<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * El historial de sesiones y la bitácora de cambios.
 *
 * Tercer hueco de `routes/api/informes.php`: `HistorialesController` estaba a
 * **1 de 4**. Es la pantalla con la que el colegio contesta «¿quién cambió esta
 * nota?» y «¿quién ha intentado entrar en esta cuenta?».
 *
 * Y por eso lo que devuelve pesa: `historiales` guarda **la IP, el navegador, la
 * plataforma y el modelo del dispositivo** de cada entrada, y `bitacoras` guarda
 * el valor viejo y el nuevo de cada nota tocada.
 *
 * Las cuatro rutas llevan `auth.personal`, o sea los 51 profesores. Ninguna
 * comprueba de quién es el historial que se pide.
 *
 * Las dos tablas están vacías en el seed, así que las filas las monta el test.
 */
class HistorialesTest extends CasoDeContrato
{
    private function personal(): object
    {
        $grupo = $this->grupoConAlumnos();

        $usuario = DB::selectOne('SELECT u.id, u.username FROM users u
            INNER JOIN periodos p ON p.id = u.periodo_id AND p.deleted_at IS NULL
            WHERE u.tipo = "Usuario" AND u.is_active = 1 AND u.deleted_at IS NULL
              AND p.year_id = ? ORDER BY u.id LIMIT 1', [$grupo->year_id]);

        $this->assertNotNull($usuario, "El seed no tiene personal en el año {$grupo->year_id}.");

        return (object) [
            'user_id' => (int) $usuario->id,
            'username' => $usuario->username,
            'token' => $this->tokenDe($usuario->username),
        ];
    }

    /** Una sesión anotada, con lo que de verdad guarda la tabla. */
    private function unaSesionDe(int $userId): int
    {
        return DB::table('historiales')->insertGetId([
            'user_id' => $userId,
            'tipo' => 'login',
            'ip' => '190.85.44.7',
            'browser_name' => 'Chrome',
            'platform_name' => 'Android',
            'device_model' => 'SM-A155M',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * **Los intentos de entrar en una cuenta salen siempre vacíos.**
     *
     * `Services\Login::anotarIntentoFallido()` escribe el **`username`** en
     * `affected_person_name`, que es una columna de nombre. Y
     * `HistorialCalc::intentos_fallidos_de_usuario($user_id)` la busca pasándole
     * **el `user_id`**, que es un número. Nunca casan.
     *
     * O sea que la pantalla que contesta «¿quién ha intentado entrar en esta
     * cuenta?» **contesta siempre que nadie**, y eso es peor que no tenerla: una
     * lista vacía se lee como «no ha pasado nada».
     *
     * Y lo que lo convierte en un descuido y no en una decisión es que **la misma
     * consulta existe en tres sitios y las otras dos aciertan**:
     * `ChangeAskedController` la tiene dos veces y las dos le pasan
     * `$user->username`. La copia equivocada es justo la que vive en la clase
     * reutilizable. Ver 14-certificados.md §9.
     */
    public function test_los_intentos_fallidos_salen_siempre_vacios(): void
    {
        $personal = $this->personal();

        // Un intento fallido tal como lo escribe el login de verdad.
        DB::table('bitacoras')->insert([
            'created_by' => 0,
            'descripcion' => 'Intento login>> Entorno: web',
            'affected_person_name' => $personal->username,
            'affected_element_type' => 'intento_login',
            'created_at' => now(),
        ]);

        $respuesta = $this->withToken($personal->token)
            ->putJson('/api/historiales/de-usuario', ['user_id' => $personal->user_id])
            ->assertStatus(200);

        $this->assertSame([], $respuesta->json('intentos_fallidos'),
            'La fila existe y la pantalla dice que no hay ninguna.');

        // Y que la fila sí está: lo que falla es con qué se busca, no que falte.
        $this->assertSame(1, DB::table('bitacoras')
            ->where('affected_element_type', 'intento_login')
            ->where('affected_person_name', $personal->username)->count());
    }

    /**
     * Cualquiera del personal ve las sesiones de cualquiera: IP, navegador y
     * dispositivo.
     *
     * `putDeUsuario()` recibe `user_id` **por el cuerpo** y no comprueba nada. Lo
     * que devuelve no es una lista de fechas: `historiales` guarda `ip`,
     * `browser_name`, `platform_name` y `device_model`, así que cualquiera de los
     * 51 profesores puede sacar **desde qué sitio y con qué teléfono entra un
     * compañero** — o el superusuario.
     */
    public function test_el_personal_ve_las_sesiones_de_otro_con_su_ip(): void
    {
        $personal = $this->personal();

        $otro = DB::selectOne('SELECT id FROM users WHERE id <> ? AND deleted_at IS NULL
                               ORDER BY id LIMIT 1', [$personal->user_id]);

        $this->assertNotNull($otro, 'El seed no tiene un segundo usuario.');

        $this->unaSesionDe((int) $otro->id);

        $historial = $this->withToken($personal->token)
            ->putJson('/api/historiales/de-usuario', ['user_id' => $otro->id])
            ->assertStatus(200)
            ->json('historial');

        $this->assertCount(1, $historial);
        $this->assertSame('190.85.44.7', $historial[0]['ip']);
        $this->assertSame('SM-A155M', $historial[0]['device_model']);
    }

    /**
     * Y el detalle de una sesión ajena también, con el `username` dentro.
     *
     * `putSesion()` recibe `historial_id` por el cuerpo. La consulta une con
     * `users` para traer el `username`, así que ni siquiera hace falta saber de
     * quién es la sesión: el id basta y el nombre viene dentro.
     */
    public function test_el_detalle_de_una_sesion_ajena_trae_el_username(): void
    {
        $personal = $this->personal();

        $otro = DB::selectOne('SELECT id, username FROM users WHERE id <> ? AND deleted_at IS NULL
                               ORDER BY id LIMIT 1', [$personal->user_id]);

        $sesion = $this->unaSesionDe((int) $otro->id);

        $respuesta = $this->withToken($personal->token)
            ->putJson('/api/historiales/sesion', ['historial_id' => $sesion])
            ->assertStatus(200);

        $this->assertSame($otro->username, $respuesta->json('historial.username'));
        $this->assertSame('190.85.44.7', $respuesta->json('historial.ip'));
    }

    /** Una sesión que no existe sí está contemplada: 400 y no 500. */
    public function test_una_sesion_que_no_existe_es_400(): void
    {
        $personal = $this->personal();

        $inventada = ((int) DB::table('historiales')->max('id')) + 1000;

        $this->withToken($personal->token)
            ->putJson('/api/historiales/sesion', ['historial_id' => $inventada])
            ->assertStatus(400);
    }

    /**
     * Un usuario que no existe devuelve las dos listas vacías, en 200.
     *
     * `putDeUsuario()` no resuelve el usuario: le pasa el id a las dos consultas
     * y devuelve lo que salga. Con un id inventado eso son dos listas vacías y un
     * 200, que **no se distingue de un usuario que existe y no ha entrado nunca**.
     *
     * Se fija tal cual. No es grave y es la forma de siempre: una respuesta que
     * no miente pero tampoco dice nada.
     */
    public function test_un_usuario_que_no_existe_devuelve_listas_vacias(): void
    {
        $personal = $this->personal();

        $inventado = ((int) DB::table('users')->max('id')) + 1000;

        $respuesta = $this->withToken($personal->token)
            ->putJson('/api/historiales/de-usuario', ['user_id' => $inventado])
            ->assertStatus(200);

        $this->assertSame([], $respuesta->json('historial'));
        $this->assertSame([], $respuesta->json('intentos_fallidos'));
    }
}
