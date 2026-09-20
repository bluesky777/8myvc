<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * **El día de matrículas**: las estaciones del recorrido y quién cerró cada paso.
 *
 * Fase 1 del proceso de admisión. Decidido por Joseth el 20 sep 2026 con las cuatro
 * preguntas delante; el porqué de cada una está en
 * `docs/migracion/44-el-dia-de-matriculas.md`.
 *
 * ## TRES COLUMNAS, Y LAS QUE NO ENTRAN PESAN IGUAL
 *
 * La propuesta original (`myvc_front/PANTALLAS-MATRICULA.md` §4) pedía **seis** en
 * `requisitos_matricula` —tipo, rol_id, obligatorio, bloquea, estacion_nro,
 * dias_limite— y **cuatro** en `requisitos_alumno`. Entran **una y dos**, y las
 * otras siete no se quedan fuera por recorte: **cada una la cerró una respuesta**.
 *
 *   estacion_nro  NO: «el número impreso ES el `orden` que ya existe». El paso 3 se
 *                 atiende en la estación 3. La columna ya está y la escribe la
 *                 pantalla de configuración.
 *
 *   rol_id        NO: «cualquiera del personal puede cerrar, pero queda con su
 *                 nombre y hora». Un rol por estación habría INVENTADO un concepto
 *                 que cada colegio tendría que configurar **antes** de que el módulo
 *                 sirviera de nada — y el día de matrículas atienden docentes de
 *                 cualquier asignatura.
 *
 *   obligatorio   NO: Joseth describió **un** interruptor —«obligatoria antes de
 *                 continuar u opcional»—, no dos. Ver §2 del 44 para lo que eso deja
 *                 fuera, que está dicho y decidido.
 *
 *   tipo,         NO: no los pidió nadie. Una columna sin pantalla no la escribe
 *   dias_limite   nadie nunca — es `profesores.tono`, y van cuatro en un mes.
 *
 * ## `bloquea` ES POR ESTACIÓN, Y ESO NO ES GRANULARIDAD GRATUITA
 *
 * Es la respuesta a la pregunta que parecía la más floja de las cuatro. *«¿«falta»
 * significa que la familia no entregó, o que nadie lo marcó?»* — **«las dos cosas,
 * según la estación»**.
 *
 * Eso convierte el interruptor en lo único que hace esto desplegable: **el bloqueo
 * no se puede encender de golpe**. En las estaciones donde el dato es fiable se
 * enciende y frena de verdad; en las que nadie marca se deja apagado y sólo informa.
 * Un `bloquea` global —o un interruptor de colegio— habría mandado de vuelta a
 * familias que sí entregaron, **el primer día y en la cola**.
 *
 * Por eso nace en **0**: un colegio que actualiza no puede encontrarse el lunes con
 * un recorrido que frena donde antes no frenaba.
 *
 * ## `cerrado_por` NO es `updated_by`, y por eso son dos
 *
 * `requisitos_alumno` ya tiene `updated_by`/`updated_at`, y la tentación es reusarlos.
 * **Cambian cada vez que se toca la fila**: escribir una observación, corregir una
 * tilde o desmarcar mueven `updated_by` al último que pasó por ahí. Lo que la
 * trazabilidad del día necesita es **quién lo cerró y a qué hora**, que es un hecho
 * que no se repite.
 *
 * Dicho al revés: *`updated_by` es quien tocó esto por última vez; `cerrado_por` es
 * quien lo chuleó.* Son dos preguntas, y por eso son dos columnas.
 *
 * ## Y NINGUNA INSTANTÁNEA SE MUEVE, medido y no supuesto
 *
 * `RequisitosController::putIndex` hace `SELECT *`, así que una columna nueva se
 * reparte sola a la respuesta — es la trampa de `columna-nueva-mas-select-asterisco`.
 * Comprobado con `tools/lo-que-reparte-una-columna.py` sobre las 129 instantáneas:
 *
 *     requisitos_matricula   ->  0 se mueven
 *     requisitos_alumno      ->  0 se mueven
 *
 * Lo que eso significa de verdad **no es «no hay riesgo»**, es que **esas rutas no
 * tienen ninguna instantánea de contrato**. El riesgo no está tapado: no está
 * medido, y por eso la fase 1 trae los suyos.
 */
return new class extends Migration
{
    public function up()
    {
        if (! Schema::hasColumn('requisitos_matricula', 'bloquea')) {
            Schema::table('requisitos_matricula', function (Blueprint $tabla) {
                // «Obligatoria antes de continuar» (1) u «opcional» (0). Nace en 0:
                // actualizar no puede encender frenos que ayer no estaban.
                $tabla->boolean('bloquea')->default(false)->after('orden');
            });
        } else {
            echo "  requisitos_matricula.bloquea: ya existe, no se toca.\n";
        }

        if (! Schema::hasColumn('requisitos_alumno', 'cerrado_por')) {
            Schema::table('requisitos_alumno', function (Blueprint $tabla) {
                $tabla->unsignedInteger('cerrado_por')->nullable()->after('descripcion');
                $tabla->timestamp('cerrado_at')->nullable()->after('cerrado_por');

                // La consulta del recorrido pregunta «¿qué le falta a ESTE alumno?»,
                // y ya hay índice por `alumno_id`. Éste es para la otra mitad: el
                // tablero del día, que cuenta cuántos pasaron por cada estación y
                // cuándo — o sea, dónde está el tapón.
                $tabla->index(['cerrado_at'], 'requisitos_alumno_cerrado_at');
            });
        } else {
            echo "  requisitos_alumno.cerrado_por: ya existe, no se toca.\n";
        }
    }

    public function down()
    {
        if (Schema::hasColumn('requisitos_matricula', 'bloquea')) {
            Schema::table('requisitos_matricula', function (Blueprint $tabla) {
                $tabla->dropColumn('bloquea');
            });
        }

        if (Schema::hasColumn('requisitos_alumno', 'cerrado_por')) {
            Schema::table('requisitos_alumno', function (Blueprint $tabla) {
                $tabla->dropIndex('requisitos_alumno_cerrado_at');
                $tabla->dropColumn(['cerrado_at', 'cerrado_por']);
            });
        }
    }
};
