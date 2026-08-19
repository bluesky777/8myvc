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

    /**
     * The controller namespace for the application.
     *
     * When present, controller route declarations will automatically be prefixed with this namespace.
     *
     * @var string|null
     */
    /*
     * Sin $namespace: las rutas usan [Controlador::class, 'metodo'] en vez de
     * strings 'Controlador@metodo'. Con el prefijo activo, Laravel antepondría
     * el namespace a una cadena que ya lo lleva. Además la sintaxis de string
     * desaparece en Laravel 9.
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
