<?php

namespace App\Http\Controllers\Informes;

use App\Http\Controllers\Concerns\ResuelveElUsuario;
use App\Http\Controllers\Controller;
use App\Services\CodigoDeInscripcion;
use App\Support\Autoriza;
use App\Support\Reloj;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Str;

/**
 * **El formulario de inscripción que se imprime**, en sus dos modos, con el código
 * que ata el papel al alumno.
 *
 * Encargo de Joseth (19 sep 2026), construido contra la pantalla que hizo
 * `myvc-front-bf`. Las decisiones están en
 * `docs/migracion/41-el-formulario-de-inscripcion.md`; el porqué de cada columna,
 * en el docblock de la migración `2026_09_19_100000`. Aquí va sólo lo que decide
 * este controlador.
 *
 * ## ACUÑAR ES ESCRIBIR, y por eso el que imprime es un POST
 *
 *     POST informes/formularios-inscripcion        acuña
 *     GET  informes/formularios-inscripcion/{lote} NO acuña
 *
 * El `GET` existe porque **sin él, recargar la pantalla vuelve a gastar códigos**:
 * una impresora atascada costaría diez. Lo pidió el front y tenía razón — era el
 * defecto más grave del contrato que yo le había mandado.
 *
 * ## Los dos modos, y por qué el lote se construye distinto en cada uno
 *
 *     nuevos     formularios EN BLANCO, por CANTIDAD. Cada llamada son papeles
 *                nuevos de verdad, así que el lote es un UUID nuevo.
 *
 *     antiguos   renovación, por GRUPO, con los datos puestos. El lote es
 *                DETERMINISTA —`antiguos-<campaña>-g<grupo>`— porque «las
 *                renovaciones de 5°A para 2027» son UNA cosa aunque se impriman
 *                cinco veces. Reimprimir devuelve el mismo lote y los mismos
 *                códigos.
 *
 * Y el grupo que se elige es **el del año ACTUAL**, no el de destino: el formulario
 * pregunta «¿vuelve el año que viene?», así que el grupo al que iría ni existe ni
 * hace falta. El colegio abre la campaña antes de crear los grupos — medido por el
 * front: 13 grupos en el año actual y 0 en el siguiente.
 *
 * ## El get-or-create lo resuelve la BASE, no un `if`
 *
 * El requisito es «un código por alumno y año». Escrito como comprueba-y-luego-
 * inserta, dos secretarías reimprimiendo 5°A **a la vez** dejan dos códigos para el
 * mismo alumno y el código deja de identificar a nadie.
 *
 * Así que el camino normal lee primero —es una sola consulta y cubre el 99% de las
 * reimpresiones— pero **la garantía no es ésa**: es el `UNIQUE (year_campana,
 * alumno_id)`. Si el `INSERT` choca contra él, es que otro lo acaba de crear, y
 * entonces se relee esa fila en vez de fallar. Es el fallo de `notas_finales` que
 * explica medio [10](../../../docs/migracion/10-definitivas.md), visto antes de
 * cometerlo.
 *
 * ## Los textos se resuelven aquí, no en el papel
 *
 * `tipo_doc`, las tres ciudades y el parentesco viajan **ya resueltos a texto**.
 * Un papel no puede imprimir un `ciudad_id`, y resolverlos en el front obligaría a
 * bajarse los catálogos enteros para pintar cuarenta hojas.
 *
 * ## Lo que este controlador NO hace
 *
 * No dibuja QR —no hay portal al que llevar, y el paquete entraría en los dieciséis
 * colegios por el `vendor/` compartido— y **no toca `matriculas.nro_folio`**: son
 * 1.720 filas con formatos incompatibles tecleados a mano durante años, y es el
 * único registro histórico que el colegio tiene.
 */
class FormulariosInscripcionController extends Controller
{
    use ResuelveElUsuario;

    /**
     * Tope de ejemplares en blanco por llamada.
     *
     * No es una cifra de rendimiento: **acuñar es irreversible**. Un `2000` tecleado
     * donde iba un `20` quema dos mil códigos de la campaña y ensucia para siempre
     * la lista de «vendidos que no volvieron», que es justo lo que esta tabla viene
     * a producir. Doscientos es más de lo que cabe en una impresora de secretaría
     * de una sentada.
     */
    private const MAXIMO_EN_BLANCO = 200;

    /** Lo que cabe en `ordenes_inscripcion.cierra`. */
    private const LARGO_CIERRA = 120;

    /**
     * Tope del precio del formulario, en pesos.
     *
     * **No está para cazar erratas y conviene no creer que lo hace**: un cero de más
     * en 30.000 da 300.000, que pasa por debajo de esto sin despeinarse. Lo que
     * impide es el absurdo y el desbordamiento de la columna —`unsignedInteger` se
     * acaba en 4.294.967.295— y se valida **aquí y no en la base** porque el docker
     * trunca en silencio y MariaDB 10.5 aborta: el mismo dato daría dos resultados
     * distintos en desarrollo y en producción.
     *
     * Contra la errata de tecleo lo único que sirve es que la pantalla enseñe el
     * precio guardado, que es cosa del front.
     */
    private const MAXIMO_VALOR = 10000000;

    /**
     * El precio de la campaña, leído **una vez por petición**.
     *
     * Se memoriza porque acuñar un grupo de renovaciones inserta treinta filas y
     * todas cuestan lo mismo: sin esto serían treinta consultas idénticas para
     * contestar una pregunta que no cambia dentro de la misma llamada.
     */
    private ?int $precio = null;

    private bool $precioLeido = false;

    /**
     * Cuántas veces se reintenta si el código aleatorio choca.
     *
     * Con 29^5 combinaciones un choque es raro, pero *raro* no es *imposible* y el
     * `UNIQUE` lo rechaza. Tres intentos dejan la probabilidad de fallar por debajo
     * de lo que vale la pena pensar; el cuarto sería un síntoma de otra cosa.
     */
    private const INTENTOS_DE_CODIGO = 3;

    public function postAcunar()
    {
        $user = $this->user;

        $modo = strtolower(trim((string) Request::input('modo')));

        if (! in_array($modo, ['nuevos', 'antiguos'], true)) {
            abort(422, 'El modo tiene que ser «nuevos» o «antiguos».');
        }

        $cierra = $this->cierraValidado();

        $anio = DB::selectOne('SELECT id, year FROM years WHERE id=? AND deleted_at IS NULL',
            [$user->year_id]);

        if (! $anio) {
            abort(404, 'El año lectivo de la sesión no existe.');
        }

        // La campaña es la del año siguiente. **La columna existe precisamente para
        // que el día que haya que inscribir a mitad de curso —el estado `ASIS`, que
        // existe y se usa— eso sea un parámetro y no una migración**: hoy no se
        // expone porque la pantalla no lo pide, y añadirlo sin pantalla es la
        // trampa de `profesores.tono`.
        $campana = ((int) $anio->year) + 1;

        $lote = $modo === 'nuevos'
            ? $this->acunarEnBlanco($user, $anio, $campana, $cierra)
            : $this->acunarRenovacion($user, $anio, $campana, $cierra);

        return $this->pintarLote($lote, $campana, $cierra);
    }

    /**
     * **Qué campos salen en el papel de este colegio.**
     *
     * Autorizado por Joseth el 19 sep 2026 cuando contestó que **cada colegio tiene
     * su propio formulario en papel**: una lista fija no podía servirles a los
     * dieciséis.
     *
     * ## Se guardan CLAVES, no etiquetas, y eso lo decidió el front con razón
     *
     * Lo que viaja son identificadores (`nombres`, `ac_celular`); qué significa cada
     * uno —su rótulo, su bloque y cuánto mide— vive en el catálogo de `app2`. No es
     * reparto de conveniencia: **un campo del papel tiene que corresponder a una
     * columna que esta API sepa leer**, o la renovación lo imprimiría siempre en
     * blanco. Si el colegio pudiera inventar campos desde una pantalla, tendría
     * renglones que no rellena nadie nunca.
     *
     * ## Por eso aquí NO hay lista blanca de claves, y es a propósito
     *
     * Validar contra el catálogo obligaría a desplegar el backend cada vez que el
     * front añade un campo, y el front ya resuelve el caso contrario: **una clave
     * que no conoce la ignora**, así que un colegio con una selección hecha desde
     * una versión más nueva imprime lo que entiende en vez de romperse.
     *
     * Lo que sí se valida es la **forma**: que sean claves y no basura, y que no
     * quepa un fichero entero en una columna `text` que nadie mira.
     *
     * ## Vacío significa «el defecto», no «un papel sin campos»
     *
     * Un colegio que nunca ha configurado nada no es uno que quiera un formulario en
     * blanco. Quien resuelve el defecto es el front —es el único que sabe cuánto mide
     * cada campo, y el alto es lo que decide— así que aquí se devuelve la lista vacía
     * sin adornarla.
     */
    public function getCampos()
    {
        $year_id = $this->anioDeLaPeticion(Request::input('year_id'));

        $fila = DB::selectOne('SELECT campos, valor FROM config_formulario_inscripcion WHERE year_id=?',
            [$year_id]);

        return [
            'year_id' => $year_id,
            'campos' => $this->decodificar($fila->campos ?? null),
            // **`null` no es cero y la pantalla tiene que poder distinguirlos**: sin
            // precio, el pago en línea contesta 422 y el colegio necesita ver que lo
            // que falta es decidirlo, no que valga cero.
            'valor' => isset($fila->valor) ? (int) $fila->valor : null,
        ];
    }

    public function putCampos()
    {
        $user = $this->user;
        $year_id = $this->anioDeLaPeticion(Request::input('year_id'));
        $campos = $this->camposValidados();
        $valor = $this->precioValidado();

        // `INSERT ... ON DUPLICATE KEY UPDATE` y no comprueba-y-luego-inserta: el
        // `UNIQUE (year_id)` es lo que impide dos configuraciones del mismo año, y
        // dos pestañas guardando a la vez no pueden dejar dos filas.
        //
        // Es sintaxis de MySQL Y de MariaDB 10.5 —no un `upsert()` de Eloquent, que
        // aquí casi no se usa—, así que se comporta igual en el docker y en los
        // dieciséis.
        //
        // La hora sale de `Reloj` y no de `NOW()`: `config/database.php` no fija la
        // zona de la sesión, así que `NOW()` es el reloj del servidor —dieciséis
        // cuentas de cPanel— y esta columna acabaría con una hora distinta en cada
        // colegio sin nada en la fila que lo dijera. Ver el 53 §1.
        $ahora = Reloj::ahoraTexto();

        DB::insert('INSERT INTO config_formulario_inscripcion
                (year_id, campos, valor, created_by, updated_by, created_at, updated_at)
            VALUES (?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE campos=VALUES(campos), valor=VALUES(valor),
                updated_by=VALUES(updated_by), updated_at=VALUES(updated_at)',
            [$year_id, json_encode($campos, JSON_UNESCAPED_UNICODE), $valor,
                $user->user_id, $user->user_id, $ahora, $ahora]);

        return 'Guardado';
    }

    /**
     * Vuelve a leer un lote ya acuñado. **No escribe nada.**
     *
     * No filtra por el año de la sesión a propósito: un lote impreso en 2026 se
     * tiene que poder reimprimir en enero de 2027, cuando la sesión ya está en el
     * año nuevo y nadie se acuerda de qué fecha se puso en `cierra`.
     */
    public function getLote(string $lote)
    {
        $filas = DB::select('SELECT * FROM ordenes_inscripcion
            WHERE lote_id=? AND deleted_at IS NULL ORDER BY id', [$lote]);

        if (count($filas) === 0) {
            abort(404, 'Ese lote de formularios no existe.');
        }

        return $this->pintarLote($filas, (int) $filas[0]->year_campana, $filas[0]->cierra);
    }

    /**
     * **Lo que hay detrás de un código**, para la ventanilla.
     *
     * Secretaría tiene el papel lleno delante y teclea lo que lleva impreso en la
     * cabecera. Esto le dice de quién es ese formulario, si está pagado y si ya
     * llegó a matrícula. **No escribe nada.**
     *
     * ## Encuentra también por el código VIEJO, y eso es la mitad del método
     *
     * Un código corregido (ver `putCodigo`) deja un papel circulando con lo que se
     * imprimió. Buscar sólo por `codigo` le contestaría a esa familia *«no existe»*,
     * que es exactamente la avería que `codigo_anterior` viene a impedir. Por eso la
     * respuesta dice **`encontrado_por`**: la pantalla necesita poder avisar de que
     * ese papel ya no lleva el código bueno.
     *
     * ## El guard es `auth.personal` y aquí NO hay criterio dentro, a propósito
     *
     * Leer un formulario no decide nada —las dos escrituras de esta familia sí, y
     * por eso ésas llevan `puedeAtarFormularios` dentro—. Poner el mismo criterio
     * aquí dejaría a un docente que atiende la estación de documentos sin poder
     * mirar el papel que tiene en la mano, que es justo lo que el día de matrículas
     * hay que hacer.
     */
    public function getPorCodigo(string $codigo)
    {
        $orden = $this->ordenDelCodigo($codigo);

        return $this->pintarOrden($orden);
    }

    /**
     * **Ata el papel a un alumno.** El código **no cambia**: es el requisito literal
     * de Joseth —*«se queda con ese alumno al que inscriban para matricular»*—.
     *
     * ## Los tres casos, y el segundo es el que pidió Joseth por su nombre
     *
     * 1. **La orden está libre** (`alumno_id` NULL) y el alumno no tiene ninguna de
     *    esta campaña → se ata. El código sigue siendo el mismo que hay impreso.
     * 2. **El alumno YA tiene una orden de esta campaña** → **no se acuña nada, no
     *    se mueve nada, y se devuelve la suya**. Es el caso de *«ya le dimos el
     *    formulario a un acudiente y viene otro nuevo por el mismo alumno»*: el
     *    papel que trae el segundo queda **libre** para otra familia, que es lo que
     *    hay que hacer con un formulario en blanco que no se usó. Contesta **409**
     *    con el código que ya tiene dentro, porque lo que se pidió no se hizo.
     * 3. **Se repite la misma llamada** (esta orden ya es de ese alumno) → **200**,
     *    idempotente. Un reintento de la pantalla no puede ser un error.
     *
     * ## Y el 409 del caso 2 lo garantiza la BASE, no este `if`
     *
     * La lectura previa cubre el 99 % y **no es la garantía**: dos ventanillas
     * atando dos papeles al mismo alumno a la vez pasarían las dos. Lo que lo impide
     * es el `UNIQUE (year_campana, alumno_id)`, y por eso el `UPDATE` va dentro de un
     * `try` que, al chocar, **relee y contesta lo mismo que el camino normal**. Es la
     * misma forma que `insertarConCodigo`, y por el mismo motivo: el
     * comprueba-y-luego-escribe de `notas_finales` es medio
     * [10](../../../docs/migracion/10-definitivas.md).
     *
     * ## `matricula_id` se rellena aquí, y el estado va detrás
     *
     * Si el alumno **ya tiene matrícula viva del año de la campaña**, se guarda y la
     * orden pasa a `MATRICULADA`. No hace falta pedirlo aparte: el día de matrículas
     * el papel se ata cuando el alumno ya existe.
     *
     * **Lo que NO se hace es enganchar esto en el flujo de matrícula**, y conviene
     * que quede dicho porque es la pregunta obvia: `matriculas` tiene **ocho
     * escritores** en todo `app/` —`MatriculasController`, `LoginController`,
     * `PromovidosController`, `ImportarController` y `GuardarAlumno`— y ninguno es de
     * este módulo. Meter una escritura nuestra en los ocho es tocar el camino
     * caliente de los dieciséis colegios para una columna que sólo lee este informe.
     * Por eso **el informe de campaña no se fía de `estado`: cuenta los matriculados
     * con el `JOIN` vivo** (ver `getCampana`), y esta columna es una comodidad, no
     * una fuente de verdad. *Una caché con ocho escritores ajenos es `notas_finales`
     * otra vez.*
     */
    public function putAlumno(string $codigo)
    {
        $user = $this->user;

        Autoriza::exigir(Autoriza::puedeAtarFormularios($user),
            'No tiene permiso para atar formularios de inscripción a un alumno.');

        $orden = $this->ordenDelCodigo($codigo);
        $alumno = $this->alumnoDelCuerpo();

        // `$orden->alumno_id !== null` va DELANTE y no sobra: `(int) null` es **0**, así
        // que sin él las dos comparaciones de aquí abajo funcionarían por accidente
        // —hoy ningún `alumnos.id` vale 0 porque es autoincremental— y dejarían de
        // funcionar el día que alguien importe una fila con id 0. Una condición que
        // depende de que un id nunca valga cero no es una condición, es una apuesta.
        if ($orden->estado === 'MATRICULADA'
            && ($orden->alumno_id === null || (int) $orden->alumno_id !== (int) $alumno->id)) {
            abort(409, 'Ese formulario ya está cerrado con otro alumno matriculado.');
        }

        // Caso 3: la misma llamada otra vez. Se recomprueba la matrícula —puede
        // haberse matriculado entre las dos— y se contesta 200.
        if ($orden->alumno_id !== null && (int) $orden->alumno_id === (int) $alumno->id) {
            $this->cerrarSiYaEstaMatriculado($orden, $user);

            return $this->pintarOrden($this->ordenPorId((int) $orden->id));
        }

        // Caso 2, camino normal: el alumno ya tiene la suya. No se toca ninguna de
        // las dos — la de este papel se queda libre para otra familia.
        $suya = DB::selectOne('SELECT * FROM ordenes_inscripcion
            WHERE year_campana=? AND alumno_id=? AND deleted_at IS NULL',
            [(int) $orden->year_campana, (int) $alumno->id]);

        if ($suya) {
            return $this->yaTieneElSuyo($suya, $alumno);
        }

        if ($orden->alumno_id !== null) {
            abort(409, 'Ese formulario ya es de otro alumno. Un formulario vendido no cambia de dueño.');
        }

        $matricula = $this->matriculaDeLaCampana((int) $alumno->id, (int) $orden->year_campana);

        try {
            // Condicional: `alumno_id IS NULL`. Si otra ventanilla lo ató entre la
            // lectura y esto, afecta **cero filas** y no se pisa nada.
            $tocadas = DB::update('UPDATE ordenes_inscripcion
                SET alumno_id=?, matricula_id=?, estado=?, updated_by=?, updated_at=?
                WHERE id=? AND alumno_id IS NULL AND deleted_at IS NULL', [
                (int) $alumno->id,
                $matricula?->id,
                $matricula ? 'MATRICULADA' : $orden->estado,
                $user->user_id,
                Reloj::ahoraTexto(),
                (int) $orden->id,
            ]);
        } catch (QueryException $e) {
            // Caso 2, la carrera: otra ventanilla acaba de atarle el suyo. El
            // `UNIQUE` es lo que lo impide, y la respuesta es la misma que arriba.
            if (! str_contains($e->getMessage(), 'ordenes_inscripcion_alumno_campana')) {
                throw $e;
            }

            $suya = DB::selectOne('SELECT * FROM ordenes_inscripcion
                WHERE year_campana=? AND alumno_id=? AND deleted_at IS NULL',
                [(int) $orden->year_campana, (int) $alumno->id]);

            if (! $suya) {
                throw $e;   // chocó contra otra cosa: no se disfraza de conflicto
            }

            return $this->yaTieneElSuyo($suya, $alumno);
        }

        if ($tocadas === 0) {
            // Se lo ató otro entre la lectura y el `UPDATE`, y no fue a este alumno
            // —si lo fuera, habría chocado el `UNIQUE`—.
            abort(409, 'Ese formulario acaba de atarse a otro alumno.');
        }

        return $this->pintarOrden($this->ordenPorId((int) $orden->id));
    }

    /**
     * **Corregir el código de un formulario ya acuñado.**
     *
     * Pedido por Joseth el 20 sep 2026, y su frase lleva dentro las dos mitades:
     * *«los códigos no los debe inventar la secretaría, eso debe ser automático,
     * aunque no estaría mal que lo pueda modificar después de generado/atado,
     * asegurando de darle herramientas para que no repita código»*.
     *
     * ## Se teclea el SUFIJO, no el código — y eso ES la herramienta
     *
     * El cuerpo lleva `sufijo`, cinco caracteres del alfabeto. **El carácter de
     * control lo calcula la API**, así que por este camino no puede salir un código
     * que no valide: el que corrige no tiene forma de escribir uno roto porque no
     * escribe esa parte. Sin `sufijo`, se acuña uno **automático**, que es el botón
     * de *«deme otro»* cuando el papel se estropeó.
     *
     * Y que no se repita **no lo garantiza ninguna comprobación de aquí**: lo
     * garantiza el `UNIQUE (codigo)`. Este método lo traduce a un **409 que dice qué
     * pasó** en vez de a un 500 — *que la base lo rechace es una garantía; que el
     * generador «no repita» es una esperanza*.
     *
     * ## El papel viejo NO se queda huérfano
     *
     * El código que se retira se guarda en `codigo_anterior`, y `getPorCodigo`
     * busca por las dos columnas. Sin eso, corregir un código convierte en basura
     * silenciosa el papel que está en casa de la familia: quien lo teclee recibe
     * *«no existe»* y **nadie puede saber que existió**.
     *
     * Se guarda **uno solo**, el inmediatamente anterior. Corregir dos veces deja
     * huérfano el primero, y es una limitación consciente: el caso de uso es *una*
     * corrección, y un historial sería una tabla que nadie ha pedido.
     *
     * ## Lo único que no se puede corregir es lo que ya cerró
     *
     * `MATRICULADA` es 409: ese código ya está pegado a una matrícula y cambiarlo
     * sólo puede confundir a quien lo busque después. Con pagos **sí** se deja, y no
     * por descuido: las colillas y los pagos apuntan a `orden_id`, no al código, así
     * que dentro de la casa no se rompe nada — lo único que cambia es el papel, y
     * de eso se ocupa `codigo_anterior`. La respuesta lleva `tenia_pagos` para que
     * la pantalla pueda avisar antes.
     */
    public function putCodigo(string $codigo)
    {
        $user = $this->user;

        Autoriza::exigir(Autoriza::puedeAtarFormularios($user),
            'No tiene permiso para corregir el código de un formulario.');

        $orden = $this->ordenDelCodigo($codigo);

        if ($orden->estado === 'MATRICULADA') {
            abort(409, 'Ese formulario ya está cerrado con una matrícula: su código no se cambia.');
        }

        $campana = (int) $orden->year_campana;
        $sufijo = $this->sufijoValidado();

        // Pedir el que ya tiene no es un error ni una corrección: no se toca nada y
        // `codigo_anterior` se queda como estaba. Si esto escribiera, el papel
        // bueno pasaría a ser «el anterior» de sí mismo.
        if ($sufijo !== null && CodigoDeInscripcion::componer($campana, $sufijo) === $orden->codigo) {
            return $this->pintarOrden($orden) + ['tenia_pagos' => $this->tienePagos((int) $orden->id)];
        }

        $tenia = $this->tienePagos((int) $orden->id);
        $intentos = $sufijo === null ? self::INTENTOS_DE_CODIGO : 1;

        for ($intento = 0; $intento < $intentos; $intento++) {
            $nuevo = $sufijo === null
                ? CodigoDeInscripcion::generar($campana)
                : CodigoDeInscripcion::componer($campana, $sufijo);

            try {
                $tocadas = DB::update('UPDATE ordenes_inscripcion
                    SET codigo=?, codigo_anterior=?, updated_by=?, updated_at=?
                    WHERE id=? AND codigo=? AND deleted_at IS NULL',
                    [$nuevo, $orden->codigo, $user->user_id, Reloj::ahoraTexto(),
                        (int) $orden->id, $orden->codigo]);
            } catch (QueryException $e) {
                // **`ordenes_inscripcion_codigo` es PREFIJO de
                // `ordenes_inscripcion_codigo_anterior`**, así que este `str_contains`
                // casaría con los dos. Hoy da igual —el segundo es un índice de
                // búsqueda y no puede lanzar «Duplicate entry»— y queda dicho porque
                // el día que alguien le ponga un `UNIQUE` a esa columna, el choque se
                // leería aquí como «el aleatorio repitió» y se reintentaría para
                // siempre con el mismo resultado.
                if (! str_contains($e->getMessage(), 'ordenes_inscripcion_codigo')) {
                    throw $e;
                }

                if ($sufijo !== null) {
                    abort(409, 'Ese código ya está en uso. Escriba otro.');
                }

                continue;   // el aleatorio chocó: otro
            }

            if ($tocadas === 0) {
                abort(409, 'Ese formulario acaba de cambiar de código. Vuelva a mirarlo.');
            }

            return $this->pintarOrden($this->ordenPorId((int) $orden->id)) + ['tenia_pagos' => $tenia];
        }

        abort(500, 'No se pudo acuñar un código libre después de '.self::INTENTOS_DE_CODIGO.' intentos.');
    }

    /**
     * **El informe de la campaña**: qué se imprimió, qué se cobró y **quién compró y
     * no volvió**.
     *
     * Esa última lista es la que el docblock de la migración prometía —*«de aquí sale
     * la lista de quién compró y no volvió, que hoy no existe en ninguna parte y es
     * dinero que el colegio ya recibió»*— y hasta hoy no salía de ningún sitio:
     * `ordenes_inscripcion` sólo se podía leer **por lote**.
     *
     * ## Los matriculados NO se cuentan por `estado`, y ésa es la decisión del método
     *
     * Se cuentan con un `JOIN` vivo contra `matriculas`. El motivo está en
     * `putAlumno`: `estado = 'MATRICULADA'` lo escribe **sólo** este módulo, y
     * `matriculas` tiene **ocho escritores ajenos**, así que la columna va por detrás
     * siempre que alguien matricule por cualquiera de los otros siete caminos.
     * **Contarla daría una cifra que baja sola** y una lista de llamadas con gente
     * que ya está en clase.
     *
     * ## «Vendido» no es «acuñado», aunque la columna se llame `vendida_at`
     *
     * Conviene decirlo porque el nombre engaña: `vendida_por` y `vendida_at` se
     * escriben **al acuñar**, o sea al imprimir. Cincuenta formularios en blanco no
     * son cincuenta ventas. Lo que dice que alguien pagó es el **estado**
     * —`PAGADA` la pone la colilla aprobada o el webhook—, y por eso `recaudado`
     * suma sobre el estado y no sobre esa fecha.
     *
     * ## Y por eso «compró y no volvió» es una resta de dos cosas medidas distinto
     *
     * Pagó (el estado lo dice) **y** no tiene matrícula viva del año de la campaña
     * (el `JOIN` lo dice). Viaja con los teléfonos del alumno y de su primer
     * acudiente, porque para lo que sirve esa lista es para una tarde de llamadas.
     */
    public function getCampana()
    {
        $campana = $this->campanaDeLaPeticion();

        $porEstado = DB::select('SELECT estado, COUNT(*) AS cuantas,
                SUM(CASE WHEN alumno_id IS NOT NULL THEN 1 ELSE 0 END) AS atadas,
                SUM(COALESCE(valor,0)) AS suma
            FROM ordenes_inscripcion
            WHERE year_campana=? AND deleted_at IS NULL
            GROUP BY estado', [$campana]);

        $cuenta = array_fill_keys(self::ESTADOS, 0);
        $impresos = 0;
        $atados = 0;
        $recaudado = 0;

        foreach ($porEstado as $fila) {
            $cuenta[$fila->estado] = (int) $fila->cuantas;
            $impresos += (int) $fila->cuantas;
            $atados += (int) $fila->atadas;

            if (in_array($fila->estado, self::PAGADOS, true)) {
                $recaudado += (int) $fila->suma;
            }
        }

        $pagados = array_sum(array_map(fn ($e) => $cuenta[$e], self::PAGADOS));

        // El `JOIN` vivo, que es de donde sale la verdad. `LEFT JOIN … IS NULL` y no
        // un `NOT EXISTS` porque la misma consulta tiene que servir para contar y
        // para listar, y así las dos miran exactamente lo mismo.
        // **`actualizado_at` y NO `pagado_at`, que es como se llamaba y era mentira.**
        // `updated_at` es la última modificación de la fila, no la fecha del pago:
        // corregir el código de un formulario ya pagado la mueve, y la lista de
        // llamadas diría que pagó hoy. Es **el mismo pecado que `vendida_at`** dos
        // métodos más arriba, cometido al escribir esta consulta y cazado releyéndola.
        // La fecha del pago de verdad está en `colillas.resuelta_at` y
        // `pagos.verificado_at`, y las dos las devuelve el `GET` por código.
        $sinVolver = DB::select('SELECT o.codigo, o.estado, o.valor, o.updated_at AS actualizado_at,
                a.id AS alumno_id, a.nombres, a.apellidos, a.documento,
                a.telefono, a.celular, a.email
            FROM ordenes_inscripcion o
            LEFT JOIN alumnos a ON a.id=o.alumno_id AND a.deleted_at IS NULL
            LEFT JOIN matriculas m ON m.alumno_id=o.alumno_id AND m.deleted_at IS NULL
                AND (m.estado="ASIS" OR m.estado="MATR")
                AND m.grupo_id IN (SELECT g.id FROM grupos g
                    INNER JOIN years y ON y.id=g.year_id AND y.deleted_at IS NULL AND y.year=?
                    WHERE g.deleted_at IS NULL)
            WHERE o.year_campana=? AND o.deleted_at IS NULL
                AND o.estado IN ("'.implode('","', self::PAGADOS).'")
                AND m.id IS NULL
            GROUP BY o.id
            ORDER BY o.updated_at DESC
            LIMIT '.(self::MAXIMO_SIN_VOLVER + 1), [$campana, $campana]);

        $recortada = count($sinVolver) > self::MAXIMO_SIN_VOLVER;
        $sinVolver = array_slice($sinVolver, 0, self::MAXIMO_SIN_VOLVER);

        return [
            'year_campana' => $campana,
            'resumen' => [
                'impresos' => $impresos,
                'atados' => $atados,
                'en_blanco' => $impresos - $atados,
                'pagados' => $pagados,
                'matriculados' => $this->cuantosMatriculados($campana),
                'recaudado' => $recaudado,
                'sin_volver' => count($sinVolver),
            ],
            'por_estado' => $cuenta,
            // **Recortada y dicho**: una lista que se corta en silencio deja al
            // colegio llamando a los primeros quinientos y creyendo que no hay más.
            'sin_volver' => $this->conAcudientes($sinVolver),
            'sin_volver_recortada' => $recortada,
        ];
    }

    /**
     * @return array<int, object>
     */
    private function acunarEnBlanco(object $user, object $anio, int $campana, ?string $cierra): array
    {
        $cantidad = Request::input('cantidad');

        if (! is_numeric($cantidad) || (int) $cantidad != $cantidad) {
            abort(422, 'La cantidad tiene que ser un número entero.');
        }

        $cantidad = (int) $cantidad;

        if ($cantidad < 1 || $cantidad > self::MAXIMO_EN_BLANCO) {
            abort(422, 'La cantidad tiene que estar entre 1 y '.self::MAXIMO_EN_BLANCO.'.');
        }

        $grado_id = $this->gradoValidado();
        $lote_id = (string) Str::uuid();
        $ids = [];

        for ($i = 0; $i < $cantidad; $i++) {
            $ids[] = $this->insertarConCodigo($user, $anio, $campana, $lote_id, $cierra, [
                'modo' => 'nuevos',
                'alumno_id' => null,
                'grupo_id' => null,
                'grado_id' => $grado_id,
            ]);
        }

        return DB::select('SELECT * FROM ordenes_inscripcion WHERE id IN ('
            .implode(',', array_fill(0, count($ids), '?')).') ORDER BY id', $ids);
    }

    /**
     * @return array<int, object>
     */
    private function acunarRenovacion(object $user, object $anio, int $campana, ?string $cierra): array
    {
        $grupo_id = Request::input('grupo_id');

        if (! is_numeric($grupo_id)) {
            abort(422, 'Falta el grupo.');
        }

        // El grupo tiene que ser del año de la SESIÓN. Sin esto, un `grupo_id` del
        // cuerpo dejaría imprimir la renovación de un grupo de hace ocho años.
        $grupo = DB::selectOne('SELECT id, nombre FROM grupos
            WHERE id=? AND year_id=? AND deleted_at IS NULL', [(int) $grupo_id, $anio->id]);

        if (! $grupo) {
            abort(404, 'Ese grupo no es del año lectivo actual.');
        }

        // Los mismos dos estados que cuentan como «está en el colegio» en el resto
        // de la casa (`putConCantidadAlumnos`). Un retirado no renueva.
        $alumnos = DB::select('SELECT a.id
            FROM alumnos a
            INNER JOIN matriculas m ON m.alumno_id=a.id AND m.grupo_id=? AND m.deleted_at IS NULL
                AND (m.estado="ASIS" OR m.estado="MATR")
            WHERE a.deleted_at IS NULL
            ORDER BY a.apellidos, a.nombres', [$grupo->id]);

        if (count($alumnos) === 0) {
            abort(422, 'Ese grupo no tiene alumnos matriculados ni asistentes.');
        }

        $lote_id = 'antiguos-'.$campana.'-g'.$grupo->id;

        foreach ($alumnos as $alumno) {
            $ya = DB::selectOne('SELECT id FROM ordenes_inscripcion
                WHERE year_campana=? AND alumno_id=?', [$campana, $alumno->id]);

            if ($ya) {
                continue;   // reimpresión: se reusa el código, que es el requisito
            }

            $this->insertarConCodigo($user, $anio, $campana, $lote_id, $cierra, [
                'modo' => 'antiguos',
                'alumno_id' => (int) $alumno->id,
                'grupo_id' => (int) $grupo->id,
                'grado_id' => null,
            ]);
        }

        return DB::select('SELECT * FROM ordenes_inscripcion
            WHERE lote_id=? AND deleted_at IS NULL ORDER BY id', [$lote_id]);
    }

    /**
     * Inserta una orden acuñando el código, y devuelve su id.
     *
     * Dos únicos pueden saltar y **no significan lo mismo**, así que no se pueden
     * tratar igual:
     *
     *   - `ordenes_inscripcion_codigo`          el aleatorio chocó → otro código
     *   - `ordenes_inscripcion_alumno_campana`  otro lo creó a la vez → reusar
     *
     * Distinguirlos por el nombre del índice es lo que hace que una carrera entre
     * dos secretarías acabe en **un** código y no en un 500.
     *
     * @param  array{modo: string, alumno_id: ?int, grupo_id: ?int, grado_id: ?int}  $extra
     */
    private function insertarConCodigo(object $user, object $anio, int $campana,
        string $lote_id, ?string $cierra, array $extra): int
    {
        // Fuera del bucle: si el primer código choca y hay que reintentar, la orden
        // se vendió cuando se vendió, no cuando el aleatorio acertó.
        $ahora = Reloj::ahoraTexto();

        for ($intento = 0; $intento < self::INTENTOS_DE_CODIGO; $intento++) {
            try {
                // **El precio se ESTAMPA, no se referencia.** Lo que vale este
                // formulario es lo que valía el día que se acuñó: subir el precio en
                // marzo no puede reescribir lo que se vendió en enero. Con una clave
                // ajena a la configuración, la bandeja del tesorero enseñaría meses
                // después un importe distinto del que la familia pagó.
                DB::insert('INSERT INTO ordenes_inscripcion
                    (codigo, year_id, year_campana, lote_id, modo, alumno_id, grupo_id, grado_id,
                     cierra, valor, vendida_por, vendida_at, created_by, created_at, updated_at)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', [
                    CodigoDeInscripcion::generar($campana),
                    $anio->id, $campana, $lote_id, $extra['modo'],
                    $extra['alumno_id'], $extra['grupo_id'], $extra['grado_id'],
                    $cierra, $this->precioDelFormulario((int) $anio->id),
                    $user->user_id, $ahora, $user->user_id, $ahora, $ahora,
                ]);

                return (int) DB::getPdo()->lastInsertId();
            } catch (QueryException $e) {
                $mensaje = $e->getMessage();

                if (str_contains($mensaje, 'ordenes_inscripcion_alumno_campana')) {
                    $ya = DB::selectOne('SELECT id FROM ordenes_inscripcion
                        WHERE year_campana=? AND alumno_id=?', [$campana, $extra['alumno_id']]);

                    if ($ya) {
                        return (int) $ya->id;
                    }
                }

                if (! str_contains($mensaje, 'ordenes_inscripcion_codigo')) {
                    throw $e;
                }
            }
        }

        abort(500, 'No se pudo acuñar un código libre después de '.self::INTENTOS_DE_CODIGO.' intentos.');
    }

    /**
     * @param  array<int, object>  $filas
     */
    private function pintarLote(array $filas, int $campana, ?string $cierra): array
    {
        $alumnos = $this->datosDeLosAlumnos($filas);
        $grados = $this->nombresDeLosGrados($filas);

        $formularios = [];

        foreach ($filas as $fila) {
            $formularios[] = [
                'codigo' => $fila->codigo,
                'grado' => $fila->grado_id ? ($grados[$fila->grado_id] ?? null) : null,
                'grupo' => $alumnos[$fila->alumno_id]->grupo_actual ?? null,
                'alumno' => $fila->alumno_id ? ($alumnos[$fila->alumno_id] ?? null) : null,
            ];
        }

        return [
            'lote_id' => $filas[0]->lote_id,
            'year' => $campana,
            'cierra' => $cierra,
            'formularios' => $formularios,
            'colegio' => $this->membrete(),
        ];
    }

    /**
     * Los datos de los alumnos del lote, **en una consulta y con los textos ya
     * resueltos**.
     *
     * Uno por alumno sería `N+1` con cuarenta hojas, y el papel no puede imprimir un
     * `ciudad_id`.
     *
     * @param  array<int, object>  $filas
     * @return array<int, object>
     */
    private function datosDeLosAlumnos(array $filas): array
    {
        $ids = array_values(array_filter(array_map(fn ($f) => $f->alumno_id, $filas)));

        if (count($ids) === 0) {
            return [];
        }

        $huecos = implode(',', array_fill(0, count($ids), '?'));

        $alumnos = DB::select('SELECT a.id, a.nombres, a.apellidos, a.sexo, a.documento,
                a.fecha_nac, a.tipo_sangre, a.eps, a.direccion, a.barrio, a.estrato,
                a.telefono, a.celular, a.email, a.religion, a.no_matricula,
                td.tipo   AS tipo_doc,
                cd.ciudad AS ciudad_doc,
                cn.ciudad AS ciudad_nac,
                cr.ciudad AS ciudad_resid,
                g.nombre  AS grupo_actual
            FROM alumnos a
            LEFT JOIN tipos_documentos td ON td.id=a.tipo_doc AND td.deleted_at IS NULL
            LEFT JOIN ciudades cd ON cd.id=a.ciudad_doc AND cd.deleted_at IS NULL
            LEFT JOIN ciudades cn ON cn.id=a.ciudad_nac AND cn.deleted_at IS NULL
            LEFT JOIN ciudades cr ON cr.id=a.ciudad_resid AND cr.deleted_at IS NULL
            LEFT JOIN matriculas m ON m.alumno_id=a.id AND m.deleted_at IS NULL
                AND (m.estado="ASIS" OR m.estado="MATR")
            LEFT JOIN grupos g ON g.id=m.grupo_id AND g.deleted_at IS NULL
            WHERE a.id IN ('.$huecos.') AND a.deleted_at IS NULL
            GROUP BY a.id', $ids);

        $porId = [];

        foreach ($alumnos as $alumno) {
            $alumno->acudientes = [];
            $porId[$alumno->id] = $alumno;
        }

        // Los acudientes, también en una sola consulta. Salen **los dos primeros**:
        // es lo que cabe en la hoja, medido por el front (23 de 25 cm útiles con el
        // membrete detrás), y el tercero es raro.
        $acudientes = DB::select('SELECT p.alumno_id, p.parentesco,
                ac.nombres, ac.apellidos, ac.documento, ac.ocupacion,
                ac.telefono, ac.celular, ac.email,
                td.tipo AS tipo_doc
            FROM parentescos p
            INNER JOIN acudientes ac ON ac.id=p.acudiente_id AND ac.deleted_at IS NULL
            LEFT JOIN tipos_documentos td ON td.id=ac.tipo_doc AND td.deleted_at IS NULL
            WHERE p.alumno_id IN ('.$huecos.') AND p.deleted_at IS NULL
            ORDER BY p.alumno_id, p.id', $ids);

        foreach ($acudientes as $acudiente) {
            $alumno_id = $acudiente->alumno_id;

            if (! isset($porId[$alumno_id]) || count($porId[$alumno_id]->acudientes) >= 2) {
                continue;
            }

            unset($acudiente->alumno_id);
            $porId[$alumno_id]->acudientes[] = $acudiente;
        }

        return $porId;
    }

    /**
     * @param  array<int, object>  $filas
     * @return array<int, string>
     */
    private function nombresDeLosGrados(array $filas): array
    {
        $ids = array_values(array_unique(array_filter(array_map(fn ($f) => $f->grado_id, $filas))));

        if (count($ids) === 0) {
            return [];
        }

        $grados = DB::select('SELECT id, nombre FROM grados WHERE id IN ('
            .implode(',', array_fill(0, count($ids), '?')).') AND deleted_at IS NULL', $ids);

        return array_column(array_map(fn ($g) => (array) $g, $grados), 'nombre', 'id');
    }

    /**
     * El membrete, que es **el mismo que el del certificado de estudio**.
     *
     * Reusar `config_certificados` tiene dos efectos que no son gratis: el
     * formulario sale con la misma imagen que el resto de papeles del colegio, y
     * cada colegio lo ajusta **una vez**, donde ya lo ajustó. La consulta es la
     * misma que la de `CertificadosPersonaController`, a propósito: dos consultas
     * distintas para el mismo membrete acabarían pintando dos membretes distintos.
     */
    private function membrete(): array
    {
        $anio = DB::selectOne('SELECT y.nombre_colegio, y.encabezado_certificado,
                y.config_certificado_estudio_id, i.nombre AS logo
            FROM years y
            LEFT JOIN images i ON i.id=y.logo_id AND i.deleted_at IS NULL
            WHERE y.id=? AND y.deleted_at IS NULL', [$this->user->year_id]);

        $membrete = [
            'nombre' => $anio->nombre_colegio ?? null,
            'logo' => $anio->logo ?? null,
            'encabezado_certificado' => $anio->encabezado_certificado ?? null,
            'encabezado_nombre' => null,
            'encabezado_margin_top' => null,
            'encabezado_margin_left' => null,
            'piepagina_nombre' => null,
            'piepagina_height' => null,
            'piepagina_margin_bottom' => null,
            'piepagina_margin_left' => null,
        ];

        if (! $anio || ! $anio->config_certificado_estudio_id) {
            return $membrete;
        }

        $config = DB::selectOne('SELECT c.*, i.nombre AS encabezado_nombre, i2.nombre AS piepagina_nombre
            FROM config_certificados c
            LEFT JOIN images i ON i.id=c.encabezado_img_id AND i.deleted_at IS NULL
            LEFT JOIN images i2 ON i2.id=c.piepagina_img_id AND i2.deleted_at IS NULL
            WHERE c.id=?', [$anio->config_certificado_estudio_id]);

        if (! $config) {
            return $membrete;
        }

        return array_merge($membrete, [
            'encabezado_nombre' => $config->encabezado_nombre,
            'encabezado_margin_top' => $config->encabezado_margin_top,
            'encabezado_margin_left' => $config->encabezado_margin_left,
            'piepagina_nombre' => $config->piepagina_nombre,
            'piepagina_height' => $config->piepagina_height,
            'piepagina_margin_bottom' => $config->piepagina_margin_bottom,
            'piepagina_margin_left' => $config->piepagina_margin_left,
        ]);
    }

    /**
     * La fecha límite tal y como la tecleó el colegio.
     *
     * **El tope se valida aquí y no se le deja a la base**, que es regla de la casa:
     * el docker trunca en silencio a 120 y MariaDB 10.5 aborta, así que sin esto una
     * frase larga se guardaría a medias en desarrollo y reventaría en los dieciséis.
     */
    private function cierraValidado(): ?string
    {
        $cierra = Request::input('cierra');

        if ($cierra === null || trim((string) $cierra) === '') {
            return null;
        }

        $cierra = trim((string) $cierra);

        if (mb_strlen($cierra) > self::LARGO_CIERRA) {
            abort(422, 'La fecha límite no puede pasar de '.self::LARGO_CIERRA.' caracteres.');
        }

        return $cierra;
    }

    /**
     * Cuántas claves caben, y cuánto mide una.
     *
     * No es una restricción de negocio —el tope de verdad es el alto de la hoja, y
     * ése lo mide el front— sino de **no dejar que alguien meta un fichero en una
     * columna `text` que nadie vuelve a mirar**. El catálogo del front son 26.
     */
    private const MAXIMO_CAMPOS = 100;

    private const LARGO_CLAVE = 40;

    /**
     * El año de la petición, comprobado.
     *
     * Por defecto el de la sesión: la pantalla que llama esto está mirando un año
     * concreto y no tiene por qué repetirlo. Un año que no existe —o que está en la
     * papelera— es **404** y no un 200 que no escribió nada.
     */
    private function anioDeLaPeticion(mixed $year_id): int
    {
        if ($year_id === null || $year_id === '') {
            return (int) $this->user->year_id;
        }

        if (! is_numeric($year_id)) {
            abort(422, 'El año no es válido.');
        }

        $anio = DB::selectOne('SELECT id FROM years WHERE id=? AND deleted_at IS NULL',
            [(int) $year_id]);

        if (! $anio) {
            abort(404, 'Ese año lectivo no existe.');
        }

        return (int) $anio->id;
    }

    /**
     * @return list<string>
     */
    /**
     * El precio que manda el cliente, o `null`.
     *
     * **Ausente y cero no son lo mismo que se guarda igual**: el colegio que todavía
     * no ha decidido el precio y el que puso cero acaban los dos en `NULL`, porque en
     * los dos casos no hay nada que cobrar y el checkout los trata igual. Lo que no se
     * acepta es basura: un precio que no es un número entero positivo es un error del
     * cliente y no un cero.
     */
    private function precioValidado(): ?int
    {
        $valor = Request::input('valor');

        if ($valor === null || $valor === '') {
            return null;
        }

        if (! is_numeric($valor) || (float) $valor != (int) $valor || (int) $valor < 0) {
            abort(422, 'El precio del formulario tiene que ser un número entero de pesos.');
        }

        $valor = (int) $valor;

        if ($valor > self::MAXIMO_VALOR) {
            abort(422, 'Ese precio pasa de '.number_format(self::MAXIMO_VALOR, 0, ',', '.').' pesos. Revíselo.');
        }

        return $valor > 0 ? $valor : null;
    }

    /**
     * Ver la propiedad `$precio`: una consulta por petición, no una por formulario.
     */
    private function precioDelFormulario(int $yearId): ?int
    {
        if (! $this->precioLeido) {
            $fila = DB::selectOne('SELECT valor FROM config_formulario_inscripcion WHERE year_id=?',
                [$yearId]);

            $this->precio = ($fila !== null && $fila->valor !== null && (int) $fila->valor > 0)
                ? (int) $fila->valor
                : null;

            $this->precioLeido = true;
        }

        return $this->precio;
    }

    private function camposValidados(): array
    {
        $campos = Request::input('campos');

        if ($campos === null) {
            abort(422, 'Falta la lista de campos.');
        }

        if (! is_array($campos)) {
            abort(422, 'Los campos tienen que venir en una lista.');
        }

        if (count($campos) > self::MAXIMO_CAMPOS) {
            abort(422, 'No caben más de '.self::MAXIMO_CAMPOS.' campos.');
        }

        $limpios = [];

        foreach ($campos as $campo) {
            if (! is_string($campo)) {
                abort(422, 'Cada campo tiene que ser una clave de texto.');
            }

            $campo = trim($campo);

            // Una clave y no una frase. Sin esto, la columna acabaría guardando lo
            // que sea que mande un cliente con un error, y lo descubriríamos al
            // imprimir.
            if (preg_match('/^[a-z0-9_]{1,'.self::LARGO_CLAVE.'}$/', $campo) !== 1) {
                abort(422, "«{$campo}» no tiene forma de clave de campo.");
            }

            if (! in_array($campo, $limpios, true)) {
                $limpios[] = $campo;   // se conserva el orden, que es el del papel
            }
        }

        return $limpios;
    }

    /**
     * @return list<string>
     */
    private function decodificar(?string $json): array
    {
        if ($json === null || trim($json) === '') {
            return [];
        }

        $campos = json_decode($json, true);

        // Si lo de la columna no es una lista, se contesta vacío en vez de reventar:
        // vacío ya significa «el defecto», así que la pantalla sigue funcionando y el
        // colegio vuelve a elegir. Un 500 aquí dejaría la pantalla muerta por una
        // fila mal escrita a mano.
        return is_array($campos) ? array_values(array_filter($campos, 'is_string')) : [];
    }

    /**
     * Los estados de una orden, **en el orden del embudo**.
     *
     * Están escritos aquí y no se leen de la base por una razón concreta: el
     * `array_fill_keys` con esta lista es lo que hace que **un estado con cero salga
     * igualmente en la respuesta**. Sin él, la pantalla no puede distinguir
     * «ninguno» de «no me lo mandaron», que es la diferencia entre un tablero que
     * dice «0 rechazados» y uno que se deja el renglón.
     *
     * **Lo que NO hace es filtrar**, y conviene no creerlo: la columna es un
     * `varchar` sin `CHECK`, así que un estado inventado a mano en phpMyAdmin se
     * añade a la respuesta además de los cinco. **Eso es lo que hace falta** —un
     * estado que nadie puso ahí desde el código tiene que verse, no esconderse—,
     * pero significa que esta lista es un **mínimo garantizado**, no un censo
     * cerrado.
     */
    private const ESTADOS = ['IMPRESA', 'PAGADA', 'APROBADA', 'RECHAZADA', 'MATRICULADA'];

    /**
     * Los estados que significan **que el colegio ya recibió el dinero**.
     *
     * `PAGADA` la pone la colilla aprobada por el tesorero y también el webhook de
     * la pasarela. `APROBADA` no la escribe hoy nadie y va aquí de todas formas: el
     * día que alguien la use, dejarla fuera contaría un pago como impago **sin que
     * nada se ponga rojo**.
     */
    private const PAGADOS = ['PAGADA', 'APROBADA'];

    /**
     * Cuántos «compró y no volvió» caben en una respuesta.
     *
     * No es rendimiento: es que esa lista es **una tarde de llamadas**, y quinientas
     * ya son más de las que nadie hace en una tarde. Lo que importa es el
     * `sin_volver_recortada` que viaja al lado — una lista cortada en silencio deja
     * al colegio llamando a los primeros y creyendo que no hay más.
     */
    private const MAXIMO_SIN_VOLVER = 500;

    /**
     * La orden que lleva ese código, buscando **también por el código viejo**.
     *
     * El 422 y el 404 dicen cosas distintas y por eso no se juntan: lo primero es
     * *«eso no es un código nuestro»* —lo caza el carácter de control sin tocar la
     * base, que es también lo que impide usar esta ruta para tantear la tabla— y lo
     * segundo es *«es un código nuestro y no existe»*.
     */
    private function ordenDelCodigo(string $codigo): object
    {
        $normal = CodigoDeInscripcion::normalizar($codigo);

        if ($normal === null || ! CodigoDeInscripcion::esValido($normal)) {
            abort(422, 'Ese código no tiene la forma de un código de inscripción. Revíselo.');
        }

        $orden = DB::selectOne('SELECT * FROM ordenes_inscripcion
            WHERE codigo=? AND deleted_at IS NULL', [$normal]);

        if ($orden) {
            $orden->encontrado_por = 'codigo';

            return $orden;
        }

        $orden = DB::selectOne('SELECT * FROM ordenes_inscripcion
            WHERE codigo_anterior=? AND deleted_at IS NULL ORDER BY id DESC', [$normal]);

        if (! $orden) {
            abort(404, 'No hay ningún formulario con ese código.');
        }

        $orden->encontrado_por = 'codigo_anterior';

        return $orden;
    }

    private function ordenPorId(int $id): object
    {
        $orden = DB::selectOne('SELECT * FROM ordenes_inscripcion WHERE id=?', [$id]);

        if (! $orden) {
            abort(404, 'No hay ningún formulario con ese código.');
        }

        $orden->encontrado_por = 'codigo';

        return $orden;
    }

    /**
     * Una orden vista desde la ventanilla.
     *
     * Lleva los **textos** y no los ids por lo mismo que el papel: quien mira esto
     * tiene una familia delante. Y lleva `matricula` resuelta contra `matriculas` y
     * no contra `matricula_id`, porque esa columna sólo la escribe este módulo y el
     * alumno puede haberse matriculado por cualquiera de los otros siete caminos
     * (ver `putAlumno`).
     *
     * @return array<string, mixed>
     */
    private function pintarOrden(object $orden): array
    {
        $alumno = null;

        if ($orden->alumno_id !== null) {
            $alumno = DB::selectOne('SELECT a.id, a.nombres, a.apellidos, a.documento,
                    a.telefono, a.celular, a.email, g.nombre AS grupo_actual
                FROM alumnos a
                LEFT JOIN matriculas m ON m.alumno_id=a.id AND m.deleted_at IS NULL
                    AND (m.estado="ASIS" OR m.estado="MATR")
                LEFT JOIN grupos g ON g.id=m.grupo_id AND g.deleted_at IS NULL
                WHERE a.id=? AND a.deleted_at IS NULL
                GROUP BY a.id', [(int) $orden->alumno_id]);
        }

        $grado = null;

        if ($orden->grado_id !== null) {
            $fila = DB::selectOne('SELECT nombre FROM grados WHERE id=? AND deleted_at IS NULL',
                [(int) $orden->grado_id]);
            $grado = $fila->nombre ?? null;
        }

        $matricula = $orden->alumno_id === null
            ? null
            : $this->matriculaDeLaCampana((int) $orden->alumno_id, (int) $orden->year_campana);

        return [
            'codigo' => $orden->codigo,
            'codigo_anterior' => $orden->codigo_anterior,
            'encontrado_por' => $orden->encontrado_por ?? 'codigo',
            'year_campana' => (int) $orden->year_campana,
            'lote_id' => $orden->lote_id,
            'modo' => $orden->modo,
            'estado' => $orden->estado,
            'cierra' => $orden->cierra,
            'valor' => $orden->valor === null ? null : (int) $orden->valor,
            'vendida_at' => $orden->vendida_at,
            'grado' => $grado,
            'alumno' => $alumno,
            'matricula' => $matricula,
            'pagos' => $this->pagosDeLaOrden((int) $orden->id),
        ];
    }

    /**
     * El 409 del caso 2 de `putAlumno`, con **el código que ese alumno ya tiene
     * dentro del cuerpo**.
     *
     * El cuerpo importa tanto como el código HTTP: sin él la pantalla sólo puede
     * decir *«ya tiene uno»* y la secretaría tendría que ir a buscarlo, que es
     * exactamente el trabajo que este módulo existe para quitar.
     */
    private function yaTieneElSuyo(object $suya, object $alumno): never
    {
        abort(response()->json([
            'message' => 'Ese alumno ya tiene el formulario '.$suya->codigo.' para esta campaña. '
                .'El que trae en la mano queda libre para otra familia.',
            'codigo' => $suya->codigo,
            'alumno_id' => (int) $alumno->id,
            'year_campana' => (int) $suya->year_campana,
        ], 409));
    }

    /**
     * El alumno que manda el cuerpo, comprobado.
     *
     * Un alumno en la papelera es **404 y no un 200 que no escribió nada**: atar un
     * cobro a una ficha borrada es justo el caso en que el silencio se descubre
     * meses después.
     */
    private function alumnoDelCuerpo(): object
    {
        $alumno_id = Request::input('alumno_id');

        if (! is_numeric($alumno_id)) {
            abort(422, 'Falta el alumno.');
        }

        $alumno = DB::selectOne('SELECT id, nombres, apellidos FROM alumnos
            WHERE id=? AND deleted_at IS NULL', [(int) $alumno_id]);

        if (! $alumno) {
            abort(404, 'Ese alumno no existe.');
        }

        return $alumno;
    }

    /**
     * La matrícula viva del alumno **en el año de la campaña**, si la hay.
     *
     * `matriculas` no tiene `year_id`: el año va por `grupos.year_id`, así que el
     * `JOIN` es obligado y no un adorno. Y se compara contra `years.year` —el número
     * del año lectivo— y no contra un id, porque `year_campana` es *«el año al que la
     * familia se inscribe»* y **puede no tener fila todavía**; el día que la tenga,
     * esto la encuentra sin cambiar nada.
     */
    private function matriculaDeLaCampana(int $alumnoId, int $campana): ?object
    {
        return DB::selectOne('SELECT m.id, m.estado, m.fecha_matricula, g.nombre AS grupo
            FROM matriculas m
            INNER JOIN grupos g ON g.id=m.grupo_id AND g.deleted_at IS NULL
            INNER JOIN years y ON y.id=g.year_id AND y.deleted_at IS NULL
            WHERE m.alumno_id=? AND y.year=? AND m.deleted_at IS NULL
                AND (m.estado="ASIS" OR m.estado="MATR")
            ORDER BY m.id DESC', [$alumnoId, $campana]);
    }

    /**
     * Cierra la orden si su alumno ya está matriculado en la campaña.
     *
     * Se llama desde el camino idempotente de `putAlumno` —la misma llamada otra
     * vez— porque entre las dos el alumno puede haberse matriculado. **Un reintento
     * que no recomprueba deja la columna por detrás para siempre.**
     */
    private function cerrarSiYaEstaMatriculado(object $orden, object $user): void
    {
        if ($orden->estado === 'MATRICULADA' || $orden->alumno_id === null) {
            return;
        }

        $matricula = $this->matriculaDeLaCampana((int) $orden->alumno_id, (int) $orden->year_campana);

        if (! $matricula) {
            return;
        }

        DB::update('UPDATE ordenes_inscripcion
            SET matricula_id=?, estado="MATRICULADA", updated_by=?, updated_at=?
            WHERE id=? AND estado<>"MATRICULADA"',
            [(int) $matricula->id, $user->user_id, Reloj::ahoraTexto(), (int) $orden->id]);
    }

    /** Si por este formulario ya pasó dinero, por cualquiera de los dos caminos. */
    private function tienePagos(int $ordenId): bool
    {
        $fila = DB::selectOne('SELECT
                (SELECT COUNT(*) FROM colillas_inscripcion WHERE orden_id=?) AS colillas,
                (SELECT COUNT(*) FROM pagos_inscripcion WHERE orden_id=?) AS pagos',
            [$ordenId, $ordenId]);

        return ((int) $fila->colillas + (int) $fila->pagos) > 0;
    }

    /**
     * Los comprobantes y los pagos en línea de una orden, para la ventanilla.
     *
     * **El nombre del fichero de la colilla no viaja.** La URL es la llave —lo dice
     * el 41 §5— y esta ruta la puede llamar cualquiera de las 74 cuentas de
     * personal; lo que hace falta aquí es saber **si está pagado y si algo se
     * rechazó**, no poder abrir el recibo.
     *
     * @return array<string, mixed>
     */
    private function pagosDeLaOrden(int $ordenId): array
    {
        return [
            'colillas' => DB::select('SELECT id, estado, motivo, created_at, resuelta_at
                FROM colillas_inscripcion WHERE orden_id=? ORDER BY id', [$ordenId]),
            'en_linea' => DB::select('SELECT id, proveedor, estado, estado_pasarela,
                    monto_centavos, verificado_por, verificado_at, created_at
                FROM pagos_inscripcion WHERE orden_id=? ORDER BY id', [$ordenId]),
        ];
    }

    /**
     * El sufijo que teclea quien corrige, o `null` para que lo acuñe la API.
     *
     * Se valida contra el **mismo alfabeto** del generador, y eso es lo que hace que
     * la corrección no pueda producir un código ambiguo: la `O`, el `0`, la `I`, el
     * `1`, la `L`, la `S` y el `5` no están fuera por estética, están fuera porque
     * una persona los confunde leyendo un papel. Dejar colar una `O` aquí metería
     * en circulación justo el código que este módulo evita acuñar.
     */
    private function sufijoValidado(): ?string
    {
        $sufijo = Request::input('sufijo');

        if ($sufijo === null || trim((string) $sufijo) === '') {
            return null;
        }

        $sufijo = strtoupper(trim((string) $sufijo));
        $clase = preg_quote(CodigoDeInscripcion::ALFABETO, '/');

        if (preg_match('/^['.$clase.']{'.CodigoDeInscripcion::LARGO.'}$/', $sufijo) !== 1) {
            abort(422, 'El código son '.CodigoDeInscripcion::LARGO
                .' caracteres de «'.CodigoDeInscripcion::ALFABETO.'». '
                .'No lleva la O, el 0, la I, el 1, la L, la S ni el 5: se confunden en el papel.');
        }

        return $sufijo;
    }

    /**
     * De qué campaña es el informe.
     *
     * Por defecto **la misma cuenta que `postAcunar`** —el año de la sesión más
     * uno—, y por el mismo motivo: la pantalla que llama esto está mirando el año en
     * el que trabaja. Se deja elegir porque en enero el colegio sigue llamando a los
     * que compraron en septiembre, y para entonces la sesión ya está en el año nuevo.
     */
    private function campanaDeLaPeticion(): int
    {
        $campana = Request::input('year_campana');

        if ($campana === null || $campana === '') {
            $anio = DB::selectOne('SELECT year FROM years WHERE id=? AND deleted_at IS NULL',
                [$this->user->year_id]);

            if (! $anio) {
                abort(404, 'El año lectivo de la sesión no existe.');
            }

            return ((int) $anio->year) + 1;
        }

        if (! is_numeric($campana) || (int) $campana != $campana
            || (int) $campana < 2000 || (int) $campana > 2999) {
            abort(422, 'El año de la campaña no es válido.');
        }

        return (int) $campana;
    }

    /**
     * Cuántos de los formularios de la campaña acabaron en matrícula, **contado
     * contra `matriculas` y no contra `estado`** (ver `getCampana`).
     */
    private function cuantosMatriculados(int $campana): int
    {
        $fila = DB::selectOne('SELECT COUNT(DISTINCT o.id) AS cuantos
            FROM ordenes_inscripcion o
            INNER JOIN matriculas m ON m.alumno_id=o.alumno_id AND m.deleted_at IS NULL
                AND (m.estado="ASIS" OR m.estado="MATR")
            INNER JOIN grupos g ON g.id=m.grupo_id AND g.deleted_at IS NULL
            INNER JOIN years y ON y.id=g.year_id AND y.deleted_at IS NULL AND y.year=?
            WHERE o.year_campana=? AND o.deleted_at IS NULL AND o.alumno_id IS NOT NULL',
            [$campana, $campana]);

        return (int) ($fila->cuantos ?? 0);
    }

    /**
     * Le pega a cada fila el celular de su primer acudiente, **en una consulta**.
     *
     * Uno por fila sería `N+1` con quinientas. Y va aquí y no en el `SELECT` de
     * arriba porque un `JOIN` con `parentescos` multiplicaría las filas por el número
     * de acudientes, que es el error que convierte un informe en una lista con
     * repetidos.
     *
     * @param  array<int, object>  $filas
     * @return array<int, object>
     */
    private function conAcudientes(array $filas): array
    {
        $ids = array_values(array_filter(array_map(fn ($f) => $f->alumno_id, $filas)));

        // La clave se pone **antes** de salir por el atajo: una respuesta en la que
        // `acudiente` a veces está y a veces no obliga al front a comprobarlo en cada
        // fila, y la vez que se le olvide lo descubre en producción. La forma de la
        // respuesta no puede depender de si había datos.
        foreach ($filas as $fila) {
            $fila->acudiente = null;
        }

        if (count($ids) === 0) {
            return $filas;
        }

        $acudientes = DB::select('SELECT p.alumno_id, ac.nombres, ac.apellidos,
                ac.celular, ac.telefono, ac.email
            FROM parentescos p
            INNER JOIN acudientes ac ON ac.id=p.acudiente_id AND ac.deleted_at IS NULL
            WHERE p.alumno_id IN ('.implode(',', array_fill(0, count($ids), '?')).')
                AND p.deleted_at IS NULL
            ORDER BY p.alumno_id, p.id', $ids);

        $primero = [];

        foreach ($acudientes as $acudiente) {
            $alumno_id = $acudiente->alumno_id;

            if (! isset($primero[$alumno_id])) {
                unset($acudiente->alumno_id);
                $primero[$alumno_id] = $acudiente;
            }
        }

        foreach ($filas as $fila) {
            if ($fila->alumno_id !== null && isset($primero[$fila->alumno_id])) {
                $fila->acudiente = $primero[$fila->alumno_id];
            }
        }

        return $filas;
    }

    private function gradoValidado(): ?int
    {
        $grado_id = Request::input('grado_id');

        if ($grado_id === null || $grado_id === '') {
            return null;
        }

        if (! is_numeric($grado_id)) {
            abort(422, 'El grado no es válido.');
        }

        $grado = DB::selectOne('SELECT id FROM grados WHERE id=? AND deleted_at IS NULL',
            [(int) $grado_id]);

        if (! $grado) {
            abort(404, 'Ese grado no existe.');
        }

        return (int) $grado->id;
    }
}
