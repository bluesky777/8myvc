<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * La página `/auditoria` de app2 (contrato 2): lo que ve el docente sin el permiso de
 * auditoría —las notas de sus asignaturas y las de los alumnos del grupo del que es
 * titular, `AlcanceDelDocente`—, `auditoria/permisos`, la bitácora anterior paginada y
 * los ingresos paginados.
 *
 * Las líneas se siembran dentro de la transacción de cada test con el alumno inventado
 * `ALUMNO` donde se puede, para que no se cuele nada del seed. La titularidad también se
 * fabrica: el test pone al docente de titular de un grupo con alumnos, y se deshace sola.
 */
class AuditoriaDeLosDocentesTest extends CasoDeContrato
{
    /** Un `alumno_id` que no existe en el seed ni está matriculado en ningún grupo. */
    private const ALUMNO = 987654;

    /** Un `notas.id` que no existe: su línea no deriva asignatura de la fila. */
    private const NOTA_BORRADA = 999999999;

    /** @var object{username: string, user_id: int, profesor_id: int, nota_id: int, asignatura_id: int} */
    private object $docente;

    private string $tokenDocente;

    protected function setUp(): void
    {
        parent::setUp();

        // Un docente con una nota de verdad en una asignatura suya.
        $this->docente = DB::selectOne('SELECT u.username, u.id AS user_id, pr.id AS profesor_id,
                n.id AS nota_id, un.asignatura_id
            FROM notas n
            JOIN subunidades s ON s.id = n.subunidad_id
            JOIN unidades un ON un.id = s.unidad_id
            JOIN asignaturas asg ON asg.id = un.asignatura_id AND asg.deleted_at IS NULL
            JOIN profesores pr ON pr.id = asg.profesor_id AND pr.deleted_at IS NULL
            JOIN users u ON u.id = pr.user_id AND u.tipo = "Profesor" AND u.is_active = 1
                 AND u.deleted_at IS NULL AND u.is_superuser = 0
            JOIN periodos p ON p.id = u.periodo_id
            ORDER BY n.id LIMIT 1');
        $this->assertNotNull($this->docente, 'El seed no tiene ningún docente con notas en una asignatura suya.');
        $this->tokenDocente = $this->tokenDe($this->docente->username);
    }

    private function linea(array $campos): int
    {
        return (int) DB::table('auditoria')->insertGetId($campos + [
            'accion' => 'editar',
            'entidad' => 'nota',
            'entidad_id' => self::NOTA_BORRADA,
            'alumno_id' => self::ALUMNO,
            'alumno_nombre' => 'ALUMNA DE PRUEBA',
            'actor_user_id' => 1,
            'actor_nombre' => 'Coordinación de prueba',
            'actor_tipo' => 'Usuario',
            'atribucion' => 'sesion',
            'ocurrido_en' => '2026-09-10 10:00:00.000',
        ]);
    }

    /** Una nota de una asignatura que NO da el docente. */
    private function notaAjena(): object
    {
        $nota = DB::selectOne('SELECT n.id, un.asignatura_id FROM notas n
            JOIN subunidades s ON s.id = n.subunidad_id
            JOIN unidades un ON un.id = s.unidad_id
            JOIN asignaturas asg ON asg.id = un.asignatura_id
            WHERE asg.profesor_id IS NULL OR asg.profesor_id <> ?
            ORDER BY n.id LIMIT 1', [$this->docente->profesor_id]);
        $this->assertNotNull($nota);

        return $nota;
    }

    /**
     * Pone al docente de titular de un grupo con alumnos y devuelve el grupo, un alumno
     * suyo y un periodo de ese año.
     */
    private function hacerloTitular(): object
    {
        $grupo = $this->grupoConAlumnos();
        DB::table('grupos')->where('id', $grupo->id)->update(['titular_id' => $this->docente->profesor_id]);
        $grupo->alumno_id = (int) DB::table('matriculas')->where('grupo_id', $grupo->id)
            ->whereNull('deleted_at')->orderBy('id')->value('alumno_id');
        $grupo->periodo_id = (int) DB::table('periodos')->where('year_id', $grupo->year_id)->orderBy('id')->value('id');

        return $grupo;
    }

    private function listado(string $familia, array $query = [], ?string $token = null): array
    {
        $r = $this->withToken($token ?? $this->tokenDocente)
            ->getJson('/api/auditoria/alumnos/'.$familia.'?'.http_build_query($query));
        $r->assertStatus(200);

        return $r->json();
    }

    /** @return list<int> */
    private function ids(array $cuerpo): array
    {
        return array_column($cuerpo['acciones'], 'id');
    }

    public function test_el_docente_ve_lo_de_su_asignatura_aunque_lo_hiciera_otro(): void
    {
        // La línea de nota no trae asignatura: sale de la fila de la nota.
        $deSuNota = $this->linea(['entidad_id' => $this->docente->nota_id]);
        // La definitiva la trae en la línea.
        $deSuFinal = $this->linea(['entidad' => 'nota_final', 'entidad_id' => 1,
            'asignatura_id' => $this->docente->asignatura_id]);
        $ajena = $this->linea(['entidad_id' => $this->notaAjena()->id]);

        $cuerpo = $this->listado('notas', ['alumno_id' => self::ALUMNO]);
        $this->assertEqualsCanonicalizing([$deSuNota, $deSuFinal], $this->ids($cuerpo));
        $this->assertNotContains($ajena, $this->ids($cuerpo));
        $this->assertSame(2, $cuerpo['total']);
        $this->assertSame(['alumnos' => 1, 'actores' => 1], $cuerpo['resumen']);
        $this->assertSame('Coordinación de prueba', $cuerpo['acciones'][0]['actor_nombre'],
            'Lo hizo otro, y el docente lo ve.');
    }

    public function test_el_titular_ve_la_nota_de_su_alumno_en_una_asignatura_que_no_da(): void
    {
        $grupo = $this->hacerloTitular();
        $alumno = $grupo->alumno_id;
        $ajena = $this->notaAjena();

        $deSuAlumno = $this->linea(['alumno_id' => $alumno, 'entidad_id' => $ajena->id, 'year_id' => $grupo->year_id]);
        // Sin año en la línea: el de su periodo.
        $porPeriodo = $this->linea(['alumno_id' => $alumno, 'year_id' => null, 'periodo_id' => $grupo->periodo_id]);
        // El mismo alumno en otro año: el grupo no era suyo.
        $otroAnio = $this->linea(['alumno_id' => $alumno, 'entidad_id' => $ajena->id, 'year_id' => $grupo->year_id + 1000]);
        // Sin año, ni periodo, ni grupo: no se sabe de qué año es, y no entra.
        $sinAnio = $this->linea(['alumno_id' => $alumno, 'year_id' => null]);

        $ids = $this->ids($this->listado('notas', ['alumno_id' => $alumno]));
        $this->assertEqualsCanonicalizing([$deSuAlumno, $porPeriodo], $ids);
        $this->assertNotContains($otroAnio, $ids);
        $this->assertNotContains($sinAnio, $ids);

        // Y también por el filtro de asignatura de una que no da.
        $this->assertSame([$deSuAlumno], $this->ids($this->listado('notas',
            ['alumno_id' => $alumno, 'asignatura_id' => $ajena->asignatura_id])));
    }

    public function test_el_docente_no_ve_la_de_otro_grupo_ni_la_de_otra_asignatura_ni_manipulando_filtros(): void
    {
        $grupo = $this->hacerloTitular();
        $ajena = $this->notaAjena();
        // Un alumno de otro grupo, que se deja sin titular. En el seed sólo hay dos grupos
        // con alumnos, de dos años, y son los mismos alumnos: la línea del año del otro
        // grupo es la de un alumno que fue suyo, pero no ese año.
        $otro = DB::selectOne('SELECT g.id, g.year_id FROM grupos g
            JOIN matriculas m ON m.grupo_id = g.id AND m.deleted_at IS NULL
            WHERE g.id <> ? ORDER BY g.id LIMIT 1', [$grupo->id]);
        $this->assertNotNull($otro, 'El seed necesita dos grupos con alumnos.');
        $this->assertNotEquals($grupo->year_id, $otro->year_id);
        DB::table('grupos')->where('id', $otro->id)->update(['titular_id' => null]);
        $otroAlumno = (int) DB::table('matriculas')->where('grupo_id', $otro->id)->whereNull('deleted_at')
            ->orderBy('id')->value('alumno_id');
        $anioDelOtro = (int) $otro->year_id;

        $deOtroGrupo = $this->linea(['alumno_id' => $otroAlumno, 'entidad_id' => $ajena->id, 'year_id' => $anioDelOtro,
            'actor_user_id' => 424242]);
        $deOtraAsignatura = $this->linea(['entidad_id' => $ajena->id, 'asignatura_id' => $ajena->asignatura_id,
            'actor_user_id' => 424242]);

        foreach ([
            ['alumno_id' => $otroAlumno],
            ['asignatura_id' => $ajena->asignatura_id],
            ['actor_user_id' => 424242],
            ['grupo_id' => $grupo->id, 'alumno_id' => $otroAlumno],
            ['tipos' => 'nota,final,nivelacion,ausencia'],
        ] as $filtro) {
            $cuerpo = $this->listado('notas', $filtro);
            $this->assertNotContains($deOtroGrupo, $this->ids($cuerpo), json_encode($filtro));
            $this->assertNotContains($deOtraAsignatura, $this->ids($cuerpo), json_encode($filtro));
        }
        $this->assertSame(0, $this->listado('notas', ['actor_user_id' => 424242, 'solo_total' => 1])['total'],
            'Ni el recuento de las pestañas deja ver lo de fuera.');

        // Los actores: quien sólo sale fuera del alcance no aparece en el desplegable.
        $actores = $this->withToken($this->tokenDocente)->getJson('/api/auditoria/alumnos/actores')
            ->assertStatus(200)->json();
        $this->assertNotContains(424242, array_column($actores, 'user_id'));
    }

    public function test_los_actores_del_docente_son_los_de_su_alcance(): void
    {
        $this->linea(['entidad_id' => $this->docente->nota_id, 'actor_user_id' => 434343, 'actor_nombre' => 'DENTRO']);
        $this->linea(['entidad_id' => $this->notaAjena()->id, 'actor_user_id' => 454545, 'actor_nombre' => 'FUERA']);
        // Una línea de convivencia de su asignatura tampoco: el docente no tiene esa familia.
        $this->linea(['entidad' => 'frase_asignatura', 'asignatura_id' => $this->docente->asignatura_id,
            'actor_user_id' => 464646]);

        $ids = array_column($this->withToken($this->tokenDocente)->getJson('/api/auditoria/alumnos/actores')
            ->assertStatus(200)->json(), 'user_id');
        $this->assertContains(434343, $ids);
        $this->assertNotContains(454545, $ids);
        $this->assertNotContains(464646, $ids);
    }

    public function test_datos_y_convivencia_dan_403_al_docente(): void
    {
        $this->withToken($this->tokenDocente)->getJson('/api/auditoria/alumnos/datos')->assertStatus(403);
        $this->withToken($this->tokenDocente)->getJson('/api/auditoria/alumnos/convivencia')->assertStatus(403);
        $this->withToken($this->tokenDocente)->getJson('/api/auditoria/alumnos/otra')->assertStatus(404);
    }

    public function test_el_cajon_de_una_entidad_dentro_y_fuera_de_su_alcance(): void
    {
        $dentro = $this->linea(['entidad_id' => $this->docente->nota_id]);
        $ajena = $this->notaAjena();
        $this->linea(['entidad_id' => $ajena->id]);
        $this->linea(['entidad' => 'comportamiento', 'entidad_id' => 77]);

        $r = $this->withToken($this->tokenDocente)->getJson('/api/auditoria/entidad/nota/'.$this->docente->nota_id);
        $r->assertStatus(200);
        $this->assertContains($dentro, array_column($r->json('acciones'), 'id'));

        $this->withToken($this->tokenDocente)->getJson('/api/auditoria/entidad/nota/'.$ajena->id)->assertStatus(403);
        $this->withToken($this->tokenDocente)->getJson('/api/auditoria/entidad/comportamiento/77')->assertStatus(403);
        $this->withToken($this->tokenDocente)->getJson('/api/auditoria/entidad/nota/'.(self::NOTA_BORRADA - 1))
            ->assertStatus(403);

        // La del alumno de su grupo, aunque la asignatura no sea suya.
        $grupo = $this->hacerloTitular();
        $this->linea(['entidad_id' => $ajena->id, 'alumno_id' => $grupo->alumno_id, 'year_id' => $grupo->year_id]);
        $this->withToken($this->tokenDocente)->getJson('/api/auditoria/entidad/nota/'.$ajena->id)->assertStatus(200);

        // Con el permiso, cualquiera.
        $admin = $this->usuarioLlanoDelPersonal();
        $this->darPermisoDeAuditoria((int) $admin->id);
        $this->withToken($this->tokenDe($admin->username))->getJson('/api/auditoria/entidad/comportamiento/77')
            ->assertStatus(200);
    }

    public function test_los_permisos_dicen_que_pestanas_se_ven(): void
    {
        $this->withToken($this->tokenDocente)->getJson('/api/auditoria/permisos')->assertStatus(200)->assertExactJson([
            'familias' => ['notas'], 'ingresos_de_otros' => false, 'bitacora' => false, 'alcance' => 'docente',
            'limpieza' => false,
        ]);

        $llano = $this->usuarioLlanoDelPersonal();
        $this->withToken($this->tokenDe($llano->username))->getJson('/api/auditoria/permisos')->assertExactJson([
            'familias' => [], 'ingresos_de_otros' => false, 'bitacora' => false, 'alcance' => 'ninguno',
            'limpieza' => false,
        ]);

        $this->darPermisoDeAuditoria((int) $llano->id);
        $this->withToken($this->tokenDe($llano->username))->getJson('/api/auditoria/permisos')->assertExactJson([
            'familias' => ['datos', 'notas', 'convivencia'], 'ingresos_de_otros' => true, 'bitacora' => true,
            'alcance' => 'todo', 'limpieza' => false,
        ]);

        // Con el permiso, el docente lo ve todo como cualquiera.
        $this->darPermisoDeAuditoria((int) $this->docente->user_id);
        $this->withToken($this->tokenDe($this->docente->username))->getJson('/api/auditoria/permisos')
            ->assertJsonPath('alcance', 'todo');
    }

    public function test_la_bitacora_anterior_paginada(): void
    {
        $admin = $this->usuarioLlanoDelPersonal();
        $this->darPermisoDeAuditoria((int) $admin->id);
        $token = $this->tokenDe($admin->username);
        $autor = (int) $this->docente->user_id;

        $ids = [];
        for ($i = 0; $i < 30; $i++) {
            $ids[] = (int) DB::table('bitacoras')->insertGetId([
                'created_by' => $autor,
                'descripcion' => $i === 7 ? 'Cambió la nota de ZOILA_PRUEBA' : 'Línea '.$i,
                'affected_element_type' => 'Nota',
                'affected_element_old_value_int' => 40,
                'affected_element_new_value_int' => 45,
                'created_at' => sprintf('2026-09-%02d 10:00:00', 1 + intdiv($i, 3)),
            ]);
        }
        // Una sobre un alumno: su nombre sale de su ficha por `affected_user_id`.
        $alumno = DB::selectOne('SELECT id, user_id, nombres, apellidos FROM alumnos
            WHERE user_id IS NOT NULL AND deleted_at IS NULL ORDER BY id LIMIT 1');
        DB::table('bitacoras')->where('id', $ids[29])->update(['affected_user_id' => $alumno->user_id]);
        $borrada = (int) DB::table('bitacoras')->insertGetId(['created_by' => $autor, 'descripcion' => 'Borrada',
            'created_at' => '2026-09-05 10:00:00', 'deleted_at' => '2026-09-06 10:00:00']);
        $esperado = array_reverse($ids);

        $pedir = fn (array $q) => $this->withToken($token)->getJson('/api/auditoria/bitacora-anterior?'.http_build_query($q));

        $primera = $pedir(['user_id' => $autor])->assertStatus(200)->json();
        $this->assertSame(['pagina', 'por_pagina', 'total', 'filas'], array_keys($primera));
        $this->assertSame([1, 25, 30], [$primera['pagina'], $primera['por_pagina'], $primera['total']]);
        $this->assertSame(array_slice($esperado, 0, 25), array_column($primera['filas'], 'id'));
        $this->assertNotContains($borrada, array_column($primera['filas'], 'id'), 'Lo borrado no sale.');
        $fila = $primera['filas'][0];
        $this->assertNotEmpty($fila['actor_nombre']);
        $this->assertSame('Profesor', $fila['actor_tipo']);
        $this->assertArrayHasKey('actor_foto', $fila);
        $this->assertSame(trim($alumno->nombres.' '.$alumno->apellidos), $fila['alumno_nombre']);
        $this->assertArrayHasKey('alumno_foto', $fila);
        $this->assertNull($primera['filas'][1]['alumno_nombre'], 'Sin alumno afectado, sin alumno.');

        $segunda = $pedir(['user_id' => $autor, 'pagina' => 2])->json();
        $this->assertSame(array_slice($esperado, 25), array_column($segunda['filas'], 'id'));

        $this->assertSame([$ids[7]], array_column($pedir(['user_id' => $autor, 'q' => 'zoila_prueba'])->json('filas'), 'id'));
        $this->assertSame(0, $pedir(['user_id' => $autor, 'q' => 'zoila%prueba'])->json('total'), 'El `%` no es comodín.');
        $this->assertSame(6, $pedir(['user_id' => $autor, 'desde' => '2026-09-02', 'hasta' => '2026-09-03'])->json('total'),
            'Las dos fechas son inclusivas.');
        $pedir(['desde' => '02/09/2026'])->assertStatus(422);

        $this->withToken($this->tokenDocente)->getJson('/api/auditoria/bitacora-anterior')->assertStatus(403);
    }

    public function test_los_ingresos_paginados_y_sin_pedirlo_como_siempre(): void
    {
        $yo = (int) $this->docente->user_id;
        $hoy = now('America/Bogota')->toDateString();
        $ids = [];
        for ($i = 0; $i < 30; $i++) {
            $ids[] = (int) DB::table('historiales')->insertGetId([
                'user_id' => $yo, 'tipo' => 'login', 'ip' => '127.0.0.1',
                'created_at' => $hoy.' 08:00:00', 'updated_at' => $hoy.' 08:00:00',
            ]);
        }
        $pedir = fn (array $q, ?string $token = null) => $this->withToken($token ?? $this->tokenDocente)
            ->getJson('/api/auditoria/ingresos?'.http_build_query($q));

        // Sin paginación, la forma de siempre (el login del propio test también cuenta).
        $siempre = $pedir([])->assertStatus(200)->json();
        $this->assertSame(['user_id', 'desde', 'hasta', 'ingresos', 'hay_mas'], array_keys($siempre));
        $total = count($siempre['ingresos']);
        $this->assertGreaterThanOrEqual(30, $total);

        $primera = $pedir(['pagina' => 1, 'por_pagina' => 25])->assertStatus(200)->json();
        $this->assertSame(['user_id', 'desde', 'hasta', 'pagina', 'por_pagina', 'total', 'ingresos', 'hay_mas'],
            array_keys($primera));
        $this->assertSame($total, $primera['total']);
        $this->assertCount(25, $primera['ingresos']);
        $this->assertTrue($primera['hay_mas']);
        $this->assertSame(array_slice(array_column($siempre['ingresos'], 'id'), 0, 25), array_column($primera['ingresos'], 'id'));
        $this->assertArrayNotHasKey('hecho_desde', $siempre['ingresos'][0], 'Sin paginación, la fila de siempre.');
        $this->assertSame(array_keys($siempre['ingresos'][0] + ['hecho_desde' => null]), array_keys($primera['ingresos'][0]));

        $segunda = $pedir(['pagina' => 2, 'por_pagina' => 25])->json();
        $this->assertSame(array_slice(array_column($siempre['ingresos'], 'id'), 25), array_column($segunda['ingresos'], 'id'));
        $this->assertFalse($segunda['hay_mas']);
        $this->assertContains($ids[0], array_column($segunda['ingresos'], 'id'));

        // `hecho_desde`, con la regla del listado de alumnos.
        DB::table('historiales')->where('id', $ids[0])->update(['entorno' => 'Mobile',
            'browser_family' => 'Chrome Mobile', 'platform_family' => 'Android']);
        $movil = array_column($pedir(['pagina' => 2, 'por_pagina' => 25])->json('ingresos'), 'hecho_desde', 'id')[$ids[0]];
        $this->assertSame(['origen' => 'web_celular', 'navegador' => 'Chrome Mobile', 'plataforma' => 'Android'], $movil);

        // Los de otro, sólo con el permiso.
        $pedir(['user_id' => 1, 'pagina' => 1])->assertStatus(403);
        $admin = $this->usuarioLlanoDelPersonal();
        $this->darPermisoDeAuditoria((int) $admin->id);
        $this->assertSame($total, $pedir(['user_id' => $yo, 'pagina' => 1], $this->tokenDe($admin->username))->json('total'));
    }

    public function test_las_personas_con_ingresos_para_el_selector(): void
    {
        $yo = (int) $this->docente->user_id;   // su login del setUp ya dejó un ingreso
        $admin = $this->usuarioLlanoDelPersonal();

        $this->withToken($this->tokenDocente)->getJson('/api/auditoria/ingresos/personas')->assertStatus(403);

        $this->darPermisoDeAuditoria((int) $admin->id);
        $personas = $this->withToken($this->tokenDe($admin->username))->getJson('/api/auditoria/ingresos/personas')
            ->assertStatus(200)->json();

        $this->assertSame(['user_id', 'nombre', 'tipo', 'rol', 'foto'], array_keys($personas[0]));
        $porId = array_column($personas, null, 'user_id');
        $this->assertArrayHasKey($yo, $porId);
        $this->assertSame('Profesor', $porId[$yo]['tipo']);
        $this->assertSame('Docente', $porId[$yo]['rol']);
        $this->assertNotEmpty($porId[$yo]['nombre']);
        $this->assertCount(count($porId), $personas, 'Una vez cada persona.');

        $sinIngreso = DB::selectOne('SELECT u.id FROM users u WHERE u.id NOT IN
            (SELECT h.user_id FROM historiales h WHERE h.user_id IS NOT NULL) ORDER BY u.id LIMIT 1');
        $this->assertArrayNotHasKey((int) $sinIngreso->id, $porId);
    }
}
