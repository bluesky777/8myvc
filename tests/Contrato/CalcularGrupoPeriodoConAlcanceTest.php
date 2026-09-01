<?php

namespace Tests\Contrato;

use App\Services\BoletinIndependiente;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;

/**
 * `putCalcularGrupoPeriodo` acotada: la única de las 25 donde equivocarse **escribe
 * filas** en vez de devolverlas.
 *
 * DEF-108. Salió de BI-2 a propósito, con ciclo propio. Es **el segundo escritor de
 * definitivas** y corre cada vez que alguien pulsa «calcular definitivas per N».
 *
 * ## Por qué la forma obvia no vale, y ésta sí
 *
 * Su consulta interior **no une con `matriculas`**, y para comparar con `ALCANCE` hay
 * que traerla. Las dos maneras naturales rompen:
 *
 *     INNER JOIN matriculas  -> deja fuera al alumno con notas y sin matrícula en ese
 *                               grupo — hoy los hay, es la §1.1 del 10 — y la
 *                               respuesta SE MUEVE
 *     LEFT JOIN matriculas   -> no deja a nadie fuera, pero un alumno con DOS
 *                               matrículas vivas en el mismo grupo multiplica filas y
 *                               la SUM() SE DOBLA
 *
 * > **Acotar una consulta que agrega no es añadir una condición: es cambiarle el
 * > conjunto de filas a un `SUM()`.**
 *
 * Por eso va con `BoletinIndependiente::alcanceCorrelacionado()`: una **subconsulta
 * escalar**, que da un valor o `NULL` y **no puede multiplicar**.
 *
 * ## El caso de las dos matrículas: **lo fabrica este test**
 *
 * **Medido el 24 ago 2026 sobre la base de desarrollo: `0` pares (alumno, grupo)
 * repetidos de 3.542 matrículas vivas.** Y `matriculas` tiene `PRIMARY KEY (id)` y
 * claves sueltas por `alumno_id` y `grupo_id`, pero **ninguna única sobre el par**:
 * nada lo impide, ni aquí ni en las otras quince bases que nadie ha mirado.
 *
 * Así que **este test construye el caso**, dentro de su transacción. *Un test que
 * fabrica su caso y no lo dice es el que alguien borra por irreal dentro de seis
 * meses.*
 *
 * Y no se elige la subconsulta **porque hoy los datos no dupliquen** —eso sería una
 * medición usada como guardián— **sino porque el esquema no lo impide**.
 */
class CalcularGrupoPeriodoConAlcanceTest extends CasoDeContrato
{
    public function test_una_segunda_matricula_no_dobla_la_definitiva(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        BoletinIndependiente::olvidar();

        [$grupo, $token] = $this->grupoYPersonal();

        $periodo = DB::selectOne(
            'SELECT p.id, p.numero FROM periodos p
              WHERE p.year_id = ? AND p.deleted_at IS NULL ORDER BY p.numero LIMIT 1',
            [$grupo->year_id]
        );

        $calcular = fn () => $this->putJson('/api/definitivas_periodos/calcular-grupo-periodo', [
            'grupo_id' => $grupo->id,
            'periodo_id' => $periodo->id,
            'num_periodo' => $periodo->numero,
        ], ['Authorization' => 'Bearer '.$token])->assertStatus(200);

        $definitivas = fn () => DB::select(
            'SELECT nf.alumno_id, nf.asignatura_id, nf.nota
               FROM notas_finales nf
               INNER JOIN asignaturas a ON a.id = nf.asignatura_id AND a.grupo_id = ?
              WHERE nf.periodo_id = ?
              ORDER BY nf.alumno_id, nf.asignatura_id',
            [$grupo->id, $periodo->id]
        );

        $calcular();
        $antes = array_map(fn ($f) => (array) $f, $definitivas());

        $this->assertNotEmpty($antes, 'No se escribió ninguna definitiva: el test no mide nada.');

        // ── El caso que el esquema permite y los datos no tienen ─────────────────
        $victima = DB::selectOne(
            'SELECT m.* FROM matriculas m
               INNER JOIN notas_finales nf ON nf.alumno_id = m.alumno_id AND nf.periodo_id = ?
              WHERE m.grupo_id = ? AND m.deleted_at IS NULL AND nf.nota > 0
              LIMIT 1',
            [$periodo->id, $grupo->id]
        );

        $this->assertNotNull($victima,
            'Ningún alumno del grupo tiene definitiva con nota > 0: sin eso, «se dobló» y «no se '
            .'dobló» son el mismo número y el test no distingue nada.');

        $copia = (array) $victima;
        unset($copia['id']);
        DB::table('matriculas')->insert($copia);

        $this->assertSame(2,
            (int) DB::selectOne('SELECT COUNT(*) c FROM matriculas WHERE alumno_id = ? AND grupo_id = ? AND deleted_at IS NULL',
                [$victima->alumno_id, $grupo->id])->c,
            'No se pudo fabricar el caso de las dos matrículas.');

        // ── Con el caso puesto, la definitiva NO se mueve ────────────────────────
        $calcular();
        $despues = array_map(fn ($f) => (array) $f, $definitivas());

        $this->assertSame($antes, $despues,
            'Una segunda matrícula del mismo alumno en el mismo grupo movió las definitivas. '
            .'La subconsulta escalar de `alcanceCorrelacionado()` no debería poder multiplicar '
            .'filas: si esto falla, se ha colado un JOIN por algún lado.');

        // ── El control: la forma ingenua SÍ dobla ────────────────────────────────
        //
        // La misma agregación con `LEFT JOIN matriculas`, que es la salida natural
        // cuando falta `m` en el ámbito. Tiene que dar MÁS que la correcta; si diera
        // lo mismo, la subconsulta escalar no compraría nada y esta clase sobraría.
        $suma = fn (string $desde) => (float) (DB::selectOne(
            'SELECT COALESCE(SUM(nt.ValorNota), 0) AS total FROM ('.$desde.') nt',
            [':periodo_id' => $periodo->id, ':grupo_id' => $grupo->id]
        )->total ?? 0);

        $comun = 'select u.asignatura_id, n.alumno_id, sum(((u.porcentaje/100)*((s.porcentaje/100)*n.nota))) ValorNota
                    from unidades u
                    inner join subunidades s on s.unidad_id=u.id and s.deleted_at is null and u.periodo_id=:periodo_id
                    inner join notas n on n.subunidad_id=s.id and n.deleted_at is null
                    inner join asignaturas asi2 on asi2.id=u.asignatura_id and asi2.deleted_at is null and asi2.grupo_id=:grupo_id';

        $correcta = $suma($comun.' where u.deleted_at is null
                    and u.alumno_id <=> '.BoletinIndependiente::alcanceCorrelacionado('n.alumno_id', 'u').'
                    group by n.alumno_id, u.id, s.id');

        $ingenua = $suma($comun.'
                    left join matriculas mnaif on mnaif.alumno_id = n.alumno_id and mnaif.grupo_id = asi2.grupo_id and mnaif.deleted_at is null
                    where u.deleted_at is null
                    group by n.alumno_id, u.id, s.id, mnaif.id');

        $this->assertGreaterThan($correcta, $ingenua,
            'La forma ingenua con `LEFT JOIN matriculas` NO dobló la suma, y eso invalida la '
            .'premisa de este test: si las dos formas dan lo mismo, la subconsulta escalar no '
            .'compra nada. Comprueba el montaje antes de creerte el verde de arriba. '
            .'(correcta='.$correcta.', ingenua='.$ingenua.')');
    }

    public function test_marcar_a_un_alumno_solo_mueve_lo_suyo(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        BoletinIndependiente::olvidar();

        [$grupo, $token] = $this->grupoYPersonal();

        $periodo = DB::selectOne(
            'SELECT p.id, p.numero FROM periodos p
              WHERE p.year_id = ? AND p.deleted_at IS NULL ORDER BY p.numero LIMIT 1',
            [$grupo->year_id]
        );

        $calcular = fn () => $this->putJson('/api/definitivas_periodos/calcular-grupo-periodo', [
            'grupo_id' => $grupo->id, 'periodo_id' => $periodo->id, 'num_periodo' => $periodo->numero,
        ], ['Authorization' => 'Bearer '.$token])->assertStatus(200);

        $porAlumno = function () use ($grupo, $periodo) {
            $filas = [];
            foreach (DB::select(
                'SELECT nf.alumno_id, SUM(nf.nota) AS suma FROM notas_finales nf
                   INNER JOIN asignaturas a ON a.id = nf.asignatura_id AND a.grupo_id = ?
                  WHERE nf.periodo_id = ? GROUP BY nf.alumno_id',
                [$grupo->id, $periodo->id]) as $f) {
                $filas[(int) $f->alumno_id] = (float) $f->suma;
            }
            ksort($filas);

            return $filas;
        };

        $calcular();
        $antes = $porAlumno();

        $conNota = array_keys(array_filter($antes, fn ($s) => $s > 0));
        $this->assertGreaterThan(1, count($conNota),
            'Hace falta más de un alumno con definitiva para distinguir «lo suyo» de «todo».');

        $marcado = $conNota[0];

        $this->marcarIndependiente($marcado, (int) $periodo->id);

        $calcular();
        $despues = $porAlumno();

        $this->assertSame(0.0, $despues[$marcado] ?? 0.0,
            'El alumno '.$marcado.' va por boletín independiente y NO tiene unidades propias, '
            .'así que su definitiva debería salir de cero notas. Siguió llevándose la del grupo: '
            .'el alcance no está llegando a la consulta que ESCRIBE.');

        foreach ($antes as $alumnoId => $suma) {
            if ($alumnoId === $marcado) {
                continue;
            }

            $this->assertSame($suma, $despues[$alumnoId] ?? null,
                'La definitiva del alumno '.$alumnoId.' cambió al marcar a OTRO. Y aquí eso no '
                .'es una lectura mal hecha: son filas escritas en `notas_finales`.');
        }
    }
}
