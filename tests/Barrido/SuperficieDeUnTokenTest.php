<?php

namespace Tests\Barrido;

use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Group;
use Tests\Contrato\CasoDeContrato;

/**
 * Qué alcanza de verdad un token, golpeando la API entera.
 *
 * **No es un test: es la herramienta de medición que encontró las §14 y §15**, y
 * vive aquí y no en `tools/` por una razón concreta — barrer las escrituras
 * significa ejecutarlas, y la única forma de hacerlo sin dañar nada es dentro de
 * la transacción que envuelve cada test. Un script en `tools/` tendría que
 * elegir entre no medir las escrituras o dejar la base tocada.
 *
 *     docker exec -e BARRIDO_TIPO=Alumno 8myvc-app-1 php artisan test --group=barrido
 *
 * Va en el grupo `barrido`, que `phpunit.xml` excluye: no corre con los demás
 * porque tarda y porque no afirma nada. **Imprime, no comprueba.** Lo que se
 * decide a partir de lo que imprime se fija después en `SuperficieDeUnAlumnoTest`,
 * que es donde viven los candados.
 *
 * Contesta las dos preguntas que ninguna otra herramienta contesta, y que son
 * distintas de la que contestan `inventario-autorizacion.py` y los candados de
 * `AutorizacionTest`. Aquéllas preguntan por la PETICIÓN —qué identificador
 * viaja, qué guard lo mira—; éstas preguntan por el RESULTADO:
 *
 *   1. **¿Qué sale?** Si en la respuesta aparece el dato personal de alguien.
 *   2. **¿Llegó a escribir?** No qué código respondió. En este proyecto se lee
 *      con `PUT`, así que un 200 no distingue una consulta de un `UPDATE`; lo
 *      que se mira son las consultas que la petición ejecutó de verdad.
 *
 * Las rutas se golpean con identificadores AJENOS a propósito: otro alumno, otro
 * grupo, un profesor, un superusuario. Un 403 es la respuesta correcta y no se
 * imprime; lo que se imprime es lo que pasó de largo.
 *
 * **Lo que sigue sin barrer** (20 ago 2026): se hizo con `BARRIDO_TIPO=Alumno`.
 * El acudiente tiene una superficie parecida y no idéntica —`persona.propia` le
 * acepta lo de sus acudidos— y nadie la ha medido con esto.
 */
#[Group('barrido')]
class SuperficieDeUnTokenTest extends CasoDeContrato
{
    /** Columnas que, si salen con valor, son el dato personal de alguien. */
    private const PERSONALES = [
        'num_doc', 'documento', 'telefono', 'celular', 'direccion', 'fecha_nac',
        'email', 'email_persona', 'email_restore', 'barrio', 'estado_civil',
    ];

    /**
     * Las tablas cuya escritura NO cuenta como hallazgo.
     *
     * `bitacoras` la escribe el propio guard al anotar un rechazo y
     * `personal_access_tokens` la escribe entrar: son la huella de la defensa,
     * no la del ataque. Sin descontarlas, cada 403 aparecería como escritura.
     */
    private const RUIDO = ['bitacoras', 'personal_access_tokens'];

    private string $token;

    private array $ajenos;

    /** Las consultas de escritura de la petición que se está midiendo. */
    private array $escrituras = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);

        $tipo = getenv('BARRIDO_TIPO') ?: 'Alumno';
        $quien = $this->usuarioDeTipo($tipo);
        $this->token = $this->tokenDe($quien->username);
        $this->ajenos = $this->identificadoresAjenosA($quien, $tipo);

        DB::listen(function ($q) {
            if (preg_match('/^\s*(insert|update|delete)\s/i', $q->sql) !== 1) {
                return;
            }

            preg_match('/^\s*(?:insert(?:\s+ignore)?\s+into|update|delete\s+from)\s+`?([a-z_]+)`?/i',
                $q->sql, $m);

            $tabla = $m[1] ?? '?';

            if (! in_array($tabla, self::RUIDO, true)) {
                $this->escrituras[] = strtolower(trim(explode(' ', trim($q->sql))[0])).' '.$tabla;
            }
        });

        echo "\nBarrido con token de {$tipo} (usuario {$quien->id}).\n"
            .'Identificadores usados, todos ajenos: '.json_encode($this->ajenos)."\n\n";
    }

    public function test_que_alcanza_este_token(): void
    {
        $encontrado = 0;
        $sinValor = [];

        foreach (Route::getRoutes()->getRoutes() as $ruta) {
            $verbo = $ruta->methods()[0];
            $uri = $ruta->uri();

            if (! str_starts_with($uri, 'api') || $verbo === 'HEAD') {
                continue;
            }

            $pedida = $this->rellenar($uri);

            if ($pedida === null) {
                $sinValor[] = $verbo.' '.$uri;

                continue;
            }

            $this->escrituras = [];

            try {
                $r = $this->withToken($this->token)->json($verbo, '/'.$pedida, []);
                $codigo = $r->getStatusCode();

                // Alguna de las rutas cierra la sesión —o le cambia la contraseña
                // al propio usuario—, y desde ahí todo respondería 401 y el
                // barrido dejaría de medir. Se vuelve a entrar y se repite.
                if ($codigo === 401) {
                    $this->token = $this->tokenDe($this->usuarioDeTipo(getenv('BARRIDO_TIPO') ?: 'Alumno')->username);
                    $this->escrituras = [];
                    $r = $this->withToken($this->token)->json($verbo, '/'.$pedida, []);
                    $codigo = $r->getStatusCode();
                }
            } catch (\Throwable $e) {
                echo '  EXCEPCIÓN   '.$verbo.' '.$uri.'   '.substr($e->getMessage(), 0, 90)."\n";

                continue;
            }

            // El 403 es la respuesta correcta: no se imprime.
            if ($codigo === 403) {
                continue;
            }

            $escribio = array_values(array_unique($this->escrituras));
            $personales = $escribio === [] && $codigo === 200
                ? $this->datosPersonalesEn((string) $r->getContent())
                : [];

            if ($escribio === [] && $personales === []) {
                continue;
            }

            $encontrado++;

            echo '  '.str_pad((string) $codigo, 5).str_pad($verbo, 7).str_pad($uri, 58)
                .($escribio !== [] ? '  ESCRIBE: '.implode(' | ', $escribio) : '')
                .($personales !== [] ? '  PERSONALES: '.implode(',', $personales)
                    .' ['.strlen((string) $r->getContent()).' b]' : '')
                ."\n";
        }

        echo "\n{$encontrado} rutas pasaron de largo con algo dentro.\n"
            ."Cada una hay que mirarla: muchas son lo suyo, y eso el barrido no lo sabe.\n";

        // La única comprobación que tiene sentido en un archivo que mide, y es
        // la que impide el fallo silencioso: si mañana alguien añade una ruta
        // con un parámetro que este mapa no conoce, el barrido la saltaría y
        // seguiría diciendo que todo está medido. Un barrido que se encoge sin
        // avisar es peor que no tenerlo.
        $this->assertSame([], $sinValor,
            "El mapa de identificadores no cubre estos parámetros, así que el barrido\n"
            .'los saltó sin medirlos. Añádelos en identificadoresAjenosA().');
    }

    /** Sustituye los parámetros de la URL, o devuelve null si no sabe con qué. */
    private function rellenar(string $uri): ?string
    {
        $pedida = strtr($uri, array_map('strval', $this->ajenos));
        $pedida = preg_replace('/\{[^}]*\?\}/', '', $pedida);
        $pedida = rtrim(str_replace('//', '/', $pedida), '/');

        return str_contains($pedida, '{') ? null : $pedida;
    }

    private function datosPersonalesEn(string $cuerpo): array
    {
        return array_values(array_filter(self::PERSONALES,
            fn ($col) => preg_match('/"'.$col.'"\s*:\s*"[^"]+"/', $cuerpo) === 1));
    }

    /**
     * Un valor para cada parámetro que usan las 539 rutas, y ninguno suyo.
     *
     * Que sean ajenos es la mitad del método: con los propios, un guard que
     * funcione y un guard que no mire nada responden lo mismo.
     */
    private function identificadoresAjenosA(object $quien, string $tipo): array
    {
        $mio = DB::selectOne('SELECT a.id, m.grupo_id, g.year_id FROM alumnos a
            INNER JOIN matriculas m ON m.alumno_id = a.id AND m.deleted_at IS NULL
            INNER JOIN grupos g ON g.id = m.grupo_id
            WHERE a.user_id = ? LIMIT 1', [$quien->id]);

        $grupoPropio = $mio->grupo_id ?? 0;
        $year = $mio->year_id ?? DB::selectOne('SELECT id FROM years ORDER BY id DESC LIMIT 1')->id;

        $grupo = DB::selectOne('SELECT id FROM grupos WHERE id <> ? AND titular_id IS NOT NULL
            AND deleted_at IS NULL ORDER BY id LIMIT 1', [$grupoPropio]);
        $alumno = DB::selectOne('SELECT id FROM alumnos WHERE id <> ? AND user_id IS NOT NULL
            AND deleted_at IS NULL ORDER BY id LIMIT 1', [$mio->id ?? 0]);
        $profesor = DB::selectOne('SELECT id FROM profesores WHERE deleted_at IS NULL ORDER BY id LIMIT 1');
        $asignatura = DB::selectOne('SELECT id FROM asignaturas WHERE grupo_id = ? AND deleted_at IS NULL
            ORDER BY id LIMIT 1', [$grupo->id ?? 0]);
        $periodo = DB::selectOne('SELECT id, numero FROM periodos WHERE year_id = ? AND deleted_at IS NULL
            ORDER BY numero LIMIT 1', [$year]);
        $imagen = DB::selectOne('SELECT id FROM images WHERE user_id IS NOT NULL AND user_id <> ?
            ORDER BY id LIMIT 1', [$quien->id]);
        $superusuario = DB::selectOne('SELECT id, username FROM users WHERE is_superuser = 1
            AND deleted_at IS NULL ORDER BY id LIMIT 1');
        $ciudad = DB::selectOne('SELECT id, departamento FROM ciudades WHERE departamento IS NOT NULL
            ORDER BY id LIMIT 1');
        $nota = DB::selectOne('SELECT id FROM notas ORDER BY id LIMIT 1');
        $votacion = DB::selectOne('SELECT id FROM vt_votaciones ORDER BY id LIMIT 1');
        $rol = DB::selectOne('SELECT id FROM roles ORDER BY id LIMIT 1');

        return [
            '{grupo_id}' => $grupo->id ?? 0, '{grupo_id?}' => $grupo->id ?? 0,
            '{alumno_id}' => $alumno->id ?? 0, '{alumno_id?}' => $alumno->id ?? 0,
            '{alumnoelegido}' => $alumno->id ?? 0,
            '{profesor_id}' => $profesor->id ?? 0, '{profe_id}' => $profesor->id ?? 0,
            '{profeelegido}' => $profesor->id ?? 0, '{persona_id?}' => $profesor->id ?? 0,
            '{usuarioelegido}' => $superusuario->id ?? 0, '{user_id}' => $superusuario->id ?? 0,
            '{user_id?}' => $superusuario->id ?? 0, '{user?}' => $superusuario->id ?? 0,
            '{username}' => $superusuario->username ?? 'x',
            '{asignatura_id}' => $asignatura->id ?? 0,
            '{periodo_id}' => $periodo->id ?? 0, '{periodo_a_calcular?}' => $periodo->numero ?? 1,
            '{imagen_id}' => $imagen->id ?? 0, '{id}' => $superusuario->id ?? 0,
            '{year_id}' => $year, '{year}' => $year,
            '{ciudad_id}' => $ciudad->id ?? 0, '{pais_id}' => 1,
            '{departamento}' => rawurlencode((string) ($ciudad->departamento ?? 'x')),
            '{nota_id}' => $nota->id ?? 0, '{votacion_id}' => $votacion->id ?? 0,
            '{role_id}' => $rol->id ?? 0, '{tiposdocumento}' => 1, '{tamanio}' => 10,
            '{frase_id?}' => 1,
        ];
    }
}
