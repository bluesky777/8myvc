<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * `perfiles/destroy` no borra un perfil: manda un GRUPO a la papelera — §100.
 *
 * Es la misma forma que acaba de salir en `boletines2/destroy` (§89), que no
 * borra un boletín sino un alumno: **el nombre del fichero no dice sobre qué
 * tabla escribe.** Aquí, además, hay un cliente enchufado: la rejilla de
 * Usuarios del front llama `PerfilesApi.eliminar(row.user_id)` y el backend hace
 * `Grupo::findOrFail($id)->delete()` con ese `user_id`.
 *
 * O sea que pulsar «Eliminar» sobre un usuario **deja al usuario donde estaba y
 * manda a la papelera el grupo cuyo id coincide con su `user_id`**. El front ya
 * tiene escrito en `PerfilesApi` que cinco métodos de este controlador operan
 * sobre grupo —«Si alguien añade obtener(id) por analogía, va a devolver un
 * grupo y parecerá que funciona»— y aun así el botón sigue enchufado ahí.
 *
 * **Lo que este test hace es fijarlo, no arreglarlo**: cambiar lo que borra esa
 * ruta es una decisión, y la regla del repo para lo roto con ruta es
 * documentarlo. Lo que sí se cierra es su autorización, que no necesita
 * decisión ninguna: sus dos hermanas de la papelera en este mismo fichero
 * —`forcedelete` y `restore`— piden superusuario desde la §28.4 y la §76, y ésta
 * se había quedado con `auth.personal` a secas. Es el §97 otra vez, en el
 * controlador de al lado.
 */
class BorrarUnPerfilBorraUnGrupoTest extends CasoDeContrato
{
    /**
     * El superusuario borra «un usuario» y lo que cae es el grupo.
     *
     * Se elige a propósito una cuenta cuyo `user_id` coincida con el id de un
     * grupo vivo: es la situación real, no una inventada — los dos son enteros
     * pequeños de tablas distintas y en una base de colegio se solapan casi
     * enteros.
     */
    public function test_borrar_un_usuario_manda_a_la_papelera_el_grupo_con_ese_id(): void
    {
        $jefe = $this->tokenDeUnSuperusuario();

        $grupo = DB::selectOne('SELECT g.id FROM grupos g
            INNER JOIN users u ON u.id = g.id AND u.deleted_at IS NULL
            WHERE g.deleted_at IS NULL ORDER BY g.id LIMIT 1');

        $this->assertNotNull($grupo,
            'El seed no tiene ningún grupo cuyo id coincida con el de una cuenta viva.');

        $r = $this->withToken($jefe)->deleteJson('/api/perfiles/destroy/'.$grupo->id, []);
        $r->assertStatus(200);

        // La respuesta es la fila de `grupos`, con su `grado_id`: ni siquiera
        // disimula. El front la lee como si fuera el usuario borrado.
        $this->assertArrayHasKey('grado_id', $r->json(),
            'La respuesta dejó de ser un grupo — entonces el §100 ya no describe lo que hay.');

        $this->assertNotNull(DB::table('grupos')->where('id', $grupo->id)->value('deleted_at'),
            'El grupo no fue a la papelera.');

        $this->assertNull(DB::table('users')->where('id', $grupo->id)->value('deleted_at'),
            'Ahora sí borra al usuario: eso es nuevo y hay que medirlo antes de celebrarlo.');
    }

    /**
     * Y ya no la alcanza cualquiera de los 51 profesores.
     *
     * Nadie pierde un botón que hoy vea: la rejilla de Usuarios vive en un menú
     * que el front enseña con `hasRoleOrPerm('admin')`, y los diez `Admin` son
     * exactamente los diez `is_superuser` (§28.4).
     */
    public function test_un_profesor_cualquiera_ya_no_borra_por_ahi(): void
    {
        $token = $this->tokenDe($this->usuarioDeTipo('Profesor')->username);

        $grupo = DB::selectOne('SELECT id FROM grupos WHERE deleted_at IS NULL ORDER BY id LIMIT 1');

        $this->assertSame(403,
            $this->withToken($token)->deleteJson('/api/perfiles/destroy/'.$grupo->id, [])->status(),
            'Un profesor cualquiera mandó un grupo a la papelera por la puerta de perfiles — §100.');

        $this->assertNull(DB::table('grupos')->where('id', $grupo->id)->value('deleted_at'),
            'El rechazo borró el grupo antes de contestar que no.');
    }

    /**
     * La gemela sigue abierta, y se fija para que se vea.
     *
     * `grupos/destroy` hace exactamente lo mismo —`Grupo::find($id)->delete()`,
     * con `auth.personal` y sin autorización— y **`GruposController` no es de
     * este lote**: se anota y no se toca, que es la regla. Este test es la prueba
     * de que cerrar una mitad no cierra la operación, escrita en verde para que
     * el día que se cierre la otra alguien tenga que venir aquí a borrarlo.
     */
    public function test_la_gemela_de_grupos_sigue_abierta_a_cualquier_profesor(): void
    {
        $token = $this->tokenDe($this->usuarioDeTipo('Profesor')->username);

        $grupo = DB::selectOne('SELECT id FROM grupos WHERE deleted_at IS NULL ORDER BY id LIMIT 1');

        $this->assertSame(200,
            $this->withToken($token)->deleteJson('/api/grupos/destroy/'.$grupo->id, [])->status(),
            'Se cerró `grupos/destroy`: quita este test y anótalo, que es la mitad que faltaba.');

        $this->assertNotNull(DB::table('grupos')->where('id', $grupo->id)->value('deleted_at'),
            'Contestó 200 y no lo mandó a la papelera.');
    }

    /** Igual que en `PapeleraRestaurarTest`: por la columna, que es lo que el código pregunta. */
    private function tokenDeUnSuperusuario(): string
    {
        $jefe = DB::selectOne('SELECT u.username FROM users u
            INNER JOIN periodos p ON p.id = u.periodo_id
            WHERE u.is_superuser = 1 AND u.is_active = 1 AND u.deleted_at IS NULL
            ORDER BY u.id LIMIT 1');

        $this->assertNotNull($jefe, 'El seed no tiene ningún superusuario con contexto completo.');

        return $this->tokenDe($jefe->username);
    }
}
