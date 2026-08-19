<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La tabla donde viven los tokens de sesión (Fase 3).
 *
 * **Es la primera migración de verdad de este repo.** Las tres que había están
 * archivadas en database/migrations/legacy/ y no se ejecutan: la historia de
 * migraciones nunca reflejó el esquema real, que se construyó a mano durante
 * años. La fuente de verdad sigue siendo database/schema/mysql-schema.sql; de
 * aquí en adelante, todo cambio va en una migración normal como esta.
 *
 * Consecuencia para el despliegue: `php artisan migrate` hay que correrlo en
 * CADA colegio, y hasta que se corra, el login nuevo devuelve 500 porque la
 * tabla no existe. Está anotado en docs/DESPLIEGUE.md.
 *
 * **Por qué no se usa la migración que trae Sanctum.** La suya no tiene
 * `expires_at`: Sanctum 2.15 solo sabe de una caducidad global calculada sobre
 * `created_at` (`config('sanctum.expiration')`), y aquí conviven tres vidas
 * distintas —acceso 60 min, refresco 14 días, legado 24 h—, así que la
 * caducidad tiene que ir por fila. `expires_at` es además la columna que
 * Sanctum 4 trae de serie, así que esto no es una desviación: es adelantarla.
 * Ver config/sesion.php y app/Services/Sesion.php.
 */
class CreatePersonalAccessTokensTable extends Migration
{
    public function up()
    {
        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->morphs('tokenable');

            // Identifica la SESIÓN, no el token: el de acceso y el de refresco
            // de una misma sesión comparten nombre ('web:<uuid>'), y por eso
            // cerrar sesión puede borrar el par entero con un solo DELETE.
            $table->string('name');

            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();

            // Caducidad por fila. La comprueba App\Services\Sesion, no Sanctum.
            $table->timestamp('expires_at')->nullable();

            // Rotación del refresco: apunta al que lo sustituyó. Sirve para
            // distinguir dos cosas que si no se confunden — un refresco que
            // simplemente caducó, y uno que ya se había usado y alguien vuelve
            // a presentar. Ver app/Services/Sesion.php::refrescar().
            $table->unsignedBigInteger('reemplazado_por')->nullable();

            $table->timestamps();

            // Para la limpieza periódica (php artisan sesion:limpiar).
            $table->index('expires_at');
        });
    }

    public function down()
    {
        Schema::dropIfExists('personal_access_tokens');
    }
}
