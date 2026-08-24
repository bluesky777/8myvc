<?php

namespace Tests\Contrato;

use App\Models\TokenDeSesion;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/**
 * Las rutas que el frontend llama SIN sesión no pueden exigir token.
 *
 * La lista la levantó la sesión de `myvc_front` (18 ago 2026) recorriendo los
 * cuatro estados que viven fuera del área autenticada —`main`, `login`,
 * `reset-password` y `logout`— y `AuthService`. Todo lo demás cuelga de `panel`,
 * que sí resuelve la sesión.
 *
 * **Esto es lo que la auditoría del backend no puede saber leyéndose a sí
 * mismo.** Dos de estas rutas escriben datos de una persona y parecen "de
 * usuario", pero por definición se ejecutan sin sesión:
 *
 *   - `publicaciones/ultimas` pinta las noticias dentro de la propia pantalla
 *     de login.
 *   - `login/reset-password` se llama desde el enlace del correo: el usuario no
 *     ha iniciado sesión —no puede, ha olvidado la contraseña— y el token del
 *     reseteo viaja en la URL, no en la cabecera.
 *
 * Si alguna acaba con guard, no se rompe una función suelta: se rompe la entrada
 * al sistema, y el usuario no tiene forma de salir de ahí.
 */
class RutasPreLoginTest extends CasoDeContrato
{
    /**
     * **El numero que se publica fuera de este fichero.**
     *
     * Existe para que `CLAUDE.md` pueda citar una cifra que **un test defiende**:
     * `test_el_numero_publicado_sale_de_la_lista_y_no_de_la_memoria` falla si esta
     * constante y `PRE_LOGIN` dejan de coincidir, y
     * `test_el_inventario_de_publicas_no_tiene_de_mas_ni_de_menos` falla si
     * `PRE_LOGIN` deja de coincidir con lo que el router hace de verdad.
     *
     * Encadenados, los dos hacen que **el numero de `CLAUDE.md` no pueda envejecer
     * en silencio**, que es lo unico que le pasaba: decia quince, este docblock
     * decia siete y `grep withoutMiddleware routes/` daba diecinueve.
     *
     * ## Y esto, antes de «verificarlo» con un `grep` y darlo por roto
     *
     * **Once es el numero del RESULTADO, no del mecanismo.** Hay **18** rutas sin
     * `auth.token` en `routes/`, y **no son 18 publicas**: llamadas sin cabecera,
     * **siete contestan 401 igual** —`auth/refresh` y las seis de `tardanzas/*`—
     * porque se defienden en el metodo, donde `User::fromToken()` aborta.
     *
     * **Quitarle el guard a una ruta no la hace publica.** Asi que **ningun `grep`
     * puede dar este numero**, ni el que cuenta 19 ni el que cuenta 18 bien: miden
     * el mecanismo, y la pregunta es sobre el resultado. Si alguien encuentra 18 y
     * cree haber pillado un error, esto es lo que tiene que leer antes.
     *
     * La auditoria commit a commit de los tres numeros viejos:
     * `docs/migracion/noche-2026-08-25/pub-1.md`.
     */
    public const TOTAL_PUBLICAS = 11;

    /**
     * Verbo y URI tal y como las llama el frontend.
     *
     * `publicaciones/ultimas` va por PUT aunque solo lea. No es un error de esta
     * lista: es como la llama el front.
     */
    private const PRE_LOGIN = [
        ['PUT',  'login/crear-prematricula'],
        ['PUT',  'publicaciones/ultimas'],
        // El GET no es scaffolding: fue el verbo REAL del front durante cinco años
        // y medio. En myvc_front, c116e3f (2018-10-12) lo introdujo con
        // `$http.get`, y no pasó a PUT hasta c09718e (2024-03-05).
        //
        // Como cada colegio publica su front por separado y no hay inventario de
        // qué versión tiene cada cual, cualquier colegio con un front anterior a
        // marzo de 2024 sigue llamando por GET hoy. Cerrarlo le dejaría la
        // pantalla de login rota, y sin síntoma en ningún sitio hasta que
        // alguien de ese colegio se queje.
        //
        // Se puede cerrar el día que se confirme que TODOS los colegios están en
        // un front posterior a c09718e. Hasta entonces, público a propósito.
        ['GET',  'publicaciones/ultimas'],
        ['POST', 'login/recuperar-clave'],
        ['POST', 'login/ver-pass'],           // alias, mientras el front migra
        ['PUT',  'login/reset-password'],
        ['POST', 'login'],
        ['POST', 'login/credentials'],
        ['PUT',  'login/logout'],
        // La sesión de la Fase 3. Entrar no requiere estar dentro, y salir
        // tiene que funcionar con el token ya vencido.
        //
        // `auth/refresh` NO está aquí a propósito: sin token responde 401, y
        // debe hacerlo. No es una pantalla previa al login, es la renovación de
        // una sesión que ya existe.
        ['POST', 'auth/login'],
        ['POST', 'auth/logout'],
    ];

    public function test_ninguna_lleva_guard_de_autenticacion(): void
    {
        $conGuard = [];

        foreach (self::PRE_LOGIN as [$verbo, $uri]) {
            $ruta = collect(Route::getRoutes())->first(
                fn ($r) => $r->uri() === 'api/'.$uri && in_array($verbo, $r->methods(), true)
            );

            $this->assertNotNull($ruta, "La ruta {$verbo} api/{$uri} no existe. ¿Se renombró?");

            if ($this->exigeToken($ruta)) {
                $conGuard[] = $verbo.' api/'.$uri;
            }
        }

        $this->assertSame([], $conGuard,
            "Estas rutas las llama el frontend SIN sesión y ahora exigen token.\n".
            "Protegerlas rompe la entrada al sistema:\n  ".implode("\n  ", $conGuard));
    }

    public function test_ninguna_responde_401_sin_token(): void
    {
        $rotas = [];

        foreach (self::PRE_LOGIN as [$verbo, $uri]) {
            $r = $this->json($verbo, '/api/'.$uri);

            // Cualquier código vale menos 401: sin parámetros unas darán 422 y
            // otras 500. Lo que no puede pasar es que rechacen por falta de token.
            if ($r->getStatusCode() === 401) {
                $rotas[] = $verbo.' api/'.$uri;
            }
        }

        $this->assertSame([], $rotas,
            "Responden 401 sin token, y el frontend las llama sin sesión:\n  ".
            implode("\n  ", $rotas));
    }

    /**
     * Cerrar sesión con el token ya caducado tiene que funcionar, y funcionar
     * dos veces seguidas.
     *
     * Devolvía 500 SIEMPRE: `DB::update()` devuelve un entero y el código le
     * aplicaba `[0]`. Estaba así desde 2021 y pasó desapercibido porque hasta
     * PHP 7.3 indexar un entero devolvía null en silencio.
     */
    public function test_logout_funciona_y_es_idempotente(): void
    {
        $usuario = $this->usuarioLlanoDelPersonal();
        $token = $this->tokenDe($usuario->username);
        $cab = ['Authorization' => 'Bearer '.$token];

        $this->putJson('/api/login/logout', [], $cab)->assertStatus(200);
        $this->putJson('/api/login/logout', [], $cab)->assertStatus(200);

        // Sin token tampoco puede reventar: el front tiene que poder limpiar su
        // estado aunque no le quede nada que presentar.
        $this->putJson('/api/login/logout', [])->assertStatus(200);
    }

    /** El cierre de sesión se registra en el historial del usuario del token. */
    public function test_logout_registra_la_salida(): void
    {
        $usuario = $this->usuarioLlanoDelPersonal();
        $token = $this->tokenDe($usuario->username);

        // El login que acaba de hacer tokenDe() deja la fila abierta.
        $fila = DB::table('historiales')->where('user_id', $usuario->id)
            ->orderByDesc('id')->first();

        $this->assertNotNull($fila, 'El login debería haber dejado una fila en historiales.');
        $this->assertNull($fila->logout_at);

        $this->putJson('/api/login/logout', [], ['Authorization' => 'Bearer '.$token])
            ->assertStatus(200);

        $this->assertNotNull(
            DB::table('historiales')->where('id', $fila->id)->value('logout_at'),
            'El logout no registró la salida.'
        );
    }

    /**
     * No se puede falsificar el cierre de sesión de otro.
     *
     * El `user_id` llegaba en el cuerpo, así que cualquiera podía marcar la
     * salida de cualquiera sabiendo su id. No lo echaba del sistema, pero
     * corrompía el historial de accesos — justo lo que se mira para reconstruir
     * qué pasó. Ahora el usuario sale del token y el cuerpo se ignora.
     */
    public function test_no_se_puede_cerrar_la_sesion_de_otro(): void
    {
        $mio = $this->usuarioLlanoDelPersonal();
        $otro = $this->usuarioDeTipo('Profesor');
        $token = $this->tokenDe($mio->username);

        $suya = DB::table('historiales')->insertGetId([
            'user_id' => $otro->id,
            'tipo' => $otro->tipo,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Con MI token, pidiendo cerrar la SUYA.
        $this->putJson('/api/login/logout', ['user_id' => $otro->id],
            ['Authorization' => 'Bearer '.$token])->assertStatus(200);

        $this->assertNull(
            DB::table('historiales')->where('id', $suya)->value('logout_at'),
            'Se pudo marcar la salida de otro usuario mandando su id en el cuerpo.'
        );
    }

    /**
     * El caso que motiva todo el diseño: cerrar sesión con el token YA CADUCADO.
     *
     * Es lo normal — quien vuelve al día siguiente y pulsa "salir". Por eso el
     * usuario no se resuelve con `User::fromToken()`, que aborta con 401 al
     * expirar, sino buscando la fila del token: existe aunque haya vencido, y
     * de paso es la que hay que borrar.
     */
    public function test_registra_la_salida_con_el_token_caducado(): void
    {
        $usuario = $this->usuarioDeTipo('Usuario');

        // Un token de verdad, emitido por el login, al que se le adelanta la
        // caducidad en la propia tabla. Es lo que pasa de forma natural con el
        // de quien vuelve al día siguiente.
        $caducado = $this->tokenDe($usuario->username);

        TokenDeSesion::findToken($caducado)
            ->forceFill(['expires_at' => now()->subDay()])
            ->save();

        // Y comprobado que de verdad está muerto para todo lo demás.
        $this->getJson('/api/ciudades', ['Authorization' => 'Bearer '.$caducado])
            ->assertStatus(401);

        // El ingreso de ESTE token. Antes este caso insertaba una fila suelta de
        // `historiales` y comprobaba que la salida caía ahí, porque `putLogout`
        // marcaba «la última de esta persona». Desde la fase 2 de
        // 18-auditoria.md el token sabe de qué ingreso salió y se marca **ése**,
        // así que una fila inventada después ya no es la que se cierra — y no
        // debe serlo: es de otra sesión.
        //
        // Lo que este caso viene a comprobar no cambia: **con el token caducado la
        // salida se registra igual**, que es el caso normal de quien vuelve al día
        // siguiente y pulsa salir.
        $fila = DB::table('personal_access_tokens')
            ->where('id', explode('|', $caducado)[0])
            ->value('historial_id');

        $this->assertNotNull($fila, 'El token no sabe de qué ingreso salió: el caso no mide nada.');

        $this->putJson('/api/login/logout', [], ['Authorization' => 'Bearer '.$caducado])
            ->assertStatus(200);

        $this->assertNotNull(
            DB::table('historiales')->where('id', $fila)->value('logout_at'),
            'Con el token caducado no se registró la salida, que es el caso normal.'
        );
    }

    /** Un token inventado no vale: se comprueba la firma. */
    public function test_un_token_falsificado_no_registra_nada(): void
    {
        $otro = $this->usuarioDeTipo('Profesor');

        $fila = DB::table('historiales')->insertGetId([
            'user_id' => $otro->id,
            'tipo' => $otro->tipo,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Cabecera con la forma de un JWT pero firmada con otra cosa.
        $falso = 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.'
            .base64_encode(json_encode(['sub' => $otro->id, 'exp' => time() + 3600]))
            .'.firmaInventada';

        $this->putJson('/api/login/logout', [], ['Authorization' => 'Bearer '.$falso])
            ->assertStatus(200);

        $this->assertNull(
            DB::table('historiales')->where('id', $fila)->value('logout_at'),
            'Un token con firma inválida pudo marcar la salida de alguien.'
        );
    }

    /** Sin token no se registra nada, pero tampoco falla. */
    public function test_sin_token_no_registra_pero_responde_200(): void
    {
        $otro = $this->usuarioDeTipo('Profesor');

        $suya = DB::table('historiales')->insertGetId([
            'user_id' => $otro->id,
            'tipo' => $otro->tipo,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->putJson('/api/login/logout', ['user_id' => $otro->id])->assertStatus(200);

        $this->assertNull(
            DB::table('historiales')->where('id', $suya)->value('logout_at'),
            'Sin token se pudo marcar la salida de un usuario cualquiera.'
        );
    }

    /**
     * **El invariante fuerte, y ahora cerrado por las DOS direcciones.**
     *
     * Recorre TODAS las rutas de la API sin cabecera de autenticacion y compara el
     * conjunto que contesta **algo distinto de 401** con `PRE_LOGIN`. Da igual como
     * se defienda cada una —middleware, `User::fromToken()` en el metodo, o en el
     * constructor—: lo que se afirma aqui es **el resultado, no el mecanismo**.
     *
     * ## Por que las dos direcciones, y no solo «ninguna otra responde»
     *
     * Antes esto solo miraba **de mas**: que ninguna ruta fuera de la lista
     * contestara 2xx. Con eso, `PRE_LOGIN` podia envejecer **de menos** sin que
     * nada saltara — una ruta de la lista que dejara de ser publica seguia
     * figurando como tal, y el numero que se cita en `CLAUDE.md` salia de contarla.
     *
     * Y eso no es hipotetico: **es exactamente lo que habia pasado.** El docblock
     * de esta clase decia «salvo estas **7**» al lado de una lista de **11**, y
     * `CLAUDE.md` decia **quince**. Tres sitios, tres numeros, y ninguno se podia
     * comprobar sin volver a contar a mano.
     *
     * ## Por que «no 401» y no «2xx»
     *
     * Porque los `{parametros}` se sustituyen por `1` y varias contestan 422 o 500
     * por el parametro, no por la sesion. **Un criterio de 2xx contaria como
     * cerrada una ruta que esta abierta y solo se quejo del cuerpo** — que es
     * precisamente el error que este test existe para no cometer.
     *
     * ## Y lo que este test NO dice
     *
     * **Que una ruta conteste sin token no significa que deba.** Esto cuenta y
     * fija; no juzga. Abrir o cerrar una ruta es otra decision y no la toma un
     * test. Ver `docs/migracion/noche-2026-08-25/pub-1.md`.
     *
     * Las llamadas se hacen dentro de la transaccion del test, asi que si alguna
     * ruta desprotegida llegara a escribir, se deshace al terminar.
     */
    public function test_el_inventario_de_publicas_no_tiene_de_mas_ni_de_menos(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);

        $declaradas = [];

        foreach (self::PRE_LOGIN as [$verbo, $uri]) {
            $declaradas[$verbo.' api/'.$uri] = true;
        }

        $abiertas = [];

        foreach (Route::getRoutes()->getRoutes() as $ruta) {
            foreach ($ruta->methods() as $verbo) {
                // Solo la API. `routes/web.php` sirve la pagina de bienvenida de
                // Laravel en `/`, que es publica por definicion.
                if ($verbo === 'HEAD' || ! str_starts_with($ruta->uri(), 'api/')) {
                    continue;
                }

                $uri = preg_replace('/\{[^}]+\}/', '1', $ruta->uri());

                if ($this->json($verbo, '/'.$uri)->getStatusCode() !== 401) {
                    $abiertas[$verbo.' '.$ruta->uri()] = true;
                }
            }
        }

        $deMas = array_keys(array_diff_key($abiertas, $declaradas));
        $deMenos = array_keys(array_diff_key($declaradas, $abiertas));

        sort($deMas);
        sort($deMenos);

        $this->assertSame([], $deMas,
            "Estas rutas contestan a quien no presenta token y NO estan en `PRE_LOGIN`:\n  "
            .implode("\n  ", $deMas)
            ."\n\nSi es correcto que sean publicas, van a la lista con su motivo al lado. "
            .'Si no lo es, es un agujero: no se cierra desde aqui.');

        $this->assertSame([], $deMenos,
            "Estas estan en `PRE_LOGIN` pero YA NO contestan sin token:\n  "
            .implode("\n  ", $deMenos)
            ."\n\nO se les puso guard sin querer -y entonces la entrada al sistema esta rota-, "
            .'o dejaron de existir y la lista arrastra un fantasma que alguien cuenta.');
    }

    /**
     * **El numero se deriva, no se escribe.**
     *
     * Este es el test que impide que vuelva a pasar lo que motivo el lote: tres
     * sitios con tres numeros. `contarPublicas()` es la unica fuente, y el
     * docblock de la clase se lee con ella al lado.
     *
     * *Un numero escrito a mano al lado de una lista es el que alguien cita
     * manana, y no envejece ruidosamente: envejece en silencio.*
     */
    public function test_el_numero_publicado_sale_de_la_lista_y_no_de_la_memoria(): void
    {
        $this->assertSame(self::TOTAL_PUBLICAS, count(self::PRE_LOGIN),
            'La constante `TOTAL_PUBLICAS` dice '.self::TOTAL_PUBLICAS.' y `PRE_LOGIN` tiene '
            .count(self::PRE_LOGIN).'. Es exactamente el fallo que este fichero vino a cerrar: '
            .'un numero al lado de una lista de otra longitud.');
    }

    /**
     * Los dos verbos de `publicaciones/ultimas` devuelven lo mismo.
     *
     * Eran 21 líneas duplicadas palabra por palabra. Importa más de lo que
     * parece: esta respuesta alimenta el formulario público de prematrícula
     * —el desplegable de grupos sale de `year.grados_sig`—, así que si las dos
     * copias divergieran, los colegios con un front viejo (que llama por GET)
     * verían un formulario distinto, sin que nada fallara.
     */
    public function test_los_dos_verbos_de_ultimas_devuelven_lo_mismo(): void
    {
        $get = $this->getJson('/api/publicaciones/ultimas');
        $put = $this->putJson('/api/publicaciones/ultimas');

        $get->assertStatus(200);
        $put->assertStatus(200);

        $this->assertSame($put->json(), $get->json(),
            'El GET y el PUT de publicaciones/ultimas ya no devuelven lo mismo. '.
            'Los colegios con un front anterior a marzo de 2024 llaman por GET.');
    }
}
