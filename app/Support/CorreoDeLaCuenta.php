<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

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

    /**
     * Cuando se guarda el correo de la FICHA de un alumno o un docente, lleva el
     * mismo a su CUENTA si la cuenta no tenía uno propio.
     *
     * Decisión de Joseth del 24 sep 2026 (PLAN-COSAS-PENDIENTES §2.6): *el correo
     * que cuenta es `users.email`*, el único que lee la recuperación. La ficha y la
     * cuenta son dos columnas que nadie sincronizaba, así que un correo corregido en
     * la ficha no llegaba nunca al reseteo.
     *
     * «No tenía uno propio» son dos casos, y sólo esos:
     *
     *   - la cuenta estaba **vacía**, o
     *   - tenía **el mismo que la ficha de antes** — iban juntas, y cambiar una sin
     *     la otra las separaría.
     *
     * Si la cuenta tenía otro, alguien lo puso ahí a propósito y no se toca. Y si el
     * cliente mandó `email2` distinto del que tenía la cuenta, **editó la cuenta** en
     * esta misma petición: gana eso, también cuando la vació. `AlumnosEditCtrl.ts:122`
     * devuelve el `email2` que leyó en cada guardado, así que un `email2` igual al de
     * antes es un eco, no una decisión.
     *
     * No vacía nunca la cuenta: si la ficha queda vacía o con algo que no es una
     * dirección (`oNada`), la cuenta se queda como estaba.
     *
     * Las colisiones se saltan, igual que en las dos migraciones: `LoginController:240`
     * se queda con la primera fila, así que un correo repetido le manda el enlace a
     * uno de los dos y el otro no se entera.
     *
     * @param  mixed  $cuentaAntes  el `users.email` de antes de esta petición; `false`
     *                              si la petición no ha tocado la cuenta y se puede
     *                              leer ahora
     * @param  mixed  $email2QueVino  el `email2` que mandó el cliente, tal cual y antes
     *                                de ningún `sanarInput*`; `null` si no vino
     * @return string qué pasó — `copiado`, `sin_cuenta`, `no_es_correo`,
     *                `cuenta_propia`, `el_cliente_la_edito`, `ya_estaba` o `colision`
     */
    public static function seguirALaFicha(mixed $userId, mixed $fichaAntes, mixed $fichaNueva, mixed $cuentaAntes = false, mixed $email2QueVino = null): string
    {
        $nuevo = self::oNada($fichaNueva);

        if ($nuevo === null) {
            return 'no_es_correo';
        }

        $cuenta = $userId ? DB::selectOne('SELECT email FROM users WHERE id = ? AND deleted_at IS NULL', [$userId]) : null;

        if ($cuenta === null) {
            return 'sin_cuenta';
        }

        $antes = trim((string) ($cuentaAntes === false ? $cuenta->email : $cuentaAntes));
        $fichaDeAntes = trim((string) $fichaAntes);

        if ($antes !== '' && $antes !== $fichaDeAntes) {
            return 'cuenta_propia';
        }

        if ($email2QueVino !== null && trim((string) $email2QueVino) !== $antes) {
            return 'el_cliente_la_edito';
        }

        if (trim((string) $cuenta->email) === $nuevo) {
            return 'ya_estaba';
        }

        $ocupado = DB::selectOne(
            'SELECT COUNT(*) AS n FROM users WHERE deleted_at IS NULL AND id <> ? AND email = ?',
            [$userId, $nuevo]
        );

        if ((int) $ocupado->n > 0) {
            return 'colision';
        }

        DB::update('UPDATE users SET email = ?, updated_at = ? WHERE id = ?', [$nuevo, Carbon::now('America/Bogota'), $userId]);

        return 'copiado';
    }
}
