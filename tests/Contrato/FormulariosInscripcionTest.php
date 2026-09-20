<?php

namespace Tests\Contrato;

use App\Services\CodigoDeInscripcion;
use Illuminate\Support\Facades\DB;

/**
 * **Las cuatro rutas del formulario de inscripción impreso.**
 *
 *     POST informes/formularios-inscripcion        acuña
 *     GET  informes/formularios-inscripcion/{lote} NO acuña
 *     GET  informes/formularios-inscripcion/campos qué campos salen en el papel
 *     PUT  informes/formularios-inscripcion/campos los guarda
 *
 * Lo que este fichero defiende no es que las rutas contesten 200: es que **acuñar
 * un código es irreversible** y que las tres propiedades que hacen útil al código
 * se cumplen de verdad contra la base.
 *
 * Por eso casi todas las pruebas **cuentan filas antes y después**. Un test que
 * mirase sólo la respuesta pasaría igual el día que el `GET` empiece a acuñar en
 * silencio, que es exactamente el fallo que el front nos hizo ver: sin `lote_id`,
 * una impresora atascada cuesta diez códigos y no lo dice nadie.
 */
class FormulariosInscripcionTest extends CasoDeContrato
{
    private const RUTA = '/api/informes/formularios-inscripcion';

    public function test_en_blanco_salen_tantos_codigos_como_se_piden_y_todos_distintos(): void
    {
        $token = $this->tokenDelPersonalLlano();

        $r = $this->withToken($token)->postJson(self::RUTA, [
            'modo' => 'nuevos',
            'cantidad' => 5,
            'cierra' => '31 de octubre de 2026',
        ]);

        $r->assertStatus(200);
        $cuerpo = $r->json();

        $this->assertCount(5, $cuerpo['formularios']);

        $codigos = array_column($cuerpo['formularios'], 'codigo');

        $this->assertCount(5, array_unique($codigos),
            'Dos ejemplares salieron con el mismo código: el papel dejaría de identificar a nadie.');

        foreach ($codigos as $codigo) {
            $this->assertTrue(CodigoDeInscripcion::esValido($codigo),
                "La API acuñó «{$codigo}» y su propio validador lo rechaza.");
        }

        foreach ($cuerpo['formularios'] as $formulario) {
            $this->assertNull($formulario['alumno'],
                'Un formulario en blanco trae alumno: entonces no está en blanco.');
        }

        $this->assertSame('31 de octubre de 2026', $cuerpo['cierra']);
        $this->assertArrayHasKey('colegio', $cuerpo);
    }

    /**
     * **La propiedad que pidió Joseth: un código por alumno y año.**
     *
     * Reimprimir 5°A porque se atascó la impresora tiene que devolver **los mismos
     * códigos y el mismo lote**. Si devolviera otros, el código dejaría de
     * identificar al alumno en cuanto alguien imprime dos veces — y eso es lo único
     * que el código hace.
     */
    public function test_reimprimir_la_renovacion_devuelve_los_mismos_codigos(): void
    {
        $token = $this->tokenDelPersonalLlano();
        $grupo = $this->unGrupoConAlumnos();

        $primera = $this->withToken($token)->postJson(self::RUTA,
            ['modo' => 'antiguos', 'grupo_id' => $grupo->id])->assertStatus(200)->json();

        $antes = $this->cuantasOrdenes();

        $segunda = $this->withToken($token)->postJson(self::RUTA,
            ['modo' => 'antiguos', 'grupo_id' => $grupo->id])->assertStatus(200)->json();

        $this->assertSame($antes, $this->cuantasOrdenes(),
            'Reimprimir acuñó códigos nuevos. El get-or-create no está funcionando.');

        $this->assertSame($primera['lote_id'], $segunda['lote_id'],
            'La reimpresión dio otro lote, así que el primero dejaría de resolver.');

        $this->assertSame(
            array_column($primera['formularios'], 'codigo'),
            array_column($segunda['formularios'], 'codigo'),
            'La reimpresión dio otros códigos: el papel que tiene la familia deja de valer.');
    }

    public function test_la_renovacion_trae_los_datos_resueltos_a_texto(): void
    {
        $token = $this->tokenDelPersonalLlano();
        $grupo = $this->unGrupoConAlumnos();

        $cuerpo = $this->withToken($token)->postJson(self::RUTA,
            ['modo' => 'antiguos', 'grupo_id' => $grupo->id])->assertStatus(200)->json();

        $this->assertNotEmpty($cuerpo['formularios']);

        $alumno = $cuerpo['formularios'][0]['alumno'];

        $this->assertNotNull($alumno, 'La renovación salió sin datos: sería un formulario en blanco.');
        $this->assertArrayHasKey('nombres', $alumno);
        $this->assertArrayHasKey('acudientes', $alumno);

        // Lo que de verdad se comprueba: que NO viajan ids donde va texto. Un papel
        // no puede imprimir un `ciudad_id`, y si viajara el id el front tendría que
        // bajarse los catálogos enteros para pintar cuarenta hojas.
        foreach (['tipo_doc', 'ciudad_doc', 'ciudad_nac', 'ciudad_resid'] as $campo) {
            $this->assertArrayHasKey($campo, $alumno);
            $this->assertFalse(is_int($alumno[$campo]),
                "«{$campo}» viaja como id y no como texto: el papel imprimiría un número.");
        }

        $this->assertLessThanOrEqual(2, count($alumno['acudientes']),
            'Salen más de dos acudientes: la hoja no da para eso (23 de 25 cm medidos).');
    }

    /**
     * El `GET` de un lote **no puede escribir nada**. Es su razón de existir.
     */
    public function test_releer_un_lote_no_acuna(): void
    {
        $token = $this->tokenDelPersonalLlano();

        $lote = $this->withToken($token)->postJson(self::RUTA,
            ['modo' => 'nuevos', 'cantidad' => 3])->assertStatus(200)->json();

        $antes = $this->cuantasOrdenes();

        $releido = $this->withToken($token)
            ->getJson(self::RUTA.'/'.$lote['lote_id'])->assertStatus(200)->json();

        $this->assertSame($antes, $this->cuantasOrdenes(),
            'Releer un lote acuñó códigos. Es justo lo que esta ruta existe para no hacer.');

        $this->assertSame(
            array_column($lote['formularios'], 'codigo'),
            array_column($releido['formularios'], 'codigo'),
            'El lote releído no trae los mismos códigos que se imprimieron.');
    }

    public function test_un_lote_que_no_existe_es_404(): void
    {
        $this->withToken($this->tokenDelPersonalLlano())
            ->getJson(self::RUTA.'/no-existe-este-lote')
            ->assertStatus(404);
    }

    /**
     * Acuñar es irreversible, así que la cantidad se valida **antes** de escribir.
     */
    public function test_una_cantidad_imposible_es_422_y_no_escribe(): void
    {
        $token = $this->tokenDelPersonalLlano();
        $antes = $this->cuantasOrdenes();

        foreach ([0, -3, 201, 5000, 'muchos', 2.5] as $cantidad) {
            $this->withToken($token)->postJson(self::RUTA,
                ['modo' => 'nuevos', 'cantidad' => $cantidad])
                ->assertStatus(422);
        }

        $this->assertSame($antes, $this->cuantasOrdenes(),
            'Una cantidad rechazada dejó filas escritas.');
    }

    public function test_un_modo_que_no_existe_es_422(): void
    {
        $this->withToken($this->tokenDelPersonalLlano())
            ->postJson(self::RUTA, ['modo' => 'todos', 'cantidad' => 1])
            ->assertStatus(422);
    }

    /**
     * El grupo llega por el cuerpo, así que se comprueba que sea **del año de la
     * sesión**. Sin esto se podría imprimir la renovación de un grupo de hace ocho
     * años, con los alumnos de entonces.
     */
    public function test_un_grupo_de_otro_anio_es_404(): void
    {
        $ajeno = DB::selectOne('SELECT g.id FROM grupos g
            INNER JOIN years y ON y.id=g.year_id AND y.actual=0 AND y.deleted_at IS NULL
            WHERE g.deleted_at IS NULL LIMIT 1');

        $this->assertNotNull($ajeno, 'El seed no tiene grupos de años no actuales: esto no mediría nada.');

        $this->withToken($this->tokenDelPersonalLlano())
            ->postJson(self::RUTA, ['modo' => 'antiguos', 'grupo_id' => $ajeno->id])
            ->assertStatus(404);
    }

    /**
     * El tope se valida aquí, no en la base.
     *
     * Sin esto, el docker trunca en silencio a 120 y **MariaDB 10.5 en producción
     * aborta**: verde en desarrollo y roto en los dieciséis.
     */
    public function test_una_fecha_limite_larguisima_es_422_y_no_escribe(): void
    {
        $token = $this->tokenDelPersonalLlano();
        $antes = $this->cuantasOrdenes();

        $this->withToken($token)->postJson(self::RUTA, [
            'modo' => 'nuevos',
            'cantidad' => 1,
            'cierra' => str_repeat('x', 121),
        ])->assertStatus(422);

        $this->assertSame($antes, $this->cuantasOrdenes());
    }

    public function test_sin_token_las_dos_son_401(): void
    {
        $this->postJson(self::RUTA, ['modo' => 'nuevos', 'cantidad' => 1])->assertStatus(401);
        $this->getJson(self::RUTA.'/lo-que-sea')->assertStatus(401);
    }

    public function test_un_alumno_no_puede_imprimir_formularios(): void
    {
        $token = $this->tokenDe($this->usuarioDeTipo('Alumno')->username);

        $this->withToken($token)->postJson(self::RUTA, ['modo' => 'nuevos', 'cantidad' => 1])
            ->assertStatus(403);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // La configuración de campos
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * **`campos` no puede quedar atrapado por `{lote}`.**
     *
     * Laravel casa por orden de registro. Con `GET …/{lote}` declarado antes, una
     * petición a `…/campos` entra por el lote llamado «campos» y contesta 404 — la
     * pantalla de configuración no funciona y el error no dice por qué. Es un fallo
     * de una línea invisible en el código, así que se fija aquí.
     */
    public function test_campos_no_lo_atrapa_la_ruta_del_lote(): void
    {
        $r = $this->withToken($this->tokenDelPersonalLlano())
            ->getJson(self::RUTA.'/campos');

        $r->assertStatus(200);
        $this->assertArrayHasKey('campos', $r->json(),
            'La ruta de campos contestó algo que no es la configuración: la está atendiendo `{lote}`.');
    }

    public function test_un_colegio_sin_configurar_devuelve_la_lista_vacia(): void
    {
        DB::statement('DELETE FROM config_formulario_inscripcion');

        $cuerpo = $this->withToken($this->tokenDelPersonalLlano())
            ->getJson(self::RUTA.'/campos')->assertStatus(200)->json();

        $this->assertSame([], $cuerpo['campos'],
            'Vacío significa «la selección por defecto», y quien la resuelve es el front.');
    }

    public function test_lo_que_se_guarda_es_lo_que_se_lee(): void
    {
        $token = $this->tokenDelPersonalLlano();
        $elegidos = ['nombres', 'apellidos', 'documento', 'eps', 'ac_celular'];

        $this->withToken($token)->putJson(self::RUTA.'/campos', ['campos' => $elegidos])
            ->assertStatus(200);

        $cuerpo = $this->withToken($token)->getJson(self::RUTA.'/campos')
            ->assertStatus(200)->json();

        $this->assertSame($elegidos, $cuerpo['campos'],
            'El orden importa: es el que sale en el papel.');
    }

    /**
     * Guardar dos veces no puede dejar dos configuraciones del mismo año.
     */
    public function test_guardar_otra_vez_reemplaza_y_no_duplica(): void
    {
        $token = $this->tokenDelPersonalLlano();

        $this->withToken($token)->putJson(self::RUTA.'/campos', ['campos' => ['nombres']])
            ->assertStatus(200);
        $this->withToken($token)->putJson(self::RUTA.'/campos', ['campos' => ['apellidos', 'eps']])
            ->assertStatus(200);

        $filas = DB::select('SELECT campos FROM config_formulario_inscripcion WHERE year_id=?',
            [$this->yearActual()]);

        $this->assertCount(1, $filas, 'Quedaron dos configuraciones para el mismo año.');
        $this->assertSame(['apellidos', 'eps'], json_decode($filas[0]->campos, true));
    }

    public function test_las_claves_repetidas_se_colapsan(): void
    {
        $token = $this->tokenDelPersonalLlano();

        $this->withToken($token)->putJson(self::RUTA.'/campos',
            ['campos' => ['nombres', 'eps', 'nombres']])->assertStatus(200);

        $cuerpo = $this->withToken($token)->getJson(self::RUTA.'/campos')->json();

        $this->assertSame(['nombres', 'eps'], $cuerpo['campos'],
            'Un campo dos veces en el papel no significa nada.');
    }

    /**
     * No hay lista blanca de claves —el catálogo vive en el front— pero sí de FORMA.
     * Sin esto, la columna acabaría guardando lo que mande un cliente con un error y
     * lo descubriríamos al imprimir.
     */
    public function test_lo_que_no_tiene_forma_de_clave_es_422_y_no_escribe(): void
    {
        $token = $this->tokenDelPersonalLlano();
        DB::statement('DELETE FROM config_formulario_inscripcion');

        $basura = [
            ['campos' => 'nombres'],
            ['campos' => [['nombres']]],
            ['campos' => ['Nombres Del Alumno']],
            ['campos' => ['<script>']],
            ['campos' => ['nombres; DROP TABLE alumnos']],
            ['campos' => [str_repeat('x', 41)]],
            ['campos' => array_fill(0, 101, 'nombres')],
            [],
        ];

        foreach ($basura as $cuerpo) {
            $this->withToken($token)->putJson(self::RUTA.'/campos', $cuerpo)->assertStatus(422);
        }

        $this->assertSame(0, (int) DB::selectOne('SELECT COUNT(*) c FROM config_formulario_inscripcion')->c,
            'Una lista rechazada dejó una configuración escrita.');
    }

    public function test_un_anio_que_no_existe_es_404(): void
    {
        $this->withToken($this->tokenDelPersonalLlano())
            ->getJson(self::RUTA.'/campos?year_id=999999')
            ->assertStatus(404);
    }

    public function test_un_alumno_no_configura_el_formulario(): void
    {
        $token = $this->tokenDe($this->usuarioDeTipo('Alumno')->username);

        $this->withToken($token)->putJson(self::RUTA.'/campos', ['campos' => ['nombres']])
            ->assertStatus(403);
    }

    // ──────────────────────── el precio del formulario ────────────────────────

    /**
     * **Lo que se guarda es lo que se lee**, también para el precio.
     *
     * Va en la misma fila y por la misma ruta que los campos, así que no gasta ruta
     * nueva. Decidido por Joseth el 19 sep 2026 entre tres formas (doc 41 §7).
     */
    public function test_el_precio_se_guarda_y_se_lee_con_los_campos(): void
    {
        $token = $this->tokenDelPersonalLlano();

        $this->withToken($token)->putJson(self::RUTA.'/campos',
            ['campos' => ['nombres', 'documento'], 'valor' => 30000])->assertStatus(200);

        $cuerpo = $this->withToken($token)->getJson(self::RUTA.'/campos')
            ->assertStatus(200)->json();

        $this->assertSame(30000, $cuerpo['valor']);
        $this->assertSame(['nombres', 'documento'], $cuerpo['campos'],
            'Guardar el precio se llevó por delante los campos, que van en la misma fila.');
    }

    /**
     * **Sin configurar, `valor` es `null` y no cero.**
     *
     * No es una distinción de estilo: el colegio que no ha decidido el precio y el
     * que puso cero se parecen desde la base pero no desde la pantalla, y lo que el
     * front tiene que enseñar en el primer caso es *«falta decidirlo»*.
     */
    public function test_un_colegio_sin_configurar_no_tiene_precio(): void
    {
        DB::statement('DELETE FROM config_formulario_inscripcion');

        $cuerpo = $this->withToken($this->tokenDelPersonalLlano())
            ->getJson(self::RUTA.'/campos')->assertStatus(200)->json();

        $this->assertNull($cuerpo['valor']);
    }

    /**
     * **EL PRECIO SE ESTAMPA AL ACUÑAR, Y CAMBIARLO DESPUÉS NO REESCRIBE LO VENDIDO.**
     *
     * Ésta es la propiedad que hace correcto todo esto y la única que no se ve
     * mirando una respuesta. Con una clave ajena a la configuración —que era la
     * forma «obvia» de no duplicar el dato— subir el precio en marzo cambiaría el
     * importe de lo que se vendió en enero, y el síntoma sería que la bandeja del
     * tesorero enseña meses después una cifra distinta de la que la familia pagó,
     * sin nada que lo explique.
     */
    public function test_subir_el_precio_no_cambia_lo_que_ya_se_acuno(): void
    {
        $token = $this->tokenDelPersonalLlano();

        $this->withToken($token)->putJson(self::RUTA.'/campos',
            ['campos' => ['nombres'], 'valor' => 30000])->assertStatus(200);

        $codigo = $this->withToken($token)->postJson(self::RUTA,
            ['modo' => 'nuevos', 'cantidad' => 1])->assertStatus(200)->json('formularios.0.codigo');

        // El colegio sube el precio para el resto de la campaña.
        $this->withToken($token)->putJson(self::RUTA.'/campos',
            ['campos' => ['nombres'], 'valor' => 45000])->assertStatus(200);

        $viejo = DB::selectOne('SELECT valor FROM ordenes_inscripcion WHERE codigo=?', [$codigo]);

        $this->assertSame(30000, (int) $viejo->valor,
            'El formulario ya impreso cambió de precio al cambiar la configuración.');

        $nuevo = $this->withToken($token)->postJson(self::RUTA,
            ['modo' => 'nuevos', 'cantidad' => 1])->assertStatus(200)->json('formularios.0.codigo');

        $este = DB::selectOne('SELECT valor FROM ordenes_inscripcion WHERE codigo=?', [$nuevo]);

        $this->assertSame(45000, (int) $este->valor,
            'El formulario nuevo salió con el precio viejo: no se está leyendo la configuración.');
    }

    /**
     * Sin precio configurado, la orden nace sin él — y es lo que hace que el pago en
     * línea conteste 422 en vez de cobrar cero pesos.
     */
    public function test_sin_precio_configurado_la_orden_nace_sin_valor(): void
    {
        DB::statement('DELETE FROM config_formulario_inscripcion');

        $codigo = $this->withToken($this->tokenDelPersonalLlano())->postJson(self::RUTA,
            ['modo' => 'nuevos', 'cantidad' => 1])->assertStatus(200)->json('formularios.0.codigo');

        $fila = DB::selectOne('SELECT valor FROM ordenes_inscripcion WHERE codigo=?', [$codigo]);

        $this->assertNull($fila->valor);
    }

    /**
     * **El tope se valida aquí y no se le deja a la base**: el docker trunca en
     * silencio y MariaDB 10.5 aborta, así que el mismo dato daría dos resultados
     * distintos en desarrollo y en producción.
     *
     * Y lo que NO hace, dicho para que nadie lo suponga: **no caza una errata de
     * tecleo**. Un cero de más en 30.000 da 300.000 y pasa por debajo del tope sin
     * despeinarse. Contra eso lo único que sirve es que la pantalla enseñe el precio
     * guardado.
     */
    public function test_un_precio_absurdo_o_que_no_es_un_numero_se_rechaza(): void
    {
        $token = $this->tokenDelPersonalLlano();

        foreach ([['valor' => 'gratis'], ['valor' => 30000.5], ['valor' => -1],
            ['valor' => 999999999]] as $cuerpo) {
            $this->withToken($token)
                ->putJson(self::RUTA.'/campos', array_merge(['campos' => ['nombres']], $cuerpo))
                ->assertStatus(422);
        }
    }

    /**
     * Mandar cero o nada **borra** el precio, que es como un colegio deja de cobrar
     * sin tener que borrar su configuración entera.
     */
    public function test_cero_y_ausente_dejan_el_precio_en_null(): void
    {
        $token = $this->tokenDelPersonalLlano();

        foreach ([0, null] as $vacio) {
            $this->withToken($token)->putJson(self::RUTA.'/campos',
                ['campos' => ['nombres'], 'valor' => 45000])->assertStatus(200);

            $this->withToken($token)->putJson(self::RUTA.'/campos',
                ['campos' => ['nombres'], 'valor' => $vacio])->assertStatus(200);

            $this->assertNull($this->withToken($token)->getJson(self::RUTA.'/campos')->json('valor'));
        }
    }

    private function yearActual(): int
    {
        return (int) DB::selectOne('SELECT id FROM years WHERE actual=1 AND deleted_at IS NULL')->id;
    }

    private function cuantasOrdenes(): int
    {
        return (int) DB::selectOne('SELECT COUNT(*) c FROM ordenes_inscripcion')->c;
    }

    private function unGrupoConAlumnos(): object
    {
        $grupo = DB::selectOne('SELECT g.id
            FROM grupos g
            INNER JOIN years y ON y.id=g.year_id AND y.actual=1 AND y.deleted_at IS NULL
            INNER JOIN matriculas m ON m.grupo_id=g.id AND m.deleted_at IS NULL
                AND (m.estado="ASIS" OR m.estado="MATR")
            WHERE g.deleted_at IS NULL
            GROUP BY g.id
            HAVING COUNT(m.id) > 0
            LIMIT 1');

        $this->assertNotNull($grupo,
            'El seed no tiene ningún grupo del año actual con alumnos: nada de esto mediría.');

        return $grupo;
    }
}
