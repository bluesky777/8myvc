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
     * El tope del blob del proyecto, **en BYTES**: lo que cabe en un `MEDIUMTEXT`.
     *
     * Decisión 5 de la §10.2, contestada por Joseth el 6 sep 2026: **poner el límite y
     * devolver 422** en vez de guardar a medias. Lo que lo decidió no fue el tamaño —el
     * `.myvch` más grande que existe mide 128.779 b, o sea **130 veces menos**— sino que
     * **el docker y producción fallaban distinto**: aquí `sql_mode` no es estricto y MySQL
     * **truncaba en silencio contestando `201`**, y en los dieciséis, con
     * `STRICT_TRANS_TABLES` de serie en MariaDB 10.5, el mismo caso **aborta con un 1406 y
     * sale un 500**. Ninguno de los dos decía qué había pasado. Con el rechazo delante los
     * diecisiete fallan igual y lo dicen (§9.ter.3).
     *
     * ## Por qué NO es una regla `max:16777215` de Laravel, que es lo que parecía
     *
     * **Porque `max` cuenta CARACTERES y esta columna cuenta BYTES.** `getSize()` de
     * `ValidatesAttributes` resuelve a `mb_strlen`, comprobado el 6 sep 2026 contra este
     * árbol: `str_repeat('ñ', 10)` son **10 caracteres y 20 bytes**, y pasa un `max:15`.
     * O sea que con la regla puesta un proyecto de acentos —que es **todos**: los nombres
     * de los colegios llevan `Ó`, y el blob admite emoji de 4 bytes— **pasaría la
     * validación y lo truncaría MySQL igual**, que es exactamente el fallo que esta
     * decisión viene a cerrar. *La salida que parecía barata no era la misma decisión con
     * menos trabajo: era no tomarla.*
     *
     * Así que el tope se comprueba con `strlen()` y a mano, y por eso vive aquí y no dentro
     * de la lista de reglas.
     *
     * ## Esta constante es el TECHO, y el que se aplica sale de `config/horario.php`
     *
     * El valor efectivo se lee de la configuración y **se recorta contra ésta**
     * (`maximoDelProyecto()`), así que bajarlo vale y subirlo no hace nada. Lo segundo es
     * deliberado: un tope configurado por encima del de la columna devolvería justo el fallo
     * que la decisión 5 cerró —MySQL truncando en silencio detrás de un `201`—, y **una
     * configuración no puede reabrir una decisión**.
     */
    private const TOPE_DE_LA_COLUMNA = 16777215;

    /**
     * La forma de un `pieza_id`: la que la columna `horario_lecciones.pieza_id` ya acepta.
     *
     * Es **el único texto del fichero de proyecto que sale por `getLecciones`**, y sale
     * acotado a un identificador de hasta 64 caracteres de esta clase — la misma puerta
     * por la que ya viajan los `pieza_id` de las lecciones, así que no se abre una de
     * otra clase. Medido el 5 sep 2026 sobre la versión 8: 300 son `a1324-2` y uno es
     * `misa-religion`.
     */
    private const FORMA_DEL_ID_DE_PIEZA = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/';

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
            $cuerpo = Request::validate([
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

        /*
         * El tope del blob (decisión 5), y **con `motivo` propio y no `cuerpo-mal-formado`**.
         *
         * Aquí el cuerpo está perfectamente bien formado: es del tamaño que no cabe. Meterlo
         * en el rechazo de forma le diría al escritorio que revise su JSON —que está bien— y
         * le escondería lo único que puede hacer, que es mirar su fichero. Los seis rechazos
         * de dominio de esta familia ya traen su `motivo` y su población dentro; éste es el
         * séptimo y sigue la misma forma.
         *
         * **`strlen` y no `mb_strlen`**, por lo que dice `TOPE_DE_LA_COLUMNA`: lo que cuenta
         * la columna son bytes. Y va **antes de la transacción y antes de tocar `years`**,
         * porque un rechazo por tamaño no necesita saber nada del año.
         */
        $bytes = strlen((string) $cuerpo['proyecto']);
        $maximo = $this->maximoDelProyecto();

        if ($bytes > $maximo) {
            $this->rechazar([
                'message' => "El fichero de proyecto mide {$bytes} bytes y el máximo son {$maximo} bytes. "
                    .'No se sube: por encima de ese tope la base lo guardaría cortado y la respuesta diría que todo fue bien, '
                    .'así que se rechaza entero. Nada se escribió.',
                'motivo' => 'proyecto-demasiado-grande',
                'bytes' => $bytes,
                'maximo' => $maximo,
            ]);
        }

        return $cuerpo;
    }

    /**
     * El tope que se aplica de verdad: el de la configuración, **recortado por el de la
     * columna**.
     *
     * `min()` y no el valor tal cual, y no es defensa por defender: subir
     * `horario.maximo_del_proyecto` por encima de lo que aguanta un `MEDIUMTEXT` no ampliaría
     * nada — lo ampliaría hasta el punto en que **MySQL vuelve a truncar en silencio detrás de
     * un `201`**, que es exactamente el fallo que cerró la decisión 5. Una configuración puede
     * apretar este tope; **no puede reabrir la decisión**.
     *
     * El `max(1, …)` es para el otro extremo: un `0` o un negativo mal puestos dejarían la
     * ruta rechazando **todas** las subidas, y con `1` lo peor que pasa es que se rechacen
     * todas menos las de un byte — igual de roto, pero **se ve al primer intento** en vez de
     * parecer que la ruta está caída.
     */
    protected function maximoDelProyecto(): int
    {
        $configurado = (int) config('horario.maximo_del_proyecto', self::TOPE_DE_LA_COLUMNA);

        return max(1, min($configurado, self::TOPE_DE_LA_COLUMNA));
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
                // **Reescrito el 5 sep 2026: decía «no en este servidor» y era falso.**
                // Medido sobre el proyecto de la versión oficial: **los 47 docentes traen
                // la clave `disponibilidad`**, 26 con marcas de verdad —134 en total, 92
                // `condicional` y 42 `inadecuado`— y eso vive en `horario_versiones.proyecto`,
                // **que es una columna de esta base**.
                //
                // **Y esta cadena tiene un lector HOY, que es lo que la separa de las otras.**
                // `escritorio/src/app/subir/veredicto.ts:212` la imprime **verbatim** —no tiene
                // copia propia del texto—, así que la frase falsa se la está enseñando a un
                // coordinador de colegio cada vez que alguien sube un horario. Se arregla en su
                // pantalla en cuanto cambia aquí, sin que ellos toquen nada.
                //
                // **El daño ya salió de este repositorio**: `myvc-front-90` escribió y
                // **commiteó** en su catálogo que las disponibilidades «no se guardan en el
                // servidor», y con eso clasificó su informe como **no derivable**. Lo corrigió
                // abriendo el blob, y la categoría pasó a **«falta ruta»** — un cartel
                // completamente distinto. *No fue un error de lectura suyo: se creyó un cartel
                // nuestro.*
                //
                // **Y las dos frases piden trabajos distintos, que es por lo que no es
                // cosmético:** «no la guarda» pide **inventar un dato**; «la guarda y no la sabe
                // consultar» pide **parsear el blob**. La frase de antes empujaba al primero.
                'disponibilidad' => 'NO COMPROBADA: las disponibilidades declaradas SÍ están guardadas —dentro del fichero de proyecto de esta misma subida, en `horario_versiones.proyecto`— pero esta comprobación no las lee. No es que falte el dato: falta la comprobación.',
                // **Reescrito el 5 sep 2026, y el anterior era falso por las DOS mitades.**
                // Decía que la rejilla y los timbres «viven en el fichero de proyecto, así
                // que aquí no se sabe si la franja cae dentro de la jornada del nivel ni si
                // cruza un descanso». Medido sobre las siete versiones de `simonbolivar`:
                // el fichero que se acaba de subir trae `proyecto.jornadaPorDefecto` **y**
                // `proyecto.niveles[].jornada`, las dos con `dias`, `franjas`, `timbres` y
                // `descansosTras`. O sea que **el dato está aquí, en el cuerpo de esta misma
                // petición**, y desde el 4 sep esta API ya sabe leerlo.
                //
                // Sigue sin comprobarse, y ése es el punto: **lo que cambia es el porqué**.
                // «No puedo» y «no lo hago» se leen igual en un veredicto y sólo el segundo
                // se puede resolver — el primero hace que nadie vuelva a preguntar, que es
                // exactamente lo que le pasó a los descansos, que llevaban dos días
                // legibles y nadie los pidió porque el renglón de al lado decía que vivían
                // en otro sitio.
                //
                // **Y lo que falta ahora es una decisión, no un dato**: si una franja fuera
                // de la jornada de su nivel debe frenar la subida (422) o sólo avisar. Este
                // texto lo dice para que la próxima persona sepa qué preguntar.
                //
                // Cambia lo que registran las subidas FUTURAS; las siete que ya existen
                // conservan la frase con la que se guardaron, que es para lo que existe el
                // veredicto guardado. Autorizado por Joseth el 5 sep 2026.
                'jornada' => 'NO COMPROBADA, y no por falta de dato: el fichero que se acaba de subir trae la jornada por defecto y la de cada nivel —días, franjas, timbres y descansos—, y esta API sabe leerla. Lo que falta es la comprobación, y antes que ella la decisión de si una franja fuera de la jornada de su nivel frena la subida o sólo avisa.',
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
     * Por eso cada catálogo viaja con su estado y su población, y **son cinco estados
     * y no dos**:
     *
     *   - `completo`     lo guardamos y está todo.
     *   - `parcial`      lo guardamos y hay menos de lo que la versión usa.
     *   - `vacio`        lo guardamos, el colegio no creó ninguno, **y es legítimo**.
     *   - `sin_catalogo` **esta API no puede saberlo**, hoy ni por este camino.
     *   - `ilegible`     **lo tenemos guardado y no se deja leer** — y tiene arreglo.
     *
     * ## El quinto entró el 4 sep 2026, y es el único que acusa a una subida concreta
     *
     * Decisión de Joseth. Nació de que `catalogos.timbres` decía *«viven en el fichero de
     * proyecto»* — verdad que engaña, porque el fichero está en la columna de al lado— y de
     * que al arreglar esa frase salió que **no había palabra para «lo tenemos y está roto»**.
     *
     * **`sin_catalogo` no servía, y meterlo ahí habría sido el mismo fallo con otro traje.**
     * `sin_catalogo` afirma *«esta API no puede saberlo **por diseño**»*: es una frase sobre
     * el **producto**, idéntica en los dieciséis colegios y que no arregla nadie desde la
     * web. «El proyecto de este colegio no se deja leer» es sobre **ese colegio y esa
     * subida**, y **tiene arreglo: volver a subirlo**. Juntarlas es `[]` contra `null` otra
     * vez — y con la agravante de que tres renglones ya usan `sin_catalogo` con el primer
     * significado, así que el cuarto lo habría usado con otro sin avisar.
     *
     * **Y de los cinco es el único que cambia lo que la persona debería hacer.** Los otros
     * cuatro explícitamente no llevan llamada a la acción: `vacio` es legítimo y
     * `sin_catalogo` no lo arregla nadie. *Ése es el argumento de que sea un estado y no un
     * matiz dentro de otro.*
     *
     * **Y lo que decidió la forma frente a un campo `porque` dentro de `sin_catalogo` no fue
     * la limpieza: fue quién queda obligado.** Medido por `myvc-front-b2` en su repositorio:
     * sus cuatro `Record<EstadoDeCatalogo, …>` —palabra, icono, color y frase— **dejan de
     * compilar** con un valor más en la unión, así que el compilador le exige nombrar el
     * caso nuevo; con un campo al lado **no se rompe nada** y su panel pintaría, ante un
     * proyecto ilegible, la palabra que hoy tiene para `sin_catalogo`: ***«No lo guarda el
     * servidor»***, que es lo contrario de la verdad. `tsc` verde, pruebas verdes y la
     * pantalla mintiendo. *Con el estado obliga el compilador; con el campo se obliga el
     * cliente a sí mismo.*
     *
     * **Hoy tiene cero ejemplos** —las siete versiones de `simonbolivar` parsean— así que
     * vive atado por tests y **no se ha visto nunca con datos reales**. Se dice porque unas
     * pruebas verdes se leen dentro de seis meses como «esto se ha visto funcionar».
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
     *
     * ## Las cuatro listas de la decisión 38 — escritas el 5 sep 2026
     *
     * Joseth aprobó en `myvc_horarios` que esta ruta mande además, **todo sacado del
     * fichero de proyecto que ya lee**: la **plantilla entera** de docentes (47 en el
     * colegio medido, cuando por las lecciones sólo viajaban 12 y los otros 35 eran
     * justo los que el informe «quién está libre» existe para nombrar), las **jornadas
     * por nivel** con de qué nivel cuelga cada grupo **y su `porque`**, las
     * **disponibilidades declaradas** con quién declaró cada marca, y las **piezas sin
     * colocar**, que están guardadas y son identificables aunque `horario_lecciones` no
     * las tenga. Van como cuatro claves más del sobre, cada una con su renglón en
     * `catalogos` y **`null` cuando no se entiende entera**.
     *
     * **El permiso no cambia y eso es un precio, no un detalle**: `auth.personal` se
     * justificó aquí porque el horario ya se imprime y se cuelga; las disponibilidades
     * declaradas no están en ninguna pared, y desde este día cualquiera de los docentes
     * puede leer las horas que sus compañeros marcaron `inadecuado`. Está escrito dentro
     * de la decisión 38 como precio pagado, y se repite aquí porque el argumento que
     * sostiene el guard ya no cubre todo lo que la ruta transporta.
     *
     * **Y la guarda de forma de `descansosDelProyecto()` pasa a ser la de todas**: cada
     * lista es mucho más blob que un par de enteros, así que cada valor se comprueba
     * campo a campo y ninguna cadena libre del fichero sale por aquí (los nombres se
     * resuelven por id contra las tablas; el único texto es el `pieza_id`, acotado a la
     * forma de su columna). El test de fuga tiene un caso con la marca **dentro** de
     * cada lista, porque el que vigila `programa` no vería ninguna de las cuatro.
     */
    public function getLecciones($id): JsonResponse
    {
        $versionId = (int) $id;
        $yearId = (int) $this->user->year_id;

        // **No es un 403: decide cuánto dice la respuesta, no si se da.** Quien puede
        // publicar el horario ve de quién es cada pega de disponibilidad; el resto del
        // personal ve que la pega existe y no de quién (decisión 3, 6 sep 2026). Se
        // resuelve una sola vez aquí porque lo miran dos sitios —la lista y su renglón de
        // `catalogos`— y **tienen que decir lo mismo**: leerlo dos veces es cómo una
        // respuesta acaba tachando el autor y declarando `autor: visible`.
        $puedePublicar = Autoriza::puedePublicarHorario($this->user);

        // El año sale del TOKEN, igual que en `getVersiones` y por lo mismo: un
        // `year_id` por parámetro sería un identificador que llega de fuera y no
        // comprueba nadie. Y va en el `WHERE` junto al id, no en un `if` después: así
        // «no existe» y «no es de tu año» son la misma respuesta y no hay forma de
        // averiguar qué versiones tienen los otros años preguntando por ellas.
        //
        // ── Y `hv.proyecto` VIAJA A PHP, que es lo único caro de esta consulta.
        //
        // Se trae el blob entero —129.550 bytes en el más grande de los siete reales—
        // para sacarle **un** dato: los descansos de la jornada. Medido el 4 sep 2026
        // sobre la versión 7 de `simonbolivar`, 200 repeticiones:
        //
        //     sin el blob ......................... 0,78 ms
        //     + traerlo + `json_decode` ........... 2,86 ms   (+2,07 ms)
        //     `JSON_EXTRACT` dentro del SELECT .... 5,42 ms   (+4,64 ms)
        //
        // **La opción que parecía la barata es la cara, y por eso está escrito**: la
        // columna es `mediumtext`, **no `json`**, así que MySQL no tiene un documento
        // ya parseado que indexar — reconstruye el JSON entero en cada llamada, y lo
        // hace más despacio que PHP. Un `JSON_EXTRACT` sobre una columna de texto no
        // es una lectura barata: es un `json_decode` en el otro lado del cable.
        //
        // Los +2,07 ms van sobre una ruta que tarda **21,9 ms** y devuelve 114 KB
        // (312 lecciones, medido igual): un **+9%**. Se paga.
        $version = DB::select(
            'SELECT hv.id, hv.year_id, hv.nombre, hv.created_at, hv.comprobaciones, hv.proyecto,
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

        // **Una sola vez por petición**, y de aquí salen dos cosas: los descansos de la
        // jornada y si los tres catálogos que viven dentro del blob son `sin_catalogo`
        // —no están en esta API por diseño— o `ilegible` —están aquí y no se dejan leer—.
        $proyecto = $this->proyectoDeLaVersion($version[0]->proyecto);

        // ── LAS CUATRO LISTAS DE LA DECISIÓN 38 (`myvc_horarios`), del MISMO blob y UNA sola vez.
        //
        // La plantilla entera de docentes, las jornadas por nivel, las disponibilidades
        // declaradas y las piezas sin colocar viven en el fichero de proyecto que la
        // consulta de arriba ya trae: el `json_decode` está pagado (+2,07 ms, medido) y
        // lo que se añade es el sobre. **Cada lista pasa por su comprobación de forma
        // ANTES de resolverse contra las tablas**, y por el mismo motivo que
        // `descansosDelProyecto()`: el blob lo escribe un programa de escritorio, y un
        // valor devuelto tal cual es una puerta de salida del fichero con nombre de dato,
        // en la ruta cuyo contrato es que *mirar no es llevarse* (decisión 12, §9.bis).
        // **De aquí no sale ni una cadena libre del blob**: los nombres de docentes,
        // niveles, materias y grupos se resuelven por id contra las tablas de esta base,
        // y lo único de texto que viaja es el `pieza_id`, acotado a la forma que la
        // columna ya acepta (`FORMA_DEL_ID_DE_PIEZA`).
        //
        // Cada una es `null` cuando no se entiende ENTERA —una entrada rota tira la lista,
        // no se filtra a medias— y su renglón de `catalogos` lo dice como `ilegible`.
        $leidaPlantilla = $this->plantillaDelProyecto($proyecto);
        $leidaDisponibilidad = $this->disponibilidadDelProyecto($proyecto);
        $leidasJornadas = $this->jornadasDelProyecto($proyecto);
        $leidoSinColocar = $this->sinColocarDelProyecto($proyecto);

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

        // Quién tiene lección en ESTA versión lo dice `horario_pieza_docente`, no el blob:
        // es la tabla la que sabe qué quedó colocado. Con esto la plantilla puede decir
        // `con_leccion` fila a fila, que es lo que el informe «quién está libre» necesita
        // para contar a los que ese día no vinieron.
        $conLeccion = [];
        foreach ($docentesPorPieza as $docentesDeUnaPieza) {
            foreach ($docentesDeUnaPieza as $d) {
                $conLeccion[$d['id']] = true;
            }
        }

        // Una consulta para todas las fichas que faltan —plantilla y piezas sin colocar—;
        // las de las lecciones ya vinieron con `docentesDeLaVersion`.
        $fichas = $this->fichasDeDocentes(array_merge(
            $leidaPlantilla ?? [],
            $leidoSinColocar === null ? [] : array_merge([], ...array_map(fn ($p) => $p['docente_ids'], $leidoSinColocar['piezas'])),
        ));

        $plantilla = $leidaPlantilla === null ? null : $this->plantillaResuelta($leidaPlantilla, $fichas, $conLeccion);
        $jornadas = $leidasJornadas === null ? null : $this->jornadasResueltas($leidasJornadas);
        $sinColocar = $leidoSinColocar === null ? null : $this->piezasSinColocarResueltas($leidoSinColocar['piezas'], $fichas);

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
            'ejes' => $this->ejesDeLaVersion($lecciones, $this->descansosDelProyecto($proyecto)),
            'catalogos' => $this->catalogosDeLaVersion($yearId, $lecciones, [
                'legible' => $proyecto !== null,
                'plantilla' => $plantilla,
                'disponibilidad' => $leidaDisponibilidad,
                'jornadas' => $jornadas,
                'sin_colocar' => $sinColocar,
                'cuentas_sin_colocar' => $leidoSinColocar,
            ], $puedePublicar),
            'lecciones' => $lecciones,
            // La población, delante y siempre. Sin ella `lecciones: []` se lee como
            // «todo bien» — que es literalmente el fallo de la §2, el que estuvo meses
            // sin que nadie lo reportara.
            'total_lecciones' => count($lecciones),
            // ── Las cuatro de la decisión 38. `null` = no se pudo leer entera, y el
            // renglón de `catalogos` del mismo nombre dice por qué. **Van detrás de
            // `catalogos` y nunca sin su renglón** (regla 1 de la §9.bis.3).
            //
            // La plantilla ENTERA: 47 en el colegio medido, cuando por las lecciones sólo
            // viajaban 12. Los otros 35 son exactamente los que el informe «quién está
            // libre» existe para nombrar, y sin ellos su cuenta
            // `enHueco + ocupados + sinClases === docentes` no se puede cuadrar — y **una
            // cuenta que cuadra sobre la población equivocada no falla, y por eso no se
            // investiga**.
            'plantilla' => $plantilla,
            // Por NIVEL y no por grupo, que es como lo modela el escritorio: cuatro
            // jornadas y no trece, más de qué nivel cuelga cada grupo **con su `porque`**.
            // Sin el `porque`, un grupo pintado con la jornada por defecto no se distingue
            // de uno que la declaró: en pantalla se aclara con un aviso; en papel no hay
            // dónde ponerlo después.
            'jornadas' => $jornadas,
            // Una entrada por docente de la plantilla, con quién declaró cada marca: una
            // hoja que dice «a alguien le viene mal» sin decir a quién se reparte más
            // fácil y se rebate peor. `marcas: []` es «sin pegas», porque el escritorio
            // sólo guarda lo que no es `adecuado`.
            'disponibilidad' => $this->disponibilidadQueViaja($leidaDisponibilidad, $puedePublicar),
            // Las que el fichero tiene y ninguna casilla recibió. No están en
            // `horario_lecciones` —que sólo guarda las colocadas— y eso no es lo mismo que
            // que el dato no esté.
            'sin_colocar' => $sinColocar,
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
     * ## `descansos_tras` es la excepción, y hereda la regla entera
     *
     * Es lo único de la jornada que el proyecto sí trae y que aquí se puede leer, así
     * que viaja — pero **con los mismos tres estados que el resto de esta ruta**:
     * `[3,5]` es dónde van las líneas gruesas, `[]` es **el colegio no descansa** —que
     * es un dato— y `null` es **no lo sabemos**. Aplastar los dos últimos a `[]`
     * convertiría «no lo sé» en «no hay recreo» y el front pintaría una parrilla
     * corrida con toda la confianza del mundo.
     *
     * @param  list<array<string, mixed>>  $lecciones
     * @param  list<int>|null  $descansos
     * @return array<string, mixed>
     */
    protected function ejesDeLaVersion(array $lecciones, ?array $descansos = null): array
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
            // el centinela del escritorio justo en el caso para el que existe. Lo que el
            // proyecto DECLARA viaja aparte, en `jornadas`, con el `porque` de cada grupo
            // al lado — desde el 5 sep 2026—; esto son los ejes de lo colocado.
            'timbres' => null,
            // Dónde van las líneas gruesas del recreo. **Base 1 y «tras»**: un `3`
            // significa *después de la tercera lección*, no *en la tercera*.
            //
            // **No se recorta contra `franjas`** aunque se pudiera: `franjas` son las
            // que ESTA versión usa y el proyecto declara la jornada del colegio, así
            // que un descanso tras la 7 en una versión que sólo llega a la 5 es
            // legítimo — el colegio descansa ahí aunque esta versión no lo alcance.
            // Filtrarlo escondería el dato en vez de comprobarlo.
            'descansos_tras' => $descansos,
        ];
    }

    /**
     * El fichero de proyecto **decodificado**, o `null` si no se pudo leer.
     *
     * Se decodifica **una sola vez por petición** y el resultado se reparte: de aquí
     * salen los descansos (`ejes.descansos_tras`) y el estado `ilegible` de los tres
     * catálogos que viven dentro del blob. Decodificarlo dos veces costaría otros
     * 2 ms medidos, y peor: **las dos lecturas podrían discrepar**, que es la forma de
     * la que salen dos verdades sobre el mismo hecho.
     *
     * **`null` significa «este fichero no se puede leer como proyecto»**, y es más ancho
     * que «el JSON está corrupto»: también lo es un JSON válido que no trae un objeto
     * `proyecto` dentro. Las dos cosas significan lo mismo para quien lo subió —*lo que
     * mandaron no se lee como un proyecto*— y **no se separan a propósito**: `myvc-front-b2`
     * confirmó el 4 sep 2026 que no tiene nada distinto que pintar con ellas, y un cuarto
     * caso que nadie distingue es el error del que viene todo este módulo.
     *
     * @return array<string, mixed>|null
     */
    protected function proyectoDeLaVersion(?string $blob): ?array
    {
        if ($blob === null || $blob === '') {
            return null;
        }

        $leido = json_decode($blob, true);

        if (! is_array($leido) || ! isset($leido['proyecto']) || ! is_array($leido['proyecto'])) {
            return null;
        }

        return $leido['proyecto'];
    }

    /**
     * Los descansos que declara el fichero de proyecto, **con sus tres estados**.
     *
     * `proyecto.jornadaPorDefecto.descansosTras` dentro del blob —el camino es real y
     * está medido: los **siete** `horario_versiones` de `simonbolivar` traen
     * `{"dias":[1,2,3,4,5],"franjas":7,"timbres":null,"descansosTras":[3,5]}`—. Es lo
     * único de la jornada que este servidor puede contestar sin inventarse nada, y por
     * eso es lo único que sale.
     *
     * | lo que dice el proyecto | lo que devuelve |
     * |---|---|
     * | `descansosTras: [3,5]` | `[3, 5]` |
     * | `descansosTras: []` | `[]` — **el colegio no descansa**, y eso es un dato |
     * | no hay clave, el blob no parsea, o no es lo que se espera | `null` — **no lo sabemos** |
     *
     * **La tercera fila es la razón de que este método exista y no sea un `??`.** Es la
     * misma distinción que sostiene `vacio` contra `sin_catalogo` en los catálogos: si
     * «no lo sé» saliera como `[]`, el front pintaría una parrilla corrida y **nadie
     * recibiría ningún error** — la hoja bien maquetada y falsa que este módulo lleva
     * dos documentos evitando.
     *
     * **Un `json_decode` que falla NO revienta la ruta**: cae a `null`, que para eso
     * está la tercera fila. Y no es un caso de laboratorio — el blob es texto libre de
     * 129.550 bytes que sube un programa de escritorio, así que un fichero a medias es
     * de las cosas que pasan.
     *
     * **Y una lista con un elemento que no es un entero sale `null` entera**, no
     * filtrada. Filtrar dejaría las líneas buenas y borraría la mala **sin decirlo**, y
     * una línea gruesa en el sitio equivocado es indistinguible de una correcta: es
     * exactamente la familia de fallo que el convenio `0 = domingo` se declara para
     * evitar. Un dato que no se entiende entero no se entiende.
     *
     * ## ⚠️ SI VIENES A SIMPLIFICAR ESTO, LEE ESTO PRIMERO
     *
     * La versión corta —`$blob['proyecto']['jornadaPorDefecto']['descansosTras'] ?? null`—
     * **es más corta y hace otra cosa**. Y el motivo que yo mismo tenía escrito para
     * rechazarla era **falso**: *«sin comprobar las formas, la ruta se cae»*. No se cae.
     * Medido el 4 sep 2026 sustituyendo el método entero: el `??` sobre un offset de
     * cadena devuelve `null` tan tranquilo y **los cuatro casos rotos siguen dando 200**.
     *
     * Lo que hace de verdad es **devolver tal cual lo que hubiera ahí** — y ahí lo escribe
     * un programa de escritorio, en un blob de 129.550 bytes. O sea que el campo se
     * convierte en **una puerta de salida del fichero de proyecto con nombre de dato**, en
     * la ruta cuyo contrato es que *mirar no es llevarse* (decisión 12, §9.bis). *Comprobar
     * la forma se escribió por corrección y resultó ser una guarda de seguridad.*
     *
     * **Y el test que ya vigilaba la fuga no lo habría visto**: su marca vive en
     * `programa`, fuera de `descansosTras`. Por eso hay un caso que mete la marca **dentro
     * del propio valor** (`la_comprobacion_de_forma_tambien_cierra_la_fuga`), que es el
     * único sitio donde la versión corta la dejaría salir.
     *
     * **Y esta guarda ya no protege un campo.** Todo lo que la pantalla del horario pide
     * del escritorio —jornadas por nivel, disponibilidades declaradas, las piezas sin
     * colocar, la plantilla— **sale de este mismo blob y pasa por esta misma puerta**:
     * desde el 5 sep 2026 lo leen `plantillaDelProyecto`, `disponibilidadDelProyecto`,
     * `jornadasDelProyecto` y `sinColocarDelProyecto`, con la misma regla (§9.bis.6). *(La
     * decisión 38 de `myvc_horarios` que lo pidió no está en este repositorio; lo que sí
     * está comprobado es que **el blob es la única fuente de las cuatro**.)*
     *
     * @return list<int>|null
     */
    protected function descansosDelProyecto(?array $proyecto): ?array
    {
        if ($proyecto === null) {
            return null;
        }

        $jornada = $proyecto['jornadaPorDefecto'] ?? null;

        if (! is_array($jornada) || ! array_key_exists('descansosTras', $jornada)) {
            return null;
        }

        $descansos = $jornada['descansosTras'];

        // `array_is_list` y no `is_array` a secas: un objeto JSON llega también como
        // array de PHP, y `{"a":3}` no es una lista de franjas.
        if (! is_array($descansos) || ! array_is_list($descansos)) {
            return null;
        }

        foreach ($descansos as $tras) {
            if (! is_int($tras)) {
                return null;
            }
        }

        return $descansos;
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
     * ## La población va DENTRO del renglón, y no es cosmético
     *
     * Hasta el 5 sep 2026 `tono` decía `completo · 12 de 12` con **47 docentes vivos** y
     * 35 sin color: coherente consigo mismo y contando sobre la población que no era. El
     * lector del front lo tenía apuntado y **no podía escribir la comprobación**, porque
     * el sobre no le daba la otra población. *Una cuenta que cuadra sobre la población
     * equivocada no falla, y por eso no se investiga.* Desde ese día cada renglón dice
     * su `criterio` y su denominador, y `tono` se mide sobre **los docentes que viajan
     * en esta misma respuesta** —plantilla, lecciones y piezas sin colocar—, así que el
     * consumidor puede comprobar que `tono.de` es exactamente lo que recibió.
     *
     * @param  list<array<string, mixed>>  $lecciones
     * @param  array{legible: bool, plantilla: list<array<string, mixed>>|null, disponibilidad: list<array<string, mixed>>|null, jornadas: array<string, mixed>|null, sin_colocar: list<array<string, mixed>>|null, cuentas_sin_colocar: array<string, mixed>|null}  $delProyecto
     * @return array<string, array<string, mixed>>
     */
    protected function catalogosDeLaVersion(int $yearId, array $lecciones, array $delProyecto, bool $conAutor): array
    {
        $total = count($lecciones);
        $legible = $delProyecto['legible'];
        $plantilla = $delProyecto['plantilla'];
        $disponibilidad = $delProyecto['disponibilidad'];
        $jornadas = $delProyecto['jornadas'];
        $sinColocar = $delProyecto['sin_colocar'];
        $cuentas = $delProyecto['cuentas_sin_colocar'];

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

        // ── Los docentes que VIAJAN, que es la población de `tono` y el control de la
        // plantilla. Se cuentan sobre la respuesta y no sobre una tabla a propósito: así
        // el denominador es, por construcción, lo que el cliente tiene delante.
        $tonoPorDocente = [];
        $conLeccionIds = [];
        foreach ($lecciones as $l) {
            foreach ($l['docentes'] as $d) {
                $tonoPorDocente[$d['id']] = $d['tono'];
                $conLeccionIds[$d['id']] = true;
            }
        }
        foreach ($plantilla ?? [] as $d) {
            $tonoPorDocente[$d['id']] = $d['tono'];
        }
        foreach ($sinColocar ?? [] as $p) {
            foreach ($p['docentes'] as $d) {
                $tonoPorDocente[$d['id']] = $d['tono'];
            }
        }
        $deTono = count($tonoPorDocente);
        $conTono = count(array_filter($tonoPorDocente, fn ($t) => $t !== null && $t !== ''));

        return [
            // Los dos primeros llevan `criterio` desde el 5 sep 2026 aunque su población
            // parezca obvia: «grupos» y «asignaciones» **del año del token y vivas**, que no
            // es lo mismo que las de la versión —una versión vieja puede nombrar filas que
            // ya están en la papelera—. Sin el criterio, el consumidor no puede saber contra
            // qué recontar, que es justo lo que le pasó a `tono` diciendo 12 de 12.
            'grupos' => [
                'estado' => $grupos === 0 ? 'vacio' : 'completo',
                'total' => $grupos,
                'criterio' => 'los grupos vivos del año del token',
            ],
            'asignaciones' => [
                'estado' => $asignaciones === 0 ? 'vacio' : 'completo',
                'total' => $asignaciones,
                'criterio' => 'las asignaciones vivas del año del token',
                'lecciones_sin_asignacion_viva' => count(array_filter($lecciones, fn ($l) => $l['materia'] === null)),
            ],
            // **El criterio se nombra**, porque «docentes» admite dos lecturas y la otra
            // da otro número: aquí son los que tienen alguna asignación en el año, no
            // los vivos de `profesores` —de los que 42 de 47 ni siquiera tienen
            // `tipo_profesor`, así que esa columna no sirve hoy para decir quién enseña—.
            // La tercera lectura, la plantilla que declara el fichero, tiene su propio
            // renglón justo debajo.
            'docentes' => [
                'estado' => $docentes === 0 ? 'vacio' : 'completo',
                'total' => $docentes,
                'criterio' => 'con asignación viva en el año',
                'lecciones_sin_docente' => $sinDocente,
            ],
            'plantilla' => $this->renglonDeLaPlantilla($legible, $plantilla, $conLeccionIds),
            // Sobre los docentes que viajan en ESTA respuesta, y por eso `de` no es una
            // cifra de tabla: es lo que el consumidor tiene delante y puede recontar.
            // **Nace vacío en los diecisiete**: mientras nadie reparta colores, esto es
            // `vacio` y no `completo`, y seis de los ocho informes del escritorio pintan
            // distinto sin él sin que nada se ponga rojo.
            'tono' => [
                'estado' => $conTono === 0 ? 'vacio' : ($conTono < $deTono ? 'parcial' : 'completo'),
                'con_tono' => $conTono,
                'de' => $deTono,
                'criterio' => 'los docentes que viajan en esta respuesta: plantilla, lecciones y piezas sin colocar',
                'motivo' => $conTono === 0
                    ? 'la columna existe y nadie ha repartido los colores todavía'
                    : null,
            ],
            // El caso medido y el peor de los cuatro: 87 de 312 con salón y **3 nombres**
            // contra los 17 del proyecto real. Un catálogo a medias hace MENOS ruido que
            // uno ausente, así que aquí la población no es adorno.
            'salones' => [
                'estado' => $conSalon === 0 ? 'vacio' : ($conSalon < $total ? 'parcial' : 'completo'),
                'con_salon' => $conSalon,
                'de' => $total,
                'distintos' => count($salones),
                'hay_ids' => false,
                // El `criterio` lo destapó su propio test el 5 sep 2026: este renglón decía
                // `87 de 312` sin decir **de qué son esos 312**, y son las lecciones de esta
                // versión, no los salones del colegio —que son 17 en el proyecto real y aquí
                // no se pueden contar—. Un denominador sin nombre es la mitad del fallo que
                // el renglón viene a evitar.
                'criterio' => 'las lecciones de esta versión que traen nombre de salón; `distintos` son los nombres, no los salones del colegio',
                'motivo' => 'sólo viaja el nombre que mandó la subida: el servidor no guarda salones (§4)',
            ],
            'jornadas' => $this->renglonDeLasJornadas($legible, $jornadas),
            // **Desde el 5 sep 2026 es un catálogo de verdad y ya no `sin_catalogo`**: las
            // horas de reloj viajan dentro de cada jornada (`jornadas.*.timbres`), así que
            // «no viaja por aquí» pasó a ser falso. Lo que sigue siendo cierto es que el
            // colegio no las ha dado —`null` en las cinco jornadas de los ocho proyectos
            // reales—, y eso es `vacio`: legítimo, y sin llamada a la acción. `ejes.timbres`
            // sigue `null` a propósito (ver `ejesDeLaVersion`): una cosa es lo que el
            // proyecto declara, con su `porque`, y otra una rejilla reconstruida.
            'timbres' => $this->renglonDeLosTimbres($legible, $jornadas),
            'disponibilidad' => $this->renglonDeLaDisponibilidad($legible, $disponibilidad, $conAutor),
            'sin_colocar' => $this->renglonDeLasSinColocar($legible, $sinColocar, $cuentas),
            'restricciones' => $this->renglonDelProyecto($legible,
                'restricciones, pesos y distribuciones de bloque viven dentro del fichero de proyecto y esta ruta no los parsea (§4)'),
        ];
    }

    /**
     * El renglón de un catálogo que sólo existe dentro del fichero de proyecto.
     *
     * `sin_catalogo` cuando el fichero se lee y sencillamente esto no viaja por aquí;
     * **`ilegible` cuando el fichero está guardado y no se deja leer**, que es otra cosa y
     * es la que tiene arreglo — volver a subir el proyecto.
     *
     * **El `motivo` de `ilegible` es el mismo para todos a propósito**: la causa no es
     * de cada catálogo, es del fichero. Y **no dice por qué no se pudo leer** —si el JSON
     * está roto o si lo que subieron no es un proyecto— porque el servidor no distingue las
     * dos y **una pantalla no debe afirmar una causa que la API no le ha dado**.
     *
     * *Y el `motivo` no llega al usuario: `myvc-front-b2` no lo imprime —está redactado
     * para quien lee la API y es el mismo texto en los dieciséis colegios—. **Lo que llega
     * a la pantalla es el `estado`**, y por eso el quinto tenía que ser un estado.*
     *
     * @return array<string, string>
     */
    protected function renglonDelProyecto(bool $legible, string $motivoCuandoSeLee): array
    {
        if ($legible) {
            return ['estado' => 'sin_catalogo', 'motivo' => $motivoCuandoSeLee];
        }

        return [
            'estado' => 'ilegible',
            'motivo' => 'el fichero de proyecto de esta versión está guardado y no se pudo leer, '
                .'así que de esto no se sabe nada: hay que volver a subirlo',
        ];
    }

    /**
     * `ilegible` con dos motivos, porque son dos hechos distintos y los dos tienen arreglo.
     *
     * O el fichero entero no se lee —el motivo de `renglonDelProyecto`, común a todos—, o
     * **el fichero se lee y una de sus partes no tiene la forma esperada**. Lo segundo se
     * dice con la parte nombrada: un proyecto cuyos `docentes` no se entienden puede tener
     * unas `piezas` perfectamente legibles, y marcar los cuatro renglones a la vez sería
     * afirmar de tres lo que sólo se sabe de uno. El estado es la misma palabra a propósito:
     * es la que obliga al compilador del cliente a nombrar el caso.
     *
     * @return array<string, string>
     */
    protected function renglonIlegible(bool $ficheroLegible, string $parte): array
    {
        if (! $ficheroLegible) {
            return $this->renglonDelProyecto(false, '');
        }

        return [
            'estado' => 'ilegible',
            'motivo' => "el fichero de proyecto se lee, pero su parte `{$parte}` no tiene la forma que esta ruta "
                .'espera, así que no viaja: un dato que no se entiende entero no se entiende. Hay que volver a subirlo',
        ];
    }

    /**
     * `plantilla`: los docentes que declara el fichero, y cuántos de ellos quedaron con lección.
     *
     * `parcial` aquí significa **que hay docentes con lección que la plantilla no declara**
     * —`fuera_de_la_plantilla`—, que es la única forma en que la cuenta del informe
     * `enHueco + ocupados + sinClases === docentes` puede dejar de cuadrar: alguien
     * ocupado a quien el total no cuenta. Hoy es 0 de 47 en el colegio medido.
     *
     * @param  list<array<string, mixed>>|null  $plantilla
     * @param  array<int, true>  $conLeccionIds
     * @return array<string, mixed>
     */
    protected function renglonDeLaPlantilla(bool $legible, ?array $plantilla, array $conLeccionIds): array
    {
        if ($plantilla === null) {
            return $this->renglonIlegible($legible, 'docentes');
        }

        $total = count($plantilla);
        $conLeccion = count(array_filter($plantilla, fn ($d) => $d['con_leccion']));
        $declarados = array_flip(array_column($plantilla, 'id'));
        $fuera = count(array_filter(array_keys($conLeccionIds), fn ($id) => ! isset($declarados[$id])));

        return [
            // **El `fuera` manda sobre el `vacio`, y el orden de este ternario es el
            // hallazgo.** Escrito al revés —`$total === 0 ? 'vacio' : …`— una plantilla sin
            // un solo docente declarado y lecciones que sí los tienen salía `vacio`, que
            // significa *«el colegio no declaró ninguno, y es legítimo»* y **no lleva llamada
            // a la acción**. Es el vacío que no es vacío: hay gente dando clase a la que el
            // total no cuenta, que es justo lo que rompe la cuenta del informe. Con `fuera`
            // delante sale `parcial`, que es lo que alguien mira.
            'estado' => $fuera > 0 ? 'parcial' : ($total === 0 ? 'vacio' : 'completo'),
            'total' => $total,
            'con_leccion' => $conLeccion,
            'sin_leccion' => $total - $conLeccion,
            'sin_ficha' => count(array_filter($plantilla, fn ($d) => $d['nombres'] === null && $d['apellidos'] === null)),
            'fuera_de_la_plantilla' => $fuera,
            'criterio' => 'los docentes que declara el fichero de proyecto de esta versión; `con_leccion` se mira en `horario_pieza_docente`, no en el fichero',
        ];
    }

    /**
     * `jornadas`: cuántos grupos se pintan con una jornada que es SUYA y cuántos con una prestada.
     *
     * Propia = `porque` `nivel` o `sin-nivel` (la del nivel, o la por defecto **a
     * propósito**); prestada = `sin-resolver` o `nivel-desconocido`, que se pintan con la
     * por defecto **sin que sea la suya**. Es la misma distinción que `jornadaEsSuya()` del
     * escritorio, y `parcial` es exactamente «hay grupos cuya jornada no se sabe».
     *
     * @param  array<string, mixed>|null  $jornadas
     * @return array<string, mixed>
     */
    protected function renglonDeLasJornadas(bool $legible, ?array $jornadas): array
    {
        if ($jornadas === null) {
            return $this->renglonIlegible($legible, 'jornadaPorDefecto · niveles · grupos');
        }

        $grupos = count($jornadas['grupos']);
        $niveles = count($jornadas['niveles']);
        $propia = count(array_filter($jornadas['grupos'], fn ($g) => in_array($g['porque'], ['nivel', 'sin-nivel'], true)));

        return [
            'estado' => $grupos === 0 && $niveles === 0 ? 'vacio' : ($propia < $grupos ? 'parcial' : 'completo'),
            'niveles' => $niveles,
            'grupos' => $grupos,
            'con_jornada_propia' => $propia,
            'con_jornada_prestada' => $grupos - $propia,
            'criterio' => 'propia = `porque` nivel o sin-nivel; prestada = sin-resolver o nivel-desconocido, que se pintan con `por_defecto` sin que sea la suya',
        ];
    }

    /**
     * `timbres`: en cuántas de las jornadas que viajan el colegio dio las horas de reloj.
     *
     * @param  array<string, mixed>|null  $jornadas
     * @return array<string, mixed>
     */
    protected function renglonDeLosTimbres(bool $legible, ?array $jornadas): array
    {
        if ($jornadas === null) {
            return $this->renglonIlegible($legible, 'jornadaPorDefecto · niveles · grupos');
        }

        $todas = [$jornadas['por_defecto'], ...array_column($jornadas['niveles'], 'jornada')];
        $de = count($todas);
        $con = count(array_filter($todas, fn ($j) => $j['timbres'] !== null));

        return [
            'estado' => $con === 0 ? 'vacio' : ($con < $de ? 'parcial' : 'completo'),
            'con_timbres' => $con,
            'de' => $de,
            'criterio' => 'la jornada por defecto y la de cada nivel, tal como las declara el fichero',
            'motivo' => $con === 0
                ? 'el colegio no ha dado las horas de reloj en ninguna jornada: `jornadas.*.timbres` van nulos. `ejes.timbres` sigue nulo a propósito'
                : null,
        ];
    }

    /**
     * `disponibilidad`: cuántos docentes declararon alguna marca, y cuántas de cada clase.
     *
     * No hay `parcial`: la entrada existe para cada docente de la plantilla y `marcas: []`
     * es un dato —«sin pegas»—, no un hueco. `vacio` es que nadie marcó nada, y es legítimo.
     *
     * @param  list<array<string, mixed>>|null  $disponibilidad
     * @return array<string, mixed>
     */
    protected function renglonDeLaDisponibilidad(bool $legible, ?array $disponibilidad, bool $conAutor): array
    {
        if ($disponibilidad === null) {
            return $this->renglonIlegible($legible, 'docentes[].disponibilidad');
        }

        $marcas = array_merge([], ...array_column($disponibilidad, 'marcas'));
        $porEstado = array_count_values(array_column($marcas, 'estado'));

        return [
            'estado' => $marcas === [] ? 'vacio' : 'completo',
            'con_marcas' => count(array_filter($disponibilidad, fn ($d) => $d['marcas'] !== [])),
            'de' => count($disponibilidad),
            'marcas' => count($marcas),
            'condicional' => $porEstado['condicional'] ?? 0,
            'inadecuado' => $porEstado['inadecuado'] ?? 0,
            // **`autor` y NO `estado`, y el nombre es la decisión.** En este sobre ya hay
            // dos cosas llamadas `estado` con vocabularios que no se solapan —el de un
            // catálogo (`completo`…`ilegible`) y el de una marca (`condicional` ·
            // `inadecuado`)—, así que un tercero bajo esa clave dejaría al lector sin forma
            // de saber cuál le tocó (§9.ter.6). Este campo contesta otra pregunta —**si el
            // `profesor_id` de cada entrada viaja o va en nulo**— y por eso lleva otra
            // palabra.
            //
            // Existe para que un `profesor_id: null` **nunca se lea como «este dato no
            // está»**: aquí sí está, y lo que pasa es que a quien pregunta no le
            // corresponde. Sin este renglón, «reservado» e «ilegible» se verían igual desde
            // la pantalla, que es la confusión que este módulo lleva cinco secciones
            // evitando.
            'autor' => $conAutor ? 'visible' : 'reservado',
            'criterio' => 'una entrada por docente de la plantilla; `marcas: []` es «sin pegas», porque el escritorio sólo guarda lo que no es adecuado'
                .($conAutor ? '' : '. El `profesor_id` de cada entrada va en nulo: las cuentas de este renglón son completas, pero de quién es cada pega sólo lo ve quien puede publicar el horario'),
        ];
    }

    /**
     * La lista de disponibilidad tal y como sale, **con o sin el autor de cada pega**.
     *
     * Decisión 3 de esta noche, contestada por Joseth el 6 sep 2026: *el `inadecuado` viaja
     * con su autor, pero sólo para quien puede publicar*.
     *
     * ## Por qué el criterio va aquí dentro y no en la ruta
     *
     * Porque **`getLecciones` no tiene permiso interno**: lleva `auth.personal` y nada más,
     * así que la llama cualquiera de los 53 docentes del colegio. Con el autor
     * incondicional, esta lista es *«a qué hora le viene mal a cada compañero»* repartida a
     * la sala de profesores entera — y una pega con nombre **se rebate peor de lo que se
     * reparte**. Quien cuadra el horario sí necesita saber a quién preguntarle; los demás
     * necesitan ver **que hay una pega**, que es lo que las cuentas del renglón siguen
     * diciendo enteras.
     *
     * Es el mismo escalón que la ruta del proyecto: `auth.personal` en la ruta —cierra la
     * puerta a alumnos y acudientes— y `puedePublicarHorario` **dentro**, donde se decide
     * qué sale. No es un 403: la respuesta se da igual y lo que cambia es cuánto dice.
     *
     * ## Se tacha el AUTOR, no la marca
     *
     * Las marcas siguen viajando enteras —día, franja y `estado`—, así que la rejilla se
     * pinta igual para todos y `con_marcas`, `marcas`, `condicional` e `inadecuado` cuadran
     * con lo que se recibió. Lo único que se va es **de quién es cada una**.
     *
     * @param  list<array<string, mixed>>|null  $disponibilidad
     * @return list<array<string, mixed>>|null
     */
    protected function disponibilidadQueViaja(?array $disponibilidad, bool $conAutor): ?array
    {
        if ($disponibilidad === null || $conAutor) {
            return $disponibilidad;
        }

        // `profesor_id` a nulo y **la clave se queda**: quitarla cambiaría la forma de la
        // entrada según quién pregunte, y un lector que la exija se rompería con el docente
        // raso y no con el coordinador — o sea el fallo que sólo aparece en producción y en
        // la mitad de las cuentas. El renglón `autor` de `catalogos` dice que ese nulo es
        // una reserva y no un hueco.
        return array_map(fn ($d) => ['profesor_id' => null, 'marcas' => $d['marcas']], $disponibilidad);
    }

    /**
     * `sin_colocar`: las piezas del fichero que ninguna casilla recibió, contra las incompletas.
     *
     * **`sin_colocar ⊆ incompletas`, y NUNCA `===`.** Verificado en dos versiones reales
     * el 5 sep 2026: la 6 daba 1 y 1, la 8 da **1 contra 3**. Una pieza sin colocar
     * siempre deja su asignación corta; lo contrario no, porque colocar una pieza de
     * varios grupos —la misa— **tira** las que estorban en la casilla, y una pieza tirada
     * desaparece de la versión en vez de ir a la bandeja. La igualdad se cumplió en siete
     * versiones seguidas y era coincidencia. Por eso el renglón trae las dos cifras: para
     * **confrontar** (`total ≤ incompletas`), no para cuadrar.
     *
     * `incompletas` se cuenta sobre las asignaciones y la IH **del propio fichero**, no de
     * `asignaturas`: así las dos cifras son del mismo instante, que es lo que hace que se
     * puedan confrontar.
     *
     * @param  list<array<string, mixed>>|null  $sinColocar
     * @param  array<string, mixed>|null  $cuentas
     * @return array<string, mixed>
     */
    protected function renglonDeLasSinColocar(bool $legible, ?array $sinColocar, ?array $cuentas): array
    {
        if ($sinColocar === null || $cuentas === null) {
            return $this->renglonIlegible($legible, 'piezas · colocaciones · asignaciones');
        }

        return [
            'estado' => $sinColocar === [] ? 'vacio' : 'completo',
            'total' => count($sinColocar),
            'piezas' => $cuentas['total_piezas'],
            'colocadas' => $cuentas['colocadas'],
            'incompletas' => $cuentas['incompletas'],
            'criterio' => 'sin_colocar ⊆ incompletas, nunca ===: colocar una pieza de varios grupos TIRA las que estorban y una pieza tirada no va a la bandeja. `incompletas` se cuenta con la IH del propio fichero',
        ];
    }

    // ═════════════════════════════════════════════════════════════════════════════
    // Lectores del fichero de proyecto. Cada uno devuelve `null` cuando su parte no se
    // entiende ENTERA, nunca una lista filtrada a medias: una entrada rota tira la
    // lista, por la misma regla que `descansosDelProyecto()`. Y ninguno devuelve una
    // cadena libre del blob: los ids se resuelven contra las tablas después.
    // ═════════════════════════════════════════════════════════════════════════════

    /**
     * Una lista del proyecto, o `null` si no está o no es una lista.
     *
     * @param  array<string, mixed>|null  $proyecto
     * @return list<mixed>|null
     */
    private function listaDe(?array $proyecto, string $clave): ?array
    {
        if ($proyecto === null || ! array_key_exists($clave, $proyecto)) {
            return null;
        }

        $lista = $proyecto[$clave];

        return is_array($lista) && array_is_list($lista) ? $lista : null;
    }

    /**
     * Una lista de enteros, o `null` si no lo es entera.
     *
     * @return list<int>|null
     */
    private function listaDeEnteros(mixed $lista): ?array
    {
        if (! is_array($lista) || ! array_is_list($lista)) {
            return null;
        }

        foreach ($lista as $n) {
            if (! is_int($n)) {
                return null;
            }
        }

        return $lista;
    }

    /**
     * Los `profesores.id` que declara el fichero de proyecto: la plantilla ENTERA.
     *
     * Sólo el id: el `nombre` que el escritorio guarda al lado **no sale de aquí** —se
     * resuelve contra `profesores`—, y un id repetido tira la lista, porque una plantilla
     * que cuenta dos veces a alguien no es una plantilla.
     *
     * @param  array<string, mixed>|null  $proyecto
     * @return list<int>|null
     */
    protected function plantillaDelProyecto(?array $proyecto): ?array
    {
        $docentes = $this->listaDe($proyecto, 'docentes');

        if ($docentes === null) {
            return null;
        }

        $ids = [];

        foreach ($docentes as $d) {
            if (! is_array($d) || ! isset($d['profesorId']) || ! is_int($d['profesorId']) || $d['profesorId'] < 1 || isset($ids[$d['profesorId']])) {
                return null;
            }

            $ids[$d['profesorId']] = true;
        }

        return array_keys($ids);
    }

    /**
     * Las marcas de disponibilidad que declaró cada docente del fichero.
     *
     * Una entrada por docente, **con quién la declaró** (`profesor_id`). La clave
     * `disponibilidad` es opcional en el modelo del escritorio y su ausencia vale por
     * `marcas: []` —es lo que hace su `estadoEn()`—; pero si está y no tiene la forma,
     * la lista entera es `null`. Los estados son los dos que el escritorio guarda:
     * `adecuado` es el valor por defecto y **nunca se escribe**, así que uno que aparezca
     * no es su modelo y tira la lista.
     *
     * @param  array<string, mixed>|null  $proyecto
     * @return list<array{profesor_id: int, marcas: list<array{dia: int, franja: int, estado: string}>}>|null
     */
    protected function disponibilidadDelProyecto(?array $proyecto): ?array
    {
        $docentes = $this->listaDe($proyecto, 'docentes');

        if ($docentes === null) {
            return null;
        }

        $salida = [];

        foreach ($docentes as $d) {
            if (! is_array($d) || ! isset($d['profesorId']) || ! is_int($d['profesorId']) || isset($salida[$d['profesorId']])) {
                return null;
            }

            $marcas = [];

            if (array_key_exists('disponibilidad', $d) && $d['disponibilidad'] !== null) {
                $declarada = $d['disponibilidad'];

                if (! is_array($declarada) || ! array_key_exists('marcas', $declarada)
                    || ! is_array($declarada['marcas']) || ! array_is_list($declarada['marcas'])) {
                    return null;
                }

                foreach ($declarada['marcas'] as $m) {
                    $marca = $this->marcaLeida($m);

                    if ($marca === null) {
                        return null;
                    }

                    $marcas[] = $marca;
                }
            }

            $salida[$d['profesorId']] = ['profesor_id' => $d['profesorId'], 'marcas' => $marcas];
        }

        ksort($salida);

        return array_values($salida);
    }

    /**
     * Una marca: casilla (`dia` 0..6, `franja` base 1) y uno de los dos estados que se guardan.
     *
     * @return array{dia: int, franja: int, estado: string}|null
     */
    private function marcaLeida(mixed $m): ?array
    {
        if (! is_array($m)) {
            return null;
        }

        $dia = $m['dia'] ?? null;
        $franja = $m['franja'] ?? null;
        $estado = $m['estado'] ?? null;

        if (! is_int($dia) || $dia < 0 || $dia > 6 || ! is_int($franja) || $franja < 1) {
            return null;
        }

        if (! in_array($estado, ['condicional', 'inadecuado'], true)) {
            return null;
        }

        return ['dia' => $dia, 'franja' => $franja, 'estado' => $estado];
    }

    /**
     * Las jornadas del fichero: la por defecto, la de cada nivel, y de cuál cuelga cada grupo.
     *
     * Las tres partes van juntas o no va ninguna: el `porque` de un grupo apunta a la
     * lista de niveles, y un `nivel` sobre una lista que no se pudo leer no dice nada.
     * El `porque` se calcula aquí **igual que `jornadaDelGrupo()` del escritorio**:
     *
     *   - `nivel`              cuelga de un nivel que está en la lista: la jornada es la suya
     *   - `sin-nivel`          no cuelga de ninguno a propósito: la por defecto ES la suya
     *   - `sin-resolver`       no se supo cuál es: se pinta con la por defecto y PUEDE NO SER la suya
     *   - `nivel-desconocido`  apunta a un nivel que no está en la lista: igual de poco fiable
     *
     * El `grado` que el escritorio guarda al lado de `sin-resolver` es texto libre y no sale.
     *
     * @param  array<string, mixed>|null  $proyecto
     * @return array{por_defecto: array<string, mixed>, niveles: list<array{id: int, jornada: array<string, mixed>}>, grupos: list<array{grupo_id: int, nivel_id: int|null, porque: string}>}|null
     */
    protected function jornadasDelProyecto(?array $proyecto): ?array
    {
        if ($proyecto === null) {
            return null;
        }

        $porDefecto = $this->jornadaLeida($proyecto['jornadaPorDefecto'] ?? null);
        $niveles = $this->listaDe($proyecto, 'niveles');
        $grupos = $this->listaDe($proyecto, 'grupos');

        if ($porDefecto === null || $niveles === null || $grupos === null) {
            return null;
        }

        $nivelesLeidos = [];

        foreach ($niveles as $n) {
            if (! is_array($n) || ! isset($n['id']) || ! is_int($n['id']) || isset($nivelesLeidos[$n['id']])) {
                return null;
            }

            $jornada = $this->jornadaLeida($n['jornada'] ?? null);

            if ($jornada === null) {
                return null;
            }

            $nivelesLeidos[$n['id']] = ['id' => $n['id'], 'jornada' => $jornada];
        }

        $gruposLeidos = [];

        foreach ($grupos as $g) {
            if (! is_array($g) || ! isset($g['id']) || ! is_int($g['id']) || isset($gruposLeidos[$g['id']])
                || ! isset($g['nivel']) || ! is_array($g['nivel'])) {
                return null;
            }

            $estado = $g['nivel']['estado'] ?? null;

            if ($estado === 'sin-nivel' || $estado === 'sin-resolver') {
                $gruposLeidos[$g['id']] = ['grupo_id' => $g['id'], 'nivel_id' => null, 'porque' => $estado];

                continue;
            }

            if ($estado !== 'resuelto' || ! isset($g['nivel']['nivelId']) || ! is_int($g['nivel']['nivelId'])) {
                return null;
            }

            $nivelId = $g['nivel']['nivelId'];
            $gruposLeidos[$g['id']] = [
                'grupo_id' => $g['id'],
                'nivel_id' => $nivelId,
                'porque' => isset($nivelesLeidos[$nivelId]) ? 'nivel' : 'nivel-desconocido',
            ];
        }

        ksort($nivelesLeidos);
        ksort($gruposLeidos);

        return ['por_defecto' => $porDefecto, 'niveles' => array_values($nivelesLeidos), 'grupos' => array_values($gruposLeidos)];
    }

    /**
     * Una jornada del escritorio, con su forma comprobada campo a campo.
     *
     * `timbres` es `null` mientras el colegio no los haya dado —y **es importante que
     * pueda serlo**, dice su modelo: un array de horas inventadas se imprimiría en el
     * horario de la puerta del salón—. Cuando no lo es, tiene exactamente `franjas`
     * elementos y cada uno es `HH:MM` a `HH:MM`: es la única cadena que se acepta y va
     * atada a una expresión regular, porque **sin ella sería un canal de texto libre del
     * blob** con nombre de hora.
     *
     * @return array{dias: list<int>, franjas: int, descansos_tras: list<int>, timbres: list<array{inicio: string, fin: string}>|null}|null
     */
    private function jornadaLeida(mixed $j): ?array
    {
        if (! is_array($j) || ! array_key_exists('timbres', $j)) {
            return null;
        }

        $dias = $this->listaDeEnteros($j['dias'] ?? null);
        $descansos = $this->listaDeEnteros($j['descansosTras'] ?? null);
        $franjas = $j['franjas'] ?? null;

        if ($dias === null || $descansos === null || ! is_int($franjas) || $franjas < 0) {
            return null;
        }

        foreach ($dias as $d) {
            if ($d < 0 || $d > 6) {
                return null;
            }
        }

        $timbres = null;

        if ($j['timbres'] !== null) {
            if (! is_array($j['timbres']) || ! array_is_list($j['timbres']) || count($j['timbres']) !== $franjas) {
                return null;
            }

            $timbres = [];

            foreach ($j['timbres'] as $t) {
                if (! is_array($t) || ! isset($t['inicio'], $t['fin']) || ! $this->esHoraDeReloj($t['inicio']) || ! $this->esHoraDeReloj($t['fin'])) {
                    return null;
                }

                $timbres[] = ['inicio' => $t['inicio'], 'fin' => $t['fin']];
            }
        }

        return ['dias' => $dias, 'franjas' => $franjas, 'descansos_tras' => $descansos, 'timbres' => $timbres];
    }

    private function esHoraDeReloj(mixed $hora): bool
    {
        return is_string($hora) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $hora) === 1;
    }

    /**
     * Las piezas del fichero que no están en ninguna colocación, y las cuentas para confrontarlas.
     *
     * Hacen falta las tres listas —`piezas`, `colocaciones`, `asignaciones`— y las tres se
     * leen enteras: una colocación que apunte a una pieza que no existe, o dos que apunten
     * a la misma, no se entienden y tiran la lectura. `incompletas` sale de aquí y no de
     * `asignaturas` a propósito (ver `renglonDeLasSinColocar`).
     *
     * @param  array<string, mixed>|null  $proyecto
     * @return array{piezas: list<array{pieza_id: string, duracion: int, asignatura_ids: list<int>, docente_ids: list<int>}>, total_piezas: int, colocadas: int, incompletas: int}|null
     */
    protected function sinColocarDelProyecto(?array $proyecto): ?array
    {
        $piezas = $this->listaDe($proyecto, 'piezas');
        $colocaciones = $this->listaDe($proyecto, 'colocaciones');
        $asignaciones = $this->listaDe($proyecto, 'asignaciones');

        if ($piezas === null || $colocaciones === null || $asignaciones === null) {
            return null;
        }

        $ih = [];

        foreach ($asignaciones as $a) {
            if (! is_array($a) || ! isset($a['id'], $a['ih']) || ! is_int($a['id']) || ! is_int($a['ih']) || isset($ih[$a['id']])) {
                return null;
            }

            $ih[$a['id']] = $a['ih'];
        }

        $leidas = [];

        foreach ($piezas as $p) {
            if (! is_array($p) || ! isset($p['id'], $p['duracion'], $p['lecciones'], $p['docentes'])) {
                return null;
            }

            $id = $p['id'];

            if (! is_string($id) || preg_match(self::FORMA_DEL_ID_DE_PIEZA, $id) !== 1 || isset($leidas[$id])) {
                return null;
            }

            if (! is_int($p['duracion']) || $p['duracion'] < 1) {
                return null;
            }

            if (! is_array($p['lecciones']) || ! array_is_list($p['lecciones']) || $p['lecciones'] === []) {
                return null;
            }

            $asignaturaIds = [];

            foreach ($p['lecciones'] as $l) {
                if (! is_array($l) || ! isset($l['asignacionId']) || ! is_int($l['asignacionId'])) {
                    return null;
                }

                $asignaturaIds[] = $l['asignacionId'];
            }

            $docenteIds = $this->listaDeEnteros($p['docentes']);

            if ($docenteIds === null) {
                return null;
            }

            $leidas[$id] = [
                'pieza_id' => $id,
                'duracion' => $p['duracion'],
                'asignatura_ids' => $asignaturaIds,
                'docente_ids' => array_values(array_unique($docenteIds)),
            ];
        }

        $colocadas = [];

        foreach ($colocaciones as $c) {
            if (! is_array($c) || ! isset($c['piezaId']) || ! is_string($c['piezaId'])
                || ! isset($leidas[$c['piezaId']]) || isset($colocadas[$c['piezaId']])) {
                return null;
            }

            $colocadas[$c['piezaId']] = true;
        }

        // Σ duración colocada por asignación, contra la IH del fichero: las que quedan cortas.
        $colocado = [];

        foreach ($leidas as $id => $pieza) {
            if (! isset($colocadas[$id])) {
                continue;
            }

            foreach ($pieza['asignatura_ids'] as $asignaturaId) {
                $colocado[$asignaturaId] = ($colocado[$asignaturaId] ?? 0) + $pieza['duracion'];
            }
        }

        $incompletas = 0;

        foreach ($ih as $asignaturaId => $horas) {
            if (($colocado[$asignaturaId] ?? 0) < $horas) {
                $incompletas++;
            }
        }

        $sinColocar = array_values(array_filter($leidas, fn ($p) => ! isset($colocadas[$p['pieza_id']])));
        usort($sinColocar, fn ($a, $b) => strcmp($a['pieza_id'], $b['pieza_id']));

        return [
            'piezas' => $sinColocar,
            'total_piezas' => count($leidas),
            'colocadas' => count($colocadas),
            'incompletas' => $incompletas,
        ];
    }

    // ═════════════════════════════════════════════════════════════════════════════
    // Resolución contra las tablas: los ids que salieron del blob se convierten en las
    // fichas de esta base. Una consulta por familia, nunca una por fila.
    // ═════════════════════════════════════════════════════════════════════════════

    /**
     * Las fichas de `profesores` de esos ids, indexadas por id. **Sin filtrar `deleted_at`**,
     * igual que `docentesDeLaVersion`: una versión vieja puede nombrar a alguien que ya se
     * fue, y su nombre sigue siendo el suyo.
     *
     * @param  list<int>  $ids
     * @return array<int, array{id: int, nombres: string|null, apellidos: string|null, tono: string|null}>
     */
    protected function fichasDeDocentes(array $ids): array
    {
        $ids = array_values(array_unique($ids));

        if ($ids === []) {
            return [];
        }

        $filas = DB::select(
            'SELECT p.id, p.nombres, p.apellidos, p.tono FROM profesores p WHERE p.id IN ('.implode(',', array_fill(0, count($ids), '?')).')',
            $ids
        );

        $fichas = [];

        foreach ($filas as $f) {
            $fichas[(int) $f->id] = ['id' => (int) $f->id, 'nombres' => $f->nombres, 'apellidos' => $f->apellidos, 'tono' => $f->tono];
        }

        return $fichas;
    }

    /**
     * La ficha de un docente, o una con los nombres a `null` si ese id no existe en `profesores`.
     *
     * Con `null` y no omitido: la plantilla es lo que el fichero declara, y quitar una fila
     * haría que `total` dejara de ser el total. El renglón cuenta cuántas son (`sin_ficha`).
     *
     * @param  array<int, array{id: int, nombres: string|null, apellidos: string|null, tono: string|null}>  $fichas
     * @return array{id: int, nombres: string|null, apellidos: string|null, tono: string|null}
     */
    private function fichaDe(array $fichas, int $id): array
    {
        return $fichas[$id] ?? ['id' => $id, 'nombres' => null, 'apellidos' => null, 'tono' => null];
    }

    /**
     * La plantilla con su ficha y su `con_leccion`, ordenada como los docentes de una pieza.
     *
     * @param  list<int>  $ids
     * @param  array<int, array{id: int, nombres: string|null, apellidos: string|null, tono: string|null}>  $fichas
     * @param  array<int, true>  $conLeccion
     * @return list<array<string, mixed>>
     */
    protected function plantillaResuelta(array $ids, array $fichas, array $conLeccion): array
    {
        $plantilla = array_map(fn (int $id) => [...$this->fichaDe($fichas, $id), 'con_leccion' => isset($conLeccion[$id])], $ids);

        usort($plantilla, fn ($a, $b) => [$a['apellidos'] ?? '', $a['nombres'] ?? '', $a['id']] <=> [$b['apellidos'] ?? '', $b['nombres'] ?? '', $b['id']]);

        return $plantilla;
    }

    /**
     * Las jornadas con el nombre de cada nivel puesto desde `niveles_educativos`.
     *
     * Del blob sale el id y nada más: el nombre del nivel es de la tabla, y `null` si ese id
     * no está en ella — que es exactamente lo que `nivel-desconocido` significa para un grupo.
     *
     * @param  array{por_defecto: array<string, mixed>, niveles: list<array{id: int, jornada: array<string, mixed>}>, grupos: list<array<string, mixed>>}  $leidas
     * @return array<string, mixed>
     */
    protected function jornadasResueltas(array $leidas): array
    {
        $ids = array_column($leidas['niveles'], 'id');
        $nombres = [];

        if ($ids !== []) {
            $filas = DB::select(
                'SELECT n.id, n.nombre FROM niveles_educativos n WHERE n.id IN ('.implode(',', array_fill(0, count($ids), '?')).')',
                $ids
            );

            foreach ($filas as $f) {
                $nombres[(int) $f->id] = $f->nombre;
            }
        }

        return [
            'por_defecto' => $leidas['por_defecto'],
            'niveles' => array_map(fn ($n) => ['id' => $n['id'], 'nombre' => $nombres[$n['id']] ?? null, 'jornada' => $n['jornada']], $leidas['niveles']),
            'grupos' => $leidas['grupos'],
        ];
    }

    /**
     * Las piezas sin colocar con su asignación y sus docentes resueltos contra las tablas.
     *
     * La asignación viaja con las mismas claves que en una lección —materia, alias, grupo—
     * para que la bandeja se pinte con el mismo código que la rejilla. Con `LEFT JOIN` y sin
     * filtrar `deleted_at`, como las lecciones: una asignación borrada sale con `materia`
     * a `null`, que es un agujero que se ve.
     *
     * @param  list<array{pieza_id: string, duracion: int, asignatura_ids: list<int>, docente_ids: list<int>}>  $piezas
     * @param  array<int, array{id: int, nombres: string|null, apellidos: string|null, tono: string|null}>  $fichas
     * @return list<array<string, mixed>>
     */
    protected function piezasSinColocarResueltas(array $piezas, array $fichas): array
    {
        $ids = array_values(array_unique(array_merge([], ...array_column($piezas, 'asignatura_ids'))));
        $asignaturas = [];

        if ($ids !== []) {
            $filas = DB::select(
                'SELECT a.id, a.creditos, m.materia, m.alias AS alias_materia,
                        g.id AS grupo_id, g.nombre AS nombre_grupo, g.abrev AS abrev_grupo
                   FROM asignaturas a
                   LEFT JOIN materias m ON m.id = a.materia_id
                   LEFT JOIN grupos g ON g.id = a.grupo_id
                  WHERE a.id IN ('.implode(',', array_fill(0, count($ids), '?')).')',
                $ids
            );

            foreach ($filas as $f) {
                $asignaturas[(int) $f->id] = [
                    'asignatura_id' => (int) $f->id,
                    'ih' => $f->creditos === null ? null : (int) $f->creditos,
                    'materia' => $f->materia,
                    'alias_materia' => $f->alias_materia,
                    'grupo_id' => $f->grupo_id === null ? null : (int) $f->grupo_id,
                    'nombre_grupo' => $f->nombre_grupo,
                    'abrev_grupo' => $f->abrev_grupo,
                ];
            }
        }

        return array_map(fn ($p) => [
            'pieza_id' => $p['pieza_id'],
            'duracion' => $p['duracion'],
            'asignaturas' => array_map(fn (int $id) => $asignaturas[$id] ?? [
                'asignatura_id' => $id, 'ih' => null, 'materia' => null, 'alias_materia' => null,
                'grupo_id' => null, 'nombre_grupo' => null, 'abrev_grupo' => null,
            ], $p['asignatura_ids']),
            'docentes' => array_map(fn (int $id) => $this->fichaDe($fichas, $id), $p['docente_ids']),
        ], $piezas);
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
    /**
     * El proyecto de una versión, tal cual se subió, para llevárselo a otro computador.
     *
     * **La sexta ruta, autorizada por Joseth el 5 sep 2026** — la única de la familia
     * que estuvo escrita como pregunta desde el principio y nadie llegó a pedir (§10.2,
     * decisión 3). Cierra el hueco que dejaban las otras cinco: hasta hoy, el `.myvch`
     * que subió un colegio **sólo se sacaba con un `SELECT` a mano**.
     *
     * ## Por qué su permiso NO es el de mirar
     *
     * `puedePublicarHorario` —superusuario o coordinación—, que es el mismo de
     * `putOficial` y **no** el `auth.personal` con el que cualquiera de los 53 docentes
     * lee las lecciones. Es la línea que las decisiones 12 y §9.bis ya habían trazado en
     * dos pasos: *«listar no es descargar»* y luego *«mirar no es llevarse»*. Mirar la
     * rejilla del colegio es un hecho que ya está en el pasillo —el horario se imprime y
     * se cuelga—; **llevarse el fichero es sacar de la casa el trabajo entero de cuadrar
     * el año**, con las disponibilidades declaradas de los 47 docentes dentro.
     *
     * El guard de la ruta sigue siendo `auth.personal`, que cierra la puerta a alumnos y
     * acudientes antes de tocar el controlador; el criterio fino va aquí, como en las
     * otras dos escrituras de la familia.
     *
     * ## Por qué viaja como fichero y no dentro de un JSON
     *
     * **Medido, no elegido por gusto**: meter el `.myvch` como cadena dentro de un JSON
     * duplica cada tabulador y cada comilla, y el factor va de **× 1,41** en un proyecto
     * vacío a **× 1,795** en uno con el horario entero colocado (§10.2). Los 128.779
     * bytes del proyecto real de `simonbolivar` se irían a 231.135. Aquí no hay nada que
     * escapar: sale el mismo texto que entró, byte a byte.
     *
     * Y por eso mismo **no lleva `comprobaciones` ni `es_oficial` al lado**: quien quiera
     * el veredicto tiene `getVersiones`, y mezclar metadatos con el fichero obligaría a
     * un sobre y a volver al escapado. *Una ruta que devuelve un fichero devuelve un
     * fichero.*
     *
     * ## El `{id}` se comprueba contra el año del token, igual que en `getLecciones`
     *
     * **404 si la versión no es de ese año, no 403**, y por la misma razón de allí: sin
     * eso sería un identificador de la URL que no comprueba nadie
     * (`tools/identificadores-del-cuerpo.py`). Y el nombre del fichero se construye
     * aquí y no se toma de `horario_versiones.nombre`: ese campo lo escribe el cliente y
     * lleva acentos, comillas invertidas y puntos —«prueba `servidor` 2026-09-02 · punta
     * a punta» es un nombre real de esta base—, así que ponerlo en una cabecera es
     * ofrecerle al que sube que elija la cabecera del que descarga.
     */
    public function getProyecto($id)
    {
        Autoriza::exigir(Autoriza::puedePublicarHorario($this->user),
            'No tienes permiso para descargar el proyecto del horario.');

        $versionId = (int) $id;
        $yearId = (int) $this->user->year_id;

        $version = DB::select(
            'SELECT hv.id, hv.year_id, hv.proyecto, y.year
               FROM horario_versiones hv
               LEFT JOIN years y ON y.id = hv.year_id
              WHERE hv.id = ? AND hv.year_id = ?',
            [$versionId, $yearId]
        );

        if ($version === []) {
            abort(404, 'Esa versión del horario no existe en este año.');
        }

        $proyecto = (string) $version[0]->proyecto;
        $anio = $version[0]->year === null ? $yearId : (int) $version[0]->year;
        $nombre = "horario-{$anio}-v{$versionId}.myvch";

        // `Content-Length` va explícito porque el cuerpo es una cadena ya en memoria y
        // el cliente de escritorio la usa para su barra de progreso. `strlen` y no
        // `mb_strlen`: lo que cuenta una cabecera son bytes, no caracteres, y este
        // fichero lleva acentos dentro.
        return response($proyecto, 200, [
            'Content-Type' => 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="'.$nombre.'"',
            'Content-Length' => (string) strlen($proyecto),
        ]);
    }

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

        /*
         * `acepto_vaciar` — LA PUERTA DE LA VERSIÓN VACÍA (decisión 8, contestada por Joseth
         * el 7 sep 2026), y es **la misma forma que `acepto_perder` a propósito**, no una
         * parecida.
         *
         * **Qué cierra, medido el 7 sep 2026:** publicar una versión con cero lecciones
         * contestaba `200` y ponía a cero las siete columnas de día de **las 134 asignaturas
         * del año** — «Clases de hoy» en blanco para el colegio entero, que es el problema de
         * la §2 que este módulo vino a resolver. La respuesta lo declaraba
         * (`asignaciones_con_algun_dia: 0`), pero **informar bien y dejar publicar son dos
         * cosas distintas**.
         *
         * **Y NO se prohíbe, porque hoy es la única forma de despublicar**: la única escritura
         * de `years.horario_version_id` pone un id, ninguna lo pone a `NULL` y no hay ruta que
         * borre una versión. Un colegio que publicó un horario equivocado y prefiere no
         * enseñar ninguno sólo tiene esta palanca; un 422 seco se la quitaría sin sustituto.
         *
         * Un número y no un `true`, por lo que dice `acepto_perder` doce líneas más arriba:
         * *un booleano acaba puesto por costumbre, porque nunca estorba*. La simetría es
         * exacta — **publicar no puede quitarle el horario a 134 asignaciones en silencio, por
         * lo mismo que no puede perder 32.**
         */
        $aceptoVaciar = Request::input('acepto_vaciar');

        if ($aceptoVaciar !== null && ! is_int($aceptoVaciar)) {
            $this->rechazar([
                'message' => 'El campo `acepto_vaciar` es el NÚMERO de asignaciones que se quedarían sin horario, no una bandera. Nada se escribió.',
                'motivo' => 'acepto-vaciar-no-es-un-numero',
                'acepto_vaciar_recibido' => $aceptoVaciar,
            ]);
        }

        $derivacion = DB::transaction(function () use ($versionId, $yearId, $ahora, $aceptoPerder, $aceptoVaciar): array {
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
             * LA PUERTA DE LA VERSIÓN VACÍA, y **la cuenta va aquí dentro por lo mismo que la
             * de arriba**: contar fuera y escribir dentro son dos instantes, y entre los dos
             * alguien puede publicar otra cosa.
             */
            $seVacian = $this->asignacionesQueSeQuedanSinHorario($versionId, $yearId);

            if ($aceptoVaciar === null && $seVacian !== 0) {
                $this->rechazar([
                    // Mismo criterio que el de `acepto_perder`: nombra el número y **no**
                    // invita a remandarlo. Un «vuelve a llamar con acepto_vaciar: N»
                    // reconstruye el `forzar: true` en dos viajes, y la confirmación tiene
                    // que pasar por una persona.
                    'message' => "Esta versión no coloca ninguna clase, así que publicarla dejaría {$seVacian} asignacion(es) sin ningún día: el colegio entero vería «Clases de hoy» en blanco. Si es lo que quieres —hoy es la única forma de dejar el año sin horario publicado—, enséñale esa cifra a quien publica y confirma con la que él diga. Nada se escribió.",
                    'motivo' => 'vaciado-no-aceptado',
                    'asignaciones_que_se_quedan_sin_horario' => $seVacian,
                ]);
            }

            /*
             * **Y también rebota el número que sobra**, igual que arriba: si el cliente
             * declara 134 y el servidor cuenta 0 —porque el año ya estaba sin horario, o
             * porque esta versión sí coloca clases—, ese campo está puesto a mano en el
             * código del cliente y no lo ha mirado nadie. Dejarlo pasar «porque no vacía
             * nada» es exactamente cómo se convierte en la bandera que no quisimos.
             */
            if ($aceptoVaciar !== null && $aceptoVaciar !== $seVacian) {
                $this->rechazar([
                    'message' => "No coincide: aceptas vaciar {$aceptoVaciar} y el servidor cuenta {$seVacian} en este momento. Esa cifra de {$seVacian} es la de ahora y no la da ninguna otra pantalla: enséñasela a quien publica y confirma con lo que él diga. Nada se escribió.",
                    'motivo' => 'acepto-vaciar-no-coincide',
                    'acepto_vaciar' => $aceptoVaciar,
                    'asignaciones_que_se_quedan_sin_horario' => $seVacian,
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
    /**
     * Cuántas asignaciones **se quedarían sin ningún día** si se publicara esta versión.
     *
     * ## Las dos condiciones, y la primera es la que impide romper a los clientes vivos
     *
     * **Devuelve 0 en cuanto la versión coloca ALGO**, y eso no es un atajo: es el requisito.
     * Publicar una versión normal **no puede empezar a pedir un campo nuevo** —eso rompería a
     * todo cliente desplegado el día de la tanda—, así que la puerta sólo existe para el caso
     * que Joseth decidió: **la versión que no coloca ni una clase**. Una versión incompleta
     * —que deja asignaturas sin día porque no le dio tiempo a cuadrarlas— **no pasa por aquí**,
     * y su hueco ya lo dice el veredicto de la subida (`incompletas: N de M`).
     *
     * **Y la segunda: sólo cuenta lo que HOY tiene horario.** Si el año todavía no tiene
     * ninguna columna de día escrita —el primer despliegue de los dieciséis, por ejemplo—,
     * publicar una versión vacía no le quita nada a nadie y **no se pide confirmación**. La
     * puerta se abre por lo que se pierde, no por lo que la versión es: es el mismo criterio
     * que `acepto_perder`, que tampoco salta cuando no se pierde nada.
     *
     * El `OR` de los siete días se arma desde `COLUMNAS_DE_DIA` y no a mano, por lo que dice
     * esa constante: un convenio repetido es un convenio que se puede cambiar a medias.
     */
    private function asignacionesQueSeQuedanSinHorario(int $versionId, int $yearId): int
    {
        $filas = (int) DB::select(
            'SELECT count(*) AS c FROM horario_lecciones WHERE version_id = ?', [$versionId]
        )[0]->c;

        if ($filas !== 0) {
            return 0;
        }

        $conAlgunDia = implode(' OR ', array_map(fn ($c) => "a.{$c} = 1", self::COLUMNAS_DE_DIA));

        return (int) DB::select(
            "SELECT count(*) AS c
               FROM asignaturas a
               INNER JOIN grupos g ON g.id = a.grupo_id AND g.year_id = ? AND g.deleted_at IS NULL
              WHERE a.deleted_at IS NULL AND ({$conAlgunDia})",
            [$yearId]
        )[0]->c;
    }

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
