<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * `grupos.juntos_con`: qué grupos van SIEMPRE juntos. Pedido por Joseth el 28 sep
 * 2026, para el programa de horarios.
 *
 * ## El caso
 *
 * Prejardín, Jardín y Transición de lalvirtual reciben todas las clases a la vez,
 * con la misma maestra y en la misma aula; Segundo y Segundo B igual. El docente
 * puede cambiar de una materia a otra —edu física, informática—, pero los grupos
 * no se separan nunca. Sin saberlo, el horario le cuenta a esa maestra 63 h en una
 * semana de 25 casillas y sale «imposible».
 *
 * ## La forma: el id del grupo ancla
 *
 * «Siempre juntos» es una partición: un grupo está en un conjunto o en ninguno. Por
 * eso basta una columna: todos los del conjunto guardan el mismo número —el id del
 * grupo más bajo, que el endpoint elige— y `NULL` es «va solo». Un conjunto de uno
 * no existe: `GruposController::putJuntos` y `putSoltar` lo vacían.
 *
 * Sin clave foránea a propósito: los grupos se borran en blando, y una FK con
 * `SET NULL` no se enteraría. Quien lee filtra `deleted_at`.
 *
 * Al FINAL de la tabla y anulable, por lo mismo que `grupos.ih`
 * (`2026_09_07_100000_grupos_con_ih`): sólo añade, no toca una fila.
 */
class GruposJuntos extends Migration
{
    public function up()
    {
        Schema::table('grupos', function (Blueprint $tabla) {
            $tabla->integer('juntos_con')->unsigned()->nullable();
        });
    }

    public function down()
    {
        Schema::table('grupos', function (Blueprint $tabla) {
            $tabla->dropColumn('juntos_con');
        });
    }
}
