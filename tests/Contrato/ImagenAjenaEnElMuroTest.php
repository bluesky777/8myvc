<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * §144 — Colgar en el muro del colegio la imagen privada de otro.
 *
 * `PublicacionesController::putStore()` y `putGuardarEdicion()` guardaban
 * `imagen.id` y `imagen.nombre` **tal como venían en el cuerpo**, sin preguntar
 * de quién es la imagen. Medido de punta a punta el 23 ago 2026:
 *
 *   1. un alumno manda `imagen: {id: 5, nombre: "imagen-5.jpg"}`, que tiene
 *      `publica IS NULL` y `user_id = 2` — de otra persona;
 *   2. la fila entra tal cual: `imagen_id=5, imagen_nombre="imagen-5.jpg"`;
 *   3. y `publicaciones/ultimas` **le sirve ese nombre a todo el mundo**,
 *      comprobado con el token de un profesor que no es el dueño.
 *
 * O sea que la imagen privada de cualquiera acaba publicada en el muro del
 * colegio sólo con nombrar su id.
 *
 * Es la familia de la [§53](../../docs/migracion/05-codigo-muerto-y-roto.md)
 * —donde `images-users/imagenes-de-usuario` soltaba 162 imágenes privadas de un
 * superusuario— con una diferencia que la empeora: **allí se listaban, aquí se
 * publican.**
 *
 * ## De dónde salió, que es lo que la hace del lote J
 *
 * De una anotación propia que decía, literal, *«no afirmo que filtre: afirmo que
 * no lo he mirado»*. Salió del barrido del lote J —las rutas abiertas que ningún
 * candado mira y ningún test juzga—, y quedó como candidato porque medirla
 * necesitaba la base y había seis `phpunit` vivos.
 *
 * > Un candidato anotado y no medido es trabajo a medias, no un resultado. El
 * > valor de escribirlo así es que **se puede volver**, y esto es la vuelta.
 *
 * ## La regla, que no se inventa aquí
 *
 * **Tuya, o pública.** Es exactamente lo que ya decide la pantalla que elige la
 * imagen: `ImagesController::getIndex()` devuelve las privadas del que pregunta
 * y, sólo a superusuario o profesor, las `publica = 1`. Así que la comprobación
 * no le quita ninguna opción a ningún cliente que use el selector — le quita las
 * que el selector nunca le ofreció.
 */
class ImagenAjenaEnElMuroTest extends CasoDeContrato
{
    /**
     * Un alumno no cuelga la imagen privada de otro, y no queda fila.
     *
     * Se comprueba **la tabla** además del 403: un `abort` puesto después del
     * `INSERT` también daría 403 y dejaría la publicación escrita.
     */
    public function test_un_alumno_no_cuelga_la_imagen_privada_de_otro(): void
    {
        $alumno = $this->usuarioDeTipo('Alumno');
        $ajena = $this->imagenPrivadaQueNoSeaDe((int) $alumno->id);

        $antes = (int) DB::selectOne('SELECT COUNT(*) n FROM publicaciones')->n;

        $this->withToken($this->tokenDe($alumno->username))
            ->putJson('/api/publicaciones/store', [
                'publi_para' => 'publi_para_todos',
                'contenido' => 'la imagen de otro',
                'imagen' => ['id' => $ajena->id, 'nombre' => $ajena->nombre],
            ])
            ->assertStatus(403)
            ->assertJsonPath('message', 'Esa imagen no es tuya.');

        $this->assertSame($antes, (int) DB::selectOne('SELECT COUNT(*) n FROM publicaciones')->n,
            'Quedó la publicación escrita pese al 403: la comprobación está después del INSERT.');
    }

    /**
     * Y editando una publicación propia, tampoco.
     *
     * `putGuardarEdicion` tenía el mismo bloque copiado, y sí comprueba que la
     * publicación sea suya —`exigeQueLaPublicacionSeaSuya`— así que **parecía
     * cubierta**: lo que no comprobaba es de quién es la imagen que le pones.
     * Es la asimetría de siempre, dentro de un método que ya tenía una
     * comprobación puesta.
     */
    public function test_editando_su_propia_publicacion_tampoco(): void
    {
        $alumno = $this->usuarioDeTipo('Alumno');
        $ajena = $this->imagenPrivadaQueNoSeaDe((int) $alumno->id);
        $token = $this->tokenDe($alumno->username);

        $id = $this->withToken($token)->putJson('/api/publicaciones/store', [
            'publi_para' => 'publi_para_todos', 'contenido' => 'mía y sin imagen',
        ])->assertStatus(200)->json('publicacion_id');

        $this->olvidarControladores();

        $this->withToken($token)->putJson('/api/publicaciones/guardar-edicion', [
            'id' => $id,
            'contenido' => 'ahora con la de otro',
            'imagen' => ['id' => $ajena->id, 'nombre' => $ajena->nombre],
        ])->assertStatus(403);

        $this->assertNull(
            DB::selectOne('SELECT imagen_id FROM publicaciones WHERE id = ?', [$id])->imagen_id,
            'La edición coló la imagen ajena aunque contestara 403.');
    }

    /**
     * Y colgar la suya sigue funcionando, y llega al muro.
     *
     * La otra mitad, y la que un `abort` de más apagaría: sin esto, la
     * comprobación podría estar rechazando **todas** las imágenes y el test de
     * arriba seguiría verde. Se mira **el viaje entero** —se publica y se lee el
     * muro con otro usuario— porque publicar es lo que hace la pantalla.
     */
    public function test_colgar_la_suya_sigue_llegando_al_muro(): void
    {
        $alumno = $this->usuarioDeTipo('Alumno');

        $suya = DB::selectOne('SELECT id, nombre FROM images
            WHERE user_id = ? AND deleted_at IS NULL ORDER BY id LIMIT 1', [$alumno->id]);

        if ($suya === null) {
            // El seed no le da imágenes propias a un alumno, así que se le crea
            // una: el caso bueno tiene que probarse igual, y montarlo aquí es
            // más honesto que saltar el test — un test saltado se lee como verde.
            $suya = (object) ['id' => DB::table('images')->insertGetId([
                'nombre' => 'propia-de-prueba.jpg', 'user_id' => $alumno->id, 'publica' => 0,
            ]), 'nombre' => 'propia-de-prueba.jpg'];
        }

        $id = $this->withToken($this->tokenDe($alumno->username))
            ->putJson('/api/publicaciones/store', [
                'publi_para' => 'publi_para_todos',
                'contenido' => 'la mía',
                'imagen' => ['id' => $suya->id, 'nombre' => $suya->nombre],
            ])->assertStatus(200)->json('publicacion_id');

        $this->assertSame((int) $suya->id,
            (int) DB::selectOne('SELECT imagen_id FROM publicaciones WHERE id = ?', [$id])->imagen_id,
            'Colgar la imagen propia dejó de escribir el `imagen_id`.');

        $this->olvidarControladores();

        $muro = $this->withToken($this->tokenDe($this->usuarioDeTipo('Profesor')->username))
            ->putJson('/api/publicaciones/ultimas', [])->assertStatus(200)->json();

        $this->assertContains((int) $id, array_map('intval', array_column($muro['publicaciones'] ?? [], 'id')),
            'La publicación propia con imagen propia no llega al muro.');
    }

    /**
     * Una imagen privada que no sea del usuario dado.
     *
     * `publica IS NULL OR publica = 0` porque la columna es nulable y en la copia
     * de producción la mayoría están a `NULL`, no a `0`: preguntar sólo por `= 0`
     * habría devuelto otra fila —o ninguna— y el test mediría otra cosa.
     */
    private function imagenPrivadaQueNoSeaDe(int $userId): object
    {
        $fila = DB::selectOne('SELECT id, nombre, user_id FROM images
            WHERE deleted_at IS NULL AND (publica IS NULL OR publica = 0)
              AND user_id IS NOT NULL AND user_id <> ? ORDER BY id LIMIT 1', [$userId]);

        $this->assertNotNull($fila,
            'El seed no tiene ninguna imagen privada de otra persona: sin eso este test no puede '
            .'distinguir la imagen ajena de la propia y pasaría sin comprobar nada.');

        return $fila;
    }
}
