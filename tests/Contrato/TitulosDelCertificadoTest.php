<?php

namespace Tests\Contrato;

use App\Models\Year;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * **El título que va impreso arriba de los dos certificados**, por año.
 *
 * Encargo de Joseth del 15 sep 2026. Hasta ese día los dos papeles de *Informes →
 * Finales* —«Certificado final» y «Certificado periodos»— llevaban el título
 * **escrito dentro de la plantilla** de los dos fronts, y el colegio no podía
 * cambiarlo. El documento entero está en
 * `docs/migracion/38-los-titulos-del-certificado.md`.
 *
 * ## Lo que este fichero mira es el RESULTADO, no la columna
 *
 * La prueba que sostiene la entrega no es que la migración corriera: es que **el
 * título llegue dentro de la respuesta que pinta el certificado**
 * (`PUT bolfinales/detailed-notas-year/{grupo}`). Una columna escrita que no viaja
 * en esa respuesta es exactamente `profesores.tono` antes del 4 sep: una columna que
 * nadie puede leer ni escribir y un renglón vacío para siempre.
 *
 * Por eso el caso central compara contra el `year` de esa respuesta y no contra
 * `SELECT`.
 *
 * ## Y mira las dos puertas de escritura, no una
 *
 * `PUT certificados/encabezado` escribe los títulos y comprueba el invariante —no
 * vacío, 255 caracteres—. `PUT years/toggle-cambiar-valor` escribe **cualquier
 * columna de `years`** con el mismo `auth.personal`, así que sin el corte de allí la
 * comprobación de aquí sería un cartel y no una puerta. Las dos van en este fichero
 * a propósito: separadas, la segunda parece un test de otra cosa y se borra.
 */
class TitulosDelCertificadoTest extends CasoDeContrato
{
    /**
     * Las dos columnas. **Los textos no se copian aquí**: salen de
     * `Year::TITULOS_POR_DEFECTO`, porque un test que reescribiera las cadenas
     * comprobaría que el código dice lo que el test dice — y saldría verde el día que
     * los dos se equivoquen igual. Lo que se cruza contra una fuente independiente es
     * `SHOW COLUMNS`, abajo.
     *
     * Los nombres sí están, y se comparan con los del modelo en el primer caso: es lo
     * que hace que añadir un tercer título **no** pase inadvertido por aquí.
     */
    private const COLUMNAS = ['titulo_certificado_final', 'titulo_certificado_periodos'];

    private function personal(): object
    {
        $grupo = $this->grupoConAlumnos();

        return (object) [
            'year_id' => (int) $grupo->year_id,
            'grupo' => $grupo,
            'token' => $this->tokenDelPersonalDe((int) $grupo->year_id),
        ];
    }

    /**
     * El defecto del modelo y el de la base son el mismo.
     *
     * La constante vive en `Year` y el `DEFAULT` en la columna, y la migración
     * escribe **su propio literal** a propósito —una migración es lo que pasó un día
     * y no debe cambiar hacia atrás—. Esa duplicación es segura sólo mientras algo
     * la compare: es la trampa que ya llevan escritas `Autoriza::PERMISO_*` y
     * `Year::MODELOS_DE_EVALUACION`, dos sitios que dicen una cadena y no falla nada
     * hasta que alguien guarda el valor que sólo conoce uno de los dos.
     */
    #[Test]
    public function el_defecto_del_modelo_y_el_de_la_base_son_el_mismo(): void
    {
        // `SHOW COLUMNS ... LIKE ?` no admite binding —MySQL da un 1064 en el `?`—, así
        // que se lee la tabla entera y se busca aquí. Los nombres salen de `COLUMNAS`,
        // que es una constante de este fichero, no del cliente.
        $definiciones = collect(DB::select('SHOW COLUMNS FROM years'))->keyBy('Field');

        $this->assertSame(self::COLUMNAS, array_keys(Year::TITULOS_POR_DEFECTO),
            'La lista del modelo y la de este test han dejado de nombrar las mismas columnas.');

        foreach (self::COLUMNAS as $columna) {
            $definicion = $definiciones->get($columna);

            $this->assertNotNull($definicion, "La columna `{$columna}` no existe: ¿corrió la migración?");

            $this->assertSame(
                Year::TITULOS_POR_DEFECTO[$columna],
                $definicion->Default,
                "El `DEFAULT` de `{$columna}` y `Year::TITULOS_POR_DEFECTO` han dejado de decir lo "
                .'mismo. Un año nuevo nacería con un título y el código validaría contra otro.'
            );

            $this->assertSame('NO', $definicion->Null,
                "`{$columna}` admite NULL, y un certificado sin título no es un estado que exista: "
                .'el `@if` que lo leyera tendría que inventarse un texto de respaldo, que es la '
                .'cadena dentro de la plantilla que esto viene a quitar.');
        }

        // **Y son distintos**, que es media entrega. Los dos papeles decían lo mismo
        // porque comparten plantilla, no porque nadie lo decidiera: el certificado por
        // periodos se emite con el año SIN cerrar. Si alguien vuelve a igualarlos, el
        // parcial afirma otra vez en papel firmado algo que no es.
        $this->assertNotSame(
            Year::TITULOS_POR_DEFECTO['titulo_certificado_final'],
            Year::TITULOS_POR_DEFECTO['titulo_certificado_periodos'],
            'Los dos defectos han vuelto a ser el mismo texto. El del parcial lleva `PARCIAL` '
            .'detrás a propósito (Joseth, 15 sep 2026): sin esa palabra, un certificado emitido '
            .'a mitad de año se lee como si certificara el año entero.'
        );
    }

    /**
     * Los años que ya existían amanecen con el título puesto.
     *
     * `ADD COLUMN ... NOT NULL DEFAULT` rellena las filas que ya están, así que no
     * hace falta tocar ninguna. Lo que esto comprueba es que de verdad las rellenó
     * —y con la **Ñ** y la **É** enteras, que es donde se rompen estas cosas: el
     * defecto son 33 caracteres y 35 bytes.
     */
    #[Test]
    public function todos_los_anios_nacen_con_el_titulo_puesto(): void
    {
        foreach (self::COLUMNAS as $columna) {
            $vacios = DB::table('years')->whereNull('deleted_at')
                ->where(function ($q) use ($columna) {
                    $q->whereNull($columna)->orWhere($columna, '');
                })->count();

            $this->assertSame(0, $vacios, "Hay años con `{$columna}` vacío.");
        }

        $fila = DB::selectOne('SELECT titulo_certificado_final AS final,
            titulo_certificado_periodos AS periodos FROM years LIMIT 1');

        $this->assertSame(Year::TITULOS_POR_DEFECTO['titulo_certificado_final'], $fila->final,
            'El defecto llegó cambiado a la fila. Si difiere en la Ñ o en la É, el problema es de '
            .'codificación y no de la migración.');

        $this->assertSame(Year::TITULOS_POR_DEFECTO['titulo_certificado_periodos'], $fila->periodos,
            'El año existente no se llevó el defecto del PARCIAL: si se llevó el del final, la '
            .'migración puso el mismo `DEFAULT` en las dos columnas.');
    }

    /**
     * **El caso central: los dos títulos viajan en la respuesta del certificado.**
     *
     * `Year::datos()` nombra las columnas una a una —eran 36 de las 74 el día de la
     * medición—, así que una columna de `years` **no llega sola** a este papel por
     * mucho que exista. Si esta prueba
     * se pone roja, el certificado sigue imprimiendo lo que tenga escrito dentro y
     * la entrega no sirve para nada.
     */
    #[Test]
    public function el_certificado_recibe_los_dos_titulos(): void
    {
        $p = $this->personal();

        $cuerpo = $this->putJson('/api/bolfinales/detailed-notas-year/'.$p->grupo->id, [],
            ['Authorization' => 'Bearer '.$p->token])->assertStatus(200)->json();

        $year = $cuerpo[1] ?? null;

        $this->assertIsArray($year, 'La respuesta no trae el año en la posición 1 de la tupla.');

        foreach (self::COLUMNAS as $columna) {
            $this->assertArrayHasKey($columna, $year,
                "`{$columna}` no viaja en el `year` del certificado. La columna existe pero la "
                .'plantilla no la puede leer: hay que nombrarla en `Year::datos()`.');

            $this->assertSame(Year::TITULOS_POR_DEFECTO[$columna], $year[$columna]);
        }
    }

    /**
     * Y en la rama del año que **no** es el actual, que es la otra mitad de
     * `Year::datos()`.
     *
     * Se llama con `year_selected`, que es lo que `BolfinalesController` mira para
     * pedir los datos del año del informe en vez de los del año de hoy. Las dos
     * ramas son dos consultas escritas a mano, casi iguales y **editadas por
     * separado**: arreglar una y dejarse la otra es el fallo natural aquí, y desde
     * la pantalla se ve como «en los años pasados el título sale vacío».
     */
    #[Test]
    public function tambien_en_la_rama_del_anio_que_no_es_el_actual(): void
    {
        $consulta = (new \ReflectionMethod(Year::class, 'datos'))->getFileName();

        $fuente = file_get_contents($consulta);

        foreach (self::COLUMNAS as $columna) {
            $this->assertSame(2, substr_count($fuente, 'y.'.$columna),
                "`{$columna}` no está en las DOS ramas de `Year::datos()`. La de `\$actual=false` "
                .'es la que sirve los años cerrados, que es de donde se piden los certificados '
                .'viejos.');
        }
    }

    /**
     * Se escribe un título y el encabezado **no se toca**.
     *
     * Hasta el 15 sep 2026 `putEncabezado` escribía `encabezado_certificado` viniera
     * o no en el cuerpo, así que un `PUT` que no lo mandara **lo borraba**. Con tres
     * campos eso sería un destructor silencioso: la pantalla que guarda un título
     * vaciaría el encabezado del certificado, que es papel firmado.
     */
    #[Test]
    public function escribir_un_titulo_no_borra_el_encabezado(): void
    {
        $p = $this->personal();

        DB::table('years')->where('id', $p->year_id)
            ->update(['encabezado_certificado' => 'EL ENCABEZADO QUE NO SE DEBE PERDER']);

        $this->putJson('/api/certificados/encabezado', [
            'year_id' => $p->year_id,
            'titulo_certificado_final' => 'ACTA DE DESEMPEÑO',
        ], ['Authorization' => 'Bearer '.$p->token])->assertStatus(200);

        $fila = DB::table('years')->where('id', $p->year_id)->first();

        $this->assertSame('ACTA DE DESEMPEÑO', $fila->titulo_certificado_final);
        $this->assertSame('EL ENCABEZADO QUE NO SE DEBE PERDER', $fila->encabezado_certificado,
            'Guardar un título borró el encabezado del certificado.');
        $this->assertSame(Year::TITULOS_POR_DEFECTO['titulo_certificado_periodos'],
            $fila->titulo_certificado_periodos, 'Guardar un título pisó el otro.');
    }

    /** Y al revés: escribir el encabezado no toca los títulos. */
    #[Test]
    public function escribir_el_encabezado_no_borra_los_titulos(): void
    {
        $p = $this->personal();

        DB::table('years')->where('id', $p->year_id)->update([
            'titulo_certificado_final' => 'CERTIFICADO DE DESEMPEÑO',
            'titulo_certificado_periodos' => 'INFORME PARCIAL',
        ]);

        $this->putJson('/api/certificados/encabezado', [
            'year_id' => $p->year_id,
            'encabezado_certificado' => 'OTRO ENCABEZADO',
        ], ['Authorization' => 'Bearer '.$p->token])->assertStatus(200);

        $fila = DB::table('years')->where('id', $p->year_id)->first();

        $this->assertSame('CERTIFICADO DE DESEMPEÑO', $fila->titulo_certificado_final);
        $this->assertSame('INFORME PARCIAL', $fila->titulo_certificado_periodos);
    }

    /**
     * Un título vacío es 422, y las tres formas de mandarlo vacío también.
     *
     * La cadena vacía, los espacios y el valor que no es texto. Se rechaza en vez de
     * guardarse porque lo que se imprimiría es una cabecera en blanco en un papel
     * que alguien firma, y nadie mira una cabecera que no está.
     *
     * @param  mixed  $valor
     */
    #[DataProvider('titulosQueNoValen')]
    #[Test]
    public function un_titulo_que_no_vale_es_422(string $campo, $valor): void
    {
        $p = $this->personal();

        $antes = DB::table('years')->where('id', $p->year_id)->value($campo);

        $this->putJson('/api/certificados/encabezado', [
            'year_id' => $p->year_id,
            $campo => $valor,
        ], ['Authorization' => 'Bearer '.$p->token])->assertStatus(422);

        $this->assertSame($antes, DB::table('years')->where('id', $p->year_id)->value($campo),
            'Contestó 422 y escribió igual, que es la familia de `respuestas-que-mienten.py` al revés.');
    }

    /** @return array<string, array{string, mixed}> */
    public static function titulosQueNoValen(): array
    {
        $casos = [];

        foreach (self::COLUMNAS as $columna) {
            $casos["{$columna}: cadena vacía"] = [$columna, ''];
            $casos["{$columna}: sólo espacios"] = [$columna, '     '];
            $casos["{$columna}: no es texto"] = [$columna, 12345];
            $casos["{$columna}: pasa de 255"] = [$columna, str_repeat('Ñ', 256)];
        }

        return $casos;
    }

    /**
     * **Y los 255 se cuentan en caracteres, no en bytes.**
     *
     * Un título de 255 **Ñ** son 510 bytes y cabe: la columna es `utf8mb4` y su
     * límite es de caracteres. Contarlo con `strlen` lo rechazaría a la mitad, y es
     * el error que se comete solo — el defecto mismo tiene 33 caracteres y 35 bytes.
     */
    #[Test]
    public function doscientos_cincuenta_y_cinco_enies_caben(): void
    {
        $p = $this->personal();

        $limite = str_repeat('Ñ', 255);

        $this->putJson('/api/certificados/encabezado', [
            'year_id' => $p->year_id,
            'titulo_certificado_final' => $limite,
        ], ['Authorization' => 'Bearer '.$p->token])->assertStatus(200);

        $this->assertSame($limite, DB::table('years')->where('id', $p->year_id)
            ->value('titulo_certificado_final'),
            'El título de 255 caracteres se guardó truncado: el docker trunca en silencio y '
            .'MariaDB aborta, así que esto sale distinto en el servidor de verdad.');
    }

    /**
     * Los espacios de los extremos se recortan: un título con sangría sale torcido en
     * el papel.
     *
     * **Y quien lo recorta hoy NO es el controlador: es `TrimStrings`**, el middleware
     * global de esta API. Se escribe porque un test cuyo verde se lo debe a otro no
     * puede presentarse como prueba de este método — y porque el día que alguien
     * excluya esta ruta de `TrimStrings` (ya excluye las contraseñas, así que la lista
     * no es teórica) este caso tiene que seguir verde por el `trim` del controlador.
     *
     * Lo que sujeta es **el resultado**, que es lo que importa: venga de donde venga el
     * recorte, en la base no queda un título con sangría.
     */
    #[Test]
    public function el_titulo_se_recorta(): void
    {
        $p = $this->personal();

        $this->putJson('/api/certificados/encabezado', [
            'year_id' => $p->year_id,
            'titulo_certificado_periodos' => '   INFORME PARCIAL   ',
        ], ['Authorization' => 'Bearer '.$p->token])->assertStatus(200);

        $this->assertSame('INFORME PARCIAL', DB::table('years')->where('id', $p->year_id)
            ->value('titulo_certificado_periodos'));
    }

    /**
     * Un `PUT` sin ningún texto es 422 y no un 200 que no escribió nada.
     *
     * La familia de `tools/respuestas-que-mienten.py`: quien recibiera «Cambiado»
     * creería que guardó algo.
     */
    #[Test]
    public function sin_ningun_texto_es_422(): void
    {
        $p = $this->personal();

        $this->putJson('/api/certificados/encabezado', ['year_id' => $p->year_id],
            ['Authorization' => 'Bearer '.$p->token])->assertStatus(422);
    }

    /**
     * Y el **404 sigue yendo antes que el 422**: un año que no existe no es un cuerpo
     * mal formado.
     *
     * Lo fija también `ConfigCertificadosTest::test_un_id_que_no_existe_es_404`, que
     * manda exactamente este cuerpo —sólo `year_id`— y espera 404. Se repite aquí
     * porque es el orden lo que se está sujetando, y aquel test no sabe que ahora hay
     * una validación que podría adelantarse.
     */
    #[Test]
    public function un_anio_que_no_existe_sigue_siendo_404_y_no_422(): void
    {
        $p = $this->personal();

        $inventado = ((int) DB::table('years')->max('id')) + 1000;

        $this->putJson('/api/certificados/encabezado', [
            'year_id' => $inventado,
            'titulo_certificado_final' => 'DA IGUAL',
        ], ['Authorization' => 'Bearer '.$p->token])->assertStatus(404);
    }

    /**
     * **La puerta de al lado, que es la que hace que lo de arriba sea una puerta.**
     *
     * `PUT years/toggle-cambiar-valor` escribe cualquier columna de `years` con el
     * mismo `auth.personal`. Sin este corte, la comprobación de
     * `putEncabezado` sería un cartel de «no vacíes el título» con la puerta abierta
     * al lado, y el certificado saldría con la cabecera en blanco sin que nada
     * fallara.
     *
     * No es una restricción de permiso —quien llega aquí ya puede escribir el título
     * por la ruta buena—: es que **por aquí no pasa por la validación**.
     */
    #[Test]
    public function el_generico_no_puede_vaciar_un_titulo(): void
    {
        $p = $this->personal();

        foreach (self::COLUMNAS as $columna) {
            $antes = DB::table('years')->where('id', $p->year_id)->value($columna);

            $this->putJson('/api/years/toggle-cambiar-valor', [
                'year_id' => $p->year_id,
                'campo' => $columna,
                'valor' => '',
            ], ['Authorization' => 'Bearer '.$p->token])->assertStatus(422);

            $this->assertSame($antes, DB::table('years')->where('id', $p->year_id)->value($columna),
                "El genérico vació `{$columna}`: el certificado saldría sin cabecera.");
        }
    }

    /**
     * El año nuevo hereda los dos títulos del anterior.
     *
     * Sin esto, **el colegio que escribió el suyo amanecería con el defecto cada
     * enero**. Aquí muerde más que en sus vecinas de esa lista: `coal` y `coljordan`
     * nacen con un título que no es el que quieren y lo corrigen a mano (§4 del doc
     * 38), así que sin la herencia lo corregirían otra vez todos los años.
     *
     * `CentinelaDeLasColumnasDelAnioNuevoTest` comprueba que las columnas estén
     * **nombradas** en `postStore`; lo que no puede comprobar es de dónde sale el
     * valor. Eso es esto.
     */
    #[Test]
    public function el_anio_nuevo_hereda_los_dos_titulos(): void
    {
        // El sujeto sale del último año **vivo**: `postStore` copia con
        // `Year::where('year', $pedido - 1)->first()`, que lleva `SoftDeletes`. Con
        // `max(year) + 1` el anterior sería el borrado del seed, la copia no correría y
        // este caso saldría verde sin haber heredado nada.
        $pasado = DB::selectOne('SELECT * FROM years WHERE deleted_at IS NULL
            ORDER BY year DESC, id DESC LIMIT 1');

        $this->assertNotNull($pasado, 'No hay año vivo del que heredar.');

        DB::table('years')->where('id', $pasado->id)->update([
            'titulo_certificado_final' => 'CERTIFICADO DE DESEMPEÑO',
            'titulo_certificado_periodos' => 'INFORME DE PERIODO',
        ]);

        $this->withToken($this->tokenDe($this->usuarioDeTipo('Usuario')->username))
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
            ])->assertStatus(200);

        $nuevo = DB::table('years')->where('year', ((int) $pasado->year) + 1)
            ->whereNull('deleted_at')->first();

        $this->assertNotNull($nuevo, 'No se creó el año nuevo.');

        $this->assertSame('CERTIFICADO DE DESEMPEÑO', $nuevo->titulo_certificado_final,
            'El año nuevo perdió el título del certificado final y amaneció con el defecto.');
        $this->assertSame('INFORME DE PERIODO', $nuevo->titulo_certificado_periodos,
            'El año nuevo perdió el título del certificado por periodos.');
    }
}
