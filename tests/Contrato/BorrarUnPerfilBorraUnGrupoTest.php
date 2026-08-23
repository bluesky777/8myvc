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
     * Y la gemela, que era la mitad de verdad — cerrada en el mismo commit.
     *
     * `grupos/destroy` hace exactamente lo mismo: `Grupo::find($id)->delete()`
     * con `auth.personal` y sin autorización. **De las cuatro operaciones de
     * papelera de un grupo, las dos que borran estaban abiertas y las dos que
     * deshacen pedían superusuario** — la pareja al revés de como suele salir.
     *
     * Hasta el 23 ago 2026 aquí había un test EN VERDE afirmando que seguía
     * abierta, escrito para que quien la cerrara tuviera que venir a borrarlo.
     * Esto es lo que lo sustituye, que es como se cierra una población: no
     * quitando el test, cambiándole el valor esperado.
     */
    public function test_la_gemela_de_grupos_tambien_esta_cerrada(): void
    {
        $token = $this->tokenDe($this->usuarioDeTipo('Profesor')->username);

        $grupo = DB::selectOne('SELECT id FROM grupos WHERE deleted_at IS NULL ORDER BY id LIMIT 1');

        $this->assertSame(403,
            $this->withToken($token)->deleteJson('/api/grupos/destroy/'.$grupo->id, [])->status(),
            'Un profesor cualquiera mandó un grupo a la papelera por `grupos/destroy` — §100.');

        $this->assertNull(DB::table('grupos')->where('id', $grupo->id)->value('deleted_at'),
            'El rechazo borró el grupo antes de contestar que no.');
    }

    /**
     * Y el superusuario sigue borrando por las dos puertas.
     *
     * Sin esto, los dos `abort(403)` darían verde arriba y habrían apagado la X
     * de la rejilla de grupos para los diez que la usan.
     */
    public function test_el_superusuario_sigue_borrando_por_las_dos_puertas(): void
    {
        $jefe = $this->tokenDeUnSuperusuario();

        foreach (['perfiles', 'grupos'] as $puerta) {
            $grupo = DB::selectOne('SELECT id FROM grupos WHERE deleted_at IS NULL ORDER BY id LIMIT 1');

            $this->withToken($jefe)->deleteJson('/api/'.$puerta.'/destroy/'.$grupo->id, [])
                ->assertStatus(200);

            $this->assertNotNull(DB::table('grupos')->where('id', $grupo->id)->value('deleted_at'),
                "`{$puerta}/destroy` contestó 200 y no mandó el grupo a la papelera.");
        }
    }

    /**
     * Y `perfiles/forcedelete` es la tercera puerta al mismo grupo — §100.
     *
     * Ésta sí pedía superusuario desde la §28.4, pero **nadie había mirado su
     * respuesta**: la exención decía quién no pasa y ahí se quedó. Lo que faltaba
     * es que hace `forceDelete()`, o sea borrado FÍSICO con la cascada de 27
     * tablas hasta `notas`, y que **solo funciona desde la papelera** — con el
     * grupo vivo, `onlyTrashed()` no lo encuentra y contesta 404, no 200.
     *
     * Se monta un grupo desechable en vez de usar uno del seed: la transacción del
     * test lo desharía igual, pero un `forceDelete` sobre un grupo de verdad
     * arrastra una cascada larga y un test no debería depender de que la
     * transacción la deshaga entera. Es el mismo cuidado que ya tenía
     * `GruposTest`.
     */
    public function test_forcedelete_por_la_puerta_de_perfiles_borra_de_verdad(): void
    {
        $jefe = $this->tokenDeUnSuperusuario();
        $profesor = $this->tokenDe($this->usuarioDeTipo('Profesor')->username);

        $base = DB::selectOne('SELECT year_id, grado_id FROM grupos WHERE deleted_at IS NULL ORDER BY id LIMIT 1');

        $id = DB::table('grupos')->insertGetId([
            'nombre' => 'Grupo desechable del lote E',
            'abrev' => 'GDE',
            'year_id' => $base->year_id,
            'grado_id' => $base->grado_id,
            'orden' => 998,
        ]);

        $this->assertSame(403,
            $this->withToken($profesor)->deleteJson('/api/perfiles/forcedelete/'.$id, [])->status(),
            'Un profesor cualquiera borró un grupo definitivamente por la puerta de perfiles.');

        // Vivo, no en la papelera: `onlyTrashed()` no lo encuentra.
        $this->assertSame(404,
            $this->withToken($jefe)->deleteJson('/api/perfiles/forcedelete/'.$id, [])->status(),
            'Borró definitivamente un grupo que no estaba en la papelera.');

        DB::update('UPDATE grupos SET deleted_at = ? WHERE id = ?', ['2026-08-23 02:00:00', $id]);

        $this->withToken($jefe)->deleteJson('/api/perfiles/forcedelete/'.$id, [])->assertStatus(200);

        $this->assertNull(DB::table('grupos')->where('id', $id)->first(),
            'Contestó 200 y dejó la fila: `forcedelete` es borrado físico.');
    }

    /**
     * Los cinco métodos engañosos, y el que se separó de su gemelo — §104.
     *
     * `PerfilesApi.ts` avisa de que **cinco** métodos de este controlador operan
     * sobre GRUPO y no sobre persona: `show`, `destroy`, `forcedelete`, `restore`
     * y `trashed`. Los dos de borrar ya están medidos arriba; éste cierra los tres
     * que faltaban, porque **medir media población es lo que ha mordido tres veces
     * esta noche**.
     *
     * De los tres, dos son copias fieles de `GruposController` y **uno no**:
     *
     * ```
     * GruposController::getShow    ->  Profesor::find($grupo->titular_id)
     * PerfilesController::getShow  ->  Profesor::findOrFail($grupo->titular_id)
     * ```
     *
     * `grupos.titular_id` es **nullable** —un grupo puede no tener titular, y el
     * formulario de «Nuevo grupo» no obliga a elegirlo—, así que con esa fila las
     * dos rutas contestan cosas distintas: la de grupos devuelve el grupo con
     * `titular: null`, y la de perfiles **404, diciendo que no existe un grupo que
     * sí existe**.
     *
     * Se alinea con su gemela, que es la que tiene razón: un grupo sin titular no
     * es un grupo que falte. No lo llama ningún cliente ([§14.2]) — o sea que era
     * una mina, como el §102 — pero es una palabra y deja la población entera
     * diciendo lo mismo.
     */
    public function test_un_grupo_sin_titular_sale_por_las_dos_puertas(): void
    {
        $jefe = $this->tokenDeUnSuperusuario();

        $base = DB::selectOne('SELECT year_id, grado_id FROM grupos WHERE deleted_at IS NULL ORDER BY id LIMIT 1');

        // El seed no trae ninguno: `titular_id` es nullable pero hoy están todos
        // puestos, así que la fila que separa a las dos rutas hay que fabricarla.
        $sinTitular = DB::table('grupos')->insertGetId([
            'nombre' => 'Grupo sin titular',
            'abrev' => 'GST',
            'year_id' => $base->year_id,
            'grado_id' => $base->grado_id,
            'orden' => 996,
            'titular_id' => null,
        ]);

        foreach (['grupos', 'perfiles'] as $puerta) {
            $r = $this->withToken($jefe)->getJson('/api/'.$puerta.'/show/'.$sinTitular);

            $this->assertSame(200, $r->status(),
                "`{$puerta}/show` contestó ".$r->status().' con un grupo sin titular, que es una '
                .'fila legítima: `titular_id` es nullable — §104.');

            $this->assertNull($r->json('titular'),
                "`{$puerta}/show` se inventó un titular para un grupo que no lo tiene.");
        }
    }

    /** `perfiles/trashed` es la papelera de GRUPOS, y devuelve lo mismo que su gemela. */
    public function test_la_papelera_de_perfiles_es_la_de_grupos(): void
    {
        $jefe = $this->tokenDeUnSuperusuario();

        $grupo = DB::selectOne('SELECT id FROM grupos WHERE deleted_at IS NULL ORDER BY id LIMIT 1');
        DB::update('UPDATE grupos SET deleted_at = ? WHERE id = ?', ['2026-08-23 03:00:00', $grupo->id]);

        $porPerfiles = $this->withToken($jefe)->getJson('/api/perfiles/trashed');
        $porGrupos = $this->withToken($jefe)->getJson('/api/grupos/trashed');

        $porPerfiles->assertStatus(200);
        $porGrupos->assertStatus(200);

        $this->assertSame($porGrupos->json(), $porPerfiles->json(),
            '`perfiles/trashed` y `grupos/trashed` dejaron de devolver lo mismo: son el mismo '
            .'método bajo dos URL, y si divergen es que alguien tocó una sola — §104.');

        $this->assertContains((int) $grupo->id,
            array_map(static fn ($g) => (int) $g['id'], $porPerfiles->json()),
            'La papelera no trae el grupo que acabamos de mandar allí.');
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
