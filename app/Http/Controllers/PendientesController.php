<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResuelveElUsuario;
use App\Models\Role;
use App\Services\CalendarioDePeriodos;
use App\Support\Autoriza;
use App\Support\CierreDeAsignatura;
use App\Support\AlcanceDeLaPlantilla;
use App\Support\FotoDeLaPlantilla;
use App\Support\Reloj;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * **Las cosas pendientes del colegio**: el aviso que sale al ingresar y que queda además
 * en Inicio › Pendientes (`myvc_front/app2`, `comunes/pendientes/`). Diseño y decisiones de
 * Joseth: `myvc_front/PLAN-COSAS-PENDIENTES.md`.
 *
 * `GET pendientes/mios` devuelve la lista **ya ordenada** —Importantes, Posponibles,
 * Silenciables, y dentro por urgencia— y **ya filtrada por quién pregunta**. Cada
 * pendiente viaja con todo lo que la pantalla pinta —`tipo`, `clave`, `insistencia`,
 * `titular`, `detalle` (con `**negritas**`), `filas` y `destino`—, así que el front no
 * decide textos ni vuelve a contar nada. Lo que el usuario ocultó va aparte, en `ocultos`.
 *
 * ## Quién ve qué
 *
 * | tipo | insistencia | lo ve | primero para |
 * |---|---|---|---|
 * | `entrega_boletines` | Importante | superusuario, Coord académico y Rector: todas; docente: las suyas y las de su grupo si es titular | — (manda la fecha) |
 * | `compromisos` | Importante | quien coordina compromisos (`Autoriza::puedeCambiarLaNotaNumerica`) | Coord académico |
 * | `intensidad_horaria` | Importante | directivos | Coord académico |
 * | `acudientes` | Posponible | directivos | Secretario |
 * | `celular` | Posponible | directivos | Secretario |
 * | `jefes_de_area` | Silenciable | superusuario y Coord académico (plan §4) | — |
 * | `firmas_por_aprobar` | Importante | quien aprueba firmas (`Autoriza::puedeAprobarFirmas`) | — |
 * | `periodos_faltan` | Importante sin ninguno; Silenciable con 1 a 3 | quien abre `/colegio/:y/periodos`: superusuario y `Admin` | — |
 * | `fechas_de_periodos` | Importante | ídem | — |
 * | `periodo_desfasado` | Importante, pero lo pospone el colegio entero | ídem | — |
 * | `plantilla_sin_propagar` | Importante | quien edita la plantilla (`Autoriza::puedeEditarPlantillaNotas`) | — |
 * | `tardanzas_sin_situacion` | Importante | superusuario y Coord disciplinario (el rol o `years.coordinador_disciplinario_id`) | — |
 * | `prematricula_vieja` | Posponible | superusuario, `Admin` y `Secretario` | Secretario |
 * | `alumnos_sin_datos` | Posponible | ídem | Secretario |
 * | `docentes_sin_datos` | Posponible | ídem | Secretario |
 * | `grupos_sin_titular` | Posponible | superusuario, `Admin` y Coord académico | Coord académico |
 * | `asignaturas_sin_docente` | Importante | ídem | Coord académico |
 * | `escala_incompleta` | Importante | superusuario y `Admin` | — |
 * | `plantilla_que_no_suma` | Importante; Silenciable si está vacía | quien edita la plantilla | Coord académico |
 * | `ficha_incompleta` | Silenciable | superusuario, `Admin` y `Secretario` | Secretario |
 *
 * «Directivos» es superusuario, `Admin`, `Rector`, `Secretario`, `Coord académico` y
 * `Coord disciplinario`. A quien no le toca nada se le contesta la lista vacía en 200.
 * «Primero para» es un empujón de orden **dentro de la misma insistencia**: a la
 * secretaria le sale arriba lo suyo, porque es lo que ella puede resolver.
 *
 * ## Insistencias (plan §3)
 *
 * **Importante** no se oculta. **Posponible** se oculta 7 días. **Silenciable** se oculta por el
 * año: su `clave` lleva el año, y el año siguiente es otra clave. Lo guarda
 * `pendientes_ocultos` (`PUT pendientes/ocultar`, `PUT pendientes/mostrar`).
 *
 * **La excepción es el que trae `posponer`**: un Importante que sí se pospone, con su propia
 * etiqueta y, si `para_todos`, para el colegio entero. Se guarda con `user_id = 0` —cada
 * colegio tiene su base, así que 0 es «el colegio»— y lo leen todos. Hoy sólo lo trae
 * `periodo_desfasado`: «Seguimos nivelando» es una decisión del colegio (plan §2.7).
 *
 * ## Todo del año que ELIGIÓ el usuario
 *
 * `$user->year_id` sale de `users.periodo_id` (`ContextoDeUsuario`), no de
 * `years.actual`: es el mismo año que ve en el selector y el mismo que usan
 * `areas/jefes` y el tablero de cierres.
 *
 * ## Coste
 *
 * Una consulta por tipo, más dos del cierre (`delPeriodo`, `faltantesPorAsignatura`) y
 * las de `contarPerdidas`. Nada por fila.
 */
class PendientesController extends Controller
{
    use ResuelveElUsuario;

    public const IMPORTANTE = 'importante';

    public const POSPONIBLE = 'posponible';

    public const SILENCIABLE = 'silenciable';

    /** Días que se oculta un Posponible (plan §3). */
    public const DIAS_DE_POSPONER = 7;

    /**
     * **El aviso de entrega sale 7 días antes, y sigue saliendo si ya venció** (plan §4,
     * decisión de Joseth del 24 sep 2026). Hoy cuenta como día 0.
     */
    public const DIAS_DE_AVISO_DE_ENTREGA = 7;

    /** Cuántas filas viajan por pendiente como mucho. El total va aparte. */
    public const TOPE_DE_FILAS = 50;

    private const DIRECTIVOS = ['Admin', 'Rector', 'Secretario', 'Coord académico', 'Coord disciplinario'];

    /** Los estados de una matrícula de verdad: ni retirados ni prematriculados. */
    private const MATRICULADO = 'm.estado IN ("MATR","ASIS")';

    public function getMios(): array
    {
        $user = $this->user;
        $roles = array_map(static fn ($r) => (string) $r->name, Role::getUserRoles($user->user_id ?? 0));
        $vacio = ['year_id' => (int) $user->year_id, 'pendientes' => [], 'ocultos' => []];

        $year = DB::selectOne('SELECT * FROM years WHERE id = ? AND deleted_at IS NULL', [(int) $user->year_id]);

        if ($year === null) {
            return $vacio;
        }

        $super = Autoriza::esSuperusuario($user);
        $directivo = $this->esDirectivo($user, $roles);
        $coordAcademico = $super || in_array('Coord académico', $roles, true);
        $profesorId = ($user->tipo ?? null) === 'Profesor' && ($user->persona_id ?? null) !== null ? (int) $user->persona_id : null;
        $todasLasAsignaturas = $super || array_intersect(['Coord académico', 'Rector'], $roles) !== [];
        $coordDisciplinario = $super || in_array('Coord disciplinario', $roles, true)
            || ($profesorId !== null && (int) ($year->coordinador_disciplinario_id ?? 0) === $profesorId);
        $secretaria = $super || array_intersect(['Admin', 'Secretario'], $roles) !== [];

        // Los de periodos, a quien puede arreglarlos: `/colegio/:y/periodos` es `esAdmin`.
        $periodos = ($super || in_array('Admin', $roles, true))
            ? DB::select('SELECT * FROM periodos WHERE year_id = ? AND deleted_at IS NULL ORDER BY numero', [(int) $year->id])
            : null;

        $pendientes = array_filter([
            ($todasLasAsignaturas || $profesorId !== null)
                ? $this->entregaDeBoletines($year, ['todas' => $todasLasAsignaturas, 'profesor_id' => $profesorId])
                : null,
            Autoriza::puedeCambiarLaNotaNumerica($user) ? $this->compromisos($year, (int) ($user->numero_periodo ?? 0)) : null,
            $coordAcademico ? $this->jefesDeArea($year) : null,
            $directivo ? $this->intensidadHoraria($year) : null,
            $directivo ? $this->sinAcudiente($year) : null,
            $directivo ? $this->sinCelular($year) : null,
            Autoriza::puedeAprobarFirmas($user) ? $this->firmasPorAprobar((int) $user->user_id) : null,
            $periodos !== null ? $this->periodosQueFaltan($year, $periodos) : null,
            $periodos !== null ? $this->fechasDePeriodos($year, $periodos) : null,
            $periodos !== null ? $this->periodoDesfasado($year, $periodos) : null,
            Autoriza::puedeEditarPlantillaNotas($user) ? $this->plantillaSinPropagar($year) : null,
            $coordDisciplinario ? $this->tardanzasSinSituacion($year) : null,
            $secretaria ? $this->prematriculaVieja($year) : null,
            $secretaria ? $this->alumnosSinDatos($year) : null,
            $secretaria ? $this->docentesSinDatos($year) : null,
            ($super || array_intersect(['Admin', 'Coord académico'], $roles) !== []) ? $this->gruposSinTitular($year) : null,
            ($super || array_intersect(['Admin', 'Coord académico'], $roles) !== []) ? $this->asignaturasSinDocente($year) : null,
            ($super || in_array('Admin', $roles, true)) ? $this->escalaIncompleta($year) : null,
            Autoriza::puedeEditarPlantillaNotas($user) ? $this->plantillaQueNoSuma($year) : null,
            $secretaria ? $this->fichaIncompleta($year) : null,
        ]);

        foreach ($pendientes as &$p) {
            if (array_intersect($p['primero_para'], $roles) !== []) {
                $p['urgencia'] += 1000;
            }
            unset($p['primero_para']);
        }
        unset($p);

        $peso = [self::IMPORTANTE => 0, self::POSPONIBLE => 1, self::SILENCIABLE => 2];
        usort($pendientes, static fn ($a, $b) => [$peso[$a['insistencia']], $b['urgencia']] <=> [$peso[$b['insistencia']], $a['urgencia']]);

        // Lo que esta persona ocultó y sigue oculto. Un Importante nunca: si alguien lo metió
        // a mano en la tabla, se ignora.
        $ocultas = [];
        foreach (DB::select('SELECT clave, hasta FROM pendientes_ocultos WHERE user_id IN (?, 0) AND (hasta IS NULL OR hasta > ?)',
            [(int) $user->user_id, Reloj::ahoraTexto()]) as $o) {
            $ocultas[$o->clave] = $o->hasta;
        }

        $visibles = [];
        $ocultos = [];
        foreach ($pendientes as $p) {
            if (($p['insistencia'] !== self::IMPORTANTE || isset($p['posponer'])) && array_key_exists($p['clave'], $ocultas)) {
                $p['oculto_hasta'] = $ocultas[$p['clave']];
                $ocultos[] = $p;
            } else {
                $visibles[] = $p;
            }
        }

        return ['year_id' => (int) $year->id, 'pendientes' => $visibles, 'ocultos' => $ocultos];
    }

    /**
     * `PUT pendientes/ocultar` — `{clave, modo: 'posponer'|'silenciar'}`.
     *
     * Se valida contra lo que **hoy** le sale a esta persona: sólo se oculta un pendiente
     * que existe, con la insistencia que lo permite. Posponer un Silenciable vale (es
     * ocultarlo menos tiempo); silenciar un Posponible no.
     */
    public function putOcultar(): array
    {
        $clave = (string) Request::input('clave', '');
        $modo = (string) Request::input('modo', '');

        abort_unless(in_array($modo, ['posponer', 'silenciar'], true), 422, 'El modo es `posponer` o `silenciar`.');

        $mio = null;
        foreach ($this->getMios() as $lista => $items) {
            if (is_array($items)) {
                foreach ($items as $p) {
                    if (($p['clave'] ?? null) === $clave) {
                        $mio = $p;
                    }
                }
            }
        }

        abort_if($mio === null, 422, 'Ese pendiente no te sale ahora mismo.');
        abort_if($mio['insistencia'] === self::IMPORTANTE && ! ($modo === 'posponer' && isset($mio['posponer'])), 422, 'Este pendiente no se puede ocultar: sale hasta que se resuelva.');
        abort_if($modo === 'silenciar' && $mio['insistencia'] !== self::SILENCIABLE, 422, 'Este pendiente sólo se puede posponer.');

        $hasta = $modo === 'posponer' ? Reloj::ahora()->addDays(self::DIAS_DE_POSPONER)->toDateTimeString() : null;
        $ahora = Reloj::ahoraTexto();

        DB::table('pendientes_ocultos')->updateOrInsert(
            ['user_id' => ($mio['posponer']['para_todos'] ?? false) ? 0 : (int) $this->user->user_id, 'clave' => $clave],
            ['hasta' => $hasta, 'updated_at' => $ahora, 'created_at' => $ahora]
        );

        return ['clave' => $clave, 'hasta' => $hasta];
    }

    /** `PUT pendientes/mostrar` — `{clave}`: deshace el ocultar, también el del colegio entero. */
    public function putMostrar(): array
    {
        $clave = (string) Request::input('clave', '');

        DB::table('pendientes_ocultos')->whereIn('user_id', [(int) $this->user->user_id, 0])->where('clave', $clave)->delete();

        return ['clave' => $clave];
    }

    /** @param list<string> $roles */
    private function esDirectivo($user, array $roles): bool
    {
        return Autoriza::esSuperusuario($user) || array_intersect(self::DIRECTIVOS, $roles) !== [];
    }

    /* ── 1. La entrega de boletines se acerca y hay asignaturas sin cerrar ─────────── */

    /**
     * @param  array{todas: bool, profesor_id: ?int}  $alcance  Quién mira: coordinación ve todas;
     *                                                          un docente, las suyas y las de su grupo.
     */
    private function entregaDeBoletines(object $year, array $alcance): ?array
    {
        // `SELECT *` y no la columna por nombre: `fecha_entrega_boletines` la añade una
        // migración aditiva que puede no haber corrido en un colegio. Sin la columna, o
        // sin fecha puesta, este aviso no existe.
        $hoy = Reloj::ahora()->startOfDay();
        $periodo = null;
        $dias = null;

        // El periodo que manda es el de la ÚLTIMA entrega que ya llegó o llega en ≤7 días:
        // si la del 3 venció y la del 4 aún está lejos, se habla del 3 (§4 del plan: «o ya
        // vencida»). Una entrega vieja con una más nueva detrás ya no se persigue.
        foreach (DB::select('SELECT * FROM periodos WHERE year_id = ? AND deleted_at IS NULL ORDER BY numero', [(int) $year->id]) as $p) {
            $fecha = $p->fecha_entrega_boletines ?? null;

            if ($fecha === null) {
                continue;
            }

            // En la zona del reloj: `Carbon::parse` a secas la leería en UTC y a las siete
            // de la noche de Bogotá «faltan 7 días» saldría como 6.
            $faltan = (int) round($hoy->diffInDays(Carbon::parse($fecha, $hoy->getTimezone())->startOfDay(), false));

            if ($faltan <= self::DIAS_DE_AVISO_DE_ENTREGA && ($dias === null || $faltan > $dias)) {
                $periodo = $p;
                $dias = $faltan;
            }
        }

        if ($periodo === null) {
            return null;
        }

        $filtro = '';
        $datos = [(int) $year->id];

        if (! $alcance['todas']) {
            if ($alcance['profesor_id'] === null) {
                return null;
            }

            $filtro = ' AND (a.profesor_id = ? OR g.titular_id = ?)';
            $datos[] = $alcance['profesor_id'];
            $datos[] = $alcance['profesor_id'];
        }

        $cierres = CierreDeAsignatura::delPeriodo((int) $periodo->id);
        $faltan = CierreDeAsignatura::faltantesPorAsignatura((int) $periodo->id);

        $abiertas = array_values(array_filter(DB::select(
            'SELECT a.id AS asignatura_id, g.nombre AS nombre_grupo, g.abrev AS abrev_grupo, g.titular_id,
                    m.materia, a.profesor_id, p.nombres, p.apellidos,
                    IFNULL(fp.nombre, iu.nombre) AS foto
               FROM asignaturas a
               INNER JOIN grupos g ON g.id = a.grupo_id AND g.deleted_at IS NULL
               LEFT JOIN materias m ON m.id = a.materia_id AND m.deleted_at IS NULL
               LEFT JOIN profesores p ON p.id = a.profesor_id AND p.deleted_at IS NULL
               LEFT JOIN images fp ON fp.id = p.foto_id AND fp.deleted_at IS NULL
               LEFT JOIN users up ON up.id = p.user_id
               LEFT JOIN images iu ON iu.id = up.imagen_id AND iu.deleted_at IS NULL
              WHERE g.year_id = ? AND a.deleted_at IS NULL'.$filtro.'
              ORDER BY g.orden, g.nombre, a.orden, m.materia, a.id',
            $datos
        ), static fn ($a) => ! ($cierres[(int) $a->asignatura_id]['cerrada'] ?? false)));

        $cuantas = count($abiertas);

        if ($cuantas === 0) {
            return null;
        }

        foreach ($abiertas as $a) {
            $a->faltan = $faltan[(int) $a->asignatura_id] ?? 0;
            $a->mia = $alcance['profesor_id'] !== null && (int) $a->profesor_id === $alcance['profesor_id'];
        }

        // Las suyas primero; después, las que más notas deben.
        usort($abiertas, static fn ($x, $y) => [$y->mia, $y->faltan] <=> [$x->mia, $x->faltan]);

        $numero = $periodo->numero;
        $cuando = match (true) {
            $dias < 0 => 'La entrega de boletines del Periodo '.$numero.' venció hace '.$this->plural(-$dias, 'día', 'días'),
            $dias === 0 => 'Hoy se entregan los boletines del Periodo '.$numero,
            $dias === 1 => 'Mañana se entregan los boletines del Periodo '.$numero,
            default => "Faltan {$dias} días para entregar los boletines del Periodo {$numero}",
        };

        $fecha = Carbon::parse($periodo->fecha_entrega_boletines)->locale('es')->isoFormat('dddd D [de] MMMM');
        $fechaFrase = $dias < 0 ? "La entrega fue el **{$fecha}**." : "La entrega es el **{$fecha}**.";

        if ($alcance['todas']) {
            $docentes = count(array_unique(array_filter(array_map(static fn ($a) => $a->profesor_id, $abiertas))));
            $sinDocente = count(array_filter($abiertas, static fn ($a) => $a->profesor_id === null));

            $titular = $cuando.' y hay '.$this->plural($cuantas, 'asignatura sin cerrar', 'asignaturas sin cerrar');
            $deQuien = $cuantas === 1
                ? ($docentes === 1 ? 'Es de '.$this->nombreDocente($abiertas[0]).'.' : 'No tiene docente asignado.')
                : 'Las '.$cuantas.' asignaturas son de '.$this->plural($docentes, 'docente', 'docentes')
                    .($sinDocente > 0 ? ', y '.$this->plural($sinDocente, 'no tiene', 'no tienen').' docente' : '').'.';
            $detalle = "{$fechaFrase} {$deQuien}";
            $destino = [
                'ruta' => '/colegio/'.$year->id.'/periodos',
                'query' => ['registro' => (int) $periodo->id],
                'etiqueta' => 'Ver estado de registro',
            ];
        } else {
            $mias = count(array_filter($abiertas, static fn ($a) => $a->mia));
            $delGrupo = $cuantas - $mias;
            $grupos = array_values(array_unique(array_map(
                static fn ($a) => $a->abrev_grupo ?: $a->nombre_grupo,
                array_filter($abiertas, static fn ($a) => ! $a->mia)
            )));

            $partes = [];
            if ($mias > 0) {
                $partes[] = 'tienes '.$this->plural($mias, 'asignatura sin cerrar', 'asignaturas sin cerrar');
            }
            if ($delGrupo > 0) {
                $partes[] = 'hay '.$this->plural($delGrupo, 'asignatura', 'asignaturas')
                    .($mias > 0 ? ' más' : '').' sin cerrar en tu grupo '.implode(', ', $grupos);
            }

            $titular = $cuando.' y '.implode(', y ', $partes);
            $detalle = $fechaFrase.($delGrupo > 0 ? ' Las de tu grupo las cierra cada docente; como titular, te toca saber cuáles faltan.' : '');
            $destino = $mias > 0
                ? ['ruta' => '/mis-asignaturas', 'etiqueta' => 'Ir a mis asignaturas']
                : null;
        }

        return [
            'tipo' => 'entrega_boletines',
            'clave' => 'entrega_boletines:p='.$periodo->id,
            'insistencia' => self::IMPORTANTE,
            // Iconos de Ant que `app2` ya registra (`app.config.ts`, `ICONOS`): uno que no
            // esté ahí se pide por la red y sale en blanco.
            'urgencia' => 200 + (self::DIAS_DE_AVISO_DE_ENTREGA - $dias),
            'icono' => 'clock-circle',
            'titular' => $titular,
            'detalle' => $detalle,
            'filas' => array_map(fn ($a) => [
                'texto' => implode(' · ', array_filter([
                    $a->materia ?? 'Asignatura',
                    $a->abrev_grupo ?: $a->nombre_grupo,
                    $a->mia ? 'tuya' : ($a->profesor_id !== null ? 'Prof. '.$this->nombreDocente($a) : 'sin docente'),
                ])),
                'nota' => $a->faltan > 0 ? $this->plural($a->faltan, 'nota sin poner', 'notas sin poner') : 'completa, sin cerrar',
                'aviso' => $a->faltan > 0,
                'foto' => $a->profesor_id !== null ? ($a->foto ?? null) : null,
                'nombres' => $a->nombres ?? null,
                'apellidos' => $a->apellidos ?? null,
            ], array_slice($abiertas, 0, self::TOPE_DE_FILAS)),
            'total_filas' => $cuantas,
            'destino' => $destino,
            'datos' => ['periodo_id' => (int) $periodo->id, 'dias' => $dias],
            'primero_para' => ['Coord académico'],
        ];
    }

    /* ── 3. Estudiantes que pasan el corte de perdidas sin compromiso ──────────────── */

    private function compromisos(object $year, int $numeroPeriodo): ?array
    {
        if ($numeroPeriodo < 1) {
            return null;
        }

        // **El módulo no tiene interruptor.** Se toma por encendido el colegio que lo ha
        // tocado este año: guardó su plantilla (`config_compromiso`) o creó alguno. Un
        // colegio que nunca lo abrió no recibe un aviso de un módulo que no usa.
        $usa = DB::selectOne(
            'SELECT (EXISTS (SELECT 1 FROM config_compromiso WHERE year_id = ?)
                  OR EXISTS (SELECT 1 FROM compromisos WHERE year_id = ?)) AS usa',
            [(int) $year->id, (int) $year->id]
        );

        if (! (int) ($usa->usa ?? 0)) {
            return null;
        }

        try {
            $cuenta = app(CompromisosController::class)->candidatosSinCompromiso((int) $year->id, $numeroPeriodo);
        } catch (HttpExceptionInterface) {
            // El parágrafo de primaria encendido sin sus dos materias es un 422 en la
            // pantalla de compromisos, y allí se ve. Aquí no tumba los demás avisos.
            return null;
        }

        $candidatos = $cuenta['candidatos'];
        $cuantos = count($candidatos);

        if ($cuantos === 0) {
            return null;
        }

        usort($candidatos, static fn ($a, $b) => $b['cantidad_perdidas'] <=> $a['cantidad_perdidas']);
        $mostrados = array_slice($candidatos, 0, self::TOPE_DE_FILAS);
        $fotos = $this->fotosDeAlumnos(array_column($mostrados, 'alumno_id'));

        $unidad = $cuenta['regla'] === 'area' ? ['área', 'áreas'] : ['asignatura', 'asignaturas'];
        $pierden = $cuantos === 1 ? 'pierde' : 'pierden';
        $tienen = $cuantos === 1 ? 'tiene' : 'tienen';

        return [
            'tipo' => 'compromisos',
            'clave' => 'compromisos:y='.$year->id.':p='.$numeroPeriodo,
            'insistencia' => self::IMPORTANTE,
            'urgencia' => 150,
            'icono' => 'solution',
            'titular' => $this->plural($cuantos, 'estudiante', 'estudiantes')
                ." {$pierden} {$cuenta['corte']} o más {$unidad[1]} y no {$tienen} compromiso académico",
            'detalle' => "Cuenta del **Periodo {$numeroPeriodo}**, con el corte de la plantilla del compromiso: "
                ."desde {$this->plural($cuenta['corte'], $unidad[0].' perdida', $unidad[1].' perdidas')}.",
            'filas' => array_map(fn ($c) => $this->filaDeAlumno(
                $fotos[(int) $c['alumno_id']] ?? null,
                (string) $c['grupo'],
                $this->plural((int) $c['cantidad_perdidas'], $unidad[0].' perdida', $unidad[1].' perdidas'),
                true
            ), $mostrados),
            'total_filas' => $cuantos,
            'destino' => ['ruta' => '/compromisos', 'etiqueta' => 'Proponer compromisos'],
            'primero_para' => ['Coord académico'],
        ];
    }

    /* ── 2. Áreas sin jefe en el año ────────────────────────────────────────────────── */

    /**
     * Sólo las áreas que **se dictan este año** —alguna materia suya tiene asignatura en
     * un grupo del año—: `areas` no tiene año, y un área vieja que ya nadie da no
     * necesita jefe. `areas/jefes` las lista todas porque allí se nombra a cualquiera.
     */
    private function jefesDeArea(object $year): ?array
    {
        $areas = array_map(static fn ($a) => (string) $a->nombre, DB::select(
            'SELECT a.nombre FROM areas a
               LEFT JOIN jefes_de_area j ON j.area_id = a.id AND j.year_id = ?
              WHERE a.deleted_at IS NULL AND j.id IS NULL
                AND EXISTS (SELECT 1 FROM materias m
                     INNER JOIN asignaturas s ON s.materia_id = m.id AND s.deleted_at IS NULL
                     INNER JOIN grupos g ON g.id = s.grupo_id AND g.year_id = ? AND g.deleted_at IS NULL
                     WHERE m.area_id = a.id AND m.deleted_at IS NULL)
              ORDER BY a.orden, a.id',
            [(int) $year->id, (int) $year->id]
        ));

        $cuantas = count($areas);

        if ($cuantas === 0) {
            return null;
        }

        return [
            'tipo' => 'jefes_de_area',
            'clave' => 'jefes_de_area:y='.$year->id,
            'insistencia' => self::SILENCIABLE,
            'urgencia' => 100,
            'icono' => 'idcard',
            'titular' => $cuantas === 1
                ? "Falta poner el jefe del área de {$this->capitalizar($areas[0])}"
                : "Falta poner el jefe de {$cuantas} áreas",
            'detalle' => $cuantas === 1
                ? "Se dicta en **{$year->year}** y nadie la dirige todavía."
                : "Se dictan en **{$year->year}** y nadie las dirige todavía.",
            'filas' => array_map(fn ($a) => ['texto' => $this->capitalizar($a), 'nota' => 'sin jefe', 'aviso' => false], array_slice($areas, 0, self::TOPE_DE_FILAS)),
            'total_filas' => $cuantas,
            'destino' => ['ruta' => '/areas/directores', 'etiqueta' => 'Poner jefes de área'],
            'primero_para' => [],
        ];
    }

    /* ── 7. Intensidad horaria: asignaturas sin IH y grupos que no cuadran ──────────── */

    /**
     * Los dos casos del encargo, con el criterio de `app2/…/asignaturas.ts` (cabecera
     * del cuadre): la IH de la asignatura es `asignaturas.creditos`; la del grupo,
     * `grupos.ih`. Un grupo **sólo se compara** si tiene `ih` puesta y ninguna de sus
     * asignaturas está sin IH: con una vacía, la suma se queda corta por construcción y
     * acusaría a las demás filas.
     */
    private function intensidadHoraria(object $year): ?array
    {
        $filas = DB::select(
            'SELECT a.id, a.creditos, g.id AS grupo_id, g.nombre AS nombre_grupo, g.abrev AS abrev_grupo, g.ih,
                    m.materia
               FROM asignaturas a
               INNER JOIN grupos g ON g.id = a.grupo_id AND g.deleted_at IS NULL
               LEFT JOIN materias m ON m.id = a.materia_id AND m.deleted_at IS NULL
              WHERE g.year_id = ? AND a.deleted_at IS NULL
              ORDER BY g.orden, g.nombre, a.orden, m.materia, a.id',
            [(int) $year->id]
        );

        $sinIh = [];
        $grupos = [];

        foreach ($filas as $f) {
            $g = $grupos[$f->grupo_id] ??= ['nombre' => $f->abrev_grupo ?: $f->nombre_grupo, 'ih' => $f->ih, 'suma' => 0, 'vacias' => 0];

            if ($f->creditos === null || (int) $f->creditos <= 0) {
                $sinIh[] = $f;
                $g['vacias']++;
            } else {
                $g['suma'] += (int) $f->creditos;
            }

            $grupos[$f->grupo_id] = $g;
        }

        $descuadrados = array_values(array_filter($grupos,
            static fn ($g) => $g['ih'] !== null && $g['vacias'] === 0 && $g['suma'] !== (int) $g['ih']));

        if ($sinIh === [] && $descuadrados === []) {
            return null;
        }

        $partes = [];

        if ($sinIh !== []) {
            $partes[] = $this->plural(count($sinIh), 'asignatura sin intensidad horaria', 'asignaturas sin intensidad horaria');
        }

        if ($descuadrados !== []) {
            $partes[] = $this->plural(count($descuadrados), 'grupo cuyas horas no cuadran', 'grupos cuyas horas no cuadran');
        }

        $lista = array_merge(
            array_map(static fn ($g) => [
                'texto' => 'Grupo '.$g['nombre'],
                'nota' => "{$g['suma']} de {$g['ih']} horas",
                'aviso' => true,
            ], $descuadrados),
            array_map(static fn ($f) => [
                'texto' => ($f->materia ?? 'Asignatura').' · '.($f->abrev_grupo ?: $f->nombre_grupo),
                'nota' => 'sin IH',
                'aviso' => true,
            ], $sinIh),
        );

        return [
            'tipo' => 'intensidad_horaria',
            'clave' => 'intensidad_horaria:y='.$year->id,
            'insistencia' => self::IMPORTANTE,
            'urgencia' => 90,
            'icono' => 'hourglass',
            'titular' => 'Hay '.implode(' y ', $partes),
            'detalle' => 'La intensidad horaria es obligatoria para lo académico, aunque el colegio no use horario.'
                .($descuadrados !== [] ? ' Un grupo cuadra cuando la suma de sus asignaturas da la **IH del grupo**.' : ''),
            'filas' => array_slice($lista, 0, self::TOPE_DE_FILAS),
            'total_filas' => count($lista),
            'destino' => ['ruta' => '/asignaturas', 'etiqueta' => 'Ir a asignaturas'],
            'primero_para' => ['Coord académico'],
        ];
    }

    /* ── 5. Matriculados sin ningún acudiente ────────────────────────────────────────── */

    private function sinAcudiente(object $year): ?array
    {
        $alumnos = DB::select(
            'SELECT al.id AS alumno_id, al.nombres, al.apellidos, i.nombre AS foto,
                    g.nombre AS nombre_grupo, g.abrev AS abrev_grupo
               FROM matriculas m
               INNER JOIN grupos g ON g.id = m.grupo_id AND g.year_id = ? AND g.deleted_at IS NULL
               INNER JOIN alumnos al ON al.id = m.alumno_id AND al.deleted_at IS NULL
               LEFT JOIN images i ON i.id = al.foto_id AND i.deleted_at IS NULL
              WHERE m.deleted_at IS NULL AND '.self::MATRICULADO.'
                AND NOT EXISTS (
                    SELECT 1 FROM parentescos p
                     INNER JOIN acudientes ac ON ac.id = p.acudiente_id AND ac.deleted_at IS NULL
                     WHERE p.alumno_id = al.id AND p.deleted_at IS NULL)
              ORDER BY g.orden, g.nombre, al.apellidos, al.nombres',
            [(int) $year->id]
        );

        return $this->pendienteDeAlumnos(
            (int) $year->id, 'acudientes', 70, 'team', $alumnos,
            ['estudiante no tiene acudiente', 'estudiantes no tienen acudiente'],
            "Matriculados en **{$year->year}** sin ningún acudiente asignado.",
            ['ruta' => '/acudientes', 'etiqueta' => 'Ir a acudientes'],
            ['ruta' => '/alumnos', 'etiqueta' => 'Ir a alumnos'],
        );
    }

    /* ── 6. Matriculados sin celular propio ──────────────────────────────────────────── */

    /**
     * **Sólo el celular del alumno**, que es lo que se pidió. Un valor basura cuenta como
     * vacío: espacios, «0», cualquier cosa de menos de 7 dígitos o todo ceros. Se filtra
     * en PHP y no con `REGEXP_REPLACE` porque producción puede ser MariaDB, y porque la
     * lista ya viene acotada a los matriculados.
     *
     * `sin_celular_de_acudiente` viaja en `datos` y el front no lo pinta todavía: de los
     * que no tienen celular, cuántos tampoco tienen el de ningún acudiente.
     */
    private function sinCelular(object $year): ?array
    {
        $filas = DB::select(
            'SELECT al.id AS alumno_id, al.nombres, al.apellidos, al.celular, i.nombre AS foto,
                    g.nombre AS nombre_grupo, g.abrev AS abrev_grupo,
                    (SELECT GROUP_CONCAT(ac.celular SEPARATOR "|") FROM parentescos p
                      INNER JOIN acudientes ac ON ac.id = p.acudiente_id AND ac.deleted_at IS NULL
                      WHERE p.alumno_id = al.id AND p.deleted_at IS NULL) AS celulares_acudientes
               FROM matriculas m
               INNER JOIN grupos g ON g.id = m.grupo_id AND g.year_id = ? AND g.deleted_at IS NULL
               INNER JOIN alumnos al ON al.id = m.alumno_id AND al.deleted_at IS NULL
               LEFT JOIN images i ON i.id = al.foto_id AND i.deleted_at IS NULL
              WHERE m.deleted_at IS NULL AND '.self::MATRICULADO.'
              ORDER BY g.orden, g.nombre, al.apellidos, al.nombres',
            [(int) $year->id]
        );

        $sin = array_values(array_filter($filas, fn ($f) => ! self::esCelular($f->celular)));

        $tampocoAcudiente = count(array_filter($sin, static function ($f) {
            foreach (explode('|', (string) $f->celulares_acudientes) as $c) {
                if (self::esCelular($c)) {
                    return false;
                }
            }

            return true;
        }));

        $p = $this->pendienteDeAlumnos(
            (int) $year->id, 'celular', 60, 'phone', $sin,
            ['estudiante no tiene celular', 'estudiantes no tienen celular'],
            "Matriculados en **{$year->year}** sin celular propio, o con uno que no es un número (vacío, «0», menos de 7 dígitos).",
            ['ruta' => '/alumnos', 'etiqueta' => 'Ir a alumnos'],
            null,
        );

        if ($p !== null) {
            $p['datos'] = ['sin_celular_de_acudiente' => $tampocoAcudiente];
        }

        return $p;
    }

    /** ¿Es un celular de verdad? 7 dígitos o más, y no todos ceros. */
    public static function esCelular(?string $valor): bool
    {
        $digitos = preg_replace('/\D/', '', (string) $valor);

        return strlen($digitos) >= 7 && trim($digitos, '0') !== '';
    }

    /* ── 8. Firmas del titular esperando aprobación ─────────────────────────────────── */

    /**
     * Las mismas solicitudes que `firmas-del-titular/pendientes`, menos la propia: quien
     * pidió su firma no puede aprobarla (`FirmasDelTitularController::getCuantas`).
     * Importante porque la firma no sale en los boletines hasta que alguien la apruebe, y
     * resolverla es un clic.
     */
    private function firmasPorAprobar(int $userId): ?array
    {
        $filas = DB::select('SELECT p.nombres, p.apellidos, i.nombre AS foto, c.created_at
            FROM change_asked c
            INNER JOIN change_asked_data d ON d.id = c.data_id AND d.firma_id_new IS NOT NULL
            INNER JOIN profesores p ON p.user_id = c.asked_by_user_id AND p.deleted_at IS NULL
            LEFT JOIN images i ON i.id = p.foto_id AND i.deleted_at IS NULL
            WHERE c.deleted_at IS NULL AND c.answered_by IS NULL AND c.asked_by_user_id <> ?
            ORDER BY c.created_at, c.id', [$userId]);

        $cuantas = count($filas);

        if ($cuantas === 0) {
            return null;
        }

        return [
            'tipo' => 'firmas_por_aprobar',
            'clave' => 'firmas_por_aprobar',
            'insistencia' => self::IMPORTANTE,
            'urgencia' => 200,
            'icono' => 'edit',
            'titular' => $this->plural($cuantas, 'firma de titular espera tu aprobación', 'firmas de titular esperan tu aprobación'),
            'detalle' => 'No sale en los boletines hasta que alguien la apruebe.',
            'filas' => array_map(fn ($f) => [
                'texto' => trim($f->nombres.' '.$f->apellidos),
                'nota' => 'desde el '.Carbon::parse($f->created_at)->locale('es')->translatedFormat('j M'),
                'aviso' => false,
                'foto' => $f->foto,
                'nombres' => $f->nombres,
                'apellidos' => $f->apellidos,
            ], array_slice($filas, 0, self::TOPE_DE_FILAS)),
            'total_filas' => $cuantas,
            'destino' => ['ruta' => '/firmas-por-aprobar', 'etiqueta' => 'Revisar firmas'],
            'primero_para' => [],
        ];
    }

    /* ── 9. Periodos: que existan, que tengan fechas y que el actual sea el de hoy ─── */

    /**
     * **Sin ninguno** es Importante: no hay dónde poner notas. **Con 1 a 3** es Silenciable:
     * `CalendarioDePeriodos` crea 4, pero un colegio por trimestres tiene 3 a propósito y no
     * puede quedarse con un aviso que no se va.
     *
     * @param  list<object>  $periodos
     */
    private function periodosQueFaltan(object $year, array $periodos): ?array
    {
        $n = count($periodos);

        if ($n >= CalendarioDePeriodos::CANTIDAD) {
            return null;
        }

        return [
            'tipo' => 'periodos_faltan',
            'clave' => 'periodos_faltan:y='.$year->id,
            'insistencia' => $n === 0 ? self::IMPORTANTE : self::SILENCIABLE,
            'urgencia' => $n === 0 ? 900 : 50,
            'icono' => 'calendar',
            'titular' => $n === 0
                ? "El año {$year->year} no tiene periodos"
                : "El año {$year->year} tiene ".$this->plural($n, 'periodo', 'periodos').' de '.CalendarioDePeriodos::CANTIDAD,
            'detalle' => $n === 0
                ? 'Sin periodos no se pueden poner notas ni sacar boletines.'
                : 'Si el colegio trabaja con '.$n.' a propósito, silencia este aviso por el año.',
            'filas' => [],
            'total_filas' => 0,
            'destino' => ['ruta' => '/colegio/'.$year->id.'/periodos', 'etiqueta' => 'Crear periodos'],
            'primero_para' => [],
        ];
    }

    /**
     * Inicio y fin en todos; entrega de boletines sólo en los que no han terminado —pedirla
     * de un periodo cerrado en junio no sirve de nada—. Los tres juntos en un pendiente: se
     * arreglan en la misma pantalla y tres avisos seguidos serían ruido.
     *
     * @param  list<object>  $periodos
     */
    private function fechasDePeriodos(object $year, array $periodos): ?array
    {
        $hoy = Reloj::ahora()->startOfDay();
        $filas = [];

        foreach ($periodos as $p) {
            $falta = [];

            if ($p->fecha_inicio === null) {
                $falta[] = 'el inicio';
            }
            if ($p->fecha_fin === null) {
                $falta[] = 'el fin';
            }
            // `property_exists`: la columna la añade una migración que puede no haber corrido.
            $sigue = $p->fecha_fin === null || Carbon::parse($p->fecha_fin, $hoy->getTimezone())->startOfDay()->gte($hoy);
            if (property_exists($p, 'fecha_entrega_boletines') && $p->fecha_entrega_boletines === null && $sigue) {
                $falta[] = 'la entrega de boletines';
            }

            if ($falta !== []) {
                $nota = count($falta) === 3 ? 'sin ninguna fecha' : (count($falta) === 1 ? 'falta ' : 'faltan ').$this->enLista($falta);
                $filas[] = ['texto' => 'Periodo '.$p->numero, 'nota' => $nota, 'aviso' => false];
            }
        }

        $n = count($filas);

        if ($n === 0) {
            return null;
        }

        return [
            'tipo' => 'fechas_de_periodos',
            'clave' => 'fechas_de_periodos:y='.$year->id,
            'insistencia' => self::IMPORTANTE,
            'urgencia' => 120,
            'icono' => 'calendar',
            'titular' => $n === 1 ? 'Faltan fechas en el '.$filas[0]['texto'] : "Faltan fechas en {$n} periodos",
            'detalle' => 'Con las fechas puestas, MyVC avisa cuándo cambiar de periodo y cuándo se acerca la **entrega de boletines**.',
            'filas' => $filas,
            'total_filas' => $n,
            'destino' => ['ruta' => '/colegio/'.$year->id.'/periodos', 'etiqueta' => 'Poner las fechas'],
            'primero_para' => [],
        ];
    }

    /** Días que se deja al colegio en el periodo anterior antes de avisar (plan, encargo). */
    public const DIAS_DE_GRACIA_DEL_PERIODO = 7;

    /**
     * Según `fecha_inicio`, hoy es de un periodo posterior al actual, y hace **7 días o
     * más**: antes es normal, porque muchos colegios se quedan en el anterior mientras
     * nivelan. Sólo en el año en curso, que es el único que tiene un «hoy».
     *
     * «Seguimos nivelando» lo pospone 7 días **para todo el colegio** (plan §2.7).
     *
     * @param  list<object>  $periodos
     */
    private function periodoDesfasado(object $year, array $periodos): ?array
    {
        if ((int) $year->actual !== 1) {
            return null;
        }

        $hoy = Reloj::ahora()->startOfDay();
        $actual = null;
        $deHoy = null;
        $desde = null;

        foreach ($periodos as $p) {
            if ((int) $p->actual === 1) {
                $actual = $p;
            }
            if ($p->fecha_inicio !== null) {
                $inicio = Carbon::parse($p->fecha_inicio, $hoy->getTimezone())->startOfDay();
                if ($inicio->lte($hoy)) {
                    $deHoy = $p;
                    $desde = $inicio;
                }
            }
        }

        if ($actual === null || $deHoy === null || (int) $deHoy->numero <= (int) $actual->numero) {
            return null;
        }

        if ((int) round($desde->diffInDays($hoy)) < self::DIAS_DE_GRACIA_DEL_PERIODO) {
            return null;
        }

        return [
            'tipo' => 'periodo_desfasado',
            'clave' => 'periodo_desfasado:p='.$deHoy->id,
            'insistencia' => self::IMPORTANTE,
            'urgencia' => 180,
            'icono' => 'swap',
            'titular' => 'El periodo actual sigue siendo el '.$actual->numero,
            'detalle' => 'Según las fechas, el colegio está en el **Periodo '.$deHoy->numero.'** desde el **'
                .$desde->locale('es')->isoFormat('D [de] MMMM').'**. Quien ingresa queda en el periodo actual.',
            'filas' => [],
            'total_filas' => 0,
            'destino' => ['ruta' => '/colegio/'.$year->id.'/periodos', 'etiqueta' => 'Cambiar el periodo actual'],
            'posponer' => ['etiqueta' => 'Seguimos nivelando', 'para_todos' => true],
            'primero_para' => [],
        ];
    }

    /** `['el inicio', 'el fin']` → `el inicio y el fin`. */
    private function enLista(array $cosas): string
    {
        $ultima = array_pop($cosas);

        return $cosas === [] ? $ultima : implode(', ', $cosas).' y '.$ultima;
    }

    /* ── 10. La plantilla cambió y no se ha propagado ───────────────────────────────── */

    /**
     * Contra la última foto (`FotoDeLaPlantilla`). Sin foto no sale: el colegio que nunca
     * propagó desde que existen no tiene con qué comparar, y adivinarlo sería inventar.
     */
    private function plantillaSinPropagar(object $year): ?array
    {
        $cambios = FotoDeLaPlantilla::cambios((int) $year->id);

        if ($cambios === null || $cambios === []) {
            return null;
        }

        $foto = FotoDeLaPlantilla::ultima((int) $year->id);
        $n = count($cambios);
        $cuando = FotoDeLaPlantilla::cuando($foto);

        return [
            'tipo' => 'plantilla_sin_propagar',
            'clave' => 'plantilla_sin_propagar:y='.$year->id,
            'insistencia' => self::IMPORTANTE,
            'urgencia' => 160,
            'icono' => 'diff',
            'titular' => 'La plantilla tiene '.$this->plural($n, 'cambio', 'cambios').' sin propagar',
            'detalle' => 'Se propagó por última vez el **'.$cuando.'**'.($foto->quien ? ' ('.$foto->quien.')' : '')
                .'. Las asignaturas siguen con la plantilla de ese día.',
            'filas' => array_map(static fn ($c) => ['texto' => $c['texto'], 'nota' => null, 'aviso' => $c['tipo'] === 'menos'], array_slice($cambios, 0, self::TOPE_DE_FILAS)),
            'total_filas' => $n,
            'destino' => ['ruta' => '/plan-evaluacion', 'query' => ['paso' => 'plantilla'], 'etiqueta' => 'Revisar la plantilla'],
            'primero_para' => ['Coord académico'],
        ];
    }

    /* ── 11. Tardanzas que ya dan una situación y nadie la ha creado ──────────────── */

    /**
     * Con la regla del colegio (`dis_configuraciones`): cada `cant_tard_to_ft1` tardanzas **de
     * entrada** dan una situación tipo 1. Se cuentan por periodo si `reinicia_por_periodo`
     * —y entonces sólo el periodo actual, que es el que se está viviendo— o en el año entero.
     * Contra las situaciones que ya salieron de tardanzas (`dis_procesos.deriva_de_tardanzas`):
     * con 10 tardanzas y una situación, falta la segunda.
     *
     * No hay dónde marcar «ya lo vi»: se va cuando la situación existe. Con el umbral en 0
     * la regla está apagada y no sale nada.
     */
    private function tardanzasSinSituacion(object $year): ?array
    {
        $conf = DB::selectOne('SELECT * FROM dis_configuraciones WHERE year_id = ? AND deleted_at IS NULL ORDER BY id LIMIT 1', [(int) $year->id]);
        $umbral = $conf === null ? 0 : (int) $conf->cant_tard_to_ft1;

        if ($umbral <= 0) {
            return null;
        }

        $porPeriodo = (int) $conf->reinicia_por_periodo === 1;
        $filtro = '';
        $datos = [(int) $year->id];

        if ($porPeriodo) {
            $actual = DB::selectOne('SELECT id FROM periodos WHERE year_id = ? AND actual = 1 AND deleted_at IS NULL', [(int) $year->id]);
            if ($actual === null) {
                return null;
            }
            $filtro = ' AND p.id = ?';
            $datos[] = (int) $actual->id;
        }

        $cuentas = DB::select(
            'SELECT a.alumno_id, COUNT(*) AS tardanzas,
                    (SELECT COUNT(*) FROM dis_procesos d
                      WHERE d.alumno_id = a.alumno_id AND d.year_id = p.year_id AND d.deriva_de_tardanzas = 1
                        AND d.deleted_at IS NULL'.($porPeriodo ? ' AND d.periodo_id = p.id' : '').') AS situaciones
               FROM ausencias a
               INNER JOIN periodos p ON p.id = a.periodo_id AND p.year_id = ? AND p.deleted_at IS NULL'.$filtro.'
              WHERE a.tipo = "tardanza" AND a.entrada = 1 AND a.deleted_at IS NULL
              GROUP BY a.alumno_id'.($porPeriodo ? ', p.id, p.year_id' : ', p.year_id').'
             HAVING tardanzas >= ?',
            array_merge($datos, [$umbral])
        );

        $faltan = [];
        foreach ($cuentas as $c) {
            if (intdiv((int) $c->tardanzas, $umbral) > (int) $c->situaciones) {
                $faltan[(int) $c->alumno_id] = (int) $c->tardanzas;
            }
        }

        if ($faltan === []) {
            return null;
        }

        $alumnos = $this->alumnosDelAnio((int) $year->id, array_keys($faltan));
        usort($alumnos, static fn ($a, $b) => $faltan[(int) $b->alumno_id] <=> $faltan[(int) $a->alumno_id]);
        $n = count($alumnos);

        if ($n === 0) {
            return null;
        }

        $situacion = mb_strtolower((string) ($conf->falta_tipo1_displayname ?: 'situación tipo 1'));

        return [
            'tipo' => 'tardanzas_sin_situacion',
            'clave' => 'tardanzas_sin_situacion:y='.$year->id,
            'insistencia' => self::IMPORTANTE,
            'urgencia' => 170,
            'icono' => 'field-time',
            'titular' => $this->plural($n, "estudiante llegó a {$umbral} tardanzas y no tiene {$situacion}", "estudiantes llegaron a {$umbral} tardanzas y no tienen {$situacion}"),
            'detalle' => 'Así está configurado en Disciplina: **'.$umbral.' tardanzas de entrada** = una '.$situacion.'. '
                .($porPeriodo ? 'Se cuenta por periodo.' : 'Se cuenta en todo el año.'),
            'filas' => array_map(fn ($a) => $this->filaDeAlumno(
                $a, $a->abrev_grupo ?: $a->nombre_grupo, $faltan[(int) $a->alumno_id].' tardanzas', true
            ), array_slice($alumnos, 0, self::TOPE_DE_FILAS)),
            'total_filas' => $n,
            'destino' => ['ruta' => '/disciplina', 'etiqueta' => 'Ir a disciplina'],
            'primero_para' => [],
        ];
    }

    /* ── 12. Prematriculados y asistentes que ya deberían estar matriculados ─────────── */

    /**
     * Desde el periodo 2 (el actual del año), las matrículas PREM, PREA o ASIS que llevan
     * en ese estado más de `years.dias_max_prematricula` días (10 por defecto). La fecha es
     * `estado_desde`; en las filas de antes de existir esa columna, la mejor que hay
     * —`prematriculado`, `fecha_matricula` o el alta de la fila— y la nota dice «al menos».
     */
    private function prematriculaVieja(object $year): ?array
    {
        $actual = DB::selectOne('SELECT numero FROM periodos WHERE year_id = ? AND actual = 1 AND deleted_at IS NULL', [(int) $year->id]);

        if ($actual === null || (int) $actual->numero < 2) {
            return null;
        }

        $dias = (int) ($year->dias_max_prematricula ?? 10);
        $conFecha = property_exists($year, 'dias_max_prematricula');
        $hoy = Reloj::ahora()->startOfDay();

        $filas = DB::select(
            'SELECT al.id AS alumno_id, al.nombres, al.apellidos, i.nombre AS foto,
                    g.nombre AS nombre_grupo, g.abrev AS abrev_grupo, m.estado,
                    '.($conFecha ? 'm.estado_desde' : 'NULL').' AS estado_desde,
                    COALESCE(IF(m.estado = "PREM", m.prematriculado, NULL), m.fecha_matricula, DATE(m.created_at)) AS aprox
               FROM matriculas m
               INNER JOIN grupos g ON g.id = m.grupo_id AND g.year_id = ? AND g.deleted_at IS NULL
               INNER JOIN alumnos al ON al.id = m.alumno_id AND al.deleted_at IS NULL
               LEFT JOIN images i ON i.id = al.foto_id AND i.deleted_at IS NULL
              WHERE m.deleted_at IS NULL AND m.estado IN ("PREM", "PREA", "ASIS")
              ORDER BY g.orden, g.nombre, al.apellidos, al.nombres',
            [(int) $year->id]
        );

        $nombres = ['PREM' => 'prematriculado', 'PREA' => 'prematriculado', 'ASIS' => 'asistente'];
        $viejas = [];

        foreach ($filas as $f) {
            $desde = $f->estado_desde ?? $f->aprox;
            if ($desde === null) {
                continue;
            }
            $lleva = (int) round(Carbon::parse($desde, $hoy->getTimezone())->startOfDay()->diffInDays($hoy));
            if ($lleva > $dias) {
                $f->nota = ($nombres[$f->estado] ?? $f->estado).' hace '.($f->estado_desde === null ? 'al menos ' : '').$lleva.' días';
                $viejas[] = $f;
            }
        }

        $n = count($viejas);

        if ($n === 0) {
            return null;
        }

        return [
            'tipo' => 'prematricula_vieja',
            'clave' => 'prematricula_vieja:y='.$year->id,
            'insistencia' => self::POSPONIBLE,
            'urgencia' => 80,
            'icono' => 'solution',
            'titular' => $this->plural($n, 'estudiante sigue', 'estudiantes siguen').' como prematriculado o asistente',
            'detalle' => 'Ya va el **Periodo '.$actual->numero.'** y '.($n === 1 ? 'lleva' : 'llevan').' más de **'.$dias.' días** en ese estado: o se '.($n === 1 ? 'matricula o se retira.' : 'matriculan o se retiran.'),
            'filas' => array_map(fn ($a) => $this->filaDeAlumno($a, $a->abrev_grupo ?: $a->nombre_grupo, $a->nota, false), array_slice($viejas, 0, self::TOPE_DE_FILAS)),
            'total_filas' => $n,
            'destino' => ['ruta' => '/matriculas', 'etiqueta' => 'Ir a matrículas'],
            'primero_para' => ['Secretario'],
        ];
    }

    /* ── 13. Personas del año sin documento o sin correo ─────────────────────────────── */

    /**
     * Matriculados sin documento o sin correo. **El correo es el de la cuenta**
     * (`users.email`, plan §2.6): es el único con el que se recupera la contraseña. Un
     * `…@myvc.com` inventado al crear la cuenta cuenta como vacío: nadie lo lee.
     */
    private function alumnosSinDatos(object $year): ?array
    {
        $alumnos = DB::select(
            'SELECT al.id AS alumno_id, al.nombres, al.apellidos, i.nombre AS foto,
                    g.nombre AS nombre_grupo, g.abrev AS abrev_grupo,
                    (al.documento IS NULL OR TRIM(al.documento) = "") AS sin_documento,
                    (u.email IS NULL OR TRIM(u.email) = "" OR u.email LIKE "%@myvc.com") AS sin_correo
               FROM matriculas m
               INNER JOIN grupos g ON g.id = m.grupo_id AND g.year_id = ? AND g.deleted_at IS NULL
               INNER JOIN alumnos al ON al.id = m.alumno_id AND al.deleted_at IS NULL
               LEFT JOIN users u ON u.id = al.user_id AND u.deleted_at IS NULL
               LEFT JOIN images i ON i.id = al.foto_id AND i.deleted_at IS NULL
              WHERE m.deleted_at IS NULL AND '.self::MATRICULADO.'
             HAVING sin_documento = 1 OR sin_correo = 1
              ORDER BY g.orden, g.nombre, al.apellidos, al.nombres',
            [(int) $year->id]
        );

        $n = count($alumnos);

        if ($n === 0) {
            return null;
        }

        return [
            'tipo' => 'alumnos_sin_datos',
            'clave' => 'alumnos_sin_datos:y='.$year->id,
            'insistencia' => self::POSPONIBLE,
            'urgencia' => 60,
            'icono' => 'idcard',
            'titular' => $this->plural($n, 'estudiante no tiene', 'estudiantes no tienen').' documento o correo',
            'detalle' => "Matriculados en **{$year->year}**. Sin correo en su cuenta no pueden recuperar la contraseña.",
            'filas' => array_map(fn ($a) => $this->filaDeAlumno($a, $a->abrev_grupo ?: $a->nombre_grupo, $this->queFalta($a), false), array_slice($alumnos, 0, self::TOPE_DE_FILAS)),
            'total_filas' => $n,
            'destino' => ['ruta' => '/alumnos', 'etiqueta' => 'Ir a alumnos'],
            'primero_para' => ['Secretario'],
        ];
    }

    /** Docentes con contrato en el año, sin cédula o sin correo en su cuenta. */
    private function docentesSinDatos(object $year): ?array
    {
        $docentes = DB::select(
            'SELECT p.id, p.nombres, p.apellidos, i.nombre AS foto,
                    (p.num_doc IS NULL OR TRIM(p.num_doc) = "") AS sin_documento,
                    (u.email IS NULL OR TRIM(u.email) = "" OR u.email LIKE "%@myvc.com") AS sin_correo
               FROM contratos c
               INNER JOIN profesores p ON p.id = c.profesor_id AND p.deleted_at IS NULL
               LEFT JOIN users u ON u.id = p.user_id AND u.deleted_at IS NULL
               LEFT JOIN images i ON i.id = p.foto_id AND i.deleted_at IS NULL
              WHERE c.year_id = ? AND c.deleted_at IS NULL
              GROUP BY p.id, p.nombres, p.apellidos, i.nombre, p.num_doc, u.email
             HAVING sin_documento = 1 OR sin_correo = 1
              ORDER BY p.apellidos, p.nombres',
            [(int) $year->id]
        );

        $n = count($docentes);

        if ($n === 0) {
            return null;
        }

        return [
            'tipo' => 'docentes_sin_datos',
            'clave' => 'docentes_sin_datos:y='.$year->id,
            'insistencia' => self::POSPONIBLE,
            'urgencia' => 55,
            'icono' => 'idcard',
            'titular' => $this->plural($n, 'docente no tiene', 'docentes no tienen').' cédula o correo',
            'detalle' => "Con contrato en **{$year->year}**. Sin correo en su cuenta no pueden recuperar la contraseña.",
            'filas' => array_map(fn ($d) => [
                'texto' => trim($d->nombres.' '.$d->apellidos),
                'nota' => $this->queFalta($d, 'cédula'),
                'aviso' => false,
                'foto' => $d->foto,
                'nombres' => $d->nombres,
                'apellidos' => $d->apellidos,
            ], array_slice($docentes, 0, self::TOPE_DE_FILAS)),
            'total_filas' => $n,
            'destino' => ['ruta' => '/profesores', 'etiqueta' => 'Ir a docentes'],
            'primero_para' => ['Secretario'],
        ];
    }

    private function queFalta(object $p, string $documento = 'documento'): string
    {
        if ((int) $p->sin_documento === 1 && (int) $p->sin_correo === 1) {
            return "sin {$documento} ni correo";
        }

        return (int) $p->sin_documento === 1 ? "sin {$documento}" : 'sin correo';
    }

    /**
     * Los matriculados del año entre `$ids`, con grupo y foto.
     *
     * @param  list<int>  $ids
     * @return list<object>
     */
    private function alumnosDelAnio(int $yearId, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return DB::select(
            'SELECT al.id AS alumno_id, al.nombres, al.apellidos, i.nombre AS foto,
                    g.nombre AS nombre_grupo, g.abrev AS abrev_grupo
               FROM matriculas m
               INNER JOIN grupos g ON g.id = m.grupo_id AND g.year_id = ? AND g.deleted_at IS NULL
               INNER JOIN alumnos al ON al.id = m.alumno_id AND al.deleted_at IS NULL
               LEFT JOIN images i ON i.id = al.foto_id AND i.deleted_at IS NULL
              WHERE m.deleted_at IS NULL AND '.self::MATRICULADO.'
                AND al.id IN ('.implode(',', array_fill(0, count($ids), '?')).')',
            array_merge([$yearId], $ids)
        );
    }

    /* ── 14. Configuración del año ──────────────────────────────────────────────────── */

    /** Un año nuevo nace con los grupos sin titular a propósito (`YearsController:518`). */
    private function gruposSinTitular(object $year): ?array
    {
        $grupos = DB::select('SELECT nombre FROM grupos WHERE year_id = ? AND deleted_at IS NULL AND titular_id IS NULL ORDER BY orden, nombre', [(int) $year->id]);
        $n = count($grupos);

        if ($n === 0) {
            return null;
        }

        return [
            'tipo' => 'grupos_sin_titular',
            'clave' => 'grupos_sin_titular:y='.$year->id,
            'insistencia' => self::POSPONIBLE,
            'urgencia' => 75,
            'icono' => 'team',
            'titular' => $this->plural($n, 'grupo no tiene', 'grupos no tienen').' titular',
            'detalle' => 'Sin titular, nadie firma sus boletines ni ve el aviso de sus asignaturas sin cerrar.',
            'filas' => array_map(static fn ($g) => ['texto' => $g->nombre, 'nota' => 'sin titular', 'aviso' => false], array_slice($grupos, 0, self::TOPE_DE_FILAS)),
            'total_filas' => $n,
            'destino' => ['ruta' => '/grupos', 'etiqueta' => 'Poner titulares'],
            'primero_para' => ['Coord académico'],
        ];
    }

    /**
     * Sin docente, o con uno que no tiene contrato este año: un año nuevo copia el
     * `profesor_id` pero no los contratos, y la celda sale en blanco (`YearsController:579`).
     */
    private function asignaturasSinDocente(object $year): ?array
    {
        $filas = DB::select(
            'SELECT m.materia, g.nombre AS grupo, a.profesor_id
               FROM asignaturas a
               INNER JOIN grupos g ON g.id = a.grupo_id AND g.year_id = ? AND g.deleted_at IS NULL
               INNER JOIN materias m ON m.id = a.materia_id
              WHERE a.deleted_at IS NULL
                AND (a.profesor_id IS NULL OR NOT EXISTS (
                     SELECT 1 FROM contratos c WHERE c.profesor_id = a.profesor_id AND c.year_id = g.year_id AND c.deleted_at IS NULL))
              ORDER BY g.orden, g.nombre, m.orden, m.materia',
            [(int) $year->id]
        );
        $n = count($filas);

        if ($n === 0) {
            return null;
        }

        return [
            'tipo' => 'asignaturas_sin_docente',
            'clave' => 'asignaturas_sin_docente:y='.$year->id,
            'insistencia' => self::IMPORTANTE,
            'urgencia' => 140,
            'icono' => 'user-delete',
            'titular' => $this->plural($n, 'asignatura no tiene', 'asignaturas no tienen').' docente',
            'detalle' => 'Nadie puede poner sus notas. Cuenta también la que tiene un docente sin contrato en **'.$year->year.'**.',
            'filas' => array_map(fn ($f) => [
                'texto' => $this->capitalizar($f->materia).' · '.$f->grupo,
                'nota' => $f->profesor_id === null ? 'sin docente' : 'docente sin contrato',
                'aviso' => false,
            ], array_slice($filas, 0, self::TOPE_DE_FILAS)),
            'total_filas' => $n,
            'destino' => ['ruta' => '/asignaturas', 'etiqueta' => 'Asignar docentes'],
            'primero_para' => ['Coord académico'],
        ];
    }

    /**
     * La escala del año: que exista, que no tenga huecos y que alguna banda sea «perdido».
     * Los huecos con la misma regla que el front (`cobertura-de-la-escala.ts`): una banda
     * cubre hasta `porc_final + 1`, y lo que queda entre una y la siguiente es hueco.
     */
    private function escalaIncompleta(object $year): ?array
    {
        $bandas = DB::select('SELECT desempenio, porc_inicial, porc_final, perdido FROM escalas_de_valoracion
            WHERE year_id = ? AND deleted_at IS NULL ORDER BY porc_inicial', [(int) $year->id]);
        $problemas = [];

        if ($bandas === []) {
            $problemas[] = ['texto' => 'No hay ninguna banda', 'nota' => null, 'aviso' => true];
        } else {
            $cubierto = 0.0;
            foreach ($bandas as $b) {
                $desde = (float) $b->porc_inicial;
                $hasta = (float) $b->porc_final + 1;
                if ($desde > $cubierto) {
                    $problemas[] = ['texto' => 'De '.$this->nota($cubierto).' a '.$this->nota($desde - 0.01), 'nota' => 'sin desempeño', 'aviso' => true];
                }
                $cubierto = max($cubierto, $hasta);
            }
            if (array_filter($bandas, static fn ($b) => (int) $b->perdido === 1) === []) {
                $problemas[] = ['texto' => 'Ninguna banda está marcada como perdida', 'nota' => null, 'aviso' => true];
            }
        }

        if ($problemas === []) {
            return null;
        }

        return [
            'tipo' => 'escala_incompleta',
            'clave' => 'escala_incompleta:y='.$year->id,
            'insistencia' => self::IMPORTANTE,
            'urgencia' => 130,
            'icono' => 'bar-chart',
            'titular' => 'La escala de valoración de '.$year->year.' está incompleta',
            'detalle' => 'Una nota que cae en un hueco sale sin desempeño en el boletín, y sin banda perdida no se sabe qué se pierde.',
            'filas' => $problemas,
            'total_filas' => count($problemas),
            'destino' => ['ruta' => '/colegio/anios', 'etiqueta' => 'Revisar la escala'],
            'primero_para' => [],
        ];
    }

    /**
     * Cada destino de la plantilla (nivel × materia de las asignaturas del año) tiene que
     * sumar 100: es el 422 de `putSembrar`, dicho antes de que alguien pulse el botón.
     * **Vacía** es Silenciable: un colegio puede no usar plantilla y dejar que cada docente
     * monte la suya.
     */
    private function plantillaQueNoSuma(object $year): ?array
    {
        $yearId = (int) $year->id;
        $vacia = ! DB::table('unidades_por_defecto')->where('year_id', $yearId)->whereNull('deleted_at')->exists();

        if ($vacia) {
            return [
                'tipo' => 'plantilla_que_no_suma',
                'clave' => 'plantilla_vacia:y='.$yearId,
                'insistencia' => self::SILENCIABLE,
                'urgencia' => 40,
                'icono' => 'profile',
                'titular' => 'El año '.$year->year.' no tiene plantilla de notas',
                'detalle' => 'Sin ella, cada asignatura nace sin columnas y cada docente monta las suyas. Si el colegio lo prefiere así, silencia este aviso.',
                'filas' => [],
                'total_filas' => 0,
                'destino' => ['ruta' => '/plan-evaluacion', 'query' => ['paso' => 'plantilla'], 'etiqueta' => 'Crear la plantilla'],
                'primero_para' => ['Coord académico'],
            ];
        }

        $destinos = DB::select(
            'SELECT DISTINCT a.materia_id, g2.nivel_educativo_id, m.materia, n.nombre AS nivel
               FROM asignaturas a
               JOIN grupos g  ON g.id = a.grupo_id AND g.deleted_at IS NULL AND g.year_id = ?
               JOIN grados g2 ON g2.id = g.grado_id AND g2.deleted_at IS NULL
               LEFT JOIN materias m ON m.id = a.materia_id
               LEFT JOIN niveles_educativos n ON n.id = g2.nivel_educativo_id
              WHERE a.deleted_at IS NULL',
            [$yearId]
        );

        $filas = [];
        $vistas = [];
        foreach ($destinos as $d) {
            $unidades = AlcanceDeLaPlantilla::unidadesPara(
                $yearId,
                $d->nivel_educativo_id === null ? null : (int) $d->nivel_educativo_id,
                $d->materia_id === null ? null : (int) $d->materia_id
            );
            if ($unidades === []) {
                continue;
            }
            $suma = array_sum(array_map(static fn ($u) => (int) $u->porcentaje, $unidades));
            // Varias materias caen en el mismo reparto (las filas generales): se dice una vez.
            $firma = implode(',', array_map(static fn ($u) => (int) $u->id, $unidades));
            if ($suma === 100 || isset($vistas[$firma])) {
                continue;
            }
            $vistas[$firma] = true;
            $general = array_filter($unidades, static fn ($u) => ($u->nivel_educativo_id ?? null) !== null || ($u->materia_id ?? null) !== null) === [];
            $filas[] = [
                'texto' => $general ? 'La plantilla general' : trim(($d->nivel ?? '').' · '.$this->capitalizar((string) $d->materia), ' ·'),
                'nota' => 'suma '.$suma.' %',
                'aviso' => true,
            ];
        }

        $n = count($filas);

        if ($n === 0) {
            return null;
        }

        return [
            'tipo' => 'plantilla_que_no_suma',
            'clave' => 'plantilla_que_no_suma:y='.$yearId,
            'insistencia' => self::IMPORTANTE,
            'urgencia' => 150,
            'icono' => 'profile',
            'titular' => $n === 1 ? 'Un reparto de la plantilla no suma 100 %' : "{$n} repartos de la plantilla no suman 100 %",
            'detalle' => 'Así no se puede propagar: las asignaturas de estos repartos quedarían con una definitiva que no llega o se pasa.',
            'filas' => array_slice($filas, 0, self::TOPE_DE_FILAS),
            'total_filas' => $n,
            'destino' => ['ruta' => '/plan-evaluacion', 'query' => ['paso' => 'plantilla'], 'etiqueta' => 'Revisar la plantilla'],
            'primero_para' => ['Coord académico'],
        ];
    }

    /** Lo que sale en los papeles oficiales: logo, DANE, resolución, rector y su firma. */
    private function fichaIncompleta(object $year): ?array
    {
        $vacio = static fn ($v) => $v === null || trim((string) $v) === '';
        $falta = [];

        if ($vacio($year->logo_id ?? null)) {
            $falta[] = ['texto' => 'Logo del colegio', 'nota' => 'sale en boletines y certificados'];
        }
        if ($vacio($year->codigo_dane ?? null)) {
            $falta[] = ['texto' => 'Código DANE', 'nota' => 'sale en certificados'];
        }
        if ($vacio($year->resolucion ?? null)) {
            $falta[] = ['texto' => 'Resolución de aprobación', 'nota' => 'sale en certificados'];
        }
        if ($vacio($year->rector_id ?? null)) {
            $falta[] = ['texto' => 'Rector', 'nota' => 'firma boletines finales y certificados'];
        } else {
            $firma = DB::selectOne('SELECT firma_id FROM profesores WHERE id = ? AND deleted_at IS NULL', [(int) $year->rector_id]);
            if ($firma === null || $vacio($firma->firma_id)) {
                $falta[] = ['texto' => 'Firma del rector', 'nota' => 'sale en boletines finales y certificados'];
            }
        }

        $n = count($falta);

        if ($n === 0) {
            return null;
        }

        return [
            'tipo' => 'ficha_incompleta',
            'clave' => 'ficha_incompleta:y='.$year->id,
            'insistencia' => self::SILENCIABLE,
            'urgencia' => 45,
            'icono' => 'bank',
            'titular' => $n === 1 ? 'Falta en la ficha del colegio: '.lcfirst($falta[0]['texto']) : "Faltan {$n} datos en la ficha del colegio",
            'detalle' => 'Salen en los papeles oficiales de **'.$year->year.'**.',
            'filas' => array_map(static fn ($f) => $f + ['aviso' => false], $falta),
            'total_filas' => $n,
            'destino' => ['ruta' => '/colegio/'.$year->id.'/ficha', 'etiqueta' => 'Completar la ficha'],
            'primero_para' => ['Secretario'],
        ];
    }

    /** `29.99` → `29,99`; `30` → `30`. Como `rotuloDeNota` del front. */
    private function nota(float $valor): string
    {
        return floor($valor) === $valor ? (string) (int) $valor : str_replace('.', ',', number_format($valor, 2, '.', ''));
    }

    /* ── Piezas ──────────────────────────────────────────────────────────────────────── */

    private function pendienteDeAlumnos(
        int $yearId, string $tipo, int $urgencia, string $icono, array $alumnos, array $titular,
        string $detalle, array $destino, ?array $alterno
    ): ?array {
        $cuantos = count($alumnos);

        if ($cuantos === 0) {
            return null;
        }

        return [
            'tipo' => $tipo,
            'clave' => $tipo.':y='.$yearId,
            'insistencia' => self::POSPONIBLE,
            'urgencia' => $urgencia,
            'icono' => $icono,
            'titular' => $this->plural($cuantos, $titular[0], $titular[1]),
            'detalle' => $detalle,
            'filas' => array_map(fn ($a) => $this->filaDeAlumno(
                $a, $a->abrev_grupo ?: $a->nombre_grupo, null, false
            ), array_slice($alumnos, 0, self::TOPE_DE_FILAS)),
            'total_filas' => $cuantos,
            'destino' => $destino,
            'destino_alterno' => $alterno,
            'primero_para' => ['Secretario'],
        ];
    }

    private function filaDeAlumno(?object $a, string $grupo, ?string $nota, bool $aviso): array
    {
        return [
            'texto' => trim(($a->nombres ?? '').' '.($a->apellidos ?? '')).' · '.$grupo,
            'nota' => $nota,
            'aviso' => $aviso,
            'foto' => $a->foto ?? null,
            'nombres' => $a->nombres ?? null,
            'apellidos' => $a->apellidos ?? null,
        ];
    }

    /** @return array<int, object> Una consulta para todas las fotos que se van a pintar. */
    private function fotosDeAlumnos(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));

        if ($ids === []) {
            return [];
        }

        $salida = [];

        foreach (DB::select(
            'SELECT al.id, al.nombres, al.apellidos, i.nombre AS foto FROM alumnos al
               LEFT JOIN images i ON i.id = al.foto_id AND i.deleted_at IS NULL
              WHERE al.id IN ('.implode(',', array_fill(0, count($ids), '?')).')',
            $ids
        ) as $f) {
            $salida[(int) $f->id] = $f;
        }

        return $salida;
    }

    /** `CIENCIAS SOCIALES` se lee peor que `Ciencias sociales`; muchos colegios las escriben en mayúsculas. */
    private function capitalizar(string $texto): string
    {
        if ($texto !== mb_strtoupper($texto)) {
            return $texto;
        }

        $bajo = mb_strtolower($texto);

        return mb_strtoupper(mb_substr($bajo, 0, 1)).mb_substr($bajo, 1);
    }

    private function nombreDocente(object $a): string
    {
        return trim(($a->nombres ?? '').' '.($a->apellidos ?? ''));
    }

    private function plural(int $n, string $uno, string $varios): string
    {
        return $n.' '.($n === 1 ? $uno : $varios);
    }
}
