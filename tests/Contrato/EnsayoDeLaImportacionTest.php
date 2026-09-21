<?php

namespace Tests\Contrato;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as EscritorXlsx;

/**
 * El ensayo: qué va a pasar si se sube esta hoja, sin escribir una sola fila.
 *
 * Es `POST importar/alumnos/ensayo/{year}`, la ruta que da sentido a la frase
 * con la que Joseth pidió todo esto —«diciéndole qué va a pasar»—. Hasta el 20
 * sep 2026 la única forma de saber qué hacía una importación era hacerla, y lo
 * que hace no es lo que parece: **el importador no valida, adivina**.
 *
 * El primero de estos tests es el que sostiene la pantalla entera. Los demás
 * comprueban que lo que promete es cierto.
 */
class EnsayoDeLaImportacionTest extends CasoDeContrato
{
    /**
     * NO ESCRIBE NADA. Cinco tablas contadas antes y después.
     *
     * Es el test que hace que el botón se pueda pulsar sin miedo, y por eso
     * cuenta las cinco tablas que la importación toca —no sólo `alumnos`—: una
     * fila de alumno son ocho escrituras repartidas entre alumno, usuario, rol,
     * matrícula y los dos acudientes, y mirar sólo la primera dejaría pasar
     * justo las que nadie vigila.
     *
     * La hoja que se le da es una CON DEFECTOS a propósito: un tipo de documento
     * inventado y un estado que no cabe. Ensayar una hoja perfecta no demuestra
     * nada, porque lo que se quiere saber es que tampoco escribe cuando tiene
     * cosas que decir.
     */
    public function test_el_ensayo_no_escribe_nada(): void
    {
        [$token, $year] = $this->personalYSuYear();
        $archivo = $this->hojaConDefectos($this->exportacionDeAlumnos($token));

        $antes = $this->censoDeLasTablas();

        $this->ensayar($archivo, $token, $year)->assertStatus(200)->assertJson(['escribe' => false]);

        $this->assertSame($antes, $this->censoDeLasTablas(),
            'El ensayo escribió en la base. La pantalla promete que no toca nada ANTES de que nadie decida.');
    }

    /**
     * Y lo dice de sí mismo en la respuesta, no sólo en un comentario.
     *
     * El front pinta ese `escribe: false`; si algún día dejara de ser cierto,
     * esta línea sería una mentira que se lee en pantalla.
     */
    public function test_se_declara_como_lo_que_es(): void
    {
        [$token, $year] = $this->personalYSuYear();

        $this->ensayar($this->exportacionDeAlumnos($token), $token, $year)
            ->assertStatus(200)
            ->assertJson(['ok' => true, 'escribe' => false, 'totales' => ['borrar' => 0]]);
    }

    /**
     * Reimportar lo exportado no le cambia nada a nadie — y el ensayo lo dice
     * ANTES.
     *
     * `ExcelTest` demuestra que ese viaje de ida y vuelta no mueve un dato. Este
     * test comprueba que el ensayo **predice** lo mismo: si dijera «actualizar»
     * para los 37, la pantalla asustaría con un pisotón que no existe, y si
     * dijera «crear» sería la duplicación de vuelta.
     */
    public function test_reimportar_lo_exportado_sale_como_sin_cambios(): void
    {
        [$token, $year] = $this->personalYSuYear();

        $r = $this->ensayar($this->exportacionDeAlumnos($token), $token, $year)->assertStatus(200);

        $totales = $r->json('totales');

        $this->assertGreaterThan(0, $totales['filas']);
        $this->assertSame(0, $totales['crear'], 'Una hoja de alumnos que ya están no crea a nadie.');
        $this->assertSame($totales['filas'], $totales['sin_cambios'],
            'Reimportar lo exportado no le cambia nada a nadie, y el ensayo tiene que decirlo antes.');
    }

    /**
     * Una hoja que no es de ningún grupo del año SE DICE, en vez de reventar a
     * media importación.
     *
     * Hoy eso es un 500 —`RuntimeException` desde dentro del bucle— y llega con
     * medio colegio ya escrito, porque la comprobación vive a media faena. Aquí
     * no escribe nadie, así que puede contestarse con calma: esa hoja no casa
     * con ningún grupo, y éstos son los grupos que hay.
     */
    public function test_una_hoja_sin_grupo_se_dice_y_no_revienta(): void
    {
        [$token, $year] = $this->personalYSuYear();
        $archivo = $this->conLaPrimeraHojaRenombrada($this->exportacionDeAlumnos($token), 'NoExiste');

        $r = $this->ensayar($archivo, $token, $year)->assertStatus(200);

        $hojas = collect($r->json('hojas'))->keyBy('nombre');

        $this->assertNull($hojas['NoExiste']['coincide_con'],
            'La hoja no es de ningún grupo del año y el ensayo tiene que decirlo, no reventar.');
        $this->assertNotEmpty($r->json('grupos_del_year'),
            'Si una hoja no casa, hay que poder ofrecer a qué grupo va.');
    }

    /**
     * Un valor fuera del catálogo sale AGRUPADO, con sus veces y su
     * consecuencia.
     *
     * Es lo que convierte cuarenta y cinco avisos en cuatro decisiones: a la
     * persona no le sirve una lista de filas, le sirve saber qué es «CARNÉ
     * DIPLOMÁTICO» y decidirlo una vez.
     */
    public function test_un_valor_fuera_del_catalogo_sale_agrupado(): void
    {
        [$token, $year] = $this->personalYSuYear();
        $archivo = $this->hojaConDefectos($this->exportacionDeAlumnos($token));

        $r = $this->ensayar($archivo, $token, $year)->assertStatus(200);

        $noReconocidos = collect($r->json('valores_no_reconocidos'))
            ->firstWhere('valor', 'CARNÉ DIPLOMÁTICO');

        $this->assertNotNull($noReconocidos, 'El valor inventado tiene que salir.');
        $this->assertSame('tipo_de_documento', $noReconocidos['columna']);
        $this->assertSame(1, $noReconocidos['veces']);
        $this->assertNotEmpty($noReconocidos['filas'][0]['hoja'], 'Hay que poder llevar a la celda.');
        $this->assertStringContainsString('Tarjeta de Identidad', $noReconocidos['motivo'],
            'Lo que hace falta no es «no lo entendí», es qué se guardaría si nadie hace nada.');
    }

    /**
     * El estado que no cabe sale ANTES de subirlo, con lo que le pasaría.
     *
     * `matriculas.estado` es `varchar(4)`: un «Activo» se guardaba «Acti», que
     * no es ninguno de los siete códigos vivos, y el alumno desaparecía de todas
     * las listas. Desde la Fase 1 no se escribe; esto es poder decirlo antes.
     */
    public function test_el_estado_que_no_cabe_sale_con_su_consecuencia(): void
    {
        [$token, $year] = $this->personalYSuYear();
        $archivo = $this->hojaConDefectos($this->exportacionDeAlumnos($token));

        $truncados = $this->ensayar($archivo, $token, $year)->assertStatus(200)->json('truncados');

        $estado = collect($truncados)->firstWhere('columna', 'estado_matricula');

        $this->assertNotNull($estado);
        $this->assertSame('Activo', $estado['valor']);
        $this->assertSame(4, $estado['longitud_maxima']);
        $this->assertSame('no_se_escribe', $estado['consecuencia'],
            'Lo que no cabe no se escribe desde la Fase 1: la matrícula conserva el estado que tenía.');
    }

    /**
     * **Y el que CABE pero no existe, que es la otra puerta.**
     *
     * Lo encontró `myvc-front-51` el 21 sep 2026: `ACTV` pasa los cuatro
     * caracteres, se escribía tal cual y dejaba al alumno en un estado que **no
     * consulta ninguna query**. El síntoma es el mismo que el del truncado —
     * desaparece de las listas— y el camino es el contrario: no se estropea al
     * guardarlo, se guarda entero y no significa nada.
     *
     * La consecuencia va con **su propia palabra** y no reusa `no_se_escribe`:
     * lo que la persona hace con cada uno no es lo mismo — uno se acorta y el
     * otro se traduce—, y la pantalla tiene que poder decirlo.
     */
    public function test_el_estado_que_cabe_pero_no_existe_tambien_sale(): void
    {
        [$token, $year] = $this->personalYSuYear();
        $archivo = $this->hojaConUnEstadoQueCabeYNoExiste($this->exportacionDeAlumnos($token));

        $truncados = $this->ensayar($archivo, $token, $year)->assertStatus(200)->json('truncados');

        $estado = collect($truncados)->firstWhere('valor', 'ACTV');

        $this->assertNotNull($estado,
            'Un estado de cuatro caracteres que no existe pasó el ensayo sin que nadie lo nombrara: '
            .'el alumno se queda invisible en las listas y nadie se entera.');

        $this->assertSame('no_esta_en_el_catalogo', $estado['consecuencia']);

        $this->assertContains('MATR', $estado['catalogo'],
            'El aviso no lleva el catálogo dentro, así que la persona no puede saber contra qué elegir.');
        $this->assertNotContains('ACTV', $estado['catalogo']);
    }

    /**
     * Y la otra mitad, que es la que impide que este arreglo sea el fallo de
     * enfrente: **un estado que el colegio SÍ usa no se marca**.
     *
     * No hay tabla de estados —son convenciones repartidas por el SQL—, así que
     * el catálogo se mide: lo que el código nombra más lo que este colegio ya
     * tiene escrito. Una lista corta declarada a mano marcaría como error las
     * matrículas legítimas de un colegio con un octavo estado.
     */
    public function test_un_estado_que_el_colegio_ya_usa_no_se_marca(): void
    {
        [$token, $year] = $this->personalYSuYear();

        $suyo = DB::selectOne("SELECT estado FROM matriculas
            WHERE deleted_at IS NULL AND estado IS NOT NULL AND TRIM(estado) <> ''
            GROUP BY estado ORDER BY COUNT(*) DESC LIMIT 1");

        $this->assertNotNull($suyo, 'El seed no tiene ninguna matrícula con estado: esto no mide nada.');

        $libro = IOFactory::load($this->exportacionDeAlumnos($token));
        $hoja = $libro->getSheet(0);
        $hoja->setCellValue($this->columnasDe($hoja)['estado_matricula'].'3', $suyo->estado);

        $truncados = $this->ensayar($this->guardar($libro), $token, $year)
            ->assertStatus(200)->json('truncados');

        $this->assertNull(collect($truncados)->firstWhere('valor', $suyo->estado),
            "El estado `{$suyo->estado}` lo usa este colegio en sus propias matrículas y el ensayo "
            .'lo marcó como desconocido.');
    }

    /**
     * Los catálogos viajan con el ensayo, con sus tildes.
     *
     * Son tablas de cada colegio y no constantes: una lista incrustada en el
     * front acierta en uno y miente en los otros quince. Y el importador compara
     * bytes, así que lo que se elija tiene que ser la cadena del catálogo tal
     * cual.
     */
    public function test_los_catalogos_del_colegio_viajan_con_el_ensayo(): void
    {
        [$token, $year] = $this->personalYSuYear();

        $catalogos = $this->ensayar($this->exportacionDeAlumnos($token), $token, $year)
            ->assertStatus(200)->json('catalogos');

        $this->assertNotEmpty($catalogos['tipos_documento']);
        $this->assertArrayHasKey('abrev', $catalogos['tipos_documento'][0]);
        $this->assertCount(7, $catalogos['estados_matricula'], 'Son siete códigos vivos, no cuatro.');
    }

    /**
     * El mismo documento dos veces dentro del propio libro, con cuál ganaría.
     *
     * «Dejar que gane la última» tiene una respuesta concreta que sólo sabe el
     * servidor, porque depende del orden en que se leen las filas.
     */
    public function test_un_documento_repetido_en_el_archivo_sale_con_cual_gana(): void
    {
        [$token, $year] = $this->personalYSuYear();
        $archivo = $this->conElDocumentoDeLaFilaCuatroRepetido($this->exportacionDeAlumnos($token));

        $duplicados = $this->ensayar($archivo, $token, $year)->assertStatus(200)->json('duplicados_en_el_archivo');

        $this->assertNotEmpty($duplicados, 'Dos filas con el mismo documento chocan y hay que decirlo.');
        $this->assertCount(2, $duplicados[0]['filas']);
        $this->assertNotNull($duplicados[0]['cual_ganaria_hoy']);
        $this->assertSame(
            $duplicados[0]['filas'][1]['fila_del_libro'],
            $duplicados[0]['cual_ganaria_hoy']['fila_del_libro'],
            'Gana la última, porque las filas se procesan en orden y la última pisa.'
        );
    }

    /**
     * EL CANDADO DE TODO ESTO: lo que el ensayo promete es lo que pasa.
     *
     * Ensayar y luego importar de verdad la MISMA hoja, y comprobar contra la
     * base que el cambio prometido ocurrió y que a quien dijo «sin cambios» no
     * se le movió nada. Es el criterio de este repo aplicado aquí —mirar el
     * resultado y no la llamada— y es lo único que impide que el ensayo y el
     * importador se separen: son dos caminos distintos sobre el mismo fichero, y
     * el día que uno aprenda algo que el otro no, esto se pone rojo.
     *
     * Ya cazó uno mientras se escribía: `nro_sisben` se escribe DOS VECES en el
     * mismo `UPDATE` y gana la segunda, así que un «No aplica» de la hoja acaba
     * en NULL. El ensayo prometía el valor crudo y habría mentido en las 37
     * filas.
     */
    public function test_lo_que_promete_es_lo_que_pasa(): void
    {
        [$token, $year] = $this->personalYSuYear();
        $archivo = $this->conOtroTelefonoEnLaFilaTres($this->exportacionDeAlumnos($token));

        $plan = $this->ensayar($archivo, $token, $year)->assertStatus(200)->json('plan');

        $prometido = collect($plan)->firstWhere('fila_del_libro', 3);

        $this->assertSame('actualizar', $prometido['accion']);
        $cambio = collect($prometido['cambios'])->firstWhere('campo', 'telefono');
        $this->assertNotNull($cambio, 'El ensayo tiene que ver el teléfono nuevo.');
        // `assertEquals` y no `assertSame`: Excel guarda eso como NÚMERO y la
        // columna de la base es texto, así que el ensayo devuelve el int tal
        // como lo leyó. Exigir la cadena aquí sería exigirle al ensayo que
        // convirtiera antes de tiempo — y convertir documentos es justo lo que
        // rompe a los 7 alumnos con cero a la izquierda.
        $this->assertEquals('6045550000', $cambio['despues']);

        $id = $prometido['alumno_existente']['id'];
        $intactos = collect($plan)->where('accion', 'sin_cambios')->pluck('alumno_existente.id')->all();
        $antesDeLosIntactos = $this->fichasDe($intactos);

        // Y ahora se importa de verdad.
        $this->post(
            "/api/importar/algo/{$year}",
            ['file' => new UploadedFile($archivo, 'alumnos.xlsx', null, null, true)],
            ['Authorization' => 'Bearer '.$token]
        )->assertStatus(200);

        $this->assertSame(
            '6045550000',
            DB::selectOne('SELECT telefono FROM alumnos WHERE id = ?', [$id])->telefono,
            'El ensayo prometió este cambio y la importación tenía que hacerlo.'
        );

        // `updated_at` queda fuera de la comparación, y no es un atajo: medido
        // el 20 sep, de las 37 fichas que el ensayo dio por «sin cambios» la
        // ÚNICA columna que se movió fue ésa, en las 37. El `UPDATE` del
        // importador se ejecuta igual aunque los diecisiete datos sean los
        // mismos, así que la fila se toca sin que cambie un dato. Eso es lo que
        // «sin cambios» significa, y por eso la respuesta del ensayo lo dice con
        // esas palabras en vez de dejar creer que no se escribe.
        $this->assertEquals(
            $this->sinLaMarcaDeTiempo($antesDeLosIntactos),
            $this->sinLaMarcaDeTiempo($this->fichasDe($intactos)),
            'A los que el ensayo dio por «sin cambios» se les movió un DATO: el ensayo prometió de menos.'
        );
    }

    /**
     * Los mismos cinco números, hoja a hoja, y cuadrando con el total.
     *
     * Una hoja puede ser inocente y la de al lado no, y un total de 800 filas no
     * distingue las dos. Se calcula sobre el mismo plan que los totales a
     * propósito: dos cuentas del mismo número se separan, y la que quedaría mal
     * es la que la gente mira antes de pulsar.
     */
    public function test_los_numeros_van_tambien_hoja_a_hoja(): void
    {
        [$token, $year] = $this->personalYSuYear();

        $r = $this->ensayar($this->exportacionDeAlumnos($token), $token, $year)->assertStatus(200);

        $porHoja = $r->json('por_hoja');
        $totales = $r->json('totales');

        $this->assertNotEmpty($porHoja);
        $this->assertSame($totales['filas'], array_sum(array_column($porHoja, 'filas')),
            'Las filas por hoja tienen que sumar el total: si no, hay dos cuentas del mismo número.');
        $this->assertSame($totales['sin_cambios'], array_sum(array_column($porHoja, 'sin_cambios')));
        $this->assertArrayHasKey('documentos_nuevos', $porHoja[0],
            'Es el denominador del aviso de D4: en enero, que sea el 100 % es lo normal.');
    }

    /**
     * Las celdas vacías se cuentan POR COLUMNA, con lo que se guardaría.
     *
     * El traductor no avisa de un hueco —y hace bien: avisar por celda llenaría
     * la pantalla de huecos de las dieciséis bases—, pero **el vacío no es
     * neutro**: por el defecto, una celda vacía de `tipo_de_documento` se guarda
     * como Tarjeta de Identidad. «No sé» acaba siendo una afirmación sobre un
     * menor, y hace falta un número delante para poder decirlo.
     */
    public function test_las_celdas_vacias_se_cuentan_por_columna(): void
    {
        [$token, $year] = $this->personalYSuYear();
        $archivo = $this->conElTipoDeDocumentoVacioEnLaFilaTres($this->exportacionDeAlumnos($token));

        $vacios = $this->ensayar($archivo, $token, $year)->assertStatus(200)->json('vacios');

        $tipo = collect($vacios)->firstWhere('columna', 'tipo_de_documento');

        $this->assertNotNull($tipo, 'Una celda vacía en el tipo de documento tiene que contarse.');
        $this->assertGreaterThanOrEqual(1, $tipo['veces']);
        $this->assertStringContainsString('TARJETA DE IDENTIDAD', $tipo['consecuencia'],
            'Lo que hace falta no es «hay 5 vacías», es qué se guarda en esas 5.');
    }

    /**
     * Y el defecto viaja estructurado, con el literal LEÍDO DEL CATÁLOGO.
     *
     * El importador clava `tipo_doc = 3` —eso es código— pero qué es el 3 lo
     * dice cada base. `existe_en_el_catalogo` está para el caso que nadie ha
     * mirado: un colegio sin esa fila recibiría una referencia rota, hoy en
     * silencio.
     */
    public function test_el_valor_por_defecto_viaja_estructurado(): void
    {
        [$token, $year] = $this->personalYSuYear();

        $columnas = $this->ensayar($this->exportacionDeAlumnos($token), $token, $year)
            ->assertStatus(200)->json('columnas_destino');

        $tipo = collect($columnas)->firstWhere('clave', 'tipo_de_documento');

        $this->assertSame(3, $tipo['valor_por_defecto']['id']);
        $this->assertTrue($tipo['valor_por_defecto']['existe_en_el_catalogo']);
        $this->assertNotNull($tipo['valor_por_defecto']['literal'],
            'El literal sale del catálogo del colegio, no de una constante del código.');
        $this->assertSame(
            DB::selectOne('SELECT tipo FROM tipos_documentos WHERE id = 3')->tipo,
            $tipo['valor_por_defecto']['literal']
        );
    }

    /**
     * Un fichero que no se puede leer contesta 422 EN JSON, no un 500 en HTML.
     *
     * Lo preguntó la sesión del front —su pantalla llama al ensayo con
     * `responseType: 'json'`, así que un 500 en HTML se convierte en «no se pudo
     * leer el archivo» y el motivo se pierde—. Y hay un segundo motivo más
     * serio: con `APP_DEBUG=true` el cuerpo de un 500 trae `Host`, `Port` y
     * `Database`.
     *
     * Aquí se puede hacer porque **el ensayo no escribe nada**: no hay nada a
     * medias que explicar. En `postAlgo` el 500 se deja pasar a propósito,
     * porque cambiarlo es tocar el contrato de una ruta viva.
     */
    public function test_un_fichero_ilegible_contesta_json_y_no_un_500(): void
    {
        [$token, $year] = $this->personalYSuYear();

        $basura = tempnam(sys_get_temp_dir(), 'ensayo').'.xlsx';
        file_put_contents($basura, 'esto no es un libro de excel');

        $r = $this->post(
            "/api/importar/alumnos/ensayo/{$year}",
            ['file' => new UploadedFile($basura, 'alumnos.xlsx', null, null, true)],
            ['Authorization' => 'Bearer '.$token]
        );

        $r->assertStatus(422)->assertJson(['ok' => false]);

        json_decode($r->getContent());
        $this->assertSame(JSON_ERROR_NONE, json_last_error(),
            'Si esto no parsea, la pantalla del front lo convierte en «no se pudo subir» y pierde el motivo.');

        $this->assertNotEmpty($r->json('detalle'), 'El motivo tiene que viajar, o no se puede diagnosticar.');
        $this->assertStringNotContainsString('/app/', (string) $r->json('detalle'),
            'La ruta del servidor no le sirve a nadie al otro lado.');
    }

    /**
     * LOS NÚMEROS DEL ENSAYO Y LOS DE LA SUBIDA SON COMPARABLES.
     *
     * Es lo que pide el escenario 9: el informe final **compara lo prometido con
     * lo hecho** y enseña la diferencia si la hay. Para eso los dos conjuntos
     * tienen que hablar de lo mismo, y este test es el que lo fija — porque se
     * parecen lo bastante como para restarlos mal.
     *
     * **La correspondencia no es uno a uno, y ésa es la trampa:**
     *
     * - `hechos.actualizados` incluye los `sin_cambios` del ensayo. El `UPDATE`
     *   se ejecuta igual aunque los diecisiete datos sean los mismos, así que
     *   desde abajo no se distinguen. Hay que sumar los dos del ensayo.
     * - `hechos.ya_estaban_hechas` **no tiene pareja en el ensayo**: son filas de
     *   una tanda anterior, y el ensayo no sabe nada de puntos de control.
     * - `hechos.sin_primer_nombre` sí es `totales.se_saltan`. Los dos contadores
     *   se llamaban casi igual y contaban cosas distintas hasta el 20 sep.
     */
    public function test_lo_prometido_y_lo_hecho_se_pueden_comparar(): void
    {
        [$token, $year] = $this->personalYSuYear();
        $archivo = $this->exportacionDeAlumnos($token);

        $totales = $this->ensayar($archivo, $token, $year)->assertStatus(200)->json('totales');

        $hechos = $this->post(
            "/api/importar/algo/{$year}",
            ['file' => new UploadedFile($archivo, 'alumnos.xlsx', null, null, true)],
            ['Authorization' => 'Bearer '.$token]
        )->assertStatus(200)->json('hechos');

        $this->assertSame($totales['filas'], $hechos['filas'],
            'Las filas que el ensayo dijo que iba a mirar no son las que miró.');

        $this->assertSame($totales['crear'], $hechos['creados'],
            'El ensayo prometió crear otra cantidad de alumnos de los que se crearon.');

        $this->assertSame(
            $totales['actualizar'] + $totales['sin_cambios'],
            $hechos['actualizados'],
            'Desde abajo no se distingue «actualizar» de «sin cambios»: el UPDATE corre igual.'
        );

        $this->assertSame($totales['se_saltan'], $hechos['sin_primer_nombre'],
            'Las filas que no crean nada tienen que contarse igual en los dos sitios.');

        $this->assertSame($totales['crear'], $hechos['usuarios_creados'],
            'Cada alumno creado estrena usuario: si esto se separa, hay cuentas huérfanas o alumnos sin entrar.');
    }

    /**
     * UNA HOJA A LA QUE LE FALTA UNA COLUMNA SE ESTUDIA IGUAL, y dice cuál.
     *
     * Es el escenario 2, y es el caso que hoy hace reventar la importación de
     * verdad: `ImporterFixer::verificar()` lee las claves sin comprobar, así que
     * un libro sin la columna de la fecha da «Undefined array key
     * "fecha_de_nacim"» **a media faena y con medio grupo ya escrito**. El
     * ensayo rellena lo que falte antes de traducir, precisamente para poder
     * contestar en vez de morir.
     *
     * Y `faltan` va POR HOJA a propósito: un libro puede traer la fecha en sexto
     * y no en jardín, y «falta en Jar» se arregla mirando esa pestaña mientras
     * que «falta en 1 hoja» no.
     */
    public function test_una_hoja_sin_una_columna_se_estudia_y_se_dice_cual(): void
    {
        [$token, $year] = $this->personalYSuYear();
        $archivo = $this->sinLaColumna($this->exportacionDeAlumnos($token), 'fecha_de_nacim');

        $r = $this->ensayar($archivo, $token, $year)->assertStatus(200);

        $hoja = $r->json('hojas')[0];

        $this->assertContains('fecha_de_nacim', $hoja['faltan'],
            'La columna que falta hay que nombrarla: hoy eso es un 500 que dice la clave y no la hoja.');
        $this->assertGreaterThan(0, $r->json('totales.filas'),
            'Con una columna menos el ensayo tiene que seguir estudiando las filas, no rendirse.');

        // Y la consecuencia de que falte, que es lo que la pantalla enseña.
        $columna = collect($r->json('columnas_destino'))->firstWhere('clave', 'fecha_de_nacim');
        $this->assertStringContainsString('BORRA', $columna['si_falta']);
    }

    /**
     * El ensayo dice de qué fichero es el plan, con la misma huella que usa la
     * importación para reconocer «el mismo archivo».
     *
     * Sin eso, el plan es una promesa condicionada y la condición no se puede
     * comprobar: «se crean 12, se actualizan 25» es cierto para ESE libro, y si
     * se sube otro —o el mismo con una celda corregida— los números siguen
     * saliendo, sólo que son otros, y nadie se entera.
     */
    public function test_el_plan_dice_de_que_fichero_es(): void
    {
        [$token, $year] = $this->personalYSuYear();
        $archivo = $this->exportacionDeAlumnos($token);

        $huella = $this->ensayar($archivo, $token, $year)->assertStatus(200)->json('huella');

        $this->assertSame(hash_file('sha256', $archivo), $huella);

        // Y la misma con la que la subida reconoce el archivo, o las dos no
        // estarían hablando de lo mismo.
        $this->post(
            "/api/importar/algo/{$year}",
            ['file' => new UploadedFile($archivo, 'alumnos.xlsx', null, null, true)],
            ['Authorization' => 'Bearer '.$token]
        )->assertStatus(200);

        $this->assertSame(
            $huella,
            DB::selectOne('SELECT huella FROM importaciones ORDER BY id DESC LIMIT 1')->huella
        );
    }

    /**
     * El ensayo DICE que no estudia a los acudientes, en vez de contar cero.
     *
     * Este campo decía `acudientes_tocados: 0` y era falso: la v1 sólo estudia
     * al alumno, pero **la subida sí escribe acudientes y parentescos**. Un 0 no
     * decía «no se tocan», decía «no los he mirado», y las dos se leen igual en
     * una pantalla.
     *
     * Es el fallo que este módulo entero persigue —un número plausible que
     * afirma algo que nadie comprobó— cometido en la respuesta que viene a
     * evitarlo. El test existe para que no vuelva como número.
     */
    public function test_no_cuenta_cero_acudientes_sino_que_dice_que_no_los_mira(): void
    {
        [$token, $year] = $this->personalYSuYear();

        $colaterales = $this->ensayar($this->exportacionDeAlumnos($token), $token, $year)
            ->assertStatus(200)->json('efectos_colaterales');

        $this->assertArrayNotHasKey('acudientes_tocados', $colaterales,
            'Un cero ahí afirma que no se tocan, y la subida sí los escribe.');

        $this->assertFalse($colaterales['acudientes']['los_estudia_el_ensayo']);
        $this->assertTrue($colaterales['acudientes']['los_escribe_la_subida']);
    }

    /**
     * Las columnas de acudiente NO salen como «sobran».
     *
     * Salían todas juntas, y la pantalla las enseñaba bajo «no las usa MyVc y se
     * ignoran». **Para las de acudiente eso es falso**: el importador las lee y
     * escribe acudientes y parentescos con ellas. Decir que se ignoran cuando
     * van a crear personas es la clase de frase tranquilizadora y falsa que este
     * módulo existe para quitar.
     *
     * Lo vio la sesión del front conduciendo la pantalla contra el docker — que
     * es donde se ve lo que una respuesta **parece decir**, y no sólo lo que
     * dice.
     */
    public function test_las_columnas_de_acudiente_no_se_dan_por_ignoradas(): void
    {
        [$token, $year] = $this->personalYSuYear();

        $hojas = $this->ensayar($this->exportacionDeAlumnos($token), $token, $year)
            ->assertStatus(200)->json('hojas');

        $conDatos = collect($hojas)->firstWhere('filas', '>', 0);

        $this->assertNotEmpty($conDatos['de_acudiente_no_estudiadas'],
            'El modelo trae columnas de acudiente y tienen que salir declaradas como no estudiadas.');

        foreach ($conDatos['sobran'] as $columna) {
            $this->assertStringNotContainsString('acud', $columna,
                "«{$columna}» sale como que sobra, y el importador la usa para escribir acudientes.");
        }
    }

    /**
     * EL ENSAYO DICE SI LA IMPORTACIÓN VA A FALLAR ENTERA, no sólo qué hoja no
     * casa.
     *
     * Es la respuesta más importante que puede dar, y no la daba: el dato
     * estaba —`coincide_con: null`— pero **no la consecuencia**. Conduciendo, la
     * pantalla del front pintó esa hoja como «Vacía · no se importa»,
     * inofensiva, dejó pulsar «Importar 32 alumnos» y la subida contestó 500.
     * Mi dato era correcto y la lectura que inducía, falsa — el mismo animal que
     * el `acudientes_tocados: 0`.
     */
    public function test_dice_si_la_importacion_va_a_fallar_entera(): void
    {
        [$token, $year] = $this->personalYSuYear();
        $archivo = $this->conLaPrimeraHojaRenombrada($this->exportacionDeAlumnos($token), 'NOEXISTE');

        $r = $this->ensayar($archivo, $token, $year)->assertStatus(200);

        $this->assertFalse($r->json('puede_importarse'),
            'Una hoja que no es de ningún grupo detiene la importación, y hay que decirlo ANTES de pulsar.');

        $bloqueo = $r->json('bloqueos')[0];

        $this->assertSame('hoja_sin_grupo', $bloqueo['tipo']);
        $this->assertSame('NOEXISTE', $bloqueo['hoja']);
        $this->assertStringContainsString('ya habrán quedado escritas', $bloqueo['motivo'],
            'Lo que hace falta no es «esta hoja no casa», es que la importación para y deja lo anterior dentro.');
    }

    /** Y con el libro bueno dice que sí, o el campo de arriba no distinguiría nada. */
    public function test_con_el_libro_bueno_dice_que_puede_importarse(): void
    {
        [$token, $year] = $this->personalYSuYear();

        $this->ensayar($this->exportacionDeAlumnos($token), $token, $year)
            ->assertStatus(200)
            ->assertJson(['puede_importarse' => true, 'bloqueos' => []]);
    }

    /**
     * Y UNA HOJA VACÍA CON EL NOMBRE MALO DETIENE IGUAL.
     *
     * Es lo que hizo fallar a la pantalla: un `filas > 0` daba por hecho que una
     * hoja sin alumnos no puede hacer daño. El importador resuelve la pestaña
     * contra `grupos` **antes** de mirar si trae filas, así que «no tiene
     * alumnos» no la hace inofensiva.
     */
    public function test_una_hoja_vacia_con_nombre_malo_tambien_bloquea(): void
    {
        [$token, $year] = $this->personalYSuYear();
        $archivo = $this->conUnaHojaVaciaLlamada($this->exportacionDeAlumnos($token), 'NOEXISTE');

        $r = $this->ensayar($archivo, $token, $year)->assertStatus(200);

        $this->assertFalse($r->json('puede_importarse'));
        $this->assertContains('NOEXISTE', array_column($r->json('bloqueos'), 'hoja'));
    }

    /** El guard, que es el mismo de la subida. */
    public function test_sin_token_no_contesta(): void
    {
        $this->post('/api/importar/alumnos/ensayo/2026')->assertStatus(401);
    }

    /** Y sin fichero contesta 422, no un 200 con una cadena dentro. */
    public function test_sin_fichero_contesta_422(): void
    {
        [$token, $year] = $this->personalYSuYear();

        $this->post("/api/importar/alumnos/ensayo/{$year}", [], ['Authorization' => 'Bearer '.$token])
            ->assertStatus(422)
            ->assertJson(['ok' => false]);
    }

    // ── andamio ──────────────────────────────────────────────────────────────

    /** @return array{0: string, 1: int} */
    private function personalYSuYear(): array
    {
        $usuario = $this->usuarioDeTipo('Usuario');

        $year = DB::table('periodos')
            ->join('years', 'years.id', '=', 'periodos.year_id')
            ->where('periodos.id', $usuario->periodo_id)
            ->value('years.year');

        return [$this->tokenDe($usuario->username), (int) $year];
    }

    /** Las cinco tablas que una fila de alumno toca, más la del punto de control. */
    private function censoDeLasTablas(): array
    {
        $censo = [];

        foreach (['alumnos', 'users', 'matriculas', 'acudientes', 'parentescos', 'importaciones'] as $tabla) {
            $censo[$tabla] = (int) DB::selectOne("SELECT COUNT(*) AS n FROM {$tabla}")->n;
        }

        return $censo;
    }

    private function exportacionDeAlumnos(string $token): string
    {
        $r = $this->get('/api/users/export', ['Authorization' => 'Bearer '.$token])->assertStatus(200);

        $copia = tempnam(sys_get_temp_dir(), 'ensayo').'.xlsx';
        copy($this->archivoDescargado($r), $copia);

        return $copia;
    }

    private function ensayar(string $archivo, string $token, int $year)
    {
        return $this->post(
            "/api/importar/alumnos/ensayo/{$year}",
            ['file' => new UploadedFile($archivo, 'alumnos.xlsx', null, null, true)],
            ['Authorization' => 'Bearer '.$token]
        );
    }

    /** Un tipo de documento que no existe y un estado que no cabe, en la fila 3. */
    private function hojaConDefectos(string $archivo): string
    {
        $libro = IOFactory::load($archivo);
        $hoja = $libro->getSheet(0);
        $columnas = $this->columnasDe($hoja);

        $hoja->setCellValue($columnas['tipo_de_documento'].'3', 'CARNÉ DIPLOMÁTICO');
        $hoja->setCellValue($columnas['estado_matricula'].'3', 'Activo');

        return $this->guardar($libro);
    }

    /**
     * **Sin tiempo, el ensayo se recorta y lo DICE — no revienta.**
     *
     * Antes de esto un libro grande daba 500 por `max_execution_time` (medido
     * por `myvc-front-51`: 421 filas 2 s, 2.085 12 s, 4.133 **500**), y ese 500
     * llega al navegador **sin cabeceras de CORS**, así que la pantalla lo
     * confundía con un fichero ilegible y decía «no se pudo leer el archivo» de
     * un fichero perfectamente legible.
     *
     * Lo que se comprueba no es el 200: es que **`puede_importarse` no diga que
     * sí**. Las filas que no se miraron pueden traer la hoja sin grupo que hace
     * reventar la importación entera, y un aval que nadie dio es peor que un
     * error.
     */
    public function test_sin_tiempo_el_ensayo_se_recorta_y_no_avala_nada(): void
    {
        [$token, $year] = $this->personalYSuYear();
        $archivo = $this->exportacionDeAlumnos($token);

        config(['importacion.segundos_del_ensayo' => 0]);

        $r = $this->ensayar($archivo, $token, $year)->assertStatus(200);

        $this->assertFalse($r->json('completo'), 'Se recortó y dijo que el plan estaba completo.');
        $this->assertSame(0, $r->json('filas_estudiadas'));
        $this->assertGreaterThan(0, $r->json('filas_del_libro'),
            'No contó las filas del libro, así que no puede decir sobre cuántas se pronuncia.');

        $this->assertNull($r->json('puede_importarse'),
            'Un plan recortado avaló la importación. `null` es «no se sabe» y `false` es «va a '
            .'fallar»: son dos frases distintas, y `true` aquí deja pulsar Importar sin aval.');
    }

    /** Y con tiempo de sobra el plan se declara completo, que es el caso normal. */
    public function test_con_tiempo_el_plan_se_declara_completo(): void
    {
        [$token, $year] = $this->personalYSuYear();

        config(['importacion.segundos_del_ensayo' => 120]);

        $r = $this->ensayar($this->exportacionDeAlumnos($token), $token, $year)->assertStatus(200);

        $this->assertTrue($r->json('completo'));
        $this->assertSame($r->json('filas_del_libro'), $r->json('filas_estudiadas'));
        $this->assertNotNull($r->json('puede_importarse'));
    }

    /** Un estado de cuatro caracteres que no es ninguno de los del colegio. */
    private function hojaConUnEstadoQueCabeYNoExiste(string $archivo): string
    {
        $libro = IOFactory::load($archivo);
        $hoja = $libro->getSheet(0);

        $hoja->setCellValue($this->columnasDe($hoja)['estado_matricula'].'3', 'ACTV');

        return $this->guardar($libro);
    }

    private function conLaPrimeraHojaRenombrada(string $archivo, string $nombre): string
    {
        $libro = IOFactory::load($archivo);
        $libro->getSheet(0)->setTitle($nombre);

        return $this->guardar($libro);
    }

    /** Le pone a la fila 4 el documento de la 3: el mismo documento dos veces. */
    private function conElDocumentoDeLaFilaCuatroRepetido(string $archivo): string
    {
        $libro = IOFactory::load($archivo);
        $hoja = $libro->getSheet(0);
        $columna = $this->columnasDe($hoja)['nro_de_documento'];

        $hoja->setCellValue($columna.'4', $hoja->getCell($columna.'3')->getValue());

        return $this->guardar($libro);
    }

    /** @param array<int, object> $fichas */
    private function sinLaMarcaDeTiempo(array $fichas): array
    {
        return array_map(function ($ficha) {
            $copia = (array) $ficha;
            unset($copia['updated_at'], $copia['updated_by']);

            return $copia;
        }, $fichas);
    }

    /** Las fichas tal cual, para comparar antes y después sin nombrar columnas. */
    private function fichasDe(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $marcas = implode(',', array_fill(0, count($ids), '?'));

        return DB::select("SELECT * FROM alumnos WHERE id IN ({$marcas}) ORDER BY id", $ids);
    }

    private function conElTipoDeDocumentoVacioEnLaFilaTres(string $archivo): string
    {
        $libro = IOFactory::load($archivo);
        $hoja = $libro->getSheet(0);

        $hoja->setCellValue($this->columnasDe($hoja)['tipo_de_documento'].'3', null);

        return $this->guardar($libro);
    }

    /** Añade una pestaña sin una sola fila, con el nombre que se le diga. */
    private function conUnaHojaVaciaLlamada(string $archivo, string $nombre): string
    {
        $libro = IOFactory::load($archivo);
        $libro->createSheet()->setTitle($nombre);

        return $this->guardar($libro);
    }

    /** Borra del libro la columna que se le diga, por su slug. */
    private function sinLaColumna(string $archivo, string $slug): string
    {
        $libro = IOFactory::load($archivo);
        $hoja = $libro->getSheet(0);

        $hoja->removeColumn($this->columnasDe($hoja)[$slug]);

        return $this->guardar($libro);
    }

    /** Un teléfono que no está en el seed, para tener un cambio de verdad. */
    private function conOtroTelefonoEnLaFilaTres(string $archivo): string
    {
        $libro = IOFactory::load($archivo);
        $hoja = $libro->getSheet(0);

        $hoja->setCellValue($this->columnasDe($hoja)['telefono'].'3', '6045550000');

        return $this->guardar($libro);
    }

    private function guardar($libro): string
    {
        $ruta = tempnam(sys_get_temp_dir(), 'ensayo').'.xlsx';
        (new EscritorXlsx($libro))->save($ruta);

        return $ruta;
    }

    /** @return array<string, string> */
    private function columnasDe($hoja): array
    {
        $columnas = [];

        foreach ($hoja->getRowIterator(2, 2) as $fila) {
            foreach ($fila->getCellIterator() as $celda) {
                $titulo = trim((string) $celda->getValue());

                if ($titulo !== '') {
                    // Los encabezados se normalizan como hace `WithHeadingRow`:
                    // minúsculas, guiones bajos Y SIN TILDE. «Estado Matrícula»
                    // llega al importador como `estado_matricula`, no como
                    // `estado_matrícula` — que es el nombre que tendría si sólo
                    // se cambiaran los espacios.
                    $slug = strtr(mb_strtolower($titulo, 'UTF-8'), [
                        'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n',
                    ]);

                    $columnas[str_replace(' ', '_', $slug)] = $celda->getColumn();
                }
            }
        }

        return $columnas;
    }
}
