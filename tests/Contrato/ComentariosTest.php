<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * Los comentarios de las publicaciones, y el que nadie podía borrar.
 *
 * `putBorrarComentario` decidía así:
 *
 *     if ($user->is_superuser || $user.persona_id==comentario.persona_id) {
 *
 * `$user.persona_id` no es una propiedad: es **concatenación de cadenas** con una
 * constante llamada `persona_id` que no existe, y otro tanto con
 * `comentario.persona_id`. En PHP 7 una constante indefinida era un aviso y se
 * tomaba como su propio nombre; **en PHP 8 es un error fatal**. Como el `||`
 * corta por la izquierda, un superusuario nunca llegaba a esa mitad — y todos los
 * demás sí. O sea que **el autor de un comentario recibía un 500 al borrar el
 * suyo**, y solo desde el salto a PHP 8.
 *
 * Ver docs/migracion/05-codigo-muerto-y-roto.md §42.
 */
class ComentariosTest extends CasoDeContrato
{
    /** Una publicación sobre la que comentar. */
    private function publicacion(): int
    {
        $fila = DB::table('publicaciones')->whereNull('deleted_at')->orderBy('id')->first();

        return (int) ($fila->id ?? DB::table('publicaciones')->insertGetId([
            'persona_id' => 1,
            'tipo_persona' => 'Usuario',
            'contenido' => 'para comentar',
            'para_todos' => 1,
        ]));
    }

    /** Comentar deja la fila con quién lo escribió. */
    public function test_comentar_guarda_quien_lo_escribio(): void
    {
        $alumno = $this->usuarioDeTipo('Alumno');
        $publiId = $this->publicacion();

        $r = $this->withToken($this->tokenDe($alumno->username))
            ->putJson('/api/publicaciones/comentar', [
                'publi_id' => $publiId,
                'comentario' => 'lo escribió un alumno',
            ]);

        $r->assertStatus(200);

        $fila = DB::table('comentarios')->where('id', $r->json('comentario_id'))->first();

        $this->assertNotNull($fila, 'El comentario no llegó a escribirse.');
        $this->assertSame('Alumno', $fila->tipo_persona);
        $this->assertSame('lo escribió un alumno', $fila->comentario);
    }

    /**
     * El autor borra el suyo, que es lo que la expresión rota impedía.
     *
     * Se comprueba la fila y no solo el código: lo que importa es que el
     * comentario quede en la papelera.
     */
    public function test_el_autor_borra_su_propio_comentario(): void
    {
        $alumno = $this->usuarioDeTipo('Alumno');
        $token = $this->tokenDe($alumno->username);
        $publiId = $this->publicacion();

        $comentarioId = $this->withToken($token)
            ->putJson('/api/publicaciones/comentar', [
                'publi_id' => $publiId,
                'comentario' => 'me equivoqué',
            ])->json('comentario_id');

        $this->withToken($token)->putJson('/api/publicaciones/borrar-comentario', [
            'comentario_id' => $comentarioId,
        ])->assertStatus(200);

        $this->assertNotNull(DB::table('comentarios')->where('id', $comentarioId)->value('deleted_at'),
            'El autor no consiguió borrar su propio comentario.');
    }

    /** Y no borra el de otro. */
    public function test_nadie_borra_el_comentario_de_otro(): void
    {
        $publiId = $this->publicacion();

        $autor = $this->usuarioDeTipo('Alumno');
        $comentarioId = $this->withToken($this->tokenDe($autor->username))
            ->putJson('/api/publicaciones/comentar', [
                'publi_id' => $publiId,
                'comentario' => 'esto es mío',
            ])->json('comentario_id');

        $otro = $this->usuarioDeTipo('Acudiente');

        $this->withToken($this->tokenDe($otro->username))
            ->putJson('/api/publicaciones/borrar-comentario', [
                'comentario_id' => $comentarioId,
            ])->assertStatus(400);

        $this->assertNull(DB::table('comentarios')->where('id', $comentarioId)->value('deleted_at'),
            'Un tercero borró el comentario de otro.');
    }

    /** El superusuario sí, que es la mitad que ya funcionaba. */
    public function test_el_superusuario_borra_cualquiera(): void
    {
        $publiId = $this->publicacion();

        $autor = $this->usuarioDeTipo('Alumno');
        $comentarioId = $this->withToken($this->tokenDe($autor->username))
            ->putJson('/api/publicaciones/comentar', [
                'publi_id' => $publiId,
                'comentario' => 'lo borrará el superusuario',
            ])->json('comentario_id');

        $super = DB::selectOne('SELECT u.username FROM users u
            INNER JOIN periodos p ON p.id = u.periodo_id
            WHERE u.is_superuser = 1 AND u.is_active = 1 AND u.deleted_at IS NULL
            ORDER BY u.id LIMIT 1');

        $this->withToken($this->tokenDe($super->username))
            ->putJson('/api/publicaciones/borrar-comentario', [
                'comentario_id' => $comentarioId,
            ])->assertStatus(200);

        $this->assertNotNull(DB::table('comentarios')->where('id', $comentarioId)->value('deleted_at'));
    }
}
