<?php

namespace Tests\Contrato;

use App\Exports\LibroDeNotas;
use App\Support\EscalaDeNotas;
use App\Support\FirmaDelLibro;
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

    #[Test]
    public function la_planilla_de_otro_docente_da_403_al_importar(): void
    {
        $caso = $this->unaPlanilla();
        $otro = $this->otroDocenteConCuenta($caso['profesor_id']);

        if ($otro === null) {
            $this->markTestSkipped('El seed no tiene un segundo docente con cuenta.');
        }

        // La D4 —coordinación sube por otro— es la fase 5 y viene con su acta de lo
        // que entró. Adelantarla aquí sería escribir notas en nombre de alguien sin
        // ese acta.
        $this->importar($this->tokenDe($otro->username), $caso['ruta'])->assertStatus(403);
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

    #[Test]
    public function las_ausencias_se_cuentan_y_se_declaran_no_aplicadas(): void
    {
        $caso = $this->unaPlanilla();

        $aus = (string) $caso['mapa']['columna_aus'];

        $ruta = $this->escribiendo($caso['ruta'], $caso['hoja'], [[$caso['fila'], $aus, 7]]);

        $antes = DB::selectOne('SELECT COUNT(*) AS n FROM ausencias WHERE deleted_at IS NULL')->n;

        $r = $this->ensayo($caso['token'], $ruta)->assertStatus(200);

        $familia = $r->json('familias.ausencias');

        $this->assertCount(1, $familia);
        $this->assertSame($caso['hoja'], $familia[0]['hoja']);
        $this->assertNotEmpty($familia[0]['descripcion'],
            'El libro trae esas columnas rellenas y escribibles, así que callarlo sería prometer que entraron. '
            .'La pantalla lo pinta como aviso con este texto, así que tiene que poder leerse.');

        $r = $this->importar($caso['token'], $ruta)->assertStatus(200);

        $this->assertSame($antes, DB::selectOne('SELECT COUNT(*) AS n FROM ausencias WHERE deleted_at IS NULL')->n,
            'Importar las ausencias es la fase 4: subir un conteo crearía filas fechadas hoy.');

        // **Y se dice en voz alta después de escribir.** «Entraron 312 notas» no le
        // dice nada a nadie si además se cambiaron seis ausencias y ninguna entró.
        $this->assertNotEmpty(
            array_filter($r->json('avisos'), static fn ($a) => str_contains($a, 'ausencias')),
            'Lo que no entró tiene que salir en los avisos de la importación, no sólo en el ensayo.'
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Los ayudantes
    // ─────────────────────────────────────────────────────────────────────────

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
     * @param  ?callable(array<string,mixed>): array<string,mixed>  $tocarCabecera
     * @param  ?callable(list<array<string,mixed>>): list<array<string,mixed>>  $tocarMapas
     */
    private function reescribirMetadatos(string $ruta, ?callable $tocarCabecera, ?callable $tocarMapas, bool $firmar): string
    {
        $metadatos = $this->metadatosDe($ruta);

        $cabecera = $tocarCabecera === null ? $metadatos['cabecera'] : $tocarCabecera($metadatos['cabecera']);
        $mapas = $tocarMapas === null ? $metadatos['mapas'] : $tocarMapas($metadatos['mapas']);

        $firma = $firmar
            ? FirmaDelLibro::firmar(['libro' => $cabecera, 'hojas' => $mapas])
            : $metadatos['firma'];

        $libro = IOFactory::load($ruta);
        $libro->removeSheetByIndex($libro->getIndex($libro->getSheetByName(LibroDeNotas::METADATOS)));

        $meta = $libro->createSheet();
        $meta->setTitle(LibroDeNotas::METADATOS);

        $meta->setCellValue('A1', 'myvc');
        $meta->setCellValue('B1', FirmaDelLibro::FORMATO);

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

    private function guardar(Spreadsheet $libro): string
    {
        $destino = tempnam(sys_get_temp_dir(), 'planilla-fase2-').'.xlsx';
        $this->temporales[] = $destino;

        (new Xlsx($libro))->save($destino);
        $libro->disconnectWorksheets();

        return $destino;
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
