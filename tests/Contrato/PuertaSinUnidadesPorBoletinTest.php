<?php

namespace Tests\Contrato;

use App\Services\BoletinIndependiente;
use App\Services\DefinitivasDeAsignatura;
use Illuminate\Support\Facades\DB;

/**
 * «Sin unidades no se escribe» es **por boletín**, no por asignatura.
 *
 * La guarda la decidió Joseth el 28 ago 2026 y la fija
 * [`SinUnidadesNoSeEscribeTest`](SinUnidadesNoSeEscribeTest.php): con la asignatura
 * sin montar, `recalcular()` no escribe **una definitiva a cero por cada
 * matriculado**. Estaba escrita como un `EXISTS` sobre la asignatura entera, y eso
 * era exacto mientras cada asignatura tuviera **un solo reparto de unidades**.
 *
 * Con boletines independientes hay uno por boletín, y un solo booleano **contesta la
 * pregunta de otro en las dos direcciones**. Los dos casos dan el mismo síntoma —una
 * definitiva en cero— por motivos opuestos, que es la trampa que esta clase existe
 * para separar:
 *
 * | | Quién no tiene unidades | Qué pasaba |
 * |---|---|---|
 * | 1 | **el grupo**, y sí las tiene un independiente | `hay = 1`, y a los treinta del grupo se les escribe **el cero que esta guarda existe para no escribir**. Es el fallo del 28 ago entrando otra vez por una puerta nueva |
 * | 2 | **el marcado**, y sí las tiene el grupo | `hay = 1`, y se escribe **su** cero: es la §9.1 del [19](../../docs/migracion/19-boletin-independiente.md) —el alumno que se cae por el hueco— con una definitiva que parece una nota |
 *
 * **Con nadie marcado los dos casos son inalcanzables**, así que la suite entera no
 * podía verlos: todas las unidades son del grupo, y «el boletín del grupo tiene
 * unidades» y «la asignatura tiene unidades» son la misma frase. Por eso este fichero
 * **construye los dos escenarios** en vez de comprobar que nada se movió.
 */
class PuertaSinUnidadesPorBoletinTest extends CasoDeContrato
{
    /** Una asignatura del seed con unidades del grupo, notas, y su grupo. */
    private function asignaturaMontada(): object
    {
        $fila = DB::selectOne('SELECT u.asignatura_id, u.periodo_id, a.grupo_id, COUNT(*) AS notas
            FROM notas n
            INNER JOIN subunidades s ON s.id = n.subunidad_id AND s.deleted_at IS NULL AND n.deleted_at IS NULL
            INNER JOIN unidades u ON u.id = s.unidad_id AND u.deleted_at IS NULL AND u.alumno_id IS NULL
            INNER JOIN asignaturas a ON a.id = u.asignatura_id AND a.deleted_at IS NULL
            INNER JOIN periodos p ON p.id = u.periodo_id AND p.deleted_at IS NULL
            GROUP BY u.asignatura_id, u.periodo_id, a.grupo_id
            ORDER BY notas DESC LIMIT 1');

        $this->assertNotNull($fila, 'El seed necesita una asignatura con unidades del grupo y notas.');

        return $fila;
    }

    private function unMatriculado(int $grupoId): int
    {
        $fila = DB::selectOne('SELECT m.alumno_id FROM matriculas m
            WHERE m.grupo_id = ? AND m.deleted_at IS NULL AND m.estado IN ("MATR","ASIS")
            ORDER BY m.alumno_id LIMIT 1', [$grupoId]);

        $this->assertNotNull($fila, 'El seed necesita un matriculado en ese grupo.');

        return (int) $fila->alumno_id;
    }

    /**
     * Caso 1: el grupo sin montar y un independiente con lo suyo.
     *
     * **Es el que reintroduce el fallo del 28 ago por una puerta nueva.** Se borran
     * en blando las unidades del grupo, se marca a un alumno y se le monta una unidad
     * propia. Si la puerta sigue siendo un booleano de la asignatura, `hay = 1` y los
     * demás matriculados reciben su cero.
     */
    public function test_el_grupo_sin_unidades_no_recibe_ceros_porque_un_independiente_si_las_tenga(): void
    {
        BoletinIndependiente::olvidar();

        $e = $this->asignaturaMontada();
        $marcado = $this->unMatriculado((int) $e->grupo_id);

        // Fuera las del grupo: a partir de aquí el boletín del grupo NO está montado.
        DB::table('unidades')
            ->where('asignatura_id', $e->asignatura_id)
            ->where('periodo_id', $e->periodo_id)
            ->whereNull('alumno_id')
            ->whereNull('deleted_at')
            ->update(['deleted_at' => now()]);

        $this->marcarIndependiente($marcado, (int) $e->periodo_id);

        DB::insert(
            'INSERT INTO unidades (definicion, porcentaje, asignatura_id, periodo_id, alumno_id, orden, created_at, updated_at)
             VALUES ("Sólo suya", 100, ?, ?, ?, 0, NOW(), NOW())',
            [$e->asignatura_id, $e->periodo_id, $marcado]
        );

        // Se vacía la tabla para que cualquier fila que aparezca la haya escrito ESTE
        // recálculo: contar «antes y después» no distinguiría un cero nuevo de uno viejo.
        DB::table('notas_finales')
            ->where('asignatura_id', $e->asignatura_id)
            ->where('periodo_id', $e->periodo_id)
            ->delete();

        BoletinIndependiente::olvidar();
        DefinitivasDeAsignatura::recalcular((int) $e->asignatura_id, (int) $e->periodo_id, 999994);

        $delGrupo = DB::selectOne(
            'SELECT COUNT(*) AS n FROM notas_finales
              WHERE asignatura_id = ? AND periodo_id = ? AND alumno_id <> ?',
            [$e->asignatura_id, $e->periodo_id, $marcado]
        );

        $this->assertSame(0, (int) $delGrupo->n,
            'Se escribieron '.$delGrupo->n.' definitivas para alumnos cuyo boletín —el del grupo— '
            .'no tiene ni una unidad. Es exactamente el cero que la guarda del 28 ago existe para '
            .'no escribir, entrando por la puerta del boletín independiente: basta con que UN '
            .'alumno marcado tenga unidades propias para que la asignatura parezca montada.');
    }

    /**
     * Caso 2: el grupo montado y el marcado sin nada suyo.
     *
     * Es la §9.1 vista desde la escritura. Su definitiva en cero no es «sacó cero»:
     * es «su boletín no existe todavía», y escribirla la disfraza de nota.
     */
    public function test_el_marcado_sin_estructura_propia_no_recibe_un_cero(): void
    {
        BoletinIndependiente::olvidar();

        $e = $this->asignaturaMontada();
        $marcado = $this->unMatriculado((int) $e->grupo_id);

        // Marcado y SIN una sola unidad suya: el grupo sigue montado.
        $this->marcarIndependiente($marcado, (int) $e->periodo_id);

        DB::table('notas_finales')
            ->where('asignatura_id', $e->asignatura_id)
            ->where('periodo_id', $e->periodo_id)
            ->delete();

        BoletinIndependiente::olvidar();
        DefinitivasDeAsignatura::recalcular((int) $e->asignatura_id, (int) $e->periodo_id, 999993);

        $suya = DB::selectOne(
            'SELECT COUNT(*) AS n FROM notas_finales
              WHERE asignatura_id = ? AND periodo_id = ? AND alumno_id = ?',
            [$e->asignatura_id, $e->periodo_id, $marcado]
        );

        $this->assertSame(0, (int) $suya->n,
            'Al alumno '.$marcado.' —marcado, y sin una sola unidad propia— se le escribió una '
            .'definitiva. Su boletín no está montado: ese cero no significa «sacó cero», significa '
            .'«todavía no le han hecho el boletín», y escribirlo lo disfraza de nota. §9.1.');

        // Y la otra mitad, que es la que no puede caerse al arreglar la primera: a los
        // demás, cuyo boletín SÍ está montado, se les sigue escribiendo.
        $delGrupo = DB::selectOne(
            'SELECT COUNT(*) AS n FROM notas_finales
              WHERE asignatura_id = ? AND periodo_id = ? AND alumno_id <> ?',
            [$e->asignatura_id, $e->periodo_id, $marcado]
        );

        $this->assertGreaterThan(0, (int) $delGrupo->n,
            'No se escribió ninguna definitiva para el resto del grupo, que sí tiene su boletín '
            .'montado. La guarda se puso sobre el conjunto equivocado y ahora no escribe nadie.');
    }
}
