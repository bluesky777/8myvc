<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * LAS CONDICIONES DE LAS PREGUNTAS  *(26 sep 2026, contrato §1.3 y §2.5)*.
 *
 * «Mostrar esta pregunta sólo si Pn es / no es / contiene X». Una fila por condición; las de la
 * misma `grupo` se cumplen juntas (Y) y basta con que se cumpla un grupo (O). `depende_de_id`
 * siempre es una pregunta ANTERIOR —lo exige el controlador—, y eso es lo que impide los ciclos.
 *
 * Tabla nueva: sólo añade. Las FK son hacia tablas `ws_*` y en cascada: borrar la pregunta, o la
 * opción de la que depende, se lleva la condición.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ws_condiciones')) {
            return;
        }

        Schema::create('ws_condiciones', function (Blueprint $t) {
            $t->increments('id');
            $t->unsignedInteger('pregunta_id');
            $t->unsignedTinyInteger('grupo')->default(1);
            $t->unsignedInteger('depende_de_id');
            $t->enum('operador', ['es', 'no_es', 'contiene']);
            $t->unsignedInteger('opcion_id')->nullable();
            $t->string('valor', 200)->nullable();
            $t->timestamps();

            $t->index('pregunta_id');
            $t->index('depende_de_id');

            $t->foreign('pregunta_id')->references('id')->on('ws_preguntas')->cascadeOnDelete();
            $t->foreign('depende_de_id')->references('id')->on('ws_preguntas')->cascadeOnDelete();
            $t->foreign('opcion_id')->references('id')->on('ws_opciones')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ws_condiciones');
    }
};
