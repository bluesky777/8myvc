<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * `PUT boletin-independiente/planilla` — la pantalla del docente, §6.1 del
 * [19](../../docs/migracion/19-boletin-independiente.md).
 *
 * ## Lo que estos tests miran, y por qué no es la forma de la respuesta
 *
 * La forma la fija una instantánea. Lo que aquí se construye son los estados que
 * **con nadie marcado son inalcanzables**, que es la regla de la noche: con la tabla
 * vacía la respuesta correcta y la incorrecta salen iguales, así que un test escrito
 * sobre el seed tal cual pasaría con el endpoint mal escrito.
 *
 * Los cuatro que importan:
 *
 *   1. **`aplica: false` con datos propios** — el que la §1 pide que conviva: *«no debe
 *      borrar los datos … pero esos datos deben ser ignorados»*. Sale en la lista y sale
 *      con **sus** unidades, no con las del curso. Es el caso donde leer por alcance en
 *      vez de por propiedad da lo contrario de lo pedido.
 *   2. **`sin_estructura_propia`** — marcado y sin nada suyo. La §9.1, el alumno que se
 *      cae por el hueco, y el único que la pantalla tiene que gritar.
 *   3. **`vaciada`** — tuvo unidades propias y están todas borradas. Sólo se distingue
 *      mirando `deleted_at`.
 *   4. **`asignatura_sin_montar`** — tampoco hay unidades del grupo.
 */
class BoletinIndependientePlanillaTest extends CasoDeContrato
{
    private const RUTA = '/api/boletin-independiente/planilla';

    /**
     * Una asignatura del año actual con unidades **del grupo** en el periodo actual.
     *
     * Es el mismo montaje que `PlanillaSinIndependientesTest::contexto()` y por la misma
     * razón: el periodo sale del token, así que elegir la asignatura por un lado y el
     * periodo por otro deja la respuesta vacía y el test pasando sin ejecutar nada.
     */
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

    /** @return list<int> Dos alumnos del grupo, para poder distinguir a uno del otro. */
    private function dosAlumnos(int $grupoId): array
    {
        $filas = DB::select(
            'SELECT m.alumno_id FROM matriculas m
              WHERE m.grupo_id = ? AND m.deleted_at IS NULL AND m.estado IN ("MATR","ASIS","PREM")
              ORDER BY m.alumno_id LIMIT 2',
            [$grupoId]
        );

        $this->assertCount(2, $filas, 'Hacen falta dos alumnos en el grupo para distinguir los casos.');

        return array_values(array_map(static fn ($f) => (int) $f->alumno_id, $filas));
    }

    /** Una unidad **con dueño**, con una subunidad dentro. */
    private function unidadPropia(object $ctx, int $alumnoId, int $porcentaje = 100): int
    {
        $unidad = (int) DB::table('unidades')->insertGetId([
            'definicion' => 'Unidad propia (test)',
            'porcentaje' => $porcentaje,
            'periodo_id' => $ctx->periodo_id,
            'asignatura_id' => $ctx->asignatura_id,
            'alumno_id' => $alumnoId,
            'orden' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('subunidades')->insert([
            'definicion' => 'Subunidad propia (test)',
            'porcentaje' => 100,
            'unidad_id' => $unidad,
            'nota_default' => 0,
            'orden' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $unidad;
    }

    private function planilla(object $ctx): array
    {
        $r = $this->withToken($this->tokenDelPersonalDe((int) $ctx->year_id))
            ->putJson(self::RUTA, ['asignatura_id' => $ctx->asignatura_id]);

        $r->assertStatus(200);

        return $r->json();
    }

    /** @return array<int, array<string, mixed>> Los alumnos de la respuesta, por id. */
    private function porAlumno(array $respuesta): array
    {
        $salida = [];

        foreach ($respuesta['alumnos'] as $alumno) {
            $salida[(int) $alumno['alumno_id']] = $alumno;
        }

        return $salida;
    }

    // ─────────────────────────────────────────────────────────────────────
    // A quién lista
    // ─────────────────────────────────────────────────────────────────────

    /**
     * **Sin nadie marcado la lista está vacía, y la respuesta NO lo es.**
     *
     * Es el caso de todos los colegios hoy, y lo que se comprueba es que la pantalla
     * pueda abrirse igual: la asignatura, el periodo y `estructura_del_grupo` llegan,
     * así que el diálogo de copiar tiene de dónde leer aunque no haya nadie a quien
     * copiarle todavía.
     */
    public function test_sin_nadie_marcado_la_lista_esta_vacia_pero_la_respuesta_no(): void
    {
        $ctx = $this->contexto();
        $r = $this->planilla($ctx);

        $this->assertSame([], $r['alumnos'], 'Sin nadie marcado no hay boletines aparte que gobernar.');
        $this->assertSame((int) $ctx->asignatura_id, (int) $r['asignatura']['asignatura_id']);
        $this->assertSame((int) $ctx->periodo_id, $r['periodo']['periodo_id']);
        $this->assertNotEmpty($r['estructura_del_grupo'], 'La vista previa de copiar se quedó sin datos.');
    }

    /** Un alumno del grupo sin marca y sin nada suyo NO sale: no hay nada que gobernarle. */
    public function test_el_alumno_normal_no_sale_en_la_lista(): void
    {
        $ctx = $this->contexto();
        [$marcado, $normal] = $this->dosAlumnos((int) $ctx->grupo_id);

        $this->marcarIndependiente($marcado, (int) $ctx->periodo_id);

        $lista = $this->porAlumno($this->planilla($ctx));

        $this->assertArrayHasKey($marcado, $lista);
        $this->assertArrayNotHasKey($normal, $lista,
            'Un alumno sin marca y sin estructura propia salió en la planilla del boletín aparte.');
    }

    /**
     * **El `aplica: false` CON datos propios sale, y sale con LO SUYO.**
     *
     * Es el caso entero por el que esta pantalla existe y **el que distingue leer por
     * propiedad de leer por alcance**. `BoletinIndependiente::alcance()` devuelve `null`
     * para un `aplica = false`, así que una lectura por alcance le devolvería **las
     * unidades del curso** pintadas como si fueran suyas — y el docente creería que su
     * boletín aparte se ha llenado solo.
     *
     * Lo que la §1 pide es lo contrario: *«no debe borrar los datos … pero esos datos
     * deben ser ignorados»*. Aquí es donde se ven los que se están ignorando.
     */
    public function test_el_desmarcado_con_datos_sale_con_sus_unidades_y_no_con_las_del_curso(): void
    {
        $ctx = $this->contexto();
        [$alumno] = $this->dosAlumnos((int) $ctx->grupo_id);

        $this->marcarIndependiente($alumno, (int) $ctx->periodo_id, aplica: false);
        $unidad = $this->unidadPropia($ctx, $alumno);

        $lista = $this->porAlumno($this->planilla($ctx));

        $this->assertArrayHasKey($alumno, $lista,
            'El alumno con estructura propia y el periodo yendo con el grupo desapareció de la pantalla '
            .'que existe para enseñar justamente eso.');

        $fila = $lista[$alumno];

        $this->assertFalse($fila['aplica'], 'La marca estaba apagada y la respuesta dice que no.');

        $ids = array_map(static fn ($u) => $u['unidad_id'], $fila['unidades']);

        $this->assertSame([$unidad], $ids,
            'La planilla le devolvió unidades que no son suyas: se está leyendo por alcance y no por propiedad.');

        $this->assertArrayNotHasKey('motivo', $fila, 'Tiene unidades: no hay vacío que explicar.');
    }

    /** Y con sus subunidades dentro, que es lo que la pantalla pinta. */
    public function test_las_unidades_traen_sus_subunidades(): void
    {
        $ctx = $this->contexto();
        [$alumno] = $this->dosAlumnos((int) $ctx->grupo_id);

        $this->marcarIndependiente($alumno, (int) $ctx->periodo_id);
        $this->unidadPropia($ctx, $alumno);

        $fila = $this->porAlumno($this->planilla($ctx))[$alumno];

        $this->assertCount(1, $fila['unidades']);
        $this->assertCount(1, $fila['unidades'][0]['subunidades'],
            'La unidad llegó sin sus subunidades: la pantalla no tiene dónde poner la nota.');
        $this->assertArrayHasKey('nota', $fila['unidades'][0]['subunidades'][0]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Los tres motivo
    // ─────────────────────────────────────────────────────────────────────

    /** Marcado y sin nada suyo, con el grupo montado: la §9.1, el que hay que gritar. */
    public function test_motivo_sin_estructura_propia(): void
    {
        $ctx = $this->contexto();
        [$alumno] = $this->dosAlumnos((int) $ctx->grupo_id);

        $this->marcarIndependiente($alumno, (int) $ctx->periodo_id);

        $fila = $this->porAlumno($this->planilla($ctx))[$alumno];

        $this->assertSame([], $fila['unidades']);
        $this->assertSame('sin_estructura_propia', $fila['motivo']);
    }

    /**
     * Tuvo unidades propias y están todas borradas.
     *
     * **Se comprueba antes que `asignatura_sin_montar` a propósito**, y aquí se fija ese
     * orden: es un hecho sobre **este alumno**, no sobre la asignatura. Al revés, a quien
     * le vaciaron el boletín en una asignatura sin montar se le diría «el docente no ha
     * entrado», que es lo contrario de lo que pasó.
     */
    public function test_motivo_vaciada(): void
    {
        $ctx = $this->contexto();
        [$alumno] = $this->dosAlumnos((int) $ctx->grupo_id);

        $this->marcarIndependiente($alumno, (int) $ctx->periodo_id);
        $unidad = $this->unidadPropia($ctx, $alumno);

        DB::table('unidades')->where('id', $unidad)->update(['deleted_at' => now()]);

        $fila = $this->porAlumno($this->planilla($ctx))[$alumno];

        $this->assertSame([], $fila['unidades']);
        $this->assertSame('vaciada', $fila['motivo'],
            'Un boletín vaciado se está contando como uno que nunca tuvo nada.');
    }

    /**
     * **Vaciada Y con la asignatura sin montar: el caso que fija el ORDEN.**
     *
     * Los dos motivos son ciertos a la vez y sólo puede viajar uno. `vaciada` es un
     * hecho sobre **este alumno** —alguien le retiró el boletín— y `asignatura_sin_montar`
     * uno sobre la asignatura. Con el orden al revés, a quien le vaciaron el boletín en
     * una asignatura que además está sin montar se le diría **«el docente no ha
     * entrado»**, que es lo contrario de lo que pasó: entró, le montó lo suyo y luego se
     * lo quitó.
     *
     * **Este test existe porque el otro no lo cazaba.** `test_motivo_vaciada` deja el
     * grupo con sus unidades, así que las dos ordenaciones dan `vaciada` y el rojo no se
     * ponía: la justificación del orden estaba escrita en el código y no la comprobaba
     * nadie. Comprobado en rojo invirtiendo las dos ramas.
     */
    public function test_vaciada_gana_a_asignatura_sin_montar(): void
    {
        $ctx = $this->contexto();
        [$alumno] = $this->dosAlumnos((int) $ctx->grupo_id);

        $this->marcarIndependiente($alumno, (int) $ctx->periodo_id);
        $unidad = $this->unidadPropia($ctx, $alumno);

        // Las dos cosas ciertas a la vez: lo suyo retirado y el curso sin montar.
        DB::table('unidades')->where('id', $unidad)->update(['deleted_at' => now()]);
        DB::table('unidades')
            ->where('asignatura_id', $ctx->asignatura_id)
            ->where('periodo_id', $ctx->periodo_id)
            ->whereNull('alumno_id')
            ->update(['deleted_at' => now()]);

        $fila = $this->porAlumno($this->planilla($ctx))[$alumno];

        $this->assertSame('vaciada', $fila['motivo'],
            'Con las dos condiciones ciertas gana la de la asignatura, y a este alumno le vaciaron '
            .'el boletín: la pantalla le diría «el docente no ha entrado» cuando entró y se lo quitó.');
    }

    /** Y si el grupo tampoco tiene nada, no es culpa de la marca. */
    public function test_motivo_asignatura_sin_montar(): void
    {
        $ctx = $this->contexto();
        [$alumno] = $this->dosAlumnos((int) $ctx->grupo_id);

        $this->marcarIndependiente($alumno, (int) $ctx->periodo_id);

        DB::table('unidades')
            ->where('asignatura_id', $ctx->asignatura_id)
            ->where('periodo_id', $ctx->periodo_id)
            ->whereNull('alumno_id')
            ->update(['deleted_at' => now()]);

        $fila = $this->porAlumno($this->planilla($ctx))[$alumno];

        $this->assertSame('asignatura_sin_montar', $fila['motivo']);
    }

    /** Y el vacío llega en **200 con motivo**, no en 400 diciendo «no hay». */
    public function test_un_vacio_no_es_un_error(): void
    {
        $ctx = $this->contexto();
        [$alumno] = $this->dosAlumnos((int) $ctx->grupo_id);

        $this->marcarIndependiente($alumno, (int) $ctx->periodo_id);

        $this->withToken($this->tokenDelPersonalDe((int) $ctx->year_id))
            ->putJson(self::RUTA, ['asignatura_id' => $ctx->asignatura_id])
            ->assertStatus(200);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Los porcentajes y la vista previa
    // ─────────────────────────────────────────────────────────────────────

    /**
     * **La suma se devuelve tal cual y NO se corrige**, aunque no dé 100.
     *
     * Regla 2 de `DefinitivasDeAsignatura` y [10 §9.3](../../docs/migracion/10-definitivas.md):
     * una estructura mal configurada da una definitiva rara, y **que se note es lo que la
     * delata**. Si el backend la cuadrara, la pantalla enseñaría 100 y el boletín saldría
     * con otra cosa.
     */
    public function test_el_porcentaje_no_se_corrige(): void
    {
        $ctx = $this->contexto();
        [$alumno] = $this->dosAlumnos((int) $ctx->grupo_id);

        $this->marcarIndependiente($alumno, (int) $ctx->periodo_id);
        $this->unidadPropia($ctx, $alumno, porcentaje: 40);

        $fila = $this->porAlumno($this->planilla($ctx))[$alumno];

        $this->assertSame(40.0, (float) $fila['porcentaje_unidades'],
            'La suma se está corrigiendo: un reparto mal configurado tiene que verse.');
    }

    /**
     * **`estructura_del_grupo` trae los periodos del año con su recuento**, y cuenta las
     * del **grupo** y no las de todo el mundo.
     *
     * Es lo que permite que el diálogo de copiar diga *«se van a copiar 4 unidades y 9
     * subunidades»* **antes** del clic, sin llamar a
     * `GET unidades/de-asignatura-periodo`, que **escribe**. Contar las unidades con
     * dueño aquí diría «se van a copiar 12» cuando se van a copiar 4.
     */
    public function test_la_vista_previa_cuenta_solo_lo_del_grupo(): void
    {
        $ctx = $this->contexto();
        [$alumno] = $this->dosAlumnos((int) $ctx->grupo_id);

        $antes = collect($this->planilla($ctx)['estructura_del_grupo'])
            ->firstWhere('periodo_id', (int) $ctx->periodo_id);

        $this->assertNotNull($antes, 'El periodo del token no salió en la vista previa.');
        $this->assertGreaterThan(0, $antes['unidades'], 'El contexto elegido no tiene unidades del grupo.');

        $this->marcarIndependiente($alumno, (int) $ctx->periodo_id);
        $this->unidadPropia($ctx, $alumno);

        $despues = collect($this->planilla($ctx)['estructura_del_grupo'])
            ->firstWhere('periodo_id', (int) $ctx->periodo_id);

        $this->assertSame($antes['unidades'], $despues['unidades'],
            'Una unidad con dueño entró en el recuento del grupo: el diálogo de copiar diría de más.');
        $this->assertSame($antes['subunidades'], $despues['subunidades']);
        $this->assertSame($antes['porcentaje_unidades'], $despues['porcentaje_unidades']);
    }

    // ─────────────────────────────────────────────────────────────────────
    // La puerta
    // ─────────────────────────────────────────────────────────────────────

    /** Un alumno no entra: la pantalla es del personal. */
    public function test_un_alumno_no_entra(): void
    {
        $ctx = $this->contexto();

        $r = $this->withToken($this->tokenDe($this->usuarioDeTipo('Alumno')->username))
            ->putJson(self::RUTA, ['asignatura_id' => $ctx->asignatura_id]);

        $this->assertNotEquals(200, $r->getStatusCode(), 'Un alumno abrió la planilla del boletín aparte.');
    }

    /**
     * **Un docente SÍ entra, y eso es deliberado.**
     *
     * Marcar un boletín lo decide el colegio —la decisión 5, más estrecha que lo de
     * hoy—, pero **montarle las unidades y ponerle las notas al que ya está marcado es
     * trabajo de aula**. Estrechar esta lectura le quitaría al docente algo que hoy
     * tiene por otras pantallas, que es el razonamiento con el que
     * `grupos/listado/{grupo_id}` se quedó en `auth.personal`.
     */
    public function test_un_docente_si_entra(): void
    {
        $ctx = $this->contexto();

        $this->withToken($this->tokenDe($this->usuarioDeTipo('Profesor')->username))
            ->putJson(self::RUTA, ['asignatura_id' => $ctx->asignatura_id])
            ->assertStatus(200);
    }

    /** Una asignatura de otro año no existe para esta pantalla. */
    public function test_una_asignatura_de_otro_anio_es_404(): void
    {
        $ctx = $this->contexto();

        $ajena = DB::selectOne(
            'SELECT a.id FROM asignaturas a
              INNER JOIN grupos g ON g.id = a.grupo_id AND g.deleted_at IS NULL AND g.year_id <> ?
             WHERE a.deleted_at IS NULL ORDER BY a.id LIMIT 1',
            [$ctx->year_id]
        );

        $this->assertNotNull($ajena, 'El seed necesita una asignatura de otro año.');

        $this->withToken($this->tokenDelPersonalDe((int) $ctx->year_id))
            ->putJson(self::RUTA, ['asignatura_id' => (int) $ajena->id])
            ->assertStatus(404);
    }

    /** Y el cuerpo sin `asignatura_id` es 422, no un 0 que se cuela como identificador. */
    public function test_sin_asignatura_id_es_422(): void
    {
        $ctx = $this->contexto();

        $this->withToken($this->tokenDelPersonalDe((int) $ctx->year_id))
            ->putJson(self::RUTA, [])
            ->assertStatus(422);
    }
}
