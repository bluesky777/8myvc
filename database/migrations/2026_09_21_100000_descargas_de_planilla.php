<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * **Qué libro de notas anda por fuera, y de quién.**
 *
 * Fase 1 de `myvc_front/PLAN-NOTAS-SIN-INTERNET.md`, §4.6: *«se anota aparte, en
 * una tabla `descargas_de_planilla` (quién, qué, cuándo, huella), para la
 * auditoría y para coordinación»*.
 *
 * ## Por qué una tabla y no una línea en el log
 *
 * Porque lo que hay que contestar no es «¿se descargó?» sino **«¿qué libro es
 * éste que me están subiendo?»**, y eso es una consulta. El asistente de la fase
 * 2 la necesita en tres momentos distintos:
 *
 *   1. **La huella dice si el archivo ya se subió.** Es el mismo mecanismo que
 *      `app2/src/comunes/huella-sha256.ts` usa en el importador de alumnos para
 *      saber si hay algo que reanudar **antes de pulsar nada**.
 *   2. **La fecha de descarga es media pantalla de choques** (§6.3): *«también
 *      cambiaron en el sistema desde que descargó el libro el 21/09 a las 4:12»*.
 *      Sin la fila, esa frase no se puede escribir — el libro lleva dentro su
 *      fecha, pero **el libro es justo lo que no nos podemos creer** cuando la
 *      firma está rota.
 *   3. **Coordinación tiene que poder ayudar cuando alguien rompe un libro**, y
 *      para eso hace falta saber cuál tenía.
 *
 * Y hay una cuarta, que es la que la hace de auditoría y no de comodidad: estas
 * descargas **sacan del sistema la planilla entera de un grupo, con los nombres de
 * los alumnos**. Que salgan datos personales por una ruta nueva sin dejar rastro
 * es exactamente lo que el doc 18 vino a cerrar en las otras cinco familias.
 *
 * ## Lo que NO guarda, y es deliberado
 *
 * **No guarda el fichero.** Ni el contenido ni una copia. Un libro de quince
 * asignaturas son cientos de kB y un colegio los genera a diario; guardar el
 * archivo convertiría una tabla de rastro en un almacén, y el rastro es lo que
 * hace falta. La huella basta para reconocerlo: si el que suben tiene otra, es
 * otro archivo, y eso es lo único que la pregunta necesita.
 *
 * **No guarda las notas.** El espejo vive dentro del libro (`_myvc`), firmado.
 * Duplicarlo aquí sería un segundo sitio donde mentir, y además una copia de las
 * notas de un periodo por cada vez que alguien pulsa descargar.
 *
 * ## `asignaturas` es `text` y no `json`
 *
 * Producción corre **MariaDB 10.5** y el docker MySQL 8 (ver `CLAUDE.md`). En
 * MariaDB `json` es un alias de `longtext` con un `CHECK` detrás, así que
 * declararlo deja dos esquemas que no son el mismo. Y aquí no se gana nada con el
 * tipo nativo: **nadie consulta dentro**; se lee entero y se decodifica en PHP.
 *
 * ## `profesor_id` y `user_id` son dos cosas distintas
 *
 * `user_id` es **quien pulsó el botón** y `profesor_id` **de quién es el libro**.
 * Coinciden casi siempre y no siempre: la D4 deja que coordinación baje el libro
 * de un docente. Guardar uno solo haría imposible contestar la pregunta que esta
 * tabla existe para contestar el día que no coincidan — que es justo el día en que
 * alguien pregunta.
 *
 * `profesor_id` es anulable porque una asignatura puede no tener docente asignado
 * (`asignaturas.profesor_id` lo es), y entonces el libro lo baja administración.
 *
 * ## Volver atrás
 *
 * Aditiva pura: una tabla nueva a la que no apunta nada. `down()` la tira y no se
 * pierde ningún dato del colegio — sólo el rastro de las descargas.
 *
 * ## Idempotente, por lo mismo que sus hermanas
 *
 * De las bases `%testing%` del docker la mayoría son de otras sesiones. Una
 * migración que no comprueba antes revienta en cuanto dos árboles la corren sobre
 * la misma base.
 */
class DescargasDePlanilla extends Migration
{
    public function up()
    {
        if (Schema::hasTable('descargas_de_planilla')) {
            return;
        }

        Schema::create('descargas_de_planilla', function (Blueprint $tabla) {
            $tabla->increments('id');

            // Quién pulsó y de quién es el libro. Ver la cabecera: no son lo mismo.
            $tabla->unsignedInteger('user_id');
            $tabla->unsignedInteger('profesor_id')->nullable();

            $tabla->unsignedInteger('year_id');
            $tabla->unsignedInteger('periodo_id');

            /*
             * **Si el periodo estaba abierto EN EL MOMENTO de la descarga.** Se guarda
             * y no se deduce: el colegio puede cerrar el periodo mañana, y entonces la
             * pregunta «¿este libro salió como copia de consulta?» dejaría de tener
             * respuesta. Es lo que decide el nombre del archivo, así que sin esto no se
             * puede reconstruir qué fichero se entregó.
             */
            $tabla->boolean('periodo_abierto')->default(true);

            // Los ids de las asignaturas que entraron, separados por coma. Ver la
            // cabecera: `text` y no `json`.
            $tabla->text('asignaturas')->nullable();

            // Cuántas hojas de asignatura trajo. Es el número que la pantalla enseña
            // en el botón («Descargar el libro (4 hojas)») y el que hace comprobable
            // que lo descargado es lo que se pidió.
            $tabla->unsignedInteger('hojas')->default(0);

            $tabla->string('nombre_archivo');

            /*
             * `char(64)`: un sha256 en hexadecimal mide exactamente eso, y fijarlo deja
             * el índice del tamaño que se espera. Es la huella del fichero **tal y como
             * se entregó**, así que la fase 2 puede reconocer un libro sin abrirlo.
             */
            $tabla->char('huella', 64);
            $tabla->unsignedInteger('bytes')->default(0);

            $tabla->timestamps();

            // La lectura del asistente: «el último libro de este docente para este
            // periodo». Por eso el orden de las columnas es ése y no el alfabético.
            $tabla->index(['profesor_id', 'periodo_id', 'created_at'], 'descargas_de_planilla_del_docente');

            // Y la otra: «¿de dónde salió este archivo que me están subiendo?».
            $tabla->index('huella', 'descargas_de_planilla_huella');

            $tabla->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $tabla->foreign('profesor_id')->references('id')->on('profesores')->onDelete('set null');
            $tabla->foreign('year_id')->references('id')->on('years')->onDelete('cascade');
            $tabla->foreign('periodo_id')->references('id')->on('periodos')->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('descargas_de_planilla');
    }
}
