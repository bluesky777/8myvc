<?php

namespace App\Support;

use Illuminate\Support\Facades\Config;

/**
 * La firma de la hoja oculta `_myvc` del libro de notas sin internet.
 *
 * HMAC-SHA256 con `APP_KEY`, sobre la forma canónica de lo que se escribió en el
 * libro. Es la §4.6 del plan
 * (`myvc_front/PLAN-NOTAS-SIN-INTERNET.md`) y sólo tiene dos verbos, porque
 * quien la usa sólo hace dos cosas: {@see firmar} al descargar y
 * {@see comprobar} al subir (fase 2).
 *
 * ## Para qué está, que NO es para impedir nada
 *
 * El docente puede abrir el `.zip`, editar la hoja oculta y volver a cerrarlo, y
 * eso no se puede evitar: la protección de PhpSpreadsheet se salta cambiando la
 * extensión. La firma **no está para impedirlo: está para saberlo**.
 *
 * La razón es el espejo de las notas. La D3 —«sólo entra lo que el docente
 * cambió»— se decide comparando tres valores: lo que había al descargar (el
 * espejo), lo que hay hoy en la base y lo que trae el archivo. Si el espejo está
 * tocado, esa comparación **sigue funcionando y miente**: da por «no tocada» una
 * celda que sí se tocó, o al revés, y nadie lo nota. Con la firma rota el
 * asistente **degrada al peldaño 2** de la §4.7 —todas las celdas con valor
 * cuentan como cambiadas y hay que confirmar la lista— en vez de aplicar en
 * silencio una comparación mentirosa.
 *
 * O sea que lo que esto protege no es la nota: es **la diferencia**.
 *
 * ## Por qué la forma canónica y no el texto de las celdas
 *
 * Firmar «lo que pone en la celda» ataría la firma al formato exacto con que
 * PhpSpreadsheet serializa un `json_encode`, al orden en que PHP recorrió un
 * array asociativo y a si un id vino de MySQL como `"301"` o como `301`. Las
 * tres cosas cambian solas —PDO devuelve los enteros como cadenas, y basta un
 * `SELECT` reordenado para que las claves salgan en otro orden— y entonces un
 * libro correcto tendría la firma rota **sin que nadie hubiera tocado nada**,
 * que es el peor fallo posible aquí: una alarma que salta sola deja de leerse.
 *
 * Por eso {@see canonico} ordena las claves de cada nivel y normaliza los tipos
 * antes de serializar. Dos ejecuciones con los mismos datos firman igual aunque
 * los arrays vengan en distinto orden.
 *
 * ## La clave: `APP_KEY`, y hay que decodificarla
 *
 * Laravel guarda la clave como `base64:…`. `config('app.key')` devuelve **la
 * cadena con el prefijo**, no los bytes: es el `EncryptionServiceProvider` quien
 * los decodifica al construir el `Encrypter`. Firmar con la cadena de texto
 * también «funciona» —HMAC acepta cualquier cadena— pero deja la firma atada a
 * una representación y no a la clave, así que aquí se decodifica igual que hace
 * el framework. Si algún día un colegio tuviera la clave sin prefijo, se usa tal
 * cual, que es exactamente lo que hace Laravel.
 *
 * **Y no es un secreto compartido con nadie**: la clave no sale del servidor, y
 * la comprobación es siempre del lado del servidor. El libro sólo lleva el
 * resultado.
 *
 * ## Una clave por colegio, y eso es una propiedad, no un descuido
 *
 * Cada uno de los dieciséis colegios tiene su `.env` y su `APP_KEY`, así que un
 * libro firmado en un colegio **no valida en otro**. Es justo lo que la familia
 * F1 del plan («el libro no es de aquí») necesita: la primera criba la hace la
 * firma, sin tener que leer un identificador de colegio de dentro del libro y
 * fiarse de él.
 */
final class FirmaDelLibro
{
    /**
     * El número de formato de lo que se firma.
     *
     * Va **dentro** de los datos firmados y no al lado: si algún día cambia lo
     * que entra en la firma, un libro viejo tiene que fallar la comprobación con
     * un motivo legible —«formato 1, y aquí se espera el 2»— en vez de fallar
     * como «firma rota», que manda a buscar un manipulador que no existe.
     */
    public const FORMATO = 1;

    /** El algoritmo, escrito una vez. No es configurable a propósito. */
    private const ALGORITMO = 'sha256';

    /**
     * La firma de unos datos, en hexadecimal.
     *
     * @param  array<array-key, mixed>  $datos  lo que va en la hoja `_myvc`, sin la propia firma
     */
    public static function firmar(array $datos): string
    {
        return hash_hmac(self::ALGORITMO, self::canonico($datos), self::clave());
    }

    /**
     * ¿Es ésta la firma de estos datos?
     *
     * `hash_equals` y no `===`: la comparación de cadenas de PHP se corta en el
     * primer byte distinto, y eso deja medir por tiempo cuántos bytes iniciales
     * acertó un intento. Aquí el atacante tendría que ser alguien del colegio con
     * un libro en la mano y la ganancia sería poder mentir sobre qué celdas tocó,
     * así que no es el fin del mundo — pero la función correcta cuesta lo mismo
     * que la incorrecta y **es la que no hay que volver a mirar**.
     *
     * @param  array<array-key, mixed>  $datos
     */
    public static function comprobar(array $datos, string $firma): bool
    {
        return hash_equals(self::firmar($datos), $firma);
    }

    /**
     * La forma canónica: claves ordenadas en todos los niveles y tipos normalizados.
     *
     * **Los enteros se normalizan y las notas no se convierten a cero.** Una nota
     * sin calificar es `null` desde `2026_09_19_500000_la_casilla_vacia`, y `null`
     * y `0` tienen que firmar distinto: si firmaran igual, un libro descargado con
     * la casilla vacía validaría después de que alguien le escribiera un cero en
     * el espejo, que es exactamente la manipulación que esto detecta.
     *
     * `JSON_UNESCAPED_UNICODE` porque las definiciones de los indicadores llevan
     * tildes y eñes, y el escapado `\uXXXX` de PHP no es estable entre versiones
     * para todos los puntos de código.
     *
     * @param  array<array-key, mixed>  $datos
     */
    private static function canonico(array $datos): string
    {
        $ordenados = self::ordenar(['formato' => self::FORMATO, 'datos' => $datos]);

        return (string) json_encode(
            $ordenados,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
        );
    }

    /**
     * Ordena recursivamente por clave y normaliza escalares.
     *
     * Las listas (claves `0..n` seguidas) **conservan su orden**: en una lista el
     * orden es dato —los indicadores van numerados por él— y ordenarla sería
     * borrar información. Sólo se ordenan los mapas.
     */
    private static function ordenar(mixed $valor): mixed
    {
        if (is_array($valor)) {
            $esLista = array_keys($valor) === range(0, count($valor) - 1);

            if (! $esLista) {
                ksort($valor, SORT_STRING);
            }

            return array_map(static fn ($v) => self::ordenar($v), $valor);
        }

        // PDO devuelve los enteros de MySQL como cadenas, así que `"301"` y `301`
        // llegan aquí según por qué camino vino el dato. Sin esto, el mismo libro
        // firmaría distinto según la consulta que lo trajo.
        if (is_string($valor) && $valor !== '' && preg_match('/^-?[0-9]{1,15}$/', $valor) === 1) {
            return (int) $valor;
        }

        return $valor;
    }

    /**
     * Los bytes de `APP_KEY`, decodificados igual que hace el framework.
     */
    private static function clave(): string
    {
        $clave = (string) Config::get('app.key');

        if (str_starts_with($clave, 'base64:')) {
            $bytes = base64_decode(substr($clave, 7), true);

            // Un base64 inválido devuelve `false`; entonces vale más firmar con la
            // cadena tal cual que firmar con una cadena vacía, que es lo que haría
            // un cast silencioso — y una clave vacía es una firma que cualquiera
            // puede reproducir.
            if ($bytes !== false && $bytes !== '') {
                return $bytes;
            }
        }

        return $clave;
    }
}
