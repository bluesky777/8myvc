<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * Rutas que decían que sí cuando la respuesta era que no.
 *
 * No son un agujero: las tres **sí** frenan la escritura. Lo que hacían mal era
 * **contarlo**: respondían 200 —una con `['Guardado.']`, otra con el cuerpo
 * vacío, la tercera con la cadena «No tiene permisos» dentro de un 200— y el
 * cliente no tiene forma de distinguir eso de un éxito. Está comprobado que el
 * front no la tiene: `FileManagerCtrl.publicarImagen` enseña «Ahora la imagen es
 * pública» en cualquier respuesta correcta.
 *
 * **Una respuesta que miente es peor que un error**, porque el que la lee deja de
 * mirar. Es la misma familia que los doce `abort()` de la §12 y que el
 * `response()->json()` sin `return` de la §35, dicha por tercera vez.
 *
 * Se encontraron con un buscador de forma, no leyendo: métodos cuyo primer
 * statement es un `if` de permiso que **abarca el cuerpo entero y no tiene
 * `else`**. Ver docs/migracion/05-codigo-muerto-y-roto.md §37.
 */
class RespuestasQueMientenTest extends CasoDeContrato
{
    /**
     * Un profesor que edita a otro profesor recibía 200 y el cuerpo vacío.
     *
     * Se comprueban las dos mitades: el 403 y que la fila **no se movió**, que es
     * lo que ya pasaba antes y lo que no debe cambiar.
     */
    public function test_editar_a_otro_profesor_lo_dice_en_vez_de_callarlo(): void
    {
        $token = $this->tokenDe($this->usuarioDeTipo('Profesor')->username);

        $otro = DB::selectOne('SELECT id, nombres, telefono FROM profesores
            WHERE deleted_at IS NULL ORDER BY id LIMIT 1');

        $this->withToken($token)->putJson('/api/profesores/update/'.$otro->id, [
            'nombres' => 'Reescrito',
            'apellidos' => 'Por Otro',
            'telefono' => '000',
        ])->assertStatus(403);

        $this->assertSame($otro->nombres,
            DB::table('profesores')->where('id', $otro->id)->value('nombres'),
            'El profesor se reescribió, que es peor de lo que se pensaba.');
    }

    /** Y `guardar-valor`, que respondía literalmente «Guardado.» sin guardar. */
    public function test_guardar_valor_de_un_profesor_ya_no_dice_guardado_sin_guardar(): void
    {
        $token = $this->tokenDe($this->usuarioDeTipo('Profesor')->username);

        $victima = DB::selectOne('SELECT u.id, u.is_active FROM users u
            INNER JOIN profesores p ON p.user_id = u.id AND p.deleted_at IS NULL
            WHERE u.deleted_at IS NULL ORDER BY u.id LIMIT 1');

        $r = $this->withToken($token)->putJson('/api/profesores/guardar-valor', [
            'user_id' => $victima->id,
            'propiedad' => 'is_active',
            'valor' => 0,
        ]);

        $r->assertStatus(403);
        $this->assertStringNotContainsString('Guardado', (string) $r->getContent());

        $this->assertEquals($victima->is_active,
            DB::table('users')->where('id', $victima->id)->value('is_active'));
    }

    /**
     * Publicar una imagen siendo familia: la respuesta ya no parece un éxito.
     *
     * Es la que más se nota, porque el botón está en la pestaña «Mis imágenes»,
     * que ven los cuatro tipos de usuario. Un alumno lo pulsaba, le decían que
     * ahora era pública, y seguía privada.
     */
    public function test_publicar_una_imagen_siendo_familia_es_un_403(): void
    {
        foreach (['Alumno', 'Acudiente'] as $tipo) {
            $usuario = $this->usuarioDeTipo($tipo);

            $imagen = DB::table('images')->insertGetId([
                'nombre' => 'privada-de-prueba.png',
                'publica' => 0,
                'user_id' => $usuario->id,
                'created_by' => $usuario->id,
            ]);

            $this->withToken($this->tokenDe($usuario->username))
                ->putJson('/api/myimages/publicar-imagen/'.$imagen)
                ->assertStatus(403);

            $this->assertEquals(0, DB::table('images')->where('id', $imagen)->value('publica'),
                "La imagen de un {$tipo} se publicó de verdad.");
        }
    }

    /** Y el personal la sigue publicando, que es de quien es el botón. */
    public function test_el_personal_sigue_publicando_su_imagen(): void
    {
        $usuario = $this->usuarioDeTipo('Profesor');

        $imagen = DB::table('images')->insertGetId([
            'nombre' => 'del-profesor.png',
            'publica' => 0,
            'user_id' => $usuario->id,
            'created_by' => $usuario->id,
        ]);

        $this->withToken($this->tokenDe($usuario->username))
            ->putJson('/api/myimages/publicar-imagen/'.$imagen)
            ->assertStatus(200);

        $this->assertEquals(1, DB::table('images')->where('id', $imagen)->value('publica'));
    }
}
