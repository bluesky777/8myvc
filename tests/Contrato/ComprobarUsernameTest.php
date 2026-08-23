<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * `GET api/perfiles/comprobarusername/{username}`, que respondía 500 siempre.
 *
 * Llamaba a `User::withTrashed()`, y `App\User` no usa SoftDeletes: ese método
 * no existe, así que la llamada era un `BadMethodCallException` desde que se
 * escribió. Lo encontró larastan al subir al nivel 1 — el nivel 0 no lo ve
 * porque `Model` tiene `__callStatic` y para el análisis la clase «podría»
 * responder. Es el mismo punto ciego que el `$this->user()` de la Fase 6.
 *
 * Lo usa la pantalla de crear usuario, para avisar antes de guardar. Que
 * llevara años roto encaja con el resto de la lista: el error sale en la
 * consola del navegador, no en la pantalla.
 *
 * Sin scope global que filtre, un `where` a secas ya incluye a los borrados,
 * que es justo lo que hace falta y lo que `withTrashed` pretendía: el username
 * de alguien borrado sigue ocupado, porque la fila sigue en la tabla y el
 * INSERT chocaría con ella.
 */
class ComprobarUsernameTest extends CasoDeContrato
{
    public function test_un_username_que_existe_se_reporta_como_ocupado(): void
    {
        $usuario = $this->usuarioLlanoDelPersonal();
        $token = $this->tokenDe($usuario->username);

        $r = $this->getJson('/api/perfiles/comprobarusername/'.$usuario->username,
            ['Authorization' => 'Bearer '.$token])->assertStatus(200);

        $this->assertSame([['existe' => true]], $r->json());
    }

    public function test_un_username_libre_se_reporta_como_libre(): void
    {
        $usuario = $this->usuarioLlanoDelPersonal();
        $token = $this->tokenDe($usuario->username);

        $libre = 'nadie-se-llama-asi-'.$usuario->id;

        $this->assertSame(0, DB::table('users')->where('username', $libre)->count(),
            'El test necesita un username que no exista.');

        $r = $this->getJson('/api/perfiles/comprobarusername/'.$libre,
            ['Authorization' => 'Bearer '.$token])->assertStatus(200);

        $this->assertSame([['existe' => false]], $r->json());
    }

    /**
     * Lo que `withTrashed()` quería decir, y la razón de no cambiarlo por un
     * `whereNull('deleted_at')` al arreglarlo: si esto dijera «libre», la
     * pantalla dejaría intentar el alta y el INSERT chocaría contra la fila
     * borrada, que sigue ahí.
     */
    public function test_el_username_de_un_usuario_borrado_sigue_ocupado(): void
    {
        $usuario = $this->usuarioLlanoDelPersonal();
        $token = $this->tokenDe($usuario->username);

        $victima = DB::table('users')->whereNull('deleted_at')
            ->where('id', '<>', $usuario->id)->orderBy('id')->first();

        // La transacción del caso de test lo deshace al terminar.
        DB::table('users')->where('id', $victima->id)->update(['deleted_at' => now()]);

        $r = $this->getJson('/api/perfiles/comprobarusername/'.$victima->username,
            ['Authorization' => 'Bearer '.$token])->assertStatus(200);

        $this->assertSame([['existe' => true]], $r->json(),
            'Un username borrado debe seguir contando como ocupado: la fila no se fue.');
    }
}
