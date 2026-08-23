<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Las trece rutas del lote C, una a una: **cuál escribe en la rejilla sin
 * preguntar por el interruptor del periodo**. §92.
 *
 * La respuesta corta, y es la mitad del resultado del lote: **ninguna de las
 * trece**. Las seis que escriben preguntan, y se comprueba aquí que preguntan
 * **de verdad** —con el periodo cerrado, 400 y la fila intacta— y no sólo que la
 * llamada esté escrita en el código.
 *
 * | Ruta | Escribe | Pregunta |
 * |---|---|---|
 * | `DELETE definitivas_periodos/destroy/{id}` | sí | sí, por `notas_finales.periodo_id` |
 * | `PUT definitivas_periodos/toggle-manual` | sí | ídem |
 * | `PUT definitivas_periodos/toggle-recuperada` | sí | ídem |
 * | `PUT definitivas_periodos/eliminar-recuperada` | sí | sí, **por el año entero** |
 * | `DELETE notas/destroy/{id}` | sí | sí, por la subunidad → unidad |
 * | `POST subunidades` | sí | sí, por la unidad |
 * | `GET notas/show/{nota_id}` | no | — |
 * | `PUT nota_comportamiento/frases-check` | no | — |
 * | `PUT puestos/detailed-notas-year` | no | — |
 * | `PUT subunidades/eliminadas/{asignatura_id}` | no | — |
 * | `PUT nota_comportamiento/guardar-libro` | sí | **no** — §91 |
 * | `DELETE boletines2/destroy/{id}` | no toca la rejilla: borra un alumno | §89 |
 * | `DELETE boletines3/destroy/{id}` | ídem | §89 |
 *
 * Lo que sí salió al leerlas es **la otra mitad**: cuatro de las trece reciben un
 * identificador y contestan con datos de quien sea. El candado del periodo estaba
 * puesto; el de «de quién es esta fila», no.
 *
 * Ver docs/migracion/noche-2026-08-23/c.md §92.
 */
class RejillaLasQueFaltabanTest extends CasoDeContrato
{
    /**
     * Un profesor con **todo su año cerrado**, para que nada de lo que pase pueda
     * pasar por permiso.
     *
     * El token va primero: `Services\Login` reescribe `users.periodo_id` al entrar,
     * así que leer el periodo antes mide el año equivocado.
     */
    private function profesorConElAnioCerrado(): object
    {
        $prof = $this->usuarioDeTipo('Profesor');
        $token = $this->tokenDe($prof->username);

        $suyo = DB::selectOne('SELECT p.id, p.numero, p.year_id FROM periodos p
            INNER JOIN users u ON u.periodo_id = p.id WHERE u.id = ?', [$prof->id]);

        $this->assertNotNull($suyo, 'El profesor del seed se quedó sin periodo al entrar.');

        DB::table('periodos')->where('year_id', $suyo->year_id)
            ->update(['profes_pueden_editar_notas' => 0, 'profes_pueden_nivelar' => 0]);

        return (object) ['token' => $token, 'periodo' => $suyo];
    }

    /** El mismo, pero sin tocar los interruptores: para lo que no mide el candado. */
    private function profesorConElAnioAbierto(): object
    {
        $prof = $this->usuarioDeTipo('Profesor');
        $token = $this->tokenDe($prof->username);

        $suyo = DB::selectOne('SELECT p.id, p.numero, p.year_id FROM periodos p
            INNER JOIN users u ON u.periodo_id = p.id WHERE u.id = ?', [$prof->id]);

        $this->assertNotNull($suyo, 'El profesor del seed se quedó sin periodo al entrar.');

        return (object) ['token' => $token, 'periodo' => $suyo];
    }

    /** Una definitiva del periodo del profesor, que es el que está cerrado. */
    private function definitivaDelPeriodo(int $periodoId): ?object
    {
        return DB::selectOne('SELECT id, nota, manual, recuperada FROM notas_finales
            WHERE periodo_id = ? ORDER BY id LIMIT 1', [$periodoId]);
    }

    /**
     * **Las tres de `notas_finales` que se piden por `nf_id`.**
     *
     * Las tres derivan el periodo de la fila —`PeriodoDeLaFila::deNotaFinal`—, que
     * es lo que arregló la §27: antes se miraba el periodo que nombrara el cuerpo y
     * la escritura iba a otro sitio. Por eso el cuerpo nombra **el periodo abierto**
     * que no existe y aun así no pasa: no hay ninguno abierto, pero si el candado
     * mirara `num_periodo` con un año entero cerrado el resultado sería el mismo, y
     * lo que distingue las dos cosas ya lo mide `PeriodoDeLaFilaTest`. Aquí lo que
     * se comprueba es que **estas tres, que nadie había golpeado, contestan lo que
     * dicen contestar**.
     *
     * @return array<string, array{string, string, array<string, mixed>}>
     */
    public static function lasTresDeUnaDefinitiva(): array
    {
        return [
            'destroy' => ['delete', '/api/definitivas_periodos/destroy/{id}', []],
            'toggle-manual' => ['put', '/api/definitivas_periodos/toggle-manual', ['manual' => 1]],
            'toggle-recuperada' => ['put', '/api/definitivas_periodos/toggle-recuperada', ['recuperada' => 1]],
        ];
    }

    #[DataProvider('lasTresDeUnaDefinitiva')]
    public function test_con_el_periodo_cerrado_la_definitiva_no_se_toca(string $verbo, string $url, array $cuerpo): void
    {
        $e = $this->profesorConElAnioCerrado();
        $nf = $this->definitivaDelPeriodo((int) $e->periodo->id);

        if ($nf === null) {
            $this->markTestSkipped('El seed no tiene definitivas en el periodo del profesor.');
        }

        $url = str_replace('{id}', (string) $nf->id, $url);
        $cuerpo['nf_id'] = $nf->id;

        $this->withToken($e->token)->{$verbo.'Json'}($url, $cuerpo)->assertStatus(400);

        $ahora = DB::selectOne('SELECT id, nota, manual, recuperada FROM notas_finales WHERE id = ?', [$nf->id]);

        $this->assertNotNull($ahora, 'La definitiva del periodo cerrado se borró igual.');
        $this->assertEquals([$nf->nota, $nf->manual, $nf->recuperada],
            [$ahora->nota, $ahora->manual, $ahora->recuperada],
            'La definitiva del periodo cerrado cambió pese al 400.');
    }

    /**
     * **`eliminar-recuperada` se cierra por el AÑO, no por un periodo**, y no es un
     * descuido: `recuperacion_final` guarda alumno, asignatura, `year` y nota — **no
     * tiene `periodo_id`**, así que no hay fila de la que derivar uno.
     *
     * Joseth decidió el 21 ago 2026, entre cuatro formas medidas, **exigir que estén
     * abiertos todos los periodos del año**, porque lo que se toca es del año
     * entero. La otra cara quedó dicha al elegir: con el periodo 1 cerrado y el 4
     * abierto, la recuperación final no se puede tocar.
     *
     * Este caso fija justo esa otra cara — un solo periodo cerrado basta — porque es
     * la mitad que nadie golpea y la que sorprendería a un colegio.
     */
    public function test_un_solo_periodo_cerrado_basta_para_no_borrar_una_recuperacion(): void
    {
        $prof = $this->usuarioDeTipo('Profesor');
        $token = $this->tokenDe($prof->username);

        $suyo = DB::selectOne('SELECT p.id, p.year_id FROM periodos p
            INNER JOIN users u ON u.periodo_id = p.id WHERE u.id = ?', [$prof->id]);

        // El año entero ABIERTO menos uno: es lo que separa «por el año» de «por el
        // periodo de la fila». Con todo cerrado los dos criterios darían igual.
        DB::table('periodos')->where('year_id', $suyo->year_id)
            ->update(['profes_pueden_editar_notas' => 1, 'profes_pueden_nivelar' => 1]);

        $otro = DB::selectOne('SELECT id FROM periodos WHERE year_id = ? AND id <> ? AND deleted_at IS NULL
            ORDER BY numero LIMIT 1', [$suyo->year_id, $suyo->id]);

        if ($otro === null) {
            $this->markTestSkipped('El año del profesor tiene un solo periodo: no se puede medir esto.');
        }

        DB::table('periodos')->where('id', $otro->id)->update(['profes_pueden_nivelar' => 0]);

        $alumno = DB::selectOne('SELECT alumno_id, asignatura_id FROM notas_finales ORDER BY id LIMIT 1');

        $rfId = DB::table('recuperacion_final')->insertGetId([
            'alumno_id' => $alumno->alumno_id,
            'asignatura_id' => $alumno->asignatura_id,
            'year' => date('Y'),
            'nota' => 60,
        ]);

        $this->withToken($token)->putJson('/api/definitivas_periodos/eliminar-recuperada', ['rf_id' => $rfId])
            ->assertStatus(400);

        $this->assertSame(1, DB::table('recuperacion_final')->where('id', $rfId)->count(),
            'La recuperación se borró con un periodo del año cerrado: cambió el criterio que eligió Joseth el 21 ago 2026.');
    }

    /**
     * **`notas/destroy` con el periodo cerrado no borra la nota.**
     *
     * La nota no lleva periodo: cuelga de la subunidad y ésa de la unidad, que sí.
     * Es la otra de las dos que la §27.1 daba por difíciles, y su gemela
     * `notas/update` ya la fija `PeriodoDeLaFilaTest`; ésta no la había golpeado
     * nadie, y es la destructiva de las dos.
     */
    public function test_con_el_periodo_cerrado_la_nota_no_se_borra(): void
    {
        $e = $this->profesorConElAnioCerrado();

        $nota = DB::selectOne('SELECT n.id FROM notas n
            INNER JOIN subunidades s ON s.id = n.subunidad_id AND s.deleted_at IS NULL
            INNER JOIN unidades u ON u.id = s.unidad_id AND u.deleted_at IS NULL
            WHERE u.periodo_id = ? AND n.deleted_at IS NULL ORDER BY n.id LIMIT 1',
            [$e->periodo->id]);

        if ($nota === null) {
            $this->markTestSkipped('El seed no tiene notas en el periodo del profesor.');
        }

        $this->withToken($e->token)->deleteJson('/api/notas/destroy/'.$nota->id)->assertStatus(400);

        $this->assertSame(1, DB::table('notas')->where('id', $nota->id)->count(),
            'La nota del periodo cerrado se borró: y es un DELETE físico, no la papelera.');
    }

    /**
     * **`POST subunidades` con el periodo cerrado no crea la subunidad.**
     *
     * La subunidad todavía no existe cuando se comprueba, así que el periodo sale de
     * la unidad de la que va a colgar. Es el caso al revés del de
     * `PeriodoDeLaFilaTest`, que golpea `subunidades/update` sobre una que ya
     * existe: crear y editar salen del mismo sitio pero por caminos distintos.
     */
    public function test_con_el_periodo_cerrado_no_se_crea_una_subunidad(): void
    {
        $e = $this->profesorConElAnioCerrado();

        $unidad = DB::selectOne('SELECT id FROM unidades WHERE periodo_id = ? AND deleted_at IS NULL
            ORDER BY id LIMIT 1', [$e->periodo->id]);

        if ($unidad === null) {
            $this->markTestSkipped('El seed no tiene unidades en el periodo del profesor.');
        }

        $antes = DB::table('subunidades')->where('unidad_id', $unidad->id)->count();

        $this->withToken($e->token)->postJson('/api/subunidades', [
            'unidad_id' => $unidad->id,
            'definicion' => 'creada con el periodo cerrado',
            'porcentaje' => 10,
        ])->assertStatus(400);

        $this->assertSame($antes, DB::table('subunidades')->where('unidad_id', $unidad->id)->count(),
            'Se creó una subunidad en un periodo cerrado: entra en la rejilla con su porcentaje.');
    }

    // ------------------------------------------------------------------
    // La otra mitad: las cuatro que no escriben, y a quién le contestan.
    //
    // Ninguna se cierra, y no por falta de criterio sino porque **el criterio
    // ya está decidido y es que no**: «hoy un profesor alcanza todo lo que
    // alcanza un administrativo, y es lo que Joseth dejó fuera a propósito»
    // (08-revision-idor.md, «Lo que queda para el refactor de permisos»). Estas
    // cuatro llevan `auth.personal`, así que no alcanzan ni a un alumno ni a un
    // acudiente: lo que miden es personal contra personal, que es exactamente
    // el punto aplazado. Se fija lo que contestan hoy.
    // ------------------------------------------------------------------

    /**
     * **`GET notas/show/{nota_id}` devuelve cualquier nota por su id**, sin mirar de
     * quién es ni de qué asignatura.
     *
     * Sus dos hermanas del mismo controlador —`notas/update/{id}` y
     * `notas/destroy/{id}`— piden el candado del periodo, que es una pregunta
     * distinta: *cuándo* se puede escribir, no *de quién* es la fila. Ninguna de las
     * tres mira lo segundo, así que aquí tampoco hay criterio que copiar.
     */
    public function test_notas_show_devuelve_la_nota_de_cualquiera(): void
    {
        $prof = $this->usuarioDeTipo('Profesor');
        $token = $this->tokenDe($prof->username);

        $profesorId = DB::selectOne('SELECT id FROM profesores WHERE user_id = ? AND deleted_at IS NULL',
            [$prof->id])->id;

        // Una nota de una asignatura que NO es de este profesor.
        $nota = DB::selectOne('SELECT n.id, n.alumno_id FROM notas n
            INNER JOIN subunidades s ON s.id = n.subunidad_id AND s.deleted_at IS NULL
            INNER JOIN unidades u ON u.id = s.unidad_id AND u.deleted_at IS NULL
            INNER JOIN asignaturas a ON a.id = u.asignatura_id AND a.deleted_at IS NULL
            WHERE n.deleted_at IS NULL AND a.profesor_id <> ? ORDER BY n.id LIMIT 1', [$profesorId]);

        if ($nota === null) {
            $this->markTestSkipped('El seed no tiene notas de asignaturas de otro profesor.');
        }

        $r = $this->withToken($token)->getJson('/api/notas/show/'.$nota->id);

        $r->assertStatus(200);

        $this->assertSame((int) $nota->alumno_id, (int) $r->json('alumno_id'),
            'Si esto deja de contestar, alguien decidió el punto 1 del 08: anótese la decisión.');

        $this->compararConInstantanea('notas-show', $this->forma($r->json()));
    }

    /**
     * Y **con una nota que está en la papelera contesta `200` con el cuerpo vacío**,
     * no 404.
     *
     * `Nota::find()` respeta el borrado lógico y devuelve `null`, que Laravel
     * serializa como cuerpo vacío. Se fija sin juzgarlo: cambiarlo a 404 es correcto
     * por el [criterio de códigos](../../CLAUDE.md) pero es un cambio de contrato de
     * una ruta que un cliente lee, y hoy ningún cliente pide una nota borrada.
     */
    public function test_notas_show_de_una_nota_borrada_contesta_200_vacio(): void
    {
        $token = $this->tokenDe($this->usuarioDeTipo('Profesor')->username);

        $nota = DB::selectOne('SELECT id FROM notas WHERE deleted_at IS NULL ORDER BY id LIMIT 1');
        DB::table('notas')->where('id', $nota->id)->update(['deleted_at' => now()]);

        $r = $this->withToken($token)->getJson('/api/notas/show/'.$nota->id);

        $r->assertStatus(200);
        $this->assertSame('', $r->getContent(),
            'Dejó de contestar vacío: si ahora es 404 está mejor, pero es un cambio de contrato.');
    }

    /**
     * **`PUT puestos/detailed-notas-year` contesta con el año entero de cualquier
     * grupo**: nombres, apellidos, definitivas por asignatura y promedio.
     *
     * `grupo_id` viaja en el cuerpo y no lo mira nadie —sale así en
     * `identificadores-del-cuerpo.py`, con el asterisco puesto—. Es la misma
     * respuesta que su hermana `detailed-notas-periodo/{grupo_id}`, que lo lleva en
     * la URL y tampoco lo comprueba: la forma de la §13.2 —el guard que no
     * reconoce el nombre— aquí es que **no hay guard en ninguna de las dos**.
     */
    public function test_puestos_del_year_contesta_de_cualquier_grupo(): void
    {
        $grupo = $this->grupoConAlumnos();
        $token = $this->tokenDe($this->usuarioDeTipo('Profesor')->username);

        $r = $this->withToken($token)->putJson('/api/puestos/detailed-notas-year', [
            'grupo_id' => $grupo->id,
        ]);

        $r->assertStatus(200);

        $this->assertNotEmpty($r->json('alumnos'),
            'Si deja de contestar alumnos, alguien puso un guard: anótese la decisión.');

        $this->compararConInstantanea('puestos-detailed-notas-year', $this->formaUnida($r->json()));
    }

    /**
     * Las dos que sólo devuelven catálogo del colegio, fijadas por su forma.
     *
     * `subunidades/eliminadas/{asignatura_id}` da la papelera de una asignatura y
     * `nota_comportamiento/frases-check` busca en las frases del observador. Ninguna
     * lleva datos de una persona dentro, que es el criterio con el que el
     * [08](../../docs/migracion/08-revision-idor.md) separa una fuga de un catálogo.
     */
    public function test_las_dos_lecturas_de_catalogo_contestan_su_forma(): void
    {
        $e = $this->profesorConElAnioAbierto();
        $token = $e->token;

        // Una subunidad en la papelera montada aquí, **y del periodo del profesor**:
        // la consulta filtra por `u.periodo_id = $user->periodo_id`, así que con una
        // de otro periodo la respuesta sale `[]` — y un snapshot de una lista vacía
        // no fija ninguna forma: pasaría igual el día que la consulta dejara de
        // traer columnas. Es el verde hueco de siempre, y aquí salió al mirar el
        // .json generado, no leyendo el test. La transacción lo deshace.
        $sub = DB::selectOne('SELECT s.id, u.asignatura_id FROM subunidades s
            INNER JOIN unidades u ON u.id = s.unidad_id AND u.deleted_at IS NULL
            WHERE s.deleted_at IS NULL AND u.periodo_id = ? ORDER BY s.id LIMIT 1',
            [$e->periodo->id]);

        $this->assertNotNull($sub, 'El seed necesita una subunidad en el periodo del profesor.');

        DB::table('subunidades')->where('id', $sub->id)->update(['deleted_at' => now()]);

        $r = $this->withToken($token)->putJson('/api/subunidades/eliminadas/'.$sub->asignatura_id);
        $r->assertStatus(200);
        $this->assertSame(['subunidades'], array_keys($r->json()));
        $this->assertNotEmpty($r->json('subunidades'),
            'La papelera salió vacía: el snapshot de abajo no fijaría ninguna forma.');

        $this->olvidarControladores();

        $f = $this->withToken($token)->putJson('/api/nota_comportamiento/frases-check', ['texto' => 'a']);
        $f->assertStatus(200);
        $this->assertSame(['frases'], array_keys($f->json()));

        $this->compararConInstantanea('lecturas-de-catalogo-del-lote-c', [
            'subunidades/eliminadas' => $this->formaUnida($r->json()),
            'nota_comportamiento/frases-check' => $this->formaUnida($f->json()),
        ]);
    }
}
