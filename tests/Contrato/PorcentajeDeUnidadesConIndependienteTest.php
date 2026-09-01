<?php

namespace Tests\Contrato;

use App\Services\BoletinIndependiente;
use App\Services\DefinitivasDeAsignatura;
use Illuminate\Support\Facades\DB;

/**
 * `porcentajeDeLasUnidades()` devuelve el reparto de UN boletín, no la suma de dos.
 *
 * **Este fichero estaba en el grupo `rojo` y sale de él el 31 ago 2026.** Era el
 * único caso del [19](../../docs/migracion/19-boletin-independiente.md) donde acotar
 * no era añadir una condición: la función contestaba *«¿las unidades de esta
 * asignatura suman 100?»* devolviendo un `float`, y con boletines independientes esa
 * pregunta **no tiene una sola respuesta** — hay un reparto por boletín, el del grupo
 * y el de cada alumno marcado. Sumarlos daba un número que **no era el de ninguno**.
 *
 * El rojo esperaba *«las dos preguntas del 19 §2, que son de Joseth»*, y **están
 * contestadas** (decisiones 5, 6 y 7). Así que se le quita el grupo y pasa a la suite,
 * que es lo que un rojo a propósito existe para poder hacer: ser la red del arreglo y
 * no una queja archivada.
 *
 * ## Lo que se comprueba, y por qué el caso vacío va primero
 *
 * Con nadie marcado, la firma vieja y la nueva dan el mismo número —todas las unidades
 * son del grupo—, así que un test que sólo mirase «no se movió» **pasaría con el fallo
 * dentro**. Por eso el sujeto es la asimetría: montado el independiente, el reparto del
 * grupo tiene que seguir siendo el de antes y el suyo tiene que ser el suyo.
 */
class PorcentajeDeUnidadesConIndependienteTest extends CasoDeContrato
{
    /** Una asignatura con unidades del grupo y un alumno matriculado en él. */
    private function escenario(): object
    {
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

        $donde->alumno_id = (int) $alumno->alumno_id;

        return $donde;
    }

    public function test_el_reparto_del_grupo_no_se_mueve_cuando_alguien_va_aparte(): void
    {
        BoletinIndependiente::olvidar();

        $e = $this->escenario();

        $delGrupo = DefinitivasDeAsignatura::porcentajeDeLasUnidades(
            (int) $e->asignatura_id, (int) $e->periodo_id, null);

        $this->assertGreaterThan(0, $delGrupo,
            'El reparto del grupo es 0: sin él, sumarle el del independiente no se notaría.');

        $this->marcarIndependiente($e->alumno_id, (int) $e->periodo_id);

        DB::insert(
            'INSERT INTO unidades (definicion, porcentaje, asignatura_id, periodo_id, alumno_id, orden, created_at, updated_at)
             VALUES ("Unidad propia del independiente", 40, ?, ?, ?, 1, NOW(), NOW())',
            [$e->asignatura_id, $e->periodo_id, $e->alumno_id]
        );

        BoletinIndependiente::olvidar();

        $this->assertSame($delGrupo,
            DefinitivasDeAsignatura::porcentajeDeLasUnidades((int) $e->asignatura_id, (int) $e->periodo_id, null),
            'El reparto del boletín del GRUPO cambió al montarle 40 % propio a un alumno que ya no '
            .'va con el grupo. Eso es la suma de dos repartos, y no es el de ninguno: la planilla '
            .'señalaría en rojo una asignatura que está bien configurada.');
    }

    public function test_el_independiente_tiene_su_propio_reparto(): void
    {
        BoletinIndependiente::olvidar();

        $e = $this->escenario();
        $this->marcarIndependiente($e->alumno_id, (int) $e->periodo_id);

        DB::insert(
            'INSERT INTO unidades (definicion, porcentaje, asignatura_id, periodo_id, alumno_id, orden, created_at, updated_at)
             VALUES ("Unidad propia del independiente", 40, ?, ?, ?, 1, NOW(), NOW())',
            [$e->asignatura_id, $e->periodo_id, $e->alumno_id]
        );

        BoletinIndependiente::olvidar();

        $suyo = DefinitivasDeAsignatura::porcentajeDeLasUnidades(
            (int) $e->asignatura_id, (int) $e->periodo_id,
            BoletinIndependiente::alcance($e->alumno_id, (int) $e->periodo_id));

        $this->assertSame(40.0, $suyo,
            'Su reparto tiene que ser el de SUS unidades —40 %, y en rojo en la pantalla porque no '
            .'llega a 100—, no el del grupo ni la suma de los dos.');
    }

    /**
     * El `<=>` es la mitad del arreglo, y con `=` este test es el que se cae.
     *
     * Con `= ?` y `null` bindeado, la rama del grupo **no empareja ninguna fila** y el
     * reparto sale 0 para todo el mundo — que en esta pantalla se lee como «asignatura
     * sin montar», no como un error.
     */
    public function test_sin_nadie_marcado_el_reparto_del_grupo_son_todas_las_unidades(): void
    {
        BoletinIndependiente::olvidar();

        $e = $this->escenario();

        $todas = DB::selectOne(
            'SELECT COALESCE(SUM(porcentaje), 0) AS suma FROM unidades
              WHERE asignatura_id = ? AND periodo_id = ? AND deleted_at IS NULL',
            [$e->asignatura_id, $e->periodo_id]
        );

        $this->assertSame((float) $todas->suma,
            DefinitivasDeAsignatura::porcentajeDeLasUnidades((int) $e->asignatura_id, (int) $e->periodo_id, null),
            'Con nadie marcado, todas las unidades son del grupo y el reparto del grupo tiene que '
            .'ser exactamente el de antes del lote. Si esto sale 0, el `<=>` se convirtió en `=`.');
    }
}
