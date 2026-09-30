<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * EL ÍNDICE DE LOS LISTADOS DE AUDITORÍA DE ALUMNOS (`auditoria/alumnos/{familia}`).
 *
 * Sólo índices: no toca ni una fila.
 *
 * - **Añade `aud_entidad_fecha (entidad, ocurrido_en, alumno_id, actor_user_id)`.** El
 *   listado filtra siempre por un puñado de entidades (las de la familia) y ordena por
 *   fecha. Con `(entidad, ocurrido_en)` la página y el `COUNT` salen del índice; las dos
 *   columnas de detrás son para que el `resumen` —alumnos y actores distintos sobre todo
 *   el filtro— y el desplegable de actores también salgan de él, sin ir a la fila por
 *   cada una de los cientos de miles de líneas. `id` no hace falta ponerlo: InnoDB lo
 *   cuelga de todo índice secundario, y es el desempate del orden.
 * - **Quita `aud_sesion (sesion_id, id)`.** No lo usa ninguna consulta: `grep` sobre
 *   `app/` no da ni un `WHERE` por `sesion_id` contra `auditoria` —el detalle de un
 *   ingreso va por `historial_id`—. Se quita para que la tabla no crezca en índices.
 *   `aud_actor` se queda: lo usa este mismo listado con el filtro «Cambiado por».
 */
class IndiceDeLosListadosDeAuditoria extends Migration
{
    public function up()
    {
        Schema::table('auditoria', function (Blueprint $tabla) {
            $tabla->index(['entidad', 'ocurrido_en', 'alumno_id', 'actor_user_id'], 'aud_entidad_fecha');
            $tabla->dropIndex('aud_sesion');
        });
    }

    public function down()
    {
        Schema::table('auditoria', function (Blueprint $tabla) {
            $tabla->index(['sesion_id', 'id'], 'aud_sesion');
            $tabla->dropIndex('aud_entidad_fecha');
        });
    }
}
