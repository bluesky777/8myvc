<?php

namespace App\Providers;

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
        //
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
