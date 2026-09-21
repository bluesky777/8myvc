<?php

namespace Tests\Feature;

use App\Services\PuntoDeControlDeImportacion;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `importaciones:marcar-abandonadas` — cierra lo que nadie va a continuar.
 *
 * No reanuda nada — el porqué está en el docblock del comando. Lo que fija
 * este test es el criterio del corte: sólo `en_proceso` y sólo por
 * `updated_at`, no por cuánto lleva abierta ni por `estado` distinto.
 */
class ImportacionesAbandonadasTest extends TestCase
{
    use DatabaseTransactions;

    private function crear(string $estado, string $updatedAt): int
    {
        return (int) DB::table('importaciones')->insertGetId([
            'tipo' => 'alumnos',
            'huella' => str_repeat('a', 64),
            'archivo' => 'alumnos.xlsx',
            'year' => 2026,
            'avance' => '{}',
            'filas' => 10,
            'estado' => $estado,
            'created_at' => $updatedAt,
            'updated_at' => $updatedAt,
        ]);
    }

    public function test_marca_fallida_la_que_lleva_mas_del_umbral_sin_escribir(): void
    {
        $id = $this->crear(PuntoDeControlDeImportacion::EN_PROCESO, now()->subMinutes(15)->toDateTimeString());

        $marcadas = PuntoDeControlDeImportacion::marcarAbandonadas(10);

        $this->assertSame(1, $marcadas);

        $fila = DB::table('importaciones')->where('id', $id)->first();
        $this->assertSame(PuntoDeControlDeImportacion::FALLIDA, $fila->estado);
        $this->assertStringContainsString('Abandonada', $fila->error);
        $this->assertStringContainsString('Vuelve a subir el mismo archivo', $fila->error);
    }

    public function test_no_toca_la_que_sigue_escribiendo_dentro_del_umbral(): void
    {
        $id = $this->crear(PuntoDeControlDeImportacion::EN_PROCESO, now()->subMinutes(2)->toDateTimeString());

        $marcadas = PuntoDeControlDeImportacion::marcarAbandonadas(10);

        $this->assertSame(0, $marcadas);
        $this->assertSame(
            PuntoDeControlDeImportacion::EN_PROCESO,
            DB::table('importaciones')->where('id', $id)->value('estado')
        );
    }

    public function test_no_toca_la_que_ya_esta_completada_o_fallida(): void
    {
        $completada = $this->crear(PuntoDeControlDeImportacion::COMPLETADA, now()->subMinutes(30)->toDateTimeString());
        $fallida = $this->crear(PuntoDeControlDeImportacion::FALLIDA, now()->subMinutes(30)->toDateTimeString());

        $marcadas = PuntoDeControlDeImportacion::marcarAbandonadas(10);

        $this->assertSame(0, $marcadas);
        $this->assertSame(
            PuntoDeControlDeImportacion::COMPLETADA,
            DB::table('importaciones')->where('id', $completada)->value('estado')
        );
        $this->assertSame(
            PuntoDeControlDeImportacion::FALLIDA,
            DB::table('importaciones')->where('id', $fallida)->value('estado')
        );
    }

    public function test_el_comando_reporta_cuantas_marco(): void
    {
        $this->crear(PuntoDeControlDeImportacion::EN_PROCESO, now()->subMinutes(20)->toDateTimeString());
        $this->crear(PuntoDeControlDeImportacion::EN_PROCESO, now()->subMinutes(20)->toDateTimeString());

        $this->artisan('importaciones:marcar-abandonadas')
            ->expectsOutputToContain('Marcadas 2 importaciones abandonadas')
            ->assertExitCode(0);
    }
}
