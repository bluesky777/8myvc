<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * El acta de la recuperación del año. Tarea A9, contrato en
 * `docs/migracion/22-nivelaciones.md` §6 y §9.
 *
 * ## Esta tabla ya hacía lo difícil, y le faltaba lo fácil
 *
 * `recuperacion_final` es **el único sitio del proyecto que ya guardaba la nota de
 * la recuperación aparte** en vez de pisar la original: por eso el plan (§3.3) dice
 * que del nivel del año «sólo falta la pantalla». Lo que no tiene es el acta —
 * cuándo, quién y con qué actividad—, y el art. 16 del 1290 pide «las novedades
 * académicas»: una novedad sin fecha ni responsable no es una novedad.
 *
 * Las tres nacen `NULL`. **No se rellenan hacia atrás**: las recuperaciones ya
 * escritas se quedan sin acta, porque `updated_by` dice quién la tocó la última vez
 * y `updated_at` cuándo, y **no es lo mismo** que quién registró la recuperación.
 * Copiar uno en otro sería inventar un acta, que es exactamente lo que el §6.6 del
 * reparto prohíbe hacer desde `bitacoras`.
 *
 * ## `year` sigue siendo el número, y eso no se toca aquí
 *
 * `recuperacion_final.year` guarda el **número** del año y no el id, y convertirlo
 * es un refactor de permisos ya analizado y decidido en `PeriodoDeLaFila`
 * (`todosLosDelAnio`, y de ahí sale que esta tabla exija **todos** los periodos
 * abiertos en vez de uno). El plan lo deja fuera a propósito (§7) y esta migración
 * también: mezclarlo convertiría tres columnas de acta en un cambio de claves.
 *
 * `observacion` y no `nivelacion_obs` como en las otras dos tablas: aquí la fila
 * **entera** es la recuperación, así que el prefijo no distingue nada de nada. En
 * `notas` y `notas_finales` sí, porque la fila existe antes de la nivelación.
 */
class ActaDeLaRecuperacionFinal extends Migration
{
    public function up()
    {
        // Una sola llamada, un solo `ALTER`: en MySQL 5.7 cada sentencia
        // reconstruye la tabla. `recuperacion_final` es pequeña, pero el criterio
        // no depende del tamaño, y quien copie este fichero para otra tabla se
        // lleva el criterio con él.
        Schema::table('recuperacion_final', function (Blueprint $tabla) {
            $tabla->dateTime('nivelada_at')->nullable()->default(null)->after('nota');
            $tabla->integer('nivelada_por')->nullable()->default(null)->after('nivelada_at');
            $tabla->string('observacion', 255)->nullable()->default(null)->after('nivelada_por');
        });
    }

    public function down()
    {
        Schema::table('recuperacion_final', function (Blueprint $tabla) {
            $tabla->dropColumn(['nivelada_at', 'nivelada_por', 'observacion']);
        });
    }
}
