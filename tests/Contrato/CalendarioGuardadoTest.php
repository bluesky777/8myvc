<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * **Guardar un evento con cuerpo, recordatorio y destinatarios — sin romper a
 * los dos clientes que no saben que eso existe.**
 *
 * `calendario/crear-evento` y `calendario/guardar-evento` las llaman también la
 * aplicación vieja y `myvc_flutter`, desplegadas en los quince colegios, y el
 * despliegue va colegio a colegio. O sea que durante días **los dos formatos de
 * cuerpo conviven contra el mismo código**, y la mitad de esta clase existe para
 * fijar eso: lo que un cliente no manda, no se toca.
 *
 * Las dos formas de romperlos son silenciosas —ninguna da error— y por eso están
 * cada una en su caso:
 *
 * 1. **Calcular el espejo de `solo_profes` sobre una lista que el cliente nunca
 *    mandó**: un evento interno creado desde la aplicación vieja nacería público.
 * 2. **Escribir `descripcion` y los destinatarios en cada `UPDATE`**: la primera
 *    edición desde la aplicación vieja le borraría el cuerpo y el reparto.
 */
class CalendarioGuardadoTest extends CasoDeContrato
{
    private function tokenDelPersonalDelColegio(): string
    {
        return $this->tokenDe($this->usuarioDeTipo('Profesor')->username);
    }

    /** @return array<string, mixed> */
    private function filaDe(int $id): array
    {
        return (array) DB::selectOne('SELECT * FROM calendario WHERE id = ?', [$id]);
    }

    /** @return list<array{publico: string, grupo_id: int|null}> */
    private function destinatariosDe(int $id): array
    {
        return array_values(array_map(
            fn ($f) => ['publico' => (string) $f->publico, 'grupo_id' => $f->grupo_id === null ? null : (int) $f->grupo_id],
            DB::select('SELECT publico, grupo_id FROM calendario_destinatarios
                WHERE calendario_id = ? ORDER BY publico, grupo_id', [$id])));
    }

    private function crear(string $token, array $cuerpo): int
    {
        $r = $this->withToken($token)->putJson('/api/calendario/crear-evento', $cuerpo);
        $r->assertStatus(200);

        return (int) $r->json()['evento_id'];
    }

    /**
     * **La descripción se sanea al escribir, con la lista blanca del PIAR.**
     *
     * El front ya sanea al pintar, pero eso es **un cliente de cuatro**, y la API
     * acepta lo que le manden con `curl`. La lista de aquí y la de
     * `html-rico.pipe.ts` en `app2` son la misma a propósito: si se toca una, se
     * toca la otra.
     */
    public function test_la_descripcion_se_sanea_al_crear(): void
    {
        $id = $this->crear($this->tokenDelPersonalDelColegio(), [
            'title' => 'Feria de la ciencia',
            'start' => '2026-09-22 08:00:00',
            'descripcion' => '<p>Traer <strong>bata</strong></p><img src=x onerror="alert(1)">'
                .'<a href="javascript:alert(2)">pincha</a><script>alert(3)</script>',
        ]);

        $guardada = (string) $this->filaDe($id)['descripcion'];

        $this->assertStringNotContainsString('onerror', $guardada, 'Se guardó un manejador de eventos.');
        $this->assertStringNotContainsString('<script', $guardada, 'Se guardó una etiqueta script.');
        $this->assertStringNotContainsString('javascript:', $guardada, 'Se guardó un enlace `javascript:`.');

        $this->assertStringContainsString('<strong>bata</strong>', $guardada,
            'El saneado se llevó por delante el formato que el docente escribió.');
    }

    /**
     * **Los destinatarios se guardan y se DEDUPLICAN al escribir.**
     *
     * No hay clave única en la tabla y no puede haberla: MySQL trata cada NULL
     * como distinto, así que dos filas `('alumnos', NULL)` —la misma frase,
     * «todos los alumnos», que es la que se marca con una casilla— caben las dos
     * bajo un `UNIQUE`. El único sitio donde se puede impedir es aquí.
     */
    public function test_los_destinatarios_se_guardan_deduplicados(): void
    {
        $grupo = DB::selectOne('SELECT id FROM grupos WHERE deleted_at IS NULL ORDER BY id LIMIT 1');

        $id = $this->crear($this->tokenDelPersonalDelColegio(), [
            'title' => 'Entrega de boletines',
            'start' => '2026-09-22 08:00:00',
            'destinatarios' => [
                ['publico' => 'acudientes', 'grupo_id' => null],
                ['publico' => 'acudientes', 'grupo_id' => null],
                ['publico' => 'alumnos', 'grupo_id' => (int) $grupo->id],
                ['publico' => 'alumnos', 'grupo_id' => (int) $grupo->id],
            ],
        ]);

        $this->assertSame([
            ['publico' => 'acudientes', 'grupo_id' => null],
            ['publico' => 'alumnos', 'grupo_id' => (int) $grupo->id],
        ], $this->destinatariosDe($id),
            'Los destinatarios repetidos entraron dos veces: el índice único no puede pararlos, '
            .'porque los NULL no chocan entre sí en MySQL.');
    }

    /**
     * **`solo_profes` se escribe como espejo: 1 sólo si TODO es personal.**
     *
     * No es un residuo. `calendario/this-year` la sigue leyendo la aplicación
     * vieja y `myvc_flutter` en los quince colegios: si se deja de escribir, un
     * evento «sólo personal» se ve **público** allí y no da ningún error.
     */
    public function test_solo_profes_es_el_espejo_de_los_destinatarios(): void
    {
        $token = $this->tokenDelPersonalDelColegio();

        $soloPersonal = $this->crear($token, [
            'title' => 'Consejo académico', 'start' => '2026-09-22 08:00:00',
            'destinatarios' => [['publico' => 'personal', 'grupo_id' => null]],
        ]);
        $this->olvidarControladores();

        $mezclado = $this->crear($token, [
            'title' => 'Izada de bandera', 'start' => '2026-09-22 08:00:00',
            'destinatarios' => [['publico' => 'personal', 'grupo_id' => null], ['publico' => 'alumnos', 'grupo_id' => null]],
        ]);
        $this->olvidarControladores();

        $publico = $this->crear($token, [
            'title' => 'Día de la familia', 'start' => '2026-09-22 08:00:00', 'destinatarios' => [],
        ]);

        $this->assertSame(1, (int) $this->filaDe($soloPersonal)['solo_profes'],
            'Un evento sólo para el personal nació con `solo_profes = 0`: en la aplicación vieja y en '
            .'Flutter se ve PÚBLICO, y sin ningún error.');
        $this->assertSame(0, (int) $this->filaDe($mezclado)['solo_profes'],
            'Un evento que también va a los alumnos quedó marcado como interno.');
        $this->assertSame(0, (int) $this->filaDe($publico)['solo_profes']);
    }

    /**
     * **Un cliente que no manda `destinatarios` sigue creando eventos internos.**
     *
     * Es la aplicación vieja y `myvc_flutter`. Si el espejo se calculara sobre
     * una lista vacía que nunca mandaron, su evento interno nacería público — el
     * mismo fallo que este módulo viene a arreglar, cometido por la otra puerta.
     */
    public function test_un_cliente_viejo_sigue_creando_eventos_internos(): void
    {
        $id = $this->crear($this->tokenDelPersonalDelColegio(), [
            'title' => 'Reunión de profesores',
            'start' => '2026-09-22 08:00:00',
            'solo_profes' => 1,
        ]);

        $this->assertSame(1, (int) $this->filaDe($id)['solo_profes'],
            'El `solo_profes` del cuerpo dejó de valer para un cliente que no sabe de destinatarios.');
        $this->assertSame([], $this->destinatariosDe($id));
    }

    /**
     * **Editar desde un cliente viejo NO borra la descripción ni el reparto.**
     *
     * Es la mitad cara de la compatibilidad: la aplicación vieja manda `title`,
     * `start`, `end`, `allDay` y `solo_profes`, y **nada más**. Con un `UPDATE`
     * que escriba siempre todas las columnas, la primera vez que alguien corrija
     * la hora de un evento desde allí se lleva por delante el cuerpo que escribió
     * el colegio y a quién iba dirigido. Sin error.
     */
    public function test_editar_desde_un_cliente_viejo_no_borra_lo_que_no_manda(): void
    {
        $token = $this->tokenDelPersonalDelColegio();
        $grupo = DB::selectOne('SELECT id FROM grupos WHERE deleted_at IS NULL ORDER BY id LIMIT 1');

        $id = $this->crear($token, [
            'title' => 'Feria de la ciencia',
            'start' => '2026-09-22 08:00:00',
            'descripcion' => '<p>Traer bata</p>',
            'recordatorio_minutos' => 15,
            'destinatarios' => [['publico' => 'alumnos', 'grupo_id' => (int) $grupo->id]],
        ]);
        $this->olvidarControladores();

        // Exactamente lo que manda la aplicación vieja: ni `descripcion`, ni
        // `recordatorio_minutos`, ni `destinatarios`.
        $this->withToken($token)->putJson('/api/calendario/guardar-evento', [
            'id' => $id,
            'title' => 'Feria de la ciencia (aplazada)',
            'start' => '2026-09-29 08:00:00',
            'allDay' => 0,
            'solo_profes' => 0,
        ])->assertStatus(200);

        $fila = $this->filaDe($id);

        $this->assertSame('Feria de la ciencia (aplazada)', $fila['title'], 'El cliente viejo no pudo editar.');
        $this->assertSame('<p>Traer bata</p>', $fila['descripcion'],
            'Editar desde la aplicación vieja borró la descripción que había escrito el colegio.');
        $this->assertSame(15, (int) $fila['recordatorio_minutos'],
            'Editar desde la aplicación vieja borró el recordatorio.');
        $this->assertSame([['publico' => 'alumnos', 'grupo_id' => (int) $grupo->id]], $this->destinatariosDe($id),
            'Editar desde la aplicación vieja borró el reparto: el evento pasó a ser público.');
    }

    /** Y la pantalla nueva sí puede cambiar el reparto entero, que es la otra mitad. */
    public function test_la_pantalla_nueva_reescribe_el_reparto(): void
    {
        $token = $this->tokenDelPersonalDelColegio();
        $grupo = DB::selectOne('SELECT id FROM grupos WHERE deleted_at IS NULL ORDER BY id LIMIT 1');

        $id = $this->crear($token, [
            'title' => 'Reunión', 'start' => '2026-09-22 08:00:00',
            'destinatarios' => [['publico' => 'alumnos', 'grupo_id' => (int) $grupo->id]],
        ]);
        $this->olvidarControladores();

        $this->withToken($token)->putJson('/api/calendario/guardar-evento', [
            'id' => $id, 'title' => 'Reunión', 'start' => '2026-09-22 08:00:00',
            'descripcion' => '<p>Nueva</p>',
            'destinatarios' => [['publico' => 'personal', 'grupo_id' => null]],
        ])->assertStatus(200);

        $this->assertSame([['publico' => 'personal', 'grupo_id' => null]], $this->destinatariosDe($id));
        $this->assertSame(1, (int) $this->filaDe($id)['solo_profes'],
            'El espejo de `solo_profes` no se actualizó al reescribir el reparto.');
        $this->assertSame('<p>Nueva</p>', $this->filaDe($id)['descripcion']);
    }

    /** Un destinatario con un `publico` que no existe es un 422, no una fila rara. */
    public function test_un_publico_inventado_es_un_422(): void
    {
        $token = $this->tokenDelPersonalDelColegio();

        foreach ([
            [['publico' => 'todos', 'grupo_id' => null]],
            [['grupo_id' => 3]],
            [['publico' => 'alumnos', 'grupo_id' => 'seis']],
            'alumnos',
        ] as $destinatarios) {
            $this->withToken($token)->putJson('/api/calendario/crear-evento', [
                'title' => 'X', 'start' => '2026-09-22 08:00:00', 'destinatarios' => $destinatarios,
            ])->assertStatus(422);
            $this->olvidarControladores();
        }
    }

    /** Y un recordatorio que no es un número de minutos, también. */
    public function test_un_recordatorio_imposible_es_un_422(): void
    {
        $token = $this->tokenDelPersonalDelColegio();

        foreach (['ayer', -5, 999999] as $minutos) {
            $this->withToken($token)->putJson('/api/calendario/crear-evento', [
                'title' => 'X', 'start' => '2026-09-22 08:00:00', 'recordatorio_minutos' => $minutos,
            ])->assertStatus(422);
            $this->olvidarControladores();
        }
    }

    /** Quien no es personal del colegio sigue sin poder crear nada: 403. */
    public function test_una_familia_no_crea_eventos(): void
    {
        foreach (['Alumno', 'Acudiente'] as $tipo) {
            $this->withToken($this->tokenDe($this->usuarioDeTipo($tipo)->username))
                ->putJson('/api/calendario/crear-evento', [
                    'title' => 'X', 'start' => '2026-09-22 08:00:00',
                    'destinatarios' => [['publico' => 'personal', 'grupo_id' => null]],
                ])->assertStatus(403);
            $this->olvidarControladores();
        }
    }
}
