<?php

namespace Tests\Contrato;

use App\Support\EscalaDeNotas;
use Carbon\Carbon;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;

/**
 * La base de los tests del módulo nuevo de actividades (`act/*`, `tests/Contrato/Act*Test.php`).
 *
 * Contrato: `myvc_front/docs/funciones/ACTIVIDADES-CONTRATO.md`. Cuando el contrato y el código difieren manda el
 * código, y cada test que lo note lo dice en su docblock.
 *
 * ## La escena
 *
 * Todo sale de UNA escena del seed, elegida por criterio y no por id (ver `escena()`): el grupo del
 * año actual con más alumnos, su titular (que da clase allí), un docente que da clase en el mismo
 * grupo **sin** ser titular de ningún grupo del año, un superusuario (el directivo), alguien del
 * personal que no es directivo, los alumnos del grupo con cuenta y un acudiente oficial de uno de
 * ellos. Lo demás —actividades, preguntas, hojas, grupos de relleno— lo crea cada test dentro de su
 * transacción.
 *
 * **El año no se elige**: `Services\Login` pone a todo el que entra en el periodo actual, así que la
 * escena se ancla al año con `years.actual = 1` y no al grupo más poblado a secas (ver
 * `CasoDeContrato::tokenDelPersonalDe`).
 *
 * ## Lo que no deshace la transacción
 *
 * - Los ficheros de las entregas (`storage/app/actividades/{año}/{actividad}/`): se borran en
 *   `tearDown` las carpetas de las actividades que el test creó.
 * - `Carbon::setTestNow()` y la memoria de `EscalaDeNotas`: se sueltan en `tearDown`.
 */
abstract class CasoDeActividades extends CasoDeContrato
{
    /** @var array<string, string> username → token, para no entrar dos veces con el mismo en un test */
    private array $tokens = [];

    private ?object $escena = null;

    /** @var list<int> actividades creadas por el test, para limpiar sus ficheros */
    private array $creadas = [];

    /**
     * Sin el `throttle:login`: un test de actividades entra con seis o siete personas (docente,
     * directivo, alumnos, acudiente) y el limitador corta en 5 por minuto y por IP. Lo que aquí se
     * mide es `act/*`, que no lleva `throttle`; el del login lo vigilan sus propios tests.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->creadas as $id) {
            foreach (glob(storage_path('app/actividades/*/'.$id), GLOB_ONLYDIR) ?: [] as $carpeta) {
                File::deleteDirectory($carpeta);
            }
        }

        Carbon::setTestNow();
        EscalaDeNotas::olvidar();

        parent::tearDown();
    }

    // ------------------------------------------------------------------ la escena

    /**
     * La gente del seed con la que se monta todo. Ver la cabecera.
     *
     * Trae `year_id`, `periodo_id`, `grupo_id`, `grado_id`; `clase` y `clase2` (dos asignaturas del
     * titular en su grupo; la primera con `unidad_id`, un logro del periodo); `titular`, `ajeno` (con
     * su `clase_ajeno`), `directivo` y `llano` (filas de `users`); `alumnos` (con `alumno_id`,
     * `user_id`, `username`) y `acudiente` (con `user_id`, `acudiente_id` y el `alumno_id` del hijo).
     */
    protected function escena(): object
    {
        if ($this->escena !== null) {
            return $this->escena;
        }

        $year = DB::selectOne('SELECT id FROM years WHERE actual = 1 AND deleted_at IS NULL ORDER BY id DESC LIMIT 1');
        $this->assertNotNull($year, 'El seed no tiene año actual.');
        $yearId = (int) $year->id;

        $periodo = DB::selectOne('SELECT id FROM periodos WHERE year_id = ? AND actual = 1 AND deleted_at IS NULL ORDER BY id LIMIT 1', [$yearId]);
        $this->assertNotNull($periodo, 'El año actual del seed no tiene periodo actual.');

        $grupo = DB::selectOne(
            "SELECT g.id, g.grado_id, g.titular_id FROM grupos g
              INNER JOIN matriculas m ON m.grupo_id = g.id AND m.deleted_at IS NULL AND m.estado IN ('MATR', 'ASIS')
              WHERE g.year_id = ? AND g.deleted_at IS NULL AND g.titular_id IS NOT NULL
              GROUP BY g.id, g.grado_id, g.titular_id ORDER BY COUNT(m.id) DESC, g.id LIMIT 1",
            [$yearId]
        );
        $this->assertNotNull($grupo, 'El año actual del seed no tiene un grupo con alumnos y titular.');

        $titular = DB::selectOne(
            "SELECT u.id, u.username, pr.id AS profesor_id FROM profesores pr
              INNER JOIN users u ON u.id = pr.user_id AND u.is_active = 1 AND u.deleted_at IS NULL AND u.tipo = 'Profesor'
              WHERE pr.id = ? AND pr.deleted_at IS NULL",
            [$grupo->titular_id]
        );
        $this->assertNotNull($titular, 'El titular del grupo no tiene cuenta de Profesor.');

        // Dos clases del titular en su grupo, la primera con un logro en el periodo actual.
        $clases = DB::select(
            'SELECT a.id FROM asignaturas a
              WHERE a.grupo_id = ? AND a.profesor_id = ? AND a.deleted_at IS NULL
              ORDER BY (SELECT COUNT(*) FROM unidades u WHERE u.asignatura_id = a.id AND u.periodo_id = ?
                         AND u.alumno_id IS NULL AND u.deleted_at IS NULL) = 0, a.id',
            [$grupo->id, $titular->profesor_id, $periodo->id]
        );
        $this->assertGreaterThanOrEqual(2, count($clases), 'El titular necesita dos clases en su grupo para duplicar.');

        $unidad = DB::selectOne(
            'SELECT id FROM unidades WHERE asignatura_id = ? AND periodo_id = ? AND alumno_id IS NULL AND deleted_at IS NULL
              ORDER BY orden, id LIMIT 1',
            [$clases[0]->id, $periodo->id]
        );
        $this->assertNotNull($unidad, 'La clase del titular no tiene logros en el periodo actual.');

        // Un docente del mismo grupo que no es titular de ningún grupo del año: el «ajeno».
        $ajeno = DB::selectOne(
            "SELECT u.id, u.username, pr.id AS profesor_id, a.id AS asignatura_id FROM asignaturas a
              INNER JOIN profesores pr ON pr.id = a.profesor_id AND pr.deleted_at IS NULL
              INNER JOIN users u ON u.id = pr.user_id AND u.is_active = 1 AND u.deleted_at IS NULL AND u.tipo = 'Profesor'
              WHERE a.grupo_id = ? AND a.profesor_id <> ? AND a.deleted_at IS NULL
                AND NOT EXISTS (SELECT 1 FROM grupos g2 WHERE g2.year_id = ? AND g2.titular_id = pr.id AND g2.deleted_at IS NULL)
              ORDER BY a.id LIMIT 1",
            [$grupo->id, $titular->profesor_id, $yearId]
        );
        $this->assertNotNull($ajeno, 'El grupo no tiene un docente que no sea titular.');

        $directivo = DB::selectOne(
            "SELECT id, username FROM users WHERE is_superuser = 1 AND tipo = 'Usuario' AND is_active = 1 AND deleted_at IS NULL
              ORDER BY id LIMIT 1"
        );
        $this->assertNotNull($directivo, 'El seed no tiene superusuario.');

        $llano = $this->usuarioLlanoDelPersonal();

        $alumnos = DB::select(
            "SELECT al.id AS alumno_id, u.id AS user_id, u.username, al.nombres, al.apellidos FROM matriculas m
              INNER JOIN alumnos al ON al.id = m.alumno_id AND al.deleted_at IS NULL
              INNER JOIN users u ON u.id = al.user_id AND u.is_active = 1 AND u.deleted_at IS NULL AND u.tipo = 'Alumno'
              WHERE m.grupo_id = ? AND m.estado IN ('MATR', 'ASIS') AND m.deleted_at IS NULL
              ORDER BY al.id",
            [$grupo->id]
        );
        $this->assertGreaterThanOrEqual(8, count($alumnos), 'El grupo necesita al menos 8 alumnos con cuenta.');

        $acudiente = DB::selectOne(
            "SELECT u.id AS user_id, u.username, ac.id AS acudiente_id, p.alumno_id FROM parentescos p
              INNER JOIN acudientes ac ON ac.id = p.acudiente_id AND ac.deleted_at IS NULL AND ac.is_acudiente = 1
              INNER JOIN users u ON u.id = ac.user_id AND u.is_active = 1 AND u.deleted_at IS NULL AND u.tipo = 'Acudiente'
              INNER JOIN matriculas m ON m.alumno_id = p.alumno_id AND m.grupo_id = ? AND m.estado IN ('MATR', 'ASIS') AND m.deleted_at IS NULL
              WHERE p.deleted_at IS NULL ORDER BY u.id LIMIT 1",
            [$grupo->id]
        );
        $this->assertNotNull($acudiente, 'Ningún alumno del grupo tiene un acudiente oficial con cuenta.');

        return $this->escena = (object) [
            'year_id' => $yearId,
            'periodo_id' => (int) $periodo->id,
            'grupo_id' => (int) $grupo->id,
            'grado_id' => (int) $grupo->grado_id,
            'clase' => (int) $clases[0]->id,
            'clase2' => (int) $clases[1]->id,
            'unidad_id' => (int) $unidad->id,
            'titular' => $titular,
            'ajeno' => $ajeno,
            'clase_ajeno' => (int) $ajeno->asignatura_id,
            'directivo' => $directivo,
            'llano' => $llano,
            'alumnos' => $alumnos,
            'acudiente' => $acudiente,
        ];
    }

    /** El alumno número `$i` del grupo (por id de alumno). */
    protected function alumno(int $i = 0): object
    {
        return $this->escena()->alumnos[$i];
    }

    // ------------------------------------------------------------------ peticiones

    /** El token de alguien, entrando una sola vez por test. */
    protected function token(string $username): string
    {
        return $this->tokens[$username] ??= $this->tokenDe($username);
    }

    /** `$this->withToken(...)` con la persona: `titular`, `ajeno`, `directivo`, `llano`, `acudiente`, o un username. */
    protected function como(string|object $quien): static
    {
        return $this->withToken($this->token($this->username($quien)));
    }

    private function username(string|object $quien): string
    {
        if (is_object($quien)) {
            return $quien->username;
        }

        $e = $this->escena();

        return match ($quien) {
            'titular' => $e->titular->username,
            'ajeno' => $e->ajeno->username,
            'directivo' => $e->directivo->username,
            'llano' => $e->llano->username,
            'acudiente' => $e->acudiente->username,
            default => $quien,
        };
    }

    // ------------------------------------------------------------------ montar actividades

    /** La config de una encuesta al grupo de la escena, respondida por sus alumnos. */
    protected function encuesta(array $extra = []): array
    {
        return array_replace([
            'modo' => 'encuesta',
            'titulo' => 'Encuesta de prueba',
            'alcance' => 'grupo',
            'grupo_id' => $this->escena()->grupo_id,
            'responden' => 'alumnos',
            'anonimato' => 'nombre',
            'destinatarios' => [],
        ], $extra);
    }

    /** La config de un cuestionario en la clase del titular. */
    protected function cuestionario(array $extra = []): array
    {
        return array_replace([
            'modo' => 'cuestionario',
            'titulo' => 'Cuestionario de prueba',
            'alcance' => 'clase',
            'asignatura_id' => $this->escena()->clase,
            'responden' => 'alumnos',
            'mostrar_correctas' => 'al_enviar',
        ], $extra);
    }

    /** La config de una tarea en la clase del titular, que pide texto. */
    protected function tarea(array $extra = []): array
    {
        return array_replace([
            'modo' => 'tarea',
            'titulo' => 'Tarea de prueba',
            'alcance' => 'clase',
            'asignatura_id' => $this->escena()->clase,
            'responden' => 'alumnos',
            'entrega' => ['texto' => true, 'foto' => false, 'archivo' => false, 'enlace' => false],
        ], $extra);
    }

    /** `POST act/crear`, exigiendo 200. @return array ActEditable */
    protected function crear(array $config, string|object $quien = 'titular'): array
    {
        $r = $this->como($quien)->postJson('/api/act/crear', $config);
        $r->assertStatus(200);
        $this->creadas[] = (int) $r->json('id');

        return $r->json();
    }

    /** `POST act/{id}/preguntas`, exigiendo 200. @return array PreguntaAct */
    protected function pregunta(int $actId, array $pregunta, string|object $quien = 'titular'): array
    {
        $r = $this->como($quien)->postJson("/api/act/{$actId}/preguntas", array_replace([
            'tipo' => 'unica',
            'enunciado' => 'Pregunta de prueba',
            'puntos' => 1,
        ], $pregunta));
        $r->assertStatus(200);

        return $r->json();
    }

    /**
     * Una de opción única con opciones `A`, `B`, `C`; la correcta es la de `$correcta` (0..2), o
     * ninguna con `null`.
     *
     * @return array PreguntaAct
     */
    protected function unica(int $actId, ?int $correcta = 0, array $extra = [], string|object $quien = 'titular'): array
    {
        $opciones = [];

        foreach (['A', 'B', 'C'] as $i => $letra) {
            $opciones[] = ['definicion' => $letra, 'is_correct' => $i === $correcta, 'image_id' => null];
        }

        return $this->pregunta($actId, array_replace(['tipo' => 'unica', 'opciones' => $opciones], $extra), $quien);
    }

    /** `POST act/{id}/publicar`, exigiendo 200. */
    protected function publicar(int $actId, array $cuerpo = [], string|object $quien = 'titular'): array
    {
        $r = $this->como($quien)->postJson("/api/act/{$actId}/publicar", $cuerpo);
        $r->assertStatus(200);

        return $r->json();
    }

    /** Una encuesta al grupo con una pregunta única, ya publicada. @return array{0: int, 1: array} id y pregunta */
    protected function encuestaPublicada(array $extra = [], string|object $quien = 'titular'): array
    {
        $act = $this->crear($this->encuesta($extra), $quien);
        $p = $this->unica($act['id'], null, [], $quien);
        $this->publicar($act['id'], [], $quien);

        return [$act['id'], $p];
    }

    /** `POST act/{id}/enviar` como el alumno `$alumno`, con las respuestas dadas. */
    protected function enviar(int $actId, object $alumno, array $respuestas): TestResponse
    {
        return $this->como($alumno)->postJson("/api/act/{$actId}/enviar", ['respuestas' => $respuestas]);
    }

    /** La respuesta a una pregunta de opciones, marcando las opciones de índices `$indices`. */
    protected function marcar(array $pregunta, int ...$indices): array
    {
        return [
            'pregunta_id' => $pregunta['id'],
            'opcion_ids' => array_map(fn ($i) => $pregunta['opciones'][$i]['id'], $indices),
        ];
    }

    /**
     * Una hoja terminada escrita directamente, para los recuentos que necesitan muchas (k-anonimato).
     * Es la misma fila que deja `enviar`, sin respuestas.
     */
    protected function hojaTerminada(int $actId, object $alumno, array $extra = []): int
    {
        $ahora = now('America/Bogota')->format('Y-m-d H:i:s');

        return (int) DB::table('ws_actividades_resueltas')->insertGetId(array_replace([
            'actividad_id' => $actId,
            'user_id' => $alumno->user_id,
            'alumno_id' => $alumno->alumno_id,
            'publico' => 'alumno',
            'grupo_id' => $this->escena()->grupo_id,
            'terminado' => 1,
            'intento' => 1,
            'iniciada_at' => $ahora,
            'enviada_at' => $ahora,
            'created_at' => $ahora,
            'updated_at' => $ahora,
        ], $extra));
    }

    /** La fila de la actividad, cruda. */
    protected function fila(int $actId): object
    {
        return DB::selectOne('SELECT * FROM ws_actividades WHERE id = ?', [$actId]);
    }

    /** Los avisos encolados de una actividad, de una clase. @return list<object> */
    protected function avisos(int $actId, ?string $clase = null): array
    {
        return DB::select(
            'SELECT * FROM ws_avisos WHERE actividad_id = ?'.($clase ? ' AND clase = ?' : '').' ORDER BY id',
            $clase ? [$actId, $clase] : [$actId]
        );
    }

    /**
     * `$n` grupos más en el año de la escena, sin alumnos: para los alcances de «más de 3 grupos».
     *
     * @return list<int>
     */
    protected function gruposDeRelleno(int $n): array
    {
        $ids = [];

        for ($i = 0; $i < $n; $i++) {
            $ids[] = $this->grupoAjenoDelMismoAnio($this->escena()->year_id)->grupo_id;
        }

        return $ids;
    }

    /** Le da un rol del colegio a un usuario (se deshace con la transacción). */
    protected function darRol(int $userId, string $rol): void
    {
        $rolId = DB::table('roles')->where('name', $rol)->value('id');
        $this->assertNotNull($rolId, "El seed no tiene el rol '{$rol}'.");

        DB::table('role_user')->insert(['user_id' => $userId, 'role_id' => $rolId]);
    }
}
