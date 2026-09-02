<?php

namespace Tests\Contrato;

use App\Services\Nivelacion;
use Illuminate\Support\Facades\DB;

/**
 * `PUT editnota/alum-asignatura` — lo que lee el editor de la definitiva.
 *
 * Lo pidió el front el 2 sep 2026 y es la mitad que faltaba de A8: **`editor-nota`
 * no lee `notas/detailed`**, lee esta ruta. Sin el acta aquí, el docente nivelaba
 * la definitiva, recargaba, y veía la nota vieja **sin ninguna marca**: la
 * escritura ocurría y era invisible.
 *
 * Y esta ruta **no tenía instantánea de forma**, que es cómo las cinco columnas de
 * `notas` llegaron a viajar por aquí sin que nadie lo decidiera —un
 * `Nota::where(...)->first()` encadenado, el punto ciego que
 * `tools/filas-enteras-al-cliente.php` declara en su cabecera—. Ahora la tiene.
 */
class EditorDeNotaConElParTest extends CasoDeContrato
{
    /**
     * Una asignatura del año y periodo actuales con su profesor, su rejilla y un
     * alumno. Mismo criterio que `NotasTest::contexto()`: `Services\Login`
     * reescribe `users.periodo_id` en cada inicio de sesión, y `periodos.actual` es
     * el de su año mientras el del colegio lo dice `years.actual`.
     *
     * **El `ORDER BY` lleva el alumno, y ahí está la diferencia con `NotasTest`.**
     * Aquel ordena sólo por `a.id` y le basta: de las **333 filas que empatan** en el
     * primer `a.id` de esta base, las seis columnas que él selecciona
     * —grupo, profesor, usuario, username, periodo— tienen **un solo valor**. Esta
     * consulta es la suya **más `m.alumno_id`**, y esa columna tiene **37 valores
     * distintos** entre esas mismas 333 filas: sin ordenar por ella, cuál sale lo
     * decide el plan de MySQL y no el test.
     *
     * Costó un rojo que sólo aparecía en la suite entera y desaparecía al correr la
     * clase sola (2 sep 2026). No se veía como inestabilidad porque el síntoma era
     * un **tipo**: las notas se calculan, para unos alumnos la cuenta da un entero y
     * para otros un fraccionario, y la instantánea guarda el tipo — `float` contra
     * `int` en `nota_unidad`, `valor` y `valor_unidad`. Regenerar la instantánea
     * habría horneado el alumno de aquella corrida y el test habría seguido cayendo,
     * en las dos direcciones y sin patrón.
     *
     * La lección, que es la del repo con otra cara: **se heredó el `ORDER BY` de
     * `NotasTest` junto con una columna que ese `ORDER BY` no determina.** El
     * criterio era correcto allí y dejó de serlo aquí, y nada se puso rojo al
     * copiarlo.
     */
    private function contexto(): object
    {
        $fila = DB::selectOne('SELECT a.id AS asignatura_id, a.grupo_id, u.username, un.periodo_id,
                g.year_id, m.alumno_id
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
            ORDER BY a.id, m.alumno_id LIMIT 1');

        $this->assertNotNull($fila, 'El seed necesita una asignatura del año y periodo actuales con rejilla y alumnos.');

        return $fila;
    }

    /** @return array<string, mixed>|null El periodo actual dentro de la respuesta. */
    private function periodoActual(array $json, int $periodoId): ?array
    {
        foreach ($json as $periodo) {
            if ((int) ($periodo['id'] ?? 0) === $periodoId) {
                return $periodo;
            }
        }

        return null;
    }

    /**
     * **La forma de la respuesta, fijada por primera vez.**
     *
     * Sin esto, cualquier columna nueva de `notas` o de `notas_finales` sigue
     * apareciendo aquí sin que nadie se entere — que es exactamente lo que pasó
     * con las cinco de la nivelación.
     */
    public function test_la_forma_del_editor_de_nota(): void
    {
        $contexto = $this->contexto();
        $token = $this->tokenDe($contexto->username);

        $r = $this->withToken($token)->putJson('/api/editnota/alum-asignatura', [
            'alumno_id' => $contexto->alumno_id,
            'asignatura_id' => $contexto->asignatura_id,
        ]);

        $r->assertStatus(200);

        // `formaUnida()` y no `forma()`: aquélla mira `$valor[0]` y describe **la fila
        // que tocó**, no la columna. Aquí la lista son las unidades del alumno y las
        // notas se calculan, así que el tipo de `nota_unidad` sale `int` o `float`
        // según si la cuenta de la primera unidad da redonda — el mismo fallo que ya
        // documenta `CasoDeContrato::formaUnida()` para el acta de evaluación.
        //
        // Al unir, la instantánea dejó de mentir en cuatro sitios además del que se
        // buscaba: `created_at` estaba anotado `null` cuando también viene `string`, y
        // `updated_by` `int` cuando también viene `null`. **No cambió ni una clave**,
        // así que no puede esconder una columna filtrada — que es lo único que este
        // test existe para vigilar.
        $this->compararConInstantanea('editnota-alum-asignatura', $this->formaUnida($r->json()));

        $this->assertNotEmpty($r->json(), 'La respuesta salió vacía: la instantánea no comprobaría nada.');
    }

    /**
     * Nivelada la definitiva, el editor recibe el acta **con valores**.
     *
     * Es lo que pidió el front: sin estas cuatro claves, nivelar y recargar enseña
     * la nota vieja sin marca.
     */
    public function test_despues_de_nivelar_la_definitiva_el_editor_trae_el_acta(): void
    {
        $contexto = $this->contexto();
        $token = $this->tokenDe($contexto->username);

        // La definitiva de ese alumno en ese periodo, creada si no la hay: se pide
        // primero la pantalla, que es lo que la siembra.
        $this->withToken($token)->putJson('/api/editnota/alum-asignatura', [
            'alumno_id' => $contexto->alumno_id,
            'asignatura_id' => $contexto->asignatura_id,
        ])->assertStatus(200);

        $nf = DB::selectOne('SELECT id FROM notas_finales WHERE alumno_id = ? AND asignatura_id = ? AND periodo_id = ?',
            [$contexto->alumno_id, $contexto->asignatura_id, $contexto->periodo_id]);

        if ($nf === null) {
            $this->markTestSkipped('Ese alumno no tiene definitiva en el periodo actual.');
        }

        $this->conRegla((int) $contexto->year_id);
        DB::update('UPDATE notas_finales SET nota = 28, recuperada = 0, manual = 0 WHERE id = ?', [$nf->id]);

        $this->withToken($token)->putJson('/api/definitivas_periodos/nivelar', [
            'nf_id' => $nf->id,
            'nota_nivelacion' => 45,
            'observacion' => 'Sustentación de la asignatura',
        ])->assertStatus(200);

        $r = $this->withToken($token)->putJson('/api/editnota/alum-asignatura', [
            'alumno_id' => $contexto->alumno_id,
            'asignatura_id' => $contexto->asignatura_id,
        ]);

        $r->assertStatus(200);

        $periodo = $this->periodoActual($r->json(), (int) $contexto->periodo_id);

        $this->assertNotNull($periodo, 'El periodo actual no salió en la respuesta.');

        $this->assertEquals(35, $periodo['nota_asignatura'], 'La vigente es la que dejó la regla.');
        $this->assertEquals(28, $periodo['nota_original'],
            'Sin la original, el editor no puede pintar el par y el docente ve la nota vieja sin marca.');
        $this->assertEquals(45, $periodo['nota_nivelacion']);
        $this->assertNotNull($periodo['nivelada_at']);
        $this->assertNotNull($periodo['nivelada_por_username'],
            'El `LEFT JOIN` con `users` no trajo el nombre: el pie se queda sin «quién».');
        $this->assertSame('Sustentación de la asignatura', $periodo['nivelacion_obs']);
        $this->assertEquals(1, $periodo['recuperada']);
        $this->assertEquals(1, $periodo['manual']);
    }

    /**
     * Y las celdas de indicador traen las seis suyas, con `null` cuando no hay
     * nivelación — incluidas **todas** las que no la tienen, que es lo que se
     * rompería si el `select` explícito se hubiera dejado alguna columna fuera.
     */
    public function test_las_celdas_traen_las_seis_claves_de_la_nivelacion(): void
    {
        $contexto = $this->contexto();
        $token = $this->tokenDe($contexto->username);

        $r = $this->withToken($token)->putJson('/api/editnota/alum-asignatura', [
            'alumno_id' => $contexto->alumno_id,
            'asignatura_id' => $contexto->asignatura_id,
        ]);

        $r->assertStatus(200);

        $vistas = 0;

        foreach ($r->json() as $periodo) {
            foreach ($periodo['unidades'] ?? [] as $unidad) {
                foreach ($unidad['subunidades'] ?? [] as $subunidad) {
                    if (! isset($subunidad['nota'])) {
                        continue;
                    }

                    $vistas++;

                    foreach (['nota_original', 'nota_nivelacion', 'nivelada_at', 'nivelada_por',
                        'nivelada_por_username', 'nivelacion_obs'] as $clave) {
                        $this->assertArrayHasKey($clave, $subunidad['nota'],
                            "Falta `{$clave}` en la celda: el editor no puede pintar el par.");
                    }
                }
            }
        }

        $this->assertGreaterThan(0, $vistas, 'Ninguna celda con nota: este caso no mide nada.');
    }

    private function conRegla(int $yearId): void
    {
        DB::update('UPDATE years SET regla_nivelacion = ?, nota_minima_aceptada = ? WHERE id = ?',
            [Nivelacion::TOPADA, '35', $yearId]);

        Nivelacion::olvidar();
    }
}
