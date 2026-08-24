<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\Log;

/**
 * `version_minima_app`: el número con el que `myvc_flutter` decide si se bloquea.
 *
 * La app **ya tiene escrito y probado** el bloqueo, enganchado en los sitios por
 * los que le pasa una respuesta de login; lo que faltaba era que el backend
 * mandara el campo. El contrato lo escribió la app y aquí no se reinventa: el
 * campo se llama `version_minima_app` y el valor es el **`versionCode`** (el `+N`
 * de `pubspec.yaml`), no la versión con puntos.
 *
 * ## Lo que estos casos protegen de verdad
 *
 * **Que sin configurar el campo NO viaje.** No que viaje en `null`: que la clave
 * no esté. Es lo que hace que este cambio sea inerte al desplegarlo en los
 * dieciséis colegios, y lo que deja la decisión de bloquear en manos de quien
 * rellena el `.env` en lugar de en este commit.
 *
 * Y **que viaje por los cuatro caminos**, que no es lo mismo que por uno:
 *
 * | Respuesta | Quién la lee |
 * |---|---|
 * | `POST auth/login` | `myvc_front` |
 * | `POST auth/refresh` | `myvc_front` — y el **único** sitio donde un mínimo nuevo llega sin que el usuario salga y vuelva |
 * | `POST login/credentials` | **`myvc_flutter`** (`lib/Http/Server.dart:36`) |
 * | `POST login` | **`myvc_flutter`** (`:43`) y `myvc_front_2` |
 *
 * Las dos de abajo son las que importan para el bloqueo, porque **son las que la
 * app llama de verdad** — está leído en su código y anotado en
 * [07-sesion.md](../../docs/migracion/07-sesion.md), no supuesto. Poner el campo
 * sólo en `auth/*` habría dejado la función entera sin efecto y con todos los
 * tests en verde.
 */
class VersionMinimaDeLaAppTest extends CasoDeContrato
{
    // `CLAVE` la hereda de `CasoDeContrato` ('test-1234'): es la contraseña con
    // la que el seed crea a todo el mundo, y redeclararla aquí sería inventarse
    // una segunda verdad sobre el mismo dato.

    private function usuario(): object
    {
        return $this->usuarioDeTipo('Profesor');
    }

    /** El par nuevo, por la ruta de la Fase 3. */
    private function entrar(string $username): array
    {
        $r = $this->postJson('/api/auth/login', ['username' => $username, 'password' => self::CLAVE]);
        $r->assertStatus(200);

        return $r->json();
    }

    /** Las cuatro respuestas de login, ya en array. */
    private function lasCuatro(string $username): array
    {
        $par = $this->entrar($username);

        $this->olvidarControladores();

        $refresco = $this->postJson('/api/auth/refresh', [], ['Authorization' => 'Bearer '.$par['refresco']]);
        $refresco->assertStatus(200);

        $this->olvidarControladores();

        $credentials = $this->postJson('/api/login/credentials', [
            'username' => $username, 'password' => self::CLAVE,
        ]);
        $credentials->assertStatus(200);

        $this->olvidarControladores();

        $login = $this->postJson('/api/login', [], ['Authorization' => 'Bearer '.$par['el_token']]);
        $login->assertStatus(200);

        return [
            'auth/login' => $par,
            'auth/refresh' => $refresco->json(),
            'login/credentials' => $credentials->json(),
            'login' => $login->json(),
        ];
    }

    /**
     * **Sin configurar, la clave no está en ninguna de las cuatro.**
     *
     * Es el caso que hace que esto se pueda desplegar esta noche: la forma de las
     * cuatro respuestas no cambia, así que los snapshots de contrato que ya
     * existen siguen valiendo y ningún cliente ve nada nuevo.
     *
     * Se comprueba con `assertArrayNotHasKey` y no con `assertNull` a propósito:
     * un `version_minima_app: null` **sería** un cambio de forma, y hay clientes
     * que distinguen «no está» de «está y es null».
     */
    public function test_sin_configurar_el_campo_no_viaja(): void
    {
        config(['aplicacion-movil.version_minima' => null]);

        foreach ($this->lasCuatro($this->usuario()->username) as $donde => $cuerpo) {
            $this->assertArrayNotHasKey('version_minima_app', $cuerpo,
                "Sin `APP_MOVIL_VERSION_MINIMA` puesto, `{$donde}` manda el campo igual. ".
                'Así, desplegar esto encendería el bloqueo en los dieciséis colegios a la vez.');
        }
    }

    /** Y el `.env` recién copiado, que deja la variable puesta y **vacía**. */
    public function test_la_variable_vacia_es_lo_mismo_que_no_tenerla(): void
    {
        config(['aplicacion-movil.version_minima' => '']);

        foreach ($this->lasCuatro($this->usuario()->username) as $donde => $cuerpo) {
            $this->assertArrayNotHasKey('version_minima_app', $cuerpo,
                "`APP_MOVIL_VERSION_MINIMA=` (vacío) hizo que `{$donde}` mandara el campo. ".
                'Es como queda el .env recién copiado de .env.example.');
        }
    }

    /**
     * Con un número puesto, el campo viaja en las cuatro — y **como entero**.
     *
     * El entero es lo que menos se puede malinterpretar: la app lee tolerante y
     * `"12"` también le vale, pero un número no admite dos lecturas.
     */
    public function test_con_un_numero_puesto_viaja_en_las_cuatro(): void
    {
        config(['aplicacion-movil.version_minima' => 37]);

        foreach ($this->lasCuatro($this->usuario()->username) as $donde => $cuerpo) {
            $this->assertArrayHasKey('version_minima_app', $cuerpo,
                "`{$donde}` no manda `version_minima_app` teniendo un mínimo configurado.");

            $this->assertSame(37, $cuerpo['version_minima_app'],
                "`{$donde}` no manda el versionCode como entero.");
        }
    }

    /**
     * **El refresco lo trae**, y merece su propio caso.
     *
     * Es el único punto donde la app se entera de un mínimo nuevo **sin que el
     * usuario salga y vuelva a entrar**. El refresco vive catorce días y rota en
     * cada uso, así que quien entra a diario puede pasarse meses sin teclear la
     * contraseña: si el campo sólo viajara en el login, a ese usuario el mínimo
     * nuevo no le llegaría en todo ese tiempo.
     */
    public function test_el_refresco_entera_a_la_app_sin_que_el_usuario_vuelva_a_entrar(): void
    {
        // Se entra ANTES de que el colegio exija nada: es el caso real de la app
        // que lleva semanas abierta.
        config(['aplicacion-movil.version_minima' => null]);

        $par = $this->entrar($this->usuario()->username);

        $this->assertArrayNotHasKey('version_minima_app', $par);

        $this->olvidarControladores();

        // Y ahora el colegio pone el número, con la sesión ya abierta.
        config(['aplicacion-movil.version_minima' => 5]);

        $r = $this->postJson('/api/auth/refresh', [], ['Authorization' => 'Bearer '.$par['refresco']]);
        $r->assertStatus(200);

        $this->assertSame(5, $r->json('version_minima_app'),
            'El refresco no trae el mínimo nuevo: a quien no cierre sesión no le llegará '.
            'hasta que vuelva a entrar, y el refresco vive catorce días.');
    }

    /** `"12"` en el `.env` es texto, y tiene que llegar como el número 12. */
    public function test_el_numero_escrito_como_texto_llega_como_numero(): void
    {
        config(['aplicacion-movil.version_minima' => '12']);

        $cuerpo = $this->postJson('/api/login/credentials', [
            'username' => $this->usuario()->username, 'password' => self::CLAVE,
        ])->assertStatus(200)->json();

        $this->assertSame(12, $cuerpo['version_minima_app'],
            'Todo lo que sale de un `.env` es una cadena; si se manda tal cual, el contrato '.
            'depende de que el cliente lo convierta.');
    }

    /**
     * **Un valor que no es un número no bloquea a nadie, y no se calla.**
     *
     * `APP_MOVIL_VERSION_MINIMA=v1.4` o un `12 # el de agosto` son errores
     * plausibles: `.env` es texto y no lo valida nadie. Las dos mitades importan
     * y por eso se comprueban las dos.
     *
     * Fallar hacia el lado que **no** deja a nadie fuera es lo correcto aquí — el
     * daño de bloquear de más es un colegio entero sin app y sin salida—, pero un
     * fallo silencioso sería peor que el error: **desde el cliente no se distingue
     * un `.env` mal puesto de un colegio que no exige nada**, y son dieciséis
     * `.env` distintos. Sin el aviso en el log, el que lo escribió mal cree que
     * está exigiendo una versión y no exige ninguna.
     */
    public function test_un_valor_que_no_es_numero_ni_bloquea_ni_se_calla(): void
    {
        config(['aplicacion-movil.version_minima' => 'v1.4']);

        $log = Log::spy();

        $cuerpo = $this->postJson('/api/login/credentials', [
            'username' => $this->usuario()->username, 'password' => self::CLAVE,
        ])->assertStatus(200)->json();

        $this->assertArrayNotHasKey('version_minima_app', $cuerpo,
            'Un valor que no es un número acabó mandándose. Con un `(int)` silencioso '.
            '`"v1.4"` vale 0, que la app leería como «sin mínimo» — correcto por accidente.');

        $log->shouldHaveReceived('warning')
            ->withArgs(fn ($mensaje, $contexto = []) => str_contains((string) $mensaje, 'APP_MOVIL_VERSION_MINIMA')
                && ($contexto['valor'] ?? null) === 'v1.4')
            ->once();
    }
}
