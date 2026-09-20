<?php

namespace App\Support;

/**
 * Qué se puede escribir en `users.email`, que no es lo mismo que lo que se puede
 * escribir en la ficha de una persona.
 *
 * ## Por qué esta columna tiene una regla y la de la ficha no
 *
 * `users.email` **es la llave de la recuperación de contraseña**: es la única
 * columna por la que busca `LoginController:240-266`, en cuatro consultas
 * seguidas. Y ese método contesta `Enviado` exista o no el correo —a propósito,
 * para que nadie pueda averiguar qué direcciones hay en el colegio probando una a
 * una—, así que **un valor que no es una dirección no da error: da un «Enviado»
 * que no entrega nada**, y eso no lo ve ni el usuario ni nosotros.
 *
 * `acudientes.email`, `alumnos.email` y `profesores.email` son otra cosa: un dato
 * de contacto que el colegio mira en pantalla y que puede estar como esté. **Aquí
 * no se toca la ficha**, sólo la cuenta.
 *
 * ## La regla, y por qué es tan corta
 *
 * *Una cadena que no tiene nada delante de la arroba no es una dirección.*
 *
 * No valida correos —no llama a `filter_var` ni mira el dominio— y eso es una
 * decisión, no una simplificación: esta base tiene direcciones escritas a mano
 * desde 2018 y rechazar de golpe las que un validador no apruebe convertiría un
 * arreglo en una pérdida de datos. La regla ataca **lo que se fabricó**, que es lo
 * medido, y deja en paz lo que escribió una persona.
 *
 * ## Lo que se fabricó, medido en el docker el 20 sep 2026
 *
 * Dos inventos, de dos sitios distintos y ninguno de una persona:
 *
 *     '@gmail.com'          678 cuentas vivas, todas activas
 *     'username@myvc.com'    30 cuentas vivas, 16 activas
 *
 * El primero lo mandaba el alta de la aplicación **vieja** cuando no se tecleaba
 * correo, copiando a `formatear_nuevo`; el segundo lo fabricaba el `else` de los
 * dos `sanarInputUser`, quitado ese mismo día. Ese `else` ya no existe y el front
 * nuevo ya no manda el literal — **pero `app/` no se toca por decisión de Joseth,
 * así que mientras esa pantalla siga viva seguirá llegando**. Por eso la regla vive
 * aquí, donde el dato entra, y no en quien lo manda.
 *
 * De los 853 alumnos a los que la recuperación llega, **655 llevan el literal y
 * sólo 196 tienen un correo de verdad**. *Alcanzable no es recuperable.*
 *
 * **Las que ya están escritas se quedan**, decidido por Joseth el 20 sep 2026 con
 * los dos órdenes de magnitud delante. Esto no limpia nada: impide que nazcan más.
 *
 * ## `@myvc.com` NO lo rechaza, y es a propósito
 *
 * Tiene nombre delante de la arroba, así que pasa esta regla. Rechazarlo aquí
 * habría sido meter por la puerta de atrás la limpieza de las 30 que Joseth decidió
 * dejar, y además el dominio podría existir algún día. Lo que impide que nazcan más
 * es que el `else` ya no está.
 */
final class CorreoDeLaCuenta
{
    /**
     * Devuelve la dirección lista para escribir en `users.email`, o `null`.
     *
     * `null` y no cadena vacía porque la columna es `DEFAULT NULL` y porque el
     * método del reseteo compara con `=`: una cadena vacía sería un valor que
     * alguien podría llegar a teclear.
     */
    public static function oNada(mixed $valor): ?string
    {
        if (! is_string($valor) && ! is_numeric($valor)) {
            return null;
        }

        // `TrimStrings` ya recorta todo lo que entra por HTTP (`Kernel.php:22`), así
        // que esto es para las llamadas que no vienen de una petición.
        $correo = trim((string) $valor);

        if ($correo === '') {
            return null;
        }

        // La regla entera: algo delante de la arroba. Medido el 20 sep 2026 — las
        // 678 cuentas vivas cuyo correo empieza por `@` son EXACTAMENTE las del
        // literal fabricado, así que hoy esto no rechaza ni una dirección escrita
        // por una persona.
        if (str_starts_with($correo, '@')) {
            return null;
        }

        return $correo;
    }
}
