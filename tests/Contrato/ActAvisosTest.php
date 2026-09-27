<?php

namespace Tests\Contrato;

use App\Services\Notificaciones\Publicador;
use App\Services\Notificaciones\TemasDeNotificacion;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\PendingCommand;

/**
 * Actividades nuevas — **los avisos y el calendario** (contrato §3.10, §3.16 y tanda 5).
 *
 * `ws_avisos` es la bandeja de salida: los endpoints encolan una fila por hecho y destinatario, la
 * campana (`act/avisos`) y el push (`notificaciones:enviar`, fuente `actividades`) leen las mismas
 * filas. Se mira:
 *
 * - publicar: una fila por alumno (a su tema, `user_id` NULL) y una por acudiente oficial y hijo;
 * - recordar: sólo a quien falta, salvo en `total`, donde no se sabe quién falta y va a todos; 429 si
 *   al mismo grupo se le recordó hace menos de 12 h;
 * - la campana junta por actividad y clase, y «leído» es una marca que sólo avanza;
 * - el calendario es una capa derivada: no escribe en `calendario`;
 * - `notificaciones:enviar --seco` lee la fuente sin mandar nada ni mover la marca, y sin red (el
 *   publicador es de mentira).
 */
class ActAvisosTest extends CasoDeActividades
{
    private object $publicador;

    protected function setUp(): void
    {
        parent::setUp();

        $this->publicador = new class implements Publicador
        {
            public array $mandados = [];

            public function estaConfigurado(): bool
            {
                return true;
            }

            public function publicar(string $tema, string $titulo, string $cuerpo, array $datos = []): bool
            {
                $this->mandados[] = compact('tema', 'titulo', 'cuerpo', 'datos');

                return true;
            }
        };
        $this->app->instance(Publicador::class, $this->publicador);

        foreach (['notas', 'asistencia', 'disciplina', 'muro', 'matricula', 'compromiso', 'compromiso-resultado', 'actividades'] as $fuente) {
            Cache::forget('notificaciones.marca.'.$fuente);
        }
    }

    public function test_publicar_encola_una_fila_por_alumno_y_por_acudiente_e_hijo(): void
    {
        $e = $this->escena();
        $conteo = $this->como('titular')->postJson('/api/act/conteo', ['modo' => 'encuesta', 'alcance' => 'grupo', 'responden' => 'ambos',
            'acudiente_por_hijo' => true, 'destinatarios' => [['grupo_id' => $e->grupo_id]]])->json();

        [$id] = $this->encuestaPublicada(['responden' => 'ambos', 'acudiente_por_hijo' => true]);
        $filas = $this->avisos($id, 'publicada');

        $deAlumno = array_filter($filas, fn ($f) => $f->user_id === null);
        $deAcudiente = array_filter($filas, fn ($f) => $f->user_id !== null);

        $this->assertCount($conteo['alumnos'] + $conteo['sin_cuenta'], $deAlumno, 'Una fila por alumno del grupo, a su tema.');
        $this->assertCount($conteo['acudientes'], $deAcudiente, 'Una fila por acudiente oficial e hijo.');
        $this->assertContains((int) $this->alumno()->alumno_id, array_map(fn ($f) => (int) $f->alumno_id, $deAlumno));
        $this->assertTrue(collect($deAcudiente)->contains(fn ($f) => (int) $f->user_id === (int) $e->acudiente->user_id
            && (int) $f->alumno_id === (int) $e->acudiente->alumno_id));
    }

    public function test_sin_avisar_al_publicar_o_programada_no_encola(): void
    {
        [$callada] = $this->encuestaPublicada(['avisos' => ['al_publicar' => false]]);
        $this->assertSame([], $this->avisos($callada));

        $programada = $this->crear($this->encuesta(['publica_at' => '2099-01-01 08:00', 'titulo' => 'Programada']));
        $this->unica($programada['id'], null);
        $this->publicar($programada['id']);
        $this->assertSame([], $this->avisos($programada['id']), 'Una programada se avisa cuando se abre, no al publicarla.');
    }

    public function test_recordar_va_a_quien_falta_y_no_repite_en_12_horas(): void
    {
        [$id, $p] = $this->encuestaPublicada(['anonimato' => 'seguimiento']);
        $this->enviar($id, $this->alumno(0), [$this->marcar($p, 0)])->assertStatus(200);
        $grupo = $this->escena()->grupo_id;
        $destinatarios = $this->como('titular')->getJson("/api/act/{$id}/faltan")->json('grupos.0.total');

        $avisados = $this->como('titular')->postJson("/api/act/{$id}/recordar", ['grupo_id' => $grupo])->assertStatus(200)->json('avisados');

        $this->assertSame($destinatarios - 1, $avisados);
        $filas = $this->avisos($id, 'recordatorio');
        $this->assertCount($avisados, $filas);
        $this->assertNotContains((int) $this->alumno(0)->alumno_id, array_map(fn ($f) => (int) $f->alumno_id, $filas), 'Se le recordó a quien ya respondió.');
        $this->assertNotNull($this->como('titular')->getJson("/api/act/{$id}/faltan")->json('ultimo_recordatorio_at'));

        $this->como('titular')->postJson("/api/act/{$id}/recordar", ['grupo_id' => $grupo])->assertStatus(429);
        $this->como('titular')->postJson("/api/act/{$id}/recordar")->assertStatus(429);

        // Pasadas 12 horas, otra vez.
        DB::table('ws_avisos')->where('actividad_id', $id)->update(['created_at' => now('America/Bogota')->subHours(13)->format('Y-m-d H:i:s')]);
        $this->como('titular')->postJson("/api/act/{$id}/recordar", ['grupo_id' => $grupo])->assertStatus(200)->assertJson(['avisados' => $avisados]);
    }

    /** En `total` no se sabe quién falta: el recordatorio va a todos, también a quien respondió. */
    public function test_en_total_recordar_va_a_todos(): void
    {
        [$id, $p] = $this->encuestaPublicada(['anonimato' => 'total']);
        $this->enviar($id, $this->alumno(0), [$this->marcar($p, 0)])->assertStatus(200);

        $this->como('titular')->postJson("/api/act/{$id}/recordar")->assertStatus(200);

        $this->assertContains((int) $this->alumno(0)->alumno_id, array_map(fn ($f) => (int) $f->alumno_id, $this->avisos($id, 'recordatorio')));

        // Y la frase no le dice a nadie que le falta: «si aún no lo has hecho».
        $aviso = collect($this->como($this->alumno(0))->getJson('/api/act/avisos')->json('avisos'))->firstWhere('clase', 'recordatorio');
        $this->assertStringContainsString('Si aún no lo has hecho', $aviso['texto']);
    }

    public function test_recordar_es_del_dueno_y_de_una_abierta(): void
    {
        [$id] = $this->encuestaPublicada();
        $this->como('ajeno')->postJson("/api/act/{$id}/recordar")->assertStatus(403);

        DB::table('ws_actividades')->where('id', $id)->update(['cierra_at' => '2020-01-01 00:00:00']);
        $this->como('titular')->postJson("/api/act/{$id}/recordar")->assertStatus(409);
        $this->assertSame([], $this->avisos($id, 'recordatorio'));
    }

    public function test_la_campana_y_los_leidos(): void
    {
        [$id] = $this->encuestaPublicada(['titulo' => 'Salida pedagógica']);
        $yo = $this->alumno();

        $r = $this->como($yo)->getJson('/api/act/avisos')->assertStatus(200);
        $aviso = collect($r->json('avisos'))->firstWhere('actividad.id', $id);
        $this->assertNotNull($aviso, 'La campana del alumno no trae la publicación.');
        $this->assertSame('publicada', $aviso['clase']);
        $this->assertSame("/act/{$id}/responder", $aviso['enlace']);
        $this->assertFalse($aviso['leido']);
        $this->assertGreaterThanOrEqual(1, $r->json('no_leidos'));

        $hasta = $this->como($yo)->postJson('/api/act/avisos/leidos')->assertStatus(200)->json('hasta_id');
        $this->assertGreaterThanOrEqual($aviso['id'], $hasta);

        $r = $this->como($yo)->getJson('/api/act/avisos');
        $this->assertSame(0, $r->json('no_leidos'));
        $this->assertTrue(collect($r->json('avisos'))->firstWhere('actividad.id', $id)['leido']);

        // La marca sólo avanza.
        $this->como($yo)->postJson('/api/act/avisos/leidos', ['hasta_id' => 1])->assertJson(['hasta_id' => $hasta]);

        // Y a otro no le llega lo de éste: a un docente ajeno, nada de esta actividad.
        $this->assertNull(collect($this->como('ajeno')->getJson('/api/act/avisos')->json('avisos'))->firstWhere('actividad.id', $id));
    }

    /** Doce entregas de la misma tarea son una línea: se prueba con dos. */
    public function test_la_campana_junta_las_entregas(): void
    {
        $act = $this->crear($this->tarea());
        $this->publicar($act['id']);

        foreach ([0, 1] as $i) {
            $this->como($this->alumno($i))->postJson("/api/act/{$act['id']}/entregar", ['texto' => 'Hecho'])->assertStatus(200);
        }

        $lineas = array_values(array_filter($this->como('titular')->getJson('/api/act/avisos')->json('avisos'),
            fn ($a) => $a['actividad']['id'] === $act['id']));

        $this->assertCount(1, $lineas);
        $this->assertSame(['entregada', 2, 2, null, "/act/{$act['id']}/entregas"],
            [$lineas[0]['clase'], $lineas[0]['cuantas'], $lineas[0]['nuevas'], $lineas[0]['alumno'], $lineas[0]['enlace']]);
        $this->assertStringContainsString('2 entregas nuevas', $lineas[0]['texto']);
    }

    public function test_el_calendario_es_una_capa_derivada(): void
    {
        $filasDeCalendario = DB::table('calendario')->count();

        [$enRango] = $this->encuestaPublicada(['cierra_at' => '2099-03-10 18:00', 'titulo' => 'En rango']);
        [$fuera] = $this->encuestaPublicada(['cierra_at' => '2099-05-10 18:00', 'titulo' => 'Fuera']);
        [$oculta] = $this->encuestaPublicada(['cierra_at' => '2099-03-11 18:00', 'titulo' => 'Oculta', 'avisos' => ['en_calendario' => false]]);
        $programada = $this->crear($this->encuesta(['publica_at' => '2099-03-01 08:00', 'cierra_at' => '2099-03-12 18:00', 'titulo' => 'Programada']));
        $this->unica($programada['id'], null);
        $this->publicar($programada['id']);

        $rango = '?desde=2099-03-01&hasta=2099-03-31';

        $delDueno = collect($this->como('titular')->getJson('/api/act/calendario'.$rango)->assertStatus(200)->json())->keyBy('id');
        $this->assertTrue($delDueno[$enRango]['soy_dueno']);
        $this->assertSame('2099-03-10 18:00:00', $delDueno[$enRango]['cierra_at']);
        $this->assertTrue($delDueno->has($programada['id']), 'El dueño ve su programada.');
        $this->assertFalse($delDueno->has($fuera));
        $this->assertFalse($delDueno->has($oculta));

        $delAlumno = collect($this->como($this->alumno())->getJson('/api/act/calendario'.$rango)->assertStatus(200)->json())->keyBy('id');
        $this->assertSame('pendiente', $delAlumno[$enRango]['mi_estado']);
        $this->assertFalse($delAlumno[$enRango]['soy_dueno']);
        $this->assertFalse($delAlumno->has($programada['id']), 'Una programada todavía no existe para quien la recibe.');

        $this->assertSame($filasDeCalendario, DB::table('calendario')->count(), 'El calendario copió filas.');

        $this->como('titular')->getJson('/api/act/calendario?desde=10/03/2099&hasta=2099-03-31')->assertStatus(422);
        $this->como('titular')->getJson('/api/act/calendario?desde=2099-03-31&hasta=2099-03-01')->assertStatus(422);
    }

    /** El push en seco: dice qué mandaría, sin mandar ni mover la marca (y sin red). */
    public function test_el_push_en_seco_lee_la_fuente_actividades(): void
    {
        $this->correr();   // la primera pasada pone las marcas
        $marca = Cache::get('notificaciones.marca.actividades');
        $this->assertNotNull($marca);

        [$id] = $this->encuestaPublicada(['titulo' => 'Clima de aula']);
        $this->assertNotEmpty($this->avisos($id, 'publicada'));

        $tema = TemasDeNotificacion::deAlumnoYTipo((int) $this->alumno()->alumno_id, 'actividad');
        $this->correr('--seco', "[actividades] {$tema} :: Encuesta nueva");

        $this->assertSame([], $this->publicador->mandados, 'En seco mandó de verdad.');
        $this->assertSame($marca, Cache::get('notificaciones.marca.actividades'), 'En seco movió la marca.');

        // Y en firme, un aviso por tema, y la marca avanza hasta la última fila.
        $this->correr();
        $alTema = array_values(array_filter($this->publicador->mandados, fn ($m) => $m['tema'] === $tema));
        $this->assertCount(1, $alTema);
        $this->assertSame((string) $id, $alTema[0]['datos']['actividad_id']);
        $this->assertSame((int) DB::table('ws_avisos')->max('id'), (int) Cache::get('notificaciones.marca.actividades'));
    }

    /** El reloj encola `por_cerrar` a quien falta; en seco sólo cuenta. */
    public function test_el_reloj_encola_por_cerrar(): void
    {
        $cierre = now('America/Bogota')->addHours(5)->format('Y-m-d H:i');
        [$id, $p] = $this->encuestaPublicada(['cierra_at' => $cierre, 'avisos' => ['recordar_horas_antes' => 24]]);
        $this->enviar($id, $this->alumno(0), [$this->marcar($p, 0)])->assertStatus(200);

        $this->correr('--seco');
        $this->assertSame([], $this->avisos($id, 'por_cerrar'), 'En seco encoló.');

        $this->correr();
        $filas = $this->avisos($id, 'por_cerrar');
        $this->assertNotEmpty($filas);
        $this->assertNotContains((int) $this->alumno(0)->alumno_id, array_map(fn ($f) => (int) $f->alumno_id, $filas));

        // Una sola vez por actividad.
        $this->correr();
        $this->assertCount(count($filas), $this->avisos($id, 'por_cerrar'));
    }

    private function correr(string $opciones = '', ?string $salida = null): void
    {
        $comando = $this->artisan(trim('notificaciones:enviar '.$opciones));

        if ($salida !== null && $comando instanceof PendingCommand) {
            $comando->expectsOutputToContain($salida);
        }

        $codigo = $comando instanceof PendingCommand ? $comando->run() : $comando;

        $this->assertSame(0, $codigo, 'El comando salió con código '.$codigo.'.');
    }
}
