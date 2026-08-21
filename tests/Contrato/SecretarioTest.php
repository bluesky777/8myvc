<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * El rol `Secretario`, que once sitios buscaban y no existía.
 *
 * El código preguntaba por él de dos maneras que no podían ser las dos:
 * `Role::isSecretario($user_id)` —un rol que la tabla no tenía— y
 * `$this->user->tipo == 'Secretario'` —un valor que `users.tipo` no toma nunca,
 * porque solo son los cuatro del `switch` de `ContextoDeUsuario`—. Las dos
 * fallaban siempre, así que el criterio efectivo era `is_superuser` y la
 * consecuencia visible era la contraria de la que la línea pretendía:
 * **un administrativo sin superusuario no podía crear acudientes**.
 *
 * Ver docs/migracion/05-codigo-muerto-y-roto.md §30.2.
 *
 * **Por qué cada test se fabrica su Secretario.** El seed se genera desde la base
 * real y hace `TRUNCATE TABLE roles` antes de insertar los once, así que la fila
 * que crea la migración no sobrevive a `construir-bd-test.sh` — las migraciones
 * corren antes del seed. Y aunque sobreviviera, haría falta dársela a alguien:
 * lo que hay que comprobar es el caso que hoy no existe en ningún colegio, que es
 * **tener el rol sin `is_superuser`**.
 */
class SecretarioTest extends CasoDeContrato
{
    /** Un usuario del personal, sin superusuario, con el rol puesto. */
    private function secretario(): object
    {
        $usuario = DB::selectOne('SELECT u.* FROM users u
            INNER JOIN periodos p ON p.id = u.periodo_id
            WHERE u.tipo = "Usuario" AND u.is_superuser = 0 AND u.is_active = 1
              AND u.deleted_at IS NULL ORDER BY u.id LIMIT 1');

        $this->assertNotNull($usuario,
            'El seed no tiene ningún administrativo sin superusuario, que es el caso entero.');

        DB::table('role_user')->insert([
            'user_id' => $usuario->id,
            'role_id' => $this->idDelRol('Secretario'),
        ]);

        return $usuario;
    }

    /** El rol, creado dentro de la transacción del test. */
    private function idDelRol(string $nombre): int
    {
        $rol = DB::table('roles')->where('name', $nombre)->first();

        return (int) ($rol->id ?? DB::table('roles')->insertGetId([
            'name' => $nombre,
            'created_at' => now(),
            'updated_at' => now(),
        ]));
    }

    /**
     * Los datos mínimos de un acudiente, que es la ruta donde esto se veía.
     *
     * `tipo_doc` y `parentesco` viajan como objetos y no como valores —el
     * controlador hace `Request::input('tipo_doc')['id']`—, que es la forma que
     * manda el front y no una elección de este test.
     */
    private function acudienteNuevo(): array
    {
        $alumno = DB::selectOne('SELECT id FROM alumnos WHERE deleted_at IS NULL ORDER BY id LIMIT 1');

        return [
            'nombres' => 'Secretaría',
            'apellidos' => 'De Prueba',
            'sexo' => 'F',
            'documento' => '900'.random_int(100000, 999999),
            'tipo_doc' => ['id' => 1],
            'alumno_id' => $alumno->id,
            'parentesco' => ['parentesco' => 'Madre'],
        ];
    }

    /**
     * Lo que el arreglo abre: un administrativo sin superusuario crea acudientes.
     *
     * Se comprueban las dos mitades en la misma corrida —sin el rol no puede, con
     * el rol sí—, que es la única forma de que el test no pase por otra razón.
     */
    public function test_un_administrativo_sin_superusuario_crea_acudientes_solo_con_el_rol(): void
    {
        $usuario = DB::selectOne('SELECT u.* FROM users u
            INNER JOIN periodos p ON p.id = u.periodo_id
            WHERE u.tipo = "Usuario" AND u.is_superuser = 0 AND u.is_active = 1
              AND u.deleted_at IS NULL ORDER BY u.id LIMIT 1');

        $token = $this->tokenDe($usuario->username);

        $this->withToken($token)
            ->postJson('/api/acudientes/crear', $this->acudienteNuevo())
            ->assertStatus(403);

        DB::table('role_user')->insert([
            'user_id' => $usuario->id,
            'role_id' => $this->idDelRol('Secretario'),
        ]);

        $this->withToken($this->tokenDe($usuario->username))
            ->postJson('/api/acudientes/crear', $this->acudienteNuevo())
            ->assertStatus(200);
    }

    /**
     * Y lo que NO abre, que es la mitad que se decidió a mano.
     *
     * `esAdministrativo()` colgaba de sí seis llamadas, y crear el rol se las
     * habría dado todas sin que nadie lo decidiera. Las cuatro de aquí se
     * anclaron a `esSuperusuario` el mismo día: crear las cuentas del colegio
     * —«no crea usuarios» fue textual— y los tres borrados físicos, que arrastran
     * 20, 27 y 31 tablas en cascada y que la §28.4 ya había fijado como de
     * superusuario. La regla: **crear un rol no puede dar permisos que nadie
     * pidió**.
     */
    public function test_el_secretario_no_hereda_lo_que_nadie_le_dio(): void
    {
        $token = $this->tokenDe($this->secretario()->username);

        $this->withToken($token)
            ->putJson('/api/perfiles/creartodoslosusuarios')
            ->assertStatus(403);

        $profesor = DB::selectOne('SELECT id FROM profesores WHERE deleted_at IS NOT NULL
                                   ORDER BY id LIMIT 1')
            ?? DB::selectOne('SELECT id FROM profesores ORDER BY id LIMIT 1');

        $this->withToken($token)
            ->deleteJson('/api/profesores/forcedelete/'.$profesor->id)
            ->assertStatus(403);

        $this->assertNotNull(DB::selectOne('SELECT id FROM profesores WHERE id = ?', [$profesor->id]),
            'El profesor se borró de todas formas.');
    }

    /**
     * Las cuatro masivas sí son suyas, y esto es literal.
     *
     * «Puede cambiarle la contraseña/username a los alumnos y acudientes
     * solamente» — Joseth, 21 ago 2026. Que es exactamente lo que hacen las
     * cuatro rutas de `cambiar-usuarios`: no hay ninguna de profesores ni de
     * administrativos.
     */
    public function test_las_masivas_de_alumnos_y_acudientes_si_son_suyas(): void
    {
        $token = $this->tokenDe($this->secretario()->username);

        $this->withToken($token)
            ->putJson('/api/cambiar-usuarios/poner-documento-como-username-alumnos', ['confirmar' => true])
            ->assertStatus(200);
    }

    /**
     * El psicólogo escribe las necesidades educativas especiales, y antes no.
     *
     * La rama existía desde 2019 comparando `tipo` con `'Psicólogo'`, que `tipo`
     * no toma nunca, con la nota de su autor al lado diciendo que el criterio
     * bueno era el rol. Importa por lo que hay al lado: el PIAR solo lista
     * alumnos con `nee=1`, así que con la rama muerta el psicólogo podía trabajar
     * el PIAR pero no meter a nadie en él (05 §35.3).
     */
    public function test_el_psicologo_marca_las_necesidades_educativas(): void
    {
        $usuario = DB::selectOne('SELECT u.* FROM users u
            INNER JOIN periodos p ON p.id = u.periodo_id
            WHERE u.tipo = "Usuario" AND u.is_superuser = 0 AND u.is_active = 1
              AND u.deleted_at IS NULL ORDER BY u.id LIMIT 1');

        $alumno = DB::selectOne('SELECT a.id, a.user_id, a.nee FROM alumnos a
            INNER JOIN matriculas m ON m.alumno_id = a.id AND m.deleted_at IS NULL
                AND m.estado IN ("MATR","ASIS")
            WHERE a.deleted_at IS NULL AND (a.nee = 0 OR a.nee IS NULL)
            ORDER BY a.id LIMIT 1');

        $this->assertNotNull($alumno, 'El seed no tiene ningún alumno sin nee que marcar.');

        $cuerpo = [
            'alumno_id' => $alumno->id,
            'user_id' => $alumno->user_id,
            'propiedad' => 'nee',
            'valor' => 1,
        ];

        $this->withToken($this->tokenDe($usuario->username))
            ->putJson('/api/alumnos/guardar-valor', $cuerpo)
            ->assertStatus(400);

        $this->assertSame(0, (int) DB::table('alumnos')->where('id', $alumno->id)->value('nee'));

        DB::table('role_user')->insert([
            'user_id' => $usuario->id,
            'role_id' => $this->idDelRol('Psicólogo'),
        ]);

        $this->withToken($this->tokenDe($usuario->username))
            ->putJson('/api/alumnos/guardar-valor', $cuerpo)
            ->assertStatus(200);

        $this->assertSame(1, (int) DB::table('alumnos')->where('id', $alumno->id)->value('nee'),
            'La marca de necesidades educativas no llegó a escribirse.');
    }

    /** Y el psicólogo no se pasa de ahí: `nee` sí, el resto de la ficha no. */
    public function test_el_psicologo_solo_toca_las_necesidades_educativas(): void
    {
        $usuario = DB::selectOne('SELECT u.* FROM users u
            INNER JOIN periodos p ON p.id = u.periodo_id
            WHERE u.tipo = "Usuario" AND u.is_superuser = 0 AND u.is_active = 1
              AND u.deleted_at IS NULL ORDER BY u.id LIMIT 1');

        DB::table('role_user')->insert([
            'user_id' => $usuario->id,
            'role_id' => $this->idDelRol('Psicólogo'),
        ]);

        $alumno = DB::selectOne('SELECT a.id, a.user_id, a.direccion FROM alumnos a
            WHERE a.deleted_at IS NULL ORDER BY a.id LIMIT 1');

        $this->withToken($this->tokenDe($usuario->username))
            ->putJson('/api/alumnos/guardar-valor', [
                'alumno_id' => $alumno->id,
                'user_id' => $alumno->user_id,
                'propiedad' => 'direccion',
                'valor' => 'Calle que no debería quedar escrita',
            ])->assertStatus(400);

        $this->assertSame($alumno->direccion,
            DB::table('alumnos')->where('id', $alumno->id)->value('direccion'),
            'El psicólogo escribió una propiedad que no es suya.');
    }
}
