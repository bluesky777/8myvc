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
 * **Barrido con `Alumno` y con `Acudiente`** (20 ago 2026). El acudiente no
 * encontró ningún agujero nuevo: alcanza dos rutas más que el alumno
 * —`acudientes/mis-acudidos` y `ChangesAsked/to-me`— y las dos le devuelven lo
 * de su acudido, que es la regla.
 *
 * **Lo que la segunda pasada sí encontró fue en el barrido mismo**, y por eso
 * hay que leer esto antes de fiarse de un resultado suyo:
 *
 *   - **Imprimía menos de lo que contaba.** Una respuesta de archivo vacía el
 *     buffer de salida al enviarse, y con él las líneas ya escritas: decía «17
 *     rutas» y enseñaba once. Ahora acumula y vuelca al final.
 *   - **Pedía en el año equivocado.** `Services\Login` reescribe
 *     `users.periodo_id` al periodo del año actual, así que el año solo se sabe
 *     después de entrar. Con el de antes, media API contestaba vacío.
 *   - **36 rutas no se estaban midiendo** —boletines, planillas, observador,
 *     certificados de otro grupo— porque el seed tiene dos grupos y el sujeto de
 *     siempre está matriculado en los dos: no había ningún grupo ajeno y se
 *     pedían con un cero. Ahora se elige un sujeto que sí deje uno libre, y las
 *     36 salieron cerradas. Para el acudiente no hay sujeto posible, y el
 *     barrido lo dice al final en vez de callárselo.
 *
 * Y una cosa que este archivo **no** puede encontrar, demostrada el mismo día:
 * lo que sale sin ser dato personal. `unidades/trashed` devolvía a un alumno la
 * papelera académica del colegio y el barrido la vio pasar, porque su criterio
 * de fuga es la lista `PERSONALES` de arriba. Ver 05 §16.
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

    /**
     * El cuerpo que se manda en cada petición, con los mismos valores ajenos.
     *
     * **Golpear con el cuerpo vacío era la mitad del barrido que faltaba.** Este
     * proyecto pide por el cuerpo tanto como por la URL, y una ruta que saca su
     * alcance de ahí —`promovidos/calcular-grupo` con su `grupo_id`,
     * `cartera/alumnos` con su `grupo_actual`— no llegaba a tocar a nadie: el
     * controlador entraba, no encontraba a quién, y devolvía vacío sin escribir.
     * El barrido lo leía como «esta ruta no alcanza nada». Ver 05 §17.
     *
     * Se mandan todas las claves a la vez, no una a una: una ruta lee la que
     * conoce y las demás le dan igual. Lo que cuesta es que una que lea DOS
     * recibe una combinación que puede no casar —el alumno ajeno con el grupo
     * ajeno de otro año—, y entonces el vacío vuelve a no probar nada. Es el
     * mismo límite del mapa de la URL y se acepta por lo mismo: la alternativa
     * es una petición por combinación, y son 539 rutas.
     */
    private array $cuerpo = [];

    /**
     * Los parámetros para los que el seed NO tiene ningún valor ajeno.
     *
     * Las rutas que los llevan se golpean igual, pero con un cero: contestan
     * vacío, y un vacío no distingue un guard que funciona de uno que no mira
     * nada. **No están medidas, y el barrido tiene que decirlo** — es el mismo
     * motivo por el que el `assertSame` del final existe.
     */
    private array $sinAjeno = [];

    /** Las consultas de escritura de la petición que se está midiendo. */
    private array $escrituras = [];

    /**
     * Lo que el barrido va a imprimir, acumulado hasta el final.
     *
     * No es un capricho de estilo: **imprimir sobre la marcha perdía hallazgos**.
     * Entre las 539 rutas hay descargas, y una respuesta de archivo de Symfony
     * vacía el buffer de salida al enviarse; con ella se iban las líneas que ya
     * se habían escrito. El barrido decía «17 rutas» y enseñaba once, y las seis
     * que faltaban eran justo las de antes de la primera descarga — las de las
     * rutas que se golpean primero. Se guarda y se vuelca al terminar, que es
     * después de la última respuesta.
     */
    private array $salida = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);

        $tipo = getenv('BARRIDO_TIPO') ?: 'Alumno';
        $quien = $this->sujetoDeBarrido($tipo);

        // El login va ANTES de elegir los identificadores, y no es indiferente:
        // `Services\Login` reescribe `users.periodo_id` al periodo del año
        // actual, así que el año del contexto solo se sabe después de entrar.
        // Con la fila leída antes, el barrido pedía en un año en el que el token
        // no está y media API contestaba vacía sin que nada fallara.
        $this->token = $this->tokenDe($quien->username);
        $this->ajenos = $this->identificadoresAjenosA($quien, $tipo);
        $this->cuerpo = $this->cuerpoPlausible();

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

        $this->salida[] = "Barrido con token de {$tipo} (usuario {$quien->id}).";
        $this->salida[] = 'Identificadores usados, todos ajenos: '.json_encode($this->ajenos);
        $this->salida[] = '';
    }

    public function test_que_alcanza_este_token(): void
    {
        $encontrado = 0;
        $sinValor = [];
        $sinMedir = [];

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

            foreach ($this->sinAjeno as $parametro) {
                if (str_contains($uri, $parametro)) {
                    $sinMedir[] = $verbo.' '.$uri.'   ('.$parametro.')';

                    break;
                }
            }

            $this->escrituras = [];

            try {
                $r = $this->withToken($this->token)->json($verbo, '/'.$pedida, $this->cuerpo);
                $codigo = $r->getStatusCode();

                // Alguna de las rutas cierra la sesión —o le cambia la contraseña
                // al propio usuario—, y desde ahí todo respondería 401 y el
                // barrido dejaría de medir. Se vuelve a entrar y se repite.
                if ($codigo === 401) {
                    $this->token = $this->tokenDe($this->sujetoDeBarrido(getenv('BARRIDO_TIPO') ?: 'Alumno')->username);
                    $this->escrituras = [];
                    $r = $this->withToken($this->token)->json($verbo, '/'.$pedida, $this->cuerpo);
                    $codigo = $r->getStatusCode();
                }
            } catch (\Throwable $e) {
                $this->salida[] = '  EXCEPCIÓN   '.$verbo.' '.$uri.'   '.substr($e->getMessage(), 0, 90);

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

            $this->salida[] = '  '.str_pad((string) $codigo, 5).str_pad($verbo, 7).str_pad($uri, 58)
                .($escribio !== [] ? '  ESCRIBE: '.implode(' | ', $escribio) : '')
                .($personales !== [] ? '  PERSONALES: '.implode(',', $personales)
                    .' ['.strlen((string) $r->getContent()).' b]' : '');
        }

        $this->salida[] = '';
        $this->salida[] = "{$encontrado} rutas pasaron de largo con algo dentro.";
        $this->salida[] = 'Cada una hay que mirarla: muchas son lo suyo, y eso el barrido no lo sabe.';

        if ($sinMedir !== []) {
            $this->salida[] = '';
            $this->salida[] = count($sinMedir).' rutas NO se midieron: el seed no tiene ningún valor '
                .'ajeno para '.implode(', ', $this->sinAjeno).'.';
            $this->salida[] = 'Se golpearon con un cero, así que su respuesta vacía no prueba nada.';

            foreach ($sinMedir as $ruta) {
                $this->salida[] = '  '.$ruta;
            }
        }

        echo "\n".implode("\n", $this->salida)."\n";

        // La única comprobación que tiene sentido en un archivo que mide, y es
        // la que impide el fallo silencioso: si mañana alguien añade una ruta
        // con un parámetro que este mapa no conoce, el barrido la saltaría y
        // seguiría diciendo que todo está medido. Un barrido que se encoge sin
        // avisar es peor que no tenerlo.
        $this->assertSame([], $sinValor,
            "El mapa de identificadores no cubre estos parámetros, así que el barrido\n"
            .'los saltó sin medirlos. Añádelos en identificadoresAjenosA().');
    }

    /**
     * A quién se le da el token, que no es «el primero de su tipo» por una razón.
     *
     * El seed tiene **dos** grupos y 56 de sus 68 alumnos están matriculados en
     * los dos —el del año pasado y el de éste—. Para uno de ésos no existe
     * ningún grupo ajeno, así que el barrido pedía `grupo_id=0` y 36 rutas
     * —boletines, planillas, observador, certificados de OTRO grupo— contestaban
     * vacío sin haber medido nada. Es el agujero más grande que tenía la medida
     * de agosto y no se veía, porque una respuesta vacía se parece a una
     * respuesta que no filtra.
     *
     * Se prefiere un alumno matriculado en UN solo grupo, y que ese grupo sea
     * del año actual: el login reescribe su periodo al del año actual, y un
     * alumno cuyo único grupo fuera de otro año se quedaría sin contexto y
     * contestaría 400 en toda la API.
     *
     * Para el acudiente no hay elección posible —ninguno del seed tiene a sus
     * acudidos en un solo grupo— y por eso se cae al de siempre. El barrido lo
     * dice al final en vez de callárselo.
     */
    private function sujetoDeBarrido(string $tipo): object
    {
        if ($tipo !== 'Alumno') {
            return $this->usuarioDeTipo($tipo);
        }

        // Los grupos se cuentan SIN mirar el estado de la matrícula: una
        // matrícula retirada sigue siendo suya, y un alumno con la de este año
        // en un grupo y una vieja en el otro no deja ningún grupo ajeno. Ése fue
        // el primer sujeto que se eligió y seguía dando `grupo_id=0`.
        $elegido = DB::selectOne('SELECT u.id FROM users u
            INNER JOIN alumnos a ON a.user_id = u.id AND a.deleted_at IS NULL
            INNER JOIN periodos p ON p.id = u.periodo_id
            INNER JOIN (SELECT alumno_id, MIN(grupo_id) AS grupo_id, COUNT(DISTINCT grupo_id) AS grupos
                        FROM matriculas WHERE deleted_at IS NULL GROUP BY alumno_id) sus
                ON sus.alumno_id = a.id AND sus.grupos = 1
            INNER JOIN grupos g ON g.id = sus.grupo_id AND g.deleted_at IS NULL
            INNER JOIN years y ON y.id = g.year_id AND y.actual = 1 AND y.deleted_at IS NULL
            WHERE u.tipo = "Alumno" AND u.is_active = 1 AND u.deleted_at IS NULL
              AND p.year_id = g.year_id
              AND EXISTS (SELECT 1 FROM matriculas m WHERE m.alumno_id = a.id
                          AND m.deleted_at IS NULL AND m.estado IN ("MATR", "ASIS", "PREM"))
            ORDER BY u.id LIMIT 1');

        return $elegido === null
            ? $this->usuarioDeTipo($tipo)
            : DB::selectOne('SELECT * FROM users WHERE id = ?', [$elegido->id]);
    }

    /**
     * Los mismos valores ajenos, con los nombres que usan los cuerpos.
     *
     * Los nombres NO son los de la URL: un `{grupo_id}` de la URL se llama
     * `grupo_actual` en la cartera y `grupo_id` en la promoción, y esa diferencia
     * es justo la que dejó las dos sin medir. La lista se amplía cuando aparezca
     * un nombre nuevo, igual que el mapa de la URL — y como aquél, lo que no
     * cubre lo salta en silencio, con la diferencia de que aquí no hay forma
     * estática de saber qué claves lee un controlador.
     *
     * `texto_a_buscar` va con una sola letra a propósito: es lo que enseñó que
     * los buscadores devolvían 49 compañeros.
     */
    private function cuerpoPlausible(): array
    {
        $de = fn (string $clave) => $this->ajenos[$clave] ?? 0;

        return [
            'alumno_id' => $de('{alumno_id}'),
            'grupo_id' => $de('{grupo_id}'),
            'grupo_actual' => $de('{grupo_id}'),
            'profesor_id' => $de('{profesor_id}'),
            'persona_id' => $de('{profesor_id}'),
            'user_id' => $de('{user_id}'),
            'usuario_id' => $de('{user_id}'),
            'acudiente_id' => $de('{persona_id?}'),
            'asignatura_id' => $de('{asignatura_id}'),
            'periodo_id' => $de('{periodo_id}'),
            'num_periodo' => $this->ajenos['{periodo_a_calcular?}'] ?? 1,
            'year_id' => $de('{year_id}'),
            'year' => $de('{year}'),
            'imagen_id' => $de('{imagen_id}'),
            'img_id' => $de('{imagen_id}'),
            'nota_id' => $de('{nota_id}'),
            'votacion_id' => $de('{votacion_id}'),
            'role_id' => $de('{role_id}'),
            'ciudad_id' => $de('{ciudad_id}'),
            'texto_a_buscar' => 'a',
        ];
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
     *
     * **Y «suyo» no significa lo mismo para cada tipo**, que es lo que obligó a
     * partir esto en dos. Lo suyo de un alumno es su ficha y su grupo; lo de un
     * acudiente es su ficha MÁS la de cada acudido, su cuenta y las de ellos, y
     * los grupos de ellos — `persona.propia` se lo acepta todo. Elegir los
     * identificadores con el criterio del alumno —«el primer alumno que no sea
     * yo»— podía dar justo el acudido, y entonces el 403 que no llega no prueba
     * nada: el guard estaría funcionando.
     */
    private function identificadoresAjenosA(object $quien, string $tipo): array
    {
        $suyo = $this->loSuyoDe($quien, $tipo);

        // Los ids salen de la base, no de la petición: se interpolan porque
        // `IN (?)` no admite lista. La lista vacía se escribe como `(0)`, que
        // no excluye nada y es lo que se quiere.
        $fuera = static fn (array $ids) => implode(',', $ids === [] ? [0] : $ids);

        $year = $suyo['year'];

        $grupo = DB::selectOne('SELECT id FROM grupos WHERE id NOT IN ('.$fuera($suyo['grupos']).')
            AND titular_id IS NOT NULL AND deleted_at IS NULL ORDER BY id LIMIT 1');
        $alumno = DB::selectOne('SELECT id FROM alumnos WHERE id NOT IN ('.$fuera($suyo['alumnos']).')
            AND user_id IS NOT NULL AND deleted_at IS NULL ORDER BY id LIMIT 1');
        // El profesor hace de `persona_id` ajeno, así que no vale cualquiera: si
        // su id coincidiera con el de un acudido, el guard lo daría por suyo por
        // el número y no por la tabla. Es el cruce de numeraciones de la §11.
        $profesor = DB::selectOne('SELECT id FROM profesores WHERE deleted_at IS NULL
            AND id NOT IN ('.$fuera($suyo['personas']).') ORDER BY id LIMIT 1');
        $asignatura = DB::selectOne('SELECT id FROM asignaturas WHERE grupo_id = ? AND deleted_at IS NULL
            ORDER BY id LIMIT 1', [$grupo->id ?? 0]);
        $periodo = DB::selectOne('SELECT id, numero FROM periodos WHERE year_id = ? AND deleted_at IS NULL
            ORDER BY numero LIMIT 1', [$year]);
        $imagen = DB::selectOne('SELECT id FROM images WHERE user_id IS NOT NULL
            AND user_id NOT IN ('.$fuera($suyo['usuarios']).') ORDER BY id LIMIT 1');
        $superusuario = DB::selectOne('SELECT id, username FROM users WHERE is_superuser = 1
            AND deleted_at IS NULL AND id NOT IN ('.$fuera($suyo['usuarios']).') ORDER BY id LIMIT 1');
        $ciudad = DB::selectOne('SELECT id, departamento FROM ciudades WHERE departamento IS NOT NULL
            ORDER BY id LIMIT 1');
        $nota = DB::selectOne('SELECT id FROM notas ORDER BY id LIMIT 1');
        $votacion = DB::selectOne('SELECT id FROM vt_votaciones ORDER BY id LIMIT 1');
        $rol = DB::selectOne('SELECT id FROM roles ORDER BY id LIMIT 1');

        foreach ([
            '{grupo_id}' => $grupo, '{grupo_id?}' => $grupo,
            '{alumno_id}' => $alumno, '{alumno_id?}' => $alumno, '{alumnoelegido}' => $alumno,
            '{asignatura_id}' => $asignatura, '{imagen_id}' => $imagen,
            '{profesor_id}' => $profesor, '{profe_id}' => $profesor,
            '{profeelegido}' => $profesor, '{persona_id?}' => $profesor,
        ] as $parametro => $fila) {
            if ($fila === null) {
                $this->sinAjeno[] = $parametro;
            }
        }

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

    /**
     * Lo que el guard daría por suyo a quien lleva el token.
     *
     * Se lee con las MISMAS consultas que hace `ExigirPersonaPropia`
     * —`parentescos` para los acudidos, `alumnos.user_id` para sus cuentas—
     * para que la definición de «ajeno» del barrido no se separe de la del
     * guard el día que una de las dos cambie.
     *
     * Las cuatro listas están separadas porque el guard resuelve cada clave
     * contra una tabla distinta: `alumno_id` contra `alumnos`, `persona_id`
     * contra la ficha del tipo, `user_id` contra `users`. Un mismo número es
     * ajeno en una y propio en otra.
     *
     * @return array{personas: int[], alumnos: int[], usuarios: int[], grupos: int[], year: int}
     */
    private function loSuyoDe(object $quien, string $tipo): array
    {
        if ($tipo !== 'Acudiente') {
            $mio = DB::selectOne('SELECT a.id, m.grupo_id, g.year_id FROM alumnos a
                INNER JOIN matriculas m ON m.alumno_id = a.id AND m.deleted_at IS NULL
                INNER JOIN grupos g ON g.id = m.grupo_id
                WHERE a.user_id = ? LIMIT 1', [$quien->id]);

            $suyos = $mio === null ? [] : array_map(
                fn ($f) => (int) $f->grupo_id,
                DB::select('SELECT DISTINCT grupo_id FROM matriculas
                    WHERE alumno_id = ? AND deleted_at IS NULL', [$mio->id])
            );

            return [
                'personas' => [(int) ($mio->id ?? 0)],
                'alumnos' => [(int) ($mio->id ?? 0)],
                'usuarios' => [(int) $quien->id],
                'grupos' => $suyos,
                'year' => $this->yearDelContextoDe($quien),
            ];
        }

        $acudiente = DB::selectOne('SELECT id FROM acudientes WHERE user_id = ? AND deleted_at IS NULL
            ORDER BY id LIMIT 1', [$quien->id]);

        $acudidos = array_map(fn ($f) => (int) $f->alumno_id, DB::select(
            'SELECT alumno_id FROM parentescos WHERE acudiente_id = ? AND deleted_at IS NULL',
            [$acudiente->id ?? 0]
        ));

        $enLista = implode(',', $acudidos === [] ? [0] : $acudidos);

        $cuentas = array_map(fn ($f) => (int) $f->user_id, DB::select(
            'SELECT user_id FROM alumnos WHERE id IN ('.$enLista.')
             AND user_id IS NOT NULL AND deleted_at IS NULL'
        ));

        $grupos = array_map(fn ($f) => (int) $f->grupo_id, DB::select(
            'SELECT DISTINCT grupo_id FROM matriculas WHERE alumno_id IN ('.$enLista.')
             AND deleted_at IS NULL'
        ));

        return [
            'personas' => array_merge([(int) ($acudiente->id ?? 0)], $acudidos),
            'alumnos' => $acudidos,
            'usuarios' => array_merge([(int) $quien->id], $cuentas),
            'grupos' => $grupos,
            'year' => $this->yearDelContextoDe($quien),
        ];
    }

    /**
     * El año en el que trabaja este token, que es el único que devuelve datos.
     *
     * No sale de la matrícula ni de la fila que se leyó al elegir al usuario:
     * sale de `users.periodo_id` **releído después del login**, porque el login
     * lo reescribe. Un acudiente no tiene grupo —el contexto le pone la cadena
     * "N/A"— así que para él no hay otra fuente, y para un alumno la matrícula
     * puede ser la de un año anterior: el del barrido tiene dos, y la de la
     * primera es de 2024 mientras su token trabaja en 2025.
     */
    private function yearDelContextoDe(object $quien): int
    {
        $fila = DB::selectOne('SELECT p.year_id FROM users u
            INNER JOIN periodos p ON p.id = u.periodo_id
            WHERE u.id = ?', [$quien->id]);

        return (int) ($fila->year_id
            ?? DB::selectOne('SELECT id FROM years WHERE actual = 1 ORDER BY id LIMIT 1')->id);
    }
}
