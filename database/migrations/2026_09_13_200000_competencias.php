<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Las competencias del colegio: **una tabla nueva y nada más**.
 *
 * Es la **Fase 2** de
 * [35-el-modelo-de-evaluacion-del-colegio.md](../../docs/migracion/35-el-modelo-de-evaluacion-del-colegio.md),
 * que sale de la **D10** de `myvc_front/DECISIONES-MODELO-DE-EVALUACION.md`: la
 * competencia es un **padre opcional**, sin nota y sin porcentaje. Sus hijos son
 * los desempeños, que la apuntarán con `competencia_id` desde la Fase 3.
 *
 * ## Ningún dato existente se toca, y eso es lo que hace la fase desplegable sola
 *
 * Un `CREATE TABLE` y nada más: ni `ALTER`, ni `UPDATE`, ni back-fill. **Con la
 * tabla vacía, los tres boletines, las definitivas y la planilla dan byte a byte
 * lo de hoy**, porque nadie la lee todavía. Volver atrás es borrarla.
 *
 * ## `definicion` es `text` desde el primer día, y no es un capricho
 *
 * `unidades_por_defecto.definicion` es `varchar(255)` y MySQL aquí **no está en
 * modo estricto**: un texto más largo entra recortado y devuelve 200. Eso es lo
 * que la Fase 0 acabó de arreglar en `frases_asignatura` —626 frases cortadas a
 * mitad de palabra y ya impresas en boletines (doc 28 §1.ter)— y lo que aquí se
 * evita **antes** de tener una sola fila: **el enunciado más largo de los
 * Estándares Básicos del MEN que esta tanda empaqueta pasa de 255 caracteres**,
 * así que un `varchar(255)` habría cortado el catálogo el día de la primera
 * adopción. Es el mismo `ALTER` de la Fase 0, hecho a tiempo y gratis.
 *
 * ## Las tres columnas de dirección, y por qué NO llevan clave foránea
 *
 * `materia_id`, `grado_id` y `alumno_id` dicen **a quién va dirigida** la
 * competencia, igual que `unidades_por_defecto.nivel_educativo_id` y
 * `materia_id` dicen a quién va dirigida una fila de plantilla. La ausencia de
 * FK es **la misma decisión** de `2026_09_05_200000_alcance_de_la_plantilla`, y
 * se repite aquí porque el argumento es idéntico y porque un lector que vea la
 * FK de `year_id` va a preguntar por las otras tres:
 *
 *   - `ON DELETE SET NULL` es **el peor**, y parece el más suave: `grado_id NULL`
 *     significa **«para todos los grados»**, así que borrar el grado 6.º
 *     convertiría las competencias de 6.º en competencias **del colegio entero**.
 *     Es la fuga de alcance por la otra puerta.
 *   - `ON DELETE CASCADE` borraría en silencio el plan de área que el colegio
 *     escribió porque alguien reorganizó el catálogo de materias.
 *   - **Y las dos fallan donde más se usa**: `materias`, `grados` y `alumnos` se
 *     borran con **borrado lógico** en los caminos normales de esta API, y una FK
 *     **no se entera de un `UPDATE deleted_at`**. O sea que la FK daría sensación
 *     de integridad en el caso raro (el `forceDelete`) y ninguna en el frecuente.
 *
 * Sin FK, una competencia puede quedar apuntando a una materia que ya no existe:
 * **deja de casar con ninguna asignatura, no se imprime en ninguna parte y se
 * queda quieta y visible en `GET competencias`**, que es donde el colegio puede
 * arreglarla. Que el id exista se comprueba **al escribir**, en
 * `CompetenciasController`, con 422 y el nombre del campo delante.
 *
 * `year_id` sí la lleva, y por lo mismo que `unidades_por_defecto`: un año
 * borrado de verdad se lleva 59 tablas por delante y ésta es una más, sin
 * ninguna de las tres pegas de arriba — no hay «año NULL» que signifique nada.
 *
 * ## `codigo_men`: procedencia, NO enlace — y es lo único que este fichero
 * añade a la lista de columnas del plan
 *
 * El plan de la Fase 2 lista `id, year_id, materia_id, grado_id, alumno_id,
 * definicion, orden` y las seis de auditoría. Aquí hay **una más**, anulable y
 * sin FK a ninguna parte, y el motivo es un test que el propio plan pide:
 * **«adoptar dos veces no duplica»**.
 *
 * Sin ella, la única forma de saber si un enunciado del MEN ya se adoptó es
 * **comparar cadenas de 300 caracteres** — y D11 dice que *«lo adoptado es
 * suyo»*, o sea que el colegio lo va a editar. El día que alguien corrija una
 * tilde, la comparación deja de casar y la segunda adopción **duplica las
 * veinticinco competencias del área**. Es exactamente el argumento de D9 para
 * `frases_asignatura.desempeno_id`: *«sin él, la rejilla no se puede pintar salvo
 * comparando cadenas de 200 caracteres, y una errata corregida en el catálogo
 * desmarcaría a treinta alumnos»*.
 *
 * **Y no rompe «adoptar copia, el catálogo no manda»** (D11, regla 1 de la §4 del
 * doc 28): `definicion` se copia entera y **nadie vuelve a leer el catálogo para
 * imprimir**. `codigo_men` sirve para **una sola cosa** —saber que esa fila nació
 * de tal enunciado del MEN— y si el fichero de datos cambiara el texto mañana, la
 * fila del colegio **no se entera**. Es procedencia, no dependencia. Si algún día
 * alguien la usa para releer el catálogo al imprimir, es entonces cuando se rompe
 * la regla, y este párrafo es la advertencia.
 *
 * ## El índice es el del plan, y se usa entero
 *
 * `(year_id, materia_id, grado_id, alumno_id)` en ese orden porque es el orden en
 * que se filtra: siempre el año, casi siempre la materia, después el grado y al
 * final el alumno. La lectura del boletín es `year_id = ? AND materia_id = ? AND
 * grado_id <=> ? AND alumno_id <=> ?`, que lo recorre entero.
 *
 * ## El prefijo `09_13_200000`
 *
 * La Fase 1 lleva `2026_09_13_100000` (`years.modelo_evaluacion`) y ésta la
 * sigue. **No dependen la una de la otra** —esta tabla no mira ninguna columna de
 * `years` salvo la clave— pero el orden escrito hace determinista el despliegue,
 * que es la razón de siempre (rúbricas, tareas §4.1).
 */
class Competencias extends Migration
{
    public function up()
    {
        Schema::create('competencias', function (Blueprint $tabla) {
            $tabla->increments('id');
            $tabla->unsignedInteger('year_id');

            // La materia es obligatoria: una competencia siempre es de un área.
            // El grado y el alumno son la dirección fina, y `NULL` es «sin
            // restricción» en los dos — «para todos los grados» y «para todo el
            // grupo», que es lo mismo que significa en `unidades`.
            $tabla->unsignedInteger('materia_id');
            $tabla->unsignedInteger('grado_id')->nullable();
            $tabla->unsignedInteger('alumno_id')->nullable();

            $tabla->text('definicion');
            $tabla->integer('orden')->default(0);

            // Anulable y corto: es `LEN-1-3-PT-1`, no un texto. Nace `NULL` en
            // todo lo que escriba el colegio a mano.
            $tabla->string('codigo_men', 40)->nullable();

            $tabla->integer('created_by')->nullable();
            $tabla->integer('updated_by')->nullable();
            $tabla->integer('deleted_by')->nullable();
            $tabla->timestamps();
            $tabla->softDeletes();

            $tabla->index(
                ['year_id', 'materia_id', 'grado_id', 'alumno_id'],
                'competencias_alcance'
            );

            $tabla->foreign('year_id')->references('id')->on('years')->onDelete('cascade');
        });
    }

    /*
     * Exacto: lo que había antes de `up()` era que esta tabla no existía. Lo
     * único que se pierde son las competencias escritas desde entonces, y
     * ninguna nota, ninguna definitiva y ningún boletín las mira — que es lo que
     * hace que este `down()` sea de verdad la vuelta atrás y no un apaño.
     */
    public function down()
    {
        Schema::dropIfExists('competencias');
    }
}
