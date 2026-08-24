<?php

namespace Tests\Contrato;

use App\Models\Unidad;
use App\Services\BoletinIndependiente;
use Illuminate\Support\Facades\DB;

/**
 * `Unidad::deAsignatura` con el alcance del boletin independiente.
 *
 * **Este test se escribio ANTES de acotar nada**, y no es una preferencia de
 * estilo: es lo unico que discrimina. Con `boletin_independiente` a 0 en todas las
 * matriculas y `alumno_id` NULL en todas las unidades, **la forma correcta y la
 * incorrecta dan el mismo verde** — un test escrito despues del arreglo aqui no
 * comprueba el arreglo, comprueba que nada se movio, que es otra cosa.
 *
 * ## Lo que hace que esto no sea inventar una regla nueva
 *
 * El alcance **ya esta escrito** en `BoletinIndependiente::alcance()`, y el metodo
 * hermano de este —`Unidad::deAsignaturaCalculada`, cinco llamadas— **ya lo usa**
 * (`Unidad:111`), con el `<=>` y su porque encima. `deAsignatura` es el mismo
 * metodo sin acotar, con **diecisiete** llamadores.
 *
 * *No se escribe como se elige la matricula: se usa la que ya esta escrita.* Es la
 * regla que BI-2 pago con un `LIMIT 1` que leia el interruptor de 2024 para un
 * periodo de 2026.
 *
 * ## Las dos mitades, y cada una sin la otra no prueba nada
 *
 * 1. **Con nadie marcado, `deAsignatura` y `deAsignaturaCalculada` traen las
 *    mismas unidades.** Es el criterio de aceptacion: acotar es un **no-op hoy**,
 *    asi que el cambio de conducta es latente y no actual.
 * 2. **Con un alumno marcado y una unidad suya, tienen que separarse.** Sin esta
 *    mitad, la primera se cumpliria igual **sobre el codigo sin acotar** — y de
 *    hecho es lo que pasa hoy: comprobar que algo no cambio no prueba que el
 *    cambio este.
 *
 * La marca y el dueno se ponen **dentro de la transaccion del test**: no salen de
 * aqui.
 *
 * ## Lo que este fichero NO es
 *
 * **No es la red de ninguna acotada.** Compara `deAsignatura` con
 * `deAsignaturaCalculada` —los dos metodos del modelo— y **ningun llamador pasa
 * por aqui**. Fija que el arreglo se puede hacer y que hoy no mueve nada; la red
 * de cada llamador acotado es un test propio, con la respuesta del endpoint
 * delante, y va en el commit de esa acotada.
 */
class UnidadDeAsignaturaConAlcanceTest extends CasoDeContrato
{
    /**
     * Hoy las dos formas coinciden, y ese es el criterio de aceptacion.
     *
     * Si esto cayera, acotar **no** seria un no-op y el lote entero cambiaria de
     * tamano: habria que mirar que respuesta se mueve antes de tocar nada.
     */
    public function test_sin_nadie_marcado_las_dos_formas_traen_lo_mismo(): void
    {
        BoletinIndependiente::olvidar();

        [$alumnoId, $asignaturaId, $periodoId] = $this->unAlumnoConUnidades();

        $sinAcotar = $this->ids(Unidad::deAsignatura($asignaturaId, $periodoId));
        $acotada = $this->ids(Unidad::deAsignaturaCalculada($alumnoId, $asignaturaId, $periodoId));

        $this->assertSame($sinAcotar, $acotada,
            'Con nadie marcado como boletin independiente, `deAsignatura` y '
            .'`deAsignaturaCalculada` tienen que traer LAS MISMAS unidades. Si no coinciden, '
            .'acotar `deAsignatura` NO es un no-op y hay que medir que respuesta se mueve '
            .'antes de tocar los diecisiete llamadores.');

        $this->assertNotEmpty($sinAcotar,
            'Ninguna de las dos trajo unidades: el seed no ejerce nada y las dos mitades de '
            .'este test se cumplirian por vacio.');
    }

    /**
     * **EL CONTROL de la premisa. Y NO esta en rojo: pasa hoy, a proposito.**
     *
     * Esto hay que decirlo antes que nada porque el docblock de este metodo decia
     * «la mitad que hoy esta en rojo» **antes de correrlo**, y es falso. Lo que
     * este test fija es **la premisa del lote** —que el alcance ya esta escrito y
     * sabe separar— **no la red de ninguna acotada**: compara los dos metodos del
     * modelo entre si, y ninguno de los diecisiete llamadores pasa por aqui.
     *
     * **La red de cada acotada es otro test y va con ella**, uno por commit. Este
     * es el que dice que el arreglo *se puede* hacer y que hoy *no mueve nada*.
     *
     * Con un alumno marcado y una unidad que es suya, la forma acotada tiene que
     * traer **la suya** y la sin acotar sigue trayendo **las del grupo**. Ese es
     * exactamente el fallo que el alcance viene a impedir: el dia que una unidad
     * tenga dueno, los diecisiete llamadores de `deAsignatura` **le dan al
     * independiente las unidades de los otros treinta**.
     *
     * **Si las dos formas coincidieran aqui, el test cae diciendo que su premisa se
     * cayo** —que `deAsignaturaCalculada` sabe separar— y entonces el problema no
     * seria `deAsignatura`: seria el alcance, y tendria otro arreglo.
     */
    public function test_con_un_alumno_marcado_la_forma_acotada_se_separa(): void
    {
        BoletinIndependiente::olvidar();

        [$alumnoId, $asignaturaId, $periodoId] = $this->unAlumnoConUnidades();

        // Su matricula pasa a boletin independiente, y una unidad pasa a ser suya.
        DB::update(
            'UPDATE matriculas SET boletin_independiente = 1
              WHERE alumno_id = ? AND deleted_at IS NULL',
            [$alumnoId]
        );

        DB::insert(
            'INSERT INTO unidades (asignatura_id, periodo_id, alumno_id, definicion, porcentaje, orden, created_at, updated_at)
             VALUES (?, ?, ?, "unidad del independiente", 100, 99, NOW(), NOW())',
            [$asignaturaId, $periodoId, $alumnoId]
        );

        BoletinIndependiente::olvidar();

        $delGrupo = $this->ids(Unidad::deAsignatura($asignaturaId, $periodoId));
        $delAlumno = $this->ids(Unidad::deAsignaturaCalculada($alumnoId, $asignaturaId, $periodoId));

        // CONTROL: la premisa es que la forma acotada SABE separar. Si no separa,
        // este test no esta midiendo `deAsignatura`.
        $this->assertNotSame($delGrupo, $delAlumno,
            'CONTROL CAIDO, y esto NO es el fallo que este test persigue: con el alumno '
            .$alumnoId.' marcado y una unidad suya, `deAsignaturaCalculada` trajo LO MISMO '
            .'que la sin acotar. La premisa de este lote —que el alcance ya esta escrito y '
            .'funciona— seria falsa, y el arreglo no seria pasar el alcance a los diecisiete.');

        $this->assertNotEmpty($delAlumno,
            'La forma acotada no trajo ninguna unidad para el alumno marcado.');
    }

    /** Un alumno del seed con unidades en alguna asignatura suya, o el test no mide. */
    private function unAlumnoConUnidades(): array
    {
        $fila = DB::selectOne(
            'SELECT m.alumno_id, u.asignatura_id, u.periodo_id
               FROM matriculas m
              INNER JOIN grupos g ON g.id = m.grupo_id AND g.deleted_at IS NULL
              INNER JOIN asignaturas a ON a.grupo_id = g.id AND a.deleted_at IS NULL
              INNER JOIN unidades u ON u.asignatura_id = a.id AND u.deleted_at IS NULL
              WHERE m.deleted_at IS NULL AND u.alumno_id IS NULL
              ORDER BY m.alumno_id, u.id
              LIMIT 1'
        );

        $this->assertNotNull($fila,
            'El seed no tiene ningun alumno matriculado con unidades en una asignatura de su '
            .'grupo: sin eso este test no ejerce `deAsignatura` y su verde no dice nada.');

        return [(int) $fila->alumno_id, (int) $fila->asignatura_id, (int) $fila->periodo_id];
    }

    /** @param array<int,object> $unidades @return array<int,int> */
    private function ids(array $unidades): array
    {
        $ids = array_map(fn ($u) => (int) $u->unidad_id, $unidades);
        sort($ids);

        return $ids;
    }
}
