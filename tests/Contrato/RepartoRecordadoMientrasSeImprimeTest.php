<?php

namespace Tests\Contrato;

use App\Support\RepartoDeLaNota;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

/**
 * La memoria del reparto (docs/migracion/48 §P3a) sólo existe mientras se arma un
 * boletín. **Fuera de ahí tiene que leer la columna cada vez**: `YearsController`
 * lee el reparto, lo cambia y recalcula el año en la misma petición, y una memoria
 * encendida de más recalcularía el año entero con el reparto viejo.
 */
class RepartoRecordadoMientrasSeImprimeTest extends CasoDeContrato
{
    #[Test]
    public function fuera_del_boletin_lee_la_columna_cada_vez(): void
    {
        $year = (int) DB::table('years')->where('actual', 1)->value('id');

        $this->poner($year, RepartoDeLaNota::PORCENTAJE);
        $this->assertSame(RepartoDeLaNota::PORCENTAJE, RepartoDeLaNota::modoDelAnio($year));

        $this->poner($year, RepartoDeLaNota::PROMEDIO);
        $this->assertSame(RepartoDeLaNota::PROMEDIO, RepartoDeLaNota::modoDelAnio($year));
    }

    #[Test]
    public function dentro_pregunta_una_vez_y_al_salir_lo_olvida_aunque_falle(): void
    {
        $year = (int) DB::table('years')->where('actual', 1)->value('id');
        $this->poner($year, RepartoDeLaNota::PORCENTAJE);

        $consultas = 0;
        DB::listen(function ($q) use (&$consultas) {
            if (str_contains($q->sql, 'reparto_subunidades FROM years')) {
                $consultas++;
            }
        });

        RepartoDeLaNota::recordandoElReparto(function () use ($year) {
            foreach (range(1, 5) as $_) {
                RepartoDeLaNota::modoDelAnio($year);
            }
        });
        $this->assertSame(1, $consultas);

        try {
            RepartoDeLaNota::recordandoElReparto(function () use ($year) {
                RepartoDeLaNota::modoDelAnio($year);
                throw new \RuntimeException('se cae a medias');
            });
        } catch (\RuntimeException) {
        }

        $this->poner($year, RepartoDeLaNota::PROMEDIO);
        $this->assertSame(RepartoDeLaNota::PROMEDIO, RepartoDeLaNota::modoDelAnio($year));
    }

    private function poner(int $year, string $modo): void
    {
        DB::table('years')->where('id', $year)->update(['reparto_subunidades' => $modo]);
    }
}
