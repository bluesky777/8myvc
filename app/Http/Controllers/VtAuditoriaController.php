<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResuelveElUsuario;
use App\Services\Auditoria;
use App\Support\Autoriza;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;

/**
 * **La auditoría del voto: rompe el secreto, y por eso no se parece a nada más.**
 *
 *     GET  auditoria/{votacion}
 *
 * Devuelve, fila por voto, **quién votó y por quién**. Eso es exactamente lo que el
 * resto del módulo existe para no decir: la [11 §6](../../../docs/migracion/11-votaciones.md)
 * mandó cerrar la fuga del voto nominal, la 600000 tiró la papelera de `vt_votos`
 * porque *«conservaba `user_id` y `candidato_id` intactos»*, y el índice único se
 * ordenó `(votacion_id, aspiracion_id, user_id)` a propósito para que *«los votos de
 * este usuario en cualquier elección»* **no fuera una consulta cómoda**.
 *
 * Esta pantalla hace esa consulta. Existe porque el día que un colegio impugna una
 * elección hay que poder demostrar qué pasó en la mesa 3 entre las 10:12 y las 10:14,
 * y sin esto la respuesta es «no se sabe». Pero es **la excepción**, y todo lo de
 * abajo está escrito para que siga siéndolo.
 *
 * ## QUIÉN ENTRA: `Autoriza::esSuperusuario()`, que es el camino más estrecho que hay
 *
 * Se buscó en el repositorio antes de elegir, y hay tres candidatos con tres
 * alcances distintos:
 *
 *   - `Autoriza::puedeVerAuditoria()` — superusuario **o** el permiso de rol
 *     `can_view_auditoria`, que su migración *siembra a rectoría y coordinación*. O
 *     sea que con éste la lista nominal de votos se abre a rectoría por defecto. **No.**
 *   - `Autoriza::esAdministrativo()` — `is_superuser || rol Secretario`. Lo comparten
 *     quince llamadas de dominios que no se parecen a éste, y ensancharlo un día
 *     ensancharía esta puerta sin que nadie lo decidiera (razonado ya en
 *     `puedeAtarFormularios` y en `puedeResolverNotaDeEstacion`). **No.**
 *   - `Autoriza::esSuperusuario()` — lee `users.is_superuser` y nada más. **Sí.**
 *
 * **El repositorio sí distingue superadministrador de rector**, así que no hay que
 * pararse: `Rector` es una fila de `roles` y `is_superuser` es una columna de `users`,
 * y `Autoriza` tiene medido que **no son el mismo conjunto** —12 superusuarios vivos
 * contra 10 con el rol `Admin` en `simonbolivar`, con dos superusuarios sin el rol—.
 * Esta puerta es la columna.
 *
 * No se escribe un criterio nuevo en `Autoriza` porque no hace falta uno: la regla es
 * literalmente *«sólo superusuario»*, que es lo que ese método ya dice. El día que un
 * colegio pida otra cosa, se le pone nombre allí.
 *
 * ## CADA CONSULTA QUEDA REGISTRADA
 *
 * Mirar esta pantalla **es un hecho auditable**: quien rompe el secreto del voto tiene
 * que quedar anotado con su nombre. Se usa el mecanismo del repositorio,
 * `App\Services\Auditoria` (18 §4.3), y no se inventa ninguna tabla.
 *
 * `Auditoria` cierra `ACCIONES` y `ENTIDADES` en el propio servicio —a propósito: *«que
 * ampliarlo sea un acto y no un `string` suelto»*—, así que el 22 sep 2026 se le añadió
 * `'auditoria_del_voto' => null`, sin tabla detrás porque no es una fila de nada: es un
 * suceso, como `intento_login`. La acción `crear` ya existía y es la que usan los
 * sucesos.
 *
 * **Es la única lectura del sistema que se audita**, y se audita justamente porque la
 * fila no dice qué cambió: dice quién miró.
 *
 * **Y el intento denegado se registra igual**, con `denegado`: quién intentó mirar el
 * voto nominal y no pudo es tan interesante como quién sí.
 *
 * ## LO QUE NO SE FILTRA POR NINGÚN OTRO SITIO, y lo que sí
 *
 * Se repasaron los endpoints del módulo buscando `user_id` junto a `candidato_id`:
 *
 *   - **`GET votos` (`VtVotosController::getIndex`) devuelve `VtVoto::all()`**, o sea
 *     la tabla entera con el `user_id` de cada voto, detrás de `auth.personal`. Es la
 *     misma fuga que esta clase abre bajo llave, abierta a los 74 del personal. Ya
 *     está anotado en `routes/api/votaciones.php` —*«Es el voto secreto. No la llama
 *     ningún cliente»*—. **No se toca desde aquí: es de otro agente.**
 *   - `VtVoto::deUsuarioEnCargo()` devuelve `candidato_id` y su propio docblock avisa
 *     de que *«quien la llame decide si eso viaja al cliente»*. Hoy no la llama nadie
 *     de los controladores.
 *   - `votos/show` y `votaciones/*` cuentan con `COUNT(*)` y no sacan `user_id`.
 *
 * ## LAS ANOMALÍAS SE SEÑALAN, NO SE JUZGAN
 *
 * Dos, y las dos son preguntas y no acusaciones: **ráfagas** —papeletas seguidas por
 * la misma persona en menos de N segundos— y **segundos sospechosamente bajos**. Van
 * con su umbral dentro de la respuesta para que el que la lea sepa con qué vara se
 * midió, y el umbral se puede mover por parámetro: el que sirve en un colegio de
 * ochenta no sirve en uno de mil.
 */
class VtAuditoriaController extends Controller
{
    use ResuelveElUsuario;

    /**
     * El nombre que le haría falta a `Auditoria::ENTIDADES`. Ver la cabecera.
     *
     * Sin tabla detrás —`null`— porque **no es una fila de nada**: es un suceso, como
     * `intento_login` o `refresco_reutilizado`.
     */
    private const ENTIDAD = 'auditoria_del_voto';

    /**
     * Cuántos segundos entre dos papeletas seguidas de la misma persona hacen que
     * valga la pena mirarlas.
     *
     * Veinte, y es un punto de partida, no una medida: nadie ha cronometrado todavía
     * cuánto tarda de verdad un alumno de quinto en una mesa. Por eso se puede mover
     * con `?segundos_entre_papeletas=`.
     */
    private const SEGUNDOS_ENTRE_PAPELETAS = 20;

    /**
     * Por debajo de esto, un voto se marca.
     *
     * `vt_votos.segundos` es anulable y **cero no es «no se sabe»** (600000): de los
     * votos viejos no se sabe, y esos no se marcan. Se marca lo que trae un número
     * pequeño de verdad.
     */
    private const SEGUNDOS_SOSPECHOSOS = 5;

    /** El tope de filas que viajan. Una elección grande son miles de votos. */
    private const MAXIMO_FILAS = 5000;

    public function getShow($votacion_id)
    {
        $votacion = DB::selectOne('SELECT id, nombre, year_id FROM vt_votaciones
            WHERE id = ? AND deleted_at IS NULL', [$votacion_id]);

        if ($votacion === null) {
            abort(404, 'Esa votación no existe.');
        }

        if (! Autoriza::esSuperusuario($this->user)) {
            // El intento queda anotado ANTES del 403: quién quiso mirar el voto
            // nominal y no pudo es parte de lo que esta tabla existe para contestar.
            $this->registrarLaConsulta($votacion, [], 0, false);

            abort(403, 'La auditoría del voto es sólo de un superadministrador.');
        }

        $filtros = $this->filtrosQueVienen();

        $filas = $this->filas($votacion, $filtros);
        $anomalias = $this->anomalias($votacion, $filtros);

        $this->registrarLaConsulta($votacion, $filtros, count($filas), true);

        return [
            'votacion' => ['id' => (int) $votacion->id, 'nombre' => $votacion->nombre, 'year_id' => $votacion->year_id],
            'filtros' => $filtros,
            'votos' => $filas,
            'truncado' => count($filas) >= self::MAXIMO_FILAS,
            'anomalias' => $anomalias,
        ];
    }

    /*
     * ─────────────────────────────────────────────────────────────────────────
     *  Las filas
     * ─────────────────────────────────────────────────────────────────────────
     */

    /**
     * Voto a voto: hora, votante con su grupo, cargo, por quién votó, mesa, quién
     * condujo, segundos y origen.
     *
     * ## EL NOMBRE SALE DE TRES SITIOS, Y NINGUNO ES OBLIGATORIO
     *
     * Un votante puede ser alumno, docente o una cuenta administrativa, así que el
     * nombre se busca en `alumnos`, luego en `profesores`, y si no hay ficha queda el
     * `username`. Con `INNER JOIN alumnos` —que es lo que hace media consulta vieja de
     * este módulo— **los votos de los docentes desaparecerían de la auditoría en
     * silencio**, y `votan_profes` existe desde 2014.
     *
     * ## EL GRUPO SE DESEMPATA, por lo mismo que en el censo
     *
     * `matriculas` no tiene único sobre (alumno, año) y hay casos reales de dos vivas.
     * Sin el `MAX(id)`, ese alumno **saldría dos veces en la auditoría**: un voto
     * duplicado en una pantalla que se usa para impugnar una elección.
     *
     * El año es el de la votación, no el del usuario que consulta.
     */
    private function filas($votacion, array $filtros): array
    {
        [$donde, $datos] = $this->donde($filtros);

        $consulta = 'SELECT v.id, v.created_at AS hora, v.origen, v.segundos,
                    v.user_id, v.aspiracion_id, v.candidato_id, v.mesa_id, v.asistido_por,
                    asp.aspiracion AS cargo, asp.abrev AS cargo_abrev,
                    me.nombre AS mesa,
                    COALESCE(CONCAT(al.nombres, " ", al.apellidos),
                             CONCAT(pr.nombres, " ", pr.apellidos),
                             uv.username) AS votante,
                    uv.username AS votante_username,
                    g.id AS grupo_id, g.nombre AS grupo, g.abrev AS grupo_abrev,
                    COALESCE(CONCAT(alc.nombres, " ", alc.apellidos), uc.username) AS voto_por,
                    COALESCE(CONCAT(pa.nombres, " ", pa.apellidos), ua.username) AS conducida_por
                FROM vt_votos v
                INNER JOIN vt_aspiraciones asp ON asp.id = v.aspiracion_id
                LEFT JOIN users uv ON uv.id = v.user_id
                LEFT JOIN alumnos al ON al.user_id = v.user_id AND al.deleted_at IS NULL
                LEFT JOIN profesores pr ON pr.user_id = v.user_id AND pr.deleted_at IS NULL
                LEFT JOIN matriculas m ON m.alumno_id = al.id AND m.deleted_at IS NULL
                     AND m.id = (SELECT MAX(m2.id) FROM matriculas m2
                                 INNER JOIN grupos g2 ON g2.id = m2.grupo_id AND g2.year_id = ? AND g2.deleted_at IS NULL
                                 WHERE m2.alumno_id = m.alumno_id AND m2.deleted_at IS NULL)
                LEFT JOIN grupos g ON g.id = m.grupo_id AND g.year_id = ? AND g.deleted_at IS NULL
                LEFT JOIN vt_candidatos c ON c.id = v.candidato_id
                LEFT JOIN users uc ON uc.id = c.user_id
                LEFT JOIN alumnos alc ON alc.user_id = c.user_id AND alc.deleted_at IS NULL
                LEFT JOIN vt_mesas me ON me.id = v.mesa_id
                LEFT JOIN users ua ON ua.id = v.asistido_por
                LEFT JOIN profesores pa ON pa.user_id = v.asistido_por AND pa.deleted_at IS NULL
                WHERE v.votacion_id = ? '.$donde.'
             ORDER BY v.created_at, v.id
                LIMIT '.self::MAXIMO_FILAS;

        $filas = DB::select($consulta, array_merge([$votacion->year_id, $votacion->year_id, $votacion->id], $datos));

        $sospechosos = [];

        foreach ($this->votosSospechosos($votacion, $filtros) as $id) {
            $sospechosos[$id] = true;
        }

        foreach ($filas as $fila) {
            // `candidato_id` nulo **es** el voto en blanco desde la 600000. Se dice con
            // palabras y no con un `null` suelto: esta pantalla la lee una persona.
            $fila->voto_por = $fila->candidato_id === null ? 'Voto en Blanco' : $fila->voto_por;
            $fila->blanco = $fila->candidato_id === null;
            $fila->segundos = $fila->segundos === null ? null : (int) $fila->segundos;
            $fila->marcado_por_segundos = isset($sospechosos[(int) $fila->id]);
        }

        return $filas;
    }

    /*
     * ─────────────────────────────────────────────────────────────────────────
     *  Las anomalías
     * ─────────────────────────────────────────────────────────────────────────
     */

    /**
     * Las dos cosas que hay que mirar dos veces.
     *
     * **Se calculan sobre la elección entera y no sobre la página**, aunque las filas
     * de arriba vengan cortadas por `LIMIT`: una ráfaga que cae en el voto 5.001 es
     * exactamente la que hay que ver, y calcularla sobre lo que cupo la escondería
     * justo en las elecciones grandes, que son las que se impugnan.
     */
    private function anomalias($votacion, array $filtros): array
    {
        return [
            'umbrales' => [
                'segundos_entre_papeletas' => $filtros['segundos_entre_papeletas'],
                'segundos_sospechosos' => $filtros['segundos_sospechosos'],
            ],
            'rafagas' => $this->rafagas($votacion, $filtros),
            'segundos_bajos' => $this->segundosBajos($votacion, $filtros),
        ];
    }

    /**
     * **Papeletas seguidas de la misma persona en menos de N segundos.**
     *
     * «La misma persona» es **quien condujo** (`asistido_por`), no quien votó, y esa
     * es la decisión de este método. Un votante emite todos sus cargos de una sentada
     * —cuatro votos en diez segundos es lo normal y no dice nada—; lo que sí dice algo
     * es una persona del colegio despachando papeleta tras papeleta a un ritmo que no
     * le da tiempo a nadie a leer el tarjetón.
     *
     * Por eso se agrupa primero en **papeletas** —los votos de un votante con un
     * conductor son una sola— y se miran los huecos entre papeletas consecutivas del
     * mismo conductor. Sin ese paso, cada votante contaría como cuatro ráfagas él solo
     * y la señal se perdería en el ruido.
     *
     * Los votos sin `asistido_por` no entran: nadie los condujo.
     */
    private function rafagas($votacion, array $filtros): array
    {
        [$donde, $datos] = $this->donde($filtros);

        $papeletas = DB::select('SELECT v.asistido_por, v.user_id, v.mesa_id,
                    MIN(v.created_at) AS empezo, MAX(v.created_at) AS termino, COUNT(*) AS votos,
                    COALESCE(CONCAT(al.nombres, " ", al.apellidos),
                             CONCAT(pr.nombres, " ", pr.apellidos), uv.username) AS votante,
                    COALESCE(CONCAT(pa.nombres, " ", pa.apellidos), ua.username) AS conducida_por,
                    me.nombre AS mesa
                FROM vt_votos v
                LEFT JOIN users uv ON uv.id = v.user_id
                LEFT JOIN alumnos al ON al.user_id = v.user_id AND al.deleted_at IS NULL
                LEFT JOIN profesores pr ON pr.user_id = v.user_id AND pr.deleted_at IS NULL
                LEFT JOIN users ua ON ua.id = v.asistido_por
                LEFT JOIN profesores pa ON pa.user_id = v.asistido_por AND pa.deleted_at IS NULL
                LEFT JOIN vt_mesas me ON me.id = v.mesa_id
                WHERE v.votacion_id = ? AND v.asistido_por IS NOT NULL '.$donde.'
             GROUP BY v.asistido_por, v.user_id, v.mesa_id, uv.username, al.nombres, al.apellidos,
                      pr.nombres, pr.apellidos, pa.nombres, pa.apellidos, ua.username, me.nombre
             ORDER BY v.asistido_por, empezo',
            array_merge([$votacion->id], $datos));

        $umbral = $filtros['segundos_entre_papeletas'];

        $rafagas = [];
        $anterior = null;

        foreach ($papeletas as $papeleta) {
            if ($anterior !== null
                && (int) $anterior->asistido_por === (int) $papeleta->asistido_por) {

                $hueco = strtotime($papeleta->empezo) - strtotime($anterior->termino);

                if ($hueco >= 0 && $hueco < $umbral) {
                    $rafagas[] = [
                        'asistido_por' => (int) $papeleta->asistido_por,
                        'conducida_por' => $papeleta->conducida_por,
                        'mesa_id' => $papeleta->mesa_id === null ? null : (int) $papeleta->mesa_id,
                        'mesa' => $papeleta->mesa,
                        'segundos_entre' => $hueco,
                        'anterior' => ['user_id' => (int) $anterior->user_id, 'votante' => $anterior->votante,
                            'termino' => $anterior->termino],
                        'siguiente' => ['user_id' => (int) $papeleta->user_id, 'votante' => $papeleta->votante,
                            'empezo' => $papeleta->empezo],
                    ];
                }
            }

            $anterior = $papeleta;
        }

        return $rafagas;
    }

    /**
     * Los votos con `segundos` por debajo del umbral.
     *
     * `IS NOT NULL` explícito: de los votos anteriores a la 600000 **no se sabe cuánto
     * tardaron**, y `NULL < 5` es desconocido en SQL, así que sin la condición no
     * entrarían igual. Va escrito porque la próxima vez que alguien lea esto va a
     * preguntárselo — y porque un `COALESCE(segundos, 0)` puesto sin pensar
     * convertiría toda la elección de 2025 en una anomalía.
     */
    private function segundosBajos($votacion, array $filtros): array
    {
        [$donde, $datos] = $this->donde($filtros);

        $filas = DB::select('SELECT v.id, v.created_at AS hora, v.segundos, v.user_id, v.mesa_id,
                    asp.aspiracion AS cargo,
                    COALESCE(CONCAT(al.nombres, " ", al.apellidos),
                             CONCAT(pr.nombres, " ", pr.apellidos), uv.username) AS votante,
                    COALESCE(CONCAT(pa.nombres, " ", pa.apellidos), ua.username) AS conducida_por,
                    me.nombre AS mesa
                FROM vt_votos v
                INNER JOIN vt_aspiraciones asp ON asp.id = v.aspiracion_id
                LEFT JOIN users uv ON uv.id = v.user_id
                LEFT JOIN alumnos al ON al.user_id = v.user_id AND al.deleted_at IS NULL
                LEFT JOIN profesores pr ON pr.user_id = v.user_id AND pr.deleted_at IS NULL
                LEFT JOIN users ua ON ua.id = v.asistido_por
                LEFT JOIN profesores pa ON pa.user_id = v.asistido_por AND pa.deleted_at IS NULL
                LEFT JOIN vt_mesas me ON me.id = v.mesa_id
                WHERE v.votacion_id = ? AND v.segundos IS NOT NULL AND v.segundos < ? '.$donde.'
             ORDER BY v.segundos, v.created_at',
            array_merge([$votacion->id, $filtros['segundos_sospechosos']], $datos));

        foreach ($filas as $fila) {
            $fila->segundos = (int) $fila->segundos;
        }

        return $filas;
    }

    /** Sólo los ids, para marcar las filas de la tabla sin repetir la consulta entera. */
    private function votosSospechosos($votacion, array $filtros): array
    {
        [$donde, $datos] = $this->donde($filtros);

        $filas = DB::select('SELECT v.id FROM vt_votos v
                WHERE v.votacion_id = ? AND v.segundos IS NOT NULL AND v.segundos < ? '.$donde,
            array_merge([$votacion->id, $filtros['segundos_sospechosos']], $datos));

        return array_map(fn ($fila) => (int) $fila->id, $filas);
    }

    /*
     * ─────────────────────────────────────────────────────────────────────────
     *  Los filtros
     * ─────────────────────────────────────────────────────────────────────────
     */

    /**
     * Mesa, grupo, quién condujo y rango de horas. Y los dos umbrales.
     *
     * Todo llega por `query string` porque esto es un `GET` que se comparte por enlace:
     * *«mira esto, mesa 3, entre las 10:12 y las 10:14»* tiene que caber en una URL.
     *
     * @return array{mesa_id:?int, grupo_id:?int, asistido_por:?int, desde:?string, hasta:?string, segundos_entre_papeletas:int, segundos_sospechosos:int}
     */
    private function filtrosQueVienen(): array
    {
        return [
            'mesa_id' => $this->entero('mesa_id'),
            'grupo_id' => $this->entero('grupo_id'),
            'asistido_por' => $this->entero('asistido_por'),
            'desde' => $this->hora('desde'),
            'hasta' => $this->hora('hasta'),
            'segundos_entre_papeletas' => $this->entero('segundos_entre_papeletas')
                ?? self::SEGUNDOS_ENTRE_PAPELETAS,
            'segundos_sospechosos' => $this->entero('segundos_sospechosos')
                ?? self::SEGUNDOS_SOSPECHOSOS,
        ];
    }

    /**
     * El `WHERE` de los filtros, con sus datos, para pegarlo detrás de la votación.
     *
     * El grupo se resuelve con un `EXISTS` sobre la matrícula y **no** con el `JOIN` de
     * la consulta grande: así el mismo `WHERE` vale para las tres consultas de esta
     * clase, que tienen uniones distintas. Repetirlo tres veces adaptado a cada una es
     * como se separan tres filtros que tenían que decir lo mismo.
     *
     * @return array{0:string, 1:list<mixed>}
     */
    private function donde(array $filtros): array
    {
        $donde = '';
        $datos = [];

        if ($filtros['mesa_id'] !== null) {
            $donde .= ' AND v.mesa_id = ?';
            $datos[] = $filtros['mesa_id'];
        }

        if ($filtros['asistido_por'] !== null) {
            $donde .= ' AND v.asistido_por = ?';
            $datos[] = $filtros['asistido_por'];
        }

        if ($filtros['desde'] !== null) {
            $donde .= ' AND v.created_at >= ?';
            $datos[] = $filtros['desde'];
        }

        if ($filtros['hasta'] !== null) {
            $donde .= ' AND v.created_at <= ?';
            $datos[] = $filtros['hasta'];
        }

        if ($filtros['grupo_id'] !== null) {
            $donde .= ' AND EXISTS (SELECT 1 FROM alumnos ax
                    INNER JOIN matriculas mx ON mx.alumno_id = ax.id AND mx.deleted_at IS NULL
                    WHERE ax.user_id = v.user_id AND ax.deleted_at IS NULL AND mx.grupo_id = ?)';
            $datos[] = $filtros['grupo_id'];
        }

        return [$donde, $datos];
    }

    /** Un entero de la query string, o null. Un `?mesa_id=` vacío es «sin filtro». */
    private function entero(string $clave): ?int
    {
        $valor = Request::input($clave);

        if ($valor === null || $valor === '' || ! is_numeric($valor)) {
            return null;
        }

        return (int) $valor;
    }

    /**
     * Una hora del rango, tal cual la manda el cliente.
     *
     * Se compara contra `vt_votos.created_at`, que es el reloj con el que se escribió
     * el voto. **No se convierte de zona aquí**: el 18 §1 tiene medido que en este
     * esquema conviven columnas escritas en UTC y en Bogotá y que *«nada en la fila
     * dice cuál es cuál»*, así que una conversión inventada movería el rango sin
     * decirlo. Lo que se manda es lo que se compara.
     */
    private function hora(string $clave): ?string
    {
        $valor = Request::input($clave);

        if (! is_string($valor) || trim($valor) === '') {
            return null;
        }

        $valor = trim($valor);

        if (! preg_match('/^\d{4}-\d{2}-\d{2}([ T]\d{2}:\d{2}(:\d{2})?)?$/', $valor)) {
            abort(422, 'El rango de horas se manda como `AAAA-MM-DD` o `AAAA-MM-DD HH:MM`.');
        }

        return str_replace('T', ' ', $valor);
    }

    /*
     * ─────────────────────────────────────────────────────────────────────────
     *  El rastro
     * ─────────────────────────────────────────────────────────────────────────
     */

    /**
     * Deja constancia de que alguien miró —o intentó mirar— el voto nominal.
     *
     * **Escribe en `auditoria`, la tabla de verdad.** `'auditoria_del_voto' => null` se
     * añadió a `Auditoria::ENTIDADES` el 22 sep 2026 para esto: va sin tabla detrás
     * porque no es una fila de nada, es un suceso, como `intento_login`. El servicio
     * cierra ese catálogo en su propio fichero a propósito, así que llamarlo con un
     * nombre que no esté dentro lanza `InvalidArgumentException`, que `guardar()` se
     * traga y convierte en un `Log::error` por consulta — o sea, un rastro perdido sin
     * que nada se queje donde se lee.
     *
     * No hace falta nada más: el actor, la sesión, la hora, la ruta y la IP los
     * resuelve el servicio, que son las cinco cosas que quien llama no decide (18 §4.3).
     *
     * `crear` para el suceso consumado y `denegado` para el que no pasó es la misma
     * pareja que usan `Services\Login` y `Services\Sesion` con sus sucesos sin tabla.
     *
     * El resumen lleva **los filtros dentro** en las dos formas: «quién miró» sin «qué
     * miró» no contesta nada el día de una impugnación, y el filtro es justamente lo
     * que dice si alguien fue a buscar a una persona concreta.
     */
    private function registrarLaConsulta($votacion, array $filtros, int $filas, bool $permitido): void
    {
        $resumen = ($permitido ? 'Consultó' : 'Intentó consultar')
            .' la auditoría del voto de «'.$votacion->nombre.'»'
            .($permitido ? ' ('.$filas.' votos)' : '');

        $linea = $permitido
            ? Auditoria::registrar()->crear(self::ENTIDAD, (int) $votacion->id)
            : Auditoria::registrar()->denegado(self::ENTIDAD, (int) $votacion->id);

        $linea->en(year: (int) $votacion->year_id)
            ->a(['filtros' => array_filter($filtros, fn ($v) => $v !== null), 'votos' => $filas])
            ->resumen($resumen)
            ->guardar();
    }
}
