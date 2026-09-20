<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * El correo de la CUENTA y el de la FICHA son dos, y sólo uno sirve para entrar.
 *
 * `acudientes.email` es la ficha; `users.email` es la cuenta. **La recuperación
 * de contraseña sólo mira la cuenta**: `LoginController:240-266` busca cuatro
 * veces y las cuatro por `users.email`.
 *
 * Hasta el 20 sep 2026 el front sólo escribía la ficha y `AcudientesController`
 * no copiaba nada, así que la cuenta nacía sin correo. Medido en el docker ese
 * día: de 1.085 acudientes vivos, **100 con correo de ficha y 0 de cuenta**. Y
 * como el reseteo contesta `Enviado` exista o no el correo —a propósito, para no
 * filtrar qué direcciones hay—, los mil veían la misma pantalla que si hubiera
 * salido.
 *
 * Estos tests fijan las dos mitades de la decisión de Joseth del 20 sep:
 *
 * 1. **se copia** la ficha a la cuenta cuando el cliente no manda `email2`, y
 * 2. **no se inventa nada** cuando no hay correo ninguno.
 *
 * La segunda es la que hay que defender con un test, porque la salida barata era
 * copiar entera la red de `AlumnosController:496` y `ProfesoresController:248`,
 * que en ese caso ponen `username@myvc.com`. Eso hace que el reseteo encuentre la
 * cuenta, mande el enlace a un buzón de nadie y conteste «Enviado»: cambia «no
 * llega» por «no llega y además creemos que sí». Ya le pasa a 16 cuentas vivas,
 * 11 de ellas de profesores.
 */
class ElCorreoDeLaCuentaDelAcudienteTest extends CasoDeContrato
{
    private function tokenDelSuperusuario(): string
    {
        $fila = DB::selectOne('SELECT u.username FROM users u
            INNER JOIN periodos p ON p.id = u.periodo_id
            WHERE u.tipo = "Usuario" AND u.is_superuser = 1 AND u.is_active = 1
              AND u.deleted_at IS NULL ORDER BY u.id LIMIT 1');

        $this->assertNotNull($fila, 'El seed no tiene ningún superusuario con periodo.');

        return $this->tokenDe($fila->username);
    }

    /** @return array<string, mixed> */
    private function cuerpoDeAcudiente(): array
    {
        $alumno = DB::selectOne('SELECT id FROM alumnos WHERE deleted_at IS NULL ORDER BY id LIMIT 1');

        return [
            'nombres' => 'Acudiente', 'apellidos' => 'Del Correo', 'sexo' => 'F',
            'documento' => (string) random_int(800000000, 899999999),
            'celular' => '3000000000',
            'alumno_id' => $alumno->id,
            'parentesco' => ['parentesco' => 'Madre'],
        ];
    }

    /** @return object{acudiente: object, usuario: object} */
    private function crear(array $cuerpo): object
    {
        $this->withToken($this->tokenDelSuperusuario())
            ->postJson('/api/acudientes/crear', $cuerpo)->assertStatus(200);

        $acudiente = DB::selectOne('SELECT * FROM acudientes WHERE documento = ?', [$cuerpo['documento']]);
        $this->assertNotNull($acudiente, 'No se creó el acudiente.');

        $usuario = DB::selectOne('SELECT * FROM users WHERE id = ?', [$acudiente->user_id]);
        $this->assertNotNull($usuario, 'El acudiente se creó sin cuenta.');

        return (object) ['acudiente' => $acudiente, 'usuario' => $usuario];
    }

    /**
     * Lo que el front manda hoy es `email` a secas, y con eso tiene que quedar
     * escrito en los dos sitios.
     */
    public function test_el_correo_de_la_ficha_llega_tambien_a_la_cuenta(): void
    {
        $cuerpo = $this->cuerpoDeAcudiente();
        $cuerpo['email'] = 'madre.'.$cuerpo['documento'].'@ejemplo.com';

        $creado = $this->crear($cuerpo);

        $this->assertSame($cuerpo['email'], $creado->acudiente->email,
            'La ficha tiene que seguir guardando lo suyo.');
        $this->assertSame($cuerpo['email'], $creado->usuario->email,
            'Sin esto la cuenta nace sin correo y su dueño no puede recuperar la contraseña.');
    }

    /**
     * Y ésta es la mitad que hay que defender: sin correo, la cuenta se queda
     * VACÍA. Nada de `username@myvc.com`.
     */
    public function test_sin_correo_la_cuenta_nace_vacia_y_no_con_uno_inventado(): void
    {
        $creado = $this->crear($this->cuerpoDeAcudiente());

        $this->assertTrue(
            $creado->usuario->email === null || trim((string) $creado->usuario->email) === '',
            'Un acudiente sin correo tiene que quedarse sin correo de cuenta: es la decisión '
            .'de Joseth del 20 sep 2026, y lo contrario hace que el reseteo diga «Enviado» '
            .'mandando el enlace a un buzón de nadie.'
        );

        $this->assertStringNotContainsString('@myvc.com', (string) $creado->usuario->email,
            'La red de alumnos y profesores inventa este dominio. Aquí NO se copia esa mitad.');
    }

    /** Si el cliente manda los dos, cada uno va a su sitio y no se pisan. */
    public function test_si_vienen_los_dos_cada_correo_va_a_su_columna(): void
    {
        $cuerpo = $this->cuerpoDeAcudiente();
        $cuerpo['email'] = 'ficha.'.$cuerpo['documento'].'@ejemplo.com';
        $cuerpo['email2'] = 'cuenta.'.$cuerpo['documento'].'@ejemplo.com';

        $creado = $this->crear($cuerpo);

        $this->assertSame($cuerpo['email'], $creado->acudiente->email);
        $this->assertSame($cuerpo['email2'], $creado->usuario->email,
            '`email2` es el de la cuenta y gana al de la ficha cuando viene.');
    }

    /**
     * La rejilla: `email2` escribe la CUENTA.
     *
     * Antes no había rama para esta propiedad y caía en el `default`, que hace
     * `UPDATE acudientes SET …` pasando por `ColumnaSegura::exigir()`. Como
     * `acudientes` no tiene ninguna columna `email2`, **no guardaba en el sitio
     * equivocado: reventaba**. Así que este test fija dos cosas a la vez — que
     * escribe, y dónde.
     */
    public function test_la_rejilla_escribe_el_correo_de_la_cuenta_sin_tocar_la_ficha(): void
    {
        $cuerpo = $this->cuerpoDeAcudiente();
        $cuerpo['email'] = 'ficha.'.$cuerpo['documento'].'@ejemplo.com';
        $creado = $this->crear($cuerpo);

        $nuevo = 'nuevo.'.$cuerpo['documento'].'@ejemplo.com';

        $this->withToken($this->tokenDelSuperusuario())
            ->putJson('/api/acudientes/guardar-valor', [
                'acudiente_id' => $creado->acudiente->id,
                'user_id' => $creado->acudiente->user_id,
                'propiedad' => 'email2',
                'valor' => $nuevo,
            ])->assertStatus(200);

        $this->assertSame($nuevo, DB::table('users')
            ->where('id', $creado->acudiente->user_id)->value('email'));

        $this->assertSame($cuerpo['email'], DB::table('acudientes')
            ->where('id', $creado->acudiente->id)->value('email'),
            'La ficha no se toca al editar el correo de la cuenta.');
    }

    /** Y `email` a secas sigue escribiendo la ficha, que es lo que hacía. */
    public function test_la_rejilla_con_email_sigue_escribiendo_la_ficha(): void
    {
        $cuerpo = $this->cuerpoDeAcudiente();
        $cuerpo['email'] = 'ficha.'.$cuerpo['documento'].'@ejemplo.com';
        $creado = $this->crear($cuerpo);

        $otro = 'otro.'.$cuerpo['documento'].'@ejemplo.com';

        $this->withToken($this->tokenDelSuperusuario())
            ->putJson('/api/acudientes/guardar-valor', [
                'acudiente_id' => $creado->acudiente->id,
                'user_id' => $creado->acudiente->user_id,
                'propiedad' => 'email',
                'valor' => $otro,
            ])->assertStatus(200);

        $this->assertSame($otro, DB::table('acudientes')
            ->where('id', $creado->acudiente->id)->value('email'));

        $this->assertSame($cuerpo['email'], DB::table('users')
            ->where('id', $creado->acudiente->user_id)->value('email'),
            'Editar la ficha no arrastra la cuenta: son dos correos y se editan por separado.');
    }

    /**
     * El otro camino que crea cuenta, y que no ponía correo NUNCA.
     *
     * `postCrearUsuario` le da cuenta a un acudiente que ya tenía ficha, así que
     * dejaba irrecuperables **incluso a los que sí tenían correo escrito**.
     */
    public function test_darle_cuenta_a_un_acudiente_que_ya_existe_le_copia_su_correo(): void
    {
        $cuerpo = $this->cuerpoDeAcudiente();
        $cuerpo['email'] = 'vieja.'.$cuerpo['documento'].'@ejemplo.com';
        $creado = $this->crear($cuerpo);

        // Se le quita la cuenta para reproducir al acudiente que no la tenía.
        DB::update('UPDATE acudientes SET user_id = NULL WHERE id = ?', [$creado->acudiente->id]);

        $this->withToken($this->tokenDelSuperusuario())
            ->postJson('/api/acudientes/crear-usuario', [
                'acudiente' => [
                    'id' => $creado->acudiente->id,
                    'nombres' => $cuerpo['nombres'],
                    'sexo' => $cuerpo['sexo'],
                ],
            ])->assertStatus(201);   // 201 y no 200: devuelve el modelo recién creado

        $userId = DB::table('acudientes')->where('id', $creado->acudiente->id)->value('user_id');
        $this->assertNotNull($userId, 'No le creó la cuenta.');

        $this->assertSame($cuerpo['email'], DB::table('users')->where('id', $userId)->value('email'),
            'La cuenta nueva tiene que nacer con el correo que la ficha ya tenía.');
    }
}
