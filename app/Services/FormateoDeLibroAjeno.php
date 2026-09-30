<?php

namespace App\Services;

use App\Http\Controllers\Alumnos\OperacionesAlumnos;
use App\Models\Matricula;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Shared\Date as FechaExcel;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * UN LIBRO QUE NO ES LA PLANTILLA SE TRADUCE A LA PLANTILLA, Y LA PERSONA LO REVISA ANTES DE SUBIRLO.
 *
 * Los colegios mandan listas sacadas de otros sistemas —la de Zaragoza del 29 sep 2026: una pestaña
 * «SEXTO - A» por grupo, el nombre entero en «Estudiante», el documento como «RC: 1042827586»—. El
 * importador no puede con eso, y no debe: busca el grupo por el nombre de la pestaña y al alumno por
 * el documento exacto, así que ese libro o se paraba o creaba un duplicado de cada alumno.
 *
 * Esto **no escribe nada en la base**. Lee el libro, reconoce sus columnas por el encabezado Y por lo
 * que traen, busca a cada alumno en el sistema y devuelve un libro nuevo con la forma exacta de la
 * plantilla (la de `simat.blade.php`), con comentarios donde se transformó algo y una columna
 * «Qué pasará» que dice si el alumno se crea o ya existía. Ese libro es el que se importa.
 *
 * Sin IA a propósito: todo son reglas que se pueden leer aquí y que fallan diciendo por qué.
 *
 * Qué dato manda cuando el alumno ya existe (decidido por Joseth el 29 sep 2026: el archivo se sube
 * para ACTUALIZAR, así que lo normal es que lo suyo sea lo bueno):
 *  - lo que trae el ARCHIVO, con lo que había en el sistema en el comentario de la celda. En amarillo
 *    sólo si cambia el documento o el nombre, que es cambiar quién es.
 *  - un nombre que sólo difiere en tildes o mayúsculas se queda como está en el sistema.
 *  - todo lo que el archivo no trae sale del sistema, porque el importador BORRA lo que llega vacío.
 */
class FormateoDeLibroAjeno
{
    public const COLUMNA_QUE_PASARA = 'Qué pasará';

    /** Los encabezados de la plantilla, en su orden (`resources/views/simat.blade.php`). */
    public const CABECERAS = [
        // «Qué pasará» va junto al documento y no al final: es lo primero que hay que leer de cada fila.
        'No', 'ID', 'Tipo de Documento', 'Nro de documento', self::COLUMNA_QUE_PASARA, 'Lugar de Expedición Departamento', 'Lugar de Expedición Ciudad',
        'Primer apellido', 'Segundo apellido', 'Primer nombre', 'Segundo nombre', 'Usuario', 'Estado Matrícula', 'Número Matrícula',
        'Dirección residencia', 'Barrio', 'Ciudad residencia', 'Urbana', 'Teléfono', 'Celular', 'Estrato', 'SISBEN', 'SISBEN 3',
        'Fecha de nacim', 'Departam nacimiento', 'Ciudad nacimiento', 'Sexo', 'Nuevo', 'RH', 'EPS', 'Religión',
        'ID Acud1', 'Nombres Acud1', 'Apellidos Acud1', 'Sexo Acud1', 'Tipo docu Acud1', '¿Es el acudiente? Acud1', 'Parentesco Acud1',
        'Documento Acud1', 'Departam Docu Acud1', 'Ciudad Docu Acud1', 'Teléfono Acud1', 'Celular Acud1', 'Ocupación Acud1',
        'Dirección Acud1', 'Username Acud1', 'Email Acud1', 'Observaciones Acud1',
        'ID Acud2', 'Nombres Acud2', 'Apellidos Acud2', 'Sexo Acud2', 'Tipo docu Acud2', '¿Es el acudiente? Acud2', 'Parentesco Acud2',
        'Documento Acud2', 'Departam Docu Acud2', 'Ciudad Docu Acud2', 'Teléfono Acud2', 'Celular Acud2', 'Ocupación Acud2',
        'Dirección Acud2', 'Username Acud2', 'Email Acud2', 'Observaciones Acud2',
    ];

    /** Prefijos que ensucian un documento, y la abreviatura de `tipos_documentos` que significan. */
    private const PREFIJOS = [
        'NUIP' => 'NUIP', 'NIP' => 'NIP', 'RC' => 'RC', 'TI' => 'TI', 'CC' => 'CC', 'CE' => 'CE',
        'PEP' => 'PEP', 'PPT' => 'PEP', 'PA' => 'PASP', 'PAS' => 'PASP', 'PASAPORTE' => 'PASP', 'NES' => 'NES',
    ];

    private const PARTICULAS = ['de', 'del', 'la', 'las', 'los', 'san', 'santa', 'y', 'da', 'van', 'von', 'mc'];

    private const GRADOS = [
        'prejardin' => 'prejardin', 'pre jardin' => 'prejardin', 'parvulos' => 'parvulos', 'jardin' => 'jardin',
        'kinder' => 'jardin', 'transicion' => 'transicion', 'primero' => '1', 'segundo' => '2', 'tercero' => '3',
        'cuarto' => '4', 'quinto' => '5', 'sexto' => '6', 'septimo' => '7', 'octavo' => '8', 'noveno' => '9',
        'decimo' => '10', 'once' => '11', 'undecimo' => '11', 'onceavo' => '11',
    ];

    private const AMARILLO = 'FFFFF2A8';

    private const VERDE = 'FFD9F2D0';

    /** @var array<string, object> tipos_documentos por abreviatura */
    private array $tipos = [];

    /** @var array<int, object> grupos del año */
    private array $grupos = [];

    /** @var array<string, int[]> documento limpio → ids de alumno */
    private array $porDocumento = [];

    /** @var array<string, int[]> nombre normalizado (palabras ordenadas) → ids */
    private array $porNombre = [];

    /** @var array<int, object> */
    private array $alumnos = [];

    /** @var array<string, int> fechas de nacimiento de relleno: las que comparten muchos alumnos */
    private array $fechasDeRelleno = [];

    public array $resumen = [];

    public function __construct(private int $year) {}

    public static function normalizar($texto): string
    {
        $t = Str::lower(Str::ascii((string) $texto));
        $t = preg_replace('/[^a-z0-9]+/', ' ', $t);

        return trim(preg_replace('/\s+/', ' ', $t));
    }

    /**
     * ¿Es ya la plantilla? La fila 2 trae «Nro de documento» y «Primer nombre», que es lo único que el
     * importador necesita para leerla. Si cualquier pestaña lo trae, el libro no se toca.
     */
    public static function esPlantilla(Spreadsheet $libro): bool
    {
        foreach ($libro->getWorksheetIterator() as $hoja) {
            $fila = [];
            foreach ($hoja->getRowIterator(2, 2) as $r) {
                foreach ($r->getCellIterator() as $c) {
                    $fila[] = self::normalizar(self::plano($c->getValue()));
                }
            }
            if (in_array('nro de documento', $fila, true) && in_array('primer nombre', $fila, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Devuelve el libro formateado o lanza `LibroNoReconocido` con lo que impidió estar seguro.
     */
    public function formatear(string $ruta): Spreadsheet
    {
        $origen = IOFactory::load($ruta);
        $this->cargarSistema();

        $filas = [];      // cada fila del origen ya leída y entendida
        $columnasVistas = [];
        $avisos = [];
        $hojasResumen = [];

        foreach ($origen->getWorksheetIterator() as $hoja) {
            $leida = $this->leerHoja($hoja);
            if ($leida === null) {
                continue;
            }
            [$mapa, $datos, $cabeceras] = $leida;
            foreach ($cabeceras as $i => $c) {
                $columnasVistas[$c] = $mapa[$i] ?? null;
            }
            $grupoHoja = $this->resolverGrupo($hoja->getTitle(), null);
            $n = 0;
            foreach ($datos as $d) {
                $d['hoja'] = $hoja->getTitle();
                $grado = trim(($d['grado'] ?? '').' '.($d['grupo'] ?? ''));
                $d['grupo_resuelto'] = $grado !== '' ? ($this->resolverGrupo($grado, $grupoHoja) ?? $grupoHoja) : $grupoHoja;
                $filas[] = $d;
                $n++;
            }
            $hojasResumen[] = [
                'origen' => $hoja->getTitle(),
                'filas' => $n,
                'grupo' => $grupoHoja?->abrev,
                'grupo_nombre' => $grupoHoja?->nombre,
            ];
        }

        if (count($filas) === 0) {
            throw new LibroNoReconocido('No encontré filas de alumnos en ninguna pestaña.');
        }

        // Quién es quién, antes de dividir nombres: los encontrados enseñan el orden del libro.
        foreach ($filas as &$f) {
            $this->buscarEnElSistema($f);
        }
        unset($f);
        $orden = $this->ordenDeLosNombres($filas);
        foreach ($filas as &$f) {
            if (! isset($f['alumno'])) {
                $this->buscarParecido($f, $orden);
            }
        }
        unset($f);

        $porGrupo = [];
        $sinGrupo = 0;
        foreach ($filas as $f) {
            $grupo = $f['grupo_resuelto'];
            // El que ya tiene matrícula este año se queda en SU grupo: el importador no cambia a nadie
            // de grupo, así que ponerlo en otra pestaña sólo confundiría.
            if (isset($f['alumno']) && $f['alumno']->grupo_id) {
                $actual = $this->grupos[$f['alumno']->grupo_id] ?? null;
                if ($actual && (! $grupo || $grupo->id !== $actual->id)) {
                    $f['aviso_grupo'] = 'Tu archivo lo pone en «'.$f['hoja'].'», pero en el sistema está matriculado en '.$actual->nombre.'. Se deja en su grupo.';
                    $grupo = $actual;
                }
            }
            if (! $grupo) {
                $sinGrupo++;
                $avisos[] = 'Fila '.$f['fila'].' de «'.$f['hoja'].'»: no supe a qué grupo pertenece; no va en el archivo preparado.';

                continue;
            }
            $porGrupo[$grupo->id][] = $f;
        }

        $destino = new Spreadsheet;
        $destino->removeSheetByIndex(0);
        $cuenta = ['nuevos' => 0, 'por_documento' => 0, 'por_nombre' => 0, 'parecidos' => 0, 'nuevos_dudosos' => 0, 'se_matriculan' => 0, 'acudientes_nuevos' => 0, 'acudientes_existentes' => 0];

        foreach ($this->grupos as $grupo) {
            if (empty($porGrupo[$grupo->id])) {
                continue;
            }
            $hoja = $destino->createSheet();
            $hoja->setTitle($grupo->abrev);
            $hoja->setCellValue('A1', $grupo->nombre.' — formateado por MyVc a partir de tu archivo. Revisa las celdas amarillas (tienen un comentario) y la columna «Qué pasará».');
            foreach (self::CABECERAS as $i => $cab) {
                $hoja->setCellValue([$i + 1, 2], $cab);
            }
            $hoja->getStyle('A2:'.Coordinate::stringFromColumnIndex(count(self::CABECERAS)).'2')->getFont()->setBold(true);

            $r = 3;
            foreach ($porGrupo[$grupo->id] as $k => $f) {
                [$valores, $notas, $que] = $this->armarFila($f, $orden, $cuenta);
                $valores['No'] = $k + 1;
                $valores[self::COLUMNA_QUE_PASARA] = $que;
                foreach (self::CABECERAS as $i => $cab) {
                    $v = $valores[$cab] ?? null;
                    if ($v === null || $v === '') {
                        continue;
                    }
                    // Todo como texto: un documento con ceros delante o una fecha no deben reinterpretarse.
                    $hoja->setCellValueExplicit([$i + 1, $r], (string) $v, DataType::TYPE_STRING);
                }
                foreach ($notas as $cab => $nota) {
                    $col = array_search($cab, self::CABECERAS, true);
                    $celda = Coordinate::stringFromColumnIndex($col + 1).$r;
                    $hoja->getComment($celda)->getText()->createTextRun($nota['texto']);
                    $hoja->getComment($celda)->setWidth('260pt')->setHeight('80pt');
                    if ($nota['revisar']) {
                        $hoja->getStyle($celda)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::AMARILLO);
                    }
                }
                if (! isset($f['alumno'])) {
                    $ultima = Coordinate::stringFromColumnIndex(array_search(self::COLUMNA_QUE_PASARA, self::CABECERAS, true) + 1).$r;
                    $hoja->getStyle($ultima)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::VERDE);
                }
                $r++;
            }
            foreach (['ID', 'Nro de documento', self::COLUMNA_QUE_PASARA, 'Primer apellido', 'Segundo apellido', 'Primer nombre',
                'Segundo nombre', 'Fecha de nacim', 'Nombres Acud1', 'Apellidos Acud1', 'Celular Acud1', 'Email Acud1'] as $cab) {
                $hoja->getColumnDimensionByColumn(array_search($cab, self::CABECERAS, true) + 1)->setAutoSize(true);
            }
            // Fijas hasta «Qué pasará»: al desplazarse a la derecha se sigue viendo de quién es la fila y qué le pasa.
            $hoja->freezePane(Coordinate::stringFromColumnIndex(array_search(self::COLUMNA_QUE_PASARA, self::CABECERAS, true) + 2).'3');
        }

        $this->resumen = [
            'filas' => count($filas),
            'sin_grupo' => $sinGrupo,
            'orden_nombres' => $orden,
            'hojas' => $hojasResumen,
            'columnas' => array_map(fn ($o, $d) => ['origen' => (string) $o, 'destino' => $d], array_keys($columnasVistas), $columnasVistas),
            'avisos' => $avisos,
        ] + $cuenta;

        return $destino;
    }

    public static function escribir(Spreadsheet $libro): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'fmt');
        (new Xlsx($libro))->save($tmp);
        $bytes = file_get_contents($tmp);
        @unlink($tmp);

        return $bytes === false ? '' : $bytes;
    }

    // ───────────────────────────── lectura del libro ajeno ─────────────────────────────

    /**
     * La fila de encabezados es la primera de las diez primeras con tres o más celdas de texto.
     * Devuelve [mapa índice→campo, filas de datos, encabezados originales] o null si la hoja está vacía.
     */
    private function leerHoja(Worksheet $hoja): ?array
    {
        $ultimaFila = $hoja->getHighestDataRow();
        $ultimaCol = Coordinate::columnIndexFromString($hoja->getHighestDataColumn());
        if ($ultimaFila < 2) {
            return null;
        }

        $filaCab = null;
        for ($r = 1; $r <= min(10, $ultimaFila); $r++) {
            $textos = 0;
            for ($c = 1; $c <= $ultimaCol; $c++) {
                $v = self::plano($hoja->getCell([$c, $r])->getValue());
                if (is_string($v) && preg_match('/[a-zA-Z]{3}/', $v)) {
                    $textos++;
                }
            }
            if ($textos >= 3) {
                $filaCab = $r;
                break;
            }
        }
        if ($filaCab === null) {
            return null;
        }

        $cabeceras = [];
        $columnas = [];
        for ($c = 1; $c <= $ultimaCol; $c++) {
            $cab = trim((string) self::plano($hoja->getCell([$c, $filaCab])->getValue()));
            if ($cab === '') {
                continue;
            }
            $cabeceras[$c] = $cab;
            $valores = [];
            for ($r = $filaCab + 1; $r <= $ultimaFila; $r++) {
                $valores[$r] = $this->valorDeCelda($hoja, $c, $r);
            }
            $columnas[$c] = $valores;
        }

        $mapa = $this->clasificarColumnas($cabeceras, $columnas, $hoja->getTitle());

        $datos = [];
        for ($r = $filaCab + 1; $r <= $ultimaFila; $r++) {
            $d = ['fila' => $r];
            $algo = false;
            foreach ($mapa as $c => $campo) {
                $v = $columnas[$c][$r] ?? null;
                if ($v !== null && $v !== '') {
                    $d[$campo] = $v;
                    $algo = true;
                }
            }
            if ($algo && (isset($d['nombre_completo']) || isset($d['nombres']) || isset($d['apellidos']) || isset($d['documento']))) {
                $datos[] = $d;
            }
        }

        return [$mapa, $datos, $cabeceras];
    }

    /** Las celdas con formato llegan como `RichText`, no como cadena. */
    private static function plano($v)
    {
        return $v instanceof RichText ? $v->getPlainText() : $v;
    }

    private function valorDeCelda(Worksheet $hoja, int $c, int $r)
    {
        $celda = $hoja->getCell([$c, $r]);
        $v = self::plano($celda->getCalculatedValue());
        if (is_numeric($v) && FechaExcel::isDateTime($celda)) {
            return FechaExcel::excelToDateTimeObject($v)->format('Y-m-d');
        }
        if (is_float($v) && floor($v) == $v) {
            return (string) (int) $v;
        }

        return is_string($v) ? trim(preg_replace('/\s+/u', ' ', $v)) : $v;
    }

    /**
     * Qué es cada columna, por su encabezado. El documento además se comprueba por el contenido: si
     * menos del 80 % de sus celdas dan un número de documento después de limpiarlas, no es la columna,
     * y sin documento seguro no se formatea nada — con él se decide si un alumno se crea o no.
     */
    private function clasificarColumnas(array $cabeceras, array $columnas, string $hoja): array
    {
        $mapa = [];
        $hayAcudiente = false;
        foreach ($cabeceras as $c => $cab) {
            $n = self::normalizar($cab);
            if (preg_match('/\b(acudiente|padre|madre|responsable|tutor|representante)\b/', $n)
                && ! preg_match('/\b(documento|cedula|celular|telefono|tel|email|correo|parentesco|direccion)\b/', $n)) {
                $hayAcudiente = true;
            }
        }

        $candidatosDoc = [];
        foreach ($cabeceras as $c => $cab) {
            $n = self::normalizar($cab);
            $deAcudiente = (bool) preg_match('/\b(acudiente|acud|padre|madre|responsable|tutor|representante)\b/', $n);
            $deAlumno = (bool) preg_match('/\b(estudiante|alumno)\b/', $n);
            $campo = null;

            if (preg_match('/\btipo\b/', $n) && preg_match('/\b(doc|documento|identificacion)\b/', $n)) {
                $campo = $deAcudiente ? null : 'tipo_documento';
            } elseif (preg_match('/\b(documento|identificacion|identidad|nuip|cedula|doc|no doc|nro doc)\b/', $n)) {
                if ($deAcudiente) {
                    $campo = 'acud_documento';
                } else {
                    $candidatosDoc[] = $c;
                }
            } elseif (preg_match('/^(estudiante|alumno|nombre|nombres y apellidos|nombre completo|nombre del estudiante|nombre estudiante|nombre del alumno|nombre alumno|apellidos y nombres|nombres completos|estudiantes)$/', $n)) {
                $campo = 'nombre_completo';
                if (str_starts_with($n, 'apellidos')) {
                    $this->resumen['apellidos_primero_por_cabecera'] = true;
                }
            } elseif (! $deAcudiente && preg_match('/^(primer nombre|nombre 1|1 nombre)$/', $n)) {
                $campo = 'primer_nombre';
            } elseif (! $deAcudiente && preg_match('/^(segundo nombre|nombre 2|2 nombre)$/', $n)) {
                $campo = 'segundo_nombre';
            } elseif (! $deAcudiente && preg_match('/^(primer apellido|apellido 1|1 apellido)$/', $n)) {
                $campo = 'primer_apellido';
            } elseif (! $deAcudiente && preg_match('/^(segundo apellido|apellido 2|2 apellido)$/', $n)) {
                $campo = 'segundo_apellido';
            } elseif (! $deAcudiente && preg_match('/^nombres?( del (estudiante|alumno))?$/', $n)) {
                $campo = 'nombres';
            } elseif (! $deAcudiente && preg_match('/^apellidos?( del (estudiante|alumno))?$/', $n)) {
                $campo = 'apellidos';
            } elseif (preg_match('/\b(genero|sexo)\b/', $n)) {
                $campo = $deAcudiente ? 'acud_sexo' : 'sexo';
            } elseif (preg_match('/\bnacimiento\b|\bfecha nac\b|\bf nac\b/', $n)) {
                $campo = 'fecha_nac';
            } elseif (preg_match('/^(grado|curso|grado escolar)$/', $n)) {
                $campo = 'grado';
            } elseif (preg_match('/^(grupo|seccion|paralelo)$/', $n)) {
                $campo = 'grupo';
            } elseif (preg_match('/\bparentesco\b/', $n)) {
                $campo = 'acud_parentesco';
            } elseif (preg_match('/\b(celular|movil|whatsapp)\b/', $n)) {
                $campo = ($deAcudiente || ($hayAcudiente && ! $deAlumno)) ? 'acud_celular' : 'celular';
            } elseif (preg_match('/\b(telefono|tel|fijo)\b/', $n)) {
                $campo = ($deAcudiente || ($hayAcudiente && ! $deAlumno)) ? 'acud_telefono' : 'telefono';
            } elseif (preg_match('/\b(email|correo|e mail|mail)\b/', $n)) {
                $campo = ($deAcudiente || ($hayAcudiente && ! $deAlumno)) ? 'acud_email' : 'email';
            } elseif (preg_match('/\bocupacion\b/', $n)) {
                $campo = $deAcudiente ? 'acud_ocupacion' : null;
            } elseif (preg_match('/\bdireccion\b/', $n)) {
                $campo = $deAcudiente ? 'acud_direccion' : 'direccion';
            } elseif (preg_match('/\bbarrio\b/', $n)) {
                $campo = $deAcudiente ? null : 'barrio';
            } elseif (preg_match('/\b(eps|salud)\b/', $n)) {
                $campo = 'eps';
            } elseif (preg_match('/\b(rh|sangre)\b/', $n)) {
                $campo = 'rh';
            } elseif (preg_match('/\b(acudiente|padre|madre|responsable|tutor|representante)\b/', $n)) {
                $campo = 'acud_nombre';
            }
            if ($campo !== null && ! in_array($campo, $mapa, true)) {
                $mapa[$c] = $campo;
            }
        }

        // El documento: de las columnas que se llaman así, la que de verdad trae documentos.
        $mejor = null;
        $mejorTasa = 0;
        foreach ($candidatosDoc as $c) {
            $llenas = array_filter($columnas[$c], fn ($v) => $v !== null && $v !== '');
            if (count($llenas) === 0) {
                continue;
            }
            $buenas = count(array_filter($llenas, fn ($v) => self::limpiarDocumento($v)['numero'] !== null));
            $tasa = $buenas / count($llenas);
            if ($tasa > $mejorTasa) {
                $mejor = $c;
                $mejorTasa = $tasa;
            }
        }
        if ($mejor === null || $mejorTasa < 0.8) {
            throw new LibroNoReconocido(
                count($candidatosDoc) === 0
                    ? 'En la pestaña «'.$hoja.'» no encontré una columna que se llame documento, identificación o similar. Sin el documento no puedo saber qué alumnos ya están en el sistema.'
                    : 'En la pestaña «'.$hoja.'» la columna «'.$cabeceras[$candidatosDoc[0]].'» no trae documentos en la mayoría de sus filas, así que no puedo estar seguro de que lo sea.'
            );
        }
        $mapa[$mejor] = 'documento';

        if (! in_array('nombre_completo', $mapa, true) && ! in_array('primer_nombre', $mapa, true) && ! in_array('nombres', $mapa, true)) {
            throw new LibroNoReconocido('En la pestaña «'.$hoja.'» no encontré la columna con el nombre del alumno.');
        }

        ksort($mapa);

        return $mapa;
    }

    /**
     * «RC: 1.042.827.586» → número 1042827586 y tipo RC. Devuelve numero=null si no parece documento.
     */
    public static function limpiarDocumento($valor): array
    {
        $original = trim((string) $valor);
        $t = Str::upper(Str::ascii($original));
        $tipo = null;
        if (preg_match('/^\s*(PASAPORTE|NUIP|NIP|PEP|PPT|PAS|NES|R\.?\s?C|T\.?\s?I|C\.?\s?C|C\.?\s?E|PA)\b[\s.:#\-]*/', $t, $m)) {
            $clave = preg_replace('/[^A-Z]/', '', $m[1]);
            $tipo = self::PREFIJOS[$clave] ?? null;
            $t = substr($t, strlen($m[0]));
        } elseif (preg_match('/^\s*[A-Z][A-Z.\s]{0,11}[:#\-]\s*(?=\d)/', $t, $m)) {
            // Un prefijo que no conozco («TE:», «T.I:», «DOC:»…) también ensucia: se quita, pero el tipo
            // no se adivina. Sólo si lo cierran dos puntos, almohadilla o guion y detrás van cifras.
            $t = substr($t, strlen($m[0]));
        }
        $esPasaporte = $tipo === 'PASP';
        $numero = $esPasaporte ? preg_replace('/[^A-Z0-9]/', '', $t) : preg_replace('/[^0-9]/', '', $t);
        // Letras sueltas que quedan dentro (fuera de un pasaporte) son otra cosa, no un documento.
        if (! $esPasaporte && preg_match('/[A-Z]{2,}/', $t)) {
            return ['numero' => null, 'tipo' => $tipo, 'original' => $original];
        }
        $largo = strlen((string) $numero);

        return [
            'numero' => ($largo >= 5 && $largo <= 15) ? $numero : null,
            'tipo' => $tipo,
            'original' => $original,
        ];
    }

    // ───────────────────────────── el sistema ─────────────────────────────

    private function cargarSistema(): void
    {
        foreach (DB::select('SELECT id, tipo, abrev FROM tipos_documentos WHERE deleted_at IS NULL') as $t) {
            $this->tipos[strtoupper($t->abrev)] = $t;
        }

        $grupos = DB::select('SELECT g.id, g.nombre, g.abrev, g.orden, g.year_id FROM grupos g
            INNER JOIN years y ON y.id=g.year_id AND y.deleted_at IS NULL
            WHERE y.year=? AND g.deleted_at IS NULL ORDER BY g.orden, g.id', [$this->year]);
        foreach ($grupos as $g) {
            [$g->grado, $g->letra] = $this->gradoYLetra($g->nombre);
            $this->grupos[$g->id] = $g;
        }

        // Todos los alumnos vivos, con su grupo de este año si lo tienen.
        $filas = DB::select('SELECT a.id, a.nombres, a.apellidos, a.documento, m.grupo_id, m.estado
            FROM alumnos a
            LEFT JOIN (SELECT m.alumno_id, m.grupo_id, m.estado FROM matriculas m
                INNER JOIN grupos g ON g.id=m.grupo_id AND g.deleted_at IS NULL
                INNER JOIN years y ON y.id=g.year_id AND y.year=?
                WHERE m.deleted_at IS NULL) m ON m.alumno_id=a.id
            WHERE a.deleted_at IS NULL ORDER BY a.id', [$this->year]);
        // CAZ tenía 119 alumnos nacidos el 2000-06-25: eso no es una fecha, es un relleno, y no debe
        // ganarle a la del archivo.
        foreach (DB::select('SELECT fecha_nac, COUNT(*) c FROM alumnos WHERE deleted_at IS NULL AND fecha_nac IS NOT NULL
            GROUP BY fecha_nac HAVING COUNT(*) >= 5') as $x) {
            $this->fechasDeRelleno[(string) $x->fecha_nac] = (int) $x->c;
        }
        foreach ($filas as $a) {
            $this->alumnos[$a->id] = $a;
            $doc = self::limpiarDocumento($a->documento)['numero'];
            if ($doc !== null) {
                $this->porDocumento[ltrim($doc, '0')][] = $a->id;
            }
            $this->porNombre[$this->claveDeNombre($a->nombres.' '.$a->apellidos)][] = $a->id;
        }
    }

    private function claveDeNombre(string $nombre): string
    {
        $p = explode(' ', self::normalizar($nombre));
        sort($p);

        return implode(' ', $p);
    }

    /** «Transición B» → [transicion, B]; «6ª» → [6, null]; «Once» → [11, null]. */
    private function gradoYLetra(string $texto): array
    {
        $n = ' '.self::normalizar($texto).' ';
        $n = str_replace(' pre jardin ', ' prejardin ', $n);
        $grado = null;
        foreach (self::GRADOS as $palabra => $g) {
            if (str_contains($n, ' '.$palabra.' ')) {
                $grado = $g;
                $n = str_replace(' '.$palabra.' ', ' ', $n);
                break;
            }
        }
        if ($grado === null && preg_match('/\b(1[01]|[1-9])\s?[a-z]?\b/', $n, $m)) {
            $grado = $m[1];
            $n = preg_replace('/\b'.$m[1].'/', ' ', $n, 1);
        }
        $letra = preg_match('/(?:^|\s)([a-z])(?:\s|$)/', trim($n), $m) ? strtoupper($m[1]) : null;

        return [$grado, $letra];
    }

    /**
     * El grupo del año que corresponde a un texto («SEXTO - A», «Grado 6 A», «TRANSICIÓN - A»). Si del
     * grado hay un solo grupo, es ése aunque la letra no esté; si hay varios, la letra decide.
     */
    private function resolverGrupo(string $texto, ?object $porDefecto): ?object
    {
        foreach ($this->grupos as $g) {
            if (self::normalizar($g->abrev) === self::normalizar($texto) || self::normalizar($g->nombre) === self::normalizar($texto)) {
                return $g;
            }
        }
        [$grado, $letra] = $this->gradoYLetra($texto);
        if ($grado === null) {
            return $porDefecto;
        }
        $delGrado = array_values(array_filter($this->grupos, fn ($g) => $g->grado === $grado));
        if (count($delGrado) === 1) {
            return $delGrado[0];
        }
        foreach ($delGrado as $g) {
            if ($letra !== null && $g->letra === $letra) {
                return $g;
            }
        }

        return null;
    }

    private function buscarEnElSistema(array &$f): void
    {
        $doc = self::limpiarDocumento($f['documento'] ?? '');
        $f['doc'] = $doc;
        $completo = $f['nombre_completo'] ?? trim(implode(' ', array_filter([
            $f['primer_nombre'] ?? $f['nombres'] ?? null, $f['segundo_nombre'] ?? null,
            $f['primer_apellido'] ?? $f['apellidos'] ?? null, $f['segundo_apellido'] ?? null,
        ])));
        $f['completo'] = $completo;

        if ($doc['numero'] !== null) {
            $ids = $this->porDocumento[ltrim($doc['numero'], '0')] ?? [];
            // EL DOCUMENTO SOLO NO BASTA: en CAZ hay documentos que tienen dos alumnos a la vez, y
            // «CESAR CAMILO LEÓN» con el documento que también tiene «ANTHONY GÓMEZ» acababa pisando a
            // Anthony. Entre los que lo tienen, el que se llame parecido; si no se parece ninguno, el
            // documento es de otro y no sirve para encontrarlo.
            $mejor = null;
            $mejorParecido = 0;
            // Entre los que se llaman parecido, manda el que tiene matrícula este año: CAZ tenía a
            // «SAHID» (sin matrícula, de 2023) y «SAID» (en Tercero) con el mismo documento, y elegir al
            // de mejor nombre matriculó al viejo y dejó a los dos en Tercero (importación del 29 sep 2026).
            foreach ($ids as $id) {
                $a = $this->alumnos[$id];
                $p = self::palabrasParecidas($completo, $a->nombres.' '.$a->apellidos);
                $gana = $mejor === null
                    || ($p >= 2 && $a->grupo_id && ! $mejor->grupo_id)
                    || ($p > $mejorParecido && ! ($mejorParecido >= 2 && $mejor->grupo_id && ! $a->grupo_id));
                if ($gana) {
                    $mejor = $a;
                    $mejorParecido = $p;
                }
            }
            if ($mejor && $mejorParecido >= 2) {
                $f['alumno'] = $mejor;
                $f['encontrado'] = 'documento';
                if (count($ids) > 1) {
                    $f['aviso_repetido'] = 'Ese documento lo tienen '.count($ids).' alumnos en el sistema (ids '.implode(', ', $ids).'). Se usa el '.$mejor->id.', que es el que se llama así.';
                }

                return;
            }
            if (count($ids) > 0) {
                $f['doc_ajeno'] = implode('; ', array_map(fn ($id) => $id.' '.trim($this->alumnos[$id]->nombres).' '.trim($this->alumnos[$id]->apellidos), $ids));
            }
        }
        $ids = $this->porNombre[$this->claveDeNombre($completo)] ?? [];
        if (count($ids) === 1) {
            $f['alumno'] = $this->alumnos[$ids[0]];
            $f['encontrado'] = 'nombre';
        }
    }

    /**
     * 2 si son la misma palabra (sin tildes), 1 si difieren en una letra (seis letras o más) o dos
     * (ocho o más), 0 si no. Las cortas tienen que ser iguales: «MARIA» y «MARIN» no son erratas.
     */
    private static function palabraParecida(string $x, string $y): int
    {
        if ($x === $y) {
            return 2;
        }
        $largo = min(strlen($x), strlen($y));
        $tope = $largo >= 8 ? 2 : ($largo >= 6 ? 1 : 0);

        return $tope > 0 && levenshtein($x, $y) <= $tope ? 1 : 0;
    }

    /** Cuántas palabras de `$a` tienen una casi igual en `$b` (una o dos letras de diferencia). */
    private static function palabrasParecidas(string $a, string $b): int
    {
        $pb = array_filter(explode(' ', self::normalizar($b)));
        $n = 0;
        foreach (array_filter(explode(' ', self::normalizar($a))) as $x) {
            foreach ($pb as $y) {
                if (self::palabraParecida($x, $y) > 0) {
                    $n++;
                    break;
                }
            }
        }

        return $n;
    }

    /**
     * EL QUE NO APARECIÓ EXACTO PUEDE ESTAR CON UNA ERRATA. En la lista de CAZ del 29 sep 2026, ocho de
     * los diez «nuevos» ya estaban: «145139661» por «1045139661», «JOSEPH» por «JOSETH», «MARIAJOSE»
     * por «MARIA JOSÉ». Crearlos era duplicarlos.
     *
     * Se da por el mismo alumno cuando los apellidos son los mismos y además el primer nombre o el
     * documento se parecen (una o dos letras o cifras de diferencia) y sólo hay UNO así. Si sólo se
     * parece el documento, o hay varios candidatos, no se decide: la fila va como nueva y en amarillo
     * con los candidatos. Dos hermanos comparten apellidos, así que los apellidos solos no bastan.
     */
    private function buscarParecido(array &$f, string $orden): void
    {
        if (isset($f['nombre_completo'])) {
            $d = self::dividirNombre($f['nombre_completo'], $orden);
            $nombres = implode(' ', $d['nombres']);
            $apellidos = implode(' ', $d['apellidos']);
        } else {
            $nombres = trim(($f['primer_nombre'] ?? $f['nombres'] ?? '').' '.($f['segundo_nombre'] ?? ''));
            $apellidos = trim(($f['primer_apellido'] ?? $f['apellidos'] ?? '').' '.($f['segundo_apellido'] ?? ''));
        }
        $fn = self::normalizar($nombres);
        $fa = self::normalizar($apellidos);
        $doc = $f['doc']['numero'] ?? null;
        if ($fn === '' || $fa === '') {
            return;
        }
        $seguros = [];
        $posibles = [];
        foreach ($this->alumnos as $a) {
            $an = self::normalizar($a->nombres);
            $aa = self::normalizar($a->apellidos);
            if ($an === '' || $aa === '') {
                continue;
            }
            $mismosApellidos = $this->claveDeNombre($fa) === $this->claveDeNombre($aa) || str_replace(' ', '', $fa) === str_replace(' ', '', $aa);
            $p1 = explode(' ', $fn)[0];
            $p2 = explode(' ', $an)[0];
            $juntoF = str_replace(' ', '', $fn);
            $juntoA = str_replace(' ', '', $an);
            $nombreParecido = $juntoF === $juntoA || str_starts_with($juntoF, $juntoA) || str_starts_with($juntoA, $juntoF)
                || (strlen($p1) >= 4 && levenshtein($p1, $p2) <= 1);
            $docA = self::limpiarDocumento($a->documento)['numero'];
            $docParecido = $doc !== null && $docA !== null && levenshtein($doc, $docA) <= 2;
            // Mismos nombres y un apellido que es el comienzo del otro: «JUAN SEBASTIAN QUIÑONEZ» en
            // MyVc y «JUAN SEBASTIAN QUIÑONEZ MONROY» en el archivo (CAZ, 29 sep 2026).
            $apellidoRecortado = $juntoF === $juntoA
                && (str_starts_with($fa.' ', $aa.' ') || str_starts_with($aa.' ', $fa.' '));
            if ($apellidoRecortado || ($mismosApellidos && ($nombreParecido || $docParecido))) {
                $seguros[] = $a;
            } elseif ($docParecido && ($nombreParecido || $mismosApellidos)) {
                $posibles[] = $a;
            }
        }
        $describe = fn ($a) => $a->id.' '.trim($a->nombres).' '.trim($a->apellidos).' (doc '.($a->documento ?: 'vacío').')';
        if (count($seguros) === 1) {
            $f['alumno'] = $seguros[0];
            $f['encontrado'] = 'parecido';

            return;
        }
        $candidatos = array_merge($seguros, $posibles);
        if (count($candidatos) > 0) {
            $f['posibles_nombres'] = implode(' o ', array_map(
                fn ($a) => implode(' ', preg_split('/[\s\x{00A0}]+/u', $a->nombres.' '.$a->apellidos, -1, PREG_SPLIT_NO_EMPTY)),
                $candidatos
            ));
            $f['posibles'] = 'Se parece a: '.implode('; ', array_map($describe, $candidatos))
                .'. Si es la misma persona, borra esta fila para no duplicarlo y corrige los datos en su ficha.';
        }
    }

    /**
     * ¿El libro escribe «NOMBRES APELLIDOS» o «APELLIDOS NOMBRES»? Lo dicen los alumnos que ya están en
     * el sistema: se mira con qué empieza su nombre en el libro. Sin votos, lo que diga la cabecera, y
     * si no, nombres primero, que es lo corriente.
     */
    private function ordenDeLosNombres(array $filas): string
    {
        $votos = ['nombres_primero' => 0, 'apellidos_primero' => 0];
        foreach ($filas as $f) {
            if (! isset($f['alumno'], $f['nombre_completo'])) {
                continue;
            }
            $libro = self::normalizar($f['nombre_completo']);
            $nombres = self::normalizar($f['alumno']->nombres);
            $apellidos = self::normalizar($f['alumno']->apellidos);
            if ($nombres !== '' && str_starts_with($libro, $nombres)) {
                $votos['nombres_primero']++;
            } elseif ($apellidos !== '' && str_starts_with($libro, $apellidos)) {
                $votos['apellidos_primero']++;
            }
        }
        if ($votos['nombres_primero'] + $votos['apellidos_primero'] > 0) {
            return $votos['apellidos_primero'] > $votos['nombres_primero'] ? 'apellidos_primero' : 'nombres_primero';
        }

        return ! empty($this->resumen['apellidos_primero_por_cabecera']) ? 'apellidos_primero' : 'nombres_primero';
    }

    /**
     * Divide un nombre completo. Las partículas van pegadas a lo que sigue («DE LA HOZ» es un
     * apellido). Con tres partes o más, dos son apellidos. Con cinco o más, o con una sola, la fila se
     * marca para revisar.
     *
     * @return array{nombres: string[], apellidos: string[], dudoso: bool}
     */
    public static function dividirNombre(string $completo, string $orden): array
    {
        $palabras = preg_split('/\s+/u', trim($completo)) ?: [];
        $unidades = [];
        $pendiente = '';
        foreach ($palabras as $p) {
            if (in_array(self::normalizar($p), self::PARTICULAS, true)) {
                $pendiente .= $p.' ';

                continue;
            }
            $unidades[] = $pendiente.$p;
            $pendiente = '';
        }
        if ($pendiente !== '') {
            $unidades[] = trim($pendiente);
        }
        $n = count($unidades);
        if ($n <= 1) {
            return ['nombres' => $unidades, 'apellidos' => [], 'dudoso' => true];
        }
        $cuantosApellidos = $n >= 3 ? 2 : 1;
        if ($orden === 'apellidos_primero') {
            $apellidos = array_slice($unidades, 0, $cuantosApellidos);
            $nombres = array_slice($unidades, $cuantosApellidos);
        } else {
            $nombres = array_slice($unidades, 0, $n - $cuantosApellidos);
            $apellidos = array_slice($unidades, $n - $cuantosApellidos);
        }

        // Tres partes son casi siempre un nombre y dos apellidos; cinco o más ya no se sabe.
        return ['nombres' => $nombres, 'apellidos' => $apellidos, 'dudoso' => $n > 4];
    }

    /**
     * Divide el nombre del archivo con tantos nombres de pila como tiene la ficha: si en el sistema es
     * «DENILMARK» y el archivo trae «DENILMARK MOSQUERA ECHEVERRI», uno de nombre y el resto apellidos.
     */
    private static function dividirComoLaFicha(string $completo, string $orden, string $nombresFicha, string $apellidosFicha): array
    {
        // Se busca el corte que mejor casa cada palabra con la ficha: las parecidas a sus nombres a un
        // lado y las parecidas a sus apellidos al otro. Así un segundo nombre que la ficha no tenía
        // («VALENTINA ANDREA MARTÍNEZ GARCÉS») o uno que falta («MELANIE MARTINEZ MARTINEZ») no
        // desplaza a los apellidos. Antes se contaban los nombres de la ficha y «AVRIL SOFIA» salía
        // como uno solo, con «SOFIA» de primer apellido.
        $unidades = self::unidades($completo);
        $n = count($unidades);
        if ($n < 2) {
            return self::dividirNombre($completo, $orden);
        }
        // Cuánto se parece una palabra a la mejor de una lista: 2 igual, 1 errata, 0 nada.
        $esDe = function (string $u, string $lista): int {
            $mejor = 0;
            foreach (array_filter(explode(' ', self::normalizar($lista))) as $y) {
                foreach (array_filter(explode(' ', self::normalizar($u))) as $x) {
                    $mejor = max($mejor, self::palabraParecida($x, $y));
                }
            }

            return $mejor;
        };
        $kApellidos = count(self::unidades($apellidosFicha));
        $mejor = null;
        $mejorPuntos = -1;
        for ($i = 1; $i < $n; $i++) {
            [$primero, $segundo] = [array_slice($unidades, 0, $i), array_slice($unidades, $i)];
            [$nom, $ape] = $orden === 'apellidos_primero' ? [$segundo, $primero] : [$primero, $segundo];
            $puntos = 0;
            // Una palabra en el lado que no le toca resta sólo si es IGUAL a una del otro lado.
            foreach ($nom as $u) {
                $puntos += $esDe($u, $nombresFicha) ?: ($esDe($u, $apellidosFicha) === 2 ? -2 : 0);
            }
            foreach ($ape as $u) {
                $puntos += $esDe($u, $apellidosFicha) ?: ($esDe($u, $nombresFicha) === 2 ? -2 : 0);
            }
            // Empate: el que deja tantos apellidos como tiene la ficha.
            $puntos = $puntos * 10 + (count($ape) === $kApellidos ? 1 : 0);
            if ($puntos > $mejorPuntos) {
                $mejorPuntos = $puntos;
                $mejor = ['nombres' => $nom, 'apellidos' => $ape, 'dudoso' => false];
            }
        }

        return $mejor;
    }

    /** Las palabras de un nombre, con las partículas pegadas a la siguiente («DE LA HOZ» es una). */
    private static function unidades(string $texto): array
    {
        $unidades = [];
        $pendiente = '';
        foreach (preg_split('/\s+/u', trim($texto), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $p) {
            if (in_array(self::normalizar($p), self::PARTICULAS, true)) {
                $pendiente .= $p.' ';

                continue;
            }
            $unidades[] = $pendiente.$p;
            $pendiente = '';
        }
        if ($pendiente !== '') {
            $unidades[] = trim($pendiente);
        }

        return $unidades;
    }

    /** «2011-09-06» y «2011-06-09»: el mismo año, con el día y el mes intercambiados. */
    private static function diaYMesCruzados(string $a, string $b): bool
    {
        if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $a, $x) || ! preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $b, $y)) {
            return false;
        }

        return $x[1] === $y[1] && $x[2] === $y[3] && $x[3] === $y[2] && $x[2] !== $x[3];
    }

    private static function capitalizar(string $s): string
    {
        return mb_convert_case(mb_strtolower($s), MB_CASE_TITLE, 'UTF-8');
    }

    private static function sexo($v): ?string
    {
        $n = self::normalizar($v);

        return match (true) {
            in_array($n, ['f', 'femenino', 'mujer', 'nina', 'fem'], true) => 'F',
            in_array($n, ['m', 'masculino', 'hombre', 'nino', 'masc'], true) => 'M',
            default => null,
        };
    }

    // ───────────────────────────── la fila de la plantilla ─────────────────────────────

    /** @return array{0: array<string, mixed>, 1: array<string, array{texto: string, revisar: bool}>, 2: string} */
    private function armarFila(array $f, string $orden, array &$cuenta): array
    {
        $v = [];
        $notas = [];
        $nota = function (string $col, string $texto, bool $revisar = false) use (&$notas) {
            $notas[$col] = isset($notas[$col])
                ? ['texto' => $notas[$col]['texto']."\n".$texto, 'revisar' => $notas[$col]['revisar'] || $revisar]
                : ['texto' => $texto, 'revisar' => $revisar];
        };

        $doc = $f['doc'];
        $existente = null;
        if (isset($f['alumno'])) {
            $existente = $this->filaDelSistema($f['alumno']->id);
        }

        if ($existente) {
            $v = $existente['valores'];
            $sinMatricula = ! $f['alumno']->grupo_id;
            if ($f['encontrado'] === 'documento') {
                $cuenta['por_documento']++;
            } elseif ($f['encontrado'] === 'parecido') {
                $cuenta['parecidos']++;
                $nota('Primer nombre', 'No coincidió exacto, pero es casi seguro el mismo alumno: en el sistema «'
                    .trim($f['alumno']->nombres).' '.trim($f['alumno']->apellidos).'», documento '.($f['alumno']->documento ?: 'vacío')
                    .'; en tu archivo «'.$f['completo'].'», «'.($f['doc']['original'] ?: 'sin documento').'». Si NO es la misma persona, borra el ID y corrige nombres y documento.', true);
            } else {
                $cuenta['por_nombre']++;
                if (trim((string) $f['alumno']->documento) === '') {
                    $nota('Primer nombre', 'Encontrado por el nombre exacto; el sistema no tenía su documento.');
                } else {
                    $nota('Primer nombre', 'Encontrado por el NOMBRE: el documento de tu archivo («'.($f['doc']['original'] ?: 'vacío')
                        .'») no coincide con el del sistema ('.$f['alumno']->documento.'). Comprueba que es la misma persona.', true);
                }
            }
            if ($sinMatricula) {
                $cuenta['se_matriculan']++;
            }
            $que = ['documento' => 'Ya existe (por documento)', 'nombre' => 'Ya existe (por nombre)', 'parecido' => 'Ya existe (parecido, revisa)'][$f['encontrado']]
                .($sinMatricula ? ' — se matricula en este grupo' : ' — se actualiza');

            // MANDA EL ARCHIVO: se sube para poner la ficha al día. Lo que había en el sistema queda en
            // el comentario de la celda. En amarillo sólo lo que cambia quién es: documento y nombre.
            $antesEn = fn ($x) => 'Se quita lo que tenía el sistema: «'.$x.'».';
            if ($doc['numero'] !== null) {
                $crudo = (string) ($v['Nro de documento'] ?? '');
                $delSistema = self::limpiarDocumento($crudo)['numero'];
                if ($delSistema === null) {
                    $v['Nro de documento'] = $doc['numero'];
                    $nota('Nro de documento', 'El sistema no tenía documento'.($crudo !== '' ? ' válido («'.$crudo.'»)' : '').'; se toma el de tu archivo.'
                        .(isset($f['doc_ajeno']) ? ' Ojo: ese documento ya lo tiene en el sistema '.$f['doc_ajeno'].'.' : ''), isset($f['doc_ajeno']));
                } elseif (ltrim($delSistema, '0') !== ltrim($doc['numero'], '0')) {
                    $v['Nro de documento'] = $doc['numero'];
                    $nota('Nro de documento', 'CAMBIA el documento. '.$antesEn($crudo).' Si el bueno era ése, vuelve a ponerlo aquí.'
                        .(isset($f['doc_ajeno']) ? ' Ojo: el nuevo ya lo tiene en el sistema '.$f['doc_ajeno'].'.' : ''), true);
                } elseif ($delSistema !== $crudo) {
                    $v['Nro de documento'] = $delSistema;
                    $nota('Nro de documento', 'Mismo número, sin puntos ni letras. '.$antesEn($crudo));
                }
                if ($doc['tipo'] && isset($this->tipos[$doc['tipo']])) {
                    $tipo = $this->tipos[$doc['tipo']]->tipo;
                    if (($v['Tipo de Documento'] ?? '') !== '' && self::normalizar($v['Tipo de Documento']) !== self::normalizar($tipo)) {
                        $nota('Tipo de Documento', $antesEn($v['Tipo de Documento']));
                    }
                    $v['Tipo de Documento'] = $tipo;
                }
            }
            if (isset($f['fecha_nac']) && $f['fecha_nac'] !== ($v['Fecha de nacim'] ?? '')) {
                $antes = $v['Fecha de nacim'] ?? '';
                $v['Fecha de nacim'] = $f['fecha_nac'];
                if ($antes === '') {
                    $nota('Fecha de nacim', 'El sistema no la tenía.');
                } elseif (self::diaYMesCruzados($antes, $f['fecha_nac'])) {
                    $nota('Fecha de nacim', 'Se quita lo que tenía el sistema: «'.$antes.'», que es la misma fecha con el día y el mes al revés.');
                } elseif (isset($this->fechasDeRelleno[$antes])) {
                    $nota('Fecha de nacim', 'Se quita lo que tenía el sistema: «'.$antes.'», una fecha de relleno que comparten '.$this->fechasDeRelleno[$antes].' alumnos.');
                } else {
                    $nota('Fecha de nacim', $antesEn($antes));
                }
            }
            if (isset($f['sexo']) && ($s = self::sexo($f['sexo'])) && $s !== ($v['Sexo'] ?? '')) {
                if (($v['Sexo'] ?? '') !== '') {
                    $nota('Sexo', $antesEn($v['Sexo']));
                }
                $v['Sexo'] = $s;
            }
            // El nombre: si sólo cambian tildes o mayúsculas, se queda el del sistema (el archivo
            // también tiene erratas de tilde, como «JULIÁNA»). Si cambia de verdad, el del archivo,
            // dividido con cuántos nombres tiene la ficha para no partirlo distinto.
            $delSistemaCompleto = trim($f['alumno']->nombres).' '.trim($f['alumno']->apellidos);
            if (isset($f['nombre_completo']) && self::normalizar($f['nombre_completo']) !== self::normalizar($delSistemaCompleto)
                && $this->claveDeNombre($f['nombre_completo']) !== $this->claveDeNombre($delSistemaCompleto)
                && str_replace(' ', '', self::normalizar($f['nombre_completo'])) !== str_replace(' ', '', self::normalizar($delSistemaCompleto))) {
                // Con las mayúsculas del archivo, como vienen: la ficha las guarda así.
                $d = self::dividirComoLaFicha($f['nombre_completo'], $orden, (string) $f['alumno']->nombres, (string) $f['alumno']->apellidos);
                $partes = [
                    'Primer nombre' => $d['nombres'][0] ?? '', 'Segundo nombre' => implode(' ', array_slice($d['nombres'], 1)),
                    'Primer apellido' => $d['apellidos'][0] ?? '', 'Segundo apellido' => implode(' ', array_slice($d['apellidos'], 1)),
                ];
                $nota('Primer nombre', 'CAMBIA el nombre: en el sistema era «'.$delSistemaCompleto.'». Si estaba bien, vuelve a ponerlo.', true);
                // Y cada parte que cambia dice qué se quita de ELLA, no sólo del nombre entero.
                foreach ($partes as $col => $nuevo) {
                    $viejo = (string) ($v[$col] ?? '');
                    if ($viejo !== $nuevo) {
                        $nota($col, $viejo === '' ? 'El sistema no tenía nada aquí.' : $antesEn($viejo), true);
                    }
                    $v[$col] = $nuevo;
                }
            }
            if (! empty($f['aviso_grupo'])) {
                $nota(self::COLUMNA_QUE_PASARA, $f['aviso_grupo'], true);
            }
            if (! empty($f['aviso_repetido'])) {
                $nota('ID', $f['aviso_repetido'], true);
            }
            if ($sinMatricula) {
                // Con el ID vacío el importador lo reencuentra por el documento y lo matricula; con el ID
                // puesto sólo lo actualizaría. Así que el ID se quita si el documento lo va a encontrar.
                $docSistema = (string) ($f['alumno']->documento ?? '');
                // Sólo si el documento es de ÉL solo: con dos alumnos que lo comparten, el importador
                // reencuentra al de id más bajo, que puede no ser éste.
                $unico = count($this->porDocumento[ltrim((string) self::limpiarDocumento($docSistema)['numero'], '0')] ?? []) === 1;
                if ($docSistema !== '' && $unico && $docSistema === ($v['Nro de documento'] ?? null)) {
                    $v['ID'] = '';
                    $v['Estado Matrícula'] = 'MATR';
                    $nota(self::COLUMNA_QUE_PASARA, 'No tiene matrícula este año. El ID va vacío a propósito: así el importador lo encuentra por el documento y lo matricula en este grupo.');
                } else {
                    $nota(self::COLUMNA_QUE_PASARA, 'No tiene matrícula este año y su documento en el sistema no coincide con el de la fila, así que el importador sólo lo actualizará: matricúlalo a mano.', true);
                }
            } elseif (in_array($f['alumno']->estado, ['RETI', 'DESE', 'TRAS'], true)) {
                $nota('Estado Matrícula', 'En el sistema está '.$f['alumno']->estado.'. Si ya volvió, cámbialo a MATR.', true);
            }
        } else {
            $cuenta['nuevos']++;
            $que = 'NUEVO — se creará (no se encontró su documento ni su nombre)';
            if (isset($f['posibles'])) {
                $cuenta['nuevos_dudosos']++;
                $que = 'NUEVO, pero se parece a otro: revisa a '.$f['posibles_nombres'];
                $nota(self::COLUMNA_QUE_PASARA, $f['posibles'], true);
            }
            if (isset($f['nombre_completo'])) {
                $d = self::dividirNombre($f['nombre_completo'], $orden);
                $nombres = array_map([self::class, 'capitalizar'], $d['nombres']);
                $apellidos = array_map([self::class, 'capitalizar'], $d['apellidos']);
                $v['Primer nombre'] = $nombres[0] ?? '';
                $v['Segundo nombre'] = implode(' ', array_slice($nombres, 1));
                $v['Primer apellido'] = $apellidos[0] ?? '';
                $v['Segundo apellido'] = implode(' ', array_slice($apellidos, 1));
                $nota('Primer nombre', 'Dividido de «'.$f['nombre_completo'].'».'.($d['dudoso'] ? ' No es seguro: revisa qué es nombre y qué apellido.' : ''), $d['dudoso']);
            } else {
                $v['Primer nombre'] = self::capitalizar($f['primer_nombre'] ?? $f['nombres'] ?? '');
                $v['Segundo nombre'] = self::capitalizar($f['segundo_nombre'] ?? '');
                $v['Primer apellido'] = self::capitalizar($f['primer_apellido'] ?? $f['apellidos'] ?? '');
                $v['Segundo apellido'] = self::capitalizar($f['segundo_apellido'] ?? '');
            }
            if ($doc['numero'] !== null && isset($f['doc_ajeno'])) {
                // Con ese documento el importador encontraría al otro alumno y lo sobrescribiría.
                $nota('Nro de documento', 'Tu archivo trae «'.$doc['original'].'», pero en el sistema ese documento es de: '.$f['doc_ajeno']
                    .'. Va vacío para no sobrescribir a ese alumno. Corrige el documento donde esté mal.', true);
            } elseif ($doc['numero'] !== null) {
                $v['Nro de documento'] = $doc['numero'];
                if ($doc['numero'] !== $doc['original']) {
                    $nota('Nro de documento', 'Limpiado de «'.$doc['original'].'».');
                }
            } elseif (isset($f['documento'])) {
                $nota('Nro de documento', '«'.$f['documento'].'» no parece un documento; va vacío.', true);
            }
            $tipo = $doc['tipo'] ?? null;
            if (! $tipo && isset($f['tipo_documento'])) {
                $tipo = self::limpiarDocumento($f['tipo_documento'].' 00000')['tipo'];
            }
            if ($tipo && isset($this->tipos[$tipo])) {
                $v['Tipo de Documento'] = $this->tipos[$tipo]->tipo;
            } elseif (isset($f['tipo_documento'])) {
                $v['Tipo de Documento'] = $f['tipo_documento'];
            } else {
                $nota('Tipo de Documento', 'Tu archivo no dice el tipo; el importador pondrá Tarjeta de identidad.', true);
            }
            $v['Fecha de nacim'] = $f['fecha_nac'] ?? '';
            $v['Sexo'] = isset($f['sexo']) ? (self::sexo($f['sexo']) ?? '') : '';
            if (isset($f['sexo']) && $v['Sexo'] === '') {
                $nota('Sexo', 'No entendí «'.$f['sexo'].'»: pon M o F.', true);
            }
            $v['Estado Matrícula'] = 'MATR';
            $v['Nuevo'] = 'SI';
            $nota('Nuevo', '«Nuevo» es nuevo en el colegio. Si ya estudiaba aquí el año pasado, pon NO.');
            $v['SISBEN'] = 'No aplica';
            $v['SISBEN 3'] = 'No aplica';
            foreach (['eps' => 'EPS', 'rh' => 'RH'] as $k => $col) {
                if (isset($f[$k])) {
                    $v[$col] = $f[$k];
                }
            }
        }

        // Contacto del alumno: manda el archivo.
        foreach (['celular' => 'Celular', 'telefono' => 'Teléfono', 'direccion' => 'Dirección residencia', 'barrio' => 'Barrio'] as $k => $col) {
            if (isset($f[$k]) && (string) $f[$k] !== (string) ($v[$col] ?? '')) {
                if (($v[$col] ?? '') !== '') {
                    $nota($col, 'Se quita lo que tenía el sistema: «'.$v[$col].'».');
                }
                $v[$col] = $f[$k];
            }
        }

        $this->ponerAcudiente($f, $v, $nota, $orden, $existente, $cuenta);

        return [$v, $notas, $que];
    }

    /**
     * El acudiente del archivo va al hueco que le toca: si el alumno ya tiene uno con ese nombre, a
     * ése (se actualiza el contacto); si no, al primer hueco libre como nuevo. Los dos ocupados por
     * otras personas: no se toca ninguno, y se dice.
     */
    private function ponerAcudiente(array $f, array &$v, callable $nota, string $orden, ?array $existente, array &$cuenta): void
    {
        if (empty($f['acud_nombre'])) {
            return;
        }
        $slot = null;
        foreach ([1, 2] as $i) {
            $id = $v['ID Acud'.$i] ?? '';
            if ($id === '') {
                continue;
            }
            $a = $this->claveDeNombre(($v['Nombres Acud'.$i] ?? '').' '.($v['Apellidos Acud'.$i] ?? ''));
            $b = $this->claveDeNombre($f['acud_nombre']);
            $comunes = array_intersect(explode(' ', $a), explode(' ', $b));
            if ($a === $b || count($comunes) >= 2) {
                $slot = $i;
                $cuenta['acudientes_existentes']++;
                break;
            }
        }
        $nuevo = false;
        if ($slot === null) {
            foreach ([1, 2] as $i) {
                if (($v['ID Acud'.$i] ?? '') === '' && ($v['Nombres Acud'.$i] ?? '') === '') {
                    $slot = $i;
                    $nuevo = true;
                    break;
                }
            }
        }
        if ($slot === null) {
            $nota('Nombres Acud1', 'Tu archivo trae como acudiente a «'.$f['acud_nombre'].'», pero el alumno ya tiene dos acudientes que no son esa persona. No se tocó ninguno.', true);

            return;
        }
        $s = 'Acud'.$slot;
        if ($nuevo) {
            $cuenta['acudientes_nuevos']++;
            $d = self::dividirNombre($f['acud_nombre'], 'nombres_primero');
            $v['Nombres '.$s] = implode(' ', array_map([self::class, 'capitalizar'], $d['nombres']));
            $v['Apellidos '.$s] = implode(' ', array_map([self::class, 'capitalizar'], $d['apellidos']));
            $v['¿Es el acudiente? '.$s] = 'SI';
            $v['Parentesco '.$s] = $f['acud_parentesco'] ?? 'Acudiente';
            $nota('Nombres '.$s, 'Acudiente NUEVO, dividido de «'.$f['acud_nombre'].'».'.($d['dudoso'] ? ' Revisa qué es nombre y qué apellido.' : ''), $d['dudoso']);
            if (! isset($f['acud_parentesco'])) {
                $nota('Parentesco '.$s, 'Tu archivo no dice el parentesco. Si sabes cuál es (Madre, Padre, Abuela…), cámbialo.');
            }
            if (isset($f['acud_sexo']) && ($sx = self::sexo($f['acud_sexo']))) {
                $v['Sexo '.$s] = $sx;
            }
            if (isset($f['acud_documento'])) {
                $dd = self::limpiarDocumento($f['acud_documento']);
                $v['Documento '.$s] = $dd['numero'] ?? $f['acud_documento'];
                if ($dd['tipo'] && isset($this->tipos[$dd['tipo']])) {
                    $v['Tipo docu '.$s] = $this->tipos[$dd['tipo']]->tipo;
                }
            }
        }
        foreach (['acud_celular' => 'Celular ', 'acud_telefono' => 'Teléfono ', 'acud_email' => 'Email ', 'acud_ocupacion' => 'Ocupación ', 'acud_direccion' => 'Dirección '] as $k => $col) {
            if (! isset($f[$k])) {
                continue;
            }
            $nuevoValor = $k === 'acud_email' ? Str::lower((string) $f[$k]) : (string) $f[$k];
            $anterior = (string) ($v[$col.$s] ?? '');
            if ($anterior !== '' && $anterior !== $nuevoValor) {
                $nota($col.$s, 'Se quita lo que tenía el sistema: «'.$anterior.'».');
            }
            $v[$col.$s] = $nuevoValor;
        }
    }

    /**
     * La fila del alumno tal como la exporta la plantilla, con la misma consulta que usa
     * `AlumnosExport`: así un alumno que ya existe no pierde nada al importar, porque el importador
     * borra lo que llega vacío.
     */
    private function filaDelSistema(int $alumnoId): ?array
    {
        $consulta = Matricula::$consulta_asistentes_o_matriculados_simat;
        $desde = strpos($consulta, 'FROM alumnos a');
        $select = substr($consulta, 0, $desde);
        $sql = $select.' FROM alumnos a
            LEFT JOIN matriculas m ON a.id=m.alumno_id AND m.deleted_at IS NULL
                AND m.grupo_id IN (SELECT g.id FROM grupos g INNER JOIN years y ON y.id=g.year_id WHERE y.year=:year AND g.deleted_at IS NULL)
            left join users u on a.user_id=u.id and u.deleted_at is null
            left join images i on i.id=u.imagen_id and i.deleted_at is null
            left join tipos_documentos t1 on t1.id=a.tipo_doc and t1.deleted_at is null
            left join images i2 on i2.id=a.foto_id and i2.deleted_at is null
            left join ciudades c1 on c1.id=a.ciudad_nac and c1.deleted_at is null
            left join ciudades c2 on c2.id=a.ciudad_doc and c2.deleted_at is null
            left join ciudades c3 on c3.id=a.ciudad_resid and c3.deleted_at is null
            WHERE a.id=:alumno LIMIT 1';
        $filas = DB::select($sql, [':year' => $this->year, ':alumno' => $alumnoId]);
        if (count($filas) === 0) {
            return null;
        }
        (new OperacionesAlumnos)->recorrer_y_dividir_nombres($filas);
        $a = $filas[0];
        $acudientes = DB::select(Matricula::$consulta_parientes, [$alumnoId]);

        $v = [
            'ID' => $a->alumno_id, 'Tipo de Documento' => $a->tipo_doc_name ?? $a->tipo_doc, 'Nro de documento' => $a->documento,
            'Lugar de Expedición Departamento' => $a->departamento_doc_nombre, 'Lugar de Expedición Ciudad' => $a->ciudad_doc_nombre,
            'Primer apellido' => $a->apellidos_divididos['first'], 'Segundo apellido' => $a->apellidos_divididos['last'],
            'Primer nombre' => $a->nombres_divididos['first'], 'Segundo nombre' => $a->nombres_divididos['last'],
            'Usuario' => $a->username, 'Estado Matrícula' => $a->estado, 'Número Matrícula' => $a->no_matricula,
            'Dirección residencia' => $a->direccion, 'Barrio' => $a->barrio, 'Ciudad residencia' => $a->ciudad_resid_nombre,
            'Urbana' => $a->es_urbana, 'Teléfono' => $a->telefono, 'Celular' => $a->celular, 'Estrato' => $a->estrato,
            'SISBEN' => $a->sisben, 'SISBEN 3' => $a->sisben_3, 'Fecha de nacim' => $a->fecha_nac,
            'Departam nacimiento' => $a->departamento_nac_nombre, 'Ciudad nacimiento' => $a->ciudad_nac_nombre,
            'Sexo' => $a->sexo, 'Nuevo' => $a->estado === null ? 'NO' : $a->es_nuevo, 'RH' => $a->tipo_sangre, 'EPS' => $a->eps, 'Religión' => $a->religion,
        ];
        foreach (array_slice($acudientes, 0, 2) as $k => $ac) {
            $s = 'Acud'.($k + 1);
            $v += [
                'ID '.$s => $ac->id, 'Nombres '.$s => $ac->nombres, 'Apellidos '.$s => $ac->apellidos, 'Sexo '.$s => $ac->sexo,
                'Tipo docu '.$s => $ac->tipo_doc_nombre, '¿Es el acudiente? '.$s => $ac->es_acudiente, 'Parentesco '.$s => $ac->parentesco,
                'Documento '.$s => $ac->documento, 'Departam Docu '.$s => $ac->departamento_doc_nombre, 'Ciudad Docu '.$s => $ac->ciudad_doc_nombre,
                'Teléfono '.$s => $ac->telefono, 'Celular '.$s => $ac->celular, 'Ocupación '.$s => $ac->ocupacion, 'Dirección '.$s => $ac->direccion,
                'Username '.$s => $ac->username, 'Email '.$s => $ac->email, 'Observaciones '.$s => $ac->observaciones,
            ];
        }
        $v = array_map(fn ($x) => $x === null ? '' : (string) $x, $v);

        return ['valores' => $v];
    }
}
