<?php

namespace Tests\Contrato;

use App\Services\CodigoDeInscripcion;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * **El comprobante del pago del formulario, y su aprobación.**
 *
 *     POST colillas-inscripcion/{codigo}      PÚBLICA
 *     GET  colillas-inscripcion/pendientes    tesorero
 *     PUT  colillas-inscripcion/{id}/aprobar  tesorero
 *     PUT  colillas-inscripcion/{id}/rechazar tesorero
 *
 * La primera es **la única ruta de esta API que recibe un fichero sin token**, así
 * que aquí no basta con comprobar que conteste 200: lo que hay que defender es
 * **qué NO deja pasar**, y que lo que guarda no se pueda usar para llegar a lo que
 * no es suyo.
 *
 * Las dos propiedades que más importan y que no se ven mirando la respuesta:
 *
 *   - **el nombre del fichero no es el código**. Si lo fuera, saber un código —que
 *     la familia dicta por teléfono— daría el recibo de esa familia.
 *   - **el tope es de la fila, no del reloj**. Tres por orden y uno pendiente; un
 *     limitador se reinicia cada hora y esto no.
 */
class ColillasInscripcionTest extends CasoDeContrato
{
    private const RUTA = '/api/colillas-inscripcion';

    protected function tearDown(): void
    {
        // Los ficheros se escriben de verdad en `public/colillas` y **no los deshace
        // la transacción del test**: sólo se deshacen las filas. Sin esto, cada
        // pasada dejaría basura en el árbol.
        foreach (glob(public_path('colillas').'/*') ?: [] as $f) {
            @unlink($f);
        }

        parent::tearDown();
    }

    public function test_la_familia_puede_mandar_solo_el_numero_de_referencia(): void
    {
        $codigo = $this->unCodigoAcunado();

        $r = $this->postJson(self::RUTA.'/'.$codigo, ['referencia' => 'REC-2027-88431']);

        $r->assertStatus(200);
        $this->assertTrue($r->json('recibido'));

        $fila = DB::selectOne('SELECT referencia, archivo, estado FROM colillas_inscripcion');

        $this->assertSame('REC-2027-88431', $fila->referencia);
        $this->assertNull($fila->archivo, 'El camino de la referencia no debe guardar ningún fichero.');
        $this->assertSame('PENDIENTE', $fila->estado);
    }

    /**
     * **El nombre del fichero no puede ser el código.**
     *
     * Es la propiedad que hace tolerable que el fichero viva bajo `public/`: la URL
     * es la llave, así que la llave no puede ser algo que la familia dicta por
     * teléfono.
     */
    public function test_el_fichero_se_guarda_con_nombre_aleatorio_y_nunca_el_codigo(): void
    {
        $codigo = $this->unCodigoAcunado();

        $this->post(self::RUTA.'/'.$codigo, [
            'archivo' => UploadedFile::fake()->image('mi-recibo.jpg', 40, 40),
        ])->assertStatus(200);

        $fila = DB::selectOne('SELECT archivo, tipo, bytes FROM colillas_inscripcion');

        $this->assertNotNull($fila->archivo);
        $this->assertStringNotContainsString($codigo, $fila->archivo,
            'El fichero se llama como el código: saber el código daría el recibo.');
        $this->assertStringNotContainsString('mi-recibo', $fila->archivo,
            'Se conservó el nombre que mandó el desconocido.');
        $this->assertStringEndsWith('.jpg', $fila->archivo);
        $this->assertSame('image/jpeg', $fila->tipo);
        $this->assertGreaterThan(0, (int) $fila->bytes);

        $this->assertFileExists(public_path($fila->archivo),
            'La fila apunta a un fichero que no está en el disco.');
    }

    public function test_un_codigo_mal_tecleado_es_422_y_no_consulta_nada(): void
    {
        // El carácter de control se comprueba ANTES de tocar la base: un código
        // inventado no llega ni a una consulta.
        foreach (['2027-AAAAAA', 'no-es-un-codigo', '2027-4K7M2', ''] as $malo) {
            $this->postJson(self::RUTA.'/'.urlencode($malo ?: 'x'), ['referencia' => 'REC-1'])
                ->assertStatus(422);
        }

        $this->assertSame(0, $this->cuantasColillas());
    }

    public function test_un_codigo_valido_que_no_existe_es_404(): void
    {
        $inventado = CodigoDeInscripcion::generar(2027);

        $this->postJson(self::RUTA.'/'.$inventado, ['referencia' => 'REC-1'])
            ->assertStatus(404);
    }

    public function test_sin_fichero_y_sin_referencia_es_422(): void
    {
        $codigo = $this->unCodigoAcunado();

        $this->postJson(self::RUTA.'/'.$codigo, [])->assertStatus(422);

        $this->assertSame(0, $this->cuantasColillas());
    }

    /**
     * El tope de verdad: **uno pendiente por orden**. Sin esto, la bandeja del
     * tesorero se llena desde un solo código y el disco con ella.
     */
    public function test_no_se_puede_mandar_un_segundo_mientras_uno_espera(): void
    {
        $codigo = $this->unCodigoAcunado();

        $this->postJson(self::RUTA.'/'.$codigo, ['referencia' => 'REC-1'])->assertStatus(200);
        $this->postJson(self::RUTA.'/'.$codigo, ['referencia' => 'REC-2'])->assertStatus(429);

        $this->assertSame(1, $this->cuantasColillas());
    }

    public function test_pasado_el_tope_por_orden_no_entra_ninguno_mas(): void
    {
        $codigo = $this->unCodigoAcunado();
        $token = $this->tokenDelTesorero();

        // Tres, resolviendo cada uno para que no choque con «uno pendiente».
        for ($i = 1; $i <= 3; $i++) {
            $this->postJson(self::RUTA.'/'.$codigo, ['referencia' => 'REC-'.$i])->assertStatus(200);

            $ultima = DB::selectOne('SELECT id FROM colillas_inscripcion ORDER BY id DESC LIMIT 1');

            $this->withToken($token)->putJson(self::RUTA.'/'.$ultima->id.'/rechazar',
                ['motivo' => 'no cuadra'])->assertStatus(200);
        }

        $this->postJson(self::RUTA.'/'.$codigo, ['referencia' => 'REC-4'])->assertStatus(429);

        $this->assertSame(3, $this->cuantasColillas());
    }

    /**
     * **Un SVG servido desde el dominio del colegio ejecuta JavaScript en ese
     * origen.** Es el único de estos rechazos que es un agujero y no una molestia.
     */
    public function test_lo_que_el_navegador_ejecutaria_no_entra(): void
    {
        $codigo = $this->unCodigoAcunado();

        // **Los cuatro se construyen como una subida DE VERDAD y no con
        // `UploadedFile::fake()`, y eso lo descubrió este test fallando.**
        //
        // Un `fake()` declara su tipo a partir de la extensión, así que un fichero
        // llamado `recibo.jpg` con PHP dentro dice de sí mismo `image/jpeg` y **se
        // cuela por la lista blanca**. Medido en el docker:
        //
        //     subida real    getMimeType() = text/x-php     -> se rechaza
        //     fake del test  getMimeType() = image/jpeg     -> pasa
        //
        // O sea que el agujero no estaba en el controlador —en producción el tipo se
        // husmea del contenido y el fichero cae— sino en el doble, que mentía sobre
        // sí mismo. Un test con `fake()` habría dicho «esto entra» sobre algo que no
        // entra, y la reacción natural habría sido «arreglar» el controlador.
        $peligrosos = [
            $this->subidaDeVerdad('recibo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
            $this->subidaDeVerdad('recibo.html', '<script>alert(1)</script>'),
            $this->subidaDeVerdad('recibo.php', '<?php echo 1;'),
            $this->subidaDeVerdad('recibo.jpg', '<?php echo 1;'),
        ];

        foreach ($peligrosos as $file) {
            $this->post(self::RUTA.'/'.$codigo, ['archivo' => $file])->assertStatus(422);
        }

        $this->assertSame(0, $this->cuantasColillas(), 'Entró algo que no debía.');
        $this->assertSame([], glob(public_path('colillas').'/*') ?: [],
            'Quedó un fichero rechazado escrito en el disco.');
    }

    public function test_un_fichero_enorme_no_entra(): void
    {
        $codigo = $this->unCodigoAcunado();

        $this->post(self::RUTA.'/'.$codigo, [
            'archivo' => UploadedFile::fake()->create('recibo.pdf', 6 * 1024, 'application/pdf'),
        ])->assertStatus(422);

        $this->assertSame(0, $this->cuantasColillas());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // El tesorero
    // ─────────────────────────────────────────────────────────────────────────

    public function test_un_docente_cualquiera_no_ve_ni_resuelve_los_comprobantes(): void
    {
        $token = $this->tokenDelPersonalLlano();

        $this->withToken($token)->getJson(self::RUTA.'/pendientes')->assertStatus(403);
        $this->withToken($token)->putJson(self::RUTA.'/1/aprobar')->assertStatus(403);
    }

    public function test_un_alumno_tampoco(): void
    {
        $token = $this->tokenDe($this->usuarioDeTipo('Alumno')->username);

        $this->withToken($token)->getJson(self::RUTA.'/pendientes')->assertStatus(403);
    }

    public function test_aprobar_deja_la_orden_pagada(): void
    {
        $codigo = $this->unCodigoAcunado();
        $this->postJson(self::RUTA.'/'.$codigo, ['referencia' => 'REC-77'])->assertStatus(200);

        $colilla = DB::selectOne('SELECT id FROM colillas_inscripcion');

        $this->withToken($this->tokenDelTesorero())
            ->putJson(self::RUTA.'/'.$colilla->id.'/aprobar')->assertStatus(200);

        $this->assertSame('APROBADA',
            DB::selectOne('SELECT estado FROM colillas_inscripcion WHERE id=?', [$colilla->id])->estado);

        $this->assertSame('PAGADA',
            DB::selectOne('SELECT estado FROM ordenes_inscripcion WHERE codigo=?', [$codigo])->estado,
            'La orden no avanzó a PAGADA, así que aprobar no sirvió de nada.');
    }

    public function test_rechazar_sin_decir_por_que_es_422(): void
    {
        $codigo = $this->unCodigoAcunado();
        $this->postJson(self::RUTA.'/'.$codigo, ['referencia' => 'REC-9'])->assertStatus(200);

        $colilla = DB::selectOne('SELECT id FROM colillas_inscripcion');

        $this->withToken($this->tokenDelTesorero())
            ->putJson(self::RUTA.'/'.$colilla->id.'/rechazar')->assertStatus(422);

        $this->assertSame('PENDIENTE',
            DB::selectOne('SELECT estado FROM colillas_inscripcion WHERE id=?', [$colilla->id])->estado);
    }

    /**
     * Resolver dos veces dejaría dos fechas y dos firmantes sobre la misma fila, y
     * nadie sabría cuál valió.
     */
    public function test_un_comprobante_ya_resuelto_no_se_vuelve_a_tocar(): void
    {
        $codigo = $this->unCodigoAcunado();
        $this->postJson(self::RUTA.'/'.$codigo, ['referencia' => 'REC-5'])->assertStatus(200);

        $colilla = DB::selectOne('SELECT id FROM colillas_inscripcion');
        $token = $this->tokenDelTesorero();

        $this->withToken($token)->putJson(self::RUTA.'/'.$colilla->id.'/aprobar')->assertStatus(200);
        $this->withToken($token)->putJson(self::RUTA.'/'.$colilla->id.'/rechazar',
            ['motivo' => 'me arrepentí'])->assertStatus(422);

        $this->assertSame('APROBADA',
            DB::selectOne('SELECT estado FROM colillas_inscripcion WHERE id=?', [$colilla->id])->estado);
    }

    public function test_la_bandeja_solo_trae_los_pendientes(): void
    {
        $codigo = $this->unCodigoAcunado();
        $this->postJson(self::RUTA.'/'.$codigo, ['referencia' => 'REC-A'])->assertStatus(200);

        $token = $this->tokenDelTesorero();

        $cuerpo = $this->withToken($token)->getJson(self::RUTA.'/pendientes')
            ->assertStatus(200)->json();

        $this->assertCount(1, $cuerpo['pendientes']);
        $this->assertSame($codigo, $cuerpo['pendientes'][0]['codigo']);

        $colilla = DB::selectOne('SELECT id FROM colillas_inscripcion');
        $this->withToken($token)->putJson(self::RUTA.'/'.$colilla->id.'/aprobar')->assertStatus(200);

        $this->assertCount(0, $this->withToken($token)->getJson(self::RUTA.'/pendientes')
            ->json('pendientes'), 'La bandeja sigue enseñando lo ya resuelto.');
    }

    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Un fichero subido **como llega uno de verdad**: con su contenido en disco y
     * con el tipo husmeado, no declarado.
     *
     * El quinto argumento en `true` es el modo de prueba —salta
     * `is_uploaded_file()`, que no se cumple fuera de una petición real— y **no
     * afecta a cómo se detecta el tipo**: eso se mide del contenido igual.
     */
    private function subidaDeVerdad(string $nombre, string $contenido): UploadedFile
    {
        $ruta = tempnam(sys_get_temp_dir(), 'colilla').'-'.$nombre;
        file_put_contents($ruta, $contenido);

        return new UploadedFile($ruta, $nombre, 'image/jpeg', null, true);
    }

    private function cuantasColillas(): int
    {
        return (int) DB::selectOne('SELECT COUNT(*) c FROM colillas_inscripcion')->c;
    }

    /** Un código de verdad, acuñado por la ruta que lo acuña. */
    private function unCodigoAcunado(): string
    {
        $r = $this->withToken($this->tokenDelPersonalLlano())
            ->postJson('/api/informes/formularios-inscripcion', ['modo' => 'nuevos', 'cantidad' => 1]);

        $r->assertStatus(200);

        return $r->json('formularios.0.codigo');
    }

    /**
     * Alguien que pueda resolver comprobantes.
     *
     * Se usa un superusuario porque `esAdministrativo` es la mitad que SIEMPRE
     * existe: `years.tesorero_id` está en NULL en los cuatro años del seed —medido—
     * así que un test que dependiera del tesorero nombrado no mediría nada hoy.
     */
    private function tokenDelTesorero(): string
    {
        $super = DB::selectOne('SELECT username FROM users
            WHERE is_superuser=1 AND deleted_at IS NULL AND is_active=1 LIMIT 1');

        $this->assertNotNull($super, 'El seed no tiene superusuarios: esto no mediría nada.');

        return $this->tokenDe($super->username);
    }
}
