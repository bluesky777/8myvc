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

        // Y el año nuevo nace con sus cuatro periodos, que es lo que lo hace usable.
        // Era **uno** hasta el 30 ago 2026; lo que traen dentro lo mide
        // `test_el_ano_nuevo_nace_con_cuatro_periodos`.
        $this->assertSame(4, DB::table('periodos')->where('year_id', $r->json('id'))->count());
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

        // Lo único que sí nace: sus cuatro periodos, que están fuera del bloque. Eran
        // **uno** hasta el 30 ago 2026, y sin fechas; ahora son cuatro y con las que
        // calcula `CalendarioDePeriodos` — sin año del que copiar, calendario A, que
        // es el defecto del esquema.
        $this->assertSame(4, DB::table('periodos')->where('year_id', $nuevo)->count());
        $this->assertSame(0, DB::table('periodos')->where('year_id', $nuevo)
            ->where(fn ($q) => $q->whereNull('fecha_inicio')->orWhereNull('fecha_fin'))->count(),
            'Un año creado sin anterior del que copiar también tiene que traer sus cuatro fechas.');
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

    /**
     * El rastro apunta a la fila que se guardó, no al id que mandó el cliente.
     *
     * `putGuardarCambios` era **el único de los diez escritores de bitácora** que
     * derivaba el sujeto de la fila del **cuerpo** —`affected_element_id =
     * Request::input('id')`— en vez de la fila leída. Los otros nueve ya usan
     * `$nota->alumno_id` o `$subunidad->id`, que es la lección de la §50 del
     * [05](../../docs/migracion/05-codigo-muerto-y-roto.md): *«¿qué MÁS lee este
     * identificador del cuerpo?»*. Medido en
     * [med-2.md](../../docs/migracion/noche-2026-08-24/med-2.md).
     *
     * ## Lo que este test prueba y lo que NO, dicho antes de que alguien lo cite
     *
     * Prueba que **el rastro apunta a la fila escrita**, que es el contrato que la
     * fase 4 va a necesitar en los siete dominios.
     *
     * **No** prueba el arreglo: con el código viejo también pasaba. Y no por estar
     * mal escrito, sino porque `config/database.php` lleva `strict => false`, así
     * que un `id` no numérico se convierte **en silencio** al entrar en la columna
     * `int` y las dos formas guardan el mismo número. **No hay ningún cuerpo
     * alcanzable hoy que las distinga**, y se buscó: con espacios, con ceros
     * delante, con decimales y con texto detrás.
     *
     * Lo que el arreglo quita es un fallo **latente**: con el modo estricto puesto
     * —un endurecimiento razonable y no descartado— la versión vieja lanzaría
     * **después** de `$year->save()`, y como el `catch` contesta `abort(422)`, el
     * año quedaría cambiado, el cliente leería «Datos incorrectos» y del rastro no
     * quedaría nada. Eso no se puede comprobar sin tocar la configuración de la
     * conexión, y tocarla en un test mediría otra base que la de producción.
     *
     * O sea: este test es la red que impide que alguien lo devuelva al cuerpo, no
     * la prueba de que hacía falta cambiarlo.
     */
    public function test_la_bitacora_del_ano_apunta_a_la_fila_guardada(): void
    {
        $token = $this->tokenDelPersonal();
        $year = DB::selectOne('SELECT * FROM years WHERE deleted_at IS NULL ORDER BY id LIMIT 1');

        $antes = DB::table('bitacoras')->where('affected_element_type', 'YEAR CONFIGURACION')->count();

        $this->withToken($token)->putJson('/api/years/guardar-cambios', [
            'id' => $year->id,
            'telefono' => '6012345678',
        ])->assertStatus(200);

        $linea = DB::selectOne(
            "SELECT * FROM bitacoras WHERE affected_element_type = 'YEAR CONFIGURACION'
             ORDER BY id DESC LIMIT 1"
        );

        $this->assertSame($antes + 1,
            DB::table('bitacoras')->where('affected_element_type', 'YEAR CONFIGURACION')->count(),
            'La llamada tiene que dejar UNA línea de bitácora. Si no la deja, lo que este '
            .'test comprueba abajo es una fila vieja de otra corrida.');

        $this->assertSame((int) $year->id, (int) $linea->affected_element_id,
            'El rastro tiene que apuntar al año que se guardó, y ese id sale de la fila '
            .'—`$year->id`—, nunca de `Request::input(\'id\')`.');
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

    /** Los cuatro periodos del año nuevo, tal como salen de la base. @return list<object> */
    private function periodosDe(int $year_id): array
    {
        return DB::select('SELECT * FROM periodos WHERE year_id=? AND deleted_at IS NULL ORDER BY numero', [$year_id]);
    }

    /** El id del año creado a partir del último vivo. */
    private function crearElAnioSiguiente(): int
    {
        $ultimo = DB::selectOne('SELECT id, year FROM years WHERE deleted_at IS NULL ORDER BY year DESC LIMIT 1');

        $r = $this->withToken($this->tokenDelPersonal())->postJson('/api/years/store',
            $this->cuerpoDeAnioNuevo(((int) $ultimo->year) + 1, false));
        $r->assertStatus(200);

        return (int) $r->json('id');
    }

    /** Le pone al año `$year_id` cuatro periodos con las fechas dadas, y jubila los que tuviera. */
    private function ponerleElCalendario(int $year_id, array $rangos): void
    {
        // A la papelera, y no `delete()`: `users.periodo_id` cuelga de `periodos` con
        // `ON DELETE CASCADE`, así que borrar un periodo de verdad **se lleva por
        // delante a los usuarios que estén parados en él** y ahí choca contra la clave
        // ajena de `alumnos`. Y da igual para lo que se mide: `postStore` lee los del
        // año anterior filtrando `deleted_at`, que es lo mismo que hacen todos.
        DB::table('periodos')->where('year_id', $year_id)->update(['deleted_at' => now()]);

        foreach ($rangos as $i => [$inicio, $fin]) {
            DB::table('periodos')->insert([
                'numero' => $i + 1,
                'fecha_inicio' => $inicio,
                'fecha_fin' => $fin,
                'actual' => $i === 0 ? 1 : 0,
                'year_id' => $year_id,
            ]);
        }
    }

    /**
     * Crear un año crea **cuatro** periodos, y hasta el 30 ago 2026 creaba uno.
     *
     * `postStore` insertaba `numero=1, actual=1` y nada más: ni fechas, ni
     * `created_at`, ni `created_by`. El resultado está en la base del colegio del
     * seed — sus ocho años viejos tienen los cuatro periodos, puestos a mano uno a
     * uno después, y **el único año creado por esta ruta tiene uno**—, y de ahí sale
     * lo demás: `years/useractive` contesta 400 «Año sin ningún periodo» en cuanto
     * alguien borra ese único, y el acta de evaluación no puede repartir una sola
     * falta porque reparte contra `fecha_inicio` y `fecha_fin`.
     *
     * Cuatro es decisión de Joseth (30 ago 2026), no una lectura del año anterior.
     */
    public function test_el_ano_nuevo_nace_con_cuatro_periodos(): void
    {
        $nuevo = $this->crearElAnioSiguiente();
        $periodos = $this->periodosDe($nuevo);

        $this->assertCount(4, $periodos);
        $this->assertSame([1, 2, 3, 4], array_map(fn ($p) => (int) $p->numero, $periodos));

        // Uno solo actual, y el primero. Con un periodo esto se cumplía solo; con
        // cuatro hay que decirlo, porque dos actuales dejan a
        // `Login::ponerEnElPeriodoActual` eligiendo por el orden en que salgan.
        $this->assertSame([1, 0, 0, 0], array_map(fn ($p) => (int) $p->actual, $periodos));

        foreach ($periodos as $periodo) {
            $this->assertNotNull($periodo->fecha_inicio, "El periodo {$periodo->numero} nació sin fecha de inicio.");
            $this->assertNotNull($periodo->fecha_fin, "El periodo {$periodo->numero} nació sin fecha de fin.");
            $this->assertNotNull($periodo->created_at, "El periodo {$periodo->numero} nació sin created_at.");
            $this->assertNotNull($periodo->created_by, "El periodo {$periodo->numero} nació sin created_by.");
            $this->assertLessThan($periodo->fecha_fin, $periodo->fecha_inicio);
        }

        // En orden y sin solaparse: el acta de evaluación mete cada falta en el
        // periodo cuyo rango la contiene, y con dos rangos solapados la metería en el
        // primero que mire.
        for ($i = 1; $i < 4; $i++) {
            $this->assertGreaterThan($periodos[$i - 1]->fecha_fin, $periodos[$i]->fecha_inicio,
                "El periodo {$periodos[$i]->numero} empieza antes de que acabe el anterior.");
        }
    }

    /**
     * La respuesta trae los periodos, que es lo que la pantalla necesita para pintar
     * el año que acaba de crear.
     *
     * `YearsCtrl.crearNewYear` hace `$ctrl.years.push(r)` con la respuesta tal cual, y
     * `years.html` recorre `year.periodos`. Hasta hoy la respuesta no traía ninguno
     * —los creaba y no los devolvía—, así que el año recién creado aparecía en la
     * lista **sin periodos** hasta recargar la pantalla. `getIndex` sí los adjunta.
     */
    public function test_la_respuesta_de_crear_trae_los_periodos(): void
    {
        $ultimo = DB::selectOne('SELECT id, year FROM years WHERE deleted_at IS NULL ORDER BY year DESC LIMIT 1');

        $r = $this->withToken($this->tokenDelPersonal())->postJson('/api/years/store',
            $this->cuerpoDeAnioNuevo(((int) $ultimo->year) + 1, false));

        $r->assertStatus(200);
        $this->assertCount(4, $r->json('periodos'));
        $this->assertSame([1, 2, 3, 4], array_map('intval', array_column($r->json('periodos'), 'numero')));
    }

    /**
     * Si el año anterior tiene su calendario completo, se traslada: mismo día de la
     * semana y misma duración, no un `+1 año` a secas.
     *
     * Un `+1 año` literal mueve el día de la semana —365 días son 52 semanas y **un
     * día**—, así que el periodo que empezaba lunes empezaría martes, al año siguiente
     * miércoles, y al cabo de tres el año lectivo arrancaría en sábado.
     */
    public function test_los_periodos_heredan_el_calendario_del_ano_anterior(): void
    {
        $ultimo = DB::selectOne('SELECT id, year FROM years WHERE deleted_at IS NULL ORDER BY year DESC LIMIT 1');

        // Lunes a viernes las cuatro, para que el traslado se pueda comprobar a ojo.
        $rangos = [
            ['2025-01-20', '2025-03-28'],
            ['2025-03-31', '2025-06-13'],
            ['2025-07-07', '2025-09-19'],
            ['2025-09-22', '2025-11-28'],
        ];
        DB::table('years')->where('id', $ultimo->id)->update(['year' => 2025]);
        $this->ponerleElCalendario((int) $ultimo->id, $rangos);

        $r = $this->withToken($this->tokenDelPersonal())->postJson('/api/years/store',
            $this->cuerpoDeAnioNuevo(2026, false));
        $r->assertStatus(200);

        $periodos = $this->periodosDe((int) $r->json('id'));
        $this->assertCount(4, $periodos);

        foreach ($periodos as $i => $periodo) {
            [$inicio_ant, $fin_ant] = $rangos[$i];

            $inicio = new \DateTimeImmutable($periodo->fecha_inicio);
            $fin = new \DateTimeImmutable($periodo->fecha_fin);

            $this->assertSame('2026', $inicio->format('Y'), 'El periodo se quedó en el año viejo.');
            $this->assertSame((new \DateTimeImmutable($inicio_ant))->format('N'), $inicio->format('N'),
                "El periodo {$periodo->numero} cambió de día de la semana al trasladarse.");
            $this->assertSame((new \DateTimeImmutable($fin_ant))->format('N'), $fin->format('N'));

            // Y la duración exacta, al día: es lo que se pierde si cada fecha se ajusta
            // por su cuenta y una de las dos cruza el 29 de febrero y la otra no.
            $this->assertSame(
                (new \DateTimeImmutable($inicio_ant))->diff(new \DateTimeImmutable($fin_ant))->days,
                $inicio->diff($fin)->days,
                "El periodo {$periodo->numero} cambió de duración al trasladarse.");
        }
    }

    /**
     * Y si el año anterior **no** tiene fechas, se calculan. Es el caso normal: de los
     * nueve años del seed, sólo tres las tienen, y ninguno desde 2021.
     */
    public function test_sin_fechas_en_el_anterior_el_calendario_se_calcula(): void
    {
        $ultimo = DB::selectOne('SELECT id, year FROM years WHERE deleted_at IS NULL ORDER BY year DESC LIMIT 1');

        DB::table('years')->where('id', $ultimo->id)->update(['year' => 2025, 'calendario' => 'A']);
        DB::table('periodos')->where('year_id', $ultimo->id)
            ->update(['fecha_inicio' => null, 'fecha_fin' => null]);

        $r = $this->withToken($this->tokenDelPersonal())->postJson('/api/years/store',
            $this->cuerpoDeAnioNuevo(2026, false));
        $r->assertStatus(200);

        $periodos = $this->periodosDe((int) $r->json('id'));

        // Calendario A: del tercer lunes de enero al último viernes de noviembre,
        // partido en cuatro con dos semanas de receso entre el segundo y el tercero.
        $this->assertSame(
            [['2026-01-19', '2026-04-03'], ['2026-04-06', '2026-06-19'],
                ['2026-07-06', '2026-09-18'], ['2026-09-21', '2026-11-27']],
            array_map(fn ($p) => [$p->fecha_inicio, $p->fecha_fin], $periodos));
    }

    /**
     * Un calendario **a medias** en el año anterior se calcula entero, no se traslada
     * a medias.
     *
     * En la base hay años exactamente así: uno tiene un periodo con `fecha_inicio`
     * puesta y `fecha_fin` en NULL, y los otros tres vacíos. Trasladar eso dejaría al
     * año nuevo con un periodo fechado y tres sin fechas — o sea con el agujero que
     * esto viene a tapar.
     */
    public function test_un_calendario_a_medias_se_calcula_entero(): void
    {
        $ultimo = DB::selectOne('SELECT id, year FROM years WHERE deleted_at IS NULL ORDER BY year DESC LIMIT 1');

        DB::table('years')->where('id', $ultimo->id)->update(['year' => 2025, 'calendario' => 'A']);
        $this->ponerleElCalendario((int) $ultimo->id, [
            ['2025-01-20', '2025-03-28'],
            ['2025-03-31', '2025-06-13'],
            ['2025-07-07', null],
            [null, null],
        ]);

        $r = $this->withToken($this->tokenDelPersonal())->postJson('/api/years/store',
            $this->cuerpoDeAnioNuevo(2026, false));
        $r->assertStatus(200);

        $periodos = $this->periodosDe((int) $r->json('id'));

        // Las calculadas, no las trasladadas: el traslado del P1 habría dado
        // 2026-01-19 → 2026-03-27, y el calculado acaba el 3 de abril.
        $this->assertSame('2026-01-19', $periodos[0]->fecha_inicio);
        $this->assertSame('2026-04-03', $periodos[0]->fecha_fin);

        foreach ($periodos as $periodo) {
            $this->assertNotNull($periodo->fecha_fin, "El periodo {$periodo->numero} se quedó sin fecha de fin.");
        }
    }

    /** El colegio de calendario B empieza en agosto, y no en enero. */
    public function test_el_calendario_b_arranca_en_agosto(): void
    {
        $ultimo = DB::selectOne('SELECT id, year FROM years WHERE deleted_at IS NULL ORDER BY year DESC LIMIT 1');

        DB::table('years')->where('id', $ultimo->id)->update(['year' => 2025, 'calendario' => 'B']);
        DB::table('periodos')->where('year_id', $ultimo->id)
            ->update(['fecha_inicio' => null, 'fecha_fin' => null]);

        $r = $this->withToken($this->tokenDelPersonal())->postJson('/api/years/store',
            $this->cuerpoDeAnioNuevo(2026, false));
        $r->assertStatus(200);

        // La letra se copia del año anterior, y por eso las fechas la respetan: si
        // los periodos se crearan antes de esa copia, este colegio estrenaría el año
        // con el calendario A.
        $this->assertSame('B', DB::table('years')->where('id', $r->json('id'))->value('calendario'));

        $periodos = $this->periodosDe((int) $r->json('id'));
        $this->assertSame('2026-08-17', $periodos[0]->fecha_inicio);
        $this->assertSame('2027-06-25', $periodos[3]->fecha_fin);
    }

    /**
     * Los dos interruptores del periodo se heredan del año anterior, y no nacen
     * abiertos.
     *
     * `profes_pueden_editar_notas` decide si la planilla de notas de ese periodo está
     * abierta a los docentes. El defecto del esquema es `1`, y en el seed hay años
     * con los cuatro periodos **cerrados**: nacer abiertos abriría la planilla de un
     * año lectivo entero a los 51 docentes sin que nadie lo pidiera.
     */
    public function test_los_periodos_heredan_los_interruptores_del_anterior(): void
    {
        $ultimo = DB::selectOne('SELECT id, year FROM years WHERE deleted_at IS NULL ORDER BY year DESC LIMIT 1');

        DB::table('periodos')->where('year_id', $ultimo->id)
            ->update(['profes_pueden_editar_notas' => 0, 'profes_pueden_nivelar' => 0]);
        DB::table('periodos')->where('year_id', $ultimo->id)->where('numero', 2)
            ->update(['profes_pueden_editar_notas' => 1]);

        $periodos = $this->periodosDe($this->crearElAnioSiguiente());

        $this->assertSame([0, 1, 0, 0], array_map(fn ($p) => (int) $p->profes_pueden_editar_notas, $periodos));
        $this->assertSame([0, 0, 0, 0], array_map(fn ($p) => (int) $p->profes_pueden_nivelar, $periodos));
    }

    /**
     * Las diez columnas del año que no se copiaban, y cuatro de ellas se imprimen.
     *
     * `caracter`, `calendario` y `jornada` salen literalmente en el certificado de
     * estudio —«de carácter X, calendario Y, jornada Z»— y las tres tienen defecto en
     * el esquema, así que el año nuevo no salía en blanco: salía diciendo «Privado»,
     * «A» y «Mañana y tarde» fuera cual fuera el colegio, que es peor que vacío porque
     * nadie lo nota. `frase_final_certificado` es la frase de cierre de ese papel y sí
     * nacía vacía.
     */
    public function test_el_ano_nuevo_copia_las_diez_columnas_que_faltaban(): void
    {
        $ultimo = DB::selectOne('SELECT id, year FROM years WHERE deleted_at IS NULL ORDER BY year DESC LIMIT 1');

        $puestas = [
            'genero_colegio' => 'M',
            'caracter' => 'Oficial',
            'calendario' => 'B',
            'jornada' => 'Única',
            'frase_final_certificado' => 'Se expide en La Guajira.',
            'texto_acta_eval' => 'Reunido el consejo académico...',
            'show_materias_todas' => 0,
            'prematr_antiguos' => 1,
            'prematr_nuevos' => 1,
        ];
        DB::table('years')->where('id', $ultimo->id)->update($puestas);

        $nuevo = DB::table('years')->where('id', $this->crearElAnioSiguiente())->first();

        foreach ($puestas as $columna => $valor) {
            $this->assertSame((string) $valor, (string) $nuevo->$columna,
                "El año nuevo no copió {$columna} del anterior.");
        }

        // La décima, `img_encabezado_id`, va aparte porque es una imagen: se copia el
        // id igual que ya se copiaba el del logo, y las dos apuntan a la misma tabla
        // `images`, que no es por año.
        $this->assertSame(
            DB::table('years')->where('id', $ultimo->id)->value('img_encabezado_id'),
            $nuevo->img_encabezado_id);
    }

    /**
     * El interruptor de los puestos del boletín independiente **sobrevive al cambio
     * de año**, que es lo que lo distingue de las diez de arriba.
     *
     * Las diez hacían **perder** una configuración; ésta hacía **resucitar la
     * contraria a la elegida**: la columna es `NOT NULL DEFAULT 1`, así que el año
     * nuevo nacía a 1 —«los independientes cuentan para el puesto»— aunque el colegio
     * lo hubiera puesto a 0 el año anterior. Sin error, sin aviso, y con el efecto de
     * la §7 del [19](../../docs/migracion/19-boletin-independiente.md): **el puesto
     * impreso de todos los alumnos del grupo se mueve**.
     *
     * **Por qué se escribe este test y no se confía en el bloque:** de las cuatro
     * columnas que han entrado a `years` por migración desde que se congeló el
     * volcado, **dos se acordaron de la lista de `postStore` y dos no** —la pareja de
     * los certificados sí, `firmantes_acta` y ésta no—. O sea que la lista **no se
     * mantiene sola**, y una columna que resucita cada enero es de las que no se
     * descubren hasta el año siguiente.
     *
     * Se pone a **0** a propósito y no a 1: con 1 el test pasaría con la línea de la
     * copia quitada, porque 1 es el `DEFAULT`. El rojo de aquí es literalmente el
     * defecto reapareciendo.
     */
    public function test_el_ano_nuevo_conserva_el_interruptor_de_puestos_apagado(): void
    {
        $ultimo = DB::selectOne('SELECT id FROM years WHERE deleted_at IS NULL ORDER BY year DESC LIMIT 1');

        DB::table('years')->where('id', $ultimo->id)->update(['puestos_con_bol_independiente' => 0]);

        $nuevo = DB::table('years')->where('id', $this->crearElAnioSiguiente())->first();

        $this->assertSame(0, (int) $nuevo->puestos_con_bol_independiente,
            'El año nuevo nació con el interruptor de puestos ENCENDIDO habiéndolo apagado el colegio '
            .'el año anterior. Es el `DEFAULT 1` de la columna reapareciendo porque `postStore` no la '
            .'copia, y lo que cambia con él es el puesto impreso de todo el grupo (19 §7).');
    }

    /**
     * Y el otro lado, sin el cual esto sería fijar media conducta: encendido se
     * hereda encendido.
     *
     * No es simetría por gusto. Una copia mal escrita —un `0` literal, un `(bool)`
     * de más— pasaría el test de arriba y **apagaría el interruptor en los quince
     * colegios el primer enero**, que es el fallo grande de los dos: hoy los quince
     * están en 1.
     */
    public function test_el_ano_nuevo_conserva_el_interruptor_de_puestos_encendido(): void
    {
        $ultimo = DB::selectOne('SELECT id FROM years WHERE deleted_at IS NULL ORDER BY year DESC LIMIT 1');

        DB::table('years')->where('id', $ultimo->id)->update(['puestos_con_bol_independiente' => 1]);

        $nuevo = DB::table('years')->where('id', $this->crearElAnioSiguiente())->first();

        $this->assertSame(1, (int) $nuevo->puestos_con_bol_independiente,
            'El año nuevo nació con el interruptor apagado sin que nadie lo apagara.');
    }

    /**
     * Los requisitos de matrícula se copian, y eran la única tabla de configuración
     * por año que no se copiaba.
     *
     * El año nuevo nacía sin ninguno y la pantalla de matrículas salía vacía, con el
     * colegio volviendo a escribir la misma lista todos los años. No se copia
     * `editable_por_profe_id`: apunta a una persona de la planta, y **cuando se crea
     * el año no hay ni un contrato en él**.
     */
    public function test_el_ano_nuevo_copia_los_requisitos_de_matricula(): void
    {
        $ultimo = DB::selectOne('SELECT id, year FROM years WHERE deleted_at IS NULL ORDER BY year DESC LIMIT 1');

        // El seed no trae ninguno: sin esto el test pasaría contando cero contra
        // cero, que es la lectura falsa de un «0 encontrados».
        foreach ([['Fotocopia del documento', 1], ['Certificado médico', 2]] as [$requisito, $orden]) {
            DB::table('requisitos_matricula')->insert([
                'year_id' => $ultimo->id,
                'orden' => $orden,
                'requisito' => $requisito,
                'descripcion' => 'Traer '.$requisito,
                'editable_por_profe_id' => 1,
            ]);
        }

        $copiados = DB::select('SELECT * FROM requisitos_matricula WHERE year_id=? AND deleted_at IS NULL ORDER BY orden',
            [$this->crearElAnioSiguiente()]);

        $this->assertCount(2, $copiados);
        $this->assertSame(['Fotocopia del documento', 'Certificado médico'],
            array_map(fn ($r) => $r->requisito, $copiados));
        $this->assertSame('Traer Fotocopia del documento', $copiados[0]->descripcion);

        foreach ($copiados as $copiado) {
            $this->assertNull($copiado->editable_por_profe_id,
                'El requisito copiado se trajo un docente que no tiene contrato en el año nuevo.');
            $this->assertNotNull($copiado->created_at);
        }
    }

    /**
     * La asignatura del año nuevo se lleva su docente; el grupo **no** se lleva su
     * titular. Son las dos direcciones y las dos son decisión de Joseth (30 ago 2026).
     *
     * El docente sí, porque cuando se crea el año no hay ni un contrato en él y eso es
     * justo lo que lo hace inocuo: la columna «Profesor» de la rejilla resuelve el
     * nombre **filtrando la lista de contratados** (`AsignaturasCtrl`, alimentada por
     * `Profesor::paraElegirEnAsignaturas`), así que la celda sale en blanco hasta que
     * se le hace el contrato y entonces **aparece sola**. El reparto del año pasado
     * queda de borrador y se materializa según se contrata. Es lo que ya hacía
     * `POST asignaturas/copiar` de grupo a grupo; esta ruta era la única que no.
     *
     * El titular no, porque su listado hace `left join profesores p on p.id=g.titular_id`
     * **sin pasar por `contratos`**: copiado sale con nombre y apellidos, como si
     * estuviera en la planta del año nuevo. No es un borrador, es un dato que se ve y
     * parece cierto.
     */
    public function test_la_asignatura_hereda_su_docente_y_el_grupo_no_hereda_su_titular(): void
    {
        $ultimo = DB::selectOne('SELECT id, year FROM years WHERE deleted_at IS NULL ORDER BY year DESC LIMIT 1');

        $grupo = DB::selectOne('SELECT id FROM grupos WHERE year_id=? AND deleted_at IS NULL ORDER BY id LIMIT 1',
            [$ultimo->id]);
        $this->assertNotNull($grupo, 'El año de partida tiene que traer grupos, o esto no copia nada.');

        $docente = DB::table('profesores')->whereNull('deleted_at')->orderBy('id')->value('id');
        DB::table('grupos')->where('id', $grupo->id)->update(['titular_id' => $docente]);
        DB::table('asignaturas')->where('grupo_id', $grupo->id)->whereNull('deleted_at')
            ->update(['profesor_id' => $docente]);

        $repartidas = DB::table('asignaturas')->where('grupo_id', $grupo->id)->whereNull('deleted_at')->count();
        $this->assertGreaterThan(0, $repartidas,
            'El grupo de partida tiene que traer asignaturas: si no, esto cuenta cero contra cero.');

        $nuevo = $this->crearElAnioSiguiente();

        $copiadas = DB::select('SELECT a.profesor_id FROM asignaturas a
            INNER JOIN grupos g ON g.id=a.grupo_id
            WHERE g.year_id=? AND a.deleted_at IS NULL AND g.deleted_at IS NULL', [$nuevo]);

        $this->assertCount($repartidas, $copiadas);

        foreach ($copiadas as $asignatura) {
            $this->assertSame((int) $docente, (int) $asignatura->profesor_id,
                'La asignatura del año nuevo perdió el docente que traía la del anterior.');
        }

        foreach (DB::select('SELECT titular_id FROM grupos WHERE year_id=? AND deleted_at IS NULL', [$nuevo]) as $g) {
            $this->assertNull($g->titular_id,
                'El grupo del año nuevo se trajo un titular que no está en la planta de ese año.');
        }
    }
}
