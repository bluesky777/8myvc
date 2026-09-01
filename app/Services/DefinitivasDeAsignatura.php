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
 * **Esta clase la llaman 18 sitios de seis ficheros**, y hasta el 26 ago 2026 esta
 * línea decía *«todavía no la llama nadie»*. Era cierta el día que se escribió y la
 * fase 3 la dejó vieja sin que nadie volviera a leerla:
 *
 * | fichero | llamadas |
 * |---|---|
 * | `Models/NotaFinal` | 4 |
 * | `NotasController` | 6 — 4 a `recalcular`, 1 a `recalcularPorNota`, 1 a `estaDesactualizada` |
 * | `SubunidadesController` | 3 |
 * | `UnidadesController` | 2 |
 * | `Informes/BoletinesController` | 2 |
 * | `PeriodosController` | 1 |
 *
 * Se recuenta con `grep -rn 'DefinitivasDeAsignatura::' app/`, **que da 19 y no 18**:
 * el sobrante es una **mención dentro de un comentario** de
 * `DefinitivasPeriodosController:269`, no una llamada.
 *
 * Lo que **sigue** pendiente es la **fase 2** —la migración que limpia y pone las
 * claves únicas—, que no se puede desplegar sola: el índice con el código viejo
 * convierte cada duplicado en un 500. Y sigue bloqueada por el mismo dato de
 * siempre, los números de la fase 0 de los quince colegios.
 *
 * > **La cabecera de una clase es lo que más se lee y lo que menos se releé.**
 * > Ésta afirmaba lo contrario de lo que hacía el fichero, y quien la creyera
 * > habría dado por seguro tocar aquí lo que hoy corre en quince producciones.
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
 * 3. **Ya no hay redondeo**: `cast(... as decimal(7,4))`, que es lo que cabe en la
 *    columna desde la migración `2026_08_30_200000_notas_finales_en_decimal`.
 *    Decía «el redondeo es el del código, `decimal(4,0)`, porque la columna es un
 *    `int`» — y añadía que cambiarlo movería todas las definitivas del colegio, que
 *    **sigue siendo verdad y ahora es el objetivo**: los empates de puesto salían de
 *    aquí. Los cuatro decimales no son un margen elegido a ojo, son los que hacen el
 *    cálculo **exacto** (`nota * pct_sub * pct_uni / 10000`, los tres enteros);
 *    medido en la cabecera de esa migración, 0 de 125.352 filas se salen.
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
            'SELECT asignatura_id, periodo_id, alumno_id FROM unidades WHERE id = ?',
            [$unidadId]
        );

        if ($donde === null) {
            return null;
        }

        return self::recalcular(
            (int) $donde->asignatura_id,
            (int) $donde->periodo_id,
            $porUsuario,
            self::duenoDeLaUnidad($donde)
        );
    }

    /**
     * Lo mismo desde una **subunidad**, que no lleva ni asignatura ni periodo:
     * cuelga de la unidad y la unidad sí.
     */
    public static function recalcularPorSubunidad(int $subunidadId, ?int $porUsuario = null): ?array
    {
        $donde = DB::selectOne(
            'SELECT u.asignatura_id, u.periodo_id, u.alumno_id
               FROM subunidades s
               INNER JOIN unidades u ON u.id = s.unidad_id
              WHERE s.id = ?',
            [$subunidadId]
        );

        if ($donde === null) {
            return null;
        }

        return self::recalcular(
            (int) $donde->asignatura_id,
            (int) $donde->periodo_id,
            $porUsuario,
            self::duenoDeLaUnidad($donde)
        );
    }

    /**
     * El alumno al que hay que acotar la ESCRITURA, o `null` si la unidad es del grupo.
     *
     * ## Por qué el alcance se lee aquí y no se lo pasa el llamante
     *
     * Porque **ninguno de los cinco llamadores tiene un alumno a mano, y no es un
     * descuido suyo**: los cinco editan o borran una unidad o una subunidad
     * —`UnidadesController::putUpdate` y `deleteDestroy`, y los tres de
     * `SubunidadesController`—, y una unidad del grupo **le cambia la definitiva a
     * los treinta**, así que ahí recalcular entero es lo correcto. Pedirles el
     * alumno sería pedirles un dato que su petición no tiene.
     *
     * Lo que distingue los dos casos no está en el llamante: está en la unidad.
     * `unidades.alumno_id` —la columna que trajo el boletín independiente— dice de
     * quién es. Si tiene dueño, **esa unidad sólo entra en el cálculo de ese
     * alumno** (lo hace `calcular()`, con su `c.dueno <=> ALCANCE`), y reescribir a
     * los demás no es que sea caro: es que **`recalcular()` crea la fila que falta**
     * —los alumnos salen de `matriculas`, regla 1— y aparecerían definitivas a
     * cero donde no había ninguna, firmadas por quien editó la unidad de otro.
     *
     * ## Hoy no cambia nada, y eso es comprobable
     *
     * `unidades.alumno_id` es NULL en todas las filas mientras nadie esté marcado,
     * así que esto devuelve `null` siempre y `recalcular()` hace exactamente lo de
     * antes. **No espera a la decisión de a quién se marca**: es la red puesta antes
     * de que haya con qué caerse.
     *
     * @param  object  $unidad  con `alumno_id` dentro
     */
    private static function duenoDeLaUnidad(object $unidad): ?int
    {
        return $unidad->alumno_id === null ? null : (int) $unidad->alumno_id;
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

            // **Sin unidades no se escribe nada.** Decisión de Joseth, 28 ago 2026,
            // y es lo que separa la regla 1 de su efecto secundario.
            //
            // La regla 1 —los alumnos salen de `matriculas`, no de `notas`— existe
            // para que **un alumno sin ninguna nota conserve su fila**, que es lo que
            // los seis escritores viejos no hacen. Pero cuando la asignatura no tiene
            // NINGUNA unidad en el periodo, esa misma regla escribe **una definitiva a
            // cero por cada matriculado** sobre un periodo que nadie ha montado.
            //
            // Y no es hipotético: `UnidadesController::deleteDestroy` llama a
            // `recalcularPorUnidad` **después** del borrado —a propósito, porque quitar
            // una unidad cambia los pesos de las demás—, así que **borrar la última
            // unidad de un periodo escribía treinta ceros firmados por quien la borró**.
            // Medido el 28 ago 2026 sobre la asignatura 1300 del grupo 104:
            // `escritas=30`, las 31 definitivas del periodo a cero. Lo cuenta entero
            // `docs/migracion/noche-2026-08-28/desact-1.md` §5.
            //
            // **Los dos casos se distinguen aquí y hay que no confundirlos**, porque dan
            // el mismo síntoma —una definitiva a cero— por motivos opuestos:
            //
            //   - *no hay unidades*        -> no se escribe. Esto.
            //   - *hay unidades y este alumno no tiene notas* -> **se escribe el cero**,
            //     que es la regla 1 y sigue intacta.
            //
            // **Se pregunta por las unidades y NO por `porcentajeDeLasUnidades()`**,
            // aunque la decisión se enunciara como «porcentaje 0» y hoy las dos den lo
            // mismo (0 pares de 3.930 con unidades vivas sumando 0). Por dos razones, y
            // ninguna es el dato:
            //
            //   1. **el esquema no impide `porcentaje = 0`**, y una medición usada como
            //      guardián es lo que este repositorio ya pagó una noche;
            //   2. ese método es **el reparto de UN boletín** desde el 31 ago 2026, y
            //      colgar de él la decisión de escribir sería atar una escritura a un
            //      número que con dos boletines no tiene una sola respuesta.
            //
            // **No borra lo que ya hubiera**: la decisión fue «sin unidades no se
            // escribe», no «se limpia». La limpieza de lo viejo la hace el botón de
            // Informes, que ya la hace.
            //
            // ## La puerta es POR BOLETÍN, y con un solo booleano mezclaba dos casos opuestos
            //
            // Esto era un `EXISTS` sobre la asignatura entera, y con boletines
            // independientes **contestaba la pregunta de otro** en las dos direcciones:
            //
            //   - **el grupo sin montar y un independiente con sus unidades** ->
            //     `hay = 1`, y a los treinta del grupo se les escribe **el cero que esta
            //     misma guarda existe para no escribir**. Es el fallo del 28 ago entrando
            //     otra vez por una puerta nueva;
            //   - **el grupo montado y el marcado sin nada suyo** -> `hay = 1`, y **su**
            //     cero se escribe: es la §9.1 del 19 —el alumno que se cae por el hueco—
            //     con una definitiva en cero que parece una nota.
            //
            // Así que la pregunta se hace **por dueño**: qué boletines de esta asignatura
            // y periodo tienen alguna unidad viva. `NULL` en la lista es el del grupo.
            // Una sola consulta, no una por alumno.
            //
            // **Con nadie marcado esto no mueve nada, y es comprobable**: todas las
            // unidades son del grupo, `calcular()` da `dueno = NULL` para todo el mundo, y
            // el conjunto es `{NULL}` si hay unidades y vacío si no — que son exactamente
            // las dos ramas del booleano de antes.
            $conUnidades = DB::select(
                'SELECT DISTINCT alumno_id FROM unidades
                  WHERE asignatura_id = ? AND periodo_id = ? AND deleted_at IS NULL',
                [$asignaturaId, $periodoId]
            );

            // Se compara en PHP y no en SQL porque `calcular()` ya trae el dueño de cada
            // fila: volver a bajar a la base sería preguntar dos veces lo mismo. Las
            // claves se normalizan a cadena porque PDO devuelve los enteros como cadena
            // y `null` como `null`, y `in_array` con `0 == null` es de los sitios donde
            // PHP muerde.
            $boletinesMontados = [];

            foreach ($conUnidades as $fila) {
                $boletinesMontados[$fila->alumno_id === null ? 'grupo' : (string) (int) $fila->alumno_id] = true;
            }

            $calculadas = $boletinesMontados === []
                ? []
                : array_values(array_filter(
                    self::calcular($asignaturaId, $periodoId),
                    static fn ($fila) => isset($boletinesMontados[
                        $fila->dueno === null ? 'grupo' : (string) (int) $fila->dueno
                    ])
                ));

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
                        // `(float)` y no `(int)`, y no es cosmético: desde que la
                        // columna es `DECIMAL`, PDO la devuelve como **cadena**
                        // (`"43.7500"`), y `(int)` de eso **trunca** —43, no 44—.
                        // O sea que dejarlo en `(int)` no sólo tiraba los decimales
                        // que esta migración viene a conservar: los tiraba **hacia
                        // abajo**, que es peor que el `round()` que había antes.
                        // El `(float)` además mantiene el valor como número en el
                        // JSON; la cadena cruda lo convertiría en `"43.7500"` y le
                        // cambiaría el tipo al front.
                        'nota' => (float) $guardada->nota,
                        'manual' => (bool) $guardada->manual,
                        'recuperada' => (bool) $guardada->recuperada,
                    ];
                }
            }

            // **El reparto que se devuelve es el del MISMO boletín que la `definitiva`
            // de la línea de abajo**, y por eso se le pasa el alcance en vez de
            // dejarlo por defecto. Con un solo alumno pedido, el número es el de SU
            // boletín —el del grupo si va con el grupo, el suyo si va aparte—; sin
            // alumno, el recálculo cubre la asignatura entera y la única respuesta
            // honesta es la del grupo.
            //
            // Devolver la suma de los dos repartos, que es lo que hacía, daba **un
            // número que no era el de ningún boletín** junto a una definitiva que sí
            // era de uno concreto: dos campos del mismo array hablando de cosas
            // distintas, y nada que lo dijera.
            $alcance = $soloAlumno !== null
                ? BoletinIndependiente::alcance($soloAlumno, $periodoId)
                : null;

            return [
                'escritas' => $escritas,
                'creadas' => $creadas,
                'respetadas' => $respetadas,
                'porcentaje_unidades' => self::porcentajeDeLasUnidades($asignaturaId, $periodoId, $alcance),
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
     * @return array<int, object{alumno_id:int, dueno:?int, nota:int, notas:int}>
     */
    public static function calcular(int $asignaturaId, int $periodoId): array
    {
        // **El alcance del boletín independiente, BI-2.** Esta consulta resuelve el
        // grupo entero de una vez y no puede preguntar alumno por alumno, así que
        // usa la forma que `BoletinIndependiente` dejó para eso: el `LEFT JOIN` con
        // `bol_ind_periodos` y la expresión `ALCANCE`, que da el id del alumno si va
        // por independiente en ese periodo y `NULL` si no.
        //
        // **La derivada agrupa además por `u.alumno_id` y el emparejamiento es
        // `<=>`.** Sin el `GROUP BY` extra, las unidades del grupo y las de un
        // independiente caerían en la misma suma y le inflarían la definitiva a los
        // treinta; sin el `<=>` —con `=` a secas— el alumno normal no emparejaría
        // nada y **todas las definitivas del colegio se irían a 0 sin un error en el
        // log**, que es el fallo más caro que este fichero puede introducir.
        //
        // **Hoy no mueve nada y es comprobable:** `matriculas.boletin_independiente`
        // es 0 en todas las filas y `unidades.alumno_id` es NULL en todas, así que
        // `c.dueno <=> ALCANCE` es `NULL <=> NULL` para todo el mundo y selecciona
        // exactamente las filas de antes. Lo fija
        // `Tests\Contrato\DefinitivaConAlcanceTest`, que compara la definitiva de
        // todo un grupo antes y después con la unidad marcada y sin marcar.
        //
        // El `bip.periodo_id` va con parámetro y no con `u.periodo_id` como la
        // constante `JOIN_ESTADO`, porque aquí `u` vive dentro de la derivada y no
        // está en el ámbito de la consulta de fuera. Es la excepción que la cabecera
        // de `BoletinIndependiente` pide declarar en vez de copiar a mano.
        return DB::select(
            'SELECT m.alumno_id,
                    '.BoletinIndependiente::ALCANCE.' AS dueno,
                    CAST(COALESCE(c.suma, 0) AS DECIMAL(7,4)) AS nota,
                    COALESCE(c.notas, 0) AS notas
               FROM asignaturas a
               INNER JOIN grupos g ON g.id = a.grupo_id AND g.deleted_at IS NULL
               -- **Sin filtro de `m.estado`, y es una decisión de Joseth del 28 ago 2026**,
               -- no un descuido: *«el recálculo debe cubrir a todos los alumnos, incluidos
               -- los que se fueron»*. Aquí decía `m.estado IN ("MATR","ASIS")`.
               --
               -- Lo que lo trajo: el botón `calcular-grupo-periodo` **borra** las
               -- automáticas del grupo sin mirar la matrícula y **repone** a todo el que
               -- tenga notas, así que hoy cubre a los retirados. Esta clase es la fase 3 —
               -- la que lo sustituye—, y con el filtro puesto la sustitución le quitaba la
               -- definitiva a **6.435 pares (alumno, asignatura) de 314 retirados** sin un
               -- solo error: sólo un alumno que deja de tener nota. Medido y contado en
               -- `docs/migracion/noche-2026-08-28/desact-1.md` §6.
               --
               -- **Y NO es «igual que los informes», aunque así se enunciara.** Se le
               -- planteó con la medición delante: el boletín (`BoletinesController:435`) y
               -- `Grupo::alumnos` admiten `MATR`, `ASIS` y `PREM` — **los informes no
               -- enseñan a los retirados**. Esto es *más* que los informes, elegido a
               -- sabiendas: la definitiva de quien se fue se conserva aunque su boletín no
               -- se imprima. Quien venga a «alinearlo con los informes» estaría
               -- deshaciendo la decisión, no completándola.
               --
               -- **El duplicado que esto podría abrir, medido**: `matriculas` no tiene
               -- clave única sobre `(alumno_id, grupo_id)`, así que sin el filtro de estado
               -- un alumno con dos matrículas vivas en el mismo grupo daría dos filas aquí.
               -- Hoy son **0 pares de 3.542**, con el filtro y sin él. Y no se apoya en ese
               -- dato: `recalcular()` decide **por si la fila existe**, así que la segunda
               -- vuelta actualiza en vez de insertar y no puede nacer una gemela.
               INNER JOIN matriculas m ON m.grupo_id = g.id AND m.deleted_at IS NULL
               LEFT JOIN bol_ind_periodos bip
                    ON bip.alumno_id = m.alumno_id AND bip.periodo_id = ?
               LEFT JOIN (
                    SELECT n.alumno_id, u.alumno_id AS dueno,
                           SUM((u.porcentaje / 100) * ((s.porcentaje / 100) * n.nota)) AS suma,
                           COUNT(*) AS notas
                      FROM unidades u
                      INNER JOIN subunidades s ON s.unidad_id = u.id AND s.deleted_at IS NULL
                      INNER JOIN notas n ON n.subunidad_id = s.id AND n.deleted_at IS NULL
                     WHERE u.asignatura_id = ? AND u.periodo_id = ? AND u.deleted_at IS NULL
                     GROUP BY n.alumno_id, u.alumno_id
               ) c ON c.alumno_id = m.alumno_id AND c.dueno <=> '.BoletinIndependiente::ALCANCE.'
              WHERE a.id = ? AND a.deleted_at IS NULL
              GROUP BY m.alumno_id, dueno, c.suma, c.notas',
            [$periodoId, $asignaturaId, $periodoId, $asignaturaId]
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

    // **BI-2: esto NO se acota, y acotarlo sería un fallo.** Es una de las 25
    // lecturas de `unidades` sin alcance del boletín independiente, y la única
    // donde el criterio del lote —«acotar es más correcto»— mete el error.
    //
    // Esto es un SELLO DE CACHÉ: dice si hay que recalcular. Su modo de fallo NO
    // es simétrico:
    //
    //   sin acotar  el sello cambia cuando un independiente toca SU unidad
    //               -> recalcula de más. Cuesta tiempo. Nunca sirve un dato viejo.
    //   acotado     deja de moverse cuando cambia el boletín de ese alumno
    //               -> sirve un dato VIEJO, y sin un solo error en el log.
    //
    // **La sobre-aproximación no es un defecto que se tolera aquí: es lo que lo
    // hace correcto.** Si alguien viene en la pasada siguiente aplicando el
    // criterio del lote a las que faltan, ésta hay que saltársela — y por eso el
    // porqué vive aquí y no sólo en `docs/migracion/noche-2026-08-25/bi-2.md` §6.bis.
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
     * El estado de las definitivas de un grupo entero y un periodo, en UNA consulta.
     *
     * Es `estaDesactualizada()` preguntado por todo el grupo a la vez, y existe por
     * lo que cuesta la otra forma: `estaDesactualizada()` es **por alumno y
     * asignatura** y cada llamada gasta dos consultas —la fila y el sello, que a su
     * vez son cuatro subconsultas—. Un boletín de grupo son ~12 asignaturas × ~30
     * alumnos, o sea **~720 consultas sólo para preguntar si hace falta recalcular**,
     * sobre la pantalla que ya tarda 24–63 s. Ésta contesta lo mismo en una.
     *
     * **No la llama nadie todavía, y es deliberado.** El punto 2 del plan de
     * informes —qué hace un informe cuando descubre que sus definitivas están por
     * detrás: repararlas o avisar— es una decisión de Joseth, y se toma con el coste
     * medido delante. Lo que hace falta en las dos ramas es este número, así que se
     * escribe antes que la decisión y no después.
     *
     * ## Qué devuelve, y por qué una fila por asignatura y no un bool
     *
     * Un bool por grupo contestaría «algo está desactualizado» y **eso no basta para
     * ninguna de las dos ramas**: la que repara necesita saber QUÉ recalcular —
     * recalcular el grupo entero es volver al botón—, y la que avisa necesita
     * nombrar la asignatura. Por eso una fila por asignatura viva del grupo, con:
     *
     * - `alumnos`: los matriculados que se miraron. **Va aunque salga todo al día**,
     *   por la regla del CLAUDE.md: un «0 desactualizadas» sin población no
     *   distingue *«revisé treinta y ninguna lo estaba»* de *«no revisé nada»*, y de
     *   las dos lecturas la falsa es la que hace archivar el asunto;
     * - `faltan`: matriculados **sin fila** — la §9.1 dice que la fila existe
     *   siempre que exista la matrícula, así que «no está» es un estado que hay que
     *   reparar, no uno que haya que respetar. Son las 11.988 de la fase 0;
     * - `atrasadas`: filas automáticas cuyo `updated_at` no alcanza al sello;
     * - `sello`: el mismo de `selloDeVersion()`, para que quien pinte el aviso pueda
     *   decir desde cuándo.
     *
     * ## Los tres sitios donde tenía que coincidir con `estaDesactualizada()`
     *
     * Un detector que conteste *parecido* al método que dice replicar es peor que no
     * tenerlo, así que los tres criterios se copian y no se reinterpretan:
     *
     * 1. **Los alumnos salen de `matriculas` con `estado IN ("MATR","ASIS")` y el
     *    grupo vivo**, que es de donde los saca `calcular()`. Con cualquier otro
     *    conjunto, `faltan` contaría alumnos a los que el recalculador **no les
     *    escribe nunca** y la asignatura saldría desactualizada para siempre: un
     *    informe que repara entraría en un recálculo en cada carga sin arreglar nada.
     * 2. **`manual` y `recuperada` no cuentan como atrasadas**, porque no se
     *    recalculan (regla 4).
     * 3. **Con el sello a NULL nada está atrasado** —no hay unidades, ni
     *    subunidades, ni notas de las que depender—, que es lo que contesta
     *    `estaDesactualizada()` en ese caso. Y **en el empate se cuenta como
     *    atrasada** (`<=`), que es la comparación conservadora de la §4.5: recalcular
     *    de más cuesta tiempo, declararla al día sin serlo es el fallo que esto viene
     *    a quitar.
     *
     * Eso lo ata `test_el_estado_del_grupo_dice_lo_mismo_que_preguntar_una_a_una`,
     * que compara las dos formas asignatura por asignatura y alumno por alumno. Es
     * el control que importa: **la consulta agregada es rápida por ser otra
     * consulta, y por eso hay que demostrar que contesta la misma pregunta.**
     *
     * ## Dos cosas que NO hace, a propósito
     *
     * **No acota por el boletín independiente**, igual que `selloDeVersion()` y por
     * el mismo motivo escrito allí: un sello que se sobre-aproxima recalcula de más
     * —cuesta tiempo—, y uno acotado sirve un dato viejo sin un error en el log.
     *
     * **No cuenta duplicados.** Mira la fila de `id` menor, que es la que mira
     * `estaDesactualizada()` con su `ORDER BY id LIMIT 1`; contar duplicados es de
     * `tools/salud-de-las-definitivas.php`, y mezclarlo aquí haría que las dos
     * formas dejaran de coincidir justo en las filas que la fase 2 va a limpiar.
     *
     * @return array<int, array{asignatura_id:int, sello:string|null, alumnos:int,
     *     faltan:int, atrasadas:int, desactualizada:bool}>
     */
    public static function estadoDelGrupo(int $grupoId, int $periodoId): array
    {
        // **El centinela es una FECHA y no un `0`, y esto costó los dos primeros
        // rojos del test de equivalencia.** `selloDeVersion()` escribe
        // `COALESCE(x, 0)` y le funciona porque devuelve el valor a PHP, que lo
        // compara con `strtotime`. Aquí la comparación ocurre **dentro de MySQL**, y
        // con un `0` entre los argumentos el `GREATEST` pasa a comparar **como
        // números**: `2026-08-28 04:16:41` vale **2026**, cualquier `updated_at`
        // vale catorce cifras, y `updated_at <= sello` es falso siempre.
        //
        // El modo de fallo es el peor posible para un detector: **cero
        // desactualizadas, siempre**, sin un error en el log y con la columna
        // imprimiéndose como la fecha correcta —lo que delata el tipo es que
        // `CAST(sello AS DATETIME)` sale NULL—. Un informe cableado a esto habría
        // dicho «todo al día» sobre un grupo entero por detrás.
        //
        // `1000-01-01 00:00:00` es el mínimo que admite `DATETIME`, así que hace de
        // «no hay nada» sin poder confundirse con una fecha real, y el `NULLIF` lo
        // vuelve a convertir en el `null` que ya devuelve `selloDeVersion()`.
        $filas = DB::select(
            'SELECT asignatura_id,
                    sello,
                    COUNT(*) AS alumnos,
                    SUM(nf_id IS NULL) AS faltan,
                    SUM(nf_id IS NOT NULL AND automatica = 1 AND sello IS NOT NULL
                        AND (nf_updated_at IS NULL OR nf_updated_at <= sello)) AS atrasadas
               FROM (
                    SELECT a.id AS asignatura_id,
                           CAST(NULLIF(GREATEST(
                               COALESCE(sn.sello, CAST("1000-01-01 00:00:00" AS DATETIME)),
                               COALESCE(su.sello, CAST("1000-01-01 00:00:00" AS DATETIME)),
                               COALESCE(ss.sello, CAST("1000-01-01 00:00:00" AS DATETIME)),
                               COALESCE(sm.sello, CAST("1000-01-01 00:00:00" AS DATETIME))
                           ), CAST("1000-01-01 00:00:00" AS DATETIME)) AS DATETIME) AS sello,
                           nf.id AS nf_id,
                           nf.updated_at AS nf_updated_at,
                           ((nf.manual IS NULL OR nf.manual = 0)
                                AND (nf.recuperada IS NULL OR nf.recuperada = 0)) AS automatica
                      FROM asignaturas a
                      INNER JOIN grupos g ON g.id = a.grupo_id AND g.deleted_at IS NULL
                      INNER JOIN matriculas m ON m.grupo_id = g.id AND m.deleted_at IS NULL
                           AND m.estado IN ("MATR", "ASIS")
                      LEFT JOIN (
                            SELECT nf2.alumno_id, nf2.asignatura_id, MIN(nf2.id) AS id
                              FROM notas_finales nf2
                              INNER JOIN asignaturas aa ON aa.id = nf2.asignatura_id
                             WHERE aa.grupo_id = ? AND nf2.periodo_id = ?
                             GROUP BY nf2.alumno_id, nf2.asignatura_id
                      ) primera ON primera.alumno_id = m.alumno_id AND primera.asignatura_id = a.id
                      LEFT JOIN notas_finales nf ON nf.id = primera.id
                      LEFT JOIN (
                            SELECT u.asignatura_id,
                                   CAST(MAX(GREATEST(COALESCE(n.updated_at, CAST("1000-01-01 00:00:00" AS DATETIME)), COALESCE(n.deleted_at, CAST("1000-01-01 00:00:00" AS DATETIME)))) AS DATETIME) AS sello
                              FROM notas n
                              INNER JOIN subunidades s ON s.id = n.subunidad_id
                              INNER JOIN unidades u ON u.id = s.unidad_id
                              INNER JOIN asignaturas aa ON aa.id = u.asignatura_id
                             WHERE aa.grupo_id = ? AND u.periodo_id = ?
                             GROUP BY u.asignatura_id
                      ) sn ON sn.asignatura_id = a.id
                      LEFT JOIN (
                            SELECT u.asignatura_id,
                                   CAST(MAX(GREATEST(COALESCE(u.updated_at, CAST("1000-01-01 00:00:00" AS DATETIME)), COALESCE(u.deleted_at, CAST("1000-01-01 00:00:00" AS DATETIME)))) AS DATETIME) AS sello
                              FROM unidades u
                              INNER JOIN asignaturas aa ON aa.id = u.asignatura_id
                             WHERE aa.grupo_id = ? AND u.periodo_id = ?
                             GROUP BY u.asignatura_id
                      ) su ON su.asignatura_id = a.id
                      LEFT JOIN (
                            SELECT u.asignatura_id,
                                   CAST(MAX(GREATEST(COALESCE(s.updated_at, CAST("1000-01-01 00:00:00" AS DATETIME)), COALESCE(s.deleted_at, CAST("1000-01-01 00:00:00" AS DATETIME)))) AS DATETIME) AS sello
                              FROM subunidades s
                              INNER JOIN unidades u ON u.id = s.unidad_id
                              INNER JOIN asignaturas aa ON aa.id = u.asignatura_id
                             WHERE aa.grupo_id = ? AND u.periodo_id = ?
                             GROUP BY u.asignatura_id
                      ) ss ON ss.asignatura_id = a.id
                      LEFT JOIN (
                            SELECT m2.grupo_id, MAX(m2.created_at) AS sello
                              FROM matriculas m2
                             WHERE m2.grupo_id = ? AND m2.deleted_at IS NULL
                             GROUP BY m2.grupo_id
                      ) sm ON sm.grupo_id = g.id
                     WHERE a.grupo_id = ? AND a.deleted_at IS NULL
               ) f
              GROUP BY asignatura_id, sello
              ORDER BY asignatura_id',
            [$grupoId, $periodoId, $grupoId, $periodoId, $grupoId, $periodoId,
                $grupoId, $periodoId, $grupoId, $grupoId]
        );

        return array_map(static function ($fila): array {
            $faltan = (int) $fila->faltan;
            $atrasadas = (int) $fila->atrasadas;

            return [
                'asignatura_id' => (int) $fila->asignatura_id,
                'sello' => $fila->sello === null ? null : (string) $fila->sello,
                'alumnos' => (int) $fila->alumnos,
                'faltan' => $faltan,
                'atrasadas' => $atrasadas,
                'desactualizada' => $faltan > 0 || $atrasadas > 0,
            ];
        }, $filas);
    }

    /**
     * La suma real de los porcentajes de las unidades **de UN boletín**.
     *
     * Vale 100 cuando está bien configurado. **Se devuelve en vez de corregirse**
     * porque la §9.3 decidió que la fórmula no normaliza: unos porcentajes que no
     * suman 100 dan definitivas raras, y que se noten es lo que los delata. Quien
     * pinta la planilla tiene aquí con qué señalarlos.
     *
     * ## `$alcance` es obligatorio, y ésa es toda la corrección
     *
     * Este método fue **la única de las lecturas del [19](../../docs/migracion/19-boletin-independiente.md)
     * que no se podía acotar añadiendo una condición**, y por eso llevaba un rojo
     * puesto en vez de un arreglo: contestaba *«¿las unidades de esta asignatura
     * suman 100?»* devolviendo un `float`, y **con boletines independientes esa
     * pregunta no tiene una sola respuesta** — hay un reparto por boletín, el del
     * grupo y el de cada alumno marcado. Sumarlos todos daba un número que **no era
     * el reparto de ninguno**.
     *
     * El rojo esperaba *«las dos preguntas del 19 §2, que son de Joseth»*. **Están
     * contestadas** (decisiones 5, 6 y 7 del 31 ago 2026), así que el bloqueo se
     * levantó y esto pasa a la suite.
     *
     * **`$alcance` no lleva defecto a propósito.** Un `?int $alcance = null` habría
     * dejado que los llamadores de antes siguieran compilando y **cambiándoles el
     * significado en silencio**, que es exactamente el modo de fallo que este
     * módulo lleva tres revisiones quitando. Sin defecto, cada llamador **tiene que
     * decir de qué boletín pregunta**, y el que no lo sepa no compila.
     *
     * @param  ?int  $alcance  `null` = el boletín del grupo; un id = el de ese alumno.
     *                         Sale de `BoletinIndependiente::alcance()`, nunca a mano.
     */
    public static function porcentajeDeLasUnidades(int $asignaturaId, int $periodoId, ?int $alcance): float
    {
        // `<=>` y no `=`: el igual null-safe empareja NULL con NULL, así que esta
        // única condición resuelve las dos ramas —el reparto del grupo contra
        // `alumno_id IS NULL`, el del independiente contra el suyo—. Con `=` a secas
        // la rama del grupo devuelve cero filas y **el reparto sale 0 para todo el
        // mundo**, que aquí se lee como «asignatura sin montar».
        $fila = DB::selectOne(
            'SELECT COALESCE(SUM(porcentaje), 0) AS suma FROM unidades
              WHERE asignatura_id = ? AND periodo_id = ? AND deleted_at IS NULL
                AND alumno_id <=> ?',
            [$asignaturaId, $periodoId, $alcance]
        );

        return (float) ($fila->suma ?? 0);
    }
}
