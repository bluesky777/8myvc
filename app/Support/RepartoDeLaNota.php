<?php

namespace App\Support;

/**
 * **Cómo reparte una nota su peso dentro de la unidad, y la unidad dentro del
 * periodo.** Un solo sitio.
 *
 * Es la **fase 0** de la Entrega 5 de
 * [28-competencias-e-indicadores.md](../../docs/migracion/28-competencias-e-indicadores.md)
 * §5.5, sobre el encargo de Joseth del 2 sep 2026: *«debería ser opcional que las
 * subunidades se manejen con porcentajes; que el colegio pueda elegir que sean
 * tratados con promedios»*.
 *
 * **Esta fase no cambia ni un resultado.** Devuelve exactamente los mismos
 * fragmentos que había escritos a mano, para que las instantáneas de contrato
 * queden verdes **sin regenerar ninguna** — que es la única prueba de que un
 * refactor de dieciséis sitios no se llevó nada por delante.
 *
 * ## Por qué esto devuelve SQL y no un número, que es lo que decide el diseño
 *
 * El plan del doc 28 dice «extraer el fragmento de cálculo a un punto único», y al
 * ir a hacerlo sale el detalle que lo gobierna todo: **los dieciséis sitios viven
 * dentro de cadenas SQL**, ninguno es una expresión PHP. No hay ningún punto del
 * código donde exista «la nota» como variable y se la multiplique por «el peso»:
 * lo multiplica MySQL, dentro de un `SUM`, sobre filas que PHP nunca ve.
 *
 * Así que un helper que devolviera un `float` no tendría a quién servirle. Lo que
 * se unifica es **el texto del fragmento**, y por eso estos métodos devuelven
 * `string` y se interpolan en la consulta.
 *
 * > **Y eso obliga a una regla que no es opcional:** lo que entra aquí por
 * > parámetro son **alias de tabla**, nunca valores de una petición. Están
 * > declarados con un valor por defecto y **los llamantes no los pasan**: si algún
 * > día hace falta pasarlos, van desde una constante del propio llamante, no desde
 * > `Request::input()`. Un alias que venga del cliente es SQL inyectado, y aquí no
 * > hay forma de ligarlo como parámetro porque **no es un valor: es sintaxis**.
 *
 * ## Las cuatro formas, que eran cuatro y parecían una
 *
 * El doc 28 las cuenta como «la fórmula `nota × porcentaje / 100`, 18 sitios». Al
 * clasificarlas salen **cuatro fragmentos distintos** que hacen cosas distintas, y
 * la diferencia importa porque el modo promedio **no las toca a todas igual**:
 *
 *   1. {@see aportacionALaDefinitiva} — 10 veces. La nota pesada por su subunidad
 *      **y** por su unidad. Es la definitiva.
 *   2. {@see notaDeLaUnidad} — 4 veces. La nota de una unidad, ya redondeada a
 *      entero.
 *   3. {@see valorDeLaNota} — 2 veces. Lo que aporta **una** nota suelta a su
 *      unidad, con un decimal. Es lo que se pinta en la rejilla.
 *   4. {@see pesoDeSubunidad} y {@see pesoDeUnidad} — 2 veces. **No calculan
 *      nada**: se proyectan tal cual para que el cliente multiplique. Es la puerta
 *      por la que la D30 hace que los cuatro clientes queden correctos sin tocar
 *      una línea de ninguno.
 *
 * ## El recuento del doc 28, que dice 18 y son 16
 *
 * Recontado el 14 sep 2026 con la misma orden que aquel día
 * —`grep -rn "porcentaje/100\|porcentaje / 100" app/`—: **18 líneas, y dos son
 * comentarios**. `SubunidadesController:240` y `UnidadesController:457` llevan la
 * fórmula escrita **en prosa**, explicando por qué `porcentaje` no es un campo
 * descriptivo.
 *
 * El reparto por fichero del doc 28 **se reproduce exacto** doce días después, así
 * que el número no ha envejecido: **nació contando líneas en vez de código**, que
 * es la misma clase de error que un censo de esta misma sesión cometió el mismo día
 * contando 42 donde había 8. *Un `grep` no distingue una fórmula de una frase que
 * habla de la fórmula.*
 *
 * **Las dos de prosa no se quedan sin arreglar**: el día que el interruptor entre,
 * lo que dicen deja de ser verdad para la mitad de los colegios. Pero son
 * documentación, no sitios que haya que enrutar por aquí — y contarlas como código
 * hace que «faltan dos» parezca un agujero cuando es una frase.
 *
 * ## Si el número de la pantalla y el guardado discrepan, **manda el guardado**
 *
 * Decisión de Joseth del 14 sep 2026: **se redondea en un solo sitio, el que
 * escribe la definitiva**, y lo que se sirve al cliente es la fracción sin
 * recortar. Eso deja una asimetría que hay que tener escrita **aquí**, porque aquí
 * es donde llega quien persiga la diferencia:
 *
 *     backend   (Σ nota) / n × %unidad      ← se calcula así y ESTO es lo que se guarda
 *     cliente   Σ (nota × 1/n × %unidad)    ← reconstrucción, con `double` de 64 bits
 *
 * Matemáticamente son la misma expresión; **en coma flotante pueden diferir en el
 * último bit**. Con notas enteras sobre 50 y dos decimales impresos no se va a ver
 * nunca — pero el día que alguien encuentre un `36,24` donde esperaba un `36,25`,
 * lo que tiene que saber es que **no es un fallo de ninguno de los dos lados: son
 * los dos caminos**.
 *
 * **Y ante la diferencia se corrige la pantalla, nunca al revés.** «Arreglar» el
 * backend para que cuadre con la celda sería mover el número bueno. La misma nota
 * está escrita del otro lado, en el docblock de `promedio-ponderado.ts` de
 * `myvc_front`, para que se encuentre por cualquiera de los dos extremos.
 */
final class RepartoDeLaNota
{
    /** Lo de siempre: cada subunidad pesa lo que diga su `porcentaje`. */
    public const PORCENTAJE = 'porcentaje';

    /** Todas pesan igual: `1/n`, con `n` las subunidades **vivas** de la unidad. */
    public const PROMEDIO = 'promedio';

    /**
     * Las subunidades vivas de la unidad a la que pertenece `$s`, como subconsulta.
     *
     * **Correlacionada a propósito, y no un `JOIN`.** Estas dieciséis consultas ya
     * agrupan y suman; meter otra tabla en el `FROM` multiplicaría filas y cambiaría
     * los `SUM` de sitios que hoy están bien. Una escalar en el `SELECT` no toca el
     * plan de agregación.
     *
     * **Nunca divide por cero**: se evalúa en una fila que YA es una subunidad de esa
     * unidad, así que la cuenta es como mínimo 1. Por eso no lleva `NULLIF` — y ponerlo
     * escondería el día que esa premisa dejara de ser cierta.
     *
     * `deleted_at IS NULL` no es opcional: es **el mismo `n`** que usa el árbol de
     * `putDetailed` al contar `$subunidades` en PHP. Si los dos denominadores
     * discreparan, el cliente pintaría un reparto y la definitiva guardaría otro —
     * que es el fallo entero que la Entrega 5 viene a evitar.
     */
    private static function cuantasSubunidades(string $s): string
    {
        return "(SELECT COUNT(*) FROM subunidades sx WHERE sx.unidad_id = {$s}.unidad_id"
            .' AND sx.deleted_at IS NULL)';
    }

    /**
     * El peso de una subunidad dentro de su unidad, **como factor entre 0 y 1**.
     *
     * `s.porcentaje` es un entero de 0 a 100 en la base; dividirlo entre 100 lo
     * convierte en el factor por el que se multiplica la nota. Se proyecta tal cual
     * —`as subunidad_porc`— en `NotasController::putDetailed`, y **eso no es un
     * detalle de implementación: es contrato**. Los cuatro clientes multiplican por
     * este número, y por eso la D30 puede cambiar el modo de evaluación sin tocar
     * ninguno.
     */
    public static function pesoDeSubunidad(string $modo = self::PORCENTAJE, string $s = 's'): string
    {
        if ($modo === self::PROMEDIO) {
            // `1.0` y no `1`: con dos enteros, MySQL haría división entera y todas las
            // subunidades pesarían 0 salvo cuando hay una sola. Sale un cero silencioso
            // en todas las notas, que es la clase de fallo de esta casa.
            return '(1.0/'.self::cuantasSubunidades($s).')';
        }

        return "{$s}.porcentaje/100";
    }

    /**
     * El peso de una unidad dentro del periodo, **como factor entre 0 y 1**.
     *
     * **No lo toca el interruptor de la Entrega 5**, y es una decisión escrita, no
     * un olvido: los porcentajes de las unidades los pone el colegio **una vez al
     * año en la plantilla**, así que no le cuestan nada al docente. Los de las
     * subunidades los teclea él en cada asignatura. *Se quita el que cuesta*
     * (doc 28 §5.5).
     */
    public static function pesoDeUnidad(string $u = 'u'): string
    {
        return "{$u}.porcentaje/100";
    }

    /**
     * Lo que una nota aporta a la **definitiva**: pesada por su subunidad y por su
     * unidad. Diez de los dieciséis sitios.
     *
     * Se devuelve **con los mismos paréntesis que tenía escrita a mano**, incluidos
     * los que sobran. No es descuido: esta fase existe para que las instantáneas
     * queden verdes sin regenerarse, y aunque el paréntesis no cambie el resultado
     * **sí cambia el texto de la consulta**, que es lo que leen los tests que miran
     * el SQL y lo que se compara al depurar una diferencia de un decimal.
     *
     * *Un refactor que «de paso» limpia la sintaxis deja de ser comprobable.*
     */
    public static function aportacionALaDefinitiva(string $modo = self::PORCENTAJE, string $u = 'u', string $s = 's', string $n = 'n'): string
    {
        return '('.self::pesoDeUnidad($u).')*(('.self::pesoDeSubunidad($modo, $s).")*{$n}.nota)";
    }

    /**
     * La nota de una unidad: la suma de sus notas pesadas, **redondeada a entero**.
     * Cuatro sitios, los cuatro en `Models\Unidad`.
     *
     * El `ROUND` sin segundo argumento es a entero, y va **fuera** del `SUM`: se
     * redondea una vez, al final. Ponerlo dentro redondearía cada sumando y el
     * resultado bailaría con el número de subunidades — que es justo el fallo que
     * la Entrega 5 tiene que evitar cuando el denominador pase a ser `1/n`.
     */
    public static function notaDeLaUnidad(string $modo = self::PORCENTAJE, string $s = 's', string $n = 'n'): string
    {
        return "ROUND(sum(({$n}.nota*".self::pesoDeSubunidad($modo, $s).')))';
    }

    /**
     * Lo que aporta **una** nota suelta a su unidad, con un decimal. Dos sitios,
     * los dos en `Models\Subunidad`.
     *
     * Es lo que la rejilla pinta debajo de la nota —«vale 3,5 de la unidad»— y por
     * eso lleva un decimal donde la de unidad lleva cero: aquí el redondeo es para
     * que quepa en la casilla, no para cerrar una cuenta.
     */
    public static function valorDeLaNota(string $modo = self::PORCENTAJE, string $s = 's', string $n = 'n'): string
    {
        return "ROUND(({$n}.nota*".self::pesoDeSubunidad($modo, $s).'), 1)';
    }
}
