<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * A nadie se le inventa un correo: quien no tiene, se queda sin él.
 *
 * Hasta el 20 sep 2026, `sanarInputUser` de `AlumnosController` y de
 * `ProfesoresController` tenía un `else` que ponía `username@myvc.com` cuando no
 * venía ningún correo. Se quitó ese día por decisión de Joseth, con lo que
 * costaba delante.
 *
 * ## Por qué un buzón inventado es peor que ninguno
 *
 * `users.email` es por donde busca la recuperación de contraseña
 * —`LoginController:240-266`, cuatro consultas y las cuatro sobre esa columna—.
 * Con un correo inventado el método **encuentra** la cuenta, manda el enlace a un
 * dominio que no es de nadie y contesta `Enviado`. Cambia «no llega» por «no
 * llega y además creemos que sí», y esa segunda forma no la detecta nadie porque
 * la pantalla dice lo mismo que cuando sale bien.
 *
 * Medido en el docker ese día: **30 cuentas vivas con `@myvc.com`, 16 activas**
 * —11 profesores, 2 alumnos, 3 sin ficha—. De los 12 profesores a los que el
 * reseteo llega, **11 no pueden recuperar nada**. *Alcanzable no es recuperable.*
 *
 * **Las 30 que ya existen se quedan**, decidido el mismo día y con esos números
 * delante: está sabido y no es un olvido. Este test no las mira; mira que no
 * nazcan más.
 *
 * ## Y cubre la EDICIÓN, que es la mitad que no se ve
 *
 * El `else` no sólo corría en el alta. Si una pantalla mandaba la clave `email2`
 * vacía, `ConvertEmptyStringsToNull` la dejaba en null, `!input('email2')` era
 * cierto y se fabricaba uno — y como `$vinieron->trae('email2')` contesta que sí
 * *(la clave vino)*, ese correo inventado **se escribía encima del que hubiera**.
 * O sea que vaciar el campo a propósito no vaciaba: sustituía. Ahora se respeta.
 */
class SinCorreoNoSeInventaNingunoTest extends CasoDeContrato
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

    /** En toda la base no puede quedar ningún camino que fabrique este dominio. */
    public function test_ningun_controlador_construye_ya_el_dominio_inventado(): void
    {
        $encontrados = [];

        foreach (['AlumnosController.php', 'ProfesoresController.php', 'AcudientesController.php'] as $fichero) {
            $ruta = app_path('Http/Controllers/'.$fichero);
            $lineas = file($ruta);
            $this->assertNotFalse($lineas, 'No se pudo leer '.$fichero);

            foreach ($lineas as $n => $linea) {
                // Se busca la CONSTRUCCIÓN, no la palabra: los comentarios de esos
                // tres ficheros cuentan la historia de este invento y tienen que
                // poder seguir nombrándolo.
                if (preg_match('/^\s*[^\/*]*\.\s*[\'"]@myvc\.com[\'"]/', $linea)) {
                    $encontrados[] = $fichero.':'.($n + 1);
                }
            }
        }

        $this->assertSame([], $encontrados,
            'Alguien volvió a fabricar un correo. El reseteo encontraría esa cuenta y '
            .'contestaría «Enviado» mandando el enlace a un buzón de nadie.');
    }

    /**
     * Un profesor NUEVO sin correo nace sin correo.
     *
     * Va por `profesores/store` y no por `update` ni por `guardar-valor`, y eso lo
     * decidió una medición, no el gusto. Las dos versiones anteriores de este test
     * **pasaban con el `else` puesto**, o sea que no medían nada:
     *
     * - `guardar-valor` ni siquiera llama a `sanarInputUser`.
     * - `update` sí lo llama, pero luego escribe el correo **sólo si la clave
     *   `email2` vino en la petición** (`$vinieron->trae('email2')`). Si no mandas
     *   esa clave, el invento se fabrica y no se escribe.
     *
     * El alta no tiene esa guarda: `postStore` asigna `Request::input('email2')` a
     * pelo, así que es donde el `else` sí dejaba el buzón inventado en la base.
     *
     * *Un test verde que pasaría igual sin el arreglo es peor que no tenerlo, porque
     * hace archivar el asunto. Éste se comprobó volviendo a poner el `else`.*
     */
    public function test_un_profesor_nuevo_sin_correo_no_estrena_uno_inventado(): void
    {
        $usuario = 'profe.sincorreo.'.random_int(100000, 999999);

        $this->withToken($this->tokenDelSuperusuario())
            ->postJson('/api/profesores/store', [
                'nombres' => 'Profesor',
                'apellidos' => 'Sin Correo',
                'sexo' => 'M',
                'username' => $usuario,
                'tipo_profesor' => 'Catedrático',
                // Ni `email` ni `email2`: es exactamente el caso del `else`.
            ]);

        $correo = (string) DB::table('users')->where('username', $usuario)->value('email');

        $this->assertStringNotContainsString('@myvc.com', $correo,
            'Un profesor nuevo sin correo estrenaba un buzón inventado, y con él el '
            .'reseteo encuentra la cuenta y contesta «Enviado» sin entregar nada.');
    }

    /**
     * El SEGUNDO invento, que no lo fabricábamos nosotros y que pasa por la rama buena.
     *
     * El alta de la aplicación **vieja** manda `email: '@gmail.com'` cuando no se
     * teclea correo, copiando a `formatear_nuevo`. Como es una cadena **no vacía**,
     * pasaba el `if (Request::input('email'))` que dejamos en pie al quitar el `else`
     * — o sea que cerrar aquel grifo no cerraba éste.
     *
     * Y es cuarenta veces más grande: **678 cuentas vivas** lo llevan frente a 16
     * activas con `@myvc.com`. De los 853 alumnos a los que la recuperación llega,
     * 655 son éstos y sólo 196 tienen un correo de verdad.
     *
     * `app/` no se toca por decisión de Joseth, así que esa pantalla va a seguir
     * mandándolo: la regla vive donde el dato entra (`CorreoDeLaCuenta`), no en quien
     * lo manda. **La ficha sí lo conserva** — sólo tiene regla la columna que es la
     * llave del reseteo.
     */
    public function test_una_cadena_sin_nada_delante_de_la_arroba_no_llega_a_la_cuenta(): void
    {
        $usuario = 'profe.arroba.'.random_int(100000, 999999);

        $this->withToken($this->tokenDelSuperusuario())
            ->postJson('/api/profesores/store', [
                'nombres' => 'Profesor',
                'apellidos' => 'Arroba Suelta',
                'sexo' => 'M',
                'username' => $usuario,
                'tipo_profesor' => 'Catedrático',
                'email' => '@gmail.com',
            ]);

        $fila = DB::table('users')->where('username', $usuario)->first();
        $this->assertNotNull($fila, 'No se creó el profesor.');

        $this->assertNull($fila->email,
            'El literal `@gmail.com` llegó a `users.email`. Ahí el reseteo lo ENCUENTRA '
            .'y contesta «Enviado» sin entregar nada: 678 cuentas vivas están así.');

        $this->assertSame('@gmail.com', DB::table('profesores')
            ->where('user_id', $fila->id)->value('email'),
            'La ficha sí lo conserva: la regla es de la cuenta, no del dato de contacto.');
    }

    /**
     * Y el caso que no se ve: mandar el campo VACÍO a propósito.
     *
     * Antes esto no vaciaba — sustituía por el inventado, porque
     * `$vinieron->trae('email2')` contesta que sí *(la clave vino)* aunque el valor
     * fuera nulo. Es la mitad de la decisión que no está en el alta.
     */
    public function test_vaciar_el_correo_a_proposito_no_lo_sustituye_por_uno_inventado(): void
    {
        $profesor = DB::selectOne('SELECT p.*, u.username FROM profesores p
            INNER JOIN users u ON u.id = p.user_id AND u.deleted_at IS NULL
            WHERE p.deleted_at IS NULL ORDER BY p.id LIMIT 1');
        $this->assertNotNull($profesor, 'El seed no tiene ningún profesor con cuenta.');

        DB::update('UPDATE users SET email = ? WHERE id = ?', ['algo@ejemplo.com', $profesor->user_id]);

        $this->withToken($this->tokenDelSuperusuario())
            ->putJson('/api/profesores/update/'.$profesor->id, [
                'nombres' => $profesor->nombres,
                'apellidos' => $profesor->apellidos,
                'username' => $profesor->username,
                'user_id' => $profesor->user_id,
                'sexo' => $profesor->sexo,
                'email2' => '',
            ]);

        $correo = (string) DB::table('users')->where('id', $profesor->user_id)->value('email');

        $this->assertStringNotContainsString('@myvc.com', $correo,
            'Vaciar el campo escribía el correo inventado encima del que había.');
    }
}
