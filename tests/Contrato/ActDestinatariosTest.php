<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * Actividades nuevas — **a quién le llega** (contrato §2.7, `POST act/conteo` y la bandeja).
 *
 * `Services\Act\Destinatarios` es el servicio único: el conteo en vivo, la bandeja, responder,
 * «faltan» y los avisos resuelven con él. Por eso aquí se mira el conteo **y** lo que ve quien
 * recibe: si los dos se separaran, el docente leería «32» y a un alumno no le saldría.
 *
 * Los recuentos esperados se sacan del seed con la regla del contrato escrita aparte (matrículas
 * `MATR`/`ASIS` vivas, alumno con cuenta), no con el servicio: si se pidieran al servicio, el test
 * comprobaría que el servicio coincide consigo mismo.
 */
class ActDestinatariosTest extends CasoDeActividades
{
    /** Alumnos con cuenta de un grupo, según el contrato (§2.7). */
    private function alumnosConCuenta(int $grupoId): int
    {
        return (int) DB::selectOne(
            "SELECT COUNT(DISTINCT al.id) AS n FROM matriculas m
              INNER JOIN alumnos al ON al.id = m.alumno_id AND al.deleted_at IS NULL
              INNER JOIN users u ON u.id = al.user_id AND u.deleted_at IS NULL
              WHERE m.grupo_id = ? AND m.estado IN ('MATR', 'ASIS') AND m.deleted_at IS NULL",
            [$grupoId]
        )->n;
    }

    private function conteo(array $cuerpo): array
    {
        return $this->como('titular')->postJson('/api/act/conteo', $cuerpo)->assertStatus(200)->json();
    }

    public function test_clase_y_grupo_cuentan_los_alumnos_con_cuenta_del_grupo(): void
    {
        $e = $this->escena();
        $esperados = $this->alumnosConCuenta($e->grupo_id);

        $clase = $this->conteo(['modo' => 'cuestionario', 'alcance' => 'clase', 'responden' => 'alumnos',
            'destinatarios' => [['asignatura_id' => $e->clase]]]);
        $grupo = $this->conteo(['modo' => 'encuesta', 'alcance' => 'grupo', 'responden' => 'alumnos',
            'destinatarios' => [['grupo_id' => $e->grupo_id]]]);

        foreach ([$clase, $grupo] as $c) {
            $this->assertSame($esperados, $c['alumnos']);
            $this->assertSame($esperados, $c['total']);
            $this->assertSame(0, $c['acudientes']);
            $this->assertSame([['grupo_id' => (int) $e->grupo_id, 'n' => $esperados, 'es_titularia' => true]],
                array_map(fn ($g) => array_intersect_key($g, array_flip(['grupo_id', 'n', 'es_titularia'])), $c['grupos']));
        }
    }

    public function test_conteo_no_escribe_nada(): void
    {
        $antes = [DB::table('ws_actividades')->count(), DB::table('destinatarios')->count()];

        $this->conteo(['modo' => 'encuesta', 'alcance' => 'colegio', 'responden' => 'ambos']);

        $this->assertSame($antes, [DB::table('ws_actividades')->count(), DB::table('destinatarios')->count()]);
    }

    public function test_varios_grupos_y_grados_y_todo_el_colegio(): void
    {
        $e = $this->escena();
        [$relleno] = $this->gruposDeRelleno(1);

        $varios = $this->conteo(['modo' => 'encuesta', 'alcance' => 'grupos', 'responden' => 'alumnos',
            'destinatarios' => [['grupo_id' => $e->grupo_id], ['grupo_id' => $relleno]]]);
        $this->assertEqualsCanonicalizing([$e->grupo_id, $relleno], array_column($varios['grupos'], 'grupo_id'));

        // Un grado se expande a sus grupos del año.
        $grado = $this->conteo(['modo' => 'encuesta', 'alcance' => 'grupos', 'responden' => 'alumnos',
            'destinatarios' => [['grado_id' => $e->grado_id]]]);
        $delGrado = DB::table('grupos')->where('year_id', $e->year_id)->where('grado_id', $e->grado_id)->whereNull('deleted_at')->pluck('id')->all();
        $this->assertEqualsCanonicalizing($delGrado, array_column($grado['grupos'], 'grupo_id'));

        // Todo el colegio: todos los grupos del año, y los alumnos de todos.
        $colegio = $this->conteo(['modo' => 'encuesta', 'alcance' => 'colegio', 'responden' => 'alumnos']);
        $delAnio = DB::table('grupos')->where('year_id', $e->year_id)->whereNull('deleted_at')->pluck('id')->all();
        $this->assertEqualsCanonicalizing($delAnio, array_column($colegio['grupos'], 'grupo_id'));
        $this->assertSame(array_sum(array_map(fn ($g) => $this->alumnosConCuenta($g), $delAnio)), $colegio['alumnos']);
    }

    public function test_al_personal(): void
    {
        $docentes = (int) DB::selectOne("SELECT COUNT(*) AS n FROM users WHERE tipo = 'Profesor' AND is_active = 1 AND deleted_at IS NULL")->n;
        $personal = (int) DB::selectOne("SELECT COUNT(*) AS n FROM users WHERE tipo IN ('Profesor', 'Usuario') AND is_active = 1 AND deleted_at IS NULL")->n;

        $this->assertSame($docentes, $this->conteo(['modo' => 'encuesta', 'alcance' => 'personal', 'responden' => 'personal',
            'destinatarios' => [['publico' => 'docentes']]])['personal']);
        $this->assertSame($personal, $this->conteo(['modo' => 'encuesta', 'alcance' => 'personal', 'responden' => 'personal',
            'destinatarios' => [['publico' => 'personal']]])['personal']);

        $uno = $this->conteo(['modo' => 'encuesta', 'alcance' => 'personal', 'responden' => 'personal',
            'destinatarios' => [['user_id' => $this->escena()->llano->id]]]);
        $this->assertSame(1, $uno['total']);

        // Los directivos: el superusuario sí; el personal llano no.
        $act = $this->crear($this->encuesta(['alcance' => 'personal', 'responden' => 'personal', 'grupo_id' => null,
            'destinatarios' => [['publico' => 'directivos']]]));
        $this->unica($act['id'], null);
        $this->publicar($act['id']);

        $this->assertContains($act['id'], array_column($this->como('directivo')->getJson('/api/act/bandeja?vista=responder')->json(), 'id'));
        $this->assertNotContains($act['id'], array_column($this->como('llano')->getJson('/api/act/bandeja?vista=responder')->json(), 'id'));
    }

    /** Un alumno sin cuenta cuenta como `sin_cuenta`: está en el grupo, pero no puede responder. */
    public function test_el_alumno_sin_cuenta_va_aparte(): void
    {
        $e = $this->escena();
        $esperados = $this->alumnosConCuenta($e->grupo_id);
        DB::table('alumnos')->where('id', $this->alumno(0)->alumno_id)->update(['user_id' => null]);

        $c = $this->conteo(['modo' => 'encuesta', 'alcance' => 'grupo', 'responden' => 'alumnos', 'destinatarios' => [['grupo_id' => $e->grupo_id]]]);

        $this->assertSame($esperados - 1, $c['alumnos']);
        $this->assertSame(1, $c['sin_cuenta']);
    }

    /** `MATR` y `ASIS` están en clase; un retirado no. */
    public function test_asis_cuenta_y_reti_no(): void
    {
        $e = $this->escena();
        $cuerpo = ['modo' => 'encuesta', 'alcance' => 'grupo', 'responden' => 'alumnos', 'destinatarios' => [['grupo_id' => $e->grupo_id]]];
        $antes = $this->conteo($cuerpo)['alumnos'];

        DB::table('matriculas')->where('grupo_id', $e->grupo_id)->where('alumno_id', $this->alumno(0)->alumno_id)->update(['estado' => 'ASIS']);
        $this->assertSame($antes, $this->conteo($cuerpo)['alumnos'], 'Un alumno ASIS dejó de contar (el seed no trae ninguno: 03-tests.md).');

        DB::table('matriculas')->where('grupo_id', $e->grupo_id)->where('alumno_id', $this->alumno(1)->alumno_id)->update(['estado' => 'RETI']);
        $this->assertSame($antes - 1, $this->conteo($cuerpo)['alumnos']);
    }

    /**
     * El acudiente oficial (`is_acudiente = 1`): una vez por hijo o una sola vez. Se le da un segundo
     * hijo en el grupo para que las dos cuentas se distingan.
     */
    public function test_acudiente_oficial_una_vez_por_hijo_o_una_sola_vez(): void
    {
        $e = $this->escena();
        $segundo = collect($e->alumnos)->first(fn ($a) => $a->alumno_id !== $e->acudiente->alumno_id);
        DB::table('parentescos')->insert(['acudiente_id' => $e->acudiente->acudiente_id, 'alumno_id' => $segundo->alumno_id,
            'parentesco' => 'Padre', 'created_at' => now(), 'updated_at' => now()]);

        $cuerpo = ['modo' => 'encuesta', 'alcance' => 'grupo', 'responden' => 'acudientes', 'destinatarios' => [['grupo_id' => $e->grupo_id]]];
        $porHijo = $this->conteo($cuerpo + ['acudiente_por_hijo' => true])['acudientes'];
        $unaVez = $this->conteo($cuerpo + ['acudiente_por_hijo' => false])['acudientes'];
        $this->assertGreaterThanOrEqual($unaVez + 1, $porHijo, 'Con dos hijos, «una vez por hijo» cuenta al acudiente dos veces.');

        // Lo que ve él: dos filas, una por hijo, y cada una responde por su hijo.
        [$id, $p] = $this->encuestaPublicada(['responden' => 'acudientes', 'acudiente_por_hijo' => true]);
        $bandeja = array_values(array_filter($this->como('acudiente')->getJson('/api/act/bandeja?vista=responder')->json(), fn ($f) => $f['id'] === $id));
        $this->assertEqualsCanonicalizing([$e->acudiente->alumno_id, $segundo->alumno_id], array_column(array_column($bandeja, 'por_alumno'), 'alumno_id'));

        $sinHijo = $this->como('acudiente')->postJson("/api/act/{$id}/enviar", ['respuestas' => [$this->marcar($p, 0)]]);
        $sinHijo->assertStatus(422);
        $this->assertEqualsCanonicalizing([$e->acudiente->alumno_id, $segundo->alumno_id], $sinHijo->json('hijos'));

        foreach ([$e->acudiente->alumno_id, $segundo->alumno_id] as $hijo) {
            $this->como('acudiente')->postJson("/api/act/{$id}/enviar", ['alumno_id' => $hijo, 'respuestas' => [$this->marcar($p, 0)]])->assertStatus(200);
        }

        $this->assertSame(2, DB::table('ws_actividades_resueltas')->where('actividad_id', $id)->where('user_id', $e->acudiente->user_id)->where('terminado', 1)->count());

        // Una sola vez: una fila, sin hijo.
        [$otra] = $this->encuestaPublicada(['responden' => 'acudientes', 'acudiente_por_hijo' => false, 'titulo' => 'Una vez']);
        $filas = array_values(array_filter($this->como('acudiente')->getJson('/api/act/bandeja?vista=responder')->json(), fn ($f) => $f['id'] === $otra));
        $this->assertCount(1, $filas);
        $this->assertNull($filas[0]['por_alumno']);
    }

    /** Un acudiente que no es el oficial (`is_acudiente = 0`) no recibe. */
    public function test_el_acudiente_no_oficial_no_cuenta(): void
    {
        $e = $this->escena();
        $cuerpo = ['modo' => 'encuesta', 'alcance' => 'grupo', 'responden' => 'acudientes', 'acudiente_por_hijo' => false,
            'destinatarios' => [['grupo_id' => $e->grupo_id]]];
        $antes = $this->conteo($cuerpo)['acudientes'];

        DB::table('acudientes')->where('id', $e->acudiente->acudiente_id)->update(['is_acudiente' => 0]);

        $this->assertSame($antes - 1, $this->conteo($cuerpo)['acudientes']);
    }

    /** La bandeja de quien responde: abiertas y cerradas; nunca borrador, por aprobar ni programada. */
    public function test_la_bandeja_de_quien_responde(): void
    {
        [$abierta] = $this->encuestaPublicada();

        $borrador = $this->crear($this->encuesta(['titulo' => 'Borrador']));
        $this->unica($borrador['id'], null);

        $programada = $this->crear($this->encuesta(['titulo' => 'Programada', 'publica_at' => '2099-01-01 08:00']));
        $this->unica($programada['id'], null);
        $this->publicar($programada['id']);

        [$cerrada] = $this->encuestaPublicada(['titulo' => 'Cerrada']);
        DB::table('ws_actividades')->where('id', $cerrada)->update(['cierra_at' => '2020-01-01 00:00:00']);

        $filas = $this->como($this->alumno())->getJson('/api/act/bandeja?vista=responder')->assertStatus(200)->json();
        $ids = array_column($filas, 'id');

        $this->assertContains($abierta, $ids);
        $this->assertContains($cerrada, $ids);
        $this->assertNotContains($borrador['id'], $ids);
        $this->assertNotContains($programada['id'], $ids);

        $porId = array_column($filas, null, 'id');
        $this->assertSame('pendiente', $porId[$abierta]['mi_estado']);
        $this->assertSame('vencida', $porId[$cerrada]['mi_estado']);
        $this->assertNull($porId[$abierta]['respondieron_n'], 'Quien responde no ve cuántos respondieron.');
    }
}
