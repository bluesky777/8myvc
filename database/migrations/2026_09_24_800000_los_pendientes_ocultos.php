<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * **Los pendientes que cada usuario pospuso o silenció** (`myvc_front/PLAN-COSAS-PENDIENTES.md` §3).
 *
 * Un pendiente se calcula siempre y desaparece solo cuando el dato deja de faltar; nadie lo
 * marca como hecho. Lo único que se guarda es que **esta persona** no quiere verlo:
 *
 *   - `hasta` con fecha: pospuesto (7 días), vuelve solo ese día;
 *   - `hasta` en NULL: silenciado. La `clave` lleva el año (`jefes_de_area:y=8`), así que
 *     «por el año» sale solo: el año siguiente es otra clave.
 *
 * La clave lleva el caso (`entrega_boletines:p=41`), así que ocultar el periodo 3 no oculta el 4.
 * Un pendiente Firme no se puede ocultar: lo impide el controlador, no esta tabla.
 *
 * Aditiva: tabla nueva, no toca ninguna fila existente. `down()` la borra.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pendientes_ocultos')) {
            return;
        }

        Schema::create('pendientes_ocultos', function (Blueprint $tabla) {
            $tabla->increments('id');
            $tabla->integer('user_id');
            $tabla->string('clave', 120);
            $tabla->dateTime('hasta')->nullable();
            $tabla->timestamps();

            $tabla->unique(['user_id', 'clave']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pendientes_ocultos');
    }
};
