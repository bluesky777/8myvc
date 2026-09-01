<?php

namespace App\Services;

use App\Models\Nota;
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
 * - **El interruptor de los puestos SÍ está ya**, desde la fase 6 del 31 ago 2026:
 *   `puestosCuentanIndependientes()` y sus dos ayudantes. Volvió con quien lo
 *   consume —los ocho sitios que copian `Nota::puestoAlumno`—, que es lo que le
 *   faltaba la primera vez: una columna que nadie lee moviendo tres respuestas
 *   vivas es coste sin contrapartida.
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
     * El interruptor de los puestos ya preguntado, por año.
     *
     * Mismo criterio que `$memoria`: un boletín de grupo pregunta una vez por alumno
     * y son treinta, y la respuesta es la misma para los treinta porque el año es
     * uno. Vive lo que vive la petición.
     *
     * @var array<int, bool>
     */
    private static array $interruptor = [];

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

    /**
     * **En qué `numero` de periodo va aparte cada alumno de un año.** Una consulta
     * para toda la respuesta.
     *
     * Lo piden las dos pantallas que enseñan **el año entero de una vez** —la rejilla
     * de `definitivas_periodos`, con sus cuatro columnas, y el acta de evaluación, que
     * es de todo el año—, y las dos emiten con esto
     * `alumno.bol_independiente_aparte_en: [2, 3]`. §7 de la cola de la noche del
     * 31 ago 2026, forma fijada por el front.
     *
     * ## Por qué hacía falta un método más y no valían los dos que ya había
     *
     * `aplica()` es por `(alumno, periodo)` y `delGrupo()` por `(grupo, periodo)`.
     * Contestar con ellos es **treinta alumnos por cuatro periodos** en la rejilla, y
     * en el acta **todos los grupos del año por cuatro** — sobre un informe cuyo propio
     * docblock presume de haber pasado de 151 consultas a una. Es el mismo salto de
     * grano que ya justificó `delGrupo()` frente a `aplica()`, un escalón más arriba.
     *
     * ## El año y no una lista de periodos, y esto es la decisión
     *
     * El valor que sale es una lista de **`numero`**, así que el método tiene que
     * llegar a `periodos` de todas formas: pedirle al llamante los ids le obligaría a
     * traer además los `numero` y a emparejarlos, que es la parte que se equivoca.
     * Y las dos pantallas preguntan por el año entero por construcción: la rejilla
     * enseña las cuatro columnas y el acta es del año. **Para «los periodos que este
     * informe promedia», que es la otra pregunta, ya está `aplicaEnAlguno()`** — y no
     * son la misma: mezclarlas fue lo que estuvo a punto de aplanar este campo a un
     * booleano.
     *
     * ## Sin `alumnoIds`, y medido antes de decidirlo
     *
     * La coordinación preguntó si debía llevar un `?array $alumnoIds` para que la
     * rejilla no se trajera el colegio entero. **No lo lleva**, y la razón es la
     * población: `bol_ind_periodos` **sólo tiene las filas que alguien escribió a
     * mano** —nace vacía, y la marca es la excepción, no el caso—, así que el techo de
     * un año es *(alumnos marcados × 4)*, no *(alumnos × 4)*. Hoy, en `simonbolivar`,
     * son **cero**.
     *
     * Y `EXPLAIN` dice por dónde entra: **por `bol_ind_periodos`**, resolviendo
     * `periodos` con `eq_ref` sobre `PRIMARY` — o sea **una fila de `periodos` por
     * marca**, y no un recorrido de alumnos ni de matrículas. **Población medida: dos
     * filas** (la base de desarrollo el 1 sep 2026), así que con ese tamaño el
     * optimizador se salta el índice y las recorre las dos; lo que el plan fija es el
     * **orden de entrada**, que es lo que decide si esto crece con las marcas o con el
     * colegio. `possible_keys` ya nombra `bol_ind_periodos_periodo_id_foreign` para
     * cuando haya filas que valga la pena indexar.
     *
     * Un parámetro que ningún llamante usa es una rama muerta sobre la que alguien
     * ramificará sin que se note nunca — la lección que ya pagó
     * `years.puestos_con_bol_independiente`: **entra con quien la consume**. El día que
     * un colegio marque a media escuela, el parámetro entra con la pantalla que lo
     * necesite.
     *
     * ## No memoiza, y eso también es la decisión
     *
     * Cada llamante la llama **una vez por petición**, así que una tercera propiedad
     * estática no ahorraría ninguna consulta y sí habría que acordarse de vaciarla en
     * `olvidar()`. Esta noche dos rojos que parecían de otra cosa salieron justo de una
     * memoria estática que sobrevivía entre tests. **Lo que no se cachea no se puede
     * olvidar mal.**
     *
     * ## `aplica = 1`, y no `<=>`
     *
     * Es la regla partida en dos de la §1.6 del reparto, formulada por el lote D: el
     * null-safe es para *«¿qué unidades le TOCAN a este alumno?»*. Esto es
     * *«¿está marcado?»*, que **afirma propiedad**, y por eso compara con `=`. La fila
     * que falta significa «va con el grupo» (decisión 7), así que quien no tenga
     * ninguna **no sale del mapa** y el llamante le pone su `[]`.
     *
     * @return array<int, list<int>> alumno_id => los `numero` en los que va aparte, en orden
     */
    public static function aparteEnPorAlumno(int $yearId): array
    {
        $filas = DB::select(
            'SELECT bip.alumno_id, p.numero
               FROM bol_ind_periodos bip
               INNER JOIN periodos p ON p.id = bip.periodo_id
                                    AND p.year_id = ?
                                    AND p.deleted_at IS NULL
              WHERE bip.aplica = 1
              ORDER BY bip.alumno_id, p.numero',
            [$yearId]
        );

        $mapa = [];

        foreach ($filas as $fila) {
            $mapa[(int) $fila->alumno_id][] = (int) $fila->numero;
        }

        return $mapa;
    }

    /**
     * ¿Este alumno va aparte en ALGUNO de estos periodos?
     *
     * La versión de `aplica()` para los informes que promedian **varios** periodos
     * —el de promoción y el de certificados promedian el año entero—. Un alumno que
     * pasó el segundo periodo con su propio boletín tiene una definitiva de ese
     * periodo que **no se calculó sobre el reparto del grupo**, así que su promedio
     * anual no es comparable con el de los demás aunque hoy vuelva a ir con el
     * grupo: la marca de un solo periodo basta para sacarlo del recuento.
     *
     * **Y por eso recibe los periodos y no el año.** `CertificadosPersonaController`
     * promedia «hasta el periodo N» cuando se lo piden, y preguntar por el año
     * entero ahí sacaría del recuento a alguien por una marca de un periodo que ese
     * informe **no está promediando** — que no es un fallo suyo, es un puesto
     * cambiado a treinta compañeros por un dato que no entra en la cuenta.
     *
     * @param  list<int>  $periodoIds  los periodos que el informe promedia
     */
    public static function aplicaEnAlguno(int $alumnoId, array $periodoIds): bool
    {
        foreach ($periodoIds as $periodoId) {
            if (self::aplica($alumnoId, $periodoId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * **¿Está activado el interruptor de los puestos de este año?** Y nada más.
     *
     * ## La regla que ya está pagada: contesta esto y NUNCA «¿se enseña el puesto?»
     *
     * El front esconde el puesto al `Acudiente` y al `Alumno` aunque el año lo tenga
     * activado (`boletines-periodo.spec.ts:222-243`). Si este método contestara «¿se
     * enseña?», o le filtraría el puesto a las familias por su cuenta —duplicando una
     * regla que ya vive en el front— o dejaría muerta la del front, **las dos en
     * silencio**. Son dos sitios decidiendo lo mismo con criterios distintos, que es
     * de donde salió el recalculador único de las definitivas.
     *
     * ## Memoria por año, y por la misma razón que la de `alcance()`
     *
     * Un boletín de grupo pregunta una vez por alumno y son treinta; la respuesta es
     * la misma para los treinta porque el año es uno solo. Vive lo que vive la
     * petición: no hay caché entre peticiones y por tanto no hay nada que invalidar
     * cuando un colegio cambia el interruptor.
     *
     * ## Un año que no existe cuenta como «lo de hoy»
     *
     * `selectOne` sin fila devuelve `null` y aquí eso es `true`, igual que el
     * `DEFAULT 1` de la columna. Que un `year_id` inexistente apague los puestos de
     * todo un informe sería el fallo silencioso caro: nadie mira un puesto y piensa
     * «esto es que el año no existe».
     */
    public static function puestosCuentanIndependientes(int $yearId): bool
    {
        if (! array_key_exists($yearId, self::$interruptor)) {
            $fila = DB::selectOne(
                'SELECT puestos_con_bol_independiente FROM years WHERE id = ?',
                [$yearId]
            );

            self::$interruptor[$yearId] = $fila === null
                || (int) $fila->puestos_con_bol_independiente === 1;
        }

        return self::$interruptor[$yearId];
    }

    /**
     * La lista contra la que se cuenta el puesto: los alumnos que entran en el
     * recuento.
     *
     * Con el interruptor en 1 —lo de hoy y lo de los quince colegios— devuelve la
     * lista **tal cual llegó**, sin copiar ni reordenar nada.
     *
     * Con el interruptor en 0 salen de ella los que van por boletín independiente, y
     * eso es lo que hace que **los treinta de detrás suban un puesto** si el que se
     * va iba primero (§7.2 del plan). Es el efecto que nadie espera y el único que
     * demuestra que el interruptor hace algo: quitar a alguien de una tabla de
     * puestos **le cambia el número a todos los demás**, en pantalla y en el papel
     * impreso.
     *
     * @param  array<int, object>  $alumnos  filas con `alumno_id`
     * @param  list<int>  $periodoIds  los periodos que el informe promedia
     * @return array<int, object>
     */
    public static function losQueCuentanParaElPuesto(array $alumnos, array $periodoIds, int $yearId): array
    {
        if (self::puestosCuentanIndependientes($yearId)) {
            return $alumnos;
        }

        return array_values(array_filter(
            $alumnos,
            fn (object $alumno): bool => ! self::aplicaEnAlguno((int) $alumno->alumno_id, $periodoIds)
        ));
    }

    /**
     * Pone `puesto` a cada alumno de la lista. **Es el único sitio donde el
     * interruptor decide un puesto**, y por eso lo llaman los ocho.
     *
     * ## Por qué está aquí y no copiado ocho veces
     *
     * `Nota::puestoAlumno($promedio, $alumnos)` es una **función pura** —cuenta
     * cuántos promedios hay por encima— y sigue siéndolo: este método no la toca, le
     * elige la lista. Lo que estaba copiado ocho veces era el `foreach` de una línea;
     * lo que se copiaría ahora es *«sácalo de la lista, y si es él, `null`»*, que ya
     * son tres decisiones. Ocho copias de tres decisiones es exactamente lo que le
     * pasó a la definitiva con sus seis escritores y cinco criterios.
     *
     * ## El `null` es la decisión 6 de Joseth y no un caso degenerado
     *
     * Al independiente que no cuenta se le manda `puesto: null`, que el front pinta
     * `—`. Calcularle un puesto contra una lista de la que se le acaba de sacar sería
     * inventarlo: saldría siempre 1 si su promedio es el mejor de una lista donde no
     * está. **Y `null`, no `0`**: `0` es un puesto en la escala del front antiguo
     * —su filtro `puestoAlumno` arranca en 0— y se pintaría como un número.
     *
     * ## Lo que este método NO hace
     *
     * No saca al independiente de `$alumnos`. Su fila sigue viajando, con sus notas y
     * su boletín; lo único que le falta es el puesto. Sacarlo de la respuesta sería
     * decidir desde aquí qué se enseña, que es justo lo que
     * `puestosCuentanIndependientes()` tiene prohibido.
     *
     * @param  array<int, object>  $alumnos  filas con `alumno_id` y `promedio`
     * @param  list<int>  $periodoIds  los periodos que el informe promedia
     */
    public static function ponerPuestos(array $alumnos, array $periodoIds, int $yearId): void
    {
        $cuentan = self::losQueCuentanParaElPuesto($alumnos, $periodoIds, $yearId);

        $entra = [];
        foreach ($cuentan as $alumno) {
            $entra[(int) $alumno->alumno_id] = true;
        }

        foreach ($alumnos as $alumno) {
            $alumno->puesto = isset($entra[(int) $alumno->alumno_id])
                ? Nota::puestoAlumno($alumno->promedio, $cuentan)
                : null;
        }
    }

    /**
     * Se llama entre tests: la memoria es por petición y una suite es un proceso.
     *
     * **Las dos memorias, y la del interruptor es la que muerde**: un test que lo
     * pone a 0 y otro que lo deja a 1 corren en el mismo proceso, así que olvidar
     * sólo `$memoria` dejaría al segundo leyendo la respuesta del primero — verde o
     * rojo según el orden de la suite, que es la peor forma de fallar que hay.
     */
    public static function olvidar(): void
    {
        self::$memoria = [];
        self::$interruptor = [];
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
