<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * A quién se le emitió el enlace de reseteo.
 *
 * `password_reminders` guardaba `email`, `token` y `created_at`, y nada más. Como el username no
 * estaba en ninguna parte, `putResetPassword` no tenía de dónde sacarlo y lo leía del cuerpo de la
 * petición:
 *
 *     UPDATE users SET password=? WHERE username=? and email=? and deleted_at is null
 *
 * O sea que un enlace abría cualquier cuenta que compartiera ese correo. Medido en la copia de
 * desarrollo: 16 cuentas en 8 grupos. Ver docs/migracion/12-larastan-nivel-7.md §8.
 *
 * La columna es nullable **a propósito y para siempre**, no por comodidad de la migración: las
 * filas que ya estén en la tabla cuando esto se despliegue no tienen a quién apuntar, y
 * `putResetPassword` las rechaza justamente por eso. Ponerle un valor por defecto convertiría un
 * token viejo en un token válido para ese valor.
 *
 * No lleva índice: la tabla se consulta siempre por `token`, que es único de hecho, y nunca por
 * username. Un índice que no se usa solo cuesta escrituras — misma regla que las siete de
 * 2026_08_20_100000, que sí se midieron con EXPLAIN antes de crearse.
 */
class AddUsernameToPasswordRemindersTable extends Migration
{
    public function up()
    {
        Schema::table('password_reminders', function (Blueprint $table) {
            $table->string('username', 191)->nullable()->after('email');
        });
    }

    public function down()
    {
        Schema::table('password_reminders', function (Blueprint $table) {
            $table->dropColumn('username');
        });
    }
}
