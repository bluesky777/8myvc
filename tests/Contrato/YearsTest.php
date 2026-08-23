<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * El año lectivo del colegio: cuál es el actual, y qué se puede cambiar de él.
 *
 * `YearsController` era el hueco más grande de la medición de cobertura del 20 de
 * agosto —4 de 19 rutas comprobadas— y de él cuelga lo que decide en qué año
 * trabaja todo el mundo: `Services\Login` mete a cada usuario en el periodo
 * `actual` del año `actual` en cada inicio de sesión.
 *
 * Ver docs/migracion/05-codigo-muerto-y-roto.md §28.
 */
class YearsTest extends CasoDeContrato
{
    private function tokenDelPersonal(): string
    {
        return $this->tokenDe($this->usuarioDeTipo('Usuario')->username);
    }

    /** @return list<int> Los años marcados como actuales, en orden. */
    private function actuales(): array
    {
        // Filtrando la papelera: un año borrado no cuenta para nadie que lea el
        // año actual, y contarlo aquí haría fallar los tests por una fila que el
        // sistema no mira. Que además esa fila exista —2026, borrada y con
        // `actual=1`— es lo que arregla `deleteDelete`.
        return array_values(array_map('intval', DB::table('years')->where('actual', 1)
            ->whereNull('deleted_at')->orderBy('id')->pluck('id')->all()));
    }

    /**
     * Lo que manda el front al crear un año, que no es un formulario en blanco.
     *
     * `YearsCtrl.fixControles` copia del último año los nombres de unidad y
     * subunidad —que son NOT NULL en el esquema— y fija `actual: true`. Escribir
     * aquí solo el año y el nombre da un 500 de restricción, que es un test que
     * mide la base y no el endpoint.
     */
    private function cuerpoDeAnioNuevo(int $year, bool $actual): array
    {
        $ultimo = DB::selectOne('SELECT * FROM years WHERE deleted_at IS NULL ORDER BY year DESC LIMIT 1');

        return [
            'year' => $year,
            'actual' => $actual,
            'nombre_colegio' => $ultimo->nombre_colegio,
            'abrev_colegio' => $ultimo->abrev_colegio,
            'nota_minima_aceptada' => $ultimo->nota_minima_aceptada,
            'resolucion' => $ultimo->resolucion,
            'codigo_dane' => $ultimo->codigo_dane,
            'encabezado_certificado' => $ultimo->encabezado_certificado,
            'telefono' => $ultimo->telefono,
            'celular' => $ultimo->celular,
            'unidad_displayname' => $ultimo->unidad_displayname,
            'unidades_displayname' => $ultimo->unidades_displayname,
            'genero_unidad' => $ultimo->genero_unidad,
            'subunidad_displayname' => $ultimo->subunidad_displayname,
            'subunidades_displayname' => $ultimo->subunidades_displayname,
            'genero_subunidad' => $ultimo->genero_subunidad,
            'website' => $ultimo->website,
            'website_myvc' => $ultimo->website_myvc,
            'alumnos_can_see_notas' => $ultimo->alumnos_can_see_notas,
        ];
    }

    /**
     * Destildar la casilla apaga el año, y antes lo encendía.
     *
     * `putSetActual` hacía `$year->actual = 1` pasara lo que pasara y devolvía
     * «Ahora NO es año actual». El front es una casilla por año con
     * `ng-false-value="0"`, así que quien la apagaba creía haberla apagado y
     * dejaba **un año actual de más**.
     */
    public function test_destildar_la_casilla_apaga_el_ano(): void
    {
        $token = $this->tokenDelPersonal();

        $apagado = DB::selectOne('SELECT id FROM years
            WHERE actual = 0 AND deleted_at IS NULL ORDER BY id LIMIT 1');
        $this->assertNotNull($apagado, 'El seed no tiene ningún año apagado.');

        $antes = $this->actuales();

        $r = $this->withToken($token)->putJson('/api/years/set-actual',
            ['year_id' => $apagado->id, 'can' => false]);

        $r->assertStatus(200);
        $this->assertSame('Ahora NO es año actual', $r->getContent());
        $this->assertSame(0, (int) DB::table('years')->where('id', $apagado->id)->value('actual'),
            'El año quedó marcado como actual después de decir que no lo es.');
        $this->assertSame($antes, $this->actuales(),
            'Apagar un año que ya estaba apagado cambió la lista de años actuales.');
    }

    /** Y tildarla lo enciende a él y apaga a los demás, que es lo que ya hacía. */
    public function test_tildar_la_casilla_deja_uno_solo(): void
    {
        $token = $this->tokenDelPersonal();

        $apagado = DB::selectOne('SELECT id FROM years
            WHERE actual = 0 AND deleted_at IS NULL ORDER BY id LIMIT 1');

        $r = $this->withToken($token)->putJson('/api/years/set-actual',
            ['year_id' => $apagado->id, 'can' => true]);

        $r->assertStatus(200);
        $this->assertSame('Ahora es año actual.', $r->getContent());
        $this->assertSame([(int) $apagado->id], $this->actuales(),
            'Encender un año debe dejar exactamente uno encendido.');
    }

    /**
     * Y apagar el último deja cero, que es lo que pidió quien lo apagó.
     *
     * Cero años actuales no rompe el inicio de sesión:
     * `Login::ponerEnElPeriodoActual` devuelve `null` cuando la lista viene vacía
     * y deja a cada usuario en el periodo donde estaba. Se comprueba, porque «no
     * pasa nada» es justo lo que hay que medir antes de dejar que pase.
     */
    public function test_apagar_el_ultimo_deja_cero_y_se_puede_entrar(): void
    {
        $token = $this->tokenDelPersonal();

        // **El sujeto se resuelve ANTES de apagar los años, y el orden es el arreglo.**
        // `usuarioLlanoDelPersonal()` exige `years.actual = 1` —para no mudar de año al
        // repuntar los tests, §157— y este test **apaga todos los años actuales dos
        // líneas más abajo, que es justo lo que viene a comprobar**. Resolverlo después
        // deja al ayudante sin ninguno y falla con su propio mensaje.
        //
        // Ni el ayudante ni el test están mal: **cada uno mira una mitad, y lo que
        // choca es el orden entre los dos**. No se ve leyendo ninguno de los dos, sólo
        // ejecutándolos juntos — por eso salió en la corrida de cierre y no antes.
        $usuario = $this->usuarioLlanoDelPersonal();

        foreach ($this->actuales() as $id) {
            $this->withToken($token)->putJson('/api/years/set-actual',
                ['year_id' => $id, 'can' => false])->assertStatus(200);
        }

        $this->assertSame([], $this->actuales());

        $periodoAntes = DB::table('users')->where('id', $usuario->id)->value('periodo_id');

        $this->postJson('/api/login/credentials', [
            'username' => $usuario->username, 'password' => self::CLAVE,
        ])->assertStatus(200);

        $this->assertSame($periodoAntes,
            DB::table('users')->where('id', $usuario->id)->value('periodo_id'),
            'Sin año actual, entrar no debe mover a nadie de periodo.');
    }

    /**
     * Crear un año pidiendo que no sea el actual no lo enciende.
     *
     * El front manda siempre `actual: true` (`YearsCtrl.fixControles`), así que
     * esto no cambia la pantalla: cierra el segundo camino por el que aparecían
     * años actuales de más.
     */
    public function test_crear_un_ano_respeta_lo_que_se_pide(): void
    {
        $token = $this->tokenDelPersonal();
        $siguiente = ((int) DB::table('years')->max('year')) + 1;

        $antes = $this->actuales();

        $r = $this->withToken($token)->postJson('/api/years/store',
            $this->cuerpoDeAnioNuevo($siguiente, false));

        // 200 y no el 201 de `opciones/add-opcion`, aunque las dos devuelvan un
        // modelo recién creado: Laravel pone el 201 mirando `wasRecentlyCreated`,
        // y aquí el controlador vuelve a buscar el año con `Year::find()` antes
        // de devolverlo. La instancia que sale ya no sabe que acaba de nacer.
        $r->assertStatus(200);
        $this->assertSame(0, (int) DB::table('years')->where('id', $r->json('id'))->value('actual'));
        $this->assertSame($antes, $this->actuales(),
            'Crear un año que no es el actual no debe tocar cuál lo es.');

        // Y el año nuevo nace con su primer periodo, que es lo que lo hace usable.
        $this->assertSame(1, DB::table('periodos')->where('year_id', $r->json('id'))->count());
    }

    /** Y pidiéndolo actual, se queda solo él. */
    public function test_crear_un_ano_actual_apaga_a_los_demas(): void
    {
        $token = $this->tokenDelPersonal();
        $siguiente = ((int) DB::table('years')->max('year')) + 1;

        $r = $this->withToken($token)->postJson('/api/years/store',
            $this->cuerpoDeAnioNuevo($siguiente, true));

        $r->assertStatus(200);
        $this->assertSame([(int) $r->json('id')], $this->actuales());
    }

    /** El usuario se muda de año, y eso es otra cosa que el año del colegio. */
    public function test_useractive_muda_al_usuario_y_no_al_colegio(): void
    {
        $usuario = $this->usuarioLlanoDelPersonal();
        $token = $this->tokenDe($usuario->username);

        $suyo = DB::table('users')->where('id', $usuario->id)->value('periodo_id');
        $actualesAntes = $this->actuales();

        $otro = DB::selectOne('SELECT DISTINCT y.id FROM years y
            INNER JOIN periodos p ON p.year_id = y.id AND p.deleted_at IS NULL
            WHERE y.deleted_at IS NULL AND y.id <> (SELECT year_id FROM periodos WHERE id = ?)
            ORDER BY y.id LIMIT 1', [$suyo]);

        $r = $this->withToken($token)->putJson('/api/years/useractive/'.$otro->id, []);

        $r->assertStatus(200);
        $this->assertSame((int) $otro->id, (int) $r->json('year_id'));
        $this->assertSame((int) $r->json('id'),
            (int) DB::table('users')->where('id', $usuario->id)->value('periodo_id'));
        $this->assertSame($actualesAntes, $this->actuales(),
            'Mudar a un usuario de año no debe cambiar el año del colegio.');
    }

    /** Un año sin ningún periodo no es un sitio al que mudarse. */
    public function test_useractive_a_un_ano_sin_periodos_es_400(): void
    {
        $usuario = $this->usuarioDeTipo('Usuario');
        $token = $this->tokenDe($usuario->username);

        $siguiente = ((int) DB::table('years')->max('year')) + 1;
        DB::table('years')->insert(['year' => $siguiente, 'actual' => 0,
            'created_at' => now(), 'updated_at' => now()]);
        $vacio = DB::getPdo()->lastInsertId();

        $antes = DB::table('users')->where('id', $usuario->id)->value('periodo_id');

        $this->withToken($token)->putJson('/api/years/useractive/'.$vacio, [])->assertStatus(400);

        $this->assertSame($antes, DB::table('users')->where('id', $usuario->id)->value('periodo_id'));
    }

    /** La papelera de años: borrar, verla y volver. */
    public function test_el_ano_va_a_la_papelera_y_vuelve(): void
    {
        $token = $this->tokenDelPersonal();

        $year = DB::selectOne('SELECT id FROM years
            WHERE actual = 0 AND deleted_at IS NULL ORDER BY id DESC LIMIT 1');

        $enPapelera = fn () => count($this->withToken($token)->getJson('/api/years/trashed')->json());
        $antes = $enPapelera();

        $this->withToken($token)->deleteJson('/api/years/delete/'.$year->id)->assertStatus(200);
        $this->assertNotNull(DB::table('years')->where('id', $year->id)->value('deleted_at'));
        $this->assertSame($antes + 1, $enPapelera());

        $this->withToken($token)->putJson('/api/years/restore/'.$year->id, [])->assertStatus(200);
        $this->assertNull(DB::table('years')->where('id', $year->id)->value('deleted_at'));
        $this->assertSame($antes, $enPapelera());
    }

    /**
     * Un año que va a la papelera deja de ser el actual.
     *
     * En la base hay un año así —2026, borrado, con `actual=1`—, y hoy no se ve
     * porque todo lo que lee el año actual filtra `deleted_at`. La trampa es
     * `years/restore/{id}`: lo devolvería encendido al lado del que lo esté, y
     * con dos encendidos `Login::ponerEnElPeriodoActual` se queda con el primero
     * que devuelva MySQL, sin `ORDER BY`. O sea que en qué año entra todo el
     * colegio lo decidiría el orden de las filas.
     *
     * El test no comprueba la línea, comprueba la trampa: borra el año actual,
     * lo restaura, y exige que no haya dos.
     */
    public function test_un_ano_en_la_papelera_no_vuelve_siendo_el_actual(): void
    {
        $token = $this->tokenDelPersonal();

        $actual = $this->actuales();
        $this->assertCount(1, $actual, 'El seed debería tener un solo año actual vivo.');
        $actual = $actual[0];

        $this->withToken($token)->deleteJson('/api/years/delete/'.$actual)->assertStatus(200);
        $this->assertSame(0, (int) DB::table('years')->where('id', $actual)->value('actual'),
            'Un año en la papelera no puede seguir siendo el año actual del colegio.');

        // Otro pasa a ser el actual mientras aquél está en la papelera.
        $otro = DB::selectOne('SELECT id FROM years
            WHERE actual = 0 AND deleted_at IS NULL AND id <> ? ORDER BY id LIMIT 1', [$actual]);
        $this->withToken($token)->putJson('/api/years/set-actual',
            ['year_id' => $otro->id, 'can' => true])->assertStatus(200);

        $this->withToken($token)->putJson('/api/years/restore/'.$actual, [])->assertStatus(200);

        $this->assertSame([(int) $otro->id], $this->actuales(),
            'Al restaurar el año viejo quedaron dos actuales, y quién gana lo decide MySQL.');
    }

    /**
     * El borrado físico sigue siendo solo de superusuario.
     *
     * Arrastra 59 tablas por las FK en cascada, y es el borrado de mayor alcance
     * del sistema. El candado se puso en la revisión de la papelera; esto es el
     * cerrojo que impide que se caiga sin que nadie se entere.
     */
    public function test_el_borrado_fisico_es_solo_de_superusuario(): void
    {
        $profesor = $this->usuarioDeTipo('Profesor');
        $token = $this->tokenDe($profesor->username);

        $year = DB::selectOne('SELECT id FROM years
            WHERE actual = 0 AND deleted_at IS NULL ORDER BY id DESC LIMIT 1');
        DB::table('years')->where('id', $year->id)->update(['deleted_at' => now()]);

        $this->withToken($token)->deleteJson('/api/years/destroy/'.$year->id)->assertStatus(403);

        $this->assertNotNull(DB::table('years')->where('id', $year->id)->value('deleted_at'),
            'La fila debe seguir ahí, solo en la papelera.');
    }

    /**
     * Crear un año copia la disciplina del anterior, y esas filas ya nacen con fecha.
     *
     * Los dos `INSERT` de `postStore` eran los únicos de las **cuatro** escrituras que
     * hay en `dis_configuraciones` y `dis_ordinales` que no ponían `created_at`; los
     * otros tres —`GruposController:265` y los dos de `OrdinalesController`— sí. Lo
     * encontró el lote B leyendo lo suyo y no lo tocó, porque el fichero es de éste.
     *
     * Como sólo corre al crear un año, la fila mal nacía **una vez por año y por
     * colegio**. Las que ya están escritas están medidas y no se tocan: en el seed,
     * **14 de 17 ordinales y 7 de 9 configuraciones** tienen `created_at` nulo — o
     * sea todos los años creados por esta ruta, del 3 en adelante. Hoy no lo lee
     * nadie: los listados de disciplina ordenan por `ordinal`. Se arregla porque
     * «cuándo apareció esta fila» es la pregunta que no se puede contestar después.
     */
    public function test_el_ano_nuevo_copia_la_disciplina_con_su_fecha(): void
    {
        $token = $this->tokenDelPersonal();

        // **El año de partida se busca vivo, y no con `max(year)` a secas.** `postStore`
        // hace `Year::where('year', $nuevo - 1)->first()`, que respeta el borrado
        // suave, y el seed trae un 2026 **en la papelera**: pedir `max(year) + 1` da
        // 2027, cuyo anterior está borrado, y entonces no se copia nada de nada. El
        // test pasaba por la razón equivocada y no medía la copia.
        $ultimo = DB::selectOne('SELECT id, year FROM years WHERE deleted_at IS NULL
            ORDER BY year DESC LIMIT 1');

        $this->assertGreaterThan(0,
            DB::table('dis_ordinales')->where('year_id', $ultimo->id)->whereNull('deleted_at')->count(),
            'El año de partida tiene que traer ordinales, o esto no copia nada.');

        // 200 y no 201, por lo que explica `test_crear_un_ano_respeta_lo_que_se_pide`.
        $r = $this->withToken($token)->postJson('/api/years/store',
            $this->cuerpoDeAnioNuevo(((int) $ultimo->year) + 1, false));
        $r->assertStatus(200);
        $nuevo = (int) $r->json('id');

        foreach (['dis_ordinales', 'dis_configuraciones'] as $tabla) {
            $filas = DB::table($tabla)->where('year_id', $nuevo)->get();
            $this->assertNotEmpty($filas, "El año nuevo no copió ninguna fila de {$tabla}.");

            foreach ($filas as $fila) {
                $this->assertNotNull($fila->created_at,
                    "Una fila de {$tabla} del año nuevo nació sin created_at.");
                $this->assertNotNull($fila->updated_at,
                    "Una fila de {$tabla} del año nuevo nació sin updated_at.");
            }
        }
    }

    /**
     * Y lo que se mide y **no** se arregla: si el año anterior está en la papelera,
     * el año nuevo nace **vacío** y contesta 200 igual.
     *
     * `postStore` busca el anterior con `Year::where('year', $nuevo - 1)->first()`,
     * que respeta el borrado suave. Si no lo encuentra —porque no existe o porque
     * está borrado— se salta el bloque entero: ni configuración copiada, ni
     * disciplina, ni grupos, ni asignaturas, ni escalas. El colegio se queda con un
     * año que hay que configurar entero a mano y nadie se lo dice.
     *
     * No se arregla aquí porque la pregunta es del colegio: copiar desde un año que
     * alguien mandó a la papelera puede ser justo lo que no se quiere. Se fija lo que
     * hace hoy para que se decida sobre un dato y no sobre una impresión.
     */
    public function test_si_el_ano_anterior_esta_en_la_papelera_el_nuevo_nace_vacio(): void
    {
        $token = $this->tokenDelPersonal();
        $ultimo = DB::selectOne('SELECT id, year FROM years WHERE deleted_at IS NULL
            ORDER BY year DESC LIMIT 1');

        DB::table('years')->where('id', $ultimo->id)->update(['deleted_at' => now()]);

        $r = $this->withToken($token)->postJson('/api/years/store',
            $this->cuerpoDeAnioNuevo(((int) $ultimo->year) + 1, false));
        $r->assertStatus(200);
        $nuevo = (int) $r->json('id');

        foreach (['dis_ordinales', 'dis_configuraciones', 'grupos', 'escalas_de_valoracion'] as $tabla) {
            $this->assertSame(0, DB::table($tabla)->where('year_id', $nuevo)->count(),
                "Con el año anterior en la papelera no se copia nada, y {$tabla} trajo filas.");
        }

        // Lo único que sí nace: su primer periodo, que va antes del bloque.
        $this->assertSame(1, DB::table('periodos')->where('year_id', $nuevo)->count());
    }

    /** Los conmutadores del boletín guardan lo que dicen que guardan. */
    public function test_los_conmutadores_guardan_lo_que_dicen(): void
    {
        $token = $this->tokenDelPersonal();
        $year = DB::selectOne('SELECT id FROM years WHERE deleted_at IS NULL ORDER BY id LIMIT 1');

        $conmutadores = [
            'years/alumnos-can-see-notas' => 'alumnos_can_see_notas',
            'years/profes-can-edit-alumnos' => 'profes_can_edit_alumnos',
            'years/mostrar-todas-materias' => 'show_materias_todas',
            'years/toggle-mostrar-puestos-en-boletin' => 'mostrar_puesto_boletin',
            'years/toggle-mostrar-nota-comport-en-boletin' => 'mostrar_nota_comport_boletin',
            'years/toggle-mostrar-anio-pasado-en-boletin' => 'year_pasado_en_bol',
            'years/toggle-solo-valorativas' => 'solo_escalas_valorativas',
            'years/toggle-ignorar-notas-perdidas' => 'si_recupera_materia_recup_indicador',
        ];

        foreach ($conmutadores as $ruta => $columna) {
            foreach ([1, 0] as $valor) {
                $this->withToken($token)->putJson('/api/'.$ruta,
                    ['year_id' => $year->id, 'can' => $valor])->assertStatus(200);

                $this->assertSame($valor,
                    (int) DB::table('years')->where('id', $year->id)->value($columna),
                    "{$ruta} no dejó {$columna} en {$valor}.");
            }
        }
    }

    /**
     * **§93. Un campo que no se manda no es un campo que no cambia: es un campo
     * que se pisa** — y aquí lo que se pisa es la identidad del colegio.
     *
     * `putGuardarCambios` escribía las veintiuna columnas con
     * `Request::input('x')` a secas, así que un `PUT {"id": 1}` de una línea
     * dejaba el año **sin nombre de colegio, sin resolución, sin código DANE, sin
     * rector y sin los nombres de unidad y subunidad** —que salen impresos en el
     * boletín de todos los alumnos— y contestaba **200**. Es la misma familia que
     * la [§68](../../docs/migracion/05-codigo-muerto-y-roto.md), con la diferencia
     * de que allí el que omitía el campo era una pantalla y aquí no lo es ninguna:
     * es una ruta que cualquiera de los 51 profesores alcanza con `auth.personal`.
     *
     * **Se eligió conservar los ausentes y no contestar 422**, y no a ojo: los tres
     * repos de cliente están al lado y sólo uno la llama —`YearsApi.guardarCambios`
     * desde `YearsCtrl.guardar_cambios`, con el objeto `year` entero que vino de
     * `years/colegio`—. Un 422 rompería a cualquier colegio cuya copia de
     * `myvc_front` sea más vieja y mande veinte de los veintiún campos; conservar
     * los ausentes no puede romper a nadie, porque quien los manda todos escribe
     * exactamente lo mismo que antes.
     */
    public function test_guardar_cambios_conserva_lo_que_el_cuerpo_no_trae(): void
    {
        $token = $this->tokenDelPersonal();

        // `compromiso_familiar_label` viene nula del seed, y una columna que ya vale
        // null no distingue «se conservó» de «se pisó»: con ella sola el test pasaba
        // igual con el arreglo revertido. Se le pone un valor antes de medir.
        DB::table('years')->whereNull('deleted_at')->orderBy('id')->limit(1)
            ->update(['compromiso_familiar_label' => 'Compromiso de familia']);

        $antes = DB::selectOne('SELECT * FROM years WHERE deleted_at IS NULL ORDER BY id LIMIT 1');
        $this->assertSame('Compromiso de familia', $antes->compromiso_familiar_label);

        $r = $this->withToken($token)->putJson('/api/years/guardar-cambios', ['id' => $antes->id]);
        $r->assertStatus(200);

        $despues = DB::selectOne('SELECT * FROM years WHERE id = ?', [$antes->id]);

        // Las que imprime el boletín y el certificado, que son las caras.
        foreach (['year', 'nombre_colegio', 'abrev_colegio', 'resolucion', 'codigo_dane',
            'rector_id', 'secretario_id', 'tesorero_id', 'telefono', 'celular', 'website',
            'website_myvc', 'unidad_displayname', 'unidades_displayname', 'genero_unidad',
            'subunidad_displayname', 'subunidades_displayname', 'genero_subunidad',
            'alumnos_can_see_notas', 'msg_when_students_blocked',
            'compromiso_familiar_label'] as $columna) {
            $this->assertSame($antes->$columna, $despues->$columna,
                "Un cuerpo que no trae `{$columna}` la dejó en ".var_export($despues->$columna, true).'.');
        }
    }

    /**
     * Y mandarla vacía **sí** la borra: el defecto tapa la clave ausente, no la que
     * llega. Es la mitad que distingue este arreglo de un `?? ` mal puesto, que
     * dejaría al colegio sin forma de quitar un campo desde la pantalla.
     */
    public function test_mandar_el_compromiso_vacio_si_lo_borra(): void
    {
        $token = $this->tokenDelPersonal();
        DB::table('years')->whereNull('deleted_at')->orderBy('id')->limit(1)
            ->update(['compromiso_familiar_label' => 'Compromiso de familia']);
        $year = DB::selectOne('SELECT * FROM years WHERE deleted_at IS NULL ORDER BY id LIMIT 1');

        $this->withToken($token)->putJson('/api/years/guardar-cambios',
            ['id' => $year->id, 'compromiso_familiar_label' => ''])->assertStatus(200);

        $this->assertNull(DB::table('years')->where('id', $year->id)->value('compromiso_familiar_label'));
    }

    /** Y lo que el cuerpo sí trae se guarda, que es la otra mitad del arreglo. */
    public function test_guardar_cambios_escribe_lo_que_el_cuerpo_trae(): void
    {
        $token = $this->tokenDelPersonal();
        $antes = DB::selectOne('SELECT * FROM years WHERE deleted_at IS NULL ORDER BY id LIMIT 1');

        $this->withToken($token)->putJson('/api/years/guardar-cambios', [
            'id' => $antes->id,
            'nombre_colegio' => 'COLEGIO DE PRUEBA',
            'telefono' => '6040000',
            'unidad_displayname' => 'Logro',
        ])->assertStatus(200);

        $despues = DB::selectOne('SELECT * FROM years WHERE id = ?', [$antes->id]);
        $this->assertSame('COLEGIO DE PRUEBA', $despues->nombre_colegio);
        $this->assertSame('6040000', $despues->telefono);
        $this->assertSame('Logro', $despues->unidad_displayname);
        // Y el resto sigue donde estaba.
        $this->assertSame($antes->codigo_dane, $despues->codigo_dane);
        $this->assertSame($antes->resolucion, $despues->resolucion);
    }

    /**
     * **§93.2. Lo que no se arregla: un `null` explícito no llega `null` a la fila,
     * y la respuesta dice que sí.**
     *
     * `config/database.php` pone `'strict' => false` en las dos conexiones, o sea
     * que la sesión de MySQL corre con `sql_mode = NO_ENGINE_SUBSTITUTION` — medido,
     * no supuesto, y **es el mismo modo en producción**, porque el valor lo fija
     * Laravel al conectar y no el servidor. Con eso, escribir `null` en una columna
     * `NOT NULL` **no falla**: MySQL guarda `''` o `0` y avisa. Y el método devuelve
     * el modelo en memoria, que sí tiene el `null`.
     *
     * O sea que **la respuesta contradice a la fila**: dice `nombre_colegio: null`
     * donde la tabla tiene `''`. Se deja fijado y no se toca, porque cambiarlo es
     * encender el modo estricto para las 990 consultas crudas del proyecto —eso lo
     * decide Joseth, está anotado— o releer la fila antes de responder, que es un
     * cambio de contrato para el único cliente que la llama.
     */
    public function test_un_null_explicito_no_llega_null_a_la_fila(): void
    {
        $token = $this->tokenDelPersonal();
        $year = DB::selectOne('SELECT * FROM years WHERE deleted_at IS NULL ORDER BY id LIMIT 1');

        $r = $this->withToken($token)->putJson('/api/years/guardar-cambios', [
            'id' => $year->id,
            'nombre_colegio' => null,
            'year' => null,
            'abrev_colegio' => null,
        ]);
        $r->assertStatus(200);

        $this->assertNull($r->json('nombre_colegio'), 'La respuesta devuelve el modelo en memoria.');

        $fila = DB::selectOne('SELECT * FROM years WHERE id = ?', [$year->id]);
        $this->assertSame('', $fila->nombre_colegio, 'NOT NULL sin modo estricto guarda la cadena vacía.');
        $this->assertSame(0, (int) $fila->year, 'Y un entero NOT NULL guarda 0.');
        // La nulable sí queda nula: la diferencia la pone el esquema, no el código.
        $this->assertNull($fila->abrev_colegio);
    }

    /**
     * **§94. El conmutador genérico no puede encender un segundo año actual.**
     *
     * `years/toggle-cambiar-valor` es el «guardar un campo suelto» de la rejilla:
     * recibe `{year_id, campo, valor}` y escribe la columna que le digan, con
     * `ColumnaSegura` impidiendo la inyección pero **no** limitando cuál. Entre las
     * columnas de `years` está `actual`, que tiene invariante —uno solo— y una ruta
     * propia que lo mantiene, `years/set-actual`, la que fija
     * `test_tildar_la_casilla_deja_uno_solo` justo aquí arriba.
     *
     * Medido antes de arreglarlo: `{campo: 'actual', valor: 1}` sobre 2018 dejaba
     * **2018 y 2025 encendidos a la vez**. Y eso no se queda en la fila:
     * `Services\Login::ponerEnElPeriodoActual` hace `SELECT ... WHERE actual=1` y se
     * queda con **el primero, sin `ORDER BY`** —o sea el de id más bajo, 2018—, así
     * que el siguiente inicio de sesión de **todo el colegio** muda a los usuarios a
     * un año de hace ocho. Es la [§28](../../docs/migracion/05-codigo-muerto-y-roto.md)
     * otra vez, alcanzada por otra puerta.
     */
    public function test_el_conmutador_generico_no_enciende_el_ano_actual(): void
    {
        $token = $this->tokenDelPersonal();
        $antes = $this->actuales();
        $this->assertNotEmpty($antes, 'El seed tiene que traer un año actual para que esto mida algo.');

        $apagado = DB::selectOne('SELECT id FROM years
            WHERE actual = 0 AND deleted_at IS NULL ORDER BY id LIMIT 1');

        $r = $this->withToken($token)->putJson('/api/years/toggle-cambiar-valor',
            ['year_id' => $apagado->id, 'campo' => 'actual', 'valor' => 1]);

        $this->assertSame(422, $r->status(),
            'El conmutador genérico no debe poder tocar el año actual: para eso está years/set-actual.');
        $this->assertSame($antes, $this->actuales(),
            'Y sobre todo: no puede quedar más de un año actual.');
    }

    /**
     * La otra mitad, que **no** cambia: el conmutador sigue escribiendo cualquier
     * otra columna de `years`, y sigue sin dejar pasar un nombre que no lo sea.
     *
     * No es lo que promete el nombre de la ruta —es un «guardar campo» genérico,
     * como `asignaturas/toggle-dia`—, pero no es un agujero: quien pasa
     * `auth.personal` ya escribe esas mismas columnas por `years/guardar-cambios`.
     * Se fija para que el arreglo del `actual` no se lea como «esta ruta está
     * limitada»: no lo está, y el día que alguien apoye un permiso en eso se
     * llevará una sorpresa.
     */
    public function test_el_conmutador_generico_escribe_las_demas_columnas(): void
    {
        $token = $this->tokenDelPersonal();
        $year = DB::selectOne('SELECT id FROM years WHERE deleted_at IS NULL ORDER BY id LIMIT 1');

        $this->withToken($token)->putJson('/api/years/toggle-cambiar-valor',
            ['year_id' => $year->id, 'campo' => 'codigo_dane', 'valor' => '999'])
            ->assertStatus(200);

        $this->assertSame('999', DB::table('years')->where('id', $year->id)->value('codigo_dane'));

        foreach (['no_existe', 'codigo_dane = 1, actual', 'deleted_at', 'id'] as $campo) {
            $r = $this->withToken($token)->putJson('/api/years/toggle-cambiar-valor',
                ['year_id' => $year->id, 'campo' => $campo, 'valor' => '1']);
            $this->assertSame(422, $r->status(), "ColumnaSegura dejó pasar `{$campo}`.");
        }

        $this->assertSame('999', DB::table('years')->where('id', $year->id)->value('codigo_dane'),
            'Ninguno de los cuatro intentos debe haber escrito nada.');
    }

    /** Un año que no existe no escribe nada y lo dice, que es lo que hacía ya. */
    public function test_el_conmutador_generico_con_un_ano_inventado(): void
    {
        $token = $this->tokenDelPersonal();
        $inventado = ((int) DB::table('years')->max('id')) + 1000;

        $r = $this->withToken($token)->putJson('/api/years/toggle-cambiar-valor',
            ['year_id' => $inventado, 'campo' => 'codigo_dane', 'valor' => '1']);

        $r->assertStatus(200);
        $this->assertSame('No guardado', $r->getContent(),
            'Contesta 200 con un texto: es lo que hay, y el front lo pinta tal cual.');
    }

    /** Una familia no toca la configuración del colegio: son de `auth.personal`. */
    public function test_una_familia_no_toca_la_configuracion_del_colegio(): void
    {
        $year = DB::selectOne('SELECT id, actual FROM years WHERE deleted_at IS NULL ORDER BY id LIMIT 1');

        foreach (['Alumno', 'Acudiente'] as $tipo) {
            $token = $this->tokenDe($this->usuarioDeTipo($tipo)->username);

            $this->withToken($token)->putJson('/api/years/set-actual',
                ['year_id' => $year->id, 'can' => true])->assertStatus(403);
            $this->withToken($token)->putJson('/api/years/alumnos-can-see-notas',
                ['year_id' => $year->id, 'can' => true])->assertStatus(403);
            $this->withToken($token)->deleteJson('/api/years/delete/'.$year->id)->assertStatus(403);
        }

        $this->assertSame((int) $year->actual,
            (int) DB::table('years')->where('id', $year->id)->value('actual'));
    }
}
