<?php

use App\Support\Ancla;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * LAS PREGUNTAS INTERACTIVAS  *(1 oct 2026, contrato de actividades §1.7 y tanda 7)*.
 *
 * Los 33 retos animados del prototipo de inglés entran como UN tipo de pregunta más,
 * `interactiva`, y no como módulo aparte (Joseth, 1 oct). No hace falta tabla: dos columnas.
 *
 * - `ws_preguntas.config`: `{"reto": "<clave>", "cfg": {…}, "respuesta": "…"}`, el JSON tal como lo
 *   manda el editor. `cfg` es el objeto del motor (`T[reto].toCfg()`); el backend sólo lo lee para
 *   calificar (`App\Services\Act\Retos`).
 * - `ws_respuestas.estado`: el estado del motor (`s`), tal cual. Una fila por pregunta interactiva.
 *
 * `TEXT` y no `JSON`: los dieciséis no tienen todos un MySQL que conozca el tipo, y el tope de
 * 20 000 bytes lo pone la API, no la columna. Sólo añade, y con guarda: corre dos veces seguidas
 * sin error y no toca ninguna fila existente.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ws_preguntas') && ! Schema::hasColumn('ws_preguntas', 'config')) {
            Schema::table('ws_preguntas', function (Blueprint $t) {
                $columna = $t->text('config')->nullable();
                $ancla = Ancla::de($t, 'puntaje_parcial');

                if ($ancla !== null) {
                    $columna->after($ancla);
                }
            });
        }

        if (Schema::hasTable('ws_respuestas') && ! Schema::hasColumn('ws_respuestas', 'estado')) {
            Schema::table('ws_respuestas', function (Blueprint $t) {
                $columna = $t->text('estado')->nullable();
                $ancla = Ancla::de($t, 'archivo_id');

                if ($ancla !== null) {
                    $columna->after($ancla);
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('ws_respuestas', 'estado')) {
            Schema::table('ws_respuestas', fn (Blueprint $t) => $t->dropColumn('estado'));
        }

        if (Schema::hasColumn('ws_preguntas', 'config')) {
            Schema::table('ws_preguntas', fn (Blueprint $t) => $t->dropColumn('config'));
        }
    }
};
