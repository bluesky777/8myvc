<?php

namespace Tests\Feature;

use App\Support\ConsultasLentas;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * El registro de consultas lentas, y las dos reglas que lo hacen desplegable.
 *
 * Es el paso 3 del plan de rendimiento y va a los dieciséis colegios, así que
 * lo que importa no es que registre —eso se ve— sino lo otro: que **apagado no
 * haga nada** y que **no escriba credenciales ni datos personales**.
 *
 * Lo segundo tiene precedente en este repo: el MEDIO-8 del plan de seguridad
 * era un `Log::info($token)` que dejaba el token de sesión en texto plano en el
 * disco de cada colegio. Un registro nuevo que escriba lo que ve pasar es
 * exactamente la misma forma, y por eso lleva su test desde el primer día.
 *
 * No hace falta base de datos: el evento se dispara a mano. Lo que se comprueba
 * es la decisión de qué anotar, no que MySQL sea lento.
 */
class ConsultasLentasTest extends TestCase
{
    /** El log de mentira donde caen las anotaciones. */
    private $registro;

    public function test_apagado_no_engancha_nada(): void
    {
        config(['rendimiento.consultas_lentas.umbral_ms' => 0]);

        Log::shouldReceive('channel')->never();

        ConsultasLentas::registrar();

        $this->dispararConsulta('select * from `alumnos`', [], 5000.0);
    }

    public function test_lo_rapido_no_se_anota(): void
    {
        config(['rendimiento.consultas_lentas.umbral_ms' => 500]);

        Log::shouldReceive('channel')->never();

        ConsultasLentas::registrar();

        $this->dispararConsulta('select * from `alumnos`', [], 499.9);
    }

    public function test_lo_lento_se_anota_con_la_ruta_que_lo_pidio(): void
    {
        $this->enganchar(['umbral_ms' => 500]);

        $anotado = $this->anotar(
            'select *
               from  notas    where alumno_id = ?',
            [7],
            812.5
        );

        $this->assertSame(812.5, $anotado['ms']);

        // El SQL de este proyecto viene de cadenas PHP de varias líneas. Anotado
        // tal cual, agrupar por consulta en el informe sería imposible.
        $this->assertSame('select * from notas where alumno_id = ?', $anotado['sql']);

        // La mitad útil: MySQL sabría decir qué consulta es lenta, pero no a qué
        // pantalla ir a mirar. Aquí no hay petición HTTP, así que dice `consola`.
        $this->assertSame('consola', $anotado['origen']);
    }

    /**
     * Los valores van apagados, y ese es el valor que se despliega.
     *
     * Por las consultas de este sistema pasan nombres, documentos y fechas de
     * nacimiento de menores, y el fichero acaba en un disco compartido por
     * dieciséis colegios. Para decidir un índice basta la forma de la consulta.
     */
    public function test_los_valores_no_se_anotan_por_defecto(): void
    {
        $this->enganchar(['umbral_ms' => 500]);

        $anotado = $this->anotar('select * from `alumnos` where nombres = ?', ['Juan Pérez'], 900.0);

        $this->assertArrayNotHasKey('valores', $anotado);
        $this->assertStringNotContainsString('Juan', json_encode($anotado));
    }

    /**
     * Y encendidos, siguen sin salir las credenciales.
     *
     * Un colegio puede encenderlos un rato para reproducir un EXPLAIN. Que en
     * ese rato no caiga una contraseña o un token en el log no puede depender de
     * que quien lo encendió se acuerde de apagarlo antes del siguiente login.
     */
    public function test_ni_encendidos_escriben_credenciales(): void
    {
        $this->enganchar(['umbral_ms' => 500, 'bindings' => true]);

        $login = $this->anotar(
            'select * from `users` where `username` = ? and `password` = ?',
            ['rector', 'la-clave-de-verdad'],
            900.0
        );

        $this->assertStringNotContainsString('la-clave-de-verdad', json_encode($login));

        $token = $this->anotar(
            'select * from `personal_access_tokens` where `token` = ?',
            ['8b1c-el-secreto'],
            900.0
        );

        $this->assertStringNotContainsString('el-secreto', json_encode($token));

        // Una escritura tampoco: las contraseñas entran por INSERT y UPDATE.
        $insert = $this->anotar(
            'insert into `users` (`username`, `nueva`) values (?, ?)',
            ['nuevo', 'otra-clave'],
            900.0
        );

        $this->assertStringNotContainsString('otra-clave', json_encode($insert));

        // Y lo que sí sirve para un EXPLAIN sigue saliendo.
        $normal = $this->anotar('select * from `notas` where `alumno_id` = ?', [42], 900.0);

        $this->assertSame(['42'], array_values($normal['valores']));
    }

    public function test_un_valor_larguisimo_no_llena_el_disco(): void
    {
        $this->enganchar(['umbral_ms' => 500, 'bindings' => true]);

        $anotado = $this->anotar('select * from `notas` where `frase` = ?', [str_repeat('a', 5000)], 900.0);

        $this->assertLessThan(100, strlen(array_values($anotado['valores'])[0]));
    }

    /**
     * Engancha el registro una sola vez con la configuración dada.
     *
     * Una vez, y no una por consulta: `DB::listen` acumula, así que enganchar
     * dentro del ayudante que dispara haría que la cuarta consulta de un test
     * se anotara cuatro veces.
     */
    private function enganchar(array $configuracion): void
    {
        foreach ($configuracion as $clave => $valor) {
            config(["rendimiento.consultas_lentas.{$clave}" => $valor]);
        }

        $this->registro = new class
        {
            public array $contextos = [];

            public function info($mensaje, array $contexto = []): void
            {
                $this->contextos[] = $contexto;
            }
        };

        Log::shouldReceive('channel')->andReturn($this->registro);

        ConsultasLentas::registrar();
    }

    private function anotar(string $sql, array $valores, float $ms): array
    {
        $this->dispararConsulta($sql, $valores, $ms);

        $this->assertNotEmpty($this->registro->contextos, "No se anotó nada para: {$sql}");

        return end($this->registro->contextos);
    }

    private function dispararConsulta(string $sql, array $valores, float $ms): void
    {
        event(new QueryExecuted($sql, $valores, $ms, DB::connection()));
    }
}
