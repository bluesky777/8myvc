<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * **Cerrar un periodo deja fuera de la cuenta lo no calificado, en los dieciséis.**
 * Pedido por Joseth el 24 sep 2026, después de que en quibdo dos cierres pusieran a 0
 * 17.341 casillas vacías —3.094 de ellas vaciadas a propósito por los docentes—.
 *
 * ESTA MIGRACIÓN REESCRIBE FILAS, y a sabiendas: el `DEFAULT 'cero'` de
 * `2026_09_20_600000` dejó `cero` escrito en todos los años, así que cambiar sólo el
 * valor de fábrica no movería a ningún colegio. Toca únicamente `years` —unas pocas filas
 * por colegio—; ni `periodos` —lo que se hizo en cada cierre se queda— ni `notas`.
 * `bloquear` se respeta: ése sólo pudo ponerlo alguien a propósito.
 *
 * El `down()` devuelve el valor de fábrica, no las filas: tras esto no se sabe qué año
 * estaba en `cero` por fábrica y cuál por elección, y en ninguno había pantalla para elegirlo.
 */
return new class extends Migration
{
    public function up()
    {
        DB::statement("ALTER TABLE years ALTER COLUMN cierre_sin_calificar SET DEFAULT 'fuera'");
        DB::update("UPDATE years SET cierre_sin_calificar = 'fuera' WHERE cierre_sin_calificar = 'cero'");
    }

    public function down()
    {
        DB::statement("ALTER TABLE years ALTER COLUMN cierre_sin_calificar SET DEFAULT 'cero'");
    }
};
