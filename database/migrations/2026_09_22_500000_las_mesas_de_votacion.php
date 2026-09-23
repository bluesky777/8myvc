<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * **Las mesas de votación: un equipo, unos grupos y quien las conduce.**
 *
 * Tercera de las cinco. Es la número **4** del encargo y va la **tercera** por
 * una razón que no es de estilo: `vt_votos.mesa_id` —de la migración que viene
 * detrás— tiene clave ajena a `vt_mesas`, y **una clave ajena no se puede
 * declarar contra una tabla que todavía no existe**. Con el orden al revés, el
 * `migrate` se para aquí y deja el colegio a medias, que es el modo de fallo que
 * documenta `App\Support\Ancla`.
 *
 * ## QUÉ ES UNA MESA, en una línea
 *
 * Un portátil, una persona del colegio delante, y los cursos que pasan por él.
 * Es el caso que el módulo viejo no sabía representar: en un colegio con veinte
 * equipos y quinientos alumnos, votar «cada uno desde su cuenta» no es una
 * elección, es una cola.
 *
 * ## TRES TABLAS Y NO UNA
 *
 * Porque son tres preguntas distintas y las tres son de varios a varios:
 *
 *   - `vt_mesas` — la mesa. Cuelga de la elección y de nada más.
 *   - `vt_mesa_usuarios` — **quién la conduce**. Varios, porque una mesa dura
 *     toda la mañana y la gente se releva a la hora del descanso.
 *   - `vt_mesa_grupos` — **qué grupos pasan por ella**. Varios, porque una mesa
 *     atiende a dos cursos seguidos; y un grupo puede repartirse en dos mesas
 *     cuando es grande, así que tampoco vale una columna en `grupos`.
 *
 * ## LO QUE ESTAS TABLAS *NO* DICEN, y hay que saberlo antes de leerlas
 *
 * **No son el censo.** Quién puede votar lo decide `vt_grupos_votacion` más la
 * matrícula del año (ver `VtVotacion::censo()`); esto sólo dice *dónde* y *con
 * quién delante*. Un grupo sin mesa vota igual — salvo que la elección tenga
 * `solo_en_mesa = 1`, que es el único sitio donde las dos preguntas se tocan.
 *
 * **Y no son la única forma de conducir una mesa.** Con
 * `vt_votaciones.titulares_conducen = 1` —el defecto— el titular de un grupo
 * conduce el suyo sin que exista ninguna fila aquí. Estas tablas son para el
 * colegio que nombra mesas a mano; el que no las use no tiene que crear
 * ninguna.
 *
 * ## `activa`, que no es `deleted_at`
 *
 * Una mesa se apaga cuando se acaba el papel, se cae la red o el aula se
 * necesita para otra cosa, y se vuelve a encender media hora después. Eso no es
 * borrar: `activa = 0` conserva la mesa, su equipo y sus grupos, y el acta que
 * cuelgue de ella sigue teniendo sentido. Estas tres tablas **no llevan
 * papelera** a propósito — una mesa borrada no tiene ningún uso posterior, y un
 * `deleted_at` sin trait (o al revés) es exactamente el fallo de la 05 §58.
 *
 * ## LOS ÚNICOS DE LAS DOS PAREJAS
 *
 * `(mesa_id, user_id)` y `(mesa_id, grupo_id)`. Sin ellos, pulsar dos veces en
 * la pantalla mete a la misma persona dos veces en la misma mesa, y el recuento
 * de «quién condujo» pasa a depender de cuántas veces se pulsó. Lo cierra la
 * base, no el controlador.
 *
 * ## VOLVER ATRÁS
 *
 * `down()` tira las tres. Se pierde cómo estaban montadas las mesas —no un
 * voto: el voto vive en `vt_votos`, y su `mesa_id` lo quita su propia
 * migración—. Con el permiso del 22 sep 2026 sobre los datos de votaciones, y
 * sin tocar ninguna otra tabla del sistema.
 */
class LasMesasDeVotacion extends Migration
{
    public function up()
    {
        if (Schema::hasTable('vt_mesas')) {
            echo "  vt_mesas: ya existe, no se toca.\n";
        } else {
            Schema::create('vt_mesas', function (Blueprint $tabla) {
                $tabla->increments('id');

                $tabla->unsignedInteger('votacion_id');

                /*
                 * Lo que el colegio escribe en un papel y pega en la puerta:
                 * «Mesa 3 — Sala de sistemas». 120 caracteres es de sobra y el
                 * tope evita que alguien pegue un párrafo.
                 */
                $tabla->string('nombre', 120);

                // Se apaga y se enciende durante la jornada. Ver la cabecera.
                $tabla->boolean('activa')->default(true);

                $tabla->timestamps();

                /*
                 * La lectura es siempre «las mesas de esta elección», así que el
                 * índice va por ahí. No es único: un colegio puede tener dos
                 * mesas con el mismo nombre y eso no rompe nada.
                 */
                $tabla->index('votacion_id', 'vt_mesas_de_la_votacion');

                $tabla->foreign('votacion_id')->references('id')->on('vt_votaciones')->onDelete('cascade');
            });
        }

        if (Schema::hasTable('vt_mesa_usuarios')) {
            echo "  vt_mesa_usuarios: ya existe, no se toca.\n";
        } else {
            Schema::create('vt_mesa_usuarios', function (Blueprint $tabla) {
                $tabla->increments('id');

                $tabla->unsignedInteger('mesa_id');

                /*
                 * `users.id` y no `profesores.id`: quien conduce una mesa puede
                 * ser una cuenta de secretaría, que no tiene ficha en
                 * `profesores`. Es la misma razón por la que `vt_votos.user_id`
                 * apunta a `users`.
                 */
                $tabla->unsignedInteger('user_id');

                $tabla->timestamps();

                $tabla->unique(['mesa_id', 'user_id'], 'vt_mesa_usuarios_unico');

                $tabla->foreign('mesa_id')->references('id')->on('vt_mesas')->onDelete('cascade');
                $tabla->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            });
        }

        if (Schema::hasTable('vt_mesa_grupos')) {
            echo "  vt_mesa_grupos: ya existe, no se toca.\n";

            return;
        }

        Schema::create('vt_mesa_grupos', function (Blueprint $tabla) {
            $tabla->increments('id');

            $tabla->unsignedInteger('mesa_id');
            $tabla->unsignedInteger('grupo_id');

            $tabla->timestamps();

            $tabla->unique(['mesa_id', 'grupo_id'], 'vt_mesa_grupos_unico');

            $tabla->foreign('mesa_id')->references('id')->on('vt_mesas')->onDelete('cascade');
            $tabla->foreign('grupo_id')->references('id')->on('grupos')->onDelete('cascade');
        });
    }

    public function down()
    {
        // En orden inverso: las dos hijas antes que la madre, porque sus claves
        // ajenas apuntan a `vt_mesas`.
        Schema::dropIfExists('vt_mesa_grupos');
        Schema::dropIfExists('vt_mesa_usuarios');
        Schema::dropIfExists('vt_mesas');
    }
}
