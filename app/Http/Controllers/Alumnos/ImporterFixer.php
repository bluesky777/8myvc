<?php

namespace App\Http\Controllers\Alumnos;

use App\Models\Debugging;
use App\Support\ColumnaSegura;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Request;

class ImporterFixer
{
    public $tipos_doc;

    public $cant_td;

    public $ciudades;

    public $cant_ciud;

    /**
     * Lo que esta clase NO supo traducir, para que alguien pueda decirlo.
     *
     * Hasta hoy un valor que no casaba con el catalogo no dejaba rastro: se
     * caia al defecto y la importacion respondia 'Importados.' igual. Esto no
     * cambia lo que se guarda —eso seria cambiarle el resultado a dieciseis
     * colegios sin avisar—: solo lo anota, para que la pantalla pueda
     * preguntar. Es el cimiento de la importacion que dice que va a pasar.
     *
     * `hoja` y `fila_del_libro` son opcionales y NO las pone esta clase: las
     * añade el ensayo, que sí sabe por qué pestaña y por qué fila del libro iba.
     * Aquí sólo se declara que el aviso puede llevarlas, porque el que las lee
     * —la pantalla— tiene que poder llevar a la celda, y `fila` no sirve para
     * eso: es el `numero_matricula`, que puede venir vacío.
     *
     * @var array<int, array{fila?: mixed, campo?: string, valor?: string, motivo?: string, hoja?: string, fila_del_libro?: int}>
     */
    public $avisos = [];

    /**
     * Compara como compara una persona: sin tildes y sin mayusculas.
     *
     * `strtolower` solo baja bytes ASCII, asi que con el catalogo en mayusculas
     * y con tilde `CEDULA DE CIUDADANIA` casaba y `Cedula de ciudadania` no
     * —con sus tildes de verdad—. O sea que **el Excel mejor escrito era el que
     * fallaba**, y el resultado no era un error: era tipo_doc = 3, Tarjeta de
     * Identidad, en silencio.
     *
     * Es la tilde de `docs/migracion/33-la-tilde-que-sql-no-ve.md` otra vez,
     * ahora en PHP. Se pliega el acento ademas de bajar la caja porque el
     * catalogo de un colegio puede tener `CEDULA` sin tilde y el Excel de otro
     * `Cedula` con ella, y las dos quieren decir lo mismo.
     */
    public function normalizar($valor)
    {
        if ($valor === null) {
            return '';
        }

        $valor = mb_strtolower(trim((string) $valor), 'UTF-8');

        return strtr($valor, [
            'á' => 'a',
            'é' => 'e',
            'í' => 'i',
            'ó' => 'o',
            'ú' => 'u',
            'à' => 'a',
            'è' => 'e',
            'ì' => 'i',
            'ò' => 'o',
            'ù' => 'u',
            'ü' => 'u',
            'ñ' => 'n',
            'ç' => 'c',
        ]);
    }

    /**
     * Lo que una persona decidió que significa cada valor que no se entendía.
     *
     * Sale de la pantalla del ensayo: «CARNÉ DIPLOMÁTICO es Cédula», «Activo es
     * MATR». Las claves llegan **tal como estaban en el fichero** y se guardan
     * normalizadas, porque quien las escribe está mirando la celda y no
     * pensando en tildes.
     *
     * ## Por qué viven AQUÍ y no en el importador
     *
     * Porque por esta clase pasan **los dos caminos** —el ensayo y la subida— y
     * ahí está toda la garantía de que el plan que se enseña es el que se
     * cumple. Si se aplicaran en `ImportarController`, la pantalla enseñaría un
     * plan SIN las correcciones que la persona acaba de escribir y el resultado
     * sería otro: el mismo fallo que este módulo persigue, con un paso más de
     * disimulo.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $equivalencias = [];

    /**
     * Cuántas se han usado de verdad, por campo.
     *
     * No es telemetría: es lo que deja al informe final decir «se aplicaron las
     * 13 que aprobaste». Una respuesta que se ignora en silencio es el mismo
     * silencio que este módulo empezó quitando.
     *
     * @var array<string, int>
     */
    public array $equivalenciasUsadas = [];

    /**
     * El tipo dice `mixed` y no `array<string, mixed>` a propósito: esto llega
     * de un JSON que manda el cliente, así que **lo que promete el docblock no
     * es lo que puede llegar**. Larastan señaló el `is_array()` de abajo como
     * redundante y tenía razón sobre el papel; lo que estaba mal era el papel.
     *
     * @param  array<string, mixed>  $equivalencias  campo => [valor del fichero => valor bueno]
     */
    public function __construct(array $equivalencias = [])
    {
        foreach ($equivalencias as $campo => $mapa) {
            if (! is_array($mapa)) {
                continue;
            }

            foreach ($mapa as $original => $bueno) {
                $clave = $this->normalizar($original);

                // Un original vacío casaría con TODA celda vacía, y un hueco no
                // es un valor que alguien haya decidido. Los vacíos se deciden
                // por su propio camino, que es otra cosa.
                if ($clave !== '') {
                    $this->equivalencias[$campo][$clave] = $bueno;
                }
            }
        }

        $this->tipos_doc = DB::select('SELECT id, tipo, abrev FROM tipos_documentos WHERE deleted_at is null');
        $this->cant_td = count($this->tipos_doc);
        $this->ciudades = DB::select('SELECT id, ciudad FROM ciudades WHERE deleted_at is null');
        $this->cant_ciud = count($this->ciudades);
    }

    public function verificar(&$alumno, $year)
    {
        $cons = '';
        // Los valores del fragmento `$cons` van aparte y ligados. Lo que entra
        // aqui sale de la hoja que sube el usuario, y concatenarlo era inyeccion:
        // el fragmento acaba dentro del SET de un UPDATE que si se ejecuta
        // (ImportarController 186 y 669). Ver 05 §60.
        $valores = [];
        $consA1 = '';
        $consA2 = '';
        $ciudad_id_A1 = null;
        $ciudad_id_A2 = null;
        // if ($alumno->tipo_de_documento == 'fecha_nac')
        //	$valor = Carbon::parse($valor);

        // Tipo doc
        //
        // La comparacion normaliza las dos partes: sin tildes y sin mayusculas.
        // Ver `normalizar()`, que lleva la medicion de lo que pasaba antes.
        $altipo_low = $this->normalizar($alumno['tipo_de_documento'] ?? null);
        $A1tipo_low = $this->normalizar($alumno['tipo_docu_acud1'] ?? null);
        $A2tipo_low = $this->normalizar($alumno['tipo_docu_acud2'] ?? null);

        // LO QUE UNA PERSONA DECIDIÓ MANDA SOBRE EL CATÁLOGO, y va antes que él
        // a propósito: quien contesta «CARNÉ DIPLOMÁTICO es Cédula» está
        // mirando esa celda, y el catálogo ya demostró que no sabe leerla.
        $decidido = $this->equivalencias['tipo_de_documento'][$altipo_low] ?? null;

        if ($decidido !== null && $altipo_low !== '') {
            $alumno['tipo_doc'] = (int) $decidido;
            $this->equivalenciasUsadas['tipo_de_documento'] = ($this->equivalenciasUsadas['tipo_de_documento'] ?? 0) + 1;
        }

        for ($i = 0; $i < $this->cant_td; $i++) {

            $tipo_low = $this->normalizar($this->tipos_doc[$i]->tipo);
            $abrev_low = $this->normalizar($this->tipos_doc[$i]->abrev);

            // La celda vacia NO compara. Si el catalogo del colegio tuviera una
            // fila con `abrev` vacia —es varchar y nadie lo impide—, un ''=='' la
            // daria por buena y le pondria ESE tipo al alumno. Un valor ausente
            // no puede casar con nada.
            if ($altipo_low !== '' && ($tipo_low === $altipo_low || $abrev_low === $altipo_low)) {
                $alumno['tipo_doc'] = $this->tipos_doc[$i]->id;
            }
            if ($A1tipo_low !== '' && ($tipo_low === $A1tipo_low || $abrev_low === $A1tipo_low)) {
                $alumno['tipo_docu_acud1'] = $this->tipos_doc[$i]->id;
            }
            if ($A2tipo_low !== '' && ($tipo_low === $A2tipo_low || $abrev_low === $A2tipo_low)) {
                $alumno['tipo_docu_acud2'] = $this->tipos_doc[$i]->id;
            }
        }
        if (! array_key_exists('tipo_doc', $alumno)) {
            // «No reconoci lo que puso» y «no puso nada» acaban los dos aqui, y
            // hasta hoy eran indistinguibles: los dos salian Tarjeta de Identidad
            // sin error ni log. Son cosas muy distintas — un colegio que escribio
            // «Registro civil de nacimiento» no esta pidiendo el defecto, esta
            // diciendo otra cosa que no supimos leer.
            //
            // Lo que se GUARDA no cambia: cambiarlo aqui le cambiaria el resultado
            // a dieciseis colegios sin que nadie lo pida. Lo que cambia es que el
            // caso malo deja rastro, para que la pantalla pueda preguntar.
            if ($altipo_low !== '') {
                $this->avisos[] = [
                    'fila' => $alumno['numero_matricula'] ?? null,
                    'campo' => 'tipo_de_documento',
                    'valor' => (string) $alumno['tipo_de_documento'],
                    'motivo' => 'No coincide con ningun tipo de documento del colegio; '
                              .'se guardo Tarjeta de Identidad por defecto.',
                ];
            }

            $alumno['tipo_doc'] = 3; // 3 es Tarjeta de identidad
        }

        $alumno['no_matricula'] = $alumno['numero_matricula'];
        if (is_int($alumno['fecha_de_nacim'])) {
            $alumno['fecha_de_nacim'] = Carbon::parse('30-12-1899')->addDays($alumno['fecha_de_nacim'])->format('Y-m-d');
        }

        // ciudad de doc y ciudad de nac
        //
        // Estas tres SI se concatenan y aguantan, pero el motivo no es «sale de
        // la base»: eso dejo de valer con la inyeccion de segundo orden de
        // `putSincronizarCumples` (05 §61), donde lo concatenado era una columna
        // que escribe otra ruta desde `Request::input`. Aqui aguanta porque lo
        // que entra es `->id` —entero autoincremental—, no `->ciudad`, que es
        // varchar(255) y SI lo escribe `CiudadesController::postGuardarCiudad`
        // desde el cuerpo. Si algun dia se concatena el nombre en vez del id,
        // esto es inyeccion.
        // Las cinco columnas de ciudad se normalizan UNA vez y no dentro del
        // bucle: son ~1.100 ciudades por cinco comparaciones y por alumno.
        // Y va con `normalizar()` por lo de siempre, que aqui muerde mas que en
        // ninguna parte: las ciudades colombianas llevan tilde —Medellin,
        // Bogota, Ibague, Cucuta— asi que con `strtolower` el Excel que las
        // escribe bien era el que no casaba.
        $ciu = [];
        foreach (['lugar_de_expedicion_ciudad', 'ciudad_nacimiento', 'ciudad_residencia',
            'ciudad_docu_acud1', 'ciudad_docu_acud2'] as $campo) {
            $ciu[$campo] = $this->normalizar($alumno[$campo] ?? null);
        }

        for ($i = 0; $i < $this->cant_ciud; $i++) {
            $ciudad_low = $this->normalizar($this->ciudades[$i]->ciudad);

            if (($ciu['lugar_de_expedicion_ciudad'] !== '' && $ciudad_low === $ciu['lugar_de_expedicion_ciudad']) || $this->ciudades[$i]->id == $alumno['lugar_de_expedicion_ciudad']) {
                $cons .= ', ciudad_doc='.$this->ciudades[$i]->id;
            }
            if (($ciu['ciudad_nacimiento'] !== '' && $ciudad_low === $ciu['ciudad_nacimiento']) || $this->ciudades[$i]->id == $alumno['ciudad_nacimiento']) {
                $cons .= ', ciudad_nac='.$this->ciudades[$i]->id;
            }
            if (($ciu['ciudad_residencia'] !== '' && $ciudad_low === $ciu['ciudad_residencia']) || $this->ciudades[$i]->id == $alumno['ciudad_residencia']) {
                $cons .= ', ciudad_resid='.$this->ciudades[$i]->id;
            }
            if (($ciu['ciudad_docu_acud1'] !== '' && $ciudad_low === $ciu['ciudad_docu_acud1']) || $this->ciudades[$i]->id == $alumno['ciudad_docu_acud1']) {
                $consA1 .= ', ciudad_doc='.$this->ciudades[$i]->id;
                $ciudad_id_A1 = $this->ciudades[$i]->id;
            }
            if (($ciu['ciudad_docu_acud2'] !== '' && $ciudad_low === $ciu['ciudad_docu_acud2']) || $this->ciudades[$i]->id == $alumno['ciudad_docu_acud2']) {
                $consA2 .= ', ciudad_doc='.$this->ciudades[$i]->id;
                $ciudad_id_A2 = $this->ciudades[$i]->id;
            }
        }

        // is_urbana
        //
        // Con `normalizar()` en vez de `strtolower`: «Si» es como se escribe bien
        // en espanol y hasta hoy NO casaba con 'si', asi que no entraba en ninguna
        // de las dos ramas y la columna se quedaba como estuviera. Lo mismo abajo
        // con SISBEN, «nuevo» y los dos acudientes. Y normalizar recorta, que
        // arregla de paso el «no aplica » con espacio detras: hoy ese cae en el
        // `else` y se guarda como que SI tiene SISBEN.
        if ($this->normalizar($alumno['urbana'] ?? null) == 'si') {
            $cons .= ', is_urbana=1';
        } elseif ($this->normalizar($alumno['urbana'] ?? null) == 'no') {
            $cons .= ', is_urbana=0';
        }

        // EL ESTADO DE LA MATRÍCULA, que hasta hoy no pasaba por aquí.
        //
        // Lo leía el importador directamente, así que una equivalencia puesta
        // allí la vería la subida y NO el ensayo — y el plan enseñaría un estado
        // distinto del que se va a escribir. Se traduce aquí porque aquí pasan
        // los dos.
        //
        // «Activo» no se traduce solo, y eso no cambia: podría ser MATR o ASIS y
        // lo decide el colegio. Lo que cambia es que ahora, cuando alguien lo ha
        // decidido, su decisión llega.
        $estadoLow = $this->normalizar($alumno['estado_matricula'] ?? null);
        $estadoDecidido = $this->equivalencias['estado_matricula'][$estadoLow] ?? null;

        if ($estadoDecidido !== null && $estadoLow !== '') {
            $alumno['estado_matricula'] = $estadoDecidido;
            $this->equivalenciasUsadas['estado_matricula'] = ($this->equivalenciasUsadas['estado_matricula'] ?? 0) + 1;
        }

        // SISBEN
        if ($this->normalizar($alumno['sisben'] ?? null) == 'no aplica' || $this->normalizar($alumno['sisben'] ?? null) == '') {
            $cons .= ', has_sisben=0, nro_sisben=null';
        } else {
            $cons .= ', has_sisben=1, nro_sisben=?';
            $valores[] = $alumno['sisben'];
        }

        // SISBEN 3
        if ($this->normalizar($alumno['sisben_3'] ?? null) == 'no aplica' || $this->normalizar($alumno['sisben_3'] ?? null) == '') {
            $cons .= ', has_sisben_3=0, nro_sisben_3=null';
        } else {
            $cons .= ', has_sisben_3=1, nro_sisben_3=?';
            $valores[] = $alumno['sisben_3'];
        }

        // Nuevo
        if ($this->normalizar($alumno['nuevo'] ?? null) == 'no' || $this->normalizar($alumno['nuevo'] ?? null) == '') {
            $alumno['es_nuevo'] = 0;
        } elseif ($this->normalizar($alumno['nuevo'] ?? null) == 'si') {
            $alumno['es_nuevo'] = 1;
        }

        // Es acudiente 1
        if ($this->normalizar($alumno['es_el_acudiente_acud1'] ?? null) == 'no' || $this->normalizar($alumno['es_el_acudiente_acud1'] ?? null) == '') {
            $alumno['is_acudiente1'] = 0;
            // Debugging::pin('$alumno["es_el_acudiente_acud1"]=="no" ', $alumno["es_el_acudiente_acud1"]);
        } elseif ($this->normalizar($alumno['es_el_acudiente_acud1'] ?? null) == 'si') {
            $alumno['is_acudiente1'] = 1;
            // Debugging::pin('$alumno->es_el_acudiente_acud1=="SI" ');
        }

        // Es acudiente 2
        if ($this->normalizar($alumno['es_el_acudiente_acud2'] ?? null) == 'no' || $this->normalizar($alumno['es_el_acudiente_acud2'] ?? null) == '') {
            $alumno['is_acudiente2'] = 0;
        } elseif ($this->normalizar($alumno['es_el_acudiente_acud2'] ?? null) == 'si') {
            $alumno['is_acudiente2'] = 1;
        }

        // Parentesco 1
        if (is_null($alumno['parentesco_acud1']) || $alumno['parentesco_acud1'] == '') {
            $alumno['parentesco_acud1'] = 'Madre';
        }
        // Parentesco 2
        if (is_null($alumno['parentesco_acud2']) || $alumno['parentesco_acud2'] == '') {
            $alumno['parentesco_acud2'] = 'Madre';
        }

        return ['consulta' => $cons, 'valores' => $valores, 'consultaA1' => $consA1, 'consultaA2' => $consA2, 'ciudad_id_A1' => $ciudad_id_A1, 'ciudad_id_A2' => $ciudad_id_A2];

    }

    public function valorAcudiente($acudiente_id, $parentesco_id, $user_acud_id, $propiedad, $valor, $user_id)
    {

        $consulta = '';
        $datos = [];
        $now = Carbon::now('America/Bogota');

        if ($propiedad == 'fecha_nac') {
            $valor = Carbon::parse($valor);
        }

        switch ($propiedad) {
            case 'username':
                $consulta = 'UPDATE users SET username=:valor, updated_by=:modificador, updated_at=:fecha WHERE id=:user_id';
                $datos = [':valor' => $valor, ':modificador' => $user_id, ':fecha' => $now, ':user_id' => $user_acud_id];
                break;

            case 'parentesco':
                $consulta = 'UPDATE parentescos SET parentesco=:valor, updated_by=:modificador, updated_at=:fecha WHERE id=:parentesco_id';
                $datos = [':valor' => $valor, ':modificador' => $user_id, ':fecha' => $now, ':parentesco_id' => $parentesco_id];
                break;

            default:
                $consulta = 'UPDATE acudientes SET '.ColumnaSegura::exigir('acudientes', $propiedad).'=:valor, updated_by=:modificador, updated_at=:fecha WHERE id=:acudiente_id';
                $datos = [
                    ':valor' => $valor,
                    ':modificador' => $user_id,
                    ':fecha' => $now,
                    ':acudiente_id' => $acudiente_id,
                ];
                break;
        }

        $res = DB::update($consulta, $datos);

        if ($res) {
            return 'Guardado';
        } else {
            return 'No guardado';
        }

    }
}
