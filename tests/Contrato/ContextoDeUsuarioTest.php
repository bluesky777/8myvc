<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * Cómo se resuelve el contexto del usuario a partir del token.
 *
 * `User::fromToken()` no valida un token y ya: monta el objeto que usa medio
 * proyecto —persona, grupo, año, periodo, configuración del colegio, roles y
 * permisos— con un `switch` de cuatro ramas y consultas de cuarenta columnas.
 * Cuesta de 5 a 8 consultas cada vez.
 */
class ContextoDeUsuarioTest extends CasoDeContrato
{
    /**
     * El contexto se resuelve UNA vez por petición.
     *
     * Desde que el guard `auth.token` se aplica a toda la API, cada petición lo
     * resolvía por lo menos dos veces: una en el middleware y otra en el
     * controlador al leer `$this->user`. Algunos métodos llaman además por su
     * cuenta.
     */
    public function test_el_contexto_se_resuelve_una_sola_vez_por_peticion(): void
    {
        $usuario = $this->usuarioDeTipo('Profesor');
        $token = $this->tokenDe($usuario->username);

        $veces = 0;

        // La consulta del switch es la cara, y es la que identifica una
        // resolución completa: las cuatro ramas empiezan por "as persona_id".
        DB::listen(function ($consulta) use (&$veces) {
            if (str_contains($consulta->sql, 'as persona_id')) {
                $veces++;
            }
        });

        // Una ruta que pasa por el guard Y lee $this->user en el controlador.
        $this->getJson('/api/periodos', ['Authorization' => 'Bearer '.$token])
            ->assertStatus(200);

        $this->assertSame(1, $veces,
            "El contexto del usuario se resolvió {$veces} veces en una sola petición.\n".
            'Debería salir de la memoria de la petición (App\User::CONTEXTO).');
    }

    /**
     * Un alumno cuyo `periodo_id` apunta a otro año entra a la primera.
     *
     * `fromToken()` detecta ese caso, corrige el periodo en la base y vuelve a
     * resolver. Pero se llamaba a sí misma tirando el resultado (`return;` a
     * secas), así que devolvía null: el alumno recibía **200 con el cuerpo
     * vacío** al entrar. Al segundo intento funcionaba —el UPDATE ya había
     * corregido el periodo—, y por eso parecía cosa de una vez.
     *
     * Se llega aquí de forma natural al pasar de año, que es justo cuando más
     * gente entra a la vez.
     */
    public function test_un_periodo_de_otro_anio_se_corrige_y_devuelve_el_contexto(): void
    {
        $usuario = $this->usuarioDeTipo('Alumno');
        $token = $this->tokenDe($usuario->username);

        $suYear = DB::select('SELECT g.year_id FROM alumnos a
            INNER JOIN matriculas m ON m.alumno_id = a.id AND m.deleted_at IS NULL
            INNER JOIN grupos g ON g.id = m.grupo_id
            WHERE a.user_id = ? LIMIT 1', [$usuario->id])[0]->year_id;

        $ajeno = DB::select('SELECT id FROM periodos WHERE deleted_at IS NULL AND year_id <> ? LIMIT 1',
            [$suYear])[0];

        DB::update('UPDATE users SET periodo_id = ? WHERE id = ?', [$ajeno->id, $usuario->id]);

        $r = $this->postJson('/api/login', [], ['Authorization' => 'Bearer '.$token]);

        $r->assertStatus(200);

        $this->assertNotEmpty($r->json(),
            'El alumno entró con el periodo de otro año y recibió un cuerpo vacío.');

        $this->assertSame($usuario->id, $r->json('user_id'));

        // Y de paso dejó el periodo arreglado, que es lo que hacía que al
        // segundo intento funcionara.
        $this->assertNotSame($ajeno->id, DB::table('users')->where('id', $usuario->id)->value('periodo_id'));
    }
}
