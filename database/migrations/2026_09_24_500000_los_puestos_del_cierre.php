<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * **SIN USO desde el 24 sep 2026** (Joseth): el puesto se calcula al vuelo siempre, como
 * la definitiva, y ya nadie escribe ni lee esta tabla (`PeriodosController` dejó de
 * llamar a `PuestosDelCierre::tomar` y `BoletinIndependiente::ponerPuestos` no mira la
 * foto). Se deja creada, vacía, y no se borra: borrar una tabla desplegada es una
 * migración que quita, y en producción sólo van las que añaden. Lo de abajo describe
 * lo que fue.
 *
 * `puestos_del_cierre`: la foto del puesto de cada alumno el día que se cerró el periodo.
 *
 * Fase 4 del cierre de periodo (`myvc_front/PLAN-CIERRE-DE-PERIODO.md`, decisiones 4 y 5
 * de Joseth del 23 sep 2026): **el puesto se congela al cerrar**, y al recerrar tras una
 * rendija **la foto se rehace siempre**. La escribe `App\Services\PuestosDelCierre` y la lee
 * `BoletinIndependiente::ponerPuestos` cuando el informe es de un solo periodo y ese
 * periodo está cerrado.
 *
 * Tabla nueva y vacía: no toca nada de lo que hay. Los periodos cerrados antes de esto no
 * tienen foto y se siguen calculando al vuelo, como hasta hoy.
 *
 * - `puesto` NULL es el independiente que no cuenta (decisión 6 del doc 19), no un hueco.
 * - `promedio` va guardado para poder explicar un puesto, no para recalcularlo.
 * - Único por (periodo, alumno): un alumno tiene un puesto por periodo.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('puestos_del_cierre')) {
            return;
        }

        Schema::create('puestos_del_cierre', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('periodo_id')->unsigned();
            $table->integer('grupo_id')->unsigned();
            $table->integer('alumno_id')->unsigned();
            $table->double('promedio')->nullable();
            $table->integer('puesto')->unsigned()->nullable();
            $table->dateTime('congelado_at');
            $table->integer('congelado_por')->nullable();

            $table->unique(['periodo_id', 'alumno_id'], 'puestos_del_cierre_periodo_alumno');
            $table->index('grupo_id');
            $table->foreign('periodo_id')->references('id')->on('periodos')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('puestos_del_cierre');
    }
};
