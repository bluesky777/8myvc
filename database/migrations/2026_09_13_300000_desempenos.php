<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * El plan de área del colegio: **una tabla, plana y por año**.
 *
 * Es la Fase 3 de
 * [35-el-modelo-de-evaluacion-del-colegio.md](../../docs/migracion/35-el-modelo-de-evaluacion-del-colegio.md)
 * **reescrita por el modelo plano** de
 * [39](../../docs/migracion/39-el-modelo-plano-por-competencias.md), que es lo que
 * el colegio pidió al mirar el resultado: **D31** —el colegio y el docente escriben
 * las mismas filas físicas— y **P1.bis** —el nivel se deriva de la definitiva, no se
 * congela—.
 *
 *     desempenos_por_defecto      el plan de área: un texto por materia + grado + periodo
 *
 * ## Qué se cayó de la versión del 13 sep, y por qué esta migración se EDITÓ
 *
 * Nació con **dos** `Schema::create` —ésta y `desempenos`, la copia por asignatura—
 * y con una columna `competencia_id`. Las dos cosas eran la misma idea: *sembrar*
 * el plan de área en cada asignatura y colgarlo de una competencia padre. D31 la
 * quitó entera:
 *
 *   - **la copia**, porque si el colegio y el docente escriben las mismas filas, no
 *     hay a dónde sembrar — el boletín lee el catálogo vivo;
 *   - **`competencia_id`**, porque el modelo es plano (H3, P3): no hay cabecera de
 *     competencia en el boletín, así que un padre que nadie imprime es una columna
 *     que sólo puede desincronizarse.
 *
 * Y se **edita** en vez de borrarse y escribirse otra —que es lo que decía el plan—
 * porque esta migración **crea la única tabla del modelo nuevo**: borrarla entera
 * borraría lo que se queda. Se puede editar en sitio por una premisa que hay que
 * volver a comprobar el día que se ejecute: **ningún colegio la tiene desplegada**
 * (§7 de [39](../../docs/migracion/39-el-modelo-plano-por-competencias.md)), así que
 * no existe un solo servidor donde la versión vieja haya corrido y esta versión
 * tenga que alcanzarla.
 *
 * ## Ningún dato existente se toca
 *
 * Un `CREATE TABLE` y nada más. Ni `ALTER`, ni `UPDATE`, ni back-fill. **Con la
 * tabla vacía, la planilla y los boletines dan byte a byte lo de hoy**, porque nadie
 * la lee todavía. Volver atrás es borrarla.
 *
 * ## `definicion` es `text`, y aquí es donde de verdad importa
 *
 * Éste es el texto que **acaba impreso en el boletín**: es el que la Fase 0
 * persiguió en `frases_asignatura` —626 frases cortadas a mitad de palabra y ya
 * entregadas a las familias (doc 28 §1.ter)—. Nacer `varchar(255)` aquí sería
 * repetir ese trabajo dentro de dos años, y MySQL **no está en modo estricto** en
 * este contenedor: el corte llega con un 200 y sin una línea en el log.
 *
 * ## No hay columna de dueño, y no es un olvido
 *
 * **D31**: el colegio y el docente escriben **las mismas filas físicas**. Una
 * columna que distinguiera «ésta es del colegio» sería exactamente el candado que
 * esa decisión quita — y el `por_defecto` que tenía la tabla borrada. Quién escribió
 * cada fila se sigue sabiendo por `created_by` y por `auditoria`.
 *
 * Lo que sí gobierna quién puede escribir es `Autoriza::puedeEscribirDesempenos`, y
 * **el alcance vive en `grado_id`**: `NULL` es «todos los grados», o sea una fila que
 * alcanza a grados que el docente no da, y por eso ésa **es sólo del colegio** (§3
 * del 39).
 *
 * ## `tipo` nace vacía y es del colegio (D7)
 *
 * Diez de los catorce SIEE clasifican el texto —saber/hacer/ser,
 * cognitivo/procedimental/actitudinal, conceptual…— y **cada colegio le pone otras
 * palabras**. Por eso es un `varchar` anulable y **no** un
 * `enum('saber','hacer','ser')`: este sistema ya gastó seis columnas de
 * `displayname` en no hacer eso. El que no la quiera **no la ve**.
 *
 * ## Las claves foráneas: dos, y las mismas razones de siempre
 *
 * Llevan FK **lo que no puede significar nada estando a NULL y cuyo borrado físico
 * deja basura de verdad**: `year_id` y `periodo_id`.
 *
 * **`materia_id` y `grado_id` NO la llevan**, y por el argumento de
 * `2026_09_05_200000_alcance_de_la_plantilla`:
 *
 *   - `ON DELETE SET NULL` es el peor: `grado_id NULL` significa **«para todos los
 *     grados»**, así que borrar un grado convertiría el plan de área de 6.º en el del
 *     colegio entero.
 *   - `ON DELETE CASCADE` borraría en silencio lo que el colegio escribió.
 *   - **Y las dos fallan donde más se usa**: materias y grados se borran **en lógico**
 *     en los caminos normales de esta API, y una FK **no se entera de un
 *     `UPDATE deleted_at`**. Daría integridad en el caso raro y ninguna en el frecuente.
 *
 * Sin FK, un desempeño huérfano **se ve y se arregla** en la pantalla en vez de
 * desaparecer o de cambiar de alcance solo. Que el id exista se comprueba **al
 * escribir**, con 422 y el nombre del campo delante.
 */
class Desempenos extends Migration
{
    public function up()
    {
        /*
         * El plan de área: **por año, materia, grado y PERIODO**.
         *
         * El periodo es D5: *«sin periodo, el boletín de periodo acabaría imprimiendo
         * los mismos textos cuatro veces»*. Es también el grano del Decreto 230 art. 3
         * c) —«al finalizar cada uno de los períodos del año escolar, en cada área y
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
    }

    /*
     * Exacto: lo que había antes de `up()` era que no existía. Se pierde el plan de
     * área escrito desde entonces, y **ninguna nota, ninguna definitiva y ningún
     * boletín de hoy lo mira** — que es lo que hace que este `down()` sea de verdad
     * la vuelta atrás.
     */
    public function down()
    {
        Schema::dropIfExists('desempenos_por_defecto');
    }
}
