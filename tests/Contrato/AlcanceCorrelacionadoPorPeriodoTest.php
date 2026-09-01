<?php

namespace Tests\Contrato;

use App\Services\BoletinIndependiente;
use Illuminate\Support\Facades\DB;

/**
 * El alcance se resuelve **por periodo**, y una consulta que abarca varios necesita
 * que se resuelva dentro y no fuera.
 *
 * BI-2, trampa 1. `NotasPerdidasController` construye su rango con
 * `p.numero <= :periodo`, o sea que **una sola consulta abarca varios periodos**. Y
 * `bol_ind_periodos` es **por periodo**: un alumno puede ir por boletín
 * independiente en el 3 y no en el 2.
 *
 * > **Un `alcance($alumno, $periodo)` bindeado UNA vez daría el alcance del periodo
 * > equivocado para todos los demás, y no habría ningún error que lo señalara**: no
 * > falta una fila ni sobra otra — salen las unidades de otro boletín.
 *
 * ## Por qué este test existe y por qué es el único que distingue
 *
 * **Con nadie marcado, la forma correcta y la incorrecta dan el mismo verde.** Todo
 * es `NULL`, `<=> NULL` acierta en las dos, y la suite entera pasa igual con el
 * valor bindeado que con la subconsulta correlacionada. *El criterio de aceptación
 * del lote —«la respuesta no se mueve»— no puede ver esta diferencia.*
 *
 * Así que el test **construye el caso**: un alumno con `aplica = 1` en un periodo y
 * `aplica = 0` en el otro, con unidades propias en los dos. Si el alcance se
 * resolviera fuera, uno de los dos periodos traería las unidades equivocadas.
 *
 * Todo dentro de la transacción del test.
 */
class AlcanceCorrelacionadoPorPeriodoTest extends CasoDeContrato
{
    public function test_cada_periodo_resuelve_su_propio_alcance(): void
    {
        BoletinIndependiente::olvidar();

        $grupo = $this->grupoConAlumnos();

        $periodos = DB::select(
            'SELECT p.id, p.numero FROM periodos p
              WHERE p.year_id = ? AND p.deleted_at IS NULL
              ORDER BY p.numero LIMIT 2',
            [$grupo->year_id]
        );

        $this->assertCount(2, $periodos,
            'El seed no tiene dos periodos en este año: sin dos, «cada periodo el suyo» no '
            .'se puede distinguir de «uno para todos».');

        [$uno, $dos] = $periodos;

        $alumno = DB::selectOne(
            'SELECT m.alumno_id FROM matriculas m
              WHERE m.grupo_id = ? AND m.deleted_at IS NULL
                AND m.estado IN ("MATR","ASIS") LIMIT 1',
            [$grupo->id]
        );

        $asignatura = DB::selectOne(
            'SELECT a.id FROM asignaturas a WHERE a.grupo_id = ? AND a.deleted_at IS NULL LIMIT 1',
            [$grupo->id]
        );

        $this->assertNotNull($alumno, 'El seed no tiene alumno matriculado en este grupo.');
        $this->assertNotNull($asignatura, 'El seed no tiene asignatura en este grupo.');

        // **APAGADO en el primero, ENCENDIDO en el segundo.** Ésa es la asimetría que
        // el test viene a ejercer, y ahora se escribe con dos filas porque la marca es
        // por periodo (decisión 7): antes bastaba una —marcar el año en `matriculas`—
        // y la fila del periodo 1 era la excepción que lo apagaba.
        //
        // El periodo 1 se escribe **con `aplica = 0` y no dejándolo sin fila**, aunque
        // hoy las dos cosas den el mismo NULL. Es el caso de la §1 del plan: «este
        // periodo no tiene boletín independiente» es una decisión que alguien tomó y
        // que **no borra los datos**, y se distingue de «nunca estuvo marcado» — que es
        // lo que la pantalla necesita para pintar el badge.
        $this->marcarIndependiente((int) $alumno->alumno_id, (int) $uno->id, aplica: false);
        $this->marcarIndependiente((int) $alumno->alumno_id, (int) $dos->id);

        // ── Lo que dice el servicio, periodo a periodo ───────────────────────────
        $this->assertNull(
            BoletinIndependiente::alcance((int) $alumno->alumno_id, (int) $uno->id),
            'En el periodo '.$uno->numero.' está apagado por `bol_ind_periodos.aplica = 0`, '
            .'así que su alcance debería ser NULL — las unidades del grupo.');

        $this->assertSame((int) $alumno->alumno_id,
            BoletinIndependiente::alcance((int) $alumno->alumno_id, (int) $dos->id),
            'En el periodo '.$dos->numero.' la fila dice `aplica = 1`, así que su alcance '
            .'debería ser él mismo — sus propias unidades.');

        // ── Y lo que dice el SQL correlacionado, en UNA consulta que abarca los dos ──
        //
        // Una unidad propia en cada periodo. Si el alcance se resolviera fuera de la
        // consulta, la del periodo apagado entraría igual (o la del encendido se
        // quedaría fuera), y eso es lo que aquí se distingue.
        foreach ([$uno, $dos] as $p) {
            DB::insert('INSERT INTO unidades (definicion, porcentaje, asignatura_id, periodo_id, alumno_id, orden, created_at, updated_at)
                        VALUES (?, 50, ?, ?, ?, 1, NOW(), NOW())',
                ['Propia del periodo '.$p->numero, $asignatura->id, $p->id, $alumno->alumno_id]);
        }

        $suyas = DB::select(
            'SELECT u.id, u.periodo_id, u.alumno_id
               FROM unidades u
               INNER JOIN periodos p ON p.id = u.periodo_id AND p.numero <= ? AND p.deleted_at IS NULL
              WHERE u.asignatura_id = ? AND u.deleted_at IS NULL
                AND u.alumno_id <=> '.BoletinIndependiente::alcanceCorrelacionado((string) (int) $alumno->alumno_id, 'u'),
            [$dos->numero, $asignatura->id]
        );

        $porPeriodo = [];

        foreach ($suyas as $u) {
            $porPeriodo[(int) $u->periodo_id][] = $u->alumno_id === null ? 'del grupo' : 'suya';
        }

        $this->assertNotContains('suya', $porPeriodo[(int) $uno->id] ?? [],
            'En el periodo '.$uno->numero.' —donde está APAGADO— la consulta trajo su unidad '
            .'propia. El alcance no se está resolviendo por periodo: con un valor bindeado una '
            .'sola vez, el periodo apagado hereda el alcance del encendido.');

        $this->assertContains('suya', $porPeriodo[(int) $dos->id] ?? [],
            'En el periodo '.$dos->numero.' —donde está ENCENDIDO— la consulta NO trajo su '
            .'unidad propia. El alcance del periodo apagado se está aplicando a los dos.');

        $this->assertNotContains('del grupo', $porPeriodo[(int) $dos->id] ?? [],
            'En el periodo '.$dos->numero.' salieron además unidades del grupo: un alumno con '
            .'boletín propio no debe llevarse las dos.');

        // ── El control, DENTRO del test y no como comprobación de una vez ────────
        //
        // La misma consulta con el alcance **bindeado una sola vez** — la forma
        // ingenua, la que sale sola al leer «acótala con `alcance()`»—. Tiene que dar
        // un resultado DISTINTO y equivocado. Si diera lo mismo, la subconsulta
        // correlacionada no estaría comprando nada y esta clase entera sobraría.
        $ingenua = DB::select(
            'SELECT u.periodo_id, u.alumno_id
               FROM unidades u
               INNER JOIN periodos p ON p.id = u.periodo_id AND p.numero <= ? AND p.deleted_at IS NULL
              WHERE u.asignatura_id = ? AND u.deleted_at IS NULL AND u.alumno_id <=> ?',
            [$dos->numero, $asignatura->id,
                BoletinIndependiente::alcance((int) $alumno->alumno_id, (int) $dos->id)]
        );

        $suyasEnElApagado = array_filter($ingenua,
            fn ($u) => (int) $u->periodo_id === (int) $uno->id && $u->alumno_id !== null);

        $this->assertNotSame([], $suyasEnElApagado,
            'La forma ingenua —alcance bindeado una vez— NO se equivocó, y eso invalida la '
            .'premisa de este test: si las dos formas dan lo mismo, la subconsulta '
            .'correlacionada no compra nada. Comprueba el montaje antes de creerte el verde '
            .'de arriba.');
    }
}
