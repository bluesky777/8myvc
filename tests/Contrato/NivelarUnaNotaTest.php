<?php

namespace Tests\Contrato;

use App\Services\Auditoria;
use App\Services\Nivelacion;
use Illuminate\Support\Facades\DB;

/**
 * Los tres endpoints de nivelar, y **el centinela de los dos que no cambian**.
 *
 * Contrato: [22-nivelaciones.md](../../docs/migracion/22-nivelaciones.md). Tareas
 * A5 y A6 del reparto, y van **juntas**: la mitad de este fichero comprueba que
 * `notas/update` y `notas/lote` siguen comportándose exactamente como hoy, que es
 * la red de `myvc_flutter` (§6.1). Escribir A5 sin A6 sería fiar a la memoria la
 * única cosa que no se puede romper.
 *
 * El criterio es el de la casa: **se mira lo que queda escrito**, no el 200. Una
 * nivelación que conteste bien sin escribir el acta, o un `notas/update` que
 * conteste bien después de haberse llevado por delante una nivelación, pasan
 * cualquier test que mire el código de respuesta.
 */
class NivelarUnaNotaTest extends CasoDeContrato
{
    private function tokenDeSuperusuario(): string
    {
        $super = DB::selectOne('SELECT username FROM users
            WHERE is_superuser = 1 AND is_active = 1 AND deleted_at IS NULL ORDER BY id LIMIT 1');

        return $this->tokenDe($super->username);
    }

    /** Una nota con su unidad y su periodo vivos, que es lo que se puede nivelar. */
    private function unaNota(): object
    {
        $nota = DB::selectOne('SELECT n.id, n.nota, n.alumno_id, u.asignatura_id, u.periodo_id, p.year_id
            FROM notas n
            INNER JOIN subunidades s ON s.id = n.subunidad_id AND s.deleted_at IS NULL
            INNER JOIN unidades u ON u.id = s.unidad_id AND u.deleted_at IS NULL
            INNER JOIN periodos p ON p.id = u.periodo_id AND p.deleted_at IS NULL
            WHERE n.deleted_at IS NULL ORDER BY n.id LIMIT 1');

        $this->assertNotNull($nota, 'El seed necesita una nota con su unidad y su periodo vivos.');

        return $nota;
    }

    /** La regla y la mínima del año de esa nota, puestas a lo que el caso necesita. */
    private function conRegla(int $yearId, string $regla, int $minima): void
    {
        DB::update('UPDATE years SET regla_nivelacion = ?, nota_minima_aceptada = ? WHERE id = ?',
            [$regla, (string) $minima, $yearId]);

        Nivelacion::olvidar();
    }

    /**
     * La asignatura del **año y periodo actuales** con su profesor, su rejilla y sus
     * alumnos: el contexto que `notas/detailed` necesita para contestar 200.
     *
     * Calcado de `NotasTest::contexto()`, y el porqué está medido allí:
     * `Services\Login` **reescribe `users.periodo_id` al periodo actual en cada
     * inicio de sesión**, así que no vale ponérselo a mano; y `periodos.actual`
     * marca el actual **de su año**, mientras que el año actual del colegio lo dice
     * `years.actual` — sin las dos condiciones se elige una asignatura de otro año y
     * la rejilla contesta 404 o 500.
     */
    private function contextoDeLaRejilla(): object
    {
        $fila = DB::selectOne('SELECT a.id AS asignatura_id, a.profesor_id, u.username, un.periodo_id, g.year_id
            FROM asignaturas a
            INNER JOIN profesores p ON p.id = a.profesor_id AND p.deleted_at IS NULL
            INNER JOIN users u ON u.id = p.user_id AND u.is_active = 1 AND u.deleted_at IS NULL
            INNER JOIN grupos g ON g.id = a.grupo_id AND g.deleted_at IS NULL
            INNER JOIN unidades un ON un.asignatura_id = a.id AND un.deleted_at IS NULL AND un.alumno_id IS NULL
            INNER JOIN periodos per ON per.id = un.periodo_id AND per.actual = 1
                AND per.year_id = g.year_id AND per.deleted_at IS NULL
            INNER JOIN years y ON y.id = g.year_id AND y.actual = 1 AND y.deleted_at IS NULL
            INNER JOIN subunidades s ON s.unidad_id = un.id AND s.deleted_at IS NULL
            INNER JOIN matriculas m ON m.grupo_id = a.grupo_id AND m.deleted_at IS NULL
                AND m.estado IN ("MATR", "ASIS", "PREM")
            WHERE a.deleted_at IS NULL
            ORDER BY a.id LIMIT 1');

        $this->assertNotNull($fila, 'El seed necesita una asignatura del año y periodo actuales con rejilla y alumnos.');

        return $fila;
    }

    /** Todas las celdas de la rejilla, aplanadas. */
    private function celdasDeLaRejilla(array $json): array
    {
        $celdas = [];

        foreach ($json['alumnos'] ?? [] as $alumno) {
            foreach ($alumno['notas'] ?? [] as $n) {
                $celdas[] = $n;
            }
        }

        return $celdas;
    }

    /** La fila cruda, para mirar lo que quedó escrito y no lo que se devolvió. */
    private function filaDe(int $id): object
    {
        return DB::selectOne('SELECT nota, nota_original, nota_nivelacion, nivelada_at, nivelada_por,
            nivelacion_obs FROM notas WHERE id = ?', [$id]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // A6 — LOS DOS QUE NO CAMBIAN. Esto es la red de Flutter.
    // ─────────────────────────────────────────────────────────────────────

    /**
     * **`notas/update` no aprendió a nivelar.**
     *
     * Es el fallo concreto que el §6.1 del reparto manda impedir: si el camino de
     * siempre aprendiera a nivelar, un docente calificando desde el móvil mandaría
     * un 95 y, con la regla `topada`, se guardaría **70**. Sin error, sin aviso, y
     * con la nota buena perdida.
     *
     * Así que con la regla más agresiva encendida —`topada`, mínima 35— se teclea
     * un 48 por el camino de siempre y tiene que quedar **48**, y el acta tiene que
     * seguir vacía.
     *
     * Los números salen de la escala real del seed, **0 a 50**: con un 95 la
     * petición sale 422 por `EscalaDeNotas` y el caso pasaría sin medir nada.
     */
    public function test_notas_update_no_nivela_aunque_la_regla_este_encendida(): void
    {
        $token = $this->tokenDeSuperusuario();
        $nota = $this->unaNota();

        $this->conRegla((int) $nota->year_id, Nivelacion::TOPADA, 35);

        $this->withToken($token)->putJson('/api/notas/update/'.$nota->id, ['nota' => 48])
            ->assertStatus(200);

        $fila = $this->filaDe((int) $nota->id);

        $this->assertEquals(48, $fila->nota,
            'La regla `topada` se aplicó por `notas/update`: es exactamente el fallo del §6.1. '.
            'Nivelar son endpoints nuevos; este camino no cambia ni una línea.');
        $this->assertNull($fila->nota_original, '`notas/update` no escribe la valoración inicial.');
        $this->assertNull($fila->nota_nivelacion);
        $this->assertNull($fila->nivelada_at);
    }

    /** Lo mismo por el lote, que es el otro camino que usa la app. */
    public function test_notas_lote_no_nivela_aunque_la_regla_este_encendida(): void
    {
        $token = $this->tokenDeSuperusuario();
        $nota = $this->unaNota();

        $this->conRegla((int) $nota->year_id, Nivelacion::TOPADA, 35);

        $this->withToken($token)->putJson('/api/notas/lote', ['notas' => [['id' => $nota->id, 'nota' => 48]]])
            ->assertStatus(200)
            ->assertJsonPath('guardadas', 1);

        $fila = $this->filaDe((int) $nota->id);

        $this->assertEquals(48, $fila->nota, 'El lote aplicó la regla: mismo fallo del §6.1 por el otro camino.');
        $this->assertNull($fila->nota_original);
        $this->assertNull($fila->nota_nivelacion);
    }

    /**
     * **`notas/update` sobre una nota YA nivelada escribe la vigente y deja el acta
     * intacta** — ni la limpia ni recalcula (22 §3.2ter).
     *
     * Este recorrido existe y no se puede cerrar: Flutter lee `nota`, no ve ninguna
     * marca porque las claves nuevas le son invisibles, y corrige. Las dos
     * tentaciones son peores que el hueco: **limpiar el acta** sería borrar un
     * registro académico desde un móvil, y **recalcular por la regla** sería
     * aprender a nivelar por la puerta de atrás. Que quede una fila cuya `nota` no
     * es la de la regla es la consecuencia aceptada, y la línea de auditoría es la
     * única traza.
     */
    public function test_editar_una_nota_ya_nivelada_no_borra_el_acta(): void
    {
        $token = $this->tokenDeSuperusuario();
        $nota = $this->unaNota();

        $this->conRegla((int) $nota->year_id, Nivelacion::TOPADA, 35);
        DB::update('UPDATE notas SET nota = 28 WHERE id = ?', [$nota->id]);

        $this->withToken($token)->putJson('/api/notas/nivelar/'.$nota->id, ['nota_nivelacion' => 45])
            ->assertStatus(200);

        $this->withToken($token)->putJson('/api/notas/update/'.$nota->id, ['nota' => 40])
            ->assertStatus(200);

        $fila = $this->filaDe((int) $nota->id);

        $this->assertEquals(40, $fila->nota, 'La corrección por el camino normal escribe la vigente, tal cual.');
        $this->assertEquals(28, $fila->nota_original, '`notas/update` NO puede borrar la valoración inicial.');
        $this->assertEquals(45, $fila->nota_nivelacion, '`notas/update` NO puede borrar la nivelación registrada.');
        $this->assertNotNull($fila->nivelada_at, 'El acta se queda: quitarla es `DELETE notas/nivelar/{id}`.');
    }

    // ─────────────────────────────────────────────────────────────────────
    // A5 — PUT notas/nivelar/{id}
    // ─────────────────────────────────────────────────────────────────────

    /** La respuesta entera de la §1.2, y lo que queda escrito detrás. */
    public function test_nivelar_aplica_la_regla_y_deja_el_acta(): void
    {
        $token = $this->tokenDeSuperusuario();
        $nota = $this->unaNota();

        $this->conRegla((int) $nota->year_id, Nivelacion::TOPADA, 35);
        DB::update('UPDATE notas SET nota = 28 WHERE id = ?', [$nota->id]);

        $r = $this->withToken($token)->putJson('/api/notas/nivelar/'.$nota->id, [
            'nota_nivelacion' => 45,
            'observacion' => 'Taller de refuerzo y sustentación oral',
            'fecha' => '2026-08-28',
        ]);

        $r->assertStatus(200)
            ->assertJsonPath('id', (int) $nota->id)
            ->assertJsonPath('nota', 35)
            ->assertJsonPath('nota_original', 28)
            ->assertJsonPath('nota_nivelacion', 45)
            ->assertJsonPath('nivelada_at', '2026-08-28 00:00:00')
            ->assertJsonPath('nivelacion_obs', 'Taller de refuerzo y sustentación oral')
            ->assertJsonPath('regla_aplicada.regla', 'topada')
            ->assertJsonPath('regla_aplicada.nota_minima', 35)
            ->assertJsonPath('regla_aplicada.explicacion',
                'Regla del colegio: la nivelación se topa en la mínima aprobatoria (35). Queda 35.');

        // Las once claves del contrato, ni una más ni una menos.
        $this->assertSame([
            'alumno_id', 'definitiva', 'id', 'nivelacion_obs', 'nivelada_at', 'nivelada_por',
            'nivelada_por_username', 'nota', 'nota_nivelacion', 'nota_original', 'regla_aplicada',
            'subunidad_id', 'updated_at',
        ], $this->ordenadas($r->json()), 'La respuesta cambió de forma respecto al contrato (22 §1.2).');

        $this->assertNotNull($r->json('nivelada_por'), 'El acta sin responsable no es un acta (art. 16).');

        $fila = $this->filaDe((int) $nota->id);
        $this->assertEquals(35, $fila->nota, 'Lo que se devuelve tiene que ser lo que se escribió.');
        $this->assertEquals(28, $fila->nota_original);
        $this->assertEquals(45, $fila->nota_nivelacion,
            'Sin esta columna, bajo `topada` el 45 desaparece del sistema.');
    }

    /** @param array<string, mixed> $json @return list<string> */
    private function ordenadas(array $json): array
    {
        $claves = array_keys($json);
        sort($claves);

        return $claves;
    }

    /** Las tres reglas, sobre la misma nota, por el endpoint de verdad. */
    public function test_las_tres_reglas_por_el_endpoint(): void
    {
        $token = $this->tokenDeSuperusuario();
        $nota = $this->unaNota();

        foreach ([[Nivelacion::TOPADA, 35], [Nivelacion::MAYOR, 45], [Nivelacion::REEMPLAZA, 45]] as [$regla, $queda]) {
            $this->conRegla((int) $nota->year_id, $regla, 35);
            DB::update('UPDATE notas SET nota = 28, nota_original = NULL, nota_nivelacion = NULL,
                nivelada_at = NULL, nivelada_por = NULL, nivelacion_obs = NULL WHERE id = ?', [$nota->id]);

            $this->withToken($token)->putJson('/api/notas/nivelar/'.$nota->id, ['nota_nivelacion' => 45])
                ->assertStatus(200)
                ->assertJsonPath('nota', $queda);

            $this->assertEquals($queda, $this->filaDe((int) $nota->id)->nota, "Con la regla {$regla}.");
        }
    }

    /**
     * Repetir el `PUT` **sustituye** la nivelación y **conserva la original**
     * (§1.3). Es la decisión «un intento por indicador» llevada al endpoint: el
     * docente que tecleó 80 queriendo 85 no tiene que borrar y volver a nivelar.
     */
    public function test_nivelar_dos_veces_sustituye_y_conserva_la_valoracion_inicial(): void
    {
        $token = $this->tokenDeSuperusuario();
        $nota = $this->unaNota();

        $this->conRegla((int) $nota->year_id, Nivelacion::MAYOR, 35);
        DB::update('UPDATE notas SET nota = 28 WHERE id = ?', [$nota->id]);

        $this->withToken($token)->putJson('/api/notas/nivelar/'.$nota->id, ['nota_nivelacion' => 40])
            ->assertStatus(200)->assertJsonPath('nota', 40);

        $this->withToken($token)->putJson('/api/notas/nivelar/'.$nota->id, ['nota_nivelacion' => 45])
            ->assertStatus(200)->assertJsonPath('nota', 45)
            ->assertJsonPath('nota_original', 28);

        $fila = $this->filaDe((int) $nota->id);

        $this->assertEquals(28, $fila->nota_original,
            'La segunda nivelación pisó la valoración inicial con la vigente, que ya era nivelada.');
        $this->assertEquals(45, $fila->nota_nivelacion);

        // La primera no se pierde: deja de estar en `notas` y está en `auditoria`.
        $lineas = DB::select('SELECT valor_anterior, valor_nuevo FROM auditoria
            WHERE entidad = "nota" AND entidad_id = ? AND accion = ? ORDER BY id', [$nota->id, Auditoria::NIVELAR]);

        $this->assertCount(2, $lineas, 'Cada nivelación deja su línea: la primera vive ahí.');
        $this->assertEquals(40, json_decode((string) $lineas[1]->valor_anterior));
    }

    /** Corregir la valoración inicial de una celda ya nivelada (§1.6), que es `editar`. */
    public function test_corregir_la_valoracion_inicial_reaplica_la_regla_y_se_audita_como_edicion(): void
    {
        $token = $this->tokenDeSuperusuario();
        $nota = $this->unaNota();

        $this->conRegla((int) $nota->year_id, Nivelacion::MAYOR, 35);
        DB::update('UPDATE notas SET nota = 28 WHERE id = ?', [$nota->id]);

        // Con `mayor`, una nivelación por debajo de la original deja la original:
        // 28 contra 20 queda 28. Es el caso que hace visible la reaplicación.
        $this->withToken($token)->putJson('/api/notas/nivelar/'.$nota->id, ['nota_nivelacion' => 20])
            ->assertStatus(200)->assertJsonPath('nota', 28);

        // Se corrige la valoración inicial a 32: con `mayor`, la vigente pasa a 32.
        $this->withToken($token)->putJson('/api/notas/nivelar/'.$nota->id, ['nota_original' => 32])
            ->assertStatus(200)
            ->assertJsonPath('nota_original', 32)
            ->assertJsonPath('nota', 32)
            ->assertJsonPath('nota_nivelacion', 20);

        $lineas = DB::select('SELECT accion FROM auditoria WHERE entidad = "nota" AND entidad_id = ? ORDER BY id DESC LIMIT 1',
            [$nota->id]);

        $this->assertSame(Auditoria::EDITAR, $lineas[0]->accion,
            'Corregir es `editar` y no `nivelar`: la §1.2 del plan es que no se confundan.');
    }

    /** En una celda sin nivelar, la original se corrige con `notas/update`, no aquí. */
    public function test_corregir_la_original_de_una_nota_sin_nivelar_es_422(): void
    {
        $token = $this->tokenDeSuperusuario();
        $nota = $this->unaNota();

        $this->withToken($token)->putJson('/api/notas/nivelar/'.$nota->id, ['nota_original' => 58])
            ->assertStatus(422);

        $this->assertNull($this->filaDe((int) $nota->id)->nota_original, 'Un 422 no escribe nada.');
    }

    /** La definitiva viaja en la misma respuesta, con la forma de `notas/update`. */
    public function test_la_definitiva_viaja_en_la_respuesta(): void
    {
        $token = $this->tokenDeSuperusuario();
        $nota = $this->unaNota();

        $r = $this->withToken($token)->putJson('/api/notas/nivelar/'.$nota->id, ['nota_nivelacion' => 40]);

        $r->assertStatus(200);

        $definitiva = $r->json('definitiva');

        if ($definitiva !== null) {
            $this->assertSame(
                ['alumno_id', 'asignatura_id', 'manual', 'nota', 'periodo_id', 'recuperada'],
                $this->ordenadas($definitiva),
                'La definitiva tiene la forma de `notas-update.json`, para que el front no tenga dos ideas de lo mismo.');
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // A7 — los campos nuevos en `notas/detailed`
    // ─────────────────────────────────────────────────────────────────────

    /**
     * La planilla trae el par **con valores**, no sólo las claves en `null`.
     *
     * La instantánea de la rejilla fija que las seis claves existen; eso no
     * comprueba que se llenen. Sin este caso, un `SELECT` que devolviera siempre
     * `null` —un alias mal escrito, un `JOIN` que no case— pasaría el snapshot y la
     * celda del front nunca pintaría la original tachada.
     *
     * `nivelada_por_username` es la que de verdad se puede romper sola: sale de un
     * `LEFT JOIN` con `users`, y con `INNER` desaparecerían de la planilla **todas
     * las notas sin nivelar**, que son casi todas.
     */
    public function test_la_planilla_trae_el_par_con_valores_despues_de_nivelar(): void
    {
        $contexto = $this->contextoDeLaRejilla();
        $token = $this->tokenDe($contexto->username);

        // La rejilla siembra las notas que falten, así que se pide primero y se
        // nivela una de las que devuelve: así la celda existe seguro.
        $primera = $this->withToken($token)->putJson('/api/notas/detailed', [
            'asignatura_id' => $contexto->asignatura_id,
            'profesor_id' => $contexto->profesor_id,
        ]);

        $primera->assertStatus(200);

        $celdas = $this->celdasDeLaRejilla($primera->json());

        $this->assertNotEmpty($celdas, 'La rejilla salió sin notas: el caso no mediría nada.');

        $notaId = (int) $celdas[0]['id'];

        $this->conRegla((int) $contexto->year_id, Nivelacion::TOPADA, 35);
        DB::update('UPDATE notas SET nota = 28 WHERE id = ?', [$notaId]);

        $this->withToken($token)->putJson('/api/notas/nivelar/'.$notaId,
            ['nota_nivelacion' => 45, 'observacion' => 'Taller de superación'])->assertStatus(200);

        $r = $this->withToken($token)->putJson('/api/notas/detailed', [
            'asignatura_id' => $contexto->asignatura_id,
            'profesor_id' => $contexto->profesor_id,
        ]);

        $r->assertStatus(200);

        $celda = null;

        foreach ($this->celdasDeLaRejilla($r->json()) as $n) {
            if ((int) $n['id'] === $notaId) {
                $celda = $n;
            }
        }

        $this->assertNotNull($celda, 'La nota nivelada no salió en la planilla: el caso no mide nada.');

        $this->assertEquals(35, $celda['nota'], 'La vigente es la que la regla dejó.');
        $this->assertEquals(28, $celda['nota_original'], 'Sin la original, la celda no puede pintar el par.');
        $this->assertEquals(45, $celda['nota_nivelacion']);
        $this->assertNotNull($celda['nivelada_at']);
        $this->assertNotNull($celda['nivelada_por']);
        $this->assertNotNull($celda['nivelada_por_username'],
            'El `LEFT JOIN` con `users` no trajo el nombre: el pie del diálogo se queda sin «quién».');
        $this->assertSame('Taller de superación', $celda['nivelacion_obs']);
    }

    /**
     * Y las notas **sin** nivelar siguen saliendo, con las seis claves en `null`.
     *
     * Es la mitad que el caso de arriba no puede ver: si el `JOIN` con `users` fuera
     * `INNER`, la planilla perdería **todas** las celdas sin nivelar —que son casi
     * todas— y el caso anterior seguiría verde, porque la suya sí tiene usuario.
     */
    public function test_las_notas_sin_nivelar_siguen_saliendo_con_las_claves_en_null(): void
    {
        $contexto = $this->contextoDeLaRejilla();
        $token = $this->tokenDe($contexto->username);

        $r = $this->withToken($token)->putJson('/api/notas/detailed', [
            'asignatura_id' => $contexto->asignatura_id,
            'profesor_id' => $contexto->profesor_id,
        ]);

        $r->assertStatus(200);

        $celdas = $this->celdasDeLaRejilla($r->json());

        $this->assertNotEmpty($celdas, 'La planilla salió sin ninguna nota: el `JOIN` con `users` se las llevó.');

        $sinNivelar = 0;

        foreach ($celdas as $celda) {
            foreach (['nota_original', 'nota_nivelacion', 'nivelada_at', 'nivelada_por',
                'nivelada_por_username', 'nivelacion_obs'] as $clave) {
                $this->assertArrayHasKey($clave, $celda,
                    "Falta `{$clave}`: las seis van SIEMPRE, para que el front no distinga «vacío» de «no vino».");
            }

            if ($celda['nota_original'] === null) {
                $sinNivelar++;
            }
        }

        $this->assertGreaterThan(0, $sinNivelar,
            'Ninguna celda sin nivelar en la rejilla: este caso no puede ver el `INNER JOIN` que busca.');
    }

    // ─────────────────────────────────────────────────────────────────────
    // Los errores del §1.5 — y ninguno escribe nada
    // ─────────────────────────────────────────────────────────────────────

    public function test_sin_nota_de_nivelacion_es_422(): void
    {
        $token = $this->tokenDeSuperusuario();
        $nota = $this->unaNota();

        $this->withToken($token)->putJson('/api/notas/nivelar/'.$nota->id, [])->assertStatus(422);
        $this->withToken($token)->putJson('/api/notas/nivelar/'.$nota->id, ['nota_nivelacion' => 'ocho'])
            ->assertStatus(422);

        $this->assertNull($this->filaDe((int) $nota->id)->nota_original);
    }

    public function test_una_nota_que_no_existe_es_404(): void
    {
        $token = $this->tokenDeSuperusuario();
        $inexistente = (int) DB::selectOne('SELECT COALESCE(MAX(id), 0) + 1000 AS id FROM notas')->id;

        $this->withToken($token)->putJson('/api/notas/nivelar/'.$inexistente, ['nota_nivelacion' => 40])
            ->assertStatus(404);
    }

    public function test_una_observacion_de_mas_de_255_es_422(): void
    {
        $token = $this->tokenDeSuperusuario();
        $nota = $this->unaNota();

        $this->withToken($token)->putJson('/api/notas/nivelar/'.$nota->id, [
            'nota_nivelacion' => 40,
            'observacion' => str_repeat('a', 256),
        ])->assertStatus(422);

        $this->assertNull($this->filaDe((int) $nota->id)->nota_original);
    }

    /** Un acta con fecha de mañana no es un acta. */
    public function test_una_fecha_futura_o_ilegible_es_422(): void
    {
        $token = $this->tokenDeSuperusuario();
        $nota = $this->unaNota();

        foreach (['2030-01-01', 'el martes', '28/08/2026'] as $fecha) {
            $this->withToken($token)->putJson('/api/notas/nivelar/'.$nota->id,
                ['nota_nivelacion' => 40, 'fecha' => $fecha])->assertStatus(422);
        }

        $this->assertNull($this->filaDe((int) $nota->id)->nota_original);
    }

    /**
     * **403 y no el 400 del guard viejo**: es código nuevo y usa los códigos
     * correctos, mientras `pueden_modificar_definitivas` conserva su 400 porque lo
     * llaman cinco métodos de definitivas desde Flutter.
     */
    public function test_con_el_periodo_cerrado_a_nivelar_es_403_y_no_escribe(): void
    {
        $nota = $this->unaNota();
        $token = $this->tokenDelPersonalLlano();

        DB::update('UPDATE periodos SET profes_pueden_nivelar = 0 WHERE id = ?', [$nota->periodo_id]);

        $this->withToken($token)->putJson('/api/notas/nivelar/'.$nota->id, ['nota_nivelacion' => 40])
            ->assertStatus(403);

        $this->assertNull($this->filaDe((int) $nota->id)->nota_original, 'Un 403 no escribe nada.');
    }

    /** Una regla que no es de las tres se dice, no se sustituye por el defecto. */
    public function test_una_regla_invalida_en_el_anio_es_422(): void
    {
        $token = $this->tokenDeSuperusuario();
        $nota = $this->unaNota();

        $this->conRegla((int) $nota->year_id, 'la-que-sea', 35);

        $this->withToken($token)->putJson('/api/notas/nivelar/'.$nota->id, ['nota_nivelacion' => 40])
            ->assertStatus(422);

        $this->assertNull($this->filaDe((int) $nota->id)->nota_original);
    }

    // ─────────────────────────────────────────────────────────────────────
    // A5 — DELETE notas/nivelar/{id}
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Quitar la nivelación devuelve `nota` a `nota_original` y deja **las cinco**
     * del acta en NULL. `nota_nivelacion` incluida: una nota que dice no estar
     * nivelada y a la vez guarda la nota de una nivelación acaba impresa en algún
     * sitio.
     */
    public function test_quitar_la_nivelacion_devuelve_la_original_y_limpia_las_cinco(): void
    {
        $token = $this->tokenDeSuperusuario();
        $nota = $this->unaNota();

        $this->conRegla((int) $nota->year_id, Nivelacion::TOPADA, 35);
        DB::update('UPDATE notas SET nota = 28 WHERE id = ?', [$nota->id]);

        $this->withToken($token)->putJson('/api/notas/nivelar/'.$nota->id,
            ['nota_nivelacion' => 45, 'observacion' => 'Taller'])->assertStatus(200);

        $this->withToken($token)->deleteJson('/api/notas/nivelar/'.$nota->id)
            ->assertStatus(200)
            ->assertJsonPath('nota', 28)
            ->assertJsonPath('nota_original', null)
            ->assertJsonPath('nota_nivelacion', null)
            ->assertJsonPath('nivelada_at', null)
            ->assertJsonPath('nivelada_por', null)
            ->assertJsonPath('nivelacion_obs', null)
            ->assertJsonPath('regla_aplicada', null);

        $fila = $this->filaDe((int) $nota->id);

        $this->assertEquals(28, $fila->nota, 'La vigente vuelve a ser la valoración inicial.');

        foreach (['nota_original', 'nota_nivelacion', 'nivelada_at', 'nivelada_por', 'nivelacion_obs'] as $columna) {
            $this->assertNull($fila->$columna, "`{$columna}` sobrevivió al DELETE: la fila dice dos cosas a la vez.");
        }

        $ultima = DB::selectOne('SELECT accion FROM auditoria WHERE entidad = "nota" AND entidad_id = ?
            ORDER BY id DESC LIMIT 1', [$nota->id]);

        $this->assertSame(Auditoria::QUITAR_NIVELACION, $ultima->accion);
    }

    /**
     * **409 y no 200 vacío.** Un `DELETE` que contesta bien sobre algo que no
     * existía es una respuesta que miente: el front pintaría «nivelación retirada»
     * sobre una celda que nunca la tuvo.
     */
    public function test_quitar_una_nivelacion_que_no_hay_es_409(): void
    {
        $token = $this->tokenDeSuperusuario();
        $nota = $this->unaNota();

        DB::update('UPDATE notas SET nota_original = NULL WHERE id = ?', [$nota->id]);

        $this->withToken($token)->deleteJson('/api/notas/nivelar/'.$nota->id)->assertStatus(409);
    }

    // ─────────────────────────────────────────────────────────────────────
    // A5 — PUT notas/nivelar/lote, con los tres desenlaces de `notas/lote`
    // ─────────────────────────────────────────────────────────────────────

    /** Desenlace 1: todo bien, con `niveladas[]` y `definitivas[]`. */
    public function test_el_lote_nivela_y_devuelve_lo_que_quedo(): void
    {
        $token = $this->tokenDeSuperusuario();

        $notas = DB::select('SELECT n.id, u.periodo_id, p.year_id FROM notas n
            INNER JOIN subunidades s ON s.id = n.subunidad_id AND s.deleted_at IS NULL
            INNER JOIN unidades u ON u.id = s.unidad_id AND u.deleted_at IS NULL
            INNER JOIN periodos p ON p.id = u.periodo_id AND p.deleted_at IS NULL
            WHERE n.deleted_at IS NULL ORDER BY n.id LIMIT 3');

        $this->assertCount(3, $notas, 'El seed necesita tres notas nivelables.');

        $this->conRegla((int) $notas[0]->year_id, Nivelacion::TOPADA, 35);

        foreach ($notas as $n) {
            DB::update('UPDATE notas SET nota = 28 WHERE id = ?', [$n->id]);
        }

        $r = $this->withToken($token)->putJson('/api/notas/nivelar/lote', [
            'notas' => array_map(fn ($n) => ['id' => $n->id, 'nota_nivelacion' => 45], $notas),
        ]);

        $r->assertStatus(200)
            ->assertJsonPath('guardadas', 3)
            ->assertJsonPath('fallidas', [])
            ->assertJsonCount(3, 'niveladas');

        $this->assertSame(35, $r->json('niveladas.0.nota'),
            '`niveladas[]` existe porque lo que queda en `nota` lo decide la regla, no el cuerpo.');
        $this->assertArrayNotHasKey('definitiva', $r->json('niveladas.0'),
            'La definitiva va una vez por alumno en `definitivas`, no una por nota.');
        $this->assertIsArray($r->json('definitivas'));

        foreach ($notas as $n) {
            $this->assertEquals(35, $this->filaDe((int) $n->id)->nota);
        }
    }

    /** Desenlace 2: éxito parcial, 200 con `fallidas[]`. */
    public function test_el_lote_aparta_las_malas_y_guarda_las_buenas(): void
    {
        $token = $this->tokenDeSuperusuario();
        $nota = $this->unaNota();
        $inexistente = (int) DB::selectOne('SELECT COALESCE(MAX(id), 0) + 1000 AS id FROM notas')->id;

        $r = $this->withToken($token)->putJson('/api/notas/nivelar/lote', ['notas' => [
            ['id' => $nota->id, 'nota_nivelacion' => 40],
            ['id' => $inexistente, 'nota_nivelacion' => 40],
            ['id' => $nota->id, 'nota_nivelacion' => 'ocho'],
            ['nota_nivelacion' => 40],
        ]]);

        $r->assertStatus(200)->assertJsonPath('guardadas', 1);

        $motivos = array_column($r->json('fallidas'), 'motivo');

        $this->assertCount(3, $motivos, 'Una nota mala no puede llevarse por delante las buenas.');
        $this->assertContains('No existe la nota, o su indicador ya no está.', $motivos);
        $this->assertContains('La nota no es un número.', $motivos);
    }

    /** Desenlace 3: el permiso tumba el lote entero, y no escribe nada. */
    public function test_con_el_periodo_cerrado_el_lote_no_escribe_nada(): void
    {
        $nota = $this->unaNota();
        $token = $this->tokenDelPersonalLlano();

        DB::update('UPDATE periodos SET profes_pueden_nivelar = 0 WHERE id = ?', [$nota->periodo_id]);

        $this->withToken($token)->putJson('/api/notas/nivelar/lote',
            ['notas' => [['id' => $nota->id, 'nota_nivelacion' => 45]]])->assertStatus(403);

        $this->assertNull($this->filaDe((int) $nota->id)->nota_original, 'Un 403 no escribe ni media nota.');
    }

    /** Los dos 422 que abortan el lote entero. */
    public function test_el_lote_vacio_o_de_mas_de_200_es_422(): void
    {
        $token = $this->tokenDeSuperusuario();
        $nota = $this->unaNota();

        $this->withToken($token)->putJson('/api/notas/nivelar/lote', ['notas' => []])->assertStatus(422);
        $this->withToken($token)->putJson('/api/notas/nivelar/lote', [])->assertStatus(422);

        $muchas = array_fill(0, 201, ['id' => $nota->id, 'nota_nivelacion' => 40]);

        $this->withToken($token)->putJson('/api/notas/nivelar/lote', ['notas' => $muchas])->assertStatus(422);
    }

    /** Y el lote deja **una línea de auditoría por nota**, como `notas/lote`. */
    public function test_el_lote_deja_una_linea_por_nota(): void
    {
        $token = $this->tokenDeSuperusuario();

        $notas = DB::select('SELECT n.id FROM notas n
            INNER JOIN subunidades s ON s.id = n.subunidad_id AND s.deleted_at IS NULL
            INNER JOIN unidades u ON u.id = s.unidad_id AND u.deleted_at IS NULL
            INNER JOIN periodos p ON p.id = u.periodo_id AND p.deleted_at IS NULL
            WHERE n.deleted_at IS NULL ORDER BY n.id LIMIT 2');

        $ids = array_map(fn ($n) => (int) $n->id, $notas);

        $this->withToken($token)->putJson('/api/notas/nivelar/lote', [
            'notas' => array_map(fn ($id) => ['id' => $id, 'nota_nivelacion' => 40], $ids),
        ])->assertStatus(200);

        $lineas = DB::select('SELECT entidad_id FROM auditoria WHERE entidad = "nota" AND accion = ?
            AND entidad_id IN ('.implode(',', $ids).')', [Auditoria::NIVELAR]);

        $this->assertCount(count($ids), $lineas, 'Una línea por nota: la pregunta es «quién tocó ESTA nota».');
    }
}
