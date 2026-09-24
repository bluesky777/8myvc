<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * **Desde cuándo está cada matrícula en su estado**, y cuántos días se tolera una
 * prematrícula o un asistente (`myvc_front/PLAN-COSAS-PENDIENTES.md` §4).
 *
 * El encargo de Joseth del 24 sep 2026: *desde el segundo periodo no debería haber
 * prematriculados ni asistentes a los que se les puso ese estado hace más de 10 días, y los
 * días se personalizan*. Hoy sólo PREM tiene fecha (`matriculas.prematriculado`); ASIS y PREA
 * no tienen ninguna, y `updated_at` cambia con cualquier edición.
 *
 *   - `matriculas.estado_desde`: la escribe el modelo `Matricula` cada vez que cambia
 *     `estado`. Las filas viejas quedan en NULL: no se inventa una fecha. El pendiente usa
 *     entonces la mejor que hay (`prematriculado`, `fecha_matricula`, `created_at`) y lo dice.
 *   - `years.dias_max_prematricula`: 10 por defecto.
 *
 * Aditiva: dos columnas nuevas con NULL o valor por defecto; no toca ninguna fila existente.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('matriculas', 'estado_desde')) {
            Schema::table('matriculas', function (Blueprint $tabla) {
                $tabla->date('estado_desde')->nullable();
            });
        }

        if (! Schema::hasColumn('years', 'dias_max_prematricula')) {
            Schema::table('years', function (Blueprint $tabla) {
                $tabla->integer('dias_max_prematricula')->default(10);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('matriculas', 'estado_desde')) {
            Schema::table('matriculas', function (Blueprint $tabla) {
                $tabla->dropColumn('estado_desde');
            });
        }

        if (Schema::hasColumn('years', 'dias_max_prematricula')) {
            Schema::table('years', function (Blueprint $tabla) {
                $tabla->dropColumn('dias_max_prematricula');
            });
        }
    }
};
