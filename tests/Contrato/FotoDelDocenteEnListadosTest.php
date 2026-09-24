<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * La foto del docente viaja en los listados donde sale un docente.
 *
 * Regla de pantalla del 24 sep 2026: donde un listado nombra a un profesor, pinta
 * su foto. El front no puede pedirla aparte fila por fila, así que cada respuesta
 * trae **un campo más**, sin quitar ni renombrar nada —varias de estas rutas las lee
 * también la app Flutter—:
 *
 * - `foto_titular` en las filas de GRUPO (la del titular) y en la nota de
 *   comportamiento (que la pone el titular).
 * - `foto_profesor` en las filas con un docente de asignatura, o que son un docente.
 *
 * El valor es `images.nombre` de la foto OFICIAL (`profesores.foto_id`), y **`null`
 * cuando no hay**: nada de `default_male.png`, que es lo que ya trae `foto_nombre`
 * donde existe y lo que impide al front distinguir «sin foto» de «foto de verdad».
 */
class FotoDelDocenteEnListadosTest extends CasoDeContrato
{
    /** Cuarto 2025: su titular tiene foto oficial en el seed. */
    private const ANIO_CON_FOTO = 8;

    private const GRUPO_CON_FOTO = 98;

    public function test_grupos_trae_la_foto_del_titular_y_null_cuando_no_tiene(): void
    {
        $conFoto = $this->withToken($this->tokenDeSuperusuarioDe(self::ANIO_CON_FOTO))->getJson('/api/grupos');
        $conFoto->assertStatus(200);
        $this->cadaFilaLleva($conFoto->json(), 'foto_titular', 'grupos');

        $esperada = DB::selectOne('SELECT i.nombre FROM grupos g
            JOIN profesores p ON p.id = g.titular_id
            JOIN images i ON i.id = p.foto_id AND i.deleted_at IS NULL
            WHERE g.id = ?', [self::GRUPO_CON_FOTO]);
        $this->assertNotNull($esperada, 'El seed ya no le da foto al titular del grupo '.self::GRUPO_CON_FOTO.'.');

        $fila = collect($conFoto->json())->firstWhere('id', self::GRUPO_CON_FOTO);
        $this->assertSame($esperada->nombre, $fila['foto_titular']);

        // Y sin foto oficial, null: se le quita al mismo titular dentro de la transacción,
        // que el seed no garantiza un grupo del mismo año con titular sin foto.
        DB::update('UPDATE profesores SET foto_id = NULL WHERE id = ?', [$this->titularConFoto()]);

        $sinFoto = $this->withToken($this->tokenDeSuperusuarioDe(self::ANIO_CON_FOTO))->getJson('/api/grupos');
        $sinFoto->assertStatus(200);
        $fila = collect($sinFoto->json())->firstWhere('id', self::GRUPO_CON_FOTO);
        $this->assertArrayHasKey('foto_titular', $fila);
        $this->assertNull($fila['foto_titular'], 'Sin foto oficial tiene que llegar null, no una imagen por defecto.');
    }

    public function test_grupos_cant_alumnos(): void
    {
        $r = $this->withToken($this->tokenDeSuperusuarioDe(self::ANIO_CON_FOTO))->getJson('/api/grupos/cant-alumnos');
        $r->assertStatus(200);
        $this->cadaFilaLleva($r->json(), 'foto_titular', 'grupos/cant-alumnos');
        $this->assertNotNull(collect($r->json())->firstWhere('id', self::GRUPO_CON_FOTO)['foto_titular']);
    }

    public function test_actividades_edicion(): void
    {
        $asignatura = DB::selectOne('SELECT id FROM asignaturas WHERE grupo_id = ? AND deleted_at IS NULL ORDER BY id LIMIT 1',
            [self::GRUPO_CON_FOTO]);
        $periodo = DB::selectOne('SELECT id FROM periodos WHERE year_id = ? AND deleted_at IS NULL ORDER BY id LIMIT 1',
            [self::ANIO_CON_FOTO]);

        $actividad = DB::table('ws_actividades')->insertGetId([
            'asignatura_id' => $asignatura->id, 'periodo_id' => $periodo->id, 'descripcion' => 'Foto (test)',
            'tipo' => 'E', 'compartida' => 1, 'para_alumnos' => 1, 'can_upload' => 1, 'in_action' => 1,
            'duracion_preg' => 90, 'duracion_exam' => 3600, 'oportunidades' => 2, 'one_by_one' => 1,
            'tipo_calificacion' => 'Por puntos', 'contenido' => 'x', 'created_by' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $r = $this->withToken($this->tokenDeSuperusuarioDe(self::ANIO_CON_FOTO))
            ->putJson('/api/actividades/edicion', ['actividad_id' => $actividad]);
        $r->assertStatus(200);
        $this->cadaFilaLleva($r->json('grupos'), 'foto_titular', 'actividades/edicion');
    }

    public function test_planillas_ver_simat(): void
    {
        $r = $this->withToken($this->tokenDeSuperusuarioDe(self::ANIO_CON_FOTO))->getJson('/api/planillas/ver-simat');
        $r->assertStatus(200);
        $this->cadaFilaLleva($r->json(), 'foto_titular', 'planillas/ver-simat');
    }

    public function test_myimages(): void
    {
        $r = $this->withToken($this->tokenDeSuperusuarioDe(self::ANIO_CON_FOTO))->getJson('/api/myimages');
        $r->assertStatus(200);
        $this->cadaFilaLleva($r->json('grupos'), 'foto_titular', 'myimages');
    }

    public function test_changes_asked_to_me(): void
    {
        $r = $this->withToken($this->tokenDeSuperusuarioDe(self::ANIO_CON_FOTO))->getJson('/api/ChangesAsked/to-me');
        $r->assertStatus(200);
        $this->cadaFilaLleva($r->json('profes_actuales'), 'foto_profesor', 'ChangesAsked/to-me');
    }

    public function test_asignaturas_papelera(): void
    {
        DB::update('UPDATE asignaturas SET deleted_at = NOW() WHERE id = (
            SELECT id FROM (SELECT id FROM asignaturas WHERE grupo_id = ? AND deleted_at IS NULL ORDER BY id LIMIT 1) x)',
            [self::GRUPO_CON_FOTO]);

        $r = $this->withToken($this->tokenDeSuperusuarioDe(self::ANIO_CON_FOTO))->getJson('/api/asignaturas/papelera');
        $r->assertStatus(200);
        $this->cadaFilaLleva($r->json(), 'foto_profesor', 'asignaturas/papelera');
    }

    public function test_los_tres_listados_de_compromisos(): void
    {
        $token = $this->tokenDe($this->usuarioDeTipo('Usuario')->username);
        $compromiso = $this->crearUnCompromiso($token);

        $this->withToken($token)->putJson('/api/compromisos/'.$compromiso['id'].'/entregar', ['canal' => 'papel'])
            ->assertStatus(200);

        $index = $this->withToken($token)->getJson('/api/compromisos?year_id='.self::ANIO_CON_FOTO);
        $index->assertStatus(200);
        $items = collect($index->json('compromisos'))->firstWhere('id', $compromiso['id'])['items'] ?? null;
        $this->cadaFilaLleva($items, 'foto_profesor', 'compromisos');

        $item = DB::selectOne('SELECT u.username FROM compromiso_items ci
            JOIN profesores p ON p.id = ci.profesor_id
            JOIN users u ON u.id = p.user_id AND u.deleted_at IS NULL
            WHERE ci.compromiso_id = ? ORDER BY ci.id LIMIT 1', [$compromiso['id']]);
        $this->assertNotNull($item, 'El compromiso no tiene ningún ítem con un docente con cuenta.');

        $mios = $this->withToken($this->tokenDe($item->username))->getJson('/api/compromisos/mios?year_id='.self::ANIO_CON_FOTO);
        $mios->assertStatus(200);
        $this->cadaFilaLleva($mios->json('items'), 'foto_profesor', 'compromisos/mios');

        $familia = $this->withToken($token)->getJson('/api/compromisos/de-alumno/'.$compromiso['alumno_id']);
        $familia->assertStatus(200);
        $this->cadaFilaLleva($familia->json('compromisos.0.items'), 'foto_profesor', 'compromisos/de-alumno');
    }

    public function test_disciplina_alumnos(): void
    {
        $alumno = $this->alumnoDelGrupoConFoto();
        $periodo = DB::selectOne('SELECT id FROM periodos WHERE year_id = ? AND deleted_at IS NULL ORDER BY numero LIMIT 1',
            [self::ANIO_CON_FOTO]);

        DB::table('dis_procesos')->insert([
            'descripcion' => 'Foto (test)', 'alumno_id' => $alumno, 'year_id' => self::ANIO_CON_FOTO,
            'periodo_id' => $periodo->id, 'tipo_situacion' => 1, 'profesor_id' => $this->titularConFoto(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $r = $this->withToken($this->tokenDeSuperusuarioDe(self::ANIO_CON_FOTO))
            ->putJson('/api/disciplina/alumnos', ['grupo_id' => self::GRUPO_CON_FOTO, 'year_id' => self::ANIO_CON_FOTO]);
        $r->assertStatus(200);

        $situaciones = $this->buscarListas($r->json('alumnos'), 'profesor_nombre');
        $this->cadaFilaLleva($situaciones, 'foto_profesor', 'disciplina/alumnos');
        $this->assertNotNull($situaciones[0]['foto_profesor']);
    }

    public function test_horario_lecciones(): void
    {
        $anio = (int) DB::selectOne('SELECT p.year_id FROM users u JOIN periodos p ON p.id = u.periodo_id WHERE u.id = ?',
            [$this->usuarioLlanoDelPersonal()->id])->year_id;
        $asignatura = DB::selectOne('SELECT a.id, a.profesor_id FROM asignaturas a JOIN grupos g ON g.id = a.grupo_id
            WHERE g.year_id = ? AND a.deleted_at IS NULL AND g.deleted_at IS NULL ORDER BY a.id LIMIT 1', [$anio]);
        $this->assertNotNull($asignatura);

        DB::insert('INSERT INTO horario_versiones (year_id, nombre, subida_por, proyecto, comprobaciones, created_at, updated_at)
            VALUES (?, ?, NULL, ?, NULL, NOW(), NOW())', [$anio, 'Foto (test)', json_encode(['formato' => 1, 'proyecto' => [
            'anio' => 2025, 'jornadaPorDefecto' => ['dias' => [1, 2, 3, 4, 5], 'franjas' => 7, 'descansosTras' => [3, 5], 'timbres' => null],
            'niveles' => [], 'grupos' => [], 'docentes' => [], 'salones' => [], 'asignaciones' => [], 'piezas' => [], 'colocaciones' => [],
        ]])]);
        $version = (int) DB::getPdo()->lastInsertId();

        DB::insert('INSERT INTO horario_lecciones (version_id, pieza_id, asignatura_id, dia, franja, duracion, salon, salon_capacidad_grupos)
            VALUES (?, ?, ?, 1, 1, 1, NULL, NULL)', [$version, 'a1-0', $asignatura->id]);
        DB::insert('INSERT INTO horario_pieza_docente (version_id, pieza_id, profesor_id) VALUES (?, ?, ?)',
            [$version, 'a1-0', $this->titularConFoto()]);

        $r = $this->withToken($this->tokenDelPersonalLlano())->getJson("/api/horario/versiones/{$version}/lecciones");
        $r->assertStatus(200);
        $this->cadaFilaLleva($r->json('lecciones.0.docentes'), 'foto_profesor', 'horario/versiones/{id}/lecciones');
        $this->assertNotNull($r->json('lecciones.0.docentes.0.foto_profesor'));
    }

    public function test_detalles_grupos_periodos(): void
    {
        $r = $this->withToken($this->tokenDeSuperusuarioDe(self::ANIO_CON_FOTO))->putJson('/api/detalles/grupos-periodos', [
            'year_id' => self::ANIO_CON_FOTO,
            'alumno_id' => $this->alumnoConComportamiento(),
        ]);
        $r->assertStatus(200);

        $asignaturas = [];
        foreach ($this->buscarListas($r->json(), 'nombres_profesor') as $fila) {
            $asignaturas[] = $fila;
        }
        $this->cadaFilaLleva($asignaturas, 'foto_profesor', 'detalles/grupos-periodos');
    }

    public function test_notas_alumno_con_la_del_titular_en_el_comportamiento(): void
    {
        $alumno = $this->alumnoConComportamiento();
        $r = $this->withToken($this->tokenDeSuperusuarioDe(self::ANIO_CON_FOTO))
            ->getJson("/api/notas/alumno/{$alumno}/".self::GRUPO_CON_FOTO);
        $r->assertStatus(200);

        $this->cadaFilaLleva($this->buscarListas($r->json(), 'nombres_profesor'), 'foto_profesor', 'notas/alumno');

        $comportamientos = array_values(array_filter(
            array_map(fn ($f) => $f['nota_comportamiento'], $this->buscarListas($r->json(), 'nota_comportamiento')),
            fn ($c) => is_array($c) && $c !== []
        ));
        $this->cadaFilaLleva($comportamientos, 'foto_titular', 'notas/alumno (comportamiento)');
        $this->assertNotNull($comportamientos[0]['foto_titular']);
    }

    public function test_notas_alumno_periodo_grupo(): void
    {
        $alumno = $this->alumnoConComportamiento();
        $periodo = DB::selectOne('SELECT periodo_id FROM nota_comportamiento WHERE alumno_id = ? AND deleted_at IS NULL ORDER BY periodo_id LIMIT 1', [$alumno]);

        $r = $this->withToken($this->tokenDeSuperusuarioDe(self::ANIO_CON_FOTO))->putJson('/api/notas/alumno-periodo-grupo', [
            'alumno_id' => $alumno, 'periodo_id' => $periodo->periodo_id, 'grupo_id' => self::GRUPO_CON_FOTO,
        ]);
        $r->assertStatus(200);

        $this->cadaFilaLleva($this->buscarListas($r->json(), 'nombres_profesor'), 'foto_profesor', 'notas/alumno-periodo-grupo');
        $this->assertArrayHasKey('foto_titular', $this->buscarUno($r->json(), 'nota_comportamiento'));
    }

    public function test_boletin_independiente_alumno(): void
    {
        $alumno = $this->alumnoDelGrupoConFoto();
        $periodo = DB::selectOne('SELECT id FROM periodos WHERE year_id = ? AND deleted_at IS NULL ORDER BY numero LIMIT 1',
            [self::ANIO_CON_FOTO]);
        $this->marcarIndependiente($alumno, (int) $periodo->id);

        $r = $this->withToken($this->tokenDeSuperusuarioDe(self::ANIO_CON_FOTO))
            ->putJson('/api/boletin-independiente/alumno', ['alumno_id' => $alumno, 'periodo_id' => $periodo->id]);
        $r->assertStatus(200);
        $this->cadaFilaLleva($this->buscarListas($r->json(), 'nombres_profesor'), 'foto_profesor', 'boletin-independiente/alumno');
    }

    // --- ayudas -------------------------------------------------------------

    private function cadaFilaLleva(?array $filas, string $campo, string $ruta): void
    {
        $this->assertNotEmpty($filas, "`{$ruta}` no trajo ninguna fila: el test no estaría comprobando nada.");

        foreach ($filas as $i => $fila) {
            $this->assertIsArray($fila, "`{$ruta}`: la fila {$i} no es un objeto.");
            $this->assertArrayHasKey($campo, $fila, "`{$ruta}`: a la fila {$i} le falta `{$campo}`.");
            $this->assertNotSame('default_male.png', $fila[$campo]);
            $this->assertNotSame('default_female.png', $fila[$campo]);
        }
    }

    /** Todas las filas (objetos) de la respuesta que llevan la clave dada, a cualquier profundidad. */
    private function buscarListas($nodo, string $clave): array
    {
        $halladas = [];

        if (is_array($nodo)) {
            if (array_key_exists($clave, $nodo)) {
                $halladas[] = $nodo;
            }
            foreach ($nodo as $hijo) {
                array_push($halladas, ...$this->buscarListas($hijo, $clave));
            }
        }

        return $halladas;
    }

    /** El primer valor no vacío de esa clave, a cualquier profundidad. */
    private function buscarUno($nodo, string $clave): array
    {
        foreach ($this->buscarListas($nodo, $clave) as $fila) {
            if (is_array($fila[$clave]) && $fila[$clave] !== []) {
                return $fila[$clave];
            }
        }

        $this->fail("La respuesta no trae ningún `{$clave}` con datos.");
    }

    private function tokenDeSuperusuarioDe(int $yearId): string
    {
        $u = DB::selectOne('SELECT u.username FROM users u
            JOIN periodos p ON p.id = u.periodo_id AND p.deleted_at IS NULL
            WHERE u.tipo = "Usuario" AND u.is_superuser = 1 AND u.is_active = 1 AND u.deleted_at IS NULL
              AND p.year_id = ? ORDER BY u.id LIMIT 1', [$yearId]);
        $this->assertNotNull($u, "El seed no tiene superusuario en el año {$yearId}.");

        return $this->tokenDe($u->username);
    }

    private function titularConFoto(): int
    {
        return (int) DB::selectOne('SELECT titular_id FROM grupos WHERE id = ?', [self::GRUPO_CON_FOTO])->titular_id;
    }

    private function alumnoDelGrupoConFoto(): int
    {
        return (int) DB::selectOne('SELECT m.alumno_id FROM matriculas m JOIN alumnos a ON a.id = m.alumno_id AND a.deleted_at IS NULL
            WHERE m.grupo_id = ? AND m.deleted_at IS NULL AND m.estado IN ("MATR","ASIS") ORDER BY m.alumno_id LIMIT 1',
            [self::GRUPO_CON_FOTO])->alumno_id;
    }

    private function alumnoConComportamiento(): int
    {
        $fila = DB::selectOne('SELECT n.alumno_id FROM nota_comportamiento n
            JOIN matriculas m ON m.alumno_id = n.alumno_id AND m.grupo_id = ? AND m.deleted_at IS NULL
            WHERE n.deleted_at IS NULL ORDER BY n.alumno_id LIMIT 1', [self::GRUPO_CON_FOTO]);
        $this->assertNotNull($fila, 'Nadie del grupo '.self::GRUPO_CON_FOTO.' tiene nota de comportamiento en el seed.');

        return (int) $fila->alumno_id;
    }

    /** Lo mismo que `CompromisosDelAlumnoTest::crearUnCompromiso`: Cuarto 2025, periodo 2, corte 3. */
    private function crearUnCompromiso(string $token): array
    {
        $candidatos = $this->withToken($token)->putJson('/api/compromisos/candidatos', [
            'year_id' => self::ANIO_CON_FOTO, 'periodo' => 2, 'grupo_id' => self::GRUPO_CON_FOTO,
            'regla' => 'asignatura', 'corte' => 3,
        ])->assertStatus(200)->json('candidatos');
        $this->assertNotSame([], $candidatos, 'El seed no da candidatos a compromiso en Cuarto 2025.');

        $r = $this->withToken($token)->postJson('/api/compromisos', [
            'year_id' => self::ANIO_CON_FOTO, 'periodo' => 2, 'regla' => 'asignatura', 'corte' => 3,
            'matricula_ids' => [$candidatos[0]['matricula_id']],
        ]);
        $r->assertStatus(200);
        $this->assertSame(1, $r->json('creados'), 'El compromiso no se creó: '.json_encode($r->json('omitidos')));

        return ['id' => (int) $r->json('ids.0'), 'alumno_id' => (int) $candidatos[0]['alumno_id']];
    }
}
