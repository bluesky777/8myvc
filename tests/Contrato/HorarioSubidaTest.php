<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * `POST horario/versiones` — el cuerpo, no el guard.
 *
 * `HorarioAutorizacionTest` prueba **quién** puede llamar a esta ruta, y a
 * propósito no prueba nada más: sus casos positivos usan `assertNotSame(403, …)`
 * para no fijar el 501 del andamio, así que **pasan igual contra un 422, un 500 o
 * un 200**. Cuando `postVersiones` dejó de ser 501 y pasó a tener 732 líneas
 * —validación de forma, seis comprobaciones, veredicto, transacción y dieciocho
 * rechazos—, **ninguna de ellas quedó ejercitada por nada automático**. El commit
 * que las escribió dice «las tres rutas ejercitadas» y «las comprobaciones
 * probadas rojas», y fue **a mano contra el docker**: cierto el día que se hizo, y
 * no algo que vuelva a correr mañana. Este fichero es lo que vuelve a correr.
 *
 * **No toca `HorarioController`.** Prueba la implementación tal como está.
 *
 * ## Lo que el dato real NO ejercita, y por eso se fabrica aquí
 *
 * Medido por la sesión del escritorio sobre `lleno.myvch`, el único proyecto real
 * que existe: **312 piezas colocadas, 0 de varios grupos, 0 sin asignación, y
 * ningún choque en ninguna de las dos lecturas**. O sea que subir ese fichero
 * **no distingue** una implementación correcta de tres formas de romperla:
 *
 *   1. **Los choques se calculan por CASILLA, no por pieza.** Un bloque de
 *      `duracion` 2 en la franja 3 ocupa la 3 y la 4. Sin expandir, ese bloque y
 *      una pieza en la franja 4 **no dan duplicado y chocan igual** — y sobre el
 *      fichero real la diferencia es 344 casillas frente a 312 piezas, con cero
 *      choques en ambas. Nada lo delataría.
 *   2. **La misa: una pieza con varias asignaciones es UNA ocupación, no N.** Hoy
 *      hay 0 piezas de varios grupos en el único proyecto real, así que la forma
 *      —una pieza, N filas, una casilla— no la ejercita nada.
 *   3. **Σ ≤ IH suma `duracion`, no cuenta filas.** Sobre el fichero real, contar
 *      filas daría 312 frente a 344, o sea **32 horas de menos**, y el renglón
 *      blando diría que casi todas las asignaciones están incompletas.
 *
 * Las tres se construyen aquí a mano. **Un verde sobre el seed tal cual no
 * demostraría ninguna de las tres**, que es exactamente lo que pasó con el `LEFT`
 * de `Asignatura::detallada()`: 1.006 tests en verde sin ejercer el cambio.
 *
 * **La 1 y la 3 se comprobaron en rojo rompiendo el controlador a propósito** —el
 * bucle de casillas fijado en una, y la Σ sumando 1 por fila en vez de `duracion`—
 * y las dos cayeron. **La 2 NO se pudo comprobar en rojo, y eso se dice en su
 * sitio en vez de dejar el docblock afirmándolo**: ver
 * `una_pieza_con_dos_asignaturas_no_choca_consigo_misma`.
 *
 * ## El seed tiene UN grupo por año, y por eso aquí se fabrica un segundo
 *
 * Con un solo grupo —el 98 en el año actual, el 84 en el anterior— dos piezas en la
 * misma casilla chocan **por grupo y por docente a la vez**, así que romper sólo una
 * de las dos comprobaciones **la cazaría la otra** y el test seguiría verde. Son
 * **dos de las seis** de la §6, cada una con su 422: que se validen entre ellas es
 * justo lo que no puede quedarse sin comprobar, y es la misma forma que el rojo que
 * no llegó — dos caminos indistinguibles con el verde encima.
 *
 * Por eso `unSegundoGrupo()` fabrica un grupo con su asignatura dentro de la
 * transacción del test, y los dos choques se separan: **mismo docente en dos grupos
 * distintos** da choque de docente y **no** de grupo, y **dos docentes distintos en
 * el mismo grupo** da el contrario. Los dos comprobados en rojo, cada uno anulando
 * sólo su comprobación.
 */
class HorarioSubidaTest extends CasoDeContrato
{
    /** Un proyecto de mentira: aquí no se mira su contenido, sólo que viaje. */
    private const PROYECTO = '{"version":1,"piezas":[],"nota":"no es un .myvch de verdad"}';

    /** El año abierto del seed. */
    private function anio(): object
    {
        $fila = DB::selectOne('SELECT id, year, nombre_colegio FROM years WHERE actual = 1 AND deleted_at IS NULL');

        $this->assertNotNull($fila, 'El seed no tiene ningún año con `actual = 1`.');

        return $fila;
    }

    /**
     * Las asignaturas vivas de un año, en orden, para poder nombrarlas por
     * posición sin escribir ningún id a mano — los del seed cambian.
     *
     * @return array<int, object>
     */
    private function asignaturasDe(int $yearId): array
    {
        $filas = DB::select(
            'SELECT a.id, a.creditos, a.grupo_id FROM asignaturas a
               JOIN grupos g ON g.id = a.grupo_id
              WHERE g.year_id = ? AND a.deleted_at IS NULL AND g.deleted_at IS NULL
              ORDER BY a.id',
            [$yearId]
        );

        $this->assertGreaterThanOrEqual(3, count($filas),
            "El año {$yearId} del seed tiene ".count($filas)." asignaturas vivas y hacen falta al menos 3.\n".
            'Sin ellas estos casos pasarían sin ejercer nada.');

        return $filas;
    }

    /** Un `profesores.id` que existe. */
    private function unProfesor(): int
    {
        return (int) DB::selectOne('SELECT id FROM profesores WHERE deleted_at IS NULL ORDER BY id LIMIT 1')->id;
    }

    /** Dos `profesores.id` distintos que existen. */
    private function dosProfesores(): array
    {
        $filas = DB::select('SELECT id FROM profesores WHERE deleted_at IS NULL ORDER BY id LIMIT 2');

        $this->assertCount(2, $filas, 'El seed no tiene dos profesores vivos.');

        return [(int) $filas[0]->id, (int) $filas[1]->id];
    }

    /**
     * Un segundo grupo del año con una asignatura suya, fabricado aquí.
     *
     * **No es comodidad de escenario: es lo único que separa las dos
     * comprobaciones.** Con el único grupo del seed, un choque de grupo y uno de
     * docente salen siempre juntos y cada uno tapa al otro.
     *
     * Se cuelga del mismo `grado_id` y la misma materia que una asignatura que ya
     * existe, para no inventar más filas de las necesarias. La transacción del test
     * lo deshace todo al terminar.
     *
     * @return int el `asignaturas.id` de la asignatura nueva, en un grupo nuevo
     */
    private function unSegundoGrupo(int $yearId): int
    {
        $modelo = DB::selectOne(
            'SELECT a.materia_id, g.grado_id FROM asignaturas a
               JOIN grupos g ON g.id = a.grupo_id
              WHERE g.year_id = ? AND a.deleted_at IS NULL ORDER BY a.id LIMIT 1',
            [$yearId]
        );

        $this->assertNotNull($modelo, "El año {$yearId} no tiene ninguna asignatura de la que copiar la forma.");

        DB::insert('INSERT INTO grupos (nombre, year_id, grado_id) VALUES (?, ?, ?)',
            ['Grupo de prueba del choque', $yearId, (int) $modelo->grado_id]);

        $grupoId = (int) DB::getPdo()->lastInsertId();

        DB::insert('INSERT INTO asignaturas (materia_id, grupo_id, creditos) VALUES (?, ?, ?)',
            [(int) $modelo->materia_id, $grupoId, 5]);

        return (int) DB::getPdo()->lastInsertId();
    }

    /**
     * @param  list<int>  $asignaciones
     * @param  list<int>  $docentes
     * @return array<string, mixed>
     */
    private function pieza(string $id, int $dia, int $franja, int $duracion, array $asignaciones, array $docentes = []): array
    {
        return [
            'pieza_id' => $id,
            'dia' => $dia,
            'franja' => $franja,
            'duracion' => $duracion,
            'asignaciones' => $asignaciones,
            'docentes' => $docentes,
            'salon_nombre' => 'Aula 4',
            'salon_capacidad_grupos' => 1,
        ];
    }

    /**
     * Sube. `$version` sobrescribe campos de `version`; `$extra` mete claves de
     * primer nivel —o las quita, pasando `null` con `$quitar`—.
     *
     * @param  list<array<string, mixed>>  $piezas
     * @param  array<string, mixed>  $version
     * @param  array<string, mixed>  $extra
     * @param  list<string>  $quitar
     */
    private function subir(array $piezas, array $version = [], array $extra = [], array $quitar = []): TestResponse
    {
        $anio = $this->anio();

        $cuerpo = array_merge([
            'version' => array_merge([
                'nombre' => 'Propuesta A',
                'year_id' => (int) $anio->id,
                'anio' => (int) $anio->year,
                'nombre_colegio' => (string) $anio->nombre_colegio,
            ], $version),
            'proyecto' => self::PROYECTO,
            'piezas' => $piezas,
        ], $extra);

        foreach ($quitar as $clave) {
            unset($cuerpo[$clave]);
        }

        return $this->postJson('/api/horario/versiones', $cuerpo, [
            'Authorization' => 'Bearer '.$this->tokenDe($this->usuarioDeTipo('Usuario')->username),
        ]);
    }

    /**
     * **Nada se escribió**, en las tres tablas y no sólo en la de versiones.
     *
     * Va en casi todos los rechazos porque es la mitad del contrato que un código
     * de estado no dice: la §6 exige que *la versión entre entera o no entre*, y
     * un 422 con filas dentro sería peor que un 500 — dejaría una versión a medias
     * que **parece un horario**.
     */
    private function assertNadaEscrito(string $porque): void
    {
        foreach (['horario_versiones', 'horario_lecciones', 'horario_pieza_docente'] as $tabla) {
            $this->assertSame(0, (int) DB::selectOne("SELECT COUNT(*) AS n FROM {$tabla}")->n,
                "Se rechazó ({$porque}) y quedaron filas en `{$tabla}`. La versión entra entera o no entra.");
        }
    }

    // ─────────────────────────────────────────────────────────── el camino feliz

    #[Test]
    public function una_version_buena_entra_entera_y_devuelve_201_con_su_veredicto(): void
    {
        $anio = $this->anio();
        $a = $this->asignaturasDe((int) $anio->id);
        $profe = $this->unProfesor();

        $r = $this->subir([
            $this->pieza('a1-0', 1, 1, 1, [(int) $a[0]->id], [$profe]),
            $this->pieza('a2-0', 1, 2, 1, [(int) $a[1]->id], [$profe]),
        ]);

        $r->assertStatus(201);

        $versionId = $r->json('id');

        $this->assertSame((int) $anio->id, $r->json('year_id'));
        $this->assertFalse($r->json('es_oficial'), 'Subir NO publica: la versión nace sin ser la oficial.');

        // **No vuelve `proyecto`.** Devolverlo aquí abriría por la puerta de atrás
        // lo que `GET horario/versiones` cierra por delante: listar no es descargar.
        $this->assertNull($r->json('proyecto'),
            'La respuesta trae el blob del proyecto. Listar no es descargar (decisión 12), y '.
            'devolverlo en el POST es el mismo agujero con otra puerta.');

        $fila = DB::selectOne('SELECT year_id, nombre, subida_por, proyecto, comprobaciones FROM horario_versiones WHERE id = ?', [$versionId]);

        $this->assertSame(self::PROYECTO, $fila->proyecto, 'El blob no se guardó tal cual.');
        $this->assertNotSame('', (string) $fila->comprobaciones, 'La versión se guardó sin veredicto.');

        // Dos piezas × una asignación cada una = dos lecciones, y dos docentes.
        $this->assertSame(2, (int) DB::selectOne('SELECT COUNT(*) AS n FROM horario_lecciones WHERE version_id = ?', [$versionId])->n);
        $this->assertSame(2, (int) DB::selectOne('SELECT COUNT(*) AS n FROM horario_pieza_docente WHERE version_id = ?', [$versionId])->n);

        // El salón viaja y se guarda; lo que NO hace es validar nada.
        $leccion = DB::selectOne('SELECT salon, salon_capacidad_grupos, dia, franja, duracion FROM horario_lecciones WHERE version_id = ? AND pieza_id = ?', [$versionId, 'a1-0']);
        $this->assertSame('Aula 4', $leccion->salon);
        $this->assertSame(1, (int) $leccion->salon_capacidad_grupos);
    }

    /**
     * **`subida_por` sale del token y `comprobaciones` del servidor**, y mandarlos
     * en el cuerpo no cambia nada (§5.2, correcciones 2 y 3).
     *
     * Un identificador de persona que llega por el cuerpo y no comprueba nadie es
     * un patrón que aquí tiene herramienta propia; y un veredicto que viaja de
     * fuera deja que un cliente suba un horario con «comprobado todo ✓» encima,
     * con lo que el historial deja de servir para lo único que sirve.
     */
    #[Test]
    public function el_cuerpo_no_puede_firmar_por_otro_ni_escribir_su_propio_veredicto(): void
    {
        $anio = $this->anio();
        $a = $this->asignaturasDe((int) $anio->id);
        $usuario = $this->usuarioDeTipo('Usuario');

        $r = $this->subir(
            [$this->pieza('a1-0', 2, 1, 1, [(int) $a[0]->id])],
            ['subida_por' => 999999],
            ['comprobaciones' => 'comprobado todo ✓', 'subida_por' => 999999, 'created_at' => '1999-01-01 00:00:00']
        );

        $r->assertStatus(201);

        $fila = DB::selectOne('SELECT subida_por, comprobaciones, created_at FROM horario_versiones WHERE id = ?', [$r->json('id')]);

        $this->assertSame((int) $usuario->id, (int) $fila->subida_por,
            'La versión quedó firmada por quien dijo el cuerpo y no por quien trajo el token.');
        $this->assertStringNotContainsString('comprobado todo ✓', (string) $fila->comprobaciones,
            'El veredicto del cuerpo llegó a la columna: el historial deja de ser el juicio del servidor.');
        $this->assertStringNotContainsString('1999', (string) $fila->created_at,
            'La fecha salió del reloj del portátil del coordinador y no del servidor.');
    }

    /** Un horario que todavía no se ha empezado es una versión legítima. */
    #[Test]
    public function una_version_sin_ninguna_pieza_entra(): void
    {
        $this->subir([])->assertStatus(201);
    }

    // ──────────────────────────────────────────────────── la forma, con 422 delante

    /**
     * **El 422 va DELANTE del `NOT NULL`.** `horario_versiones.proyecto` es
     * `mediumText` sin `nullable()`, así que sin esta validación el cliente
     * recibiría un error de SQL en vez de un mensaje.
     */
    #[Test]
    public function sin_proyecto_es_422_y_no_un_error_de_sql(): void
    {
        $r = $this->subir([], [], [], ['proyecto']);

        $r->assertStatus(422);
        $this->assertNadaEscrito('sin proyecto');
    }

    /**
     * **Un `pieza_id` que se pasa de 64 se RECHAZA; nunca se trunca.**
     *
     * Es el fallo más caro de esta familia y no da ningún error: dos piezas que
     * trunquen a los mismos 64 caracteres **se fusionan en una sola**, y entonces
     * filas que no son una misa comparten `pieza_id` en la misma casilla — con lo
     * que el choque de docente se calcula **sobre una pieza que no existe**. En
     * MySQL no estricto no dice absolutamente nada.
     *
     * El núcleo del escritorio no limita longitud ni juego de caracteres: el
     * formato `a{id}-{i}` es convención del llamador, no garantía del núcleo.
     */
    #[Test]
    public function un_pieza_id_de_mas_de_64_se_rechaza_y_no_se_trunca(): void
    {
        $a = $this->asignaturasDe((int) $this->anio()->id);
        $largo = str_repeat('a', 65);

        $this->subir([$this->pieza($largo, 1, 1, 1, [(int) $a[0]->id])])->assertStatus(422);

        $this->assertNadaEscrito('pieza_id de 65');

        $this->assertSame(0, (int) DB::selectOne(
            'SELECT COUNT(*) AS n FROM horario_lecciones WHERE pieza_id = ?', [substr($largo, 0, 64)]
        )->n, 'El identificador entró truncado a 64: es así como dos piezas se funden en una.');
    }

    // ─────────────────────────────────────────── el dominio, nombrando la pieza

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function piezasQueNoValen(): array
    {
        return [
            // `dia` es el día de la semana de verdad (0 = domingo), el convenio con
            // el que se CONSUMEN las siete columnas — no el índice de la columna de
            // la rejilla.
            'dia 7' => [['dia' => 7], 'El día va de 0 a 6'],
            'dia -1' => [['dia' => -1], 'El día va de 0 a 6'],
            'franja 0' => [['franja' => 0], 'La franja es base 1'],
            // Casillas, no minutos. `years.minu_hora_clase` vale 50 y está en la
            // base, así que sin declararlo alguien lee `duracion` en minutos.
            'duracion 0' => [['duracion' => 0], 'se cuenta en CASILLAS'],
        ];
    }

    #[DataProvider('piezasQueNoValen')]
    public function test_una_pieza_mal_formada_es_422_que_la_nombra(array $cambio, string $trozo): void
    {
        $a = $this->asignaturasDe((int) $this->anio()->id);
        $pieza = array_merge($this->pieza('a1-0', 1, 1, 1, [(int) $a[0]->id]), $cambio);

        $r = $this->subir([$pieza]);

        $r->assertStatus(422);
        // El `pieza_id` va APARTE del `message`: un error que sólo es texto obliga
        // al cliente a leerlo con expresiones regulares para señalar la casilla.
        $r->assertJsonPath('pieza_id', 'a1-0');
        $this->assertStringContainsString($trozo, (string) $r->json('motivo'));
        $this->assertNadaEscrito('pieza mal formada');
    }

    /**
     * Una pieza sin `asignaturas` no se puede subir, y **nunca se empareja por
     * nombres** (§8).
     *
     * Es la frontera de vender el escritorio sin MyVC detrás: un proyecto armado
     * sin MyVC no tiene `asignatura_id` de nada. Aceptar nulos dejaría «Clases de
     * hoy» igual de vacía, esta vez con un horario oficial encima; y emparejar por
     * nombres —la salida que parece amable— mete las horas de «Matemáticas de 3°A»
     * en 3°B **sin dar ningún error**.
     */
    #[Test]
    public function una_pieza_sin_asignaturas_no_se_puede_subir(): void
    {
        $r = $this->subir([$this->pieza('a1-0', 1, 1, 1, [])]);

        $r->assertStatus(422);
        $r->assertJsonPath('pieza_id', 'a1-0');
        $this->assertStringContainsString('NUNCA se empareja por nombres', (string) $r->json('motivo'));
        $this->assertNadaEscrito('pieza sin asignaturas');
    }

    /**
     * El mismo `pieza_id` dos veces rompe la garantía sobre la que se apoya el
     * choque de docente: las filas que comparten `pieza_id` **son la misma pieza en
     * la misma casilla**.
     */
    #[Test]
    public function el_mismo_pieza_id_dos_veces_es_422(): void
    {
        $a = $this->asignaturasDe((int) $this->anio()->id);

        $r = $this->subir([
            $this->pieza('a1-0', 1, 1, 1, [(int) $a[0]->id]),
            $this->pieza('a1-0', 2, 1, 1, [(int) $a[1]->id]),
        ]);

        $r->assertStatus(422);
        $r->assertJsonPath('pieza_id', 'a1-0');
        $this->assertNadaEscrito('pieza_id repetido');
    }

    #[Test]
    public function una_asignatura_que_no_existe_es_422(): void
    {
        $r = $this->subir([$this->pieza('a1-0', 1, 1, 1, [999999])]);

        $r->assertStatus(422);
        $r->assertJsonPath('pieza_id', 'a1-0');
        $this->assertStringContainsString('no existe en este colegio', (string) $r->json('motivo'));
        $this->assertNadaEscrito('asignatura inexistente');
    }

    /**
     * Una asignatura de la papelera **se nombra como tal**, no como «no existe»:
     * son dos arreglos distintos en el colegio. Dejarla entrar descuadraría Σ ≤ IH
     * sin explicación posible.
     *
     * Se sube al **año anterior**, que es donde el seed tiene borradas — y subir a
     * un año cerrado está decidido que vale (decisión 13).
     */
    #[Test]
    public function una_asignatura_de_la_papelera_es_422(): void
    {
        $borrada = DB::selectOne(
            'SELECT a.id, g.year_id, y.year FROM asignaturas a
               JOIN grupos g ON g.id = a.grupo_id
               JOIN years y ON y.id = g.year_id
              WHERE a.deleted_at IS NOT NULL AND y.deleted_at IS NULL
              ORDER BY a.id LIMIT 1'
        );

        $this->assertNotNull($borrada,
            "El seed no tiene ninguna asignatura en la papelera.\n".
            'Sin ella este caso pasa sin ejercer nada — en el colegio real hay 240.');

        $r = $this->subir(
            [$this->pieza('a1-0', 1, 1, 1, [(int) $borrada->id])],
            ['year_id' => (int) $borrada->year_id, 'anio' => (int) $borrada->year]
        );

        $r->assertStatus(422);
        $this->assertStringContainsString('está en la papelera', (string) $r->json('motivo'));
        $this->assertNadaEscrito('asignatura en la papelera');
    }

    /**
     * **La cuarta comprobación de la §6**, y este es el ÚNICO sitio donde existe.
     *
     * `asignaturas` no tiene `year_id`: el año le llega por `grupos.year_id`, así
     * que «esta asignación es de este año» es un JOIN y no un `WHERE`. Y el emisor
     * del escritorio **no puede** cribarlo, porque guarda el año una sola vez, en
     * `origen.anioId`. Sin esto las filas entran, el veredicto sale limpio y lo
     * cobra la §7: marcarla oficial derivaría las columnas del año que no es.
     */
    #[Test]
    public function una_asignatura_de_otro_anio_es_422(): void
    {
        $anio = $this->anio();

        $intrusa = DB::selectOne(
            'SELECT a.id FROM asignaturas a
               JOIN grupos g ON g.id = a.grupo_id
              WHERE g.year_id <> ? AND a.deleted_at IS NULL AND g.deleted_at IS NULL
              ORDER BY a.id LIMIT 1', [(int) $anio->id]
        );

        $this->assertNotNull($intrusa, 'El seed no tiene asignaturas de otro año.');

        $r = $this->subir([$this->pieza('a1-0', 1, 1, 1, [(int) $intrusa->id])]);

        $r->assertStatus(422);
        $r->assertJsonPath('pieza_id', 'a1-0');
        $this->assertStringContainsString('derivaría las columnas de día del año que no es', (string) $r->json('motivo'));
        $this->assertNadaEscrito('asignatura de otro año');
    }

    /**
     * El docente se nombra con **`profesores.id`, nunca con `users.id`** (§5.2.1):
     * son dos columnas de la misma fila y coger la que no es sale gratis. Aquí no
     * se notaría —los 47 profesores tienen `user_id`— pero la columna es NULLable,
     * y un docente sin cuenta desaparecería de la revalidación **sin error**.
     */
    #[Test]
    public function un_docente_que_no_es_un_profesores_id_es_422(): void
    {
        $a = $this->asignaturasDe((int) $this->anio()->id);

        $r = $this->subir([$this->pieza('a1-0', 1, 1, 1, [(int) $a[0]->id], [999999])]);

        $r->assertStatus(422);
        $r->assertJsonPath('pieza_id', 'a1-0');
        $this->assertStringContainsString('NUNCA con users.id', (string) $r->json('motivo'));
        $this->assertNadaEscrito('docente inexistente');
    }

    // ───────────────────────────────────── el año: la quinta comprobación, dura

    /**
     * **Lo único que caza un `.myvch` subido al colegio equivocado.**
     *
     * `years.id` 8 es 2025 en este colegio y puede ser 2019 en otro, así que
     * *identificador que existe + año distinto* es la señal. Sin el campo `anio` el
     * servidor no tiene contra qué contrastar su propia fila, y entonces esa
     * comprobación no es que falle: **no existe, y su ausencia no da ningún
     * error**.
     */
    #[Test]
    public function el_anio_del_cuerpo_tiene_que_ser_el_del_servidor(): void
    {
        $anio = $this->anio();

        $r = $this->subir([], ['anio' => ((int) $anio->year) + 1]);

        $r->assertStatus(422);
        $r->assertJsonPath('motivo', 'anio-no-coincide');
        $r->assertJsonPath('anio_del_servidor', (int) $anio->year);
        $this->assertNadaEscrito('año que no coincide');
    }

    #[Test]
    public function un_year_id_que_no_existe_es_404(): void
    {
        $this->subir([], ['year_id' => 999999])->assertStatus(404);
        $this->assertNadaEscrito('año inexistente');
    }

    // ──────────────────────── los choques: por CASILLA y por pieza_id, fabricados

    /**
     * **La casilla es la unidad de choque, no la pieza — y esto no lo ejercita
     * ningún dato real.**
     *
     * Un bloque de `duracion` 2 en la franja 3 ocupa la 3 **y la 4**. Comparando
     * piezas por `(dia, franja)` a secas, este bloque y la pieza de la franja 4
     * **no darían duplicado** y estarían la una encima de la otra. Sobre
     * `lleno.myvch` la diferencia entre las dos lecturas es 344 casillas frente a
     * 312 piezas — y **cero choques en las dos**, así que romper la expansión no
     * lo delataría nada.
     *
     * Salen las **dos** listas porque el seed tiene un solo grupo por año y las dos
     * piezas comparten grupo y docente por construcción; separarlas exigiría un
     * grupo que este seed no tiene, y eso se dice en vez de fingirlo.
     */
    #[Test]
    public function un_bloque_de_dos_choca_con_la_franja_siguiente(): void
    {
        $a = $this->asignaturasDe((int) $this->anio()->id);
        $profe = $this->unProfesor();

        $r = $this->subir([
            $this->pieza('bloque', 3, 3, 2, [(int) $a[0]->id], [$profe]),
            $this->pieza('encima', 3, 4, 1, [(int) $a[1]->id], [$profe]),
        ]);

        $r->assertStatus(422);
        $r->assertJsonPath('motivo', 'choque');

        $this->assertNotEmpty($r->json('choques_de_grupo'),
            'El bloque de duración 2 no se expandió a sus casillas: la franja 4 quedó libre para otra pieza.');
        $this->assertNotEmpty($r->json('choques_de_docente'),
            'El mapa de docentes no se llenó casilla a casilla.');

        // El error dice su población: «hay choques» no distingue «revisé las 345 y
        // encontré tres» de «me rendí en la primera».
        $this->assertSame(2, $r->json('piezas_revisadas'));
        $this->assertSame(3, $r->json('casillas_revisadas'),
            'Dos piezas de duraciones 2 y 1 son TRES casillas; si dice 2, se está contando piezas.');

        $this->assertNadaEscrito('choque');
    }

    /**
     * **La misa: una pieza con varias asignaciones es UNA ocupación y N filas.**
     *
     * Una lección de varios grupos es **una** pieza colocada en **una** casilla que
     * gasta una hora de la IH de cada grupo unido. Aquí se construye con dos
     * asignaturas del mismo grupo, porque el seed tiene un solo grupo por año.
     *
     * ## Lo que este caso NO demuestra, y se dice porque lo comprobé
     *
     * El controlador indexa la ocupación por `pieza_id`
     * —`$porGrupo[$grupo][$dia][$franja][$pieza_id]`— y su comentario dice que
     * contando filas *«su propia misa lo declararía duplicado consigo mismo»*.
     * **Sustituí esa clave por un `[]` y los 24 casos siguieron en verde**, así que
     * esa afirmación no la sostiene ningún test — tampoco éste.
     *
     * Y el motivo no es que falte un caso: es que **hoy no hay ninguna entrada por
     * donde una pieza pueda ocupar dos veces la misma casilla**. `grupos` y
     * `docentes` se normalizan con `array_unique` antes de llegar aquí, y una pieza
     * repetida en el cuerpo se rechaza antes con su propio 422. La clave por
     * `pieza_id` es defensa en profundidad **correcta y hoy inalcanzable**, y
     * volvería a hacer falta el día que alguien quite uno de esos dos `array_unique`
     * o construya la ocupación desde las filas de `horario_lecciones` —que es de
     * donde saldrá la derivación de las siete columnas de la §7—.
     *
     * Lo que sí queda fijado aquí es la **forma**: 201, dos filas de lección con el
     * mismo `pieza_id`, y **un** docente para la pieza y no uno por asignación.
     */
    #[Test]
    public function una_pieza_con_dos_asignaturas_no_choca_consigo_misma(): void
    {
        $a = $this->asignaturasDe((int) $this->anio()->id);
        $profe = $this->unProfesor();

        $r = $this->subir([
            $this->pieza('misa', 3, 1, 1, [(int) $a[0]->id, (int) $a[1]->id], [$profe]),
        ]);

        $r->assertStatus(201);

        // Una fila por (pieza × asignación), con el `pieza_id` compartido: es lo que
        // hace que derivar las siete columnas y comprobar Σ ≤ IH sean un GROUP BY.
        $this->assertSame(2, (int) DB::selectOne(
            'SELECT COUNT(*) AS n FROM horario_lecciones WHERE version_id = ? AND pieza_id = ?',
            [$r->json('id'), 'misa']
        )->n);

        // Y UN solo docente para la pieza, no uno por asignación.
        $this->assertSame(1, (int) DB::selectOne(
            'SELECT COUNT(*) AS n FROM horario_pieza_docente WHERE version_id = ?', [$r->json('id')]
        )->n);
    }

    /**
     * **Choque de DOCENTE sin choque de grupo**: el mismo profesor en dos grupos
     * distintos a la misma hora.
     *
     * Es una de las seis de la §6 y va sobre `horario_pieza_docente`, **no sobre
     * `asignaturas.profesor_id`** — que es el caso del capellán: si la misa la da
     * él, el titular de Religión tiene esa hora libre aunque la hora salga de su
     * asignación, y leer el docente de la asignatura daría la respuesta contraria
     * justo en el único caso raro que tiene el colegio.
     *
     * Con el grupo fabricado, `choques_de_grupo` tiene que venir **vacío**: si
     * viniera lleno, el escenario no estaría separando nada.
     */
    #[Test]
    public function el_mismo_docente_en_dos_grupos_a_la_misma_hora_es_choque_de_docente(): void
    {
        $anio = $this->anio();
        $a = $this->asignaturasDe((int) $anio->id);
        $otra = $this->unSegundoGrupo((int) $anio->id);
        [$profe] = $this->dosProfesores();

        $r = $this->subir([
            $this->pieza('en-cuarto', 2, 5, 1, [(int) $a[0]->id], [$profe]),
            $this->pieza('en-el-otro', 2, 5, 1, [$otra], [$profe]),
        ]);

        $r->assertStatus(422);
        $r->assertJsonPath('motivo', 'choque');

        $this->assertNotEmpty($r->json('choques_de_docente'),
            'El mismo docente en dos grupos a la misma hora no salió como choque.');
        $this->assertEmpty($r->json('choques_de_grupo'),
            'Salió también choque de grupo: son dos grupos distintos, así que el escenario '.
            'no está separando las dos comprobaciones y cada una taparía a la otra.');

        $this->assertNadaEscrito('choque de docente');
    }

    /**
     * **Choque de GRUPO sin choque de docente**: dos profesores distintos metiendo
     * clase al mismo curso a la misma hora.
     *
     * La contraria de la de arriba, y la que demuestra que las dos se comprueban
     * por separado y no una a costa de la otra.
     */
    #[Test]
    public function dos_docentes_en_el_mismo_grupo_a_la_misma_hora_es_choque_de_grupo(): void
    {
        $a = $this->asignaturasDe((int) $this->anio()->id);
        [$uno, $otro] = $this->dosProfesores();

        $r = $this->subir([
            $this->pieza('mates', 2, 6, 1, [(int) $a[0]->id], [$uno]),
            $this->pieza('ingles', 2, 6, 1, [(int) $a[1]->id], [$otro]),
        ]);

        $r->assertStatus(422);
        $r->assertJsonPath('motivo', 'choque');

        $this->assertNotEmpty($r->json('choques_de_grupo'),
            'Dos piezas del mismo grupo en la misma casilla no salieron como choque.');
        $this->assertEmpty($r->json('choques_de_docente'),
            'Salió también choque de docente: son dos profesores distintos, así que el '.
            'escenario no está separando las dos comprobaciones.');

        $this->assertNadaEscrito('choque de grupo');
    }

    // ─────────────────────────────────────────── Σ contra IH: dura y blanda

    /**
     * **Σ ≤ IH es la dura**, y suma `duracion` en vez de contar filas.
     *
     * Gastar más horas de las que la asignación tiene es imposible en cualquier
     * lectura, y eso sí es un fichero mal armado. Se construye con la asignatura de
     * IH más baja del año y un bloque más largo que ella: contando **filas** esto
     * sería 1 y pasaría.
     */
    #[Test]
    public function gastar_mas_horas_de_las_que_tiene_la_asignatura_es_422(): void
    {
        $a = $this->asignaturasDe((int) $this->anio()->id);

        usort($a, fn ($x, $y) => (int) $x->creditos <=> (int) $y->creditos);
        $flaca = $a[0];

        $r = $this->subir([
            $this->pieza('gorda', 4, 1, ((int) $flaca->creditos) + 1, [(int) $flaca->id]),
        ]);

        $r->assertStatus(422);
        $r->assertJsonPath('motivo', 'suma-mayor-que-la-ih');

        $this->assertGreaterThan(0, $r->json('asignaciones_revisadas'),
            'El rechazo no dice su población: sin ella «hay asignaciones pasadas» no distingue '.
            'haberlas revisado todas de haberse rendido en la primera.');

        $this->assertNadaEscrito('Σ mayor que la IH');
    }

    /**
     * **Σ = IH es BLANDA: una versión a medias entra, y el veredicto la cuenta.**
     *
     * Es la corrección del 2 sep 2026, y la trajo el único dato real que existe: de
     * las 313 piezas de `lleno.myvch` viajan **312**, o sea que hay una asignación
     * que gasta 2 de sus 3 horas. Con la igualdad como regla dura, **el servidor
     * rechazaría el único fichero de verdad que hay**. Una versión a medias es
     * legítima: es justo para lo que existen las versiones.
     *
     * Y lo que hace útil el renglón es la cuenta: sin ella «incompleta» se lee como
     * «rota»; con ella el coordinador ve lo que le falta y decide si publica igual.
     */
    #[Test]
    public function una_version_a_medias_entra_y_el_veredicto_dice_cuantas_faltan(): void
    {
        $anio = $this->anio();
        $a = $this->asignaturasDe((int) $anio->id);

        // Una sola hora de una asignatura que tiene más de una.
        usort($a, fn ($x, $y) => (int) $y->creditos <=> (int) $x->creditos);
        $gorda = $a[0];

        $this->assertGreaterThan(1, (int) $gorda->creditos,
            'Ninguna asignatura del año tiene IH mayor que 1: este caso no ejercería la desigualdad.');

        $r = $this->subir([$this->pieza('a1-0', 5, 1, 1, [(int) $gorda->id])]);

        $r->assertStatus(201);

        $renglon = $r->json('comprobaciones.renglones.suma_igual_que_la_ih');

        $this->assertGreaterThan(0, $renglon['incompletas'],
            'Colocada una hora de una asignatura de varias, el veredicto no cuenta ninguna incompleta.');
        $this->assertSame(count($a), $renglon['de'],
            'El renglón no cuenta TODAS las asignaciones vivas del año. Una asignatura que la '.
            'versión no menciona gasta cero horas de su IH, y callarlo es el `[]` que se lee '.
            'como «todo bien».');
        $this->assertNotEmpty($renglon['cuales'], 'Se cuentan las incompletas pero no se nombra ninguna.');
    }

    /**
     * La población del veredicto **sale de esa corrida, no del código**.
     *
     * 345 y 134 son cifras de `simonbolivar`; escritas a mano dirían 345 en el
     * colegio catorce habiendo mirado 200, que es exactamente la mentira que la
     * opción B existe para impedir.
     */
    #[Test]
    public function el_veredicto_lleva_su_poblacion_y_nombra_lo_que_no_comprobo(): void
    {
        $a = $this->asignaturasDe((int) $this->anio()->id);

        $r = $this->subir([
            $this->pieza('a1-0', 6, 1, 2, [(int) $a[0]->id, (int) $a[1]->id], [$this->unProfesor()]),
        ]);

        $r->assertStatus(201);

        $p = $r->json('comprobaciones.poblacion');

        $this->assertSame(1, $p['piezas']);
        $this->assertSame(2, $p['casillas'], 'Una pieza de duración 2 son DOS casillas.');
        $this->assertSame(2, $p['lecciones'], 'Una pieza con dos asignaciones son DOS lecciones.');
        $this->assertSame(count($a), $p['asignaciones_vivas_del_anio']);

        // Y las tres que el servidor NO puede comprobar van nombradas, no calladas:
        // un `if` contra un dato que no se tiene no falla nunca —pasa siempre, se ve
        // verde y no comprueba nada—, y aceptar con un «validado» encima de un
        // horario ilegal es más caro que no comprobar.
        foreach (['salon', 'disponibilidad', 'jornada'] as $regla) {
            $this->assertStringContainsString('NO COMPROBAD',
                (string) $r->json("comprobaciones.no_comprobadas.{$regla}"),
                "El veredicto no dice que `{$regla}` se quedó sin comprobar.");
        }
    }

    /**
     * **`nombre_colegio` es BLANDO y nunca puerta cerrada.**
     *
     * No es una identidad sino texto libre, editable desde configuración y distinto
     * por año: un colegio que se renombró legítimamente entre el import y la subida
     * **no se puede quedar sin poder subir su horario**. Va al veredicto y ya.
     */
    #[Test]
    public function un_nombre_de_colegio_distinto_no_bloquea_pero_sale_en_el_veredicto(): void
    {
        $r = $this->subir([], ['nombre_colegio' => 'COLEGIO QUE SE RENOMBRÓ EL MARTES']);

        $r->assertStatus(201);
        $r->assertJsonPath('comprobaciones.renglones.nombre_del_colegio.coincide', false);
        $r->assertJsonPath('comprobaciones.renglones.nombre_del_colegio.del_cuerpo', 'COLEGIO QUE SE RENOMBRÓ EL MARTES');
    }

    /**
     * **Una asignatura sin IH no es 422: va al veredicto nombrada y contada.**
     *
     * `asignaturas.creditos` es `int DEFAULT NULL`, y la trampa es fina en las dos
     * direcciones: `SUM(...) = creditos` con un `NULL` dentro **no da falso, se cae
     * del resultado**, y en PHP el `==` acusa a quien no tiene culpa. Un 422
     * convertiría un dato incompleto del colegio en un módulo inutilizable.
     *
     * **El seed no tiene ninguna** —0 de 1219 en el colegio real, tampoco—, así que
     * la IH se vacía aquí dentro y la transacción del test lo deshace. Un verde sin
     * esto no diría nada de este camino.
     */
    #[Test]
    public function una_asignatura_sin_ih_no_bloquea_y_se_cuenta_como_no_comprobada(): void
    {
        $a = $this->asignaturasDe((int) $this->anio()->id);

        $this->assertNotNull($a[0]->creditos,
            'La asignatura elegida ya tiene la IH nula: este test la vacía a propósito y '.
            'necesita partir de una que la tenga.');

        DB::update('UPDATE asignaturas SET creditos = NULL WHERE id = ?', [(int) $a[0]->id]);

        $r = $this->subir([$this->pieza('a1-0', 1, 1, 1, [(int) $a[0]->id])]);

        $r->assertStatus(201);

        $sinIh = $r->json('comprobaciones.no_comprobadas.asignaciones_sin_ih');

        $this->assertSame(1, $sinIh['cuantas'],
            'La asignatura con la IH vacía no salió contada como no comprobada.');
        $this->assertSame(count($a), $sinIh['de'], 'El renglón no dice sobre cuántas se miró.');
        $this->assertNotEmpty($sinIh['cuales'], 'Se cuenta pero no se nombra.');

        // Y la dura se declara sobre las que SÍ tienen IH, no sobre todas.
        $this->assertStringContainsString(count($a) - 1 .' asignación',
            (string) $r->json('comprobaciones.comprobadas.suma_menor_o_igual_que_la_ih'),
            'Σ ≤ IH dice haberse comprobado sobre asignaciones que no tienen IH contra la que comparar.');
    }

    /**
     * **Todos los 422 de esta familia traen `motivo`, incluido el de forma.**
     *
     * Lo midió `myvc-horarios-83` contra el docker y era falso: los seis rechazos de
     * dominio lo traían y el de `Request::validate` **no** —salía con `errors` y un
     * `message` de `validation.required (and 6 more errors)`—, así que una pantalla que
     * diera `motivo` por seguro se rompía justo en el caso más tonto, el del cuerpo mal
     * formado. Es el único 422 de la familia que no lo escribe una línea nuestra, y por
     * eso es el que se escapa.
     *
     * **`errors` se comprueba también**: el arreglo es aditivo, y un cliente que ya lo
     * lea no puede perderlo.
     */
    #[Test]
    public function el_422_de_forma_tambien_trae_motivo(): void
    {
        $respuesta = $this->postJson('/api/horario/versiones', [], [
            'Authorization' => 'Bearer '.$this->tokenDe($this->usuarioDeTipo('Usuario')->username),
        ]);

        $respuesta->assertStatus(422);
        $respuesta->assertJsonPath('motivo', 'cuerpo-mal-formado');

        $this->assertNotEmpty($respuesta->json('errors'),
            '`errors` tiene que seguir viajando: el arreglo era añadir `motivo`, no '
            .'sustituir lo que ya leía alguien.');
    }
}
