<?php

namespace Tests\Contrato;

use App\Models\Asignatura;
use App\Models\Subunidad;
use App\Models\Unidad;
use App\Services\DefinitivasDeAsignatura;
use App\Support\RepartoDeLaNota;
use Illuminate\Support\Facades\DB;
use Tests\Contrato\Concerns\LaPlanillaDelLienzo;

/**
 * **Fase 1.bis de [43](../../docs/migracion/43-lo-que-todavia-no-se-ha-calificado.md):
 * la parcial y la cobertura llegan al SEGUNDO calculador**, que es el que produce
 * el número que el docente mira todos los días.
 *
 * La fase 1 se las dio a `DefinitivasDeAsignatura`, que es **el que escribe**. Pero
 * la planilla no pasa por ahí: pasa por `App\Models\Asignatura::calculoAlumnoNotas`,
 * un calculador entero y paralelo escrito en PHP, con **seis lectores**. Mientras
 * eso no las tuviera, los dos números existían y **no los veía nadie**.
 *
 * ## Lo que este fichero tiene que impedir, en orden de coste
 *
 * 1. **Que la acumulada se mueva un decimal.** Es la que cierra el periodo y la que
 *    imprimen los dieciséis colegios. Todos los casos que comprueban un número nuevo
 *    comprueban el viejo en la misma línea.
 * 2. **Que la parcial salga de un divisor que la acumulada de al lado no usa.** Es
 *    el riesgo propio de esta fase y tiene caso propio
 *    ({@see test_en_promedio_la_parcial_sigue_a_la_acumulada_de_este_metodo}): este
 *    método **nunca ha pasado por `RepartoDeLaNota`** —pesa `s.porcentaje` crudo— y
 *    sacar el peso de allí dejaría un cociente entre dos repartos distintos.
 * 3. **Que sea un 0 donde tiene que ser `null`.** Los dos ceros de división, los dos
 *    con su caso, porque son dos hechos distintos.
 * 4. **Que los números se calculen y no lleguen a la respuesta.** Por eso el último
 *    caso es una petición HTTP de verdad y no una llamada al método: *mirar el
 *    resultado y no el estado*.
 *
 * ## Los dos calculadores sobre la MISMA planilla
 *
 * El montaje está en {@see LaPlanillaDelLienzo} y lo comparte con
 * `LaParcialYLaCoberturaTest`, que es el de la fase 1. No es aseo: la afirmación de
 * esta fase es **que los dos dicen lo mismo**, y eso no se puede comprobar con dos
 * montajes que se parecen —el día que uno cambiara un porcentaje, los dos ficheros
 * seguirían verdes por separado y la afirmación dejaría de estar probada sin que
 * nada se pusiera rojo—.
 */
class LaParcialEnLaPlanillaTest extends CasoDeContrato
{
    use LaPlanillaDelLienzo;

    /**
     * **Los tres números del lienzo, por el camino de la planilla.**
     *
     *     acumulada = 0,70 × (0,30×48 + 0,20×47)        = 16,66   BAJO
     *     parcial   = 16,66 ÷ (0,70×0,30 + 0,70×0,20)   = 47,60   SUPERIOR
     *     cobertura = 0,35 ÷ 1,00                       =  0,35
     *
     * Y la cobertura se comprueba contra **0,35 y no contra 0,4**: hay 2 casillas
     * calificadas de 5 —el 40 %— y lo evaluado pesa el 35 %. Si saliera 0,4 la
     * fórmula estaría contando indicadores, y entonces un indicador del 40 % sin
     * calificar y uno del 5 % dejarían el mismo hueco.
     */
    public function test_la_planilla_da_los_tres_numeros_del_lienzo(): void
    {
        $ctx = $this->laPlanillaDelLienzo();
        $asig = $this->comoLoCargaUnLector($ctx, $ctx['alumno']);

        $this->assertSame(16.66, (float) $asig->nota_asignatura,
            'La acumulada de la planilla se movió: es la que cierra el periodo.');

        $this->assertSame(47.6, (float) $asig->nota_parcial,
            'La parcial salió '.var_export($asig->nota_parcial, true)
            .': no se normalizó por el peso de lo evaluado.');

        $this->assertSame(0.35, (float) $asig->cobertura,
            'La cobertura salió '.var_export($asig->cobertura, true)
            .': con 2 de 5 casillas, un 0,4 significa que se están contando indicadores.');
    }

    /**
     * **Los dos calculadores, la misma planilla, los mismos tres números.**
     *
     * Es la afirmación entera de la fase y por eso se comprueba y no se argumenta.
     * `DefinitivasDeAsignatura::calcular` lo hace en SQL sobre `DECIMAL`;
     * `calculoAlumnoNotas` lo hace en PHP sobre `float`, y agrupa distinto —suma por
     * unidad y luego multiplica, en vez de sumar términos ya pesados—. Que coincidan
     * hasta el último bit no está garantizado por nada, así que lo que se exige es
     * que coincidan **en lo que se imprime**, con margen de una milésima: la escala
     * del colegio va de 0 a 50 y se pinta con dos decimales.
     */
    public function test_los_dos_calculadores_dicen_lo_mismo(): void
    {
        $ctx = $this->laPlanillaDelLienzo();

        $planilla = $this->comoLoCargaUnLector($ctx, $ctx['alumno']);
        $servicio = $this->filaDelServicio($ctx, $ctx['alumno']);

        $this->assertEqualsWithDelta((float) $servicio->nota, (float) $planilla->nota_asignatura, 0.001,
            'La acumulada del servicio y la de la planilla dejaron de ser la misma.');

        $this->assertEqualsWithDelta((float) $servicio->parcial, (float) $planilla->nota_parcial, 0.001,
            'La parcial de la planilla no es la del servicio: son dos respuestas a la misma '
            .'pregunta en la misma pantalla.');

        $this->assertEqualsWithDelta((float) $servicio->cobertura, (float) $planilla->cobertura, 0.001,
            'La cobertura de la planilla no es la del servicio.');
    }

    /**
     * **Un 0 que TECLEÓ un docente sí cuenta en el divisor, y un `null` no.**
     *
     * Éste es el caso que distingue las dos cosas que el 43 §1 dice que llevan años
     * siendo el mismo número, y **sin él este fichero no probaba nada de eso**: se
     * escribió después de mutar `!== null` a `> 0` y ver los nueve casos en verde.
     * Con el lienzo tal cual no hay ninguna casilla calificada con 0 —las dos que
     * valen 0 son `null`— así que las dos escrituras daban idénticos los tres
     * números. *Comprobar que un número sale bien no prueba que salga de donde uno
     * cree.*
     *
     * Son **3.940** ceros tecleados en la copia de desarrollo, el 3,8 % de los
     * 102.401 ceros. Un cero tecleado es una nota: baja la parcial y **sube la
     * cobertura**.
     *
     *     acumulada = la misma, 16,66          ← un 0 no aporta nada
     *     parcial   = 16,66 ÷ 0,525 = 31,73    ← con 47,6 el 0 se está leyendo como «sin calificar»
     *     cobertura = 0,525                    ← con 0,35 lo mismo
     *
     * Y se comprueba **en los dos calculadores**, porque el agujero era el mismo en
     * el de la fase 1.
     */
    public function test_un_cero_tecleado_si_cuenta_en_el_divisor(): void
    {
        $ctx = $this->laPlanillaDelLienzo();

        // La tercera casilla —la de 25 %— pasa de «sin calificar» a «calificada con 0».
        DB::table('notas')->where('id', $ctx['notas'][2])->update(['nota' => 0]);

        $planilla = $this->comoLoCargaUnLector($ctx, $ctx['alumno']);
        $servicio = $this->filaDelServicio($ctx, $ctx['alumno']);

        $this->assertSame(16.66, (float) $planilla->nota_asignatura,
            'La acumulada se movió: un 0 no aporta nada, ni antes ni ahora.');

        $this->assertSame(0.525, (float) $planilla->cobertura,
            'La cobertura salió '.var_export($planilla->cobertura, true).': un 0,35 significa '
            .'que el 0 del docente se está contando como «sin calificar», que es exactamente '
            .'el bug que el 43 viene a quitar.');

        $this->assertEqualsWithDelta(31.7333, (float) $planilla->nota_parcial, 0.0001,
            'La parcial salió '.var_export($planilla->nota_parcial, true).': con 47,6 el cero '
            .'tecleado no está en el divisor.');

        $this->assertSame(0.525, (float) $servicio->cobertura,
            'El servicio contó el 0 tecleado como sin calificar.');
        $this->assertEqualsWithDelta(31.7333, (float) $servicio->parcial, 0.0001);
    }

    /**
     * **Sin nada calificado la parcial es `null`, nunca 0** — y la cobertura sí es 0.
     *
     * Dos hechos distintos: *«no hay con qué decirlo»* y *«no se ha evaluado nada de
     * un plan que sí existe»*. Un 0 en la parcial es una nota perdida que nadie sacó,
     * o sea el bug de origen mudado de sitio.
     */
    public function test_sin_una_sola_nota_puesta_la_parcial_es_null_y_la_cobertura_cero(): void
    {
        $ctx = $this->laPlanillaDelLienzo();

        DB::table('notas')->whereIn('id', $ctx['notas'])->update(['nota' => null]);

        $asig = $this->comoLoCargaUnLector($ctx, $ctx['alumno']);

        $this->assertNull($asig->nota_parcial,
            'La parcial salió '.var_export($asig->nota_parcial, true).' con el divisor en cero: '
            .'«va en cero» y «no hay con qué decirlo» volvieron a ser el mismo número.');

        $this->assertSame(0.0, (float) $asig->cobertura,
            'El plan existe y no se ha evaluado nada: la cobertura es 0, no null.');

        $this->assertSame(0.0, (float) $asig->nota_asignatura);
    }

    /**
     * **El segundo cero de división: `Σ peso` TOTAL = 0 → la cobertura es `null`.**
     *
     * Distinto del de arriba: allí `Σ peso` total vale 1 y la cobertura es 0 de
     * verdad. Aquí no hay plan del que hablar, y un 0 afirmaría que se conoce el plan
     * y que no se ha tocado. Medido por la fase 1 sobre `simonbolivar` en periodos
     * abiertos son **3.158 de 9.422 pares, el 33,5 %**.
     */
    public function test_con_todo_el_plan_a_peso_cero_la_cobertura_tambien_es_null(): void
    {
        $ctx = $this->laPlanillaDelLienzo();

        DB::table('subunidades')
            ->whereIn('unidad_id', [$ctx['unidad_1'], $ctx['unidad_2']])
            ->update(['porcentaje' => 0]);

        $asig = $this->comoLoCargaUnLector($ctx, $ctx['alumno']);

        $this->assertNull($asig->cobertura,
            'La cobertura salió '.var_export($asig->cobertura, true).' con el divisor total en '
            .'cero: un 0 ahí dice «no se ha evaluado nada de un plan conocido», y no hay plan.');

        $this->assertNull($asig->nota_parcial);
        $this->assertSame(0.0, (float) $asig->nota_asignatura);
    }

    /**
     * **El alumno sin una sola casilla sale con las dos en `null`.**
     *
     * Es la mayoría a mitad de periodo —1.786 de 2.553 pares medidos— y aquí, además,
     * es el caso que el otro calculador **no puede tener igual**: el servicio parte de
     * `matriculas` y devuelve una fila para este alumno con `nota` 0; éste parte de
     * las unidades de la asignatura y para él el alumno simplemente no tiene casillas.
     * Los dos acaban en lo mismo —0 y dos `null`— por caminos distintos, y eso es lo
     * que se comprueba.
     */
    public function test_el_alumno_sin_casillas_no_tiene_ni_parcial_ni_cobertura(): void
    {
        $ctx = $this->laPlanillaDelLienzo();
        $asig = $this->comoLoCargaUnLector($ctx, $ctx['alumno_sin_notas']);

        $this->assertSame(0.0, (float) $asig->nota_asignatura,
            'Un 0 aquí significa «sin notas», no «sacó cero».');
        $this->assertNull($asig->nota_parcial);
        $this->assertNull($asig->cobertura,
            'Sin casillas no hay denominador: una cobertura de 0 afirmaría que se conoce el plan.');
    }

    /**
     * **Una casilla de peso 0 calificada no cuenta como evaluada.**
     *
     * Son **2.242 de 36.705** subunidades vivas en la copia de desarrollo, el 6,1 %.
     * Lo que aportan es `peso × nota` con el peso a cero, así que no mueven la nota y
     * tampoco pueden mover la cobertura: si la movieran, el docente creería que
     * avanzó. Y el divisor tiene que aguantarlas sin dividir por cero.
     */
    public function test_una_casilla_de_peso_cero_calificada_no_mueve_nada(): void
    {
        $ctx = $this->laPlanillaDelLienzo();
        $antes = $this->comoLoCargaUnLector($ctx, $ctx['alumno']);

        $this->casilla($ctx['unidad_1'], 0, 50, $ctx['alumno']);

        $despues = $this->comoLoCargaUnLector($ctx, $ctx['alumno']);

        // **Que la casilla EXISTA se comprueba, o este caso pasa sin haber añadido nada.**
        // Es el modo de fallo de un test que afirma «esto no cambió»: si el montaje no llegó
        // a montarse, no cambiar nada es exactamente lo que se espera.
        $casillas = 0;

        foreach ($despues->unidades as $unidad) {
            if ((int) $unidad->unidad_id === $ctx['unidad_1']) {
                $casillas = count($unidad->subunidades);
            }
        }

        $this->assertSame(5, $casillas,
            'La casilla de peso 0 no llegó a crearse: este caso no está comprobando nada.');

        $this->assertSame((float) $antes->nota_asignatura, (float) $despues->nota_asignatura);
        $this->assertSame((float) $antes->nota_parcial, (float) $despues->nota_parcial,
            'Una casilla que no pesa movió la parcial.');
        $this->assertSame((float) $antes->cobertura, (float) $despues->cobertura,
            'Una casilla que no pesa se contó como evaluada: el docente leería que avanzó.');
    }

    /**
     * **Una asignatura mal repartida NO da una cobertura por encima del 100 %.**
     *
     * El mismo `Σ peso` está arriba y abajo, así que el cociente vive en `[0, 1]`. Lo
     * que hay que impedir no es el número: es que alguien venga a rescatar la
     * consecuencia 2 del 43 §3.bis poniendo un 1 en el divisor. Con eso, una
     * asignatura bien calificada cuyas unidades sumen 80 diría «80 % evaluado» para
     * siempre y el docente buscaría notas que no faltan. El delator del reparto malo
     * es `porcentaje_unidades`, no esto.
     */
    public function test_una_asignatura_mal_repartida_no_pasa_del_cien_por_cien(): void
    {
        $ctx = $this->laPlanillaDelLienzo();

        DB::table('unidades')->where('id', $ctx['unidad_2'])->update(['porcentaje' => 70]);

        $aMedias = $this->comoLoCargaUnLector($ctx, $ctx['alumno']);

        $this->assertSame(0.25, (float) $aMedias->cobertura,
            'La cobertura salió '.var_export($aMedias->cobertura, true).': con Σ peso = 1,40 y '
            .'0,35 evaluado es 0,25. Un 0,35 aquí es el divisor fijo a 1 que el doc pedía.');

        DB::table('notas')->whereIn('id', $ctx['notas'])->update(['nota' => 40]);

        $entera = $this->comoLoCargaUnLector($ctx, $ctx['alumno']);

        $this->assertSame(1.0, (float) $entera->cobertura,
            'Calificada entera, la cobertura es 1,0 y no 1,4.');
    }

    /**
     * **En `promedio` la parcial sigue a la acumulada DE ESTE MÉTODO, y el servicio
     * dice otra cosa. Las dos mitades de esa frase son el caso.**
     *
     * `calculoAlumnoNotas` **nunca ha pasado por `RepartoDeLaNota`**: pesa
     * `s.porcentaje` crudo, porque eso es lo que le traen `Unidad::deAsignatura` y
     * `Subunidad::deUnidad` —las dos que usan los seis lectores—. Así que en modo
     * `promedio` **la acumulada de la planilla ya discrepaba de la que se guarda,
     * antes de esta fase**: medido el 20 sep 2026 sobre `simonbolivar`, en el único
     * año de la copia que está en `promedio` (2026), **14 de 99** pares
     * alumno-asignatura-periodo dan distinto, y el peor **42,3 puntos** sobre una
     * escala de 0 a 50.
     *
     * **Por eso el peso de la parcial sale de los mismos dos números que la
     * acumulada y no de `RepartoDeLaNota`.** Sacarlo de allí arreglaría el divisor y
     * dejaría el cociente `acumulada ÷ Σ peso` midiendo **dos repartos distintos**,
     * que no es una nota de nada. Aquí el quinto indicador de peso 0 es inerte —en
     * `porcentaje` no existe para nadie— y en `promedio` repartiría, así que el caso
     * distingue de verdad los dos caminos.
     *
     * *Este caso NO bendice la discrepancia: la fija para que se vea.* Cerrarla es
     * mover el número que imprimen los dieciséis colegios, o sea una decisión, y
     * está apuntada en el 43 §7.
     */
    public function test_en_promedio_la_parcial_sigue_a_la_acumulada_de_este_metodo(): void
    {
        $ctx = $this->laPlanillaDelLienzo();

        $this->casilla($ctx['unidad_1'], 0, null, $ctx['alumno']);

        DB::table('years')->where('id', $ctx['year'])
            ->update(['reparto_subunidades' => RepartoDeLaNota::PROMEDIO]);

        $planilla = $this->comoLoCargaUnLector($ctx, $ctx['alumno']);
        $servicio = $this->filaDelServicio($ctx, $ctx['alumno']);

        // La planilla ignora el interruptor, hoy y desde siempre: los mismos números
        // que en `porcentaje`.
        $this->assertSame(16.66, (float) $planilla->nota_asignatura);
        $this->assertSame(47.6, (float) $planilla->nota_parcial);
        $this->assertSame(0.35, (float) $planilla->cobertura);

        // Y el servicio, que sí lo mira, dice otra cosa **en los tres**. Si algún día
        // esto se pone verde con los números iguales, es que alguien unificó los dos
        // calculadores: entonces este caso sobra y hay que borrarlo, no relajarlo.
        $this->assertSame(13.3, (float) $servicio->nota,
            'El servicio dejó de repartir en `promedio`.');
        $this->assertSame(47.5, (float) $servicio->parcial);
        $this->assertSame(0.28, (float) $servicio->cobertura);

        $this->assertNotEqualsWithDelta((float) $servicio->parcial, (float) $planilla->nota_parcial, 0.001,
            'Los dos calculadores coinciden en `promedio`: si es porque se unificaron, este caso '
            .'sobra; si es porque el divisor de la planilla pasó a `RepartoDeLaNota` sin que la '
            .'acumulada lo hiciera, la parcial es ahora un cociente entre dos repartos distintos.');
    }

    /**
     * **Y llegan a la respuesta, que es lo que nadie comprueba mirando el método.**
     *
     * `GET planillas/show-profesor/{id}` es la planilla del docente: para cada
     * asignatura suya, cada alumno y cada periodo, la definitiva. Aquí es donde el
     * 43 §2 mide el daño —767 pares en rojo de 767, y 258 de ellos en SUPERIOR
     * contando sólo lo evaluado—, así que es el sitio donde los dos números tenían
     * que aparecer.
     *
     * Los tres viajan **juntos**: una parcial sin su cobertura es un 47,6 que puede
     * venir de una sola casilla.
     */
    public function test_la_planilla_del_profesor_publica_los_tres_numeros(): void
    {
        $ctx = $this->laPlanillaDelLienzo();

        $profesorId = DB::table('asignaturas')->where('id', $ctx['asignatura'])->value('profesor_id');

        $this->assertNotNull($profesorId,
            'La asignatura del lienzo no tiene profesor, así que `planillas/show-profesor` no la '
            .'devolvería y este caso no comprobaría nada.');

        $r = $this->withToken($this->tokenDelPersonalDe($ctx['year']))
            ->getJson('/api/planillas/show-profesor/'.$profesorId);

        $r->assertStatus(200);

        $periodo = $this->periodoDelLienzoEnLaRespuesta($r->json(), $ctx);

        $this->assertArrayHasKey('nota_parcial', $periodo,
            'La planilla del docente sigue publicando sólo la acumulada: los dos números se '
            .'calculan y no salen por ningún sitio.');
        $this->assertArrayHasKey('cobertura', $periodo);

        $this->assertEqualsWithDelta(16.66, (float) $periodo['nota_asignatura'], 0.001);
        $this->assertEqualsWithDelta(47.6, (float) $periodo['nota_parcial'], 0.001);
        $this->assertEqualsWithDelta(0.35, (float) $periodo['cobertura'], 0.001);
    }

    /**
     * La asignatura calculada **como la cargan los seis lectores**, y no de otra forma.
     *
     * Los seis hacen exactamente estas tres líneas: `Unidad::deAsignatura`,
     * `Subunidad::deUnidad` por unidad y `calculoAlumnoNotas`. Cargarla de otra manera
     * —por ejemplo con `deAsignaturaCalculada`, que sí mira el reparto— probaría un
     * camino que no usa nadie.
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

    /**
     * La fila del **otro** calculador, el que escribe, para el mismo alumno.
     *
     * @param  array<string, mixed>  $ctx
     */
    private function filaDelServicio(array $ctx, int $alumnoId): object
    {
        foreach (DefinitivasDeAsignatura::calcular($ctx['asignatura'], $ctx['periodo']) as $fila) {
            if ((int) $fila->alumno_id === $alumnoId) {
                return $fila;
            }
        }

        $this->fail('El alumno '.$alumnoId.' no salió en el cálculo del servicio.');
    }

    /**
     * El periodo del lienzo dentro de la respuesta de `planillas/show-profesor`.
     *
     * La respuesta es `[year, asignaturas]` y dentro va `asignaturas[].alumnos[]
     * .periodos[]`. Se busca por id en los tres niveles en vez de por posición: el
     * profesor puede tener más asignaturas y el grupo más alumnos, y un `[0]` haría
     * que este caso comprobara la asignatura de otro sin decirlo.
     *
     * @param  array<int, mixed>  $cuerpo
     * @param  array<string, mixed>  $ctx
     * @return array<string, mixed>
     */
    private function periodoDelLienzoEnLaRespuesta(array $cuerpo, array $ctx): array
    {
        foreach ($cuerpo[1] ?? [] as $asignatura) {
            if ((int) ($asignatura['asignatura_id'] ?? 0) !== $ctx['asignatura']) {
                continue;
            }

            foreach ($asignatura['alumnos'] ?? [] as $alumno) {
                if ((int) ($alumno['alumno_id'] ?? 0) !== $ctx['alumno']) {
                    continue;
                }

                foreach ($alumno['periodos'] ?? [] as $periodo) {
                    if ((int) ($periodo['id'] ?? 0) === $ctx['periodo']) {
                        return $periodo;
                    }
                }
            }
        }

        $this->fail('La asignatura '.$ctx['asignatura'].', el alumno '.$ctx['alumno'].' y el '
            .'periodo '.$ctx['periodo'].' no salieron juntos en la planilla del profesor.');
    }
}
