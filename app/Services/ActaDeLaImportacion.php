<?php

namespace App\Services;

use App\Support\Reloj;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * **El acta de una importación de planilla: lo que ENTRÓ, no lo que se prometió.**
 *
 * Fase 5 de «notas sin internet». Es la pieza que el plan pide con una frase que
 * dice para qué sirve y no sólo qué es: *«un acta descargable de lo que entró —
 * que es lo que hace falta cuando el docente y coordinación no están de acuerdo en
 * qué se subió»*. O sea que su lector no es quien acaba de pulsar el botón —ése ya
 * vio el recuento en la pantalla— sino **alguien que pregunta después**, cuando la
 * respuesta HTTP hace semanas que se fue.
 *
 * Esta clase hace las dos mitades de eso:
 *
 * 1. {@see deLaPasada} arma **lo que una pasada hizo**, para que
 *    {@see PuntoDeControlDeImportacion::guardarHechos} lo acumule en la fila.
 * 2. {@see construir} lo pinta.
 *
 * ## Un `.xlsx` y NO un PDF, y la decisión no es estética
 *
 * **En este backend no hay ninguna librería de PDF.** Los informes del colegio
 * —boletines, certificados, actas de evaluación— los imprime el **front** desde el
 * navegador: la API manda los datos y el papel se hace allí. Montar `dompdf` o
 * `mpdf` aquí sería meter una dependencia nueva, con sus fuentes y su memoria, en
 * los dieciséis colegios **para un único documento**, y encima uno cuyo contenido
 * es una tabla de números.
 *
 * Con el `.xlsx` no se añade nada: **PhpSpreadsheet ya está**, es lo que genera la
 * planilla que esta misma familia descarga, y el acta comparte con ella la portada
 * —el colegio arriba, el periodo debajo— así que las dos se reconocen como del
 * mismo sitio. Y de paso el acta se puede **filtrar y sumar**, que es lo que
 * alguien hace de verdad con un desglose de 40 hojas y no podría hacer con un PDF.
 *
 * Si algún día hay que entregarlo en papel: se abre y se imprime, que es
 * exactamente lo que ya se hace hoy con la planilla.
 *
 * ## Por qué el contenido son «dos personas y una lista de motivos»
 *
 * Porque el acta se pide cuando algo no cuadra, y lo que no cuadra es casi siempre
 * una de estas tres:
 *
 * - **«Yo no subí eso»** → la cabecera dice quién subió y **por cuenta de quién**,
 *   con la fecha y la huella del archivo. La huella es lo que distingue «el mismo
 *   libro» de «otro libro con el mismo nombre».
 * - **«Faltan notas»** → el desglose por hoja dice cuántas entraron **y cuántas se
 *   quedaron fuera con su motivo**. Un acta que sólo dijera las que entraron
 *   convertiría cada hueco en una discusión.
 * - **«Eso lo subí de una vez»** → `pasadas` y `reanudada`: una importación que se
 *   cortó y se continuó son dos momentos distintos, y las notas llevan la hora del
 *   segundo.
 */
class ActaDeLaImportacion
{
    /** El mismo azul de la portada del libro, para que las dos se reconozcan. */
    public const COLOR_DE_MARCA = 'FF1677FF';

    /** El tope de motivos que se guardan por hoja. Ver {@see deLaPasada}. */
    private const TOPE_DE_MOTIVOS = 200;

    /**
     * **Lo que ESTA pasada hizo**, en la forma que `guardarHechos` sabe acumular.
     *
     * Junta las dos mitades que hoy viajan por separado y **tienen que ir juntas
     * para que el acta signifique algo**:
     *
     * - De {@see EscrituraDeNotasImportadas} sale **lo que se escribió**: las notas,
     *   las que se borraron, las faltas, los indicadores. Sale de ahí y no del plan
     *   porque es el objeto que hizo los `UPDATE`, y contarlo desde el plan sería
     *   contar la promesa.
     * - Del diagnóstico sale **lo que no se hizo y por qué**. Eso el escritor no lo
     *   sabe: nunca vio esas casillas, precisamente porque no entraron.
     *
     * ## `filas_descartadas` se cae de los totales a propósito
     *
     * Es el único contador que **una pasada reanudada vuelve a contar**: una fila
     * escrita a mano que no se puede emparejar no se escribe, así que tampoco se
     * marca como hecha, así que la pasada siguiente la diagnostica otra vez. Sumarla
     * daría el doble en dos tandas. El acta la cuenta a partir de `descartadas`, que
     * es un conjunto de identificadores y no un número, y por eso se puede unir.
     *
     * @param  array<string, mixed>  $diagnostico  lo que devolvió el ensayo de esta pasada
     * @param  array<string, mixed>  $contexto  las dos personas y el libro; ver el controlador
     * @return array<string, mixed>
     */
    public static function deLaPasada(array $diagnostico, EscrituraDeNotasImportadas $escritura,
        array $contexto): array
    {
        $totales = $escritura->hechos;
        unset($totales['filas_descartadas']);

        return [
            'totales' => $totales,
            'por_hoja' => self::porHoja($diagnostico, $escritura),
            'indicadores' => $escritura->indicadoresCreados,
            'contexto' => $contexto,
        ];
    }

    /**
     * El desglose por hoja, **llaveado por el nombre de la hoja**.
     *
     * Por el nombre y no por la posición: en una pasada reanudada las hojas que ya
     * estaban hechas no vuelven a aparecer, así que los índices no cuadran entre
     * tandas. El nombre sí — es el mismo archivo.
     *
     * @param  array<string, mixed>  $diagnostico
     * @return array<string, array<string, mixed>>
     */
    private static function porHoja(array $diagnostico, EscrituraDeNotasImportadas $escritura): array
    {
        $hojas = [];

        // 1 · El armazón sale del ENSAYO y no del escritor, porque el ensayo ve
        //     también las hojas que no entraron — que son justo las que hay que
        //     explicar. Una hoja caída no aparece en `$escritura->porHoja`.
        foreach ($diagnostico['por_hoja'] ?? [] as $fila) {
            $nombre = (string) ($fila['hoja'] ?? '');

            if ($nombre === '') {
                continue;
            }

            $hojas[$nombre] = [
                'hoja' => $nombre,
                'asignatura' => $fila['asignatura'] ?? null,
                'asignatura_id' => isset($fila['asignatura_id']) ? (int) $fila['asignatura_id'] : null,
                'escritas' => 0,
                'borradas' => 0,
                'se_quedan_fuera' => (int) ($fila['se_quedan_fuera'] ?? 0),
                'indicadores_creados' => 0,
                'fuera' => false,
                'motivos' => [],
                'descartadas' => [],
            ];
        }

        // 2 · Si la hoja se cayó entera, su motivo y su marca. `fuera` no es
        //     decorativo: es lo que le dice a `juntarLasHojas` que `se_quedan_fuera`
        //     se sustituye en vez de sumarse (una hoja caída se re-diagnostica igual
        //     en cada pasada).
        foreach ($diagnostico['hojas'] ?? [] as $ficha) {
            $nombre = (string) ($ficha['nombre'] ?? '');

            if ($nombre === '' || ! isset($hojas[$nombre])) {
                continue;
            }

            if (($ficha['fuera'] ?? false) === true) {
                $hojas[$nombre]['fuera'] = true;
                $hojas[$nombre]['motivos'][] = (string) ($ficha['motivo_fuera'] ?? 'La hoja no entró.');
            }
        }

        // 3 · Lo que SÍ se escribió. Se suma encima del armazón, y si el escritor
        //     trae una hoja que el ensayo no listó —no debería, salen del mismo
        //     recorrido— se crea: perder una hoja escrita del acta sería el peor
        //     error posible aquí.
        foreach ($escritura->porHoja as $fila) {
            $nombre = (string) ($fila['hoja'] ?? '');

            if ($nombre === '') {
                continue;
            }

            $hojas[$nombre] ??= [
                'hoja' => $nombre,
                'asignatura' => $fila['asignatura'] ?? null,
                'asignatura_id' => isset($fila['asignatura_id']) ? (int) $fila['asignatura_id'] : null,
                'escritas' => 0, 'borradas' => 0, 'se_quedan_fuera' => 0, 'indicadores_creados' => 0,
                'fuera' => false, 'motivos' => [], 'descartadas' => [],
            ];

            $hojas[$nombre]['escritas'] += (int) ($fila['escritas'] ?? 0);
            $hojas[$nombre]['borradas'] += (int) ($fila['borradas'] ?? 0);
        }

        foreach ($escritura->indicadoresCreados as $indicador) {
            $nombre = (string) ($indicador['hoja'] ?? '');

            if ($nombre !== '' && isset($hojas[$nombre])) {
                $hojas[$nombre]['indicadores_creados']++;
            }
        }

        self::motivosDeLasFamilias($diagnostico, $hojas);

        foreach ($hojas as $nombre => $hoja) {
            // El tope no protege la columna —es `longText`— sino el acta: mil
            // motivos iguales no se leen, y el archivo de una hoja escrita entera en
            // otro idioma se iría a megabytes de texto repetido.
            $hojas[$nombre]['motivos'] = array_slice(
                array_values(array_unique($hoja['motivos'])), 0, self::TOPE_DE_MOTIVOS
            );
        }

        return $hojas;
    }

    /**
     * Los motivos que vienen de las familias del ensayo y **saben de qué hoja son**.
     *
     * Son tres —las filas que no se reconocen (F6), las columnas de reserva que no
     * se crearon (F9) y los conteos de asistencia que no se aplicaron (F8)— y son
     * las tres que llevan `hoja` dentro. Las otras dos familias, `celdas` (F4) y
     * `escala` (F5), **se agrupan por valor y no por hoja**: un «4,5» que aparece en
     * seis hojas es un solo renglón. Ésas van al acta por el otro camino, la columna
     * `avisos`, y se pintan en su propia sección.
     *
     * @param  array<string, mixed>  $diagnostico
     * @param  array<string, array<string, mixed>>  $hojas
     */
    private static function motivosDeLasFamilias(array $diagnostico, array &$hojas): void
    {
        $familias = $diagnostico['familias'] ?? [];

        foreach ($familias['filas'] ?? [] as $fila) {
            $nombre = (string) ($fila['hoja'] ?? '');

            if ($nombre === '' || ! isset($hojas[$nombre]) || ($fila['resuelta'] ?? false) === true) {
                continue;
            }

            // **El `id` y no un contador**: es estable entre pasadas —lo compone la F6
            // con la hoja, la fila y el tipo— y es lo que permite unir en vez de sumar.
            $hojas[$nombre]['descartadas'][] = (string) ($fila['id'] ?? ($nombre.':'.($fila['fila'] ?? '?')));
            $hojas[$nombre]['motivos'][] = trim((string) ($fila['titulo'] ?? 'Una fila no se reconoció'))
                .'. '.trim((string) ($fila['si_no_hago_nada'] ?? ''));
        }

        foreach ($familias['reserva'] ?? [] as $renglon) {
            $nombre = (string) ($renglon['hoja'] ?? '');

            if ($nombre === '' || ! isset($hojas[$nombre])
                || ($renglon['se_crea'] ?? false) === true || (int) ($renglon['notas'] ?? 0) === 0) {
                continue;
            }

            $hojas[$nombre]['motivos'][] = 'Columna '.($renglon['columna'] ?? '?').': '
                .trim((string) ($renglon['si_no_hago_nada'] ?? ''));
        }

        foreach ($familias['ausencias'] ?? [] as $renglon) {
            $nombre = (string) ($renglon['hoja'] ?? '');

            if ($nombre === '' || ! isset($hojas[$nombre])
                || ($renglon['decision'] ?? null) === RespuestasDeLaPlanilla::AUSENCIAS_APLICAR) {
                continue;
            }

            $hojas[$nombre]['motivos'][] = ($renglon['alumno'] ?? 'Un alumno')
                .' ('.($renglon['tipo'] ?? 'asistencia').'): '
                .trim((string) ($renglon['si_no_hago_nada'] ?? ''));
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // El papel
    // ─────────────────────────────────────────────────────────────────────────

    /** `acta-importacion-412-P2-2026.xlsx`, para que se distingan en una carpeta. */
    public static function nombreDeArchivo(object $importacion, array $hechos): string
    {
        $periodo = $hechos['contexto']['libro']['periodo_numero'] ?? null;

        return 'acta-importacion-'.$importacion->id
            .($periodo === null ? '' : '-P'.$periodo)
            .'-'.$importacion->year.'.xlsx';
    }

    /**
     * El acta, en una hoja.
     *
     * **Una hoja y no cinco**, aunque tenga cinco secciones: el acta se lee de
     * arriba abajo una vez, no se navega. Repartirla en pestañas obligaría a mirar
     * cuatro para saber si falta algo, que es exactamente lo que no puede costar.
     *
     * @param  object  $importacion  la fila de `importaciones`
     * @param  array<string, mixed>  $hechos  su columna `hechos`, ya decodificada
     * @param  list<string>  $avisos  su columna `avisos`: lo que no tiene hoja
     */
    public static function construir(object $importacion, array $hechos, array $avisos): Spreadsheet
    {
        $libro = new Spreadsheet;

        $libro->getProperties()
            ->setCreator('MyVC')
            ->setTitle('Acta de importación '.$importacion->id)
            ->setDescription('Lo que entró en el sistema al subir esta planilla.');

        $hoja = $libro->getActiveSheet();
        $hoja->setTitle('Acta');

        $hoja->getColumnDimension('A')->setWidth(34);
        $hoja->getColumnDimension('B')->setWidth(46);
        $hoja->getColumnDimension('C')->setWidth(14);
        $hoja->getColumnDimension('D')->setWidth(14);
        $hoja->getColumnDimension('E')->setWidth(14);
        $hoja->getColumnDimension('F')->setWidth(14);

        $contexto = is_array($hechos['contexto'] ?? null) ? $hechos['contexto'] : [];

        $fila = self::pintarCabecera($hoja, $contexto);
        $fila = self::pintarQuien($hoja, $importacion, $contexto, $fila);
        $fila = self::pintarTotales($hoja, $hechos, $fila);
        $fila = self::pintarLasHojas($hoja, $hechos, $fila);
        $fila = self::pintarLosIndicadores($hoja, $hechos, $fila);
        self::pintarLosAvisos($hoja, $avisos, $fila);

        // **Bloqueada entera, igual que la portada del libro.** No hay nada que
        // escribir en un acta: lo que dice ya pasó, y una casilla editable invita a
        // «corregirla» antes de reenviarla a alguien.
        $hoja->getProtection()->setSheet(true);
        $hoja->setSelectedCell('A1');

        return $libro;
    }

    /**
     * El colegio arriba y el periodo debajo, **como la portada del libro**.
     *
     * Los nombres se resuelven ahora y no se guardaron al importar, a diferencia de
     * los de las dos personas. La diferencia tiene motivo: el colegio y el periodo
     * **siguen existiendo** y su nombre de hoy es el bueno —si el colegio se renombró,
     * el acta tiene que decir cómo se llama—, mientras que una cuenta de usuario se
     * puede borrar y entonces el nombre guardado es lo único que queda de quién fue.
     *
     * @param  array<string, mixed>  $contexto
     */
    private static function pintarCabecera(Worksheet $hoja, array $contexto): int
    {
        $libro = is_array($contexto['libro'] ?? null) ? $contexto['libro'] : [];

        $anio = null;

        if (isset($libro['year_id'])) {
            $anio = DB::selectOne(
                'SELECT y.year, y.nombre_colegio FROM years y WHERE y.id = ?',
                [(int) $libro['year_id']]
            );
        }

        $hoja->mergeCells('A1:F1');
        $hoja->setCellValue('A1', mb_strtoupper((string) ($anio->nombre_colegio ?? 'Colegio'), 'UTF-8'));
        $hoja->getStyle('A1:F1')->applyFromArray([
            'font' => ['bold' => true, 'size' => 14, 'color' => ['argb' => 'FFFFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => self::COLOR_DE_MARCA]],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'indent' => 1],
        ]);
        $hoja->getRowDimension(1)->setRowHeight(28);

        $periodo = $libro['periodo_numero'] ?? null;

        $hoja->setCellValue('A2', 'Acta de importación de notas'
            .($periodo === null ? '' : ' · Periodo '.$periodo)
            .($anio === null ? '' : ' · '.$anio->year));
        $hoja->getStyle('A2')->getFont()->setBold(true)->setSize(11);

        $hoja->setCellValue('A3', 'Lo que entró en el sistema al subir esta planilla. '
            .'No es el plan de la subida: es el recuento de lo escrito.');
        $hoja->getStyle('A3')->getFont()->setSize(9)->getColor()->setARGB('FF888888');

        // La fecha del acta y no la de la importación, y por eso dice «generada»:
        // dos actas de la misma importación son el mismo documento, y quien las
        // compara tiene que poder ver que lo son.
        $hoja->setCellValue('A4', 'Acta generada el '.Reloj::ahora()->format(Reloj::FORMATO_HUMANO));
        $hoja->getStyle('A4')->getFont()->setSize(9)->getColor()->setARGB('FF888888');

        return 6;
    }

    /**
     * **Las dos personas**, el archivo y su huella.
     *
     * «Por cuenta de» va en su propio renglón y no escondido detrás de quien subió,
     * porque es el dato que hace falta cuando alguien pregunta — y un acta que lo
     * pusiera entre paréntesis lo dejaría fuera de un `Ctrl+F`.
     *
     * @param  array<string, mixed>  $contexto
     */
    private static function pintarQuien(Worksheet $hoja, object $importacion, array $contexto, int $fila): int
    {
        $fila = self::titulo($hoja, 'Quién, qué y cuándo', $fila);

        $delLibro = is_array($contexto['libro'] ?? null) ? $contexto['libro'] : [];
        $subio = is_array($contexto['subio'] ?? null) ? $contexto['subio'] : [];
        $porCuenta = is_array($contexto['por_cuenta_de'] ?? null) ? $contexto['por_cuenta_de'] : null;

        $renglones = [
            ['La subió', ($subio['nombre'] ?? 'Desconocido')
                .(isset($subio['user_id']) ? ' (usuario '.$subio['user_id'].')' : '')],

            // La frase entera y no sólo el nombre: «Por cuenta de — (nadie)» se lee
            // mal, y «el libro es suyo» contesta la pregunta de un vistazo.
            ['Por cuenta de', $porCuenta === null
                ? 'Nadie: subió su propio libro.'
                : ($porCuenta['nombre'] ?? 'el docente '.($porCuenta['profesor_id'] ?? '?'))
                  .' · el libro es de esa persona y lo subió coordinación'],
            ['El libro es de', $delLibro['profesor'] ?? 'Sin docente asignado'],
            ['Empezó', (string) ($importacion->inicio ?? $importacion->created_at ?? '')],
            ['Terminó', $importacion->fin === null
                ? 'Sin terminar (estado: '.$importacion->estado.')'
                : (string) $importacion->fin],
            ['Archivo', (string) ($importacion->archivo ?? 'sin nombre')],
            ['Huella del archivo (sha256)', (string) $importacion->huella],
            ['Descargado el', (string) ($delLibro['descargado_at'] ?? 'no consta en el libro')],
            ['Reanudada', ($contexto['reanudada'] ?? false) === true
                ? 'Sí: se cortó y se continuó. '.($contexto['pasadas'] ?? '?').' pasada(s) en total.'
                : 'No: entró en '.($contexto['pasadas'] ?? 1).' pasada(s).'],
            ['Importación', '#'.$importacion->id.' · estado '.$importacion->estado],
        ];

        foreach ($renglones as [$etiqueta, $valor]) {
            $hoja->setCellValue('A'.$fila, $etiqueta);
            $hoja->getStyle('A'.$fila)->getFont()->setBold(true);

            // **Como texto explícito.** Una huella de 64 caracteres hexadecimales que
            // empiece por dígitos se convierte en notación científica si se deja que
            // Excel adivine, y entonces deja de servir para lo único que sirve:
            // comparar dos archivos.
            $hoja->setCellValueExplicit('B'.$fila, (string) $valor, DataType::TYPE_STRING);
            $hoja->getStyle('B'.$fila)->getAlignment()->setWrapText(true);

            $fila++;
        }

        if ($importacion->error !== null && $importacion->error !== '') {
            $hoja->setCellValue('A'.$fila, 'Se cortó con un error');
            $hoja->getStyle('A'.$fila)->getFont()->setBold(true);
            $hoja->setCellValueExplicit('B'.$fila, (string) $importacion->error, DataType::TYPE_STRING);
            $hoja->getStyle('B'.$fila)->getAlignment()->setWrapText(true);
            $fila++;
        }

        return $fila + 1;
    }

    /**
     * El recuento del libro entero.
     *
     * `filas_descartadas` se cuenta aquí a partir del conjunto de identificadores y
     * no de un contador, por lo que explica {@see deLaPasada}.
     *
     * @param  array<string, mixed>  $hechos
     */
    private static function pintarTotales(Worksheet $hoja, array $hechos, int $fila): int
    {
        $fila = self::titulo($hoja, 'Lo que entró, en total', $fila);

        $totales = is_array($hechos['totales'] ?? null) ? $hechos['totales'] : [];

        $renglones = [
            ['Notas escritas', (int) ($totales['notas_escritas'] ?? 0)],
            ['Notas borradas', (int) ($totales['notas_borradas'] ?? 0)],
            ['Faltas creadas', (int) ($totales['ausencias_creadas'] ?? 0)],
            ['Faltas borradas', (int) ($totales['ausencias_borradas'] ?? 0)],
            ['Indicadores creados', (int) ($totales['indicadores_creados'] ?? 0)],
            ['Filas de alumno aplicadas', (int) ($totales['filas'] ?? 0)],
            ['Filas descartadas', self::cuantasDescartadas($hechos)],
            ['Definitivas recalculadas', (int) ($totales['definitivas_recalculadas'] ?? 0)],
        ];

        foreach ($renglones as [$etiqueta, $valor]) {
            $hoja->setCellValue('A'.$fila, $etiqueta);
            $hoja->setCellValue('B'.$fila, $valor);
            $hoja->getStyle('B'.$fila)->getFont()->setBold(true);
            $fila++;
        }

        return $fila + 1;
    }

    /**
     * La tabla por hoja, que es el cuerpo del acta.
     *
     * @param  array<string, mixed>  $hechos
     */
    private static function pintarLasHojas(Worksheet $hoja, array $hechos, int $fila): int
    {
        $fila = self::titulo($hoja, 'Por hoja', $fila);

        $cabeceras = ['Hoja', 'Asignatura', 'Escritas', 'Borradas', 'Fuera', 'Filas desc.'];

        foreach ($cabeceras as $i => $texto) {
            $columna = chr(ord('A') + $i);
            $hoja->setCellValue($columna.$fila, $texto);
        }

        $hoja->getStyle('A'.$fila.':F'.$fila)->applyFromArray([
            'font' => ['bold' => true],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFEEF4FF']],
            'borders' => ['bottom' => ['borderStyle' => Border::BORDER_THIN]],
        ]);

        $fila++;

        $porHoja = is_array($hechos['por_hoja'] ?? null) ? $hechos['por_hoja'] : [];

        if ($porHoja === []) {
            $hoja->setCellValue('A'.$fila, 'Esta importación no llegó a mirar ninguna hoja.');

            return $fila + 2;
        }

        foreach ($porHoja as $nombre => $ficha) {
            $hoja->setCellValueExplicit('A'.$fila, (string) $nombre, DataType::TYPE_STRING);
            $hoja->setCellValueExplicit('B'.$fila, (string) ($ficha['asignatura'] ?? ''), DataType::TYPE_STRING);
            $hoja->setCellValue('C'.$fila, (int) ($ficha['escritas'] ?? 0));
            $hoja->setCellValue('D'.$fila, (int) ($ficha['borradas'] ?? 0));
            $hoja->setCellValue('E'.$fila, (int) ($ficha['se_quedan_fuera'] ?? 0));
            $hoja->setCellValue('F'.$fila, count(is_array($ficha['descartadas'] ?? null) ? $ficha['descartadas'] : []));
            $fila++;

            // **El motivo debajo de su hoja y no en un anexo.** Es la diferencia
            // entre «se quedaron fuera 12» —que abre una discusión— y «se quedaron
            // fuera 12 porque el periodo se cerró el martes», que la cierra.
            foreach (is_array($ficha['motivos'] ?? null) ? $ficha['motivos'] : [] as $motivo) {
                $hoja->mergeCells('B'.$fila.':F'.$fila);
                $hoja->setCellValueExplicit('B'.$fila, '· '.$motivo, DataType::TYPE_STRING);
                $hoja->getStyle('B'.$fila)->getFont()->setSize(9)->getColor()->setARGB('FF9C5700');
                $hoja->getStyle('B'.$fila)->getAlignment()->setWrapText(true);
                $fila++;
            }
        }

        return $fila + 1;
    }

    /**
     * Los indicadores que la F9 creó.
     *
     * Van con nombre y peso, y no como un número: un indicador nuevo **cambia la
     * definitiva de todo el grupo**, así que «se creó 1» no es información
     * suficiente para que nadie decida nada.
     *
     * @param  array<string, mixed>  $hechos
     */
    private static function pintarLosIndicadores(Worksheet $hoja, array $hechos, int $fila): int
    {
        $indicadores = is_array($hechos['indicadores'] ?? null) ? $hechos['indicadores'] : [];

        if ($indicadores === []) {
            return $fila;
        }

        $fila = self::titulo($hoja, 'Indicadores creados desde el archivo', $fila);

        foreach ($indicadores as $indicador) {
            $hoja->setCellValueExplicit('A'.$fila, (string) ($indicador['hoja'] ?? ''), DataType::TYPE_STRING);
            $hoja->setCellValueExplicit('B'.$fila,
                (string) ($indicador['definicion'] ?? '').' — '.($indicador['porcentaje'] ?? '?').'%',
                DataType::TYPE_STRING);
            $hoja->setCellValue('C'.$fila, (int) ($indicador['subunidad_id'] ?? 0));
            $fila++;
        }

        return $fila + 1;
    }

    /**
     * Lo que no entró y **no tiene hoja**: la F4 y la F5.
     *
     * Están agrupadas por valor —«seis casillas con “4,5”»— porque así es como se
     * arreglan: de una vez, no una por una. Repartirlas por hoja para que cupieran
     * en la tabla de arriba las convertiría en veinte renglones que dicen lo mismo.
     *
     * @param  list<string>  $avisos
     */
    private static function pintarLosAvisos(Worksheet $hoja, array $avisos, int $fila): void
    {
        if ($avisos === []) {
            return;
        }

        $fila = self::titulo($hoja, 'Avisos de la subida', $fila);

        // **Sin los repetidos exactos.** La columna `avisos` acumula por pasada, así
        // que una importación reanudada trae el mismo aviso una vez por tanda. Esto
        // colapsa los idénticos y **no** los que sólo se parecen —«6 casillas con
        // “4,5”» y «4 casillas con “4,5”» son dos cadenas distintas y las dos son
        // ciertas de su tanda—; arreglar eso de verdad es agrupar por valor al
        // guardar, que es trabajo de la columna y no del papel.
        foreach (array_values(array_unique($avisos)) as $aviso) {
            $hoja->mergeCells('A'.$fila.':F'.$fila);
            $hoja->setCellValueExplicit('A'.$fila, '· '.(string) $aviso, DataType::TYPE_STRING);
            $hoja->getStyle('A'.$fila)->getAlignment()->setWrapText(true);
            $fila++;
        }
    }

    /** Un título de sección, con la franja de la marca. Devuelve la fila siguiente. */
    private static function titulo(Worksheet $hoja, string $texto, int $fila): int
    {
        $hoja->mergeCells('A'.$fila.':F'.$fila);
        $hoja->setCellValue('A'.$fila, $texto);
        $hoja->getStyle('A'.$fila.':F'.$fila)->applyFromArray([
            'font' => ['bold' => true, 'size' => 11, 'color' => ['argb' => self::COLOR_DE_MARCA]],
            'borders' => ['bottom' => ['borderStyle' => Border::BORDER_THIN,
                'color' => ['argb' => self::COLOR_DE_MARCA]]],
        ]);

        return $fila + 1;
    }

    /**
     * Cuántas filas se descartaron **en todo el libro**, contando el conjunto.
     *
     * @param  array<string, mixed>  $hechos
     */
    private static function cuantasDescartadas(array $hechos): int
    {
        $cuantas = 0;

        foreach (is_array($hechos['por_hoja'] ?? null) ? $hechos['por_hoja'] : [] as $ficha) {
            $cuantas += count(is_array($ficha['descartadas'] ?? null) ? $ficha['descartadas'] : []);
        }

        return $cuantas;
    }
}
