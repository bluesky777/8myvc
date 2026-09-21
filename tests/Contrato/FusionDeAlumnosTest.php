<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * Unir dos fichas del mismo alumno.
 *
 * **Es la operación más destructiva que tiene el sistema después del borrado físico**, y la
 * única que mueve un expediente entero de una fila a otra. Lo que se fija aquí es lo que no
 * puede fallar nunca:
 *
 *   1. que `revisar` **no escriba**;
 *   2. que **no se pierda una sola fila** — lo que sale de una ficha entra en la otra;
 *   3. que el origen quede en la **papelera** y no borrado de verdad;
 *   4. que las notas que chocan se resuelvan **como diga la pantalla**, no como quiera el motor;
 *   5. y que el descubrimiento de tablas sea el de la base, para que una tabla nueva no se
 *      quede fuera en silencio.
 *
 * Contexto medido (docker, 21 sep 2026): 27 documentos repetidos y 63 nombres repetidos en un
 * colegio; el par real movió **803 filas** en 8 tablas.
 */
class FusionDeAlumnosTest extends CasoDeContrato
{
    private const REVISAR = '/api/alumnos/revisar-fusion';

    private const FUSIONAR = '/api/alumnos/fusionar';

    private function token(): string
    {
        $u = DB::selectOne('SELECT u.username FROM users u
            INNER JOIN periodos p ON p.id = u.periodo_id
            WHERE u.tipo = "Usuario" AND u.is_superuser = 1 AND u.is_active = 1
              AND u.deleted_at IS NULL ORDER BY u.id LIMIT 1');

        $this->assertNotNull($u, 'El seed no tiene ningún superusuario con periodo.');

        return $this->tokenDe($u->username);
    }

    /** @return array{0: int, 1: int} dos alumnos vivos y distintos, con notas. */
    private function dosAlumnos(): array
    {
        $filas = DB::select('SELECT a.id FROM alumnos a
            INNER JOIN notas_finales nf ON nf.alumno_id = a.id
            WHERE a.deleted_at IS NULL
            GROUP BY a.id ORDER BY COUNT(*) DESC LIMIT 2');

        $this->assertCount(2, $filas, 'El seed no tiene dos alumnos con notas.');

        return [(int) $filas[0]->id, (int) $filas[1]->id];
    }

    /** REVISAR NO ESCRIBE. Es lo que la pantalla llama al abrir el diálogo. */
    public function test_revisar_no_mueve_nada(): void
    {
        [$destino, $origen] = $this->dosAlumnos();

        $antes = DB::table('notas_finales')->orderBy('id')->pluck('alumno_id', 'id')->all();

        $r = $this->withToken($this->token())->putJson(self::REVISAR, [
            'origen_id' => $origen, 'destino_id' => $destino,
        ]);

        $r->assertStatus(200)->assertJsonStructure([
            'origen', 'destino', 'mueve', 'filas_totales', 'choques', 'matriculas', 'cuenta_de_acceso',
        ]);

        $this->assertSame($antes, DB::table('notas_finales')->orderBy('id')->pluck('alumno_id', 'id')->all(),
            'La ruta que sólo mira ha movido notas.');
        $this->assertNull(DB::table('alumnos')->where('id', $origen)->value('deleted_at'));
    }

    /**
     * NO SE PIERDE NI UNA FILA, y el origen acaba en la papelera.
     *
     * Es la comprobación que sostiene todo lo demás: lo que tenía cada ficha, sumado, tiene que
     * seguir estando en la que queda. Si el motor se dejara una tabla fuera, aquí se vería.
     */
    public function test_todo_lo_del_origen_acaba_en_el_destino(): void
    {
        [$destino, $origen] = $this->dosAlumnos();

        $notasAntes = DB::table('notas_finales')->where('alumno_id', $origen)->count()
                     + DB::table('notas_finales')->where('alumno_id', $destino)->count();
        $matriAntes = DB::table('matriculas')->where('alumno_id', $origen)->count()
                     + DB::table('matriculas')->where('alumno_id', $destino)->count();

        $revision = $this->withToken($this->token())->putJson(self::REVISAR, [
            'origen_id' => $origen, 'destino_id' => $destino,
        ])->json();

        $r = $this->withToken($this->token())->putJson(self::FUSIONAR, [
            'origen_id' => $origen, 'destino_id' => $destino,
        ]);

        $r->assertStatus(200);
        $this->assertGreaterThan(0, $r->json('movidas'));

        // Los choques resueltos se borran de una de las dos, así que la suma baja justo en eso.
        $choques = count($revision['choques']['notas_finales'] ?? []);

        $this->assertSame($notasAntes - $choques,
            DB::table('notas_finales')->where('alumno_id', $destino)->count(),
            'Se perdieron o se duplicaron definitivas al unir.');
        $this->assertSame(0, DB::table('notas_finales')->where('alumno_id', $origen)->count());

        $this->assertSame($matriAntes, DB::table('matriculas')->where('alumno_id', $destino)->count());

        // A la PAPELERA, no borrado: si la unión salió mal, la fila sigue ahí para mirarla.
        $ficha = DB::table('alumnos')->where('id', $origen)->first();
        $this->assertNotNull($ficha, 'La ficha se borró físicamente en vez de ir a la papelera.');
        $this->assertNotNull($ficha->deleted_at);
        $this->assertNotNull($ficha->deleted_by, 'No queda constancia de quién la unió.');
    }

    /**
     * EL CHOQUE SE RESUELVE COMO DIGA LA PANTALLA.
     *
     * Se fabrica: se copia una definitiva del destino al origen, cambiándole la nota. Luego se
     * pide que gane la del origen y se comprueba que la que queda es ésa. Sin esto, el motor
     * podría quedarse siempre con la del destino y nadie lo notaría — las dos notas existen y
     * una de las dos siempre parece correcta.
     */
    public function test_la_nota_que_gana_es_la_que_se_elige(): void
    {
        [$destino, $origen] = $this->dosAlumnos();

        $suya = DB::table('notas_finales')->where('alumno_id', $destino)->first();
        $this->assertNotNull($suya, 'El seed no tiene definitivas del alumno elegido.');

        DB::table('notas_finales')->where('alumno_id', $origen)
            ->where('asignatura_id', $suya->asignatura_id)->where('periodo_id', $suya->periodo_id)->delete();

        DB::table('notas_finales')->insert([
            'alumno_id' => $origen,
            'asignatura_id' => $suya->asignatura_id,
            'periodo_id' => $suya->periodo_id,
            'periodo' => $suya->periodo,
            'nota' => 1.23,
        ]);

        $clave = $suya->asignatura_id.'_'.$suya->periodo_id;

        $revision = $this->withToken($this->token())->putJson(self::REVISAR, [
            'origen_id' => $origen, 'destino_id' => $destino,
        ])->json();

        $this->assertContains($clave, array_column($revision['choques']['notas_finales'], 'clave'),
            'El choque fabricado no aparece en la revisión.');

        $this->withToken($this->token())->putJson(self::FUSIONAR, [
            'origen_id' => $origen,
            'destino_id' => $destino,
            'decisiones' => ['notas_finales' => [$clave => 'origen']],
        ])->assertStatus(200);

        $queda = DB::table('notas_finales')->where('alumno_id', $destino)
            ->where('asignatura_id', $suya->asignatura_id)->where('periodo_id', $suya->periodo_id)->get();

        $this->assertCount(1, $queda, 'Quedaron dos definitivas para la misma asignatura y periodo.');
        $this->assertEquals(1.23, (float) $queda[0]->nota, 'Ganó la nota que no se eligió.');
    }

    /** Y sin decisión gana la del destino, que es el superviviente. */
    public function test_sin_decision_gana_la_del_destino(): void
    {
        [$destino, $origen] = $this->dosAlumnos();

        $suya = DB::table('notas_finales')->where('alumno_id', $destino)->first();

        DB::table('notas_finales')->where('alumno_id', $origen)
            ->where('asignatura_id', $suya->asignatura_id)->where('periodo_id', $suya->periodo_id)->delete();
        DB::table('notas_finales')->insert([
            'alumno_id' => $origen, 'asignatura_id' => $suya->asignatura_id,
            'periodo_id' => $suya->periodo_id, 'periodo' => $suya->periodo, 'nota' => 1.23,
        ]);

        $this->withToken($this->token())->putJson(self::FUSIONAR, [
            'origen_id' => $origen, 'destino_id' => $destino,
        ])->assertStatus(200);

        $queda = DB::table('notas_finales')->where('alumno_id', $destino)
            ->where('asignatura_id', $suya->asignatura_id)->where('periodo_id', $suya->periodo_id)->get();

        $this->assertCount(1, $queda);
        $this->assertEquals((float) $suya->nota, (float) $queda[0]->nota);
    }

    /**
     * LAS TABLAS SE DESCUBREN DE LA BASE, no de una lista escrita.
     *
     * Eran 19 en el dump de agosto y son 27 hoy: ocho entraron después por migraciones. Una
     * lista a mano se queda vieja **en silencio** —la tabla nueva no se mueve y sus filas quedan
     * apuntando a una ficha borrada—. Se comprueba que `mueve` no nombra nada que no exista y
     * que trae, al menos, las tres que siempre tienen datos.
     */
    public function test_lo_que_dice_que_mueve_existe_de_verdad(): void
    {
        [$destino, $origen] = $this->dosAlumnos();

        $revision = $this->withToken($this->token())->putJson(self::REVISAR, [
            'origen_id' => $origen, 'destino_id' => $destino,
        ])->json();

        $tablas = array_column($revision['mueve'], 'tabla');
        $this->assertNotEmpty($tablas);

        foreach ($tablas as $t) {
            $existe = DB::selectOne('SELECT COUNT(*) AS n FROM information_schema.TABLES
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$t]);
            $this->assertGreaterThan(0, $existe->n, "Dice que mueve `$t`, que no existe.");
        }

        $this->assertContains('notas_finales', $tablas);
        $this->assertContains('matriculas', $tablas);

        // `auditoria` NUNCA se mueve: reescribirla sería falsificar el registro de lo que pasó.
        $this->assertNotContains('auditoria', $tablas);
    }

    /** Una ficha no se fusiona consigo misma. */
    public function test_la_misma_ficha_dos_veces_es_422(): void
    {
        [$destino] = $this->dosAlumnos();

        $this->withToken($this->token())->putJson(self::FUSIONAR, [
            'origen_id' => $destino, 'destino_id' => $destino,
        ])->assertStatus(422);
    }

    /**
     * FUSIONAR EXIGE SUPERUSUARIO; revisar, sólo administrativo.
     *
     * Mirar cuáles están repetidos es trabajo de secretaría. Mover un expediente entero y mandar
     * la ficha a la papelera no se deshace solo, y por eso está un escalón por encima.
     */
    public function test_un_profesor_no_puede_ni_mirar_ni_unir(): void
    {
        [$destino, $origen] = $this->dosAlumnos();
        $token = $this->tokenDe($this->usuarioDeTipo('Profesor')->username);

        foreach ([self::REVISAR, self::FUSIONAR, '/api/alumnos/duplicados'] as $ruta) {
            $this->withToken($token)->putJson($ruta, ['origen_id' => $origen, 'destino_id' => $destino])
                ->assertStatus(403);
        }

        $this->assertNull(DB::table('alumnos')->where('id', $origen)->value('deleted_at'));
    }
}
