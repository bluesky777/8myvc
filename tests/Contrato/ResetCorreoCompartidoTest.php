<?php

namespace Tests\Contrato;

use App\Mail\ResetPassword;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

/**
 * El enlace de reseteo abre **solo** la cuenta a la que se emitió.
 *
 * Este test nació fijando lo contrario. `putResetPassword` ataba la contraseña
 * nueva al correo del token, pero el `username` llegaba en el cuerpo de la
 * petición:
 *
 *     UPDATE users SET password=? WHERE username=? and email=? and deleted_at is null
 *
 * y `password_reminders` no guardaba a quién se le había emitido el token — solo
 * `email`, `token` y `created_at`—, así que el endpoint no tenía de dónde
 * sacarlo. El resultado era que un enlace abría **cualquier** cuenta que
 * compartiera ese correo: la protección existía y llegaba hasta el borde del
 * grupo. Medido: 16 cuentas en 8 grupos en la copia de desarrollo.
 *
 * Cerrado el 21 ago 2026 guardando el username al emitir. **El del cuerpo se
 * ignora, no se compara**: compararlo dejaría el mismo agujero con un paso más y
 * encima parecería arreglado.
 *
 * El test se queda con la misma forma y la expectativa cambiada, que es lo que
 * lo hace útil: si alguien vuelve a leer el username del cuerpo, esto se pone
 * rojo. Ver docs/migracion/12-larastan-nivel-7.md §8.
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

    public function test_el_token_de_un_correo_compartido_no_alcanza_a_la_otra_cuenta(): void
    {
        $cuentas = $this->dosCuentasConElMismoCorreo();

        $antesSegundo = DB::selectOne('SELECT password FROM users WHERE username = ?',
            [$cuentas->segundo])->password;

        // El enlace se emite para la PRIMERA cuenta: la consulta que resuelve el
        // username coge `[0]`. Aquí se pide el reseteo nombrando a la SEGUNDA.
        $numero = $this->pedirElEnlace($cuentas->correo);

        $this->putJson('/api/login/reset-password', [
            'numero' => $numero,
            'username' => $cuentas->segundo,
            'password1' => 'tomada-1234',
        ])->assertStatus(200);

        $this->assertSame($antesSegundo,
            DB::selectOne('SELECT password FROM users WHERE username = ?', [$cuentas->segundo])->password,
            'El token emitido para la primera cuenta cambió la clave de la segunda.');

        // Y la contraseña que sí cambia es la del dueño del token, porque el
        // `username` del cuerpo se ignora en vez de rechazarse: el enlace hace lo
        // que decía hacer, no falla.
        $this->assertTrue(
            Hash::check('tomada-1234',
                DB::selectOne('SELECT password FROM users WHERE username = ?', [$cuentas->primero])->password),
            'El token no cambió la clave de la cuenta a la que se emitió.');
    }

    /** Un token emitido antes de la migración no sabe a quién iba: se rechaza. */
    public function test_un_token_sin_usuario_guardado_no_vale(): void
    {
        $cuentas = $this->dosCuentasConElMismoCorreo();

        $antes = DB::selectOne('SELECT password FROM users WHERE username = ?',
            [$cuentas->primero])->password;

        $numero = $this->pedirElEnlace($cuentas->correo);

        // Así están las filas que ya estaban en la tabla el día del despliegue.
        DB::update('UPDATE password_reminders SET username = NULL WHERE token = ?',
            [hash('sha256', $numero)]);

        $this->putJson('/api/login/reset-password', [
            'numero' => $numero,
            'username' => $cuentas->primero,
            'password1' => 'tomada-1234',
        ])->assertStatus(200)->assertSee('Token inválido');

        $this->assertSame($antes,
            DB::selectOne('SELECT password FROM users WHERE username = ?', [$cuentas->primero])->password,
            'Un token sin usuario guardado cambió una contraseña.');
    }

    /**
     * Y a una cuenta de otro correo tampoco, que ya era cierto antes del arreglo.
     *
     * Se conserva porque es el control: si algún día se rompiera **esto**, el
     * fallo sería de otra clase y mucho peor que el que cerró la §8.
     *
     * **Aquí se ve el único cambio de contrato del arreglo.** Antes, nombrar en el
     * cuerpo a una cuenta que no era la del token dejaba el UPDATE en cero filas y
     * la respuesta era «Token inválido». Ahora el cuerpo se ignora, así que el
     * enlace hace lo que decía hacer —resetea a su dueño— y responde «Reseteado».
     * `LoginCtrl.ts` manda `$stateParams.username`, que sale del enlace que
     * construyó el propio backend, así que para el cliente real las dos cuentas
     * siempre coinciden y el cambio no se nota. El «Token inválido» que el front
     * sí sabe leer se sigue devolviendo donde importa: token caducado, token
     * desconocido y token sin usuario guardado.
     */
    public function test_tampoco_alcanza_a_una_cuenta_con_otro_correo(): void
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
        ])->assertStatus(200);

        $this->assertSame($antes,
            DB::selectOne('SELECT password FROM users WHERE username = ?', [$ajeno->username])->password,
            'El token alcanzó a una cuenta que NO comparte el correo.');
    }

    /**
     * La otra cara del arreglo, y por eso está escrita como test y no como nota:
     * **la segunda cuenta de un correo compartido ya no puede pedir un enlace
     * para sí**.
     *
     * `postRecuperarClave` recibe solo el correo —el formulario de «olvidé mi
     * contraseña» del front manda `{email, ruta}` y nada más— y se queda con
     * `$persona[0]`, la cuenta de id más bajo. Antes, la segunda llegaba a
     * cambiar su contraseña nombrándose en el cuerpo al canjear, que era
     * exactamente el agujero que se cerró: **el arreglo le quitó la única vía que
     * tenía**. Son 8 cuentas en la copia de desarrollo, todas hermanos con el
     * correo de un padre, y las cuenta `usuarios:correos-compartidos`.
     *
     * No se «arregla» aquí porque no es un fallo: elegir a cuál de las dos va el
     * enlace es una decisión, y toca a los cuatro clientes. Lo que sí es un fallo
     * sería que dejara de notarse.
     */
    public function test_la_segunda_cuenta_no_puede_pedir_un_enlace_para_si(): void
    {
        $cuentas = $this->dosCuentasConElMismoCorreo();

        $numero = $this->pedirElEnlace($cuentas->correo);

        $fila = DB::selectOne('SELECT username FROM password_reminders WHERE token = ?',
            [hash('sha256', $numero)]);

        $this->assertNotNull($fila, 'No se guardó el token que se acaba de emitir.');

        $this->assertSame($cuentas->primero, $fila->username,
            'El enlace de un correo compartido se emite para la cuenta de id más bajo.');

        $this->assertNotSame($cuentas->segundo, $fila->username,
            'Si esto cambia, la segunda cuenta ya puede recuperar: se decidió algo y hay que contarlo.');
    }
}
