<?php

namespace Tests\Contrato;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Las imágenes de perfil: subir, recortar y rotar.
 *
 * Es el punto P0 de la Fase 0.2 que quedó sin escribir, y el que más falta
 * hacía: la Fase 4 cambió `intervention/image` de la v2 (de 2022) a la v4, lo
 * que reescribió las tres llamadas del proyecto —`Image::make()` pasó a
 * `Image::decodePath()`, `->orientate()` a `->orient()` y `->fit(200)` a
 * `->cover(200, 200)`— sin una sola prueba detrás. Estos tests fijan lo que el
 * frontend recibe y lo que acaba en disco, que es donde un cambio de librería
 * de imagen se nota de verdad: el recorte y el sentido del giro.
 *
 * Por eso aquí se mira el archivo y no solo el JSON. Un `assertStatus(200)` no
 * distingue una foto de 200x200 de una de 640x480, y la diferencia entre
 * `fit()` y `cover()` es exactamente esa.
 */
class ImagenesTest extends CasoDeContrato
{
    /** Directorio de trabajo real, al que se vuelve en tearDown. */
    private string $directorioPrevio;

    /** Directorio temporal donde el test deja lo que escriba. */
    private string $temporal;

    /**
     * Los controladores de imagen escriben en `images/perfil/...`, que es una
     * ruta RELATIVA: el destino depende del directorio de trabajo del proceso.
     * Sirviendo por HTTP es `public/`, porque ahí vive el `index.php`; corriendo
     * phpunit es la raíz del repo, y por eso hay ahí un `images/perfil/` vacío
     * con carpetas `user_*` que nadie creó a propósito.
     *
     * El test se muda a una carpeta temporal: reproduce la condición de
     * producción —rutas relativas a un directorio de trabajo— y no deja nada
     * escrito en el repo.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->directorioPrevio = (string) getcwd();
        $this->temporal = sys_get_temp_dir().'/contrato-imagenes-'.getmypid().'-'.uniqid();

        File::ensureDirectoryExists($this->temporal);
        chdir($this->temporal);
    }

    protected function tearDown(): void
    {
        chdir($this->directorioPrevio);
        File::deleteDirectory($this->temporal);

        parent::tearDown();
    }

    // ---------------------------------------------------------------- Subida

    public function test_la_foto_de_perfil_se_recorta_a_200x200(): void
    {
        $token = $this->tokenDe($this->usuarioDeTipo('Profesor')->username);

        $r = $this->post('/api/myimages/store',
            ['file' => $this->imagenMarcada('retrato.jpg', 640, 480)],
            ['Authorization' => 'Bearer '.$token]);

        $r->assertStatus(201);
        $this->compararConInstantanea('myimages-store', $this->forma($r->json()));

        $this->assertSame([200, 200], $this->dimensiones($r->json('nombre')),
            'postStore recorta con cover(200, 200). Si esto cambia, cambian todas las fotos de perfil.');
    }

    public function test_las_intactas_conservan_su_tamano(): void
    {
        $token = $this->tokenDe($this->usuarioDeTipo('Profesor')->username);

        foreach (['store-intacta' => true, 'store-intacta-privada' => false, 'store-firma' => false] as $ruta => $publica) {
            $r = $this->post("/api/myimages/{$ruta}",
                ['file' => $this->imagenMarcada('membrete.png', 640, 480)],
                ['Authorization' => 'Bearer '.$token]);

            $r->assertStatus(201);

            $this->assertSame([640, 480], $this->dimensiones($r->json('nombre')),
                "myimages/{$ruta} no debe tocar la imagen: por eso se llama intacta.");

            $this->assertSame($publica, (bool) $r->json('publica'),
                "myimages/{$ruta} decide si la imagen la ven los demás.");
        }
    }

    public function test_el_nombre_repetido_no_pisa_el_archivo_anterior(): void
    {
        $token = $this->tokenDe($this->usuarioDeTipo('Profesor')->username);

        $primera = $this->post('/api/myimages/store-intacta',
            ['file' => $this->imagenMarcada('repetida.png', 640, 480)],
            ['Authorization' => 'Bearer '.$token])->json('nombre');

        $segunda = $this->post('/api/myimages/store-intacta',
            ['file' => $this->imagenMarcada('repetida.png', 320, 240)],
            ['Authorization' => 'Bearer '.$token])->json('nombre');

        $this->assertNotSame($primera, $segunda,
            'Dos subidas con el mismo nombre deben dar dos archivos: repetida.png y repetida(1).png.');

        $this->assertSame([640, 480], $this->dimensiones($primera),
            'La segunda subida sobrescribió a la primera.');
    }

    public function test_un_php_disfrazado_de_imagen_no_entra(): void
    {
        $token = $this->tokenDe($this->usuarioDeTipo('Profesor')->username);

        $this->post('/api/myimages/store',
            ['file' => UploadedFile::fake()->createWithContent('shell.php', '<?php echo 1;')],
            ['Authorization' => 'Bearer '.$token])
            ->assertStatus(422);

        $this->assertSame([], $this->archivosEscritos(),
            'Un archivo rechazado no debe quedar escrito bajo el directorio público.');
    }

    public function test_un_alumno_no_puede_acumular_imagenes(): void
    {
        $usuario = $this->usuarioDeTipo('Alumno');
        $token = $this->tokenDe($usuario->username);

        DB::table('images')->where('user_id', $usuario->id)->delete();

        for ($i = 1; $i <= 3; $i++) {
            $this->post('/api/myimages/store',
                ['file' => $this->imagenMarcada("foto{$i}.jpg", 300, 300)],
                ['Authorization' => 'Bearer '.$token])
                ->assertStatus(201);
        }

        $this->post('/api/myimages/store',
            ['file' => $this->imagenMarcada('cuarta.jpg', 300, 300)],
            ['Authorization' => 'Bearer '.$token])
            ->assertStatus(400);
    }

    /**
     * Una foto de móvil llega derecha aunque venga tumbada en el archivo.
     *
     * Las cámaras de teléfono no giran los píxeles: los guardan como salieron
     * del sensor y anotan en la EXIF cómo hay que verlos.
     *
     * Quien lo corrige NO es el `->orient()` de `postStore`, aunque lo parezca.
     * Comprobado quitándolo: la imagen sale igual de derecha. En la v4 lo hace
     * el propio decodificador —`FilePathImageDecoder` mira `autoOrientation`,
     * que viene activada de serie y que `config/image.php` no toca—, así que la
     * llamada es redundante desde la Fase 4. Se deja porque no cuesta nada y
     * deja escrita la intención.
     *
     * Lo que este test protege es el resultado, que es lo que ve el usuario: la
     * foto sube derecha. Da igual quién de los dos la enderece.
     *
     * Se usa una imagen cuadrada a propósito: así el `cover(200, 200)` que
     * viene después escala sin recortar, y lo único que puede mover la marca de
     * sitio es la orientación.
     */
    public function test_una_foto_tumbada_por_la_camara_se_endereza_al_subirla(): void
    {
        $token = $this->tokenDe($this->usuarioDeTipo('Profesor')->username);

        // Orientación 6: «la primera fila del archivo es el lado derecho de la
        // escena», o sea que hay que girarla un cuarto de vuelta a la derecha
        // para verla bien. Es lo que manda un teléfono en vertical.
        $r = $this->post('/api/myimages/store',
            ['file' => $this->imagenMarcada('del-movil.jpg', 480, 480, orientacionExif: 6)],
            ['Authorization' => 'Bearer '.$token]);

        $r->assertStatus(201);

        $this->assertSame('arriba-derecha', $this->esquinaMarcada($r->json('nombre')),
            'Con orientación 6, la marca guardada arriba a la izquierda se ve arriba a la derecha. '.
            'Si sale arriba a la izquierda es que la EXIF no se está leyendo: comprueba que ext-exif '.
            'esté activa en el PHP del servidor. intervention solo la SUGIERE, no la exige, así que '.
            'sin ella no falla nada: las fotos de móvil salen tumbadas y ya.');
    }

    public function test_una_foto_sin_exif_no_se_toca(): void
    {
        $token = $this->tokenDe($this->usuarioDeTipo('Profesor')->username);

        $r = $this->post('/api/myimages/store',
            ['file' => $this->imagenMarcada('del-escaner.jpg', 480, 480)],
            ['Authorization' => 'Bearer '.$token]);

        $r->assertStatus(201);

        $this->assertSame('arriba-izquierda', $this->esquinaMarcada($r->json('nombre')),
            'Sin EXIF que corregir, orient() tiene que dejar la imagen como estaba.');
    }

    // ----------------------------------------------------------------- Giro

    /**
     * Los dos endpoints de girar quedaron invertidos en la Fase 4 y estos dos
     * tests son los que lo destaparon.
     *
     * `intervention/image` cambió el signo del ángulo entre la v2 y la v4. La
     * v2 se lo pasaba tal cual a `imagerotate()` de GD, que es antihorario; la
     * v4 lo interpreta al contrario (su `RotateModifier` lo llama «clockwise
     * rotation angle»). El código heredaba `rotate(-90)` para el botón de la
     * derecha y `rotate(90)` para el de la izquierda, que era correcto con la
     * v2 y quedó al revés con la v4.
     *
     * No lo habría visto un test de estado: los dos endpoints seguían
     * respondiendo 200 y seguían escribiendo una imagen girada. Solo se ve
     * mirando adónde fue a parar un píxel.
     */
    public function test_rotar_a_la_derecha_lleva_la_esquina_marcada_arriba_a_la_derecha(): void
    {
        [$token, $imagen] = $this->unaImagenSubida();

        $this->putJson("/api/images-users/rotarimagen/{$imagen->id}", [],
            ['Authorization' => 'Bearer '.$token])
            ->assertStatus(200);

        $this->assertSame([480, 640], $this->dimensiones($imagen->nombre),
            'Un cuarto de vuelta intercambia ancho y alto.');

        $this->assertSame('arriba-derecha', $this->esquinaMarcada($imagen->nombre),
            'ImagesUsersApi.rotarDerecha() llama a esta ruta: tiene que girar en el sentido del reloj.');
    }

    public function test_rotar_a_la_izquierda_la_lleva_abajo_a_la_izquierda(): void
    {
        [$token, $imagen] = $this->unaImagenSubida();

        $this->putJson("/api/images-users/rotar-imagen-izquierda/{$imagen->id}", [],
            ['Authorization' => 'Bearer '.$token])
            ->assertStatus(200);

        $this->assertSame([480, 640], $this->dimensiones($imagen->nombre));

        $this->assertSame('abajo-izquierda', $this->esquinaMarcada($imagen->nombre),
            'ImagesUsersApi.rotarIzquierda() llama a esta ruta: tiene que girar al contrario que la otra.');
    }

    public function test_rotar_cuatro_veces_deja_la_imagen_como_estaba(): void
    {
        [$token, $imagen] = $this->unaImagenSubida();

        for ($i = 0; $i < 4; $i++) {
            $this->putJson("/api/images-users/rotarimagen/{$imagen->id}", [],
                ['Authorization' => 'Bearer '.$token])
                ->assertStatus(200);
        }

        $this->assertSame([640, 480], $this->dimensiones($imagen->nombre));
        $this->assertSame('arriba-izquierda', $this->esquinaMarcada($imagen->nombre));
    }

    // -------------------------------------------------------------- Borrado

    /**
     * Borrar una imagen respondía 500 **después** de haberla borrado.
     *
     * El endpoint hacía su trabajo entero —el archivo del disco, la fila de
     * `images`, y las referencias en alumnos, profesores, acudientes, usuarios
     * y años— y reventaba en la última línea, en un `count()` sobre un Builder
     * que en PHP 7 era un warning y en PHP 8 es un TypeError. O sea que el
     * frontend recibía un error de una operación que sí había ocurrido: quien
     * lo reintentara vería el 404 del `findOrFail`, que parece otro fallo.
     *
     * Se mira lo que quedó escrito y no solo el código: un 200 no distingue
     * «borró» de «no llegó a borrar».
     */
    public function test_borrar_una_imagen_responde_bien_y_limpia_las_referencias(): void
    {
        [$token, $imagen] = $this->unaImagenSubida();

        $usuario = $this->usuarioDeTipo('Profesor');
        DB::table('users')->where('id', $usuario->id)->update(['imagen_id' => $imagen->id]);

        $this->delete("/api/images-users/destroy/{$imagen->id}", [],
            ['Authorization' => 'Bearer '.$token])
            ->assertStatus(200);

        $this->assertFileDoesNotExist('images/perfil/'.$imagen->nombre);

        $this->assertNotNull(DB::table('images')->where('id', $imagen->id)->value('deleted_at'),
            'La fila de images se marca como borrada.');

        $this->assertNull(DB::table('users')->where('id', $usuario->id)->value('imagen_id'),
            'Y nadie queda apuntando a una imagen que ya no está: es lo que hace este endpoint '.
            'además de borrar, y lo que se perdería si alguien lo cambiara por un delete a secas.');
    }

    /**
     * Un alumno no borra la foto de otro.
     *
     * La ruta ya llevaba `persona.propia` desde la revisión de IDOR, y aun así
     * el agujero estaba abierto: el guard recoge los identificadores **por su
     * nombre** y esta es la única ruta de imagen cuyo parámetro se llama `{id}`
     * en vez de `{imagen_id}`. Sus hermanas —rotar, publicar, privatizar— sí lo
     * nombran, así que sí estaban cerradas. Sin identificador reconocible el
     * guard entiende «lo mío» y deja pasar, que es lo correcto para las rutas
     * sin id y lo peor posible para esta.
     *
     * Se comprueba el efecto y no el código: antes de esto, el alumno recibía un
     * 500 —el de arriba— y la imagen ajena ya estaba borrada.
     */
    public function test_un_alumno_no_puede_borrar_la_imagen_de_otro(): void
    {
        [, $imagen] = $this->unaImagenSubida();

        $tokenAlumno = $this->tokenDe($this->usuarioDeTipo('Alumno')->username);

        $this->delete("/api/images-users/destroy/{$imagen->id}", [],
            ['Authorization' => 'Bearer '.$tokenAlumno])
            ->assertStatus(403);

        $this->assertFileExists('images/perfil/'.$imagen->nombre,
            'La imagen del profesor sigue en disco.');

        $this->assertNull(DB::table('images')->where('id', $imagen->id)->value('deleted_at'),
            'Y su fila sigue viva.');
    }

    /** Y el dueño sí borra la suya: cerrar de más también se nota en producción. */
    public function test_un_alumno_borra_la_suya(): void
    {
        $token = $this->tokenDe($this->usuarioDeTipo('Alumno')->username);

        $r = $this->post('/api/myimages/store-intacta',
            ['file' => $this->imagenMarcada('mia.png', 320, 240)],
            ['Authorization' => 'Bearer '.$token]);

        $r->assertStatus(201);

        $this->delete("/api/images-users/destroy/{$r->json('id')}", [],
            ['Authorization' => 'Bearer '.$token])
            ->assertStatus(200);
    }

    /**
     * La petición de cambio que nombraba la imagen se va con ella.
     *
     * Es la sexta referencia, y la única que hasta el 20 ago 2026 no se limpiaba
     * —el bloque que lo intentaba no llegó a hacerlo nunca, §13.1—. Joseth
     * decidió ese día que **se borra la petición** en vez de poner su referencia
     * a `null`: una que pide cambiar la foto por una imagen que ya no está solo
     * se puede rechazar, así que dejarla viva es dejarle trabajo inútil a quien
     * las revisa.
     *
     * Se comprueba en las cuatro columnas porque son las cuatro formas que tiene
     * una petición de nombrar una imagen, y la que se olvidara seguiría dejando
     * peticiones imposibles detrás.
     */
    #[DataProvider('columnasQueNombranUnaImagen')]
    public function test_la_peticion_de_cambio_se_borra_con_la_imagen(string $columna): void
    {
        [$token, $imagen] = $this->unaImagenSubida();

        $usuario = $this->usuarioDeTipo('Profesor');

        $dataId = DB::table('change_asked_data')->insertGetId([$columna => $imagen->id]);
        $askedId = DB::table('change_asked')->insertGetId([
            'asked_by_user_id' => $usuario->id,
            'data_id' => $dataId,
        ]);

        $this->delete("/api/images-users/destroy/{$imagen->id}", [],
            ['Authorization' => 'Bearer '.$token])
            ->assertStatus(200);

        $this->assertDatabaseMissing('change_asked', ['id' => $askedId]);
        $this->assertDatabaseMissing('change_asked_data', ['id' => $dataId]);
    }

    public static function columnasQueNombranUnaImagen(): array
    {
        return [
            'la foto de la ficha' => ['foto_id_new'],
            'la imagen del usuario' => ['image_id_new'],
            'la firma' => ['firma_id_new'],
            'la que pedía borrar' => ['image_to_delete_id'],
        ];
    }

    /**
     * Y el cambio de asignatura que viajaba en la misma petición se va con ella.
     *
     * Es el único efecto de la decisión que no se ve venir: una petición es
     * **una por usuario y año**, así que puede llevar dentro un cambio de
     * asignatura que no tiene nada que ver con la imagen. Borrar la petición lo
     * borra también — es lo que significa borrarla, y es lo que hace
     * `putDestruir`, que es la operación que ya existía para esto.
     *
     * El test está para que eso sea una decisión escrita y no una sorpresa el
     * día que alguien lo reporte.
     */
    public function test_borrar_la_peticion_arrastra_su_cambio_de_asignatura(): void
    {
        [$token, $imagen] = $this->unaImagenSubida();

        $usuario = $this->usuarioDeTipo('Profesor');

        $dataId = DB::table('change_asked_data')->insertGetId(['image_id_new' => $imagen->id]);
        $asignaturaId = DB::table('change_asked_assignment')->insertGetId([]);
        $askedId = DB::table('change_asked')->insertGetId([
            'asked_by_user_id' => $usuario->id,
            'data_id' => $dataId,
            'assignment_id' => $asignaturaId,
        ]);

        $this->delete("/api/images-users/destroy/{$imagen->id}", [],
            ['Authorization' => 'Bearer '.$token])
            ->assertStatus(200);

        $this->assertDatabaseMissing('change_asked', ['id' => $askedId]);
        $this->assertDatabaseMissing('change_asked_assignment', ['id' => $asignaturaId]);
    }

    /** Y la de otra imagen no se toca: borrar de más también se nota. */
    public function test_la_peticion_de_otra_imagen_sigue_viva(): void
    {
        [$token, $imagen] = $this->unaImagenSubida();

        $usuario = $this->usuarioDeTipo('Profesor');

        $dataId = DB::table('change_asked_data')->insertGetId(['image_id_new' => $imagen->id + 1000]);
        $askedId = DB::table('change_asked')->insertGetId([
            'asked_by_user_id' => $usuario->id,
            'data_id' => $dataId,
        ]);

        $this->delete("/api/images-users/destroy/{$imagen->id}", [],
            ['Authorization' => 'Bearer '.$token])
            ->assertStatus(200);

        $this->assertDatabaseHas('change_asked', ['id' => $askedId]);
        $this->assertDatabaseHas('change_asked_data', ['id' => $dataId]);
    }

    // --------------------------------------------------------------- Listado

    /**
     * Se sube una imagen antes de mirar el listado a propósito: un Alumno y un
     * Acudiente del seed no tienen ninguna, y una snapshot de cuatro listas
     * vacías pasaría igual de bien con el endpoint roto.
     */
    #[DataProvider('tiposDeUsuario')]
    public function test_la_forma_del_listado_de_imagenes(string $tipo): void
    {
        $token = $this->tokenDe($this->usuarioDeTipo($tipo)->username);

        $this->post('/api/myimages/store-intacta-privada',
            ['file' => $this->imagenMarcada('en-el-album.png', 320, 240)],
            ['Authorization' => 'Bearer '.$token])
            ->assertStatus(201);

        $r = $this->getJson('/api/myimages', ['Authorization' => 'Bearer '.$token]);

        $r->assertStatus(200);
        $this->compararConInstantanea('myimages-index-'.strtolower($tipo), $this->forma($r->json()));

        $this->assertNotEmpty($r->json('imagenes_privadas'),
            'La imagen recién subida tiene que aparecer en el álbum privado de quien la subió.');
    }

    public static function tiposDeUsuario(): array
    {
        return [
            'profesor' => ['Profesor'],
            'usuario' => ['Usuario'],
            'alumno' => ['Alumno'],
            'acudiente' => ['Acudiente'],
        ];
    }

    // ---------------------------------------------------------------- Apoyos

    /** Sube una imagen intacta y devuelve el token y la fila de `images`. */
    private function unaImagenSubida(): array
    {
        $token = $this->tokenDe($this->usuarioDeTipo('Profesor')->username);

        $r = $this->post('/api/myimages/store-intacta',
            ['file' => $this->imagenMarcada('girar.png', 640, 480)],
            ['Authorization' => 'Bearer '.$token]);

        // 201 y no 200: el controlador devuelve el modelo recién creado, y
        // Laravel traduce eso a «Created». Es lo que ve el frontend hoy.
        $r->assertStatus(201);

        return [$token, (object) ['id' => $r->json('id'), 'nombre' => $r->json('nombre')]];
    }

    /**
     * Una imagen con la esquina superior izquierda roja y el resto blanca.
     *
     * `UploadedFile::fake()->image()` sirve para el tamaño pero no para el
     * sentido del giro: sale plana, y una imagen plana rotada es idéntica a sí
     * misma. La marca es lo que convierte «giró» en «giró hacia allá».
     */
    private function imagenMarcada(string $nombre, int $ancho, int $alto, ?int $orientacionExif = null): UploadedFile
    {
        $lienzo = imagecreatetruecolor($ancho, $alto);

        imagefill($lienzo, 0, 0, imagecolorallocate($lienzo, 255, 255, 255));
        imagefilledrectangle($lienzo, 0, 0, intdiv($ancho, 8), intdiv($alto, 8),
            imagecolorallocate($lienzo, 255, 0, 0));

        $ruta = $this->temporal.'/origen-'.uniqid().'-'.$nombre;

        str_ends_with(strtolower($nombre), '.png')
            ? imagepng($lienzo, $ruta)
            : imagejpeg($lienzo, $ruta, 100);

        imagedestroy($lienzo);

        if ($orientacionExif !== null) {
            file_put_contents($ruta, $this->conEtiquetaDeOrientacion(file_get_contents($ruta), $orientacionExif));
        }

        return new UploadedFile($ruta, $nombre, null, null, true);
    }

    /**
     * Mete una EXIF mínima con la etiqueta de orientación en un JPEG.
     *
     * GD no sabe escribir EXIF, así que el segmento se construye a mano y se
     * cuela justo detrás del marcador de inicio. Son unos pocos bytes: cabecera
     * TIFF en little-endian, un solo IFD, una sola etiqueta (0x0112).
     */
    private function conEtiquetaDeOrientacion(string $jpeg, int $orientacion): string
    {
        $this->assertSame("\xFF\xD8", substr($jpeg, 0, 2), 'Esto solo vale para un JPEG.');

        $etiqueta = pack('v', 0x0112)   // orientación
            .pack('v', 3)               // tipo SHORT
            .pack('V', 1)               // un valor
            .pack('v', $orientacion)
            .pack('v', 0);              // relleno hasta los cuatro bytes del campo

        $tiff = 'II'                    // little-endian
            .pack('v', 0x002A)          // marca de TIFF
            .pack('V', 8)               // el primer IFD empieza en el byte 8
            .pack('v', 1)               // una entrada
            .$etiqueta
            .pack('V', 0);              // no hay más IFD

        $carga = "Exif\x00\x00".$tiff;

        return substr($jpeg, 0, 2)
            ."\xFF\xE1".pack('n', strlen($carga) + 2).$carga
            .substr($jpeg, 2);
    }

    /** @return array{0: int, 1: int} */
    private function dimensiones(string $nombre): array
    {
        $ruta = 'images/perfil/'.$nombre;

        $this->assertFileExists($ruta);

        return array_slice((array) getimagesize($ruta), 0, 2);
    }

    /** En qué esquina quedó el cuadro rojo de `imagenMarcada()`. */
    private function esquinaMarcada(string $nombre): string
    {
        $ruta = 'images/perfil/'.$nombre;
        $imagen = str_ends_with(strtolower($ruta), '.png')
            ? imagecreatefrompng($ruta)
            : imagecreatefromjpeg($ruta);

        [$ancho, $alto] = $this->dimensiones($nombre);

        $esquinas = [
            'arriba-izquierda' => [1, 1],
            'arriba-derecha' => [$ancho - 2, 1],
            'abajo-izquierda' => [1, $alto - 2],
            'abajo-derecha' => [$ancho - 2, $alto - 2],
        ];

        $encontradas = [];

        foreach ($esquinas as $donde => [$x, $y]) {
            $color = imagecolorat($imagen, $x, $y);

            if ((($color >> 16) & 0xFF) > 200 && (($color >> 8) & 0xFF) < 80 && ($color & 0xFF) < 80) {
                $encontradas[] = $donde;
            }
        }

        imagedestroy($imagen);

        $this->assertCount(1, $encontradas,
            'La marca roja debería estar en una esquina y solo en una: '.implode(', ', $encontradas));

        return $encontradas[0];
    }

    /** Todo lo que el test haya escrito bajo su directorio de trabajo temporal. */
    private function archivosEscritos(): array
    {
        if (! is_dir('images')) {
            return [];
        }

        return array_values(array_map(
            fn (\SplFileInfo $f) => $f->getFilename(),
            iterator_to_array(File::allFiles('images'))
        ));
    }
}
