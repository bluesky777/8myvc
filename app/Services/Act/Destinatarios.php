<?php

namespace App\Services\Act;

use App\Support\DocenteDelUsuario;
use Illuminate\Support\Facades\DB;

/**
 * A QUIÉN VA UNA ACTIVIDAD, Y A QUIÉN LE TOCA RESPONDERLA.
 *
 * Contrato: `myvc_front/ACTIVIDADES-CONTRATO.md` §2.7 (y §2.3 para la aprobación). **Es el servicio
 * único**: lo usan el conteo en vivo, la bandeja, responder, «faltan» y —en la tanda 5— los avisos.
 * Si dos de esos sitios resolvieran por su cuenta, el docente vería «32 destinatarios» y a un alumno
 * del grupo no le saldría la encuesta.
 *
 * ## Las dos mitades de un destinatario
 *
 * Una fila de `destinatarios` es un **público** (`alumnos`, `acudientes_oficiales`, `docentes`,
 * `directivos`, `personal`) en un **alcance** (`asignatura_id`, `grupo_id`, `grado_id`; los tres NULL
 * = todos los grupos del año) o una persona suelta (`user_id`). Resolverla da **entradas**: una por
 * cada persona que responde, y en «una vez por hijo» una por (acudiente, hijo).
 *
 * Una entrada es un array con:
 *
 *   user_id     quien responde; NULL = alumno sin cuenta (cuenta como `sin_cuenta`, no puede responder)
 *   alumno_id   el alumno (él mismo, o el hijo en «una vez por hijo»); NULL en personal y «una sola vez»
 *   publico     'alumno' | 'acudiente' | 'personal' — el mismo vocabulario de la hoja
 *   grupo_id    el grupo del alumno (o del hijo); NULL en personal y «una sola vez»
 *   nombre, foto, hijo (el nombre del hijo, sólo para acudientes por hijo)
 *
 * ## Por qué «al revés» no tiene su propia consulta
 *
 * «¿Qué me toca?» se contesta resolviendo hacia delante **filtrado a una persona** (`$soloUser`), y
 * no con una consulta que reproduzca las reglas en sentido contrario: dos versiones de la misma regla
 * acaban diciendo cosas distintas, y la que falla es la que nadie mira.
 */
class Destinatarios
{
    public const PUBLICOS = ['personal', 'docentes', 'directivos', 'alumnos', 'acudientes_oficiales'];

    public const ALCANCES = ['clase', 'grupo', 'grupos', 'colegio', 'personal'];

    public const RESPONDEN = ['alumnos', 'acudientes', 'ambos', 'personal'];

    /** Los públicos de alumnos que salen de `responden`. `personal` los trae cada fila. */
    public static function publicosDe(string $responden): array
    {
        return match ($responden) {
            'alumnos' => ['alumnos'],
            'acudientes' => ['acudientes_oficiales'],
            'ambos' => ['alumnos', 'acudientes_oficiales'],
            default => [],
        };
    }

    /**
     * Las filas que se guardan, a partir de lo que manda el front.
     *
     * **El público de las filas de alumnos lo decide `responden`, no la fila**: el front manda el
     * alcance (la clase, el grupo, los grupos o grados) y aquí se multiplica por los públicos. Así una
     * actividad nunca queda con `responden = 'alumnos'` y una fila de acudientes colgando. Para
     * `clase` y `grupo` el alcance puede venir también en `asignatura_id` / `grupo_id` de la config.
     *
     * @param  list<array<string, mixed>>  $dadas
     * @return array<int, array{publico: string, grupo_id: ?int, grado_id: ?int, asignatura_id: ?int, user_id: ?int}>
     */
    public static function normalizar(string $alcance, string $responden, array $dadas, ?int $asignaturaId, ?int $grupoId): array
    {
        if (($alcance === 'personal') !== ($responden === 'personal')) {
            abort(422, 'Sólo el personal responde lo que va al personal, y viceversa.');
        }

        $dadas = array_values(array_filter($dadas, 'is_array'));
        $filas = [];

        if ($alcance === 'personal') {
            foreach ($dadas as $d) {
                $user = self::entero($d['user_id'] ?? null);
                $publico = $user !== null ? 'personal' : (string) ($d['publico'] ?? '');

                if (! in_array($publico, ['personal', 'docentes', 'directivos'], true)) {
                    abort(422, 'Al personal sólo se le envía como personal, docentes, directivos o a una persona.');
                }

                $filas[] = self::fila($publico, null, null, null, $user);
            }

            return self::sinRepetir($filas);
        }

        $alcances = [];

        switch ($alcance) {
            case 'clase':
                $asignatura = $asignaturaId;

                foreach ($dadas as $d) {
                    $asignatura ??= self::entero($d['asignatura_id'] ?? null);
                }

                if ($asignatura === null) {
                    abort(422, 'Falta la clase.');
                }

                $alcances[] = [null, null, $asignatura];
                break;

            case 'grupo':
                $grupo = $grupoId;

                foreach ($dadas as $d) {
                    $grupo ??= self::entero($d['grupo_id'] ?? null);
                }

                if ($grupo === null) {
                    abort(422, 'Falta el grupo.');
                }

                $alcances[] = [$grupo, null, null];
                break;

            case 'grupos':
                foreach ($dadas as $d) {
                    $g = self::entero($d['grupo_id'] ?? null);
                    $gr = self::entero($d['grado_id'] ?? null);

                    if ($g !== null) {
                        $alcances[] = [$g, null, null];
                    } elseif ($gr !== null) {
                        $alcances[] = [null, $gr, null];
                    }
                }
                break;

            case 'colegio':
                $alcances[] = [null, null, null];
                break;

            default:
                abort(422, 'El alcance no es válido.');
        }

        foreach (self::publicosDe($responden) as $publico) {
            foreach ($alcances as [$g, $gr, $a]) {
                $filas[] = self::fila($publico, $g, $gr, $a, null);
            }
        }

        return self::sinRepetir($filas);
    }

    /** Reemplaza los destinatarios guardados de una actividad. */
    public static function guardar(int $actividadId, array $filas): void
    {
        $ahora = Actividad::ahora();

        DB::delete("DELETE FROM destinatarios WHERE origen_tipo = 'actividad' AND origen_id = ?", [$actividadId]);

        foreach ($filas as $f) {
            DB::table('destinatarios')->insert($f + [
                'origen_tipo' => 'actividad',
                'origen_id' => $actividadId,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ]);
        }
    }

    /** @return array<int, array{publico: string, grupo_id: ?int, grado_id: ?int, asignatura_id: ?int, user_id: ?int}> */
    public static function deActividad(int $actividadId): array
    {
        $filas = DB::select(
            "SELECT publico, grupo_id, grado_id, asignatura_id, user_id FROM destinatarios
              WHERE origen_tipo = 'actividad' AND origen_id = ? ORDER BY id",
            [$actividadId]
        );

        return array_map(fn ($f) => self::fila($f->publico, self::entero($f->grupo_id), self::entero($f->grado_id),
            self::entero($f->asignatura_id), self::entero($f->user_id)), $filas);
    }

    /**
     * Los grupos del alcance de las filas de alumnos y acudientes: grado → sus grupos del año,
     * clase → su grupo, todo NULL → todos los grupos del año.
     *
     * @return list<int>
     */
    public static function gruposDelAlcance(int $yearId, array $filas): array
    {
        $grupos = [];

        foreach ($filas as $f) {
            if (in_array($f['publico'], ['alumnos', 'acudientes_oficiales'], true)) {
                foreach (self::gruposDeFila($yearId, $f) as $g) {
                    $grupos[$g] = true;
                }
            }
        }

        return array_keys($grupos);
    }

    /** @return array<int, int> */
    private static function gruposDeFila(int $yearId, array $f): array
    {
        if ($f['asignatura_id'] !== null) {
            $filas = DB::select(
                'SELECT g.id FROM asignaturas a
                  INNER JOIN grupos g ON g.id = a.grupo_id AND g.year_id = ? AND g.deleted_at IS NULL
                  WHERE a.id = ? AND a.deleted_at IS NULL',
                [$yearId, $f['asignatura_id']]
            );
        } elseif ($f['grupo_id'] !== null) {
            $filas = DB::select('SELECT id FROM grupos WHERE id = ? AND year_id = ? AND deleted_at IS NULL',
                [$f['grupo_id'], $yearId]);
        } elseif ($f['grado_id'] !== null) {
            $filas = DB::select('SELECT id FROM grupos WHERE grado_id = ? AND year_id = ? AND deleted_at IS NULL',
                [$f['grado_id'], $yearId]);
        } else {
            $filas = DB::select('SELECT id FROM grupos WHERE year_id = ? AND deleted_at IS NULL', [$yearId]);
        }

        return array_map(fn ($r) => (int) $r->id, $filas);
    }

    /**
     * Las entradas de una actividad (ver la cabecera). `$act` necesita `year_id` y
     * `acudiente_por_hijo`; `$filas` por defecto son las guardadas. Con `$soloUser`, sólo las de esa
     * persona: es la pregunta «¿me toca?».
     *
     * @return list<array<string, mixed>>
     */
    public static function resolver(object $act, ?array $filas = null, ?int $soloUser = null): array
    {
        $filas ??= self::deActividad((int) $act->id);
        $yearId = (int) $act->year_id;
        $entradas = [];

        $deAlumnos = array_values(array_filter($filas, fn ($f) => $f['publico'] === 'alumnos'));
        $deAcudientes = array_values(array_filter($filas, fn ($f) => $f['publico'] === 'acudientes_oficiales'));
        $dePersonal = array_values(array_filter($filas, fn ($f) => in_array($f['publico'], ['personal', 'docentes', 'directivos'], true)));

        if ($deAlumnos !== []) {
            foreach (self::alumnos(self::gruposDelAlcance($yearId, $deAlumnos), $soloUser) as $e) {
                $entradas[$e['clave']] = $e;
            }
        }

        if ($deAcudientes !== []) {
            $porHijo = (bool) ($act->acudiente_por_hijo ?? true);

            foreach (self::acudientes(self::gruposDelAlcance($yearId, $deAcudientes), $porHijo, $soloUser) as $e) {
                $entradas[$e['clave']] = $e;
            }
        }

        if ($dePersonal !== []) {
            foreach (self::personal($dePersonal, $soloUser) as $e) {
                $entradas[$e['clave']] = $e;
            }
        }

        return array_values($entradas);
    }

    /** @return list<array<string, mixed>> */
    private static function alumnos(array $grupos, ?int $soloUser): array
    {
        if ($grupos === []) {
            return [];
        }

        $huecos = implode(',', array_fill(0, count($grupos), '?'));
        $params = $grupos;
        $filtro = '';

        if ($soloUser !== null) {
            $filtro = ' AND u.id = ?';
            $params[] = $soloUser;
        }

        // `MATR` y `ASIS`: los que están en clase (§2.7). Un alumno sin `users` —o con la cuenta
        // borrada— sale igual, con `user_id` NULL: cuenta como `sin_cuenta` y no puede responder.
        $filas = DB::select(
            "SELECT al.id AS alumno_id, u.id AS user_id, m.grupo_id, al.nombres, al.apellidos, i.nombre AS foto
               FROM matriculas m
              INNER JOIN alumnos al ON al.id = m.alumno_id AND al.deleted_at IS NULL
               LEFT JOIN users u ON u.id = al.user_id AND u.deleted_at IS NULL
               LEFT JOIN images i ON i.id = al.foto_id AND i.deleted_at IS NULL
              WHERE m.grupo_id IN ($huecos) AND m.estado IN ('MATR', 'ASIS') AND m.deleted_at IS NULL $filtro
              ORDER BY al.apellidos, al.nombres, m.id",
            $params
        );

        $entradas = [];

        foreach ($filas as $f) {
            $clave = 'alumno:'.$f->alumno_id;

            // Dos matrículas vivas en el mismo año: gana la primera, una sola entrada.
            $entradas[$clave] ??= [
                'clave' => $clave,
                'user_id' => self::entero($f->user_id),
                'alumno_id' => (int) $f->alumno_id,
                'publico' => 'alumno',
                'grupo_id' => (int) $f->grupo_id,
                'nombre' => self::nombre($f->nombres, $f->apellidos),
                'foto' => $f->foto,
                'hijo' => null,
            ];
        }

        return array_values($entradas);
    }

    /** @return list<array<string, mixed>> */
    private static function acudientes(array $grupos, bool $porHijo, ?int $soloUser): array
    {
        if ($grupos === []) {
            return [];
        }

        $huecos = implode(',', array_fill(0, count($grupos), '?'));
        $params = $grupos;
        $filtro = '';

        if ($soloUser !== null) {
            $filtro = ' AND u.id = ?';
            $params[] = $soloUser;
        }

        // Oficiales = `is_acudiente = 1`, y con cuenta: sin usuario no hay quien responda.
        $filas = DB::select(
            "SELECT u.id AS user_id, ac.nombres, ac.apellidos, i.nombre AS foto,
                    al.id AS alumno_id, al.nombres AS al_nombres, al.apellidos AS al_apellidos, m.grupo_id
               FROM parentescos p
              INNER JOIN acudientes ac ON ac.id = p.acudiente_id AND ac.deleted_at IS NULL AND ac.is_acudiente = 1
              INNER JOIN users u ON u.id = ac.user_id AND u.deleted_at IS NULL
              INNER JOIN alumnos al ON al.id = p.alumno_id AND al.deleted_at IS NULL
              INNER JOIN matriculas m ON m.alumno_id = p.alumno_id AND m.deleted_at IS NULL
                     AND m.estado IN ('MATR', 'ASIS') AND m.grupo_id IN ($huecos)
               LEFT JOIN images i ON i.id = ac.foto_id AND i.deleted_at IS NULL
              WHERE p.deleted_at IS NULL $filtro
              ORDER BY ac.apellidos, ac.nombres, al.apellidos, al.nombres",
            $params
        );

        $entradas = [];

        foreach ($filas as $f) {
            $clave = $porHijo ? 'acudiente:'.$f->user_id.':'.$f->alumno_id : 'acudiente:'.$f->user_id;

            $entradas[$clave] ??= [
                'clave' => $clave,
                'user_id' => (int) $f->user_id,
                'alumno_id' => $porHijo ? (int) $f->alumno_id : null,
                'publico' => 'acudiente',
                'grupo_id' => $porHijo ? (int) $f->grupo_id : null,
                'nombre' => self::nombre($f->nombres, $f->apellidos),
                'foto' => $f->foto,
                'hijo' => $porHijo ? self::nombre($f->al_nombres, $f->al_apellidos) : null,
            ];
        }

        return array_values($entradas);
    }

    /** @return list<array<string, mixed>> */
    private static function personal(array $filas, ?int $soloUser): array
    {
        $ids = [];

        foreach ($filas as $f) {
            if ($f['user_id'] !== null) {
                if ($soloUser === null || $soloUser === $f['user_id']) {
                    $ids[$f['user_id']] = true;
                }

                continue;
            }

            $tipos = $f['publico'] === 'docentes' ? ['Profesor'] : ['Profesor', 'Usuario'];
            $huecos = implode(',', array_fill(0, count($tipos), '?'));
            $params = $tipos;
            $extra = '';

            if ($f['publico'] === 'directivos') {
                // Un superusuario puede tener otro tipo; los directivos se miran uno a uno abajo.
                $extra = ' OR is_superuser = 1';
            }

            $sql = "SELECT id, is_superuser FROM users
                     WHERE deleted_at IS NULL AND is_active = 1 AND (tipo IN ($huecos) $extra)";

            if ($soloUser !== null) {
                $sql .= ' AND id = ?';
                $params[] = $soloUser;
            }

            foreach (DB::select($sql, $params) as $u) {
                if ($f['publico'] === 'directivos'
                    && ! Actividad::esDirectivo((object) ['user_id' => (int) $u->id, 'is_superuser' => (int) $u->is_superuser])) {
                    continue;
                }

                $ids[(int) $u->id] = true;
            }
        }

        if ($ids === []) {
            return [];
        }

        $lista = array_keys($ids);
        $huecos = implode(',', array_fill(0, count($lista), '?'));

        $gente = DB::select(
            "SELECT u.id, u.username, p.nombres, p.apellidos, COALESCE(ip.nombre, iu.nombre) AS foto
               FROM users u
               LEFT JOIN profesores p ON p.user_id = u.id AND p.deleted_at IS NULL
               LEFT JOIN images ip ON ip.id = p.foto_id AND ip.deleted_at IS NULL
               LEFT JOIN images iu ON iu.id = u.imagen_id AND iu.deleted_at IS NULL
              WHERE u.id IN ($huecos) AND u.deleted_at IS NULL
              ORDER BY p.apellidos, p.nombres, u.username",
            $lista
        );

        $entradas = [];

        foreach ($gente as $g) {
            $clave = 'personal:'.$g->id;

            $entradas[$clave] ??= [
                'clave' => $clave,
                'user_id' => (int) $g->id,
                'alumno_id' => null,
                'publico' => 'personal',
                'grupo_id' => null,
                'nombre' => $g->nombres !== null ? self::nombre($g->nombres, $g->apellidos) : (string) $g->username,
                'foto' => $g->foto,
                'hijo' => null,
            ];
        }

        return array_values($entradas);
    }

    /**
     * El conteo en vivo del selector (`POST act/conteo`) y la regla de aprobación (§2.3).
     *
     * `$act` necesita `year_id`, `acudiente_por_hijo`, `modo` y `responden`; `$creador` es el
     * contexto del token de quien crea.
     */
    public static function conteo(object $act, array $filas, object $creador): array
    {
        $entradas = self::resolver($act, $filas);
        $cuenta = ['alumno' => 0, 'acudiente' => 0, 'personal' => 0];
        $sinCuenta = 0;
        $porGrupo = [];

        foreach ($entradas as $e) {
            if ($e['user_id'] === null) {
                $sinCuenta++;

                continue;
            }

            $cuenta[$e['publico']]++;

            if ($e['grupo_id'] !== null) {
                $porGrupo[$e['grupo_id']] = ($porGrupo[$e['grupo_id']] ?? 0) + 1;
            }
        }

        $grupos = self::gruposDelAlcance((int) $act->year_id, $filas);
        $titularias = self::titularias($creador, (int) $act->year_id);
        $nombres = self::nombresDeGrupos($grupos);

        $lista = [];

        foreach ($grupos as $g) {
            $lista[] = [
                'grupo_id' => $g,
                'nombre' => $nombres[$g] ?? ('Grupo '.$g),
                'n' => $porGrupo[$g] ?? 0,
                'es_titularia' => in_array($g, $titularias, true),
            ];
        }

        usort($lista, fn ($a, $b) => strnatcasecmp($a['nombre'], $b['nombre']));

        [$requiere, $motivo] = self::requiereAprobacion($act, $filas, $creador);

        return [
            'total' => $cuenta['alumno'] + $cuenta['acudiente'] + $cuenta['personal'],
            'alumnos' => $cuenta['alumno'],
            'acudientes' => $cuenta['acudiente'],
            'personal' => $cuenta['personal'],
            'sin_cuenta' => $sinCuenta,
            'grupos' => $lista,
            'requiere_aprobacion' => $requiere,
            'motivo_aprobacion' => $motivo,
        ];
    }

    /**
     * §2.3, literal:
     *
     *     requiere = modo = 'encuesta' Y NO directivo(creador) Y (
     *          (responden ∈ {acudientes, ambos} Y algún grupo del alcance no es de su titularía)
     *       O  (responden ∈ {alumnos, ambos}    Y el alcance cubre más de 3 grupos distintos) )
     *
     * @return array{0: bool, 1: ?string} si requiere, y la frase que lo explica
     */
    public static function requiereAprobacion(object $act, array $filas, object $creador): array
    {
        if ($act->modo !== 'encuesta' || Actividad::esDirectivo($creador)) {
            return [false, null];
        }

        $yearId = (int) $act->year_id;
        $responden = (string) $act->responden;

        if (in_array($responden, ['acudientes', 'ambos'], true)) {
            $deAcudientes = array_values(array_filter($filas, fn ($f) => $f['publico'] === 'acudientes_oficiales'));
            $ajenos = array_values(array_diff(self::gruposDelAlcance($yearId, $deAcudientes), self::titularias($creador, $yearId)));

            if ($ajenos !== []) {
                $nombres = self::nombresDeGrupos($ajenos);
                $lista = implode(', ', array_map(fn ($g) => $nombres[$g] ?? (string) $g, array_slice($ajenos, 0, 3)));
                $mas = count($ajenos) > 3 ? ' y '.(count($ajenos) - 3).' más' : '';
                $verbo = count($ajenos) === 1 ? 'que no es tu titularía' : 'que no son tu titularía';

                return [true, "Va a acudientes de {$lista}{$mas}, {$verbo}."];
            }
        }

        if (in_array($responden, ['alumnos', 'ambos'], true)) {
            $deAlumnos = array_values(array_filter($filas, fn ($f) => $f['publico'] === 'alumnos'));
            $n = count(self::gruposDelAlcance($yearId, $deAlumnos));

            if ($n > 3) {
                return [true, "Va a alumnos de {$n} grupos; a más de 3 grupos necesita aprobación."];
            }
        }

        return [false, null];
    }

    /**
     * Los grupos del año de los que es titular. `grupos.titular_id` es un `profesores.id`: el del
     * Profesor o el enlazado a un Usuario (`DocenteDelUsuario`) — nunca el `persona_id` de un
     * `Usuario`, que es un `users.id` y daría la titularía de otra persona.
     *
     * @return array<int, int>
     */
    public static function titularias(object $user, int $yearId): array
    {
        $docenteId = DocenteDelUsuario::id($user);

        if ($docenteId === null) {
            return [];
        }

        return array_map(fn ($f) => (int) $f->id, DB::select(
            'SELECT id FROM grupos WHERE year_id = ? AND titular_id = ? AND deleted_at IS NULL',
            [$yearId, $docenteId]
        ));
    }

    /** @return array<int, string> */
    public static function nombresDeGrupos(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $huecos = implode(',', array_fill(0, count($ids), '?'));
        $nombres = [];

        foreach (DB::select("SELECT id, nombre FROM grupos WHERE id IN ($huecos)", array_values($ids)) as $g) {
            $nombres[(int) $g->id] = (string) $g->nombre;
        }

        return $nombres;
    }

    /**
     * Las actividades publicadas o cerradas del año que PODRÍAN tocarle a esta persona, por el
     * público de sus filas. Es sólo el primer cedazo: quién de verdad las recibe lo dice
     * `resolver()` con `$soloUser`.
     *
     * @return array<int, object>
     */
    public static function candidatasPara(object $user, int $yearId): array
    {
        $publicos = match ($user->tipo ?? '') {
            'Alumno' => ['alumnos'],
            'Acudiente' => ['acudientes_oficiales'],
            default => ['personal', 'docentes', 'directivos'],
        };

        $huecos = implode(',', array_fill(0, count($publicos), '?'));

        return DB::select(
            "SELECT a.* FROM ws_actividades a
              WHERE a.modo IS NOT NULL AND a.deleted_at IS NULL AND a.year_id = ?
                AND a.estado IN ('publicada', 'cerrada')
                AND EXISTS (SELECT 1 FROM destinatarios d
                             WHERE d.origen_tipo = 'actividad' AND d.origen_id = a.id AND d.publico IN ($huecos))
              ORDER BY COALESCE(a.cierra_at, '9999-12-31') ASC, a.id DESC",
            array_merge([$yearId], $publicos)
        );
    }

    /**
     * Quiénes ya respondieron, como claves para `respondio()`: la entrega entregada en la tarea (una
     * por alumno), la hoja terminada en lo demás (por `user_id` y alumno). Lo usan «faltan» y los
     * avisos que van sólo a quien falta; en `total` ninguno de los dos lo pregunta (§2.6).
     *
     * @return array<string, int>
     */
    public static function respondieron(object $act): array
    {
        if ($act->modo === 'tarea') {
            return array_flip(array_map(fn ($f) => 'alumno:'.$f->alumno_id, DB::select(
                'SELECT alumno_id FROM ws_entregas WHERE actividad_id = ? AND entregada_at IS NOT NULL', [$act->id])));
        }

        return array_flip(array_map(fn ($f) => $f->user_id.':'.($f->alumno_id ?? 0), DB::select(
            'SELECT DISTINCT user_id, alumno_id FROM ws_actividades_resueltas
              WHERE actividad_id = ? AND terminado = 1 AND deleted_at IS NULL', [$act->id])));
    }

    /** Si esta entrada ya respondió, con las claves de `respondieron()`. */
    public static function respondio(object $act, array $entrada, array $ya): bool
    {
        return $act->modo === 'tarea'
            ? isset($ya['alumno:'.$entrada['alumno_id']])
            : isset($ya[$entrada['user_id'].':'.($entrada['alumno_id'] ?? 0)]);
    }

    /** @return array{publico: string, grupo_id: ?int, grado_id: ?int, asignatura_id: ?int, user_id: ?int} */
    private static function fila(string $publico, ?int $grupo, ?int $grado, ?int $asignatura, ?int $user): array
    {
        return [
            'publico' => $publico,
            'grupo_id' => $grupo,
            'grado_id' => $grado,
            'asignatura_id' => $asignatura,
            'user_id' => $user,
        ];
    }

    private static function sinRepetir(array $filas): array
    {
        $unicas = [];

        foreach ($filas as $f) {
            $unicas[implode('|', array_map(fn ($v) => (string) $v, $f))] = $f;
        }

        return array_values($unicas);
    }

    public static function entero($v): ?int
    {
        return ($v === null || $v === '' || ! is_numeric($v)) ? null : (int) $v;
    }

    public static function nombre(?string $nombres, ?string $apellidos): string
    {
        return trim(trim((string) $nombres).' '.trim((string) $apellidos));
    }
}
