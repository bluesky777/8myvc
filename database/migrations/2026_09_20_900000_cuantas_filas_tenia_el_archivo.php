<?php

use App\Support\Ancla;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cuántas filas tenía el archivo, que es el denominador que faltaba.
 *
 * Sale de la decisión de Joseth del 20 sep 2026 sobre el aviso de «importación
 * a medias»: mientras una importación no termine, las pantallas que listan
 * alumnos tienen que decirlo, porque desde que la importación se trocea **un
 * corte deja medio colegio cargado y nada en pantalla lo cuenta**.
 *
 * ## Por qué hace falta una columna y no se calcula
 *
 * `importaciones.filas` sabe cuántas van aplicadas. El total **sólo se sabe
 * leyendo el libro**, y el caso que este aviso viene a cubrir es justo aquel en
 * el que el libro ya no está: alguien cerró el navegador el viernes y otra
 * persona abre la pantalla el lunes.
 *
 * Sin esto el aviso diría «500 filas aplicadas» y con esto «500 de 1.000», que
 * es la diferencia entre un número y un número con su denominador — la regla que
 * este repo lleva meses pagando en otros sitios.
 *
 * ## Anulable, y el NULL significa algo
 *
 * `NULL` es **«no se sabe»**, no «cero»: las importaciones anteriores a hoy
 * nunca midieron su total, y las de los dieciséis colegios ya están escritas. La
 * pantalla tiene que poder distinguir «500 de 1.000» de «500, y no sé de
 * cuántas» — inventarle un total a una fila vieja sería peor que no tenerlo.
 *
 * ## Qué mueve, contado y no supuesto
 *
 * **Cero instantáneas.** Medido antes de escribirla con
 * `tools/lo-que-reparte-una-columna.py importaciones`: de las 129 de
 * `tests/Contrato/Snapshots`, **ninguna lleva dentro una fila entera de esta
 * tabla** —el mayor solape es de 6 columnas de 14—, así que no hay nada que
 * regenerar.
 *
 * Lo que sí cuesta es lo otro: `PuntoDeControlDeImportacion::pendienteDe()`
 * nombra sus columnas una a una, así que **la base de test que no se migre
 * revienta esa consulta** en vez de ignorar la columna. Es la contrapartida
 * conocida de un `SELECT` explícito frente a un `SELECT *`.
 *
 * ## Idempotente, por lo mismo que su hermana
 *
 * De las bases `%testing%` del docker la mayoría son de otras sesiones. Una
 * migración que no comprueba antes revienta con `Duplicate column name` en
 * cuanto dos árboles la corren sobre la misma base.
 *
 * `INT` anulable y al final de la fila: en MariaDB desde la 10.4 eso es
 * instantáneo, así que la tanda no reconstruye la tabla en los dieciséis.
 */
class CuantasFilasTeniaElArchivo extends Migration
{
    public function up()
    {
        Schema::table('importaciones', function (Blueprint $table) {
            if (! Schema::hasColumn('importaciones', 'filas_totales')) {
                $table->integer('filas_totales')->nullable()->after(Ancla::de($table, 'filas'));
            }
        });
    }

    public function down()
    {
        Schema::table('importaciones', function (Blueprint $table) {
            if (Schema::hasColumn('importaciones', 'filas_totales')) {
                $table->dropColumn('filas_totales');
            }
        });
    }
}
