<?php

namespace Tests\Contrato;

use App\Services\BoletinIndependiente;
use Illuminate\Support\Facades\DB;

/**
 * **Las cuatro rejillas de `unidades/*` y `subunidades/*` son las DEL CURSO.**
 *
 * Son los cuatro sitios de la fase 1 que quedaban en estos dos controladores
 * ([reparto B](../../docs/migracion/noche-2026-08-31/reparto.md)), y ninguno de los
 * cuatro es «pintar de más»:
 *
 * | Sitio | Qué decide de verdad |
 * |---|---|
 * | `UnidadesController:26` (`$cons_unidades`) | **una escritura.** `getDeAsignaturaPeriodo` siembra las unidades por defecto del año cuando `count() == 0`; con las de un independiente contadas, el curso **se queda sin sembrar** |
 * | `UnidadesController:64` | el panel de «años anteriores», que existe **para copiar de él**: una unidad con dueño de hace tres años se propondría como plan del curso |
 * | `UnidadesController:359` (`putEliminadas`) | la papelera que lleva al lado un `unidades/restore/{id}` |
 * | `SubunidadesController:362` (`putEliminadas`) | la misma, para subunidades |
 *
 * **Con nadie marcado los cuatro dan el mismo verde escritos bien o mal**, así que
 * cada caso construye el escenario: marca a un alumno, le monta lo suyo y comprueba
 * que el curso sigue viendo el curso. Los cuatro se comprobaron **en rojo** con su
 * condición revertida antes de darlos por buenos.
 */
class RejillaDelCursoSinIndependientesTest extends CasoDeContrato
{
    /** Una asignatura del seed con su profesor, en el periodo actual y abierto. */
    private function contexto(): object
    {
        $fila = DB::selectOne('SELECT a.id AS asignatura_id, a.grupo_id, a.materia_id, a.profesor_id,
                u.username, un.periodo_id, g.year_id, g.grado_id
            FROM asignaturas a
            INNER JOIN profesores p ON p.id = a.profesor_id AND p.deleted_at IS NULL
            INNER JOIN users u ON u.id = p.user_id AND u.is_active = 1 AND u.deleted_at IS NULL
            INNER JOIN grupos g ON g.id = a.grupo_id AND g.deleted_at IS NULL
            INNER JOIN unidades un ON un.asignatura_id = a.id AND un.deleted_at IS NULL
                AND un.alumno_id IS NULL
            INNER JOIN subunidades s ON s.unidad_id = un.id AND s.deleted_at IS NULL
            INNER JOIN periodos per ON per.id = un.periodo_id AND per.actual = 1
                AND per.year_id = g.year_id AND per.deleted_at IS NULL
                AND per.profes_pueden_editar_notas = 1
            INNER JOIN years y ON y.id = g.year_id AND y.actual = 1 AND y.deleted_at IS NULL
            WHERE a.deleted_at IS NULL
            ORDER BY a.id LIMIT 1');

        $this->assertNotNull($fila,
            'El seed necesita una asignatura con unidades y subunidades del grupo en un periodo actual y abierto.');

        return $fila;
    }

    private function unAlumnoDelGrupo(int $grupoId): int
    {
        $fila = DB::selectOne('SELECT m.alumno_id FROM matriculas m
            WHERE m.grupo_id = ? AND m.deleted_at IS NULL AND m.estado IN ("MATR","ASIS")
            ORDER BY m.alumno_id LIMIT 1', [$grupoId]);

        $this->assertNotNull($fila, 'El seed necesita un matriculado en ese grupo.');

        return (int) $fila->alumno_id;
    }

    /** Una unidad de ese alumno y sólo suya, con una subunidad dentro. */
    private function unidadPropia(int $asignaturaId, int $periodoId, int $alumnoId): int
    {
        DB::insert(
            'INSERT INTO unidades (definicion, porcentaje, asignatura_id, periodo_id, alumno_id, orden, created_at, updated_at)
             VALUES ("Sólo del independiente", 100, ?, ?, ?, 0, NOW(), NOW())',
            [$asignaturaId, $periodoId, $alumnoId]
        );

        return (int) DB::getPdo()->lastInsertId();
    }

    // ----------------------------------------------- (1) la que ESCRIBE, :26

    /**
     * El curso sin unidades propias **sigue recibiendo las del año**, aunque un
     * independiente sí tenga las suyas.
     *
     * Es la guarda del 28 ago —«sin unidades no se escribe»— entrando por una puerta
     * nueva y del revés: aquí lo que se pierde no es un cero de más, es **la rejilla
     * entera del curso**, que se queda vacía sin un error en el log. Y el docente lo
     * único que ve es una asignatura sin montar.
     */
    public function test_el_curso_recibe_las_unidades_por_defecto_aunque_un_independiente_tenga_las_suyas(): void
    {
        BoletinIndependiente::olvidar();

        $ctx = $this->contexto();
        $marcado = $this->unAlumnoDelGrupo((int) $ctx->grupo_id);

        // El seed no trae `unidades_por_defecto`, así que el escenario las pone: sin
        // ellas el método devuelve '' y esta rama no se ejecuta nunca.
        DB::insert('INSERT INTO unidades_por_defecto (definicion, porcentaje, year_id, obligatoria, orden, created_at, updated_at)
             VALUES ("Unidad del año", 100, ?, 0, 1, NOW(), NOW())', [$ctx->year_id]);
        $unidadDefecto = (int) DB::getPdo()->lastInsertId();

        DB::insert('INSERT INTO subunidades_por_defecto (definicion, porcentaje, unidad_defec_id, nota_default, obligatoria, orden, created_at, updated_at)
             VALUES ("Subunidad del año", 100, ?, 1, 0, 1, NOW(), NOW())', [$unidadDefecto]);

        // Fuera las del curso: a partir de aquí la asignatura NO está montada.
        DB::table('unidades')
            ->where('asignatura_id', $ctx->asignatura_id)
            ->where('periodo_id', $ctx->periodo_id)
            ->whereNull('alumno_id')
            ->whereNull('deleted_at')
            ->update(['deleted_at' => now()]);

        $this->marcarIndependiente($marcado, (int) $ctx->periodo_id);
        $unidadPropia = $this->unidadPropia((int) $ctx->asignatura_id, (int) $ctx->periodo_id, $marcado);

        $r = $this->getJson(
            '/api/unidades/de-asignatura-periodo/'.$ctx->asignatura_id.'/'.$ctx->periodo_id,
            ['Authorization' => 'Bearer '.$this->tokenDe($ctx->username)]
        );
        $r->assertStatus(200);

        $sembradas = DB::table('unidades')
            ->where('asignatura_id', $ctx->asignatura_id)
            ->where('periodo_id', $ctx->periodo_id)
            ->whereNull('alumno_id')
            ->whereNull('deleted_at')
            ->count();

        $this->assertGreaterThan(0, $sembradas,
            'El curso se quedó sin sembrar porque un independiente tenía las suyas: la rejilla sale vacía y nadie recibe un error.');

        $this->assertNotContains($unidadPropia, array_column($r->json(), 'id'),
            'La unidad de un independiente sale en la rejilla del curso.');
    }

    // -------------------------------------------- (2) los años pasados, :64

    /**
     * El panel de «años anteriores» no propone el plan de estudios de un alumno.
     *
     * Este panel existe **para copiar de él**, así que una unidad con dueño colada
     * ahí no se queda en pintar: acaba siendo el reparto de un curso entero. Y no
     * lleva nada que diga de quién es, así que se copia sin que nadie pueda notarlo.
     */
    public function test_los_anios_anteriores_no_proponen_la_unidad_de_un_independiente(): void
    {
        BoletinIndependiente::olvidar();

        $ctx = $this->contexto();

        // **El seed no trae ese año y por eso se construye.** Medido: de los nueve
        // años sólo el 7 (2024) y el 8 (2025) tienen asignaturas, y **sus grados no
        // coinciden**, así que el panel de años anteriores viene vacío para todas las
        // asignaturas del seed. Un test que se conformara con lo que hay pasaría
        // siempre sin ejecutar la consulta que mide.
        $periodoViejo = DB::selectOne('SELECT p.id FROM periodos p
            INNER JOIN years y ON y.id = p.year_id AND y.deleted_at IS NULL AND y.id != ?
            WHERE p.deleted_at IS NULL
              AND p.numero = (SELECT p2.numero FROM periodos p2 WHERE p2.id = ?)
            ORDER BY p.id DESC LIMIT 1', [$ctx->year_id, $ctx->periodo_id]);

        $this->assertNotNull($periodoViejo, 'Hace falta otro año con el mismo número de periodo.');

        $anioViejo = DB::selectOne('SELECT year_id FROM periodos WHERE id = ?', [$periodoViejo->id]);

        DB::insert('INSERT INTO grupos (nombre, year_id, grado_id, created_at, updated_at)
             VALUES ("Grupo de hace años", ?, ?, NOW(), NOW())', [$anioViejo->year_id, $ctx->grado_id]);
        $grupoViejo = (int) DB::getPdo()->lastInsertId();

        DB::insert('INSERT INTO asignaturas (materia_id, grupo_id, created_at, updated_at)
             VALUES (?, ?, NOW(), NOW())', [$ctx->materia_id, $grupoViejo]);
        $asignaturaVieja = (int) DB::getPdo()->lastInsertId();

        // Una del curso de aquel año —para que el panel traiga algo y el test
        // distinga «no propone la ajena» de «no propone nada»— y una con dueño.
        DB::insert('INSERT INTO unidades (definicion, porcentaje, asignatura_id, periodo_id, alumno_id, orden, created_at, updated_at)
             VALUES ("Plan de aquel curso", 100, ?, ?, NULL, 1, NOW(), NOW())',
            [$asignaturaVieja, $periodoViejo->id]);
        $unidadDelCursoViejo = (int) DB::getPdo()->lastInsertId();

        $vieja = (object) ['asignatura_id' => $asignaturaVieja, 'periodo_id' => (int) $periodoViejo->id];

        $alumnoViejo = (object) ['alumno_id' => $this->unAlumnoDelGrupo((int) $ctx->grupo_id)];

        $unidadAjena = $this->unidadPropia(
            (int) $vieja->asignatura_id, (int) $vieja->periodo_id, (int) $alumnoViejo->alumno_id
        );

        $r = $this->putJson(
            '/api/unidades/de-asignatura-periodo/'.$ctx->asignatura_id.'/'.$ctx->periodo_id,
            [],
            ['Authorization' => 'Bearer '.$this->tokenDe($ctx->username)]
        );
        $r->assertStatus(200);

        $propuestas = [];
        foreach ($r->json('anios_pasados') as $periodo) {
            foreach ($periodo['unidades'] as $unidad) {
                $propuestas[] = (int) $unidad['id'];
            }
        }

        $this->assertContains($unidadDelCursoViejo, $propuestas,
            'El panel vino sin la unidad del curso de aquel año: así este test no compara nada.');
        $this->assertNotContains($unidadAjena, $propuestas,
            'El panel de años anteriores propone copiar el boletín de un alumno como plan del curso.');
    }

    // ------------------------------------------------ (3) y (4) la papelera

    /**
     * La papelera del curso no ofrece restaurar la unidad borrada de un
     * independiente.
     *
     * Restaurar va por id y sigue funcionando: lo que se acota es **a quién se le
     * ofrece**. Quien pulsa en esa rejilla cree que devuelve una unidad al curso, y
     * devolvería la de otro alumno con su porcentaje contando otra vez en la
     * definitiva de ése.
     */
    public function test_la_papelera_de_unidades_del_curso_no_lista_la_del_independiente(): void
    {
        BoletinIndependiente::olvidar();

        $ctx = $this->contexto();
        $marcado = $this->unAlumnoDelGrupo((int) $ctx->grupo_id);

        $this->marcarIndependiente($marcado, (int) $ctx->periodo_id);
        $unidadPropia = $this->unidadPropia((int) $ctx->asignatura_id, (int) $ctx->periodo_id, $marcado);

        DB::table('unidades')->where('id', $unidadPropia)->update(['deleted_at' => now()]);

        // Y una del curso borrada al lado, para que el test distinga «no lista la
        // ajena» de «no lista nada».
        $delCurso = DB::selectOne('SELECT id FROM unidades
            WHERE asignatura_id = ? AND periodo_id = ? AND alumno_id IS NULL AND deleted_at IS NULL
            ORDER BY id LIMIT 1', [$ctx->asignatura_id, $ctx->periodo_id]);
        $this->assertNotNull($delCurso, 'Hace falta una unidad del curso que borrar.');
        DB::table('unidades')->where('id', $delCurso->id)->update(['deleted_at' => now()]);

        $r = $this->putJson('/api/unidades/eliminadas/'.$ctx->asignatura_id, [],
            ['Authorization' => 'Bearer '.$this->tokenDe($ctx->username)]);
        $r->assertStatus(200);

        $ids = array_column($r->json('unidades_eliminadas'), 'id');

        $this->assertContains((int) $delCurso->id, array_map('intval', $ids),
            'La papelera del curso dejó de enseñar lo del curso.');
        $this->assertNotContains($unidadPropia, array_map('intval', $ids),
            'La papelera del curso ofrece restaurar la unidad de un independiente.');
    }

    /**
     * Lo mismo para subunidades, y con la razón por la que el alcance se pregunta en
     * `u` y nunca en `s`: **las subunidades no tienen dueño propio, lo heredan de su
     * unidad** (§3 del plan).
     */
    public function test_la_papelera_de_subunidades_del_curso_no_lista_la_del_independiente(): void
    {
        BoletinIndependiente::olvidar();

        $ctx = $this->contexto();
        $marcado = $this->unAlumnoDelGrupo((int) $ctx->grupo_id);

        $this->marcarIndependiente($marcado, (int) $ctx->periodo_id);
        $unidadPropia = $this->unidadPropia((int) $ctx->asignatura_id, (int) $ctx->periodo_id, $marcado);

        DB::insert(
            'INSERT INTO subunidades (definicion, porcentaje, unidad_id, nota_default, orden, deleted_at, created_at, updated_at)
             VALUES ("Suya y borrada", 100, ?, 1, 0, NOW(), NOW(), NOW())',
            [$unidadPropia]
        );
        $subunidadPropia = (int) DB::getPdo()->lastInsertId();

        $delCurso = DB::selectOne('SELECT s.id FROM subunidades s
            INNER JOIN unidades u ON u.id = s.unidad_id AND u.asignatura_id = ? AND u.periodo_id = ?
                AND u.alumno_id IS NULL AND u.deleted_at IS NULL
            WHERE s.deleted_at IS NULL ORDER BY s.id LIMIT 1',
            [$ctx->asignatura_id, $ctx->periodo_id]);
        $this->assertNotNull($delCurso, 'Hace falta una subunidad del curso que borrar.');
        DB::table('subunidades')->where('id', $delCurso->id)->update(['deleted_at' => now()]);

        $r = $this->putJson('/api/subunidades/eliminadas/'.$ctx->asignatura_id, [],
            ['Authorization' => 'Bearer '.$this->tokenDe($ctx->username)]);
        $r->assertStatus(200);

        $ids = array_map('intval', array_column($r->json('subunidades'), 'id'));

        $this->assertContains((int) $delCurso->id, $ids,
            'La papelera del curso dejó de enseñar lo del curso.');
        $this->assertNotContains($subunidadPropia, $ids,
            'La papelera del curso ofrece restaurar la subunidad de un independiente.');
    }
}
