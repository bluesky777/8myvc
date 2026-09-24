<?php

namespace Tests\Contrato;

use App\Models\Grupo;
use App\Services\BoletinIndependiente;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

/**
 * **El puesto NO se congela al cerrar** (Joseth, 24 sep 2026). El nombre del fichero es
 * el de la fase 4 del cierre de periodo (ff370d2), que fotografiaba el puesto en
 * `puestos_del_cierre`; esa foto se quitó el mismo día y aquí queda la prueba de lo
 * contrario: el puesto se calcula al vuelo siempre, como la definitiva — «si un
 * directivo edita una nota en una emergencia, el informe se recalcula aunque salga
 * distinto del impreso; lo importante es que el historial queda».
 *
 * El lienzo: un grupo con un periodo abierto y definitivas puestas a mano (para que ni
 * el cierre ni un recálculo las muevan), con un alumno —«el último»— por debajo de todos.
 * Se cierra, se le sube la definitiva como haría la edición de un directivo, y el puesto
 * tiene que salir 1.
 */
class LosPuestosDelCierreTest extends CasoDeContrato
{
    #[Test]
    public function cerrar_ya_no_fotografia_el_puesto(): void
    {
        $l = $this->lienzo();

        $r = $this->cerrar($l->periodo_id);
        $r->assertStatus(200);
        $this->assertStringNotContainsString('queda fijo', $r->getContent(),
            'El cierre ya no promete un puesto fijo.');

        $this->assertSame(0, DB::table('puestos_del_cierre')->where('periodo_id', $l->periodo_id)->count(),
            'El cierre volvió a escribir la foto del puesto.');
    }

    #[Test]
    public function con_el_periodo_cerrado_editar_una_nota_recalcula_el_puesto(): void
    {
        $l = $this->lienzo();
        $this->cerrar($l->periodo_id)->assertStatus(200);

        $antes = $this->conPromedioVivo($l);
        BoletinIndependiente::ponerPuestos($antes, [$l->periodo_id], $l->year_id);
        $this->assertSame(count($l->alumnos), $this->elDe($antes, $l->ultimo)->puesto,
            'El lienzo no empieza con el último en el último puesto.');

        $this->subirAlUltimo($l);

        $alumnos = $this->conPromedioVivo($l);
        BoletinIndependiente::ponerPuestos($alumnos, [$l->periodo_id], $l->year_id);

        $ultimo = $this->elDe($alumnos, $l->ultimo);
        $this->assertSame(1, $ultimo->puesto,
            'Con el periodo cerrado el puesto tiene que recalcularse con la nota editada.');
        $this->assertObjectNotHasProperty('puesto_congelado_at', $ultimo,
            'Sin foto no hay «a fecha de cierre» que rotular.');
    }

    // ─────────────────────────────────────────────────────────────────────

    private function lienzo(): object
    {
        $donde = DB::selectOne(
            'SELECT g.id AS grupo_id, g.year_id, p.id AS periodo_id, p.numero
               FROM grupos g
               INNER JOIN periodos p ON p.year_id = g.year_id AND p.deleted_at IS NULL
              WHERE g.deleted_at IS NULL
                AND (SELECT COUNT(DISTINCT m.alumno_id) FROM matriculas m
                      WHERE m.grupo_id = g.id AND m.deleted_at IS NULL
                        AND m.estado IN ("MATR","ASIS","PREM")) >= 3
                AND EXISTS (SELECT 1 FROM asignaturas a
                             INNER JOIN profesores pr ON pr.id = a.profesor_id AND pr.deleted_at IS NULL
                             WHERE a.grupo_id = g.id AND a.deleted_at IS NULL)
              ORDER BY g.id, p.id LIMIT 1'
        );

        $this->assertNotNull($donde, 'El seed no tiene un grupo con tres matriculados y una asignatura con docente.');

        // Abierto y sin marca: el cierre tiene que ser una transición de verdad.
        DB::table('periodos')->where('id', $donde->periodo_id)
            ->update(['profes_pueden_editar_notas' => 1, 'cierre_sin_calificar' => null]);
        DB::table('puestos_del_cierre')->where('periodo_id', $donde->periodo_id)->delete();

        $alumnos = array_map(fn ($a) => (int) $a->alumno_id, Grupo::alumnos((int) $donde->grupo_id));
        $asignaturas = DB::select('SELECT a.id FROM asignaturas a
            INNER JOIN profesores pr ON pr.id = a.profesor_id AND pr.deleted_at IS NULL
            WHERE a.grupo_id = ? AND a.deleted_at IS NULL', [$donde->grupo_id]);

        DB::table('notas_finales')->whereIn('alumno_id', $alumnos)
            ->where('periodo_id', $donde->periodo_id)->delete();

        $ultimo = $alumnos[0];

        foreach ($alumnos as $alumno) {
            foreach ($asignaturas as $asignatura) {
                DB::table('notas_finales')->insert([
                    'alumno_id' => $alumno,
                    'asignatura_id' => $asignatura->id,
                    'periodo_id' => $donde->periodo_id,
                    'periodo' => $donde->numero,
                    'nota' => $alumno === $ultimo ? 20 : 30,
                    'manual' => 1,
                    'recuperada' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        $donde->alumnos = $alumnos;
        $donde->ultimo = $ultimo;

        return $donde;
    }

    /** Lo que haría un directivo editando la nota: la definitiva vigente del último sube por encima de todos. */
    private function subirAlUltimo(object $l): void
    {
        DB::table('notas_finales')->where('alumno_id', $l->ultimo)
            ->where('periodo_id', $l->periodo_id)->update(['nota' => 45]);
    }

    /** Las filas como las tiene un boletín: el promedio vivo, de las definitivas de hoy. */
    private function conPromedioVivo(object $l): array
    {
        $alumnos = Grupo::alumnos((int) $l->grupo_id);

        foreach ($alumnos as $alumno) {
            $asig = Grupo::detailed_materias_notafinal($alumno->alumno_id, $l->grupo_id, $l->periodo_id, $l->year_id);
            $alumno->promedio = array_sum(array_map(fn ($a) => (float) $a->nota_asignatura, $asig)) / max(1, count($asig));
        }

        return $alumnos;
    }

    private function elDe(array $alumnos, int $id): object
    {
        foreach ($alumnos as $a) {
            if ((int) $a->alumno_id === $id) {
                return $a;
            }
        }

        $this->fail("El alumno {$id} no está en la lista.");
    }

    private function cerrar(int $periodoId, bool $abrir = false)
    {
        $usuario = $this->usuarioDeTipo('Usuario');

        return $this->withToken($this->tokenDe($usuario->username))
            ->putJson('/api/periodos/toggle-profes-pueden-editar-notas',
                ['periodo_id' => $periodoId, 'pueden' => $abrir ? 1 : 0]);
    }
}
