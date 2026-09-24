<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;

/**
 * **El compromiso académico de un alumno**: las decisiones del módulo que costaron
 * tomarse, cada una con un test que se cae si alguien la deshace.
 *
 * No cubre las quince rutas. Cubre **lo que no se puede deducir leyendo el código**
 * y lo que, roto, no daría ningún error: un recuento que cuenta de menos, una nota
 * que se recalcula, un `NULL` que se guarda como `0`, y un acudiente que firma el
 * papel de otro niño cambiando un número en la URL.
 *
 * Diseño entero en `myvc_front/COMPROMISOS-ACADEMICOS.md` §3, §5 y §6.
 *
 * ## LOS DOS ANCLAJES DEL SEED, Y POR QUÉ ÉSTOS
 *
 *     grupo 84   Tercero, año 7 (2024)   grado 8   ← SÍ es del parágrafo de primaria
 *     grupo 98   Cuarto,  año 8 (2025)   grado 9   ← NO lo es
 *
 * Son los dos únicos grupos con alumnos del seed, y por suerte caen uno a cada lado
 * de la frontera de D5, que es justo lo que hace falta para medirla: un test que
 * sólo mirase el grupo de dentro pasaría igual con el parágrafo aplicado a todo el
 * colegio.
 *
 * ## LO QUE ESTE SEED NO PUEDE VER, DICHO AQUÍ Y NO EN UNA NOTA
 *
 * `matriculas` sólo tiene `MATR` y `RETI`: **cero `ASIS`** (03-tests.md). Estos
 * tests entran por `ESTADOS_DE_MATRICULA_VIVA = MATR|ASIS|PREM`, así que ninguno
 * distingue ese predicado de un `MATR` a secas. No es un hueco de estos tests: es
 * del seed, y vale para las 82 apariciones de `ASIS` en `app/`.
 */
class CompromisosDelAlumnoTest extends CasoDeContrato
{
    /** Tercero 2024: grado 8, uno de los tres del parágrafo. */
    private const GRUPO_PRIMARIA = 84;

    private const ANIO_PRIMARIA = 7;

    /** Cuarto 2025: grado 9, básica primaria pero FUERA del parágrafo. */
    private const GRUPO_NO_PRIMARIA = 98;

    private const ANIO_NO_PRIMARIA = 8;

    /** `materias.id` de LENGUA CASTELLANA y MATEMÁTICAS en el seed. */
    private const LENGUAJE = 11;

    private const MATEMATICAS = 15;

    /* ══════════════════════════════════════════════════════════════════════════════
     * D5 — EL PARÁGRAFO DE PRIMARIA
     * ══════════════════════════════════════════════════════════════════════════════ */

    /**
     * **De dónde sale «1.º, 2.º y 3.º», que es el punto entero de D5.**
     *
     * Este test empieza comprobando su propia premisa, y no es ceremonia: si mañana
     * el seed cambia y Primero pasa a tener `orden = 1`, los dos caminos falsos y el
     * bueno darían **el mismo resultado** y todo lo de abajo seguiría en verde sin
     * medir nada. La premisa medida en los tres volcados —`la_hermosa`,
     * `simonbolivar` y `caz_zaragoza`— es que **Primero tiene `orden = 4`**, porque
     * los tres grados de preescolar ocupan del 1 al 3.
     *
     * De ahí los dos caminos que uno escribiría primero y que son falsos:
     *
     *   - `grados.orden <= 3` seleccionaría **prejardín, jardín y transición**: el
     *     parágrafo aplicado a los tres grados equivocados, y sin dar ningún error.
     *   - `grados.abrev IN ('1','2','3')` casa grados por texto libre que teclea el
     *     colegio; en los mismos volcados preescolar lo usa con `'00A'`, `'Pree'`,
     *     `'OOC'` y `'Kin'`.
     *
     * Lo que sí es estructura es `niveles_educativos.orden = 2` —la básica primaria
     * del catálogo del MEN— y, dentro de él, los tres grados de menor `orden`.
     *
     * **Tercero tiene `grados.orden = 6`**, así que este test se pone rojo con
     * cualquiera de los dos caminos falsos: con `orden <= 3` Tercero se quedaría
     * fuera del parágrafo y el alumno volvería a contar sus dos asignaturas.
     */
    #[Test]
    public function test_el_paragrafo_de_primaria_sale_del_nivel_educativo_y_no_del_orden_del_grado(): void
    {
        $tercero = DB::selectOne('SELECT g.orden, n.orden AS nivel_orden
            FROM grupos gr
            INNER JOIN grados g ON g.id = gr.grado_id
            INNER JOIN niveles_educativos n ON n.id = g.nivel_educativo_id
            WHERE gr.id = ?', [self::GRUPO_PRIMARIA]);

        $this->assertSame(2, (int) $tercero->nivel_orden,
            'El grupo de este test ya no está en básica primaria, así que no mide el parágrafo.');

        $this->assertGreaterThan(3, (int) $tercero->orden,
            'La premisa de este test se cayó: Tercero ya no tiene `grados.orden` mayor que 3, '
            .'así que `orden <= 3` y la regla buena darían lo mismo y este test dejaría de '
            .'distinguirlas. Medido el 22 sep 2026 en los tres volcados: preescolar ocupa 1-3 '
            .'y Primero empieza en 4.');

        $token = $this->tokenDeCoordinacion();

        // ── Sin parágrafo: el alumno pierde DOS, inglés y matemáticas ──
        $sinParagrafo = $this->candidatos($token, [
            'year_id' => self::ANIO_PRIMARIA,
            'periodo' => 1,
            'grupo_id' => self::GRUPO_PRIMARIA,
            'regla' => 'asignatura',
            'corte' => 2,
        ]);

        $this->assertCount(1, $sinParagrafo,
            'El seed cambió: este test necesita exactamente un alumno de Tercero con dos '
            .'asignaturas perdidas en el primer periodo, y ya no lo hay.');

        $este = $sinParagrafo[0]['matricula_id'];

        $this->assertSame(2, $sinParagrafo[0]['cantidad_perdidas']);
        $this->assertFalse($sinParagrafo[0]['por_paragrafo_primaria']);

        // ── Con el parágrafo encendido: sólo cuenta matemáticas ──
        $this->encenderElParagrafo(self::ANIO_PRIMARIA);

        $conParagrafo = $this->candidatos($token, [
            'year_id' => self::ANIO_PRIMARIA,
            'periodo' => 1,
            'grupo_id' => self::GRUPO_PRIMARIA,
            'regla' => 'asignatura',
            'corte' => 1,
        ]);

        $suyo = $this->candidatoDe($conParagrafo, $este);

        $this->assertNotNull($suyo,
            'Con el parágrafo encendido el alumno desapareció del todo. El parágrafo acota '
            .'a lenguaje y matemáticas, y éste pierde matemáticas: tiene que seguir saliendo.');

        $this->assertTrue($suyo['por_paragrafo_primaria'],
            'El servidor no marcó el candidato como contado por el parágrafo. La pantalla lo '
            .'rotula con ese campo, y sin él el coordinador ve «pierde 1» sin saber que hay '
            .'siete asignaturas que no se contaron.');

        $this->assertSame(1, $suyo['cantidad_perdidas'],
            'El parágrafo de primaria no filtró: el alumno sigue contando las asignaturas de '
            .'fuera de lenguaje y matemáticas. Si el grado se resolviera por `grados.orden <= 3` '
            .'o por `grados.abrev`, Tercero (orden 6, abrev "3") se quedaría fuera del parágrafo '
            .'y saldría exactamente este número.');

        $this->assertSame([self::MATEMATICAS],
            array_map(fn (array $p): int => $this->materiaDe($p), $suyo['perdidas']),
            'La perdida que quedó no es la de matemáticas, así que el filtro recortó por otro '
            .'criterio que no es el del SIEP.');

        // El corte se aplica DESPUÉS de filtrar, no antes: con corte 2 y el parágrafo
        // encendido este alumno ya no llega, porque sólo se le cuenta una.
        $this->assertSame([], $this->candidatos($token, [
            'year_id' => self::ANIO_PRIMARIA,
            'periodo' => 1,
            'grupo_id' => self::GRUPO_PRIMARIA,
            'regla' => 'asignatura',
            'corte' => 2,
        ]), 'El corte se comparó contra el recuento SIN filtrar. Ese orden le abriría un '
            .'compromiso a un alumno de Tercero por asignaturas que su SIEP no cuenta.');
    }

    /**
     * Cuarto está en básica primaria y **no** entra en el parágrafo.
     *
     * Es la otra mitad, y sin ella el test de arriba pasaría igual con el parágrafo
     * aplicado al colegio entero — que es el fallo simétrico y más caro: dejaría a
     * todo bachillerato con el recuento recortado a dos materias.
     *
     * Cuarto es `grados.orden = 7` y nivel 2: el mismo nivel que Tercero, y por eso
     * no vale con mirar el nivel a secas. Son **los tres de menor orden dentro del
     * nivel**, y éste es el cuarto.
     */
    #[Test]
    public function test_cuarto_no_entra_en_el_paragrafo_aunque_este_en_basica_primaria(): void
    {
        $cuarto = DB::selectOne('SELECT n.orden AS nivel_orden
            FROM grupos gr
            INNER JOIN grados g ON g.id = gr.grado_id
            INNER JOIN niveles_educativos n ON n.id = g.nivel_educativo_id
            WHERE gr.id = ?', [self::GRUPO_NO_PRIMARIA]);

        $this->assertSame(2, (int) $cuarto->nivel_orden,
            'Cuarto ya no está en básica primaria, así que este test dejó de medir la '
            .'distinción que le importa: dentro del MISMO nivel, sólo los tres primeros.');

        $this->encenderElParagrafo(self::ANIO_NO_PRIMARIA);

        $candidatos = $this->candidatos($this->tokenDeCoordinacion(), [
            'year_id' => self::ANIO_NO_PRIMARIA,
            'periodo' => 2,
            'grupo_id' => self::GRUPO_NO_PRIMARIA,
            'regla' => 'asignatura',
            'corte' => 3,
        ]);

        $this->assertNotSame([], $candidatos,
            'Cuarto se quedó sin candidatos con el parágrafo encendido: el filtro se le aplicó '
            .'a un grado que el SIEP no menciona.');

        foreach ($candidatos as $candidato) {
            $this->assertFalse($candidato['por_paragrafo_primaria'],
                'Un alumno de Cuarto salió contado por el parágrafo de primaria.');
        }

        $this->assertGreaterThan(2, max(array_column($candidatos, 'cantidad_perdidas')),
            'A Cuarto se le recortó el recuento a lenguaje y matemáticas. El parágrafo es de '
            .'1.º, 2.º y 3.º; aplicado más arriba deja fuera del compromiso a quien pierde '
            .'siete asignaturas que no son ésas.');
    }

    /**
     * **Encendido sin las dos materias es 422, no «cuenta cero».**
     *
     * La pantalla de reglas ya lo impide al guardar. Se comprueba aquí porque una fila
     * editada a mano en phpMyAdmin se salta aquel 422, y entonces el recuento
     * devolvería **cero candidatos en 1.º, 2.º y 3.º** — que es indistinguible de
     * «este año nadie perdió nada», con el interruptor en «sí» en la pantalla.
     */
    #[Test]
    public function test_el_paragrafo_encendido_sin_las_dos_materias_es_422(): void
    {
        $this->encenderElParagrafo(self::ANIO_PRIMARIA);
        DB::update('UPDATE config_compromiso SET primaria_materia_2_id=NULL WHERE year_id=?',
            [self::ANIO_PRIMARIA]);

        $this->withToken($this->tokenDeCoordinacion())
            ->putJson('/api/compromisos/candidatos', [
                'year_id' => self::ANIO_PRIMARIA,
                'periodo' => 1,
                'grupo_id' => self::GRUPO_PRIMARIA,
                'regla' => 'asignatura',
                'corte' => 1,
            ])
            ->assertStatus(422);
    }

    /* ══════════════════════════════════════════════════════════════════════════════
     * PERDIDA = LO QUE SE IMPRIME
     * ══════════════════════════════════════════════════════════════════════════════ */

    /**
     * **Se pierde por la nota impresa, no por sus decimales** (decisión de producto,
     * 24 sep 2026): con mínima 60, un 59,9 se imprime «60» y no entra en el
     * compromiso; un 59,4 se imprime «59» y sí.
     *
     * La mínima se pone a 60 a mano para usar los números del ejemplo tal cual; el
     * seed califica sobre 50, y con 60 casi todo queda perdido, así que se mira sólo
     * el renglón de la fila tocada. La fila se elige sin gemelas (mismo alumno,
     * asignatura y periodo): una segunda fila con nota baja la haría perder igual.
     */
    #[Test]
    public function test_la_asignatura_se_pierde_por_la_nota_impresa_y_no_por_sus_decimales(): void
    {
        DB::update('UPDATE years SET nota_minima_aceptada = ? WHERE id = ?', ['60', self::ANIO_NO_PRIMARIA]);

        $fila = DB::selectOne('SELECT nf.id, nf.alumno_id, nf.asignatura_id
            FROM notas_finales nf
            INNER JOIN asignaturas a ON a.id = nf.asignatura_id AND a.grupo_id = ? AND a.deleted_at IS NULL
            INNER JOIN matriculas m ON m.alumno_id = nf.alumno_id AND m.grupo_id = a.grupo_id
                AND m.deleted_at IS NULL AND m.estado = "MATR"
            WHERE nf.periodo = 2
              AND NOT EXISTS (SELECT 1 FROM notas_finales o
                              WHERE o.alumno_id = nf.alumno_id AND o.asignatura_id = nf.asignatura_id
                                AND o.periodo = nf.periodo AND o.id <> nf.id)
            ORDER BY nf.id LIMIT 1', [self::GRUPO_NO_PRIMARIA]);

        $this->assertNotNull($fila, 'Cuarto 2025 no tiene ninguna definitiva del periodo 2 en el seed.');

        $token = $this->tokenDeCoordinacion();

        foreach ([['59.9', false], ['59.4', true]] as [$nota, $perdida]) {
            DB::update('UPDATE notas_finales SET nota = ? WHERE id = ?', [$nota, $fila->id]);

            $candidatos = $this->candidatos($token, [
                'year_id' => self::ANIO_NO_PRIMARIA,
                'periodo' => 2,
                'grupo_id' => self::GRUPO_NO_PRIMARIA,
                'regla' => 'asignatura',
                'corte' => 1,
            ]);

            $perdidas = [];

            foreach ($candidatos as $candidato) {
                if ((int) $candidato['alumno_id'] === (int) $fila->alumno_id) {
                    $perdidas = array_merge($perdidas, array_column($candidato['perdidas'], 'asignatura_id'));
                }
            }

            $this->assertSame($perdida, in_array((int) $fila->asignatura_id, $perdidas, true),
                "Con mínima 60, un {$nota} se imprime «".round((float) $nota).'» y '
                .($perdida ? 'SÍ' : 'NO').' tiene que salir como perdida en el compromiso.');
        }
    }

    /* ══════════════════════════════════════════════════════════════════════════════
     * LO QUE SE CONGELA
     * ══════════════════════════════════════════════════════════════════════════════ */

    /**
     * **`nota_al_crear` y `texto` se congelan, y no se vuelven a mirar.**
     *
     * Es el argumento del art. 16 que ya sostuvo `notas_finales.nota_original`: si el
     * papel se reimprime en diciembre y recalcula, dice otra cosa que la copia que el
     * padre firmó en septiembre — y entonces el documento no prueba nada.
     *
     * Por eso el test no lee el código: **cambia el mundo por debajo** —la nota del
     * alumno y el bloque de texto del colegio— y vuelve a pedir el mismo compromiso.
     * Un controlador que leyera datos vivos devolvería lo nuevo, y lo haría con 200.
     */
    #[Test]
    public function test_la_nota_y_el_texto_congelados_no_se_recalculan(): void
    {
        $token = $this->tokenDeCoordinacion();
        $compromiso = $this->crearUnCompromiso($token);

        $antes = $this->withToken($token)->getJson('/api/compromisos/'.$compromiso['id']);
        $antes->assertStatus(200);

        $notaCongelada = $antes->json('items.0.nota_al_crear');
        $textoCongelado = $antes->json('texto');

        $this->assertNotNull($notaCongelada, 'El renglón nació sin nota congelada.');
        $this->assertNotSame('', $textoCongelado, 'El compromiso nació sin el texto del colegio dentro.');

        // El mundo cambia: al alumno le suben la nota y el colegio reescribe su papel.
        DB::update('UPDATE notas_finales nf
            INNER JOIN compromiso_items ci ON ci.asignatura_id = nf.asignatura_id
            INNER JOIN compromisos c ON c.id = ci.compromiso_id
            INNER JOIN matriculas m ON m.id = c.matricula_id AND m.alumno_id = nf.alumno_id
            SET nf.nota = 49
            WHERE c.id = ? AND nf.periodo = c.periodo', [$compromiso['id']]);

        DB::insert('INSERT INTO compromiso_bloques(year_id, clave, orden, activo, titulo, cuerpo, created_at, updated_at)
            VALUES(?,?,?,?,?,?,NOW(),NOW())',
            [self::ANIO_NO_PRIMARIA, 'cita', 1, 1, 'Otra cosa',
                'ESTE TEXTO SE ESCRIBIÓ DESPUÉS DE QUE EL ACUDIENTE FIRMARA.']);

        $despues = $this->withToken($token)->getJson('/api/compromisos/'.$compromiso['id']);
        $despues->assertStatus(200);

        $this->assertSame($notaCongelada, $despues->json('items.0.nota_al_crear'),
            'La nota del papel se recalculó. El acudiente firmó una hoja que decía un número '
            .'y la reimpresión dice otro: a partir de ahí el documento no prueba nada.');

        $this->assertSame($textoCongelado, $despues->json('texto'),
            'El texto del compromiso se volvió a resolver desde `compromiso_bloques`. Un colegio '
            .'que retoque su plantilla en diciembre estaría reescribiendo un documento firmado '
            .'en septiembre.');

        $this->assertStringNotContainsString('DESPUÉS DE QUE EL ACUDIENTE FIRMARA',
            (string) $despues->json('texto'));
    }

    /**
     * **`perN_nota` del periodo del compromiso es igual a `nota_al_crear`.**
     *
     * Las dos columnas salen de dos consultas distintas —una cuenta las perdidas, la
     * otra congela las cuatro notas del año para la casilla `col_periodos`— y el papel
     * las imprime **en el mismo renglón, una al lado de la otra**. Si divergen, el
     * documento se contradice a sí mismo delante de la familia, y lo hace con 200 y
     * sin log.
     *
     * Por eso el controlador no confía en que coincidan: para N = el periodo del
     * compromiso, escribe `nota_al_crear` en la columna. Este test fija ese pegado.
     *
     * ## EL NEGATIVO DE ESTE TEST HACE FALTA EN DOS PASOS, Y ESO ES LO QUE MIDE
     *
     * Quitar el pegado a secas **deja este test en verde**, y no porque esté mal
     * escrito: sobre este seed y con `regla = 'asignatura'` las dos consultas leen la
     * misma fila de `notas_finales`, así que coinciden solas. O sea que el verde de
     * aquí, por sí mismo, no demuestra que el pegado esté haciendo nada.
     *
     * Comprobado el 23 sep 2026 con las dos mitades:
     *
     *     consulta de los cuatro periodos envenenada (+7), pegado puesto   → VERDE
     *     consulta envenenada y pegado quitado                             → ROJO
     *
     * La primera línea es la que vale: con las dos fuentes diciendo cosas distintas,
     * el papel sigue imprimiendo la nota que justifica el renglón. Y lo que el seed
     * no puede enseñar —dos fuentes que divergen **de verdad**, que es lo que pasa en
     * cuanto `congeladoDelLote` no encuentra la clave y devuelve `NULL`— queda dicho
     * aquí en vez de dejarlo en verde como si se hubiera comprobado.
     */
    #[Test]
    public function test_la_nota_del_periodo_es_la_misma_que_la_que_justifica_el_renglon(): void
    {
        $token = $this->tokenDeCoordinacion();
        $compromiso = $this->crearUnCompromiso($token, 2);

        $items = DB::select('SELECT nota_al_crear, per1_nota, per2_nota, per3_nota, per4_nota
            FROM compromiso_items WHERE compromiso_id=? ORDER BY id', [$compromiso['id']]);

        $this->assertNotEmpty($items, 'El compromiso nació sin renglones: no hay nada que comparar.');

        foreach ($items as $item) {
            $this->assertSame((float) $item->nota_al_crear, (float) $item->per2_nota,
                'La columna del segundo periodo y la nota que justifica el renglón dicen números '
                .'distintos. El papel las imprime juntas, así que el documento se contradice en '
                .'la misma línea — y lo hace con 200 y sin log.');
        }
    }

    /* ══════════════════════════════════════════════════════════════════════════════
     * EL VEREDICTO DEL DOCENTE (R3)
     * ══════════════════════════════════════════════════════════════════════════════ */

    /**
     * **`asistio` tiene tres estados, y `null` no es `false`.**
     *
     * Escrito con el `(int) (bool) Request::input('asistio')` que es lo natural —y lo
     * que hacen los interruptores de la configuración— un cuerpo sin la clave se
     * guardaría como **«no asistió»**: la afirmación más cara del documento, puesta
     * por omisión, con autor y fecha, dentro de un papel que se firma.
     *
     * Y el caso no es hipotético: es el botón de D4 —*confirmar lo que propuso la
     * nivelación*—, donde el docente está diciendo algo del resultado y nada de la
     * asistencia.
     *
     * Los cuatro casos van juntos porque el que importa —`null`— sólo significa algo
     * al lado de los otros tres: sin el `false` explícito, un servidor que guardara
     * siempre `NULL` pasaría el primero.
     */
    #[Test]
    public function test_asistio_tiene_tres_estados_y_el_nulo_no_es_un_no(): void
    {
        $item = $this->unItemEntregado();

        $this->veredicto($item, ['resultado' => 'en_espera'])->assertStatus(200);
        $this->assertNull($this->asistioDe($item['item_id']),
            'Un veredicto sin `asistio` se guardó como una respuesta. «No lo he dicho» y «se le '
            .'convocó y no fue» no son lo mismo, y la segunda va impresa en un papel que se firma.');

        $this->veredicto($item, ['resultado' => 'no_nivelo', 'asistio' => false])->assertStatus(200);
        $this->assertSame(0, $this->asistioDe($item['item_id']),
            'Un «no asistió» explícito no se guardó como 0.');

        $this->veredicto($item, ['resultado' => 'nivelo', 'asistio' => true])->assertStatus(200);
        $this->assertSame(1, $this->asistioDe($item['item_id']));

        // Y basura sigue siendo 422: un `"quizá"` no puede caer del lado del `false`.
        $this->veredicto($item, ['resultado' => 'nivelo', 'asistio' => 'quizá'])->assertStatus(422);
        $this->assertSame(1, $this->asistioDe($item['item_id']),
            'El 422 escribió igual: una petición rechazada movió la columna.');
    }

    /**
     * **Una observación larga se recorta, se guarda, y la respuesta lo dice.**
     *
     * Las tres cosas van juntas porque quitar cualquiera deja un fallo distinto y
     * los tres son caros (decisión de Joseth, 23 sep 2026):
     *
     *  - Si **rechazara** —que es lo que hacía— un 422 en mitad de una tanda de
     *    cuarenta filas le tira al docente lo que acaba de escribir.
     *  - Si **no recortara** y lo dejara a la columna, el colegio que tenga
     *    `sql_mode` estricto se come un 500 al guardar un veredicto: MySQL ahí no
     *    trunca, **lanza**. Son dieciséis cuentas de cPanel y no controlamos la
     *    configuración de ninguna.
     *  - Si recortara **en silencio**, media frase acabaría dentro de un papel que
     *    se firma y nadie se enteraría hasta tenerlo impreso delante de la familia.
     *
     * El recorte se comprueba en **caracteres y no en bytes**: el texto va con
     * tildes a propósito, así que un `substr` en vez de `mb_substr` guardaría 255
     * bytes —menos de 255 caracteres— y además partiría el último por la mitad.
     */
    #[Test]
    public function test_una_observacion_larga_se_recorta_y_la_respuesta_lo_dice(): void
    {
        $item = $this->unItemEntregado();

        // 300 caracteres con tilde: más que el tope, y con multibyte dentro.
        $larga = str_repeat('á', 300);

        $r = $this->veredicto($item, ['resultado' => 'no_nivelo', 'observacion' => $larga]);

        $r->assertStatus(200);

        $guardada = DB::selectOne('SELECT observacion FROM compromiso_items WHERE id=?',
            [$item['item_id']])->observacion;

        $this->assertSame(255, mb_strlen((string) $guardada),
            'La observación no se guardó recortada a 255 CARACTERES. Con `substr` en vez de '
            .'`mb_substr` saldrían 255 bytes —127 caracteres y medio— y el último partido.');

        $this->assertSame(mb_substr($larga, 0, 255), $guardada,
            'Lo guardado no es el principio de lo que escribió el docente.');

        $this->assertStringContainsString('recortada', (string) $r->getContent(),
            'El servidor recortó sin decirlo. Recortar en silencio es pérdida de datos, y esto '
            .'acaba en un papel que se firma: el docente tiene que enterarse ahora y no cuando '
            .'lo tenga impreso delante de una familia.');

        // Y una que cabe no se toca ni avisa de nada.
        $corta = 'Asistió a las dos sesiones y entregó el taller.';
        $r = $this->veredicto($item, ['resultado' => 'nivelo', 'observacion' => $corta]);

        $r->assertStatus(200);

        $this->assertSame($corta, DB::selectOne('SELECT observacion FROM compromiso_items WHERE id=?',
            [$item['item_id']])->observacion);

        $this->assertStringNotContainsString('recortada', (string) $r->getContent(),
            'Avisó de un recorte que no hubo: el aviso deja de significar algo.');
    }

    /* ══════════════════════════════════════════════════════════════════════════════
     * LA MÁQUINA DE ESTADOS (§5)
     * ══════════════════════════════════════════════════════════════════════════════ */

    /**
     * **El orden de §5 no es decorativo, y las cuatro fechas son pruebas.**
     *
     * Tres saltos prohibidos, y cada uno protege algo distinto:
     *
     *   - **Cerrar sin entregar** sería certificar el resultado de un apoyo que la
     *     familia nunca supo que existía.
     *   - **Notificar el resultado sin cerrar** arrancaría el plazo de reclamación de
     *     un expediente que el colegio todavía no ha resuelto.
     *   - **Re-entregar** movería `entregado_at` a hoy. El día que el colegio tenga
     *     que sostener ante una tutela que avisó *antes* del plazo, esa fecha es todo
     *     lo que tiene, y un `UPDATE` idempotente la borraría sin dejar rastro.
     *
     * Lo que se comprueba no es el 422: es que **la fecha no se movió**. Un servidor
     * que contestara 422 después de escribir pasaría el `assertStatus` y perdería la
     * prueba igual.
     */
    #[Test]
    public function test_la_maquina_de_estados_no_admite_saltos(): void
    {
        $token = $this->tokenDeCoordinacion();
        $compromiso = $this->crearUnCompromiso($token);
        $id = $compromiso['id'];

        $this->withToken($token)->putJson("/api/compromisos/{$id}/cerrar", [])
            ->assertStatus(422);

        $this->withToken($token)->putJson("/api/compromisos/{$id}/entregar", ['canal' => 'papel'])
            ->assertStatus(200);

        $entregadoAt = DB::selectOne('SELECT entregado_at, estado FROM compromisos WHERE id=?', [$id]);
        $this->assertNotNull($entregadoAt->entregado_at);
        $this->assertSame('entregado', $entregadoAt->estado);

        $this->withToken($token)->putJson("/api/compromisos/{$id}/entregar-resultado", ['canal' => 'papel'])
            ->assertStatus(422);

        $this->assertNull(DB::selectOne('SELECT reclamacion_vence FROM compromisos WHERE id=?', [$id])->reclamacion_vence,
            'Notificar un resultado que no existe arrancó el plazo de reclamación igualmente.');

        // Re-entregar: 422, y la fecha de la primera entrega intacta.
        $this->withToken($token)->putJson("/api/compromisos/{$id}/entregar", ['canal' => 'push'])
            ->assertStatus(422);

        $ahora = DB::selectOne('SELECT entregado_at, entrega_canal FROM compromisos WHERE id=?', [$id]);

        $this->assertSame($entregadoAt->entregado_at, $ahora->entregado_at,
            'La segunda entrega movió `entregado_at`. Esa fecha es la prueba de que el colegio '
            .'avisó, y reescribirla la destruye sin que quede rastro de la original.');

        $this->assertSame('papel', $ahora->entrega_canal,
            'El canal de la primera entrega se reescribió. «Se entregó en mano» y «se mandó un '
            .'push» no valen lo mismo ante una reclamación.');

        // Y con el orden respetado, el camino entero sí llega hasta el final.
        $this->withToken($token)->putJson("/api/compromisos/{$id}/cerrar", [])->assertStatus(200);
        $this->withToken($token)->putJson("/api/compromisos/{$id}/entregar-resultado", ['canal' => 'papel'])
            ->assertStatus(200);

        $final = DB::selectOne('SELECT estado, reclamacion_vence FROM compromisos WHERE id=?', [$id]);

        $this->assertSame('notificado', $final->estado);
        $this->assertNotNull($final->reclamacion_vence,
            'El resultado se notificó sin guardar cuándo vence el plazo para reclamar. Sin esa '
            .'fecha el colegio no puede sostener que venció, y recalcularla en diciembre daría '
            .'una distinta de la impresa.');
    }

    /**
     * **Un compromiso fuera de alcance contesta 404 y no 403.**
     *
     * Es la misma decisión que el reseteo de contraseña con un correo que no existe:
     * distinguir «no existe» de «no es tuyo» deja **averiguar, probando ids, qué
     * alumnos del colegio tienen compromiso**. Es un dato de menores, y con el 403 se
     * obtiene sin leer ni una sola respuesta completa.
     *
     * El 404 se pide con un id **que sí existe**, que es lo único que mide algo: un
     * id inventado da 404 aunque el servidor conteste 403 a lo que no es tuyo.
     */
    #[Test]
    public function test_un_compromiso_fuera_de_alcance_contesta_404_y_no_403(): void
    {
        $compromiso = $this->crearUnCompromiso($this->tokenDeCoordinacion());

        $ajeno = DB::selectOne('SELECT u.username FROM profesores p
            INNER JOIN users u ON u.id = p.user_id AND u.is_active = 1 AND u.deleted_at IS NULL
            WHERE p.deleted_at IS NULL
              AND p.id NOT IN (SELECT DISTINCT profesor_id FROM asignaturas
                               WHERE deleted_at IS NULL AND profesor_id IS NOT NULL)
              AND p.id NOT IN (SELECT titular_id FROM grupos
                               WHERE deleted_at IS NULL AND titular_id IS NOT NULL)
            ORDER BY p.id LIMIT 1');

        $this->assertNotNull($ajeno,
            'El seed no tiene ningún profesor sin asignaturas ni titularidad, así que este test '
            .'no puede montar el caso de «alguien del colegio que no tiene nada que ver».');

        $r = $this->withToken($this->tokenDe($ajeno->username))
            ->getJson('/api/compromisos/'.$compromiso['id']);

        $this->assertSame(404, $r->status(),
            'Un docente ajeno recibió '.$r->status().' en vez de 404. Con un 403 se puede barrer '
            .'la tabla por ids y saber qué alumnos del colegio tienen compromiso sin leer ni una '
            .'respuesta: es un dato de menores y el 403 lo regala.');
    }

    /* ══════════════════════════════════════════════════════════════════════════════
     * LA FIRMA DE LA FAMILIA — EL MÁS IMPORTANTE
     * ══════════════════════════════════════════════════════════════════════════════ */

    /**
     * **Un acudiente no puede firmar el compromiso de otro niño.**
     *
     * Éste es el test más importante del fichero, y lo es por una razón estructural
     * que no se ve leyendo el controlador: **el middleware de la ruta no protege
     * nada aquí**.
     *
     * `PUT compromisos/{id}/acuse` lleva `persona.propia`, y `ExigirPersonaPropia`
     * recoge los identificadores **por su nombre** de una lista de claves de persona
     * —`alumno_id`, `acudiente_id`, `matricula_id`…—. El `{id}` de esta ruta es un
     * **compromiso**, no una persona, así que el guard no reconoce ninguno de sus
     * identificadores y **deja pasar la petición entera**. Es exactamente la forma
     * del IDOR de `images-users/destroy/{id}` (05 §13.2), donde un alumno borraba la
     * foto de cualquiera.
     *
     * O sea que lo único que separa a un acudiente del compromiso del hijo de otro es
     * la comprobación de `CompromisosDeLaFamiliaController::compromisoDelAcudiente()`.
     * **Si eso se rompe, un acudiente firma cambiando un número en la URL** — y firma
     * de verdad: `acuse_at` es una fecha con valor probatorio y no se puede reescribir
     * después.
     *
     * *(Esta misma situación ya la canta `AutorizacionTest::test_el_guard_reconoce_algun_
     * identificador_de_cada_ruta_que_protege`, que hoy está en rojo por estas dos
     * rutas. Este test no la sustituye: aquél dice que el guard no mira nada, y éste
     * que el controlador sí.)*
     */
    #[Test]
    public function test_un_acudiente_no_puede_firmar_el_compromiso_de_otro(): void
    {
        $token = $this->tokenDeCoordinacion();
        $compromiso = $this->crearUnCompromiso($token);
        $id = $compromiso['id'];

        $this->withToken($token)->putJson("/api/compromisos/{$id}/entregar", ['canal' => 'papel'])
            ->assertStatus(200);

        $suyo = $this->acudienteDe((int) $compromiso['alumno_id']);
        $ajeno = $this->acudienteQueNoLoEs((int) $compromiso['alumno_id']);

        // ── El de otro niño: 403, y la firma sigue sin ponerse ──
        $this->withToken($this->tokenDe($ajeno->username))
            ->putJson("/api/compromisos/{$id}/acuse", [])
            ->assertStatus(403);

        $this->assertNull(DB::selectOne('SELECT acuse_at FROM compromisos WHERE id=?', [$id])->acuse_at,
            'El acudiente de OTRO alumno firmó el compromiso. La ruta lleva `persona.propia`, '
            .'pero ese guard busca identificadores de PERSONA y el `{id}` de esta ruta es un '
            .'compromiso: no mira nada. Lo único que estaba cerrando esta puerta era la '
            .'comprobación del controlador, y acaba de dejar de hacerlo.');

        // ── Y el suyo sí, para que el 403 de arriba no sea un «no firma nadie» ──
        $this->withToken($this->tokenDe($suyo->username))
            ->putJson("/api/compromisos/{$id}/acuse", [])
            ->assertStatus(200);

        $firmado = DB::selectOne('SELECT acuse_at, acuse_por, acuse_canal, estado FROM compromisos WHERE id=?', [$id]);

        $this->assertNotNull($firmado->acuse_at,
            'El acudiente del propio alumno tampoco pudo firmar, así que el 403 de arriba no '
            .'demuestra nada: podría ser que no firme nadie.');

        $this->assertSame((int) $suyo->acudiente_id, (int) $firmado->acuse_por,
            'La firma quedó guardada a nombre de otra persona.');

        $this->assertSame('app', $firmado->acuse_canal);

        $this->assertSame('entregado', $firmado->estado,
            'El acuse movió `estado`. `borrador → entregado → cerrado → notificado` es lo que '
            .'hace el COLEGIO con el expediente; el acuse es lo que hace la familia, y son dos '
            .'ejes distintos. Mezclarlos deja de poder contar cuántos están sin firmar (D8).');
    }

    /**
     * Y un alumno no firma su propio compromiso, aunque sea suyo.
     *
     * `persona.propia` lo deja entrar —es su papel— y aquí la puerta es otra: el
     * compromiso lo firma **el acudiente**, que es quien responde por el menor. El
     * caso real es un estudiante de once pulsando el botón desde su cuenta.
     */
    #[Test]
    public function test_un_alumno_no_firma_su_propio_compromiso(): void
    {
        $token = $this->tokenDeCoordinacion();
        $compromiso = $this->crearUnCompromiso($token);
        $id = $compromiso['id'];

        $this->withToken($token)->putJson("/api/compromisos/{$id}/entregar", ['canal' => 'papel'])
            ->assertStatus(200);

        $alumno = DB::selectOne('SELECT u.username FROM users u
            INNER JOIN alumnos a ON a.user_id = u.id AND a.deleted_at IS NULL
            WHERE u.tipo = "Alumno" AND u.is_active = 1 AND u.deleted_at IS NULL AND a.id = ?',
            [$compromiso['alumno_id']]);

        if ($alumno === null) {
            $this->markTestSkipped('El alumno de este compromiso no tiene cuenta en el seed.');
        }

        $this->withToken($this->tokenDe($alumno->username))
            ->putJson("/api/compromisos/{$id}/acuse", [])
            ->assertStatus(403);

        $this->assertNull(DB::selectOne('SELECT acuse_at FROM compromisos WHERE id=?', [$id])->acuse_at);
    }

    /* ══════════════════════════════════════════════════════════════════════════════
     * ANDAMIAJE
     * ══════════════════════════════════════════════════════════════════════════════ */

    /**
     * El token de quien coordina: superusuario, que es el conjunto que abre
     * `Autoriza::puedeCambiarLaNotaNumerica`.
     */
    private function tokenDeCoordinacion(): string
    {
        return $this->tokenDe($this->usuarioDeTipo('Usuario')->username);
    }

    /**
     * Enciende el parágrafo de primaria del año con lenguaje y matemáticas.
     *
     * La fila se crea perezosa, igual que en producción: un colegio que no ha abierto
     * la pantalla de reglas no tiene fila, y su ausencia significa «los defectos».
     */
    private function encenderElParagrafo(int $year_id): void
    {
        if (DB::selectOne('SELECT id FROM config_compromiso WHERE year_id=?', [$year_id]) === null) {
            DB::insert('INSERT INTO config_compromiso(year_id, created_at, updated_at) VALUES(?,NOW(),NOW())',
                [$year_id]);
        }

        DB::update('UPDATE config_compromiso
            SET primaria_activa=1, primaria_materia_1_id=?, primaria_materia_2_id=? WHERE year_id=?',
            [self::LENGUAJE, self::MATEMATICAS, $year_id]);
    }

    /**
     * Los candidatos de una consulta, ya desempaquetados.
     *
     * @param  array<string, mixed>  $cuerpo
     * @return list<array<string, mixed>>
     */
    private function candidatos(string $token, array $cuerpo): array
    {
        $r = $this->withToken($token)->putJson('/api/compromisos/candidatos', $cuerpo);

        $r->assertStatus(200);

        return $r->json('candidatos');
    }

    /**
     * @param  list<array<string, mixed>>  $candidatos
     * @return array<string, mixed>|null
     */
    private function candidatoDe(array $candidatos, int $matricula_id): ?array
    {
        foreach ($candidatos as $candidato) {
            if ((int) $candidato['matricula_id'] === $matricula_id) {
                return $candidato;
            }
        }

        return null;
    }

    /** La materia que hay detrás de una perdida de asignatura. */
    private function materiaDe(array $perdida): int
    {
        return (int) DB::selectOne('SELECT materia_id FROM asignaturas WHERE id=?',
            [$perdida['asignatura_id']])->materia_id;
    }

    /**
     * Crea un compromiso de verdad por la ruta de verdad, sobre Cuarto 2025.
     *
     * Se crea **por el endpoint** y no a mano en la base: lo que estos tests miran es
     * lo que `postStore` congela, y una fila escrita a mano traería lo que escribiera
     * el test en vez de lo que escribe el servidor.
     *
     * @return array{id: int, alumno_id: int, matricula_id: int}
     */
    private function crearUnCompromiso(string $token, int $periodo = 2): array
    {
        $candidatos = $this->candidatos($token, [
            'year_id' => self::ANIO_NO_PRIMARIA,
            'periodo' => $periodo,
            'grupo_id' => self::GRUPO_NO_PRIMARIA,
            'regla' => 'asignatura',
            'corte' => 3,
        ]);

        $this->assertNotSame([], $candidatos,
            'El seed no da ningún candidato en Cuarto 2025 con corte 3 en el periodo '.$periodo.
            ', así que estos tests no tienen sobre qué medir.');

        $candidato = $candidatos[0];

        $r = $this->withToken($token)->postJson('/api/compromisos', [
            'year_id' => self::ANIO_NO_PRIMARIA,
            'periodo' => $periodo,
            'regla' => 'asignatura',
            'corte' => 3,
            'matricula_ids' => [$candidato['matricula_id']],
        ]);

        $r->assertStatus(200);

        $this->assertSame(1, $r->json('creados'),
            'El compromiso no se creó: '.json_encode($r->json('omitidos')));

        return [
            'id' => (int) $r->json('ids.0'),
            'alumno_id' => (int) $candidato['alumno_id'],
            'matricula_id' => (int) $candidato['matricula_id'],
        ];
    }

    /**
     * Un renglón de un compromiso ya entregado, con el docente que puede dictaminarlo.
     *
     * Entregado porque `putVeredicto` rechaza los borradores: sus renglones todavía
     * pueden cambiar. Y el docente es el **titular del grupo**, que es el que D3 deja
     * rellenar los que falten — y el único que el seed garantiza con cuenta activa
     * para cualquier renglón del grupo.
     *
     * @return array{item_id: int, token: string}
     */
    private function unItemEntregado(): array
    {
        $token = $this->tokenDeCoordinacion();
        $compromiso = $this->crearUnCompromiso($token);

        $this->withToken($token)
            ->putJson('/api/compromisos/'.$compromiso['id'].'/entregar', ['canal' => 'papel'])
            ->assertStatus(200);

        $item = DB::selectOne('SELECT id FROM compromiso_items WHERE compromiso_id=? ORDER BY id LIMIT 1',
            [$compromiso['id']]);

        $this->assertNotNull($item, 'El compromiso nació sin renglones.');

        $titular = DB::selectOne('SELECT u.username FROM grupos g
            INNER JOIN profesores p ON p.id = g.titular_id AND p.deleted_at IS NULL
            INNER JOIN users u ON u.id = p.user_id AND u.is_active = 1 AND u.deleted_at IS NULL
            WHERE g.id = ?', [self::GRUPO_NO_PRIMARIA]);

        $this->assertNotNull($titular,
            'El titular de Cuarto 2025 no tiene cuenta activa en el seed, así que no hay nadie '
            .'que pueda firmar un veredicto y este test no puede medir nada.');

        return ['item_id' => (int) $item->id, 'token' => $this->tokenDe($titular->username)];
    }

    /**
     * @param  array{item_id: int, token: string}  $item
     * @param  array<string, mixed>  $cuerpo
     */
    private function veredicto(array $item, array $cuerpo): TestResponse
    {
        return $this->withToken($item['token'])
            ->putJson('/api/compromisos/items/'.$item['item_id'].'/veredicto', $cuerpo);
    }

    /** `asistio` leído de la base, con su `NULL` intacto. */
    private function asistioDe(int $item_id): ?int
    {
        $valor = DB::selectOne('SELECT asistio FROM compromiso_items WHERE id=?', [$item_id])->asistio;

        return $valor === null ? null : (int) $valor;
    }

    /** El acudiente de ese alumno, con cuenta activa. */
    private function acudienteDe(int $alumno_id): object
    {
        $fila = DB::selectOne('SELECT ac.id AS acudiente_id, u.username
            FROM parentescos pa
            INNER JOIN acudientes ac ON ac.id = pa.acudiente_id AND ac.deleted_at IS NULL
            INNER JOIN users u ON u.id = ac.user_id AND u.is_active = 1 AND u.deleted_at IS NULL
            WHERE pa.alumno_id = ? AND pa.deleted_at IS NULL
            ORDER BY ac.id LIMIT 1', [$alumno_id]);

        $this->assertNotNull($fila,
            "El alumno {$alumno_id} no tiene acudiente con cuenta en el seed, así que la mitad "
            .'positiva de este test no se puede montar — y sin ella el 403 no demuestra nada.');

        return $fila;
    }

    /**
     * Un acudiente **que no lo es de ese alumno**, con cuenta activa.
     *
     * La condición va por `NOT EXISTS` sobre `parentescos` y no por «otro id
     * cualquiera»: un alumno puede tener varios acudientes y dos hermanos comparten
     * los suyos, así que «el siguiente de la tabla» acertaría por casualidad.
     */
    private function acudienteQueNoLoEs(int $alumno_id): object
    {
        $fila = DB::selectOne('SELECT ac.id AS acudiente_id, u.username
            FROM acudientes ac
            INNER JOIN users u ON u.id = ac.user_id AND u.is_active = 1 AND u.deleted_at IS NULL
            WHERE ac.deleted_at IS NULL
              AND NOT EXISTS (SELECT 1 FROM parentescos pa
                              WHERE pa.acudiente_id = ac.id AND pa.alumno_id = ?
                                AND pa.deleted_at IS NULL)
              AND EXISTS (SELECT 1 FROM parentescos pa
                          WHERE pa.acudiente_id = ac.id AND pa.deleted_at IS NULL)
            ORDER BY ac.id LIMIT 1', [$alumno_id]);

        $this->assertNotNull($fila,
            'El seed no tiene ningún acudiente de otro alumno con cuenta activa.');

        return $fila;
    }
}
