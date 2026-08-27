<?php

namespace Tests\Contrato;

use App\Services\BoletinIndependiente;
use App\Services\DefinitivasDeAsignatura;
use Illuminate\Support\Facades\DB;

/**
 * Editar la unidad **de un alumno** no puede reescribirle la definitiva a los otros treinta.
 *
 * `DefinitivasDeAsignatura::recalcular()` acepta un cuarto argumento, `$soloAlumno`, que acota
 * **la escritura** sin tocar el cálculo. De sus tres puertas, `recalcularPorNota` lo pasaba y
 * las otras dos —`recalcularPorUnidad` y `recalcularPorSubunidad`— no. **No era que no se
 * pudiera acotar: es que a dos se les pasó.**
 *
 * ## Por qué el arreglo NO está en los cinco llamadores
 *
 * Porque **ninguno tiene un alumno a mano, y no es un descuido suyo**: los cinco editan o
 * borran una unidad o una subunidad —`UnidadesController::putUpdate` y `deleteDestroy`, y los
 * tres de `SubunidadesController`—, y **una unidad del grupo le cambia la definitiva a los
 * treinta**, así que ahí recalcular entero es lo correcto.
 *
 * Lo que distingue los dos casos no está en el llamante: está en la unidad. `unidades.alumno_id`
 * dice de quién es. **El detector que produjo la lista contaba bien el síntoma —cinco sitios sin
 * acotar— y no estaba contando la causa**, que es una sola y vive dos capas más abajo.
 *
 * ## Qué se rompe si no está, y por qué hoy no se ve
 *
 * Una unidad con dueño **sólo entra en el cálculo de ese alumno** — lo hace `calcular()`, con su
 * `c.dueno <=> ALCANCE`. Recalcular la asignatura entera desde ahí no es que sea caro: es que
 * **`recalcular()` crea la fila que falta** —los alumnos salen de `matriculas`, regla 1—, así
 * que aparecen definitivas **a cero donde no había ninguna**, firmadas por quien editó la unidad
 * de otro. Sin un error en el log.
 *
 * Hoy no pasa porque `unidades.alumno_id` es NULL en todas las filas de los quince colegios.
 * **Esto es la red puesta antes de que haya con qué caerse**, y por eso el test tiene que
 * fabricar el dueño: no lo hay en ningún seed.
 *
 * Ver `docs/migracion/05-codigo-muerto-y-roto.md` §237.
 */
class RecalculoAcotadoAlDuenoTest extends CasoDeContrato
{
    /** Una unidad con notas, su asignatura y su periodo, con más de un alumno con definitiva. */
    private function unidadConNotas(): object
    {
        $donde = DB::selectOne(
            'SELECT u.id AS unidad_id, u.asignatura_id, u.periodo_id, a.grupo_id
               FROM unidades u
               INNER JOIN asignaturas a ON a.id = u.asignatura_id AND a.deleted_at IS NULL
               INNER JOIN subunidades s ON s.unidad_id = u.id AND s.deleted_at IS NULL
               INNER JOIN notas n ON n.subunidad_id = s.id AND n.deleted_at IS NULL
              WHERE u.deleted_at IS NULL AND u.alumno_id IS NULL
              GROUP BY u.id, u.asignatura_id, u.periodo_id, a.grupo_id
              LIMIT 1');

        $this->assertNotNull($donde, 'El seed necesita una unidad con notas.');

        return $donde;
    }

    /** @return array<int, array{nota:int, updated_at:?string}> Lo GUARDADO, no lo calculado. */
    private function guardadas(object $donde): array
    {
        $filas = [];

        foreach (DB::select(
            'SELECT alumno_id, nota, updated_at FROM notas_finales
              WHERE asignatura_id = ? AND periodo_id = ? ORDER BY alumno_id',
            [$donde->asignatura_id, $donde->periodo_id]
        ) as $f) {
            $filas[(int) $f->alumno_id] = ['nota' => (int) $f->nota, 'updated_at' => $f->updated_at];
        }

        return $filas;
    }

    /**
     * El caso de hoy, que **no se toca**: una unidad del grupo le cambia la definitiva a
     * todos, y por eso recalcular entero desde ahí es lo correcto.
     *
     * Va primero porque es la mitad que el arreglo podría haber roto, y es la que corre en los
     * quince colegios ahora mismo.
     */
    public function test_una_unidad_del_grupo_sigue_recalculando_a_todos(): void
    {
        BoletinIndependiente::olvidar();
        $donde = $this->unidadConNotas();

        // Se vacía la tabla de esta asignatura para que «escribió» se vea contando filas y no
        // comparando sellos de tiempo, que dentro del mismo segundo no distinguen.
        DB::delete('DELETE FROM notas_finales WHERE asignatura_id = ? AND periodo_id = ?',
            [$donde->asignatura_id, $donde->periodo_id]);

        $r = DefinitivasDeAsignatura::recalcularPorUnidad((int) $donde->unidad_id);

        $this->assertNotNull($r);
        $this->assertGreaterThan(1, count($this->guardadas($donde)),
            'Una unidad del grupo tiene que reponer la definitiva de TODO el grupo. Si esto '
            .'baja a uno, el acotado se comió el caso normal, que es el que corre hoy.');
    }

    /**
     * Y el que trae el arreglo: con dueño, escribe **una fila y sólo una**.
     *
     * Es el test que va rojo sobre el código de antes.
     */
    public function test_una_unidad_con_dueno_solo_escribe_la_de_su_dueno(): void
    {
        BoletinIndependiente::olvidar();
        $donde = $this->unidadConNotas();

        $alumnos = array_keys($this->guardadas($donde));
        $this->assertGreaterThan(1, count($alumnos),
            'Con un solo alumno con definitiva, «escribe una» y «escribe todas» son lo mismo.');

        $dueno = (int) $alumnos[0];

        // El dueño, que hoy no existe en ningún colegio: se fabrica y se deshace con el test.
        DB::update('UPDATE unidades SET alumno_id = ? WHERE id = ?', [$dueno, $donde->unidad_id]);

        DB::delete('DELETE FROM notas_finales WHERE asignatura_id = ? AND periodo_id = ?',
            [$donde->asignatura_id, $donde->periodo_id]);

        DefinitivasDeAsignatura::recalcularPorUnidad((int) $donde->unidad_id);

        $this->assertSame([$dueno], array_keys($this->guardadas($donde)),
            'Editar la unidad de UN alumno escribió definitivas de más. Cada una es una fila '
            .'nueva a cero para alguien que no tenía ninguna, firmada por quien no le tocó nada.');
    }

    /** Lo mismo por la puerta de la subunidad, que es la que llaman tres sitios y no dos. */
    public function test_por_subunidad_tambien_se_acota(): void
    {
        BoletinIndependiente::olvidar();
        $donde = $this->unidadConNotas();

        $subunidad = DB::selectOne(
            'SELECT id FROM subunidades WHERE unidad_id = ? AND deleted_at IS NULL LIMIT 1',
            [$donde->unidad_id]);
        $this->assertNotNull($subunidad);

        $alumnos = array_keys($this->guardadas($donde));
        $this->assertGreaterThan(1, count($alumnos));
        $dueno = (int) $alumnos[0];

        DB::update('UPDATE unidades SET alumno_id = ? WHERE id = ?', [$dueno, $donde->unidad_id]);
        DB::delete('DELETE FROM notas_finales WHERE asignatura_id = ? AND periodo_id = ?',
            [$donde->asignatura_id, $donde->periodo_id]);

        DefinitivasDeAsignatura::recalcularPorSubunidad((int) $subunidad->id);

        $this->assertSame([$dueno], array_keys($this->guardadas($donde)),
            'La subunidad cuelga de una unidad con dueño: el alcance es el de la unidad.');
    }

    /**
     * Y la mitad que se olvida: **acotar la escritura no puede cambiar el número**.
     *
     * `$soloAlumno` filtra lo que se escribe, no lo que se calcula. Si el valor del dueño
     * saliera distinto al acotar, el arreglo estaría cambiando definitivas de un colegio en el
     * despliegue, que es lo contrario de lo que viene a hacer.
     */
    public function test_acotar_no_cambia_el_numero_del_dueno(): void
    {
        BoletinIndependiente::olvidar();
        $donde = $this->unidadConNotas();

        $alumnos = array_keys($this->guardadas($donde));
        $this->assertGreaterThan(1, count($alumnos));
        $dueno = (int) $alumnos[0];

        DB::update('UPDATE unidades SET alumno_id = ? WHERE id = ?', [$dueno, $donde->unidad_id]);

        // El valor que el cálculo le da al dueño con la unidad ya marcada, sin acotar nada.
        $calculado = null;

        foreach (DefinitivasDeAsignatura::calcular((int) $donde->asignatura_id, (int) $donde->periodo_id) as $f) {
            if ((int) $f->alumno_id === $dueno) {
                $calculado = (int) $f->nota;
            }
        }

        $this->assertNotNull($calculado);

        DB::delete('DELETE FROM notas_finales WHERE asignatura_id = ? AND periodo_id = ?',
            [$donde->asignatura_id, $donde->periodo_id]);

        DefinitivasDeAsignatura::recalcularPorUnidad((int) $donde->unidad_id);

        $this->assertSame($calculado, $this->guardadas($donde)[$dueno]['nota'],
            'Acotar la escritura movió el número. `$soloAlumno` filtra lo que se guarda, '
            .'nunca lo que se calcula.');
    }
}
