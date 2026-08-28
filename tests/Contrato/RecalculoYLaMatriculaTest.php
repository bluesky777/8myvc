<?php

namespace Tests\Contrato;

use App\Services\DefinitivasDeAsignatura;
use Illuminate\Support\Facades\DB;

/**
 * **Quién entra en un recálculo de definitivas: los dos recalculadores no
 * contestan lo mismo, y uno de los dos va a sustituir al otro.**
 *
 * Joseth decidió el 28 ago 2026, a propósito de la pérdida de definitivas del
 * botón de Informes: *«el recálculo debe cubrir a todos los alumnos, incluidos
 * los que se fueron, igual que hacen los informes»*. Su razonamiento es que un
 * informe imprime las notas de los retirados, así que calcular sobre un conjunto
 * más estrecho que el que se imprime es una incoherencia.
 *
 * Al ir a aplicarlo apareció que **la respuesta dependía de a cuál de los dos se
 * lo preguntaras**, y que la que iba a ganar era la equivocada:
 *
 * | Quién recalcula | ¿Filtra por `matriculas.estado`? |
 * |---|---|
 * | `PUT definitivas_periodos/calcular-grupo-periodo` | no, y nunca lo hizo |
 * | `App\Services\DefinitivasDeAsignatura` | **sí, hasta el 28 ago 2026** |
 *
 * No eran dos caminos alternativos: el servicio es **la fase 3** de
 * [10-definitivas.md](../../docs/migracion/10-definitivas.md), o sea el que
 * sustituye al botón y a los otros cinco escritores, y ya está vivo en
 * `unidades/*`, `subunidades/*` y `notas/*`. La regla de Joseth **se cumplía en el
 * que se va y se incumplía en el que llega**, y la sustitución se la habría
 * llevado por delante sin ningún error: sólo un retirado que deja de tener
 * definitiva.
 *
 * **Cerrado el 28 ago 2026**: el filtro salió de `calcular()` y los dos
 * recalculadores dicen ya lo mismo. Este fichero deja de fijar una divergencia y
 * pasa a fijar **la conducta que costó encontrarla** — que es lo que hay que
 * defender, porque `m.estado IN ("MATR","ASIS")` es la línea que cualquiera vuelve
 * a escribir creyendo que limpia.
 *
 * ## Por qué esto es un test y no una línea en un documento
 *
 * Porque la decisión salió «no hay nada que hacer» —el botón ya cumplía— y ésa es
 * exactamente la clase de conclusión que no deja rastro en el código. Medido el 28
 * ago 2026 sobre la base de desarrollo, el botón repone hoy **6.435 pares
 * (alumno, asignatura) de alumnos RETI y 442 de alumnos sin matrícula viva**, de
 * 125.082 en 379 combinaciones grupo+periodo. Eso es lo que hay que no perder.
 *
 * ## Lo que este test NO dice
 *
 * **No dice cuál de las dos conductas es la correcta.** Fija las dos como están
 * hoy. El día que la fase 3 unifique, uno de los dos casos de aquí se pone en
 * rojo, y ése es el momento de traer la frase de Joseth y decidir a la vista de
 * las dos — que es justo lo que no pudo hacerse esta vez, porque la divergencia
 * no estaba escrita en ninguna parte.
 *
 * ## Y un aviso para quien venga a «limpiar» el filtro que falta
 *
 * En el `SELECT` del botón hay **un** sitio que toca `matriculas`, y no es un
 * filtro de estado: la subconsulta de `BoletinIndependiente::alcanceCorrelacionado`,
 * que decide si las notas de un alumno se leen del boletín normal o del suyo. Sólo
 * mira `boletin_independiente`, nunca `estado`, y cuando el alumno no tiene
 * matrícula devuelve `NULL` —que significa «no es independiente»— y por eso los
 * 442 sin matrícula entran. **Quitarla creyendo que es el filtro de los retirados
 * rompe el boletín independiente**, que es el [19](../../docs/migracion/19-boletin-independiente.md).
 */
class RecalculoYLaMatriculaTest extends CasoDeContrato
{
    /**
     * Un alumno con notas de verdad en una asignatura y periodo, **y su matrícula
     * puesta a `RETI`** dentro de la transacción del test.
     *
     * Se fuerza el estado en vez de buscar un retirado que ya lo esté porque lo
     * que se mide es el criterio, no el seed: si mañana el seed se queda sin
     * retirados en el sitio justo, un test que los busque se salta solo y deja de
     * avisar. Aquí el retirado siempre existe.
     */
    private function retiradoConNotas(): object
    {
        $fila = DB::selectOne('SELECT u.asignatura_id, u.periodo_id, a.grupo_id, n.alumno_id,
                    p.numero, p.year_id, COUNT(*) AS notas
            FROM notas n
            INNER JOIN subunidades s ON s.id = n.subunidad_id AND s.deleted_at IS NULL AND n.deleted_at IS NULL
            INNER JOIN unidades u ON u.id = s.unidad_id AND u.deleted_at IS NULL
            INNER JOIN asignaturas a ON a.id = u.asignatura_id AND a.deleted_at IS NULL
            INNER JOIN periodos p ON p.id = u.periodo_id AND p.deleted_at IS NULL
            INNER JOIN matriculas m ON m.alumno_id = n.alumno_id AND m.grupo_id = a.grupo_id
                 AND m.deleted_at IS NULL
            GROUP BY u.asignatura_id, u.periodo_id, a.grupo_id, n.alumno_id, p.numero, p.year_id
            ORDER BY notas DESC, n.alumno_id LIMIT 1');

        $this->assertNotNull($fila, 'El seed necesita un alumno con notas para medir esto.');

        // El retirado se fabrica aquí: `DatabaseTransactions` lo deshace al salir.
        DB::table('matriculas')
            ->where('alumno_id', $fila->alumno_id)
            ->where('grupo_id', $fila->grupo_id)
            ->whereNull('deleted_at')
            ->update(['estado' => 'RETI']);

        return $fila;
    }

    /** La definitiva de ese alumno en esa asignatura y periodo, o `null`. */
    private function definitivaDe(object $e): ?object
    {
        return DB::selectOne(
            'SELECT id, nota, manual, recuperada, updated_by FROM notas_finales
              WHERE alumno_id = ? AND asignatura_id = ? AND periodo_id = ? ORDER BY id LIMIT 1',
            [$e->alumno_id, $e->asignatura_id, $e->periodo_id]
        );
    }

    /**
     * **El botón de Informes SÍ repone al que se fue**, que es la conducta que
     * Joseth confirmó el 28 ago 2026.
     *
     * El `DELETE` de `putCalcularGrupoPeriodo` se lleva todas las automáticas del
     * grupo y periodo sin mirar la matrícula, así que si el `INSERT` filtrara por
     * estado, un retirado perdería su definitiva **cada vez que alguien pulsa el
     * botón** y no habría forma de recuperarla. No la filtra, y eso es lo que se
     * clava aquí.
     */
    public function test_el_boton_repone_la_definitiva_de_un_alumno_retirado(): void
    {
        $e = $this->retiradoConNotas();

        $profesor = DB::selectOne('SELECT u.username FROM users u
            INNER JOIN profesores pr ON pr.user_id = u.id AND pr.deleted_at IS NULL
            WHERE u.tipo = "Profesor" AND u.is_active = 1 AND u.deleted_at IS NULL
            ORDER BY u.id LIMIT 1');

        $this->assertNotNull($profesor, 'El seed necesita un Profesor para llamar a la ruta.');

        $token = $this->tokenDe($profesor->username);

        // Que la fila de partida no sea manual ni recuperada: si lo fuera, el DELETE
        // la respetaría y el test pasaría sin haber probado nada.
        DB::table('notas_finales')
            ->where('alumno_id', $e->alumno_id)
            ->where('asignatura_id', $e->asignatura_id)
            ->where('periodo_id', $e->periodo_id)
            ->update(['manual' => 0, 'recuperada' => 0]);

        $this->withToken($token)->putJson('/api/definitivas_periodos/calcular-grupo-periodo', [
            'grupo_id' => $e->grupo_id,
            'periodo_id' => $e->periodo_id,
            'num_periodo' => $e->numero,
        ])->assertStatus(200);

        $this->assertNotNull($this->definitivaDe($e),
            'El recálculo dejó sin definitiva a un alumno RETI que tiene notas. '.
            'Alguien le puso un filtro de matrícula al SELECT, y el DELETE de arriba '.
            'no lo tiene: eso es una pérdida garantizada por diseño.');
    }

    /**
     * **El servicio de la fase 3 también lo repone**, desde el 28 ago 2026.
     *
     * Se afirma sobre `updated_by` y no sobre la existencia de la fila: la fila
     * puede seguir ahí de antes. Lo que se mide es si **este** recálculo la tocó,
     * que es la única forma de distinguir «lo cubre» de «ya estaba».
     *
     * Y se mide con la fila puesta a automática primero: si fuera `manual` o
     * `recuperada` el servicio la respetaría —regla 4— y el caso pasaría a medir
     * otra cosa sin avisar.
     */
    public function test_el_servicio_de_la_fase_3_tambien_repone_al_retirado(): void
    {
        $e = $this->retiradoConNotas();

        $firma = 999999;

        DB::table('notas_finales')
            ->where('alumno_id', $e->alumno_id)
            ->where('asignatura_id', $e->asignatura_id)
            ->where('periodo_id', $e->periodo_id)
            ->update(['manual' => 0, 'recuperada' => 0]);

        DefinitivasDeAsignatura::recalcular(
            (int) $e->asignatura_id,
            (int) $e->periodo_id,
            $firma
        );

        $suya = $this->definitivaDe($e);

        $this->assertNotNull($suya,
            'El retirado se quedó sin definitiva tras el recálculo del servicio.');

        $this->assertSame($firma, (int) $suya->updated_by,
            'El servicio no tocó la definitiva de un alumno RETI: alguien le devolvió '.
            'el `m.estado IN ("MATR","ASIS")` a `calcular()`. Esa línea deshace la '.
            'decisión de Joseth del 28 ago 2026 y le quita la definitiva a 6.435 pares '.
            'sin un solo error. Léela en el comentario que hay donde estaba.');
    }

    /**
     * **Y que los dos hablen del mismo alumno**, que es lo que convierte lo de
     * arriba en una divergencia y no en dos tests sobre dos cosas distintas.
     *
     * Sin este caso, alguien que lea los dos de arriba puede pensar que el servicio
     * no tocó al retirado porque no tenía notas que calcular. Las tiene: el mismo
     * recálculo escribe para los demás alumnos de esa asignatura.
     */
    public function test_el_servicio_si_escribe_para_los_demas_de_esa_asignatura(): void
    {
        $e = $this->retiradoConNotas();

        $resultado = DefinitivasDeAsignatura::recalcular(
            (int) $e->asignatura_id,
            (int) $e->periodo_id,
            999998
        );

        $this->assertGreaterThan(0, $resultado['escritas'] + $resultado['creadas'],
            'El servicio no escribió nada para nadie en esa asignatura, así que el '.
            'caso de arriba no distingue «deja fuera al retirado» de «no hizo nada».');
    }
}
