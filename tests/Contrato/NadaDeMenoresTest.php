<?php

namespace Tests\Contrato;

use App\Services\Portal\CuerpoDelPortal;
use Illuminate\Support\Facades\DB;

/**
 * §2.1.5 punto 4: el cuerpo entero, serializado A CADENA, no lleva dentro nada de
 * un alumno. Es la única que caza un agregado que arrastre un dato dentro de un
 * texto — un `nombre_colegio` relleno con otra cosa, un mensaje interpolado.
 */
class NadaDeMenoresTest extends CasoDeContrato
{
    use SiembraDelPortal;

    public function test_el_cuerpo_no_lleva_ningun_dato_de_un_alumno(): void
    {
        $g = $this->sembrarGrado('PXN', 6, [37], [
            'nombres' => 'ZZZ_ALUMNO_DE_PRUEBA',
            'apellidos' => 'ZZZ_APELLIDO_DE_PRUEBA',
            'documento' => '999000111222333',
            'email' => 'zzz.menor@prueba.invalid',
            'eps' => 'ZZZ_EPS_DE_PRUEBA',
        ]);
        // Un grado de UNO cuya media es inconfundible (11 y 12 → 11.5): si la regla
        // del grado pequeño faltara, «11.5» aparecería en el cuerpo.
        $solo = $this->sembrarGrado('PXS', 1, [11, 12], ['nombres' => 'ZZZ_ALUMNO_SOLO']);

        $this->atencion($g['alumnos'][0], '2025-05-05 10:00:00', 'ZZZ_MOTIVO_DE_ENFERMERIA');
        DB::table('dis_procesos')->insert([
            'alumno_id' => $solo['alumnos'][0], 'year_id' => $this->yearId(),
            'descripcion' => 'ZZZ_DESCRIPCION_DISCIPLINARIA',
        ]);

        $texto = CuerpoDelPortal::json($this->cuerpo());

        foreach ([
            'ZZZ_ALUMNO_DE_PRUEBA', 'ZZZ_APELLIDO_DE_PRUEBA', '999000111222333',
            'zzz.menor@prueba.invalid', 'ZZZ_EPS_DE_PRUEBA', 'ZZZ_ALUMNO_SOLO',
            'ZZZ_MOTIVO_DE_ENFERMERIA', 'ZZZ_DESCRIPCION_DISCIPLINARIA', '11.5',
        ] as $prohibido) {
            $this->assertStringNotContainsString($prohibido, $texto, "El cuerpo lleva «{$prohibido}».");
        }

        // Y el cuerpo sí se armó con ellos dentro: lo contrario haría pasar esto en vacío.
        $this->assertStringContainsString('"grado":"PXS"', $texto);
        $this->assertStringContainsString('"grado":"PXN"', $texto);
    }
}
