<?php

namespace Tests\Contrato;

use App\Services\PuntoDeControlDeImportacion;
use App\Support\Reloj;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as EscritorXlsx;

/**
 * Lo que una importación recuerda entre una tanda y la siguiente.
 *
 * `importaciones` sabía desde el 20 ago 2026 **por dónde iba**. Lo que no sabía
 * es **qué no entendió** ni **qué se le contestó**, y la pantalla del escenario
 * 8 —la importación que se cayó a medias— necesita las dos para que «seguir
 * donde se quedó» signifique algo:
 *
 * - Los avisos que enseña son de filas que se escribieron en otro proceso y
 *   puede que hace meses. Si vivieran sólo en la respuesta de aquella subida,
 *   esas filas no se podrían mirar nunca más.
 * - Y sin las respuestas, reanudar obliga a contestar otra vez el mapa de
 *   columnas y las equivalencias: empezar de cero con pasos saltados, que es
 *   peor que empezar de cero.
 *
 * De ahí que los dos se guarden con REGLAS CONTRARIAS, y eso es lo que más se
 * comprueba aquí: los avisos **acumulan** porque son historia, y las respuestas
 * **pisan** porque son una instrucción vigente.
 */
class LoQueLaImportacionRecuerdaTest extends CasoDeContrato
{
    public function test_sin_nada_a_medias_contesta_que_no_hay(): void
    {
        [$token, $year] = $this->personalYSuYear();

        $this->get("/api/importar/alumnos/pendiente/{$year}", ['Authorization' => 'Bearer '.$token])
            ->assertStatus(200)
            ->assertExactJson(['pendiente' => null]);
    }

    /**
     * Una importación cortada se puede encontrar sin saber su `id`.
     *
     * Es el caso real: la pantalla pregunta al entrar y no sabe nada, sólo el
     * año. Si esto exigiera un `id`, la persona que llega el lunes a un colegio
     * donde la importación se cortó el viernes no tendría forma de llegar a
     * ella.
     */
    public function test_la_que_quedo_a_medias_se_encuentra_por_el_year(): void
    {
        [$token, $year] = $this->personalYSuYear();
        $archivo = $this->exportacionDeAlumnos($token);

        $this->puntoDeControlAMedias($archivo, $year, ['5' => 12]);

        $pendiente = $this->get("/api/importar/alumnos/pendiente/{$year}", ['Authorization' => 'Bearer '.$token])
            ->assertStatus(200)
            ->json('pendiente');

        $this->assertNotNull($pendiente);
        $this->assertSame('alumnos.xlsx', $pendiente['archivo']);
        $this->assertSame(PuntoDeControlDeImportacion::EN_PROCESO, $pendiente['estado']);
        $this->assertSame(['5' => 12], $pendiente['avance'], 'El avance por hoja es la tabla de la pantalla.');
    }

    /**
     * Una que terminó bien NO sale.
     *
     * Y el criterio tiene que ser el mismo con el que `abrir()` decide qué
     * reanudar: si esto enseñara una importación que aquél no va a continuar,
     * el botón «seguir donde se quedó» mentiría.
     */
    public function test_una_completada_no_sale_como_pendiente(): void
    {
        [$token, $year] = $this->personalYSuYear();

        $this->importar($this->exportacionDeAlumnos($token), $token, $year)->assertStatus(200);

        $this->get("/api/importar/alumnos/pendiente/{$year}", ['Authorization' => 'Bearer '.$token])
            ->assertStatus(200)
            ->assertExactJson(['pendiente' => null]);
    }

    /**
     * Los avisos de las filas ya escritas siguen ahí cuando la importación se
     * retoma. Es la frase entera de la pantalla: «de las 63 filas ya escritas,
     * 4 llevaron un valor que no se reconoció».
     */
    public function test_los_avisos_sobreviven_a_la_subida_que_los_produjo(): void
    {
        [$token, $year] = $this->personalYSuYear();

        $archivo = $this->conTipoDeDocumentoInventado($this->exportacionDeAlumnos($token), 'CARNÉ DIPLOMÁTICO');
        $this->importar($archivo, $token, $year)->assertStatus(200);

        $guardados = json_decode((string) $this->ultimaImportacion()->avisos, true);

        $this->assertNotEmpty($guardados, 'Los avisos tienen que quedar en la fila, no sólo en la respuesta.');
        $this->assertSame('CARNÉ DIPLOMÁTICO', $guardados[0]['valor']);
    }

    /**
     * ACUMULAN, no pisan — que es la mitad que hace falta para el escenario 8.
     *
     * Dos tandas del mismo archivo con dos valores raros distintos: al final
     * tienen que estar los dos. Si pisaran, al reanudar se perdería justo lo
     * que la pantalla cuenta de las filas que ya entraron.
     */
    public function test_los_avisos_de_dos_tandas_se_acumulan(): void
    {
        [$token, $year] = $this->personalYSuYear();
        $archivo = $this->conTipoDeDocumentoInventado($this->exportacionDeAlumnos($token), 'CARNÉ DIPLOMÁTICO');

        // Lo que habría dejado la tanda que se cortó. La fila queda 'en_proceso'
        // y con la huella de ESTE archivo, así que la subida de abajo la reanuda
        // en vez de abrir otra.
        $id = $this->puntoDeControlAMedias($archivo, $year, ['5' => 0], [
            ['fila' => 1, 'campo' => 'estado_matricula', 'valor' => 'Activo', 'motivo' => 'de la tanda anterior'],
        ]);

        $this->importar($archivo, $token, $year)->assertStatus(200);

        $fila = DB::selectOne('SELECT avisos FROM importaciones WHERE id = ?', [$id]);
        $campos = array_column(json_decode((string) $fila->avisos, true), 'campo');

        $this->assertContains('estado_matricula', $campos,
            'El aviso de la tanda anterior se perdió: con eso, la pantalla no puede hablar de las filas ya escritas.');
        $this->assertContains('tipo_de_documento', $campos,
            'El aviso de esta tanda no se añadió.');
    }

    /**
     * Las respuestas del usuario viajan con el fichero y se guardan antes de
     * leer una fila, porque el caso en que hacen falta es aquel en el que esto
     * se corta a la mitad.
     */
    public function test_lo_que_contesto_la_persona_se_guarda_y_se_devuelve(): void
    {
        [$token, $year] = $this->personalYSuYear();
        $archivo = $this->exportacionDeAlumnos($token);

        $this->puntoDeControlAMedias($archivo, $year, ['5' => 3]);

        $this->post(
            "/api/importar/algo/{$year}",
            [
                'file' => new UploadedFile($archivo, 'alumnos.xlsx', null, null, true),
                'respuestas' => json_encode(['equivalencias' => ['CARNÉ DIPLOMÁTICO' => 1]]),
            ],
            ['Authorization' => 'Bearer '.$token]
        )->assertStatus(200);

        $guardadas = json_decode((string) $this->ultimaImportacion()->respuestas, true);

        $this->assertSame(['equivalencias' => ['CARNÉ DIPLOMÁTICO' => 1]], $guardadas);
    }

    /**
     * Y PISAN, al revés que los avisos: una respuesta es una instrucción
     * vigente, no historia. Quien vuelve a subir con el mapa corregido está
     * corrigiendo lo que dijo antes.
     */
    public function test_unas_respuestas_nuevas_sustituyen_a_las_viejas(): void
    {
        [$token, $year] = $this->personalYSuYear();
        $archivo = $this->exportacionDeAlumnos($token);

        $id = $this->puntoDeControlAMedias($archivo, $year, ['5' => 3], null, ['mapa' => ['a' => 1]]);

        $this->subirCon($archivo, $token, $year, ['mapa' => ['b' => 2]]);

        $fila = DB::selectOne('SELECT respuestas FROM importaciones WHERE id = ?', [$id]);

        $this->assertSame(['mapa' => ['b' => 2]], json_decode((string) $fila->respuestas, true));
    }

    /**
     * Y una subida sin instrucciones no borra las que había.
     *
     * «Esta subida no traía nada» y «olvida lo que te dije» no son lo mismo, y
     * la diferencia la paga quien reanuda: con la primera lectura, subir el
     * archivo otra vez desde otra pantalla le vaciaría el trabajo.
     */
    public function test_una_subida_sin_respuestas_no_borra_las_guardadas(): void
    {
        [$token, $year] = $this->personalYSuYear();
        $archivo = $this->exportacionDeAlumnos($token);

        $id = $this->puntoDeControlAMedias($archivo, $year, ['5' => 3], null, ['mapa' => ['a' => 1]]);

        $this->importar($archivo, $token, $year)->assertStatus(200);

        $fila = DB::selectOne('SELECT respuestas FROM importaciones WHERE id = ?', [$id]);

        $this->assertSame(['mapa' => ['a' => 1]], json_decode((string) $fila->respuestas, true));
    }

    /**
     * La hora que sale es la del COLEGIO.
     *
     * Esta ruta existe para que la pantalla diga «empezada el 14 de enero a las
     * 9:41», así que la hora que devuelve es lo que se comprueba.
     *
     * **Lo que cambió el 22 sep 2026 es CÓMO se consigue, y el test iba escrito
     * sobre el cómo.** `importaciones` se escribía con `now()` —UTC, la única
     * excepción del repo— y esta ruta convertía al leer; el test sembraba en UTC,
     * exigía que la respuesta viniera movida cinco horas y remataba con un
     * `assertNotSame` para que la conversión no pudiera desaparecer sin avisar.
     *
     * Ahora la tabla va en Bogotá y **no hay conversión que comprobar**: la columna
     * ya trae la hora buena. Lo que se fija aquí es la propiedad que siempre quiso
     * fijar —lo que ve la pantalla es la hora del colegio— y no el mecanismo, que
     * era lo que lo ataba a una decisión que podía cambiar. Y cambió.
     *
     * El `assertNotSame` se va con su motivo: decía *«si coincidieran, o la
     * conversión no ocurre o la base ya no está en UTC»*. Hoy coinciden **porque
     * la base ya no está en UTC**, que era justo una de las dos cosas que ese
     * aserto mandaba mirar. Se miró.
     */
    public function test_la_hora_que_sale_es_la_del_colegio(): void
    {
        [$token, $year] = $this->personalYSuYear();
        $archivo = $this->exportacionDeAlumnos($token);

        $id = $this->puntoDeControlAMedias($archivo, $year, ['5' => 2]);

        $enLaBase = DB::selectOne('SELECT inicio FROM importaciones WHERE id = ?', [$id])->inicio;

        $devuelta = $this->get("/api/importar/alumnos/pendiente/{$year}", ['Authorization' => 'Bearer '.$token])
            ->assertStatus(200)
            ->json('pendiente.inicio');

        $this->assertSame($enLaBase, $devuelta,
            'La ruta ya no convierte nada: lo que hay en la columna es lo que sale.');

        // Y que lo que hay en la columna sea de verdad la hora del colegio, que es
        // la propiedad que importa. Sin esto, el `assertSame` de arriba pasaría
        // igual con las dos puntas mal a la vez.
        $this->assertLessThan(
            120,
            abs(Reloj::ahora()->getTimestamp() - Reloj::desdeTexto($devuelta)?->getTimestamp()),
            'La hora escrita no es la de Bogotá. Si esto se va cinco horas, `importaciones` '
            .'volvió a escribirse con `now()`; si se va una, alguien puso `NOW()` de MySQL, '
            .'que en los diecisiete es EDT. Ver el 53 §3.'
        );
    }

    /**
     * `empezada_por` trae un nombre aunque quien la empezó no tenga ficha de
     * profesor — que es el caso NORMAL, no el raro.
     *
     * Medido el 20 sep 2026 en la copia de desarrollo: de las 22 cuentas de tipo
     * `Usuario` —los administrativos, que son quienes importan alumnos—
     * **ninguna** tiene ficha en `profesores`. Unir sólo contra esa tabla dejaba
     * la cabecera diciendo «empezada el 14 de enero a las 9:41 por» y nada,
     * justo para todos los que usan esta pantalla.
     */
    public function test_dice_quien_la_empezo_aunque_no_sea_docente(): void
    {
        [$token, $year] = $this->personalYSuYear();
        $usuario = $this->usuarioDeTipo('Usuario');
        $archivo = $this->exportacionDeAlumnos($token);

        $id = $this->puntoDeControlAMedias($archivo, $year, ['5' => 2]);
        // `usuarioDeTipo` devuelve la fila de `users`, así que es `id` y no
        // `user_id` — ése es el nombre en el objeto del contexto, no aquí.
        DB::update('UPDATE importaciones SET created_by = ? WHERE id = ?', [$usuario->id, $id]);

        $pendiente = $this->get("/api/importar/alumnos/pendiente/{$year}", ['Authorization' => 'Bearer '.$token])
            ->assertStatus(200)
            ->json('pendiente');

        $this->assertNotNull($pendiente['empezada_por'],
            'Sin nombre, la cabecera dice «empezada el 14 de enero a las 9:41 por» y nada.');
        $this->assertNotSame('', trim((string) $pendiente['empezada_por']));
    }

    /** El guard de la ruta nueva, que es el mismo de la subida. */
    public function test_sin_token_no_contesta(): void
    {
        $this->get('/api/importar/alumnos/pendiente/2026')->assertStatus(401);
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

    private function subirCon(string $archivo, string $token, int $year, array $respuestas)
    {
        return $this->post(
            "/api/importar/algo/{$year}",
            [
                'file' => new UploadedFile($archivo, 'alumnos.xlsx', null, null, true),
                'respuestas' => json_encode($respuestas),
            ],
            ['Authorization' => 'Bearer '.$token]
        )->assertStatus(200);
    }

    private function ultimaImportacion(): object
    {
        $fila = DB::selectOne('SELECT * FROM importaciones ORDER BY id DESC LIMIT 1');

        $this->assertNotNull($fila, 'La importación no dejó ninguna fila.');

        return $fila;
    }

    /**
     * Escribe el punto de control que habría dejado un corte, con lo que esa
     * tanda anterior hubiera dejado anotado.
     *
     * `avisos` y `respuestas` son parámetros porque los tests de acumulación
     * van de eso: que lo de ANTES siga ahí. Ponerlos aquí —en vez de subir dos
     * veces— es lo que hace que la segunda subida reanude ESTA fila en lugar de
     * abrir otra, y es además el caso real: una subida que termina bien deja la
     * fila 'completada', y `abrir()` no reanuda completadas.
     *
     * La primera versión de estos tests subía dos veces y comprobaba la última
     * fila de la tabla. Pasaba por el motivo equivocado: la segunda inserción a
     * mano creaba una fila NUEVA y limpia, así que «no acumuló» no decía nada
     * del código. El detector contestaba bien a la pregunta que se le hizo.
     */
    private function puntoDeControlAMedias(
        string $archivo,
        int $year,
        array $avance,
        ?array $avisos = null,
        ?array $respuestas = null,
    ): int {
        DB::insert(
            'INSERT INTO importaciones (tipo, huella, archivo, year, avance, filas, estado, avisos, respuestas, inicio, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            ['alumnos', hash_file('sha256', $archivo), 'alumnos.xlsx', $year,
                json_encode($avance), array_sum($avance) + count($avance),
                PuntoDeControlDeImportacion::EN_PROCESO,
                $avisos === null ? null : json_encode($avisos, JSON_UNESCAPED_UNICODE),
                $respuestas === null ? null : json_encode($respuestas, JSON_UNESCAPED_UNICODE),
                Reloj::ahora(), Reloj::ahora(), Reloj::ahora()]
        );

        return (int) DB::getPdo()->lastInsertId();
    }

    private function conTipoDeDocumentoInventado(string $archivo, string $valor): string
    {
        $libro = IOFactory::load($archivo);
        $pestana = $libro->getSheet(0);

        $columnas = [];
        foreach ($pestana->getRowIterator(2, 2) as $fila) {
            foreach ($fila->getCellIterator() as $celda) {
                $titulo = trim((string) $celda->getValue());
                if ($titulo !== '') {
                    $columnas[strtolower(str_replace(' ', '_', $titulo))] = $celda->getColumn();
                }
            }
        }

        $pestana->setCellValue($columnas['tipo_de_documento'].'3', $valor);

        $ruta = tempnam(sys_get_temp_dir(), 'importar').'.xlsx';
        (new EscritorXlsx($libro))->save($ruta);

        return $ruta;
    }
}
