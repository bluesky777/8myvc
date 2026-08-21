<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * El flujo que aprueba los cambios pedidos, que no comprobaba lo que aprobaba.
 *
 * Siete rutas de `ChangeAskedController` sin que nadie hubiera mirado nunca lo
 * que responden. Ver docs/migracion/05-codigo-muerto-y-roto.md §39.
 */
class AceptarCambiosTest extends CasoDeContrato
{
    /**
     * Aceptar un cambio de nombre no debería poder renombrar a quien no lo pidió.
     *
     * `putAceptarAlumno` recibe `asked_id` y busca el pedido... y luego escribe
     * con el `alumno_id` y el `valor_nuevo` **del cuerpo de la petición**, sin
     * comprobar que el pedido sea de ese alumno ni que pidiera ese valor. Con lo
     * cual el pedido es decorativo: la ruta es un `UPDATE alumnos SET nombres`
     * abierto a cualquiera de los 51 profesores.
     */
    public function test_aceptar_un_cambio_no_renombra_a_un_alumno_cualquiera(): void
    {
        $token = $this->tokenDe($this->usuarioDeTipo('Profesor')->username);

        $victima = DB::selectOne('SELECT id, nombres FROM alumnos
            WHERE deleted_at IS NULL ORDER BY id LIMIT 1');

        $this->withToken($token)->putJson('/api/ChangesAsked/aceptar-alumno', [
            'asked_id' => 999999,
            'data_id' => 999999,
            'tipo' => 'nombres',
            'alumno_id' => $victima->id,
            'valor_nuevo' => 'RENOMBRADO SIN PEDIRLO',
        ]);

        $this->assertSame($victima->nombres,
            DB::table('alumnos')->where('id', $victima->id)->value('nombres'),
            'Se renombró a un alumno sin que hubiera ningún pedido suyo.');
    }

    /**
     * Y lo mismo con la asignatura: `putAceptarAsignatura` leía el pedido entero
     * de `Request::input('pedido')`, así que el cuerpo decidía a qué profesor se
     * le asigna qué, y podía además **borrar cualquier asignatura**.
     *
     * La primera versión de este test **pasaba, y pasaba por la razón
     * equivocada**: mandaba `asignatura_actual['id']` y el controlador lee
     * `asignatura_actual['asignatura_id']`, así que el UPDATE iba a `null` y no
     * tocaba nada. Se vio comparándolo con el código, no corriéndolo. Un test que
     * da verde porque no llegó a ninguna parte es peor que no tenerlo.
     */
    public function test_aceptar_una_asignatura_no_se_la_pasa_a_quien_diga_el_cuerpo(): void
    {
        $token = $this->tokenDe($this->usuarioDeTipo('Profesor')->username);

        $asignatura = DB::selectOne('SELECT id, profesor_id, creditos FROM asignaturas
            WHERE deleted_at IS NULL AND profesor_id IS NOT NULL ORDER BY id LIMIT 1');

        $otro = DB::selectOne('SELECT id FROM profesores
            WHERE deleted_at IS NULL AND id <> ? ORDER BY id LIMIT 1', [$asignatura->profesor_id]);

        $this->assertNotNull($otro, 'El seed necesita dos profesores.');

        $this->withToken($token)->putJson('/api/ChangesAsked/aceptar-asignatura', [
            'pedido' => [
                'materia_to_add_id' => 1,
                'profesor_id' => $otro->id,
                'creditos_new' => 99,
                'asignatura_actual' => ['ocupada' => true, 'asignatura_id' => $asignatura->id],
                'assignment_id' => 999999,
            ],
        ]);

        $this->assertEquals($asignatura->profesor_id,
            DB::table('asignaturas')->where('id', $asignatura->id)->value('profesor_id'),
            'La asignatura cambió de profesor sin que nadie lo pidiera.');
    }

    /**
     * Y la mitad que tiene que seguir funcionando: un cambio pedido de verdad
     * **sí** se aplica, y se aplica **al que lo pidió y con lo que pidió**.
     *
     * Sin esto, el arreglo de arriba podría ser simplemente «no hacer nada
     * nunca», que también hace pasar aquel test. Se monta el pedido a mano
     * porque es la única forma de tener uno dentro de la transacción.
     */
    public function test_un_cambio_pedido_de_verdad_si_se_aplica(): void
    {
        $alumno = DB::selectOne('SELECT a.id, a.user_id, a.nombres FROM alumnos a
            INNER JOIN users u ON u.id = a.user_id AND u.deleted_at IS NULL
            WHERE a.deleted_at IS NULL AND a.user_id IS NOT NULL ORDER BY a.id LIMIT 1');

        $this->assertNotNull($alumno, 'El seed necesita un alumno con cuenta.');

        $otro = DB::selectOne('SELECT id, nombres FROM alumnos
            WHERE deleted_at IS NULL AND id <> ? ORDER BY id LIMIT 1', [$alumno->id]);

        $year = DB::selectOne('SELECT id FROM years WHERE actual = 1 AND deleted_at IS NULL LIMIT 1');

        $dataId = DB::table('change_asked_data')->insertGetId([
            'nombres_new' => 'Nombre Que Sí Pidió',
        ]);

        $askedId = DB::table('change_asked')->insertGetId([
            'asked_by_user_id' => $alumno->user_id,
            'tipo_user' => 'Alumno',
            'data_id' => $dataId,
            'year_asked_id' => $year->id,
        ]);

        $this->withToken($this->tokenDe($this->usuarioDeTipo('Profesor')->username))
            ->putJson('/api/ChangesAsked/aceptar-alumno', [
                'asked_id' => $askedId,
                'tipo' => 'nombres',
                // Lo que el cuerpo diga ya no manda. Se mandan a propósito otro
                // alumno y otro valor: si alguno de los dos volviera a colarse,
                // este test lo ve.
                'alumno_id' => $otro->id,
                'valor_nuevo' => 'ESTO NO DEBERÍA ESCRIBIRSE',
                'data_id' => 999999,
            ])->assertStatus(200);

        $this->assertSame('Nombre Que Sí Pidió',
            DB::table('alumnos')->where('id', $alumno->id)->value('nombres'),
            'El cambio que la persona pidió de verdad no llegó a aplicarse.');

        $this->assertSame($otro->nombres,
            DB::table('alumnos')->where('id', $otro->id)->value('nombres'),
            'Se escribió sobre el alumno que nombraba el cuerpo.');

        $this->assertEquals(1,
            DB::table('change_asked_data')->where('id', $dataId)->value('nombres_accepted'),
            'El pedido no quedó marcado como aceptado.');
    }

    /**
     * Y no se borra una asignatura nombrándola en el cuerpo.
     *
     * Es la rama que más alcance tenía: `asignatura_to_remove_id` iba directo a
     * un `UPDATE asignaturas SET deleted_at`, y una asignatura en la papelera se
     * lleva por delante lo que cuelgue de ella en las pantallas.
     */
    public function test_una_asignatura_no_se_borra_nombrandola_en_el_cuerpo(): void
    {
        $token = $this->tokenDe($this->usuarioDeTipo('Profesor')->username);

        $asignatura = DB::selectOne('SELECT id FROM asignaturas
            WHERE deleted_at IS NULL ORDER BY id LIMIT 1');

        $this->withToken($token)->putJson('/api/ChangesAsked/aceptar-asignatura', [
            'pedido' => [
                'asked_id' => 999999,
                'materia_to_add_id' => 0,
                'asignatura_to_remove_id' => $asignatura->id,
                'assignment_id' => 999999,
                'asked_by_user_id' => 1,
            ],
        ])->assertStatus(404);

        $this->assertNull(
            DB::table('asignaturas')->where('id', $asignatura->id)->value('deleted_at'),
            'La asignatura se mandó a la papelera nombrándola en el cuerpo.');
    }

    /**
     * Y el pedido de asignatura real sí se aplica, **al profesor que lo pidió**.
     *
     * Se manda a propósito otro `profesor_id` en el cuerpo: si volviera a
     * colarse, la asignatura acabaría en el profesor equivocado y este test lo
     * vería.
     */
    public function test_un_pedido_de_asignatura_real_si_se_aplica(): void
    {
        $asignatura = DB::selectOne('SELECT a.id, a.materia_id, a.grupo_id, a.profesor_id
            FROM asignaturas a WHERE a.deleted_at IS NULL AND a.materia_id IS NOT NULL
            ORDER BY a.id LIMIT 1');

        $solicitante = DB::selectOne('SELECT id, user_id FROM profesores
            WHERE deleted_at IS NULL AND user_id IS NOT NULL AND id <> ?
            ORDER BY id LIMIT 1', [$asignatura->profesor_id]);

        $this->assertNotNull($solicitante, 'El seed necesita otro profesor con cuenta.');

        $year = DB::selectOne('SELECT id FROM years WHERE actual = 1 AND deleted_at IS NULL LIMIT 1');

        $assignmentId = DB::table('change_asked_assignment')->insertGetId([
            'materia_to_add_id' => $asignatura->materia_id,
            'grupo_to_add_id' => $asignatura->grupo_id,
            'creditos_new' => 7,
        ]);

        $askedId = DB::table('change_asked')->insertGetId([
            'asked_by_user_id' => $solicitante->user_id,
            'tipo_user' => 'Profesor',
            'assignment_id' => $assignmentId,
            'year_asked_id' => $year->id,
        ]);

        $this->withToken($this->tokenDe($this->usuarioDeTipo('Profesor')->username))
            ->putJson('/api/ChangesAsked/aceptar-asignatura', [
                'pedido' => [
                    'asked_id' => $askedId,
                    'profesor_id' => 999999,
                    'creditos_new' => 99,
                ],
            ])->assertStatus(200);

        $fila = DB::table('asignaturas')->where('id', $asignatura->id)->first();

        $this->assertEquals($solicitante->id, $fila->profesor_id,
            'La asignatura no quedó en el profesor que la pidió.');
        $this->assertEquals(7, $fila->creditos,
            'Los créditos salieron del cuerpo y no del pedido.');
    }
}
