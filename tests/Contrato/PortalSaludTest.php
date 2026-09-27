<?php

namespace Tests\Contrato;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * El bloque `salud` de la v2 (§2.1.6): dos conteos por colegio y mes, y una
 * lista que empieza en el mes del primer registro.
 */
class PortalSaludTest extends CasoDeContrato
{
    use SiembraDelPortal;

    protected function setUp(): void
    {
        parent::setUp();

        // La base de tests no trae ninguna, pero si mañana trajera, estos números
        // dejarían de ser los que se siembran aquí.
        DB::table('registros_enfermeria')->delete();
    }

    public function test_sin_registros_la_lista_va_vacia(): void
    {
        $this->assertSame([], $this->cuerpo()['salud']['meses']);
    }

    public function test_empieza_en_el_primer_mes_y_los_posteriores_sin_registros_van_a_cero(): void
    {
        $a = $this->sembrarGrado('PXH', 2, [])['alumnos'];
        $this->atencion($a[0], '2025-06-03 08:00:00');
        $this->atencion($a[0], '2025-08-20 08:00:00');
        // Otro año: no cuenta.
        $this->atencion($a[1], '2024-02-01 08:00:00');

        $meses = $this->cuerpo()['salud']['meses']; // corte: 15 oct 2025

        $this->assertSame([6, 7, 8, 9, 10], array_column($meses, 'mes'),
            'Antes de junio no existe (no usaban enfermería); de julio a octubre sí, con ceros.');
        $this->assertSame([1, 0, 1, 0, 0], array_column($meses, 'atenciones'));
    }

    public function test_alumnos_atendidos_cuenta_distintos(): void
    {
        $a = $this->sembrarGrado('PXD', 2, [])['alumnos'];
        foreach (['04', '11', '18', '25'] as $dia) {
            $this->atencion($a[0], "2025-09-{$dia} 10:00:00");
        }
        $this->atencion($a[1], '2025-09-12 10:00:00');

        $septiembre = $this->cuerpo()['salud']['meses'][0];

        $this->assertSame(['mes' => 9, 'atenciones' => 5, 'alumnos_atendidos' => 2], $septiembre);
    }

    public function test_lo_posterior_al_corte_no_cuenta_y_un_anio_pasado_llega_a_diciembre(): void
    {
        $a = $this->sembrarGrado('PXC', 1, [])['alumnos'];
        $this->atencion($a[0], '2025-10-15 23:00:00');
        $this->atencion($a[0], '2025-10-16 07:00:00');

        $meses = $this->cuerpo()['salud']['meses'];
        $this->assertSame([['mes' => 10, 'atenciones' => 1, 'alumnos_atendidos' => 1]], $meses);

        // El mismo año mandado en la carga inicial, desde 2026: octubre a diciembre.
        $retro = $this->cuerpo(Carbon::parse('2026-03-01 04:10:00', 'America/Bogota'))['salud']['meses'];
        $this->assertSame([10, 11, 12], array_column($retro, 'mes'));
        $this->assertSame([2, 0, 0], array_column($retro, 'atenciones'));
    }
}
