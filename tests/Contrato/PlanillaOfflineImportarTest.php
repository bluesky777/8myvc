<?php

namespace Tests\Contrato;

use App\Exports\LibroDeNotas;
use App\Services\LaPlanillaQueSeSube;
use App\Support\Autoriza;
use App\Support\EscalaDeNotas;
use App\Support\FirmaDelLibro;
use App\Support\Reloj;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\Test;

/**
 * **La planilla sin internet, fase 2: el ensayo y la escritura.**
 *
 * `POST planilla-offline/ensayo` y `POST planilla-offline/importar`. El plan está
 * en `myvc_front/PLAN-NOTAS-SIN-INTERNET.md` (§5 y §7) y lo construido en
 * `docs/migracion/49-la-planilla-sin-internet.md`.
 *
 * ## Qué se comprueba aquí, y por qué no se puede comprobar de otra manera
 *
 * **El viaje de ida y vuelta entero.** Cada caso empieza **descargando un libro de
 * verdad con la fase 1**, le escribe encima con PhpSpreadsheet lo que un docente
 * escribiría, lo sube y **mira la base**. Un test que fabricara el `.xlsx` a mano
 * probaría el lector contra un libro que nadie genera; uno que se quedara en la
 * respuesta pasaría con la importación escribiendo en el indicador de al lado.
 *
 * Es la única forma de que las dos mitades —el generador y el lector— se sujeten
 * la una a la otra: **si el mapa de `_myvc` y la rejilla se desalinean, aquí sale
 * rojo y en ningún otro sitio**.
 *
 * ## Los casos que el seed no tiene, se fabrican
 *
 * Un periodo cerrado, una firma rota, un libro de otro colegio y un indicador
 * borrado no están en el seed, y **esperar a que aparezcan es no comprobarlos**.
 * Se fabrican dentro de la transacción del test: unos tocando la base y otros
 * reescribiendo la hoja `_myvc` del libro descargado —con {@see reescribirMetadatos},
 * que vuelve a firmar o deja la firma rota a propósito—.
 */
class PlanillaOfflineImportarTest extends CasoDeContrato
{
    /**
     * La confirmación de la D4, tal y como la manda la pantalla.
     *
     * Constante y no literal repetido en cinco tests: si el nombre de la sección
     * cambiara, lo que pasaría con el literal suelto es que **tres tests seguirían
     * en verde midiendo «sin confirmar»** mientras creen medir «con confirmación».
     */
    private const POR_OTRO = ['por_otro' => ['decision' => 'confirmo']];

    /** Los ficheros temporales de este test, para borrarlos al terminar. @var list<string> */
    private array $temporales = [];

    protected function tearDown(): void
    {
        foreach ($this->temporales as $ruta) {
            if (is_file($ruta)) {
                @unlink($ruta);
            }
        }

        $this->temporales = [];

        parent::tearDown();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // El ensayo no escribe
    // ─────────────────────────────────────────────────────────────────────────

    #[Test]
    public function el_ensayo_no_escribe_ni_una_fila(): void
    {
        $caso = $this->unaPlanilla();

        $ruta = $this->escribiendo($caso['ruta'], $caso['hoja'], [
            [$caso['fila'], $caso['columna'], $this->otroValorPara($caso, $caso['fila'], $caso['columna'])],
        ]);

        $antes = $this->huellaDeLaBase();

        $r = $this->ensayo($caso['token'], $ruta);

        $r->assertStatus(200)->assertJson(['ok' => true, 'escribe' => false]);

        // El botón de la pantalla promete «esto no toca nada». Si algún día alguien
        // mete una escritura en el ensayo, la promesa pasa a mentir **en la pantalla
        // donde la gente pulsa antes de decidir**.
        $this->assertSame($antes, $this->huellaDeLaBase(),
            'El ensayo escribió en la base. Es lo único que no puede hacer.');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // La D3 — las tres puntas
    // ─────────────────────────────────────────────────────────────────────────

    #[Test]
    public function d3_lo_que_solo_cambio_en_el_archivo_entra(): void
    {
        $caso = $this->unaPlanilla();
        $nuevo = $this->otroValorPara($caso, $caso['fila'], $caso['columna']);

        $ruta = $this->escribiendo($caso['ruta'], $caso['hoja'], [[$caso['fila'], $caso['columna'], $nuevo]]);

        $this->ensayo($caso['token'], $ruta)->assertStatus(200)->assertJson(['puede_importarse' => true]);
        $this->importar($caso['token'], $ruta)->assertStatus(200)->assertJson(['ok' => true]);

        $this->assertSame($nuevo, $this->notaDe($caso['alumno_id'], $caso['subunidad_id']),
            'Es el caso normal y es «el trabajo»: el docente cambió la casilla y tiene que entrar.');
    }

    #[Test]
    public function d3_lo_que_solo_cambio_en_el_sistema_no_se_toca(): void
    {
        $caso = $this->unaPlanilla();

        // El libro se descarga y **no se le escribe nada**: la celda sigue valiendo
        // lo que valía. Mientras tanto, alguien cambia esa nota por la web.
        $porLaWeb = $this->otroValorPara($caso, $caso['fila'], $caso['columna']);

        DB::update('UPDATE notas SET nota = ? WHERE alumno_id = ? AND subunidad_id = ?',
            [$porLaWeb, $caso['alumno_id'], $caso['subunidad_id']]);

        $r = $this->ensayo($caso['token'], $caso['ruta'])->assertStatus(200);

        $this->assertSame(0, $r->json('totales.cambiaron'),
            'El docente no tocó ni una casilla: no hay nada que este libro mueva.');
        $this->assertSame([], $r->json('familias.choques'),
            'No hay choque: el docente no tocó esa casilla, así que no hay dos cambios que enfrentar.');

        $this->importar($caso['token'], $caso['ruta'])->assertStatus(200);

        // **La frase del encargo, comprobada**: una casilla que no tocó no se
        // escribe, aunque en la web haya cambiado.
        $this->assertSame($porLaWeb, $this->notaDe($caso['alumno_id'], $caso['subunidad_id']),
            'Subir un libro viejo no puede pisar lo que otro hizo por la web.');
    }

    #[Test]
    public function d3_lo_que_cambio_en_los_dos_sitios_es_un_choque_y_gana_el_sistema(): void
    {
        $caso = $this->unaPlanilla();

        $delArchivo = $this->otroValorPara($caso, $caso['fila'], $caso['columna']);
        $delSistema = $this->otroValorPara($caso, $caso['fila'], $caso['columna'], [$delArchivo]);

        $ruta = $this->escribiendo($caso['ruta'], $caso['hoja'], [[$caso['fila'], $caso['columna'], $delArchivo]]);

        DB::update('UPDATE notas SET nota = ? WHERE alumno_id = ? AND subunidad_id = ?',
            [$delSistema, $caso['alumno_id'], $caso['subunidad_id']]);

        $r = $this->ensayo($caso['token'], $ruta)->assertStatus(200);

        $choques = $r->json('familias.choques');

        $this->assertCount(1, $choques, 'Cambió en los dos sitios y a valores distintos: eso es un choque.');
        $this->assertSame($delArchivo, $choques[0]['valor_archivo']);
        $this->assertSame($delSistema, $choques[0]['valor_sistema']);
        $this->assertNotEmpty($choques[0]['id'],
            'Sin un id estable, la decisión que tome el docente sobre esta línea no puede volver '
            .'a apuntar a la misma nota cuando suba el archivo.');

        // El defecto es el SEGURO, no el cómodo: si el sistema cambió después de la
        // descarga, alguien lo tocó sabiendo lo que hacía. Se comprueba por el
        // resultado, que es lo que se nota.
        $this->assertSame(1, $r->json('totales.se_quedan_fuera'));

        $this->importar($caso['token'], $ruta)->assertStatus(200);

        $this->assertSame($delSistema, $this->notaDe($caso['alumno_id'], $caso['subunidad_id']));
    }

    #[Test]
    public function d3_en_un_choque_el_archivo_puede_mandar_si_se_dice(): void
    {
        $caso = $this->unaPlanilla();

        $delArchivo = $this->otroValorPara($caso, $caso['fila'], $caso['columna']);
        $delSistema = $this->otroValorPara($caso, $caso['fila'], $caso['columna'], [$delArchivo]);

        $ruta = $this->escribiendo($caso['ruta'], $caso['hoja'], [[$caso['fila'], $caso['columna'], $delArchivo]]);

        DB::update('UPDATE notas SET nota = ? WHERE alumno_id = ? AND subunidad_id = ?',
            [$delSistema, $caso['alumno_id'], $caso['subunidad_id']]);

        $this->importar($caso['token'], $ruta, ['choques' => ['por_defecto' => 'archivo']])->assertStatus(200);

        $this->assertSame($delArchivo, $this->notaDe($caso['alumno_id'], $caso['subunidad_id']));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // La D9 — vacía no borra, el guion sí
    // ─────────────────────────────────────────────────────────────────────────

    #[Test]
    public function d9_una_casilla_vacia_no_borra_la_nota(): void
    {
        $caso = $this->unaPlanilla();
        $antes = $this->notaDe($caso['alumno_id'], $caso['subunidad_id']);

        $this->assertNotNull($antes, 'Para que este caso signifique algo la casilla tiene que venir con nota.');

        $ruta = $this->escribiendo($caso['ruta'], $caso['hoja'], [[$caso['fila'], $caso['columna'], null]]);

        $this->importar($caso['token'], $ruta)->assertStatus(200);

        // Sin esta regla, un docente que baja el libro, escribe las notas de un Logro
        // y sube **borraría todo lo demás**: la planilla viene llena (D2) y una hoja
        // pasada por Google Sheets puede traer huecos donde había notas.
        $this->assertSame($antes, $this->notaDe($caso['alumno_id'], $caso['subunidad_id']),
            'Una casilla vacía significa «no la toques», no «bórrala».');
    }

    #[Test]
    public function d9_un_guion_borra_la_nota(): void
    {
        $caso = $this->unaPlanilla();

        $this->assertNotNull($this->notaDe($caso['alumno_id'], $caso['subunidad_id']));

        $ruta = $this->escribiendo($caso['ruta'], $caso['hoja'], [[$caso['fila'], $caso['columna'], '-']]);

        $r = $this->ensayo($caso['token'], $ruta)->assertStatus(200);

        $this->assertSame(1, $r->json('totales.se_borran'));

        $this->importar($caso['token'], $ruta)->assertStatus(200);

        // `NULL` y no cero: desde `2026_09_19_500000_la_casilla_vacia` una casilla
        // sin calificar no vale cero, y escribir un 0 le regalaría al alumno una
        // nota que nadie puso.
        $this->assertNull($this->notaDe($caso['alumno_id'], $caso['subunidad_id']));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // F4 y F5 — la decisión se llavea por el VALOR
    // ─────────────────────────────────────────────────────────────────────────

    #[Test]
    public function f4_un_decimal_no_se_guarda_recortado_y_una_sola_decision_vale_para_todas_sus_celdas(): void
    {
        $caso = $this->unaPlanilla(2);

        $ruta = $this->escribiendo($caso['ruta'], $caso['hoja'], [
            [$caso['fila'], $caso['columna'], '4,5'],
            [$caso['filas'][1], $caso['columna'], '4,5'],
        ]);

        $r = $this->ensayo($caso['token'], $ruta)->assertStatus(200);

        $celdas = $r->json('familias.celdas');

        // **Dos celdas, UNA pregunta.** Es la regla que se hereda entera del
        // importador de alumnos, y aquí es lo único que hace usable la pantalla: una
        // hoja de 45 alumnos por 19 indicadores son 855 celdas.
        $this->assertCount(1, $celdas);
        $this->assertSame('4,5', $celdas[0]['valor']);
        $this->assertSame('decimal', $celdas[0]['tipo']);
        $this->assertSame(2, $celdas[0]['veces']);
        $this->assertTrue($celdas[0]['decidible']);
        $this->assertSame(5, $celdas[0]['sugerido'],
            'Una sugerencia, nunca un valor aplicado: ayuda a decidir y el defecto sigue siendo no escribir.');
        $this->assertNotEmpty($celdas[0]['si_no_hago_nada'],
            'Es la columna que convierte un aviso en una decisión informada, y la escribe el servidor.');
        $this->assertCount(2, $celdas[0]['donde']);

        $antes = $this->notaDe($caso['alumno_id'], $caso['subunidad_id']);

        $this->importar($caso['token'], $ruta)->assertStatus(200);

        $this->assertSame($antes, $this->notaDe($caso['alumno_id'], $caso['subunidad_id']),
            'Un 4,5 guardado como 4 sería la peor forma de perder una nota: sin error y sin aviso.');

        // Y ahora con la decisión tomada: **un renglón contestado, dos celdas
        // escritas**.
        $this->importar($caso['token'], $ruta, [
            'celdas' => [['valor' => '4,5', 'decision' => 'interpretar:5']],
        ])->assertStatus(200);

        $this->assertSame(5, $this->notaDe($caso['alumno_id'], $caso['subunidad_id']));
        $this->assertSame(5, $this->notaDe($caso['alumnos'][1], $caso['subunidad_id']));
    }

    #[Test]
    public function f4_una_formula_se_ve_como_formula_y_no_como_su_resultado(): void
    {
        $caso = $this->unaPlanilla();

        $ruta = $this->escribiendo($caso['ruta'], $caso['hoja'], [
            [$caso['fila'], $caso['columna'], '=1+1'],
        ]);

        $r = $this->ensayo($caso['token'], $ruta)->assertStatus(200);

        $celdas = $r->json('familias.celdas');

        $this->assertCount(1, $celdas);
        $this->assertSame('formula', $celdas[0]['tipo'],
            'Lo que se importa es lo que está escrito. Un lector que devolviera el resultado '
            .'escondería que la casilla no tiene un número.');
        $this->assertNull($celdas[0]['sugerido'],
            'De una fórmula no hay nada que sugerir, y un cero sería inventarse una nota.');
    }

    #[Test]
    public function f5_una_nota_fuera_de_escala_no_entra_y_topar_es_una_decision_por_valor(): void
    {
        $caso = $this->unaPlanilla();

        $maximo = EscalaDeNotas::maximo($caso['year_id']);

        if ($maximo === null) {
            $this->markTestSkipped('Este año no tiene escala configurada, así que no hay tope que probar.');
        }

        $fuera = $maximo + 10;

        $ruta = $this->escribiendo($caso['ruta'], $caso['hoja'], [[$caso['fila'], $caso['columna'], $fuera]]);

        $r = $this->ensayo($caso['token'], $ruta)->assertStatus(200);

        $escala = $r->json('familias.escala');

        $this->assertCount(1, $escala);
        $this->assertSame($fuera, $escala[0]['valor']);
        $this->assertSame($maximo, $escala[0]['maximo'], 'La pantalla tiene que poder enseñar el tope del año.');
        $this->assertNotEmpty($escala[0]['alumnos'], 'Y de quién es la nota, que es lo que se mira para decidir.');

        $antes = $this->notaDe($caso['alumno_id'], $caso['subunidad_id']);

        $this->importar($caso['token'], $ruta)->assertStatus(200);

        $this->assertSame($antes, $this->notaDe($caso['alumno_id'], $caso['subunidad_id']));

        $this->importar($caso['token'], $ruta, [
            'escala' => [['valor' => (string) $fuera, 'decision' => 'topar']],
        ])->assertStatus(200);

        $this->assertSame($maximo, $this->notaDe($caso['alumno_id'], $caso['subunidad_id']));
    }

    #[Test]
    public function la_escala_sale_del_ano_del_periodo_de_la_fila_y_sin_escala_no_se_bloquea_nada(): void
    {
        $caso = $this->unaPlanilla();

        // Se le quita la escala al año. `EscalaDeNotas::maximo()` devuelve `null` y
        // entonces **no se bloquea nada**: es la decisión escrita en esa clase, y va
        // en contra del instinto a propósito —rechazar todo cuando falta la
        // configuración convierte un hueco de configuración en una caída de las
        // notas para un colegio entero—.
        DB::update('UPDATE escalas_de_valoracion SET deleted_at = NOW() WHERE year_id = ?', [$caso['year_id']]);
        EscalaDeNotas::olvidar();

        $ruta = $this->escribiendo($caso['ruta'], $caso['hoja'], [[$caso['fila'], $caso['columna'], 999]]);

        $r = $this->ensayo($caso['token'], $ruta)->assertStatus(200);

        $this->assertSame([], $r->json('familias.escala'));

        $this->importar($caso['token'], $ruta)->assertStatus(200);

        $this->assertSame(999, $this->notaDe($caso['alumno_id'], $caso['subunidad_id']));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // F2 — la estructura cambió
    // ─────────────────────────────────────────────────────────────────────────

    #[Test]
    public function f2_un_indicador_borrado_deja_su_columna_fuera_y_se_puede_mover(): void
    {
        $caso = $this->unaPlanilla();

        $otra = $this->otraColumnaDe($caso);

        if ($otra === null) {
            $this->markTestSkipped('Esta asignatura sólo tiene un indicador: no hay a dónde mover.');
        }

        $nuevo = $this->otroValorPara($caso, $caso['fila'], $caso['columna']);

        $ruta = $this->escribiendo($caso['ruta'], $caso['hoja'], [[$caso['fila'], $caso['columna'], $nuevo]]);

        DB::update('UPDATE subunidades SET deleted_at = NOW() WHERE id = ?', [$caso['subunidad_id']]);

        $r = $this->ensayo($caso['token'], $ruta)->assertStatus(200);

        $borradas = array_values(array_filter(
            $r->json('familias.estructura'), static fn ($e) => $e['tipo'] === 'indicador_borrado'
        ));

        $this->assertCount(1, $borradas);
        $this->assertSame($caso['columna'], $borradas[0]['columna']);
        $this->assertTrue($borradas[0]['decidible'], 'Ésta sí se decide; el peso que cambia, no.');
        $this->assertNotEmpty($borradas[0]['destinos'],
            'Los destinos van CON la pregunta: obligar a otra llamada para saber qué opciones hay '
            .'es lo que convierte una decisión en un formulario.');
        $this->assertArrayHasKey('nombre', $borradas[0]['destinos'][0]);
        $this->assertNotEmpty($borradas[0]['si_no_hago_nada']);

        // Y con la decisión: las notas de la columna huérfana van al indicador que
        // el docente eligió. Es el caso frecuente —coordinación borró el indicador y
        // creó otro con el texto corregido—.
        $this->importar($caso['token'], $ruta, [
            'estructura' => [[
                'hoja' => $caso['hoja'], 'columna' => $caso['columna'],
                'decision' => 'mover:'.$otra['subunidad_id'],
            ]],
        ])->assertStatus(200);

        $this->assertSame($nuevo, $this->notaDe($caso['alumno_id'], $otra['subunidad_id']));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // F3 — el periodo cerrado se lleva SU hoja, y nada más
    // ─────────────────────────────────────────────────────────────────────────

    #[Test]
    public function f3_una_hoja_de_periodo_cerrado_se_cae_y_el_resto_del_libro_entra(): void
    {
        $caso = $this->dosPlanillas();

        $cerrado = DB::selectOne(
            'SELECT id FROM periodos WHERE year_id = ? AND id <> ? AND deleted_at IS NULL ORDER BY numero LIMIT 1',
            [$caso['year_id'], $caso['periodo_id']]
        );

        if ($cerrado === null) {
            $this->markTestSkipped('El año del seed sólo tiene un periodo: no hay otro que cerrar.');
        }

        DB::update('UPDATE periodos SET profes_pueden_editar_notas = 0 WHERE id = ?', [$cerrado->id]);

        // **El caso se fabrica**: hoy la fase 1 baja un libro por periodo, así que un
        // libro con dos periodos dentro no se puede descargar. Se construye
        // reescribiendo `_myvc` —y volviendo a firmar, para que sea un libro
        // legítimo— porque lo que hay que comprobar es la regla, no cómo se llegó a
        // ella: *«un periodo cerrado no tira el libro entero»*.
        $nuevo = $this->otroValorPara($caso, $caso['fila'], $caso['columna']);

        $ruta = $this->escribiendo($caso['ruta'], $caso['hoja'], [[$caso['fila'], $caso['columna'], $nuevo]]);

        $ruta = $this->reescribirMetadatos($ruta, null, function (array $mapas) use ($caso, $cerrado) {
            foreach ($mapas as $i => $mapa) {
                if ($mapa['hoja'] === $caso['otra_hoja']) {
                    $mapas[$i]['periodo_id'] = (int) $cerrado->id;
                }
            }

            return $mapas;
        }, true);

        $r = $this->ensayo($caso['token'], $ruta)->assertStatus(200);

        $hojas = collect($r->json('hojas'))->keyBy('nombre');

        $this->assertTrue($hojas[$caso['otra_hoja']]['fuera']);
        $this->assertFalse($hojas[$caso['otra_hoja']]['periodo_abierto']);
        $this->assertStringContainsString('cerrado', $hojas[$caso['otra_hoja']]['motivo_fuera']);

        $this->assertFalse($hojas[$caso['hoja']]['fuera'],
            'Tirar las cuatro hojas por una es la clase de error que hace que la gente deje de usar una función.');

        $this->importar($caso['token'], $ruta)->assertStatus(200);

        $this->assertSame($nuevo, $this->notaDe($caso['alumno_id'], $caso['subunidad_id']),
            'La hoja del periodo abierto tenía que entrar igual.');
    }

    /**
     * **El motivo de una hoja no puede hablar del libro entero.**
     *
     * El caso de arriba es el raro —una hoja de otro periodo dentro del libro— y el
     * de aquí es el normal: **el periodo es del libro**, así que cerrarlo se lleva
     * todas sus hojas de una vez. Hasta este arreglo, cada una de las que caían traía
     * pegado un *«Las demás hojas del libro entran igual»* escrito mirando sólo a
     * ella, y la misma respuesta lo desmentía tres campos más allá: `totales.entran:
     * 0`. Medido con un docente de verdad: 26 hojas, 26 copias de la frase, cero
     * notas entrando y 917 casillas fuera.
     *
     * Por eso el test mira **las dos cosas a la vez** —que no entra nada y que nadie
     * dice que sí— y no sólo el texto: comprobar la frase sin comprobar el número
     * volvería a pasar el día que la frase cambie de palabras y siga mintiendo.
     */
    #[Test]
    public function f3_si_el_cierre_se_lleva_el_libro_entero_ningun_motivo_dice_que_las_demas_entran(): void
    {
        $caso = $this->dosPlanillas();

        // **El libro se baja abierto y se cierra después**, que es como pasa: el
        // docente bajó su planilla y coordinación cerró el periodo mientras él
        // calificaba sin internet. Aquí no hace falta reescribir `_myvc`: las dos
        // hojas ya son de este periodo, porque la fase 1 baja un libro por periodo.
        DB::update('UPDATE periodos SET profes_pueden_editar_notas = 0 WHERE id = ?', [$caso['periodo_id']]);

        $ruta = $this->escribiendo($caso['ruta'], $caso['hoja'], [
            [$caso['fila'], $caso['columna'], $this->otroValorPara($caso, $caso['fila'], $caso['columna'])],
        ]);

        $r = $this->ensayo($caso['token'], $ruta)->assertStatus(200);

        $hojas = $r->json('hojas');

        $this->assertNotEmpty($hojas, 'El libro bajó sin hojas de asignatura: no hay caso que medir.');
        $this->assertSame(0, (int) $r->json('totales.entran'),
            'Con el periodo del libro cerrado no puede entrar ni una nota; si entra, el caso no es el que dice.');

        foreach ($hojas as $hoja) {
            $this->assertTrue($hoja['fuera'], '«'.$hoja['nombre'].'» tenía que caerse con el periodo cerrado.');
            $this->assertStringContainsString('cerrado', (string) $hoja['motivo_fuera'],
                'Se cayó por otra cosa: entonces este test no está midiendo la F3.');
            $this->assertStringNotContainsString('entran igual', (string) $hoja['motivo_fuera'],
                'El motivo de «'.$hoja['nombre'].'» afirma que las demás hojas entran, y no entra ninguna. '
                .'Esa frase no la puede escribir una hoja: cuando se escribe no se ha mirado el resto.');
        }

        foreach ($r->json('por_hoja') as $fila) {
            $this->assertStringNotContainsString('entran igual', (string) ($fila['nota_pendiente'] ?? ''),
                'La misma frase falsa, por la puerta del acta: `nota_pendiente` copia el motivo.');
        }
    }

    /**
     * **Y la otra mitad: cuando sí es verdad, tiene que seguir diciéndose.**
     *
     * Es el caso de arriba con una sola hoja caída, y el que impide que el arreglo se
     * convierta en «quitar la frase». La información —*«las demás entran igual»*— es
     * justo la que evita que el docente crea que su libro se perdió entero, así que
     * lo que cambia es **quién la escribe**: ya no la hoja, que no puede saberlo,
     * sino el cierre del diagnóstico, que cuenta `totales.entran` antes de decirlo.
     */
    #[Test]
    public function f3_con_una_hoja_caida_y_el_resto_entrando_el_motivo_si_dice_que_las_demas_entran(): void
    {
        $caso = $this->dosPlanillas();

        $cerrado = DB::selectOne(
            'SELECT id FROM periodos WHERE year_id = ? AND id <> ? AND deleted_at IS NULL ORDER BY numero LIMIT 1',
            [$caso['year_id'], $caso['periodo_id']]
        );

        if ($cerrado === null) {
            $this->markTestSkipped('El año del seed sólo tiene un periodo: no hay otro que cerrar.');
        }

        DB::update('UPDATE periodos SET profes_pueden_editar_notas = 0 WHERE id = ?', [$cerrado->id]);

        $ruta = $this->escribiendo($caso['ruta'], $caso['hoja'], [
            [$caso['fila'], $caso['columna'], $this->otroValorPara($caso, $caso['fila'], $caso['columna'])],
        ]);

        $ruta = $this->reescribirMetadatos($ruta, null, function (array $mapas) use ($caso, $cerrado) {
            foreach ($mapas as $i => $mapa) {
                if ($mapa['hoja'] === $caso['otra_hoja']) {
                    $mapas[$i]['periodo_id'] = (int) $cerrado->id;
                }
            }

            return $mapas;
        }, true);

        $r = $this->ensayo($caso['token'], $ruta)->assertStatus(200);

        $hojas = collect($r->json('hojas'))->keyBy('nombre');

        $this->assertGreaterThan(0, (int) $r->json('totales.entran'),
            'Si no entra ninguna nota, este no es el caso parcial y el test no comprueba lo que dice.');

        $this->assertStringContainsString('Las demás hojas del libro entran igual.',
            (string) $hojas[$caso['otra_hoja']]['motivo_fuera'],
            'Con el resto del libro entrando, esto es cierto y es lo que evita que el docente crea que '
            .'perdió el libro entero. Quitarlo del todo sería cambiar una frase falsa por un silencio.');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // F9 — la columna de reserva
    // ─────────────────────────────────────────────────────────────────────────

    #[Test]
    public function f9_escribir_en_una_columna_de_reserva_propone_crear_el_indicador(): void
    {
        $caso = $this->unaPlanilla();

        $reservada = array_key_first($caso['mapa']['reservadas'] ?? []);

        if ($reservada === null) {
            $this->markTestSkipped('Esta hoja no tiene columnas de reserva: todas sus unidades están llenas.');
        }

        $ruta = $this->escribiendo($caso['ruta'], $caso['hoja'], [[$caso['fila'], (string) $reservada, 33]]);

        $r = $this->ensayo($caso['token'], $ruta)->assertStatus(200);

        $reserva = $r->json('familias.reserva');

        $this->assertCount(1, $reserva);
        $this->assertSame((string) $reservada, $reserva[0]['columna']);
        $this->assertSame(1, $reserva[0]['notas']);
        $this->assertFalse($reserva[0]['se_crea'], 'Sin decisión no se crea nada.');
        $this->assertIsBool($reserva[0]['pide_peso']);
        $this->assertNotNull($reserva[0]['unidad_id'],
            'Sin saber de qué unidad es la columna no se puede crear el indicador, y el caso que más '
            .'lo necesita —una asignatura sin ningún indicador— no tiene vecina a la que preguntar.');

        $antes = DB::selectOne('SELECT COUNT(*) AS n FROM subunidades WHERE deleted_at IS NULL')->n;

        $this->importar($caso['token'], $ruta)->assertStatus(200);

        $this->assertSame(
            $antes,
            DB::selectOne('SELECT COUNT(*) AS n FROM subunidades WHERE deleted_at IS NULL')->n,
            'Sin decisión no se crea ningún indicador.'
        );

        // Y con la decisión: se crea el indicador, con su nombre y su peso, y la nota
        // entra dentro.
        $r = $this->importar($caso['token'], $ruta, [
            'reserva' => [[
                'hoja' => $caso['hoja'], 'columna' => (string) $reservada,
                'decision' => 'crear', 'nombre' => 'Actividad del 21 de septiembre', 'peso' => 10,
            ]],
        ])->assertStatus(200);

        $creado = $r->json('indicadores_creados');

        $this->assertCount(1, $creado);
        $this->assertSame('Actividad del 21 de septiembre', $creado[0]['definicion']);
        $this->assertSame(10, $creado[0]['porcentaje']);
        $this->assertSame((int) $reserva[0]['unidad_id'], $creado[0]['unidad_id']);

        $this->assertSame(33, $this->notaDe($caso['alumno_id'], (int) $creado[0]['subunidad_id']));
    }

    #[Test]
    public function f9_volver_a_subir_el_mismo_archivo_no_crea_el_indicador_dos_veces(): void
    {
        $caso = $this->unaPlanilla();

        $reservada = array_key_first($caso['mapa']['reservadas'] ?? []);

        if ($reservada === null) {
            $this->markTestSkipped('Esta hoja no tiene columnas de reserva.');
        }

        $ruta = $this->escribiendo($caso['ruta'], $caso['hoja'], [[$caso['fila'], (string) $reservada, 33]]);

        $respuestas = ['reserva' => [[
            'hoja' => $caso['hoja'], 'columna' => (string) $reservada,
            'decision' => 'crear', 'nombre' => 'Actividad repetida', 'peso' => 10,
        ]]];

        $this->importar($caso['token'], $ruta, $respuestas)->assertStatus(200);
        $this->importar($caso['token'], $ruta, $respuestas)->assertStatus(200);

        // Crear el indicador pasa **una vez por columna, fuera de la transacción de
        // la fila**, así que el punto de control no lo cubre: la idempotencia tiene
        // que ser por nombre. Dos columnas iguales en la misma unidad serían, en modo
        // porcentaje, una unidad que suma de más.
        $this->assertSame(
            1,
            (int) DB::selectOne(
                'SELECT COUNT(*) AS n FROM subunidades WHERE definicion = ? AND deleted_at IS NULL',
                ['Actividad repetida']
            )->n
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Los peldaños — la firma rota y el libro que no es de aquí
    // ─────────────────────────────────────────────────────────────────────────

    #[Test]
    public function la_firma_rota_degrada_al_peldano_2_y_hay_que_confirmar(): void
    {
        $caso = $this->unaPlanilla();

        $nuevo = $this->otroValorPara($caso, $caso['fila'], $caso['columna']);

        $ruta = $this->escribiendo($caso['ruta'], $caso['hoja'], [[$caso['fila'], $caso['columna'], $nuevo]]);

        // Se toca el espejo **sin volver a firmar**: es exactamente lo que pasa
        // cuando alguien abre el `.zip`, edita la hoja oculta y lo cierra.
        $ruta = $this->reescribirMetadatos($ruta, null, function (array $mapas) {
            $hoja = array_key_first($mapas[0]['espejo']);
            $columna = array_key_first($mapas[0]['espejo'][$hoja]);
            $mapas[0]['espejo'][$hoja][$columna] = 999;

            return $mapas;
        }, false);

        $r = $this->ensayo($caso['token'], $ruta)->assertStatus(200);

        $this->assertSame(2, $r->json('peldano'));
        $this->assertFalse($r->json('firma_valida'));
        $this->assertFalse($r->json('puede_importarse'));
        $this->assertSame('firma_rota', $r->json('bloqueos.0.tipo'));

        // **Y no produce choques**: un choque se define contra el espejo, y con la
        // firma rota el espejo no vale. Lo que hay en su lugar es una lista entera
        // que confirmar.
        $this->assertSame([], $r->json('familias.choques'));

        $this->importar($caso['token'], $ruta)->assertStatus(422);

        $r = $this->ensayo($caso['token'], $ruta, ['firma' => ['decision' => 'confirmo']])->assertStatus(200);

        $this->assertTrue($r->json('puede_importarse'));
        $this->assertSame([], $r->json('bloqueos'));
        $this->assertSame('firma_rota', $r->json('bloqueos_resueltos.0.tipo'),
            'Resuelto, no desaparecido: sin esta lista no se puede enseñar «esto era un problema y lo resolviste».');

        $this->importar($caso['token'], $ruta, ['firma' => ['decision' => 'confirmo']])->assertStatus(200);

        $this->assertSame($nuevo, $this->notaDe($caso['alumno_id'], $caso['subunidad_id']));
    }

    #[Test]
    public function un_libro_que_no_es_de_este_colegio_se_bloquea_entero(): void
    {
        $caso = $this->unaPlanilla();

        $ruta = $this->reescribirMetadatos($caso['ruta'], function (array $cabecera) {
            $cabecera['periodo_id'] = 999999999;

            return $cabecera;
        }, null, true);

        $r = $this->ensayo($caso['token'], $ruta)->assertStatus(200);

        $this->assertFalse($r->json('libro.colegio_ok'));
        $this->assertFalse($r->json('puede_importarse'));
        $this->assertSame('libro_de_otro_sitio', $r->json('bloqueos.0.tipo'));

        // **F1 tira el libro entero**, y es lo que la separa de la F3: no hay ni una
        // hoja que se pueda escribir, así que no se estudia ninguna.
        $this->assertSame([], $r->json('hojas'));
    }

    #[Test]
    public function un_archivo_que_no_es_una_planilla_para_en_el_peldano_5_con_su_salida(): void
    {
        $caso = $this->unaPlanilla();

        $basura = tempnam(sys_get_temp_dir(), 'no-es-planilla-').'.xlsx';
        $this->temporales[] = $basura;
        file_put_contents($basura, 'esto no es un xlsx');

        $r = $this->ensayo($caso['token'], $basura)->assertStatus(200);

        $this->assertSame(5, $r->json('peldano'));
        $this->assertSame('peldano_5', $r->json('bloqueos.0.tipo'));
        $this->assertStringContainsString('Descargue el libro otra vez', $r->json('bloqueos.0.motivo'),
            'El peldaño 5 es el que evita el callejón sin salida: un error que no ofrece salida '
            .'obliga a llamar por teléfono.');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Sin la hoja `_myvc`: el clasificador de los peldaños 3, 4 y 5
    //
    // `LaPlanillaQueSeSube::sinHojaOculta()` es de donde cuelgan los tres caminos
    // que esta fase no trabaja, y **el 5 ya está desplegado**: es la frase y la
    // salida que ve un docente que sube un archivo que no se reconoce. Los 3 y 4 se
    // van a construir encima (§10 del plan), así que esto es una RED, no un
    // rediseño: ata lo que el método hace HOY para que quien lo cambie sepa qué
    // está cambiando.
    //
    // El caso de arriba —«un archivo que no es una planilla»— pasa por aquí sin
    // decirlo: un `.txt` lo lee PhpSpreadsheet como CSV y llega con una pestaña
    // llamada `Worksheet`, así que lo que escribe ese motivo es esta rama. Lo que
    // no había era ni un caso de los peldaños 3 y 4.
    //
    // Los libros se fabrican como todos los de aquí: **se baja uno de verdad con la
    // fase 1** y se le quita la hoja `_myvc`, que es lo único que separa los
    // peldaños 1 y 2 de estos tres.
    // ─────────────────────────────────────────────────────────────────────────

    #[Test]
    public function sin_la_hoja_oculta_pero_con_la_columna_id_es_el_peldano_3(): void
    {
        $caso = $this->unaPlanilla();

        // Lo que deja un docente que borró la hoja que no entendía: la rejilla
        // entera, con su enlace a la portada y su columna ID.
        $ruta = $this->libroSinLaHojaOculta($caso['ruta'], portada: true, enlace: true, id: 'ID');

        $r = $this->ensayo($caso['token'], $ruta)->assertStatus(200);

        $this->assertSame(3, $r->json('peldano'));
        $this->assertSame('peldano_3', $r->json('bloqueos.0.tipo'));

        // Y el ID manda **sobre las otras dos marcas**: quitadas la portada y el
        // enlace, el libro sigue siendo del 3 y no del 4. El orden del método es
        // `conId` primero y `reconocible` después, y es lo que decide cuál de los
        // dos arreglos de la fase 3 se le ofrece a quien sube.
        $solo = $this->libroSinLaHojaOculta($caso['ruta'], portada: false, enlace: false, id: 'ID');

        $this->assertSame(3, $this->ensayo($caso['token'], $solo)->assertStatus(200)->json('peldano'));
    }

    #[Test]
    public function sin_id_pero_con_el_enlace_a_la_portada_es_el_peldano_4(): void
    {
        $caso = $this->unaPlanilla();

        // Sin la pestaña de portada y sin rótulo en C2: lo único que queda es el
        // «← Volver a la portada» de `A1`, y el método sólo le mira la flecha.
        $ruta = $this->libroSinLaHojaOculta($caso['ruta'], portada: false, enlace: true, id: null);

        $r = $this->ensayo($caso['token'], $ruta)->assertStatus(200);

        $this->assertSame(4, $r->json('peldano'));
        $this->assertSame('peldano_4', $r->json('bloqueos.0.tipo'));
    }

    #[Test]
    public function la_pestana_de_portada_sola_ya_saca_el_libro_del_peldano_5(): void
    {
        $caso = $this->unaPlanilla();

        // Ni enlace ni columna ID en ninguna pestaña. Lo único que queda del libro
        // es **el nombre de una pestaña**.
        $ruta = $this->libroSinLaHojaOculta($caso['ruta'], portada: true, enlace: false, id: null);

        $r = $this->ensayo($caso['token'], $ruta)->assertStatus(200);

        // **Y con eso basta**, que es de las cosas que sorprenden: la portada
        // enciende `reconocible` por su NOMBRE, sin mirarle una sola celda. O sea
        // que cualquier libro con una pestaña llamada «Bienvenida» —de este colegio
        // o del club de lectura— deja de ser peldaño 5 y **pierde la salida
        // escrita**: en vez de «descargue el libro otra vez» le contesta «casar a
        // los alumnos por nombre es la fase 3 y todavía no está».
        $this->assertSame(4, $r->json('peldano'));
        $this->assertSame('peldano_4', $r->json('bloqueos.0.tipo'));
    }

    #[Test]
    public function sin_mapa_sin_enlace_y_sin_id_es_el_peldano_5_con_su_frase_entera(): void
    {
        $caso = $this->unaPlanilla();

        $ruta = $this->libroSinLaHojaOculta($caso['ruta'], portada: false, enlace: false, id: null);

        $r = $this->ensayo($caso['token'], $ruta)->assertStatus(200);

        $this->assertSame(5, $r->json('peldano'));
        $this->assertSame('peldano_5', $r->json('bloqueos.0.tipo'));

        // **La frase entera y no un trozo, porque es la que lee el docente.** Las
        // tres cosas que nombra son las tres que el método acaba de mirar, y la
        // salida del final es lo único que separa este peldaño de un callejón sin
        // salida. Cambiarla es cambiar una pantalla desplegada.
        $this->assertSame(
            'Este archivo no parece una planilla de MyVc: no trae la hoja interna «'
            .LibroDeNotas::METADATOS.'», ninguna pestaña tiene el enlace a la portada y ninguna '
            .'tiene la columna ID. Descargue el libro otra vez desde Académico → Trabajar sin internet '
            .'y escriba las notas sobre ése.',
            $r->json('bloqueos.0.motivo')
        );
    }

    #[Test]
    public function once_hojas_sin_id_y_una_con_id_mandan_el_libro_entero_al_peldano_3(): void
    {
        $caso = $this->unaPlanilla();

        // Once pestañas donde no hay forma de atar una fila a un alumno, y una que
        // sí. **`tiene_id` es de una hoja; `peldano` es del libro**, así que gana la
        // única que la tiene y las once viajan de polizón.
        $ruta = $this->libroSinLaHojaOculta($caso['ruta'], portada: false, enlace: false, id: null,
            tocar: static function (Spreadsheet $libro): void {
                for ($i = count($libro->getSheetNames()); $i < 11; $i++) {
                    $libro->createSheet()->setTitle('Sin ID '.$i);
                }

                $conId = $libro->createSheet();
                $conId->setTitle('La única con ID');
                $conId->setCellValueExplicit('C'.LaPlanillaQueSeSube::FILA_CABECERA, 'ID', DataType::TYPE_STRING);
            });

        // La cuenta se comprueba, que es justo de lo que habla el caso: once y una.
        $lector = LaPlanillaQueSeSube::abrir($ruta);

        try {
            $this->assertCount(12, $lector->pestanas);
            $this->assertSame(3, $lector->peldano);
        } finally {
            $lector->cerrar();
        }

        // Y el libro entero se lo lleva puesto: el docente ve «conserva la columna
        // ID» de un libro en el que once de doce hojas no la tienen. **No es un
        // fallo que se arregle aquí**: es el diseño de hoy, y la §10.5 del plan dice
        // que el contrato de la fase 3 tendrá que decir `tiene_id` por hoja.
        $this->assertSame(3, $this->ensayo($caso['token'], $ruta)->assertStatus(200)->json('peldano'));
    }

    #[Test]
    public function el_rotulo_id_se_reconoce_en_minusculas_y_con_espacios(): void
    {
        $caso = $this->unaPlanilla();

        // Sin portada y sin enlace **a propósito**: así, si el `strcasecmp` o el
        // `trim` dejaran de valer, el libro no se cae al peldaño 4 —que se parece—
        // sino al 5, y el rojo dice lo que pasó.
        foreach ([' ID ', 'id', 'Id', "\tiD\n"] as $rotulo) {
            $ruta = $this->libroSinLaHojaOculta($caso['ruta'], portada: false, enlace: false, id: $rotulo);

            $r = $this->ensayo($caso['token'], $ruta)->assertStatus(200);

            $this->assertSame(3, $r->json('peldano'),
                'Con '.json_encode($rotulo).' en la celda del rótulo sigue siendo el peldaño 3.');
        }
    }

    #[Test]
    public function la_planilla_de_otro_docente_da_403_al_importar(): void
    {
        $caso = $this->unaPlanilla();
        $otro = $this->otroDocenteConCuenta($caso['profesor_id']);

        if ($otro === null) {
            $this->markTestSkipped('El seed no tiene un segundo docente con cuenta.');
        }

        // **Un docente SIN el permiso de la D4 sigue recibiendo 403**, y la fase 5 no
        // lo cambió: lo que abrió fue la puerta de coordinación, no la de al lado.
        // `puedeSubirLaPlanillaDeOtro` es superusuario o `can_edit_plantilla_notas`, y
        // un docente del seed no es ninguna de las dos cosas.
        $this->importar($this->tokenDe($otro->username), $caso['ruta'])->assertStatus(403);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // La D4 — coordinación sube por otro (fase 5)
    //
    // Las dos preguntas son distintas y por eso se comprueban por separado: el
    // permiso dice si esta persona **puede**, y la confirmación si **quiso** sobre
    // este libro. Un test que sólo mirara la primera daría por buena una versión en
    // la que pulsar «Siguiente» escribe las notas de un grupo que nadie ha mirado.
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * **Sin el permiso, el ensayo sigue siendo un callejón sin salida con su
     * motivo.**
     *
     * Se mira en el ensayo y no en la subida porque es donde la pantalla lee: el
     * 403 de `importar` ya lo fija el test de arriba, y lo que aquí importa es que
     * el docente ajeno vea **por qué** en vez de descubrirlo al pulsar.
     */
    #[Test]
    public function d4_un_docente_ajeno_sin_permiso_sigue_bloqueado_con_libro_de_otro_docente(): void
    {
        $caso = $this->unaPlanilla();
        $otro = $this->otroDocenteConCuenta($caso['profesor_id']);

        if ($otro === null) {
            $this->markTestSkipped('El seed no tiene un segundo docente con cuenta.');
        }

        $r = $this->ensayo($this->tokenDe($otro->username), $caso['ruta'])->assertStatus(200);

        $this->assertSame(['libro_de_otro_docente'], array_column($r->json('bloqueos'), 'tipo'));
        $this->assertFalse($r->json('libro.puede_por_otro'),
            'Sin el permiso, la pantalla no puede ofrecer la confirmación: no hay nada que confirmar.');
        $this->assertStringContainsString('coordinación académica', $r->json('bloqueos.0.motivo'));
    }

    /**
     * **Con el permiso, el bloqueo cambia de cara**: ya no es una pared, es una
     * confirmación.
     */
    #[Test]
    public function d4_con_el_permiso_el_ensayo_ofrece_subir_por_otro(): void
    {
        $caso = $this->unaPlanilla();
        $coordinacion = $this->unCoordinador();

        $r = $this->ensayo($this->tokenDe($coordinacion->username), $caso['ruta'])->assertStatus(200);

        $this->assertSame(['subir_por_otro'], array_column($r->json('bloqueos'), 'tipo'));
        $this->assertTrue($r->json('libro.puede_por_otro'));
        $this->assertFalse($r->json('libro.por_otro_confirmado'),
            'Tener el permiso no es haber confirmado: son las dos preguntas de la D4.');
    }

    /**
     * **Sin confirmar no entra una sola nota**, y es la mitad de la D4 que se cae
     * sola si nadie la fija.
     *
     * El 422 lo pone el paso de los bloqueos, que va **antes** de instanciar la
     * escritura, así que la comprobación de verdad no es el código de estado: es la
     * huella de las seis tablas antes y después. Un día que alguien mueva el orden
     * de ese `if`, el 422 podría seguir saliendo con media planilla dentro.
     */
    #[Test]
    public function d4_sin_confirmar_la_importacion_da_422_y_no_escribe_ni_una_nota(): void
    {
        $caso = $this->unaPlanilla();
        $coordinacion = $this->unCoordinador();

        $nuevo = $this->otroValorPara($caso, $caso['fila'], $caso['columna']);
        $ruta = $this->escribiendo($caso['ruta'], $caso['hoja'], [[$caso['fila'], $caso['columna'], $nuevo]]);

        $antes = $this->huellaDeLaBase();

        $r = $this->importar($this->tokenDe($coordinacion->username), $ruta)->assertStatus(422);

        $this->assertSame(['subir_por_otro'], array_column($r->json('bloqueos'), 'tipo'));

        $this->assertSame($antes, $this->huellaDeLaBase(),
            'Un bloqueo sin resolver no puede escribir NADA: media planilla dentro y un error '
            .'delante es peor que no haber subido.');
    }

    /**
     * **Con la confirmación entra, y el rastro lleva a las dos personas.**
     *
     * Las tres cosas que se comprueban aquí son las tres que el plan pide juntas, y
     * separarlas dejaría pasar la versión mala de cada una:
     *
     * 1. La nota entra (si no, el permiso no sirve de nada).
     * 2. El bloqueo aparece en `bloqueos_resueltos` y **no desaparece**: el acta y
     *    la pantalla tienen que poder decir que esto se subió por otro, y un bloqueo
     *    que se esfuma al resolverse deja la subida indistinguible de una normal.
     * 3. La línea de auditoría nombra **a los dos**. Sin esto, el rastro dice que
     *    coordinación editó la nota 88.412 y el docente cuyo libro entró no aparece
     *    en ningún sitio — que es justo el dato que se reclama.
     */
    #[Test]
    public function d4_con_la_confirmacion_escribe_y_queda_auditado_con_las_dos_personas(): void
    {
        $caso = $this->unaPlanilla();
        $coordinacion = $this->unCoordinador();
        $token = $this->tokenDe($coordinacion->username);

        $nuevo = $this->otroValorPara($caso, $caso['fila'], $caso['columna']);
        $ruta = $this->escribiendo($caso['ruta'], $caso['hoja'], [[$caso['fila'], $caso['columna'], $nuevo]]);

        $ensayo = $this->ensayo($token, $ruta, self::POR_OTRO)->assertStatus(200);

        $this->assertSame([], $ensayo->json('bloqueos'));
        $this->assertSame(['subir_por_otro'], array_column($ensayo->json('bloqueos_resueltos'), 'tipo'));
        $this->assertTrue($ensayo->json('libro.por_otro_confirmado'));

        $this->importar($token, $ruta, self::POR_OTRO)->assertStatus(200);

        $this->assertSame($nuevo, $this->notaDe($caso['alumno_id'], $caso['subunidad_id']));

        $nota = DB::selectOne(
            'SELECT id FROM notas WHERE alumno_id = ? AND subunidad_id = ? AND deleted_at IS NULL',
            [$caso['alumno_id'], $caso['subunidad_id']]
        );

        $linea = DB::selectOne(
            'SELECT actor_user_id, resumen FROM auditoria
              WHERE entidad = "nota" AND entidad_id = ? ORDER BY id DESC LIMIT 1',
            [$nota->id]
        );

        $this->assertNotNull($linea, 'La escritura por otro tiene que dejar rastro como cualquier otra.');
        $this->assertSame((int) $coordinacion->id, (int) $linea->actor_user_id,
            'Quien sube es quien actúa: el rastro no puede fingir que lo hizo el docente.');
        $this->assertStringContainsString('por cuenta de', (string) $linea->resumen,
            'Un rastro con una sola persona no contesta la pregunta que esta fase existe para contestar.');
        $this->assertStringContainsString(
            $this->nombreDelProfesor($caso['profesor_id']), (string) $linea->resumen
        );
    }

    /**
     * **Secretaría baja el libro y NO lo sube**, que es toda la diferencia entre
     * los dos permisos de la D4.
     *
     * `puedeDescargarLaPlanillaDeOtro` se permite ser ancha —incluye
     * `esAdministrativo`, o sea el rol `Secretario`— con un argumento escrito en
     * `Autoriza`: lo que sale por las rutas de lectura es la planilla que esa
     * persona **ya puede ver por la web**, sólo que en un `.xlsx`.
     * `puedeSubirLaPlanillaDeOtro` no tiene esa rama, y por eso el mismo token se
     * lleva un 200 y un 403 en el mismo test: subir es **escribir las notas de un
     * grupo entero a nombre de otra persona**, y eso es de coordinación académica.
     *
     * **Las dos llamadas van juntas a propósito.** El día que alguien unifique los
     * dos métodos «porque hacen lo mismo», dos tests separados seguirían verdes
     * cada uno por su lado. Lo que no puede pasar es que a la misma persona le
     * contesten lo mismo las dos rutas, y eso sólo se ve mirándolas a la vez.
     *
     * La rama de al lado —`can_edit_plantilla_notas`, que SÍ sube— ya la fijan
     * {@see d4_con_el_permiso_el_ensayo_ofrece_subir_por_otro} y
     * {@see d4_con_la_confirmacion_escribe_y_queda_auditado_con_las_dos_personas},
     * los dos con `unCoordinador()`: personal llano con ese permiso y nada más.
     */
    #[Test]
    public function d4_un_secretario_baja_el_libro_y_no_puede_subirlo(): void
    {
        $caso = $this->unaPlanilla();

        $secretaria = $this->usuarioLlanoDelPersonal();

        // El rol se crea aquí, igual que en `FichaDelPersonalTest`: el seed hace
        // `TRUNCATE TABLE roles` y esa fila no existe en la base de tests. **Y sin
        // colgarle ningún permiso**: con `can_edit_plantilla_notas` dentro, el 403
        // de abajo no saldría y esto estaría midiendo la rama de coordinación.
        $rol = DB::table('roles')->where('name', 'Secretario')->value('id')
            ?? DB::table('roles')->insertGetId([
                'name' => 'Secretario',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        DB::table('role_user')->insert(['user_id' => $secretaria->id, 'role_id' => $rol]);

        $this->assertNull(DB::selectOne(
            'SELECT 1 AS si FROM role_user ru
               INNER JOIN permission_role pr ON pr.role_id = ru.role_id
               INNER JOIN permissions pm ON pm.id = pr.permission_id
              WHERE ru.user_id = ? AND pm.name = ?',
            [$secretaria->id, Autoriza::PERMISO_PLANTILLA_NOTAS]
        ), 'La secretaria tiene `can_edit_plantilla_notas`: este test mide la otra rama.');

        $token = $this->tokenDe($secretaria->username);

        // Baja: 200, y con la planilla de OTRO docente delante.
        $r = $this->withToken($token)
            ->getJson('/api/planilla-offline/periodos?profesor_id='.$caso['profesor_id'])
            ->assertStatus(200);

        $this->assertSame($caso['profesor_id'], $r->json('profesor.id'));
        $this->assertSame(true, $r->json('puede_bajar_la_de_otro'));

        // Y sube: 403 con el mismo token y el libro que acaba de poder ver.
        $this->importar($token, $caso['ruta'])->assertStatus(403);
    }

    /**
     * **Un periodo cerrado no se abre con el permiso de la D4 ni con la
     * confirmación.**
     *
     * Es la primera de las tres cosas que `Autoriza::puedeSubirLaPlanillaDeOtro` no
     * relaja, y la que más fácil sería perder: subir *por* un docente y subir *a* un
     * periodo cerrado se parecen en la pantalla y son dos decisiones distintas — la
     * segunda es del colegio y tiene la suya.
     */
    #[Test]
    public function d4_un_periodo_cerrado_no_se_escribe_ni_con_permiso_ni_con_confirmacion(): void
    {
        $caso = $this->unaPlanilla();
        $coordinacion = $this->unCoordinador();

        $nuevo = $this->otroValorPara($caso, $caso['fila'], $caso['columna']);
        $ruta = $this->escribiendo($caso['ruta'], $caso['hoja'], [[$caso['fila'], $caso['columna'], $nuevo]]);

        $antes = $this->notaDe($caso['alumno_id'], $caso['subunidad_id']);

        // Se cierra DESPUÉS de bajar el libro, que es el caso de verdad: el docente
        // se llevó la planilla con el periodo abierto y el colegio lo cerró mientras
        // él pasaba las notas.
        DB::update('UPDATE periodos SET profes_pueden_editar_notas = 0 WHERE id = ?', [$caso['periodo_id']]);

        $r = $this->importar($this->tokenDe($coordinacion->username), $ruta, self::POR_OTRO)
            ->assertStatus(422);

        $this->assertStringContainsString('Ninguna hoja', (string) $r->json('msg'));

        $this->assertSame($antes, $this->notaDe($caso['alumno_id'], $caso['subunidad_id']),
            'El permiso de la D4 no abre un periodo cerrado, y la confirmación tampoco.');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // El acta (fase 5)
    //
    // Un `.xlsx` y no un PDF: en este backend no hay librería de PDF —los informes
    // los imprime el front desde el navegador— y el acta se pinta con el mismo
    // PhpSpreadsheet que genera la planilla. El porqué entero está en la cabecera
    // de `App\Services\ActaDeLaImportacion`.
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * **Las tres puertas y la que está cerrada.**
     *
     * La cuarta —un docente ajeno— es la que hace útil a las otras tres: el acta
     * lleva dentro el recuento de las notas de un grupo, o sea lo mismo que la
     * planilla, y si se pudiera pedir por número sería un listado del colegio a
     * razón de una petición por `id`.
     */
    #[Test]
    public function el_acta_la_piden_quien_subio_el_dueno_del_libro_y_coordinacion_y_no_un_docente_ajeno(): void
    {
        $caso = $this->unaPlanilla();
        $coordinacion = $this->unCoordinador();
        $token = $this->tokenDe($coordinacion->username);

        $nuevo = $this->otroValorPara($caso, $caso['fila'], $caso['columna']);
        $ruta = $this->escribiendo($caso['ruta'], $caso['hoja'], [[$caso['fila'], $caso['columna'], $nuevo]]);

        $id = $this->importar($token, $ruta, self::POR_OTRO)->assertStatus(200)->json('importacion_id');

        $this->assertNotNull($id, 'Sin `importacion_id` en la respuesta no hay forma de pedir el acta.');

        // 1 · quien la subió
        $this->acta($token, $id)->assertStatus(200);

        // 2 · el docente dueño del libro, que es la mitad que hace útil a la fase:
        //     sin esto, coordinación sube por él y él no tiene con qué comprobarlo.
        $this->acta($caso['token'], $id)->assertStatus(200);

        // 3 · un docente ajeno, que no.
        $otro = $this->otroDocenteConCuenta($caso['profesor_id']);

        if ($otro === null) {
            $this->markTestSkipped('El seed no tiene un segundo docente con cuenta.');
        }

        $r = $this->acta($this->tokenDe($otro->username), $id)->assertStatus(403);

        $this->assertStringContainsString('no es suya', (string) $r->json('message'),
            'Un 403 sin motivo manda a mirar los roles, que es el sitio equivocado.');
    }

    /**
     * **El acta cuenta lo HECHO y no lo prometido**, y el caso que lo demuestra es
     * aquel en que las dos cifras difieren.
     *
     * Se fabrica con el presupuesto a cero: el ensayo promete las cuatro filas del
     * libro y la petición escribe **una**, porque el bucle para en cuanto ha
     * estudiado la primera. Un acta que leyera el plan diría cuatro, y ése es
     * exactamente el error que la hace inútil — *«entraron 312 notas» de una
     * importación en la que entraron once*.
     */
    #[Test]
    public function el_acta_cuenta_lo_hecho_y_no_lo_prometido(): void
    {
        $caso = $this->unaPlanilla(4);

        $celdas = [];

        foreach ($caso['filas'] as $fila) {
            $celdas[] = [$fila, $caso['columna'], $this->otroValorPara($caso, $fila, $caso['columna'])];
        }

        $ruta = $this->escribiendo($caso['ruta'], $caso['hoja'], $celdas);

        // Lo PROMETIDO, medido con el ensayo y con el presupuesto entero.
        $prometido = (int) $this->ensayo($caso['token'], $ruta)->assertStatus(200)->json('totales.entran');

        $this->assertGreaterThan(1, $prometido,
            'Si el plan promete una sola nota, este test no distingue lo hecho de lo prometido.');

        config(['importacion.segundos_por_peticion' => 0.0]);

        $r = $this->importar($caso['token'], $ruta)->assertStatus(200);

        config(['importacion.segundos_por_peticion' => 20.0]);

        $this->assertFalse($r->json('terminado'), 'El corte es el que fabrica la diferencia.');

        $acta = $this->leerElActa($caso['token'], (int) $r->json('importacion_id'));

        $this->assertSame((string) $r->json('hechos.notas_escritas'), $this->delActa($acta, 'Notas escritas'),
            'El acta tiene que decir lo mismo que la respuesta de la subida: las dos salen del '
            .'recorrido que escribió.');

        $this->assertNotSame((string) $prometido, $this->delActa($acta, 'Notas escritas'),
            'Ésta es la razón de existir del acta: si contara el plan, diría cuatro donde entró una.');
    }

    /**
     * **Una importación cortada y continuada da un acta con el total**, no con la
     * última tanda.
     *
     * Es la otra mitad de lo mismo, y la que se rompe si `hechos` pisa en vez de
     * acumular: la última pasada de una importación de cuatro filas cortada en la
     * primera escribe **tres**, y un acta que guardara sólo la última diría que
     * entraron tres de las cuatro que hay en la base.
     */
    #[Test]
    public function el_acta_de_una_importacion_reanudada_trae_el_total_acumulado(): void
    {
        $caso = $this->unaPlanilla(4);

        $celdas = [];

        foreach ($caso['filas'] as $fila) {
            $celdas[] = [$fila, $caso['columna'], $this->otroValorPara($caso, $fila, $caso['columna'])];
        }

        $ruta = $this->escribiendo($caso['ruta'], $caso['hoja'], $celdas);

        config(['importacion.segundos_por_peticion' => 0.0]);
        $primera = $this->importar($caso['token'], $ruta)->assertStatus(200);
        config(['importacion.segundos_por_peticion' => 20.0]);

        $segunda = $this->importar($caso['token'], $ruta)->assertStatus(200);

        $this->assertTrue($segunda->json('reanudada'));
        $this->assertTrue($segunda->json('terminado'));

        $escritas = (int) $primera->json('hechos.notas_escritas') + (int) $segunda->json('hechos.notas_escritas');

        $this->assertGreaterThan((int) $segunda->json('hechos.notas_escritas'), $escritas,
            'Sin dos tandas con escrituras en las dos, este test no distingue acumular de pisar.');

        $acta = $this->leerElActa($caso['token'], (int) $segunda->json('importacion_id'));

        $this->assertSame((string) $escritas, $this->delActa($acta, 'Notas escritas'),
            'El acta de una importación reanudada tiene que traer el total, no la última tanda.');

        $this->assertStringContainsString('Sí', (string) $this->delActa($acta, 'Reanudada'),
            'Y tiene que decir que se cortó: las notas llevan la hora de la segunda pasada.');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // La reanudación
    // ─────────────────────────────────────────────────────────────────────────

    #[Test]
    public function cortar_a_media_importacion_y_volver_a_subir_no_duplica_ni_salta_filas(): void
    {
        $caso = $this->unaPlanilla(4);

        $escritos = [];
        $celdas = [];

        foreach ($caso['filas'] as $i => $fila) {
            $valor = $this->otroValorPara($caso, $fila, $caso['columna']);
            $celdas[] = [$fila, $caso['columna'], $valor];
            $escritos[$caso['alumnos'][$i]] = $valor;
        }

        $ruta = $this->escribiendo($caso['ruta'], $caso['hoja'], $celdas);

        // **El corte se fabrica con el presupuesto a cero.** El bucle para en cuanto
        // haya estudiado una fila —nunca antes, que sería un bucle infinito—, así que
        // la primera petición escribe exactamente una fila y deja la importación en
        // `en_proceso` con su avance anotado.
        config(['importacion.segundos_por_peticion' => 0.0]);

        $primera = $this->importar($caso['token'], $ruta)->assertStatus(200);

        $this->assertFalse($primera->json('terminado'));
        $this->assertSame(1, $primera->json('hechos.filas'));

        config(['importacion.segundos_por_peticion' => 20.0]);

        $segunda = $this->importar($caso['token'], $ruta)->assertStatus(200);

        $this->assertTrue($segunda->json('reanudada'),
            'Volver a subir el MISMO archivo continúa: la huella es del contenido, no del nombre.');
        $this->assertTrue($segunda->json('terminado'));
        $this->assertSame(1, $segunda->json('filas_ya_estaban_hechas'),
            'La fila de la tanda anterior no se vuelve a estudiar ni a escribir.');

        // Ni se saltó ninguna…
        foreach ($escritos as $alumnoId => $valor) {
            $this->assertSame($valor, $this->notaDe($alumnoId, $caso['subunidad_id']),
                'Reanudar no puede saltarse la fila por la que iba.');
        }

        // …ni se repitió ninguna. Una fila reprocesada dejaría **dos** líneas de
        // bitácora para la misma nota, y es la única forma de verlo desde fuera.
        $notaId = DB::selectOne(
            'SELECT id FROM notas WHERE alumno_id = ? AND subunidad_id = ? AND deleted_at IS NULL',
            [$caso['alumno_id'], $caso['subunidad_id']]
        );

        $lineas = DB::selectOne(
            'SELECT COUNT(*) AS n FROM bitacoras WHERE affected_element_type = "Nota" AND affected_element_id = ?',
            [$notaId->id]
        );

        $this->assertSame(1, (int) $lineas->n,
            'Una fila está aplicada si y sólo si el punto de control la da por hecha: dos líneas '
            .'significarían que se reprocesó.');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // La ida y la vuelta entera
    // ─────────────────────────────────────────────────────────────────────────

    #[Test]
    public function la_ida_y_la_vuelta_el_libro_que_genera_la_fase_1_lo_lee_la_fase_2(): void
    {
        $caso = $this->unaPlanilla(3);

        // Un docente de verdad: llena varias casillas de varios alumnos, borra una
        // con el guion y deja otra en blanco.
        $celdas = [];
        $esperado = [];

        foreach ($caso['filas'] as $i => $fila) {
            $valor = $this->otroValorPara($caso, $fila, $caso['columna']);
            $celdas[] = [$fila, $caso['columna'], $valor];
            $esperado[$caso['alumnos'][$i]] = $valor;
        }

        $otra = $this->otraColumnaDe($caso);

        if ($otra !== null) {
            $celdas[] = [$caso['fila'], $otra['columna'], '-'];
        }

        $ruta = $this->escribiendo($caso['ruta'], $caso['hoja'], $celdas);

        $ensayo = $this->ensayo($caso['token'], $ruta)->assertStatus(200);

        $this->assertTrue($ensayo->json('puede_importarse'));
        $this->assertSame(1, $ensayo->json('peldano'), 'Un libro recién bajado tiene la firma buena.');
        $this->assertTrue($ensayo->json('libro.es_mio'));
        $this->assertSame(count($caso['filas']), $ensayo->json('totales.entran'));

        if ($otra !== null) {
            $this->assertSame(1, $ensayo->json('totales.se_borran'));
        }

        $r = $this->importar($caso['token'], $ruta)->assertStatus(200);

        // **Prometido contra hecho.** Es la tabla que el importador de alumnos ya
        // enseña, y la única forma de que «entraron 312 notas» se pueda comprobar sin
        // abrir la base.
        $this->assertSame($ensayo->json('totales.entran'), $r->json('hechos.notas_escritas'));
        $this->assertSame($ensayo->json('totales.se_borran'), $r->json('hechos.notas_borradas'));
        $this->assertSame(1, $r->json('hechos.definitivas_recalculadas'),
            'Una vez por (asignatura, periodo) y al final. Nunca por nota: recalcularPorNota son '
            .'~6 consultas y llamarlo 300 veces tumba la petición.');

        foreach ($esperado as $alumnoId => $valor) {
            $this->assertSame($valor, $this->notaDe($alumnoId, $caso['subunidad_id']));
        }

        if ($otra !== null) {
            $this->assertNull($this->notaDe($caso['alumno_id'], $otra['subunidad_id']));
        }

        // La definitiva se recalcula **una vez por (asignatura, periodo) y al final**,
        // nunca por nota y nunca a mano. Que exista la fila es lo que lo demuestra
        // desde fuera: `notas_finales` no la escribe nadie más en este camino.
        $this->assertNotNull(
            DB::selectOne(
                'SELECT id FROM notas_finales WHERE alumno_id = ? AND asignatura_id = ? AND periodo_id = ?',
                [$caso['alumno_id'], $caso['asignatura_id'], $caso['periodo_id']]
            ),
            'La definitiva tenía que quedar recalculada.'
        );
    }

    /**
     * **«Definitivas a recalcular» tiene que ser el número que el acta cuenta
     * después**, y no el de hojas que el ensayo alcanzó a leer.
     *
     * Es el número que el asistente enseña al lado de «notas que entran», y hasta el
     * 22 sep 2026 contaba **hojas reconocidas**: un libro de 26 asignaturas con una
     * sola hoja tocada prometía **26** delante de una importación que dejaba
     * `definitivas_recalculadas: 1` en la base. Se veía sin abrir la base — volver a
     * subir **el mismo archivo sin cambiar nada** decía «0 de 0 que cambió» y, al
     * lado, «26 definitivas a recalcular».
     *
     * Las cuatro mitades del contrato, y las cuatro hacen falta:
     *
     * 1. Un libro **sin tocar** promete **0**.
     * 2. Un libro con **una hoja tocada** promete **1**.
     * 3. Ese número es el mismo que `hechos.definitivas_recalculadas` de la respuesta
     *    **y que el `hechos.totales.definitivas_recalculadas` que queda en
     *    `importaciones`** — el que se lee el día que alguien reclama, meses después.
     * 4. Y el mismo archivo subido otra vez, ya aplicado, vuelve a prometer **0**.
     *
     * Las dos mitades del predicado están en {@see EnsayoDeLaPlanilla::estudiarHoja}
     * y en {@see EscrituraDeNotasImportadas::aplicarHoja}, y este test es lo único
     * que las obliga a seguir diciendo lo mismo: **son dos recorridos distintos del
     * mismo plan**, y nada más los sujeta el uno al otro.
     */
    #[Test]
    public function el_ensayo_promete_las_definitivas_que_la_importacion_recalcula(): void
    {
        $caso = $this->unaPlanilla();

        // 1 · El libro recién bajado, sin una sola casilla escrita encima.
        $sinTocar = $this->ensayo($caso['token'], $caso['ruta'])->assertStatus(200);

        $this->assertSame(0, $sinTocar->json('totales.cambiaron'),
            'Un libro recién bajado no cambia nada. Si esto no es 0, el caso no es el que se cree '
            .'y lo de abajo no mide lo que dice.');

        $this->assertSame(0, $sinTocar->json('totales.definitivas_a_recalcular'),
            'Sin una sola escritura no hay ninguna definitiva que recalcular. Contar hojas leídas '
            .'es lo que hacía que un libro sin tocar prometiera una definitiva por asignatura.');

        // 2 · Una hoja tocada, una casilla.
        $ruta = $this->escribiendo($caso['ruta'], $caso['hoja'], [
            [$caso['fila'], $caso['columna'], $this->otroValorPara($caso, $caso['fila'], $caso['columna'])],
        ]);

        $ensayo = $this->ensayo($caso['token'], $ruta)->assertStatus(200);

        $this->assertSame(1, $ensayo->json('totales.entran'));
        $this->assertSame(1, $ensayo->json('totales.definitivas_a_recalcular'),
            'Una hoja tocada, una definitiva — aunque el libro traiga las otras veinticinco '
            .'asignaturas del docente dentro.');

        // 3 · Prometido contra hecho, y contra lo que queda escrito.
        $r = $this->importar($caso['token'], $ruta)->assertStatus(200);

        $this->assertTrue($r->json('terminado'));

        $this->assertSame(
            $ensayo->json('totales.definitivas_a_recalcular'),
            $r->json('hechos.definitivas_recalculadas'),
            'El ensayo y la subida tienen que decir el mismo número: era el único de la pantalla '
            .'que no cuadraba con lo que pasaba después.'
        );

        $fila = DB::selectOne('SELECT hechos FROM importaciones WHERE id = ?',
            [(int) $r->json('importacion_id')]);

        $hechos = json_decode((string) ($fila->hechos ?? ''), true);

        $this->assertSame(
            $ensayo->json('totales.definitivas_a_recalcular'),
            (int) ($hechos['totales']['definitivas_recalculadas'] ?? -1),
            'El acta guardada es la que se lee meses después, y tiene que traer el mismo número '
            .'que el asistente prometió antes de que nadie pulsara.'
        );

        // 4 · El mismo archivo otra vez, ya aplicado. Es la prueba que se hizo a mano.
        $otraVez = $this->ensayo($caso['token'], $ruta)->assertStatus(200);

        $this->assertSame(0, $otraVez->json('totales.cambiaron'),
            'Lo que ya está escrito no vuelve a entrar.');

        $this->assertSame(0, $otraVez->json('totales.definitivas_a_recalcular'),
            '«0 notas que entran de 0 que cambiaron» no puede llevar 26 definitivas al lado: es '
            .'exactamente lo que la pantalla enseñaba.');
    }

    #[Test]
    public function se_siembra_la_fila_de_una_casilla_que_nadie_visito_nunca(): void
    {
        $caso = $this->unaPlanilla();

        // Una casilla que nadie visitó **no tiene fila en `notas`**: la siembra la
        // hace `putDetailed` al abrir la planilla en el navegador. Un docente que se
        // lleva el libro y califica un indicador que nunca abrió en la web no la
        // tiene, y `notas/lote` escribe **por `id`**: sin sembrar, esa nota no se
        // guarda y no da error.
        //
        // **La fila se borra ANTES de bajar el libro, y hay que hacerlo así.** El
        // espejo de `_myvc` guarda lo que había el día de la descarga: borrarla
        // después dejaría un espejo con nota y una base vacía, o sea un choque, y el
        // caso que se quiere fabricar es el contrario — una casilla que nunca se
        // calificó y que el docente estrena desde el Excel.
        DB::delete('DELETE FROM notas WHERE alumno_id = ? AND subunidad_id = ?',
            [$caso['alumno_id'], $caso['subunidad_id']]);

        $caso = $this->bajarLaDe($caso['docente']);

        $this->assertNull($this->notaDe($caso['alumno_id'], $caso['subunidad_id']));

        $ruta = $this->escribiendo($caso['ruta'], $caso['hoja'], [[$caso['fila'], $caso['columna'], 41]]);

        $r = $this->importar($caso['token'], $ruta)->assertStatus(200);

        $this->assertGreaterThan(0, $r->json('hechos.filas_sembradas'));
        $this->assertSame(41, $this->notaDe($caso['alumno_id'], $caso['subunidad_id']));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // F8 / D5 — las ausencias y las tardanzas (fase 4)
    //
    // La regla que gobierna los ocho tests de abajo, y que es lo único que hay que
    // recordar de esta familia: **subir es añadir y baja el listón; bajar es borrar
    // historia y no ocurre sin que alguien lo pida.**
    //
    // Y el orden de cada caso importa por lo que dice la §5 del doc 50: lo que
    // fabrica el estado de partida va **antes** de bajar el libro —queda en el
    // espejo— y lo que simula «alguien tocó la web» va **después**.
    // ─────────────────────────────────────────────────────────────────────────

    #[Test]
    public function f8_subir_un_conteo_crea_faltas_fechadas_hoy_y_marcadas_como_de_la_planilla(): void
    {
        $caso = $this->conFaltas(0, 'ausencia');

        $ruta = $this->escribiendo($caso['ruta'], $caso['hoja'],
            [[$caso['fila'], (string) $caso['mapa']['columna_aus'], 3]]);

        $r = $this->ensayo($caso['token'], $ruta)->assertStatus(200);

        $renglon = $this->conteoDe($r, $caso['alumno_id'], 'ausencias');

        $this->assertSame('sube', $renglon['direccion']);
        $this->assertSame(3, $renglon['cuantas']);
        $this->assertSame(0, $renglon['base']);
        $this->assertSame(3, $renglon['archivo']);
        $this->assertFalse($renglon['choque']);

        // **El defecto de subir es aplicar**, y el renglón lo dice para que la
        // pantalla lo pinte ya marcado en vez de deducirlo.
        $this->assertSame('aplicar', $renglon['por_defecto']);

        // Y `si_no_hago_nada` dice el precio: la fecha no es la del día que faltó.
        $this->assertStringContainsString('no la del día que faltó', $renglon['si_no_hago_nada']);

        $r = $this->importar($caso['token'], $ruta)->assertStatus(200);

        $this->assertSame(3, $r->json('hechos.ausencias_creadas'));
        $this->assertSame(0, $r->json('hechos.ausencias_borradas'));

        $filas = $this->faltasDe($caso, 'ausencia');

        $this->assertCount(3, $filas, 'Subir de 0 a 3 son tres filas, una por falta.');

        foreach ($filas as $falta) {
            // La fecha es la del día de la importación y no hay otra posible: el día
            // que el alumno faltó **no está en el archivo**.
            $this->assertSame(Reloj::ahora()->toDateString(), substr((string) $falta->fecha_hora, 0, 10));

            // Y las reglas de negocio copiadas de `postAgregarAusencia`, una por una.
            $this->assertSame('ausencia', $falta->tipo);
            $this->assertSame(1, (int) $falta->cantidad_ausencia);
            $this->assertNull($falta->cantidad_tardanza);
            $this->assertSame(0, (int) $falta->entrada,
                'La columna del libro es de una asignatura, o sea faltas a clase y no de portería.');
        }

        // **La marca de que vino de una planilla vive en `auditoria`**, que es el
        // rastro que esta familia usa desde el 22 ago 2026 (`AusenciasController`).
        // Sin ella, quien mire la planilla del acudiente ve tres faltas el mismo día
        // y no tiene dónde preguntar por qué.
        $linea = DB::selectOne(
            'SELECT valor_nuevo FROM auditoria
              WHERE entidad = "ausencia" AND accion = "crear" AND entidad_id = ?
              ORDER BY id DESC LIMIT 1',
            [(int) $filas[0]->id]
        );

        $this->assertNotNull($linea, 'Una falta creada por la planilla sin su línea de auditoría.');
        $this->assertStringContainsString('planilla sin internet', (string) $linea->valor_nuevo);
    }

    #[Test]
    public function f8_bajar_un_conteo_no_borra_nada_si_nadie_lo_pide(): void
    {
        $caso = $this->conFaltas(4, 'ausencia');

        $ruta = $this->escribiendo($caso['ruta'], $caso['hoja'],
            [[$caso['fila'], (string) $caso['mapa']['columna_aus'], 2]]);

        $r = $this->ensayo($caso['token'], $ruta)->assertStatus(200);

        $renglon = $this->conteoDe($r, $caso['alumno_id'], 'ausencias');

        $this->assertSame('baja', $renglon['direccion']);
        $this->assertSame(2, $renglon['cuantas']);
        $this->assertSame(4, $renglon['espejo'], 'El libro se bajó con las cuatro faltas ya puestas.');

        // **El defecto asimétrico, que es el punto de la fase entera.**
        $this->assertSame('dejar', $renglon['por_defecto']);
        $this->assertStringContainsString('No se borra nada', $renglon['si_no_hago_nada']);

        $r = $this->importar($caso['token'], $ruta)->assertStatus(200);

        $this->assertSame(0, $r->json('hechos.ausencias_borradas'));
        $this->assertCount(4, $this->faltasDe($caso, 'ausencia'),
            'Bajar borra historia con sus fechas, y eso no pasa sin que alguien lo pida con esa sección puesta.');

        // Y se dice en voz alta después de escribir: «entraron 312 notas» no cuenta
        // que además había dos faltas esperando una decisión.
        $this->assertNotEmpty(
            array_filter($r->json('avisos'), static fn ($a) => str_contains($a, 'No se borra nada')),
            'Lo que se quedó esperando tiene que salir en los avisos de la importación.'
        );
    }

    #[Test]
    public function f8_bajar_con_la_decision_puesta_borra_las_mas_recientes_y_por_el_camino_blando(): void
    {
        $caso = $this->conFaltas(0, 'ausencia');

        // Cuatro faltas **antes** de bajar el libro, para que el espejo diga 4: tres
        // de días distintos y **una sin fecha**, que en los datos de verdad son tres
        // de cada cuatro (420 de 544 en `caz_zaragoza`, 21 sep 2026). El orden en que
        // caen es lo único que este test mira.
        $this->ponerFaltas($caso, 'ausencia', ['2026-03-02 07:10:00', '2026-05-11 07:10:00',
            '2026-08-20 07:10:00', null]);

        $caso = $this->bajarLaDe($caso['docente']);

        $ruta = $this->escribiendo($caso['ruta'], $caso['hoja'],
            [[$caso['fila'], (string) $caso['mapa']['columna_aus'], 2]]);

        $r = $this->importar($caso['token'], $ruta, [
            'ausencias' => [[
                'hoja' => $caso['hoja'], 'tipo' => 'ausencias', 'direccion' => 'baja',
                'decision' => 'aplicar',
            ]],
        ])->assertStatus(200);

        $this->assertSame(2, $r->json('hechos.ausencias_borradas'));

        $vivas = $this->faltasDe($caso, 'ausencia');
        $dias = array_map(static fn ($f) => substr((string) $f->fecha_hora, 0, 10), $vivas);

        // **La sin fecha primero, y después la más reciente de las fechadas.** Lo
        // primero porque una falta que no está en ningún día no sale en ninguna
        // consulta por fecha, así que borrarla no le quita nada a nadie; lo segundo
        // porque la vieja es la que sostiene una citación o un proceso, y la reciente
        // es la que todavía se puede recordar y volver a anotar con su día.
        $this->assertSame(['2026-03-02', '2026-05-11'], $dias,
            'Con el orden natural de MySQL —los NULL al final en un DESC— habrían caído las dos fechadas '
            .'recientes y la que no dice nada habría sobrevivido: destruir la única información que hay '
            .'para conservar la que no existe.');

        // **Camino blando, nunca `DELETE`**, y firmado: la fila borrada es justo lo
        // que se mira cuando alguien reclama.
        $borradas = DB::select(
            'SELECT deleted_by FROM ausencias
              WHERE alumno_id = ? AND asignatura_id = ? AND periodo_id = ? AND tipo = "ausencia"
                AND deleted_at IS NOT NULL',
            [$caso['alumno_id'], $caso['asignatura_id'], $caso['periodo_id']]
        );

        $this->assertCount(2, $borradas, 'Un `DELETE` de verdad se llevaría la fila y con ella el rastro.');

        foreach ($borradas as $falta) {
            $this->assertNotNull($falta->deleted_by,
                'En la copia de producción del 22 ago 2026 había 5.689 ausencias borradas y 5.684 sin autor.');
        }
    }

    #[Test]
    public function f8_d3_lo_que_el_docente_no_toco_no_se_toca_aunque_la_base_haya_cambiado(): void
    {
        $caso = $this->conFaltas(2, 'ausencia');

        // Y **después** de bajar el libro, alguien anota dos más en la web. Es el caso
        // que la D3 existe para proteger: el docente no tocó la columna `Aus`, así que
        // su «2» no es una orden de bajar el conteo a la mitad.
        $this->ponerFaltas($caso, 'ausencia', [null, null]);

        $r = $this->ensayo($caso['token'], $caso['ruta'])->assertStatus(200);

        $this->assertSame([], $r->json('familias.ausencias'),
            'El archivo trae lo mismo que el espejo: esa columna no se decide, pase lo que pase con la base.');

        $this->importar($caso['token'], $caso['ruta'])->assertStatus(200);

        $this->assertCount(4, $this->faltasDe($caso, 'ausencia'),
            'Sin el espejo, el importador habría visto «archivo 2 contra base 4» y habría borrado las dos.');
    }

    #[Test]
    public function f8_cambio_en_los_dos_sitios_es_un_choque_y_manda_el_sistema(): void
    {
        $caso = $this->conFaltas(2, 'tardanza');

        // El docente sube a 5 en su libro; mientras tanto alguien anota una más en la
        // web. Cambió en los dos sitios y a valores distintos: eso es un choque.
        $ruta = $this->escribiendo($caso['ruta'], $caso['hoja'],
            [[$caso['fila'], (string) $caso['mapa']['columna_tar'], 5]]);

        $this->ponerFaltas($caso, 'tardanza', [null]);

        $r = $this->ensayo($caso['token'], $ruta)->assertStatus(200);

        $renglon = $this->conteoDe($r, $caso['alumno_id'], 'tardanzas');

        $this->assertTrue($renglon['choque']);
        $this->assertSame(2, $renglon['espejo']);
        $this->assertSame(3, $renglon['base']);
        $this->assertSame(5, $renglon['archivo']);
        $this->assertSame('dejar', $renglon['decision'],
            'Manda el sistema, como en las notas: quien lo tocó en la web sabía lo que hacía.');

        // Y **ni siquiera pidiéndolo**: el contrato de esta familia se decide por
        // columna y dirección, así que no hay sitio donde contestar «en este alumno
        // manda el archivo». Antes que inventarlo, gana el sistema siempre.
        $this->importar($caso['token'], $ruta, [
            'ausencias' => [[
                'hoja' => $caso['hoja'], 'tipo' => 'tardanzas', 'direccion' => 'sube',
                'decision' => 'aplicar',
            ]],
        ])->assertStatus(200);

        $this->assertCount(3, $this->faltasDe($caso, 'tardanza'));
    }

    #[Test]
    public function f8_las_dos_direcciones_de_la_misma_columna_se_contestan_por_separado(): void
    {
        $caso = $this->conFaltas(0, 'ausencia', 2);

        // El caso normal y el que rompería una llave de dos piezas: **la misma hoja y
        // la misma columna, con un alumno que sube y otro que baja**. Si la llave
        // fuera `hoja` + `tipo`, las dos direcciones se contestarían con una sola
        // entrada y la mitad se aplicaría con el defecto de la otra mitad.
        $este = $this->paraElAlumno($caso, 1);
        $this->ponerFaltas($este, 'ausencia', [null, null, null]);

        $caso = $this->bajarLaDe($caso['docente'], 2);
        $este = $this->paraElAlumno($caso, 1);

        $aus = (string) $caso['mapa']['columna_aus'];

        $ruta = $this->escribiendo($caso['ruta'], $caso['hoja'], [
            [$caso['filas'][0], $aus, 2],   // 0 -> 2, sube
            [$caso['filas'][1], $aus, 1],   // 3 -> 1, baja
        ]);

        $r = $this->ensayo($caso['token'], $ruta)->assertStatus(200);

        $this->assertSame('sube', $this->conteoDe($r, $caso['alumnos'][0], 'ausencias')['direccion']);
        $this->assertSame('baja', $this->conteoDe($r, $caso['alumnos'][1], 'ausencias')['direccion']);

        // Se contesta **sólo la bajada**. La subida no viene en la sección y cae a su
        // propio defecto, que es aplicar — que es justo lo que la pantalla pinta.
        $r = $this->importar($caso['token'], $ruta, [
            'ausencias' => [[
                'hoja' => $caso['hoja'], 'tipo' => 'ausencias', 'direccion' => 'baja',
                'decision' => 'aplicar',
            ]],
        ])->assertStatus(200);

        $this->assertSame(2, $r->json('hechos.ausencias_creadas'));
        $this->assertSame(2, $r->json('hechos.ausencias_borradas'));

        $this->assertCount(2, $this->faltasDe($this->paraElAlumno($caso, 0), 'ausencia'));
        $this->assertCount(1, $this->faltasDe($este, 'ausencia'));
    }

    #[Test]
    public function f8_una_seccion_que_dice_dejar_gana_al_defecto_de_subir(): void
    {
        $caso = $this->conFaltas(0, 'ausencia');

        $ruta = $this->escribiendo($caso['ruta'], $caso['hoja'],
            [[$caso['fila'], (string) $caso['mapa']['columna_aus'], 3]]);

        // El defecto de subir es aplicar, pero **manda lo que llegue**. Es la mitad
        // que impide que los dos lados tengan defectos propios y dejen de coincidir
        // en silencio: si la sección viaja, se obedece tal cual.
        $r = $this->importar($caso['token'], $ruta, [
            'ausencias' => [[
                'hoja' => $caso['hoja'], 'tipo' => 'ausencias', 'direccion' => 'sube',
                'decision' => 'dejar',
            ]],
        ])->assertStatus(200);

        $this->assertSame(0, $r->json('hechos.ausencias_creadas'));
        $this->assertCount(0, $this->faltasDe($caso, 'ausencia'));
    }

    #[Test]
    public function f8_un_libro_de_la_version_de_formato_anterior_se_sigue_leyendo(): void
    {
        $caso = $this->conFaltas(2, 'ausencia');

        // Un libro tal y como lo generaba el despliegue de antes de la fase 4: sin
        // `asistencia` en el mapa, con `B1 = 1` y **firmado con el formato 1**.
        $ruta = $this->reescribirMetadatos($caso['ruta'], null, static function (array $mapas) {
            foreach ($mapas as $i => $mapa) {
                unset($mapas[$i]['asistencia']);
            }

            return $mapas;
        }, true, 1);

        $ruta = $this->escribiendo($ruta, $caso['hoja'],
            [[$caso['fila'], (string) $caso['mapa']['columna_aus'], 5]]);

        $r = $this->ensayo($caso['token'], $ruta)->assertStatus(200);

        // **Lo primero, que no reviente ni degrade**: la firma sigue valiendo, porque
        // se comprueba con el número que el libro declara y no con el de hoy.
        $r->assertJson(['peldano' => 1, 'firma_valida' => true, 'version_formato' => 1]);

        $renglon = $this->conteoDe($r, $caso['alumno_id'], 'ausencias');

        // Sin espejo, esta familia se comporta como si no lo hubiera: se compara
        // archivo contra base, y no hay choque que declarar.
        $this->assertNull($renglon['espejo']);
        $this->assertFalse($renglon['choque']);
        $this->assertSame('sube', $renglon['direccion']);

        // Y el `null` **lo explica el servidor**: pintado como un guion, la pantalla
        // no sabría si quiere decir «el libro no lo trae» o «había cero», y la
        // segunda es un número y no un desconocido.
        $this->assertStringContainsString('versión anterior', $renglon['si_no_hago_nada']);

        $this->importar($caso['token'], $ruta)->assertStatus(200);

        $this->assertCount(5, $this->faltasDe($caso, 'ausencia'),
            'Un libro de la versión anterior se lee entero: lo que le falta es el espejo, no la función.');
    }

    #[Test]
    public function f8_un_libro_de_una_version_que_este_servidor_no_conoce_no_es_firma_rota(): void
    {
        $caso = $this->unaPlanilla();

        $ruta = $this->reescribirMetadatos($caso['ruta'], null, null, true, 99);

        $r = $this->ensayo($caso['token'], $ruta)->assertStatus(200);

        // No es el peldaño 2 —«alguien tocó este archivo»— sino el 5 con su salida:
        // lo que hay delante es un libro de otro despliegue, y mandar a buscar un
        // manipulador que no existe es peor que no decir nada.
        $r->assertJson(['peldano' => 5]);

        $this->assertNotEmpty(array_filter($r->json('bloqueos'),
            static fn ($b) => str_contains((string) ($b['motivo'] ?? ''), 'Descargue el libro otra vez')),
            'El peldaño 5 es el que evita el callejón sin salida: tiene que ofrecer la salida.');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // F6 — las filas que no se reconocen. Tres casos y tres respuestas distintas
    // ─────────────────────────────────────────────────────────────────────────

    #[Test]
    public function f6a_una_fila_escrita_a_mano_se_empareja_con_tildes_y_con_el_orden_cambiado(): void
    {
        $caso = $this->unaPlanilla();

        $quien = $this->fichaDelAlumno($caso['alumno_id']);
        $fila = $this->bloqueDeAlumnosNuevos($caso);
        $escrito = $this->comoLoEscribiriaElDocente($quien);

        // La planilla imprime «APELLIDOS, Nombres» y con tildes; el docente escribe
        // el nombre delante, en minúsculas y sin tildes. **Las dos cosas a la vez
        // son el caso normal**, no el raro, y un emparejador que sólo comparase la
        // cadena entera no lo vería (`ParecidoDeNombresTest`).
        $ruta = $this->escribiendo($caso['ruta'], $caso['hoja'], [
            [$fila, 'B', $escrito],
            [$fila, $caso['columna'], $this->otroValorPara($caso, $caso['fila'], $caso['columna'])],
        ]);

        $r = $this->ensayo($caso['token'], $ruta)->assertStatus(200);

        $aMano = $this->filasDelTipo($r, 'escrita_a_mano');

        $this->assertCount(1, $aMano, 'El nombre escrito en el bloque del final es UNA pregunta, y por fila.');
        $this->assertSame($escrito, $aMano[0]['escrito'], 'Lo que tecleó va tal cual, sin limpiar.');
        $this->assertSame($fila, $aMano[0]['fila']);
        $this->assertNotEmpty($aMano[0]['grupo'], 'La frase de la pantalla dice en qué grupo se buscó.');
        $this->assertTrue($aMano[0]['decidible']);
        $this->assertSame(1, $aMano[0]['notas_en_la_fila']);

        $candidatos = $aMano[0]['candidatos'];

        $this->assertNotEmpty($candidatos, 'Si no lo encuentra, manda a secretaría a matricular a alguien '
            .'que ya está matriculado.');
        $this->assertLessThanOrEqual(3, count($candidatos),
            'Cinco nombres que no se parecen a nada son peor que ninguno.');
        // **Está entre los candidatos, no necesariamente el primero**, y el seed
        // explica por qué: tiene dos alumnos con el mismo nombre exacto en el mismo
        // grupo. Con un empate perfecto no hay un «el correcto» que el servidor
        // pueda adivinar — y ésa es justamente la pantalla que se está construyendo:
        // la que pregunta.
        $suyo = $this->candidatoDe($aMano[0], $caso['alumno_id']);

        $this->assertGreaterThan(0.5, $suyo['parecido']);

        // **Con foto y con matrícula**, como todo listado de personas en MyVc: en una
        // lista de treinta apellidos parecidos es lo que evita el error.
        $this->assertNotEmpty($suyo['foto']);
        $this->assertArrayHasKey('no_matricula', $suyo);

        // Y el sexo crudo, que es lo que deja escribir «Sí, es él» o «Sí, es ella».
        // Adivinarlo por el nombre falla justo donde se decide.
        $this->assertArrayHasKey('sexo', $suyo);
        $this->assertSame(
            trim((string) $quien->sexo) === '' ? null : trim((string) $quien->sexo),
            $suyo['sexo']
        );
    }

    #[Test]
    public function f6a_la_tarjeta_dice_que_ese_alumno_ya_esta_en_la_hoja_y_con_que_notas(): void
    {
        $caso = $this->unaPlanilla();

        $quien = $this->fichaDelAlumno($caso['alumno_id']);
        $fila = $this->bloqueDeAlumnosNuevos($caso);

        $ruta = $this->escribiendo($caso['ruta'], $caso['hoja'], [
            [$fila, 'B', $this->comoLoEscribiriaElDocente($quien)],
        ]);

        $r = $this->ensayo($caso['token'], $ruta)->assertStatus(200);

        $candidato = $this->candidatoDe($this->filasDelTipo($r, 'escrita_a_mano')[0], $caso['alumno_id']);

        // **Es el caso de verdad frecuente y es lo que hace útil la tarjeta**: el
        // alumno ya estaba en la lista —está como *Cárdenas*, el docente escribió
        // *Cardenaz*— y lo apuntó abajo sin verlo. Si la respuesta no dice que ya
        // tiene notas en la fila 8, la persona acepta y **pisa notas sin enterarse**.
        $this->assertNotNull($candidato['ya_esta_en_la_hoja'],
            'Sin esto la tarjeta es un buscador, y el buscador no avisa de lo que se va a pisar.');
        $this->assertSame($caso['fila'], $candidato['ya_esta_en_la_hoja']['fila']);
        $this->assertCount(
            count($caso['mapa']['columnas']),
            $candidato['ya_esta_en_la_hoja']['notas'],
            'Van los valores de TODA la fila, que es lo que el docente compara de un vistazo.'
        );
    }

    #[Test]
    public function f6a_sin_candidatos_la_fila_no_se_importa_y_se_dice_quien_puede_arreglarlo(): void
    {
        $caso = $this->unaPlanilla();

        $fila = $this->bloqueDeAlumnosNuevos($caso);

        $ruta = $this->escribiendo($caso['ruta'], $caso['hoja'], [
            [$fila, 'B', 'Zzyzx Qwertyuiop Mnbvcxz'],
            [$fila, $caso['columna'], $this->otroValorPara($caso, $caso['fila'], $caso['columna'])],
        ]);

        $antes = $this->huellaDeLaBase();

        $r = $this->ensayo($caso['token'], $ruta)->assertStatus(200);

        $aMano = $this->filasDelTipo($r, 'escrita_a_mano');

        $this->assertCount(1, $aMano);
        $this->assertSame([], $aMano[0]['candidatos']);
        $this->assertFalse($aMano[0]['decidible'],
            'Tres botones sin nadie a quien señalar es una pantalla rota: sin candidatos esto es un aviso.');
        $this->assertStringContainsString('secretaría', $aMano[0]['si_no_hago_nada'],
            'Un error que no ofrece salida obliga a llamar por teléfono. Aquí la salida existe y no es '
            .'del docente: matricular es cosa de secretaría.');

        $r = $this->importar($caso['token'], $ruta)->assertStatus(200);

        $this->assertSame($antes, $this->huellaDeLaBase(), 'No se crea a nadie. Nunca.');
        $this->assertGreaterThan(0, $r->json('hechos.filas_descartadas'));
        $this->assertNotEmpty(
            array_filter($r->json('avisos'), static fn ($a) => str_contains($a, 'Zzyzx')),
            'Lo que no entró se dice en voz alta después de escribir, no sólo en el ensayo.'
        );
    }

    #[Test]
    public function f6a_resolver_la_fila_escribe_la_nota_para_ese_alumno(): void
    {
        $caso = $this->unaPlanilla();

        // **La casilla se vacía ANTES de bajar el libro.** El espejo guarda lo del
        // día de la descarga: borrarla después fabricaría un choque, que es el caso
        // de al lado y no éste.
        DB::delete('DELETE FROM notas WHERE alumno_id = ? AND subunidad_id = ?',
            [$caso['alumno_id'], $caso['subunidad_id']]);

        $caso = $this->bajarLaDe($caso['docente']);

        $quien = $this->fichaDelAlumno($caso['alumno_id']);
        $fila = $this->bloqueDeAlumnosNuevos($caso);

        $valor = $this->otroValorPara($caso, $caso['fila'], $caso['columna']);

        $ruta = $this->escribiendo($caso['ruta'], $caso['hoja'], [
            [$fila, 'B', $this->comoLoEscribiriaElDocente($quien)],
            [$fila, $caso['columna'], $valor],
        ]);

        $r = $this->ensayo($caso['token'], $ruta)->assertStatus(200);
        $id = $this->filasDelTipo($r, 'escrita_a_mano')[0]['id'];

        // Sin decidir no se escribe: el defecto de esta familia es «todavía no se
        // sabe de quién es», y el que no pierde trabajo.
        $this->importar($caso['token'], $ruta)->assertStatus(200);
        $this->assertNull($this->notaDe($caso['alumno_id'], $caso['subunidad_id']));

        $r = $this->importar($caso['token'], $ruta, [
            'filas' => [['id' => $id, 'decision' => 'es:'.$caso['alumno_id']]],
        ])->assertStatus(200);

        $this->assertSame($valor, $this->notaDe($caso['alumno_id'], $caso['subunidad_id']),
            'La fila resuelta se escribe con la misma siembra y el mismo recálculo que las de la rejilla.');
        $this->assertGreaterThan(0, $r->json('hechos.filas_sembradas'),
            'La casilla no tenía fila en `notas`: hay que sembrarla, o la nota no se guarda y no da error.');
    }

    #[Test]
    public function f6a_resolver_una_fila_que_pisa_una_nota_existente_es_un_choque_y_no_una_escritura_silenciosa(): void
    {
        $caso = $this->unaPlanilla();

        // El estado de partida va ANTES de la descarga: lo que se fabrica es «este
        // alumno YA tiene nota puesta», no «alguien la cambió por la web».
        $puesta = EscalaDeNotas::minimo((int) $caso['year_id']) ?? 0;

        $this->ponerNota($caso['alumno_id'], $caso['subunidad_id'], $puesta);

        $caso = $this->bajarLaDe($caso['docente']);

        $quien = $this->fichaDelAlumno($caso['alumno_id']);
        $fila = $this->bloqueDeAlumnosNuevos($caso);
        $otro = $this->otroValorPara($caso, $caso['fila'], $caso['columna']);

        $ruta = $this->escribiendo($caso['ruta'], $caso['hoja'], [
            [$fila, 'B', $this->comoLoEscribiriaElDocente($quien)],
            [$fila, $caso['columna'], $otro],
        ]);

        $r = $this->ensayo($caso['token'], $ruta)->assertStatus(200);
        $id = $this->filasDelTipo($r, 'escrita_a_mano')[0]['id'];

        // La tarjeta lo avisa ANTES: ese alumno ya está en la hoja y ya tiene notas.
        $this->assertNotNull(
            $this->candidatoDe($this->filasDelTipo($r, 'escrita_a_mano')[0], $caso['alumno_id'])['ya_esta_en_la_hoja']
        );

        $decidida = ['filas' => [['id' => $id, 'decision' => 'es:'.$caso['alumno_id']]]];

        $r = $this->ensayo($caso['token'], $ruta, $decidida)->assertStatus(200);

        // **Y con la fila ya resuelta, la casilla entra por el camino de los choques
        // (F7) y no por un atajo.** Una fila escrita a mano no tiene espejo —esa
        // casilla estaba vacía al bajar el libro—, así que pisar una nota que ya
        // existe es, por definición, «cambió en los dos sitios».
        $choques = array_values(array_filter(
            $r->json('familias.choques'),
            static fn ($c) => (int) $c['valor_sistema'] === $puesta
        ));

        $this->assertCount(1, $choques, 'Resolver la fila no puede escribir encima sin enseñarlo.');
        $this->assertSame($otro, (int) $choques[0]['valor_archivo']);

        // Por defecto manda el sistema, que es lo seguro.
        $this->importar($caso['token'], $ruta, $decidida)->assertStatus(200);

        $this->assertSame($puesta, $this->notaDe($caso['alumno_id'], $caso['subunidad_id']));

        // Y sólo si se dice, manda el archivo.
        $this->importar($caso['token'], $ruta, [
            'filas' => $decidida['filas'],
            'choques' => ['por_defecto' => 'archivo', 'excepciones' => []],
        ])->assertStatus(200);

        $this->assertSame($otro, $this->notaDe($caso['alumno_id'], $caso['subunidad_id']));
    }

    #[Test]
    public function f6a_un_alumno_de_otro_grupo_da_error_y_no_escribe_nada(): void
    {
        $caso = $this->unaPlanilla();

        // **El caso se fabrica**: los 68 alumnos del seed están todos matriculados en
        // este grupo, así que «alguien de fuera» no se puede encontrar, se crea. Es
        // lo mismo que hace este test con un periodo cerrado o un indicador borrado:
        // esperar a que el seed lo traiga es no comprobarlo.
        $ajeno = $this->unAlumnoSinGrupo('Ajeno Deotrogrupo', 'Quintanilla Wu');

        $quien = $this->fichaDelAlumno($caso['alumno_id']);
        $fila = $this->bloqueDeAlumnosNuevos($caso);

        $ruta = $this->escribiendo($caso['ruta'], $caso['hoja'], [
            [$fila, 'B', $this->comoLoEscribiriaElDocente($quien)],
            [$fila, $caso['columna'], $this->otroValorPara($caso, $caso['fila'], $caso['columna'])],
        ]);

        $id = $this->filasDelTipo($this->ensayo($caso['token'], $ruta)->assertStatus(200), 'escrita_a_mano')[0]['id'];

        $antes = $this->huellaDeLaBase();

        // **La decisión llega del cliente y no se cree.** Escribir la nota de alguien
        // que no está matriculado en ese grupo sería corromper la planilla en
        // silencio: un dato que parece bueno, que nadie revisa y que sale en un
        // boletín.
        $r = $this->importar($caso['token'], $ruta, [
            'filas' => [['id' => $id, 'decision' => 'es:'.$ajeno]],
        ])->assertStatus(422);

        $this->assertSame(
            ['fila_de_otro_grupo'],
            array_values(array_unique(array_column($r->json('bloqueos'), 'tipo')))
        );

        $this->assertSame($antes, $this->huellaDeLaBase(),
            'Un bloqueo es «este libro no se puede subir»: escribir lo que sí se puede dejaría media '
            .'planilla dentro y un error delante.');
    }

    #[Test]
    public function f6b_el_id_que_ya_no_esta_en_el_grupo_se_informa_con_su_motivo_y_no_se_pregunta(): void
    {
        $caso = $this->unaPlanilla();

        // Se retira DESPUÉS de bajar el libro, que es el caso: el `ID` estaba en la
        // hoja cuando se descargó y hoy ya no está en el grupo.
        DB::update(
            'UPDATE matriculas SET estado = "RETI", fecha_retiro = "2026-09-18",
                    razon_retiro = "Cambio de ciudad"
              WHERE alumno_id = ? AND grupo_id = ? AND deleted_at IS NULL',
            [$caso['alumno_id'], $this->grupoDe($caso['asignatura_id'])]
        );

        $ruta = $this->escribiendo($caso['ruta'], $caso['hoja'], [
            [$caso['fila'], $caso['columna'], $this->otroValorPara($caso, $caso['fila'], $caso['columna'])],

            // Y también sus faltas, para la F8: el docente escribió en la columna `Aus`
            // de una fila cuya persona ya no está en el grupo.
            [$caso['fila'], (string) $caso['mapa']['columna_aus'], 9],
        ]);

        $r = $this->ensayo($caso['token'], $ruta)->assertStatus(200);

        // **Sus faltas tampoco se preguntan.** La escritura se salta su fila entera,
        // así que un renglón decidible sobre sus ausencias sería una promesa que la
        // importación no va a cumplir. Lo que se le dice es lo de abajo: se retiró.
        $this->assertSame([], array_values(array_filter(
            $r->json('familias.ausencias') ?? [],
            static fn ($a) => (int) $a['alumno_id'] === $caso['alumno_id']
        )), 'Un renglón de ausencias de quien ya no está en el grupo es una decisión que no se aplica.');

        $suya = array_values(array_filter(
            $this->filasDelTipo($r, 'ya_no_esta_en_el_grupo'),
            static fn ($f) => $f['alumno']['alumno_id'] === $caso['alumno_id']
        ));

        $this->assertCount(1, $suya);
        $this->assertFalse($suya[0]['decidible'],
            'Que alguien se haya retirado no es algo que el docente pueda contestar.');
        $this->assertSame([], $suya[0]['candidatos']);
        $this->assertSame($caso['fila'], $suya[0]['fila']);
        $this->assertStringContainsString('18/09/2026', $suya[0]['alumno']['motivo'],
            'Sin el motivo y la fecha, «sus notas no entraron» es indistinguible de una avería.');
        $this->assertStringContainsString('Cambio de ciudad', $suya[0]['alumno']['motivo']);
        $this->assertNotEmpty($suya[0]['alumno']['foto']);
        $this->assertArrayHasKey('sexo', $suya[0]['alumno']);

        $antes = $this->notaDe($caso['alumno_id'], $caso['subunidad_id']);

        $this->importar($caso['token'], $ruta)->assertStatus(200);

        $this->assertSame($antes, $this->notaDe($caso['alumno_id'], $caso['subunidad_id']),
            'Sus notas se quedan fuera: ya no está matriculado en el grupo de esa hoja.');
    }

    #[Test]
    public function f6c_el_que_entro_despues_es_un_aviso_con_su_nombre_y_sin_fila(): void
    {
        $caso = $this->unaPlanilla();

        $grupoId = $this->grupoDe($caso['asignatura_id']);

        // Igual que el de al lado: en el seed no queda nadie por matricular aquí, así
        // que el alumno se fabrica.
        $nuevo = $this->unAlumnoSinGrupo('Recienllegada', 'Toro Mejia');

        // Se matricula DESPUÉS de bajar el libro: por eso no tiene casillas en la
        // hoja y por eso sus notas siguen sin pasar.
        DB::insert(
            'INSERT INTO matriculas (alumno_id, grupo_id, estado, fecha_matricula, created_at, updated_at)
             VALUES (?, ?, "MATR", "2026-09-19", NOW(), NOW())',
            [$nuevo, $grupoId]
        );

        $r = $this->ensayo($caso['token'], $caso['ruta'])->assertStatus(200);

        $suya = array_values(array_filter(
            $this->filasDelTipo($r, 'entro_despues'),
            static fn ($f) => $f['alumno']['alumno_id'] === $nuevo
        ));

        $this->assertCount(1, $suya);
        $this->assertFalse($suya[0]['decidible'], 'Es un aviso, no un problema.');
        $this->assertNotEmpty($suya[0]['alumno']['nombre'],
            'Con su nombre, para que el docente sepa a quién le falta pasar la nota.');

        // **`fila` va `null` y no un cero.** Ese alumno no tiene fila en el libro,
        // que es justamente el problema del que avisa: un número que parece una fila
        // del Excel y no lo es acaba copiado en un correo y no lleva a ninguna parte.
        $this->assertNull($suya[0]['fila']);
        $this->assertSame(0, $suya[0]['notas_en_la_fila']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Los ayudantes
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * La primera fila del bloque «alumnos que no aparecen en la lista».
     *
     * Se calcula como la calcula el lector —cuatro filas debajo del último alumno—
     * y **no se lee del mapa**, porque el mapa no la guarda: su sitio está atado al
     * de la rejilla y guardarla sería un segundo sitio donde la misma cuenta puede
     * desincronizarse.
     *
     * @param  array<string, mixed>  $caso
     */
    private function bloqueDeAlumnosNuevos(array $caso): int
    {
        return max(array_map('intval', array_keys($caso['mapa']['filas']))) + 4;
    }

    private function fichaDelAlumno(int $alumnoId): object
    {
        $ficha = DB::selectOne('SELECT nombres, apellidos, sexo FROM alumnos WHERE id = ?', [$alumnoId]);

        $this->assertNotNull($ficha);

        return $ficha;
    }

    /**
     * Lo que el docente teclea de verdad: **el nombre delante, en minúsculas y sin
     * tildes**.
     *
     * Son las dos cosas que el §6.4 pone de ejemplo y las dos a la vez, porque las
     * dos a la vez son el caso normal: la planilla imprime `APELLIDOS, Nombres` con
     * sus tildes y nadie escribe así a mano.
     */
    private function comoLoEscribiriaElDocente(object $ficha): string
    {
        $entero = trim(trim((string) $ficha->nombres).' '.trim((string) $ficha->apellidos));

        return trim(strtr(mb_strtolower($entero, 'UTF-8'), [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
        ]));
    }

    /**
     * Los renglones de la F6 de un tipo.
     *
     * @return list<array<string, mixed>>
     */
    private function filasDelTipo(TestResponse $r, string $tipo): array
    {
        return array_values(array_filter(
            (array) $r->json('familias.filas'),
            static fn ($f) => $f['tipo'] === $tipo
        ));
    }

    /** Deja una nota puesta, sembrando la fila si no existía. */
    private function ponerNota(int $alumnoId, int $subunidadId, int $valor): void
    {
        $hay = DB::selectOne(
            'SELECT id FROM notas WHERE alumno_id = ? AND subunidad_id = ? AND deleted_at IS NULL LIMIT 1',
            [$alumnoId, $subunidadId]
        );

        if ($hay === null) {
            DB::insert(
                'INSERT INTO notas (subunidad_id, alumno_id, nota, created_at, updated_at)
                 VALUES (?, ?, ?, NOW(), NOW())',
                [$subunidadId, $alumnoId, $valor]
            );

            return;
        }

        DB::update('UPDATE notas SET nota = ? WHERE id = ?', [$valor, $hay->id]);
    }

    /**
     * El candidato que es **esa** persona, con su nombre delante si no está.
     *
     * @param  array<string, mixed>  $fila
     * @return array<string, mixed>
     */
    private function candidatoDe(array $fila, int $alumnoId): array
    {
        foreach ($fila['candidatos'] as $candidato) {
            if ($candidato['alumno_id'] === $alumnoId) {
                return $candidato;
            }
        }

        $this->fail('El alumno '.$alumnoId.' no salió entre los candidatos de «'.$fila['escrito'].'»: '
            .implode(', ', array_column($fila['candidatos'], 'nombre')));
    }

    /**
     * Un alumno recién creado y **sin ninguna matrícula**.
     *
     * Hace falta porque el seed tiene 68 alumnos y los 68 están matriculados en el
     * grupo que estos tests usan: «alguien de fuera» no se puede encontrar, se
     * fabrica. Va dentro de la transacción del test, como todo lo demás.
     */
    private function unAlumnoSinGrupo(string $nombres, string $apellidos): int
    {
        DB::insert(
            'INSERT INTO alumnos (nombres, apellidos, sexo, nee, created_at, updated_at)
             VALUES (?, ?, "M", 0, NOW(), NOW())',
            [$nombres, $apellidos]
        );

        return (int) DB::getPdo()->lastInsertId();
    }

    private function grupoDe(int $asignaturaId): int
    {
        return (int) DB::selectOne('SELECT grupo_id FROM asignaturas WHERE id = ?', [$asignaturaId])->grupo_id;
    }

    /**
     * Un docente con una asignatura calificable en el periodo actual, y su libro ya
     * descargado.
     *
     * **No vale «el primer profesor del seed»**: un docente sin unidades en ese
     * periodo devuelve un libro sin columnas y todas las comprobaciones de debajo
     * pasarían sin haber mirado nada. Se pide el que más indicadores tiene y se
     * ordena por id para que sea el mismo en cada corrida.
     *
     * @param  int  $cuantasFilas  cuántos alumnos hacen falta para el caso
     * @return array<string, mixed>
     */
    private function unaPlanilla(int $cuantasFilas = 1): array
    {
        $docente = $this->docenteConPlanilla($cuantasFilas);

        return $this->bajarLaDe($docente, $cuantasFilas);
    }

    /**
     * Una planilla cuyo alumno ya tiene `$cuantas` faltas **antes de bajar el
     * libro**, o sea con el espejo puesto.
     *
     * El orden es la trampa de la §5 del doc 50 y aquí muerde igual que con las
     * notas: poner las faltas después de la descarga no fabrica «el docente las
     * baja», fabrica un choque.
     *
     * **Y empieza vaciando lo que el seed ya trae**, que es lo que costó la primera
     * corrida: los alumnos del seed **ya tienen faltas** en esa asignatura y ese
     * periodo, así que «subir de 0 a 3» no era de 0 a 3 y seis tests salieron rojos
     * con el mismo desfase de uno. Un caso fabricado sobre una población que no se
     * ha mirado no es un caso: es una coincidencia.
     *
     * @return array<string, mixed>
     */
    private function conFaltas(int $cuantas, string $tipo, int $cuantosAlumnos = 1): array
    {
        $caso = $this->unaPlanilla($cuantosAlumnos);

        // Borrado duro y de la asignatura entera: esto **fabrica el estado de
        // partida** y no prueba nada —para el borrado está el test del camino
        // blando—, y vive dentro de la transacción del test, que se deshace al
        // terminar.
        DB::delete('DELETE FROM ausencias WHERE asignatura_id = ? AND periodo_id = ?',
            [$caso['asignatura_id'], $caso['periodo_id']]);

        if ($cuantas > 0) {
            $this->ponerFaltas($caso, $tipo, array_fill(0, $cuantas, null));
        }

        return $this->bajarLaDe($caso['docente'], $cuantosAlumnos);
    }

    /**
     * Anota faltas a mano, una por fecha. **Un `null` en la lista es una falta sin
     * fecha**, que es lo que de verdad hay en la base: 420 de las 544 filas vivas
     * que el libro puede contar en `caz_zaragoza` (21 sep 2026) no están en ningún
     * día. Rellenárselas aquí fabricaría una población que no existe.
     *
     * **Se escriben con `INSERT` y no por `ausencias/*`**, y no es un atajo: esas
     * rutas son el contrato de `myvc_flutter` y llamarlas desde aquí mezclaría lo
     * que este test fabrica con lo que este test comprueba.
     *
     * @param  array<string, mixed>  $caso
     * @param  list<?string>  $fechas
     */
    private function ponerFaltas(array $caso, string $tipo, array $fechas): void
    {
        foreach ($fechas as $fecha) {
            DB::insert(
                'INSERT INTO ausencias (alumno_id, asignatura_id, periodo_id, cantidad_ausencia,
                    cantidad_tardanza, entrada, tipo, fecha_hora, created_by, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, 0, ?, ?, 1, NOW(), NOW())',
                [$caso['alumno_id'], $caso['asignatura_id'], $caso['periodo_id'],
                    $tipo === 'tardanza' ? null : 1, $tipo === 'tardanza' ? 1 : null,
                    $tipo, $fecha]
            );
        }
    }

    /**
     * Las faltas vivas de ese alumno en esa asignatura y periodo, **de la vieja a la
     * nueva**, que es el orden en el que este test razona.
     *
     * @param  array<string, mixed>  $caso
     * @return array<int, object>
     */
    private function faltasDe(array $caso, string $tipo): array
    {
        return DB::select(
            'SELECT id, tipo, fecha_hora, cantidad_ausencia, cantidad_tardanza, entrada, deleted_by
               FROM ausencias
              WHERE alumno_id = ? AND asignatura_id = ? AND periodo_id = ? AND tipo = ?
                AND deleted_at IS NULL
              ORDER BY fecha_hora, id',
            [$caso['alumno_id'], $caso['asignatura_id'], $caso['periodo_id'], $tipo]
        );
    }

    /** El mismo caso apuntando a otro de sus alumnos. @param array<string, mixed> $caso */
    private function paraElAlumno(array $caso, int $cual): array
    {
        $caso['alumno_id'] = $caso['alumnos'][$cual];
        $caso['fila'] = $caso['filas'][$cual];

        return $caso;
    }

    /**
     * El renglón de la F8 de un alumno y un tipo. Falla si no está, que es lo que
     * hace falta: un `assertNull` sobre una familia vacía pasaría igual.
     *
     * @return array<string, mixed>
     */
    private function conteoDe(TestResponse $r, int $alumnoId, string $tipo): array
    {
        foreach (($r->json('familias.ausencias') ?? []) as $renglon) {
            if ((int) $renglon['alumno_id'] === $alumnoId && $renglon['tipo'] === $tipo) {
                return $renglon;
            }
        }

        $this->fail('La familia de ausencias no trae el renglón de '.$tipo.' del alumno '.$alumnoId
            .'. Lo que trae: '.json_encode($r->json('familias.ausencias')));
    }

    /**
     * Vuelve a bajar el libro del mismo docente **después de tocar la base**.
     *
     * Hace falta para los casos en los que lo que se fabrica es el estado de
     * partida: el espejo de `_myvc` guarda lo que había **el día de la descarga**,
     * así que cambiar una nota y seguir usando el libro viejo no fabrica el caso —
     * fabrica un choque.
     *
     * @return array<string, mixed>
     */
    private function bajarLaDe(object $docente, int $cuantasFilas = 1): array
    {
        $token = $this->tokenDe($docente->username);

        $ruta = $this->bajar($token,
            "/api/planilla-offline/planilla/{$docente->asignatura_id}/{$docente->periodo_id}");

        return $this->casoDesde($ruta, $token, $docente, $cuantasFilas);
    }

    /**
     * Lo mismo con dos asignaturas, para la F3: una hoja se cae y la otra entra.
     *
     * @return array<string, mixed>
     */
    private function dosPlanillas(): array
    {
        $docente = $this->docenteConPlanilla(1, 2);

        $otra = DB::selectOne(
            'SELECT a.id FROM asignaturas a
              INNER JOIN grupos g ON g.id = a.grupo_id AND g.year_id = ? AND g.deleted_at IS NULL
             WHERE a.profesor_id = ? AND a.id <> ? AND a.deleted_at IS NULL
             ORDER BY a.id LIMIT 1',
            [$docente->year_id, $docente->profesor_id, $docente->asignatura_id]
        );

        if ($otra === null) {
            $this->markTestSkipped('Este docente sólo tiene una asignatura: no hay dos hojas que separar.');
        }

        $token = $this->tokenDe($docente->username);

        $ruta = $this->bajar($token,
            "/api/planilla-offline/libro/{$docente->periodo_id}?asignaturas={$docente->asignatura_id},{$otra->id}");

        $caso = $this->casoDesde($ruta, $token, $docente, 1);

        $libro = IOFactory::load($ruta);
        $otras = array_values(array_filter(
            $libro->getSheetNames(),
            static fn ($n) => $n !== LibroDeNotas::PORTADA && $n !== LibroDeNotas::METADATOS && $n !== $caso['hoja']
        ));
        $libro->disconnectWorksheets();

        if ($otras === []) {
            $this->markTestSkipped('El libro salió con una sola hoja de asignatura.');
        }

        $caso['otra_hoja'] = $otras[0];

        return $caso;
    }

    /**
     * Arma el caso: qué hoja, qué fila, qué columna y qué alumno.
     *
     * @return array<string, mixed>
     */
    private function casoDesde(string $ruta, string $token, object $docente, int $cuantasFilas): array
    {
        $mapa = $this->mapaDeLaHoja($ruta, $docente->asignatura_id);

        $this->assertNotEmpty($mapa['columnas'], 'La hoja bajó sin columnas de indicador: no hay nada que probar.');
        $this->assertNotEmpty($mapa['filas'], 'La hoja bajó sin alumnos.');

        $filas = array_map('intval', array_keys($mapa['filas']));
        $alumnos = array_map('intval', array_values($mapa['filas']));

        if (count($filas) < $cuantasFilas) {
            $this->markTestSkipped('El grupo tiene menos de '.$cuantasFilas.' alumnos.');
        }

        $columna = (string) array_key_first($mapa['columnas']);

        return [
            'docente' => $docente,
            'token' => $token,
            'ruta' => $ruta,
            'mapa' => $mapa,
            'hoja' => $mapa['hoja'],
            'profesor_id' => (int) $docente->profesor_id,
            'asignatura_id' => (int) $docente->asignatura_id,
            'periodo_id' => (int) $docente->periodo_id,
            'year_id' => (int) $docente->year_id,
            'columna' => $columna,
            'subunidad_id' => (int) $mapa['columnas'][$columna],
            'fila' => $filas[0],
            'alumno_id' => $alumnos[0],
            'filas' => array_slice($filas, 0, $cuantasFilas),
            'alumnos' => array_slice($alumnos, 0, $cuantasFilas),
        ];
    }

    private function docenteConPlanilla(int $cuantosAlumnos, int $cuantasAsignaturas = 1): object
    {
        $fila = DB::selectOne(
            'SELECT p.id AS profesor_id, u.username, per.id AS periodo_id, per.year_id,
                    a.id AS asignatura_id, a.grupo_id, COUNT(DISTINCT s.id) AS indicadores
               FROM profesores p
               INNER JOIN users u ON u.id = p.user_id AND u.deleted_at IS NULL AND u.is_active = 1
                                 AND u.tipo = "Profesor"
               INNER JOIN asignaturas a ON a.profesor_id = p.id AND a.deleted_at IS NULL
               INNER JOIN grupos g ON g.id = a.grupo_id AND g.deleted_at IS NULL
               INNER JOIN years y ON y.id = g.year_id AND y.actual = 1 AND y.deleted_at IS NULL
               INNER JOIN periodos per ON per.year_id = y.id AND per.actual = 1 AND per.deleted_at IS NULL
                                      AND per.profes_pueden_editar_notas = 1
               INNER JOIN unidades un ON un.asignatura_id = a.id AND un.periodo_id = per.id
                                     AND un.deleted_at IS NULL AND un.alumno_id IS NULL
               INNER JOIN subunidades s ON s.unidad_id = un.id AND s.deleted_at IS NULL
              WHERE p.deleted_at IS NULL
                AND (SELECT COUNT(*) FROM matriculas m
                      WHERE m.grupo_id = a.grupo_id AND m.deleted_at IS NULL
                        AND m.estado IN ("MATR","ASIS","PREM")) >= ?
                AND (SELECT COUNT(DISTINCT a2.id)
                       FROM asignaturas a2
                       INNER JOIN grupos g2 ON g2.id = a2.grupo_id AND g2.year_id = y.id
                                           AND g2.deleted_at IS NULL
                      WHERE a2.profesor_id = p.id AND a2.deleted_at IS NULL) >= ?
              GROUP BY p.id, u.username, per.id, per.year_id, a.id, a.grupo_id
              ORDER BY indicadores DESC, a.id
              LIMIT 1',
            [$cuantosAlumnos, $cuantasAsignaturas]
        );

        if ($fila === null && $cuantasAsignaturas > 1) {
            // Se dice por qué no se comprobó, en vez de pasar en silencio: un test que
            // se salta sin motivo escrito es un test que nadie vuelve a mirar.
            $this->markTestSkipped('El seed no tiene ningún docente con '.$cuantasAsignaturas
                .' asignaturas calificables en el periodo actual.');
        }

        $this->assertNotNull($fila,
            'El seed no tiene ningún docente con planilla en el periodo actual abierto del año actual. '
            .'Sin eso estos tests pasarían sobre respuestas vacías.');

        return $fila;
    }

    private function otroDocenteConCuenta(int $distintoDe): ?object
    {
        return DB::selectOne(
            'SELECT u.username FROM profesores p
              INNER JOIN users u ON u.id = p.user_id AND u.deleted_at IS NULL AND u.is_active = 1
                                AND u.tipo = "Profesor"
             WHERE p.deleted_at IS NULL AND p.id <> ?
             ORDER BY p.id LIMIT 1',
            [$distintoDe]
        );
    }

    /** Baja un `.xlsx` de la fase 1 a un temporal propio del test. */
    private function bajar(string $token, string $uri): string
    {
        $r = $this->withToken($token)->get($uri);

        $r->assertStatus(200);

        $destino = tempnam(sys_get_temp_dir(), 'planilla-fase2-').'.xlsx';
        $this->temporales[] = $destino;

        copy($this->archivoDescargado($r), $destino);

        return $destino;
    }

    /**
     * El mapa de `_myvc` de la hoja de una asignatura.
     *
     * Se lee **del propio fichero** y no se reconstruye: es lo único que la fase 2
     * tiene delante, así que si lo escrito no basta, tiene que salir rojo aquí.
     *
     * @return array<string, mixed>
     */
    private function mapaDeLaHoja(string $ruta, int $asignaturaId): array
    {
        foreach ($this->metadatosDe($ruta)['mapas'] as $mapa) {
            if ((int) $mapa['asignatura_id'] === $asignaturaId) {
                return $mapa;
            }
        }

        $this->fail('El libro no trae el mapa de la asignatura '.$asignaturaId.'.');
    }

    /**
     * Lee la hoja `_myvc` entera: cabecera, firma y mapas con su espejo, en orden.
     *
     * @return array{cabecera: array<string,mixed>, firma: string, mapas: list<array<string,mixed>>}
     */
    private function metadatosDe(string $ruta): array
    {
        $libro = IOFactory::load($ruta);
        $meta = $libro->getSheetByName(LibroDeNotas::METADATOS);

        $this->assertNotNull($meta, 'El libro bajó sin la hoja oculta.');

        $cabecera = json_decode((string) $meta->getCell('B2')->getValue(), true);
        $firma = (string) $meta->getCell('B3')->getValue();

        $porNombre = [];
        $orden = [];

        foreach ($meta->getRowIterator() as $fila) {
            $f = $fila->getRowIndex();
            $clave = (string) $meta->getCell('A'.$f)->getValue();

            if ($clave === 'hoja') {
                $nombre = (string) $meta->getCell('B'.$f)->getValue();
                $porNombre[$nombre] = json_decode((string) $meta->getCell('C'.$f)->getValue(), true);
                $porNombre[$nombre]['espejo'] = [];
                $orden[] = $nombre;
            } elseif ($clave === 'espejo') {
                $nombre = (string) $meta->getCell('B'.$f)->getValue();
                $alumno = (string) $meta->getCell('C'.$f)->getValue();
                $porNombre[$nombre]['espejo'][$alumno] =
                    json_decode((string) $meta->getCell('D'.$f)->getValue(), true);
            }
        }

        $libro->disconnectWorksheets();

        return [
            'cabecera' => $cabecera,
            'firma' => $firma,
            'mapas' => array_map(static fn ($n) => $porNombre[$n], $orden),
        ];
    }

    /**
     * Escribe celdas en una hoja del libro, como haría el docente, y lo guarda.
     *
     * @param  list<array{0:int,1:string,2:mixed}>  $celdas  fila, columna, valor
     */
    private function escribiendo(string $ruta, string $hoja, array $celdas): string
    {
        $libro = IOFactory::load($ruta);
        $pestana = $libro->getSheetByName($hoja);

        $this->assertNotNull($pestana, "El libro no tiene la hoja «{$hoja}».");

        foreach ($celdas as [$fila, $columna, $valor]) {
            if ($valor === null) {
                // Vaciar de verdad, no escribir cadena vacía: lo que se está probando
                // es la casilla en blanco.
                $pestana->getCell($columna.$fila)->setValue(null);

                continue;
            }

            if (is_string($valor)) {
                $pestana->setCellValueExplicit($columna.$fila, $valor, DataType::TYPE_STRING);

                continue;
            }

            $pestana->setCellValue($columna.$fila, $valor);
        }

        return $this->guardar($libro);
    }

    /**
     * Reescribe la hoja `_myvc` con la cabecera y los mapas que se le den.
     *
     * Es **la máquina de fabricar casos** de este test, y hace falta porque tres de
     * los que hay que comprobar no se pueden descargar: un libro con dos periodos
     * dentro (F3), uno de otro colegio (F1) y uno con el espejo tocado (peldaño 2).
     *
     * `$firmar` decide si el libro sale legítimo o con la firma rota, que es
     * exactamente la diferencia entre el peldaño 1 y el 2.
     *
     * `$formato` fabrica **un libro de una versión de formato distinta de la de
     * hoy**, y hace falta desde la fase 4: el único caso que hay que poder probar de
     * verdad es un libro bajado antes de que `_myvc` guardara el espejo de la
     * asistencia. Se escribe en `B1` **y** se firma con ese número, que es
     * exactamente lo que hizo el despliegue anterior.
     *
     * @param  ?callable(array<string,mixed>): array<string,mixed>  $tocarCabecera
     * @param  ?callable(list<array<string,mixed>>): list<array<string,mixed>>  $tocarMapas
     */
    private function reescribirMetadatos(string $ruta, ?callable $tocarCabecera, ?callable $tocarMapas,
        bool $firmar, ?int $formato = null): string
    {
        $metadatos = $this->metadatosDe($ruta);

        $cabecera = $tocarCabecera === null ? $metadatos['cabecera'] : $tocarCabecera($metadatos['cabecera']);
        $mapas = $tocarMapas === null ? $metadatos['mapas'] : $tocarMapas($metadatos['mapas']);

        $firma = $firmar
            ? FirmaDelLibro::firmar(['libro' => $cabecera, 'hojas' => $mapas], $formato)
            : $metadatos['firma'];

        $libro = IOFactory::load($ruta);
        $libro->removeSheetByIndex($libro->getIndex($libro->getSheetByName(LibroDeNotas::METADATOS)));

        $meta = $libro->createSheet();
        $meta->setTitle(LibroDeNotas::METADATOS);

        $meta->setCellValue('A1', 'myvc');
        $meta->setCellValue('B1', $formato ?? FirmaDelLibro::FORMATO);

        $meta->setCellValue('A2', 'libro');
        $this->texto($meta, 'B2', (string) json_encode($cabecera, JSON_UNESCAPED_UNICODE));

        $meta->setCellValue('A3', 'firma');
        $this->texto($meta, 'B3', $firma);

        $fila = 5;

        foreach ($mapas as $mapa) {
            $sinEspejo = $mapa;
            unset($sinEspejo['espejo']);

            $meta->setCellValue('A'.$fila, 'hoja');
            $this->texto($meta, 'B'.$fila, (string) $mapa['hoja']);
            $this->texto($meta, 'C'.$fila, (string) json_encode($sinEspejo, JSON_UNESCAPED_UNICODE));
            $fila++;
        }

        foreach ($mapas as $mapa) {
            foreach ($mapa['espejo'] as $alumnoId => $notas) {
                $meta->setCellValue('A'.$fila, 'espejo');
                $this->texto($meta, 'B'.$fila, (string) $mapa['hoja']);
                $this->texto($meta, 'C'.$fila, (string) $alumnoId);
                $this->texto($meta, 'D'.$fila, (string) json_encode($notas, JSON_UNESCAPED_UNICODE));
                $fila++;
            }
        }

        $meta->setSheetState(Worksheet::SHEETSTATE_HIDDEN);

        return $this->guardar($libro);
    }

    private function texto(Worksheet $hoja, string $celda, string $valor): void
    {
        $hoja->setCellValueExplicit($celda, $valor, DataType::TYPE_STRING);
    }

    /**
     * El libro de la fase 1 **sin la hoja `_myvc`**, con las tres marcas que mira
     * {@see LaPlanillaQueSeSube::sinHojaOculta} puestas o quitadas a voluntad.
     *
     * Se parte de un libro descargado de verdad —y no de uno fabricado a mano— por
     * lo mismo que el resto de este test: las tres marcas son **cadenas concretas**
     * («← Volver a la portada», «ID», el nombre de la portada) y un libro inventado
     * las probaría contra sí mismo. Quitar `_myvc` es exactamente lo que separa los
     * peldaños 1 y 2 de los otros tres.
     *
     * `$id` es `null` para vaciar la celda del rótulo y una cadena para escribirla,
     * que es lo que hace falta para probar el `strcasecmp` + `trim` del método.
     *
     * @param  ?callable(Spreadsheet): void  $tocar  para añadir pestañas antes de guardar
     */
    private function libroSinLaHojaOculta(string $ruta, bool $portada, bool $enlace, ?string $id,
        ?callable $tocar = null): string
    {
        $libro = IOFactory::load($ruta);
        $libro->removeSheetByIndex($libro->getIndex($libro->getSheetByName(LibroDeNotas::METADATOS)));

        if (! $portada) {
            $libro->removeSheetByIndex($libro->getIndex($libro->getSheetByName(LibroDeNotas::PORTADA)));
        }

        // **Todas las hojas y no la de la asignatura del caso**: el método recorre
        // las que haya, así que una marca que se quedara puesta por descuido en otra
        // pestaña cambiaría el peldaño y el test pasaría midiendo otra cosa.
        foreach ($libro->getSheetNames() as $nombre) {
            if ($nombre === LibroDeNotas::PORTADA) {
                continue;
            }

            $hoja = $libro->getSheetByName($nombre);

            if (! $enlace) {
                // Vaciar de verdad, no escribir cadena vacía: se está probando la
                // celda en blanco.
                $hoja->getCell('A1')->setValue(null);
            }

            $celda = 'C'.LaPlanillaQueSeSube::FILA_CABECERA;

            if ($id === null) {
                $hoja->getCell($celda)->setValue(null);
            } else {
                $this->texto($hoja, $celda, $id);
            }
        }

        if ($tocar !== null) {
            $tocar($libro);
        }

        // La activa puede haberse ido con la portada, y el escritor no guarda un
        // libro cuya hoja activa no existe.
        $libro->setActiveSheetIndex(0);

        return $this->guardar($libro);
    }

    private function guardar(Spreadsheet $libro): string
    {
        $destino = tempnam(sys_get_temp_dir(), 'planilla-fase2-').'.xlsx';
        $this->temporales[] = $destino;

        (new Xlsx($libro))->save($destino);
        $libro->disconnectWorksheets();

        return $destino;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Los ayudantes de la D4 y del acta
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Alguien de coordinación: **personal llano con `can_edit_plantilla_notas`**.
     *
     * Llano y no superusuario a propósito. Con la columna puesta, todo lo que estos
     * tests demuestran es «el superusuario puede», que es menos y además tapa el
     * caso real: la coordinadora del colegio no es superusuaria.
     */
    private function unCoordinador(): object
    {
        $usuario = $this->usuarioLlanoDelPersonal();

        $this->assertSame(0, (int) $usuario->is_superuser,
            'El sujeto NO puede ser superusuario: con la columna puesta el permiso no diría nada.');

        $this->darPermisoDeLaPlantilla((int) $usuario->id);

        return $usuario;
    }

    /**
     * Le da `can_edit_plantilla_notas` **por la vía real**: permiso → rol → usuario.
     *
     * Lo monta el test y no el seed porque `database/dumps/test-seed.sql` hace
     * `TRUNCATE` de `permissions`, `permission_role`, `roles` y `role_user` antes de
     * insertar, y las migraciones corren **antes** del seed: lo que siembra la
     * migración del permiso no sobrevive a construir la base. Y va por rol y no
     * inventando una columna porque así es como el permiso llega a `perms` del
     * contexto, que es lo que `Autoriza` mira.
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

    private function nombreDelProfesor(int $profesorId): string
    {
        $fila = DB::selectOne('SELECT nombres, apellidos FROM profesores WHERE id = ?', [$profesorId]);

        return trim(($fila->nombres ?? '').' '.($fila->apellidos ?? ''));
    }

    private function acta(string $token, int $importacionId): TestResponse
    {
        // **Con `Accept: application/json`, y no es decoración.** Sin la cabecera, el
        // 403 vuelve como página HTML y `->json('message')` no encuentra el motivo:
        // el test falla enseñando la excepción cruda en vez de la comprobación, que
        // es media hora buscando un fallo que no existe. Y es además lo que manda el
        // front, así que sin ella se estaría probando otra respuesta.
        return $this->withToken($token)
            ->get('/api/planilla-offline/acta/'.$importacionId, ['Accept' => 'application/json']);
    }

    /**
     * Baja el acta y la devuelve como rejilla.
     *
     * **Se lee el `.xlsx` de verdad y no una respuesta JSON**, y es lo que hace que
     * este test valga: el acta es un archivo, y lo que hay que comprobar es lo que
     * dice el archivo que la gente abre.
     *
     * @return list<array<int, mixed>>
     */
    private function leerElActa(string $token, int $importacionId): array
    {
        $r = $this->acta($token, $importacionId)->assertStatus(200);

        $destino = tempnam(sys_get_temp_dir(), 'acta-').'.xlsx';
        $this->temporales[] = $destino;

        copy($this->archivoDescargado($r), $destino);

        $libro = IOFactory::load($destino);
        $hoja = $libro->getSheetByName('Acta');

        $this->assertNotNull($hoja, 'El acta tiene que venir en una hoja que se llame «Acta».');

        // `array_values` no cambia el contenido: `toArray` ya numera de 0 en adelante con
        // `$returnCellRef` en falso. Es lo que le dice a Larastan que esto es una `list`.
        $rejilla = array_values($hoja->toArray(null, true, false, false));

        $libro->disconnectWorksheets();

        return $rejilla;
    }

    /**
     * El valor de la columna B del renglón cuya columna A es `$etiqueta`.
     *
     * Devuelve **cadena** aunque la casilla lleve un número: lo que se compara es lo
     * que el acta imprime, y un `assertSame(1, '1')` fallaría por el tipo diciendo
     * que el acta está mal cuando no lo está.
     *
     * @param  list<array<int, mixed>>  $acta
     */
    private function delActa(array $acta, string $etiqueta): string
    {
        foreach ($acta as $fila) {
            if (trim((string) ($fila[0] ?? '')) === $etiqueta) {
                return (string) ($fila[1] ?? '');
            }
        }

        $this->fail('El acta no trae el renglón «'.$etiqueta.'».');
    }

    /** @param array<string, mixed> $respuestas */
    private function ensayo(string $token, string $ruta, array $respuestas = []): TestResponse
    {
        return $this->subir('/api/planilla-offline/ensayo', $token, $ruta, $respuestas);
    }

    /** @param array<string, mixed> $respuestas */
    private function importar(string $token, string $ruta, array $respuestas = []): TestResponse
    {
        return $this->subir('/api/planilla-offline/importar', $token, $ruta, $respuestas);
    }

    /** @param array<string, mixed> $respuestas */
    private function subir(string $uri, string $token, string $ruta, array $respuestas): TestResponse
    {
        $cuerpo = ['file' => new UploadedFile($ruta, 'planilla.xlsx', null, null, true)];

        if ($respuestas !== []) {
            // **Como cadena y no como array**: viaja en el mismo `FormData` que el
            // fichero, y ahí no hay tipos. Mandarlo como array probaría un camino que
            // el front no usa.
            $cuerpo['respuestas'] = json_encode($respuestas);
        }

        return $this->post($uri, $cuerpo, ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json']);
    }

    private function notaDe(int $alumnoId, int $subunidadId): ?int
    {
        $fila = DB::selectOne(
            'SELECT nota FROM notas WHERE alumno_id = ? AND subunidad_id = ? AND deleted_at IS NULL',
            [$alumnoId, $subunidadId]
        );

        return $fila === null || $fila->nota === null ? null : (int) $fila->nota;
    }

    /**
     * Un valor distinto del que hay, y **dentro de la escala**: lo que se está
     * probando es la D3, no la F5.
     *
     * @param  array<string, mixed>  $caso
     * @param  list<int>  $evitando
     */
    private function otroValorPara(array $caso, int $fila, string $columna, array $evitando = []): int
    {
        $alumnoId = (int) $caso['mapa']['filas'][(string) $fila];
        $subunidadId = (int) $caso['mapa']['columnas'][$columna];

        $ahora = $this->notaDe($alumnoId, $subunidadId);
        $espejo = $caso['mapa']['espejo'][(string) $alumnoId][(string) $subunidadId] ?? null;

        $maximo = EscalaDeNotas::maximo((int) $caso['year_id']) ?? 100;
        $minimo = EscalaDeNotas::minimo((int) $caso['year_id']) ?? 0;

        for ($v = $minimo; $v <= $maximo; $v++) {
            if ($v !== $ahora && $v !== $espejo && ! in_array($v, $evitando, true)) {
                return $v;
            }
        }

        $this->fail('No hay ningún valor libre dentro de la escala.');
    }

    /**
     * Otra columna de la misma hoja, con su indicador. `null` si sólo hay una.
     *
     * @param  array<string, mixed>  $caso
     * @return ?array{columna:string, subunidad_id:int}
     */
    private function otraColumnaDe(array $caso): ?array
    {
        foreach ($caso['mapa']['columnas'] as $letra => $subunidadId) {
            if ((string) $letra !== $caso['columna']) {
                return ['columna' => (string) $letra, 'subunidad_id' => (int) $subunidadId];
            }
        }

        return null;
    }

    /**
     * Las filas y la última escritura de las cuatro tablas que el ensayo no puede
     * tocar.
     *
     * Es la misma huella que usa `PlanillaOfflineTest` para la descarga, y por lo
     * mismo: **un 200 no distingue un ensayo limpio de uno que acaba de sembrar
     * cuatrocientas filas** en un colegio de producción.
     *
     * @return array<string, mixed>
     */
    private function huellaDeLaBase(): array
    {
        $huella = [];

        // `MAX(id)` y no `MAX(updated_at)` a propósito: **no todas estas tablas
        // tienen `updated_at`** —`bitacoras` y `auditoria` sólo llevan la hora de
        // creación— y una columna que no existe convertiría el test en un error de
        // SQL en vez de en una comprobación. Con las filas y el último id se ve
        // igual de bien cualquier `INSERT`, que es lo que el ensayo no puede hacer.
        foreach (['notas', 'notas_finales', 'subunidades', 'unidades', 'bitacoras', 'auditoria'] as $tabla) {
            $fila = DB::selectOne("SELECT COUNT(*) AS filas, MAX(id) AS ultimo FROM {$tabla}");

            $huella[$tabla] = ['filas' => (int) $fila->filas, 'ultimo' => $fila->ultimo ?? null];
        }

        // Y las notas, además, por su valor: un `UPDATE` no mueve ni el recuento ni
        // el último id, y es justo lo que más daño haría aquí.
        $huella['suma_de_las_notas'] = DB::selectOne(
            'SELECT COALESCE(SUM(nota), 0) AS suma, COUNT(nota) AS puestas FROM notas WHERE deleted_at IS NULL'
        )->suma;

        return $huella;
    }
}
