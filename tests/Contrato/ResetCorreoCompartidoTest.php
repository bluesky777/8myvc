<?php

namespace Tests\Contrato;

use App\Mail\ResetPassword;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

/**
 * El enlace de reseteo abre **cualquier** cuenta que comparta ese correo.
 *
 * `putResetPassword` ata la contraseña nueva al correo del token, pero el
 * `username` **sigue llegando en el cuerpo de la petición**:
 *
 *     UPDATE users SET password=? WHERE username=? and email=? and deleted_at is null
 *
 * Y `password_reminders` no guarda a quién se le emitió el token — solo tiene
 * `email`, `token` y `created_at`—, así que el endpoint no tiene de dónde
 * sacarlo. El comentario que hay encima de esa consulta dice que «el token
 * manda», y es verdad solo hasta el grupo de cuentas que comparten el correo:
 * dentro de ese grupo elige el cliente.
 *
 * Esto no es teoría en este proyecto. Ver
 * docs/migracion/12-larastan-nivel-7.md §8 para el recuento por colegio.
 *
 * El test fija **lo que hace hoy**, no lo que debería hacer: mientras esté en
 * rojo el día que alguien lo arregle, se sabrá que el arreglo llegó.
 */
class ResetCorreoCompartidoTest extends CasoDeContrato
{
    /** Dos usuarios activos con el mismo correo, y su correo. */
    private function dosCuentasConElMismoCorreo(): object
    {
        $correo = 'compartido.'.uniqid().'@ejemplo.test';

        $ids = DB::select('SELECT id FROM users WHERE deleted_at IS NULL AND is_active = 1
            ORDER BY id LIMIT 2');

        $this->assertCount(2, $ids, 'El seed necesita dos usuarios activos.');

        DB::update('UPDATE users SET email = ? WHERE id IN (?, ?)',
            [$correo, $ids[0]->id, $ids[1]->id]);

        $filas = DB::select('SELECT username FROM users WHERE id IN (?, ?) ORDER BY id',
            [$ids[0]->id, $ids[1]->id]);

        return (object) [
            'correo' => $correo,
            'primero' => $filas[0]->username,
            'segundo' => $filas[1]->username,
        ];
    }

    /** El `numero` que viaja en el enlace del correo, que es el token en claro. */
    private function pedirElEnlace(string $correo): string
    {
        Mail::fake();

        // 'ruta' es la base del enlace, y el endpoint exige que su host coincida
        // con el de la petición: si no, aborta con 422 antes de mirar el correo.
        $this->postJson('/api/login/recuperar-clave', [
            'email' => $correo,
            'ruta' => 'http://localhost/',
        ])->assertStatus(200);

        $enlace = null;

        Mail::assertSent(ResetPassword::class, function (ResetPassword $correoEnviado) use (&$enlace) {
            $enlace = $correoEnviado->enlace;

            return true;
        });

        $this->assertNotNull($enlace, 'No salió ningún correo de reseteo.');

        // …/#!/reset-password/{numero}/{username}
        $partes = explode('/', (string) $enlace);

        return $partes[count($partes) - 2];
    }

    public function test_el_token_de_un_correo_compartido_cambia_la_clave_de_la_otra_cuenta(): void
    {
        $cuentas = $this->dosCuentasConElMismoCorreo();

        // El enlace se emite para la PRIMERA cuenta: la consulta que resuelve el
        // username coge `[0]`. Aquí se usa contra la SEGUNDA.
        $numero = $this->pedirElEnlace($cuentas->correo);

        $this->putJson('/api/login/reset-password', [
            'numero' => $numero,
            'username' => $cuentas->segundo,
            'password1' => 'tomada-1234',
        ])->assertStatus(200);

        $hash = DB::selectOne('SELECT password FROM users WHERE username = ?',
            [$cuentas->segundo])->password;

        $this->assertTrue(Hash::check('tomada-1234', $hash),
            'El token emitido para la primera cuenta no cambió la clave de la segunda: '
            .'si esto falla, alguien ató el token a su usuario y hay que borrar este test.');
    }

    /**
     * Y con un correo que no es el del token, no. O sea que la protección existe
     * y llega exactamente hasta el borde del grupo que comparte correo.
     */
    public function test_pero_no_alcanza_a_una_cuenta_con_otro_correo(): void
    {
        $cuentas = $this->dosCuentasConElMismoCorreo();

        $ajeno = DB::selectOne('SELECT username FROM users
            WHERE deleted_at IS NULL AND is_active = 1 AND email != ? AND username NOT IN (?, ?)
            ORDER BY id LIMIT 1', [$cuentas->correo, $cuentas->primero, $cuentas->segundo]);

        $this->assertNotNull($ajeno, 'El seed necesita un tercer usuario con otro correo.');

        $antes = DB::selectOne('SELECT password FROM users WHERE username = ?', [$ajeno->username])->password;

        $numero = $this->pedirElEnlace($cuentas->correo);

        $this->putJson('/api/login/reset-password', [
            'numero' => $numero,
            'username' => $ajeno->username,
            'password1' => 'tomada-1234',
        ])->assertStatus(200)->assertSee('Token inválido');

        $this->assertSame($antes,
            DB::selectOne('SELECT password FROM users WHERE username = ?', [$ajeno->username])->password,
            'El token alcanzó a una cuenta que NO comparte el correo.');
    }
}
