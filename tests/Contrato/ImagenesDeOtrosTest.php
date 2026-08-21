<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * Las cinco rutas que cambian la imagen o la firma **de otra persona**.
 *
 * Salieron de la lista de cobertura del 21 ago 2026 y todas llevaban lo mismo:
 * `auth.personal`, o sea cualquiera de los 51 profesores. Y todas viven, en el
 * front, dentro de la pestaña «Imágenes de usuarios» del gestor de archivos, que
 * la plantilla enseña con `ng-if="hasRoleOrPerm('admin')"`.
 *
 * Es exactamente la situación de la §29.3 —**el backend dos escalones por debajo
 * de su propia pantalla**— y se cierra con la decisión que allí ya se tomó, no
 * con una nueva. Lo que hace que no rompa nada es que a quien no es admin el
 * front no le enseña ni el botón.
 *
 * La que más pesa no es la firma sino el logo: sale en **cada boletín y cada
 * certificado** del colegio.
 *
 * Ver docs/migracion/05-codigo-muerto-y-roto.md §36.
 */
class ImagenesDeOtrosTest extends CasoDeContrato
{
    /**
     * Las cinco, con el cuerpo que espera cada una.
     *
     * Se comparan con `assertSame` sobre `getStatusCode()` y no con
     * `assertStatus()` porque este test recorre cinco rutas en un bucle y hace
     * falta que el fallo diga **cuál**. `assertStatus()` solo acepta un
     * parámetro: el mensaje que se le pasa de más **PHP se lo traga en silencio**,
     * así que el test parecía llevar mensajes y no llevaba ninguno. Lo dijo
     * larastan, no la corrida.
     */
    private function rutas(): array
    {
        $profesor = DB::selectOne('SELECT id FROM profesores WHERE deleted_at IS NULL ORDER BY id LIMIT 1');
        $alumno = DB::selectOne('SELECT id FROM alumnos WHERE deleted_at IS NULL ORDER BY id LIMIT 1');
        $usuario = DB::selectOne('SELECT id FROM users WHERE deleted_at IS NULL ORDER BY id LIMIT 1');
        $imagen = DB::selectOne('SELECT id FROM images WHERE deleted_at IS NULL ORDER BY id LIMIT 1');

        $this->assertNotNull($imagen, 'El seed necesita alguna imagen.');

        return [
            'la firma de un profesor' => [
                'perfiles/cambiarfirmaunprofe/'.$profesor->id,
                ['imgFirmaProfe' => $imagen->id],
                fn () => DB::table('profesores')->where('id', $profesor->id)->value('firma_id'),
            ],
            'la foto de un profesor' => [
                'perfiles/cambiarimgunprofe/'.$profesor->id,
                ['imgOficialProfe' => $imagen->id],
                fn () => DB::table('profesores')->where('id', $profesor->id)->value('foto_id'),
            ],
            'la foto de un alumno' => [
                'perfiles/cambiarimgunalumno/'.$alumno->id,
                ['imgOficialAlumno' => $imagen->id],
                fn () => DB::table('alumnos')->where('id', $alumno->id)->value('foto_id'),
            ],
            'la imagen de un usuario' => [
                'perfiles/cambiarimgunusuario/'.$usuario->id,
                ['imgParaUsuario' => $imagen->id],
                fn () => DB::table('users')->where('id', $usuario->id)->value('imagen_id'),
            ],
            'el logo del colegio' => [
                'myimages/cambiarlogocolegio',
                ['imagen_id' => $imagen->id, 'img_id' => $imagen->id],
                fn () => DB::table('years')->where('actual', 1)->whereNull('deleted_at')->value('logo_id'),
            ],
        ];
    }

    /**
     * Un profesor cualquiera no le cambia la firma ni la foto a nadie.
     *
     * Se comprueban las dos mitades en cada una: el 403 **y que la columna no se
     * movió**. La firma de un profesor es la que aparece al pie de los informes
     * que él certifica; el logo sale en cada boletín.
     */
    public function test_un_profesor_no_cambia_la_imagen_de_otro(): void
    {
        $token = $this->tokenDe($this->usuarioDeTipo('Profesor')->username);

        foreach ($this->rutas() as $nombre => [$ruta, $cuerpo, $leer]) {
            $antes = $leer();

            $this->assertSame(403,
                $this->withToken($token)->putJson('/api/'.$ruta, $cuerpo)->getStatusCode(),
                "{$nombre}: la ruta dejó pasar a un profesor cualquiera.");

            $this->assertSame($antes, $leer(), "{$nombre}: la columna se escribió igual.");
        }
    }

    /** Y un administrativo sigue pudiendo, que es de quien es la pantalla. */
    public function test_un_superusuario_si_las_cambia(): void
    {
        $super = DB::selectOne('SELECT u.username FROM users u
            INNER JOIN periodos p ON p.id = u.periodo_id
            WHERE u.is_superuser = 1 AND u.is_active = 1 AND u.deleted_at IS NULL
            ORDER BY u.id LIMIT 1');

        $this->assertNotNull($super, 'El seed no tiene ningún superusuario.');

        $token = $this->tokenDe($super->username);

        foreach ($this->rutas() as $nombre => [$ruta, $cuerpo, $leer]) {
            $this->assertSame(200,
                $this->withToken($token)->putJson('/api/'.$ruta, $cuerpo)->getStatusCode(),
                "{$nombre}: el administrativo dejó de poder.");
        }
    }

    /** Una familia tampoco, que ya estaba cerrado y se fija de paso. */
    public function test_una_familia_tampoco(): void
    {
        foreach (['Alumno', 'Acudiente'] as $tipo) {
            $token = $this->tokenDe($this->usuarioDeTipo($tipo)->username);

            foreach ($this->rutas() as $nombre => [$ruta, $cuerpo, $leer]) {
                $this->assertSame(403,
                    $this->withToken($token)->putJson('/api/'.$ruta, $cuerpo)->getStatusCode(),
                    "{$nombre}: entró un {$tipo}.");
            }
        }
    }
}
