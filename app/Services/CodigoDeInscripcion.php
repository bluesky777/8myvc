<?php

namespace App\Services;

use InvalidArgumentException;

/**
 * El código que lleva impreso un formulario de inscripción.
 *
 * Forma: `2027-4K7M2X` — el año de la campaña, un guion, **cinco caracteres
 * aleatorios** y **un carácter de control**. Decidido con Joseth el 19 sep 2026
 * (`docs/migracion/41-el-formulario-de-inscripcion.md`).
 *
 * Este código no es un secreto: es **una etiqueta que viaja en papel**, que una
 * familia lee en voz alta por teléfono y que una secretaría teclea con cincuenta
 * formularios delante. Todo lo de abajo sale de esas tres frases.
 *
 * ## El alfabeto se elige por lo que NO tiene
 *
 * Fuera `O` y `0`, fuera `I`, `1` y `L`, fuera `S` y `5`. Quedan **29**
 * caracteres. No es estética: son los pares que una persona confunde leyendo un
 * papel impreso, y **quitarlos del alfabeto es la única forma de que no haya nada
 * que confundir** — corregirlos al teclear no se puede, porque no hay a qué
 * corregirlos sin adivinar.
 *
 * Con 29 caracteres y cinco posiciones son **20.511.149** combinaciones. No es
 * criptografía y no pretende serlo; es que **adivinar uno a ciegas no sale a
 * cuenta** frente al limitador, y que dos tandas impresas el mismo día no choquen.
 *
 * ## El carácter de control atrapa TODOS los errores de una persona
 *
 * Es la mitad que hace que esto valga la pena, y la propiedad es demostrable, no
 * una impresión: la suma es ponderada con pesos distintos y **módulo 29, que es
 * primo**.
 *
 * - **Un carácter mal tecleado** siempre cambia el resultado: `w·(a−b) mod 29` sólo
 *   es cero si `a = b`, porque 29 es primo y `w` nunca es múltiplo de 29.
 * - **Dos caracteres intercambiados** —el error típico de quien copia rápido—
 *   también: el cambio es `(w_i − w_j)·(a − b)`, y con pesos distintos y módulo
 *   primo eso no puede ser cero salvo que los caracteres fueran iguales, en cuyo
 *   caso no hubo error.
 *
 * Sin él, **un tecleo malo no daría un error: daría OTRO alumno**, que es el único
 * fallo de esta familia que no se ve nunca — la secretaría digitaría el formulario
 * de Pedro sobre el código de Ana y las dos cosas parecerían correctas.
 *
 * ## El año va DENTRO de la cuenta, no sólo delante
 *
 * El control se calcula sobre el año **y** los cinco caracteres. Así, el mismo
 * sufijo de otra campaña no valida, y un código de 2026 tecleado en la pantalla de
 * 2027 se cae aquí en vez de encontrar una fila que no era.
 *
 * ## Esto NO garantiza que el código no se repita
 *
 * Lo garantiza el `UNIQUE` de `ordenes_inscripcion.codigo`. Un generador
 * aleatorio puede repetir y quien lo llame tiene que estar preparado para
 * reintentar: *que la base lo rechace es una garantía; que el generador «no
 * repita» es una esperanza.*
 */
class CodigoDeInscripcion
{
    /**
     * Los 29 que quedan al quitar los que se leen mal en papel.
     *
     * El orden importa y no se toca: el valor de cada carácter es su posición, y
     * reordenarlo invalidaría **todos los códigos ya impresos**, que están en casa
     * de las familias y no se pueden reimprimir.
     */
    public const ALFABETO = '2346789ABCDEFGHJKMNPQRTUVWXYZ';

    /** Cuántos caracteres aleatorios, sin contar el de control. */
    public const LARGO = 5;

    /**
     * Los pesos del control, uno por posición.
     *
     * **Tienen que ser distintos entre sí** —es lo que atrapa las transposiciones—
     * y ninguno múltiplo de 29. Son nueve —cuatro dígitos del año más cinco del
     * sufijo— y por eso se calculan en vez de escribirse a mano: una lista literal
     * se queda corta el día que el sufijo cambie de largo, y se quedaría corta **en
     * silencio**. `posición + 2` los deja en 2..10, ninguno 0 ni 1 ni 29.
     */
    private static function peso(int $posicion): int
    {
        return $posicion + 2;
    }

    /**
     * Un código nuevo para la campaña de ese año.
     */
    public static function generar(int $year): string
    {
        $sufijo = '';

        for ($i = 0; $i < self::LARGO; $i++) {
            // `random_int` y no `rand`: no es por seguridad —esto no es un secreto—
            // sino porque `rand` con la misma semilla en dos procesos del mismo
            // segundo puede dar la misma tanda, y aquí se acuñan de cincuenta en
            // cincuenta.
            $sufijo .= self::ALFABETO[random_int(0, strlen(self::ALFABETO) - 1)];
        }

        return $year.'-'.$sufijo.self::control($year, $sufijo);
    }

    /**
     * Un código a partir de un sufijo que tecleó una persona.
     *
     * Existe para **corregir** el código de un formulario ya acuñado, que Joseth
     * pidió el 20 sep 2026. La forma es la que hace que esa corrección sea segura:
     * quien corrige escribe **los cinco caracteres y no el código**, y el carácter
     * de control lo pone esto. Por ese camino **no puede salir un código que no
     * valide**, porque la persona no escribe la parte que podría estar mal.
     *
     * Lo que sigue sin garantizar —igual que `generar`— es que no se repita: eso lo
     * dice el `UNIQUE` de la tabla, y quien llame tiene que traducir el choque a un
     * 409 en vez de a un 500.
     *
     * @throws InvalidArgumentException si el sufijo trae algo que no es del alfabeto
     */
    public static function componer(int $year, string $sufijo): string
    {
        return $year.'-'.$sufijo.self::control($year, $sufijo);
    }

    /**
     * Si un código tecleado a mano es uno de los nuestros.
     *
     * Devuelve `false` en vez de lanzar: quien llama es una ruta que tiene que
     * contestar 404 o 422, no reventar. Lo que **no** hace es decir si existe —eso
     * es una consulta— sino si **puede** existir.
     */
    public static function esValido(?string $codigo): bool
    {
        $codigo = self::normalizar($codigo);

        if ($codigo === null) {
            return false;
        }

        [$year, $sufijo, $control] = self::partir($codigo);

        return self::control($year, $sufijo) === $control;
    }

    /**
     * Lo que se teclea, puesto en la forma canónica: sin espacios y en mayúsculas.
     *
     * Devuelve `null` si no tiene la forma, que es distinto de «el control no
     * cuadra»: lo primero es que no es un código, lo segundo es que está mal
     * copiado. Las dos acaban en el mismo sitio hoy, pero el día que la pantalla
     * quiera decir «revísalo» en vez de «no existe», la diferencia ya está hecha.
     *
     * **No se intenta corregir `O` por `0` ni `I` por `1`**: esos caracteres no
     * están en el alfabeto justamente para que no haya que adivinar, y adivinar
     * aquí acabaría en el formulario de otro alumno.
     */
    public static function normalizar(?string $codigo): ?string
    {
        if ($codigo === null) {
            return null;
        }

        $codigo = strtoupper(trim($codigo));

        $largo = self::LARGO + 1;
        $clase = preg_quote(self::ALFABETO, '/');

        if (preg_match('/^(\d{4})-(['.$clase.']{'.$largo.'})$/', $codigo) !== 1) {
            return null;
        }

        return $codigo;
    }

    /**
     * El carácter de control de un año y un sufijo.
     *
     * @throws InvalidArgumentException si el sufijo trae algo que no es del alfabeto
     */
    private static function control(int $year, string $sufijo): string
    {
        $cuenta = 0;
        $posicion = 0;

        foreach (str_split((string) $year) as $digito) {
            $cuenta += self::peso($posicion++) * (int) $digito;
        }

        foreach (str_split($sufijo) as $caracter) {
            $valor = strpos(self::ALFABETO, $caracter);

            if ($valor === false) {
                throw new InvalidArgumentException("El carácter «{$caracter}» no es del alfabeto.");
            }

            $cuenta += self::peso($posicion++) * $valor;
        }

        return self::ALFABETO[$cuenta % strlen(self::ALFABETO)];
    }

    /**
     * @return array{0: int, 1: string, 2: string} año, sufijo y carácter de control
     */
    private static function partir(string $codigo): array
    {
        [$year, $resto] = explode('-', $codigo, 2);

        return [
            (int) $year,
            substr($resto, 0, self::LARGO),
            substr($resto, self::LARGO),
        ];
    }
}
