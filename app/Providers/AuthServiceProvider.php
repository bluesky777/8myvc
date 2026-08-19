<?php

namespace App\Providers;

use App\Models\TokenDeSesion;
use App\Services\Sesion;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\Sanctum;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The policy mappings for the application.
     *
     * @var array
     */
    protected $policies = [
        // 'App\Models\Model' => 'App\Policies\ModelPolicy',
    ];

    /**
     * Aquí se registra todo lo de Sanctum, porque su ServiceProvider NO se
     * carga: composer.json lo tiene en `dont-discover`.
     *
     * Se usa Sanctum como librería —el trait, el modelo y la tabla— y no como
     * paquete instalado. Su ServiceProvider traía tres cosas que aquí estorban:
     * registraba la ruta `/sanctum/csrf-cookie` (esta API tiene 533 rutas
     * explícitas y un test que comprueba la tabla entera; una ruta que aparece
     * sola es justo lo que se lleva años quitando), añadía un guard `sanctum`
     * que no vale para lo que hace falta (ver `boot()`), y cargaba su propia
     * migración de `personal_access_tokens`, que chocaría con la de este repo
     * porque la suya no tiene `expires_at`.
     */
    public function register()
    {
        // Nuestro modelo, no el de Sanctum: el suyo no conoce `expires_at` ni
        // `reemplazado_por`. Ver app/Models/TokenDeSesion.php.
        Sanctum::usePersonalAccessTokenModel(TokenDeSesion::class);
    }

    /**
     * Register any authentication / authorization services.
     *
     * @return void
     */
    public function boot()
    {
        $this->registerPolicies();

        /*
        | El guard `sesion` de config/auth.php.
        |
        | No se usa el guard `sanctum` que trae el paquete por dos razones. La
        | primera es que Sanctum 2.15 solo sabe de una caducidad global sobre
        | `created_at`, y aquí cada token tiene la suya en `expires_at`: con su
        | guard, un token caducado seguiría valiendo para `auth()->user()`. La
        | segunda es que su guard no sabe nada de los JWT viejos, que hay que
        | seguir aceptando durante la transición.
        |
        | Con esto, `auth()->user()`, `$request->user()` y el middleware
        | `auth.token` contestan todos lo mismo, porque los tres preguntan a
        | App\Services\Sesion.
        */
        Auth::viaRequest('sesion', function (Request $peticion) {
            return app(Sesion::class)->usuarioDe($peticion);
        });
    }
}
