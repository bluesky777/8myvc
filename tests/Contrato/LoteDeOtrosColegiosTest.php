<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * BOLETINES DE OTROS COLEGIOS EN LOTE (26 sep 2026). Ver `App\Services\LoteDeOtrosColegios`.
 *
 * Lo que tiene que sostenerse: el ensayo no escribe; el alumno se encuentra por documento aunque
 * venga con puntos; lo repetido --el mismo alumno y año ya guardados, o la misma materia dos veces
 * con notas distintas-- NO se resuelve solo: sin decisión, 422; y la materia emparejada a mano se
 * aprende para el lote siguiente.
 */
class LoteDeOtrosColegiosTest extends CasoDeContrato
{
    private function h(): array
    {
        return ['Authorization' => 'Bearer '.$this->tokenDe($this->usuarioDeTipo('Usuario')->username), 'Accept' => 'application/json'];
    }

    /** Un alumno con documento, y el documento escrito con puntos como lo pondría un boletín. */
    private function alumno(): object
    {
        $a = DB::selectOne("SELECT id, documento FROM alumnos
            WHERE deleted_at IS NULL AND documento IS NOT NULL AND TRIM(documento) REGEXP '^[0-9]{6,}$' ORDER BY id LIMIT 1");
        $this->assertNotNull($a, 'El seed no tiene ningún alumno con documento numérico.');

        return $a;
    }

    private function materia(): object
    {
        $m = DB::selectOne('SELECT id, materia FROM materias WHERE deleted_at IS NULL AND materia IS NOT NULL ORDER BY id LIMIT 1');
        $this->assertNotNull($m);

        return $m;
    }

    private function fila(object $alumno, string $materia, string $nota, int $year = 2019): array
    {
        return [
            'documento' => number_format((int) $alumno->documento, 0, '', '.'),
            'alumno' => 'Quien sea', 'year' => $year, 'grado' => 'Quinto',
            'colegio' => 'Colegio de Prueba', 'municipio' => 'Pasto',
            'materia' => $materia, 'nota' => $nota,
            'escala_min' => 1, 'escala_max' => 5, 'escala_aprueba' => 3,
        ];
    }

    public function test_el_ensayo_empareja_por_documento_convierte_y_no_escribe(): void
    {
        $alumno = $this->alumno();
        $materia = $this->materia();
        $antes = DB::table('anos_externos')->count();

        $r = $this->putJson('/api/otros-colegios/lote/ensayo',
            ['filas' => [$this->fila($alumno, mb_strtoupper($materia->materia), '4.5')]], $this->h())
            ->assertStatus(200)->json();

        $g = $r['grupos'][0];
        $this->assertSame((int) $alumno->id, $g['alumno']['id']);
        $this->assertSame('documento', $g['alumno']['como']);
        $this->assertSame((int) $materia->id, $g['filas'][0]['materia']['id']);
        $this->assertNotNull($g['filas'][0]['nota']);
        $this->assertNull($g['existente']);
        $this->assertSame(['crear', 'omitir'], $g['opciones']);
        $this->assertSame($antes, DB::table('anos_externos')->count(), 'El ensayo escribió.');
    }

    public function test_el_mismo_alumno_y_ano_ya_guardado_se_compara_y_no_se_resuelve_solo(): void
    {
        $alumno = $this->alumno();
        $materia = $this->materia();
        $h = $this->h();

        $creado = $this->postJson('/api/otros-colegios/lote', ['filas' => [$this->fila($alumno, $materia->materia, '4')]], $h)
            ->assertStatus(200)->assertJsonPath('creados', 1)->json('anos');
        // El id de cada año escrito vuelve, para archivar después su documento (el guion de la puerta 3).
        $this->assertCount(1, $creado);

        $this->olvidarControladores();
        $otra = $this->fila($alumno, $materia->materia, '3.2');
        $g = $this->putJson('/api/otros-colegios/lote/ensayo', ['filas' => [$otra]], $h)->assertStatus(200)->json('grupos.0');

        $this->assertNotNull($g['existente']);
        $this->assertSame(['conservar', 'reemplazar', 'combinar'], $g['opciones']);
        $this->assertSame('distinta', $g['comparacion']['materias'][0]['estado']);
        $this->assertSame('4', $g['comparacion']['materias'][0]['hay']);
        $this->assertSame('3.2', $g['comparacion']['materias'][0]['nuevo']);

        // Sin decisión, no se escribe nada.
        $this->olvidarControladores();
        $this->postJson('/api/otros-colegios/lote', ['filas' => [$otra]], $h)->assertStatus(422);

        // Combinar eligiendo lo nuevo en esa materia: queda UNA nota, la nueva.
        $this->olvidarControladores();
        $clave = $g['comparacion']['materias'][0]['clave'];
        $this->postJson('/api/otros-colegios/lote', ['filas' => [$otra], 'decisiones' => [
            $g['clave'] => ['accion' => 'combinar', 'combinar' => [$clave => 'nuevo']],
        ]], $h)->assertStatus(200)->assertJsonPath('combinados', 1);

        $notas = DB::select('SELECT nota_original FROM notas_externas WHERE ano_externo_id = ? AND deleted_at IS NULL', [$g['existente']['id']]);
        $this->assertSame(['3.2'], array_map(fn ($n) => $n->nota_original, $notas));
    }

    public function test_la_misma_materia_dos_veces_con_notas_distintas_pide_elegir(): void
    {
        $alumno = $this->alumno();
        $materia = $this->materia();
        $filas = [$this->fila($alumno, $materia->materia, '4', 2018), $this->fila($alumno, $materia->materia, '2', 2018)];
        $h = $this->h();

        $g = $this->putJson('/api/otros-colegios/lote/ensayo', ['filas' => $filas], $h)->assertStatus(200)->json('grupos.0');
        $this->assertCount(1, $g['repetidas']);

        $this->olvidarControladores();
        $this->postJson('/api/otros-colegios/lote', ['filas' => $filas], $h)->assertStatus(422);

        $this->olvidarControladores();
        $this->postJson('/api/otros-colegios/lote', ['filas' => $filas, 'decisiones' => [
            $g['clave'] => ['accion' => 'crear', 'repetidas' => [$g['repetidas'][0]['clave'] => 1]],
        ]], $h)->assertStatus(200)->assertJsonPath('notas', 1);
    }

    public function test_la_materia_emparejada_a_mano_se_aprende(): void
    {
        $alumno = $this->alumno();
        $materia = $this->materia();
        $inventada = 'Asignatura Rarisima Del Otro Colegio';
        $filas = [$this->fila($alumno, $inventada, '4', 2017)];
        $h = $this->h();

        $g = $this->putJson('/api/otros-colegios/lote/ensayo', ['filas' => $filas], $h)->json('grupos.0');
        $this->assertNull($g['filas'][0]['materia']);

        $this->olvidarControladores();
        $this->postJson('/api/otros-colegios/lote', ['filas' => $filas, 'decisiones' => [
            $g['clave'] => ['accion' => 'crear', 'materias' => [0 => $materia->id]],
        ]], $h)->assertStatus(200)->assertJsonPath('aprendidas', 1);

        $this->olvidarControladores();
        $otra = $this->putJson('/api/otros-colegios/lote/ensayo', ['filas' => [$this->fila($alumno, $inventada, '3', 2016)]], $h)
            ->json('grupos.0.filas.0.materia');
        $this->assertSame((int) $materia->id, $otra['id']);
        $this->assertSame('aprendida', $otra['como']);
    }

    public function test_el_alumno_que_no_esta_se_crea_una_sola_vez_para_varios_anos(): void
    {
        $materia = $this->materia();
        $fila = fn (int $year) => ['documento' => '9990001112', 'alumno' => 'Zambrano Erazo, María Camila', 'year' => $year,
            'colegio' => 'Otro', 'materia' => $materia->materia, 'nota' => '4', 'escala_min' => 1, 'escala_max' => 5, 'escala_aprueba' => 3];
        $filas = [$fila(2018), $fila(2019)];
        $h = $this->h();

        $grupos = $this->putJson('/api/otros-colegios/lote/ensayo', ['filas' => $filas], $h)->json('grupos');
        $this->assertNull($grupos[0]['alumno']);

        // Sin sexo no se crea: la columna no admite nulo.
        $this->olvidarControladores();
        $nuevo = ['nombres' => 'María Camila', 'apellidos' => 'Zambrano Erazo', 'documento' => '9990001112'];
        $this->postJson('/api/otros-colegios/lote', ['filas' => $filas, 'decisiones' => [
            $grupos[0]['clave'] => ['accion' => 'crear', 'alumno_nuevo' => $nuevo],
        ]], $h)->assertStatus(422);

        $this->olvidarControladores();
        $decisiones = [];
        foreach ($grupos as $g) {
            $decisiones[$g['clave']] = ['accion' => 'crear', 'alumno_nuevo' => $nuevo + ['sexo' => 'F']];
        }
        $this->postJson('/api/otros-colegios/lote', ['filas' => $filas, 'decisiones' => $decisiones], $h)
            ->assertStatus(200)->assertJsonPath('alumnos_nuevos', 1)->assertJsonPath('creados', 2);

        $alumno = DB::selectOne("SELECT id, nombres, sexo FROM alumnos WHERE documento = '9990001112'");
        $this->assertSame('MARÍA CAMILA', $alumno->nombres);
        $this->assertSame(2, (int) DB::table('anos_externos')->where('alumno_id', $alumno->id)->count());
    }

    public function test_un_alumno_no_entra(): void
    {
        $h = ['Authorization' => 'Bearer '.$this->tokenDe($this->usuarioDeTipo('Alumno')->username), 'Accept' => 'application/json'];
        $this->putJson('/api/otros-colegios/lote/ensayo', ['filas' => [['alumno' => 'x']]], $h)->assertStatus(403);
    }
}
