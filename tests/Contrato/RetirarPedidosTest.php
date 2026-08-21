<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * Retirar un pedido de cambio.
 *
 * Sale de la cobertura: `ChangeAskedController` tenía cinco rutas sin ninguna
 * respuesta comprobada, y dos de ellas borraban por identificadores del cuerpo
 * sin mirar de quién eran. Ver 05 §49.
 *
 * El criterio —el dueño o el superusuario— no se eligió: sale de los dos únicos
 * sitios del front que llaman aquí. `ListAsignaturasCtrl.quitarSolicitud` retira
 * **el suyo**, y el modal de `AnunciosDir` se abre desde el panel de revisión, que
 * es `getToMe()` y exige `Usuario` y `is_superuser`.
 */
class RetirarPedidosTest extends CasoDeContrato
{
    /** Un pedido de asignatura hecho por el usuario que se le pase. */
    private function pedidoDe(int $userId): array
    {
        DB::insert('INSERT INTO change_asked_assignment (asignatura_to_remove_id, created_at) VALUES (NULL, ?)', [now()]);
        $assignment = (int) DB::getPdo()->lastInsertId();

        DB::insert('INSERT INTO change_asked (asked_by_user_id, tipo_user, year_asked_id, assignment_id, created_at)
                    VALUES (?, "Profesor", ?, ?, ?)', [$userId, 8, $assignment, now()]);

        return [(int) DB::getPdo()->lastInsertId(), $assignment];
    }

    private function otroProfesor(): object
    {
        $otro = DB::selectOne('SELECT u.id, u.username FROM users u
            INNER JOIN profesores p ON p.user_id = u.id AND p.deleted_at IS NULL
            WHERE u.tipo = "Profesor" AND u.is_active = 1 AND u.deleted_at IS NULL
            ORDER BY u.id DESC LIMIT 1');

        $this->assertNotNull($otro, 'El seed no tiene un segundo profesor.');

        return $otro;
    }

    /** Un docente retira el suyo, que es lo que hace el botón del front. */
    public function test_un_docente_retira_su_propio_pedido(): void
    {
        $yo = $this->usuarioDeTipo('Profesor');
        [$asked, $assignment] = $this->pedidoDe((int) $yo->id);

        $this->withToken($this->tokenDe($yo->username))
            ->putJson('/api/ChangesAsked/destruir-pedido-asignatura', [
                'asked_id' => $asked, 'assignment_id' => $assignment,
            ])->assertStatus(200);

        $this->assertSame(0, DB::table('change_asked')->where('id', $asked)->count());
    }

    /**
     * El hallazgo: **cualquiera de los 71 que pasan `auth.personal` hacía
     * desaparecer el pedido pendiente de otro**, y quien lo revisa no llega a
     * verlo nunca.
     */
    public function test_un_docente_no_retira_el_pedido_de_otro(): void
    {
        $otro = $this->otroProfesor();
        [$asked, $assignment] = $this->pedidoDe((int) $otro->id);

        $this->withToken($this->tokenDe($this->usuarioDeTipo('Profesor')->username))
            ->putJson('/api/ChangesAsked/destruir-pedido-asignatura', [
                'asked_id' => $asked, 'assignment_id' => $assignment,
            ])->assertStatus(403);

        $this->assertSame(1, DB::table('change_asked')->where('id', $asked)->count(),
            'El pedido del otro sigue ahí.');
    }

    /** El superusuario sí, porque es quien revisa: `getToMe()` es suyo. */
    public function test_el_superusuario_retira_el_pedido_de_cualquiera(): void
    {
        $otro = $this->otroProfesor();
        [$asked, $assignment] = $this->pedidoDe((int) $otro->id);

        $super = DB::selectOne('SELECT username FROM users
            WHERE is_superuser = 1 AND is_active = 1 AND deleted_at IS NULL ORDER BY id LIMIT 1');

        $this->withToken($this->tokenDe($super->username))
            ->putJson('/api/ChangesAsked/destruir-pedido-asignatura', [
                'asked_id' => $asked, 'assignment_id' => $assignment,
            ])->assertStatus(200);

        $this->assertSame(0, DB::table('change_asked')->where('id', $asked)->count());
    }

    /**
     * La §39 otra vez: los otros dos identificadores viajaban en el cuerpo, así
     * que se podían borrar filas de `change_asked_assignment` de **otro** pedido
     * con solo nombrarlas. Ahora se derivan de la fila.
     */
    public function test_no_se_borra_el_anexo_de_otro_pedido_nombrandolo(): void
    {
        $yo = $this->usuarioDeTipo('Profesor');
        [$mio] = $this->pedidoDe((int) $yo->id);
        [, $assignmentAjeno] = $this->pedidoDe((int) $this->otroProfesor()->id);

        $this->withToken($this->tokenDe($yo->username))
            ->putJson('/api/ChangesAsked/destruir-pedido-asignatura', [
                'asked_id' => $mio, 'assignment_id' => $assignmentAjeno,
            ])->assertStatus(200);

        $this->assertSame(1, DB::table('change_asked_assignment')->where('id', $assignmentAjeno)->count(),
            'El anexo del pedido ajeno sigue ahí: el id del cuerpo ya no manda.');
    }

    /** Un pedido que no existe era 200 diciendo «borrar: 0». */
    public function test_un_pedido_que_no_existe_es_404(): void
    {
        $maximo = (int) DB::table('change_asked')->max('id');

        $this->withToken($this->tokenDe($this->usuarioDeTipo('Profesor')->username))
            ->putJson('/api/ChangesAsked/destruir', ['asked_id' => $maximo + 1000])
            ->assertStatus(404);
    }

    /**
     * Rechazar es de la bandeja de revisión, y esa bandeja es `getToMe()`, que
     * exige `Usuario` y `is_superuser`. Quien no es superusuario **no puede ni ver
     * la lista desde la que se rechaza**, así que cerrarlo no le quita nada por el
     * front: cierra la llamada directa. Antes no comprobaba nada.
     */
    public function test_un_docente_no_rechaza_un_pedido(): void
    {
        [$asked] = $this->pedidoDe((int) $this->otroProfesor()->id);

        $this->withToken($this->tokenDe($this->usuarioDeTipo('Profesor')->username))
            ->putJson('/api/ChangesAsked/rechazar', ['asked_id' => $asked, 'tipo' => 'nombres'])
            ->assertStatus(403);
    }

    /** Y ver los detalles del pedido de otro tampoco. */
    public function test_un_docente_no_ve_los_detalles_del_pedido_de_otro(): void
    {
        [$asked] = $this->pedidoDe((int) $this->otroProfesor()->id);

        $this->withToken($this->tokenDe($this->usuarioDeTipo('Profesor')->username))
            ->putJson('/api/ChangesAsked/ver-detalles', ['asked_id' => $asked])
            ->assertStatus(403);
    }

    /** Los suyos sí, que es el criterio de la §49 y no «solo el superusuario». */
    public function test_un_docente_ve_los_detalles_de_su_propio_pedido(): void
    {
        $yo = $this->usuarioDeTipo('Profesor');
        [$asked] = $this->pedidoDe((int) $yo->id);

        $this->withToken($this->tokenDe($yo->username))
            ->putJson('/api/ChangesAsked/ver-detalles', ['asked_id' => $asked])
            ->assertStatus(200);
    }

    /**
     * `detalles()` indexa con `[0]` el resultado de su consulta, así que un id que
     * no existe era un 500 — la forma de la §44 y la §47.
     */
    public function test_los_detalles_de_un_pedido_que_no_existe_son_404(): void
    {
        $maximo = (int) DB::table('change_asked')->max('id');

        $this->withToken($this->tokenDe($this->usuarioDeTipo('Profesor')->username))
            ->putJson('/api/ChangesAsked/ver-detalles', ['asked_id' => $maximo + 1000])
            ->assertStatus(404);
    }

    /**
     * `solicitar-cambios`, la última ruta del controlador que nadie miraba. Es la
     * pantalla «pedir que me corrijan mis datos», y el front solo la pinta para
     * alumnos —`userConfiguracion.html` lleva `ng-if="perfilactual.tipo=='Al'"`—,
     * que es por qué el controlador solo tiene esa rama. `'Al'` es el código corto
     * del front y no `users.tipo`, que vale `'Alumno'`: son dos vocabularios.
     */
    public function test_se_pide_un_cambio_de_datos(): void
    {
        $alumno = DB::selectOne('SELECT id, nombres FROM alumnos WHERE deleted_at IS NULL ORDER BY id LIMIT 1');
        $antes = DB::table('change_asked')->count();

        $r = $this->withToken($this->tokenDe($this->usuarioDeTipo('Profesor')->username))
            ->putJson('/api/ChangesAsked/solicitar-cambios', [
                'tipo' => 'Al', 'persona_id' => $alumno->id, 'nombres' => 'Nombre Distinto',
            ]);

        $r->assertStatus(200);
        $this->assertSame($antes + 1, DB::table('change_asked')->count());
    }

    /** Pedir lo que ya vale no crea pedido: el cuerpo se compara con la ficha. */
    public function test_pedir_el_mismo_nombre_no_crea_pedido(): void
    {
        $alumno = DB::selectOne('SELECT id, nombres FROM alumnos WHERE deleted_at IS NULL ORDER BY id LIMIT 1');
        $antes = DB::table('change_asked')->count();

        $this->withToken($this->tokenDe($this->usuarioDeTipo('Profesor')->username))
            ->putJson('/api/ChangesAsked/solicitar-cambios', [
                'tipo' => 'Al', 'persona_id' => $alumno->id, 'nombres' => $alumno->nombres,
            ])->assertStatus(200);

        $this->assertSame($antes, DB::table('change_asked')->count());
    }

    /** Y un alumno que no existe era 500. */
    public function test_pedir_cambios_de_un_alumno_que_no_existe_es_404(): void
    {
        $maximo = (int) DB::table('alumnos')->max('id');

        $this->withToken($this->tokenDe($this->usuarioDeTipo('Profesor')->username))
            ->putJson('/api/ChangesAsked/solicitar-cambios', [
                'tipo' => 'Al', 'persona_id' => $maximo + 1000, 'nombres' => 'Inventado',
            ])->assertStatus(404);
    }
}
