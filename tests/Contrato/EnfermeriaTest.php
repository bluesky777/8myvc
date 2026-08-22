<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * Los antecedentes médicos del alumno, y quién los escribe.
 *
 * `enfermeria/guardar-valor` preguntaba `$this->user->tipo == 'Enfermero'`, y
 * `users.tipo` solo toma los cuatro valores del `switch` de `ContextoDeUsuario`.
 * Es la tercera de la familia de la §30.2 —el Secretario y el Psicólogo— con la
 * misma forma y **el mismo comentario del autor al lado**. Y de las que cierran
 * de más: la enfermera del colegio no podía escribir nada.
 *
 * Ver docs/migracion/05-codigo-muerto-y-roto.md §41.2.
 *
 * **El código del rechazo pasó de 401 a 403 el 21 ago 2026** (§54), y merece la
 * pena por qué no se tocó al escribir este test: la §41.2 entró a arreglar el
 * CRITERIO —quién puede escribir— y anotó el código que había, sin preguntárselo.
 * Es la tercera vez que pasa lo mismo en dos días, después de las dos de la §53:
 * **un test que fija lo que hay deja fijado también lo que estaba mal**, y lo
 * vuelve más difícil de ver, porque a partir de ahí hay un test verde que dice
 * que es así.
 *
 * El 401 importaba: `Sesion.ts` del front lo lee como «sesión caducada», rota los
 * tokens del que llama y en la carrera que documenta lo echa al login. A la
 * enfermera sin rol no se le decía «no puedes»; se le rompía la sesión.
 */
class EnfermeriaTest extends CasoDeContrato
{
    /** Una fila de antecedentes sobre la que escribir. */
    private function antecedente(): int
    {
        $alumno = DB::selectOne('SELECT id FROM alumnos WHERE deleted_at IS NULL ORDER BY id LIMIT 1');

        $fila = DB::table('antecedentes')->where('alumno_id', $alumno->id)->first();

        $id = (int) ($fila->id ?? DB::table('antecedentes')->insertGetId([
            'alumno_id' => $alumno->id,
        ]));

        // Se deja en un valor conocido: el seed trae la fila con `observaciones`
        // en NULL, y un test que parte de NULL no distingue «no se escribió» de
        // «se escribió un vacío».
        DB::table('antecedentes')->where('id', $id)->update(['observaciones' => 'ninguna']);

        return $id;
    }

    /** Un usuario con el rol `Enfermero` y sin superusuario. */
    private function enfermero(): object
    {
        $usuario = DB::selectOne('SELECT u.* FROM users u
            INNER JOIN periodos p ON p.id = u.periodo_id
            WHERE u.tipo = "Usuario" AND u.is_superuser = 0 AND u.is_active = 1
              AND u.deleted_at IS NULL ORDER BY u.id LIMIT 1');

        $this->assertNotNull($usuario, 'El seed necesita un administrativo sin superusuario.');

        $rol = DB::table('roles')->where('name', 'Enfermero')->first();
        $this->assertNotNull($rol, 'El rol Enfermero desapareció de la tabla.');

        DB::table('role_user')->insert(['user_id' => $usuario->id, 'role_id' => $rol->id]);

        return $usuario;
    }

    /**
     * La enfermera escribe los antecedentes, y antes no podía.
     *
     * Las dos mitades en la misma corrida —sin el rol no, con el rol sí—, que es
     * lo único que impide que el test pase por otra razón.
     */
    public function test_el_enfermero_escribe_los_antecedentes_solo_con_el_rol(): void
    {
        $antecedenteId = $this->antecedente();

        $usuario = DB::selectOne('SELECT u.* FROM users u
            INNER JOIN periodos p ON p.id = u.periodo_id
            WHERE u.tipo = "Usuario" AND u.is_superuser = 0 AND u.is_active = 1
              AND u.deleted_at IS NULL ORDER BY u.id LIMIT 1');

        $cuerpo = [
            'antec_id' => $antecedenteId,
            'propiedad' => 'observaciones',
            'valor' => 'alérgica al polen',
        ];

        $this->withToken($this->tokenDe($usuario->username))
            ->putJson('/api/enfermeria/guardar-valor', $cuerpo)
            ->assertStatus(403);

        $this->assertSame('ninguna',
            DB::table('antecedentes')->where('id', $antecedenteId)->value('observaciones'));

        DB::table('role_user')->insert([
            'user_id' => $usuario->id,
            'role_id' => DB::table('roles')->where('name', 'Enfermero')->value('id'),
        ]);

        $this->withToken($this->tokenDe($usuario->username))
            ->putJson('/api/enfermeria/guardar-valor', $cuerpo)
            ->assertStatus(200);

        $this->assertSame('alérgica al polen',
            DB::table('antecedentes')->where('id', $antecedenteId)->value('observaciones'),
            'El antecedente no llegó a escribirse con el rol puesto.');
    }

    /** Y un profesor cualquiera sigue sin poder, que es lo que ya hacía. */
    public function test_un_profesor_no_escribe_los_antecedentes(): void
    {
        $antecedenteId = $this->antecedente();

        $this->withToken($this->tokenDe($this->usuarioDeTipo('Profesor')->username))
            ->putJson('/api/enfermeria/guardar-valor', [
                'antec_id' => $antecedenteId,
                'propiedad' => 'observaciones',
                'valor' => 'lo escribió un profesor',
            ])->assertStatus(403);

        $this->assertSame('ninguna',
            DB::table('antecedentes')->where('id', $antecedenteId)->value('observaciones'));
    }

    /**
     * La columna se elige por lista blanca, no por lo que venga en el cuerpo.
     *
     * `ColumnaSegura::exigir()` es lo que separa esto de una inyección: la
     * `propiedad` se concatena en el SQL. Se fija aquí porque el día que alguien
     * la quite «porque estorba», esto lo cuenta.
     */
    public function test_una_propiedad_inventada_no_llega_al_sql(): void
    {
        $antecedenteId = $this->antecedente();
        $enfermero = $this->enfermero();

        $this->withToken($this->tokenDe($enfermero->username))
            ->putJson('/api/enfermeria/guardar-valor', [
                'antec_id' => $antecedenteId,
                'propiedad' => 'observaciones=1, updated_by=99 WHERE 1=1 -- ',
                'valor' => 'x',
            ])->assertStatus(422);

        $this->assertSame('ninguna',
            DB::table('antecedentes')->where('id', $antecedenteId)->value('observaciones'));
    }
}
