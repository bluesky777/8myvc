<?php

namespace App\Services;

use App\Http\Controllers\Alumnos\ImporterFixer;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\ToArray;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Events\BeforeSheet;

/**
 * Qué va a pasar si se sube esta hoja — **sin escribir una sola fila**.
 *
 * Es la Fase 2 de `docs/migracion/45-la-importacion-dinamica.md`, y existe por
 * la frase que ordena el plan entero: *«preguntándole al usuario qué quiere
 * hacer en tal y cual caso, **diciéndole qué va a pasar**»*. Hasta hoy no había
 * forma de contestar eso: la única manera de saber qué hacía una importación era
 * hacerla.
 *
 * ## Lo que hay que entender antes de tocar esto
 *
 * **El importador de hoy casi no valida: adivina.** Un valor que no reconoce no
 * produce un 422 — produce otro valor, plausible y equivocado, y sigue. Por eso
 * cada renglón de aquí no dice sólo *qué no entendí*, sino **qué se guardaría si
 * nadie hace nada**, que es donde se ve el `tipo_doc = 3`.
 *
 * ## La regla dura: esto NO escribe
 *
 * Ni un `INSERT`, ni un `UPDATE`, ni un `DELETE`, ni una transacción. Si algún
 * día alguien añade una escritura aquí, el botón que dice «esto no toca nada»
 * pasa a mentir, y lo hará en la pantalla donde la gente pulsa **antes** de
 * decidir. Lo fija `EnsayoDeLaImportacionTest::test_el_ensayo_no_escribe_nada`,
 * que cuenta filas de cinco tablas antes y después.
 *
 * ## La promesa es CONDICIONADA, y la condición hay que poder comprobarla
 *
 * Todo lo que devuelve es cierto **para el fichero que se le dio**. Hay dos
 * formas de que deje de serlo, y ninguna rompe nada ni pone nada en rojo:
 *
 * 1. **Que se suba otro fichero.** Aunque sea el mismo con una celda corregida,
 *    el plan que se enseñó ya no describe lo que va a pasar. Por eso la
 *    respuesta lleva la **huella** del libro que estudió: con ella delante, la
 *    pantalla puede decirlo antes de que alguien pulse.
 * 2. **Que la persona corrija algo en la pantalla y eso no llegue al
 *    importador.** Hoy no puede ocurrir porque la pantalla no deja cambiar nada
 *    y se sube el mismo fichero que produjo el plan. El día que se puedan
 *    corregir equivalencias, **la subida tiene que mandarlas y el importador
 *    aplicarlas**, o el plan deja de ser una promesa y pasa a ser una
 *    casualidad que se cumple mientras nadie toque nada.
 *
 * *Lo segundo no es un «no hagas esto»: es que, si se hace sin lo otro, esto
 * deja de ser un ensayo y se convierte en una pantalla decorativa.*
 *
 * ## Y la segunda: usa EL MISMO traductor que la importación de verdad
 *
 * `ImporterFixer::verificar()` no escribe —sólo traduce vocabularios y anota lo
 * que no supo leer— así que el ensayo lo llama tal cual. Es lo que hace que lo
 * que promete sea lo que va a hacer: una copia del traductor se separaría del
 * original en la primera corrección, y nadie se enteraría hasta que el informe
 * final no cuadrara con lo prometido.
 */
class EnsayoDeLaImportacion implements ToArray, WithEvents, WithHeadingRow
{
    /**
     * Las columnas que el importador lee, con lo que hace falta para poder
     * pintarlas y para poder decir qué pasa si faltan.
     *
     * `si_falta` es la columna que da sentido a la pantalla: no es «esta
     * columna es obligatoria» sino **qué queda escrito en la base cuando no
     * viene**. Las cinco claves que el importador calcula —`tipo_doc`,
     * `no_matricula`, `es_nuevo`, `is_acudiente1/2`— no están aquí a propósito:
     * son el cálculo, no el fichero, y enseñarlas invitaría a buscarlas en el
     * Excel.
     *
     * @var array<string, array{etiqueta: string, obligatoria: bool, tipo: string, longitud: int|null, si_falta: string}>
     */
    public const COLUMNAS = [
        'id' => ['etiqueta' => 'ID', 'obligatoria' => false, 'tipo' => 'entero', 'longitud' => null,
            'si_falta' => 'Se busca al alumno por su documento; si tampoco está, se crea uno nuevo.'],
        'tipo_de_documento' => ['etiqueta' => 'Tipo de Documento', 'obligatoria' => false, 'tipo' => 'catalogo', 'longitud' => null,
            'si_falta' => 'Se guarda TARJETA DE IDENTIDAD (id 3) sin avisar de nada.'],
        'nro_de_documento' => ['etiqueta' => 'Nro de documento', 'obligatoria' => true, 'tipo' => 'texto', 'longitud' => 20,
            'si_falta' => 'El alumno se crea igual, sin documento y sin poder reconocerse la próxima vez.'],
        'primer_apellido' => ['etiqueta' => 'Primer apellido', 'obligatoria' => true, 'tipo' => 'texto', 'longitud' => 100,
            'si_falta' => 'Los apellidos quedan en blanco.'],
        'segundo_apellido' => ['etiqueta' => 'Segundo apellido', 'obligatoria' => false, 'tipo' => 'texto', 'longitud' => 100,
            'si_falta' => 'Se guarda sólo el primero.'],
        'primer_nombre' => ['etiqueta' => 'Primer nombre', 'obligatoria' => true, 'tipo' => 'texto', 'longitud' => 100,
            'si_falta' => 'La fila NO crea alumno: sin primer nombre el importador la salta entera.'],
        'segundo_nombre' => ['etiqueta' => 'Segundo nombre', 'obligatoria' => false, 'tipo' => 'texto', 'longitud' => 100,
            'si_falta' => 'Se guarda sólo el primero.'],
        'estado_matricula' => ['etiqueta' => 'Estado Matrícula', 'obligatoria' => false, 'tipo' => 'catalogo', 'longitud' => 4,
            'si_falta' => 'La matrícula conserva el estado que ya tenía; una nueva nace MATR.'],
        'numero_matricula' => ['etiqueta' => 'Número Matrícula', 'obligatoria' => false, 'tipo' => 'texto', 'longitud' => 20,
            'si_falta' => 'Queda en blanco.'],
        'direccion_residencia' => ['etiqueta' => 'Dirección residencia', 'obligatoria' => false, 'tipo' => 'texto', 'longitud' => 150,
            'si_falta' => 'Se BORRA la que hubiera, porque el UPDATE escribe todas las columnas.'],
        'barrio' => ['etiqueta' => 'Barrio', 'obligatoria' => false, 'tipo' => 'texto', 'longitud' => 100,
            'si_falta' => 'Se BORRA el que hubiera.'],
        'telefono' => ['etiqueta' => 'Teléfono', 'obligatoria' => false, 'tipo' => 'texto', 'longitud' => 50,
            'si_falta' => 'Se BORRA el que hubiera.'],
        'celular' => ['etiqueta' => 'Celular', 'obligatoria' => false, 'tipo' => 'texto', 'longitud' => 50,
            'si_falta' => 'Se BORRA el que hubiera.'],
        'estrato' => ['etiqueta' => 'Estrato', 'obligatoria' => false, 'tipo' => 'entero', 'longitud' => null,
            'si_falta' => 'Se BORRA el que hubiera.'],
        'sisben' => ['etiqueta' => 'SISBEN', 'obligatoria' => false, 'tipo' => 'texto', 'longitud' => 50,
            'si_falta' => 'Se BORRA el que hubiera.'],
        'fecha_de_nacim' => ['etiqueta' => 'Fecha de nacim', 'obligatoria' => false, 'tipo' => 'fecha', 'longitud' => null,
            'si_falta' => 'Se BORRA la que hubiera, y con ella la comprobación de «mismo nombre, otro documento».'],
        'sexo' => ['etiqueta' => 'Sexo', 'obligatoria' => false, 'tipo' => 'catalogo', 'longitud' => 1,
            'si_falta' => 'Al crear se guarda M; al actualizar se BORRA el que hubiera.'],
        'rh' => ['etiqueta' => 'RH', 'obligatoria' => false, 'tipo' => 'texto', 'longitud' => 10,
            'si_falta' => 'Se BORRA el que hubiera.'],
        'eps' => ['etiqueta' => 'EPS', 'obligatoria' => false, 'tipo' => 'texto', 'longitud' => 100,
            'si_falta' => 'Se BORRA la que hubiera.'],
        'religion' => ['etiqueta' => 'Religión', 'obligatoria' => false, 'tipo' => 'texto', 'longitud' => 100,
            'si_falta' => 'Se BORRA la que hubiera.'],
    ];

    /** Las columnas del UPDATE de un alumno que ya existe, para poder decir qué le cambia. */
    private const COMPARABLES = [
        'nombres' => 'Nombres', 'apellidos' => 'Apellidos', 'sexo' => 'Sexo', 'fecha_nac' => 'Fecha de nacimiento',
        'tipo_doc' => 'Tipo de documento', 'documento' => 'Documento', 'no_matricula' => 'Número de matrícula',
        'direccion' => 'Dirección', 'barrio' => 'Barrio', 'telefono' => 'Teléfono', 'celular' => 'Celular',
        'estrato' => 'Estrato', 'tipo_sangre' => 'RH', 'eps' => 'EPS', 'religion' => 'Religión',
        'nro_sisben' => 'SISBEN',
    ];

    public array $sheetNames = [];

    /** @var array<int, array<string, mixed>> */
    public array $hojas = [];

    /** @var array<int, array<string, mixed>> */
    public array $plan = [];

    /** @var array<int, array<string, mixed>> */
    public array $posiblesRepetidos = [];

    /** @var array<int, array<string, mixed>> */
    public array $filasSinDocumento = [];

    /** Documento => filas donde aparece, para el choque dentro del propio libro. */
    private array $documentosVistos = [];

    /** Cuántas celdas vienen vacías en cada columna. Columna => veces. */
    private array $vacios = [];

    /** Caché de `documento` => id, para no repetir la misma consulta por fila. */
    private array $idPorDocumento = [];

    /** @var array<string, array<string, mixed>> */
    private array $truncados = [];

    public function __construct(private int $year, private ImporterFixer $fixer) {}

    public function registerEvents(): array
    {
        return [
            BeforeSheet::class => function (BeforeSheet $event) {
                $this->sheetNames[] = $event->getSheet()->getTitle();
            },
        ];
    }

    public function headingRow(): int
    {
        return 2;
    }

    /**
     * Una pestaña del libro, que debería ser un grupo.
     *
     * Lo primero que hace es mirar si el nombre de la pestaña casa con un
     * `grupos.abrev` del año, y **eso hoy ocurre a media importación**: el
     * importador lo comprueba dentro del bucle y por eso su 500 llega con medio
     * colegio ya escrito. Aquí no escribe nadie, así que la comprobación puede
     * decir simplemente que esa hoja no va a entrar.
     */
    public function array(array $array)
    {
        $nombre = $this->sheetNames[count($this->sheetNames) - 1];

        $grupo = DB::selectOne(
            'SELECT g.id, g.abrev, g.nombre, g.year_id FROM grupos g
             INNER JOIN years y ON y.id = g.year_id
             WHERE g.abrev = ? AND g.deleted_at IS NULL AND y.deleted_at IS NULL AND y.year = ?',
            [$nombre, $this->year]
        );

        $encabezados = $this->encabezadosDe($array);

        $this->hojas[] = [
            'nombre' => $nombre,
            'filas' => count($array),
            'coincide_con' => $grupo->abrev ?? null,
            'grupo_id' => $grupo->id ?? null,
            'encabezados' => $encabezados,
            'faltan' => $this->faltan($encabezados),

            // `sobran` son las que NO LEE NADIE. Las de acudiente van aparte
            // porque el importador sí las escribe aunque el ensayo no las mire:
            // juntarlas hacía que la pantalla dijera «se ignoran» de columnas
            // que crean personas.
            'sobran' => $this->loQueNoSeEstudia($encabezados)['sobran'],
            'de_acudiente_no_estudiadas' => $this->loQueNoSeEstudia($encabezados)['de_acudiente'],
        ];

        // Una hoja que no es de ningún grupo del año no se estudia fila a fila:
        // hoy esa hoja hace reventar la importación entera, y lo que la pantalla
        // necesita saber es eso, no qué traía dentro.
        if ($grupo === null) {
            return;
        }

        foreach ($array as $i => $fila) {
            $this->estudiarFila($fila, $nombre, $i, $grupo);
        }
    }

    /**
     * Qué pasaría con esta fila.
     *
     * El orden importa y es el del importador: traducir, buscar por id, buscar
     * por documento, y sólo entonces decidir entre crear y actualizar. Cambiarlo
     * aquí haría que el ensayo prometiera algo distinto de lo que va a ocurrir.
     */
    private function estudiarFila(array $fila, string $hoja, int $indice, object $grupo): void
    {
        // La fila que llega puede no traer todas las columnas, y `verificar()`
        // las lee sin comprobar. Rellenar con null lo que falte es lo que
        // permite estudiar una hoja incompleta en vez de reventar con ella —
        // que es exactamente lo que hoy le pasa a la importación de verdad.
        $alumno = $this->conTodasLasClaves($fila);

        $antes = count($this->fixer->avisos);
        $this->fixer->verificar($alumno, $this->year);

        // Los avisos del traductor no saben en qué fila del libro estaban: los
        // numera por `numero_matricula`, que puede venir vacío. Se les añade la
        // posición aquí, que es lo que la pantalla usa para llevar a la celda.
        for ($i = $antes; $i < count($this->fixer->avisos); $i++) {
            $this->fixer->avisos[$i]['hoja'] = $hoja;
            $this->fixer->avisos[$i]['fila_del_libro'] = $indice + 3;
        }

        $this->anotarTruncados($fila, $hoja, $indice);
        $this->anotarVacios($fila);

        $documento = $fila['nro_de_documento'] ?? null;
        $nombreCompleto = trim(($fila['primer_nombre'] ?? '').' '.($fila['segundo_nombre'] ?? ''));
        $apellidos = trim(($fila['primer_apellido'] ?? '').' '.($fila['segundo_apellido'] ?? ''));

        if ($documento === null || trim((string) $documento) === '') {
            $this->filasSinDocumento[] = [
                'hoja' => $hoja,
                'fila_del_libro' => $indice + 3,
                'nombre' => trim($nombreCompleto.' '.$apellidos),
            ];
        } else {
            $this->documentosVistos[(string) $documento][] = [
                'hoja' => $hoja, 'fila_del_libro' => $indice + 3,
                'nombre' => trim($nombreCompleto.' '.$apellidos), 'grupo' => $hoja,
                'campos_con_dato' => $this->camposConDato($fila),
            ];
        }

        $id = $fila['id'] ?? null;
        $reencontrado = false;

        if (! $id && $documento !== null) {
            $id = $this->buscarPorDocumento($documento);
            $reencontrado = $id !== null;
        }

        $existente = $id ? $this->fichaDe((int) $id) : null;

        // Sin primer nombre el importador salta la fila entera y no escribe
        // nada. Es una tercera acción y no un «crear» con avisos: la pantalla
        // tiene que poder decir «estas tres filas no van a hacer nada».
        if ($existente === null && trim((string) ($fila['primer_nombre'] ?? '')) === '') {
            $this->plan[] = [
                'hoja' => $hoja, 'fila_del_libro' => $indice + 3, 'documento' => $documento,
                'nombre' => trim($nombreCompleto.' '.$apellidos),
                'accion' => 'se_salta', 'motivo' => 'La fila no trae primer nombre, así que el importador no la escribe.',
                'alumno_existente' => null, 'cambios' => [],
            ];

            return;
        }

        if ($existente === null) {
            $this->buscarPosibleRepetido($hoja, $indice, $nombreCompleto, $apellidos, $fila);

            $this->plan[] = [
                'hoja' => $hoja, 'fila_del_libro' => $indice + 3, 'documento' => $documento,
                'nombre' => trim($nombreCompleto.' '.$apellidos),
                'accion' => 'crear', 'alumno_existente' => null, 'cambios' => [],
            ];

            return;
        }

        $cambios = $this->cambiosQueLeHace($existente, $alumno, $fila);

        $this->plan[] = [
            'hoja' => $hoja,
            'fila_del_libro' => $indice + 3,
            'documento' => $documento,
            'nombre' => trim($nombreCompleto.' '.$apellidos),
            // «No le cambia nada» es un estado propio y no un «actualizar» con
            // la lista vacía: es lo que hace que la pantalla no parezca un
            // pisotón general — «a 11 de los 34 no les cambia nada».
            //
            // Y significa exactamente eso: NINGÚN DATO cambia. La fila sí se
            // escribe —el `UPDATE` se ejecuta igual y mueve `updated_at`, medido
            // en las 37 del seed—, así que no es «no se toca». La distinción
            // importa el día que alguien mire quién modificó una ficha.
            'accion' => $cambios === [] ? 'sin_cambios' : 'actualizar',
            'reencontrado_por_documento' => $reencontrado,
            'alumno_existente' => [
                'id' => (int) $existente->id,
                'nombres' => $existente->nombres,
                'apellidos' => $existente->apellidos,
                'documento' => $existente->documento,
                'grupo' => $existente->grupo_actual,
                'tiene_matricula_en_el_year' => $existente->grupo_actual !== null,
            ],
            'cambios' => $cambios,
        ];
    }

    /**
     * Qué le cambia esta fila a un alumno que ya está.
     *
     * Incluye **los que pasan a vacío**, y ése es el caso que más duele: el
     * `UPDATE` del importador escribe las diecisiete columnas, así que una hoja
     * que no traiga la columna EPS **le borra la EPS a todo el grupo** sin que
     * nadie lo pida. Esconder eso aquí haría que la pantalla prometiera menos
     * daño del que hace.
     *
     * @return array<int, array{campo: string, etiqueta: string, antes: mixed, despues: mixed}>
     */
    private function cambiosQueLeHace(object $existente, array $traducido, array $fila): array
    {
        $futuro = [
            'nombres' => trim(($fila['primer_nombre'] ?? '').' '.($fila['segundo_nombre'] ?? '')),
            'apellidos' => trim(($fila['primer_apellido'] ?? '').' '.($fila['segundo_apellido'] ?? '')),
            'sexo' => $fila['sexo'] ?? null,
            'fecha_nac' => $traducido['fecha_de_nacim'] ?? null,
            'tipo_doc' => $traducido['tipo_doc'] ?? null,
            'documento' => $fila['nro_de_documento'] ?? null,
            'no_matricula' => $fila['numero_matricula'] ?? null,
            'direccion' => $fila['direccion_residencia'] ?? null,
            'barrio' => $fila['barrio'] ?? null,
            'telefono' => $fila['telefono'] ?? null,
            'celular' => $fila['celular'] ?? null,
            'estrato' => $fila['estrato'] ?? null,
            'tipo_sangre' => $fila['rh'] ?? null,
            'eps' => $fila['eps'] ?? null,
            'religion' => $fila['religion'] ?? null,

            // NO es `$fila['sisben']`, y ésta es la trampa que un ensayo
            // ingenuo no ve: el `UPDATE` del importador escribe `nro_sisben`
            // DOS VECES —una en la lista fija y otra en el fragmento que arma
            // `verificar()`— y en un `SET a=?, a=NULL` **gana la segunda**. Así
            // que un «No aplica» de la hoja no se guarda: la columna acaba en
            // NULL. Predecir el valor crudo haría que el ensayo prometiera un
            // cambio que no ocurre, y en esta hoja eso son las 37 filas.
            'nro_sisben' => $this->sisbenQueQuedaria($fila),
        ];

        $cambios = [];

        foreach (self::COMPARABLES as $columna => $etiqueta) {
            $ahora = $existente->{$columna} ?? null;
            $luego = $futuro[$columna] ?? null;

            // Se comparan como cadenas porque lo que sale de la hoja es texto y
            // lo que hay en la base puede ser número: `5` y `'5'` son el mismo
            // estrato, y enseñarlo como un cambio llenaría la pantalla de ruido
            // que no lo es.
            if ((string) $ahora === (string) $luego) {
                continue;
            }

            $cambios[] = [
                'campo' => $columna,
                'etiqueta' => $etiqueta,
                'antes' => $ahora,
                'despues' => $luego,
                'se_vacia' => $luego === null || (string) $luego === '',
            ];
        }

        return $cambios;
    }

    /**
     * Lo que acaba en `nro_sisben`, con la regla del traductor y no con la de la
     * hoja.
     *
     * `ImporterFixer::verificar()` escribe `has_sisben=0, nro_sisben=null`
     * cuando la celda dice «no aplica» o está vacía, y ese fragmento va DESPUÉS
     * en el mismo `SET`, así que pisa al valor crudo. La normalización se le
     * pide al fixer en vez de repetirla aquí: si algún día aprende a plegar otra
     * cosa, el ensayo la aprende con él.
     */
    private function sisbenQueQuedaria(array $fila): ?string
    {
        $valor = $fila['sisben'] ?? null;

        $normalizado = $this->fixer->normalizar($valor);

        return $normalizado === 'no aplica' || $normalizado === '' ? null : $valor;
    }

    /**
     * El caso de la decisión D4: el documento no está, pero el nombre sí.
     *
     * Es el chico que pasa de Registro Civil a Tarjeta de Identidad, que es el
     * caso más común de todos y hoy **se duplica en silencio**. No decide nada:
     * deja las dos filas al lado para que una persona elija, porque fusionar dos
     * expedientes mal es de lo poco aquí que no se deshace con un `DELETE`.
     */
    private function buscarPosibleRepetido(string $hoja, int $indice, string $nombres, string $apellidos, array $fila): void
    {
        $fecha = $fila['fecha_de_nacim'] ?? null;

        if ($nombres === '' || $apellidos === '') {
            return;
        }

        $candidatos = DB::select(
            'SELECT a.id, a.nombres, a.apellidos, a.documento, a.fecha_nac, t.tipo AS tipo_doc_literal,
                    g.abrev AS grupo, y.year AS year_de_la_matricula
             FROM alumnos a
             LEFT JOIN tipos_documentos t ON t.id = a.tipo_doc AND t.deleted_at IS NULL
             LEFT JOIN matriculas m ON m.alumno_id = a.id AND m.deleted_at IS NULL
             LEFT JOIN grupos g ON g.id = m.grupo_id AND g.deleted_at IS NULL
             LEFT JOIN years y ON y.id = g.year_id
             WHERE a.deleted_at IS NULL AND a.nombres = ? AND a.apellidos = ?
             ORDER BY y.year DESC LIMIT 5',
            [$nombres, $apellidos]
        );

        if ($candidatos === []) {
            return;
        }

        $coincideEn = ['nombres', 'apellidos'];

        foreach ($candidatos as $candidato) {
            if ($fecha !== null && $candidato->fecha_nac !== null && (string) $candidato->fecha_nac === (string) $fecha) {
                $coincideEn[] = 'fecha_nac';
                break;
            }
        }

        $this->posiblesRepetidos[] = [
            'hoja' => $hoja,
            'fila_del_libro' => $indice + 3,
            'nombre' => trim($nombres.' '.$apellidos),
            'documento_en_la_hoja' => $fila['nro_de_documento'] ?? null,
            'coincide_en' => array_values(array_unique($coincideEn)),
            'candidatos' => $candidatos,
        ];
    }

    /**
     * El mismo documento dos veces dentro del propio libro.
     *
     * `cual_ganaria_hoy` no es un adorno: las filas se procesan en orden y la
     * última pisa a la anterior, así que «dejar que gane la última» tiene una
     * respuesta concreta **que sólo sabe el servidor**, porque depende del orden
     * de lectura de las hojas.
     *
     * @return array<int, array<string, mixed>>
     */
    public function duplicadosEnElArchivo(): array
    {
        $duplicados = [];

        foreach ($this->documentosVistos as $documento => $filas) {
            if (count($filas) < 2) {
                continue;
            }

            $duplicados[] = [
                'documento' => $documento,
                'filas' => $filas,
                'cual_ganaria_hoy' => $filas[count($filas) - 1],
            ];
        }

        return $duplicados;
    }

    /**
     * Los valores que el traductor no supo leer, **agrupados por valor y no por
     * fila**.
     *
     * Es lo que convierte cuarenta y cinco avisos en cuatro decisiones: a la
     * persona no le sirve una lista de filas, le sirve saber que «CARNÉ
     * DIPLOMÁTICO» aparece 37 veces y decidir una vez qué es.
     *
     * @return array<int, array<string, mixed>>
     */
    public function valoresNoReconocidos(): array
    {
        $porValor = [];

        foreach ($this->fixer->avisos as $aviso) {
            $clave = ($aviso['campo'] ?? '').'|'.($aviso['valor'] ?? '');

            if (! isset($porValor[$clave])) {
                $porValor[$clave] = [
                    'columna' => $aviso['campo'] ?? '',
                    'valor' => $aviso['valor'] ?? '',
                    'motivo' => $aviso['motivo'] ?? '',
                    'veces' => 0,
                    'filas' => [],
                ];
            }

            $porValor[$clave]['veces']++;

            if (count($porValor[$clave]['filas']) < 50) {
                $porValor[$clave]['filas'][] = [
                    'hoja' => $aviso['hoja'] ?? null,
                    'fila_del_libro' => $aviso['fila_del_libro'] ?? null,
                ];
            }
        }

        return array_values($porValor);
    }

    /**
     * Los valores que no caben y se guardarían cortados.
     *
     * El caso medido es `matriculas.estado`, que es `varchar(4)`: un «Activo» se
     * guardaba «Acti», que no es ninguno de los siete códigos vivos, y el alumno
     * **desaparecía de todas las listas**. Desde la Fase 1 no se escribe, pero
     * la pantalla tiene que poder decirlo **antes**, que es de lo que va esto.
     *
     * @return array<int, array<string, mixed>>
     */
    public function truncados(): array
    {
        return array_values($this->truncados);
    }

    /**
     * Las celdas vacías, contadas POR COLUMNA y nunca por celda.
     *
     * El traductor no deja aviso cuando una celda viene vacía, y hace bien: si
     * lo dejara, preguntaría por cada hueco de las dieciséis bases y el aviso
     * dejaría de significar nada. Pero **el vacío no es neutro**, y ahí está el
     * renglón que hoy no existe: por el defecto de `ImporterFixer`, cinco celdas
     * vacías en `tipo_de_documento` **se guardan como Tarjeta de Identidad**, o
     * sea que «no sé» se convierte en una afirmación sobre un menor.
     *
     * Un número por columna es lo que permite escribir «5 vacías → se guardarán
     * como TARJETA DE IDENTIDAD» sin convertir la pantalla en una lista de
     * huecos. Lo pidió la Fase 2 dibujando el escenario 3.
     */
    private function anotarVacios(array $fila): void
    {
        foreach (array_keys(self::COLUMNAS) as $columna) {
            $valor = $fila[$columna] ?? null;

            if ($valor === null || trim((string) $valor) === '') {
                $this->vacios[$columna] = ($this->vacios[$columna] ?? 0) + 1;
            }
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function vacios(): array
    {
        $salida = [];

        foreach ($this->vacios as $columna => $veces) {
            $salida[] = [
                'columna' => $columna,
                'etiqueta' => self::COLUMNAS[$columna]['etiqueta'],
                'veces' => $veces,
                'consecuencia' => self::COLUMNAS[$columna]['si_falta'],
            ];
        }

        return $salida;
    }

    /**
     * Lo que no cabe, anotado al pasar por la fila.
     *
     * Las dos columnas son las medidas, y las consecuencias son DISTINTAS: en
     * `estado_matricula` la Fase 1 dejó que lo que no cabe **no se escriba** —la
     * matrícula conserva el suyo— y en `sexo` sigue cortándose, que en un
     * `varchar(1)` convierte «Masculino» en «M» por suerte y «Femenino» en «F»
     * por suerte también, pero «Hombre» en «H», que no es ninguno de los dos.
     */
    private function anotarTruncados(array $fila, string $hoja, int $indice): void
    {
        foreach (['estado_matricula' => 4, 'sexo' => 1] as $columna => $tope) {
            $valor = $fila[$columna] ?? null;

            if ($valor === null || mb_strlen(trim((string) $valor)) <= $tope) {
                continue;
            }

            $clave = $columna.'|'.$valor;

            $this->truncados[$clave] ??= [
                'columna' => $columna,
                'etiqueta' => self::COLUMNAS[$columna]['etiqueta'],
                'valor' => $valor,
                'se_guardaria' => $columna === 'estado_matricula' ? null : mb_substr((string) $valor, 0, $tope),
                'longitud_maxima' => $tope,
                'consecuencia' => $columna === 'estado_matricula' ? 'no_se_escribe' : 'se_corta',
                'veces' => 0,
                'filas' => [],
            ];

            $this->truncados[$clave]['veces']++;

            if (count($this->truncados[$clave]['filas']) < 50) {
                $this->truncados[$clave]['filas'][] = ['hoja' => $hoja, 'fila_del_libro' => $indice + 3];
            }
        }
    }

    /** Los avisos del traductor, tal cual, ya con su hoja y su fila. */
    public function avisos(): array
    {
        return $this->fixer->avisos;
    }

    // ── lo de dentro ─────────────────────────────────────────────────────────

    /**
     * El id del alumno con ese documento, con la MISMA comparación que hace la
     * importación de verdad.
     *
     * Se llama fila a fila y no con un `IN (...)` de los 800 documentos, y es a
     * propósito: esa comparación tiene una conversión implícita dentro —hay 7
     * alumnos vivos con cero a la izquierda que dependen de ella— y agruparla
     * cambiaría a quién encuentra. **Un ensayo que promete algo distinto de lo
     * que va a pasar es peor que un ensayo lento.** Lo que sí se hace es no
     * repetir la consulta para el mismo documento.
     */
    private function buscarPorDocumento($documento): ?int
    {
        $clave = (string) $documento;

        if (array_key_exists($clave, $this->idPorDocumento)) {
            return $this->idPorDocumento[$clave];
        }

        $documento = is_string($documento) ? trim($documento) : $documento;

        if ($documento === null || $documento === '' || $documento === 0 || $documento === '0') {
            return $this->idPorDocumento[$clave] = null;
        }

        $fila = DB::selectOne(
            'SELECT id FROM alumnos WHERE documento = ? AND deleted_at IS NULL ORDER BY id LIMIT 1',
            [$documento]
        );

        return $this->idPorDocumento[$clave] = $fila === null ? null : (int) $fila->id;
    }

    private function fichaDe(int $id): ?object
    {
        return DB::selectOne(
            'SELECT a.*, g.abrev AS grupo_actual
             FROM alumnos a
             LEFT JOIN matriculas m ON m.alumno_id = a.id AND m.deleted_at IS NULL
             LEFT JOIN grupos g ON g.id = m.grupo_id AND g.deleted_at IS NULL
             LEFT JOIN years y ON y.id = g.year_id AND y.year = ?
             WHERE a.id = ? AND a.deleted_at IS NULL
             LIMIT 1',
            [$this->year, $id]
        );
    }

    private function encabezadosDe(array $array): array
    {
        if ($array === []) {
            return [];
        }

        return array_keys($array[0]);
    }

    private function faltan(array $encabezados): array
    {
        return array_values(array_diff(array_keys(self::COLUMNAS), $encabezados));
    }

    /**
     * Las columnas del fichero que este ensayo no estudia, **partidas en dos
     * porque no son lo mismo**.
     *
     * Hasta hoy salían todas juntas en `sobran`, y la pantalla las enseñaba bajo
     * «no las usa MyVc y se ignoran». **Para las 34 de acudiente eso es falso:
     * el importador SÍ las lee y escribe acudientes y parentescos con ellas.**
     * Decir que se ignoran cuando van a crear personas es exactamente la clase
     * de frase tranquilizadora y falsa que este módulo existe para quitar.
     *
     * Lo vio la sesión del front conduciendo la pantalla contra el docker, que
     * es donde se ve lo que una respuesta *parece decir*.
     *
     * @return array{sobran: array<int, string>, de_acudiente: array<int, string>}
     */
    private function loQueNoSeEstudia(array $encabezados): array
    {
        $fuera = array_values(array_diff($encabezados, array_keys(self::COLUMNAS)));

        $deAcudiente = array_values(array_filter(
            $fuera,
            fn ($c) => str_contains((string) $c, 'acud1') || str_contains((string) $c, 'acud2')
        ));

        return [
            'sobran' => array_values(array_diff($fuera, $deAcudiente)),
            'de_acudiente' => $deAcudiente,
        ];
    }

    private function conTodasLasClaves(array $fila): array
    {
        foreach (array_keys(self::COLUMNAS) as $clave) {
            $fila[$clave] ??= null;
        }

        foreach (['tipo_docu_acud1', 'tipo_docu_acud2', 'es_el_acudiente_acud1', 'es_el_acudiente_acud2',
            'parentesco_acud1', 'parentesco_acud2', 'ciudad_docu_acud1', 'ciudad_docu_acud2',
            'urbana', 'nuevo', 'ciudad_residencia', 'ciudad_nacimiento', 'departam_nacimiento',
            'lugar_de_expedicion_ciudad', 'lugar_de_expedicion_departamento', 'sisben_3'] as $clave) {
            $fila[$clave] ??= null;
        }

        return $fila;
    }

    private function camposConDato(array $fila): int
    {
        return count(array_filter($fila, fn ($v) => $v !== null && trim((string) $v) !== ''));
    }
}
