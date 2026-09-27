<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * LOS AVISOS DE LAS ACTIVIDADES  *(26 sep 2026, contrato §1.7)*.
 *
 * La bandeja de salida que leerá `EnviarNotificaciones` como fuente `actividades` (la marca es el
 * último `id`). La llena la tanda 5; se crea ya en la tanda 1 para que todo el esquema del módulo
 * salga en un solo despliegue. `faltan` (tanda 1) sólo la lee, para `ultimo_recordatorio_at`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ws_avisos')) {
            return;
        }

        Schema::create('ws_avisos', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedInteger('actividad_id');
            $t->enum('clase', ['publicada', 'recordatorio', 'por_cerrar', 'por_aprobar', 'aprobada',
                'rechazada', 'calificada', 'resultados', 'nota_cambiada']);
            $t->unsignedInteger('user_id')->nullable();
            $t->unsignedInteger('alumno_id')->nullable();
            $t->unsignedInteger('grupo_id')->nullable();
            $t->timestamp('created_at')->nullable();

            $t->index('actividad_id');
            $t->foreign('actividad_id')->references('id')->on('ws_actividades')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ws_avisos');
    }
};
