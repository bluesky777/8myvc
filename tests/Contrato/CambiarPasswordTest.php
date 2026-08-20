<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * `PUT api/perfiles/cambiarpassword/{id}`, que tiene dos `if` que siempre son
 * ciertos, con consecuencias opuestas.
 *
 * Los dos están escritos igual —`Request::has('x') || Request::has('x') == ''`—
 * y los dos son siempre ciertos por la misma razón: `has()` devuelve un
 * booleano, y en PHP `false == ''` es verdadero. Cuando el campo no viene, la
 * mitad izquierda es `false` y la derecha lo convierte en `true`.
 *
 * **El de `email_restore` borra datos.** El cuerpo del `if` es
 * `$perfil->email = Request::input('email_restore')`, y si el campo no vino eso
 * es `null`: cambiar la contraseña **vacía el correo de recuperación**, que es
 * justo con lo que se recupera la cuenta si se pierde la contraseña nueva. La
 * columna es `DEFAULT NULL`, así que el `save()` no protesta.
 *
 * **El de `oldpassword` es lo único que defiende el endpoint.** Al ser siempre
 * cierto, la comprobación de la contraseña antigua se hace SIEMPRE, también
 * cuando el cliente no manda el campo — y entonces `Hash::check('', $hash)`
 * falla y responde 400. Escrito como seguramente se pretendía,
 * `if (Request::has('oldpassword'))`, un token robado cambiaría la contraseña
 * **sin conocer la anterior**. Es un `if` de adorno que resultó ser la cerradura.
 *
 * `SuperficieDeUnAlumnoTest` ya dice que esta ruta «es la única de su familia
 * que se defiende». Estos dos tests fijan por qué, para que el día que alguien
 * simplifique la condición redundante se entere de lo que quita.
 */
class CambiarPasswordTest extends CasoDeContrato
{
    /** Alguien con correo puesto, para que borrarlo se note. */
    private function conCorreo(): object
    {
        $usuario = DB::selectOne('SELECT u.id, u.username, u.email, u.password FROM users u
            INNER JOIN periodos p ON p.id = u.periodo_id AND p.deleted_at IS NULL
            WHERE u.tipo = "Usuario" AND u.is_active = 1 AND u.deleted_at IS NULL
              AND u.email IS NOT NULL AND u.email <> "" ORDER BY u.id LIMIT 1');

        $this->assertNotNull($usuario, 'El seed no tiene ningún Usuario con correo.');

        return $usuario;
    }

    public function test_cambiar_la_password_no_borra_el_correo_de_recuperacion(): void
    {
        $usuario = $this->conCorreo();
        $cabeceras = ['Authorization' => 'Bearer '.$this->tokenDe($usuario->username)];

        // Sin `email_restore`: el cliente viene a cambiar la contraseña y no
        // menciona el correo para nada.
        $this->putJson("/api/perfiles/cambiarpassword/{$usuario->id}", [
            'oldpassword' => self::CLAVE,
            'password' => 'nueva-1234',
        ], $cabeceras)->assertStatus(200);

        $this->assertSame($usuario->email,
            DB::selectOne('SELECT email FROM users WHERE id = ?', [$usuario->id])->email,
            'Cambiar la contraseña ha borrado el correo de recuperación.');
    }

    public function test_sin_la_password_antigua_no_se_cambia_nada(): void
    {
        $usuario = $this->conCorreo();
        $cabeceras = ['Authorization' => 'Bearer '.$this->tokenDe($usuario->username)];

        $this->putJson("/api/perfiles/cambiarpassword/{$usuario->id}", [
            'password' => 'nueva-1234',
        ], $cabeceras)->assertStatus(400);

        $this->assertSame($usuario->password,
            DB::selectOne('SELECT password FROM users WHERE id = ?', [$usuario->id])->password,
            'La contraseña se cambió sin comprobar la antigua.');
    }
}
