<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * QUIÉN YA RESPONDIÓ  *(26 sep 2026, contrato §1.4 y §2.6)*.
 *
 * El «ya respondió» de una actividad, **separado de las respuestas y sin nada que las una**. Es lo
 * que permite la encuesta «anónima sabiendo quién falta»: aquí se guarda que X respondió —para
 * recordarle y para no dejarle responder dos veces— y la hoja con sus respuestas no lleva su id.
 *
 * **Sin `id` autoincremental y sin `created_at`, a propósito.** Con cualquiera de los dos, el orden
 * de inserción o la hora emparejarían esta fila con la hoja anónima escrita en el mismo instante.
 * La clave primaria `(actividad_id, user_id, por_alumno_id)` ordena físicamente por persona, y
 * `dia` sólo guarda el día.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ws_participaciones')) {
            return;
        }

        Schema::create('ws_participaciones', function (Blueprint $t) {
            $t->unsignedInteger('actividad_id');
            $t->unsignedInteger('user_id');
            // El hijo en «una vez por hijo»; el propio alumno; 0 = no aplica.
            $t->unsignedInteger('por_alumno_id')->default(0);
            $t->date('dia');

            $t->primary(['actividad_id', 'user_id', 'por_alumno_id']);
            $t->foreign('actividad_id')->references('id')->on('ws_actividades')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ws_participaciones');
    }
};
