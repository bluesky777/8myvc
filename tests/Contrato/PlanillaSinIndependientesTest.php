<?php

namespace Tests\Contrato;

use App\Models\Nota;
use App\Services\BoletinIndependiente;
use Illuminate\Support\Facades\DB;

/**
 * **La fase 3 del [19](../../docs/migracion/19-boletin-independiente.md): la planilla del grupo
 * deja de ser la de todo el mundo.**
 *
 * Es la petición literal del colegio —*«que no aparezca en esa planilla de notas
 * normales»*— y tiene tres mitades, no una:
 *
 * 1. `putDetailed` **no devuelve** al independiente entre `alumnos`, y **sí** lo
 *    nombra en `independientes`, para que la pantalla pueda decir a quién no está
 *    enseñando en vez de que el docente lo dé por perdido;
 * 2. la rejilla de `unidades` es **la del grupo**, así que las unidades propias de
 *    un marcado no salen ahí;
 * 3. `Nota::verificarCrearNotas` **deja de sembrarle** las notas de las subunidades
 *    del grupo — y al revés, cuando la unidad tiene dueño siembra **una** y no
 *    treinta (§6.5).
 *
 * ## Por qué este fichero construye los escenarios en vez de mirar snapshots
 *
 * **Con nadie marcado la forma correcta y la incorrecta dan el mismo verde**, y no
 * por poco: `bol_ind_periodos` nace vacía, así que `alcance()` devuelve `null` para
 * todo el mundo y `u.alumno_id <=> NULL` selecciona exactamente las filas de hoy.
 * La suite entera —1.586 pruebas— no puede ver ninguno de estos cuatro casos. Por
 * eso aquí se marca a un alumno, se le monta lo suyo y se comprueban **las dos
 * direcciones**: que al marcado no le llegue lo del grupo (de menos) y que al grupo
 * no le llegue lo del marcado (de más).
 *
 * **Comprobado en rojo contra el código de antes**, que es lo que separa un test que
 * mide el arreglo de uno escrito después: los cuatro casos fallan con las cuatro
 * líneas revertidas, y el desglose está en
 * [`noche-2026-08-31/b.md`](../../docs/migracion/noche-2026-08-31/b.md).
 */
class PlanillaSinIndependientesTest extends CasoDeContrato
{
    /**
     * Una asignatura del seed con su profesor, su periodo y sus alumnos, todo cuadrado.
     *
     * Es la misma de `NotasTest::contexto()` y por las mismas razones —`putDetailed`
     * sólo recorre las unidades del periodo del CONTEXTO, así que elegir la
     * asignatura por un lado y el periodo por otro deja la rejilla vacía y el test
     * pasando sin ejecutar nada—. Se copia en vez de compartirse porque tocar la de
     * `NotasTest` movería el escenario de sus diecinueve casos.
     */
    private function contexto(): object
    {
        $fila = DB::selectOne('SELECT a.id AS asignatura_id, a.grupo_id, a.profesor_id,
                u.username, un.periodo_id
            FROM asignaturas a
            INNER JOIN profesores p ON p.id = a.profesor_id AND p.deleted_at IS NULL
            INNER JOIN users u ON u.id = p.user_id AND u.is_active = 1 AND u.deleted_at IS NULL
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

    /** @return list<int> Los alumnos de ese grupo, tal como los ve la planilla. */
    private function alumnosDelGrupo(int $grupoId): array
    {
        $filas = DB::select('SELECT m.alumno_id FROM matriculas m
            WHERE m.grupo_id = ? AND m.deleted_at IS NULL
              AND m.estado IN ("MATR","ASIS","PREM")
            ORDER BY m.alumno_id', [$grupoId]);

        $this->assertGreaterThanOrEqual(2, count($filas),
            'Hacen falta dos alumnos: uno marcado y otro que siga en la planilla.');

        return array_values(array_map(static fn ($f) => (int) $f->alumno_id, $filas));
    }

    private function rejilla(object $ctx): array
    {
        $token = $this->tokenDe($ctx->username);

        $r = $this->putJson('/api/notas/detailed', [
            'asignatura_id' => $ctx->asignatura_id,
            'profesor_id' => $ctx->profesor_id,
        ], ['Authorization' => 'Bearer '.$token]);

        $r->assertStatus(200);

        return $r->json();
    }

    /** Una unidad propia del marcado, con una subunidad dentro. */
    private function unidadPropia(object $ctx, int $alumnoId): int
    {
        DB::insert(
            'INSERT INTO unidades (definicion, porcentaje, asignatura_id, periodo_id, alumno_id, orden, created_at, updated_at)
             VALUES ("Sólo del independiente", 100, ?, ?, ?, 0, NOW(), NOW())',
            [$ctx->asignatura_id, $ctx->periodo_id, $alumnoId]
        );

        return (int) DB::getPdo()->lastInsertId();
    }

    // ------------------------------------------------------------------ (1)

    /**
     * El marcado sale de `alumnos` y entra en `independientes`.
     *
     * Y `independientes` lleva **exactamente tres claves**. La cuarta que se pidió y
     * no entra es `aplica`: ese array lista justo a los que tienen alcance, así que
     * valdría `true` por construcción, y un campo constante es uno sobre el que
     * alguien ramificará sin que su rama muerta se note nunca (§6.4).
     */
    public function test_la_planilla_no_trae_al_marcado_y_dice_a_quien_no_esta_enseñando(): void
    {
        BoletinIndependiente::olvidar();

        $ctx = $this->contexto();
        $alumnos = $this->alumnosDelGrupo((int) $ctx->grupo_id);
        $marcado = $alumnos[0];
        $normal = $alumnos[1];

        $antes = $this->rejilla($ctx);
        $this->assertContains($marcado, array_column($antes['alumnos'], 'alumno_id'),
            'Sin marcar, el alumno tiene que estar en la planilla: si no, este test no compara nada.');
        $this->assertSame([], $antes['independientes'],
            'Con nadie marcado, `independientes` viene vacío — es el caso de todos los colegios de hoy.');

        $this->marcarIndependiente($marcado, (int) $ctx->periodo_id);

        $despues = $this->rejilla($ctx);
        $ids = array_column($despues['alumnos'], 'alumno_id');

        $this->assertNotContains($marcado, $ids,
            'El marcado sigue en la planilla del grupo: es justo lo que el colegio pidió que no pasara.');
        $this->assertContains($normal, $ids,
            'Marcar a uno se llevó por delante a los demás.');

        $this->assertCount(1, $despues['independientes']);
        $this->assertSame(
            ['alumno_id', 'nombres', 'apellidos'],
            array_keys($despues['independientes'][0]),
            '`independientes` lleva tres claves y ninguna más: `aplica` sería `true` por construcción.'
        );
        $this->assertSame($marcado, (int) $despues['independientes'][0]['alumno_id']);

        // Las dos listas parten la misma población y no se solapan: es lo que
        // garantiza partirlas de una sola pasada sobre `Grupo::alumnos()` en vez de
        // preguntarle la segunda a otra consulta con otra población.
        $this->assertSame(
            count($antes['alumnos']),
            count($despues['alumnos']) + count($despues['independientes']),
            'Alguien se cayó entre las dos listas.'
        );
    }

    // ------------------------------------------------------------------ (2)

    /**
     * La rejilla de `unidades` es la del grupo, y esto **escribe**.
     *
     * No se queda en pintar de más: `$unidadesT` es lo que alimenta a
     * `verificarCrearNotas`, así que una unidad ajena en esa lista le siembra a los
     * treinta una fila de `notas` dentro del boletín de otro — y esas filas cuentan
     * en la definitiva.
     */
    public function test_la_rejilla_del_grupo_no_trae_las_unidades_del_marcado(): void
    {
        BoletinIndependiente::olvidar();

        $ctx = $this->contexto();
        $alumnos = $this->alumnosDelGrupo((int) $ctx->grupo_id);
        $marcado = $alumnos[0];

        $this->marcarIndependiente($marcado, (int) $ctx->periodo_id);
        $unidadPropia = $this->unidadPropia($ctx, $marcado);

        DB::insert(
            'INSERT INTO subunidades (definicion, porcentaje, unidad_id, nota_default, orden, created_at, updated_at)
             VALUES ("Suya", 100, ?, 1, 0, NOW(), NOW())',
            [$unidadPropia]
        );
        $subunidadPropia = (int) DB::getPdo()->lastInsertId();

        $r = $this->rejilla($ctx);

        $this->assertNotContains($unidadPropia, array_column($r['unidades'], 'id'),
            'La unidad de un independiente sale en la rejilla del curso.');

        $this->assertSame(0,
            DB::table('notas')->where('subunidad_id', $subunidadPropia)->count(),
            'Abrir la planilla del grupo sembró notas dentro del boletín del independiente.');
    }

    // ------------------------------------------------------------------ (3)

    /**
     * Al marcado no se le crean las notas de las subunidades del grupo — y a los
     * demás sí, que es la mitad que impide «arreglarlo» dejando de sembrar a nadie.
     */
    public function test_al_marcado_no_se_le_siembran_las_notas_del_grupo(): void
    {
        BoletinIndependiente::olvidar();

        $ctx = $this->contexto();
        $alumnos = $this->alumnosDelGrupo((int) $ctx->grupo_id);
        $marcado = $alumnos[0];
        $normal = $alumnos[1];

        $subunidad = DB::selectOne('SELECT s.id FROM subunidades s
            INNER JOIN unidades un ON un.id = s.unidad_id AND un.asignatura_id = ?
                AND un.periodo_id = ? AND un.alumno_id IS NULL AND un.deleted_at IS NULL
            WHERE s.deleted_at IS NULL ORDER BY s.id LIMIT 1',
            [$ctx->asignatura_id, $ctx->periodo_id]);

        $this->assertNotNull($subunidad, 'El seed necesita una subunidad del grupo en ese periodo.');

        // Se vacía la subunidad para que sembrar sea observable: si las notas ya
        // están, el `NOT EXISTS` no inserta y el test no distingue nada.
        DB::table('notas')->where('subunidad_id', $subunidad->id)->delete();

        $this->marcarIndependiente($marcado, (int) $ctx->periodo_id);

        $this->rejilla($ctx);

        $this->assertSame(0,
            DB::table('notas')->where('subunidad_id', $subunidad->id)->where('alumno_id', $marcado)->count(),
            'Al independiente se le creó la casilla de una subunidad del grupo.');

        $this->assertSame(1,
            DB::table('notas')->where('subunidad_id', $subunidad->id)->where('alumno_id', $normal)->count(),
            'Se dejó de sembrar a todo el mundo, no sólo al marcado: eso rompe la planilla del curso.');
    }

    // ------------------------------------------------------------------ (4)

    /**
     * §6.5: cuando la unidad tiene dueño, nace **una** nota y no treinta.
     *
     * Se llama al modelo y no a `POST subunidades` a propósito: la ruta exige el
     * periodo abierto y un token que pueda editar, y lo que aquí se mide es a quién
     * se le siembra, no quién puede. Los dos llamadores pasan por este método.
     */
    public function test_la_subunidad_de_una_unidad_con_dueño_siembra_una_nota_y_no_treinta(): void
    {
        BoletinIndependiente::olvidar();

        $ctx = $this->contexto();
        $alumnos = $this->alumnosDelGrupo((int) $ctx->grupo_id);
        $marcado = $alumnos[0];

        $this->marcarIndependiente($marcado, (int) $ctx->periodo_id);
        $unidadPropia = $this->unidadPropia($ctx, $marcado);

        DB::insert(
            'INSERT INTO subunidades (definicion, porcentaje, unidad_id, nota_default, orden, created_at, updated_at)
             VALUES ("Suya", 100, ?, 7, 0, NOW(), NOW())',
            [$unidadPropia]
        );
        $subunidadPropia = (int) DB::getPdo()->lastInsertId();

        Nota::verificarCrearNotas(
            (int) $ctx->grupo_id,
            (object) ['id' => $subunidadPropia, 'unidad_id' => $unidadPropia, 'nota_default' => 7],
            1
        );

        $creadas = DB::table('notas')->where('subunidad_id', $subunidadPropia)->get();

        $this->assertCount(1, $creadas,
            'Una subunidad de una unidad con dueño le nació al grupo entero: veintinueve alumnos con una fila dentro del boletín de otro.');
        $this->assertSame($marcado, (int) $creadas[0]->alumno_id);
        $this->assertEquals(7, $creadas[0]->nota, 'La nota nace en el valor por defecto de su subunidad.');
    }

    // ------------------------------------------------------------------ (5)

    /**
     * La definitiva automática de un alumno **normal** no cuenta las notas que le
     * quedaron dentro de la unidad de un independiente.
     *
     * Es el sitio `:156` de la fase 1 y es el que sobrevive a la fase 3: al marcado
     * ya no se le pregunta esta consulta —no viene en `alumnos`—, pero al normal sí,
     * y sus filas dentro del boletín ajeno son exactamente lo que la versión vieja de
     * `verificarCrearNotas` sembraba. Sale por pantalla de la peor manera: la columna
     * «automática» inflada **al lado de la guardada, que es la correcta**.
     */
    public function test_la_definitiva_automatica_no_cuenta_las_notas_dentro_del_boletin_ajeno(): void
    {
        BoletinIndependiente::olvidar();

        $ctx = $this->contexto();
        $alumnos = $this->alumnosDelGrupo((int) $ctx->grupo_id);
        $marcado = $alumnos[0];
        $normal = $alumnos[1];

        $this->marcarIndependiente($marcado, (int) $ctx->periodo_id);
        $unidadPropia = $this->unidadPropia($ctx, $marcado);

        DB::insert(
            'INSERT INTO subunidades (definicion, porcentaje, unidad_id, nota_default, orden, created_at, updated_at)
             VALUES ("Suya", 100, ?, 1, 0, NOW(), NOW())',
            [$unidadPropia]
        );
        $subunidadPropia = (int) DB::getPdo()->lastInsertId();

        // El resto que dejó la siembra vieja: el alumno normal con una nota de 100
        // dentro de la unidad del independiente. No se borra al marcar —marcar no
        // borra nada— así que la consulta se la encontrará si no lleva alcance.
        DB::insert(
            'INSERT INTO notas (subunidad_id, alumno_id, nota, created_by, created_at, updated_at)
             VALUES (?, ?, 100, 1, NOW(), NOW())',
            [$subunidadPropia, $normal]
        );

        $r = $this->rejilla($ctx);

        $fila = null;
        foreach ($r['alumnos'] as $a) {
            if ((int) $a['alumno_id'] === $normal) {
                $fila = $a;
            }
        }

        $this->assertNotNull($fila, 'El alumno normal tiene que seguir en la planilla.');

        // La unidad ajena vale 100 % con una subunidad al 100 % y una nota de 100:
        // si se colara, aportaría 100 puntos enteros a la definitiva automática.
        $this->assertLessThan(100, (float) $fila['nota_final']['def_materia_auto'],
            'La definitiva automática se comió las notas que este alumno tiene dentro del boletín de otro.');
    }
}
