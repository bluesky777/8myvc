<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * LA CAMPANA DE LAS ACTIVIDADES  *(26 sep 2026, tanda 5 del contrato de actividades)*.
 *
 * Tres cosas, las tres aditivas y con guarda (corre dos veces seguidas sin error):
 *
 * 1. `ws_avisos.clase` gana el valor `entregada` (la entrega nueva de una tarea, al docente). El
 *    contrato no la listaba y el aviso sí se pidió. Añadir un valor al final de un `enum` no toca
 *    ninguna fila: las que hay siguen valiendo lo mismo.
 * 2. `ws_avisos_leidos`: hasta qué aviso leyó cada usuario en la campana web. Una fila por usuario
 *    con el último `id` leído — la marca, igual que la de `EnviarNotificaciones`—, no una fila por
 *    aviso: marcar leído es «todo hasta aquí».
 * 3. Índices de `ws_avisos` por `user_id` y `alumno_id`: la campana busca por los dos.
 */
return new class extends Migration
{
    private const CLASES = ['publicada', 'recordatorio', 'por_cerrar', 'por_aprobar', 'aprobada',
        'rechazada', 'calificada', 'resultados', 'nota_cambiada', 'entregada'];

    public function up(): void
    {
        if (Schema::hasTable('ws_avisos')) {
            $tipo = DB::selectOne(
                "SELECT column_type AS t FROM information_schema.columns
                  WHERE table_schema = DATABASE() AND table_name = 'ws_avisos' AND column_name = 'clase'"
            );

            if ($tipo && ! str_contains((string) $tipo->t, "'entregada'")) {
                $valores = implode(',', array_map(fn ($c) => "'".$c."'", self::CLASES));
                DB::statement("ALTER TABLE ws_avisos MODIFY clase ENUM($valores) NOT NULL");
            }

            foreach (['user_id', 'alumno_id'] as $columna) {
                if (! Schema::hasIndex('ws_avisos', 'ws_avisos_'.$columna.'_index')) {
                    Schema::table('ws_avisos', fn (Blueprint $t) => $t->index($columna));
                }
            }
        }

        if (! Schema::hasTable('ws_avisos_leidos')) {
            Schema::create('ws_avisos_leidos', function (Blueprint $t) {
                $t->unsignedInteger('user_id')->primary();
                $t->unsignedBigInteger('hasta_id')->default(0);
                $t->timestamp('updated_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ws_avisos_leidos');
    }
};
