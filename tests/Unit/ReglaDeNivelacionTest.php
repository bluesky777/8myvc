<?php

namespace Tests\Unit;

use App\Services\Nivelacion;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * La tabla de la §1.4 de [22-nivelaciones.md](../../docs/migracion/22-nivelaciones.md),
 * celda por celda.
 *
 * Es el contrato con el front: el diálogo **previsualiza** con esta misma tabla
 * antes de guardar, y si el backend y el doble de B discrepan en una celda, el
 * docente ve un número al teclear y otro al guardar. Por eso las frases se
 * comparan **enteras**, no sólo el número: la frase también viaja.
 *
 * Sin base: `aplicar()` es pura a propósito, y esto corre sin contenedor.
 */
class ReglaDeNivelacionTest extends TestCase
{
    /** @return iterable<string, array{string, int, int, int, int, string}> */
    public static function laTablaDelContrato(): iterable
    {
        // regla, original, nivelación, mínima, queda, frase
        yield 'topada: 55 → 90 queda en la mínima' => ['topada', 55, 90, 70, 70,
            'Regla del colegio: la nivelación se topa en la mínima aprobatoria (70). Queda 70.'];
        yield 'topada: 55 → 40 queda 40, tal cual' => ['topada', 55, 40, 70, 40,
            'Regla del colegio: la nivelación se topa en la mínima aprobatoria (70). Queda 40.'];
        yield 'topada: 55 → 65 queda 65' => ['topada', 55, 65, 70, 65,
            'Regla del colegio: la nivelación se topa en la mínima aprobatoria (70). Queda 65.'];
        yield 'topada: justo la mínima queda la mínima' => ['topada', 55, 70, 70, 70,
            'Regla del colegio: la nivelación se topa en la mínima aprobatoria (70). Queda 70.'];

        yield 'mayor: 55 → 90 queda 90' => ['mayor', 55, 90, 70, 90,
            'Regla del colegio: queda la mayor de las dos. Queda 90.'];
        yield 'mayor: 55 → 40 queda 55' => ['mayor', 55, 40, 70, 55,
            'Regla del colegio: queda la mayor de las dos. Queda 55.'];
        yield 'mayor: 55 → 65 queda 65' => ['mayor', 55, 65, 70, 65,
            'Regla del colegio: queda la mayor de las dos. Queda 65.'];

        yield 'reemplaza: 55 → 90 queda 90' => ['reemplaza', 55, 90, 70, 90,
            'Regla del colegio: la nivelación reemplaza la valoración inicial. Queda 90.'];
        yield 'reemplaza: 55 → 40 queda 40' => ['reemplaza', 55, 40, 70, 40,
            'Regla del colegio: la nivelación reemplaza la valoración inicial. Queda 40.'];
        yield 'reemplaza: 55 → 65 queda 65' => ['reemplaza', 55, 65, 70, 65,
            'Regla del colegio: la nivelación reemplaza la valoración inicial. Queda 65.'];

        // La mínima no es siempre 70: un colegio de 0 a 50 topa en 30.
        yield 'topada con escala de 50: 20 → 48 queda 30' => ['topada', 20, 48, 30, 30,
            'Regla del colegio: la nivelación se topa en la mínima aprobatoria (30). Queda 30.'];
    }

    #[DataProvider('laTablaDelContrato')]
    public function test_cada_celda_de_la_tabla(string $regla, int $original, int $nivelacion, int $minima, int $queda, string $frase): void
    {
        $resultado = Nivelacion::aplicar($regla, $original, $nivelacion, $minima);

        $this->assertSame($queda, $resultado['nota'], "Con {$regla}, {$original} → niveló {$nivelacion} (mínima {$minima}).");
        $this->assertSame($frase, $resultado['explicacion'], 'La frase también es contrato: el front la previsualiza calcada.');
    }

    /**
     * Una regla que no es de las tres **no cae al defecto**. `years.regla_nivelacion`
     * es un `varchar` y la base puede llevar cualquier cosa; sustituirla en
     * silencio por `topada` sería un valor inventado con pinta de decisión, y aquí
     * se imprime.
     */
    public function test_una_regla_desconocida_no_se_sustituye_por_el_defecto(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("'la-que-sea'");

        Nivelacion::aplicar('la-que-sea', 55, 90, 70);
    }

    /** Las tres, y sólo las tres, para el endpoint que la escribe (22 §5). */
    public function test_solo_las_tres_reglas_son_reglas(): void
    {
        $this->assertSame(['topada', 'mayor', 'reemplaza'], Nivelacion::REGLAS);

        foreach (Nivelacion::REGLAS as $regla) {
            $this->assertTrue(Nivelacion::esRegla($regla));
        }

        foreach (['TOPADA', 'Topada', '', null, 0, 'mayor ', ['mayor']] as $noEs) {
            $this->assertFalse(Nivelacion::esRegla($noEs), var_export($noEs, true).' no debería valer como regla.');
        }
    }
}
