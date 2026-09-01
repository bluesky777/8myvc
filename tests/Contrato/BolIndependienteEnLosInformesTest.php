<?php

namespace Tests\Contrato;

use App\Services\BoletinIndependiente;
use Illuminate\Support\Facades\DB;

/**
 * Los tres campos que el front pidió para las pantallas que **enseñan varios periodos
 * a la vez**. §7 de [la cola](../../docs/migracion/noche-2026-08-31/estado-de-la-cola.md),
 * fijados por el front y no negociables en la forma.
 *
 * | Pantalla | Campo | Forma |
 * |---|---|---|
 * | `definitivas_periodos` | `alumno.bol_independiente_aparte_en` | `[2, 3]` — los `numero` |
 * | `actas-evaluacion/acta-evaluacion-promocion` | `alumno.bol_independiente_aparte_en` | idem |
 * | `notas-perdidas/*` | `alumno.bol_independiente_periodo` | booleano |
 *
 * ## Por qué una lista de `numero` y no un booleano en las dos primeras
 *
 * Las dos enseñan **el año entero en cuatro columnas**. Un booleano aplanado no puede
 * decir **cuál** de las cuatro celdas es la rara, y ésa es justo la pregunta que se
 * hace el docente que ve la nota de un estudiante que no vio en su planilla —o un 0
 * sin explicación, que es la §9.1 hecha visible—.
 *
 * ## Y por qué el nombre es distinto del de la ficha
 *
 * `bol_independiente_periodos` (la ficha, §6.4) carga
 * `[{periodo_id, numero, aplica, tiene_datos}]`. Aquí no hace falta `tiene_datos`, así
 * que **el mismo nombre tendría dos formas según por dónde saliera** — una trampa que
 * este módulo ya ha pisado dos veces. Nombres distintos, formas distintas.
 *
 * ## Lo que estos tests comprueban y una lectura del código no puede
 *
 * Que el campo **varía dentro de la misma respuesta**: el marcado y su compañero sin
 * marcar, en la misma llamada. Un campo constante es uno sobre el que alguien
 * ramificará sin que su rama muerta se note nunca. Y que **el periodo importa**: se
 * marca el periodo N y se comprueba que el campo NO se enciende para el M — con el
 * año entero por respuesta los dos casos darían el mismo verde.
 */
class BolIndependienteEnLosInformesTest extends CasoDeContrato
{
    /** @var array{token: string, year_id: int, asignatura: int, profesor: int, p1: int, p2: int, n1: int, n2: int, a: int, b: int, minima: float} */
    private array $escenario;

    protected function setUp(): void
    {
        parent::setUp();

        BoletinIndependiente::olvidar();
    }

    /**
     * Los periodos del año, por `numero`.
     *
     * @return array<int, int> numero => periodo_id
     */
    private function periodosPorNumero(int $yearId): array
    {
        $filas = DB::select('SELECT id, numero FROM periodos
            WHERE year_id = ? AND deleted_at IS NULL ORDER BY numero', [$yearId]);

        $mapa = [];

        foreach ($filas as $fila) {
            $mapa[(int) $fila->numero] = (int) $fila->id;
        }

        return $mapa;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // notas-perdidas: `alumno.bol_independiente_periodo`
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Todos los `alumnos[]` de la lista de pérdidas, vengan de donde vengan.
     *
     * Busca en vez de navegar: la respuesta es profesores → grupos → asignaturas →
     * alumnos en `todos`, y grupos → asignaturas → alumnos en `profesor-grupos`. Lo
     * que se pregunta es si el dato **sale**, no por qué camino.
     *
     * @return array<int, bool> alumno_id => bol_independiente_periodo
     */
    private function perdidasPorAlumno(mixed $nodo): array
    {
        $encontrados = [];

        if (! is_array($nodo)) {
            return $encontrados;
        }

        if (isset($nodo['alumno_id']) && array_key_exists('bol_independiente_periodo', $nodo)) {
            $encontrados[(int) $nodo['alumno_id']] = (bool) $nodo['bol_independiente_periodo'];
        }

        foreach ($nodo as $hijo) {
            $encontrados += $this->perdidasPorAlumno($hijo);
        }

        return $encontrados;
    }

    /**
     * El caso construido: un grupo, una asignatura con profesor, dos periodos, dos
     * alumnos y las notas perdidas puestas a mano.
     *
     * **Hay que construirlo, y esto no es comodidad de test.** Marcar a un alumno del
     * seed y volver a pedir la lista **lo hace desaparecer**: desde la fase 1 la
     * consulta pide `u.alumno_id <=> ALCANCE`, y un independiente sin unidades propias
     * no empareja con ninguna. O sea que el escenario «marcado y sin estructura» no
     * llega a esta pantalla con el campo apagado: **no llega en absoluto** (ver la §3
     * de las notas del lote F). Para que el campo se pueda mirar, el marcado tiene que
     * tener unidades suyas — que es además el caso que el front va a pintar.
     *
     * @return array{token: string, year_id: int, asignatura: int, profesor: int, p1: int, p2: int, n1: int, n2: int, a: int, b: int, minima: float}
     */
    private function escenarioDePerdidas(): array
    {
        if (isset($this->escenario)) {
            return $this->escenario;
        }

        $grupo = $this->grupoConAlumnos();
        $token = $this->tokenDelPersonalDe((int) $grupo->year_id);

        $periodos = $this->periodosPorNumero((int) $grupo->year_id);
        $numeros = array_keys($periodos);

        $this->assertGreaterThanOrEqual(2, count($periodos),
            'El año de este grupo no tiene dos periodos: sin dos, «el periodo que se mira» no se '
            .'distingue de «el año entero», que es la mitad que este test existe para ver.');

        $asignatura = DB::selectOne('SELECT a.id, a.profesor_id FROM asignaturas a
            WHERE a.grupo_id = ? AND a.profesor_id IS NOT NULL AND a.deleted_at IS NULL
            ORDER BY a.id LIMIT 1', [$grupo->id]);

        $this->assertNotNull($asignatura,
            'El grupo del seed no tiene ninguna asignatura con profesor: `profesor-grupos` '
            .'contestaría `[]` en 200 y el test pasaría sin mirar nada.');

        $alumnos = DB::select('SELECT m.alumno_id FROM matriculas m
             WHERE m.grupo_id = ? AND m.deleted_at IS NULL AND m.estado IN ("MATR","ASIS","PREM")
             ORDER BY m.alumno_id LIMIT 2', [$grupo->id]);

        $this->assertCount(2, $alumnos,
            'Este test necesita DOS alumnos: uno marcado y un compañero sin marcar. Con uno solo '
            .'no se distingue «el campo vale true» de «el campo es constante».');

        $year = DB::selectOne('SELECT nota_minima_aceptada FROM years WHERE id = ?', [$grupo->year_id]);

        return $this->escenario = [
            'token' => $token,
            'year_id' => (int) $grupo->year_id,
            'asignatura' => (int) $asignatura->id,
            'profesor' => (int) $asignatura->profesor_id,
            'p1' => $periodos[$numeros[0]],
            'p2' => $periodos[$numeros[1]],
            'n1' => $numeros[0],
            'n2' => $numeros[1],
            'a' => (int) $alumnos[0]->alumno_id,
            'b' => (int) $alumnos[1]->alumno_id,
            'minima' => (float) $year->nota_minima_aceptada,
        ];
    }

    /**
     * Las unidades y las notas perdidas del periodo: **dos del grupo y una propia de A**.
     *
     * Los dos lados con números distintos a propósito (§1.4 del reparto): con un 1
     * contra un 1 una lista que trajera las contrarias sería indistinguible de la
     * correcta. Aquí lo que se mira es un booleano, pero el escenario tiene que
     * sobrevivir a que alguien lo reutilice para contar.
     */
    /** @param array{token: string, year_id: int, asignatura: int, profesor: int, p1: int, p2: int, n1: int, n2: int, a: int, b: int, minima: float}  $e */
    private function sembrarPerdidas(array $e, int $periodoId): void
    {
        $delGrupo = $this->unidadConSubunidades($e['asignatura'], $periodoId, null, 2);
        $propia = $this->unidadConSubunidades($e['asignatura'], $periodoId, $e['a'], 1);

        foreach ($delGrupo as $subunidad) {
            $this->perder($e, $subunidad, $e['a']);
            $this->perder($e, $subunidad, $e['b']);
        }

        $this->perder($e, $propia[0], $e['a']);
    }

    /**
     * Una unidad con `$cuantas` subunidades. `$alumnoId` NULL es una unidad del grupo.
     *
     * @return list<int> los ids de las subunidades creadas
     */
    private function unidadConSubunidades(int $asignaturaId, int $periodoId, ?int $alumnoId, int $cuantas): array
    {
        DB::insert(
            'INSERT INTO unidades (definicion, porcentaje, asignatura_id, periodo_id, alumno_id, orden, created_at, updated_at)
             VALUES (?, 100, ?, ?, ?, 1, NOW(), NOW())',
            [$alumnoId === null ? 'Del grupo' : 'Propia', $asignaturaId, $periodoId, $alumnoId]
        );

        $unidadId = (int) DB::getPdo()->lastInsertId();

        $subunidades = [];

        for ($i = 1; $i <= $cuantas; $i++) {
            DB::insert(
                'INSERT INTO subunidades (definicion, porcentaje, unidad_id, orden, created_at, updated_at)
                 VALUES (?, ?, ?, ?, NOW(), NOW())',
                ['Actividad '.$i, (int) (100 / $cuantas), $unidadId, $i]
            );

            $subunidades[] = (int) DB::getPdo()->lastInsertId();
        }

        return $subunidades;
    }

    /** Una nota perdida de ese alumno en esa subunidad. */
    /** @param array{token: string, year_id: int, asignatura: int, profesor: int, p1: int, p2: int, n1: int, n2: int, a: int, b: int, minima: float}  $e */
    private function perder(array $e, int $subunidadId, int $alumnoId): void
    {
        DB::insert(
            'INSERT INTO notas (nota, subunidad_id, alumno_id, created_at, updated_at)
             VALUES (?, ?, ?, NOW(), NOW())',
            [max(0, $e['minima'] - 1), $subunidadId, $alumnoId]
        );
    }

    public function test_las_perdidas_dicen_quien_va_por_boletin_aparte(): void
    {
        $e = $this->escenarioDePerdidas();

        $this->sembrarPerdidas($e, $e['p1']);
        $this->marcarIndependiente($e['a'], $e['p1']);

        $cuerpo = ['periodo_a_calcular' => $e['n1'], 'solo_periodo' => 1, 'profesor_id' => $e['profesor']];

        // Las dos rutas, y no una: son dos copias de la misma consulta y de la misma
        // pantalla. Si el campo lo emite una y la otra no, la que calla se lee como
        // «este alumno no va aparte».
        foreach (['api/notas-perdidas/todos', 'api/notas-perdidas/profesor-grupos'] as $ruta) {
            $r = $this->withToken($e['token'])->putJson('/'.$ruta, $cuerpo);

            $r->assertStatus(200);

            $visto = $this->perdidasPorAlumno($r->json());

            $this->assertArrayHasKey($e['a'], $visto,
                "El alumno marcado no está en {$ruta}. Si esto falla, mira primero si su unidad "
                .'propia se sembró: sin ella la fase 1 lo saca de la lista entera.');

            $this->assertTrue($visto[$e['a']],
                "{$ruta} no dice que el alumno marcado va por boletín aparte, así que la pantalla "
                .'lo sigue acusando de perderlo todo.');

            $this->assertArrayHasKey($e['b'], $visto,
                "El compañero sin marcar desapareció de {$ruta}: sin él el campo no se puede "
                .'distinguir de una constante.');

            $this->assertFalse($visto[$e['b']],
                "{$ruta} enciende el campo también para quien NO está marcado: es constante y no dice nada.");
        }
    }

    /**
     * **La mitad que se cae si el campo se resuelve contra el año y no contra el
     * informe.** Se marca un periodo y se pide el informe de otro con `solo_periodo`:
     * el campo tiene que seguir apagado.
     *
     * Sin esto, resolverlo con los cuatro periodos del año pasaría igual de verde — y
     * en una pantalla que acusa de perder asignaturas, explicar las pérdidas de un
     * periodo con una marca de otro es exactamente el error que este campo existe para
     * no cometer.
     */
    public function test_una_marca_de_otro_periodo_no_enciende_el_campo(): void
    {
        $e = $this->escenarioDePerdidas();

        // En el periodo 1 A va CON EL GRUPO, así que sigue en la lista por las del
        // grupo; lo marcado es el 2, que este informe no está mirando.
        $this->sembrarPerdidas($e, $e['p1']);
        $this->marcarIndependiente($e['a'], $e['p2']);

        $r = $this->withToken($e['token'])->putJson('/api/notas-perdidas/profesor-grupos', [
            'periodo_a_calcular' => $e['n1'],
            'solo_periodo' => 1,
            'profesor_id' => $e['profesor'],
        ]);

        $r->assertStatus(200);

        $visto = $this->perdidasPorAlumno($r->json());

        $this->assertArrayHasKey($e['a'], $visto, 'El alumno se cayó de la lista del periodo 1.');

        $this->assertFalse($visto[$e['a']],
            "Marcado en el periodo {$e['n2']} y encendido en el informe del {$e['n1']}: el campo se "
            .'está resolviendo contra el año entero y no contra los periodos que el informe lista.');
    }

    /**
     * Y la otra dirección de lo mismo: **sin `solo_periodo` el informe abarca
     * `numero <= N`**, así que una marca del periodo 1 SÍ tiene que encenderlo cuando
     * se pide hasta el 2. Es lo que impide «arreglar» el test de arriba dejando el
     * campo siempre apagado.
     */
    public function test_sin_solo_periodo_el_campo_abarca_los_periodos_que_el_informe_lista(): void
    {
        $e = $this->escenarioDePerdidas();

        $this->sembrarPerdidas($e, $e['p1']);
        $this->marcarIndependiente($e['a'], $e['p1']);

        $r = $this->withToken($e['token'])->putJson('/api/notas-perdidas/profesor-grupos', [
            'periodo_a_calcular' => $e['n2'],
            'profesor_id' => $e['profesor'],
        ]);

        $r->assertStatus(200);

        $visto = $this->perdidasPorAlumno($r->json());

        $this->assertArrayHasKey($e['a'], $visto, 'El alumno se cayó de la lista de `numero <= 2`.');

        $this->assertTrue($visto[$e['a']],
            'El informe abarca los periodos 1 y 2, el alumno va aparte en el 1 y el campo dice que '
            .'no. Las pérdidas que se están listando incluyen las de un boletín aparte y la '
            .'pantalla no puede decirlo.');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // definitivas_periodos: `alumno.bol_independiente_aparte_en`
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Un profesor con asignatura en un grupo con dos alumnos, y su token.
     *
     * **Tiene que ser un Profesor y no un Usuario.** `getIndex` saca el docente de
     * `$user->persona_id` cuando el tipo es `Profesor`, y de `Request::input()` cuando
     * es superusuario — y con un Usuario llano no saca ninguno: la respuesta sale `[]`
     * en 200 y el test pasaría sin haber mirado nada. Es la razón por la que
     * `api/definitivas_periodos` está en `lecturasVacias()` del muestreo.
     *
     * @return array{token: string, year_id: int, a: int, b: int}
     */
    private function escenarioDeLaRejilla(): array
    {
        $grupo = $this->grupoConAlumnos();

        $profesor = DB::selectOne('SELECT u.username FROM users u
            INNER JOIN profesores pr ON pr.user_id = u.id AND pr.deleted_at IS NULL
            INNER JOIN asignaturas a ON a.profesor_id = pr.id AND a.grupo_id = ? AND a.deleted_at IS NULL
            INNER JOIN periodos p ON p.id = u.periodo_id AND p.year_id = ? AND p.deleted_at IS NULL
            WHERE u.tipo = "Profesor" AND u.is_active = 1 AND u.deleted_at IS NULL
            ORDER BY u.id LIMIT 1', [$grupo->id, $grupo->year_id]);

        $this->assertNotNull($profesor,
            "El seed no tiene un Profesor con asignatura en el grupo {$grupo->id}: la rejilla "
            .'saldría vacía en 200 y el test no comprobaría nada.');

        $alumnos = DB::select('SELECT m.alumno_id FROM matriculas m
             WHERE m.grupo_id = ? AND m.deleted_at IS NULL AND m.estado IN ("MATR","ASIS")
             ORDER BY m.alumno_id LIMIT 2', [$grupo->id]);

        $this->assertCount(2, $alumnos,
            'Hacen falta dos alumnos: uno marcado y un compañero sin marcar. Con uno solo la '
            .'lista no se distingue de una constante.');

        return [
            'token' => $this->tokenDe($profesor->username),
            'year_id' => (int) $grupo->year_id,
            'a' => (int) $alumnos[0]->alumno_id,
            'b' => (int) $alumnos[1]->alumno_id,
        ];
    }

    /**
     * Los `bol_independiente_aparte_en` de la rejilla, por alumno.
     *
     * @return array<int, list<int>>
     */
    private function aparteEnPorAlumno(mixed $nodo): array
    {
        $encontrados = [];

        if (! is_array($nodo)) {
            return $encontrados;
        }

        if (isset($nodo['alumno_id']) && array_key_exists('bol_independiente_aparte_en', $nodo)) {
            $encontrados[(int) $nodo['alumno_id']] = $nodo['bol_independiente_aparte_en'];
        }

        foreach ($nodo as $hijo) {
            $encontrados += $this->aparteEnPorAlumno($hijo);
        }

        return $encontrados;
    }

    /**
     * La rejilla dice **en cuáles** de los cuatro periodos va aparte, no si va aparte.
     *
     * Se marcan **dos periodos y no uno**, y no es adorno: con un solo periodo marcado
     * una lista correcta y un booleano disfrazado de lista (`[periodo_del_token]`)
     * darían el mismo verde. Y se marcan el 2 y el 3 —ni el primero ni el último— para
     * que un `range()` o un `<=` tampoco acierten por casualidad.
     */
    public function test_la_rejilla_dice_en_que_periodos_va_aparte(): void
    {
        $e = $this->escenarioDeLaRejilla();
        $periodos = $this->periodosPorNumero($e['year_id']);

        $this->assertGreaterThanOrEqual(3, count($periodos),
            'El año tiene menos de tres periodos: no se puede marcar «el 2 y el 3» y el test '
            .'dejaría de distinguir una lista de un booleano.');

        $numeros = array_keys($periodos);
        $marcados = [$numeros[1], $numeros[2]];

        foreach ($marcados as $numero) {
            $this->marcarIndependiente($e['a'], $periodos[$numero]);
        }

        // Y uno apagado con la fila puesta: `aplica = 0` no es lo mismo que no tener
        // fila, y la lista tiene que dejarlo fuera igual.
        $this->marcarIndependiente($e['a'], $periodos[$numeros[0]], aplica: false);

        $r = $this->withToken($e['token'])->getJson('/api/definitivas_periodos');

        $r->assertStatus(200);

        $visto = $this->aparteEnPorAlumno($r->json());

        $this->assertNotSame([], $visto,
            'La rejilla salió sin ningún alumno con el campo: o la respuesta está vacía o el '
            .'campo no viaja.');

        $this->assertArrayHasKey($e['a'], $visto, 'El alumno marcado no está en la rejilla.');
        $this->assertArrayHasKey($e['b'], $visto, 'El compañero sin marcar no está en la rejilla.');

        $this->assertSame($marcados, $visto[$e['a']],
            'La rejilla no dice en cuáles de los cuatro periodos va aparte. Con las cuatro '
            .'columnas a la vez, un dato que no nombre el periodo no puede señalar la celda rara.');

        $this->assertSame([], $visto[$e['b']],
            'El compañero sin marcar trae periodos: el campo es constante y no dice nada. Y `[]` '
            .'y no `null` a propósito — una lista vacía se recorre igual que una llena.');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // actas-evaluacion: `alumno.bol_independiente_aparte_en`
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * El acta es de **todo el año**, así que dice en cuáles de los cuatro periodos.
     *
     * Decir «va aparte» sin decir en cuál no contesta nada — el mismo argumento por el
     * que este campo no se aplanó a un booleano en `definitivas_periodos`.
     *
     * **El acta NO lleva `asignatura.bol_independiente`** y este test no lo busca: su
     * respuesta son grupos con matrículas, resumen, promoción y periodos, sin una sola
     * asignatura por alumno. Emitirlo ahí no pintaría nada y no daría ningún error.
     * Decisión tomada por el front el 1 sep 2026.
     */
    public function test_el_acta_dice_en_que_periodos_del_anio_va_aparte(): void
    {
        [$grupo, $token] = $this->grupoYPersonal();

        $periodos = $this->periodosPorNumero((int) $grupo->year_id);

        $this->assertGreaterThanOrEqual(3, count($periodos),
            'El año tiene menos de tres periodos: no se puede marcar «el 2 y el 3» y el test '
            .'dejaría de distinguir una lista de un booleano.');

        $alumnos = DB::select('SELECT m.alumno_id FROM matriculas m
             WHERE m.grupo_id = ? AND m.deleted_at IS NULL ORDER BY m.alumno_id LIMIT 2',
            [$grupo->id]);

        $this->assertCount(2, $alumnos, 'Hacen falta dos alumnos en el grupo del acta.');

        $numeros = array_keys($periodos);
        $marcados = [$numeros[1], $numeros[2]];
        $a = (int) $alumnos[0]->alumno_id;
        $b = (int) $alumnos[1]->alumno_id;

        foreach ($marcados as $numero) {
            $this->marcarIndependiente($a, $periodos[$numero]);
        }

        $this->marcarIndependiente($a, $periodos[$numeros[0]], aplica: false);

        $r = $this->putJson('/api/actas-evaluacion/acta-evaluacion-promocion', [],
            ['Authorization' => 'Bearer '.$token]);

        $r->assertStatus(200);

        $visto = $this->aparteEnPorAlumno($r->json('grupos'));

        $this->assertArrayHasKey($a, $visto,
            'El alumno marcado no está en el acta con el campo: o no viaja, o el acta no lo lista.');

        $this->assertSame($marcados, $visto[$a],
            'El acta no dice en cuáles de los cuatro periodos va aparte. Es de todo el año: sin '
            .'el periodo, «va aparte» no contesta nada.');

        $this->assertArrayHasKey($b, $visto, 'El compañero sin marcar no está en el acta.');

        $this->assertSame([], $visto[$b],
            'El compañero sin marcar trae periodos: el campo es constante y no dice nada.');
    }

    /**
     * **Y el acta sigue SIN `asignatura.bol_independiente`**, que es la decisión que el
     * front corrigió el 1 sep 2026 y que este test fija para que no vuelva.
     *
     * No tiene dónde colgarlo —no hay ni una asignatura por alumno en su respuesta—, y
     * eso significa que si alguien lo emitiera **no pintaría nada y no daría ningún
     * error**: una rama muerta que nadie vería. Un test que lo busque es la única forma
     * de que ese intento se note.
     */
    public function test_el_acta_no_lleva_el_rotulo_de_asignatura(): void
    {
        [, $token] = $this->grupoYPersonal();

        $r = $this->putJson('/api/actas-evaluacion/acta-evaluacion-promocion', [],
            ['Authorization' => 'Bearer '.$token]);

        $r->assertStatus(200);

        $this->assertStringNotContainsString('"bol_independiente"', $r->getContent(),
            'El acta emitió `asignatura.bol_independiente`. No tiene dónde colgarlo —su respuesta '
            .'no lleva ni una asignatura por alumno—, así que no pintaría nada y tampoco daría un '
            .'error: una rama muerta. El campo que sí contesta algo aquí es '
            .'`bol_independiente_aparte_en`.');
    }
}
