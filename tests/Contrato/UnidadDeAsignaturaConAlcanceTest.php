<?php

namespace Tests\Contrato;

use App\Models\Unidad;
use App\Services\BoletinIndependiente;
use Illuminate\Support\Facades\DB;

/**
 * `Unidad::deAsignatura` con el alcance del boletin independiente.
 *
 * **Este test se escribio ANTES de acotar nada**, y por eso sirve: con
 * `bol_ind_periodos` vacia y `alumno_id` NULL en todas
 * las unidades, **la forma correcta y la incorrecta dan el mismo verde**. Un test
 * escrito despues del arreglo no comprueba el arreglo: comprueba que nada se movio,
 * que es otra cosa.
 *
 * > **Actualizado el 26 ago 2026, cuando el arreglo entro** (05 §239). Lo que
 * > cambia no es lo que mide sino **contra que lo compara**: antes la segunda mitad
 * > exigia que `deAsignatura` y `deAsignaturaCalculada` **se separaran** —porque una
 * > acotaba y la otra no—; ahora exige que **coincidan cuando se les pasa el mismo
 * > alumno** y que `deAsignatura` **se separe de si misma** segun a quien se le
 * > pregunte. La pregunta que discrimina sigue siendo la misma; el sujeto cambio.
 *
 * ## Lo que hace que esto no sea inventar una regla nueva
 *
 * El alcance **ya estaba escrito** en `BoletinIndependiente::alcance()`, y el metodo
 * hermano de este —`Unidad::deAsignaturaCalculada`, cinco llamadas— **ya lo usaba**,
 * con el `<=>` y su porque encima. `deAsignatura` lo usa tambien desde el 26 ago 2026,
 * en todos sus llamadores. *(Cuantos son se recuenta en el docblock del metodo; el
 * numero no sostiene nada, la propiedad si.)*
 *
 * **Lo que este docblock decia y era falso: que `deAsignatura` es «el mismo metodo
 * sin acotar».** No lo es, y se vio al abrirlo para arreglarlo: la hermana hace
 * `left join` a `subunidades` y a `notas`, agrupa, y devuelve ademas `nota_unidad`
 * —y en una de sus tres ramas, `desempenio` y las columnas de la escala—. Son la
 * estructura y la estructura con notas. Por eso el arreglo **no fue cambiar los
 * llamadores a la hermana** —les habria cambiado la forma de la respuesta
 * y les habria metido un join por alumno en los mismos boletines fichados por tardar
 * 24-63 s— sino **ponerle el alcance a esta**.
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
 * **No es la red de ningun llamador.** Vive en el modelo y **ningun endpoint pasa
 * por aqui**. Fija que el alcance funciona y que hoy no mueve nada.
 *
 * Lo que cubre a los llamadores son otras dos cosas, y hay que saber cual
 * hace cual: **larastan nivel 7** contesta *«¿se le paso el alumno?»* —el parametro
 * es obligatorio y un sitio olvidado es `arguments.count`, que es una puerta de
 * `composer run stan`— y **`BoletinDelIndependienteTest`** contesta *«¿se le paso el
 * alumno BUENO?»*, que es la que ninguna herramienta puede contestar sola.
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

        $estructura = $this->ids(Unidad::deAsignatura($asignaturaId, $periodoId, $alumnoId));
        $acotada = $this->ids(Unidad::deAsignaturaCalculada($alumnoId, $asignaturaId, $periodoId));

        $this->assertSame($estructura, $acotada,
            'Con nadie marcado como boletin independiente, `deAsignatura` y '
            .'`deAsignaturaCalculada` tienen que traer LAS MISMAS unidades. Si no coinciden, '
            .'el alcance NO es un no-op hoy y hay una respuesta moviendose en los quince.');

        $this->assertNotEmpty($estructura,
            'Ninguna de las dos trajo unidades: el seed no ejerce nada y las dos mitades de '
            .'este test se cumplirian por vacio.');
    }

    /**
     * **La mitad que discrimina: `deAsignatura` se separa DE SI MISMA segun a quien
     * se le pregunte.**
     *
     * Antes del 26 ago esta mitad comparaba `deAsignatura` con
     * `deAsignaturaCalculada` y exigia que **se separaran** —una acotaba y la otra
     * no—. Ahora las dos acotan, asi que esa comparacion ya no discrimina nada: el
     * sujeto pasa a ser el mismo metodo con dos alumnos distintos.
     *
     * Con un alumno marcado y una unidad que es suya:
     *
     *   - preguntando **por el**, tiene que salir **la suya**;
     *   - preguntando **por un companero suyo sin marcar**, tienen que salir **las
     *     del grupo**, y la del marcado NO puede estar entre ellas.
     *
     * La segunda es la que importa y es nueva: es el fallo al reves —que la unidad
     * privada de un alumno se le cuele en el boletin de los otros treinta—, y la
     * version anterior de este test **no lo comprobaba**, porque su forma «sin
     * acotar» las traia todas por definicion.
     *
     * **Si las dos preguntas coincidieran, el alcance no esta llegando a la
     * consulta** y todos sus llamadores estan dando lo mismo a todo el mundo.
     */
    public function test_deasignatura_se_separa_segun_a_quien_se_le_pregunte(): void
    {
        BoletinIndependiente::olvidar();

        [$alumnoId, $asignaturaId, $periodoId] = $this->unAlumnoConUnidades();

        // Este periodo suyo pasa a boletin independiente, y una unidad pasa a ser suya.
        // Antes se marcaba la matricula —el ano entero— y aqui se marca solo el periodo
        // que la consulta va a mirar, que es la decision 7 del 31 ago 2026.
        $this->marcarIndependiente($alumnoId, $periodoId);

        DB::insert(
            'INSERT INTO unidades (asignatura_id, periodo_id, alumno_id, definicion, porcentaje, orden, created_at, updated_at)
             VALUES (?, ?, ?, "unidad del independiente", 100, 99, NOW(), NOW())',
            [$asignaturaId, $periodoId, $alumnoId]
        );

        BoletinIndependiente::olvidar();

        $suya = (int) DB::selectOne(
            'SELECT id FROM unidades WHERE alumno_id = ? AND asignatura_id = ? AND periodo_id = ?',
            [$alumnoId, $asignaturaId, $periodoId])->id;

        $companero = $this->unCompaneroSinMarcar($alumnoId, $asignaturaId);

        $paraElMarcado = $this->ids(Unidad::deAsignatura($asignaturaId, $periodoId, $alumnoId));
        $paraElCompanero = $this->ids(Unidad::deAsignatura($asignaturaId, $periodoId, $companero));

        $this->assertNotSame($paraElCompanero, $paraElMarcado,
            'Preguntando por el alumno '.$alumnoId.' —marcado, y con una unidad suya— y por su '
            .'companero '.$companero.' salio LO MISMO. El alcance no esta llegando a la '
            .'consulta y todos sus llamadores dan lo mismo a todo el mundo.');

        $this->assertSame([$suya], $paraElMarcado,
            'Al marcado tienen que salirle SUS unidades y solo las suyas.');

        $this->assertNotContains($suya, $paraElCompanero,
            'La unidad privada del alumno '.$alumnoId.' se le colo al companero '.$companero
            .'. Es el fallo al reves, y es el que ningun test cubria antes: no es que al '
            .'independiente le falten unidades, es que las suyas entran en el boletin de los '
            .'otros treinta.');

        $this->assertNotEmpty($paraElCompanero,
            'Al companero no le salio ninguna unidad del grupo: el test no distingue nada.');
    }

    /** Otro alumno de la misma asignatura, sin marcar: es contra quien se compara. */
    private function unCompaneroSinMarcar(int $alumnoId, int $asignaturaId): int
    {
        // «Sin marcar» ya no es una columna de `matriculas`: es **no tener fila
        // encendida en ningun periodo**, que es lo que dice `bol_ind_periodos`. El
        // `NOT EXISTS` va sin filtrar el periodo a proposito — para comparar hace falta
        // alguien que no este marcado en ninguno, no alguien que se libre solo en este.
        $fila = DB::selectOne(
            'SELECT m.alumno_id
               FROM asignaturas a
              INNER JOIN matriculas m ON m.grupo_id = a.grupo_id AND m.deleted_at IS NULL
              WHERE a.id = ? AND m.alumno_id <> ?
                AND NOT EXISTS (SELECT 1 FROM bol_ind_periodos bip
                                 WHERE bip.alumno_id = m.alumno_id AND bip.aplica = 1)
              ORDER BY m.alumno_id LIMIT 1',
            [$asignaturaId, $alumnoId]);

        $this->assertNotNull($fila,
            'El seed necesita un segundo alumno en ese grupo: con uno solo, «lo suyo» y «lo de '
            .'todos» son lo mismo y este test no distingue nada.');

        return (int) $fila->alumno_id;
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
