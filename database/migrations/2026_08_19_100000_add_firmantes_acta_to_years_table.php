<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Firmantes de la comisión de evaluación y promoción.
 *
 * El acta de evaluación y promoción es un documento que certifica que un grupo de personas se
 * reunió, deliberó y decidió. Hasta ahora el informe terminaba en la última tabla, sin dónde
 * firmar, así que el colegio lo imprimía y le agregaba las firmas a mano en una hoja aparte.
 *
 * Se guarda como JSON en years y no en una tabla propia porque es configuración del año lectivo,
 * igual que resolucion, encabezado_certificado o texto_acta_eval, que ya viven ahí como texto.
 *
 * El sello horario va después del de la migración de personal_access_tokens (Fase 3) a propósito:
 * compartir timestamp deja el orden de ejecución a merced de cómo el sistema de archivos devuelva
 * los nombres.
 */
class AddFirmantesActaToYearsTable extends Migration
{
    public function up()
    {
        Schema::table('years', function (Blueprint $table) {
            $table->text('firmantes_acta')->nullable()->after('texto_acta_eval');
        });
    }

    public function down()
    {
        Schema::table('years', function (Blueprint $table) {
            $table->dropColumn('firmantes_acta');
        });
    }
}
