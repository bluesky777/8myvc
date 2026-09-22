<?php

namespace App\Services;

use App\Models\Grupo;
use App\Support\Autoriza;
use App\Support\EscalaDeNotas;
use App\Support\ParecidoDeNombres;
use App\Support\RepartoDeLaNota;
use Illuminate\Support\Facades\DB;

/**
 * Qué va a pasar si se sube este libro de notas — **sin escribir una sola fila**.
 *
 * Es la fase 2 de `myvc_front/PLAN-NOTAS-SIN-INTERNET.md` y el hermano de
 * {@see EnsayoDeLaImportacion}, del que se hereda la máquina entera: la llave de
 * una decisión es **el valor** y no la fila, el plan se declara completo o
 * recortado, y **esto no escribe nada**.
 *
 * ## La regla dura: esto NO escribe
 *
 * Ni un `INSERT`, ni un `UPDATE`, ni un `DELETE`, ni una transacción. La única
 * escritura de toda la familia `planilla-offline` sigue siendo la fila de
 * auditoría de la descarga. Si algún día alguien mete una escritura aquí, el botón
 * que dice «esto no toca nada» pasa a mentir en la pantalla donde la gente pulsa
 * **antes** de decidir.
 *
 * ## LA D3 ES LA RAZÓN DE SER DE TODO ESTO
 *
 * *«Sólo entra lo que el docente cambió.»* Una celda que no tocó no se escribe
 * **aunque en la web haya cambiado**. Y eso no se puede saber mirando el archivo:
 * el servidor vería un 45 y no sabría si lo escribió hoy o si ya estaba cuando se
 * descargó el libro.
 *
 * Por eso la comparación es de **tres puntas**:
 *
 * | | De dónde sale | Qué significa |
 * |---|---|---|
 * | **espejo** | la hoja oculta `_myvc`, firmada | lo que había **al descargar** |
 * | **base** | `notas` ahora mismo | lo que hay **hoy** |
 * | **archivo** | la celda de la hoja | lo que el docente trae |
 *
 * Y las tres reglas que salen de ahí, que son el corazón de esta clase:
 *
 * 1. `archivo === espejo` → **no se escribe**, pase lo que pase con la base. El
 *    docente no tocó esa casilla.
 * 2. `archivo !== espejo` y `base === espejo` → **se escribe**. Es el caso normal
 *    y es «el trabajo», no un problema.
 * 3. `archivo !== espejo` **y** `base !== espejo` **y** `archivo !== base` →
 *    **choque** (F7). Se enseña antes de aplicar, y por defecto **manda el
 *    sistema**.
 *
 * ## Y LA D9: una casilla vacía NO borra
 *
 * Vacía significa «no la toques». Para borrar una nota se escribe **un guion**, y
 * eso pone `notas.nota` a `NULL` —que desde `2026_09_19_500000_la_casilla_vacia`
 * es «sin calificar» y no un cero—. La regla va impresa en la portada del libro
 * por ser la única que no se adivina sola.
 *
 * Sin esto, un docente que baja el libro, escribe las notas de un Logro y sube
 * **borraría todo lo demás**: la planilla viene llena (D2) y una hoja recortada o
 * pegada en Google Sheets puede traer huecos donde había notas.
 *
 * ## Los peldaños, y por qué el 2 no compara
 *
 * {@see LaPlanillaQueSeSube} decide el peldaño. Aquí sólo cambia una cosa, y es la
 * que importa: **en el peldaño 2 el espejo no se usa**. Con la firma rota, quien
 * tocó la hoja oculta pudo escribir en el espejo el valor que quisiera, así que la
 * comparación de tres puntas *seguiría funcionando y mintiendo*. Lo que se hace en
 * su lugar es lo que dice la §4.7: **todas** las celdas con valor cuentan como
 * cambiadas, y hay que confirmar la lista.
 *
 * ## La forma de la respuesta la fija el front
 *
 * El diagnóstico se sirve con **las interfaces de
 * `app2/src/app/datos/planilla-offline.ts`**, que la pantalla de
 * `notas/sin-internet/subir` ya tiene construidas y probadas. Dos consecuencias que
 * no son cosmética y que gobiernan casi todo el código de abajo:
 *
 * - **`si_no_hago_nada` lo escribe el servidor.** Es la columna «qué pasa si no
 *   hago nada» del mock, y es lo que convierte un aviso en una decisión informada.
 *   El front no puede deducirla: sólo aquí se sabe qué hará el importador con esa
 *   columna o con ese valor.
 * - **`decidible` separa «hay que elegir» de «esto es informativo».** Un peso que
 *   cambió o un indicador nuevo no tienen respuesta posible, y sin ese campo la
 *   pantalla pintaría un desplegable para algo que no se decide.
 *
 * ## El tope de tiempo
 *
 * El mismo de `EnsayoDeLaImportacion` y por el mismo motivo: **el ensayo no
 * escribe, así que no puede reanudarse — o cabe o se recorta**. Un plan sobre las
 * primeras N filas, marcado como incompleto, es infinitamente mejor que un 500 que
 * además llega al navegador sin cabeceras de CORS.
 *
 * Lo que aquí todavía **no está medido** es dónde cae el corte: el doc 49 §2.3
 * cronometró la descarga (26 hojas, 1,6 s) y dejó escrito que *«el ensayo de la
 * fase 2 es otra medición y sigue sin hacerse»*. Sigue sin hacerse contra datos
 * de verdad; el tope está por si acaso, no por una medida.
 */
class EnsayoDeLaPlanilla
{
    /** Cuándo hay que dejar de estudiar filas, en segundos desde que empezó. */
    public const SEGUNDOS = 20;

    /** Cuántos sitios se enseñan de un mismo valor problemático. */
    private const EJEMPLOS = 12;

    /** Si se recortó por tiempo. Lo que hace que el plan se declare incompleto. */
    public bool $recortado = false;

    /** Filas de alumno estudiadas de verdad. */
    public int $filasEstudiadas = 0;

    /** Filas de alumno que tiene el libro, contadas aunque ya no se estudien. */
    public int $filasDelLibro = 0;

    /** Filas que ya estaban aplicadas de una tanda anterior (sólo al importar). */
    public int $filasYaHechas = 0;

    /**
     * El plan de escritura, hoja a hoja. Es lo que consume
     * {@see EscrituraDeNotasImportadas}, y **no se calcula dos veces**: lo que se
     * enseña y lo que se escribe salen del mismo recorrido.
     *
     * @var list<array<string, mixed>>
     */
    public array $plan = [];

    /** Cuándo se acaba el tiempo. */
    private float $limite;

    /** @var list<array<string, mixed>> */
    private array $bloqueos = [];

    /** @var list<array<string, mixed>> */
    private array $bloqueosResueltos = [];

    /** Coordinación confirmó que sube por otro (D4). Sin esto, `EscrituraDeNotasImportadas` no escribe. */
    private bool $porOtroConfirmado = false;

    /** @var list<array<string, mixed>> */
    private array $hojas = [];

    /**
     * F4, llaveado por el texto del valor.
     *
     * `array-key` y no `string`: PHP convierte a entero la clave de un array cuando
     * el texto es un número, así que un `"55"` acaba siendo la clave `55`. Decir
     * `string` aquí no es una preferencia de tipo, es una afirmación falsa.
     *
     * @var array<array-key, array<string, mixed>>
     */
    private array $celdas = [];

    /** F5, llaveado por el texto del valor. Ver arriba lo de `array-key`.
     * @var array<array-key, array<string, mixed>> */
    private array $escala = [];

    /** @var list<array<string, mixed>> F2. */
    private array $estructura = [];

    /** @var list<array<string, mixed>> F7. */
    private array $choques = [];

    /** @var list<array<string, mixed>> F9. */
    private array $reserva = [];

    /**
     * F6 — las filas que no se reconocen.
     *
     * **La única familia de todo el ensayo cuya llave es la fila**, y lo es porque
     * cada fila es una persona distinta: agrupar por valor —que es lo que salva las
     * demás pantallas— aquí sería preguntar por dos personas a la vez.
     *
     * @var list<array<string, mixed>>
     */
    private array $filas = [];

    /**
     * F8 — los conteos de ausencias y tardanzas que cambiaron.
     *
     * **Desde la fase 4 son decisiones y ya no avisos.** Su llave es la columna
     * —`hoja` + `tipo`— partida además **por la dirección**, que es lo que ninguna
     * otra familia necesita: subir y bajar no valen lo mismo ni tienen el mismo
     * defecto.
     *
     * @var list<array<string, mixed>>
     */
    private array $ausencias = [];

    /** @var array<string, int> */
    private array $totales = [
        'casillas' => 0, 'cambiaron' => 0, 'entran' => 0, 'se_borran' => 0,
        'se_quedan_fuera' => 0, 'filas_descartadas' => 0, 'definitivas_a_recalcular' => 0,

        // **Aparte de `casillas` y de `cambiaron`, y no por orden**: una falta no es
        // una casilla de nota y sumarla al mismo montón rompería la igualdad
        // `cambiaron = entran + se_borran + se_quedan_fuera` que la pantalla usa para
        // que sus tres números sumen el total (decisión (j) del doc 50).
        'ausencias_que_suben' => 0, 'ausencias_que_bajan' => 0,
    ];

    /** @var list<array<string, mixed>> */
    private array $porHoja = [];

    /**
     * Los pares (asignatura, periodo) que tocará recalcular — **los que el
     * importador va a recalcular de verdad**, no las hojas que se leyeron. El
     * predicado está en {@see estudiarHoja} y es el de
     * {@see EscrituraDeNotasImportadas::aplicarHoja}, palabra por palabra.
     *
     * @var array<string, bool>
     */
    private array $pares = [];

    /** Caché de `user_id` => nombre, para «lo cambió Fulano». @var array<int, ?string> */
    private array $quienes = [];

    private ?object $periodoDelLibro = null;

    private bool $colegioOk = false;

    private bool $esMio = false;

    private ?object $profesorDelLibro = null;

    /**
     * @param  object  $usuario  el contexto del token (`User::fromToken()`)
     * @param  ?PuntoDeControlDeImportacion  $punto  sólo al importar: para saltarse lo ya hecho
     */
    public function __construct(
        private LaPlanillaQueSeSube $lector,
        private RespuestasDeLaPlanilla $respuestas,
        private object $usuario,
        ?float $segundos = null,
        private ?PuntoDeControlDeImportacion $punto = null,
    ) {
        $this->limite = microtime(true)
            + ($segundos ?? (float) config('importacion.segundos_del_ensayo', self::SEGUNDOS));
    }

    /**
     * Estudia el libro entero y devuelve el diagnóstico.
     *
     * El orden no es casual: **primero lo que tira el libro entero** (F1 y los
     * peldaños) y sólo después las hojas. Un libro de otro colegio no tiene por qué
     * enseñar los nombres de treinta alumnos que no son de quien lo subió.
     *
     * @return array<string, mixed>
     */
    public function estudiar(): array
    {
        $this->mirarElPeldano();
        $this->mirarElLibro();

        if ($this->bloqueos === []) {
            foreach ($this->lector->mapas as $mapa) {
                $this->estudiarHoja($mapa);
            }
        }

        $this->totales['definitivas_a_recalcular'] = count($this->pares);

        return $this->diagnostico();
    }

    /**
     * Lo que hay que decir en voz alta después de escribir. **Frases, no números.**
     *
     * La respuesta de la importación las pinta tal cual, así que aquí se escriben ya
     * legibles: qué hoja se quedó fuera y por qué, qué filas se descartaron, qué
     * ausencias no entraron y cuántas casillas escritas se quedaron en el camino.
     *
     * Existe porque el resumen de la fase 2 **no es un total**: «entraron 312 notas»
     * no le dice nada a nadie si además se cayó una hoja entera y nadie lo dijo.
     *
     * @return list<string>
     */
    public function avisos(): array
    {
        $avisos = [];

        foreach ($this->hojas as $hoja) {
            if ($hoja['fuera'] && $hoja['motivo_fuera'] !== null) {
                $avisos[] = '«'.$hoja['nombre'].'»: '.$hoja['motivo_fuera'];
            }
        }

        foreach ($this->filas as $fila) {
            // La que se resolvió no se cuenta: sus notas entraron, y un aviso que
            // dice «esta fila no se importa» detrás de una fila que sí se importó es
            // peor que no decir nada.
            if (($fila['resuelta'] ?? false) === true) {
                continue;
            }

            $avisos[] = '«'.$fila['hoja'].'»: '.$fila['titulo'].'. '.$fila['si_no_hago_nada'];
        }

        // **Sólo los conteos que NO entraron**, que es la mitad que hay que decir en
        // voz alta: los que sí entraron ya se cuentan en `hechos`. Es la misma regla
        // que las filas resueltas de la F6 — un aviso detrás de algo que sí se hizo
        // es peor que no decir nada.
        foreach ($this->ausencias as $renglon) {
            if ($renglon['decision'] === RespuestasDeLaPlanilla::AUSENCIAS_APLICAR) {
                continue;
            }

            $avisos[] = '«'.$renglon['hoja'].'» '.($renglon['alumno'] ?? 'alumno '.$renglon['alumno_id'])
                .' ('.$renglon['tipo'].'): '.$renglon['si_no_hago_nada'];
        }

        foreach ($this->celdas as $renglon) {
            if (($renglon['decision'] ?? null) !== RespuestasDeLaPlanilla::CELDA_INTERPRETAR) {
                $avisos[] = $renglon['veces'].' casilla(s) con «'.$renglon['valor'].'» no entraron. '
                    .$renglon['motivo'];
            }
        }

        foreach ($this->escala as $renglon) {
            if (($renglon['decision'] ?? null) !== RespuestasDeLaPlanilla::ESCALA_TOPAR) {
                $avisos[] = $renglon['veces'].' nota(s) con el valor '.$renglon['valor']
                    .' no entraron: no caben en la escala de este año.';
            }
        }

        foreach ($this->reserva as $renglon) {
            if ($renglon['se_crea'] || $renglon['notas'] === 0) {
                continue;
            }

            $avisos[] = '«'.$renglon['hoja'].'» columna '.$renglon['columna'].': '.$renglon['si_no_hago_nada'];
        }

        return $avisos;
    }

    // ── Lo que tira el libro entero ──────────────────────────────────────────

    /**
     * Los peldaños de la §4.7 que esta fase no trabaja.
     *
     * El 3 y el 4 **tienen arreglo y llegan en la fase 3**; el 5 no lo tiene y por
     * eso su motivo lleva la salida escrita dentro —«descargue el libro otra vez»—.
     * El peldaño 5 es el que evita el callejón sin salida: un error que no ofrece
     * salida obliga a llamar por teléfono.
     */
    private function mirarElPeldano(): void
    {
        if ($this->lector->peldano === 5) {
            $this->bloqueos[] = [
                'tipo' => 'peldano_5',
                'hoja' => null,
                'motivo' => $this->lector->motivo
                    ?? 'Este archivo no se reconoce como una planilla de MyVc. Descargue el libro otra vez '
                       .'desde Académico → Trabajar sin internet y escriba las notas sobre ése.',
            ];

            return;
        }

        if ($this->lector->peldano === 3 || $this->lector->peldano === 4) {
            $this->bloqueos[] = [
                'tipo' => 'peldano_'.$this->lector->peldano,
                'hoja' => null,
                'motivo' => ($this->lector->peldano === 3
                    ? 'El archivo perdió la hoja interna con el mapa, pero conserva la columna ID. '
                        .'Reconstruirlo por ID es la fase 3 y todavía no está. '
                    : 'El archivo perdió la hoja interna con el mapa y tampoco tiene la columna ID. '
                        .'Casar a los alumnos por nombre es la fase 3 y todavía no está. ')
                    .'Descargue el libro otra vez y copie las notas sobre él.',
            ];

            return;
        }

        if ($this->lector->peldano !== 2) {
            return;
        }

        // Peldaño 2: el archivo se modificó por fuera. **Se sigue**, pero no se
        // compara contra el espejo y hay que confirmar la lista entera. Es un
        // bloqueo que la persona resuelve, no un fin del camino — la misma forma
        // que `hoja_sin_grupo` en el importador de alumnos.
        $bloqueo = [
            'tipo' => 'firma_rota',
            'hoja' => null,
            'motivo' => 'La hoja interna del archivo se modificó por fuera, así que no se puede saber qué '
                .'casillas tocó usted y cuáles ya venían llenas. Se pueden subir igual, pero todas las '
                .'casillas con valor cuentan como cambiadas: revise la lista antes de confirmar.',
        ];

        if ($this->respuestas->confirmaLaFirmaRota()) {
            $bloqueo['resuelto_por'] = 'confirmo';
            $this->bloqueosResueltos[] = $bloqueo;

            return;
        }

        $this->bloqueos[] = $bloqueo;
    }

    /**
     * F1: **el libro no es de aquí**.
     *
     * Tres preguntas y las tres son bloqueantes, que es lo que la separa de la F3:
     * un periodo cerrado se lleva por delante su hoja y nada más, pero un libro de
     * otro colegio, de otro año o de otro docente no tiene ni una hoja que se pueda
     * escribir.
     *
     * **`es_mio` no mira el permiso de coordinación a propósito.** La D4 —«subir
     * por otro»— es la fase 5, y dejarla entreabierta aquí sería escribir notas en
     * nombre de alguien sin el acta que esa fase trae. Se bloquea con el motivo
     * escrito, no en silencio.
     */
    private function mirarElLibro(): void
    {
        if ($this->lector->cabecera === null) {
            return;
        }

        $cabecera = $this->lector->cabecera;
        $periodoId = (int) ($cabecera['periodo_id'] ?? 0);

        $this->periodoDelLibro = DB::selectOne(
            'SELECT p.id, p.numero, p.year_id, p.profes_pueden_editar_notas, y.year
               FROM periodos p
               INNER JOIN years y ON y.id = p.year_id AND y.deleted_at IS NULL
              WHERE p.id = ? AND p.deleted_at IS NULL',
            [$periodoId]
        );

        $this->colegioOk = $this->periodoDelLibro !== null
            && (int) $this->periodoDelLibro->year_id === (int) ($cabecera['year_id'] ?? 0)
            && (int) $this->periodoDelLibro->year === (int) ($cabecera['year'] ?? 0);

        if (! $this->colegioOk) {
            // **Un solo bloqueo para «otro colegio» y «otro año», y es honesto.**
            // Desde aquí no se pueden distinguir: cada colegio tiene su base y su
            // `APP_KEY`, así que un periodo que no existe es lo mismo que un periodo
            // de otra base. Inventar dos motivos sería fingir que se sabe cuál.
            $this->bloqueos[] = [
                'tipo' => 'libro_de_otro_sitio',
                'hoja' => null,
                'motivo' => 'Este libro no es de este colegio o no es de este año: su periodo ('
                    .$periodoId.', año '.((int) ($cabecera['year'] ?? 0)).') no existe aquí. '
                    .'Descargue la planilla desde su propia cuenta.',
            ];

            return;
        }

        $profesorId = (int) ($cabecera['profesor_id'] ?? 0);
        $propio = ($this->usuario->tipo ?? '') === 'Profesor' ? (int) $this->usuario->persona_id : null;

        $this->profesorDelLibro = DB::selectOne(
            'SELECT p.id, p.nombres, p.apellidos FROM profesores p WHERE p.id = ? AND p.deleted_at IS NULL',
            [$profesorId]
        );

        $this->esMio = $propio !== null && $propio === $profesorId;

        if ($this->esMio) {
            return;
        }

        $deQuien = $this->profesorDelLibro === null
            ? 'otro docente'
            : trim(($this->profesorDelLibro->nombres ?? '').' '.($this->profesorDelLibro->apellidos ?? ''));

        /*
         * ── LA D4, Y POR QUÉ SON DOS PREGUNTAS Y NO UNA ──────────────────────────
         *
         * `puedeSubirLaPlanillaDeOtro` contesta **si esta persona puede**; es un
         * permiso del colegio entero y no dice nada de *este* archivo. La segunda
         * pregunta es si **quiso**, y esa se contesta sobre el libro que tiene
         * delante.
         *
         * Hacen falta las dos porque el permiso es ancho de por sí: quien lo tiene
         * lo tiene para los cincuenta y tres docentes, así que confundirse de
         * archivo —o subir el que un compañero dejó en la carpeta compartida— es
         * escribir las notas de un grupo que nadie ha mirado. Un bloqueo que hay
         * que resolver a mano obliga a **leer de quién es el libro antes de que
         * pase nada**, y el motivo lo dice con su nombre.
         *
         * Es la misma forma que `firma_rota` unas líneas más arriba, y no por
         * simetría: las dos son «se puede seguir, pero no en silencio».
         */
        if (! Autoriza::puedeSubirLaPlanillaDeOtro($this->usuario)) {
            $this->bloqueos[] = [
                'tipo' => 'libro_de_otro_docente',
                'hoja' => null,
                'motivo' => 'Este libro es de '.$deQuien.', y cada docente sólo puede subir el suyo. '
                    .'Subir la planilla de otro es cosa de coordinación académica.',
            ];

            return;
        }

        $bloqueo = [
            'tipo' => 'subir_por_otro',
            'hoja' => null,
            'motivo' => 'Este libro es de '.$deQuien.'. Usted puede subirlo por esa persona, pero '
                .'confírmelo antes: lo que entre quedará registrado a nombre de los dos, y el acta '
                .'dirá quién lo subió y por quién.',
        ];

        if ($this->respuestas->confirmaSubirPorOtro()) {
            $bloqueo['resuelto_por'] = 'confirmo';
            $this->bloqueosResueltos[] = $bloqueo;
            $this->porOtroConfirmado = true;

            return;
        }

        $this->bloqueos[] = $bloqueo;
    }

    // ── Una hoja ─────────────────────────────────────────────────────────────

    /**
     * Una hoja de asignatura: su estructura, sus filas y sus celdas.
     *
     * **La F3 vive aquí y no arriba, y es la diferencia que el plan subraya**: un
     * periodo cerrado *«se lleva por delante sus hojas y nada más»*. Un docente que
     * sube el libro después del cierre tiene el resto de hojas entrando; tirar el
     * libro entero por una es la clase de error que hace que la gente deje de usar
     * una función.
     *
     * @param  array<string, mixed>  $mapa
     */
    private function estudiarHoja(array $mapa): void
    {
        $nombre = (string) ($mapa['hoja'] ?? '');
        $asignaturaId = (int) ($mapa['asignatura_id'] ?? 0);
        $periodoId = (int) ($mapa['periodo_id'] ?? 0);
        $filas = is_array($mapa['filas'] ?? null) ? $mapa['filas'] : [];

        $this->filasDelLibro += count($filas);

        $ficha = [
            'nombre' => $nombre,
            'asignatura_id' => $asignaturaId > 0 ? $asignaturaId : null,
            'asignatura' => null,
            'alumnos' => count($filas),
            'periodo_numero' => null,
            'periodo_abierto' => null,
            'reconocida' => false,
            'casillas' => 0,
            'cambiaron' => 0,
            'entran' => 0,
            'problemas' => 0,
            'fuera' => true,
            'motivo_fuera' => null,
        ];

        $cuentas = ['casillas' => 0, 'cambiaron' => 0, 'entran' => 0, 'se_borran' => 0,
            'se_quedan_fuera' => 0, 'problemas' => 0, 'sin_pasar' => 0, 'filas_descartadas' => 0];

        // **El total de filas se cuenta SIEMPRE, también después de recortar** —eso
        // es lo de arriba— pero una hoja que llega con el tiempo ya agotado no se
        // estudia: entrar a estudiarla para parar en su primera fila la dejaría
        // marcada como «entra» con cero notas dentro, que es la peor forma de
        // decirlo. Se declara sin estudiar, y `puede_importarse: null` ya avisa de
        // que el plan no está completo.
        if ($this->recortado) {
            $ficha['motivo_fuera'] = 'Esta hoja no se llegó a estudiar: al ensayo se le acabó el tiempo. '
                .'Vuelva a subir el mismo archivo y continuará por aquí.';
            $this->cerrarHoja($ficha, $cuentas, null);

            return;
        }

        if (! $this->lector->tieneLaPestana($nombre)) {
            $ficha['motivo_fuera'] = 'La pestaña «'.$nombre.'» ya no está en el archivo: se borró o se le '
                .'cambió el nombre.';
            $this->cerrarHoja($ficha, $cuentas, null);

            return;
        }

        $periodo = DB::selectOne(
            'SELECT p.id, p.numero, p.year_id, p.profes_pueden_editar_notas
               FROM periodos p WHERE p.id = ? AND p.deleted_at IS NULL',
            [$periodoId]
        );

        if ($periodo === null) {
            $ficha['motivo_fuera'] = 'El periodo de esta hoja ya no existe.';
            $this->cerrarHoja($ficha, $cuentas, null);

            return;
        }

        $ficha['periodo_numero'] = (int) $periodo->numero;
        $ficha['periodo_abierto'] = (bool) $periodo->profes_pueden_editar_notas;

        // F3. Y se cuentan las casillas que se quedan fuera: «4° A Estadística: 0 —
        // el periodo está cerrado» dice algo; «se importaron 312 notas» no dice nada
        // de esto.
        if (! $periodo->profes_pueden_editar_notas) {
            $ficha['reconocida'] = true;
            $ficha['motivo_fuera'] = 'El periodo '.$periodo->numero.' está cerrado, así que esta hoja no se '
                .'puede subir. Las demás hojas del libro entran igual.';
            $cuentas['casillas'] = $this->contarCeldasConValor($nombre, $mapa);
            $cuentas['se_quedan_fuera'] = $cuentas['casillas'];
            $this->totales['se_quedan_fuera'] += $cuentas['casillas'];
            $this->cerrarHoja($ficha, $cuentas, null);

            return;
        }

        $asignatura = DB::selectOne(
            'SELECT a.id, a.profesor_id, a.grupo_id, m.materia, m.alias, g.nombre AS nombre_grupo, g.abrev,
                    g.year_id
               FROM asignaturas a
               INNER JOIN materias m ON m.id = a.materia_id AND m.deleted_at IS NULL
               INNER JOIN grupos g ON g.id = a.grupo_id AND g.deleted_at IS NULL
              WHERE a.id = ? AND a.deleted_at IS NULL',
            [$asignaturaId]
        );

        if ($asignatura === null) {
            $ficha['motivo_fuera'] = 'La asignatura de esta hoja ya no existe o se borró.';
            $this->cerrarHoja($ficha, $cuentas, null);

            return;
        }

        $ficha['reconocida'] = true;
        $ficha['asignatura'] = trim(($asignatura->abrev ?: $asignatura->nombre_grupo).' · '
            .($asignatura->alias ?: $asignatura->materia));

        // **Se vuelve a autorizar cada hoja contra «¿es esta asignatura de este
        // docente?»**, y no se da por bueno lo que diga el archivo. Es la §4.3 del
        // plan: la protección del `.xlsx` no impide nada, la seguridad está aquí.
        if ((int) ($asignatura->profesor_id ?? 0) !== (int) ($this->lector->cabecera['profesor_id'] ?? -1)) {
            $ficha['motivo_fuera'] = 'Esta asignatura ya no es de este docente, así que su hoja no se puede subir.';
            $this->cerrarHoja($ficha, $cuentas, null);

            return;
        }

        $this->estudiarLaRejilla($mapa, $ficha, $cuentas, $asignatura, $periodo);
    }

    /**
     * La rejilla de una hoja que sí entra: columnas, filas y celdas.
     *
     * @param  array<string, mixed>  $mapa
     * @param  array<string, mixed>  $ficha
     * @param  array<string, int>  $cuentas
     */
    private function estudiarLaRejilla(array $mapa, array $ficha, array $cuentas, object $asignatura, object $periodo): void
    {
        $nombre = (string) $mapa['hoja'];
        $asignaturaId = (int) $asignatura->id;
        $periodoId = (int) $periodo->id;
        $yearId = (int) $periodo->year_id;
        $modo = RepartoDeLaNota::modoDelAnio($yearId);

        $vivas = $this->subunidadesVivas($asignaturaId, $periodoId);
        $unidades = $this->unidadesVivas($asignaturaId, $periodoId);

        $columnas = $this->columnasQueEntran($nombre, $mapa, $vivas, $modo);
        $nuevas = $this->columnasQueCrear($nombre, $mapa, $unidades, $vivas, $modo);

        $filas = is_array($mapa['filas'] ?? null) ? $mapa['filas'] : [];
        $deLaRejilla = array_map('intval', array_values($filas));

        $matriculados = $this->matriculados((int) $asignatura->grupo_id);

        // **La F6 va ANTES de leer las notas de hoy**, y no por orden estético: una
        // fila escrita a mano puede resolverse en un alumno que **no está en la
        // rejilla** —se matriculó después de la descarga y el docente lo apuntó
        // abajo—, y ése no saldría en `notasDeHoy` si la consulta se hiciera con la
        // lista del mapa. Sin su nota de hoy no hay con qué comparar, y la D3 diría
        // «no ha cambiado» de una casilla que sí cambió.
        $resueltas = $this->estudiarLasFilas($nombre, $mapa, $filas, $deLaRejilla, $matriculados,
            $asignatura, $cuentas);

        $alumnos = array_values(array_unique(array_merge(
            $deLaRejilla,
            array_map(static fn (array $r) => $r['alumno_id'], $resueltas)
        )));

        $notas = $this->notasDeHoy($asignaturaId, $periodoId, $alumnos);
        $nombres = $this->nombresDe($alumnos);

        // **La F8 va aquí, con los nombres ya en la mano**: cada renglón lleva el
        // alumno con nombre y apellido, porque la pantalla pregunta «¿borro dos
        // faltas de Fulano?» y no «¿borro dos faltas del alumno 1055?».
        //
        // Se estudia la hoja entera de una vez —una consulta— y lo que sale se
        // reparte por alumno en el bucle de abajo, para que cada fila entre en la
        // transacción de su alumno y en su punto de control. Igual que las notas.
        $asistencia = $this->estudiarLasAusencias($nombre, $mapa, $asignaturaId, $periodoId,
            $deLaRejilla, $matriculados, $ficha['asignatura'], $nombres);

        $delPlan = [
            'hoja' => $nombre, 'asignatura_id' => $asignaturaId, 'asignatura' => $ficha['asignatura'],
            'grupo_id' => (int) $asignatura->grupo_id,
            'periodo_id' => $periodoId, 'entra' => true, 'crear' => $nuevas, 'filas' => [],
        ];

        $indice = -1;

        foreach ($filas as $fila => $alumno) {
            $indice++;
            $fila = (int) $fila;
            $alumnoId = (int) $alumno;

            // Lo que ya entró en una tanda anterior no se vuelve a estudiar ni a
            // escribir. **Va antes del reloj a propósito**: saltarse filas hechas
            // tiene que ser barato, o reanudar por la última fila de un libro grande
            // gastaría el presupuesto entero en llegar hasta ella.
            if ($this->punto?->yaProcesada($nombre, $indice)) {
                $this->filasYaHechas++;

                continue;
            }

            // **Nunca se para sin haber estudiado nada**, que si no es un bucle: la
            // petición diría «hice 0, faltan N», la siguiente haría lo mismo y el
            // asistente diría «falta» para siempre. Es la red que el importador de
            // alumnos ya tiene escrita.
            if (microtime(true) >= $this->limite && $this->filasEstudiadas > 0) {
                $this->recortado = true;

                break;
            }

            if (! isset($matriculados[$alumnoId])) {
                continue;
            }

            $celdas = $this->estudiarFila(
                $nombre, $fila, $alumnoId, $columnas, $nuevas, $notas, $mapa, $cuentas, $yearId, $nombres,
                $ficha['asignatura']
            );

            $this->filasEstudiadas++;

            $suya = $asistencia[$alumnoId] ?? [];

            // **La asistencia entra en la fila aunque no haya ni una nota que
            // escribir**, y por eso la condición tiene dos mitades: el caso normal de
            // esta fase es un docente que sólo corrigió las faltas de un alumno. Sin
            // esto, esa fila no estaría en el plan y su transacción —la que lleva el
            // punto de control— no existiría.
            if ($celdas !== [] || $suya !== []) {
                $delPlan['filas'][] = [
                    'indice' => $indice, 'fila' => $fila, 'alumno_id' => $alumnoId, 'celdas' => $celdas,
                    'asistencia' => $suya,
                ];
            }
        }

        // ── Las filas escritas a mano que el docente resolvió (F6) ───────────
        //
        // Van **después** de la rejilla y con su `indice` calculado del mapa y no
        // del bucle, para que reanudar una importación cortada apunte siempre a la
        // misma fila: si se contaran con el contador del bucle, un recorte por
        // tiempo movería el índice de estas tres y la siguiente petición reescribiría
        // o se saltaría una.
        //
        // Y si el ensayo ya se recortó, no se tocan: entran enteras en la siguiente.
        if (! $this->recortado) {
            foreach ($resueltas as $resuelta) {
                if ($this->punto?->yaProcesada($nombre, $resuelta['indice'])) {
                    $this->filasYaHechas++;

                    continue;
                }

                $celdas = $this->estudiarFila(
                    $nombre, $resuelta['fila'], $resuelta['alumno_id'], $columnas, $nuevas, $notas,
                    $mapa, $cuentas, $yearId, $nombres, $ficha['asignatura'], $resuelta['fila']
                );

                $this->filasEstudiadas++;

                if ($celdas !== []) {
                    $delPlan['filas'][] = [
                        'indice' => $resuelta['indice'], 'fila' => $resuelta['fila'],
                        'alumno_id' => $resuelta['alumno_id'], 'celdas' => $celdas,

                        // **Sin asistencia, y a propósito**: las casillas `Aus` y `Tar`
                        // del bloque del final nacen vacías, así que leerlas sería ver
                        // un hueco donde el sistema tiene faltas. Ver la cabecera de
                        // {@see estudiarLasAusencias}.
                        'asistencia' => [],
                    ];
                }
            }
        }

        $ficha['fuera'] = false;

        /*
         * **El mismo predicado que {@see EscrituraDeNotasImportadas::aplicarHoja}**,
         * escrito con las mismas dos mitades a propósito: el importador recalcula la
         * definitiva de este par si la hoja aporta **al menos una fila al plan** o si
         * crea algún indicador (F9). Contarlo al cerrar toda hoja reconocida —que es
         * lo que esto hacía— convertía el número en «hojas leídas»: el asistente
         * prometía 26 definitivas delante de una importación que recalculaba una, y
         * **volver a subir el mismo archivo sin tocar nada seguía prometiendo 26**
         * al lado de un «0 de 0 que cambió».
         *
         * Y no vale mirar sólo las que entran: **borrar** una nota (el guion de la
         * D9) también mueve la definitiva, y una fila que sólo trae faltas entra en
         * el plan igual —su transacción lleva el punto de control—, así que el
         * importador recalcula por ella. `filas` ya lleva las tres cosas dentro, y
         * por eso el predicado es el de la fila y no el de `entran`.
         */
        if ($delPlan['filas'] !== [] || $nuevas !== []) {
            $this->pares[$asignaturaId.':'.$periodoId] = true;
        }

        $this->cerrarHoja($ficha, $cuentas, $delPlan);
    }

    /**
     * Una fila de alumno: sus celdas de nota, una por columna.
     *
     * @param  array<string, array<string, mixed>>  $columnas
     * @param  list<array<string, mixed>>  $nuevas
     * @param  array<int, array<int, object>>  $notas
     * @param  array<string, mixed>  $mapa
     * @param  array<string, int>  $cuentas
     * @param  array<int, string>  $nombres
     * @param  ?int  $filaAMano  la fila del bloque del final si esto es una fila
     *                           escrita a mano y resuelta (F6); `null` en la rejilla
     * @return list<array<string, mixed>>
     */
    private function estudiarFila(
        string $hoja, int $fila, int $alumnoId, array $columnas, array $nuevas,
        array $notas, array $mapa, array &$cuentas, int $yearId, array $nombres, ?string $asignatura,
        ?int $filaAMano = null
    ): array {
        $aEscribir = [];

        foreach ($columnas as $letra => $columna) {
            $cuentas['casillas']++;
            $this->totales['casillas']++;

            $subunidadId = (int) $columna['destino'];
            $crudo = $this->lector->celda($hoja, $letra.$fila);
            $leido = $this->interpretar($crudo);

            // **La base es la del DESTINO y el espejo es el del ORIGEN**, y en una
            // columna normal son el mismo indicador. Sólo se separan cuando el
            // docente mandó mover una columna huérfana (F2): lo que había al
            // descargar es del indicador borrado, y lo que hay hoy es del indicador
            // al que se mueve. Leer los dos del mismo sitio haría que mover pareciera
            // siempre un choque.
            $actual = $notas[$alumnoId][$subunidadId] ?? null;
            $base = $actual === null ? null : ($actual->nota === null ? null : (int) $actual->nota);

            if ($columna['entra'] === false) {
                // La columna se queda fuera (F2). Sus celdas con valor **se cuentan
                // igual**: el docente escribió ahí y tiene que ver cuántas notas se
                // quedan en el camino.
                if ($leido['tipo'] !== 'vacia') {
                    $cuentas['cambiaron']++;
                    $cuentas['se_quedan_fuera']++;
                    $this->totales['cambiaron']++;
                    $this->totales['se_quedan_fuera']++;
                }

                if ($base === null) {
                    $cuentas['sin_pasar']++;
                }

                continue;
            }

            // **Una fila escrita a mano NO TIENE ESPEJO, y eso es lo que hace que
            // resolverla no pise notas en silencio.** Al descargar el libro esa
            // casilla estaba vacía —el bloque del final nace en blanco—, así que su
            // espejo es `null` aunque el alumno que se eligió sí tenga espejo en su
            // fila de la rejilla.
            //
            // La consecuencia es justo la que pide el plan: si el alumno **ya tiene
            // nota** en esa casilla, `base !== espejo` y la celda entra por el camino
            // de los choques (F7) —donde por defecto manda el sistema y la pantalla
            // la enseña—, en vez de escribirse por un atajo. Y si la casilla estaba
            // sin calificar, `base` y `espejo` son los dos `null` y se escribe sin
            // molestar a nadie, que es el caso normal.
            $espejo = $this->lector->firmaValida && $filaAMano === null
                ? $this->delEspejo($mapa, $alumnoId, (int) $columna['subunidad_id'])
                : null;

            $resultado = $this->decidirLaCelda(
                $hoja, $fila, $letra, $alumnoId, $subunidadId, $columna, $leido,
                $espejo, $base, $actual, $yearId, $nombres, $cuentas, $asignatura, $filaAMano
            );

            if ($resultado !== null) {
                $aEscribir[] = $resultado;
            }

            $final = $resultado === null ? $base : $resultado['valor'];

            if ($final === null) {
                $cuentas['sin_pasar']++;
            }
        }

        // Las columnas de reserva que el docente decidió crear: **siempre se
        // escriben**. No hay espejo ni hay nota anterior —el indicador no existía—,
        // así que no hay nada con lo que comparar y no puede haber choque.
        foreach ($nuevas as $nueva) {
            $cuentas['casillas']++;
            $this->totales['casillas']++;

            $leido = $this->interpretar($this->lector->celda($hoja, $nueva['columna'].$fila));

            if ($leido['tipo'] === 'vacia') {
                $cuentas['sin_pasar']++;

                continue;
            }

            $resultado = $this->decidirLaCelda(
                $hoja, $fila, $nueva['columna'], $alumnoId, 0,
                ['entra' => true, 'destino' => 0, 'subunidad_id' => 0, 'reserva' => $nueva['columna'],
                    'indicador' => $nueva['nombre']],
                $leido, null, null, null, $yearId, $nombres, $cuentas, $asignatura, $filaAMano
            );

            if ($resultado !== null) {
                $aEscribir[] = $resultado;
            } else {
                $cuentas['sin_pasar']++;
            }
        }

        return $aEscribir;
    }

    /**
     * Las tres reglas de la D3 y la D9, en un sitio.
     *
     * Devuelve lo que hay que escribir, o `null` si esa celda no se toca. Es **la**
     * función de esta clase: todo lo demás es traerle los tres valores.
     *
     * @param  array<string, mixed>  $columna
     * @param  array{tipo:string, valor:?int, texto:string, clase:?string}  $leido
     * @param  array<int, string>  $nombres
     * @param  array<string, int>  $cuentas
     * @return ?array<string, mixed>
     */
    private function decidirLaCelda(
        string $hoja, int $fila, string $letra, int $alumnoId, int $subunidadId, array $columna,
        array $leido, ?int $espejo, ?int $base, ?object $actual, int $yearId, array $nombres,
        array &$cuentas, ?string $asignatura, ?int $filaAMano = null
    ): ?array {
        // **D9, la mitad que más data salva: una casilla vacía no borra.** Significa
        // «no la toques», y sin esto un docente que sólo llenó un Logro borraría todo
        // lo demás — el libro se descarga lleno (D2).
        if ($leido['tipo'] === 'vacia') {
            return null;
        }

        // F4: la celda no es un entero. **La decisión se llavea por el valor.**
        if ($leido['tipo'] === 'problema') {
            $decidido = $this->respuestas->queHacerConLaCelda($leido['texto']);

            $this->anotarCelda($leido, $decidido, $hoja, $fila, $letra, $nombres[$alumnoId] ?? null);

            if ($decidido['decision'] !== RespuestasDeLaPlanilla::CELDA_INTERPRETAR) {
                $cuentas['cambiaron']++;
                $cuentas['problemas']++;
                $cuentas['se_quedan_fuera']++;
                $this->totales['cambiaron']++;
                $this->totales['se_quedan_fuera']++;

                return null;
            }

            $cuentas['problemas']++;
            $leido = ['tipo' => 'entero', 'valor' => $decidido['nota'], 'texto' => (string) $decidido['nota'],
                'clase' => null];
        }

        $valor = $leido['tipo'] === 'borrar' ? null : $leido['valor'];

        // F5: la nota no cabe en la escala del año **del periodo de la fila**, que es
        // la regla del §3.2 y no la del año en curso: la D1 deja subir periodos
        // pasados y cada año tuvo su escala. Y si el año no tiene escala configurada,
        // `motivoSiNoCabeEnAnio` devuelve `null` y **no se bloquea nada**.
        if ($valor !== null) {
            $noCabe = EscalaDeNotas::motivoSiNoCabeEnAnio($valor, $yearId);

            if ($noCabe !== null) {
                $decision = $this->respuestas->queHacerConLaEscala((string) $valor);
                $topado = $this->topar($valor, $yearId);

                $this->anotarEscala($valor, $decision, $topado, $yearId, $hoja, $fila, $letra,
                    $nombres[$alumnoId] ?? null);

                if ($decision !== RespuestasDeLaPlanilla::ESCALA_TOPAR || $topado === null) {
                    $cuentas['cambiaron']++;
                    $cuentas['problemas']++;
                    $cuentas['se_quedan_fuera']++;
                    $this->totales['cambiaron']++;
                    $this->totales['se_quedan_fuera']++;

                    return null;
                }

                $cuentas['problemas']++;
                $valor = $topado;
            }
        }

        // ── Las tres puntas ──────────────────────────────────────────────────
        //
        // Con la firma rota no hay espejo del que fiarse, así que `cambióEnArchivo`
        // es siempre cierto —todas las celdas con valor cuentan como cambiadas, §4.7
        // peldaño 2— y `cambióEnSistema` no se puede saber: sin espejo, «cambió
        // desde la descarga» no tiene con qué compararse. Por eso un libro con la
        // firma rota **no produce choques**; produce una lista que hay que confirmar.
        //
        // **Y una columna MOVIDA no puede chocar**, que es la otra mitad: su espejo
        // es el del indicador borrado y su base la del indicador de destino, o sea
        // dos casillas distintas. Enfrentarlas daría un choque en cada fila —y
        // ganaría el sistema— así que mover una columna no movería nada. Es el fallo
        // que la primera versión de esto tuvo y que cazó
        // `f2_un_indicador_borrado_deja_su_columna_fuera_y_se_puede_mover`.
        $fiable = $this->lector->firmaValida;
        $movida = ($columna['movida'] ?? false) === true;
        $cambioEnArchivo = $fiable ? ($valor !== $espejo) : true;
        $cambioEnSistema = $fiable && ! $movida ? ($base !== $espejo) : false;

        // **Regla 1 de la D3.** Igual al espejo: el docente no tocó esa casilla, así
        // que no se escribe **aunque la base haya cambiado**. Es literalmente la
        // frase del encargo, y es la que evita que subir un libro viejo pise el
        // trabajo que otro hizo por la web.
        if (! $cambioEnArchivo) {
            return null;
        }

        // El archivo trae lo que ya hay: no es un choque ni es trabajo. No se cuenta
        // como cambiada para que los tres números de la pantalla —entran, se borran,
        // se quedan fuera— sumen exactamente `cambiaron`.
        if ($base === $valor) {
            return null;
        }

        $cuentas['cambiaron']++;
        $this->totales['cambiaron']++;

        // **Regla 3: el choque (F7).** Cambió en los dos sitios y a valores
        // distintos. Por defecto manda el sistema, que es «lo seguro»: si alguien lo
        // tocó después de la descarga, lo tocó sabiendo lo que hacía.
        if ($cambioEnSistema) {
            $id = $this->idDelChoque($hoja, $alumnoId, $subunidadId, $filaAMano);
            $decision = $this->respuestas->queHacerConElChoque($id);

            $this->choques[] = [
                'id' => $id,
                'hoja' => $hoja,
                'asignatura' => $asignatura,
                'subunidad' => $columna['indicador'] ?? null,
                'alumno' => $nombres[$alumnoId] ?? null,
                'valor_archivo' => $valor,
                'valor_sistema' => $base,
                'cambiado_at' => $actual->updated_at ?? null,
                'cambiado_por' => $this->quienEs($actual->updated_by ?? null),
            ];

            if ($decision !== RespuestasDeLaPlanilla::CHOQUE_ARCHIVO) {
                $cuentas['se_quedan_fuera']++;
                $this->totales['se_quedan_fuera']++;

                return null;
            }
        }

        if ($valor === null) {
            $cuentas['se_borran']++;
            $this->totales['se_borran']++;
        } else {
            $cuentas['entran']++;
            $this->totales['entran']++;
        }

        return [
            'columna' => $letra,
            'subunidad_id' => $subunidadId > 0 ? $subunidadId : null,
            'reserva' => $columna['reserva'] ?? null,
            'valor' => $valor,
            'anterior' => $base,
        ];
    }

    // ── La estructura (F2 y F9) ──────────────────────────────────────────────

    /**
     * Qué columnas del mapa siguen valiendo hoy, y qué se hace con las que no (F2).
     *
     * @param  array<string, mixed>  $mapa
     * @param  array<int, object>  $vivas
     * @return array<string, array<string, mixed>>
     */
    private function columnasQueEntran(string $hoja, array $mapa, array $vivas, string $modo): array
    {
        $columnas = [];
        $delMapa = is_array($mapa['columnas'] ?? null) ? $mapa['columnas'] : [];

        foreach ($delMapa as $letra => $subunidadId) {
            $letra = (string) $letra;
            $subunidadId = (int) $subunidadId;

            if (! isset($vivas[$subunidadId])) {
                // **El indicador se borró desde la descarga.** La decisión es de la
                // COLUMNA y no de las 45 celdas que cuelgan de ella: una hoja de 45
                // alumnos con un indicador borrado son 45 problemas idénticos, y la
                // persona que los mira tiene que contestar **uno**.
                $decidido = $this->respuestas->queHacerConLaColumna($hoja, $letra);
                $destino = $decidido['subunidad_id'];
                $vale = $decidido['decision'] === RespuestasDeLaPlanilla::COLUMNA_MOVER
                    && $destino !== null && isset($vivas[$destino]);

                $afectadas = $this->contarColumnaConValor($hoja, $mapa, $letra);

                $this->estructura[] = [
                    'hoja' => $hoja,
                    'columna' => $letra,
                    'tipo' => 'indicador_borrado',
                    'titulo' => 'El indicador de la columna '.$letra.' ya no existe',
                    'detalle' => 'Lo borraron después de que usted descargara el libro. Puede mandar sus notas '
                        .'a otro indicador de esta misma asignatura, o dejarlas fuera.',
                    'notas_afectadas' => $afectadas,
                    'decidible' => true,
                    'si_no_hago_nada' => $afectadas === 0
                        ? 'No pasa nada: no escribió ninguna nota en esa columna.'
                        : 'Las '.$afectadas.' nota(s) de esa columna no se importan.',
                    'destinos' => $this->destinosPosibles($vivas),
                ];

                $columnas[$letra] = [
                    'entra' => $vale, 'destino' => $vale ? $destino : 0, 'subunidad_id' => $subunidadId,
                    'movida' => $vale,
                    'indicador' => $vale ? ($vivas[$destino]->definicion ?? null) : null,
                ];

                continue;
            }

            // El peso cambió. **Informativo y sin decisión**: no cambia ni una nota
            // —la nota es del indicador, no del peso— pero sí cambia la definitiva,
            // y el docente calificó mirando el peso que llevaba impreso su hoja.
            $pesoDelArchivo = $modo === RepartoDeLaNota::PORCENTAJE
                ? $this->lector->pesoDeLaCabecera($hoja, $letra)
                : null;

            if ($pesoDelArchivo !== null && $pesoDelArchivo !== (int) $vivas[$subunidadId]->porcentaje) {
                $this->estructura[] = [
                    'hoja' => $hoja,
                    'columna' => $letra,
                    'tipo' => 'peso_cambiado',
                    'titulo' => 'La columna '.$letra.' pesa distinto que cuando bajó el libro',
                    'detalle' => 'Su hoja dice que pesaba el '.$pesoDelArchivo.'% y hoy pesa el '
                        .((int) $vivas[$subunidadId]->porcentaje).'%.',
                    'notas_afectadas' => $this->contarColumnaConValor($hoja, $mapa, $letra),
                    'decidible' => false,
                    'si_no_hago_nada' => 'Las notas entran igual. Lo que cambia es la definitiva que salga '
                        .'de ellas, que se calcula con el peso de hoy.',
                    'destinos' => [],
                ];
            }

            $columnas[$letra] = [
                'entra' => true, 'destino' => $subunidadId, 'subunidad_id' => $subunidadId,
                'movida' => false,
                'indicador' => $vivas[$subunidadId]->definicion ?? null,
            ];
        }

        // Indicadores que hoy existen y **no estaban** en el libro. También
        // informativo: no hay nada que importar de ellos —el docente no tuvo dónde
        // escribirlos— y lo que hace falta decir es que sus casillas se quedan vacías.
        $enElLibro = array_map('intval', array_values($delMapa));

        foreach ($vivas as $id => $suya) {
            if (in_array($id, $enElLibro, true)) {
                continue;
            }

            $this->estructura[] = [
                'hoja' => $hoja,

                // **Sin columna, y por eso va `null`**: este indicador no tiene
                // columna en la hoja — ése es justamente el problema. Es el único
                // renglón de la familia que no la lleva.
                'columna' => null,
                'tipo' => 'indicador_nuevo',
                'titulo' => 'Hay un indicador nuevo que su hoja no tiene',
                'detalle' => trim((string) ($suya->definicion ?? '')) === ''
                    ? '(sin descripción)'
                    : (string) $suya->definicion,
                'notas_afectadas' => 0,
                'decidible' => false,
                'si_no_hago_nada' => 'Nada. Se creó después de que usted descargara el libro, así que su hoja '
                    .'no tiene columna para él y sus casillas se quedan como están.',
                'destinos' => [],
            ];
        }

        return $columnas;
    }

    /**
     * F9: las columnas **de reserva** en las que el docente escribió.
     *
     * La reserva de la D12 existe para el caso que pidió Joseth: el docente está sin
     * internet, hace una actividad que no tenía planeada y necesita una columna
     * donde ponerla. Al volver, esa columna no es un error: **es un indicador que
     * hay que crear**.
     *
     * Y crearlo no es gratis (§9.7 del plan): en modo `porcentaje` el Logro tiene
     * que seguir sumando 100, así que el peso **lo da el docente** y no se reparte
     * solo — repartir cambiaría notas ya guardadas. En modo `promedio` no hay nada
     * que pedir: cada indicador vale `100/n`, y de ahí que `pide_peso` sea `false`.
     *
     * @param  array<string, mixed>  $mapa
     * @param  list<object>  $unidades
     * @param  array<int, object>  $vivas
     * @return list<array<string, mixed>>
     */
    private function columnasQueCrear(string $hoja, array $mapa, array $unidades, array $vivas, string $modo): array
    {
        $reservadas = is_array($mapa['reservadas'] ?? null) ? $mapa['reservadas'] : [];
        $crear = [];

        foreach ($reservadas as $letra => $numero) {
            $letra = (string) $letra;
            $conValor = $this->contarColumnaConValor($hoja, $mapa, $letra);

            if ($conValor === 0) {
                continue;
            }

            $decidido = $this->respuestas->queHacerConLaReserva($hoja, $letra);
            $orden = $this->lector->unidadDeLaColumna($hoja, $letra);
            $unidad = $orden !== null ? ($unidades[$orden - 1] ?? null) : null;
            $pidePeso = $modo === RepartoDeLaNota::PORCENTAJE;

            $vale = $decidido['decision'] === RespuestasDeLaPlanilla::RESERVA_CREAR
                && $unidad !== null
                && (! $pidePeso || $decidido['peso'] !== null);

            $this->reserva[] = [
                'hoja' => $hoja,
                'columna' => $letra,
                'numero' => $numero === null ? null : (int) $numero,
                'unidad' => $unidad === null ? null : (string) ($unidad->definicion ?? ''),
                'unidad_id' => $unidad === null ? null : (int) $unidad->id,
                'notas' => $conValor,

                // `false` en modo promedio: ahí un indicador nuevo vale `100/n` y no
                // hay nada que preguntar (§3.5).
                'pide_peso' => $pidePeso,
                'peso_sugerido' => $pidePeso && $unidad !== null
                    ? $this->pesoQueFalta($unidad, $vivas)
                    : null,

                // Si la decisión que llegó se va a aplicar. No está en la interfaz del
                // front y se manda igual: sin esto, la respuesta de la importación no
                // puede distinguir «no se creó porque no lo pidió» de «lo pidió y no
                // se pudo», que se leen igual y se arreglan distinto.
                'se_crea' => $vale,
                'si_no_hago_nada' => $unidad === null
                    ? 'Esas '.$conValor.' nota(s) no se importan. Y aquí no se puede crear el indicador: la '
                        .'cabecera de la hoja cambió, así que no se sabe de qué unidad es esta columna.'
                    : 'Esas '.$conValor.' nota(s) no se importan: ese indicador todavía no existe.',
            ];

            if ($vale) {
                $crear[] = [
                    'columna' => $letra, 'nombre' => $decidido['nombre'],
                    'peso' => $decidido['peso'] ?? 0, 'unidad_id' => (int) $unidad->id,
                ];
            }
        }

        return $crear;
    }

    // ── Las filas que no se reconocen (F6) y las ausencias (F8) ──────────────

    /**
     * F6: las tres formas en que una fila del libro y una persona dejan de casar.
     *
     * **Son tres casos distintos y se contestan distinto**, que es lo que el §6.4
     * del plan subraya y lo que esta función existe para no mezclar:
     *
     * | Tipo | Qué pasó | Se pregunta |
     * |---|---|---|
     * | `escrita_a_mano` | El docente escribió un nombre en el bloque del final | **Sí**: se busca un parecido en el grupo y decide él |
     * | `ya_no_esta_en_el_grupo` | El `ID` estaba al descargar y hoy no | **No.** Se informa con el motivo y la fecha, y sus notas se quedan fuera |
     * | `entro_despues` | Está en el grupo y no en el archivo | **No.** Es un aviso: sus casillas se quedan vacías |
     *
     * ## La regla del encargo, que es la que manda sobre todo lo de abajo
     *
     * > *«No debe crear el alumno, pero sí intentar encontrarlo en el grupo y
     * > preguntarle si ese es, para proseguir.»*
     *
     * **Nunca se crea un alumno y nunca se fusionan dos.** Y se busca **dentro del
     * grupo de esa hoja**, no en el colegio: escribir la nota de alguien que no está
     * matriculado ahí sería corromper la planilla en silencio — un dato que parece
     * bueno, que nadie revisa y que sale en un boletín. Por eso el buscador del
     * front —el «No, es otro…»— hereda el mismo límite, y por eso la comprobación de
     * *«este alumno está en este grupo»* se repite aquí aunque la pantalla ya la
     * haya hecho: la decisión llega del cliente y no se cree.
     *
     * Si no hay ningún parecido, la respuesta **dice quién puede arreglarlo**. Un
     * error que no ofrece salida obliga a llamar por teléfono, y aquí la salida
     * existe y no es del docente: la matrícula es cosa de secretaría.
     *
     * @param  array<string, mixed>  $mapa
     * @param  array<array-key, mixed>  $filas  el `filas` del mapa: fila → alumno
     * @param  list<int>  $deLaRejilla
     * @param  array<int, object>  $matriculados
     * @param  array<string, int>  $cuentas
     * @return list<array{fila:int, alumno_id:int, indice:int}> las que se resolvieron
     */
    private function estudiarLasFilas(string $hoja, array $mapa, array $filas, array $deLaRejilla,
        array $matriculados, object $asignatura, array &$cuentas): array
    {
        $grupo = trim((string) ($asignatura->abrev ?: $asignatura->nombre_grupo));
        $materia = trim((string) ($asignatura->alias ?: $asignatura->materia));

        $this->lasQueYaNoEstan($hoja, $mapa, $filas, $matriculados, $grupo, $materia,
            (int) $asignatura->grupo_id, (int) $asignatura->year_id, $cuentas);

        $this->lasQueEntraronDespues($hoja, $deLaRejilla, $matriculados, $grupo, $materia);

        return $this->lasEscritasAMano($hoja, $mapa, $filas, $matriculados, $grupo, $materia, $cuentas);
    }

    /**
     * Caso (b): el `ID` que estaba al descargar y hoy no está en el grupo.
     *
     * **Aquí no se pregunta nada, y eso es una decisión de diseño y no una
     * comodidad**: que un alumno se haya retirado o trasladado no es algo que el
     * docente pueda contestar, y ofrecerle un botón sería pedirle que decida sobre
     * una matrícula. Lo que sí hace falta es **el motivo y la fecha**, porque sin
     * ellos el aviso se lee como un fallo del sistema: «sus cinco notas no entraron»
     * a secas es indistinguible de una avería.
     *
     * @param  array<string, mixed>  $mapa
     * @param  array<array-key, mixed>  $filas
     * @param  array<int, object>  $matriculados
     * @param  array<string, int>  $cuentas
     */
    private function lasQueYaNoEstan(string $hoja, array $mapa, array $filas, array $matriculados,
        string $grupo, ?string $materia, int $grupoId, int $yearId, array &$cuentas): void
    {
        $fuera = [];

        foreach ($filas as $fila => $alumno) {
            $alumnoId = (int) $alumno;

            if (! isset($matriculados[$alumnoId])) {
                $fuera[$alumnoId] = (int) $fila;
            }
        }

        if ($fuera === []) {
            return;
        }

        $fichas = $this->fichasDe(array_keys($fuera));
        $motivos = $this->porQueYaNoEstan($grupoId, $yearId, array_keys($fuera), $grupo);

        foreach ($fuera as $alumnoId => $fila) {
            $cuentas['filas_descartadas']++;
            $this->totales['filas_descartadas']++;

            $ficha = $fichas[$alumnoId] ?? null;
            $nombre = $ficha->nombre ?? ('el alumno '.$alumnoId);
            $cuantas = $this->notasEnLaFila($hoja, $mapa, $fila);

            $this->filas[] = [
                'id' => $this->idDeLaFila($hoja, $fila, 'ya_no_esta_en_el_grupo', (string) $alumnoId),
                'hoja' => $hoja,
                'asignatura' => $materia,
                'grupo' => $grupo,
                'fila' => $fila,
                'tipo' => 'ya_no_esta_en_el_grupo',
                'escrito' => null,
                'notas_en_la_fila' => $cuantas,

                // **No es decidible y por eso no lleva candidatos.** La pantalla lo
                // pinta como aviso, no como tarjeta con botones.
                'decidible' => false,
                'resuelta' => false,
                'titulo' => $nombre.' ya no está en '.$grupo,
                'si_no_hago_nada' => ($cuantas === 0
                    ? 'No pasa nada: esa fila no trae ninguna nota escrita.'
                    : 'Sus '.$cuantas.' nota(s) del libro se quedan fuera.')
                    .' No es una decisión suya: para que entren tendría que volver a estar matriculado '
                    .'en '.$grupo.', y eso es cosa de secretaría.',
                'alumno' => [
                    'alumno_id' => $alumnoId,
                    'nombre' => $ficha->nombre ?? null,
                    'no_matricula' => $ficha->no_matricula ?? null,
                    'foto' => $ficha->foto ?? null,
                    'sexo' => $ficha->sexo ?? null,
                    'motivo' => $motivos[$alumnoId] ?? 'Ya no está matriculado en '.$grupo.'.',
                ],
                'candidatos' => [],
            ];
        }
    }

    /**
     * Caso (c): está en el grupo y no en el archivo — se matriculó después.
     *
     * **Aviso, no problema.** Sus casillas no existen en la hoja porque el libro se
     * bajó antes de que llegara, así que no hay nada que importar ni nada que
     * decidir; lo único que hace falta es **su nombre**, para que el docente sepa a
     * quién le falta pasar la nota.
     *
     * Va **un renglón por persona** y no uno por hoja, aunque la pantalla los
     * agrupe: cada renglón lleva su `alumno` con su foto, que es lo que deja
     * enseñarlos como se enseña cualquier lista de personas en MyVc.
     *
     * Y `fila` va `null` — el único sitio de la familia donde eso pasa — porque
     * **ese alumno no tiene fila en el libro**, que es exactamente el problema del
     * que avisa. Es lo mismo que le ocurre a `estructura[].columna` en el renglón
     * `indicador_nuevo`, y por el mismo motivo.
     *
     * @param  list<int>  $deLaRejilla
     * @param  array<int, object>  $matriculados
     */
    private function lasQueEntraronDespues(string $hoja, array $deLaRejilla, array $matriculados,
        string $grupo, ?string $materia): void
    {
        foreach ($matriculados as $alumnoId => $ficha) {
            if (in_array($alumnoId, $deLaRejilla, true)) {
                continue;
            }

            $this->filas[] = [
                'id' => $this->idDeLaFila($hoja, 0, 'entro_despues', (string) $alumnoId),
                'hoja' => $hoja,
                'asignatura' => $materia,
                'grupo' => $grupo,
                'fila' => null,
                'tipo' => 'entro_despues',
                'escrito' => null,
                'notas_en_la_fila' => 0,
                'decidible' => false,
                'resuelta' => false,
                'titulo' => $ficha->nombre.' entró a '.$grupo.' después de que usted bajara el libro',
                'si_no_hago_nada' => 'Nada. No tiene casillas en esta hoja, así que sus notas siguen sin '
                    .'pasar: hay que ponérselas por la web, o bajar el libro otra vez y ahí ya saldrá.',
                'alumno' => [
                    'alumno_id' => $alumnoId,
                    'nombre' => $ficha->nombre,
                    'no_matricula' => $ficha->no_matricula,
                    'foto' => $ficha->foto,
                    'sexo' => $ficha->sexo,
                    'motivo' => $ficha->desde === null
                        ? 'Se matriculó después de que usted bajara el libro.'
                        : 'Está '.$this->desdeCuando($ficha->desde).'.',
                ],
                'candidatos' => [],
            ];
        }
    }

    /**
     * Caso (a): el nombre escrito a mano en el bloque del final. **La que sí se
     * pregunta.**
     *
     * ## Qué hace útil a la tarjeta, que no es el buscador
     *
     * `ya_esta_en_la_hoja` no es un adorno. **El caso de verdad frecuente es que el
     * alumno ya estuviera en la lista**: está como *Cárdenas*, el docente escribió
     * *Cardenaz*, no se vio y lo apuntó abajo. Si la respuesta no dice que esa
     * persona ya tiene notas en la fila 8, quien mira acepta y **pisa notas sin
     * enterarse**. Por eso se mandan la fila y los valores que ya hay.
     *
     * Y la otra mitad de esa red está en {@see estudiarFila}: una fila escrita a
     * mano no tiene espejo, así que una casilla que pisa una nota existente entra
     * por el camino de los choques (F7) y no por un atajo. La tarjeta avisa antes;
     * el choque para después.
     *
     * ## Y el «no hay nadie»
     *
     * Sin candidatos la fila **no se importa** y la frase dice **quién puede
     * arreglarlo**: *si el alumno es nuevo, secretaría tiene que matricularlo
     * primero*. No se crea a nadie, y no se deja a nadie sin salida.
     *
     * @param  array<string, mixed>  $mapa
     * @param  array<array-key, mixed>  $filas
     * @param  array<int, object>  $matriculados
     * @param  array<string, int>  $cuentas
     * @return list<array{fila:int, alumno_id:int, indice:int}>
     */
    private function lasEscritasAMano(string $hoja, array $mapa, array $filas, array $matriculados,
        string $grupo, ?string $materia, array &$cuentas): array
    {
        $escritas = $this->lector->filasDeAlumnosNuevos($hoja, $filas);

        if ($escritas === []) {
            return [];
        }

        // Cuentan como filas del libro: el docente escribió en ellas y el contador
        // de avance que ve en la pantalla tiene que incluirlas, o al reanudar diría
        // «voy por la 30 de 28».
        $this->filasDelLibro += count($escritas);

        $nombresDelGrupo = array_map(static fn (object $f) => $f->nombre, $matriculados);
        $delMapa = array_map('intval', is_array($mapa['filas'] ?? null) ? $mapa['filas'] : []);

        // **El índice sale de la fila, no de un contador.** `yaProcesada` es una
        // marca de agua —«voy por la N»— así que los índices tienen que crecer en el
        // orden en que se procesan y no moverse entre dos subidas del mismo fichero.
        // Sumar el número de fila a la altura de la rejilla cumple las dos cosas:
        // nunca choca con un índice de la rejilla (la fila del bloque va siempre por
        // debajo) y no depende de cuáles de las tres filas estén escritas.
        $altura = count($filas);

        $resueltas = [];
        $yaElegidos = [];

        foreach ($escritas as $escrita) {
            $fila = (int) $escrita['fila'];
            $escrito = (string) $escrita['nombre'];
            $id = $this->idDeLaFila($hoja, $fila, 'escrita_a_mano', $escrito);
            $cuantas = $this->notasEnLaFila($hoja, $mapa, $fila);

            $candidatos = [];

            foreach (ParecidoDeNombres::mejores($escrito, $nombresDelGrupo) as $parecido) {
                $alumnoId = (int) $parecido['clave'];
                $ficha = $matriculados[$alumnoId];

                $candidatos[] = [
                    'alumno_id' => $alumnoId,
                    'nombre' => $ficha->nombre,
                    'no_matricula' => $ficha->no_matricula,
                    'foto' => $ficha->foto,
                    'sexo' => $ficha->sexo,
                    'desde' => $ficha->desde === null ? null : $this->desdeCuando($ficha->desde),
                    'parecido' => $parecido['parecido'],
                    'ya_esta_en_la_hoja' => $this->dondeEstaYa($hoja, $mapa, $delMapa, $alumnoId),
                ];
            }

            $decidido = $this->respuestas->queHacerConLaFila($id);
            $elegido = null;

            if ($decidido['decision'] === RespuestasDeLaPlanilla::FILA_ES) {
                $alumnoId = (int) $decidido['alumno_id'];
                $elegido = $this->comprobarAlQueSeEligio($hoja, $fila, $escrito, $alumnoId, $grupo,
                    $matriculados, $yaElegidos);
            }

            if ($elegido !== null) {
                $yaElegidos[$elegido] = $fila;
                $resueltas[] = ['fila' => $fila, 'alumno_id' => $elegido, 'indice' => $altura + $fila];
            } else {
                $cuentas['filas_descartadas']++;
                $this->totales['filas_descartadas']++;
            }

            $this->filas[] = [
                'id' => $id,
                'hoja' => $hoja,
                'asignatura' => $materia,
                'grupo' => $grupo,
                'fila' => $fila,
                'tipo' => 'escrita_a_mano',
                'escrito' => $escrito,
                'notas_en_la_fila' => $cuantas,

                // **Decidible sólo si hay a quién elegir.** Una tarjeta con botones y
                // sin candidatos es una pregunta sin respuestas posibles: lo que hay
                // que enseñar ahí es el «no está, y esto es lo que se hace».
                'decidible' => $candidatos !== [],
                'resuelta' => $elegido !== null,
                // **El título lleva dentro lo que escribió**, también cuando no hay
                // nadie: esta misma frase se dice en voz alta después de importar
                // (`avisos()`), y «no hay nadie con ese nombre» sin el nombre es un
                // aviso que no se puede ni buscar en la hoja.
                'titulo' => $candidatos === []
                    ? '«'.$escrito.'» no está en '.$grupo
                    : 'Fila '.$fila.': usted escribió «'.$escrito.'»',
                'si_no_hago_nada' => $this->siNoHagoNadaConLaFila($candidatos, $cuantas, $grupo),
                'alumno' => null,
                'candidatos' => $candidatos,
            ];
        }

        return $resueltas;
    }

    /**
     * Que el alumno elegido se pueda escribir de verdad. **La decisión llega del
     * cliente y no se cree.**
     *
     * Dos puertas, y las dos dan **bloqueo** —o sea 422 y no se escribe nada— y no
     * un renglón que se ignora en silencio:
     *
     * 1. **No está matriculado en el grupo de esa hoja.** Puede ser un `alumno_id`
     *    de otro grupo, de otro año o inventado. Escribirlo sería la corrupción
     *    silenciosa que el §6.4 existe para evitar, y devolver la fila como «no
     *    decidida» dejaría a la pantalla enseñando una pregunta que la persona ya
     *    contestó.
     * 2. **Dos filas dicen ser la misma persona.** Se escribirían las dos, una
     *    encima de la otra, y ganaría la de abajo por el orden del bucle. Es un
     *    error de quien decide y hay que devolvérselo, no repartirlo a suertes.
     *
     * @param  array<int, object>  $matriculados
     * @param  array<int, int>  $yaElegidos  alumno → fila que ya lo eligió
     */
    private function comprobarAlQueSeEligio(string $hoja, int $fila, string $escrito, int $alumnoId,
        string $grupo, array $matriculados, array $yaElegidos): ?int
    {
        if (! isset($matriculados[$alumnoId])) {
            $this->bloqueos[] = [
                'tipo' => 'fila_de_otro_grupo',
                'hoja' => $hoja,
                'motivo' => 'La fila '.$fila.' de «'.$hoja.'» («'.$escrito.'») se asignó a un alumno que no '
                    .'está matriculado en '.$grupo.'. Sólo se puede elegir a alguien del grupo de esa hoja: '
                    .'escribir la nota de un alumno de otro grupo dejaría la planilla mal sin dar ningún '
                    .'error. Vuelva a abrir el paso de alumnos y elija a alguien de la lista.',
            ];

            return null;
        }

        if (isset($yaElegidos[$alumnoId])) {
            $this->bloqueos[] = [
                'tipo' => 'dos_filas_el_mismo_alumno',
                'hoja' => $hoja,
                'motivo' => 'Las filas '.$yaElegidos[$alumnoId].' y '.$fila.' de «'.$hoja.'» dicen ser la '
                    .'misma persona ('.$matriculados[$alumnoId]->nombre.'). Una de las dos tiene que quedarse '
                    .'fuera: si se escribieran las dos, la de abajo taparía a la de arriba y nadie sabría '
                    .'cuál quedó.',
            ];

            return null;
        }

        return $alumnoId;
    }

    /**
     * La frase de «qué pasa si no hago nada» de una fila escrita a mano.
     *
     * Las dos son distintas a propósito: la primera describe **una decisión que
     * está pendiente** y la segunda **un camino cerrado con su salida al lado**.
     * Dar la misma frase a las dos convertiría el segundo caso en un botón que no
     * hace nada.
     *
     * @param  list<array<string, mixed>>  $candidatos
     */
    private function siNoHagoNadaConLaFila(array $candidatos, int $cuantas, string $grupo): string
    {
        $suyas = $cuantas === 0
            ? 'Esa fila no trae ninguna nota escrita, así que no se pierde nada'
            : 'Sus '.$cuantas.' nota(s) se quedan fuera';

        if ($candidatos === []) {
            return $suyas.'. No hay nadie con ese nombre en '.$grupo.', ni parecido, así que esa fila no se '
                .'va a importar. Si es un alumno nuevo, secretaría tiene que matricularlo primero; '
                .'cuando esté, baje el libro otra vez y aparecerá en la lista. Aquí no se crea a nadie.';
        }

        return 'Esa fila no se importa mientras no diga de quién es. '.$suyas.'.';
    }

    /**
     * Dónde está ya ese alumno en la hoja, y con qué notas — o `null` si no está.
     *
     * **Es lo que hace útil la tarjeta.** Los valores salen del **archivo**, no de
     * la base: la frase del §6.4 es *«sus notas en esta hoja»* y lo que hay que
     * poder comparar de un vistazo es la fila de arriba con la que se escribió
     * abajo. El guion largo marca la casilla vacía, igual que en el libro.
     *
     * @param  array<string, mixed>  $mapa
     * @param  array<array-key, int>  $delMapa
     * @return ?array{fila:int, notas:list<string>}
     */
    private function dondeEstaYa(string $hoja, array $mapa, array $delMapa, int $alumnoId): ?array
    {
        $fila = array_search($alumnoId, $delMapa, true);

        if ($fila === false) {
            return null;
        }

        $fila = (int) $fila;
        $notas = [];

        foreach (array_keys(is_array($mapa['columnas'] ?? null) ? $mapa['columnas'] : []) as $letra) {
            $leido = $this->interpretar($this->lector->celda($hoja, (string) $letra.$fila));

            $notas[] = $leido['tipo'] === 'vacia' ? '—' : $leido['texto'];
        }

        return ['fila' => $fila, 'notas' => $notas];
    }

    /**
     * Cuántas casillas de nota trae escritas una fila, **contando las de reserva**.
     *
     * Las de reserva cuentan porque el docente escribió ahí y ahí hay trabajo suyo:
     * decirle que su fila trae cuatro notas cuando trae cinco es la clase de número
     * que hace desconfiar de todos los demás.
     *
     * @param  array<string, mixed>  $mapa
     */
    private function notasEnLaFila(string $hoja, array $mapa, int $fila): int
    {
        $letras = array_merge(
            array_keys(is_array($mapa['columnas'] ?? null) ? $mapa['columnas'] : []),
            array_keys(is_array($mapa['reservadas'] ?? null) ? $mapa['reservadas'] : [])
        );

        $cuantas = 0;

        foreach ($letras as $letra) {
            if ($this->interpretar($this->lector->celda($hoja, (string) $letra.$fila))['tipo'] !== 'vacia') {
                $cuantas++;
            }
        }

        return $cuantas;
    }

    /**
     * El `id` de una fila de la F6: estable para el mismo archivo y opaco para el
     * front.
     *
     * **Lleva dentro lo que el docente escribió**, no sólo la fila. Es a propósito:
     * si corrige el nombre y vuelve a subir, la pregunta es otra y tiene que
     * volver a contestarse — una decisión que sobreviviera a cambiar el nombre
     * apuntaría a una persona distinta de la que se aprobó.
     */
    private function idDeLaFila(string $hoja, int $fila, string $tipo, string $clave): string
    {
        return substr(sha1($hoja.'|'.$fila.'|'.$tipo.'|'.$clave), 0, 16);
    }

    /**
     * El sexo **crudo, tal y como está en la columna**, o `null` si está vacío.
     *
     * Viaja porque el botón que confirma un emparejamiento dice «Sí, es él» o «Sí,
     * es ella» delante de una persona con nombre y foto, y **adivinarlo por el
     * nombre falla justo ahí**: José María, Guadalupe, un nombre que el colegio
     * escribió abreviado. Si no llega, la pantalla tiene que caer a una fórmula
     * neutra.
     *
     * **Y no se traduce a una etiqueta.** El rótulo es cosa de la pantalla; aquí
     * sale el valor de `alumnos.sexo`, que es el mismo que sirve
     * {@see Grupo::alumnos} a todas las demás listas de personas. Un
     * servidor que mandara «Masculino» obligaría al front a deshacer la traducción
     * para poder escribir «él».
     */
    private function sexo(mixed $valor): ?string
    {
        $sexo = trim((string) ($valor ?? ''));

        return $sexo === '' ? null : $sexo;
    }

    /**
     * «matriculado desde el 3 de febrero», con el mes en palabras.
     *
     * Los meses van a mano y **no con `strftime()` ni con `IntlDateFormatter`** por
     * lo mismo que el `strtr` de {@see ParecidoDeNombres::normalizar}: dependen del
     * locale del servidor, y los dieciséis colegios no corren en el mismo. Una
     * fecha que sale en inglés en un colegio y en español en otro es un fallo que
     * sólo se ve en producción.
     */
    private function desdeCuando(string $fecha): string
    {
        $meses = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto',
            'septiembre', 'octubre', 'noviembre', 'diciembre'];

        $marca = strtotime($fecha);

        if ($marca === false) {
            return 'matriculado desde el '.$fecha;
        }

        return 'matriculado desde el '.((int) date('j', $marca)).' de '.$meses[((int) date('n', $marca)) - 1];
    }

    /**
     * Por qué ya no está: se retiró, se trasladó, o simplemente no está.
     *
     * El traslado manda sobre el retiro cuando se dan los dos, y es lo correcto:
     * salir de un grupo para entrar en otro **se escribe como un retiro** en la
     * matrícula vieja, así que quedarse con ésa diría «se retiró del colegio» de un
     * alumno que está pasillo abajo.
     *
     * @param  list<int>  $alumnos
     * @return array<int, string>
     */
    private function porQueYaNoEstan(int $grupoId, int $yearId, array $alumnos, string $grupo): array
    {
        if ($alumnos === []) {
            return [];
        }

        $estados = LaPlanillaQueSeDescarga::ESTADOS;
        $marcas = implode(',', array_fill(0, count($alumnos), '?'));

        $filas = DB::select(
            "SELECT m.alumno_id, m.grupo_id, m.estado, m.fecha_retiro, m.razon_retiro, m.deleted_at,
                    g.nombre AS nombre_grupo, g.abrev
               FROM matriculas m
               INNER JOIN grupos g ON g.id = m.grupo_id AND g.year_id = ? AND g.deleted_at IS NULL
              WHERE m.alumno_id IN ({$marcas})
              ORDER BY m.id",
            array_merge([$yearId], $alumnos)
        );

        $motivos = [];

        foreach ($filas as $fila) {
            $alumnoId = (int) $fila->alumno_id;
            $suGrupo = trim((string) ($fila->abrev ?: $fila->nombre_grupo));

            // El traslado: una matrícula viva en OTRO grupo del mismo año.
            if ((int) $fila->grupo_id !== $grupoId && $fila->deleted_at === null
                && str_contains($estados, "'".$fila->estado."'")) {
                $motivos[$alumnoId] = 'Se trasladó a '.$suGrupo.'.';

                continue;
            }

            if ((int) $fila->grupo_id !== $grupoId || isset($motivos[$alumnoId])) {
                continue;
            }

            $razon = trim((string) ($fila->razon_retiro ?? ''));

            if ($fila->fecha_retiro !== null) {
                $motivos[$alumnoId] = 'Se retiró de '.$grupo.' el '
                    .date('d/m/Y', (int) strtotime((string) $fila->fecha_retiro)).'.'
                    .($razon === '' ? '' : ' Motivo: '.$razon.'.');

                continue;
            }

            $motivos[$alumnoId] = 'Su matrícula en '.$grupo.' ya no está vigente (estado '
                .($fila->estado ?? '—').').'.($razon === '' ? '' : ' Motivo: '.$razon.'.');
        }

        return $motivos;
    }

    /**
     * F8 / D5: las columnas `Aus` y `Tar`. **La fase 4.**
     *
     * ## Por qué esta familia es distinta de las otras siete
     *
     * **Una ausencia no es un número: es una fila con fecha.** `ausencias` guarda
     * una por evento, con su `fecha_hora` y su `tipo`, y la columna del Excel sólo
     * puede llevar **el total del periodo**. De ahí sale todo lo demás (§3.6 del
     * plan):
     *
     * - Subir de 2 a 4 **crea dos filas fechadas el día de la importación**, no el
     *   día que el alumno faltó.
     * - Bajar de 4 a 2 **borra** dos filas, y con ellas sus fechas.
     * - **Las planillas de ausencias para acudientes leen esas fechas.**
     *
     * Y de ahí la regla que gobierna la fase entera: **subir es añadir y baja el
     * listón; bajar es borrar historia y no ocurre sin que alguien lo pida.** Son
     * los dos defectos asimétricos de {@see RespuestasDeLaPlanilla}, y la única
     * asimetría de todo el asistente.
     *
     * ## La D3 vale igual aquí, con el espejo que la fase 4 le añadió al libro
     *
     * Las tres puntas son las mismas —espejo, base, archivo— y la regla 1 es la
     * misma: **si el archivo trae lo mismo que el espejo, no se toca nada aunque la
     * base haya cambiado**. Es exactamente el caso que hace falta proteger: el
     * docente baja el libro, no toca la columna `Aus`, y mientras tanto secretaría
     * anota dos faltas en la web. Sin el espejo, el archivo diría «2» contra una
     * base de «4» y el importador borraría las dos.
     *
     * **Un libro de la versión de formato anterior no trae ese espejo**, y entonces
     * esta familia se comporta como si no lo hubiera: se compara archivo contra
     * base, no hay choques que declarar y los defectos siguen siendo los de la
     * dirección. No revienta y no se calla.
     *
     * ## Sólo la rejilla
     *
     * Las filas escritas a mano del bloque del final (F6) **no entran aquí**, y no
     * es un olvido: esas casillas nacen vacías en el libro, así que leerlas sería
     * ver un hueco donde el sistema tiene faltas y eso se leería como «bajar a 0»
     * en cada fila que alguien resolviera. Una fila a mano trae notas; la asistencia
     * de esa persona se toca en su fila de la rejilla o no se toca.
     *
     * ## Y sólo los que siguen matriculados
     *
     * El alumno cuyo `ID` estaba al descargar y hoy ya no está en el grupo (F6,
     * `ya_no_esta_en_el_grupo`) **no entra**, por lo mismo que sus notas: la
     * escritura se salta su fila entera, así que enseñar un renglón decidible sobre
     * sus faltas sería prometer algo que la importación no va a hacer. Lo que se le
     * dice es lo que ya dice su renglón de la F6 — se retiró, y sus casillas se
     * quedan fuera.
     *
     * @param  array<string, mixed>  $mapa
     * @param  list<int>  $alumnos  los de la rejilla
     * @param  array<int, mixed>  $matriculados  los que siguen en el grupo
     * @param  array<int, string>  $nombres
     * @return array<int, list<array{tipo:string, tipo_db:string, direccion:string, cuantas:int}>>
     *                                                                                             lo que hay que escribir, por alumno
     */
    private function estudiarLasAusencias(string $hoja, array $mapa, int $asignaturaId, int $periodoId,
        array $alumnos, array $matriculados, ?string $asignatura, array $nombres): array
    {
        $letras = [
            RespuestasDeLaPlanilla::AUSENCIAS => (string) ($mapa['columna_aus'] ?? ''),
            RespuestasDeLaPlanilla::TARDANZAS => (string) ($mapa['columna_tar'] ?? ''),
        ];

        if ($letras[RespuestasDeLaPlanilla::AUSENCIAS] === ''
            || $letras[RespuestasDeLaPlanilla::TARDANZAS] === ''
            || $alumnos === []) {
            return [];
        }

        // El singular es el de la base (`ausencias.tipo`); el plural es el del
        // contrato del front. Se cruzan aquí y en ningún otro sitio.
        $enLaBase = [
            RespuestasDeLaPlanilla::AUSENCIAS => 'ausencia',
            RespuestasDeLaPlanilla::TARDANZAS => 'tardanza',
        ];

        $base = $this->asistenciaDeHoy($asignaturaId, $periodoId, $alumnos);
        $aEscribir = [];

        foreach (($mapa['filas'] ?? []) as $fila => $alumno) {
            $alumnoId = (int) $alumno;

            // El que ya no está en el grupo se salta entero, igual que sus notas: la
            // escritura no toca su fila, así que un renglón decidible sobre sus faltas
            // sería una promesa que nadie va a cumplir.
            if (! isset($matriculados[$alumnoId])) {
                continue;
            }

            foreach ($letras as $tipo => $letra) {
                $leido = $this->interpretar($this->lector->celda($hoja, $letra.(int) $fila));

                // **Sólo un entero no negativo cuenta como un conteo.** Una casilla
                // vacía es la D9 —«no la toques»— y un `4,5`, un guion o un texto en
                // una columna que cuenta faltas no tienen lectura posible: no hay
                // «media falta» ni «borrar la cuenta». No se declaran como problema
                // (F4) porque no son casillas de nota y arrastrarlas a esa pantalla
                // ofrecería «interpretar como N» sobre algo que no es una nota.
                if ($leido['tipo'] !== 'entero' || $leido['valor'] < 0) {
                    continue;
                }

                $renglon = $this->decidirElConteo(
                    $hoja, $alumnoId, $tipo, (int) $leido['valor'],
                    $base[$alumnoId][$enLaBase[$tipo]] ?? 0,
                    $this->delEspejoDeLaAsistencia($mapa, $alumnoId, $tipo),
                    $this->porQueNoHayEspejo($mapa, $alumnoId, $tipo),
                    $asignatura, $nombres[$alumnoId] ?? null
                );

                if ($renglon === null) {
                    continue;
                }

                $this->ausencias[] = $renglon;

                $this->totales[$renglon['direccion'] === RespuestasDeLaPlanilla::SUBE
                    ? 'ausencias_que_suben'
                    : 'ausencias_que_bajan']++;

                if ($renglon['decision'] !== RespuestasDeLaPlanilla::AUSENCIAS_APLICAR) {
                    continue;
                }

                $aEscribir[$alumnoId][] = [
                    'tipo' => $tipo,
                    'tipo_db' => $enLaBase[$tipo],
                    'direccion' => $renglon['direccion'],
                    'cuantas' => $renglon['cuantas'],
                ];
            }
        }

        return $aEscribir;
    }

    /**
     * Un conteo: las tres puntas, la dirección y la decisión. `null` si no se toca.
     *
     * Es la {@see decidirLaCelda} de la F8, y las dos reglas que la separan de
     * aquélla están escritas dentro:
     *
     * 1. **El choque lo gana el sistema y aquí no hay excepción que valga.** En las
     *    notas la pantalla de §6.3 deja repasarlos uno a uno; el contrato de esta
     *    familia se decide por columna y dirección, así que no hay sitio donde
     *    contestar «en este alumno manda el archivo». Antes que inventarlo, **se
     *    deja ganar al sistema siempre** y el renglón lo dice: el conteo cambió en
     *    los dos sitios y quien lo tocó en la web sabía lo que hacía.
     * 2. **El defecto depende de la dirección**, y es lo único asimétrico de todo
     *    el asistente.
     *
     * ## Y la tercera, que es la que nadie adivina: LA DIRECCIÓN SE MIDE CONTRA LA BASE
     *
     * *(Añadido el 21 sep 2026, preguntándolo el front. El plan no lo contesta.)*
     *
     * Las tres puntas pueden apuntar a sitios distintos: **el libro pide 4, al
     * descargar había 2, y el sistema tiene ahora 5**. Respecto al espejo eso es una
     * subida; respecto a la base es una bajada. `direccion` dice **`baja`**, y el
     * criterio es el de la fase entera: *lo que importa es lo que se va a borrar, y
     * ahí se van a borrar tres filas con sus fechas*. Llamarlo `sube` lo metería en
     * el grupo cuyo defecto es `aplicar`, y borraría historia con el defecto de
     * añadir — exactamente lo que esta fase existe para no hacer.
     *
     * En ese caso concreto hay además una segunda red: `base !== espejo` lo convierte
     * en **choque**, así que gana el sistema y no se toca nada. Pero la red no se
     * puede usar como argumento, porque **un libro sin espejo no la tiene** —formato
     * 1— y ahí la dirección es lo único que separa «añadir» de «borrar».
     *
     * @param  ?string  $sinEspejo  por qué no hay espejo, para que la frase lo diga
     * @return ?array<string, mixed>
     */
    private function decidirElConteo(string $hoja, int $alumnoId, string $tipo, int $archivo, int $base,
        ?int $espejo, ?string $sinEspejo, ?string $asignatura, ?string $alumno): ?array
    {
        // **La D3, regla 1, y es la que protege la historia.** El docente no tocó la
        // columna: lo que traiga la base es de quien la tocó en la web y no se pisa.
        if ($espejo !== null && $archivo === $espejo) {
            return null;
        }

        // Ya está como el archivo lo pide. Pasa de verdad y a menudo: al reanudar una
        // importación cortada, las filas ya aplicadas llegan aquí con la base movida.
        if ($archivo === $base) {
            return null;
        }

        // **Contra la base y no contra el espejo.** Ver la cabecera: lo que decide si
        // esto es «añadir» o «borrar historia» es lo que va a pasarle a las filas que
        // hay hoy, no de dónde venía el número.
        $sube = $archivo > $base;
        $direccion = $sube ? RespuestasDeLaPlanilla::SUBE : RespuestasDeLaPlanilla::BAJA;
        $cuantas = abs($archivo - $base);
        $choque = $espejo !== null && $base !== $espejo;

        $porDefecto = RespuestasDeLaPlanilla::porDefectoSegunLaDireccion($direccion);

        // **La llave es la tripleta `hoja` + `tipo` + `direccion`**, y la dirección no
        // es un adorno de la llave: en la misma columna de la misma hoja es normal que
        // a unos alumnos les suban las faltas y a otros les bajen. Agrupar por el par y
        // deducir la dirección contestaría el grupo mixto con **una sola** entrada, y
        // la mitad se aplicaría con el defecto de la otra mitad.
        $decision = $choque
            ? RespuestasDeLaPlanilla::AUSENCIAS_DEJAR
            : $this->respuestas->queHacerConLasAusencias($hoja, $tipo, $direccion);

        $palabra = $tipo === RespuestasDeLaPlanilla::AUSENCIAS ? 'ausencia' : 'tardanza';

        return [
            'id' => substr(sha1($hoja.'|'.$alumnoId.'|'.$tipo), 0, 16),
            'hoja' => $hoja,
            'asignatura' => $asignatura,
            'alumno' => $alumno,
            'alumno_id' => $alumnoId,
            'tipo' => $tipo,
            'espejo' => $espejo,
            'base' => $base,
            'archivo' => $archivo,
            'direccion' => $direccion,
            'cuantas' => $cuantas,
            'choque' => $choque,

            // **De más: el front no los lee y viajan igual.** El renglón del contrato
            // no tiene `decision`, así que la pantalla pinta el defecto por su cuenta y
            // **manda la sección entera con las dos decisiones explícitas** — como ya
            // hace con `choques`. Estos dos campos son para lo otro: `por_defecto` deja
            // comparar de un vistazo lo que el servidor haría con lo que la pantalla
            // pintó, y `decision` es **lo que de verdad va a pasar con las respuestas
            // que llegaron**, que es lo que hace que volver a ensayar enseñe el plan
            // corregido y no el de antes de decidir. De `decision` salen además los
            // avisos de después de escribir.
            'por_defecto' => $porDefecto,
            'decision' => $decision,

            'si_no_hago_nada' => $this->siNoHagoNadaConElConteo($choque, $sube, $cuantas, $base, $archivo,
                $palabra, $porDefecto, $espejo === null ? $sinEspejo : null),
        ];
    }

    /**
     * La frase de «qué pasa si no hago nada», que **la escribe el servidor**.
     *
     * Y con el espejo dentro cuando no lo hay, que es lo que el front no puede
     * deducir: `espejo: null` pintado como un guion no distingue *«el libro es de
     * una versión anterior y no guarda cuántas faltas había al descargarlo»* de
     * *«había cero»* — y la segunda es un número, no un desconocido. Las dos cosas
     * cambian lo que uno haría con el renglón, así que van en la frase que el
     * docente ya está mirando.
     */
    private function siNoHagoNadaConElConteo(bool $choque, bool $sube, int $cuantas, int $base, int $archivo,
        string $palabra, string $porDefecto, ?string $sinEspejo): string
    {
        $plural = $cuantas === 1 ? '' : 's';
        $coletilla = $sinEspejo === null ? '' : ' '.$sinEspejo;

        if ($choque) {
            return 'Este conteo cambió en el archivo y en el sistema desde que se bajó el libro: el archivo '
                .'dice '.$archivo.' y ahora hay '.$base.'. **Manda el sistema** y se queda en '.$base
                .'. Si el número bueno es el del archivo, corríjalo en la pantalla de asistencia.';
        }

        if ($sube) {
            return ($porDefecto === RespuestasDeLaPlanilla::AUSENCIAS_APLICAR
                ? 'Se crearán '.$cuantas.' '.$palabra.$plural.' con la fecha del día en que se importe, '
                    .'no la del día que faltó: la columna sólo trae el total. Pasará de '.$base.' a '.$archivo.'.'
                : 'No se crea nada: se queda en '.$base.'.').$coletilla;
        }

        return 'No se borra nada: se queda en '.$base.'. Bajarlo a '.$archivo.' significa **borrar '
            .$cuantas.' '.$palabra.$plural.' con su fecha**, que son las que salen en la planilla de '
            .'ausencias del acudiente, así que sólo pasa si se pide.'.$coletilla;
    }

    /**
     * Por qué este renglón viene **sin espejo**, dicho con palabras.
     *
     * `null` cuando sí lo hay. Las tres causas son distintas y sólo una es un
     * problema del libro, así que decir «no hay espejo» a las tres sería no decir
     * nada:
     *
     * 1. **Firma rota** (peldaño 2): el espejo existe y no se puede creer.
     * 2. **Formato anterior**: el libro se bajó antes de que esto se guardara.
     * 3. **El alumno no estaba** en la rejilla al descargar el libro.
     *
     * @param  array<string, mixed>  $mapa
     */
    private function porQueNoHayEspejo(array $mapa, int $alumnoId, string $tipo): ?string
    {
        if ($this->delEspejoDeLaAsistencia($mapa, $alumnoId, $tipo) !== null) {
            return null;
        }

        if (! $this->lector->firmaValida) {
            return 'Y no se puede saber cuántas faltas había al bajar el libro, porque su firma no cuadra: '
                .'lo que la hoja interna diga del pasado no es de fiar.';
        }

        if (! is_array($mapa['asistencia'] ?? null)) {
            return 'Y no se puede saber cuántas faltas había al bajar el libro: **este libro es de una '
                .'versión anterior** y todavía no las guardaba. Se compara contra lo que hay hoy, así que '
                .'si alguien anotó faltas en la web desde entonces, esa diferencia sale aquí sin ser suya. '
                .'Volver a descargar el libro quita este aviso.';
        }

        return 'Y no se puede saber cuántas faltas había al bajar el libro: este alumno no estaba en la '
            .'lista ese día.';
    }

    /**
     * Los dos conteos de hoy, por alumno y por tipo. **Una consulta por hoja.**
     *
     * Es la misma que usa `LaPlanillaQueSeDescarga::asistenciaDe` para llenar las
     * columnas `Aus` y `Tar`, y tiene que serlo: si contaran distinto, el libro
     * enseñaría un número y el ensayo compararía contra otro.
     *
     * **Cuenta filas** (`COUNT(*)`) y no suma `cantidad_ausencia`. En este proyecto
     * conviven los dos criterios sobre estos mismos datos y **dan números
     * distintos**; el que manda aquí es el que el libro imprimió.
     *
     * @param  list<int>  $alumnos
     * @return array<int, array<string, int>>
     */
    private function asistenciaDeHoy(int $asignaturaId, int $periodoId, array $alumnos): array
    {
        $marcas = implode(',', array_fill(0, count($alumnos), '?'));

        $filas = DB::select(
            "SELECT a.alumno_id, a.tipo, COUNT(*) AS cuantas
               FROM ausencias a
              WHERE a.asignatura_id = ? AND a.periodo_id = ? AND a.deleted_at IS NULL
                AND a.alumno_id IN ({$marcas})
              GROUP BY a.alumno_id, a.tipo",
            array_merge([$asignaturaId, $periodoId], $alumnos)
        );

        $contados = [];

        foreach ($filas as $fila) {
            $contados[(int) $fila->alumno_id][(string) $fila->tipo] = (int) $fila->cuantas;
        }

        return $contados;
    }

    /**
     * El espejo de un conteo: **lo que había el día de la descarga**, o `null`.
     *
     * `null` en tres casos y los tres legítimos, que es la razón de que quien llama
     * tenga que tratarlo como «no comparable» y nunca como cero:
     *
     * 1. **La firma está rota** (peldaño 2). El espejo no vale para nada.
     * 2. **El libro es de la versión de formato 1**, de antes de la fase 4: el mapa
     *    no trae `asistencia` porque cuando se generó nadie la iba a leer.
     * 3. El alumno no estaba en la rejilla al descargar.
     *
     * @param  array<string, mixed>  $mapa
     */
    private function delEspejoDeLaAsistencia(array $mapa, int $alumnoId, string $tipo): ?int
    {
        if (! $this->lector->firmaValida) {
            return null;
        }

        $asistencia = $mapa['asistencia'] ?? null;

        if (! is_array($asistencia)) {
            return null;
        }

        $suya = $asistencia[$alumnoId] ?? $asistencia[(string) $alumnoId] ?? null;

        if (! is_array($suya) || ! isset($suya[$tipo]) || ! is_numeric($suya[$tipo])) {
            return null;
        }

        return (int) $suya[$tipo];
    }

    // ── Lo que sale ──────────────────────────────────────────────────────────

    /**
     * El diagnóstico entero, con **las interfaces de
     * `app2/src/app/datos/planilla-offline.ts`**.
     *
     * @return array<string, mixed>
     */
    private function diagnostico(): array
    {
        $cabecera = $this->lector->cabecera ?? [];

        return [
            'ok' => true,

            // **La huella la pone el controlador**, que es quien tiene el fichero.
            'peldano' => $this->lector->peldano,
            'firma_valida' => $this->lector->firmaValida,
            'version_formato' => $this->lector->versionFormato,

            'libro' => [
                'colegio_ok' => $this->colegioOk,
                'year_id' => isset($cabecera['year_id']) ? (int) $cabecera['year_id'] : null,
                'periodo_id' => isset($cabecera['periodo_id']) ? (int) $cabecera['periodo_id'] : null,
                'periodo_numero' => $this->periodoDelLibro === null
                    ? (isset($cabecera['periodo_numero']) ? (int) $cabecera['periodo_numero'] : null)
                    : (int) $this->periodoDelLibro->numero,

                // **El de HOY, no el que el libro llevaba escrito.** Un libro
                // descargado con el periodo abierto y subido después del cierre tiene
                // `periodo_abierto: true` dentro y `false` aquí, y es esto lo que
                // decide (F3).
                'periodo_abierto' => $this->periodoDelLibro === null
                    ? null
                    : (bool) $this->periodoDelLibro->profes_pueden_editar_notas,
                'profesor' => [
                    'id' => isset($cabecera['profesor_id']) ? (int) $cabecera['profesor_id'] : null,
                    'nombre' => $this->profesorDelLibro === null ? null : trim(
                        ($this->profesorDelLibro->nombres ?? '').' '.($this->profesorDelLibro->apellidos ?? '')
                    ),
                ],
                'descargado_at' => $cabecera['generado'] ?? null,
                'es_mio' => $this->esMio,

                /*
                 * Los dos que la pantalla necesita para no adivinar: `puede_por_otro`
                 * decide si enseña la confirmación o el callejón sin salida, y
                 * `por_otro_confirmado` si el botón de importar puede encenderse.
                 * Con el libro propio los dos son irrelevantes y van en `false`.
                 */
                /*
                 * `colegioOk` TAMBIEN, y no es de adorno: cuando el libro es de otro
                 * colegio o de otro año, el metodo de arriba vuelve antes de mirar de
                 * quien es, asi que `esMio` se queda en `false` por no haberse
                 * preguntado nunca --no por ser de otro docente--. Sin esta condicion,
                 * `! $esMio && puede…` da `true` y la pantalla ofreceria confirmar
                 * «subo por esa persona» al lado de un bloqueo `libro_de_otro_sitio`
                 * que ninguna confirmacion resuelve. Un boton que no lleva a ninguna
                 * parte es peor que no tener boton.
                 */
                'puede_por_otro' => $this->colegioOk && ! $this->esMio
                    && Autoriza::puedeSubirLaPlanillaDeOtro($this->usuario),
                'por_otro_confirmado' => $this->porOtroConfirmado,
            ],

            // **UN PLAN RECORTADO NO PUEDE DECIR QUE SÍ**, y `null` no es `false`:
            // no es «va a fallar», es «no se sabe», y son dos frases distintas para
            // quien decide.
            'puede_importarse' => $this->recortado ? null : $this->bloqueos === [],
            'completo' => ! $this->recortado,
            'filas_estudiadas' => $this->filasEstudiadas,
            'filas_del_libro' => $this->filasDelLibro,

            'bloqueos' => $this->bloqueos,
            'bloqueos_resueltos' => $this->bloqueosResueltos,

            'hojas' => $this->hojas,
            'totales' => $this->totales,
            'por_hoja' => $this->porHoja,

            'respuestas' => $this->respuestas->hayAlguna() ? $this->respuestas->resumen() : null,

            'familias' => [
                'estructura' => $this->estructura,
                'celdas' => array_values($this->celdas),
                'escala' => array_values($this->escala),
                'choques' => $this->choques,
                'reserva' => $this->reserva,

                // **F6, y desde la fase 3 es una decisión de verdad y no un aviso.**
                // La única familia cuya llave es la fila, porque cada fila es una
                // persona distinta.
                'filas' => $this->filas,

                // **F8, y desde la fase 4 también es una decisión.** Con dos defectos
                // distintos según la dirección —el único sitio del asistente donde eso
                // pasa—, porque subir añade y bajar borra historia.
                'ausencias' => $this->ausencias,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $ficha
     * @param  array<string, int>  $cuentas
     * @param  ?array<string, mixed>  $delPlan
     */
    private function cerrarHoja(array $ficha, array $cuentas, ?array $delPlan): void
    {
        $ficha['casillas'] = $cuentas['casillas'];
        $ficha['cambiaron'] = $cuentas['cambiaron'];
        $ficha['entran'] = $cuentas['entran'];
        $ficha['problemas'] = $cuentas['problemas'];

        $this->hojas[] = $ficha;

        $this->porHoja[] = [
            'hoja' => $ficha['nombre'],
            'asignatura' => $ficha['asignatura'],
            'asignatura_id' => $ficha['asignatura_id'],
            'entran' => $cuentas['entran'],
            'se_borran' => $cuentas['se_borran'],
            'se_quedan_fuera' => $cuentas['se_quedan_fuera'],
            'sin_pasar' => $cuentas['sin_pasar'],

            /*
             * **Ya se contaba por hoja y no salía de aquí** (`$cuentas` lo lleva desde
             * la fase 3); lo que faltaba era publicarlo. Lo estrena el acta de la fase
             * 5, que tiene que decir **por hoja** cuántas filas se descartaron, y
             * dividir el total del libro entre las hojas después es imposible: la F6
             * agrupa por fila, no por asignatura.
             */
            'filas_descartadas' => $cuentas['filas_descartadas'],

            // La frase corta de la fila: el motivo si la hoja se cae, y si no, lo que
            // queda por pasar. **`null` cuando no hay nada que decir**, para que la
            // pantalla no tenga que decidir si una cadena vacía es un aviso.
            'nota_pendiente' => $this->notaPendiente($ficha, $cuentas),
        ];

        if ($delPlan !== null) {
            $this->plan[] = $delPlan;
        }
    }

    /**
     * @param  array<string, mixed>  $ficha
     * @param  array<string, int>  $cuentas
     */
    private function notaPendiente(array $ficha, array $cuentas): ?string
    {
        if ($ficha['fuera']) {
            return $ficha['motivo_fuera'];
        }

        if ($cuentas['sin_pasar'] > 0) {
            return 'Quedan '.$cuentas['sin_pasar'].' casilla(s) sin calificar en esta hoja.';
        }

        return null;
    }

    // ── Leer una celda ───────────────────────────────────────────────────────

    /**
     * Qué es lo que hay escrito en una casilla de nota.
     *
     * Las cuatro respuestas posibles y por qué son cuatro y no dos:
     *
     * - **`vacia`** — D9: no se toca.
     * - **`borrar`** — el guion: `nota` a `NULL`. Es la otra mitad de la D9 y la
     *   única forma de quitar una nota desde el Excel.
     * - **`entero`** — lo normal. `notas.nota` es `int` (§3.1).
     * - **`problema`** — todo lo demás, **con su tipo**: `decimal`, `formula` o
     *   `texto`. El tipo importa porque las tres se arreglan distinto, y porque un
     *   `4,5` que se guardara como `4` sería *«la peor forma de perder una nota»*:
     *   sin error y sin aviso.
     *
     * @return array{tipo:string, valor:?int, texto:string, clase:?string}
     */
    private function interpretar(mixed $crudo): array
    {
        if ($crudo === null) {
            return ['tipo' => 'vacia', 'valor' => null, 'texto' => '', 'clase' => null];
        }

        if (is_bool($crudo)) {
            return ['tipo' => 'problema', 'valor' => null, 'texto' => $crudo ? 'VERDADERO' : 'FALSO',
                'clase' => 'texto'];
        }

        if (is_int($crudo)) {
            return ['tipo' => 'entero', 'valor' => $crudo, 'texto' => (string) $crudo, 'clase' => null];
        }

        if (is_float($crudo)) {
            $texto = $this->textoDelNumero($crudo);

            return $crudo === floor($crudo)
                ? ['tipo' => 'entero', 'valor' => (int) $crudo, 'texto' => $texto, 'clase' => null]
                : ['tipo' => 'problema', 'valor' => null, 'texto' => $texto, 'clase' => 'decimal'];
        }

        $texto = trim((string) $crudo);

        if ($texto === '') {
            return ['tipo' => 'vacia', 'valor' => null, 'texto' => '', 'clase' => null];
        }

        // **El guion, y los tres que la gente escribe.** El libro pide `-` en su
        // portada, pero el guion largo y el corto se cuelan solos —el corrector de
        // Word y el teclado del móvil los ponen— y negarse a entenderlos sería
        // castigar un acierto.
        if (in_array($texto, ['-', '–', '—'], true)) {
            return ['tipo' => 'borrar', 'valor' => null, 'texto' => $texto, 'clase' => null];
        }

        if (str_starts_with($texto, '=')) {
            return ['tipo' => 'problema', 'valor' => null, 'texto' => $texto, 'clase' => 'formula'];
        }

        if (is_numeric($texto)) {
            $numero = (float) $texto;

            return $numero === floor($numero)
                ? ['tipo' => 'entero', 'valor' => (int) $numero, 'texto' => $texto, 'clase' => null]
                : ['tipo' => 'problema', 'valor' => null, 'texto' => $texto, 'clase' => 'decimal'];
        }

        // Un `4,5` tecleado con coma no es numérico para PHP y **no se convierte en
        // silencio**: se declara como decimal, que es lo que es, y la persona decide
        // si vale 4 o 5. Convertirlo aquí sería decidir por ella una nota.
        if (preg_match('/^-?\d+,\d+$/', $texto) === 1) {
            return ['tipo' => 'problema', 'valor' => null, 'texto' => $texto, 'clase' => 'decimal'];
        }

        return ['tipo' => 'problema', 'valor' => null, 'texto' => $texto, 'clase' => 'texto'];
    }

    /** Un float en el texto más corto que lo representa, para poder llavear por él. */
    private function textoDelNumero(float $valor): string
    {
        $texto = rtrim(rtrim(number_format($valor, 6, '.', ''), '0'), '.');

        return $texto === '' || $texto === '-' ? '0' : $texto;
    }

    /**
     * Anota un renglón de la F4, **agrupando por el valor**.
     *
     * Trece renglones se revisan; ochocientos no. Es la regla del importador de
     * alumnos y aquí es lo único que hace usable la pantalla: una hoja con doce
     * `4,5` es **una** pregunta.
     *
     * @param  array{tipo:string, valor:?int, texto:string, clase:?string}  $leido
     * @param  array{decision:string, nota:?int}  $decidido
     */
    private function anotarCelda(array $leido, array $decidido, string $hoja, int $fila, string $columna,
        ?string $alumno): void
    {
        $llave = $leido['texto'];

        if (! isset($this->celdas[$llave])) {
            $this->celdas[$llave] = [
                'valor' => $leido['texto'],
                'veces' => 0,

                // `tipo` no está en la interfaz del front y se manda igual: es lo único
                // legible por máquina de esta familia —`decimal`, `formula`, `texto`— y
                // sin él lo único que queda para distinguirlas es el texto del motivo,
                // que está escrito para leerse, no para compararse.
                'tipo' => $leido['clase'],
                'motivo' => $this->motivoDeLaCelda($leido['clase']),
                'donde' => [],
                'si_no_hago_nada' => 'Esas casillas se quedan como están: no se escribe nada en ellas.',

                // **Una sugerencia, nunca un valor aplicado.** El defecto sigue siendo
                // no escribir: sugerir un 5 para un `4,5` ayuda a decidir, aplicarlo
                // sería decidir por el docente la nota de un alumno.
                'sugerido' => $this->sugerirPara($leido),
                'decidible' => true,
            ];
        }

        $this->celdas[$llave]['veces']++;
        $this->celdas[$llave]['decision'] = $decidido['decision'];

        if (count($this->celdas[$llave]['donde']) < self::EJEMPLOS) {
            $this->celdas[$llave]['donde'][] = $this->donde($hoja, $fila, $columna, $alumno);
        }
    }

    /**
     * Qué número propondría el servidor para un valor que no es entero.
     *
     * Sólo para los decimales, y **redondeando**: es lo que el docente quiso decir
     * con un `4,5` en una escala de enteros. Para una fórmula o un texto no hay nada
     * que sugerir, y devolver un cero sería inventarse una nota.
     *
     * @param  array{tipo:string, valor:?int, texto:string, clase:?string}  $leido
     */
    private function sugerirPara(array $leido): ?int
    {
        if ($leido['clase'] !== 'decimal') {
            return null;
        }

        $texto = str_replace(',', '.', $leido['texto']);

        return is_numeric($texto) ? (int) round((float) $texto) : null;
    }

    private function motivoDeLaCelda(?string $clase): string
    {
        return match ($clase) {
            'decimal' => 'La nota de un indicador es un número ENTERO en MyVc. Un decimal se guardaría '
                .'recortado, sin error y sin aviso, que es la peor forma de perder una nota.',
            'formula' => 'La casilla tiene una fórmula, no un número. Lo que se importa es lo que está '
                .'escrito, y una fórmula no se puede guardar en una nota.',
            default => 'La casilla no tiene un número entero.',
        };
    }

    /** Anota un renglón de la F5, también agrupando por el valor. */
    private function anotarEscala(int $valor, string $decision, ?int $topado, int $yearId,
        string $hoja, int $fila, string $columna, ?string $alumno): void
    {
        $llave = (string) $valor;

        if (! isset($this->escala[$llave])) {
            $maximo = EscalaDeNotas::maximo($yearId);
            $minimo = EscalaDeNotas::minimo($yearId);

            $this->escala[$llave] = [
                'valor' => $valor,
                'veces' => 0,
                'maximo' => $maximo,
                'minimo' => $minimo,
                'donde' => [],
                'alumnos' => [],
                'si_no_hago_nada' => 'Esas notas no se importan: '.$valor.' no cabe en la escala de este año'
                    .($maximo === null ? '.' : ', que va de '.($minimo ?? 0).' a '.$maximo.'.')
                    .($topado === null ? '' : ' Si decide topar, se guardarían como '.$topado.'.'),
            ];
        }

        $this->escala[$llave]['veces']++;
        $this->escala[$llave]['decision'] = $decision;

        if (count($this->escala[$llave]['donde']) < self::EJEMPLOS) {
            $this->escala[$llave]['donde'][] = $this->donde($hoja, $fila, $columna, $alumno);

            if ($alumno !== null && ! in_array($alumno, $this->escala[$llave]['alumnos'], true)) {
                $this->escala[$llave]['alumnos'][] = $alumno;
            }
        }
    }

    /** Dónde está una celda, en una frase que se pueda leer en pantalla. */
    private function donde(string $hoja, int $fila, string $columna, ?string $alumno): string
    {
        return '«'.$hoja.'» '.($alumno ?? 'fila '.$fila).', columna '.$columna;
    }

    /** El valor topado al techo o al suelo de la escala, o `null` si no hay escala. */
    private function topar(int $valor, int $yearId): ?int
    {
        $maximo = EscalaDeNotas::maximo($yearId);
        $minimo = EscalaDeNotas::minimo($yearId) ?? 0;

        if ($maximo === null) {
            return null;
        }

        return $valor > $maximo ? $maximo : ($valor < $minimo ? $minimo : $valor);
    }

    /**
     * El `id` de un choque: estable para el mismo archivo y opaco para el front.
     *
     * La hoja, el alumno y el indicador son las tres cosas que identifican una
     * casilla, y ninguna se mueve al reenviar el fichero — que es la propiedad que
     * hace falta: el docente decide sobre la lista del ensayo y sube después, y la
     * decisión tiene que seguir apuntando a la misma nota.
     *
     * **Con una excepción, y llegó con la F6 (fase 3):** una fila escrita a mano que
     * se resolvió en un alumno **que ya estaba en la rejilla** produce una casilla
     * con la misma hoja, el mismo alumno y el mismo indicador que la de su fila de
     * arriba — el caso de *Cárdenas / Cardenaz*, que es el frecuente. Las dos pueden
     * chocar a la vez y con valores distintos, así que la de abajo lleva su fila
     * dentro del `id`. **Los de la rejilla no cambian**, que es lo que deja que unas
     * respuestas guardadas antes de este cambio sigan apuntando a lo mismo.
     */
    private function idDelChoque(string $hoja, int $alumnoId, int $subunidadId, ?int $filaAMano = null): string
    {
        $llave = $hoja.'|'.$alumnoId.'|'.$subunidadId.($filaAMano === null ? '' : '|f'.$filaAMano);

        return substr(sha1($llave), 0, 16);
    }

    // ── Consultas ────────────────────────────────────────────────────────────

    /** @return array<int, object> */
    private function subunidadesVivas(int $asignaturaId, int $periodoId): array
    {
        $filas = DB::select(
            'SELECT s.id, s.definicion, s.porcentaje, s.unidad_id
               FROM subunidades s
               INNER JOIN unidades u ON u.id = s.unidad_id AND u.deleted_at IS NULL
                                    AND u.asignatura_id = ? AND u.periodo_id = ? AND u.alumno_id IS NULL
              WHERE s.deleted_at IS NULL',
            [$asignaturaId, $periodoId]
        );

        $vivas = [];

        foreach ($filas as $fila) {
            $vivas[(int) $fila->id] = $fila;
        }

        return $vivas;
    }

    /**
     * Las unidades de la hoja **en el mismo orden que las pintó el libro**.
     *
     * `ORDER BY u.orden, u.id` y no otro: es el de `LaPlanillaQueSeDescarga`, y de
     * él depende que el número de la banda (`"2 · Geometría"`) siga apuntando a la
     * misma unidad al volver. Cambiarlo en un sitio y no en el otro haría que la F9
     * creara el indicador en la unidad de al lado, **sin error**.
     *
     * @return list<object>
     */
    private function unidadesVivas(int $asignaturaId, int $periodoId): array
    {
        return array_values(DB::select(
            'SELECT u.id, u.definicion, u.porcentaje, u.orden
               FROM unidades u
              WHERE u.asignatura_id = ? AND u.periodo_id = ?
                AND u.deleted_at IS NULL AND u.alumno_id IS NULL
              ORDER BY u.orden, u.id',
            [$asignaturaId, $periodoId]
        ));
    }

    /**
     * Cuánto peso le queda libre a una unidad, para proponerlo al crear (F9).
     *
     * En modo `porcentaje` el Logro tiene que seguir sumando 100 (§9.7), así que lo
     * que falta para llegar es la única propuesta que no obliga a tocar notas ya
     * guardadas. **Es una sugerencia y no un cálculo que se aplique**: si la unidad
     * ya suma 100, sugiere 0 y el docente tendrá que repartir a mano —repartirlo
     * aquí cambiaría definitivas sin decírselo a nadie.
     *
     * @param  array<int, object>  $vivas
     */
    private function pesoQueFalta(object $unidad, array $vivas): int
    {
        $suma = 0;

        foreach ($vivas as $subunidad) {
            if ((int) $subunidad->unidad_id === (int) $unidad->id) {
                $suma += (int) $subunidad->porcentaje;
            }
        }

        return max(0, 100 - $suma);
    }

    /**
     * Quién sigue matriculado en el grupo, **con su ficha entera**.
     *
     * Con la misma lista de estados que armó la hoja
     * ({@see LaPlanillaQueSeDescarga::ESTADOS}), que es lo que hace que «se retiró»
     * signifique lo mismo en los dos lados.
     *
     * Devuelve la ficha y no sólo el nombre desde la fase 3: la F6 necesita **la
     * foto, la matrícula y desde cuándo está** para pintar la tarjeta del §6.4, y
     * son los mismos campos con los que {@see Grupo::alumnos} sirve
     * cualquier lista de personas de MyVc —incluida la caída al avatar por sexo—,
     * para que el front pueda usar el pipe `perfil` que ya tiene.
     *
     * **`nombre` va como lo ordena la planilla**, `APELLIDOS, Nombres`: es el orden
     * en el que el docente ve la lista en su hoja, y el emparejador de la F6 compara
     * el conjunto de palabras, así que el orden no le estorba.
     *
     * @return array<int, object{nombre:string, no_matricula:?string, foto:?string, sexo:?string, desde:?string}>
     */
    private function matriculados(int $grupoId): array
    {
        $estados = LaPlanillaQueSeDescarga::ESTADOS;

        $filas = DB::select(
            "SELECT a.id, a.apellidos, a.nombres, a.no_matricula, a.sexo,
                    MIN(m.fecha_matricula) AS desde,
                    IFNULL(i.nombre, IF(a.sexo = 'F', 'default_female.png', 'default_male.png')) AS foto
               FROM alumnos a
               INNER JOIN matriculas m ON m.alumno_id = a.id AND m.grupo_id = ?
                                      AND m.estado IN ({$estados}) AND m.deleted_at IS NULL
               LEFT JOIN images i ON i.id = a.foto_id AND i.deleted_at IS NULL
              WHERE a.deleted_at IS NULL
              GROUP BY a.id, a.apellidos, a.nombres, a.no_matricula, a.sexo, i.nombre",
            [$grupoId]
        );

        $matriculados = [];

        foreach ($filas as $fila) {
            $matriculados[(int) $fila->id] = (object) [
                'nombre' => trim(($fila->apellidos ?? '').', '.($fila->nombres ?? ''), ' ,'),
                'no_matricula' => $fila->no_matricula === null ? null : (string) $fila->no_matricula,
                'foto' => $fila->foto === null ? null : (string) $fila->foto,
                'sexo' => $this->sexo($fila->sexo ?? null),
                'desde' => $fila->desde === null ? null : (string) $fila->desde,
            ];
        }

        return $matriculados;
    }

    /**
     * La ficha de unos alumnos **sin pasar por la matrícula**: nombre, matrícula y
     * foto.
     *
     * Es la hermana de {@see matriculados} para los que **ya no están** en el grupo:
     * ahí no se puede entrar por `matriculas` —que es justo lo que falta— y hace
     * falta igual la foto, porque la tarjeta de «se retiró» es una tarjeta de
     * persona como todas las demás.
     *
     * Se pide **sólo cuando hay alguien fuera**, que en un libro normal no pasa
     * nunca: una consulta que casi siempre se ahorra.
     *
     * @param  list<int>  $alumnos
     * @return array<int, object{nombre:string, no_matricula:?string, foto:?string, sexo:?string}>
     */
    private function fichasDe(array $alumnos): array
    {
        if ($alumnos === []) {
            return [];
        }

        $marcas = implode(',', array_fill(0, count($alumnos), '?'));

        $filas = DB::select(
            "SELECT a.id, a.apellidos, a.nombres, a.no_matricula, a.sexo,
                    IFNULL(i.nombre, IF(a.sexo = 'F', 'default_female.png', 'default_male.png')) AS foto
               FROM alumnos a
               LEFT JOIN images i ON i.id = a.foto_id AND i.deleted_at IS NULL
              WHERE a.id IN ({$marcas})",
            $alumnos
        );

        $fichas = [];

        foreach ($filas as $fila) {
            $fichas[(int) $fila->id] = (object) [
                'nombre' => trim(($fila->apellidos ?? '').', '.($fila->nombres ?? ''), ' ,'),
                'no_matricula' => $fila->no_matricula === null ? null : (string) $fila->no_matricula,
                'foto' => $fila->foto === null ? null : (string) $fila->foto,
                'sexo' => $this->sexo($fila->sexo ?? null),
            ];
        }

        return $fichas;
    }

    /**
     * Las notas que hay **hoy** en la base: la segunda punta de la comparación.
     *
     * Trae `updated_at` y `updated_by` porque la pantalla de choques (§6.3) los
     * necesita: *«cada línea dice cuándo cambió en el sistema; es lo único que
     * permite decidir de verdad»* — y quién lo cambió es la otra mitad de esa
     * frase, porque muchas veces fue el propio docente desde el móvil.
     *
     * @param  list<int>  $alumnos
     * @return array<int, array<int, object>>
     */
    private function notasDeHoy(int $asignaturaId, int $periodoId, array $alumnos): array
    {
        if ($alumnos === []) {
            return [];
        }

        $marcas = implode(',', array_fill(0, count($alumnos), '?'));

        $filas = DB::select(
            "SELECT n.id, n.alumno_id, n.subunidad_id, n.nota, n.updated_at, n.updated_by
               FROM notas n
               INNER JOIN subunidades s ON s.id = n.subunidad_id AND s.deleted_at IS NULL
               INNER JOIN unidades u ON u.id = s.unidad_id AND u.deleted_at IS NULL
                                    AND u.periodo_id = ? AND u.asignatura_id = ? AND u.alumno_id IS NULL
              WHERE n.deleted_at IS NULL AND n.alumno_id IN ({$marcas})",
            array_merge([$periodoId, $asignaturaId], $alumnos)
        );

        $notas = [];

        foreach ($filas as $fila) {
            $notas[(int) $fila->alumno_id][(int) $fila->subunidad_id] = $fila;
        }

        return $notas;
    }

    /**
     * El nombre de quien tocó una nota por última vez.
     *
     * Se resuelve **de una en una y con caché**, no por lotes: en un libro normal no
     * hay choques, y cuando los hay son de dos o tres personas. Precargarlos costaría
     * una consulta en el caso que no los tiene.
     *
     * Cae al `username` cuando no hay ficha de profesor, que es el caso de los
     * administrativos —la misma red que `PuntoDeControlDeImportacion::pendienteDe`
     * documentó con números: de las 22 cuentas de tipo `Usuario`, ninguna tiene ficha.
     */
    private function quienEs(mixed $userId): ?string
    {
        if ($userId === null || ! is_numeric($userId)) {
            return null;
        }

        $id = (int) $userId;

        if (array_key_exists($id, $this->quienes)) {
            return $this->quienes[$id];
        }

        $fila = DB::selectOne(
            'SELECT COALESCE(
                        NULLIF(TRIM(CONCAT(COALESCE(p.nombres, ""), " ", COALESCE(p.apellidos, ""))), ""),
                        u.username
                    ) AS nombre
               FROM users u
               LEFT JOIN profesores p ON p.user_id = u.id AND p.deleted_at IS NULL
              WHERE u.id = ?',
            [$id]
        );

        return $this->quienes[$id] = $fila?->nombre;
    }

    /**
     * Los nombres de los alumnos de la hoja, en una consulta.
     *
     * @param  list<int>  $alumnos
     * @return array<int, string>
     */
    private function nombresDe(array $alumnos): array
    {
        if ($alumnos === []) {
            return [];
        }

        $marcas = implode(',', array_fill(0, count($alumnos), '?'));

        $filas = DB::select(
            "SELECT a.id, a.apellidos, a.nombres FROM alumnos a WHERE a.id IN ({$marcas})",
            $alumnos
        );

        $nombres = [];

        foreach ($filas as $fila) {
            $nombres[(int) $fila->id] = trim(($fila->apellidos ?? '').', '.($fila->nombres ?? ''), ' ,');
        }

        return $nombres;
    }

    /**
     * El valor del espejo de una celda.
     *
     * @param  array<string, mixed>  $mapa
     */
    private function delEspejo(array $mapa, int $alumnoId, int $subunidadId): ?int
    {
        $espejo = $mapa['espejo'][$alumnoId] ?? $mapa['espejo'][(string) $alumnoId] ?? null;

        if (! is_array($espejo)) {
            return null;
        }

        $valor = $espejo[$subunidadId] ?? $espejo[(string) $subunidadId] ?? null;

        return $valor === null ? null : (int) $valor;
    }

    /**
     * Cuántas casillas de una columna traen algo escrito.
     *
     * @param  array<string, mixed>  $mapa
     */
    private function contarColumnaConValor(string $hoja, array $mapa, string $letra): int
    {
        $cuantas = 0;

        foreach (array_keys(is_array($mapa['filas'] ?? null) ? $mapa['filas'] : []) as $fila) {
            if ($this->interpretar($this->lector->celda($hoja, $letra.(int) $fila))['tipo'] !== 'vacia') {
                $cuantas++;
            }
        }

        return $cuantas;
    }

    /**
     * Cuántas casillas de nota traen algo escrito. Para las hojas que no entran.
     *
     * @param  array<string, mixed>  $mapa
     */
    private function contarCeldasConValor(string $hoja, array $mapa): int
    {
        $cuantas = 0;

        foreach (array_keys(is_array($mapa['columnas'] ?? null) ? $mapa['columnas'] : []) as $letra) {
            $cuantas += $this->contarColumnaConValor($hoja, $mapa, (string) $letra);
        }

        return $cuantas;
    }

    /**
     * Los indicadores a los que se puede mover una columna huérfana.
     *
     * Van **con la pregunta y no en otra ruta**: quien tiene que elegir a cuál mover
     * las notas está mirando esta pantalla, y obligarle a otra llamada para saber
     * qué opciones hay es lo que convierte una decisión en un formulario.
     *
     * @param  array<int, object>  $vivas
     * @return list<array{subunidad_id:int, nombre:string}>
     */
    private function destinosPosibles(array $vivas): array
    {
        $destinos = [];

        foreach ($vivas as $id => $suya) {
            $nombre = trim((string) ($suya->definicion ?? ''));

            $destinos[] = [
                'subunidad_id' => $id,
                'nombre' => $nombre === '' ? '(sin descripción)' : $nombre,
            ];
        }

        return $destinos;
    }
}
