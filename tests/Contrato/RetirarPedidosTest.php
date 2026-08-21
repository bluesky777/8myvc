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
}
