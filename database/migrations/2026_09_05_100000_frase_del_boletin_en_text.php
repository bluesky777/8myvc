<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * La frase que sale en el boletín del alumno deja de cortarse a los 255.
 *
 * Autorizado por Joseth el 2 sep 2026 —*«cambia el tipo de campo como
 * necesites»*— para que quepan los indicadores de desempeño que propone
 * `docs/migracion/28-competencias-e-indicadores.md` §5.3. La pregunta se hizo
 * mirando al futuro; la medición encontró que **el daño ya estaba hecho**.
 *
 * ## No es un riesgo, es un corte que ya ocurrió
 *
 * Contado sobre la copia de `simonbolivar` del contenedor:
 *
 * | población | valor |
 * |---|---|
 * | filas en `frases_asignatura` | **12.294** |
 * | de catálogo (`frase_id`) | 698 |
 * | **escritas a mano** | **11.596** |
 * | **con exactamente 255 caracteres** | **626 (5,4 % de las escritas a mano)** |
 *
 * **255 exactos no es una casualidad, es la firma de un truncamiento**, y se lee
 * en los datos: las frases acaban a mitad de palabra y sin punto — *«…para
 * desarrollar las competencias p»*, *«…de las funciones seno, coseno, tangente»*.
 *
 * Y no dio error a nadie: el `sql_mode` del contenedor es
 * `NO_ENGINE_SUBSTITUTION`, **sin `STRICT_TRANS_TABLES`**, así que MySQL corta y
 * devuelve 200. El docente vio su frase guardada y el acudiente la recibió
 * cortada, impresa en el boletín. Es la familia de `tools/respuestas-que-mienten.py`.
 *
 * **La población de los diecisiete NO se sabe** y no se puede saber desde aquí:
 * esto es un colegio. Se cuenta el día del despliegue con la consulta de la §1.ter
 * del 28, y el resultado se escribe con su denominador delante.
 *
 * ## Por qué `text` y no un `varchar` más ancho
 *
 * Porque es lo que ya son sus dos hermanas: `frases.frase` —el catálogo del que
 * sale `IFNULL(f.frase, fa.frase)` en `FraseAsignatura::deAlumno`— y
 * `definiciones_comportamiento.frase` son **`text`** en el volcado. Esta columna
 * se quedó en 255 sola; el `ALTER` no inventa un tipo, la devuelve a la familia.
 * Un `varchar(1000)` volvería a poner un techo que nadie ha medido, y el catálogo
 * —que no tiene techo— no pasa hoy de **102 caracteres**: el límite no lo pedía el
 * dato, lo ponía la columna.
 *
 * El `COLLATE` va escrito porque la tabla es `utf8mb4_unicode_ci` y las dos
 * hermanas son `utf8mb4_general_ci`: sin decirlo, el `MODIFY` heredaría el de la
 * tabla y la comparación de frases cambiaría de collation en silencio. Se queda el
 * de la tabla, que es el que esta columna ya tiene.
 *
 * ## Lo que esta migración NO hace, y no es un olvido
 *
 * 1. **No repara hacia atrás, y es la decisión de Joseth del 2 sep 2026.** Las 626
 *    frases cortadas **no vuelven**: el texto que el docente escribió de más nunca
 *    llegó a la base, así que no hay de dónde sacarlo. Se propuso contarlas en los
 *    diecisiete y pasarle al colegio la lista de boletines afectados; la decisión
 *    fue **arreglar sólo hacia adelante**. La consecuencia, escrita para que no
 *    haya que descubrirla dentro de dos años: **cada reimpresión de un boletín de
 *    un año pasado seguirá saliendo con la frase cortada a mitad de palabra**.
 * 2. **No toca `frases_asignatura.frase_id` ni el catálogo.** La frase de catálogo
 *    nunca se cortó: viaja por `frases.frase`, que ya es `text`.
 * 3. **No añade validación.** Un `text` de MySQL admite 65.535 bytes y nadie los va
 *    a teclear; poner un tope en PHP sería inventar un número nuevo sin medirlo.
 * 4. **No es aditiva, y eso contradice el «Paso 4. Volver atrás» de
 *    `docs/DESPLIEGUE.md`.** Ese paso deja las migraciones puestas al revertir código
 *    **porque son aditivas** —una columna de más no le estorba al código viejo—. Ésta
 *    no añade nada: cambia un tipo, y su `down()` **destruye datos** (ver abajo). Si
 *    hay que revertir el despliegue, **el código vuelve y esta migración se queda**;
 *    correrle el `down()` es una decisión aparte y con pérdida, no parte de la vuelta
 *    atrás rutinaria. Lo señaló `8myvc-d2` la noche del 2 sep 2026.
 * 5. **No enciende `STRICT_TRANS_TABLES`.** Es del servidor, no de esta API, y
 *    encenderlo convertiría en error 500 truncamientos silenciosos de otras
 *    dieciséis tablas que nadie ha contado. Queda fichado, no hecho.
 *
 * ## Lo que sí cambia
 *
 * Nada que se vea, y eso es lo que se busca: `varchar(255)` → `text` **no pierde
 * un solo dato** y no hay índice que reconstruir — `frases_asignatura` no tiene
 * más clave que la primaria (`database/schema/mysql-schema.sql`). La respuesta de
 * `frases_asignatura/show` y la del boletín siguen trayendo el mismo campo con el
 * mismo nombre; sólo dejan de perder lo que se escriba de más a partir de hoy.
 */
class FraseDelBoletinEnText extends Migration
{
    public function up()
    {
        DB::statement('ALTER TABLE frases_asignatura MODIFY frase TEXT COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL');
    }

    public function down()
    {
        // **Vuelve a cortar, y esta vez de verdad.** Un `MODIFY` a `varchar(255)`
        // sobre filas más largas trunca sin avisar con el `sql_mode` de estos
        // servidores. Quien la corra en un colegio ya desplegado pierde el texto
        // que se haya escrito de más desde el `up()`, y no hay forma de deshacerlo
        // otra vez. Está aquí porque una migración sin `down()` no se puede probar.
        DB::statement('ALTER TABLE frases_asignatura MODIFY frase VARCHAR(255) COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL');
    }
}
