<?php

namespace Tests\Contrato;

use App\Models\Asignatura;
use App\Models\Subunidad;
use App\Models\Unidad;
use App\Support\RepartoDeLaNota;
use Illuminate\Support\Facades\DB;
use Tests\Contrato\Concerns\LaPlanillaDelLienzo;

/**
 * **Fase 2 del [43](../../docs/migracion/43-lo-que-todavia-no-se-ha-calificado.md): la
 * parcial y la cobertura por el camino que de verdad lee la pantalla del semáforo.**
 *
 * Las fases 1 y 1.bis dieron los dos números a `DefinitivasDeAsignatura` y a
 * `Asignatura::calculoAlumnoNotas`. **El boletín no pasa por ninguno de los dos**: va por
 * `Grupo::detailed_materias_notafinal` + `notas_finales`, un tercer camino que ningún censo
 * del 43 recogía. Lo destapó la sesión del front al medir a qué endpoint llama de verdad su
 * hoja: `PUT boletines/detailed-notas-group/{grupo}`, con el formato fijo en el cliente.
 *
 * ## Lo que este fichero fija, y por qué cada caso no es decoración
 *
 * 1. **Que los dos números salgan por ahí.** Sin esto la fase 2 del front no se enciende,
 *    por muy fundida que esté la fase 1.
 * 2. **Que sean los MISMOS que los de la planilla** para el mismo alumno y la misma
 *    asignatura. Es el caso que protege de lo único que puede pasar aquí: dos
 *    implementaciones de la misma cuenta que se separan. Se compara contra
 *    `calculoAlumnoNotas` cargado **como lo carga un lector**, no contra números escritos a
 *    mano — un número a mano se ajusta cuando falla, y entonces el test deja de comparar.
 * 3. **Que una subunidad sin fila en `notas` no cuente.** Aquí la fila llega por un
 *    `LEFT JOIN`, así que la subunidad que no le toca a este alumno **vuelve igual** con
 *    `nota` a `null`. Es la trampa entera de este camino y no existe en el otro.
 * 4. **Que el 0 tecleado cuente en el divisor.** La mutación `!== null` → `> 0` es el bug
 *    de origen del 43 reintroducido; en la fase 1.bis los nueve casos pasaron con esa
 *    mutación puesta porque el lienzo no tenía ningún 0 tecleado.
 *
 * ## `nota_asignatura` NO se toca, y este fichero lo aprovecha para probar algo
 *
 * El lienzo monta unidades, subunidades y notas, y **no escribe `notas_finales`**. O sea que
 * en la respuesta la acumulada sale `null` —el `LEFT JOIN` no encuentra fila— **y la parcial
 * y la cobertura salen igual**. Eso prueba de una vez que el numerador **se recalcula** y no
 * se divide la definitiva guardada, que era la decisión de Joseth del 20 sep 2026: si
 * saliera de `notas_finales`, aquí no habría nada que dividir.
 */
class LaParcialEnElBoletinTest extends CasoDeContrato
{
    use LaPlanillaDelLienzo;

    /**
     * Los tres números del lienzo, por el endpoint del semáforo.
     *
     * `nota_asignatura` a `null` no es un descuido del montaje: es la mitad de lo que este
     * caso demuestra (ver la cabecera).
     */
    public function test_el_boletin_publica_la_parcial_y_la_cobertura(): void
    {
        $ctx = $this->laPlanillaDelLienzo();

        $asignatura = $this->asignaturaDelLienzoEnElBoletin($ctx);

        $this->assertArrayHasKey('nota_parcial', $asignatura,
            'El boletín sigue publicando sólo la acumulada: la pantalla del semáforo no tiene '
            .'con qué pintar el corte, por muy fundida que esté la fase 1.');
        $this->assertArrayHasKey('cobertura', $asignatura);

        $this->assertEqualsWithDelta(47.6, (float) $asignatura['nota_parcial'], 0.001);
        $this->assertEqualsWithDelta(0.35, (float) $asignatura['cobertura'], 0.001);

        $this->assertNull($asignatura['nota_asignatura'],
            'La acumulada de este camino sale de `notas_finales` y el lienzo no escribe ahí. '
            .'Si tiene valor, el montaje cambió y este caso ya no prueba que el numerador se '
            .'recalcule.');
    }

    /**
     * **El caso que sostiene todo lo demás: el boletín y la planilla dicen lo mismo.**
     *
     * Son dos recorridos distintos sobre los mismos datos —uno consulta la nota por
     * subunidad, el otro la trae en un `LEFT JOIN`— y la afirmación de la fase 2 es que dan
     * el mismo par. Si algún día se separan, el alumno tendrá una cobertura en la planilla
     * de su profesor y otra en su boletín, las dos con 200 y ninguna en rojo.
     */
    public function test_el_boletin_y_la_planilla_dicen_la_misma_parcial(): void
    {
        $ctx = $this->laPlanillaDelLienzo();

        $delBoletin = $this->asignaturaDelLienzoEnElBoletin($ctx);
        $deLaPlanilla = $this->comoLoCargaUnLector($ctx, $ctx['alumno']);

        $this->assertEqualsWithDelta(
            (float) $deLaPlanilla->nota_parcial, (float) $delBoletin['nota_parcial'], 0.0001,
            'La parcial del boletín se separó de la de la planilla: son dos implementaciones '
            .'de la misma cuenta y una de las dos cambió.');

        $this->assertEqualsWithDelta(
            (float) $deLaPlanilla->cobertura, (float) $delBoletin['cobertura'], 0.0001,
            'La cobertura del boletín se separó de la de la planilla.');
    }

    /**
     * **La trampa del `LEFT JOIN`, que no existe en el otro camino.**
     *
     * Una subunidad sin fila en `notas` para este alumno no está en su plan: no entra ni en
     * el divisor ni en el total. `calculoAlumnoNotas` lo consigue porque su consulta no
     * devuelve nada; aquí la subunidad **vuelve igual**, con `nota_id` a `null`, así que hay
     * que descartarla a mano.
     *
     * Con el descarte, la cobertura se queda en **0,35**. Mirando `nota` en vez de `nota_id`
     * el peso total sube de 10.000 a 11.500 y la cobertura cae a **0,304**: la pantalla
     * pintaría de gris a un alumno por un indicador que no es suyo.
     */
    public function test_una_subunidad_sin_fila_de_nota_no_le_baja_la_cobertura(): void
    {
        $ctx = $this->laPlanillaDelLienzo();

        DB::table('subunidades')->insert([
            'unidad_id' => $ctx['unidad_2'],
            'definicion' => 'INDICADOR QUE NO ES DE ESTE ALUMNO',
            'porcentaje' => 50,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $asignatura = $this->asignaturaDelLienzoEnElBoletin($ctx);

        $this->assertEqualsWithDelta(0.35, (float) $asignatura['cobertura'], 0.001,
            'Una subunidad sin fila en `notas` entró en el peso total: se está mirando `nota` '
            .'y no `nota_id`, y la cobertura del boletín ya no es la de la planilla.');
    }

    /**
     * El 0 que teclea un docente es una nota, no un hueco. Son 3.940 en la copia.
     *
     * Con el indicador del 25 % puesto a 0: el numerador no se mueve —un 0 aporta 0— pero el
     * divisor sí, así que la parcial **baja** de 47,6 a 31,73 y la cobertura sube a 0,525.
     * Con la mutación `> 0` los dos se quedarían donde estaban.
     */
    public function test_el_cero_tecleado_cuenta_en_el_divisor(): void
    {
        $ctx = $this->laPlanillaDelLienzo();

        DB::table('notas')->where('id', $ctx['notas'][2])->update(['nota' => 0]);

        $asignatura = $this->asignaturaDelLienzoEnElBoletin($ctx);

        $this->assertEqualsWithDelta(0.525, (float) $asignatura['cobertura'], 0.001,
            'El 0 tecleado no entró en el divisor: es el bug de origen del 43 metido en la '
            .'cobertura del boletín.');
        $this->assertEqualsWithDelta(31.7333, (float) $asignatura['nota_parcial'], 0.001);
    }

    /**
     * El alumno sin una sola casilla: **los dos a `null`, y ninguno a 0**.
     *
     * Un 0 en la parcial afirmaría que le fue mal y un 0 en la cobertura que no se ha
     * evaluado nada de un plan que existe. Aquí lo que pasa es que no hay plan para él, y la
     * pantalla lo pinta distinto: la casilla del `%` vacía, no un cero.
     */
    public function test_el_alumno_sin_casillas_no_tiene_ni_parcial_ni_cobertura(): void
    {
        $ctx = $this->laPlanillaDelLienzo();

        $asignatura = $this->asignaturaDelLienzoEnElBoletin($ctx, $ctx['alumno_sin_notas']);

        $this->assertNull($asignatura['nota_parcial']);
        $this->assertNull($asignatura['cobertura'],
            'La cobertura de un alumno sin plan salió 0: «no se ha evaluado nada» y «no hay '
            .'nada que evaluar» no son lo mismo, y la pantalla los pinta distinto.');
    }

    // ── Andamio ─────────────────────────────────────────────────────────────────

    /**
     * La asignatura del lienzo tal como sale de `PUT boletines/detailed-notas-group`, que es
     * **el endpoint que llama la pantalla del semáforo** y no otro parecido.
     *
     * El `periodo_id` va en el cuerpo porque el lienzo elige un periodo sin unidades previas
     * y no tiene por qué ser el activo del token. La respuesta es
     * `[grupo, year, alumnos, escalas]`, y se busca por id en los dos niveles en vez de por
     * posición: con un `[0]` este fichero comprobaría el alumno de otro sin decirlo.
     *
     * @param  array<string, mixed>  $ctx
     * @return array<string, mixed>
     */
    /**
     * **En modo `promedio` el boletín tiene que seguir diciendo algo.**
     *
     * Hasta el 20 sep 2026 decía `null` en los dos campos, que no es una convención sino una
     * afirmación falsa: *«esta asignatura no tiene nada que calificar»* de una asignatura con
     * casillas puestas. Lo vio Joseth probando, en Ética de Once —nueve exámenes calificados y
     * el semáforo en blanco—, y lo diagnosticó `myvc-front-a7`.
     *
     * ## Por qué pasaba, y por qué el caso hay que montarlo con los pesos a CERO
     *
     * `LaParcialYLaCobertura` pesaba `s.porcentaje` crudo. En `promedio` esa columna **no se
     * usa** —el peso lo pone `1/n`— así que el colegio la deja a 0 o a `NULL`, y entonces
     * `peso_total = Σ(porc_unidad × 0) = 0` y las dos guardas devuelven `null`.
     *
     * Por eso este caso **pone los porcentajes a cero**: con los del lienzo intactos pasaría
     * también con el fallo puesto, porque el peso crudo seguiría sumando. El cero es la
     * condición real —medida en `simonbolivar`, donde las 2.205 subunidades de 2026 lo
     * están— y es lo que hace que este test se ponga rojo si alguien vuelve a leer la columna.
     *
     * ## De dónde salen los dos números, para que no se lean como copiados de la salida
     *
     * Unidad 1 al 70 % con **cuatro** indicadores —dos calificados, 48 y 47— y unidad 2 al
     * 30 % con **uno**, sin calificar. Con `1/n`: cada uno de la primera pesa `100/4 = 25` y
     * el de la segunda `100/1 = 100`.
     *
     *     peso_total     = 70×25×4 + 30×100      = 7.000 + 3.000 = 10.000
     *     peso_evaluado  = 70×25×2               = 3.500          -> cobertura 0,35
     *     numerador      = (48×25 + 47×25)/100 × 70/100           = 16,625
     *     parcial        = 16,625 × 10.000 / 3.500                = 47,5
     *
     * **Y la parcial es lo que distingue los dos modos**: en `porcentaje` el mismo lienzo da
     * **47,6** (los pesos son 30 y 20, no 25 y 25). La cobertura sale 0,35 en los dos por
     * casualidad aritmética —lo calificado es la mitad del peso de la unidad 1 por los dos
     * caminos—, así que **afirmar sólo la cobertura no distinguiría los modos**.
     */
    public function test_en_modo_promedio_el_boletin_no_dice_que_no_hay_nada_que_calificar(): void
    {
        $ctx = $this->laPlanillaDelLienzo();

        DB::table('years')->where('id', $ctx['year'])
            ->update(['reparto_subunidades' => RepartoDeLaNota::PROMEDIO]);

        // La condición real de un año en `promedio`: la columna del peso, sin usar.
        $aCero = DB::table('subunidades')
            ->whereIn('unidad_id', [$ctx['unidad_1'], $ctx['unidad_2']])
            ->update(['porcentaje' => 0]);

        $this->assertSame(5, $aCero,
            'El lienzo ya no tiene cinco indicadores: los números de abajo se calcularon sobre '
            .'cuatro en la unidad 1 y uno en la 2, y dejan de valer.');

        $asignatura = $this->asignaturaDelLienzoEnElBoletin($ctx);

        $this->assertNotNull($asignatura['cobertura'],
            'La API dice `cobertura: null` —«aquí no hay nada que calificar»— de una asignatura '
            .'con dos casillas calificadas, porque pesa `s.porcentaje` crudo y en `promedio` esa '
            .'columna vale 0. El front lo pinta fielmente y el boletín sale con rayas.');

        $this->assertNotNull($asignatura['nota_parcial'],
            'Sin parcial la casilla imprime una raya donde el alumno tiene un 48 y un 47.');

        $this->assertEqualsWithDelta(0.35, (float) $asignatura['cobertura'], 0.001);

        $this->assertEqualsWithDelta(47.5, (float) $asignatura['nota_parcial'], 0.001,
            'La parcial no está repartiendo por `1/n`: con los pesos del lienzo daría 47,6, que '
            .'es el número del modo `porcentaje` y significa que el modo no se está mirando.');
    }

    private function asignaturaDelLienzoEnElBoletin(array $ctx, ?int $alumnoId = null): array
    {
        $alumnoId ??= $ctx['alumno'];

        $grupoId = (int) DB::table('asignaturas')->where('id', $ctx['asignatura'])->value('grupo_id');

        $cuerpo = $this->withToken($this->tokenDelPersonalDe($ctx['year']))
            ->putJson('/api/boletines/detailed-notas-group/'.$grupoId,
                ['periodo_id' => $ctx['periodo']])
            ->assertStatus(200)
            ->json();

        foreach ($cuerpo[2] ?? [] as $alumno) {
            if ((int) ($alumno['alumno_id'] ?? 0) !== $alumnoId) {
                continue;
            }

            foreach ($alumno['asignaturas'] ?? [] as $asignatura) {
                if ((int) ($asignatura['asignatura_id'] ?? 0) === $ctx['asignatura']) {
                    return $asignatura;
                }
            }
        }

        $this->fail('El alumno '.$alumnoId.' y la asignatura '.$ctx['asignatura'].' no salieron '
            .'juntos en el boletín del grupo.');
    }

    /**
     * La asignatura calculada **como la cargan los seis lectores** de `calculoAlumnoNotas`.
     *
     * Copiado de `LaParcialEnLaPlanillaTest` a propósito: lo que aquí se compara es el
     * resultado de los dos caminos, así que el lado «planilla» tiene que cargarse igual que
     * allí. Cargarlo de otra forma compararía contra un camino que no usa nadie.
     *
     * @param  array<string, mixed>  $ctx
     */
    private function comoLoCargaUnLector(array $ctx, int $alumnoId): object
    {
        $asignatura = new \stdClass;
        $asignatura->unidades = Unidad::deAsignatura($ctx['asignatura'], $ctx['periodo'], $alumnoId);

        foreach ($asignatura->unidades as $unidad) {
            $unidad->subunidades = Subunidad::deUnidad($unidad->unidad_id);
        }

        Asignatura::calculoAlumnoNotas($asignatura, $alumnoId);

        return $asignatura;
    }
}
