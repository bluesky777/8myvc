<?php

namespace Tests\Contrato;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as EscritorXlsx;

/**
 * EL LIBRO AJENO Y LAS CELDAS VACÍAS — lo que entró el 29 sep 2026 con la lista de CAZ.
 *
 * 1. `importar/alumnos/formatear` convierte la lista de otro sistema («SEXTO - A», el nombre entero
 *    en una columna, «TI: 123») en la plantilla, sin escribir nada, y ese libro lo acepta el ensayo.
 * 2. Con «conservar», una celda vacía NO borra: ni el segundo nombre (que va unido al primero en
 *    `nombres`), ni el tipo de documento (que el traductor rellena con TI), ni el SISBEN, ni el
 *    estado de la matrícula. Las cuatro se perdían importando de verdad antes del arreglo.
 */
class LibroAjenoYVaciosTest extends CasoDeContrato
{
    public function test_la_plantilla_no_se_formatea(): void
    {
        [$token, $year] = $this->personalYSuYear();

        $this->formatear($this->exportacionDeAlumnos($token), $token, $year)
            ->assertStatus(200)
            ->assertJson(['ok' => true, 'ajeno' => false]);
    }

    public function test_un_libro_ajeno_vuelve_como_plantilla_sin_escribir_y_el_ensayo_la_acepta(): void
    {
        [$token, $year] = $this->personalYSuYear();
        [$grupo, $alumnos] = $this->grupoConAlumnosConDocumento($year);
        $antes = $this->censo();

        $r = $this->formatear($this->libroAjeno($grupo->nombre, $alumnos), $token, $year)->assertStatus(200);

        $this->assertSame($antes, $this->censo(), 'Formatear no puede escribir en la base.');
        $this->assertTrue($r->json('ajeno'));
        $this->assertSame(count($alumnos), $r->json('resumen.por_documento'), 'Con «TI: » delante, el documento tiene que encontrarlos.');
        $this->assertSame(1, $r->json('resumen.nuevos'));

        $preparado = tempnam(sys_get_temp_dir(), 'fmt').'.xlsx';
        file_put_contents($preparado, base64_decode($r->json('archivo')));

        $e = $this->ensayar($preparado, $token, $year)->assertStatus(200);
        $this->assertSame($grupo->abrev, $e->json('hojas.0.coincide_con'), 'La pestaña preparada tiene que ser la del grupo.');
        $this->assertSame([], $e->json('hojas.0.sobran'), '«Qué pasará» es del formateo, no una columna que sobre.');
        // Lo que el formateo dijo que crearía es lo que el ensayo cuenta: los alumnos del seed pueden
        // tener ya sus dos acudientes, así que el número no es fijo, pero las dos cuentas cuadran.
        $this->assertSame($r->json('resumen.acudientes_nuevos'), $e->json('efectos_colaterales.acudientes.crear'));
        $this->assertGreaterThanOrEqual(1, $e->json('efectos_colaterales.acudientes.crear'), 'El alumno nuevo trae su acudiente.');
    }

    public function test_sin_columna_de_documento_no_adivina(): void
    {
        [$token, $year] = $this->personalYSuYear();
        $libro = new Spreadsheet;
        $hoja = $libro->getActiveSheet();
        $hoja->fromArray([['Estudiante', 'Genero', 'Acudiente'], ['ANA PEREZ GOMEZ', 'Femenino', 'LUZ GOMEZ']]);

        $this->formatear($this->guardar($libro), $token, $year)
            ->assertStatus(422)
            ->assertJson(['ok' => false, 'ajeno' => true]);
    }

    public function test_con_conservar_una_celda_vacia_no_borra_nada(): void
    {
        [$token, $year] = $this->personalYSuYear();
        $archivo = $this->exportacionDeAlumnos($token);
        $libro = IOFactory::load($archivo);
        $hoja = $libro->getSheet(0);
        $col = $this->columnas($hoja);
        $id = (int) $hoja->getCell([$col['ID'], 3])->getValue();
        $this->assertGreaterThan(0, $id, 'La exportación tiene que traer alumnos.');

        // Una ficha con todo lo que se perdía, y la fila de la hoja con esas celdas vacías.
        DB::update("UPDATE alumnos SET nombres='NOMBRE UNO', tipo_doc=5, has_sisben=1, nro_sisben='A1' WHERE id=?", [$id]);
        $hoja->setCellValue([$col['Primer nombre'], 3], 'NOMBRE');
        foreach (['Segundo nombre', 'Tipo de Documento', 'SISBEN', 'Estado Matrícula', 'Nuevo'] as $c) {
            $hoja->setCellValue([$col[$c], 3], null);
        }
        $archivo = $this->guardar($libro);
        $estadoAntes = $this->estadoDeLaMatricula($id, $year);

        $vacios = $this->ensayar($archivo, $token, $year)->assertStatus(200)->json('vacios');
        $respuestas = [
            'version' => 1,
            'huella' => hash_file('sha256', $archivo),
            'vocabularios' => [],
            'vacios' => array_map(fn ($v) => ['columna' => $v['columna'], 'decision' => 'conservar'], $vacios),
        ];
        do {
            $r = $this->importar($archivo, $token, $year, $respuestas)->assertStatus(200);
        } while ($r->json('terminado') === false);

        $ficha = DB::selectOne('SELECT nombres, tipo_doc, has_sisben, nro_sisben FROM alumnos WHERE id=?', [$id]);
        $this->assertSame('NOMBRE UNO', $ficha->nombres, 'El segundo nombre se perdió con la celda vacía.');
        $this->assertSame(5, (int) $ficha->tipo_doc, 'El tipo de documento se volvió Tarjeta de identidad.');
        $this->assertSame(1, (int) $ficha->has_sisben, 'El SISBEN se borró.');
        $this->assertSame($estadoAntes, $this->estadoDeLaMatricula($id, $year), 'Un estado vacío dejó la matrícula fuera de las listas.');
    }

    // ───────────────────────────── ayudas ─────────────────────────────

    private function personalYSuYear(): array
    {
        $usuario = $this->usuarioDeTipo('Usuario');
        $year = DB::table('periodos')->join('years', 'years.id', '=', 'periodos.year_id')
            ->where('periodos.id', $usuario->periodo_id)->value('years.year');

        return [$this->tokenDe($usuario->username), (int) $year];
    }

    private function exportacionDeAlumnos(string $token): string
    {
        $r = $this->get('/api/users/export', ['Authorization' => 'Bearer '.$token])->assertStatus(200);
        $copia = tempnam(sys_get_temp_dir(), 'exp').'.xlsx';
        copy($this->archivoDescargado($r), $copia);

        return $copia;
    }

    private function formatear(string $archivo, string $token, int $year)
    {
        return $this->post("/api/importar/alumnos/formatear/{$year}",
            ['file' => new UploadedFile($archivo, 'lista.xlsx', null, null, true)], ['Authorization' => 'Bearer '.$token]);
    }

    private function ensayar(string $archivo, string $token, int $year)
    {
        return $this->post("/api/importar/alumnos/ensayo/{$year}",
            ['file' => new UploadedFile($archivo, 'alumnos.xlsx', null, null, true)], ['Authorization' => 'Bearer '.$token]);
    }

    private function importar(string $archivo, string $token, int $year, array $respuestas)
    {
        return $this->post("/api/importar/algo/{$year}",
            ['file' => new UploadedFile($archivo, 'alumnos.xlsx', null, null, true), 'respuestas' => json_encode($respuestas)],
            ['Authorization' => 'Bearer '.$token]);
    }

    /** Un grupo del año del que sólo hay UNO de su grado (así «NOMBRE - A» casa sin dudas) y 3 alumnos con documento único. */
    private function grupoConAlumnosConDocumento(int $year): array
    {
        $grupos = DB::select("SELECT g.id, g.nombre, g.abrev, g.grado_id FROM grupos g JOIN years y ON y.id=g.year_id
            WHERE y.year=? AND g.deleted_at IS NULL AND g.nombre REGEXP '^[A-Za-zÁÉÍÓÚáéíóú]+$'", [$year]);
        foreach ($grupos as $g) {
            $alumnos = DB::select("SELECT a.id, a.nombres, a.apellidos, a.documento FROM alumnos a
                JOIN matriculas m ON m.alumno_id=a.id AND m.grupo_id=? AND m.deleted_at IS NULL AND m.estado IN ('MATR','ASIS')
                WHERE a.deleted_at IS NULL AND a.documento REGEXP '^[0-9]{6,12}$'
                  AND (SELECT COUNT(*) FROM alumnos b WHERE b.documento=a.documento AND b.deleted_at IS NULL)=1
                LIMIT 3", [$g->id]);
            if (count($alumnos) === 3) {
                return [$g, $alumnos];
            }
        }
        $this->markTestSkipped('El seed no tiene un grupo con tres alumnos de documento único.');
    }

    /** La lista como la manda otro sistema: nombre entero, «TI: » delante y el acudiente en una columna. */
    private function libroAjeno(string $pestana, array $alumnos): string
    {
        $libro = new Spreadsheet;
        $hoja = $libro->getActiveSheet();
        $hoja->setTitle(mb_strtoupper($pestana).' - A');
        $filas = [['Grado', 'Estudiante', 'Genero', 'Numero documento identidad', 'Acudiente', 'Celular']];
        foreach ($alumnos as $a) {
            $filas[] = [mb_strtoupper($pestana), trim($a->nombres).' '.trim($a->apellidos), 'Femenino', 'TI: '.$a->documento, 'LUZ MARINA GOMEZ PEREZ', '3001234567'];
        }
        $filas[] = [mb_strtoupper($pestana), 'NUEVA PERSONA DE PRUEBA', 'Masculino', 'RC: 9.990.001.234', 'PEDRO PRUEBA RUIZ', '3007654321'];
        $hoja->fromArray($filas);

        return $this->guardar($libro);
    }

    private function guardar(Spreadsheet $libro): string
    {
        $ruta = tempnam(sys_get_temp_dir(), 'lib').'.xlsx';
        (new EscritorXlsx($libro))->save($ruta);

        return $ruta;
    }

    /** Encabezado de la fila 2 → número de columna. */
    private function columnas($hoja): array
    {
        $col = [];
        foreach ($hoja->getRowIterator(2, 2) as $fila) {
            foreach ($fila->getCellIterator() as $c) {
                $col[trim((string) $c->getValue())] = Coordinate::columnIndexFromString($c->getColumn());
            }
        }

        return $col;
    }

    private function estadoDeLaMatricula(int $id, int $year): ?string
    {
        return DB::selectOne('SELECT m.estado FROM matriculas m JOIN grupos g ON g.id=m.grupo_id JOIN years y ON y.id=g.year_id
            WHERE m.alumno_id=? AND y.year=? AND m.deleted_at IS NULL LIMIT 1', [$id, $year])?->estado;
    }

    private function censo(): array
    {
        $c = [];
        foreach (['alumnos', 'users', 'matriculas', 'acudientes', 'parentescos', 'importaciones'] as $t) {
            $c[$t] = (int) DB::selectOne("SELECT COUNT(*) AS n FROM {$t}")->n;
        }

        return $c;
    }
}
