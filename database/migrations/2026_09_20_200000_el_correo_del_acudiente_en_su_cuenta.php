<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Copia a la CUENTA el correo que el acudiente ya tenía escrito en su FICHA.
 *
 * ## Qué estaba roto
 *
 * Son dos columnas distintas y nadie las sincronizaba: `acudientes.email` es la
 * ficha y `users.email` es la cuenta. **La recuperación de contraseña sólo mira
 * la cuenta** — `LoginController:240-266` busca cuatro veces y las cuatro por
 * `users.email`—, y el front sólo escribía la ficha.
 *
 * Medido en el docker (`simonbolivar`) el 20 sep 2026:
 *
 *     acudientes vivos                        1.085
 *     con correo de FICHA                       100   (9,2 %)
 *     con cuenta viva y activa                1.000
 *     con correo de CUENTA                        0   <- por aquí busca
 *     alcanzables por la recuperación             0
 *
 * **No son nueve de cada diez, son diez de diez**, incluidos los 100 que sí
 * tenían correo. Y como el método contesta `Enviado` exista o no el correo —a
 * propósito, para no filtrar qué direcciones hay—, **los mil ven la misma
 * pantalla que si hubiera salido**.
 *
 * La causa y su arreglo van en el mismo commit que esta migración:
 * `AcudientesController` no tenía la red que sí tienen `AlumnosController:496` y
 * `ProfesoresController:248`. Eso cura a los acudientes que se creen **desde
 * ahora**; las filas ya escritas no las arregla un commit, y de eso va esto.
 *
 * ## Lo que hace, y lo que NO hace a propósito
 *
 * Copia la ficha a la cuenta **sólo cuando la cuenta está vacía**. No pisa
 * ningún correo existente y no inventa ninguno: un acudiente sin correo en la
 * ficha se queda sin correo en la cuenta. Decisión de Joseth del 20 sep 2026,
 * y la mitad que se descartó es la de `username@myvc.com` — llenar la columna
 * con un buzón de nadie hace que el reseteo encuentre la cuenta, mande el enlace
 * y conteste «Enviado», que es peor que no encontrarla. Ya le pasa a 16 cuentas
 * vivas, 11 de ellas de profesores, y **esas no se tocan aquí**: limpiarlas es
 * otra decisión y no se ha tomado.
 *
 * ## Las colisiones se SALTAN, y ése es el punto delicado
 *
 * `users.email` **no tiene índice único** (comprobado en
 * `database/schema/mysql-schema.sql`), así que la base aceptaría duplicados sin
 * rechistar. El que no los acepta es el flujo: `LoginController:240` hace
 * `SELECT * FROM users WHERE email = ?` y se queda con `$persona[0]`, **la
 * primera fila**. Un correo repetido no da error: le manda el enlace a uno de
 * los dos y el otro no se entera nunca.
 *
 * Por eso, cuando el correo de la ficha **ya lo tiene otra cuenta viva**, esta
 * migración **no escribe** y lo cuenta aparte. En el docker son **6 de 94**, y
 * hay que decidirlos uno a uno: son personas distintas compartiendo dirección,
 * normalmente una familia.
 *
 * *Y ya hay duplicados de antes que esto ni crea ni arregla: nueve direcciones
 * repartidas entre 694 cuentas vivas, 678 de ellas con la cadena literal
 * `@gmail.com` —sin nada delante— en cuentas de alumno. No es un correo y no se
 * toca aquí.*
 *
 * ## Es segura de repetir
 *
 * La condición de entrada es «la cuenta está vacía», y al terminar deja de
 * estarlo, así que una segunda pasada no encuentra esa fila. Correrla dos veces
 * no cambia nada la segunda vez.
 *
 * ## `down()` no deshace, y aquí está el porqué
 *
 * Vaciar los correos que coincidan con la ficha **borraría también los que ya
 * estaban ahí antes** y coincidían legítimamente. No se sabe cuáles puso esta
 * migración: no hay columna que lo diga. *La vuelta atrás es la copia de
 * seguridad, no esto.*
 */
return new class extends Migration
{
    public function up(): void
    {
        // Las candidatas: ficha con correo, cuenta viva sin correo. El `TRIM` va
        // aquí y no en el cliente porque un correo con un espacio delante no casa
        // con nada en la búsqueda del reseteo y parecería escrito.
        $candidatas = DB::select(
            'SELECT u.id AS user_id, TRIM(ac.email) AS correo
               FROM acudientes ac
               JOIN users u ON u.id = ac.user_id AND u.deleted_at IS NULL
              WHERE ac.deleted_at IS NULL
                AND ac.email IS NOT NULL AND TRIM(ac.email) <> \'\'
                AND (u.email IS NULL OR TRIM(u.email) = \'\')'
        );

        $copiados = 0;
        $chocan = [];

        foreach ($candidatas as $fila) {
            // Se pregunta DENTRO del bucle a propósito: si dos acudientes comparten
            // el correo de su ficha, el primero lo ocupa y el segundo pasa a ser una
            // colisión. Consultarlo una vez antes del bucle no lo vería.
            $ocupado = DB::selectOne(
                'SELECT COUNT(*) AS n FROM users
                  WHERE deleted_at IS NULL AND id <> ? AND email = ?',
                [$fila->user_id, $fila->correo]
            );

            if ((int) $ocupado->n > 0) {
                $chocan[] = $fila->correo;

                continue;
            }

            DB::update('UPDATE users SET email = ? WHERE id = ?', [$fila->correo, $fila->user_id]);
            $copiados++;
        }

        // Se imprime porque esto corre en diecisiete colegios y «migrated» a secas
        // no distingue «no había ninguno» de «no hizo nada». Y las colisiones se
        // dicen: son las que quedan sin arreglar y necesitan a una persona.
        echo sprintf(
            "    correo del acudiente -> su cuenta:  copiados %d de %d candidatos; %d sin tocar por colision\n",
            $copiados, count($candidatas), count($chocan)
        );

        if (count($chocan) > 0) {
            echo "    (esos correos ya los tiene otra cuenta viva; el reseteo se queda con la primera fila,\n"
                ."     asi que se decide uno a uno en vez de repartir enlaces al azar)\n";
        }
    }

    public function down(): void
    {
        // A propósito no deshace nada. El porqué está en la cabecera: no se sabe
        // qué filas puso esta migración, y vaciar las que coincidan con la ficha
        // borraría también las que ya estaban bien.
        echo "    el_correo_del_acudiente_en_su_cuenta: down() no restaura — la vuelta es la\n"
            ."    copia de seguridad, no esta migracion. El porque, en su cabecera.\n";
    }
};
