<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * **Cómo quedó la plantilla la última vez que se propagó** (`myvc_front/PLAN-COSAS-PENDIENTES.md`
 * §2.3).
 *
 * Cada `PUT plantilla-notas/sembrar` guarda aquí las filas vivas de `unidades_por_defecto` y
 * `subunidades_por_defecto` del año, en JSON. Comparar la plantilla de hoy con la última foto es
 * lo que dice «hay cambios sin propagar», y volver a la foto es «Descartar los cambios».
 *
 * Sin foto no se sabe nada: el colegio que nunca propagó después de este despliegue no ve el
 * pendiente ni puede descartar. Tomar una foto aquí, al migrar, daría por propagado lo que quizá
 * no lo está.
 *
 * Aditiva: tabla nueva, no toca ninguna fila existente. `down()` la borra.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('plantilla_fotos')) {
            return;
        }

        Schema::create('plantilla_fotos', function (Blueprint $tabla) {
            $tabla->increments('id');
            $tabla->integer('year_id');
            $tabla->longText('foto');
            $tabla->integer('tomada_por')->nullable();
            $tabla->timestamps();

            $tabla->index('year_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plantilla_fotos');
    }
};
