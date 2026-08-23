<?php

namespace Tests\Contrato;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Qué pasa cuando el campo del archivo no trae lo que el código da por hecho.
 *
 * Sale del nivel 7 de larastan, que señaló `getRealPath()`/`move()` sobre
 * `array<UploadedFile>|UploadedFile` en tres sitios a la vez: `Request::file('x')`
 * devuelve **un archivo o un array**, según el cliente mande `file` o `file[]`, y
 * los dos puntos de subida del sistema asumían lo primero. Ver 05 §45.
 */
class SubidaDeArchivosTest extends CasoDeContrato
{
    private string $directorioPrevio;

    private string $temporal;

    /**
     * Mismo apaño que `ImagenesTest`, y por el mismo motivo: los controladores de
     * imagen escriben en `images/perfil/...`, que es una ruta **relativa**, así que
     * el destino depende del directorio de trabajo. Sirviendo por HTTP es
     * `public/`; corriendo phpunit es la raíz del repo, y el test deja ahí un
     * `images/perfil/user_*` que nadie creó a propósito. Se comprobó de la peor
     * manera: escribiéndolo sin esto y viéndolo aparecer en `git status`.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->directorioPrevio = (string) getcwd();
        $this->temporal = sys_get_temp_dir().'/contrato-subidas-'.getmypid().'-'.uniqid();

        File::ensureDirectoryExists($this->temporal);
        chdir($this->temporal);
    }

    protected function tearDown(): void
    {
        chdir($this->directorioPrevio);
        File::deleteDirectory($this->temporal);

        parent::tearDown();
    }

    private function token(): string
    {
        return $this->tokenDe($this->usuarioDeTipo('Profesor')->username);
    }

    /** El camino bueno, que es lo que no se puede romper al cerrar los otros. */
    public function test_una_imagen_se_sube(): void
    {
        $antes = DB::table('images')->count();

        $r = $this->withToken($this->token())->post('/api/myimages/store-intacta-privada', [
            'file' => UploadedFile::fake()->image('retrato.jpg'),
        ]);

        $r->assertStatus(201);
        $this->assertSame($antes + 1, DB::table('images')->count());
        $this->assertStringContainsString('retrato', (string) $r->json('nombre'));
    }

    /**
     * Dos archivos en el mismo campo daban **500**: `nombreDisponible()` recibía un
     * array donde su firma declara `?UploadedFile`, y el TypeError se veía desde
     * fuera como «el servidor está caído». No es un agujero —no llega a guardarse
     * nada— pero es la única operación de subida que tiene el sistema.
     *
     * Se rechaza en vez de quedarse con el primero: quien manda dos cree que sube
     * dos, y guardar uno en silencio es peor que decirle que no.
     */
    public function test_dos_archivos_en_el_mismo_campo_son_422(): void
    {
        $antes = DB::table('images')->count();

        $this->withToken($this->token())->post('/api/myimages/store-intacta-privada', [
            'file' => [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.jpg')],
        ])->assertStatus(422);

        $this->assertSame($antes, DB::table('images')->count(), 'No se guarda ninguno de los dos.');
    }

    /** Y sin archivo sigue siendo 422, que ya lo estaba y no había que tocarlo. */
    public function test_sin_archivo_sigue_siendo_422(): void
    {
        $this->withToken($this->token())
            ->post('/api/myimages/store-intacta-privada', [])
            ->assertStatus(422);
    }

    /**
     * `store-firma` es la MISMA función que `store-intacta-privada`, línea por línea.
     *
     * Los dos métodos son `guardar_imagen($user)` + `publica = false` + `save()`,
     * sin una sola diferencia, y viven a diez líneas uno de otro. No se unifican
     * aquí —son dos entradas del contrato con cuatro clientes y fundirlas es una
     * decisión— pero **sí se fija que hacen lo mismo**: mientras nadie lo
     * compruebe, arreglar una y no la otra es gratis, y es exactamente lo que ha
     * pasado dos veces esta noche en `perfiles`/`grupos`.
     *
     * Lo que se comprueba es el resultado y no la respuesta: la fila que queda en
     * `images` tiene dueño y es privada.
     */
    public function test_subir_una_firma_hace_lo_mismo_que_su_gemela(): void
    {
        $token = $this->token();

        $filas = [];

        foreach (['store-firma', 'store-intacta-privada'] as $ruta) {
            $r = $this->withToken($token)->post('/api/myimages/'.$ruta, [
                'file' => UploadedFile::fake()->image('firma.jpg'),
            ]);

            $this->assertSame(201, $r->status(), "`myimages/{$ruta}` dejó de aceptar una imagen.");

            $fila = DB::table('images')->where('id', $r->json('id'))->first();

            $this->assertNotNull($fila, "`myimages/{$ruta}` contestó 201 y no dejó fila.");

            $filas[$ruta] = ['publica' => $fila->publica, 'user_id' => $fila->user_id];
        }

        $this->assertSame($filas['store-intacta-privada'], $filas['store-firma'],
            'Las dos gemelas dejaron filas distintas: alguien tocó una y no la otra.');

        $this->assertSame(0, (int) $filas['store-firma']['publica'],
            'Una firma dejó de subirse como privada.');
    }

    /**
     * La lista blanca sigue en pie: lo que se comprueba aquí no es la extensión
     * —eso ya lo fija `ImagenesTest`— sino que el atajo nuevo no se la salte.
     */
    public function test_un_php_disfrazado_sigue_sin_entrar(): void
    {
        $this->withToken($this->token())->post('/api/myimages/store-intacta-privada', [
            'file' => UploadedFile::fake()->createWithContent('malo.php', '<?php echo 1;'),
        ])->assertStatus(422);
    }
}
