<?php

namespace Tests\Contrato;

use App\Services\BoletinIndependiente;
use App\Services\DefinitivasDeAsignatura;
use App\Support\CierreDeLoNoCalificado;
use App\Support\RepartoDeLaNota;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

/**
 * **Fase 4 de [43](../../docs/migracion/43-lo-que-todavia-no-se-ha-calificado.md):
 * el cierre, que es donde el hueco tiene que morir.**
 *
 * La fase 0 hizo que una casilla sin calificar valga `NULL` y la 1 puso al lado de la
 * definitiva la parcial. Las dos son correctas **mientras el periodo esté abierto**.
 * Al cerrar la pregunta cambia de dueño: *dejar de contar lo no calificado durante el
 * periodo es correcto; dejar de contarlo al cerrar es aprobar a quien no entregó*.
 *
 * **D3**: lo elige cada rector, con tres salidas, y `cero` de fábrica.
 *
 * ## Las seis formas de aprobar sin medir nada, y cómo las cierra cada caso
 *
 * **1. Creer que `cero` no hace falta porque «ya valían cero».** Es cierto **para la
 * definitiva** y falso para todo lo demás, y es la trampa central de esta fase: la
 * definitiva no normaliza, así que `SUM(peso × NULL)` y `SUM(peso × 0)` dan el mismo
 * número. Lo que cambia al escribir los ceros es la **cobertura** (pasa a 1) y la
 * **parcial** (pasa a coincidir con lo que imprime el boletín). Por eso
 * {@see cerrar_con_cero_escribe_los_ceros_y_la_definitiva_no_se_mueve} comprueba las
 * **tres** cosas a la vez: si sólo mirara la definitiva estaría verde tanto con el
 * código puesto como con el código borrado.
 *
 * **2. Creer que `fuera` es lo mismo que `cero` con otro nombre.** Sin
 * {@see cerrar_con_fuera_hace_que_la_definitiva_sea_la_parcial}, D3 sería un selector
 * de tres opciones que hace lo mismo con dos de ellas, **y ningún test lo diría**.
 *
 * **3. Dejar que la elección del rector alcance un periodo ya cerrado.** Es la regla
 * dura del encargo, y el caso que la fija es
 * {@see cambiar_la_eleccion_no_mueve_un_periodo_ya_cerrado}: cierra en `cero`, cambia
 * el año a `fuera` y comprueba que la definitiva del periodo cerrado **no se movió ni
 * un decimal**. Con el cálculo leyendo `years` en vez de `periodos`, ese caso sale
 * rojo y ningún otro se entera.
 *
 * **4. Tocar los periodos que ya estaban cerrados el día del despliegue.** Son todos
 * los de los dieciséis colegios. {@see volver_a_cerrar_un_periodo_sin_marca_no_toca_nada}
 * es el que impide que «ponerles los ceros que de todas formas valían» se cuele como
 * una mejora: movería `updated_by` y `updated_at` de un millón de filas de periodos
 * cuyas definitivas están impresas y firmadas.
 *
 * **5. Contestar 422 y escribir igual.** `bloquear` que cierra el periodo antes de
 * negarse es peor que no tenerlo. Por eso
 * {@see bloquear_no_cierra_y_no_escribe_nada} mira **la fila**, no el código HTTP.
 *
 * **6. Dejar la puerta de atrás abierta.** La decisión vive en un cálculo, y hay
 * **otro** botón que escribe la misma columna con otra fórmula:
 * `definitivas_periodos/calcular-grupo-periodo`. Sin
 * {@see el_recalculo_por_grupo_no_deshace_un_cierre_con_fuera}, pulsar ese botón
 * después de cerrar con `fuera` devolvería las definitivas a la acumulada **en
 * silencio y con 200**.
 *
 * ## El montaje, que es el del lienzo del doc 43 y por eso los números se leen ahí
 *
 * Unidad 1 al **70 %** con cuatro indicadores al 30/20/25/25, de los que sólo el
 * Taller (30) y el Quiz (20) están calificados —**48** y **47**—, y unidad 2 al
 * **30 %** con un indicador al 100 % sin calificar:
 *
 *     acumulada  = 0,70 × (0,30×48 + 0,20×47)        = 16,66   BAJO
 *     parcial    = 16,66 ÷ (0,70×0,30 + 0,70×0,20)   = 47,60   SUPERIOR
 *     cobertura  = 0,35 ÷ 1,00                       = 0,35
 *
 * Al cerrar con `cero`, los tres huecos valen 0 y la acumulada **sigue siendo 16,66**
 * —los ceros no aportan— con cobertura 1. Al cerrar con `fuera`, la definitiva pasa a
 * **47,60**. *Ésos son los dos números que un rector está eligiendo.*
 */
class ElCierreYLoNoCalificadoTest extends CasoDeContrato
{
    private const RUTA_ELEGIR = '/api/years/cierre-sin-calificar';

    private const RUTA_CERRAR = '/api/periodos/toggle-profes-pueden-editar-notas';

    /** Los cuatro indicadores de la unidad 1, con su nota — `null` es sin calificar. */
    private const UNIDAD_1 = [
        ['porcentaje' => 30, 'nota' => 48],
        ['porcentaje' => 20, 'nota' => 47],
        ['porcentaje' => 25, 'nota' => null],
        ['porcentaje' => 25, 'nota' => null],
    ];

    /** La acumulada del lienzo: la que cierra el periodo y la que imprimen los dieciséis. */
    private const ACUMULADA = 16.66;

    /** La parcial del lienzo: lo evaluado, normalizado por su propio peso. */
    private const PARCIAL = 47.6;

    // ── Lo que hay hoy, antes de que nadie elija nada ────────────────────────

    /**
     * **Los años nacen en `fuera` y los periodos sin marca.** Nacían en `cero` hasta el
     * 24 sep 2026: Joseth pidió `fuera` en los dieciséis después de que en quibdo un
     * cierre pusiera a 0 las casillas que los docentes habían vaciado
     * (`2026_09_24_900000_el_cierre_deja_fuera_por_defecto`).
     */
    #[Test]
    public function todos_los_anios_nacen_en_fuera_y_ningun_periodo_trae_marca(): void
    {
        $anios = DB::table('years')->whereNull('deleted_at')->count();

        $this->assertGreaterThan(0, $anios, 'El seed no tiene años: este caso no mediría nada.');

        $this->assertSame(0, DB::table('years')->whereNull('deleted_at')
            ->where('cierre_sin_calificar', '!=', CierreDeLoNoCalificado::FUERA)->count(),
            'Algún año nace con una decisión que no tomó nadie, y son '.$anios.' años.');

        $periodos = DB::table('periodos')->whereNull('deleted_at')->count();

        $this->assertGreaterThan(0, $periodos, 'El seed no tiene periodos.');

        $this->assertSame(0, DB::table('periodos')->whereNull('deleted_at')
            ->whereNotNull('cierre_sin_calificar')->count(),
            'Un periodo trae marca sin que nadie lo haya cerrado por este camino, y son '
            .$periodos.' periodos. La migración no rellena hacia atrás a propósito: marcar '
            .'un periodo de 2021 como «cero» afirmaría que alguien tomó esa decisión, y la '
            .'tomó el NOT NULL de `notas.nota`.');
    }

    /**
     * Las dos listas del código y las dos del `enum` dicen lo mismo.
     *
     * Es la trampa que ya lleva escrita `Autoriza::PERMISO_*` y que volvió a costar en
     * `modelo_evaluacion`: dos sitios que dicen una cadena y **no falla nada** hasta
     * que alguien guarda el valor que sólo conoce uno de los dos. Con el `sql_mode` de
     * estos servidores un valor fuera del `enum` **no lanza: guarda la cadena vacía y
     * devuelve 200**.
     *
     * Y son **dos listas distintas a propósito**: `bloquear` no se aplica nunca —su
     * resultado es que no hay cierre—, así que un periodo no puede quedarse con esa
     * marca. Si el `enum` de `periodos` la admitiera, ese estado sería representable y
     * el día que alguien lo encontrara escrito no habría forma de saber si es un fallo
     * o una decisión.
     */
    #[Test]
    public function las_listas_del_codigo_y_las_de_la_base_son_las_mismas(): void
    {
        $this->assertSame(
            CierreDeLoNoCalificado::SALIDAS,
            $this->valoresDelEnum('years', 'cierre_sin_calificar'),
            'La lista de `years` y el enum de la columna se han separado.'
        );

        $this->assertSame(
            CierreDeLoNoCalificado::SALIDAS_QUE_SE_CONGELAN,
            $this->valoresDelEnum('periodos', 'cierre_sin_calificar'),
            '`bloquear` entró en el enum de `periodos`: es un estado que no puede existir, '
            .'porque con `bloquear` no hay cierre que congelar.'
        );
    }

    // ── Las tres salidas ────────────────────────────────────────────────────

    /**
     * **`cero`: se escriben los ceros, la definitiva no se mueve y la cobertura sí.**
     *
     * Las tres afirmaciones van juntas porque por separado ninguna dice nada. Que la
     * definitiva no se mueva es lo que hace que esto se pueda desplegar a los dieciséis
     * con el defecto puesto; que la cobertura pase a 1 y la parcial a coincidir con la
     * definitiva es **la razón entera de escribir los ceros**: en un periodo cerrado,
     * lo que ve la familia y lo que dice el papel vuelven a ser el mismo número.
     */
    #[Test]
    public function cerrar_con_cero_escribe_los_ceros_y_la_definitiva_baja_a_la_acumulada(): void
    {
        $ctx = $this->laPlanillaDelLienzo();

        $this->elegir($ctx['year'], CierreDeLoNoCalificado::CERO);
        $antes = $this->filaDe($ctx, $ctx['alumno']);

        // **Antes de cerrar la definitiva YA es la parcial**, desde el 22 sep 2026. Este
        // renglon decia `ACUMULADA` y es el que cuenta la inversion entera: lo que
        // cambio no es este test, es de que numero parte el periodo abierto.
        $this->assertSame(self::PARCIAL, round((float) $antes->nota, 2));
        $this->assertSame(0.35, (float) $antes->cobertura);
        $this->assertSame(3, $this->casillasVacias($ctx));

        $this->cerrar($ctx['periodo'])->assertStatus(200);

        $this->assertSame(0, $this->casillasVacias($ctx),
            'Se cerro con «pasa a cero» y quedaron casillas vacias: la decision no se aplico.');

        $despues = $this->filaDe($ctx, $ctx['alumno']);

        // **Y AHORA SI SE MUEVE, Y HACIA ABAJO.** Es el efecto que `cero` tiene desde que
        // la definitiva normaliza: escribir los ceros mete las tres casillas en el
        // divisor, la cobertura pasa a 1 y la nota cae de la parcial a la acumulada. Antes
        // de la inversion este mismo test afirmaba lo contrario --«la definitiva no se
        // mueve»-- y era cierto porque `SUM(peso x NULL)` y `SUM(peso x 0)` daban lo
        // mismo. Que la eleccion del rector tenga una consecuencia observable es lo que
        // hace que D3 no sea un adorno.
        $this->assertSame(self::ACUMULADA, round((float) $despues->nota, 2),
            'La definitiva no bajo al escribir los ceros. Tiene que bajar: con las tres '
            .'casillas puestas a 0 el divisor pasa a ser el periodo entero, que es '
            .'exactamente lo que el rector eligio al cerrar con «pasa a cero».');

        $this->assertSame(1.0, (float) $despues->cobertura,
            'La cobertura sigue por debajo de 1 con todo escrito: entonces el semaforo del '
            .'periodo cerrado se queda gris para siempre, que es el hueco que esta fase mata.');

        $this->assertSame(self::ACUMULADA, round((float) $despues->parcial, 2),
            'La parcial y la definitiva siguen diciendo cosas distintas en un periodo '
            .'cerrado: la familia veria un numero y el boletin otro.');
    }

    /**
     * **`cero` sin el «sí» de quien cierra no escribe ceros**: el periodo queda `fuera`.
     */
    #[Test]
    public function cerrar_con_cero_sin_confirmar_no_escribe_ceros_y_queda_fuera(): void
    {
        $ctx = $this->laPlanillaDelLienzo();

        $this->elegir($ctx['year'], CierreDeLoNoCalificado::CERO);
        $this->assertSame(3, $this->casillasVacias($ctx));

        $this->cerrar($ctx['periodo'], false, false)->assertStatus(200);

        $this->assertSame(3, $this->casillasVacias($ctx),
            'Se cerro sin confirmar los ceros y las casillas vacias pasaron a 0. Es lo que '
            .'paso en quibdo el 24 sep 2026: 3.094 casillas que los docentes habian vaciado.');

        $this->assertSame(CierreDeLoNoCalificado::FUERA,
            DB::table('periodos')->where('id', $ctx['periodo'])->value('cierre_sin_calificar'),
            'Sin ceros escritos el periodo tiene que quedar marcado `fuera`: con `cero` la '
            .'definitiva de un periodo cerrado no normaliza y las vacias contarian como 0.');

        $this->assertSame(self::PARCIAL, round((float) $this->filaDe($ctx, $ctx['alumno'])->nota, 2));
    }

    /**
     * **`fuera`: las casillas se quedan vacias y la definitiva sigue siendo la parcial.**
     *
     * **Este test cambio de sentido el 22 sep 2026 sin cambiar una sola asercion sobre el
     * resultado.** Antes probaba que `fuera` MOVIA la definitiva --de la acumulada a la
     * parcial-- porque el resto del tiempo no normalizaba; ahora prueba que NO la mueve,
     * porque ya era la parcial desde que se abrio el periodo. El numero que se comprueba
     * es el mismo; lo que cambio es de donde viene.
     *
     * Sigue haciendo falta: es el que dice que `fuera` **no escribe ceros**, que es lo
     * unico que lo separa de `cero` ahora que la formula es la misma en los dos.
     *
     * **Se mira `notas_finales`, que es lo que imprime el boletin**, y no solo lo que
     * devuelve el calculo: la mitad cara de esta salida es que el cierre **reescribe** las
     * definitivas del periodo, porque despues de cerrar ya no lo hace nadie
     * (`ponerAlDiaUnInforme` no escribe en un periodo cerrado, decision del 17 sep).
     */
    #[Test]
    public function cerrar_con_fuera_no_mueve_la_definitiva_porque_ya_era_la_parcial(): void
    {
        $ctx = $this->laPlanillaDelLienzo();

        DefinitivasDeAsignatura::recalcular($ctx['asignatura'], $ctx['periodo']);

        $this->assertSame(self::PARCIAL, round((float) $this->definitivaGuardada($ctx), 2),
            'De partida la definitiva guardada tiene que ser la parcial: con el periodo '
            .'abierto lo no calificado no cuenta, y eso ya no depende de ninguna eleccion.');

        $this->elegir($ctx['year'], CierreDeLoNoCalificado::FUERA);
        $this->cerrar($ctx['periodo'])->assertStatus(200);

        $this->assertSame(3, $this->casillasVacias($ctx),
            'Se cerro con «queda fuera de la cuenta» y se escribieron ceros igual.');

        $this->assertSame(self::PARCIAL, round((float) $this->filaDe($ctx, $ctx['alumno'])->nota, 2),
            'El calculo dejo de dar la parcial en un periodo cerrado con «fuera».');

        $this->assertSame(self::PARCIAL, round((float) $this->definitivaGuardada($ctx), 2),
            'El calculo da la parcial y `notas_finales` se quedo con otra cosa. El boletin '
            .'imprime la tabla, asi que la decision del rector no llegaria al papel -- y '
            .'nadie la va a reescribir despues: con el periodo cerrado, ponerAlDiaUnInforme '
            .'no escribe.');
    }

    /**
     * **Cerrar con `cero` reescribe la definitiva GUARDADA, y reabrir la devuelve.**
     *
     * El test de arriba de `cero` mira `calcular()`, o sea la fórmula; éste mira
     * `notas_finales`, que es lo que imprime el boletín. Hasta el 25 sep 2026 la rama
     * `cero` escribía los ceros y no recalculaba —su comentario decía que la
     * definitiva no se movía, cierto antes del 22 sep—, así que la tabla se quedaba
     * con la parcial y el cálculo daba la acumulada. Con el periodo cerrado nadie la
     * reescribe después: `ponerAlDiaUnInforme` no escribe en periodos cerrados.
     */
    #[Test]
    public function cerrar_con_cero_reescribe_la_guardada_y_reabrir_la_devuelve(): void
    {
        $ctx = $this->laPlanillaDelLienzo();

        DefinitivasDeAsignatura::recalcular($ctx['asignatura'], $ctx['periodo']);
        $this->assertSame(self::PARCIAL, round((float) $this->definitivaGuardada($ctx), 2));

        $this->elegir($ctx['year'], CierreDeLoNoCalificado::CERO);
        $this->cerrar($ctx['periodo'])->assertStatus(200);

        $this->assertSame(self::ACUMULADA, round((float) $this->definitivaGuardada($ctx), 2),
            'Se cerro con «pasa a cero», el calculo da la acumulada y la tabla se quedo con '
            .'la parcial: el boletin imprimiria la nota de antes del cierre.');

        $this->cerrar($ctx['periodo'], true)->assertStatus(200);

        $this->assertSame(
            round((float) $this->filaDe($ctx, $ctx['alumno'])->nota, 2),
            round((float) $this->definitivaGuardada($ctx), 2),
            'Se reabrio un periodo cerrado en «cero» y la tabla no siguio al calculo: '
            .'abierto vuelve a dividir por lo evaluado.');
    }

    /**
     * **`bloquear`: 422, el periodo sigue abierto y no se escribió nada.**
     *
     * Se mira **la fila y las casillas**, no el código HTTP: un 422 después de haber
     * cerrado sería el peor de los dos mundos —el periodo cerrado y quien lo pulsó
     * creyendo que no—, y es exactamente la familia de
     * `tools/respuestas-que-mienten.py` por la otra punta.
     */
    #[Test]
    public function bloquear_no_cierra_y_no_escribe_nada(): void
    {
        $ctx = $this->laPlanillaDelLienzo();

        $this->elegir($ctx['year'], CierreDeLoNoCalificado::BLOQUEAR);

        $r = $this->cerrar($ctx['periodo']);

        $r->assertStatus(422);
        $this->assertStringContainsString('3', $r->getContent(),
            'El mensaje no lleva dentro cuántas faltan, así que no se puede ir a buscarlas.');

        $periodo = DB::table('periodos')->where('id', $ctx['periodo'])->first();

        $this->assertSame(1, (int) $periodo->profes_pueden_editar_notas,
            'Contestó 422 y cerró igual, que es la peor de las dos formas de fallar.');
        $this->assertNull($periodo->cierre_sin_calificar,
            'Se congeló una marca de un cierre que no ocurrió.');
        $this->assertSame(3, $this->casillasVacias($ctx));
    }

    /**
     * `bloquear` **sí deja cerrar** cuando ya no queda nada sin calificar.
     *
     * Es la otra mitad y sin ella el caso de arriba no distingue *«bloquea porque
     * faltan»* de *«bloquea siempre»*, que es un colegio que no puede cerrar nunca.
     *
     * Y se congela como `cero`, que es lo que describe el resultado: no quedó ninguna
     * fuera de la cuenta.
     */
    #[Test]
    public function bloquear_deja_cerrar_cuando_no_queda_nada_sin_calificar(): void
    {
        $ctx = $this->laPlanillaDelLienzo();

        $this->elegir($ctx['year'], CierreDeLoNoCalificado::BLOQUEAR);

        DB::table('notas')->whereIn('id', $ctx['notas'])->whereNull('nota')->update(['nota' => 0]);

        $this->assertSame(0, $this->casillasVacias($ctx));

        $this->cerrar($ctx['periodo'])->assertStatus(200);

        $periodo = DB::table('periodos')->where('id', $ctx['periodo'])->first();

        $this->assertSame(0, (int) $periodo->profes_pueden_editar_notas);
        $this->assertSame(CierreDeLoNoCalificado::CERO, $periodo->cierre_sin_calificar,
            'Se congeló `bloquear`, que no es un estado en el que un periodo pueda quedarse.');
    }

    // ── La regla dura: un periodo cerrado no se mueve ────────────────────────

    /**
     * **Cambiar la elección del rector NO mueve un periodo ya cerrado.**
     *
     * Éste es el caso que justifica que haya dos columnas y no una, y el único que se
     * pone rojo si el cálculo lee `years.cierre_sin_calificar` en vez de
     * `periodos.cierre_sin_calificar`. Con una sola columna, un rector que cambiara de
     * opinión en octubre movería las definitivas de los tres periodos que ya tiene
     * cerrados e impresos, **sin tocar una nota y sin un solo error en ningún log**.
     */
    #[Test]
    public function cambiar_la_eleccion_no_mueve_un_periodo_ya_cerrado(): void
    {
        $ctx = $this->laPlanillaDelLienzo();

        // **Se cierra con `fuera` y se cambia a `cero`, y no al revés.** Esa dirección
        // es la única que este caso puede medir: cerrando con `cero` la definitiva vale
        // 16,66 **y seguiría valiendo 16,66 aunque la fase entera no existiera**, así que
        // el verde no diría nada. Medido: con la normalización desactivada a mano, la
        // versión al revés de este caso seguía en verde. Escrito así, la primera línea
        // exige 47,60 y con el mecanismo apagado el caso cae en la primera aserción.
        //
        // Y además es la dirección que duele: un periodo cerrado con «queda fuera de la
        // cuenta» tiene los boletines impresos con la nota normalizada, y volver a la
        // acumulada le baja la nota a todo el grupo.
        $this->elegir($ctx['year'], CierreDeLoNoCalificado::FUERA);
        $this->cerrar($ctx['periodo'])->assertStatus(200);

        $guardada = (float) $this->definitivaGuardada($ctx);

        $this->assertSame(self::PARCIAL, $guardada,
            'El cierre con «fuera» no dejó la definitiva normalizada, así que este caso no '
            .'tiene nada que proteger.');

        // El rector cambia de opinión. El periodo ya está cerrado e impreso.
        $this->elegir($ctx['year'], CierreDeLoNoCalificado::CERO);

        $this->assertSame(self::PARCIAL, (float) $this->filaDe($ctx, $ctx['alumno'])->nota,
            'El cálculo de un periodo CERRADO cambió al cambiar la elección del año. Eso es '
            .'un boletín firmado moviéndose sin que nadie toque una nota, y es exactamente '
            .'lo que pasaría si el cálculo leyera `years` en vez de `periodos`.');

        DefinitivasDeAsignatura::recalcular($ctx['asignatura'], $ctx['periodo']);

        $this->assertSame($guardada, (float) $this->definitivaGuardada($ctx),
            'Un recálculo posterior reescribió la definitiva de un periodo cerrado con la '
            .'política nueva.');

        $this->assertSame(3, $this->casillasVacias($ctx),
            'Cambiar la elección del año le escribió ceros a las casillas de un periodo que '
            .'ya estaba cerrado.');
    }

    /**
     * **Cerrar con `cero` es IRREVERSIBLE, y eso hay que saberlo antes de pulsar.**
     *
     * Salió al escribir el control del caso de arriba y no estaba en el plan. Las dos
     * salidas no son simétricas:
     *
     * - `fuera` **conserva la información**: las casillas siguen vacías, así que
     *   reabrir y cerrar con `cero` todavía puede ponerles el 0.
     * - `cero` **la destruye**: escribe un 0 real, y a partir de ahí *«nadie lo
     *   calificó»* y *«sacó cero»* vuelven a ser indistinguibles — que es el fallo
     *   entero que la fase 0 vino a quitar. Reabrir y cerrar con `fuera` ya no devuelve
     *   47,60: devuelve 16,66, porque no queda nada fuera de la cuenta.
     *
     * No se arregla —hacerlo reversible pediría guardar qué casillas se cerraron a cero,
     * o sea la columna `calificada_at` que D6 descartó— y **no es un fallo**: es lo que
     * significa cerrar. Lo que sí hace falta es que esté escrito, porque la pantalla que
     * pregunte *«¿seguro?»* tiene que poder decir por qué.
     */
    #[Test]
    public function cerrar_con_cero_es_irreversible_y_reabrir_no_lo_deshace(): void
    {
        $ctx = $this->laPlanillaDelLienzo();

        $this->elegir($ctx['year'], CierreDeLoNoCalificado::CERO);
        $this->cerrar($ctx['periodo'])->assertStatus(200);

        $this->assertSame(0, $this->casillasVacias($ctx));

        $this->cerrar($ctx['periodo'], true)->assertStatus(200);
        $this->elegir($ctx['year'], CierreDeLoNoCalificado::FUERA);
        $this->cerrar($ctx['periodo'])->assertStatus(200);

        $this->assertSame(self::ACUMULADA, (float) $this->definitivaGuardada($ctx),
            'Reabrir y volver a cerrar con «fuera» devolvió la nota normalizada. No puede: '
            .'los ceros ya están escritos y son indistinguibles de un cero del docente. Si '
            .'esto está rojo, alguien guardó en alguna parte qué casillas cerró a cero — y '
            .'eso es la columna `calificada_at` que D6 descartó, entrando por la ventana.');
    }

    /**
     * **Un periodo cerrado ANTES de que esto existiera no se toca al volver a pulsar.**
     *
     * Son todos los periodos de los dieciséis colegios el día del despliegue. La
     * tentación es «ponerles los ceros, que de todas formas valían cero»: sería
     * correcto aritméticamente y movería `updated_by` y `updated_at` de un millón de
     * filas de periodos cuyas definitivas están impresas.
     */
    #[Test]
    public function volver_a_cerrar_un_periodo_sin_marca_no_toca_nada(): void
    {
        $ctx = $this->laPlanillaDelLienzo();

        // El estado exacto del día del despliegue: cerrado, sin marca, con casillas
        // vacías dentro. Se pone a mano porque es lo que no se puede reproducir
        // cerrando: el cierre nuevo siempre marca.
        DB::table('periodos')->where('id', $ctx['periodo'])
            ->update(['profes_pueden_editar_notas' => 0, 'cierre_sin_calificar' => null]);

        $this->elegir($ctx['year'], CierreDeLoNoCalificado::CERO);

        $this->cerrar($ctx['periodo'])->assertStatus(200);

        $this->assertSame(3, $this->casillasVacias($ctx),
            'Volver a pulsar «cerrar» sobre un periodo que ya estaba cerrado antes de esta '
            .'fase le escribió ceros. Ese periodo tiene el boletín impreso.');

        $this->assertNull(DB::table('periodos')->where('id', $ctx['periodo'])
            ->value('cierre_sin_calificar'),
            'Se le puso marca a un periodo que no se cerró por este camino.');
    }

    /**
     * **Reabrir no cambia el cálculo, y no hace falta borrar la marca.**
     *
     * Este caso también se dio la vuelta el 22 sep 2026. Comprobaba que la reapertura
     * **deshacía** la normalización —`normalizaLaDefinitiva()` exigía cerrado *y*
     * marcado—; ahora normalizar es lo normal, así que abrir y cerrar con `fuera` dan
     * el mismo número y lo que se comprueba es que **no salta** a la acumulada.
     *
     * La marca se conserva a propósito: es el registro de cómo se cerró la vez
     * anterior, y volver a cerrar vuelve a elegir. Eso no ha cambiado, y es lo que
     * impide que reabrir un periodo cerrado con `cero` lo deje con los ceros puestos y
     * sin decir por qué.
     */
    #[Test]
    public function reabrir_no_cambia_el_calculo_y_no_borra_la_marca(): void
    {
        $ctx = $this->laPlanillaDelLienzo();

        $this->elegir($ctx['year'], CierreDeLoNoCalificado::FUERA);
        $this->cerrar($ctx['periodo'])->assertStatus(200);

        $this->assertSame(self::PARCIAL, (float) $this->filaDe($ctx, $ctx['alumno'])->nota);

        $this->cerrar($ctx['periodo'], true)->assertStatus(200);

        $this->assertSame(self::PARCIAL, (float) $this->filaDe($ctx, $ctx['alumno'])->nota,
            'Con el periodo REABIERTO la definitiva dejó de normalizar y volvió a la suma '
            .'cruda. Ya no hay ningún estado en que lo no calificado pese como un cero, '
            .'salvo el cierre que lo pidió con «pasa a cero».');

        $this->assertSame(CierreDeLoNoCalificado::FUERA,
            DB::table('periodos')->where('id', $ctx['periodo'])->value('cierre_sin_calificar'),
            'Reabrir borró la marca. No hace falta y se conserva: es el registro de cómo se '
            .'cerró, y lo que desactiva el efecto es que el periodo esté abierto.');
    }

    // ── Las puertas de al lado ──────────────────────────────────────────────

    /**
     * **El recálculo por grupo escribe la misma cuenta, y por eso ya no hace falta
     * cerrarle la puerta.**
     *
     * `definitivas_periodos/calcular-grupo-periodo` es otro escritor de `notas_finales`
     * con **su propia consulta**. Hasta el 22 sep 2026 esa consulta era la acumulada a
     * pelo, así que pulsar el botón después de cerrar con `fuera` habría borrado las
     * definitivas normalizadas y escrito las otras **en silencio y con 200**: el mismo
     * número por dos botones distintos, que es la §3.4 del 10. El corte era un 422.
     *
     * Invertida la regla, ese 422 habría saltado en **todos** los periodos abiertos de
     * los dieciséis colegios —el botón, muerto—, así que se le enseñó la cuenta. Lo que
     * este caso comprueba ahora es lo mismo que comprobaba entonces, por el otro lado:
     * que después de pulsarlo la fila **no cambia**.
     *
     * Se mira la tabla y no sólo el código HTTP, por lo de siempre: este método empieza
     * por un `DELETE`, así que «no la cambió» incluye «no la dejó sin escribir».
     */
    #[Test]
    public function el_recalculo_por_grupo_escribe_la_misma_cuenta_que_el_cierre(): void
    {
        $ctx = $this->laPlanillaDelLienzo();

        $this->elegir($ctx['year'], CierreDeLoNoCalificado::FUERA);
        $this->cerrar($ctx['periodo'])->assertStatus(200);

        $this->assertSame(self::PARCIAL, (float) $this->definitivaGuardada($ctx));

        $r = $this->withToken($this->tokenDelSuperusuario())
            ->putJson('/api/definitivas_periodos/calcular-grupo-periodo', [
                'grupo_id' => $ctx['grupo'],
                'periodo_id' => $ctx['periodo'],
                'num_periodo' => $ctx['num_periodo'],
            ]);

        $r->assertStatus(200);

        $this->assertSame(self::PARCIAL, (float) $this->definitivaGuardada($ctx),
            'El botón de recalcular el grupo dejó otra nota que la que dejó el cierre. Es la '
            .'§3.4 del 10: el mismo número producido por dos botones distintos, y el alumno '
            .'cambiando según cuál se pulse. O peor, borró la fila: ese método empieza por '
            .'un DELETE.');
    }

    /**
     * **El genérico de `years` no puede escribir esta columna.**
     *
     * `PUT years/toggle-cambiar-valor` escribe cualquier columna de `years` que exista
     * con sólo `auth.personal`, así que sin un corte explícito el permiso de abajo vale
     * exactamente hasta que alguien mande `{campo: "cierre_sin_calificar"}`.
     *
     * Se prueba con un **superusuario**, que es el sujeto más fuerte posible: si ni él
     * la escribe por ahí, nadie la escribe.
     */
    #[Test]
    public function el_generico_de_years_no_puede_escribir_la_columna(): void
    {
        $usuario = $this->usuarioDeTipo('Usuario');

        $this->assertSame(1, (int) $usuario->is_superuser,
            'Este caso necesita el sujeto MÁS fuerte: con uno llano, el corte podría venir '
            .'del permiso y no de la lista de columnas.');

        $yearId = $this->anioActual();
        $antes = DB::table('years')->where('id', $yearId)->value('cierre_sin_calificar');

        $r = $this->withToken($this->tokenDe($usuario->username))
            ->putJson('/api/years/toggle-cambiar-valor', [
                'year_id' => $yearId,
                'campo' => 'cierre_sin_calificar',
                'valor' => CierreDeLoNoCalificado::FUERA,
            ]);

        $r->assertStatus(422);
        $this->assertSame($antes, DB::table('years')->where('id', $yearId)->value('cierre_sin_calificar'),
            'El genérico contestó 422 y escribió igual.');
        $this->assertStringContainsString('years/cierre-sin-calificar', $r->getContent(),
            'El mensaje no dice a qué ruta ir, y el rastro de eso es una persona probando un '
            .'endpoint que no era y concluyendo que no tiene permiso.');
    }

    // ── El permiso ──────────────────────────────────────────────────────────

    /**
     * **Un docente llano no elige qué pasa al cerrar.**
     *
     * El guard de la ruta es `auth.personal`, que deja pasar a las 74 cuentas de
     * personal —**53 son docentes**—; lo que para a un profesor es
     * `Autoriza::puedeElegirQuePasaAlCerrar` DENTRO del método. Sin este caso, quitarlo
     * del controlador no pondría **nada** en rojo.
     *
     * Y el sujeto es el que hace que la decisión importe: el docente que no calificó es
     * quien se beneficia de `fuera`.
     */
    #[Test]
    public function un_docente_llano_no_elige_que_pasa_al_cerrar(): void
    {
        $usuario = $this->usuarioLlanoDelPersonal();

        $this->assertSame(0, (int) $usuario->is_superuser,
            'El sujeto de este caso NO puede ser superusuario: con la columna puesta, el 403 '
            .'no diría nada sobre el permiso.');

        $yearId = $this->anioActual();
        $antes = DB::table('years')->where('id', $yearId)->value('cierre_sin_calificar');

        $r = $this->withToken($this->tokenDe($usuario->username))
            ->putJson(self::RUTA_ELEGIR, ['year_id' => $yearId, 'valor' => CierreDeLoNoCalificado::FUERA]);

        $r->assertStatus(403);
        $this->assertSame($antes, DB::table('years')->where('id', $yearId)->value('cierre_sin_calificar'),
            'Contestó 403 y escribió igual: el criterio frena la respuesta pero no la escritura.');
    }

    /**
     * **El mismo usuario con el rol de rectoría sí entra.**
     *
     * Con un superusuario el verde no diría nada: pasa por encima del permiso. El rol
     * se da **por `role_user`**, que es como llega de verdad al contexto, para que lo
     * único que cambie entre el 403 y el 200 sea esa fila.
     */
    #[Test]
    public function un_rector_sin_superusuario_si_elige(): void
    {
        $usuario = $this->usuarioLlanoDelPersonal();

        $this->assertSame(0, (int) $usuario->is_superuser);

        $rolId = DB::table('roles')->where('name', 'Rector')->whereNull('deleted_at')->value('id')
            ?? DB::table('roles')->insertGetId([
                'name' => 'Rector',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        DB::table('role_user')->insert(['user_id' => $usuario->id, 'role_id' => $rolId]);

        $yearId = $this->anioActual();

        $r = $this->withToken($this->tokenDe($usuario->username))
            ->putJson(self::RUTA_ELEGIR, ['year_id' => $yearId, 'valor' => CierreDeLoNoCalificado::FUERA]);

        $r->assertStatus(200);
        $r->assertJson(['year_id' => $yearId, 'cierre_sin_calificar' => CierreDeLoNoCalificado::FUERA]);

        $this->assertSame(CierreDeLoNoCalificado::FUERA,
            DB::table('years')->where('id', $yearId)->value('cierre_sin_calificar'));
    }

    /** Una salida que no existe es 422 y no deja la fila a medias. */
    #[Test]
    public function una_salida_que_no_existe_es_422_y_no_escribe(): void
    {
        $yearId = $this->anioActual();
        $antes = DB::table('years')->where('id', $yearId)->value('cierre_sin_calificar');

        $this->withToken($this->tokenDelSuperusuario())
            ->putJson(self::RUTA_ELEGIR, ['year_id' => $yearId, 'valor' => 'ponerlos_en_50'])
            ->assertStatus(422);

        $this->assertSame($antes, DB::table('years')->where('id', $yearId)->value('cierre_sin_calificar'),
            'Una salida inventada entró en la fila. El enum de MySQL guardaría la cadena '
            .'vacía sin modo estricto, que es la familia de `frases_asignatura` cortando a '
            .'los 255.');
    }

    // ── El diálogo ──────────────────────────────────────────────────────────

    /**
     * **El diálogo dice cuántas faltan, de quién son y qué va a pasar con ellas.**
     *
     * Las tres cosas, y la tercera es la que evita el susto: la misma pantalla con las
     * mismas casillas significa *«se van a poner en cero»* en un colegio y *«no vas a
     * poder cerrar»* en el de al lado, y sin `salida` no hay forma de saber cuál. *Una
     * decisión que no se ve antes de aplicarse la acaba descubriendo quien pulsa.*
     */
    #[Test]
    public function el_dialogo_dice_cuantas_faltan_de_quien_son_y_que_va_a_pasar(): void
    {
        $ctx = $this->laPlanillaDelLienzo();

        $this->elegir($ctx['year'], CierreDeLoNoCalificado::FUERA);

        $r = $this->withToken($this->tokenDelSuperusuario())
            ->getJson('/api/periodos/sin-calificar/'.$ctx['periodo']);

        $r->assertStatus(200);
        $cuerpo = $r->json();

        $this->assertTrue($cuerpo['abierto']);
        $this->assertSame(CierreDeLoNoCalificado::FUERA, $cuerpo['salida']);
        $this->assertNull($cuerpo['congelado'], 'Sin cerrar todavía no hay nada congelado.');
        $this->assertSame(3, $cuerpo['casillas']);
        $this->assertNotSame('', $cuerpo['descripcion'],
            'Sin la frase, cada uno de los cuatro clientes se inventa la suya.');

        $mia = null;

        foreach ($cuerpo['asignaturas'] as $fila) {
            if ((int) $fila['asignatura_id'] === $ctx['asignatura']) {
                $mia = $fila;
            }
        }

        $this->assertNotNull($mia, 'La asignatura del lienzo no sale en el desglose.');
        $this->assertSame(3, (int) $mia['casillas']);
        $this->assertSame(1, (int) $mia['alumnos'],
            '`alumnos` cuenta personas y no casillas: es lo que mide el daño de poner ceros.');
        $this->assertArrayHasKey('nombres_profesor', $mia,
            'Sin el docente al lado, el desglose no dice de quién es el silencio, que es el '
            .'aviso que el colegio no tiene hoy.');
    }

    /**
     * Con el periodo ya cerrado, el diálogo dice **lo que se aplicó**, no lo que se
     * aplicaría. En un periodo cerrado esa pregunta ya tiene respuesta.
     */
    #[Test]
    public function con_el_periodo_cerrado_el_dialogo_dice_lo_que_se_aplico(): void
    {
        $ctx = $this->laPlanillaDelLienzo();

        $this->elegir($ctx['year'], CierreDeLoNoCalificado::FUERA);
        $this->cerrar($ctx['periodo'])->assertStatus(200);

        // El rector cambia de opinión después de cerrar. El diálogo del periodo cerrado
        // NO puede empezar a decir «cero»: ahí ya se aplicó `fuera`.
        $this->elegir($ctx['year'], CierreDeLoNoCalificado::CERO);

        $cuerpo = $this->withToken($this->tokenDelSuperusuario())
            ->getJson('/api/periodos/sin-calificar/'.$ctx['periodo'])->json();

        $this->assertFalse($cuerpo['abierto']);
        $this->assertSame(CierreDeLoNoCalificado::FUERA, $cuerpo['salida'],
            'El diálogo de un periodo cerrado está leyendo la elección vigente en vez de lo '
            .'que se aplicó, y eso hace que la pantalla describa mal un periodo impreso.');
        $this->assertSame(CierreDeLoNoCalificado::FUERA, $cuerpo['congelado']);
    }

    /** Un periodo que no existe es 404 y no una respuesta vacía. */
    #[Test]
    public function un_periodo_que_no_existe_es_404(): void
    {
        $this->withToken($this->tokenDelSuperusuario())
            ->getJson('/api/periodos/sin-calificar/99999999')
            ->assertStatus(404);
    }

    // ── El año nuevo ────────────────────────────────────────────────────────

    /**
     * **El año nuevo hereda la elección del anterior.**
     *
     * El centinela de las columnas del año nuevo mira el **fuente** —que la columna
     * esté nombrada— y una escrita como `Request::input('x')` lo pasa igual. Aquí se
     * mira el resultado. Sin la herencia, el colegio que eligió `fuera` amanecería en
     * `cero` y **el primer periodo que cerrara le pondría ceros a todo lo que sus
     * docentes no hubieran calificado**.
     */
    #[Test]
    public function el_anio_nuevo_hereda_la_eleccion(): void
    {
        // **El sujeto sale del último año VIVO, y eso no es un detalle del seed.**
        // `postStore` copia con `Year::where('year', $pedido - 1)->first()`, que lleva
        // `SoftDeletes`: un año en la papelera no se copia, y este caso saldría verde
        // sin haber heredado nada. Es la misma trampa que documenta
        // `ModeloDeEvaluacionDelAnioTest`.
        $pasado = DB::selectOne('SELECT * FROM years WHERE deleted_at IS NULL
            ORDER BY year DESC, id DESC LIMIT 1');

        $this->assertNotNull($pasado, 'No hay año vivo del que heredar.');

        $this->elegir((int) $pasado->id, CierreDeLoNoCalificado::FUERA);

        $r = $this->withToken($this->tokenDelSuperusuario())
            ->postJson('/api/years/store', [
                'year' => ((int) $pasado->year) + 1,
                'actual' => false,
                'nombre_colegio' => $pasado->nombre_colegio,
                'abrev_colegio' => $pasado->abrev_colegio,
                'nota_minima_aceptada' => $pasado->nota_minima_aceptada,
                'resolucion' => $pasado->resolucion,
                'codigo_dane' => $pasado->codigo_dane,
                'encabezado_certificado' => $pasado->encabezado_certificado,
                'telefono' => $pasado->telefono,
                'celular' => $pasado->celular,
                'unidad_displayname' => $pasado->unidad_displayname,
                'unidades_displayname' => $pasado->unidades_displayname,
                'genero_unidad' => $pasado->genero_unidad,
                'subunidad_displayname' => $pasado->subunidad_displayname,
                'subunidades_displayname' => $pasado->subunidades_displayname,
                'genero_subunidad' => $pasado->genero_subunidad,
                'website' => $pasado->website,
                'website_myvc' => $pasado->website_myvc,
                'alumnos_can_see_notas' => $pasado->alumnos_can_see_notas,
            ]);

        $r->assertStatus(200);

        $nuevo = DB::table('years')->where('id', $r->json('id'))->first();

        $this->assertSame(CierreDeLoNoCalificado::FUERA, $nuevo->cierre_sin_calificar,
            'El año nuevo nació con el defecto de la columna en vez de con la decisión del '
            .'colegio, y eso no se ve hasta el primer cierre de enero: el colegio que eligió '
            .'«fuera» le pondría ceros a todo lo que sus docentes no calificaron.');
    }

    // ── Andamiaje ───────────────────────────────────────────────────────────

    /**
     * Los valores del `enum` de una columna, leídos de **la base viva** y no del
     * volcado congelado.
     *
     * Por lo mismo que `CentinelaDeLasColumnasDelAnioNuevoTest`: el volcado no tiene
     * las columnas que entraron por migración, o sea que un centinela medido contra él
     * estaría mirando donde ninguna candidata puede aparecer y saldría verde para
     * siempre.
     *
     * Se pregunta a `information_schema` y no con `SHOW COLUMNS … LIKE ?`: ése **no
     * admite un parámetro ligado** y revienta con un `QueryException` —costó un rojo al
     * escribir este fichero—. Concatenar el nombre para esquivarlo sería meter una
     * cadena en el SQL por comodidad de un test.
     */
    private function valoresDelEnum(string $tabla, string $columna): array
    {
        $fila = DB::selectOne(
            'SELECT COLUMN_TYPE AS tipo FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$tabla, $columna]
        );

        $this->assertNotNull($fila, 'No existe '.$tabla.'.'.$columna.' en la base de tests.');

        preg_match_all("/'([^']*)'/", (string) $fila->tipo, $encontrados);

        return $encontrados[1];
    }

    /** Elige la salida del año con el token que puede. */
    private function elegir(int $yearId, string $valor): void
    {
        $this->withToken($this->tokenDelSuperusuario())
            ->putJson(self::RUTA_ELEGIR, ['year_id' => $yearId, 'valor' => $valor])
            ->assertStatus(200);
    }

    /**
     * Cierra —o reabre— el periodo por la ruta de verdad, que es donde se aplica. Por
     * defecto con el «sí» a los ceros, que es lo que estos casos modelan: un rector que
     * eligió `cero` y lo confirmó al cerrar.
     */
    private function cerrar(int $periodoId, bool $abrir = false, bool $ponerCeros = true)
    {
        return $this->withToken($this->tokenDelSuperusuario())
            ->putJson(self::RUTA_CERRAR, [
                'periodo_id' => $periodoId,
                'pueden' => $abrir ? 1 : 0,
                'poner_ceros' => $ponerCeros ? 1 : 0,
            ]);
    }

    private function tokenDelSuperusuario(): string
    {
        $usuario = $this->usuarioDeTipo('Usuario');

        $this->assertSame(1, (int) $usuario->is_superuser,
            'El sujeto de estos casos tiene que ser superusuario: sin él, un 403 del permiso '
            .'se leería como un fallo de la fase.');

        return $this->tokenDe($usuario->username);
    }

    private function anioActual(): int
    {
        $id = DB::table('years')->where('actual', 1)->whereNull('deleted_at')->value('id');

        $this->assertNotNull($id, 'El seed no tiene año actual.');

        return (int) $id;
    }

    /** Cuántas de las casillas DEL LIENZO siguen vacías. */
    private function casillasVacias(array $ctx): int
    {
        return DB::table('notas')->whereIn('id', $ctx['notas'])
            ->whereNull('deleted_at')->whereNull('nota')->count();
    }

    /** Lo que quedó GUARDADO, que es lo que imprime el boletín. */
    private function definitivaGuardada(array $ctx)
    {
        return DB::table('notas_finales')
            ->where('alumno_id', $ctx['alumno'])
            ->where('asignatura_id', $ctx['asignatura'])
            ->where('periodo_id', $ctx['periodo'])
            ->value('nota');
    }

    /**
     * La fila de `calcular()` de un alumno concreto.
     *
     * Se pregunta a `calcular()` y no a la tabla cuando lo que se mide es la fórmula:
     * la parcial y la cobertura no se guardan en ninguna columna.
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

    /** Una subunidad con su nota para un alumno. Devuelve el id de la nota. */
    private function casilla(int $unidadId, int $porcentaje, ?int $nota, int $alumnoId): int
    {
        $subunidadId = DB::table('subunidades')->insertGetId([
            'unidad_id' => $unidadId,
            'definicion' => 'INDICADOR AL '.$porcentaje.' %',
            'porcentaje' => $porcentaje,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (int) DB::table('notas')->insertGetId([
            'subunidad_id' => $subunidadId,
            'alumno_id' => $alumnoId,
            'nota' => $nota,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * La planilla del lienzo del doc 43, montada sobre una asignatura **vacía** y un
     * periodo **abierto**.
     *
     * Las dos condiciones se comprueban y ninguna sobra. Sin la primera, unas unidades
     * del seed entrarían en las mismas sumas y todos los números dejarían de ser los
     * del lienzo —en silencio, porque el cálculo seguiría siendo correcto—. Sin la
     * segunda, el cierre no sería una transición y no aplicaría nada: **el caso saldría
     * verde sin haber ejercitado una línea de esta fase**.
     *
     * @return array<string, mixed>
     */
    private function laPlanillaDelLienzo(): array
    {
        // El alcance del boletín independiente se resuelve una vez y se cachea; sin
        // olvidarlo, un caso anterior de la misma tanda decide por éste.
        BoletinIndependiente::olvidar();

        $donde = DB::selectOne(
            'SELECT a.id AS asignatura_id, a.grupo_id, p.id AS periodo_id, p.numero, g.year_id
               FROM asignaturas a
               INNER JOIN grupos g ON g.id = a.grupo_id AND g.deleted_at IS NULL
               INNER JOIN periodos p ON p.year_id = g.year_id AND p.deleted_at IS NULL
              WHERE a.deleted_at IS NULL
                AND (SELECT COUNT(DISTINCT m.alumno_id) FROM matriculas m
                      WHERE m.grupo_id = a.grupo_id AND m.deleted_at IS NULL) >= 2
                AND NOT EXISTS (SELECT 1 FROM unidades u
                                 WHERE u.asignatura_id = a.id AND u.periodo_id = p.id
                                   AND u.deleted_at IS NULL)
              ORDER BY a.id, p.id LIMIT 1'
        );

        $this->assertNotNull($donde,
            'El seed no tiene una asignatura con dos matriculados y sin unidades en algún '
            .'periodo: sin un par limpio, las sumas de este fichero no son las del lienzo.');

        // **El periodo se deja abierto explícitamente y no se da por hecho.** Un seed que
        // lo trajera cerrado dejaría todos los casos de cierre sin transición que
        // ejercitar, y en verde.
        DB::table('periodos')->where('id', $donde->periodo_id)->update([
            'profes_pueden_editar_notas' => 1,
            'cierre_sin_calificar' => null,
        ]);

        // Y el periodo no puede estar ya marcado por otra vía: la marca es lo que decide
        // la fórmula, así que heredarla convertiría estos casos en otra cosa.
        $this->assertNull(
            CierreDeLoNoCalificado::estadoDelPeriodo((int) $donde->periodo_id)['congelado']
        );

        $alumnos = DB::select(
            'SELECT DISTINCT m.alumno_id FROM matriculas m
              WHERE m.grupo_id = ? AND m.deleted_at IS NULL ORDER BY m.alumno_id LIMIT 2',
            [$donde->grupo_id]
        );

        $alumno = (int) $alumnos[0]->alumno_id;

        // El reparto se deja explícito en `porcentaje` aunque sea el defecto: un defecto
        // heredado no se puede afirmar, y en modo promedio los pesos del lienzo cambian.
        DB::table('years')->where('id', $donde->year_id)
            ->update(['reparto_subunidades' => RepartoDeLaNota::PORCENTAJE]);

        $unidad1 = (int) DB::table('unidades')->insertGetId([
            'asignatura_id' => $donde->asignatura_id,
            'periodo_id' => $donde->periodo_id,
            'definicion' => 'UNIDAD 1 DEL LIENZO',
            'porcentaje' => 70,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $notas = [];

        foreach (self::UNIDAD_1 as $indicador) {
            $notas[] = $this->casilla($unidad1, $indicador['porcentaje'], $indicador['nota'], $alumno);
        }

        $unidad2 = (int) DB::table('unidades')->insertGetId([
            'asignatura_id' => $donde->asignatura_id,
            'periodo_id' => $donde->periodo_id,
            'definicion' => 'UNIDAD 2 DEL LIENZO',
            'porcentaje' => 30,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $notas[] = $this->casilla($unidad2, 100, null, $alumno);

        return [
            'asignatura' => (int) $donde->asignatura_id,
            'grupo' => (int) $donde->grupo_id,
            'periodo' => (int) $donde->periodo_id,
            'num_periodo' => (int) $donde->numero,
            'year' => (int) $donde->year_id,
            'alumno' => $alumno,
            'notas' => $notas,
        ];
    }
}
