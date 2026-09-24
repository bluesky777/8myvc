<?php

namespace Tests\Contrato;

use App\Http\Controllers\Informes\BolfinalesPreescolarController;
use App\Models\NotaComportamiento;
use Illuminate\Support\Facades\DB;

/**
 * El promedio de comportamiento del año conserva los decimales, y lo que se
 * imprime es el entero más cercano (decisión de producto del 24 sep 2026,
 * `App\Support\NotaImpresa`).
 *
 * Antes `nota_promedio_year()` hacía `(int)`, que truncaba: un 59,5 salía 59 y
 * con mínima 60 se pintaba perdida.
 */
class PromedioDelComportamientoTest extends CasoDeContrato
{
    /** Un alumno con sus notas de comportamiento del año sustituidas por `$notas`. */
    private function alumnoConNotas(array $notas): object
    {
        $fila = DB::selectOne('SELECT m.alumno_id, g.year_id FROM matriculas m
            INNER JOIN grupos g ON g.id = m.grupo_id AND g.deleted_at IS NULL
            WHERE m.deleted_at IS NULL AND (SELECT COUNT(*) FROM periodos p
                WHERE p.year_id = g.year_id AND p.deleted_at IS NULL) >= ?
            ORDER BY m.alumno_id LIMIT 1', [count($notas)]);

        $this->assertNotNull($fila, 'El seed necesita un alumno en un año con periodos.');

        $periodos = DB::table('periodos')->where('year_id', $fila->year_id)
            ->whereNull('deleted_at')->orderBy('numero')->pluck('id');

        DB::table('nota_comportamiento')->where('alumno_id', $fila->alumno_id)
            ->whereIn('periodo_id', $periodos)->delete();

        foreach ($notas as $i => $nota) {
            DB::table('nota_comportamiento')->insert([
                'alumno_id' => $fila->alumno_id, 'periodo_id' => $periodos[$i], 'nota' => $nota,
            ]);
        }

        return $fila;
    }

    public function test_el_promedio_del_anio_conserva_los_decimales(): void
    {
        $a = $this->alumnoConNotas([59, 60]);

        $this->assertSame(59.5, NotaComportamiento::nota_promedio_year($a->alumno_id, $a->year_id));
    }

    public function test_sin_notas_el_promedio_sigue_siendo_cero(): void
    {
        $a = $this->alumnoConNotas([]);

        $this->assertSame(0, NotaComportamiento::nota_promedio_year($a->alumno_id, $a->year_id));
    }

    /** 59,5 se imprime 60 y con mínima 60 no está perdida; 59,33 se imprime 59 y sí. */
    public function test_la_cabecera_de_preescolar_imprime_y_juzga_la_nota_redondeada(): void
    {
        $a = $this->alumnoConNotas([59, 60]);
        $promedio = NotaComportamiento::nota_promedio_year($a->alumno_id, $a->year_id);

        $html = $this->encabezado($promedio);
        $this->assertStringContainsString('comportamiento-nota ">60<', $html);
        $this->assertStringNotContainsString('59.5', $html);
        $this->assertStringNotContainsString('nota-perdida-bold', $html);

        $a = $this->alumnoConNotas([59, 59, 60]);
        $promedio = NotaComportamiento::nota_promedio_year($a->alumno_id, $a->year_id);
        $this->assertEqualsWithDelta(59.3333, $promedio, 0.001);

        $html = $this->encabezado($promedio);
        $this->assertStringContainsString('>59<', $html);
        $this->assertStringContainsString('nota-perdida-bold', $html);
    }

    private function encabezado(float $nota): string
    {
        $c = new \ReflectionClass(BolfinalesPreescolarController::class);
        $controlador = $c->newInstanceWithoutConstructor();

        $escalas = $c->getProperty('escalas_val');
        $escalas->setAccessible(true);
        $escalas->setValue($controlador, []);

        $metodo = $c->getMethod('encabezado_comportamiento_boletin');
        $metodo->setAccessible(true);

        return $metodo->invoke($controlador, $nota, 60, 1, 'M');
    }
}
