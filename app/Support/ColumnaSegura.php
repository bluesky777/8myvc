<?php

namespace App\Support;

use Illuminate\Support\Facades\Schema;

/**
 * Nombres de columna que llegan del cliente.
 *
 * Hay un patrón repetido por todo el sistema: pantallas que guardan un campo suelto mandando
 * {propiedad, valor} y un controlador que arma
 *
 *     UPDATE <tabla> SET '.$propiedad.'=:valor WHERE id=:id
 *
 * El valor va parametrizado, pero el NOMBRE DE LA COLUMNA se concatena tal cual. Un nombre de
 * columna no se puede parametrizar en SQL, así que la única defensa posible es validarlo antes, y
 * en diez sitios no se validaba: bastaba mandar una `propiedad` con SQL dentro para escribir lo que
 * se quisiera en la fila. Cualquier usuario con sesión llegaba.
 *
 * Tres de los sitios que comparten esta forma sí estaban a salvo, porque la propiedad venía
 * restringida por un switch con casos literales. Esos no se tocan: lo que hace segura una
 * concatenación es que el valor no lo elija el cliente, y ahí no lo elige.
 *
 * LA COMPROBACIÓN SE HACE CONTRA EL ESQUEMA REAL, no contra una lista escrita a mano. Varias de
 * estas pantallas son rejillas donde la propiedad sale de la definición de la columna en tiempo de
 * ejecución, así que una lista blanca a mano se quedaría corta el día que alguien añada un campo, y
 * el arreglo de seguridad se convertiría en una avería. Preguntándole al esquema, toda columna que
 * existe de verdad sigue funcionando y nada más pasa.
 */
class ColumnaSegura
{
    /**
     * Columnas que este tipo de endpoint no debe escribir nunca, aunque existan.
     *
     * id y las de auditoría las pone el sistema; deleted_at es el borrado lógico y dejar que se
     * escriba por aquí convierte un "guardar un campo" en un borrado o en una resurrección.
     */
    public const PROHIBIDAS = [
        'id',
        'created_at', 'updated_at', 'deleted_at',
        'created_by', 'updated_by', 'deleted_by',
    ];

    /** Listados de columnas ya consultados, por tabla. Evita repetir la consulta al esquema. */
    private static $columnasPorTabla = [];

    /**
     * ¿Tiene forma de identificador de columna y no es una de las prohibidas?
     *
     * Es puro a propósito -- sin base de datos -- para poder fijar en una prueba los intentos de
     * inyección concretos sin montar un esquema.
     */
    public static function nombreValido($columna): bool
    {
        if (! is_string($columna)) {
            return false;
        }

        if (in_array(strtolower(trim($columna)), self::PROHIBIDAS, true)) {
            return false;
        }

        // Sin comillas, espacios, paréntesis, comas ni punto y coma: nada con lo que salir del
        // nombre y seguir escribiendo SQL.
        return (bool) preg_match('/^[a-zA-Z][a-zA-Z0-9_]*$/', $columna);
    }

    /**
     * ¿Es una columna que existe de verdad en esa tabla y se puede escribir?
     */
    public static function valida(string $tabla, $columna): bool
    {
        if (! self::nombreValido($columna)) {
            return false;
        }

        if (! isset(self::$columnasPorTabla[$tabla])) {
            self::$columnasPorTabla[$tabla] = array_map(
                'strtolower',
                Schema::getColumnListing($tabla)
            );
        }

        return in_array(strtolower($columna), self::$columnasPorTabla[$tabla], true);
    }

    /**
     * Valida y corta la petición si no pasa. Devuelve el nombre entre acentos graves, listo para
     * concatenar.
     *
     * Que devuelva el nombre ya citado es deliberado: así el sitio de llamada escribe
     * `SET '.ColumnaSegura::exigir($tabla, $prop).'=:valor` y no hay forma de validar y luego
     * concatenar la variable sin validar por descuido.
     */
    public static function exigir(string $tabla, $columna): string
    {
        if (! self::valida($tabla, $columna)) {
            abort(422, 'Propiedad no válida.');
        }

        return '`'.$columna.'`';
    }

    /** Sólo para pruebas: olvida los listados consultados. */
    public static function olvidarCache(): void
    {
        self::$columnasPorTabla = [];
    }
}
