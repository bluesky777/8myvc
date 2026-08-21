<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * El observador de convivencia: los procesos disciplinarios de un alumno.
 *
 * `DisciplinaController` estaba en 1 de 7 rutas comprobadas, y es de lo más
 * sensible que guarda el sistema: la descripción de una falta de un menor, sus
 * testigos y su descargo. Las siete llevan `auth.personal` — lo que no había era
 * nadie mirando qué devuelven ni qué escriben.
 */
class DisciplinaTest extends CasoDeContrato
{
    private function tokenDelPersonal(): string
    {
        return $this->tokenDe($this->usuarioDeTipo('Usuario')->username);
    }

    /** Un alumno matriculado, con su año y su periodo, que es lo que pide el alta. */
    private function alumnoConContexto(): object
    {
        $fila = DB::selectOne('SELECT a.id AS alumno_id, g.year_id, p.id AS periodo_id
            FROM alumnos a
            INNER JOIN matriculas m ON m.alumno_id = a.id AND m.deleted_at IS NULL
                AND m.estado IN ("MATR","ASIS","PREM")
            INNER JOIN grupos g ON g.id = m.grupo_id AND g.deleted_at IS NULL
            INNER JOIN periodos p ON p.year_id = g.year_id AND p.deleted_at IS NULL
            WHERE a.deleted_at IS NULL ORDER BY a.id LIMIT 1');

        $this->assertNotNull($fila, 'El seed no tiene ningún alumno matriculado con periodo.');

        return $fila;
    }

    private function cuerpoDeProceso(object $ctx): array
    {
        return [
            'year_id' => $ctx->year_id,
            'alumno_id' => $ctx->alumno_id,
            'periodo_id' => $ctx->periodo_id,
            'descripcion' => 'Llegó tarde tres veces en la semana.',
            'testigos' => 'El coordinador de convivencia',
            'descargo' => 'Dice que el bus se retrasó.',
            'tipo_situacion' => 1,
            'fecha_hora_aprox' => '2026-08-20 07:15:00',
            'deriva_de_tardanzas' => 0,
        ];
    }

    /** El proceso se abre y vuelve dentro de la ficha del alumno. */
    public function test_se_abre_un_proceso_disciplinario(): void
    {
        $token = $this->tokenDelPersonal();
        $ctx = $this->alumnoConContexto();

        $antes = DB::table('dis_procesos')->count();

        $r = $this->withToken($token)->postJson('/api/disciplina/store', $this->cuerpoDeProceso($ctx));

        // 200 y no 201: la respuesta es la ficha del alumno, un array armado a
        // mano, no un modelo Eloquent recién creado — que es lo que hace que
        // Laravel ponga el 201. Ver el contraste en `opciones/add-opcion` (§27).
        $r->assertStatus(200);
        $this->assertSame($antes + 1, DB::table('dis_procesos')->count());

        $fila = DB::table('dis_procesos')->orderByDesc('id')->first();
        $this->assertSame((int) $ctx->alumno_id, (int) $fila->alumno_id);
        $this->assertSame('Llegó tarde tres veces en la semana.', $fila->descripcion);
        $this->assertNotNull($fila->added_by, 'El proceso debe quedar firmado por quien lo abrió.');

        // La respuesta es la ficha del alumno, no el proceso: es lo que repinta
        // la pantalla del observador de una vez.
        $this->assertSame((int) $ctx->alumno_id, $r->json('alumno_id') ?? $r->json('id'));
    }

    /** Y con ordinales dentro, que son los artículos del manual de convivencia. */
    public function test_el_proceso_se_abre_con_sus_ordinales(): void
    {
        $token = $this->tokenDelPersonal();
        $ctx = $this->alumnoConContexto();

        $ordinales = DB::table('dis_ordinales')->orderBy('id')->limit(2)->pluck('id')->all();
        $this->assertCount(2, $ordinales, 'El seed no tiene ordinales de disciplina.');

        $this->withToken($token)->postJson('/api/disciplina/store',
            $this->cuerpoDeProceso($ctx) + [
                'selected_ordinales' => [['id' => $ordinales[0]], ['id' => $ordinales[1]]],
            ])->assertStatus(200);

        $proceso = DB::table('dis_procesos')->orderByDesc('id')->value('id');

        $this->assertSame($ordinales,
            DB::table('dis_proceso_ordinales')->where('proceso_id', $proceso)
                ->orderBy('ordinal_id')->pluck('ordinal_id')->all());
    }

    /**
     * Un ordinal se añade y se quita, y quitarlo es blando.
     *
     * Dos cosas que solo se ven ejecutándolo. El parámetro se llama **`id`** y no
     * `ordinal_id`, aunque la columna sí —las dos rutas lo leen así—. Y quitar no
     * borra la fila: la marca con `deleted_at`, y todas las consultas que las leen
     * filtran por ahí. O sea que quitar y volver a poner el mismo ordinal deja
     * **dos filas**, una marcada y otra viva; para lo que sale por pantalla da
     * igual, y queda escrito para que no sorprenda al contarlas.
     */
    public function test_se_asigna_y_se_quita_un_ordinal(): void
    {
        $token = $this->tokenDelPersonal();
        $ctx = $this->alumnoConContexto();

        $this->withToken($token)->postJson('/api/disciplina/store', $this->cuerpoDeProceso($ctx))
            ->assertStatus(200);
        $proceso = DB::table('dis_procesos')->orderByDesc('id')->value('id');
        $ordinal = DB::table('dis_ordinales')->orderBy('id')->value('id');

        $vivos = fn () => DB::table('dis_proceso_ordinales')->where('proceso_id', $proceso)
            ->where('ordinal_id', $ordinal)->whereNull('deleted_at')->count();

        $r = $this->withToken($token)->postJson('/api/disciplina/asignar-ordinal',
            ['proceso_id' => $proceso, 'id' => $ordinal]);

        $r->assertStatus(200);
        $this->assertSame(1, $vivos());
        $this->assertSame((int) $ordinal, (int) $r->json('ordinal_id'));

        $this->withToken($token)->putJson('/api/disciplina/quitar-ordinal',
            ['proceso_id' => $proceso, 'id' => $ordinal])->assertStatus(200);

        $this->assertSame(0, $vivos(), 'Quitar el ordinal debe dejarlo fuera de lo que se lee.');
        $this->assertSame(1, DB::table('dis_proceso_ordinales')->where('proceso_id', $proceso)
            ->where('ordinal_id', $ordinal)->count(),
            'Quitar es blando: la fila se queda marcada.');
    }

    /** El borrado es blando: la falta queda con su fecha y con quién la borró. */
    public function test_borrar_un_proceso_lo_deja_marcado_y_firmado(): void
    {
        $token = $this->tokenDelPersonal();
        $ctx = $this->alumnoConContexto();

        $this->withToken($token)->postJson('/api/disciplina/store', $this->cuerpoDeProceso($ctx))
            ->assertStatus(200);
        $proceso = DB::table('dis_procesos')->orderByDesc('id')->value('id');

        $this->withToken($token)->putJson('/api/disciplina/destroy',
            ['proceso_id' => $proceso, 'alumno_id' => $ctx->alumno_id])->assertStatus(200);

        $fila = DB::table('dis_procesos')->where('id', $proceso)->first();
        $this->assertNotNull($fila, 'El borrado de un proceso es blando: la fila se queda.');
        $this->assertNotNull($fila->deleted_at);
        $this->assertNotNull($fila->deleted_by, 'Sin deleted_by no se sabe quién borró la falta.');
    }

    /** Y una falta puede derivar en otra, que es lo que enlaza `become_id`. */
    public function test_una_falta_deriva_en_otra(): void
    {
        $token = $this->tokenDelPersonal();
        $ctx = $this->alumnoConContexto();

        $this->withToken($token)->postJson('/api/disciplina/store', $this->cuerpoDeProceso($ctx))
            ->assertStatus(200);
        $primera = DB::table('dis_procesos')->orderByDesc('id')->value('id');

        $this->withToken($token)->postJson('/api/disciplina/store', $this->cuerpoDeProceso($ctx))
            ->assertStatus(200);
        $segunda = DB::table('dis_procesos')->orderByDesc('id')->value('id');

        $this->withToken($token)->putJson('/api/disciplina/cambiar-situacion-derivante',
            ['id' => $primera, 'become_id' => $segunda])->assertStatus(200);

        $this->assertSame($segunda,
            (int) DB::table('dis_procesos')->where('id', $primera)->value('become_id'));
    }

    /**
     * Una familia no abre, ni borra, ni lee procesos disciplinarios.
     *
     * Es lo más sensible que guarda el sistema junto con el PIAR: la descripción
     * de una falta de un menor, sus testigos y su descargo.
     */
    public function test_una_familia_no_toca_los_procesos_disciplinarios(): void
    {
        $ctx = $this->alumnoConContexto();
        $antes = DB::table('dis_procesos')->count();

        foreach (['Alumno', 'Acudiente'] as $tipo) {
            $token = $this->tokenDe($this->usuarioDeTipo($tipo)->username);

            $this->withToken($token)->postJson('/api/disciplina/store', $this->cuerpoDeProceso($ctx))
                ->assertStatus(403);
            $this->withToken($token)->putJson('/api/disciplina/alumnos',
                ['grupo_id' => 1, 'year_id' => $ctx->year_id])->assertStatus(403);
            $this->withToken($token)->putJson('/api/disciplina/destroy',
                ['proceso_id' => 1, 'alumno_id' => $ctx->alumno_id])->assertStatus(403);
        }

        $this->assertSame($antes, DB::table('dis_procesos')->count());
    }
}
