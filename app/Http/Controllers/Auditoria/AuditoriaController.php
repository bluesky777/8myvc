<?php

namespace App\Http\Controllers\Auditoria;

use App\Http\Controllers\Concerns\ResuelveElUsuario;
use App\Http\Controllers\Controller;
use App\Services\Auditoria;
use App\Support\AlcanceAcademico;
use App\Support\AlcanceDelDocente;
use App\Support\Autoriza;
use App\Support\FichaEditada;
use App\Support\ListadoDeAuditoria;
use App\Support\Reloj;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Quién cambió qué, cuándo y desde qué ingreso.
 *
 * **Fase 5 de [`docs/migracion/18-auditoria.md`](../../../../docs/migracion/18-auditoria.md).**
 * Hasta hoy la tabla `auditoria` sólo tenía escritor: se llevaba grabando rastro
 * desde agosto y `grep 'FROM auditoria' app` daba **cero**. Esto es el lector.
 *
 * ## Tres reglas que gobiernan las cuatro rutas, y no son de estilo
 *
 * 1. **Sin `INNER JOIN` a las tablas de datos.** La fila de auditoría trae dentro
 *    el nombre del alumno y el del actor (§4.2), copiados a propósito, para que la
 *    línea se siga leyendo cuando la nota, la subunidad o la persona ya no existan.
 *    Unir contra `alumnos` para «mejorar» el nombre reintroduce justo el fallo que
 *    la denormalización cerró: el rastro de lo borrado deja de verse.
 * 2. **Un vacío es `200` con lista vacía; un id que no existe es `404`.** Hoy
 *    `historiales/sesion` aborta con `400 {message:'No hay historial'}` cuando no
 *    encuentra la fila, y el manejador del front lo pinta como un fallo de red. Con
 *    3.229 ingresos y el 99,4 % sin ninguna acción, **el vacío es el caso normal**:
 *    si se responde como un error, la pantalla nace rota para casi todo el mundo.
 * 3. **La respuesta dice cómo se supo de qué ingreso salió cada línea**
 *    (`atribucion`). Antes de la fase 2 el `historial_id` se adivinaba con el último
 *    login; después lo dice el token. La pantalla **tiene que poder decirlo** y no lo
 *    puede deducir: el navegador no sabe qué día se desplegó la fase 2 en su colegio.
 *
 * ## Lo que enseña cada fila, y por qué no el «de X a Y»
 *
 * La tabla devuelve `valor_nuevo` como *el* valor de la fila —40, 45, 50 bajando
 * por la pantalla cuenta la historia mejor que «de 40 a 45» repetido, y es la forma
 * que pidió Joseth el 21 sep—. `valor_anterior` viaja igual en la respuesta y no se
 * pinta de serie, porque es lo que hace visible un hueco: mientras sólo 42 de los
 * 221 métodos que escriben dejen rastro, un cambio hecho por un camino sin
 * instrumentar rompe la cadena, y con los dos valores delante la pantalla puede
 * enseñar que la rompió en vez de afirmar una continuidad falsa.
 *
 * ## Autorización: partida, y no sólo en la ruta
 *
 * Las cuatro llevan `auth.personal`, que deja pasar a 75 cuentas. El permiso
 * concreto va **dentro** (decisión 3): lo propio siempre, lo de otro con
 * `can_view_auditoria`. Un alumno ve su propia vida académica; **el acudiente
 * todavía no ve la de sus acudidos por esta puerta** y es una restricción
 * consciente, no un olvido — resolver «mis acudidos» pide la consulta de
 * parentesco y entra con la ruta que la necesite, no antes.
 */
class AuditoriaController extends Controller
{
    use ResuelveElUsuario;

    /** Cuántas líneas devuelve una pantalla como mucho. La auditoría crece sola y nadie lee 5.000 filas. */
    private const TOPE = 300;

    /**
     * **El orden de un historial lo decide el servidor, y es uno solo.**
     *
     * Hasta el 22 sep 2026 no lo decidía nadie: `getEntidad` ordenaba `a.id ASC` y
     * `getAlumno` `a.id DESC`, así que **las mismas tres líneas salían al revés según
     * por qué ruta se pidieran** —lo encontró `myvc-front-89` pintando el modal de
     * matrículas: `8305, 8306, 8307` por una y `8307, 8306, 8305` por la otra—.
     *
     * Lo que lo hace algo más que una inconsistencia bonita es el `LIMIT`: con tope,
     * **el orden decide qué líneas se pierden**. Con `ASC` el recorte se llevaba las
     * más recientes, que son justo las que se consultan cuando alguien reclama.
     *
     * Y el modo de fallo del cliente es el que no se ve: un historial del revés **se
     * lee perfectamente bien** y es mentira. No da error, no rompe nada, y quien lo
     * mire concluye que un cambio ocurrió antes que otro.
     *
     * `DESC` y no `ASC` porque lo primero que se quiere ver es lo último que pasó, y
     * porque así el tope conserva lo reciente. Por `id` y no por `ocurrido_en`: es
     * monótono y desempata solo — tres líneas del mismo segundo son normales, y con
     * `ocurrido_en` a secas su orden entre sí quedaría al azar del motor.
     */
    private const ORDEN = 'a.id DESC';

    /**
     * Los ingresos de un usuario, con **cuántas acciones hizo en cada uno**.
     *
     * El contador es lo que hace la lista útil: se ve de un vistazo cuál merece
     * abrirse, en vez de entrar a los 3.229 de uno en uno. Sale de un `LEFT JOIN`
     * agrupado y no de una consulta por fila, que es la forma natural de escribir
     * esto y la que convierte una pantalla en 200 consultas.
     */
    public function getIngresos(Request $peticion): JsonResponse
    {
        $user = $this->user;
        $deQuien = (int) ($peticion->input('user_id') ?: $user->user_id);

        Autoriza::exigirVerAuditoriaDe($user, $deQuien);

        /*
         * El rango por defecto es corto a propósito: sin él, el primer uso de la
         * pantalla barre la tabla entera del colegio.
         *
         * **`Reloj::ahora()` y no `now()`, y no es una formalidad del centinela.**
         * Estas dos fechas se comparan contra `historiales.created_at` y acotan lo que
         * se cruza con `auditoria.ocurrido_en`, que es `DATETIME` fijado a Bogotá
         * (decisión 1). `now()` sale en la zona de `config/app.php` —UTC—, así que a
         * partir de las 19:00 de Bogotá el «hoy» por defecto era el de mañana y el
         * rango entero se desplazaba un día. Es la misma discrepancia de cinco horas
         * que este documento avisa por el lado de la celda, aquí del lado del filtro.
         * Lo cazó `RelojUnicoTest` y lo trajo la sesión del front el 21 sep.
         */
        $desde = $peticion->input('desde') ?: Reloj::ahora()->subDays(30)->toDateString();
        $hasta = $peticion->input('hasta') ?: Reloj::ahora()->toDateString();

        // Con `pagina` o `por_pagina`, la pestaña Ingresos de `/auditoria`: página y
        // `total` en vez del tope. Sin ninguno de los dos, la respuesta de siempre.
        if ($peticion->filled('pagina') || $peticion->filled('por_pagina')) {
            return $this->ingresosPaginados($peticion, $deQuien, $desde, $hasta);
        }

        $ingresos = DB::select(
            'SELECT h.id, h.tipo, h.ip, h.created_at AS entro_en, h.logout_at,
                    h.browser_name, h.browser_version, h.platform_name, h.device_family,
                    COUNT(a.id) AS acciones
               FROM historiales h
               LEFT JOIN auditoria a ON a.historial_id = h.id
              WHERE h.user_id = ?
                AND h.deleted_at IS NULL
                AND h.created_at >= ?
                AND h.created_at < DATE_ADD(?, INTERVAL 1 DAY)
              GROUP BY h.id
              ORDER BY h.id DESC
              LIMIT '.(self::TOPE + 1),
            [$deQuien, $desde, $hasta]
        );

        [$ingresos, $hayMas] = $this->recortadas($ingresos);

        return response()->json([
            'user_id' => $deQuien,
            'desde' => $desde,
            'hasta' => $hasta,
            'ingresos' => $ingresos,
            'hay_mas' => $hayMas,
        ]);
    }

    /**
     * Los ingresos de `getIngresos`, por páginas de 25, 50 o 100, y cada uno con su
     * `hecho_desde` (app, web desde el celular o desde el computador). El `total` sale de
     * `historiales` sola —el `LEFT JOIN` no cambia cuántos ingresos hay—, y `hay_mas`
     * sigue viajando para quien ya lo lea. Sin `pagina` ni `por_pagina` no llega nada de
     * esto: la respuesta de siempre no cambia.
     */
    private function ingresosPaginados(Request $peticion, int $deQuien, string $desde, string $hasta): JsonResponse
    {
        [$pagina, $porPagina] = self::paginacion($peticion);
        $filtro = 'h.user_id = ? AND h.deleted_at IS NULL
                   AND h.created_at >= ? AND h.created_at < DATE_ADD(?, INTERVAL 1 DAY)';
        $parametros = [$deQuien, $desde, $hasta];

        $total = (int) DB::selectOne("SELECT COUNT(*) AS total FROM historiales h WHERE $filtro", $parametros)->total;

        $ingresos = DB::select(
            "SELECT h.id, h.tipo, h.ip, h.created_at AS entro_en, h.logout_at,
                    h.browser_name, h.browser_version, h.platform_name, h.device_family,
                    h.entorno, h.browser_family, h.platform_family,
                    COUNT(a.id) AS acciones
               FROM historiales h
               LEFT JOIN auditoria a ON a.historial_id = h.id
              WHERE $filtro
              GROUP BY h.id
              ORDER BY h.id DESC
              LIMIT $porPagina OFFSET ".(($pagina - 1) * $porPagina),
            $parametros
        );

        // `hecho_desde` con la regla del listado de alumnos; las tres columnas de las que
        // sale no viajan, para que la fila sea la de siempre más ese campo.
        foreach ($ingresos as $h) {
            $h->hecho_desde = ListadoDeAuditoria::hechoDesde($h);
            unset($h->entorno, $h->browser_family, $h->platform_family);
        }

        return response()->json([
            'user_id' => $deQuien,
            'desde' => $desde,
            'hasta' => $hasta,
            'pagina' => $pagina,
            'por_pagina' => $porPagina,
            'total' => $total,
            'ingresos' => $ingresos,
            'hay_mas' => $pagina * $porPagina < $total,
        ]);
    }

    /**
     * El detalle de un ingreso: qué hizo, en orden.
     *
     * Es la pantalla que se pidió en origen. `404` si el ingreso no existe; `200`
     * con `acciones: []` si existe y no hizo nada, que es el caso mayoritario.
     */
    public function getIngreso(int $id): JsonResponse
    {
        $ingreso = DB::select(
            'SELECT id, user_id, tipo, ip, created_at AS entro_en, logout_at,
                    browser_name, browser_version, platform_name, device_family
               FROM historiales WHERE id = ? AND deleted_at IS NULL',
            [$id]
        );

        if ($ingreso === []) {
            return response()->json(['message' => 'Ese ingreso no existe'], 404);
        }

        $ingreso = $ingreso[0];

        // El permiso se mira contra el DUEÑO del ingreso, no contra quien pregunta:
        // el id de la URL no dice de quién es, y creerlo sería la regla del sujeto
        // al revés (§ «La regla del sujeto» del 18).
        Autoriza::exigirVerAuditoriaDe($this->user, $ingreso->user_id);

        /*
         * **`ASC` aquí y `DESC` en las otras dos, y es a propósito.** Un ingreso es una
         * sesión acotada y la pregunta es «qué hizo, y en qué orden»; un historial de
         * entidad o de alumno está abierto por arriba y la pregunta es «qué es lo
         * último». No es la inconsistencia que se arregló en `f2fcd6c`: aquélla eran
         * dos rutas contestando lo mismo del revés.
         */
        [$acciones, $hayMas] = $this->lineas('a.historial_id = ?', [$id], 'a.id ASC');

        return response()->json([
            'ingreso' => $ingreso,
            'acciones' => $acciones,
            'hay_mas' => $hayMas,
        ]);
    }

    /**
     * La vida entera de una nota, una asistencia o una frase.
     *
     * Sustituye a `historiales/nota-detalle` y `nota-final-detalle`, que sólo saben
     * de dos entidades y leen la tabla congelada. El tipo se valida contra el
     * vocabulario cerrado del servicio: un `entidad` libre aquí dejaría que la URL
     * preguntara por cualquier cosa y devolviera siempre vacío, que es la forma más
     * cara de no encontrar nada.
     */
    public function getEntidad(string $tipo, int $id): JsonResponse
    {
        if (! array_key_exists($tipo, Auditoria::ENTIDADES)) {
            return response()->json(['message' => 'Ese tipo de entidad no se audita'], 404);
        }

        // Sin el permiso, el docente: si alguna línea de esa nota, definitiva, nivelación o
        // ausencia cae en su alcance, la historia entera de la entidad; si no, `403`.
        $profesor = AlcanceDelDocente::profesorDe($this->user);
        Autoriza::exigir(
            Autoriza::puedeVerAuditoria($this->user)
                || ($profesor !== null && AlcanceDelDocente::incluyeEntidad($profesor, $tipo, $id)),
            'No tiene permiso para ver la auditoría'
        );

        [$acciones, $hayMas] = $this->lineas('a.entidad = ? AND a.entidad_id = ?', [$tipo, $id], self::ORDEN);

        return response()->json([
            'entidad' => $tipo,
            'entidad_id' => $id,
            'acciones' => $acciones,
            'hay_mas' => $hayMas,
        ]);
    }

    /**
     * Todo lo que se le ha hecho a un alumno. Es la que contesta un reclamo de acudiente.
     */
    public function getAlumno(int $id): JsonResponse
    {
        $user = $this->user;

        // Un alumno ve lo suyo sin permiso; cualquier otro lo necesita. `persona_id`
        // es el id de la ficha —el de `alumnos`—, no el de `users`: son distintos y
        // confundirlos aquí abriría la auditoría de todos a cualquier alumno.
        $esSuyo = ($user->tipo ?? null) === 'Alumno' && (int) ($user->persona_id ?? 0) === $id;

        if (! $esSuyo) {
            Autoriza::exigir(
                Autoriza::puedeVerAuditoria($user),
                'No tiene permiso para ver la auditoría de un alumno'
            );
        }

        [$acciones, $hayMas] = $this->lineas('a.alumno_id = ?', [$id], self::ORDEN);

        return response()->json([
            'alumno_id' => $id,
            'acciones' => $acciones,
            'hay_mas' => $hayMas,
        ]);
    }

    /**
     * Lo editado en la FICHA de una persona —alumno, acudiente, profesor o la cuenta—:
     * todo lo que pinta su fila en las rejillas, aunque sean varias tablas. Qué entra en
     * cada una lo dice `FichaEditada`. Es el diálogo de las columnas Historial.
     */
    public function getFicha(string $tipo, int $id): JsonResponse
    {
        if (! in_array($tipo, FichaEditada::TIPOS, true)) {
            return response()->json(['message' => 'Ese tipo de ficha no existe'], 404);
        }

        Autoriza::exigir(
            Autoriza::puedeVerAuditoria($this->user),
            'No tiene permiso para ver la auditoría'
        );

        [$donde, $parametros] = FichaEditada::condicion($tipo, $id);
        [$acciones, $hayMas] = $this->lineas('('.$donde.')', $parametros, self::ORDEN);

        return response()->json([
            'tipo' => $tipo,
            'id' => $id,
            'acciones' => $acciones,
            'hay_mas' => $hayMas,
        ]);
    }

    /**
     * LOS LISTADOS DE AUDITORÍA DE ALUMNOS: lo cambiado en los datos, las notas o la
     * convivencia de todos los alumnos, paginado y con filtros. La consulta y lo que se
     * añade a cada línea viven en `ListadoDeAuditoria`; aquí, el permiso y la entrada.
     *
     * A diferencia de las demás rutas no hay tope: hay página y `total`, porque es un
     * listado que se recorre y no el historial de una cosa. Con `solo_total=1` viene el
     * `total` y el `resumen` con `acciones: []`.
     *
     * `todas` es la lista de la pestaña «Por persona»: las tres familias mezcladas, con
     * los tipos de las tres y las importaciones. Sólo con el permiso: al docente, `403`.
     */
    public function getAlumnos(Request $peticion, string $familia): JsonResponse
    {
        if (! isset(ListadoDeAuditoria::FAMILIAS[$familia]) && $familia !== ListadoDeAuditoria::TODAS) {
            return response()->json(['message' => 'Esa familia no existe'], 404);
        }

        // El docente sin permiso sólo tiene la familia de notas; datos y convivencia, `403`.
        $alcance = $this->alcanceDelListado();
        Autoriza::exigir($alcance === null || $familia === 'notas', 'No tiene permiso para ver la auditoría');

        foreach (['desde', 'hasta'] as $clave) {
            $fecha = $peticion->query($clave);
            if ($fecha !== null && $fecha !== '' && (! is_string($fecha) || ! self::esFecha($fecha))) {
                return response()->json(['message' => "La fecha '$clave' tiene que ser AAAA-MM-DD"], 422);
            }
        }

        [$pagina, $porPagina] = self::paginacion($peticion);

        [$donde, $parametros] = ListadoDeAuditoria::filtro($familia, $peticion->query());
        // El alcance va con `AND` sobre el filtro: ningún filtro de la URL lo ensancha.
        if ($alcance !== null) {
            $donde = "$donde AND {$alcance[0]}";
            array_push($parametros, ...$alcance[1]);
        }
        // Datos lleva además las líneas de la importación de alumnos, que viven en
        // `importaciones.cambios` y no en `auditoria` (ver `ListadoDeAuditoria::deImportaciones`).
        $deImportacion = in_array($familia, ['datos', ListadoDeAuditoria::TODAS], true) && $alcance === null
            ? ListadoDeAuditoria::deImportaciones($peticion->query(), $familia)
            : [];
        $totales = ListadoDeAuditoria::conImportaciones($donde, $parametros, $deImportacion,
            ListadoDeAuditoria::totales($donde, $parametros));
        // `solo_total=1`: los contadores de las pestañas, sin leer página.
        $desde = ($pagina - 1) * $porPagina;
        $enLaPagina = match (true) {
            $peticion->boolean('solo_total') => [],
            $deImportacion === [] => ListadoDeAuditoria::pagina($donde, $parametros, $desde, $porPagina),
            default => ListadoDeAuditoria::paginaMezclada($donde, $parametros, $deImportacion, $desde, $porPagina),
        };

        $ids = array_values(array_filter($enLaPagina, 'is_int'));
        $deAuditoria = [];
        if ($ids !== []) {
            [$lineas] = $this->lineas(
                'a.id IN ('.implode(',', array_fill(0, count($ids), '?')).')',
                $ids,
                'a.ocurrido_en DESC, a.id DESC'
            );
            foreach ($lineas as $l) {
                $deAuditoria[(int) $l->id] = $l;
            }
        }
        // En el orden de la página, que ya viene mezclado.
        $acciones = array_values(array_filter(array_map(
            fn ($x) => is_int($x) ? ($deAuditoria[$x] ?? null) : $x,
            $enLaPagina
        )));
        ListadoDeAuditoria::completar($acciones, $familia);

        return response()->json([
            'familia' => $familia,
            'pagina' => $pagina,
            'por_pagina' => $porPagina,
            'total' => $totales['total'],
            'resumen' => ['alumnos' => $totales['alumnos'], 'actores' => $totales['actores']],
            'acciones' => $acciones,
        ]);
    }

    /**
     * Quién aparece como actor en los listados de alumnos: el desplegable «Cambiado por».
     * Al docente, sólo quien aparece en su alcance.
     */
    public function getAlumnosActores(): JsonResponse
    {
        return response()->json(ListadoDeAuditoria::actores($this->alcanceDelListado()));
    }

    /**
     * EL RANKING DE LA PESTAÑA «POR PERSONA»: quién hizo cambios en el rango, del que más
     * al que menos, con su reparto por familia (`ListadoDeAuditoria::personasConCambios`).
     * `rol` filtra por el rol legible (`Docente`, `Coordinación`…). Paginado como los
     * listados; `total` es cuántas personas. Sólo con el permiso.
     */
    public function getPersonasConCambios(Request $peticion): JsonResponse
    {
        Autoriza::exigir(Autoriza::puedeVerAuditoria($this->user), 'No tiene permiso para ver la auditoría');
        if ($error = self::fechasMalas($peticion)) {
            return $error;
        }

        [$pagina, $porPagina] = self::paginacion($peticion);
        $rol = is_scalar($peticion->query('rol')) ? trim((string) $peticion->query('rol')) : '';
        $todas = ListadoDeAuditoria::personasConCambios($peticion->query(), $rol);

        return response()->json([
            'pagina' => $pagina,
            'por_pagina' => $porPagina,
            'total' => count($todas),
            'filas' => array_slice($todas, ($pagina - 1) * $porPagina, $porPagina),
        ]);
    }

    /**
     * EL RESUMEN DE UNA PERSONA en la pestaña «Por persona»: sus tarjetas, sus chips de
     * tipo y su lista de asignaturas (`ListadoDeAuditoria::resumenDePersona`). Sólo con el
     * permiso.
     */
    public function getResumenDePersona(Request $peticion, int $userId): JsonResponse
    {
        Autoriza::exigir(Autoriza::puedeVerAuditoria($this->user), 'No tiene permiso para ver la auditoría');
        if ($error = self::fechasMalas($peticion)) {
            return $error;
        }

        return response()->json(ListadoDeAuditoria::resumenDePersona($userId, $peticion->query()));
    }

    /** El `422` de un `desde` o `hasta` que no es AAAA-MM-DD, como en los listados. */
    private static function fechasMalas(Request $peticion): ?JsonResponse
    {
        foreach (['desde', 'hasta'] as $clave) {
            $fecha = $peticion->query($clave);
            if ($fecha !== null && $fecha !== '' && (! is_string($fecha) || ! self::esFecha($fecha))) {
                return response()->json(['message' => "La fecha '$clave' tiene que ser AAAA-MM-DD"], 422);
            }
        }

        return null;
    }

    /**
     * QUÉ PESTAÑAS DE `/auditoria` VE QUIEN PREGUNTA, para que el front no lo adivine.
     *
     * Con el permiso, todo. El docente sin él, las notas de su alcance (`AlcanceDelDocente`)
     * y sus propios ingresos. El resto del personal sin permiso, sólo sus ingresos: la
     * entrada del menú es de todo el personal y la pestaña de lo propio no pide nada.
     */
    public function getPermisos(): JsonResponse
    {
        $todo = Autoriza::puedeVerAuditoria($this->user);
        $docente = ! $todo && AlcanceDelDocente::profesorDe($this->user) !== null;

        return response()->json([
            'familias' => $todo ? array_keys(ListadoDeAuditoria::FAMILIAS) : ($docente ? ['notas'] : []),
            'ingresos_de_otros' => $todo,
            'bitacora' => $todo,
            'alcance' => $todo ? 'todo' : ($docente ? 'docente' : 'ninguno'),
            // La pestaña «Limpieza»: sólo el superusuario, como `AuditoriaLimpiezaController`.
            'limpieza' => Autoriza::esSuperusuario($this->user),
        ]);
    }

    /**
     * LA BITÁCORA ANTERIOR, paginada: la tabla `bitacoras`, el rastro de antes de
     * `auditoria`, para la pestaña «Bitácora anterior». `GET bitacoras/{user_id?}` no se
     * toca: devuelve la tabla entera de una persona y la leen otros clientes.
     *
     * `created_by` es el `users.id` de quien lo hizo; `affected_person_*`, sobre quién
     * (`affected_person_type`: `Al`, `Pr`…); `affected_element_*`, qué cosa y sus valores
     * viejo y nuevo, en número o en texto según la escribió cada controlador. Lo borrado
     * (`deleted_at`) no sale, como en `BitacorasController`. Las fechas van como están:
     * la tabla las tiene en dos zonas sin nada en la fila que diga cuál
     * (`create_auditoria_table` §4.1), y convertirlas sería inventar.
     */
    public function getBitacoraAnterior(Request $peticion): JsonResponse
    {
        Autoriza::exigir(
            Autoriza::puedeVerAuditoria($this->user),
            'No tiene permiso para ver la auditoría'
        );

        foreach (['desde', 'hasta'] as $clave) {
            $fecha = $peticion->query($clave);
            if ($fecha !== null && $fecha !== '' && (! is_string($fecha) || ! self::esFecha($fecha))) {
                return response()->json(['message' => "La fecha '$clave' tiene que ser AAAA-MM-DD"], 422);
            }
        }

        [$pagina, $porPagina] = self::paginacion($peticion);
        $texto = fn (string $clave) => is_scalar($peticion->query($clave)) ? trim((string) $peticion->query($clave)) : '';

        $donde = ['b.deleted_at IS NULL'];
        $parametros = [];
        if (($id = (int) $texto('user_id')) > 0) {
            $donde[] = 'b.created_by = ?';
            $parametros[] = $id;
        }
        if (($desde = $texto('desde')) !== '') {
            $donde[] = 'b.created_at >= ?';
            $parametros[] = $desde.' 00:00:00';
        }
        if (($hasta = $texto('hasta')) !== '') {
            $donde[] = 'b.created_at < ?';
            $parametros[] = date('Y-m-d', (int) strtotime($hasta.' +1 day')).' 00:00:00';
        }
        if (($q = $texto('q')) !== '') {
            $como = '%'.addcslashes($q, '\\%_').'%';
            $donde[] = '(b.descripcion LIKE ? OR b.affected_person_name LIKE ?)';
            array_push($parametros, $como, $como);
        }
        $donde = implode(' AND ', $donde);

        $total = (int) DB::selectOne("SELECT COUNT(*) AS total FROM bitacoras b WHERE $donde", $parametros)->total;

        // Quien lo hizo (`created_by`) y, si fue sobre un alumno, el alumno
        // (`affected_user_id` → `alumnos.user_id`), con nombre y foto en la misma consulta.
        [$deQuien, $nombre] = self::nombreDeCuenta('u', 'b.created_by');
        $filas = DB::select(
            "SELECT b.id, b.created_by, b.historial_id, b.descripcion,
                    b.affected_user_id, b.affected_person_id, b.affected_person_name, b.affected_person_type,
                    b.affected_element_type, b.affected_element_id,
                    b.affected_element_old_value_int, b.affected_element_new_value_int,
                    b.affected_element_old_value_string, b.affected_element_new_value_string,
                    b.periodo_id, b.created_at,
                    $nombre AS actor_nombre, u.tipo AS actor_tipo,
                    COALESCE(ipu.nombre, iu.nombre) AS actor_foto,
                    NULLIF(TRIM(CONCAT_WS(' ', afe.nombres, afe.apellidos)), '') AS alumno_nombre,
                    ia.nombre AS alumno_foto
               FROM bitacoras b
               $deQuien
               LEFT JOIN images ipu ON ipu.id = p_u.foto_id AND ipu.deleted_at IS NULL
               LEFT JOIN images iu ON iu.id = u.imagen_id AND iu.deleted_at IS NULL
               LEFT JOIN alumnos afe ON afe.id = (SELECT MIN(a3.id) FROM alumnos a3
                                                   WHERE a3.user_id = b.affected_user_id AND a3.deleted_at IS NULL)
               LEFT JOIN images ia ON ia.id = afe.foto_id AND ia.deleted_at IS NULL
              WHERE $donde
              ORDER BY b.id DESC
              LIMIT $porPagina OFFSET ".(($pagina - 1) * $porPagina),
            $parametros
        );

        return response()->json([
            'pagina' => $pagina,
            'por_pagina' => $porPagina,
            'total' => $total,
            'filas' => $filas,
        ]);
    }

    /**
     * Las personas con al menos un ingreso: el selector de persona de la pestaña Ingresos.
     * `[{user_id, nombre, tipo, rol, foto}]` por nombre, como «Cambiado por». Sin tope de
     * fechas: en la copia de caz (30 sep 2026) son 321 personas de 13.270 ingresos, y el
     * `DISTINCT` sale del índice `historiales_user_id_foreign`.
     */
    public function getIngresosPersonas(): JsonResponse
    {
        Autoriza::exigir(
            Autoriza::puedeVerAuditoria($this->user),
            'No tiene permiso para ver la auditoría de otras personas'
        );

        [$deQuien, $nombre] = self::nombreDeCuenta('u', 'h.user_id');

        return response()->json(ListadoDeAuditoria::personas(DB::select(
            "SELECT h.user_id, $nombre AS nombre, u.tipo
               FROM (SELECT DISTINCT user_id FROM historiales WHERE deleted_at IS NULL AND user_id IS NOT NULL) h
               $deQuien
              WHERE u.id IS NOT NULL"
        )));
    }

    /**
     * El nombre de una cuenta por su ficha —docente, alumno o acudiente— o, sin ficha (las
     * cuentas de administración), su `username`. Devuelve los `LEFT JOIN` desde `$columna`
     * (alias `$u` para `users`, `p_$u`, `al_$u` y `ac_$u` para las fichas) y la expresión.
     * La ficha es la de id más bajo: una cuenta con dos no duplica la fila.
     *
     * @return array{0: string, 1: string}
     */
    private static function nombreDeCuenta(string $u, string $columna): array
    {
        $ficha = fn (string $tabla, string $alias) => "LEFT JOIN $tabla {$alias}_$u ON {$alias}_$u.id = (
            SELECT MIN(x.id) FROM $tabla x WHERE x.user_id = $u.id AND x.deleted_at IS NULL)";

        return [
            "LEFT JOIN users $u ON $u.id = $columna
             ".$ficha('profesores', 'p').'
             '.$ficha('alumnos', 'al').'
             '.$ficha('acudientes', 'ac'),
            "COALESCE(NULLIF(TRIM(CONCAT_WS(' ', p_$u.nombres, p_$u.apellidos)), ''),
                      NULLIF(TRIM(CONCAT_WS(' ', al_$u.nombres, al_$u.apellidos)), ''),
                      NULLIF(TRIM(CONCAT_WS(' ', ac_$u.nombres, ac_$u.apellidos)), ''),
                      $u.username)",
        ];
    }

    /**
     * El alcance de los listados de alumnos para quien pregunta: `null` si lo ve todo, la
     * condición de `AlcanceDelDocente` si es docente sin el permiso, y `403` si no es
     * ninguna de las dos.
     *
     * @return array{0: string, 1: list<mixed>}|null
     */
    private function alcanceDelListado(): ?array
    {
        if (Autoriza::puedeVerAuditoria($this->user)) {
            return null;
        }

        $profesor = AlcanceDelDocente::profesorDe($this->user);
        Autoriza::exigir($profesor !== null, 'No tiene permiso para ver la auditoría');

        return AlcanceDelDocente::condicion($profesor);
    }

    /**
     * `pagina` (1..) y `por_pagina` (25|50|100; otro valor, 25), como en los listados.
     *
     * @return array{0: int, 1: int}
     */
    private static function paginacion(Request $peticion): array
    {
        $porPagina = (int) $peticion->query('por_pagina', 25);

        return [
            max(1, (int) $peticion->query('pagina', 1)),
            in_array($porPagina, [25, 50, 100], true) ? $porPagina : 25,
        ];
    }

    private static function esFecha(string $fecha): bool
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $fecha, $m) === 1
            && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    /**
     * Las columnas Historial de las pantallas académicas (planilla, asistencias,
     * comportamiento…): lo que ESA tabla enseña de cada alumno. Ver `AlcanceAcademico`.
     *
     * `PUT auditoria/alcance/fechas` → `{fechas: {alumno_id: fecha}}` para toda la rejilla;
     * `PUT auditoria/alcance/lineas` → las líneas de un alumno, con la forma de las demás.
     */
    public function putAlcanceFechas(Request $peticion): JsonResponse
    {
        [$entidades, $filtros] = $this->alcance($peticion);
        $alumnos = array_values(array_filter(array_map('intval', (array) $peticion->input('alumnos', []))));

        return response()->json(['fechas' => (object) AlcanceAcademico::fechas($entidades, $alumnos, $filtros)]);
    }

    public function putAlcanceLineas(Request $peticion): JsonResponse
    {
        [$entidades, $filtros] = $this->alcance($peticion);
        $alumno = (int) $peticion->input('alumno_id');
        if ($alumno <= 0) {
            return response()->json(['message' => 'Falta el alumno'], 422);
        }

        [$sub, $parametros] = AlcanceAcademico::lineas($entidades, [$alumno], $filtros);
        [$acciones, $hayMas] = $this->lineas("a.id IN (SELECT x.id FROM ($sub) x)", $parametros, self::ORDEN);

        return response()->json(['alumno_id' => $alumno, 'acciones' => $acciones, 'hay_mas' => $hayMas]);
    }

    /** @return array{0: string[], 1: array<string, int>} */
    private function alcance(Request $peticion): array
    {
        // Sin el permiso, el docente de la asignatura: sólo lo acotado a ESA asignatura.
        $conPermiso = Autoriza::puedeVerAuditoria($this->user);
        Autoriza::exigir(
            $conPermiso || Autoriza::daLaAsignatura($this->user, (int) $peticion->input('asignatura_id')),
            'No tiene permiso para ver la auditoría'
        );
        $permitidas = $conPermiso ? AlcanceAcademico::entidades() : AlcanceAcademico::entidadesDeAsignatura();

        $entidades = array_values(array_intersect((array) $peticion->input('entidades', []), $permitidas));
        abort_if(! $entidades, 422, 'Ninguna entidad del alcance');

        $filtros = [];
        foreach (['asignatura_id', 'periodo_id', 'subunidad_id', 'year', 'year_id'] as $clave) {
            if ($peticion->filled($clave)) {
                $filtros[$clave] = (int) $peticion->input($clave);
            }
        }

        return [$entidades, $filtros];
    }

    /**
     * EL HISTORIAL DE UN AÑO: lo que se cambió del Plan de evaluación (`ambito=plan`) o de
     * la configuración del año (`ambito=config`). Cada línea sale con su `seccion` en
     * palabras —la pestaña de la pantalla donde se hizo—, que es por lo que la pantalla
     * filtra. Las escrituras las apunta `Support\AuditarFila` con su antes y su después.
     *
     * `year_config` se reparte por la ruta que la escribió: el modelo y el reparto son
     * del plan; la ficha, los certificados y los ajustes, de la configuración. Y sólo con
     * `entidad_id` = el año: `BolfinalesController` usa la misma entidad con otro id.
     */
    public function getAnio(Request $peticion, int $yearId): JsonResponse
    {
        Autoriza::exigir(
            Autoriza::puedeVerAuditoria($this->user),
            'No tiene permiso para ver la auditoría'
        );

        $ambito = $peticion->query('ambito') === 'config' ? 'config' : 'plan';
        $modelo = "a.ruta LIKE 'PUT years/modelo-evaluacion%'";

        if ($ambito === 'plan') {
            $donde = "a.year_id = ? AND (
                a.entidad IN ('unidad_plantilla', 'subunidad_plantilla', 'desempeno', 'escala')
                OR (a.entidad = 'unidad' AND a.ruta LIKE 'PUT plantilla-notas/%')
                OR (a.entidad = 'year_config' AND a.entidad_id = ? AND {$modelo}))";
        } else {
            $donde = "a.year_id = ? AND (
                a.entidad IN ('periodo', 'config_certificado', 'config_compromiso', 'compromiso_bloque')
                OR (a.entidad = 'year_config' AND a.entidad_id = ? AND NOT {$modelo}))";
        }

        [$acciones, $hayMas] = $this->lineas($donde, [$yearId, $yearId], self::ORDEN);

        foreach ($acciones as $a) {
            $a->seccion = self::seccionDe($a);
        }

        return response()->json(['year_id' => $yearId, 'ambito' => $ambito, 'acciones' => $acciones, 'hay_mas' => $hayMas]);
    }

    /** La pestaña de la pantalla a la que pertenece una línea del historial del año. */
    private static function seccionDe(object $a): string
    {
        $ruta = (string) ($a->ruta ?? '');

        return match ($a->entidad) {
            'unidad', 'unidad_plantilla', 'subunidad_plantilla' => 'Plantilla',
            'desempeno' => 'Competencias',
            'escala' => 'Escalas',
            'periodo' => 'Periodos',
            'config_certificado' => 'Certificados',
            'config_compromiso', 'compromiso_bloque' => 'Compromiso',
            'year_config' => match (true) {
                str_contains($ruta, 'modelo-evaluacion') => 'Modelo y reparto',
                str_contains($ruta, 'guardar-cambios') => 'Ficha',
                str_contains($ruta, 'certificado') => 'Certificados',
                default => 'Ajustes',
            },
            default => 'Otros',
        };
    }

    /**
     * Las líneas de auditoría que cumplen una condición, con las columnas que pinta
     * la pantalla y en un solo sitio.
     *
     * `SELECT` explícito y no `*`: una columna nueva en `auditoria` no debe aparecer
     * sola en cuatro respuestas del front el día que se añada — en este repo eso ya
     * costó instantáneas movidas sin querer.
     */
    private function lineas(string $donde, array $parametros, string $orden): array
    {
        $filas = DB::select(
            'SELECT a.id, a.accion, a.entidad, a.entidad_id,
                    a.actor_user_id, a.actor_nombre, a.actor_tipo, a.actor_intentado,
                    a.alumno_id, a.alumno_nombre, a.grupo_id, a.asignatura_id,
                    a.periodo_id, a.year_id,
                    a.valor_anterior, a.valor_nuevo,
                    a.valor_anterior_num, a.valor_nuevo_num,
                    a.resumen, a.ip, a.ruta, a.atribucion, a.ocurrido_en,
                    a.sesion_id, a.historial_id
               FROM auditoria a
              WHERE '.$donde.'
              ORDER BY '.$orden.'
              LIMIT '.(self::TOPE + 1),
            $parametros
        );

        $this->ponerDetalle($filas);

        return $this->recortadas($filas);
    }

    /**
     * `detalle`: DE QUÉ habla la línea, en palabras —el indicador de una nota, el periodo
     * de una definitiva—. Se busca al leer y no se guarda: la línea sólo lleva el id, y
     * escribir el nombre del indicador en cada una de las miles de notas sería repetirlo
     * en la base (pedido por Joseth el 25 sep 2026, con el mismo argumento con que se
     * quitaron los nombres del resumen). Si la fila ya no existe, `detalle` es null.
     *
     * @param  array<int, object>  $filas
     */
    private function ponerDetalle(array $filas): void
    {
        $ids = fn (array $entidades) => array_values(array_unique(array_map(
            fn ($f) => (int) $f->entidad_id,
            array_filter($filas, fn ($f) => in_array($f->entidad, $entidades, true) && $f->entidad_id)
        )));
        $marcas = fn (array $l) => implode(',', array_fill(0, count($l), '?'));

        $deNota = [];
        if ($l = $ids(['nota', 'rubrica_valoracion'])) {
            foreach (DB::select(
                'SELECT n.id, s.definicion AS sub, u.definicion AS uni, p.numero
                   FROM notas n
                   JOIN subunidades s ON s.id = n.subunidad_id
                   JOIN unidades u ON u.id = s.unidad_id
                   LEFT JOIN periodos p ON p.id = u.periodo_id
                  WHERE n.id IN ('.$marcas($l).')', $l) as $f) {
                $deNota[(int) $f->id] = trim(($f->sub ?: $f->uni ?: 'Indicador sin nombre').($f->numero ? ' · P'.$f->numero : ''));
            }
        }

        $deDefinitiva = [];
        if ($l = $ids(['nota_final'])) {
            foreach (DB::select(
                'SELECT nf.id, p.numero FROM notas_finales nf LEFT JOIN periodos p ON p.id = nf.periodo_id
                  WHERE nf.id IN ('.$marcas($l).')', $l) as $f) {
                $deDefinitiva[(int) $f->id] = 'Definitiva'.($f->numero ? ' del periodo '.$f->numero : '');
            }
        }

        foreach ($filas as $fila) {
            $id = (int) $fila->entidad_id;
            $fila->detalle = match ($fila->entidad) {
                'nota', 'rubrica_valoracion' => $deNota[$id] ?? null,
                'nota_final' => $deDefinitiva[$id] ?? null,
                default => null,
            };
        }
    }

    /**
     * Recorta al tope y deja dicho si sobraba — **sin contar la tabla**.
     *
     * Lo pidió `myvc-front-89` el 22 sep 2026 con el argumento entero: con sólo las
     * líneas, **el tope no se puede detectar desde el cliente**. Si llegan exactamente
     * 300 puede haber 300 justas o cuatro mil, y una pantalla que escriba «puede haber
     * más» se equivoca cuando son 300 exactas — estaría afirmando algo que no sabe. Así
     * que la pantalla callaba, y **callar es peor que no saber**: el docente lee 300
     * líneas creyendo que ése es el historial entero.
     *
     * La bandera no se calcula con un `COUNT(*)`: se piden `TOPE + 1` filas y sobra una
     * o no sobra. Es exacto y cuesta una fila, mientras que contar es recorrer la tabla
     * para pintar un número que nadie usa.
     */
    private function recortadas(array $filas): array
    {
        $hayMas = count($filas) > self::TOPE;

        return [array_slice($filas, 0, self::TOPE), $hayMas];
    }
}
