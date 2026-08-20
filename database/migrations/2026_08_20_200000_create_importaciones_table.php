<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * El punto de control de las importaciones, que hasta hoy era un `Debugging::pin`.
 *
 * Es el §1 de docs/migracion/09-pendientes.md. La intuición original era la
 * correcta —hace falta saber por dónde iba la importación cuando el servidor la
 * corta— y está escrita en el propio importador, en las dos líneas marcadas
 * «No eliminar para continuar si se cae el servidor!!». Lo que le faltaba era
 * dónde vivir: `Debugging::pin('Alum_id: 431', 'Grupo: 5A', ...)` guarda TRES
 * CADENAS por alumno, sin decir de qué archivo ni de qué año son, así que el
 * código no puede leerlas — solo un humano, mirando la tabla y adivinando.
 *
 * Esta tabla guarda lo mismo en una forma que el importador puede consultar
 * SOLO: qué archivo era, qué año, por qué fila de qué hoja iba, y si terminó.
 *
 * **Una fila por importación, no por alumno.** `debugging` crece una fila por
 * cada alumno de cada importación de cada año y no se limpia nunca; esta crece
 * una fila cada vez que la secretaría sube una hoja.
 *
 * **Y de paso contesta la pregunta que nadie sabía contestar**: cuánto tarda de
 * verdad una importación. Ese número es el que decide si `max_execution_time`
 * puede bajar de los 300 s que tiene la cuenta de cPanel, y hasta hoy solo
 * existía como «tardaba mucho»:
 *
 *     SELECT archivo, year, filas, TIMESTAMPDIFF(SECOND, inicio, fin) AS segundos
 *     FROM importaciones WHERE estado = 'completada' ORDER BY id DESC;
 */
class CreateImportacionesTable extends Migration
{
    public function up()
    {
        Schema::create('importaciones', function (Blueprint $table) {
            $table->increments('id');

            /*
             * Qué importador la creó. Hoy solo hay uno vivo —'alumnos'—, porque
             * el de cartera está roto desde el salto a maatwebsite/excel 3.x
             * (docs/migracion/05-codigo-muerto-y-roto.md §8). La columna existe
             * para que el día que se arregle no haya que migrar la tabla.
             */
            $table->string('tipo', 20);

            /*
             * La huella es lo que identifica «el mismo archivo», y por eso es
             * el contenido y no el nombre: la secretaría sube tres veces
             * `alumnos.xlsx` y son tres archivos distintos.
             *
             * Es sha256 del contenido subido. `archivo` guarda el nombre solo
             * para que un humano reconozca la fila al mirarla.
             */
            $table->char('huella', 64);
            $table->string('archivo', 255)->nullable();
            $table->integer('year');

            /*
             * Por dónde iba: {"<nombre de hoja>": <última fila procesada>}.
             *
             * Un JSON y no dos columnas (hoja, fila) porque una hoja de alumnos
             * trae una pestaña por grupo y el importador las recorre en el orden
             * que le da el archivo. Con hoja+fila hay que saber además qué hojas
             * quedaron detrás; con el mapa, cada hoja se pregunta por sí misma y
             * el orden deja de importar.
             */
            $table->json('avance')->nullable();

            /** Cuántas filas se han procesado en total. Es lo que se divide por el tiempo. */
            $table->integer('filas')->default(0);

            /*
             * en_proceso · completada · fallida.
             *
             * Se reanuda todo lo que NO esté 'completada'. Que 'fallida' también
             * se reanude es a propósito: si el fallo era del dato de una fila,
             * el reintento llega a esa fila enseguida en vez de repetir las mil
             * anteriores, y el error queda escrito en vez de perderse en un 500.
             */
            $table->string('estado', 20)->default('en_proceso');
            $table->text('error')->nullable();

            $table->integer('created_by')->nullable();
            $table->timestamp('inicio')->nullable();
            $table->timestamp('fin')->nullable();
            $table->timestamps();

            /* La consulta del arranque: ¿hay algo a medias de ESTE archivo? */
            $table->index(['tipo', 'huella', 'year', 'estado'], 'importaciones_reanudar_index');
        });

        /*
         * El documento del alumno, que es la clave natural con la que la
         * importación deja de duplicar.
         *
         * Va aquí y no en la migración de índices del paso 12 porque hasta hoy
         * no había ninguna consulta que buscara por él: `alumnos` se consulta
         * por `id`. La que lo estrena es la de este trabajo —antes de crear un
         * alumno, mirar si su documento ya está—, y el EXPLAIN da exactamente el
         * criterio de aquella migración: `type: ALL`, `possible_keys: NULL`, o
         * sea que NO EXISTE índice que MySQL pudiera considerar.
         *
         * No es único a propósito: hay filas históricas con `documento` vacío o
         * repetido, y una restricción UNIQUE aquí haría fallar el ALTER en los
         * colegios que las tengan. El importador comprueba el duplicado leyendo,
         * que es lo que se puede desplegar sin mirar dieciséis bases antes.
         */
        Schema::table('alumnos', function (Blueprint $table) {
            $table->index('documento', 'alumnos_documento_index');
        });
    }

    public function down()
    {
        Schema::table('alumnos', function (Blueprint $table) {
            $table->dropIndex('alumnos_documento_index');
        });

        Schema::dropIfExists('importaciones');
    }
}
