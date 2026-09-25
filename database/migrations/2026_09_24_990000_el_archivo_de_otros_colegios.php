<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * EL ARCHIVO DE OTROS COLEGIOS Y DE AÑOS ANTIGUOS  *(24 sep 2026, pedido por Joseth)*.
 *
 * Un alumno nuevo trae los boletines y certificados de los años que cursó en otro colegio; y de los
 * años de este colegio de antes de MYVC sólo hay papel. **Nada de eso es una matrícula**: no tiene
 * grupo, ni periodos, ni asistencia, y el año puede no existir en `years`. Así que va aparte:
 *
 *   anos_externos         un año cursado fuera (o aquí, antes de MYVC: `propio = 1`), con el colegio,
 *                         el grado como venía escrito y, si es propio, el libro y el folio de papel.
 *   documentos_externos   el PDF o la foto de ese año. El fichero va en `storage/`, NO en `public/`:
 *                         son datos de un menor y se descargan con token (ver el controlador).
 *
 * Es el nivel 1 de `myvc_front/NOTAS-DE-OTRO-COLEGIO.md` §8: el archivo. Las notas en tablas
 * (`notas_externas`) llegan después y cuelgan de `anos_externos`.
 *
 * Sólo AÑADE: dos tablas nuevas, ni una columna de una que ya exista.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('anos_externos')) {
            Schema::create('anos_externos', function (Blueprint $tabla) {
                $tabla->increments('id');
                $tabla->unsignedInteger('alumno_id');
                $tabla->smallInteger('year');
                $tabla->unsignedInteger('grado_id')->nullable();
                $tabla->string('grado_texto', 60)->nullable();
                $tabla->boolean('propio')->default(false);
                $tabla->string('colegio_nombre', 160)->nullable();
                $tabla->string('colegio_municipio', 80)->nullable();
                $tabla->string('libro', 20)->nullable();
                $tabla->string('folio', 20)->nullable();
                $tabla->text('observaciones')->nullable();
                $tabla->unsignedInteger('created_by')->nullable();
                $tabla->unsignedInteger('updated_by')->nullable();
                $tabla->timestamps();
                $tabla->softDeletes();

                $tabla->index('alumno_id');
            });
        }

        if (! Schema::hasTable('documentos_externos')) {
            Schema::create('documentos_externos', function (Blueprint $tabla) {
                $tabla->increments('id');
                $tabla->unsignedInteger('ano_externo_id');
                $tabla->unsignedInteger('alumno_id');
                $tabla->string('nombre_original', 200);
                $tabla->string('archivo', 80);
                $tabla->string('tipo', 60);
                $tabla->unsignedInteger('bytes');
                $tabla->unsignedInteger('created_by')->nullable();
                $tabla->timestamps();
                $tabla->softDeletes();

                $tabla->index('ano_externo_id');
                $tabla->index('alumno_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('documentos_externos');
        Schema::dropIfExists('anos_externos');
    }
};
