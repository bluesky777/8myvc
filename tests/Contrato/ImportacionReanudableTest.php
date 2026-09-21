<?php

namespace Tests\Contrato;

use App\Services\PuntoDeControlDeImportacion;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as EscritorXlsx;

/**
 * La importación de alumnos, cortada por la mitad y retomada.
 *
 * Es el §1 de docs/migracion/09-pendientes.md. `max_execution_time` está en
 * 300 s en la cuenta de cPanel **porque las importaciones tardaban mucho**, y
 * para poder bajarlo la importación tiene que dejar de ser una petición que o
 * entra entera o se pierde.
 *
 * Lo que se comprueba aquí no es que el importador funcione —de eso se encarga
 * el viaje de ida y vuelta de ExcelTest— sino las dos promesas nuevas:
 *
 * 1. **Volver a subir el mismo archivo continúa**, no repite lo hecho.
 * 2. **Y aunque repitiera, no duplicaría**, porque el documento del alumno es
 *    la clave natural y se mira antes de crear.
 *
 * Son dos porque cada una tapa un agujero distinto: la primera es rápida y la
 * segunda es la que salva el caso en el que la primera no aplica —la secretaría
 * exporta la hoja otra vez en vez de volver a subir la misma—.
 *
 * Los tests no matan el proceso a media importación, que no se puede hacer
 * desde PHPUnit: escriben a mano el punto de control que habría dejado un corte
 * y comprueban qué hace el importador con él.
 *
 * ## ⚠️ Y esa última frase decía algo FALSO, que es lo que dejó pasar el fallo
 *
 * Decía: *«Es la misma fila, con los mismos valores, que la que deja un `kill`»*.
 * **No lo era.** Hasta el 20 sep 2026, `config/excel.php` dejaba el manejador de
 * transacciones de maatwebsite en `'db'`, que envuelve la importación **entera**
 * (`Reader.php:111`); `anotar()` escribía dentro, así que un corte real hacía
 * ROLLBACK de las filas **y del avance a la vez**. Medido por `myvc-front-a6`
 * cortando en la fila 150 de 421: `filas` → **0**, `avance` → **`{}`**.
 *
 * O sea que la fila que estos tests escribían a mano era la de un corte que **no
 * podía ocurrir**, y por eso los dos verdes de aquí no dijeron nada: *un test que
 * construye su propio punto de partida nunca comprueba que ese punto de partida
 * exista*. El que sí lo comprueba es
 * `test_la_importacion_no_va_dentro_de_una_transaccion_global`.
 */
class ImportacionReanudableTest extends CasoDeContrato
{
    /**
     * Una importación entera deja escrito cuánto tardó y cuántas filas eran.
     *
     * Ese número es el que decide si `max_execution_time` puede bajar de 300 s,
     * y hasta hoy no existía: «tardaba mucho» era todo lo que se sabía. Por eso
     * el test mira `inicio`, `fin` y `filas` y no solo el estado.
     */
    /**
     * **Sin tiempo NUNCA se para en seco: cada petición escribe al menos un lote.**
     *
     * Es el borde que casi se queda sin tapar. El presupuesto se cuenta desde
     * antes de leer el libro —a propósito: leer también gasta—, así que un libro
     * grande en un servidor lento puede agotarlo **antes del primer lote**. Sin
     * esta garantía la petición contestaría «hice 0, faltan N», la siguiente
     * haría lo mismo, y el importador diría «falta» **para siempre**.
     *
     * Lo cazó `myvc-front-a6` al cablear el bucle en la pantalla: su guarda de
     * «para si una vuelta no escribe ni una fila» **era la única red**. Un
     * cliente no puede ser lo que impide que el servidor entre en bucle.
     *
     * Con el presupuesto en cero —el peor caso posible— tiene que escribir un
     * lote igual.
     */
    public function test_con_el_presupuesto_agotado_escribe_un_lote_igual(): void
    {
        [$token, $year] = $this->credenciales();
        $archivo = $this->exportacionDeAlumnos($token);

        config(['importacion.segundos_por_peticion' => 0, 'importacion.filas_por_lote' => 5]);

        $r = $this->importar($archivo, $token, $year)->assertStatus(200);

        $this->assertFalse($r->json('terminado'), 'Con presupuesto cero no puede haber terminado.');
        $this->assertSame(5, $r->json('filas_hechas'),
            'Escribió '.$r->json('filas_hechas')." filas con el presupuesto en cero.\n"
            .'Tiene que escribir exactamente un lote: con 0 el importador nunca avanza y el '
            ."cliente\nse queda pidiendo lo mismo para siempre; con más, el presupuesto no se está mirando.");
    }

    /**
     * Y la prueba de que eso **termina**: a base de lotes de uno en uno, llega.
     *
     * Es la otra mitad del caso de arriba. Que escriba un lote no sirve de nada
     * si el avance no se acumula entre peticiones — y eso es justo lo que el
     * ROLLBACK global rompía esta mañana.
     */
    public function test_a_lotes_minimos_la_importacion_acaba_llegando(): void
    {
        [$token, $year] = $this->credenciales();
        $archivo = $this->exportacionDeAlumnos($token);

        config(['importacion.segundos_por_peticion' => 0, 'importacion.filas_por_lote' => 10]);

        $vueltas = 0;
        $anterior = -1;

        do {
            $r = $this->importar($archivo, $token, $year)->assertStatus(200);
            $hechas = (int) $r->json('filas_hechas');

            $this->assertGreaterThan($anterior, $hechas,
                "La vuelta {$vueltas} no avanzó ni una fila: esto es el bucle infinito.");

            $anterior = $hechas;
            $vueltas++;
        } while (! $r->json('terminado') && $vueltas < 50);

        $this->assertTrue($r->json('terminado'),
            "No terminó en {$vueltas} vueltas de diez filas.");
        $this->assertSame(0, $r->json('faltan'));
        $this->assertSame(PuntoDeControlDeImportacion::COMPLETADA, $this->ultimaImportacion()->estado);
    }

    /**
     * **Dos peticiones a la vez con el mismo archivo: la segunda no entra.**
     *
     * El troceado convirtió «reenviar» en el funcionamiento normal, así que dos
     * pestañas abiertas o un doble clic dejaron de ser un caso raro. Sin cerrojo
     * las dos reanudan la misma fila: los datos sobreviven —idempotencia por
     * documento— pero `filas` cuenta de más y el progreso que ve la pantalla
     * miente.
     *
     * Aquí el cerrojo se toma a mano para simular a la otra petición, porque dos
     * peticiones de verdad en paralelo no se pueden montar desde PHPUnit. **Lo
     * que se comprueba es lo mismo que vería la segunda ventana: un 409, no un
     * 200 que duplica trabajo.**
     */
    public function test_dos_a_la_vez_con_el_mismo_archivo_no_entran_las_dos(): void
    {
        [$token, $year] = $this->credenciales();
        $archivo = $this->exportacionDeAlumnos($token);
        $huella = hash_file('sha256', $archivo);

        // **El cerrojo se toma desde OTRA CONEXIÓN, y no es un detalle del test:
        // es la definición del mecanismo.** `GET_LOCK` es por sesión de MySQL y
        // **reentrante dentro de ella**, así que tomarlo desde la conexión del
        // test no bloquea a la petición —que usa esa misma— y el caso saldría
        // verde sin haber probado nada. La primera versión de esto lo hacía así y
        // devolvió 200: *un test que no puede fallar por el motivo que dice.*
        config(['database.connections.cerrojo_de_prueba' => config('database.connections.'.config('database.default'))]);
        $otra = DB::connection('cerrojo_de_prueba');

        $nombre = PuntoDeControlDeImportacion::cerrojo('alumnos', $huella, (int) $year);

        $this->assertSame(1, (int) $otra->selectOne('SELECT GET_LOCK(?, 0) AS t', [$nombre])->t,
            'La otra conexión no pudo tomar el cerrojo, así que este test no mide nada.');

        try {
            $this->importar($archivo, $token, $year)->assertStatus(409);
        } finally {
            $otra->selectOne('SELECT RELEASE_LOCK(?) AS s', [$nombre]);
            $otra->disconnect();
        }

        // Y soltado, la misma petición entra: el cerrojo cierra mientras dura, no
        // para siempre.
        $this->importar($archivo, $token, $year)->assertStatus(200);
    }

    /**
     * El cerrojo es **por archivo y por año**, no por colegio.
     *
     * Dos secretarías subiendo hojas distintas a la vez es trabajo normal, y un
     * cerrojo global las pondría en fila sin motivo. Se comprueba con el nombre,
     * que es donde vive esa decisión — y **cabe en 64 caracteres**, que es el
     * tope de `GET_LOCK`: pasarse no da error, trunca, y dos archivos pasarían a
     * compartir cerrojo sin que nadie lo notara.
     */
    public function test_el_cerrojo_es_por_archivo_y_cabe_en_el_tope(): void
    {
        $a = PuntoDeControlDeImportacion::cerrojo('alumnos', str_repeat('a', 64), 2026);
        $b = PuntoDeControlDeImportacion::cerrojo('alumnos', str_repeat('b', 64), 2026);
        $c = PuntoDeControlDeImportacion::cerrojo('alumnos', str_repeat('a', 64), 2025);

        $this->assertNotSame($a, $b, 'Dos archivos distintos comparten cerrojo.');
        $this->assertNotSame($a, $c, 'Dos años distintos comparten cerrojo.');

        foreach ([$a, $b, $c] as $nombre) {
            $this->assertLessThanOrEqual(64, strlen($nombre),
                'El nombre del cerrojo mide '.strlen($nombre)." y MySQL trunca a 64: dos archivos\n"
                .'distintos pasarían a ser el mismo cerrojo sin dar ningún error.');
        }
    }

    /**
     * **Sin tiempo, la importación PARA, lo dice, y no miente sobre lo hecho.**
     *
     * Es la pieza que Joseth pidió el 20 sep 2026 —*«lo más failover posible»*— y
     * la única forma de conseguirlo en esta casa: **no hay cola**
     * (`QUEUE_CONNECTION=sync`, cero `app/Jobs`) porque el hosting es cPanel
     * compartido, así que una importación grande no puede irse a segundo plano. O
     * cabe en la petición o se pierde. Lo que sí puede es caber en **varias**.
     *
     * Con el presupuesto en cero se agota antes del primer lote, que es el caso
     * extremo y el único que se puede reproducir sin depender del reloj.
     */
    public function test_sin_tiempo_para_y_lo_dice_sin_cerrar_la_importacion(): void
    {
        [$token, $year] = $this->credenciales();
        $archivo = $this->exportacionDeAlumnos($token);

        config(['importacion.segundos_por_peticion' => 0, 'importacion.filas_por_lote' => 25]);

        $r = $this->importar($archivo, $token, $year)->assertStatus(200);

        $this->assertFalse($r->json('terminado'),
            'Se quedó sin tiempo y dijo que había terminado: el front no volvería a llamar.');

        // **Escribe UN lote y para.** Este renglón decía `0` cuando se escribió,
        // y lo cambió a propósito la garantía de avance mínimo: sin ella la
        // petición no escribía nada y el cliente pedía lo mismo para siempre. Se
        // reescribe al comportamiento nuevo, que es el que se quiso.
        $this->assertSame(25, $r->json('filas_hechas'));

        $this->assertGreaterThan(0, $r->json('filas_totales'),
            'No contó las filas del archivo, así que no puede decir cuántas faltan.');
        $this->assertSame($r->json('filas_totales') - 25, $r->json('faltan'));

        $fila = $this->ultimaImportacion();

        $this->assertNotSame(PuntoDeControlDeImportacion::COMPLETADA, $fila->estado,
            'Cerró la importación sin haberla hecho: la siguiente subida creería que no hay nada pendiente.');
    }

    /**
     * Y la otra mitad, que es la que de verdad importa: **volver a llamar
     * termina el trabajo**, sin repetir lo hecho y sin duplicar a nadie.
     *
     * Este caso es el flujo entero del contrato nuevo, y por eso no se conforma
     * con el 200: cuenta los alumnos antes y después de la segunda llamada, que
     * es lo único que distingue «reanudó» de «volvió a empezar».
     */
    public function test_volver_a_llamar_termina_lo_que_quedo_a_medias(): void
    {
        [$token, $year] = $this->credenciales();
        $archivo = $this->exportacionDeAlumnos($token);

        // Primera pasada: sin tiempo, escribe un lote y para.
        config(['importacion.segundos_por_peticion' => 0, 'importacion.filas_por_lote' => 10]);
        $primera = $this->importar($archivo, $token, $year)->assertStatus(200);

        $this->assertFalse($primera->json('terminado'));
        $this->assertSame(10, $primera->json('filas_hechas'));

        $importacion = (int) $this->ultimaImportacion()->id;
        $alumnosAntes = $this->cuantosAlumnos();

        // Segunda: con tiempo de sobra, termina.
        config(['importacion.segundos_por_peticion' => 120]);
        $r = $this->importar($archivo, $token, $year)->assertStatus(200);

        $this->assertTrue($r->json('terminado'), 'Con tiempo de sobra no terminó.');
        $this->assertSame(0, $r->json('faltan'));
        $this->assertTrue($r->json('reanudada'),
            'Abrió una importación nueva en vez de continuar la que quedó a medias.');

        $fila = $this->ultimaImportacion();

        $this->assertSame($importacion, (int) $fila->id,
            'La segunda llamada creó otra fila de `importaciones` en vez de seguir la misma.');
        $this->assertSame(PuntoDeControlDeImportacion::COMPLETADA, $fila->estado);

        $this->assertSame($alumnosAntes, $this->cuantosAlumnos(),
            'Reanudar creó alumnos nuevos: el archivo es el export de los que ya están.');
    }

    /**
     * **La marca se escribe una vez por LOTE, no una por fila.**
     *
     * Era 1 de las 6,7 consultas por fila que se midieron el 20 sep 2026, o sea
     * el 15 % del coste del importador, y no hacía falta: dentro de la
     * transacción del lote, escribirla al final da la misma garantía —si el lote
     * cae, se van las filas y la marca juntas—.
     *
     * Se cuenta la consulta concreta y no el total, porque el total lo mueve
     * cualquier cosa y entonces el test se volvería un adivino.
     */
    public function test_la_marca_se_escribe_una_vez_por_lote(): void
    {
        [$token, $year] = $this->credenciales();
        $archivo = $this->exportacionDeAlumnos($token);

        config(['importacion.segundos_por_peticion' => 120, 'importacion.filas_por_lote' => 25]);

        $marcas = 0;
        DB::listen(function ($q) use (&$marcas) {
            if (str_contains($q->sql, 'UPDATE importaciones SET avance')) {
                $marcas++;
            }
        });

        $r = $this->importar($archivo, $token, $year)->assertStatus(200);

        $filas = (int) $r->json('filas_hechas');
        $esperadas = (int) ceil($filas / 25);

        $this->assertGreaterThan(0, $filas, 'No se aplicó ninguna fila: esto no mide nada.');
        $this->assertSame($esperadas, $marcas,
            "Se escribió la marca {$marcas} veces para {$filas} filas en lotes de 25.\n"
            .'Con una por fila ha vuelto el coste que este cambio quitó; con menos de las '
            .'esperadas, hay un lote que escribió filas y no dejó marca — y ése se repetiría al reanudar.');
    }

    public function test_la_importacion_no_va_dentro_de_una_transaccion_global(): void
    {
        [$token, $year] = $this->credenciales();
        $archivo = $this->exportacionDeAlumnos($token);

        // El test ya corre dentro de su propia transacción, así que lo que se mide
        // es la PROFUNDIDAD RELATIVA y no el número absoluto.
        $base = DB::transactionLevel();
        $maximo = $base;

        // `TransactionBeginning` se dispara **después** de incrementar el contador
        // (`ManagesTransactions.php:132`), así que dentro del oyente el nivel ya es
        // el nuevo.
        Event::listen(TransactionBeginning::class, function () use (&$maximo) {
            $maximo = max($maximo, DB::transactionLevel());
        });

        $this->importar($archivo, $token, $year)->assertStatus(200);

        $this->assertSame($base + 1, $maximo,
            "La importación abrió transacciones hasta el nivel {$maximo} sobre {$base}.\n"
            ."Con +1 sólo está la de CADA FILA (`ImportarController:177`), que es la que\n"
            ."hace que la fila y su marca entren juntas. Con +2 ha vuelto el manejador de\n"
            ."maatwebsite (`config/excel.php`, `'handler' => 'db'`), y entonces la de la fila\n"
            .'es un savepoint: al fallar, el ROLLBACK se lleva las filas Y el avance, y '
            ."reanudar\nno puede reanudar nada.");
    }

    /**
     * Y la otra mitad del mismo mecanismo: **hay una transacción por LOTE, y la
     * hay**.
     *
     * Sin ninguna, quitar el manejador global sí dejaría medio alumno en la base
     * —una fila son ocho escrituras—. O sea que estos dos casos no son el mismo
     * medido dos veces: uno exige que **no** haya una envolvente de fichero, y el
     * otro que **sí** haya la de dentro.
     *
     * > **Esta prueba se escribió por la mañana diciendo «una por FILA» y la puso
     * > en rojo el cambio de la tarde, que fue a lotes. Se reescribe al invariante
     * > nuevo, no se relaja**: sigue siendo un `assertSame` contra un número
     * > calculado, porque *de menos* significa que algún lote escribió filas sin
     * > dejar marca —y ésas se repetirían al reanudar—.
     */
    public function test_cada_lote_va_en_su_propia_transaccion(): void
    {
        [$token, $year] = $this->credenciales();
        $archivo = $this->exportacionDeAlumnos($token);

        config(['importacion.segundos_por_peticion' => 120, 'importacion.filas_por_lote' => 25]);

        $abiertas = 0;

        Event::listen(TransactionBeginning::class, function () use (&$abiertas) {
            $abiertas++;
        });

        $this->importar($archivo, $token, $year)->assertStatus(200);

        $filas = (int) $this->ultimaImportacion()->filas;
        $esperadas = (int) ceil($filas / 25);

        $this->assertGreaterThan(0, $filas, 'La importación no aplicó ninguna fila: esto no mide nada.');
        $this->assertSame($esperadas, $abiertas,
            'Se abrieron '.$abiertas." transacciones para {$filas} filas en lotes de 25.\n"
            .'De más, se perdió el agrupado; de menos, hay filas que entraron fuera de toda '
            ."transacción\ny pueden dejar medio alumno en la base.");
    }

    public function test_una_importacion_deja_su_rastro_medible(): void
    {
        [$token, $year] = $this->credenciales();

        $r = $this->importar($this->exportacionDeAlumnos($token), $token, $year);

        $r->assertStatus(200);

        // Esta línea decía `assertSame('Importados.', ...)` con el motivo «la
        // respuesta es el contrato con los cuatro clientes y no puede cambiar»,
        // y **frenó este cambio**, que es para lo que estaba. Se cambia con la
        // decisión encima, no regenerándola: el 20 sep 2026 se midió que de los
        // cuatro llamadores ninguno lee el cuerpo cuando la importación va bien
        // —y que `app2`, que lo pide como JSON, enseñaba «no se pudieron
        // importar» después de una importación buena, porque `'Importados.'` no
        // parsea—. El contrato de esta ruta lo fija ahora
        // `RespuestaDeLaImportacionTest`, que es donde vive el porqué entero.
        $r->assertJson(['ok' => true]);

        $fila = $this->ultimaImportacion();

        $this->assertSame(PuntoDeControlDeImportacion::COMPLETADA, $fila->estado);
        $this->assertSame('alumnos', $fila->tipo);
        $this->assertSame((int) $year, (int) $fila->year);
        $this->assertNull($fila->error);

        // No basta con que `inicio` y `fin` no sean null: una fecha que se
        // guarda mal en MySQL se guarda como '0000-00-00 00:00:00', que tampoco
        // lo es, y la resta que justifica la tabla daría un número absurdo sin
        // que nada fallara.
        $inicio = strtotime((string) $fila->inicio);
        $fin = strtotime((string) $fila->fin);

        $this->assertGreaterThan(0, $inicio, 'La marca de `inicio` no es una fecha.');
        $this->assertGreaterThanOrEqual($inicio, $fin, 'La importación terminó antes de empezar.');
        $this->assertLessThan(3600, abs(time() - $fin),
            'La marca de `fin` no es de hace un momento: las dos zonas horarias del proyecto se mezclaron en esta tabla.');

        $avance = json_decode((string) $fila->avance, true);

        $this->assertNotEmpty($avance, 'El avance vacío significa que no procesó ninguna hoja.');

        // `filas` y `avance` cuentan lo mismo desde dos sitios: la suma de las
        // últimas filas de cada hoja (base 0, de ahí el +1) tiene que dar el
        // total. Si se separan, el punto de control está mintiendo sobre algo.
        $this->assertSame(
            array_sum(array_map(fn ($ultima) => $ultima + 1, $avance)),
            (int) $fila->filas,
            'El total de filas no cuadra con el avance por hoja.'
        );
    }

    /**
     * Reanudar salta lo que ya estaba aplicado.
     *
     * Se prepara la fila que habría dejado un corte —la primera fila de la hoja
     * dada por hecha, el resto no— y se les pone a dos alumnos un nombre
     * imposible: uno detrás del punto de control y otro delante. Si la
     * importación respeta al primero y pisa al segundo, el corte se retomó
     * justo donde decía.
     *
     * El corte va DENTRO de una pestaña y no entre dos porque es el caso real:
     * el proceso no muere en el hueco entre hojas, muere en el alumno 340.
     */
    public function test_reanudar_no_vuelve_a_pasar_por_las_filas_hechas(): void
    {
        [$token, $year] = $this->credenciales();

        $archivo = $this->exportacionDeAlumnos($token);
        $hojas = $this->hojasDelLibro($archivo);

        $hoja = array_key_first($hojas);
        $ultima = $hojas[$hoja] - 1;   // índice de la última fila de datos, base 0

        $this->assertGreaterThanOrEqual(2, $hojas[$hoja],
            'Hace falta una hoja con al menos dos alumnos para poder cortarla por la mitad.');

        $hecha = $this->alumnoDeLaFila($archivo, $hoja, 0);
        $pendiente = $this->alumnoDeLaFila($archivo, $hoja, $ultima);

        DB::update('UPDATE alumnos SET nombres = ? WHERE id IN (?, ?)',
            ['NO ME TOQUES', $hecha, $pendiente]);

        $this->puntoDeControlAMedias($archivo, (int) $year, [$hoja => 0]);

        $this->importar($archivo, $token, $year)->assertStatus(200);

        $this->assertSame('NO ME TOQUES', $this->nombreDe($hecha),
            'La fila que el punto de control daba por hecha se volvió a procesar.');

        $this->assertNotSame('NO ME TOQUES', $this->nombreDe($pendiente),
            'La fila que quedaba pendiente no se procesó: reanudar se comió el resto de la hoja.');

        $fila = $this->ultimaImportacion();

        $this->assertSame(PuntoDeControlDeImportacion::COMPLETADA, $fila->estado);
        $this->assertSame($ultima, json_decode((string) $fila->avance, true)[$hoja],
            'El avance no llegó hasta el final de la hoja.');
    }

    /**
     * Una importación completada no bloquea volver a subir el mismo archivo.
     *
     * Es el uso corriente: la secretaría corrige cuatro celdas y sube la hoja
     * otra vez. Si reanudar se aplicara también a las completadas, esa segunda
     * subida no haría nada y nadie entendería por qué.
     */
    public function test_subir_otra_vez_un_archivo_ya_importado_lo_vuelve_a_aplicar(): void
    {
        [$token, $year] = $this->credenciales();

        $archivo = $this->exportacionDeAlumnos($token);

        $this->importar($archivo, $token, $year)->assertStatus(200);

        $primera = $this->ultimaImportacion();

        $alumno = $this->primerAlumnoDelGrupo(array_key_first($this->hojasDelLibro($archivo)), (int) $year);
        DB::update('UPDATE alumnos SET nombres = ? WHERE id = ?', ['NO ME TOQUES', $alumno]);

        $this->importar($archivo, $token, $year)->assertStatus(200);

        $segunda = $this->ultimaImportacion();

        $this->assertNotSame((int) $primera->id, (int) $segunda->id,
            'La segunda subida reanudó la primera en vez de empezar una importación nueva.');

        $this->assertNotSame('NO ME TOQUES', $this->nombreDe($alumno),
            'La segunda subida no aplicó nada: se dio por hecha con el punto de control de la primera.');
    }

    /**
     * Un alumno cuya fila viene sin `id` pero cuyo documento ya está en la base
     * se actualiza, no se duplica.
     *
     * Es la mitad que no depende del punto de control, y la que salva el caso
     * real que este trabajo venía a resolver: la importación se cortó, la
     * secretaría exportó la hoja de nuevo —huella distinta, nada que reanudar—
     * y la subió. Antes, cada alumno creado por el intento anterior entraba
     * otra vez, con su usuario y su matrícula.
     */
    public function test_no_crea_un_alumno_repetido_si_el_documento_ya_esta(): void
    {
        [$token, $year] = $this->credenciales();

        $archivo = $this->exportacionDeAlumnos($token);

        [$hoja, $fila, $documento] = $this->primeraFilaConDocumento($archivo);

        $sinId = $this->libroSinIdEn($archivo, $hoja, $fila);

        $alumnosAntes = $this->cuantosAlumnos();
        $usuariosAntes = $this->cuantosUsuarios();
        $matriculasAntes = $this->cuantasMatriculas();

        $this->importar($sinId, $token, $year)->assertStatus(200);

        $this->assertSame($alumnosAntes, $this->cuantosAlumnos(),
            "La fila sin id del documento {$documento} creó un alumno repetido.");
        $this->assertSame($usuariosAntes, $this->cuantosUsuarios(),
            'Creó también el usuario del alumno repetido, que es lo que llena `users` de fantasmas.');
        $this->assertSame($matriculasAntes, $this->cuantasMatriculas(),
            'Creó una matrícula de más: el alumno quedaría en dos grupos.');
    }

    /**
     * Una pestaña cuyo nombre no es el de ningún grupo deja el error escrito y
     * la importación reanudable.
     *
     * Es el fallo corriente —subir la hoja del año pasado— y hasta hoy era
     * «Undefined array key 0» en el log del colegio, sin decir qué pestaña era.
     * El código de respuesta sigue siendo 500 a propósito: cambiarlo es tocar
     * el contrato de la pantalla, y eso es otro trabajo.
     */
    public function test_una_pestana_sin_grupo_queda_escrita_con_su_nombre(): void
    {
        [$token, $year] = $this->credenciales();

        $archivo = $this->libroConLaPrimeraHojaRenombrada(
            $this->exportacionDeAlumnos($token), 'NO-EXISTE'
        );

        $this->importar($archivo, $token, $year)->assertStatus(500);

        $fila = $this->ultimaImportacion();

        $this->assertSame(PuntoDeControlDeImportacion::FALLIDA, $fila->estado);
        $this->assertStringContainsString('NO-EXISTE', (string) $fila->error,
            'El error no dice qué pestaña fue, que es lo único que hace falta para arreglarlo.');
    }

    /**
     * El nombre del archivo se guarda saneado.
     *
     * `importaciones.archivo` existe para que un humano reconozca la fila, y el
     * nombre lo pone quien sube el archivo. Lo que se guarda en una columna
     * termina saliendo por una pantalla, así que pasa por el mismo saneado que
     * las subidas de imágenes y documentos —`SafeUpload`, que es donde
     * `GuardsDestructivosTest` exige que viva `getClientOriginalName()`—.
     */
    public function test_el_nombre_del_archivo_se_guarda_saneado(): void
    {
        [$token, $year] = $this->credenciales();

        $this->post(
            "/api/importar/algo/{$year}",
            ['file' => new UploadedFile($this->exportacionDeAlumnos($token),
                'alu<script>mnos.php.xlsx', null, null, true)],
            ['Authorization' => 'Bearer '.$token]
        )->assertStatus(200);

        $guardado = (string) $this->ultimaImportacion()->archivo;

        $this->assertSame('alu_script_mnos_php.xlsx', $guardado);
    }

    // ---------------------------------------------------------------- Apoyos

    /** Token de alguien del colegio y el año que le corresponde. */
    private function credenciales(): array
    {
        $usuario = $this->usuarioDeTipo('Usuario');

        $year = DB::table('periodos')
            ->join('years', 'years.id', '=', 'periodos.year_id')
            ->where('periodos.id', $usuario->periodo_id)
            ->value('years.year');

        return [$this->tokenDe($usuario->username), (int) $year];
    }

    /**
     * La hoja que produce el export, que es la plantilla que la gente rellena.
     *
     * Se copia fuera del directorio temporal de la respuesta porque varios
     * tests la modifican y la vuelven a subir.
     */
    private function exportacionDeAlumnos(string $token): string
    {
        $r = $this->get('/api/users/export', ['Authorization' => 'Bearer '.$token])->assertStatus(200);

        $copia = tempnam(sys_get_temp_dir(), 'importar').'.xlsx';
        copy($this->archivoDescargado($r), $copia);

        return $copia;
    }

    private function importar(string $archivo, string $token, int $year)
    {
        return $this->post(
            "/api/importar/algo/{$year}",
            ['file' => new UploadedFile($archivo, 'alumnos.xlsx', null, null, true)],
            ['Authorization' => 'Bearer '.$token]
        );
    }

    /** La fila de `importaciones` que dejó la última subida. */
    private function ultimaImportacion(): object
    {
        $fila = DB::selectOne('SELECT * FROM importaciones ORDER BY id DESC LIMIT 1');

        $this->assertNotNull($fila, 'La importación no dejó ninguna fila en `importaciones`.');

        return $fila;
    }

    /**
     * Escribe el punto de control que habría dejado un corte.
     *
     * La huella tiene que ser la del contenido del archivo, igual que la que
     * calcula el controlador: si no coincidiera, esto no sería «la misma
     * importación» y no habría nada que reanudar — que es exactamente lo que se
     * quiere comprobar que no pasa.
     */
    private function puntoDeControlAMedias(string $archivo, int $year, array $avance): void
    {
        DB::insert(
            'INSERT INTO importaciones (tipo, huella, archivo, year, avance, filas, estado, inicio, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            ['alumnos', hash_file('sha256', $archivo), 'alumnos.xlsx', $year,
                json_encode($avance), array_sum($avance) + count($avance),
                PuntoDeControlDeImportacion::EN_PROCESO, now(), now(), now()]
        );
    }

    /** Cada pestaña del libro con cuántas filas de datos trae. */
    private function hojasDelLibro(string $archivo): array
    {
        $hojas = [];

        foreach (IOFactory::load($archivo)->getAllSheets() as $hoja) {
            // Los encabezados están en la fila 2 (`headingRow()` del
            // importador), así que las de datos empiezan en la 3.
            $hojas[$hoja->getTitle()] = max(0, $hoja->getHighestDataRow() - 2);
        }

        return $hojas;
    }

    private function primerAlumnoDelGrupo(string $abrev, int $year): int
    {
        $fila = DB::selectOne(
            'SELECT a.id FROM alumnos a
             INNER JOIN matriculas m ON m.alumno_id = a.id AND m.deleted_at IS NULL
             INNER JOIN grupos g ON g.id = m.grupo_id AND g.deleted_at IS NULL
             INNER JOIN years y ON y.id = g.year_id AND y.year = ?
             WHERE g.abrev = ? AND a.deleted_at IS NULL ORDER BY a.id LIMIT 1',
            [$year, $abrev]
        );

        $this->assertNotNull($fila, "El grupo '{$abrev}' del año {$year} no tiene alumnos en el seed.");

        return (int) $fila->id;
    }

    /**
     * El alumno de la base que corresponde a una fila de datos de la hoja.
     *
     * `$fila` es el índice que usa el importador —base 0, contando desde la
     * primera fila de datos—, no el número de fila del libro. Los encabezados
     * están en la 2, así que la fila 0 del importador es la 3 del libro.
     */
    private function alumnoDeLaFila(string $archivo, string $hoja, int $fila): int
    {
        $pestana = IOFactory::load($archivo)->getSheetByName($hoja);

        $id = trim((string) $pestana->getCell($this->columnasDe($pestana)['id'].($fila + 3))->getValue());

        $this->assertNotSame('', $id, "La fila {$fila} de la hoja '{$hoja}' no trae id.");

        return (int) $id;
    }

    private function nombreDe(int $alumno): string
    {
        return (string) DB::table('alumnos')->where('id', $alumno)->value('nombres');
    }

    private function cuantosAlumnos(): int
    {
        return DB::table('alumnos')->whereNull('deleted_at')->count();
    }

    private function cuantosUsuarios(): int
    {
        return DB::table('users')->whereNull('deleted_at')->count();
    }

    private function cuantasMatriculas(): int
    {
        return DB::table('matriculas')->whereNull('deleted_at')->count();
    }

    /**
     * La primera fila del libro que trae id y documento, que es la que se puede
     * dejar sin id para fingir un alumno nuevo.
     *
     * Devuelve [hoja, fila del libro (base 1), documento].
     */
    private function primeraFilaConDocumento(string $archivo): array
    {
        $libro = IOFactory::load($archivo);

        foreach ($libro->getAllSheets() as $hoja) {
            $columnas = $this->columnasDe($hoja);

            for ($fila = 3; $fila <= $hoja->getHighestDataRow(); $fila++) {
                $id = trim((string) $hoja->getCell($columnas['id'].$fila)->getValue());
                $documento = trim((string) $hoja->getCell($columnas['nro_de_documento'].$fila)->getValue());

                if ($id !== '' && $documento !== '') {
                    return [$hoja->getTitle(), $fila, $documento];
                }
            }
        }

        $this->fail('El export no trae ninguna fila con id y documento; sin eso este test no comprueba nada.');
    }

    /** Copia del libro con el `id` de una fila borrado: el importador la verá como alumno nuevo. */
    private function libroSinIdEn(string $archivo, string $hoja, int $fila): string
    {
        $libro = IOFactory::load($archivo);
        $pestana = $libro->getSheetByName($hoja);

        $pestana->setCellValue($this->columnasDe($pestana)['id'].$fila, null);

        return $this->guardar($libro);
    }

    private function libroConLaPrimeraHojaRenombrada(string $archivo, string $nombre): string
    {
        $libro = IOFactory::load($archivo);
        $libro->getSheet(0)->setTitle($nombre);

        return $this->guardar($libro);
    }

    private function guardar($libro): string
    {
        $ruta = tempnam(sys_get_temp_dir(), 'importar').'.xlsx';
        (new EscritorXlsx($libro))->save($ruta);

        return $ruta;
    }

    /**
     * Qué letra de columna es cada encabezado.
     *
     * Los encabezados están en la fila 2 y se normalizan igual que hace
     * `WithHeadingRow`: minúsculas y guiones bajos. Es lo que permite escribir
     * `$columnas['nro_de_documento']` en vez de una letra fija que se rompería
     * el día que el export añada una columna.
     */
    private function columnasDe($hoja): array
    {
        $columnas = [];

        foreach ($hoja->getRowIterator(2, 2) as $fila) {
            foreach ($fila->getCellIterator() as $celda) {
                $titulo = trim((string) $celda->getValue());

                if ($titulo !== '') {
                    $columnas[$this->normalizar($titulo)] = $celda->getColumn();
                }
            }
        }

        $this->assertArrayHasKey('id', $columnas, 'El export dejó de traer la columna `id`.');
        $this->assertArrayHasKey('nro_de_documento', $columnas, 'El export dejó de traer `nro_de_documento`.');

        return $columnas;
    }

    private function normalizar(string $titulo): string
    {
        $sinTildes = strtr(mb_strtolower($titulo), [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n',
        ]);

        return trim(preg_replace('/_+/', '_', preg_replace('/[^a-z0-9]+/', '_', $sinTildes)), '_');
    }
}
