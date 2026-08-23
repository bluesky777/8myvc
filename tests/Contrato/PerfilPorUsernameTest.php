<?php

namespace Tests\Contrato;

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
        $personal = $this->usuarioLlanoDelPersonal();

        $otro = DB::selectOne('SELECT username FROM users
            WHERE id <> ? AND deleted_at IS NULL AND username <> "" ORDER BY id LIMIT 1', [$personal->id]);

        $this->perfilDe($this->tokenDe($personal->username), $otro->username)->assertStatus(200);
    }

    /**
     * Y lo que había detrás de la ruta, que no era del guard: **un 500 tapando una
     * fuga**. Arreglado el 24 ago 2026; esto queda fijando el arreglo.
     *
     * La consulta grande cubre profesores, alumnos y usuarios sin ficha. Un
     * username que no sea ninguno de esos —**un acudiente**, o uno que no
     * existe— cae a una segunda consulta, y esa consulta tenía dos fallos
     * encadenados en los que **el segundo tapaba al primero**:
     *
     *   · no filtraba por el nombre: su `WHERE` era sólo `ac.deleted_at is null`,
     *     así que devolvía **los mil acudientes del colegio** con documento,
     *     fecha de nacimiento, correo personal y correo de recuperación;
     *   · y se le pasaba un `:username` que no aparecía en el SQL, así que PDO
     *     lanzaba «Invalid parameter number» antes de ejecutarla. **500** para
     *     todo acudiente y todo nombre inventado —1.000 de las 1.067 cuentas de
     *     la base local—, y por eso el `abort(400)` del final era inalcanzable.
     *
     * O sea que **lo único que impedía la fuga era el fallo de binding**, y el
     * arreglo que sugiere el mensaje de error —quitar el parámetro que sobra— es
     * exactamente el que abre la puerta. El que se hizo es el otro: añadir
     * `and u.username = :username`, que es lo que hacen sus tres consultas
     * hermanas.
     *
     * **Lo que este test mira es el tamaño de la respuesta, no su código.** Un
     * 200 aquí no distingue «el perfil del acudiente» de «los mil acudientes»:
     * las dos cosas son 200 con un array dentro. Por eso se cuenta la fila y se
     * comprueba de quién es — que es el criterio de los tests de contrato, mirar
     * el resultado y no el estado.
     */
    public function test_un_acudiente_recibe_su_perfil_y_no_el_de_los_demas(): void
    {
        $acudiente = DB::selectOne('SELECT u.username FROM acudientes ac
            INNER JOIN users u ON u.id = ac.user_id AND u.deleted_at IS NULL
            WHERE ac.deleted_at IS NULL AND u.username <> "" LIMIT 1');

        $this->assertNotNull($acudiente, 'El seed necesita un acudiente con cuenta.');

        $cuantos = (int) DB::selectOne('SELECT COUNT(*) AS n FROM acudientes ac
            INNER JOIN users u ON u.id = ac.user_id AND u.deleted_at IS NULL
            WHERE ac.deleted_at IS NULL')->n;

        $this->assertGreaterThan(
            1,
            $cuantos,
            'Con un solo acudiente en el seed este test no distingue el arreglo de la fuga.'
        );

        $personal = $this->usuarioDeTipo('Usuario');

        $cuerpo = $this->perfilDe($this->tokenDe($personal->username), $acudiente->username)
            ->assertStatus(200)
            ->json();

        $this->assertCount(
            1,
            $cuerpo,
            'La rama de acudientes volvió a devolver más de una fila: son '.$cuantos.' en la base. '
            .'Si el WHERE por username se cayó, esta ruta está entregando el directorio entero.'
        );

        $this->assertSame($acudiente->username, $cuerpo[0]['username']);
    }

    /**
     * Y el `abort(400)` del final **se alcanza por primera vez**.
     *
     * Se fija el **400** y no el 404 que pediría CLAUDE.md para código nuevo:
     * esto no es código nuevo, es una respuesta que hasta hoy nadie había visto
     * porque el 500 llegaba antes. Cambiarla es una decisión sobre lo que ya
     * leen cuatro clientes, y va aparte.
     */
    public function test_un_nombre_inventado_contesta_400_en_vez_de_reventar(): void
    {
        $personal = $this->usuarioDeTipo('Usuario');

        $this->perfilDe($this->tokenDe($personal->username), 'no.existe.'.uniqid())
            ->assertStatus(400);
    }
}
