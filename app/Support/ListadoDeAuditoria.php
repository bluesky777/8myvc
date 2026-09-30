<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * LOS LISTADOS DE AUDITORÍA DE ALUMNOS: `GET auditoria/alumnos/{familia}` y el
 * desplegable «Cambiado por» (`GET auditoria/alumnos/actores`). La pantalla es la de
 * `myvc_front/docs/auditoria-alumnos/mock.html`.
 *
 * Tres familias —`datos`, `notas`, `convivencia`—, cada una un puñado de entidades
 * de `auditoria` agrupadas en «tipos» que son los chips de la pantalla.
 *
 * ## Cómo se lee sin recorrer la tabla
 *
 * La página se pide en dos tiempos: primero **sólo los `id`** con el filtro, el orden y
 * el `OFFSET` —sale del índice `aud_entidad_fecha` sin tocar la fila—, y después las
 * líneas de esos `id` con `AuditoriaController::lineas()`, que son 25, 50 o 100. El
 * `COUNT` y el `resumen` van en una sola consulta sobre el mismo filtro.
 *
 * Lo que se añade a cada línea (nombres, año, fotos, `hecho_desde`, `hueco`) se busca
 * **sólo para las líneas de la página**, con una consulta por tabla y no por fila.
 *
 * ## `grupo_id` y `asignatura_id` no se leen sólo de la línea
 *
 * Las líneas de nota no traen ni grupo ni asignatura (en la copia de caz, 0 de 17.065):
 * filtrar por la columna a secas daría siempre vacío. El grupo es **el del alumno**
 * —su matrícula en ese grupo, en el año del grupo—, que es lo que enseña la maqueta; la
 * asignatura sale de la fila de la entidad (la nota por su unidad, la definitiva, la
 * nivelación y la ausencia por su columna). La columna de la línea cuenta también, para
 * las filas que ya no existen.
 */
final class ListadoDeAuditoria
{
    /** clave de tipo → entidades de `auditoria`, por familia. El orden es el de los chips. */
    public const FAMILIAS = [
        'datos' => [
            'ficha' => ['alumno'],
            'cuenta' => ['usuario'],
            'matricula' => ['matricula'],
            'acudientes' => ['acudiente', 'parentesco'],
            'antecedentes' => ['antecedente'],
            // No es una entidad de `auditoria`: son las líneas que salen de
            // `importaciones.cambios`, una por alumno cambiado. Ver `deImportaciones()`.
            'importacion' => [self::IMPORTACION],
        ],
        'notas' => [
            'nota' => ['nota'],
            'final' => ['nota_final'],
            'nivelacion' => ['recuperacion_final'],
            'ausencia' => ['ausencia'],
        ],
        'convivencia' => [
            'comportamiento' => ['comportamiento'],
            'situacion' => ['dis_proceso', 'dis_libro_rojo'],
            'ordinal' => ['dis_ordinal'],
            'observacion' => ['frase', 'frase_asignatura', 'frase_preescolar'],
            'compromiso' => ['compromiso', 'compromiso_item'],
        ],
    ];

    /** La entidad de las líneas que salen de `importaciones.cambios`, y su chip. */
    public const IMPORTACION = 'importacion';

    /** Las entidades con valor numérico: las únicas en las que se mira el `hueco`. */
    private const NUMERICAS = ['nota', 'nota_final', 'recuperacion_final'];

    /** De dónde sale la asignatura de cada entidad que la tiene en su fila. */
    private const ASIGNATURA_DE = [
        'nota' => 'SELECT n.id, u.asignatura_id FROM notas n
                     JOIN subunidades s ON s.id = n.subunidad_id
                     JOIN unidades u ON u.id = s.unidad_id',
        'nota_final' => 'SELECT t.id, t.asignatura_id FROM notas_finales t',
        'recuperacion_final' => 'SELECT t.id, t.asignatura_id FROM recuperacion_final t',
        'ausencia' => 'SELECT t.id, t.asignatura_id FROM ausencias t',
        'frase_asignatura' => 'SELECT t.id, t.asignatura_id FROM frases_asignatura t',
    ];

    /**
     * El rol legible del desplegable, sobre los nombres de `roles` que ya usa `Autoriza`.
     * Va en orden de precedencia: quien es docente y coordinador sale como Coordinación.
     */
    private const ROL_LEGIBLE = [
        'Rector' => 'Rectoría',
        'Coord académico' => 'Coordinación',
        'Coord disciplinario' => 'Coordinación',
        'Secretario' => 'Secretaría',
        'Tesorero' => 'Tesorería',
        'Psicólogo' => 'Psicología',
        'Enfermero' => 'Enfermería',
        'Admin' => 'Administración',
    ];

    /** Y, sin ninguno de esos roles, el tipo de la cuenta. */
    private const TIPO_LEGIBLE = [
        'Profesor' => 'Docente',
        'Acudiente' => 'Acudiente',
        'Alumno' => 'Alumno',
        'Usuario' => 'Administración',
    ];

    /** @return list<string> */
    public static function entidadesDe(string $familia): array
    {
        return array_merge(...array_values(self::FAMILIAS[$familia]));
    }

    /**
     * La condición sobre `auditoria a` para el filtro de la pantalla. Lo que no aplica a la
     * familia (la asignatura fuera de notas, año y periodo en datos) se ignora; una clave de
     * tipo que no es de la familia, también — y si no queda ninguna, son todas.
     *
     * @param  array<string, mixed>  $entrada
     * @return array{0: string, 1: list<mixed>}
     */
    public static function filtro(string $familia, array $entrada): array
    {
        // Un parámetro repetido (`tipos[]=…`) llega como lista: no es la forma, y se ignora.
        $texto = fn (string $clave) => is_scalar($entrada[$clave] ?? null) ? trim((string) $entrada[$clave]) : '';
        $entero = fn (string $clave) => max(0, (int) $texto($clave));

        $tipos = self::FAMILIAS[$familia];
        $pedidos = array_values(array_intersect(
            array_map('trim', explode(',', $texto('tipos'))),
            array_keys($tipos)
        ));
        $entidades = $pedidos
            ? array_merge(...array_map(fn ($t) => $tipos[$t], $pedidos))
            : self::entidadesDe($familia);

        $donde = ['a.entidad IN ('.self::marcas($entidades).')'];
        $parametros = $entidades;

        if ($id = $entero('alumno_id')) {
            $donde[] = 'a.alumno_id = ?';
            $parametros[] = $id;
        }

        if ($id = $entero('actor_user_id')) {
            $donde[] = 'a.actor_user_id = ?';
            $parametros[] = $id;
        }

        if ($id = $entero('grupo_id')) {
            // El grupo del alumno en el año del grupo. Una línea sin año (casi ninguna: la
            // escribe el año del usuario) entra si el alumno estuvo alguna vez en ese grupo.
            $donde[] = '(a.grupo_id = ? OR (a.alumno_id IN (
                            SELECT m.alumno_id FROM matriculas m WHERE m.grupo_id = ? AND m.deleted_at IS NULL)
                        AND (a.year_id IS NULL OR a.year_id = (SELECT g.year_id FROM grupos g WHERE g.id = ?))))';
            array_push($parametros, $id, $id, $id);
        }

        if ($familia === 'notas' && ($id = $entero('asignatura_id'))) {
            [$sql, $deAsignatura] = self::porAsignatura($entidades, '= ?', [$id]);
            $donde[] = $sql;
            array_push($parametros, ...$deAsignatura);
        }

        if ($familia !== 'datos') {
            // El año derivado, el mismo que se pinta en `anio`: el de la línea, si no el de
            // su periodo, si no el de su grupo.
            if ($id = $entero('year_id')) {
                $donde[] = '(a.year_id = ? OR (a.year_id IS NULL AND (
                                a.periodo_id IN (SELECT p.id FROM periodos p WHERE p.year_id = ?)
                                OR (a.periodo_id IS NULL AND a.grupo_id IN (SELECT g.id FROM grupos g WHERE g.year_id = ?)))))';
                array_push($parametros, $id, $id, $id);
            }
            if ($id = $entero('periodo_id')) {
                $donde[] = 'a.periodo_id = ?';
                $parametros[] = $id;
            }
        }

        // Las fechas ya vienen validadas por el controlador. `hasta` es inclusiva: se
        // compara con el día siguiente para no cortar a las 00:00:00.
        if (($desde = $texto('desde')) !== '') {
            $donde[] = 'a.ocurrido_en >= ?';
            $parametros[] = $desde.' 00:00:00';
        }
        if (($hasta = $texto('hasta')) !== '') {
            $donde[] = 'a.ocurrido_en < ?';
            $parametros[] = date('Y-m-d', (int) strtotime($hasta.' +1 day')).' 00:00:00';
        }

        $q = $texto('q');
        if ($q !== '') {
            $como = '%'.addcslashes($q, '\\%_').'%';
            $donde[] = '(a.alumno_nombre LIKE ? OR a.alumno_id IN (SELECT al.id FROM alumnos al WHERE al.documento LIKE ?))';
            array_push($parametros, $como, $como);
        }

        return [implode(' AND ', $donde), $parametros];
    }

    /**
     * La condición «la asignatura de la línea cumple `$comparacion`»: la columna de la
     * línea, o la de la fila de su entidad (la nota por su unidad). La usan el filtro de
     * asignatura (`= ?`) y el alcance del docente (`IN (sus asignaturas)`), para que las
     * dos deriven la asignatura igual.
     *
     * @param  list<string>  $entidades
     * @param  list<mixed>  $valores  los parámetros de `$comparacion`
     * @return array{0: string, 1: list<mixed>}
     */
    public static function porAsignatura(array $entidades, string $comparacion, array $valores): array
    {
        $vias = ["a.asignatura_id $comparacion"];
        $parametros = $valores;
        foreach (self::ASIGNATURA_DE as $entidad => $sql) {
            if (! in_array($entidad, $entidades, true)) {
                continue;
            }
            $columna = $entidad === 'nota' ? 'u.asignatura_id' : 't.asignatura_id';
            $vias[] = "(a.entidad = ? AND a.entidad_id IN (SELECT x.id FROM ($sql WHERE $columna $comparacion) x))";
            array_push($parametros, $entidad, ...$valores);
        }

        return ['('.implode(' OR ', $vias).')', $parametros];
    }

    /**
     * Cuántas líneas, y cuántos alumnos y actores distintos, sobre TODO el filtro.
     *
     * @param  list<mixed>  $parametros
     * @return array{total: int, alumnos: int, actores: int}
     */
    public static function totales(string $donde, array $parametros): array
    {
        $fila = DB::selectOne(
            "SELECT COUNT(*) AS total, COUNT(DISTINCT a.alumno_id) AS alumnos,
                    COUNT(DISTINCT a.actor_user_id) AS actores
               FROM auditoria a WHERE $donde",
            $parametros
        );

        return [
            'total' => (int) ($fila->total ?? 0),
            'alumnos' => (int) ($fila->alumnos ?? 0),
            'actores' => (int) ($fila->actores ?? 0),
        ];
    }

    /**
     * Los `id` de una página, en el orden de la pantalla: `ocurrido_en DESC, id DESC`.
     *
     * @param  list<mixed>  $parametros
     * @return list<int>
     */
    public static function pagina(string $donde, array $parametros, int $desde, int $cuantas): array
    {
        return array_values(array_map(fn ($f) => (int) $f->id, DB::select(
            "SELECT a.id FROM auditoria a WHERE $donde
              ORDER BY a.ocurrido_en DESC, a.id DESC
              LIMIT $cuantas OFFSET $desde",
            $parametros
        )));
    }

    /**
     * LAS LÍNEAS DE LA IMPORTACIÓN DE ALUMNOS, que no están en `auditoria`: la importación
     * guarda lo que cambió en su propia fila (`importaciones.cambios`, decisión de Joseth
     * del 30 sep 2026) y aquí se abre en una línea por alumno cambiado, con la misma forma
     * que las de `auditoria` para que la pantalla no las distinga.
     *
     * Se leen enteras y se filtran en PHP porque son pocas —una fila por importación, y
     * los colegios importan a principios de año—. El único filtro en SQL es el que
     * descarta importaciones enteras: quién, cuándo y, con `alumno_id`, si el alumno está
     * dentro del JSON (`JSON_CONTAINS_PATH`, que tienen MySQL 8 y MariaDB 10.5 sobre un
     * `longtext`).
     *
     * El `id` es NEGATIVO y no choca con ninguno de `auditoria`: `-(importación × 10⁷ +
     * alumno)`. `entidad_id` es `null` para que la pantalla no abra el historial de una
     * entidad que no existe; `importacion_id` dice de qué importación sale.
     *
     * Ordenadas como la página: `ocurrido_en DESC`, y dentro, importación y alumno DESC.
     *
     * @param  array<string, mixed>  $entrada
     * @return list<object>
     */
    public static function deImportaciones(array $entrada): array
    {
        $texto = fn (string $clave) => is_scalar($entrada[$clave] ?? null) ? trim((string) $entrada[$clave]) : '';
        $entero = fn (string $clave) => max(0, (int) $texto($clave));

        $pedidos = array_values(array_intersect(
            array_map('trim', explode(',', $texto('tipos'))),
            array_keys(self::FAMILIAS['datos'])
        ));
        if ($pedidos !== [] && ! in_array(self::IMPORTACION, $pedidos, true)) {
            return [];
        }

        $donde = ["i.tipo = 'alumnos'", 'i.cambios IS NOT NULL'];
        $parametros = [];
        $delAlumno = $entero('alumno_id');
        if ($delAlumno) {
            $donde[] = "JSON_CONTAINS_PATH(i.cambios, 'one', ?)";
            $parametros[] = '$."'.$delAlumno.'"';
        }
        if ($id = $entero('actor_user_id')) {
            $donde[] = 'i.created_by = ?';
            $parametros[] = $id;
        }
        if (($desde = $texto('desde')) !== '') {
            $donde[] = 'COALESCE(i.fin, i.inicio) >= ?';
            $parametros[] = $desde.' 00:00:00';
        }
        if (($hasta = $texto('hasta')) !== '') {
            $donde[] = 'COALESCE(i.fin, i.inicio) < ?';
            $parametros[] = date('Y-m-d', (int) strtotime($hasta.' +1 day')).' 00:00:00';
        }

        $importaciones = DB::select(
            'SELECT i.id, i.archivo, i.cambios, i.created_by, y.id AS year_id, u.tipo AS actor_tipo,
                    COALESCE(i.fin, i.inicio) AS ocurrido_en,
                    COALESCE(NULLIF(TRIM(CONCAT(COALESCE(p.nombres, ""), " ", COALESCE(p.apellidos, ""))), ""), u.username) AS actor_nombre
               FROM importaciones i
               LEFT JOIN years y ON y.year = i.year AND y.deleted_at IS NULL
               LEFT JOIN users u ON u.id = i.created_by
               LEFT JOIN profesores p ON p.user_id = u.id AND p.deleted_at IS NULL
              WHERE '.implode(' AND ', $donde),
            $parametros
        );

        $cambiosDe = [];
        foreach ($importaciones as $i) {
            $cambios = json_decode((string) $i->cambios, true);
            foreach (is_array($cambios) ? $cambios : [] as $alumno => $campos) {
                if ((int) $alumno > 0 && is_array($campos) && $campos !== [] && (! $delAlumno || (int) $alumno === $delAlumno)) {
                    $cambiosDe[(int) $i->id][(int) $alumno] = $campos;
                }
            }
        }
        $alumnos = array_values(array_unique(array_merge([], ...array_map('array_keys', $cambiosDe))));
        if ($alumnos === []) {
            return [];
        }

        // Los nombres, y de paso los filtros que miran al alumno: el grupo (como en
        // `auditoria`, haber estado matriculado en él) y el texto (nombre o documento).
        $donde = [];
        $parametros = $alumnos;
        if ($id = $entero('grupo_id')) {
            $donde[] = 'al.id IN (SELECT m.alumno_id FROM matriculas m WHERE m.grupo_id = ? AND m.deleted_at IS NULL)';
            $parametros[] = $id;
        }
        if (($q = $texto('q')) !== '') {
            $como = '%'.addcslashes($q, '\\%_').'%';
            $donde[] = "(CONCAT_WS(' ', al.nombres, al.apellidos) LIKE ? OR al.documento LIKE ?)";
            array_push($parametros, $como, $como);
        }
        $nombres = [];
        foreach (DB::select(
            'SELECT al.id, al.nombres, al.apellidos FROM alumnos al WHERE al.id IN ('.self::marcas($alumnos).')'
            .($donde ? ' AND '.implode(' AND ', $donde) : ''),
            $parametros
        ) as $a) {
            $nombres[(int) $a->id] = trim($a->nombres.' '.$a->apellidos);
        }

        $lineas = [];
        foreach ($importaciones as $i) {
            foreach ($cambiosDe[(int) $i->id] ?? [] as $alumno => $campos) {
                if (! isset($nombres[$alumno])) {
                    continue;
                }
                [$antes, $despues] = self::enDosColumnas($campos);
                $lineas[] = (object) [
                    'id' => -((int) $i->id * 10_000_000 + $alumno),
                    'accion' => empty($campos['_creado']) ? 'editar' : 'crear',
                    'entidad' => self::IMPORTACION,
                    'entidad_id' => null,
                    'importacion_id' => (int) $i->id,
                    'actor_user_id' => $i->created_by === null ? null : (int) $i->created_by,
                    'actor_nombre' => $i->actor_nombre,
                    'actor_tipo' => $i->actor_tipo,
                    'actor_intentado' => null,
                    'alumno_id' => $alumno,
                    'alumno_nombre' => $nombres[$alumno],
                    'grupo_id' => null,
                    'asignatura_id' => null,
                    'periodo_id' => null,
                    'year_id' => $i->year_id === null ? null : (int) $i->year_id,
                    'valor_anterior' => $antes === [] ? null : json_encode($antes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'valor_nuevo' => $despues === [] ? null : json_encode($despues, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'valor_anterior_num' => null,
                    'valor_nuevo_num' => null,
                    'resumen' => 'Importación de '.($i->archivo ?: 'un archivo sin nombre'),
                    'ip' => null,
                    'ruta' => 'importar/algo',
                    'atribucion' => null,
                    'ocurrido_en' => $i->ocurrido_en,
                    'sesion_id' => null,
                    'historial_id' => null,
                    'detalle' => null,
                ];
            }
        }

        usort($lineas, fn ($a, $b) => [$b->ocurrido_en, $b->importacion_id, $b->alumno_id]
            <=> [$a->ocurrido_en, $a->importacion_id, $a->alumno_id]);

        return $lineas;
    }

    /**
     * `{campo: [antes, después]}` en las dos columnas de una línea, como las de la ficha:
     * `{campo: antes}` y `{campo: después}`. Lo del acudiente va con su id delante
     * (`acudiente_57_celular`), y el acudiente que la fila sólo vinculó, como
     * `acudiente_57_vinculado`.
     *
     * @param  array<string, mixed>  $campos
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private static function enDosColumnas(array $campos): array
    {
        $antes = [];
        $despues = [];
        $poner = function (string $prefijo, array $deQuien) use (&$antes, &$despues) {
            $creado = ! empty($deQuien['_creado']);
            if (! empty($deQuien['_vinculado'])) {
                $despues[$prefijo.'vinculado'] = true;
            }
            foreach ($deQuien as $campo => $valor) {
                if (! is_array($valor) || str_starts_with((string) $campo, '_') || $campo === 'acudientes') {
                    continue;
                }
                if (! $creado && array_key_exists(0, $valor)) {
                    $antes[$prefijo.$campo] = $valor[0];
                }
                $despues[$prefijo.$campo] = $valor[1] ?? null;
            }
        };

        $poner('', $campos);
        foreach (is_array($campos['acudientes'] ?? null) ? $campos['acudientes'] : [] as $acudiente => $deQuien) {
            if (is_array($deQuien)) {
                $poner('acudiente_'.$acudiente.'_', $deQuien);
            }
        }

        return [$antes, $despues];
    }

    /**
     * La página cuando hay líneas de importación: las dos fuentes mezcladas en el orden de
     * la pantalla, sin leer `auditoria` entera.
     *
     * Con `V` líneas de importación, en las posiciones `[desde, desde + cuantas)` sólo
     * pueden caer las de `auditoria` de `OFFSET desde − V` a `desde + cuantas`: delante de
     * cada una hay, como mucho, las `V`. Se lee esa ventana —`id` y fecha, del índice— y
     * se cuenta la posición de cada línea de las dos fuentes: la de una de `auditoria` es
     * su `OFFSET` más las de importación que van antes; la de una de importación, su
     * puesto entre ellas más las de la ventana que van antes y las que había delante de la
     * ventana. Una de importación más nueva que la ventana cae antes de `desde`, y una más
     * vieja que una ventana llena, después de la página: las dos se quedan fuera, que es
     * lo correcto. En un empate de fecha va primero la de importación.
     *
     * @param  list<mixed>  $parametros
     * @param  list<object>  $deImportacion  ordenadas como `deImportaciones()`
     * @return list<int|object> el `id` de cada línea de `auditoria`, o la de importación
     */
    public static function paginaMezclada(string $donde, array $parametros, array $deImportacion, int $desde, int $cuantas): array
    {
        $v = count($deImportacion);
        $inicio = max(0, $desde - $v);
        $cuantasLeer = $cuantas + min($desde, $v);
        $ventana = DB::select(
            "SELECT a.id, a.ocurrido_en FROM auditoria a WHERE $donde
              ORDER BY a.ocurrido_en DESC, a.id DESC
              LIMIT $cuantasLeer OFFSET $inicio",
            $parametros
        );

        // `auditoria.ocurrido_en` lleva milisegundos y `importaciones.fin` no: se comparan
        // con la fracción rellena, para que el mismo segundo sea un empate de verdad.
        $clave = fn ($t) => str_pad(strlen((string) $t) === 19 ? $t.'.' : (string) $t, 26, '0');
        $enPosicion = [];
        foreach ($ventana as $k => $a) {
            $antes = count(array_filter($deImportacion, fn ($l) => $clave($l->ocurrido_en) >= $clave($a->ocurrido_en)));
            $enPosicion[$inicio + $k + $antes] = (int) $a->id;
        }
        foreach ($deImportacion as $j => $l) {
            $antes = count(array_filter($ventana, fn ($a) => $clave($a->ocurrido_en) > $clave($l->ocurrido_en)));
            $enPosicion[$j + $inicio + $antes] = $l;
        }

        ksort($enPosicion);

        return array_values(array_filter(
            $enPosicion,
            fn ($posicion) => $posicion >= $desde && $posicion < $desde + $cuantas,
            ARRAY_FILTER_USE_KEY
        ));
    }

    /**
     * Los totales de `totales()` con las líneas de importación sumadas: cada línea cuenta,
     * y un alumno o un actor que ya salía en `auditoria` no se cuenta dos veces.
     *
     * @param  list<mixed>  $parametros
     * @param  list<object>  $deImportacion
     * @param  array{total: int, alumnos: int, actores: int}  $totales
     * @return array{total: int, alumnos: int, actores: int}
     */
    public static function conImportaciones(string $donde, array $parametros, array $deImportacion, array $totales): array
    {
        if ($deImportacion === []) {
            return $totales;
        }

        $alumnos = self::ids(array_column($deImportacion, 'alumno_id'));
        $actores = self::ids(array_column($deImportacion, 'actor_user_id'));
        $repetidos = DB::selectOne(
            'SELECT COUNT(DISTINCT CASE WHEN a.alumno_id IN ('.self::marcas($alumnos).') THEN a.alumno_id END) AS alumnos,
                    COUNT(DISTINCT CASE WHEN a.actor_user_id IN ('.($actores ? self::marcas($actores) : 'NULL').') THEN a.actor_user_id END) AS actores
               FROM auditoria a WHERE '.$donde,
            [...$alumnos, ...$actores, ...$parametros]
        );

        return [
            'total' => $totales['total'] + count($deImportacion),
            'alumnos' => $totales['alumnos'] + count($alumnos) - (int) ($repetidos->alumnos ?? 0),
            'actores' => $totales['actores'] + count($actores) - (int) ($repetidos->actores ?? 0),
        ];
    }

    /**
     * Añade a cada línea de la página lo que la pantalla pinta y la línea no guarda.
     *
     * @param  array<int, object>  $filas
     */
    public static function completar(array $filas, string $familia): void
    {
        if ($filas === []) {
            return;
        }

        $tipoDe = [];
        foreach (self::FAMILIAS[$familia] as $tipo => $entidades) {
            foreach ($entidades as $entidad) {
                $tipoDe[$entidad] = $tipo;
            }
        }

        $asignaturaDe = self::asignaturas($filas);
        $asignaturas = self::porId('SELECT asg.id, asg.grupo_id, m.materia AS nombre
            FROM asignaturas asg LEFT JOIN materias m ON m.id = asg.materia_id', array_values($asignaturaDe), 'asg.id');
        $periodos = self::porId('SELECT id, numero, year_id FROM periodos', array_column($filas, 'periodo_id'));

        // El año de la línea: el suyo, si no el de su periodo, si no el de su grupo.
        $gruposDeLinea = self::porId('SELECT id, nombre, year_id FROM grupos', array_column($filas, 'grupo_id'));
        $anioDe = [];
        foreach ($filas as $f) {
            $anioDe[$f->id] = $f->year_id
                ?: ($periodos[(int) $f->periodo_id]->year_id ?? null)
                ?: ($gruposDeLinea[(int) $f->grupo_id]->year_id ?? null);
        }
        $anios = self::porId('SELECT id, year FROM years', array_values($anioDe));

        $matriculas = self::matriculas($filas);
        $grupoDe = [];
        foreach ($filas as $f) {
            $grupoDe[$f->id] = $f->grupo_id
                ?: ($asignaturas[$asignaturaDe[$f->id] ?? 0]->grupo_id ?? null)
                ?: ($matriculas[(int) $f->alumno_id][(int) $anioDe[$f->id]] ?? null);
        }
        $grupos = self::porId('SELECT id, nombre FROM grupos', array_values($grupoDe));

        $fotosDeAlumno = self::fotosDeAlumnos(array_column($filas, 'alumno_id'));
        $fotosDeActor = self::fotosDeUsuarios(array_column($filas, 'actor_user_id'));
        $ingresos = self::porId('SELECT id, entorno, browser_name, browser_family, platform_family FROM historiales',
            array_column($filas, 'historial_id'));
        $huecos = self::huecos($filas);

        foreach ($filas as $f) {
            $f->tipo = $tipoDe[$f->entidad] ?? null;
            $f->grupo_nombre = $grupos[(int) $grupoDe[$f->id]]->nombre ?? null;
            $f->asignatura_nombre = $asignaturas[$asignaturaDe[$f->id] ?? 0]->nombre ?? null;
            $f->anio = isset($anios[(int) $anioDe[$f->id]]) ? (int) $anios[(int) $anioDe[$f->id]]->year : null;
            $f->periodo_numero = isset($periodos[(int) $f->periodo_id]) ? (int) $periodos[(int) $f->periodo_id]->numero : null;
            $f->alumno_foto = $fotosDeAlumno[(int) $f->alumno_id] ?? null;
            $f->actor_foto = $fotosDeActor[(int) $f->actor_user_id] ?? null;
            $f->hecho_desde = self::hechoDesde($ingresos[(int) $f->historial_id] ?? null);
            $f->hueco = $huecos[$f->id] ?? false;
        }
    }

    /**
     * El desplegable «Cambiado por»: quien aparece como actor en alguna entidad de las tres
     * familias, con su nombre de la última línea (la denormalización de `auditoria`: el
     * nombre se lee aunque la persona ya no exista), su rol legible y su foto.
     *
     * Quien no tiene `actor_user_id` —el sistema, un login fallido— no sale: no hay por
     * qué filtrarlo.
     *
     * `tipo` es el de la cuenta tal como lo guarda la línea (`Profesor`, `Usuario`…);
     * `rol`, el legible para agrupar el desplegable (Docente, Secretaría, Coordinación…).
     *
     * Con `$alcance` (el del docente, `AlcanceDelDocente::condicion()`), sólo las
     * entidades de notas y sólo las líneas que caen dentro: el desplegable no enseña a
     * nadie que no salga en su listado.
     *
     * @param  array{0: string, 1: list<mixed>}|null  $alcance
     * @return list<array{user_id: int, nombre: string|null, tipo: string|null, rol: string|null, foto: string|null}>
     */
    public static function actores(?array $alcance = null): array
    {
        $entidades = $alcance === null
            ? array_merge(...array_map(fn ($f) => self::entidadesDe($f), array_keys(self::FAMILIAS)))
            : self::entidadesDe('notas');
        [$acotado, $deAlcance] = $alcance ?? ['1 = 1', []];

        $ultimas = array_map(fn ($f) => (int) $f->ultima, DB::select(
            'SELECT MAX(a.id) AS ultima FROM auditoria a
              WHERE a.entidad IN ('.self::marcas($entidades).') AND a.actor_user_id IS NOT NULL
                AND '.$acotado.'
              GROUP BY a.actor_user_id',
            [...$entidades, ...$deAlcance]
        ));

        $lineas = $ultimas === [] ? [] : DB::select(
            'SELECT actor_user_id AS user_id, actor_nombre AS nombre, actor_tipo AS tipo
               FROM auditoria WHERE id IN ('.self::marcas($ultimas).')',
            $ultimas
        );

        // Y quien importó alumnos, que sale en datos sin línea en `auditoria` (el docente
        // no ve datos: a él no se le añade).
        if ($alcance === null) {
            $ya = array_map(fn ($l) => (int) $l->user_id, $lineas);
            foreach (self::deImportaciones([]) as $l) {
                if ($l->actor_user_id !== null && ! in_array($l->actor_user_id, $ya, true)) {
                    $ya[] = $l->actor_user_id;
                    $lineas[] = (object) ['user_id' => $l->actor_user_id, 'nombre' => $l->actor_nombre, 'tipo' => $l->actor_tipo];
                }
            }
        }
        if ($lineas === []) {
            return [];
        }

        return self::personas($lineas);
    }

    /**
     * Las personas de un desplegable —`user_id`, `nombre`, `tipo`— con su rol legible y su
     * foto, ordenadas por nombre sin que la tilde mande. Lo usan «Cambiado por» y el
     * selector de persona de los ingresos (`GET auditoria/ingresos/personas`).
     *
     * @param  array<int, \stdClass>  $lineas
     * @return list<array{user_id: int, nombre: string|null, tipo: string|null, rol: string|null, foto: string|null}>
     */
    public static function personas(array $lineas): array
    {
        if ($lineas === []) {
            return [];
        }
        $ids = array_map(fn ($l) => (int) $l->user_id, $lineas);

        $roles = [];
        foreach (DB::select(
            'SELECT ru.user_id, r.name FROM role_user ru
               JOIN roles r ON r.id = ru.role_id AND r.deleted_at IS NULL
              WHERE ru.user_id IN ('.self::marcas($ids).')', $ids) as $r) {
            $roles[(int) $r->user_id][] = $r->name;
        }
        // Los cargos del año traen su rol sin fila en `role_user` (`Role::rolesDelNombramiento`).
        foreach (DB::select(
            'SELECT p.user_id, r.name FROM years y
               JOIN profesores p ON p.deleted_at IS NULL AND p.user_id IN ('.self::marcas($ids).')
               JOIN roles r ON r.deleted_at IS NULL
                    AND ((r.name = \'Secretario\' AND y.secretario_id = p.id)
                      OR (r.name = \'Tesorero\' AND y.tesorero_id = p.id))
              WHERE y.actual = 1 AND y.deleted_at IS NULL', $ids) as $r) {
            $roles[(int) $r->user_id][] = $r->name;
        }
        $cuentas = self::porId('SELECT id, tipo, is_superuser FROM users', $ids);
        $fotos = self::fotosDeUsuarios($ids);

        $salida = [];
        foreach ($lineas as $l) {
            $id = (int) $l->user_id;
            $salida[] = [
                'user_id' => $id,
                'nombre' => $l->nombre,
                'tipo' => $l->tipo,
                'rol' => self::rolLegible($roles[$id] ?? [], $cuentas[$id] ?? null, $l->tipo),
                'foto' => $fotos[$id] ?? null,
            ];
        }

        usort($salida, fn ($a, $b) => strcmp(
            Str::lower(Str::ascii((string) $a['nombre'])),
            Str::lower(Str::ascii((string) $b['nombre']))
        ) ?: $a['user_id'] <=> $b['user_id']);

        return $salida;
    }

    /** @param  list<string>  $roles */
    private static function rolLegible(array $roles, ?object $cuenta, ?string $actorTipo): ?string
    {
        foreach (self::ROL_LEGIBLE as $rol => $legible) {
            if (in_array($rol, $roles, true)) {
                return $legible;
            }
        }
        if ($cuenta !== null && (int) $cuenta->is_superuser === 1) {
            return 'Administración';
        }
        $tipo = $cuenta->tipo ?? $actorTipo;

        return self::TIPO_LEGIBLE[$tipo] ?? $tipo;
    }

    /**
     * Desde dónde se hizo, por el ingreso de la línea —o el propio ingreso, en la pestaña
     * Ingresos— (`historiales`, que llena
     * `Services/Login.php` con el User-Agent). `Bot` con navegador `Unknown` es la app:
     * es una inferencia —Dart no se reconoce y cae ahí—, la que documenta
     * `myvc_front/AUDITORIA-DE-ALUMNOS.md`. `App` todavía no lo escribe nadie: se acepta
     * para el día que `Login.php` mire `X-MyVC-Cliente`.
     *
     * @return array{origen: string|null, navegador: string|null, plataforma: string|null}
     */
    public static function hechoDesde(?object $ingreso): array
    {
        if ($ingreso === null) {
            return ['origen' => null, 'navegador' => null, 'plataforma' => null];
        }

        $limpio = fn ($v) => ($v === null || $v === '' || $v === 'Unknown') ? null : (string) $v;

        $origen = match ($ingreso->entorno) {
            'App' => 'app',
            'Bot' => $limpio($ingreso->browser_name) === null ? 'app' : null,
            'Mobile', 'Tablet' => 'web_celular',
            'Desktop' => 'web_computador',
            default => null,
        };

        // La familia y no el nombre: «Chrome Mobile · Android», como la maqueta, sin la
        // versión. Y `platform_family`, no `platform_name`: `Login.php` guarda en éste el
        // motor del navegador («Blink»).
        return [
            'origen' => $origen,
            'navegador' => $limpio($ingreso->browser_family),
            'plataforma' => $limpio($ingreso->platform_family),
        ];
    }

    /**
     * `true` si el valor de antes de la línea no es el que dejó la línea anterior de la
     * misma (entidad, entidad_id): hubo un cambio por un camino sin rastro. Sólo en las
     * entidades numéricas, con las columnas `*_num`, y como `hayHuecoAntesDe()` del front:
     * si falta uno de los dos números, no se afirma nada. La anterior se busca en toda la
     * tabla —por `aud_entidad`, una lectura por línea—, no en la página.
     *
     * @param  array<int, object>  $filas
     * @return array<int, bool>
     */
    private static function huecos(array $filas): array
    {
        $ids = array_values(array_map(fn ($f) => (int) $f->id, array_filter(
            $filas,
            fn ($f) => in_array($f->entidad, self::NUMERICAS, true) && $f->entidad_id !== null
        )));
        if ($ids === []) {
            return [];
        }

        $huecos = [];
        foreach (DB::select(
            'SELECT a.id, a.valor_anterior_num AS vino, p.valor_nuevo_num AS dejo
               FROM auditoria a
               JOIN auditoria p ON p.id = (
                    SELECT MAX(q.id) FROM auditoria q
                     WHERE q.entidad = a.entidad AND q.entidad_id = a.entidad_id AND q.id < a.id)
              WHERE a.id IN ('.self::marcas($ids).')', $ids) as $f) {
            $huecos[(int) $f->id] = $f->vino !== null && $f->dejo !== null && (int) $f->vino !== (int) $f->dejo;
        }

        return $huecos;
    }

    /**
     * La asignatura de cada línea: la suya, o la de la fila de su entidad.
     *
     * @param  array<int, object>  $filas
     * @return array<int, int> id de línea → asignatura_id
     */
    private static function asignaturas(array $filas): array
    {
        $de = [];
        $faltan = [];
        foreach ($filas as $f) {
            if ($f->asignatura_id) {
                $de[$f->id] = (int) $f->asignatura_id;
            } elseif (isset(self::ASIGNATURA_DE[$f->entidad]) && $f->entidad_id) {
                $faltan[$f->entidad][] = (int) $f->entidad_id;
            }
        }

        foreach ($faltan as $entidad => $ids) {
            $ids = array_values(array_unique($ids));
            $alias = $entidad === 'nota' ? 'n' : 't';
            $deLaFila = [];
            foreach (DB::select(self::ASIGNATURA_DE[$entidad]." WHERE $alias.id IN (".self::marcas($ids).')', $ids) as $r) {
                $deLaFila[(int) $r->id] = (int) $r->asignatura_id;
            }
            foreach ($filas as $f) {
                if (! isset($de[$f->id]) && $f->entidad === $entidad && isset($deLaFila[(int) $f->entidad_id])) {
                    $de[$f->id] = $deLaFila[(int) $f->entidad_id];
                }
            }
        }

        return $de;
    }

    /**
     * alumno → año → grupo, de sus matrículas. Si hay dos en un año, la última.
     *
     * @param  array<int, object>  $filas
     * @return array<int, array<int, int>>
     */
    private static function matriculas(array $filas): array
    {
        $ids = self::ids(array_column($filas, 'alumno_id'));
        if ($ids === []) {
            return [];
        }

        $de = [];
        foreach (DB::select(
            'SELECT m.alumno_id, m.grupo_id, g.year_id FROM matriculas m
               JOIN grupos g ON g.id = m.grupo_id AND g.deleted_at IS NULL
              WHERE m.alumno_id IN ('.self::marcas($ids).') AND m.deleted_at IS NULL
              ORDER BY m.id', $ids) as $m) {
            $de[(int) $m->alumno_id][(int) $m->year_id] = (int) $m->grupo_id;
        }

        return $de;
    }

    /**
     * El nombre de la foto del alumno (`alumnos.foto_id` → `images.nombre`), como la
     * sirven los demás listados en `foto_nombre`, pero sin el `default_*.png`: sin foto
     * es `null` y la pantalla pone las iniciales.
     *
     * @param  array<int, mixed>  $ids
     * @return array<int, string>
     */
    private static function fotosDeAlumnos(array $ids): array
    {
        $ids = self::ids($ids);
        if ($ids === []) {
            return [];
        }

        $fotos = [];
        foreach (DB::select(
            'SELECT a.id, i.nombre FROM alumnos a
               JOIN images i ON i.id = a.foto_id AND i.deleted_at IS NULL
              WHERE a.id IN ('.self::marcas($ids).')', $ids) as $f) {
            $fotos[(int) $f->id] = $f->nombre;
        }

        return $fotos;
    }

    /**
     * La foto de quien hizo el cambio: la de su ficha de docente (`profesores.foto_id`,
     * la de los listados de docentes), y si no tiene, la de su cuenta (`users.imagen_id`).
     *
     * @param  array<int, mixed>  $ids
     * @return array<int, string>
     */
    private static function fotosDeUsuarios(array $ids): array
    {
        $ids = self::ids($ids);
        if ($ids === []) {
            return [];
        }

        $fotos = [];
        foreach (DB::select(
            'SELECT u.id, ip.nombre AS de_docente, iu.nombre AS de_cuenta FROM users u
               LEFT JOIN profesores p ON p.user_id = u.id AND p.deleted_at IS NULL
               LEFT JOIN images ip ON ip.id = p.foto_id AND ip.deleted_at IS NULL
               LEFT JOIN images iu ON iu.id = u.imagen_id AND iu.deleted_at IS NULL
              WHERE u.id IN ('.self::marcas($ids).')', $ids) as $f) {
            $foto = $f->de_docente ?: $f->de_cuenta;
            if ($foto && ! isset($fotos[(int) $f->id])) {
                $fotos[(int) $f->id] = $foto;
            }
        }

        return $fotos;
    }

    /**
     * Las filas de `$sql` con esos `id`, por id. `$sql` es un `SELECT id, … FROM …` sin
     * `WHERE`; `$columna` es su `id` con el alias, si lo lleva.
     *
     * @param  array<int, mixed>  $ids
     * @return array<int, object>
     */
    private static function porId(string $sql, array $ids, string $columna = 'id'): array
    {
        $ids = self::ids($ids);
        if ($ids === []) {
            return [];
        }

        $filas = [];
        foreach (DB::select($sql." WHERE $columna IN (".self::marcas($ids).')', $ids) as $f) {
            $filas[(int) $f->id] = $f;
        }

        return $filas;
    }

    /**
     * @param  array<int, mixed>  $valores
     * @return list<int>
     */
    private static function ids(array $valores): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $valores))));
    }

    /** @param  array<int, mixed>  $lista */
    private static function marcas(array $lista): string
    {
        return implode(',', array_fill(0, count($lista), '?'));
    }
}
