<?php

namespace Tests\Contrato;

use App\Services\DefinitivasDeAsignatura;
use Illuminate\Support\Facades\DB;

/**
 * **Dos definitivas a cero que se ven iguales y se escriben por motivos
 * opuestos.** Este fichero existe para que no se vuelvan a confundir.
 *
 * | Situación | ¿Se escribe la fila a cero? | Por qué |
 * |---|---|---|
 * | La asignatura **no tiene ninguna unidad** en el periodo | **NO** | Decisión de Joseth, 28 ago 2026 |
 * | Hay unidades y **este alumno no tiene notas** | **SÍ** | Es la **regla 1** y no se toca |
 *
 * La regla 1 de `DefinitivasDeAsignatura` —los alumnos salen de `matriculas`, no
 * de `notas`— existe por un motivo bueno: que un alumno sin ninguna nota conserve
 * su fila, que es lo que los seis escritores viejos no hacen. Su **efecto
 * secundario** era que, con el periodo sin montar, escribía una definitiva a cero
 * por cada matriculado.
 *
 * Y llegaba por un camino que nadie miraba: `UnidadesController::deleteDestroy`
 * llama a `recalcularPorUnidad` **después** del borrado, así que **borrar la
 * última unidad de un periodo escribía treinta ceros firmados por quien la
 * borró**. Medido el 28 ago 2026 y contado entero en
 * [`noche-2026-08-28/desact-1.md`](../../docs/migracion/noche-2026-08-28/desact-1.md) §5.
 *
 * ## Por qué hacen falta los dos casos y no basta el primero
 *
 * Porque un test que sólo comprobara «sin unidades no escribe» pasa igual si
 * alguien corta de más y deja de escribir **también** cuando el alumno no tiene
 * notas — que es deshacer la regla 1 sin decirlo, y el síntoma sería el mismo que
 * esta clase viene a arreglar, sólo que al revés y sobre alumnos matriculados.
 *
 * Es la lección de las dos correcciones de la noche, escrita como test: **dos
 * conjuntos distintos con el mismo síntoma**, y un número que no los separa no
 * contesta la pregunta.
 */
class SinUnidadesNoSeEscribeTest extends CasoDeContrato
{
    /** Una asignatura y periodo con unidades vivas y notas puestas. */
    private function asignaturaMontada(): object
    {
        $fila = DB::selectOne('SELECT u.asignatura_id, u.periodo_id, a.grupo_id, COUNT(*) AS notas
            FROM notas n
            INNER JOIN subunidades s ON s.id = n.subunidad_id AND s.deleted_at IS NULL AND n.deleted_at IS NULL
            INNER JOIN unidades u ON u.id = s.unidad_id AND u.deleted_at IS NULL
            INNER JOIN asignaturas a ON a.id = u.asignatura_id AND a.deleted_at IS NULL
            INNER JOIN periodos p ON p.id = u.periodo_id AND p.deleted_at IS NULL
            GROUP BY u.asignatura_id, u.periodo_id, a.grupo_id
            ORDER BY notas DESC LIMIT 1');

        $this->assertNotNull($fila, 'El seed necesita una asignatura con unidades y notas.');

        return $fila;
    }

    /**
     * **Sin ninguna unidad viva, `recalcular()` no escribe.**
     *
     * Se borran las unidades igual que las borra `deleteDestroy` —borrado blando— y
     * se dispara el recálculo, que es literalmente lo que hace esa ruta.
     */
    public function test_sin_unidades_no_escribe_ninguna_definitiva(): void
    {
        $e = $this->asignaturaMontada();
        $firma = 999997;

        DB::table('unidades')
            ->where('asignatura_id', $e->asignatura_id)
            ->where('periodo_id', $e->periodo_id)
            ->whereNull('deleted_at')
            ->update(['deleted_at' => now()]);

        $r = DefinitivasDeAsignatura::recalcular(
            (int) $e->asignatura_id, (int) $e->periodo_id, $firma
        );

        $this->assertSame(0, $r['escritas'] + $r['creadas'],
            'Recalculó una asignatura que no tiene ni una unidad en el periodo. '.
            'Eso escribe una definitiva a cero por cada matriculado, y es lo que '.
            'convertía «borrar la última unidad» en «treinta ceros».');

        $firmadas = DB::table('notas_finales')
            ->where('asignatura_id', $e->asignatura_id)
            ->where('periodo_id', $e->periodo_id)
            ->where('updated_by', $firma)
            ->count();

        $this->assertSame(0, $firmadas, 'Alguna fila quedó firmada por este recálculo.');
    }

    /**
     * **Y tampoco borra lo que ya hubiera**, que es la otra mitad de la decisión.
     *
     * Joseth eligió «no se escribe» sobre «se borra lo viejo»: la limpieza de las
     * definitivas huérfanas la hace el botón de Informes, que ya la hace. Sin este
     * caso, alguien puede leer el de arriba como permiso para vaciar el periodo.
     */
    public function test_sin_unidades_tampoco_borra_lo_que_ya_habia(): void
    {
        $e = $this->asignaturaMontada();

        $antes = DB::table('notas_finales')
            ->where('asignatura_id', $e->asignatura_id)
            ->where('periodo_id', $e->periodo_id)
            ->count();

        if ($antes === 0) {
            $this->markTestSkipped('Sin definitivas previas no se mide si las borra.');
        }

        DB::table('unidades')
            ->where('asignatura_id', $e->asignatura_id)
            ->where('periodo_id', $e->periodo_id)
            ->whereNull('deleted_at')
            ->update(['deleted_at' => now()]);

        DefinitivasDeAsignatura::recalcular((int) $e->asignatura_id, (int) $e->periodo_id, 999996);

        $despues = DB::table('notas_finales')
            ->where('asignatura_id', $e->asignatura_id)
            ->where('periodo_id', $e->periodo_id)
            ->count();

        $this->assertSame($antes, $despues,
            'El recálculo sin unidades borró definitivas. La decisión fue no escribir, '.
            'no limpiar: lo viejo lo quita el botón de Informes.');
    }

    /**
     * **La regla 1, intacta: con unidades, el alumno sin notas SÍ recibe su cero.**
     *
     * Éste es el caso que no puede caerse al arreglar el de arriba. Se fabrica
     * borrando blandamente las notas de UN alumno —dejando las unidades y las de
     * los demás en pie— y quitándole la fila, para que el recálculo tenga que
     * **crearla**.
     */
    public function test_con_unidades_el_alumno_sin_notas_si_recibe_su_fila(): void
    {
        $e = $this->asignaturaMontada();
        $firma = 999995;

        $alumno = DB::selectOne('SELECT m.alumno_id FROM matriculas m
            WHERE m.grupo_id = ? AND m.deleted_at IS NULL AND m.estado IN ("MATR","ASIS")
            ORDER BY m.alumno_id LIMIT 1', [$e->grupo_id]);

        $this->assertNotNull($alumno, 'El seed necesita un matriculado en ese grupo.');

        // Que no tenga ni una nota viva en esa asignatura y periodo...
        DB::update('UPDATE notas n
            INNER JOIN subunidades s ON s.id = n.subunidad_id
            INNER JOIN unidades u ON u.id = s.unidad_id
            SET n.deleted_at = NOW()
            WHERE u.asignatura_id = ? AND u.periodo_id = ? AND n.alumno_id = ?
              AND n.deleted_at IS NULL',
            [$e->asignatura_id, $e->periodo_id, $alumno->alumno_id]);

        // ...ni fila, para que el recálculo tenga que crearla.
        DB::table('notas_finales')
            ->where('asignatura_id', $e->asignatura_id)
            ->where('periodo_id', $e->periodo_id)
            ->where('alumno_id', $alumno->alumno_id)
            ->delete();

        DefinitivasDeAsignatura::recalcular((int) $e->asignatura_id, (int) $e->periodo_id, $firma);

        $suya = DB::selectOne(
            'SELECT nota, updated_by FROM notas_finales
              WHERE alumno_id = ? AND asignatura_id = ? AND periodo_id = ? ORDER BY id LIMIT 1',
            [$alumno->alumno_id, $e->asignatura_id, $e->periodo_id]
        );

        $this->assertNotNull($suya,
            'Un alumno matriculado, con la asignatura montada y sin notas, se quedó sin '.
            'definitiva. Eso es la regla 1 deshecha: la fila existe siempre que exista '.
            'la matrícula. Si la guarda de «sin unidades» se comió este caso, la '.
            'condición está puesta sobre el conjunto equivocado.');

        $this->assertSame(0, (int) $suya->nota, 'Sin notas, la definitiva es cero.');
        $this->assertSame($firma, (int) $suya->updated_by, 'La fila no la escribió este recálculo.');
    }

    /**
     * **La casilla vacía sigue siendo escribible, que es la condición con la que
     * Joseth aprobó el `null`.**
     *
     * Su frase, 28 ago 2026: *«quisiera que saliera vacío, nulo, pero si el usuario
     * edita el input vacío espero que pueda crear y guardar el nuevo valor
     * manual»*. O sea que la casilla en blanco **no es de sólo lectura**: es una
     * fila que todavía no existe y que el docente crea al escribir.
     *
     * El front comprobó su mitad —la rejilla pinta `?? ''`, el input queda
     * habilitado, y `cambiarNota` sin `nf_id` llama a `crearNota`—. **Ésta es la
     * mitad del backend, que no la había comprobado nadie**: la rama `else` de
     * `putUpdate`, la que resuelve el periodo por `num_periodo` e inserta. Antes de
     * la guarda de esta clase esa rama casi nunca hacía falta, porque el boletín
     * sembraba la fila al abrirse; ahora es **la única puerta** por la que nace la
     * definitiva de una casilla vacía.
     *
     * Lo que este caso protege, dicho para quien lo vea en rojo: si alguien
     * «limpia» `putUpdate` quitándole la rama sin `nf_id` por parecer un duplicado
     * de la de arriba, **las casillas vacías se vuelven de sólo lectura** y no hay
     * ningún error que lo diga — el front manda la petición y recibe un 4xx que
     * parece de permisos.
     *
     * Y la fila nace con `manual = 1`, que es lo que la hace sobrevivir al botón de
     * Informes: su `DELETE` respeta las manuales. Lo que el docente escriba en una
     * casilla vacía no se lo lleva el siguiente recálculo.
     */
    public function test_una_casilla_sin_fila_se_puede_crear_escribiendola(): void
    {
        $e = $this->asignaturaMontada();

        $periodo = DB::selectOne('SELECT p.id, p.numero, p.year_id FROM periodos p WHERE p.id = ?', [$e->periodo_id]);

        $alumno = DB::selectOne('SELECT m.alumno_id FROM matriculas m
            WHERE m.grupo_id = ? AND m.deleted_at IS NULL AND m.estado IN ("MATR","ASIS")
            ORDER BY m.alumno_id LIMIT 1', [$e->grupo_id]);

        $this->assertNotNull($alumno, 'El seed necesita un matriculado en ese grupo.');

        // La casilla vacía: sin unidades y sin fila, que es el estado nuevo.
        DB::table('unidades')
            ->where('asignatura_id', $e->asignatura_id)->where('periodo_id', $e->periodo_id)
            ->whereNull('deleted_at')->update(['deleted_at' => now()]);

        DB::table('notas_finales')
            ->where('asignatura_id', $e->asignatura_id)->where('periodo_id', $e->periodo_id)
            ->where('alumno_id', $alumno->alumno_id)->delete();

        // El token primero: `Services\Login` reescribe `users.periodo_id` al entrar.
        $token = $this->tokenDelPersonalDe((int) $periodo->year_id);

        $respuesta = $this->withToken($token)->putJson('/api/definitivas_periodos/update', [
            'alumno_id' => $alumno->alumno_id,
            'asignatura_id' => $e->asignatura_id,
            'num_periodo' => $periodo->numero,
            'nota' => 37,
        ]);

        $respuesta->assertStatus(200);

        $creada = DB::selectOne(
            'SELECT nota, manual FROM notas_finales
              WHERE alumno_id = ? AND asignatura_id = ? AND periodo_id = ? ORDER BY id LIMIT 1',
            [$alumno->alumno_id, $e->asignatura_id, $e->periodo_id]
        );

        $this->assertNotNull($creada,
            'Escribir en una casilla vacía no creó la definitiva. Eso rompe la condición '.
            'con la que se aprobó que la casilla saliera vacía: si no se puede crear, '.
            'el `null` deja de ser una casilla en blanco y pasa a ser una nota perdida.');

        $this->assertSame(37, (int) $creada->nota, 'Se creó la fila pero no con la nota tecleada.');
        $this->assertSame(1, (int) $creada->manual,
            'La definitiva tecleada nació sin `manual = 1`, así que el botón de Informes '.
            'se la llevará en el siguiente recálculo.');
    }
}
