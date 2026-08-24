<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Que una nota quepa en la escala del colegio. Antes no lo comprobaba nadie.
 *
 * Medido el 24 ago 2026: hay **diez** sitios en `app/` que comparan una nota con
 * `escalas_de_valoracion.porc_inicial`/`porc_final`, y **los diez son para pintar
 * la banda** —SUPERIOR, ALTO, BÁSICO—. Ninguno rechaza. En todo el proyecto hay
 * 2 validaciones y ninguna era ésta, así que **el único guardián de la escala era
 * el navegador**: `Request::input('nota')` iba directo a la columna.
 *
 * Y no era teórico. En esta base, con escala **de 0 a 50**, hay **92 notas fuera
 * de rango**: 65 con un `100`, 24 con `95`, dos con `78` y una con `89`. Son
 * notas tecleadas como si la escala fuera de 100. Todas en los años 1 a 5;
 * **cero en los cuatro más recientes**, que es lo que hizo razonable ponerlo
 * ahora: cierra la puerta sin romper nada vivo.
 *
 * Lo destapó el front al encontrar un `[nzMax]="100"` escrito a mano en una de
 * sus tres pantallas hermanas —las otras dos sí guardan—. Arreglar sólo esa
 * pantalla dejaba el sistema cubierto **por costumbre y no por diseño**: la
 * cuarta pantalla, un `curl`, o un teléfono con una versión vieja de la app se lo
 * saltan igual. Ver [18 §4.5.1](../../docs/migracion/18-auditoria.md).
 *
 * ## La decisión que importa: si no se sabe la escala, NO se bloquea
 *
 * `maximo()` devuelve `null` cuando el año no tiene escala configurada, y
 * entonces `comprobar()` **deja pasar**. Es deliberado y va en contra del
 * instinto:
 *
 * - Rechazar todo cuando falta la configuración convierte **un hueco de
 *   configuración en una caída de las notas para un colegio entero**. Un año sin
 *   escalas sembradas —que pasa, los crea el propio colegio— dejaría a sus
 *   profesores sin poder calificar.
 * - Dejar pasar devuelve exactamente el comportamiento de hoy, que es malo pero
 *   conocido. De los dos fallos, éste no es peor que el estado actual y el otro
 *   sí.
 *
 * **Y no se inventa un máximo por defecto**, que es la tercera opción y la peor:
 * el front tiene un `escalaMaxima() ?? 100` que hace justo eso, y en un colegio
 * de 0 a 50 **afloja el límite al doble** en vez de apretarlo. Un valor inventado
 * es peor que ninguno porque parece una comprobación.
 */
final class EscalaDeNotas
{
    /**
     * Máximos ya resueltos en esta petición, por año.
     *
     * `putLote` valida hasta 200 notas seguidas y casi siempre son del mismo
     * año: sin esto serían 200 consultas idénticas dentro de la misma petición.
     *
     * @var array<int, ?int>
     */
    private static array $cache = [];

    /**
     * El techo de la escala de un año, o `null` si ese año no tiene escala.
     *
     * Sale de `MAX(porc_final)` y no de la banda más alta por `orden`: el orden
     * es de presentación y nada garantiza que la de arriba sea la del número
     * mayor. Lo que se está comprobando es qué números caben.
     */
    public static function maximo(int $yearId): ?int
    {
        if (array_key_exists($yearId, self::$cache)) {
            return self::$cache[$yearId];
        }

        $fila = DB::selectOne(
            'SELECT MAX(porc_final) AS mx FROM escalas_de_valoracion
              WHERE year_id = ? AND deleted_at IS NULL',
            [$yearId]
        );

        return self::$cache[$yearId] = $fila?->mx === null ? null : (int) $fila->mx;
    }

    /** El suelo, por el mismo camino. Hoy es 0 en todos los años de esta base. */
    public static function minimo(int $yearId): ?int
    {
        $fila = DB::selectOne(
            'SELECT MIN(porc_inicial) AS mn FROM escalas_de_valoracion
              WHERE year_id = ? AND deleted_at IS NULL',
            [$yearId]
        );

        return $fila?->mn === null ? null : (int) $fila->mn;
    }

    /**
     * El año al que pertenece un periodo. Es el camino que usan los llamantes:
     * una nota sabe de su periodo (`PeriodoDeLaFila`) y el periodo sabe del año.
     *
     * **Se resuelve desde la fila y no desde `$user->year`** a propósito: se
     * puede escribir en un año pasado ([16](../../docs/migracion/16-escribir-en-un-anio-pasado.md)),
     * y validar contra la escala del año en curso rechazaría notas correctas de
     * un año viejo cuya escala fuera distinta.
     */
    public static function anioDePeriodo(int $periodoId): ?int
    {
        $fila = DB::selectOne('SELECT year_id FROM periodos WHERE id = ? AND deleted_at IS NULL', [$periodoId]);

        return $fila?->year_id === null ? null : (int) $fila->year_id;
    }

    /**
     * ¿Cabe este valor en la escala de este periodo? Devuelve el motivo, o `null`
     * si cabe —o si no se puede saber, que es lo mismo para quien llama.
     *
     * Devuelve texto en vez de abortar para que `putLote` pueda meterlo en su
     * lista de `fallidas` y guardar las demás: un lote de cuarenta notas no se
     * cae entero porque una venga mal.
     */
    public static function motivoSiNoCabe(mixed $valor, ?int $periodoId): ?string
    {
        if ($periodoId === null) {
            return null;
        }

        return self::motivoSiNoCabeEnAnio($valor, self::anioDePeriodo($periodoId));
    }

    /**
     * Lo mismo pero con el año ya en la mano.
     *
     * Existe porque **`recuperacion_final` no tiene `periodo_id`**: guarda
     * alumno, asignatura, `year` y nota, y lo que se toca ahí es del año entero
     * —de ahí que su guarda exija todos los periodos abiertos y no uno—. Forzar
     * un periodo inventado para poder validar habría sido resolver la escala
     * contra algo que esa fila no tiene.
     */
    public static function motivoSiNoCabeEnAnio(mixed $valor, ?int $yearId): ?string
    {
        if ($yearId === null || ! is_numeric($valor)) {
            return null;
        }

        $maximo = self::maximo($yearId);

        if ($maximo === null) {
            return null;   // Año sin escala: no se bloquea. Ver la cabecera.
        }

        $minimo = self::minimo($yearId) ?? 0;
        $nota = (float) $valor;

        if ($nota > $maximo || $nota < $minimo) {
            return "La nota {$valor} no cabe en la escala del colegio, que va de {$minimo} a {$maximo}.";
        }

        return null;
    }

    /** Lo mismo, para quien sí tiene que cortar la petición. 422, no 400. */
    public static function comprobar(mixed $valor, ?int $periodoId): void
    {
        $motivo = self::motivoSiNoCabe($valor, $periodoId);

        if ($motivo !== null) {
            abort(422, $motivo);
        }
    }

    /** Y con el año en la mano, para `recuperacion_final`. */
    public static function comprobarEnAnio(mixed $valor, ?int $yearId): void
    {
        $motivo = self::motivoSiNoCabeEnAnio($valor, $yearId);

        if ($motivo !== null) {
            abort(422, $motivo);
        }
    }

    /** Sólo para los tests: la caché es de la petición y ellos hacen varias. */
    public static function olvidar(): void
    {
        self::$cache = [];
    }
}
