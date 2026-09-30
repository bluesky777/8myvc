<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * Los listados de auditoría de alumnos: `GET auditoria/alumnos/{familia}` y
 * `GET auditoria/alumnos/actores`, el contrato de la pantalla de app2
 * (`myvc_front/docs/auditoria-alumnos/mock.html`).
 *
 * En la base de tests `auditoria` nace vacía: cada test siembra sus líneas dentro de
 * su transacción, con un alumno inventado (`ALUMNO`) donde el filtro lo permite, para
 * que las líneas que deja el propio login no se cuelen en los recuentos.
 */
class AuditoriaListadosDeAlumnosTest extends CasoDeContrato
{
    /** Un `alumno_id` que no existe en el seed: las líneas de auditoría no exigen la fila. */
    private const ALUMNO = 987654;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $yo = $this->usuarioLlanoDelPersonal();
        $this->darPermisoDeAuditoria((int) $yo->id);
        $this->token = $this->tokenDe($yo->username);
    }

    /** Escribe una línea de auditoría con lo mínimo y lo que se le pase. */
    private function linea(array $campos): int
    {
        return (int) DB::table('auditoria')->insertGetId($campos + [
            'accion' => 'editar',
            'entidad' => 'nota',
            'entidad_id' => 1,
            'alumno_id' => self::ALUMNO,
            'alumno_nombre' => 'ALUMNA DE PRUEBA',
            'actor_user_id' => 1,
            'actor_nombre' => 'Actor de prueba',
            'actor_tipo' => 'Profesor',
            'atribucion' => 'sesion',
            'ocurrido_en' => '2026-09-10 10:00:00.000',
        ]);
    }

    private function listado(string $familia, array $query = []): array
    {
        $r = $this->withToken($this->token)->getJson('/api/auditoria/alumnos/'.$familia.'?'.http_build_query($query));
        $r->assertStatus(200);

        return $r->json();
    }

    /** @return list<int> */
    private function ids(array $cuerpo): array
    {
        return array_column($cuerpo['acciones'], 'id');
    }

    public function test_pagina_y_total_sobre_el_mismo_filtro(): void
    {
        $ids = [];
        for ($i = 0; $i < 30; $i++) {
            // Dos por minuto: el mismo `ocurrido_en` en pares, para que el `id DESC` desempate.
            $ids[] = $this->linea([
                'entidad_id' => 100 + $i,
                'actor_user_id' => 1 + $i % 3,
                'ocurrido_en' => sprintf('2026-09-10 10:%02d:00.000', intdiv($i, 2)),
            ]);
        }
        $esperado = array_reverse($ids);

        $primera = $this->listado('notas', ['alumno_id' => self::ALUMNO]);
        $this->assertSame('notas', $primera['familia']);
        $this->assertSame(1, $primera['pagina']);
        $this->assertSame(25, $primera['por_pagina']);
        $this->assertSame(30, $primera['total']);
        $this->assertSame(['alumnos' => 1, 'actores' => 3], $primera['resumen'],
            'El resumen cuenta sobre TODO el filtro, no sobre la página.');
        $this->assertSame(array_slice($esperado, 0, 25), $this->ids($primera));

        $segunda = $this->listado('notas', ['alumno_id' => self::ALUMNO, 'pagina' => 2]);
        $this->assertSame(30, $segunda['total']);
        $this->assertSame(array_slice($esperado, 25), $this->ids($segunda));

        $grande = $this->listado('notas', ['alumno_id' => self::ALUMNO, 'por_pagina' => 50]);
        $this->assertCount(30, $grande['acciones']);

        $contador = $this->listado('notas', ['alumno_id' => self::ALUMNO, 'solo_total' => 1]);
        $this->assertSame(30, $contador['total']);
        $this->assertSame($primera['resumen'], $contador['resumen']);
        $this->assertSame([], $contador['acciones'], 'Con `solo_total` no se lee página.');

        $raro = $this->listado('notas', ['alumno_id' => self::ALUMNO, 'por_pagina' => 7]);
        $this->assertSame(25, $raro['por_pagina'], 'Un tamaño fuera de 25|50|100 es 25.');
    }

    public function test_la_lista_vacia_es_200(): void
    {
        foreach (['datos', 'notas', 'convivencia'] as $familia) {
            $cuerpo = $this->listado($familia, ['alumno_id' => self::ALUMNO + 1]);
            $this->assertSame(0, $cuerpo['total']);
            $this->assertSame([], $cuerpo['acciones']);
            $this->assertSame(['alumnos' => 0, 'actores' => 0], $cuerpo['resumen']);
        }
    }

    public function test_una_familia_que_no_existe_es_404(): void
    {
        $this->withToken($this->token)->getJson('/api/auditoria/alumnos/otra')->assertStatus(404);
    }

    public function test_sin_el_permiso_no_se_ve(): void
    {
        $sinPermiso = DB::selectOne('SELECT u.username FROM users u
            WHERE u.tipo = "Usuario" AND u.is_active = 1 AND u.deleted_at IS NULL AND u.is_superuser = 0
              AND u.id NOT IN (SELECT ru.user_id FROM role_user ru)
            ORDER BY u.id DESC LIMIT 1');
        $this->assertNotNull($sinPermiso);
        $token = $this->tokenDe($sinPermiso->username);

        $this->withToken($token)->getJson('/api/auditoria/alumnos/notas')->assertStatus(403);
        $this->withToken($token)->getJson('/api/auditoria/alumnos/actores')->assertStatus(403);
    }

    public function test_los_tipos_filtran_por_su_entidad_y_viajan_en_cada_linea(): void
    {
        $nota = $this->linea(['entidad' => 'nota']);
        $final = $this->linea(['entidad' => 'nota_final']);
        $ausencia = $this->linea(['entidad' => 'ausencia']);
        $this->linea(['entidad' => 'comportamiento']);   // de otra familia: no sale

        $todas = $this->listado('notas', ['alumno_id' => self::ALUMNO]);
        $this->assertEqualsCanonicalizing([$nota, $final, $ausencia], $this->ids($todas));
        $tipos = array_column($todas['acciones'], 'tipo', 'id');
        $this->assertSame('nota', $tipos[$nota]);
        $this->assertSame('final', $tipos[$final]);
        $this->assertSame('ausencia', $tipos[$ausencia]);

        $this->assertSame([$final], $this->ids($this->listado('notas', ['alumno_id' => self::ALUMNO, 'tipos' => 'final'])));
        $this->assertEqualsCanonicalizing([$final, $ausencia],
            $this->ids($this->listado('notas', ['alumno_id' => self::ALUMNO, 'tipos' => 'final,ausencia'])));
        $this->assertCount(3, $this->listado('notas', ['alumno_id' => self::ALUMNO, 'tipos' => 'nadie'])['acciones'],
            'Una clave que no es de la familia se ignora; si no queda ninguna, son todas.');

        $conv = $this->listado('convivencia', ['alumno_id' => self::ALUMNO]);
        $this->assertSame(['comportamiento'], array_column($conv['acciones'], 'tipo'));
    }

    public function test_los_datos_de_acudiente_salen_sin_alumno(): void
    {
        $acudiente = $this->linea(['entidad' => 'acudiente', 'alumno_id' => null, 'alumno_nombre' => null,
            'actor_user_id' => 424242]);
        $parentesco = $this->linea(['entidad' => 'parentesco', 'actor_user_id' => 424242]);

        $cuerpo = $this->listado('datos', ['actor_user_id' => 424242]);
        $this->assertEqualsCanonicalizing([$acudiente, $parentesco], $this->ids($cuerpo));
        $this->assertSame(['acudientes'], array_values(array_unique(array_column($cuerpo['acciones'], 'tipo'))));
        $this->assertSame(1, $cuerpo['resumen']['alumnos'], 'COUNT(DISTINCT) no cuenta el alumno nulo.');

        $this->assertSame([$parentesco], $this->ids($this->listado('datos', ['alumno_id' => self::ALUMNO])));
    }

    public function test_el_filtro_de_actor_y_el_de_fechas(): void
    {
        $a = $this->linea(['actor_user_id' => 11, 'ocurrido_en' => '2026-09-01 00:00:00.000']);
        $b = $this->linea(['actor_user_id' => 12, 'ocurrido_en' => '2026-09-05 23:59:59.999']);
        $c = $this->linea(['actor_user_id' => 11, 'ocurrido_en' => '2026-09-06 00:00:00.000']);

        $base = ['alumno_id' => self::ALUMNO];
        $this->assertEqualsCanonicalizing([$a, $c], $this->ids($this->listado('notas', $base + ['actor_user_id' => 11])));
        $this->assertEqualsCanonicalizing([$a, $b],
            $this->ids($this->listado('notas', $base + ['desde' => '2026-09-01', 'hasta' => '2026-09-05'])),
            'Las dos fechas son inclusivas: `hasta` cubre el día entero.');
        $this->assertSame([$c], $this->ids($this->listado('notas', $base + ['desde' => '2026-09-06'])));

        $this->withToken($this->token)->getJson('/api/auditoria/alumnos/notas?desde=06/09/2026')->assertStatus(422);
        $this->withToken($this->token)->getJson('/api/auditoria/alumnos/notas?hasta=2026-02-30')->assertStatus(422);
    }

    public function test_el_filtro_de_anio_y_periodo_y_el_anio_derivado(): void
    {
        $periodo = DB::selectOne('SELECT p.id, p.numero, p.year_id, y.year FROM periodos p
            JOIN years y ON y.id = p.year_id WHERE p.deleted_at IS NULL
              AND EXISTS (SELECT 1 FROM grupos g WHERE g.year_id = p.year_id) ORDER BY p.id LIMIT 1');
        $otro = DB::selectOne('SELECT id FROM periodos WHERE year_id <> ? ORDER BY id LIMIT 1', [$periodo->year_id]);

        $conAnio = $this->linea(['year_id' => $periodo->year_id, 'periodo_id' => $periodo->id]);
        // Sin `year_id`: el año sale del periodo, para el filtro y para `anio`.
        $sinAnio = $this->linea(['year_id' => null, 'periodo_id' => $periodo->id]);
        $fuera = $this->linea(['year_id' => null, 'periodo_id' => $otro->id]);
        // Sin año ni periodo: el año sale del grupo de la línea.
        $grupo = DB::selectOne('SELECT id FROM grupos WHERE year_id = ? ORDER BY id LIMIT 1', [$periodo->year_id]);
        $porGrupo = $this->linea(['year_id' => null, 'periodo_id' => null, 'grupo_id' => $grupo->id]);

        $base = ['alumno_id' => self::ALUMNO];
        $cuerpo = $this->listado('notas', $base + ['year_id' => $periodo->year_id]);
        $this->assertEqualsCanonicalizing([$conAnio, $sinAnio, $porGrupo], $this->ids($cuerpo));
        foreach ($cuerpo['acciones'] as $linea) {
            $this->assertSame((int) $periodo->year, $linea['anio']);
            $this->assertSame($linea['id'] === $porGrupo ? null : (int) $periodo->numero, $linea['periodo_numero']);
        }

        $this->assertSame([$fuera], $this->ids($this->listado('notas', $base + ['periodo_id' => $otro->id])));
        $ficha = $this->linea(['entidad' => 'alumno', 'periodo_id' => $periodo->id]);
        $this->assertSame([$ficha], $this->ids($this->listado('datos', $base + ['periodo_id' => $otro->id])),
            'En datos el periodo no filtra.');
    }

    public function test_el_filtro_de_grupo_es_el_grupo_del_alumno(): void
    {
        $grupo = $this->grupoConAlumnos();
        $alumno = DB::selectOne('SELECT m.alumno_id FROM matriculas m WHERE m.grupo_id = ? AND m.deleted_at IS NULL
            ORDER BY m.id LIMIT 1', [$grupo->id]);
        $nombre = DB::table('grupos')->where('id', $grupo->id)->value('nombre');

        $suya = $this->linea(['alumno_id' => $alumno->alumno_id, 'year_id' => $grupo->year_id]);
        $otroAnio = $this->linea(['alumno_id' => $alumno->alumno_id, 'year_id' => $grupo->year_id + 1000]);
        $this->linea([]);   // el alumno inventado: en ningún grupo

        $cuerpo = $this->listado('notas', ['grupo_id' => $grupo->id, 'alumno_id' => $alumno->alumno_id]);
        $this->assertSame([$suya], $this->ids($cuerpo), 'Del año del grupo, no las de otro año.');
        $this->assertSame($nombre, $cuerpo['acciones'][0]['grupo_nombre']);
        $this->assertNotContains($otroAnio, $this->ids($cuerpo));
    }

    public function test_el_filtro_de_asignatura_sale_de_la_fila_de_la_nota(): void
    {
        $nota = DB::selectOne('SELECT n.id, n.alumno_id, u.asignatura_id, m.materia FROM notas n
            JOIN subunidades s ON s.id = n.subunidad_id
            JOIN unidades u ON u.id = s.unidad_id
            JOIN asignaturas asg ON asg.id = u.asignatura_id
            JOIN materias m ON m.id = asg.materia_id
            ORDER BY n.id LIMIT 1');

        // La línea de nota no lleva asignatura: se busca por la nota.
        $deLaNota = $this->linea(['entidad_id' => $nota->id, 'asignatura_id' => null]);
        // Una nota ya borrada que sí la llevaba en la línea.
        $borrada = $this->linea(['entidad_id' => 999999999, 'asignatura_id' => $nota->asignatura_id]);
        $this->linea(['entidad_id' => 999999998]);

        $cuerpo = $this->listado('notas', ['alumno_id' => self::ALUMNO, 'asignatura_id' => $nota->asignatura_id]);
        $this->assertEqualsCanonicalizing([$deLaNota, $borrada], $this->ids($cuerpo));
        foreach ($cuerpo['acciones'] as $linea) {
            $this->assertSame($nota->materia, $linea['asignatura_nombre']);
        }

        $frase = $this->linea(['entidad' => 'frase_asignatura', 'asignatura_id' => $nota->asignatura_id + 1]);
        $this->assertSame([$frase], $this->ids($this->listado('convivencia',
            ['alumno_id' => self::ALUMNO, 'asignatura_id' => $nota->asignatura_id])),
            'Fuera de notas la asignatura no filtra.');
    }

    public function test_la_busqueda_por_nombre_y_por_documento(): void
    {
        $alumno = DB::selectOne('SELECT id, documento FROM alumnos WHERE documento IS NOT NULL AND documento <> ""
            ORDER BY id LIMIT 1');

        $porNombre = $this->linea(['alumno_nombre' => 'ZOILA 100% DE PRUEBA']);
        $porDocumento = $this->linea(['alumno_id' => $alumno->id, 'alumno_nombre' => 'OTRO NOMBRE']);

        $this->assertSame([$porNombre], $this->ids($this->listado('notas', ['q' => 'zoila 100%'])));
        $this->assertSame([], $this->ids($this->listado('notas', ['q' => 'zoila 1_0'])), 'El `_` no es comodín.');
        $this->assertContains($porDocumento, $this->ids($this->listado('notas', ['q' => $alumno->documento])));
    }

    public function test_el_hueco_mira_la_linea_anterior_de_la_misma_nota_aunque_no_este_en_la_pagina(): void
    {
        $uno = $this->linea(['entidad_id' => 555, 'valor_anterior_num' => null, 'valor_nuevo_num' => 40,
            'ocurrido_en' => '2026-09-01 08:00:00.000']);
        // Otra nota en medio: no es la anterior de ésta.
        $this->linea(['entidad_id' => 556, 'valor_anterior_num' => 10, 'valor_nuevo_num' => 20,
            'ocurrido_en' => '2026-09-01 09:00:00.000']);
        $dos = $this->linea(['entidad_id' => 555, 'valor_anterior_num' => 40, 'valor_nuevo_num' => 45,
            'ocurrido_en' => '2026-09-02 08:00:00.000']);
        $tres = $this->linea(['entidad_id' => 555, 'valor_anterior_num' => 47, 'valor_nuevo_num' => 50,
            'ocurrido_en' => '2026-09-03 08:00:00.000']);
        // En una entidad no numérica no se mira.
        $ausencia = $this->linea(['entidad' => 'ausencia', 'entidad_id' => 555, 'valor_anterior_num' => 1,
            'ocurrido_en' => '2026-09-04 08:00:00.000']);

        $hueco = array_column($this->listado('notas', ['alumno_id' => self::ALUMNO])['acciones'], 'hueco', 'id');
        $this->assertFalse($hueco[$uno], 'La primera no tiene anterior: no se afirma nada.');
        $this->assertFalse($hueco[$dos]);
        $this->assertTrue($hueco[$tres], 'Vino de 47 y la anterior dejó 45.');
        $this->assertFalse($hueco[$ausencia]);

        // Sólo la tercera en la página: la anterior se busca igual.
        $sola = $this->listado('notas', ['alumno_id' => self::ALUMNO, 'desde' => '2026-09-03', 'tipos' => 'nota']);
        $this->assertSame([$tres], $this->ids($sola));
        $this->assertTrue($sola['acciones'][0]['hueco']);
    }

    public function test_hecho_desde_sale_del_ingreso(): void
    {
        $ingreso = fn (array $c) => (int) DB::table('historiales')->insertGetId($c + [
            'user_id' => 1, 'tipo' => 'login', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $casos = [
            'web_computador' => $ingreso(['entorno' => 'Desktop', 'browser_name' => 'Chrome 140', 'browser_family' => 'Chrome',
                'platform_name' => 'Blink', 'platform_family' => 'Windows']),
            'web_celular' => $ingreso(['entorno' => 'Mobile', 'browser_name' => 'Chrome Mobile 140', 'browser_family' => 'Chrome Mobile',
                'platform_family' => 'Android']),
            'app' => $ingreso(['entorno' => 'Bot', 'browser_name' => 'Unknown', 'browser_family' => 'Unknown',
                'platform_family' => 'Unknown']),
        ];
        $lineas = [];
        foreach ($casos as $origen => $historial) {
            $lineas[$this->linea(['historial_id' => $historial])] = $origen;
        }
        $sin = $this->linea(['historial_id' => null]);

        $desde = array_column($this->listado('notas', ['alumno_id' => self::ALUMNO])['acciones'], 'hecho_desde', 'id');
        foreach ($lineas as $id => $origen) {
            $this->assertSame($origen, $desde[$id]['origen']);
        }
        $computador = array_search('web_computador', $lineas, true);
        $this->assertSame(['origen' => 'web_computador', 'navegador' => 'Chrome', 'plataforma' => 'Windows'], $desde[$computador],
            'La plataforma es `platform_family`: `platform_name` guarda el motor.');
        $app = array_search('app', $lineas, true);
        $this->assertSame(['origen' => 'app', 'navegador' => null, 'plataforma' => null], $desde[$app]);
        $this->assertSame(['origen' => null, 'navegador' => null, 'plataforma' => null], $desde[$sin]);
    }

    public function test_la_forma_de_la_respuesta(): void
    {
        $this->linea(['valor_anterior' => json_encode(40), 'valor_nuevo' => json_encode(45),
            'valor_anterior_num' => 40, 'valor_nuevo_num' => 45]);

        $cuerpo = $this->listado('notas', ['alumno_id' => self::ALUMNO]);
        $this->compararConInstantanea('auditoria-alumnos-notas', $this->formaUnida($cuerpo));
    }

    public function test_los_actores_con_su_rol_legible(): void
    {
        // Dos docentes sin rol de cargo, ni a mano ni por nombramiento del año.
        $sinCargo = 'SELECT u.id FROM users u WHERE u.tipo = "Profesor" AND u.deleted_at IS NULL AND u.is_superuser = 0
            AND u.id NOT IN (SELECT ru.user_id FROM role_user ru JOIN roles r ON r.id = ru.role_id
                              WHERE r.name NOT IN ("Profesor", "Alumno", "Acudiente"))
            AND u.id NOT IN (SELECT p.user_id FROM profesores p JOIN years y ON y.actual = 1
                              AND p.id IN (y.secretario_id, y.tesorero_id) WHERE p.user_id IS NOT NULL)
            ORDER BY u.id LIMIT 2';
        [$docente, $rector] = DB::select($sinCargo);
        $rol = DB::table('roles')->where('name', 'Rector')->value('id')
            ?? DB::table('roles')->insertGetId(['name' => 'Rector', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('role_user')->insert(['user_id' => $rector->id, 'role_id' => $rol]);

        $this->linea(['actor_user_id' => $docente->id, 'actor_nombre' => 'Nombre viejo']);
        $this->linea(['actor_user_id' => $docente->id, 'actor_nombre' => 'ÁNGELA DOCENTE']);
        $this->linea(['entidad' => 'alumno', 'actor_user_id' => $rector->id, 'actor_nombre' => 'BERTA RECTORA']);
        $this->linea(['entidad' => 'year_config', 'actor_user_id' => 1, 'actor_nombre' => 'Fuera de las familias']);

        $r = $this->withToken($this->token)->getJson('/api/auditoria/alumnos/actores');
        $r->assertStatus(200);
        $actores = $r->json();

        $this->assertSame(['user_id', 'nombre', 'tipo', 'rol', 'foto'], array_keys($actores[0]));
        $porId = array_column($actores, null, 'user_id');
        $this->assertSame('ÁNGELA DOCENTE', $porId[$docente->id]['nombre'], 'El nombre de la última línea.');
        $this->assertSame('Profesor', $porId[$docente->id]['tipo']);
        $this->assertSame('Docente', $porId[$docente->id]['rol']);
        $this->assertSame('Rectoría', $porId[$rector->id]['rol']);
        $this->assertArrayNotHasKey(1, $porId, 'Sólo quien sale en las entidades de las tres familias.');

        $nombres = array_column($actores, 'nombre');
        $this->assertLessThan(array_search('BERTA RECTORA', $nombres, true), array_search('ÁNGELA DOCENTE', $nombres, true),
            'Por nombre, sin que la tilde lo mande al final.');
    }
}
