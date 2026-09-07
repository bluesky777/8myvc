<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\TrimStrings as Middleware;

class TrimStrings extends Middleware
{
    /**
     * The names of the attributes that should not be trimmed.
     *
     * `proyecto` es el fichero `.myvch` que sube el programa de escritorio del horario
     * (`POST horario/versiones`), y **es lo único de esta lista que no es una contraseña**.
     * Decisión 6 de la [§10.2 del 23](../../../docs/migracion/23-horarios.md), contestada
     * por Joseth el 6 sep 2026.
     *
     * **Por qué no puede recortarse:** un `.myvch` que termine en salto de línea se
     * guardaba con **un byte menos**, contestaba `201` y **seguía siendo JSON válido**, así
     * que el escritorio lo abría y **no había ningún síntoma** — sólo se veía comparando
     * hashes. La ruta promete el fichero *byte a byte* (§10.2 decisión 3) y con el recorte
     * esa promesa era falsa (§9.ter.7).
     *
     * **Y esta línea es global, así que se midió antes de escribirla:** `$except` se compara
     * con `Str::is($except, $key)`, o sea que sin comodines casa **sólo con la clave exacta
     * de primer nivel** —una anidada llega como `algo.proyecto` y no casaría—. Barridos los
     * **260 ficheros** `.php` de `app/` y `routes/` el 6 sep 2026, `proyecto` es campo de
     * petición **únicamente** en `horario/`; la única otra mención es
     * `config('notificaciones.fcm.proyecto')` en `EnvioFcm`, que es **configuración y no
     * entrada**, así que este middleware no la toca. *La decisión se tomó sobre la premisa
     * de que era quirúrgico y la premisa está comprobada, no supuesta.*
     *
     * @var array<int, string>
     */
    protected $except = [
        'proyecto',
        'current_password',
        'password',
        'password_confirmation',
    ];
}
