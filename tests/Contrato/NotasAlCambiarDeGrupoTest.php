<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * Las notas que se quedan atrás cuando un alumno cambia de grupo.
 *
 * **EL PROBLEMA ES INVISIBLE, y por eso hace falta el test.** Mover a un chico de 4A a 4B no toca
 * ninguna nota: `Matricula::matricularUno` reutiliza la matrícula y le cambia el `grupo_id`. Pero
 * el boletín se arma **desde el grupo** —`Grupo::detailed_materias_notafinal` (`Grupo.php:345`)
 * parte de las asignaturas del grupo pedido y hace `LEFT JOIN` a `notas_finales`—, así que las
 * definitivas de 4A, que cuelgan de las asignaturas de 4A, **dejan de salir** y el boletín de 4B
 * aparece en blanco en esos periodos. No hay error, no hay aviso: se descubre al imprimir.
 *
 * Lo que se fija: que revisar no escriba, que el pareo sea por materia, que lo que no tiene
 * pareja se diga en vez de perderse, y que traer dos veces no duplique.
 */
class NotasAlCambiarDeGrupoTest extends CasoDeContrato
{
    private const REVISAR = '/api/matriculas/revisar-notas-del-grupo-anterior';
    private const TRAER = '/api/matriculas/traer-notas-del-grupo-anterior';

    private function token(): string
    {
        $u = DB::selectOne('SELECT u.username FROM users u
            INNER JOIN periodos p ON p.id = u.periodo_id
            WHERE u.tipo = "Usuario" AND u.is_superuser = 1 AND u.is_active = 1
              AND u.deleted_at IS NULL ORDER BY u.id LIMIT 1');

        $this->assertNotNull($u, 'El seed no tiene ningún superusuario con periodo.');

        return $this->tokenDe($u->username);
    }

    /**
     * Un alumno con definitivas, su grupo, y otro grupo con las MISMAS materias.
     *
     * **El grupo destino se fabrica dentro de la transacción.** El seed tiene un solo grupo por
     * grado, así que un test que espere encontrar «4A y 4B» no encuentra nada y pasa por no mirar
     * —que es como se escriben los tests que no protegen—.
     *
     * @return array{0: int, 1: int, 2: int} alumno, grupo origen, grupo destino
     */
    private function elCaso(): array
    {
        $fila = DB::selectOne('SELECT nf.alumno_id, a.grupo_id
            FROM notas_finales nf
            INNER JOIN asignaturas a ON a.id = nf.asignatura_id AND a.deleted_at IS NULL
            GROUP BY nf.alumno_id, a.grupo_id ORDER BY COUNT(*) DESC LIMIT 1');

        $this->assertNotNull($fila, 'El seed no tiene ninguna definitiva con asignatura.');

        $origen = DB::table('grupos')->where('id', $fila->grupo_id)->first();

        $destinoId = DB::table('grupos')->insertGetId([
            'nombre'   => 'Grupo de prueba (fusión)',
            'year_id'  => $origen->year_id,
            'grado_id' => $origen->grado_id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // Las mismas materias, otras asignaturas: es exactamente 4A y 4B.
        foreach (DB::table('asignaturas')->where('grupo_id', $origen->id)->whereNull('deleted_at')->get() as $a) {
            DB::table('asignaturas')->insert([
                'materia_id' => $a->materia_id,
                'grupo_id'   => $destinoId,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return [(int) $fila->alumno_id, (int) $origen->id, (int) $destinoId];
    }

    /** REVISAR NO ESCRIBE: la pantalla lo llama sola tras cada cambio de grupo. */
    public function test_revisar_no_escribe_ninguna_nota(): void
    {
        [$alumno, $origen, $destino] = $this->elCaso();

        $antes = DB::table('notas_finales')->count();

        $r = $this->withToken($this->token())->putJson(self::REVISAR, [
            'alumno_id' => $alumno, 'grupo_origen' => $origen, 'grupo_destino' => $destino,
        ]);

        $r->assertStatus(200)->assertJsonStructure([
            'periodos', 'definitivas', 'sin_pareja', 'comportamiento_se_queda',
        ]);
        $this->assertGreaterThan(0, $r->json('definitivas'), 'No ve las notas que se quedan atrás.');

        $this->assertSame($antes, DB::table('notas_finales')->count(), 'La ruta que sólo mira ha escrito.');
    }

    /**
     * EL AÑO VIAJA CON EL PERIODO.
     *
     * Medido en el docker: una revisión devolvió **dos periodos «1»** —notas de dos años en el
     * mismo grupo— y en la pantalla eran dos filas idénticas entre las que no se podía elegir.
     */
    public function test_cada_periodo_trae_su_anio(): void
    {
        [$alumno, $origen, $destino] = $this->elCaso();

        $periodos = $this->withToken($this->token())->putJson(self::REVISAR, [
            'alumno_id' => $alumno, 'grupo_origen' => $origen, 'grupo_destino' => $destino,
        ])->json('periodos');

        $this->assertNotEmpty($periodos);
        foreach ($periodos as $p) {
            $this->assertArrayHasKey('year', $p);
            $this->assertGreaterThan(2000, $p['year']);
        }
    }

    /** TRAER LAS PONE EN LA ASIGNATURA DEL GRUPO NUEVO, pareadas por materia. */
    public function test_traer_las_pone_en_el_grupo_nuevo(): void
    {
        [$alumno, $origen, $destino] = $this->elCaso();

        $enDestinoAntes = DB::table('notas_finales')
            ->join('asignaturas', 'asignaturas.id', '=', 'notas_finales.asignatura_id')
            ->where('notas_finales.alumno_id', $alumno)->where('asignaturas.grupo_id', $destino)->count();

        $this->assertSame(0, $enDestinoAntes, 'El grupo fabricado ya tenía notas.');

        $r = $this->withToken($this->token())->putJson(self::TRAER, [
            'alumno_id' => $alumno, 'grupo_origen' => $origen, 'grupo_destino' => $destino,
        ]);

        $r->assertStatus(200);
        $this->assertGreaterThan(0, $r->json('creadas'));

        $enDestino = DB::table('notas_finales')
            ->join('asignaturas', 'asignaturas.id', '=', 'notas_finales.asignatura_id')
            ->where('notas_finales.alumno_id', $alumno)->where('asignaturas.grupo_id', $destino)->count();

        $this->assertSame($r->json('creadas'), $enDestino);

        // Y las de origen NO se mueven: traer es copiar, no vaciar el grupo viejo.
        $enOrigen = DB::table('notas_finales')
            ->join('asignaturas', 'asignaturas.id', '=', 'notas_finales.asignatura_id')
            ->where('notas_finales.alumno_id', $alumno)->where('asignaturas.grupo_id', $origen)->count();
        $this->assertGreaterThan(0, $enOrigen, 'Traer vació el grupo de origen.');
    }

    /**
     * TRAER DOS VECES NO DUPLICA: la segunda pisa, no crea.
     *
     * Sin esto, pulsar dos veces dejaría dos definitivas para la misma asignatura y periodo — y
     * `notas_finales` no tiene índice único, así que la base **no avisaría**: el boletín sacaría
     * una de las dos según el orden del motor.
     */
    public function test_traer_dos_veces_no_duplica(): void
    {
        [$alumno, $origen, $destino] = $this->elCaso();

        $cuerpo = ['alumno_id' => $alumno, 'grupo_origen' => $origen, 'grupo_destino' => $destino];

        $primera = $this->withToken($this->token())->putJson(self::TRAER, $cuerpo)->json();
        $segunda = $this->withToken($this->token())->putJson(self::TRAER, $cuerpo)->json();

        $this->assertGreaterThan(0, $primera['creadas']);
        $this->assertSame(0, $segunda['creadas'], 'La segunda vez volvió a crear filas.');
        $this->assertSame($primera['creadas'], $segunda['pisadas']);

        $porAsignaturaYPeriodo = DB::select(
            'SELECT COUNT(*) AS n FROM notas_finales nf
             INNER JOIN asignaturas a ON a.id = nf.asignatura_id AND a.grupo_id = ?
             WHERE nf.alumno_id = ?
             GROUP BY nf.asignatura_id, nf.periodo_id HAVING n > 1', [$destino, $alumno]);

        $this->assertSame([], $porAsignaturaYPeriodo, 'Quedaron dos definitivas para la misma asignatura y periodo.');
    }

    /**
     * LO QUE EL GRUPO NUEVO NO DA SE NOMBRA, no se pierde en silencio.
     *
     * Se borra una asignatura del destino y esa materia tiene que salir en `sin_pareja`.
     */
    public function test_lo_que_no_tiene_pareja_se_dice(): void
    {
        [$alumno, $origen, $destino] = $this->elCaso();

        $sobra = DB::table('asignaturas')->where('grupo_id', $destino)->first();
        DB::table('asignaturas')->where('id', $sobra->id)->update(['deleted_at' => now()]);

        $materia = DB::table('materias')->where('id', $sobra->materia_id)->value('materia');

        $revision = $this->withToken($this->token())->putJson(self::REVISAR, [
            'alumno_id' => $alumno, 'grupo_origen' => $origen, 'grupo_destino' => $destino,
        ])->json();

        $this->assertContains($materia, $revision['sin_pareja'],
            'La materia que el grupo nuevo no da no aparece en sin_pareja.');
    }

    /** Sólo mirar es de cualquiera con sesión; escribir notas, de un administrativo. */
    public function test_traer_exige_administrativo(): void
    {
        [$alumno, $origen, $destino] = $this->elCaso();
        $token = $this->tokenDe($this->usuarioDeTipo('Profesor')->username);

        $this->withToken($token)->putJson(self::TRAER, [
            'alumno_id' => $alumno, 'grupo_origen' => $origen, 'grupo_destino' => $destino,
        ])->assertStatus(403);
    }
}
