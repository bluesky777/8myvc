<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * LOS DESTINATARIOS  *(26 sep 2026, contrato §1.5 y §2.7)*.
 *
 * A quién va algo, en una tabla genérica: hoy la usan las actividades (`origen_tipo = 'actividad'`)
 * y el calendario puede migrar aquí después. Una fila = un público en un alcance:
 *
 *   grupo_id / grado_id / asignatura_id   el alcance (los tres NULL = todo el colegio del año)
 *   user_id                                una persona suelta del personal
 *
 * Sin FK: `origen_id` apunta a tablas distintas según `origen_tipo`, y las demás son tablas viejas
 * (ver la cabecera de `2026_09_27_100000_las_actividades_nuevas`).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('destinatarios')) {
            return;
        }

        Schema::create('destinatarios', function (Blueprint $t) {
            $t->increments('id');
            $t->string('origen_tipo', 30);
            $t->unsignedInteger('origen_id');
            $t->enum('publico', ['personal', 'docentes', 'directivos', 'alumnos', 'acudientes_oficiales']);
            $t->unsignedInteger('grupo_id')->nullable();
            $t->unsignedInteger('grado_id')->nullable();
            $t->unsignedInteger('asignatura_id')->nullable();
            $t->unsignedInteger('user_id')->nullable();
            $t->timestamps();

            $t->index(['origen_tipo', 'origen_id']);
            $t->index(['publico', 'grupo_id']);
            $t->index(['publico', 'grado_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('destinatarios');
    }
};
