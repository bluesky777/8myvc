<?php

namespace App\Http\Controllers\Alumnos;

use App\Http\Controllers\Concerns\ResuelveElUsuario;
use App\Http\Controllers\Controller;
use App\Models\Alumno;
use App\Models\Debugging;
use App\Models\Matricula;
use App\Models\Role;
use App\Services\EnsayoDeLaImportacion;
use App\Services\PuntoDeControlDeImportacion;
use App\Services\RespuestasDeLaImportacion;
use App\Support\EstadosDeMatricula;
use App\Support\Reloj;
use App\Support\SafeUpload;
use App\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Request;
use Maatwebsite\Excel\Concerns\ToArray;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Events\BeforeSheet;
use Maatwebsite\Excel\Facades\Excel;

class AlumnoSheetImport implements ToCollection, WithHeadingRow
{
    public function collection(Collection $rows)
    {
        return $rows;
    }

    public function headingRow(): int
    {
        return 2;
    }
}
class AlumnosImport implements WithMultipleSheets
{
    public function sheets(): array
    {
        return [
            new AlumnoSheetImport,
        ];
    }
}
class ExcelUtils implements ToArray, WithEvents, WithHeadingRow
{
    public $sheetNames;

    public $sheetData;

    public $year;

    public $fixer;

    /**
     * El punto de control por el que se sabe qué filas están ya aplicadas.
     *
     * Lo abre el controlador, no esta clase: la huella con la que se reconoce
     * «el mismo archivo» se saca del fichero subido, y aquí solo llegan las
     * hojas ya leídas.
     */
    public $punto;

    /** Lo que la persona decidió en la pantalla. Nunca es null: sin ella, los defectos de siempre. */
    public RespuestasDeLaImportacion $respuestas;

    /**
     * Qué hizo de verdad, contado mientras lo hace.
     *
     * Lo pide la pantalla del ensayo: el informe final **compara lo prometido
     * con lo hecho**, y para eso los dos tienen que hablar de lo mismo. Hasta
     * hoy la respuesta era la cadena `'Importados.'` y estos números no los
     * sabía nadie — ni siquiera cuántas filas eran, que `importaciones` sí
     * guarda pero sin repartirlas entre crear y actualizar.
     *
     * `reencontrados` va aparte de `actualizados` a propósito: son las filas
     * que venían **sin `id`** y aun así no se crearon, porque su documento ya
     * estaba. Es la idempotencia trabajando, y es el número que dice cuántos
     * alumnos duplicados se habrían creado antes del 20 ago 2026.
     *
     * @var array<string, int>
     */
    public $hechos = [
        'filas' => 0,
        'creados' => 0,
        'actualizados' => 0,
        'reencontrados' => 0,
        'usuarios_creados' => 0,
        'matriculas_creadas' => 0,

        // OJO CON ESTAS DOS, QUE SE LLAMABAN IGUAL Y NO SON LO MISMO.
        //
        // `ya_estaban_hechas` son las filas que el punto de control da por
        // aplicadas en una tanda anterior: existen, están bien, y esta subida no
        // vuelve a pasar por ellas. `sin_primer_nombre` son las que el
        // importador NO ESCRIBE NUNCA, porque sin primer nombre no crea alumno.
        //
        // La primera versión llamaba `saltadas` a la primera, y el ensayo llama
        // `se_saltan` a la segunda. La pantalla del escenario 9 compara lo
        // prometido con lo hecho, así que restar esos dos números habría dado una
        // diferencia inventada **y con aspecto de fallo real**. Se renombran el
        // mismo día en que alguien fue a compararlos, y antes de que exista un
        // solo cliente que los lea.
        'ya_estaban_hechas' => 0,
        'sin_primer_nombre' => 0,

        // Lo que las cuatro secciones nuevas de decisiones hacen de verdad, para
        // que el informe final pueda cerrar la frase «se aplicaron las que
        // aprobaste». Una decisión que no se puede contar es una decisión que
        // nadie puede comprobar.
        'hojas_omitidas' => 0,
        'repetidos_omitidos' => 0,
        'duplicados_descartados' => 0,
        'columnas_conservadas' => 0,
    ];

    /** Las hojas que se saltaron porque la persona lo pidió, con su nombre. @var list<string> */
    public array $hojasOmitidas = [];

    /**
     * Cuántas filas entran en una transacción, y por tanto **cada cuánto se
     * escribe la marca**.
     *
     * No es un número de rendimiento suelto: es **el grano del failover**. Con
     * 25, un corte pierde como mucho 25 filas de trabajo —que se rehacen solas
     * al reanudar, porque el importador es idempotente por documento— y la marca
     * se escribe 25 veces menos. Con 1 se vuelve al comportamiento anterior.
     *
     * Se queda corto a propósito: rehacer 25 filas cuesta milisegundos, y una
     * transacción larga sobre `alumnos` en una MariaDB compartida la pagan los
     * demás.
     */
    public const FILAS_POR_LOTE = 25;

    /** El de verdad, que el colegio puede estrechar por `.env`. Ver `config/importacion.php`. */
    private function filasPorLote(): int
    {
        return max(1, (int) config('importacion.filas_por_lote', self::FILAS_POR_LOTE));
    }

    /**
     * Cuándo hay que parar y devolver el control, en segundos desde que empezó.
     *
     * **Es lo que convierte esto en algo que no se puede cortar.** cPanel tiene
     * `max_execution_time` en 300 s y **no hay cola** —`QUEUE_CONNECTION` es
     * `sync` y no existe un solo `app/Jobs`—, así que una importación grande no
     * puede irse a segundo plano: o cabe en la petición o se pierde. Lo que sí
     * puede es **caber en varias**.
     *
     * 20 s deja margen bajo cualquier tope razonable —el de PHP, el del proxy,
     * el del navegador— y hace que el peor caso de un corte sea perder 20 s de
     * trabajo en vez de los 300 que tardaba en morir.
     */
    public const SEGUNDOS_POR_PETICION = 20;

    /** Cuándo se acaba el tiempo de esta petición. */
    private float $limite;

    /** Si se paró por tiempo: es lo que hace que la respuesta diga «falta». */
    public bool $agotado = false;

    /** Filas de datos que tiene el archivo, contadas hoja a hoja según se leen. */
    public int $filasDelArchivo = 0;

    public function __construct($year, $fixer, PuntoDeControlDeImportacion $punto, ?float $segundos = null,
        ?RespuestasDeLaImportacion $respuestas = null)
    {
        $this->sheetNames = [];
        $this->sheetData = [];
        $this->year = $year;
        $this->fixer = $fixer;
        $this->punto = $punto;

        // **Sin respuestas se comporta como siempre**, que es lo que hace que
        // esto sea aditivo: cada `queHacerCon…()` devuelve el defecto de la Fase
        // 1 cuando nadie decidió nada.
        $this->respuestas = $respuestas ?? new RespuestasDeLaImportacion([]);
        $this->limite = microtime(true)
            + ($segundos ?? (float) config('importacion.segundos_por_peticion', self::SEGUNDOS_POR_PETICION));
    }

    /** Si ya no queda tiempo en esta petición. */
    private function sinTiempo(): bool
    {
        return microtime(true) >= $this->limite;
    }

    /**
     * Una hoja del archivo, que es un grupo.
     *
     * maatwebsite llama a esto una vez por pestaña y le pasa las filas ya
     * leídas; el nombre de la pestaña llega por el evento BeforeSheet, no por
     * aquí, y de ahí el `sheetNames` de la primera línea.
     */
    public function array(array $array)
    {
        $sheetName = $this->sheetNames[count($this->sheetNames) - 1];
        $this->sheetData[$sheetName] = $array;
        $now = Carbon::now('America/Bogota');
        $abrev = $sheetName;

        // **El total se cuenta SIEMPRE, incluso agotado el tiempo.** maatwebsite
        // llama a este método para todas las pestañas aunque nosotros ya no
        // escribamos, así que aquí es donde se sabe cuántas filas tiene el
        // archivo entero — y sin ese número la respuesta no puede decir «faltan
        // 1.200», que es lo único que le dice al front si tiene que volver.
        $this->filasDelArchivo += count($array);

        // Se acabó el tiempo en una pestaña anterior: ésta ni se mira. Y NO se
        // cuentan como `ya_estaban_hechas`, que significa otra cosa —aplicadas en
        // una tanda anterior— y confundirlas haría que el informe final dijera
        // que se hizo un trabajo que no se hizo.
        if ($this->agotado) {
            return;
        }

        // Una hoja que quedó entera detrás del punto de control se salta sin
        // mirar siquiera qué grupo era. Reanudar un archivo de dieciséis
        // pestañas por la última no puede costar dieciséis consultas de grupo.
        if (count($array) > 0 && $this->punto->yaProcesada($abrev, count($array) - 1)) {
            $this->hechos['ya_estaban_hechas'] += count($array);

            return;
        }

        $consulta = 'SELECT g.id, g.abrev, g.year_id FROM grupos g inner join years y on y.id=g.year_id WHERE g.abrev=? and g.deleted_at is null and y.deleted_at is null and y.year=?;';
        $grupos = DB::select($consulta, [$abrev, $this->year]);

        // Esto era `DB::select(...)[0]`, y una pestaña cuyo nombre no fuera el
        // de ningún grupo del año daba «Undefined array key 0» — que no dice
        // cuál era la pestaña, y es el fallo corriente cuando alguien sube la
        // hoja del año pasado. Sigue siendo un 500, porque cambiarlo es tocar
        // el contrato de la pantalla; lo que cambia es que ahora se puede leer
        // en `importaciones.error` con el nombre dentro.
        if (count($grupos) === 0) {
            // **Saltarla es una decisión de la persona, no del código.** Por
            // defecto sigue parando la importación entera, que es lo que hace
            // desde siempre; `omitir` es la salida para el caso corriente que eso
            // castiga —la hoja de notas, la de instrucciones o la del año pasado
            // que alguien dejó dentro del libro—.
            //
            // Y se declara: la respuesta dice cuáles se saltaron. Una hoja que
            // desaparece en silencio es un grupo entero sin importar y nadie
            // enterándose.
            if ($this->respuestas->queHacerConHoja($abrev) === RespuestasDeLaImportacion::HOJA_OMITIR) {
                $this->hechos['hojas_omitidas']++;
                $this->hojasOmitidas[] = $abrev;

                return;
            }

            throw new \RuntimeException("La hoja '".$abrev."' no corresponde a ningún grupo del año ".$this->year.'.');
        }

        $grupo = $grupos[0];
        $results = $array;

        $total = count($results);

        // **Los duplicados se resuelven ANTES de escribir, no mientras se
        // escribe.** Saber cuál gana exige haber visto la hoja entera, y el
        // bucle va por lotes: decidirlo sobre la marcha haría que la respuesta
        // dependiera de dónde cayó el corte del lote.
        $descartadas = $this->filasQueDescartaElDuplicado($results);

        $porLote = $this->filasPorLote();

        for ($desde = 0; $desde < $total; $desde += $porLote) {
            $hasta = min($desde + $porLote, $total) - 1;

            // **El tiempo se mira ENTRE lotes y no dentro.** Un lote empezado se
            // termina: cortarlo por la mitad no ahorra nada —la transacción haría
            // rollback y ese trabajo se perdería— y en cambio rompe el
            // invariante. Lo que cuesta mirarlo aquí es que la petición se pasa,
            // como mucho, lo que tarde un lote.
            //
            // ## Y NUNCA SE PARA SIN HABER ESCRITO NADA, que es un bucle infinito
            //
            // El presupuesto se cuenta **desde antes de leer el libro** —a
            // propósito: leer también gasta—, así que un libro grande en un
            // servidor lento puede agotarlo **antes del primer lote**. Sin esta
            // segunda condición la petición contestaría «hice 0, faltan N», la
            // siguiente haría exactamente lo mismo, y el importador diría «falta»
            // para siempre sin avanzar una fila.
            //
            // Lo cazó `myvc-front-a6` al cablear el bucle: su pantalla lleva una
            // guarda que para cuando una vuelta no escribe nada, y **esa guarda
            // era la única red**. Un cliente no puede ser lo que impide que el
            // servidor se quede en bucle.
            //
            // Con esto, cada petición escribe **al menos un lote** pase lo que
            // pase con el reloj: si va lenta avanzará de 25 en 25, pero avanza.
            // *Lo único que no puede arreglarse aquí es que un solo lote no quepa
            // en el tope de PHP; para eso está `filas_por_lote` en el `.env`.*
            if ($this->sinTiempo() && $this->hechos['filas'] > 0) {
                $this->agotado = true;

                return;
            }

            // Las filas del lote y su marca, en la MISMA transacción.
            //
            // Ahí está toda la garantía: una fila de alumno son ocho escrituras
            // —alumno, usuario, rol, matrícula y los dos acudientes con sus
            // parentescos— y sin transacción el proceso puede morir con tres
            // hechas. Antes eso dejaba medio alumno en la base y nadie sabía
            // cuál; ahora **el lote entero entra o no entra**, y el punto de
            // control no puede mentir porque se guarda con él.
            //
            // La marca se escribe **una vez por lote** y no una por fila:
            // `apuntar()` la mueve en memoria y `volcar()` la escribe al final,
            // dentro de la misma transacción. Si el lote falla se van las filas y
            // la marca a la vez, que es exactamente lo que tiene que pasar.
            // `$descartadas` va en el `use` y no es un detalle: un closure no captura
            // nada por su cuenta, así que sin esto `isset($descartadas[$f])` mira una
            // variable indefinida, **da falso sin error** y la decisión de la persona
            // no se aplica en silencio. Costó un rojo que no señalaba a este sitio.
            DB::transaction(function () use ($results, $desde, $hasta, $grupo, $abrev, $now, $descartadas) {
                $huboAlguna = false;

                for ($f = $desde; $f <= $hasta; $f++) {
                    if ($this->punto->yaProcesada($abrev, $f)) {
                        $this->hechos['ya_estaban_hechas']++;

                        continue;
                    }

                    // La fila perdedora de un duplicado **se marca igual**: si no
                    // se apuntara, reanudar volvería a pasar por ella y la
                    // decisión duraría lo que durase la petición.
                    if (isset($descartadas[$f])) {
                        $this->hechos['duplicados_descartados']++;
                        $this->punto->apuntar($abrev, $f);
                        $huboAlguna = true;

                        continue;
                    }

                    $this->hechos['filas']++;
                    $this->procesarFila($results[$f], $grupo, $abrev, $now);
                    $this->punto->apuntar($abrev, $f);
                    $huboAlguna = true;
                }

                // Un lote entero ya aplicado no reescribe la marca: reanudar un
                // archivo por la última pestaña no puede costar una escritura por
                // cada lote que ya estaba hecho.
                if ($huboAlguna) {
                    $this->punto->volcar();
                }
            });
        }
    }

    /**
     * Qué filas de esta hoja pierden por ser el duplicado de otra.
     *
     * Hoy gana **la última** porque el importador procesa en orden y la segunda
     * pasada pisa a la primera — no porque nadie lo eligiera. Que se pueda pedir
     * `primera` es lo que convierte ese accidente en una decisión.
     *
     * Con `ultima` no se descarta nada y el comportamiento es idéntico al de
     * siempre: la última sigue ganando porque escribe la última. Lo que cambia
     * con `primera` es que **las siguientes no llegan a escribir**, que es lo
     * único que impide que la de abajo pise a la de arriba.
     *
     * @param  array<int, array<string, mixed>>  $filas
     * @return array<int, true> índices de las filas que no se escriben
     */
    private function filasQueDescartaElDuplicado(array $filas): array
    {
        $vistos = [];
        $descartadas = [];

        foreach ($filas as $i => $fila) {
            $documento = trim((string) ($fila['nro_de_documento'] ?? ''));

            if ($documento === '') {
                continue;
            }

            if (! isset($vistos[$documento])) {
                $vistos[$documento] = $i;

                continue;
            }

            // Ya salió antes. Con `primera` pierde ésta; con `ultima` —el
            // defecto— pierde la anterior, que es lo que ya pasaba de hecho.
            if ($this->respuestas->cualGanaEnDuplicado($documento) === RespuestasDeLaImportacion::DUPLICADO_PRIMERA) {
                $descartadas[$i] = true;

                continue;
            }

            $vistos[$documento] = $i;
        }

        return $descartadas;
    }

    /**
     * Lo que hace falta hacer con una fila de la hoja: crear o actualizar al
     * alumno, su usuario, su matrícula y sus dos acudientes.
     *
     * Era el cuerpo del `for` de arriba; sale a su propio método para poder
     * envolverlo en una transacción.
     */
    private function procesarFila($alumno, $grupo, $abrev, $now)
    {

        $res = $this->fixer->verificar($alumno, $this->year);
        $alumno['ciudad_docu_acud1'] = $res['ciudad_id_A1'];
        $alumno['ciudad_docu_acud2'] = $res['ciudad_id_A2'];

        // Idempotencia por la clave natural, que es la otra mitad de poder
        // reanudar: saber por dónde ibas no sirve si volver a pasar por una
        // fila crea un alumno repetido.
        //
        // La hoja trae el `id` de los alumnos que ya estaban y lo trae vacío
        // para los nuevos, así que hasta hoy «vacío» significaba «créalo»,
        // sin mirar si ese documento ya estaba en la base. Eso duplicaba —
        // alumno, usuario y matrícula— en dos casos reales: la importación
        // que se cortó y se volvió a subir, y el alumno que cambia de grupo y
        // alguien escribe a mano en la hoja del grupo nuevo.
        //
        // El documento es la clave natural, y el importador ya lo usaba como
        // tal en el de cartera (`UPDATE alumnos ... WHERE documento=?`).
        $reencontrado = false;

        if (! $alumno['id']) {
            $id = $this->idPorDocumento($alumno['nro_de_documento']);

            if ($id !== null) {
                // **Y la persona puede decir que no lo toque.** Por defecto se
                // actualiza —es la idempotencia del 20 ago y lo que evita crear
                // duplicados—, pero en una hoja de alumnos NUEVOS un documento
                // que ya existe es un error de quien la llenó, y machacar la
                // ficha buena con esa fila es el daño, no el arreglo.
                if ($this->respuestas->queHacerConRepetido((string) $alumno['nro_de_documento'])
                    === RespuestasDeLaImportacion::REPETIDO_OMITIR) {
                    $this->hechos['repetidos_omitidos']++;

                    return;
                }

                $alumno['id'] = $id;
                $reencontrado = true;
            }
        }

        // Los nombres se recomponen de dos columnas, y se recortan porque la
        // segunda casi siempre viene vacía: un alumno de un solo nombre de
        // pila quedaba guardado como 'Irene ', con el espacio dentro. Lo
        // destapó el test de ida y vuelta —exportar e importar lo exportado—,
        // que hasta este arreglo cambiaba a 68 de los 68 alumnos del seed.

        if ($alumno['id']) {
            $this->hechos['actualizados']++;

            if ($reencontrado) {
                $this->hechos['reencontrados']++;
            }

            // **UNA CELDA VACÍA NO DICE «no sé», DICE «bórralo».**
            //
            // Este `UPDATE` escribe todas las columnas, así que una celda en
            // blanco vacía la que hubiera — lo dice el propio catálogo del
            // ensayo: *«Se BORRA la que hubiera, porque el UPDATE escribe todas
            // las columnas»*. Es el comportamiento de siempre y **se conserva por
            // defecto**: cambiarlo sin que nadie lo pida sería decidir por el
            // colegio.
            //
            // Lo que entra el 21 sep 2026 es poder decir **`conservar`** por
            // columna, y entonces esa columna sale del `SET`. No se escribe el
            // valor viejo —eso exigiría leerlo antes y sería otra consulta por
            // fila—: simplemente **no se toca**, que es más barato y no puede
            // equivocarse.
            $columnas = [
                // **`no_matricula` se escribía DOS VECES en el mismo `SET`** —una
                // con `$alumno['no_matricula']` y otra con
                // `$alumno['numero_matricula']`— y ganaba la segunda. Es el mismo
                // defecto que el `nro_sisben` que ya está documentado, en la misma
                // consulta y por la misma puerta.
                //
                // Al colapsarlo se conserva **el valor que ganaba**, no el que se
                // escribía primero: cambiar cuál gana mientras se reordena una
                // consulta sería colar una decisión dentro de un refactor.
                'numero_matricula' => ['no_matricula', $alumno['numero_matricula']],
                'primer_nombre' => ['nombres', trim($alumno['primer_nombre'].' '.$alumno['segundo_nombre'])],
                'primer_apellido' => ['apellidos', trim($alumno['primer_apellido'].' '.$alumno['segundo_apellido'])],
                'sexo' => ['sexo', $alumno['sexo']],
                'fecha_de_nacim' => ['fecha_nac', $alumno['fecha_de_nacim']],
                'tipo_de_documento' => ['tipo_doc', $alumno['tipo_doc']],
                'nro_de_documento' => ['documento', $alumno['nro_de_documento']],
                'direccion_residencia' => ['direccion', $alumno['direccion_residencia']],
                'barrio' => ['barrio', $alumno['barrio']],
                'telefono' => ['telefono', $alumno['telefono']],
                'celular' => ['celular', $alumno['celular']],
                'estrato' => ['estrato', $alumno['estrato']],
                'rh' => ['tipo_sangre', $alumno['rh']],
                'eps' => ['eps', $alumno['eps']],
                'religion' => ['religion', $alumno['religion']],
                'sisben' => ['nro_sisben', $alumno['sisben']],
            ];

            $aConservar = $this->respuestas->columnasAConservar();
            $sets = [];
            $valores = [];

            foreach ($columnas as $deLaHoja => [$enLaBase, $valor]) {
                $vacia = $valor === null || trim((string) $valor) === '';

                if ($vacia && in_array($deLaHoja, $aConservar, true)) {
                    $this->hechos['columnas_conservadas']++;

                    continue;
                }

                $sets[] = $enLaBase.'=?';
                $valores[] = $valor;
            }

            $sets[] = 'updated_at=?';
            $valores[] = $now;

            // Los valores del fragmento van en medio: entra detras de `updated_at=?`
            // y delante del `WHERE id=?`, asi que el orden del array tiene que ser
            // el mismo. Ver 05 §60.
            $consulta = 'UPDATE alumnos SET '.implode(', ', $sets).$res['consulta'].' WHERE id=?';

            DB::update($consulta, array_merge($valores, $res['valores'], [$alumno['id']]));

            // `matriculas.estado` es varchar(4), y lo que llega aqui es texto de
            // una hoja de calculo. Un «Activo» se guardaba como «Acti», que NO
            // esta entre los codigos que usa el colegio —hoy en esta base son
            // MATR, RETI, PREM, FORM, ASIS, PREA y DESE—, asi que el alumno
            // desaparecia de la consulta que alimenta el export, el SIMAT y
            // todas las listas: existe, tiene matricula, y no sale en ninguna
            // parte. En el docker pasa callado (`sql_mode` vacio) y en la
            // MariaDB estricta de produccion aborta a media hoja. Las dos son
            // malas y son distintas.
            //
            // No se traduce —«Activo» podria ser MATR o ASIS y eso lo decide el
            // colegio, no este fichero—: lo que NO cabe simplemente no se
            // escribe, y la matricula conserva el estado que ya tenia. No
            // escribir es peor que escribir bien y mucho mejor que escribir
            // mal, porque un estado corrupto no se nota hasta que alguien echa
            // en falta a un alumno.
            // **Dos puertas, no una.** La Fase 1 cerró la del tamaño; la del
            // catálogo la encontró `myvc-front-51` el 21 sep 2026: `ACTV` cabe en
            // cuatro caracteres, se escribía tal cual y dejaba al alumno en un
            // estado que **no consulta ninguna query** — el mismo síntoma que el
            // truncado, por el camino contrario.
            $estadoCrudo = $alumno['estado_matricula'] === null
                ? null
                : (string) $alumno['estado_matricula'];

            $cabe = EstadosDeMatricula::cabe($estadoCrudo);
            $existe = $cabe && EstadosDeMatricula::existe($estadoCrudo);

            if ($cabe && $existe) {
                DB::update('UPDATE matriculas m INNER JOIN grupos g ON g.id=m.grupo_id and g.year_id=? and g.deleted_at is null SET m.nuevo=?, m.estado=?, m.updated_at=? WHERE m.alumno_id=? and m.deleted_at is null', [$grupo->year_id, $alumno['es_nuevo'], $alumno['estado_matricula'], $now, $alumno['id']]);
            } else {
                // `nuevo` si se escribe: no tiene nada que ver con el estado y
                // descartarlo tambien seria castigar dos columnas por una.
                DB::update('UPDATE matriculas m INNER JOIN grupos g ON g.id=m.grupo_id and g.year_id=? and g.deleted_at is null SET m.nuevo=?, m.updated_at=? WHERE m.alumno_id=? and m.deleted_at is null', [$grupo->year_id, $alumno['es_nuevo'], $now, $alumno['id']]);

                $this->fixer->avisos[] = [
                    'fila' => $alumno['numero_matricula'] ?? null,
                    'campo' => 'estado_matricula',
                    'valor' => (string) $alumno['estado_matricula'],
                    'motivo' => $cabe
                        ? 'No es ninguno de los estados que usa este colegio ('
                          .implode(', ', EstadosDeMatricula::delColegio()).'), asi que el alumno '
                          .'habria quedado en un estado que no consulta ninguna lista. '
                          .'La matricula conserva el estado que ya tenia.'
                        : 'No cabe en matriculas.estado (4 caracteres) y se habria '
                          .'guardado cortado, dejando al alumno fuera de las listas. '
                          .'La matricula conserva el estado que ya tenia.',
                ];
            }

            // **Aquí había un `Debugging::pin()` por alumno, y se retira el 20 sep
            // 2026 con la decisión de Joseth de hacer esto rápido y reanudable.**
            //
            // Su propio comentario ya decía que era el punto de control *de
            // antes* —una fila en `debugging` por alumno, para mirar a mano por
            // dónde iba la importación cuando el servidor la cortaba— y que ya no
            // hacía ese trabajo, porque lo hace `importaciones`, que el código
            // sabe leer y aquello no.
            //
            // Lo que faltaba era el precio, medido ese día: **una consulta por
            // fila, el 15 % de las del importador** —6,7 por fila, de las que 6
            // son las escrituras útiles— y **17.457 filas acumuladas** sólo en la
            // copia de desarrollo, que no se limpian nunca.
            //
            // **La tabla NO se borra y lo escrito sigue ahí**: es el único rastro
            // de las importaciones anteriores a hoy en las dieciséis bases, y
            // vaciarla es una limpieza aparte con su propia decisión. Lo que para
            // es que siga creciendo por un trabajo que ya hace otro.

            // Acudiente 1
            $this->modificar_acudiente1($alumno, $now, $res['consultaA1']);

            // Acudiente 2
            $this->modificar_acudiente2($alumno, $now, $res['consultaA2']);

            // Solo cuando se llegó aquí por el documento. Una fila que TRAÍA su
            // id sigue comportándose exactamente igual que antes: la matrícula
            // se actualiza, no se crea. Lo que se cubre es el alumno que la
            // hoja daba por nuevo y resultó existir — sin esto se quedaría
            // actualizado pero fuera del grupo de la pestaña.
            if ($reencontrado) {
                $this->asegurarMatricula($alumno['id'], $grupo, $now);
            }

        } else {

            $alumno_row = $alumno;

            if (! $alumno_row['primer_nombre']) {
                // La fila no crea nada y hasta hoy no dejaba rastro de ninguna
                // clase: ni error, ni aviso, ni número. El ensayo la cuenta como
                // `se_salta`, así que la subida tiene que contarla también o el
                // informe final no cuadra con lo que se prometió.
                $this->hechos['sin_primer_nombre']++;
            }

            if ($alumno_row['primer_nombre']) {
                $alumno = new Alumno;
                $alumno->nombres = trim($alumno_row['primer_nombre'].' '.$alumno_row['segundo_nombre']);
                $alumno->apellidos = trim($alumno_row['primer_apellido'].' '.$alumno_row['segundo_apellido']);
                $alumno->sexo = $alumno_row['sexo'] ? $alumno_row['sexo'] : 'M';
                $alumno->tipo_doc = $alumno_row['tipo_doc'];
                $alumno->documento = $alumno_row['nro_de_documento'];
                $alumno->no_matricula = $alumno_row['numero_matricula'];
                $alumno->direccion = $alumno_row['direccion_residencia'];
                $alumno->barrio = $alumno_row['barrio'];
                $alumno->fecha_nac = $alumno_row['fecha_de_nacim'];
                $alumno->telefono = $alumno_row['telefono'];
                $alumno->celular = $alumno_row['celular'];
                $alumno->estrato = $alumno_row['estrato'];
                $alumno->eps = $alumno_row['eps'];
                $alumno->tipo_sangre = $alumno_row['rh'];
                $alumno->religion = $alumno_row['religion'];
                $alumno->nro_sisben = $alumno_row['sisben'];
                $alumno->save();

                $this->hechos['creados']++;

                $alumno_row['id'] = $alumno->id;

                $opera = new OperacionesAlumnos;

                $usuario = new User;
                $usuario->username = $opera->username_no_repetido($alumno->nombres, 'alumno'.$alumno->id);
                $usuario->password = Hash::make('123456');
                $usuario->sexo = $alumno_row['sexo'] ? $alumno_row['sexo'] : 'M';
                $usuario->is_superuser = 0;
                $usuario->periodo_id = 1; // Verificar que haya un periodo cod 1
                $usuario->is_active = 1;
                $usuario->tipo = 'Alumno';
                $usuario->save();

                $this->hechos['usuarios_creados']++;

                $role = Role::where('name', 'Alumno')->get();
                // $usuario->attachRole($role[0]);
                $usuario->roles()->attach($role[0]['id']);

                $alumno->user_id = $usuario->id;
                $alumno->save();

                $matricula = new Matricula;
                $matricula->alumno_id = $alumno->id;
                $matricula->grupo_id = $grupo->id;
                $matricula->estado = 'MATR';
                $matricula->fecha_matricula = $now;
                $matricula->save();

                $this->hechos['matriculas_creadas']++;

                // Acudiente 1
                $this->modificar_acudiente1($alumno_row, $now, $res['consultaA1']);

                // Acudiente 2
                $this->modificar_acudiente2($alumno_row, $now, $res['consultaA2']);

            }

        }

    }

    /**
     * El id del alumno que ya tiene ese documento, si lo hay.
     *
     * `alumnos.documento` no es UNIQUE y no puede serlo: hay filas históricas
     * con el documento vacío o repetido, y un índice único ahí haría fallar el
     * despliegue en los colegios que las tengan. Se comprueba leyendo, y con
     * `ORDER BY id` para que dos filas repetidas den siempre la misma — una
     * importación que eligiera una u otra según el humor de MySQL sería peor
     * que la que duplicaba.
     *
     * ## Por qué el documento NO se convierte a cadena antes de atarlo
     *
     * Parece que habría que hacerlo y hay un `EXPLAIN` que lo pide, así que
     * aquí queda por qué no, medido el 19 sep 2026 en `simonbolivar`:
     *
     * | parámetro | type | key |
     * |---|---|---|
     * | `'1035123456'` | `ref` | `alumnos_documento_index` |
     * | `1035123456` | `index` | `PRIMARY` |
     *
     * O sea: **el índice que se creó el 20 ago se esquiva** cuando la celda del
     * Excel es numérica. Y aun así no se toca, por dos medidas:
     *
     * 1. **No cuesta.** 200 búsquedas: 36 ms como texto, **26 ms como número**.
     *    La tabla son 1.284 filas y recorrer el índice entero es más barato que
     *    el `ORDER BY id` que la otra forma tiene que resolver aparte. La
     *    hipótesis de «800 barridos contra los 300 s de cPanel» se midió y es
     *    falsa a esta escala. Vuélvase a medir en una base grande antes de
     *    darla por cierta allí.
     * 2. **Y convertir ROMPERÍA gente.** `'01035123456' = 1035123456` es cierto
     *    y `'01035123456' = '1035123456'` es falso. Hay **7 alumnos vivos con
     *    cero a la izquierda**: la comparación numérica de hoy los encuentra y
     *    la de cadena no. Pasarlo a texto crearía un duplicado de cada uno —
     *    exactamente el fallo que la §1 de `09-pendientes.md` vino a cerrar.
     *
     * Lo que sí hacía falta era **descartar el 0**, que es el único valor con
     * el que esa conversión implícita hace daño; está justo debajo.
     *
     * *El síntoma era real y la consecuencia que se le suponía, falsa. Antes de
     * actuar sobre un `EXPLAIN`, medir el tiempo y mirar a quién deja fuera.*
     */
    private function idPorDocumento($documento)
    {
        $documento = is_string($documento) ? trim($documento) : $documento;

        if ($documento === null || $documento === '') {
            return null;
        }

        // Un documento que vale CERO no busca: descarta.
        //
        // Cuando la celda del Excel es numérica, `$documento` llega como int y PDO
        // lo ata como número, así que MySQL convierte la COLUMNA para comparar. Con
        // un valor de verdad eso es inofensivo e incluso conveniente —ver el
        // comentario del método—, pero con un 0 deja de serlo: cualquier documento
        // que no sea un número se convierte también a 0, así que `documento = 0`
        // casaría con TODOS ellos y devolvería el primero por `ORDER BY id`. El
        // alumno de la fila se fundiría con un desconocido, en silencio y en 200.
        //
        // Medido el 19 sep 2026 en `simonbolivar`: `'AB1234' = 0` da 1, y dos
        // documentos no numéricos cualesquiera colisionan entre sí en 0. En esta
        // base hay 0 filas así, o sea que hoy no muerde AQUÍ — los otros quince
        // colegios no están medidos, y es una columna que rellena una secretaría.
        //
        // El `''` de arriba no cubre este caso: un 0 entero no es cadena vacía.
        if (is_int($documento) && $documento === 0) {
            return null;
        }

        $fila = DB::selectOne('SELECT id FROM alumnos WHERE documento = ? and deleted_at is null ORDER BY id LIMIT 1', [$documento]);

        return $fila === null ? null : (int) $fila->id;
    }

    /**
     * Matricula al alumno en el grupo de la pestaña si no lo estaba ya en ese
     * año.
     *
     * La comprobación es por AÑO y no por grupo, igual que el UPDATE de
     * matrículas de unas líneas más arriba: un alumno tiene una matrícula por
     * año, y crearle otra por haber aparecido en la pestaña de al lado lo
     * dejaría en dos grupos a la vez.
     */
    private function asegurarMatricula($alumno_id, $grupo, $now)
    {
        $ya = DB::selectOne('SELECT m.id FROM matriculas m INNER JOIN grupos g ON g.id=m.grupo_id
			WHERE m.alumno_id=? and g.year_id=? and m.deleted_at is null and g.deleted_at is null LIMIT 1',
            [$alumno_id, $grupo->year_id]);

        if ($ya !== null) {
            return;
        }

        $matricula = new Matricula;
        $matricula->alumno_id = $alumno_id;
        $matricula->grupo_id = $grupo->id;
        $matricula->estado = 'MATR';
        $matricula->fecha_matricula = $now;
        $matricula->save();
    }

    public function registerEvents(): array
    {
        return [
            BeforeSheet::class => function (BeforeSheet $event) {
                $this->sheetNames[] = $event->getSheet()->getDelegate()->getTitle();
            },
        ];
    }

    public function chunkSize(): int
    {
        return 100;
    }

    public function getSheetNames()
    {
        return $this->sheetNames;
    }

    public function headingRow(): int
    {
        return 2;
    }

    private function modificar_acudiente1(&$alumno, $now, $consulta)
    {

        $alumno['sexo_acud1'] = ((is_null($alumno['sexo_acud1']) || $alumno['sexo_acud1'] == '') ? 'M' : $alumno['sexo_acud1']);

        if ($alumno['id_acud1'] > 0 && (! (is_null($alumno['nombres_acud1']) || $alumno['nombres_acud1'] == ''))) {

            // Si tiene código y tiene nombre escrito, sólo quiere modificarlo
            DB::update('UPDATE acudientes SET nombres=?, apellidos=?, sexo=?, tipo_doc=?, documento=?, is_acudiente=?, telefono=?, celular=?, ocupacion=?, direccion=?, email=?, updated_at=?'.$consulta.' WHERE id=?',
                [$alumno['nombres_acud1'], $alumno['apellidos_acud1'], $alumno['sexo_acud1'], $alumno['tipo_docu_acud1'], $alumno['documento_acud1'], ($alumno['is_acudiente1'] ? $alumno['is_acudiente1'] : 1),
                    $alumno['telefono_acud1'], $alumno['celular_acud1'], $alumno['ocupacion_acud1'], $alumno['direccion_acud1'], $alumno['email_acud1'], $now, $alumno['id_acud1']]);

            DB::update('UPDATE parentescos p INNER JOIN acudientes a ON a.id=p.acudiente_id and p.alumno_id=? and p.acudiente_id=? and p.deleted_at is null and a.deleted_at is null 
				SET p.parentesco=?, p.observaciones=?, p.updated_at=?', [$alumno['id'], $alumno['id_acud1'], $alumno['parentesco_acud1'], $alumno['observaciones_acud1'], $now]);

        } elseif ($alumno['id_acud1'] > 0 && (is_null($alumno['nombres_acud1']) || $alumno['nombres_acud1'] == '')) {

            // Si tiene código y NO tiene nombre escrito, quiere añadirlo como nuevo acudiente de este alumno, NO modificarlo
            DB::insert('INSERT INTO parentescos(acudiente_id, alumno_id, parentesco, observaciones, created_at, updated_at) VALUES(?, ?, ?, ?, ?, ?)', [$alumno['id_acud1'], $alumno['id'], $alumno['parentesco_acud1'], $alumno['observaciones_acud1'], $now, $now]);

        } else {
            if (! (is_null($alumno['nombres_acud1']) || $alumno['nombres_acud1'] == '')) {
                DB::insert('INSERT INTO acudientes(nombres, apellidos, sexo, tipo_doc, documento, is_acudiente, telefono, celular, ocupacion, direccion, email, created_at, updated_at, ciudad_doc) VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [$alumno['nombres_acud1'], $alumno['apellidos_acud1'], $alumno['sexo_acud1'], $alumno['tipo_docu_acud1'], $alumno['documento_acud1'], ($alumno['is_acudiente1'] ? $alumno['is_acudiente1'] : 1),
                        $alumno['telefono_acud1'], $alumno['celular_acud1'], $alumno['ocupacion_acud1'], $alumno['direccion_acud1'], $alumno['email_acud1'], $now, $now, $alumno['ciudad_docu_acud1']]);

                $last_id = DB::getPdo()->lastInsertId();
                DB::insert('INSERT INTO parentescos(acudiente_id, alumno_id, parentesco, observaciones, created_at, updated_at) VALUES(?, ?, ?, ?, ?, ?)', [$last_id, $alumno['id'], $alumno['parentesco_acud1'], $alumno['observaciones_acud1'], $now, $now]);
            }
        }
    }

    private function modificar_acudiente2(&$alumno, $now, $consulta)
    {

        $alumno['sexo_acud2'] = ((is_null($alumno['sexo_acud2']) || $alumno['sexo_acud2'] == '') ? 'M' : $alumno['sexo_acud2']);

        if ($alumno['id_acud2'] > 0 && (! (is_null($alumno['nombres_acud2']) || $alumno['nombres_acud2'] == ''))) {

            // Si tiene código y tiene nombre escrito, sólo quiere modificarlo
            DB::update('UPDATE acudientes SET nombres=?, apellidos=?, sexo=?, tipo_doc=?, documento=?, is_acudiente=?, telefono=?, celular=?, ocupacion=?, direccion=?, email=?, updated_at=?'.$consulta.' WHERE id=?',
                [$alumno['nombres_acud2'], $alumno['apellidos_acud2'], $alumno['sexo_acud2'], $alumno['tipo_docu_acud2'], $alumno['documento_acud2'], ($alumno['is_acudiente2'] ? $alumno['is_acudiente2'] : 1),
                    $alumno['telefono_acud2'], $alumno['celular_acud2'], $alumno['ocupacion_acud2'], $alumno['direccion_acud2'], $alumno['email_acud2'], $now, $alumno['id_acud2']]);

            DB::update('UPDATE parentescos p INNER JOIN acudientes a ON a.id=p.acudiente_id and p.alumno_id=? and p.acudiente_id=? and p.deleted_at is null and a.deleted_at is null 
				SET p.parentesco=?, p.observaciones=?, p.updated_at=?', [$alumno['id'], $alumno['id_acud2'], $alumno['parentesco_acud2'], $alumno['observaciones_acud2'], $now]);

        } elseif ($alumno['id_acud2'] > 0 && (is_null($alumno['nombres_acud2']) || $alumno['nombres_acud2'] == '')) {

            // Si tiene código y NO tiene nombre escrito, quiere añadirlo como nuevo acudiente de este alumno, NO modificarlo
            DB::insert('INSERT INTO parentescos(acudiente_id, alumno_id, parentesco, observaciones, created_at, updated_at) VALUES(?, ?, ?, ?, ?, ?)', [$alumno['id_acud2'], $alumno['id'], $alumno['parentesco_acud2'], $alumno['observaciones_acud2'], $now, $now]);

        } else {
            if (! (is_null($alumno['nombres_acud2']) || $alumno['nombres_acud2'] == '')) {
                DB::insert('INSERT INTO acudientes(nombres, apellidos, sexo, tipo_doc, documento, is_acudiente, telefono, celular, ocupacion, direccion, email, created_at, updated_at, ciudad_doc) VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [$alumno['nombres_acud2'], $alumno['apellidos_acud2'], $alumno['sexo_acud2'], $alumno['tipo_docu_acud2'], $alumno['documento_acud2'], ($alumno['is_acudiente2'] ? $alumno['is_acudiente2'] : 1),
                        $alumno['telefono_acud2'], $alumno['celular_acud2'], $alumno['ocupacion_acud2'], $alumno['direccion_acud2'], $alumno['email_acud2'], $now, $now, $alumno['ciudad_docu_acud2']]);

                $last_id = DB::getPdo()->lastInsertId();
                DB::insert('INSERT INTO parentescos(acudiente_id, alumno_id, parentesco, observaciones, created_at, updated_at) VALUES(?, ?, ?, ?, ?, ?)', [$last_id, $alumno['id'], $alumno['parentesco_acud2'], $alumno['observaciones_acud2'], $now, $now]);
            }
        }
    }
}

class ImportarController extends Controller
{
    use ResuelveElUsuario;

    /**
     * La importación de alumnos: una pestaña por grupo, una fila por alumno.
     *
     * **Es reanudable desde el 20 ago 2026** (§1 de docs/migracion/09-pendientes.md).
     * Si el servidor la corta —`max_execution_time` son 300 s en cPanel, y está
     * en 300 por esto— volver a subir el MISMO archivo continúa por donde iba en
     * vez de empezar de cero. Quien lo hace es
     * App\Services\PuntoDeControlDeImportacion; el porqué de cada decisión está
     * ahí y no aquí.
     *
     * **La respuesta SÍ cambió, el 20 sep 2026**, y este bloque decía lo
     * contrario: «sigue siendo la cadena pelada 'Importados.', que es lo que
     * leen hoy los cuatro clientes». Lo segundo era falso y se midió: ninguno
     * de los cuatro lee el cuerpo cuando la importación va bien, y los dos de
     * `app2` lo piden como JSON, así que la cadena los mandaba por su rama de
     * error **después de importar bien**. El motivo de fondo del párrafo sigue
     * en pie —Flutter es UNA app para los dieciséis y por eso esto es
     * reanudable y no encolado (§3 de 09-pendientes)—; lo que no valía era dar
     * por conocido a un lector que no se había ido a mirar.
     */
    public function postAlgo($year)
    {
        // El catálogo de estados se cachea por petición —la importación pregunta
        // una vez por fila y son miles— y aquí se olvida, porque en los tests el
        // proceso es uno solo y un caso que estrena un estado tiene que verlo.
        EstadosDeMatricula::olvidar();

        if (Request::hasFile('file')) {
            $archivo = request()->file('file');
            $huella = hash_file('sha256', $archivo->getRealPath());

            $respuestas = new RespuestasDeLaImportacion($this->respuestasDelCuerpo() ?? []);

            // UNAS RESPUESTAS DE OTRO FICHERO NO SE APLICAN A ÉSTE.
            //
            // Es el agujero que este módulo lleva rodeando: alguien aprueba
            // trece decisiones, cambia una celda y sube. Las respuestas siguen
            // encajando —casan por nombre de columna, no por contenido— y se
            // aplicarían a un libro que nadie revisó. **Los números saldrían
            // igual, sólo que serían otros.**
            if ($respuestas->sonDeOtroFichero($huella)) {
                return response()->json([
                    'ok' => false,
                    'msg' => 'Las instrucciones que llegaron son de otro archivo. Vuelve a revisarlo antes de importar.',
                ], 422);
            }

            // EL CERROJO, y es lo que el troceado hizo necesario.
            //
            // `abrir()` busca la importación pendiente y sigue por donde iba, sin
            // bloquear nada: dos peticiones a la vez con el mismo archivo
            // **reanudan la misma fila**. Los datos sobreviven —el importador es
            // idempotente por documento— pero `filas` cuenta de más y el progreso
            // que ve la pantalla miente.
            //
            // El agujero ya existía; lo que cambia es que ahora **reenviar es el
            // funcionamiento normal**, así que dos pestañas abiertas o un doble
            // clic dejan de ser un caso raro.
            //
            // Se suelta en el `finally` de más abajo, y si el proceso muere se
            // suelta solo al caerse la conexión — que es justo lo que hace falta
            // en un importador que existe para sobrevivir a los cortes.
            if (! PuntoDeControlDeImportacion::tomarCerrojo('alumnos', $huella, (int) $year)) {
                return response()->json([
                    'ok' => false,
                    'msg' => 'Ese mismo archivo se está importando ahora mismo en otra ventana. '
                           .'Espera a que termine antes de volver a subirlo.',
                ], 409);
            }

            // La huella es del CONTENIDO del archivo, no de su nombre: la
            // secretaría sube tres veces `alumnos.xlsx` y son tres archivos
            // distintos. Es lo que hace que «volver a subir el mismo» tenga un
            // significado que el código pueda comprobar.
            $punto = PuntoDeControlDeImportacion::abrir(
                'alumnos',
                $huella,
                (int) $year,
                SafeUpload::nombreParaGuardar($archivo),
                $this->user->user_id
            );

            // Lo que la persona contestó en la pantalla viaja CON el fichero, no
            // por una ruta propia: es parte de «sube esto con estas
            // instrucciones», no un recurso aparte. Se guarda ANTES de leer una
            // sola fila, porque el caso en que hacen falta es precisamente aquel
            // en el que esto se corta a la mitad.
            $punto->guardarRespuestas($this->respuestasDelCuerpo());

            $fixer = new ImporterFixer($respuestas->equivalencias());
            $Import = new ExcelUtils($year, $fixer, $punto, null, $respuestas);

            // El error se guarda y se vuelve a lanzar: el 500 que ve el cliente
            // es el mismo de siempre —cambiarlo es tocar el contrato— pero deja
            // de ser la única señal de que algo pasó. Y la fila queda en
            // 'fallida', que se reanuda igual que 'en_proceso'.
            try {
                Excel::import($Import, $archivo);
            } catch (\Throwable $e) {
                PuntoDeControlDeImportacion::soltarCerrojo('alumnos', $huella, (int) $year);

                // Los avisos de lo que SÍ se escribió antes de reventar se
                // guardan igual, y ése es el caso que más los necesita: la
                // pantalla que retoma esta importación tiene que poder decir qué
                // llevaban las filas que ya entraron.
                $punto->guardarAvisos($fixer->avisos);
                $punto->fallar($e);
                throw $e;
            }

            $punto->guardarAvisos($fixer->avisos);

            // El denominador del aviso de «a medias»: se guarda SIEMPRE, también
            // cuando la petición se quedó corta, porque es justo la que deja la
            // importación pendiente y la que tiene que poder contarse.
            $punto->anotarElTotal($Import->filasDelArchivo);

            // **Sólo se cierra si de verdad terminó.** Si se acabó el tiempo, la
            // fila se queda en `en_proceso` con su avance, que es justo lo que
            // `abrir()` sabe reanudar en la petición siguiente. Cerrarla aquí
            // sería decirle a la próxima subida que no hay nada pendiente.
            if (! $Import->agotado) {
                $punto->completar();
            }

            // **Se suelta DESPUÉS de cerrar la importación, no antes.** Soltarlo
            // al salir del `try` dejaría que otra petición entrara, encontrara la
            // fila todavía en `en_proceso` y la reanudara mientras ésta la está
            // cerrando.
            //
            // Y si esto no llega a ejecutarse —un fatal por tiempo, el proceso
            // muerto—, **el cerrojo se suelta solo al caerse la conexión**. Ésa es
            // la propiedad por la que se eligió `GET_LOCK` y no una columna: aquí
            // no hay cron que limpie lo que un corte deje a medias.
            PuntoDeControlDeImportacion::soltarCerrojo('alumnos', $huella, (int) $year);

            // Aquí había un bucle que llenaba un `$data` que no devolvía nadie,
            // instanciando `AlumnosImport` una vez por hoja. Se va con el
            // cambio de respuesta: era lo único que instanciaba esa clase.
            //
            // LO QUE SE DEVOLVÍA HASTA HOY ERA LA CADENA `'Importados.'`, y eso
            // no era inocuo. Medido el 20 sep 2026 contra el docker:
            //
            //     status 200 · Content-Type text/html · cuerpo 'Importados.'
            //
            // `app2` sube por `comunes/subida/subida.ts`, que llama a
            // `http.post` **sin `responseType`** —o sea `'json'`—, y Angular
            // convierte un 2xx cuyo cuerpo no parsea en un ERROR. Así que las
            // dos pantallas nuevas enseñaban «no se pudieron importar» después
            // de una importación que había funcionado. Lo dedujo la sesión del
            // front leyendo su propio código y lo confirmó esta medición.
            //
            // Devolver JSON arregla ese camino además de abrir el contrato.
            // **Los errores siguen siendo texto**, decidido por Joseth el 20
            // sep: la pantalla vieja pinta `status + ': ' + data` y con un JSON
            // ahí saldría `500: [object Object]`.
            return response()->json([
                'ok' => true,
                'importacion_id' => $punto->id(),
                'reanudada' => $punto->reanudada(),
                'filas_del_archivo' => $punto->filas(),
                'hechos' => $Import->hechos,
                'avisos' => $fixer->avisos,

                // **EL CONTRATO NUEVO DEL 20 SEP 2026: `terminado` decide si el
                // cliente tiene que volver a llamar.**
                //
                // No hay cola en esta casa —`QUEUE_CONNECTION` es `sync` y no hay
                // un solo `app/Jobs`— y cPanel corta a los 300 s, así que una
                // importación grande no puede irse a segundo plano. Lo que hace es
                // caber en varias peticiones: cada una escribe lo que le da tiempo
                // (`SEGUNDOS_POR_PETICION`), deja el punto de control y dice si
                // falta.
                //
                // **`terminado: false` no es un error**: es «vuelve a subir el
                // mismo archivo». Se reanuda solo, porque la huella es del
                // contenido y `abrir()` continúa la fila pendiente.
                //
                // Una pantalla vieja que no lea este campo **no se rompe**: ve un
                // 200 con sus `hechos` y, como antes, sigue necesitando que
                // alguien vuelva a subir — sólo que ahora al volver a subir no
                // repite lo hecho. *Degradarse bien.*
                // Lo que las cuatro secciones nuevas hicieron de verdad. Va en la
                // respuesta porque una decisión que no se puede contar es una
                // decisión que nadie puede comprobar — y porque una hoja saltada
                // es un grupo entero sin importar.
                'hojas_omitidas' => $Import->hojasOmitidas,

                'terminado' => ! $Import->agotado,
                'filas_totales' => $Import->filasDelArchivo,
                'filas_hechas' => $punto->filas(),
                'faltan' => max(0, $Import->filasDelArchivo - $punto->filas()),

                // Qué se hizo con lo que la persona decidió. Sin esto, una
                // respuesta ignorada en silencio no la vería nadie — y el
                // informe final no podría cerrar la frase «se aplicaron las 13
                // que aprobaste».
                'respuestas' => $respuestas->hayAlguna()
                    ? $respuestas->resumen($fixer->equivalenciasUsadas)
                    : null,
            ]);
        }

        // Se queda en texto a propósito, por lo mismo que los errores: es la
        // rama de «no me mandaste fichero», y los cuatro llamadores mandan uno
        // siempre.
        return 'No se encontró archivo.';
    }

    /**
     * QUÉ VA A PASAR SI SE SUBE ESTA HOJA — y no pasa nada mientras se mira.
     *
     * Es la ruta que da sentido a la frase con la que Joseth pidió todo esto:
     * *«preguntándole al usuario qué quiere hacer en tal y cual caso,
     * **diciéndole qué va a pasar**»*. Hasta hoy la única forma de saber qué
     * hacía una importación era hacerla, y lo que hace no es lo que parece: el
     * importador **no valida, adivina** — un valor que no reconoce no da un 422,
     * da otro valor plausible y equivocado, y sigue.
     *
     * **No escribe nada**, y eso no es una promesa del comentario: lo cuenta
     * `EnsayoDeLaImportacionTest` mirando cinco tablas antes y después.
     *
     * El mismo guard que la subida —`auth.personal`— y ningún permiso dentro:
     * quien puede importar puede preguntar antes qué pasaría. Darle menos
     * alcance que a la subida sería empujar a la gente a subir para enterarse,
     * que es exactamente lo que esto viene a evitar.
     */
    public function postEnsayo($year)
    {
        EstadosDeMatricula::olvidar();

        if (! Request::hasFile('file')) {
            return response()->json(['ok' => false, 'msg' => 'No se encontró archivo.'], 422);
        }

        $huella = hash_file('sha256', request()->file('file')->getRealPath());
        $respuestas = new RespuestasDeLaImportacion($this->respuestasDelCuerpo() ?? []);

        // El ensayo acepta las mismas instrucciones que la subida, y ésa es la
        // pieza que hace verdad a las demás: corregir, volver a ensayar, ver el
        // plan CORREGIDO y subir. Sin esta vuelta, lo que se enseña es el plan
        // de antes de las correcciones, y la promesa se rompe justo donde más
        // duele.
        if ($respuestas->sonDeOtroFichero($huella)) {
            return response()->json([
                'ok' => false,
                'msg' => 'Las instrucciones que llegaron son de otro archivo.',
            ], 422);
        }

        $fixer = new ImporterFixer($respuestas->equivalencias());
        $ensayo = new EnsayoDeLaImportacion((int) $year, $fixer);

        // UN FICHERO QUE NO SE PUEDE LEER CONTESTA 422 EN JSON, NO UN 500 EN
        // HTML, y aquí sí se puede hacer: **esto no escribe nada**, así que no
        // hay nada a medias que explicar. En `postAlgo` el 500 se deja pasar a
        // propósito —cambiarlo es tocar el contrato de una ruta viva— y aquí no
        // hay contrato viejo que respetar.
        //
        // Lo pidió la sesión del front, y tenía razón por un motivo que no es
        // suyo: su pantalla llama al ensayo con `responseType: 'json'`, así que
        // un 500 en HTML se convierte en «no se pudo leer el archivo» y el
        // motivo se pierde por el camino.
        //
        // Y hay un segundo motivo, más serio: con `APP_DEBUG=true` —que es lo
        // que corre el contenedor, y colegio a colegio nadie lo ha mirado— el
        // cuerpo de un 500 trae `Host`, `Port` y `Database`. Es el pendiente del
        // [01] visto antes de abrirle otro camino.
        //
        // Del error viaja la clase y el mensaje, NO el fichero ni la línea: el
        // mensaje de PhpSpreadsheet dice cosas útiles —«Invalid cell
        // coordinate», «File not readable»— y la ruta del servidor no le sirve a
        // nadie al otro lado.
        try {
            Excel::import($ensayo, request()->file('file'));
        } catch (\Throwable $e) {
            return response()->json([
                'ok' => false,
                'msg' => 'No se pudo leer el archivo.',
                'detalle' => class_basename($e).': '.$this->sinRutasDelServidor($e->getMessage()),
            ], 422);
        }

        $plan = $ensayo->plan;
        $cuenta = fn (string $accion) => count(array_filter($plan, fn ($f) => $f['accion'] === $accion));

        $bloqueos = [];

        foreach ($ensayo->hojas as $hoja) {
            if ($hoja['coincide_con'] === null) {
                $bloqueos[] = [
                    'tipo' => 'hoja_sin_grupo',
                    'hoja' => $hoja['nombre'],
                    'motivo' => "La pestaña «{$hoja['nombre']}» no es ningún grupo del año, y el importador se "
                              .'detiene al llegar a ella. Las hojas anteriores ya habrán quedado escritas.',
                ];
            }
        }

        return response()->json([
            'ok' => true,
            'year' => (int) $year,

            // LA HUELLA DEL FICHERO QUE SE ESTUDIÓ, y no es informativa: es lo
            // que ata este plan a un fichero concreto.
            //
            // Todo lo que hay debajo —«se crean 12, se actualizan 25, a 11 no
            // les cambia nada»— es cierto PARA ESTE LIBRO. Si luego se sube otro,
            // aunque sea el mismo con una celda corregida, el plan que se enseñó
            // ya no describe lo que va a pasar y **nadie se enteraría**: los
            // números seguirían saliendo, sólo que serían otros.
            //
            // Con la huella delante, la pantalla puede decir «esto que te enseñé
            // era de otro fichero» antes de que alguien pulse. Es el mismo sha256
            // del contenido con el que `PuntoDeControlDeImportacion` reconoce «el
            // mismo archivo», así que los dos hablan de lo mismo.
            'huella' => $huella,

            // Lo mismo que devuelve la subida, para que la pantalla pueda
            // enseñar el plan CON las correcciones aplicadas y decir cuántas
            // entraron.
            'respuestas' => $respuestas->hayAlguna()
                ? $respuestas->resumen($fixer->equivalenciasUsadas)
                : null,

            // Va en la respuesta y no sólo en la documentación porque es lo que
            // la pantalla le promete a quien pulsa. Si algún día dejara de ser
            // cierto, esta línea es una mentira que se lee.
            'escribe' => false,

            'hojas' => $ensayo->hojas,

            // SI ESTA IMPORTACIÓN VA A FALLAR ENTERA, Y POR QUÉ.
            //
            // Es la respuesta más importante que puede dar un ensayo y hasta hoy
            // no la daba: el dato estaba —`coincide_con: null`— pero **no la
            // consecuencia**, y la pantalla del front pintó esa hoja como
            // «Vacía · no se importa», inofensiva, dejó pulsar «Importar 32
            // alumnos» y la subida contestó 500.
            //
            // Mi respuesta era correcta y la lectura que inducía, falsa. Es el
            // mismo animal que el `acudientes_tocados: 0`.
            //
            // Y una hoja VACÍA con el nombre malo detiene igual: el importador
            // resuelve la pestaña contra `grupos` **antes** de mirar si trae
            // filas (`ExcelUtils::array()`), así que «no tiene alumnos» no la
            // hace inofensiva.
            // **UN PLAN RECORTADO NO PUEDE DECIR QUE SÍ.**
            //
            // Si el ensayo se quedó sin tiempo, las filas que no miró pueden
            // traer la hoja sin grupo que hace reventar la importación entera.
            // Un `true` aquí sería la peor forma de este fallo: la pantalla
            // dejaría pulsar «Importar» con un aval que nadie dio.
            //
            // `null` y no `false`: no es «va a fallar», es «no se sabe», y son
            // dos frases distintas para la persona que decide.
            'puede_importarse' => $ensayo->recortado ? null : $bloqueos === [],
            'bloqueos' => $bloqueos,

            // EL PLAN SE DECLARA COMPLETO O NO, y es lo que impide leerlo mal.
            //
            // El ensayo no escribe, así que no puede reanudarse: o cabe en la
            // petición o se recorta. Antes de esto un libro grande daba 500 por
            // `max_execution_time` —medido por `myvc-front-51`: 4.133 filas—, y
            // ese 500 llega al navegador sin cabeceras de CORS, así que la
            // pantalla no lo distinguía de un fichero ilegible.
            'completo' => ! $ensayo->recortado,
            'filas_estudiadas' => $ensayo->filasEstudiadas,
            'filas_del_libro' => $ensayo->filasDelLibro,
            'grupos_del_year' => $this->gruposDelYear((int) $year),
            'columnas_destino' => $this->columnasDestino(),
            'catalogos' => $this->catalogos(),

            'valores_no_reconocidos' => $ensayo->valoresNoReconocidos(),

            // Las celdas vacías, contadas por columna. No es lo mismo que «no lo
            // entendí»: el traductor no avisa de un hueco, y por el defecto una
            // celda vacía de `tipo_de_documento` se guarda como Tarjeta de
            // Identidad. O sea que «no sé» acaba siendo una afirmación sobre un
            // menor, y eso hay que poder decirlo con un número delante.
            'vacios' => $ensayo->vacios(),
            'truncados' => $ensayo->truncados(),
            'posibles_repetidos' => $ensayo->posiblesRepetidos,
            'duplicados_en_el_archivo' => $ensayo->duplicadosEnElArchivo(),
            'filas_sin_documento' => $ensayo->filasSinDocumento,

            'plan' => $plan,
            'totales' => [
                'filas' => count($plan),
                'crear' => $cuenta('crear'),
                'actualizar' => $cuenta('actualizar'),
                'sin_cambios' => $cuenta('sin_cambios'),
                'se_saltan' => $cuenta('se_salta'),
                // Se enseña el cero a propósito: «y no se borra a nadie» es la
                // mitad de la respuesta que la gente necesita antes de pulsar, y
                // un campo ausente no dice eso.
                'borrar' => 0,
            ],
            // Los mismos cinco números, hoja a hoja. La pantalla los enseña en
            // una tabla porque una hoja puede ser inocente y la de al lado no, y
            // un total de 800 filas no distingue las dos.
            'por_hoja' => $this->porHoja($plan, $ensayo->hojas),

            'efectos_colaterales' => [
                'usuarios_a_crear' => $cuenta('crear'),
                'matriculas_a_crear' => $cuenta('crear'),
                'estado_de_las_matriculas_nuevas' => 'MATR',
                'contrasena_de_los_usuarios_nuevos' => '123456',

                // ESTE CAMPO DECÍA `acudientes_tocados: 0` Y ERA FALSO.
                //
                // La v1 del ensayo sólo estudia al alumno —decisión del 19 sep:
                // el 24 % de los acudientes vivos comparte documento con otro, y
                // tocarlos pide su propio estudio— pero **la subida SÍ escribe
                // acudientes y parentescos**. O sea que un 0 ahí no decía «no se
                // tocan»: decía «no los he mirado», y las dos se leen igual en
                // una pantalla.
                //
                // Es exactamente el fallo que este módulo persigue —un número
                // plausible que afirma algo que nadie comprobó— cometido en la
                // respuesta que viene a evitarlo. Así que el campo dice lo que
                // pasa y no un número que no significa lo que parece.
                'acudientes' => [
                    'los_estudia_el_ensayo' => false,
                    'los_escribe_la_subida' => true,
                    'nota' => 'La v1 del ensayo sólo estudia alumnos. Al importar SÍ se crean y '
                            .'actualizan acudientes y parentescos, y eso no está en este plan.',
                ],
            ],

            // Cambia el texto de la pantalla y el front no lo puede saber: en el
            // docker un valor que no cabe se recorta en silencio, y en la
            // MariaDB de producción **aborta la fila**. Es la misma hoja con dos
            // finales distintos.
            'servidor_estricto' => $this->servidorEstricto(),
        ]);
    }

    /**
     * Los cinco números repartidos por pestaña.
     *
     * Se calcula sobre el plan y no se cuenta aparte: dos cuentas del mismo
     * número se separan, y aquí la que quedaría mal es la que la gente mira
     * antes de pulsar.
     */
    private function porHoja(array $plan, array $hojas): array
    {
        $cuentas = [];

        foreach ($hojas as $hoja) {
            $cuentas[$hoja['nombre']] = [
                'hoja' => $hoja['nombre'],
                'coincide_con' => $hoja['coincide_con'],
                'filas' => 0, 'crear' => 0, 'actualizar' => 0, 'sin_cambios' => 0, 'se_saltan' => 0,
                // Cuántos documentos de esta hoja no están hoy en MyVc. Es el
                // denominador del aviso de D4: en enero, que sea el 100 % es lo
                // normal y no una alarma.
                'documentos_nuevos' => 0,
            ];
        }

        foreach ($plan as $fila) {
            $hoja = $fila['hoja'];

            if (! isset($cuentas[$hoja])) {
                continue;
            }

            $cuentas[$hoja]['filas']++;
            $cuentas[$hoja][$fila['accion'] === 'se_salta' ? 'se_saltan' : $fila['accion']]++;

            if ($fila['alumno_existente'] === null) {
                $cuentas[$hoja]['documentos_nuevos']++;
            }
        }

        return array_values($cuentas);
    }

    /**
     * El mensaje de un error, sin las rutas del servidor que traiga dentro.
     *
     * **Lo cazó el test que se escribió para esto**, que es la mitad que
     * importa: la primera versión devolvía el mensaje tal cual y PhpSpreadsheet
     * dice cosas como
     * `Could not find zip member zip:///app/.worktrees/imp/storage/framework/…`.
     * O sea que el 422 que se puso para NO filtrar el `.env` por el cuerpo de un
     * 500 estaba filtrando la ruta del despliegue por su cuenta.
     *
     * Se queda el nombre del fichero y se va el camino: «Could not find zip
     * member laravel-excel-….xlsx» le dice lo mismo a quien diagnostica, y en
     * los dieciséis colegios esa ruta lleva dentro el subdominio del colegio.
     */
    private function sinRutasDelServidor(string $mensaje): string
    {
        $limpio = (string) preg_replace('#[a-z]*:?/{1,3}(?:[\w.\-]+/)+#i', '', $mensaje);

        return mb_substr($limpio, 0, 500);
    }

    /** Los grupos del año, para poder ofrecer a cuál va una hoja que no casó. */
    private function gruposDelYear(int $year): array
    {
        return DB::select(
            'SELECT g.id, g.abrev, g.nombre,
                    TRIM(CONCAT(COALESCE(p.nombres, ""), " ", COALESCE(p.apellidos, ""))) AS titular,
                    (SELECT COUNT(*) FROM matriculas m WHERE m.grupo_id = g.id AND m.deleted_at IS NULL) AS matriculados
             FROM grupos g
             INNER JOIN years y ON y.id = g.year_id AND y.deleted_at IS NULL
             LEFT JOIN profesores p ON p.id = g.titular_id AND p.deleted_at IS NULL
             WHERE y.year = ? AND g.deleted_at IS NULL
             ORDER BY g.orden',
            [$year]
        );
    }

    /**
     * Las columnas que el importador lee, con su etiqueta humana.
     *
     * La etiqueta no es cosmética: hoy, cuando falta una columna, lo que se ve
     * es un 500 con `Undefined array key "fecha_de_nacim"`.
     */
    private function columnasDestino(): array
    {
        $columnas = [];

        foreach (EnsayoDeLaImportacion::COLUMNAS as $clave => $meta) {
            $columnas[] = ['clave' => $clave, 'valor_por_defecto' => $this->valorPorDefecto($clave)] + $meta;
        }

        return $columnas;
    }

    /**
     * Qué queda escrito cuando la celda viene vacía, **estructurado** y no sólo
     * como frase.
     *
     * Va en una celda de la tabla, al lado del valor que trae el fichero, así
     * que la frase de `si_falta` no sirve ahí: partirla en el front sería
     * adivinar dónde.
     *
     * **El literal se lee del catálogo del colegio, no se escribe aquí.** El
     * importador clava `tipo_doc = 3` —eso es código— pero qué es el 3 lo dice
     * cada base, y `existe_en_el_catalogo` está para el caso que nadie ha
     * mirado: **un colegio sin la fila 3 recibiría una referencia rota**, y hoy
     * eso ocurriría en silencio.
     *
     * @return array<string, mixed>|null
     */
    private function valorPorDefecto(string $clave): ?array
    {
        if ($clave === 'tipo_de_documento') {
            $fila = DB::selectOne('SELECT id, tipo FROM tipos_documentos WHERE id = 3 AND deleted_at IS NULL');

            return [
                'id' => 3,
                'literal' => $fila->tipo ?? null,
                'existe_en_el_catalogo' => $fila !== null,
            ];
        }

        if ($clave === 'sexo') {
            // Sólo al CREAR. Al actualizar, una celda vacía borra el que hubiera,
            // que es otra cosa y está en la frase.
            return ['valor' => 'M', 'literal' => 'Masculino', 'solo_al_crear' => true];
        }

        return null;
    }

    /**
     * Los vocabularios, que son TABLAS DE CADA COLEGIO y no constantes.
     *
     * Por eso los manda el servidor: una lista incrustada en el front acierta en
     * un colegio y miente en los otros quince. Y con el tipo de documento hay
     * una razón más fuerte — el importador compara **bytes**, así que lo que se
     * elija tiene que ser la cadena del catálogo tal cual, con su tilde y su
     * caja.
     */
    private function catalogos(): array
    {
        return [
            'tipos_documento' => DB::select('SELECT id, tipo, abrev FROM tipos_documentos WHERE deleted_at IS NULL ORDER BY id'),

            // Los siete códigos vivos. No se traducen solos —«Activo» podría ser
            // MATR o ASIS y eso lo decide el colegio— así que la pantalla los
            // ofrece y una persona elige.
            'estados_matricula' => [
                ['codigo' => 'MATR', 'nombre' => 'Matriculado'],
                ['codigo' => 'ASIS', 'nombre' => 'Asistente'],
                ['codigo' => 'PREM', 'nombre' => 'Prematriculado'],
                ['codigo' => 'PREA', 'nombre' => 'Preinscrito'],
                ['codigo' => 'FORM', 'nombre' => 'Con formulario'],
                ['codigo' => 'RETI', 'nombre' => 'Retirado'],
                ['codigo' => 'DESE', 'nombre' => 'Desertor'],
            ],
            'sexo' => [['codigo' => 'M', 'nombre' => 'Masculino'], ['codigo' => 'F', 'nombre' => 'Femenino']],
        ];
    }

    /**
     * Si esta base aborta lo que no cabe o lo recorta en silencio.
     *
     * Las dos son malas y **son distintas**: el docker recorta y la MariaDB de
     * producción aborta a media hoja. La misma hoja tiene dos finales, y el
     * front no puede saber cuál le toca sin preguntarlo.
     */
    private function servidorEstricto(): bool
    {
        $modo = (string) (DB::selectOne('SELECT @@SESSION.sql_mode AS modo')->modo ?? '');

        return str_contains($modo, 'STRICT_TRANS_TABLES') || str_contains($modo, 'STRICT_ALL_TABLES');
    }

    /**
     * Las instrucciones que la pantalla mandó con el fichero.
     *
     * Vienen en un `multipart/form-data` —el fichero manda—, así que un objeto
     * llega como cadena y hay que decodificarlo. Lo que no sea un objeto se
     * descarta en silencio y no se guarda: esto no valida la forma de las
     * respuestas, sólo se niega a guardar algo que no lo sea. Quien las
     * interpreta es la Fase 2, y validar aquí una forma que todavía se está
     * dibujando sería fijarla antes de tiempo.
     *
     * @return array<string, mixed>|null
     */
    private function respuestasDelCuerpo(): ?array
    {
        $crudo = Request::input('respuestas');

        if (is_string($crudo)) {
            $crudo = json_decode($crudo, true);
        }

        return is_array($crudo) && $crudo !== [] ? $crudo : null;
    }

    /**
     * La importación de alumnos de este año que quedó a medias, si la hay.
     *
     * Es lo primero que pregunta la pantalla de importar **al entrar**, antes de
     * que nadie elija fichero: si hay uno a medias, lo que toca no es subir otro
     * sino decidir entre seguir donde se quedó o empezar de cero.
     *
     * Devuelve lo que esa decisión necesita y que hasta hoy no salía por ningún
     * lado: por qué se cortó, por dónde iba hoja a hoja, **qué llevaban las
     * filas que ya entraron** —los avisos— y **qué contestó quien la empezó**
     * —las respuestas—. Sin los dos últimos, «seguir donde se quedó» obliga a
     * contestarlo todo otra vez con pasos saltados, que es peor que empezar de
     * cero.
     *
     * `auth.personal` como la subida: quien puede importar puede preguntar si
     * hay algo a medias. No lleva permiso propio dentro porque **no decide
     * nada** — lee lo que ya pasó en el año de trabajo del colegio.
     */
    public function getPendiente($year)
    {
        $pendiente = PuntoDeControlDeImportacion::pendienteDe('alumnos', (int) $year);

        if ($pendiente === null) {
            // Un `null` explícito y no un 404: «no hay ninguna a medias» es una
            // respuesta buena a esta pregunta, y la más frecuente con mucho.
            return response()->json(['pendiente' => null]);
        }

        // LAS FECHAS SE CONVIERTEN AL LEER, Y LA TABLA SE QUEDA EN UTC.
        //
        // `importaciones` escribe `inicio`, `fin` y `updated_at` con `now()`, o
        // sea en UTC, y está declarado como excepción en `RelojUnicoTest` con
        // este motivo: *«sólo se restan entre sí, nunca se comparan con otra
        // tabla, así que unificar la zona no cambia ningún resultado — sólo
        // desplaza cinco horas lo que se lee en pantalla»*.
        //
        // **Esa premisa caduca hoy**: esta ruta existe para que una pantalla
        // diga «empezada el 14 de enero a las 9:41 por Marta Ospina», y con la
        // hora cruda diría las 14:41. La salida no es cambiar la escritura —eso
        // dejaría la columna con dos relojes en su historia, que es justo la
        // enfermedad que la fase 1 del reloj vino a curar, y la decisión está
        // anotada como de quien lleve las importaciones—: es convertir **al
        // leer**. La tabla conserva una sola zona y la pantalla enseña la hora
        // del colegio.
        foreach (['inicio', 'fin', 'created_at', 'updated_at'] as $campo) {
            if (! empty($pendiente->{$campo})) {
                $pendiente->{$campo} = Carbon::parse($pendiente->{$campo}, 'UTC')
                    ->setTimezone(Reloj::ZONA)
                    ->format('Y-m-d H:i:s');
            }
        }

        $pendiente->avance = json_decode((string) $pendiente->avance, true) ?: [];
        $pendiente->avisos = json_decode((string) $pendiente->avisos, true) ?: [];
        $pendiente->respuestas = json_decode((string) $pendiente->respuestas, true) ?: null;
        $pendiente->empezada_por = $pendiente->empezada_por !== null && trim($pendiente->empezada_por) !== ''
            ? $pendiente->empezada_por
            : null;

        return response()->json(['pendiente' => $pendiente]);
    }

    /**
     * La importación de cartera, que **está rota y lleva años estándolo**.
     *
     * `Excel::import($ruta, $closure)` es la firma de maatwebsite/excel 2.x. En la
     * 3.x el primer argumento es el objeto de importación y el segundo la ruta,
     * así que el closure llega donde se espera una ruta y `pathinfo()` revienta:
     * 500 en cada llamada, desde antes de esta migración. Es el mismo error exacto
     * que `getIndex()` unas líneas más abajo.
     *
     * No salió en el muestreo de rutas del 20 ago 2026 porque aquello golpeaba
     * lecturas sin parámetro, y esta es un POST con un archivo dentro. Lo destapó
     * el trabajo de la importación reanudable, yendo a mirar los dos importadores
     * que se daban por vivos.
     *
     * **Se deja rota a propósito**, con la regla del proyecto: con ruta y rota se
     * documenta, porque borrarla convierte un 500 en un 404 sin decirle a nadie
     * qué pretendía hacer esa pantalla. Qué debe hacer —y si la operación debe
     * existir— es una decisión del colegio.
     * Ver docs/migracion/05-codigo-muerto-y-roto.md §8.4; el error queda fijado
     * por tests/Contrato/ExcelTest.php.
     */
    public function postCartera()
    {
        if (Request::hasFile('file')) {
            $path = Request::file('file')->getRealPath();

            $rr = Excel::import($path, function ($reader) {

                $now = Carbon::now('America/Bogota');
                $results = $reader->all();

                for ($i = 0; $i < count($results); $i++) {
                    $alumno = $results[$i];

                    if (strtolower($results[$i]->pazysalvo) == 'si' || strtolower($results[$i]->paz_y_salvo) == 'si') {
                        $pazysalvo = 1;
                    } else {
                        $pazysalvo = 0;
                    }

                    $fecha_pension = null;

                    if ($results[$i]->fecha_pension) {
                        $fecha_pension = Carbon::parse($results[$i]->fecha_pension);
                    }

                    if ($results[$i]->fecha) {
                        $fecha_pension = Carbon::parse($results[$i]->fecha);
                    }

                    if ($results[$i]->documento) {
                        $consulta = 'UPDATE alumnos SET deuda=?, pazysalvo=? WHERE documento=?;';
                        $actua = DB::update($consulta, [$results[$i]->deuda, $pazysalvo, $results[$i]->documento]);

                        DB::update('UPDATE matriculas m INNER JOIN alumnos a ON a.id=m.alumno_id and m.deleted_at is null 
							SET m.fecha_pension=? WHERE a.documento=? and a.deleted_at is null',
                            [$fecha_pension, $alumno->documento]);

                        // No eliminar para continuar si se cae el servidor!!
                        Debugging::pin('Alum_documento: '.$alumno->documento);

                    }

                }

            });
        }

        return (array) $rr;
    }

    public function getIndex()
    {

        $rr = Excel::import('app/Http/Controllers/Alumnos/archivos/alumnos.xls', function ($reader) {

            $results = $reader->all();
            $now = Carbon::parse(Request::input('fecha_matricula'));

            for ($i = 0; $i < count($results); $i++) {

                $abrev = $results[$i]->getTitle();
                $consulta = 'SELECT * FROM grupos WHERE abrev=?';
                $grupo = DB::select($consulta, [$abrev])[0];

                for ($f = 0; $f < count($results[$i]); $f++) {

                    $alumno_row = $results[$i][$f];

                    $alumno = new Alumno;
                    $alumno->nombres = $alumno_row->nombres;
                    $alumno->apellidos = $alumno_row->apellidos;
                    $alumno->sexo = $alumno_row->sexo ? $alumno_row->sexo : 'M';
                    $alumno->save();

                    $opera = new OperacionesAlumnos;

                    $usuario = new User;
                    $usuario->username = $opera->username_no_repetido($alumno->nombres, 'alumno'.$alumno->id);
                    $usuario->password = Hash::make('123456');
                    $usuario->sexo = $alumno_row->sexo ? $alumno_row->sexo : 'M';
                    $usuario->is_superuser = 0;
                    $usuario->periodo_id = 1; // Verificar que haya un periodo cod 1
                    $usuario->is_active = 1;
                    $usuario->tipo = 'Alumno';
                    $usuario->save();

                    $role = Role::where('name', 'Alumno')->get();
                    // Entrust no está instalado; es la misma migración que ya tenía hecha
                    // AlumnosController y que aquí quedó sin hacer.
                    $usuario->roles()->attach($role[0]['id']);

                    $alumno->user_id = $usuario->id;
                    $alumno->save();

                    $matricula = new Matricula;
                    $matricula->alumno_id = $alumno->id;
                    $matricula->grupo_id = $grupo->id;
                    $matricula->estado = 'MATR';
                    $matricula->fecha_matricula = $now;
                    $matricula->save();

                }
            }
        });

        return (array) $rr;
    }

    public function getModificar($year)
    {
        $host = apache_request_headers()['Host'];
        if ($host == '0.0.0.0' || $host == 'localhost' || $host == '127.0.0.1') {
            $extension = 'xls';
        } else {
            $extension = 'xlsx';
        }

        $rr = Excel::import('app/Http/Controllers/Alumnos/archivos/alumnos-modificar-'.$year.'.'.$extension, function ($reader) use ($year) {

            $now = Carbon::now('America/Bogota');
            $results = $reader->all();
            $fixer = new ImporterFixer;

            for ($i = 0; $i < count($results); $i++) {

                $abrev = $results[$i]->getTitle();
                $consulta = 'SELECT g.id, g.abrev, g.year_id FROM grupos g inner join years y on y.id=g.year_id WHERE g.abrev=? and g.deleted_At is null and y.deleted_at is null and y.year=?;';
                $grupo = DB::select($consulta, [$abrev, $year])[0];

                for ($f = 0; $f < count($results[$i]); $f++) {

                    $alumno = $results[$i][$f];
                    $res = $fixer->verificar($alumno, $year);
                    $alumno->ciudad_docu_acud1 = $res['ciudad_id_A1'];
                    $alumno->ciudad_docu_acud2 = $res['ciudad_id_A2'];

                    if ($alumno->id) {
                        $consulta = 'UPDATE alumnos SET no_matricula=?, nombres=?, apellidos=?, sexo=?, fecha_nac=?, 
							tipo_doc=?, documento=?, no_matricula=?, direccion=?, barrio=?, telefono=?, celular=?, estrato=?, 
							tipo_sangre=?, eps=?, religion=?, updated_at=?'.$res['consulta'].' WHERE id=?';

                        // Aqui la consulta NO liga `nro_sisben`, al reves que la de procesarFila:
                        // el fragmento es el unico que la escribe por esta ruta. Ver 05 §60.
                        DB::update($consulta, array_merge([$alumno->no_matricula, $alumno->primer_nombre.' '.$alumno->segundo_nombre, $alumno->primer_apellido.' '.$alumno->segundo_apellido, $alumno->sexo, $alumno->fecha_de_nacim,
                            $alumno->tipo_doc, $alumno->nro_de_documento, $alumno->numero_matricula, $alumno->direccion_residencia, $alumno->barrio, $alumno->telefono, $alumno->celular, $alumno->estrato,
                            $alumno->rh, $alumno->eps, $alumno->religion, $now], $res['valores'], [$alumno->id]));

                        DB::update('UPDATE matriculas m INNER JOIN grupos g ON g.id=m.grupo_id and g.year_id=? and g.deleted_at is null SET m.nuevo=?, m.estado=?, m.updated_at=? WHERE m.alumno_id=? and m.deleted_at is null', [$grupo->year_id, $alumno->es_nuevo, $alumno->estado_matricula, $now, $alumno->id]);

                        // No eliminar!!
                        Debugging::pin('Alum_id: '.$alumno->id, 'Grupo: '.$abrev, 'Grupo_id: '.$grupo->id);

                        // Acudiente 1
                        // $this->modificar_acudiente1($alumno, $now, $res['consultaA1']);

                        // Acudiente 2
                        // $this->modificar_acudiente2($alumno, $now, $res['consultaA2']);

                    }

                }

            }

        });

        return (array) $rr;
    }
}
