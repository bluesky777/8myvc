<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * LAS EQUIVALENCIAS DE MATERIAS  *(26 sep 2026)*, `myvc_front/NOTAS-DE-OTRO-COLEGIO.md` §1.2 y §11.
 *
 * Cómo escribe otro colegio una materia --«Matemáticas III», «Castellano»-- y a qué materia de este
 * colegio corresponde. Se aprende en la revisión del lote: cada emparejamiento que confirma quien
 * trae los boletines se guarda, y el siguiente lote lo encuentra sin preguntar.
 *
 * `texto` va NORMALIZADO (`ParecidoDeNombres::normalizar`: sin tildes, minúsculas, espacios
 * simples), y es único: una forma de escribirla apunta a una sola materia.
 *
 * Sólo añade: una tabla nueva, y nada que toque datos que ya existen.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('equivalencias_materias')) {
            return;
        }

        Schema::create('equivalencias_materias', function (Blueprint $tabla) {
            $tabla->increments('id');
            $tabla->string('texto', 160)->unique();
            $tabla->unsignedInteger('materia_id');
            $tabla->unsignedInteger('created_by')->nullable();
            $tabla->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('equivalencias_materias');
    }
};
