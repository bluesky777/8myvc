<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * LAS ENTREGAS DE LAS TAREAS  *(26 sep 2026, contrato §1.6 y §3.8)*.
 *
 *   ws_archivos   la foto o el archivo que sube un alumno (a una entrega, o a una pregunta de tipo
 *                 `archivo`). El fichero va en `storage/app/actividades/{year}/{actividad}/`, FUERA
 *                 de `public/`: es trabajo de un menor y sale sólo por `GET act/archivos/{id}`, con
 *                 token y permiso.
 *   ws_entregas   la entrega de la tarea: una por (actividad, alumno). `entregada_at` NULL = guardada
 *                 sin entregar. La nota la pone el docente (tanda 2).
 *
 * Y aquí se ata la FK de `ws_respuestas.archivo_id` —la columna la añade la migración de preguntas y
 * respuestas, que corre antes de que esta tabla exista—.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ws_archivos')) {
            Schema::create('ws_archivos', function (Blueprint $t) {
                $t->increments('id');
                $t->unsignedInteger('actividad_id');
                $t->unsignedInteger('user_id');
                $t->unsignedInteger('alumno_id')->nullable();
                $t->enum('clase', ['foto', 'archivo']);
                $t->string('nombre_original', 255);
                $t->string('ruta', 500);
                $t->string('mime', 100);
                $t->unsignedInteger('bytes');
                $t->unsignedSmallInteger('ancho')->nullable();
                $t->unsignedSmallInteger('alto')->nullable();
                $t->timestamps();
                $t->softDeletes();

                $t->index(['actividad_id', 'alumno_id']);
                $t->foreign('actividad_id')->references('id')->on('ws_actividades')->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('ws_entregas')) {
            Schema::create('ws_entregas', function (Blueprint $t) {
                $t->increments('id');
                $t->unsignedInteger('actividad_id');
                $t->unsignedInteger('alumno_id');
                $t->unsignedInteger('user_id');
                $t->text('texto')->nullable();
                $t->string('enlace', 500)->nullable();
                $t->unsignedInteger('foto_id')->nullable();
                $t->unsignedInteger('archivo_id')->nullable();
                $t->dateTime('entregada_at')->nullable();
                $t->boolean('tarde')->default(false);
                $t->smallInteger('nota')->nullable();
                $t->text('comentario')->nullable();
                $t->unsignedInteger('calificada_por')->nullable();
                $t->dateTime('calificada_at')->nullable();
                $t->timestamps();

                $t->unique(['actividad_id', 'alumno_id']);
                $t->foreign('actividad_id')->references('id')->on('ws_actividades')->cascadeOnDelete();
                $t->foreign('foto_id')->references('id')->on('ws_archivos')->nullOnDelete();
                $t->foreign('archivo_id')->references('id')->on('ws_archivos')->nullOnDelete();
            });
        }

        if (Schema::hasColumn('ws_respuestas', 'archivo_id') && ! $this->tieneFk('ws_respuestas_archivo_id_foreign')) {
            Schema::table('ws_respuestas', function (Blueprint $t) {
                $t->foreign('archivo_id')->references('id')->on('ws_archivos')->nullOnDelete();
            });
        }
    }

    private function tieneFk(string $nombre): bool
    {
        return DB::selectOne(
            "SELECT 1 AS si FROM information_schema.table_constraints
              WHERE constraint_schema = DATABASE() AND table_name = 'ws_respuestas'
                AND constraint_type = 'FOREIGN KEY' AND constraint_name = ?",
            [$nombre]
        ) !== null;
    }

    public function down(): void
    {
        if ($this->tieneFk('ws_respuestas_archivo_id_foreign')) {
            Schema::table('ws_respuestas', fn (Blueprint $t) => $t->dropForeign('ws_respuestas_archivo_id_foreign'));
        }

        Schema::dropIfExists('ws_entregas');
        Schema::dropIfExists('ws_archivos');
    }
};
