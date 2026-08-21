<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * El nombre de usuario que se fabrica cuando la persona no trae documento.
 *
 * Es el generador que **de verdad se usa** —`OperacionesAlumnos::username_no_repetido()`,
 * al que llaman el importador de alumnos y las dos puertas de acudientes—, y no
 * el de `perfiles/creartodoslosusuarios` de la §12: aquel moría en un
 * `attachRole()` de Entrust antes de enlazar la ficha, así que en años no creó
 * ninguna cuenta usable. Medido el 21 ago 2026: **cero** usernames mutilados en
 * la base, y 63 usuarios activos sin ficha, que es lo que aquel sí dejaba.
 *
 * Lo que estos tests fijan es lo que se veía en los datos y nadie leía como un
 * fallo, porque un username raro se lee como un dato del alumno:
 *
 * - `SamuelSamuel12345`, `MatíasMatías1234`, `MariaJoséMariaJosé12` — el sufijo
 *   se acumulaba sobre el candidato anterior en vez de sobre la base.
 * - la cuenta con el username **vacío** de 2019, que con `users.username` UNIQUE
 *   convierte al segundo nombre en blanco en un 500.
 *
 * Ver docs/migracion/12-larastan-nivel-7.md §14.
 */
class UsernameGeneradoTest extends CasoDeContrato
{
    private function tokenDelSuperusuario(): string
    {
        $fila = DB::selectOne('SELECT u.username FROM users u
            INNER JOIN periodos p ON p.id = u.periodo_id
            WHERE u.tipo = "Usuario" AND u.is_superuser = 1 AND u.is_active = 1
              AND u.deleted_at IS NULL ORDER BY u.id LIMIT 1');

        $this->assertNotNull($fila, 'El seed no tiene ningún superusuario con periodo.');

        return $this->tokenDe($fila->username);
    }

    /**
     * `acudientes/crear` **sin documento**, que es la rama que fabrica el nombre.
     *
     * Con documento el username es el documento y no pasa por el generador; ese
     * caso ya lo fija `AcudientesTest`.
     */
    private function crearAcudienteLlamado(string $nombres): object
    {
        $alumno = DB::selectOne('SELECT id FROM alumnos WHERE deleted_at IS NULL ORDER BY id LIMIT 1');

        $this->withToken($this->tokenDelSuperusuario())
            ->postJson('/api/acudientes/crear', [
                'nombres' => $nombres,
                'apellidos' => 'De Prueba',
                'sexo' => 'F',
                'celular' => '3000000000',
                'alumno_id' => $alumno->id,
                'parentesco' => ['parentesco' => 'Madre'],
            ])->assertStatus(200);

        $acudiente = DB::selectOne('SELECT id, user_id FROM acudientes
            WHERE apellidos = "De Prueba" ORDER BY id DESC LIMIT 1');

        $this->assertNotNull($acudiente, 'El acudiente no llegó a la tabla.');
        $this->assertNotNull($acudiente->user_id, 'El acudiente se creó sin cuenta.');

        return DB::selectOne('SELECT id, username FROM users WHERE id = ?', [$acudiente->user_id]);
    }

    /** Un username libre se usa tal cual: el sufijo es para las colisiones. */
    public function test_sin_colision_el_username_es_el_nombre(): void
    {
        $usuario = $this->crearAcudienteLlamado('Zutanita');

        $this->assertSame('Zutanita', $usuario->username);
    }

    /**
     * Y con dos colisiones sale `Zutanita2`, no `Zutanita12`.
     *
     * El bucle hacía `$username = $username.$i` sobre el candidato anterior, así
     * que crecía un carácter por colisión: a la quinta, `Samuel` era
     * `Samuel12345`. No es cosmético — es lo que el acudiente tiene que teclear
     * para entrar, y es lo que hay escrito hoy en las cuentas de este colegio.
     */
    public function test_el_sufijo_no_se_acumula(): void
    {
        DB::insert('INSERT INTO users (username, password, created_at, updated_at)
            VALUES ("Zutanita", "x", NOW(), NOW()), ("Zutanita1", "x", NOW(), NOW())');

        $usuario = $this->crearAcudienteLlamado('Zutanita');

        $this->assertSame('Zutanita2', $usuario->username,
            'El sufijo se está acumulando sobre el candidato anterior otra vez.');
    }

    /**
     * Con el nombre en blanco cae a `{tipo}{id}` en vez de a la cadena vacía.
     *
     * Va por `acudientes/crear-usuario` y no por `crear` a propósito: por `crear`
     * un nombre en blanco no llega nunca al generador —`acudientes.nombres` es
     * NOT NULL y el `ConvertEmptyStringsToNull` de Laravel lo convierte en null,
     * así que el INSERT del acudiente falla antes—. Por esta otra puerta el
     * acudiente **ya existe** y lo único que se crea es la cuenta, así que el
     * nombre en blanco sí llega.
     *
     * Y aquí está lo que lo saca de «cosmético»: `users.username` es UNIQUE y en
     * la base hay una cuenta con el username vacío desde 2019 —usuario 842, un
     * acudiente activo—, así que el segundo nombre en blanco no creaba una cuenta
     * inservible: **reventaba con clave duplicada**, y este método no tiene
     * `catch`, o sea 500.
     */
    public function test_un_nombre_en_blanco_no_choca_con_la_cuenta_vacia(): void
    {
        DB::insert('INSERT INTO users (username, password, created_at, updated_at)
            VALUES ("", "x", NOW(), NOW())');

        $acudiente = DB::selectOne('SELECT id FROM acudientes
            WHERE deleted_at IS NULL ORDER BY id DESC LIMIT 1');

        $this->withToken($this->tokenDelSuperusuario())
            ->postJson('/api/acudientes/crear-usuario', [
                'acudiente' => ['id' => $acudiente->id, 'nombres' => '', 'sexo' => 'F'],
            ])
            // 201 y no 200: el método devuelve el modelo recién creado, y Laravel
            // le pone «Created» a un modelo con `wasRecentlyCreated`. Va anotado
            // porque es contrato con el cliente, no un detalle del test.
            ->assertStatus(201);

        $usuario = DB::selectOne('SELECT u.username FROM users u
            INNER JOIN acudientes a ON a.user_id = u.id WHERE a.id = ?', [$acudiente->id]);

        $this->assertNotNull($usuario, 'El acudiente se quedó sin cuenta.');

        $this->assertSame('acudiente'.$acudiente->id, $usuario->username,
            'Un nombre en blanco volvió a dar el username vacío, que ya está ocupado desde 2019.');
    }

    /**
     * Las tildes se transliteran, no se borran.
     *
     * No es un problema de acceso —`users.username` es `utf8mb4_unicode_ci`, así
     * que `JoseAndres` y `JoséAndrés` son el mismo valor para MySQL— sino de que
     * un identificador acaba en sitios que no son MySQL: el correo autogenerado
     * de la §9 es `username@myvc.com`, y con tilde `filter_var` lo rechaza.
     */
    public function test_las_tildes_se_transliteran(): void
    {
        $usuario = $this->crearAcudienteLlamado('José Andrés');

        $this->assertSame('JoseAndres', $usuario->username);
    }
}
