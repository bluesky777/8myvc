<?php

namespace App\Services;

use App\Models\Nota;
use App\Support\NombreDelAlumno;
use App\Support\Reloj;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Escribir las notas que trae un libro de «notas sin internet». **La otra mitad
 * del ensayo, y ni una decisión más.**
 *
 * Es la fase 2 de `myvc_front/PLAN-NOTAS-SIN-INTERNET.md`. Lo único que hace es
 * aplicar el plan que {@see EnsayoDeLaPlanilla} ya construyó: qué celda va a qué
 * indicador, con qué valor y por qué. **No vuelve a decidir nada** —ni la D3, ni la
 * D9, ni un choque— y eso es el diseño entero, no una comodidad:
 *
 * > Lo que se prometió y lo que se hace salen **del mismo recorrido**. Una copia
 * > de la decisión se separaría del original en la primera corrección y nadie se
 * > enteraría hasta que el informe final no cuadrara con lo prometido.
 *
 * Es la misma lección que el importador de alumnos dejó escrita con
 * `ImporterFixer`, y aquí importa más: allí lo que se perdía era un vocabulario;
 * aquí lo que se perdería es la nota de un alumno.
 *
 * ## Las tres reglas que definen esta clase
 *
 * ### 1 · Siembra la fila que falte, antes de escribir
 *
 * Una casilla que nadie visitó **no tiene fila en `notas`**: la siembra la hace
 * `putDetailed` al abrir la planilla en el navegador, o
 * {@see Nota::verificarCrearNotas} al crear el indicador. Un docente que se lleva
 * el libro y califica un indicador que nunca abrió en la web no tiene esa fila, y
 * `notas/lote` escribe **por `id`**: sin sembrar, esa nota no se guarda y no da
 * error. Se usa el mismo `INSERT … WHERE NOT EXISTS` de
 * `NotasController::putSubunidad`, que es el que ya resuelve la carrera.
 *
 * ### 2 · Recalcula UNA VEZ por (asignatura, periodo), al final
 *
 * `DefinitivasDeAsignatura::recalcularPorNota()` son ~6 consultas; llamarlo 300
 * veces en una importación es lo que tumba la petición, y es exactamente la mitad
 * del ahorro que midió `notas/lote` (3,8×–5,9×). Así que se junta: una llamada por
 * par, con las transacciones de las filas ya cerradas, y **sin `soloAlumno`**
 * —`calcular()` agrega a todos los alumnos del grupo en la misma consulta, así que
 * acotar por alumno sería pedir esa agregación una vez por cada uno—.
 *
 * Y **nunca se escribe `notas_finales` a mano**. Es la regla del doc 10 y no tiene
 * excepciones: la definitiva la calcula el recalculador único o no la calcula nadie.
 *
 * ### 3 · Una transacción por fila de alumno, con el avance dentro
 *
 * Reutiliza {@see PuntoDeControlDeImportacion} tal cual. La garantía es la de
 * siempre: **una fila está aplicada si y sólo si el punto de control la da por
 * hecha**, porque la marca se escribe dentro de la misma transacción que las notas.
 * Volver a subir **el mismo archivo** —la misma huella— continúa por donde iba y no
 * reprocesa ninguna fila.
 *
 * La granularidad es la fila de alumno y no el lote, por lo mismo que allí: lo que
 * se agota es el tiempo, no la memoria, y anotar de N en N obliga a reprocesar
 * hasta N-1 filas al reanudar.
 *
 * ## Las ausencias y las tardanzas (D5, fase 4), que son de otra pasta
 *
 * Entran **en la misma transacción de la fila del alumno** y no por un camino
 * propio, por lo mismo que las filas escritas a mano: lo que se promete y lo que se
 * hace salen del mismo recorrido, y una fila está aplicada si y sólo si el punto de
 * control la da por hecha. Pero lo que escriben **no es una nota**:
 *
 * - **Subir un conteo CREA filas**, una por falta, con `fecha_hora` del día de la
 *   importación. No hay otra fecha posible: la columna del Excel sólo lleva el
 *   total del periodo, así que el día en que el alumno faltó **no está en el
 *   archivo**. Se dice en el libro, en el ensayo y aquí.
 * - **Bajar un conteo BORRA filas**, por el camino blando y **las más recientes
 *   primero** (ver {@see borrarLasMasRecientes}).
 * - Y **el defecto de bajar es no bajar**: si el ensayo no puso la operación en el
 *   plan, aquí no hay nada que borrar. La decisión se toma allí, con el espejo y el
 *   grupo delante; aquí ya no se puede y comprobarla a medias sería peor.
 *
 * **Y no se escriben por `ausencias/*`.** Esas seis rutas las comparte
 * `myvc_flutter`, que es una sola app para los dieciséis colegios y cuyas versiones
 * viejas conviven meses: lo que se le añade a una ruta que usa la móvil viaja a una
 * app que no se puede publicar el mismo día. Lo que sí se copia entera es **su
 * regla de negocio** —el periodo abierto, el tipo, el `entrada`, la fila de
 * auditoría con el nombre del alumno dentro—, que es lo que de verdad hay que
 * compartir.
 *
 * ## Lo que NO escribe, y se dice
 *
 * - **La `Def`**. Es una fórmula orientativa y bloqueada; la definitiva sale del
 *   recalculador.
 * - **Ningún alumno**. Es el encargo literal: *«no debe crear el alumno»*.
 * - **Ninguna fila de un alumno que ya no está matriculado** en el grupo.
 *
 * ## Y las filas escritas a mano (F6), que tampoco son una excepción
 *
 * Una fila del bloque del final que el docente resolvió —*«sí, es José Luis»*—
 * llega aquí **como una fila más del plan**, con su `alumno_id` dentro, y se
 * escribe con la misma siembra, el mismo rastro y el mismo recálculo que las
 * demás. No hay un camino aparte y no puede haberlo: quién es esa persona, que
 * esté matriculada en el grupo de esa hoja y con estado válido, y si su nota pisa
 * una que ya existe —eso es un choque (F7)— lo decide el ensayo, que es quien
 * tiene el grupo delante. **Aquí no se comprueba nada de eso porque aquí ya no se
 * puede**, y comprobarlo a medias sería peor que no hacerlo.
 */
class EscrituraDeNotasImportadas
{
    /**
     * Lo que de verdad se hizo.
     *
     * Las cuatro primeras claves son las que pinta la pantalla del front
     * (`app2/src/app/datos/planilla-offline.ts`); las tres últimas van detrás porque
     * el ensayo no puede prometerlas y hacen falta para leer un incidente: cuántas
     * filas de `notas` hubo que sembrar, cuántos indicadores creó la F9 y por cuántas
     * filas de alumno se pasó.
     *
     * @var array<string, int>
     */
    public array $hechos = [
        'notas_escritas' => 0, 'notas_borradas' => 0,
        'definitivas_recalculadas' => 0, 'filas_descartadas' => 0,
        'filas' => 0, 'filas_sembradas' => 0, 'indicadores_creados' => 0,

        // **Faltas, no notas, y por eso van con nombre propio.** Un total que
        // mezclara «notas escritas» con «faltas creadas» escondería justo lo que hay
        // que poder leer después: *«se crearon 6 ausencias fechadas hoy»* es una frase
        // que alguien puede querer deshacer, y *«entraron 318 cosas»* no lo es.
        'ausencias_creadas' => 0, 'ausencias_borradas' => 0,
    ];

    /** Lo hecho hoja a hoja, para la tabla de «prometido contra hecho». @var list<array<string,mixed>> */
    public array $porHoja = [];

    /** Los indicadores que la F9 creó, para poder enseñarlos. @var list<array<string,mixed>> */
    public array $indicadoresCreados = [];

    /** @var array<string, array{0:int,1:int}> */
    private array $pares = [];

    /** Índice de `porHoja` por nombre de hoja, para no buscar en la lista. @var array<string,int> */
    private array $dondeVaLaHoja = [];

    /**
     * @param  ?string  $porCuentaDe  el docente dueño del libro, **sólo cuando no es
     *                                quien sube** (D4, fase 5). Ver {@see porCuentaDe}.
     */
    public function __construct(
        private object $usuario,
        private PuntoDeControlDeImportacion $punto,
        private ?string $porCuentaDe = null,
    ) {}

    /**
     * **Las dos personas en cada línea de rastro**, cuando son dos.
     *
     * Coordinación puede subir la planilla de un docente (D4), y entonces el
     * `created_by` de la nota es coordinación: el docente cuyo libro entró **no
     * aparecería en ningún sitio**. Es exactamente el dato que hace falta el día
     * que el docente y coordinación no están de acuerdo en qué se subió — el mismo
     * día por el que existe el acta.
     *
     * Devuelve la línea tal cual cuando sube el dueño, que es el caso normal: una
     * nota «por cuenta de sí mismo» sería ruido en todas las demás.
     */
    private function porCuentaDe(Auditoria $linea): Auditoria
    {
        return $this->porCuentaDe === null ? $linea : $linea->porCuentaDe($this->porCuentaDe);
    }

    /**
     * Aplica el plan del ensayo.
     *
     * @param  list<array<string, mixed>>  $plan
     * @param  int  $filasDescartadas  las que el ensayo ya contó como no importables (F6)
     */
    public function aplicar(array $plan, int $filasDescartadas = 0): void
    {
        $this->hechos['filas_descartadas'] = $filasDescartadas;

        // Los nombres de los alumnos, en una consulta por hoja y **fuera de las
        // transacciones**: `Auditoria` los pide uno a uno y sin esto serían una
        // consulta por nota. Es lo mismo que hace `putLote`, y fuera de la
        // transacción porque es una lectura que no gana nada dentro y alarga lo que
        // la transacción tiene abierto.
        foreach ($plan as $hoja) {
            if ($hoja['entra'] !== true) {
                continue;
            }

            $this->aplicarHoja($hoja);
        }

        // **Al final, y una vez por par.** Ver la regla 2 de la cabecera.
        foreach ($this->pares as $par) {
            DefinitivasDeAsignatura::recalcular($par[0], $par[1], $this->usuario->user_id);
            $this->hechos['definitivas_recalculadas']++;
        }
    }

    /** @param array<string, mixed> $hoja */
    private function aplicarHoja(array $hoja): void
    {
        $nombre = (string) $hoja['hoja'];
        $asignaturaId = (int) $hoja['asignatura_id'];
        $periodoId = (int) $hoja['periodo_id'];
        $grupoId = (int) $hoja['grupo_id'];

        if (! isset($this->dondeVaLaHoja[$nombre])) {
            $this->dondeVaLaHoja[$nombre] = count($this->porHoja);
            $this->porHoja[] = [
                'hoja' => $nombre,
                'asignatura' => $hoja['asignatura'] ?? null,
                'asignatura_id' => $asignaturaId,
                'escritas' => 0,
                'borradas' => 0,
            ];
        }

        // F9 primero: los indicadores que el docente pidió crear tienen que existir
        // antes de que se escriba la primera nota suya.
        $creadas = $this->crearLosIndicadores($nombre, $hoja['crear'] ?? [], $grupoId);

        $alumnos = array_map(static fn ($f) => (int) $f['alumno_id'], $hoja['filas']);

        if ($alumnos !== []) {
            NombreDelAlumno::deVarios($alumnos);
        }

        foreach ($hoja['filas'] as $fila) {
            $this->aplicarFila($nombre, $fila, $creadas, $asignaturaId, $periodoId);
        }

        if ($hoja['filas'] !== [] || $creadas !== []) {
            $this->pares[$asignaturaId.':'.$periodoId] = [$asignaturaId, $periodoId];
        }
    }

    /**
     * Una fila de alumno: **una transacción, con el avance anotado dentro**.
     *
     * Ahí está toda la garantía de la reanudación. Si el proceso muere aquí, la
     * fila entera se deshace y su marca con ella, así que al volver a subir el
     * mismo archivo esa fila se repite —entera y una vez— y las anteriores no.
     *
     * **Las faltas van dentro de la misma transacción**, detrás de las notas. Si la
     * fila se deshace, se deshacen con ella: no puede quedar un alumno con dos
     * ausencias creadas y su nota sin escribir, ni la marca del punto de control
     * puesta sobre media fila.
     *
     * @param  array<string, mixed>  $fila
     * @param  array<string, int>  $creadas  columna de reserva => subunidad nueva
     */
    private function aplicarFila(string $hoja, array $fila, array $creadas, int $asignaturaId,
        int $periodoId): void
    {
        $alumnoId = (int) $fila['alumno_id'];
        $indice = (int) $fila['indice'];

        DB::transaction(function () use ($hoja, $fila, $creadas, $asignaturaId, $periodoId, $alumnoId, $indice) {
            $ahora = Reloj::ahora();

            foreach ($fila['celdas'] as $celda) {
                $subunidadId = $celda['subunidad_id'] !== null
                    ? (int) $celda['subunidad_id']
                    : ($creadas[$celda['reserva']] ?? null);

                if ($subunidadId === null) {
                    // La columna de reserva no llegó a crearse (el indicador ya
                    // existía con otro nombre, o la creación falló). No se escribe a
                    // ciegas en un indicador que no se sabe cuál es.
                    continue;
                }

                $this->escribirLaNota($hoja, $alumnoId, (int) $subunidadId, $celda['valor'], $periodoId, $ahora);
            }

            // Y las faltas (F8/D5), **detrás de las notas y dentro de la misma
            // transacción**. Sólo llegan aquí las que el ensayo decidió aplicar: una
            // bajada sin su decisión puesta no produce operación, así que aquí no hay
            // nada que borrar y no hace falta volver a preguntarlo.
            foreach (($fila['asistencia'] ?? []) as $operacion) {
                $this->aplicarElConteo($alumnoId, $asignaturaId, $periodoId, $operacion, $ahora);
            }

            // **Dentro de la transacción, no después.** Llamarla fuera reabre justo el
            // agujero que esto cierra: el proceso muere entre el commit de la fila y
            // el de su marca, y al reanudar la fila se repite.
            $this->punto->anotar($hoja, $indice);
        });

        $this->hechos['filas']++;
    }

    /**
     * Una nota: sembrar si hace falta, escribir, y dejar los dos rastros.
     *
     * El `INSERT … WHERE NOT EXISTS` es el de `NotasController::putSubunidad` y
     * **siembra con `nota` en `NULL`**, que es lo que vale «sin calificar» desde
     * `2026_09_19_500000_la_casilla_vacia`. Sembrar con un cero le regalaría al
     * alumno una nota que nadie puso.
     */
    private function escribirLaNota(string $hoja, int $alumnoId, int $subunidadId, ?int $valor,
        int $periodoId, Carbon $ahora): void
    {
        $sembrada = DB::insert(
            'INSERT INTO notas (subunidad_id, alumno_id, nota, created_by, created_at, updated_at)
             SELECT * FROM
             (SELECT ? AS subunidad_id, ? AS alumno_id, NULL AS nota, ? AS created_by, ? AS created_at, ? AS updated_at) AS tmp
              WHERE NOT EXISTS (
                  SELECT * FROM notas WHERE subunidad_id = ? AND alumno_id = ? AND deleted_at IS NULL
              ) LIMIT 1',
            [$subunidadId, $alumnoId, $this->usuario->user_id, $ahora, $ahora, $subunidadId, $alumnoId]
        );

        if ($sembrada) {
            $this->hechos['filas_sembradas']++;
        }

        $nota = DB::selectOne(
            'SELECT id, nota FROM notas WHERE subunidad_id = ? AND alumno_id = ? AND deleted_at IS NULL LIMIT 1',
            [$subunidadId, $alumnoId]
        );

        if ($nota === null) {
            return;
        }

        $anterior = $nota->nota === null ? null : (int) $nota->nota;

        DB::update(
            'UPDATE notas SET nota = ?, updated_by = ?, updated_at = ? WHERE id = ?',
            [$valor, $this->usuario->user_id, $ahora, (int) $nota->id]
        );

        $historialId = isset($this->usuario->historial_id) && is_numeric($this->usuario->historial_id)
            ? (int) $this->usuario->historial_id
            : null;

        // El rastro viejo, exactamente como lo escribe `putLote`: los cuatro clientes
        // leen `bitacoras` y una importación que no dejara su línea sería la única
        // forma de cambiar una nota sin que la pantalla de historial lo cuente.
        DB::insert(
            'INSERT INTO bitacoras (created_by, historial_id, affected_user_id, affected_person_type,
                affected_element_type, affected_element_id, affected_element_new_value_int,
                affected_element_old_value_int, created_at)
             VALUES (?, ?, ?, "Al", "Nota", ?, ?, ?, ?)',
            [$this->usuario->user_id, $historialId, $alumnoId, (int) $nota->id, $valor, $anterior, $ahora]
        );

        // Y el rastro nuevo (18 §4), **dentro de la transacción de la fila**: si la
        // fila se deshace, las líneas se deshacen con ella. Una línea por nota y no
        // una por archivo: la pregunta que la tabla contesta es «quién tocó ESTA
        // nota», y la respuesta tiene que poder ser «una planilla de Excel».
        $this->porCuentaDe(
            Auditoria::registrar()
                ->editar('nota', (int) $nota->id)
                ->deAlumno($alumnoId, NombreDelAlumno::de($alumnoId))
                ->en(periodo: $periodoId)
                ->de($anterior)
                ->a($valor)
        )->guardar();

        if ($valor === null) {
            $this->hechos['notas_borradas']++;
            $this->porHoja[$this->dondeVaLaHoja[$hoja]]['borradas']++;

            return;
        }

        $this->hechos['notas_escritas']++;
        $this->porHoja[$this->dondeVaLaHoja[$hoja]]['escritas']++;
    }

    /**
     * Un conteo de asistencia que cambió: crear faltas o borrarlas (F8 / D5).
     *
     * @param  array{tipo:string, tipo_db:string, direccion:string, cuantas:int}  $operacion
     */
    private function aplicarElConteo(int $alumnoId, int $asignaturaId, int $periodoId, array $operacion,
        Carbon $ahora): void
    {
        if ($operacion['cuantas'] <= 0) {
            return;
        }

        if ($operacion['direccion'] === RespuestasDeLaPlanilla::SUBE) {
            $this->crearLasFaltas($alumnoId, $asignaturaId, $periodoId, $operacion, $ahora);

            return;
        }

        $this->borrarLasMasRecientes($alumnoId, $asignaturaId, $periodoId, $operacion, $ahora);
    }

    /**
     * Subir el conteo: **una fila por falta, fechada el día de la importación**.
     *
     * ## La fecha, que es lo que hay que decir en voz alta
     *
     * `fecha_hora` es la de hoy porque **el día en que el alumno faltó no está en el
     * archivo**: la columna del Excel lleva el total del periodo y nada más. Así que
     * tres faltas subidas de golpe salen las tres con la fecha de la importación, y
     * quien mire después la planilla de ausencias del acudiente verá tres el mismo
     * día. **Eso no es un fallo: es el precio de la columna**, y por eso está escrito
     * en el comentario de la celda, en el renglón del ensayo y aquí.
     *
     * ## Y las reglas de negocio, copiadas de `postAgregarAusencia` una por una
     *
     * - `cantidad_ausencia = 1` (o `cantidad_tardanza`), que es lo que hace que
     *   **contar filas** y **sumar cantidades** den el mismo número para lo que esto
     *   escribe. Las dos formas conviven en este proyecto y dan resultados distintos;
     *   la que manda aquí es la que el libro imprimió: `COUNT(*)`.
     * - `entrada = 0`. La columna del libro es de **una asignatura**, o sea faltas a
     *   clase, no de portería. Medido sobre `caz_zaragoza` el 21 sep 2026: las 352
     *   filas con `entrada = 1` tienen las 352 `asignatura_id` a null, así que una
     *   falta de portería no entra en el total del libro ni puede salir de aquí.
     * - `tipo` explícito, que es por lo que filtran los cuatro lectores.
     * - `created_by` es quien sube, que es quien responde por la falta.
     *
     * @param  array{tipo:string, tipo_db:string, direccion:string, cuantas:int}  $operacion
     */
    private function crearLasFaltas(int $alumnoId, int $asignaturaId, int $periodoId, array $operacion,
        Carbon $ahora): void
    {
        $esTardanza = $operacion['tipo_db'] === 'tardanza';

        for ($i = 0; $i < $operacion['cuantas']; $i++) {
            DB::insert(
                'INSERT INTO ausencias (alumno_id, asignatura_id, periodo_id, cantidad_ausencia,
                    cantidad_tardanza, entrada, tipo, fecha_hora, created_by, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?)',
                [$alumnoId, $asignaturaId, $periodoId,
                    $esTardanza ? null : 1, $esTardanza ? 1 : null,
                    $operacion['tipo_db'], $ahora, $this->usuario->user_id, $ahora, $ahora]
            );

            $this->anotarLaFalta(Auditoria::CREAR, (int) DB::getPdo()->lastInsertId(), $alumnoId,
                $asignaturaId, $periodoId, $operacion, $ahora);

            $this->hechos['ausencias_creadas']++;
        }
    }

    /**
     * Bajar el conteo: **las más recientes primero, y por el camino blando**.
     *
     * ## Las tres decisiones, con su motivo escrito
     *
     * **1 · Las más recientes.** Borrar la más antigua tiraría el registro que más se
     * consulta: la falta vieja es la que sostiene una citación al acudiente, un
     * proceso disciplinario o un reclamo de meses atrás, y la reciente es la que
     * todavía se puede recordar y volver a anotar con su día. Y hay un argumento más
     * fuerte: **la falta que esta misma importación acaba de crear —fechada hoy— es
     * la más reciente de todas**, así que corregir un error de tecleo subiendo y
     * bajando deshace lo que se acaba de hacer, en vez de morderle un día real al
     * historial.
     *
     * **2 · Pero LAS QUE NO TIENEN FECHA VAN LAS PRIMERAS**, y esto no estaba en el
     * plan: lo destapó una medición. *Contado sobre `caz_zaragoza` el 21 sep 2026,
     * filas vivas:*
     *
     * | Población | Filas | Sin `fecha_hora` |
     * |---|---|---|
     * | Faltas de clase (`entrada = 0`, con asignatura) — **las que el libro cuenta** | 544 | **420 (77 %)** |
     * | Faltas de portería (`entrada = 1`) — el libro no las ve | 352 | 0 |
     *
     * O sea que **tres de cada cuatro filas que esta columna puede borrar no están
     * en ningún día**. `AusenciasController::putDeAlumno` ya lo tenía escrito —*«hay
     * filas que cuentan en los totales y no están en ningún día»*— y cambia la
     * conclusión: dejando el orden natural de MySQL, que manda los `NULL` al final
     * en un `DESC`, bajar un conteo se llevaría por delante las pocas filas fechadas
     * y **dejaría intactas las 420 que no dicen nada**. Sería destruir la única
     * información que hay para conservar la que no existe.
     *
     * Así que el orden es `(fecha_hora IS NULL) DESC, fecha_hora DESC, id DESC`:
     * primero lo que no tiene nada que perder, y sólo después la regla 1. Sirve
     * **mejor** al motivo de la regla 1 que su lectura literal — lo que se protege
     * es el registro que alguien puede consultar, y una falta sin día no sale en
     * ninguna consulta por fecha.
     *
     * **3 · `deleted_at`, nunca `DELETE`.** Toda esta familia borra en blando y la
     * fila borrada es justo lo que se mira cuando alguien reclama. Con `deleted_by`
     * puesto, que es lo que la §deleteDestroy de `AusenciasController` dejó escrito
     * el 22 ago 2026: en la copia de producción de ese día había 5.689 ausencias
     * borradas y 5.684 sin autor.
     *
     * @param  array{tipo:string, tipo_db:string, direccion:string, cuantas:int}  $operacion
     */
    private function borrarLasMasRecientes(int $alumnoId, int $asignaturaId, int $periodoId, array $operacion,
        Carbon $ahora): void
    {
        $candidatas = DB::select(
            'SELECT id, fecha_hora FROM ausencias
              WHERE alumno_id = ? AND asignatura_id = ? AND periodo_id = ? AND tipo = ?
                AND deleted_at IS NULL
              ORDER BY (fecha_hora IS NULL) DESC, fecha_hora DESC, id DESC
              LIMIT '.(int) $operacion['cuantas'],
            [$alumnoId, $asignaturaId, $periodoId, $operacion['tipo_db']]
        );

        foreach ($candidatas as $falta) {
            DB::update(
                'UPDATE ausencias SET deleted_by = ?, deleted_at = ?, updated_at = ? WHERE id = ?',
                [$this->usuario->user_id, $ahora, $ahora, (int) $falta->id]
            );

            // **Con la fecha que la falta TENÍA**, y por eso se lee antes de borrar: la
            // pregunta que el colegio hace cuando alguien reclama es «qué falta se
            // borró», y la respuesta es el día que ya no está en ninguna pantalla.
            $this->anotarLaFalta(Auditoria::BORRAR, (int) $falta->id, $alumnoId, $asignaturaId, $periodoId,
                $operacion, $falta->fecha_hora);

            $this->hechos['ausencias_borradas']++;
        }
    }

    /**
     * La línea de auditoría de una falta importada.
     *
     * **Sólo `auditoria`, y no `bitacoras`**, que es lo que hacen las seis rutas de
     * `ausencias/*` desde el 22 ago 2026 (`AusenciasController::anotar`). No es una
     * excepción de esta clase: `bitacoras` **no tiene vocabulario para una falta** —
     * sus `affected_element_type` son `Nota`, `NF_UPDATE`, `Nueva subunidad`…— y las
     * dos pantallas del front que la leen buscan por tipo. Un tipo nuevo ahí sería
     * una fila que nadie ve, en la tabla que el doc 18 está retirando.
     *
     * Lo que sí se copia entero es lo demás: el **nombre del alumno congelado
     * dentro** —sin él la frase de serie dice «Fulano creó ausencia 4821», que no se
     * puede leer—, la asignatura y el periodo, y el valor completo de la falta.
     *
     * Y **`origen`**, que es lo único que esta línea añade a las de las seis rutas:
     * es la marca de que la falta vino de una planilla y no de la pantalla de
     * asistencia. Ver la §de las ausencias en `docs/migracion/51` para dónde NO está
     * esa marca, que es la fila de `ausencias` misma.
     *
     * @param  array{tipo:string, tipo_db:string, direccion:string, cuantas:int}  $operacion
     */
    private function anotarLaFalta(string $accion, int $id, int $alumnoId, int $asignaturaId, int $periodoId,
        array $operacion, mixed $fechaHora): void
    {
        $esTardanza = $operacion['tipo_db'] === 'tardanza';

        $valor = [
            'tipo' => $operacion['tipo_db'],
            'fecha_hora' => (string) $fechaHora,
            'cantidad_ausencia' => $esTardanza ? null : 1,
            'cantidad_tardanza' => $esTardanza ? 1 : null,
            'origen' => 'planilla sin internet',
        ];

        $linea = $this->porCuentaDe(
            Auditoria::registrar()
                ->deAlumno($alumnoId, NombreDelAlumno::de($alumnoId))
                ->en(asignatura: $asignaturaId, periodo: $periodoId)
        );

        if ($accion === Auditoria::BORRAR) {
            $linea->borrar('ausencia', $id)->de($valor);
        } else {
            $linea->crear('ausencia', $id)->a($valor);
        }

        $linea->guardar();
    }

    /**
     * F9: crear los indicadores que el docente escribió en columnas de reserva.
     *
     * ## Por qué esto tiene que ser idempotente, y no basta con la transacción
     *
     * Crear el indicador pasa **una vez por columna, antes del bucle de filas**, así
     * que no cabe en «una transacción por fila». Si el proceso muere entre la
     * creación y la primera fila, el punto de control no ha anotado nada y la
     * siguiente subida del mismo archivo volvería a crear el indicador: **dos
     * columnas con el mismo nombre**, y en modo `porcentaje` una unidad que suma de
     * más.
     *
     * Así que antes de crear se busca uno vivo con **la misma definición en la misma
     * unidad** y, si está, se reutiliza. Es idempotencia por nombre, que es la única
     * llave que el archivo trae — y la misma idea con la que el importador de
     * alumnos es idempotente por documento.
     *
     * @param  list<array<string, mixed>>  $crear
     * @return array<string, int> columna de reserva => subunidad
     */
    private function crearLosIndicadores(string $hoja, array $crear, int $grupoId): array
    {
        $creadas = [];

        foreach ($crear as $nueva) {
            $unidadId = (int) $nueva['unidad_id'];
            $nombre = (string) $nueva['nombre'];

            $yaEsta = DB::selectOne(
                'SELECT id FROM subunidades
                  WHERE unidad_id = ? AND deleted_at IS NULL AND definicion = ? LIMIT 1',
                [$unidadId, $nombre]
            );

            if ($yaEsta !== null) {
                $creadas[(string) $nueva['columna']] = (int) $yaEsta->id;

                continue;
            }

            $creadas[(string) $nueva['columna']] = $this->crearElIndicador($hoja, $nueva, $grupoId);
        }

        return $creadas;
    }

    /** @param array<string, mixed> $nueva */
    private function crearElIndicador(string $hoja, array $nueva, int $grupoId): int
    {
        $unidadId = (int) $nueva['unidad_id'];
        $nombre = (string) $nueva['nombre'];
        $peso = (int) $nueva['peso'];

        return DB::transaction(function () use ($hoja, $unidadId, $nombre, $peso, $grupoId) {
            $ahora = Reloj::ahora();

            $cuantas = DB::selectOne(
                'SELECT COUNT(*) AS n FROM subunidades WHERE unidad_id = ? AND deleted_at IS NULL',
                [$unidadId]
            );

            DB::insert(
                'INSERT INTO subunidades (definicion, porcentaje, unidad_id, nota_default, orden,
                    created_by, created_at, updated_at)
                 VALUES (?, ?, ?, 0, ?, ?, ?, ?)',
                [$nombre, $peso, $unidadId, (int) ($cuantas->n ?? 0), $this->usuario->user_id, $ahora, $ahora]
            );

            $id = (int) DB::getPdo()->lastInsertId();

            $historialId = isset($this->usuario->historial_id) && is_numeric($this->usuario->historial_id)
                ? (int) $this->usuario->historial_id
                : null;

            DB::insert(
                'INSERT INTO bitacoras (created_by, historial_id, affected_element_type, affected_element_id,
                    affected_element_new_value_string, created_at)
                 VALUES (?, ?, "Nueva subunidad", ?, ?, ?)',
                [$this->usuario->user_id, $historialId, $id, $nombre.' -- '.$peso.'%', $ahora]
            );

            $this->porCuentaDe(
                Auditoria::registrar()
                    ->crear('subunidad', $id)
                    ->a(['definicion' => $nombre, 'porcentaje' => $peso, 'origen' => 'planilla sin internet'])
            )->guardar();

            // **La subunidad y sus notas nacen juntas** (§5.1 del doc 10). Sin esto
            // queda una ventana en la que la definitiva se guarda sin el aporte del
            // indicador nuevo, y si el docente bajó los pesos de los demás para hacerle
            // sitio, baja el doble.
            $subunidad = (object) ['id' => $id, 'unidad_id' => $unidadId];

            if ($grupoId > 0) {
                Nota::verificarCrearNotas($grupoId, $subunidad, $this->usuario->user_id);
            }

            $this->hechos['indicadores_creados']++;
            $this->indicadoresCreados[] = [
                'hoja' => $hoja, 'subunidad_id' => $id, 'definicion' => $nombre, 'porcentaje' => $peso,
                'unidad_id' => $unidadId,
            ];

            return $id;
        });
    }
}
