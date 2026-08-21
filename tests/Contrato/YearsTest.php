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

        foreach ($this->actuales() as $id) {
            $this->withToken($token)->putJson('/api/years/set-actual',
                ['year_id' => $id, 'can' => false])->assertStatus(200);
        }

        $this->assertSame([], $this->actuales());

        $usuario = $this->usuarioDeTipo('Usuario');
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
        $usuario = $this->usuarioDeTipo('Usuario');
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
