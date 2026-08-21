<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * El comando que cuenta los años actuales de un colegio.
 *
 * No comprueba que el comando esté bien: comprueba que **distingue los tres
 * casos**, que es para lo que se va a correr en dieciséis bases. Un diagnóstico
 * que dice «todo bien» cuando no lo está es peor que no tenerlo.
 *
 * Ver docs/migracion/05-codigo-muerto-y-roto.md §28.3.
 */
class AniosActualesTest extends CasoDeContrato
{
    /** Deja exactamente un año actual y la papelera apagada. */
    private function unSoloActual(): int
    {
        DB::table('years')->update(['actual' => 0]);

        $id = (int) DB::table('years')->orderBy('id')->value('id');
        DB::table('years')->where('id', $id)->update(['actual' => 1, 'deleted_at' => null]);

        return $id;
    }

    public function test_con_un_solo_ano_actual_sale_bien(): void
    {
        $this->unSoloActual();

        $this->artisan('anios:actuales')->assertExitCode(0);
    }

    public function test_con_dos_anos_actuales_falla_y_lo_dice(): void
    {
        $this->unSoloActual();

        $otro = DB::table('years')->where('actual', 0)->whereNull('deleted_at')->orderBy('id')->value('id');
        $this->assertNotNull($otro, 'El seed necesita al menos dos años vivos.');

        DB::table('years')->where('id', $otro)->update(['actual' => 1]);

        $this->artisan('anios:actuales')
            ->expectsOutputToContain('MÁS DE UN año actual')
            ->assertExitCode(1);
    }

    /**
     * El caso que de verdad había en la base, y que no se ve desde ninguna
     * pantalla: un año con `actual=1` y `deleted_at` puesto. Todo lo que lee el
     * año filtra los borrados, así que para el sistema no existe — hasta que
     * `years/restore/{id}` lo devuelve encendido al lado del que lo esté.
     */
    public function test_un_ano_encendido_en_la_papelera_no_pasa_desapercibido(): void
    {
        $actual = $this->unSoloActual();

        $otro = DB::table('years')->where('id', '<>', $actual)->orderBy('id')->value('id');
        $this->assertNotNull($otro, 'El seed necesita al menos dos años.');

        DB::table('years')->where('id', $otro)->update(['actual' => 1, 'deleted_at' => now()]);

        $this->artisan('anios:actuales')
            ->expectsOutputToContain('en la papelera')
            ->assertExitCode(1);
    }

    /** Y sin ninguno, que es el otro extremo y deja al colegio sin entrar. */
    public function test_sin_ningun_ano_actual_tambien_avisa(): void
    {
        DB::table('years')->update(['actual' => 0]);

        $this->artisan('anios:actuales')
            ->expectsOutputToContain('NINGÚN año actual')
            ->assertExitCode(1);
    }
}
