<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * **Qué pasa con las casillas que nadie calificó el día que se cierra el periodo.**
 * Un solo sitio.
 *
 * Es la **fase 4** de
 * [43-lo-que-todavia-no-se-ha-calificado.md](../../docs/migracion/43-lo-que-todavia-no-se-ha-calificado.md),
 * decisión **D3** de Joseth: *«al cerrar, qué pasa con lo no calificado lo elige
 * cada rector»*, con tres salidas y una columna de `years`.
 *
 * ## Por qué esta fase existe y no es una línea
 *
 * La fase 0 hizo que una casilla sin calificar valga `NULL` y la fase 1 puso al lado
 * de la definitiva la **parcial** —lo evaluado, normalizado por su propio peso—.
 * Las dos son correctas **mientras el periodo esté abierto**: a mitad de periodo,
 * *«cuánto del periodo entero lleva ganado»* no es la pregunta de nadie.
 *
 * Al cerrar la pregunta cambia de dueño. **Dejar de contar lo no calificado durante
 * el periodo es correcto; dejar de contarlo al cerrar es aprobar a quien no
 * entregó.** Es literalmente el aviso de Moodle que cita la §4 del documento: Moodle
 * no puede distinguir *«no entregado»* de *«aún no toca»*, así que obliga al docente
 * a teclear el 0. Aquí lo que se hace es preguntárselo al colegio una vez.
 *
 * ## Las tres salidas, y qué hace cada una DE VERDAD
 *
 * |  | qué pasa con la casilla vacía | qué le pasa a la definitiva |
 * |---|---|---|
 * | `cero`     | **se escribe un 0 de verdad** en `notas.nota` | nada: ya valía lo mismo |
 * | `fuera`    | se queda vacía | pasa a ser **la parcial** — se divide por el peso de lo evaluado |
 * | `bloquear` | no se cierra | nada: no hay cierre |
 *
 * **La primera fila es la que engaña y hay que leerla entera.** La definitiva **no
 * normaliza** (regla 2 de `DefinitivasDeAsignatura`): es `Σ (peso × nota)` dando por
 * hecho que el divisor vale 1. Y `SUM(peso × NULL)` se salta la fila, así que hoy
 * una casilla vacía y una casilla a 0 **producen exactamente la misma definitiva**.
 *
 * O sea que si `cero` no escribiera nada, `cero` y `fuera` serían indistinguibles en
 * el boletín y D3 sería un adorno. Lo que hace `cero` es **cerrar el desacuerdo**:
 * después de escribir los ceros, la cobertura de ese periodo es 100 %, la parcial
 * coincide con lo que imprime el boletín y el semáforo deja de estar gris. *Ahí es
 * donde muere el hueco*: en un periodo cerrado, lo que ve la familia y lo que dice
 * el papel vuelven a ser el mismo número.
 *
 * Y `fuera` sí mueve la definitiva, a propósito y sólo para quien lo pida: es la
 * mitad de D3 que el rector elige cuando prefiere no castigar al alumno por lo que
 * su docente no calificó.
 *
 * ## DOS columnas: la que se elige y la que se congela
 *
 * - `years.cierre_sin_calificar` — **la elección del rector**, de fábrica `cero`.
 *   Se escribe por `PUT years/cierre-sin-calificar` y **sólo se lee en el momento de
 *   cerrar**.
 * - `periodos.cierre_sin_calificar` — **lo que se aplicó al cerrar este periodo**.
 *   `NULL` mientras no se haya cerrado por este camino. Es lo único que lee el
 *   cálculo.
 *
 * **Esa separación es la regla dura del encargo convertida en mecanismo:** *un
 * periodo ya cerrado no puede moverse por esto, y que no se mueva no puede depender
 * de que alguien se acuerde*. Si el cálculo leyera la elección del año, un rector que
 * cambiara de opinión en octubre movería las definitivas de los tres periodos que ya
 * tiene cerrados e impresos. Leyendo la congelada, **no hay ninguna secuencia de
 * pulsaciones que alcance un periodo cerrado**: la única escritura de esa columna es
 * el propio cierre, y el cierre sólo ocurre sobre un periodo abierto.
 *
 * Es la misma forma que usó la migración de la fase 0, que acotó su `UPDATE` con
 * `p.profes_pueden_editar_notas = 1` en vez de confiar en que nadie lo corriera dos
 * veces.
 *
 * ## `profes_pueden_editar_notas` es la marca de «cerrado», y no se inventa otra
 *
 * Por lo mismo que lo decidió `DefinitivasDeAsignatura::ponerAlDiaUnInforme` el 17
 * sep 2026: es el interruptor **que el propio colegio baja**, el que gobierna
 * `User::pueden_editar_notas()` y el que ya decide si un docente puede tocar una
 * nota. Un segundo criterio de «cerrado» serían dos puertas al mismo dato con reglas
 * distintas.
 *
 * Y de ahí sale la propiedad que hace que esta fase sea la última que puede escribir:
 * **con el periodo cerrado, `ponerAlDiaUnInforme` no reescribe ninguna definitiva**.
 * O sea que lo que el cierre deje escrito es lo que se imprime, y por eso el cierre
 * —y no un recálculo posterior— es donde hay que aplicar la decisión.
 */
final class CierreDeLoNoCalificado
{
    /** Lo de siempre: la casilla que nadie calificó **se escribe como 0**. */
    public const CERO = 'cero';

    /** La casilla vacía se queda vacía y **la definitiva se normaliza**: pasa a ser la parcial. */
    public const FUERA = 'fuera';

    /** No se deja cerrar mientras quede una casilla sin calificar. */
    public const BLOQUEAR = 'bloquear';

    /**
     * Lo que puede elegir un rector, **en el mismo orden que el `enum` de `years`**.
     *
     * Vive aquí y no en el controlador por lo mismo que `Year::MODELOS_DE_EVALUACION`:
     * es una propiedad de la columna, y dos sitios que dicen una cadena se separan sin
     * que falle nada — con el `sql_mode` de estos servidores, un valor fuera del `enum`
     * **no lanza: guarda la cadena vacía y devuelve 200**. Lo cruza contra
     * `SHOW COLUMNS` un test.
     *
     * @var list<string>
     */
    public const SALIDAS = [self::CERO, self::FUERA, self::BLOQUEAR];

    /**
     * Lo que puede quedar CONGELADO en un periodo, que son dos y no tres.
     *
     * `bloquear` no se aplica nunca: su resultado es que no hay cierre, así que un
     * periodo no puede quedarse con esa marca. El `enum` de `periodos` tiene dos
     * valores justamente para que ese estado no sea representable — si lo fuera, el día
     * que alguien lo encontrara escrito no habría forma de saber si es un fallo o una
     * decisión.
     *
     * @var list<string>
     */
    public const SALIDAS_QUE_SE_CONGELAN = [self::CERO, self::FUERA];

    /**
     * Qué eligió el rector de ese año. **Ante la duda, `fuera`** (desde el 24 sep 2026,
     * `2026_09_24_900000`: cerrar no escribe ceros que nadie pidió).
     *
     * Antes el defecto era `cero` porque era el comportamiento de entonces, así
     * que un año que no conteste —porque no existe, porque la columna todavía no está
     * desplegada en ese colegio— se comporta como se comportaba ayer. Es el mismo
     * criterio de `RepartoDeLaNota::modoDelAnio`, y por la misma razón.
     */
    public static function elegidoPorElAnio($yearId): string
    {
        if (! is_numeric($yearId) || (int) $yearId <= 0) {
            return self::FUERA;
        }

        $fila = DB::selectOne(
            'SELECT cierre_sin_calificar FROM years WHERE id = ? AND deleted_at IS NULL',
            [(int) $yearId]
        );

        $valor = $fila->cierre_sin_calificar ?? null;

        return in_array($valor, self::SALIDAS, true) ? (string) $valor : self::FUERA;
    }

    /**
     * Lo que elegiría el cierre de ESTE periodo, mirando su año.
     *
     * **Se llama una sola vez, al cerrar.** A partir de ahí manda lo congelado, que es
     * lo que impide que un cambio de opinión mueva un boletín impreso.
     */
    public static function elegidoParaElPeriodo($periodoId): string
    {
        if (! is_numeric($periodoId) || (int) $periodoId <= 0) {
            return self::FUERA;
        }

        $fila = DB::selectOne(
            'SELECT year_id FROM periodos WHERE id = ? AND deleted_at IS NULL',
            [(int) $periodoId]
        );

        return self::elegidoPorElAnio($fila->year_id ?? null);
    }

    /**
     * El estado de un periodo para esta decisión: si está abierto y con qué se cerró.
     *
     * Una sola consulta porque los dos datos se leen siempre juntos: *«¿está cerrado?»*
     * sin *«¿cómo?»* no decide nada, y al revés tampoco — un periodo **reabierto**
     * conserva la marca de su cierre anterior y, mientras esté abierto, esa marca no
     * gobierna nada.
     *
     * @return array{existe:bool, abierto:bool, congelado:?string, year_id:?int}
     */
    public static function estadoDelPeriodo($periodoId): array
    {
        $vacio = ['existe' => false, 'abierto' => false, 'congelado' => null, 'year_id' => null];

        if (! is_numeric($periodoId) || (int) $periodoId <= 0) {
            return $vacio;
        }

        $fila = DB::selectOne(
            'SELECT year_id, profes_pueden_editar_notas, cierre_sin_calificar
               FROM periodos WHERE id = ? AND deleted_at IS NULL',
            [(int) $periodoId]
        );

        if ($fila === null) {
            return $vacio;
        }

        $congelado = $fila->cierre_sin_calificar ?? null;

        return [
            'existe' => true,
            'abierto' => (int) $fila->profes_pueden_editar_notas === 1,
            'congelado' => in_array($congelado, self::SALIDAS_QUE_SE_CONGELAN, true)
                ? (string) $congelado
                : null,
            'year_id' => (int) $fila->year_id,
        ];
    }

    /**
     * Si la definitiva de este periodo se divide entre el peso de lo evaluado.
     *
     * **Desde el 22 sep 2026 la respuesta es «sí» salvo que se haya pedido lo
     * contrario.** Decisión de Joseth, con el caso delante: un alumno con cinco
     * casillas calificadas de siete salía con **34,8** en la planilla y con **47** en
     * el semáforo, y el 34,8 no es una nota —es la nota de otro, el que sí tuvo las
     * siete—. Lo que un profesor todavía no ha calificado **no es un cero del alumno**,
     * y ésa es la regla 1 de este fichero llevada hasta donde hacía falta llevarla:
     * hasta el número que se guarda.
     *
     * Antes esto era al revés —`false` salvo periodo cerrado con `fuera`— porque las
     * fases 0 a 3 prometían no mover la definitiva de nadie. Esa promesa se levanta
     * aquí a propósito y en una sola línea, que es la razón de que el interruptor
     * viva en un método y no repartido por los calculadores.
     *
     * **Lo único que sigue contando las vacías como cero es el cierre que lo pidió**:
     * `cerrado` + congelado en `cero`. Y se pregunta por el PERIODO y no por el año,
     * que es la regla dura del encargo hecha mecanismo: gobierna
     * `periodos.cierre_sin_calificar` —lo que se aplicó el día que se cerró—, no la
     * elección vigente del rector, porque con la elección un cambio de opinión en
     * octubre movería periodos ya impresos.
     *
     * **`fuera` y `NULL` dan los dos `true` ahora**, así que la salida `fuera` de D3
     * dejó de ser la que cambia algo: lo que cambia algo es elegir `cero`.
     */
    public static function normalizaLaDefinitiva($periodoId): bool
    {
        $estado = self::estadoDelPeriodo($periodoId);

        return ! ($estado['existe'] && ! $estado['abierto'] && $estado['congelado'] === self::CERO);
    }

    /**
     * Cuántas casillas vivas de este periodo no ha calificado nadie.
     *
     * Es el número que el diálogo de cierre enseña antes de preguntar, y el que lleva
     * dentro el 422 de `bloquear`. **Cuenta filas de `notas`, no subunidades**: lo que
     * está sin calificar es *«la exposición de Isabela»*, no *«la exposición»* — a
     * treinta compañeros se la pueden haber puesto.
     */
    public static function cuantasSinCalificar(int $periodoId): int
    {
        $fila = DB::selectOne(
            'SELECT COUNT(*) AS cuantas
               FROM notas n
               INNER JOIN subunidades s ON s.id = n.subunidad_id AND s.deleted_at IS NULL
               INNER JOIN unidades u ON u.id = s.unidad_id AND u.deleted_at IS NULL
              WHERE u.periodo_id = ? AND n.deleted_at IS NULL AND n.nota IS NULL',
            [$periodoId]
        );

        return (int) ($fila->cuantas ?? 0);
    }

    /**
     * El desglose que pinta el diálogo: qué asignatura deja cuántas casillas.
     *
     * **Por asignatura y con el docente al lado, no un total suelto**, y eso es la
     * mitad del valor de esta fase. Un «faltan 19.735 casillas» no se puede resolver;
     * *«Química de 10B, 45 casillas, 1 alumno afectado por indicador»* sí, y además
     * **dice de quién es el silencio** — que es lo que hoy no dice nadie: un docente
     * que no calificó produce treinta rojos y la culpa se lee como del alumno.
     *
     * `alumnos` cuenta **personas distintas**, no casillas: es lo que mide el daño si
     * la salida es `cero`.
     *
     * Ordenado por casillas descendente porque quien abre este diálogo está buscando a
     * quién llamar, y el que más debe va primero.
     *
     * `array_values` no es adorno: `DB::select` devuelve una lista, pero el análisis no
     * puede saberlo desde la firma y el nivel 7 lo dice —y tiene razón en el caso
     * general—. Envolverlo cuesta nada y **conserva la promesa del tipo**, que es lo que
     * el cliente lee para saber que esto es un array JSON y no un objeto con claves.
     *
     * @return list<object>
     */
    public static function porAsignatura(int $periodoId): array
    {
        // La foto del docente viaja con su nombre: toda fila que nombra a un docente lleva su
        // cara (regla de Joseth, 24 sep 2026). La oficial (`profesores.foto_id`) y la de su
        // usuario; el cliente elige con el mismo orden que para los alumnos.
        return array_values(DB::select(
            'SELECT a.id AS asignatura_id, a.grupo_id,
                    g.nombre AS nombre_grupo, m.materia,
                    a.profesor_id, p.nombres AS nombres_profesor, p.apellidos AS apellidos_profesor,
                    fp.nombre AS foto_nombre_profesor, iu.nombre AS imagen_nombre_profesor,
                    COUNT(*) AS casillas,
                    COUNT(DISTINCT n.alumno_id) AS alumnos,
                    COUNT(DISTINCT s.id) AS indicadores
               FROM notas n
               INNER JOIN subunidades s ON s.id = n.subunidad_id AND s.deleted_at IS NULL
               INNER JOIN unidades u ON u.id = s.unidad_id AND u.deleted_at IS NULL
               INNER JOIN asignaturas a ON a.id = u.asignatura_id AND a.deleted_at IS NULL
               INNER JOIN grupos g ON g.id = a.grupo_id AND g.deleted_at IS NULL
               LEFT JOIN materias m ON m.id = a.materia_id AND m.deleted_at IS NULL
               LEFT JOIN profesores p ON p.id = a.profesor_id
               LEFT JOIN images fp ON fp.id = p.foto_id
               LEFT JOIN users up ON up.id = p.user_id
               LEFT JOIN images iu ON iu.id = up.imagen_id
              WHERE u.periodo_id = ? AND n.deleted_at IS NULL AND n.nota IS NULL
              GROUP BY a.id, a.grupo_id, g.nombre, m.materia,
                       a.profesor_id, p.nombres, p.apellidos, fp.nombre, iu.nombre
              ORDER BY casillas DESC, a.id',
            [$periodoId]
        ));
    }

    /**
     * Escribe un 0 de verdad en las casillas vacías del periodo. Devuelve cuántas.
     *
     * **Acotado al periodo que se está cerrando y a nada más**, que es la forma que ya
     * usó la migración de la fase 0: lo que protege a los periodos cerrados no es que
     * nadie se acuerde de excluirlos, es que **el `UPDATE` no los alcanza**.
     *
     * ## `updated_by` sí, bitácora no — y las dos mitades se deciden
     *
     * `updated_by` y `updated_at` se escriben porque la fila tiene que decir quién la
     * dejó en 0 y cuándo; sin eso, el cero del cierre sería otra vez indistinguible de
     * un cero de fábrica, que es el fallo que la fase 0 vino a quitar. Es además lo que
     * hace que el proxy de la §1 del documento —`updated_by IS NULL AND created_at <=>
     * updated_at`— deje de seleccionarlas, que es correcto: ya no son casillas que
     * nadie miró.
     *
     * **Bitácora no**, y no por pereza: en la copia de desarrollo esto son **19.735
     * filas en un solo clic** para el periodo 2 de 2025. Una bitácora con veinte mil
     * renglones idénticos no la puede leer nadie y tapa los renglones que sí importan.
     * El acto es **uno** —un cierre— y queda registrado donde corresponde: en
     * `periodos.updated_by` y en la propia marca `cierre_sin_calificar`.
     *
     * ## Un solo `UPDATE`, y está medido
     *
     * La fase 0 cronometró este mismo camino —de `periodos` hacia abajo por los
     * índices, sin recorrer `notas`— en **0,49 s para 20.655 filas** contra MariaDB
     * 10.5. No hace falta trocearlo.
     */
    public static function pasarACero(int $periodoId, ?int $porUsuario): int
    {
        return DB::update(
            'UPDATE notas n
               INNER JOIN subunidades s ON s.id = n.subunidad_id AND s.deleted_at IS NULL
               INNER JOIN unidades u ON u.id = s.unidad_id AND u.deleted_at IS NULL
                SET n.nota = 0, n.updated_by = ?, n.updated_at = ?
              WHERE u.periodo_id = ? AND n.deleted_at IS NULL AND n.nota IS NULL',
            [$porUsuario, Reloj::ahora(), $periodoId]
        );
    }

    /**
     * Las asignaturas del periodo, para rehacer sus definitivas al cerrar con `fuera`.
     *
     * Sale de `unidades` y no de `asignaturas` porque `DefinitivasDeAsignatura` **no
     * escribe nada cuando la asignatura no tiene unidades vivas** en el periodo
     * (decisión del 28 ago 2026: borrar la última unidad no puede escribir treinta
     * ceros). Pedirle que recalcule las 1.219 asignaturas del colegio sería pedirle
     * 1.219 veces que salga por esa puerta.
     *
     * @return list<int>
     */
    public static function asignaturasConPlan(int $periodoId): array
    {
        $filas = DB::select(
            'SELECT DISTINCT u.asignatura_id
               FROM unidades u
               INNER JOIN asignaturas a ON a.id = u.asignatura_id AND a.deleted_at IS NULL
              WHERE u.periodo_id = ? AND u.deleted_at IS NULL
              ORDER BY u.asignatura_id',
            [$periodoId]
        );

        // `array_values` por lo mismo que en `porAsignatura`: `array_map` sobre lo que
        // devuelve `DB::select` conserva las claves y el análisis no puede afirmar que
        // sean 0..n.
        return array_values(array_map(static fn ($f) => (int) $f->asignatura_id, $filas));
    }
}
