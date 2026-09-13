<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * De qué casilla salió la frase del boletín, y **con qué nivel**.
 *
 * Es la **Fase 4** de
 * [35-el-modelo-de-evaluacion-del-colegio.md](../../docs/migracion/35-el-modelo-de-evaluacion-del-colegio.md)
 * con la **D23** encima: *«la casilla de la rejilla ES el nivel»*. Cada cruce
 * alumno × desempeño guarda el nivel de `escalas_de_valoracion` que el colegio
 * tenga puesto —Superior/Alto/Básico/Bajo, o como los llame—, premarcado con el
 * que al alumno le toca por su definitiva.
 *
 *     frases_asignatura + desempeno_id   de qué casilla salió        (D9)
 *                       + escala_id      qué nivel se le puso        (D23)
 *                       + nivel          cómo se llamaba ese nivel   (D23)
 *
 * ## Tres columnas y no una, y la tercera es la que alguien va a querer quitar
 *
 * Es el argumento de la D9 repetido un piso más arriba. `escalas_de_valoracion`
 * es **por año y editable**: `EscalasDeValoracionController` hace
 * `UPDATE … SET desempenio = :desemp … WHERE id = :id` **sobre la fila viva**, la
 * misma que un boletín de 2026 leería por su id, y `YearsController` copia las
 * escalas al año siguiente **con `new` y `save()`**, o sea con ids nuevos. Una
 * celda que guardara sólo `escala_id` dejaría que **renombrar «Básico» en 2028
 * cambiara un boletín impreso en 2026**.
 *
 * O sea exactamente el papel que `frase` ya hace frente a `frase_id` en esta misma
 * tabla: el id **pinta la casilla** y el texto **es lo que se imprime**. Lleva su
 * caso de contrato —`test_renombrar_la_escala_no_cambia_un_boletin_ya_puesto`—
 * porque sin él la tercera columna parece redundante y se va en la primera
 * limpieza.
 *
 * ## Anulables las tres, y eso no es prudencia: es lo que ya hay dentro
 *
 * `frases_asignatura` tiene hoy **12.294 filas** en `simonbolivar` (medido en la
 * Fase 0) y **ninguna** salió de una rejilla. Una columna `NOT NULL` obligaría a
 * un back-fill que se inventaría un dato: **ninguna de esas frases tuvo nunca una
 * casilla ni un nivel**. `NULL` aquí significa *«esta frase no vino de la
 * rejilla»*, que es la verdad, y es lo que separa las frases de siempre —la
 * pantalla de `frases_asignatura`, que no se toca— de las celdas de la rejilla.
 *
 * **La rejilla sólo mira las filas con `desempeno_id IS NOT NULL`.** Es lo que
 * hace que las dos pantallas puedan escribir en la misma tabla sin comerse la una
 * a la otra, y va comprobado en
 * `test_la_rejilla_no_toca_las_frases_escritas_a_mano`.
 *
 * ## Ningún dato existente se mueve, y el boletín no cambia ni un byte
 *
 * Tres `ADD COLUMN` anulables y un índice. Ni `UPDATE`, ni back-fill, ni
 * `DEFAULT` que escriba nada. `FraseAsignatura::deAlumno` —lo que leen los tres
 * boletines— **nombra sus columnas una a una** y no trae ninguna de las tres, así
 * que con la tabla migrada y la rejilla sin usar los boletines dan lo mismo que
 * hoy.
 *
 * > **Lo que sí sale por una puerta, medido y no supuesto:**
 * > `DELETE api/frases_asignatura/destroy/{id}` acaba en `return $frase;` con un
 * > modelo Eloquent, así que **su respuesta pasa a traer las tres columnas
 * > nuevas**. Es aditivo y no rompe a nadie —nadie las lee—, pero es la puerta
 * > por la que `profesores.tono` se repartió a seis respuestas vivas, así que
 * > queda escrito aquí en vez de descubrirse dentro de dos años. **No se arregla
 * > en esta fase**: nombrar las columnas de esa respuesta es cambiarle la forma a
 * > una ruta que llevan años llamando los dos fronts y Flutter, y eso es una
 * > entrega propia con su medición delante.
 *
 * ## Sin claves ajenas, por el motivo de `2026_09_13_300000`
 *
 * `desempeno_id` y `escala_id` **no llevan FK**, y es la misma decisión que
 * tomaron `competencias` y `desempenos`:
 *
 *   - `ON DELETE SET NULL` es el peor de los dos: `desempeno_id NULL` significa
 *     **«esta frase no es una celda»**, así que borrar un desempeño convertiría la
 *     celda de un alumno en una frase suelta —y el boletín seguiría imprimiéndola
 *     sin que nada dijera de dónde salió—;
 *   - `ON DELETE CASCADE` **borraría del boletín** un texto que el docente puso;
 *   - y las dos fallan donde más se usa: desempeños y escalas se borran **en
 *     lógico** en los caminos normales de esta API, y una FK **no se entera de un
 *     `UPDATE deleted_at`**.
 *
 * Que el id exista se comprueba **al escribir**, con 422 y el nombre del campo
 * delante, que es lo que hace `DesempenosController::putRejilla`.
 *
 * ## El índice, y por qué NO es el que parecía
 *
 * La rejilla pide **una asignatura y un periodo enteros**, o sea todas las celdas
 * de treinta alumnos de golpe. El índice que ya existe —
 * `frases_asignatura_alumno_asig_periodo_index (alumno_id, asignatura_id,
 * periodo_id)`, de `2026_08_20_100000`— **no le sirve a un `WHERE asignatura_id=?
 * AND periodo_id=?`**: le falta la columna de la izquierda.
 *
 * Se resuelve **sin índice nuevo y sin tocar el que hay**, acotando la consulta
 * con `alumno_id IN (…)` —los alumnos del grupo, que la rejilla ya tiene que
 * traer de todas formas—, y así el compuesto entra por su primera columna. Va
 * comprobado con `EXPLAIN` en `test_la_consulta_de_la_rejilla_no_recorre_la_tabla`,
 * que es la forma de `IndicesTest`: no se pregunta si el índice existe, se le
 * pregunta a MySQL si **para esta consulta** hay alguno aplicable.
 *
 * Lo que sí se añade es un índice sobre **`desempeno_id`**, y por una consulta
 * distinta: borrar un desempeño tiene que poder encontrar sus celdas, y esa
 * pregunta no empieza por `alumno_id` ni puede acotarse con un `IN`.
 *
 * ## El prefijo `09_13_400000`
 *
 * Detrás de `2026_09_13_300000_desempenos`, de quien cuelga `desempeno_id`. Sin FK
 * entre las dos —ver arriba— pero el orden escrito hace determinista el
 * despliegue, que es la razón de siempre.
 */
class MarcaDelDesempeno extends Migration
{
    public function up()
    {
        Schema::table('frases_asignatura', function (Blueprint $tabla) {
            // **De qué casilla salió** (D9). Sin él la rejilla no se puede pintar
            // salvo comparando cadenas de 200 caracteres, y una errata corregida
            // en el catálogo desmarcaría a treinta alumnos de golpe.
            $tabla->unsignedInteger('desempeno_id')->nullable()->after('frase');

            // **Qué nivel se le puso** (D23). Es lo que pinta la casilla al volver
            // a abrir la rejilla: el front necesita el id para saber cuál de los
            // cuatro botones va marcado.
            $tabla->unsignedInteger('escala_id')->nullable()->after('desempeno_id');

            // **Y cómo se llamaba ese nivel el día que se puso.** `varchar(255)`
            // porque es lo que mide `escalas_de_valoracion.desempenio`, de donde
            // se copia: ni más —inventaría un techo— ni menos —cortaría.
            $tabla->string('nivel', 255)->nullable()->after('escala_id');

            $tabla->index('desempeno_id', 'frases_asignatura_desempeno_index');
        });
    }

    /*
     * Se pierden las celdas de la rejilla y **no se pierde ni una frase**: el texto
     * vive en `frase`, que esta migración no toca, así que un boletín impreso
     * después de volver atrás dice exactamente lo mismo. Lo que desaparece es de
     * qué casilla salió cada una y con qué nivel — o sea que la rejilla se vuelve a
     * abrir premarcada por la nota y sin lo que el docente hubiera corregido.
     *
     * Es aditiva, así que el «Paso 4. Volver atrás» de `docs/DESPLIEGUE.md` vale
     * tal cual: el código vuelve y las columnas se quedan. Este `down()` es para
     * poder probar la migración, no para el despliegue.
     */
    public function down()
    {
        Schema::table('frases_asignatura', function (Blueprint $tabla) {
            $tabla->dropIndex('frases_asignatura_desempeno_index');
            $tabla->dropColumn(['desempeno_id', 'escala_id', 'nivel']);
        });
    }
}
