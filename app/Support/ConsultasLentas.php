<?php

namespace App\Support;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Anota las consultas que tardan más de lo que se le diga.
 *
 * Es el paso 3 del plan de rendimiento, que estaba escrito para el
 * `slow_query_log` de MySQL y no se podía hacer: los colegios viven en cuentas
 * de cPanel compartidas, sin `my.cnf` ni `SET GLOBAL`. Puesto en la aplicación
 * sí se llega, y de paso se gana lo que el registro de MySQL no da: **qué ruta
 * hizo la consulta**. Con 538 rutas y 990 consultas crudas, un SQL suelto en un
 * log no dice a quién ir a mirar.
 *
 * Lo que sale de aquí es lo que desbloquea el paso 12 —los índices—, y en ese
 * orden a propósito: el plan dice que añadir índices a ciegas en una tabla de
 * 1,16 millones de filas ralentiza las escrituras sin garantía de ganar nada.
 *
 * **Los valores de la consulta no se anotan por defecto.** Por aquí pasan
 * nombres y fechas de nacimiento de menores, y el fichero cae en un disco
 * compartido. La forma de la consulta basta para decidir un índice.
 */
class ConsultasLentas
{
    /**
     * Si la consulta menciona una de estas, sus valores no se anotan nunca,
     * aunque el colegio haya encendido `bindings`.
     *
     * Es la misma lección del MEDIO-8 del plan de seguridad, donde un
     * `Log::info($token)` dejaba el token de sesión en texto plano en el disco:
     * lo que no debería estar en un log no se filtra al leerlo, se filtra al
     * escribirlo.
     */
    private const PALABRAS_SENSIBLES = ['password', 'clave', 'token', 'secret', 'remember'];

    /** Ningún valor anotado pasa de aquí, para que un texto largo no llene el disco. */
    private const LARGO_MAXIMO = 64;

    /**
     * Engancha el registro, si el colegio lo tiene encendido.
     *
     * Con el umbral en 0 no se registra ni el listener: un colegio que no lo
     * use no paga ni la llamada al closure por consulta.
     */
    public static function registrar(): void
    {
        $umbral = (float) config('rendimiento.consultas_lentas.umbral_ms', 0);

        if ($umbral <= 0) {
            return;
        }

        DB::listen(function (QueryExecuted $consulta) use ($umbral) {
            self::anotar($consulta, $umbral);
        });
    }

    public static function anotar(QueryExecuted $consulta, float $umbral): void
    {
        if ($consulta->time < $umbral) {
            return;
        }

        Log::channel(config('rendimiento.consultas_lentas.canal', 'consultas-lentas'))
            ->info('consulta lenta', self::contexto($consulta));
    }

    /**
     * Lo que se anota de cada consulta.
     *
     * En una sola línea de JSON, para que `tools/consultas-lentas.py` pueda
     * agruparlas sin adivinar dónde acaba cada una: el SQL de este proyecto
     * viene de cadenas PHP de varias líneas, y un log de texto plano lo parte.
     */
    private static function contexto(QueryExecuted $consulta): array
    {
        $contexto = [
            'ms' => round($consulta->time, 2),
            'conexion' => $consulta->connectionName,
            'sql' => self::enUnaLinea($consulta->sql),
        ];

        $contexto += self::deLaPeticion();

        if (config('rendimiento.consultas_lentas.bindings', false)) {
            $contexto['valores'] = self::valores($consulta);
        }

        return $contexto;
    }

    /**
     * La ruta y quién la pidió.
     *
     * Es la mitad útil del registro: el SQL dice qué tabla, esto dice a qué
     * pantalla ir. Se anota el patrón de la ruta —`api/boletines/{grupo}`— y no
     * la URL, porque agrupar por URL con el id dentro da una fila por alumno.
     */
    private static function deLaPeticion(): array
    {
        if (app()->runningInConsole()) {
            return ['origen' => 'consola'];
        }

        $peticion = request();
        $ruta = $peticion->route();

        return [
            'origen' => $ruta
                ? $peticion->method().' '.$ruta->uri()
                : $peticion->method().' (sin ruta)',
            // El id del usuario, no el usuario: resolver el contexto aquí dentro
            // costaría consultas dentro del listener de las consultas.
            'usuario_id' => optional($peticion->user())->id,
        ];
    }

    /**
     * Los valores, si el colegio los encendió y la consulta no es de las que
     * llevan credenciales dentro.
     *
     * Solo de los SELECT: las contraseñas y los tokens entran por INSERT y
     * UPDATE, y un SELECT no escribe nada que no estuviera ya.
     */
    private static function valores(QueryExecuted $consulta): array
    {
        $sql = strtolower($consulta->sql);

        if (! str_starts_with(ltrim($sql), 'select')) {
            return ['(omitidos)' => 'no es una lectura'];
        }

        foreach (self::PALABRAS_SENSIBLES as $palabra) {
            if (str_contains($sql, $palabra)) {
                return ['(omitidos)' => 'la consulta menciona '.$palabra];
            }
        }

        return array_map(fn ($valor) => self::recortar($valor), $consulta->bindings);
    }

    public static function recortar($valor): string
    {
        if ($valor === null) {
            return 'null';
        }

        if (is_bool($valor)) {
            return $valor ? 'true' : 'false';
        }

        if (is_object($valor) || is_array($valor)) {
            return '('.gettype($valor).')';
        }

        $texto = (string) $valor;

        return strlen($texto) > self::LARGO_MAXIMO
            ? substr($texto, 0, self::LARGO_MAXIMO).'…'
            : $texto;
    }

    private static function enUnaLinea(string $sql): string
    {
        return preg_replace('/\s+/', ' ', trim($sql));
    }
}
