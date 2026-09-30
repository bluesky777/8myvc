<?php

namespace Tests\Contrato;

use App\Services\PuntoDeControlDeImportacion;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as EscritorXlsx;

/**
 * **Lo que la importación de alumnos cambió**, en `importaciones.cambios` y en
 * `GET auditoria/alumnos/datos`.
 *
 * Decisión de Joseth del 30 sep 2026: un registro por importación
 * (`{alumno_id: {campo: [antes, después]}}`), no una línea de `auditoria` por alumno.
 * Los casos salen del export del seed, que es la plantilla que la gente rellena:
 * importarlo sin tocar no cambia nada, y cada test toca la celda que quiere medir.
 */
class LoQueLaImportacionCambioTest extends CasoDeContrato
{
    private string $token;

    private int $year;

    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();

        $usuario = $this->usuarioDeTipo('Usuario');
        $this->darPermisoDeAuditoria((int) $usuario->id);
        $this->userId = (int) $usuario->id;
        $this->token = $this->tokenDe($usuario->username);
        $this->year = (int) DB::table('periodos')
            ->join('years', 'years.id', '=', 'periodos.year_id')
            ->where('periodos.id', $usuario->periodo_id)
            ->value('years.year');
    }

    public function test_importar_el_export_sin_tocarlo_no_deja_cambios(): void
    {
        $this->importar($this->exportacion())->assertStatus(200)->assertJsonPath('terminado', true);

        $this->assertNull($this->ultimaImportacion()->cambios,
            'Importar lo exportado anotó cambios que no hubo: '.$this->ultimaImportacion()->cambios);

        $datos = $this->datos(['tipos' => 'importacion']);
        $this->assertSame(0, $datos['total']);
    }

    public function test_un_nombre_cambiado_queda_con_su_antes_y_su_despues(): void
    {
        $archivo = $this->exportacion();
        [$hoja, $fila] = $this->primeraFilaConId($archivo);
        $alumno = $this->idDeLaFila($archivo, $hoja, $fila);
        $antes = (string) DB::table('alumnos')->where('id', $alumno)->value('nombres');

        $libro = IOFactory::load($archivo);
        $pestana = $libro->getSheetByName($hoja);
        $c = $this->columnasDe($pestana);
        $pestana->setCellValue($c['primer_nombre'].$fila, 'ZENAIDA');
        $pestana->setCellValue($c['segundo_nombre'].$fila, 'PRUEBA');

        $this->importar($this->guardar($libro))->assertStatus(200);

        $cambios = json_decode((string) $this->ultimaImportacion()->cambios, true);
        $this->assertSame([(string) $alumno => ['nombres' => [$antes, 'ZENAIDA PRUEBA']]],
            $this->conClavesDeTexto($cambios));

        // Nunca la contraseña, ni nada del usuario.
        $this->assertStringNotContainsString('password', (string) $this->ultimaImportacion()->cambios);
    }

    public function test_un_alumno_nuevo_va_marcado_como_creado(): void
    {
        $archivo = $this->exportacion();
        [$hoja, $fila] = $this->primeraFilaConId($archivo);

        $libro = IOFactory::load($archivo);
        $pestana = $libro->getSheetByName($hoja);
        $c = $this->columnasDe($pestana);
        $pestana->setCellValue($c['id'].$fila, null);
        $pestana->setCellValue($c['nro_de_documento'].$fila, '99887766554');
        $pestana->setCellValue($c['primer_nombre'].$fila, 'NUEVA');
        $pestana->setCellValue($c['segundo_nombre'].$fila, null);

        $this->importar($this->guardar($libro))->assertStatus(200)->assertJsonPath('hechos.creados', 1);

        $nuevo = (int) DB::table('alumnos')->where('documento', '99887766554')->value('id');
        $cambios = json_decode((string) $this->ultimaImportacion()->cambios, true);

        $this->assertArrayHasKey($nuevo, $cambios);
        $this->assertTrue($cambios[$nuevo]['_creado']);
        $this->assertSame([null, 'NUEVA'], $cambios[$nuevo]['nombres']);
        $this->assertSame([null, '99887766554'], $cambios[$nuevo]['documento']);
    }

    public function test_un_acudiente_cambiado_va_bajo_su_alumno(): void
    {
        $archivo = $this->exportacion();
        [$hoja, $fila, $acudiente] = $this->primeraFilaConAcudiente($archivo);
        $alumno = $this->idDeLaFila($archivo, $hoja, $fila);
        $antes = DB::table('acudientes')->where('id', $acudiente)->value('ocupacion');

        $libro = IOFactory::load($archivo);
        $pestana = $libro->getSheetByName($hoja);
        $pestana->setCellValue($this->columnasDe($pestana)['ocupacion_acud1'].$fila, 'ASTRONAUTA');

        $this->importar($this->guardar($libro))->assertStatus(200);

        $cambios = $this->conClavesDeTexto(json_decode((string) $this->ultimaImportacion()->cambios, true));
        $this->assertSame(
            [(string) $alumno => ['acudientes' => [(string) $acudiente => ['ocupacion' => [$antes, 'ASTRONAUTA']]]]],
            $cambios
        );
    }

    /**
     * Cortada en dos tandas, los cambios de la segunda se FUNDEN con los de la primera:
     * la fila se escribe con la marca de cada lote, así que un corte no pierde el rastro
     * de lo que ya entró.
     */
    public function test_reanudar_por_tandas_funde_los_cambios(): void
    {
        $archivo = $this->exportacion();
        $hoja = $this->hojaConAlMenos($archivo, 12);

        $libro = IOFactory::load($archivo);
        $pestana = $libro->getSheetByName($hoja);
        $c = $this->columnasDe($pestana);
        // Fila 0 (primera tanda) y fila 11 (segunda, con lotes de 10).
        foreach ([3, 14] as $n => $fila) {
            $pestana->setCellValue($c['barrio'].$fila, 'BARRIO TANDA '.($n + 1));
        }
        $libro->setActiveSheetIndex($libro->getIndex($pestana));
        $modificado = $this->guardar($libro);
        $primero = $this->idDeLaFila($modificado, $hoja, 3);
        $segundo = $this->idDeLaFila($modificado, $hoja, 14);

        // La hoja cambiada va primero en el libro, para que la primera tanda la toque.
        $this->assertSame($hoja, IOFactory::load($modificado)->getSheet(0)->getTitle(),
            'El test necesita que la hoja con 12 filas sea la primera del libro.');

        config(['importacion.segundos_por_peticion' => 0, 'importacion.filas_por_lote' => 10]);
        $this->importar($modificado)->assertStatus(200)->assertJsonPath('terminado', false);

        $tras1 = json_decode((string) $this->ultimaImportacion()->cambios, true);
        $this->assertSame(['BARRIO TANDA 1'], [$tras1[$primero]['barrio'][1] ?? null]);
        $this->assertArrayNotHasKey($segundo, $tras1);

        config(['importacion.segundos_por_peticion' => 120]);
        $this->importar($modificado)->assertStatus(200)
            ->assertJsonPath('terminado', true)->assertJsonPath('reanudada', true);

        $tras2 = json_decode((string) $this->ultimaImportacion()->cambios, true);
        $this->assertSame('BARRIO TANDA 1', $tras2[$primero]['barrio'][1] ?? null, 'La segunda tanda pisó la primera.');
        $this->assertSame('BARRIO TANDA 2', $tras2[$segundo]['barrio'][1] ?? null);
    }

    /** Fundir: el «antes» es el primero, el «después» el último, y lo que vuelve a su valor se va. */
    public function test_fundir_conserva_el_primer_antes_y_quita_lo_que_volvio(): void
    {
        $previos = [7 => ['celular' => ['1', '2'], 'barrio' => ['A', 'B']]];
        $nuevos = [
            7 => ['celular' => ['2', '3'], 'barrio' => ['B', 'A'], 'acudientes' => [9 => ['email' => [null, 'x@y.co']]]],
            8 => ['_creado' => true, 'nombres' => [null, 'ANA']],
        ];

        $this->assertSame([
            7 => ['celular' => ['1', '3'], 'acudientes' => [9 => ['email' => [null, 'x@y.co']]]],
            8 => ['_creado' => true, 'nombres' => [null, 'ANA']],
        ], PuntoDeControlDeImportacion::fundirCambios($previos, $nuevos));
    }

    public function test_la_importacion_sale_en_datos_una_linea_por_alumno_y_con_alumno_id(): void
    {
        $archivo = $this->exportacion();
        [$hoja, $fila] = $this->primeraFilaConId($archivo);
        $alumno = $this->idDeLaFila($archivo, $hoja, $fila);

        $libro = IOFactory::load($archivo);
        $pestana = $libro->getSheetByName($hoja);
        $c = $this->columnasDe($pestana);
        $pestana->setCellValue($c['barrio'].$fila, 'EL PORVENIR');
        $pestana->setCellValue($c['celular'].$fila, '3001234567');
        $this->importar($this->guardar($libro))->assertStatus(200);
        $importacion = $this->ultimaImportacion();

        $datos = $this->datos(['alumno_id' => $alumno]);
        $lineas = array_values(array_filter($datos['acciones'], fn ($a) => $a['tipo'] === 'importacion'));
        $this->assertCount(1, $lineas);
        $l = $lineas[0];
        $this->assertSame('importacion', $l['entidad']);
        $this->assertSame((int) $importacion->id, $l['importacion_id']);
        $this->assertSame($this->userId, $l['actor_user_id']);
        $this->assertSame($alumno, $l['alumno_id']);
        $this->assertSame('Importación de alumnos.xlsx', $l['resumen']);
        $this->assertSame((string) $importacion->fin, substr((string) $l['ocurrido_en'], 0, 19));
        $this->assertSame(['barrio' => 'EL PORVENIR', 'celular' => '3001234567'], json_decode($l['valor_nuevo'], true));
        $this->assertSame(['barrio', 'celular'], array_keys(json_decode($l['valor_anterior'], true)));
        $this->assertLessThan(0, $l['id']);
        $this->assertNull($l['entidad_id']);

        // Otro alumno no la ve; el chip de ficha, tampoco.
        $this->assertSame(0, $this->datos(['alumno_id' => $alumno + 1, 'tipos' => 'importacion'])['total']);
        $this->assertSame(0, $this->datos(['alumno_id' => $alumno, 'tipos' => 'ficha'])['total']);
        $this->assertSame(1, $this->datos(['alumno_id' => $alumno, 'tipos' => 'importacion'])['total']);

        // Y quien importó sale en «Cambiado por».
        $actores = $this->withToken($this->token)->getJson('/api/auditoria/alumnos/actores')->assertStatus(200)->json();
        $this->assertContains($this->userId, array_column($actores, 'user_id'));
    }

    /**
     * Las dos fuentes mezcladas: el total las suma, y las páginas salen en el orden de la
     * pantalla sin repetir ni saltarse ninguna.
     */
    public function test_la_paginacion_mezcla_auditoria_e_importaciones_en_orden(): void
    {
        $alumno = (int) DB::table('alumnos')->whereNull('deleted_at')->orderBy('id')->value('id');

        // 30 líneas de ficha, una por minuto desde las 10:00.
        $deAuditoria = [];
        for ($i = 0; $i < 30; $i++) {
            $deAuditoria[] = [
                'id' => DB::table('auditoria')->insertGetId([
                    'accion' => 'editar', 'entidad' => 'alumno', 'entidad_id' => $alumno,
                    'alumno_id' => $alumno, 'alumno_nombre' => 'X', 'actor_user_id' => 1,
                    'actor_nombre' => 'A', 'actor_tipo' => 'Usuario', 'atribucion' => 'sesion',
                    'ocurrido_en' => sprintf('2026-09-10 10:%02d:00.000', $i),
                ]),
                't' => sprintf('2026-09-10 10:%02d:00', $i),
                'imp' => 0,
            ];
        }
        $deImportacion = [];
        // 4 importaciones intercaladas; la de 10:05 empata con una línea y va delante.
        foreach (['09:00:00', '10:05:00', '10:17:30', '11:00:00'] as $hora) {
            $id = DB::table('importaciones')->insertGetId([
                'tipo' => 'alumnos', 'huella' => str_repeat('a', 63).substr(md5($hora), 0, 1), 'archivo' => "de-$hora.xlsx",
                'year' => $this->year, 'avance' => '{}', 'filas' => 1, 'estado' => 'completada',
                'created_by' => $this->userId, 'inicio' => "2026-09-10 $hora", 'fin' => "2026-09-10 $hora",
                'cambios' => json_encode([$alumno => ['barrio' => ['A', 'B']]]),
            ]);
            $deImportacion[] = ['id' => -($id * 10_000_000 + $alumno), 't' => "2026-09-10 $hora", 'imp' => 1];
        }

        $todas = array_merge($deAuditoria, $deImportacion);
        usort($todas, fn ($a, $b) => [$b['t'], $b['imp'], $b['id']] <=> [$a['t'], $a['imp'], $a['id']]);
        $esperado = array_column($todas, 'id');

        $vistas = [];
        foreach ([1, 2] as $pagina) {
            $r = $this->datos(['alumno_id' => $alumno, 'pagina' => $pagina, 'por_pagina' => 25]);
            $this->assertSame(34, $r['total']);
            $this->assertSame(1, $r['resumen']['alumnos']);
            array_push($vistas, ...array_column($r['acciones'], 'id'));
        }
        $this->assertSame($esperado, $vistas);

        $this->assertSame(34, $this->datos(['alumno_id' => $alumno, 'solo_total' => 1])['total']);
        $this->assertSame(4, $this->datos(['alumno_id' => $alumno, 'tipos' => 'importacion',
            'desde' => '2026-09-10', 'hasta' => '2026-09-10', 'actor_user_id' => $this->userId])['total']);
        $this->assertSame(0, $this->datos(['alumno_id' => $alumno, 'desde' => '2026-09-11'])['total']);
        $this->assertSame(0, $this->datos(['alumno_id' => $alumno, 'q' => 'zzzz-no-existe'])['total']);
    }

    // ── ayudantes ──────────────────────────────────────────────────────────────

    private function datos(array $query): array
    {
        return $this->withToken($this->token)
            ->getJson('/api/auditoria/alumnos/datos?'.http_build_query($query))
            ->assertStatus(200)->json();
    }

    private function exportacion(): string
    {
        $r = $this->get('/api/users/export', ['Authorization' => 'Bearer '.$this->token])->assertStatus(200);
        $copia = tempnam(sys_get_temp_dir(), 'cambios').'.xlsx';
        copy($this->archivoDescargado($r), $copia);

        return $copia;
    }

    private function importar(string $archivo)
    {
        return $this->post(
            "/api/importar/algo/{$this->year}",
            ['file' => new UploadedFile($archivo, 'alumnos.xlsx', null, null, true)],
            ['Authorization' => 'Bearer '.$this->token]
        );
    }

    private function ultimaImportacion(): object
    {
        $fila = DB::selectOne('SELECT * FROM importaciones ORDER BY id DESC LIMIT 1');
        $this->assertNotNull($fila, 'La importación no dejó fila en `importaciones`.');

        return $fila;
    }

    /** [hoja, fila del libro] de la primera fila con id. */
    private function primeraFilaConId(string $archivo): array
    {
        foreach (IOFactory::load($archivo)->getAllSheets() as $hoja) {
            $c = $this->columnasDe($hoja);
            for ($fila = 3; $fila <= $hoja->getHighestDataRow(); $fila++) {
                if (trim((string) $hoja->getCell($c['id'].$fila)->getValue()) !== ''
                    && trim((string) $hoja->getCell($c['nro_de_documento'].$fila)->getValue()) !== '') {
                    return [$hoja->getTitle(), $fila];
                }
            }
        }
        $this->fail('El export no trae ninguna fila con id y documento.');
    }

    /** [hoja, fila, id del acudiente 1] de la primera fila con acudiente 1 con nombre. */
    private function primeraFilaConAcudiente(string $archivo): array
    {
        foreach (IOFactory::load($archivo)->getAllSheets() as $hoja) {
            $c = $this->columnasDe($hoja);
            for ($fila = 3; $fila <= $hoja->getHighestDataRow(); $fila++) {
                $acudiente = (int) $hoja->getCell($c['id_acud1'].$fila)->getValue();
                if ($acudiente > 0 && trim((string) $hoja->getCell($c['nombres_acud1'].$fila)->getValue()) !== ''
                    && trim((string) $hoja->getCell($c['id'].$fila)->getValue()) !== '') {
                    return [$hoja->getTitle(), $fila, $acudiente];
                }
            }
        }
        $this->fail('El export no trae ninguna fila con acudiente.');
    }

    private function hojaConAlMenos(string $archivo, int $filas): string
    {
        $hoja = IOFactory::load($archivo)->getSheet(0);
        $this->assertGreaterThanOrEqual($filas, $hoja->getHighestDataRow() - 2,
            'La primera hoja del export no tiene filas para dos tandas.');

        return $hoja->getTitle();
    }

    private function idDeLaFila(string $archivo, string $hoja, int $fila): int
    {
        $pestana = IOFactory::load($archivo)->getSheetByName($hoja);

        return (int) $pestana->getCell($this->columnasDe($pestana)['id'].$fila)->getValue();
    }

    private function guardar($libro): string
    {
        $ruta = tempnam(sys_get_temp_dir(), 'cambios').'.xlsx';
        (new EscritorXlsx($libro))->save($ruta);

        return $ruta;
    }

    /** Qué letra es cada encabezado (fila 2), normalizado como `WithHeadingRow`. */
    private function columnasDe($hoja): array
    {
        $columnas = [];
        foreach ($hoja->getRowIterator(2, 2) as $fila) {
            foreach ($fila->getCellIterator() as $celda) {
                $titulo = trim((string) $celda->getValue());
                if ($titulo !== '') {
                    $sinTildes = strtr(mb_strtolower($titulo), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n']);
                    $columnas[trim(preg_replace('/_+/', '_', preg_replace('/[^a-z0-9]+/', '_', $sinTildes)), '_')] = $celda->getColumn();
                }
            }
        }

        return $columnas;
    }

    /** El JSON decodificado con las claves de id como texto, para compararlo entero. */
    private function conClavesDeTexto(mixed $valor): mixed
    {
        if (! is_array($valor) || array_is_list($valor)) {
            return $valor;
        }
        $fuera = [];
        foreach ($valor as $k => $v) {
            $fuera[(string) $k] = $this->conClavesDeTexto($v);
        }

        return $fuera;
    }
}
