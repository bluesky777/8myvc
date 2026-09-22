<?php

namespace App\Exports;

use App\Support\RepartoDeLaNota;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Style\Conditional;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Protection;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Una hoja de asignatura del libro «notas sin internet»: la rejilla que el
 * docente rellena.
 *
 * La estructura es la §4.2 del plan (`myvc_front/PLAN-NOTAS-SIN-INTERNET.md`)
 * **con las correcciones D11 y D12, que son posteriores al texto de esa sección y
 * mandan sobre él**:
 *
 *   - **D11, dos filas de cabecera y no cinco.** El enlace en `A1`, las bandas de
 *     unidad desde `D1`, el título en `A2:B2`, y el número del indicador **con su
 *     peso dentro de la misma celda**. Se van los rótulos «Nº», «Alumno» y
 *     «NOTAS», que ocupaban una fila entera para decir lo que ya se ve. `Def`,
 *     `Aus` y `Tar` van en la fila 2 con los indicadores. Panel congelado en `D3`.
 *   - **D12, columnas de reserva.** Cada una de las tres primeras unidades ocupa
 *     {@see RESERVA_PRIMERAS} columnas y de la cuarta en adelante
 *     {@see RESERVA_RESTO}, **existan o no los indicadores**.
 *
 * ## Por qué la reserva no es un capricho de formato
 *
 * El caso que resuelve es el que pidió Joseth: el docente está sin internet, hace
 * una actividad que no tenía planeada y **necesita una columna donde ponerla**.
 * Sin reserva, la única salida es insertar una columna en una hoja protegida —que
 * desalinea la cabecera y rompe el mapa de la hoja `_myvc`— o no anotarla.
 *
 * La reservada **lleva su número y ningún porcentaje**, que es lo que la
 * distingue a simple vista de una que existe, y va con fondo ámbar. La
 * numeración corre seguida incluyendo las de reserva, que es la salida (b) de la
 * §9.6 del plan: **el Indicador 6 del Excel puede ser el Indicador 4 de la web**,
 * y está asumido — el mock dibuja ésa porque es lo que se pidió.
 *
 * ## Las tres cosas que esta hoja NO hace
 *
 * 1. **No escribe nada en la base.** Ni siembra filas de `notas` ni recalcula
 *    definitivas. La `Def` de aquí es una **fórmula de Excel**, no un número
 *    guardado.
 * 2. **No numera como la pantalla.** Ver arriba: es una decisión, no un olvido.
 * 3. **No normaliza los porcentajes.** Hay asignaturas cuyas unidades suman 200 a
 *    propósito, para que una configuración mala se delate en la planilla. El libro
 *    los enseña crudos y la portada lo avisa (§3.5 del plan).
 */
final class HojaDeAsignatura
{
    /** Columnas de nota que se reservan a cada una de las tres primeras unidades (D12). */
    public const RESERVA_PRIMERAS = 5;

    /** Y a la cuarta en adelante (D12). */
    public const RESERVA_RESTO = 3;

    /** Cuántas unidades se consideran «las primeras» a efectos de reserva (D12). */
    public const UNIDADES_ANCHAS = 3;

    /**
     * Cuántas filas en blanco para alumnos que no aparecen en la lista.
     *
     * **Tres y no treinta**, y el número es el argumento: treinta filas vacías
     * invitan a pegar una lista entera, y esto **no crea a nadie**. Tres dicen que
     * es la excepción (§4.2 del plan).
     */
    public const FILAS_NUEVAS = 3;

    private const FILA_BANDA = 1;

    private const FILA_CABECERA = 2;

    private const FILA_PRIMER_ALUMNO = 3;

    private const COL_ORDEN = 1;      // A

    private const COL_NOMBRE = 2;     // B

    private const COL_ID = 3;         // C

    private const COL_PRIMERA_NOTA = 4; // D

    /**
     * El gris de lo que el docente no usa: el número de orden y el ID.
     *
     * Van en 8 pt y en gris **a propósito, y no por decoración**: son las dos
     * columnas que el sistema necesita y la persona no, así que tienen que verse
     * lo justo para no borrarse sin querer y no competir por la atención con la
     * casilla donde se escribe.
     */
    private const GRIS = 'FF9A9A9A';

    /** El ámbar de lo que está vacío a propósito: reservas y filas de alumnos nuevos. */
    private const AMBAR = 'FFFFF1D6';

    /**
     * Las bandas de la escala, de menor a mayor, para las que no son `perdido`.
     *
     * Son **tonos claros** y se ciclan si un colegio tiene más de tres bandas
     * aprobatorias. La banda `perdido` no sale de aquí: lleva su rojo y **además
     * negrita**, porque la planilla tiene que poder leerse impresa en blanco y
     * negro y un color solo no sobrevive a una fotocopia (§4.5 del plan).
     */
    private const TONOS_APROBADOS = ['FFFFF7DB', 'FFE8F3FF', 'FFE3F7E6'];

    private const TONO_PERDIDO = 'FFFFE0E0';

    private const TEXTO_PERDIDO = 'FF9F1616';

    /** @var list<array{columna:int, numero:int, subunidad:?object, unidad:object}> */
    private array $columnas = [];

    /** @var list<array{unidad:object, desde:int, hasta:int, numero:int}> */
    private array $bandas = [];

    /**
     * @param  object  $planilla  lo que devuelve `LaPlanillaQueSeDescarga::planilla`
     * @param  object  $anio  lo que devuelve `LaPlanillaQueSeDescarga::cabeceraDelAnio`
     * @param  object  $periodo  `{id, numero, abierto}`
     * @param  list<object>  $escalas  las bandas del año
     */
    public function __construct(
        private object $planilla,
        private object $anio,
        private object $periodo,
        private array $escalas,
        private string $colorDeMarca,
    ) {
        $this->repartirColumnas();
    }

    /**
     * Pinta la hoja y devuelve **el mapa que va a la hoja oculta `_myvc`**.
     *
     * Devolver el mapa desde aquí y no reconstruirlo fuera es lo único que
     * garantiza que el mapa describa la hoja que de verdad se pintó: son las
     * mismas variables, en el mismo recorrido. Un mapa que se calcule aparte
     * empieza correcto y se desincroniza en el primer cambio de formato, y lo
     * hace **en silencio** — la firma cuadraría y el espejo apuntaría a otra
     * columna.
     *
     * @return array<string, mixed>
     */
    public function pintar(Worksheet $hoja, string $portada): array
    {
        $this->enlaceALaPortada($hoja, $portada);
        $this->titulo($hoja);
        $this->bandasDeUnidad($hoja);
        $this->cabecerasDeIndicador($hoja);

        $filas = $this->alumnos($hoja);
        $nuevas = $this->bloqueDeAlumnosNuevos($hoja, $filas['ultima']);

        $this->anchos($hoja);
        $this->validaciones($hoja, $filas['primera'], $nuevas['ultima']);
        $this->coloresDeLaEscala($hoja, $filas['primera'], $nuevas['ultima']);
        $this->proteger($hoja, $filas['primera'], $filas['ultima'], $nuevas['primera'], $nuevas['ultima']);

        // El panel congelado en `D3`: el nombre y el ID se ven siempre, y la
        // cabecera también. En una hoja de 31 alumnos y 19 indicadores es la
        // diferencia entre una planilla y un laberinto (§4.2).
        $hoja->freezePane('D'.self::FILA_PRIMER_ALUMNO);
        $hoja->setSelectedCell('D'.self::FILA_PRIMER_ALUMNO);

        return $this->mapa($hoja->getTitle(), $filas['por_alumno']);
    }

    /** Cuántas hojas de cálculo ocupa: una. Existe para leerse en el llamante. */
    public function asignatura(): object
    {
        return $this->planilla->asignatura;
    }

    /**
     * El reparto de columnas, que es **la única pieza de la que cuelga todo lo
     * demás**: la banda fusionada, la cabecera, la validación, el color y el mapa
     * de `_myvc` se calculan de aquí y no cada uno por su cuenta.
     *
     * La numeración corre seguida e **incluye las reservadas** (D12).
     */
    private function repartirColumnas(): void
    {
        $columna = self::COL_PRIMERA_NOTA;
        $numero = 0;
        $indice = 0;

        foreach ($this->planilla->unidades as $unidad) {
            $indice++;
            $reserva = $indice <= self::UNIDADES_ANCHAS ? self::RESERVA_PRIMERAS : self::RESERVA_RESTO;

            // `max`: la reserva es un **suelo**, no un techo. Una unidad con siete
            // indicadores de verdad ocupa siete columnas; recortarla a cinco dejaría
            // dos indicadores existentes fuera del libro, que es perder trabajo hecho.
            $cuantas = max($reserva, count($unidad->subunidades));

            $desde = $columna;

            for ($i = 0; $i < $cuantas; $i++) {
                $subunidad = $unidad->subunidades[$i] ?? null;

                $this->columnas[] = [
                    'columna' => $columna,
                    'numero' => ++$numero,
                    'subunidad' => $subunidad,
                    'unidad' => $unidad,
                ];

                $columna++;
            }

            $this->bandas[] = [
                'unidad' => $unidad,
                'desde' => $desde,
                'hasta' => $columna - 1,
                'numero' => $indice,
            ];
        }
    }

    /**
     * Lo que pesa **una columna** dentro de su unidad, como fracción de 1. Es el
     * factor con el que la `Def` orientativa multiplica esa casilla.
     *
     * En `promedio` vale `1/n` con `n` las subunidades **vivas** de la unidad, y
     * `subunidades.porcentaje` se ignora: es la §3.5 del plan y la regla que vive
     * en `RepartoDeLaNota`. Se calcula en PHP y no en SQL porque lo que se está
     * construyendo es una **fórmula de Excel**, no una consulta — pero el
     * denominador es el mismo `n` que cuenta el backend: las subunidades vivas que
     * trajo la consulta, que es exactamente lo que cuenta
     * `RepartoDeLaNota::cuantasSubunidades` con su `deleted_at IS NULL`.
     *
     * En `porcentaje` sale de `subunidades.porcentaje`, **sin normalizar**. Si la
     * unidad no suma 100, la `Def` del libro sale distinta de 100 y eso es lo
     * correcto: el libro enseña el reparto crudo y la portada avisa.
     *
     * Una columna **reservada** pesa 0: todavía no existe, así que no puede
     * aportar. El día que el docente escriba en ella y la fase 2 cree el
     * indicador, el peso se decide entonces — y en modo `porcentaje` eso cambia
     * notas ya guardadas, que es la §9.7 del plan.
     */
    private function pesoDeColumna(array $columna): float
    {
        if ($columna['subunidad'] === null) {
            return 0.0;
        }

        if ($this->anio->reparto === RepartoDeLaNota::PROMEDIO) {
            $cuantas = count($columna['unidad']->subunidades);

            return $cuantas > 0 ? 1.0 / $cuantas : 0.0;
        }

        return ((int) $columna['subunidad']->porcentaje) / 100;
    }

    /**
     * `A1`, el enlace de vuelta.
     *
     * **Hipervínculo interno y no macro.** Un `.xlsm` con VBA lo bloquea Excel por
     * defecto, no lo abre Google Sheets y lo marca media docena de antivirus
     * (§4.1). Esto es la otra mitad del menú de la portada.
     */
    private function enlaceALaPortada(Worksheet $hoja, string $portada): void
    {
        $hoja->setCellValue('A'.self::FILA_BANDA, '← Volver a la portada');
        $hoja->getCell('A'.self::FILA_BANDA)->getHyperlink()->setUrl("sheet://'".$portada."'!A1");
        $hoja->getStyle('A'.self::FILA_BANDA)->getFont()
            ->setUnderline(true)->setSize(9)->getColor()->setARGB($this->colorDeMarca);
    }

    /** `A2:B2` con el título de la hoja, y `C2` con el rótulo del ID. */
    private function titulo(Worksheet $hoja): void
    {
        $hoja->mergeCells('A'.self::FILA_CABECERA.':B'.self::FILA_CABECERA);
        $hoja->setCellValue('A'.self::FILA_CABECERA, $this->rotuloDeLaHoja());
        $hoja->getStyle('A'.self::FILA_CABECERA)->getFont()->setBold(true)->setSize(11);
        $hoja->getStyle('A'.self::FILA_CABECERA)->getAlignment()
            ->setVertical(Alignment::VERTICAL_CENTER);

        // `ID` y no «Matrícula»: lo que lleva la columna es `no_matricula` **o** el
        // id del alumno cuando aquél está vacío, así que un rótulo que prometa
        // matrícula sería falso en las filas que más se miran — las de los alumnos
        // recién matriculados, que son justo los que todavía no tienen número.
        $hoja->setCellValue('C'.self::FILA_CABECERA, 'ID');
        $hoja->getStyle('C'.self::FILA_CABECERA)->getFont()->setSize(8)->getColor()->setARGB(self::GRIS);
        $hoja->getStyle('C'.self::FILA_CABECERA)->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER)
            ->setVertical(Alignment::VERTICAL_BOTTOM);
    }

    /** «3° B · MATEMÁTICAS · P3». La materia en mayúsculas, que es como se lee de un vistazo. */
    private function rotuloDeLaHoja(): string
    {
        $asignatura = $this->planilla->asignatura;
        $grupo = $asignatura->abrev_grupo !== null && trim((string) $asignatura->abrev_grupo) !== ''
            ? (string) $asignatura->abrev_grupo
            : $asignatura->nombre_grupo;

        $materia = $asignatura->alias_materia !== null && trim((string) $asignatura->alias_materia) !== ''
            ? (string) $asignatura->alias_materia
            : $asignatura->materia;

        return $grupo.' · '.mb_strtoupper($materia, 'UTF-8').' · P'.$this->periodo->numero;
    }

    /**
     * Fila 1, desde `D1`: la banda de cada unidad, fusionada sobre sus columnas.
     *
     * El texto va **corto** —«1 · Números racion… 30%»— porque una banda de cinco
     * columnas estrechas no da para más, y el título entero va en el **comentario**
     * de la celda. Es el tercer sitio del §4.4 del plan, el que nació con la D11:
     * sin el comentario, el título de la unidad no se puede leer entero en ninguna
     * parte del libro.
     */
    private function bandasDeUnidad(Worksheet $hoja): void
    {
        foreach ($this->bandas as $banda) {
            $desde = Coordinate::stringFromColumnIndex($banda['desde']).self::FILA_BANDA;
            $hasta = Coordinate::stringFromColumnIndex($banda['hasta']).self::FILA_BANDA;

            if ($banda['desde'] !== $banda['hasta']) {
                $hoja->mergeCells($desde.':'.$hasta);
            }

            $definicion = trim((string) ($banda['unidad']->definicion ?? ''));
            $corto = $this->recortar($definicion, max(12, ($banda['hasta'] - $banda['desde'] + 1) * 7));

            $hoja->setCellValue($desde, $banda['numero'].' · '.$corto.'  '.$banda['unidad']->porcentaje.'%');

            $hoja->getStyle($desde.':'.$hasta)->applyFromArray([
                'font' => ['bold' => true, 'size' => 9, 'color' => ['argb' => 'FFFFFFFF']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $this->colorDeMarca]],
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_CENTER,
                    'vertical' => Alignment::VERTICAL_CENTER,
                ],
            ]);

            $entero = $definicion === ''
                ? '('.$this->anio->unidad_displayname.' sin descripción)'
                : $definicion;

            $this->comentario(
                $hoja,
                $desde,
                $this->anio->unidad_displayname.' '.$banda['numero'].': '.$entero
                    ."\nPesa el ".$banda['unidad']->porcentaje.'% del periodo.'
            );
        }
    }

    /**
     * Fila 2, desde `D2`: **el número y el peso en la misma celda** (D11).
     *
     * Dos líneas dentro de una celda —`1.` en negrita y `20%` debajo en 8 pt
     * gris— en vez de las dos filas que había en la §4.2. No es sólo ahorrar una
     * fila: **es que el número y su peso dejan de poder desalinearse**, que es el
     * fallo callado de una cabecera de dos filas cuando alguien inserta una
     * columna.
     *
     * En modo `promedio` (§3.5) **no se imprime un porcentaje**: pone `prom.`, que
     * es lo que la §4.2 pedía para esa fila. Un «20 %» impreso en un colegio en
     * modo promedio es un número que no gobierna nada, y eso es peor que ninguno
     * porque es creíble.
     */
    private function cabecerasDeIndicador(Worksheet $hoja): void
    {
        foreach ($this->columnas as $columna) {
            $celda = Coordinate::stringFromColumnIndex($columna['columna']).self::FILA_CABECERA;

            $texto = new RichText;
            $numero = $texto->createTextRun((string) $columna['numero'].'.');
            $numero->getFont()->setBold(true)->setSize(10);

            $abajo = $this->rotuloDelPeso($columna);

            if ($abajo !== null) {
                $texto->createText("\n");
                $peso = $texto->createTextRun($abajo);
                $peso->getFont()->setSize(8)->setColor(new Color(self::GRIS));
            }

            $hoja->setCellValue($celda, $texto);

            $hoja->getStyle($celda)->applyFromArray([
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_CENTER,
                    'vertical' => Alignment::VERTICAL_CENTER,
                    'wrapText' => true,
                ],
                'borders' => ['bottom' => ['borderStyle' => Border::BORDER_THIN]],
            ]);

            if ($columna['subunidad'] === null) {
                $hoja->getStyle($celda)->getFill()
                    ->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::AMBAR);
            }

            $this->comentario($hoja, $celda, $this->textoDelComentario($columna));
        }

        $hoja->getRowDimension(self::FILA_CABECERA)->setRowHeight(30);

        $this->tresFinales($hoja);
    }

    /** El renglón de debajo del número: el peso, `prom.`, o nada si es reservada. */
    private function rotuloDelPeso(array $columna): ?string
    {
        // **La reservada lleva su número y ningún porcentaje** (D12). Es lo que la
        // distingue de un indicador que existe sin tener que leer el comentario.
        if ($columna['subunidad'] === null) {
            return null;
        }

        if ($this->anio->reparto === RepartoDeLaNota::PROMEDIO) {
            return 'prom.';
        }

        return ((int) $columna['subunidad']->porcentaje).'%';
    }

    /**
     * El comentario de la cabecera de un indicador.
     *
     * **Si la definición está vacía dice «(sin descripción)», no desaparece.** Una
     * celda con comentario y otra sin él parecen dos cosas distintas cuando lo
     * único que falta es un dato (§4.4 del plan).
     *
     * Y el de una columna **reservada** dice qué va a pasar si se escribe en ella,
     * porque el aviso tiene que llegar cuando el docente está a punto de escribir
     * y no en un manual que nadie abre.
     */
    private function textoDelComentario(array $columna): string
    {
        $nombre = $this->anio->subunidad_displayname;

        if ($columna['subunidad'] === null) {
            return $nombre.' '.$columna['numero'].": esta columna está reservada.\n"
                .'Todavía no existe; si escribe aquí se le preguntará cómo se llama y cuánto pesa '
                .'cuando suba la planilla.';
        }

        $definicion = trim((string) ($columna['subunidad']->definicion ?? ''));

        if ($definicion === '') {
            $definicion = '(sin descripción)';
        }

        $peso = $this->anio->reparto === RepartoDeLaNota::PROMEDIO
            ? 'Todos los '.mb_strtolower($this->anio->subunidades_displayname, 'UTF-8')
                .' de este '.mb_strtolower($this->anio->unidad_displayname, 'UTF-8').' pesan igual.'
            : 'Pesa el '.((int) $columna['subunidad']->porcentaje).'% de '
                .mb_strtolower($this->anio->unidad_displayname, 'UTF-8').' '.$this->unidadDeLaColumna($columna).'.';

        return $nombre.' '.$columna['numero'].': '.$definicion."\n".$peso;
    }

    private function unidadDeLaColumna(array $columna): int
    {
        foreach ($this->bandas as $banda) {
            if ($banda['unidad'] === $columna['unidad']) {
                return $banda['numero'];
            }
        }

        return 0;
    }

    /**
     * `Def`, `Aus` y `Tar`, **en la fila 2 y al final** (D11 y §4.2).
     *
     * Van al final a propósito: `Aus` y `Tar` son del periodo entero, no de una
     * unidad, y metidas entre los indicadores se leerían como uno más.
     */
    private function tresFinales(Worksheet $hoja): void
    {
        $columna = $this->primeraDeLasTres();

        $rotulos = [
            'Def' => 'La definitiva que sale de lo que hay escrito en esta fila. Es ORIENTATIVA y no se '
                ."sube nunca: la de verdad la calcula el servidor.\nEstá bloqueada.",
            // **El comentario dice lo que cuesta, no sólo lo que hace.** Un total que
            // sube crea faltas fechadas el día de la importación —no el día que el
            // alumno faltó—, y uno que baja BORRA filas con sus fechas, que son las que
            // leen las planillas de ausencias de los acudientes. Quien vaya a escribir
            // aquí tiene que saberlo antes de escribir, no después de subir.
            'Aus' => "Ausencias del periodo en esta asignatura.\nSubir este total crea faltas con la fecha "
                ."del día en que se importe, no la del día que faltó.\nBajarlo borra faltas y se pregunta "
                .'aparte antes de hacerlo.',
            'Tar' => "Tardanzas del periodo en esta asignatura.\nSubir este total crea tardanzas con la fecha "
                ."del día en que se importe, no la del día que llegó tarde.\nBajarlo borra tardanzas y se "
                .'pregunta aparte antes de hacerlo.',
        ];

        foreach (array_keys($rotulos) as $i => $rotulo) {
            $celda = Coordinate::stringFromColumnIndex($columna + $i).self::FILA_CABECERA;

            $hoja->setCellValue($celda, $rotulo);
            $hoja->getStyle($celda)->applyFromArray([
                'font' => ['bold' => true, 'size' => 9],
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_CENTER,
                    'vertical' => Alignment::VERTICAL_CENTER,
                ],
                'borders' => ['bottom' => ['borderStyle' => Border::BORDER_THIN]],
            ]);

            $this->comentario($hoja, $celda, $rotulos[$rotulo]);
        }
    }

    /** El índice de la columna `Def`. Las otras dos van detrás. */
    private function primeraDeLasTres(): int
    {
        if ($this->columnas === []) {
            return self::COL_PRIMERA_NOTA;
        }

        return end($this->columnas)['columna'] + 1;
    }

    /**
     * Fila 3 en adelante: un alumno por fila.
     *
     * @return array{primera:int, ultima:int, por_alumno:array<int,int>}
     */
    private function alumnos(Worksheet $hoja): array
    {
        $fila = self::FILA_PRIMER_ALUMNO;
        $porAlumno = [];
        $orden = 0;

        foreach ($this->planilla->alumnos as $alumno) {
            $orden++;
            $porAlumno[$fila] = $alumno->alumno_id;

            $hoja->setCellValue('A'.$fila, $orden);
            $hoja->setCellValue('B'.$fila, trim($alumno->apellidos.', '.$alumno->nombres, ' ,'));

            // **El ID entra como TEXTO.** Hay colegios cuyo `no_matricula` lleva
            // ceros a la izquierda o letras, y Excel se come los ceros del que
            // parece número. Un `01055` convertido en `1055` es una fila que al
            // subirla no casa con nadie, y el docente no puede ni verlo.
            $hoja->setCellValueExplicit(
                'C'.$fila,
                $alumno->identificador,
                DataType::TYPE_STRING
            );

            foreach ($this->columnas as $columna) {
                if ($columna['subunidad'] === null) {
                    continue;
                }

                $nota = $this->planilla->notas[$alumno->alumno_id][$columna['subunidad']->id] ?? null;

                // **La D2: el libro se descarga siempre CON lo que ya esté puesto.**
                // Y `null` se queda vacío, que no es un cero: desde
                // `2026_09_19_500000_la_casilla_vacia` una casilla sin calificar no
                // vale cero, y escribir un 0 aquí le regalaría al alumno una nota que
                // nadie puso.
                if ($nota !== null) {
                    $hoja->setCellValue(Coordinate::stringFromColumnIndex($columna['columna']).$fila, $nota);
                }
            }

            $this->definitivaOrientativa($hoja, $fila);

            $asistencia = $this->planilla->asistencia[$alumno->alumno_id] ?? null;
            $tres = $this->primeraDeLasTres();
            $hoja->setCellValue(Coordinate::stringFromColumnIndex($tres + 1).$fila, $asistencia->ausencias ?? 0);
            $hoja->setCellValue(Coordinate::stringFromColumnIndex($tres + 2).$fila, $asistencia->tardanzas ?? 0);

            $fila++;
        }

        $ultima = $fila - 1;

        if ($ultima >= self::FILA_PRIMER_ALUMNO) {
            $this->estiloDeLaRejilla($hoja, self::FILA_PRIMER_ALUMNO, $ultima);
        }

        return ['primera' => self::FILA_PRIMER_ALUMNO, 'ultima' => $ultima, 'por_alumno' => $porAlumno];
    }

    /**
     * La columna `Def`: **una fórmula bloqueada y orientativa**.
     *
     * Reconstruye la definitiva por el camino del cliente —`Σ (nota × peso_ind ×
     * %unidad)`— mientras el servidor la calcula por el suyo —`(Σ nota)/n ×
     * %unidad`—. Matemáticamente son la misma expresión y **en coma flotante
     * pueden diferir en el último bit**; el aviso entero está en el docblock de
     * `RepartoDeLaNota`. Por eso esta columna **no se importa nunca**: es para que
     * el docente vea por dónde va.
     *
     * **`SUMPRODUCT` y no una suma de productos**, y no es estilo: el docente puede
     * escribir un guion en una casilla para borrar la nota (D9), y `D3*0,2` con un
     * guion dentro da `#¡VALOR!` y **contagia el error a toda la fila**.
     * `SUMPRODUCT` trata lo que no es número como cero, así que la `Def` sigue
     * diciendo algo mientras el docente teclea.
     */
    private function definitivaOrientativa(Worksheet $hoja, int $fila): void
    {
        $trozos = [];

        foreach ($this->bandas as $banda) {
            $desde = Coordinate::stringFromColumnIndex($banda['desde']).$fila;
            $hasta = Coordinate::stringFromColumnIndex($banda['hasta']).$fila;

            $pesos = [];

            foreach ($this->columnas as $columna) {
                if ($columna['columna'] < $banda['desde'] || $columna['columna'] > $banda['hasta']) {
                    continue;
                }

                $pesos[] = rtrim(rtrim(number_format($this->pesoDeColumna($columna), 6, '.', ''), '0'), '.') ?: '0';
            }

            if ($pesos === []) {
                continue;
            }

            $porcentaje = ((int) $banda['unidad']->porcentaje) / 100;

            $trozos[] = 'SUMPRODUCT('.$desde.':'.$hasta.',{'.implode(',', $pesos).'})*'
                .rtrim(rtrim(number_format($porcentaje, 4, '.', ''), '0'), '.');
        }

        if ($trozos === []) {
            return;
        }

        $hoja->setCellValue(
            Coordinate::stringFromColumnIndex($this->primeraDeLasTres()).$fila,
            '=ROUND('.implode('+', $trozos).',2)'
        );
    }

    private function estiloDeLaRejilla(Worksheet $hoja, int $primera, int $ultima): void
    {
        $ultimaColumna = Coordinate::stringFromColumnIndex($this->primeraDeLasTres() + 2);

        // Las dos columnas que el docente no usa, en 8 pt y gris: se ven lo justo
        // para no borrarse sin querer.
        foreach (['A', 'C'] as $columna) {
            $hoja->getStyle($columna.$primera.':'.$columna.$ultima)->applyFromArray([
                'font' => ['size' => 8, 'color' => ['argb' => self::GRIS]],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            ]);
        }

        $hoja->getStyle('B'.$primera.':B'.$ultima)->getFont()->setSize(10);

        $hoja->getStyle(
            Coordinate::stringFromColumnIndex(self::COL_PRIMERA_NOTA).$primera.':'.$ultimaColumna.$ultima
        )->applyFromArray([
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_HAIR, 'color' => ['argb' => 'FFD9D9D9']]],
        ]);

        $def = Coordinate::stringFromColumnIndex($this->primeraDeLasTres());
        $hoja->getStyle($def.$primera.':'.$def.$ultima)->getFont()->setBold(true);
    }

    /**
     * Debajo del último alumno: el separador, el rótulo, la frase y las tres filas.
     *
     * La frase va **en la propia hoja** y no en un manual: el aviso llega cuando el
     * docente está a punto de escribir. Dice literalmente que no se crea a nadie,
     * porque ésa es la regla del encargo —*«no debe crear el alumno»*— y la que
     * más sorprende si se descubre al subir.
     *
     * @return array{primera:int, ultima:int}
     */
    private function bloqueDeAlumnosNuevos(Worksheet $hoja, int $ultimaDeAlumnos): array
    {
        $separador = $ultimaDeAlumnos + 1;
        $rotulo = $separador + 1;
        $frase = $rotulo + 1;
        $primera = $frase + 1;
        $ultima = $primera + self::FILAS_NUEVAS - 1;

        $ancho = Coordinate::stringFromColumnIndex($this->primeraDeLasTres() + 2);

        $hoja->getStyle('A'.$separador.':'.$ancho.$separador)->applyFromArray([
            'borders' => ['bottom' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['argb' => self::GRIS]]],
        ]);
        $hoja->getRowDimension($separador)->setRowHeight(6);

        $hoja->setCellValue('A'.$rotulo, 'ALUMNOS QUE NO APARECEN EN LA LISTA · no se crea a nadie');
        $hoja->getStyle('A'.$rotulo)->getFont()->setBold(true)->setSize(9);

        $grupo = $this->planilla->asignatura->abrev_grupo ?: $this->planilla->asignatura->nombre_grupo;

        $hoja->setCellValue(
            'A'.$frase,
            'Escriba el nombre completo en la columna del nombre. Al subir la planilla, el sistema lo '
            .'buscará en '.$grupo.' y le preguntará si es el correcto. Si no está matriculado ahí, la fila '
            .'no se importa: matricularlo es cosa de secretaría.'
        );
        $hoja->getStyle('A'.$frase)->getFont()->setSize(8)->getColor()->setARGB(self::GRIS);

        for ($fila = $primera; $fila <= $ultima; $fila++) {
            $hoja->getStyle('A'.$fila.':C'.$fila)->applyFromArray([
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => self::AMBAR]],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_HAIR, 'color' => ['argb' => self::GRIS]]],
            ]);

            $hoja->getStyle(
                Coordinate::stringFromColumnIndex(self::COL_PRIMERA_NOTA).$fila.':'.$ancho.$fila
            )->applyFromArray([
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => self::AMBAR]],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_HAIR, 'color' => ['argb' => self::GRIS]]],
            ]);
        }

        return ['primera' => $primera, 'ultima' => $ultima];
    }

    /**
     * Los anchos. `A` y `C` **muy estrechas** a propósito (§4.2 con la D11).
     */
    private function anchos(Worksheet $hoja): void
    {
        $hoja->getColumnDimension('A')->setWidth(3.5);
        $hoja->getColumnDimension('B')->setWidth(34);
        $hoja->getColumnDimension('C')->setWidth(6);

        foreach ($this->columnas as $columna) {
            $hoja->getColumnDimension(Coordinate::stringFromColumnIndex($columna['columna']))->setWidth(6);
        }

        $tres = $this->primeraDeLasTres();

        $hoja->getColumnDimension(Coordinate::stringFromColumnIndex($tres))->setWidth(7);
        $hoja->getColumnDimension(Coordinate::stringFromColumnIndex($tres + 1))->setWidth(5);
        $hoja->getColumnDimension(Coordinate::stringFromColumnIndex($tres + 2))->setWidth(5);
    }

    /**
     * La validación de las casillas de nota: **número entero**, dentro de la escala.
     *
     * ## Por qué es `custom` y no «entero entre 0 y 50», que sería lo obvio
     *
     * Porque las dos reglas del libro **chocan**: la §3.1 quiere entero —`notas.nota`
     * es `int` y un `4,5` tecleado se guardaría como `4` sin error y sin aviso, que
     * es la peor forma de perder una nota— y la **D9** quiere que un guion `-`
     * borre la nota. Una validación de tipo entero con alerta de parada **rechaza el
     * guion**, o sea que la D9 no se podría usar; y bajarla a aviso deja pasar el
     * `4,5` con un clic.
     *
     * La fórmula acepta las dos cosas y **nada más**, así que la alerta puede seguir
     * siendo de parada:
     *
     *     IF(D3="-", TRUE, AND(ISNUMBER(D3), D3=INT(D3), D3>=min, D3<=max))
     *
     * **`IF` y no `OR`**, y es la diferencia entre que funcione y que no: `OR` evalúa
     * las dos ramas y `INT("-")` da `#¡VALOR!`, que contagia al `OR` entero; `IF`
     * sólo evalúa la rama que toca.
     *
     * **Si el año no tiene escala, la fórmula sale sin tope.** Es la decisión escrita
     * en `EscalaDeNotas`: no se inventa un 100, porque en un colegio de 0 a 50 eso
     * afloja el límite al doble y encima parece una comprobación.
     */
    private function validaciones(Worksheet $hoja, int $primera, int $ultima): void
    {
        if ($this->columnas === [] || $ultima < $primera) {
            return;
        }

        $minimo = $this->anio->escala_minima;
        $maximo = $this->anio->escala_maxima;

        foreach ($this->columnas as $columna) {
            $letra = Coordinate::stringFromColumnIndex($columna['columna']);
            $ancla = $letra.$primera;

            $condiciones = ['ISNUMBER('.$ancla.')', $ancla.'=INT('.$ancla.')'];

            if ($minimo !== null) {
                $condiciones[] = $ancla.'>='.$minimo;
            }

            if ($maximo !== null) {
                $condiciones[] = $ancla.'<='.$maximo;
            }

            $validacion = new DataValidation;
            $validacion->setType(DataValidation::TYPE_CUSTOM);
            $validacion->setErrorStyle(DataValidation::STYLE_STOP);
            $validacion->setAllowBlank(true);
            $validacion->setShowInputMessage(true);
            $validacion->setShowErrorMessage(true);
            $validacion->setFormula1('IF('.$ancla.'="-",TRUE,AND('.implode(',', $condiciones).'))');

            $validacion->setPromptTitle($this->anio->subunidad_displayname.' '.$columna['numero']);
            $validacion->setPrompt($this->mensajeDeEntrada($columna));

            $validacion->setErrorTitle('Esa nota no cabe');
            $validacion->setError($this->mensajeDeError());

            $hoja->setDataValidation($letra.$primera.':'.$letra.$ultima, $validacion);
        }
    }

    /**
     * El mensaje de entrada, que es el otro mecanismo del §4.4 y hace falta
     * **además** del comentario.
     *
     * El comentario sale al pasar el ratón; éste sale al **seleccionar** la casilla,
     * también con el teclado. Sólo el comentario deja fuera a quien pasa la planilla
     * con el tabulador sin tocar el ratón, que es como se pasa una planilla de
     * verdad. Van los dos.
     */
    private function mensajeDeEntrada(array $columna): string
    {
        $rango = $this->rangoEscrito();

        $texto = $this->anio->subunidad_displayname.' '.$columna['numero'].' · número entero'.$rango.'.';

        if ($columna['subunidad'] === null) {
            $texto .= ' Esta columna está reservada: todavía no existe ese '
                .mb_strtolower($this->anio->subunidad_displayname, 'UTF-8').'.';
        }

        return $texto."\nEscriba un guion (-) para borrar la nota. Una casilla vacía se queda como está.";
    }

    private function mensajeDeError(): string
    {
        return 'Sólo se admiten números enteros'.$this->rangoEscrito().', o un guion (-) para borrar la nota. '
            ."\nUna nota con decimales se guardaría recortada, así que el libro no la deja escribir.";
    }

    private function rangoEscrito(): string
    {
        if ($this->anio->escala_maxima === null) {
            return '';
        }

        return ' de '.($this->anio->escala_minima ?? 0).' a '.$this->anio->escala_maxima;
    }

    /**
     * El formato condicional con las bandas de `escalas_de_valoracion` del año.
     *
     * **La regla es `porc_inicial <= nota < porc_final + 1`** (D10), y se escribe
     * así y no como un «entre inicial y final» aunque con notas enteras dé lo
     * mismo: es la regla que eligió Joseth el 13 sep, vive en trece sitios del
     * backend, y una hoja que pintara con otra tendría que volver a discutirse el
     * día que un colegio guarde un decimal.
     *
     * La banda `perdido` va **además en negrita**, no sólo en rojo: una planilla
     * impresa en blanco y negro tiene que seguir diciendo quién perdió.
     *
     * `ISNUMBER` delante de cada condición porque el guion de la D9 es texto, y en
     * Excel una comparación de texto contra un número sale `TRUE` — sin esto, una
     * casilla con `-` se pintaría con la banda más alta.
     */
    private function coloresDeLaEscala(Worksheet $hoja, int $primera, int $ultima): void
    {
        if ($this->escalas === [] || $this->columnas === [] || $ultima < $primera) {
            return;
        }

        $desde = Coordinate::stringFromColumnIndex(self::COL_PRIMERA_NOTA);
        $hasta = Coordinate::stringFromColumnIndex(end($this->columnas)['columna']);
        $ancla = $desde.$primera;

        $reglas = [];
        $aprobados = 0;

        foreach ($this->escalas as $banda) {
            $condicion = new Conditional;
            $condicion->setConditionType(Conditional::CONDITION_EXPRESSION);
            $condicion->setConditions([
                'AND(ISNUMBER('.$ancla.'),'.$ancla.'>='.$banda->porc_inicial.','.$ancla.'<'.($banda->porc_final + 1).')',
            ]);

            if ($banda->perdido) {
                $condicion->getStyle()->getFill()
                    ->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::TONO_PERDIDO);
                $condicion->getStyle()->getFont()->setBold(true)->getColor()->setARGB(self::TEXTO_PERDIDO);
            } else {
                $tono = self::TONOS_APROBADOS[$aprobados % count(self::TONOS_APROBADOS)];
                $aprobados++;
                $condicion->getStyle()->getFill()
                    ->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB($tono);
            }

            $reglas[] = $condicion;
        }

        $hoja->getStyle($desde.$primera.':'.$hasta.$ultima)->setConditionalStyles($reglas);
    }

    /**
     * La protección de la hoja.
     *
     * **La contraseña no es un secreto y no pretende serlo**: la protección de
     * PhpSpreadsheet se salta cambiando la extensión a `.zip`. No está para impedir
     * nada, está para que no se borre el `ID` de un alumno **sin querer** al
     * arrastrar una selección, que es lo que pasa de verdad (§4.3 del plan).
     *
     * La seguridad de verdad está en el servidor: al importar, cada nota se vuelve a
     * autorizar contra «¿es esta asignatura de este docente?» y contra el periodo.
     * Un libro manipulado no puede escribir nada que su dueño no pudiera escribir
     * por la web.
     */
    private function proteger(Worksheet $hoja, int $primera, int $ultima, int $nuevasPrimera, int $nuevasUltima): void
    {
        $hoja->getProtection()->setSheet(true);
        $hoja->getProtection()->setPassword(LibroDeNotas::CONTRASENA);

        /*
         * **Estos interruptores dicen lo CONTRARIO de lo que parecen, y es un fallo
         * de una sola letra.** En OOXML cada atributo de `sheetProtection` es una
         * *prohibición*, no un permiso: `true` significa «esto queda bloqueado
         * cuando la hoja está protegida». PhpSpreadsheet lo copia tal cual —«Sorting
         * is locked when sheet is protected, default true»— así que un
         * `setSort(false)` bien intencionado **permite ordenar**.
         *
         * Escrito al revés, la hoja salía con `sort="0" insertRows="0"` en el XML,
         * o sea con todo abierto, y **nada lo delataba**: el libro se abre igual, la
         * protección se ve puesta y sólo se nota el día que alguien ordena la hoja
         * por nota. Se cazó mirando el `sheetProtection` del `.xlsx` generado, que es
         * la única forma —el píxel en vez del 200.
         *
         * Ordenar, autofiltrar e insertar o borrar filas **rompen el pareado
         * fila↔alumno** que guarda `_myvc`, que es lo que hace posible la
         * comparación de tres puntas de la D3. Van prohibidos, o sea a `true`.
         */
        $hoja->getProtection()->setSort(true);
        $hoja->getProtection()->setAutoFilter(true);
        $hoja->getProtection()->setInsertRows(true);
        $hoja->getProtection()->setInsertColumns(true);
        $hoja->getProtection()->setDeleteRows(true);
        $hoja->getProtection()->setDeleteColumns(true);

        // Y se puede **seleccionar** todo, también lo bloqueado: si no, el docente no
        // puede copiar el nombre de un alumno ni mirar la Def de cerca, y una hoja
        // donde media pantalla no se deja ni tocar se siente rota. «No prohibir
        // seleccionar» es `false`, por lo de arriba.
        $hoja->getProtection()->setSelectLockedCells(false);
        $hoja->getProtection()->setSelectUnlockedCells(false);

        if ($this->columnas === []) {
            $this->desbloquear($hoja, 'A'.$nuevasPrimera.':C'.$nuevasUltima);

            return;
        }

        $desde = Coordinate::stringFromColumnIndex(self::COL_PRIMERA_NOTA);
        $hasta = Coordinate::stringFromColumnIndex(end($this->columnas)['columna']);
        $tres = $this->primeraDeLasTres();

        if ($ultima >= $primera) {
            // Las casillas de nota, y `Aus` y `Tar`. **`Def` no**: es una fórmula
            // orientativa que no se importa nunca, y dejarla escribible invita a
            // corregirla a mano creyendo que eso cambia algo.
            $this->desbloquear($hoja, $desde.$primera.':'.$hasta.$ultima);
            $this->desbloquear(
                $hoja,
                Coordinate::stringFromColumnIndex($tres + 1).$primera.':'
                .Coordinate::stringFromColumnIndex($tres + 2).$ultima
            );
        }

        // El bloque de alumnos nuevos, entero: ahí se escribe el nombre y las notas.
        $this->desbloquear(
            $hoja,
            'A'.$nuevasPrimera.':'.Coordinate::stringFromColumnIndex($tres + 2).$nuevasUltima
        );
    }

    private function desbloquear(Worksheet $hoja, string $rango): void
    {
        $hoja->getStyle($rango)->getProtection()->setLocked(Protection::PROTECTION_UNPROTECTED);
    }

    /**
     * Un comentario de celda con su texto, y con sitio para leerlo.
     *
     * El tamaño se pone a mano porque el de serie de PhpSpreadsheet es una caja
     * diminuta: un indicador de dos renglones sale cortado, que es exactamente lo
     * que este comentario existe para evitar.
     */
    private function comentario(Worksheet $hoja, string $celda, string $texto): void
    {
        $comentario = $hoja->getComment($celda);
        $comentario->setWidth('260pt');
        $comentario->setHeight('80pt');
        $comentario->getText()->createTextRun($texto)->getFont()->setSize(9);
    }

    /** Corta un texto por palabras y le pone puntos suspensivos si no cabe. */
    private function recortar(string $texto, int $largo): string
    {
        if ($texto === '') {
            return '(sin descripción)';
        }

        if (mb_strlen($texto, 'UTF-8') <= $largo) {
            return $texto;
        }

        return rtrim(mb_substr($texto, 0, $largo - 1, 'UTF-8')).'…';
    }

    /**
     * El mapa de esta hoja para `_myvc`: qué columna es qué indicador, qué fila es
     * qué alumno, y **el espejo de las notas tal y como se descargaron**.
     *
     * El espejo es lo que hace posible la D3 —«sólo entra lo que el docente
     * cambió»—. Sin él, el servidor vería un 45 y no sabría si lo escribió hoy o si
     * ya estaba, y el choque de la F7 no se podría definir.
     *
     * ## Y desde la fase 4, también el espejo de `Aus` y `Tar`
     *
     * Los dos conteos de asistencia van en `asistencia`, y hacen falta por lo mismo
     * que el de las notas: sin ellos, «el docente subió las faltas de 2 a 4» no se
     * distingue de «el docente no tocó la columna y alguien anotó dos faltas en la
     * web desde que se bajó el libro». La primera crea dos filas fechadas hoy; la
     * segunda no tiene que hacer nada.
     *
     * **Van dentro del `json` del mapa y no en filas sueltas como el espejo de las
     * notas**, y eso no contradice la razón por la que aquél se partió: lo que se
     * temía allí era el tope de 32.767 caracteres de una celda, y allí el tamaño es
     * *alumnos x indicadores*. Aquí son **dos números por alumno** y el mapa ya
     * lleva la lista entera de alumnos en `filas`, así que esto la acompaña sin
     * cambiarle el orden de magnitud: un grupo de 45 son ~1,8 KB.
     *
     * @param  array<int,int>  $porAlumno  fila => alumno_id
     * @return array<string, mixed>
     */
    private function mapa(string $titulo, array $porAlumno): array
    {
        $columnas = [];
        $reservadas = [];

        foreach ($this->columnas as $columna) {
            $letra = Coordinate::stringFromColumnIndex($columna['columna']);

            if ($columna['subunidad'] === null) {
                $reservadas[$letra] = $columna['numero'];

                continue;
            }

            $columnas[$letra] = $columna['subunidad']->id;
        }

        $espejo = [];

        foreach ($porAlumno as $alumnoId) {
            $fila = [];

            foreach ($this->columnas as $columna) {
                if ($columna['subunidad'] === null) {
                    continue;
                }

                $fila[(string) $columna['subunidad']->id] =
                    $this->planilla->notas[$alumnoId][$columna['subunidad']->id] ?? null;
            }

            $espejo[(string) $alumnoId] = $fila;
        }

        // **Los mismos números que se imprimen en las columnas `Aus` y `Tar`**, y
        // salen de la misma fuente (`LaPlanillaQueSeDescarga::asistenciaDe`) para que
        // no puedan discrepar. Si se contaran aquí por segunda vez, el día que una de
        // las dos consultas cambiara el libro diría una cosa en la celda y otra en su
        // propio espejo — y la D3 se decidiría con la que nadie ve.
        $asistencia = [];

        foreach ($porAlumno as $alumnoId) {
            $suya = $this->planilla->asistencia[$alumnoId] ?? null;

            $asistencia[(string) $alumnoId] = [
                'ausencias' => (int) ($suya->ausencias ?? 0),
                'tardanzas' => (int) ($suya->tardanzas ?? 0),
            ];
        }

        $tres = $this->primeraDeLasTres();

        return [
            'hoja' => $titulo,
            'asignatura_id' => $this->planilla->asignatura->asignatura_id,
            'grupo_id' => $this->planilla->asignatura->grupo_id,
            'periodo_id' => $this->periodo->id,
            'columnas' => $columnas,
            'reservadas' => $reservadas,
            'filas' => array_map('strval', $porAlumno),
            'columna_def' => Coordinate::stringFromColumnIndex($tres),
            'columna_aus' => Coordinate::stringFromColumnIndex($tres + 1),
            'columna_tar' => Coordinate::stringFromColumnIndex($tres + 2),
            'espejo' => $espejo,
            'asistencia' => $asistencia,
        ];
    }
}
