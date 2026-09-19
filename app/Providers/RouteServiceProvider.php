<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to the "home" route for your application.
     *
     * This is used by Laravel authentication to redirect users after login.
     *
     * @var string
     */
    public const HOME = '/home';

    /*
     * Sin $namespace: las rutas usan [Controlador::class, 'metodo'] en vez de
     * strings 'Controlador@metodo'. Con el prefijo activo, Laravel antepondría
     * el namespace a una cadena que ya lo lleva. Además la sintaxis de string
     * desaparece en Laravel 9.
     *
     * El docblock que describía la propiedad se quedó aquí huérfano al quitarla
     * en la Fase 1, anunciando un `@var` de nada.
     */

    /**
     * Define your route model bindings, pattern filters, etc.
     *
     * @return void
     */
    public function boot()
    {
        $this->configureRateLimiting();

        $this->routes(function () {
            Route::prefix('api')
                ->middleware('api')
                ->group(base_path('routes/api.php'));

            Route::middleware('web')
                ->group(base_path('routes/web.php'));
        });
    }

    /**
     * Configure the rate limiters for the application.
     *
     * @return void
     */
    protected function configureRateLimiting()
    {
        // El límite general. Sube de 60 a 120 porque los informes hacen ráfagas
        // legítimas —un boletín de grupo son decenas de peticiones seguidas— y
        // porque el límite que de verdad importaba, el de las contraseñas, pasa
        // a tener el suyo propio y mucho más estrecho.
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(120)->by(optional($request->user())->id ?: $request->ip());
        });

        /*
         * Contraseñas.
         *
         * Antes compartían los 60/min de todo lo demás: 86.400 intentos al día
         * por IP contra 2.318 cuentas con contraseñas de colegio. Eso no
         * resiste un diccionario.
         *
         * Van dos cubos y no uno, porque tapan ataques distintos:
         *
         *   por IP       — un atacante probando muchas contraseñas de una cuenta
         *   por usuario  — un atacante repartido entre muchas IP contra una cuenta
         *
         * Cinco por minuto no estorba a nadie: quien escribe mal su contraseña
         * cinco veces en un minuto necesita recuperarla, no un sexto intento.
         *
         * NO se aplica a `tardanzas/subir/*`, que también manda credenciales en
         * cada petición pero no es un login: es el lector subiendo un lote, y
         * ahí cinco por minuto sí estorbaría.
         */
        /*
         * Prematrícula: el único endpoint público que ESCRIBE.
         *
         * Sin token crea un alumno, su matrícula y un usuario activo. Con el
         * límite general eran 7.200 filas por hora desde una IP. Ya no crea la
         * cuenta con una contraseña conocida —eso se cerró en el PR #7, ahora
         * es aleatoria— pero seguir pudiendo inundar `alumnos` y `matriculas`
         * es un problema por sí solo.
         *
         * Veinte por hora y no cinco: una familia matricula a varios hijos
         * seguidos, y un colegio que ponga un equipo en recepción los mete a
         * todos desde la misma IP. Veinte no lo estorba y sigue cortando el
         * abuso por tres órdenes de magnitud.
         */
        RateLimiter::for('prematricula', function (Request $request) {
            return Limit::perHour(20)->by($request->ip());
        });

        /*
         * La colilla del pago del formulario de inscripción, que es **la única
         * ruta pública de esta API que recibe un FICHERO de un desconocido**.
         *
         * Son DOS límites a la vez, como en `login`, y cada uno tapa un agujero
         * distinto que el otro deja abierto:
         *
         *   por IP      corta al que sube en bucle desde un sitio. Diez por hora es
         *               más de lo que hace una familia —sube una, se equivoca, sube
         *               otra— y tres órdenes de magnitud menos que un abuso.
         *
         *   por CÓDIGO  corta al que reparte la carga entre muchas IPs contra el
         *               mismo formulario, que es lo que el límite por IP no ve. Y
         *               también protege al tesorero: su bandeja no se puede llenar
         *               desde un solo código.
         *
         * **Ninguno de los dos es la defensa principal**, y conviene no confundirse:
         * el tope real es que una orden admite **tres colillas y una sola
         * pendiente**, que es una regla de la FILA y no se reinicia con el reloj.
         * Un limitador protege la base de datos; lo que protege el disco es la
         * cuenta por orden.
         *
         * Y nada de esto para un DDoS de verdad: eso se para en el borde —Cloudflare
         * delante del dominio— y es una decisión de hosting, no de código.
         */
        RateLimiter::for('colilla', function (Request $request) {
            return [
                Limit::perHour(10)->by('ip:'.$request->ip()),
                Limit::perHour(10)->by('cod:'.strtoupper(trim((string) $request->route('codigo')))),
            ];
        });

        /*
         * El checkout del pago en línea del formulario. Mismo reparto que
         * `colilla` —por IP y por código a la vez— y por los mismos dos agujeros:
         * el de IP corta al que abre checkouts en bucle desde un sitio, y el de
         * código al que reparte la carga entre muchas IPs contra el mismo
         * formulario.
         *
         * **Y tampoco aquí es la defensa principal.** El tope de verdad es que una
         * orden admite DIEZ intentos de pago, que es una regla de la fila y no se
         * reinicia con el reloj. Veinte por hora es más de lo que hace una familia
         * —lo intenta, se le cae el banco, lo vuelve a intentar— y sigue cortando
         * el abuso por órdenes de magnitud.
         */
        RateLimiter::for('checkout-inscripcion', function (Request $request) {
            return [
                Limit::perHour(20)->by('ip:'.$request->ip()),
                Limit::perHour(20)->by('cod:'.strtoupper(trim((string) $request->route('codigo')))),
            ];
        });

        /*
         * El webhook de la pasarela. **Este limitador es distinto de todos los
         * demás de este fichero, y conviene ver por qué antes de tocarle el
         * número.**
         *
         * Aquí quien llama no es una persona: es el servidor de la pasarela, desde
         * unas pocas IPs, y **cortarle una llamada cuesta un pago que no se
         * registra**. Un 429 le dice que reintente —lo hace—, pero un límite
         * apretado durante una tanda de matrículas convertiría eso en la norma.
         *
         * Por eso va **alto y sólo por IP**: no está aquí para acotar a la
         * pasarela, sino para que un desconocido no pueda usar esta ruta como
         * altavoz. Y ni siquiera es lo que lo impide — lo impide que un evento con
         * una referencia que no es nuestra se descarta con una consulta indexada,
         * **sin salir a internet**. Esto es el cinturón del tirante.
         */
        RateLimiter::for('webhook-inscripcion', function (Request $request) {
            return Limit::perHour(300)->by($request->ip());
        });

        RateLimiter::for('login', function (Request $request) {
            $identidad = (string) ($request->input('username')
                ?: $request->input('email')
                ?: $request->ip());

            return [
                Limit::perMinute(5)->by('ip:'.$request->ip()),
                Limit::perMinute(5)->by('id:'.Str::lower($identidad)),
            ];
        });
    }
}
