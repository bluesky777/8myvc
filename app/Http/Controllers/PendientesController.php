<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResuelveElUsuario;
use App\Models\Role;
use App\Support\Autoriza;
use App\Support\CierreDeAsignatura;
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

        $year = DB::selectOne('SELECT id, year FROM years WHERE id = ? AND deleted_at IS NULL', [(int) $user->year_id]);

        if ($year === null) {
            return $vacio;
        }

        $super = Autoriza::esSuperusuario($user);
        $directivo = $this->esDirectivo($user, $roles);
        $coordAcademico = $super || in_array('Coord académico', $roles, true);
        $profesorId = ($user->tipo ?? null) === 'Profesor' && ($user->persona_id ?? null) !== null ? (int) $user->persona_id : null;
        $todasLasAsignaturas = $super || array_intersect(['Coord académico', 'Rector'], $roles) !== [];

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
        foreach (DB::select('SELECT clave, hasta FROM pendientes_ocultos WHERE user_id = ? AND (hasta IS NULL OR hasta > ?)',
            [(int) $user->user_id, Reloj::ahoraTexto()]) as $o) {
            $ocultas[$o->clave] = $o->hasta;
        }

        $visibles = [];
        $ocultos = [];
        foreach ($pendientes as $p) {
            if ($p['insistencia'] !== self::IMPORTANTE && array_key_exists($p['clave'], $ocultas)) {
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
        abort_if($mio['insistencia'] === self::IMPORTANTE, 422, 'Este pendiente no se puede ocultar: sale hasta que se resuelva.');
        abort_if($modo === 'silenciar' && $mio['insistencia'] !== self::SILENCIABLE, 422, 'Este pendiente sólo se puede posponer.');

        $hasta = $modo === 'posponer' ? Reloj::ahora()->addDays(self::DIAS_DE_POSPONER)->toDateTimeString() : null;
        $ahora = Reloj::ahoraTexto();

        DB::table('pendientes_ocultos')->updateOrInsert(
            ['user_id' => (int) $this->user->user_id, 'clave' => $clave],
            ['hasta' => $hasta, 'updated_at' => $ahora, 'created_at' => $ahora]
        );

        return ['clave' => $clave, 'hasta' => $hasta];
    }

    /** `PUT pendientes/mostrar` — `{clave}`: deshace el ocultar. */
    public function putMostrar(): array
    {
        $clave = (string) Request::input('clave', '');

        DB::table('pendientes_ocultos')->where('user_id', (int) $this->user->user_id)->where('clave', $clave)->delete();

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
