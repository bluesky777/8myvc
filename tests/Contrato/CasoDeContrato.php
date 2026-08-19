<?php

namespace Tests\Contrato;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Routing\Route;
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

        if (! preg_match('/_(testing|test)$/', (string) $base)) {
            $this->fail(
                "Los tests apuntan a la base '{$base}', que no acaba en _testing.\n".
                "Revisa DB_CONNECTION en phpunit.xml. Debe ser 'mysql_testing'."
            );
        }

        if (\DB::table('users')->count() === 0) {
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

        $filas = \DB::select(
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
     */
    protected function compararConInstantanea(string $nombre, array $real): void
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
}
