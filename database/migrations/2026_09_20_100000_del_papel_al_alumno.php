<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * **Del papel al alumno**: lo que le faltaba al formulario de inscripción para que
 * el código hiciera lo que Joseth pidió el 19 sep 2026 — *«se queda con ese alumno
 * al que inscriban para matricular»*.
 *
 * Autorizado por Joseth el 20 sep 2026 con el alcance y el precio delante. El
 * contrato y los porqués están en
 * `docs/migracion/41-el-formulario-de-inscripcion.md` §9.
 *
 * ## EL HUECO QUE ESTO TAPA, MEDIDO ANTES DE ESCRIBIR NADA
 *
 * `ordenes_inscripcion` se acuña, se imprime y se cobra, y **`alumno_id` sólo se
 * escribe al acuñar una renovación**. Los únicos `UPDATE ordenes_inscripcion` de
 * todo `app/` ponen `estado="PAGADA"` (la colilla aprobada y el webhook), así que:
 *
 *   - un formulario del modo `nuevos` —el del aspirante, que es el caso principal—
 *     nace con `alumno_id` NULL y **no se ata a nadie jamás**;
 *   - `matricula_id` **no lo escribe nadie**, aunque su comentario diga «cuando el
 *     aspirante se convierte en alumno, el código se queda pegado»;
 *   - `estado = 'MATRICULADA'` tampoco, y `PagosInscripcionController` ya lo
 *     nombra en `YA_NO_SE_COBRA`.
 *
 * Es `profesores.tono` **por cuarta vez en un mes**, y otra vez no lo destapó un
 * barrido: lo destapó que la función siguiente necesitaba el dato.
 *
 * ## `codigo_anterior`: el papel viejo no se queda huérfano
 *
 * Joseth pidió (20 sep) que el código lo **acuñe siempre la API** y que secretaría
 * pueda **corregirlo después**, «con herramientas para que no repita código». Las
 * herramientas son tres y ninguna es nueva: el carácter de control de
 * `CodigoDeInscripcion`, el `UNIQUE (codigo)` de esta tabla, y que **el carácter de
 * control lo calcula la API** — quien corrige teclea los cinco caracteres, no el
 * código entero, así que no puede escribir uno que no valide.
 *
 * Lo que sí es nuevo es esta columna, y es la que hace que corregir sea seguro:
 * **el código viejo está impreso en un papel que está en casa de una familia**.
 * Sin guardarlo, cambiarlo convierte ese papel en basura silenciosa — quien lo
 * teclee recibe «ese código no existe» y nadie puede saber que existió. Con ella,
 * el código viejo **sigue encontrando su orden** y la pantalla puede decir «este
 * formulario ahora es el 2027-XXXXX».
 *
 * Guarda **uno** y no un historial: lo que hace falta es que el papel que circula
 * encuentre su fila, y el papel que circula es el anterior. Un historial entero
 * sería una tabla, y nadie ha pedido auditar correcciones de código.
 *
 * ## El índice de `matricula_id` es para ir al revés
 *
 * El requisito literal es *«siempre se puede ir del papel a la matrícula y al
 * revés»*. De la orden a la matrícula se va por la columna; **al revés no se podía
 * ir sin recorrer la tabla entera**, y esa pregunta —«¿de qué formulario salió esta
 * matrícula?»— es la que contesta el informe de campaña.
 */
return new class extends Migration
{
    public function up()
    {
        Schema::table('ordenes_inscripcion', function (Blueprint $tabla) {
            // El código que llevaba impreso el papel antes de que secretaría lo
            // corrigiera. Mismo largo que `codigo`, y **sin `UNIQUE`**: dos
            // correcciones distintas pueden dejar aquí el mismo valor sólo si el
            // `UNIQUE` de `codigo` dejó repetirlo, que no puede — pero el índice
            // que hace falta es de búsqueda, no de unicidad, y exigir unicidad
            // aquí haría fallar una corrección legítima el día que se liberara un
            // código.
            $tabla->string('codigo_anterior', 20)->nullable()->after('codigo');

            $tabla->index('codigo_anterior', 'ordenes_inscripcion_codigo_anterior');
            $tabla->index('matricula_id', 'ordenes_inscripcion_matricula');
        });
    }

    public function down()
    {
        Schema::table('ordenes_inscripcion', function (Blueprint $tabla) {
            $tabla->dropIndex('ordenes_inscripcion_matricula');
            $tabla->dropIndex('ordenes_inscripcion_codigo_anterior');
            $tabla->dropColumn('codigo_anterior');
        });
    }
};
