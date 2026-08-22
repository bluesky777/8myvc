<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * `PUT api/definitivas_periodos/calcular-notas-finales-asignatura`, que **nunca
 * calculó nada y por el camino borraba las definitivas puestas a mano**.
 *
 * Ver [05 §71](../../docs/migracion/05-codigo-muerto-y-roto.md). Lo medido el
 * 22 ago 2026, sobre una asignatura con 164 definitivas y cuatro manuales:
 *
 *     respuesta: 500 «Unknown column 'g.asignatura_id'»
 *     definitivas: 164 -> 160     manuales: 4 -> 0
 *
 * O sea que el orden es el que hace daño: primero un `DELETE` de verdad —no la
 * papelera— con el criterio **invertido** (`manual is null or manual=1`), y
 * después una consulta contra una columna que no existe. Sin transacción, así que
 * el 500 llega con el borrado hecho.
 *
 * Y las que se lleva son las que no se pueden rehacer: una definitiva automática
 * se recalcula desde las notas; una manual la escribió una persona.
 *
 * ## Por qué esto no se «arregla», se corta
 *
 * Arreglarlo de verdad es cablear `App\Services\DefinitivasDeAsignatura` —la fase
 * 3 del plan— y retirar el botón es la fase 5. Ninguna de las dos se decide desde
 * aquí. Lo único que no podía esperar es que **siga borrando**, así que el método
 * contesta 410 antes de tocar nada.
 *
 * La ruta **no se borra**: la regla de este repo es que un endpoint enrutado y
 * roto se documenta, porque borrarlo convierte un 500 en un 404 sin decirle a
 * nadie qué pretendía hacer esa pantalla.
 */
class CalcularAsignaturaBorraYRevientaTest extends CasoDeContrato
{
    /**
     * El caso entero: se construye una definitiva manual, se llama, y ni la
     * respuesta ni la tabla se mueven de donde tienen que estar.
     *
     * La manual se **construye** —`UPDATE … SET manual = 1`— y no se busca. Aquí el
     * seed resulta que sí trae tres para esa asignatura, pero eso es una propiedad
     * del seed de hoy: un caso que dependa de ella pasa a no medir nada el día que
     * el seed cambie, y ese día nadie se entera. La comprobación es sobre el número
     * de antes contra el de después, no sobre un número escrito aquí.
     */
    public function test_el_calculo_retirado_no_borra_las_definitivas_manuales(): void
    {
        $fila = DB::selectOne('SELECT nf.asignatura_id FROM notas_finales nf
            WHERE nf.asignatura_id IS NOT NULL
            GROUP BY nf.asignatura_id ORDER BY COUNT(*) DESC LIMIT 1');

        $this->assertNotNull($fila, 'El seed necesita definitivas para que esto mida algo.');

        DB::update('UPDATE notas_finales SET manual = 1 WHERE asignatura_id = ? LIMIT 1',
            [$fila->asignatura_id]);

        $antes = DB::selectOne('SELECT COUNT(*) n, SUM(manual = 1) manuales FROM notas_finales
            WHERE asignatura_id = ?', [$fila->asignatura_id]);

        $this->assertGreaterThanOrEqual(1, (int) $antes->manuales,
            'No hay ninguna definitiva manual: sin eso el caso no distingue el corte de «no había nada que borrar».');

        $grupo = $this->grupoConAlumnos();
        $token = $this->tokenDelPersonalDe($grupo->year_id);

        // El cuerpo es el que aceptaba: manda `profesor_id` y el método lo usaba como
        // `asignatura_id`, con el `// Aquí un error por arreglar` del autor al lado.
        $this->withToken($token)->putJson('/api/definitivas_periodos/calcular-notas-finales-asignatura', [
            'profesor_id' => $fila->asignatura_id,
        ])->assertStatus(410);

        $despues = DB::selectOne('SELECT COUNT(*) n, SUM(manual = 1) manuales FROM notas_finales
            WHERE asignatura_id = ?', [$fila->asignatura_id]);

        $this->assertSame((int) $antes->n, (int) $despues->n,
            'Se borraron definitivas: el corte tiene que estar ANTES del DELETE, no después.');
        $this->assertSame((int) $antes->manuales, (int) $despues->manuales,
            'Desaparecieron definitivas manuales, que son justo las que este método se llevaba.');
    }

    /**
     * El gemelo sigue ahí y **no está enrutado**, que es la única razón por la que no
     * hace falta cortarlo también.
     *
     * `Alumnos\Definitivas::calcular_notas_finales_asignatura_periodo` es la copia
     * literal del método de arriba, con el mismo DELETE invertido. Si alguien le pone
     * una ruta, este caso no se entera —no puede—, pero deja escrito dónde mirar.
     * Ver 10-definitivas.md §7.
     */
    public function test_el_gemelo_sigue_sin_ruta(): void
    {
        $rutas = collect(app('router')->getRoutes()->getRoutes())
            ->map(fn ($r) => $r->getActionName())
            ->filter(fn ($a) => str_contains($a, 'Definitivas'));

        $this->assertTrue(
            $rutas->filter(fn ($a) => str_contains($a, 'calcular_notas_finales_asignatura_periodo'))->isEmpty(),
            'Alguien enrutó el gemelo del método retirado: lleva el mismo DELETE invertido.'
        );
    }
}
