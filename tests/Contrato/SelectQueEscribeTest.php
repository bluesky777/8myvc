<?php

namespace Tests\Contrato;

use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;

/**
 * Los `DB::select` que escriben: **qué fila queda**, no qué código responde.
 *
 * VERBOS-1. Ocho sitios de `app/` llaman a `DB::select()` con una sentencia que
 * escribe. Funciona —y por eso lleva años ahí— y el lote cambia la palabra sin
 * cambiar una sola conducta.
 *
 * ## Por qué NO hay un test que falle antes, y por qué eso no es una excusa
 *
 * La ficha del lote pedía *«un test que falle antes y pase después»*. **No se puede,
 * y la razón es la premisa del propio lote**: `DB::select` con un `INSERT` escribe
 * exactamente igual que `DB::insert`. La palabra **no cambia la conducta** — ésa es
 * toda la seguridad de VERBOS-1. Un test sobre el resultado **pasa en verde antes y
 * después**, y uno que fallara antes estaría midiendo otra cosa.
 *
 * > **La red no es «rojo antes»: es «rojo si la escritura deja de ocurrir».** Y eso
 * > se verifica, no se supone: cada aserción de este fichero se ha visto caer
 * > quitando su sentencia, y restaurándola después.
 *
 * **Un test de caracterización que nunca se ha visto caer no distingue «la escritura
 * ocurre» de «mi test no la mira».** Es el «0 encontrados» de las herramientas de
 * `tools/`, en forma de aserción.
 *
 * ## El orden con el que se usó
 *
 * Escrito **contra el código de antes** —con `DB::select`— y verde. Después la
 * palabra. Después verde otra vez. *Eso es lo que prueba la neutralidad; escribirlo
 * al revés no probaría nada.*
 */
class SelectQueEscribeTest extends CasoDeContrato
{
    /** `EnfermeriaController::putDatos` → `INSERT INTO antecedentes` */
    public function test_pedir_los_datos_de_enfermeria_crea_la_ficha_de_antecedentes(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);

        [$grupo, $token] = $this->grupoYPersonal();

        $alumno = DB::selectOne(
            'SELECT m.alumno_id FROM matriculas m
              WHERE m.grupo_id = ? AND m.deleted_at IS NULL
                AND m.alumno_id NOT IN (SELECT a.alumno_id FROM antecedentes a)
              LIMIT 1',
            [$grupo->id]
        );

        $this->assertNotNull($alumno,
            'Todos los alumnos del grupo tienen ya ficha de antecedentes: sin uno sin ella, '
            .'el INSERT no se ejerce y este test no mira nada.');

        $antes = (int) DB::selectOne('SELECT COUNT(*) c FROM antecedentes WHERE alumno_id = ?',
            [$alumno->alumno_id])->c;

        $this->assertSame(0, $antes, 'La precondición se rompió entre la consulta y el uso.');

        $this->putJson('/api/enfermeria/datos', ['alumno_id' => $alumno->alumno_id],
            ['Authorization' => 'Bearer '.$token])->assertStatus(200);

        $this->assertSame(1,
            (int) DB::selectOne('SELECT COUNT(*) c FROM antecedentes WHERE alumno_id = ?',
                [$alumno->alumno_id])->c,
            'No quedó la fila de `antecedentes`. El `DB::select` con el INSERT dentro es lo '
            .'único que la crea (EnfermeriaController:47-48).');
    }

    /** `EnfermeriaController::postCrearSuceso` → `INSERT INTO registros_enfermeria` */
    public function test_crear_un_suceso_de_enfermeria_deja_la_fila(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);

        [$grupo, $token] = $this->grupoYPersonal();

        $alumno = DB::selectOne(
            'SELECT m.alumno_id FROM matriculas m WHERE m.grupo_id = ? AND m.deleted_at IS NULL LIMIT 1',
            [$grupo->id]
        );

        $antes = (int) DB::selectOne('SELECT COUNT(*) c FROM registros_enfermeria')->c;

        $this->postJson('/api/enfermeria/crear-suceso', [
            'alumno_id' => $alumno->alumno_id,
            'fecha_suceso' => '2026-08-24',
            'signo_fc' => 80, 'signo_fr' => 16, 'signo_t' => 36,
            'signo_glu' => 90, 'signo_spo2' => 98,
            'signo_pa_dia' => 70, 'signo_pa_sis' => 110,
            'asignatura' => 'Matemáticas',
            'motivo_consulta' => 'VERBOS-1',
            'descripcion_suceso' => 'Fila de caracterización',
        ], ['Authorization' => 'Bearer '.$token])->assertStatus(200);

        $this->assertSame($antes + 1,
            (int) DB::selectOne('SELECT COUNT(*) c FROM registros_enfermeria')->c,
            'No quedó la fila de `registros_enfermeria` (EnfermeriaController:106-109).');
    }

    /** `RequisitosController::putUpdate` → `UPDATE requisitos_matricula` */
    public function test_actualizar_un_requisito_de_matricula_escribe_la_fila(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);

        [$grupo, $token] = $this->grupoYPersonal();

        $id = DB::table('requisitos_matricula')->insertGetId([
            'requisito' => 'antes', 'descripcion' => 'antes',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->putJson('/api/requisitos/update',
            ['id' => $id, 'requisito' => 'VERBOS-1', 'descripcion' => 'escrito por el test'],
            ['Authorization' => 'Bearer '.$token])->assertStatus(200);

        $fila = DB::selectOne('SELECT requisito, descripcion FROM requisitos_matricula WHERE id = ?', [$id]);

        $this->assertSame('VERBOS-1', $fila->requisito,
            'El `UPDATE requisitos_matricula` no llegó a la fila (RequisitosController:63-64).');
        $this->assertSame('escrito por el test', $fila->descripcion);
    }

    /** `RequisitosController::postAlumno` → `UPDATE requisitos_alumno` */
    public function test_guardar_el_requisito_de_un_alumno_escribe_la_fila(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);

        [$grupo, $token] = $this->grupoYPersonal();

        $alumno = DB::selectOne(
            'SELECT m.alumno_id FROM matriculas m WHERE m.grupo_id = ? AND m.deleted_at IS NULL LIMIT 1',
            [$grupo->id]
        );

        $requisito = DB::table('requisitos_matricula')->insertGetId([
            'requisito' => 'para el alumno', 'descripcion' => '',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $id = DB::table('requisitos_alumno')->insertGetId([
            'alumno_id' => $alumno->alumno_id, 'requisito_id' => $requisito,
            'estado' => 0, 'descripcion' => 'antes',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->postJson('/api/requisitos/alumno',
            ['requisito_alumno_id' => $id, 'estado' => 1, 'descripcion' => 'VERBOS-1'],
            ['Authorization' => 'Bearer '.$token])->assertStatus(200);

        $fila = DB::selectOne('SELECT estado, descripcion FROM requisitos_alumno WHERE id = ?', [$id]);

        $this->assertSame('VERBOS-1', $fila->descripcion,
            'El `UPDATE requisitos_alumno` no llegó a la fila (RequisitosController:79-81).');
        $this->assertSame(1, (int) $fila->estado);
    }

    /** `RolesController::putAddroletouser` → `INSERT INTO role_user` */
    public function test_darle_un_rol_a_un_usuario_deja_la_fila_en_role_user(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);

        // **Un superusuario, y no el personal llano**: `exigirAdminUsuarios()` corta
        // con 403 a quien no lo sea ni tenga `can_edit_usuarios`. La primera versión
        // usaba `tokenDelPersonalLlano()` y el test cayó — pero cayó DICIENDO que no
        // estaba llegando al INSERT, que es lo que se le pedía al aserto de abajo.
        // Un 403 dado por bueno aquí habría dejado la escritura sin mirar.
        $token = $this->tokenDe($this->usuarioDeTipo('Usuario')->username);

        $par = DB::selectOne(
            'SELECT u.id AS user_id, r.id AS role_id
               FROM users u, roles r
              WHERE u.deleted_at IS NULL
                AND NOT EXISTS (SELECT 1 FROM role_user ru WHERE ru.user_id = u.id AND ru.role_id = r.id)
              LIMIT 1'
        );

        $this->assertNotNull($par,
            'No hay ningún par (usuario, rol) sin asignar: el INSERT no se ejerce.');

        $r = $this->putJson('/api/roles/addroletouser/'.$par->role_id,
            ['user_id' => $par->user_id], ['Authorization' => 'Bearer '.$token]);

        // Si el token llano no es admin de usuarios, esto no mide el INSERT: lo decimos
        // en vez de dejar pasar un 403 como si fuera el caso.
        $this->assertNotSame(403, $r->getStatusCode(),
            'El token usado no puede administrar usuarios, así que este test no llega al '
            .'INSERT. Hace falta uno que sí pueda.');

        $this->assertSame(1,
            (int) DB::selectOne('SELECT COUNT(*) c FROM role_user WHERE user_id = ? AND role_id = ?',
                [$par->user_id, $par->role_id])->c,
            'No quedó la fila en `role_user` (RolesController:96-98).');
    }

    /** `DefinitivasPeriodosController::putCalcularGrupoPeriodo` → `INSERT INTO notas_finales` */
    public function test_calcular_el_grupo_y_periodo_deja_las_definitivas(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);

        [$grupo, $token] = $this->grupoYPersonal();

        $periodo = DB::selectOne(
            'SELECT p.id, p.numero FROM periodos p
              WHERE p.year_id = ? AND p.deleted_at IS NULL ORDER BY p.numero LIMIT 1',
            [$grupo->year_id]
        );

        DB::delete(
            'DELETE nf FROM notas_finales nf
               INNER JOIN asignaturas a ON a.id = nf.asignatura_id AND a.grupo_id = ?
              WHERE nf.periodo_id = ?',
            [$grupo->id, $periodo->id]
        );

        $cuantas = fn () => (int) DB::selectOne(
            'SELECT COUNT(*) c FROM notas_finales nf
               INNER JOIN asignaturas a ON a.id = nf.asignatura_id AND a.grupo_id = ?
              WHERE nf.periodo_id = ?',
            [$grupo->id, $periodo->id])->c;

        $this->assertSame(0, $cuantas(), 'La precondición no se cumplió: quedaban definitivas.');

        $this->putJson('/api/definitivas_periodos/calcular-grupo-periodo', [
            'grupo_id' => $grupo->id,
            'periodo_id' => $periodo->id,
            'num_periodo' => $periodo->numero,
        ], ['Authorization' => 'Bearer '.$token])->assertStatus(200);

        $this->assertGreaterThan(0, $cuantas(),
            'No quedó ninguna definitiva. El `DB::select` con el `INSERT INTO notas_finales` '
            .'dentro es el que las escribe (DefinitivasPeriodosController:147-154) — y es uno '
            .'de los DOS que el censo del 05 §191 no listaba.');
    }
}
