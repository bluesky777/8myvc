<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * Lo que encontró subir larastan al nivel 2 (20 ago 2026).
 *
 * Cuatro endpoints enrutados que reventaban siempre, y ninguno lo sabía nadie.
 * Dos se arreglaron —la corrección no tenía decisión detrás— y dos se quedan
 * como están porque sí la tienen; de esos dos, lo que este fichero fija es el
 * error EXACTO, para que un cambio de la migración no los mueva en silencio.
 *
 * Es la misma forma que la §8 de 05-codigo-muerto-y-roto.md, con un detector
 * distinto: allí fue golpear las rutas, aquí leer todas las líneas.
 */
class EntrustYPropiedadesTest extends CasoDeContrato
{
    /**
     * `PUT api/perfiles/creartodoslosusuarios` — arreglado.
     *
     * Crea la cuenta de cada alumno, profesor y acudiente que no la tenga.
     * Llamaba a `attachRole()`, que es de Entrust y no está instalado desde
     * antes de esta migración, así que moría en la primera persona de la lista.
     * Y moría DESPUÉS de guardar el usuario y ANTES de enganchárselo a la
     * persona: cada intento dejaba un usuario huérfano y devolvía 500.
     *
     * El reemplazo no fue una decisión: `AlumnosController` ya tenía hecha esa
     * misma migración —`roles()->attach()`, con la línea de Entrust comentada
     * al lado— y aquí quedó sin hacer.
     */
    public function test_crear_los_usuarios_que_faltan_ya_no_revienta(): void
    {
        $usuario = $this->usuarioDeTipo('Usuario');
        $token = $this->tokenDe($usuario->username);

        // Un alumno sin cuenta, para que el endpoint tenga trabajo que hacer.
        $alumno = DB::selectOne('SELECT id FROM alumnos WHERE deleted_at IS NULL ORDER BY id LIMIT 1');
        DB::update('UPDATE alumnos SET user_id = NULL WHERE id = ?', [$alumno->id]);

        $antes = DB::table('users')->count();

        $this->putJson('/api/perfiles/creartodoslosusuarios', [], ['Authorization' => 'Bearer '.$token])
            ->assertStatus(200);

        $this->assertNotNull(
            DB::table('alumnos')->where('id', $alumno->id)->value('user_id'),
            'El alumno salió sin cuenta: el fallo caía entre guardar el usuario y engancharlo a la persona.'
        );

        $this->assertGreaterThan($antes, DB::table('users')->count(),
            'No se creó ninguna cuenta, así que este test no comprobó nada.');
    }

    /**
     * `PUT api/periodos/update/{id}` — sigue roto, y aquí queda por qué.
     *
     * Escribe `$periodo->year` y guarda, pero `periodos` no tiene esa columna:
     * tiene `year_id`. MySQL responde «Unknown column 'year' in 'field list'».
     *
     * No se arregla porque falta saber qué manda el cliente en `year` —el número
     * del año o el id—, y no hay cliente: `myvc_front` no llama a esta ruta.
     * Adivinarlo sería escribir en `year_id` un número de año.
     */
    public function test_editar_un_periodo_sigue_fallando_por_la_columna_que_no_existe(): void
    {
        $usuario = $this->usuarioDeTipo('Usuario');
        $token = $this->tokenDe($usuario->username);

        $periodo = DB::selectOne('SELECT id, numero FROM periodos WHERE deleted_at IS NULL ORDER BY id LIMIT 1');

        $respuesta = $this->putJson('/api/periodos/update/'.$periodo->id, [
            'numero' => $periodo->numero,
            'year' => 2025,
        ], ['Authorization' => 'Bearer '.$token]);

        $this->assertSame(500, $respuesta->status(),
            'El endpoint dejó de fallar. Si se arregló, quita este test y anota la decisión '.
            'en docs/migracion/05-codigo-muerto-y-roto.md §9.');
    }

    /**
     * `POST api/asistencias` — sigue roto, y por dos cosas a la vez.
     *
     * El INSERT declara `:asignatura_id` y el array de valores no lo trae, así
     * que la consulta ni se ejecuta. Detrás espera la segunda: `$datos` es un
     * array y hace `$datos->id = $id`, que en PHP 8 es un Error y no un aviso.
     * Un fallo tapando a otro, como el `Input::` de la §1.
     */
    public function test_subir_una_asistencia_sigue_fallando(): void
    {
        $usuario = $this->usuarioDeTipo('Usuario');
        $token = $this->tokenDe($usuario->username);

        $alumno = DB::selectOne('SELECT id FROM alumnos WHERE deleted_at IS NULL ORDER BY id LIMIT 1');

        $respuesta = $this->postJson('/api/asistencias', [
            'alumno_id' => $alumno->id,
            'cantidad_ausencia' => 1,
            'cantidad_tardanza' => 0,
            'tipo' => 'ausencia',
        ], ['Authorization' => 'Bearer '.$token]);

        $this->assertSame(500, $respuesta->status(),
            'El endpoint dejó de fallar. Si se arregló, quita este test y anota la decisión '.
            'en docs/migracion/05-codigo-muerto-y-roto.md §9.');
    }
}
