<?php

namespace Tests\Contrato;

use Carbon\Carbon;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * `portal:enviar` y `portal:respaldo-inicial`: la firma de §3.1, los reintentos de
 * §3.2 y el «sin configuración no es un error» de §3.0.
 */
class PortalEnviarTest extends CasoDeContrato
{
    use SiembraDelPortal;

    private const URL = 'https://portal.prueba.invalid';

    private const CLAVE_PORTAL = 'a3f1c0de9b8e7d6c5b4a39281706f5e4d3c2b1a09f8e7d6c5b4a392817060f1e';

    protected function setUp(): void
    {
        parent::setUp();

        config(['portal.url' => self::URL, 'portal.clave' => self::CLAVE_PORTAL, 'portal.esperas' => [0, 0]]);
        // 03:00 UTC del 16 = 22:00 del 15 en Bogotá: la fecha de corte es el 15.
        Carbon::setTestNow(Carbon::parse('2025-10-16 03:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_firma_las_cabeceras_sobre_los_bytes_exactos(): void
    {
        Http::fake(['*' => Http::response(['resultado' => 'aceptado'], 201)]);

        $this->assertSame(0, Artisan::call('portal:enviar', ['--anio' => self::ANIO]));

        Http::assertSentCount(1);
        Http::assertSent(function (Request $r): bool {
            $cuerpo = $r->body();
            $dane = $r->header('X-Portal-Colegio')[0];
            $fecha = $r->header('X-Portal-Fecha')[0];
            $version = $r->header('X-Portal-Version')[0];
            $canonico = $dane."\n".$fecha."\n".$version."\n".hash('sha256', $cuerpo);

            $this->assertSame(self::URL.'/api/envios', $r->url());
            $this->assertSame('POST', $r->method());
            $this->assertSame('305001010358', $dane);
            $this->assertSame('2', $version);
            $this->assertSame('2025-10-15T22:00:00-05:00', $fecha);
            // La clave, la cadena hex tal cual (así la usa el receptor).
            $this->assertTrue(hash_equals(hash_hmac('sha256', $canonico, self::CLAVE_PORTAL), $r->header('X-Portal-Firma')[0]));

            $json = json_decode($cuerpo, true);
            $this->assertSame('2025-10-15', $json['sobre']['fecha_corte'], 'fecha_corte va en hora de Bogotá, no en UTC.');
            $this->assertSame('2025-10-15T22:00:00-05:00', $json['sobre']['generado_en']);
            $this->assertFalse($json['sobre']['es_retroactivo']);

            return true;
        });
    }

    public function test_sin_configuracion_no_manda_y_sale_con_cero(): void
    {
        config(['portal.url' => null, 'portal.clave' => null]);
        Http::fake();

        $this->assertSame(0, Artisan::call('portal:enviar'));
        $this->assertSame(0, Artisan::call('portal:respaldo-inicial'));

        Http::assertNothingSent();
        $this->assertStringContainsString('no es un error', Artisan::output());
    }

    public function test_en_seco_imprime_el_cuerpo_y_no_manda(): void
    {
        config(['portal.url' => null, 'portal.clave' => null]);
        Http::fake();

        $this->assertSame(0, Artisan::call('portal:enviar', ['--anio' => self::ANIO, '--seco' => true]));

        Http::assertNothingSent();
        $json = json_decode(trim(Artisan::output()), true);
        $this->assertSame(self::ANIO, $json['sobre']['anio']);
    }

    public function test_reintenta_la_red_y_los_5xx_hasta_tres_veces(): void
    {
        Http::fake(['*' => Http::sequence()->push('', 503)->push('', 502)->push(['resultado' => 'aceptado'], 201)]);

        $this->assertSame(0, Artisan::call('portal:enviar', ['--anio' => self::ANIO]));

        Http::assertSentCount(3);
    }

    public function test_tres_fallos_salen_con_error(): void
    {
        Http::fake(['*' => Http::response('', 500)]);

        $this->assertSame(1, Artisan::call('portal:enviar', ['--anio' => self::ANIO]));

        Http::assertSentCount(3);
    }

    public function test_un_4xx_no_se_reintenta(): void
    {
        Http::fake(['*' => Http::response(['resultado' => 'rechazado', 'motivo' => 'firma'], 401)]);

        $this->assertSame(1, Artisan::call('portal:enviar', ['--anio' => self::ANIO]));

        Http::assertSentCount(1);
    }

    public function test_el_respaldo_salta_lo_que_ya_esta_y_manda_un_solo_anio(): void
    {
        $viejo = (int) DB::selectOne('SELECT MIN(year) AS y FROM years WHERE deleted_at IS NULL')->y;

        Http::fake([
            self::URL.'/api/envios/mios*' => fn (Request $r) => Http::response([
                'codigo_dane' => '305001010358',
                // El más viejo ya está; el siguiente no.
                'anios' => [['anio' => $viejo, 'fotos' => 1, 'ultima_fecha_corte' => '2025-10-01', 'es_retroactivo' => true]],
            ]),
            self::URL.'/api/envios' => Http::response(['resultado' => 'aceptado'], 201),
        ]);

        $this->assertSame(0, Artisan::call('portal:respaldo-inicial'));

        $gets = Http::recorded(fn (Request $r) => $r->method() === 'GET')->values();
        $posts = Http::recorded(fn (Request $r) => $r->method() === 'POST')->values();
        $this->assertCount(2, $gets, 'Pregunta por el más viejo, lo salta, pregunta por el siguiente.');
        $this->assertCount(1, $posts, 'Un año por ejecución.');

        // El GET se firma con el sha256 del cuerpo vacío.
        [$get] = $gets[0];
        $canonico = $get->header('X-Portal-Colegio')[0]."\n".$get->header('X-Portal-Fecha')[0]."\n2\n".hash('sha256', '');
        $this->assertSame(hash_hmac('sha256', $canonico, self::CLAVE_PORTAL), $get->header('X-Portal-Firma')[0]);
        $this->assertStringContainsString('anio='.$viejo, $get->url());

        [$post] = $posts[0];
        $json = json_decode($post->body(), true);
        $this->assertSame($viejo + 1, $json['sobre']['anio']);
        $this->assertTrue($json['sobre']['es_retroactivo']);
    }
}
