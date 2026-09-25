<?php

namespace Tests\Contrato;

use App\Services\BoletinIndependiente;
use App\Services\DefinitivasDeAsignatura;
use App\Support\RepartoDeLaNota;
use Illuminate\Support\Facades\DB;

/**
 * **Cada escritura que cambia lo que vale una definitiva la deja al día**, y el
 * tablero de Informes avisa sólo de las que valen otra cosa.
 *
 * Sale del censo del 25 sep 2026, hecho a raíz de `lal`: el tablero marcaba 1.068
 * definitivas y recalculándolas **ninguna** estaba atrasada por una escritura. El
 * censo encontró, además, cinco caminos que sí cambian el valor y no recalculaban:
 * restaurar una unidad o una subunidad de la papelera, mover subunidades de una
 * unidad a otra, el botón que borra las notas de un alumno en un periodo y marcar o
 * desmarcar el boletín independiente. El sexto —cerrar en `cero`— está en
 * `ElCierreYLoNoCalificadoTest`.
 *
 * Todos miran lo mismo: **la tabla contra `calcular()`**, que es la definición de
 * «al día», y además que el valor se movió —si no, el caso pasaría aunque la ruta no
 * hubiera recalculado nada—.
 */
class LosCaminosQueRecalculanTest extends CasoDeContrato
{
    public function test_restaurar_una_unidad_rehace_la_definitiva(): void
    {
        $e = $this->escenario();
        DB::table('unidades')->where('id', $e->unidad2)->update(['deleted_at' => '2026-01-01 00:00:00']);
        DefinitivasDeAsignatura::recalcular($e->asignatura, $e->periodo);
        $antes = $this->guardada($e);

        $this->withToken($e->token)->putJson('/api/unidades/restore/'.$e->unidad2)->assertStatus(200);

        $this->assertAlDiaYMovida($e, $antes);
    }

    public function test_restaurar_una_subunidad_rehace_la_definitiva(): void
    {
        $e = $this->escenario();
        DB::table('subunidades')->where('id', $e->subB)->update(['deleted_at' => '2026-01-01 00:00:00']);
        DefinitivasDeAsignatura::recalcular($e->asignatura, $e->periodo);
        $antes = $this->guardada($e);

        $this->withToken($e->token)->putJson('/api/subunidades/restore/'.$e->subB)->assertStatus(200);

        $this->assertAlDiaYMovida($e, $antes);
    }

    /** Mover una subunidad a otra unidad la hace pesar con otro porcentaje. */
    public function test_mover_una_subunidad_de_unidad_rehace_la_definitiva(): void
    {
        $e = $this->escenario();
        $antes = $this->guardada($e);

        $this->withToken($e->token)->putJson('/api/subunidades/update-orden-varias', [
            'unidad1_id' => $e->unidad1,
            'unidad2_id' => $e->unidad2,
            'sortHash1' => [[(string) $e->subA => 1], [(string) $e->subB => 2], [(string) $e->subC => 3]],
            'sortHash2' => [],
        ])->assertStatus(200);

        $this->assertAlDiaYMovida($e, $antes);
    }

    /** El borrado es físico: sin recálculo, la definitiva de esas notas se quedaba. */
    public function test_borrar_las_notas_del_periodo_rehace_la_definitiva(): void
    {
        $e = $this->escenario();
        $antes = $this->guardada($e);

        $this->withToken($e->token)->putJson('/api/detalles/eliminar-notas-periodo', [
            'periodo_id' => $e->periodo,
            'alumno_id' => $e->alumno,
            'grupo_id' => $e->grupo,
        ])->assertStatus(200);

        $this->assertAlDiaYMovida($e, $antes);
    }

    /**
     * Marcar el independiente cambia de qué unidades sale la definitiva. Aquí la
     * rejilla sembrada da el mismo número, así que el montaje estropea la guardada:
     * lo que se mide es que la ruta la reescribe, no cuánto cambia.
     */
    public function test_marcar_el_boletin_independiente_rehace_su_definitiva(): void
    {
        $e = $this->escenario();
        $this->estropear($e);

        $this->withToken($this->tokenDelPersonalDe($e->year))->putJson('/api/boletin-independiente/periodo', [
            'alumno_id' => $e->alumno,
            'periodo_id' => $e->periodo,
            'aplica' => true,
        ])->assertStatus(200);
        BoletinIndependiente::olvidar();

        $this->assertSame($this->calculada($e), round((float) $this->guardada($e), 4),
            'Se marcó el boletín independiente y la definitiva guardada no se reescribió.');
    }

    /**
     * **El aviso del tablero no lo enciende la nota de un compañero.** Es el caso
     * que llenaba el tablero de `lal`: `notas/update` recalcula al alumno de la
     * casilla, el sello es de la asignatura entera, y los demás salían atrasados
     * sin que su definitiva valiera otra cosa.
     */
    public function test_el_aviso_no_lo_enciende_la_nota_de_un_companero(): void
    {
        $e = $this->escenario();
        $companero = $this->casilla($e->unidad2, 100, 20, $e->otro);
        DefinitivasDeAsignatura::recalcular($e->asignatura, $e->periodo);

        DB::table('notas')->where('id', $companero)->update(['nota' => 45, 'updated_at' => now()->addHour()]);
        DefinitivasDeAsignatura::recalcularPorNota($companero);

        $this->assertGreaterThan(0, $this->marcadasPorSello($e),
            'El montaje no reproduce el caso: el sello no marca a nadie.');
        $this->assertSame(0, $this->marcadasPorValor($e),
            'El tablero marca la asignatura y todas sus definitivas valen lo que tienen que valer.');
    }

    /** Y al revés: lo que vale otra cosa sí sale, aunque ninguna fecha lo diga. */
    public function test_el_aviso_sale_cuando_el_valor_es_otro_aunque_la_fecha_sea_nueva(): void
    {
        $e = $this->escenario();
        $this->estropear($e);

        $this->assertSame(1, $this->filaDelTablero($e)['atrasadas']);

        $this->withToken($e->token)->putJson('/api/definitivas_periodos/calcular-grupo-periodo', [
            'grupo_id' => $e->grupo,
            'periodo_id' => $e->periodo,
            'num_periodo' => $e->numero,
        ])->assertStatus(200);

        $this->assertSame(0, $this->marcadasPorValor($e),
            'Se pulsó el botón que acompaña al aviso y el aviso sigue: es el de Zaragoza.');
    }

    /**
     * **La guardada entera de antes de `912830a` no es una atrasada** si redondeando
     * la cuenta sale ella; si sale otra, sí. El escenario da 39,5 (A 40 y B 30 en la
     * unidad del 70 %, C 50 en la del 30 %), así que 40 es el redondeo y 39 no.
     */
    public function test_la_definitiva_entera_de_antes_de_los_decimales_no_es_atrasada(): void
    {
        $e = $this->escenario();
        $this->assertSame(39.5, $this->calculada($e));

        $this->guardar($e, 40);
        $this->assertSame(0, $this->filaDelTablero($e)['atrasadas'],
            'Un 40 guardado cuando la cuenta da 39,5 es la misma definitiva con el redondeo de antes.');

        $this->guardar($e, 39);
        $this->assertSame(1, $this->filaDelTablero($e)['atrasadas'],
            'Un 39 no sale de redondear 39,5: esa sí vale otra cosa.');
    }

    /** `faltan` cuenta al que tiene casillas y no fila, y no al que no tiene nada. */
    public function test_faltan_es_quien_tiene_notas_y_no_tiene_fila(): void
    {
        $e = $this->escenario();

        DB::table('notas_finales')->where('asignatura_id', $e->asignatura)
            ->where('periodo_id', $e->periodo)->delete();

        $this->assertSame(1, $this->filaDelTablero($e)['faltan'],
            'Sólo un alumno tiene casillas en el escenario; los demás no tienen nada que calcular.');
    }

    // ── montaje ─────────────────────────────────────────────────────────────────

    /**
     * Una asignatura sin unidades en un periodo abierto del año del superusuario, con
     * dos unidades (70 y 30) y tres casillas de un alumno:
     * A (50 %, 40) y B (50 %, 30) en la 1, C (100 %, 50) en la 2.
     */
    private function escenario(): object
    {
        BoletinIndependiente::olvidar();

        $usuario = $this->usuarioDeTipo('Usuario');
        $this->assertSame(1, (int) $usuario->is_superuser);
        // El token primero: `Services\Login` reescribe `users.periodo_id` al entrar.
        $token = $this->tokenDe($usuario->username);
        $year = (int) DB::selectOne('SELECT p.year_id FROM periodos p INNER JOIN users u ON u.periodo_id = p.id
            WHERE u.id = ?', [$usuario->id])->year_id;

        $donde = DB::selectOne(
            'SELECT a.id AS asignatura_id, a.grupo_id, p.id AS periodo_id, p.numero
               FROM asignaturas a
               INNER JOIN grupos g ON g.id = a.grupo_id AND g.deleted_at IS NULL AND g.year_id = ?
               INNER JOIN periodos p ON p.year_id = g.year_id AND p.deleted_at IS NULL
              WHERE a.deleted_at IS NULL
                AND (SELECT COUNT(DISTINCT m.alumno_id) FROM matriculas m WHERE m.grupo_id = a.grupo_id
                      AND m.deleted_at IS NULL AND m.estado IN ("MATR","ASIS")) >= 2
                AND NOT EXISTS (SELECT 1 FROM unidades u WHERE u.asignatura_id = a.id AND u.periodo_id = p.id)
              ORDER BY a.id, p.id LIMIT 1',
            [$year]
        );
        $this->assertNotNull($donde, 'El seed no tiene una asignatura limpia con dos matriculados.');

        DB::table('periodos')->where('id', $donde->periodo_id)
            ->update(['profes_pueden_editar_notas' => 1, 'cierre_sin_calificar' => null]);
        DB::table('years')->where('id', $year)->update(['reparto_subunidades' => RepartoDeLaNota::PORCENTAJE]);

        $alumnos = DB::select('SELECT DISTINCT alumno_id FROM matriculas WHERE grupo_id = ? AND deleted_at IS NULL
            AND estado IN ("MATR","ASIS") ORDER BY alumno_id LIMIT 2', [$donde->grupo_id]);

        $e = (object) [
            'token' => $token,
            'year' => $year,
            'grupo' => (int) $donde->grupo_id,
            'asignatura' => (int) $donde->asignatura_id,
            'periodo' => (int) $donde->periodo_id,
            'numero' => (int) $donde->numero,
            'alumno' => (int) $alumnos[0]->alumno_id,
            'otro' => (int) $alumnos[1]->alumno_id,
        ];

        $e->unidad1 = $this->unidad($e, 70);
        $e->unidad2 = $this->unidad($e, 30);
        $e->subA = $this->subunidadDe($this->casilla($e->unidad1, 50, 40, $e->alumno));
        $e->subB = $this->subunidadDe($this->casilla($e->unidad1, 50, 30, $e->alumno));
        $e->subC = $this->subunidadDe($this->casilla($e->unidad2, 100, 50, $e->alumno));

        DefinitivasDeAsignatura::recalcular($e->asignatura, $e->periodo);
        $this->assertSame(0, $this->marcadasPorValor($e), 'El escenario nace con avisos.');

        return $e;
    }

    private function unidad(object $e, int $porcentaje): int
    {
        return (int) DB::table('unidades')->insertGetId([
            'asignatura_id' => $e->asignatura, 'periodo_id' => $e->periodo,
            'definicion' => 'UNIDAD AL '.$porcentaje.' %', 'porcentaje' => $porcentaje,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function casilla(int $unidadId, int $porcentaje, ?int $nota, int $alumnoId): int
    {
        $subunidadId = DB::table('subunidades')->insertGetId([
            'unidad_id' => $unidadId, 'definicion' => 'INDICADOR AL '.$porcentaje.' %',
            'porcentaje' => $porcentaje, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return (int) DB::table('notas')->insertGetId([
            'subunidad_id' => $subunidadId, 'alumno_id' => $alumnoId, 'nota' => $nota,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function subunidadDe(int $notaId): int
    {
        return (int) DB::table('notas')->where('id', $notaId)->value('subunidad_id');
    }

    /** Pone en la guardada un valor que no sale de ninguna cuenta, con fecha nueva. */
    private function estropear(object $e): void
    {
        DB::table('notas_finales')->where('alumno_id', $e->alumno)->where('asignatura_id', $e->asignatura)
            ->where('periodo_id', $e->periodo)->update(['nota' => 77.7, 'updated_at' => now()->addDay()]);
    }

    private function guardar(object $e, float $nota): void
    {
        DB::table('notas_finales')->where('alumno_id', $e->alumno)->where('asignatura_id', $e->asignatura)
            ->where('periodo_id', $e->periodo)->update(['nota' => $nota]);
    }

    private function guardada(object $e)
    {
        return DB::table('notas_finales')->where('alumno_id', $e->alumno)->where('asignatura_id', $e->asignatura)
            ->where('periodo_id', $e->periodo)->orderBy('id')->value('nota');
    }

    private function calculada(object $e): float
    {
        foreach (DefinitivasDeAsignatura::calcular($e->asignatura, $e->periodo) as $fila) {
            if ((int) $fila->alumno_id === $e->alumno) {
                return round((float) $fila->nota, 4);
            }
        }

        $this->fail('El alumno no salió en el cálculo.');
    }

    private function assertAlDiaYMovida(object $e, $antes): void
    {
        $this->assertNotNull($antes, 'El montaje no dejó definitiva guardada de partida.');
        $this->assertNotSame(round((float) $antes, 4), $this->calculada($e),
            'La escritura no cambió lo que vale la definitiva: el caso no mide nada.');
        $this->assertSame($this->calculada($e), round((float) $this->guardada($e), 4),
            'La escritura cambió lo que vale la definitiva y la guardada se quedó con la de antes.');
        $this->assertSame(0, $this->marcadasPorValor($e));
    }

    /** @return array{faltan:int, atrasadas:int} */
    private function filaDelTablero(object $e): array
    {
        foreach (DefinitivasDeAsignatura::diferenciasDelGrupo($e->grupo, $e->periodo) as $fila) {
            if ($fila['asignatura_id'] === $e->asignatura) {
                return $fila;
            }
        }

        $this->fail('La asignatura no salió en el tablero.');
    }

    private function marcadasPorValor(object $e): int
    {
        $f = $this->filaDelTablero($e);

        return $f['faltan'] + $f['atrasadas'];
    }

    private function marcadasPorSello(object $e): int
    {
        foreach (DefinitivasDeAsignatura::estadoDelGrupo($e->grupo, $e->periodo) as $fila) {
            if ($fila['asignatura_id'] === $e->asignatura) {
                return $fila['faltan'] + $fila['atrasadas'];
            }
        }

        return 0;
    }
}
