<?php

namespace App\Services;

use App\Exports\HojaDeAsignatura;
use App\Exports\LibroDeNotas;
use App\Support\FirmaDelLibro;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Throwable;

/**
 * El libro de «notas sin internet» que llega de vuelta: **leerlo, y saber de qué
 * fiarse**.
 *
 * Es el reverso exacto de {@see LaPlanillaQueSeDescarga} y de
 * `App\Exports\LibroDeNotas`, y existe por una razón que no es de comodidad:
 * **el ensayo y la importación tienen que leer el archivo de la misma manera**.
 * Dos lectores se separan en el primer cambio de formato —uno entiende el guion
 * y el otro no, uno lee la hoja oculta y el otro la reconstruye— y entonces el
 * plan que se enseñó deja de describir lo que va a pasar sin que nada se ponga
 * rojo. Es la misma lección que el importador de alumnos dejó escrita con
 * `ImporterFixer`: el ensayo usa **el mismo traductor** que la escritura.
 *
 * ## Lo único que esta clase decide: EL PELDAÑO
 *
 * No interpreta ni una celda de nota —eso es {@see EnsayoDeLaPlanilla}— y no
 * escribe nada. Lo que hace es contestar la pregunta de la §4.7 del plan: *¿de
 * qué me puedo fiar en este archivo?*
 *
 * | | Estado | Aquí |
 * |---|---|---|
 * | 1 | `_myvc` intacto, firma buena | `peldano = 1`, `firmaValida = true` |
 * | 2 | `_myvc` intacto, **firma rota** | `peldano = 2`: el mapa se usa, **el espejo no** |
 * | 3 | Sin `_myvc`, con columna `ID` | `peldano = 3` y nada más: es la fase 3 |
 * | 4 | Sin `_myvc` y sin `ID` | `peldano = 4`, lo mismo |
 * | 5 | Nada reconocible | `peldano = 5` con el motivo escrito |
 *
 * **El 2 no es «casi el 1».** Con la firma rota el mapa sigue siendo lo único que
 * dice qué columna es qué indicador, así que se usa igual —no hay alternativa—,
 * pero **el espejo deja de valer**: si alguien pudo tocar la hoja oculta, pudo
 * escribir en el espejo el valor que quisiera, y la comparación de tres puntas de
 * la D3 *seguiría funcionando y mintiendo*. Por eso el peldaño 2 no compara: da
 * **todas** las celdas con valor por cambiadas y pide confirmar la lista.
 *
 * ## Y la sexta pregunta, que no es un peldaño: DE QUÉ VERSIÓN ES EL LIBRO
 *
 * `_myvc!B1` lleva el número de formato, y desde la fase 4 hay dos vivos: el 1 —sin
 * el espejo de la asistencia— y el 2. **Un libro se comprueba con su propio
 * número** ({@see FirmaDelLibro::FORMATOS_QUE_SE_LEEN}), que es lo único que evita
 * que subir la versión mande al peldaño 2 a todos los libros que ya andan por
 * fuera. Y un número que este servidor no conoce **no es firma rota**: es peldaño 5
 * con su motivo y su salida, porque lo que hay delante es un libro de otro
 * despliegue y no un manipulador.
 *
 * ## Por qué PhpSpreadsheet directo y no `Excel::import`
 *
 * El importador de alumnos lee con `maatwebsite/excel` y un `ToArray`, y aquí no
 * sirve por tres cosas que sí hacen falta:
 *
 * 1. **La hoja `_myvc` está oculta**, y lo que hace falta de ella es el texto
 *    crudo de unas celdas concretas, no una tabla.
 * 2. **Una fórmula tiene que poder verse como fórmula.** `=D3*2` en una casilla de
 *    nota es un problema con nombre propio (F4), y el valor calculado que
 *    devolvería un lector normal lo escondería.
 * 3. **La cabecera lleva el peso dentro de la celda** (D11), y es la única fuente
 *    que dice cuánto pesaba el indicador **el día de la descarga**.
 *
 * `setReadDataOnly(true)` a propósito: sin estilos el libro de quince asignaturas
 * cabe holgado en memoria, y lo aplana todo a escalares y cadenas —la cabecera
 * `RichText` se lee como `"1.\n33%"`, que es justo lo que hace falta—.
 */
class LaPlanillaQueSeSube
{
    /** La fila de las cabeceras de indicador, con el número y el peso (D11). */
    public const FILA_CABECERA = 2;

    /** Dónde empieza la rejilla de alumnos. */
    public const FILA_PRIMER_ALUMNO = 3;

    private ?Spreadsheet $libro = null;

    /** El peldaño de la §4.7. 1 y 2 se trabajan; 3, 4 y 5 sólo se declaran. */
    public int $peldano = 5;

    public bool $firmaValida = false;

    /** El número de formato que el propio libro dice traer, o `null`. */
    public ?int $versionFormato = null;

    /** La cabecera de `_myvc`: colegio, año, periodo, profesor, generado. */
    public ?array $cabecera = null;

    /**
     * El mapa de cada hoja, en el orden en que `_myvc` los escribió.
     *
     * **El orden es dato y no adorno**: la firma se calcula sobre una lista, así
     * que reordenarlos rompería la comprobación de un libro perfectamente correcto.
     *
     * @var list<array<string, mixed>>
     */
    public array $mapas = [];

    /** Por qué no se pudo leer, cuando el peldaño es 5. */
    public ?string $motivo = null;

    /** Los nombres de las pestañas, tal y como vienen. @var list<string> */
    public array $pestanas = [];

    /**
     * Abre el archivo y decide el peldaño. **No lanza**: un archivo ilegible es un
     * peldaño 5 con su motivo, no un 500.
     *
     * Es la misma regla que `postEnsayo` del importador de alumnos aprendió a la
     * fuerza: un 500 llega al navegador sin cabeceras de CORS, así que la pantalla
     * no lo distingue de un fichero ilegible y **pierde el motivo por el camino**.
     * Aquí el motivo es justo lo que el peldaño 5 tiene que poder decir para
     * ofrecer «descargar el libro correcto».
     */
    public static function abrir(string $ruta): self
    {
        $suyo = new self;

        try {
            $lector = IOFactory::createReaderForFile($ruta);
            $lector->setReadDataOnly(true);
            $suyo->libro = $lector->load($ruta);
        } catch (Throwable $e) {
            $suyo->motivo = 'No se pudo abrir el archivo como libro de Excel: '
                .class_basename($e).': '.$e->getMessage();

            return $suyo;
        }

        $suyo->pestanas = $suyo->libro->getSheetNames();
        $suyo->clasificar();

        return $suyo;
    }

    /** El libro abierto, para quien tenga que leer celdas. */
    public function libro(): ?Spreadsheet
    {
        return $this->libro;
    }

    /**
     * Suelta la memoria de PhpSpreadsheet.
     *
     * **A mano y no esperando al recolector**, que es lo que ya hace la descarga:
     * el objeto de un libro grande deja referencias cruzadas que no se resuelven
     * solas, y aquí además hay una petición que sigue trabajando después de leer.
     */
    public function cerrar(): void
    {
        $this->libro?->disconnectWorksheets();
        $this->libro = null;
    }

    /**
     * El valor **crudo** de una celda de una hoja de asignatura.
     *
     * Crudo quiere decir lo que hay escrito: un `int`, un `float`, una cadena o la
     * **fórmula** con su `=` delante. Interpretarlo es de {@see EnsayoDeLaPlanilla},
     * y tiene que ser de un solo sitio: la diferencia entre `4,5` y `4` la decide
     * una regla del plan (§3.1), no el lector.
     */
    public function celda(string $hoja, string $coordenada): mixed
    {
        $pestana = $this->libro?->getSheetByName($hoja);

        if ($pestana === null) {
            return null;
        }

        return $pestana->getCell($coordenada)->getValue();
    }

    /** Si la pestaña que el mapa nombra sigue estando en el archivo. */
    public function tieneLaPestana(string $hoja): bool
    {
        return $this->libro?->getSheetByName($hoja) !== null;
    }

    /**
     * El peso que **el archivo** dice que tenía esa columna, leído de su cabecera.
     *
     * La celda de la fila 2 lleva el número y el peso juntos (`"3.\n34%"`, D11), y
     * es la **única** fuente que sabe cuánto pesaba el indicador el día de la
     * descarga: ni el mapa ni el espejo guardan pesos, y la base sólo sabe lo de
     * hoy. Sin esto, «el peso cambió» no se puede decir.
     *
     * Devuelve `null` cuando no hay porcentaje que leer, y eso pasa en **dos** casos
     * legítimos que no son un error: la columna de reserva no lleva porcentaje (D12)
     * y el modo `promedio` escribe `prom.` (§3.5). Quien llama tiene que tratar el
     * `null` como «no comparable», nunca como cero.
     */
    public function pesoDeLaCabecera(string $hoja, string $columna): ?int
    {
        $texto = (string) $this->celda($hoja, $columna.self::FILA_CABECERA);

        if (preg_match('/(\d{1,3})\s*%/', $texto, $trozos) !== 1) {
            return null;
        }

        return (int) $trozos[1];
    }

    /**
     * De qué unidad es una columna, según **la banda de la fila 1 del archivo**.
     *
     * Devuelve el número de orden de la unidad dentro de la hoja (1, 2, 3…), que es
     * lo que la banda imprime delante del texto: `"2 · Geometría  30%"`.
     *
     * ## Por qué hay que leerlo de ahí, que parece rebuscado
     *
     * El mapa de `_myvc` guarda `columnas` (letra → indicador) y `reservadas`
     * (letra → número), **y las reservadas no dicen de qué unidad son**. Para la F9
     * —crear el indicador que el docente escribió en una columna de reserva— hace
     * falta una `unidad_id`, y el caso que más la necesita es justo el que no se
     * puede deducir mirando a los lados: **una asignatura sin ningún indicador**,
     * donde todas las columnas son de reserva y no hay vecina que preguntar. Es la
     * decisión (c) del doc 49 y el caso que la D12 vino a cubrir.
     *
     * La banda vive en una celda fusionada, así que el valor está sólo en la
     * primera columna del grupo: se camina hacia la izquierda hasta encontrarla.
     */
    public function unidadDeLaColumna(string $hoja, string $columna): ?int
    {
        $indice = Coordinate::columnIndexFromString($columna);

        // Hasta `D`, que es donde empieza la primera columna de nota. Por debajo
        // están el orden, el nombre y el ID, y ahí no hay banda que valga.
        for ($i = $indice; $i >= 4; $i--) {
            $letra = Coordinate::stringFromColumnIndex($i);
            $texto = trim((string) $this->celda($hoja, $letra.'1'));

            if ($texto === '') {
                continue;
            }

            if (preg_match('/^(\d{1,3})\s*·/u', $texto, $trozos) === 1) {
                return (int) $trozos[1];
            }

            return null;
        }

        return null;
    }

    /**
     * Las tres filas del bloque «alumnos que no aparecen en la lista», con lo que el
     * docente escribiera en ellas.
     *
     * **Se calculan y no se guardan en el mapa** porque su sitio está atado al de la
     * rejilla: el bloque empieza cuatro filas debajo del último alumno (separador,
     * rótulo, frase y ya). Guardarlas en `_myvc` sería un segundo sitio donde la
     * misma cuenta puede desincronizarse.
     *
     * Aquí sólo se leen: **quién es cada nombre lo decide {@see EnsayoDeLaPlanilla}**
     * (F6 del plan, §6.4), buscándolo dentro del grupo de esa hoja y preguntando. Ni
     * aquí ni allí se crea a nadie.
     *
     * @param  array<array-key, mixed>  $filas  el `filas` del mapa: fila → alumno
     * @return list<array{fila:int, nombre:string}>
     */
    public function filasDeAlumnosNuevos(string $hoja, array $filas): array
    {
        if ($filas === []) {
            return [];
        }

        $ultima = max(array_map('intval', array_keys($filas)));
        $primera = $ultima + 4;

        $escritas = [];

        for ($fila = $primera; $fila < $primera + HojaDeAsignatura::FILAS_NUEVAS; $fila++) {
            $nombre = trim((string) $this->celda($hoja, 'B'.$fila));

            if ($nombre !== '') {
                $escritas[] = ['fila' => $fila, 'nombre' => $nombre];
            }
        }

        return $escritas;
    }

    /**
     * Lee `_myvc` si está, comprueba la firma y fija el peldaño.
     */
    private function clasificar(): void
    {
        $meta = $this->libro?->getSheetByName(LibroDeNotas::METADATOS);

        if ($meta === null) {
            $this->sinHojaOculta();

            return;
        }

        $this->versionFormato = is_numeric($meta->getCell('B1')->getValue())
            ? (int) $meta->getCell('B1')->getValue()
            : null;

        // **Un formato que este servidor no conoce no es «firma rota».** Se declara
        // con su número y su salida, como el peldaño 5, y NO se intenta comprobar la
        // firma: hacerlo la daría por rota —porque el número entra en lo que se
        // firma— y mandaría a buscar un manipulador donde lo que hay es un libro de
        // otro despliegue. `null` se deja pasar: no dice de qué formato es, y
        // entonces manda la firma, que es la que sabe contestar.
        if ($this->versionFormato !== null
            && ! in_array($this->versionFormato, FirmaDelLibro::FORMATOS_QUE_SE_LEEN, true)) {
            $this->peldano = 5;
            $this->motivo = 'Este libro es de la versión de formato '.$this->versionFormato
                .' y este servidor lee hasta la '.FirmaDelLibro::FORMATO.'. Descargue el libro otra vez '
                .'desde Académico → Trabajar sin internet y pase las notas a ése.';

            return;
        }

        $cabecera = json_decode((string) $meta->getCell('B2')->getValue(), true);
        $firma = (string) $meta->getCell('B3')->getValue();

        if (! is_array($cabecera) || $firma === '') {
            // La hoja existe y no dice nada legible: alguien la vació o la copió a
            // medias. No vale como peldaño 2 —no hay mapa que usar— y no es un
            // archivo cualquiera, así que se declara con su motivo.
            $this->peldano = 5;
            $this->motivo = 'El archivo trae la hoja interna «'.LibroDeNotas::METADATOS
                .'» pero está vacía o ilegible, así que no se puede saber qué columna es qué '
                .'indicador ni qué fila es qué alumno.';

            return;
        }

        $this->cabecera = $cabecera;

        // El orden de las filas `hoja` **es** el orden de la lista que se firmó.
        $porNombre = [];
        $orden = [];

        foreach ($meta->getRowIterator() as $fila) {
            $f = $fila->getRowIndex();
            $clave = (string) $meta->getCell('A'.$f)->getValue();

            if ($clave === 'hoja') {
                $nombre = (string) $meta->getCell('B'.$f)->getValue();
                $mapa = json_decode((string) $meta->getCell('C'.$f)->getValue(), true);

                if (! is_array($mapa)) {
                    continue;
                }

                $mapa['espejo'] = [];
                $porNombre[$nombre] = $mapa;
                $orden[] = $nombre;
            } elseif ($clave === 'espejo') {
                $nombre = (string) $meta->getCell('B'.$f)->getValue();
                $alumno = (string) $meta->getCell('C'.$f)->getValue();
                $notas = json_decode((string) $meta->getCell('D'.$f)->getValue(), true);

                if (isset($porNombre[$nombre]) && is_array($notas)) {
                    $porNombre[$nombre]['espejo'][$alumno] = $notas;
                }
            }
        }

        $this->mapas = array_map(static fn ($n) => $porNombre[$n], $orden);

        if ($this->mapas === []) {
            $this->peldano = 5;
            $this->motivo = 'La hoja interna «'.LibroDeNotas::METADATOS
                .'» no describe ninguna hoja de asignatura.';

            return;
        }

        // **Con el número que el libro declara, no con el de hoy.** Es lo que deja
        // que un libro bajado antes de la fase 4 —formato 1, sin el espejo de la
        // asistencia— siga validando su firma en vez de caer en bloque al peldaño 2
        // el día del despliegue, sin que nadie lo hubiera tocado. Lo que le pasa a
        // ese libro es otra cosa y se decide arriba, en el ensayo: su familia de
        // ausencias se comporta como si no hubiera espejo.
        $this->firmaValida = FirmaDelLibro::comprobar(
            ['libro' => $cabecera, 'hojas' => $this->mapas],
            $firma,
            $this->versionFormato
        );

        $this->peldano = $this->firmaValida ? 1 : 2;
    }

    /**
     * Sin `_myvc`: distinguir el peldaño 3 del 4 y los dos del 5.
     *
     * Ninguno de los tres se trabaja en esta fase, pero **decir cuál es no es
     * decorativo**: el 3 y el 4 tienen arreglo y llegan en la fase 3, y el 5 es el
     * que necesita la salida de «descargar el libro correcto» para no dejar a nadie
     * en un callejón. Contestar «no se pudo leer» a los tres los haría iguales.
     */
    private function sinHojaOculta(): void
    {
        $conId = false;
        $reconocible = false;

        foreach ($this->pestanas as $nombre) {
            if ($nombre === LibroDeNotas::PORTADA) {
                $reconocible = true;

                continue;
            }

            $pestana = $this->libro?->getSheetByName($nombre);

            if ($pestana === null) {
                continue;
            }

            if (str_starts_with(trim((string) $pestana->getCell('A1')->getValue()), '←')) {
                $reconocible = true;
            }

            if (strcasecmp(trim((string) $pestana->getCell('C'.self::FILA_CABECERA)->getValue()), 'ID') === 0) {
                $conId = true;
                $reconocible = true;
            }
        }

        if ($conId) {
            $this->peldano = 3;

            return;
        }

        if ($reconocible) {
            $this->peldano = 4;

            return;
        }

        $this->peldano = 5;
        $this->motivo = 'Este archivo no parece una planilla de MyVc: no trae la hoja interna «'
            .LibroDeNotas::METADATOS.'», ninguna pestaña tiene el enlace a la portada y ninguna '
            .'tiene la columna ID. Descargue el libro otra vez desde Académico → Trabajar sin internet '
            .'y escriba las notas sobre ése.';
    }
}
