<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * EL ROL DE TESORERO, que no existía. Pedido por Joseth el 24 sep 2026: quien quede nombrado tesorero
 * del año (`years.tesorero_id`) tiene que poder hacer lo del tesorero aunque su usuario sea de
 * docente, y también tiene que poder dársele a mano a alguien que SÓLO es tesorero.
 *
 * Sólo añade una fila, y sólo si no está: no toca `role_user` de nadie. Los nombrados reciben el
 * rol por `Role::getUserRoles`, mientras dure el nombramiento, sin que se escriba nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        $ya = DB::table('roles')->where('name', 'Tesorero')->exists();

        if (! $ya) {
            DB::table('roles')->insert([
                'name' => 'Tesorero',
                'display_name' => 'Tesorero(a)',
                'description' => 'Cartera y paz y salvo, y aprueba las colillas de pago de los formularios de inscripción.',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // A propósito vacío: bajar esta migración le quitaría el rol a quien se le haya dado a mano.
    }
};
