<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResuelveElUsuario;
use App\Support\Autoriza;
use App\Support\Reloj;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;

/**
 * Horario: versiones del horario de un año, y cuál de ellas es la oficial.
 *
 * El contrato es [23-horarios.md](../../../docs/migracion/23-horarios.md) (v2, 2
 * sep 2026) y este fichero lo sigue; si algo de aquí discrepa del documento, el
 * que está mal es éste.
 *
 * **El horario NO se cuadra aquí.** Se cuadra en un programa de escritorio (Tauri
 * 2 + Angular) con su propio fichero de proyecto, y a esta API le queda una cosa
 * mucho más pequeña: **guardar versiones del horario de un año y decir cuál es la
 * oficial**. Salones, disponibilidad, rejilla, timbres y pesos **no existen en el
 * servidor** (§4) — y ésa no es una omisión que haya que ir tapando: es la razón
 * de ser de la §6.
 *
 * ## Las tres reglas que gobiernan cada método
 *
 *   1. **Subir no es publicar.** Cada subida crea una versión; una pantalla web
 *      elige cuál es la oficial. Hasta que se elige, «Clases de hoy» sigue
 *      enseñando la anterior, y **una subida a medias no le llega a nadie**.
 *   2. **El servidor revalida las tres que puede y LO DICE** (opción B, decisión
 *      9). Grupo sin choque, docente sin choque y Σ lecciones = IH. Salón,
 *      disponibilidad y jornada quedan **nombradas como no comprobadas**, con su
 *      población dentro. Un `if` que comprueba la disponibilidad contra un dato
 *      que el servidor no tiene **no falla nunca**: pasa siempre, se ve verde y
 *      no comprueba nada. Aceptar y callar habría sido un «validado» encima de un
 *      horario ilegal.
 *   3. **Listar NO es descargar** (decisión 12). `GET horario/versiones` va con
 *      `auth.personal`, o sea los 53 docentes; devuelve nombre, fecha, quién,
 *      si es la oficial y el veredicto — **nunca el blob del proyecto ni las
 *      lecciones**. Un `SELECT *` ahí le entrega a cualquiera el fichero de
 *      proyecto entero del colegio.
 *
 * ## Estado: `postVersiones` escrito; los otros dos siguen contestando 501
 *
 * El **suelo** del módulo (lote A) —rutas, guards y autorización— entró en
 * `3524a22` con los tres métodos a 501, y **las tres se ejercitaron contra el
 * docker el 2 sep 2026**: `POST`, `GET` y `PUT` contestaron **501 y no 500**, con
 * `auth/me` a 200 como control y la migración `2026_09_04_100000_horario_versiones`
 * en `[16] Ran`. Esa distinción importaba y no era gratis: una ruta de este repo
 * que contesta mal por **migraciones sin correr** se parece muchísimo a una que
 * contesta mal porque le falta el cuerpo, y las dos se arreglan en sitios
 * distintos.
 *
 * De ahí que `getVersiones` y `putOficial` sigan a 501: un 501 dice exactamente
 * lo que pasa —la ruta existe, está autorizada y todavía no hace nada—, que es lo
 * que un 404 o un 200 vacío no dirían. **La comprobación de permiso va ANTES del
 * 501 y no después**, porque dejarla para el que escriba el cuerpo es cómo una
 * ruta acaba en producción sin ella.
 *
 * ## Por qué SQL y no Eloquent, el día que se escriban
 *
 * Por lo mismo que el resto del repo, y con la lección de `notas/detailed`
 * delante: **nunca `SELECT *`**, columnas escritas a mano. Aquí no es sólo higiene
 * — es la regla 3 de arriba.
 */
class HorarioController extends Controller
{
    use ResuelveElUsuario;

    /**
     * `POST horario/versiones` — sube una versión del horario de un año (§5.3).
     *
     * Guard de la ruta `auth.personal`; el criterio es
     * **`Autoriza::esAdministrativo`**, el mismo que pide `putCambiarlogocolegio`
     * y que es la referencia que dio Joseth (§5.4). Sube cualquier
     * administrativo; **publicar es otro criterio y otro método**.
     *
     * ## Lo que llega, y lo que NO puede llegar
     *
     * `version: {nombre, year_id, anio, nombre_colegio}`, `proyecto` y `piezas[]`.
     * `anio` y `nombre_colegio` entraron con la decisión de Joseth del 2 sep 2026
     * (§5.2.0) y **no son adorno**: `anio` se comprueba **duro** —es lo único que
     * caza el fichero subido al colegio equivocado, porque `years.id` 8 es 2025 en
     * un colegio y 2019 en otro— y `nombre_colegio` **blando**, renglón del
     * veredicto y nunca puerta cerrada, porque es texto libre que alguien puede
     * cambiar el martes.
     *
     * **`subida_por`, `created_at` y `comprobaciones` no se leen del cuerpo
     * jamás** (§5.2, correcciones 2 y 3): el primero sale del token —aceptar otro
     * es dejar firmar en nombre ajeno—, el segundo del reloj del servidor, y el
     * tercero es el veredicto del servidor sobre sí mismo: si viajara de fuera, un
     * cliente podría subir un horario con «comprobado todo ✓» encima y el
     * historial dejaría de servir para lo único que sirve.
     *
     * > **`proyecto` viaja y la §5.2 no lo decía.** Su boceto lista `version` y
     * > `piezas` y nada más, pero `horario_versiones.proyecto` es
     * > `mediumText()` **sin `nullable()`** y la decisión 22 dice que el fichero
     * > entero sube con cada versión — o sea que el cuerpo es el único sitio de
     * > donde puede salir. No es que las dos mitades discrepen: es que una no lo
     * > decía. Comprobado campo a campo contra `nucleo/envio.ts` de
     * > `myvc_horarios` el 2 sep 2026, y la §5.2 se corrigió el mismo día.
     *
     * ## Los rechazos, y por qué cada uno nombra su pieza
     *
     * `abort()` a secas sólo sabe poner un texto, y un error que sólo es texto
     * obliga al cliente a leerlo con expresiones regulares para saber a qué
     * casilla culpar. Por eso los 422 de dominio salen por `rechazarPieza()` con
     * el `pieza_id` **aparte** del `message`, y **con la población dentro**:
     * «hay choques» no distingue *«revisé las 345 y encontré tres»* de *«me rendí
     * en la primera»*, y de las dos lecturas la falsa es la que hace archivar el
     * asunto (§6).
     *
     * La forma la valida Laravel —tipos y presencia, 422 con la ruta del campo—;
     * **el dominio se valida a mano**, y no por gusto: `piezas.3.asignaciones` es
     * una posición dentro del cuerpo, no una pieza, y la pantalla del escritorio
     * no puede señalar una casilla con eso.
     *
     * ## Lo que se revalida, que son tres, y las tres que se declaran
     *
     * Opción B de la §6, elegida por Joseth: **grupo sin choque**, **docente sin
     * choque sobre los docentes que trajo la versión** y **Σ ≤ IH**. Salón,
     * disponibilidad y jornada quedan **nombradas como NO comprobadas** dentro del
     * veredicto: *un `if` contra un dato que el servidor no tiene no falla nunca*
     * —pasa siempre, se ve verde y no comprueba nada—, y aceptar con un «validado»
     * encima de un horario ilegal es más caro que no comprobar.
     *
     * **Σ ≤ IH es la dura y Σ = IH baja al veredicto con su cuenta** (decisión 20,
     * corregida el 2 sep 2026). Gastar más horas de las que la asignación tiene es
     * imposible en cualquier lectura; que falten es una versión a medias, que es
     * justo para lo que existen las versiones — y sobre `lleno.myvch`, el único
     * proyecto real que hay, **133 de 134 asignaciones cumplen la igualdad y una se
     * queda en 2 de 3**: la regla dura habría rechazado el único dato de verdad.
     *
     * Y la asignación **sin IH** (`creditos` es `int DEFAULT NULL`) no es 422: va
     * al veredicto **nombrada y contada como NO comprobada**, porque un 422
     * convertiría un dato incompleto del colegio en un módulo inutilizable. La
     * trampa que evita es fina: `SUM(...) = creditos` con un `NULL` dentro **no da
     * falso, se cae del resultado**, y en PHP el `==` acusa a quien no tiene culpa.
     *
     * ## La casilla es la unidad de choque, no la pieza
     *
     * Un bloque de `duracion: 2` ocupa **dos** casillas consecutivas del mismo día,
     * así que comparar piezas por `(dia, franja)` dejaría pasar el bloque que se
     * monta encima de la lección de la franja siguiente. Se expande y se compara
     * casilla a casilla.
     *
     * Y se cuentan **`pieza_id` distintos, no filas** (§5.1, decisión 19): la misa
     * de seis grupos son SEIS filas con el mismo `pieza_id`, en la misma casilla y
     * con los mismos docentes. Contando filas, el capellán saldría seis veces y
     * **su propia misa lo declararía duplicado consigo mismo**.
     *
     * ## La versión entra entera o no entra
     *
     * Una transacción, y las comprobaciones **antes** de abrirla: una versión a
     * medias es peor que ninguna, porque parece un horario.
     */
    public function postVersiones(): JsonResponse
    {
        Autoriza::exigir(Autoriza::esAdministrativo($this->user),
            'No tienes permiso para subir una versión del horario.');

        $cuerpo = $this->cuerpoDeLaSubida();
        $anio = $this->anioDeLaVersion((int) $cuerpo['version']['year_id'], (int) $cuerpo['version']['anio']);
        $piezas = $this->piezasQueEntran($cuerpo['piezas'], (int) $anio->id);
        $veredicto = $this->veredictoDeLaVersion($piezas, $anio, (string) $cuerpo['version']['nombre_colegio']);

        $ahora = Reloj::ahoraTexto();
        // `users.id`, igual que el `created_by` del resto del repo. NO es
        // `profesores.id`: eso es lo que identifica a un DOCENTE (§5.2.1), y quien
        // sube es una persona con cuenta, que puede no dar clase.
        $subidaPor = (int) $this->user->user_id;

        $versionId = DB::transaction(function () use ($cuerpo, $anio, $piezas, $veredicto, $ahora, $subidaPor) {
            DB::insert(
                'INSERT INTO horario_versiones (year_id, nombre, subida_por, proyecto, comprobaciones, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?)',
                [
                    (int) $anio->id,
                    (string) $cuerpo['version']['nombre'],
                    $subidaPor,
                    (string) $cuerpo['proyecto'],
                    (string) json_encode($veredicto, JSON_UNESCAPED_UNICODE),
                    $ahora,
                    $ahora,
                ]
            );

            $id = (int) DB::getPdo()->lastInsertId();

            $lecciones = [];
            $docentes = [];

            foreach ($piezas as $pieza) {
                foreach ($pieza['asignaciones'] as $asignaturaId) {
                    // Una fila por (pieza × asignación), con el `pieza_id` compartido
                    // (decisión 19): así derivar las siete columnas de la §7 y
                    // comprobar Σ ≤ IH son un `GROUP BY` y no un desempaquetado de
                    // JSON en PHP.
                    $lecciones[] = [$id, $pieza['pieza_id'], $asignaturaId, $pieza['dia'], $pieza['franja'], $pieza['duracion'], $pieza['salon_nombre'], $pieza['salon_capacidad_grupos']];
                }

                // Los docentes cuelgan de la PIEZA, no de la asignación: es el caso
                // del capellán (§5.1). Si la misa la da él, el titular de Religión
                // de Décimo tiene esa hora libre aunque la hora salga de su
                // asignación, y leerlos de `asignaturas.profesor_id` daría la
                // respuesta contraria justo en el único caso raro que hay.
                foreach ($pieza['docentes'] as $profesorId) {
                    $docentes[] = [$id, $pieza['pieza_id'], $profesorId];
                }
            }

            $this->insertarEnLotes('horario_lecciones (version_id, pieza_id, asignatura_id, dia, franja, duracion, salon, salon_capacidad_grupos)', 8, $lecciones);
            $this->insertarEnLotes('horario_pieza_docente (version_id, pieza_id, profesor_id)', 3, $docentes);

            return $id;
        });

        // 201 y el veredicto de vuelta: el cliente acaba de recibir el juicio del
        // servidor sobre lo que subió, y pedírselo otra vez con un `GET` sería
        // esconderlo. **No vuelve `proyecto`**: listar no es descargar (decisión
        // 12), y devolverlo aquí abriría por la puerta de atrás lo que el `GET`
        // cierra por delante.
        return response()->json([
            'id' => $versionId,
            'year_id' => (int) $anio->id,
            'nombre' => (string) $cuerpo['version']['nombre'],
            'subida_por' => $subidaPor,
            'created_at' => $ahora,
            'es_oficial' => false,
            'comprobaciones' => $veredicto,
        ], 201);
    }

    /**
     * La forma del cuerpo: tipos y presencia, y **nada de dominio**.
     *
     * Laravel contesta 422 con la ruta del campo (`piezas.3.dia`), que para un
     * tipo equivocado es exactamente lo que hace falta. Para el dominio no vale, y
     * por eso `dia` se declara aquí sólo como entero y el 0..6 se comprueba
     * después: `piezas.3` es **una posición dentro del cuerpo, no una pieza**, y la
     * pantalla del escritorio no puede señalar una casilla con eso.
     *
     * `piezas` va `present` y no `required`: subir una versión sin ninguna pieza
     * colocada es legítimo —es un horario que todavía no se ha empezado— y el
     * veredicto lo dirá con su cuenta en vez de rechazarlo.
     *
     * @return array<string, mixed>
     */
    protected function cuerpoDeLaSubida(): array
    {
        return Request::validate([
            'version' => 'required|array',
            'version.nombre' => 'required|string|max:255',
            'version.year_id' => 'required|integer|min:1',
            'version.anio' => 'required|integer',
            'version.nombre_colegio' => 'required|string',
            'proyecto' => 'required|string',
            'piezas' => 'present|array',
            'piezas.*.pieza_id' => 'required|string|max:64',
            'piezas.*.dia' => 'required|integer',
            'piezas.*.franja' => 'required|integer',
            'piezas.*.duracion' => 'required|integer',
            'piezas.*.docentes' => 'present|array',
            'piezas.*.docentes.*' => 'required|integer|min:1',
            'piezas.*.asignaciones' => 'present|array',
            'piezas.*.asignaciones.*' => 'required|integer|min:1',
            'piezas.*.salon_nombre' => 'nullable|string|max:120',
            'piezas.*.salon_capacidad_grupos' => 'nullable|integer|min:0',
        ]);
    }

    /**
     * El año de la versión, y **la quinta comprobación de la §6**.
     *
     * `year_id` puede ser de un año pasado y **eso está decidido** (decisión 13):
     * un horario no cuelga de ningún periodo, así que el interruptor que frena las
     * escrituras en un año cerrado no le aplica. Lo único que lo frena es el
     * permiso.
     *
     * Y por eso mismo hace falta esto: **`years.id` 8 es 2025 en un colegio y
     * puede ser 2019 en otro**, así que un `.myvch` subido al colegio equivocado
     * da *identificador que existe + año distinto*. Sin el campo `anio` el
     * servidor **no tiene contra qué contrastar su propia fila**, y entonces esa
     * comprobación no es que falle: no existe, y su ausencia no da ningún error.
     */
    protected function anioDeLaVersion(int $yearId, int $anio): \stdClass
    {
        $fila = DB::selectOne('SELECT id, year, nombre_colegio FROM years WHERE id = ?', [$yearId]);

        if ($fila === null) {
            abort(404, "No existe el año lectivo {$yearId}. Nada se escribió.");
        }

        if ((int) $fila->year !== $anio) {
            $this->rechazar([
                'message' => "La versión dice ser del año {$anio} y en este colegio years.id {$yearId} es el año {$fila->year}. No se sube: el mismo identificador de año es un año distinto en cada colegio, así que esto es lo que caza un fichero subido al colegio equivocado. Nada se escribió.",
                'motivo' => 'anio-no-coincide',
                'year_id' => $yearId,
                'anio_del_cuerpo' => $anio,
                'anio_del_servidor' => (int) $fila->year,
            ]);
        }

        return $fila;
    }

    /**
     * Las piezas ya comprobadas, o un 422 que nombra la culpable.
     *
     * Devuelve las piezas normalizadas —enteros, y `asignaciones` sin repetir— con
     * `grupos` añadido, que es lo que necesita el veredicto para el choque de
     * grupo y lo que no se puede volver a consultar dentro de la transacción sin
     * pagar otra vuelta.
     *
     * @param  array<int, array<string, mixed>>  $piezas
     * @return array<int, array<string, mixed>>
     */
    protected function piezasQueEntran(array $piezas, int $yearId): array
    {
        $total = count($piezas);

        // ── Primero lo que se ve sin consultar nada, pieza a pieza y en orden.
        $vistos = [];
        $normalizadas = [];

        foreach (array_values($piezas) as $i => $pieza) {
            $revisadas = $i + 1;
            $piezaId = (string) $pieza['pieza_id'];

            if (isset($vistos[$piezaId])) {
                // Dos piezas con el mismo identificador rompen la garantía en la que
                // se apoya el choque de docente: las filas que comparten `pieza_id`
                // son la MISMA pieza en la MISMA casilla, y con el identificador
                // repetido eso deja de ser cierto. Además el índice único
                // (version_id, pieza_id, asignatura_id) lo rechazaría con un 500.
                $this->rechazarPieza($piezaId, 'aparece dos veces en el cuerpo. Las filas que comparten pieza_id son la misma pieza en la misma casilla, y con el identificador repetido la comprobación de choque de docente deja de tener sentido.', $revisadas);
            }
            $vistos[$piezaId] = true;

            $dia = (int) $pieza['dia'];
            $franja = (int) $pieza['franja'];
            $duracion = (int) $pieza['duracion'];
            $asignaciones = array_map('intval', array_values((array) $pieza['asignaciones']));
            $docentes = array_values(array_unique(array_map('intval', array_values((array) $pieza['docentes']))));

            if ($asignaciones === []) {
                $this->rechazarPieza($piezaId, 'no trae ninguna asignatura. Sin asignatura_id no hay a qué colgar la lección, y aquí NUNCA se empareja por nombres: un proyecto armado sin MyVC detrás no se puede subir (§8).', $revisadas);
            }

            if (count(array_unique($asignaciones)) !== count($asignaciones)) {
                $this->rechazarPieza($piezaId, 'repite una asignatura dentro de la misma pieza. Una fila es (pieza × asignación) y ese par es único; además la repetida contaría sus horas dos veces en Σ ≤ IH.', $revisadas);
            }

            if ($dia < 0 || $dia > 6) {
                // El día es el día de la semana de verdad, el de `Carbon::dayOfWeek`,
                // porque es el convenio con el que se CONSUMEN las siete columnas de
                // la §7 — así la derivación no traduce nada, y un mapeo es justo
                // donde vive un off-by-one.
                $this->rechazarPieza($piezaId, "trae día {$dia}. El día va de 0 a 6 con 0 = domingo: es el día de la semana de verdad, no el índice de la columna de la rejilla ni un «día 1 del horario».", $revisadas);
            }

            if ($franja < 1) {
                $this->rechazarPieza($piezaId, "trae franja {$franja}. La franja es base 1: la primera lección del día es la 1.", $revisadas);
            }

            if ($duracion < 1) {
                $this->rechazarPieza($piezaId, "trae duración {$duracion}. La duración se cuenta en CASILLAS, no en minutos, y lo más corto que existe es 1.", $revisadas);
            }

            $normalizadas[] = [
                'pieza_id' => $piezaId,
                'dia' => $dia,
                'franja' => $franja,
                'duracion' => $duracion,
                'asignaciones' => $asignaciones,
                'docentes' => $docentes,
                'salon_nombre' => isset($pieza['salon_nombre']) && $pieza['salon_nombre'] !== '' ? (string) $pieza['salon_nombre'] : null,
                'salon_capacidad_grupos' => isset($pieza['salon_capacidad_grupos']) ? (int) $pieza['salon_capacidad_grupos'] : null,
            ];
        }

        // ── Y ahora lo que sí hay que consultar, en DOS consultas y no en 2N.
        $asignaturas = $this->asignaturasDe($normalizadas);
        $profesores = $this->profesoresDe($normalizadas);

        foreach ($normalizadas as $i => &$pieza) {
            $revisadas = $i + 1;

            foreach ($pieza['asignaciones'] as $id) {
                $a = $asignaturas[$id] ?? null;

                if ($a === null) {
                    $this->rechazarPieza($pieza['pieza_id'], "apunta a la asignatura {$id}, que no existe en este colegio.", $revisadas);
                }

                if ($a->deleted_at !== null) {
                    // Dejarla entrar mete basura en la versión, y calcular Σ ≤ IH
                    // sobre las vivas con una pieza apuntando a una borrada
                    // descuadra sin explicación posible. En este colegio hay 240 en
                    // la papelera.
                    $this->rechazarPieza($pieza['pieza_id'], "apunta a la asignatura {$id} ({$a->materia} de {$a->grupo}), que está en la papelera.", $revisadas);
                }

                if ((int) $a->year_id !== $yearId) {
                    // La cuarta de la §6, y va **por JOIN, no por columna**:
                    // `asignaturas` no tiene `year_id` y el año le llega por
                    // `grupos.year_id`. La abrió la decisión 13 —subir vale en
                    // cualquier año—, y este es el ÚNICO sitio donde existe: el
                    // emisor del escritorio guarda el año una sola vez, así que no
                    // puede ni detectarla. Sin esto las filas entran, el veredicto
                    // sale limpio, y lo cobra la §7 derivando las columnas del año
                    // equivocado.
                    $this->rechazarPieza($pieza['pieza_id'], "apunta a la asignatura {$id} ({$a->materia} de {$a->grupo}), que es del año lectivo {$a->year_id} y esta versión es del {$yearId}. Marcarla oficial derivaría las columnas de día del año que no es.", $revisadas);
                }
            }

            foreach ($pieza['docentes'] as $id) {
                if (! isset($profesores[$id])) {
                    // Esto NO es una de las seis de la §6: es que
                    // `horario_pieza_docente.profesor_id` tiene clave foránea contra
                    // `profesores`, así que un identificador que no existe reventaría
                    // el `INSERT` con un 500 que no dice a quién culpa. Y el docente
                    // se nombra con `profesores.id`, nunca con `users.id`: son dos
                    // columnas de la misma fila y coger la que no es sale gratis.
                    $this->rechazarPieza($pieza['pieza_id'], "trae el docente {$id}, que no es un profesores.id de este colegio. Ojo: el docente se nombra con profesores.id, NUNCA con users.id.", $revisadas);
                }
            }

            // Los grupos de la pieza, que es lo que mira el choque de grupo.
            $pieza['grupos'] = array_values(array_unique(array_map(
                fn ($id) => (int) $asignaturas[$id]->grupo_id,
                $pieza['asignaciones']
            )));
        }
        unset($pieza);

        if ($total !== count($normalizadas)) {
            // No puede pasar: si pasa, es que algo se perdió por el camino y
            // guardar una versión con menos piezas de las que subieron sería
            // exactamente «una versión a medias que parece un horario».
            abort(500, "Se recibieron {$total} piezas y quedaron ".count($normalizadas).'. Nada se escribió.');
        }

        return $normalizadas;
    }

    /**
     * Las asignaturas de todas las piezas, en **una** consulta, con su año por
     * JOIN y su materia y grupo para poder nombrarlas en un rechazo.
     *
     * `deleted_at` viene en el `SELECT` en vez de filtrarse con un `WHERE`: una
     * asignatura borrada tiene que salir como *«está en la papelera»* y no como
     * *«no existe»*, que son dos arreglos distintos en el colegio.
     *
     * @param  array<int, array<string, mixed>>  $piezas
     * @return array<int, \stdClass>
     */
    protected function asignaturasDe(array $piezas): array
    {
        $ids = [];
        foreach ($piezas as $pieza) {
            foreach ($pieza['asignaciones'] as $id) {
                $ids[$id] = true;
            }
        }

        if ($ids === []) {
            return [];
        }

        $ids = array_keys($ids);
        $marcadores = implode(',', array_fill(0, count($ids), '?'));

        $filas = DB::select(
            'SELECT a.id, a.creditos, a.deleted_at, a.grupo_id, g.year_id, g.nombre AS grupo, m.materia AS materia
               FROM asignaturas a
               JOIN grupos g ON g.id = a.grupo_id
               LEFT JOIN materias m ON m.id = a.materia_id
              WHERE a.id IN ('.$marcadores.')',
            $ids
        );

        $porId = [];
        foreach ($filas as $fila) {
            $porId[(int) $fila->id] = $fila;
        }

        return $porId;
    }

    /**
     * Los `profesores.id` que existen, de entre los que trajo la versión.
     *
     * @param  array<int, array<string, mixed>>  $piezas
     * @return array<int, true>
     */
    protected function profesoresDe(array $piezas): array
    {
        $ids = [];
        foreach ($piezas as $pieza) {
            foreach ($pieza['docentes'] as $id) {
                $ids[$id] = true;
            }
        }

        if ($ids === []) {
            return [];
        }

        $ids = array_keys($ids);
        $marcadores = implode(',', array_fill(0, count($ids), '?'));

        $vivos = [];
        foreach (DB::select('SELECT id FROM profesores WHERE id IN ('.$marcadores.')', $ids) as $fila) {
            $vivos[(int) $fila->id] = true;
        }

        return $vivos;
    }

    /**
     * El veredicto de la §6: las tres que se comprueban, las tres que no, y **la
     * población de cada renglón**.
     *
     * Las dos duras que pueden rechazar —choque de grupo y choque de docente— y la
     * tercera —Σ ≤ IH— abortan con 422 **enumerando a los culpables**, porque «hay
     * choques» no distingue *«revisé las 345 y encontré tres»* de *«me rendí en la
     * primera»*. Lo demás baja a renglón.
     *
     * **La población sale de esta corrida, no del código.** 345 y 134 son cifras
     * de `simonbolivar`; escritas a mano dirían 345 en el colegio catorce habiendo
     * mirado 200, que es exactamente la mentira que la opción B existe para
     * impedir.
     *
     * @param  array<int, array<string, mixed>>  $piezas
     * @return array<string, mixed>
     */
    protected function veredictoDeLaVersion(array $piezas, \stdClass $anio, string $nombreColegio): array
    {
        $yearId = (int) $anio->id;

        // ── La casilla, no la pieza. Un bloque de `duracion` 2 ocupa dos.
        $porGrupo = [];
        $porDocente = [];
        $casillas = 0;

        foreach ($piezas as $pieza) {
            for ($k = 0; $k < $pieza['duracion']; $k++) {
                $franja = $pieza['franja'] + $k;
                $casillas++;

                foreach ($pieza['grupos'] as $grupoId) {
                    // `pieza_id` como clave y no un contador: la misa de seis grupos
                    // son seis filas de la MISMA pieza, y contando filas se
                    // declararía a sí misma un choque.
                    $porGrupo[$grupoId][$pieza['dia']][$franja][$pieza['pieza_id']] = true;
                }

                foreach ($pieza['docentes'] as $profesorId) {
                    $porDocente[$profesorId][$pieza['dia']][$franja][$pieza['pieza_id']] = true;
                }
            }
        }

        $choquesDeGrupo = $this->choques($porGrupo);
        $choquesDeDocente = $this->choques($porDocente);

        if ($choquesDeGrupo !== [] || $choquesDeDocente !== []) {
            $cuantos = count($choquesDeGrupo) + count($choquesDeDocente);
            $this->rechazar([
                'message' => "La versión no entra: {$cuantos} casilla(s) con dos piezas encima. Se revisaron ".count($piezas)." piezas y {$casillas} casillas. Nada se escribió.",
                'motivo' => 'choque',
                'choques_de_grupo' => $choquesDeGrupo,
                'choques_de_docente' => $choquesDeDocente,
                'piezas_revisadas' => count($piezas),
                'casillas_revisadas' => $casillas,
            ]);
        }

        // ── Σ contra IH, sobre las asignaciones VIVAS DEL AÑO y no sólo las que
        //    trajo la versión: una asignatura que la versión no menciona gasta cero
        //    horas de su IH, y callarlo sería otra vez el `[]` que se lee como
        //    «todo bien».
        $gastado = [];
        $lecciones = 0;

        foreach ($piezas as $pieza) {
            foreach ($pieza['asignaciones'] as $id) {
                $gastado[$id] = ($gastado[$id] ?? 0) + $pieza['duracion'];
                $lecciones++;
            }
        }

        $delAnio = DB::select(
            'SELECT a.id, a.creditos, g.nombre AS grupo, m.materia AS materia
               FROM asignaturas a
               JOIN grupos g ON g.id = a.grupo_id
               LEFT JOIN materias m ON m.id = a.materia_id
              WHERE g.year_id = ? AND a.deleted_at IS NULL AND g.deleted_at IS NULL',
            [$yearId]
        );

        $pasadas = [];
        $completas = 0;
        $incompletas = [];
        $sinIh = [];

        foreach ($delAnio as $a) {
            $id = (int) $a->id;
            $suma = $gastado[$id] ?? 0;
            $nombre = "{$a->materia} de {$a->grupo}";

            if ($a->creditos === null) {
                // `creditos` es `int DEFAULT NULL`. Con un NULL dentro, `SUM(...) =
                // creditos` no da falso: se cae del resultado, y en PHP el `==`
                // acusa a quien no tiene culpa. Las dos lecturas son malas y ninguna
                // hace ruido, así que esto se nombra y se cuenta en vez de decidirse.
                $sinIh[] = ['asignatura_id' => $id, 'nombre' => $nombre, 'colocadas' => $suma];

                continue;
            }

            $ih = (int) $a->creditos;

            if ($suma > $ih) {
                $pasadas[] = ['asignatura_id' => $id, 'nombre' => $nombre, 'colocadas' => $suma, 'ih' => $ih];
            } elseif ($suma === $ih) {
                $completas++;
            } else {
                $incompletas[] = ['asignatura_id' => $id, 'nombre' => $nombre, 'colocadas' => $suma, 'ih' => $ih];
            }
        }

        if ($pasadas !== []) {
            // La DURA. Gastar más horas de las que la asignación tiene es imposible
            // en cualquier lectura, y eso sí es un fichero mal armado.
            $this->rechazar([
                'message' => count($pasadas).' asignación(es) gastan más horas de las que tienen. Se revisaron '.count($delAnio).' asignaciones vivas del año. Nada se escribió.',
                'motivo' => 'suma-mayor-que-la-ih',
                'asignaciones' => $pasadas,
                'asignaciones_revisadas' => count($delAnio),
            ]);
        }

        $conIh = count($delAnio) - count($sinIh);

        return [
            'poblacion' => [
                'piezas' => count($piezas),
                'casillas' => $casillas,
                'lecciones' => $lecciones,
                'asignaciones_vivas_del_anio' => count($delAnio),
                'asignaciones_que_toca_la_version' => count($gastado),
                'docentes' => count($porDocente),
                'grupos' => count($porGrupo),
            ],
            'comprobadas' => [
                'grupo_sin_choque' => "✓ sobre {$casillas} casillas de ".count($porGrupo).' grupo(s)',
                'docente_sin_choque' => "✓ sobre {$casillas} casillas de ".count($porDocente).' docente(s) — SÓLO los docentes que trajo la versión: una pieza sin docente no entra en esta comprobación y no es un choque menos',
                'suma_menor_o_igual_que_la_ih' => "✓ sobre {$conIh} asignación(es) con IH",
            ],
            'renglones' => [
                // Blanda: la cuenta, no un aprobado. Sin ella «incompleta» se lee
                // como «rota»; con ella el coordinador ve lo que le falta por
                // colocar y decide si publica igual.
                'suma_igual_que_la_ih' => [
                    'completas' => $completas,
                    'de' => $conIh,
                    'incompletas' => count($incompletas),
                    'cuales' => $this->hastaCincuenta($incompletas),
                ],
                'nombre_del_colegio' => $nombreColegio === (string) $anio->nombre_colegio
                    ? ['coincide' => true, 'del_cuerpo' => $nombreColegio, 'del_servidor' => (string) $anio->nombre_colegio]
                    // BLANDO a propósito, y nunca puerta cerrada: no es una
                    // identidad sino texto libre, editable desde configuración y
                    // distinto por año. Un colegio que se renombró legítimamente
                    // entre el import y la subida no se puede quedar sin poder
                    // subir su horario.
                    : ['coincide' => false, 'del_cuerpo' => $nombreColegio, 'del_servidor' => (string) $anio->nombre_colegio],
            ],
            'no_comprobadas' => [
                'asignaciones_sin_ih' => [
                    'cuantas' => count($sinIh),
                    'de' => count($delAnio),
                    'porque' => 'asignaturas.creditos es NULL: no hay contra qué comparar. No es 422 a propósito — un dato incompleto del colegio no puede convertir el módulo en inutilizable.',
                    'cuales' => $this->hastaCincuenta($sinIh),
                ],
                'salon' => 'NO COMPROBADO: falta capacidad_grupos en el servidor. La iglesia con seis grupos es indistinguible de dos grupos metidos en un aula, y la capacidad que viaja la elige el cliente — comprobar una regla contra un número que manda el mismo que quiere pasarla no es comprobar.',
                'disponibilidad' => 'NO COMPROBADA: las disponibilidades viven en el fichero de proyecto, no en este servidor.',
                'jornada' => 'NO COMPROBADA: la rejilla y los timbres viven en el fichero de proyecto, así que aquí no se sabe si la franja cae dentro de la jornada del nivel ni si cruza un descanso.',
            ],
        ];
    }

    /**
     * Nombra hasta cincuenta y corta, **sin tocar nunca la cuenta de al lado**.
     *
     * `horario_versiones.comprobaciones` es `text`: **65.535 bytes**. El veredicto
     * de `lleno.myvch` ocupa 1.621 —medido—, pero sus listas crecen con las
     * asignaciones del año, y un colegio con mil asignaciones a medio colocar lo
     * pasaría. En MySQL estricto eso es un 1406 que tumba una subida buena; sin
     * estricto, **el veredicto se guarda cortado por la mitad y nadie se entera**,
     * que es peor.
     *
     * Se corta la lista de nombres y **no la cifra**, que es lo que la §6 exige:
     * la población va siempre, los nombres son la comodidad. Es la misma regla que
     * el emisor del escritorio aplica con diez.
     *
     * @param  array<int, array<string, mixed>>  $cosas
     * @return array<int, array<string, mixed>>
     */
    protected function hastaCincuenta(array $cosas): array
    {
        return array_slice($cosas, 0, 50);
    }

    /**
     * Las casillas con más de un `pieza_id` encima, aplanadas para el 422.
     *
     * @param  array<int, array<int, array<int, array<string, true>>>>  $ocupacion
     * @return array<int, array<string, mixed>>
     */
    protected function choques(array $ocupacion): array
    {
        $choques = [];

        foreach ($ocupacion as $de => $porDia) {
            foreach ($porDia as $dia => $porFranja) {
                foreach ($porFranja as $franja => $piezas) {
                    if (count($piezas) > 1) {
                        $choques[] = [
                            'id' => (int) $de,
                            'dia' => (int) $dia,
                            'franja' => (int) $franja,
                            'piezas' => array_keys($piezas),
                        ];
                    }
                }
            }
        }

        return $choques;
    }

    /**
     * Un `INSERT` de varias filas por lote, en vez de uno por fila.
     *
     * `lleno.myvch` son 345 lecciones: fila a fila son 345 viajes de ida y vuelta
     * dentro de la transacción. El lote es de 200 filas porque lo que corta en
     * cPanel no es PHP sino `max_allowed_packet` de MySQL, y lo hace con un error
     * que no se parece a «esto es muy grande».
     *
     * @param  array<int, array<int, mixed>>  $filas
     */
    protected function insertarEnLotes(string $tablaYColumnas, int $columnas, array $filas): void
    {
        if ($filas === []) {
            return;
        }

        $fila = '('.implode(',', array_fill(0, $columnas, '?')).')';

        foreach (array_chunk($filas, 200) as $lote) {
            $valores = implode(',', array_fill(0, count($lote), $fila));
            DB::insert("INSERT INTO {$tablaYColumnas} VALUES {$valores}", array_merge(...$lote));
        }
    }

    /**
     * `GET horario/versiones` — las versiones del año (§5.3).
     *
     * `auth.personal` y nada más: cualquier docente puede ver qué versiones hay
     * (decisión 12, más abierta que lo que proponían las dos sesiones). Tiene
     * sentido, porque el horario es un papel que acaba pegado en la puerta del
     * salón.
     *
     * **Y con eso, la condición que va antes que la ruta: listar no es
     * descargar.** Nombre, fecha, quién la subió, si es la oficial y su veredicto.
     * **Ni `proyecto` ni las lecciones.** El blob se descarga por otro camino y
     * con otro permiso el día que haga falta —sería una cuarta ruta, y no está
     * pedida ni autorizada (§10.2.3)—; hoy no hace falta ninguno.
     */
    public function getVersiones(): JsonResponse
    {
        // El año sale del TOKEN y no de la petición: quien quiera ver otro año se
        // mueve a ese año, que es el producto (16). Un `year_id` por parámetro
        // sería un identificador que llega de fuera y no comprueba nadie, y de eso
        // este repositorio tiene herramienta propia
        // (`tools/identificadores-del-cuerpo.py`).
        //
        // **Y no se filtra por `y.actual`**: con la decisión 13 el año del token
        // puede ser uno pasado o cerrado, y allí también hay versiones que listar.
        $yearId = (int) $this->user->year_id;

        // ── LAS COLUMNAS, UNA A UNA. Aquí un `SELECT hv.*` es la fuga.
        //
        // `horario_versiones.proyecto` es el fichero de proyecto ENTERO del colegio
        // —128.779 bytes en el único real que existe— y esta ruta la puede llamar
        // **cualquiera de los 53 docentes**, porque lleva `auth.personal` y nada
        // más. Ésa es justo la razón por la que Joseth pudo abrir la lectura a todo
        // el personal: **listar no es descargar**. Con un asterisco, la decisión 12
        // se convierte en «cualquier docente se baja el horario entero del colegio»
        // sin que nadie lo haya decidido.
        //
        // Las lecciones tampoco viajan, y por lo mismo: son el horario. El blob y
        // las lecciones se descargarán por otro camino y con otro permiso el día
        // que haga falta — sería una cuarta ruta, y **su número se cuenta el día que
        // se autorice** (§10.2.3).
        //
        // `LEFT JOIN years` y no `JOIN`: con el `INNER`, un año en la papelera
        // devolvería **cero filas**, o sea «este año no tiene versiones» en vez de
        // un error. Es el `[]` de la §2 otra vez — se lee como «todo bien».
        //
        // `LEFT JOIN users` para el nombre de quien subió, como `nivelada_por_username`
        // en `NotasController`: `subida_por` es `users.id` sin foránea a propósito
        // —el rastro sobrevive a que la cuenta se borre—, así que el `LEFT` es
        // obligatorio o la versión de un usuario borrado desaparecería del listado.
        $filas = DB::select(
            'SELECT hv.id, hv.year_id, hv.nombre, hv.subida_por, us.username AS subida_por_username,
                    hv.comprobaciones, hv.created_at,
                    IF(y.horario_version_id = hv.id, 1, 0) AS es_oficial
               FROM horario_versiones hv
               LEFT JOIN years y ON y.id = hv.year_id
               LEFT JOIN users us ON us.id = hv.subida_por
              WHERE hv.year_id = ?
              ORDER BY hv.id DESC',
            [$yearId]
        );

        return response()->json(array_map(fn ($f) => [
            'id' => (int) $f->id,
            'year_id' => (int) $f->year_id,
            'nombre' => (string) $f->nombre,
            'subida_por' => $f->subida_por === null ? null : (int) $f->subida_por,
            'subida_por_username' => $f->subida_por_username,
            'created_at' => $f->created_at,
            // La oficial sale del PUNTERO `years.horario_version_id`, no de una
            // bandera en esta tabla: MySQL no tiene índices parciales, así que una
            // bandera no se puede atar a «como mucho una por año» y el día que
            // hubiera dos en verdadero este listado enseñaría dos oficiales.
            'es_oficial' => (int) $f->es_oficial === 1,
            // **Como se guardó, no recalculado**: es el historial. Recalcularlo aquí
            // diría lo que el servidor opina HOY de una versión que se comprobó con
            // el código de otro día, que es justo lo que el veredicto guardado
            // existe para no perder.
            //
            // Si el texto guardado no fuera JSON válido viaja **tal cual**, en vez
            // del `null` que devolvería `json_decode`: un veredicto ilegible se ve;
            // uno borrado en silencio se lee como que no había ninguno.
            'comprobaciones' => $this->veredictoGuardado($f->comprobaciones),
        ], $filas));
    }

    /**
     * El veredicto tal y como está en la fila, sin recalcular y sin perderlo.
     *
     * `json_decode` devuelve `null` tanto para el texto `"null"` como para un JSON
     * roto, y las dos lecturas acaban en el mismo `null` de la respuesta. Aquí se
     * distinguen: lo que no se puede decodificar sale como la cadena que es.
     */
    protected function veredictoGuardado(?string $guardado)
    {
        if ($guardado === null || $guardado === '') {
            return null;
        }

        $decodificado = json_decode($guardado, true);

        return json_last_error() === JSON_ERROR_NONE ? $decodificado : $guardado;
    }

    /**
     * `PUT horario/versiones/{id}/oficial` — marca cuál es la oficial (§5.3).
     *
     * Criterio **`Autoriza::puedePublicarHorario`**, que es método nuevo y no uno
     * de los que ya había: superusuario o el rol `Coord académico`. Secretaría
     * sube pero no publica.
     *
     * Marcar la oficial es un `UPDATE` de `years.horario_version_id` **y** la
     * derivación de la §7, las dos en la misma transacción — y ahí están las dos
     * trampas que el documento ya midió:
     *
     *   - **El alcance de la derivación es el AÑO ENTERO, no las filas de la
     *     versión.** Se pone todo el alcance del año a 0 y luego a 1 lo que trae
     *     la versión. Leído literal («recalcula las columnas de cada asignación
     *     desde las lecciones de esa versión») sólo se escribirían las que
     *     aparecen, así que una asignatura que la versión 2 quita **se quedaría
     *     con el `martes = 1` de la versión 1** y el docente seguiría viendo una
     *     clase que ya no existe, salida de una columna que nadie volvió a tocar.
     *   - **«Las asignaciones de este año» es un JOIN, no un `WHERE`**:
     *     `asignaturas` no tiene `year_id` y el año le llega por `grupos.year_id`.
     *     Equivocarse ahí publicando un año cerrado significaría **poner a cero
     *     las columnas del año abierto**, y con la decisión 13 eso no es teórico.
     *
     * Y va en el mismo lote, no después: **el fallo del sábado de la §2.1**
     * —`$dia + 1 = 7`, el `switch` sin caso 7 y «mañana» devolviendo todas las
     * asignaturas del docente— es invisible hoy porque las siete columnas están
     * vacías, y **se estrena el día que se rellenen**. Arreglarlo después
     * convierte el estreno del horario en un fallo nuevo.
     */
    public function putOficial($id): never
    {
        Autoriza::exigir(Autoriza::puedePublicarHorario($this->user),
            'No tienes permiso para marcar la versión oficial del horario.');

        abort(501, 'Marcar la versión oficial del horario todavía no está implementado.');
    }

    /**
     * Un 422 con el cuerpo entero, no sólo con el `message`.
     *
     * Copiado de `RubricasController::rechazar()`, y por la misma razón: `abort()`
     * a secas sólo sabe poner un texto, y **un error que sólo es texto obliga al
     * cliente a leerlo con expresiones regulares** para saber a qué pieza culpa.
     * Con las claves aparte, la pantalla del escritorio puede señalar la casilla.
     *
     * @param  array<string, mixed>  $cuerpo
     */
    protected function rechazar(array $cuerpo): never
    {
        abort(response()->json($cuerpo, 422));
    }

    /**
     * El 422 de una pieza concreta, con `pieza_id` y `motivo` **aparte** del
     * `message`.
     *
     * **El error dice su población, y eso no es adorno** (§6): «hay choques» no
     * distingue *«revisé las 345 y encontré tres»* de *«me rendí en la primera»*,
     * y de las dos lecturas la falsa es la que hace archivar el asunto. Por eso
     * `$revisadas` no es opcional por comodidad: es lo que separa un rechazo
     * legible de un `[]` que se lee como «todo bien» (§2).
     *
     * @param  ?int  $revisadas  cuántas piezas se llegaron a mirar, si se sabe
     */
    protected function rechazarPieza(string $piezaId, string $motivo, ?int $revisadas = null): never
    {
        $poblacion = $revisadas === null ? '' : " Se revisaron {$revisadas}.";

        $this->rechazar([
            'message' => "La pieza {$piezaId} no vale: {$motivo} Nada se escribió.{$poblacion}",
            'pieza_id' => $piezaId,
            'motivo' => $motivo,
            'piezas_revisadas' => $revisadas,
        ]);
    }
}
