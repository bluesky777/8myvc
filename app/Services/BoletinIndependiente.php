<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * El único sitio que decide de quién es una unidad.
 *
 * Es la **fase 1** de [docs/migracion/19-boletin-independiente.md](../../docs/migracion/19-boletin-independiente.md),
 * y la medida de la noche está en
 * [docs/migracion/noche-2026-08-24/bi-1.md](../../docs/migracion/noche-2026-08-24/bi-1.md).
 *
 * La misma forma que `DefinitivasDeAsignatura`: **un solo sitio decide**, y quien
 * pregunta no vuelve a escribir la regla. Sale de lo que le pasó a las
 * definitivas con sus seis escritores y cinco criterios; aquí el equivalente
 * sería que la planilla contase una cosa y el papel impreso otra.
 *
 * ## El diseño, en una frase
 *
 * > Una unidad puede tener dueño. `unidades.alumno_id` NULL es del grupo —todas
 * > las de hoy—; con un id es de ese alumno y de nadie más.
 *
 * Y **la comparación es `<=>`, no `=`**. El igual null-safe de MySQL empareja
 * NULL con NULL, así que una sola condición resuelve las dos ramas: el alumno
 * normal contra las unidades del grupo, el independiente contra las suyas. Con
 * `=` a secas la rama del alumno normal devuelve cero filas y **todas las
 * definitivas del colegio se van a 0** sin un solo error en el log. Es el fallo
 * más caro que este fichero puede introducir y no da ninguna señal.
 *
 * ## Con nadie marcado, esto no cambia nada, y es comprobable
 *
 * `alcance()` devuelve `null` para todo el mundo mientras
 * `matriculas.boletin_independiente` sea 0 en todas las filas —que es como nace—,
 * y `u.alumno_id <=> NULL` selecciona **exactamente** las filas de hoy. Ése es el
 * criterio de aceptación de la §4 del plan: los 1.344 tests pasan sin regenerar
 * un solo snapshot.
 *
 * ## Lo que esta clase NO hace, y no es un olvido
 *
 * - **`copiar()` no está.** Es la §6.2 y necesita las tres rutas nuevas, que no
 *   entran en esta fase: una ruta nueva es una decisión, y además dos del plan
 *   siguen abiertas (quién puede marcar a un alumno, y qué puesto lleva su
 *   boletín).
 * - **No escribe nada.** Ni la marca, ni el interruptor por periodo. Eso es la
 *   fase 2 y la 4.
 * - **El interruptor de los puestos tampoco está**: se fue a la fase 2 con su
 *   columna, porque `years.puestos_con_bol_independiente` movía tres respuestas
 *   vivas y no lo consumía nada. El comentario de más abajo guarda la regla que
 *   tiene que llevar cuando vuelva.
 */
class BoletinIndependiente
{
    /**
     * El `LEFT JOIN` que hay que añadir a las consultas que resuelven un grupo
     * entero de una vez y no pueden preguntar alumno por alumno.
     *
     * `m` es `matriculas` y `u` es `unidades`; si la consulta usa otros alias,
     * no vale copiar esto a mano — hace falta un método más aquí.
     */
    public const JOIN_ESTADO =
        'LEFT JOIN bol_ind_periodos bip
                ON bip.alumno_id = m.alumno_id AND bip.periodo_id = u.periodo_id';

    /**
     * El dueño que le toca a cada alumno, en SQL. Se compara con `u.alumno_id <=> ...`
     *
     * La fila que falta en `bol_ind_periodos` significa «lo que diga la
     * matrícula», y de ahí el `COALESCE(bip.aplica, 1)`: la tabla nace vacía y
     * tiene que comportarse como si dijera que sí.
     */
    public const ALCANCE =
        'IF(m.boletin_independiente = 1 AND COALESCE(bip.aplica, 1) = 1, m.alumno_id, NULL)';

    /**
     * El alcance como **subconsulta escalar correlacionada**, para las consultas que
     * NO tienen `matriculas` en el ámbito.
     *
     * `$alumno` es la expresión que da el id del alumno (`n.alumno_id`, `a.id`…) y
     * `$unidad` el alias de `unidades`. Se usa así:
     *
     *     ... and u.alumno_id <=> '.BoletinIndependiente::alcanceCorrelacionado('a.id', 'u')
     *
     * ## Por qué existe, y no es una comodidad
     *
     * La forma normal —`JOIN_ESTADO` + `ALCANCE`— necesita `m` en el ámbito. Cuando no
     * está, la salida obvia es **traer `matriculas` con un `JOIN`, y eso puede DUPLICAR
     * FILAS**: `matriculas` tiene `PRIMARY KEY (id)` y sendas claves por `alumno_id` y
     * `grupo_id`, pero **ninguna única sobre el par** — nada impide dos matrículas vivas
     * del mismo alumno en el mismo grupo. Medido el 24 ago 2026 sobre la base de
     * desarrollo: **0 pares de 3.542**, o sea que hoy no pasa **aquí** — y son dieciséis
     * colegios, con quince bases que nadie ha mirado.
     *
     * En una consulta con `GROUP BY` eso dobla un `SUM()`; en una que devuelve filas,
     * las repite. **Una subconsulta escalar no puede hacer ninguna de las dos**: da un
     * valor o `NULL`.
     *
     * *No se elige esta forma porque hoy los datos no dupliquen —eso sería una medición
     * usada como guardián, y aquí eso ya costó una noche—: se elige porque el esquema no
     * lo impide.*
     *
     * ## Y correlaciona el PERIODO, que es la otra mitad
     *
     * `bip.periodo_id = '.$unidad.'.periodo_id` y no un periodo bindeado: `bol_ind_periodos`
     * es **por periodo**, y hay consultas que abarcan varios (`p.numero <= N` en
     * `NotasPerdidasController`). Un alumno puede ir por independiente en el 3 y no en el
     * 2; con un valor bindeado una sola vez, **el resto de periodos se resolvería con el
     * alcance del equivocado y no habría ningún error que lo señalara**.
     *
     * El `LIMIT 1` es por la misma falta de clave única de arriba: sin él, dos matrículas
     * del mismo par convertirían la subconsulta escalar en un error de ejecución en vez
     * de en un valor. **Con `LIMIT 1` degrada a «una de las dos» en vez de reventar**, y
     * eso es lo correcto aquí: este lote no puede cambiar la respuesta, y decidir cuál de
     * dos matrículas manda es de quien lleve las matrículas duplicadas.
     */
    public static function alcanceCorrelacionado(string $alumno, string $unidad = 'u'): string
    {
        // **Es `consultar()` palabra por palabra, correlacionado por el periodo de la
        // unidad.** Y eso no es copiar: es que la elección de la matrícula es UNA
        // regla y este fichero existe para que no haya dos.
        //
        // La primera versión de este método se saltó las dos mitades de esa regla
        // —el año del periodo y el desempate— y hacía `WHERE mbi.alumno_id = ?
        // LIMIT 1`. Con un alumno que tiene matrícula en más de un año, el `LIMIT 1`
        // elegía una cualquiera: podía leer el interruptor de 2024 para un periodo
        // de 2026. **Lo cazó `AlcanceCorrelacionadoPorPeriodoTest`, no la revisión.**
        //
        //   - **el año**: se entra por `periodos` y se baja a `grupos` del MISMO
        //     `year_id`, porque una nota de 2024 pregunta por el estado de ese año
        //     aunque el token vaya por 2026;
        //   - **el desempate**: la más reciente de las vivas, `created_at DESC, id
        //     DESC`. Un `ORDER BY` con empates elige al azar.
        //
        // Si `consultar()` cambia, esto cambia con él. Están a veinte líneas para
        // que se vean juntas.
        return '(SELECT IF(mbi.boletin_independiente = 1 AND COALESCE(bipc.aplica, 1) = 1, mbi.alumno_id, NULL)
                   FROM periodos pbi
                   INNER JOIN grupos gbi     ON gbi.year_id = pbi.year_id AND gbi.deleted_at IS NULL
                   INNER JOIN matriculas mbi ON mbi.grupo_id = gbi.id AND mbi.alumno_id = '.$alumno.'
                                            AND mbi.deleted_at IS NULL
                   LEFT JOIN bol_ind_periodos bipc
                          ON bipc.alumno_id = mbi.alumno_id AND bipc.periodo_id = pbi.id
                  WHERE pbi.id = '.$unidad.'.periodo_id AND pbi.deleted_at IS NULL
                  ORDER BY mbi.created_at DESC, mbi.id DESC
                  LIMIT 1)';
    }

    /**
     * Lo ya preguntado en esta petición, por (alumno, periodo).
     *
     * No es una optimización preventiva de las que prohíbe el 02: un boletín
     * llama a `Unidad::deAsignaturaCalculada` **una vez por asignatura** —once en
     * un bachillerato— con el mismo alumno y el mismo periodo. Sin esto son once
     * consultas idénticas por alumno impreso, y un boletín de grupo son treinta
     * alumnos. Vive lo que vive la petición: no hay caché entre peticiones y por
     * tanto no hay nada que invalidar cuando alguien marca a un alumno.
     *
     * @var array<string, ?int>
     */
    private static array $memoria = [];

    /**
     * El valor que va a `unidades.alumno_id` para las consultas de UN alumno:
     * `null` (las del grupo) o su id (las suyas).
     *
     * Es el único parámetro que hace falta, y por eso este método es el que
     * usan las consultas: devuelve directamente lo que se compara, en vez de un
     * booleano que cada llamante tendría que traducir a `null` o a un id — que
     * es donde uno de ellos acabaría escribiendo `= $alumno_id` y dejando a los
     * normales sin filas.
     */
    public static function alcance(int $alumnoId, int $periodoId): ?int
    {
        $clave = $alumnoId.':'.$periodoId;

        if (! array_key_exists($clave, self::$memoria)) {
            self::$memoria[$clave] = self::consultar($alumnoId, $periodoId);
        }

        return self::$memoria[$clave];
    }

    /** ¿Este alumno, en este periodo, va por boletín independiente? */
    public static function aplica(int $alumnoId, int $periodoId): bool
    {
        return self::alcance($alumnoId, $periodoId) !== null;
    }

    /**
     * Los alumnos del grupo que van por independiente en ese periodo, y los que no.
     *
     * Devuelve `['independientes' => int[], 'normales' => int[]]`. Lo usará la
     * fase 3 —`putDetailed` deja de devolver a los independientes y tiene que
     * decir a cuántos no está enseñando— y la 6, para los puestos.
     *
     * @return array{independientes: list<int>, normales: list<int>}
     */
    public static function delGrupo(int $grupoId, int $periodoId): array
    {
        $filas = DB::select(
            'SELECT m.alumno_id,
                    IF(m.boletin_independiente = 1 AND COALESCE(bip.aplica, 1) = 1, 1, 0) AS independiente
             FROM matriculas m
             LEFT JOIN bol_ind_periodos bip
                    ON bip.alumno_id = m.alumno_id AND bip.periodo_id = ?
             WHERE m.grupo_id = ?
               AND m.deleted_at IS NULL
               AND m.estado IN ("MATR", "ASIS")
             ORDER BY m.alumno_id',
            [$periodoId, $grupoId]
        );

        $salida = ['independientes' => [], 'normales' => []];

        foreach ($filas as $fila) {
            $salida[$fila->independiente ? 'independientes' : 'normales'][] = (int) $fila->alumno_id;
        }

        return $salida;
    }

    /*
     * `puestosCuentanIndependientes()` estaba aquí y se ha ido a la FASE 2, con
     * su columna `years.puestos_con_bol_independiente`. No está sin hacer: está
     * movida, y la diferencia importa para quien lea el plan.
     *
     * Por qué se movió: la columna movía las tres instantáneas de
     * `MuestreoDeLecturasTest` —`YearsController:27` y `:43` leen con `SELECT *`—
     * y **no la consume nada todavía**: los ocho sitios que copian
     * `Nota::puestoAlumno` son de la fase 6, y las cuatro rutas de puestos no
     * calculan puesto (devuelven `promedio`; el front pinta `$index + 1`). O sea
     * que aquí era una columna que nadie lee moviendo tres respuestas vivas.
     *
     * **Y la regla que hay que conservar cuando vuelva, que ya está pagada:**
     * ese método contesta **«¿está activado el interruptor?»** y **nunca «¿se
     * enseña el puesto?»**. El front esconde el puesto al `Acudiente` y al
     * `Alumno` aunque el año lo tenga activado
     * (`boletines-periodo.spec.ts:222-243`). Si contestara lo segundo, o le
     * filtraría el puesto a las familias por su cuenta o dejaría muerta la regla
     * del front — las dos en silencio, y las dos son dos sitios decidiendo lo
     * mismo con criterios distintos, que es de lo que salió el recalculador
     * único.
     *
     * Ver la §5.ter y la §6 de docs/migracion/noche-2026-08-24/bi-1.md.
     */

    /** Se llama entre tests: la memoria es por petición y una suite es un proceso. */
    public static function olvidar(): void
    {
        self::$memoria = [];
    }

    /**
     * La consulta de verdad, y la regla de **cuál es la matrícula del año**.
     *
     * El año sale del periodo, no del token: quien pide el boletín del periodo 2
     * de 2024 pregunta por el estado de ese año, y el token puede ir por 2026.
     *
     * **La elección de la matrícula está escrita aquí a propósito, y es media
     * §9.5 del plan.** Hoy la ficha y el guardado eligen «la matrícula del año»
     * con dos consultas distintas —una filtra `deleted_at` y ordena, la otra ni
     * filtra ni ordena— y se quedan las dos con `[0]`. Un alumno con **dos
     * matrículas del mismo año** —cambió de grupo a mitad de curso, o una quedó
     * borrada— puede leerse de una y escribirse en otra. Con `repitente` eso ya
     * pasa y nadie lo ha visto porque nadie mira esos campos al día siguiente;
     * con esta marca se vería **en la planilla de otro docente**, sin ninguna
     * señal que lo relacione con un interruptor que alguien tocó en otra
     * pantalla.
     *
     * Aquí se toma **la más reciente de las vivas**, con desempate por `id` para
     * que sea determinista —un `ORDER BY` sobre una columna con empates elige al
     * azar, que es la familia de la §28—. **Esto todavía no arregla la §9.5**:
     * unificar la lectura y la escritura es la fase 2 y toca
     * `GuardarAlumno::valor` y `AlumnosController::putShow`, que esta noche los
     * lleva otra sesión. Lo que hace es no añadir **una tercera** regla distinta.
     */
    private static function consultar(int $alumnoId, int $periodoId): ?int
    {
        $filas = DB::select(
            'SELECT m.boletin_independiente, COALESCE(bip.aplica, 1) AS aplica
             FROM periodos p
             INNER JOIN grupos g     ON g.year_id = p.year_id AND g.deleted_at IS NULL
             INNER JOIN matriculas m ON m.grupo_id = g.id AND m.alumno_id = ?
                                    AND m.deleted_at IS NULL
             LEFT JOIN bol_ind_periodos bip
                    ON bip.alumno_id = m.alumno_id AND bip.periodo_id = p.id
             WHERE p.id = ? AND p.deleted_at IS NULL
             ORDER BY m.created_at DESC, m.id DESC
             LIMIT 1',
            [$alumnoId, $periodoId]
        );

        if ($filas === []) {
            return null;
        }

        $independiente = (int) $filas[0]->boletin_independiente === 1
            && (int) $filas[0]->aplica === 1;

        return $independiente ? $alumnoId : null;
    }
}
