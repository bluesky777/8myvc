<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * La regla de nivelación del colegio, en **un único sitio**.
 *
 * Es la tarea A4 del reparto (`docs/migracion/22-nivelaciones.md`, §1.4), y la
 * decisión 6 del plan: **la regla se aplica al escribir, no al leer**. El backend
 * calcula la nota vigente en el momento de registrar la nivelación y la guarda en
 * `notas.nota`; ningún lector —boletines, certificados, puestos, la fórmula de la
 * definitiva, Flutter, el front legacy— tiene que conocerla, y cambiarla a mitad de
 * año no reescribe el pasado, que es lo correcto: el SIEE vigente cuando se niveló
 * es el que gobierna esa nivelación.
 *
 * ## Por qué un servicio y no tres `if` en el controlador
 *
 * Por lo mismo que `DefinitivasDeAsignatura`: la misma regla la aplican el `PUT`
 * suelto, el lote, la corrección de la original (§1.6) y —después— la definitiva
 * del periodo. Seis escritores de definitivas con seis fórmulas es de donde salió
 * aquel servicio; aquí se empieza con uno.
 *
 * ## Las tres reglas, con `mínima = 70`
 *
 * | regla       | qué queda en `nota`                                  | 55→90 | 55→40 | 55→65 |
 * |-------------|------------------------------------------------------|-------|-------|-------|
 * | `topada`    | si `nivelación ≥ mínima`: **`mínima`**; si no: tal cual | 70    | 40    | 65    |
 * | `mayor`     | `max(original, nivelación)`                          | 90    | 55    | 65    |
 * | `reemplaza` | `nivelación`, sin comparar                           | 90    | 40    | 65    |
 *
 * `topada` es el defecto porque es la redacción más frecuente en los SIEE —«si es
 * superior se registra generando una nueva nota; en caso contrario conserva la
 * valoración»— y porque evita que nivelar valga más que aprobar a tiempo. **Y bajo
 * `topada` una nivelación por debajo de la original queda tal cual** (55 → niveló
 * 40 → 40): la regla la escribe el SIEE, no el sistema, y el colegio que quiera
 * «nunca por debajo de la original» tiene `mayor`. El diálogo enseña el resultado
 * antes de guardar precisamente para eso.
 *
 * ## Una regla desconocida NO se sustituye por el defecto
 *
 * `years.regla_nivelacion` es `varchar(20)`: la base puede llevar cualquier cosa
 * si alguien la toca a mano. Aquí se lanza `InvalidArgumentException` y el
 * endpoint la convierte en un error que lo dice. Caer a `topada` en silencio sería
 * «un valor inventado que parece una comprobación» —la tercera opción de
 * `EscalaDeNotas`, la peor— y aquí el valor inventado se imprime en un boletín.
 */
final class Nivelacion
{
    public const TOPADA = 'topada';

    public const MAYOR = 'mayor';

    public const REEMPLAZA = 'reemplaza';

    public const REGLAS = [self::TOPADA, self::MAYOR, self::REEMPLAZA];

    /**
     * La configuración de cada año ya leída en esta petición.
     *
     * El lote aplica la regla hasta 200 veces seguidas y casi siempre en el mismo
     * año: sin esto serían 200 consultas idénticas. Misma decisión que la caché
     * de `EscalaDeNotas`, y con el mismo `olvidar()` para los tests.
     *
     * @var array<int, array{regla: string, nota_minima: int}|null>
     */
    private static array $cache = [];

    /**
     * La regla y la mínima aprobatoria de un año, o `null` si el año no existe.
     *
     * `nota_minima_aceptada` es `varchar` con `'70'` por defecto y se lee como
     * entero: es la que define «perdida» en los ocho sitios que la miran, y aquí
     * decide dónde se topa.
     *
     * @return array{regla: string, nota_minima: int}|null
     */
    public static function reglaDelAnio(int $yearId): ?array
    {
        if (array_key_exists($yearId, self::$cache)) {
            return self::$cache[$yearId];
        }

        $fila = DB::selectOne(
            'SELECT regla_nivelacion, nota_minima_aceptada FROM years WHERE id = ? AND deleted_at IS NULL',
            [$yearId]
        );

        if ($fila === null) {
            return self::$cache[$yearId] = null;
        }

        return self::$cache[$yearId] = [
            'regla' => (string) $fila->regla_nivelacion,
            'nota_minima' => (int) $fila->nota_minima_aceptada,
        ];
    }

    /**
     * Qué queda en `nota`, y la frase que lo explica.
     *
     * Pura: no toca la base. Es la tabla de la cabecera, y el test unitario la
     * recorre entera. La frase es **la del contrato** (22 §1.4) y el front la tiene
     * calcada para previsualizar; después de guardar pinta ésta, no la suya.
     *
     * @return array{nota: int, explicacion: string}
     */
    public static function aplicar(string $regla, int $original, int $nivelacion, int $minima): array
    {
        switch ($regla) {
            case self::TOPADA:
                $nota = $nivelacion >= $minima ? $minima : $nivelacion;

                return [
                    'nota' => $nota,
                    'explicacion' => 'Regla del colegio: la nivelación se topa en la mínima aprobatoria ('
                        .$minima.'). Queda '.$nota.'.',
                ];

            case self::MAYOR:
                $nota = max($original, $nivelacion);

                return [
                    'nota' => $nota,
                    'explicacion' => 'Regla del colegio: queda la mayor de las dos. Queda '.$nota.'.',
                ];

            case self::REEMPLAZA:
                return [
                    'nota' => $nivelacion,
                    'explicacion' => 'Regla del colegio: la nivelación reemplaza la valoración inicial. Queda '
                        .$nivelacion.'.',
                ];
        }

        throw new InvalidArgumentException(
            "La regla de nivelación '{$regla}' no es ninguna de las tres: ".implode(', ', self::REGLAS).'.'
        );
    }

    /**
     * Lo mismo, con la regla ya resuelta desde el año: es lo que devuelve el
     * endpoint como `regla_aplicada`, más la `nota` que escribe.
     *
     * Lanza `InvalidArgumentException` si el año no existe o su regla no es de las
     * tres; el llamante decide el código HTTP.
     *
     * @return array{nota: int, regla: string, nota_minima: int, explicacion: string}
     */
    public static function aplicarDelAnio(int $yearId, int $original, int $nivelacion): array
    {
        $config = self::reglaDelAnio($yearId);

        if ($config === null) {
            throw new InvalidArgumentException("El año {$yearId} no existe: no hay regla de nivelación que aplicar.");
        }

        $resultado = self::aplicar($config['regla'], $original, $nivelacion, $config['nota_minima']);

        return [
            'nota' => $resultado['nota'],
            'regla' => $config['regla'],
            'nota_minima' => $config['nota_minima'],
            'explicacion' => $resultado['explicacion'],
        ];
    }

    /** ¿Es una de las tres? Para el endpoint que la escribe (22 §5). */
    public static function esRegla(mixed $valor): bool
    {
        return is_string($valor) && in_array($valor, self::REGLAS, true);
    }

    /** Para los tests, que cambian la regla del año dentro de la misma petición. */
    public static function olvidar(): void
    {
        self::$cache = [];
    }
}
