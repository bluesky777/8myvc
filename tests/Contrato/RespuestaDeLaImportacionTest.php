<?php

namespace Tests\Contrato;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as EscritorXlsx;

/**
 * Qué contesta `POST importar/algo/{year}` — y por qué dejó de ser una cadena.
 *
 * Hasta el 20 sep 2026 devolvía `'Importados.'` en `text/html`, y eso rompía a
 * quien no lo leía. Medido contra el docker antes de tocarlo:
 *
 *     status 200 · Content-Type 'text/html; charset=utf-8' · cuerpo 'Importados.'
 *     JSON válido: NO
 *
 * `app2` sube por `comunes/subida/subida.ts`, que llama a `http.post` sin
 * `responseType` —o sea `'json'`—, y Angular convierte un 2xx cuyo cuerpo no
 * parsea en un error. Las dos pantallas nuevas enseñaban «no se pudieron
 * importar» **después de una importación que había funcionado**. Lo predijo la
 * sesión del front leyendo Angular; esta medición lo confirmó.
 *
 * Así que el primer test de aquí no es de forma: es el que impide que alguien
 * vuelva a devolver texto por esta puerta.
 *
 * Lo que NO cambia y también se fija: **los errores siguen siendo texto**
 * (Joseth, 20 sep). `AlumnosCtrl.ts:1021` pinta `status + ': ' + data`, y con
 * un JSON ahí saldría `500: [object Object]`.
 */
class RespuestaDeLaImportacionTest extends CasoDeContrato
{
    /**
     * El cuerpo parsea como JSON.
     *
     * Es literalmente lo que Angular hace con él, y por eso el test lo hace
     * igual en vez de mirar solo el `Content-Type`: un `application/json` con
     * un cuerpo que no parsea rompería igual.
     */
    public function test_la_respuesta_es_json_y_no_una_cadena(): void
    {
        [$token, $year] = $this->personalYSuYear();

        $r = $this->importar($this->exportacionDeAlumnos($token), $token, $year);

        $r->assertStatus(200);
        $this->assertStringContainsString('application/json', (string) $r->headers->get('Content-Type'));

        json_decode($r->getContent());
        $this->assertSame(
            JSON_ERROR_NONE,
            json_last_error(),
            'El cuerpo no parsea como JSON, así que `app2` lo convierte en un error después de una importación buena.'
        );

        $r->assertJson(['ok' => true]);
        $this->assertIsInt($r->json('importacion_id'));
    }

    /**
     * Los números de lo que hizo, que es con lo que el informe final compara lo
     * que el ensayo prometió.
     */
    public function test_dice_cuantas_filas_y_que_hizo_con_ellas(): void
    {
        [$token, $year] = $this->personalYSuYear();

        $hechos = $this->importar($this->exportacionDeAlumnos($token), $token, $year)
            ->assertStatus(200)
            ->json('hechos');

        // `ya_estaban_hechas` se llamaba `saltadas` hasta el 20 sep, y se
        // renombró porque el ensayo tiene un `se_saltan` que cuenta OTRA COSA
        // —las filas sin primer nombre, que no se escriben nunca— y el informe
        // final resta los dos conjuntos. Ver el test de la comparación en
        // `EnsayoDeLaImportacionTest`.
        foreach (['filas', 'creados', 'actualizados', 'reencontrados', 'usuarios_creados',
            'matriculas_creadas', 'ya_estaban_hechas', 'sin_primer_nombre'] as $clave) {
            $this->assertArrayHasKey($clave, $hechos);
        }

        // La hoja que produce el export son los alumnos que ya están, así que
        // reimportarla los actualiza a todos y no crea a ninguno. Si esto
        // empezara a crear, el duplicado estaría de vuelta.
        $this->assertGreaterThan(0, $hechos['filas']);
        $this->assertSame(0, $hechos['creados']);
        $this->assertSame($hechos['filas'], $hechos['actualizados']);
    }

    /**
     * Lo que no se supo traducir sale por la respuesta.
     *
     * La Fase 1 dejó el aviso escrito en `ImporterFixer::$avisos` y hasta hoy
     * moría en memoria: la importación contestaba `'Importados.'` igual. Sin
     * esto, la pantalla que tiene que preguntar no tiene qué preguntar.
     */
    public function test_lo_que_no_se_entendio_viaja_en_la_respuesta(): void
    {
        [$token, $year] = $this->personalYSuYear();

        $archivo = $this->conTipoDeDocumentoInventado($this->exportacionDeAlumnos($token));

        $avisos = $this->importar($archivo, $token, $year)
            ->assertStatus(200)
            ->json('avisos');

        $delTipo = array_values(array_filter($avisos, fn ($a) => $a['campo'] === 'tipo_de_documento'));

        $this->assertNotEmpty($delTipo, 'Un tipo de documento que no está en el catálogo tiene que dejar aviso.');
        $this->assertSame('CARNÉ DIPLOMÁTICO', $delTipo[0]['valor']);
        $this->assertStringContainsString('Tarjeta de Identidad', $delTipo[0]['motivo']);
    }

    /**
     * Y la otra mitad de la Fase 1: «no puso nada» NO deja aviso.
     *
     * Las dos cosas acaban en Tarjeta de Identidad, y son distintas. Si esto se
     * rompiera, la pantalla preguntaría por cada celda vacía de las dieciséis
     * bases y el aviso dejaría de significar nada.
     */
    public function test_una_celda_vacia_no_deja_aviso(): void
    {
        [$token, $year] = $this->personalYSuYear();

        $archivo = $this->conTipoDeDocumentoVacio($this->exportacionDeAlumnos($token));

        $avisos = $this->importar($archivo, $token, $year)
            ->assertStatus(200)
            ->json('avisos');

        $this->assertSame(
            [],
            array_values(array_filter($avisos, fn ($a) => $a['campo'] === 'tipo_de_documento')),
            'Una celda vacía no es un valor que no se entendió: es que no venía.'
        );
    }

    // ── andamio ──────────────────────────────────────────────────────────────

    /** @return array{0: string, 1: int} */
    private function personalYSuYear(): array
    {
        $usuario = $this->usuarioDeTipo('Usuario');

        $year = DB::table('periodos')
            ->join('years', 'years.id', '=', 'periodos.year_id')
            ->where('periodos.id', $usuario->periodo_id)
            ->value('years.year');

        return [$this->tokenDe($usuario->username), (int) $year];
    }

    private function exportacionDeAlumnos(string $token): string
    {
        $r = $this->get('/api/users/export', ['Authorization' => 'Bearer '.$token])->assertStatus(200);

        $copia = tempnam(sys_get_temp_dir(), 'importar').'.xlsx';
        copy($this->archivoDescargado($r), $copia);

        return $copia;
    }

    private function importar(string $archivo, string $token, int $year)
    {
        return $this->post(
            "/api/importar/algo/{$year}",
            ['file' => new UploadedFile($archivo, 'alumnos.xlsx', null, null, true)],
            ['Authorization' => 'Bearer '.$token]
        );
    }

    private function conTipoDeDocumentoInventado(string $archivo): string
    {
        return $this->escribirEnLaPrimeraFila($archivo, 'CARNÉ DIPLOMÁTICO');
    }

    private function conTipoDeDocumentoVacio(string $archivo): string
    {
        return $this->escribirEnLaPrimeraFila($archivo, null);
    }

    private function escribirEnLaPrimeraFila(string $archivo, ?string $valor): string
    {
        $libro = IOFactory::load($archivo);
        $pestana = $libro->getSheet(0);

        $columna = $this->columnasDe($pestana)['tipo_de_documento'];
        $pestana->setCellValue($columna.'3', $valor);

        $ruta = tempnam(sys_get_temp_dir(), 'importar').'.xlsx';
        (new EscritorXlsx($libro))->save($ruta);

        return $ruta;
    }

    /** @return array<string, string> */
    private function columnasDe($hoja): array
    {
        $columnas = [];

        foreach ($hoja->getRowIterator(2, 2) as $fila) {
            foreach ($fila->getCellIterator() as $celda) {
                $titulo = trim((string) $celda->getValue());

                if ($titulo !== '') {
                    $columnas[strtolower(str_replace(' ', '_', $titulo))] = $celda->getColumn();
                }
            }
        }

        return $columnas;
    }
}
