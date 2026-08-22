<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Las cuatro rutas de `UsersController` que nadie había mirado, y son de cuentas.
 *
 * El dominio pesa: de aquí salieron la §26.1 —cualquiera de los 51 profesores
 * reiniciaba la contraseña de todo el colegio—, la §29 —un docente se hacía con
 * la cuenta del superusuario— y la §30 —un profesor se fabricaba un superusuario
 * mandando `is_superuser: 1`—. Por eso, y siguiendo lo que dejó escrito la
 * [§54](../../docs/migracion/05-codigo-muerto-y-roto.md), **cada valor que este
 * test fija lleva al lado por qué es ese**: un test que fija lo que hay deja
 * fijado también lo que estaba mal, y a partir de ahí hay un verde que dice que
 * es así.
 *
 * El fichero es además el que más sitios de concatenación tenía del repo —nueve—
 * y ninguno resultó inyectable: están leídos uno a uno en la §56.
 */
class UsersCuentasTest extends CasoDeContrato
{
    /**
     * `usernames-check` con el texto vacío devuelve **todos** los usuarios.
     *
     * La consulta es `WHERE username LIKE :texto` con `$texto.'%'`, así que sin
     * texto el patrón es `%` y devuelve el colegio entero. La ruta es
     * `auth.personal` y el personal ve al colegio, así que **no es un fallo de
     * autorización** — es una enumeración completa de nombres de usuario en una
     * ruta que parece de autocompletado, y conviene tenerla fijada por si algún
     * día alguien le quita el guard.
     *
     * Lo que **no** se juzga aquí es si debería exigir un mínimo de texto: eso
     * cambiaría el contrato de la pantalla que la usa y no se ha leído.
     */
    public function test_usernames_check_sin_texto_devuelve_el_colegio_entero(): void
    {
        $token = $this->tokenDe($this->usuarioDeTipo('Usuario')->username);

        $r = $this->withToken($token)->putJson('/api/users/usernames-check', ['texto' => '']);

        $r->assertStatus(200);

        $devueltos = count($r->json('usernames'));
        $enLaBase = (int) DB::selectOne('SELECT COUNT(*) n FROM users')->n;

        $this->assertSame($enLaBase, $devueltos,
            'Con el texto vacío ya no devuelve todos: alguien puso un mínimo, y eso cambia el contrato de la pantalla.');

        // La forma es `{ usernames: [...] }` y cada elemento trae solo el
        // nombre. Que no salga nada más es lo que hay que vigilar: la §29 salió
        // de una respuesta de este dominio que devolvía de más.
        $this->assertSame(['username'], array_keys((array) $r->json('usernames.0')));
    }

    /**
     * Y devuelve también los **borrados**, porque la consulta no filtra `deleted_at`.
     *
     * El seed no trae ningún usuario borrado, así que sin plantarlo aquí este
     * test pasaría sin medir nada — que es el verde hueco que ya va por ocho
     * veces (09 §0.0).
     *
     * Queda fijado, no arreglado: quién debe ver los nombres de las cuentas
     * retiradas es una decisión del colegio, y filtrarlos aquí cambiaría lo que
     * ve una pantalla en dieciséis colegios sin avisar.
     */
    public function test_usernames_check_devuelve_tambien_los_borrados(): void
    {
        $token = $this->tokenDe($this->usuarioDeTipo('Usuario')->username);

        $borrado = 'retirado'.random_int(1000, 9999);

        DB::table('users')->insert([
            'username' => $borrado,
            'password' => '-',
            'tipo' => 'Usuario',
            'is_active' => 0,
            'deleted_at' => '2026-08-22 00:00:00',
            'created_at' => '2026-08-22 00:00:00',
            'updated_at' => '2026-08-22 00:00:00',
        ]);

        $r = $this->withToken($token)->putJson('/api/users/usernames-check', ['texto' => $borrado]);

        $r->assertStatus(200);

        $this->assertSame([$borrado], array_column($r->json('usernames'), 'username'),
            'La cuenta borrada dejó de salir: alguien añadió el filtro de `deleted_at`, que es un cambio de contrato.');
    }

    /**
     * Los tres `crear-*` crean la cuenta con su rol, y solo para el superusuario.
     *
     * Cada uno cuelga un rol distinto: administrador el 1, enfermero el 7 y
     * psicólogo el 11, escritos a mano en tres copias literales del mismo
     * método. La contraseña inicial es `123456` para los tres, y el nombre de
     * usuario se genera con `rand()`.
     *
     * **Ninguno de esos tres valores se juzga aquí**: se fijan porque son lo que
     * hay. Que la contraseña inicial sea una constante conocida y que el
     * usuario nazca activo es material de la §26 —donde una llamada sin clave
     * dejó a 1.280 alumnos con la contraseña vacía— y merece su propia
     * pregunta, que no es la de esta noche.
     */
    #[DataProvider('rutasDeCreacion')]
    public function test_el_superusuario_crea_la_cuenta_con_su_rol(string $ruta, int $rol): void
    {
        $superusuario = DB::selectOne('SELECT u.username FROM users u
            INNER JOIN periodos p ON p.id = u.periodo_id
            WHERE u.is_superuser = 1 AND u.is_active = 1 AND u.deleted_at IS NULL
            ORDER BY u.id LIMIT 1');

        $this->assertNotNull($superusuario, 'El seed necesita un superusuario con periodo.');

        $r = $this->withToken($this->tokenDe($superusuario->username))->postJson('/api/users/'.$ruta);

        $r->assertStatus(200);

        $creado = $r->json('usuario');

        $this->assertNotNull($creado['user_id']);

        // La respuesta no puede traer la contraseña. La §29 salió justo de eso:
        // un método de este dominio que devolvía la clave nueva en el cuerpo.
        $this->assertArrayNotHasKey('password', $creado);

        $roles = array_column(
            DB::select('SELECT role_id FROM role_user WHERE user_id = ?', [$creado['user_id']]),
            'role_id'
        );

        $this->assertSame([$rol], $roles, 'La cuenta nueva no quedó con el rol que le toca.');
    }

    public static function rutasDeCreacion(): array
    {
        return [
            'administrador' => ['crear-administrador', 1],
            'enfermero' => ['crear-enfermero', 7],
            'psicologo' => ['crear-psicologo', 11],
        ];
    }

    /**
     * Y a quien no es superusuario le contestan **403**, que antes era un 404.
     *
     * `abort(404, 'Sin autorización')` era exactamente la familia de la
     * [§54](../../docs/migracion/05-codigo-muerto-y-roto.md): un código que
     * significa «esa fila no está» usado para decir «no puedes», en un API donde
     * se gastó una serie entera —§44, §47, §49, §50, §53— en que 404 signifique
     * lo primero. Los cuatro de `calendario/*` que tenían este mismo defecto ya
     * pasaron a 403.
     *
     * **Estos tres no salieron entonces** porque aquel barrido cubría las rutas
     * de `auth.token` y estas son `auth.personal`.
     *
     * **Comprobado antes de cambiarlo**, con la misma comprobación que hizo
     * seguro el cambio de los ocho: en los cuatro clientes solo las llama
     * `myvc_front`, desde los tres botones de `UsuariosCtrl.ts`, y su `.catch`
     * **está declarado sin argumentos** —pinta un `toastr.error` con un texto
     * fijo del front—, así que no lee el código ni el cuerpo. Es un caso más
     * limpio todavía que los de `calendario/*`, que al menos pintaban el mensaje
     * del servidor.
     *
     * Y por eso se cambió ahora y no después: la pantalla de usuarios se está
     * reescribiendo, y en la versión nueva ese `catch` mudo va a enseñar el
     * mensaje del servidor. A partir de entonces un cambio de código sí se vería.
     */
    public function test_a_quien_no_es_superusuario_le_contestan_403(): void
    {
        $profesor = $this->usuarioDeTipo('Profesor');

        $this->assertSame(0, (int) $profesor->is_superuser,
            'El profesor del seed es superusuario: este test no mediría el rechazo.');

        $antes = (int) DB::selectOne('SELECT COUNT(*) n FROM users')->n;

        foreach (['crear-administrador', 'crear-enfermero', 'crear-psicologo'] as $ruta) {
            $this->withToken($this->tokenDe($profesor->username))
                ->postJson('/api/users/'.$ruta)
                ->assertStatus(403);
        }

        $this->assertSame($antes, (int) DB::selectOne('SELECT COUNT(*) n FROM users')->n,
            'El rechazo respondió pero la cuenta se creó igual: sería la §45 otra vez, al revés.');
    }
}
