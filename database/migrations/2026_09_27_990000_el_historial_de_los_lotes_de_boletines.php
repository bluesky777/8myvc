<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * EL HISTORIAL DE «BOLETINES DE OTROS COLEGIOS»  *(27 sep 2026, pedido)*: qué lote se aplicó, quién,
 * cuándo, por qué puerta, y qué pasó con cada boletín (creado, reemplazado, combinado, dejado,
 * omitido; y si se creó el alumno). `myvc_front/NOTAS-DE-OTRO-COLEGIO.md` §11.
 *
 * Una fila por lote, con el detalle en `boletines` (JSON en texto: MariaDB y MySQL lo leen igual).
 * No es la auditoría fila a fila --eso sería una línea por nota--: es lo que se quiere leer después,
 * «a quiénes se importó y qué».
 *
 * Sólo añade: una tabla nueva.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('lotes_de_boletines')) {
            return;
        }

        Schema::create('lotes_de_boletines', function (Blueprint $tabla) {
            $tabla->increments('id');
            $tabla->unsignedInteger('user_id')->nullable();
            /* propia (IA personal) | myvc (la IA de MyVc) | guion (tools/boletines-en-lote.mjs) */
            $tabla->string('puerta', 12)->nullable();
            $tabla->unsignedSmallInteger('creados')->default(0);
            $tabla->unsignedSmallInteger('reemplazados')->default(0);
            $tabla->unsignedSmallInteger('combinados')->default(0);
            $tabla->unsignedSmallInteger('conservados')->default(0);
            $tabla->unsignedSmallInteger('omitidos')->default(0);
            $tabla->unsignedSmallInteger('alumnos_nuevos')->default(0);
            $tabla->unsignedInteger('notas')->default(0);
            $tabla->longText('boletines');
            $tabla->timestamp('created_at')->nullable();

            $tabla->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lotes_de_boletines');
    }
};
