<?php

namespace Tests\Contrato;

use App\Services\DefinitivasDeAsignatura;
use App\Support\RepartoDeLaNota;
use Illuminate\Support\Facades\DB;
use Tests\Contrato\Concerns\LaPlanillaDelLienzo;

/**
 * **Fase 1 de [43](../../docs/migracion/43-lo-que-todavia-no-se-ha-calificado.md):
 * tres números donde hoy hay uno.**
 *
 * La definitiva de hoy contesta *«cuánto del periodo entero lleva ganado»*, y a
 * mitad de periodo **ésa no es la pregunta de nadie**: pintada con la escala del
 * colegio dice BAJO de casi todo el mundo. Medido en la copia de desarrollo, periodo
 * 2 de 2025: de **767** pares alumno-asignatura con alguna nota puesta salían **767
 * en rojo**, y **258** iban en SUPERIOR contando sólo lo evaluado.
 *
 * `calcular()` devuelve además `parcial` —lo evaluado, normalizado por su propio
 * peso— y `cobertura` —qué parte del plan se ha evaluado—. **Ninguna de las dos se
 * guarda.**
 *
 * ## El montaje, que es el del lienzo del doc 43 y por eso los números se leen ahí
 *
 * Unidad 1 al **70 %** con cuatro indicadores al **30/20/25/25**, de los que sólo el
 * Taller (30) y el Quiz (20) están calificados —**48** y **47**—, y unidad 2 al
 * **30 %** con un indicador al 100 % sin calificar:
 *
 *     acumulada  = 0,70 × (0,30×48 + 0,20×47)        = 0,70 × 23,8  = 16,66   BAJO
 *     parcial    = 16,66 ÷ (0,70×0,30 + 0,70×0,20)   = 16,66 ÷ 0,35 = 47,60   SUPERIOR
 *     cobertura  = 0,35 ÷ 1,00                                      = 0,35
 *
 * **Las dos son correctas y dicen cosas distintas**, que es la afirmación entera de
 * la fase. El 16,66 no está mal calculado.
 *
 * ## Las cuatro formas de aprobar sin medir nada, y cómo las cierra cada caso
 *
 * 1. **Cobertura por casillas en vez de por peso.** Aquí hay **2 de 5** casillas
 *    calificadas —el 40 %— y la cobertura es **35 %**. Con los pesos iguales los dos
 *    números coinciden y el caso pasaría con la fórmula equivocada, así que los
 *    indicadores van **desiguales a propósito**. Un indicador del 40 % sin calificar
 *    y uno del 5 % no dejan el mismo hueco.
 * 2. **Devolver 0 donde no hay nada que decir.** Sin una sola nota puesta, `parcial`
 *    tiene que ser **`NULL`** y `cobertura` **0,0**: *«no hay con qué decirlo»* y
 *    *«no se ha evaluado nada de un plan que sí existe»* son dos hechos distintos, y
 *    un 0 en la parcial es una nota perdida que nadie sacó. **Y hay un segundo cero
 *    de división que el doc 43 no previó** —`Σ peso` TOTAL = 0—, donde la que se pone
 *    a `NULL` es la **cobertura**: son **3.158 de 9.422 pares, el 33,5 %**, así que
 *    tiene su caso propio y no es un rincón.
 * 3. **Un solo modo de reparto.** `porcentaje` es el defecto de los dieciséis, así
 *    que un caso escrito sólo en ese modo pasaría igual con el peso cableado a mano
 *    en la consulta. **Y el caso de `promedio` pasó en verde sin probar nada hasta que
 *    se le mutó el código debajo**: con los cuatro indicadores del lienzo, el peso de
 *    lo evaluado sale 0,35 en los DOS modos —0,30+0,20 en uno y 2×0,25 en el otro—,
 *    así que el divisor **coincidía por casualidad**. Por eso ese caso añade un quinto
 *    indicador de peso 0: inerte en `porcentaje`, repartiendo en `promedio`.
 *    *Comprobar que un número sale bien no prueba que salga de donde uno cree.*
 * 4. **Rescatar la «cobertura 120 %» que el doc prometía.** No existe: el mismo
 *    `Σ peso` está arriba y abajo. Tiene caso propio con una asignatura al 140 %,
 *    porque lo que hay que impedir no es el número sino que alguien ponga un 1 en el
 *    divisor para que la frase del doc sea cierta.
 *
 * > **Y el caso que protege lo que NO puede moverse:**
 * > {@see test_la_parcial_no_llega_a_la_columna_de_la_definitiva}. La definitiva es
 * > la que cierra el periodo y la que imprimen los dieciséis colegios; el fallo caro
 * > de esta fase no es equivocar la parcial, es **guardarla donde va la otra**. Ahí
 * > el número escrito tiene que seguir siendo 16,66.
 *
 * ## Lo que este fichero NO prueba, y dónde se prueba desde el 20 sep 2026
 *
 * **La planilla no pasa por `DefinitivasDeAsignatura`.** Pasa por
 * `App\Models\Asignatura::calculoAlumnoNotas`, que es un **segundo calculador de la
 * definitiva entero y paralelo, en PHP**, con seis lectores. Es el que produce
 * `nota_asignatura`. Que ése dé los mismos tres números es la **fase 1.bis** y vive en
 * {@see LaParcialEnLaPlanillaTest}, sobre **este mismo montaje** — por eso el lienzo se
 * sacó a {@see LaPlanillaDelLienzo} y no se copió.
 *
 * **A los boletines sigue sin llegar**, y el porqué está en la §Fase 1.bis del 43: el
 * único de los seis lectores que los alcanza publica una media del año y no una
 * definitiva por periodo.
 */
class LaParcialYLaCoberturaTest extends CasoDeContrato
{
    // El montaje vive en el trait desde la **fase 1.bis** del 43, y no por limpieza: el
    // segundo calculador —`Asignatura::calculoAlumnoNotas`, el de la planilla— tiene
    // que dar estos mismos números, y eso sólo se puede comprobar pasándole **la
    // misma** planilla. Dos montajes parecidos seguirían verdes por separado el día
    // que uno de los dos cambiara un porcentaje.
    use LaPlanillaDelLienzo;

    /**
     * **La definitiva y la parcial son el mismo número desde el 22 sep 2026.**
     *
     * Hasta ese día este test se llamaba *«…y la acumulada no se mueve»* y fijaba lo
     * contrario: `nota` era `Σ aporte` —16,66, con las tres casillas sin calificar
     * pesando como ceros— y `parcial` era 47,6. Joseth lo invirtió con el caso delante:
     * el 16,66 no es la nota de este alumno, es la del que sí tuvo todas las casillas.
     *
     * **Que los dos campos coincidan no los hace redundantes**, y por eso siguen
     * comprobándose los dos: se separan en el único caso que queda —un periodo cerrado
     * con `cero`—, y `parcial` se publica también donde no hay definitiva escrita.
     */
    public function test_la_definitiva_es_la_parcial_y_va_sobre_lo_evaluado(): void
    {
        $ctx = $this->laPlanillaDelLienzo();
        $fila = $this->filaDe($ctx, $ctx['alumno']);

        $this->assertSame(47.6, (float) $fila->nota,
            'La definitiva no se normalizó por el peso de lo evaluado: volvió a ser la suma '
            .'cruda, que es la nota del que sí tuvo las cinco casillas.');

        $this->assertSame(47.6, (float) $fila->parcial,
            'La parcial no se normalizó por el peso de lo evaluado.');
    }

    /**
     * La cobertura se calcula sobre el **peso**, no sobre el número de casillas.
     *
     * 2 de 5 casillas es el 40 %; lo evaluado pesa el 35 %. Si este caso diera 0,4
     * la fórmula estaría contando indicadores, y entonces un indicador del 40 % sin
     * calificar y uno del 5 % dejarían el mismo hueco.
     */
    public function test_la_cobertura_va_sobre_el_peso_y_no_sobre_el_numero_de_casillas(): void
    {
        $ctx = $this->laPlanillaDelLienzo();
        $fila = $this->filaDe($ctx, $ctx['alumno']);

        $this->assertSame(5, (int) $fila->notas, 'El montaje ya no tiene cinco casillas.');

        $this->assertSame(0.35, (float) $fila->cobertura,
            'La cobertura salió '.$fila->cobertura.': con 2 de 5 casillas, 0,4 significa '
            .'que se están contando indicadores en vez de peso.');
    }

    /**
     * **Sin nada calificado la parcial es `NULL`, nunca 0** — y la cobertura sí es 0.
     *
     * Son dos hechos distintos y por eso se comprueban juntos: *«no hay con qué
     * decirlo»* y *«no se ha evaluado nada de un plan que sí existe»*. Un 0 en la
     * parcial sería una nota perdida que nadie sacó, que es el bug de origen mudado
     * de sitio.
     */
    public function test_sin_una_sola_nota_puesta_la_parcial_es_null_y_la_cobertura_cero(): void
    {
        $ctx = $this->laPlanillaDelLienzo();

        DB::table('notas')->whereIn('id', $ctx['notas'])->update(['nota' => null]);

        $fila = $this->filaDe($ctx, $ctx['alumno']);

        $this->assertNull($fila->parcial,
            'La parcial salió '.var_export($fila->parcial, true).' con el divisor en cero: '
            .'«va en cero» y «no hay con qué decirlo» volvieron a ser el mismo número.');

        $this->assertSame(0.0, (float) $fila->cobertura,
            'El plan existe y no se ha evaluado nada: la cobertura es 0, no NULL.');

        $this->assertSame(0.0, (float) $fila->nota);
    }

    /**
     * **Y el segundo cero de división, que el doc 43 no previó: `Σ peso` TOTAL = 0.**
     *
     * Aquí la cobertura es `0 ÷ 0`, y tiene que ser **`NULL`** por el mismo motivo que
     * la parcial — un 0 afirmaría que se conoce el plan y que no se ha tocado, cuando
     * lo cierto es que **no hay plan del que hablar**. No es un rincón: medido el 20
     * sep 2026 sobre `simonbolivar` en periodos abiertos son **3.158 de los 9.422
     * pares, el 33,5 %** — 3.059 sin una sola fila (el caso de abajo) y **99 con todas
     * sus casillas a peso 0**, que es el que monta este caso.
     *
     * Y es distinto del de arriba: allí `Σ peso` total vale 1 y la cobertura es 0 de
     * verdad. Los dos ceros de división existen, dan `NULL` los dos y **no son el
     * mismo hecho**.
     */
    public function test_con_todo_el_plan_a_peso_cero_la_cobertura_tambien_es_null(): void
    {
        $ctx = $this->laPlanillaDelLienzo();

        DB::table('subunidades')
            ->whereIn('unidad_id', [$ctx['unidad_1'], $ctx['unidad_2']])
            ->update(['porcentaje' => 0]);

        $fila = $this->filaDe($ctx, $ctx['alumno']);

        $this->assertSame(5, (int) $fila->notas, 'Las casillas siguen estando: lo que no pesa nada es el plan.');

        $this->assertNull($fila->cobertura,
            'La cobertura salió '.var_export($fila->cobertura, true).' con el divisor total '
            .'en cero: un 0 ahí dice «no se ha evaluado nada de un plan conocido», y no hay plan.');

        $this->assertNull($fila->parcial);
        $this->assertSame(0.0, (float) $fila->nota);
    }

    /**
     * **El alumno sin una sola fila en `notas` sale con las dos en `NULL`.**
     *
     * Es la mayoría a mitad de periodo —1.786 de 2.553 pares medidos— y no es lo
     * mismo que el caso de arriba: allí las casillas existen vacías y el plan se
     * puede leer de ellas; aquí **no hay filas de las que deducir ningún
     * denominador**. Su definitiva sigue siendo 0 por la regla de siempre: *un 0 ahí
     * significa «sin notas», no «sacó cero»*.
     */
    public function test_el_alumno_sin_casillas_no_tiene_ni_parcial_ni_cobertura(): void
    {
        $ctx = $this->laPlanillaDelLienzo();
        $fila = $this->filaDe($ctx, $ctx['alumno_sin_notas']);

        $this->assertSame(0, (int) $fila->notas, 'El montaje necesita un alumno sin casillas.');
        $this->assertNull($fila->parcial);
        $this->assertNull($fila->cobertura,
            'Sin filas no hay denominador: una cobertura de 0 afirmaría que se conoce el plan.');
        $this->assertSame(0.0, (float) $fila->nota);
    }

    /**
     * **Una casilla de peso 0 calificada no cuenta como evaluado.**
     *
     * Son **2.242 de 36.705** subunidades vivas en la copia de desarrollo, el 6,1 %.
     * Calificarlas no mueve la nota —lo que aportan es `peso × nota` con el peso a
     * cero— así que tampoco puede mover la cobertura: si la moviera, el docente
     * creería que avanzó. Y el divisor tiene que aguantarlas sin dividir por cero.
     *
     * **Sólo tiene sentido en modo `porcentaje`**, y por eso no hay gemelo en
     * `promedio`: allí una subunidad pesa `1/n` y su `porcentaje` no lo mira nadie,
     * así que «peso 0» no existe — lo que existe es una subunidad más repartiendo.
     */
    public function test_una_casilla_de_peso_cero_calificada_no_mueve_nada(): void
    {
        $ctx = $this->laPlanillaDelLienzo();
        $antes = $this->filaDe($ctx, $ctx['alumno']);

        $this->casilla($ctx['unidad_1'], 0, 50, $ctx['alumno']);

        $despues = $this->filaDe($ctx, $ctx['alumno']);

        $this->assertSame(6, (int) $despues->notas, 'La casilla de peso 0 no llegó a crearse.');

        $this->assertSame((float) $antes->nota, (float) $despues->nota);
        $this->assertSame((float) $antes->parcial, (float) $despues->parcial,
            'Una casilla que no pesa movió la parcial.');
        $this->assertSame((float) $antes->cobertura, (float) $despues->cobertura,
            'Una casilla que no pesa se contó como evaluada: el docente leería que avanzó.');
    }

    /**
     * **Y las dos son correctas en el OTRO modo de reparto.**
     *
     * Al lienzo se le añade **un quinto indicador de porcentaje 0 y sin calificar**, y
     * ahí está todo el caso: en `porcentaje` esa casilla **no existe para nadie** —es
     * el caso de arriba— y en `promedio` **reparte como las demás**, porque allí una
     * subunidad pesa `1/n` y su porcentaje no lo mira nadie. O sea que el mismo dato
     * mueve el divisor en un modo y no en el otro:
     *
     *     acumulada  = 0,70 × (0,20×48 + 0,20×47)   = 0,70 × 19    = 13,30
     *     parcial    = 13,30 ÷ (0,70×0,20 × 2)      = 13,30 ÷ 0,28 = 47,50
     *     cobertura  = 0,28 ÷ 1,00                                 =  0,28
     *
     * **Sin ese quinto indicador este caso no probaba nada, y pasó en verde mientras
     * no lo tuvo.** Con los cuatro del lienzo, el peso de lo evaluado sale 0,35 en los
     * DOS modos —0,30+0,20 en uno y 2×0,25 en el otro—, así que el divisor coincidía
     * por casualidad y el caso pasaba **con el modo cableado a `porcentaje`**. Lo
     * delató mutar la consulta a propósito, no correrla: *comprobar que un número sale
     * bien no prueba que salga de donde uno cree.*
     *
     * Con el fragmento cableado al otro modo, hoy, esto da parcial **38,0** y cobertura
     * **0,35**.
     */
    public function test_los_dos_modos_de_reparto_dan_su_propia_parcial(): void
    {
        $ctx = $this->laPlanillaDelLienzo();

        $this->casilla($ctx['unidad_1'], 0, null, $ctx['alumno']);

        DB::table('years')->where('id', $ctx['year'])
            ->update(['reparto_subunidades' => RepartoDeLaNota::PROMEDIO]);

        $fila = $this->filaDe($ctx, $ctx['alumno']);

        // 13,30 era la acumulada, y hasta el 22 sep 2026 era esto lo que se guardaba. Hoy
        // la definitiva ya viene dividida, así que este renglón dice lo mismo que el de
        // abajo **en el otro modo de reparto**, que es lo que este caso vino a comprobar.
        $this->assertSame(47.5, (float) $fila->nota);

        $this->assertSame(47.5, (float) $fila->parcial,
            'La parcial salió '.$fila->parcial.': con el reparto en `promedio` los cinco '
            .'indicadores pesan 1/5 y la parcial es la media de lo calificado.');

        $this->assertSame(0.28, (float) $fila->cobertura,
            'La cobertura salió '.$fila->cobertura.': 0,35 es la del reparto que el colegio '
            .'apagó, o sea el fragmento del peso escrito a mano en vez de salir de `RepartoDeLaNota`.');
    }

    /**
     * **Una asignatura mal repartida NO da una cobertura por encima del 100 %**, y el
     * doc 43 promete que sí.
     *
     * La §3.bis c consecuencia 2 dice que una asignatura cuyas unidades sumen 120
     * *«termine de calificarse y salga con cobertura 120 %»*. **No puede**: el mismo
     * `Σ peso` está en el numerador y en el denominador, así que el cociente vive en
     * `[0, 1]`. Medido el 20 sep 2026 sobre `simonbolivar` en periodos abiertos: **0
     * de 9.422 pares por encima del 100 %, con 328 asignaturas mal repartidas dentro
     * de la muestra** —106 por encima de 1, hasta 2,54—.
     *
     * Este caso lo fija por los dos extremos —a medio calificar y entera— porque lo
     * que hay que impedir no es el número: es que alguien venga a **rescatar** la
     * consecuencia 2 poniendo un 1 en el divisor. Con eso, una asignatura bien
     * calificada cuyas unidades sumen 80 diría «80 % evaluado» para siempre y el
     * docente buscaría notas que no faltan.
     *
     * **El delator del reparto malo ya existe y viaja en la misma respuesta**, y por
     * eso el caso también lo comprueba: `porcentaje_unidades`, la regla 2 de
     * `DefinitivasDeAsignatura`, que aquí dice 140.
     */
    public function test_una_asignatura_mal_repartida_no_pasa_del_cien_por_cien(): void
    {
        $ctx = $this->laPlanillaDelLienzo();

        // Las unidades pasan a sumar 140: el reparto está mal y la definitiva saldrá
        // rara a propósito, que es la regla 2 y no se toca.
        DB::table('unidades')->where('id', $ctx['unidad_2'])->update(['porcentaje' => 70]);

        $aMedias = $this->filaDe($ctx, $ctx['alumno']);

        $this->assertSame(0.25, (float) $aMedias->cobertura,
            'La cobertura salió '.$aMedias->cobertura.': con Σ peso = 1,40 y 0,35 evaluado '
            .'es 0,25. Un 0,35 aquí es el divisor fijo a 1 que el doc pedía.');

        DB::table('notas')->whereIn('id', $ctx['notas'])->update(['nota' => 40]);

        $entera = $this->filaDe($ctx, $ctx['alumno']);

        $this->assertSame(1.0, (float) $entera->cobertura,
            'Calificada entera, la cobertura es 1,0 y no 1,4: la consecuencia 2 del doc 43 '
            .'no se sostiene y no se rescata metiendo un segundo divisor.');

        $recalculo = DefinitivasDeAsignatura::recalcular(
            $ctx['asignatura'], $ctx['periodo'], null, $ctx['alumno']
        );

        $this->assertSame(140.0, $recalculo['porcentaje_unidades'],
            'El delator del reparto malo es éste, no la cobertura.');
    }

    /**
     * **Lo que se guarda es la parcial, y se guarda UNA vez.**
     *
     * Este caso nació diciendo lo contrario —*«la parcial no llega a la columna de la
     * definitiva»*— y protegía `notas_finales` de moverse: el alumno pasaría de 16,66 a
     * 47,60 y *«el boletín sería creíble»*. **El 22 sep 2026 Joseth decidió que el
     * boletín diga 47,60**, porque el 16,66 era creíble y era de otro alumno.
     *
     * Lo que el caso sigue comprobando y no ha cambiado: que se escriba **una sola
     * fila**. El duplicado es el fallo caro de esta tabla y no depende de qué fórmula
     * la llene.
     */
    public function test_la_definitiva_guardada_es_la_parcial_y_hay_una_sola(): void
    {
        $ctx = $this->laPlanillaDelLienzo();

        DefinitivasDeAsignatura::recalcular($ctx['asignatura'], $ctx['periodo'], null, $ctx['alumno']);

        $guardada = DB::table('notas_finales')
            ->where('alumno_id', $ctx['alumno'])
            ->where('asignatura_id', $ctx['asignatura'])
            ->where('periodo_id', $ctx['periodo'])
            ->value('nota');

        $this->assertNotNull($guardada, 'No se escribió ninguna definitiva.');
        $this->assertSame(47.6, (float) $guardada,
            'La columna de la definitiva se quedó con '.$guardada.': lo que se guarda es la '
            .'nota sobre lo evaluado, y una suma cruda ahí es la nota de otro alumno.');

        $this->assertSame(1, DB::table('notas_finales')
            ->where('alumno_id', $ctx['alumno'])
            ->where('asignatura_id', $ctx['asignatura'])
            ->where('periodo_id', $ctx['periodo'])
            ->count());
    }

    /**
     * `recalcular()` las devuelve **sólo cuando se pidió un alumno**, como
     * `definitiva`.
     *
     * Sin `$soloAlumno` el recálculo cubre la asignatura entera y no existe «la»
     * parcial: devolver la de alguien sería el mismo fallo que ya se arregló con
     * `porcentaje_unidades` —dos campos del mismo array hablando de cosas distintas
     * y nada que lo dijera—.
     */
    public function test_recalcular_devuelve_las_dos_solo_con_un_alumno_pedido(): void
    {
        $ctx = $this->laPlanillaDelLienzo();

        $suyo = DefinitivasDeAsignatura::recalcular(
            $ctx['asignatura'], $ctx['periodo'], null, $ctx['alumno']
        );

        $this->assertSame(47.6, $suyo['parcial']);
        $this->assertSame(0.35, $suyo['cobertura']);
        // Los dos campos dicen el mismo número desde el 22 sep 2026 y **siguen siendo dos
        // campos**: `definitiva` es lo que quedó GUARDADO y `parcial` lo que se calculó.
        // Se separan en el periodo cerrado con `cero`, que es el único caso que queda.
        $this->assertSame(47.6, $suyo['definitiva']['nota'],
            'La definitiva guardada no es la nota sobre lo evaluado.');

        $delGrupo = DefinitivasDeAsignatura::recalcular($ctx['asignatura'], $ctx['periodo']);

        $this->assertNull($delGrupo['parcial'],
            'Sin alumno pedido no hay «la» parcial: devolver una es atribuirle a todos la de uno.');
        $this->assertNull($delGrupo['cobertura']);
    }

    /**
     * La fila de `calcular()` de un alumno concreto.
     *
     * **Se pregunta a `calcular()` y no a la tabla**, que es donde vive lo que esta
     * fase añade: la parcial y la cobertura no se guardan en ninguna columna.
     *
     * @param  array<string, mixed>  $ctx
     */
    private function filaDe(array $ctx, int $alumnoId): object
    {
        foreach (DefinitivasDeAsignatura::calcular($ctx['asignatura'], $ctx['periodo']) as $fila) {
            if ((int) $fila->alumno_id === $alumnoId) {
                return $fila;
            }
        }

        $this->fail('El alumno '.$alumnoId.' no salió en el cálculo de la asignatura.');
    }
}
