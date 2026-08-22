<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * Abrir un boletín ya no borra definitivas.
 *
 * Es la §1.1 de [10-definitivas.md](../../docs/migracion/10-definitivas.md), que
 * el propio documento llama **la causa principal**, y tenía el comentario del
 * autor al lado: `// CALCULAMOS SIN VERIFICAR QUE ESTÉ DESACTUALIZADO`. El bloque
 * borraba todas las definitivas automáticas del alumno en ese grupo y periodo, y
 * sólo reponía las asignaturas en las que tuviera alguna nota viva. **Una
 * asignatura sin notas perdía su definitiva y no volvía.**
 *
 * Lo que hace que esto no sea un detalle de administración: la ruta es
 * `boletin.propio`, así que quien lo disparaba no era sólo el coordinador — **el
 * propio alumno o su acudiente, al abrir su boletín**.
 *
 * Los tres casos de abajo se comprueban por el EFECTO en la tabla y no por la
 * respuesta, porque la respuesta no lo dice: el boletín sale igual de bien
 * habiendo borrado por el camino. Es el criterio del CLAUDE.md —mirar el
 * resultado— aplicado al resultado que no se ve.
 */
class BoletinNoBorraDefinitivasTest extends CasoDeContrato
{
    /**
     * El grupo con más alumnos, un alumno suyo, un token de personal y **el
     * periodo de ESE usuario**.
     *
     * El periodo importa y no es un detalle: el bloque que se sustituyó borraba
     * con `$this->user->periodo_id`, o sea **el periodo del que mira**, que es uno
     * de los tres agravantes de la §1.1. Escrito con el primer periodo del año, el
     * test montaba la definitiva en un periodo que el borrado no tocaba: pasaba
     * con el código viejo y con el nuevo, y no medía nada.
     *
     * Lo dijo la comprobación al revés —caía 1 de 4—, que es exactamente para lo
     * que sirve: si el arreglo tapa un camino y el test no cae al destaparlo, el
     * test estaba mirando otro sitio.
     */
    private function escenario(): array
    {
        $grupo = $this->grupoConAlumnos();

        $usuario = DB::selectOne('SELECT u.username, u.periodo_id, p.numero FROM users u
            INNER JOIN periodos p ON p.id = u.periodo_id AND p.deleted_at IS NULL
            WHERE u.tipo = "Usuario" AND u.is_active = 1 AND u.deleted_at IS NULL
              AND p.year_id = ? ORDER BY u.id LIMIT 1', [$grupo->year_id]);

        $this->assertNotNull($usuario, "El seed no tiene ningún Usuario en el año {$grupo->year_id}.");

        $alumno = DB::selectOne(
            'SELECT alumno_id FROM matriculas
              WHERE grupo_id = ? AND estado IN ("MATR","ASIS") AND deleted_at IS NULL
              ORDER BY alumno_id LIMIT 1',
            [$grupo->id]
        );

        $this->assertNotNull($alumno, 'El grupo del seed necesita alumnos matriculados.');

        return [$grupo, (int) $alumno->alumno_id, $this->tokenDe($usuario->username),
            (object) ['id' => (int) $usuario->periodo_id, 'numero' => (int) $usuario->numero]];
    }

    private function abrirBoletin(object $grupo, int $alumnoId, string $token): void
    {
        $this->putJson("/api/boletines/detailed-notas/{$grupo->id}", [
            'requested_alumnos' => [['alumno_id' => $alumnoId]],
        ], ['Authorization' => 'Bearer '.$token])->assertStatus(200);
    }

    /**
     * Una definitiva de una asignatura SIN notas sobrevive a abrir el boletín.
     *
     * Es el caso exacto de la §1.1: el INSERT de reposición sólo devolvía fila
     * para las asignaturas con al menos una nota viva, así que ésta se quedaba
     * borrada. Se monta a mano porque el seed no garantiza que exista una.
     */
    public function test_una_definitiva_sin_notas_detras_sobrevive(): void
    {
        [$grupo, $alumnoId, $token, $periodo] = $this->escenario();

        $asignatura = DB::selectOne(
            'SELECT id FROM asignaturas WHERE grupo_id = ? AND deleted_at IS NULL ORDER BY id LIMIT 1',
            [$grupo->id]
        );
        $this->assertNotNull($asignatura, 'El grupo necesita alguna asignatura.');

        // Nos aseguramos de que NO haya notas de ese alumno en esa asignatura y
        // periodo: es la condición que hacía desaparecer la fila.
        DB::update(
            'UPDATE notas n
               INNER JOIN subunidades s ON s.id = n.subunidad_id
               INNER JOIN unidades u ON u.id = s.unidad_id
                SET n.deleted_at = NOW()
              WHERE n.alumno_id = ? AND u.asignatura_id = ? AND u.periodo_id = ?',
            [$alumnoId, $asignatura->id, $periodo->id]
        );

        DB::table('notas_finales')->where('alumno_id', $alumnoId)
            ->where('asignatura_id', $asignatura->id)->where('periodo_id', $periodo->id)->delete();

        DB::table('notas_finales')->insert([
            'alumno_id' => $alumnoId,
            'asignatura_id' => $asignatura->id,
            'periodo_id' => $periodo->id,
            'periodo' => $periodo->numero,
            'nota' => 4,
            'manual' => 0,
            'recuperada' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->abrirBoletin($grupo, $alumnoId, $token);

        $this->assertSame(1, DB::table('notas_finales')
            ->where('alumno_id', $alumnoId)
            ->where('asignatura_id', $asignatura->id)
            ->where('periodo_id', $periodo->id)
            ->count(),
            'Abrir el boletín borró la definitiva de una asignatura sin notas: es la §1.1.');
    }

    /**
     * Y una definitiva puesta a mano tampoco se toca.
     *
     * El bloque viejo la respetaba —su DELETE excluía `manual` y `recuperada`—,
     * así que esto no comprueba un arreglo sino que **el arreglo no rompió lo que
     * ya estaba bien**. Es la mitad que se olvida cuando se sustituye código vivo.
     *
     * **No cae al revertir, y es correcto que no caiga**: mide un camino que ya
     * funcionaba. Se dice porque la regla del 09 §0.0 obliga a contar cuántos
     * tests caen al comprobar al revés —caen 2 de 4— y a explicar los que no.
     */
    public function test_una_definitiva_manual_sigue_intacta(): void
    {
        [$grupo, $alumnoId, $token, $periodo] = $this->escenario();

        $asignatura = DB::selectOne(
            'SELECT id FROM asignaturas WHERE grupo_id = ? AND deleted_at IS NULL ORDER BY id LIMIT 1',
            [$grupo->id]
        );
        DB::table('notas_finales')->where('alumno_id', $alumnoId)
            ->where('asignatura_id', $asignatura->id)->where('periodo_id', $periodo->id)->delete();

        DB::table('notas_finales')->insert([
            'alumno_id' => $alumnoId,
            'asignatura_id' => $asignatura->id,
            'periodo_id' => $periodo->id,
            'periodo' => $periodo->numero,
            'nota' => 5,
            'manual' => 1,
            'recuperada' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->abrirBoletin($grupo, $alumnoId, $token);

        $fila = DB::table('notas_finales')->where('alumno_id', $alumnoId)
            ->where('asignatura_id', $asignatura->id)->where('periodo_id', $periodo->id)->first();

        $this->assertNotNull($fila);
        $this->assertSame(5, (int) $fila->nota, 'El recálculo pisó una definitiva manual.');
    }

    /**
     * Abrir el boletín dos veces no duplica.
     *
     * La §2 dice que la raíz del duplicado es que `notas_finales` no tiene clave
     * única y los escritores son «comprueba y luego inserta». La clave la pone la
     * fase 2 y todavía no está, así que esto comprueba lo único que hoy la
     * sustituye: que el escritor nuevo no inserte dos veces por su cuenta.
     *
     * **Tampoco cae al revertir, y también es correcto**: el bloque viejo llevaba
     * `WHERE NOT EXISTS` en su INSERT, así que por esa vía tampoco duplicaba. Lo
     * que este test guarda no es el arreglo de hoy sino la puerta de mañana — el
     * día que alguien escriba el UPSERT de la fase 2 o toque el servicio. Ya pasó
     * una vez: el UPSERT del propio servicio duplicaba, y lo cazó el test hermano
     * de `DefinitivasDeAsignaturaTest`, no éste.
     */
    public function test_abrir_el_boletin_dos_veces_no_duplica(): void
    {
        [$grupo, $alumnoId, $token, $periodo] = $this->escenario();

        $this->abrirBoletin($grupo, $alumnoId, $token);
        $this->abrirBoletin($grupo, $alumnoId, $token);
        $this->abrirBoletin($grupo, $alumnoId, $token);

        $duplicados = DB::select(
            'SELECT nf.asignatura_id, nf.periodo_id, COUNT(*) AS filas
               FROM notas_finales nf
               INNER JOIN asignaturas a ON a.id = nf.asignatura_id AND a.grupo_id = ?
              WHERE nf.alumno_id = ?
              GROUP BY nf.asignatura_id, nf.periodo_id
             HAVING COUNT(*) > 1',
            [$grupo->id, $alumnoId]
        );

        $this->assertSame([], $duplicados,
            'Abrir el boletín dejó definitivas duplicadas.');
    }

    /**
     * Y en toda la petición no se borra ni una definitiva.
     *
     * Es la comprobación directa del arreglo, y la que falla si alguien devuelve
     * el bloque viejo: se escuchan las consultas y **ninguna borra de
     * `notas_finales`**. Los tres tests de arriba miran el estado final, que puede
     * salir bien aunque haya habido una ventana de borrado en medio — y la ventana
     * es justo lo que mata a quien pierde la conexión a mitad.
     */
    public function test_ninguna_consulta_del_boletin_borra_definitivas(): void
    {
        [$grupo, $alumnoId, $token, $periodo] = $this->escenario();

        $borrados = [];
        DB::listen(function ($consulta) use (&$borrados) {
            if (preg_match('/^\s*delete\s/i', $consulta->sql) === 1
                && stripos($consulta->sql, 'notas_finales') !== false) {
                $borrados[] = $consulta->sql;
            }
        });

        $this->abrirBoletin($grupo, $alumnoId, $token);

        $this->assertSame([], $borrados,
            'El boletín volvió a borrar de notas_finales: es la §1.1 otra vez.');
    }
}
