<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * **El cierre por asignatura: cada docente cierra la suya.**
 *
 * Fase 2 de `myvc_front/PLAN-CIERRE-DE-PERIODO.md` (propuesta B del mock). El
 * candado del periodo —`periodos.profes_pueden_editar_notas`— sigue siendo el
 * sobre: marca la ventana en la que se puede trabajar. Dentro, el docente
 * **cierra su asignatura** cuando termina, y coordinación la **reabre con fecha
 * y motivo**. Decisión 2 de Joseth del 23 sep 2026.
 *
 * ## UNA FILA = UNA ASIGNATURA DE UN PERIODO QUE SU DOCENTE CERRÓ
 *
 * **Sin fila, la asignatura está abierta**: es lo que hace que la migración nazca
 * neutra. Un colegio que actualiza no ve ningún cambio hasta que alguien pulse
 * «Cerrar la asignatura», porque hasta entonces la tabla está vacía y los guards
 * de `User` no encuentran nada que mirar.
 *
 * ## `estado` Y `reabierta_hasta`: LA RENDIJA SE CIERRA SOLA
 *
 * - `cerrada`: el docente la cerró; nadie de tipo Profesor escribe notas ni
 *   asistencia en ella.
 * - `reabierta`: coordinación abrió una rendija **hasta `reabierta_hasta`**. Pasada
 *   esa hora vuelve a contar como cerrada **sin que nadie escriba nada**: la
 *   comparación con la hora se hace al leer (`App\Support\CierreDeAsignatura`), no
 *   con una tarea programada, porque este backend no tiene ninguna que toque
 *   periodos y una rendija que dependiera de un cron se quedaría abierta el día
 *   que el cron no corriera.
 *
 * `cerrada_at/por` y `reabierta_at/por` + `motivo` son el rastro que una comisión
 * de evaluación pregunta en diciembre. Es **la última** reapertura, no la
 * historia entera: la auditoría del colegio ya guarda cada `UPDATE`.
 *
 * ## EL ÚNICO EN (periodo_id, asignatura_id)
 *
 * Una asignatura se cierra una vez por periodo; cerrar de nuevo actualiza la fila.
 *
 * ## VOLVER ATRÁS
 *
 * `down()` borra la tabla. Sin ella los guards no encuentran filas, que es
 * exactamente el comportamiento de antes.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('cierres_asignatura')) {
            return;
        }

        Schema::create('cierres_asignatura', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('periodo_id')->unsigned();
            $table->integer('asignatura_id')->unsigned();
            $table->enum('estado', ['cerrada', 'reabierta'])->default('cerrada');
            $table->dateTime('cerrada_at');
            $table->integer('cerrada_por')->nullable();
            $table->dateTime('reabierta_hasta')->nullable();
            $table->dateTime('reabierta_at')->nullable();
            $table->integer('reabierta_por')->nullable();
            $table->string('motivo', 500)->nullable();
            $table->timestamps();

            $table->unique(['periodo_id', 'asignatura_id'], 'cierres_asignatura_periodo_asignatura');
            $table->index('asignatura_id');
            $table->foreign('periodo_id')->references('id')->on('periodos')->onDelete('cascade');
            $table->foreign('asignatura_id')->references('id')->on('asignaturas')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cierres_asignatura');
    }
};
