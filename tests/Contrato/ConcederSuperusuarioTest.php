<?php

namespace Tests\Contrato;

use App\Support\Autoriza;
use Illuminate\Support\Facades\DB;

/**
 * Quién puede crear un superusuario.
 *
 * Cuatro sitios copiaban `is_superuser` del cuerpo de la petición sin mirar quién
 * la manda. El más caro es `POST api/profesores/store`, que solo pide
 * `auth.personal`: cualquiera de los 51 profesores creaba una cuenta de
 * superusuario con el nombre y la contraseña que quisiera y entraba con ella. No
 * hace falta tomar la cuenta de nadie — se fabrica una.
 *
 * Es la misma forma que la §29 y que la §28: **una decisión que el código no
 * llega a tomar**. Ver docs/migracion/05-codigo-muerto-y-roto.md §30.
 */
class ConcederSuperusuarioTest extends CasoDeContrato
{
    /** Un nombre de usuario que no exista, porque el controlador lo renombra si choca. */
    private function nombreLibre(string $prefijo): string
    {
        $i = 0;

        do {
            $nombre = $prefijo.$i++;
        } while (DB::table('users')->where('username', $nombre)->exists());

        return $nombre;
    }

    private function cuerpoDeProfesor(string $username, $superusuario): array
    {
        return [
            'nombres' => 'Prueba', 'apellidos' => 'De Escalada', 'sexo' => 'M',
            'num_doc' => '999000111', 'fecha_nac' => '1990-01-01',
            'tipo_profesor' => 'Tiempo completo',
            'username' => $username, 'password' => 'clave-1234', 'password2' => 'clave-1234',
            'is_superuser' => $superusuario,
        ];
    }

    /**
     * Un profesor no fabrica superusuarios.
     *
     * El profesor se crea igual —eso es lo que hace la ruta— pero su cuenta nace
     * con `is_superuser = 0`, no con lo que dijera el cuerpo.
     */
    public function test_un_profesor_no_crea_una_cuenta_de_superusuario(): void
    {
        $token = $this->tokenDe($this->usuarioDeTipo('Profesor')->username);
        $username = $this->nombreLibre('escalada_prof_');

        $r = $this->withToken($token)->postJson('/api/profesores/store',
            $this->cuerpoDeProfesor($username, 1));

        $r->assertStatus(201);

        $creado = DB::table('users')->where('username', $username)->first();
        $this->assertNotNull($creado, 'El profesor no llegó a crearse: el test no mide nada.');
        $this->assertSame(0, (int) $creado->is_superuser,
            'Un profesor creó una cuenta de superusuario pidiéndolo por el cuerpo.');
    }

    /** Y un superusuario sí, que es de quien es la decisión. */
    public function test_un_superusuario_si_crea_otro(): void
    {
        $superusuario = DB::selectOne('SELECT u.username FROM users u
            INNER JOIN periodos p ON p.id = u.periodo_id
            WHERE u.is_superuser = 1 AND u.is_active = 1 AND u.deleted_at IS NULL
            ORDER BY u.id LIMIT 1');
        $token = $this->tokenDe($superusuario->username);

        $username = $this->nombreLibre('escalada_super_');

        $this->withToken($token)->postJson('/api/profesores/store',
            $this->cuerpoDeProfesor($username, 1))->assertStatus(201);

        $this->assertSame(1,
            (int) DB::table('users')->where('username', $username)->value('is_superuser'));
    }

    /** Y si no lo pide, no lo concede aunque pueda. */
    public function test_sin_pedirlo_la_cuenta_nace_normal(): void
    {
        $superusuario = DB::selectOne('SELECT u.username FROM users u
            INNER JOIN periodos p ON p.id = u.periodo_id
            WHERE u.is_superuser = 1 AND u.is_active = 1 AND u.deleted_at IS NULL
            ORDER BY u.id LIMIT 1');
        $token = $this->tokenDe($superusuario->username);

        $username = $this->nombreLibre('escalada_normal_');

        $cuerpo = $this->cuerpoDeProfesor($username, 0);
        unset($cuerpo['is_superuser']);

        $this->withToken($token)->postJson('/api/profesores/store', $cuerpo)->assertStatus(201);

        $creado = DB::table('users')->where('username', $username)->first();
        $this->assertSame(0, (int) $creado->is_superuser);
    }

    /**
     * La regla, preguntada a `Autoriza` en vez de copiada aquí.
     *
     * Los tres tests de arriba miran el efecto en cuatro rutas; éste mira la
     * regla, que es la que no debe poder torcerse sin que falle algo. Y de paso
     * fija el tipo: devuelve `int` y no el `false` de PHP que metía
     * `sanarInputUser()` con su `Request::merge(['is_superuser' => false])`, que
     * es la familia de la §13 —el mismo campo saliendo como `false` en la
     * respuesta que lo crea y como `0` en cualquier lectura posterior—.
     */
    public function test_la_regla_es_la_de_autoriza(): void
    {
        $superusuario = (object) ['is_superuser' => 1];
        $profesor = (object) ['is_superuser' => 0, 'tipo' => 'Profesor'];

        $this->assertSame(1, Autoriza::concederSuperusuario($superusuario, 1));
        $this->assertSame(0, Autoriza::concederSuperusuario($superusuario, 0));
        $this->assertSame(0, Autoriza::concederSuperusuario($superusuario, null));

        $this->assertSame(0, Autoriza::concederSuperusuario($profesor, 1),
            'Si esto devuelve 1, las cuatro rutas vuelven a fabricar superusuarios.');
        $this->assertSame(0, Autoriza::concederSuperusuario($profesor, true));
        $this->assertSame(0, Autoriza::concederSuperusuario($profesor, '1'));
    }

    /**
     * Y por el otro camino: crear un alumno tampoco concede el permiso.
     *
     * `alumnos/store` está detrás de «profesor con `profes_can_edit_alumnos`», y
     * un usuario de tipo Alumno con `is_superuser` no es inofensivo:
     * `perfiles/reset-password/{id}` no lleva `auth.personal`, y su primera
     * comprobación es `if (!$user->is_superuser)`. La cadena entera era:
     * profesor con la bandera → alumno superusuario → contraseña de cualquiera.
     */
    public function test_crear_un_alumno_tampoco_concede_el_permiso(): void
    {
        $profesor = $this->usuarioDeTipo('Profesor');

        // Entrar primero, y leer el año DESPUÉS: `Services\Login` reescribe
        // `users.periodo_id` al periodo actual en cada inicio de sesión, así que
        // el año de antes de entrar no es el que mira el controlador. Poner la
        // bandera en el año equivocado da un 400 que parece del candado nuevo.
        $this->tokenDe($profesor->username);
        $year = DB::selectOne('SELECT p.year_id FROM periodos p
            INNER JOIN users u ON u.periodo_id = p.id WHERE u.id = ?', [$profesor->id]);
        DB::table('years')->where('id', $year->year_id)->update(['profes_can_edit_alumnos' => 1]);

        $token = $this->tokenDe($profesor->username);
        $username = $this->nombreLibre('escalada_alum_');

        $this->withToken($token)->postJson('/api/alumnos/store', [
            'nombres' => 'Prueba', 'apellidos' => 'De Escalada', 'sexo' => 'M',
            'documento' => '999000222', 'fecha_nac' => '2010-01-01',
            'fecha_matricula' => '2026-01-15', 'no_matricula' => '9990',
            'username' => $username, 'password' => 'clave-1234', 'password2' => 'clave-1234',
            'is_superuser' => 1,
        ]);

        $creado = DB::table('users')->where('username', $username)->first();

        $this->assertNotNull($creado,
            'El alumno no llegó a crearse: el test no mide nada.');
        $this->assertSame(0, (int) $creado->is_superuser,
            'Se creó un alumno con is_superuser pedido por el cuerpo.');
    }
}
