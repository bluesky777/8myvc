<?php

namespace Tests\Contrato;

use App\Models\Grupo;
use App\Services\BoletinIndependiente;
use App\Services\PuestosDelCierre;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

/**
 * **El puesto se congela al cerrar** — fase 4 del cierre de periodo
 * (`myvc_front/PLAN-CIERRE-DE-PERIODO.md`, decisiones 4 y 5).
 *
 * El lienzo: un grupo con un periodo abierto y definitivas puestas a mano (para que ni
 * el cierre ni un recálculo las muevan), con un alumno —«el último»— por debajo de todos.
 * Se cierra, se le sube la definitiva como haría una nivelación, y se mira qué puesto
 * sale. Sin la foto sería 1; con ella, el de la fecha del cierre.
 */
class LosPuestosDelCierreTest extends CasoDeContrato
{
    #[Test]
    public function cerrar_toma_la_foto_de_todo_el_anio_y_lo_dice(): void
    {
        $l = $this->lienzo();

        $r = $this->cerrar($l->periodo_id);
        $r->assertStatus(200);
        $this->assertStringContainsString('queda fijo a fecha de hoy', $r->getContent());

        $esperados = DB::selectOne('SELECT COUNT(DISTINCT m.alumno_id) AS n FROM matriculas m
            INNER JOIN grupos g ON g.id = m.grupo_id AND g.year_id = ? AND g.deleted_at IS NULL
            WHERE m.deleted_at IS NULL AND m.estado IN ("MATR","ASIS","PREM")', [$l->year_id])->n;

        $this->assertSame((int) $esperados,
            DB::table('puestos_del_cierre')->where('periodo_id', $l->periodo_id)->count(),
            'La foto es del periodo entero: una fila por alumno matriculado del año.');

        $this->assertSame(count($l->alumnos),
            (int) DB::table('puestos_del_cierre')->where('periodo_id', $l->periodo_id)
                ->where('alumno_id', $l->ultimo)->value('puesto'),
            'El último del lienzo tiene que quedar último en la foto.');
    }

    #[Test]
    public function con_el_periodo_cerrado_nivelar_no_mueve_el_puesto_y_el_papel_dice_la_fecha(): void
    {
        $l = $this->lienzo();
        $this->cerrar($l->periodo_id)->assertStatus(200);

        $this->subirAlUltimo($l);

        $alumnos = $this->conPromedioVivo($l);
        BoletinIndependiente::ponerPuestos($alumnos, [$l->periodo_id], $l->year_id);

        $ultimo = $this->elDe($alumnos, $l->ultimo);
        $this->assertSame(count($l->alumnos), $ultimo->puesto,
            'Con el periodo cerrado el puesto es el de la foto, no el recalculado.');
        $this->assertNotEmpty($ultimo->puesto_congelado_at ?? null,
            'Sin la fecha, el papel no puede rotular «puesto a fecha de cierre».');
    }

    /** La rendija: con el periodo reabierto manda el cálculo vivo. */
    #[Test]
    public function reabierto_el_puesto_vuelve_a_calcularse(): void
    {
        $l = $this->lienzo();
        $this->cerrar($l->periodo_id)->assertStatus(200);
        $this->subirAlUltimo($l);
        $this->cerrar($l->periodo_id, abrir: true)->assertStatus(200);

        $alumnos = $this->conPromedioVivo($l);
        BoletinIndependiente::ponerPuestos($alumnos, [$l->periodo_id], $l->year_id);

        $ultimo = $this->elDe($alumnos, $l->ultimo);
        $this->assertSame(1, $ultimo->puesto);
        $this->assertObjectNotHasProperty('puesto_congelado_at', $ultimo);
    }

    /** Decisión 5: al recerrar, la foto se rehace siempre. */
    #[Test]
    public function recerrar_rehace_la_foto(): void
    {
        $l = $this->lienzo();
        $this->cerrar($l->periodo_id)->assertStatus(200);
        $this->subirAlUltimo($l);
        $this->cerrar($l->periodo_id, abrir: true)->assertStatus(200);
        $this->cerrar($l->periodo_id)->assertStatus(200);

        $this->assertSame(1, (int) DB::table('puestos_del_cierre')->where('periodo_id', $l->periodo_id)
            ->where('alumno_id', $l->ultimo)->value('puesto'));
    }

    /** Los informes de varios periodos salen de las vigentes (§4 del plan). */
    #[Test]
    public function un_informe_de_varios_periodos_no_lee_la_foto(): void
    {
        $l = $this->lienzo();
        $this->cerrar($l->periodo_id)->assertStatus(200);
        $this->subirAlUltimo($l);

        $alumnos = $this->conPromedioVivo($l);
        BoletinIndependiente::ponerPuestos($alumnos, [$l->periodo_id, $l->periodo_id + 1], $l->year_id);

        $this->assertSame(1, $this->elDe($alumnos, $l->ultimo)->puesto);
    }

    /** Un periodo cerrado sin foto —todos los de antes de esto— sigue al vuelo. */
    #[Test]
    public function un_periodo_cerrado_sin_foto_sigue_al_vuelo(): void
    {
        $l = $this->lienzo();
        DB::table('periodos')->where('id', $l->periodo_id)->update(['profes_pueden_editar_notas' => 0]);
        $this->subirAlUltimo($l);

        $alumnos = $this->conPromedioVivo($l);
        BoletinIndependiente::ponerPuestos($alumnos, [$l->periodo_id], $l->year_id);

        $this->assertSame(1, $this->elDe($alumnos, $l->ultimo)->puesto);
        $this->assertSame([], PuestosDelCierre::deLosAlumnos($l->periodo_id, [$l->ultimo]));
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

    /** Lo que haría una nivelación: la definitiva vigente del último sube por encima de todos. */
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
