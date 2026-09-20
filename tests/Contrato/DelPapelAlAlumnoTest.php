<?php

namespace Tests\Contrato;

use App\Services\CodigoDeInscripcion;
use Illuminate\Support\Facades\DB;

/**
 * **Del papel al alumno**: las cuatro rutas que cierran el formulario de
 * inscripción.
 *
 *     GET  informes/formularios-inscripcion/codigo/{codigo}         lo que hay detrás
 *     PUT  informes/formularios-inscripcion/codigo/{codigo}/alumno  lo ata
 *     PUT  informes/formularios-inscripcion/codigo/{codigo}         corrige el código
 *     GET  informes/formularios-inscripcion/campana                 el informe
 *
 * Lo que este fichero defiende son **las tres frases con las que Joseth pidió
 * esto**, y ninguna se comprueba mirando un 200:
 *
 *   1. *«el código se queda con ese alumno al que inscriban»* — el código **no
 *      cambia** al atarlo, y se comprueba byte a byte contra la base.
 *   2. *«ni se repite cuando ya le dimos el formulario a un nuevo acudiente que vino
 *      por él»* — atar a un alumno que ya tiene el suyo **no acuña nada** y el papel
 *      del segundo acudiente **queda libre**. Se cuenta la tabla antes y después.
 *   3. *«los códigos no los debe inventar la secretaría»* — quien corrige teclea el
 *      sufijo y **el carácter de control lo pone la API**, así que por ese camino no
 *      puede salir un código que no valide.
 *
 * ## CONTROL VISTO EN ROJO, cuatro veces — y el primero enseñó algo
 *
 * | lo que se rompió | qué cayó |
 * |---|---|
 * | el informe cuenta `estado` en vez del `JOIN` | el test que lo nombra, y sólo ése |
 * | corregir sin guardar `codigo_anterior` | dos: el papel viejo y el «deme otro» |
 * | atar reacuña el código | seis, el primero el que lo nombra |
 * | la lectura previa de «ya tiene el suyo» | **NADA** |
 *
 * **Ese último renglón no es un test flojo: es que la propiedad la sostienen DOS
 * mecanismos independientes.** Quitando sólo la lectura previa, el
 * `UNIQUE (year_campana, alumno_id)` la atrapa igual —el `UPDATE` choca, el `catch`
 * relee y contesta lo mismo—; quitando sólo el `catch`, la atrapa la lectura.
 * Quitando **los dos** cae exactamente `test_un_segundo_acudiente_no_gasta_otro_codigo`
 * y ningún otro.
 *
 * Eso es lo que hay que querer de un test de esta familia: **comprueba la propiedad,
 * no el camino**. Uno atado a la implementación se habría puesto rojo al quitar
 * cualquiera de las dos y habría hecho creer que el código estaba roto cuando la
 * base seguía defendiéndolo.
 *
 * ## El seed pone el caso real encima de la mesa sin que haya que fingirlo
 *
 * El año actual es **2025** y **no existe fila de `years` para 2026**, que es
 * exactamente lo que la migración describe: *el colegio abre la campaña antes de
 * crear el año*. Por eso `year_campana` es una columna y no `year_id + 1`, y por eso
 * los tests que necesitan una matrícula de la campaña **tienen que crear ese año**
 * — dentro de la transacción del test, que es lo que lo hace inocuo.
 */
class DelPapelAlAlumnoTest extends CasoDeContrato
{
    private const RUTA = '/api/informes/formularios-inscripcion';

    /**
     * Los dos tokens, pedidos **una vez por test**.
     *
     * No es una optimización: cada `tokenDe()` es un **login de verdad** contra la
     * misma API, y el limitador de la casa corta a las 120 por minuto. Un bucle de
     * nueve casos que pida token en cada vuelta se cae con un **429** —visto en rojo
     * al escribir esto— y el fallo no se parece en nada a su causa: el test que se
     * rompe es el siguiente, y dice «esperaba 200 y recibí 429» desde dentro del
     * ayudante del login.
     */
    private ?string $token = null;

    private ?string $tokenAtar = null;

    // ─────────────────────────────────────────────────────────────────────────
    // 1 · El código se queda con el alumno
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * **El requisito literal**: atar no reacuña.
     *
     * Se compara contra la base y no contra la respuesta: una respuesta que devuelva
     * el código bueno mientras la fila guarda otro pasaría igual, y el papel que la
     * familia tiene en la mano dejaría de encontrar nada.
     */
    public function test_el_codigo_no_cambia_al_atarlo_a_un_alumno(): void
    {
        $codigo = $this->unCodigoEnBlanco();
        $alumno = $this->unAlumnoSinOrden();
        $antes = $this->cuantasOrdenes();

        $r = $this->withToken($this->tokenQuePuedeAtar())
            ->putJson(self::RUTA.'/codigo/'.$codigo.'/alumno', ['alumno_id' => $alumno->id]);

        $r->assertStatus(200);

        $this->assertSame($codigo, $r->json('codigo'),
            'La respuesta devolvió otro código: atar reacuñó.');

        $fila = DB::selectOne('SELECT codigo, alumno_id FROM ordenes_inscripcion WHERE codigo=?',
            [$codigo]);

        $this->assertNotNull($fila,
            'El código que se imprimió ya no está en la tabla: el papel de la familia quedó huérfano.');
        $this->assertSame((int) $alumno->id, (int) $fila->alumno_id);

        $this->assertSame($antes, $this->cuantasOrdenes(),
            'Atar creó una fila nueva. El código tiene que quedarse con el papel que ya se imprimió.');
    }

    /**
     * **El caso que Joseth nombró por su nombre**: *«no se repite cuando ya le dimos
     * el formulario a un nuevo acudiente que vino por él»*.
     *
     * El alumno ya tiene su código de la campaña. Llega otro acudiente con un
     * formulario en blanco. **No se acuña nada, no se mueve nada**, y el 409 trae
     * dentro el código que ese alumno ya tiene — sin eso la pantalla sólo podría
     * decir «ya tiene uno» y la secretaría tendría que ir a buscarlo a mano, que es
     * el trabajo que este módulo existe para quitar.
     *
     * Y lo que se comprueba además es lo que **no** pasó: el papel del segundo sigue
     * en blanco y se le puede dar a otra familia.
     */
    public function test_un_segundo_acudiente_no_gasta_otro_codigo(): void
    {
        $token = $this->tokenQuePuedeAtar();
        $suyo = $this->unaRenovacionDeUnAlumno();
        $enBlanco = $this->unCodigoEnBlanco();
        $antes = $this->cuantasOrdenes();

        $r = $this->withToken($token)->putJson(self::RUTA.'/codigo/'.$enBlanco.'/alumno',
            ['alumno_id' => $suyo->alumno_id]);

        $r->assertStatus(409);

        $this->assertSame($suyo->codigo, $r->json('codigo'),
            'El 409 no dice cuál es el código que ese alumno ya tiene, así que no sirve de nada.');

        $this->assertSame($antes, $this->cuantasOrdenes(),
            'Se acuñó un código de más para un alumno que ya tenía el suyo.');

        $libre = DB::selectOne('SELECT alumno_id FROM ordenes_inscripcion WHERE codigo=?', [$enBlanco]);

        $this->assertNull($libre->alumno_id,
            'El formulario en blanco del segundo acudiente se quedó pegado a un alumno '
            .'que ya tenía el suyo: ese papel ya no se le puede dar a otra familia.');
    }

    /** Un reintento de la pantalla no puede ser un error. */
    public function test_atar_dos_veces_al_mismo_alumno_es_idempotente(): void
    {
        $token = $this->tokenQuePuedeAtar();
        $codigo = $this->unCodigoEnBlanco();
        $alumno = $this->unAlumnoSinOrden();

        $this->withToken($token)->putJson(self::RUTA.'/codigo/'.$codigo.'/alumno',
            ['alumno_id' => $alumno->id])->assertStatus(200);

        $antes = $this->cuantasOrdenes();

        $this->withToken($token)->putJson(self::RUTA.'/codigo/'.$codigo.'/alumno',
            ['alumno_id' => $alumno->id])->assertStatus(200);

        $this->assertSame($antes, $this->cuantasOrdenes());
    }

    /**
     * Un formulario vendido no cambia de dueño.
     *
     * Reatar es rehacer un cobro: la fila dice cuánto se pagó y quién lo vendió, y
     * moverla de alumno deja esa plata contada en la familia equivocada.
     */
    public function test_un_formulario_ya_atado_no_cambia_de_dueno(): void
    {
        $token = $this->tokenQuePuedeAtar();
        $codigo = $this->unCodigoEnBlanco();
        $primero = $this->unAlumnoSinOrden();

        $this->withToken($token)->putJson(self::RUTA.'/codigo/'.$codigo.'/alumno',
            ['alumno_id' => $primero->id])->assertStatus(200);

        $segundo = $this->unAlumnoSinOrden([$primero->id]);

        $this->withToken($token)->putJson(self::RUTA.'/codigo/'.$codigo.'/alumno',
            ['alumno_id' => $segundo->id])->assertStatus(409);

        $fila = DB::selectOne('SELECT alumno_id FROM ordenes_inscripcion WHERE codigo=?', [$codigo]);

        $this->assertSame((int) $primero->id, (int) $fila->alumno_id,
            'El 409 se devolvió y la fila se escribió igual: el dueño cambió de todas formas.');
    }

    /**
     * **Atar a alguien ya matriculado en la campaña cierra el formulario.**
     *
     * Es la mitad `matricula_id` del requisito —*«siempre se puede ir del papel a la
     * matrícula y al revés»*— y hasta hoy esa columna **no la escribía nadie en todo
     * `app/`**.
     */
    public function test_atar_a_un_alumno_ya_matriculado_cierra_el_formulario(): void
    {
        $escenario = $this->unAlumnoMatriculadoEnLaCampana();
        $codigo = $this->unCodigoEnBlanco();

        $r = $this->withToken($this->tokenQuePuedeAtar())
            ->putJson(self::RUTA.'/codigo/'.$codigo.'/alumno', ['alumno_id' => $escenario->alumno_id]);

        $r->assertStatus(200);
        $this->assertSame('MATRICULADA', $r->json('estado'));

        $fila = DB::selectOne('SELECT estado, matricula_id FROM ordenes_inscripcion WHERE codigo=?',
            [$codigo]);

        $this->assertSame('MATRICULADA', $fila->estado);
        $this->assertSame((int) $escenario->matricula_id, (int) $fila->matricula_id,
            'El formulario no guardó de qué matrícula salió, así que del papel a la matrícula no se va.');
    }

    public function test_un_alumno_borrado_no_se_puede_atar(): void
    {
        $codigo = $this->unCodigoEnBlanco();

        $this->withToken($this->tokenQuePuedeAtar())
            ->putJson(self::RUTA.'/codigo/'.$codigo.'/alumno', ['alumno_id' => 999999999])
            ->assertStatus(404);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 2 · Corregir el código: automático, y sin repetir
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * **Quien corrige teclea CINCO caracteres y el control lo pone la API.**
     *
     * Es la herramienta que pidió Joseth: por este camino **no se puede escribir un
     * código que no valide**, porque la persona no escribe la parte que podría estar
     * mal. Se comprueba contra `esValido()`, que es quien va a rechazarlo cuando
     * alguien lo teclee en la ventanilla.
     */
    public function test_el_sufijo_lo_teclea_una_persona_y_el_control_lo_pone_la_api(): void
    {
        $codigo = $this->unCodigoEnBlanco();

        $r = $this->withToken($this->tokenQuePuedeAtar())
            ->putJson(self::RUTA.'/codigo/'.$codigo, ['sufijo' => 'ABCDE']);

        $r->assertStatus(200);

        $nuevo = $r->json('codigo');

        $this->assertNotSame($codigo, $nuevo);
        $this->assertStringStartsWith('2026-ABCDE', $nuevo);

        $this->assertTrue(CodigoDeInscripcion::esValido($nuevo),
            "La corrección dejó «{$nuevo}», y el validador de la propia casa lo rechaza: "
            .'ese papel no lo va a poder teclear nadie.');
    }

    /**
     * **El papel viejo sigue encontrando su formulario.**
     *
     * Sin esto, corregir un código convierte en basura silenciosa el papel que está
     * en casa de la familia: quien lo teclee recibe «no existe» y nadie puede saber
     * que existió.
     */
    public function test_el_codigo_viejo_sigue_encontrando_el_formulario(): void
    {
        $token = $this->tokenQuePuedeAtar();
        $viejo = $this->unCodigoEnBlanco();

        $nuevo = $this->withToken($token)
            ->putJson(self::RUTA.'/codigo/'.$viejo, ['sufijo' => 'ABCDE'])
            ->assertStatus(200)->json('codigo');

        $r = $this->withToken($token)->getJson(self::RUTA.'/codigo/'.$viejo);

        $r->assertStatus(200);

        $this->assertSame($nuevo, $r->json('codigo'),
            'Buscar por el código impreso no llevó al formulario: ese papel quedó huérfano.');
        $this->assertSame('codigo_anterior', $r->json('encontrado_por'),
            'La respuesta no avisa de que ese papel lleva el código viejo, '
            .'así que la pantalla no puede decírselo a nadie.');
    }

    /** Que no se repita lo garantiza el `UNIQUE`, y aquí sale como 409 y no como 500. */
    public function test_corregir_a_un_codigo_que_ya_existe_es_409_y_no_toca_nada(): void
    {
        $token = $this->tokenQuePuedeAtar();
        $uno = $this->unCodigoEnBlanco();
        $otro = $this->unCodigoEnBlanco();

        $sufijoDelOtro = substr(explode('-', $otro)[1], 0, CodigoDeInscripcion::LARGO);

        $this->withToken($token)->putJson(self::RUTA.'/codigo/'.$uno, ['sufijo' => $sufijoDelOtro])
            ->assertStatus(409);

        $this->assertNotNull(DB::selectOne('SELECT id FROM ordenes_inscripcion WHERE codigo=?', [$uno]),
            'El 409 se devolvió y el código se cambió igual.');
    }

    /**
     * Los siete caracteres que no están en el alfabeto **no entran por aquí**.
     *
     * No es validación por validar: la `O`, el `0`, la `I`, el `1`, la `L`, la `S` y
     * el `5` están fuera porque una persona los confunde leyendo un papel. Dejar
     * colar una `O` en una corrección metería en circulación justo el código que
     * este módulo se cuida de no acuñar.
     */
    public function test_el_sufijo_no_admite_los_caracteres_que_se_confunden(): void
    {
        $token = $this->tokenQuePuedeAtar();
        $this->tokenDelPersonalLlano();   // calienta el memorizado antes del bucle

        $malos = ['ABCDO', 'ABCD0', 'ABCDI', 'ABCD1', 'ABCDL', 'ABCDS', 'ABCD5', 'ABC', 'ABCDEF'];

        // Los nueve códigos en **una** llamada: uno por vuelta serían nueve tandas
        // acuñadas para tirarlas, y el papel no se acuña para tirarlo.
        $codigos = array_column($this->withToken($token)
            ->postJson(self::RUTA, ['modo' => 'nuevos', 'cantidad' => count($malos)])
            ->assertStatus(200)->json('formularios'), 'codigo');

        foreach ($malos as $i => $malo) {
            $codigo = $codigos[$i];

            $this->withToken($token)->putJson(self::RUTA.'/codigo/'.$codigo, ['sufijo' => $malo])
                ->assertStatus(422);

            $this->assertNotNull(
                DB::selectOne('SELECT id FROM ordenes_inscripcion WHERE codigo=?', [$codigo]),
                "«{$malo}» dio 422 y el código se cambió igual.");
        }
    }

    /** Sin sufijo es el botón de «deme otro»: lo acuña la API. */
    public function test_sin_sufijo_la_api_acuna_uno_nuevo(): void
    {
        $codigo = $this->unCodigoEnBlanco();

        $nuevo = $this->withToken($this->tokenQuePuedeAtar())
            ->putJson(self::RUTA.'/codigo/'.$codigo, [])
            ->assertStatus(200)->json('codigo');

        $this->assertNotSame($codigo, $nuevo);
        $this->assertTrue(CodigoDeInscripcion::esValido($nuevo));
        $this->assertSame($codigo, DB::selectOne(
            'SELECT codigo_anterior a FROM ordenes_inscripcion WHERE codigo=?', [$nuevo])->a);
    }

    /** Pedir el que ya tiene no es una corrección: no puede ser «el anterior» de sí mismo. */
    public function test_pedir_el_mismo_codigo_no_lo_convierte_en_su_propio_anterior(): void
    {
        $codigo = $this->unCodigoEnBlanco();
        $sufijo = substr(explode('-', $codigo)[1], 0, CodigoDeInscripcion::LARGO);

        $this->withToken($this->tokenQuePuedeAtar())
            ->putJson(self::RUTA.'/codigo/'.$codigo, ['sufijo' => $sufijo])
            ->assertStatus(200);

        $this->assertNull(DB::selectOne(
            'SELECT codigo_anterior a FROM ordenes_inscripcion WHERE codigo=?', [$codigo])->a);
    }

    public function test_un_formulario_matriculado_no_cambia_de_codigo(): void
    {
        $escenario = $this->unAlumnoMatriculadoEnLaCampana();
        $codigo = $this->unCodigoEnBlanco();
        $token = $this->tokenQuePuedeAtar();

        $this->withToken($token)->putJson(self::RUTA.'/codigo/'.$codigo.'/alumno',
            ['alumno_id' => $escenario->alumno_id])->assertStatus(200);

        $this->withToken($token)->putJson(self::RUTA.'/codigo/'.$codigo, ['sufijo' => 'ABCDE'])
            ->assertStatus(409);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 3 · El informe de la campaña
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * **La decisión del informe, vista en rojo**: los matriculados se cuentan contra
     * `matriculas` y **no** contra `ordenes_inscripcion.estado`.
     *
     * El escenario es el normal, no uno raro: el alumno se matricula por cualquiera
     * de los **ocho escritores de `matriculas`** que hay en `app/` —ninguno es de
     * este módulo— y nadie vuelve a tocar la orden. Si el informe contara la columna,
     * aquí diría **cero matriculados** y metería a ese alumno en la lista de llamadas
     * de «compró y no volvió» estando ya en clase.
     */
    public function test_el_informe_cuenta_los_matriculados_contra_matriculas_y_no_contra_el_estado(): void
    {
        $escenario = $this->unAlumnoMatriculadoEnLaCampana();
        $codigo = $this->unCodigoEnBlanco();

        // Se ata **sin pasar por la ruta**, que es como queda una orden cuando el
        // alumno se matricula después: la columna `estado` se queda en «IMPRESA».
        DB::update('UPDATE ordenes_inscripcion SET alumno_id=? WHERE codigo=?',
            [$escenario->alumno_id, $codigo]);

        $r = $this->withToken($this->tokenDelPersonalLlano())
            ->getJson(self::RUTA.'/campana?year_campana=2026');

        $r->assertStatus(200);

        $this->assertSame('IMPRESA',
            DB::selectOne('SELECT estado FROM ordenes_inscripcion WHERE codigo=?', [$codigo])->estado,
            'El escenario no es el que dice: la columna ya estaba puesta y el test no mide nada.');

        $this->assertGreaterThanOrEqual(1, $r->json('resumen.matriculados'),
            'El informe cuenta la columna `estado` en vez de las matrículas de verdad, '
            .'así que un alumno matriculado por cualquiera de los otros siete caminos no aparece.');
    }

    /**
     * **La lista que el colegio no tenía**: pagó y no tiene matrícula de la campaña.
     *
     * Viaja con teléfonos porque para lo que sirve es para una tarde de llamadas.
     */
    public function test_el_que_pago_y_no_tiene_matricula_sale_en_la_lista_de_llamadas(): void
    {
        $codigo = $this->unCodigoEnBlanco();
        $alumno = $this->unAlumnoSinOrden();

        $this->withToken($this->tokenQuePuedeAtar())
            ->putJson(self::RUTA.'/codigo/'.$codigo.'/alumno', ['alumno_id' => $alumno->id])
            ->assertStatus(200);

        DB::update('UPDATE ordenes_inscripcion SET estado="PAGADA", valor=30000 WHERE codigo=?',
            [$codigo]);

        $r = $this->withToken($this->tokenDelPersonalLlano())
            ->getJson(self::RUTA.'/campana?year_campana=2026');

        $r->assertStatus(200);

        $codigos = array_column($r->json('sin_volver'), 'codigo');

        $this->assertContains($codigo, $codigos,
            'Alguien pagó el formulario y no se matriculó, y el informe no lo lista: '
            .'eso es dinero que el colegio recibió y una llamada que nadie va a hacer.');

        $this->assertGreaterThanOrEqual(30000, $r->json('resumen.recaudado'));
        $this->assertArrayHasKey('celular', $r->json('sin_volver.0'));
        $this->assertArrayHasKey('acudiente', $r->json('sin_volver.0'));
    }

    /** Un estado con cero tiene que salir, o la pantalla no distingue «ninguno» de «no me lo mandaron». */
    public function test_el_informe_nombra_los_cinco_estados_aunque_estén_en_cero(): void
    {
        $r = $this->withToken($this->tokenDelPersonalLlano())
            ->getJson(self::RUTA.'/campana?year_campana=2099');

        $r->assertStatus(200);

        foreach (['IMPRESA', 'PAGADA', 'APROBADA', 'RECHAZADA', 'MATRICULADA'] as $estado) {
            $this->assertArrayHasKey($estado, $r->json('por_estado'));
        }

        $this->assertSame(0, $r->json('resumen.impresos'));
        $this->assertFalse($r->json('sin_volver_recortada'));
    }

    /**
     * **`campana` va ANTES que `{lote}` en el router, y eso no es estilo.**
     *
     * Laravel casa por orden de registro: con el comodín delante, esta petición
     * entraría por el lote llamado «campana» y contestaría 404. Es la misma trampa
     * que ya costó una vuelta con `…/campos`, y vive en una línea invisible.
     */
    public function test_campana_no_se_la_traga_el_comodin_del_lote(): void
    {
        $this->withToken($this->tokenDelPersonalLlano())
            ->getJson(self::RUTA.'/campana')
            ->assertStatus(200)
            ->assertJsonStructure(['year_campana', 'resumen', 'por_estado', 'sin_volver']);
    }

    /** Por defecto, la misma cuenta que al acuñar: el año de la sesión más uno. */
    public function test_la_campana_por_defecto_es_la_del_ano_siguiente(): void
    {
        $r = $this->withToken($this->tokenDelPersonalLlano())->getJson(self::RUTA.'/campana');

        $r->assertStatus(200);
        $this->assertSame(2026, $r->json('year_campana'));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 4 · Quién puede, y qué pasa con un código que no es nuestro
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * **Las dos escrituras llevan criterio dentro; las dos lecturas no.**
     *
     * Un docente cualquiera pasa `auth.personal` —son 53 de las 74 cuentas de
     * personal— y tiene que poder **mirar** el papel que le ponen delante en la
     * estación de documentos. Lo que no puede es decidir de quién es un cobro ni
     * cambiar lo que lleva impreso un papel que está en casa de una familia.
     */
    public function test_un_docente_mira_el_papel_pero_no_lo_ata_ni_le_cambia_el_codigo(): void
    {
        $codigo = $this->unCodigoEnBlanco();
        $token = $this->tokenDelPersonalLlano();

        $this->withToken($token)->getJson(self::RUTA.'/codigo/'.$codigo)->assertStatus(200);

        $this->withToken($token)->putJson(self::RUTA.'/codigo/'.$codigo.'/alumno',
            ['alumno_id' => $this->unAlumnoSinOrden()->id])->assertStatus(403);

        $this->withToken($token)->putJson(self::RUTA.'/codigo/'.$codigo,
            ['sufijo' => 'ABCDE'])->assertStatus(403);
    }

    public function test_un_alumno_no_entra_en_ninguna_de_las_cuatro(): void
    {
        $token = $this->tokenDe($this->usuarioDeTipo('Alumno')->username);

        $this->withToken($token)->getJson(self::RUTA.'/campana')->assertStatus(403);
        $this->withToken($token)->getJson(self::RUTA.'/codigo/2026-ABCDE')->assertStatus(403);
    }

    public function test_sin_token_ninguna_de_las_cuatro_contesta(): void
    {
        $this->getJson(self::RUTA.'/campana')->assertStatus(401);
        $this->getJson(self::RUTA.'/codigo/2026-ABCDE')->assertStatus(401);
        $this->putJson(self::RUTA.'/codigo/2026-ABCDE')->assertStatus(401);
        $this->putJson(self::RUTA.'/codigo/2026-ABCDE/alumno', ['alumno_id' => 1])->assertStatus(401);
    }

    /**
     * Un código que no valida es **422 sin tocar la base**, y eso no es cosmético:
     * es lo que impide usar esta ruta para tantear la tabla probando códigos.
     *
     * El 404 dice otra cosa —*«es un código nuestro y no existe»*— y las dos
     * respuestas tienen que ser distinguibles para que la pantalla pueda decir
     * «revíselo» en vez de «no existe».
     */
    public function test_un_codigo_que_no_valida_es_422_y_uno_que_no_existe_es_404(): void
    {
        $token = $this->tokenDelPersonalLlano();

        // Control cambiado a mano: tiene la forma, no cuadra.
        $this->withToken($token)->getJson(self::RUTA.'/codigo/2026-ABCDE')
            ->assertStatus(CodigoDeInscripcion::esValido('2026-ABCDE') ? 404 : 422);

        $valido = CodigoDeInscripcion::componer(2026, 'ZZZZZ');

        $this->withToken($token)->getJson(self::RUTA.'/codigo/'.$valido)->assertStatus(404);
    }

    /** Se teclea en minúsculas y con espacios, que es como se teclea de verdad. */
    public function test_el_codigo_se_normaliza_al_buscarlo(): void
    {
        $codigo = $this->unCodigoEnBlanco();

        $this->withToken($this->tokenDelPersonalLlano())
            ->getJson(self::RUTA.'/codigo/'.rawurlencode(' '.strtolower($codigo).' '))
            ->assertStatus(200)
            ->assertJsonPath('codigo', $codigo);
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function unCodigoEnBlanco(): string
    {
        return $this->withToken($this->tokenDelPersonalLlano())
            ->postJson(self::RUTA, ['modo' => 'nuevos', 'cantidad' => 1])
            ->assertStatus(200)->json('formularios.0.codigo');
    }

    /**
     * Una renovación ya acuñada: un alumno **con** su código de la campaña.
     *
     * @return object{codigo: string, alumno_id: int}
     */
    private function unaRenovacionDeUnAlumno(): object
    {
        $grupo = DB::selectOne('SELECT g.id
            FROM grupos g
            INNER JOIN years y ON y.id=g.year_id AND y.actual=1 AND y.deleted_at IS NULL
            INNER JOIN matriculas m ON m.grupo_id=g.id AND m.deleted_at IS NULL
                AND (m.estado="ASIS" OR m.estado="MATR")
            WHERE g.deleted_at IS NULL
            GROUP BY g.id HAVING COUNT(m.id) > 0 LIMIT 1');

        $this->assertNotNull($grupo, 'El seed no tiene grupos con alumnos: esto no mediría nada.');

        $this->withToken($this->tokenDelPersonalLlano())
            ->postJson(self::RUTA, ['modo' => 'antiguos', 'grupo_id' => $grupo->id])
            ->assertStatus(200);

        $fila = DB::selectOne('SELECT codigo, alumno_id FROM ordenes_inscripcion
            WHERE modo="antiguos" AND alumno_id IS NOT NULL ORDER BY id LIMIT 1');

        $this->assertNotNull($fila, 'La renovación no ató ningún alumno.');

        return $fila;
    }

    /**
     * Un alumno vivo que **no** tiene orden de la campaña.
     *
     * El filtro no es una precaución: los tests que llaman a esto miden el camino de
     * «atar algo libre», y un alumno que ya tuviera la suya los haría pasar por el
     * 409 y **seguir en verde midiendo otra cosa**.
     *
     * @param  list<int>  $salvo
     */
    private function unAlumnoSinOrden(array $salvo = []): object
    {
        $fuera = count($salvo) > 0
            ? ' AND a.id NOT IN ('.implode(',', array_map('intval', $salvo)).')'
            : '';

        $alumno = DB::selectOne('SELECT a.id FROM alumnos a
            LEFT JOIN ordenes_inscripcion o ON o.alumno_id=a.id AND o.year_campana=2026
            WHERE a.deleted_at IS NULL AND o.id IS NULL'.$fuera.'
            ORDER BY a.id LIMIT 1');

        $this->assertNotNull($alumno, 'El seed no tiene alumnos libres: esto no mediría nada.');

        return $alumno;
    }

    /**
     * **Monta el año de la campaña, porque el seed no lo tiene** — que es el caso
     * real, no una carencia del seed: el colegio abre la campaña en septiembre y
     * crea el año nuevo después.
     *
     * Se clona la fila del año actual en vez de escribirla campo a campo: `years`
     * tiene decenas de columnas `NOT NULL` y una lista literal se quedaría corta en
     * silencio el día que entre una columna nueva.
     *
     * @return object{alumno_id: int, matricula_id: int}
     */
    private function unAlumnoMatriculadoEnLaCampana(): object
    {
        $actual = DB::selectOne('SELECT id FROM years WHERE actual=1 AND deleted_at IS NULL');

        DB::statement('CREATE TEMPORARY TABLE anio_nuevo AS
            SELECT * FROM years WHERE id=?', [$actual->id]);
        DB::statement('UPDATE anio_nuevo SET id=NULL, year=2026, actual=0');
        DB::statement('INSERT INTO years SELECT * FROM anio_nuevo');
        $yearNuevo = (int) DB::getPdo()->lastInsertId();

        $grupoViejo = DB::selectOne('SELECT g.id FROM grupos g
            WHERE g.year_id=? AND g.deleted_at IS NULL LIMIT 1', [$actual->id]);

        DB::statement('CREATE TEMPORARY TABLE grupo_nuevo AS
            SELECT * FROM grupos WHERE id=?', [$grupoViejo->id]);
        DB::statement('UPDATE grupo_nuevo SET id=NULL, year_id=?', [$yearNuevo]);
        DB::statement('INSERT INTO grupos SELECT * FROM grupo_nuevo');
        $grupoNuevo = (int) DB::getPdo()->lastInsertId();

        $alumno = $this->unAlumnoSinOrden();

        DB::insert('INSERT INTO matriculas (alumno_id, grupo_id, estado, created_at, updated_at)
            VALUES (?,?,"MATR",NOW(),NOW())', [$alumno->id, $grupoNuevo]);

        return (object) [
            'alumno_id' => (int) $alumno->id,
            'matricula_id' => (int) DB::getPdo()->lastInsertId(),
        ];
    }

    /**
     * Alguien que pase `puedeAtarFormularios`.
     *
     * Superusuario por lo mismo que en la bandeja del tesorero: `esAdministrativo`
     * es `is_superuser || isSecretario`, y **el rol `Secretario` no tiene titulares**
     * en el seed, así que un test que dependiera de él no mediría nada hoy.
     */
    private function tokenQuePuedeAtar(): string
    {
        if ($this->tokenAtar !== null) {
            return $this->tokenAtar;
        }

        $super = DB::selectOne('SELECT username FROM users
            WHERE is_superuser=1 AND deleted_at IS NULL AND is_active=1 LIMIT 1');

        $this->assertNotNull($super, 'El seed no tiene superusuarios: esto no mediría nada.');

        return $this->tokenAtar = $this->tokenDe($super->username);
    }

    /** Ver `$token`: el mismo, memorizado, para no gastar logins del limitador. */
    protected function tokenDelPersonalLlano(): string
    {
        return $this->token ??= parent::tokenDelPersonalLlano();
    }

    private function cuantasOrdenes(): int
    {
        return (int) DB::selectOne('SELECT COUNT(*) c FROM ordenes_inscripcion')->c;
    }
}
