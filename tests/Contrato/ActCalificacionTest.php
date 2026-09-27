<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * Actividades nuevas — **cómo se califica un cuestionario** (contrato §2.4 y §2.8, §3.13).
 *
 * - Única: todo o nada. Múltiple: el conjunto exacto; con `puntaje_parcial`, suma por cada correcta
 *   marcada y resta por cada incorrecta, sin bajar de 0.
 * - `nota = round(puntaje × nota_maxima / puntaje_max)`, mitad hacia arriba.
 * - Con varios intentos cuenta el mejor.
 * - La nota y las correctas se enseñan según `mostrar_correctas`: `al_enviar`, las dos; `nunca`, la
 *   nota sí y las correctas no; `al_cerrar`, nada hasta que cierra.
 * - El error típico de un distractor sólo sale junto a la opción que el alumno marcó y falló, y
 *   sólo cuando ya se ven las correctas.
 *
 * El seed califica sobre 50 con mínima 30.
 */
class ActCalificacionTest extends CasoDeActividades
{
    /** Múltiple con opciones A, B (correctas) y C. */
    private function multiple(int $actId, bool $parcial): array
    {
        return $this->pregunta($actId, ['tipo' => 'multiple', 'enunciado' => $parcial ? 'Parcial' : 'Todo o nada', 'puntos' => 2,
            'puntaje_parcial' => $parcial, 'opciones' => [
                ['definicion' => 'A', 'is_correct' => true], ['definicion' => 'B', 'is_correct' => true], ['definicion' => 'C']]]);
    }

    private function hoja(int $actId, object $alumno): object
    {
        return DB::table('ws_actividades_resueltas')->where('actividad_id', $actId)->where('user_id', $alumno->user_id)
            ->where('terminado', 1)->orderByDesc('id')->first();
    }

    public function test_unica_multiple_y_puntaje_parcial(): void
    {
        $act = $this->crear($this->cuestionario());
        $id = $act['id'];
        $q1 = $this->unica($id, 0);
        $q2 = $this->multiple($id, false);
        $q3 = $this->multiple($id, true);
        $this->publicar($id);

        // [q1, q2, q3] → puntaje esperado (sobre 5) y nota sobre 50.
        $casos = [
            [[0], [0, 1], [0, 1, 2], 4.0, 40],   // q3: 2 bien, 1 mal → 2 − 1
            [[1], [0], [0], 1.0, 10],            // q2 incompleta = 0; q3 una de dos = 1
            [[0], [0, 1, 2], [0, 2], 1.0, 10],   // q2 con una de más = 0; q3 1 bien 1 mal = 0
            [[], [], [2], 0.0, 0],               // q3 sólo la mala: no baja de 0
        ];

        foreach ($casos as $i => [$r1, $r2, $r3, $puntaje, $nota]) {
            $r = $this->enviar($id, $this->alumno($i), [$this->marcar($q1, ...$r1), $this->marcar($q2, ...$r2), $this->marcar($q3, ...$r3)]);
            $r->assertStatus(200);

            $this->assertEquals($puntaje, $r->json('puntaje'), "Caso {$i}: puntaje.");
            $this->assertEquals(5, $r->json('puntaje_max'));
            $this->assertSame($nota, $r->json('nota'), "Caso {$i}: nota.");

            $hoja = $this->hoja($id, $this->alumno($i));
            $this->assertEquals($puntaje, (float) $hoja->puntaje);
            $this->assertSame($nota, (int) $hoja->nota_calculada);
        }

        // Los resultados del docente: aciertos por pregunta y quién aprueba (mínima 30 de 50).
        $res = $this->como('titular')->getJson("/api/act/{$id}/resultados")->assertStatus(200);
        $this->assertSame([2, 1, 0], array_column($res->json('preguntas'), 'aciertos'), 'El parcial suma puntos pero no cuenta como acierto.');
        $this->assertEquals(['promedio' => 15, 'aprobaron' => 1, 'reprobaron' => 3], $res->json('notas'));
    }

    public function test_la_nota_redondea_la_mitad_hacia_arriba(): void
    {
        $act = $this->crear($this->cuestionario());
        $preguntas = [$this->unica($act['id'], 0), $this->unica($act['id'], 0), $this->unica($act['id'], 0), $this->unica($act['id'], 0)];
        $this->publicar($act['id']);

        // 1 de 4 sobre 50 = 12,5 → 13.
        $r = $this->enviar($act['id'], $this->alumno(), [$this->marcar($preguntas[0], 0), $this->marcar($preguntas[1], 1)]);

        $r->assertStatus(200)->assertJsonPath('nota', 13);
    }

    public function test_con_varios_intentos_cuenta_el_mejor(): void
    {
        $act = $this->crear($this->cuestionario(['oportunidades' => 3]));
        $q1 = $this->unica($act['id'], 0);
        $q2 = $this->unica($act['id'], 0);
        $this->publicar($act['id']);
        $id = $act['id'];
        $yo = $this->alumno();

        $intentos = [[[0], [1], 25, 2], [[0], [0], 50, 1], [[1], [1], 0, 0]];

        foreach ($intentos as [$r1, $r2, $nota, $quedan]) {
            $this->enviar($id, $yo, [$this->marcar($q1, ...$r1), $this->marcar($q2, ...$r2)])->assertStatus(200)
                ->assertJsonPath('nota', $nota)->assertJsonPath('quedan_intentos', $quedan);
        }

        $this->enviar($id, $yo, [$this->marcar($q1, 0)])->assertStatus(409);

        $mis = $this->como($yo)->getJson("/api/act/{$id}/mis-respuestas")->assertStatus(200);
        $this->assertSame(50, $mis->json('nota'), 'Cuenta el mejor intento, no el último.');
        $this->assertSame(3, $mis->json('intentos'));

        $fila = collect($this->como($yo)->getJson('/api/act/bandeja?vista=responder')->json())->firstWhere('id', $id);
        $this->assertSame(50, $fila['mi_nota']);
        $this->assertSame('enviada', $fila['mi_estado']);
    }

    public function test_al_cerrar_esconde_nota_y_correctas_hasta_que_cierra(): void
    {
        $act = $this->crear($this->cuestionario(['mostrar_correctas' => 'al_cerrar']));
        $q = $this->unica($act['id'], 0, ['explicacion' => 'Por la definición.']);
        $this->publicar($act['id']);
        $yo = $this->alumno();

        $r = $this->enviar($act['id'], $yo, [$this->marcar($q, 0)])->assertStatus(200);
        $this->assertNull($r->json('nota'));
        $this->assertNull($r->json('puntaje'));

        $mis = $this->como($yo)->getJson("/api/act/{$act['id']}/mis-respuestas")->assertStatus(200);
        $this->assertNull($mis->json('nota'));
        $this->assertNull($mis->json('respuestas.0.acerto'));
        $this->assertArrayNotHasKey('is_correct', $mis->json('respuestas.0.pregunta.opciones.0'));
        $this->assertArrayNotHasKey('explicacion', $mis->json('respuestas.0.pregunta'));
        $this->assertNull(collect($this->como($yo)->getJson('/api/act/bandeja?vista=responder')->json())->firstWhere('id', $act['id'])['mi_nota']);

        $this->como('titular')->postJson("/api/act/{$act['id']}/cerrar")->assertStatus(200);

        $mis = $this->como($yo)->getJson("/api/act/{$act['id']}/mis-respuestas")->assertStatus(200);
        $this->assertSame(50, $mis->json('nota'));
        $this->assertTrue($mis->json('respuestas.0.acerto'));
        $this->assertTrue($mis->json('respuestas.0.pregunta.opciones.0.is_correct'));
        $this->assertSame('Por la definición.', $mis->json('respuestas.0.pregunta.explicacion'));
    }

    public function test_nunca_ensena_la_nota_pero_no_las_correctas(): void
    {
        $act = $this->crear($this->cuestionario(['mostrar_correctas' => 'nunca']));
        $q = $this->unica($act['id'], 0);
        $this->publicar($act['id']);

        $this->enviar($act['id'], $this->alumno(), [$this->marcar($q, 1)])->assertStatus(200)->assertJsonPath('nota', 0);

        $this->como('titular')->postJson("/api/act/{$act['id']}/cerrar")->assertStatus(200);

        $mis = $this->como($this->alumno())->getJson("/api/act/{$act['id']}/mis-respuestas")->assertStatus(200);
        $this->assertSame(0, $mis->json('nota'));
        $this->assertNull($mis->json('respuestas.0.acerto'));
        $this->assertArrayNotHasKey('is_correct', $mis->json('respuestas.0.pregunta.opciones.0'));
    }

    /** El error típico: sólo el de la opción que marcó y falló, y sólo cuando ya se ven las correctas. */
    public function test_el_error_tipico_solo_en_la_opcion_mal_marcada(): void
    {
        $opciones = [
            ['definicion' => '4', 'is_correct' => true],
            ['definicion' => '22', 'error_tipico' => 'Pegó las cifras.'],
            ['definicion' => '0', 'error_tipico' => 'Restó en vez de sumar.'],
        ];

        $ya = $this->crear($this->cuestionario(['mostrar_correctas' => 'al_enviar']));
        $q = $this->pregunta($ya['id'], ['tipo' => 'unica', 'enunciado' => '2 + 2', 'opciones' => $opciones]);
        $this->publicar($ya['id']);

        $this->enviar($ya['id'], $this->alumno(0), [$this->marcar($q, 1)])->assertStatus(200);
        $this->enviar($ya['id'], $this->alumno(1), [$this->marcar($q, 0)])->assertStatus(200);

        $fallo = $this->como($this->alumno(0))->getJson("/api/act/{$ya['id']}/mis-respuestas")->json('respuestas.0.pregunta.opciones');
        $this->assertSame([null, 'Pegó las cifras.', null], array_column($fallo, 'error_tipico'));

        $acierto = $this->como($this->alumno(1))->getJson("/api/act/{$ya['id']}/mis-respuestas")->json('respuestas.0.pregunta.opciones');
        $this->assertSame([null, null, null], array_column($acierto, 'error_tipico'), 'A quien acertó no se le enseñan los errores de los demás.');

        // Con las correctas todavía ocultas, ningún error típico.
        $luego = $this->crear($this->cuestionario(['mostrar_correctas' => 'al_cerrar', 'titulo' => 'Luego']));
        $q2 = $this->pregunta($luego['id'], ['tipo' => 'unica', 'enunciado' => '2 + 2', 'opciones' => $opciones]);
        $this->publicar($luego['id']);
        $this->enviar($luego['id'], $this->alumno(0), [$this->marcar($q2, 1)])->assertStatus(200);

        $mis = $this->como($this->alumno(0))->getJson("/api/act/{$luego['id']}/mis-respuestas");
        $this->assertStringNotContainsString('Pegó las cifras', $mis->getContent());
    }

    /** `corta` califica si tiene respuestas aceptadas, comparando sin tildes, mayúsculas ni espacios de más. */
    public function test_la_corta_compara_normalizada(): void
    {
        $act = $this->crear($this->cuestionario());
        $q = $this->pregunta($act['id'], ['tipo' => 'corta', 'enunciado' => 'Capital de Colombia', 'opciones' => [
            ['definicion' => 'Bogotá', 'is_correct' => true]]]);
        $this->publicar($act['id']);

        $this->enviar($act['id'], $this->alumno(0), [['pregunta_id' => $q['id'], 'texto' => '  BOGOTA  ']])->assertJsonPath('nota', 50);
        $this->enviar($act['id'], $this->alumno(1), [['pregunta_id' => $q['id'], 'texto' => 'Medellín']])->assertJsonPath('nota', 0);
    }
}
