<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Los desempeños: **el par que ya funciona en este sistema**, otra vez.
 *
 * Es la **Fase 3** de
 * [35-el-modelo-de-evaluacion-del-colegio.md](../../docs/migracion/35-el-modelo-de-evaluacion-del-colegio.md),
 * y sale de la **D5**: el texto del boletín es *«un catálogo del colegio que se
 * siembra»*, escrito **una vez por materia + grado + periodo** y copiado a cada
 * asignatura de ese grado.
 *
 *     desempenos_por_defecto   ──  sembrar  ──▶   desempenos
 *     (del año, del colegio)                      (de la asignatura y el periodo)
 *
 * Es `unidades_por_defecto` → `unidades` con otro nombre, y a propósito: esa
 * pareja lleva años funcionando y sus fallos están fichados uno a uno.
 *
 * ## Ningún dato existente se toca
 *
 * Dos `CREATE TABLE` y nada más. Ni `ALTER`, ni `UPDATE`, ni back-fill. **Con las
 * dos tablas vacías, la planilla y los tres boletines dan byte a byte lo de hoy**,
 * porque nadie las lee todavía. Volver atrás es borrarlas.
 *
 * ## `definicion` es `text`, y aquí es donde de verdad importa
 *
 * Éste es el texto que **acaba impreso en el boletín**: es el que la Fase 0
 * persiguió en `frases_asignatura` —626 frases cortadas a mitad de palabra y ya
 * entregadas a las familias (doc 28 §1.ter)—. Nacer `varchar(255)` aquí sería
 * repetir ese trabajo dentro de dos años, y MySQL **no está en modo estricto** en
 * este contenedor: el corte llega con un 200 y sin una línea en el log.
 *
 * ## `por_defecto` significa lo MISMO que en `unidades`, y eso es lo que hace D14
 *
 * «Esta fila la sembró el colegio». No hay ninguna columna nueva de permisos y no
 * hace falta: el candado de la **D14** —*«el docente no toca los del colegio, pero
 * añade los suyos»*— es exactamente `por_defecto = 1`, que es lo que ya decidió la
 * §5.1.e del doc 28 al contestar *«¿excepción por fila? No: candado binario.
 * Sembrada es candada»*. **La migración de los cuatro `can_change_*` desapareció
 * con esa respuesta y no vuelve aquí.**
 *
 * ## `tipo` nace vacía y es del colegio (D7)
 *
 * Diez de los catorce SIEE clasifican el texto —saber/hacer/ser,
 * cognitivo/procedimental/actitudinal, conceptual…— y **cada colegio le pone otras
 * palabras**. Por eso es un `varchar` anulable y **no** un
 * `enum('saber','hacer','ser')`: este sistema ya gastó seis columnas de
 * `displayname` en no hacer eso. El que no la quiera **no la ve**.
 *
 * ## `competencia_id` es anulable, y ésa es la D10 entera
 *
 * *«El colegio que sólo quiera los textos del periodo no escribe ni una
 * competencia y no ve nada.»* Un desempeño sin padre es un desempeño válido.
 *
 * ## Las claves foráneas: las mismas tres decisiones que `competencias`
 *
 * Llevan FK **lo que no puede significar nada estando a NULL y cuyo borrado físico
 * deja basura de verdad**: `year_id`, `periodo_id` y `asignatura_id`. Es lo mismo
 * que tiene `unidades` hoy (`asignatura_id` y `periodo_id`, las dos en cascada).
 *
 * **`materia_id`, `grado_id`, `alumno_id` y `competencia_id` NO la llevan**, y por
 * el argumento de `2026_09_05_200000_alcance_de_la_plantilla`, que aquí se repite
 * con un añadido que lo cierra:
 *
 *   - `ON DELETE SET NULL` es el peor: `grado_id NULL` significa **«para todos los
 *     grados»** y `competencia_id NULL` significa **«sin padre»**, así que borrar un
 *     grado convertiría el plan de área de 6.º en el del colegio entero, y borrar
 *     una competencia dejaría a sus hijos **diciendo que nunca tuvieron madre**.
 *   - `ON DELETE CASCADE` borraría en silencio lo que el colegio escribió.
 *   - **Y las dos fallan donde más se usa**: materias, grados, alumnos y
 *     competencias se borran **en lógico** en los caminos normales de esta API, y
 *     una FK **no se entera de un `UPDATE deleted_at`**. Daría integridad en el caso
 *     raro y ninguna en el frecuente.
 *
 * Sin FK, un desempeño huérfano **se ve y se arregla** en la pantalla en vez de
 * desaparecer o de cambiar de alcance solo. Que el id exista se comprueba **al
 * escribir**, con 422 y el nombre del campo delante.
 *
 * ## El prefijo `09_13_300000`
 *
 * Detrás de `2026_09_13_200000_competencias`, que es de quien cuelga
 * `competencia_id`. **No hay FK entre las dos** —ver arriba— pero el orden escrito
 * hace determinista el despliegue, que es la razón de siempre.
 */
class Desempenos extends Migration
{
    public function up()
    {
        /*
         * El catálogo del colegio: **por año, materia, grado y PERIODO**.
         *
         * El periodo es lo que separa esta tabla de `competencias`, y es D5 otra
         * vez: *«sin periodo, el boletín de periodo acabaría imprimiendo los mismos
         * textos cuatro veces»*. Es también el grano del Decreto 230 art. 3 c) —«al
         * finalizar cada uno de los períodos del año escolar, en cada área y
         * grado»— y el de los trece programas y los catorce SIEE.
         */
        Schema::create('desempenos_por_defecto', function (Blueprint $tabla) {
            $tabla->increments('id');
            $tabla->unsignedInteger('year_id');
            $tabla->unsignedInteger('materia_id');

            // `NULL` es «para todos los grados». Y **acumula con el del grado, no
            // compite** (D25): es deliberadamente lo contrario que la plantilla de
            // notas, donde gana la más específica, porque allí son porcentajes que
            // pelean por el 100 % y aquí son textos que conviven.
            $tabla->unsignedInteger('grado_id')->nullable();

            $tabla->unsignedInteger('periodo_id');
            $tabla->unsignedInteger('competencia_id')->nullable();
            $tabla->string('tipo', 60)->nullable();
            $tabla->text('definicion');
            $tabla->integer('orden')->default(0);

            $tabla->integer('created_by')->nullable();
            $tabla->integer('updated_by')->nullable();
            $tabla->integer('deleted_by')->nullable();
            $tabla->timestamps();
            $tabla->softDeletes();

            $tabla->index(
                ['year_id', 'materia_id', 'grado_id', 'periodo_id'],
                'desempenos_defecto_alcance'
            );

            $tabla->foreign('year_id')->references('id')->on('years')->onDelete('cascade');
            $tabla->foreign('periodo_id')->references('id')->on('periodos')->onDelete('cascade');
        });

        /*
         * Lo que ve el docente: **por asignatura y periodo**, igual que `unidades`.
         *
         * `alumno_id NULL` es el reparto del curso y se lee con `<=>` a través de
         * `BoletinIndependiente::alcance()` — la regla 5 de la §4 del doc 28, y el
         * mismo operador que `unidades`. Con `= NULL` el alumno normal se queda sin
         * desempeños **en 200 y sin dar ningún error**.
         */
        Schema::create('desempenos', function (Blueprint $tabla) {
            $tabla->increments('id');
            $tabla->unsignedInteger('asignatura_id');
            $tabla->unsignedInteger('periodo_id');
            $tabla->unsignedInteger('alumno_id')->nullable();
            $tabla->unsignedInteger('competencia_id')->nullable();
            $tabla->string('tipo', 60)->nullable();
            $tabla->text('definicion');
            $tabla->integer('orden')->default(0);

            // **El candado de la D14, y nada más que esto.** `1` = la sembró el
            // colegio; `0` = es del docente y la edita y la borra sin permiso
            // ninguno. `NOT NULL DEFAULT 0` para que una fila escrita por un
            // cliente viejo nazca **libre** y no candada: equivocarse hacia «el
            // docente puede» es una molestia; hacia «no puede» es una llamada a
            // soporte.
            $tabla->boolean('por_defecto')->default(0);

            $tabla->integer('created_by')->nullable();
            $tabla->integer('updated_by')->nullable();
            $tabla->integer('deleted_by')->nullable();
            $tabla->timestamps();
            $tabla->softDeletes();

            $tabla->index(['asignatura_id', 'periodo_id', 'alumno_id'], 'desempenos_alcance');

            $tabla->foreign('asignatura_id')->references('id')->on('asignaturas')->onDelete('cascade');
            $tabla->foreign('periodo_id')->references('id')->on('periodos')->onDelete('cascade');
        });
    }

    /*
     * Exacto: lo que había antes de `up()` era que ninguna de las dos existía. Se
     * pierden los desempeños escritos desde entonces, y **ninguna nota, ninguna
     * definitiva y ningún boletín de hoy los mira** — que es lo que hace que este
     * `down()` sea de verdad la vuelta atrás.
     *
     * El orden es el inverso porque nada apunta a nada: da igual, y por eso se
     * escribe al revés, que es la costumbre que salva el día en que sí importe.
     */
    public function down()
    {
        Schema::dropIfExists('desempenos');
        Schema::dropIfExists('desempenos_por_defecto');
    }
}
