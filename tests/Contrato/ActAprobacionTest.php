<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * Actividades nuevas — **la aprobación** (contrato §2.3 y §3.6).
 *
 * La regla, literal: una encuesta de alguien que no es directivo queda `por_aprobar` si va a
 * acudientes de un grupo que no es de su titularía, o a alumnos de más de 3 grupos. Quien tiene
 * uno de los roles directivos no la necesita nunca. Aprobar y rechazar es de directivos, nunca la
 * propia, y el rechazo lleva motivo y la devuelve a borrador.
 *
 * El titular de la escena es titular de su grupo; el «ajeno» da clase en ese grupo y no es titular
 * de ninguno del año (ver `CasoDeActividades::escena`).
 */
class ActAprobacionTest extends CasoDeActividades
{
    public function test_el_titular_a_su_grupo_no_necesita_aprobacion(): void
    {
        foreach (['alumnos', 'acudientes', 'ambos'] as $responden) {
            $act = $this->crear($this->encuesta(['responden' => $responden, 'titulo' => $responden]));
            $this->unica($act['id'], null);

            $r = $this->publicar($act['id']);
            $this->assertSame('abierta', $r['estado'], "A su propio grupo ({$responden}) quedó {$r['estado']}.");
            $this->assertFalse($r['requiere_aprobacion']);
        }
    }

    public function test_a_acudientes_fuera_de_su_titularia_queda_por_aprobar(): void
    {
        $act = $this->crear($this->encuesta(['responden' => 'acudientes']), 'ajeno');
        $this->unica($act['id'], null, [], 'ajeno');

        // El conteo lo avisa antes de publicar, con la misma regla.
        $conteo = $this->como('ajeno')->postJson('/api/act/conteo', ['modo' => 'encuesta', 'alcance' => 'grupo',
            'responden' => 'acudientes', 'destinatarios' => [['grupo_id' => $this->escena()->grupo_id]]])->json();
        $this->assertTrue($conteo['requiere_aprobacion']);

        $r = $this->publicar($act['id'], [], 'ajeno');

        $this->assertSame('por_aprobar', $r['estado']);
        $this->assertTrue($r['requiere_aprobacion']);
        $this->assertStringContainsString('no es tu titularía', (string) $r['motivo_aprobacion']);
        $this->assertSame(1, (int) $this->fila($act['id'])->requiere_aprobacion);

        // Mientras tanto no le llega a nadie.
        $this->assertNotContains($act['id'], array_column($this->como('acudiente')->getJson('/api/act/bandeja?vista=responder')->json(), 'id'));
        $this->como('acudiente')->getJson("/api/act/{$act['id']}/responder?alumno_id={$this->escena()->acudiente->alumno_id}")->assertStatus(409);

        // Y a los alumnos de ese mismo grupo no hace falta: la regla de alumnos es la de los 3 grupos.
        $alumnos = $this->crear($this->encuesta(['responden' => 'alumnos', 'titulo' => 'A alumnos']), 'ajeno');
        $this->unica($alumnos['id'], null, [], 'ajeno');
        $this->assertSame('abierta', $this->publicar($alumnos['id'], [], 'ajeno')['estado']);
    }

    public function test_a_alumnos_de_mas_de_tres_grupos_queda_por_aprobar(): void
    {
        $e = $this->escena();
        $relleno = $this->gruposDeRelleno(3);

        $tres = $this->crear($this->encuesta(['alcance' => 'grupos', 'grupo_id' => null,
            'destinatarios' => array_map(fn ($g) => ['grupo_id' => $g], [$e->grupo_id, $relleno[0], $relleno[1]])]));
        $this->unica($tres['id'], null);
        $this->assertSame('abierta', $this->publicar($tres['id'])['estado'], 'Tres grupos no necesitan aprobación.');

        $cuatro = $this->crear($this->encuesta(['alcance' => 'grupos', 'grupo_id' => null, 'titulo' => 'Cuatro',
            'destinatarios' => array_map(fn ($g) => ['grupo_id' => $g], [$e->grupo_id, ...$relleno])]));
        $this->unica($cuatro['id'], null);
        $r = $this->publicar($cuatro['id']);

        $this->assertSame('por_aprobar', $r['estado']);
        $this->assertStringContainsString('4 grupos', (string) $r['motivo_aprobacion']);
    }

    /** Tareas y cuestionarios nunca pasan por aprobación: son de una clase suya. */
    public function test_tareas_y_cuestionarios_no_se_aprueban(): void
    {
        $act = $this->crear($this->tarea(['asignatura_id' => $this->escena()->clase_ajeno]), 'ajeno');

        $r = $this->publicar($act['id'], [], 'ajeno');
        $this->assertSame('abierta', $r['estado']);
        $this->assertFalse($r['requiere_aprobacion']);
    }

    public function test_quien_tiene_rol_directivo_no_la_necesita(): void
    {
        $this->darRol((int) $this->escena()->ajeno->id, 'Rector');

        $act = $this->crear($this->encuesta(['responden' => 'acudientes']), 'ajeno');
        $this->unica($act['id'], null, [], 'ajeno');

        $r = $this->publicar($act['id'], [], 'ajeno');
        $this->assertSame('abierta', $r['estado']);
        $this->assertFalse($r['requiere_aprobacion']);
    }

    public function test_aprobar_la_publica_y_avisa(): void
    {
        $act = $this->crear($this->encuesta(['responden' => 'acudientes']), 'ajeno');
        $this->unica($act['id'], null, [], 'ajeno');
        $this->publicar($act['id'], [], 'ajeno');

        // Los directivos la ven por aprobar; el aviso `por_aprobar` les llegó.
        $this->assertContains($act['id'], array_column($this->como('directivo')->getJson('/api/act/bandeja?vista=aprobar')->json(), 'id'));
        $this->assertContains((int) $this->escena()->directivo->id, array_map(fn ($a) => (int) $a->user_id, $this->avisos($act['id'], 'por_aprobar')));

        // La vista previa del directivo, sin que le toque.
        $this->como('directivo')->getJson("/api/act/{$act['id']}/responder?previa=1")->assertStatus(200)->assertJsonPath('previa', true);

        // El personal llano no aprueba.
        $this->como('llano')->postJson("/api/act/{$act['id']}/aprobar")->assertStatus(403);
        $this->assertSame('por_aprobar', $this->fila($act['id'])->estado);

        $this->como('directivo')->postJson("/api/act/{$act['id']}/aprobar")->assertStatus(200)->assertJson(['estado' => 'abierta']);

        $fila = $this->fila($act['id']);
        $this->assertSame('publicada', $fila->estado);
        $this->assertSame((int) $this->escena()->directivo->id, (int) $fila->aprobada_por);
        $this->assertNotNull($fila->aprobada_at);
        $this->assertSame([(int) $this->escena()->ajeno->id], array_map(fn ($a) => (int) $a->user_id, $this->avisos($act['id'], 'aprobada')));
        $this->assertNotEmpty($this->avisos($act['id'], 'publicada'), 'Aprobada, no avisó a sus destinatarios.');

        // Ya no está por aprobar: 409.
        $this->como('directivo')->postJson("/api/act/{$act['id']}/aprobar")->assertStatus(409);
    }

    public function test_rechazar_pide_motivo_y_la_devuelve_a_borrador(): void
    {
        $act = $this->crear($this->encuesta(['responden' => 'acudientes']), 'ajeno');
        $this->unica($act['id'], null, [], 'ajeno');
        $this->publicar($act['id'], [], 'ajeno');

        $this->como('directivo')->postJson("/api/act/{$act['id']}/rechazar", ['motivo' => '  '])->assertStatus(422);
        $this->como('directivo')->postJson("/api/act/{$act['id']}/rechazar", ['motivo' => str_repeat('m', 501)])->assertStatus(422);
        $this->assertSame('por_aprobar', $this->fila($act['id'])->estado);

        $this->como('directivo')->postJson("/api/act/{$act['id']}/rechazar", ['motivo' => 'Las preguntas 2 y 3 son la misma.'])
            ->assertStatus(200)->assertJson(['estado' => 'borrador']);

        $this->assertSame('borrador', $this->fila($act['id'])->estado);
        $this->assertCount(1, $this->avisos($act['id'], 'rechazada'));

        $mia = collect($this->como('ajeno')->getJson('/api/act/bandeja?vista=mias')->json())->firstWhere('id', $act['id']);
        $this->assertSame('Las preguntas 2 y 3 son la misma.', $mia['rechazo_motivo']);

        // Volver a pedirla borra el motivo.
        $this->publicar($act['id'], [], 'ajeno');
        $this->assertNull($this->fila($act['id'])->rechazo_motivo);
    }

    /** Nadie aprueba la suya: un directivo nunca queda por aprobar, pero si la fila lo dice, 403. */
    public function test_la_propia_no_se_aprueba(): void
    {
        $act = $this->crear($this->encuesta(), 'directivo');
        DB::table('ws_actividades')->where('id', $act['id'])->update(['estado' => 'por_aprobar']);

        $this->como('directivo')->postJson("/api/act/{$act['id']}/aprobar")->assertStatus(403);
        $this->como('directivo')->postJson("/api/act/{$act['id']}/rechazar", ['motivo' => 'x'])->assertStatus(403);
        $this->assertSame('por_aprobar', $this->fila($act['id'])->estado);
    }

    /** Cambiar a quién va de una por aprobar la devuelve a borrador: la aprobación se recalcula. */
    public function test_cambiar_a_quien_va_de_una_por_aprobar_la_devuelve_a_borrador(): void
    {
        $act = $this->crear($this->encuesta(['responden' => 'acudientes']), 'ajeno');
        $this->unica($act['id'], null, [], 'ajeno');
        $this->publicar($act['id'], [], 'ajeno');

        $this->como('ajeno')->postJson("/api/act/{$act['id']}/guardar", ['responden' => 'alumnos'])->assertStatus(200)
            ->assertJsonPath('estado', 'borrador');
    }
}
