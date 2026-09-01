<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * **La fila que un `UPDATE` va a escribir existe, o la petición corta con 404.**
 *
 * Es la **opción A** de [09 §13](../../docs/migracion/09-pendientes.md), decidida por
 * Joseth el 1 sep 2026 con la medición del lote F delante
 * ([f.md §9](../../docs/migracion/noche-2026-08-31/f.md)).
 *
 * ## El problema que resuelve, en una frase
 *
 * > `DB::update` devuelve **filas afectadas**, y MySQL da **0 cuando el UPDATE no cambia
 * > ningún valor** — no cuando no encuentra la fila.
 *
 * Así que `if ($res) return 'Guardado'; else return 'No guardado';` **junta dos cosas que
 * no tienen nada que ver**: «el valor ya era ése» y «esa fila no existe». Guardar dos
 * veces lo mismo contestaba «No guardado» con 200 **y el estado correcto**.
 *
 * Un fallo real de la base **nunca** llegaba por ahí: lanza excepción y sale 500. O sea
 * que esa palabra jamás significó «falló», y por eso desaparece: preguntando antes, las
 * dos ramas quedan separadas de verdad.
 *
 * | Caso | Antes | Ahora |
 * |---|---|---|
 * | la fila existe y el valor cambia | 200 `'Guardado'` | **igual** |
 * | la fila existe y el valor ya era ése | 200 `'No guardado'` | **200 `'Guardado'`** |
 * | la fila no existe | 200 `'No guardado'` | **404** |
 * | la base falla | 500 | **igual** |
 *
 * ## Por qué esto es una clase y no tres `if` copiados
 *
 * Porque lo que no puede copiarse es **la decisión**, no el `SELECT`. Son siete ramas en
 * tres métodos, y este repo ya tiene el ejemplo de lo que pasa cuando una decisión se
 * escribe siete veces: las definitivas con sus seis escritores y cinco criterios. Mismo
 * trato que `ColumnaSegura`, que es la clase de al lado y guarda una decisión de una
 * línea por la misma razón.
 *
 * ## Lo que este cambio le hace al front, medido y no supuesto
 *
 * **`app2` lee esa palabra en 18 ficheros a propósito**, por
 * `comunes/guardado-de-campo.ts`, y **sabe que no es un fallo**: su propio docblock
 * explica `DB::update` y las filas afectadas. La convierte en rechazo **queriendo**, para
 * que **la celda de la rejilla vuelva atrás** —*«un 200 que no guardó nada no puede
 * quedarse pintado como si hubiera guardado»*— con un mensaje que junta los dos casos
 * **porque el backend no los distinguía**.
 *
 * **El front no dejó de arreglar esto: construyó lo mejor que un backend ambiguo
 * permite.** Que nadie «limpie» ese fichero creyendo que es un parche. Con esto puesto,
 * `app2` mejora **sin tocar nada**: deja de revertir la celda cuando el valor ya era ése
 * y **sigue revirtiéndola** ante un 404 de verdad. Retirar su interpretación de la
 * palabra es la **opción B**, es del front, y va después.
 *
 * La app vieja (AngularJS) **no mira el cuerpo**: para ella no cambia nada.
 *
 * ## El 404 y no el 400 que había en una de las ramas
 *
 * La rama de `matriculas` de `GuardarAlumno::valor` ya distinguía el caso, pero con
 * **400** (§9.5, commit `74c7025`). Se unifica a 404 porque **una misma ruta contestando
 * dos códigos distintos para la misma condición es peor que cualquiera de los dos**: el
 * cliente tendría que aprenderse cuál toca según la propiedad que mande. Es contrato y
 * va anotado como tal.
 */
final class FilaQueSeVaAEscribir
{
    /**
     * Corta con 404 si no hay una fila con ese id.
     *
     * `$tabla` y `$columna` son **literales del código que llama**, nunca datos de la
     * petición: aquí no hay nada que validar porque no entra nada de fuera. Lo que sí
     * entra de fuera es `$valor`, y va ligado.
     *
     * **No filtra `deleted_at`**, y es a propósito: estos `UPDATE` tampoco lo filtran, y
     * la pregunta que hay que contestar es *«¿va a escribir esta consulta en algo?»*, no
     * *«¿está viva la fila?»*. Filtrar aquí y no allí daría un 404 sobre una fila que el
     * `UPDATE` sí habría tocado — un error nuevo, distinto del que se está arreglando.
     * Quién es «la fila» cuando hay varias es otra pregunta, y la contesta
     * `Matricula::laDelAnio()` para el único caso donde se planteaba.
     */
    public static function exigir(string $tabla, string $columna, $valor, string $queEs): void
    {
        $fila = DB::selectOne('SELECT 1 AS hay FROM `'.$tabla.'` WHERE `'.$columna.'` = ? LIMIT 1', [$valor]);

        if ($fila === null) {
            abort(404, $queEs.' no existe.');
        }
    }
}
