<?php

namespace Tests\Contrato;

use App\Services\DefinitivasDeAsignatura;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;

/**
 * El recalculo de definitivas **sube de alcance al entregarse**, y aqui esta medido.
 *
 *     docker exec -w /app/.worktrees/<sufijo> -e DB_TEST_DATABASE=simonbolivar_testing_<sufijo> \
 *         8myvc-app-1 php artisan test --group=rojo
 *
 * **ROJO a proposito**, y no duplica el de `12`. `SubunidadDeUnaUnidadConDuenoTest`
 * fija la primera mitad de `SubunidadesController::postIndex` —la linea **94**, que
 * crea **notas** para todo el grupo—. Este fija **la segunda**, la linea **97**:
 *
 *     $grupo = DB::selectOne('SELECT a.grupo_id FROM unidades u ... WHERE u.id = ?', ...);
 *     Nota::verificarCrearNotas($grupo->grupo_id, ...);                       // :94  <- test de 12
 *     DefinitivasDeAsignatura::recalcularPorSubunidad((int) $subunidad->id, ...);  // :97  <- este
 *
 * **El mismo metodo hace DOS traspasos**, y el censo de `bi-2.md` §4 sólo vio uno
 * porque miraba `verificarCrearNotas`. Los encontro
 * `tools/alcance-en-los-traspasos.py`.
 *
 * ## Lo que se afirma, y por que es rojo
 *
 * `DefinitivasDeAsignatura::recalcular()` **acepta un cuarto argumento
 * `$soloAlumno`** y filtra por el (`:192`). De sus **tres** puertas:
 *
 *     recalcularPorNota        pasa $donde->alumno_id   ->  ACOTADO
 *     recalcularPorUnidad      no lo pasa               ->  toda la asignatura
 *     recalcularPorSubunidad   no lo pasa               ->  toda la asignatura
 *
 * **El mecanismo para acotar existe y se usa en una de las tres.** A las otras dos
 * no se les paso — y estan vivas: `recalcularPorUnidad` la llaman dos sitios de
 * `UnidadesController` y `recalcularPorSubunidad` **tres** de `SubunidadesController`.
 *
 * Asi que el dia que una unidad tenga dueno, **tocar la subunidad de UN alumno
 * recalcula las definitivas de los treinta**. Igual que su hermano: *el alcance no
 * se pierde en la lectura, se pierde en el traspaso.*
 *
 * ## Lo que este test NO dice
 *
 * **No dice que el arreglo sea pasar `$soloAlumno`.** Recalcular la asignatura
 * entera es lo correcto en el caso normal —una unidad del grupo afecta a todos— y
 * cambiar de donde sale el alcance **es una decision**, no un arreglo: esta escrito
 * en la ficha de BI-3 y en `docs/migracion/noche-2026-08-25/bi-3.md`. Esto solo fija
 * **que hoy no se distingue**, para que la decision se tome delante de una prueba.
 */
#[Group('rojo')]
class RecalculoPorUnidadConDuenoTest extends CasoDeContrato
{
    /**
     * Con una unidad de UN alumno, el recalculo toca a los demas.
     *
     * Se mide sobre `notas_finales`: cuantas filas de esa asignatura y periodo
     * quedan con `updated_at` movido tras llamar a la puerta que no acota. La
     * comparacion es contra **la puerta que si acota** —`recalcularPorNota`—, que
     * es el control positivo: **si las dos tocaran lo mismo, el test no estaria
     * midiendo el traspaso sino otra cosa**, y lo dice al caer.
     */
    public function test_recalcular_por_unidad_no_deberia_tocar_las_definitivas_de_los_demas(): void
    {
        $unidad = DB::selectOne(
            'SELECT u.id, u.asignatura_id, u.periodo_id
               FROM unidades u
              INNER JOIN notas_finales nf ON nf.asignatura_id = u.asignatura_id
                                         AND nf.periodo_id = u.periodo_id
              WHERE u.deleted_at IS NULL
              GROUP BY u.id, u.asignatura_id, u.periodo_id
             HAVING COUNT(DISTINCT nf.alumno_id) > 1
              ORDER BY u.id
              LIMIT 1'
        );

        $this->assertNotNull($unidad,
            'El seed no tiene ninguna unidad cuya asignatura+periodo tenga definitivas de '
            .'mas de un alumno. Sin eso este test no puede distinguir «toco al dueno» de '
            .'«toco a todos», asi que no mide el traspaso.');

        $alumnos = DB::select(
            'SELECT DISTINCT alumno_id FROM notas_finales
              WHERE asignatura_id = ? AND periodo_id = ?',
            [$unidad->asignatura_id, $unidad->periodo_id]
        );

        $this->assertGreaterThan(1, count($alumnos),
            'Poblacion insuficiente: '.count($alumnos).' alumno(s) con definitiva en esta '
            .'asignatura y periodo.');

        // El dueno que tendria la unidad si el boletin independiente estuviera en uso.
        $dueno = (int) $alumnos[0]->alumno_id;

        DB::update(
            'UPDATE notas_finales SET updated_at = ? WHERE asignatura_id = ? AND periodo_id = ?',
            ['2000-01-01 00:00:00', $unidad->asignatura_id, $unidad->periodo_id]
        );

        DefinitivasDeAsignatura::recalcularPorUnidad((int) $unidad->id);

        $tocadas = DB::select(
            'SELECT alumno_id FROM notas_finales
              WHERE asignatura_id = ? AND periodo_id = ? AND updated_at > ?',
            [$unidad->asignatura_id, $unidad->periodo_id, '2000-01-01 00:00:00']
        );

        $ajenas = array_values(array_filter($tocadas,
            fn ($f) => (int) $f->alumno_id !== $dueno));

        // **EL CONTROL, DENTRO DEL TEST Y ANTES DEL ASERTO QUE IMPORTA.**
        //
        // La misma llamada **con** `$soloAlumno` tiene que tocar UNA fila. Si
        // tocara todas, la premisa de este test -que `recalcular()` sabe acotar y
        // que a estas dos puertas se les paso pasarselo- **seria falsa**, y
        // entonces el rojo de arriba no estaria midiendo un traspaso: estaria
        // midiendo que el recalculo no distingue a nadie, que es otro problema y
        // tiene otro arreglo.
        //
        // Sin esto, el test cae igual **por el motivo equivocado** y manda a
        // arreglar el sitio equivocado. Es la regla que BI-2 dejo escrita: el
        // control va DENTRO, no como comprobacion de una vez.
        DB::update(
            'UPDATE notas_finales SET updated_at = ? WHERE asignatura_id = ? AND periodo_id = ?',
            ['2000-01-01 00:00:00', $unidad->asignatura_id, $unidad->periodo_id]
        );

        DefinitivasDeAsignatura::recalcular(
            (int) $unidad->asignatura_id, (int) $unidad->periodo_id, null, $dueno
        );

        $conAlcance = DB::select(
            'SELECT alumno_id FROM notas_finales
              WHERE asignatura_id = ? AND periodo_id = ? AND updated_at > ?',
            [$unidad->asignatura_id, $unidad->periodo_id, '2000-01-01 00:00:00']
        );

        $this->assertLessThanOrEqual(1, count($conAlcance),
            'CONTROL CAIDO, y esto NO es el fallo que este test persigue: `recalcular()` '
            .'con `$soloAlumno` = '.$dueno.' toco '.count($conAlcance).' filas en vez de una. '
            .'Si el cuarto argumento no acota, entonces las tres puertas dan igual y el '
            .'problema no es el traspaso: es el recalculo. Arreglar `recalcularPorUnidad` '
            .'no serviria de nada.');

        $this->assertSame([], array_map(fn ($f) => (int) $f->alumno_id, $ajenas),
            'Recalcular por la unidad '.$unidad->id.' movio la definitiva de '
            .count($ajenas).' alumno(s) que no son su dueno, de '.count($alumnos).' con '
            .'definitiva en esa asignatura. `recalcularPorUnidad` no pasa `$soloAlumno` a '
            .'`recalcular()`, aunque el parametro existe y `recalcularPorNota` si lo usa. '
            .'El dia que una unidad tenga dueno, tocarla recalcula a todo el grupo.');
    }
}
