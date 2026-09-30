<?php

use App\Support\Ancla;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **Lo que la importación de alumnos CAMBIÓ**, alumno por alumno y campo por campo.
 *
 * Decisión de Joseth del 30 sep 2026 (`myvc_front/AUDITORIA-DE-ALUMNOS.md`): la
 * importación no deja una línea de `auditoria` por alumno —serían cientos de filas por
 * subida—, sino **un solo registro en su propia fila de `importaciones`**:
 *
 *     {alumno_id: {campo: [antes, después], "acudientes": {acudiente_id: {campo: [antes, después]}}}}
 *
 * El alumno nuevo lleva `"_creado": true`. Lo escribe `PuntoDeControlDeImportacion`
 * junto con la marca de cada lote, y lo lee `auditoria/alumnos/datos`, que pinta una
 * línea por alumno cambiado.
 *
 * ## `longText` y no `json`, por lo mismo que sus tres hermanas
 *
 * Producción es MariaDB 10.5, donde `JSON` es un alias de `LONGTEXT` con un `CHECK`
 * detrás; el docker es MySQL 8. Ver `2026_09_21_300000_lo_que_la_importacion_hizo`.
 *
 * ## Anulable
 *
 * `NULL` es «no cambió nada, o es anterior a esta columna»: en los dos casos no hay
 * línea que pintar.
 *
 * ## Idempotente y aditiva
 *
 * Comprueba antes de añadir (las bases `%testing%` del docker son de varias sesiones) y
 * `down()` sólo tira la columna: se pierde el rastro de lo importado, ningún dato.
 */
class LoQueLaImportacionCambio extends Migration
{
    public function up()
    {
        Schema::table('importaciones', function (Blueprint $table) {
            if (! Schema::hasColumn('importaciones', 'cambios')) {
                $table->longText('cambios')->nullable()->after(Ancla::de($table, 'hechos'));
            }
        });
    }

    public function down()
    {
        Schema::table('importaciones', function (Blueprint $table) {
            if (Schema::hasColumn('importaciones', 'cambios')) {
                $table->dropColumn('cambios');
            }
        });
    }
}
