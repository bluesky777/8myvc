<?php

namespace Tests\Contrato;

use App\Support\NotaDeOtroColegio;
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
        $this->assertSame('application/octet-stream', $bajada->headers->get('Content-Type'));

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

    /*
     * NIVEL 2 (24 sep 2026): la definitiva escrita a mano, convertida POR TRAMOS a la escala de
     * aquí, y el año sale en el certificado de todos los años con la misma tupla que los cursados.
     */
    public function test_las_notas_se_convierten_por_tramos_y_salen_en_el_certificado(): void
    {
        $destino = NotaDeOtroColegio::destino();
        if ($destino === null) {
            $this->markTestSkipped('El año actual del seed no tiene escala de valoración.');
        }

        $alumno = $this->alumnoId();
        $h = $this->conToken($this->tokenDe($this->usuarioDeTipo('Usuario')->username));
        $ano = $this->postJson("/api/otros-colegios/alumno/{$alumno}",
            ['year' => 2019, 'colegio_nombre' => 'Colegio de Prueba', 'grado_texto' => 'Quinto'], $h)->json('id');

        $this->olvidarControladores();
        $this->putJson("/api/otros-colegios/{$ano}/notas", [
            'escala' => ['min' => 1, 'max' => 5, 'aprueba' => 3],
            'notas' => [
                ['asignatura_texto' => 'Matemáticas', 'intensidad' => 5, 'nota_original' => '3,0'],
                ['asignatura_texto' => 'Inglés', 'intensidad' => 2, 'nota_original' => '5'],
                ['asignatura_texto' => 'Artística', 'nota_original' => 'S'],
                ['asignatura_texto' => 'Religión', 'nota_original' => 'no sé'],
                ['asignatura_texto' => '', 'nota_original' => '4'],
            ],
        ], $h)->assertStatus(200)->assertJson(['guardadas' => 4]);

        $this->olvidarControladores();
        $notas = collect($this->getJson("/api/otros-colegios/{$ano}/notas", $h)->json('notas'))
            ->keyBy('asignatura_texto');

        // Lo que allá aprobaba --justo la mínima--, aquí aprueba justo con la mínima.
        $this->assertEquals($destino['aprueba'], (float) $notas['Matemáticas']['nota']);
        $this->assertEquals($destino['max'], (float) $notas['Inglés']['nota']);
        $this->assertSame('3,0', $notas['Matemáticas']['nota_original']);
        $this->assertNotNull($notas['Artística']['nota'], 'Una «S» vale el punto medio de Superior.');
        $this->assertNull($notas['Religión']['nota'], 'Lo que no se entiende no se inventa.');

        $this->olvidarControladores();
        $certificados = $this->getJson("/api/otros-colegios/alumno/{$alumno}/certificados", $h)->assertStatus(200)->json();
        $este = collect($certificados)->first(fn ($c) => (int) $c[1]['year'] === 2019);
        $this->assertNotNull($este, 'El año con notas tiene que salir en el certificado.');
        $this->assertSame('Colegio de Prueba', $este[4]['colegio_nombre']);
        $this->assertSame([], $este[1]['periodos'], 'Sólo la definitiva: sin columnas de periodo.');
    }

    public function test_un_ano_solo_con_documento_no_sale_en_el_certificado(): void
    {
        $alumno = $this->alumnoId();
        $h = $this->conToken($this->tokenDe($this->usuarioDeTipo('Usuario')->username));
        $this->postJson("/api/otros-colegios/alumno/{$alumno}", ['year' => 2018, 'colegio_nombre' => 'Otro'], $h);

        $this->olvidarControladores();
        $certificados = $this->getJson("/api/otros-colegios/alumno/{$alumno}/certificados", $h)->json();
        $this->assertNull(collect($certificados)->first(fn ($c) => (int) $c[1]['year'] === 2018));
    }
}
