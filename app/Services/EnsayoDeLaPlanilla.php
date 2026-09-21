<?php

namespace App\Services;

use App\Support\EscalaDeNotas;
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

    /** @var list<array{hoja:string, descripcion:string}> F6 — avisos, fase 3. */
    private array $avisosDeFilas = [];

    /** @var list<array{hoja:string, descripcion:string}> F8 — avisos, fase 4. */
    private array $avisosDeAusencias = [];

    /** @var array<string, int> */
    private array $totales = [
        'casillas' => 0, 'cambiaron' => 0, 'entran' => 0, 'se_borran' => 0,
        'se_quedan_fuera' => 0, 'filas_descartadas' => 0, 'definitivas_a_recalcular' => 0,
    ];

    /** @var list<array<string, mixed>> */
    private array $porHoja = [];

    /** Los pares (asignatura, periodo) que tocará recalcular. @var array<string, bool> */
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

        foreach (array_merge($this->avisosDeFilas, $this->avisosDeAusencias) as $aviso) {
            $avisos[] = '«'.$aviso['hoja'].'»: '.$aviso['descripcion'];
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
                       .'desde Notas → Trabajar sin internet y escriba las notas sobre ése.',
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

        $this->bloqueos[] = [
            'tipo' => 'libro_de_otro_docente',
            'hoja' => null,
            'motivo' => 'Este libro es de '
                .($this->profesorDelLibro === null
                    ? 'otro docente'
                    : trim(($this->profesorDelLibro->nombres ?? '').' '.($this->profesorDelLibro->apellidos ?? '')))
                .', y por ahora cada docente sólo puede subir el suyo. Subir la planilla de otro '
                .'(coordinación) es la fase 5 de «notas sin internet», y viene con el acta de lo que entró.',
        ];
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
        $alumnos = array_map('intval', array_values($filas));

        $matriculados = $this->matriculados((int) $asignatura->grupo_id);
        $notas = $this->notasDeHoy($asignaturaId, $periodoId, $alumnos);
        $nombres = $this->nombresDe($alumnos);

        $this->avisarDeLasFilas($nombre, $alumnos, $matriculados, $nombres, $filas, $cuentas);
        $this->avisarDeLasAusencias($nombre, $mapa, $asignaturaId, $periodoId, $alumnos);

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

            if ($celdas !== []) {
                $delPlan['filas'][] = [
                    'indice' => $indice, 'fila' => $fila, 'alumno_id' => $alumnoId, 'celdas' => $celdas,
                ];
            }
        }

        $ficha['fuera'] = false;
        $this->pares[$asignaturaId.':'.$periodoId] = true;

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
     * @return list<array<string, mixed>>
     */
    private function estudiarFila(
        string $hoja, int $fila, int $alumnoId, array $columnas, array $nuevas,
        array $notas, array $mapa, array &$cuentas, int $yearId, array $nombres, ?string $asignatura
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

            $espejo = $this->lector->firmaValida
                ? $this->delEspejo($mapa, $alumnoId, (int) $columna['subunidad_id'])
                : null;

            $resultado = $this->decidirLaCelda(
                $hoja, $fila, $letra, $alumnoId, $subunidadId, $columna, $leido,
                $espejo, $base, $actual, $yearId, $nombres, $cuentas, $asignatura
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
                $leido, null, null, null, $yearId, $nombres, $cuentas, $asignatura
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
        array &$cuentas, ?string $asignatura
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
            $id = $this->idDelChoque($hoja, $alumnoId, $subunidadId);
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

    // ── Los dos avisos: las filas (F6) y las ausencias (F8) ──────────────────

    /**
     * F6, la mitad que sí se contesta en esta fase.
     *
     * **No son decisiones del docente** —no se le pregunta nada— sino dos avisos que
     * le dicen a quién le falta pasar la nota y por qué una fila no entró:
     *
     * - quién **se retiró** después de la descarga (su fila no se importa),
     * - quién **entró después** (no tiene casillas en la hoja),
     * - y qué escribió en las tres filas de «alumnos que no aparecen en la lista».
     *
     * El emparejamiento por nombre de esas tres filas —la tarjeta del §6.4 con la
     * foto y el buscador del grupo— **es la fase 3**, y aquí se dice con esas
     * palabras en vez de callarlo: el libro tiene esas filas y el docente las va a
     * usar.
     *
     * @param  list<int>  $alumnos
     * @param  array<int, string>  $matriculados
     * @param  array<int, string>  $nombres
     * @param  array<array-key, mixed>  $filas
     * @param  array<string, int>  $cuentas
     */
    private function avisarDeLasFilas(string $hoja, array $alumnos, array $matriculados, array $nombres,
        array $filas, array &$cuentas): void
    {
        $retirados = [];

        foreach ($alumnos as $alumnoId) {
            if (! isset($matriculados[$alumnoId])) {
                $retirados[] = $nombres[$alumnoId] ?? ('alumno '.$alumnoId);
            }
        }

        if ($retirados !== []) {
            $cuentas['filas_descartadas'] += count($retirados);
            $this->totales['filas_descartadas'] += count($retirados);

            $this->avisosDeFilas[] = [
                'hoja' => $hoja,
                'descripcion' => count($retirados).' alumno(s) de esta hoja ya no están matriculados en el '
                    .'grupo, así que sus filas no se importan: '.implode(', ', $retirados).'.',
            ];
        }

        $nuevos = [];

        foreach ($matriculados as $alumnoId => $quien) {
            if (! in_array($alumnoId, $alumnos, true)) {
                $nuevos[] = $quien;
            }
        }

        if ($nuevos !== []) {
            $this->avisosDeFilas[] = [
                'hoja' => $hoja,
                'descripcion' => count($nuevos).' alumno(s) entraron al grupo después de que usted descargara '
                    .'el libro, así que no tienen casillas en esta hoja y sus notas siguen sin pasar: '
                    .implode(', ', $nuevos).'.',
            ];
        }

        $escritas = $this->lector->filasDeAlumnosNuevos($hoja, $filas);

        if ($escritas !== []) {
            $cuentas['filas_descartadas'] += count($escritas);
            $this->totales['filas_descartadas'] += count($escritas);

            $this->avisosDeFilas[] = [
                'hoja' => $hoja,
                'descripcion' => 'Escribió '.count($escritas).' nombre(s) en el bloque de «alumnos que no '
                    .'aparecen en la lista» ('.implode(', ', array_column($escritas, 'nombre')).'). '
                    .'Buscarlos en el grupo y preguntarle si son los correctos es la fase 3 de «notas sin '
                    .'internet»; por ahora esas filas no se importan y no se crea a nadie.',
            ];
        }
    }

    /**
     * F8 / D5: las columnas `Aus` y `Tar`.
     *
     * **Se cuentan y se declaran como aviso, no como decisión.** Es el mismo
     * mecanismo que `RespuestasDeLaImportacion::NO_APLICADAS` y existe por lo mismo:
     * el libro **ya trae** esas dos columnas rellenas y escribibles (decisión (e)
     * del doc 49), así que un docente puede cambiarlas creyendo que entran.
     *
     * Y no se escriben porque no es gratis (§3.6 del plan): la columna sólo lleva el
     * total del periodo, así que **subir un conteo crea filas fechadas el día de la
     * importación** y bajarlo **borra** filas con sus fechas — que son las que leen
     * las planillas de ausencias de los acudientes. Eso es la fase 4.
     *
     * @param  array<string, mixed>  $mapa
     * @param  list<int>  $alumnos
     */
    private function avisarDeLasAusencias(string $hoja, array $mapa, int $asignaturaId, int $periodoId, array $alumnos): void
    {
        $aus = (string) ($mapa['columna_aus'] ?? '');
        $tar = (string) ($mapa['columna_tar'] ?? '');

        if ($aus === '' || $tar === '' || $alumnos === []) {
            return;
        }

        $marcas = implode(',', array_fill(0, count($alumnos), '?'));

        $hoy = DB::select(
            "SELECT a.alumno_id, a.tipo, COUNT(*) AS cuantas
               FROM ausencias a
              WHERE a.asignatura_id = ? AND a.periodo_id = ? AND a.deleted_at IS NULL
                AND a.alumno_id IN ({$marcas})
              GROUP BY a.alumno_id, a.tipo",
            array_merge([$asignaturaId, $periodoId], $alumnos)
        );

        $contados = [];

        foreach ($hoy as $fila) {
            $contados[(int) $fila->alumno_id][$fila->tipo] = (int) $fila->cuantas;
        }

        $suben = 0;
        $bajan = 0;

        foreach (($mapa['filas'] ?? []) as $fila => $alumno) {
            $alumnoId = (int) $alumno;

            foreach (['ausencia' => $aus, 'tardanza' => $tar] as $tipo => $letra) {
                $leido = $this->interpretar($this->lector->celda($hoja, $letra.(int) $fila));

                if ($leido['tipo'] !== 'entero') {
                    continue;
                }

                $ahora = $contados[$alumnoId][$tipo] ?? 0;

                if ($leido['valor'] > $ahora) {
                    $suben++;
                } elseif ($leido['valor'] < $ahora) {
                    $bajan++;
                }
            }
        }

        if ($suben === 0 && $bajan === 0) {
            return;
        }

        $this->avisosDeAusencias[] = [
            'hoja' => $hoja,
            'descripcion' => 'Cambió '.($suben + $bajan).' conteo(s) de ausencias o tardanzas ('.$suben
                .' hacia arriba, '.$bajan.' hacia abajo) y **no se importan**: eso es la fase 4. La columna '
                .'sólo lleva el total del periodo, así que subir un conteo crearía filas fechadas hoy —no el '
                .'día que faltó— y bajarlo borraría filas con sus fechas, que son las que leen las planillas '
                .'de acudientes.',
        ];
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

                // Las dos que la pantalla pinta como **aviso y no como decisión**, con
                // el motivo escrito desde aquí: son fases futuras del plan y el libro
                // ya trae dentro lo que las dispara.
                'filas' => $this->avisosDeFilas,
                'ausencias' => $this->avisosDeAusencias,
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
     */
    private function idDelChoque(string $hoja, int $alumnoId, int $subunidadId): string
    {
        return substr(sha1($hoja.'|'.$alumnoId.'|'.$subunidadId), 0, 16);
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
     * Quién sigue matriculado en el grupo. `alumno_id => nombre`.
     *
     * Con la misma lista de estados que armó la hoja
     * ({@see LaPlanillaQueSeDescarga::ESTADOS}), que es lo que hace que «se retiró»
     * signifique lo mismo en los dos lados.
     *
     * @return array<int, string>
     */
    private function matriculados(int $grupoId): array
    {
        $estados = LaPlanillaQueSeDescarga::ESTADOS;

        $filas = DB::select(
            "SELECT a.id, a.apellidos, a.nombres
               FROM alumnos a
               INNER JOIN matriculas m ON m.alumno_id = a.id AND m.grupo_id = ?
                                      AND m.estado IN ({$estados}) AND m.deleted_at IS NULL
              WHERE a.deleted_at IS NULL
              GROUP BY a.id, a.apellidos, a.nombres",
            [$grupoId]
        );

        $matriculados = [];

        foreach ($filas as $fila) {
            $matriculados[(int) $fila->id] = trim(($fila->apellidos ?? '').', '.($fila->nombres ?? ''), ' ,');
        }

        return $matriculados;
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
