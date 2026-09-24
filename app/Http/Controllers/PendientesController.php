<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResuelveElUsuario;
use App\Models\Role;
use App\Support\Autoriza;
use App\Support\CierreDeAsignatura;
use App\Support\Reloj;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * **Las cosas pendientes del colegio**: el aviso que sale una vez por ingreso y que
 * queda además en Inicio › Pendientes (`myvc_front/app2`, `comunes/pendientes/`).
 *
 * `GET pendientes/mios` devuelve la lista **ya ordenada por urgencia** y **ya filtrada
 * por quién pregunta**. Cada pendiente viaja con todo lo que la pantalla pinta
 * —`tipo`, `titular`, `detalle` (con `**negritas**`), `filas` y `destino`—, así que el
 * front no decide textos ni vuelve a contar nada.
 *
 * ## Quién ve qué
 *
 * Sólo **directivos**: superusuario, `Admin`, `Rector`, `Secretario`, `Coord académico`
 * y `Coord disciplinario`. Cualquier otro recibe la lista vacía en 200 —no 403—: el
 * front la pide a todo el personal al entrar y «no tienes pendientes» es la respuesta
 * verdadera. El jefe de área no la necesita (encargo del 24 sep 2026).
 *
 * | tipo | lo ve | primero para |
 * |---|---|---|
 * | `entrega_boletines` | directivos | — (manda la fecha) |
 * | `compromisos` | quien coordina compromisos (`Autoriza::puedeCambiarLaNotaNumerica`) | Coord académico |
 * | `jefes_de_area` | directivos | — |
 * | `intensidad_horaria` | directivos | Coord académico |
 * | `acudientes` | directivos | Secretario |
 * | `celular` | directivos | Secretario |
 *
 * «Primero para» es un empujón de orden: a la secretaria le sale arriba lo suyo aunque
 * haya algo más urgente de coordinación, porque es lo que ella puede resolver.
 *
 * ## Todo del año que ELIGIÓ el usuario
 *
 * `$user->year_id` sale de `users.periodo_id` (`ContextoDeUsuario`), no de
 * `years.actual`: es el mismo año que ve en el selector y el mismo que usan
 * `areas/jefes` y el tablero de cierres. Ver la memoria «el año actual lo elige el
 * usuario».
 *
 * ## Coste
 *
 * Una consulta por tipo, más dos del cierre (`delPeriodo`, `faltantesPorAsignatura`) y
 * las de `contarPerdidas`. Nada por fila. Medido contra el docker en el commit.
 *
 * ## Punto de extensión: firmas del titular por aprobar
 *
 * Cuando exista el endpoint de la firma del titular (otro frente, 24 sep 2026), su
 * pendiente es un método más como los de abajo, añadido a `getMios()` con su audiencia.
 * No hay nada que tocar en el front: pinta cualquier `tipo` con la misma forma.
 */
class PendientesController extends Controller
{
    use ResuelveElUsuario;

    /**
     * **El aviso de entrega sale desde 14 días antes**, contando hoy como día 0.
     *
     * Dos semanas es el tiempo que coordinación necesita para perseguir a los docentes
     * que no han cerrado: con 7 el aviso llega cuando ya no hay margen, y con 30 sale
     * casi el periodo entero y deja de leerse. Pasada la fecha no sale: los boletines
     * ya se entregaron y lo que quede abierto es otra conversación.
     */
    public const DIAS_DE_AVISO_DE_ENTREGA = 14;

    /** Cuántas filas viajan por pendiente como mucho. El total va aparte. */
    public const TOPE_DE_FILAS = 50;

    private const DIRECTIVOS = ['Admin', 'Rector', 'Secretario', 'Coord académico', 'Coord disciplinario'];

    /** Los estados de una matrícula de verdad: ni retirados ni prematriculados. */
    private const MATRICULADO = 'm.estado IN ("MATR","ASIS")';

    public function getMios(): array
    {
        $user = $this->user;
        $roles = array_map(static fn ($r) => (string) $r->name, Role::getUserRoles($user->user_id ?? 0));

        if (! $this->esDirectivo($user, $roles)) {
            return ['year_id' => (int) $user->year_id, 'pendientes' => []];
        }

        $year = DB::selectOne('SELECT id, year FROM years WHERE id = ? AND deleted_at IS NULL', [(int) $user->year_id]);

        if ($year === null) {
            return ['year_id' => (int) $user->year_id, 'pendientes' => []];
        }

        $pendientes = array_filter([
            $this->entregaDeBoletines($year),
            Autoriza::puedeCambiarLaNotaNumerica($user) ? $this->compromisos($year, (int) ($user->numero_periodo ?? 0)) : null,
            $this->jefesDeArea($year),
            $this->intensidadHoraria($year),
            $this->sinAcudiente($year),
            $this->sinCelular($year),
        ]);

        foreach ($pendientes as &$p) {
            if (array_intersect($p['primero_para'], $roles) !== []) {
                $p['urgencia'] += 1000;
            }
            unset($p['primero_para']);
        }
        unset($p);

        usort($pendientes, static fn ($a, $b) => $b['urgencia'] <=> $a['urgencia']);

        return ['year_id' => (int) $year->id, 'pendientes' => array_values($pendientes)];
    }

    /** @param list<string> $roles */
    private function esDirectivo($user, array $roles): bool
    {
        return Autoriza::esSuperusuario($user) || array_intersect(self::DIRECTIVOS, $roles) !== [];
    }

    /* ── 1. La entrega de boletines se acerca y hay asignaturas sin cerrar ─────────── */

    private function entregaDeBoletines(object $year): ?array
    {
        // `SELECT *` y no la columna por nombre: `fecha_entrega_boletines` la añade una
        // migración aditiva que puede no haber corrido en un colegio. Sin la columna, o
        // sin fecha puesta, este aviso no existe.
        $hoy = Reloj::ahora()->startOfDay();
        $periodo = null;
        $dias = null;

        foreach (DB::select('SELECT * FROM periodos WHERE year_id = ? AND deleted_at IS NULL ORDER BY numero', [(int) $year->id]) as $p) {
            $fecha = $p->fecha_entrega_boletines ?? null;

            if ($fecha === null) {
                continue;
            }

            // En la zona del reloj: `Carbon::parse` a secas la leería en UTC y a las siete
            // de la noche de Bogotá «faltan 7 días» saldría como 6.
            $faltan = (int) round($hoy->diffInDays(Carbon::parse($fecha, $hoy->getTimezone())->startOfDay(), false));

            if ($faltan >= 0 && $faltan <= self::DIAS_DE_AVISO_DE_ENTREGA && ($dias === null || $faltan < $dias)) {
                $periodo = $p;
                $dias = $faltan;
            }
        }

        if ($periodo === null) {
            return null;
        }

        $cierres = CierreDeAsignatura::delPeriodo((int) $periodo->id);
        $faltan = CierreDeAsignatura::faltantesPorAsignatura((int) $periodo->id);

        $abiertas = array_values(array_filter(DB::select(
            'SELECT a.id AS asignatura_id, g.nombre AS nombre_grupo, g.abrev AS abrev_grupo,
                    m.materia, a.profesor_id, p.nombres, p.apellidos,
                    IFNULL(fp.nombre, iu.nombre) AS foto
               FROM asignaturas a
               INNER JOIN grupos g ON g.id = a.grupo_id AND g.deleted_at IS NULL
               LEFT JOIN materias m ON m.id = a.materia_id AND m.deleted_at IS NULL
               LEFT JOIN profesores p ON p.id = a.profesor_id AND p.deleted_at IS NULL
               LEFT JOIN images fp ON fp.id = p.foto_id AND fp.deleted_at IS NULL
               LEFT JOIN users up ON up.id = p.user_id
               LEFT JOIN images iu ON iu.id = up.imagen_id AND iu.deleted_at IS NULL
              WHERE g.year_id = ? AND a.deleted_at IS NULL
              ORDER BY g.orden, g.nombre, a.orden, m.materia, a.id',
            [(int) $year->id]
        ), static fn ($a) => ! ($cierres[(int) $a->asignatura_id]['cerrada'] ?? false)));

        $cuantas = count($abiertas);

        if ($cuantas === 0) {
            return null;
        }

        foreach ($abiertas as $a) {
            $a->faltan = $faltan[(int) $a->asignatura_id] ?? 0;
        }

        usort($abiertas, static fn ($x, $y) => $y->faltan <=> $x->faltan);

        $docentes = count(array_unique(array_filter(array_map(static fn ($a) => $a->profesor_id, $abiertas))));
        $sinDocente = count(array_filter($abiertas, static fn ($a) => $a->profesor_id === null));

        $cuando = match (true) {
            $dias === 0 => 'Hoy se entregan',
            $dias === 1 => 'Mañana se entregan',
            default => "Faltan {$dias} días para entregar",
        };

        $asignaturas = $this->plural($cuantas, 'asignatura sin cerrar', 'asignaturas sin cerrar');
        $fecha = Carbon::parse($periodo->fecha_entrega_boletines)->locale('es')->isoFormat('dddd D [de] MMMM');

        $deQuien = $cuantas === 1
            ? ($docentes === 1 ? 'Es de '.$this->nombreDocente($abiertas[0]).'.' : 'No tiene docente asignado.')
            : 'Las '.$cuantas.' asignaturas son de '.$this->plural($docentes, 'docente', 'docentes')
                .($sinDocente > 0 ? ', y '.$this->plural($sinDocente, 'no tiene', 'no tienen').' docente' : '').'.';

        return [
            'tipo' => 'entrega_boletines',
            // Iconos de Ant que `app2` ya registra (`app.config.ts`, `ICONOS`): uno que no
            // esté ahí se pide por la red y sale en blanco.
            'urgencia' => 200 + (self::DIAS_DE_AVISO_DE_ENTREGA - $dias),
            'icono' => 'clock-circle',
            'titular' => "{$cuando} los boletines del Periodo {$periodo->numero} y hay {$asignaturas}",
            'detalle' => "La entrega es el **{$fecha}**. {$deQuien}",
            'filas' => array_map(fn ($a) => [
                'texto' => implode(' · ', array_filter([
                    $a->materia ?? 'Asignatura',
                    $a->abrev_grupo ?: $a->nombre_grupo,
                    $a->profesor_id !== null ? 'Prof. '.$this->nombreDocente($a) : 'sin docente',
                ])),
                'nota' => $a->faltan > 0 ? $this->plural($a->faltan, 'nota sin poner', 'notas sin poner') : 'completa, sin cerrar',
                'aviso' => $a->faltan > 0,
                'foto' => $a->profesor_id !== null ? ($a->foto ?? null) : null,
                'nombres' => $a->nombres ?? null,
                'apellidos' => $a->apellidos ?? null,
            ], array_slice($abiertas, 0, self::TOPE_DE_FILAS)),
            'total_filas' => $cuantas,
            'destino' => [
                'ruta' => '/colegio/'.$year->id.'/periodos',
                'query' => ['registro' => (int) $periodo->id],
                'etiqueta' => 'Ver estado de registro',
            ],
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
            'acudientes', 70, 'team', $alumnos,
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
            'celular', 60, 'phone', $sin,
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

    /* ── Piezas ──────────────────────────────────────────────────────────────────────── */

    private function pendienteDeAlumnos(
        string $tipo, int $urgencia, string $icono, array $alumnos, array $titular,
        string $detalle, array $destino, ?array $alterno
    ): ?array {
        $cuantos = count($alumnos);

        if ($cuantos === 0) {
            return null;
        }

        return [
            'tipo' => $tipo,
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
