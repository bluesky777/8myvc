<?php

namespace Tests\Contrato;

use App\Services\Portal\CuerpoDelPortal;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Lo que siembran los tests del portal: un grado nuevo con su grupo en el año
 * 2025 de la base de tests, alumnos matriculados y definitivas.
 *
 * Todo dentro de la transacción del test (`CasoDeContrato`).
 */
trait SiembraDelPortal
{
    protected const ANIO = 2025;

    /** Un reloj fijo: 15 oct 2025, 04:10 en Bogotá. */
    protected function ahora(): Carbon
    {
        return Carbon::parse('2025-10-15 04:10:07', 'America/Bogota');
    }

    /** @return array<string, mixed> */
    protected function cuerpo(?Carbon $ahora = null): array
    {
        return app(CuerpoDelPortal::class)->armar(self::ANIO, false, $ahora ?? $this->ahora());
    }

    protected function yearId(): int
    {
        return (int) DB::selectOne('SELECT id FROM years WHERE year = ? AND deleted_at IS NULL ORDER BY id DESC LIMIT 1', [self::ANIO])->id;
    }

    protected function periodoId(int $numero = 1): int
    {
        return (int) DB::selectOne('SELECT id FROM periodos WHERE year_id = ? AND numero = ? AND deleted_at IS NULL', [$this->yearId(), $numero])->id;
    }

    /**
     * Un grado propio con un grupo en 2025 y `$cuantos` alumnos activos. Cada
     * alumno lleva una definitiva por asignatura en el periodo 1, con las notas de
     * `$notas` (una por asignatura; lista vacía = sin calificar).
     *
     * @param  list<int>  $notas
     * @param  array<string, mixed>  $alumno  columnas que pisar en `alumnos`
     * @return array{grado: string, alumnos: list<int>}
     */
    protected function sembrarGrado(string $abrev, int $cuantos, array $notas, array $alumno = []): array
    {
        $gradoId = DB::table('grados')->insertGetId(['nombre' => 'Grado '.$abrev, 'abrev' => $abrev, 'orden' => 99]);
        $grupoId = DB::table('grupos')->insertGetId(['nombre' => 'Grupo '.$abrev, 'year_id' => $this->yearId(), 'grado_id' => $gradoId]);
        $materia = (int) DB::selectOne('SELECT id FROM materias WHERE deleted_at IS NULL ORDER BY id LIMIT 1')->id;

        $asignaturas = [];
        foreach ($notas as $_) {
            $asignaturas[] = DB::table('asignaturas')->insertGetId(['materia_id' => $materia, 'grupo_id' => $grupoId]);
        }

        $ids = [];
        for ($i = 0; $i < $cuantos; $i++) {
            $alumnoId = DB::table('alumnos')->insertGetId(array_merge(
                ['nombres' => 'Alumno '.$abrev.' '.$i, 'apellidos' => 'Prueba', 'sexo' => $i % 2 === 0 ? 'F' : 'M'],
                $alumno,
            ));
            DB::table('matriculas')->insert(['alumno_id' => $alumnoId, 'grupo_id' => $grupoId, 'estado' => 'MATR']);
            foreach ($asignaturas as $k => $asignaturaId) {
                DB::table('notas_finales')->insert([
                    'alumno_id' => $alumnoId, 'asignatura_id' => $asignaturaId,
                    'periodo_id' => $this->periodoId(1), 'periodo' => 1, 'nota' => $notas[$k],
                ]);
            }
            $ids[] = $alumnoId;
        }

        return ['grado' => $abrev, 'alumnos' => $ids];
    }

    /**
     * @param  array<string, mixed>  $cuerpo
     * @return array<string, mixed>
     */
    protected function grado(array $cuerpo, string $abrev): array
    {
        foreach ($cuerpo['grados'] as $g) {
            if ($g['grado'] === $abrev) {
                return $g;
            }
        }
        $this->fail("El grado {$abrev} no está en el cuerpo.");
    }

    protected function atencion(int $alumnoId, string $fecha, string $motivo = 'Dolor de cabeza'): void
    {
        DB::table('registros_enfermeria')->insert([
            'alumno_id' => $alumnoId, 'fecha_suceso' => $fecha, 'motivo_consulta' => $motivo,
        ]);
    }
}
