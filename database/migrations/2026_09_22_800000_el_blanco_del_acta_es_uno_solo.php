<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * **El voto en blanco de un acta es UNO, y ahora lo dice la base.**
 *
 * Cierra el agujero que `2026_09_22_700000_las_actas_de_papel` dejó anotado en su
 * cabecera y en el docblock de `VtActaVoto`, con estas palabras:
 *
 * > *«En MySQL y en MariaDB, dos `NULL` no son iguales, así que un índice único no
 * > impide dos filas de voto en blanco para el mismo cargo del mismo acta. Los
 * > candidatos con nombre sí quedan cerrados; el blanco no.»*
 *
 * Quedó anotado *«para que se decida, no para que se descubra»*. Esto es la
 * decisión.
 *
 * ## POR QUÉ NO PODÍA QUEDARSE EN EL CONTROLADOR
 *
 * El 700000 dice que la regla *«hoy lo sostiene el controlador y no la base»*, y
 * ésa es exactamente la frase que esta serie lleva un mes borrando: es la misma
 * forma que tenía `VtVoto::verificarNoVoto()` antes de
 * `2026_09_22_600000_el_voto_que_no_se_reemplaza` — una regla sostenida por PHP
 * es una carrera entre dos peticiones, y aquí el módulo tiene **dos puertas de
 * escritura** (guardar el borrador y volver a guardarlo).
 *
 * Y el síntoma es el peor de todos: **el recuento suma las dos filas y nadie ve
 * nada raro**. Un acta con «blanco 3» y «blanco 3» declara seis blancos que no
 * existieron; no hay error, no hay log, y el número sale impreso en el
 * escrutinio. Reproducido en el docker el 22 sep 2026 sobre el acta 1 de la
 * elección de ensayo 901: las dos filas entran y `SUM(cantidad)` dice 6.
 *
 * ## LA COLUMNA GENERADA
 *
 * `candidato_o_blanco` es `COALESCE(candidato_id, 0)`. El `0` **no es un
 * candidato** y no se guarda como tal en `candidato_id`: vive sólo en la columna
 * generada, que es lo que la 700000 pedía evitar —*«mete en la tabla un valor que
 * no es un candidato»*— y aquí no pasa, porque nadie escribe en ella y la clave
 * ajena sigue colgando de `candidato_id`, que sigue siendo `NULL` para el blanco.
 * La convención del módulo no se toca: `candidato_id IS NULL` es el voto en
 * blanco, en `vt_votos` y aquí.
 *
 * ## `VIRTUAL` Y NO `STORED`, y no fue una preferencia: `STORED` no se puede
 *
 * Se escribió primero con `STORED` —que es lo portable entre MySQL 8 y MariaDB,
 * donde es sinónimo de `PERSISTENT` desde la 10.2— y el MySQL 8 del docker la
 * rechazó de plano el 22 sep 2026:
 *
 *     1215 Cannot add foreign key constraint
 *     (alter table `vt_acta_votos` add `candidato_o_blanco`
 *      int unsigned as (COALESCE(candidato_id, 0)) stored)
 *
 * El mensaje señala a otro sitio, y eso es lo que hay que anotar para el que
 * vuelva: **no falta ninguna clave ajena**. La regla de MySQL es que una clave
 * ajena sobre la **columna base** de una generada `STORED` no puede llevar
 * `CASCADE`, `SET NULL` ni `SET DEFAULT`, y `vt_acta_votos.candidato_id` tiene
 * `ON DELETE CASCADE` desde la 700000. Con `VIRTUAL` la restricción sólo alcanza
 * al `ON UPDATE`, así que entra.
 *
 * Y no cuesta nada donde importa: MySQL 8 indexa columnas virtuales, el único de
 * abajo funciona —comprobado a mano en el docker antes de escribir esto: el
 * segundo blanco del mismo cargo contesta **1062 Duplicate entry '1-911-0'**— y no
 * ocupa espacio en la fila.
 *
 * > **Lo que queda por comprobar el día del despliegue, y va dicho aquí en vez de
 * > descubrirse:** producción es **MariaDB 10.5**, y esto sólo se ha probado en el
 * > MySQL 8 del docker. MariaDB indexa columnas virtuales en InnoDB desde la 10.2,
 * > así que debería entrar igual; si no entrara, la salida no es volver a `STORED`
 * > —choca con la misma clave ajena— sino cambiar el `ON DELETE CASCADE` de
 * > `candidato_id`, **que es una decisión y no un arreglo**. Se prueba con un
 * > `migrate` en un colegio antes que en los dieciséis.
 *
 * ## EL ORDEN DE LOS DOS ÍNDICES, y no es cosmético
 *
 * El único viejo empieza por `acta_id`, así que **InnoDB lo está usando como
 * índice de la clave ajena `vt_acta_votos_acta_id_foreign`** — en `SHOW CREATE
 * TABLE` no hay ningún `KEY` propio para `acta_id`, sólo para `aspiracion_id` y
 * `candidato_id`. Tirarlo primero contesta
 *
 *     1553 Cannot drop index 'vt_acta_votos_unico': needed in a foreign key constraint
 *
 * que es literalmente la piedra con la que tropezó el `down()` de la 600000 el
 * mismo día. Por eso aquí **el nuevo entra antes de que el viejo salga**: empieza
 * también por `acta_id`, la clave ajena se apoya en él, y entonces el viejo ya no
 * lo necesita nadie.
 *
 * ## LAS FILAS QUE YA NO CABEN SE SUMAN, NO SE BORRAN
 *
 * Antes de poner el índice hay que dejar la tabla cumpliéndolo, y la forma de
 * hacerlo aquí **no es tirar la fila sobrante**: dos filas de blanco del mismo
 * cargo son dos montones de papeletas que alguien contó, y el total correcto es
 * la suma. Borrar una perdería votos de verdad; sumarlas deja el mismo número que
 * el escrutinio venía dando. Se conserva la de `id` menor —`MIN(id)`, por lo
 * mismo que la 600000: `created_at` es anulable y el `AUTO_INCREMENT` no tiene
 * casos raros— y se le suma lo de sus hermanas.
 *
 * Medido en el docker el 22 sep 2026: **0 grupos duplicados** en
 * `micolev1_la_hermosa` (2 filas en `vt_acta_votos`, ninguna repetida). O sea que
 * el barrido no tenía que encontrar nada — **y encontrar algo aquí habría sido la
 * noticia**, porque significaría que un acta ya estaba contando doble.
 *
 * ## VOLVER ATRÁS
 *
 * `down()` devuelve el único de tres columnas y tira la generada. No puede fallar
 * por duplicados: lo que cumple el índice nuevo cumple el viejo, que es más laxo.
 * Lo que **no** se deshace es la suma de arriba —dos filas sumadas no se vuelven a
 * partir en dos—, y es lo correcto: el número que queda es el que el colegio
 * contó.
 */
class ElBlancoDelActaEsUnoSolo extends Migration
{
    /** El índice que llega, con la columna generada dentro. */
    private const UNICO_NUEVO = 'vt_acta_votos_unico_con_blanco';

    /** El que se va, el de la 700000. */
    private const UNICO_VIEJO = 'vt_acta_votos_unico';

    public function up()
    {
        if (! Schema::hasTable('vt_acta_votos')) {
            echo "  vt_acta_votos: no existe todavía, no hay nada que cerrar.\n";

            return;
        }

        if (Schema::hasColumn('vt_acta_votos', 'candidato_o_blanco')) {
            echo "  vt_acta_votos.candidato_o_blanco: ya existe, no se toca.\n";

            return;
        }

        /*
         * ── 1. Los blancos repetidos, sumados ──
         *
         * Auto-`JOIN` y no subconsulta sobre la misma tabla, por lo mismo que la
         * 600000: MySQL no deja nombrar la tabla de destino dentro de un `FROM`
         * de subconsulta y un `DELETE … JOIN (derivada)` depende de cómo
         * materialice cada motor. Esto es el mismo SQL en MySQL 8 y en MariaDB 10.5.
         *
         * El `COALESCE` del `ON` es el que hace que dos `NULL` **sí** se
         * encuentren aquí, que es justo lo que el índice no sabía hacer.
         */
        DB::statement('UPDATE vt_acta_votos v
            INNER JOIN (
                SELECT MIN(id) AS superviviente,
                       SUM(cantidad) AS total
                  FROM vt_acta_votos
              GROUP BY acta_id, aspiracion_id, COALESCE(candidato_id, 0)
                HAVING COUNT(*) > 1
            ) s ON s.superviviente = v.id
               SET v.cantidad = s.total');

        DB::statement('DELETE v FROM vt_acta_votos v
            INNER JOIN vt_acta_votos w
                    ON w.acta_id       = v.acta_id
                   AND w.aspiracion_id = v.aspiracion_id
                   AND COALESCE(w.candidato_id, 0) = COALESCE(v.candidato_id, 0)
                   AND w.id            < v.id');

        /*
         * ── 2. La columna generada ──
         *
         * Sin `after()`: el orden de las columnas es cosmético —ninguna consulta
         * de este repositorio depende de él— y un ancla de menos es un colegio
         * menos que se queda a mitad de `migrate`. Es la lección de
         * `App\Support\Ancla`, aplicada por omisión.
         */
        Schema::table('vt_acta_votos', function (Blueprint $tabla) {
            $tabla->unsignedInteger('candidato_o_blanco')->virtualAs('COALESCE(candidato_id, 0)');
        });

        // ── 3. El índice nuevo ANTES de tirar el viejo. Ver la cabecera. ──
        Schema::table('vt_acta_votos', function (Blueprint $tabla) {
            $tabla->unique(['acta_id', 'aspiracion_id', 'candidato_o_blanco'], self::UNICO_NUEVO);
        });

        Schema::table('vt_acta_votos', function (Blueprint $tabla) {
            $tabla->dropUnique(self::UNICO_VIEJO);
        });
    }

    public function down()
    {
        if (! Schema::hasColumn('vt_acta_votos', 'candidato_o_blanco')) {
            return;
        }

        // Mismo orden invertido: el viejo entra antes de que salga el nuevo, o la
        // clave ajena de `acta_id` se queda sin índice en el que apoyarse.
        Schema::table('vt_acta_votos', function (Blueprint $tabla) {
            $tabla->unique(['acta_id', 'aspiracion_id', 'candidato_id'], self::UNICO_VIEJO);
        });

        Schema::table('vt_acta_votos', function (Blueprint $tabla) {
            $tabla->dropUnique(self::UNICO_NUEVO);
        });

        Schema::table('vt_acta_votos', function (Blueprint $tabla) {
            $tabla->dropColumn('candidato_o_blanco');
        });
    }
}
