<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **Las limpiezas del historial**: una fila por cada vez que un superusuario borró la
 * auditoría hasta una fecha (`POST auditoria/limpieza`, `Support\LimpiezaDeAuditoria`).
 *
 * Es la constancia que no se borra: la limpieza sólo toca `auditoria`,
 * `importaciones.cambios`, `bitacoras` e `historiales`, nunca esta tabla. Se escribe al
 * empezar (`en_proceso`) y se pone al día tras cada tanda, así que si la petición se corta
 * a mitad la fila dice cuánto se llegó a borrar.
 *
 * ## Sólo añade
 *
 * Una tabla nueva, sin claves ajenas (la persona puede dejar de existir y la fila se tiene
 * que seguir leyendo, por eso lleva su nombre copiado) y sin tocar ninguna otra tabla.
 * Comprueba antes de crear: las bases `%testing%` del docker son de varias sesiones.
 */
class LasLimpiezasDeLaAuditoria extends Migration
{
    public function up()
    {
        if (Schema::hasTable('auditoria_limpiezas')) {
            return;
        }

        Schema::create('auditoria_limpiezas', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('user_id')->nullable();
            $table->string('actor_nombre', 120)->nullable();
            $table->date('hasta');
            // Lista separada por comas: auditoria,importaciones,bitacoras,historiales.
            $table->string('incluir', 100);
            // Lo que la previa del servidor contó al empezar…
            $table->unsignedInteger('previa_auditoria')->default(0);
            $table->unsignedInteger('previa_importaciones')->default(0);
            $table->unsignedInteger('previa_bitacoras')->default(0);
            $table->unsignedInteger('previa_historiales')->default(0);
            // …y lo que de verdad se borró, tanda a tanda.
            $table->unsignedInteger('borrados_auditoria')->default(0);
            $table->unsignedInteger('borrados_importaciones')->default(0);
            $table->unsignedInteger('borrados_bitacoras')->default(0);
            $table->unsignedInteger('borrados_historiales')->default(0);
            $table->dateTime('inicio');
            $table->dateTime('fin')->nullable();
            // en_proceso | terminada | cortada
            $table->string('estado', 20)->default('en_proceso');
            $table->text('error')->nullable();
        });
    }

    public function down()
    {
        Schema::dropIfExists('auditoria_limpiezas');
    }
}
