<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

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
 * ### Y una quinta desde el 20 sep 2026, que no sale de las dieciséis
 *
 * {@see pesoDeLaNota} — **0 veces en el código viejo**, porque hasta la fase 1 del
 * [43](../../docs/migracion/43-lo-que-todavia-no-se-ha-calificado.md) el peso de una
 * casilla **no se sumaba nunca por separado**: la definitiva es `Σ (peso × nota)`
 * dando por hecho que el divisor es 1, y por eso una asignatura mal repartida da una
 * nota rara en vez de un aviso. La parcial y la cobertura necesitan ese divisor, así
 * que el fragmento pasa a existir — y vive aquí, no en la consulta, por lo mismo que
 * los otros cuatro: **el modo promedio lo cambia**, y una copia a mano en
 * `DefinitivasDeAsignatura` sería la decimoséptima.
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
     * Qué reparto tiene puesto ese año. **Ante la duda, `porcentaje`.**
     *
     * El defecto no es prudencia genérica: `porcentaje` **es el comportamiento de
     * hoy**, así que un año que no se encuentra, un id que no llega o un valor que
     * no está en el `enum` dejan la consulta exactamente como estaba antes de esta
     * entrega. Lo contrario —caer en `promedio` por no saber— cambiaría notas sin
     * que nadie lo hubiera pedido.
     *
     * Sin caché: es una fila por clave primaria y estas consultas se montan una vez
     * por petición. Una caché estática se llevaría por delante los tests, que
     * cambian la columna dentro del mismo proceso.
     */
    public static function modoDelAnio($yearId): string
    {
        if (! is_numeric($yearId) || (int) $yearId <= 0) {
            return self::PORCENTAJE;
        }

        $fila = DB::selectOne('SELECT reparto_subunidades FROM years WHERE id = ?', [(int) $yearId]);

        if ($fila === null || ! in_array($fila->reparto_subunidades, [self::PORCENTAJE, self::PROMEDIO], true)) {
            return self::PORCENTAJE;
        }

        return $fila->reparto_subunidades;
    }

    /**
     * El mismo, cuando lo que hay a mano es el periodo y no el año.
     *
     * Pasa en `NotaFinal::calcularAsignaturaPeriodo`, que recibe `$periodo_id` y
     * nunca ha necesitado el año para nada más.
     */
    public static function modoDelPeriodo($periodoId): string
    {
        if (! is_numeric($periodoId) || (int) $periodoId <= 0) {
            return self::PORCENTAJE;
        }

        $fila = DB::selectOne('SELECT year_id FROM periodos WHERE id = ?', [(int) $periodoId]);

        return self::modoDelAnio($fila->year_id ?? null);
    }

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
     * El mismo peso pero **para pintar**: de 0 a 100 y con dos decimales.
     *
     * Es `subunidad_porcentaje`, y el front midió quién lo lee: **cero en `app2`,
     * cero en `myvc_flutter`, y un solo sitio en la app vieja** —
     * `notas.html:108`, el tooltip «40 % Taller» al pasar el ratón por la casilla—.
     * O sea que **nadie calcula con él**: es un rótulo.
     *
     * Y por eso es el único que se redondea aquí. Joseth decidió el 14 sep 2026
     * redondear **en un solo sitio, el que escribe la definitiva**, y esto no
     * contradice esa regla: un rótulo no entra en ninguna cuenta. Sin recortar
     * pintaría `33.3333333333 %`.
     *
     * **Pero convertirlo no es opcional.** Sin esto, el docente de la app vieja
     * leería al pasar el ratón un porcentaje **que ya no gobierna nada** — y eso es
     * peor que un número raro, porque es creíble.
     */
    public static function porcentajeParaPintar(string $modo = self::PORCENTAJE, string $s = 's'): string
    {
        if ($modo === self::PROMEDIO) {
            return 'ROUND(100.0/'.self::cuantasSubunidades($s).', 2)';
        }

        return "{$s}.porcentaje";
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
     * **Lo que pesa una casilla en el periodo**, sin la nota: el divisor que la
     * definitiva de hoy da por hecho que vale 1.
     *
     * Es {@see aportacionALaDefinitiva} sin el último factor, y sirve para las dos
     * cuentas de la fase 1 del
     * [43](../../docs/migracion/43-lo-que-todavia-no-se-ha-calificado.md):
     *
     *     parcial    = Σ (peso × nota) de las CALIFICADAS ÷ Σ peso de las CALIFICADAS
     *     cobertura  = Σ peso de las CALIFICADAS          ÷ Σ peso de TODAS
     *
     * ## Por qué NO se factoriza fuera de `aportacionALaDefinitiva`
     *
     * Sería la escritura natural —`aportacion = peso × nota`— y **cambiaría el texto
     * de la consulta**: hoy sale `(u.porcentaje/100)*((s.porcentaje/100)*n.nota)` y
     * factorizado saldría `((u.porcentaje/100)*(s.porcentaje/100))*n.nota`. El
     * resultado numérico es el mismo y el texto no, y ese texto es contrato: lo leen
     * `GuardarNotasEnLoteTest` y `CosteDelLoteDeNotasTest` para **contar cuántas veces
     * se agregó**, casando por cadena. El docblock de `aportacionALaDefinitiva` ya
     * avisa de que hasta los paréntesis que sobran están ahí a propósito.
     *
     * Así que los dos fragmentos comparten los dos factores y **no se comparten entre
     * sí**. Lo que los ata es que los dos salen de {@see pesoDeUnidad} y
     * {@see pesoDeSubunidad}: el día que el reparto cambie, cambian los dos a la vez,
     * que es lo único que esta clase tiene que garantizar.
     *
     * ## Lo que cuesta, medido — y sólo cuesta en UN modo
     *
     * En `porcentaje` esto es aritmética sobre filas que la consulta ya recorre:
     * medido el 20 sep 2026 sobre `simonbolivar`, en la asignatura más cargada de la
     * copia (986 notas, 45 alumnos), las filas leídas son **idénticas** antes y
     * después — `Handler_read_key` 1.024 y `Handler_read_next` 1.665 las dos veces—.
     * **Coste cero**, y es el modo de ocho de los nueve años de la copia.
     *
     * En `promedio` no: este fragmento arrastra la subconsulta correlacionada de
     * {@see cuantasSubunidades}, que pasa de evaluarse **una vez por fila a tres**
     * —la aportación más los dos pesos—. Sobre la misma asignatura,
     * `Handler_read_key` **2.010 → 3.982** (×1,98) y `Handler_read_next`
     * **5.707 → 13.791** (×2,42); en reloj, **8,6 ms → 17,7 ms** por consulta
     * (medianas de 20 pasadas en seis bloques alternados, descontados los 6,7 ms de
     * arranque del cliente). **Un año de la copia ya está en `promedio`**, así que no
     * es hipotético.
     *
     * De los ms, lo que vale es **la razón y no el valor**: se midieron con tres suites
     * de otras sesiones corriendo en el mismo contenedor, así que los absolutos están
     * inflados. Los contadores no dependen de la carga, y por eso son la cifra.
     *
     * **Bajar de ahí no es de esta fase**: haría falta sustituir la correlacionada por
     * un agregado unido en el `FROM`, y eso cambia el texto de
     * {@see aportacionALaDefinitiva}, que es contrato. Se deja medido, no resuelto.
     */
    public static function pesoDeLaNota(string $modo = self::PORCENTAJE, string $u = 'u', string $s = 's'): string
    {
        return '('.self::pesoDeUnidad($u).')*('.self::pesoDeSubunidad($modo, $s).')';
    }

    /*
     * ── AQUÍ VIVÍA `notaDeLaUnidad()`, Y SE BORRÓ EL 22 SEP 2026 ──────────────────
     *
     * Devolvía `ROUND(sum(n.nota * <pesoSub>))` y era la nota de un criterio calculada
     * dentro de la consulta. La misma cuenta estaba escrita **también en PHP**, en
     * `Asignatura::calculoAlumnoNotas`, y el día que la casilla vacía dejó de contar sólo
     * se enteró una de las dos: dos sitios, dos verdades.
     *
     * Ahora la hace {@see \App\Support\LaParcialYLaCobertura::deSusSubunidades} y la usa
     * `Unidad::deAsignaturaCalculada`, que trae las subunidades y suma en PHP. Encargo de
     * Joseth, con su motivo: en SQL el modo `promedio` obliga a contar las subunidades
     * vivas con una subconsulta correlacionada por fila, y en PHP es un `count()`.
     *
     * **Se borra en vez de dejarla muerta** porque un helper que sigue ahí es un helper
     * que alguien vuelve a llamar, y volvería a partir la fórmula en dos idiomas. Que las
     * dos daban el mismo número está medido: **289.963 pares (unidad, alumno) de la copia
     * de desarrollo, 0 discrepancias** — y las 4 que hubo antes de afinarlo eran el `/100`
     * dentro del bucle, que en binario deja un 47,4999… donde MySQL pone 47,5.
     */

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
