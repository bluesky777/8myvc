<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * Quién ve y quién escribe la ficha de otro profesor — §97 y §98.
 *
 * `ProfesoresController` tiene las cuatro operaciones de una ficha en el mismo
 * fichero, y hasta hoy nadie las había puesto en la misma tabla. Es el método
 * que dio la §76: **la pareja se mira junta, no cada ruta suelta.**
 *
 * Editar pide superusuario (§37), sacar de la papelera pide superusuario (§76) y
 * borrar definitivamente pide superusuario (§28.4). **Mandar a la papelera no
 * pide nada**: basta `auth.personal`, o sea cualquiera de los 51 profesores de
 * la copia de producción. Un test por ruta habría dado cuatro verdes.
 */
class FichaDeUnProfesorTest extends CasoDeContrato
{
    /**
     * Las cuatro operaciones de la ficha piden lo mismo, con el mismo token.
     *
     * Se recorren juntas a propósito, que es lo que enseñó el hallazgo: medidas
     * el 22 ago daban **tres 403 y un 200**, y el 200 era `destroy`. Un test por
     * ruta habría dado cuatro verdes.
     */
    public function test_un_profesor_cualquiera_no_toca_la_ficha_de_otro(): void
    {
        $token = $this->tokenDe($this->usuarioDeTipo('Profesor')->username);
        $otro = $this->otroProfesorVivo();

        $edicion = $this->withToken($token)->putJson('/api/profesores/update/'.$otro, []);
        $this->assertSame(403, $edicion->status(),
            'Editar la ficha de otro profesor dejó de pedir superusuario.');

        $enPapelera = $this->aLaPapelera($otro);
        $restaurar = $this->withToken($token)->putJson('/api/profesores/restore/'.$enPapelera, []);
        $this->assertSame(403, $restaurar->status(),
            'Restaurar a otro profesor dejó de pedir superusuario.');

        $definitivo = $this->withToken($token)->deleteJson('/api/profesores/forcedelete/'.$enPapelera, []);
        $this->assertSame(403, $definitivo->status(),
            'Borrar definitivamente a otro profesor dejó de pedir superusuario.');

        DB::update('UPDATE profesores SET deleted_at = NULL WHERE id = ?', [$enPapelera]);

        // Y la cuarta, la que hasta el 22 ago 2026 contestaba 200 con este mismo
        // token que no puede ni cambiarle el teléfono al mismo profesor.
        $borrado = $this->withToken($token)->deleteJson('/api/profesores/destroy/'.$otro, []);

        $this->assertSame(403, $borrado->status(),
            'Un profesor cualquiera mandó a otro a la papelera — §97.');

        $this->assertNull(
            DB::table('profesores')->where('id', $otro)->value('deleted_at'),
            'El rechazo lo mandó a la papelera antes de contestar que no.'
        );
    }

    /**
     * Y el superusuario sigue pudiendo, que es la otra mitad de un cierre.
     *
     * Sin esto, un `abort(403)` puesto arriba del método daría verde el test de
     * al lado y habría apagado el botón de los diez que sí lo usan.
     */
    public function test_el_superusuario_sigue_mandando_un_profesor_a_la_papelera(): void
    {
        $jefe = $this->tokenDeUnSuperusuario();
        $otro = $this->otroProfesorVivo();

        $this->withToken($jefe)->deleteJson('/api/profesores/destroy/'.$otro, [])
            ->assertStatus(200);

        $this->assertNotNull(
            DB::table('profesores')->where('id', $otro)->value('deleted_at'),
            'Contestó 200 y no lo mandó a la papelera.'
        );
    }

    /**
     * Lo que se lleva por delante, que es lo que no se ve desde la respuesta.
     *
     * Se mira **el resultado**: qué enseñan después las dos rejillas que pintan
     * profesores. `profesores/listado` filtra `deleted_at is null` y lo pierde,
     * que es lo esperable. La rejilla de grupos hace `left join profesores` **sin
     * ese filtro**, así que el grupo sigue enseñando de titular a alguien que ya
     * no está en la lista de profesores.
     */
    public function test_borrar_un_profesor_lo_saca_del_listado_y_lo_deja_de_titular(): void
    {
        $jefe = $this->tokenDeUnSuperusuario();
        $titular = $this->profesorTitularDeUnGrupo();

        $antes = $this->withToken($jefe)->putJson('/api/profesores/listado', []);
        $antes->assertStatus(200);
        $this->assertContains($titular->profesor_id, $this->idsDelListado($antes->json()),
            'El profesor elegido no salía en el listado ni antes de borrarlo.');

        $this->withToken($jefe)->deleteJson('/api/profesores/destroy/'.$titular->profesor_id, [])
            ->assertStatus(200);

        $despues = $this->withToken($jefe)->putJson('/api/profesores/listado', []);
        $this->assertNotContains($titular->profesor_id, $this->idsDelListado($despues->json()),
            'Un profesor en la papelera seguía saliendo en el listado.');

        // El grupo no se entera: `titular_id` sigue apuntando al borrado.
        $this->assertSame(
            (int) $titular->profesor_id,
            (int) DB::table('grupos')->where('id', $titular->grupo_id)->value('titular_id'),
            'Borrar al profesor cambió el titular del grupo — eso sí sería nuevo.'
        );
    }

    /**
     * Un id que no está da 404, y hasta el 22 ago 2026 daba 500 — §98.
     *
     * `Profesor::detallado()` termina en `return $profesor[0]` sobre el resultado
     * de un `DB::select`. Sin filas eso es **clave indefinida**, que en PHP 8 es
     * error fatal. No hacía falta un id inventado para verlo: bastaba uno que
     * estuviera en la papelera, porque esa consulta filtra `deleted_at is null`.
     * Las dos formas van en el mismo test porque el arreglo tapa las dos y **una
     * sola no lo habría demostrado**.
     */
    public function test_la_ficha_de_un_profesor_que_no_esta_da_404(): void
    {
        $token = $this->tokenDe($this->usuarioDeTipo('Profesor')->username);

        $inexistente = (int) DB::table('profesores')->max('id') + 1000;
        $this->assertSame(404,
            $this->withToken($token)->getJson('/api/profesores/show/'.$inexistente)->status(),
            'La ficha de un profesor inexistente dejó de dar 404 — §98.');

        $enPapelera = $this->aLaPapelera($this->otroProfesorVivo());
        $this->assertSame(404,
            $this->withToken($token)->getJson('/api/profesores/show/'.$enPapelera)->status(),
            'La ficha de un profesor en la papelera dejó de dar 404 — §98.');
    }

    /**
     * Qué sale en la ficha, que es la mitad «quién ve» de la pregunta del lote.
     *
     * `auth.personal` cierra la puerta a alumnos y acudientes; dentro no hay
     * ningún filtro más, así que **cualquiera de los 51 profesores lee la ficha
     * completa de cualquier otro**: documento, dirección, teléfono, celular y
     * correo. No se juzga aquí —es la hoja de vida que el colegio administra— y
     * se fija la lista para que ampliarla sea una decisión y no un descuido.
     */
    public function test_la_ficha_completa_de_otro_profesor_la_ve_cualquier_profesor(): void
    {
        $token = $this->tokenDe($this->usuarioDeTipo('Profesor')->username);
        $otro = $this->otroProfesorVivo();

        $r = $this->withToken($token)->getJson('/api/profesores/show/'.$otro);
        $r->assertStatus(200);

        $ficha = $r->json()[0];

        foreach (['num_doc', 'direccion', 'telefono', 'celular', 'email', 'fecha_nac'] as $campo) {
            $this->assertArrayHasKey($campo, $ficha,
                "La ficha dejó de traer {$campo}: es contrato con el front de dieciséis colegios.");
        }

        $this->assertArrayNotHasKey('password', $ficha, 'La ficha empezó a traer la contraseña.');
    }

    /** Una familia no llega a la ficha de un profesor: la cierra `auth.personal`. */
    public function test_una_familia_no_llega_a_la_ficha_de_un_profesor(): void
    {
        $otro = $this->otroProfesorVivo();

        foreach (['Alumno', 'Acudiente'] as $tipo) {
            $token = $this->tokenDe($this->usuarioDeTipo($tipo)->username);

            $this->assertSame(403,
                $this->withToken($token)->getJson('/api/profesores/show/'.$otro)->status(),
                "Un {$tipo} llegó a la ficha de un profesor.");

            $this->assertSame(403,
                $this->withToken($token)->deleteJson('/api/profesores/destroy/'.$otro, [])->status(),
                "Un {$tipo} mandó un profesor a la papelera.");
        }
    }

    /**
     * Un profesor vivo que NO es el del token.
     *
     * Tiene que ser otro: la pregunta del lote es la ficha **de otro**, y un
     * endpoint que dejara pasar solo lo propio daría verde con el mismo id.
     */
    private function otroProfesorVivo(): int
    {
        $mio = $this->usuarioDeTipo('Profesor');

        $fila = DB::selectOne(
            'SELECT id FROM profesores WHERE deleted_at IS NULL AND (user_id IS NULL OR user_id <> ?)
             ORDER BY id LIMIT 1',
            [$mio->id]
        );

        $this->assertNotNull($fila, 'El seed no tiene un segundo profesor vivo.');

        return (int) $fila->id;
    }

    /** El titular de un grupo vivo, para mirar qué se lleva por delante borrarlo. */
    private function profesorTitularDeUnGrupo(): object
    {
        $fila = DB::selectOne(
            'SELECT g.id AS grupo_id, p.id AS profesor_id
               FROM grupos g
               INNER JOIN profesores p ON p.id = g.titular_id AND p.deleted_at IS NULL
              WHERE g.deleted_at IS NULL ORDER BY g.id LIMIT 1'
        );

        $this->assertNotNull($fila, 'El seed no tiene ningún grupo con titular vivo.');

        return $fila;
    }

    /** Manda a la papelera escribiendo la columna, no llamando al `destroy` que se mide. */
    private function aLaPapelera(int $id): int
    {
        DB::update('UPDATE profesores SET deleted_at = ? WHERE id = ?', ['2026-08-23 01:00:00', $id]);

        return $id;
    }

    /**
     * Los ids que trae `profesores/listado`, que devuelve `['year' => ..., 'profesores' => [...]]`.
     *
     * @param  array<string, mixed>|null  $cuerpo
     * @return array<int, int>
     */
    private function idsDelListado(?array $cuerpo): array
    {
        $profesores = $cuerpo['profesores'] ?? [];

        return array_map(static fn ($p) => (int) $p['id'], $profesores);
    }

    /** Igual que en `PapeleraRestaurarTest`: por la columna, que es lo que el código pregunta. */
    private function tokenDeUnSuperusuario(): string
    {
        $jefe = DB::selectOne('SELECT u.username FROM users u
            INNER JOIN periodos p ON p.id = u.periodo_id
            WHERE u.is_superuser = 1 AND u.is_active = 1 AND u.deleted_at IS NULL
            ORDER BY u.id LIMIT 1');

        $this->assertNotNull($jefe, 'El seed no tiene ningún superusuario con contexto completo.');

        return $this->tokenDe($jefe->username);
    }
}
