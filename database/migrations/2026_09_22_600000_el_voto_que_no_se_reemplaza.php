<?php

use App\Support\Ancla;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * **Un hombre, un voto por cargo — y lo garantiza la base.**
 *
 * Cuarta de las cinco, y la única que **borra filas**. Es la migración a la que
 * apunta todo lo demás.
 *
 * ## DE DÓNDE VIENE, que es lo que explica cada línea de aquí
 *
 * Hoy la unicidad del voto la sostiene un método mal llamado. `VtVoto::verificarNoVoto()`
 * no verifica: **busca el voto anterior del mismo usuario en la misma aspiración
 * y lo manda a la papelera** para que quepa el nuevo
 * (`docs/migracion/11-votaciones.md` §3). O sea que votar dos veces cambia el
 * voto en vez de duplicarlo, y **eso es lo único que impide que el recuento se
 * infle**, porque `postStore()` tampoco mira `locked` (§2).
 *
 * El 11 lo dejó escrito como una advertencia: *«el día que alguien arregle el
 * borrado, enciende el fallo de la §2 sin haberla tocado»*. Esta migración hace
 * justo lo que esa advertencia prohíbe hacer a medias — **y por eso lo hace
 * entero**: se quita el borrado y, en la misma migración, la regla pasa a un
 * índice único que MySQL hace cumplir en cada `INSERT`, con la urna abierta o
 * cerrada, por la pantalla o por la API.
 *
 * > Antes: «no hay dos votos porque el código borra el anterior».
 * > Ahora: **«no puede haber dos votos»**, y el segundo `INSERT` revienta.
 *
 * ## LAS DOS COLUMNAS QUE FALTABAN, y por qué faltaban
 *
 * Para saber de qué elección y de qué cargo es un voto había que navegar
 * `candidato_id → vt_candidatos.aspiracion_id → vt_aspiraciones.votacion_id`,
 * **y para un voto en blanco por otro camino distinto**, porque el blanco no
 * tiene candidato y guardaba el cargo en una columna aparte,
 * `blanco_aspiracion_id`.
 *
 * Dos caminos para el mismo dato es lo que hacía imposible el índice: no se
 * puede poner un único sobre un cargo que a veces vive dos tablas más allá y a
 * veces en la fila. Con `votacion_id` y `aspiracion_id` en la propia fila, la
 * regla cabe en tres columnas.
 *
 * Y de paso desaparece la asimetría: **`candidato_id IS NULL` significa voto en
 * blanco**, y `aspiracion_id` dice de qué cargo. Una sola forma.
 *
 * ## LO QUE SE BORRA, con permiso y medido
 *
 * Tres barridos, en este orden y antes del índice:
 *
 *   1. **Las filas con `deleted_at`**: en duro. Son los votos que
 *      `verificarNoVoto()` mandó a la papelera al cambiar alguien su voto, y el
 *      11 §6 los nombra por lo que son: *«la misma fuga del voto secreto, en la
 *      papelera»* —conservan `user_id` y `candidato_id` intactos—. Medido el 22
 *      sep 2026 en `simonbolivar`: **46 de 264**.
 *   2. **Las que no se puedan rellenar**: un voto cuyo candidato o cuya
 *      aspiración ya no existen no se puede contar en ningún cargo. Medido el
 *      mismo día: **cero**.
 *   3. **Los duplicados**, dejando la más antigua por
 *      `(votacion_id, aspiracion_id, user_id)`. Medido: **cero** — es la
 *      propiedad que `verificarNoVoto()` venía sosteniendo, así que el barrido
 *      no tenía que encontrar nada, y **encontrar algo aquí habría sido la
 *      noticia**.
 *
 * Todo esto es irreversible y está autorizado: Joseth, 22 sep 2026 — *«Las
 * votaciones se pueden ignorar, y si las llegan a necesitar las sacamos de un
 * backup; ellos nunca miran las votaciones de años pasados.»* **Ninguna otra
 * tabla del sistema se toca.**
 *
 * ### «La más antigua» es `MIN(id)`, y eso hay que justificarlo
 *
 * No se ordena por `created_at`: es anulable en esta tabla, y dos filas sin
 * fecha volverían a quedar en manos del orden físico, que es el fallo del que va
 * todo esto (el mismo razonamiento que `Matricula::ORDEN_DEL_ANIO`). `id` es
 * `AUTO_INCREMENT` dentro de una sola base, así que **el menor es el primero que
 * entró**, siempre, y sin casos raros. Comprobado además en `simonbolivar`: cero
 * filas con `created_at` nulo, así que los dos criterios habrían coincidido.
 *
 * ## LO QUE ESTA MIGRACIÓN *NO* ARREGLA, dicho aquí para que no sorprenda
 *
 * - **La cascada de `candidato_id` sigue siendo `ON DELETE CASCADE`.** Borrar
 *   una aspiración en duro —`aspiraciones/destroy`, que es física porque
 *   `VtAspiracion` no lleva `SoftDeletes`— se lleva candidatos y **votos**, y
 *   ahora ya no hay papelera detrás (05 §58.1). Cambiarla a `RESTRICT` apagaría
 *   un endpoint vivo en dieciséis colegios, y eso es una decisión, no un
 *   arreglo. **Queda igual que estaba y sigue documentado.**
 * - **`deleted_at` y `deleted_by` se quedan en la tabla**, vacías para siempre.
 *   No se tiran porque `down()` las necesita y porque quitarlas no compra nada.
 *   Lo que sí cambia es el modelo: `VtVoto` pierde `SoftDeletes`. Quien se lo
 *   devuelva **choca de frente con el índice** —una fila borrada seguiría
 *   ocupando su hueco de `(votacion_id, aspiracion_id, user_id)`— y eso está
 *   escrito también en el modelo.
 * - **`postStore()` sigue sin mirar `locked`** (11 §2). Eso es del controlador,
 *   y lo hace otro.
 *
 * ## VOLVER ATRÁS
 *
 * `down()` devuelve la forma: recrea `blanco_aspiracion_id` y lo rellena desde
 * `aspiracion_id` en los votos en blanco, quita el índice y las seis columnas.
 * El código viejo vuelve a funcionar sobre los votos que queden.
 *
 * **Lo que no vuelven son las filas borradas por el `up()`.** Un `rollback` no
 * las resucita; salen de un backup o no salen. Está dicho arriba y autorizado.
 */
class ElVotoQueNoSeReemplaza extends Migration
{
    public function up()
    {
        /*
         * ── 1. Las columnas, todas anulables de momento ──
         *
         * `votacion_id` y `aspiracion_id` nacen anulables **porque todavía no
         * están rellenas**: declararlas `NOT NULL` aquí pondría un `0` en las
         * 264 filas vivas y el `0` no es un id, es una mentira que luego pasa el
         * relleno de largo. Se endurecen al final, cuando ya no hay ninguna
         * vacía.
         */
        Schema::table('vt_votos', function (Blueprint $tabla) {
            if (! Schema::hasColumn('vt_votos', 'votacion_id')) {
                $tabla->unsignedInteger('votacion_id')->nullable()->after(Ancla::de($tabla, 'user_id'));
            }

            if (! Schema::hasColumn('vt_votos', 'aspiracion_id')) {
                $tabla->unsignedInteger('aspiracion_id')->nullable()->after(Ancla::de($tabla, 'votacion_id'));
            }

            /*
             * Quién condujo la mesa. **No es quien votó** —eso es `user_id`, y
             * las votaciones son secretas— sino quién estaba delante del equipo.
             * Nulo cuando el voto se emitió sin mesa.
             */
            if (! Schema::hasColumn('vt_votos', 'asistido_por')) {
                $tabla->unsignedInteger('asistido_por')->nullable()->after(Ancla::de($tabla, 'candidato_id'));
            }

            if (! Schema::hasColumn('vt_votos', 'mesa_id')) {
                $tabla->unsignedInteger('mesa_id')->nullable()->after(Ancla::de($tabla, 'asistido_por'));
            }

            /*
             * 'propio' | 'mesa'. Seis caracteres porque 'propio' mide seis, y el
             * tope es la primera puerta contra un valor inventado.
             *
             * Nace en 'propio' y eso es verdad para las filas viejas: antes de
             * esta tanda no había mesas, así que todo voto que existe hoy se
             * emitió por su cuenta. No es un defecto de relleno, es el dato.
             */
            if (! Schema::hasColumn('vt_votos', 'origen')) {
                $tabla->string('origen', 6)->default('propio')->after(Ancla::de($tabla, 'mesa_id'));
            }

            /*
             * Cuánto tardó en votar, para la auditoría de la mesa: un tarjetón
             * resuelto en dos segundos veinte veces seguidas no lo rellenó un
             * alumno. Anulable porque de los votos viejos no se sabe, y **cero
             * no es «no se sabe»**.
             */
            if (! Schema::hasColumn('vt_votos', 'segundos')) {
                $tabla->unsignedSmallInteger('segundos')->nullable()->after(Ancla::de($tabla, 'origen'));
            }
        });

        /*
         * ── 2. La papelera, en duro ──
         *
         * Antes del relleno porque es trabajo que no hay que hacer, y antes del
         * índice porque un voto borrado seguiría ocupando su hueco.
         */
        DB::statement('DELETE FROM vt_votos WHERE deleted_at IS NOT NULL');

        /*
         * ── 3. El relleno ──
         *
         * Sin filtrar `deleted_at` de `vt_candidatos` ni de `vt_aspiraciones`: lo
         * que se busca es **de qué cargo era este voto**, y eso no deja de ser
         * verdad porque el candidato esté en la papelera. Filtrarlo dejaría el
         * voto sin rellenar y el barrido de abajo se lo llevaría, que es
         * exactamente lo contrario de lo que se quiere.
         */
        DB::statement('UPDATE vt_votos v
            INNER JOIN vt_candidatos c ON c.id = v.candidato_id
            INNER JOIN vt_aspiraciones a ON a.id = c.aspiracion_id
               SET v.aspiracion_id = a.id,
                   v.votacion_id   = a.votacion_id
             WHERE v.candidato_id IS NOT NULL');

        // Los blancos, por el otro camino. Disjunto del de arriba: aquí sólo
        // entra lo que no tiene candidato.
        DB::statement('UPDATE vt_votos v
            INNER JOIN vt_aspiraciones a ON a.id = v.blanco_aspiracion_id
               SET v.aspiracion_id = a.id,
                   v.votacion_id   = a.votacion_id
             WHERE v.candidato_id IS NULL
               AND v.blanco_aspiracion_id IS NOT NULL');

        /*
         * ── 4. Lo que no se pudo rellenar ──
         *
         * Un voto sin cargo no se puede contar en ninguna urna ni encajar en el
         * índice. Medido: cero en `simonbolivar`.
         */
        DB::statement('DELETE FROM vt_votos WHERE votacion_id IS NULL OR aspiracion_id IS NULL');

        /*
         * ── 5. Los duplicados ──
         *
         * Auto-`JOIN` y no subconsulta sobre la misma tabla: MySQL no deja
         * nombrar la tabla de destino dentro de un `FROM` de subconsulta, y un
         * `DELETE … JOIN (derivada)` depende de cómo materialice cada motor
         * —producción es MariaDB 10.5 y el docker MySQL 8—. Esto es el mismo SQL
         * en los dos.
         *
         * Se borra toda fila que tenga una hermana con `id` MENOR en el mismo
         * `(votacion_id, aspiracion_id, user_id)`: sobrevive exactamente una, la
         * del `id` más pequeño, que es la más antigua. Ver la cabecera.
         */
        DB::statement('DELETE v FROM vt_votos v
            INNER JOIN vt_votos w
                    ON w.votacion_id   = v.votacion_id
                   AND w.aspiracion_id = v.aspiracion_id
                   AND w.user_id       = v.user_id
                   AND w.id            < v.id');

        /*
         * ── 6. La columna que sobra ──
         *
         * A partir de aquí, `candidato_id IS NULL` **es** el voto en blanco. No
         * lleva clave ajena (comprobado en el docker contra `simonbolivar` y
         * `caz_zaragoza`), así que se va de una.
         */
        if (Schema::hasColumn('vt_votos', 'blanco_aspiracion_id')) {
            Schema::table('vt_votos', function (Blueprint $tabla) {
                $tabla->dropColumn('blanco_aspiracion_id');
            });
        }

        /*
         * ── 7. Las dos columnas se endurecen, y entran las claves ajenas ──
         *
         * `change()` nativo de Laravel 11+; no hace falta `doctrine/dbal`. Hay
         * que repetir el tipo entero porque `change()` sustituye la definición,
         * no la parchea: lo que no se vuelva a escribir se pierde.
         */
        Schema::table('vt_votos', function (Blueprint $tabla) {
            $tabla->unsignedInteger('votacion_id')->nullable(false)->change();
            $tabla->unsignedInteger('aspiracion_id')->nullable(false)->change();
        });

        Schema::table('vt_votos', function (Blueprint $tabla) {
            $tabla->foreign('votacion_id')->references('id')->on('vt_votaciones')->onDelete('cascade');
            $tabla->foreign('aspiracion_id')->references('id')->on('vt_aspiraciones')->onDelete('cascade');

            /*
             * `set null` y no `cascade` en las dos de auditoría, y es lo mismo
             * que decir que el voto manda: si se borra la cuenta del que condujo
             * la mesa, o la mesa entera, **el voto sigue contando**. Con
             * `cascade` se llevarían por delante justo lo que esta migración
             * existe para volver intocable.
             */
            $tabla->foreign('asistido_por')->references('id')->on('users')->onDelete('set null');
            $tabla->foreign('mesa_id')->references('id')->on('vt_mesas')->onDelete('set null');
        });

        /*
         * ── 8. La regla ──
         *
         * Un usuario, un voto por cargo. Se declara al final, cuando la tabla ya
         * lo cumple; declararlo antes habría reventado el `migrate` en el primer
         * colegio con dos votos del mismo alumno.
         *
         * Va en este orden de columnas —elección, cargo, persona— porque es el
         * orden de las lecturas: «los votos de esta elección» y «los de este
         * cargo» son consultas reales, y «los de este usuario en cualquier
         * elección» **no lo es y no debe serlo**: es la pregunta del voto
         * nominal que el 11 §6 y §7.1 mandan cerrar.
         */
        Schema::table('vt_votos', function (Blueprint $tabla) {
            $tabla->unique(['votacion_id', 'aspiracion_id', 'user_id'], 'vt_votos_un_voto_por_cargo');
        });
    }

    public function down()
    {
        // Primero vuelve la columna vieja, y se rellena mientras `aspiracion_id`
        // todavía existe. Al revés, el dato se pierde por el orden.
        if (! Schema::hasColumn('vt_votos', 'blanco_aspiracion_id')) {
            Schema::table('vt_votos', function (Blueprint $tabla) {
                $tabla->unsignedInteger('blanco_aspiracion_id')->nullable()->after(Ancla::de($tabla, 'candidato_id'));
            });

            DB::statement('UPDATE vt_votos
                              SET blanco_aspiracion_id = aspiracion_id
                            WHERE candidato_id IS NULL');
        }

        /*
         * **Las claves ajenas ANTES que el índice, y no es cosmético.**
         *
         * MySQL no crea un índice propio para la clave ajena de `votacion_id`:
         * se apoya en el único de arriba, porque `votacion_id` es su primera
         * columna. Quitar el índice con la clave ajena todavía puesta contesta
         *
         *     1553 Cannot drop index 'vt_votos_un_voto_por_cargo':
         *          needed in a foreign key constraint
         *
         * y el `rollback` se para aquí dejando la tabla a medias. Salió en el
         * docker el 22 sep 2026, a la primera. Con las cuatro fuera, el índice ya
         * no lo necesita nadie.
         */
        Schema::table('vt_votos', function (Blueprint $tabla) {
            $tabla->dropForeign(['votacion_id']);
            $tabla->dropForeign(['aspiracion_id']);
            $tabla->dropForeign(['asistido_por']);
            $tabla->dropForeign(['mesa_id']);
        });

        Schema::table('vt_votos', function (Blueprint $tabla) {
            $tabla->dropUnique('vt_votos_un_voto_por_cargo');
        });

        Schema::table('vt_votos', function (Blueprint $tabla) {
            foreach (['segundos', 'origen', 'mesa_id', 'asistido_por', 'aspiracion_id', 'votacion_id'] as $columna) {
                if (Schema::hasColumn('vt_votos', $columna)) {
                    $tabla->dropColumn($columna);
                }
            }
        });
    }
}
