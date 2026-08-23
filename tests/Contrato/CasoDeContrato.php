<?php

namespace Tests\Contrato;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\PendingCommand;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\TestCase;

/**
 * Base de los tests de contrato.
 *
 * Estos tests NO comprueban que el código esté bien. Comprueban que la respuesta
 * de un endpoint no ha cambiado. Su único trabajo es gritar cuando la migración
 * altera algo que el frontend está leyendo.
 *
 * Por eso comparan contra un snapshot guardado en disco, no contra valores
 * escritos a mano: escribir a mano lo que devuelven 538 rutas es inviable, y una
 * snapshot generado del comportamiento actual describe lo que hay hoy, que es
 * justo lo que no debe cambiar.
 *
 * La base de datos NO se reconstruye entre tests: cada uno corre dentro de una
 * transacción que se deshace al terminar. El seed se carga una vez con
 * tools/construir-bd-test.sh.
 */
abstract class CasoDeContrato extends TestCase
{
    use DatabaseTransactions;

    /** Contraseña de todos los usuarios del seed. */
    protected const CLAVE = 'test-1234';

    protected function setUp(): void
    {
        parent::setUp();

        $this->comprobarBaseDeTest();
    }

    /**
     * Sin esto, un despiste de configuración deja los tests corriendo contra la
     * base de desarrollo. Con DatabaseTransactions el daño se deshace, pero los
     * tests pasarían o fallarían por datos que no controlamos, que es peor que
     * no tenerlos.
     */
    private function comprobarBaseDeTest(): void
    {
        $conexion = config('database.default');
        $base = config("database.connections.{$conexion}.database");

        // El sufijo opcional del final admite una base por sesión
        // (`simonbolivar_testing_b`), que es como se corren dos tandas a la vez
        // sin que se bloqueen entre sí. Ver docs/migracion/03-tests.md.
        if (! preg_match('/_(testing|test)(_[a-z0-9]+)?$/', (string) $base)) {
            $this->fail(
                "Los tests apuntan a la base '{$base}', que no acaba en _testing.\n".
                "Revisa DB_CONNECTION en phpunit.xml. Debe ser 'mysql_testing'."
            );
        }

        if (DB::table('users')->count() === 0) {
            $this->fail(
                "La base '{$base}' está vacía. Constrúyela con:\n".
                '  tools/construir-bd-test.sh'
            );
        }
    }

    /**
     * Un usuario del seed del tipo pedido, que el contexto pueda resolver.
     *
     * No vale cualquier usuario del tipo. `User::fromToken()` resuelve el
     * contexto con un `switch` de cuatro ramas, y cada rama exige cosas
     * distintas: un Alumno necesita ficha en `alumnos`, matrícula en estado
     * MATR/ASIS/PREM, grupo, y que su `periodo_id` sea de un periodo del MISMO
     * año que el grupo. Si falta cualquiera de esas piezas la consulta no
     * devuelve filas y el endpoint responde 400.
     *
     * El seed copia `users` entera pero solo un grupo de alumnos, así que la
     * mayoría de los usuarios de tipo Alumno no tienen ficha. Elegir "el
     * primero del tipo" da uno de esos.
     *
     * Se ordena por id para que sea el mismo en cada ejecución: si cada corrida
     * eligiera otro usuario, el snapshot no compararía nada.
     */
    protected function usuarioDeTipo(string $tipo): object
    {
        $consultas = [
            'Alumno' => 'SELECT u.* FROM users u
                         INNER JOIN alumnos a ON a.user_id = u.id AND a.deleted_at IS NULL
                         INNER JOIN matriculas m ON m.alumno_id = a.id
                            AND m.estado IN ("MATR", "ASIS", "PREM") AND m.deleted_at IS NULL
                         INNER JOIN grupos g ON g.id = m.grupo_id AND g.deleted_at IS NULL
                         INNER JOIN periodos p ON p.id = u.periodo_id AND p.year_id = g.year_id',

            'Acudiente' => 'SELECT u.* FROM users u
                            INNER JOIN acudientes ac ON ac.user_id = u.id AND ac.deleted_at IS NULL
                            INNER JOIN periodos p ON p.id = u.periodo_id',

            'Profesor' => 'SELECT u.* FROM users u
                           INNER JOIN profesores pr ON pr.user_id = u.id AND pr.deleted_at IS NULL
                           INNER JOIN periodos p ON p.id = u.periodo_id',

            'Usuario' => 'SELECT u.* FROM users u
                          INNER JOIN periodos p ON p.id = u.periodo_id',
        ];

        $this->assertArrayHasKey($tipo, $consultas, "Tipo de usuario desconocido: '{$tipo}'.");

        $filas = DB::select(
            $consultas[$tipo].' WHERE u.tipo = ? AND u.is_active = 1 AND u.deleted_at IS NULL
                                   ORDER BY u.id LIMIT 1',
            [$tipo]
        );

        $this->assertNotEmpty(
            $filas,
            "El seed no tiene ningún usuario de tipo '{$tipo}' con el contexto completo.\n".
            'Regenérala con: php tools/generar-seed-test.php'
        );

        return $filas[0];
    }

    /**
     * El grupo del seed con más alumnos matriculados.
     *
     * Casi todo lo que imprime el colegio —boletines, observador, acta de
     * evaluación, listados— se pide POR GRUPO, así que este es el punto de
     * partida de medio P1. Se ordena por cantidad y luego por id para que sea
     * siempre el mismo: si cada corrida eligiera otro grupo, los snapshots no
     * compararían nada.
     *
     * Devuelve también el `year_id` porque es lo que hay que casar con el
     * usuario que pida el informe. Ver tokenDelPersonalDe().
     */
    protected function grupoConAlumnos(): object
    {
        $grupo = DB::selectOne('SELECT g.id, g.year_id FROM grupos g
            INNER JOIN matriculas m ON m.grupo_id = g.id AND m.deleted_at IS NULL
                AND m.estado IN ("MATR","ASIS","PREM")
            WHERE g.deleted_at IS NULL
            GROUP BY g.id, g.year_id ORDER BY COUNT(m.id) DESC, g.id LIMIT 1');

        $this->assertNotNull($grupo, 'El seed no tiene ningún grupo con alumnos matriculados.');

        return $grupo;
    }

    /**
     * El token de alguien del colegio cuyo año sea el que se le pide.
     *
     * El año no se elige, y es la trampa que más veces ha vaciado un informe sin
     * que fallara nada: los controladores calculan contra `$user->year_id`, que
     * sale del periodo del usuario, y `Services\Login` reescribe `users.periodo_id`
     * al periodo `actual` en cada inicio de sesión. Con un usuario de otro año la
     * respuesta sale con la lista vacía, en 200, y el test pasa sin haber
     * calculado nada.
     *
     * Se pide un `Usuario` porque es el tipo que atraviesa los guards de
     * autorización —`boletin.propio` y `auth.personal` no le aplican—, que es lo
     * que hace falta para mirar la FORMA de la respuesta sin repetir aquí lo que
     * ya prueba AutorizacionTest.
     */
    protected function tokenDelPersonalDe(int $yearId): string
    {
        $usuario = DB::selectOne('SELECT u.username FROM users u
            INNER JOIN periodos p ON p.id = u.periodo_id AND p.deleted_at IS NULL
            WHERE u.tipo = "Usuario" AND u.is_active = 1 AND u.deleted_at IS NULL
              AND p.year_id = ? ORDER BY u.id LIMIT 1', [$yearId]);

        $this->assertNotNull($usuario,
            "El seed no tiene ningún Usuario en el año {$yearId}.\n".
            'Sin eso los informes de ese año salen vacíos y el test no comprueba nada.');

        return $this->tokenDe($usuario->username);
    }

    /**
     * Alguien del personal del colegio que **no** es superusuario.
     *
     * Existe porque los dos ayudantes de arriba **no valen para esta pregunta**, y no
     * porque estén mal: ordenan por id y devuelven el primero del tipo, que es
     * exactamente lo correcto cuando lo que se mira es **la forma de la respuesta**.
     * Cuando lo que se mira es **qué puede hacer alguien del personal**, «cualquiera
     * del tipo» es lo único que no vale.
     *
     * Medido la noche del 22 al 23 de agosto de 2026 (§157): de los **veinte `Usuario`
     * activos del seed, diez son superusuario**, y son los de id más bajo. Así que:
     *
     *     usuarioDeTipo('Usuario')   ->  usuario 1     is_superuser = 1, rol Admin
     *     tokenDelPersonalDe(8)      ->  usuario 1     is_superuser = 1
     *     tokenDelPersonalDe(7)      ->  usuario 685   is_superuser = 0
     *
     * **El mismo ayudante devuelve un superusuario o un administrativo llano según el
     * año que le toque**, y ninguna llamada pasa el año literal: todas pasan
     * `$grupo->year_id`, así que el sujeto **depende del grupo que ese test eligió** y
     * no se puede ver leyendo el test.
     *
     * Con eso, treinta y cinco tests que dicen «el personal puede X» estaban
     * demostrando «el superusuario puede X», que es **menos**, y salían verdes.
     *
     * **Los dos viejos no se tocaron, se añadió éste**, y el motivo es el reverso:
     * hay **treinta y un** métodos que afirman un rechazo —o un 500— y que hoy pasan
     * **porque el sujeto es un superusuario**. Algunos prueban de más justo por eso:
     * `PedidosDeAsignaturaTest::test_un_administrativo_recibe_403_al_pedir_una_materia`
     * demuestra que esa ruta rechaza **incluso** a un superusuario, que es más fuerte
     * que lo que dice su nombre. Cambiarles el sujeto los debilitaría sin que nadie lo
     * haya pedido.
     *
     * Y si el seed dejara de traer uno llano, esto **falla con nombre** en vez de
     * devolver un superusuario y llamarlo personal. Es el mismo criterio que
     * `tests/Barrido/SuperficieDeUnTokenTest.php` después de la §111:
     * **un ayudante que dice a quién eligió no puede elegir a otro en silencio**, que
     * es literalmente el fallo que este método viene a cerrar.
     */
    protected function usuarioLlanoDelPersonal(): object
    {
        $fila = DB::selectOne('SELECT u.* FROM users u
            INNER JOIN periodos p ON p.id = u.periodo_id AND p.deleted_at IS NULL
            WHERE u.tipo = "Usuario" AND u.is_active = 1 AND u.deleted_at IS NULL
              AND u.is_superuser = 0
            ORDER BY u.id LIMIT 1');

        $this->assertNotNull($fila,
            "El seed no tiene ningún Usuario que NO sea superusuario.\n"
            .'Sin él, un test que diga «el personal puede X» demuestra «el superusuario '
            ."puede X», que es menos.\n"
            .'Regenérala con: php tools/generar-seed-test.php');

        return $fila;
    }

    /** Su token, que es como lo usan casi todos. */
    protected function tokenDelPersonalLlano(): string
    {
        return $this->tokenDe($this->usuarioLlanoDelPersonal()->username);
    }

    /**
     * El mismo, pero **de un año concreto**: el hermano llano de `tokenDelPersonalDe()`.
     *
     * Hace falta aparte porque los informes se calculan contra `$user->year_id`, así
     * que un sujeto de otro año devuelve la lista vacía en 200 y el test pasa sin
     * haber calculado nada — que es justo lo que explica el docblock de
     * `tokenDelPersonalDe()` y sigue siendo cierto.
     *
     * Lo que cambia es lo otro: **aquel devuelve un superusuario o no según el año**
     * —el 1 para el 8 y el 685 para el 7—, y como ninguna llamada pasa el año literal
     * sino `$grupo->year_id`, **el sujeto depende del grupo que el test eligió y no se
     * ve leyendo el test**. Éste exige `is_superuser = 0` en el año que se le pida, y
     * el seed tiene al menos dos en cada uno de los tres años con usuarios.
     */
    protected function tokenDelPersonalLlanoDe(int $yearId): string
    {
        $usuario = DB::selectOne('SELECT u.username FROM users u
            INNER JOIN periodos p ON p.id = u.periodo_id AND p.deleted_at IS NULL
            WHERE u.tipo = "Usuario" AND u.is_active = 1 AND u.deleted_at IS NULL
              AND u.is_superuser = 0 AND p.year_id = ?
            ORDER BY u.id LIMIT 1', [$yearId]);

        $this->assertNotNull($usuario,
            "El seed no tiene ningún Usuario sin superusuario en el año {$yearId}.\n"
            .'Con uno de otro año el informe sale vacío en 200 y el test no comprueba nada; '
            .'con un superusuario, comprueba menos de lo que dice su nombre.');

        return $this->tokenDe($usuario->username);
    }

    /**
     * Un grupo del MISMO año al que el alumno no pertenece, montado aquí.
     *
     * Existe porque «un grupo que no es el suyo» no se puede sacar del seed con
     * `WHERE grupo_id != $suyo`, y creerlo ha costado ya cuatro veces lo mismo
     * —la última, el 21 de agosto de 2026, una acusación falsa contra un guard
     * que estaba bien—. El seed trae **dos grupos, uno por año**, y **el mismo
     * alumno está matriculado en los dos**: 84 en el año 7 y 98 en el 8. Así que
     * `!=` no devuelve un grupo ajeno, devuelve **el otro grupo suyo**.
     *
     * Y devuelve el peor de los dos, porque `Services\Login` reescribe
     * `users.periodo_id` al periodo del año `actual` en cada inicio de sesión: el
     * grupo «ajeno» que sale del `!=` acaba siendo justo el del año en el que el
     * token deja al alumno. El test pasa, y lo que ha comprobado es que un alumno
     * abre una actividad **de su propia asignatura**.
     *
     * Por eso esto crea un grupo de verdad —mismo año, sin matrículas— con una
     * asignatura dentro, y lo deshace la transacción del test. Es la única forma
     * de que «ajeno» signifique ajeno sin que el año se cuele en la comparación.
     *
     * Ver 05 §16 y docs/migracion/11-votaciones.md.
     *
     * @return object {grupo_id, asignatura_id}
     */
    protected function grupoAjenoDelMismoAnio(int $yearId): object
    {
        $molde = DB::selectOne('SELECT grado_id FROM grupos WHERE year_id = ? AND deleted_at IS NULL
                                ORDER BY id LIMIT 1', [$yearId]);

        $this->assertNotNull($molde, "El seed no tiene ningún grupo en el año {$yearId}.");

        $grupoId = DB::table('grupos')->insertGetId([
            'nombre' => 'Grupo ajeno de pruebas',
            'abrev' => 'AJE',
            'year_id' => $yearId,
            'grado_id' => $molde->grado_id,
            'orden' => 99,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $materia = DB::selectOne('SELECT id FROM materias WHERE deleted_at IS NULL ORDER BY id LIMIT 1');
        $profesor = DB::selectOne('SELECT id FROM profesores WHERE deleted_at IS NULL ORDER BY id LIMIT 1');

        $this->assertNotNull($materia, 'El seed no tiene materias.');
        $this->assertNotNull($profesor, 'El seed no tiene profesores.');

        $asignaturaId = DB::table('asignaturas')->insertGetId([
            'materia_id' => $materia->id,
            'grupo_id' => $grupoId,
            'profesor_id' => $profesor->id,
            'orden' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (object) ['grupo_id' => $grupoId, 'asignatura_id' => $asignaturaId];
    }

    /** El grupo del seed y un token que lo pueda ver entero, que es el par de siempre. */
    protected function grupoYPersonal(): array
    {
        $grupo = $this->grupoConAlumnos();

        return [$grupo, $this->tokenDelPersonalDe((int) $grupo->year_id)];
    }

    /**
     * ¿Esta ruta exige token?
     *
     * El guard se aplica en grupo a toda la API (routes/api.php) y las
     * excepciones se marcan con `->withoutMiddleware('auth.token')`, así que
     * mirar solo `middleware()` diría que sí en las 533.
     */
    protected function exigeToken(Route $ruta): bool
    {
        return in_array('auth.token', $ruta->middleware(), true)
            && ! in_array('auth.token', $ruta->excludedMiddleware(), true);
    }

    /**
     * `withToken()`, olvidando el controlador que dejó la petición anterior.
     * **Es lo que hace que un test con dos identidades mida algo.**
     *
     * `Illuminate\Routing\Route::getController()` guarda la instancia del
     * controlador **en la ruta**, y el router es un singleton que sobrevive a
     * todas las peticiones del proceso. En una petición HTTP de verdad da igual
     * —php-fpm levanta un proceso por petición—, pero dentro de un test method
     * todas las llamadas comparten proceso: **golpear la MISMA ruta con dos
     * tokens distintos reutiliza la misma instancia**, y con ella el
     * `$this->user` que memorizó el trait `ResuelveElUsuario` la primera vez.
     *
     * Lo que eso rompe no es que falle: es que **pasa por la razón equivocada**.
     * Un test de la forma «un alumno no puede; un acudiente tampoco» sobre una
     * ruta cuyo permiso se comprueba dentro del controlador —y no en un
     * middleware, que sí lee el token de la petición— comprueba dos veces al
     * alumno y nunca al acudiente. Se descubrió al revés, con un superusuario
     * recibiendo el 403 del profesor de la línea de antes.
     *
     * Se hace aquí, encima de `withToken()`, y no en un método aparte que haya
     * que acordarse de usar: la disciplina es justo lo que falló. Cuesta recorrer
     * las 539 rutas soltando una referencia, que no se nota al lado de una
     * petición HTTP completa.
     *
     * Ver docs/migracion/03-tests.md y la nota sobre Octane del plan: bajo un
     * runtime persistente esto deja de ser cosa de los tests.
     */
    public function withToken(string $token, string $type = 'Bearer'): static
    {
        $this->olvidarControladores();

        return parent::withToken($token, $type);
    }

    /** Suelta la instancia que cada ruta guarda de su controlador. */
    protected function olvidarControladores(): void
    {
        foreach (app('router')->getRoutes()->getRoutes() as $ruta) {
            (function () {
                $this->controller = null;
            })->call($ruta);
        }
    }

    /** Hace login y devuelve el token, sin comprobar nada: es la vía para montar otros tests. */
    protected function tokenDe(string $username): string
    {
        $r = $this->postJson('/api/login/credentials', [
            'username' => $username,
            'password' => self::CLAVE,
        ]);

        $r->assertStatus(200);

        return $r->json('el_token');
    }

    /**
     * Compara contra el snapshot guardado, o lo crea si no existe.
     *
     * Al crearla imprime un aviso: un snapshot recién creado no ha
     * verificado nada todavía, solo ha registrado el comportamiento de hoy.
     * Hay que leerla antes de fiarse de ella.
     *
     * `$real` no se declara `array` porque hay endpoints que devuelven un
     * escalar y su forma también lo es: `GET api/folios/iniciar` responde un
     * número suelto, y su snapshot es la cadena `'int'`. Envolverlo en un array
     * para que quepa metería en el fichero una clave que la respuesta no tiene.
     */
    protected function compararConInstantanea(string $nombre, array|string $real): void
    {
        $ruta = __DIR__.'/Snapshots/'.$nombre.'.json';

        $json = json_encode($real, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (! file_exists($ruta)) {
            file_put_contents($ruta, $json."\n");

            fwrite(STDERR, "\n  ↳ snapshot creado: {$nombre}.json (revísalo antes de fiarte)\n");

            $this->addToAssertionCount(1);

            return;
        }

        $esperado = json_decode(file_get_contents($ruta), true);

        $this->assertSame(
            $esperado,
            $real,
            "La respuesta de '{$nombre}' cambió respecto al snapshot.\n".
            "Si el cambio es intencionado, borra tests/Contrato/Snapshots/{$nombre}.json y vuelve a correr."
        );
    }

    /**
     * La forma de la respuesta, con cada posición nombrada, y el largo comprobado.
     *
     * **A esta respuesta no se le puede pasar `forma()` entera.** Para `forma()`
     * un array de claves 0..3 es una LISTA, y de una lista guarda solo la forma
     * del primer elemento — que es lo correcto cuando los elementos son
     * homogéneos, el caso de `alumnos`, y es desastroso aquí, donde la posición 0
     * es el grupo y la 3 son las escalas. El snapshot habría guardado el grupo,
     * tirado el boletín entero, y pasado siempre. Se descubrió al mirar el primer
     * `.json` generado, no leyendo el código.
     *
     * Comprobar el largo tampoco es puntillería: el frontend lee `respuesta[2]`
     * para los alumnos. Si alguna versión del framework serializara esto como
     * objeto con claves, el JSON seguiría siendo válido y la pantalla quedaría
     * en blanco.
     *
     * Por dentro usa `formaUnida()` y no `forma()`. `Grupo::alumnos()` ordena por
     * `apellidos, nombres` y el seed anonimizado repite nombres, así que qué
     * alumno cae en la posición 0 lo decide MySQL: con `forma()` las columnas
     * nullable salían `'null'` o `'string'` según la corrida. Ver el comentario
     * de formaUnida(), aquí debajo.
     *
     * Vive en la clase base desde el muestreo de la P2: la tupla no era cosa de
     * los boletines, la devuelven también las notas actuales del grupo.
     */
    protected function formaDeLaTupla(array $cuerpo, array $nombres): array
    {
        $this->assertSame(range(0, count($nombres) - 1), array_keys($cuerpo),
            'La respuesta dejó de ser una tupla posicional de '.count($nombres).' elementos.');

        $forma = [];

        foreach ($nombres as $posicion => $nombre) {
            $forma[$nombre] = $this->formaUnida($cuerpo[$posicion]);
        }

        return $forma;
    }

    /**
     * Como forma(), pero uniendo TODOS los elementos de cada lista en vez de
     * quedarse con el primero.
     *
     * `forma()` mira `$valor[0]` porque para una lista homogénea basta, y es
     * cierto casi siempre. Deja de serlo cuando la lista trae filas de una tabla
     * con columnas nullable: `eps` sale `'null'` o `'string'` según qué alumno
     * vaya primero, y quién va primero depende del `ORDER BY` de la consulta.
     *
     * **El del acta de evaluación empata.** Ordena por `apellidos, nombres`, y el
     * seed está anonimizado con un diccionario de nombres corto: hay ocho alumnos
     * llamados igual. MySQL devuelve los empates en el orden que quiera, así que
     * el snapshot cambiaba de una corrida a otra sin que cambiara nada. Se
     * descubrió porque falló la segunda vez que se ejecutó, no la primera.
     *
     * Un tipo que aparece de dos formas se escribe `'null|string'`. Eso hace la
     * comparación estable y de paso más estricta: describe la columna entera y no
     * la fila que tocó.
     *
     * No se cambia `forma()` para que haga esto. Las snapshots del P0 están
     * escritas con la otra y regenerarlas de golpe convertiría un cambio de
     * herramienta en un diff de mil líneas donde no se distingue lo que se movió.
     */
    protected function formaUnida($valor)
    {
        if (! is_array($valor)) {
            return $this->forma($valor);
        }

        if ($valor === []) {
            return [];
        }

        // Lista: se unen las formas de todos los elementos en una sola.
        if (array_keys($valor) === range(0, count($valor) - 1)) {
            $unida = $this->formaUnida($valor[0]);

            foreach (array_slice($valor, 1) as $elemento) {
                $unida = $this->unir($unida, $this->formaUnida($elemento));
            }

            return [$unida];
        }

        $forma = [];

        foreach ($valor as $clave => $v) {
            $forma[$clave] = $this->formaUnida($v);
        }

        ksort($forma);

        return $forma;
    }

    /** Une dos formas: las claves de las dos, y por clave todos los tipos vistos. */
    private function unir($a, $b)
    {
        if (is_array($a) && is_array($b)) {
            foreach ($b as $clave => $v) {
                $a[$clave] = array_key_exists($clave, $a) ? $this->unir($a[$clave], $v) : $v;
            }

            if ($a !== [] && array_keys($a) !== range(0, count($a) - 1)) {
                ksort($a);
            }

            return $a;
        }

        if (is_array($a) || is_array($b)) {
            // Una clave que unas veces trae lista y otras un escalar. Pasa, y
            // esconderlo detrás de uno de los dos sería mentir en el snapshot.
            return is_array($a) ? $a : $b;
        }

        $tipos = array_unique(array_merge(explode('|', $a), explode('|', $b)));
        sort($tipos);

        return implode('|', $tipos);
    }

    /**
     * Reduce una respuesta a su FORMA: qué claves tiene y de qué tipo es cada
     * valor, descartando los valores concretos.
     *
     * Es lo que hace que el snapshot sirva. Guardar los valores haría que el
     * test fallara porque cambió una fecha o porque el id autoincremental avanzó;
     * lo que el frontend consume es la forma, y es lo que no puede cambiar.
     */
    protected function forma($valor)
    {
        if (is_array($valor)) {
            // Lista: basta la forma del primer elemento. Si los elementos
            // tuvieran formas distintas entre sí, eso ya sería el fallo.
            if ($valor === [] || array_keys($valor) === range(0, count($valor) - 1)) {
                return $valor === [] ? [] : [$this->forma($valor[0])];
            }

            $forma = [];

            foreach ($valor as $clave => $v) {
                $forma[$clave] = $this->forma($v);
            }

            ksort($forma);

            return $forma;
        }

        if (is_null($valor)) {
            return 'null';
        }
        if (is_bool($valor)) {
            return 'bool';
        }
        if (is_int($valor)) {
            return 'int';
        }
        if (is_float($valor)) {
            return 'float';
        }

        return 'string';
    }

    /**
     * La ruta en disco del archivo que devolvió una descarga.
     *
     * Vive aquí y no en ExcelTest porque lo usan los dos lados de la misma
     * historia: el que comprueba la forma de la hoja exportada y el que la
     * vuelve a subir para comprobar que la importación la digiere.
     */
    protected function archivoDescargado(TestResponse $r): string
    {
        $respuesta = $r->baseResponse;

        $this->assertInstanceOf(BinaryFileResponse::class, $respuesta,
            'Excel::download() devuelve una BinaryFileResponse. Si esto cambia, cambió el paquete.');

        $ruta = $respuesta->getFile()->getPathname();

        $this->assertFileExists($ruta);

        return $ruta;
    }

    /**
     * Un comando de artisan, con el tipo que de verdad tiene aquí.
     *
     * `artisan()` está declarado `PendingCommand|int` y el `int` es el caso de
     * `withoutMockingConsoleOutput()`: sin salida simulada no hay nada que
     * encadenar y lo único que queda es el código de salida. Ningún test de
     * contrato la desactiva —lo que se comprueba de un comando de diagnóstico es
     * justo lo que imprime—, así que esa rama no existe de este lado. Se cierra
     * en un sitio y no en los once que encadenan `expectsOutputToContain()`.
     */
    protected function comando(string $nombre, array $parametros = []): PendingCommand
    {
        $pendiente = $this->artisan($nombre, $parametros);

        $this->assertInstanceOf(PendingCommand::class, $pendiente,
            'artisan() devolvió un int: alguien desactivó la salida simulada, y con ella lo que este test comprueba.');

        return $pendiente;
    }
}
