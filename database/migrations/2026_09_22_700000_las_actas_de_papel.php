<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * **El acta de papel: lo que se votó donde no había pantalla.**
 *
 * Quinta y última de la tanda. Es la mitad del módulo que no existía: un grupo
 * que votó en papeletas —porque se cayó la red, porque el curso es de primaria,
 * porque el colegio lo prefiere así— tenía que quedarse fuera del recuento o
 * que alguien entrara voto a voto con la cuenta de los alumnos.
 *
 * ## UN ACTA ES UN RECUENTO, NO UNA LISTA DE VOTOS
 *
 * Y es la diferencia entera con `vt_votos`:
 *
 * | | `vt_votos` | `vt_actas` + `vt_acta_votos` |
 * |---|---|---|
 * | una fila es | **una persona** votando un cargo | **un número**: «a Fulano, 14» |
 * | quién vota | `user_id`, con nombre y apellidos detrás | nadie: no hay a quién señalar |
 * | la regla | un voto por persona y cargo | una cantidad por candidato y cargo |
 *
 * Por eso el acta **no puede** guardarse como filas de `vt_votos` con un
 * usuario inventado: el índice único de allí lo impediría a la segunda papeleta,
 * y con razón. Son dos formas distintas del mismo hecho y se suman al contar,
 * no antes.
 *
 * Y tiene un efecto secundario bueno: **el acta es más secreta que la pantalla**.
 * De un acta no se puede sacar a quién votó nadie, porque el dato no está.
 *
 * ## `conto_user_id` Y `firmada_por` SON DOS PERSONAS, y por eso son dos columnas
 *
 * `conto_user_id` es quien metió los números. `firmada_por` es quien los avala
 * —el titular, el coordinador, quien el colegio diga— y puede no llegar nunca:
 * es anulable, y `firmada_en` con ella.
 *
 * Es la misma distinción que `requisitos_alumno.cerrado_por` frente a
 * `updated_by` (`2026_09_20_300000_el_dia_de_matriculas.php`): *quien tocó esto
 * por última vez* y *quien se hace responsable* son dos preguntas, y la segunda
 * es un hecho que no se repite.
 *
 * **Un acta sin firmar es un acta válida**, sólo que provisional. Exigir la
 * firma para guardar dejaría al colegio con el recuento en un papel encima de la
 * mesa mientras busca al titular.
 *
 * ## EL ÚNICO EN (votacion_id, grupo_id)
 *
 * Un grupo cuenta sus papeletas una vez. Dos actas del mismo grupo son dos
 * recuentos de la misma urna, y sumarlos dobla los votos sin que nadie vea
 * nada raro: es el fallo más caro que puede tener esta tabla y lo cierra la
 * base.
 *
 * ## EL ÚNICO DE `vt_acta_votos` TIENE UN AGUJERO, Y HAY QUE SABERLO
 *
 * `(acta_id, aspiracion_id, candidato_id)` con `candidato_id` anulable
 * —`NULL` = voto en blanco, la misma convención que `vt_votos` estrena en la
 * migración anterior—.
 *
 * > **En MySQL y en MariaDB, dos `NULL` no son iguales**, así que un índice
 * > único no impide dos filas de voto en blanco para el mismo cargo del mismo
 * > acta. Los candidatos con nombre sí quedan cerrados; **el blanco no.**
 *
 * Se deja así porque la alternativa —un `0` centinela, o una columna generada
 * `COALESCE(candidato_id, 0)`— cambia la forma que el diseño pide y mete en la
 * tabla un valor que no es un candidato. **Lo que NO se hace es callarlo:** la
 * pantalla que escribe el acta tiene que mandar una sola fila de blanco por
 * cargo, y eso hoy lo sostiene el controlador y no la base. Es exactamente el
 * tipo de regla que este repositorio lleva un mes sacando de PHP, así que queda
 * anotado para que se decida, no para que se descubra.
 *
 * ## `cantidad` ES `unsignedInteger` Y NO LLEVA TOPE
 *
 * No se compara contra el censo del grupo aquí: un acta con más votos que
 * alumnos es un error de conteo que el colegio tiene que ver y corregir, no un
 * `INSERT` rechazado a medianoche. El `unsigned` sí cierra lo único que no tiene
 * lectura posible, que es una cantidad negativa.
 *
 * ## VOLVER ATRÁS
 *
 * `down()` tira las dos tablas y se pierde lo contado en papel. Nacen vacías, así
 * que un `rollback` el mismo día no pierde nada. Con el permiso del 22 sep 2026
 * sobre los datos de votaciones, y sin tocar ninguna otra tabla del sistema.
 */
class LasActasDePapel extends Migration
{
    public function up()
    {
        if (Schema::hasTable('vt_actas')) {
            echo "  vt_actas: ya existe, no se toca.\n";
        } else {
            Schema::create('vt_actas', function (Blueprint $tabla) {
                $tabla->increments('id');

                $tabla->unsignedInteger('votacion_id');
                $tabla->unsignedInteger('grupo_id');

                // Quien metió los números.
                $tabla->unsignedInteger('conto_user_id');

                // Y quien los avala, que puede no llegar nunca. Ver la cabecera.
                $tabla->unsignedInteger('firmada_por')->nullable();
                $tabla->dateTime('firmada_en')->nullable();

                /*
                 * `text` y no `string`: aquí es donde el colegio escribe por qué
                 * este grupo votó en papel, o que faltaron tres papeletas. Eso no
                 * cabe en un renglón y es lo que salva el acta cuando alguien la
                 * mira en marzo.
                 */
                $tabla->text('observacion')->nullable();

                $tabla->timestamps();

                // Un recuento por grupo y elección. Ver la cabecera.
                $tabla->unique(['votacion_id', 'grupo_id'], 'vt_actas_una_por_grupo');

                $tabla->foreign('votacion_id')->references('id')->on('vt_votaciones')->onDelete('cascade');
                $tabla->foreign('grupo_id')->references('id')->on('grupos')->onDelete('cascade');

                /*
                 * `set null` no vale aquí: `conto_user_id` no es anulable, y el
                 * acta sin quien la contó no es un acta. `restrict` impide borrar
                 * la cuenta antes que el acta, que es el orden correcto —y el
                 * único caso real es una cuenta creada por error, no la de quien
                 * pasó una mañana contando papeletas—.
                 */
                $tabla->foreign('conto_user_id')->references('id')->on('users')->onDelete('restrict');
                $tabla->foreign('firmada_por')->references('id')->on('users')->onDelete('set null');
            });
        }

        if (Schema::hasTable('vt_acta_votos')) {
            echo "  vt_acta_votos: ya existe, no se toca.\n";

            return;
        }

        Schema::create('vt_acta_votos', function (Blueprint $tabla) {
            $tabla->increments('id');

            $tabla->unsignedInteger('acta_id');
            $tabla->unsignedInteger('aspiracion_id');

            // NULL = voto en blanco. Misma convención que `vt_votos`.
            $tabla->unsignedInteger('candidato_id')->nullable();

            // Cuántos. Sin tope por arriba: ver la cabecera.
            $tabla->unsignedInteger('cantidad');

            $tabla->timestamps();

            /*
             * Cierra los candidatos con nombre. **No cierra el blanco**, porque
             * dos `NULL` no son iguales para un índice único. Está explicado
             * arriba y es lo primero que hay que leer de esta tabla.
             */
            $tabla->unique(['acta_id', 'aspiracion_id', 'candidato_id'], 'vt_acta_votos_unico');

            $tabla->foreign('acta_id')->references('id')->on('vt_actas')->onDelete('cascade');
            $tabla->foreign('aspiracion_id')->references('id')->on('vt_aspiraciones')->onDelete('cascade');
            $tabla->foreign('candidato_id')->references('id')->on('vt_candidatos')->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('vt_acta_votos');
        Schema::dropIfExists('vt_actas');
    }
}
