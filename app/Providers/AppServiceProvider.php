<?php

namespace App\Providers;

use App\Services\Notificaciones\EnvioFcm;
use App\Services\Notificaciones\Publicador;
use App\Support\ConsultasLentas;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        // Quién publica los avisos de `notificaciones:enviar`. Va atado aquí y no
        // resuelto dentro del comando para que un test pueda cambiarlo por uno de
        // mentira: el comando decide QUÉ avisar y hasta dónde llegó, y eso es lo
        // que hay que comprobar — si para hacerlo hiciera falta una credencial de
        // Firebase, no se comprobaría nunca.
        $this->app->bind(Publicador::class, EnvioFcm::class);
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        // Solo engancha algo si el colegio puso un umbral en su `.env`; con el
        // valor por defecto no registra ni el listener. Ver config/rendimiento.php.
        ConsultasLentas::registrar();
    }
}
