<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * La pestaña «Por persona» de `/auditoria` (contrato 3): el ranking
 * `GET auditoria/alumnos/personas-con-cambios`, la familia `todas` de
 * `GET auditoria/alumnos/{familia}` y `GET auditoria/alumnos/resumen-de-persona/{user_id}`.
 *
 * Las líneas se siembran dentro de la transacción de cada test en marzo de 2031, un
 * rango en el que el seed y el propio login no dejan nada: así el ranking, que no se
 * puede acotar por alumno, sólo ve lo sembrado.
 */
class AuditoriaPorPersonaTest extends CasoDeContrato
{
    private const DESDE = '2031-03-01';

    private const HASTA = '2031-03-31';

    private string $token;

    /** Quien mira: personal llano con el permiso. En el ranking no sale como Docente. */
    private int $yo;

    /** @var list<int> dos docentes sin cargo: su rol legible es «Docente» */
    private array $docentes;

    protected function setUp(): void
    {
        parent::setUp();

        $yo = $this->usuarioLlanoDelPersonal();
        $this->darPermisoDeAuditoria((int) $yo->id);
        $this->yo = (int) $yo->id;
        $this->token = $this->tokenDe($yo->username);

        $this->docentes = array_map(fn ($f) => (int) $f->id, DB::select('SELECT u.id FROM users u
            JOIN profesores p ON p.user_id = u.id AND p.deleted_at IS NULL
            WHERE u.tipo = "Profesor" AND u.is_superuser = 0 AND u.deleted_at IS NULL
              AND NOT EXISTS (SELECT 1 FROM role_user ru JOIN roles r ON r.id = ru.role_id
                               WHERE ru.user_id = u.id AND r.name <> "Profesor")
              AND NOT EXISTS (SELECT 1 FROM years y WHERE p.id IN (y.secretario_id, y.tesorero_id))
            ORDER BY u.id LIMIT 2'));
        $this->assertCount(2, $this->docentes, 'El seed no tiene dos docentes sin cargo.');
    }

    private function linea(array $campos): int
    {
        return (int) DB::table('auditoria')->insertGetId($campos + [
            'accion' => 'editar',
            'entidad' => 'nota',
            'entidad_id' => 999999999,
            'alumno_id' => 987654,
            'alumno_nombre' => 'ALUMNA DE PRUEBA',
            'actor_user_id' => $this->docentes[0],
            'actor_nombre' => 'Docente de prueba',
            'actor_tipo' => 'Profesor',
            'atribucion' => 'sesion',
            'ocurrido_en' => '2031-03-10 10:00:00.000',
        ]);
    }

    /** Una importación de alumnos con un cambio para cada alumno de `$alumnos`. */
    private function importacion(int $deQuien, string $cuando, array $alumnos): int
    {
        $year = (int) DB::table('years')->where('actual', 1)->whereNull('deleted_at')->value('year');

        return (int) DB::table('importaciones')->insertGetId([
            'tipo' => 'alumnos', 'huella' => hash('sha256', $cuando.$deQuien), 'archivo' => 'prueba.xlsx',
            'year' => $year, 'avance' => '{}', 'filas' => count($alumnos), 'estado' => 'completada',
            'created_by' => $deQuien, 'inicio' => $cuando, 'fin' => $cuando,
            'cambios' => json_encode(array_fill_keys($alumnos, ['barrio' => ['A', 'B']])),
        ]);
    }

    /** @return list<int> */
    private function alumnosReales(int $cuantos): array
    {
        return array_map('intval', DB::table('alumnos')->whereNull('deleted_at')->orderBy('id')->limit($cuantos)->pluck('id')->all());
    }

    private function pedir(string $ruta, array $query = [], ?string $token = null): array
    {
        $r = $this->withToken($token ?? $this->token)->getJson('/api/auditoria/alumnos/'.$ruta.'?'.http_build_query($query));
        $r->assertStatus(200);

        return $r->json();
    }

    private function tokenDelDocente(): string
    {
        $docente = DB::selectOne('SELECT u.username FROM users u
            JOIN profesores pr ON pr.user_id = u.id AND pr.deleted_at IS NULL
            JOIN periodos p ON p.id = u.periodo_id
            WHERE u.tipo = "Profesor" AND u.is_active = 1 AND u.deleted_at IS NULL AND u.is_superuser = 0
              AND NOT EXISTS (SELECT 1 FROM role_user ru JOIN roles r ON r.id = ru.role_id
                               WHERE ru.user_id = u.id AND r.name <> "Profesor")
            ORDER BY u.id LIMIT 1');
        $this->assertNotNull($docente);

        return $this->tokenDe($docente->username);
    }

    public function test_el_ranking_va_por_total_dentro_del_rango_con_su_reparto_y_su_rol(): void
    {
        [$a, $b] = $this->docentes;
        // A: 3 notas, 1 comportamiento, 1 ficha; y dos fuera del rango, que lo pondrían primero.
        foreach (['nota', 'nota', 'ausencia', 'comportamiento', 'alumno'] as $i => $entidad) {
            $this->linea(['entidad' => $entidad, 'actor_user_id' => $a, 'actor_nombre' => 'Nombre viejo de A',
                'ocurrido_en' => sprintf('2031-03-%02d 10:00:00.000', 10 + $i)]);
        }
        $this->linea(['actor_user_id' => $a, 'actor_nombre' => 'Nombre de A', 'ocurrido_en' => '2031-03-14 18:00:00.000']);
        $this->linea(['actor_user_id' => $a, 'ocurrido_en' => '2031-04-01 00:00:00.000']);
        $this->linea(['actor_user_id' => $a, 'ocurrido_en' => '2031-02-28 23:59:59.999']);
        // B: 2 notas y una importación de 3 alumnos, que cuenta en datos.
        $this->linea(['actor_user_id' => $b, 'ocurrido_en' => '2031-03-05 08:00:00.000']);
        $this->linea(['actor_user_id' => $b, 'ocurrido_en' => '2031-03-06 08:00:00.000']);
        $this->importacion($b, '2031-03-20 09:00:00', $this->alumnosReales(3));
        // Yo: 7 cambios de datos.
        for ($i = 0; $i < 7; $i++) {
            $this->linea(['entidad' => 'matricula', 'actor_user_id' => $this->yo, 'actor_tipo' => 'Usuario',
                'ocurrido_en' => '2031-03-02 07:00:00.000']);
        }
        // Una línea sin actor (el sistema) no sale.
        $this->linea(['actor_user_id' => null, 'ocurrido_en' => '2031-03-15 07:00:00.000']);

        $r = $this->pedir('personas-con-cambios', ['desde' => self::DESDE, 'hasta' => self::HASTA]);
        $this->assertSame(1, $r['pagina']);
        $this->assertSame(25, $r['por_pagina']);
        $this->assertSame(3, $r['total']);
        $this->assertSame([$this->yo, $a, $b], array_column($r['filas'], 'user_id'));
        $this->assertSame([7, 6, 5], array_column($r['filas'], 'total'));

        [$yo, $filaA, $filaB] = $r['filas'];
        $this->assertSame(['datos' => 7, 'notas' => 0, 'convivencia' => 0], $yo['por_familia']);
        $this->assertNotSame('Docente', $yo['rol']);
        $this->assertSame(['datos' => 1, 'notas' => 4, 'convivencia' => 1], $filaA['por_familia']);
        $this->assertSame('Docente', $filaA['rol']);
        $this->assertSame('Profesor', $filaA['tipo']);
        $this->assertSame('Nombre de A', $filaA['nombre'], 'El nombre es el de su última línea.');
        $this->assertSame('2031-03-10 10:00:00.000', $filaA['primero']);
        $this->assertSame('2031-03-14 18:00:00.000', $filaA['ultimo']);
        $this->assertArrayHasKey('foto', $filaA);
        $this->assertSame(['datos' => 3, 'notas' => 2, 'convivencia' => 0], $filaB['por_familia'],
            'La importación cuenta en datos, una por alumno cambiado.');
        $this->assertSame('2031-03-05 08:00:00.000', $filaB['primero']);
        $this->assertSame('2031-03-20 09:00:00', $filaB['ultimo']);

        $docentes = $this->pedir('personas-con-cambios', ['desde' => self::DESDE, 'hasta' => self::HASTA, 'rol' => 'docente']);
        $this->assertSame(2, $docentes['total']);
        $this->assertSame([$a, $b], array_column($docentes['filas'], 'user_id'));
        $profesores = $this->pedir('personas-con-cambios', ['desde' => self::DESDE, 'hasta' => self::HASTA, 'rol' => 'Profesor']);
        $this->assertSame([$a, $b], array_column($profesores['filas'], 'user_id'), '`rol` acepta también el tipo de la cuenta.');
        $usuarios = $this->pedir('personas-con-cambios', ['desde' => self::DESDE, 'hasta' => self::HASTA, 'rol' => 'Usuario']);
        $this->assertSame([$this->yo], array_column($usuarios['filas'], 'user_id'));

        $pagina2 = $this->pedir('personas-con-cambios', ['desde' => self::DESDE, 'hasta' => self::HASTA, 'pagina' => 2]);
        $this->assertSame(3, $pagina2['total']);
        $this->assertSame([], $pagina2['filas']);

        $unDia = $this->pedir('personas-con-cambios', ['desde' => '2031-03-14', 'hasta' => '2031-03-14']);
        $this->assertSame([$a], array_column($unDia['filas'], 'user_id'));
        $this->assertSame(2, $unDia['filas'][0]['total'], '`hasta` es inclusiva.');
    }

    public function test_el_ranking_en_un_empate_pone_primero_el_cambio_mas_reciente(): void
    {
        [$a, $b] = $this->docentes;
        $this->linea(['actor_user_id' => $a, 'ocurrido_en' => '2031-03-10 10:00:00.000']);
        $this->linea(['actor_user_id' => $b, 'ocurrido_en' => '2031-03-11 10:00:00.000']);

        $r = $this->pedir('personas-con-cambios', ['desde' => self::DESDE, 'hasta' => self::HASTA]);
        $this->assertSame([$b, $a], array_column($r['filas'], 'user_id'));
    }

    public function test_todas_mezcla_las_tres_familias_y_las_importaciones_en_orden(): void
    {
        $actor = $this->docentes[0];
        $alumno = $this->alumnosReales(1)[0];
        $nota = $this->linea(['actor_user_id' => $actor, 'alumno_id' => $alumno, 'ocurrido_en' => '2031-03-10 10:00:00.000']);
        $ficha = $this->linea(['entidad' => 'alumno', 'actor_user_id' => $actor, 'alumno_id' => $alumno,
            'ocurrido_en' => '2031-03-10 11:00:00.000']);
        $frase = $this->linea(['entidad' => 'frase', 'actor_user_id' => $actor, 'alumno_id' => $alumno,
            'ocurrido_en' => '2031-03-10 12:00:00.000']);
        $this->linea(['entidad' => 'sesion', 'actor_user_id' => $actor, 'ocurrido_en' => '2031-03-10 12:30:00.000']);
        $imp = $this->importacion($actor, '2031-03-10 10:30:00', [$alumno]);
        $deImportacion = -($imp * 10_000_000 + $alumno);

        $filtro = ['actor_user_id' => $actor, 'desde' => self::DESDE, 'hasta' => self::HASTA];
        $r = $this->pedir('todas', $filtro);
        $this->assertSame('todas', $r['familia']);
        $this->assertSame(4, $r['total'], 'Una entidad que no es de ninguna familia (sesion) no sale.');
        $this->assertSame(['alumnos' => 1, 'actores' => 1], $r['resumen']);
        $this->assertSame([$frase, $ficha, $deImportacion, $nota], array_column($r['acciones'], 'id'));
        $this->assertSame(['observacion', 'ficha', 'importacion', 'nota'], array_column($r['acciones'], 'tipo'));
        $this->assertSame(['convivencia', 'datos', 'datos', 'notas'], array_column($r['acciones'], 'familia'));
        $this->assertArrayNotHasKey('familia', $this->pedir('notas', $filtro)['acciones'][0],
            'Las familias de siempre no cambian de forma.');

        $solo = $this->pedir('todas', $filtro + ['solo_total' => 1]);
        $this->assertSame(4, $solo['total']);
        $this->assertSame([], $solo['acciones']);

        $paginada = $this->pedir('todas', $filtro + ['pagina' => 2, 'por_pagina' => 25]);
        $this->assertSame([], $paginada['acciones']);

        // Los tipos son los de las tres familias; si no piden la importación, no sale.
        $tipos = $this->pedir('todas', $filtro + ['tipos' => 'nota,ficha']);
        $this->assertSame([$ficha, $nota], array_column($tipos['acciones'], 'id'));
        $this->assertSame([$deImportacion], array_column($this->pedir('todas', $filtro + ['tipos' => 'importacion'])['acciones'], 'id'));

        // Otro actor, otro rango: nada.
        $this->assertSame(0, $this->pedir('todas', ['actor_user_id' => $this->docentes[1]] + $filtro)['total']);
        $this->assertSame(0, $this->pedir('todas', ['actor_user_id' => $actor, 'desde' => '2031-03-11'])['total']);
    }

    public function test_todas_filtra_por_asignatura_como_notas(): void
    {
        $actor = $this->docentes[0];
        $nota = DB::selectOne('SELECT n.id, un.asignatura_id FROM notas n
            JOIN subunidades s ON s.id = n.subunidad_id JOIN unidades un ON un.id = s.unidad_id
            ORDER BY n.id LIMIT 1');
        $alumno = $this->alumnosReales(1)[0];
        $deSuNota = $this->linea(['actor_user_id' => $actor, 'entidad_id' => $nota->id]);
        $deSuFrase = $this->linea(['entidad' => 'frase_asignatura', 'actor_user_id' => $actor,
            'asignatura_id' => $nota->asignatura_id, 'ocurrido_en' => '2031-03-11 10:00:00.000']);
        $this->linea(['actor_user_id' => $actor]);   // nota que no existe: sin asignatura
        $this->linea(['entidad' => 'alumno', 'actor_user_id' => $actor]);
        $this->importacion($actor, '2031-03-10 10:30:00', [$alumno]);

        $r = $this->pedir('todas', ['actor_user_id' => $actor, 'asignatura_id' => $nota->asignatura_id,
            'desde' => self::DESDE, 'hasta' => self::HASTA]);
        $this->assertSame([$deSuFrase, $deSuNota], array_column($r['acciones'], 'id'),
            'Con asignatura no sale ni la ficha ni la importación, que no la tienen.');
    }

    public function test_todas_y_la_pestana_por_persona_son_solo_con_el_permiso(): void
    {
        $docente = $this->tokenDelDocente();
        $this->withToken($docente)->getJson('/api/auditoria/alumnos/todas')->assertStatus(403);
        $this->withToken($docente)->getJson('/api/auditoria/alumnos/personas-con-cambios')->assertStatus(403);
        $this->withToken($docente)->getJson('/api/auditoria/alumnos/resumen-de-persona/'.$this->docentes[0])->assertStatus(403);
        // Y la de notas le sigue abierta: `todas` no cambió su alcance.
        $this->withToken($docente)->getJson('/api/auditoria/alumnos/notas')->assertStatus(200);

        $sinPermiso = DB::selectOne('SELECT u.username FROM users u
            WHERE u.tipo = "Usuario" AND u.is_active = 1 AND u.deleted_at IS NULL AND u.is_superuser = 0
              AND u.id NOT IN (SELECT ru.user_id FROM role_user ru)
            ORDER BY u.id DESC LIMIT 1');
        $this->assertNotNull($sinPermiso);
        $token = $this->tokenDe($sinPermiso->username);
        foreach (['todas', 'personas-con-cambios', 'resumen-de-persona/1'] as $ruta) {
            $this->withToken($token)->getJson('/api/auditoria/alumnos/'.$ruta)->assertStatus(403);
        }

        foreach (['personas-con-cambios', 'resumen-de-persona/1', 'todas'] as $ruta) {
            $this->withToken($this->token)->getJson('/api/auditoria/alumnos/'.$ruta.'?desde=2031-13-01')->assertStatus(422);
        }
    }

    public function test_el_resumen_de_una_persona(): void
    {
        $actor = $this->docentes[0];
        $nota = DB::selectOne('SELECT n.id, un.asignatura_id, m.materia, g.nombre AS grupo FROM notas n
            JOIN subunidades s ON s.id = n.subunidad_id JOIN unidades un ON un.id = s.unidad_id
            JOIN asignaturas asg ON asg.id = un.asignatura_id
            JOIN materias m ON m.id = asg.materia_id JOIN grupos g ON g.id = asg.grupo_id
            ORDER BY n.id LIMIT 1');
        $otra = DB::selectOne('SELECT asg.id, m.materia, g.nombre AS grupo FROM asignaturas asg
            JOIN materias m ON m.id = asg.materia_id JOIN grupos g ON g.id = asg.grupo_id
            WHERE asg.id <> ? ORDER BY asg.id LIMIT 1', [$nota->asignatura_id]);
        [$alumno1, $alumno2] = $this->alumnosReales(2);

        // 12 de marzo: 3 notas (la asignatura sale de la fila de la nota), a dos alumnos.
        foreach ([$alumno1, $alumno1, $alumno2] as $i => $alumno) {
            $this->linea(['actor_user_id' => $actor, 'entidad_id' => $nota->id, 'alumno_id' => $alumno,
                'ocurrido_en' => "2031-03-12 1$i:00:00.000"]);
        }
        // 10 de marzo: una definitiva con la asignatura en la línea y dos ausencias sin ella.
        $this->linea(['actor_user_id' => $actor, 'entidad' => 'nota_final', 'asignatura_id' => $otra->id,
            'alumno_id' => $alumno1, 'ocurrido_en' => '2031-03-10 10:00:00.000']);
        $this->linea(['actor_user_id' => $actor, 'entidad' => 'ausencia', 'alumno_id' => $alumno1, 'ocurrido_en' => '2031-03-10 11:00:00.000']);
        $this->linea(['actor_user_id' => $actor, 'entidad' => 'ausencia', 'alumno_id' => $alumno1, 'ocurrido_en' => '2031-03-10 12:00:00.000']);
        // 15 de marzo: una importación de tres alumnos (uno nuevo) y un comportamiento: 4 ese día, el máximo.
        $alumno3 = $this->alumnosReales(3)[2];
        $this->importacion($actor, '2031-03-15 09:00:00', [$alumno1, $alumno2, $alumno3]);
        $this->linea(['actor_user_id' => $actor, 'entidad' => 'comportamiento', 'alumno_id' => $alumno2,
            'ocurrido_en' => '2031-03-15 10:00:00.000']);
        // Fuera del rango, de otro actor y de otra entidad: no cuentan.
        $this->linea(['actor_user_id' => $actor, 'ocurrido_en' => '2031-04-02 10:00:00.000']);
        $this->linea(['actor_user_id' => $this->docentes[1], 'ocurrido_en' => '2031-03-12 10:00:00.000']);
        $this->linea(['actor_user_id' => $actor, 'entidad' => 'sesion', 'ocurrido_en' => '2031-03-12 10:00:00.000']);

        $r = $this->pedir('resumen-de-persona/'.$actor, ['desde' => self::DESDE, 'hasta' => self::HASTA]);

        $this->assertSame($actor, $r['persona']['user_id']);
        $this->assertSame('Docente de prueba', $r['persona']['nombre'], 'El nombre de su última línea.');
        $this->assertSame('Profesor', $r['persona']['tipo']);
        $this->assertSame('Docente', $r['persona']['rol']);
        $this->assertArrayHasKey('foto', $r['persona']);
        $this->assertSame(10, $r['total']);
        $this->assertSame(3, $r['alumnos']);
        $this->assertSame(3, $r['dias']);
        $this->assertSame(['fecha' => '2031-03-15', 'total' => 4], $r['dia_max']);
        $this->assertSame([
            ['familia' => 'datos', 'tipo' => 'importacion', 'total' => 3],
            ['familia' => 'notas', 'tipo' => 'nota', 'total' => 3],
            ['familia' => 'notas', 'tipo' => 'ausencia', 'total' => 2],
            ['familia' => 'notas', 'tipo' => 'final', 'total' => 1],
            ['familia' => 'convivencia', 'tipo' => 'comportamiento', 'total' => 1],
        ], $r['por_tipo'], 'De más a menos; en el empate, en el orden de los chips.');
        $this->assertSame(2, $r['asignaturas']);
        $this->assertSame([
            ['asignatura_id' => (int) $nota->asignatura_id, 'asignatura_nombre' => $nota->materia, 'grupo_nombre' => $nota->grupo, 'total' => 3],
            ['asignatura_id' => (int) $otra->id, 'asignatura_nombre' => $otra->materia, 'grupo_nombre' => $otra->grupo, 'total' => 1],
        ], $r['por_asignatura']);

        // El resumen y el listado `todas` cuentan lo mismo, y la asignatura filtra igual.
        $filtro = ['actor_user_id' => $actor, 'desde' => self::DESDE, 'hasta' => self::HASTA];
        $this->assertSame($r['total'], $this->pedir('todas', $filtro + ['solo_total' => 1])['total']);
        $this->assertSame(3, $this->pedir('todas', $filtro + ['solo_total' => 1, 'asignatura_id' => $nota->asignatura_id])['total']);

        // Un empate de días va al más reciente; sin cambios, `dia_max` es null.
        $vacio = $this->pedir('resumen-de-persona/'.$actor, ['desde' => '2031-05-01', 'hasta' => '2031-05-31']);
        $this->assertSame($actor, $vacio['persona']['user_id'], 'Sin cambios en el rango, la persona sale igual.');
        unset($vacio['persona']);
        $this->assertSame(['total' => 0, 'alumnos' => 0, 'asignaturas' => 0, 'dias' => 0, 'dia_max' => null,
            'por_tipo' => [], 'por_asignatura' => []], $vacio);
        $sinLineas = $this->pedir('resumen-de-persona/'.$this->yo);
        $this->assertSame($this->yo, $sinLineas['persona']['user_id'], 'Sin ninguna línea, de su cuenta.');
        $this->assertNull($this->pedir('resumen-de-persona/999999999')['persona']);
        $empate = $this->pedir('resumen-de-persona/'.$actor, ['desde' => '2031-03-10', 'hasta' => '2031-03-12']);
        $this->assertSame(['fecha' => '2031-03-12', 'total' => 3], $empate['dia_max']);
    }
}
