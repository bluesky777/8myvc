<?php

namespace Tests\Unit;

use App\Support\NotaImpresa;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * «¿Perdió?» se juzga sobre la nota impresa (decisión de producto, 24 sep 2026).
 *
 * Los números son los del ejemplo de producto: con mínima 60, un 59,9 se imprime
 * «60» y NO está perdida; un 59,4 se imprime «59» y SÍ. Es la regla de
 * `notaPerdida` en `myvc_front` (app2 `nota.pipe.ts`).
 */
class NotaImpresaTest extends TestCase
{
    public static function casos(): array
    {
        return [
            '59,9 se imprime 60' => [59.9, '60', false],
            '59,4 se imprime 59' => [59.4, '60', true],
            '59,5 sube, como Math.round' => [59.5, '60', false],
            '60 exacto' => [60, '60', false],
            // `decimal` llega de PDO como cadena, y la mínima es `varchar(3)`.
            'la cadena de PDO, 59.9000' => ['59.9000', '60', false],
            'la cadena de PDO, 59.4000' => ['59.4000', '60', true],
            // Lo que no es número se compara como antes: `null < 60` es verdadero en PHP.
            'sin nota' => [null, '60', true],
        ];
    }

    #[DataProvider('casos')]
    public function test_perdida_se_juzga_sobre_lo_impreso(mixed $nota, mixed $minima, bool $perdida): void
    {
        $this->assertSame($perdida, NotaImpresa::perdida($nota, $minima));
    }

    public function test_el_valor_redondea_los_numeros_y_deja_lo_demas(): void
    {
        $this->assertSame(60.0, NotaImpresa::valor(59.9));
        $this->assertSame(70.0, NotaImpresa::valor('69.8300'));
        $this->assertNull(NotaImpresa::valor(null));
        $this->assertSame('', NotaImpresa::valor(''));
    }
}
