<?php

namespace Tests\Contrato;

use App\Models\NotaFinal;
use App\Services\BoletinIndependiente;
use Illuminate\Support\Facades\DB;

/**
 * La definitiva **automática** de la pantalla de definitivas por periodo no suma
 * los dos repartos de un independiente.
 *
 * `NotaFinal::consultaAlumnosGrupoNotaFinal()` alimenta
 * `DefinitivasPeriodosController`, la pantalla donde se ve, al lado de la definitiva
 * guardada, **la que saldría de recalcular**. Sus cuatro derivadas —una por periodo—
 * suman `unidades × subunidades × notas` agrupando por `n.alumno_id`.
 *
 * ## El fallo que este test construye, y por qué no se veía
 *
 * Un alumno marcado a mitad de periodo **conserva sus notas viejas en las subunidades
 * del grupo**: la §1 del [19](../../docs/migracion/19-boletin-independiente.md) dice
 * explícitamente que marcar **no borra los datos**, sólo hace que se ignoren. Sin
 * alcance, esta consulta las suma igual, y encima **le añade** las de sus unidades
 * propias.
 *
 * Es la forma «de más» de la §9.2, y sale por pantalla de la peor manera posible: la
 * columna «automática» aparece inflada **al lado de la guardada, que es la correcta**,
 * o sea que la pantalla acusa de estar mal a la que está bien. Un docente que pulse
 * «actualizar» ahí guarda el número inflado.
 *
 * **Con nadie marcado el caso es inalcanzable** —no hay dos repartos que sumar—, así
 * que ni la suite ni las instantáneas podían verlo.
 */
class DefinitivaAutomaticaDeLaPantallaTest extends CasoDeContrato
{
    /**
     * Una asignatura con unidades del grupo y notas **en el periodo número 1**, que
     * es el que mira `def_materia_auto_1`.
     */
    private function escenario(): object
    {
        $fila = DB::selectOne(
            'SELECT a.id AS asignatura_id, a.grupo_id, u.periodo_id, n.alumno_id
               FROM asignaturas a
               INNER JOIN unidades u    ON u.asignatura_id = a.id AND u.deleted_at IS NULL
                                       AND u.alumno_id IS NULL
               INNER JOIN periodos p    ON p.id = u.periodo_id AND p.numero = 1 AND p.deleted_at IS NULL
               INNER JOIN subunidades s ON s.unidad_id = u.id AND s.deleted_at IS NULL
               INNER JOIN notas n       ON n.subunidad_id = s.id AND n.deleted_at IS NULL AND n.nota > 0
               INNER JOIN matriculas m  ON m.grupo_id = a.grupo_id AND m.alumno_id = n.alumno_id
                                       AND m.deleted_at IS NULL AND m.estado IN ("MATR","ASIS")
              WHERE a.deleted_at IS NULL
              LIMIT 1'
        );

        $this->assertNotNull($fila,
            'El seed necesita una asignatura con unidades del grupo y notas en el periodo 1: '
            .'sin notas del grupo no hay «de más» que medir.');

        return $fila;
    }

    /** La `def_materia_auto_1` que la pantalla enseñaría para ese alumno. */
    private function automaticaDelPrimerPeriodo(object $e): ?float
    {
        $parametros = [':grupo_id' => $e->grupo_id];

        foreach (range(1, 8) as $i) {
            $parametros[':asign_id'.$i] = $e->asignatura_id;
        }

        foreach (DB::select(NotaFinal::consultaAlumnosGrupoNotaFinal(), $parametros) as $fila) {
            if ((int) $fila->alumno_id === (int) $e->alumno_id) {
                return $fila->def_materia_auto_1 === null ? null : (float) $fila->def_materia_auto_1;
            }
        }

        $this->fail('El alumno '.$e->alumno_id.' no salió en la consulta: el escenario no mide nada.');
    }

    public function test_al_marcado_no_se_le_suman_las_notas_que_tenia_en_el_grupo(): void
    {
        BoletinIndependiente::olvidar();

        $e = $this->escenario();

        $conElGrupo = $this->automaticaDelPrimerPeriodo($e);

        $this->assertNotNull($conElGrupo, 'Su automática con el grupo es null: no hay nada que comparar.');
        $this->assertGreaterThan(0, $conElGrupo,
            'Su automática con el grupo es 0: sin ella, sumarle lo propio no se notaría.');

        // Se le marca el periodo y se le monta SU estructura, con una nota suya. Sus
        // notas del grupo **se quedan donde están**, que es lo que pidió el colegio.
        $this->marcarIndependiente((int) $e->alumno_id, (int) $e->periodo_id);

        DB::insert(
            'INSERT INTO unidades (definicion, porcentaje, asignatura_id, periodo_id, alumno_id, orden, created_at, updated_at)
             VALUES ("Sólo suya", 100, ?, ?, ?, 0, NOW(), NOW())',
            [$e->asignatura_id, $e->periodo_id, $e->alumno_id]
        );
        $unidad = (int) DB::getPdo()->lastInsertId();

        DB::insert(
            'INSERT INTO subunidades (definicion, porcentaje, unidad_id, orden, created_at, updated_at)
             VALUES ("Sólo suya", 100, ?, 0, NOW(), NOW())',
            [$unidad]
        );
        $subunidad = (int) DB::getPdo()->lastInsertId();

        DB::insert(
            'INSERT INTO notas (nota, subunidad_id, alumno_id, created_at, updated_at)
             VALUES (50, ?, ?, NOW(), NOW())',
            [$subunidad, $e->alumno_id]
        );

        BoletinIndependiente::olvidar();

        $suya = $this->automaticaDelPrimerPeriodo($e);

        // 100 % de unidad × 100 % de subunidad × nota 50 = 50.
        $this->assertSame(50.0, $suya,
            'Su automática salió '.var_export($suya, true).' y su boletín son 50 exactos: una '
            .'unidad al 100 %, una subunidad al 100 % y un 50. Si salió '.($conElGrupo + 50).', '
            .'la consulta está sumando las notas que conserva en las subunidades del GRUPO —que '
            .'marcar no borra, a propósito— más las suyas. Es la forma «de más» de la §9.2, y por '
            .'pantalla acusa de estar mal a la definitiva guardada, que es la correcta.');
    }

    /**
     * Y la mitad que no puede caerse al arreglar la otra: **al compañero no le pasa
     * nada**. Si el alcance se hubiera escrito con `=` en vez de `<=>`, la rama del
     * alumno normal no emparejaría ninguna fila y su automática se iría a null.
     */
    public function test_al_companero_sin_marcar_no_le_cambia_nada(): void
    {
        BoletinIndependiente::olvidar();

        $e = $this->escenario();

        $companero = DB::selectOne(
            'SELECT m.alumno_id FROM matriculas m
              WHERE m.grupo_id = ? AND m.alumno_id <> ? AND m.deleted_at IS NULL
                AND m.estado IN ("MATR","ASIS") LIMIT 1',
            [$e->grupo_id, $e->alumno_id]
        );

        $this->assertNotNull($companero, 'El seed necesita un segundo alumno en el grupo.');

        $suyoAntes = $this->automaticaDelPrimerPeriodo((object) [
            'grupo_id' => $e->grupo_id, 'asignatura_id' => $e->asignatura_id,
            'alumno_id' => $companero->alumno_id,
        ]);

        $this->marcarIndependiente((int) $e->alumno_id, (int) $e->periodo_id);

        $suyoDespues = $this->automaticaDelPrimerPeriodo((object) [
            'grupo_id' => $e->grupo_id, 'asignatura_id' => $e->asignatura_id,
            'alumno_id' => $companero->alumno_id,
        ]);

        $this->assertSame($suyoAntes, $suyoDespues,
            'Marcar al alumno '.$e->alumno_id.' le movió la automática al compañero '
            .$companero->alumno_id.'. Si pasó a null, el alcance se escribió con `=` en vez de '
            .'`<=>`: el igual null-safe es lo que hace que la rama del alumno normal empareje '
            .'las unidades del grupo, y con `=` a secas no empareja ninguna.');
    }
}
