<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Papelera para las frases del boletín de preescolar.
 *
 * `frases_preescolar` era la única tabla de contenido de este módulo sin `deleted_at`, así que
 * `bolfinales-preescolar/eliminar-frase` hacía un `DELETE` físico: la frase se iba y no había de
 * dónde sacarla. Lo que se borra ahí es texto que escribió un profesor y que sale impreso en el
 * boletín de un niño de preescolar — en ese boletín no hay notas, hay frases.
 *
 * El daño no era el borrado en sí sino la expectativa: en el resto del sistema todo va a la
 * papelera y se restaura, así que quien pulsa «eliminar» aquí cree que puede deshacerlo. Decidido
 * por Joseth el 21 ago 2026, con la medición delante (docs/migracion/14-certificados.md §7.2).
 *
 * Va como columna y no como tabla de histórico porque es exactamente lo que hacen las otras
 * noventa: el proyecto entero usa `deleted_at` y `SoftDeletes`, y una papelera distinta aquí
 * obligaría a mirar dos sitios el día que alguien pregunte qué se borró.
 *
 * El sello horario va detrás del de `add_username_to_password_reminders` a propósito: compartir
 * timestamp deja el orden de ejecución a merced de cómo el sistema de archivos devuelva los
 * nombres.
 */
class AddDeletedAtToFrasesPreescolarTable extends Migration
{
    public function up()
    {
        Schema::table('frases_preescolar', function (Blueprint $table) {
            $table->softDeletes()->after('updated_by');
        });
    }

    public function down()
    {
        Schema::table('frases_preescolar', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
}
