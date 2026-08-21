<?php

namespace Tests\Contrato;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * `GET api/perfiles/username/{username}` entregaba el perfil de cualquiera.
 *
 * La ruta devuelve `fecha_nac`, `email_persona` y **`email_restore`** —el correo
 * al que llega el enlace de reseteo— y no comprobaba de quién era: con un token
 * de alumno y el nombre de usuario de otro respondía 200 con la ficha dentro. Lo
 * volvió a sacar el barrido del 21 ago 2026, después de arreglar el reseteo, y
 * llevaba abierta desde siempre.
 *
 * De las tres salidas que estaban escritas en 05 §14.4 —sacar el username del
 * token, enseñarle el username al guard, o recortar las columnas— Joseth eligió
 * la segunda el 21 ago 2026, y es la que menos rompe: la ruta sigue aceptando
 * parámetro, el personal la usa igual y lo único que cambia es que una familia ya
 * no alcanza a nadie que no sea suyo.
 *
 * Lo que hace que esto sea un guard y no un `if` en el controlador es lo de
 * siempre: `persona.propia` ya sabe resolver «suyo» para las seis formas de
 * nombrar a una persona, incluida la de un acudiente sobre sus acudidos. Aquí
 * solo aprende una séptima —el nombre de usuario— y reusa lo demás.
 */
class PerfilPorUsernameTest extends CasoDeContrato
{
    private function perfilDe(string $token, string $username)
    {
        return $this->withToken($token)->getJson('/api/perfiles/username/'.rawurlencode($username));
    }

    public function test_un_alumno_ve_el_suyo(): void
    {
        $alumno = $this->usuarioDeTipo('Alumno');

        $this->perfilDe($this->tokenDe($alumno->username), $alumno->username)
            ->assertStatus(200);
    }

    /** Y no el de nadie más — que es lo que hacía hasta hoy. */
    public function test_un_alumno_no_ve_el_de_otro(): void
    {
        $alumno = $this->usuarioDeTipo('Alumno');

        $otro = DB::selectOne('SELECT username FROM users
            WHERE id <> ? AND deleted_at IS NULL AND username <> "" ORDER BY id LIMIT 1', [$alumno->id]);

        $this->assertNotNull($otro, 'El seed necesita otra cuenta.');

        $this->perfilDe($this->tokenDe($alumno->username), $otro->username)
            ->assertStatus(403)
            ->assertSee('Solo puedes consultar lo tuyo');
    }

    /**
     * Un acudiente sí ve el de su acudido, porque es la regla del colegio.
     *
     * No hay `if` nuevo para esto: el guard ya resolvía `user_id` contra la lista
     * de acudidos, y lo único que se añadió es traducir el nombre de usuario a
     * ese id. Si esto se pusiera rojo querría decir que la traducción se saltó el
     * camino de siempre.
     */
    public function test_un_acudiente_ve_el_de_su_acudido(): void
    {
        $fila = DB::selectOne('SELECT ua.username AS acudiente, ual.username AS acudido
            FROM parentescos p
            INNER JOIN acudientes ac ON ac.id = p.acudiente_id AND ac.deleted_at IS NULL
            INNER JOIN users ua ON ua.id = ac.user_id AND ua.deleted_at IS NULL AND ua.is_active = 1
            INNER JOIN alumnos al ON al.id = p.alumno_id AND al.deleted_at IS NULL
            INNER JOIN users ual ON ual.id = al.user_id AND ual.deleted_at IS NULL
            WHERE p.deleted_at IS NULL LIMIT 1');

        $this->assertNotNull($fila, 'El seed necesita un acudiente con acudido.');

        $this->perfilDe($this->tokenDe($fila->acudiente), $fila->acudido)->assertStatus(200);
    }

    /** El personal pasa de largo: el guard solo estrecha a alumnos y acudientes. */
    public function test_el_personal_sigue_viendo_el_de_cualquiera(): void
    {
        $personal = $this->usuarioDeTipo('Usuario');

        $otro = DB::selectOne('SELECT username FROM users
            WHERE id <> ? AND deleted_at IS NULL AND username <> "" ORDER BY id LIMIT 1', [$personal->id]);

        $this->perfilDe($this->tokenDe($personal->username), $otro->username)->assertStatus(200);
    }

    /**
     * Y lo que hay detrás de la ruta, que no es del guard: **un 500 tapando una
     * fuga**.
     *
     * La consulta grande cubre profesores, alumnos y usuarios sin ficha. Un
     * username que no sea ninguno de esos —**un acudiente**, o uno que no
     * existe— cae a una segunda consulta, y esa consulta:
     *
     *   · no filtra por el nombre: su `WHERE` es solo `ac.deleted_at is null`,
     *     así que devolvería **los 1.000 acudientes del colegio** con documento,
     *     fecha de nacimiento, correo personal y correo de recuperación;
     *   · y se le pasa un `:username` que no aparece en el SQL, así que PDO
     *     lanza «Invalid parameter number» antes de ejecutarla. **500.**
     *
     * O sea que lo único que hoy impide que esta ruta entregue el directorio
     * entero de acudientes es un fallo de binding. Y el arreglo evidente —quitar
     * el parámetro que sobra, que es lo que sugiere el mensaje de error— es
     * exactamente el que abre la puerta. El que hay que hacer es el otro:
     * **añadir `and u.username = :username`**, que es lo que hacen sus tres
     * consultas hermanas.
     *
     * Se fija el 500 en vez de arreglarlo porque `PerfilesController` es de otra
     * sesión, y con la regla del proyecto: con ruta y roto, se documenta. Este
     * test se pondrá rojo el día que alguien lo toque — que es el día en que hay
     * que mirar si lo tocó por el lado bueno.
     *
     * Ver docs/migracion/12-larastan-nivel-7.md §19.
     */
    public function test_un_acudiente_o_un_nombre_inventado_revientan_en_la_segunda_consulta(): void
    {
        $personal = $this->usuarioDeTipo('Usuario');
        $token = $this->tokenDe($personal->username);

        $this->withoutExceptionHandling();

        $acudiente = DB::selectOne('SELECT u.username FROM acudientes ac
            INNER JOIN users u ON u.id = ac.user_id AND u.deleted_at IS NULL
            WHERE ac.deleted_at IS NULL AND u.username <> "" LIMIT 1');

        $this->assertNotNull($acudiente, 'El seed necesita un acudiente con cuenta.');

        foreach ([$acudiente->username, 'no.existe.'.uniqid()] as $nombre) {
            try {
                $this->perfilDe($token, $nombre);
                $this->fail('La segunda consulta de getUsername dejó de reventar con "'.$nombre.'". '
                    .'Si el arreglo fue quitar el parámetro que sobra, la ruta acaba de empezar a '
                    .'devolver los mil acudientes del colegio: hace falta el WHERE por username.');
            } catch (QueryException $e) {
                $this->assertStringContainsString('Invalid parameter number', $e->getMessage());
            }
        }
    }
}
