<?php

namespace Tests\Contrato;

use App\Services\BoletinIndependiente;
use Illuminate\Support\Facades\DB;

/**
 * La unidad privada de un alumno **no se cuela en el boletín de sus compañeros**, y
 * la suya sí le sale a él. Por HTTP, sobre la ruta real.
 *
 * ## Por qué este fichero existe además de `UnidadDeAsignaturaConAlcanceTest`
 *
 * Porque contestan preguntas distintas y **ninguna de las dos vale por la otra**.
 * `Unidad::deAsignatura` ganó un tercer parámetro obligatorio, el alumno, y lo tuvo que
 * ganar en todos sus llamadores. De ahí salen tres preguntas:
 *
 * | pregunta | quién la contesta |
 * |---|---|
 * | ¿el alcance funciona? | `UnidadDeAsignaturaConAlcanceTest`, en el modelo |
 * | ¿se le pasó el alumno en todos? | **larastan nivel 7** — el parámetro es obligatorio, así que un sitio olvidado es `arguments.count` y `composer run stan` es una puerta |
 * | **¿se le pasó el alumno BUENO?** | **esto** |
 *
 * La tercera es la única que ninguna herramienta puede contestar sola: pasar
 * `$alumno->alumno_id` donde tocaba `$alumno_id` compila, pasa larastan y devuelve
 * un número perfectamente creíble. **Sólo se ve mirando la respuesta.**
 *
 * ## Y por qué hoy sólo se puede ver marcando a alguien
 *
 * `unidades.alumno_id` es NULL en todas las filas de los quince colegios, así que
 * **hoy pasar un alumno u otro da exactamente lo mismo**. Un test que no marque a
 * nadie pasa igual con el alumno bueno y con el equivocado. Por eso esto marca a
 * uno y le crea una unidad propia dentro de la transacción del test.
 *
 * ## Las dos direcciones, y la segunda es la que nadie miraba
 *
 * 1. **Al independiente le sale la suya.** Es la que se cuenta siempre.
 * 2. **Al compañero NO le sale la del independiente.** Es el fallo al revés —una
 *    unidad privada entrando en el boletín de los otros treinta— y es el que más
 *    caro sale, porque el afectado no es quien está marcado: son todos los demás.
 *
 * Ver `docs/migracion/05-codigo-muerto-y-roto.md` §239.
 */
class BoletinDelIndependienteTest extends CasoDeContrato
{
    protected function setUp(): void
    {
        parent::setUp();

        // La memoria del servicio es por petición y una suite es un proceso: sin
        // esto, el test que marca a alguien le deja la respuesta cacheada al
        // siguiente. Copiado de `BolIndependienteAlcanceTest`, que lo pagó.
        BoletinIndependiente::olvidar();
    }

    /**
     * Un grupo con **dos** alumnos matriculados y una asignatura con unidades **en el
     * periodo del token**: el marcado y el compañero contra el que se compara.
     *
     * **El periodo del token no es un detalle de montaje, es la mitad del escenario.**
     * `detailedNotasGrupo` pide las unidades con `$user->periodo_id`, no con el periodo
     * del grupo. Eligiendo el grupo por su cuenta salía uno de 2025 mientras el token
     * iba por otro año: **la respuesta llegaba 200 y sin una sola unidad dentro**, y
     * los tres tests fallaban por el escenario y no por el código. Un `assertNotEmpty`
     * en cada uno es lo que lo distinguió de un fallo de verdad.
     */
    private function escenario(): object
    {
        $usuario = $this->usuarioDeTipo('Usuario');

        $fila = DB::selectOne(
            'SELECT a.id AS asignatura_id, a.grupo_id, u.periodo_id
               FROM asignaturas a
              INNER JOIN unidades u ON u.asignatura_id = a.id AND u.deleted_at IS NULL
                    AND u.alumno_id IS NULL AND u.periodo_id = ?
              INNER JOIN matriculas m ON m.grupo_id = a.grupo_id AND m.deleted_at IS NULL
                    AND m.estado IN ("MATR", "ASIS")
              WHERE a.deleted_at IS NULL
              GROUP BY a.id, a.grupo_id, u.periodo_id
             HAVING COUNT(DISTINCT m.alumno_id) >= 2
              ORDER BY a.id LIMIT 1', [$usuario->periodo_id]);

        $this->assertNotNull($fila,
            'El seed necesita una asignatura con unidades EN EL PERIODO DEL TOKEN y DOS alumnos '
            .'matriculados: con uno solo, «lo suyo» y «lo de todos» son lo mismo, y con otro '
            .'periodo la respuesta viene vacía y los tests pasan o fallan por el montaje.');

        $alumnos = DB::select(
            'SELECT alumno_id FROM matriculas
              WHERE grupo_id = ? AND deleted_at IS NULL AND estado IN ("MATR", "ASIS")
              ORDER BY alumno_id LIMIT 2', [$fila->grupo_id]);

        $fila->marcado = (int) $alumnos[0]->alumno_id;
        $fila->companero = (int) $alumnos[1]->alumno_id;
        $fila->token = $this->tokenDe($usuario->username);

        return $fila;
    }

    /** Marca al alumno y le crea una unidad propia. Devuelve el id de esa unidad. */
    private function marcarConUnidadPropia(object $e): int
    {
        $this->marcarIndependiente($e->marcado, (int) $e->periodo_id);

        DB::insert(
            'INSERT INTO unidades (asignatura_id, periodo_id, alumno_id, definicion, porcentaje, orden, created_at, updated_at)
             VALUES (?, ?, ?, "SOLO DE ESTE ALUMNO", 100, 99, NOW(), NOW())',
            [$e->asignatura_id, $e->periodo_id, $e->marcado]);

        BoletinIndependiente::olvidar();

        return (int) DB::getPdo()->lastInsertId();
    }

    /**
     * La rejilla de notas de un alumno, tal y como la pide el front.
     *
     * **`editnota` y no `boletines`, y eso costó una vuelta que merece quedar
     * escrita.** El boletín parecía el sitio natural, y no lo era por dos motivos
     * que sólo se ven abriendo el código:
     *
     *   - sus **unidades** ya venían de `deAsignaturaCalculada`, que llevaba el
     *     alcance desde BI-2: por ahí no pasa ninguno de los llamadores acotados;
     *   - y el `deAsignatura` que sí tiene —`BoletinesController:501`, dentro de
     *     `asignaturasPerdidasDeAlumnoPorPeriodo`— **no lo llama nadie en ese
     *     controlador**: es una de las copias muertas de la §216.
     *
     * `EditnotaController::allNotasAlumno` sí: le pone las unidades al alumno **sin
     * filtrarlas por nada** y salen enteras en la respuesta de
     * `PUT editnota/detailed-notas/{grupo}`. Es la rejilla donde un docente teclea
     * notas, o sea el sitio donde una unidad de más se ve a simple vista.
     */
    private function boletinDe(object $e, int $alumnoId, string $token): array
    {
        return $this->putJson("/api/editnota/detailed-notas/{$e->grupo_id}",
            ['requested_alumnos' => [['alumno_id' => $alumnoId]], 'periodos_a_calcular' => 'de_colegio'],
            ['Authorization' => 'Bearer '.$token]
        )->assertStatus(200)->json();
    }

    /**
     * Las unidades de la rejilla: `alumnos[].asignaturas[].unidades[]`, **y sólo ésas**.
     *
     * **Se apunta al camino exacto y no se barre la respuesta entera, y eso lo enseñó
     * el control cayéndose.** Un `array_walk_recursive` buscando `definicion_unidad`
     * recogía además las de `asignaturas_perdidas`, que salen de
     * `EditnotaController:327` y **están filtradas por las notas perdidas de cada
     * alumno**: difieren de un alumno a otro **con razón y sin que nadie esté
     * marcado**. El control las leía como «la respuesta se movió» y acusaba al
     * acotado de algo que no había hecho.
     *
     * *Un extractor demasiado ancho no da un falso negativo: da un falso positivo, y
     * de los que mandan a buscar un fallo que no existe.*
     *
     * @param  array<int, mixed>  $cuerpo  `[grupo, year, alumnos]`
     * @return array<int, string>
     */
    private function definicionesDeUnidad(array $cuerpo): array
    {
        $definiciones = [];

        foreach ($cuerpo[2] ?? [] as $alumno) {
            foreach ($alumno['asignaturas'] ?? [] as $asignatura) {
                foreach ($asignatura['unidades'] ?? [] as $unidad) {
                    $definiciones[] = (string) ($unidad['definicion_unidad'] ?? '');
                }
            }
        }

        sort($definiciones);

        return $definiciones;
    }

    /**
     * **La dirección que nadie miraba: la unidad privada NO entra en el boletín del
     * compañero.**
     *
     * Va primera porque es la cara del fallo: el perjudicado no es el alumno
     * marcado, son los otros treinta, y ninguno de ellos tiene forma de saberlo.
     */
    public function test_la_unidad_privada_no_sale_en_el_boletin_del_companero(): void
    {
        $e = $this->escenario();
        $token = $e->token;

        $this->marcarConUnidadPropia($e);

        $definiciones = $this->definicionesDeUnidad($this->boletinDe($e, $e->companero, $token));

        $this->assertNotEmpty($definiciones,
            'El boletín del compañero no trajo ninguna unidad: el test no distingue nada.');

        $this->assertNotContains('SOLO DE ESTE ALUMNO', $definiciones,
            'La unidad privada del alumno '.$e->marcado.' salió en el boletín de '.$e->companero
            .'. O el alcance no llega, o a ese llamador se le pasó el alumno equivocado.');
    }

    /** Y la otra mitad: al marcado sí le sale la suya. Sin ella, «no sale» se cumple por vacío. */
    public function test_al_independiente_si_le_sale_la_suya(): void
    {
        $e = $this->escenario();
        $token = $e->token;

        $this->marcarConUnidadPropia($e);

        $this->assertContains('SOLO DE ESTE ALUMNO',
            $this->definicionesDeUnidad($this->boletinDe($e, $e->marcado, $token)),
            'Al alumno marcado no le sale su propia unidad. Con la mitad de arriba sola, esto '
            .'pasaría escondiéndosela a todo el mundo, que es el fallo contrario y también es un fallo.');
    }

    /**
     * **El control que no puede faltar: sin marcar a nadie, nada se mueve.**
     *
     * Es el criterio de aceptación de todo el boletín independiente —la fase 1 es
     * aditiva— y aquí, además, es lo que dice que acotar los llamadores
     * **no le movió la respuesta a ningún colegio hoy**.
     */
    public function test_sin_nadie_marcado_los_dos_ven_lo_mismo(): void
    {
        $e = $this->escenario();
        $token = $e->token;

        $delMarcado = $this->definicionesDeUnidad($this->boletinDe($e, $e->marcado, $token));
        $delCompanero = $this->definicionesDeUnidad($this->boletinDe($e, $e->companero, $token));

        $this->assertNotEmpty($delMarcado, 'Sin unidades, este control no controla nada.');
        $this->assertSame($delMarcado, $delCompanero,
            'Con nadie marcado, dos alumnos del mismo grupo tienen que ver las mismas unidades. '
            .'Si esto cae, acotar los llamadores NO fue un no-op y hay una respuesta '
            .'moviéndose en los quince colegios.');
    }
}
