<?php

use App\Support\Ancla;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * **¿Este paso del recorrido es un documento que la familia entrega?**
 *
 * Lo destapó la pantalla 06 del portal (`myvc_front/PANTALLAS-MATRICULA.md`) al pintarla
 * contra datos: el portal ofrece «Subir» y «Lo llevo en papel» por cada requisito de la
 * campaña, y **en el recorrido de un colegio los requisitos son también las estaciones**
 * —Recepción, Entrevista, Tesorería—. Sin esta columna, la familia veía un botón para
 * «subir la Entrevista».
 *
 * La migración del día de matrículas (`2026_09_20_300000`) dejó `tipo` fuera con un motivo
 * —*«no lo pidió nadie; una columna sin pantalla no la escribe nadie nunca»*—. Esta no es
 * aquella: es un interruptor de sí/no, lo escribe la pantalla 01 (`app2/paginas/requisitos`,
 * columna «Se entrega»), y lo lee el portal.
 *
 * ## NACE EN 1, y es lo que la hace desplegable
 *
 * Con 1 todo sigue como ayer: el portal lista todos los requisitos, que es lo que hacía.
 * El colegio apaga los que no son papeles cuando arme su recorrido. Nacer en 0 habría
 * dejado el portal sin documentos en los dieciséis el día del despliegue.
 *
 * Sólo AÑADE una columna: no toca ninguna fila existente.
 */
return new class extends Migration
{
    public function up()
    {
        if (Schema::hasColumn('requisitos_matricula', 'pide_documento')) {
            echo "  requisitos_matricula.pide_documento: ya existe, no se toca.\n";

            return;
        }

        Schema::table('requisitos_matricula', function (Blueprint $tabla) {
            $tabla->boolean('pide_documento')->default(true)->after(Ancla::de($tabla, 'bloquea'));
        });
    }

    public function down()
    {
        if (Schema::hasColumn('requisitos_matricula', 'pide_documento')) {
            Schema::table('requisitos_matricula', function (Blueprint $tabla) {
                $tabla->dropColumn('pide_documento');
            });
        }
    }
};
