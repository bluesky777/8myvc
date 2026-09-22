<?php

namespace App\Services;

use App\Support\EscalaDeNotas;
use App\Support\RepartoDeLaNota;
use Illuminate\Support\Facades\DB;

/**
 * La consulta de la planilla **de sólo lectura**, para el libro de Excel que el
 * docente se lleva sin internet.
 *
 * Fase 1 de `myvc_front/PLAN-NOTAS-SIN-INTERNET.md`. Sirve a las tres rutas de
 * `planilla-offline/*` y no la llama nadie más.
 *
 * ## POR QUÉ NO REUTILIZA `NotasController::putDetailed`
 *
 * Es la §3.7 del plan y es la decisión que gobierna este fichero entero. Aquel
 * método, que parece un `GET` inocuo, hace **tres escrituras**:
 *
 *   1. `Nota::verificarCrearNotas` — **siembra una fila en `notas` por cada
 *      alumno del grupo y cada subunidad** que encuentre.
 *   2. `Unidad::arreglarOrden` — reescribe el `orden` de unidades y subunidades
 *      cuando hay duplicados.
 *   3. `DefinitivasDeAsignatura::recalcular` — recalcula la definitiva de cada
 *      alumno cuyo sello esté desactualizado.
 *
 * Una descarga no puede hacer nada de eso. **Un docente bajándose el año entero
 * para guardárselo en su carpeta (D1) sembraría cuatro periodos de filas en
 * `notas` de un colegio en producción**, y recalcularía definitivas de periodos
 * ya impresos. La siembra sí hace falta, pero **al importar, no al descargar**.
 *
 * Y hay una segunda razón, más pequeña y más terca: `putDetailed` **no acepta el
 * periodo**. Lo saca de `$user->periodo_id`, o sea del token. La D1 permite bajar
 * periodos pasados, así que el periodo tiene que viajar por parámetro o habría que
 * cambiarle la sesión al docente para que se baje el periodo 1.
 *
 * ## Las reglas que sí se copian de ahí, palabra por palabra
 *
 * - **`unidades.alumno_id IS NULL`** en todo. Sin eso se mezclan las unidades de
 *   los boletines independientes con las del curso, y la rejilla sale con
 *   columnas que no son de nadie. Es la misma guarda de `putDetailed`, y aquí
 *   además evita que el libro le pida al docente notas de una estructura que él
 *   no ve en la pantalla.
 * - **Los alumnos con boletín independiente van fuera de la rejilla**, en
 *   `independientes`, igual que en la planilla de la web. La portada los nombra
 *   para que no parezca un olvido (§9.4 del plan).
 * - **Las matrículas son `MATR`, `ASIS` y `PREM`, sin `deleted_at`, ordenadas por
 *   `apellidos, nombres`** — el criterio literal de `Grupo::alumnos`. No se usa
 *   ese método porque trae la foto, el acudiente y veinte columnas más que el
 *   libro no imprime, y porque `BoletinIndependiente::delGrupo` cuenta sólo
 *   `MATR` y `ASIS`: **dos poblaciones distintas en la misma hoja acaban
 *   discrepando**, y aquí discrepar es un alumno que no sale en ninguna lista.
 *
 * ## Y las columnas van nombradas, nunca `SELECT *`
 *
 * Por lo mismo que en `putDetailed`: una columna nueva en `unidades`, en
 * `subunidades` o en `notas` aparecería sola dentro del libro y dentro de la hoja
 * `_myvc` firmada, moviendo la firma de todos los libros sin que nadie lo hubiera
 * pedido.
 */
final class LaPlanillaQueSeDescarga
{
    /**
     * Los estados de matrícula que cuentan como «está en el grupo».
     *
     * Interpolados y no bindeados porque son una **constante de este fichero** y
     * nunca un valor de la petición. Es la misma regla que `RepartoDeLaNota`
     * escribe para sus alias de tabla.
     *
     * **Pública desde la fase 2**, para que {@see EnsayoDeLaPlanilla} conteste
     * «¿sigue este alumno en el grupo?» con exactamente la misma lista con la que
     * se armó la hoja. Copiarla serían dos listas que responden distinto el día que
     * un colegio estrene un estado — y la diferencia se vería como filas que no se
     * pueden importar, no como una lista desincronizada.
     */
    public const ESTADOS = "'MATR','ASIS','PREM'";

    /**
     * Lo que el año le dice al libro: cómo se llama cada cosa, hasta dónde llega
     * la escala y cómo reparte los pesos.
     *
     * **La escala es la del año que se le pide, no la del año en curso.** La D1
     * deja bajar periodos pasados y cada año tiene su escala; validar el libro de
     * 2024 contra la escala de 2026 pondría un tope que ese año no tenía.
     *
     * **`escala_maxima` puede ser `null` y eso NO se rellena con 100.** Es la
     * decisión escrita en `EscalaDeNotas`: un año sin escala configurada sale sin
     * validación de tope, porque un valor inventado *afloja* el límite al doble en
     * un colegio de 0 a 50 y encima parece una comprobación.
     *
     * `nota_minima_aceptada` es **`varchar(3)`** en el esquema, con `'70'` por
     * defecto. Sale casteada para que el front y la portada del libro no tengan
     * que decidir cada uno si `'30'` es un número.
     */
    public static function cabeceraDelAnio(int $yearId): object
    {
        $anio = DB::selectOne(
            'SELECT y.id AS year_id, y.year, y.nombre_colegio, y.abrev_colegio,
                    y.nota_minima_aceptada,
                    y.unidad_displayname, y.unidades_displayname, y.genero_unidad,
                    y.subunidad_displayname, y.subunidades_displayname, y.genero_subunidad,
                    y.modelo_evaluacion, y.reparto_subunidades
               FROM years y
              WHERE y.id = ? AND y.deleted_at IS NULL',
            [$yearId]
        );

        if ($anio === null) {
            abort(404, 'Ese año no existe.');
        }

        return (object) [
            'year_id' => (int) $anio->year_id,
            'year' => (int) $anio->year,
            'nombre_colegio' => (string) $anio->nombre_colegio,
            'abrev_colegio' => $anio->abrev_colegio,
            'nota_minima' => is_numeric($anio->nota_minima_aceptada) ? (int) $anio->nota_minima_aceptada : null,
            'escala_maxima' => EscalaDeNotas::maximo($yearId),
            'escala_minima' => EscalaDeNotas::minimo($yearId),
            'unidad_displayname' => (string) $anio->unidad_displayname,
            'unidades_displayname' => (string) $anio->unidades_displayname,
            'genero_unidad' => (string) $anio->genero_unidad,
            'subunidad_displayname' => (string) $anio->subunidad_displayname,
            'subunidades_displayname' => (string) $anio->subunidades_displayname,
            'genero_subunidad' => (string) $anio->genero_subunidad,
            'modelo_evaluacion' => $anio->modelo_evaluacion,
            'reparto' => RepartoDeLaNota::modoDelAnio($yearId),
        ];
    }

    /**
     * Los periodos del año, en orden, con si están abiertos.
     *
     * `abierto` es `periodos.profes_pueden_editar_notas`, **la única marca de
     * «cerrado» que existe hoy** — la misma que mira la migración de la casilla
     * vacía para no tocar lo ya impreso. Aquí no decide si se puede descargar
     * (siempre se puede, D1): decide si el archivo sale rotulado como copia de
     * consulta.
     *
     * @return list<object{id:int, numero:int, abierto:bool, actual:bool}>
     */
    public static function periodosDelAnio(int $yearId): array
    {
        $filas = DB::select(
            'SELECT p.id, p.numero, p.profes_pueden_editar_notas, p.actual
               FROM periodos p
              WHERE p.year_id = ? AND p.deleted_at IS NULL
              ORDER BY p.numero, p.id',
            [$yearId]
        );

        return array_values(array_map(static fn ($p) => (object) [
            'id' => (int) $p->id,
            'numero' => (int) $p->numero,
            'abierto' => (bool) $p->profes_pueden_editar_notas,
            'actual' => (bool) $p->actual,
        ], $filas));
    }

    /**
     * El periodo que el colegio tiene marcado como actual en ese año, si hay uno.
     *
     * Se devuelve para que la pantalla de descarga pueda abrir en él sin tener
     * que mirar el token: el periodo del token es **el del usuario**, que puede
     * estar en otro año entero.
     */
    public static function periodoActualDelAnio(int $yearId): ?int
    {
        foreach (self::periodosDelAnio($yearId) as $periodo) {
            if ($periodo->actual) {
                return $periodo->id;
            }
        }

        return null;
    }

    /**
     * Las asignaturas vivas de un docente en un año.
     *
     * Es la consulta de `Profesor::asignaturas` recortada a lo que imprime el
     * libro. **No se reutiliza aquélla** porque trae `titular_id`, `caritas`,
     * `nivel_educativo_id` y `creditos`, que aquí no pinta nadie, y porque su
     * orden (`g.orden, a.orden, m.materia`) es el de la pantalla de asignaturas;
     * el libro usa ése mismo a propósito, para que las hojas salgan en el orden en
     * que el docente ve sus asignaturas.
     *
     * @return list<object>
     */
    public static function asignaturasDelProfesor(int $profesorId, int $yearId): array
    {
        return array_values(DB::select(
            'SELECT a.id AS asignatura_id, a.grupo_id, a.profesor_id,
                    m.materia, m.alias AS alias_materia,
                    g.nombre AS nombre_grupo, g.abrev AS abrev_grupo, g.orden AS orden_grupo, a.orden
               FROM asignaturas a
               INNER JOIN materias m ON m.id = a.materia_id AND m.deleted_at IS NULL
               INNER JOIN grupos g ON g.id = a.grupo_id AND g.year_id = ? AND g.deleted_at IS NULL
              WHERE a.profesor_id = ? AND a.deleted_at IS NULL
              ORDER BY g.orden, a.orden, m.materia, m.alias, a.id',
            [$yearId, $profesorId]
        ));
    }

    /**
     * ¿De quién es esta asignatura, y en qué año vive?
     *
     * Lo necesita la autorización de la ruta de una sola hoja, que recibe un
     * `asignatura_id` suelto y **no puede fiarse de que sea del docente ni del
     * año del token**. Devuelve `null` si no existe o está borrada.
     */
    public static function duenoDeLaAsignatura(int $asignaturaId): ?object
    {
        $fila = DB::selectOne(
            'SELECT a.id AS asignatura_id, a.profesor_id, a.grupo_id, g.year_id
               FROM asignaturas a
               INNER JOIN grupos g ON g.id = a.grupo_id AND g.deleted_at IS NULL
              WHERE a.id = ? AND a.deleted_at IS NULL',
            [$asignaturaId]
        );

        if ($fila === null) {
            return null;
        }

        return (object) [
            'asignatura_id' => (int) $fila->asignatura_id,
            'profesor_id' => $fila->profesor_id === null ? null : (int) $fila->profesor_id,
            'grupo_id' => (int) $fila->grupo_id,
            'year_id' => (int) $fila->year_id,
        ];
    }

    /**
     * Cuántos alumnos, cuántos indicadores y **cuántas casillas quedan por pasar**,
     * por asignatura, en un periodo.
     *
     * ## `sin_pasar` NO sale de `subunidades.cantNotas`, y ése es el detalle caro
     *
     * El primer sitio donde se busca este número es el contador que ya existe en
     * la respuesta de la planilla; **no sirve**, porque ese campo cuenta filas de
     * `notas` y **no sabe cuántos alumnos hay**. Un indicador recién creado en un
     * grupo de 28 tiene 0 notas y 28 casillas vacías, y otro con 28 filas
     * sembradas y todas en `NULL` tiene 28 «notas» y las mismas 28 casillas
     * vacías. Los dos números serían distintos y ninguno sería el que la portada
     * enseña.
     *
     * Lo que se cuenta aquí es la resta que sí significa algo:
     *
     *     sin_pasar = alumnos_matriculados × indicadores − notas CON valor
     *
     * **`n.nota IS NOT NULL`**, no «la fila existe»: desde
     * `2026_09_19_500000_la_casilla_vacia` la fila puede estar sembrada y la nota
     * sin poner, que es exactamente el caso que la portada tiene que enseñar como
     * pendiente.
     *
     * Y las notas se cuentan **cruzadas contra las matrículas vivas**, para que la
     * nota de un alumno retirado después de calificarle no descuente una casilla
     * que sigue vacía para los que están.
     *
     * @param  list<int>  $asignaturaIds
     * @return array<int, object{alumnos:int, indicadores:int, sin_pasar:int}>
     */
    public static function recuentosDelPeriodo(array $asignaturaIds, int $periodoId): array
    {
        $asignaturaIds = array_values(array_unique(array_map('intval', $asignaturaIds)));

        if ($asignaturaIds === []) {
            return [];
        }

        $marcas = implode(',', array_fill(0, count($asignaturaIds), '?'));
        $estados = self::ESTADOS;

        $alumnos = DB::select(
            "SELECT a.id AS asignatura_id, COUNT(DISTINCT m.alumno_id) AS alumnos
               FROM asignaturas a
               INNER JOIN matriculas m ON m.grupo_id = a.grupo_id AND m.deleted_at IS NULL
                                      AND m.estado IN ({$estados})
               INNER JOIN alumnos al ON al.id = m.alumno_id AND al.deleted_at IS NULL
               LEFT JOIN bol_ind_periodos bip ON bip.alumno_id = m.alumno_id AND bip.periodo_id = ?
              WHERE a.id IN ({$marcas}) AND a.deleted_at IS NULL
                AND COALESCE(bip.aplica, 0) = 0
              GROUP BY a.id",
            array_merge([$periodoId], $asignaturaIds)
        );

        $indicadores = DB::select(
            "SELECT u.asignatura_id, COUNT(s.id) AS indicadores
               FROM unidades u
               INNER JOIN subunidades s ON s.unidad_id = u.id AND s.deleted_at IS NULL
              WHERE u.asignatura_id IN ({$marcas}) AND u.periodo_id = ?
                AND u.deleted_at IS NULL AND u.alumno_id IS NULL
              GROUP BY u.asignatura_id",
            array_merge($asignaturaIds, [$periodoId])
        );

        $puestas = DB::select(
            "SELECT u.asignatura_id, COUNT(DISTINCT n.id) AS puestas
               FROM unidades u
               INNER JOIN subunidades s ON s.unidad_id = u.id AND s.deleted_at IS NULL
               INNER JOIN notas n ON n.subunidad_id = s.id AND n.deleted_at IS NULL
                                 AND n.nota IS NOT NULL
               INNER JOIN asignaturas a ON a.id = u.asignatura_id AND a.deleted_at IS NULL
               INNER JOIN matriculas m ON m.alumno_id = n.alumno_id AND m.grupo_id = a.grupo_id
                                      AND m.deleted_at IS NULL AND m.estado IN ({$estados})
               LEFT JOIN bol_ind_periodos bip ON bip.alumno_id = n.alumno_id AND bip.periodo_id = u.periodo_id
              WHERE u.asignatura_id IN ({$marcas}) AND u.periodo_id = ?
                AND u.deleted_at IS NULL AND u.alumno_id IS NULL
                AND COALESCE(bip.aplica, 0) = 0
              GROUP BY u.asignatura_id",
            array_merge($asignaturaIds, [$periodoId])
        );

        $cuantosAlumnos = self::porAsignatura($alumnos, 'alumnos');
        $cuantosIndicadores = self::porAsignatura($indicadores, 'indicadores');
        $cuantasPuestas = self::porAsignatura($puestas, 'puestas');

        $salida = [];

        foreach ($asignaturaIds as $id) {
            $cuantos = $cuantosAlumnos[$id] ?? 0;
            $cuantasColumnas = $cuantosIndicadores[$id] ?? 0;

            $salida[$id] = (object) [
                'alumnos' => $cuantos,
                'indicadores' => $cuantasColumnas,
                // `max(0, …)`: si un alumno retirado dejó notas y su matrícula ya no
                // cuenta, la resta podría pasarse de vueltas. Un «-3 sin pasar» en la
                // portada es peor que un 0, porque nadie sabría qué hacer con él.
                'sin_pasar' => max(0, $cuantos * $cuantasColumnas - ($cuantasPuestas[$id] ?? 0)),
            ];
        }

        return $salida;
    }

    /**
     * Aplana un `GROUP BY asignatura_id` a `[asignatura_id => cuenta]`.
     *
     * Las tres consultas de arriba devuelven **sólo las asignaturas que tienen
     * algo**, así que el llamante pregunta con `?? 0`: una asignatura sin alumnos,
     * sin indicadores o sin ninguna nota puesta no aparece en su consulta, y eso no
     * es un hueco — es un cero.
     *
     * @param  list<object>  $filas
     * @return array<int, int>
     */
    private static function porAsignatura(array $filas, string $campo): array
    {
        $salida = [];

        foreach ($filas as $fila) {
            $salida[(int) $fila->asignatura_id] = (int) $fila->{$campo};
        }

        return $salida;
    }

    /**
     * Las bandas de la escala del año, en orden. Para la leyenda de la portada y
     * para el formato condicional de las casillas.
     *
     * `perdido` viaja porque esa banda se pinta **además en negrita**: la planilla
     * tiene que poder leerse impresa en blanco y negro, y un color sólo no
     * sobrevive a una fotocopia (§4.5 del plan).
     *
     * @return list<object>
     */
    public static function escalasDelAnio(int $yearId): array
    {
        $filas = DB::select(
            'SELECT e.id, e.desempenio, e.valoracion, e.porc_inicial, e.porc_final, e.perdido, e.orden
               FROM escalas_de_valoracion e
              WHERE e.year_id = ? AND e.deleted_at IS NULL
              ORDER BY e.porc_inicial, e.orden, e.id',
            [$yearId]
        );

        return array_values(array_map(static fn ($e) => (object) [
            'id' => (int) $e->id,
            'desempenio' => (string) $e->desempenio,
            'valoracion' => (string) $e->valoracion,
            'porc_inicial' => (int) $e->porc_inicial,
            'porc_final' => (int) $e->porc_final,
            'perdido' => (bool) $e->perdido,
        ], $filas));
    }

    /**
     * La rejilla entera de una asignatura en un periodo. **No escribe nada.**
     *
     * @return object{asignatura:object, unidades:list<object>, alumnos:list<object>,
     *                independientes:list<object>, notas:array<int,array<int,?int>>,
     *                asistencia:array<int,object>}
     */
    public static function planilla(int $asignaturaId, int $periodoId): object
    {
        $asignatura = DB::selectOne(
            'SELECT a.id AS asignatura_id, a.grupo_id, a.profesor_id,
                    m.materia, m.alias AS alias_materia,
                    g.nombre AS nombre_grupo, g.abrev AS abrev_grupo, g.year_id,
                    p.nombres AS nombres_profesor, p.apellidos AS apellidos_profesor
               FROM asignaturas a
               INNER JOIN materias m ON m.id = a.materia_id AND m.deleted_at IS NULL
               INNER JOIN grupos g ON g.id = a.grupo_id AND g.deleted_at IS NULL
               LEFT JOIN profesores p ON p.id = a.profesor_id AND p.deleted_at IS NULL
              WHERE a.id = ? AND a.deleted_at IS NULL',
            [$asignaturaId]
        );

        if ($asignatura === null) {
            abort(404, 'Esa asignatura no existe o está borrada.');
        }

        // **`u.alumno_id IS NULL`**: la rejilla es la DEL GRUPO. Sin esto se cuelan
        // las unidades que el boletín independiente crea con dueño, y el libro le
        // pediría al docente notas de una estructura que su pantalla no le enseña.
        $unidades = DB::select(
            'SELECT u.id, u.definicion, u.porcentaje, u.orden
               FROM unidades u
              WHERE u.asignatura_id = ? AND u.periodo_id = ?
                AND u.deleted_at IS NULL AND u.alumno_id IS NULL
              ORDER BY u.orden, u.id',
            [$asignaturaId, $periodoId]
        );

        $unidades = array_values(array_map(static fn ($u) => (object) [
            'id' => (int) $u->id,
            'definicion' => $u->definicion,
            'porcentaje' => (int) $u->porcentaje,
            'subunidades' => [],
        ], $unidades));

        $idsDeUnidad = array_map(static fn ($u) => $u->id, $unidades);

        if ($idsDeUnidad !== []) {
            $marcas = implode(',', array_fill(0, count($idsDeUnidad), '?'));

            $subunidades = DB::select(
                "SELECT s.id, s.definicion, s.porcentaje, s.unidad_id, s.orden
                   FROM subunidades s
                  WHERE s.unidad_id IN ({$marcas}) AND s.deleted_at IS NULL
                  ORDER BY s.unidad_id, s.orden, s.id",
                $idsDeUnidad
            );

            $porUnidad = [];

            foreach ($subunidades as $s) {
                $porUnidad[(int) $s->unidad_id][] = (object) [
                    'id' => (int) $s->id,
                    'definicion' => $s->definicion,
                    'porcentaje' => (int) $s->porcentaje,
                ];
            }

            foreach ($unidades as $unidad) {
                $unidad->subunidades = $porUnidad[$unidad->id] ?? [];
            }
        }

        [$alumnos, $independientes] = self::alumnosDelGrupo((int) $asignatura->grupo_id, $periodoId);

        return (object) [
            'asignatura' => (object) [
                'asignatura_id' => (int) $asignatura->asignatura_id,
                'grupo_id' => (int) $asignatura->grupo_id,
                'year_id' => (int) $asignatura->year_id,
                'profesor_id' => $asignatura->profesor_id === null ? null : (int) $asignatura->profesor_id,
                'materia' => (string) $asignatura->materia,
                'alias_materia' => $asignatura->alias_materia,
                'nombre_grupo' => (string) $asignatura->nombre_grupo,
                'abrev_grupo' => $asignatura->abrev_grupo,
                'nombre_profesor' => trim(($asignatura->nombres_profesor ?? '').' '.($asignatura->apellidos_profesor ?? '')),
            ],
            'unidades' => $unidades,
            'alumnos' => $alumnos,
            'independientes' => $independientes,
            'notas' => self::notasDe($asignaturaId, $periodoId, $alumnos),
            'asistencia' => self::asistenciaDe($asignaturaId, $periodoId, $alumnos),
        ];
    }

    /**
     * Los alumnos del grupo partidos en dos listas: los de la rejilla y los que
     * van por boletín independiente en **ese** periodo.
     *
     * **De una sola pasada sobre una sola consulta**, que es lo que garantiza que
     * sean complementarias — la lección que `putDetailed` ya dejó escrita: dos
     * fuentes que pueden discrepar acaban discrepando, y aquí discrepar es un
     * alumno que no sale en ninguna de las dos.
     *
     * `no_matricula` puede venir vacío y entonces el libro usa `alumnos.id`: la
     * columna `ID` es lo que ata la fila a una persona al subirla, y una celda
     * vacía ahí sería una fila que no se puede reconocer. El libro guarda cuál de
     * los dos usó en `es_no_matricula`, para que la fase 2 no tenga que adivinarlo.
     *
     * @return array{0: list<object>, 1: list<object>}
     */
    private static function alumnosDelGrupo(int $grupoId, int $periodoId): array
    {
        $estados = self::ESTADOS;

        $filas = DB::select(
            "SELECT a.id AS alumno_id, a.no_matricula, a.apellidos, a.nombres,
                    IF(COALESCE(bip.aplica, 0) = 1, 1, 0) AS independiente
               FROM alumnos a
               INNER JOIN matriculas m ON m.alumno_id = a.id AND m.grupo_id = ?
                                      AND m.estado IN ({$estados}) AND m.deleted_at IS NULL
               LEFT JOIN bol_ind_periodos bip ON bip.alumno_id = a.id AND bip.periodo_id = ?
              WHERE a.deleted_at IS NULL
              GROUP BY a.id, a.no_matricula, a.apellidos, a.nombres, independiente
              ORDER BY a.apellidos, a.nombres",
            [$grupoId, $periodoId]
        );

        $rejilla = [];
        $aparte = [];

        foreach ($filas as $fila) {
            $tieneMatricula = $fila->no_matricula !== null && trim((string) $fila->no_matricula) !== '';

            $alumno = (object) [
                'alumno_id' => (int) $fila->alumno_id,
                'apellidos' => (string) ($fila->apellidos ?? ''),
                'nombres' => (string) ($fila->nombres ?? ''),
                'identificador' => $tieneMatricula ? trim((string) $fila->no_matricula) : (string) (int) $fila->alumno_id,
                'es_no_matricula' => $tieneMatricula,
            ];

            if ($fila->independiente) {
                $aparte[] = $alumno;

                continue;
            }

            $rejilla[] = $alumno;
        }

        return [$rejilla, $aparte];
    }

    /**
     * Las notas de la rejilla: `[alumno_id][subunidad_id] => ?int`.
     *
     * **Una consulta para toda la hoja**, no una por alumno como hace
     * `putDetailed`. Aquél las pide de una en una porque además siembra y
     * recalcula por alumno; aquí no hay nada que sembrar, así que una hoja de 45
     * alumnos y 19 indicadores es **una** consulta en vez de 45.
     *
     * El valor es `?int` y el `null` **se conserva**: es «no calificada», que no es
     * cero (`2026_09_19_500000_la_casilla_vacia`). El espejo firmado de la hoja
     * `_myvc` guarda ese mismo `null`, y la D3 de la fase 2 lo compara.
     *
     * @param  list<object>  $alumnos
     * @return array<int, array<int, ?int>>
     */
    private static function notasDe(int $asignaturaId, int $periodoId, array $alumnos): array
    {
        if ($alumnos === []) {
            return [];
        }

        $ids = array_map(static fn ($a) => $a->alumno_id, $alumnos);
        $marcas = implode(',', array_fill(0, count($ids), '?'));

        $filas = DB::select(
            "SELECT n.alumno_id, n.subunidad_id, n.nota
               FROM notas n
               INNER JOIN subunidades s ON s.id = n.subunidad_id AND s.deleted_at IS NULL
               INNER JOIN unidades u ON u.id = s.unidad_id AND u.deleted_at IS NULL
                                    AND u.periodo_id = ? AND u.asignatura_id = ?
                                    AND u.alumno_id IS NULL
              WHERE n.deleted_at IS NULL AND n.alumno_id IN ({$marcas})",
            array_merge([$periodoId, $asignaturaId], $ids)
        );

        $notas = [];

        foreach ($filas as $fila) {
            $notas[(int) $fila->alumno_id][(int) $fila->subunidad_id] =
                $fila->nota === null ? null : (int) $fila->nota;
        }

        return $notas;
    }

    /**
     * Cuántas ausencias y cuántas tardanzas tiene cada alumno en ese periodo y esa
     * asignatura.
     *
     * **Se cuentan filas** (`COUNT(*)`), que es lo que `ausencias` guarda (§3.6 del
     * plan): una por evento, con su fecha. En este proyecto conviven dos criterios
     * de recuento sobre estos mismos datos —unos endpoints cuentan filas y otros
     * suman `cantidad_ausencia`, y **dan números distintos**—, así que el que
     * imprime el libro tiene que ser el mismo que lea la importación.
     *
     * **Y esto es lo único que las cuenta.** Desde la fase 4 (doc 51) el mismo
     * resultado viaja **dos veces** en el libro: a la celda visible y al espejo de
     * `asistencia` en el mapa de `_myvc`, que es lo que hace posible la D3 sobre
     * estas dos columnas. Las dos salen de aquí a propósito: contarlo por segundo
     * camino dejaría que un día el libro dijera una cosa en la celda y otra en su
     * propio espejo, y la D3 se decidiría con la que nadie ve.
     *
     * El precio de que la columna sólo lleve **el total del periodo** sigue siendo
     * el de siempre y lo paga la fase 4: subir un conteo crea filas fechadas el día
     * de la importación —no el día que el alumno faltó— y bajarlo **borra** filas
     * con sus fechas, que son las que leen las planillas de los acudientes. Por eso
     * bajar no ocurre sin que alguien lo pida.
     *
     * @param  list<object>  $alumnos
     * @return array<int, object{ausencias:int, tardanzas:int}>
     */
    private static function asistenciaDe(int $asignaturaId, int $periodoId, array $alumnos): array
    {
        if ($alumnos === []) {
            return [];
        }

        $ids = array_map(static fn ($a) => $a->alumno_id, $alumnos);
        $marcas = implode(',', array_fill(0, count($ids), '?'));

        $filas = DB::select(
            "SELECT a.alumno_id, a.tipo, COUNT(*) AS cuantas
               FROM ausencias a
              WHERE a.asignatura_id = ? AND a.periodo_id = ? AND a.deleted_at IS NULL
                AND a.alumno_id IN ({$marcas})
              GROUP BY a.alumno_id, a.tipo",
            array_merge([$asignaturaId, $periodoId], $ids)
        );

        $asistencia = [];

        foreach ($alumnos as $alumno) {
            $asistencia[$alumno->alumno_id] = (object) ['ausencias' => 0, 'tardanzas' => 0];
        }

        foreach ($filas as $fila) {
            $alumnoId = (int) $fila->alumno_id;

            if (! isset($asistencia[$alumnoId])) {
                continue;
            }

            if ($fila->tipo === 'tardanza') {
                $asistencia[$alumnoId]->tardanzas = (int) $fila->cuantas;
            } elseif ($fila->tipo === 'ausencia') {
                $asistencia[$alumnoId]->ausencias = (int) $fila->cuantas;
            }
        }

        return $asistencia;
    }
}
