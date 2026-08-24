<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * El único sitio que decide de quién es una unidad, y si un independiente cuenta
 * para el puesto.
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
 * - **No decide si el puesto se enseña**, sólo si el interruptor está activado.
 *   Ver `puestosCuentanIndependientes()`.
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

    /**
     * ¿El colegio ha dicho que los independientes cuentan para el puesto?
     *
     * **Contesta «¿está activado el interruptor?» y nunca «¿se enseña el
     * puesto?», y la diferencia no es de estilo.** El front aplica una segunda
     * regla que este lado no ve: esconde el puesto al `Acudiente` y al `Alumno`
     * aunque el año lo tenga activado (`boletines-periodo.spec.ts:222-243`, «el
     * puesto no sale fuera del colegio»). Si este método contestara «se enseña»,
     * o le filtraría el puesto a las familias por su cuenta o dejaría muerta la
     * regla del front — las dos, en silencio. Lo avisó `myvc-front-98` el 24 ago.
     *
     * **Y hay una tercera columna de `years` que también habla de puestos y es
     * anterior a todo esto: `mostrar_puesto_boletin`.** No es la misma pregunta
     * —aquélla dice si el puesto se imprime, ésta si el independiente cuenta— y
     * no se funden. Pero se cruzan: medido el 24 ago sobre esta base, **1 de los
     * 8 años vivos tiene `mostrar_puesto_boletin = 0`**, y en ese año este
     * interruptor no se ve por ninguna parte.
     */
    public static function puestosCuentanIndependientes(int $yearId): bool
    {
        $fila = DB::select(
            'SELECT puestos_con_bol_independiente FROM years WHERE id = ?',
            [$yearId]
        );

        // Sin año no se inventa un «no»: lo de hoy —y el valor por defecto de la
        // columna— es que cuentan, y un año que no se encuentra no es una orden
        // del colegio de sacar a nadie de la lista.
        return $fila === [] || (bool) $fila[0]->puestos_con_bol_independiente;
    }

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
