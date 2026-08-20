<?php

namespace Tests\Contrato;

use App\Services\PuntoDeControlDeImportacion;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as EscritorXlsx;

/**
 * La importación de alumnos, cortada por la mitad y retomada.
 *
 * Es el §1 de docs/migracion/09-pendientes.md. `max_execution_time` está en
 * 300 s en la cuenta de cPanel **porque las importaciones tardaban mucho**, y
 * para poder bajarlo la importación tiene que dejar de ser una petición que o
 * entra entera o se pierde.
 *
 * Lo que se comprueba aquí no es que el importador funcione —de eso se encarga
 * el viaje de ida y vuelta de ExcelTest— sino las dos promesas nuevas:
 *
 * 1. **Volver a subir el mismo archivo continúa**, no repite lo hecho.
 * 2. **Y aunque repitiera, no duplicaría**, porque el documento del alumno es
 *    la clave natural y se mira antes de crear.
 *
 * Son dos porque cada una tapa un agujero distinto: la primera es rápida y la
 * segunda es la que salva el caso en el que la primera no aplica —la secretaría
 * exporta la hoja otra vez en vez de volver a subir la misma—.
 *
 * Los tests no matan el proceso a media importación, que no se puede hacer
 * desde PHPUnit: escriben a mano el punto de control que habría dejado un corte
 * y comprueban qué hace el importador con él. Es la misma fila, con los mismos
 * valores, que la que deja un `kill`.
 */
class ImportacionReanudableTest extends CasoDeContrato
{
    /**
     * Una importación entera deja escrito cuánto tardó y cuántas filas eran.
     *
     * Ese número es el que decide si `max_execution_time` puede bajar de 300 s,
     * y hasta hoy no existía: «tardaba mucho» era todo lo que se sabía. Por eso
     * el test mira `inicio`, `fin` y `filas` y no solo el estado.
     */
    public function test_una_importacion_deja_su_rastro_medible(): void
    {
        [$token, $year] = $this->credenciales();

        $r = $this->importar($this->exportacionDeAlumnos($token), $token, $year);

        $r->assertStatus(200);
        $this->assertSame('Importados.', $r->getContent(),
            'La respuesta es el contrato con los cuatro clientes y no puede cambiar.');

        $fila = $this->ultimaImportacion();

        $this->assertSame(PuntoDeControlDeImportacion::COMPLETADA, $fila->estado);
        $this->assertSame('alumnos', $fila->tipo);
        $this->assertSame((int) $year, (int) $fila->year);
        $this->assertNull($fila->error);

        // No basta con que `inicio` y `fin` no sean null: una fecha que se
        // guarda mal en MySQL se guarda como '0000-00-00 00:00:00', que tampoco
        // lo es, y la resta que justifica la tabla daría un número absurdo sin
        // que nada fallara.
        $inicio = strtotime((string) $fila->inicio);
        $fin = strtotime((string) $fila->fin);

        $this->assertGreaterThan(0, $inicio, 'La marca de `inicio` no es una fecha.');
        $this->assertGreaterThanOrEqual($inicio, $fin, 'La importación terminó antes de empezar.');
        $this->assertLessThan(3600, abs(time() - $fin),
            'La marca de `fin` no es de hace un momento: las dos zonas horarias del proyecto se mezclaron en esta tabla.');

        $avance = json_decode((string) $fila->avance, true);

        $this->assertNotEmpty($avance, 'El avance vacío significa que no procesó ninguna hoja.');

        // `filas` y `avance` cuentan lo mismo desde dos sitios: la suma de las
        // últimas filas de cada hoja (base 0, de ahí el +1) tiene que dar el
        // total. Si se separan, el punto de control está mintiendo sobre algo.
        $this->assertSame(
            array_sum(array_map(fn ($ultima) => $ultima + 1, $avance)),
            (int) $fila->filas,
            'El total de filas no cuadra con el avance por hoja.'
        );
    }

    /**
     * Reanudar salta lo que ya estaba aplicado.
     *
     * Se prepara la fila que habría dejado un corte —la primera fila de la hoja
     * dada por hecha, el resto no— y se les pone a dos alumnos un nombre
     * imposible: uno detrás del punto de control y otro delante. Si la
     * importación respeta al primero y pisa al segundo, el corte se retomó
     * justo donde decía.
     *
     * El corte va DENTRO de una pestaña y no entre dos porque es el caso real:
     * el proceso no muere en el hueco entre hojas, muere en el alumno 340.
     */
    public function test_reanudar_no_vuelve_a_pasar_por_las_filas_hechas(): void
    {
        [$token, $year] = $this->credenciales();

        $archivo = $this->exportacionDeAlumnos($token);
        $hojas = $this->hojasDelLibro($archivo);

        $hoja = array_key_first($hojas);
        $ultima = $hojas[$hoja] - 1;   // índice de la última fila de datos, base 0

        $this->assertGreaterThanOrEqual(2, $hojas[$hoja],
            'Hace falta una hoja con al menos dos alumnos para poder cortarla por la mitad.');

        $hecha = $this->alumnoDeLaFila($archivo, $hoja, 0);
        $pendiente = $this->alumnoDeLaFila($archivo, $hoja, $ultima);

        DB::update('UPDATE alumnos SET nombres = ? WHERE id IN (?, ?)',
            ['NO ME TOQUES', $hecha, $pendiente]);

        $this->puntoDeControlAMedias($archivo, (int) $year, [$hoja => 0]);

        $this->importar($archivo, $token, $year)->assertStatus(200);

        $this->assertSame('NO ME TOQUES', $this->nombreDe($hecha),
            'La fila que el punto de control daba por hecha se volvió a procesar.');

        $this->assertNotSame('NO ME TOQUES', $this->nombreDe($pendiente),
            'La fila que quedaba pendiente no se procesó: reanudar se comió el resto de la hoja.');

        $fila = $this->ultimaImportacion();

        $this->assertSame(PuntoDeControlDeImportacion::COMPLETADA, $fila->estado);
        $this->assertSame($ultima, json_decode((string) $fila->avance, true)[$hoja],
            'El avance no llegó hasta el final de la hoja.');
    }

    /**
     * Una importación completada no bloquea volver a subir el mismo archivo.
     *
     * Es el uso corriente: la secretaría corrige cuatro celdas y sube la hoja
     * otra vez. Si reanudar se aplicara también a las completadas, esa segunda
     * subida no haría nada y nadie entendería por qué.
     */
    public function test_subir_otra_vez_un_archivo_ya_importado_lo_vuelve_a_aplicar(): void
    {
        [$token, $year] = $this->credenciales();

        $archivo = $this->exportacionDeAlumnos($token);

        $this->importar($archivo, $token, $year)->assertStatus(200);

        $primera = $this->ultimaImportacion();

        $alumno = $this->primerAlumnoDelGrupo(array_key_first($this->hojasDelLibro($archivo)), (int) $year);
        DB::update('UPDATE alumnos SET nombres = ? WHERE id = ?', ['NO ME TOQUES', $alumno]);

        $this->importar($archivo, $token, $year)->assertStatus(200);

        $segunda = $this->ultimaImportacion();

        $this->assertNotSame((int) $primera->id, (int) $segunda->id,
            'La segunda subida reanudó la primera en vez de empezar una importación nueva.');

        $this->assertNotSame('NO ME TOQUES', $this->nombreDe($alumno),
            'La segunda subida no aplicó nada: se dio por hecha con el punto de control de la primera.');
    }

    /**
     * Un alumno cuya fila viene sin `id` pero cuyo documento ya está en la base
     * se actualiza, no se duplica.
     *
     * Es la mitad que no depende del punto de control, y la que salva el caso
     * real que este trabajo venía a resolver: la importación se cortó, la
     * secretaría exportó la hoja de nuevo —huella distinta, nada que reanudar—
     * y la subió. Antes, cada alumno creado por el intento anterior entraba
     * otra vez, con su usuario y su matrícula.
     */
    public function test_no_crea_un_alumno_repetido_si_el_documento_ya_esta(): void
    {
        [$token, $year] = $this->credenciales();

        $archivo = $this->exportacionDeAlumnos($token);

        [$hoja, $fila, $documento] = $this->primeraFilaConDocumento($archivo);

        $sinId = $this->libroSinIdEn($archivo, $hoja, $fila);

        $alumnosAntes = $this->cuantosAlumnos();
        $usuariosAntes = $this->cuantosUsuarios();
        $matriculasAntes = $this->cuantasMatriculas();

        $this->importar($sinId, $token, $year)->assertStatus(200);

        $this->assertSame($alumnosAntes, $this->cuantosAlumnos(),
            "La fila sin id del documento {$documento} creó un alumno repetido.");
        $this->assertSame($usuariosAntes, $this->cuantosUsuarios(),
            'Creó también el usuario del alumno repetido, que es lo que llena `users` de fantasmas.');
        $this->assertSame($matriculasAntes, $this->cuantasMatriculas(),
            'Creó una matrícula de más: el alumno quedaría en dos grupos.');
    }

    /**
     * Una pestaña cuyo nombre no es el de ningún grupo deja el error escrito y
     * la importación reanudable.
     *
     * Es el fallo corriente —subir la hoja del año pasado— y hasta hoy era
     * «Undefined array key 0» en el log del colegio, sin decir qué pestaña era.
     * El código de respuesta sigue siendo 500 a propósito: cambiarlo es tocar
     * el contrato de la pantalla, y eso es otro trabajo.
     */
    public function test_una_pestana_sin_grupo_queda_escrita_con_su_nombre(): void
    {
        [$token, $year] = $this->credenciales();

        $archivo = $this->libroConLaPrimeraHojaRenombrada(
            $this->exportacionDeAlumnos($token), 'NO-EXISTE'
        );

        $this->importar($archivo, $token, $year)->assertStatus(500);

        $fila = $this->ultimaImportacion();

        $this->assertSame(PuntoDeControlDeImportacion::FALLIDA, $fila->estado);
        $this->assertStringContainsString('NO-EXISTE', (string) $fila->error,
            'El error no dice qué pestaña fue, que es lo único que hace falta para arreglarlo.');
    }

    /**
     * El nombre del archivo se guarda saneado.
     *
     * `importaciones.archivo` existe para que un humano reconozca la fila, y el
     * nombre lo pone quien sube el archivo. Lo que se guarda en una columna
     * termina saliendo por una pantalla, así que pasa por el mismo saneado que
     * las subidas de imágenes y documentos —`SafeUpload`, que es donde
     * `GuardsDestructivosTest` exige que viva `getClientOriginalName()`—.
     */
    public function test_el_nombre_del_archivo_se_guarda_saneado(): void
    {
        [$token, $year] = $this->credenciales();

        $this->post(
            "/api/importar/algo/{$year}",
            ['file' => new UploadedFile($this->exportacionDeAlumnos($token),
                'alu<script>mnos.php.xlsx', null, null, true)],
            ['Authorization' => 'Bearer '.$token]
        )->assertStatus(200);

        $guardado = (string) $this->ultimaImportacion()->archivo;

        $this->assertSame('alu_script_mnos_php.xlsx', $guardado);
    }

    // ---------------------------------------------------------------- Apoyos

    /** Token de alguien del colegio y el año que le corresponde. */
    private function credenciales(): array
    {
        $usuario = $this->usuarioDeTipo('Usuario');

        $year = DB::table('periodos')
            ->join('years', 'years.id', '=', 'periodos.year_id')
            ->where('periodos.id', $usuario->periodo_id)
            ->value('years.year');

        return [$this->tokenDe($usuario->username), (int) $year];
    }

    /**
     * La hoja que produce el export, que es la plantilla que la gente rellena.
     *
     * Se copia fuera del directorio temporal de la respuesta porque varios
     * tests la modifican y la vuelven a subir.
     */
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

    /** La fila de `importaciones` que dejó la última subida. */
    private function ultimaImportacion(): object
    {
        $fila = DB::selectOne('SELECT * FROM importaciones ORDER BY id DESC LIMIT 1');

        $this->assertNotNull($fila, 'La importación no dejó ninguna fila en `importaciones`.');

        return $fila;
    }

    /**
     * Escribe el punto de control que habría dejado un corte.
     *
     * La huella tiene que ser la del contenido del archivo, igual que la que
     * calcula el controlador: si no coincidiera, esto no sería «la misma
     * importación» y no habría nada que reanudar — que es exactamente lo que se
     * quiere comprobar que no pasa.
     */
    private function puntoDeControlAMedias(string $archivo, int $year, array $avance): void
    {
        DB::insert(
            'INSERT INTO importaciones (tipo, huella, archivo, year, avance, filas, estado, inicio, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            ['alumnos', hash_file('sha256', $archivo), 'alumnos.xlsx', $year,
                json_encode($avance), array_sum($avance) + count($avance),
                PuntoDeControlDeImportacion::EN_PROCESO, now(), now(), now()]
        );
    }

    /** Cada pestaña del libro con cuántas filas de datos trae. */
    private function hojasDelLibro(string $archivo): array
    {
        $hojas = [];

        foreach (IOFactory::load($archivo)->getAllSheets() as $hoja) {
            // Los encabezados están en la fila 2 (`headingRow()` del
            // importador), así que las de datos empiezan en la 3.
            $hojas[$hoja->getTitle()] = max(0, $hoja->getHighestDataRow() - 2);
        }

        return $hojas;
    }

    private function primerAlumnoDelGrupo(string $abrev, int $year): int
    {
        $fila = DB::selectOne(
            'SELECT a.id FROM alumnos a
             INNER JOIN matriculas m ON m.alumno_id = a.id AND m.deleted_at IS NULL
             INNER JOIN grupos g ON g.id = m.grupo_id AND g.deleted_at IS NULL
             INNER JOIN years y ON y.id = g.year_id AND y.year = ?
             WHERE g.abrev = ? AND a.deleted_at IS NULL ORDER BY a.id LIMIT 1',
            [$year, $abrev]
        );

        $this->assertNotNull($fila, "El grupo '{$abrev}' del año {$year} no tiene alumnos en el seed.");

        return (int) $fila->id;
    }

    /**
     * El alumno de la base que corresponde a una fila de datos de la hoja.
     *
     * `$fila` es el índice que usa el importador —base 0, contando desde la
     * primera fila de datos—, no el número de fila del libro. Los encabezados
     * están en la 2, así que la fila 0 del importador es la 3 del libro.
     */
    private function alumnoDeLaFila(string $archivo, string $hoja, int $fila): int
    {
        $pestana = IOFactory::load($archivo)->getSheetByName($hoja);

        $id = trim((string) $pestana->getCell($this->columnasDe($pestana)['id'].($fila + 3))->getValue());

        $this->assertNotSame('', $id, "La fila {$fila} de la hoja '{$hoja}' no trae id.");

        return (int) $id;
    }

    private function nombreDe(int $alumno): string
    {
        return (string) DB::table('alumnos')->where('id', $alumno)->value('nombres');
    }

    private function cuantosAlumnos(): int
    {
        return DB::table('alumnos')->whereNull('deleted_at')->count();
    }

    private function cuantosUsuarios(): int
    {
        return DB::table('users')->whereNull('deleted_at')->count();
    }

    private function cuantasMatriculas(): int
    {
        return DB::table('matriculas')->whereNull('deleted_at')->count();
    }

    /**
     * La primera fila del libro que trae id y documento, que es la que se puede
     * dejar sin id para fingir un alumno nuevo.
     *
     * Devuelve [hoja, fila del libro (base 1), documento].
     */
    private function primeraFilaConDocumento(string $archivo): array
    {
        $libro = IOFactory::load($archivo);

        foreach ($libro->getAllSheets() as $hoja) {
            $columnas = $this->columnasDe($hoja);

            for ($fila = 3; $fila <= $hoja->getHighestDataRow(); $fila++) {
                $id = trim((string) $hoja->getCell($columnas['id'].$fila)->getValue());
                $documento = trim((string) $hoja->getCell($columnas['nro_de_documento'].$fila)->getValue());

                if ($id !== '' && $documento !== '') {
                    return [$hoja->getTitle(), $fila, $documento];
                }
            }
        }

        $this->fail('El export no trae ninguna fila con id y documento; sin eso este test no comprueba nada.');
    }

    /** Copia del libro con el `id` de una fila borrado: el importador la verá como alumno nuevo. */
    private function libroSinIdEn(string $archivo, string $hoja, int $fila): string
    {
        $libro = IOFactory::load($archivo);
        $pestana = $libro->getSheetByName($hoja);

        $pestana->setCellValue($this->columnasDe($pestana)['id'].$fila, null);

        return $this->guardar($libro);
    }

    private function libroConLaPrimeraHojaRenombrada(string $archivo, string $nombre): string
    {
        $libro = IOFactory::load($archivo);
        $libro->getSheet(0)->setTitle($nombre);

        return $this->guardar($libro);
    }

    private function guardar($libro): string
    {
        $ruta = tempnam(sys_get_temp_dir(), 'importar').'.xlsx';
        (new EscritorXlsx($libro))->save($ruta);

        return $ruta;
    }

    /**
     * Qué letra de columna es cada encabezado.
     *
     * Los encabezados están en la fila 2 y se normalizan igual que hace
     * `WithHeadingRow`: minúsculas y guiones bajos. Es lo que permite escribir
     * `$columnas['nro_de_documento']` en vez de una letra fija que se rompería
     * el día que el export añada una columna.
     */
    private function columnasDe($hoja): array
    {
        $columnas = [];

        foreach ($hoja->getRowIterator(2, 2) as $fila) {
            foreach ($fila->getCellIterator() as $celda) {
                $titulo = trim((string) $celda->getValue());

                if ($titulo !== '') {
                    $columnas[$this->normalizar($titulo)] = $celda->getColumn();
                }
            }
        }

        $this->assertArrayHasKey('id', $columnas, 'El export dejó de traer la columna `id`.');
        $this->assertArrayHasKey('nro_de_documento', $columnas, 'El export dejó de traer `nro_de_documento`.');

        return $columnas;
    }

    private function normalizar(string $titulo): string
    {
        $sinTildes = strtr(mb_strtolower($titulo), [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n',
        ]);

        return trim(preg_replace('/_+/', '_', preg_replace('/[^a-z0-9]+/', '_', $sinTildes)), '_');
    }
}
