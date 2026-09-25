<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * Pidiendo hojas sueltas del boletín final con el puesto encendido, al resto del
 * grupo sólo se le calcula el promedio (docs/migracion/48 §P2c). **La hoja tiene que
 * salir como sale dentro del grupo entero**, puesto incluido: el puesto compara
 * flotantes, y un promedio sumado en otro orden rompe un empate distinto.
 */
class UnaHojaConPuestoSaleComoEnElGrupoTest extends CasoDeContrato
{
    public function test_cada_hoja_suelta_sale_igual_que_dentro_del_grupo(): void
    {
        [$grupo, $token] = $this->grupoYPersonal();
        DB::table('years')->where('id', $grupo->year_id)->update(['mostrar_puesto_boletin' => 1]);

        $entero = $this->withToken($token)
            ->putJson('/api/bolfinales/detailed-notas-year-group/'.$grupo->id, [])
            ->assertStatus(200)->json();

        $this->assertGreaterThan(1, count($entero[2]), 'Con un alumno no hay puesto que comparar.');

        foreach ($entero[2] as $hojaDelGrupo) {
            $suelta = $this->withToken($token)
                ->putJson('/api/bolfinales/detailed-notas-year/'.$grupo->id, [
                    'requested_alumnos' => [['alumno_id' => $hojaDelGrupo['alumno_id']]],
                ])
                ->assertStatus(200)->json();

            $this->assertCount(1, $suelta[2]);
            $this->assertSame($hojaDelGrupo['puesto'], $suelta[2][0]['puesto'],
                'El puesto del alumno '.$hojaDelGrupo['alumno_id'].' cambia según se pida su hoja sola o con el grupo.');
            $this->assertEquals($hojaDelGrupo, $suelta[2][0]);
        }
    }
}
