<?php

namespace Tests\Contrato;

use App\Models\Year;
use App\Services\DefinitivasDeAsignatura;
use App\Support\Autoriza;
use App\Support\RepartoDeLaNota;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

/**
 * **El reparto de las subunidades** — Entrega 5 de
 * [28-competencias-e-indicadores.md](../../docs/migracion/28-competencias-e-indicadores.md)
 * §5.5, sobre el encargo de Joseth del 2 sep 2026: *«debería ser opcional que las
 * subunidades se manejen con porcentajes; que el colegio pueda elegir que sean
 * tratados con promedios»*.
 *
 * ## Por qué este fichero existe, y por qué llega el último
 *
 * La entrega se construyó entera —la columna, los dieciséis sitios, las dos
 * representaciones— **y se fundió sin un solo caso que encendiera el interruptor**.
 * Todo lo verde de esa rama corría en `porcentaje`, que es el defecto: o sea que
 * los 156 dirigidos que pasaron habrían pasado exactamente igual con
 * `RepartoDeLaNota::PROMEDIO` devolviendo la cadena vacía. **La afirmación entera
 * de la entrega no la sujetaba nada.**
 *
 * ## Las cuatro cosas que existe para cazar, y ninguna da error sola
 *
 * **1. Que los dos números se separen.** Es la afirmación de la entrega: la
 * definitiva que se GUARDA y los factores que la planilla SIRVE tienen que
 * reconstruir el mismo número. Si se separan, el docente ve una celda que suma una
 * cosa y un boletín que dice otra, **y las dos son creíbles**. Por eso el caso
 * central no comprueba «200» ni «la columna cambió»: hace la cuenta del cliente con
 * lo que el cliente recibe y la compara con lo que quedó escrito.
 *
 * **2. Que se convierta una representación y la otra no.** Es la mitad de la D30
 * que el controlador explica en prosa: las celdas viajan con `subunidad_porc`
 * —el factor— y el árbol de unidades con `subunidades[].porcentaje` —el crudo de la
 * tabla—, y **`myvc_flutter` multiplica por el del árbol**. Convertir sólo uno de
 * los dos es exactamente el fallo que la entrega viene a evitar, y no rompe nada
 * que se pueda ver desde aquí: los dos números existen y los dos son plausibles.
 *
 * **3. Que el interruptor escriba en la tabla.** D20 dice que
 * `subunidades.porcentaje` **no se toca**: lo que cambia es lo que se sirve. Es lo
 * que hace que apagarlo devuelva el reparto que había, y sin esto una entrega que
 * «funciona» puede haberse llevado por delante los porcentajes que el docente
 * tecleó — y eso no se deshace cambiando el enum.
 *
 * **4. Que la política se escriba por la puerta de al lado.** `reparto_subunidades`
 * es la **segunda** columna con dueño de `years`, y entró convirtiendo el `if` de
 * `toggle-cambiar-valor` en una lista. Sin un caso propio, quitar su renglón de esa
 * lista no pondría nada en rojo — que es literalmente el control que la sesión
 * anterior escribió para su hermana y no escribió para ésta.
 *
 * ## El montaje: cuatro pesos desiguales, y por eso discrimina
 *
 * Una unidad al 100 % con **cuatro subunidades al 40/30/20/10** y notas
 * **40/40/20/20**. Los dos modos dan un entero y **dan enteros distintos**:
 *
 *     porcentaje   40×0,4 + 40×0,3 + 20×0,2 + 20×0,1  =  16+12+4+2  =  34
 *     promedio     (40+40+20+20) / 4                  =  120/4      =  30
 *
 * Las dos mitades son deliberadas. **Desiguales**, porque con cuatro subunidades al
 * 25 % los dos modos dan el mismo número y el caso pasaría en verde con el
 * interruptor desconectado — que es la forma de aprobado que este repo tiene
 * fichada. Y **enteros los dos**, para que la cuenta se pueda seguir de cabeza y el
 * aserto diga 30 y no 29,9999.
 *
 * > **Ese «enteros» se escribió con un motivo que era falso, y se deja dicho en vez
 * > de borrarse.** Decía *«porque `notas_finales.nota` es `int`»*, copiado de
 * > `EditarUnaNotaActualizaLaDefinitivaTest:128` **sin comprobarlo**. La columna es
 * > `decimal(7,4)` desde el 30 ago 2026 (`2026_08_30_200000_notas_finales_en_decimal`),
 * > o sea que llevaba dieciséis días sin ser verdad y se propagó a un fichero nuevo el
 * > día que alguien la reutilizó. **La elección sigue siendo buena y el motivo era
 * > otro**: con enteros la aritmética se lee, no se calcula. *Una frase heredada de un
 * > fichero vecino no viene comprobada: viene repetida.*
 *
 * El montaje **vacía primero la rejilla de esa asignatura**. Los porcentajes del
 * seed son cualesquiera y con ellos la cuenta deja de poder hacerse de cabeza, que
 * es justo lo que hace legible a un test de aritmética. Es la regla del 09: **si lo
 * que falta es la fila, se monta en el test que la necesita.**
 */
class RepartoDeLasSubunidadesTest extends CasoDeContrato
{
    /** Los cuatro pesos del montaje, en orden. Desiguales a propósito. */
    private const PESOS = [40, 30, 20, 10];

    /** Y las cuatro notas. Con los pesos de arriba: 34 en porcentaje, 30 en promedio. */
    private const NOTAS = [40, 40, 20, 20];

    private const EN_PORCENTAJE = 34.0;

    private const EN_PROMEDIO = 30.0;

    // ── Lo que el colegio tiene hoy ──────────────────────────────────────────

    /**
     * El `enum` de la base y la lista del modelo no pueden separarse.
     *
     * **Este caso existe porque el docblock que lo promete se escribió antes que
     * él.** `Year::REPARTOS_DE_SUBUNIDADES` dice *«un test comprueba que dice lo
     * mismo que el `enum` de la columna, por lo mismo que su hermana»*, y
     * `YearsController` repite la promesa en plural —*«un test comprueba que dicen
     * lo mismo que las columnas»*— cuando lo único comprobado era
     * `modelo_evaluacion`. Una promesa escrita al lado del código **se lee como una
     * garantía**, así que o se cumple o se borra.
     *
     * Lo que taparía: los dos existen, dicen cosas distintas y no falla nada hasta
     * que alguien guarda el valor que sólo conoce uno de los dos. El síntoma sería
     * un 422 para un reparto que la base acepta, o —peor— la **cadena vacía**
     * guardada sin modo estricto, que es la familia de `frases_asignatura` cortando
     * a los 255.
     */
    #[Test]
    public function la_lista_del_reparto_y_la_de_la_base_son_la_misma(): void
    {
        $tipo = (string) DB::selectOne('SHOW COLUMNS FROM years LIKE "reparto_subunidades"')->Type;

        preg_match_all("/'([^']+)'/", $tipo, $coincidencias);

        $this->assertSame(Year::REPARTOS_DE_SUBUNIDADES, $coincidencias[1],
            "El `enum` de la base dice `{$tipo}` y `Year::REPARTOS_DE_SUBUNIDADES` dice otra cosa.");

        // Y la tercera pata: lo que la clase de cálculo entiende por «los dos modos».
        // Si `RepartoDeLaNota` conociera un valor que la columna no acepta, el modo
        // se guardaría y no se podría encender nunca.
        $this->assertSame(Year::REPARTOS_DE_SUBUNIDADES,
            [RepartoDeLaNota::PORCENTAJE, RepartoDeLaNota::PROMEDIO],
            'La columna y `RepartoDeLaNota` no hablan del mismo par de modos.');
    }

    /**
     * Los dieciséis amanecen en `porcentaje`, que es el comportamiento de hoy.
     *
     * **Y la población va delante del veredicto**: un «ninguno está en promedio» no
     * distingue «los revisé todos» de «no leí ninguno». Es lo que hace que esta
     * entrega se pueda desplegar a los dieciséis sin avisar a nadie — la migración
     * es aditiva y con `DEFAULT`, así que nadie amanece con las notas movidas.
     */
    #[Test]
    public function todos_los_anios_nacen_en_porcentaje(): void
    {
        $anios = DB::select('SELECT id, reparto_subunidades FROM years WHERE deleted_at IS NULL');

        $this->assertGreaterThan(0, count($anios),
            'No se leyó ningún año: este caso no estaría comprobando nada.');

        foreach ($anios as $anio) {
            $this->assertSame('porcentaje', $anio->reparto_subunidades,
                "El año {$anio->id} nació con `reparto_subunidades` = '{$anio->reparto_subunidades}'.\n"
                .'La migración es aditiva y con DEFAULT: nadie ha tocado esto todavía.');
        }
    }

    // ── El caso central: los dos números no se separan ───────────────────────

    /**
     * **La afirmación entera de la Entrega 5**: lo que se guarda y lo que se sirve
     * reconstruyen el mismo número.
     *
     * El caso hace las dos mitades del viaje:
     *
     *  1. **En `porcentaje` sale 34.** No es decoración: es lo que demuestra que el
     *     montaje distingue los dos modos. Sin este aserto, un 30 en la segunda
     *     mitad podría venir de que el interruptor no hace nada y el seed da 30 por
     *     casualidad.
     *  2. **En `promedio` sale 30**, y la planilla sirve los factores con los que el
     *     cliente reconstruye **ese** 30 y no el 34.
     *
     * La reconstrucción se hace **como la hace el cliente** —`Σ (nota × factor de
     * subunidad × factor de unidad)`— y no como la hace el backend
     * —`(Σ nota)/n × %unidad`—, que es el otro camino. Los dos son la misma
     * expresión y en coma flotante pueden diferir en el último bit; el docblock de
     * `RepartoDeLaNota` lo deja escrito y manda que **ante la diferencia se corrija
     * la pantalla, nunca al revés**. De ahí la tolerancia: media centésima tolera el
     * polvo de los `double` y **no tolera** que uno de los dos lados se haya quedado
     * en el otro modo, que son cuatro puntos de diferencia.
     */
    #[Test]
    public function la_definitiva_guardada_y_la_planilla_dan_el_mismo_numero(): void
    {
        $ctx = $this->asignaturaDeCuatroPesos();

        // 1. El defecto: cada subunidad pesa lo que dice su columna.
        $this->ponerElReparto($ctx, RepartoDeLaNota::PORCENTAJE);
        $this->recalcular($ctx);

        $this->assertSame(self::EN_PORCENTAJE, $this->definitivaDe($ctx),
            'En `porcentaje` la cuenta de siempre no da 34: el montaje no está midiendo lo que '
            .'cree, y el 30 del promedio no demostraría nada.');

        // 2. Encendido: las cuatro pesan igual.
        $this->ponerElReparto($ctx, RepartoDeLaNota::PROMEDIO);
        $this->recalcular($ctx);

        $this->assertSame(self::EN_PROMEDIO, $this->definitivaDe($ctx),
            'La definitiva guardada siguió repartiendo por porcentaje con el año en `promedio`.');

        // 3. Y la planilla sirve los factores de ESE número.
        $celdas = $this->celdasDe($this->planilla($ctx), $ctx['alumno']);

        $this->assertCount(count(self::PESOS), $celdas,
            'La rejilla no devolvió las cuatro celdas del montaje: la suma de abajo no sería comparable.');

        $reconstruido = 0.0;

        foreach ($celdas as $celda) {
            $reconstruido += (float) $celda['nota']
                * (float) $celda['subunidad_porc']
                * (float) $celda['unidad_porc'];
        }

        $this->assertEqualsWithDelta(self::EN_PROMEDIO, $reconstruido, 0.005,
            'El cliente reconstruye '.$reconstruido.' y la base guardó '.$this->definitivaDe($ctx).".\n"
            .'Son los dos caminos de la misma cuenta: una diferencia de este tamaño no es el último '
            .'bit de un `double`, es que uno de los dos lados se quedó en el otro modo.');
    }

    /**
     * **La segunda mitad de la D30: el árbol también miente si no se convierte.**
     *
     * Las celdas viajan con `subunidad_porc` y el árbol de unidades con
     * `subunidades[].porcentaje`, y **no todos los clientes leen el mismo**:
     * `myvc_flutter` multiplica por el del árbol. Servir uno convertido y el otro
     * crudo deja la planilla sumando ponderado mientras la definitiva guarda la
     * media — dos números creíbles y ninguna forma de saber cuál.
     *
     * Se comprueban **las tres representaciones a la vez** porque el fallo es
     * justamente que se separen: el factor de la celda, el rótulo de la celda y el
     * porcentaje del árbol.
     */
    #[Test]
    public function el_arbol_y_las_celdas_viajan_con_el_mismo_reparto(): void
    {
        $ctx = $this->asignaturaDeCuatroPesos();
        $this->ponerElReparto($ctx, RepartoDeLaNota::PROMEDIO);

        $json = $this->planilla($ctx);

        $esperado = round(100 / count(self::PESOS), 2);   // 25.0

        foreach ($this->celdasDe($json, $ctx['alumno']) as $celda) {
            $this->assertEqualsWithDelta(1 / count(self::PESOS), (float) $celda['subunidad_porc'], 0.0001,
                'El factor de la celda sigue siendo el de la columna.');

            $this->assertEqualsWithDelta($esperado, (float) $celda['subunidad_porcentaje'], 0.005,
                'El rótulo de la celda pinta un porcentaje que ya no gobierna nada — y eso es peor '
                .'que un número raro, porque es creíble.');
        }

        $subunidades = $this->subunidadesDelArbol($json);

        $this->assertCount(count(self::PESOS), $subunidades,
            'El árbol no trae las cuatro subunidades del montaje.');

        foreach ($subunidades as $subunidad) {
            $this->assertEqualsWithDelta($esperado, (float) $subunidad['porcentaje'], 0.005,
                'El árbol viaja con el porcentaje crudo de la tabla mientras las celdas van '
                .'convertidas: `myvc_flutter` lee ÉSTE, así que la planilla sumaría ponderado '
                .'mientras la definitiva guarda la media.');
        }
    }

    /**
     * **D20: `subunidades.porcentaje` no se toca en la base.**
     *
     * Lo que cambia es lo que se sirve, y es lo que hace que apagar el interruptor
     * devuelva el reparto que había. Sin esto, una entrega que «funciona» puede
     * haberse llevado por delante los porcentajes que el docente tecleó — y eso no
     * se deshace cambiando el enum.
     *
     * Se mira **después de pedir la planilla en promedio**, que es el único método
     * que convierte: si alguien resolviera la D30 con un `UPDATE`, aquí es donde se
     * vería.
     */
    #[Test]
    public function el_modo_promedio_no_reescribe_los_porcentajes_del_docente(): void
    {
        $ctx = $this->asignaturaDeCuatroPesos();
        $this->ponerElReparto($ctx, RepartoDeLaNota::PROMEDIO);

        $this->planilla($ctx);

        $enLaTabla = DB::table('subunidades')->where('unidad_id', $ctx['unidad'])
            ->whereNull('deleted_at')->orderBy('orden')->pluck('porcentaje')->all();

        $this->assertSame(self::PESOS, array_map('intval', $enLaTabla),
            'La conversión llegó a la tabla. El reparto que tecleó el docente es suyo, y apagar '
            .'el interruptor tiene que devolverlo tal cual.');

        // Y la prueba por el otro lado: apagado, la planilla vuelve a servir lo de siempre.
        $this->ponerElReparto($ctx, RepartoDeLaNota::PORCENTAJE);

        $vueltos = array_map(
            static fn (array $s): int => (int) round((float) $s['porcentaje']),
            $this->subunidadesDelArbol($this->planilla($ctx))
        );

        $this->assertSame(self::PESOS, $vueltos,
            'Apagado el interruptor, la planilla no devolvió el reparto que había.');
    }

    // ── La política: quién la escribe y por dónde ────────────────────────────

    /**
     * La política se guarda por su ruta, y **lo que se guarda es lo que se lee**.
     *
     * Con superusuario, que es quien puede el primer día. La respuesta publica la
     * columna nueva —el front la necesita para pintar el interruptor— y la fila
     * tiene que decir lo mismo: un 200 que devuelve `promedio` sobre una fila que
     * quedó en `porcentaje` es la familia de `respuestas-que-mienten.py`.
     */
    #[Test]
    public function el_reparto_que_se_guarda_es_el_que_se_lee(): void
    {
        $yearId = $this->anioActual();

        $r = $this->pedir(['reparto_subunidades' => 'promedio'], $yearId);

        $r->assertStatus(200);
        $this->assertSame('promedio', $r->json('reparto_subunidades'),
            'La respuesta no publica la columna nueva: el front no puede pintar el interruptor.');

        $this->assertSame('promedio',
            DB::table('years')->where('id', $yearId)->value('reparto_subunidades'),
            'Contestó `promedio` y la fila se quedó como estaba.');
    }

    /**
     * **Las dos políticas son independientes, y cada una es opcional.**
     *
     * Es la decisión de meter la Entrega 5 en la ruta de la Fase 1 en vez de darle
     * ruta propia: son dos políticas del mismo año, con el mismo dueño y en la misma
     * pantalla. Lo que eso obliga es lo que se comprueba aquí — **el campo que no
     * viene no se toca**. Sin esto, la pantalla que cambia sólo el reparto devolvería
     * el modelo de evaluación a su valor por defecto sin que nadie lo hubiera pedido,
     * y eso **no lo diría nada**: la respuesta es 200 y la columna que se pidió está
     * bien.
     */
    #[Test]
    public function cada_politica_se_escribe_sola(): void
    {
        $yearId = $this->anioActual();

        DB::table('years')->where('id', $yearId)->update([
            'modelo_evaluacion' => 'competencias',
            'reparto_subunidades' => 'porcentaje',
        ]);

        $this->pedir(['reparto_subunidades' => 'promedio'], $yearId)->assertStatus(200);

        $fila = DB::table('years')->where('id', $yearId)->first();

        $this->assertSame('promedio', $fila->reparto_subunidades);
        $this->assertSame('competencias', $fila->modelo_evaluacion,
            'Cambiar el reparto se llevó por delante el modelo de evaluación del colegio.');

        // Y por el otro lado, que es donde estaba el riesgo real: la ruta ya escribía
        // `modelo_evaluacion` antes de que existiera la segunda columna.
        $this->pedir(['modelo_evaluacion' => 'ponderado'], $yearId)->assertStatus(200);

        $fila = DB::table('years')->where('id', $yearId)->first();

        $this->assertSame('ponderado', $fila->modelo_evaluacion);
        $this->assertSame('promedio', $fila->reparto_subunidades,
            'Cambiar el modelo devolvió el reparto a su defecto.');
    }

    /** Un valor que no es del enum no entra, y no deja la fila a medias. */
    #[Test]
    public function un_reparto_que_no_existe_es_422_y_no_escribe(): void
    {
        $yearId = $this->anioActual();
        $antes = DB::table('years')->where('id', $yearId)->value('reparto_subunidades');

        $this->pedir(['reparto_subunidades' => 'promedios'], $yearId)->assertStatus(422);

        $this->assertSame($antes,
            DB::table('years')->where('id', $yearId)->value('reparto_subunidades'),
            'Un reparto inventado entró en la fila. El `enum` de MySQL guardaría la cadena vacía '
            .'sin modo estricto, que es la familia de `frases_asignatura` cortando a los 255.');
    }

    /**
     * **Sin ningún campo no es un 200 vacío.**
     *
     * Con los dos opcionales, una petición sin ninguno de los dos entraría, guardaría
     * nada y contestaría 200 — y quien la reciba creería que guardó algo. Es
     * literalmente lo que busca `tools/respuestas-que-mienten.py`. Antes de la
     * Entrega 5 esto salía solo por el 422 de `modelo_evaluacion` cuando faltaba.
     */
    #[Test]
    public function sin_ninguna_politica_es_422(): void
    {
        $this->pedir([], $this->anioActual())->assertStatus(422);
    }

    /**
     * **La puerta de al lado**, que es la mitad de la decisión que la decisión no
     * menciona.
     *
     * `PUT years/toggle-cambiar-valor` arma un `UPDATE years SET <campo>=:valor` con
     * cualquier columna que exista y su guard es `auth.personal`. `reparto_subunidades`
     * es la **segunda** columna con dueño, y entró convirtiendo aquel `if` en una
     * lista — precisamente porque con dos, copiar el bloque es cómo se olvida la
     * tercera.
     *
     * Se comprueba con un **superusuario**, que es el sujeto más fuerte posible: si
     * ni él la escribe por ahí, nadie la escribe. Y se mira la fila, porque un 422
     * que escribe igual dejaría la decisión en un mensaje de error.
     */
    #[Test]
    public function el_generico_de_years_no_puede_escribir_el_reparto(): void
    {
        $usuario = $this->usuarioDeTipo('Usuario');

        $this->assertSame(1, (int) $usuario->is_superuser,
            'Este caso necesita el sujeto MÁS fuerte: con uno llano, el corte podría venir del '
            .'permiso y no de la lista de columnas.');

        $yearId = $this->anioActual();
        $antes = DB::table('years')->where('id', $yearId)->value('reparto_subunidades');

        $r = $this->withToken($this->tokenDe($usuario->username))
            ->putJson('/api/years/toggle-cambiar-valor', [
                'year_id' => $yearId,
                'campo' => 'reparto_subunidades',
                'valor' => 'promedio',
            ]);

        $r->assertStatus(422);
        $this->assertSame($antes,
            DB::table('years')->where('id', $yearId)->value('reparto_subunidades'),
            'El genérico contestó 422 y escribió igual, que es la peor de las dos formas de fallar.');
    }

    /**
     * **Un docente llano no cambia el reparto del colegio entero.**
     *
     * El guard de la ruta es `auth.personal`, que cierra la puerta a alumnos y
     * acudientes **y a nadie más**: los **74** del personal la pasan. Lo que para a
     * un profesor es `Autoriza::puedeEditarPlantillaNotas` DENTRO del método, y sin
     * este caso quitarlo del controlador no pondría **nada** en rojo.
     *
     * Y no es una columna cualquiera: una fila de esta política **multiplica**.
     * Cambiarla mueve la definitiva de todas las asignaturas del año.
     */
    #[Test]
    public function un_docente_llano_no_cambia_el_reparto(): void
    {
        $usuario = $this->usuarioLlanoDelPersonal();

        $this->assertSame(0, (int) $usuario->is_superuser,
            'El sujeto de este caso NO puede ser superusuario: con la columna puesta, el 403 '
            .'no diría nada sobre el permiso.');

        $yearId = $this->anioActual();
        $antes = DB::table('years')->where('id', $yearId)->value('reparto_subunidades');

        $r = $this->withToken($this->tokenDe($usuario->username))
            ->putJson('/api/years/modelo-evaluacion', [
                'year_id' => $yearId,
                'reparto_subunidades' => 'promedio',
            ]);

        $r->assertStatus(403);
        $this->assertSame($antes,
            DB::table('years')->where('id', $yearId)->value('reparto_subunidades'),
            'Contestó 403 y escribió igual: el criterio frena la respuesta pero no la escritura.');
    }

    /**
     * **El mismo usuario, con el permiso, sí entra.**
     *
     * Con un superusuario el verde no diría nada: pasa por encima del permiso. Y el
     * permiso se da **por rol**, que es como llega de verdad al contexto — un atajo
     * aquí comprobaría un camino que en producción no existe.
     */
    #[Test]
    public function con_el_permiso_y_sin_superusuario_si_cambia_el_reparto(): void
    {
        $usuario = $this->usuarioLlanoDelPersonal();

        $this->assertSame(0, (int) $usuario->is_superuser);

        $this->darPermisoDeLaPlantilla((int) $usuario->id);

        $yearId = $this->anioActual();

        $this->withToken($this->tokenDe($usuario->username))
            ->putJson('/api/years/modelo-evaluacion', [
                'year_id' => $yearId,
                'reparto_subunidades' => 'promedio',
                // El aviso del recuento no es de este caso: aquí se mide el permiso.
                'acepto_recalcular' => true,
            ])->assertStatus(200);

        $this->assertSame('promedio',
            DB::table('years')->where('id', $yearId)->value('reparto_subunidades'));
    }

    /**
     * **El año nuevo hereda el reparto**, que es la línea que la Entrega 5 no traía.
     *
     * Lo destapó `CentinelaDeLasColumnasDelAnioNuevoTest` al correr la suite entera
     * —«`postStore` no dice nada de estas columnas: `reparto_subunidades`»—, y no lo
     * habría destapado ningún caso de la entrega, porque la entrega no tenía
     * ninguno.
     *
     * El centinela vigila desde el **fuente**: que la columna esté nombrada. Esto
     * mira el **resultado**, que es lo otro — una escrita como `Request::input('x')`
     * pasaría el centinela y no heredaría nada.
     *
     * Y aquí el defecto no deja una pantalla apagada: **deja otras notas**. Un
     * colegio en `promedio` lleva meses sin cuadrar `subunidades.porcentaje` —el modo
     * existe justamente para no tener que teclearla—, así que amanecer en
     * `porcentaje` reparte por unos números que ya no significan nada.
     */
    #[Test]
    public function el_anio_nuevo_hereda_el_reparto(): void
    {
        // El sujeto sale del último año **vivo**: `postStore` copia con
        // `Year::where('year', $pedido - 1)->first()`, que lleva `SoftDeletes`. Con
        // `max(year) + 1` el anterior sería el borrado del seed, la copia no correría
        // y este caso saldría verde sin haber heredado nada.
        $pasado = DB::selectOne('SELECT * FROM years WHERE deleted_at IS NULL
            ORDER BY year DESC, id DESC LIMIT 1');

        $this->assertNotNull($pasado, 'No hay año vivo del que heredar.');

        DB::table('years')->where('id', $pasado->id)
            ->update(['reparto_subunidades' => 'promedio']);

        $r = $this->withToken($this->tokenDe($this->usuarioDeTipo('Usuario')->username))
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

        $this->assertNotNull($nuevo, 'No se creó el año siguiente.');

        $this->assertSame('promedio', $nuevo->reparto_subunidades,
            'El año nuevo amaneció en `porcentaje` con el anterior en `promedio`: cada definitiva '
            .'del año se reparte por una columna que en ese colegio ya no cuadra nadie.');
    }

    // ── Ayudantes ────────────────────────────────────────────────────────────

    /**
     * La planilla del profesor, que es lo que sirve `PUT notas/detailed`.
     *
     * @param  array<string, mixed>  $ctx
     * @return array<string, mixed>
     */
    private function planilla(array $ctx): array
    {
        $r = $this->withToken($ctx['token'])->putJson('/api/notas/detailed', [
            'asignatura_id' => $ctx['asignatura'],
            'profesor_id' => $ctx['profesor'],
        ]);

        $r->assertStatus(200);

        return $r->json();
    }

    /**
     * Las celdas de un alumno, que es donde viajan los factores.
     *
     * @param  array<string, mixed>  $json
     * @return list<array<string, mixed>>
     */
    private function celdasDe(array $json, int $alumnoId): array
    {
        $celdas = [];

        foreach ($json['alumnos'] ?? [] as $alumno) {
            if ((int) ($alumno['alumno_id'] ?? 0) !== $alumnoId) {
                continue;
            }

            foreach ($alumno['notas'] ?? [] as $nota) {
                $celdas[] = $nota;
            }
        }

        return $celdas;
    }

    /**
     * Las subunidades del árbol de unidades, que es la otra representación.
     *
     * @param  array<string, mixed>  $json
     * @return list<array<string, mixed>>
     */
    private function subunidadesDelArbol(array $json): array
    {
        $subunidades = [];

        foreach ($json['unidades'] ?? [] as $unidad) {
            foreach ($unidad['subunidades'] ?? [] as $subunidad) {
                $subunidades[] = $subunidad;
            }
        }

        return $subunidades;
    }

    /**
     * Se escribe a pelo: lo que se mide aquí es el efecto del valor, no la ruta.
     * La ruta tiene sus propios casos más arriba.
     *
     * @param  array<string, mixed>  $ctx
     */
    private function ponerElReparto(array $ctx, string $modo): void
    {
        DB::table('years')->where('id', $ctx['year'])->update(['reparto_subunidades' => $modo]);
    }

    /** @param array<string, mixed> $ctx */
    private function recalcular(array $ctx): void
    {
        DefinitivasDeAsignatura::recalcular($ctx['asignatura'], $ctx['periodo'], $ctx['user']);
    }

    /** @param array<string, mixed> $ctx */
    private function definitivaDe(array $ctx): ?float
    {
        $valor = DB::table('notas_finales')
            ->where('alumno_id', $ctx['alumno'])
            ->where('asignatura_id', $ctx['asignatura'])
            ->where('periodo_id', $ctx['periodo'])
            ->value('nota');

        return $valor === null ? null : (float) $valor;
    }

    private function anioActual(): int
    {
        $id = DB::table('years')->where('actual', 1)->whereNull('deleted_at')->value('id');

        $this->assertNotNull($id, 'El seed no tiene año actual.');

        return (int) $id;
    }

    /**
     * Con el token de un superusuario, que es quien puede el primer día.
     *
     * **Lleva `acepto_recalcular` desde el 15 sep 2026**, y no es ruido de montaje:
     * cambiar el reparto de un año que tiene definitivas guardadas contesta **422 con
     * el recuento** hasta que se acepta (doc 28 §5.5,
     * {@see AceptoRecalcularElRepartoTest}). Los casos de este fichero miden **lo que
     * se escribe**, así que pasan la llave y siguen midiendo eso; quien mide el aviso
     * es el otro fichero.
     *
     * Lo enseñó la suite entera: estos tres casos pasaban solos y cayeron al fundir
     * con el aviso, porque **se corrió el test nuevo y no el hermano que comparte
     * endpoint**. Es «un subconjunto verde no basta» otra vez, y del lado de quien ya
     * lo tenía escrito.
     *
     * @param  array<string, mixed>  $cuerpo
     */
    private function pedir(array $cuerpo, int $yearId)
    {
        $usuario = $this->usuarioDeTipo('Usuario');

        $this->assertSame(1, (int) $usuario->is_superuser,
            'El sujeto de estos casos tiene que ser superusuario: sin él, el 403 del criterio '
            .'se leería como un fallo de la validación.');

        return $this->withToken($this->tokenDe($usuario->username))
            ->putJson('/api/years/modelo-evaluacion',
                $cuerpo + ['year_id' => $yearId, 'acepto_recalcular' => true]);
    }

    /**
     * El permiso por rol, que es como llega de verdad al contexto. Calcado de
     * `ModeloDeEvaluacionDelAnioTest`, y por el mismo motivo: `test-seed.sql` hace
     * `TRUNCATE` de `permissions`, así que lo que siembre la migración **no
     * sobrevive a construir la base** y un caso que se apoyara en ello estaría
     * comprobando el seed y no el código.
     */
    private function darPermisoDeLaPlantilla(int $userId): void
    {
        $permiso = DB::table('permissions')->where('name', Autoriza::PERMISO_PLANTILLA_NOTAS)->value('id')
            ?? DB::table('permissions')->insertGetId([
                'name' => Autoriza::PERMISO_PLANTILLA_NOTAS,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        $rol = DB::table('roles')->where('name', 'CoordinacionDePrueba')->value('id')
            ?? DB::table('roles')->insertGetId([
                'name' => 'CoordinacionDePrueba',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        if (! DB::table('permission_role')->where('permission_id', $permiso)->where('role_id', $rol)->exists()) {
            DB::table('permission_role')->insert(['permission_id' => $permiso, 'role_id' => $rol]);
        }

        if (! DB::table('role_user')->where('user_id', $userId)->where('role_id', $rol)->exists()) {
            DB::table('role_user')->insert(['user_id' => $userId, 'role_id' => $rol]);
        }
    }

    /**
     * Una asignatura del profesor con **una unidad al 100 % y cuatro subunidades al
     * 40/30/20/10**, con notas 40/40/20/20 para un alumno matriculado.
     *
     * La asignatura se busca —tiene que ser **suya**, o `notas/detailed` no
     * contestaría lo que esta pantalla contesta— y la rejilla se monta. Las unidades
     * que ya tenía se mandan a la papelera primero: con los porcentajes del seed la
     * aritmética deja de poder hacerse de cabeza, y un test de aritmética que no se
     * pueda leer no defiende nada.
     *
     * @return array<string, mixed>
     */
    private function asignaturaDeCuatroPesos(): array
    {
        $fila = DB::selectOne('SELECT a.id AS asignatura_id, a.grupo_id, a.profesor_id,
                u.id AS user_id, u.username, un.periodo_id, g.year_id
            FROM asignaturas a
            INNER JOIN profesores p ON p.id = a.profesor_id AND p.deleted_at IS NULL
            INNER JOIN users u ON u.id = p.user_id AND u.is_active = 1 AND u.deleted_at IS NULL
            INNER JOIN grupos g ON g.id = a.grupo_id AND g.deleted_at IS NULL
            INNER JOIN unidades un ON un.asignatura_id = a.id AND un.deleted_at IS NULL
            INNER JOIN periodos per ON per.id = un.periodo_id AND per.actual = 1
                AND per.year_id = g.year_id AND per.deleted_at IS NULL
            INNER JOIN years y ON y.id = g.year_id AND y.actual = 1 AND y.deleted_at IS NULL
            INNER JOIN matriculas m ON m.grupo_id = a.grupo_id AND m.deleted_at IS NULL
                AND m.estado IN ("MATR", "ASIS", "PREM")
            WHERE a.deleted_at IS NULL
            ORDER BY a.id LIMIT 1');

        $this->assertNotNull($fila,
            'El seed necesita una asignatura con profesor activo, unidades en el periodo actual y '
            .'alumnos matriculados.');

        $alumno = DB::selectOne('SELECT m.alumno_id FROM matriculas m
            WHERE m.grupo_id = ? AND m.deleted_at IS NULL
              AND m.estado IN ("MATR", "ASIS", "PREM") ORDER BY m.id LIMIT 1', [$fila->grupo_id]);

        $this->assertNotNull($alumno, 'El grupo elegido se quedó sin alumnos matriculados.');

        // **Los dos lados tienen que estar mirando el mismo año, y por caminos
        // distintos.** El que ESCRIBE la definitiva resuelve el modo desde el periodo
        // (`modoDelPeriodo`) y el que SIRVE la planilla lo resuelve desde la sesión
        // (`$user->year_id`). Si el profesor del seed estuviera en otro año que su
        // grupo, este test encendería el interruptor en un año y mediría el otro —y
        // saldría verde en `porcentaje` por los dos lados, que es la forma de aprobado
        // que no enseña nada. Se exige aquí en vez de suponerse.
        $this->assertSame($this->anioActual(), (int) $fila->year_id,
            'La asignatura elegida no es del año actual: la planilla leería el modo de otro año.');

        // La rejilla que había, a la papelera. Las cuatro consultas del cálculo y la
        // de la planilla filtran `u.deleted_at is null`, así que con esto la
        // asignatura queda con la rejilla del montaje y nada más.
        DB::table('unidades')
            ->where('asignatura_id', $fila->asignatura_id)
            ->where('periodo_id', $fila->periodo_id)
            ->whereNull('deleted_at')
            ->update(['deleted_at' => now()]);

        $unidadId = DB::table('unidades')->insertGetId([
            'asignatura_id' => $fila->asignatura_id,
            'periodo_id' => $fila->periodo_id,
            'definicion' => 'UNIDAD DEL REPARTO',
            'porcentaje' => 100,
            'orden' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach (self::PESOS as $i => $peso) {
            $subId = DB::table('subunidades')->insertGetId([
                'unidad_id' => $unidadId,
                'definicion' => 'SUB '.($i + 1),
                'porcentaje' => $peso,
                'orden' => $i + 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('notas')->insert([
                'subunidad_id' => $subId,
                'alumno_id' => $alumno->alumno_id,
                'nota' => self::NOTAS[$i],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return [
            'token' => $this->tokenDe($fila->username),
            'user' => (int) $fila->user_id,
            'asignatura' => (int) $fila->asignatura_id,
            'profesor' => (int) $fila->profesor_id,
            'periodo' => (int) $fila->periodo_id,
            'year' => (int) $fila->year_id,
            'grupo' => (int) $fila->grupo_id,
            'alumno' => (int) $alumno->alumno_id,
            'unidad' => (int) $unidadId,
        ];
    }
}
