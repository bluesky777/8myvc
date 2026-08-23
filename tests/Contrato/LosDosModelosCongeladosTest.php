<?php

namespace Tests\Contrato;

use App\Models\Profesor;
use App\Models\Year;
use Illuminate\Support\Facades\DB;

/**
 * §125–127 — Los dos modelos que se congelaron, y por qué **los dos se quedan**.
 *
 * `Profesor::detallado()` acaba en `return $profesor[0];` y
 * `Year::de_un_periodo()` en `Periodo::find(...)->year_id`, las dos sin comprobar
 * que haya fila. Con un id que no existe —**o uno de la papelera**, que las dos
 * consultas descartan— eso es un aviso de PHP 8 que Laravel convierte en
 * excepción: **500**.
 *
 * Coordinación congeló los dos y puso una condición: **primero los llamantes**.
 * La razón era que un `?? null` convertiría los 500 en «comportamientos distintos
 * sin medir cuál es el correcto en cada pantalla». Con los llamantes cerrados, la
 * pregunta se puede contestar — y la respuesta se puede **medir** en vez de
 * discutir.
 *
 * ## Lo que se midió, poniendo el `?? null` y sin tocar ningún llamante
 *
 * | Método | Hoy, sin guard delante | Con `?? null` en el modelo |
 * |---|---|---|
 * | `Year::de_un_periodo()` | 500 `…"year_id" on null` **en el modelo** | 500 `…"id" on null` **en el llamante, una línea después** |
 * | `Profesor::detallado()` vía `profesores/show` | 500 `Undefined array key 0` | **200 `[null]`** |
 * | `Profesor::detallado()` vía `planillas/show-profesor` | 500 `Undefined array key 0` | **200 con la planilla entera montada y el profesor vacío dentro** |
 *
 * **El mismo arreglo, en dos modelos, da resultados contrarios** — y la diferencia
 * no está en el modelo: está en **lo que el llamante hace con lo que recibe**.
 *
 * - En `de_un_periodo` el llamante desreferencia el resultado en la línea
 *   siguiente, así que el null no esconde nada: **mueve el 500 una línea y le
 *   quita el nombre que lo identificaba** —`year_id` señala la consulta del
 *   periodo; `id` no señala nada—. Peor de diagnosticar, igual de roto.
 * - En `detallado` el llamante **mete el resultado en la respuesta**, así que el
 *   null sale por la API: un 200 con `[null]` en un caso y, en el otro, **un
 *   informe con su cabecera montada y el profesor en blanco**. Un 500 se ve; una
 *   planilla impresa a nombre de nadie, no.
 *
 * > **La respuesta a «¿debe el modelo devolver null?» no está en el modelo.** Y
 * > por eso no puede tener una sola respuesta para seis llamantes de tres
 * > dominios: es la §89 —arregla la operación, no el sitio— con el matiz de que
 * > **aquí la operación no es «leer un profesor», es «contestar una petición»**, y
 * > eso lo decide la ruta.
 *
 * **Los dos se quedan como están**, y este test es lo que impide que alguien lo
 * «arregle» dentro de seis meses sin estos números delante: si el modelo empieza a
 * devolver null, cae.
 *
 * ## La comprobación al revés, con el lote E ya fundido
 *
 * La condición para dar esto por cerrado era: **buscar si alguno de los cuatro
 * llamantes de E NO mete el resultado en la respuesta**, porque ése cambiaría la
 * regla. Fundido E, se hizo: quitados sus cuatro guards y puesto el `?? null`.
 * **Los cuatro contestan 200.**
 *
 * | Ruta | Con `?? null` y sin su guard |
 * |---|---|
 * | `profesores/show/{id}` | **200 `[null]`** |
 * | `planillas/show-profesor/{id}` | **200 con la cabecera del informe montada y sin filas** |
 * | `planillas-ausencias/show-profesor/{id}` | idem |
 * | `notas-perdidas/show-profesor/{id}` | idem |
 *
 * **Y el mecanismo es más fino de lo que parecía leyendo.** Los tres informes SÍ
 * desreferencian el resultado —`$profesor->nombres_profesor`— pero **dentro de un
 * `foreach` sobre las asignaturas del profesor**, y un profesor que no existe **no
 * tiene asignaturas**: el bucle no entra nunca.
 *
 * > **La línea que habría reventado es inalcanzable justo cuando el id es malo.**
 * > O sea que el null no se cuela a pesar de la desreferencia: se cuela **porque
 * > la desreferencia depende de datos que ese id no tiene**. Leer el método no lo
 * > dice; ejecutarlo, sí.
 *
 * Ninguno de los cuatro cambia la regla: la confirman. Y los dos que faltan
 * —`AsignaturasController` y `UnidadesController`, del lote D— quedan medidos por
 * sus propios tests de §96.
 */
class LosDosModelosCongeladosTest extends CasoDeContrato
{
    private function unProfesorEnLaPapelera(): int
    {
        $id = (int) DB::table('profesores')->whereNull('deleted_at')->orderBy('id')->value('id');
        $this->assertNotSame(0, $id, 'El seed no tiene profesores.');
        DB::table('profesores')->where('id', $id)->update(['deleted_at' => now()]);

        return $id;
    }

    /**
     * §126 — `Profesor::detallado()` **revienta** con un id que no está, y tiene
     * que seguir haciéndolo.
     *
     * No es que reventar esté bien: es que **devolver null está peor** por los dos
     * caminos medidos, y el sitio donde se contesta «no existe» es la ruta. Si
     * este test cae porque el modelo devuelve null, lo que hay que mirar son los
     * seis llamantes, no este archivo.
     */
    public function test_el_profesor_que_no_esta_revienta_en_el_modelo(): void
    {
        $inexistente = ((int) DB::table('profesores')->max('id')) + 1000;

        try {
            Profesor::detallado($inexistente);
            $this->fail('`Profesor::detallado()` devolvió algo para un profesor que no existe. '
                .'Si es un `?? null` nuevo, lee el docblock de esta clase: está medido y es peor.');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('0', $e->getMessage(),
                'El error nombra el índice que falta, que es lo que lo hace diagnosticable.');
        }
    }

    /** Y con uno de la papelera igual, que es el caso que no se ve leyendo. */
    public function test_el_profesor_de_la_papelera_tambien(): void
    {
        $papelera = $this->unProfesorEnLaPapelera();

        $this->expectException(\Throwable::class);
        Profesor::detallado($papelera);
    }

    /** El camino bueno sigue devolviendo la fila con sus columnas. */
    public function test_un_profesor_que_esta_vuelve_entero(): void
    {
        $id = (int) DB::table('profesores')->whereNull('deleted_at')->orderBy('id')->value('id');

        $profesor = Profesor::detallado($id);

        $this->assertSame($id, (int) $profesor->profesor_id);
        $this->assertObjectHasProperty('nombres_profesor', $profesor);
        $this->assertObjectHasProperty('imagen_nombre', $profesor,
            'La imagen por defecto se calcula en la consulta, y es parte de lo que la pantalla pinta.');
    }

    /**
     * §125 — `Year::de_un_periodo()` revienta igual, y por la misma razón se
     * queda.
     *
     * Aquí el `?? null` ni siquiera evitaría el 500: lo movería a la línea
     * siguiente del llamante. Medido.
     */
    public function test_el_periodo_que_no_esta_revienta_en_el_modelo(): void
    {
        $inexistente = ((int) DB::table('periodos')->max('id')) + 1000;

        $this->expectException(\Throwable::class);
        Year::de_un_periodo($inexistente);
    }

    /** Y uno de la papelera, que `find()` descarta igual. */
    public function test_el_periodo_de_la_papelera_tambien(): void
    {
        $id = (int) DB::table('periodos')->whereNull('deleted_at')->orderBy('id')->value('id');
        DB::table('periodos')->where('id', $id)->update(['deleted_at' => now()]);

        $this->expectException(\Throwable::class);
        Year::de_un_periodo($id);
    }

    /** El camino bueno: el año del periodo que se pide. */
    public function test_el_periodo_que_esta_devuelve_su_year(): void
    {
        $periodo = DB::table('periodos')->whereNull('deleted_at')->orderBy('id')->first();

        $year = Year::de_un_periodo($periodo->id);

        $this->assertSame((int) $periodo->year_id, (int) $year->id);
    }

    /**
     * §127 — Y la puerta que sí decide: la ruta contesta **404**, no 500.
     *
     * Es el llamante único de `de_un_periodo()`, cerrado por el lote D con la
     * decisión escrita al lado —«el 404 es una decisión de la ruta, y el modelo
     * sólo sabe de años»—. Este test la fija desde fuera: es lo que un colegio ve.
     */
    public function test_la_ruta_contesta_404_y_el_modelo_no_se_entera(): void
    {
        $token = $this->tokenDe($this->usuarioDeTipo('Usuario')->username);
        $profesor = DB::table('profesores')->whereNull('deleted_at')->orderBy('id')->value('id');
        $inexistente = ((int) DB::table('periodos')->max('id')) + 1000;

        $this->withToken($token)
            ->getJson("/api/asignaturas/list-asignaturas-year/{$profesor}/{$inexistente}")
            ->assertStatus(404);

        $papelera = DB::table('periodos')->whereNull('deleted_at')->orderBy('id')->value('id');
        DB::table('periodos')->where('id', $papelera)->update(['deleted_at' => now()]);

        $this->withToken($token)
            ->getJson("/api/asignaturas/list-asignaturas-year/{$profesor}/{$papelera}")
            ->assertStatus(404);
    }
}
