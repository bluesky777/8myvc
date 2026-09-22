<?php

namespace App\Exports;

use App\Support\FirmaDelLibro;
use App\Support\RepartoDeLaNota;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * El libro de «notas sin internet»: **una portada, una hoja por asignatura y una
 * hoja oculta firmada**.
 *
 * Fase 1 de `myvc_front/PLAN-NOTAS-SIN-INTERNET.md`. La estructura es su §4.
 *
 * ## PhpSpreadsheet directo y NO `FromView`, que es el patrón de al lado
 *
 * Los cuatro exports que ya existen (`AlumnosExport`, `DocentesExport`…) usan
 * `FromView`: una vista Blade con una tabla, y `maatwebsite` la convierte. Aquí no
 * vale, y no es una preferencia: **una vista no sabe proteger celdas, ni poner
 * validaciones, ni comentarios, ni hipervínculos internos, ni formato
 * condicional**, y las cinco cosas son el libro. Lo que se hereda de aquel patrón
 * es lo que sí aplica —que el fichero se arma en el backend (D6) y se sirve como
 * descarga— y nada más.
 *
 * Por lo mismo esto **no implementa ninguna interfaz de `maatwebsite`**: devuelve
 * un `Spreadsheet` y el controlador lo escribe. Meterlo en un `FromArray` para
 * poder decir `Excel::download(new LibroDeNotas)` sería envolver el resultado en
 * una capa que no aporta nada y que además habría que sortear con `WithEvents`
 * para tocar la hoja de verdad.
 *
 * ## El color del colegio: aquí está mal el plan, y se dice en voz alta
 *
 * La §4.5 dice que el color sale *«de la misma configuración que usa el boletín»*.
 * **Esa configuración no existe en este backend**: en las 90 tablas del esquema no
 * hay ninguna columna de color, y el `--myvc-acento` de `app2` es **la paleta que
 * elige cada usuario**, guardada en su `localStorage` (ver `core/tema/tema-usuario.ts`,
 * que lo dice en su cabecera). O sea que no es un dato del colegio: es un dato del
 * navegador de una persona, y el servidor no lo tiene.
 *
 * Así que el libro sale con {@see COLOR_DE_MARCA}, el azul por defecto de MyVc, y
 * eso queda anotado como pendiente en
 * `docs/migracion/49-la-planilla-sin-internet.md`. **No se inventa un color por
 * colegio ni se pide por parámetro**: un color que el front mandara sería el de
 * quien pulsa el botón, no el del colegio, y Flutter mandaría otro distinto para
 * el mismo libro.
 */
final class LibroDeNotas
{
    /**
     * La contraseña de las hojas protegidas, **y no es un secreto**.
     *
     * Está escrita aquí, en un fichero del repositorio, a propósito: la protección
     * de PhpSpreadsheet se salta cambiando la extensión a `.zip`, así que fingir
     * que es una clave sería mentir sobre lo que protege. Lo que protege es el
     * borrado accidental del `ID` de un alumno al arrastrar una selección (§4.3 del
     * plan), y para eso una contraseña conocida sirve igual.
     *
     * Y tiene una segunda utilidad que sí importa: **quien de soporte necesite
     * desbloquear la hoja de un docente puede hacerlo sin pedirle el archivo a
     * nadie.**
     */
    public const CONTRASENA = 'myvc';

    /**
     * El azul de MyVc. Ver la cabecera: el color del colegio no existe en el
     * backend, así que esto es un valor por defecto honesto y no una elección.
     */
    public const COLOR_DE_MARCA = 'FF1677FF';

    /** La hoja de portada. El nombre se usa como destino de los hipervínculos. */
    public const PORTADA = 'Bienvenida';

    /** La hoja oculta con el mapa y el espejo. El guion bajo la manda al final. */
    public const METADATOS = '_myvc';

    /** @var list<array<string, mixed>> */
    private array $mapas = [];

    /**
     * @param  object  $anio  `LaPlanillaQueSeDescarga::cabeceraDelAnio`
     * @param  object  $periodo  `{id, numero, abierto}`
     * @param  object  $profesor  `{id, nombre}`
     * @param  list<object>  $planillas  una por asignatura, en el orden de las hojas
     * @param  list<object>  $escalas  las bandas del año
     * @param  array<int,object>  $recuentos  `{alumnos, indicadores, sin_pasar}` por `asignatura_id`
     */
    public function __construct(
        private object $anio,
        private object $periodo,
        private object $profesor,
        private array $planillas,
        private array $escalas,
        private array $recuentos,
        private string $generadoEn,
    ) {}

    /**
     * El nombre del archivo.
     *
     * **`-consulta` cuando el periodo está cerrado**, y el nombre cambia porque el
     * archivo es distinto: ése no se va a poder subir. Un docente con cuatro libros
     * en una carpeta tiene que poder ver cuál es cuál sin abrirlos (§4.1).
     */
    public function nombreDeArchivo(): string
    {
        $base = 'notas-P'.$this->periodo->numero.'-'.$this->anio->year;

        return $this->periodo->abierto ? $base.'.xlsx' : $base.'-consulta.xlsx';
    }

    public function construir(): Spreadsheet
    {
        $libro = new Spreadsheet;

        $libro->getProperties()
            ->setCreator('MyVc')
            ->setTitle('Planilla de notas · '.$this->anio->nombre_colegio)
            ->setSubject('Periodo '.$this->periodo->numero.' · '.$this->anio->year)
            ->setDescription($this->profesor->nombre);

        $portada = $libro->getActiveSheet();
        $portada->setTitle(self::PORTADA);

        $usados = [self::PORTADA => true, self::METADATOS => true];

        foreach ($this->planillas as $planilla) {
            $hoja = $libro->createSheet();
            $hoja->setTitle($this->tituloDeHoja($planilla->asignatura, $usados));

            $pintor = new HojaDeAsignatura(
                $planilla, $this->anio, $this->periodo, $this->escalas, self::COLOR_DE_MARCA
            );

            $this->mapas[] = $pintor->pintar($hoja, self::PORTADA);
        }

        $this->pintarPortada($portada);
        $this->pintarMetadatos($libro);

        // El libro se abre por la portada, siempre. Sin esto Excel abre por la hoja
        // que estuviera activa al escribir, que sería la última de asignatura.
        $libro->setActiveSheetIndexByName(self::PORTADA);

        return $libro;
    }

    /**
     * Un nombre de hoja **único y de 31 caracteres como mucho**, que es el tope de
     * Excel.
     *
     * Los caracteres que Excel prohíbe en un nombre de hoja —`* : / \ ? [ ]`— se
     * cambian por un espacio antes de recortar. Y la unicidad se cierra con un
     * sufijo numérico y **no con el id de la asignatura**: un docente puede tener
     * «3B Matemáticas» dos veces por dos asignaturas distintas del mismo nombre, y
     * `3B Matemáticas 2` le dice más que `3B Matemáticas 1873`.
     *
     * @param  array<string,bool>  $usados
     */
    private function tituloDeHoja(object $asignatura, array &$usados): string
    {
        $grupo = trim((string) ($asignatura->abrev_grupo ?: $asignatura->nombre_grupo));
        $materia = trim((string) ($asignatura->alias_materia ?: $asignatura->materia));

        $bruto = trim(preg_replace('/[*:\/\\\\?\[\]]/u', ' ', $grupo.' '.$materia) ?? '');
        $bruto = trim(preg_replace('/\s+/u', ' ', $bruto) ?? '');

        if ($bruto === '') {
            $bruto = 'Asignatura';
        }

        $nombre = mb_substr($bruto, 0, 31, 'UTF-8');
        $numero = 1;

        while (isset($usados[$nombre])) {
            $numero++;
            $sufijo = ' '.$numero;
            $nombre = mb_substr($bruto, 0, 31 - mb_strlen($sufijo, 'UTF-8'), 'UTF-8').$sufijo;
        }

        $usados[$nombre] = true;

        return $nombre;
    }

    /**
     * La portada: cabecera, cómo se usa, el menú de asignaturas y la escala.
     *
     * **El menú son hipervínculos internos, no macros.** Un `.xlsm` con VBA lo
     * bloquea Excel por defecto, no lo abre Google Sheets y lo marca media docena
     * de antivirus (§4.1 del plan).
     */
    private function pintarPortada(Worksheet $hoja): void
    {
        $hoja->getColumnDimension('A')->setWidth(4);
        $hoja->getColumnDimension('B')->setWidth(38);
        $hoja->getColumnDimension('C')->setWidth(14);
        $hoja->getColumnDimension('D')->setWidth(16);
        $hoja->getColumnDimension('E')->setWidth(18);

        $fila = 1;

        if (! $this->periodo->abierto) {
            // **La banda roja del periodo cerrado, arriba del todo.** Va antes que el
            // nombre del colegio porque es lo primero que hay que saber: este archivo
            // se puede leer y no se puede subir, y descubrirlo al final de una tarde
            // de pasar notas es la peor forma de enterarse.
            $hoja->mergeCells('A1:E1');
            $hoja->setCellValue('A1',
                'COPIA DE CONSULTA · Este periodo está cerrado y esta planilla NO se puede subir.');
            $hoja->getStyle('A1:E1')->applyFromArray([
                'font' => ['bold' => true, 'size' => 11, 'color' => ['argb' => 'FFFFFFFF']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFC0392B']],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            ]);
            $hoja->getRowDimension(1)->setRowHeight(26);
            $fila = 3;
        }

        $hoja->mergeCells('A'.$fila.':E'.$fila);
        $hoja->setCellValue('A'.$fila, mb_strtoupper($this->anio->nombre_colegio, 'UTF-8'));
        $hoja->getStyle('A'.$fila)->applyFromArray([
            'font' => ['bold' => true, 'size' => 14, 'color' => ['argb' => 'FFFFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => self::COLOR_DE_MARCA]],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'indent' => 1],
        ]);
        $hoja->getRowDimension($fila)->setRowHeight(28);
        $fila++;

        $hoja->setCellValue('A'.$fila, 'Planilla de notas · Periodo '.$this->periodo->numero.' · '.$this->anio->year);
        $hoja->getStyle('A'.$fila)->getFont()->setBold(true)->setSize(11);
        $fila++;

        $hoja->setCellValue('A'.$fila, $this->profesor->nombre);
        $fila++;

        $hoja->setCellValue('A'.$fila, 'Descargada el '.$this->generadoEn);
        $hoja->getStyle('A'.$fila)->getFont()->setSize(9)->getColor()->setARGB('FF888888');
        $fila += 2;

        $fila = $this->comoSeUsa($hoja, $fila);
        $fila = $this->menuDeAsignaturas($hoja, $fila);
        $fila = $this->avisos($hoja, $fila);
        $this->leyendaDeLaEscala($hoja, $fila);

        // **La portada entera va bloqueada.** No hay nada que escribir en ella, y
        // dejarla abierta invita a tomar notas encima de los enlaces.
        $hoja->getProtection()->setSheet(true);
        $hoja->getProtection()->setPassword(self::CONTRASENA);
        $hoja->setSelectedCell('A1');
    }

    /**
     * Las cinco instrucciones, y la quinta es la que no se adivina sola.
     *
     * La D9 —*una casilla vacía no borra; para borrar se escribe un guion*— es la
     * única regla del libro que **no se puede deducir mirándolo**, y por eso vive
     * aquí y no sólo en el mensaje de la casilla: quien lea la portada una vez ya
     * no necesita descubrirla.
     */
    private function comoSeUsa(Worksheet $hoja, int $fila): int
    {
        $rango = $this->anio->escala_maxima === null
            ? 'números enteros'
            : 'números enteros de '.($this->anio->escala_minima ?? 0).' a '.$this->anio->escala_maxima;

        $hoja->setCellValue('A'.$fila, 'CÓMO SE USA');
        $hoja->getStyle('A'.$fila)->getFont()->setBold(true)->setSize(10);
        $fila++;

        $pasos = [
            'Pulse el nombre de una asignatura para ir a su planilla.',
            'Escriba las notas en las casillas blancas. Sólo '.$rango.'.',
            'Una casilla vacía se queda como está: no borra la nota que ya hubiera.',
            'Para BORRAR una nota, escriba un guion: -',
            'Lo gris no se puede tocar: es lo que le dice al sistema quién es quién. '
                .'Guarde el archivo y súbalo en Académico → Trabajar sin internet → Subir una planilla.',
        ];

        foreach ($pasos as $i => $paso) {
            $hoja->setCellValue('A'.$fila, ($i + 1).'.');
            $hoja->getStyle('A'.$fila)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $hoja->mergeCells('B'.$fila.':E'.$fila);
            $hoja->setCellValue('B'.$fila, $paso);
            $hoja->getStyle('B'.$fila)->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);

            if ($i === 3) {
                $hoja->getStyle('B'.$fila)->getFont()->setBold(true);
            }

            $fila++;
        }

        return $fila + 1;
    }

    /**
     * El menú: una fila por asignatura, con **hipervínculo interno** a su hoja.
     *
     * **«12 sin» es el número que convierte la portada en una lista de tareas** en
     * vez de en un índice: se ve de un vistazo qué falta por pasar (§4.1). Sale de
     * la resta `alumnos × indicadores − notas con valor`, no de ningún contador
     * guardado — el porqué está en `LaPlanillaQueSeDescarga::recuentosDelPeriodo`.
     */
    private function menuDeAsignaturas(Worksheet $hoja, int $fila): int
    {
        $hoja->setCellValue('A'.$fila, 'SUS ASIGNATURAS');
        $hoja->getStyle('A'.$fila)->getFont()->setBold(true)->setSize(10);
        $fila++;

        foreach (['B' => '', 'C' => 'Alumnos', 'D' => $this->anio->subunidades_displayname, 'E' => 'Sin pasar'] as $columna => $rotulo) {
            $hoja->setCellValue($columna.$fila, $rotulo);
        }

        $hoja->getStyle('A'.$fila.':E'.$fila)->applyFromArray([
            'font' => ['bold' => true, 'size' => 9],
            'borders' => ['bottom' => ['borderStyle' => Border::BORDER_THIN]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);
        $hoja->getStyle('B'.$fila)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
        $fila++;

        foreach ($this->mapas as $mapa) {
            $recuento = $this->recuentos[$mapa['asignatura_id']] ?? null;

            $hoja->setCellValue('A'.$fila, '→');
            $hoja->setCellValue('B'.$fila, $mapa['hoja']);
            $hoja->getCell('B'.$fila)->getHyperlink()->setUrl("sheet://'".$mapa['hoja']."'!A1");
            $hoja->getStyle('B'.$fila)->getFont()
                ->setUnderline(true)->setBold(true)->getColor()->setARGB(self::COLOR_DE_MARCA);

            $hoja->setCellValue('C'.$fila, $recuento->alumnos ?? 0);
            $hoja->setCellValue('D'.$fila, $recuento->indicadores ?? 0);
            $hoja->setCellValue('E'.$fila, $recuento->sin_pasar ?? 0);

            $hoja->getStyle('C'.$fila.':E'.$fila)->getAlignment()
                ->setHorizontal(Alignment::HORIZONTAL_CENTER);

            // El cero se pinta en verde y sin negrita: «aquí no falta nada» tiene
            // que leerse distinto de un número, o la columna se convierte en ruido.
            if (($recuento->sin_pasar ?? 0) > 0) {
                $hoja->getStyle('E'.$fila)->getFont()->setBold(true)->getColor()->setARGB('FFB36B00');
            } else {
                $hoja->getStyle('E'.$fila)->getFont()->getColor()->setARGB('FF2E7D32');
            }

            $fila++;
        }

        if ($this->mapas === []) {
            $hoja->mergeCells('A'.$fila.':E'.$fila);
            $hoja->setCellValue('A'.$fila,
                'No hay ninguna asignatura suya con planilla en este periodo.');
            $fila++;
        }

        return $fila + 1;
    }

    /**
     * Los dos avisos que la portada tiene que dar, y que no son decorativos.
     *
     * 1. **Los alumnos con boletín independiente no van en la rejilla** (§9.4). Van
     *    con sus nombres: un alumno que desaparece de una lista sin explicación es
     *    un alumno que el docente da por perdido y va a buscar a secretaría.
     * 2. **Las unidades que no suman 100.** No se normalizan —hay asignaturas que
     *    suman 200 a propósito, para que una configuración mala se delate— así que
     *    se dice, igual que hace la pantalla (§3.5).
     */
    private function avisos(Worksheet $hoja, int $fila): int
    {
        $lineas = [];

        foreach ($this->planillas as $indice => $planilla) {
            $hojaNombre = $this->mapas[$indice]['hoja'] ?? $planilla->asignatura->materia;

            if ($planilla->independientes !== []) {
                $nombres = array_map(
                    static fn ($a) => trim($a->apellidos.', '.$a->nombres, ' ,'),
                    $planilla->independientes
                );

                $lineas[] = $hojaNombre.': '.count($nombres).' con boletín aparte, fuera de esta planilla — '
                    .implode('; ', $nombres).'.';
            }

            $suma = 0;

            foreach ($planilla->unidades as $unidad) {
                $suma += (int) $unidad->porcentaje;
            }

            if ($planilla->unidades !== [] && $suma !== 100) {
                $lineas[] = $hojaNombre.': los '.mb_strtolower($this->anio->unidades_displayname, 'UTF-8')
                    .' suman '.$suma.'% y no 100%. El libro los enseña tal cual.';
            }
        }

        if ($this->anio->reparto === RepartoDeLaNota::PROMEDIO) {
            $lineas[] = 'Este año reparte por promedio: todos los '
                .mb_strtolower($this->anio->subunidades_displayname, 'UTF-8')
                .' de un mismo '.mb_strtolower($this->anio->unidad_displayname, 'UTF-8')
                .' pesan igual, así que las cabeceras no llevan porcentaje.';
        }

        if ($lineas === []) {
            return $fila;
        }

        $hoja->setCellValue('A'.$fila, 'AVISOS');
        $hoja->getStyle('A'.$fila)->getFont()->setBold(true)->setSize(10);
        $fila++;

        foreach ($lineas as $linea) {
            $hoja->mergeCells('A'.$fila.':E'.$fila);
            $hoja->setCellValue('A'.$fila, $linea);
            $hoja->getStyle('A'.$fila)->getFont()->setSize(9);
            $hoja->getStyle('A'.$fila)->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
            $fila++;
        }

        return $fila + 1;
    }

    /**
     * La leyenda de la escala del año, con su color y su rango.
     *
     * Es lo que hace que los colores de las casillas signifiquen algo. **Sale de
     * `escalas_de_valoracion` del año del periodo**, no de una tabla inventada: en
     * este colegio la escala va de 0 a 50 con mínima 30, y hay colegios sobre 100
     * (§3.2). Un libro con «0 a 100» impreso mide un colegio que no existe.
     */
    private function leyendaDeLaEscala(Worksheet $hoja, int $fila): void
    {
        $hoja->setCellValue('A'.$fila, 'LA ESCALA DE ESTE AÑO');
        $hoja->getStyle('A'.$fila)->getFont()->setBold(true)->setSize(10);
        $fila++;

        if ($this->escalas === []) {
            $hoja->mergeCells('A'.$fila.':E'.$fila);
            $hoja->setCellValue('A'.$fila,
                'Este año no tiene escala configurada, así que el libro no comprueba ningún tope.');
            $hoja->getStyle('A'.$fila)->getFont()->setSize(9);

            return;
        }

        foreach ($this->escalas as $banda) {
            $hoja->setCellValue('B'.$fila,
                $banda->porc_inicial.'–'.$banda->porc_final.'  '.$banda->desempenio
                .($banda->perdido ? '  (perdido)' : ''));

            if ($banda->perdido) {
                $hoja->getStyle('B'.$fila)->getFont()->setBold(true)->getColor()->setARGB('FF9F1616');
            }

            $fila++;
        }

        $hoja->setCellValue('B'.$fila, 'La nota mínima aceptada es '.($this->anio->nota_minima ?? '—').'.');
        $hoja->getStyle('B'.$fila)->getFont()->setSize(9)->getColor()->setARGB('FF888888');
    }

    /**
     * La hoja `_myvc`: **oculta, protegida y firmada** (§4.6 del plan).
     *
     * Es lo que convierte el archivo en algo que se puede volver a leer sin
     * adivinar nada: qué columna es qué indicador, qué fila es qué alumno, y **el
     * espejo de las notas tal y como se descargaron**.
     *
     * ## Por qué el espejo va por alumno y en varias filas
     *
     * Una celda de Excel admite 32.767 caracteres. Una hoja de 45 alumnos por 19
     * indicadores en un solo `json` cabría, y la de un colegio grande con un libro
     * de quince asignaturas no — y el fallo sería **silencioso**: PhpSpreadsheet
     * trunca y el espejo queda a medias, o sea mintiendo justo donde la D3 se fía
     * de él. Una fila por alumno acota el tamaño por construcción.
     *
     * ## Y por qué la firma se calcula sobre la estructura, no sobre las celdas
     *
     * Está explicado en `FirmaDelLibro`: firmar el texto de las celdas ataría la
     * firma al orden en que PHP recorrió un array y a cómo PDO devolvió un entero,
     * y entonces un libro correcto tendría la firma rota sin que nadie lo tocara.
     */
    private function pintarMetadatos(Spreadsheet $libro): void
    {
        $hoja = $libro->createSheet();
        $hoja->setTitle(self::METADATOS);

        $cabecera = [
            'formato' => FirmaDelLibro::FORMATO,
            'year_id' => $this->anio->year_id,
            'year' => $this->anio->year,
            'periodo_id' => $this->periodo->id,
            'periodo_numero' => $this->periodo->numero,
            'periodo_abierto' => $this->periodo->abierto,
            'profesor_id' => $this->profesor->id,
            'reparto' => $this->anio->reparto,
            'escala_minima' => $this->anio->escala_minima,
            'escala_maxima' => $this->anio->escala_maxima,
            'generado' => $this->generadoEn,
        ];

        $firma = FirmaDelLibro::firmar(['libro' => $cabecera, 'hojas' => $this->mapas]);

        $hoja->setCellValue('A1', 'myvc');
        $hoja->setCellValue('B1', FirmaDelLibro::FORMATO);

        $hoja->setCellValue('A2', 'libro');
        $this->texto($hoja, 'B2', (string) json_encode($cabecera, JSON_UNESCAPED_UNICODE));

        $hoja->setCellValue('A3', 'firma');
        $this->texto($hoja, 'B3', $firma);

        $fila = 5;

        foreach ($this->mapas as $mapa) {
            $sinEspejo = $mapa;
            unset($sinEspejo['espejo']);

            $hoja->setCellValue('A'.$fila, 'hoja');
            $this->texto($hoja, 'B'.$fila, (string) $mapa['hoja']);
            $this->texto($hoja, 'C'.$fila, (string) json_encode($sinEspejo, JSON_UNESCAPED_UNICODE));
            $fila++;
        }

        foreach ($this->mapas as $mapa) {
            foreach ($mapa['espejo'] as $alumnoId => $notas) {
                $hoja->setCellValue('A'.$fila, 'espejo');
                $this->texto($hoja, 'B'.$fila, (string) $mapa['hoja']);
                $this->texto($hoja, 'C'.$fila, (string) $alumnoId);
                $this->texto($hoja, 'D'.$fila, (string) json_encode($notas, JSON_UNESCAPED_UNICODE));
                $fila++;
            }
        }

        $hoja->setSheetState(Worksheet::SHEETSTATE_HIDDEN);
        $hoja->getProtection()->setSheet(true);
        $hoja->getProtection()->setPassword(self::CONTRASENA);
    }

    /**
     * Escribe una celda **como texto explícito**.
     *
     * Sin esto, un `no_matricula` de ceros a la izquierda o un `json` que empiece
     * por `-` se convierten en número o en fórmula, y el espejo deja de poder
     * leerse. Es el mismo motivo por el que el `ID` de la rejilla entra como
     * cadena.
     */
    private function texto(Worksheet $hoja, string $celda, string $valor): void
    {
        $hoja->setCellValueExplicit($celda, $valor, DataType::TYPE_STRING);
    }
}
