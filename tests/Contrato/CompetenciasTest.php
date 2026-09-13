<?php

namespace Tests\Contrato;

use App\Support\Autoriza;
use App\Support\CatalogoDelMen;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * Las siete rutas de `competencias` — la **Fase 2** de
 * [35](../../docs/migracion/35-el-modelo-de-evaluacion-del-colegio.md).
 *
 * ## Qué existe esto para cazar, y ninguno de los cinco da error solo
 *
 * **1. Que cualquier docente escriba el plan de área del colegio.** El guard de
 * la ruta es `auth.personal`, que cierra la puerta a alumnos y acudientes **y a
 * nadie más**: un profesor pasa. Lo que lo para es
 * `Autoriza::puedeEditarPlantillaNotas`, y va DENTRO del método. Sin el caso del
 * docente llano, quitarlo del controlador no pondría **nada** en rojo.
 *
 * **2. El `<=>` convertido en `=`.** Es el fallo más caro de esta fase y **es
 * mudo**: `alumno_id = NULL` no empareja nunca, así que el alumno normal se
 * quedaría sin competencias **en 200 y con la lista vacía**. Nadie vería un
 * error; el boletín saldría sin el bloque. Por eso hay un caso que pide las de un
 * alumno **normal** y exige que reciba las del grupo — con `=` ése es el único
 * que cae.
 *
 * **3. Adoptar dos veces duplicando.** D11 dice que *«lo adoptado es suyo»*, o
 * sea que el colegio **edita el texto**. Un dedupe por texto casa la primera vez y
 * deja de casar en cuanto alguien corrige una tilde, y entonces la segunda
 * adopción mete las veinticinco competencias del área otra vez. El caso que lo
 * sujeta **edita antes de repetir**; sin esa edición, el test pasaría con un
 * dedupe roto.
 *
 * **4. Un catálogo que contesta 404 donde el MEN sencillamente no publica.**
 * Religión, Artes, Ed. Física y Tecnología **nacen vacías a propósito** (D11), y
 * eso tiene que **decirse**: 200, lista vacía y motivo. Un 404 mandaría al colegio
 * a buscar una avería que no existe.
 *
 * **5. Un texto cortado en silencio.** El enunciado más largo del catálogo del MEN
 * mide **302 caracteres**, y MySQL aquí **no está en modo estricto**: con
 * `varchar(255)` —que es lo que son las dos tablas de plantilla— habría entrado
 * recortado y devuelto 200. Es la Fase 0 otra vez, y por eso `definicion` nace
 * `text`.
 */
class CompetenciasTest extends CasoDeContrato
{
    /**
     * Las cinco que escriben. **Cinco, no seis**: el plan dice «las seis de
     * escritura» contando el `GET` dentro de las seis rutas del doc 28 §5.2.
     *
     * @return array<string, array{string, string, array<string, mixed>}>
     */
    public static function lasCincoEscrituras(): array
    {
        return [
            'crear' => ['postJson', 'competencias', ['materia_id' => 1, 'definicion' => 'X']],
            'cambiar' => ['putJson', 'competencias/1', ['definicion' => 'X']],
            'borrar' => ['deleteJson', 'competencias/1', []],
            'reordenar' => ['putJson', 'competencias/orden', ['materia_id' => 1, 'orden' => [1]]],
            'copiar' => ['putJson', 'competencias/copiar', [
                'destino' => ['materia_id' => 1, 'grado_id' => 1],
                'origen' => ['tipo' => 'men'],
            ]],
        ];
    }

    #[Test]
    #[DataProvider('lasCincoEscrituras')]
    public function test_un_docente_llano_no_escribe_el_plan_de_area(string $verbo, string $ruta, array $cuerpo): void
    {
        $antes = $this->censoDeCompetencias();

        $r = $this->llamar($verbo, $ruta, $cuerpo, $this->tokenDelPersonalLlano());

        $r->assertStatus(403);
        $this->assertSame($antes, $this->censoDeCompetencias(),
            'Contestó 403 y escribió igual: el criterio frena la respuesta pero no la escritura.');
    }

    /**
     * **Los dos `GET` son 200 para un docente llano, y eso es la otra mitad de la
     * línea.** Un test que sólo comprobara los 403 pasaría también si alguien
     * cerrara la lectura por error, y entonces la Fase 3 se quedaría sin poder
     * colgar un desempeño de una competencia sin dar permiso de configuración a
     * todo el claustro.
     *
     * @return array<string, array{string}>
     */
    public static function losDosGet(): array
    {
        return [
            'la lista del año' => ['competencias'],
            'el catálogo del MEN' => ['competencias/catalogo-men'],
        ];
    }

    #[Test]
    #[DataProvider('losDosGet')]
    public function test_un_docente_llano_si_puede_leer(string $ruta): void
    {
        $materia = $this->materiaLlamada('MATEM');
        $grado = $this->gradoLlamado('Sexto');

        $r = $this->getJson(
            "/api/{$ruta}?materia_id={$materia}&grado_id={$grado}",
            ['Authorization' => 'Bearer '.$this->tokenDelPersonalLlano()]
        );

        $r->assertStatus(200);
    }

    /**
     * **El caso que demuestra que el permiso se lee de verdad.**
     *
     * Con `is_superuser` puesto, las cinco escrituras pasarían aunque
     * `puedeEditarPlantillaNotas` mirase cualquier otra cosa —o nada—. Éste tiene
     * el permiso **y no es superusuario**, que es la única forma de que el verde
     * signifique algo. Y va por rol, que es como el permiso llega al contexto.
     */
    #[Test]
    public function test_con_el_permiso_y_sin_superusuario_si_escribe(): void
    {
        $usuario = $this->usuarioLlanoDelPersonal();

        $this->assertSame(0, (int) $usuario->is_superuser,
            'El sujeto de este test NO puede ser superusuario: con la columna puesta, el verde '.
            'no diría nada sobre el permiso.');

        $this->darPermisoDePlantilla((int) $usuario->id);

        $r = $this->postJson('/api/competencias', [
            'materia_id' => $this->materiaLlamada('MATEM'),
            'definicion' => 'Competencia de prueba con permiso',
        ], ['Authorization' => 'Bearer '.$this->tokenDe($usuario->username)]);

        $r->assertStatus(200);
    }

    // ─────────────────────────────────────────────────────────────────────
    // El catálogo del MEN
    // ─────────────────────────────────────────────────────────────────────

    #[Test]
    public function test_el_catalogo_del_men_trae_las_de_la_materia_y_el_grado(): void
    {
        $r = $this->catalogo($this->materiaLlamada('LENGUA CASTELLANA'), $this->gradoLlamado('Sexto'));

        $r->assertStatus(200);
        $this->assertTrue($r->json('cubierta'));
        $this->assertSame('lenguaje', $r->json('area_men.clave'));
        $this->assertSame('6-7', $r->json('conjunto'));
        $this->assertNull($r->json('motivo'), 'Si trajo competencias, no hay nada que explicar.');

        $conjuntos = array_unique(array_column($r->json('competencias'), 'conjunto'));
        $this->assertSame(['6-7'], array_values($conjuntos),
            'La ruta va por materia Y grado: no puede devolver el corpus de los cinco conjuntos.');
    }

    /**
     * **El caso del plan, literal**: *«de una materia que el MEN no cubre devuelve
     * la lista vacía y dice por qué, no un 404: población, no `OK`»*.
     */
    #[Test]
    public function test_una_materia_que_el_men_no_cubre_da_lista_vacia_y_el_motivo(): void
    {
        $r = $this->catalogo($this->materiaLlamada('RELIGI'), $this->gradoLlamado('Sexto'));

        $r->assertStatus(200);
        $this->assertSame([], $r->json('competencias'));
        $this->assertFalse($r->json('cubierta'));
        $this->assertNull($r->json('area_men'));
        $this->assertSame('sin_estandares', $r->json('emparejamiento.materia'),
            'No es «no te entendí»: es que el MEN no publica estándares de esa área, y la '.
            'pantalla tiene que poder decirlo así.');
        $this->assertStringContainsString('no publica', (string) $r->json('motivo'));
    }

    /**
     * Preescolar tampoco es un fallo: los Estándares Básicos **empiezan en 1.º**.
     * Y sale por un motivo propio, no por el de «no reconocí la materia», porque
     * las dos frases que hay que enseñar son distintas.
     */
    #[Test]
    public function test_preescolar_sale_con_su_propio_motivo(): void
    {
        $r = $this->catalogo($this->materiaLlamada('MATEM'), $this->gradoLlamado('Transición'));

        $r->assertStatus(200);
        $this->assertSame([], $r->json('competencias'));
        $this->assertSame('preescolar', $r->json('emparejamiento.grado'));
        $this->assertStringContainsString('1.º', (string) $r->json('motivo'));
    }

    /**
     * **El grano de Matemáticas no es el de Lenguaje, y la respuesta lo dice.**
     *
     * Es el hallazgo que corrige al plan: sólo Lenguaje, las dos Ciencias y
     * Ciudadanas publican «enunciado identificador». Matemáticas e Inglés traen el
     * eje y sus estándares, y **lo que se adopta son los ejes** — si no, adoptar
     * Inglés de 10.º-11.º metería cuarenta y nueve filas donde el colegio quería
     * cinco.
     */
    #[Test]
    public function test_matematicas_trae_ejes_y_estandares_y_solo_se_adoptan_los_ejes(): void
    {
        $r = $this->catalogo($this->materiaLlamada('MATEM'), $this->gradoLlamado('Sexto'));

        $r->assertStatus(200);
        $this->assertSame(5, $r->json('por_tipo.eje'), 'Son los cinco pensamientos del MEN.');
        $this->assertGreaterThan(20, $r->json('por_tipo.estandar'));
        $this->assertSame(5, $r->json('adoptables'));
        $this->assertSame(['enunciado', 'eje'], $r->json('tipos_que_se_adoptan'));
    }

    #[Test]
    public function test_el_catalogo_exige_materia_y_grado(): void
    {
        $this->pedir('getJson', 'competencias/catalogo-men')->assertStatus(422);
        $this->pedir('getJson', 'competencias/catalogo-men?materia_id='.$this->materiaLlamada('MATEM'))
            ->assertStatus(422);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Adoptar
    // ─────────────────────────────────────────────────────────────────────

    /**
     * **Adoptar dos veces no duplica, Y el segundo intento va con el texto
     * editado.**
     *
     * Sin esa edición en medio, un dedupe por comparación de cadenas pasaría este
     * test y **se rompería el primer día**: D11 dice que lo adoptado es del colegio
     * y que desde ahí lo edita. Lo que sobrevive a la edición es `codigo_men`.
     */
    #[Test]
    public function test_adoptar_dos_veces_no_duplica_ni_despues_de_editar_el_texto(): void
    {
        $materia = $this->materiaLlamada('LENGUA CASTELLANA');
        $grado = $this->gradoLlamado('Sexto');

        $primera = $this->adoptar($materia, $grado);
        $primera->assertStatus(200);

        $cuantas = $primera->json('copiadas');
        $this->assertGreaterThan(0, $cuantas, 'Lenguaje de 6.º sí está en el catálogo del MEN.');
        $this->assertSame($cuantas, $primera->json('revisadas'));
        $this->assertSame(0, $primera->json('saltadas_por_duplicado'));

        // El colegio corrige una tilde: a partir de aquí, comparar textos ya no
        // casa con el catálogo.
        $suya = DB::selectOne('SELECT id, codigo_men FROM competencias
                                WHERE materia_id = ? AND grado_id = ? AND deleted_at IS NULL
                                ORDER BY id LIMIT 1', [$materia, $grado]);
        $this->assertNotNull($suya->codigo_men, 'Lo adoptado guarda de qué enunciado del MEN nació.');

        $this->pedir('putJson', 'competencias/'.$suya->id, [
            'definicion' => 'Texto que el colegio reescribió a su manera.',
        ])->assertStatus(200);

        $segunda = $this->adoptar($materia, $grado);
        $segunda->assertStatus(200);

        $this->assertSame(0, $segunda->json('copiadas'));
        $this->assertSame($cuantas, $segunda->json('saltadas_por_duplicado'));

        $this->assertSame($cuantas, $this->cuantasHay($materia, $grado),
            'Adoptar dos veces dejó el doble de filas: el dedupe se hizo por texto y el '.
            'colegio ya lo había editado.');
    }

    /**
     * **Lo adoptado es una copia, no un enlace** (D11, y la regla 1 de la §4 del
     * doc 28). La fila del colegio lleva el texto dentro: nadie vuelve a leer el
     * fichero del MEN para imprimir.
     */
    #[Test]
    public function test_lo_adoptado_es_copia_y_lleva_el_texto_dentro(): void
    {
        $materia = $this->materiaLlamada('LENGUA CASTELLANA');
        $grado = $this->gradoLlamado('Sexto');

        $this->adoptar($materia, $grado)->assertStatus(200);

        $delMen = CatalogoDelMen::competencias('lenguaje', '6-7', CatalogoDelMen::ADOPTABLES);
        $primero = $delMen[0];

        $fila = DB::selectOne('SELECT definicion, codigo_men FROM competencias
                                WHERE codigo_men = ? AND deleted_at IS NULL', [$primero['codigo']]);

        $this->assertNotNull($fila);
        $this->assertSame($primero['definicion'], $fila->definicion);
    }

    /**
     * **El texto de 302 caracteres viaja entero.** Es `FraseLargaEnElBoletinTest`
     * aplicado a este camino: con `definicion varchar(255)` —que es lo que son las
     * dos tablas de plantilla— este caso se ve rojo, y el síntoma sería un 200 con
     * el enunciado del MEN cortado a mitad de palabra.
     */
    #[Test]
    public function test_el_enunciado_mas_largo_del_men_no_se_corta(): void
    {
        $mayor = '';

        foreach (CatalogoDelMen::cargar()['areas'] as $area) {
            foreach ($area['competencias'] as $competencia) {
                if (mb_strlen($competencia['definicion']) > mb_strlen($mayor)) {
                    $mayor = $competencia['definicion'];
                }
            }
        }

        $this->assertGreaterThan(255, mb_strlen($mayor),
            'Si el catálogo dejara de tener ningún texto de más de 255, este caso dejaría de '.
            'medir lo que dice y habría que cambiarle el texto, no borrarlo.');

        $r = $this->pedir('postJson', 'competencias', [
            'materia_id' => $this->materiaLlamada('MATEM'),
            'definicion' => $mayor,
        ]);

        $r->assertStatus(200);
        $this->assertSame($mayor, $r->json('definicion'));
        $this->assertSame($mayor, DB::table('competencias')->where('id', $r->json('id'))->value('definicion'));
    }

    #[Test]
    public function test_copiar_de_otro_grado_trae_los_textos_y_el_origen_no_puede_ser_el_destino(): void
    {
        $materia = $this->materiaLlamada('EDUCACI');
        $sexto = $this->gradoLlamado('Sexto');
        $septimo = $this->gradoLlamado('Séptimo');

        $this->competenciaDePrueba($materia, $sexto, 'Escrita a mano para 6.º');

        $r = $this->pedir('putJson', 'competencias/copiar', [
            'destino' => ['materia_id' => $materia, 'grado_id' => $septimo],
            'origen' => ['tipo' => 'grado', 'grado_id' => $sexto],
        ]);

        $r->assertStatus(200);
        $this->assertSame(1, $r->json('copiadas'));
        $this->assertSame(1, $this->cuantasHay($materia, $septimo));

        $mismo = $this->pedir('putJson', 'competencias/copiar', [
            'destino' => ['materia_id' => $materia, 'grado_id' => $sexto],
            'origen' => ['tipo' => 'grado', 'grado_id' => $sexto],
        ]);

        $mismo->assertStatus(422);
    }

    /**
     * **Un origen que no tiene nada que dar se cuenta, no se calla.** Es
     * `saltadas_sin_catalogo`, el hermano de `saltadas_sin_plantilla` de
     * `putSembrar`: el contador que delata un origen mal dirigido.
     */
    #[Test]
    public function test_un_origen_vacio_se_reporta_en_vez_de_dar_un_200_mudo(): void
    {
        $materia = $this->materiaLlamada('EDUCACI');

        $r = $this->pedir('putJson', 'competencias/copiar', [
            'destino' => ['materia_id' => $materia, 'grado_id' => $this->gradoLlamado('Sexto')],
            'origen' => ['tipo' => 'grado', 'grado_id' => $this->gradoLlamado('Once')],
        ]);

        $r->assertStatus(200);
        $this->assertSame(0, $r->json('revisadas'));
        $this->assertSame(0, $r->json('copiadas'));
        $this->assertSame(1, $r->json('saltadas_sin_catalogo'));
    }

    // ─────────────────────────────────────────────────────────────────────
    // El `<=>`, que es el fallo mudo de esta fase
    // ─────────────────────────────────────────────────────────────────────

    /**
     * **Un alumno normal recibe las del grupo; uno con competencia propia recibe
     * la suya.**
     *
     * Con `alumno_id = ?` en vez de `alumno_id <=> ?` el primero se queda **con la
     * lista vacía y en 200**, que es el fallo que no se delata. Y el segundo
     * demuestra que el alcance se está leyendo de verdad y no devolviéndolo todo.
     */
    #[Test]
    public function test_el_alumno_normal_recibe_las_del_grado_y_el_independiente_las_suyas(): void
    {
        [$grupo, $token] = $this->grupoYPersonal();

        $alumnos = DB::select('SELECT m.alumno_id FROM matriculas m
                                WHERE m.grupo_id = ? AND m.deleted_at IS NULL
                                  AND m.estado IN ("MATR","ASIS","PREM")
                                ORDER BY m.alumno_id LIMIT 2', [$grupo->id]);

        $this->assertCount(2, $alumnos, 'Hacen falta dos alumnos del mismo grupo para este caso.');

        $normal = (int) $alumnos[0]->alumno_id;
        $propio = (int) $alumnos[1]->alumno_id;

        $gradoId = (int) DB::table('grupos')->where('id', $grupo->id)->value('grado_id');
        $periodoId = $this->periodosDelAnioDelGrupo((int) $grupo->id)[0];
        $materia = $this->materiaLlamada('EDUCACI');

        $delGrupo = $this->competenciaDePrueba($materia, $gradoId, 'La del grado entero', (int) $grupo->year_id);
        $suya = $this->competenciaDePrueba($materia, $gradoId, 'La del alumno con PIAR', (int) $grupo->year_id, $propio);

        $this->marcarIndependiente($propio, $periodoId);

        $delNormal = $this->getJson(
            "/api/competencias?alumno_id={$normal}&periodo_id={$periodoId}&materia_id={$materia}",
            ['Authorization' => 'Bearer '.$token]
        );
        $delNormal->assertStatus(200);
        $ids = array_column($delNormal->json('competencias'), 'id');

        $this->assertContains($delGrupo, $ids,
            'El alumno normal se quedó sin las competencias de su grado: es el `= NULL` que no '.
            'empareja nunca y no da ningún error.');
        $this->assertNotContains($suya, $ids, 'Y no recibe la de otro alumno.');
        $this->assertFalse($delNormal->json('independiente'));
        $this->assertNull($delNormal->json('alcance'));

        $delPropio = $this->getJson(
            "/api/competencias?alumno_id={$propio}&periodo_id={$periodoId}&materia_id={$materia}",
            ['Authorization' => 'Bearer '.$token]
        );
        $delPropio->assertStatus(200);
        $idsPropio = array_column($delPropio->json('competencias'), 'id');

        $this->assertTrue($delPropio->json('independiente'));
        $this->assertSame($propio, $delPropio->json('alcance'));
        $this->assertContains($suya, $idsPropio);
        $this->assertNotContains($delGrupo, $idsPropio,
            'Su rejilla va aparte: es lo mismo que hace `unidades` con `alumno_id`.');
    }

    /**
     * **D25 aplicada: acumulan, no compiten.** Una competencia de «todos los
     * grados» y otra del grado le llegan las dos al alumno, que es deliberadamente
     * lo contrario que la plantilla, donde gana la más específica.
     */
    #[Test]
    public function test_la_de_todos_los_grados_y_la_del_grado_llegan_las_dos(): void
    {
        [$grupo, $token] = $this->grupoYPersonal();

        $alumno = (int) DB::table('matriculas')->where('grupo_id', $grupo->id)
            ->whereNull('deleted_at')->whereIn('estado', ['MATR', 'ASIS', 'PREM'])
            ->orderBy('alumno_id')->value('alumno_id');

        $gradoId = (int) DB::table('grupos')->where('id', $grupo->id)->value('grado_id');
        $periodoId = $this->periodosDelAnioDelGrupo((int) $grupo->id)[0];
        $materia = $this->materiaLlamada('EDUCACI');

        $general = $this->competenciaDePrueba($materia, null, 'Para todos los grados', (int) $grupo->year_id);
        $concreta = $this->competenciaDePrueba($materia, $gradoId, 'Sólo para este grado', (int) $grupo->year_id);

        $r = $this->getJson(
            "/api/competencias?alumno_id={$alumno}&periodo_id={$periodoId}&materia_id={$materia}",
            ['Authorization' => 'Bearer '.$token]
        );

        $r->assertStatus(200);
        $ids = array_column($r->json('competencias'), 'id');

        $this->assertContains($general, $ids);
        $this->assertContains($concreta, $ids);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Lo que NO cambia
    // ─────────────────────────────────────────────────────────────────────

    /**
     * **Ningún informe lee `competencias`, y por eso un año sin ninguna da el
     * boletín de hoy.**
     *
     * El plan lo pide como *«un año sin ninguna competencia da la respuesta de
     * boletín idéntica a hoy»*, y eso es cierto **por construcción**: con la tabla
     * vacía no hay nada que leer. Lo que este caso protege es la construcción —que
     * mañana nadie enchufe la tabla a un boletín de los de hoy sin darse cuenta—,
     * que es más fuerte que comparar una respuesta, porque **una comparación con la
     * tabla vacía pasaría igual con el boletín ya enchufado**.
     *
     * El día que llegue el boletín nuevo de la Fase 6, **es él quien se añade a la
     * lista de excepciones**, no esta regla la que se borra.
     *
     * > **Ese día llegó el 13 sep 2026** y la excepción es una y se llama
     * > `BoletinPorCompetenciasController`. La regla sigue entera para los demás: los
     * > tres boletines de hoy **no** leen la tabla, que es lo que sostiene D16 —el
     * > boletín nuevo nace al lado, no encima— y lo que hace que un colegio sin
     * > competencias siga imprimiendo exactamente lo de siempre.
     */
    #[Test]
    public function test_ningun_informe_de_hoy_lee_la_tabla_de_competencias(): void
    {
        $miradas = array_merge(
            glob(app_path('Http/Controllers/Informes/*.php')) ?: [],
            [
                app_path('Models/Unidad.php'),
                app_path('Services/BoletinIndependiente.php'),
                app_path('Http/Controllers/UnidadesController.php'),
                app_path('Http/Controllers/NotasController.php'),
            ]
        );

        // **El boletín nuevo SÍ la lee: es su razón de existir.** Va por nombre y no
        // por una carpeta aparte, para que un informe nuevo que la lea sin decirlo
        // siga saliendo en rojo.
        $elDeLaFase6 = 'BoletinPorCompetenciasController.php';

        $culpables = [];

        foreach ($miradas as $fichero) {
            if (! is_file($fichero) || basename($fichero) === $elDeLaFase6) {
                continue;
            }

            $texto = (string) file_get_contents($fichero);

            // Se busca la TABLA, no la palabra: los boletines hablan de
            // «competencias» en comentarios y en rótulos, y eso no lee nada.
            if (preg_match('/\b(FROM|JOIN|INTO|UPDATE|table\()\s*[\'"`]?competencias\b/i', $texto) === 1) {
                $culpables[] = basename($fichero);
            }
        }

        // Y la excepción se comprueba: si el fichero desaparece o deja de leerla,
        // esta línea avisa en vez de dejar una excepción muerta que tape al siguiente.
        $this->assertTrue(
            is_file(app_path('Http/Controllers/Informes/'.$elDeLaFase6)),
            'La excepción de la Fase 6 apunta a un fichero que ya no existe: quítala.'
        );

        $this->assertSame([], $culpables,
            'Un informe de los de hoy pasó a leer `competencias`: '.implode(', ', $culpables).".\n".
            'Los tres boletines de hoy NO cambian (D16, y la §4 del doc 28). Si esto es a '.
            'propósito, va con su decisión escrita y con las instantáneas regeneradas y leídas.');
    }

    /**
     * `PUT competencias/orden` exige **exactamente** las del grupo: una lista
     * parcial deja huecos y repetidos, que es el estado del que se viene.
     */
    #[Test]
    public function test_reordenar_exige_el_grupo_entero(): void
    {
        $materia = $this->materiaLlamada('EDUCACI');
        $grado = $this->gradoLlamado('Sexto');

        $una = $this->competenciaDePrueba($materia, $grado, 'Primera');
        $otra = $this->competenciaDePrueba($materia, $grado, 'Segunda');

        $this->pedir('putJson', 'competencias/orden', [
            'materia_id' => $materia, 'grado_id' => $grado, 'orden' => [$una],
        ])->assertStatus(422);

        $r = $this->pedir('putJson', 'competencias/orden', [
            'materia_id' => $materia, 'grado_id' => $grado, 'orden' => [$otra, $una],
        ]);

        $r->assertStatus(200);
        $this->assertSame(2, $r->json('reordenadas'));
        $this->assertSame(0, (int) DB::table('competencias')->where('id', $otra)->value('orden'));
        $this->assertSame(1, (int) DB::table('competencias')->where('id', $una)->value('orden'));
    }

    /**
     * Borrar es **lógico**: la Fase 3 va a colgar desempeños de aquí, y un borrado
     * físico los dejaría apuntando a nada.
     */
    #[Test]
    public function test_borrar_manda_a_la_papelera_y_no_borra_la_fila(): void
    {
        $id = $this->competenciaDePrueba($this->materiaLlamada('EDUCACI'), null, 'La que se borra');

        $this->pedir('deleteJson', 'competencias/'.$id)->assertStatus(200);

        $this->assertNotNull(DB::table('competencias')->where('id', $id)->value('deleted_at'));
        $this->assertSame(1, (int) DB::table('competencias')->where('id', $id)->count());
    }

    /** Una competencia de otro año no se toca desde el token de éste. */
    #[Test]
    public function test_no_se_edita_una_competencia_de_otro_anio(): void
    {
        $otroAnio = DB::selectOne('SELECT id FROM years WHERE id <> ? AND deleted_at IS NULL
                                    ORDER BY id LIMIT 1', [$this->anioDelToken()]);

        $this->assertNotNull($otroAnio, 'El seed tiene que traer más de un año para este caso.');

        $ajena = $this->competenciaDePrueba(
            $this->materiaLlamada('EDUCACI'), null, 'De otro año', (int) $otroAnio->id
        );

        $this->pedir('putJson', 'competencias/'.$ajena, ['definicion' => 'No'])->assertStatus(404);
        $this->pedir('deleteJson', 'competencias/'.$ajena)->assertStatus(404);
    }

    // ─────────────────────────────────────────────────────────────────────
    // El emparejador de materias, que adivina y por eso hay que vigilarlo
    // ─────────────────────────────────────────────────────────────────────

    /**
     * **Las dos trampas que este emparejador tuvo de verdad**, medidas contra las
     * 35 materias de la copia de desarrollo el 13 sep 2026.
     *
     * Las dos venían de emparejar por **subcadena** en vez de por palabra, y
     * ninguna daba error: devolvían un área equivocada en 200.
     *
     * @return array<string, array{string, ?string}>
     */
    public static function lasTrampasDelEmparejador(): array
    {
        return [
            // «fisica» está dentro de «EDUCACION FISICA», y las áreas se miraban
            // antes que las no cubiertas: Educación Física acababa en Ciencias
            // Naturales.
            'Educación Física no es Ciencias Naturales' => ['EDUCACION FISICA, RECREACION Y DEPORTES', null],
            // «etica» está dentro de «estética»: la Dimensión Estética acababa en
            // Competencias Ciudadanas.
            'Dimensión Estética no es Ciudadanas' => ['DIMENSIÓN ESTÉTICA', null],
            // Y las que sí tienen que casar, para que arreglar lo de arriba no se
            // lleve por delante lo que funcionaba.
            'la de nombre larguísimo del colegio' => [
                'CIENCIAS SOCIALES, HISTORIA GEOGRAFÍA, CONSTITUCIÓN, POLÍTICA Y DEMOCRACIA',
                'ciencias-sociales',
            ],
            'con dos espacios dentro' => ['CIENCIAS NATURALES  Y EDUCACIÓN AMBIENTAL', 'ciencias-naturales'],
            'Física a secas sí es Ciencias Naturales' => ['FÍSICA', 'ciencias-naturales'],
            'Religión' => ['EDUCACIÓN RELIGIOSA', null],
            'Tecnología' => ['TECNOLOGÍA E INFORMÁTICA', null],
            'Artes' => ['EDUCACION ARTÍSTICA', null],
        ];
    }

    #[Test]
    #[DataProvider('lasTrampasDelEmparejador')]
    public function test_el_emparejador_de_materias_no_se_come_una_palabra_dentro_de_otra(
        string $materia, ?string $esperada): void
    {
        $leido = CatalogoDelMen::areaDeMateria($materia);

        $this->assertSame($esperada, $leido['area'],
            "«{$materia}» se emparejó con «".($leido['area'] ?? 'ninguna').'» por la clave «'
            .($leido['por'] ?? '-').'».');

        if ($esperada === null) {
            $this->assertSame('sin_estandares', $leido['motivo'],
                'Y tiene que salir como «el MEN no publica esto», no como «no te entendí»: '.
                'son dos frases distintas en la pantalla.');
        }
    }

    /**
     * **El fichero de datos también es contrato**, y estas tres cosas se rompen
     * sin que nadie las note al añadir un área o corregir una errata del MEN.
     */
    #[Test]
    public function test_el_catalogo_del_men_cabe_donde_se_guarda_y_no_repite_codigos(): void
    {
        $codigos = [];
        $total = 0;

        foreach (CatalogoDelMen::cargar()['areas'] as $area) {
            foreach ($area['competencias'] as $competencia) {
                $total++;
                $codigos[] = $competencia['codigo'];

                $this->assertLessThanOrEqual(40, strlen($competencia['codigo']),
                    "El código {$competencia['codigo']} no cabe en `competencias.codigo_men`, ".
                    'que es varchar(40).');

                $this->assertLessThanOrEqual(2000, mb_strlen($competencia['definicion']),
                    "El texto de {$competencia['codigo']} pasa del máximo que acepta `POST ".
                    'competencias`, así que adoptarlo daría 422.');
            }
        }

        $this->assertSame(count($codigos), count(array_unique($codigos)),
            'Hay códigos repetidos en el catálogo del MEN, y `codigo_men` es lo único que impide '.
            'que adoptar dos veces duplique después de que el colegio edite el texto.');

        $this->assertGreaterThan(400, $total,
            'El catálogo se quedó corto: son 523 entradas el 13 sep 2026. Si alguien lo recortó '.
            'a propósito, este número se cambia con el motivo al lado.');
    }

    // ─────────────────────────────────────────────────────────────────────
    // Ayudantes
    // ─────────────────────────────────────────────────────────────────────

    /** Con el token de alguien que SÍ puede escribir: el superusuario del seed. */
    private function pedir(string $verbo, string $ruta, array $cuerpo = [])
    {
        return $this->llamar($verbo, $ruta, $cuerpo, $this->tokenDe($this->usuarioDeTipo('Usuario')->username));
    }

    /**
     * **`getJson` no acepta cuerpo y los otros tres sí**, y su segundo parámetro
     * son las cabeceras: pasarle el cuerpo ahí no da un fallo de ruta, da un
     * `TypeError` dentro de `json_encode` que no se parece en nada a la causa.
     */
    private function llamar(string $verbo, string $ruta, array $cuerpo, string $token)
    {
        $cabeceras = ['Authorization' => 'Bearer '.$token];

        if ($verbo === 'getJson') {
            return $this->getJson("/api/{$ruta}", $cabeceras);
        }

        return $this->{$verbo}("/api/{$ruta}", $cuerpo, $cabeceras);
    }

    private function catalogo(int $materiaId, int $gradoId)
    {
        return $this->pedir('getJson', "competencias/catalogo-men?materia_id={$materiaId}&grado_id={$gradoId}");
    }

    private function adoptar(int $materiaId, int $gradoId)
    {
        return $this->pedir('putJson', 'competencias/copiar', [
            'destino' => ['materia_id' => $materiaId, 'grado_id' => $gradoId],
            'origen' => ['tipo' => 'men'],
        ]);
    }

    private function anioDelToken(): int
    {
        return (int) $this->pedir('getJson', 'competencias')->json('year_id');
    }

    private function competenciaDePrueba(int $materiaId, ?int $gradoId, string $texto,
        ?int $yearId = null, ?int $alumnoId = null): int
    {
        return (int) DB::table('competencias')->insertGetId([
            'year_id' => $yearId ?? $this->anioDelToken(),
            'materia_id' => $materiaId,
            'grado_id' => $gradoId,
            'alumno_id' => $alumnoId,
            'definicion' => $texto,
            'orden' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function cuantasHay(int $materiaId, ?int $gradoId): int
    {
        return (int) DB::table('competencias')
            ->where('materia_id', $materiaId)
            ->where(fn ($q) => $gradoId === null ? $q->whereNull('grado_id') : $q->where('grado_id', $gradoId))
            ->whereNull('deleted_at')
            ->count();
    }

    private function censoDeCompetencias(): int
    {
        return (int) DB::table('competencias')->whereNull('deleted_at')->count();
    }

    /**
     * Una materia del seed por un trozo de su nombre.
     *
     * **Se busca por trozo y no por el nombre entero** porque `materias.materia` es
     * texto libre del colegio y en la copia de desarrollo trae cosas como
     * `'CIENCIAS NATURALES  Y EDUCACIÓN AMBIENTAL'`, con dos espacios. Y **falla
     * con nombre** si el seed no la tiene, en vez de devolver la primera que haya:
     * un test que empareja Religión con Matemáticas sale verde y no mide nada.
     */
    private function materiaLlamada(string $trozo): int
    {
        $fila = DB::selectOne('SELECT id FROM materias WHERE materia LIKE ? AND deleted_at IS NULL
                                ORDER BY id LIMIT 1', [$trozo.'%']);

        $this->assertNotNull($fila, "El seed no tiene ninguna materia que empiece por '{$trozo}'.");

        return (int) $fila->id;
    }

    private function gradoLlamado(string $nombre): int
    {
        $fila = DB::selectOne('SELECT id FROM grados WHERE nombre = ? AND deleted_at IS NULL
                                ORDER BY id LIMIT 1', [$nombre]);

        $this->assertNotNull($fila, "El seed no tiene el grado '{$nombre}'.");

        return (int) $fila->id;
    }

    /**
     * El permiso por rol, que es como llega de verdad al contexto. Calcado de
     * `PlantillaNotasTest`, y por el mismo motivo: `test-seed.sql` hace `TRUNCATE`
     * de `permissions`, así que lo que siembre la migración **no sobrevive a
     * construir la base**.
     */
    private function darPermisoDePlantilla(int $userId): void
    {
        $permiso = DB::table('permissions')->where('name', Autoriza::PERMISO_PLANTILLA_NOTAS)->value('id')
            ?? DB::table('permissions')->insertGetId([
                'name' => Autoriza::PERMISO_PLANTILLA_NOTAS,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        $rol = DB::table('roles')->where('name', 'JefeDeAreaDePrueba')->value('id')
            ?? DB::table('roles')->insertGetId([
                'name' => 'JefeDeAreaDePrueba',
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
}
