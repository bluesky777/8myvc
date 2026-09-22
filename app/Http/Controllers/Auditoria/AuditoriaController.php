<?php

namespace App\Http\Controllers\Auditoria;

use App\Http\Controllers\Concerns\ResuelveElUsuario;
use App\Http\Controllers\Controller;
use App\Services\Auditoria;
use App\Support\Autoriza;
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

        Autoriza::exigir(
            Autoriza::puedeVerAuditoria($this->user),
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

        return $this->recortadas($filas);
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
