<?php

namespace Tests\Contrato;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * Marcar la oficial y **las siete columnas de día que salen de ahí** (§7 del
 * [23](../../docs/migracion/23-horarios.md)).
 *
 * La §7 lo declara obligatorio y dice por qué: las siete columnas de `asignaturas`
 * pasan a ser **dato derivado**, y un dato derivado sin quien lo vigile se
 * desincroniza sin que nada se ponga rojo. Lo que este caso ata es
 * **columnas ↔ lecciones de la versión oficial**, en las dos direcciones.
 *
 * ## Los tres fallos que esto existe para cazar, y ninguno da error
 *
 * **1. El alcance leído literal.** *«Recalcula las columnas de cada asignación desde
 * las lecciones de esa versión»* escribiría **sólo las que aparecen**: si la versión
 * 2 quita las horas que traía la versión 1, esa asignatura **se queda con el
 * `martes = 1` de la anterior** y el docente sigue viendo una clase que ya no
 * existe, salida de una columna que nadie volvió a tocar. No hay error, no hay 422:
 * hay un horario viejo mezclado con uno nuevo.
 *
 * **2. `asignaturas` no tiene `year_id`.** «Las asignaciones de este año» es un
 * **JOIN** contra `grupos.year_id`. Un `WHERE a.year_id = ?` ni siquiera compila,
 * pero un alcance que se olvide del JOIN —o que lo ponga contra el año equivocado—
 * **pone a cero las columnas del año abierto** mientras se publica uno cerrado. Con
 * la decisión 13 —publicar vale en cualquier año— eso no es teórico, y por eso el
 * caso de abajo publica el año 2025 **teniendo 2024 con columnas puestas**.
 *
 * **3. El convenio de `dia`.** 0 = domingo … 6 = sábado (§5.2.5). Si se corre uno,
 * el horario entero se corre un día: el lunes se pinta el domingo y el viernes cae
 * en jueves. **El veredicto de la §6 sale en verde igual**, porque grupo, docente y
 * Σ ≤ IH se cumplen exactamente lo mismo con el horario corrido. Por eso aquí va la
 * tabla de los siete días entera y no un caso de muestra: es el único sitio donde ese
 * off-by-one se puede ver.
 *
 * ## Y el sábado, que se estrena con este lote
 *
 * `getToMe` pedía mañana como `$dia + 1`; el sábado eso da **7**,
 * `asignaturas_dia()` no tiene caso 7 y «mañana» devolvía **todas** las asignaturas
 * del docente (§2.1). Hoy no se ve porque con las columnas vacías todo sale vacío
 * igual — **se ve el día que se rellenen, que es este**. Va aquí y no en su propio
 * lote porque arreglarlo después convertiría el estreno del horario en un fallo
 * nuevo.
 */
class HorarioOficialTest extends CasoDeContrato
{
    /** El año actual del seed, y el que se publica. */
    private const YEAR_ACTUAL = 8;

    /** Otro año CON asignaturas, que es lo que hace comprobable el alcance. */
    private const YEAR_VECINO = 7;

    /** El convenio de la §5.2.5, repetido aquí a propósito: ver `test_el_dia_no_se_traduce`. */
    public static function losSieteDias(): array
    {
        return [
            'domingo' => [0, 'domingo'],
            'lunes' => [1, 'lunes'],
            'martes' => [2, 'martes'],
            'miercoles' => [3, 'miercoles'],
            'jueves' => [4, 'jueves'],
            'viernes' => [5, 'viernes'],
            'sabado' => [6, 'sabado'],
        ];
    }

    /**
     * Las asignaturas vivas de un año, **por JOIN**, que es la única forma que hay.
     *
     * @return list<int>
     */
    private function asignaturasDe(int $yearId): array
    {
        return array_values(array_map(static fn ($f): int => (int) $f->id, DB::select(
            'SELECT a.id FROM asignaturas a
             INNER JOIN grupos g ON g.id = a.grupo_id AND g.year_id = ? AND g.deleted_at IS NULL
             WHERE a.deleted_at IS NULL ORDER BY a.id',
            [$yearId]
        )));
    }

    /**
     * Una versión con sus lecciones, metida a mano.
     *
     * A propósito **no pasa por `POST horario/versiones`**: lo que se mide aquí es la
     * derivación, y montarla sobre la subida haría que un 422 de la revalidación
     * —que es de otro lote— se leyera como un fallo de las columnas.
     *
     * El tercer elemento es el `pieza_id`, y existe **sólo** para poder fabricar la
     * misa: varias asignaciones que son **la misma pieza**. Sin él, cada fila sería una
     * pieza distinta y ese camino no se ejercería nunca (§5.1).
     *
     * @param  list<array{0: int, 1: int, 2?: string}>  $lecciones  (asignatura_id, dia[, pieza_id])
     */
    private function crearVersion(int $yearId, array $lecciones, string $nombre = 'v'): int
    {
        DB::insert(
            'INSERT INTO horario_versiones (year_id, nombre, subida_por, proyecto, comprobaciones, created_at, updated_at)
             VALUES (?, ?, null, ?, null, now(), now())',
            [$yearId, $nombre, '{}']
        );

        $versionId = (int) DB::getPdo()->lastInsertId();

        foreach ($lecciones as $i => $leccion) {
            [$asignaturaId, $dia] = $leccion;

            DB::insert(
                'INSERT INTO horario_lecciones (version_id, pieza_id, asignatura_id, dia, franja, duracion)
                 VALUES (?, ?, ?, ?, ?, 1)',
                [$versionId, $leccion[2] ?? ('p'.$i.'-'.$dia), $asignaturaId, $dia, 1]
            );
        }

        return $versionId;
    }

    /** Publica como superusuario, que es quien puede hoy (decisión 10). */
    private function publicar(int $versionId)
    {
        $usuario = $this->usuarioDeTipo('Usuario');

        $this->assertSame(1, (int) $usuario->is_superuser,
            'El sujeto de este test tiene que ser superusuario y no lo es: sin él, el 403 '.
            'del guard se leería como un fallo de la derivación.');

        return $this->putJson("/api/horario/versiones/{$versionId}/oficial", [], [
            'Authorization' => 'Bearer '.$this->tokenDe($usuario->username),
        ]);
    }

    /**
     * Lo que las siete columnas tienen que valer, **nombrando los días que van a 1**.
     *
     * Por nombre y no por posición: una lista de siete enteros es justo donde un
     * off-by-one del convenio no se ve, y este fichero existe para verlo.
     *
     * @param  list<string>  $dias
     */
    private function esperado(array $dias): array
    {
        $columnas = array_fill_keys(
            ['domingo', 'lunes', 'martes', 'miercoles', 'jueves', 'viernes', 'sabado'], 0
        );

        foreach ($dias as $dia) {
            $this->assertArrayHasKey($dia, $columnas, "Día inventado en la expectativa: {$dia}.");
            $columnas[$dia] = 1;
        }

        return $columnas;
    }

    /** Las siete columnas de una asignatura, en el orden del convenio. */
    private function columnasDe(int $asignaturaId): array
    {
        $f = DB::select(
            'SELECT domingo, lunes, martes, miercoles, jueves, viernes, sabado
             FROM asignaturas WHERE id = ?', [$asignaturaId]
        )[0];

        return array_map(static fn ($v): int => (int) $v, (array) $f);
    }

    #[Test]
    public function publicar_deriva_las_columnas_desde_las_lecciones_de_la_version(): void
    {
        $asignaturas = $this->asignaturasDe(self::YEAR_ACTUAL);
        $this->assertGreaterThanOrEqual(3, count($asignaturas),
            'El seed dejó de tener asignaturas en el año actual y este caso no ejerce nada.');

        [$una, $otra, $tercera] = $asignaturas;

        $version = $this->crearVersion(self::YEAR_ACTUAL, [
            [$una, 1], [$una, 3],   // lunes y miércoles
            [$otra, 5],             // viernes
        ]);

        $this->publicar($version)->assertStatus(200);

        $this->assertSame($this->esperado(['lunes', 'miercoles']), $this->columnasDe($una),
            'La asignatura con lecciones en lunes y miércoles no quedó con esas dos columnas.');
        $this->assertSame($this->esperado(['viernes']), $this->columnasDe($otra),
            'La asignatura de viernes no quedó sólo con viernes.');
        $this->assertSame($this->esperado([]), $this->columnasDe($tercera),
            'Una asignatura que la versión NO trae tiene que quedar a cero en las siete.');
    }

    /**
     * **El alcance es el año, no las filas de la versión.**
     *
     * Es el fallo 1 de la cabecera, y la forma de escribirlo importa: la versión 2
     * **no menciona** la asignatura que traía la 1. Si la derivación sólo escribiera
     * lo que le llega, esta asignatura no aparecería en ningún `UPDATE` y se quedaría
     * con el martes de la versión anterior.
     */
    #[Test]
    public function lo_que_la_version_nueva_no_trae_vuelve_a_cero(): void
    {
        [$una, $otra] = $this->asignaturasDe(self::YEAR_ACTUAL);

        $this->publicar($this->crearVersion(self::YEAR_ACTUAL, [[$una, 2]], 'v1'))->assertStatus(200);

        $this->assertSame(1, $this->columnasDe($una)['martes'],
            'La primera versión no dejó el martes puesto, así que este caso no puede '.
            'demostrar que la segunda lo quita.');

        // La v2 habla de OTRA asignatura y no nombra a `$una` en ninguna fila.
        $this->publicar($this->crearVersion(self::YEAR_ACTUAL, [[$otra, 4]], 'v2'))->assertStatus(200);

        $this->assertSame($this->esperado([]), $this->columnasDe($una),
            'La asignatura que la versión 2 QUITA se quedó con el martes de la versión 1: '.
            'el docente sigue viendo una clase que ya no existe. El alcance de la derivación '.
            'es el AÑO ENTERO, no las filas de la versión (§7).');
        $this->assertSame(1, $this->columnasDe($otra)['jueves'],
            'La asignatura que sí trae la versión 2 no quedó con su jueves.');
    }

    /**
     * **El alcance va por JOIN contra `grupos.year_id`, y no toca al vecino.**
     *
     * Es el fallo 2, y el escenario es el de la decisión 13: se publica un año
     * teniendo otro con las columnas puestas. Un alcance mal acotado las pone a cero
     * y **nadie lo nota hasta que un docente de ese otro año abre su panel**.
     */
    #[Test]
    public function la_derivacion_no_toca_las_columnas_de_otro_anio(): void
    {
        $delVecino = $this->asignaturasDe(self::YEAR_VECINO);
        $this->assertNotEmpty($delVecino,
            'El seed dejó de tener asignaturas en el año '.self::YEAR_VECINO.', y sin ellas '.
            'este caso pasa sin comprobar nada: es el que vigila el JOIN.');

        DB::update('UPDATE asignaturas SET martes = 1, viernes = 1 WHERE id = ?', [$delVecino[0]]);

        // Se guarda lo que hay y se compara contra ESO, no contra una forma escrita a
        // mano: el seed trae días puestos por su cuenta, y una expectativa literal
        // fallaría por el seed en vez de por el alcance, que es lo que se mide.
        $antes = $this->columnasDe($delVecino[0]);
        $this->assertSame(1, $antes['martes'], 'El vecino tiene que entrar con el martes puesto.');

        [$una] = $this->asignaturasDe(self::YEAR_ACTUAL);
        $this->publicar($this->crearVersion(self::YEAR_ACTUAL, [[$una, 1]]))->assertStatus(200);

        $this->assertSame($antes, $this->columnasDe($delVecino[0]),
            'Publicar el año '.self::YEAR_ACTUAL.' borró las columnas del año '.self::YEAR_VECINO.'. '.
            '`asignaturas` NO tiene `year_id`: el año le llega por `grupos.year_id` y el alcance '.
            'es un JOIN, no un WHERE (§7).');
    }

    /**
     * **El convenio de `dia` no se traduce**, día por día.
     *
     * La tabla entera y no un caso de muestra: un convenio corrido en uno pasa
     * cualquier prueba que sólo mire un día, y no da error en ningún sitio.
     */
    #[Test]
    #[DataProvider('losSieteDias')]
    public function el_dia_no_se_traduce(int $dia, string $columna): void
    {
        [$una] = $this->asignaturasDe(self::YEAR_ACTUAL);

        $this->publicar($this->crearVersion(self::YEAR_ACTUAL, [[$una, $dia]]))->assertStatus(200);

        $columnas = $this->columnasDe($una);
        $esperado = array_fill_keys(array_keys($columnas), 0);
        $esperado[$columna] = 1;

        $this->assertSame($esperado, $columnas,
            "Una lección con `dia = {$dia}` tiene que poner `{$columna}` y sólo esa. ".
            'El convenio es 0 = domingo … 6 = sábado (§5.2.5), el mismo con el que '.
            '`asignaturas_dia()` las consume: la derivación NO traduce.');
    }

    #[Test]
    public function el_anio_apunta_a_la_version_publicada(): void
    {
        [$una] = $this->asignaturasDe(self::YEAR_ACTUAL);
        $version = $this->crearVersion(self::YEAR_ACTUAL, [[$una, 1]]);

        $this->publicar($version)->assertStatus(200);

        $this->assertSame($version, (int) DB::select(
            'SELECT horario_version_id FROM years WHERE id = ?', [self::YEAR_ACTUAL]
        )[0]->horario_version_id, 'El puntero del año no quedó apuntando a la versión publicada.');
    }

    /**
     * **La respuesta dice su población**, que es la condición de la opción B (§6).
     *
     * Un `200` pelado no distingue *derivé las 10* de *no había ni una fila que
     * derivar*, y las dos se ven idénticas desde el cliente.
     */
    #[Test]
    public function la_respuesta_dice_su_poblacion(): void
    {
        $asignaturas = $this->asignaturasDe(self::YEAR_ACTUAL);
        [$una, $otra] = $asignaturas;

        $r = $this->publicar($this->crearVersion(self::YEAR_ACTUAL, [
            [$una, 1], [$una, 2], [$otra, 1],
        ]));

        $r->assertStatus(200);

        $this->assertSame(count($asignaturas), $r->json('derivacion.asignaciones_en_el_alcance'),
            'El alcance que dice la respuesta no es el número de asignaturas vivas del año.');
        $this->assertSame(2, $r->json('derivacion.asignaciones_con_algun_dia'));
        $this->assertSame(3, $r->json('derivacion.filas_de_la_version'));
        $this->assertSame(2, $r->json('derivacion.por_dia.lunes'));
        $this->assertSame(1, $r->json('derivacion.por_dia.martes'));
        $this->assertSame(0, $r->json('derivacion.por_dia.sabado'));
        $this->assertSame(0, $r->json('derivacion.asignaciones_de_la_version_fuera_del_alcance'));
        $this->assertTrue($r->json('es_oficial'));
    }

    /**
     * Una versión que no existe es **404**, no 501 ni 200 vacío.
     */
    #[Test]
    public function una_version_que_no_existe_es_404(): void
    {
        $this->publicar(999999)->assertStatus(404);
    }

    /**
     * **La misa: UNA pieza, VARIOS grupos, y las filas vienen de la tabla.**
     *
     * En la subida, la ocupación se indexa por `pieza_id` para que una pieza de seis
     * grupos no se declare choque a sí misma. Aquí las filas **ya no vienen del cuerpo**
     * —vienen de `horario_lecciones`, donde esa misa son N filas con el mismo `pieza_id`
     * y distinto `asignatura_id`—, que es el sitio donde `8myvc-9c` avisó de que la
     * defensa vuelve a hacer falta.
     *
     * Lo que este caso fija es que **la derivación es inmune por construcción**: `EXISTS`
     * contesta *sí o no* y no *cuántas*, así que las seis asignaciones quedan con su día
     * puesto una vez y ninguna se cuenta dos veces. **Y fija la cifra que sí lo nota**:
     * la respuesta dice `filas` y `piezas` por separado, porque «6 filas» de una misa y
     * «6 filas» de seis clases sueltas se leen igual y no son lo mismo.
     *
     * **Se fabrica a mano, y eso es el hallazgo, no un atajo**: hoy no hay ni una pieza
     * de varios grupos en los datos reales, así que si esto se rompiera **no lo
     * delataría nada**.
     */
    #[Test]
    public function una_misa_es_una_pieza_con_varios_grupos_y_no_se_cuenta_dos_veces(): void
    {
        $asignaturas = $this->asignaturasDe(self::YEAR_ACTUAL);
        $this->assertGreaterThanOrEqual(3, count($asignaturas));

        [$una, $otra, $tercera] = $asignaturas;

        // Tres asignaciones de grupos distintos, LA MISMA pieza, el mismo miércoles.
        $r = $this->publicar($this->crearVersion(self::YEAR_ACTUAL, [
            [$una, 3, 'misa-0'],
            [$otra, 3, 'misa-0'],
            [$tercera, 3, 'misa-0'],
        ]));

        $r->assertStatus(200);

        foreach ([$una, $otra, $tercera] as $id) {
            $this->assertSame($this->esperado(['miercoles']), $this->columnasDe($id),
                'Cada asignación unida por la misa tiene que quedar con SU miércoles puesto: '.
                'la pieza es una, pero las clases son las tres.');
        }

        $this->assertSame(3, $r->json('derivacion.filas_de_la_version'));
        $this->assertSame(1, $r->json('derivacion.piezas_de_la_version'),
            'La misa es UNA pieza. Si esto dice 3, la respuesta está contando clases donde '.
            'hay una sola lección de varios grupos, y quien la lea contará horas que no existen.');
        $this->assertSame(3, $r->json('derivacion.asignaciones_con_algun_dia'));
        // TRES y no una: el recuento por día va por **asignación**, y la misa es una
        // lección de varios grupos, o sea tres clases reales — una en cada grupo, y las
        // tres ese miércoles. Lo que vale uno es la PIEZA. Que estos dos números
        // discrepen es la señal de que la respuesta distingue las dos cosas.
        $this->assertSame(3, $r->json('derivacion.por_dia.miercoles'),
            'El recuento por día cuenta las ASIGNACIONES que tienen clase ese día, y la '.
            'misa se la da a sus tres grupos.');
    }

    /**
     * **EL SÁBADO**, que es el fallo que se estrena con las columnas llenas.
     *
     * Con `$dia + 1` sin más, el sábado pide el día **7**; `asignaturas_dia()` no
     * tiene caso 7, `$dia_cond` se queda en el espacio en blanco con el que nace y
     * «mañana» devuelve **todas** las asignaturas del docente. Aquí el docente tiene
     * clase el lunes y **nada el domingo**, así que «mañana» tiene que salir vacío.
     *
     * El reloj se congela en un sábado: es la única forma de ejercer esta rama, y sin
     * congelarlo el caso pasaría seis días de cada siete **sin comprobar nada**.
     */
    #[Test]
    public function el_sabado_manana_no_devuelve_todas_las_asignaturas(): void
    {
        /*
         * **El docente se elige por tener clase, no por ser el primero.**
         * `usuarioDeTipo('Profesor')` coge el de `id` más bajo, y en el seed ése tiene
         * **cero** asignaturas: con él «mañana» sale vacío con el fallo y sin él, o sea
         * que el caso pasaría **sin ejercer nada**. `users` tampoco tiene `year_id` —el
         * año le llega por su periodo, la misma forma de la trampa que `asignaturas`—,
         * así que el año entra en la consulta y no se supone.
         */
        $docente = DB::select(
            'SELECT u.id, u.username, pr.id AS profesor_id, p.year_id, count(a.id) AS asigs
             FROM users u
             INNER JOIN profesores pr ON pr.user_id = u.id AND pr.deleted_at IS NULL
             INNER JOIN periodos p ON p.id = u.periodo_id
             INNER JOIN asignaturas a ON a.profesor_id = pr.id AND a.deleted_at IS NULL
             INNER JOIN grupos g ON g.id = a.grupo_id AND g.year_id = p.year_id AND g.deleted_at IS NULL
             WHERE u.tipo = "Profesor" AND u.is_active = 1 AND u.deleted_at IS NULL
             GROUP BY u.id, u.username, pr.id, p.year_id
             ORDER BY asigs DESC, u.id ASC LIMIT 1'
        );

        $this->assertNotEmpty($docente,
            'El seed no tiene ningún docente CON asignaturas en su propio año, y sin uno '.
            'este caso pasa sin comprobar nada: «mañana» saldría vacío con el fallo y sin él.');

        $usuario = $docente[0];
        $profesorId = (int) $usuario->profesor_id;
        $yearId = (int) $usuario->year_id;

        $suyas = array_map(static fn ($f): int => (int) $f->id, DB::select(
            'SELECT a.id FROM asignaturas a
             INNER JOIN grupos g ON g.id = a.grupo_id AND g.deleted_at IS NULL
             WHERE a.profesor_id = ? AND a.deleted_at IS NULL AND g.year_id = ?',
            [$profesorId, $yearId]
        ));

        $this->assertNotEmpty($suyas, 'El docente elegido se quedó sin asignaturas.');

        // Lunes para todas, domingo para ninguna.
        $this->publicar($this->crearVersion(
            $yearId,
            array_map(static fn (int $id): array => [$id, 1], $suyas)
        ))->assertStatus(200);

        /*
         * **El reloj se congela ANTES de pedir el token, y esto costó un 401.** La
         * sesión se valida contra `Carbon::now()`, así que un token emitido con el
         * reloj real y presentado con el reloj congelado tres días por delante ya está
         * caducado. Congelando primero, emisión y comprobación caen en el mismo día.
         *
         * **Y se mira desde los DOS lados, porque «vacío» solo no demuestra nada.**
         * Un «mañana» vacío el sábado es exactamente lo que devolvería también un
         * endpoint roto, un token sin permiso o un docente sin clases. El sábado tiene
         * que salir vacío —mañana es domingo y no hay nada— **y el domingo tiene que
         * salir lleno** —mañana es lunes, que es donde se puso todo—. Con las dos, el
         * vacío del sábado significa lo que dice.
         *
         * Sólo se mira `horario_manana`: es la llamada del fallo, y la única que
         * **nunca** recibe `show_materias_todas`, así que su filtro no depende de un
         * interruptor del colegio que en el seed puede estar en cualquier lado.
         */
        $mananaDe = function (string $fecha) use ($usuario): array {
            Carbon::setTestNow(Carbon::parse($fecha, 'America/Bogota'));

            try {
                $r = $this->getJson('/api/ChangesAsked/to-me', [
                    'Authorization' => 'Bearer '.$this->tokenDe($usuario->username),
                ]);
                $r->assertStatus(200);

                return $r->json('horario_manana') ?? [];
            } finally {
                Carbon::setTestNow();
            }
        };

        // Domingo 6 sep 2026: mañana es LUNES, donde están todas sus clases.
        $this->assertSame(6, Carbon::parse('2026-09-05 10:00:00', 'America/Bogota')->dayOfWeek,
            'La fecha del sábado dejó de ser sábado y este caso no ejercería la rama.');
        $this->assertSame(0, Carbon::parse('2026-09-06 10:00:00', 'America/Bogota')->dayOfWeek,
            'La fecha del domingo dejó de ser domingo y el control positivo no valdría.');

        $this->assertNotEmpty($mananaDe('2026-09-06 10:00:00'),
            'El domingo, «mañana» es lunes y el docente tiene todas sus clases ahí: si esto '.
            'sale vacío, el vacío del sábado no demuestra nada y hay que mirar la derivación '.
            'o el token antes que el fallo del sábado.');

        $this->assertSame([], $mananaDe('2026-09-05 10:00:00'),
            'EL SÁBADO, «mañana» devolvió asignaturas. `$dia + 1` da 7, `asignaturas_dia()` '.
            'no tiene caso 7, `$dia_cond` se queda en blanco y el docente ve el curso entero '.
            'un día a la semana (§2.1). El día siguiente al sábado es el domingo, que es 0.');
    }
}
