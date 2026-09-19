<?php

namespace Tests\Contrato;

use App\Support\Autoriza;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

/**
 * **P6: lo que el colegio puso en la plantilla no lo cambia un docente.**
 *
 * Decidido por Joseth el 19 sep 2026, y es la pieza más delicada del modelo de
 * evaluación porque **es la única que le quita a un docente algo que hoy hace**
 * en los dieciséis colegios. El agujero estaba medido: `PUT unidades/update/{id}`
 * lleva sólo `auth.personal`, así que cualquier docente podía renombrar y
 * recambiar el porcentaje de una fila sembrada desde `unidades_por_defecto`.
 *
 * ## Qué existe esto para cazar, y ninguno de los cuatro da error solo
 *
 * **1. Que se quite el candado del controlador.** Es la lección de
 * `putTonoDocente` y de `PlantillaNotasTest`: el criterio vive **dentro** del
 * método, no en la ruta, así que borrarlo no pone nada en rojo por sí mismo.
 *
 * **2. Que el candado se vuelva un muro.** Añadir subunidades dentro de una
 * unidad del colegio **es el trabajo del docente** (D14). Un candado que también
 * frene eso deja la pantalla inservible y el síntoma en el colegio es *«no puedo
 * poner mis logros»* — peor que el agujero que viene a tapar.
 *
 * **3. Que el candado mire la PRESENCIA del campo en vez de su VALOR.** El front
 * reenvía el formulario entero, así que un candado que salte porque *«vino
 * `porcentaje`»* contesta 403 a quien no cambió nada. Se vería como una avería, y
 * el arreglo evidente —quitar el candado— reabre el agujero.
 *
 * **4. Que se cuele por la puerta de al lado.** `unidades/update-orden` no escribe
 * ni nombre ni porcentaje: lo único que puede hacer con una fila del colegio es
 * **moverla de sitio**. P6 nombra esa ruta, así que se frena eso — y aquí queda
 * fijado, porque es una interpretación de P6 y no una lectura literal.
 *
 * ## Lo que este test NO cubre, dicho para que no se lea de más
 *
 * El «volver a aplicar» de P6 **no está hecho**: Joseth lo dejó fuera del alcance
 * el 19 sep. Y la decisión de qué hacer con las asignaturas que ya tienen notas
 * —actualizar nombre y porcentaje igual— está tomada y escrita, pero no
 * implementada, así que aquí no hay nada suyo que fijar.
 */
class CandadoDeLaPlantillaTest extends CasoDeContrato
{
    /**
     * Un docente llano, su periodo abierto y una asignatura suya.
     *
     * El periodo se abre **a propósito**: si estuviera cerrado, todos los 403 de
     * aquí abajo serían de `pueden_editar_notas` y el test pasaría entero con el
     * candado borrado. Es el mismo cuidado que pide `PlantillaNotasTest` con
     * `is_superuser`.
     */
    private function escenario(): object
    {
        $prof = $this->usuarioDeTipo('Profesor');

        $this->assertSame(0, (int) $prof->is_superuser,
            'El sujeto de este test NO puede ser superusuario: con la columna puesta, '.
            'el candado lo dejaría pasar y el rojo no llegaría nunca.');

        $token = $this->tokenDe($prof->username);

        $suyo = DB::selectOne('SELECT p.id, p.year_id FROM periodos p
            INNER JOIN users u ON u.periodo_id = p.id WHERE u.id = ?', [$prof->id]);

        $this->assertNotNull($suyo, 'El profesor del seed se quedó sin periodo al entrar.');

        DB::table('periodos')->where('year_id', $suyo->year_id)
            ->update(['profes_pueden_editar_notas' => 1]);

        $asignatura = DB::selectOne('SELECT a.id FROM asignaturas a
            INNER JOIN grupos g ON g.id = a.grupo_id AND g.year_id = ? AND g.deleted_at IS NULL
            WHERE a.deleted_at IS NULL ORDER BY a.id LIMIT 1', [$suyo->year_id]);

        $this->assertNotNull($asignatura, 'El seed no tiene asignaturas en el año del profesor.');

        return (object) [
            'usuario' => $prof,
            'token' => $token,
            'periodo' => (int) $suyo->id,
            'year_id' => (int) $suyo->year_id,
            'asignatura' => (int) $asignatura->id,
        ];
    }

    /** Una unidad en la asignatura. `$delColegio` es lo que decide `por_defecto`. */
    private function unidad(object $e, bool $delColegio, array $campos = []): int
    {
        return (int) DB::table('unidades')->insertGetId($campos + [
            'definicion' => $delColegio ? 'Cognitivo (del colegio)' : 'Mi unidad',
            'porcentaje' => 60,
            'periodo_id' => $e->periodo,
            'asignatura_id' => $e->asignatura,
            'orden' => 0,
            'por_defecto' => $delColegio ? 1 : 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function subunidad(int $unidadId, bool $delColegio): int
    {
        return (int) DB::table('subunidades')->insertGetId([
            'definicion' => $delColegio ? 'Taller (del colegio)' : 'Mi logro',
            'porcentaje' => 100,
            'unidad_id' => $unidadId,
            'nota_default' => 0,
            'orden' => 0,
            'por_defecto' => $delColegio ? 1 : 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function filaDeUnidad(int $id): object
    {
        return DB::selectOne('SELECT definicion, porcentaje, orden FROM unidades WHERE id = ?', [$id]);
    }

    // ─── Lo que el candado frena ────────────────────────────────────────────────

    #[Test]
    public function test_un_docente_no_le_cambia_el_nombre_a_una_unidad_del_colegio(): void
    {
        $e = $this->escenario();
        $id = $this->unidad($e, delColegio: true);

        $this->withToken($e->token)
            ->putJson('/api/unidades/update/'.$id, ['definicion' => 'Como a mí me gusta'])
            ->assertStatus(403);

        $this->assertSame('Cognitivo (del colegio)', $this->filaDeUnidad($id)->definicion,
            'Contestó 403 y escribió igual: el candado frena la respuesta pero no la escritura.');
    }

    /**
     * **El caso que más duele de los cuatro**, porque no se ve: el porcentaje de la
     * unidad es el factor de fuera de la definitiva —`(u.porcentaje/100) * …`— y
     * cambiarlo **recalcula la asignatura entera** diez líneas más abajo, en el
     * mismo método. Un docente moviendo el 60 al 90 movía las notas de su curso.
     */
    #[Test]
    public function test_un_docente_no_le_cambia_el_porcentaje_a_una_unidad_del_colegio(): void
    {
        $e = $this->escenario();
        $id = $this->unidad($e, delColegio: true);

        $this->withToken($e->token)
            ->putJson('/api/unidades/update/'.$id, ['porcentaje' => 90])
            ->assertStatus(403);

        $this->assertSame(60, (int) $this->filaDeUnidad($id)->porcentaje);
    }

    #[Test]
    public function test_el_candado_vale_igual_para_una_subunidad_del_colegio(): void
    {
        $e = $this->escenario();
        $unidad = $this->unidad($e, delColegio: true);
        $sub = $this->subunidad($unidad, delColegio: true);

        $this->withToken($e->token)
            ->putJson('/api/subunidades/update/'.$sub, ['definicion' => 'Otro nombre'])
            ->assertStatus(403);

        $this->assertSame('Taller (del colegio)',
            DB::table('subunidades')->where('id', $sub)->value('definicion'));
    }

    /**
     * La puerta de al lado. Ver el punto 4 del docblock de arriba: es la única
     * cosa que `update-orden` puede hacerle a una fila del colegio.
     */
    #[Test]
    public function test_un_docente_no_mueve_de_sitio_una_unidad_del_colegio(): void
    {
        $e = $this->escenario();
        $id = $this->unidad($e, delColegio: true, campos: ['orden' => 0]);

        $this->withToken($e->token)
            ->putJson('/api/unidades/update-orden', ['sortHash' => [[$id => 5]]])
            ->assertStatus(403);

        $this->assertSame(0, (int) $this->filaDeUnidad($id)->orden);
    }

    // ─── Lo que el candado NO frena, que es la mitad que lo hace usable ─────────

    /**
     * **Reenviar el mismo valor no es cambiarlo.**
     *
     * El front manda el formulario entero, así que esto es lo que pasa cada vez
     * que el docente guarda cualquier otra cosa de la fila. Con un candado que
     * mirase la presencia del campo, esto sería 403 y la pantalla parecería rota.
     */
    #[Test]
    public function test_reenviar_los_mismos_valores_no_es_un_403(): void
    {
        $e = $this->escenario();
        $id = $this->unidad($e, delColegio: true);

        $this->withToken($e->token)
            ->putJson('/api/unidades/update/'.$id, [
                'definicion' => 'Cognitivo (del colegio)',
                'porcentaje' => 60,
            ])
            ->assertStatus(200);
    }

    /**
     * Y lo mismo con el orden: el cliente manda la rejilla **entera** cuando el
     * docente mueve una fila suya, así que la del colegio viaja en la lista sin
     * moverse. Si eso fuera 403, reordenar lo propio sería imposible.
     */
    #[Test]
    public function test_reordenar_lo_propio_manda_la_rejilla_entera_y_pasa(): void
    {
        $e = $this->escenario();
        $delColegio = $this->unidad($e, delColegio: true, campos: ['orden' => 0]);
        $mia = $this->unidad($e, delColegio: false, campos: ['orden' => 1]);

        $this->withToken($e->token)
            ->putJson('/api/unidades/update-orden', ['sortHash' => [[$delColegio => 0, $mia => 2]]])
            ->assertStatus(200);

        $this->assertSame(0, (int) $this->filaDeUnidad($delColegio)->orden);
        $this->assertSame(2, (int) $this->filaDeUnidad($mia)->orden);
    }

    #[Test]
    public function test_su_propia_unidad_la_cambia_como_siempre(): void
    {
        $e = $this->escenario();
        $id = $this->unidad($e, delColegio: false);

        $this->withToken($e->token)
            ->putJson('/api/unidades/update/'.$id, ['definicion' => 'Renombrada', 'porcentaje' => 40])
            ->assertStatus(200);

        $fila = $this->filaDeUnidad($id);
        $this->assertSame('Renombrada', $fila->definicion);
        $this->assertSame(40, (int) $fila->porcentaje);
    }

    /**
     * **D14, y es la razón de que el candado sea de dos campos y no de la fila.**
     * El colegio pone las unidades; el docente cuelga sus logros dentro. Si esto
     * fuera 403, el candado habría cambiado un agujero por una pantalla muerta.
     */
    #[Test]
    public function test_anadir_una_subunidad_dentro_de_una_unidad_del_colegio_sigue_siendo_suyo(): void
    {
        $e = $this->escenario();
        $unidad = $this->unidad($e, delColegio: true);

        $r = $this->withToken($e->token)->postJson('/api/subunidades', [
            'unidad_id' => $unidad,
            'definicion' => 'Logro que pongo yo',
            'porcentaje' => 50,
        ]);

        $this->assertNotSame(403, $r->getStatusCode(),
            'El docente tiene que poder colgar sus logros de una unidad del colegio: es D14.');
    }

    /**
     * **El caso que demuestra que el permiso se lee de verdad**, y va sin
     * `is_superuser` por lo mismo que en `PlantillaNotasTest`: con la columna
     * puesta pasaría aunque el candado mirase cualquier otra cosa.
     *
     * El permiso se da **por rol**, que es como llega al contexto de verdad, y el
     * token se pide **después** — `ContextoDeUsuario` resuelve `perms` una vez por
     * proceso, así que pedirlo antes mediría el `perms` vacío.
     */
    #[Test]
    public function test_quien_manda_en_la_plantilla_si_la_cambia(): void
    {
        $prof = $this->usuarioDeTipo('Profesor');

        $this->assertSame(0, (int) $prof->is_superuser);

        /*
         * **El orden de estas tres líneas es el test.** El permiso va ANTES del
         * token porque `ContextoDeUsuario` resuelve `perms` una vez por proceso; y
         * el periodo se lee DESPUÉS del token porque `Services\Login` reescribe
         * `users.periodo_id` al entrar. Leerlo antes deja `$suyo` en null —el
         * `INNER JOIN` no casa— y el test revienta en un sitio que no tiene nada
         * que ver con el candado. Es la misma nota que lleva `UnidadesTest`.
         */
        $this->darPermisoDePlantilla((int) $prof->id);
        $token = $this->tokenDe($prof->username);

        $suyo = DB::selectOne('SELECT p.id, p.year_id FROM periodos p
            INNER JOIN users u ON u.periodo_id = p.id WHERE u.id = ?', [$prof->id]);

        $this->assertNotNull($suyo, 'El profesor del seed se quedó sin periodo al entrar.');

        DB::table('periodos')->where('year_id', $suyo->year_id)
            ->update(['profes_pueden_editar_notas' => 1]);

        $asignatura = DB::selectOne('SELECT a.id FROM asignaturas a
            INNER JOIN grupos g ON g.id = a.grupo_id AND g.year_id = ? AND g.deleted_at IS NULL
            WHERE a.deleted_at IS NULL ORDER BY a.id LIMIT 1', [$suyo->year_id]);

        $id = (int) DB::table('unidades')->insertGetId([
            'definicion' => 'Cognitivo (del colegio)', 'porcentaje' => 60,
            'periodo_id' => (int) $suyo->id, 'asignatura_id' => (int) $asignatura->id,
            'orden' => 0, 'por_defecto' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->withToken($token)
            ->putJson('/api/unidades/update/'.$id, ['definicion' => 'Corregido por coordinación'])
            ->assertStatus(200);

        $this->assertSame('Corregido por coordinación', $this->filaDeUnidad($id)->definicion);
    }

    /**
     * El permiso por rol. Calcado de `PlantillaNotasTest::darPermisoDePlantilla`, y
     * por el mismo motivo: `test-seed.sql` hace `TRUNCATE` de `permissions`, así
     * que lo que siembre la migración **no sobrevive a construir la base**.
     */
    private function darPermisoDePlantilla(int $userId): void
    {
        $permiso = DB::table('permissions')->where('name', Autoriza::PERMISO_PLANTILLA_NOTAS)->value('id')
            ?? DB::table('permissions')->insertGetId([
                'name' => Autoriza::PERMISO_PLANTILLA_NOTAS,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        $rol = DB::table('roles')->where('name', 'JefeDeAreaDePrueba')->value('id')
            ?? DB::table('roles')->insertGetId([
                'name' => 'JefeDeAreaDePrueba',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        if (! DB::table('permission_role')->where('permission_id', $permiso)->where('role_id', $rol)->exists()) {
            DB::table('permission_role')->insert(['permission_id' => $permiso, 'role_id' => $rol]);
        }

        if (! DB::table('role_user')->where('user_id', $userId)->where('role_id', $rol)->exists()) {
            DB::table('role_user')->insert(['user_id' => $userId, 'role_id' => $rol]);
        }
    }
}
