<?php

namespace Tests\Contrato;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as EscritorXlsx;

/**
 * Que lo que la persona decide LLEGUE a lo que se escribe.
 *
 * Hasta el 21 sep 2026 las instrucciones se guardaban y se devolvían **y no las
 * interpretaba nadie**: el coordinador veía qué iba a pasar y no podía
 * cambiarlo. El ensayo decía «se guardará TARJETA DE IDENTIDAD en 37 alumnos» y
 * eso era todo lo que se podía hacer al respecto.
 *
 * Las equivalencias se aplican dentro de `ImporterFixer` **porque por ahí pasan
 * los dos caminos**, y ésa es toda la garantía de que el plan que se enseña es
 * el que se cumple. El primer test de aquí es el que lo demuestra.
 */
class QueElImportadorObedezcaTest extends CasoDeContrato
{
    /**
     * EL CICLO ENTERO: el ensayo avisa, la persona decide, el ensayo lo refleja
     * y la importación lo escribe.
     *
     * Es el test que sostiene la entrega. Si el ensayo no reflejara la
     * corrección, la pantalla enseñaría el plan de ANTES de decidir y el
     * resultado sería otro — que es el fallo que este módulo persigue, con un
     * paso más de disimulo.
     */
    public function test_el_ciclo_entero_avisar_decidir_reflejar_escribir(): void
    {
        [$token, $year] = $this->personalYSuYear();
        $archivo = $this->conTipoDeDocumento($this->exportacionDeAlumnos($token), 'CARNÉ DIPLOMÁTICO');

        // 1. El ensayo avisa de que no lo entiende.
        $sinDecidir = $this->ensayar($archivo, $token, $year)->assertStatus(200);

        $this->assertNotEmpty(
            collect($sinDecidir->json('valores_no_reconocidos'))->firstWhere('valor', 'CARNÉ DIPLOMÁTICO'),
            'Sin decisión, el valor raro tiene que salir como no reconocido.'
        );

        // 2. La persona decide, y 3. el ensayo lo refleja: ya no hay aviso.
        $respuestas = [
            'version' => 1,
            'huella' => $sinDecidir->json('huella'),
            'vocabularios' => [
                ['columna' => 'tipo_de_documento', 'valor_original' => 'CARNÉ DIPLOMÁTICO',
                    'decision' => 'usar_id', 'id' => 1],
            ],
        ];

        $conDecision = $this->ensayar($archivo, $token, $year, $respuestas)->assertStatus(200);

        $this->assertEmpty(
            collect($conDecision->json('valores_no_reconocidos'))->firstWhere('valor', 'CARNÉ DIPLOMÁTICO'),
            'Con la decisión puesta, el ensayo tiene que enseñar el plan CORREGIDO.'
        );
        $this->assertSame(1, $conDecision->json('respuestas.veces_que_se_usaron'));

        // 4. Y la importación lo escribe.
        $documento = $this->documentoDeLaFilaTres($archivo);

        $this->importar($archivo, $token, $year, $respuestas)->assertStatus(200);

        $this->assertSame(
            1,
            (int) DB::selectOne('SELECT tipo_doc FROM alumnos WHERE documento = ? ORDER BY id LIMIT 1', [$documento])->tipo_doc,
            'Lo que la persona decidió no llegó a la ficha del alumno.'
        );
    }

    /**
     * Sin decisión, el defecto sigue siendo el defecto.
     *
     * El control del test de arriba: si esto escribiera 1 sin que nadie lo
     * pidiera, aquel no demostraría nada.
     */
    public function test_sin_decision_se_guarda_el_defecto_de_siempre(): void
    {
        [$token, $year] = $this->personalYSuYear();
        $archivo = $this->conTipoDeDocumento($this->exportacionDeAlumnos($token), 'CARNÉ DIPLOMÁTICO');
        $documento = $this->documentoDeLaFilaTres($archivo);

        $this->importar($archivo, $token, $year)->assertStatus(200);

        $this->assertSame(
            3,
            (int) DB::selectOne('SELECT tipo_doc FROM alumnos WHERE documento = ? ORDER BY id LIMIT 1', [$documento])->tipo_doc,
            'Sin decisión tiene que seguir cayendo en Tarjeta de Identidad, que es lo que hace hoy.'
        );
    }

    /**
     * `a_revisar` NO aplica nada, y es una decisión y no un olvido.
     *
     * Significa «no lo decido todavía»: el valor sigue sin reconocerse y sigue
     * dejando su aviso. Convertirlo en el valor por defecto sería decidir por
     * alguien y no decirlo, que es justo lo que la Fase 1 quitó.
     */
    public function test_a_revisar_no_decide_nada_y_el_aviso_sigue(): void
    {
        [$token, $year] = $this->personalYSuYear();
        $archivo = $this->conTipoDeDocumento($this->exportacionDeAlumnos($token), 'CARNÉ DIPLOMÁTICO');

        $r = $this->ensayar($archivo, $token, $year, [
            'vocabularios' => [
                ['columna' => 'tipo_de_documento', 'valor_original' => 'CARNÉ DIPLOMÁTICO', 'decision' => 'a_revisar'],
            ],
        ])->assertStatus(200);

        $this->assertNotEmpty(
            collect($r->json('valores_no_reconocidos'))->firstWhere('valor', 'CARNÉ DIPLOMÁTICO'),
            'Marcar «a revisar» no decide el valor: el aviso tiene que seguir ahí.'
        );
        $this->assertSame(1, $r->json('respuestas.vocabularios_a_revisar'));
        $this->assertSame(0, $r->json('respuestas.veces_que_se_usaron'));
    }

    /**
     * El estado de matrícula también obedece — y ése no pasaba por el traductor.
     *
     * Lo leía el importador directamente, así que una equivalencia puesta allí
     * la habría visto la subida y NO el ensayo: el plan habría enseñado un
     * estado distinto del que se escribe.
     */
    public function test_el_estado_de_matricula_tambien_obedece(): void
    {
        [$token, $year] = $this->personalYSuYear();
        $archivo = $this->conEstado($this->exportacionDeAlumnos($token), 'Activo');
        $documento = $this->documentoDeLaFilaTres($archivo);

        $this->importar($archivo, $token, $year, [
            'vocabularios' => [
                ['columna' => 'estado_matricula', 'valor_original' => 'Activo',
                    'decision' => 'usar_valor', 'valor' => 'ASIS'],
            ],
        ])->assertStatus(200);

        $estado = DB::selectOne(
            'SELECT m.estado FROM matriculas m
             INNER JOIN alumnos a ON a.id = m.alumno_id
             WHERE a.documento = ? AND m.deleted_at IS NULL ORDER BY m.id DESC LIMIT 1',
            [$documento]
        )->estado;

        $this->assertSame('ASIS', $estado,
            '«Activo» no cabe en varchar(4) y sin decisión no se escribe; con decisión tiene que entrar el código elegido.');
    }

    /**
     * UNAS RESPUESTAS DE OTRO FICHERO NO SE APLICAN A ÉSTE.
     *
     * Es el agujero que este módulo lleva rodeando: alguien aprueba trece
     * decisiones, cambia una celda y sube. Las respuestas siguen encajando
     * —casan por nombre de columna, no por contenido— y se aplicarían a un libro
     * que nadie revisó. Los números saldrían igual, sólo que serían otros.
     */
    public function test_unas_respuestas_de_otro_fichero_se_rechazan(): void
    {
        [$token, $year] = $this->personalYSuYear();
        $archivo = $this->exportacionDeAlumnos($token);

        $respuestas = [
            'huella' => str_repeat('a', 64),
            'vocabularios' => [
                ['columna' => 'tipo_de_documento', 'valor_original' => 'X', 'decision' => 'usar_id', 'id' => 1],
            ],
        ];

        $this->importar($archivo, $token, $year, $respuestas)
            ->assertStatus(422)
            ->assertJson(['ok' => false]);

        $this->ensayar($archivo, $token, $year, $respuestas)->assertStatus(422);
    }

    /** Sin huella se aceptan: exigirla rompería a quien mande instrucciones a mano. */
    public function test_sin_huella_las_respuestas_se_aceptan(): void
    {
        [$token, $year] = $this->personalYSuYear();
        $archivo = $this->conTipoDeDocumento($this->exportacionDeAlumnos($token), 'CARNÉ DIPLOMÁTICO');

        $this->ensayar($archivo, $token, $year, [
            'vocabularios' => [
                ['columna' => 'tipo_de_documento', 'valor_original' => 'CARNÉ DIPLOMÁTICO',
                    'decision' => 'usar_id', 'id' => 1],
            ],
        ])->assertStatus(200)->assertJson(['respuestas' => ['veces_que_se_usaron' => 1]]);
    }

    /**
     * LO QUE NO SE APLICA TODAVÍA SE DICE, con el motivo.
     *
     * Aceptar una sección y no interpretarla sin decirlo es el mismo silencio
     * que este módulo empezó quitando: la pantalla prometería algo que no
     * ocurre.
     */
    public function test_lo_que_no_se_interpreta_se_declara(): void
    {
        [$token, $year] = $this->personalYSuYear();

        $r = $this->ensayar($this->exportacionDeAlumnos($token), $token, $year, [
            'repetidos' => [['hoja' => '6', 'fila_del_libro' => 15, 'decision' => 'es_el_mismo', 'alumno_id' => 1055]],
            'hojas' => [['nombre' => 'Consolidado', 'decision' => 'omitir']],
        ])->assertStatus(200);

        $secciones = array_column($r->json('respuestas.no_aplicadas'), 'seccion');

        $this->assertContains('repetidos', $secciones);
        $this->assertContains('hojas', $secciones);
        $this->assertNotEmpty($r->json('respuestas.no_aplicadas')[0]['motivo'],
            'Sin motivo, «no aplicada» no se distingue de «se perdió».');
    }

    /** Y sin respuestas el campo es null, no un resumen de ceros que parece que hubo. */
    public function test_sin_respuestas_el_campo_va_nulo(): void
    {
        [$token, $year] = $this->personalYSuYear();

        $this->ensayar($this->exportacionDeAlumnos($token), $token, $year)
            ->assertStatus(200)
            ->assertJson(['respuestas' => null]);
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

        $copia = tempnam(sys_get_temp_dir(), 'obe').'.xlsx';
        copy($this->archivoDescargado($r), $copia);

        return $copia;
    }

    private function ensayar(string $archivo, string $token, int $year, ?array $respuestas = null)
    {
        $cuerpo = ['file' => new UploadedFile($archivo, 'alumnos.xlsx', null, null, true)];

        if ($respuestas !== null) {
            $cuerpo['respuestas'] = json_encode($respuestas);
        }

        return $this->post("/api/importar/alumnos/ensayo/{$year}", $cuerpo, ['Authorization' => 'Bearer '.$token]);
    }

    private function importar(string $archivo, string $token, int $year, ?array $respuestas = null)
    {
        $cuerpo = ['file' => new UploadedFile($archivo, 'alumnos.xlsx', null, null, true)];

        if ($respuestas !== null) {
            $cuerpo['respuestas'] = json_encode($respuestas);
        }

        return $this->post("/api/importar/algo/{$year}", $cuerpo, ['Authorization' => 'Bearer '.$token]);
    }

    private function conTipoDeDocumento(string $archivo, string $valor): string
    {
        return $this->escribir($archivo, 'tipo_de_documento', $valor);
    }

    private function conEstado(string $archivo, string $valor): string
    {
        return $this->escribir($archivo, 'estado_matricula', $valor);
    }

    private function escribir(string $archivo, string $columna, string $valor): string
    {
        $libro = IOFactory::load($archivo);
        $hoja = $libro->getSheet(0);

        $hoja->setCellValue($this->columnasDe($hoja)[$columna].'3', $valor);

        $ruta = tempnam(sys_get_temp_dir(), 'obe').'.xlsx';
        (new EscritorXlsx($libro))->save($ruta);

        return $ruta;
    }

    private function documentoDeLaFilaTres(string $archivo): string
    {
        $hoja = IOFactory::load($archivo)->getSheet(0);

        return (string) $hoja->getCell($this->columnasDe($hoja)['nro_de_documento'].'3')->getValue();
    }

    /** @return array<string, string> */
    private function columnasDe($hoja): array
    {
        $columnas = [];

        foreach ($hoja->getRowIterator(2, 2) as $fila) {
            foreach ($fila->getCellIterator() as $celda) {
                $titulo = trim((string) $celda->getValue());

                if ($titulo !== '') {
                    $slug = strtr(mb_strtolower($titulo, 'UTF-8'), [
                        'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n',
                    ]);

                    $columnas[str_replace(' ', '_', $slug)] = $celda->getColumn();
                }
            }
        }

        return $columnas;
    }
}
