<?php

namespace App\Support;

use App\User;
use Illuminate\Support\Facades\DB;

/**
 * Las unidades y las subunidades que `Unidad::deAsignaturaCalculada()` pedía por
 * alumno × asignatura, pedidas una vez por grupo y periodo (docs/migracion/48). En un
 * grupo de 38 con 12 asignaturas eran 2 × 456 consultas, más 456 de escalas en el
 * formato 2; ahora son tres.
 *
 * **Devuelve las mismas filas, con las mismas columnas y en el mismo orden** que las
 * dos consultas de allí:
 *
 *  - las unidades se traen **de todos los alcances a la vez** (las del grupo, con
 *    `alumno_id` nulo, y las de cada independiente) y se reparten por
 *    `(asignatura, alcance)`; el `order by u.orden, u.id` es el mismo y es total.
 *  - las subunidades son la consulta de `Subunidad::deLasUnidadesCalculadas()` con el
 *    alumno sacado de una lista en vez de un `?`. Cada alumno recibe **todas** las
 *    subunidades de las unidades que se le piden, tenga nota o no, que es lo que hacía
 *    el `LEFT JOIN` por alumno.
 *
 * Lo que no sabe responder —otro periodo, otro año, un alumno o una asignatura que no
 * estaban en la lista— lo devuelve `null`, y el llamante va por el camino de siempre.
 * Las filas de unidad salen clonadas en cada llamada porque el llamante les escribe
 * encima (`nota_unidad`, `subunidades`, la escala).
 */
final class LasUnidadesDelGrupo
{
    /** @var array<string, list<object>>  "asignatura|alcance" => sus unidades */
    private array $unidades = [];

    /** @var array<string, list<object>>  "alumno|unidad" => sus subunidades */
    private array $subunidades = [];

    /** @var array<int, true>  las unidades traídas, para no responder por una que no */
    private array $unidadesTraidas = [];

    /** @var array<int, true> */
    private array $alumnos = [];

    /** @var array<int, true> */
    private array $asignaturas = [];

    private ?array $escalas = null;

    /**
     * @param  list<int>  $alumnoIds
     * @param  list<int>  $asignaturaIds
     */
    public function __construct(array $alumnoIds, array $asignaturaIds, private int $periodoId, private int $yearId)
    {
        if ($alumnoIds === [] || $asignaturaIds === []) {
            return;
        }

        $this->alumnos = array_fill_keys(array_map('intval', $alumnoIds), true);
        $this->asignaturas = array_fill_keys(array_map('intval', $asignaturaIds), true);

        $filas = DB::select(
            'SELECT u.id as unidad_id, u.definicion as definicion_unidad, u.porcentaje as porcentaje_unidad,
                    u.asignatura_id, u.orden as orden_unidad, u.periodo_id, u.alumno_id as alcance_de_reparto
               FROM unidades u
              where u.asignatura_id IN ('.implode(',', array_fill(0, count($this->asignaturas), '?')).')
                and u.periodo_id=? and u.deleted_at is null
              order by u.orden, u.id',
            array_merge(array_keys($this->asignaturas), [$periodoId])
        );

        $unidadIds = [];

        foreach ($filas as $fila) {
            $clave = (int) $fila->asignatura_id.'|'.($fila->alcance_de_reparto === null ? '' : (int) $fila->alcance_de_reparto);
            unset($fila->alcance_de_reparto);
            $this->unidades[$clave][] = $fila;
            $unidadIds[] = (int) $fila->unidad_id;
            $this->unidadesTraidas[(int) $fila->unidad_id] = true;
        }

        if ($unidadIds === []) {
            return;
        }

        $modo = RepartoDeLaNota::modoDelAnio($yearId);

        // El alumno sale de una lista de literales y no de `alumnos`: si la fila del
        // alumno faltara, el `LEFT JOIN` de antes le seguía dando sus subunidades vacías.
        $lista = implode(' UNION ALL ', array_map(fn ($id) => 'SELECT '.(int) $id.' AS id', array_keys($this->alumnos)));

        $filas = DB::select(
            'SELECT s.unidad_id, n.id as nota_id, s.id as subunidad_id, s.definicion as definicion_subunidad, s.porcentaje as porcentaje_subunidad,
                    s.nota_default, s.orden as orden_subunidad, s.inicia_at, s.finaliza_at, '.RepartoDeLaNota::valorDeLaNota($modo).' as valor_nota, n.nota, e.desempenio,
                    n.nota_original, n.nota_nivelacion, n.nivelada_at, n.nivelacion_obs,
                    s.definicion, s.porcentaje, e.desempenio, IF(n.nota<?, "nota-perdida-bold", "") as clase_perdida, n.nota,
                    al.id as alumno_de_reparto
               FROM subunidades s
              inner join ('.$lista.') al
              left join notas n ON n.subunidad_id=s.id and n.deleted_at is null and n.alumno_id=al.id
              left join escalas_de_valoracion e ON e.porc_inicial<=n.nota and n.nota < e.porc_final + 1 and e.deleted_at is null and e.year_id=?
              where s.unidad_id IN ('.implode(',', array_fill(0, count($unidadIds), '?')).') and s.deleted_at is null
              order by al.id, s.unidad_id, s.orden',
            array_merge([User::$nota_minima_aceptada, $yearId], $unidadIds)
        );

        foreach ($filas as $fila) {
            $clave = (int) $fila->alumno_de_reparto.'|'.(int) $fila->unidad_id;
            unset($fila->alumno_de_reparto);
            $this->subunidades[$clave][] = $fila;
        }
    }

    /**
     * Las unidades de la asignatura para ese alcance (`null`, las del grupo), clonadas.
     *
     * @return list<object>|null
     */
    public function unidades(int $asignaturaId, ?int $alcance, int $periodoId, int $yearId): ?array
    {
        if (! $this->responde($periodoId, $yearId) || ! isset($this->asignaturas[$asignaturaId])
            || ($alcance !== null && ! isset($this->alumnos[$alcance]))) {
            return null;
        }

        return array_map(static fn ($u) => clone $u, $this->unidades[$asignaturaId.'|'.($alcance ?? '')] ?? []);
    }

    /**
     * Lo de `Subunidad::deLasUnidadesCalculadas()`: unidad_id => sus subunidades.
     *
     * @param  list<int>  $unidadIds
     * @return array<int, list<object>>|null
     */
    public function subunidades(int $alumnoId, array $unidadIds, int $yearId): ?array
    {
        if ($yearId !== $this->yearId || ! isset($this->alumnos[$alumnoId])) {
            return null;
        }

        $porUnidad = [];

        foreach ($unidadIds as $unidadId) {
            if (! isset($this->unidadesTraidas[$unidadId])) {
                return null;
            }

            if (isset($this->subunidades[$alumnoId.'|'.$unidadId])) {
                $porUnidad[$unidadId] = $this->subunidades[$alumnoId.'|'.$unidadId];
            }
        }

        return $porUnidad;
    }

    /** Las escalas del año, como `Unidad::escalasDelAnio()`, una vez por petición. */
    public function escalas(int $yearId): ?array
    {
        if ($yearId !== $this->yearId) {
            return null;
        }

        return $this->escalas ??= array_values(DB::select(
            'SELECT * FROM escalas_de_valoracion
              WHERE year_id = ? AND deleted_at IS NULL
              ORDER BY porc_inicial, id',
            [$yearId]
        ));
    }

    private function responde(int $periodoId, int $yearId): bool
    {
        return $periodoId === $this->periodoId && $yearId === $this->yearId;
    }
}
