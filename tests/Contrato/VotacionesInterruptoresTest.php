<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * §101 — Los conmutadores de una votación: seis del mismo molde, tres respuestas.
 *
 * `votaciones/set-votan-profes` era la que faltaba de la familia, y leerla sola no
 * dice nada: son seis métodos idénticos de cuatro líneas. Lo que sí dice algo es
 * ponerlos en una tabla, porque **el molde se copió seis veces y el valor por
 * defecto no se copió con él**.
 *
 * `Request::input('x', DEFECTO)` decide qué pasa **cuando el campo no viaja** — un
 * front que manda `{id}` a secas, un formulario a medio rellenar, una petición que
 * pierde un campo por el camino. Y la misma columna tiene tres comportamientos
 * distintos en el mismo fichero:
 *
 * | Columna | al crear (`postStore`) | el conmutador suelto | `votaciones/update` |
 * |---|---|---|---|
 * | `votan_profes` | `true` | **`true`** — se enciende | conserva el valor |
 * | `votan_acudientes` | `true` | **`true`** — se enciende | conserva el valor |
 * | `locked` | `false` | **`true`** — se cierra | conserva el valor |
 * | `actual` | `false` | **`true`** — se hace la actual | conserva el valor |
 * | `in_action` | `false` | `false` | conserva el valor |
 * | `can_see_results` | — | `false` | — |
 *
 * **Dos columnas tienen el defecto al revés entre crear y conmutar.** Crear una
 * votación sin `locked` la deja abierta; conmutarla sin `locked` la cierra. Eso ya
 * estaba fijado por `VotacionesTest::test_sin_el_campo_el_candado_se_cierra_solo`,
 * y **la de este lote es su gemela con el signo cambiado**: sin el campo,
 * `votan_profes` **se enciende**, o sea que abre el voto a los profesores en vez de
 * cerrarlo.
 *
 * Y `votaciones/update` —también de este lote— hace lo tercero: `Request::input('x',
 * $votacion->x)`, que **conserva** lo que no viaja. Es la respuesta contraria a la
 * de `actividades/guardar`, que con un cuerpo parcial deja el examen en blanco
 * (13-actividades §1). Tres endpoints, tres criterios, ninguno escrito en ninguna
 * parte hasta ahora.
 *
 * **No se cambia ninguno.** El de `locked` ya se midió y se dejó como está por la
 * misma razón: son dieciséis colegios y nadie ha medido qué manda cada uno de los
 * cuatro clientes. Lo que faltaba no era el arreglo, era la tabla.
 */
class VotacionesInterruptoresTest extends CasoDeContrato
{
    private function tokenDelPersonal(): string
    {
        return $this->tokenDe($this->usuarioDeTipo('Usuario')->username);
    }

    /**
     * Una votación **viva**: los conmutadores usan `VtVotacion::where(...)`, y el
     * modelo lleva `SoftDeletes`, así que sobre una de la papelera no escriben nada
     * y contestan «Cambiado» igual. Coger «la primera por id» sin este filtro fue
     * lo que dio dos mediciones contrarias del mismo interruptor.
     */
    private function unaVotacion(): int
    {
        $id = (int) DB::table('vt_votaciones')->whereNull('deleted_at')->orderBy('id')->value('id');
        $this->assertNotSame(0, $id, 'El seed no tiene votaciones vivas.');

        return $id;
    }

    /** El camino bueno: con el campo, escribe lo que se le manda. */
    public function test_el_conmutador_escribe_lo_que_se_le_manda(): void
    {
        $token = $this->tokenDelPersonal();
        $id = $this->unaVotacion();

        DB::table('vt_votaciones')->where('id', $id)->update(['votan_profes' => 0]);

        $this->withToken($token)->putJson('/api/votaciones/set-votan-profes',
            ['id' => $id, 'votan_profes' => 1])->assertStatus(200)->assertSee('Cambiado');

        $this->assertSame(1, (int) DB::table('vt_votaciones')->where('id', $id)->value('votan_profes'));

        $this->withToken($token)->putJson('/api/votaciones/set-votan-profes',
            ['id' => $id, 'votan_profes' => 0])->assertStatus(200);

        $this->assertSame(0, (int) DB::table('vt_votaciones')->where('id', $id)->value('votan_profes'));
    }

    /**
     * §101 — Sin el campo, el voto de los profesores **se enciende solo**.
     *
     * `Request::input('votan_profes', true)`. Es la gemela de
     * `test_sin_el_campo_el_candado_se_cierra_solo` con el signo cambiado: allí el
     * defecto cierra, aquí abre. Y la variable local se llama `$locked` —copiada
     * del método del candado sin renombrar—, que es la huella de por qué los seis
     * defectos no coinciden.
     *
     * Se fija tal cual. Cambiarlo es visible en dieciséis colegios y la decisión ya
     * se tomó una vez para su hermana.
     */
    public function test_sin_el_campo_el_voto_de_los_profesores_se_enciende_solo(): void
    {
        $token = $this->tokenDelPersonal();
        $id = $this->unaVotacion();

        DB::table('vt_votaciones')->where('id', $id)->update(['votan_profes' => 0]);

        $this->withToken($token)->putJson('/api/votaciones/set-votan-profes', ['id' => $id])
            ->assertStatus(200)->assertSee('Cambiado');

        $this->assertSame(1, (int) DB::table('vt_votaciones')->where('id', $id)->value('votan_profes'),
            'Sin el campo, el defecto de `Request::input` es `true` y el voto se abre.');
    }

    /**
     * Y con el campo en null explícito hace lo contrario que sin el campo.
     *
     * `Request::input('x', $defecto)` solo aplica el defecto cuando **la clave no
     * está**; con la clave presente y en null devuelve null, y la columna lo recibe
     * como 0 porque `config/database.php` lleva `'strict' => false`. O sea que un
     * front que limpia el campo y otro que lo omite obtienen resultados opuestos
     * de la misma petición.
     */
    public function test_el_campo_en_null_y_el_campo_ausente_dan_lo_contrario(): void
    {
        $token = $this->tokenDelPersonal();
        $id = $this->unaVotacion();

        DB::table('vt_votaciones')->where('id', $id)->update(['votan_profes' => 0]);

        $this->withToken($token)->putJson('/api/votaciones/set-votan-profes',
            ['id' => $id, 'votan_profes' => null])->assertStatus(200);

        $this->assertSame(0, (int) DB::table('vt_votaciones')->where('id', $id)->value('votan_profes'),
            'Con la clave presente y en null, el defecto no se aplica.');
    }

    /**
     * Sin `id`: 200 «Cambiado» y **ninguna fila**. Descartado que sea masivo.
     *
     * Es lo que había que medir antes de escribir nada, porque un conmutador que
     * escriba en todas las votaciones del colegio y conteste 200 es otra cosa
     * completamente. `where('id', null)` no casa con ninguna fila — no con todas.
     */
    public function test_sin_id_no_toca_ninguna_votacion(): void
    {
        $token = $this->tokenDelPersonal();

        DB::table('vt_votaciones')->update(['votan_profes' => 0]);
        $una = $this->unaVotacion();
        DB::table('vt_votaciones')->where('id', $una)->update(['votan_profes' => 1]);

        $antes = DB::table('vt_votaciones')->orderBy('id')->pluck('votan_profes', 'id')->all();

        $this->withToken($token)->putJson('/api/votaciones/set-votan-profes', ['votan_profes' => 1])
            ->assertStatus(200)->assertSee('Cambiado');

        $this->assertSame($antes, DB::table('vt_votaciones')->orderBy('id')->pluck('votan_profes', 'id')->all(),
            'Ni una votación cambió. Si algún día esto se reescribe con otro WHERE, aquí se ve.');
    }

    /** Sobre una votación de la papelera tampoco escribe, y lo dice igual. */
    public function test_sobre_una_votacion_de_la_papelera_no_escribe(): void
    {
        $token = $this->tokenDelPersonal();
        $id = $this->unaVotacion();

        DB::table('vt_votaciones')->where('id', $id)->update(['votan_profes' => 0, 'deleted_at' => now()]);

        $this->withToken($token)->putJson('/api/votaciones/set-votan-profes',
            ['id' => $id, 'votan_profes' => 1])->assertStatus(200)->assertSee('Cambiado');

        $this->assertSame(0, (int) DB::table('vt_votaciones')->where('id', $id)->value('votan_profes'),
            'El modelo lleva SoftDeletes: el scope quita la fila del UPDATE y la respuesta no se entera.');
    }

    /**
     * §101 — `votaciones/update` hace lo tercero: **conserva lo que no viaja**.
     *
     * Es el contraste que hace útil la tabla. La misma columna, en el mismo
     * fichero: el conmutador la enciende cuando el campo falta y `update` la deja
     * como estaba. Y `actividades/guardar`, en otro módulo, la dejaría en blanco.
     */
    public function test_update_con_un_cuerpo_parcial_conserva_lo_demas(): void
    {
        $token = $this->tokenDelPersonal();
        $id = $this->unaVotacion();

        DB::table('vt_votaciones')->where('id', $id)->update([
            'votan_profes' => 1, 'votan_acudientes' => 0, 'locked' => 1, 'actual' => 1, 'in_action' => 1,
        ]);

        $r = $this->withToken($token)->putJson('/api/votaciones/update/'.$id, ['nombre' => 'Personero 2026']);
        $r->assertStatus(200);

        $fila = DB::table('vt_votaciones')->where('id', $id)->first();
        $this->assertSame('Personero 2026', $fila->nombre);
        $this->assertSame(1, (int) $fila->votan_profes);
        $this->assertSame(0, (int) $fila->votan_acudientes);
        $this->assertSame(1, (int) $fila->locked, 'El candado no se abre por editar el nombre.');
        $this->assertSame(1, (int) $fila->actual);
        $this->assertSame(1, (int) $fila->in_action);
    }

    /** Y un id que no existe es 404 en las dos, que es lo correcto. */
    public function test_una_votacion_que_no_existe_es_404(): void
    {
        $token = $this->tokenDelPersonal();
        $inexistente = ((int) DB::table('vt_votaciones')->max('id')) + 1000;

        $this->withToken($token)->getJson('/api/votaciones/show/'.$inexistente)->assertStatus(404);
        $this->withToken($token)->putJson('/api/votaciones/update/'.$inexistente, ['nombre' => 'X'])
            ->assertStatus(404);
    }

    /**
     * `votaciones/show/{id}` lleva **solo `auth.token`**, y era la pregunta del lote.
     *
     * Lo que sale es la fila de `vt_votaciones` entera: nombre, fechas y los seis
     * interruptores. **Ni un dato de una persona** —el único id que viaja es
     * `user_id`, el de quien la creó—, así que no es una fuga.
     *
     * Lo que sí permite es **enumerar**: un alumno recorre ids y sabe qué
     * votaciones existen en el colegio, de qué año son y si están abiertas, incluso
     * las de años anteriores y las que no son la actual. Se anota, no se cierra:
     * cerrarla apagaría la papeleta del alumno, que es quien tiene que leerla.
     */
    public function test_un_alumno_ve_la_votacion_entera_pero_no_a_nadie(): void
    {
        $id = $this->unaVotacion();

        foreach (['Alumno', 'Acudiente'] as $tipo) {
            $token = $this->tokenDe($this->usuarioDeTipo($tipo)->username);

            $r = $this->withToken($token)->getJson('/api/votaciones/show/'.$id);
            $r->assertStatus(200);

            $this->assertSame(
                ['id', 'user_id', 'year_id', 'nombre', 'votan_profes', 'votan_acudientes', 'locked',
                    'actual', 'in_action', 'can_see_results', 'fecha_inicio', 'fecha_fin',
                    'created_by', 'updated_by', 'deleted_by', 'deleted_at', 'created_at', 'updated_at'],
                array_keys($r->json()),
                'Si esta lista crece con algo de una persona, deja de ser un catálogo.');

            // Y las dos que escriben sí les están cerradas.
            $this->withToken($token)->putJson('/api/votaciones/update/'.$id, ['nombre' => 'X'])
                ->assertStatus(403);
            $this->withToken($token)->putJson('/api/votaciones/set-votan-profes',
                ['id' => $id, 'votan_profes' => 1])->assertStatus(403);
        }
    }
}
