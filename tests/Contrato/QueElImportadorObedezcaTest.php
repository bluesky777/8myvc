<?php

namespace Tests\Contrato;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as EscritorXlsx;

/**
 * Que lo que la persona decide LLEGUE a lo que se escribe.
 *
 * Hasta el 21 sep 2026 las instrucciones se guardaban y se devolvían **y no las
 * interpretaba nadie**: el coordinador veía qué iba a pasar y no podía
 * cambiarlo. El ensayo decía «se guardará TARJETA DE IDENTIDAD en 37 alumnos» y
 * eso era todo lo que se podía hacer al respecto.
 *
 * Las equivalencias se aplican dentro de `ImporterFixer` **porque por ahí pasan
 * los dos caminos**, y ésa es toda la garantía de que el plan que se enseña es
 * el que se cumple. El primer test de aquí es el que lo demuestra.
 */
class QueElImportadorObedezcaTest extends CasoDeContrato
{
    /**
     * EL CICLO ENTERO: el ensayo avisa, la persona decide, el ensayo lo refleja
     * y la importación lo escribe.
     *
     * Es el test que sostiene la entrega. Si el ensayo no reflejara la
     * corrección, la pantalla enseñaría el plan de ANTES de decidir y el
     * resultado sería otro — que es el fallo que este módulo persigue, con un
     * paso más de disimulo.
     */
    public function test_el_ciclo_entero_avisar_decidir_reflejar_escribir(): void
    {
        [$token, $year] = $this->personalYSuYear();
        $archivo = $this->conTipoDeDocumento($this->exportacionDeAlumnos($token), 'CARNÉ DIPLOMÁTICO');

        // 1. El ensayo avisa de que no lo entiende.
        $sinDecidir = $this->ensayar($archivo, $token, $year)->assertStatus(200);

        $this->assertNotEmpty(
            collect($sinDecidir->json('valores_no_reconocidos'))->firstWhere('valor', 'CARNÉ DIPLOMÁTICO'),
            'Sin decisión, el valor raro tiene que salir como no reconocido.'
        );

        // 2. La persona decide, y 3. el ensayo lo refleja: ya no hay aviso.
        $respuestas = [
            'version' => 1,
            'huella' => $sinDecidir->json('huella'),
            'vocabularios' => [
                ['columna' => 'tipo_de_documento', 'valor_original' => 'CARNÉ DIPLOMÁTICO',
                    'decision' => 'usar_id', 'id' => 1],
            ],
        ];

        $conDecision = $this->ensayar($archivo, $token, $year, $respuestas)->assertStatus(200);

        $this->assertEmpty(
            collect($conDecision->json('valores_no_reconocidos'))->firstWhere('valor', 'CARNÉ DIPLOMÁTICO'),
            'Con la decisión puesta, el ensayo tiene que enseñar el plan CORREGIDO.'
        );
        $this->assertSame(1, $conDecision->json('respuestas.veces_que_se_usaron'));

        // 4. Y la importación lo escribe.
        $documento = $this->documentoDeLaFilaTres($archivo);

        $this->importar($archivo, $token, $year, $respuestas)->assertStatus(200);

        $this->assertSame(
            1,
            (int) DB::selectOne('SELECT tipo_doc FROM alumnos WHERE documento = ? ORDER BY id LIMIT 1', [$documento])->tipo_doc,
            'Lo que la persona decidió no llegó a la ficha del alumno.'
        );
    }

    /**
     * Sin decisión, el defecto sigue siendo el defecto.
     *
     * El control del test de arriba: si esto escribiera 1 sin que nadie lo
     * pidiera, aquel no demostraría nada.
     */
    public function test_sin_decision_se_guarda_el_defecto_de_siempre(): void
    {
        [$token, $year] = $this->personalYSuYear();
        $archivo = $this->conTipoDeDocumento($this->exportacionDeAlumnos($token), 'CARNÉ DIPLOMÁTICO');
        $documento = $this->documentoDeLaFilaTres($archivo);

        $this->importar($archivo, $token, $year)->assertStatus(200);

        $this->assertSame(
            3,
            (int) DB::selectOne('SELECT tipo_doc FROM alumnos WHERE documento = ? ORDER BY id LIMIT 1', [$documento])->tipo_doc,
            'Sin decisión tiene que seguir cayendo en Tarjeta de Identidad, que es lo que hace hoy.'
        );
    }

    /**
     * `a_revisar` NO aplica nada, y es una decisión y no un olvido.
     *
     * Significa «no lo decido todavía»: el valor sigue sin reconocerse y sigue
     * dejando su aviso. Convertirlo en el valor por defecto sería decidir por
     * alguien y no decirlo, que es justo lo que la Fase 1 quitó.
     */
    public function test_a_revisar_no_decide_nada_y_el_aviso_sigue(): void
    {
        [$token, $year] = $this->personalYSuYear();
        $archivo = $this->conTipoDeDocumento($this->exportacionDeAlumnos($token), 'CARNÉ DIPLOMÁTICO');

        $r = $this->ensayar($archivo, $token, $year, [
            'vocabularios' => [
                ['columna' => 'tipo_de_documento', 'valor_original' => 'CARNÉ DIPLOMÁTICO', 'decision' => 'a_revisar'],
            ],
        ])->assertStatus(200);

        $this->assertNotEmpty(
            collect($r->json('valores_no_reconocidos'))->firstWhere('valor', 'CARNÉ DIPLOMÁTICO'),
            'Marcar «a revisar» no decide el valor: el aviso tiene que seguir ahí.'
        );
        $this->assertSame(1, $r->json('respuestas.vocabularios_a_revisar'));
        $this->assertSame(0, $r->json('respuestas.veces_que_se_usaron'));
    }

    /**
     * El estado de matrícula también obedece — y ése no pasaba por el traductor.
     *
     * Lo leía el importador directamente, así que una equivalencia puesta allí
     * la habría visto la subida y NO el ensayo: el plan habría enseñado un
     * estado distinto del que se escribe.
     */
    public function test_el_estado_de_matricula_tambien_obedece(): void
    {
        [$token, $year] = $this->personalYSuYear();
        $archivo = $this->conEstado($this->exportacionDeAlumnos($token), 'Activo');
        $documento = $this->documentoDeLaFilaTres($archivo);

        $this->importar($archivo, $token, $year, [
            'vocabularios' => [
                ['columna' => 'estado_matricula', 'valor_original' => 'Activo',
                    'decision' => 'usar_valor', 'valor' => 'ASIS'],
            ],
        ])->assertStatus(200);

        $estado = DB::selectOne(
            'SELECT m.estado FROM matriculas m
             INNER JOIN alumnos a ON a.id = m.alumno_id
             WHERE a.documento = ? AND m.deleted_at IS NULL ORDER BY m.id DESC LIMIT 1',
            [$documento]
        )->estado;

        $this->assertSame('ASIS', $estado,
            '«Activo» no cabe en varchar(4) y sin decisión no se escribe; con decisión tiene que entrar el código elegido.');
    }

    /**
     * CORREGIR EL ESTADO SE NOTA EN EL PLAN, no sólo en un contador.
     *
     * Conduciendo contra el servidor salió que el servidor decía «usé tu
     * corrección 14 veces» **y en el mismo JSON seguía avisando de que esas 14
     * no se escriben**. Las dos no pueden ser ciertas a la vez, y desde una
     * pantalla la lectura es la peor: «corregí y el aviso sigue, así que no
     * funcionó».
     *
     * Eran dos fallos en el mismo sitio: los truncados se calculaban sobre el
     * valor CRUDO del Excel en vez del ya traducido, y el estado de la matrícula
     * no entraba en el plan **porque no vive en `alumnos`** — así que los totales
     * no se movían jamás con ese campo por mucho que la corrección llegara a la
     * base.
     */
    public function test_corregir_el_estado_se_nota_en_el_plan(): void
    {
        [$token, $year] = $this->personalYSuYear();
        $archivo = $this->conEstado($this->exportacionDeAlumnos($token), 'Activo');

        // Sin decidir: sale como truncado y no cambia nada.
        $sinDecidir = $this->ensayar($archivo, $token, $year)->assertStatus(200);

        $this->assertNotNull(collect($sinDecidir->json('truncados'))->firstWhere('valor', 'Activo'));

        $respuestas = ['vocabularios' => [
            ['columna' => 'estado_matricula', 'valor_original' => 'Activo',
                'decision' => 'usar_valor', 'valor' => 'ASIS'],
        ]];

        $conDecision = $this->ensayar($archivo, $token, $year, $respuestas)->assertStatus(200);

        $this->assertEmpty(
            collect($conDecision->json('truncados'))->firstWhere('valor', 'Activo'),
            'El aviso de truncado mira el valor CRUDO: con la corrección puesta ya no hay nada que truncar.'
        );

        $this->assertGreaterThan(
            0,
            $conDecision->json('totales.actualizar'),
            'Si el plan no se mueve, la pantalla no tiene forma de enseñar que la corrección sirvió.'
        );

        $cambio = collect($conDecision->json('plan'))
            ->pluck('cambios')->flatten(1)->firstWhere('campo', 'estado_matricula');

        $this->assertNotNull($cambio, 'El estado vive en `matriculas` y aun así tiene que salir en el plan.');
        $this->assertSame('ASIS', $cambio['despues']);
        $this->assertSame('matriculas', $cambio['tabla']);
    }

    /**
     * UNAS RESPUESTAS DE OTRO FICHERO NO SE APLICAN A ÉSTE.
     *
     * Es el agujero que este módulo lleva rodeando: alguien aprueba trece
     * decisiones, cambia una celda y sube. Las respuestas siguen encajando
     * —casan por nombre de columna, no por contenido— y se aplicarían a un libro
     * que nadie revisó. Los números saldrían igual, sólo que serían otros.
     */
    public function test_unas_respuestas_de_otro_fichero_se_rechazan(): void
    {
        [$token, $year] = $this->personalYSuYear();
        $archivo = $this->exportacionDeAlumnos($token);

        $respuestas = [
            'huella' => str_repeat('a', 64),
            'vocabularios' => [
                ['columna' => 'tipo_de_documento', 'valor_original' => 'X', 'decision' => 'usar_id', 'id' => 1],
            ],
        ];

        $this->importar($archivo, $token, $year, $respuestas)
            ->assertStatus(422)
            ->assertJson(['ok' => false]);

        $this->ensayar($archivo, $token, $year, $respuestas)->assertStatus(422);
    }

    /** Sin huella se aceptan: exigirla rompería a quien mande instrucciones a mano. */
    public function test_sin_huella_las_respuestas_se_aceptan(): void
    {
        [$token, $year] = $this->personalYSuYear();
        $archivo = $this->conTipoDeDocumento($this->exportacionDeAlumnos($token), 'CARNÉ DIPLOMÁTICO');

        $this->ensayar($archivo, $token, $year, [
            'vocabularios' => [
                ['columna' => 'tipo_de_documento', 'valor_original' => 'CARNÉ DIPLOMÁTICO',
                    'decision' => 'usar_id', 'id' => 1],
            ],
        ])->assertStatus(200)->assertJson(['respuestas' => ['veces_que_se_usaron' => 1]]);
    }

    /**
     * **YA NO QUEDA NINGUNA SECCIÓN SIN APLICAR** — y el mecanismo que lo decía
     * se queda.
     *
     * Hasta el 21 sep 2026 sólo se aplicaban los vocabularios, y las otras cuatro
     * se aceptaban, se guardaban y se declaraban en `no_aplicadas` con su motivo:
     * aceptar una sección y no interpretarla sin decirlo es el mismo silencio que
     * este módulo empezó quitando.
     *
     * Ahora se aplican las cinco. **Lo que este caso fija no es que la lista esté
     * vacía por casualidad**: es que si mañana entra una sección a medias, tenga
     * que declararse aquí en vez de aceptarse en silencio.
     */
    public function test_ya_no_queda_ninguna_seccion_sin_aplicar(): void
    {
        [$token, $year] = $this->personalYSuYear();

        $r = $this->ensayar($this->exportacionDeAlumnos($token), $token, $year, [
            'repetidos' => [['hoja' => '6', 'fila_del_libro' => 15, 'decision' => 'actualizar', 'alumno_id' => 1055]],
            'hojas' => [['nombre' => 'Consolidado', 'decision' => 'omitir']],
        ])->assertStatus(200);

        $this->assertSame([], $r->json('respuestas.no_aplicadas'),
            'Hay una sección que se acepta y no se interpreta: tiene que decirlo con su motivo, '
            .'o la pantalla promete algo que no ocurre.');

        $this->assertContains('hojas', $r->json('respuestas.aplicadas'));
        $this->assertContains('repetidos', $r->json('respuestas.aplicadas'));
    }

    /**
     * **`vacios: conservar` deja de borrar, y es la sección que más datos salva.**
     *
     * El `UPDATE` del importador escribe todas las columnas, así que una celda
     * vacía no dice «no sé»: dice «bórralo». Lo dice el propio catálogo del
     * ensayo —*«Se BORRA la que hubiera»*— y era el comportamiento de siempre.
     *
     * Se comprueba **contra la base**, no contra la respuesta: lo que importa es
     * que el teléfono siga ahí.
     */
    public function test_conservar_una_columna_vacia_no_borra_lo_que_habia(): void
    {
        [$token, $year] = $this->personalYSuYear();

        // **El alumno es el de la FILA 3**, que es la que `escribir()` toca. Con
        // uno cualquiera el caso pasaría por no haberlo tocado nadie, que es
        // verde por el motivo equivocado.
        $exportado = $this->exportacionDeAlumnos($token);
        $documento = $this->documentoDeLaPrimeraFila($exportado);

        DB::update('UPDATE alumnos SET telefono = ? WHERE documento = ?', ['3001112233', $documento]);

        $archivo = $this->escribir($exportado, 'telefono', '');

        $this->importar($archivo, $token, $year, [
            'vacios' => [['columna' => 'telefono', 'decision' => 'conservar']],
        ])->assertStatus(200);

        $this->assertSame('3001112233',
            DB::selectOne('SELECT telefono FROM alumnos WHERE documento = ?', [$documento])->telefono,
            'Una celda vacía borró el teléfono a pesar de que se pidió conservarlo.');
    }

    /** Y sin decir nada se sigue borrando, que es el comportamiento de siempre. */
    public function test_sin_decir_nada_la_celda_vacia_sigue_borrando(): void
    {
        [$token, $year] = $this->personalYSuYear();

        $exportado = $this->exportacionDeAlumnos($token);
        $documento = $this->documentoDeLaPrimeraFila($exportado);

        DB::update('UPDATE alumnos SET telefono = ? WHERE documento = ?', ['3001112233', $documento]);

        $this->importar($this->escribir($exportado, 'telefono', ''), $token, $year)->assertStatus(200);

        $this->assertNotSame('3001112233',
            DB::selectOne('SELECT telefono FROM alumnos WHERE documento = ?', [$documento])->telefono,
            'Cambió el comportamiento por defecto sin que nadie lo pidiera: eso es decidir por '
            .'el colegio.');
    }

    /**
     * **`hojas: omitir` salta la hoja en vez de reventar la importación entera.**
     *
     * Hoy una pestaña que no casa con ningún grupo del año hace 500 y no entra
     * nadie — ni siquiera los grupos de las otras hojas. Es el caso corriente de
     * la hoja de notas o la del año pasado que alguien dejó dentro del libro.
     *
     * Y omitir **se declara**: la respuesta dice cuáles se saltaron. Una hoja que
     * desaparece en silencio es un grupo entero sin importar.
     */
    public function test_una_hoja_que_no_casa_se_puede_omitir_en_vez_de_parar(): void
    {
        [$token, $year] = $this->personalYSuYear();

        $libro = IOFactory::load($this->exportacionDeAlumnos($token));
        $libro->getSheet(0)->setTitle('NO-EXISTE');
        $archivo = $this->guardar($libro);

        $this->importar($archivo, $token, $year)->assertStatus(500);

        $r = $this->importar($archivo, $token, $year, [
            'hojas' => [['nombre' => 'NO-EXISTE', 'decision' => 'omitir']],
        ])->assertStatus(200);

        $this->assertSame(['NO-EXISTE'], $r->json('hojas_omitidas'),
            'La hoja se saltó y la respuesta no lo dice: un grupo entero sin importar y nadie '
            .'enterándose.');
    }

    /**
     * **`duplicados: primera` cambia cuál gana dentro del fichero.**
     *
     * Hoy gana la última porque el importador procesa en orden y la segunda
     * pasada pisa a la primera — no porque nadie lo eligiera. Esto convierte ese
     * accidente en una decisión.
     */
    public function test_con_duplicados_se_puede_elegir_que_gane_la_primera(): void
    {
        [$token, $year] = $this->personalYSuYear();

        $libro = IOFactory::load($this->exportacionDeAlumnos($token));
        $hoja = $libro->getSheet(0);
        $columnas = $this->columnasDe($hoja);

        $documento = (string) $hoja->getCell($columnas['nro_de_documento'].'3')->getValue();

        // **La pareja es la ÚLTIMA fila con datos, no la 4 a ciegas**: la primera
        // pestaña del export puede traer un solo alumno, y entonces escribir en
        // la 4 inventa una fila suelta en vez de un duplicado.
        $ultima = $hoja->getHighestDataRow();

        $this->assertGreaterThan(3, $ultima,
            'La primera hoja del export trae un solo alumno: sin dos filas no hay duplicado que probar.');

        $hoja->setCellValue($columnas['nro_de_documento'].$ultima, $documento);
        $hoja->setCellValue($columnas['primer_nombre'].'3', 'GANALAPRIMERA');
        $hoja->setCellValue($columnas['primer_nombre'].$ultima, 'GANALAULTIMA');

        $archivo = $this->guardar($libro);

        $r = $this->importar($archivo, $token, $year, [
            'duplicados' => [['documento' => $documento, 'decision' => 'primera']],
        ])->assertStatus(200);

        $this->assertSame(1, $r->json('hechos.duplicados_descartados'),
            'No se descartó la fila perdedora, así que la última siguió pisando a la primera.');

        $this->assertStringStartsWith('GANALAPRIMERA',
            DB::selectOne('SELECT nombres FROM alumnos WHERE documento = ?', [$documento])->nombres,
            'Ganó la última a pesar de haber elegido la primera.');
    }

    /**
     * **`repetidos: omitir` no toca la ficha que ya estaba.**
     *
     * Por defecto se actualiza, que es la idempotencia del 20 ago y lo que evita
     * crear duplicados. `omitir` es para el caso contrario: una hoja de alumnos
     * NUEVOS donde un documento que ya existe es un error de quien la llenó, y
     * machacar la ficha buena con esa fila es el daño, no el arreglo.
     */
    public function test_un_repetido_se_puede_omitir_en_vez_de_machacar(): void
    {
        [$token, $year] = $this->personalYSuYear();

        $libro = IOFactory::load($this->exportacionDeAlumnos($token));
        $hoja = $libro->getSheet(0);
        $columnas = $this->columnasDe($hoja);

        $documento = (string) $hoja->getCell($columnas['nro_de_documento'].'3')->getValue();

        // Sin `id` la fila entra por el documento, que es el camino del repetido.
        $hoja->setCellValue($columnas['id'].'3', null);
        $hoja->setCellValue($columnas['primer_nombre'].'3', 'NOMEDEBERIAESCRIBIR');

        $antes = DB::selectOne('SELECT nombres FROM alumnos WHERE documento = ?', [$documento])->nombres;

        $r = $this->importar($this->guardar($libro), $token, $year, [
            'repetidos' => [['documento_en_la_hoja' => $documento, 'decision' => 'omitir']],
        ])->assertStatus(200);

        $this->assertSame(1, $r->json('hechos.repetidos_omitidos'));
        $this->assertSame($antes,
            DB::selectOne('SELECT nombres FROM alumnos WHERE documento = ?', [$documento])->nombres,
            'Se machacó la ficha buena con una fila que se pidió omitir.');
    }

    /** Y sin respuestas el campo es null, no un resumen de ceros que parece que hubo. */
    public function test_sin_respuestas_el_campo_va_nulo(): void
    {
        [$token, $year] = $this->personalYSuYear();

        $this->ensayar($this->exportacionDeAlumnos($token), $token, $year)
            ->assertStatus(200)
            ->assertJson(['respuestas' => null]);
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

        $copia = tempnam(sys_get_temp_dir(), 'obe').'.xlsx';
        copy($this->archivoDescargado($r), $copia);

        return $copia;
    }

    private function ensayar(string $archivo, string $token, int $year, ?array $respuestas = null)
    {
        $cuerpo = ['file' => new UploadedFile($archivo, 'alumnos.xlsx', null, null, true)];

        if ($respuestas !== null) {
            $cuerpo['respuestas'] = json_encode($respuestas);
        }

        return $this->post("/api/importar/alumnos/ensayo/{$year}", $cuerpo, ['Authorization' => 'Bearer '.$token]);
    }

    private function importar(string $archivo, string $token, int $year, ?array $respuestas = null)
    {
        $cuerpo = ['file' => new UploadedFile($archivo, 'alumnos.xlsx', null, null, true)];

        if ($respuestas !== null) {
            $cuerpo['respuestas'] = json_encode($respuestas);
        }

        return $this->post("/api/importar/algo/{$year}", $cuerpo, ['Authorization' => 'Bearer '.$token]);
    }

    private function conTipoDeDocumento(string $archivo, string $valor): string
    {
        return $this->escribir($archivo, 'tipo_de_documento', $valor);
    }

    private function conEstado(string $archivo, string $valor): string
    {
        return $this->escribir($archivo, 'estado_matricula', $valor);
    }

    private function guardar($libro): string
    {
        $ruta = tempnam(sys_get_temp_dir(), 'obe').'.xlsx';
        (new EscritorXlsx($libro))->save($ruta);

        return $ruta;
    }

    /** El documento que trae la primera fila de datos: el alumno que estos casos tocan. */
    private function documentoDeLaPrimeraFila(string $archivo): string
    {
        $hoja = IOFactory::load($archivo)->getSheet(0);

        return (string) $hoja->getCell($this->columnasDe($hoja)['nro_de_documento'].'3')->getValue();
    }

    private function escribir(string $archivo, string $columna, string $valor): string
    {
        $libro = IOFactory::load($archivo);
        $hoja = $libro->getSheet(0);

        $hoja->setCellValue($this->columnasDe($hoja)[$columna].'3', $valor);

        $ruta = tempnam(sys_get_temp_dir(), 'obe').'.xlsx';
        (new EscritorXlsx($libro))->save($ruta);

        return $ruta;
    }

    private function documentoDeLaFilaTres(string $archivo): string
    {
        $hoja = IOFactory::load($archivo)->getSheet(0);

        return (string) $hoja->getCell($this->columnasDe($hoja)['nro_de_documento'].'3')->getValue();
    }

    /** @return array<string, string> */
    private function columnasDe($hoja): array
    {
        $columnas = [];

        foreach ($hoja->getRowIterator(2, 2) as $fila) {
            foreach ($fila->getCellIterator() as $celda) {
                $titulo = trim((string) $celda->getValue());

                if ($titulo !== '') {
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
