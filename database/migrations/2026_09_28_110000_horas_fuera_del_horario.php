<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * `asignaturas.horas_fuera_del_horario`: cuántas horas de la IH se dictan en OTRA
 * jornada —tarde, noche, fin de semana— y no entran en el horario oficial. Pedido
 * por Joseth el 28 sep 2026, para el programa de horarios.
 *
 * ## El caso
 *
 * Física de Décimo tiene IH 4 (`creditos`), pero tres de esas horas se dan en la
 * jornada de la tarde. En el horario de la mañana se cuadra UNA. Sin saberlo, el
 * programa de horarios busca cuatro casillas que no existen, y la subida de la
 * versión la cuenta como «incompleta» con 1 de 4 cuando está entera.
 *
 * ## Por qué aquí y no en el programa de horarios
 *
 * Decisión de Joseth: el dato vive en MyVC para que **sobreviva a reimportar** —si
 * viviera sólo en el proyecto de escritorio, cada importación lo borraría— y para
 * que **se copie al crear el año** (`YearsController::postStore`), igual que
 * `creditos`. Lo edita la pantalla de Asignaturas por `PUT asignaturas/update/{id}`
 * y lo leen `GET asignaturas` y la subida de horarios, que compara la suma de horas
 * colocadas contra `creditos - horas_fuera_del_horario`.
 *
 * ## La forma
 *
 * `NOT NULL DEFAULT 0` y no anulable, al revés que `creditos`: aquí no hay un
 * «nadie lo ha decidido» distinto de «ninguna»; una asignatura que no sabe de
 * jornadas de fuera tiene cero horas fuera, y eso es exactamente lo que ya se
 * asumía antes de que la columna existiera. Así la migración no cambia el
 * significado de ninguna fila. `tinyint unsigned` porque la IH semanal de una
 * asignatura no pasa de unas decenas; el tope real (0..creditos) lo pone el
 * endpoint con un 422 legible, no la columna.
 *
 * Al FINAL de la tabla, como `grupos.ih` y `grupos.juntos_con`: sólo añade, y en
 * MariaDB 10.5 una columna al final con defecto constante es instantánea.
 */
class HorasFueraDelHorario extends Migration
{
    public function up()
    {
        Schema::table('asignaturas', function (Blueprint $tabla) {
            $tabla->unsignedTinyInteger('horas_fuera_del_horario')->default(0);
        });
    }

    public function down()
    {
        Schema::table('asignaturas', function (Blueprint $tabla) {
            $tabla->dropColumn('horas_fuera_del_horario');
        });
    }
}
