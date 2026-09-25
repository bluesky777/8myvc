<?php

namespace Tests\Contrato;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * EL ARCHIVO DE OTROS COLEGIOS Y DE AÑOS ANTIGUOS (24 sep 2026). Ver `OtrosColegiosController`.
 *
 * Lo que tiene que sostenerse: quien edita alumnos sube, ve y descarga; nadie más, ni siquiera
 * otro docente; el fichero no sale sin token; y sólo entran PDF, JPG y PNG de verdad.
 */
class OtrosColegiosTest extends CasoDeContrato
{
    private array $carpetas = [];

    protected function tearDown(): void
    {
        // La base vuelve sola (DatabaseTransactions); el disco no.
        foreach ($this->carpetas as $carpeta) {
            File::deleteDirectory($carpeta);
        }
        parent::tearDown();
    }

    private function alumnoId(): int
    {
        $alumno = DB::selectOne('SELECT m.alumno_id FROM matriculas m
            WHERE m.deleted_at IS NULL AND m.estado IN ("MATR","ASIS") ORDER BY m.id LIMIT 1');
        $this->assertNotNull($alumno, 'El seed no tiene ninguna matrícula.');
        $this->carpetas[] = storage_path('app/archivo-alumnos/'.$alumno->alumno_id);

        return (int) $alumno->alumno_id;
    }

    private function conToken(string $token): array
    {
        return ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];
    }

    public function test_quien_edita_alumnos_crea_el_ano_sube_el_documento_y_lo_descarga(): void
    {
        $alumno = $this->alumnoId();
        $h = $this->conToken($this->tokenDe($this->usuarioDeTipo('Usuario')->username));

        $ano = $this->postJson("/api/otros-colegios/alumno/{$alumno}", [
            'year' => 2023, 'grado_texto' => 'Cuarto (4°)',
            'colegio_nombre' => 'Centro Educativo Rural La Esperanza', 'colegio_municipio' => 'Samaniego',
        ], $h)->assertStatus(200)->json('id');

        $this->olvidarControladores();
        $documento = $this->post("/api/otros-colegios/{$ano}/documento",
            ['file' => UploadedFile::fake()->image('certificado.jpg', 60, 80)], $h)
            ->assertStatus(200)->json('id');

        $this->olvidarControladores();
        $lista = $this->getJson("/api/otros-colegios/alumno/{$alumno}", $h)->assertStatus(200)->json();
        $esteAno = collect($lista)->firstWhere('id', $ano);
        $this->assertSame('Centro Educativo Rural La Esperanza', $esteAno['colegio_nombre']);
        $this->assertFalse($esteAno['propio']);
        $this->assertSame('certificado.jpg', $esteAno['documentos'][0]['nombre_original']);

        $this->olvidarControladores();
        $bajada = $this->get("/api/otros-colegios/documentos/{$documento}", $h)->assertStatus(200);
        $this->assertInstanceOf(BinaryFileResponse::class, $bajada->baseResponse);
        $this->assertSame('image/jpeg', $bajada->headers->get('Content-Type'));

        // Y el fichero NO está en `public/`: sólo sale por la ruta, con token.
        $this->assertStringStartsWith(storage_path('app/archivo-alumnos/'),
            $bajada->baseResponse->getFile()->getPathname());
    }

    public function test_un_ano_antiguo_del_propio_colegio_no_pide_colegio(): void
    {
        $alumno = $this->alumnoId();
        $h = $this->conToken($this->tokenDe($this->usuarioDeTipo('Usuario')->username));

        $this->postJson("/api/otros-colegios/alumno/{$alumno}",
            ['year' => 2010, 'propio' => true, 'grado_texto' => 'Sexto', 'libro' => '3', 'folio' => '118'], $h)
            ->assertStatus(200);

        $this->olvidarControladores();
        $this->postJson("/api/otros-colegios/alumno/{$alumno}", ['year' => 2010], $h)
            ->assertStatus(422);
    }

    public function test_sin_token_el_documento_no_sale(): void
    {
        $this->getJson('/api/otros-colegios/documentos/1')->assertStatus(401);
    }

    public function test_un_alumno_no_entra(): void
    {
        $alumno = $this->alumnoId();
        $h = $this->conToken($this->tokenDe($this->usuarioDeTipo('Alumno')->username));

        $this->getJson("/api/otros-colegios/alumno/{$alumno}", $h)->assertStatus(403);
    }

    public function test_un_docente_que_no_edita_alumnos_no_ve_ni_sube(): void
    {
        $profesor = $this->usuarioDeTipo('Profesor');
        $puede = DB::selectOne('SELECT y.profes_can_edit_alumnos AS puede FROM periodos p
            INNER JOIN years y ON y.id = p.year_id WHERE p.id = ?', [$profesor->periodo_id]);

        if ($puede && $puede->puede) {
            $this->markTestSkipped('En el año de este docente los profesores sí editan alumnos.');
        }

        $alumno = $this->alumnoId();
        $h = $this->conToken($this->tokenDe($profesor->username));

        $this->getJson("/api/otros-colegios/alumno/{$alumno}", $h)->assertStatus(403);
        $this->olvidarControladores();
        $this->postJson("/api/otros-colegios/alumno/{$alumno}",
            ['year' => 2023, 'colegio_nombre' => 'Otro'], $h)->assertStatus(403);
    }

    public function test_solo_entran_pdf_jpg_y_png_de_verdad(): void
    {
        $alumno = $this->alumnoId();
        $h = $this->conToken($this->tokenDe($this->usuarioDeTipo('Usuario')->username));
        $ano = $this->postJson("/api/otros-colegios/alumno/{$alumno}",
            ['year' => 2023, 'colegio_nombre' => 'Otro'], $h)->json('id');

        // Una subida DE VERDAD, no `fake()`: el `fake` declara su tipo por la extensión y un
        // «.pdf» con texto dentro diría ser PDF (ver `ColillasInscripcionTest`).
        $ruta = tempnam(sys_get_temp_dir(), 'doc');
        file_put_contents($ruta, 'esto no es un pdf');

        $this->olvidarControladores();
        $this->post("/api/otros-colegios/{$ano}/documento",
            ['file' => new UploadedFile($ruta, 'boletin.pdf', null, null, true)], $h)
            ->assertStatus(422);
    }
}
