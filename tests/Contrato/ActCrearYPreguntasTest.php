<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * Actividades nuevas — **crear, guardar, borrar, las preguntas y publicar** (contrato §2.2, §2.4,
 * §2.5, §2.9, §3.3–§3.5).
 *
 * Lo que se fija es lo que el front no puede comprobar solo: las reglas de §2.2 (tareas y
 * cuestionarios sólo por clase, la clase tiene que ser suya), la validación de cada tipo de
 * pregunta, que una condición sólo mire hacia atrás —al guardarla y al reordenar—, que no se borre
 * una pregunta de la que depende otra, y la lista de problemas de `publicar`.
 */
class ActCrearYPreguntasTest extends CasoDeActividades
{
    // ------------------------------------------------------------------ crear, guardar, borrar

    public function test_crear_deja_un_borrador_del_dueno_en_el_anio_y_periodo_del_token(): void
    {
        $act = $this->crear($this->cuestionario(['titulo' => 'Fracciones']));
        $fila = $this->fila($act['id']);

        $this->assertSame('borrador', $act['estado']);
        $this->assertSame('cuestionario', $fila->modo);
        $this->assertSame((int) $this->escena()->titular->id, (int) $fila->created_by, 'created_by guarda users.id en lo nuevo.');
        $this->assertSame($this->escena()->year_id, (int) $fila->year_id);
        $this->assertSame($this->escena()->periodo_id, (int) $fila->periodo_id);
        $this->assertSame($this->escena()->grupo_id, (int) $fila->grupo_id, 'La clase implica su grupo.');
        $this->assertSame([['publico' => 'alumnos', 'grupo_id' => null, 'grado_id' => null,
            'asignatura_id' => (int) $this->escena()->clase, 'user_id' => null]], $act['destinatarios']);
        $this->assertSame(50, $act['nota_maxima'], 'Sin nota máxima, la de la escala del año (el seed califica sobre 50).');
    }

    /** §2.2: tareas y cuestionarios son de una clase, los responden sus alumnos, con nombre. */
    public function test_tarea_y_cuestionario_solo_por_clase_y_con_nombre(): void
    {
        foreach ([
            $this->cuestionario(['alcance' => 'grupo', 'grupo_id' => $this->escena()->grupo_id]),
            $this->tarea(['responden' => 'acudientes']),
            $this->cuestionario(['anonimato' => 'seguimiento']),
        ] as $mala) {
            $this->como('titular')->postJson('/api/act/crear', $mala)->assertStatus(422);
        }

        $this->assertSame(0, DB::table('ws_actividades')->whereNotNull('modo')->where('created_by', $this->escena()->titular->id)->count());
    }

    public function test_la_clase_tiene_que_ser_suya(): void
    {
        $this->como('titular')->postJson('/api/act/crear', $this->cuestionario(['asignatura_id' => $this->escena()->clase_ajeno]))
            ->assertStatus(403);

        // Un directivo sí crea en cualquier clase.
        $this->como('directivo')->postJson('/api/act/crear', $this->cuestionario(['asignatura_id' => $this->escena()->clase_ajeno]))
            ->assertStatus(200);
    }

    public function test_las_validaciones_de_la_config(): void
    {
        $malas = [
            'encuesta con nota' => $this->encuesta(['califica' => true]),
            'personal sin responder personal' => $this->encuesta(['alcance' => 'personal', 'responden' => 'alumnos']),
            'nota máxima por encima de la escala' => $this->cuestionario(['nota_maxima' => 51]),
            'seis intentos' => $this->cuestionario(['oportunidades' => 6]),
            'fecha mal escrita' => $this->cuestionario(['cierra_at' => '30/09/2026']),
            'modo que no existe' => ['modo' => 'examen'] + $this->cuestionario(),
            'grupo de otro año' => $this->encuesta(['grupo_id' => 999999]),
        ];

        foreach ($malas as $que => $config) {
            $r = $this->como('titular')->postJson('/api/act/crear', $config);
            $this->assertSame(422, $r->status(), "Aceptó {$que} ({$r->status()}).");
        }
    }

    public function test_guardar_cambia_lo_que_trae_y_no_deja_cambiar_el_modo(): void
    {
        $act = $this->crear($this->encuesta());

        $r = $this->como('titular')->postJson("/api/act/{$act['id']}/guardar", ['titulo' => 'Clima escolar', 'cierra_at' => '2099-01-01 08:00']);
        $r->assertStatus(200);
        $this->assertSame('Clima escolar', $r->json('titulo'));
        $this->assertSame('2099-01-01 08:00:00', $r->json('cierra_at'));
        $this->assertSame('grupo', $r->json('alcance'), 'Lo que no vino se quedó como estaba.');

        $this->como('titular')->postJson("/api/act/{$act['id']}/guardar", ['modo' => 'tarea'])->assertStatus(422);
    }

    public function test_borrar_solo_un_borrador(): void
    {
        $act = $this->crear($this->encuesta());
        $this->como('titular')->postJson("/api/act/{$act['id']}/borrar")->assertStatus(200)->assertJson(['ok' => true]);
        $this->assertNotNull($this->fila($act['id'])->deleted_at);
        $this->como('titular')->getJson("/api/act/{$act['id']}")->assertStatus(404);

        [$publicada] = $this->encuestaPublicada();
        $this->como('titular')->postJson("/api/act/{$publicada}/borrar")->assertStatus(409);
        $this->assertNull($this->fila($publicada)->deleted_at, 'Una publicada no se borra: se cierra.');
    }

    // ------------------------------------------------------------------ preguntas

    public function test_cada_tipo_se_guarda_con_su_forma(): void
    {
        $act = $this->crear($this->encuesta());
        $id = $act['id'];

        $sino = $this->pregunta($id, ['tipo' => 'sino', 'enunciado' => '¿Viene en bus?']);
        $this->assertSame(['Sí', 'No'], array_column($sino['opciones'], 'definicion'), 'Sí/No nace con sus dos opciones.');

        $parrafo = $this->pregunta($id, ['tipo' => 'parrafo', 'enunciado' => 'Cuéntanos', 'puntos' => 5]);
        $this->assertSame(0, $parrafo['puntos'], 'Un párrafo no califica: sus puntos van en 0.');
        $this->assertFalse($parrafo['compartir'], 'Los textos libres no se comparten por defecto.');

        $escala = $this->pregunta($id, ['tipo' => 'escala', 'enunciado' => '¿Cuánto?']);
        $this->assertSame('numeros', $escala['escala_estilo']);

        $video = $this->pregunta($id, ['tipo' => 'video', 'enunciado' => 'Mira', 'youtube_id' => 'dQw4w9WgXcQ',
            'youtube_inicio' => 10, 'youtube_fin' => 40, 'opciones' => [['definicion' => 'Uno', 'is_correct' => true]]]);
        $this->assertSame('dQw4w9WgXcQ', $video['youtube_id']);

        $multiple = $this->pregunta($id, ['tipo' => 'multiple', 'enunciado' => 'Marca', 'puntaje_parcial' => true,
            'opciones' => [['definicion' => 'a', 'is_correct' => true], ['definicion' => 'b', 'is_correct' => true]]]);
        $this->assertTrue($multiple['puntaje_parcial']);

        $unicaConParcial = $this->unica($id, 0, ['puntaje_parcial' => true]);
        $this->assertFalse($unicaConParcial['puntaje_parcial'], 'El puntaje parcial sólo existe en múltiple.');

        $this->assertSame([1, 2, 3, 4, 5, 6], array_column($this->como('titular')->getJson("/api/act/{$id}")->json('preguntas'), 'orden'));
    }

    public function test_las_validaciones_de_una_pregunta(): void
    {
        $act = $this->crear($this->encuesta());
        $anonima = $this->crear($this->encuesta(['anonimato' => 'seguimiento']));

        $malas = [
            'video sin video' => [$act, ['tipo' => 'video', 'enunciado' => 'x']],
            'la URL entera en vez del id' => [$act, ['tipo' => 'unica', 'enunciado' => 'x', 'youtube_id' => 'https://youtu.be/dQw4w9WgXcQ']],
            'un id de 10 caracteres' => [$act, ['tipo' => 'unica', 'enunciado' => 'x', 'youtube_id' => 'dQw4w9WgXc']],
            'el fin antes del inicio' => [$act, ['tipo' => 'unica', 'enunciado' => 'x', 'youtube_id' => 'dQw4w9WgXcQ', 'youtube_inicio' => 30, 'youtube_fin' => 10]],
            'dos correctas en única' => [$act, ['tipo' => 'unica', 'enunciado' => 'x', 'opciones' => [
                ['definicion' => 'a', 'is_correct' => true], ['definicion' => 'b', 'is_correct' => true]]]],
            'una opción vacía' => [$act, ['tipo' => 'unica', 'enunciado' => 'x', 'opciones' => [['definicion' => '  ']]]],
            'un error típico larguísimo' => [$act, ['tipo' => 'unica', 'enunciado' => 'x', 'opciones' => [
                ['definicion' => 'a', 'error_tipico' => str_repeat('e', 301)]]]],
            'un enlace sin http' => [$act, ['tipo' => 'unica', 'enunciado' => 'x', 'enlace_url' => 'colegio.edu.co']],
            'sin enunciado' => [$act, ['tipo' => 'unica', 'enunciado' => '   ']],
            'un tipo que no existe' => [$act, ['tipo' => 'dibujo', 'enunciado' => 'x']],
            'archivo en una anónima' => [$anonima, ['tipo' => 'archivo', 'enunciado' => 'Sube']],
        ];

        foreach ($malas as $que => [$a, $cuerpo]) {
            $r = $this->como('titular')->postJson("/api/act/{$a['id']}/preguntas", $cuerpo);
            $this->assertSame(422, $r->status(), "Aceptó {$que} ({$r->status()}).");
        }

        $this->assertSame(0, DB::table('ws_preguntas')->whereIn('actividad_id', [$act['id'], $anonima['id']])->count());
    }

    /** El error típico de un distractor se guarda y vuelve (§3.4). */
    public function test_el_error_tipico_se_guarda(): void
    {
        $act = $this->crear($this->cuestionario());
        $p = $this->pregunta($act['id'], ['tipo' => 'unica', 'enunciado' => '2 + 2', 'opciones' => [
            ['definicion' => '4', 'is_correct' => true],
            ['definicion' => '22', 'is_correct' => false, 'error_tipico' => 'Pegó las cifras en vez de sumarlas.'],
        ]]);

        $this->assertSame('Pegó las cifras en vez de sumarlas.', $p['opciones'][1]['error_tipico']);
        $this->assertNull($p['opciones'][0]['error_tipico']);
    }

    /** Guardar una pregunta reemplaza sus opciones: con id se actualizan, las que faltan se van. */
    public function test_guardar_una_pregunta_reemplaza_sus_opciones(): void
    {
        $act = $this->crear($this->encuesta());
        $p = $this->unica($act['id'], null);
        [$a, , $c] = $p['opciones'];

        $r = $this->como('titular')->postJson("/api/act/preguntas/{$p['id']}/guardar", [
            'tipo' => 'unica', 'enunciado' => 'Nueva', 'opciones' => [
                ['id' => $c['id'], 'definicion' => 'C cambiada'],
                ['id' => $a['id'], 'definicion' => 'A'],
                ['definicion' => 'D'],
            ]]);

        $r->assertStatus(200);
        $this->assertSame(['C cambiada', 'A', 'D'], array_column($r->json('opciones'), 'definicion'));
        $this->assertSame($c['id'], $r->json('opciones.0.id'), 'La que traía id se actualizó, no se recreó.');
        $this->assertSame(0, DB::table('ws_opciones')->where('id', $p['opciones'][1]['id'])->count(), 'La que faltaba no se borró.');

        // Un id de otra pregunta no se cuela.
        $otra = $this->unica($act['id'], null);
        $this->como('titular')->postJson("/api/act/preguntas/{$p['id']}/guardar", [
            'tipo' => 'unica', 'enunciado' => 'x', 'opciones' => [['id' => $otra['opciones'][0]['id'], 'definicion' => 'robada']]])
            ->assertStatus(422);
    }

    /** Un lote va entero o no va: una mala tumba el lote antes de escribir la primera. */
    public function test_el_lote_se_valida_entero_antes_de_escribir(): void
    {
        $act = $this->crear($this->encuesta());

        $this->como('titular')->postJson("/api/act/{$act['id']}/preguntas/lote", ['preguntas' => [
            ['tipo' => 'parrafo', 'enunciado' => 'Buena'],
            ['tipo' => 'video', 'enunciado' => 'Mala, sin video'],
        ]])->assertStatus(422);

        $this->assertSame(0, DB::table('ws_preguntas')->where('actividad_id', $act['id'])->count());

        $r = $this->como('titular')->postJson("/api/act/{$act['id']}/preguntas/lote", ['preguntas' => [
            ['tipo' => 'parrafo', 'enunciado' => 'Una'],
            ['tipo' => 'sino', 'enunciado' => 'Dos'],
        ]]);
        $r->assertStatus(200);
        $this->assertSame([1, 2], array_column($r->json(), 'orden'));
    }

    // ------------------------------------------------------------------ condiciones

    public function test_una_condicion_solo_mira_hacia_atras(): void
    {
        $act = $this->crear($this->encuesta());
        $p1 = $this->unica($act['id'], null);
        $p2 = $this->pregunta($act['id'], ['tipo' => 'corta', 'enunciado' => '¿Por qué?']);
        $p3 = $this->pregunta($act['id'], ['tipo' => 'archivo', 'enunciado' => 'Sube']);
        $p4 = $this->pregunta($act['id'], ['tipo' => 'parrafo', 'enunciado' => 'Algo más']);

        // Hacia delante: 422 con la pregunta en la lista.
        $r = $this->como('titular')->postJson("/api/act/preguntas/{$p1['id']}/condiciones", ['grupos' => [[
            ['depende_de_id' => $p2['id'], 'operador' => 'es', 'valor' => 'x']]]]);
        $r->assertStatus(422);
        $this->assertSame([$p1['id']], $r->json('preguntas'));

        // A sí misma, y a una de archivo: tampoco.
        $this->como('titular')->postJson("/api/act/preguntas/{$p2['id']}/condiciones", ['grupos' => [[
            ['depende_de_id' => $p2['id'], 'operador' => 'es', 'valor' => 'x']]]])->assertStatus(422);
        $this->como('titular')->postJson("/api/act/preguntas/{$p4['id']}/condiciones", ['grupos' => [[
            ['depende_de_id' => $p3['id'], 'operador' => 'es', 'valor' => 'x']]]])->assertStatus(422);

        // Sobre una de opciones, una opción suya; sobre una abierta, un valor.
        $this->como('titular')->postJson("/api/act/preguntas/{$p2['id']}/condiciones", ['grupos' => [[
            ['depende_de_id' => $p1['id'], 'operador' => 'es', 'valor' => 'A']]]])->assertStatus(422);

        $r = $this->como('titular')->postJson("/api/act/preguntas/{$p4['id']}/condiciones", ['grupos' => [
            [['depende_de_id' => $p1['id'], 'operador' => 'es', 'opcion_id' => $p1['opciones'][0]['id']],
                ['depende_de_id' => $p2['id'], 'operador' => 'contiene', 'valor' => 'bus']],
            [['depende_de_id' => $p1['id'], 'operador' => 'no_es', 'opcion_id' => $p1['opciones'][2]['id']]],
        ]]);

        $r->assertStatus(200);
        $this->assertCount(2, $r->json('condiciones'), 'Dos grupos (O), el primero con dos condiciones (Y).');
        $this->assertCount(2, $r->json('condiciones.0'));
        $this->assertSame(3, DB::table('ws_condiciones')->where('pregunta_id', $p4['id'])->count());
    }

    public function test_reordenar_que_rompe_una_condicion_es_422_y_no_mueve_nada(): void
    {
        $act = $this->crear($this->encuesta());
        $p1 = $this->unica($act['id'], null);
        $p2 = $this->pregunta($act['id'], ['tipo' => 'parrafo', 'enunciado' => 'Depende']);
        $p3 = $this->pregunta($act['id'], ['tipo' => 'parrafo', 'enunciado' => 'Suelta']);
        $this->como('titular')->postJson("/api/act/preguntas/{$p2['id']}/condiciones", ['grupos' => [[
            ['depende_de_id' => $p1['id'], 'operador' => 'es', 'opcion_id' => $p1['opciones'][0]['id']]]]])->assertStatus(200);

        $r = $this->como('titular')->postJson("/api/act/{$act['id']}/preguntas/orden", ['orden' => [
            ['id' => $p2['id'], 'seccion' => 1], ['id' => $p1['id'], 'seccion' => 1], ['id' => $p3['id'], 'seccion' => 2]]]);

        $r->assertStatus(422);
        $this->assertSame([$p2['id']], $r->json('preguntas'));
        $this->assertSame([1, 2, 3], [
            (int) DB::table('ws_preguntas')->where('id', $p1['id'])->value('orden'),
            (int) DB::table('ws_preguntas')->where('id', $p2['id'])->value('orden'),
            (int) DB::table('ws_preguntas')->where('id', $p3['id'])->value('orden'),
        ], 'El 422 dejó el orden a medias.');

        // Un orden que respeta la condición sí, con sus secciones.
        $r = $this->como('titular')->postJson("/api/act/{$act['id']}/preguntas/orden", ['orden' => [
            ['id' => $p3['id'], 'seccion' => 1], ['id' => $p1['id'], 'seccion' => 2], ['id' => $p2['id'], 'seccion' => 2]]]);
        $r->assertStatus(200);
        $this->assertSame([$p3['id'], $p1['id'], $p2['id']], array_column($r->json(), 'id'));
        $this->assertSame([1, 2, 2], array_column($r->json(), 'seccion'));

        // Incompleto: 422.
        $this->como('titular')->postJson("/api/act/{$act['id']}/preguntas/orden", ['orden' => [['id' => $p1['id']]]])->assertStatus(422);
    }

    public function test_borrar_una_pregunta_con_dependientes_es_409(): void
    {
        $act = $this->crear($this->encuesta());
        $p1 = $this->unica($act['id'], null);
        $p2 = $this->pregunta($act['id'], ['tipo' => 'parrafo', 'enunciado' => 'Depende']);
        $p3 = $this->pregunta($act['id'], ['tipo' => 'parrafo', 'enunciado' => 'Suelta']);
        $this->como('titular')->postJson("/api/act/preguntas/{$p2['id']}/condiciones", ['grupos' => [[
            ['depende_de_id' => $p1['id'], 'operador' => 'es', 'opcion_id' => $p1['opciones'][0]['id']]]]])->assertStatus(200);

        $r = $this->como('titular')->postJson("/api/act/preguntas/{$p1['id']}/borrar");
        $r->assertStatus(409);
        $this->assertSame([$p2['id']], $r->json('preguntas'));
        $this->assertSame(1, DB::table('ws_preguntas')->where('id', $p1['id'])->count());

        // Sin dependientes se borra y las demás se renumeran.
        $this->como('titular')->postJson("/api/act/preguntas/{$p2['id']}/borrar")->assertStatus(200);
        $this->assertSame([1, 2], [
            (int) DB::table('ws_preguntas')->where('id', $p1['id'])->value('orden'),
            (int) DB::table('ws_preguntas')->where('id', $p3['id'])->value('orden'),
        ]);
    }

    /** Duplicar una pregunta la pone justo después, con sus opciones y condiciones. */
    public function test_duplicar_una_pregunta(): void
    {
        $act = $this->crear($this->encuesta());
        $p1 = $this->unica($act['id'], null);
        $p2 = $this->pregunta($act['id'], ['tipo' => 'parrafo', 'enunciado' => 'Depende']);
        $p3 = $this->pregunta($act['id'], ['tipo' => 'parrafo', 'enunciado' => 'Última']);
        $this->como('titular')->postJson("/api/act/preguntas/{$p2['id']}/condiciones", ['grupos' => [[
            ['depende_de_id' => $p1['id'], 'operador' => 'es', 'opcion_id' => $p1['opciones'][0]['id']]]]])->assertStatus(200);

        $copia = $this->como('titular')->postJson("/api/act/preguntas/{$p2['id']}/duplicar")->assertStatus(200)->json();

        $this->assertSame(3, $copia['orden']);
        $this->assertSame($p1['id'], $copia['condiciones'][0][0]['depende_de_id']);
        $this->assertSame(4, (int) DB::table('ws_preguntas')->where('id', $p3['id'])->value('orden'));
    }

    // ------------------------------------------------------------------ publicar

    public function test_publicar_lista_todos_los_problemas(): void
    {
        $cuestionario = $this->crear($this->cuestionario(['titulo' => '', 'cierra_at' => '2020-01-01 08:00', 'califica' => true]));
        $this->unica($cuestionario['id'], null);

        $r = $this->como('titular')->postJson("/api/act/{$cuestionario['id']}/publicar");
        $r->assertStatus(422);
        $problemas = implode(' | ', $r->json('problemas'));

        foreach (['Falta el título', 'no tiene respuesta correcta', 'La fecha de cierre ya pasó', 'Falta el logro', 'Falta el peso'] as $frase) {
            $this->assertStringContainsString($frase, $problemas);
        }

        $this->assertSame('borrador', $this->fila($cuestionario['id'])->estado);

        $sinPreguntas = $this->crear($this->encuesta());
        $this->assertContains('No tiene preguntas.', $this->como('titular')->postJson("/api/act/{$sinPreguntas['id']}/publicar")
            ->assertStatus(422)->json('problemas'));

        $tareaVacia = $this->crear($this->tarea(['entrega' => ['texto' => false]]));
        $this->assertContains('La tarea no pide ninguna entrega ni tiene preguntas.',
            $this->como('titular')->postJson("/api/act/{$tareaVacia['id']}/publicar")->assertStatus(422)->json('problemas'));

        $alReves = $this->crear($this->encuesta(['publica_at' => '2099-02-01 08:00', 'cierra_at' => '2099-01-01 08:00']));
        $this->unica($alReves['id'], null);
        $this->assertContains('La fecha de cierre es anterior a la de publicación.',
            $this->como('titular')->postJson("/api/act/{$alReves['id']}/publicar")->assertStatus(422)->json('problemas'));
    }

    public function test_publicar_dos_veces_es_409(): void
    {
        [$id] = $this->encuestaPublicada();

        $this->como('titular')->postJson("/api/act/{$id}/publicar")->assertStatus(409);
    }

    public function test_publicar_con_fecha_futura_la_deja_programada(): void
    {
        $act = $this->crear($this->encuesta(['publica_at' => '2099-01-01 08:00']));
        $this->unica($act['id'], null);

        $this->assertSame('programada', $this->publicar($act['id'])['estado']);
    }

    // ------------------------------------------------------------------ §2.9, con respuestas

    public function test_con_respuestas_solo_se_cambian_textos(): void
    {
        [$id, $p] = $this->encuestaPublicada();
        $this->enviar($id, $this->alumno(), [$this->marcar($p, 0)])->assertStatus(200);

        // Añadir, reordenar, borrar: 409.
        $this->como('titular')->postJson("/api/act/{$id}/preguntas", ['tipo' => 'parrafo', 'enunciado' => 'Nueva'])->assertStatus(409);
        $this->como('titular')->postJson("/api/act/preguntas/{$p['id']}/borrar")->assertStatus(409);

        // Cambiar la estructura de la pregunta (una opción de más): 409.
        $opciones = array_map(fn ($o) => ['id' => $o['id'], 'definicion' => $o['definicion']], $p['opciones']);
        $this->como('titular')->postJson("/api/act/preguntas/{$p['id']}/guardar", [
            'tipo' => 'unica', 'enunciado' => 'x', 'opciones' => [...$opciones, ['definicion' => 'D']]])->assertStatus(409);

        // Los textos sí.
        $opciones[0]['definicion'] = 'A, mejor dicha';
        $this->como('titular')->postJson("/api/act/preguntas/{$p['id']}/guardar", [
            'tipo' => 'unica', 'enunciado' => 'Enunciado corregido', 'opciones' => $opciones])->assertStatus(200)
            ->assertJsonPath('opciones.0.definicion', 'A, mejor dicha');

        // En la actividad: el título sí, a quién va no.
        $this->como('titular')->postJson("/api/act/{$id}/guardar", ['titulo' => 'Otro título'])->assertStatus(200);
        $r = $this->como('titular')->postJson("/api/act/{$id}/guardar", ['responden' => 'ambos']);
        $r->assertStatus(409);
        $this->assertContains('responden', $r->json('campos'));
    }
}
