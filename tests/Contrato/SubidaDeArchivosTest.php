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
