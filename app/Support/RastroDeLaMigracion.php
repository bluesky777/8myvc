<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * El rastro que deja una migración que va a pisar datos.
 *
 * **Decisión 7 de `docs/migracion/18-auditoria.md`, del 21 sep 2026.** Nació de una
 * nota que no se podía recuperar: `2026_09_19_500000_la_casilla_vacia` vació 20.655
 * filas y su `down()` dice por escrito que no las devuelve. Se recuperaron —el
 * `WHERE` sólo tocó filas que nadie había editado, así que valían el `nota_default`
 * de su subunidad—, pero eso fue **suerte, no diseño**.
 *
 * ## Por qué hace falta un sitio para esto, si ya existe `App\Services\Auditoria`
 *
 * Porque **una migración no pasa por la aplicación**. `Auditoria` vive dentro de una
 * petición: resuelve al actor del token, la sesión, la IP y la ruta, y sin request no
 * tiene ninguna de esas cosas. Una migración es SQL contra la base. Si el rastro no lo
 * escribe la migración, no lo escribe nadie — y ése es justo el momento en que más
 * falta hace, porque es cuando se pisan miles de filas de una vez.
 *
 * Joseth descartó la alternativa el 21 sep, y tenía razón: una tabla de respaldo aparte
 * serían **dos mecanismos y el mismo dato dos veces**. La fila de historia ya es el
 * respaldo. Esto sólo la escribe donde el servicio no llega.
 *
 * ## Es UNA sentencia, no un bucle
 *
 * `INSERT ... SELECT` con el mismo `FROM` y el mismo `WHERE` que el `UPDATE` que viene
 * detrás, corrido **antes**. Veinte mil filas cuestan una ida y vuelta, no veinte mil.
 * Un bucle aquí convertiría una migración de dos segundos en una de diez minutos × 17
 * colegios, y la regla se dejaría de cumplir a la tercera.
 *
 * ## Tres cosas que este escritor hace distinto del servicio, a propósito
 *
 * 1. **`actor_tipo = 'sistema'`** y `actor_user_id` nulo. No hay persona detrás: quien
 *    lanza el despliegue no es quien decidió el cambio, y ponerlo mentiría sobre quién
 *    responde de esas filas.
 * 2. **`atribucion = 'migracion'`**, un tercer valor además de `'sesion'` y
 *    `'aproximada'`. Los dos existentes describen *cómo se supo de qué ingreso salió
 *    esto*, y aquí **no hay ingreso**: `sesion_id` e `historial_id` van nulos. Poner
 *    `'sesion'` sería falso y `'aproximada'` diría que se adivinó algo que nadie
 *    intentó. La pantalla lo pinta como lo que es: lo cambió el despliegue.
 * 3. **Sólo columnas numéricas.** `valor_anterior` es `json` y en MariaDB 10.5 eso es
 *    `LONGTEXT` con un `CHECK`: un número suelto es JSON válido en los dos motores, una
 *    cadena sin comillas no lo es en MySQL 8. Antes que generar comillas a mano en SQL
 *    —que es de donde salen los `->>` que pasan en el docker y revientan en los
 *    dieciséis—, esto se limita a lo que se sabe escribir bien, y una migración que
 *    pise texto escribe su propio `INSERT` y lo dice.
 */
class RastroDeLaMigracion
{
    /**
     * Anota, antes de pisarlas, las filas que un `UPDATE` de migración va a cambiar.
     *
     * `$filas` es un `SELECT` que devuelve **exactamente tres alias**: `entidad_id`,
     * `valor` (numérico, el de ANTES) y `alumno_id` (puede ser `NULL`). Se le pasa el
     * mismo `FROM` y el mismo `WHERE` del `UPDATE`, sin inventar un criterio paralelo:
     * dos criterios que quieren decir lo mismo acaban diciendo cosas distintas, y aquí
     * eso significa anotar filas que no se tocaron y callar las que sí.
     *
     * Devuelve cuántas líneas escribió, **para imprimirlo**: una migración que corre a
     * las tres de la mañana en diecisiete colegios y dice «migrated» a secas no
     * distingue «no había ninguna» de «no anotó nada».
     *
     * @param  string  $entidad  del vocabulario cerrado de `App\Services\Auditoria::ENTIDADES`
     * @param  string  $filas  SELECT con los alias `entidad_id`, `valor` y `alumno_id`
     * @param  array<int, mixed>  $parametros  los del SELECT, en su orden
     * @param  string  $resumen  la frase que leerá una persona: «migración: la casilla vacía»
     * @param  string  $migracion  el nombre del fichero, que va a `ruta`
     */
    public static function anotar(
        string $entidad,
        string $filas,
        array $parametros,
        string $resumen,
        string $migracion,
    ): int {
        // El orden de los parámetros no es cosmético: los del SELECT van DENTRO de la
        // subconsulta, así que se ligan después de los de las columnas constantes.
        return DB::affectingStatement(
            'INSERT INTO auditoria
                (accion, entidad, entidad_id, actor_tipo, alumno_id,
                 valor_anterior, valor_anterior_num, resumen, ruta, atribucion, ocurrido_en)
             SELECT ?, ?, f.entidad_id, ?, f.alumno_id,
                    CAST(f.valor AS CHAR), f.valor, ?, ?, ?, ?
               FROM ('.$filas.') f',
            array_merge(
                ['editar', $entidad, 'sistema', $resumen, $migracion, 'migracion', Reloj::ahoraTexto()],
                $parametros
            )
        );
    }
}
