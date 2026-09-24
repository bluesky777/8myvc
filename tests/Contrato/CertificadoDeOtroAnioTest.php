<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * **EL BOLETÍN FINAL DE UN GRUPO DE OTRO AÑO SALE CON SUS NOTAS Y SU AÑO.**
 *
 * `detailedNotasGrupo` leía periodos, escalas, cabecera y nivelaciones con el año de la
 * SESIÓN. Con un grupo de un año pasado, el certificado de todos los años de un alumno
 * (`informes/certificados-alumno`) salía con **todas las notas en 0** --el
 * `JOIN periodos p ON p.year_id=:year_id` no encontraba ninguno-- y decía «durante el año
 * 2026». Medido el 24 sep 2026 en el docker: alumno 416, grupos 52 (2024) y 61 (2025).
 */
class CertificadoDeOtroAnioTest extends CasoDeContrato
{
    public function test_un_grupo_de_otro_anio_sale_con_sus_notas_y_su_anio(): void
    {
        // Un alumno con una nota distinta de cero en un grupo, y alguien del personal cuya
        // sesión está en OTRO año: es justo la combinación que salía en cero.
        $caso = DB::selectOne('SELECT g.id AS grupo_id, g.year_id, y.year, nf.alumno_id, u.username
            FROM notas_finales nf
            INNER JOIN asignaturas a ON a.id = nf.asignatura_id AND a.deleted_at IS NULL
            INNER JOIN grupos g ON g.id = a.grupo_id AND g.deleted_at IS NULL
            INNER JOIN years y ON y.id = g.year_id
            INNER JOIN matriculas m ON m.alumno_id = nf.alumno_id AND m.grupo_id = g.id
                AND m.deleted_at IS NULL AND m.estado IN ("MATR","ASIS")
            INNER JOIN users u ON u.tipo = "Usuario" AND u.is_active = 1 AND u.deleted_at IS NULL
            INNER JOIN periodos pu ON pu.id = u.periodo_id AND pu.year_id <> g.year_id
            WHERE nf.nota > 0
            ORDER BY g.year_id, u.id LIMIT 1');

        $this->assertNotNull($caso,
            'El seed no tiene una nota de un año distinto al de la sesión de algún Usuario; '
            .'sin eso este test no comprueba nada.');

        $cuerpo = $this->putJson('/api/bolfinales/detailed-notas-year/'.$caso->grupo_id,
            ['requested_alumnos' => [['alumno_id' => $caso->alumno_id]]],
            ['Authorization' => 'Bearer '.$this->tokenDe($caso->username)])
            ->assertStatus(200)->json();

        $this->assertSame((int) $caso->year, (int) $cuerpo[1]['year'],
            'La cabecera tiene que decir el año del grupo, no el de la sesión.');

        $definitivas = [];
        foreach ($cuerpo[2][0]['areas'] ?? [] as $area) {
            foreach ($area['asignaturas'] ?? [] as $asignatura) {
                foreach ($asignatura['definitivas'] ?? [] as $definitiva) {
                    $definitivas[] = (float) ($definitiva['DefMateria'] ?? 0);
                }
            }
        }

        $this->assertNotEmpty($definitivas, 'El certificado no trajo ninguna definitiva.');
        $this->assertGreaterThan(0, max($definitivas),
            'Todas las definitivas salieron en 0: se leyeron los periodos del año de la sesión.');

        $escalasDelGrupo = DB::selectOne('SELECT COUNT(*) AS n FROM escalas_de_valoracion
            WHERE year_id = ? AND deleted_at IS NULL', [$caso->year_id])->n;
        $this->assertCount((int) $escalasDelGrupo, $cuerpo[3],
            'Las escalas tienen que ser las del año del grupo.');
    }
}
