<?php

namespace App\Support;

use Carbon\Carbon;
use Tests\Contrato\RelojUnicoTest;

/**
 * La hora que se escribe en la base. Un solo sitio, a propósito.
 *
 * Existe porque `bitacoras.created_at` tiene **dos relojes dentro y nada en la
 * fila que diga cuál es cuál**: medido el 24 ago 2026, **12 filas escritas en UTC
 * contra 74 en Bogotá**, cinco horas de diferencia en la misma columna. Ordenar
 * por esa columna no da una línea de tiempo, y de ahí salía el «salen horas
 * extrañas» que originó [docs/migracion/18-auditoria.md](../../docs/migracion/18-auditoria.md).
 *
 * No es un envoltorio de `Carbon` por gusto: es **el sitio donde la decisión está
 * tomada una vez**. Antes de esto la decisión se tomaba en 134 sitios —118 con la
 * zona a mano y 16 sin ella— y bastaba con olvidarla una vez para meter una fila
 * cinco horas movida que nadie iba a distinguir después.
 *
 * ## La zona, y por qué es Bogotá y no UTC
 *
 * Decisión de Joseth, 24 ago 2026 ([18](../../docs/migracion/18-auditoria.md), decisión 1):
 *
 * - **Colombia no tiene horario de verano.** La conversión que UTC compra no hay
 *   que pagarla aquí, y la que se ahorra es la de cada pantalla y cada informe.
 * - **Es lo que ya hacen 118 sitios.** Unificar hacia la minoría habría
 *   reescrito el 88% del código para conseguir lo mismo.
 * - **Se lee bien en phpMyAdmin**, que es donde un colegio mira cuando reclama.
 *
 * Y **no se cambió `config/app.php`** (decisión 2). Sigue en UTC, así que
 * `now()` y `Carbon::now()` siguen dando UTC: mover la configuración habría
 * desplazado de golpe las expiraciones de sesión, los `jobs` y las cachés, y eso
 * es un cambio con su propia medición. Aquí se separa lo que **se guarda** —que
 * pasa por esta clase— de lo que **se compara consigo mismo**, que puede seguir
 * en UTC mientras sea coherente.
 *
 * ## Lo que hay que escribir en columnas `DATETIME`, no `TIMESTAMP`
 *
 * Esto arregla la mitad del problema. La otra mitad es del esquema: una columna
 * `TIMESTAMP` **convierte** al escribir y al leer usando la zona de la sesión de
 * MySQL, y `config/database.php` no la fija (`@@session.time_zone = SYSTEM`), así
 * que hereda la del servidor — dieciséis cuentas de cPanel distintas. Un
 * `DATETIME` no convierte: lo que escribe esta clase es lo que se lee. Por eso
 * `auditoria.ocurrido_en` es `DATETIME(3)` y no `TIMESTAMP`.
 *
 * @see RelojUnicoTest  el centinela que impide que vuelvan los dos relojes
 */
final class Reloj
{
    /**
     * La zona en la que este sistema guarda las fechas. No se lee de la
     * configuración a propósito: si saliera de `config/app.php` volvería a poder
     * cambiar sin que nadie lo notara, que es exactamente de lo que venimos.
     */
    public const ZONA = 'America/Bogota';

    /**
     * La hora de ahora, para escribirla en la base.
     *
     * **Todo lo que se guarde sale de aquí.** Si hace falta la hora para otra
     * cosa —una diferencia, un TTL de caché, una expiración que sólo se compara
     * consigo misma— no hace falta esta clase y `Carbon::now()` está bien; lo que
     * no puede pasar es que una de esas dos acabe en una columna.
     */
    public static function ahora(): Carbon
    {
        return Carbon::now(self::ZONA);
    }

    /**
     * Lo mismo, ya en el texto que espera un parámetro de consulta.
     *
     * Existe porque los 118 sitios que ya escriben la zona a mano pasan el
     * `Carbon` directo al `DB::insert`, y dejarlo así obliga a acordarse del
     * formato. Con milisegundos, que es lo que `auditoria.ocurrido_en` guarda:
     * dos notas tecleadas en el mismo segundo son dos líneas distintas del
     * historial y con precisión de segundo no se sabe cuál fue primero.
     */
    public static function ahoraTexto(): string
    {
        return self::ahora()->format('Y-m-d H:i:s.v');
    }

    /**
     * El camino de vuelta: leer una de estas columnas sin equivocarse de zona.
     *
     * **Faltaba, y la trampa que deja es peor que la de ida.** Lo encontró
     * `8myvc-39` el 24 ago con una diferencia de **17.999 segundos** —las cinco
     * horas, al segundo— en un test que leía `auditoria.ocurrido_en` con
     * `strtotime()`.
     *
     * El porqué es que **las decisiones 1 y 2 del [18](../../docs/migracion/18-auditoria.md),
     * que son correctas por separado, dejan juntas un hueco en la vuelta:**
     *
     * - La 1 guarda **hora de pared de Bogotá** en un `DATETIME`, que es lo que
     *   hace que lo escrito sea lo leído en phpMyAdmin y en los dieciséis.
     * - La 2 deja `config/app.php` en **UTC**.
     * - Y una cadena `'2026-08-24 03:51:13.000'` **no lleva la zona dentro**.
     *
     * Así que cualquier PHP que la lea sin decir la zona —`strtotime()`,
     * `Carbon::parse()`, `new Carbon($fila->columna)`— **la interpreta como UTC y
     * la mueve cinco horas**, y devuelve algo que parece una fecha correcta. El
     * mismo argumento que justifica `ahoraTexto()` vale doble aquí: existe para
     * que no haya que acordarse del formato, y quien lee tenía que acordarse del
     * formato **y además de la zona**.
     *
     * Importa sobre todo para la **fase 5**, que va a leer `ocurrido_en` en cuatro
     * endpoints: un ingreso pintado cinco horas movido, en la pantalla que se pidió
     * justamente porque «salen horas extrañas», sería el peor sitio para repetirlo.
     *
     * Devuelve `null` si el texto no tiene la forma esperada, en vez de inventar
     * una fecha: una columna vacía o corrupta no debe convertirse en una hora
     * plausible.
     */
    public static function desdeTexto(?string $texto): ?Carbon
    {
        if ($texto === null || $texto === '') {
            return null;
        }

        // Con y sin milisegundos: `ocurrido_en` es DATETIME(3), pero las columnas
        // viejas que esto también lee son DATETIME a secas.
        //
        // Con `try`/`catch` y no con `!== false`: esta versión de Carbon **lanza**
        // `InvalidFormatException` en vez de devolver `false` como hacía el
        // `DateTime` de PHP. Escrito con la comprobación de `false` —que es lo que
        // dice media internet— un texto corrupto no devolvía `null`: reventaba la
        // petición entera con un 500.
        foreach (['Y-m-d H:i:s.v', 'Y-m-d H:i:s'] as $formato) {
            try {
                return Carbon::createFromFormat($formato, $texto, self::ZONA);
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }
}
