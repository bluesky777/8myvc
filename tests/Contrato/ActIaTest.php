<?php

namespace Tests\Contrato;

use Illuminate\Http\Client\Request as PeticionHttp;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Actividades nuevas — **la IA** (contrato §3.15), con el proxy doblado (`Http::fake`).
 *
 * - `act/ia/estado` existe para que un docente sepa si hay IA sin el permiso de la plantilla: un
 *   proxy caído o sin configurar es `{disponible: false}`, nunca un error.
 * - `ia/actividades/proponer|mejorar`: el contexto lo arma el backend con la actividad **del
 *   dueño** (403 si no lo es, y entonces al proxy no le llega nada); la respuesta del proxy pasa tal
 *   cual; 503 sin IA, 403/429 con la frase del proxy, 502 lo demás.
 * - **Nada se guarda**: el front añade lo que elija con `preguntas/lote`.
 */
class ActIaTest extends CasoDeActividades
{
    private function conProxy(): void
    {
        config(['services.ia.url' => 'https://proxy.test', 'services.ia.secreto' => 'secreto-de-prueba']);
    }

    private function propuesta(): array
    {
        return [
            'datos' => ['preguntas' => [[
                'tipo' => 'unica', 'enunciado' => '¿Cuánto es 3/4 + 1/4?', 'puntos' => 1, 'puntaje_parcial' => false,
                'opciones' => [['definicion' => '1', 'is_correct' => true, 'error_tipico' => null],
                    ['definicion' => '4/8', 'is_correct' => false, 'error_tipico' => 'Sumó numeradores y denominadores.']],
                'explicacion' => null, 'dificultad' => 'facil', 'escala_estilo' => null, 'texto_arriba' => null, 'texto_abajo' => null,
            ]]],
            'costo' => 0.01, 'gastado' => 0.02, 'tope' => 1.0, 'quedan' => ['actividades' => 9],
        ];
    }

    public function test_el_estado_lo_pide_un_docente(): void
    {
        $this->conProxy();
        Http::fake(['proxy.test/*' => Http::response(['disponible' => true, 'gastado' => 0.1, 'tope' => 1, 'quedan' => ['actividades' => 12]])]);

        $this->como('titular')->getJson('/api/act/ia/estado')->assertStatus(200)
            ->assertExactJson(['disponible' => true, 'gastado' => 0.1, 'tope' => 1, 'quedan' => ['actividades' => 12]]);
    }

    public function test_un_proxy_caido_o_sin_configurar_es_no_disponible(): void
    {
        config(['services.ia.url' => '', 'services.ia.secreto' => '']);
        $this->como('titular')->getJson('/api/act/ia/estado')->assertStatus(200)->assertExactJson(['disponible' => false]);

        $this->conProxy();
        Http::fake(['proxy.test/*' => Http::response('caído', 500)]);
        $this->como('titular')->getJson('/api/act/ia/estado')->assertStatus(200)->assertExactJson(['disponible' => false]);
    }

    public function test_proponer_devuelve_lo_del_proxy_y_no_guarda_nada(): void
    {
        $this->conProxy();
        Http::fake(['proxy.test/*' => Http::response($this->propuesta())]);

        $act = $this->crear($this->cuestionario(['titulo' => 'Fracciones']));
        $this->unica($act['id'], 0, ['enunciado' => 'Una que ya estaba']);
        $antes = [DB::table('ws_preguntas')->count(), DB::table('ws_opciones')->count()];

        $r = $this->como('titular')->postJson('/api/ia/actividades/proponer', ['actividad_id' => $act['id'], 'cantidad' => 3,
            'tema' => 'Suma de fracciones', 'tipos' => ['unica', 'multiple'], 'dificultad' => 'facil', 'indicaciones' => '',
            'conversacion' => array_fill(0, 25, ['rol' => 'usuario', 'texto' => 'otra'])]);

        $r->assertStatus(200)->assertJsonStructure(['datos' => ['preguntas'], 'costo', 'gastado', 'tope', 'quedan' => ['actividades']]);
        $this->assertSame('Sumó numeradores y denominadores.', $r->json('datos.preguntas.0.opciones.1.error_tipico'));
        $this->assertSame($antes, [DB::table('ws_preguntas')->count(), DB::table('ws_opciones')->count()], 'Proponer guardó algo.');

        Http::assertSent(function (PeticionHttp $p) {
            $c = $p->data();

            return $p->url() === 'https://proxy.test/actividades/proponer'
                && $p->hasHeader('Authorization', 'Bearer secreto-de-prueba')
                && $c['contexto']['modo'] === 'cuestionario'
                && $c['contexto']['existentes'] === ['Una que ya estaba']
                && $c['pedido']['tipos'] === ['unica', 'multiple']
                && count($c['conversacion']) === 20
                && (string) $c['usuario']['id'] === (string) $this->escena()->titular->id;
        });
    }

    public function test_mejorar_devuelve_la_pregunta_entera(): void
    {
        $this->conProxy();
        Http::fake(['proxy.test/*' => Http::response(['datos' => ['pregunta' => $this->propuesta()['datos']['preguntas'][0], 'comentario' => 'Más cercana.'],
            'costo' => 0.01, 'gastado' => 0.03, 'tope' => 1.0, 'quedan' => ['actividades' => 8]])]);

        $act = $this->crear($this->cuestionario());

        $this->como('titular')->postJson('/api/ia/actividades/mejorar', ['actividad_id' => $act['id'], 'atajo' => 'vida_real',
            'pregunta' => ['tipo' => 'unica', 'enunciado' => '3/4 + 1/4', 'opciones' => [['definicion' => '1', 'is_correct' => true]]]])
            ->assertStatus(200)->assertJsonPath('datos.comentario', 'Más cercana.')->assertJsonPath('datos.pregunta.tipo', 'unica');

        Http::assertSent(fn (PeticionHttp $p) => $p->url() === 'https://proxy.test/actividades/mejorar' && $p->data()['atajo'] === 'vida_real');
    }

    public function test_los_errores_del_proxy(): void
    {
        $act = $this->crear($this->cuestionario());
        $pedido = ['actividad_id' => $act['id'], 'tema' => 'x', 'tipos' => 'azar', 'dificultad' => 'media'];

        config(['services.ia.url' => '', 'services.ia.secreto' => '']);
        $this->como('titular')->postJson('/api/ia/actividades/proponer', $pedido)->assertStatus(503);

        $this->conProxy();
        $casos = [[429, 'Ya usaste tus propuestas de este mes.', 429], [403, 'Tu rol no tiene IA.', 403], [500, null, 502]];

        // Una secuencia y no un `fake` por caso: los `fake` se acumulan y gana el primero.
        $secuencia = Http::fakeSequence('proxy.test/*');

        foreach ($casos as [$delProxy, $frase]) {
            $secuencia->push($frase ? ['error' => $frase] : 'error', $delProxy);
        }

        foreach ($casos as [, $frase, $esperado]) {
            $r = $this->como('titular')->postJson('/api/ia/actividades/proponer', $pedido);
            $r->assertStatus($esperado);

            if ($frase) {
                $this->assertSame($frase, $r->json('message'), 'La frase del proxy no llegó tal cual.');
            }
        }
    }

    public function test_la_actividad_tiene_que_ser_suya(): void
    {
        $this->conProxy();
        Http::fake(['proxy.test/*' => Http::response($this->propuesta())]);

        $act = $this->crear($this->cuestionario());

        $this->como('ajeno')->postJson('/api/ia/actividades/proponer', ['actividad_id' => $act['id'], 'tema' => 'x'])->assertStatus(403);
        $this->como('ajeno')->postJson('/api/ia/actividades/mejorar', ['actividad_id' => $act['id'],
            'pregunta' => ['enunciado' => 'x']])->assertStatus(403);
        $this->como($this->alumno())->postJson('/api/ia/actividades/proponer', ['actividad_id' => $act['id'], 'tema' => 'x'])->assertStatus(403);

        Http::assertNothingSent();
    }

    public function test_pedidos_mal_formados_son_422_sin_llamar_al_proxy(): void
    {
        $this->conProxy();
        Http::fake(['proxy.test/*' => Http::response($this->propuesta())]);
        $act = $this->crear($this->cuestionario());

        $this->como('titular')->postJson('/api/ia/actividades/proponer', ['actividad_id' => $act['id'], 'tipos' => ['dibujo']])->assertStatus(422);
        $this->como('titular')->postJson('/api/ia/actividades/proponer', ['actividad_id' => $act['id'], 'dificultad' => 'imposible'])->assertStatus(422);
        $this->como('titular')->postJson('/api/ia/actividades/mejorar', ['actividad_id' => $act['id'], 'atajo' => 'magia',
            'pregunta' => ['enunciado' => 'x']])->assertStatus(422);
        $this->como('titular')->postJson('/api/ia/actividades/mejorar', ['actividad_id' => $act['id'], 'pregunta' => ['enunciado' => '  ']])
            ->assertStatus(422);

        Http::assertNothingSent();
    }
}
