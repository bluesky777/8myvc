<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * Lo que encontró preguntar «¿qué MÁS lee este identificador del cuerpo?».
 *
 * La §50 cerró el quinto sitio en el que `data_id` o `asked_id` venían del cuerpo
 * y nadie miraba de quién eran, y dejó escrito que las tres pasadas que costó
 * —§39, §49, §50— no fueron flojas: **cada una entró por una ruta y arregló lo
 * que esa ruta tocaba**. La pregunta que las habría juntado entra por el
 * identificador. Contestada el 21 ago 2026 con
 * `tools/identificadores-del-cuerpo.py`, que dice qué rutas leen un id del cuerpo
 * y cuáles no lo comprueba nadie.
 *
 * Los tres que salieron, y que este test fija:
 *
 * 1. **El sexto `asked_id`**, en otro controlador: `ChangesAskedAssignment/ver-detalles`
 *    entregaba la fila entera de `change_asked_data` de cualquiera.
 * 2. **`images-users/imagenes-de-usuario`**, el álbum privado de cualquiera a
 *    cualquiera con token — y tapado por una exención escrita contra el nombre de
 *    clave equivocado.
 * 3. **`foto_id`**, el tercer nombre de una imagen, que `persona.propia` no
 *    conocía.
 *
 * Los tres se midieron ANTES de tocar nada, y los tres siguen aquí escritos con
 * lo que devolvían: sin eso, un test verde no distingue el arreglo del seed
 * vacío. Ver docs/migracion/05-codigo-muerto-y-roto.md §53.
 */
class IdentificadoresDelCuerpoTest extends CasoDeContrato
{
    /** Un pedido de cambio de OTRO, con datos personales dentro. */
    private function pedidoAjeno(int $userId): int
    {
        $otro = DB::selectOne('SELECT id FROM users WHERE id <> ? AND deleted_at IS NULL
            ORDER BY id LIMIT 1', [$userId]);
        $year = DB::selectOne('SELECT id FROM years WHERE actual = 1 AND deleted_at IS NULL LIMIT 1');

        $this->assertNotNull($otro, 'El seed necesita otro usuario.');
        $this->assertNotNull($year, 'El seed necesita un año actual.');

        DB::insert('INSERT INTO change_asked_data(documento_new, telefono_new, celular_new,
            direccion_new, email_new) VALUES(?,?,?,?,?)',
            ['1234567890', '6011234567', '3001234567', 'Calle Falsa 123', 'privado@ejemplo.com']);
        $dataId = (int) DB::getPdo()->lastInsertId();

        DB::insert('INSERT INTO change_asked(asked_by_user_id, year_asked_id, data_id, tipo_user, created_at)
            VALUES(?,?,?,"Alumno",NOW())', [$otro->id, $year->id, $dataId]);

        return (int) DB::getPdo()->lastInsertId();
    }

    /**
     * El sexto sitio del mismo `asked_id`, en el controlador de al lado.
     *
     * Antes: 200 con `documento_new`, `telefono_new`, `celular_new`,
     * `direccion_new` y `email_new` del pedido ajeno.
     */
    public function test_los_detalles_de_un_pedido_ajeno_no_se_ven_por_el_controlador_de_asignaturas(): void
    {
        $profe = $this->usuarioDeTipo('Profesor');
        $asked = $this->pedidoAjeno((int) $profe->id);

        $this->withToken($this->tokenDe($profe->username))
            ->putJson('/api/ChangesAskedAssignment/ver-detalles', ['asked_id' => $asked])
            ->assertStatus(403);
    }

    /** Y la hermana que arregló la §50 sigue contestando lo mismo: son un solo criterio. */
    public function test_las_dos_rutas_de_ver_detalles_contestan_igual(): void
    {
        $profe = $this->usuarioDeTipo('Profesor');
        $asked = $this->pedidoAjeno((int) $profe->id);
        $token = $this->tokenDe($profe->username);

        $this->withToken($token)->putJson('/api/ChangesAsked/ver-detalles', ['asked_id' => $asked])
            ->assertStatus(403);
        $this->withToken($token)->putJson('/api/ChangesAskedAssignment/ver-detalles', ['asked_id' => $asked])
            ->assertStatus(403);
    }

    /**
     * Un pedido que no existe es 404 y no 500.
     *
     * `ChangeAskedDetails::detalles()` indexa su consulta con `[0]`, así que sin
     * comprobación previa un id inventado reventaba. Es el mismo `[0]` de la §44,
     * la §47 y la §50 — la quinta vez que una fila que no está sale como error del
     * servidor.
     */
    public function test_un_pedido_que_no_existe_es_404_en_las_dos(): void
    {
        $profe = $this->usuarioDeTipo('Profesor');
        $token = $this->tokenDe($profe->username);
        $inexistente = ((int) DB::table('change_asked')->max('id')) + 1000;

        $this->withToken($token)->putJson('/api/ChangesAsked/ver-detalles', ['asked_id' => $inexistente])
            ->assertStatus(404);
        $this->withToken($token)->putJson('/api/ChangesAskedAssignment/ver-detalles', ['asked_id' => $inexistente])
            ->assertStatus(404);
    }

    /** El dueño sí ve los suyos: el arreglo cierra a los demás, no la pantalla. */
    public function test_el_dueno_sigue_viendo_los_detalles_de_su_pedido(): void
    {
        $profe = $this->usuarioDeTipo('Profesor');
        $year = DB::selectOne('SELECT id FROM years WHERE actual = 1 AND deleted_at IS NULL LIMIT 1');

        DB::insert('INSERT INTO change_asked_data(documento_new) VALUES("999")');
        $dataId = (int) DB::getPdo()->lastInsertId();
        DB::insert('INSERT INTO change_asked(asked_by_user_id, year_asked_id, data_id, tipo_user, created_at)
            VALUES(?,?,?,"Profesor",NOW())', [$profe->id, $year->id, $dataId]);
        $asked = (int) DB::getPdo()->lastInsertId();

        $this->withToken($this->tokenDe($profe->username))
            ->putJson('/api/ChangesAskedAssignment/ver-detalles', ['asked_id' => $asked])
            ->assertStatus(200)
            ->assertJsonPath('detalles.documento_new', '999');
    }

    /**
     * El álbum privado de otra persona.
     *
     * Antes: 200 y las 162 imágenes privadas de un superusuario, con token de
     * alumno. La clave del cuerpo se llama `usuario_id`, y la exención que
     * justificaba la ruta hablaba de `user_id` — por eso nadie la volvió a mirar.
     */
    public function test_un_alumno_no_saca_el_album_privado_de_otro(): void
    {
        $alumno = $this->usuarioDeTipo('Alumno');
        $dueno = DB::selectOne('SELECT u.id, COUNT(i.id) n FROM users u
            INNER JOIN images i ON i.user_id = u.id AND (i.publica IS NULL OR i.publica = 0)
                AND i.deleted_at IS NULL
            WHERE u.id <> ? GROUP BY u.id ORDER BY n DESC LIMIT 1', [$alumno->id]);

        $this->assertNotNull($dueno, 'El seed necesita imágenes privadas de alguien.');
        $this->assertGreaterThan(0, $dueno->n, 'El seed necesita imágenes privadas de alguien.');

        $this->withToken($this->tokenDe($alumno->username))
            ->putJson('/api/images-users/imagenes-de-usuario', ['usuario_id' => $dueno->id])
            ->assertStatus(403);
    }

    /** Y tampoco un profesor: el front solo enseña esa pestaña al administrador. */
    public function test_un_profesor_tampoco_saca_el_album_privado_de_otro(): void
    {
        $profe = $this->usuarioDeTipo('Profesor');

        $this->withToken($this->tokenDe($profe->username))
            ->putJson('/api/images-users/imagenes-de-usuario', ['usuario_id' => 1])
            ->assertStatus(403);
    }

    /** El administrador, que es quien tiene la pestaña, sigue pudiendo. */
    public function test_el_administrativo_sigue_viendo_el_album_de_otro(): void
    {
        $super = DB::selectOne('SELECT username FROM users
            WHERE is_superuser = 1 AND is_active = 1 AND deleted_at IS NULL ORDER BY id LIMIT 1');

        $dueno = DB::selectOne('SELECT u.id FROM users u
            INNER JOIN images i ON i.user_id = u.id AND (i.publica IS NULL OR i.publica = 0)
                AND i.deleted_at IS NULL
            GROUP BY u.id ORDER BY COUNT(i.id) DESC LIMIT 1');

        $this->withToken($this->tokenDe($super->username))
            ->putJson('/api/images-users/imagenes-de-usuario', ['usuario_id' => $dueno->id])
            ->assertStatus(200);
    }

    /**
     * `foto_id`, el tercer nombre de una imagen.
     *
     * Antes: 200, y `change_asked_data.foto_id_new` con la imagen de otro dentro.
     * La ruta llevaba `persona.propia` y el guard miraba el `user_id` de la URL
     * —suyo— sin ver lo que proponía el cuerpo. Es la §13 otra vez: el guard
     * estaba puesto y la pregunta era otra.
     */
    public function test_un_alumno_no_propone_la_imagen_de_otro_como_su_foto_oficial(): void
    {
        $alumno = $this->usuarioDeTipo('Alumno');
        $ajena = DB::selectOne('SELECT id FROM images
            WHERE user_id IS NOT NULL AND user_id <> ? AND deleted_at IS NULL ORDER BY id LIMIT 1',
            [$alumno->id]);

        $this->assertNotNull($ajena, 'El seed necesita una imagen de otra persona.');

        $this->withToken($this->tokenDe($alumno->username))
            ->putJson('/api/images-users/cambiar-imagen-oficial/'.$alumno->id,
                ['foto_id' => $ajena->id])
            ->assertStatus(403);

        $this->assertNull(
            DB::selectOne('SELECT d.foto_id_new FROM change_asked c
                INNER JOIN change_asked_data d ON d.id = c.data_id
                WHERE c.asked_by_user_id = ? AND d.foto_id_new = ?', [$alumno->id, $ajena->id]),
            'El 403 tiene que llegar antes de la escritura, no después.'
        );
    }

    /**
     * Comentar donde no se lee.
     *
     * `publicaciones/comentar` recibía `publi_id` del cuerpo y no miraba nada.
     * Antes: 200 y la fila escrita en una publicación marcada solo
     * `para_administradores`, que la misma llamada confirma que **no sale en el
     * muro del alumno**. Es la §22 en la hermana que aquella pasada no tocó:
     * miró borrar, restaurar y editar, y comentar no lleva `publi_id` en ninguna
     * de las tres.
     */
    public function test_un_alumno_no_comenta_una_publicacion_que_no_ve(): void
    {
        $alumno = $this->usuarioDeTipo('Alumno');

        DB::insert('INSERT INTO publicaciones(persona_id, tipo_persona, contenido,
            para_todos, para_alumnos, para_acudientes, para_profes, para_administradores,
            created_at, updated_at)
            VALUES(1, "Usuario", "Solo para administradores", 0, 0, 0, 0, 1, NOW(), NOW())');
        $invisible = (int) DB::getPdo()->lastInsertId();

        $this->withToken($this->tokenDe($alumno->username))
            ->putJson('/api/publicaciones/comentar',
                ['publi_id' => $invisible, 'comentario' => 'no debería entrar'])
            ->assertStatus(403);

        $this->assertSame(0, DB::table('comentarios')->where('publicacion_id', $invisible)->count(),
            'El 403 tiene que llegar antes de la escritura, no después.');
    }

    /** Y la que sí ve la sigue comentando: se cierra el muro ajeno, no el suyo. */
    public function test_un_alumno_sigue_comentando_lo_que_si_ve(): void
    {
        $alumno = $this->usuarioDeTipo('Alumno');

        DB::insert('INSERT INTO publicaciones(persona_id, tipo_persona, contenido,
            para_todos, para_alumnos, para_acudientes, para_profes, para_administradores,
            created_at, updated_at)
            VALUES(1, "Usuario", "Para todos", 1, 1, 1, 1, 1, NOW(), NOW())');
        $visible = (int) DB::getPdo()->lastInsertId();

        $this->withToken($this->tokenDe($alumno->username))
            ->putJson('/api/publicaciones/comentar',
                ['publi_id' => $visible, 'comentario' => 'sí entra'])
            ->assertStatus(200);

        $this->assertSame(1, DB::table('comentarios')->where('publicacion_id', $visible)->count());
    }

    /** Una publicación que no existe era 500 —la clave ajena— donde tocaba 404. */
    public function test_comentar_una_publicacion_que_no_existe_es_404(): void
    {
        $alumno = $this->usuarioDeTipo('Alumno');
        $inexistente = ((int) DB::table('publicaciones')->max('id')) + 1000;

        $this->withToken($this->tokenDe($alumno->username))
            ->putJson('/api/publicaciones/comentar',
                ['publi_id' => $inexistente, 'comentario' => 'huérfano'])
            ->assertStatus(404);
    }

    /** Con una imagen suya, la pantalla sigue funcionando. */
    public function test_un_alumno_sigue_proponiendo_su_propia_imagen(): void
    {
        $alumno = $this->usuarioDeTipo('Alumno');

        DB::insert('INSERT INTO images(nombre, user_id, created_by, created_at)
            VALUES("mia.jpg", ?, ?, NOW())', [$alumno->id, $alumno->id]);
        $mia = (int) DB::getPdo()->lastInsertId();

        $this->withToken($this->tokenDe($alumno->username))
            ->putJson('/api/images-users/cambiar-imagen-oficial/'.$alumno->id, ['foto_id' => $mia])
            ->assertStatus(200);
    }
}
