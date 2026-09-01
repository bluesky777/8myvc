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
 * ## La marca es POR PERIODO, y eso es la decisión 7 del 31 ago 2026
 *
 * Una fila en `bol_ind_periodos` con `aplica = 1` dice «este alumno, en este
 * periodo, va aparte». **La fila que falta dice «va con el grupo»**, y ése es el
 * `COALESCE(..., 0)` de más abajo. Estaba escrito al revés —la fila ausente
 * significaba «lo que diga la matrícula»— y con ese default **marcar a un alumno
 * en octubre le repintaba el boletín del primer periodo**: justo lo que se pidió
 * que no pasara. *«Tuvo un periodo normal y en el segundo un accidente … tienen
 * que convivir.»*
 *
 * **`matriculas.boletin_independiente` ya no existe**, y no es que haya dejado de
 * consultarse: se retira con su propia migración (§2.1 del plan). Dos columnas
 * que pueden discrepar en silencio acaban discrepando, y discrepar aquí es un
 * alumno que vuelve al boletín del grupo sin que nadie lo vea. Lo que se lleva por
 * delante está contado en `alcanceCorrelacionado()` y en `consultar()`, y es más
 * de lo que costaba: con la marca colgada de `(alumno_id, periodo_id)` **el año se
 * hereda del periodo en vez de derivarse de una matrícula que hay que elegir**.
 *
 * ## Con nadie marcado, esto no cambia nada, y ahora por la razón fuerte
 *
 * `alcance()` devuelve `null` para todo el mundo mientras `bol_ind_periodos` esté
 * vacía —que es como nace—, y `u.alumno_id <=> NULL` selecciona **exactamente**
 * las filas de hoy. Ése es el criterio de aceptación de la §4 del plan. Antes se
 * cumplía porque una columna estaba a 0 en todas las filas; ahora se cumple
 * porque **no hay ninguna fila**, que es una afirmación más difícil de romper sin
 * querer.
 *
 * ## Lo que esta clase NO hace, y no es un olvido
 *
 * - **`copiar()` no está.** Es la §6.2 y necesita dos de las tres rutas nuevas,
 *   que no entran en esta fase: una ruta nueva es una decisión.
 * - **No escribe nada.** Ni la marca por periodo: eso es
 *   `PUT boletin-independiente/periodo`, que con la decisión 7 es **el único
 *   escritor que hay** y por eso subió de la fase 4 a la fase 2.
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
     * **La fila que falta significa «va con el grupo», y de ahí el
     * `COALESCE(bip.aplica, 0)`.** Es la decisión 7 del 31 ago 2026 y es un
     * carácter que cambia el significado entero: con el `1` que había aquí, la
     * fila ausente significaba «lo que diga la matrícula», así que **marcar a un
     * alumno en octubre le repintaba el boletín del primer periodo** — que es
     * exactamente lo que se pidió que no pasara. *«Tuvo un periodo normal y en el
     * segundo un accidente … tienen que convivir.»*
     *
     * **Y ya no pregunta por `matriculas.boletin_independiente`, porque esa
     * columna se retira** (§2.1 del plan). Con la marca por periodo, la matrícula
     * no decide nada: quien decide es esta tabla, y su clave única
     * `(alumno_id, periodo_id)` hace que el `LEFT JOIN` de `JOIN_ESTADO` **no
     * pueda duplicar una fila**, que era el otro riesgo que había que rodear.
     */
    public const ALCANCE =
        'IF(COALESCE(bip.aplica, 0) = 1, m.alumno_id, NULL)';

    /**
     * El alcance como **subconsulta escalar correlacionada**, para las consultas que
     * NO tienen `matriculas` en el ámbito.
     *
     * `$alumno` es la expresión que da el id del alumno (`n.alumno_id`, `a.id`…) y
     * `$unidad` el alias de `unidades`. Se usa así:
     *
     *     ... and u.alumno_id <=> '.BoletinIndependiente::alcanceCorrelacionado('a.id', 'u')
     *
     * ## Esto era treinta líneas y ahora son cuatro, y no por gusto
     *
     * Mientras la marca vivió en `matriculas.boletin_independiente`, esta subconsulta
     * tenía que **derivar la matrícula del año** para poder leerla: entraba por
     * `periodos`, bajaba a `grupos` del mismo `year_id`, unía `matriculas` y
     * desempataba con `ORDER BY mbi.created_at DESC, mbi.id DESC LIMIT 1` porque
     * `matriculas` **no tiene clave única sobre (alumno, grupo)** y nada impide dos
     * filas vivas del mismo alumno. Ese `LIMIT 1` era una degradación consciente:
     * *«una de las dos»* en vez de reventar.
     *
     * **Con la marca por periodo nada de eso hace falta.** `bol_ind_periodos` cuelga
     * de `(alumno_id, periodo_id)` con clave única, y un periodo pertenece a un año y
     * sólo a uno — **el año queda implícito y exacto**, en vez de derivado y
     * desempatado. Se van con ello:
     *
     *   - las dos matrículas del mismo alumno, que dejan de poder decidir nada;
     *   - el `LIMIT 1`, porque la clave única garantiza como mucho una fila;
     *   - y toda la §9.5 del plan **para esta marca**: ya no hay «cuál es la matrícula
     *     del año» que acertar, así que leer y escribir no pueden elegir distinto.
     *
     * Lo que se conserva es la mitad que sí importaba y costó un test:
     * **correlacionar por el periodo de la unidad y no por un periodo bindeado**.
     * `bol_ind_periodos` es por periodo y hay consultas que abarcan varios
     * (`p.numero <= N` en `NotasPerdidasController`); un alumno puede ir por
     * independiente en el 3 y no en el 2, y con un valor bindeado una sola vez **el
     * resto de periodos se resolvería con el alcance del equivocado y no habría
     * ningún error que lo señalara**. Lo cazó `AlcanceCorrelacionadoPorPeriodoTest`,
     * no la revisión.
     */
    public static function alcanceCorrelacionado(string $alumno, string $unidad = 'u'): string
    {
        // Devuelve el id del alumno si ese periodo suyo va aparte, y NULL si no —que
        // es justo lo que se compara con `u.alumno_id <=>`—. No hay `COALESCE` porque
        // una subconsulta escalar sin filas **ya vale NULL**: la fila ausente da «va
        // con el grupo» por construcción, que es la decisión 7 escrita en la forma
        // más corta que tiene.
        return '(SELECT bipc.alumno_id
                   FROM bol_ind_periodos bipc
                  WHERE bipc.alumno_id = '.$alumno.'
                    AND bipc.periodo_id = '.$unidad.'.periodo_id
                    AND bipc.aplica = 1)';
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
                    IF(COALESCE(bip.aplica, 0) = 1, 1, 0) AS independiente
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
     * La consulta de verdad. Una tabla, dos columnas de clave única, y nada más.
     *
     * **Aquí había veinte líneas que elegían «la matrícula del año»** —entrar por
     * `periodos`, bajar a `grupos` del mismo `year_id`, unir `matriculas`, filtrar
     * los borrados y desempatar por `created_at DESC, id DESC`— y estaban por una
     * sola razón: la marca vivía en una columna de `matriculas` y había que dar con
     * *qué* fila leer. Retirada la columna (§2.1 del plan), **la pregunta desaparece
     * en vez de contestarse mejor**.
     *
     * Lo que eso cierra, y es más de lo que parece: media §9.5 del plan. La ficha
     * leía la matrícula de una manera y `GuardarAlumno::valor` la escribía de otra
     * —una filtra `deleted_at` y ordena, la otra ni filtra ni ordena, y las dos se
     * quedan con `[0]`—, así que un alumno con **dos matrículas del mismo año** podía
     * leerse de una y escribirse en otra. Con la marca colgada de
     * `(alumno_id, periodo_id)` **no hay dos filas entre las que equivocarse**. La
     * §9.5 sigue viva para `repitente`, `promovido` y `nro_folio`; para esta marca,
     * no.
     *
     * **El año ya no se deriva: se hereda.** Un periodo pertenece a un año y sólo a
     * uno, así que preguntar por `(alumno, periodo)` es preguntar por el año exacto
     * de ese periodo — sin que el token, que puede ir por 2026, tenga nada que decir
     * sobre un boletín de 2024.
     *
     * **Lo que esta consulta NO comprueba, a propósito:** que el alumno esté
     * matriculado en el año de ese periodo. Una fila en `bol_ind_periodos` sólo nace
     * si alguien la escribe, y esa comprobación es de quien escribe —`PUT
     * boletin-independiente/periodo`, §6.3—, no de quien lee. Ponerla aquí la
     * cobraría en cada boletín impreso para defenderse de un estado que el escritor
     * no debe dejar crear.
     */
    private static function consultar(int $alumnoId, int $periodoId): ?int
    {
        $fila = DB::selectOne(
            'SELECT aplica FROM bol_ind_periodos WHERE alumno_id = ? AND periodo_id = ?',
            [$alumnoId, $periodoId]
        );

        // Sin fila, va con el grupo: es la decisión 7 y es el caso de todo el mundo
        // hoy, porque la tabla nace vacía. `selectOne` sin `LIMIT 1` es seguro aquí y
        // no en la versión anterior: `bol_ind_periodos_unico (alumno_id, periodo_id)`
        // garantiza como mucho una fila, que es la razón por la que esa clave nació
        // con la tabla en vez de añadirse después como en `notas_finales`.
        return $fila !== null && (int) $fila->aplica === 1 ? $alumnoId : null;
    }
}
