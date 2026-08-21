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
 * **La tercera pasada fue por el `{id}`** (20 ago 2026). 85 rutas lo llevan y
 * todas recibían el mismo número, el `users.id` del superusuario, porque el mapa
 * resuelve por nombre de parámetro y `{id}` es un nombre solo. Contra las otras
 * cuarenta y dos tablas era un id prestado, y **un 404 por «esa fila no está» se
 * lee igual que un guard que funciona**. Ahora se resuelve por familia de rutas
 * —{@see TABLA_DE_ID}— y salió `myimages/destroy/{id}`, que con una imagen ajena
 * pide que la borren. Ver 05 §21.
 *
 * De ahí salió también lo que el barrido hace con las papeleras. Ocho rutas solo
 * actúan sobre una fila borrada y este seed no tiene ninguna, así que se **presta
 * una y se devuelve** —{@see fabricarEnPapelera()}—. La línea es que preparar el
 * sujeto no es fabricar el efecto: marcar una fila como borrada es lo mismo que
 * elegir a quién se le da el token; montar la fila que la ruta escribiría, no.
 *
 * **Y la cuarta cambió la lectura de todo lo anterior** (20 ago 2026). Este archivo
 * daba por cerrada toda ruta que no escribiera ni devolviera datos personales, y esa
 * lectura es falsa la mitad de las veces: 59 de 106 silencios lo eran porque no
 * había nada que alcanzar, no porque algo lo impidiera. Ahora hay una segunda
 * pasada —{@see control()}— que repite las mudas con un superusuario. Ver 05 §22.
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

    /** Una matrícula que no es suya, para las rutas que piden por `matricula_id`. */
    private int $matriculaAjena = 0;

    /**
     * Lo que el guard daría por suyo, guardado para resolver los `{id}`.
     *
     * @var array{personas: int[], alumnos: int[], usuarios: int[], grupos: int[], year: int}
     */
    private array $suyo = ['personas' => [], 'alumnos' => [], 'usuarios' => [], 'grupos' => [], 'year' => 0];

    /** Los `{id}` ya resueltos, por tabla y por si hacía falta de la papelera. */
    private array $idsPorTabla = [];

    /**
     * Las filas que este barrido ha mandado a la papelera para poder medir, y
     * que devuelve en cuanto golpea la ruta. Ver {@see fabricarEnPapelera()}.
     *
     * @var list<array{string, int}>
     */
    private array $devolverDeLaPapelera = [];

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
        $this->matriculaAjena = (int) (DB::selectOne('SELECT id FROM matriculas
            WHERE alumno_id = ? AND deleted_at IS NULL ORDER BY id LIMIT 1',
            [$this->ajenos['{alumno_id}'] ?? 0])->id ?? 0);
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
        $sinFila = [];
        $sinMapa = [];
        $mudas = [];

        foreach (Route::getRoutes()->getRoutes() as $ruta) {
            $verbo = $ruta->methods()[0];
            $uri = $ruta->uri();

            if (! str_starts_with($uri, 'api') || $verbo === 'HEAD') {
                continue;
            }

            $id = null;

            if (str_contains($uri, '{id}')) {
                [$id, $motivo] = $this->idAjenoPara($uri, $ruta);

                if ($id === null) {
                    $destino = $verbo.' '.$uri.'   ('.$motivo.')';
                    str_contains($motivo, 'TABLA_DE_ID')
                        ? $sinMapa[] = $verbo.' '.$uri
                        : $sinFila[] = $destino;
                }
            }

            $pedida = $this->rellenar($uri, $id);

            if ($pedida === null) {
                $sinValor[] = $verbo.' '.$uri;
                $this->devolverLoPrestado();

                continue;
            }

            foreach ($this->sinAjeno as $parametro) {
                if (str_contains($uri, $parametro)) {
                    $sinMedir[] = $verbo.' '.$uri.'   ('.$parametro.')';

                    break;
                }
            }

            $this->escrituras = [];
            $codigo = 0;
            $escribio = [];
            $contenido = '';

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

                // Lo que escribió la petición se captura AQUÍ, antes de devolver
                // lo prestado: el `UPDATE ... deleted_at = NULL` de la vuelta es
                // del barrido y no de la ruta, y contado como suyo aparecería
                // como un hallazgo en cada una de las ocho.
                $escribio = $this->escriturasDeLaPeticion();
                $contenido = (string) $r->getContent();
            } catch (\Throwable $e) {
                $this->salida[] = '  EXCEPCIÓN   '.$verbo.' '.$uri.'   '.substr($e->getMessage(), 0, 90);
            } finally {
                $this->devolverLoPrestado();
            }

            // Sin código no hubo respuesta: saltó la excepción, y ya está impresa.
            // Y el 403 es la respuesta correcta, así que tampoco se imprime.
            if ($codigo === 0 || $codigo === 403) {
                continue;
            }

            $personales = $escribio === [] && $codigo === 200
                ? $this->datosPersonalesEn($contenido)
                : [];

            if ($escribio === [] && $personales === []) {
                // Nada. Que es justo lo que el control de después tiene que
                // desmentir o confirmar: «nada» puede ser el guard o puede ser
                // que no hubiera nada.
                $mudas[] = [$verbo, $pedida, $uri];

                continue;
            }

            $encontrado++;

            $this->salida[] = '  '.str_pad((string) $codigo, 5).str_pad($verbo, 7).str_pad($uri, 58)
                .($escribio !== [] ? '  ESCRIBE: '.implode(' | ', $escribio) : '')
                .($personales !== [] ? '  PERSONALES: '.implode(',', $personales)
                    .' ['.strlen($contenido).' b]' : '');
        }

        $this->salida[] = '';
        $this->salida[] = "{$encontrado} rutas pasaron de largo con algo dentro.";
        $this->salida[] = 'Cada una hay que mirarla: muchas son lo suyo, y eso el barrido no lo sabe.';

        $this->control($mudas);

        if ($sinFila !== []) {
            $this->salida[] = '';
            $this->salida[] = count($sinFila).' rutas con {id} NO se midieron: el seed no tiene ninguna '
                .'fila ajena de la tabla que nombran.';
            $this->salida[] = 'Se golpearon con un cero, así que su respuesta vacía no prueba nada.';
            $this->salida[] = 'Las de papelera NO están aquí: para ésas el barrido presta una fila y la devuelve.';

            foreach ($sinFila as $ruta) {
                $this->salida[] = '  '.$ruta;
            }
        }

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

        // Y lo mismo por el lado del cuerpo. Una clave que los controladores leen
        // y el barrido no manda es una ruta que no llega a actuar sobre nada
        // ajeno: entra, no encuentra a quién, y contesta vacío. Así se escondieron
        // `promovidos/calcular-grupo` y media cartera (05 §17).
        // Que no quede nada prestado. Una fila que se quedara en la papelera
        // mediría a partir de ahí todas las rutas que la usan contra algo que no
        // está, y el barrido diría que están cerradas.
        $this->assertSame([], $this->devolverDeLaPapelera,
            'El barrido dejó filas en la papelera que tenía que haber devuelto.');

        // Y por el lado del `{id}`, que es el tercero. Una familia de rutas que no
        // esté en el mapa se golpearía con un cero y el barrido diría que la mide.
        $this->assertSame([], $sinMapa,
            "Estas rutas llevan {id} y TABLA_DE_ID no dice qué tabla nombra, así que\n"
            .'se golpearon con un cero. Añade la familia en TABLA_DE_ID.');

        $this->assertSame([], $this->clavesDeCuerpoSinCubrir(),
            "Los controladores leen del cuerpo estos identificadores y el barrido no\n"
            .'los manda, así que esas rutas se miden sin llegar a tocar a nadie. '
            .'Añádelos en CLAVES_DE_CUERPO.');
    }

    /**
     * La segunda pasada, que contesta lo que la primera no puede: ¿había algo?
     *
     * **Todo el barrido se apoya en leer «vacío» como «cerrado», y esa lectura es
     * falsa la mitad de las veces.** Una ruta que no escribe ni devuelve datos
     * personales puede estar defendida, o puede ser que los identificadores que se
     * le mandaron no nombren nada. Las dos cosas se ven idénticas desde fuera, y de
     * ahí salieron los seis hallazgos que el seed vacío tapó: `folios/iniciar`
     * pasó cuatro pasadas escribiendo sobre cero filas.
     *
     * En este seed el caso más gordo no es una tabla vacía sino un desajuste de
     * año: el sujeto trabaja en 2025 y **el único grupo ajeno que existe es de
     * 2024**, porque el seed tiene dos grupos y uno es el suyo. Cualquier ruta que
     * cruce grupo y año contesta vacío por eso, no por el guard — y así las 36
     * rutas que la §16 dio por cerradas pueden no haberse medido nunca.
     *
     * Así que las mudas se repiten con un token de **superusuario**, que no tiene
     * guard que lo pare, y con los mismos identificadores y el mismo cuerpo. Si
     * tampoco saca nada, esos identificadores no nombran nada alcanzable y el
     * silencio de la primera pasada **no prueba nada**.
     *
     * Cada control va en su propio savepoint y se deshace: son escrituras de
     * verdad hechas por quien sí puede hacerlas —`years/destroy` fuerza el borrado
     * de un año y arrastra 59 tablas por las FK— y sin deshacerlas la pasada se
     * destruiría a sí misma a mitad de camino.
     *
     * Lo que este control NO garantiza, y hay que saberlo: el superusuario tiene su
     * propio año de contexto, así que una ruta puede salir muda para él por lo
     * mismo. El error va hacia el lado seguro — dice «no puedo juzgarla» de una que
     * quizá sí estaba medida, nunca «cerrada» de una que no lo está.
     *
     * @param  list<array{string, string, string}>  $mudas
     */
    private function control(array $mudas): void
    {
        $superusuario = DB::selectOne('SELECT username FROM users WHERE is_superuser = 1
            AND is_active = 1 AND deleted_at IS NULL ORDER BY id LIMIT 1');

        if ($superusuario === null) {
            $this->salida[] = '';
            $this->salida[] = 'SIN CONTROL: el seed no tiene ningún superusuario activo.';

            return;
        }

        $token = $this->tokenDe($superusuario->username);
        $noJuzgables = [];

        foreach ($mudas as [$verbo, $pedida, $uri]) {
            $this->escrituras = [];

            DB::beginTransaction();

            try {
                $r = $this->withToken($token)->json($verbo, '/'.$pedida, $this->cuerpo);
                $saco = $this->escriturasDeLaPeticion() !== []
                    || ($r->getStatusCode() < 300 && ! $this->pareceVacia((string) $r->getContent()));
            } catch (\Throwable) {
                // Una ruta que revienta para el superusuario tampoco puede servir
                // de control: no dice si había algo, dice que está rota.
                $saco = false;
            } finally {
                DB::rollBack();
            }

            if (! $saco) {
                $noJuzgables[] = $verbo.' '.$uri;
            }
        }

        $this->salida[] = '';
        $this->salida[] = count($noJuzgables).' de las '.count($mudas).' mudas NO son juzgables: '
            .'con un superusuario tampoco sale nada.';
        $this->salida[] = 'Su silencio en la primera pasada no distingue un guard de un vacío.';

        foreach ($noJuzgables as $ruta) {
            $this->salida[] = '  '.$ruta;
        }
    }

    /**
     * Lo que escribió la petición que se acaba de medir, sin repetidos.
     *
     * Va en un método y no en la línea porque las escrituras las mete el
     * `DB::listen` de `setUp()`, y para el análisis estático la propiedad sigue
     * valiendo el `[]` con el que se limpió antes de la petición.
     *
     * @return list<string>
     */
    private function escriturasDeLaPeticion(): array
    {
        return array_values(array_unique($this->escrituras));
    }

    /** Si la respuesta no trae nada que mirar: lista vacía, objeto vacío, null. */
    private function pareceVacia(string $contenido): bool
    {
        $limpio = trim($contenido);

        return in_array($limpio, ['', '[]', '{}', 'null', '""', '0', 'false'], true);
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
     * Qué tabla nombra el `{id}` de cada familia de rutas.
     *
     * **`{id}` es el mismo nombre para cuarenta y tres tablas distintas**, y el
     * barrido les mandaba a las ochenta y cinco el mismo número: el `users.id`
     * del superusuario. Contra `perfiles/*` era el correcto y contra las demás
     * era un id de otra tabla — que existe o no existe, y si existe no es la fila
     * que la ruta pretende tocar. Un 404 por «esa fila no está» se lee igual que
     * un guard que funciona, y por eso esto no era un detalle: **eran ochenta y
     * cinco rutas cuya respuesta no probaba nada**, casi todas `DELETE` y `PUT`.
     *
     * El nombre de la familia sale de la URL y la tabla de lo que hace el
     * controlador, que no siempre coinciden: `boletines2/destroy/{id}` y
     * `editnota/destroy/{id}` borran **alumnos**, `definitivas_periodos` borra
     * `notas_finales`, y `certificados` es `config_certificados`.
     */
    private const TABLA_DE_ID = [
        'actividades' => 'ws_actividades',
        'acudientes' => 'acudientes',
        'alumnos' => 'alumnos',
        'areas' => 'areas',
        'asignaturas' => 'asignaturas',
        'aspiraciones' => 'vt_aspiraciones',
        'ausencias' => 'ausencias',
        'bitacoras' => 'bitacoras',
        'boletines2' => 'alumnos',
        'boletines3' => 'alumnos',
        'candidatos' => 'vt_candidatos',
        'certificados' => 'config_certificados',
        'ciudades' => 'ciudades',
        'contratos' => 'contratos',
        'definiciones_comportamiento' => 'definiciones_comportamiento',
        'definitivas_periodos' => 'notas_finales',
        'editnota' => 'alumnos',
        'enfermeria' => 'registros_enfermeria',
        'escalas' => 'escalas_de_valoracion',
        'frases' => 'frases',
        'frases_asignatura' => 'frases_asignatura',
        'grados' => 'grados',
        'grupos' => 'grupos',
        'images-users' => 'images',
        'materias' => 'materias',
        'matriculas' => 'matriculas',
        'myimages' => 'images',
        'niveles_educativos' => 'niveles_educativos',
        'nota_comportamiento' => 'nota_comportamiento',
        'notas' => 'notas',
        'opciones' => 'ws_opciones',
        'participantes' => 'vt_participantes',
        'perfiles' => 'users',
        'periodos' => 'periodos',
        'preguntas' => 'ws_preguntas',
        'profesores' => 'profesores',
        'requisitos' => 'requisitos_matricula',
        'roles' => 'roles',
        'subunidades' => 'subunidades',
        'unidades' => 'unidades',
        'votaciones' => 'vt_votaciones',
        'votos' => 'vt_votos',
        'years' => 'years',
    ];

    /**
     * Cómo se elige una fila AJENA en las tablas donde «suyo» significa algo.
     *
     * En las demás —`areas`, `frases`, `ciudades`, `unidades`— cualquier fila
     * sirve: son del colegio y no de nadie, que es el mismo criterio del comodín
     * `otro` de {@see CLAVES_DE_CUERPO}. Aquí están solo las cinco tablas donde
     * mandar una fila suya convertiría el barrido en un espejo: la ruta
     * respondería que sí y tendría razón.
     */
    private const AJENO_POR = [
        'alumnos' => ['id', 'alumnos'],
        'users' => ['id', 'usuarios'],
        'grupos' => ['id', 'grupos'],
        'profesores' => ['id', 'personas'],
        'acudientes' => ['id', 'personas'],
        'images' => ['user_id', 'usuarios'],
        'matriculas' => ['alumno_id', 'alumnos'],
    ];

    /**
     * Qué nombra cada clave que los controladores leen del cuerpo.
     *
     * Los nombres NO son los de la URL: un grupo se llama `grupo_id` en la
     * promoción y `grupo_actual` en la cartera, y esa diferencia es la que dejó
     * las dos sin medir (05 §17). La primera versión de este mapa se escribió a
     * mano con veinte claves; los controladores leen **setenta y ocho**, así que
     * ahora se declara qué clase de cosa nombra cada una y el valor sale de la
     * misma consulta que el mapa de la URL.
     *
     * `otro` es el comodín: un identificador que existe y no es suyo, para las
     * claves cuya tabla no hace falta acertar —lo que se mide es si la ruta llega
     * a actuar sobre algo ajeno, no sobre qué—.
     *
     * **`tipo` no se manda a propósito.** `ExigirPersonaPropia` comprueba que el
     * `tipo` declarado sea el del token, así que mandarlo provocaría 403 en rutas
     * que sin él pasan, y el barrido mediría menos creyendo que mide más.
     */
    private const CLAVES_DE_CUERPO = [
        'alumno_id' => 'alumno',
        'grupo_id' => 'grupo', 'grupo_actual' => 'grupo', 'grupo_from_id' => 'grupo',
        'grupo_to_id' => 'grupo', 'grupo_id_destino' => 'grupo', 'grupo_id_origen' => 'grupo',
        'profesor_id' => 'profesor', 'titular_id' => 'profesor', 'rector_id' => 'profesor',
        'secretario_id' => 'profesor', 'tesorero_id' => 'profesor', 'persona_id' => 'profesor',
        'user_id' => 'usuario', 'usuario_id' => 'usuario', 'become_id' => 'usuario',
        'acudiente_id' => 'acudiente',
        'imagen_id' => 'imagen', 'img_id' => 'imagen', 'foto_id' => 'imagen',
        'logo_id' => 'imagen', 'encabezado_img_id' => 'imagen', 'piepagina_img_id' => 'imagen',
        'matricula_id' => 'matricula',
        'asignatura_id' => 'asignatura', 'asign_id' => 'asignatura', 'asignatura_to_id' => 'asignatura',
        'periodo_id' => 'periodo', 'periodo_from_id' => 'periodo', 'periodo_to_id' => 'periodo',
        'year_id' => 'year',
        'nota_id' => 'nota', 'votacion_id' => 'votacion', 'role_id' => 'rol', 'ciudad_id' => 'ciudad',

        // Todo lo demás nombra algo del colegio que no es de nadie en concreto.
        'id' => 'otro', 'actividad_id' => 'otro', 'actividad_resuelta_id' => 'otro',
        'antec_id' => 'otro', 'area_id' => 'otro', 'asked_id' => 'otro',
        'aspiracion_id' => 'otro', 'assignment_id' => 'otro', 'ausencia_id' => 'otro',
        'blanco_aspiracion_id' => 'otro', 'candidato_id' => 'otro', 'comentario_id' => 'otro',
        'comportamiento_id' => 'otro', 'config_certificado_estudio_id' => 'otro',
        'config_id' => 'otro', 'contenido_id' => 'otro', 'data_id' => 'otro',
        'frase_id' => 'otro', 'grado_ant_id' => 'otro', 'grado_id' => 'otro',
        'historial_id' => 'otro', 'libro_id' => 'otro', 'materia_id' => 'otro',
        'nf_id' => 'otro', 'nivel_educativo_id' => 'otro', 'opcion_cuadricula_id' => 'otro',
        'opcion_id' => 'otro', 'ordinal_id' => 'otro', 'pais_id' => 'otro',
        'parentesco_acudiente_cambiar_id' => 'otro', 'parentesco_id' => 'otro',
        'participante_id' => 'otro', 'permission_id' => 'otro', 'pregunta_id' => 'otro',
        'proceso_id' => 'otro', 'publi_id' => 'otro', 'requisito_alumno_id' => 'otro',
        'rf_id' => 'otro', 'suceso_id' => 'otro', 'unidad1_id' => 'otro',
        'unidad2_id' => 'otro', 'unidad_id' => 'otro', 'unidades_ids' => 'otro',
        'uniforme_id' => 'otro',
    ];

    /**
     * El cuerpo que se manda, con un valor ajeno para cada clave conocida.
     *
     * `requested_alumnos` va aparte porque no es un id sino la lista con la que
     * los informes piden alumnos, y es una de las claves que `ExigirPersonaPropia`
     * mira por su cuenta.
     */
    private function cuerpoPlausible(): array
    {
        $porClase = [
            'alumno' => $this->ajenos['{alumno_id}'] ?? 0,
            'grupo' => $this->ajenos['{grupo_id}'] ?? 0,
            'profesor' => $this->ajenos['{profesor_id}'] ?? 0,
            'usuario' => $this->ajenos['{user_id}'] ?? 0,
            'acudiente' => $this->ajenos['{persona_id?}'] ?? 0,
            'imagen' => $this->ajenos['{imagen_id}'] ?? 0,
            'matricula' => $this->matriculaAjena,
            'asignatura' => $this->ajenos['{asignatura_id}'] ?? 0,
            'periodo' => $this->ajenos['{periodo_id}'] ?? 0,
            'year' => $this->ajenos['{year_id}'] ?? 0,
            'nota' => $this->ajenos['{nota_id}'] ?? 0,
            'votacion' => $this->ajenos['{votacion_id}'] ?? 0,
            'rol' => $this->ajenos['{role_id}'] ?? 0,
            'ciudad' => $this->ajenos['{ciudad_id}'] ?? 0,
            'otro' => 1,
        ];

        $cuerpo = [];

        foreach (self::CLAVES_DE_CUERPO as $clave => $clase) {
            $cuerpo[$clave] = $porClase[$clase];
        }

        $cuerpo['requested_alumnos'] = [['alumno_id' => $porClase['alumno']]];
        $cuerpo['num_periodo'] = $this->ajenos['{periodo_a_calcular?}'] ?? 1;
        $cuerpo['periodo_a_calcular'] = $cuerpo['num_periodo'];
        $cuerpo['year'] = $porClase['year'];
        $cuerpo['username'] = $this->ajenos['{username}'] ?? 'x';
        $cuerpo['texto_a_buscar'] = 'a';

        return $cuerpo;
    }

    /**
     * Las claves de cuerpo que los controladores leen y este mapa no cubre.
     *
     * El barrido ya se protege de encogerse en silencio por el lado de la URL con
     * el `assertSame` del final. Por el lado del cuerpo se decía que no había
     * atajo estático, y **no es cierto**: los identificadores se leen con
     * `Request::input('x')` y eso se puede buscar. No es exacto —una clave
     * construida en una variable se escapa— pero encuentra la que alguien añada
     * escribiéndola, que es como se añaden.
     *
     * @return list<string>
     */
    private function clavesDeCuerpoSinCubrir(): array
    {
        $fuentes = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path('Http/Controllers'))
        );

        $leidas = [];

        foreach ($fuentes as $fichero) {
            if ($fichero->getExtension() !== 'php') {
                continue;
            }

            preg_match_all(
                "/(?:Request::(?:input|has)|request\(\)->input)\(\s*'([a-zA-Z0-9_]+)'/",
                (string) file_get_contents($fichero->getPathname()),
                $coincidencias
            );

            foreach ($coincidencias[1] as $clave) {
                if (preg_match('/(^id$|_id$|_ids$)/', $clave) === 1) {
                    $leidas[$clave] = true;
                }
            }
        }

        return array_values(array_diff(array_keys($leidas), array_keys(self::CLAVES_DE_CUERPO)));
    }

    /**
     * El `{id}` que le toca a esta ruta, y por qué no lo hay cuando no lo hay.
     *
     * Devuelve el motivo en vez de un cero silencioso a propósito: el barrido
     * golpea igual —una ruta que responde 403 antes de mirar la fila queda
     * medida de todos modos—, pero **lo que no se ha medido lo dice**. Es la
     * misma regla que ya cumplen el mapa de la URL y el del cuerpo.
     *
     * @return array{0: ?int, 1: string}
     */
    private function idAjenoPara(string $uri, \Illuminate\Routing\Route $ruta): array
    {
        $familia = explode('/', $uri)[1] ?? '';

        if (! isset(self::TABLA_DE_ID[$familia])) {
            return [null, 'la familia no está en TABLA_DE_ID'];
        }

        $tabla = self::TABLA_DE_ID[$familia];
        $papelera = $this->necesitaPapelera($ruta);
        $clave = $tabla.($papelera ? ' (papelera)' : '');

        if (! array_key_exists($clave, $this->idsPorTabla)) {
            $this->idsPorTabla[$clave] = $this->idAjenoEn($tabla, $papelera);
        }

        // Si la ruta necesita una fila borrada y el seed no tiene ninguna, se
        // manda una a la papelera para esta petición y se devuelve después. NO se
        // cachea: al devolverla, el id guardado dejaría de estar borrado.
        if ($this->idsPorTabla[$clave] === null && $papelera) {
            $prestada = $this->fabricarEnPapelera($tabla);

            return $prestada === null
                ? [null, "no hay ninguna fila ajena en {$tabla} que mandar a la papelera"]
                : [$prestada, ''];
        }

        return $this->idsPorTabla[$clave] === null
            ? [null, "no hay ninguna fila ajena en {$tabla}"]
            : [$this->idsPorTabla[$clave], ''];
    }

    /**
     * Si la ruta solo actúa sobre una fila que ya esté en la papelera.
     *
     * Se lee del código del método y no del nombre de la ruta porque los nombres
     * mienten: `years/destroy/{id}` hace `forceDelete()` sobre `onlyTrashed()`
     * —es el borrado de más alcance del sistema— y `years/delete/{id}` es el que
     * manda a la papelera. Con el nombre por criterio, la de verdad peligrosa se
     * habría golpeado con un año vivo y habría contestado 404.
     */
    private function necesitaPapelera(\Illuminate\Routing\Route $ruta): bool
    {
        $accion = $ruta->getActionName();

        if (! str_contains($accion, '@')) {
            return false;
        }

        [$clase, $metodo] = explode('@', $accion);

        try {
            $reflejo = new \ReflectionMethod($clase, $metodo);
        } catch (\ReflectionException) {
            return false;
        }

        $fichero = (array) file((string) $reflejo->getFileName());
        $cuerpo = implode('', array_slice($fichero, $reflejo->getStartLine() - 1,
            $reflejo->getEndLine() - $reflejo->getStartLine() + 1));

        return str_contains($cuerpo, 'onlyTrashed');
    }

    /**
     * Manda una fila ajena a la papelera para poder medir la ruta que la restaura.
     *
     * Ocho de las rutas con `{id}` solo actúan sobre una fila con `deleted_at`
     * puesto —`alumnos/restore`, `grupos/forcedelete`, `perfiles/restore`…— y en
     * este seed no hay ni un alumno, ni un grupo, ni un usuario borrado, porque el
     * seed copia un grupo y sus datos y las papeleras se quedan fuera. Sin fila,
     * la ruta contesta 404 y ese 404 se lee igual que un guard que funciona.
     *
     * **Preparar el sujeto no es fabricar el efecto.** Lo que se mide sigue siendo
     * si el token restaura la fila de OTRO; marcarla como borrada es lo mismo que
     * ya se hace al elegir a quién se le da el token. Lo que no se hace es montar
     * la fila que la ruta escribiría — eso sí volvería turbia la medida.
     *
     * **Se devuelve en cuanto se golpea la ruta**, y no al final del barrido, por
     * una razón del seed: solo tiene dos grupos y uno es el del sujeto, así que el
     * único grupo ajeno es el mismo `{grupo_id}` que usan otras treinta y seis
     * rutas. Dejarlo borrado hasta el final las mediría todas contra un grupo que
     * ya no está.
     */
    private function fabricarEnPapelera(string $tabla): ?int
    {
        $id = $this->idAjenoEn($tabla, false);

        if ($id === null) {
            return null;
        }

        DB::update('UPDATE `'.$tabla.'` SET deleted_at = ? WHERE id = ?',
            [now()->toDateTimeString(), $id]);

        $this->devolverDeLaPapelera[] = [$tabla, $id];

        return $id;
    }

    /**
     * Devuelve lo prestado.
     *
     * Sin `deleted_at IS NOT NULL` en el WHERE a propósito: si la ruta medida la
     * restauró de verdad —que es justo el hallazgo que se busca— la fila ya está
     * viva y el UPDATE no cambia nada. Y si la borró del todo, no hay fila que
     * devolver y afecta a cero.
     */
    private function devolverLoPrestado(): void
    {
        foreach ($this->devolverDeLaPapelera as [$tabla, $id]) {
            DB::update('UPDATE `'.$tabla.'` SET deleted_at = NULL WHERE id = ?', [$id]);
        }

        $this->devolverDeLaPapelera = [];
    }

    /** Una fila de esta tabla que no sea suya, o null si el seed no tiene ninguna. */
    private function idAjenoEn(string $tabla, bool $papelera): ?int
    {
        $donde = [];

        if ($this->tieneBorradoSuave($tabla)) {
            $donde[] = $papelera ? 'deleted_at IS NOT NULL' : 'deleted_at IS NULL';
        } elseif ($papelera) {
            // El método usa `onlyTrashed()` sobre una tabla sin `deleted_at`: es
            // un endpoint roto, no una fila que falte. Se golpea con lo que haya.
            $donde[] = '1 = 1';
        }

        if (isset(self::AJENO_POR[$tabla])) {
            [$columna, $lista] = self::AJENO_POR[$tabla];
            $suyos = $this->suyo[$lista];
            $donde[] = "{$columna} NOT IN (".implode(',', $suyos === [] ? [0] : $suyos).')';
        }

        // La tabla sale de TABLA_DE_ID, que es una constante de este archivo: no
        // hay nada de la petición en esta consulta.
        $fila = DB::selectOne('SELECT id FROM `'.$tabla.'` WHERE '
            .($donde === [] ? '1 = 1' : implode(' AND ', $donde)).' ORDER BY id LIMIT 1');

        return $fila === null ? null : (int) $fila->id;
    }

    private function tieneBorradoSuave(string $tabla): bool
    {
        return DB::select('SHOW COLUMNS FROM `'.$tabla."` LIKE 'deleted_at'") !== [];
    }

    /** Sustituye los parámetros de la URL, o devuelve null si no sabe con qué. */
    private function rellenar(string $uri, ?int $id): ?string
    {
        $pedida = strtr($uri, array_map('strval', $this->ajenos) + ['{id}' => (string) ($id ?? 0)]);
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
        $suyo = $this->suyo = $this->loSuyoDe($quien, $tipo);

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
            '{imagen_id}' => $imagen->id ?? 0,
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
