<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

/**
 * Los compromisos académicos dejan línea en `auditoria` en cada paso del expediente:
 * abrirlo, entregarlo, las dos firmas de la familia, el veredicto del docente, cerrarlo
 * y notificar el resultado.
 *
 * Es un papel con valor de prueba —el colegio lo sostiene ante una tutela—, y sus
 * fechas no se reescriben; lo que faltaba era **quién** dio cada paso. `compromiso` es
 * la fila de `compromisos` y `compromiso_item` el renglón con su veredicto. Todas las
 * líneas llevan `alumno_id`, que es por donde las encuentra la pantalla del alumno.
 *
 * Igual que en `AuditoriaDeLasCincoFamiliasTest`, se mira la fila y no el 200:
 * `Auditoria::guardar()` se traga sus errores, así que una entidad fuera del
 * vocabulario sale 200 y sin rastro.
 */
class AuditoriaDeLosCompromisosTest extends CasoDeContrato
{
    /** Cuarto 2025, el mismo grupo que usa `CompromisosDelAlumnoTest`. */
    private const GRUPO = 98;

    private const ANIO = 8;

    #[Test]
    public function test_abrir_entregar_cerrar_y_notificar_dejan_una_linea_cada_uno(): void
    {
        $token = $this->tokenDeCoordinacion();
        $c = $this->crearUnCompromiso($token);

        $alta = $this->ultimaLinea('compromiso', $c['id']);
        $this->assertSame('crear', $alta->accion);
        $this->assertSame($c['alumno_id'], (int) $alta->alumno_id);
        $this->assertSame(self::ANIO, (int) $alta->year_id);
        $this->assertNull($alta->valor_anterior);
        $nuevo = json_decode($alta->valor_nuevo, true);
        $this->assertSame('borrador', $nuevo['estado']);
        $this->assertSame($c['matricula_id'], (int) $nuevo['matricula_id']);

        $this->withToken($token)->putJson("/api/compromisos/{$c['id']}/entregar", ['canal' => 'papel'])
            ->assertStatus(200);

        $entrega = $this->ultimaLinea('compromiso', $c['id']);
        $this->assertSame('editar', $entrega->accion);
        $this->assertSame($c['alumno_id'], (int) $entrega->alumno_id);
        $this->assertSame(['estado' => 'borrador', 'entregado_at' => null, 'entrega_canal' => null],
            json_decode($entrega->valor_anterior, true));
        $despues = json_decode($entrega->valor_nuevo, true);
        $this->assertSame('entregado', $despues['estado']);
        $this->assertSame('papel', $despues['entrega_canal']);
        $this->assertNotNull($despues['entregado_at']);

        $this->withToken($token)->putJson("/api/compromisos/{$c['id']}/cerrar", [])->assertStatus(200);

        $cierre = $this->ultimaLinea('compromiso', $c['id']);
        $this->assertSame($c['alumno_id'], (int) $cierre->alumno_id);
        $this->assertSame('entregado', json_decode($cierre->valor_anterior, true)['estado']);
        $this->assertNull(json_decode($cierre->valor_anterior, true)['cerrado_at']);
        $this->assertSame('cerrado', json_decode($cierre->valor_nuevo, true)['estado']);
        $this->assertNotNull(json_decode($cierre->valor_nuevo, true)['cerrado_por']);

        $this->withToken($token)->putJson("/api/compromisos/{$c['id']}/entregar-resultado", ['canal' => 'papel'])
            ->assertStatus(200);

        $notificado = $this->ultimaLinea('compromiso', $c['id']);
        $this->assertSame($c['alumno_id'], (int) $notificado->alumno_id);
        $this->assertNull(json_decode($notificado->valor_anterior, true)['reclamacion_vence']);
        $this->assertSame('notificado', json_decode($notificado->valor_nuevo, true)['estado']);
        $this->assertNotNull(json_decode($notificado->valor_nuevo, true)['reclamacion_vence']);

        $this->assertCount(4, $this->lineasDe('compromiso', $c['id']),
            'Crear, entregar, cerrar y notificar son cuatro pasos y tienen que ser cuatro líneas.');
    }

    /**
     * Las dos firmas de la familia. El actor es el acudiente: `actor_tipo` `Acudiente` y
     * `actor_persona_id` su `acudientes.id`, que es lo mismo que guarda `acuse_por`.
     */
    #[Test]
    public function test_las_dos_firmas_del_acudiente_llevan_al_acudiente_como_actor(): void
    {
        $token = $this->tokenDeCoordinacion();
        $c = $this->crearUnCompromiso($token);
        $this->withToken($token)->putJson("/api/compromisos/{$c['id']}/entregar", ['canal' => 'papel'])
            ->assertStatus(200);

        $acudiente = $this->acudienteDe($c['alumno_id']);
        $tokenAcudiente = $this->tokenDe($acudiente->username);

        $this->withToken($tokenAcudiente)->putJson("/api/compromisos/{$c['id']}/acuse", [])->assertStatus(200);

        $acuse = $this->ultimaLinea('compromiso', $c['id']);
        $this->assertSame('Acudiente', $acuse->actor_tipo);
        $this->assertSame((int) $acudiente->acudiente_id, (int) $acuse->actor_persona_id);
        $this->assertSame($c['alumno_id'], (int) $acuse->alumno_id);
        $this->assertSame(['acuse_at' => null, 'acuse_por' => null, 'acuse_canal' => null],
            json_decode($acuse->valor_anterior, true));
        $this->assertSame((int) $acudiente->acudiente_id, (int) json_decode($acuse->valor_nuevo, true)['acuse_por']);

        // La segunda pulsación contesta «ya estaba» y no escribe: no deja línea.
        $antes = count($this->lineasDe('compromiso', $c['id']));
        $this->withToken($tokenAcudiente)->putJson("/api/compromisos/{$c['id']}/acuse", [])->assertStatus(200);
        $this->assertCount($antes, $this->lineasDe('compromiso', $c['id']));

        $this->withToken($token)->putJson("/api/compromisos/{$c['id']}/cerrar", [])->assertStatus(200);
        $this->withToken($token)->putJson("/api/compromisos/{$c['id']}/entregar-resultado", ['canal' => 'papel'])
            ->assertStatus(200);

        $this->withToken($tokenAcudiente)
            ->putJson("/api/compromisos/{$c['id']}/acuse-resultado", ['tipo' => 'reclama', 'texto' => 'No asistió porque estaba enfermo.'])
            ->assertStatus(200);

        $reclamo = $this->ultimaLinea('compromiso', $c['id']);
        $this->assertSame('Acudiente', $reclamo->actor_tipo);
        $this->assertSame($c['alumno_id'], (int) $reclamo->alumno_id);
        $this->assertNull(json_decode($reclamo->valor_anterior, true)['resultado_acuse_tipo']);
        $nuevo = json_decode($reclamo->valor_nuevo, true);
        $this->assertSame('reclama', $nuevo['resultado_acuse_tipo']);
        $this->assertSame('No asistió porque estaba enfermo.', $nuevo['reclamacion_texto']);
    }

    #[Test]
    public function test_el_veredicto_del_docente_deja_linea_del_renglon(): void
    {
        $token = $this->tokenDeCoordinacion();
        $c = $this->crearUnCompromiso($token);
        $this->withToken($token)->putJson("/api/compromisos/{$c['id']}/entregar", ['canal' => 'papel'])
            ->assertStatus(200);

        $item = (int) DB::selectOne('SELECT id FROM compromiso_items WHERE compromiso_id=? ORDER BY id LIMIT 1',
            [$c['id']])->id;

        $titular = DB::selectOne('SELECT u.username, p.id AS profesor_id FROM grupos g
            INNER JOIN profesores p ON p.id = g.titular_id AND p.deleted_at IS NULL
            INNER JOIN users u ON u.id = p.user_id AND u.is_active = 1 AND u.deleted_at IS NULL
            WHERE g.id = ?', [self::GRUPO]);
        $this->assertNotNull($titular, 'El titular de Cuarto 2025 no tiene cuenta activa en el seed.');

        $this->withToken($this->tokenDe($titular->username))
            ->putJson("/api/compromisos/items/{$item}/veredicto", ['resultado' => 'nivelo', 'asistio' => true])
            ->assertStatus(200);

        $linea = $this->ultimaLinea('compromiso_item', $item);
        $this->assertSame('editar', $linea->accion);
        $this->assertSame($c['alumno_id'], (int) $linea->alumno_id);
        $this->assertSame('Profesor', $linea->actor_tipo);
        $antes = json_decode($linea->valor_anterior, true);
        $this->assertNull($antes['resultado']);
        $this->assertNull($antes['veredicto_por']);
        $despues = json_decode($linea->valor_nuevo, true);
        $this->assertSame('nivelo', $despues['resultado']);
        $this->assertSame(1, (int) $despues['asistio']);
        $this->assertSame((int) $titular->profesor_id, (int) $despues['veredicto_por']);
    }

    /* ── andamiaje ── */

    private function tokenDeCoordinacion(): string
    {
        return $this->tokenDe($this->usuarioDeTipo('Usuario')->username);
    }

    /** @return array{id: int, alumno_id: int, matricula_id: int} */
    private function crearUnCompromiso(string $token): array
    {
        $cuerpo = ['year_id' => self::ANIO, 'periodo' => 2, 'regla' => 'asignatura', 'corte' => 3];

        $r = $this->withToken($token)->putJson('/api/compromisos/candidatos', $cuerpo + ['grupo_id' => self::GRUPO]);
        $r->assertStatus(200);
        $candidato = $r->json('candidatos.0');
        $this->assertNotNull($candidato, 'El seed no da candidatos en Cuarto 2025 con corte 3.');

        $r = $this->withToken($token)->postJson('/api/compromisos', $cuerpo + ['matricula_ids' => [$candidato['matricula_id']]]);
        $r->assertStatus(200);
        $this->assertSame(1, $r->json('creados'), 'El compromiso no se creó: '.json_encode($r->json('omitidos')));

        return [
            'id' => (int) $r->json('ids.0'),
            'alumno_id' => (int) $candidato['alumno_id'],
            'matricula_id' => (int) $candidato['matricula_id'],
        ];
    }

    private function acudienteDe(int $alumno_id): object
    {
        $fila = DB::selectOne('SELECT ac.id AS acudiente_id, u.username
            FROM parentescos pa
            INNER JOIN acudientes ac ON ac.id = pa.acudiente_id AND ac.deleted_at IS NULL
            INNER JOIN users u ON u.id = ac.user_id AND u.is_active = 1 AND u.deleted_at IS NULL
            WHERE pa.alumno_id = ? AND pa.deleted_at IS NULL
            ORDER BY ac.id LIMIT 1', [$alumno_id]);

        $this->assertNotNull($fila, "El alumno {$alumno_id} no tiene acudiente con cuenta en el seed.");

        return $fila;
    }

    /** @return array<int, object> */
    private function lineasDe(string $entidad, int $id): array
    {
        return DB::select('SELECT * FROM auditoria WHERE entidad = ? AND entidad_id = ? ORDER BY id DESC',
            [$entidad, $id]);
    }

    private function ultimaLinea(string $entidad, int $id): object
    {
        $lineas = $this->lineasDe($entidad, $id);

        $this->assertNotEmpty($lineas, "No hay ninguna línea de `{$entidad}` {$id} en auditoria. "
            .'Si la entidad no está en Auditoria::ENTIDADES, guardar() la descarta y lo deja en el log.');

        return $lineas[0];
    }
}
