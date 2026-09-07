<?php

namespace Tests\Contrato;

use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * CORS: si la ventana de `myvc_horarios` puede hablarle a esta API.
 *
 * ## Por qué este fichero existe, que no es lo mismo que qué comprueba
 *
 * `myvc_horarios` es el quinto cliente y el primero que **no es un navegador ni
 * una app de móvil**: es un programa de escritorio (Tauri) cuya ventana es un
 * WebView. Eso significa que sus peticiones **cruzan origen** y las gobierna
 * `config/cors.php`, que es un fichero que **nadie ha comprobado nunca**.
 *
 * Medido el 6 sep 2026 antes de escribir esto: `grep -rn 'Origin' tests/ tools/`
 * daba **ocho aciertos y los ocho eran falsos positivos**
 * (`getClientOriginalName`, un alumno llamado «Original»). O sea que el día que
 * el `.env` de un colegio deje fuera al escritorio, **no hay nada que se ponga
 * en rojo aquí**: compila, pasa la suite y falla en la pantalla de un
 * coordinador. Eso es lo que cierra este fichero.
 *
 * ## Y ese día es MENOS probable y MÁS silencioso desde la misma tarde
 *
 * **La política es `*` y no se va a cerrar** (Joseth, 6 sep 2026: *«siempre va a
 * ser CORS `*` porque necesita ser llamado desde múltiples orígenes,
 * diferentes»*). Estos casos se escribieron cuando cerrar la lista era el plan y
 * lo que vigilaban era *«que el cierre no se haga mal»*. Ahora vigilan algo
 * distinto y peor: **que nadie se salga de la política sin querer**.
 *
 * Eso cambia cuál de los casos de aquí abajo es el importante. Ya no es
 * `test_con_los_dos_dentro_el_escritorio_entra` —esa lista no la va a escribir
 * nadie— sino los dos que describen **la forma que tiene la equivocación**:
 * `test_una_lista_solo_con_el_front_deja_fuera_al_escritorio`, porque la
 * respuesta **trae cabecera y parece que funciona**, y
 * `test_sin_lista_pasa_cualquier_origen`, que es **el que fija la política** y se
 * pondría rojo si alguien «arreglara» `config/cors.php` para que una lista vacía
 * signifique «ninguno».
 *
 * ## Lo que NO demuestra, y va arriba porque es la trampa de este asunto
 *
 * Estos casos mandan un `Origin` **tecleado por nosotros**. Contestan *«¿acepta
 * la lista esta cadena?»* y **no** *«¿funciona el programa?»*. El `Origin` de
 * verdad lo pone la ventana y no se puede falsificar desde un cliente —es un
 * *forbidden header name* del Fetch: si un cliente lo escribe, el navegador lo
 * tira sin decir nada—. Las dos preguntas se parecen y sólo una la contesta un
 * test. La otra sólo la contesta abrir el programa, y eso no vive en este
 * repositorio.
 *
 * Y no comprueban **ningún `.env` real**: los dieciséis van por
 * `tools/cors-de-los-colegios.sh --env`, que se corre en el servidor.
 *
 * ## De dónde salen los dos orígenes, que no son una suposición
 *
 * Del crate que compila ese programa —`tauri` 2.11.5,
 * `src/manager/mod.rs:339-346`, `tauri_protocol_url()`, y el test de `:778-799`
 * que lo fija—:
 *
 *     if cfg!(windows) || cfg!(target_os = "android") { "{http|https}://tauri.localhost" }
 *     else                                            { "tauri://localhost" }
 *
 * **Son dos entradas para tres plataformas**, y ésa es toda la trampa: el único
 * origen que alguien ha visto nunca es `tauri://localhost`, y se vio **en un
 * mac**. Un colegio que ponga ése solo deja fuera Windows, que es donde va a
 * estar el que cuadra el horario.
 */
class CorsDelEscritorioTest extends CasoDeContrato
{
    /** El origen del programa instalado en macOS y en Linux. */
    private const ESCRITORIO_UNIX = 'tauri://localhost';

    /** El del `.exe` de Windows. `https://` sólo con `useHttpsScheme`, que ese repo no pone. */
    private const ESCRITORIO_WINDOWS = 'http://tauri.localhost';

    /**
     * Lo que contesta el preflight con una lista dada, sin levantar la app
     * entera: quien decide es `CorsService`, y se le puede preguntar directo.
     *
     * Se devuelve la cabecera y no un booleano porque «bloqueado» y «permitido
     * con otro origen» son dos cosas distintas y el `assert` tiene que poder
     * decir cuál pasó.
     */
    private function permitidoPara(array $lista, string $origen): ?string
    {
        config(['cors.allowed_origins' => $lista]);

        $peticion = Request::create('/api/login/credentials', 'OPTIONS');
        $peticion->headers->set('Origin', $origen);
        $peticion->headers->set('Access-Control-Request-Method', 'POST');
        $peticion->headers->set('Access-Control-Request-Headers', 'authorization,content-type');

        $respuesta = (new \Fruitcake\Cors\CorsService(config('cors')))
            ->handlePreflightRequest($peticion);

        return $respuesta->headers->get('Access-Control-Allow-Origin');
    }

    /**
     * Ausente y vacía son LO MISMO, y las dos dejan pasar a todo el mundo.
     *
     * Es el estado de los dieciséis hoy y por eso el escritorio funciona. Lo que
     * fija este caso no es que esté bien: es que **si alguien «arregla»
     * `config/cors.php` para que una lista vacía signifique “nada”, el
     * escritorio y los cuatro fronts se caen a la vez y aquí se ve**.
     *
     * `config/cors.php` termina en `?: ['*']`, que es justo la línea que una
     * lectura apresurada se salta (docs/migracion/29 §5 se corrige a sí mismo
     * por haberla leído mal).
     */
    #[DataProvider('losCuatroOrigenes')]
    public function test_sin_lista_pasa_cualquier_origen(string $origen): void
    {
        $this->assertSame('*', $this->permitidoPara(['*'], $origen),
            "Sin `CORS_ALLOWED_ORIGINS`, `$origen` tendría que pasar con `*`. ".
            'Si esto falla, se cayeron los cinco clientes a la vez.');
    }

    /**
     * **El caso que hoy no vigila nadie**: una lista con el front del colegio
     * dentro y el escritorio fuera.
     *
     * Es lo que va a escribir quien cierre CORS en un colegio, porque los
     * orígenes del escritorio no se le ocurren a nadie: no son dominios, son un
     * esquema propio.
     *
     * ## Y este caso se escribió mal la primera vez, así que va con lo medido
     *
     * La primera versión afirmaba `assertNull(...)` —«sin la lista, no hay
     * cabecera»— y **falló**. Lo que devuelve el servidor con **una lista de un
     * solo elemento** es la cabecera con **el origen del front**:
     *
     *     lista = [https://simonbolivar.micolevirtual.com]
     *     Origin: tauri://localhost
     *     -> Access-Control-Allow-Origin: https://simonbolivar.micolevirtual.com
     *
     * Lo hace `isSingleOriginAllowed()` de `fruitcake/php-cors`: si la lista
     * tiene exactamente uno y no hay patrones, lo escribe **sin mirar quién
     * pregunta**. El usuario acaba igual de fuera —el navegador compara, no casa
     * y bloquea— pero **la cabecera está**, y ahí está lo que importa: cualquier
     * detector que pregunte *«¿vino una ACAO?»* en vez de *«¿vino la mía?»* da
     * un verde falso. `tools/cors-de-los-colegios.sh` tuvo ese fallo exacto y lo
     * destapó este test al fallar.
     *
     * Y no es rebuscado: `.env.example` recomienda `p.ej: https://lalvirtual.edu.co`,
     * o sea **una sola entrada**, que es justo el caso que dispara esa rama.
     */
    #[DataProvider('losDosDelEscritorio')]
    public function test_una_lista_solo_con_el_front_deja_fuera_al_escritorio(string $origen): void
    {
        $permitido = $this->permitidoPara(['https://simonbolivar.micolevirtual.com'], $origen);

        $this->assertNotSame($origen, $permitido,
            "Una lista sin `$origen` no puede devolverle SU origen. Si esto empieza a pasar, ".
            'alguien ensanchó CORS y hay que mirar por qué.');

        $this->assertNotSame('*', $permitido,
            'Con una lista puesta no puede volver a salir el comodín.');

        // La forma exacta que toma el bloqueo, fijada a propósito: si algún día
        // la librería deja de devolver el origen ajeno y devuelve `null`, el
        // resultado para el usuario es el mismo pero **el detector cambia**, y
        // este assert es el que avisa de que hay que ir a mirarlo.
        $this->assertSame('https://simonbolivar.micolevirtual.com', $permitido,
            'Una lista de UN elemento devuelve ese elemento a todo el mundo '.
            '(`isSingleOriginAllowed()`). Si esto cambia, revisa la clasificación de '.
            '`tools/cors-de-los-colegios.sh --url`, que depende de ello.');
    }

    /**
     * Y que el mecanismo **admite** un esquema propio, que es lo que hacía falta
     * saber antes de recomendar nada.
     *
     * `tauri://localhost` no es una URL http: podría no haber casado con el
     * comparador de la librería, y entonces la salida sería otra (un patrón, o
     * pedirle a la app que use el cliente de Rust). No hace falta: casa.
     */
    #[DataProvider('losDosDelEscritorio')]
    public function test_con_los_dos_dentro_el_escritorio_entra(string $origen): void
    {
        $lista = ['https://simonbolivar.micolevirtual.com', self::ESCRITORIO_UNIX, self::ESCRITORIO_WINDOWS];

        $this->assertSame($origen, $this->permitidoPara($lista, $origen),
            "Con `$origen` en la lista, el preflight tiene que devolverlo. ".
            'Si falla, `tools/cors-de-los-colegios.sh --origenes` está recomendando algo que no sirve.');
    }

    /**
     * La media verdad que va a escribir el primero que lo intente.
     *
     * Poner **sólo** `tauri://localhost` es lo natural: es el único origen que
     * alguien ha visto, y se vio en un mac. Windows se queda fuera y el que lo
     * sufre es precisamente el que cuadra el horario. Este caso existe para que
     * la lista de `--origenes` no encoja a uno «porque con uno ya funcionaba».
     */
    public function test_solo_el_origen_de_macos_deja_fuera_a_windows(): void
    {
        $lista = ['https://simonbolivar.micolevirtual.com', self::ESCRITORIO_UNIX];

        $this->assertSame(self::ESCRITORIO_UNIX, $this->permitidoPara($lista, self::ESCRITORIO_UNIX),
            'macOS y Linux tendrían que entrar con su origen dentro.');

        $this->assertNull($this->permitidoPara($lista, self::ESCRITORIO_WINDOWS),
            'Con sólo el origen de macOS en la lista, Windows QUEDA FUERA. '.
            'Ésta es la razón por la que la lista mínima son dos entradas y no una.');
    }

    /** @return array<string, array{string}> */
    public static function losDosDelEscritorio(): array
    {
        return [
            'macOS y Linux' => [self::ESCRITORIO_UNIX],
            'Windows' => [self::ESCRITORIO_WINDOWS],
        ];
    }

    /** @return array<string, array{string}> */
    public static function losCuatroOrigenes(): array
    {
        return [
            'macOS y Linux' => [self::ESCRITORIO_UNIX],
            'Windows' => [self::ESCRITORIO_WINDOWS],
            'el front de un colegio' => ['https://simonbolivar.micolevirtual.com'],
            'tauri dev' => ['http://localhost:4310'],
        ];
    }
}
