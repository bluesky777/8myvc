<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * El único sitio que calcula y escribe una definitiva.
 *
 * Es la **fase 1** de [docs/migracion/10-definitivas.md](../../docs/migracion/10-definitivas.md).
 * Hoy hay **seis** sitios que escriben en `notas_finales`, con cinco criterios
 * distintos de qué borrar, tres formas distintas de identificar la fila —`id`,
 * `periodo_id`, `periodo`— y ninguno transaccional, sobre una tabla sin clave
 * única. De ahí salen los tres síntomas que se reportaban por separado y son el
 * mismo problema: definitivas que desaparecen, definitivas duplicadas y notas
 * que los profesores juraban haber puesto.
 *
 * **Esta clase todavía no la llama nadie**, y es a propósito. Sustituir los seis
 * escritores es la fase 3, y va detrás de la fase 2 —la migración que limpia y
 * pone las claves únicas—, que a su vez no se puede desplegar sola: el índice con
 * el código viejo convierte cada duplicado en un 500. Se escribe antes para que
 * lo que llegue a producción llegue ya medido y con sus tests, no para que se
 * quede aquí.
 *
 * ## Las cinco reglas, y de dónde sale cada una
 *
 * 1. **Los alumnos salen de `matriculas`, no de `notas`.** Es la §9.1 —la fila
 *    existe siempre que exista la matrícula— y es lo que arregla la §1.1 y la
 *    §1.3: los seis escritores de hoy reponen sólo a quien tiene notas, así que
 *    un alumno sin ninguna nota pierde la definitiva y no vuelve.
 * 2. **La fórmula no cambia y no normaliza** (§9.3): suma de aportes, sin dividir
 *    por la suma de porcentajes. Que una asignatura mal configurada dé una
 *    definitiva rara es la intención — es lo que la delata en la planilla. Por
 *    eso `recalcular()` devuelve además `porcentaje_unidades`, para que quien
 *    pinte la planilla pueda señalarla en vez de taparla.
 * 3. **El redondeo es el del código**: `cast(... as decimal(4,0))`, o sea entero,
 *    porque `notas_finales.nota` es un `int`. Cambiarlo aquí movería todas las
 *    definitivas del colegio en el despliegue, que no es lo que esta fase viene a
 *    hacer.
 * 4. **`manual` y `recuperada` no se tocan**, en un único punto y no en cinco.
 * 5. **La fila se identifica por `periodo_id`**, nunca por `periodo`, que queda
 *    como columna derivada. Es la §2.1: hoy el SELECT busca por una columna y el
 *    INSERT escribe las dos, así que una fila desincronizada es invisible para el
 *    SELECT y el INSERT añade la segunda.
 *
 * ## Por qué el UPSERT está escrito a mano y no con `ON DUPLICATE KEY`
 *
 * Porque **la clave única todavía no existe**: la pone la fase 2. Sin ella,
 * `INSERT ... ON DUPLICATE KEY UPDATE` no dispara nunca y se comporta como un
 * INSERT a secas, que es exactamente el fallo que se viene a arreglar. Aquí se
 * busca la fila, y **se decide por si existe, no por las filas que devuelve el
 * `UPDATE`** — todo dentro de la transacción de `recalcular()`.
 *
 * Esa distinción no es cosmética y costó un test en rojo: MySQL devuelve **0
 * filas afectadas cuando el `UPDATE` no cambia ningún valor**, no cuando no
 * encuentra la fila. Escrito de la forma natural —`UPDATE` y, si devuelve 0,
 * `INSERT`—, recalcular tres veces dentro del mismo segundo dejaba **tres
 * filas**: el fallo que esta clase viene a quitar, reintroducido por la forma de
 * escribir el UPSERT. Lo cazó `test_recalcular_dos_veces_no_duplica`.
 *
 * **Nada de esto es atómico frente a dos peticiones a la vez, y no puede serlo
 * hasta que exista el índice.** Lo que sí hace, y es la mitad del problema de
 * hoy, es que **no hay ventana de borrado**: nunca existe un instante en el que
 * la definitiva no esté. El día que la fase 2 ponga la clave, esto se convierte
 * en un `ON DUPLICATE KEY UPDATE` de una línea y este apartado se borra.
 */
class DefinitivasDeAsignatura
{
    /**
     * Recalcula las definitivas automáticas de una asignatura y un periodo.
     *
     * `respetadas` son las `manual` o `recuperada` que no se tocan;
     * `porcentaje_unidades` es la suma REAL de los porcentajes de las unidades,
     * que vale 100 cuando la asignatura está bien configurada y es lo que hay que
     * enseñar cuando no.
     *
     * `$soloAlumno` acota la ESCRITURA a un alumno sin cambiar el cálculo, que
     * sigue siendo el de la asignatura entera. Lo propone el propio plan para el
     * día que recalcular salga caro —«la salida no es dejar de recalcular sino
     * recalcular solo la fila de ese alumno, que es lo que cambió»— y lo usa el
     * boletín individual, donde ensanchar la escritura al grupo entero convertiría
     * «un acudiente abre el boletín de su hijo» en «un acudiente reescribe las
     * definitivas de treinta alumnos». Recalcularlas sería correcto; hacerlo desde
     * ahí no es lo que nadie espera.
     *
     * `definitiva` sólo viene cuando se pidió **un solo alumno** (`$soloAlumno`), y
     * es lo que quedó **guardado**, no lo calculado: si la fila era `manual` o
     * `recuperada` el bucle la respetó y las dos cosas no coinciden.
     *
     * @return array{escritas:int, creadas:int, respetadas:int, porcentaje_unidades:float,
     *     definitiva:array{alumno_id:int, asignatura_id:int, periodo_id:int, nota:int,
     *         manual:bool, recuperada:bool}|null}
     */
    /**
     * Recalcular la definitiva que depende de una nota, por el id de la nota.
     *
     * Existe porque **la nota no sabe de qué asignatura ni de qué periodo es**:
     * cuelga de la subunidad, la subunidad de la unidad, y la unidad sí lleva las
     * dos. Ese camino lo necesitan los tres sitios que tocan una nota suelta
     * —editarla, borrarla y la vía de `putSubunidad`— y tenerlo escrito una vez
     * evita la tercera copia de un `INNER JOIN` de tres tablas.
     *
     * **Recalcula sólo la fila de ese alumno**, que es lo único que pudo cambiar,
     * y por eso es barato llamarlo en cada nota tecleada. Devuelve `null` si la
     * nota no lleva a ninguna unidad viva — el llamante no tiene que decidir nada.
     */
    public static function recalcularPorNota(int $notaId, ?int $porUsuario = null): ?array
    {
        $donde = DB::selectOne(
            'SELECT u.asignatura_id, u.periodo_id, n.alumno_id
               FROM notas n
               INNER JOIN subunidades s ON s.id = n.subunidad_id
               INNER JOIN unidades u ON u.id = s.unidad_id
              WHERE n.id = ?',
            [$notaId]
        );

        if ($donde === null) {
            return null;
        }

        return self::recalcular(
            (int) $donde->asignatura_id,
            (int) $donde->periodo_id,
            $porUsuario,
            (int) $donde->alumno_id
        );
    }

    /**
     * Recalcular la asignatura y periodo a los que pertenece una **unidad**.
     *
     * **No se filtra `deleted_at` a propósito**, igual que hace `PeriodoDeLaFila`:
     * el caso que más lo necesita es justo el borrado —quitar una unidad cambia
     * los pesos de todas las demás—, y ahí la fila ya lleva su `deleted_at`
     * puesto cuando esto se llama.
     */
    public static function recalcularPorUnidad(int $unidadId, ?int $porUsuario = null): ?array
    {
        $donde = DB::selectOne(
            'SELECT asignatura_id, periodo_id FROM unidades WHERE id = ?',
            [$unidadId]
        );

        if ($donde === null) {
            return null;
        }

        return self::recalcular((int) $donde->asignatura_id, (int) $donde->periodo_id, $porUsuario);
    }

    /**
     * Lo mismo desde una **subunidad**, que no lleva ni asignatura ni periodo:
     * cuelga de la unidad y la unidad sí.
     */
    public static function recalcularPorSubunidad(int $subunidadId, ?int $porUsuario = null): ?array
    {
        $donde = DB::selectOne(
            'SELECT u.asignatura_id, u.periodo_id
               FROM subunidades s
               INNER JOIN unidades u ON u.id = s.unidad_id
              WHERE s.id = ?',
            [$subunidadId]
        );

        if ($donde === null) {
            return null;
        }

        return self::recalcular((int) $donde->asignatura_id, (int) $donde->periodo_id, $porUsuario);
    }

    public static function recalcular(
        int $asignaturaId,
        int $periodoId,
        ?int $porUsuario = null,
        ?int $soloAlumno = null
    ): array {
        return DB::transaction(function () use ($asignaturaId, $periodoId, $porUsuario, $soloAlumno) {
            $periodo = DB::selectOne(
                'SELECT id, numero FROM periodos WHERE id = ? AND deleted_at IS NULL',
                [$periodoId]
            );

            if ($periodo === null) {
                return ['escritas' => 0, 'creadas' => 0, 'respetadas' => 0,
                    'porcentaje_unidades' => 0.0, 'definitiva' => null];
            }

            $calculadas = self::calcular($asignaturaId, $periodoId);

            if ($soloAlumno !== null) {
                $calculadas = array_values(array_filter(
                    $calculadas,
                    fn ($fila) => (int) $fila->alumno_id === $soloAlumno
                ));
            }

            $escritas = 0;
            $creadas = 0;
            $respetadas = 0;

            foreach ($calculadas as $fila) {
                $existente = DB::selectOne(
                    'SELECT id, manual, recuperada FROM notas_finales
                      WHERE alumno_id = ? AND asignatura_id = ? AND periodo_id = ?
                      ORDER BY id LIMIT 1',
                    [$fila->alumno_id, $asignaturaId, $periodoId]
                );

                if ($existente !== null && ($existente->manual || $existente->recuperada)) {
                    $respetadas++;

                    continue;
                }

                // **Se decide por si la fila EXISTE, no por las filas afectadas**, y
                // esto costó un test en rojo que merece quedar escrito: la primera
                // versión hacía `UPDATE` y, si devolvía 0, `INSERT`. MySQL devuelve
                // 0 filas afectadas cuando el `UPDATE` no cambia ningún valor —no
                // cuando no encuentra la fila—, así que recalcular tres veces
                // dentro del mismo segundo, con la misma nota y el mismo
                // `updated_at`, dejaba **tres filas**. O sea el fallo exacto que
                // esta clase viene a quitar, reintroducido por la forma de
                // escribir el UPSERT.
                //
                // Lo cazó `test_recalcular_dos_veces_no_duplica`, que cuenta filas
                // en la tabla en vez de mirar lo que devuelve el servicio. Un
                // duplicado no se ve en la respuesta.
                //
                // `NOW()` y no `Carbon::now()`: la §4.5 dice que el fallo no es la
                // resolución de un segundo sino que los dos lados de la
                // comparación se escriban desde PHP, donde un desajuste de reloj o
                // de zona invierte el resultado. El sello se lee de la base, así
                // que la marca también se escribe ahí.
                if ($existente !== null) {
                    DB::update(
                        'UPDATE notas_finales
                            SET nota = ?, periodo = ?, updated_by = ?, updated_at = NOW()
                          WHERE id = ?',
                        [$fila->nota, $periodo->numero, $porUsuario, $existente->id]
                    );

                    $escritas++;

                    continue;
                }

                DB::insert(
                    'INSERT INTO notas_finales
                        (alumno_id, asignatura_id, periodo_id, periodo, nota, recuperada, manual,
                         updated_by, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, 0, 0, ?, NOW(), NOW())',
                    [$fila->alumno_id, $asignaturaId, $periodoId, $periodo->numero,
                        $fila->nota, $porUsuario]
                );

                $creadas++;
                $escritas++;
            }

            // Cuando se recalcula **un solo alumno** se devuelve además la
            // definitiva con la que se quedó. Es para el llamante que acaba de
            // guardar una nota y tiene que repintar la celda: sin esto la
            // planilla necesita **una petición HTTP más por nota tecleada** sólo
            // para leer un entero que aquí ya está.
            //
            // **Se lee de la tabla y no de `$calculadas`**, y la diferencia
            // importa: si la fila era `manual` o `recuperada` el bucle la
            // respetó, así que lo calculado NO es lo que hay guardado. Devolver
            // lo calculado haría que la pantalla pintara un valor que la base no
            // tiene — y justo en las filas que alguien puso a mano, que son las
            // que más se miran.
            //
            // Sin `$soloAlumno` no se devuelve: recalcular una asignatura entera
            // deja tantas definitivas como alumnos, y no hay «la» definitiva.
            $definitiva = null;

            if ($soloAlumno !== null) {
                $guardada = DB::selectOne(
                    'SELECT nota, manual, recuperada FROM notas_finales
                      WHERE alumno_id = ? AND asignatura_id = ? AND periodo_id = ?
                      ORDER BY id LIMIT 1',
                    [$soloAlumno, $asignaturaId, $periodoId]
                );

                if ($guardada !== null) {
                    $definitiva = [
                        'alumno_id' => $soloAlumno,
                        'asignatura_id' => $asignaturaId,
                        'periodo_id' => $periodoId,
                        'nota' => (int) $guardada->nota,
                        'manual' => (bool) $guardada->manual,
                        'recuperada' => (bool) $guardada->recuperada,
                    ];
                }
            }

            return [
                'escritas' => $escritas,
                'creadas' => $creadas,
                'respetadas' => $respetadas,
                'porcentaje_unidades' => self::porcentajeDeLasUnidades($asignaturaId, $periodoId),
                'definitiva' => $definitiva,
            ];
        });
    }

    /**
     * La definitiva que le toca a cada alumno matriculado, sin escribir nada.
     *
     * El `LEFT JOIN` es lo que separa esto de los seis escritores de hoy: parte de
     * las matrículas y deja en 0 al que no tiene notas, en vez de partir de las
     * notas y dejar fuera al alumno. **Un 0 aquí significa «sin notas», no «sacó
     * cero»** — la §4 avisa de que hoy los dos casos son indistinguibles porque
     * `round(NULL)` vale 0. Se conserva el 0 porque cambiarlo a NULL es una
     * decisión del colegio sobre lo que sale impreso en el boletín, no un arreglo.
     *
     * @return array<int, object{alumno_id:int, nota:int, notas:int}>
     */
    public static function calcular(int $asignaturaId, int $periodoId): array
    {
        return DB::select(
            'SELECT m.alumno_id,
                    CAST(COALESCE(c.suma, 0) AS DECIMAL(4,0)) AS nota,
                    COALESCE(c.notas, 0) AS notas
               FROM asignaturas a
               INNER JOIN grupos g ON g.id = a.grupo_id AND g.deleted_at IS NULL
               INNER JOIN matriculas m ON m.grupo_id = g.id AND m.deleted_at IS NULL
                    AND m.estado IN ("MATR", "ASIS")
               LEFT JOIN (
                    SELECT n.alumno_id,
                           SUM((u.porcentaje / 100) * ((s.porcentaje / 100) * n.nota)) AS suma,
                           COUNT(*) AS notas
                      FROM unidades u
                      INNER JOIN subunidades s ON s.unidad_id = u.id AND s.deleted_at IS NULL
                      INNER JOIN notas n ON n.subunidad_id = s.id AND n.deleted_at IS NULL
                     WHERE u.asignatura_id = ? AND u.periodo_id = ? AND u.deleted_at IS NULL
                     GROUP BY n.alumno_id
               ) c ON c.alumno_id = m.alumno_id
              WHERE a.id = ? AND a.deleted_at IS NULL
              GROUP BY m.alumno_id, c.suma, c.notas',
            [$asignaturaId, $periodoId, $asignaturaId]
        );
    }

    /**
     * El sello de versión de una asignatura y un periodo.
     *
     * Es lo que sustituye al `MAX(notas.updated_at)` de la §4, que miente de
     * cuatro maneras distintas. Aquí entra **todo lo que puede cambiar el
     * resultado**:
     *
     * - las notas vivas y **las borradas** (`deleted_at`) — cierra la §4.2, donde
     *   borrar una nota BAJA el máximo y la definitiva se declara al día;
     * - las unidades y subunidades, sus `updated_at` **y sus `deleted_at`** —
     *   cierra la §4.3: cambiar un porcentaje, añadir un indicador o eliminarlo
     *   cambia la definitiva y no toca ninguna nota;
     * - las matrículas del grupo — cierra la §4.4, el alumno que llega después.
     *
     * **Los conteos que el plan pedía no hacen falta**, y merece la pena por qué:
     * estaban para que «borrar una y añadir otra dentro del mismo segundo» no
     * pasara desapercibido, y eso ya lo coge la comparación conservadora de
     * `estaDesactualizada()` —en el empate se recalcula—. Añadirlos obligaría a
     * guardar el conteo en alguna parte, que es una columna nueva para un caso que
     * el empate ya cubre.
     *
     * Devuelve `null` cuando no hay nada de qué depender: asignatura sin unidades,
     * sin subunidades y sin matrículas. Eso es «no hay nada que calcular», y no
     * «está al día».
     */
    public static function selloDeVersion(int $asignaturaId, int $periodoId): ?string
    {
        $fila = DB::selectOne(
            'SELECT GREATEST(
                        COALESCE((SELECT MAX(GREATEST(COALESCE(n.updated_at, 0), COALESCE(n.deleted_at, 0)))
                                    FROM notas n
                                    INNER JOIN subunidades s ON s.id = n.subunidad_id
                                    INNER JOIN unidades u ON u.id = s.unidad_id
                                   WHERE u.asignatura_id = ? AND u.periodo_id = ?), 0),
                        COALESCE((SELECT MAX(GREATEST(COALESCE(u.updated_at, 0), COALESCE(u.deleted_at, 0)))
                                    FROM unidades u
                                   WHERE u.asignatura_id = ? AND u.periodo_id = ?), 0),
                        COALESCE((SELECT MAX(GREATEST(COALESCE(s.updated_at, 0), COALESCE(s.deleted_at, 0)))
                                    FROM subunidades s
                                    INNER JOIN unidades u ON u.id = s.unidad_id
                                   WHERE u.asignatura_id = ? AND u.periodo_id = ?), 0),
                        COALESCE((SELECT MAX(m.created_at)
                                    FROM matriculas m
                                    INNER JOIN asignaturas a ON a.grupo_id = m.grupo_id
                                   WHERE a.id = ? AND m.deleted_at IS NULL), 0)
                    ) AS sello',
            [$asignaturaId, $periodoId, $asignaturaId, $periodoId,
                $asignaturaId, $periodoId, $asignaturaId]
        );

        $sello = $fila->sello ?? null;

        // `GREATEST` sobre puros ceros da `0`, que no es una fecha. Se traduce a
        // null para que quien llame no se lo crea como si fuera una marca real.
        if ($sello === null || (string) $sello === '0' || str_starts_with((string) $sello, '0000')) {
            return null;
        }

        return (string) $sello;
    }

    /**
     * Si la definitiva de un alumno está por detrás de lo que la produce.
     *
     * **En el empate se recalcula**, que es lo conservador: `timestamp` guarda
     * segundos, y una nota cambiada en el mismo segundo en que se guardó la
     * definitiva es indistinguible de una anterior. Recalcular de más es
     * inofensivo —la §4.5 lo dice—; declararla al día sin serlo es el fallo que
     * este método viene a quitar.
     *
     * Sin fila, está desactualizada por definición: la §9.1 dice que la fila
     * existe siempre que exista la matrícula, así que «no está» es un estado que
     * hay que reparar y no uno que haya que respetar.
     */
    public static function estaDesactualizada(int $asignaturaId, int $periodoId, int $alumnoId): bool
    {
        $definitiva = DB::selectOne(
            'SELECT updated_at, manual, recuperada FROM notas_finales
              WHERE alumno_id = ? AND asignatura_id = ? AND periodo_id = ?
              ORDER BY id LIMIT 1',
            [$alumnoId, $asignaturaId, $periodoId]
        );

        if ($definitiva === null) {
            return true;
        }

        // Una definitiva puesta a mano no se recalcula nunca, así que preguntar si
        // está desactualizada no significa nada para ella.
        if ($definitiva->manual || $definitiva->recuperada) {
            return false;
        }

        $sello = self::selloDeVersion($asignaturaId, $periodoId);

        if ($sello === null || $definitiva->updated_at === null) {
            return $sello !== null;
        }

        return strtotime($sello) >= strtotime((string) $definitiva->updated_at);
    }

    /**
     * La suma real de los porcentajes de las unidades de una asignatura y periodo.
     *
     * Vale 100 cuando está bien configurada. **Se devuelve en vez de corregirse**
     * porque la §9.3 decidió que la fórmula no normaliza: una asignatura cuyos
     * porcentajes no suman 100 da definitivas raras, y que se noten es lo que la
     * delata. Quien pinta la planilla tiene aquí con qué señalarla.
     */
    public static function porcentajeDeLasUnidades(int $asignaturaId, int $periodoId): float
    {
        $fila = DB::selectOne(
            'SELECT COALESCE(SUM(porcentaje), 0) AS suma FROM unidades
              WHERE asignatura_id = ? AND periodo_id = ? AND deleted_at IS NULL',
            [$asignaturaId, $periodoId]
        );

        return (float) ($fila->suma ?? 0);
    }
}
