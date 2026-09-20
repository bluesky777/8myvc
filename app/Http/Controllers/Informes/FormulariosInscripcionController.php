<?php

namespace App\Http\Controllers\Informes;

use App\Http\Controllers\Concerns\ResuelveElUsuario;
use App\Http\Controllers\Controller;
use App\Services\CodigoDeInscripcion;
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
        DB::insert('INSERT INTO config_formulario_inscripcion
                (year_id, campos, valor, created_by, updated_by, created_at, updated_at)
            VALUES (?,?,?,?,?,NOW(),NOW())
            ON DUPLICATE KEY UPDATE campos=VALUES(campos), valor=VALUES(valor),
                updated_by=VALUES(updated_by), updated_at=NOW()',
            [$year_id, json_encode($campos, JSON_UNESCAPED_UNICODE), $valor,
                $user->user_id, $user->user_id]);

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
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW(),?,NOW(),NOW())', [
                    CodigoDeInscripcion::generar($campana),
                    $anio->id, $campana, $lote_id, $extra['modo'],
                    $extra['alumno_id'], $extra['grupo_id'], $extra['grado_id'],
                    $cierra, $this->precioDelFormulario((int) $anio->id),
                    $user->user_id, $user->user_id,
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
