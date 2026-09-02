<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * Los informes imprimen **el par**: la valoración del periodo y la de la nivelación.
 *
 * Es la tarea **A10** para los tres que no esperan ninguna decisión —boletín tipo 1 y 5,
 * boletín final del año y notas actuales del alumno—, según el §5 del
 * [27](../../docs/migracion/27-nivelaciones-en-los-informes.md). El certificado firmado y
 * cualquier cosa del puesto **no están aquí y es a propósito**: los dos esperan una
 * decisión de Joseth (27 §2.3 y §3.3), y escribir una de las opciones antes de que
 * conteste es rehacerla.
 *
 * ## Lo que fija, y por qué en dos niveles
 *
 * El par existe en dos sitios distintos de la misma respuesta y **significan cosas
 * distintas** (plan §3.3):
 *
 *   - el **indicador** — `notas.nota_original`, lo que el docente niveló de un logro
 *     suelto. Sale por `Subunidad::deUnidadCalculada`;
 *   - la **definitiva del periodo** — `notas_finales.nota_original`, la nota de la
 *     asignatura en ese periodo. Sale por `Grupo::detailed_materias_notafinal` y por la
 *     tabla de periodos del boletín.
 *
 * Un informe que trajera uno y no el otro imprimiría media novedad, así que se comprueban
 * los dos en cada uno.
 *
 * ## `nota` no cambia de significado, y eso también se comprueba
 *
 * Es la decisión que hace desplegable todo esto (plan §3.2): **`nota` sigue siendo la
 * vigente**, la que ya se imprimía. Si `nota` pasara a ser la original, los quince
 * colegios imprimirían la nota perdida en cada boletín hasta que el front y Flutter se
 * pusieran al día. Por eso cada aserto de este test mira **las dos**: que la nueva
 * aparezca y que la vieja no se haya movido.
 */
class BoletinImprimeElParTest extends CasoDeContrato
{
    /** Lo que se escribe en la nota del indicador: sacó 55, niveló 90, le queda 70 (`topada`). */
    private const ORIGINAL = 55;

    private const NIVELACION = 90;

    private const VIGENTE = 70;

    /**
     * Un alumno del grupo del seed, una nota suya y la definitiva de esa asignatura.
     *
     * @return array{grupo: object, alumno: int, token: string, nota: object}
     */
    private function escenario(): array
    {
        [$grupo, $token] = $this->grupoYPersonal();

        // **El periodo es el del TOKEN, no uno cualquiera del año.** Los tres informes
        // pintan `$user->periodo_id`, así que una nota de otro periodo no sale en la
        // respuesta y el test daría verde sin haber mirado el par. Se saca del mismo
        // usuario que eligió `tokenDelPersonalDe`, que ordena por `u.id`.
        $periodo = DB::selectOne(
            'SELECT p.id FROM users u
              INNER JOIN periodos p ON p.id = u.periodo_id AND p.deleted_at IS NULL
              WHERE u.tipo = "Usuario" AND u.is_active = 1 AND u.deleted_at IS NULL
                AND p.year_id = ? ORDER BY u.id LIMIT 1',
            [$grupo->year_id]
        );

        $this->assertNotNull($periodo, 'El seed no tiene un Usuario con periodo en ese año.');

        $numero = (int) DB::selectOne('SELECT p.numero FROM periodos p WHERE p.id = ?', [$periodo->id])->numero;

        // La nota tiene que ser de una unidad **del grupo** (`alumno_id IS NULL`) y del
        // periodo que el boletín pinta: si se elige una cualquiera, el informe no la
        // trae y el test daría verde sin haber mirado el par.
        $nota = DB::selectOne(
            'SELECT n.id, n.nota, n.alumno_id, n.subunidad_id, u.asignatura_id, u.periodo_id
               FROM notas n
              INNER JOIN subunidades s ON s.id = n.subunidad_id AND s.deleted_at IS NULL
              INNER JOIN unidades u ON u.id = s.unidad_id AND u.deleted_at IS NULL AND u.alumno_id IS NULL
              -- `profesor_id IS NOT NULL` porque el TIPO 3 lo exige
              -- (`detailed_materias_notas_finales`: `where a.profesor_id is not null` más un
              -- `inner join profesores`). Sin esta condición la asignatura elegida no sale en
              -- ese informe y su test se queda sin población — pasó, y el aserto lo cazó.
              INNER JOIN asignaturas a ON a.id = u.asignatura_id AND a.deleted_at IS NULL
                    AND a.grupo_id = ? AND a.profesor_id IS NOT NULL
              INNER JOIN matriculas m ON m.alumno_id = n.alumno_id AND m.grupo_id = a.grupo_id
                    AND m.deleted_at IS NULL AND m.estado IN ("MATR","ASIS")
              WHERE n.deleted_at IS NULL AND u.periodo_id = ?
              ORDER BY n.id LIMIT 1',
            [$grupo->id, $periodo->id]
        );

        $this->assertNotNull($nota, 'El seed no tiene una nota del grupo en el periodo del boletín.');

        return ['grupo' => $grupo, 'alumno' => (int) $nota->alumno_id, 'token' => $token,
            'nota' => $nota, 'numero' => $numero];
    }

    /** Deja esa nota nivelada, tal como la dejaría `PUT notas/nivelar/{id}` con la regla `topada`. */
    private function nivelarElIndicador(object $nota): void
    {
        DB::update(
            'UPDATE notas SET nota = ?, nota_original = ?, nota_nivelacion = ?, nivelada_at = ?, nivelada_por = ?, nivelacion_obs = ?
              WHERE id = ?',
            [self::VIGENTE, self::ORIGINAL, self::NIVELACION, '2026-08-28 09:00:00', null,
                'Taller de refuerzo y sustentación oral', $nota->id]
        );
    }

    /** Y la definitiva de esa asignatura en ese periodo, si existe. */
    private function nivelarLaDefinitiva(object $nota): bool
    {
        $filas = DB::update(
            'UPDATE notas_finales SET nota = ?, nota_original = ?, nivelada_at = ?, recuperada = 1
              WHERE alumno_id = ? AND asignatura_id = ? AND periodo_id = ?',
            [self::VIGENTE, 62.5, '2026-08-29 15:10:00', $nota->alumno_id, $nota->asignatura_id, $nota->periodo_id]
        );

        return $filas > 0;
    }

    /**
     * Busca en la respuesta, esté donde esté, el nodo cuya clave `$clave` valga `$valor`.
     *
     * **Busca en vez de navegar**, como `BolIndependienteRotuloTest`: cada informe cuelga
     * las asignaturas de un sitio distinto y una ruta fija dejaría fuera la mitad sin que
     * se notara.
     *
     * @return list<array<string, mixed>>
     */
    private function nodosCon(mixed $nodo, string $clave, mixed $valor): array
    {
        $encontrados = [];

        if (is_array($nodo)) {
            if (array_key_exists($clave, $nodo) && (int) $nodo[$clave] === (int) $valor) {
                $encontrados[] = $nodo;
            }

            foreach ($nodo as $v) {
                $encontrados = array_merge($encontrados, $this->nodosCon($v, $clave, $valor));
            }
        }

        return $encontrados;
    }

    /** @return array<string, mixed> */
    private function pedir(string $metodo, string $uri, array $cuerpo, string $token): array
    {
        $r = $this->json($metodo, $uri, $cuerpo, ['Authorization' => 'Bearer '.$token]);

        $r->assertStatus(200);

        return $r->json();
    }

    // ------------------------------------------------------------------
    // El indicador
    // ------------------------------------------------------------------

    public function test_el_boletin_de_periodo_imprime_el_par_del_indicador(): void
    {
        $e = $this->escenario();
        $this->nivelarElIndicador($e['nota']);

        $cuerpo = $this->pedir('PUT', "/api/boletines/detailed-notas/{$e['grupo']->id}",
            ['requested_alumnos' => [['alumno_id' => $e['alumno'], 'grupo_id' => (int) $e['grupo']->id]]],
            $e['token']);

        $subunidades = $this->nodosCon($cuerpo, 'subunidad_id', $e['nota']->subunidad_id);

        $this->assertNotEmpty($subunidades,
            'La subunidad nivelada no salió en el boletín: el test no comprobó nada.');

        foreach ($subunidades as $s) {
            $this->assertSame(self::VIGENTE, (int) $s['nota'],
                '`nota` dejó de ser la vigente. Es la decisión que hace desplegable esto (plan §3.2): '
                .'si pasa a ser la original, los quince colegios imprimen la nota perdida.');
            $this->assertSame(self::ORIGINAL, (int) $s['nota_original'],
                'El boletín no trae la valoración inicial: no puede imprimir el par.');
            $this->assertSame(self::NIVELACION, (int) $s['nota_nivelacion'],
                'Falta lo que sacó en la nivelación. Con `topada` no es lo mismo que la vigente: '
                .'sacó 90 y le queda 70, y un boletín que sólo enseñara 55 → 70 escondería lo que hizo.');
            $this->assertSame('2026-08-28 09:00:00', $s['nivelada_at'],
                'Sin fecha no es una novedad académica (art. 16 del 1290).');
            $this->assertSame('Taller de refuerzo y sustentación oral', $s['nivelacion_obs']);
        }
    }

    public function test_las_notas_actuales_del_alumno_imprimen_el_par_del_indicador(): void
    {
        $e = $this->escenario();
        $this->nivelarElIndicador($e['nota']);

        $cuerpo = $this->pedir('PUT', "/api/notas-actuales-alumnos/{$e['grupo']->id}",
            ['requested_alumnos' => [['alumno_id' => $e['alumno'], 'grupo_id' => (int) $e['grupo']->id]]],
            $e['token']);

        $subunidades = $this->nodosCon($cuerpo, 'subunidad_id', $e['nota']->subunidad_id);

        $this->assertNotEmpty($subunidades,
            'La subunidad nivelada no salió en las notas actuales: el test no comprobó nada.');

        foreach ($subunidades as $s) {
            $this->assertSame(self::VIGENTE, (int) $s['nota']);
            $this->assertSame(self::ORIGINAL, (int) $s['nota_original'],
                'Es la pantalla del alumno y del acudiente, la que el art. 16 del 1290 tiene en mente.');
        }
    }

    /**
     * Sin nivelar, las claves **están y valen `null`**.
     *
     * Es la mitad que se olvida: una clave que sólo aparece cuando hay dato obliga al
     * front a distinguir «vacío» de «no vino», y es la decisión que ya tomó
     * `notas/detailed` (22 §3.1).
     */
    public function test_una_nota_sin_nivelar_trae_las_claves_en_null(): void
    {
        $e = $this->escenario();

        $cuerpo = $this->pedir('PUT', "/api/boletines/detailed-notas/{$e['grupo']->id}",
            ['requested_alumnos' => [['alumno_id' => $e['alumno'], 'grupo_id' => (int) $e['grupo']->id]]],
            $e['token']);

        $subunidades = $this->nodosCon($cuerpo, 'subunidad_id', $e['nota']->subunidad_id);

        $this->assertNotEmpty($subunidades);

        foreach ($subunidades as $s) {
            $this->assertArrayHasKey('nota_original', $s,
                'La clave tiene que venir siempre, aunque valga null.');
            $this->assertNull($s['nota_original']);
            $this->assertNull($s['nivelada_at']);
            $this->assertSame((int) $e['nota']->nota, (int) $s['nota'],
                'Una nota sin nivelar cambió de valor: la migración no es aditiva.');
        }
    }

    // ------------------------------------------------------------------
    // La definitiva del periodo
    // ------------------------------------------------------------------

    public function test_el_boletin_de_periodo_imprime_el_par_de_la_definitiva(): void
    {
        $e = $this->escenario();

        if (! $this->nivelarLaDefinitiva($e['nota'])) {
            $this->markTestSkipped('El seed no tiene definitiva para esa asignatura y periodo.');
        }

        $cuerpo = $this->pedir('PUT', "/api/boletines/detailed-notas/{$e['grupo']->id}",
            ['requested_alumnos' => [['alumno_id' => $e['alumno'], 'grupo_id' => (int) $e['grupo']->id]]],
            $e['token']);

        $asignaturas = $this->nodosCon($cuerpo, 'asignatura_id', $e['nota']->asignatura_id);

        $this->assertNotEmpty($asignaturas, 'La asignatura nivelada no salió en el boletín.');

        $conPar = 0;

        foreach ($asignaturas as $a) {
            if (array_key_exists('nota_original_asignatura', $a)) {
                $this->assertEqualsWithDelta(62.5, (float) $a['nota_original_asignatura'], 0.0001,
                    'La definitiva del boletín no dice de dónde venía.');
                $this->assertSame(1, (int) $a['recuperada'],
                    '`recuperada` no cambia de significado: 1 ⇔ viene de una nivelación.');
                $conPar++;
            }

            // La tabla «Periodo 1 · 2 · 3 · 4» del papel.
            foreach ($a['notas_finales'] ?? [] as $fila) {
                $this->assertArrayHasKey('nota_original', $fila,
                    'La fila de periodos del boletín no trae la valoración inicial.');
                $conPar++;
            }
        }

        $this->assertGreaterThan(0, $conPar,
            'Ninguna asignatura de la respuesta traía el par de la definitiva: no se comprobó nada.');
    }

    public function test_el_boletin_final_imprime_el_par_de_cada_definitiva(): void
    {
        $e = $this->escenario();

        if (! $this->nivelarLaDefinitiva($e['nota'])) {
            $this->markTestSkipped('El seed no tiene definitiva para esa asignatura y periodo.');
        }

        $cuerpo = $this->pedir('PUT', "/api/bolfinales/detailed-notas-year/{$e['grupo']->id}",
            ['requested_alumnos' => [['alumno_id' => $e['alumno'], 'grupo_id' => (int) $e['grupo']->id]]],
            $e['token']);

        $nivelada = 0;
        $sinNivelar = 0;

        foreach ($this->definitivasDe($cuerpo) as $definitiva) {
            if (! array_key_exists('id', $definitiva)) {
                continue; // los periodos de relleno de `:568`, que no vienen de la tabla
            }

            $this->assertArrayHasKey('nota_original', $definitiva,
                'El boletín final no trae la valoración inicial de la definitiva.');

            if ((int) $definitiva['periodo_id'] === (int) $e['nota']->periodo_id
                && (int) $definitiva['asignatura_id'] === (int) $e['nota']->asignatura_id) {
                $this->assertEqualsWithDelta(62.5, (float) $definitiva['nota_original'], 0.0001);
                $this->assertEqualsWithDelta(self::VIGENTE, (float) $definitiva['nota'], 0.0001,
                    '`nota` dejó de ser la vigente en el boletín final.');
                $nivelada++;
            } elseif ($definitiva['nota_original'] === null) {
                $sinNivelar++;
            }
        }

        // **Más de una vez es lo correcto y no un fallo**: el boletín final cuelga las
        // asignaturas de `alumno` y otra vez de `areas[]`, así que la misma definitiva
        // aparece dos veces en la respuesta. Lo que importa es que salga y que las dos
        // digan lo mismo — el bucle de arriba lo comprueba en cada aparición.
        $this->assertGreaterThan(0, $nivelada, 'La definitiva nivelada no salió en el boletín final.');
        $this->assertGreaterThan(0, $sinNivelar,
            'Ninguna definitiva sin nivelar en la respuesta: no se comprobó que las demás sigan en null.');
    }

    /**
     * El tipo 3 — el corto, una fila por asignatura con la definitiva de cada periodo.
     *
     * **Y este test existe porque su instantánea no lo miraba.** `boletines3-detailed-notas`
     * guarda `areas[0].asignaturas` como **lista vacía** —la primera área del alumno del seed
     * no tiene ninguna—, y `forma()` de una lista vacía es `[]`: o sea que **ninguna columna
     * de la asignatura del tipo 3 estaba vigilada por nada**. Es el mismo hallazgo que el de
     * `recuperaciones: []` en `BoletinFinalSinAsteriscoTest`, y por eso las veintiséis
     * proyecciones que este par añadió a `Grupo::detailed_materias_notas_finales` habrían
     * entrado **sin una sola prueba** si esto no estuviera escrito.
     */
    public function test_el_boletin_tipo_3_imprime_el_par_de_cada_periodo(): void
    {
        $e = $this->escenario();

        // **La definitiva que se nivela es la del periodo NÚMERO 1**, y no la del token.
        // Las cuatro variantes de `detailed_materias_notas_finales` traen `per1`; las de
        // `per2..4` sólo aparecen según el `num_periodo`, y además el `asignatura_id` de la
        // fila sale del bloque de periodo 1 por el `right join`. Fijarlo en el 1 es lo que
        // hace que este test mire siempre la misma columna.
        $definitiva = DB::selectOne(
            'SELECT nf.id FROM notas_finales nf
              INNER JOIN periodos p ON p.id = nf.periodo_id AND p.numero = 1 AND p.deleted_at IS NULL
              WHERE nf.alumno_id = ? AND nf.asignatura_id = ? AND p.year_id = ?',
            [$e['alumno'], $e['nota']->asignatura_id, $e['grupo']->year_id]
        );

        if ($definitiva === null) {
            $this->markTestSkipped('El seed no tiene definitiva del periodo 1 para esa asignatura.');
        }

        DB::update('UPDATE notas_finales SET nota = ?, nota_original = ?, nivelada_at = ?, recuperada = 1 WHERE id = ?',
            [self::VIGENTE, 62.5, '2026-08-29 15:10:00', $definitiva->id]);

        // **`periodo_a_calcular` va SIEMPRE, y es la mitad de este test.** Sin él,
        // `Boletines3Controller:73` usa el defecto **10**, y `detailed_materias_notas_finales`
        // sólo tiene ramas para 1, 2, 3 y 4: devuelve el array vacío con el que nació, así que
        // el boletín sale **con las áreas y sin una sola asignatura**, en 200 y sin avisar. Es
        // lo que hace `BoletinesTest`, y por eso su instantánea guarda `asignaturas: []` y no
        // vigila ni una columna de aquí. El front sí lo manda
        // (`boletines-periodo.ts`: `{ periodo_a_calcular: periodo }`), así que la pantalla real
        // funciona; el hueco es del contrato, no del colegio.
        $cuerpo = $this->pedir('PUT', "/api/boletines3/detailed-notas/{$e['grupo']->id}", [
            'periodo_a_calcular' => 1,
            'requested_alumnos' => [['alumno_id' => $e['alumno'], 'grupo_id' => (int) $e['grupo']->id]],
        ], $e['token']);

        $conLaClave = $this->nodosConClave($cuerpo, 'nota_original_per1');

        $this->assertNotEmpty($conLaClave,
            'Ninguna fila del tipo 3 trae `nota_original_per1`: las veintiséis proyecciones no '
            .'llegan a la respuesta. Y su instantánea tampoco lo vería: guarda `asignaturas: []`.');

        $nivelada = 0;
        $sinNivelar = 0;

        foreach ($conLaClave as $fila) {
            if ((int) $fila['nf_id_1'] === (int) $definitiva->id) {
                $this->assertEqualsWithDelta(62.5, (float) $fila['nota_original_per1'], 0.0001,
                    'El tipo 3 no dice de dónde venía la definitiva del periodo 1.');
                $this->assertEqualsWithDelta(self::VIGENTE, (float) $fila['nota_final_per1'], 0.0001,
                    '`nota_final_per1` dejó de ser la vigente: la migración no es aditiva.');
                $this->assertSame('2026-08-29 15:10:00', $fila['nivelada_at_per1'],
                    'Sin fecha no es una novedad académica (art. 16 del 1290).');
                $nivelada++;
            } elseif ($fila['nota_original_per1'] === null) {
                $sinNivelar++;
            }
        }

        $this->assertGreaterThan(0, $nivelada, 'La definitiva nivelada no salió en el tipo 3.');
        $this->assertGreaterThan(0, $sinNivelar,
            'Ninguna fila sin nivelar: no se comprobó que las demás sigan en null.');
    }

    /**
     * Los nodos que traen una clave, la traigan con el valor que la traigan.
     *
     * Hace falta además de `nodosCon` porque en el tipo 3 el `asignatura_id` de una fila
     * **puede ser `null`**: sale del bloque del periodo 1 por un `right join`, así que una
     * asignatura sin definitiva en ese periodo viaja con el id vacío. Buscar por la clave y
     * no por el id es lo único que encuentra esas filas.
     *
     * @return list<array<string, mixed>>
     */
    private function nodosConClave(mixed $nodo, string $clave): array
    {
        $encontrados = [];

        if (is_array($nodo)) {
            if (array_key_exists($clave, $nodo)) {
                $encontrados[] = $nodo;
            }

            foreach ($nodo as $v) {
                $encontrados = array_merge($encontrados, $this->nodosConClave($v, $clave));
            }
        }

        return $encontrados;
    }

    /**
     * Todas las listas `definitivas` del boletín final, vengan colgadas de donde vengan.
     *
     * @return list<array<string, mixed>>
     */
    private function definitivasDe(mixed $nodo): array
    {
        $encontradas = [];

        if (is_array($nodo)) {
            foreach ($nodo as $k => $v) {
                if ($k === 'definitivas' && is_array($v)) {
                    foreach ($v as $fila) {
                        if (is_array($fila)) {
                            $encontradas[] = $fila;
                        }
                    }

                    continue;
                }

                $encontradas = array_merge($encontradas, $this->definitivasDe($v));
            }
        }

        return $encontradas;
    }
}
