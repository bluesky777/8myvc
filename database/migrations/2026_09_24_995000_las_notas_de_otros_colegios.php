<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * LAS NOTAS DE OTROS COLEGIOS Y DE AÑOS ANTIGUOS  *(24 sep 2026, pedido por Joseth)*.
 *
 * Nivel 2 de `myvc_front/NOTAS-DE-OTRO-COLEGIO.md`: además del documento (nivel 1, migración
 * `2026_09_24_990000`), la definitiva de cada asignatura de ese año, escrita a mano, para que el
 * certificado de todos los años la imprima junto a las cursadas aquí.
 *
 *   notas_externas             una fila por asignatura: el nombre COMO VENÍA, la nota como venía
 *                              (`nota_original`, texto: «3,8», «S») y la ya convertida a la escala
 *                              de este colegio (`nota`), con su desempeño.
 *   anos_externos.escala_*     la escala del otro colegio --mínima, máxima y con cuánto se
 *                              aprueba--, que es lo que hace falta para convertir por tramos.
 *
 * Joseth decidió el 24 sep: sólo la definitiva del año (no periodos) y conversión POR TRAMOS, que
 * ancla la mínima aprobatoria: lo que allá aprobaba, aquí aprueba.
 *
 * Sólo AÑADE: una tabla nueva y tres columnas nulas en una tabla nacida hoy.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('anos_externos', 'escala_min')) {
            Schema::table('anos_externos', function (Blueprint $tabla) {
                $tabla->decimal('escala_min', 6, 2)->nullable()->after('observaciones');
                $tabla->decimal('escala_max', 6, 2)->nullable()->after('escala_min');
                $tabla->decimal('escala_aprueba', 6, 2)->nullable()->after('escala_max');
            });
        }

        if (! Schema::hasTable('notas_externas')) {
            Schema::create('notas_externas', function (Blueprint $tabla) {
                $tabla->increments('id');
                $tabla->unsignedInteger('ano_externo_id');
                $tabla->unsignedInteger('materia_id')->nullable();
                $tabla->string('area_texto', 160)->nullable();
                $tabla->string('asignatura_texto', 160);
                $tabla->unsignedTinyInteger('intensidad')->nullable();
                $tabla->string('nota_original', 12);
                $tabla->decimal('nota', 7, 2)->nullable();
                $tabla->string('desempenio', 40)->nullable();
                $tabla->unsignedSmallInteger('orden')->default(0);
                $tabla->unsignedInteger('created_by')->nullable();
                $tabla->timestamps();
                $tabla->softDeletes();

                $tabla->index('ano_externo_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('notas_externas');

        if (Schema::hasColumn('anos_externos', 'escala_min')) {
            Schema::table('anos_externos', function (Blueprint $tabla) {
                $tabla->dropColumn(['escala_min', 'escala_max', 'escala_aprueba']);
            });
        }
    }
};
