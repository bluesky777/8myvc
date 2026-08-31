<?php

namespace App\Services;

use Carbon\Carbon;

/**
 * Las fechas de los cuatro periodos de un año lectivo recién creado.
 *
 * Hasta el 30 ago 2026 `YearsController::postStore` creaba **un** periodo, sin
 * fechas: el año nacía con `numero=1, actual=1` y nada más. El resultado se ve en
 * la base — los ocho años viejos del colegio del seed tienen sus cuatro periodos,
 * puestos a mano después, y **el único creado por la ruta tiene uno**—, y las
 * fechas se ven igual de mal: de nueve años, **tres** las tienen (2018, 2019 y
 * 2020) y desde 2021 están todas en `NULL`.
 *
 * Que estén en `NULL` no es cosmético. `Informes\ActasEvaluacionController`
 * reparte las ausencias por periodo **contra estas fechas**, y ya lleva escrito
 * que «hay colegios con el calendario sin llenar»: las que no caen en ningún
 * periodo van al balde `fuera_calendario`. Con los cuatro periodos sin fechas, el
 * balde se lo lleva todo.
 *
 * Dos caminos, y el orden importa:
 *
 *   1. **Si el año anterior tiene fechas, se trasladan** — no se calculan. Son el
 *      calendario que el colegio ya negoció, y un cálculo lo pisaría con una
 *      aproximación.
 *   2. **Si no las tiene, se calculan** desde `years.calendario`, que es la misma
 *      letra que el certificado de estudio imprime («calendario {{ year.calendario }}»).
 *
 * Nada de esto es la verdad del colegio: es un punto de partida editable con
 * `periodos/cambiar-fecha-inicio` y `periodos/cambiar-fecha-fin`, que es como se
 * llenaron las de 2018-2020. Lo que arregla es que hoy el punto de partida es
 * *ninguna fecha*, y desde ahí el acta de evaluación no puede repartir nada.
 */
class CalendarioDePeriodos
{
    /**
     * Cuatro. Es una decisión de Joseth (30 ago 2026) y no una lectura del año
     * anterior: los ocho años del colegio del seed llevan cuatro, y un colegio
     * que trabaje con tres o con seis borra o añade uno — que es lo que hace hoy
     * de todas formas, sólo que partiendo de uno en vez de cuatro.
     */
    public const CANTIDAD = 4;

    /** Semanas de receso entre el segundo y el tercer periodo, cuando se calculan. */
    private const SEMANAS_DE_RECESO = 2;

    /**
     * @param  iterable<object>  $periodosDelAnterior  los periodos vivos del año de partida, o vacío
     * @return list<array{numero:int, fecha_inicio:string, fecha_fin:string, fecha_plazo:?string}>
     */
    public static function para(int $anio_nuevo, ?int $anio_anterior, iterable $periodosDelAnterior, ?string $calendario): array
    {
        $trasladados = self::trasladar($anio_nuevo, $anio_anterior, $periodosDelAnterior);

        return $trasladados ?? self::calcular($anio_nuevo, $calendario);
    }

    /**
     * El calendario del año anterior, desplazado y **cuadrado al mismo día de la
     * semana**.
     *
     * Un `+1 año` a secas mueve el día de la semana: 365 días son 52 semanas y un
     * día, así que un periodo que empezaba lunes empieza martes, y al siguiente
     * miércoles. Al cabo de tres años el calendario arranca en sábado. Aquí se
     * suma el año y después se ajusta al mismo día de la semana más cercano, que
     * siempre cae a menos de cuatro días.
     *
     * El ajuste se calcula **una vez por periodo, sobre la fecha de inicio**, y el
     * mismo número de días se le aplica al fin y al plazo. Es a propósito: como
     * ese desplazamiento es múltiplo de siete, las tres fechas conservan su día de
     * la semana **y la duración del periodo no cambia ni en un día**. Ajustando
     * cada fecha por su cuenta, un periodo podría alargarse o encogerse una semana
     * según de qué lado del 29 de febrero caiga cada extremo.
     *
     * **O los cuatro, o ninguno.** Si al año anterior le falta la fecha de inicio o
     * la de fin de cualquiera de los cuatro, se calcula el calendario entero en vez
     * de trasladar los trozos: media docena de años del seed están así —uno tiene
     * *un* periodo con `fecha_inicio` puesta y `fecha_fin` en NULL, y los otros tres
     * vacíos—, y trasladar eso deja al año nuevo exactamente con el agujero que esto
     * viene a tapar. Un calendario a medias no es el calendario que el colegio
     * negoció: es uno que se quedó sin llenar.
     *
     * @param  iterable<object>  $periodosDelAnterior
     * @return ?list<array{numero:int, fecha_inicio:string, fecha_fin:string, fecha_plazo:?string}>
     *                                                                                              null si el año anterior no trae los cuatro completos — entonces se calcula
     */
    private static function trasladar(int $anio_nuevo, ?int $anio_anterior, iterable $periodosDelAnterior): ?array
    {
        if ($anio_anterior === null || $anio_nuevo === $anio_anterior) {
            return null;
        }

        $por_numero = [];

        foreach ($periodosDelAnterior as $periodo) {
            $numero = (int) ($periodo->numero ?? 0);

            // Sólo los cuatro primeros, y el primero que aparezca de cada número.
            // En la base hay un año con DOS periodos número 4 —uno de ellos en la
            // papelera— y años con periodos que el colegio numeró más allá de cuatro.
            if ($numero < 1 || $numero > self::CANTIDAD || isset($por_numero[$numero])) {
                continue;
            }

            $por_numero[$numero] = $periodo;
        }

        $fechas = [];

        for ($numero = 1; $numero <= self::CANTIDAD; $numero++) {
            $periodo = $por_numero[$numero] ?? null;

            $inicio = self::fecha($periodo->fecha_inicio ?? null);
            $fin = self::fecha($periodo->fecha_fin ?? null);
            $plazo = self::fecha($periodo->fecha_plazo ?? null);

            if ($inicio === null || $fin === null || $fin < $inicio) {
                return null;
            }

            $dias = self::dias($inicio, $anio_nuevo - $anio_anterior);

            $fechas[] = [
                'numero' => $numero,
                'fecha_inicio' => $inicio->copy()->addDays($dias)->toDateString(),
                'fecha_fin' => $fin->copy()->addDays($dias)->toDateString(),
                'fecha_plazo' => $plazo?->copy()->addDays($dias)->toDateString(),
            ];
        }

        return $fechas;
    }

    /**
     * Cuántos días hay que sumarle a `$fecha` para caer `$anios` más tarde **en su
     * mismo día de la semana**, por el camino más corto (nunca más de tres días de
     * ajuste sobre el salto de año).
     */
    private static function dias(Carbon $fecha, int $anios): int
    {
        $destino = $fecha->copy()->addYears($anios);

        $desfase = ($destino->dayOfWeek - $fecha->dayOfWeek + 7) % 7;

        $destino = $desfase <= 3
            ? $destino->subDays($desfase)
            : $destino->addDays(7 - $desfase);

        return (int) $fecha->diffInDays($destino, false);
    }

    /**
     * Cuatro tramos entre el primer y el último día lectivo del año, con un receso
     * de dos semanas entre el segundo y el tercero.
     *
     * Los extremos salen de `years.calendario`, que es la letra que el colegio ya
     * imprime en el certificado de estudio:
     *
     *   - **A** (el defecto de la columna): tercer lunes de enero → último viernes
     *     de noviembre, dentro del mismo año.
     *   - **B**: tercer lunes de agosto → último viernes de junio del año siguiente.
     *
     * Todo periodo empieza lunes y termina viernes, y el resto de la división se
     * reparte entre los primeros para que el cuarto **termine exactamente el último
     * día lectivo**. Contra las fechas reales de 2018-2020 que hay en la base, esto
     * cae cerca: para 2026 y calendario A da 19 ene → 3 abr, 6 abr → 19 jun,
     * 6 jul → 18 sep y 21 sep → 27 nov, con el receso de mitad de año en las dos
     * últimas semanas de junio.
     *
     * @return list<array{numero:int, fecha_inicio:string, fecha_fin:string, fecha_plazo:null}>
     */
    private static function calcular(int $anio, ?string $calendario): array
    {
        $es_b = strtoupper(trim((string) $calendario)) === 'B';

        $inicio = self::lunes($es_b ? Carbon::create($anio, 8, 1) : Carbon::create($anio, 1, 1), 3);
        $fin = self::ultimoViernes($es_b ? Carbon::create($anio + 1, 6, 1) : Carbon::create($anio, 11, 1));

        $semanas = (int) floor($inicio->diffInDays($fin) / 7) + 1;
        $lectivas = max(self::CANTIDAD, $semanas - self::SEMANAS_DE_RECESO);
        $base = intdiv($lectivas, self::CANTIDAD);
        $resto = $lectivas % self::CANTIDAD;

        $fechas = [];
        $cursor = $inicio->copy();

        for ($numero = 1; $numero <= self::CANTIDAD; $numero++) {
            $duracion = $base + ($numero <= $resto ? 1 : 0);

            // El último cierra en el último día lectivo y no en lo que dé la cuenta:
            // el redondeo a semanas completas se lo come él.
            $cierre = $numero === self::CANTIDAD
                ? $fin->copy()
                : $cursor->copy()->addWeeks($duracion)->subDays(3);

            $fechas[] = [
                'numero' => $numero,
                'fecha_inicio' => $cursor->toDateString(),
                'fecha_fin' => $cierre->toDateString(),
                // El plazo para subir notas lo pone el colegio periodo a periodo
                // (`periodos/update`), y en la base entera hay UNO puesto. Calcularlo
                // sería inventar una fecha con consecuencia: es la que le dice al
                // docente hasta cuándo puede escribir.
                'fecha_plazo' => null,
            ];

            $cursor = $cursor->copy()->addWeeks($duracion);

            if ($numero === 2) {
                $cursor = $cursor->addWeeks(self::SEMANAS_DE_RECESO);
            }
        }

        return $fechas;
    }

    /** El n-ésimo lunes del mes al que pertenece `$mes`. */
    private static function lunes(Carbon $mes, int $cual): Carbon
    {
        $fecha = $mes->copy()->startOfMonth();

        while ($fecha->dayOfWeek !== Carbon::MONDAY) {
            $fecha->addDay();
        }

        return $fecha->addWeeks($cual - 1);
    }

    /** El último viernes del mes al que pertenece `$mes`. */
    private static function ultimoViernes(Carbon $mes): Carbon
    {
        $fecha = $mes->copy()->endOfMonth()->startOfDay();

        while ($fecha->dayOfWeek !== Carbon::FRIDAY) {
            $fecha->subDay();
        }

        return $fecha;
    }

    /** Una fecha de la base —`date`, o `null`— como Carbon, o null si no se puede leer. */
    private static function fecha(mixed $valor): ?Carbon
    {
        if ($valor === null || $valor === '' || $valor === '0000-00-00') {
            return null;
        }

        try {
            return Carbon::parse($valor)->startOfDay();
        } catch (\Exception) {
            return null;
        }
    }
}
