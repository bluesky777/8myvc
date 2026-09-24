<?php

use App\Support\Ancla;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * **La fecha de entrega de boletines de cada periodo.**
 *
 * El día en que el colegio entrega los boletines del periodo a las familias. Hasta
 * hoy no estaba en ninguna parte. Por ahora sólo se configura (tabla de periodos de
 * la configuración del colegio, `periodos/cambiar-fecha-entrega-boletines`) y sale
 * en las respuestas que leen `periodos` con `SELECT *`; nadie la usa todavía. Dónde
 * podría usarse: `myvc_front/PLAN-CIERRE-DE-PERIODO.md`.
 *
 * **Nace en NULL** = «sin fecha»: un colegio que actualiza no ve ningún cambio.
 * Aditiva: no toca ninguna fila existente. `down()` quita la columna.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('periodos', 'fecha_entrega_boletines')) {
            return;
        }

        Schema::table('periodos', function (Blueprint $tabla) {
            // Junto a las otras fechas del periodo. El sitio importa: se lee con
            // `SELECT *` y las instantáneas de contrato fijan el orden de los campos.
            $tabla->date('fecha_entrega_boletines')->nullable()->default(null)
                ->after(Ancla::de($tabla, 'fecha_plazo'));
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('periodos', 'fecha_entrega_boletines')) {
            Schema::table('periodos', function (Blueprint $tabla) {
                $tabla->dropColumn('fecha_entrega_boletines');
            });
        }
    }
};
