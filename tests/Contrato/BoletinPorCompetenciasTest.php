<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/**
 * **El boletín por competencias** — la Fase 6 del
 * [35](../../docs/migracion/35-el-modelo-de-evaluacion-del-colegio.md), con D16 y D17.
 *
 * Lo que sujeta, y por qué cada caso está aquí y no en otro sitio:
 *
 *  1. **La forma de la respuesta**, con su instantánea. Son **cinco** posiciones y no
 *     cuatro: la quinta es la población, que es lo que distingue a esta familia de un
 *     «listo».
 *  2. **La competencia arriba, sus desempeños debajo y los sueltos al final** (§4.3 del
 *     plan del front). Es el contrato entero de la maqueta.
 *  3. **D17: con `caritas`, el nivel va en TEXTO.** El caso comprueba que el texto está
 *     **tenga o no** el grupo la columna encendida, porque lo que el Decreto 2247 art. 10
 *     y el 1411/2022 prohíben es el informe que no se puede leer, y una carita sola no
 *     es un informe descriptivo.
 *  4. **`sin_definitiva` y `sin_banda` se cuentan aparte.** Los separó la Fase 4 y aquí
 *     se respetan: son dos causas distintas de un nivel vacío, y el
 *     [36](../../docs/migracion/36-la-nota-decimal-y-las-bandas-enteras.md) midió que la
 *     segunda ya le pasa a cuatro alumnos del año en curso.
 *  5. **Lo que nadie marcó no se imprime** — decisión 10. Sin este caso, el primero que
 *     «mejore» la consulta imprimirá los desempeños del grupo con un nivel calculado al
 *     vuelo, y el boletín afirmará un nivel que nadie miró.
 *  6. **El texto impreso es el congelado**, no el del catálogo de hoy. Es el gemelo de
 *     `test_corregir_el_desempeno_no_cambia_el_texto_ya_guardado` de la Fase 4, aplicado
 *     al camino que imprime.
 *  7. **No escribe.** Un boletín que escribiera al abrirse es la avería que costó las
 *     definitivas del periodo 1 (doc 10 §1.1), y ésta es una familia donde ya pasó.
 */
class BoletinPorCompetenciasTest extends CasoDeContrato
{
    private string $token = '';

    // ── La forma ────────────────────────────────────────────────────────────────

    /**
     * La forma entera, con **los tres casos dentro**.
     *
     * El caso se llena antes de pedir —una competencia con dos desempeños, un desempeño
     * suelto y una frase a mano— porque una instantánea tomada sobre un alumno sin
     * marcas guardaría `competencias: []` y `desempenos_sueltos: []`, y entonces **no
     * defendería nada del bloque nuevo**: es justo el fallo que la Fase 5 encontró en
     * seis de las doce instantáneas de los boletines de hoy.
     */
    public function test_la_respuesta_trae_las_cinco_posiciones(): void
    {
        $caso = $this->unCaso();
        $escala = $this->conLaEscala($caso, [['BAJO', 0, 29], ['ALTO', 30, 100]]);
        $alumno = $caso->alumnos[0];

        $this->conDefinitiva($caso, $alumno, 80);

        $competencia = $this->unaCompetencia($caso, 'Resuelve problemas con números racionales');
        $this->laCelda($caso, $alumno, $this->unDesempeno($caso, 'Identifica fracciones equivalentes', $competencia, 0), $escala['ALTO'], 'ALTO');
        $this->laCelda($caso, $alumno, $this->unDesempeno($caso, 'Opera con fracciones', $competencia, 1), $escala['BAJO'], 'BAJO');
        $this->laCelda($caso, $alumno, $this->unDesempeno($caso, 'Entrega sus trabajos a tiempo', null, 2), $escala['ALTO'], 'ALTO');

        DB::table('frases_asignatura')->insert([
            'alumno_id' => $alumno,
            'asignatura_id' => $caso->asignatura_id,
            'periodo_id' => $caso->periodo_id,
            'frase' => 'Felicitaciones por su desempeño en el periodo.',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $r = $this->pedirDe($caso, [$alumno]);

        $r->assertStatus(200);

        $this->compararConInstantanea(
            'boletines-competencias-detailed-notas',
            $this->formaDeLaTupla($r->json(), ['grupo', 'year', 'alumnos', 'escalas', 'poblacion'])
        );
    }

    public function test_la_ruta_del_grupo_trae_a_todos_los_alumnos(): void
    {
        $caso = $this->unCaso();

        $r = $this->putJson(
            "/api/boletines-competencias/detailed-notas-group/{$caso->grupo_id}",
            [],
            ['Authorization' => 'Bearer '.$this->token]
        );

        $r->assertStatus(200);
        $this->assertCount(3, $r->json()[2], 'La ruta de grupo tiene que traer a los tres alumnos.');
        $this->assertSame(3, $r->json()[4]['alumnos']);
    }

    /**
     * **Pedir uno trae uno**, y no es una perogrullada.
     *
     * `Grupo::alumnos($grupo, $requested_alumnos)` con el segundo argumento no vacío
     * entra por su OTRA rama y devuelve **todos los matriculados vigentes más** los
     * retirados que se le pidan por `matricula_id`: un superconjunto. El filtro lo hace
     * quien llama, y los tres boletines de siempre lo hacen en un segundo `foreach`.
     *
     * Sin ese filtro esta ruta contesta 200 con el grupo entero — y como lleva
     * `boletin.propio`, eso es **el boletín de los treinta compañeros dentro de la
     * cuenta de un acudiente**. Se escribió este caso porque la primera versión de este
     * controlador no lo tenía.
     */
    public function test_pedir_un_alumno_trae_ese_alumno_y_no_el_grupo(): void
    {
        $caso = $this->unCaso();

        $r = $this->pedirDe($caso, [$caso->alumnos[1]]);
        $r->assertStatus(200);

        $this->assertSame(
            [$caso->alumnos[1]],
            array_map(fn ($a) => (int) $a['alumno_id'], $r->json()[2]),
            'La ruta de un alumno no puede devolver el grupo entero.'
        );

        $this->assertSame(1, $r->json()[4]['alumnos'],
            'Y la población es la de lo que se imprime, no la del grupo.');
    }

    /**
     * El puesto se calcula sobre el grupo entero **aunque se pida un alumno**.
     *
     * Un puesto es una posición relativa: calcularlo sobre la lista ya filtrada le
     * daría el primero a cualquiera que pida su propio boletín.
     */
    public function test_el_puesto_sale_del_grupo_entero_y_no_de_lo_que_se_pidio(): void
    {
        $caso = $this->unCaso();
        $this->conLaEscala($caso, [['BAJO', 0, 29], ['ALTO', 30, 100]]);

        $this->conDefinitiva($caso, $caso->alumnos[0], 90);
        $this->conDefinitiva($caso, $caso->alumnos[1], 50);
        $this->conDefinitiva($caso, $caso->alumnos[2], 10);

        $solo = $this->pedirDe($caso, [$caso->alumnos[2]])->json()[2][0];

        $this->assertSame(3, (int) $solo['puesto'],
            'El último de tres sigue siendo el tercero aunque pida su boletín él solo.');
    }

    // ── El árbol: competencia, sus desempeños, y los sueltos al final ───────────

    public function test_la_competencia_va_arriba_sus_desempenos_debajo_y_los_sueltos_al_final(): void
    {
        $caso = $this->unCaso();
        $escala = $this->conLaEscala($caso, [['BAJO', 0, 29], ['ALTO', 30, 100]]);
        $alumno = $caso->alumnos[0];

        $competencia = $this->unaCompetencia($caso, 'Resuelve problemas con números racionales');

        $primero = $this->unDesempeno($caso, 'Identifica fracciones equivalentes', $competencia, 0);
        $segundo = $this->unDesempeno($caso, 'Opera con fracciones', $competencia, 1);
        $suelto = $this->unDesempeno($caso, 'Entrega sus trabajos a tiempo', null, 2);

        // A propósito **en el orden contrario** al que tienen que salir: lo que ordena
        // es `desempenos.orden`, no el orden en que se marcaron las casillas.
        $this->laCelda($caso, $alumno, $segundo, $escala['ALTO'], 'ALTO');
        $this->laCelda($caso, $alumno, $suelto, $escala['BAJO'], 'BAJO');
        $this->laCelda($caso, $alumno, $primero, $escala['ALTO'], 'ALTO');

        $asignatura = $this->laAsignaturaDe($caso, $alumno);

        $this->assertCount(1, $asignatura['competencias']);
        $this->assertSame('Resuelve problemas con números racionales', $asignatura['competencias'][0]['definicion']);

        $this->assertSame(
            ['Identifica fracciones equivalentes', 'Opera con fracciones'],
            array_column($asignatura['competencias'][0]['desempenos'], 'texto'),
            'Los desempeños de una competencia salen por su `orden`, no por el de la marca.'
        );

        $this->assertSame(
            ['Entrega sus trabajos a tiempo'],
            array_column($asignatura['desempenos_sueltos'], 'texto'),
            'Un desempeño sin competencia va al final, no dentro de la primera que haya.'
        );

        $this->assertSame('rejilla', $asignatura['desempenos_sueltos'][0]['origen']);
    }

    public function test_una_frase_escrita_a_mano_sale_suelta_y_se_distingue_de_un_desempeno(): void
    {
        $caso = $this->unCaso();
        $alumno = $caso->alumnos[0];

        // La pantalla de frases de siempre: `desempeno_id` nulo. Son las 12.294 filas
        // que ya hay en `simonbolivar` y ninguna vino de una rejilla.
        DB::table('frases_asignatura')->insert([
            'alumno_id' => $alumno,
            'asignatura_id' => $caso->asignatura_id,
            'periodo_id' => $caso->periodo_id,
            'frase' => 'Felicitaciones por su desempeño en el periodo.',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $asignatura = $this->laAsignaturaDe($caso, $alumno);

        $this->assertSame([], $asignatura['competencias']);
        $this->assertCount(1, $asignatura['desempenos_sueltos']);
        $this->assertSame('frase', $asignatura['desempenos_sueltos'][0]['origen']);
        $this->assertNull($asignatura['desempenos_sueltos'][0]['desempeno_id']);
        $this->assertNull($asignatura['desempenos_sueltos'][0]['nivel'],
            'Una frase a mano nunca tuvo nivel: inventarle uno es afirmar algo que nadie puso.');

        $poblacion = $this->poblacionDe($caso, $alumno);
        $this->assertSame(1, $poblacion['frases_sueltas']);
        $this->assertSame(0, $poblacion['desempenos_sueltos']);
    }

    /**
     * Si el colegio borra la competencia, su desempeño **cae entre los sueltos**.
     *
     * Y no abre un bloque con la cabecera en blanco, que es lo que sale si la consulta
     * decide por `desempenos.competencia_id` en vez de por `competencias.id`: el `left
     * join` filtra `deleted_at IS NULL`, así que con la fila borrada el primero sigue
     * puesto y el segundo viene nulo. Es un renglón de diferencia en el `SELECT` y un
     * título vacío en el papel.
     */
    public function test_si_borran_la_competencia_su_desempeno_cae_entre_los_sueltos(): void
    {
        $caso = $this->unCaso();
        $escala = $this->conLaEscala($caso, [['BAJO', 0, 29], ['ALTO', 30, 100]]);
        $alumno = $caso->alumnos[0];

        $competencia = $this->unaCompetencia($caso, 'La que el colegio va a borrar');
        $desempeno = $this->unDesempeno($caso, 'Su desempeño, que se queda', $competencia);
        $this->laCelda($caso, $alumno, $desempeno, $escala['ALTO'], 'ALTO');

        DB::table('competencias')->where('id', $competencia)->update(['deleted_at' => now()]);

        $asignatura = $this->laAsignaturaDe($caso, $alumno);

        $this->assertSame([], $asignatura['competencias'],
            'Una competencia borrada no abre un bloque con el título vacío.');
        $this->assertSame(
            ['Su desempeño, que se queda'],
            array_column($asignatura['desempenos_sueltos'], 'texto')
        );
        $this->assertSame('ALTO', $asignatura['desempenos_sueltos'][0]['nivel'],
            'Y no pierde su nivel por el camino: el texto está congelado en la celda.');
    }

    // ── D17 · `caritas` ─────────────────────────────────────────────────────────

    public function test_con_caritas_el_nivel_viaja_en_texto_y_el_icono_solo_lo_acompana(): void
    {
        $caso = $this->unCaso();
        $escala = $this->conLaEscala($caso, [['BAJO', 0, 29], ['SUPERIOR', 30, 100]]);

        DB::table('escalas_de_valoracion')->where('id', $escala['SUPERIOR'])
            ->update(['icono_infantil' => 'carita-feliz.png', 'icono_adolescente' => 'estrella.png']);

        DB::table('grupos')->where('id', $caso->grupo_id)->update(['caritas' => 1]);

        $alumno = $caso->alumnos[0];
        $desempeno = $this->unDesempeno($caso, 'Comparte con sus compañeros');
        $this->laCelda($caso, $alumno, $desempeno, $escala['SUPERIOR'], 'SUPERIOR');

        $r = $this->pedirDe($caso, [$alumno]);
        $fila = $this->laAsignaturaDe($caso, $alumno, $r)['desempenos_sueltos'][0];

        $this->assertTrue($r->json()[0]['caritas'] == 1, 'El grupo tiene que decir que lleva caritas.');
        $this->assertTrue($r->json()[4]['caritas']);

        // **Lo que exige D17**: el texto, siempre.
        $this->assertSame('SUPERIOR', $fila['nivel']);
        // Y el icono al lado, nunca solo.
        $this->assertSame('carita-feliz.png', $fila['icono_infantil']);
        $this->assertSame('estrella.png', $fila['icono_adolescente']);
    }

    public function test_sin_caritas_el_nivel_sigue_viajando_en_texto_y_el_icono_no(): void
    {
        $caso = $this->unCaso();
        $escala = $this->conLaEscala($caso, [['BAJO', 0, 29], ['SUPERIOR', 30, 100]]);

        DB::table('escalas_de_valoracion')->where('id', $escala['SUPERIOR'])
            ->update(['icono_infantil' => 'carita-feliz.png', 'icono_adolescente' => 'estrella.png']);

        $alumno = $caso->alumnos[0];
        $desempeno = $this->unDesempeno($caso, 'Comparte con sus compañeros');
        $this->laCelda($caso, $alumno, $desempeno, $escala['SUPERIOR'], 'SUPERIOR');

        $fila = $this->laAsignaturaDe($caso, $alumno)['desempenos_sueltos'][0];

        $this->assertSame('SUPERIOR', $fila['nivel'],
            'El texto del nivel no depende de `caritas`: es lo que se imprime en los dos casos.');
        $this->assertNull($fila['icono_infantil']);
        $this->assertNull($fila['icono_adolescente']);
    }

    // ── Los dos motivos de un nivel vacío ───────────────────────────────────────

    public function test_sin_definitiva_y_fuera_de_escala_se_cuentan_por_separado(): void
    {
        $caso = $this->unCaso();
        // Una escala **con un agujero** entre 29 y 30: es la forma que el doc 36 midió
        // en los nueve años del colegio, y es lo único que distingue el caso de una
        // suposición.
        $this->conLaEscala($caso, [['BAJO', 0, 29], ['ALTO', 30, 100]]);

        // El primero no tiene definitiva; el segundo la tiene **dentro del hueco**.
        $this->conDefinitiva($caso, $caso->alumnos[1], 29.5);

        $r = $this->pedirDe($caso, [$caso->alumnos[0], $caso->alumnos[1]]);
        $poblacion = $r->json()[4];

        $this->assertSame(1, $poblacion['asignaturas_sin_definitiva']);
        $this->assertSame(1, $poblacion['asignaturas_sin_banda'],
            'Una nota que no cae en ninguna banda NO es «sin definitiva»: son dos averías distintas.');

        $sin_definitiva = $this->laAsignaturaDe($caso, $caso->alumnos[0], $r);
        $sin_banda = $this->laAsignaturaDe($caso, $caso->alumnos[1], $r);

        $this->assertSame('sin_definitiva', $sin_definitiva['motivo_del_nivel']);
        $this->assertNull($sin_definitiva['nota_asignatura']);

        $this->assertSame('sin_banda', $sin_banda['motivo_del_nivel']);
        $this->assertSame(29.5, $sin_banda['nota_asignatura'],
            'El que cae en el hueco SÍ tiene nota: lo que no tiene es nivel.');
        $this->assertNull($sin_banda['desempenio']);
    }

    // ── Decisión 10 · lo que nadie marcó no se imprime ──────────────────────────

    public function test_un_desempeno_que_nadie_marco_no_se_imprime_y_sale_en_la_cuenta(): void
    {
        $caso = $this->unCaso();
        $escala = $this->conLaEscala($caso, [['BAJO', 0, 29], ['ALTO', 30, 100]]);
        $alumno = $caso->alumnos[0];

        $marcado = $this->unDesempeno($caso, 'El que el docente sí miró', null, 0);
        $this->unDesempeno($caso, 'El que nadie miró', null, 1);

        $this->laCelda($caso, $alumno, $marcado, $escala['ALTO'], 'ALTO');

        $asignatura = $this->laAsignaturaDe($caso, $alumno);

        $this->assertSame(
            ['El que el docente sí miró'],
            array_column($asignatura['desempenos_sueltos'], 'texto'),
            'Ningún boletín afirma un nivel que nadie miró: el desempeño sin celda NO se imprime.'
        );

        $poblacion = $this->poblacionDe($caso, $alumno);

        $this->assertSame(2, $poblacion['desempenos_del_grupo'], 'El denominador son los del grupo.');
        $this->assertSame(1, $poblacion['desempenos_sueltos'] + $poblacion['desempenos_impresos'],
            'Y el numerador, los que están puestos. La distancia entre los dos es la pregunta del colegio.');
    }

    // ── El texto congelado ──────────────────────────────────────────────────────

    public function test_corregir_el_desempeno_en_el_catalogo_no_cambia_el_boletin_ya_impreso(): void
    {
        $caso = $this->unCaso();
        $escala = $this->conLaEscala($caso, [['BAJO', 0, 29], ['ALTO', 30, 100]]);
        $alumno = $caso->alumnos[0];

        $desempeno = $this->unDesempeno($caso, 'Resulve problemas de fracciones');
        $this->laCelda($caso, $alumno, $desempeno, $escala['ALTO'], 'ALTO');

        // El colegio corrige la errata **después** de que la celda se guardó.
        DB::table('desempenos')->where('id', $desempeno)
            ->update(['definicion' => 'Resuelve problemas de fracciones']);

        $fila = $this->laAsignaturaDe($caso, $alumno)['desempenos_sueltos'][0];

        $this->assertSame('Resulve problemas de fracciones', $fila['texto'],
            'Lo que se imprime es la copia congelada, no la definición de hoy: es el papel que ya salió.');
    }

    public function test_renombrar_la_escala_no_cambia_el_nivel_ya_impreso(): void
    {
        $caso = $this->unCaso();
        $escala = $this->conLaEscala($caso, [['BAJO', 0, 29], ['ALTO', 30, 100]]);
        $alumno = $caso->alumnos[0];

        $desempeno = $this->unDesempeno($caso);
        $this->laCelda($caso, $alumno, $desempeno, $escala['ALTO'], 'ALTO');

        DB::table('escalas_de_valoracion')->where('id', $escala['ALTO'])
            ->update(['desempenio' => 'DESTACADO']);

        $fila = $this->laAsignaturaDe($caso, $alumno)['desempenos_sueltos'][0];

        $this->assertSame('ALTO', $fila['nivel'],
            'La tercera columna de la Fase 4 existe para esto: sin ella, renombrar en 2028 cambia un boletín de 2026.');
    }

    // ── `boletin.propio`, y por qué está aquí y no en AutorizacionTest ─────────

    /**
     * **`AutorizacionTest::familiasDeBoletines` no puede cubrir esta familia**, y por
     * eso los tres casos están aquí.
     *
     * Ese proveedor corre tres métodos contra las tres familias de hoy, y el tercero
     * —`test_un_alumno_no_puede_pedir_el_grupo_entero`— pide además
     * `GET {familia}/detailed-notas-year/{grupo}`. **Esta familia no la tiene**: es
     * byte a byte la misma en los tres, nadie la llama, y no calcarla es una de las dos
     * decisiones de la Fase 6. Añadir la familia a ese proveedor haría fallar ese método
     * con un 404 que no dice nada de lo que el método comprueba.
     *
     * Así que el mismo contrato se sujeta aquí, con las dos rutas que sí existen. Si
     * algún día esta familia estrena `detailed-notas-year`, su sitio pasa a ser el
     * proveedor y estos tres casos sobran.
     */
    public function test_un_alumno_no_puede_pedir_el_boletin_de_otro(): void
    {
        [$token, $mio, $otro] = $this->alumnoYCompanero();

        $this->putJson(
            "/api/boletines-competencias/detailed-notas/{$mio->grupo_id}",
            ['requested_alumnos' => [['alumno_id' => $otro->alumno_id, 'matricula_id' => $otro->matricula_id]]],
            ['Authorization' => 'Bearer '.$token]
        )->assertStatus(403)->assertJsonPath('message', 'No puedes ver el de otros');
    }

    public function test_un_alumno_si_puede_pedir_el_suyo(): void
    {
        [$token, $mio] = $this->alumnoYCompanero();

        $r = $this->putJson(
            "/api/boletines-competencias/detailed-notas/{$mio->grupo_id}",
            ['requested_alumnos' => [['alumno_id' => $mio->alumno_id, 'grupo_id' => $mio->grupo_id]]],
            ['Authorization' => 'Bearer '.$token]
        );

        $this->assertNotSame(403, $r->getStatusCode(),
            'El guard rechaza a un alumno pidiendo su propio boletín.');
    }

    public function test_un_alumno_no_puede_pedir_el_grupo_entero_por_ninguna_de_las_dos(): void
    {
        [$token, $mio] = $this->alumnoYCompanero();

        $this->putJson("/api/boletines-competencias/detailed-notas/{$mio->grupo_id}", [],
            ['Authorization' => 'Bearer '.$token])
            ->assertStatus(403)->assertJsonPath('message', 'Pedis más de lo que debes');

        $this->putJson("/api/boletines-competencias/detailed-notas-group/{$mio->grupo_id}", [],
            ['Authorization' => 'Bearer '.$token])
            ->assertStatus(403);
    }

    /** El alumno y su grupo, más un compañero suyo que no es él. */
    private function alumnoYCompanero(): array
    {
        $usuario = $this->usuarioDeTipo('Alumno');

        $mio = DB::select('SELECT a.id alumno_id, m.grupo_id, m.id matricula_id FROM alumnos a
            INNER JOIN matriculas m ON m.alumno_id = a.id
                AND m.estado IN ("MATR","ASIS","PREM") AND m.deleted_at IS NULL
            WHERE a.user_id = ? AND a.deleted_at IS NULL LIMIT 1', [$usuario->id])[0];

        $otro = DB::select('SELECT a.id alumno_id, m.id matricula_id FROM alumnos a
            INNER JOIN matriculas m ON m.alumno_id = a.id AND m.deleted_at IS NULL
            WHERE m.grupo_id = ? AND a.id <> ? AND a.deleted_at IS NULL LIMIT 1',
            [$mio->grupo_id, $mio->alumno_id])[0];

        return [$this->tokenDe($usuario->username), $mio, $otro];
    }

    // ── No escribe ──────────────────────────────────────────────────────────────

    public function test_abrir_el_boletin_no_escribe_ni_una_fila(): void
    {
        $caso = $this->unCaso();
        $escala = $this->conLaEscala($caso, [['BAJO', 0, 29], ['ALTO', 30, 100]]);
        $desempeno = $this->unDesempeno($caso);
        $this->laCelda($caso, $caso->alumnos[0], $desempeno, $escala['ALTO'], 'ALTO');

        $antes = [
            'frases' => DB::table('frases_asignatura')->count(),
            'definitivas' => DB::table('notas_finales')->count(),
        ];

        $this->pedirDe($caso, $caso->alumnos)->assertStatus(200);
        $this->putJson("/api/boletines-competencias/detailed-notas-group/{$caso->grupo_id}", [],
            ['Authorization' => 'Bearer '.$this->token])->assertStatus(200);

        $this->assertSame($antes['frases'], DB::table('frases_asignatura')->count());
        $this->assertSame($antes['definitivas'], DB::table('notas_finales')->count(),
            'Este boletín NO recalcula definitivas: es lo que costó las del periodo 1 (doc 10 §1.1).');
    }

    // ── Andamio ─────────────────────────────────────────────────────────────────

    /**
     * Un grupo propio con su asignatura, tres alumnos y el periodo **del usuario**.
     *
     * El periodo no se elige: este boletín calcula contra `$this->user->periodo_id`,
     * que sale del periodo del usuario y que `Services\Login` reescribe al `actual` en
     * cada inicio de sesión. Elegir aquí «el primero del año» daría una respuesta vacía
     * en 200 y el test pasaría sin haber calculado nada.
     */
    private function unCaso(): object
    {
        $grupoDelSeed = $this->grupoConAlumnos();

        $usuario = DB::selectOne('SELECT u.username FROM users u
            INNER JOIN periodos p ON p.id = u.periodo_id AND p.deleted_at IS NULL
            WHERE u.tipo = "Usuario" AND u.is_active = 1 AND u.deleted_at IS NULL
              AND p.year_id = ? ORDER BY u.id LIMIT 1', [$grupoDelSeed->year_id]);

        $this->assertNotNull($usuario,
            "El seed no tiene ningún Usuario en el año {$grupoDelSeed->year_id}.");

        $this->token = $this->tokenDe($usuario->username);

        /*
         * **El periodo se lee DESPUÉS del login, y de este usuario.** `Services\Login`
         * reescribe `users.periodo_id` al periodo `actual` en cada inicio de sesión, así
         * que el año de antes del login no tiene por qué ser el de después. Este boletín
         * calcula contra `$this->user->periodo_id`: montar el caso en otro año daría una
         * respuesta vacía **en 200** y el test pasaría sin haber calculado nada.
         */
        $contexto = DB::selectOne(
            'SELECT p.id AS periodo_id, p.year_id FROM users u
               INNER JOIN periodos p ON p.id = u.periodo_id AND p.deleted_at IS NULL
              WHERE u.username = ?',
            [$usuario->username]
        );

        $this->assertNotNull($contexto, 'El usuario del caso se quedó sin periodo tras el login.');

        $yearId = (int) $contexto->year_id;

        $molde = DB::selectOne('SELECT grado_id FROM grupos WHERE year_id = ? AND deleted_at IS NULL
                                ORDER BY id LIMIT 1', [$yearId]);

        $this->assertNotNull($molde, "El seed no tiene ningún grupo en el año {$yearId}.");

        $grupoId = (int) DB::table('grupos')->insertGetId([
            'nombre' => 'Grupo del boletín por competencias', 'abrev' => 'BXC', 'year_id' => $yearId,
            'grado_id' => $molde->grado_id, 'orden' => 97, 'caritas' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $asignaturaId = (int) DB::table('asignaturas')->insertGetId([
            'materia_id' => (int) DB::table('materias')->whereNull('deleted_at')->orderBy('id')->value('id'),
            'grupo_id' => $grupoId,
            'profesor_id' => (int) DB::table('profesores')->whereNull('deleted_at')->orderBy('id')->value('id'),
            'orden' => 1, 'creditos' => 5, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $alumnos = array_map(
            fn ($fila) => (int) $fila->id,
            DB::select('SELECT id FROM alumnos WHERE deleted_at IS NULL ORDER BY id LIMIT 3')
        );

        $this->assertCount(3, $alumnos, 'El seed no tiene tres alumnos.');

        foreach ($alumnos as $alumnoId) {
            DB::table('matriculas')->insert([
                'alumno_id' => $alumnoId, 'grupo_id' => $grupoId, 'estado' => 'MATR',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return (object) [
            'year_id' => $yearId,
            'grupo_id' => $grupoId,
            'asignatura_id' => $asignaturaId,
            'periodo_id' => (int) $contexto->periodo_id,
            'alumnos' => $alumnos,
        ];
    }

    /**
     * La escala del año, puesta por este caso.
     *
     * @param  list<array{string, int, int}>  $bandas  nombre, porc_inicial, porc_final
     * @return array<string, int> nombre => id
     */
    private function conLaEscala(object $caso, array $bandas): array
    {
        DB::table('escalas_de_valoracion')->where('year_id', $caso->year_id)
            ->whereNull('deleted_at')->update(['deleted_at' => now()]);

        $ids = [];
        $orden = 0;

        foreach ($bandas as [$nombre, $inicial, $final]) {
            $ids[$nombre] = (int) DB::table('escalas_de_valoracion')->insertGetId([
                'desempenio' => $nombre,
                'valoracion' => mb_substr($nombre, 0, 2),
                'porc_inicial' => $inicial,
                'porc_final' => $final,
                'orden' => $orden++,
                'perdido' => $orden === 1 ? 1 : 0,
                'year_id' => $caso->year_id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $ids;
    }

    private function unaCompetencia(object $caso, string $texto): int
    {
        $asignatura = DB::selectOne('SELECT a.materia_id, g.grado_id FROM asignaturas a
            INNER JOIN grupos g ON g.id = a.grupo_id WHERE a.id = ?', [$caso->asignatura_id]);

        return (int) DB::table('competencias')->insertGetId([
            'year_id' => $caso->year_id,
            'materia_id' => $asignatura->materia_id,
            'grado_id' => $asignatura->grado_id,
            'definicion' => $texto,
            'orden' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function unDesempeno(object $caso, string $texto = 'Desempeño de prueba', ?int $competencia = null, int $orden = 0): int
    {
        return (int) DB::table('desempenos')->insertGetId([
            'asignatura_id' => $caso->asignatura_id,
            'periodo_id' => $caso->periodo_id,
            'competencia_id' => $competencia,
            'definicion' => $texto,
            'orden' => $orden,
            'por_defecto' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Una celda guardada, tal como la deja `PUT desempenos/rejilla` (Fase 4). */
    private function laCelda(object $caso, int $alumnoId, int $desempenoId, int $escalaId, string $nivel): void
    {
        $texto = (string) DB::table('desempenos')->where('id', $desempenoId)->value('definicion');

        DB::table('frases_asignatura')->insert([
            'alumno_id' => $alumnoId,
            'asignatura_id' => $caso->asignatura_id,
            'periodo_id' => $caso->periodo_id,
            // El texto **se copia**, que es lo que protege el boletín ya impreso.
            'frase' => $texto,
            'desempeno_id' => $desempenoId,
            'escala_id' => $escalaId,
            'nivel' => $nivel,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function conDefinitiva(object $caso, int $alumnoId, float $nota): void
    {
        DB::table('notas_finales')->insert([
            'alumno_id' => $alumnoId,
            'asignatura_id' => $caso->asignatura_id,
            'periodo_id' => $caso->periodo_id,
            'nota' => $nota,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @param  list<int>  $alumnos */
    private function pedirDe(object $caso, array $alumnos): TestResponse
    {
        return $this->putJson(
            "/api/boletines-competencias/detailed-notas/{$caso->grupo_id}",
            ['requested_alumnos' => array_map(fn ($id) => ['alumno_id' => $id, 'grupo_id' => $caso->grupo_id], $alumnos)],
            ['Authorization' => 'Bearer '.$this->token]
        );
    }

    /**
     * La asignatura del caso dentro del boletín de un alumno.
     *
     * @return array<string, mixed>
     */
    private function laAsignaturaDe(object $caso, int $alumnoId, ?TestResponse $r = null): array
    {
        $r ??= $this->pedirDe($caso, [$alumnoId]);
        $r->assertStatus(200);

        foreach ($r->json()[2] as $alumno) {
            if ((int) $alumno['alumno_id'] !== $alumnoId) {
                continue;
            }

            foreach ($alumno['asignaturas'] as $asignatura) {
                if ((int) $asignatura['asignatura_id'] === $caso->asignatura_id) {
                    return $asignatura;
                }
            }
        }

        $this->fail("El boletín no trae la asignatura {$caso->asignatura_id} del alumno {$alumnoId}.");
    }

    /** @return array<string, mixed> */
    private function poblacionDe(object $caso, int $alumnoId): array
    {
        return $this->pedirDe($caso, [$alumnoId])->json()[4];
    }
}
