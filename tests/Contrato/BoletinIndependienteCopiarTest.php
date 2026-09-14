<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/**
 * `POST boletin-independiente/copiar` — §6.2 del
 * [19](../../docs/migracion/19-boletin-independiente.md).
 *
 * ## Lo que estos tests existen para cazar
 *
 * **Los dos orígenes se leen con alcances CONTRARIOS y viven en el mismo método**:
 * `u.alumno_id IS NULL` para el grupo, `= origen.alumno_id` para el alumno. Un `=`
 * copiado a la rama del grupo **devuelve cero filas y copia una estructura vacía en
 * 200** — no hay error, no hay excepción, y la pantalla dice «copiado».
 *
 * Por eso **ningún caso se conforma con el código de estado**: todos cuentan **las filas
 * que quedaron escritas**, que es lo único que distingue una copia de un cero. Es
 * literalmente la regla de la casa —mirar el resultado y no el 200— aplicada al sitio
 * donde más barato sale saltársela.
 *
 * Y por eso los dos orígenes tienen **un caso cada uno** en vez de uno parametrizado: un
 * test que recorriera los dos con la misma aserción pasaría con las dos ramas escritas
 * igual, que es exactamente el fallo.
 */
class BoletinIndependienteCopiarTest extends CasoDeContrato
{
    private const RUTA = '/api/boletin-independiente/copiar';

    /** Asignatura del año actual con unidades del grupo en el periodo actual. */
    private function contexto(): object
    {
        $fila = DB::selectOne('SELECT a.id AS asignatura_id, a.grupo_id, g.year_id, un.periodo_id
            FROM asignaturas a
            INNER JOIN grupos g ON g.id = a.grupo_id AND g.deleted_at IS NULL
            INNER JOIN unidades un ON un.asignatura_id = a.id AND un.deleted_at IS NULL
                AND un.alumno_id IS NULL
            INNER JOIN periodos per ON per.id = un.periodo_id AND per.actual = 1
                AND per.year_id = g.year_id AND per.deleted_at IS NULL
            INNER JOIN years y ON y.id = g.year_id AND y.actual = 1 AND y.deleted_at IS NULL
            INNER JOIN subunidades s ON s.unidad_id = un.id AND s.deleted_at IS NULL
            WHERE a.deleted_at IS NULL
            ORDER BY a.id LIMIT 1');

        $this->assertNotNull($fila,
            'El seed necesita una asignatura con unidades del grupo en el periodo actual y subunidades.');

        return $fila;
    }

    /** @return list<int> */
    private function dosAlumnos(int $grupoId): array
    {
        $filas = DB::select(
            'SELECT m.alumno_id FROM matriculas m
              WHERE m.grupo_id = ? AND m.deleted_at IS NULL AND m.estado IN ("MATR","ASIS","PREM")
              ORDER BY m.alumno_id LIMIT 2',
            [$grupoId]
        );

        $this->assertCount(2, $filas, 'Hacen falta dos alumnos.');

        return array_values(array_map(static fn ($f) => (int) $f->alumno_id, $filas));
    }

    /** Una unidad con dueño y una subunidad dentro. Devuelve `[unidad, subunidad]`. */
    private function unidadPropia(object $ctx, int $alumnoId, int $porcentaje = 100): array
    {
        $unidad = (int) DB::table('unidades')->insertGetId([
            'definicion' => 'Unidad de origen (test)',
            'porcentaje' => $porcentaje,
            'periodo_id' => $ctx->periodo_id,
            'asignatura_id' => $ctx->asignatura_id,
            'alumno_id' => $alumnoId,
            'orden' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $sub = (int) DB::table('subunidades')->insertGetId([
            'definicion' => 'Subunidad de origen (test)',
            'porcentaje' => 100,
            'unidad_id' => $unidad,
            'nota_default' => 0,
            'orden' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$unidad, $sub];
    }

    /** Cuántas unidades **propias** vivas tiene un alumno en el contexto. */
    private function suyasVivas(object $ctx, int $alumnoId): int
    {
        return (int) DB::selectOne(
            'SELECT COUNT(*) c FROM unidades
              WHERE alumno_id = ? AND asignatura_id = ? AND periodo_id = ? AND deleted_at IS NULL',
            [$alumnoId, $ctx->asignatura_id, $ctx->periodo_id]
        )->c;
    }

    /**
     * **El token se guarda y se reutiliza**, y no es una optimización: el caso del cuerpo
     * mal formado hace siete llamadas, y un login por llamada choca contra el limitador
     * de `login/credentials` — la respuesta llega con **429** y el test falla por el
     * instrumento y no por lo que mide.
     */
    private ?string $tokenDelAnio = null;

    private function copiar(object $ctx, array $cuerpo): TestResponse
    {
        $this->tokenDelAnio ??= $this->tokenDelPersonalDe((int) $ctx->year_id);

        return $this->withToken($this->tokenDelAnio)
            ->postJson(self::RUTA, array_merge([
                'asignatura_id' => $ctx->asignatura_id,
                'periodo_id' => $ctx->periodo_id,
            ], $cuerpo));
    }

    // ─────────────────────────────────────────────────────────────────────
    // Los dos orígenes, uno por caso y CONTANDO FILAS
    // ─────────────────────────────────────────────────────────────────────

    /**
     * **Origen `grupo`: se copian las unidades del CURSO.**
     *
     * Es la rama que un `=` copiado de la otra deja en cero filas y en 200. Por eso no se
     * comprueba el `resultado`, se comprueba que **al alumno le quedaron escritas tantas
     * unidades como tiene el grupo**.
     */
    public function test_origen_grupo_copia_las_unidades_del_curso(): void
    {
        $ctx = $this->contexto();
        [$destino] = $this->dosAlumnos((int) $ctx->grupo_id);

        $this->marcarIndependiente($destino, (int) $ctx->periodo_id);

        $delGrupo = (int) DB::selectOne(
            'SELECT COUNT(*) c FROM unidades
              WHERE asignatura_id = ? AND periodo_id = ? AND alumno_id IS NULL AND deleted_at IS NULL',
            [$ctx->asignatura_id, $ctx->periodo_id]
        )->c;

        $this->assertGreaterThan(0, $delGrupo, 'El contexto no tiene unidades del grupo.');
        $this->assertSame(0, $this->suyasVivas($ctx, $destino), 'El destino ya tenía estructura propia.');

        $r = $this->copiar($ctx, [
            'alumnos_destino' => [$destino],
            'origen' => ['tipo' => 'grupo', 'periodo_id' => $ctx->periodo_id],
        ]);

        $r->assertStatus(200);

        $this->assertSame($delGrupo, $this->suyasVivas($ctx, $destino),
            'La rama del grupo copió CERO unidades. Con `= algo` en vez de `IS NULL` la consulta no '
            .'devuelve filas y la respuesta dice «copiado» igual: es el fallo mudo de esta ruta.');

        $this->assertSame('copiado', $r->json('destinos.0.resultado'));
        $this->assertSame($delGrupo, $r->json('destinos.0.copiadas.unidades'));
        $this->assertSame($delGrupo, $r->json('origen.unidades'));
    }

    /**
     * **Origen `alumno`: se copian las de ESE alumno, y no las del curso.**
     *
     * La comprobación que separa las dos ramas es que el destino reciba **una** unidad
     * —la del origen— y no las del grupo: con `IS NULL` en esta rama recibiría el curso
     * entero y el docente creería que copió a la persona.
     */
    public function test_origen_alumno_copia_las_de_ese_alumno_y_no_las_del_curso(): void
    {
        $ctx = $this->contexto();
        [$origen, $destino] = $this->dosAlumnos((int) $ctx->grupo_id);

        $this->marcarIndependiente($origen, (int) $ctx->periodo_id);
        $this->marcarIndependiente($destino, (int) $ctx->periodo_id);
        $this->unidadPropia($ctx, $origen);

        $r = $this->copiar($ctx, [
            'alumnos_destino' => [$destino],
            'origen' => ['tipo' => 'alumno', 'alumno_id' => $origen, 'periodo_id' => $ctx->periodo_id],
        ]);

        $r->assertStatus(200);

        $this->assertSame(1, $this->suyasVivas($ctx, $destino),
            'La rama del alumno no copió exactamente la unidad del origen: con `IS NULL` habría '
            .'copiado el curso entero, y con un filtro de más, ninguna.');

        $this->assertSame(1, $r->json('origen.unidades'));
        $this->assertSame(1, $r->json('destinos.0.copiadas.unidades'));
    }

    /** Y las subunidades viajan con su unidad. */
    public function test_las_subunidades_se_copian_con_su_unidad(): void
    {
        $ctx = $this->contexto();
        [$origen, $destino] = $this->dosAlumnos((int) $ctx->grupo_id);

        $this->marcarIndependiente($origen, (int) $ctx->periodo_id);
        $this->marcarIndependiente($destino, (int) $ctx->periodo_id);
        $this->unidadPropia($ctx, $origen);

        $this->copiar($ctx, [
            'alumnos_destino' => [$destino],
            'origen' => ['tipo' => 'alumno', 'alumno_id' => $origen, 'periodo_id' => $ctx->periodo_id],
        ])->assertStatus(200);

        $subs = (int) DB::selectOne(
            'SELECT COUNT(*) c FROM subunidades s
               INNER JOIN unidades u ON u.id = s.unidad_id AND u.alumno_id = ?
                                    AND u.asignatura_id = ? AND u.periodo_id = ? AND u.deleted_at IS NULL
              WHERE s.deleted_at IS NULL',
            [$destino, $ctx->asignatura_id, $ctx->periodo_id]
        )->c;

        $this->assertSame(1, $subs, 'La unidad llegó sin su subunidad: no hay dónde poner la nota.');
    }

    // ─────────────────────────────────────────────────────────────────────
    // si_ya_tiene
    // ─────────────────────────────────────────────────────────────────────

    /** `saltar` es el defecto y no toca nada. */
    public function test_saltar_es_el_defecto_y_no_escribe(): void
    {
        $ctx = $this->contexto();
        [$destino] = $this->dosAlumnos((int) $ctx->grupo_id);

        $this->marcarIndependiente($destino, (int) $ctx->periodo_id);
        $this->unidadPropia($ctx, $destino);

        $r = $this->copiar($ctx, [
            'alumnos_destino' => [$destino],
            'origen' => ['tipo' => 'grupo', 'periodo_id' => $ctx->periodo_id],
        ]);

        $r->assertStatus(200);
        $this->assertSame('saltado', $r->json('destinos.0.resultado'));
        $this->assertSame('ya_tiene_estructura', $r->json('destinos.0.motivo'));
        $this->assertSame(1, $this->suyasVivas($ctx, $destino), '`saltar` escribió de todas formas.');
    }

    /** `anadir` suma a lo que ya hay, y **puede pasar de 100 sin corregirse**. */
    public function test_anadir_suma_y_no_corrige_el_porcentaje(): void
    {
        $ctx = $this->contexto();
        [$destino] = $this->dosAlumnos((int) $ctx->grupo_id);

        $this->marcarIndependiente($destino, (int) $ctx->periodo_id);
        $this->unidadPropia($ctx, $destino, porcentaje: 100);

        $r = $this->copiar($ctx, [
            'alumnos_destino' => [$destino],
            'origen' => ['tipo' => 'grupo', 'periodo_id' => $ctx->periodo_id],
            'si_ya_tiene' => 'anadir',
        ]);

        $r->assertStatus(200);
        $this->assertSame('copiado', $r->json('destinos.0.resultado'));
        $this->assertGreaterThan(1, $this->suyasVivas($ctx, $destino), '`anadir` no añadió nada.');
        $this->assertGreaterThan(100.0, (float) $r->json('destinos.0.porcentaje_unidades'),
            'La suma se está corrigiendo: que `anadir` deje un 160 se tiene que VER.');
    }

    /**
     * **`reemplazar` retira las suyas y NO borra ni una nota.**
     *
     * Es la corrección al aviso que el front iba a pintar: retirar una unidad es un
     * borrado en blando **de la unidad y de nada más**, y `PUT unidades/restore/{id}` la
     * devuelve entera con sus notas dentro. Por eso el campo se llama
     * `notas_que_dejan_de_contar`: *«se borrarán 9 notas»* es **falso**, y asusta de una
     * forma que hace que el docente no use el botón.
     */
    public function test_reemplazar_retira_las_suyas_y_no_borra_ni_una_nota(): void
    {
        $ctx = $this->contexto();
        [$destino] = $this->dosAlumnos((int) $ctx->grupo_id);

        $this->marcarIndependiente($destino, (int) $ctx->periodo_id);
        [$vieja, $subVieja] = $this->unidadPropia($ctx, $destino);

        DB::table('notas')->insert([
            'nota' => 77, 'subunidad_id' => $subVieja, 'alumno_id' => $destino,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $notasAntes = (int) DB::selectOne('SELECT COUNT(*) c FROM notas WHERE deleted_at IS NULL')->c;

        $r = $this->copiar($ctx, [
            'alumnos_destino' => [$destino],
            'origen' => ['tipo' => 'grupo', 'periodo_id' => $ctx->periodo_id],
            'si_ya_tiene' => 'reemplazar',
        ]);

        $r->assertStatus(200);

        $this->assertSame(1, $r->json('destinos.0.retiradas.unidades'));
        $this->assertSame(1, $r->json('destinos.0.retiradas.notas_que_dejan_de_contar'),
            'La cifra que la pantalla enseña no cuadra con la nota que deja de contar.');

        $this->assertNotNull(
            DB::selectOne('SELECT deleted_at FROM unidades WHERE id = ?', [$vieja])->deleted_at,
            'La unidad vieja sigue viva: `reemplazar` no reemplazó.');

        // **Y la nota sigue ahí, entera y sin borrar.** Es lo que hace cierta la palabra
        // «dejan de contar» y falsa la palabra «se borrarán».
        $this->assertGreaterThanOrEqual($notasAntes, (int) DB::selectOne(
            'SELECT COUNT(*) c FROM notas WHERE deleted_at IS NULL')->c,
            '`reemplazar` borró notas. El aviso del front dice que no las borra.');

        $this->assertNull(
            DB::selectOne('SELECT deleted_at FROM notas WHERE subunidad_id = ? AND alumno_id = ?',
                [$subVieja, $destino])->deleted_at,
            'La nota de la unidad retirada quedó borrada: `PUT unidades/restore` ya no la devolvería.');
    }

    /**
     * **`reemplazar` no toca ni una unidad del grupo ni de otro alumno.**
     *
     * Es la invariante que necesita su propio test: retirar por `(asignatura, periodo)`
     * **sin el dueño** le vaciaría la planilla a los treinta, en 200 y sin un error.
     */
    public function test_reemplazar_no_toca_las_del_grupo_ni_las_de_otro(): void
    {
        $ctx = $this->contexto();
        [$otro, $destino] = $this->dosAlumnos((int) $ctx->grupo_id);

        $this->marcarIndependiente($otro, (int) $ctx->periodo_id);
        $this->marcarIndependiente($destino, (int) $ctx->periodo_id);
        $this->unidadPropia($ctx, $otro);
        $this->unidadPropia($ctx, $destino);

        $delGrupoAntes = (int) DB::selectOne(
            'SELECT COUNT(*) c FROM unidades
              WHERE asignatura_id = ? AND periodo_id = ? AND alumno_id IS NULL AND deleted_at IS NULL',
            [$ctx->asignatura_id, $ctx->periodo_id]
        )->c;

        $this->copiar($ctx, [
            'alumnos_destino' => [$destino],
            'origen' => ['tipo' => 'grupo', 'periodo_id' => $ctx->periodo_id],
            'si_ya_tiene' => 'reemplazar',
        ])->assertStatus(200);

        $this->assertSame($delGrupoAntes, (int) DB::selectOne(
            'SELECT COUNT(*) c FROM unidades
              WHERE asignatura_id = ? AND periodo_id = ? AND alumno_id IS NULL AND deleted_at IS NULL',
            [$ctx->asignatura_id, $ctx->periodo_id]
        )->c, '`reemplazar` retiró unidades DEL GRUPO: eso le vacía la planilla a los treinta.');

        $this->assertSame(1, $this->suyasVivas($ctx, $otro),
            '`reemplazar` retiró la unidad de OTRO alumno.');
    }

    // ─────────────────────────────────────────────────────────────────────
    // con_notas
    // ─────────────────────────────────────────────────────────────────────

    /**
     * **`con_notas` entre periodos distintos es 422**, y lo corta el servidor a
     * propósito: desde la pantalla las dos casillas parecen igual de inocentes, y copiar
     * las notas del periodo 1 al 3 es **escribir en el 3 las calificaciones del 1**.
     */
    public function test_con_notas_entre_periodos_distintos_es_422(): void
    {
        $ctx = $this->contexto();
        [$destino] = $this->dosAlumnos((int) $ctx->grupo_id);

        $otroPeriodo = DB::selectOne(
            'SELECT p.id FROM periodos p
              INNER JOIN grupos g ON g.year_id = p.year_id AND g.id = ?
             WHERE p.id <> ? AND p.deleted_at IS NULL ORDER BY p.numero LIMIT 1',
            [$ctx->grupo_id, $ctx->periodo_id]
        );

        $this->assertNotNull($otroPeriodo, 'El año necesita dos periodos.');

        $this->copiar($ctx, [
            'alumnos_destino' => [$destino],
            'origen' => ['tipo' => 'grupo', 'periodo_id' => (int) $otroPeriodo->id],
            'con_notas' => true,
        ])->assertStatus(422);
    }

    /**
     * **Con origen `grupo` y el mismo periodo, `con_notas` trae las notas que el propio
     * destino ya tenía.**
     *
     * Es el caso que hace útil la operación: el alumno iba en la planilla, se le marca a
     * mitad de periodo y **se lleva lo suyo** en vez de empezar en blanco.
     */
    public function test_con_notas_desde_el_grupo_trae_las_del_propio_destino(): void
    {
        $ctx = $this->contexto();
        [$destino] = $this->dosAlumnos((int) $ctx->grupo_id);

        $subDelGrupo = DB::selectOne(
            'SELECT s.id FROM subunidades s
               INNER JOIN unidades u ON u.id = s.unidad_id AND u.asignatura_id = ? AND u.periodo_id = ?
                                    AND u.alumno_id IS NULL AND u.deleted_at IS NULL
              WHERE s.deleted_at IS NULL ORDER BY s.id LIMIT 1',
            [$ctx->asignatura_id, $ctx->periodo_id]
        );

        $this->assertNotNull($subDelGrupo);

        DB::table('notas')->where('subunidad_id', $subDelGrupo->id)->where('alumno_id', $destino)->delete();
        DB::table('notas')->insert([
            'nota' => 93, 'subunidad_id' => $subDelGrupo->id, 'alumno_id' => $destino,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->marcarIndependiente($destino, (int) $ctx->periodo_id);

        $r = $this->copiar($ctx, [
            'alumnos_destino' => [$destino],
            'origen' => ['tipo' => 'grupo', 'periodo_id' => $ctx->periodo_id],
            'con_notas' => true,
        ]);

        $r->assertStatus(200);
        $this->assertGreaterThan(0, $r->json('destinos.0.copiadas.notas'), 'No se copió ninguna nota.');

        $copiada = DB::selectOne(
            'SELECT n.nota FROM notas n
               INNER JOIN subunidades s ON s.id = n.subunidad_id AND s.deleted_at IS NULL
               INNER JOIN unidades u ON u.id = s.unidad_id AND u.alumno_id = ? AND u.deleted_at IS NULL
                                    AND u.asignatura_id = ? AND u.periodo_id = ?
              WHERE n.alumno_id = ? AND n.deleted_at IS NULL AND n.nota = 93 LIMIT 1',
            [$destino, $ctx->asignatura_id, $ctx->periodo_id, $destino]
        );

        $this->assertNotNull($copiada, 'El alumno no se llevó SU nota al boletín nuevo.');
    }

    /** Y con origen `alumno`, las del alumno de origen: calificar a dos de golpe. */
    public function test_con_notas_desde_otro_alumno_trae_las_de_ese_alumno(): void
    {
        $ctx = $this->contexto();
        [$origen, $destino] = $this->dosAlumnos((int) $ctx->grupo_id);

        $this->marcarIndependiente($origen, (int) $ctx->periodo_id);
        $this->marcarIndependiente($destino, (int) $ctx->periodo_id);
        [, $subOrigen] = $this->unidadPropia($ctx, $origen);

        DB::table('notas')->insert([
            'nota' => 61, 'subunidad_id' => $subOrigen, 'alumno_id' => $origen,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $r = $this->copiar($ctx, [
            'alumnos_destino' => [$destino],
            'origen' => ['tipo' => 'alumno', 'alumno_id' => $origen, 'periodo_id' => $ctx->periodo_id],
            'con_notas' => true,
        ]);

        $r->assertStatus(200);
        $this->assertSame(1, $r->json('destinos.0.copiadas.notas'));

        $this->assertNotNull(DB::selectOne(
            'SELECT n.id FROM notas n
               INNER JOIN subunidades s ON s.id = n.subunidad_id AND s.deleted_at IS NULL
               INNER JOIN unidades u ON u.id = s.unidad_id AND u.alumno_id = ? AND u.deleted_at IS NULL
              WHERE n.alumno_id = ? AND n.nota = 61 AND n.deleted_at IS NULL LIMIT 1',
            [$destino, $destino]
        ), 'La nota del alumno de origen no llegó al destino, que es lo que `con_notas` promete aquí.');
    }

    /** Sin `con_notas` no se copia ni una nota, aunque el origen las tenga. */
    public function test_sin_con_notas_no_viaja_ninguna(): void
    {
        $ctx = $this->contexto();
        [$origen, $destino] = $this->dosAlumnos((int) $ctx->grupo_id);

        $this->marcarIndependiente($origen, (int) $ctx->periodo_id);
        $this->marcarIndependiente($destino, (int) $ctx->periodo_id);
        [, $subOrigen] = $this->unidadPropia($ctx, $origen);

        DB::table('notas')->insert([
            'nota' => 61, 'subunidad_id' => $subOrigen, 'alumno_id' => $origen,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $r = $this->copiar($ctx, [
            'alumnos_destino' => [$destino],
            'origen' => ['tipo' => 'alumno', 'alumno_id' => $origen, 'periodo_id' => $ctx->periodo_id],
        ]);

        $r->assertStatus(200);
        $this->assertSame(0, $r->json('destinos.0.copiadas.notas'),
            'Se copiaron notas sin pedirlo: copiar estructura es preparar, copiar notas es calificar.');
    }

    // ─────────────────────────────────────────────────────────────────────
    // Los destinos y el cuerpo
    // ─────────────────────────────────────────────────────────────────────

    /**
     * **El que ya no va por independiente vuelve como `no_marcado`, nunca como 400.**
     *
     * La pantalla lo estaba listando de buena fe y que uno se desmarque entre la carga y
     * el clic es normal. Un 400 tumbaría el lote entero por un alumno.
     */
    public function test_el_que_no_va_por_independiente_vuelve_como_no_marcado(): void
    {
        $ctx = $this->contexto();
        [$marcado, $normal] = $this->dosAlumnos((int) $ctx->grupo_id);

        $this->marcarIndependiente($marcado, (int) $ctx->periodo_id);

        $r = $this->copiar($ctx, [
            'alumnos_destino' => [$marcado, $normal],
            'origen' => ['tipo' => 'grupo', 'periodo_id' => $ctx->periodo_id],
        ]);

        $r->assertStatus(200);

        $porAlumno = collect($r->json('destinos'))->keyBy('alumno_id');

        $this->assertSame('copiado', $porAlumno[$marcado]['resultado']);
        $this->assertSame('no_marcado', $porAlumno[$normal]['resultado'],
            'Un destino desmarcado tiene que volver como `no_marcado` y no tumbar el lote.');
        $this->assertSame(0, $this->suyasVivas($ctx, $normal), 'Se le escribió a un alumno no marcado.');
    }

    /** **La comprobación es contra el periodo de DESTINO**, no el de origen. */
    public function test_el_marcado_se_comprueba_en_el_periodo_de_destino(): void
    {
        $ctx = $this->contexto();
        [$destino] = $this->dosAlumnos((int) $ctx->grupo_id);

        $otro = DB::selectOne(
            'SELECT p.id FROM periodos p INNER JOIN grupos g ON g.year_id = p.year_id AND g.id = ?
              WHERE p.id <> ? AND p.deleted_at IS NULL ORDER BY p.numero LIMIT 1',
            [$ctx->grupo_id, $ctx->periodo_id]
        );

        $this->assertNotNull($otro);

        // Marcado en el periodo de ORIGEN y NO en el de destino: tiene que salir
        // `no_marcado`. Con la comprobación puesta en el origen saldría «copiado» y se le
        // escribiría estructura en un periodo que va con el grupo.
        $this->marcarIndependiente($destino, (int) $otro->id);

        $r = $this->copiar($ctx, [
            'alumnos_destino' => [$destino],
            'origen' => ['tipo' => 'grupo', 'periodo_id' => (int) $otro->id],
        ]);

        $r->assertStatus(200);
        $this->assertSame('no_marcado', $r->json('destinos.0.resultado'),
            'Se comprobó el periodo de ORIGEN: se le escribiría estructura propia en un periodo que '
            .'va con el grupo.');
    }

    /** `origen.asignatura_id` se rechaza en vez de ignorarse. */
    public function test_origen_asignatura_id_es_422(): void
    {
        $ctx = $this->contexto();
        [$destino] = $this->dosAlumnos((int) $ctx->grupo_id);

        $this->copiar($ctx, [
            'alumnos_destino' => [$destino],
            'origen' => ['tipo' => 'grupo', 'periodo_id' => $ctx->periodo_id, 'asignatura_id' => 99],
        ])->assertStatus(422);
    }

    /** El cuerpo mal formado es 422 y no un cero que se cuela. */
    public function test_el_cuerpo_mal_formado_es_422(): void
    {
        $ctx = $this->contexto();
        [$destino] = $this->dosAlumnos((int) $ctx->grupo_id);
        $ok = ['tipo' => 'grupo', 'periodo_id' => $ctx->periodo_id];

        $casos = [
            'sin alumnos_destino' => ['origen' => $ok],
            'alumnos_destino vacío' => ['alumnos_destino' => [], 'origen' => $ok],
            'alumnos_destino con basura' => ['alumnos_destino' => ['x'], 'origen' => $ok],
            'origen.tipo inventado' => ['alumnos_destino' => [$destino], 'origen' => ['tipo' => 'materia', 'periodo_id' => $ctx->periodo_id]],
            'origen alumno sin alumno_id' => ['alumnos_destino' => [$destino], 'origen' => ['tipo' => 'alumno', 'periodo_id' => $ctx->periodo_id]],
            'si_ya_tiene inventado' => ['alumnos_destino' => [$destino], 'origen' => $ok, 'si_ya_tiene' => 'borrar'],
            'con_notas cadena rara' => ['alumnos_destino' => [$destino], 'origen' => $ok, 'con_notas' => 'quizás'],
        ];

        foreach ($casos as $caso => $cuerpo) {
            $this->assertSame(422, $this->copiar($ctx, $cuerpo)->getStatusCode(),
                "El caso '{$caso}' no dio 422.");
        }
    }

    /** Un alumno repetido en la lista no recibe la estructura dos veces. */
    public function test_un_destino_repetido_no_se_copia_dos_veces(): void
    {
        $ctx = $this->contexto();
        [$origen, $destino] = $this->dosAlumnos((int) $ctx->grupo_id);

        $this->marcarIndependiente($origen, (int) $ctx->periodo_id);
        $this->marcarIndependiente($destino, (int) $ctx->periodo_id);
        $this->unidadPropia($ctx, $origen);

        $r = $this->copiar($ctx, [
            'alumnos_destino' => [$destino, $destino],
            'origen' => ['tipo' => 'alumno', 'alumno_id' => $origen, 'periodo_id' => $ctx->periodo_id],
        ]);

        $r->assertStatus(200);
        $this->assertCount(1, $r->json('destinos'));
        $this->assertSame(1, $this->suyasVivas($ctx, $destino),
            'El alumno repetido recibió la estructura dos veces y se le dobló la suma.');
    }

    /** Un alumno no entra: la pantalla es del personal. */
    public function test_un_alumno_no_entra(): void
    {
        $ctx = $this->contexto();
        [$destino] = $this->dosAlumnos((int) $ctx->grupo_id);

        $r = $this->withToken($this->tokenDe($this->usuarioDeTipo('Alumno')->username))
            ->postJson(self::RUTA, [
                'asignatura_id' => $ctx->asignatura_id,
                'periodo_id' => $ctx->periodo_id,
                'alumnos_destino' => [$destino],
                'origen' => ['tipo' => 'grupo', 'periodo_id' => $ctx->periodo_id],
            ]);

        $this->assertNotEquals(200, $r->getStatusCode(), 'Un alumno copió estructura de boletines.');
        $this->assertSame(0, $this->suyasVivas($ctx, $destino));
    }

    // ─────────────────────────────────────────────────────────────────────
    // El TERCER origen: la plantilla del colegio (Entrega 4, D18)
    // ─────────────────────────────────────────────────────────────────────

    /** Las dos coordenadas de la asignatura del contexto: por ahí se dirige la plantilla. */
    private function coordenadas(object $ctx): object
    {
        $fila = DB::selectOne(
            'SELECT a.materia_id, g2.nivel_educativo_id
               FROM asignaturas a
               INNER JOIN grupos g ON g.id = a.grupo_id
               INNER JOIN grados g2 ON g2.id = g.grado_id
              WHERE a.id = ?',
            [$ctx->asignatura_id]
        );

        $this->assertNotNull($fila, 'La asignatura del contexto no llega a un grado: no se puede dirigir la plantilla.');

        return $fila;
    }

    /** Una fila de plantilla del año, dirigida a donde se le diga, con `$subunidades` dentro. */
    private function filaDePlantilla(object $ctx, string $definicion, ?int $nivelId, ?int $materiaId,
        int $porcentaje = 100, int $subunidades = 1): int
    {
        $id = (int) DB::table('unidades_por_defecto')->insertGetId([
            'definicion' => $definicion,
            'porcentaje' => $porcentaje,
            'year_id' => $ctx->year_id,
            'nivel_educativo_id' => $nivelId,
            'materia_id' => $materiaId,
            'obligatoria' => 0,
            'orden' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        for ($i = 1; $i <= $subunidades; $i++) {
            DB::table('subunidades_por_defecto')->insert([
                'definicion' => $definicion.' · sub '.$i,
                'porcentaje' => (int) (100 / $subunidades),
                'unidad_defec_id' => $id,
                'nota_default' => 0,
                'obligatoria' => 0,
                'orden' => $i,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $id;
    }

    /** Las definiciones de las unidades propias del alumno, en su orden. */
    private function definicionesSuyas(object $ctx, int $alumnoId): array
    {
        return array_map(static fn ($u) => $u->definicion, DB::select(
            'SELECT definicion FROM unidades
              WHERE alumno_id = ? AND asignatura_id = ? AND periodo_id = ? AND deleted_at IS NULL
              ORDER BY orden, id',
            [$alumnoId, $ctx->asignatura_id, $ctx->periodo_id]
        ));
    }

    /**
     * **La plantilla del colegio se copia, con sus subunidades dentro.**
     *
     * Se cuentan las filas y no el 200, por lo mismo que las otras dos ramas: ésta lee
     * **otra tabla** —`unidades_por_defecto`— y una condición de más deja cero filas
     * copiadas y un «copiado» en la respuesta.
     */
    public function test_origen_plantilla_copia_las_filas_del_colegio(): void
    {
        $ctx = $this->contexto();
        [$destino] = $this->dosAlumnos((int) $ctx->grupo_id);

        $this->marcarIndependiente($destino, (int) $ctx->periodo_id);
        $this->filaDePlantilla($ctx, 'Plantilla del colegio', null, null, 100, 3);

        $this->assertSame(0, $this->suyasVivas($ctx, $destino));

        $r = $this->copiar($ctx, [
            'alumnos_destino' => [$destino],
            'origen' => ['tipo' => 'plantilla'],
        ]);

        $r->assertStatus(200);

        $this->assertSame(['Plantilla del colegio'], $this->definicionesSuyas($ctx, $destino),
            'El origen `plantilla` no copió la fila del colegio: si lee `unidades` en vez de '
            .'`unidades_por_defecto` la consulta devuelve cero filas y la respuesta dice «copiado».');

        $this->assertSame('plantilla', $r->json('origen.tipo'));
        $this->assertNull($r->json('origen.periodo_id'), 'La plantilla no es de ningún periodo.');
        $this->assertNull($r->json('origen.alumno_id'));
        $this->assertSame(1, $r->json('origen.unidades'));
        $this->assertSame(3, $r->json('origen.subunidades'));
        $this->assertSame(3, $r->json('destinos.0.copiadas.subunidades'),
            'Las subunidades de plantilla no viajaron con su unidad.');
    }

    /**
     * **La precedencia es la de `AlcanceDeLaPlantilla` y se aplica la GRADA ENTERA.**
     *
     * Con una fila general y otra dirigida a la materia de esta asignatura, se copia
     * **sólo la segunda**. Los dos rojos que este caso caza son distintos:
     *
     *   - copiar **las dos** —el fallo de quien lee las candidatas y no resuelve la
     *     grada— deja al alumno con un reparto del 200 % que nadie escribió;
     *   - copiar **la general** es haber escrito aquí una segunda regla de precedencia
     *     que no coincide con la que el colegio ve en su pantalla.
     *
     * **La general se inserta PRIMERO a propósito**: así un `ORDER BY id LIMIT 1` —que
     * es lo que sale solo— se queda con la equivocada. Es el mismo criterio con el que
     * `AlcanceDeLaPlantillaTest` invierte el orden de inserción.
     */
    public function test_origen_plantilla_respeta_la_precedencia_y_no_mezcla_gradas(): void
    {
        $ctx = $this->contexto();
        $coordenadas = $this->coordenadas($ctx);
        [$destino] = $this->dosAlumnos((int) $ctx->grupo_id);

        $this->marcarIndependiente($destino, (int) $ctx->periodo_id);

        $this->filaDePlantilla($ctx, 'La de siempre', null, null);
        $this->filaDePlantilla($ctx, 'La de esta materia', null, (int) $coordenadas->materia_id);

        $r = $this->copiar($ctx, [
            'alumnos_destino' => [$destino],
            'origen' => ['tipo' => 'plantilla'],
        ]);

        $r->assertStatus(200);

        $this->assertSame(['La de esta materia'], $this->definicionesSuyas($ctx, $destino),
            'La precedencia de la plantilla no se respetó: o se copiaron las dos gradas —y la suma '
            .'sale 200— o ganó la general, que es una segunda regla de precedencia escrita aquí.');
    }

    /**
     * **`origen.periodo_id` con `plantilla` es 422, y no un campo que se ignora.**
     *
     * La plantilla es del AÑO: `unidades_por_defecto` no tiene `periodo_id`. Un campo que
     * se manda y se ignora es el que hace creer que se copió otra cosa —aquí, «la
     * plantilla del periodo 1»—. Y se comprueba que **no escribió nada**: un 422 que ya
     * hubiera copiado sería peor que el 200.
     */
    public function test_origen_plantilla_con_periodo_id_es_422(): void
    {
        $ctx = $this->contexto();
        [$destino] = $this->dosAlumnos((int) $ctx->grupo_id);

        $this->marcarIndependiente($destino, (int) $ctx->periodo_id);
        $this->filaDePlantilla($ctx, 'Plantilla del colegio', null, null);

        $r = $this->copiar($ctx, [
            'alumnos_destino' => [$destino],
            'origen' => ['tipo' => 'plantilla', 'periodo_id' => $ctx->periodo_id],
        ]);

        $r->assertStatus(422);
        $this->assertStringContainsString('del AÑO', (string) $r->json('message'));
        $this->assertSame(0, $this->suyasVivas($ctx, $destino), 'El 422 llegó después de escribir.');
    }

    /** Y `origen.alumno_id` tampoco: la plantilla no es de nadie. */
    public function test_origen_plantilla_con_alumno_id_es_422(): void
    {
        $ctx = $this->contexto();
        [$otro, $destino] = $this->dosAlumnos((int) $ctx->grupo_id);

        $this->marcarIndependiente($destino, (int) $ctx->periodo_id);
        $this->filaDePlantilla($ctx, 'Plantilla del colegio', null, null);

        $r = $this->copiar($ctx, [
            'alumnos_destino' => [$destino],
            'origen' => ['tipo' => 'plantilla', 'alumno_id' => $otro],
        ]);

        $r->assertStatus(422);
        $this->assertSame(0, $this->suyasVivas($ctx, $destino));
    }

    /**
     * **`con_notas` con `plantilla` es 422, y éste es el peligroso.**
     *
     * Las subunidades de plantilla viven en `subunidades_por_defecto`, **otra tabla con
     * su propia secuencia de ids**. Sin este candado, la consulta de `copiarLaNota()`
     * buscaría en `notas` por un `subunidad_id` que casa con una subunidad **real
     * cualquiera** y le copiaría al destino la nota de un desconocido, en 200 y sin que
     * nada lo señale. Es el único 422 de los tres que protege un dato y no una idea.
     */
    public function test_origen_plantilla_con_notas_es_422(): void
    {
        $ctx = $this->contexto();
        [$destino] = $this->dosAlumnos((int) $ctx->grupo_id);

        $this->marcarIndependiente($destino, (int) $ctx->periodo_id);
        $this->filaDePlantilla($ctx, 'Plantilla del colegio', null, null);

        $r = $this->copiar($ctx, [
            'alumnos_destino' => [$destino],
            'origen' => ['tipo' => 'plantilla'],
            'con_notas' => true,
        ]);

        $r->assertStatus(422);
        $this->assertStringContainsString('no tiene notas', (string) $r->json('message'),
            'El 422 que salió es el de los periodos distintos, que aquí diría lo que no es.');
        $this->assertSame(0, $this->suyasVivas($ctx, $destino));
    }

    /**
     * **Sin ninguna fila que le toque, 422 y no un «copiado» de cero.**
     *
     * Es el fallo mudo de esta familia dicho en voz alta: copiar cero unidades y
     * contestar 200 deja al docente con la planilla en blanco creyendo que la montó. Y es
     * el caso de **todas** las asignaturas de un colegio que no haya escrito su plantilla
     * —en `simonbolivar`, `unidades_por_defecto` tiene hoy cero filas—.
     */
    public function test_origen_plantilla_sin_ninguna_fila_que_le_toque_es_422(): void
    {
        $ctx = $this->contexto();
        [$destino] = $this->dosAlumnos((int) $ctx->grupo_id);

        $this->marcarIndependiente($destino, (int) $ctx->periodo_id);

        $r = $this->copiar($ctx, [
            'alumnos_destino' => [$destino],
            'origen' => ['tipo' => 'plantilla'],
        ]);

        $r->assertStatus(422);
        $this->assertStringContainsString('plantilla', (string) $r->json('message'));
        $this->assertSame(0, $this->suyasVivas($ctx, $destino));
    }

    /** Un `origen.tipo` inventado sigue siendo 422, y el mensaje nombra los tres. */
    public function test_un_origen_tipo_inventado_nombra_los_tres(): void
    {
        $ctx = $this->contexto();
        [$destino] = $this->dosAlumnos((int) $ctx->grupo_id);

        $r = $this->copiar($ctx, [
            'alumnos_destino' => [$destino],
            'origen' => ['tipo' => 'colegio'],
        ]);

        $r->assertStatus(422);
        $this->assertStringContainsString('plantilla', (string) $r->json('message'));
    }
}
