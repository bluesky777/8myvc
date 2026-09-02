<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Las dos columnas que le faltaban al acta de la definitiva del periodo. Tarea A8,
 * contrato en `docs/migracion/22-nivelaciones.md` §6.
 *
 * ## Por qué no fueron con las tres de la migración de las 10:00
 *
 * Porque el plan (§3.3) sólo pedía tres para `notas_finales` —`nota_original`,
 * `nivelada_at`, `nivelada_por`— y ésas entraron en
 * `2026_09_02_100000_nivelaciones_columnas`. Al escribir el endpoint se ve que el
 * mismo argumento que justificó `notas.nota_nivelacion` vale igual aquí, y por eso
 * va en su propia migración con su propia decisión escrita en vez de colarse en la
 * anterior:
 *
 * - **`nota_nivelacion`** — bajo la regla `topada`, una definitiva de 28 que
 *   niveló 45 queda en 35, y sin esta columna **el 45 no está en ninguna parte**.
 *   Es el art. 16 del 1290 otra vez: «el estado de la evaluación con sus
 *   novedades». Una novedad cuyo resultado no se guarda no está registrada.
 * - **`nivelacion_obs`** — con qué actividad se superó. `notas` la tiene y la
 *   constancia de desempeño se imprime desde los dos niveles; que el indicador
 *   pueda decir «taller de refuerzo» y la asignatura no, es una asimetría que
 *   aparecería el día de imprimir, no hoy.
 *
 * `recuperada` **sigue significando lo mismo** (`1` ⇔ viene de una nivelación) y
 * `manual` también. Lo que se gana es que ahora la fila puede decir de dónde venía
 * y con qué.
 *
 * ## El mismo cuidado que la anterior
 *
 * Una sola llamada, un solo `ALTER`: en MySQL 5.7 cada sentencia reconstruye la
 * tabla, y aunque `notas_finales` es mucho menor que `notas`, el criterio no cambia
 * con el tamaño — cambia con el número de sentencias. Y las dos nacen `NULL`, así
 * que **ningún lector existente se entera**: `nota` sigue siendo la vigente.
 */
class NivelacionDeLaDefinitiva extends Migration
{
    public function up()
    {
        Schema::table('notas_finales', function (Blueprint $tabla) {
            // `decimal(7,4)` para que case con `nota` y con `nota_original`, que ya
            // lo son desde `2026_08_30_200000_notas_finales_en_decimal`. Guardar la
            // nivelación con menos precisión que la nota que produce sería perder
            // decimales justo en la comparación que el boletín imprime.
            $tabla->decimal('nota_nivelacion', 7, 4)->nullable()->default(null)->after('nota_original');
            $tabla->string('nivelacion_obs', 255)->nullable()->default(null)->after('nivelada_por');
        });
    }

    /*
     * Quitarlas pierde con qué se nivelaron las definitivas registradas desde el
     * despliegue, y nada más: `nota` nunca dejó de ser la vigente.
     */
    public function down()
    {
        Schema::table('notas_finales', function (Blueprint $tabla) {
            $tabla->dropColumn(['nota_nivelacion', 'nivelacion_obs']);
        });
    }
}
