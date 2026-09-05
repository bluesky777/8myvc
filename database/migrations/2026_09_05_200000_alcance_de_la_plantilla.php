<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * El alcance de la plantilla de notas: a qué nivel y a qué materia se siembra
 * cada fila.
 *
 * Es la **decisión 8** de Joseth (2 sep 2026) y su diseño está en la §5.7.a de
 * [28-competencias-e-indicadores.md](../../docs/migracion/28-competencias-e-indicadores.md).
 * Se decidió sobre una medición y no sobre una intuición: en preescolar **803 de
 * 1.169 subunidades repiten literalmente el texto de su unidad**, contra 76 de
 * 31.873 en el resto del colegio. Esa docente no está usando otro modelo — está
 * pagando un peaje, porque la nota no puede colgar de la unidad y tiene que
 * teclear el mismo logro dos veces. Lo que necesita es **una plantilla de una
 * fila**, y para que esa fila llegue sólo a preescolar hace falta decir a quién
 * va dirigida.
 *
 * ## NULL es «a todos», y por eso los dieciséis colegios no se enteran
 *
 * Las dos columnas nacen `NULL` en toda fila existente, y `NULL` significa **«sin
 * restricción»**: la consulta del sembrador pasa a
 *
 *     WHERE year_id = ?
 *       AND (nivel_educativo_id IS NULL OR nivel_educativo_id = ?)
 *       AND (materia_id        IS NULL OR materia_id        = ?)
 *
 * que **selecciona exactamente las mismas filas que hoy** hasta que alguien use la
 * pantalla nueva. Eso es lo que hace que esta migración sea aditiva de verdad y no
 * sólo de nombre, y hay un test de contrato que lo fija: con las dos a NULL, el
 * sembrador da la misma respuesta que antes de migrar.
 *
 * ## Sin claves foráneas, y NO por descuido
 *
 * `unidades_por_defecto` sí tiene FK en `year_id`, así que la ausencia aquí pide
 * explicación. Las tres salidas se miraron y **las tres pierden**:
 *
 *   - `ON DELETE SET NULL` es **el peor de los tres**, y parece el más suave: el
 *     día que un colegio borre un nivel educativo, la fila de preescolar pasaría a
 *     valer `NULL` — o sea **«a todos los niveles»**— y la plantilla de una fila se
 *     sembraría en el bachillerato entero. Es exactamente la fuga de alcance que
 *     esta misma tanda arregla en `YearsController`, entrando por la otra puerta.
 *   - `ON DELETE CASCADE` borraría en silencio la configuración que el colegio
 *     escribió, y borrar la plantilla de preescolar porque alguien reorganizó el
 *     catálogo de niveles no es una consecuencia que nadie haya pedido.
 *   - Sin FK, una fila puede quedar apuntando a un nivel que ya no existe. **Ésa
 *     es la que se eligió**, porque su fallo es el único que no hace daño: esa
 *     fila deja de casar con ninguna asignatura y **no se siembra en ninguna
 *     parte**. Se queda quieta y visible en la pantalla, que es donde el colegio
 *     puede arreglarla.
 *
 * Que el id exista se comprueba **al escribir**, en `PlantillaNotasController`,
 * con 422 y el nombre del campo delante. Es la misma regla de siempre: la
 * comprobación va donde se puede contestar, no donde revienta.
 *
 * ## Sin `after`, por lo mismo que `tono_del_docente`
 *
 * `ADD COLUMN … AFTER x` ata esta migración al orden de su tanda y **falla con
 * columna desconocida** si se aplica fuera de él. El sitio de una columna en la
 * tabla no lo lee nadie; el orden de despliegue sí duele. Van al final.
 *
 * ## Lo que NO se reparte solo, comprobado
 *
 * `UnidadesController:148` lee esta tabla con `SELECT *` —está avisado en la
 * cabecera de aquel fichero— pero **no la devuelve**: las filas se usan para
 * construir los `INSERT` de `unidades`, que nombran sus columnas una a una. O sea
 * que estas dos columnas **no aparecen en ninguna respuesta viva** por sí solas.
 * Quien las enseña es la pantalla nueva (`GET plantilla-notas`), que las nombra a
 * propósito.
 */
class AlcanceDeLaPlantilla extends Migration
{
    public function up()
    {
        Schema::table('unidades_por_defecto', function (Blueprint $tabla) {
            // Anulables y sin defecto explícito: `NULL` ya es el defecto de una
            // columna anulable, y escribirlo como `default(null)` sugeriría que el
            // nulo es un valor elegido y no la ausencia de restricción.
            $tabla->integer('nivel_educativo_id')->nullable();
            $tabla->integer('materia_id')->nullable();

            // El sembrador filtra por las tres a la vez y `year_id` ya tiene índice
            // propio (`unidades_por_defecto_year_id_foreign`), que es el que manda
            // aquí: la tabla es de decenas de filas por año, no de miles. No se
            // crea un índice compuesto porque no hay `EXPLAIN` que lo pida — la
            // regla de CLAUDE.md, y medir sobre una tabla vacía en los dieciséis
            // colegios no mediría nada.
        });
    }

    public function down()
    {
        Schema::table('unidades_por_defecto', function (Blueprint $tabla) {
            $tabla->dropColumn(['nivel_educativo_id', 'materia_id']);
        });
    }
}
