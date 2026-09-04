<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResuelveElUsuario;
use App\Support\Autoriza;
use App\Support\CamposQueVinieron;
use App\Support\Reloj;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;
use Illuminate\Validation\ValidationException;

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
 * ## Estado: los cuatro métodos escritos — ninguno contesta ya 501
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
 * Aquel 501 decía exactamente lo que pasaba —la ruta existe, está autorizada y
 * todavía no hace nada—, que es lo que un 404 o un 200 vacío no habrían dicho. Y la
 * regla que dejó, que sobrevive al 501: **la comprobación de permiso va ANTES del
 * cuerpo**, porque dejarla para el que lo escriba es cómo una ruta acaba en
 * producción sin ella.
 *
 * `getLecciones` (§9.bis) entró el 4 sep 2026 y es la única de las cuatro que **no
 * nació a 501**: se decidió y se escribió el mismo día.
 *
 * `putOficial` dejó de ser 501 el 2 sep 2026, y con él **se estrenan las siete
 * columnas de día de `asignaturas`** (§7): hasta ahora estaban vacías en los
 * quince colegios, y por eso «Clases de hoy» no enseñaba nada. Ese estreno se
 * llevó por delante el fallo del sábado de la §2.1 —`$dia + 1 = 7` en
 * `ChangeAskedController`—, que iba en el mismo lote a propósito: invisible con
 * las columnas vacías, y un fallo nuevo el día que se llenan.
 *
 * ## Por qué SQL y no Eloquent
 *
 * Por lo mismo que el resto del repo, y con la lección de `notas/detailed`
 * delante: **nunca `SELECT *`**, columnas escritas a mano. Aquí no es sólo higiene
 * — es la regla 3 de arriba.
 */
class HorarioController extends Controller
{
    use ResuelveElUsuario;

    /**
     * El convenio de `dia`, **en un solo sitio y con el índice por clave**.
     *
     * `0 = domingo … 6 = sábado` es contrato (§5.2.5), y es el mismo con el que
     * `ChangeAskedController::asignaturas_dia()` consume las siete columnas por
     * `Carbon::dayOfWeek`. Por eso la derivación de la §7 **no traduce nada**.
     *
     * Escrito como mapa y no como siete líneas sueltas porque un convenio repetido
     * es un convenio que se puede cambiar a medias: si el orden de este array se
     * toca, se mueven a la vez la derivación y su recuento, que es lo que impide
     * que uno de los dos quede diciendo otra cosa. Y el fallo que evita **no da
     * error**: con el convenio corrido, el lunes se pinta el domingo y el viernes
     * cae en jueves, el veredicto de la §6 sale en verde igual y lo único que se
     * nota es que el docente ve el horario de otro día.
     */
    private const COLUMNAS_DE_DIA = [
        0 => 'domingo',
        1 => 'lunes',
        2 => 'martes',
        3 => 'miercoles',
        4 => 'jueves',
        5 => 'viernes',
        6 => 'sabado',
    ];

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
        /*
         * **El 422 de forma también lleva `motivo`, y eso no salía de serie.**
         *
         * Lo midió `myvc-horarios-83` contra el docker: los seis rechazos de dominio de
         * esta familia traen `motivo`, y el de `Request::validate` **no** — sale con
         * `errors` y un `message` que dice `validation.required (and 6 more errors)`.
         * Así que una pantalla que dé `motivo` por seguro se rompe justo en el caso más
         * tonto, el del cuerpo mal formado.
         *
         * Se envuelve **sólo aquí** y no en toda la API (decisión de Joseth, 3 sep 2026):
         * la familia `horario/` es de tres rutas y ningún cliente suyo está desplegado
         * todavía, así que cerrarlo cuesta esto; hacerlo global movería la respuesta de
         * muchas rutas vivas a la vez para un contrato que sólo pidió un cliente.
         *
         * **`errors` se conserva tal cual**: es aditivo, así que un cliente que ya lea
         * `errors` no se entera de nada.
         */
        try {
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
        } catch (ValidationException $e) {
            $this->rechazar([
                'message' => 'El cuerpo de la subida no tiene la forma que pide el contrato (§5.2 del 23). Nada se escribió.',
                'motivo' => 'cuerpo-mal-formado',
                'errors' => $e->errors(),
            ]);
        }
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

        // ── EL ENVOLTORIO, Y ESTO NO ES ADORNO
        //
        // Un `[]` pelado no distingue **«este año todavía no tiene versiones»** de
        // «algo salió mal», y lo primero va a ser **lo normal**: hasta que un colegio
        // suba su primer horario, ésta es la respuesta que da. Es el `[]` de la §2 —
        // `horario_hoy` volvía vacío para todos los docentes todos los días y nadie lo
        // reportó, porque **un vacío se parece a una respuesta legítima**.
        //
        // Lo levantó `myvc-horarios-cc` comparando su versión con ésta, y el argumento
        // es el que este mismo método ya usa unas líneas más arriba para justificar el
        // `LEFT JOIN years`: la casa aplicaba su regla en la consulta y no en la salida.
        //
        // **`oficial_id` y `es_oficial` son el mismo hecho dos veces, a propósito y con
        // una condición**: hoy no pueden discrepar porque salen de la misma lectura en
        // la misma petición, pero ésa es la forma de la que sale un segundo escritor —el
        // día que alguien pagine esto y `oficial_id` venga de otra consulta, dirían
        // cosas distintas y no lo diría nadie—. Por eso el duplicado **está atado por un
        // test** (`HorarioListadoTest::es_oficial_es_verdadero_exactamente_en_la_oficial`):
        // aquí un dato repetido sólo se tolera si es un invariante comprobado.
        $versiones = array_map(fn ($f) => [
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
        ], $filas);

        $oficial = array_values(array_filter($versiones, fn ($v) => $v['es_oficial']));

        return response()->json([
            'year_id' => $yearId,
            // El puntero tal cual, y `null` cuando el año no ha publicado ninguna: es un
            // estado —subir no es publicar— y no un hueco.
            'oficial_id' => $oficial === [] ? null : $oficial[0]['id'],
            // La población. Sin ella, `versiones: []` se lee como «todo bien».
            'total' => count($versiones),
            'versiones' => $versiones,
        ]);
    }


    /**
     * `GET horario/versiones/{id}/lecciones` — el horario de una versión, **para
     * pintarlo**.
     *
     * Es la cuarta ruta de la §9.bis del [23](../../../docs/migracion/23-horarios.md).
     * La decidió Joseth el 3 sep 2026 —*el horario que se cuadra en el escritorio se
     * tiene que poder MIRAR en un menú de la web, y la web LEE DE LA API*— y su forma
     * la fijó la §9.bis.3 con lo medido en los dos repositorios el 4 sep.
     *
     * ## Por `{id}` y no `horario/oficial`, y la razón es la asimetría de Joseth
     *
     * *Subir no es publicar* (decisión 5). Quien va a publicar necesita **mirar una
     * versión que todavía no es la oficial** —es justo la pantalla que hoy no existe—,
     * y con `horario/oficial` esa pantalla no se puede escribir. El `{id}` se comprueba
     * contra el año del **token**: una versión de otro año da **404** y no 403, porque
     * responder «existe pero no es tuya» ya es contestar por ella.
     *
     * ## LEE DE `horario_lecciones`, NUNCA de las siete columnas de día
     *
     * Y esto no es una preferencia de implementación: es lo que contestó Joseth el 4
     * sep 2026 cuando el front encontró que **hay dos escritores de esas columnas**
     * —`toggleDia` de la pantalla de asignaturas y `putOficial` (§9.bis.4)—. Los
     * booleanos de `asignaturas` son *«un esfuerzo por mostrarle sólo las materias de
     * hoy y de mañana al docente en el panel»* y **no alimentan el horario**: se quedan
     * porque un colegio que nunca use este sistema tiene que poder seguir diciendo qué
     * días se da cada materia.
     *
     * Además no servirían: **las siete columnas no tienen franja**, ni se les puede
     * añadir sin cambiar la respuesta de `asignaturas_dia` (§7). Sirven para *qué*
     * clases hay hoy, nunca para *dónde* van en la rejilla.
     *
     * **Y esta ruta no da por supuesto que las dos fuentes coincidan.** Pueden no
     * hacerlo —conmutar un día después de publicar descuadra las dos sin error ni
     * aviso—, y quien quiera saberlo tiene `tools/deriva-del-horario.php`, que lo mide
     * con su población. Aquí no se compara nada: una lectura que va a llamarse cada
     * vez que se abre la rejilla no es donde se pone un diagnóstico.
     *
     * ## `catalogos` va SIEMPRE, y es la mitad del contrato
     *
     * La midió `myvc-horarios-90` sobre 144 corridas: con un `Proyecto` incompleto
     * **55 informes salen distintos sin ningún aviso y a 8 se les APAGA un aviso que
     * estaba encendido** — la hoja sale mal y encima deja de avisar de lo que antes
     * avisaba. Y el caso que ocurre de verdad no es el catálogo ausente sino el
     * **catálogo a medias**, que hace *menos* ruido: los salones fuera del todo dejan
     * un informe en cero hojas y eso se nota; a medias, seis hojas se quedan en tres y
     * **cero avisos**.
     *
     * Por eso cada catálogo viaja con su estado y su población, y **son cuatro estados
     * y no dos**:
     *
     *   - `completo`     lo guardamos y está todo.
     *   - `parcial`      lo guardamos y hay menos de lo que la versión usa.
     *   - `vacio`        lo guardamos, el colegio no creó ninguno, **y es legítimo**.
     *   - `sin_catalogo` **esta API no puede saberlo**, hoy ni por este camino.
     *
     * **La tercera la obliga la restricción de Joseth del 4 sep 2026: el horario es
     * OPCIONAL.** Lo obligatorio en MyVC es crear asignaturas con IH; salones, dobles y
     * fichas por IH **no** lo son y así deben seguir. Sin separar `vacio` de
     * `sin_catalogo`, la única forma de que la pantalla no mienta sería exigirle al
     * colegio que rellene salones y timbres — o sea, convertir en obligatorio por la
     * puerta de atrás lo que él dejó opcional. **Un colegio que sólo tiene asignaturas
     * con IH recibe 200 con sus renglones en `vacio` y `sin_catalogo`, nunca un 422.**
     *
     * ## Los docentes van en LISTA, y ahí este método se aparta de lo que pidió el front
     *
     * El front pidió `profesor_id` y `nombre_profesor` **escalares**. Aquí viajan como
     * `docentes[]`, y el motivo es el caso raro que tiene el colegio: **los docentes
     * cuelgan de la pieza y no de la asignación** (§5.1) porque si la misa la da el
     * capellán, el titular de Religión **tiene esa hora libre** aunque la hora salga de
     * su asignación. Un escalar funcionaría hoy —medido el 4 sep 2026: **0 de 312**
     * piezas tienen dos docentes— y **se rompería en silencio el día que exista la
     * misa**, tirando al segundo docente sin dar ningún error. Es la forma de fallo que
     * este módulo lleva dos documentos evitando.
     *
     * `docentes: []` es legítimo y **frecuente**: **22 de las 312** piezas de la única
     * versión real no tienen ni una fila en `horario_pieza_docente`.
     */
    public function getLecciones($id): JsonResponse
    {
        $versionId = (int) $id;
        $yearId = (int) $this->user->year_id;

        // El año sale del TOKEN, igual que en `getVersiones` y por lo mismo: un
        // `year_id` por parámetro sería un identificador que llega de fuera y no
        // comprueba nadie. Y va en el `WHERE` junto al id, no en un `if` después: así
        // «no existe» y «no es de tu año» son la misma respuesta y no hay forma de
        // averiguar qué versiones tienen los otros años preguntando por ellas.
        $version = DB::select(
            'SELECT hv.id, hv.year_id, hv.nombre, hv.created_at, hv.comprobaciones,
                    IF(y.horario_version_id = hv.id, 1, 0) AS es_oficial
               FROM horario_versiones hv
               LEFT JOIN years y ON y.id = hv.year_id
              WHERE hv.id = ? AND hv.year_id = ?',
            [$versionId, $yearId]
        );

        if ($version === []) {
            abort(404, 'Esa versión del horario no existe en este año.');
        }

        // Una fila por (pieza × asignación), que es como están guardadas: la misa es
        // UNA pieza y N asignaciones (§5.1). Las columnas van nombradas una a una —un
        // `SELECT hl.*` traería de paso lo que se añada mañana a la tabla, y esta ruta
        // la llama cualquiera de los 53 docentes.
        $filas = DB::select(
            'SELECT hl.id, hl.pieza_id, hl.dia, hl.franja, hl.duracion,
                    hl.salon, hl.salon_capacidad_grupos,
                    hl.asignatura_id, a.creditos, a.orden,
                    m.materia, m.alias AS alias_materia,
                    g.id AS grupo_id, g.nombre AS nombre_grupo, g.abrev AS abrev_grupo
               FROM horario_lecciones hl
               LEFT JOIN asignaturas a ON a.id = hl.asignatura_id
               LEFT JOIN materias m ON m.id = a.materia_id
               LEFT JOIN grupos g ON g.id = a.grupo_id
              WHERE hl.version_id = ?
              ORDER BY hl.dia, hl.franja, hl.id',
            [$versionId]
        );

        // Los `LEFT JOIN` de arriba no son cautela: la asignación de una lección puede
        // estar borrada cuando alguien mira una versión vieja —publicar y subir valen
        // en cualquier año (decisión 13)—, y con `INNER` esa lección **desaparecería de
        // la rejilla sin dejar hueco**. Con `LEFT` sale la casilla con su materia en
        // `null`, que es un agujero que se ve. Medido el 4 sep 2026 en la única versión
        // real: 0 de 312, o sea que hoy no pasa — no que no pueda pasar.

        $docentesPorPieza = $this->docentesDeLaVersion($versionId);

        $lecciones = array_map(fn ($f) => [
            'id' => (int) $f->id,
            'pieza_id' => (string) $f->pieza_id,
            // `0 = domingo`, el convenio de la §5.2.5, el mismo con el que se consumen
            // las siete columnas sobre `Carbon::dayOfWeek`. Se declara en la respuesta
            // (`ejes.convenio_dia`) en vez de dejar que el cliente lo deduzca: un
            // horario corrido un día cumple todas las reglas de la §6 y **no lo detecta
            // nadie**.
            'dia' => (int) $f->dia,
            // Base 1: la franja 1 es la primera lección del día.
            'franja' => (int) $f->franja,
            // En CASILLAS, nunca en minutos, y viaja aunque valga 1: sin ella el
            // cliente tiene que deducir una doble de dos filas contiguas, que es de la
            // familia «plausible y falso». Medido: 32 de 312 son bloques.
            'duracion' => (int) $f->duracion,
            'asignatura_id' => (int) $f->asignatura_id,
            'ih' => $f->creditos === null ? null : (int) $f->creditos,
            'materia' => $f->materia,
            // Los 35 materias vivas del colegio medido tienen alias, así que
            // `alias_materia` no es el campo raro: es el que se pinta.
            'alias_materia' => $f->alias_materia,
            'grupo_id' => $f->grupo_id === null ? null : (int) $f->grupo_id,
            'nombre_grupo' => $f->nombre_grupo,
            'abrev_grupo' => $f->abrev_grupo,
            // **No viaja `salon_id`**: no hay tabla de salones (§4), así que un campo
            // que sale `null` siempre sólo entrena al cliente a ignorarlo. Lo que hay
            // es el nombre que mandó la subida, y el catálogo dice que no hay ids.
            'nombre_salon' => $f->salon,
            'salon_capacidad_grupos' => $f->salon_capacidad_grupos === null ? null : (int) $f->salon_capacidad_grupos,
            'docentes' => $docentesPorPieza[$f->pieza_id] ?? [],
        ], $filas);

        return response()->json([
            'version' => [
                'id' => (int) $version[0]->id,
                'year_id' => (int) $version[0]->year_id,
                'nombre' => (string) $version[0]->nombre,
                'es_oficial' => (int) $version[0]->es_oficial === 1,
                'created_at' => $version[0]->created_at,
                // Como se guardó, no recalculado: es el historial (§5.3). Y es el campo
                // que el 422 de `acepto_perder` **no** puede usar como lectura fresca,
                // que es lo que corrigió `0faf099`.
                'comprobaciones' => $this->veredictoGuardado($version[0]->comprobaciones),
            ],
            'ejes' => $this->ejesDeLaVersion($lecciones),
            'catalogos' => $this->catalogosDeLaVersion($yearId, $versionId, $lecciones),
            'lecciones' => $lecciones,
            // La población, delante y siempre. Sin ella `lecciones: []` se lee como
            // «todo bien» — que es literalmente el fallo de la §2, el que estuvo meses
            // sin que nadie lo reportara.
            'total_lecciones' => count($lecciones),
        ]);
    }

    /**
     * Los docentes de cada pieza, indexados por `pieza_id`.
     *
     * Una consulta y no una por lección: son 312 filas y el bucle estaría dentro de un
     * `array_map` (`tools/consultas-en-bucle.py` existe justo para esto).
     *
     * `tono` sale de `profesores`, que es donde Joseth decidió el 4 sep 2026 que viva.
     * **Nace vacío en los diecisiete**, así que `null` va a ser el caso normal hasta que
     * alguien reparta los colores una primera vez: el contrato dice `string | null` y no
     * `string` por eso, no por prudencia.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    protected function docentesDeLaVersion(int $versionId): array
    {
        $filas = DB::select(
            'SELECT hpd.pieza_id, p.id, p.nombres, p.apellidos, p.tono
               FROM horario_pieza_docente hpd
               JOIN profesores p ON p.id = hpd.profesor_id
              WHERE hpd.version_id = ?
              ORDER BY p.apellidos, p.nombres, p.id',
            [$versionId]
        );

        $porPieza = [];

        foreach ($filas as $f) {
            $porPieza[$f->pieza_id][] = [
                'id' => (int) $f->id,
                'nombres' => $f->nombres,
                'apellidos' => $f->apellidos,
                'tono' => $f->tono,
            ];
        }

        return $porPieza;
    }

    /**
     * Los ejes de la rejilla, **declarados y sacados de las lecciones**, no supuestos.
     *
     * La rejilla del colegio vive en el fichero de proyecto del escritorio (§4), así que
     * aquí no hay «7 × 5» que devolver: lo único que este servidor sabe con certeza es
     * **qué días y qué franjas usa esta versión**. Devolver una rejilla inventada sería
     * peor que no devolverla, y tiene precedente medido: el aviso *«sin horas: el colegio
     * todavía no ha dado los timbres»* del escritorio pregunta *¿tengo timbres?*, así que
     * una jornada reconstruida por defecto **le apaga el aviso a 15 hojas** y les imprime
     * un horario que ese nivel nunca dio.
     *
     * Por eso `timbres` es `null` y no una lista vacía, y por eso el convenio del día se
     * **declara**: los dos repositorios coinciden en `0 = domingo` (medido el 4 sep 2026)
     * y el front todavía no tiene ninguna codificación numérica, así que la conversión la
     * escribirá alguien —y «no hace falta conversión» es justo la frase que hace que
     * nadie la busque.
     *
     * @param  list<array<string, mixed>>  $lecciones
     * @return array<string, mixed>
     */
    protected function ejesDeLaVersion(array $lecciones): array
    {
        $dias = array_values(array_unique(array_column($lecciones, 'dia')));
        $franjas = array_values(array_unique(array_column($lecciones, 'franja')));

        sort($dias);
        sort($franjas);

        return [
            'convenio_dia' => '0=domingo,1=lunes,…,6=sabado',
            'dias' => $dias,
            'franjas' => $franjas,
            // De `years`, que es donde está: son los minutos de una lección, no los
            // timbres. El pie del boletín ya lo imprime.
            'minutos_por_leccion' => $this->minutosPorLeccion(),
            // **`null`, y no una jornada por defecto.** Ver arriba: reconstruirla apaga
            // el centinela del escritorio justo en el caso para el que existe.
            'timbres' => null,
        ];
    }

    /** `years.minu_hora_clase` del año del token; `null` si el año no lo tiene puesto. */
    protected function minutosPorLeccion(): ?int
    {
        $fila = DB::selectOne('SELECT y.minu_hora_clase FROM years y WHERE y.id = ?', [(int) $this->user->year_id]);

        return $fila === null || $fila->minu_hora_clase === null ? null : (int) $fila->minu_hora_clase;
    }

    /**
     * El estado de cada catálogo **con su población**, que es la mitad del contrato.
     *
     * Cada renglón contesta lo mismo: *¿lo que va en esta respuesta está entero?*. Y la
     * regla que hay que sostener el día que se añada un catálogo nuevo: **una lista sin
     * su renglón aquí es un error del servidor, no un catálogo vacío.**
     *
     * @param  list<array<string, mixed>>  $lecciones
     * @return array<string, array<string, mixed>>
     */
    protected function catalogosDeLaVersion(int $yearId, int $versionId, array $lecciones): array
    {
        $total = count($lecciones);

        $conSalon = count(array_filter($lecciones, fn ($l) => $l['nombre_salon'] !== null));
        $salones = array_unique(array_filter(array_column($lecciones, 'nombre_salon')));

        $sinDocente = count(array_filter($lecciones, fn ($l) => $l['docentes'] === []));

        $docentes = (int) DB::selectOne(
            'SELECT COUNT(DISTINCT a.profesor_id) AS n
               FROM asignaturas a
               JOIN grupos g ON g.id = a.grupo_id
              WHERE g.year_id = ? AND a.deleted_at IS NULL AND a.profesor_id IS NOT NULL',
            [$yearId]
        )->n;

        $grupos = (int) DB::selectOne(
            'SELECT COUNT(*) AS n FROM grupos WHERE year_id = ? AND deleted_at IS NULL',
            [$yearId]
        )->n;

        $asignaciones = (int) DB::selectOne(
            'SELECT COUNT(*) AS n
               FROM asignaturas a
               JOIN grupos g ON g.id = a.grupo_id
              WHERE g.year_id = ? AND a.deleted_at IS NULL',
            [$yearId]
        )->n;

        return [
            'grupos' => ['estado' => $grupos === 0 ? 'vacio' : 'completo', 'total' => $grupos],
            'asignaciones' => [
                'estado' => $asignaciones === 0 ? 'vacio' : 'completo',
                'total' => $asignaciones,
                'lecciones_sin_asignacion_viva' => count(array_filter($lecciones, fn ($l) => $l['materia'] === null)),
            ],
            // **El criterio se nombra**, porque «docentes» admite dos lecturas y la otra
            // da otro número: aquí son los que tienen alguna asignación en el año, no
            // los vivos de `profesores` —de los que 42 de 47 ni siquiera tienen
            // `tipo_profesor`, así que esa columna no sirve hoy para decir quién enseña—.
            'docentes' => [
                'estado' => $docentes === 0 ? 'vacio' : 'completo',
                'total' => $docentes,
                'criterio' => 'con asignación viva en el año',
                'lecciones_sin_docente' => $sinDocente,
            ],
            // La columna existe desde el 4 sep 2026 y **nace vacía en todos**: mientras
            // nadie reparta los colores, esto es `vacio` y no `completo`. La diferencia
            // importa: seis de los ocho informes del escritorio pintan distinto sin él y
            // **nada se pone rojo**.
            'tono' => $this->estadoDelTono(),
            // El caso medido y el peor de los cuatro: 87 de 312 con salón y **3 nombres**
            // contra los 17 del proyecto real. Un catálogo a medias hace MENOS ruido que
            // uno ausente, así que aquí la población no es adorno.
            'salones' => [
                'estado' => $conSalon === 0 ? 'vacio' : ($conSalon < $total ? 'parcial' : 'completo'),
                'con_salon' => $conSalon,
                'de' => $total,
                'distintos' => count($salones),
                'hay_ids' => false,
                'motivo' => 'sólo viaja el nombre que mandó la subida: el servidor no guarda salones (§4)',
            ],
            'timbres' => [
                'estado' => 'sin_catalogo',
                'motivo' => 'la rejilla, los timbres y las jornadas por nivel viven en el fichero de proyecto (§4)',
            ],
            'disponibilidad' => [
                'estado' => 'sin_catalogo',
                'motivo' => 'ídem: el servidor no guarda la disponibilidad declarada (§4)',
            ],
            'restricciones' => [
                'estado' => 'sin_catalogo',
                'motivo' => 'ídem: restricciones, pesos y distribuciones de bloque (§4)',
            ],
        ];
    }

    /**
     * `tono`: `vacio` mientras nadie haya repartido un color, `parcial` o `completo` después.
     *
     * Se mira sobre los docentes **con asignación en el año**, que es el mismo criterio
     * del renglón `docentes`: contar sobre los 47 vivos daría un porcentaje más feo y
     * de otra población.
     *
     * @return array<string, mixed>
     */
    protected function estadoDelTono(): array
    {
        $fila = DB::selectOne(
            'SELECT COUNT(DISTINCT a.profesor_id) AS total,
                    COUNT(DISTINCT IF(p.tono IS NULL OR p.tono = "", NULL, a.profesor_id)) AS con_tono
               FROM asignaturas a
               JOIN grupos g ON g.id = a.grupo_id
               JOIN profesores p ON p.id = a.profesor_id
              WHERE g.year_id = ? AND a.deleted_at IS NULL AND a.profesor_id IS NOT NULL',
            [(int) $this->user->year_id]
        );

        $total = (int) $fila->total;
        $conTono = (int) $fila->con_tono;

        return [
            'estado' => $conTono === 0 ? 'vacio' : ($conTono < $total ? 'parcial' : 'completo'),
            'con_tono' => $conTono,
            'de' => $total,
            'motivo' => $conTono === 0
                ? 'la columna existe y nadie ha repartido los colores todavía'
                : null,
        ];
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
    public function putOficial($id)
    {
        Autoriza::exigir(Autoriza::puedePublicarHorario($this->user),
            'No tienes permiso para marcar la versión oficial del horario.');

        $versionId = (int) $id;

        $version = DB::select(
            'SELECT v.id, v.year_id, v.nombre FROM horario_versiones v WHERE v.id = ?',
            [$versionId]
        );

        if ($version === []) {
            abort(404, 'Esa versión del horario no existe.');
        }

        $yearId = (int) $version[0]->year_id;
        $ahora = Reloj::ahoraTexto();

        /*
         * `acepto_perder` — LA PUERTA DE LA DERIVA, y por qué es un NÚMERO y no un `true`.
         *
         * La §6 comprueba que cada asignación de la versión es del año **el día que se
         * sube**, y publicar es otro día y otra decisión («subir no publica», 17). Entre
         * los dos, alguien puede borrar una asignatura o mover su grupo: esas filas se
         * caen del alcance, su día no se escribe y **el horario pierde esas clases sin
         * que nada lo diga**. Antes se contaban y salían en la respuesta — o sea, se
         * avisaba **después** de haberlas perdido.
         *
         * **Un `forzar: true` no serviría, y ésa es toda la decisión.** Un booleano no
         * caza la deriva: dice «adelante pase lo que pase», así que el día que se pierdan
         * treinta en vez de las dos que el coordinador vio en pantalla, pasa igual. Y
         * acaba puesto por costumbre, porque nunca estorba. Un número **tiene que
         * coincidir** con el que el servidor cuenta en ese mismo instante, así que sólo
         * lo puede acertar quien acaba de mirar; si la realidad se movió entre mirar y
         * confirmar, deja de coincidir y el cliente vuelve a mirar. Es la misma forma que
         * la pantalla de `myvc_horarios` ya tiene: un aviso que dice «se pierden 32» y un
         * botón que confirma «32» son verificables el uno contra el otro.
         *
         * Se valida a mano y no con `integer` de Laravel a propósito: la regla `integer`
         * acepta `"32"` **y** deja pasar formas que aquí no significan nada, y este
         * repositorio tiene herramienta propia para lo contrario
         * (`tools/verdad-laxa-que-escribe.py`) — una cadena cualquiera que vale por «sí»
         * y gobierna una escritura. `true` tiene que rebotar, no valer por 1.
         */
        $aceptoPerder = Request::input('acepto_perder');

        if ($aceptoPerder !== null && ! is_int($aceptoPerder)) {
            $this->rechazar([
                'message' => 'El campo `acepto_perder` es el NÚMERO de asignaciones que aceptas perder, no una bandera. Nada se escribió.',
                'motivo' => 'acepto-perder-no-es-un-numero',
                'acepto_perder_recibido' => $aceptoPerder,
            ]);
        }

        $derivacion = DB::transaction(function () use ($versionId, $yearId, $ahora, $aceptoPerder): array {
            /*
             * LA COMPROBACIÓN VA **DENTRO** DE LA TRANSACCIÓN, y no es cosmético.
             *
             * Contar fuera y escribir dentro son dos instantes: entre los dos alguien
             * puede borrar una asignatura más, y entonces se perdería una que nadie
             * contó y que el número del cliente sí cuadraba. Aquí la cuenta y el
             * `UPDATE` ven la misma foto.
             *
             * Y `abort()` desde dentro de `DB::transaction` **deshace**: lanza
             * `HttpException`, la transacción hace rollback y el «Nada se escribió» de
             * los dos mensajes es cierto y no una promesa.
             */
            $sePierden = $this->asignacionesFueraDelAlcance($versionId, $yearId);

            if ($aceptoPerder === null && $sePierden !== 0) {
                $this->rechazar([
                    // El mensaje nombra el número pero NO le dice al cliente que lo
                    // remande: lo levantó `myvc-horarios-5e` y tiene razón. Un «vuelve a
                    // llamar con acepto_perder: N» es una invitación a que el emisor
                    // reintente solo con el N que vino en el error — y eso **funciona**,
                    // y reconstruye el `forzar: true` en dos viajes sin que nadie lo
                    // note. La confirmación tiene que pasar por una persona; el número
                    // está aquí para que se lo puedan ENSEÑAR, no para reenviarlo.
                    'message' => "Publicar esta versión dejaría {$sePierden} asignacion(es) sin horario: estaban en la versión cuando se subió y ya no están en el año. Enséñale esas {$sePierden} a quien publica y confirma con la cifra que él diga. Nada se escribió.",
                    'motivo' => 'perdida-no-aceptada',
                    'asignaciones_que_se_pierden' => $sePierden,
                ]);
            }

            /*
             * **También rebota un número que sobra**, y ésa es la mitad que parece de
             * más: si el cliente declara 5 y el servidor cuenta 0, algo cambió entre
             * mirar y confirmar —o el número está puesto a mano en el código del
             * cliente—. Dejarlo pasar «porque no se pierde nada» es cómo `acepto_perder`
             * se convierte en el `forzar: true` que vino a evitar: una constante que
             * siempre está y nunca estorba.
             */
            if ($aceptoPerder !== null && $aceptoPerder !== $sePierden) {
                $this->rechazar([
                    // **NO manda a «releer el listado», y eso es un error corregido.** El
                    // mensaje decía eso hasta que `myvc-horarios-83` fue a escribir la
                    // relectura y descubrió que NO EXISTE: `getVersiones` no devuelve la
                    // deriva —su `comprobaciones` es el veredicto guardado el día de la
                    // subida, no una cuenta de hoy—, así que la única lectura fresca **es
                    // este mismo 422**. Mandar a una pantalla a buscar un número que allí
                    // no está es peor que no decir nada: se busca, no se encuentra, y se
                    // acaba tecleando el que se recuerde.
                    //
                    // Y eso reencuadra la puerta a mejor: la garantía no es «el número
                    // vino de otro sitio» —no hay otro sitio— sino **que hay una persona
                    // en medio cada vez**, porque no se puede saber la cifra sin provocar
                    // el 422 que la enseña.
                    'message' => "No coincide: aceptas perder {$aceptoPerder} y el servidor cuenta {$sePierden} en este momento. Esa cifra de {$sePierden} es la de ahora y no la da ninguna otra pantalla: enséñasela a quien publica y confirma con lo que él diga. Nada se escribió.",
                    'motivo' => 'acepto-perder-no-coincide',
                    'acepto_perder' => $aceptoPerder,
                    'asignaciones_que_se_pierden' => $sePierden,
                ]);
            }

            /*
             * EL ALCANCE, y es lo único que hay que leer de aquí: **el año entero
             * por JOIN**, no las filas de la versión y no un `WHERE year_id`.
             *
             * `asignaturas` **no tiene `year_id`** — el año le llega por
             * `grupos.year_id`—, así que un `WHERE` aquí no da error: acota por otra
             * cosa. Publicando un año cerrado, eso pondría a cero las columnas del
             * año abierto, y con la decisión 13 —subir y publicar valen en cualquier
             * año— no es teórico.
             */
            $alcance = 'FROM asignaturas a
                        INNER JOIN grupos g ON g.id = a.grupo_id AND g.year_id = ? AND g.deleted_at IS NULL
                        WHERE a.deleted_at IS NULL';

            $enElAlcance = (int) DB::select("SELECT count(*) AS c {$alcance}", [$yearId])[0]->c;

            /*
             * **Un solo `UPDATE` que escribe las siete columnas de todo el alcance**,
             * en vez de los dos pasos que pide la §7 —«todo a 0 y luego a 1 lo que
             * trae la versión»—.
             *
             * El resultado es el mismo y la propiedad que importa es más fuerte: con
             * dos pasos, «poner a 0» y «poner a 1» son dos sitios donde el alcance
             * puede dejar de ser el mismo, y el día que se separen la mitad de las
             * asignaciones se quedaría a cero sin que nada lo diga. Aquí **cada fila
             * del alcance recibe sus siete columnas escritas de nuevo, siempre**, y
             * lo que la versión no trae sale 0 porque el `EXISTS` es falso, no porque
             * haya un segundo `UPDATE` que se acordó de ella.
             *
             * `EXISTS` y no un `LEFT JOIN` a una derivada: un multi-tabla `UPDATE`
             * contra una tabla derivada no vale en MySQL 5.7, y **de los quince
             * colegios no está verificada la versión de ninguno** (ver la migración
             * `2026_09_04_100000_horario_versiones`). Esto es SQL de 5.7.
             *
             * Y **`dia` no se traduce**: el contrato es 0 = domingo … 6 = sábado
             * (§5.2.5), el mismo convenio con el que `asignaturas_dia()` las consume
             * por `Carbon::dayOfWeek`. Un mapeo aquí sería justo donde vive un
             * off-by-one, y el §5.2.5 lo dice: si el convenio se cambia, el horario
             * entero se corre un día **sin dar error y con el veredicto en verde**.
             *
             * `duracion` **no pinta nada en esta derivación** y eso no es un descuido:
             * un bloque de dos ocupa dos casillas *del mismo día*, así que la columna
             * del día es la misma la ocupe una casilla o siete. Donde `duracion` sí
             * manda es en Σ ≤ IH y en los choques, que son de la subida.
             *
             * ## Y `pieza_id` tampoco, que aquí es lo que salva a la misa
             *
             * La revalidación de la subida **sí** tiene que indexar por `pieza_id`,
             * porque una pieza de varios grupos ocupa una casilla y no seis. Aquí las
             * filas ya no vienen del cuerpo sino de `horario_lecciones`, donde esa misa
             * son **N filas con el mismo `pieza_id` y distinto `asignatura_id`** — y ése
             * es justo el sitio donde un `GROUP BY` por (día, franja) declararía a la
             * misa en choque consigo misma, o escribiría la misma casilla seis veces
             * creyéndolas clases distintas.
             *
             * **Esto es inmune por construcción, y conviene saber por qué**: `EXISTS`
             * contesta *sí o no*, no *cuántas*. Seis filas de la misma pieza ponen el
             * mismo día de cada una de sus seis asignaturas —que es exactamente lo que
             * tiene que pasar: las seis clases son ese día— y ninguna se cuenta dos
             * veces. Si algún día esto pasara a contar en vez de a comprobar, **ahí
             * vuelve a hacer falta el `pieza_id`**.
             */
            $marcar = [];

            foreach (self::COLUMNAS_DE_DIA as $dia => $columna) {
                $marcar[] = "a.{$columna} = EXISTS (SELECT 1 FROM horario_lecciones l
                             WHERE l.version_id = ? AND l.asignatura_id = a.id AND l.dia = {$dia})";
            }

            DB::update(
                'UPDATE asignaturas a
                 INNER JOIN grupos g ON g.id = a.grupo_id AND g.year_id = ? AND g.deleted_at IS NULL
                 SET '.implode(', ', $marcar).'
                 WHERE a.deleted_at IS NULL',
                array_merge([$yearId], array_fill(0, count(self::COLUMNAS_DE_DIA), $versionId))
            );

            DB::update(
                'UPDATE years SET horario_version_id = ?, updated_at = ? WHERE id = ?',
                [$versionId, $ahora, $yearId]
            );

            return [
                'asignaciones_en_el_alcance' => $enElAlcance,
            ] + $this->poblacionDeLaDerivacion($versionId, $yearId);
        });

        return response()->json([
            'id' => $versionId,
            'year_id' => $yearId,
            'nombre' => (string) $version[0]->nombre,
            'es_oficial' => true,
            'derivacion' => $derivacion,
        ]);
    }

    /**
     * Lo que la derivación acaba de escribir, **contado sobre las filas**.
     *
     * Es la regla de la §6 aplicada aquí: *«un veredicto sin población se lee como
     * “todo bien”»*. Un `200` pelado no distingue **derivé las 134** de **no había
     * ni una fila que derivar**, y las dos respuestas se ven idénticas desde el
     * cliente. La población sale de **esta** corrida, no del código: 134 y 344 son
     * cifras de `simonbolivar`, y escritas a mano dirían 134 en el colegio catorce
     * habiendo mirado 40.
     *
     * **`asignaciones_de_la_version_fuera_del_alcance` es la que hay que mirar, y no
     * es teórica.** La revalidación de la §6 comprueba que cada asignación es del año
     * de la versión **el día que se sube**, y publicar es otro momento y otra
     * decisión —«subir no publica»—: entre los dos, alguien puede borrar una
     * asignatura o mover su grupo. Esas filas **no entran en el alcance**, así que su
     * día no se escribe y el horario pierde esas clases **en silencio**.
     *
     * **Ese «en silencio» ya no es cierto, y este párrafo decía lo contrario hasta el
     * 2 sep 2026.** Decía que convertirlas en 422 sería impedir publicar por algo que
     * pasó después de validar y que **eso lo decidía el colegio** — y el colegio lo
     * decidió: Joseth aprobó `acepto_perder`, así que hoy la deriva **cierra la puerta**
     * arriba, en `putOficial`, y sólo se pasa declarando el número exacto. Aquí se
     * siguen contando porque la respuesta necesita su población, pero **ya no son la
     * única defensa**: se enteraba uno después de haber perdido las clases.
     *
     * Se reescribe en vez de dejarse: un comentario que razona hacia la conclusión
     * contraria a la del código de al lado es peor que ninguno — se lee entero, es
     * convincente, y manda a quien lo lea a «arreglar» la puerta que sí funciona.
     *
     * @return array<string, int|array<string, int>>
     */
    private function poblacionDeLaDerivacion(int $versionId, int $yearId): array
    {
        $porDia = [];

        foreach (self::COLUMNAS_DE_DIA as $columna) {
            $porDia[$columna] = (int) DB::select(
                "SELECT count(*) AS c
                 FROM asignaturas a
                 INNER JOIN grupos g ON g.id = a.grupo_id AND g.year_id = ? AND g.deleted_at IS NULL
                 WHERE a.deleted_at IS NULL AND a.{$columna} = 1",
                [$yearId]
            )[0]->c;
        }

        $conAlgunDia = 'a.'.implode(' = 1 OR a.', self::COLUMNAS_DE_DIA).' = 1';

        return [
            'asignaciones_con_algun_dia' => (int) DB::select(
                "SELECT count(*) AS c
                 FROM asignaturas a
                 INNER JOIN grupos g ON g.id = a.grupo_id AND g.year_id = ? AND g.deleted_at IS NULL
                 WHERE a.deleted_at IS NULL AND ({$conAlgunDia})",
                [$yearId]
            )[0]->c,
            /*
             * **Filas y piezas, las dos, porque no son el mismo número.** Una misa de
             * seis grupos es **una pieza y seis filas**, así que «6 filas» de una
             * versión con una sola pieza y «6 filas» de seis clases sueltas se leen
             * igual y no son lo mismo. Con las dos cifras al lado, la diferencia se ve;
             * con una sola, quien lea la respuesta cuenta clases que no existen.
             */
            'filas_de_la_version' => (int) DB::select(
                'SELECT count(*) AS c FROM horario_lecciones WHERE version_id = ?',
                [$versionId]
            )[0]->c,
            'piezas_de_la_version' => (int) DB::select(
                'SELECT count(DISTINCT pieza_id) AS c FROM horario_lecciones WHERE version_id = ?',
                [$versionId]
            )[0]->c,
            // La MISMA llamada que la puerta de `acepto_perder`, y no una segunda
            // copia de la consulta: el número que el cliente tuvo que acertar y el
            // que sale en la respuesta **tienen que ser el mismo hecho**. Dos copias
            // del SQL son dos sitios donde el alcance puede dejar de coincidir, y
            // entonces la puerta cerraría por un número y la respuesta informaría de
            // otro sin que nada lo dijera.
            'asignaciones_de_la_version_fuera_del_alcance' => $this->asignacionesFueraDelAlcance($versionId, $yearId),
            'por_dia' => $porDia,
        ];
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
    /**
     * Cuántas asignaciones de la versión **ya no están en el año**.
     *
     * Son las que se caen del alcance de la derivación: estaban cuando la versión se
     * subió y se comprobaron entonces (§6), y entre subir y publicar alguien borró la
     * asignatura o movió su grupo. Su día no se escribe, así que **el horario pierde
     * esas clases**.
     *
     * `count(DISTINCT l.asignatura_id)` y no `count(*)`: una misa de seis grupos son
     * seis filas de la misma pieza, y lo que se pierde son **asignaciones**, no filas.
     * Con `count(*)` el número que el coordinador tiene que confirmar sería mayor que
     * el de clases que realmente desaparecen, y un número que no se puede comprobar
     * contra la pantalla es justo lo que `acepto_perder` no puede permitirse.
     */
    private function asignacionesFueraDelAlcance(int $versionId, int $yearId): int
    {
        return (int) DB::select(
            'SELECT count(DISTINCT l.asignatura_id) AS c
               FROM horario_lecciones l
              WHERE l.version_id = ?
                AND l.asignatura_id NOT IN (
                    SELECT a.id FROM asignaturas a
                    INNER JOIN grupos g ON g.id = a.grupo_id AND g.year_id = ? AND g.deleted_at IS NULL
                    WHERE a.deleted_at IS NULL)',
            [$versionId, $yearId]
        )[0]->c;
    }

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

    /**
     * El color de un docente. **La única escritura de `profesores.tono` que existe.**
     *
     * ## Por qué es una ruta y no una entrada en la ficha
     *
     * El front costeó meterla en la lista blanca `$deLaFicha` de
     * `ProfesoresController::putUpdate`, que no habría movido el router. **No vale, y no
     * por trabajo sino por permiso**: esa ruta exige `Autoriza::esSuperusuario` dentro del
     * método, así que por ahí el color lo elegirían **once personas en toda la red y ningún
     * coordinador**. Joseth decidió el 4 sep 2026 que lo elijan **también los
     * coordinadores**, y ese criterio ya tiene nombre aquí: `puedePublicarHorario`
     * —superusuario **o** `Coord académico`—, el mismo con el que se marca la versión
     * oficial. *La salida barata no era la misma decisión con menos trabajo: era otra
     * decisión.*
     *
     * **Y no se le cambia el criterio a `putUpdate` para conseguirlo**: esa ruta edita la
     * ficha entera de un docente —diecisiete campos, documento y domicilio incluidos— y
     * abrirla para que quepa un color es ensancharla para todo lo demás.
     *
     * ## La validación no es cosmética: sin ella el fallo es SEGURO Y MUDO
     *
     * Medido por `myvc_front` en `comunes/tono-docente/tono-docente.ts:353`: el cliente
     * acepta `#rgb` y `#rrggbb` y **rechaza los nombres de CSS y `rgb(...)`**, porque de
     * ésos no se puede sacar la luminancia sin un navegador delante. Y cuando rechaza,
     * `marcaDeDocente` **se cae al color automático**.
     *
     * O sea que un `rebeccapurple` guardado sin comprobar **se da por guardado, no se pinta
     * nunca y nadie se entera**: el filtro del cliente sólo sabe *no pintar*, no sabe
     * *avisar*. El 422 de aquí es lo único que convierte «no se ve» en «no se pudo
     * guardar».
     *
     * ## El nulo es el BORRADO, y no es un caso excepcional
     *
     * Devuelve al docente a su color automático. `tono` **nace nulo en los diecisiete**, así
     * que el nulo no es un caso raro: es **el estado de partida de todos**, y una ruta que
     * no supiera volver a él dejaría a un colegio sin marcha atrás desde el primer color
     * que pusiera. Se manda `tono: null` (o cadena vacía, que aquí cuenta como nulo).
     *
     * **La clave ausente NO es un borrado.** Un cuerpo sin `tono` es un cuerpo mal formado
     * y sale 422: si valiera por «borra», cualquier petición a medias apagaría un color sin
     * que nadie lo pidiera. Es la distinción que `CamposQueVinieron` existe para hacer.
     */
    public function putTonoDocente($profesorId): JsonResponse
    {
        Autoriza::exigir(Autoriza::puedePublicarHorario($this->user),
            'No tienes permiso para cambiar el color de un docente.');

        $id = (int) $profesorId;

        /*
         * El docente se comprueba contra `profesores`, no contra los que tienen
         * asignación. Un docente sin clases este año **sigue siendo un docente** y su
         * color puede repartirse por adelantado; atarlo al año lo convertiría en un 404
         * que cambia solo en enero.
         */
        $existe = DB::select('SELECT p.id FROM profesores p WHERE p.id = ? AND p.deleted_at IS NULL', [$id]);

        if ($existe === []) {
            abort(404, 'Ese docente no existe.');
        }

        $vinieron = CamposQueVinieron::capturar();

        if (! $vinieron->trae('tono')) {
            $this->rechazar([
                'message' => 'Falta el campo `tono`. Para borrar el color, mándalo con valor nulo.',
                'campo' => 'tono',
                'motivo' => 'ausente',
            ]);
        }

        $tono = $this->tonoNormalizado(Request::input('tono'));

        DB::update('UPDATE profesores SET tono = ? WHERE id = ?', [$tono, $id]);

        return response()->json([
            'profesor_id' => $id,
            'tono' => $tono,
        ]);
    }

    /**
     * `#rrggbb` en minúsculas, o `null` si es un borrado. Cualquier otra cosa, 422.
     *
     * **Se normaliza al guardar y no al leer**, y eso es lo que hace que la comparación
     * del cliente funcione: `tono-docente.ts` acepta las cuatro formas de escribir el
     * mismo color —con `#` o sin él, en mayúsculas o minúsculas, de tres dígitos o de
     * seis— y si la base guardara las cuatro, dos docentes del mismo color se leerían
     * como distintos en cualquier comparación de cadenas.
     *
     * El `#rgb` se expande a `#rrggbb` duplicando cada dígito, que es lo que hace el
     * navegador: `#0af` es exactamente `#00aaff` y guardarlo corto sólo deja dos
     * representaciones del mismo color.
     */
    private function tonoNormalizado(mixed $crudo): ?string
    {
        if ($crudo === null) {
            return null;
        }

        if (! is_string($crudo)) {
            $this->rechazarTono($crudo, 'no es una cadena');
        }

        $t = strtolower(trim($crudo));

        // La cadena vacía cuenta como nulo: un `<input>` vaciado a mano manda `''`, y
        // exigirle al cliente que distinga `''` de `null` es pedirle que acierte en algo
        // que su propio formulario no distingue.
        if ($t === '') {
            return null;
        }

        if (str_starts_with($t, '#')) {
            $t = substr($t, 1);
        }

        if (preg_match('/^[0-9a-f]{3}$/', $t) === 1) {
            $t = $t[0].$t[0].$t[1].$t[1].$t[2].$t[2];
        }

        if (preg_match('/^[0-9a-f]{6}$/', $t) !== 1) {
            $this->rechazarTono($crudo, 'sólo se aceptan `#rgb` y `#rrggbb`; los nombres de CSS y `rgb(...)` no');
        }

        return '#'.$t;
    }

    /**
     * El 422 del color, con el valor que llegó **dentro del cuerpo**.
     *
     * Devolverlo es lo que deja a la pantalla decir *«`rebeccapurple` no vale»* en vez de
     * *«no vale»*, y es barato: el cliente ya lo tenía, pero no necesariamente en el sitio
     * donde pinta el error.
     */
    private function rechazarTono(mixed $crudo, string $motivo): never
    {
        $this->rechazar([
            'message' => 'Ese color no vale. '.$motivo,
            'campo' => 'tono',
            'recibido' => is_scalar($crudo) ? (string) $crudo : gettype($crudo),
            'motivo' => $motivo,
        ]);
    }
}
