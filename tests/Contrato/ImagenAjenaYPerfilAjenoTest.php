<?php

namespace Tests\Contrato;

use App\Http\Middleware\ExigirPersonaPropia;
use Illuminate\Support\Facades\DB;

/**
 * Las tres de la [§228](../../docs/migracion/05-codigo-muerto-y-roto.md) que más se
 * parecían a un agujero — ejercidas, no leídas.
 *
 * El barrido de la §228 clasificó 17 rutas como «sin defensa propia», y tres de
 * ellas se leían como algo peor que las demás:
 *
 * | ruta | por qué llamaba la atención |
 * |---|---|
 * | `images-users/move-img-to-me` | comprueba que el destino eres tú y **el controlador no mira de quién es la imagen de origen** |
 * | `myimages/datos-imagen` | tiene el `User::fromToken()` **comentado**: la fuente de propiedad, desactivada en el fichero |
 * | `perfiles/update/{id}` | sus cuatro `abort` son `422 Datos incorrectos` — **validan formato, no propiedad** |
 *
 * **Las tres frases son ciertas del CONTROLADOR y ninguna dice que nadie lo
 * compruebe.** Es la misma distinción de la §227, y aquí se ejerce en vez de
 * razonarse: *leer no es medir*.
 *
 * ## Por qué están cerradas, y es más de lo que su nombre sugiere
 *
 * `ExigirPersonaPropia` **no vigila sólo identificadores de persona**. Su lista
 * lleva `imagen_id`, `img_id` y `foto_id`, y `esSuyo()` los normaliza y los
 * resuelve **contra el dueño de la imagen**. `move-img-to-me` es, literalmente, el
 * endpoint que hizo añadir `img_id` a esa lista —está en la cabecera del
 * middleware, §15—: **ya estuvo abierta, y el arreglo fue ampliar la lista de
 * nombres.**
 *
 * De ahí sale lo que este fichero fija y que no es un hallazgo de seguridad sino
 * algo más útil: **el guard cubre más superficie de la que su nombre sugiere**, y
 * eso hace que sus 17 dependientes sean menos frágiles de lo que la tabla de la
 * §228 sugería leída fuera de su columna.
 *
 * > **Y ése fue el error de comunicación, que se anota porque volverá a pasar:**
 * > «no comprueba de quién es la imagen de origen» estaba en una tabla titulada
 * > *«el controlador no se defiende solo»*, y se leyó como «nadie lo comprueba».
 * > **Una fila de tabla se lee siempre fuera de su encabezado.**
 */
class ImagenAjenaYPerfilAjenoTest extends CasoDeContrato
{
    /**
     * Mover a mi cuenta la imagen de otro: **403**, y el guard lo ve por `img_id`.
     *
     * El nombre de la ruta dice que el destino soy yo, así que **el `user_id` de
     * destino es mío por construcción y comprobarlo no protege de nada**. Lo que
     * protege es que `img_id` esté en la lista del middleware.
     */
    public function test_un_alumno_no_puede_moverse_la_imagen_de_otro(): void
    {
        $alumno = $this->usuarioDeTipo('Alumno');
        $ajena = $this->imagenDeOtro((int) $alumno->id);

        $this->assertNotNull($ajena,
            'El seed no tiene una imagen de otra persona: sin eso este test no ejerce nada.');

        $duenoAntes = DB::selectOne('SELECT user_id FROM images WHERE id = ?', [$ajena])->user_id;

        $r = $this->putJson('/api/images-users/move-img-to-me', ['img_id' => $ajena],
            ['Authorization' => 'Bearer '.$this->tokenDe($alumno->username)]);

        $this->assertSame(403, $r->getStatusCode(),
            'Un alumno recibió '.$r->getStatusCode().' moviéndose la imagen '.$ajena.', que es de '
            .'otra persona. Si esto es 200, es una fuga que además NO DEJA RASTRO: la víctima '
            .'pierde su imagen y nadie escribe nada.');

        // **La segunda mitad, y no es redundante:** un 403 que llegue después del
        // `UPDATE` no vale de nada. Es el criterio de `SuperficieDeUnAlumnoTest`.
        $this->assertSame($duenoAntes,
            DB::selectOne('SELECT user_id FROM images WHERE id = ?', [$ajena])->user_id,
            'El 403 llegó tarde: la imagen ya había cambiado de dueño.');
    }

    /**
     * Pedir los datos de una imagen ajena: **403**.
     *
     * Aquí el `User::fromToken()` del controlador **está comentado**, así que si el
     * middleware no mirara `imagen_id` no habría nada. Mira las dos: `imagen_id` y
     * `user_id` están en la lista.
     */
    public function test_un_alumno_no_puede_pedir_los_datos_de_una_imagen_ajena(): void
    {
        $alumno = $this->usuarioDeTipo('Alumno');
        $ajena = $this->imagenDeOtro((int) $alumno->id);

        $this->assertNotNull($ajena, 'El seed no tiene una imagen de otra persona.');

        $r = $this->putJson('/api/myimages/datos-imagen', ['imagen_id' => $ajena],
            ['Authorization' => 'Bearer '.$this->tokenDe($alumno->username)]);

        $this->assertSame(403, $r->getStatusCode(),
            'Un alumno recibió '.$r->getStatusCode().' pidiendo los datos de la imagen ajena '
            .$ajena.'. El controlador no puede pararlo: su `User::fromToken()` está comentado.');
    }

    /**
     * Escribir el perfil de otro: **403**, y por el guard.
     *
     * Los cuatro `abort(422, 'Datos incorrectos')` del controlador **no defienden
     * de esto** — validan el formato de lo que llega. Quien lee el fichero ve
     * cuatro `abort` y se queda tranquilo; **el que decide es
     * `persona.propia:persona_id`**, que resuelve el `{id}` de la URL como el id de
     * la ficha.
     */
    public function test_un_alumno_no_puede_escribir_el_perfil_de_otro(): void
    {
        $alumno = $this->usuarioDeTipo('Alumno');
        $suyo = $this->fichaDeAlumno((int) $alumno->id);
        $otro = DB::selectOne(
            'SELECT id, nombres FROM alumnos WHERE deleted_at IS NULL AND id <> ? LIMIT 1',
            [$suyo]
        );

        $this->assertNotNull($otro, 'El seed no tiene un segundo alumno.');

        $r = $this->putJson('/api/perfiles/update/'.$otro->id,
            ['tipo' => 'Alumno', 'nombres' => 'PISADO POR OTRO'],
            ['Authorization' => 'Bearer '.$this->tokenDe($alumno->username)]);

        $this->assertSame(403, $r->getStatusCode(),
            'Un alumno recibió '.$r->getStatusCode().' escribiendo el perfil del alumno '.$otro->id.'.');

        $this->assertSame($otro->nombres,
            DB::selectOne('SELECT nombres FROM alumnos WHERE id = ?', [$otro->id])->nombres,
            'El 403 llegó tarde: el nombre del otro alumno ya estaba pisado.');
    }

    /**
     * Y el que explica los tres: **el guard vigila también los ids de imagen.**
     *
     * Va como test y no como comentario porque **es la premisa de los tres de
     * arriba**: si mañana alguien quita `img_id` o `imagen_id` de `CLAVES`, los tres
     * se abren de golpe y **nada más avisaría** — los controladores no defienden.
     * Ya pasó una vez: `move-img-to-me` estuvo abierta justo por llamarlo `img_id`
     * (§15).
     */
    public function test_la_lista_del_guard_sigue_vigilando_los_ids_de_imagen(): void
    {
        $reflexion = new \ReflectionClass(ExigirPersonaPropia::class);
        $claves = $reflexion->getConstant('CLAVES');

        $this->assertIsArray($claves);

        foreach (['imagen_id', 'img_id', 'foto_id'] as $nombre) {
            $this->assertContains($nombre, $claves,
                "`{$nombre}` salió de la lista del guard. Con eso se abren `move-img-to-me`, "
                .'`myimages/datos-imagen` y las de rotar, que no se defienden solas.');
        }
    }

    /** Una imagen cuyo dueño no es este alumno — y con dueño, que `user_id` es nullable. */
    private function imagenDeOtro(int $userId): ?int
    {
        $fila = DB::selectOne(
            'SELECT id FROM images WHERE user_id IS NOT NULL AND user_id <> ? LIMIT 1',
            [$userId]
        );

        return $fila === null ? null : (int) $fila->id;
    }

    private function fichaDeAlumno(int $userId): int
    {
        $fila = DB::selectOne(
            'SELECT id FROM alumnos WHERE user_id = ? AND deleted_at IS NULL LIMIT 1',
            [$userId]
        );

        return $fila === null ? 0 : (int) $fila->id;
    }
}
