<?php

namespace Tests\Contrato;

use App\Services\BoletinIndependiente;
use App\Services\DefinitivasDeAsignatura;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;

/**
 * `porcentajeDeLasUnidades()` devuelve un número que deja de significar algo.
 *
 * **ROJO a propósito**, y **NO entra en BI-2**: es la única de las 59 donde acotar
 * no es añadir una condición.
 *
 * ## Por qué no se acota
 *
 * La función contesta *«¿las unidades de esta asignatura suman 100?»* y devuelve
 * **un `float`**. Con boletines independientes **esa pregunta deja de tener una
 * sola respuesta**: hay un reparto por boletín —el del grupo y el de cada alumno
 * marcado—, así que el retorno correcto no es un número sino uno por boletín.
 *
 * Eso no es una acotación mal hecha ni un cambio de firma: **la función está
 * definida sobre un mundo donde cada asignatura tiene un solo reparto de
 * unidades**, y ese mundo es justo el que el
 * [19](../../docs/migracion/19-boletin-independiente.md) viene a terminar. Y qué
 * significa «suman 100» cuando hay dos boletines **es de las dos preguntas del
 * 19 §2, que son de Joseth**. Por la regla del propio lote: se anota, no entra.
 *
 * ## Quién lo consume hoy: **nadie**, y ahí está el matiz
 *
 * | quién | qué hace con él |
 * |---|---|
 * | `DefinitivasDeAsignatura::recalcular()` | lo devuelve en `porcentaje_unidades` |
 * | `NotasController:392`, el único que guarda el retorno | lee **sólo** `definitiva` |
 * | `myvc_front` · `myvc_front_2` · `myvc_flutter` | **cero menciones** en los tres |
 *
 * **No hay ningún consumidor que decida nada con ese número.** Su consumidor está
 * *previsto* y no construido: el docblock del servicio dice que se devuelve *«para
 * que quien pinte la planilla pueda señalarla en vez de taparla»*, y esa planilla
 * no existe todavía.
 *
 * > Eso lo hace **barato de dejar y peligroso de olvidar**: el día que alguien
 * > construya esa señal en la planilla, la construirá sobre un número que ya está
 * > mal y **no hay nada hoy que se lo diga**. Por eso el rojo va aquí y no en una
 * > nota: **el primero que se rompe es un consumidor que aún no existe**, y ésos no
 * > aparecen en ningún censo de llamadores.
 *
 * ## Qué lo pondría verde
 *
 * Que la función **reciba el alcance y devuelva un reparto por boletín** — algo
 * como `porcentajeDeLasUnidades(int $asignaturaId, int $periodoId, ?int $alcance)`
 * devolviendo el reparto de ese boletín, o un mapa `[alcance => float]`. Con eso,
 * `recalcular()` puede decir de qué boletín es el número que devuelve, y quien
 * pinte la planilla puede señalar **la asignatura de un alumno concreto**.
 */
#[Group('rojo')]
class PorcentajeDeUnidadesConIndependienteTest extends CasoDeContrato
{
    public function test_el_porcentaje_no_deberia_sumar_el_reparto_de_dos_boletines(): void
    {
        BoletinIndependiente::olvidar();

        $grupo = $this->grupoConAlumnos();

        $donde = DB::selectOne(
            'SELECT u.asignatura_id, u.periodo_id
               FROM unidades u
               INNER JOIN asignaturas a ON a.id = u.asignatura_id AND a.grupo_id = ?
              WHERE u.deleted_at IS NULL AND u.alumno_id IS NULL
              GROUP BY u.asignatura_id, u.periodo_id LIMIT 1',
            [$grupo->id]
        );

        $this->assertNotNull($donde, 'El seed no tiene unidades del grupo: el test no mide nada.');

        $alumno = DB::selectOne(
            'SELECT m.alumno_id FROM matriculas m
              WHERE m.grupo_id = ? AND m.deleted_at IS NULL
                AND m.estado IN ("MATR","ASIS") LIMIT 1',
            [$grupo->id]
        );

        $this->assertNotNull($alumno, 'El seed no tiene un alumno matriculado en este grupo.');

        $delGrupo = DefinitivasDeAsignatura::porcentajeDeLasUnidades(
            (int) $donde->asignatura_id, (int) $donde->periodo_id);

        $this->assertGreaterThan(0, $delGrupo,
            'El reparto del grupo es 0: sin él, sumar el del independiente no se notaría.');

        // Un alumno con boletín independiente y su propio reparto de unidades.
        $this->marcarIndependiente((int) $alumno->alumno_id, (int) $donde->periodo_id);

        DB::insert(
            'INSERT INTO unidades (definicion, porcentaje, asignatura_id, periodo_id, alumno_id, orden, created_at, updated_at)
             VALUES ("Unidad propia del independiente", 40, ?, ?, ?, 1, NOW(), NOW())',
            [$donde->asignatura_id, $donde->periodo_id, $alumno->alumno_id]
        );

        BoletinIndependiente::olvidar();

        $ahora = DefinitivasDeAsignatura::porcentajeDeLasUnidades(
            (int) $donde->asignatura_id, (int) $donde->periodo_id);

        $this->assertSame($delGrupo, $ahora,
            'Devolvió '.$ahora.' donde el reparto del boletín del grupo sigue siendo '.$delGrupo.
            ' y el del alumno '.$alumno->alumno_id.' es 40. **Ese número no es el reparto de '
            .'ningún boletín: es la suma de los dos.** No se arregla acotando la consulta — la '
            .'función devuelve UN float y con independientes hay uno por boletín. Ver el '
            .'docblock de esta clase y la §6 de docs/migracion/noche-2026-08-25/bi-2.md.');
    }
}
