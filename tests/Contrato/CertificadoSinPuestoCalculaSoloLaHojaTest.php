<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * Pedir UNA hoja del boletín final calculaba el grupo entero, sólo por el puesto
 * (docs/migracion/48 §P2b). Sin puesto que poner —el certificado manda
 * `sin_puesto`, o el año no lo pinta— se calcula sólo la hoja pedida.
 *
 * Lo que se fija: **la hoja sale igual salvo el puesto**, que va a `null`, y se
 * hacen muchas menos consultas. Y lo que no puede cambiar: sin `sin_puesto`, con
 * el año pintándolo, el puesto sigue llegando.
 */
class CertificadoSinPuestoCalculaSoloLaHojaTest extends CasoDeContrato
{
    public function test_con_sin_puesto_la_hoja_sale_igual_salvo_el_puesto_y_cuesta_menos(): void
    {
        [$grupo, $token] = $this->grupoYPersonal();
        $alumnoId = (int) DB::table('matriculas')->where('grupo_id', $grupo->id)
            ->whereNull('deleted_at')->whereIn('estado', ['MATR', 'ASIS', 'PREM'])->value('alumno_id');
        DB::table('years')->where('id', $grupo->year_id)->update(['mostrar_puesto_boletin' => 1]);

        [$conPuesto, $consultasCon] = $this->pedir($grupo->id, $token, ['requested_alumnos' => [['alumno_id' => $alumnoId]]]);
        [$sinPuesto, $consultasSin] = $this->pedir($grupo->id, $token, ['requested_alumnos' => [['alumno_id' => $alumnoId]], 'sin_puesto' => true]);

        $this->assertCount(1, $conPuesto[2]);
        $this->assertNotNull($conPuesto[2][0]['puesto'], 'Sin `sin_puesto` y con el año pintándolo, el puesto tiene que llegar.');
        $this->assertNull($sinPuesto[2][0]['puesto']);

        $this->assertEquals(self::sinPuesto($conPuesto), self::sinPuesto($sinPuesto),
            'Quitando el puesto, la hoja tiene que ser la misma: si cambia otra cosa, algo dependía de calcular al resto.');
        $this->assertLessThan($consultasCon, $consultasSin);
    }

    public function test_si_el_anio_no_pinta_el_puesto_no_hace_falta_pedirlo(): void
    {
        [$grupo, $token] = $this->grupoYPersonal();
        $alumnoId = (int) DB::table('matriculas')->where('grupo_id', $grupo->id)
            ->whereNull('deleted_at')->whereIn('estado', ['MATR', 'ASIS', 'PREM'])->value('alumno_id');
        DB::table('years')->where('id', $grupo->year_id)->update(['mostrar_puesto_boletin' => 0]);

        [$respuesta] = $this->pedir($grupo->id, $token, ['requested_alumnos' => [['alumno_id' => $alumnoId]]]);

        $this->assertNull($respuesta[2][0]['puesto']);
    }

    /** @return array{0: array<int, mixed>, 1: int} */
    private function pedir(int $grupoId, string $token, array $cuerpo): array
    {
        $consultas = 0;
        DB::listen(function () use (&$consultas) {
            $consultas++;
        });

        $r = $this->withToken($token)->putJson('/api/bolfinales/detailed-notas-year/'.$grupoId, $cuerpo);
        $r->assertStatus(200);

        DB::getEventDispatcher()->forget(\Illuminate\Database\Events\QueryExecuted::class);

        return [$r->json(), $consultas];
    }

    private static function sinPuesto(array $respuesta): array
    {
        foreach ($respuesta[2] as &$hoja) {
            unset($hoja['puesto']);
        }

        return $respuesta;
    }
}
