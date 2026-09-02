<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * La familia `rubricas/` — el contrato de
 * [26-rubricas.md](../../docs/migracion/26-rubricas.md), §4.
 *
 * Lo que se mira es **lo que queda escrito** —cuántas filas hay en las cinco
 * tablas antes y después, y qué vale `notas.nota`— y no el 200, que es la regla
 * que ha encontrado todo lo que se ha encontrado en este repositorio.
 *
 * Tres cosas quedan fijadas aquí por un test y no por buena voluntad, que es lo
 * que pidió el coordinador del carril el 2 sep 2026:
 *
 *   1. **los permisos de cada ruta**: las diez exigen token y personal, y las
 *      dos de valorar exigen además lo mismo que `notas/update`;
 *   2. **la suma de pesos que no da 100 se devuelve tal cual**, no se corrige;
 *   3. **la clave única lleva `momento` dentro**: calificar la nivelación con la
 *      misma rúbrica NO pisa la valoración original. Es lo que sostiene que la
 *      constancia de desempeño pueda imprimir el antes y el después.
 *
 * Y la cuarta, que es la propiedad de la §1 entera: **ninguna llamada de esta
 * familia cambia `notas.nota`**. Se comprueba en cada test que califica.
 */
class RubricasTest extends CasoDeContrato
{
    /**
     * Una subunidad del año actual, de una unidad del grupo, con notas sembradas.
     *
     * Se elige la que más filas de `notas` tiene, para que el lote tenga dos
     * alumnos con `nota_id`. Del año **actual** porque el token del personal
     * trabaja sobre `$user->year_id`, y una rúbrica de otro año es 403.
     *
     * @return array{subunidad: int, unidad: int, asignatura: int, grupo: int, periodo: int, year: int, token: string}
     */
    private function escenario(): array
    {
        $s = DB::selectOne(
            'SELECT s.id AS subunidad_id, u.id AS unidad_id, u.asignatura_id, a.grupo_id, u.periodo_id, p.year_id,
                    (SELECT COUNT(*) FROM notas n WHERE n.subunidad_id = s.id AND n.deleted_at IS NULL) AS notas
               FROM subunidades s
              INNER JOIN unidades u ON u.id = s.unidad_id AND u.deleted_at IS NULL AND u.alumno_id IS NULL
              INNER JOIN asignaturas a ON a.id = u.asignatura_id AND a.deleted_at IS NULL
              INNER JOIN periodos p ON p.id = u.periodo_id AND p.deleted_at IS NULL
              INNER JOIN years y ON y.id = p.year_id AND y.actual = 1
              WHERE s.deleted_at IS NULL
              ORDER BY notas DESC, s.id
              LIMIT 1'
        );

        $this->assertNotNull($s, 'El seed no tiene ninguna subunidad del año actual.');
        $this->assertGreaterThanOrEqual(2, (int) $s->notas,
            'La subunidad elegida tiene menos de dos notas sembradas: el lote no se puede montar.');

        return [
            'subunidad' => (int) $s->subunidad_id,
            'unidad' => (int) $s->unidad_id,
            'asignatura' => (int) $s->asignatura_id,
            'grupo' => (int) $s->grupo_id,
            'periodo' => (int) $s->periodo_id,
            'year' => (int) $s->year_id,
            'token' => $this->tokenDelPersonalDe((int) $s->year_id),
        ];
    }

    /**
     * El cuerpo de §4.4: tres criterios (40/30/30), cuatro niveles y dos
     * descriptores, uno de ellos **vacío** — que no debe crear fila.
     *
     * @return array<string, mixed>
     */
    private function cuerpo(int $asignaturaId, array $pesos = [40, 30, 30]): array
    {
        return [
            'nombre' => 'Ensayo argumentativo',
            'descripcion' => 'La del test',
            'asignatura_id' => $asignaturaId,
            'es_plantilla' => false,
            'criterios' => [
                ['definicion' => 'Argumentación', 'peso' => $pesos[0]],
                ['definicion' => 'Ortografía', 'peso' => $pesos[1]],
                ['definicion' => 'Estructura', 'peso' => $pesos[2]],
            ],
            'niveles' => [
                ['nombre' => 'SUPERIOR', 'puntaje' => 96, 'orden' => 5],
                ['nombre' => 'ALTO', 'puntaje' => 85, 'orden' => 4],
                ['nombre' => 'BÁSICO', 'puntaje' => 75, 'orden' => 3],
                ['nombre' => 'BAJO', 'puntaje' => 35, 'orden' => 2],
            ],
            'descriptores' => [
                ['fila' => 0, 'columna' => 0, 'texto' => 'Sostiene una tesis con tres argumentos.'],
                ['fila' => 2, 'columna' => 3, 'texto' => ''],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function crear(array $e, array $pesos = [40, 30, 30]): array
    {
        $r = $this->postJson('/api/rubricas', $this->cuerpo($e['asignatura'], $pesos), $this->cabeceras($e['token']));
        $r->assertStatus(201);

        return $r->json();
    }

    /** @return array<string, string> */
    private function cabeceras(string $token): array
    {
        return ['Authorization' => 'Bearer '.$token];
    }

    private function enlazar(array $e, ?int $rubricaId): void
    {
        $this->putJson("/api/rubricas/subunidad/{$e['subunidad']}", ['rubrica_id' => $rubricaId], $this->cabeceras($e['token']))
            ->assertStatus(200)
            ->assertJson(['subunidad_id' => $e['subunidad'], 'rubrica_id' => $rubricaId]);
    }

    /**
     * Los dos primeros alumnos de `calificar` que ya tienen fila en `notas`.
     *
     * @return list<array{alumno_id: int, nota_id: int, nota: int}>
     */
    private function alumnosConNota(array $e): array
    {
        $r = $this->getJson("/api/rubricas/calificar/{$e['subunidad']}", $this->cabeceras($e['token']));
        $r->assertStatus(200);

        $conNota = array_values(array_filter($r->json('alumnos'), fn ($a) => $a['nota_id'] !== null));

        $this->assertGreaterThanOrEqual(2, count($conNota), 'Hacen falta dos alumnos con nota sembrada.');

        return array_slice($conNota, 0, 2);
    }

    private function notaEnLaBase(int $notaId): int
    {
        return (int) DB::selectOne('SELECT n.nota FROM notas n WHERE n.id = ?', [$notaId])->nota;
    }

    private function marcas(int $notaId, ?string $momento = null): int
    {
        $sql = 'SELECT COUNT(*) AS n FROM rubrica_valoraciones v WHERE v.nota_id = ?';
        $parametros = [$notaId];

        if ($momento !== null) {
            $sql .= ' AND v.momento = ?';
            $parametros[] = $momento;
        }

        return (int) DB::selectOne($sql, $parametros)->n;
    }

    // ------------------------------------------------------------------
    // 1. Los permisos
    // ------------------------------------------------------------------

    /** @return list<array{0: string, 1: string}> */
    private static function lasDiezRutas(): array
    {
        return [
            ['GET', '/api/rubricas'],
            ['GET', '/api/rubricas/niveles-de-la-escala'],
            ['GET', '/api/rubricas/1'],
            ['POST', '/api/rubricas'],
            ['PUT', '/api/rubricas/1'],
            ['DELETE', '/api/rubricas/1'],
            ['PUT', '/api/rubricas/subunidad/1'],
            ['GET', '/api/rubricas/calificar/1'],
            ['PUT', '/api/rubricas/valorar/1'],
            ['PUT', '/api/rubricas/valorar-lote'],
        ];
    }

    public function test_las_diez_rutas_exigen_token(): void
    {
        foreach (self::lasDiezRutas() as [$verbo, $uri]) {
            $this->json($verbo, $uri)->assertStatus(401);
        }
    }

    /**
     * Un alumno recibe 403 en las diez **antes** de que el método mire nada: con
     * `/1` como id, un 404 aquí significaría que el guard no está y el método
     * llegó a preguntarle a la base.
     */
    public function test_un_alumno_recibe_403_en_las_diez(): void
    {
        $alumno = $this->usuarioDeTipo('Alumno');
        $cabeceras = $this->cabeceras($this->tokenDe($alumno->username));

        foreach (self::lasDiezRutas() as [$verbo, $uri]) {
            $this->json($verbo, $uri, [], $cabeceras)->assertStatus(403);
        }
    }

    /**
     * Valorar es más estrecho que leer: pide lo mismo que `notas/update`. El
     * personal llano sin superusuario **lee** la familia y **no** puede marcar,
     * que es exactamente lo que le pasa hoy en la planilla.
     */
    public function test_valorar_pide_lo_mismo_que_notas_update(): void
    {
        $e = $this->escenario();
        $rubrica = $this->crear($e);
        $this->enlazar($e, $rubrica['id']);
        [$alumno] = $this->alumnosConNota($e);

        $llano = $this->cabeceras($this->tokenDelPersonalLlanoDe($e['year']));

        $this->getJson("/api/rubricas/{$rubrica['id']}", $llano)->assertStatus(200);

        $this->putJson("/api/rubricas/valorar/{$alumno['nota_id']}", [
            'valoraciones' => [['criterio_id' => $rubrica['criterios'][0]['id'], 'nivel_id' => $rubrica['niveles'][0]['id']]],
        ], $llano)->assertStatus(403);

        $this->assertSame(0, $this->marcas($alumno['nota_id']), 'El 403 dejó una marca escrita.');
    }

    /**
     * La rúbrica de otro año se **inserta a mano** y no se crea con el token de
     * ese año: `Services\Login` reescribe `users.periodo_id` al periodo actual en
     * cada inicio de sesión, así que `tokenDelPersonalDe(7)` trabaja sobre el año
     * 8 igual, y el test pasaba en verde comparando el mismo año consigo mismo.
     */
    public function test_una_rubrica_de_otro_anio_es_403_y_no_404(): void
    {
        $e = $this->escenario();
        $cabeceras = $this->cabeceras($e['token']);

        $otroAnio = DB::selectOne('SELECT y.id FROM years y WHERE y.id <> ? AND y.deleted_at IS NULL ORDER BY y.id DESC LIMIT 1', [$e['year']]);
        $this->assertNotNull($otroAnio, 'El seed sólo tiene un año.');

        DB::insert('INSERT INTO rubricas (year_id, nombre, created_at, updated_at) VALUES (?, ?, NOW(), NOW())', [$otroAnio->id, 'De otro año']);
        $ajena = (int) DB::getPdo()->lastInsertId();

        $this->getJson("/api/rubricas/{$ajena}", $cabeceras)->assertStatus(403);
        $this->putJson("/api/rubricas/{$ajena}", $this->cuerpo($e['asignatura']), $cabeceras)->assertStatus(403);
        $this->deleteJson("/api/rubricas/{$ajena}", [], $cabeceras)->assertStatus(403);
        $this->getJson('/api/rubricas/999999999', $cabeceras)->assertStatus(404);

        // Y no sale en la lista del año del token.
        $ids = array_column($this->getJson('/api/rubricas', $cabeceras)->assertStatus(200)->json(), 'id');
        $this->assertNotContains($ajena, $ids);
    }

    // ------------------------------------------------------------------
    // 2. La matriz
    // ------------------------------------------------------------------

    public function test_crear_deja_las_filas_que_dice_y_ninguna_celda_vacia(): void
    {
        $e = $this->escenario();
        $rubrica = $this->crear($e);

        $this->assertSame($e['year'], $rubrica['year_id']);
        $this->assertSame($e['asignatura'], $rubrica['asignatura_id']);
        $this->assertSame(0, $rubrica['es_plantilla']);
        $this->assertSame(100, $rubrica['suma_pesos']);
        $this->assertSame(['Argumentación', 'Ortografía', 'Estructura'], array_column($rubrica['criterios'], 'definicion'));
        // El mejor a la izquierda, como en la escala.
        $this->assertSame(['SUPERIOR', 'ALTO', 'BÁSICO', 'BAJO'], array_column($rubrica['niveles'], 'nombre'));
        $this->assertCount(1, $rubrica['descriptores'], 'La celda con texto vacío creó una fila.');
        $this->assertSame([], $rubrica['subunidades_que_la_usan']);

        $id = $rubrica['id'];
        $this->assertSame(3, (int) DB::selectOne('SELECT COUNT(*) AS n FROM rubrica_criterios WHERE rubrica_id = ?', [$id])->n);
        $this->assertSame(4, (int) DB::selectOne('SELECT COUNT(*) AS n FROM rubrica_niveles WHERE rubrica_id = ?', [$id])->n);
        $this->assertSame(1, (int) DB::selectOne(
            'SELECT COUNT(*) AS n FROM rubrica_descriptores d INNER JOIN rubrica_criterios c ON c.id = d.criterio_id WHERE c.rubrica_id = ?', [$id]
        )->n);

        // Y la lectura suelta dice lo mismo que la creación.
        $this->getJson("/api/rubricas/{$id}", $this->cabeceras($e['token']))
            ->assertStatus(200)
            ->assertJson($rubrica);

        $lista = $this->getJson("/api/rubricas?asignatura_id={$e['asignatura']}", $this->cabeceras($e['token']))
            ->assertStatus(200)
            ->json();

        $mia = array_values(array_filter($lista, fn ($r) => $r['id'] === $id));
        $this->assertCount(1, $mia);
        $this->assertSame(['criterios' => 3, 'niveles' => 4, 'suma_pesos' => 100, 'subunidades_que_la_usan' => 0],
            array_intersect_key($mia[0], array_flip(['criterios', 'niveles', 'suma_pesos', 'subunidades_que_la_usan'])));
    }

    /** La lección de la §9.3 del 10: se avisa, no se corrige por detrás. */
    public function test_la_suma_de_pesos_que_no_da_100_se_devuelve_tal_cual(): void
    {
        $e = $this->escenario();
        $rubrica = $this->crear($e, [40, 30, 20]);

        $this->assertSame(90, $rubrica['suma_pesos']);
        $this->assertSame([40, 30, 20], array_column($rubrica['criterios'], 'peso'));
        $this->assertSame(90, (int) DB::selectOne('SELECT SUM(peso) AS s FROM rubrica_criterios WHERE rubrica_id = ?', [$rubrica['id']])->s);
    }

    public function test_guardar_la_matriz_actualiza_crea_y_borra_por_id(): void
    {
        $e = $this->escenario();
        $rubrica = $this->crear($e);
        [$c1, $c2, $c3] = $rubrica['criterios'];
        $niveles = $rubrica['niveles'];

        $cuerpo = [
            'nombre' => 'Ensayo argumentativo v2',
            'asignatura_id' => $e['asignatura'],
            'es_plantilla' => 'on',
            'criterios' => [
                ['id' => $c1['id'], 'definicion' => 'Argumentación', 'peso' => 50, 'orden' => 1],
                // $c2 no viene: se borra.
                ['id' => $c3['id'], 'definicion' => 'Estructura', 'peso' => 30, 'orden' => 2],
                ['definicion' => 'Puntualidad', 'peso' => 20, 'orden' => 3],
            ],
            'niveles' => array_map(fn ($n) => ['id' => $n['id'], 'nombre' => $n['nombre'], 'puntaje' => $n['puntaje'], 'orden' => $n['orden']], $niveles),
            'descriptores' => [
                ['fila' => 2, 'columna' => 1, 'texto' => 'Entregó a tiempo.'],
            ],
        ];

        $r = $this->putJson("/api/rubricas/{$rubrica['id']}", $cuerpo, $this->cabeceras($e['token']));
        $r->assertStatus(200);
        $guardada = $r->json();

        $this->assertSame('Ensayo argumentativo v2', $guardada['nombre']);
        $this->assertSame(1, $guardada['es_plantilla']);
        $this->assertSame(100, $guardada['suma_pesos']);
        $this->assertSame(['Argumentación', 'Estructura', 'Puntualidad'], array_column($guardada['criterios'], 'definicion'));
        $this->assertSame($c1['id'], $guardada['criterios'][0]['id'], 'El criterio con id se recreó en vez de actualizarse.');
        $this->assertSame(50, $guardada['criterios'][0]['peso']);
        $this->assertNull(DB::selectOne('SELECT id FROM rubrica_criterios WHERE id = ?', [$c2['id']]), 'El criterio ausente no se borró.');

        $nuevo = $guardada['criterios'][2]['id'];
        $this->assertSame(
            [['criterio_id' => $nuevo, 'nivel_id' => $niveles[1]['id'], 'texto' => 'Entregó a tiempo.']],
            $guardada['descriptores'],
            'Los descriptores no se reescribieron enteros.'
        );
    }

    public function test_crear_rechaza_filas_con_id_y_descriptores_fuera_de_la_matriz(): void
    {
        $e = $this->escenario();
        $cabeceras = $this->cabeceras($e['token']);

        $conId = $this->cuerpo($e['asignatura']);
        $conId['criterios'][0]['id'] = 5;
        $this->postJson('/api/rubricas', $conId, $cabeceras)->assertStatus(422);

        $fuera = $this->cuerpo($e['asignatura']);
        $fuera['descriptores'][] = ['fila' => 3, 'columna' => 0, 'texto' => 'No hay fila 3'];
        $this->postJson('/api/rubricas', $fuera, $cabeceras)->assertStatus(422);

        $sinNombre = $this->cuerpo($e['asignatura']);
        unset($sinNombre['nombre']);
        $this->postJson('/api/rubricas', $sinNombre, $cabeceras)->assertStatus(422);

        $this->assertSame(0, (int) DB::selectOne('SELECT COUNT(*) AS n FROM rubricas WHERE year_id = ?', [$e['year']])->n,
            'Un 422 dejó una rúbrica escrita.');
    }

    // ------------------------------------------------------------------
    // 3. Enlazar, calificar, y notas.nota que no se mueve
    // ------------------------------------------------------------------

    public function test_calificar_guarda_las_marcas_calcula_la_nota_y_no_toca_notas(): void
    {
        $e = $this->escenario();
        $rubrica = $this->crear($e);
        $cabeceras = $this->cabeceras($e['token']);
        [$c1, $c2, $c3] = array_column($rubrica['criterios'], 'id');
        [$superior, $alto, $basico] = array_column($rubrica['niveles'], 'id');

        // Sin rúbrica enlazada: la lectura contesta `rubrica: null` y valorar 422.
        $sinRubrica = $this->getJson("/api/rubricas/calificar/{$e['subunidad']}", $cabeceras)->assertStatus(200)->json();
        $this->assertNull($sinRubrica['rubrica']);
        $this->assertSame($e['subunidad'], $sinRubrica['subunidad']['id']);
        $this->assertSame($e['grupo'], $sinRubrica['subunidad']['grupo_id']);

        $this->enlazar($e, $rubrica['id']);
        $this->assertSame($rubrica['id'], (int) DB::selectOne('SELECT rubrica_id FROM subunidades WHERE id = ?', [$e['subunidad']])->rubrica_id);

        [$alumno] = $this->alumnosConNota($e);
        $notaId = $alumno['nota_id'];
        $notaAntes = $this->notaEnLaBase($notaId);
        $this->assertSame($notaAntes, $alumno['nota'], 'La lectura no enseña la nota vigente.');

        // Dos de tres: no hay nota todavía.
        $parcial = $this->putJson("/api/rubricas/valorar/{$notaId}", [
            'valoraciones' => [
                ['criterio_id' => $c1, 'nivel_id' => $alto, 'comentario' => 'Falta la conclusión'],
                ['criterio_id' => $c2, 'nivel_id' => $superior],
            ],
        ], $cabeceras)->assertStatus(200)->json();

        $this->assertFalse($parcial['completa']);
        $this->assertNull($parcial['nota_calculada']);
        $this->assertSame(2, $this->marcas($notaId));
        $this->assertCount(3, $parcial['desglose'], 'El desglose no lleva todos los criterios.');
        $this->assertNull($parcial['desglose'][2]['nivel_id']);

        // La tercera, sola: lo que no viene se conserva.
        $completa = $this->putJson("/api/rubricas/valorar/{$notaId}", [
            'valoraciones' => [['criterio_id' => $c3, 'nivel_id' => $basico]],
        ], $cabeceras)->assertStatus(200)->json();

        $this->assertTrue($completa['completa']);
        // 40 % × 85 + 30 % × 96 + 30 % × 75 = 34 + 28,8 + 22,5 = 85,3 → 85
        $this->assertSame(85, $completa['nota_calculada']);
        $this->assertSame(100, $completa['suma_pesos']);
        // `assertEquals` y no `assertSame`: 34,0 viaja como `34` en JSON.
        $this->assertEquals([34.0, 28.8, 22.5], array_column($completa['desglose'], 'aporte'));
        $this->assertSame(3, $this->marcas($notaId));

        // La lectura del grupo devuelve las tres marcas de ese alumno.
        $leida = $this->getJson("/api/rubricas/calificar/{$e['subunidad']}", $cabeceras)->assertStatus(200)->json();
        $mio = array_values(array_filter($leida['alumnos'], fn ($a) => $a['nota_id'] === $notaId))[0];
        $this->assertSame([$c1, $c2, $c3], array_column($mio['valoraciones'], 'criterio_id'));
        $this->assertSame('Falta la conclusión', $mio['valoraciones'][0]['comentario']);

        // `nivel_id: null` borra la marca.
        $quitada = $this->putJson("/api/rubricas/valorar/{$notaId}", [
            'valoraciones' => [['criterio_id' => $c1, 'nivel_id' => null]],
        ], $cabeceras)->assertStatus(200)->json();

        $this->assertFalse($quitada['completa']);
        $this->assertSame(2, $this->marcas($notaId));

        // Desenlazar no borra las marcas, y la lectura deja de enseñarlas.
        $this->enlazar($e, null);
        $this->assertSame(2, $this->marcas($notaId));

        // Y en todo esto `notas.nota` no se movió.
        $this->assertSame($notaAntes, $this->notaEnLaBase($notaId), 'Alguna llamada de rubricas/ escribió notas.nota.');
    }

    /**
     * La que sostiene la constancia de desempeño con el antes y el después: la
     * valoración de la nivelación **no pisa** la original. Es la clave única con
     * `momento` dentro, y por eso `momento` nació con la tabla.
     */
    public function test_nivelar_con_la_misma_rubrica_no_pisa_la_valoracion_original(): void
    {
        $e = $this->escenario();
        $rubrica = $this->crear($e);
        $cabeceras = $this->cabeceras($e['token']);
        $this->enlazar($e, $rubrica['id']);
        [$alumno] = $this->alumnosConNota($e);
        $notaId = $alumno['nota_id'];
        $notaAntes = $this->notaEnLaBase($notaId);
        $criterios = array_column($rubrica['criterios'], 'id');
        [$superior, , , $bajo] = array_column($rubrica['niveles'], 'id');

        $todoBajo = fn ($nivel) => ['valoraciones' => array_map(fn ($c) => ['criterio_id' => $c, 'nivel_id' => $nivel], $criterios)];

        $original = $this->putJson("/api/rubricas/valorar/{$notaId}", $todoBajo($bajo), $cabeceras)->assertStatus(200)->json();
        $this->assertSame(35, $original['nota_calculada']);

        $nivelacion = $this->putJson("/api/rubricas/valorar/{$notaId}", ['momento' => 'nivelacion'] + $todoBajo($superior), $cabeceras)
            ->assertStatus(200)->json();
        $this->assertSame('nivelacion', $nivelacion['momento']);
        $this->assertSame(96, $nivelacion['nota_calculada']);

        $this->assertSame(3, $this->marcas($notaId, 'original'));
        $this->assertSame(3, $this->marcas($notaId, 'nivelacion'));
        $this->assertSame(6, $this->marcas($notaId));

        // Cada lectura enseña su momento, y la original sigue diciendo BAJO.
        $leidaOriginal = $this->getJson("/api/rubricas/calificar/{$e['subunidad']}", $cabeceras)->json();
        $leidaNivelacion = $this->getJson("/api/rubricas/calificar/{$e['subunidad']}?momento=nivelacion", $cabeceras)->json();
        $de = fn ($lectura) => array_values(array_filter($lectura['alumnos'], fn ($a) => $a['nota_id'] === $notaId))[0]['valoraciones'];
        $this->assertSame([$bajo, $bajo, $bajo], array_column($de($leidaOriginal), 'nivel_id'));
        $this->assertSame([$superior, $superior, $superior], array_column($de($leidaNivelacion), 'nivel_id'));

        $this->putJson("/api/rubricas/valorar/{$notaId}", ['momento' => 'despues'] + $todoBajo($bajo), $cabeceras)->assertStatus(422);

        $this->assertSame($notaAntes, $this->notaEnLaBase($notaId));
    }

    public function test_no_se_quita_un_criterio_que_ya_se_uso_y_nada_se_escribe(): void
    {
        $e = $this->escenario();
        $rubrica = $this->crear($e);
        $cabeceras = $this->cabeceras($e['token']);
        $this->enlazar($e, $rubrica['id']);
        [$alumno] = $this->alumnosConNota($e);
        [$c1, $c2, $c3] = $rubrica['criterios'];

        $this->putJson("/api/rubricas/valorar/{$alumno['nota_id']}", [
            'valoraciones' => [['criterio_id' => $c2['id'], 'nivel_id' => $rubrica['niveles'][0]['id']]],
        ], $cabeceras)->assertStatus(200);

        $sinC2 = [
            'nombre' => 'Otro nombre',
            'criterios' => [
                ['id' => $c1['id'], 'definicion' => $c1['definicion'], 'peso' => 50],
                ['id' => $c3['id'], 'definicion' => $c3['definicion'], 'peso' => 50],
            ],
            'niveles' => array_map(fn ($n) => ['id' => $n['id'], 'nombre' => $n['nombre'], 'puntaje' => $n['puntaje']], $rubrica['niveles']),
        ];

        $this->putJson("/api/rubricas/{$rubrica['id']}", $sinC2, $cabeceras)
            ->assertStatus(422)
            ->assertJson(['criterios_con_valoraciones' => [$c2['id']], 'niveles_con_valoraciones' => []]);

        $this->assertNotNull(DB::selectOne('SELECT id FROM rubrica_criterios WHERE id = ?', [$c2['id']]));
        $this->assertSame('Ensayo argumentativo', DB::selectOne('SELECT nombre FROM rubricas WHERE id = ?', [$rubrica['id']])->nombre,
            'El 422 escribió la cabecera antes de rechazar.');
        $this->assertSame(40, (int) DB::selectOne('SELECT peso FROM rubrica_criterios WHERE id = ?', [$c1['id']])->peso,
            'El 422 escribió un criterio antes de rechazar.');
    }

    public function test_no_se_borra_una_rubrica_en_uso(): void
    {
        $e = $this->escenario();
        $rubrica = $this->crear($e);
        $cabeceras = $this->cabeceras($e['token']);
        $this->enlazar($e, $rubrica['id']);

        $this->deleteJson("/api/rubricas/{$rubrica['id']}", [], $cabeceras)
            ->assertStatus(422)
            ->assertJsonPath('subunidades.0.id', $e['subunidad']);
        $this->assertNull(DB::selectOne('SELECT deleted_at FROM rubricas WHERE id = ?', [$rubrica['id']])->deleted_at);

        $this->enlazar($e, null);

        $this->deleteJson("/api/rubricas/{$rubrica['id']}", [], $cabeceras)->assertStatus(200);
        $this->assertNotNull(DB::selectOne('SELECT deleted_at FROM rubricas WHERE id = ?', [$rubrica['id']])->deleted_at);
        $this->getJson("/api/rubricas/{$rubrica['id']}", $cabeceras)->assertStatus(404);
        $this->putJson("/api/rubricas/subunidad/{$e['subunidad']}", ['rubrica_id' => $rubrica['id']], $cabeceras)->assertStatus(422);
    }

    public function test_el_lote_es_todo_o_nada(): void
    {
        $e = $this->escenario();
        $rubrica = $this->crear($e);
        $cabeceras = $this->cabeceras($e['token']);
        $this->enlazar($e, $rubrica['id']);
        [$a, $b] = $this->alumnosConNota($e);
        $criterios = array_column($rubrica['criterios'], 'id');
        $alto = $rubrica['niveles'][1]['id'];
        $antes = [$this->notaEnLaBase($a['nota_id']), $this->notaEnLaBase($b['nota_id'])];

        $todoAlto = array_map(fn ($c) => ['criterio_id' => $c, 'nivel_id' => $alto], $criterios);

        // La segunda fila trae un criterio de otra rúbrica: nada se escribe.
        $this->putJson('/api/rubricas/valorar-lote', ['notas' => [
            ['nota_id' => $a['nota_id'], 'valoraciones' => $todoAlto],
            ['nota_id' => $b['nota_id'], 'valoraciones' => [['criterio_id' => 999999999, 'nivel_id' => $alto]]],
        ]], $cabeceras)
            ->assertStatus(422)
            ->assertJson(['fila' => 1, 'nota_id' => $b['nota_id']]);

        $this->assertSame(0, $this->marcas($a['nota_id']), 'El lote rechazado escribió la primera fila.');
        $this->assertSame(0, $this->marcas($b['nota_id']));

        $r = $this->putJson('/api/rubricas/valorar-lote', ['notas' => [
            ['nota_id' => $a['nota_id'], 'valoraciones' => $todoAlto],
            ['nota_id' => $b['nota_id'], 'valoraciones' => $todoAlto],
        ]], $cabeceras)->assertStatus(200)->json();

        $this->assertSame([$a['nota_id'], $b['nota_id']], array_column($r['notas'], 'nota_id'));
        $this->assertSame([true, true], array_column($r['notas'], 'completa'));
        $this->assertSame([85, 85], array_column($r['notas'], 'nota_calculada'));
        $this->assertSame(3, $this->marcas($a['nota_id']));
        $this->assertSame(3, $this->marcas($b['nota_id']));

        $this->assertSame($antes, [$this->notaEnLaBase($a['nota_id']), $this->notaEnLaBase($b['nota_id'])],
            'El lote escribió notas.nota.');
    }

    // ------------------------------------------------------------------
    // 4. El sembrado
    // ------------------------------------------------------------------

    public function test_los_niveles_de_la_escala_son_el_punto_medio_y_no_escriben(): void
    {
        $e = $this->escenario();

        $escalas = DB::select(
            'SELECT desempenio, porc_inicial, porc_final, orden FROM escalas_de_valoracion
              WHERE year_id = ? AND deleted_at IS NULL ORDER BY orden DESC, id',
            [$e['year']]
        );
        $this->assertNotEmpty($escalas, 'El año actual del seed no tiene escalas.');

        $esperados = array_map(fn ($x) => [
            'nombre' => $x->desempenio,
            'puntaje' => (int) round(((int) $x->porc_inicial + (int) $x->porc_final) / 2),
            'orden' => (int) $x->orden,
        ], $escalas);

        $rubricasAntes = (int) DB::selectOne('SELECT COUNT(*) AS n FROM rubricas')->n;
        $nivelesAntes = (int) DB::selectOne('SELECT COUNT(*) AS n FROM rubrica_niveles')->n;

        $this->getJson('/api/rubricas/niveles-de-la-escala', $this->cabeceras($e['token']))
            ->assertStatus(200)
            ->assertExactJson($esperados);

        $this->assertSame($rubricasAntes, (int) DB::selectOne('SELECT COUNT(*) AS n FROM rubricas')->n);
        $this->assertSame($nivelesAntes, (int) DB::selectOne('SELECT COUNT(*) AS n FROM rubrica_niveles')->n);
    }
}
