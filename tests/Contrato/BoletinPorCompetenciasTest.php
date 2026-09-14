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

    /**
     * Borrar la competencia del catálogo **no desagrupa un boletín ya impreso**.
     *
     * `2026_09_14_100000_competencia_congelada` arregló el **renombrado** —el texto sale de
     * `frases_asignatura.competencia`— y **no el borrado**: lo que agrupaba seguía siendo
     * `competencias.id`, y el `LEFT JOIN` lo deja en `null` en cuanto la fila entra en la
     * papelera. Un boletín impreso en 2026 con dos desempeños **bajo su competencia** pasaba
     * a imprimirlos **sueltos** en 2028 porque alguien borró una fila del catálogo, que es
     * justo lo que prohíbe el invariante de la §4 del doc 28.
     *
     * Se agrupa por el **texto congelado**, que la celda ya lleva: **sin columna nueva**. Las
     * otras dos salidas que se plantearon eran peores — *aceptar la cabecera huérfana* cambia
     * el papel, y *congelar el id* deja un id que no se puede seguir a ninguna fila.
     *
     * **Y `competencia_id` sale `null`**, no la clave interna: el bloque se imprime, pero el
     * front no puede pedir una competencia que ya no existe y **no se le inventa un id**.
     *
     * ## Sustituye a `test_si_borran_la_competencia_su_desempeno_cae_entre_los_sueltos`
     *
     * Aquel test afirmaba lo contrario —que el desempeño cae a los sueltos— **y tenía razón el
     * día que se escribió**: su motivo, escrito, era *«no abrir un bloque con el título
     * vacío»*, porque sin texto congelado la cabecera salía en blanco. Caer a los sueltos era
     * el mal menor.
     *
     * `2026_09_14_100000_competencia_congelada` **le quitó el motivo esa misma mañana**: hoy
     * la cabecera sale del texto de la celda y no está vacía, así que ya no hay que elegir
     * entre un título en blanco y perder la agrupación. **Su aserción se conserva aquí** —la
     * última— porque lo que quería impedir sigue sin poder pasar.
     *
     * Es la misma forma que el `29,5` de las bandas: **una prueba que envejece con un arreglo,
     * indistinguible de una regresión en el recuento.** Por eso se sustituye con el porqué
     * delante y no se borra en silencio.
     */
    public function test_borrar_la_competencia_no_desagrupa_un_boletin_ya_impreso(): void
    {
        $caso = $this->unCaso();
        $escala = $this->conLaEscala($caso, [['BAJO', 0, 29], ['ALTO', 30, 100]]);
        $alumno = $caso->alumnos[0];

        $competencia = $this->unaCompetencia($caso, 'Comprende el sistema solar');
        $primero = $this->unDesempeno($caso, 'Nombra los planetas', $competencia, 0);
        $segundo = $this->unDesempeno($caso, 'Explica las estaciones', $competencia, 1);

        $this->laCelda($caso, $alumno, $primero, $escala['ALTO'], 'ALTO');
        $this->laCelda($caso, $alumno, $segundo, $escala['ALTO'], 'ALTO');

        // El colegio la borra del catálogo **después** de que el boletín saliera impreso.
        DB::table('competencias')->where('id', $competencia)->update(['deleted_at' => now()]);

        $asignatura = $this->laAsignaturaDe($caso, $alumno);

        $this->assertCount(1, $asignatura['competencias'],
            'La competencia borrada dejó de agrupar y sus desempeños cayeron a los sueltos: '
            .'el boletín impreso cambió porque alguien tocó el catálogo.');

        $this->assertSame('Comprende el sistema solar', $asignatura['competencias'][0]['definicion'],
            'La cabecera tiene que salir del texto congelado en la celda, no del catálogo.');

        $this->assertSame(
            ['Nombra los planetas', 'Explica las estaciones'],
            array_column($asignatura['competencias'][0]['desempenos'], 'texto'),
            'Los dos desempeños siguen juntos y en su orden.'
        );

        $this->assertSame([], $asignatura['desempenos_sueltos'],
            'Ninguno de los dos puede acabar en los sueltos.');

        $this->assertNull($asignatura['competencias'][0]['competencia_id'],
            'Con la competencia borrada el bloque se imprime, pero no se le inventa un id: '
            .'el front no puede seguir uno que ya no existe.');

        $this->assertNotSame('', (string) $asignatura['competencias'][0]['definicion'],
            'Y NO se abre un bloque con el título vacío, que es lo que pasa si la consulta '
            .'decide por `desempenos.competencia_id` en vez de por `competencias.id`. Ésa era '
            .'la preocupación del test al que éste sustituye, y sigue comprobada.');
    }

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

    /**
     * **`sin_definitiva` y `sin_banda` no son la misma avería, y se cuentan aparte.**
     *
     * ## Este caso se remontó el 13 sep 2026, y no para relajarlo
     *
     * Nació montando **BAJO 0-29 · ALTO 30-100** con una definitiva de **29,5**, o sea
     * apoyado en el **hueco de la frontera de enteros**: la nota es `decimal(7,4)` y las
     * bandas son `int`, así que un 29,5 no casaba con ninguna. **`bd02f66` cerró ese
     * hueco** —`porc_inicial <= nota AND nota < porc_final + 1`—, y con la regla nueva
     * **29,5 es BAJO**: `sin_banda` pasó de 1 a 0 y este caso se puso en rojo.
     *
     * **Lo que estaba mal era el montaje, no la afirmación.** El test seguía comprobando
     * que existiera un agujero que se acababa de tapar. Bajar el número esperado a 0
     * habría puesto el verde borrando la mitad de lo que este caso prueba.
     *
     * Así que se remonta sobre las **dos formas de `sin_banda` que sobreviven a
     * `bd02f66`**, y se cubren las dos a la vez porque son distintas y las dos son reales:
     *
     * 1. **un agujero de verdad en la escala** —`[0,59]` y `[61,100]`, con un 60—, que es
     *    algo que el colegio escribió así. Es el montaje que usa
     *    `RejillaPremarcadaTest`, y por eso aquél siguió verde;
     * 2. **una nota por encima del techo** de la banda más alta. Son las **nueve** que
     *    `bd02f66` dejó a la vista al arreglar las otras cuatro: dato malo, no hueco.
     *
     * El caso queda **más fuerte que antes**: un alumno por motivo y `sin_banda = 2`.
     */
    public function test_sin_definitiva_y_fuera_de_escala_se_cuentan_por_separado(): void
    {
        $caso = $this->unCaso();

        // Un agujero **de verdad**: el 60 no lo cubre ninguna de las dos. No es la
        // frontera de enteros —eso lo cerró `bd02f66`—, es lo que el colegio escribió.
        $this->conLaEscala($caso, [['BAJO', 0, 59], ['ALTO', 61, 100]]);

        // El primero no tiene definitiva. El segundo cae en el agujero. El tercero se
        // sale por arriba del techo de la escala.
        $this->conDefinitiva($caso, $caso->alumnos[1], 60);
        $this->conDefinitiva($caso, $caso->alumnos[2], 105);

        $r = $this->pedirDe($caso, $caso->alumnos);
        $poblacion = $r->json()[4];

        $this->assertSame(1, $poblacion['asignaturas_sin_definitiva']);
        $this->assertSame(2, $poblacion['asignaturas_sin_banda'],
            'Una nota que no cae en ninguna banda NO es «sin definitiva»: son dos averías distintas. '
            .'Y las dos formas que quedan tras `bd02f66` —el agujero de verdad y el techo— cuentan las dos.');

        $sin_definitiva = $this->laAsignaturaDe($caso, $caso->alumnos[0], $r);
        $en_el_agujero = $this->laAsignaturaDe($caso, $caso->alumnos[1], $r);
        $por_encima = $this->laAsignaturaDe($caso, $caso->alumnos[2], $r);

        $this->assertSame('sin_definitiva', $sin_definitiva['motivo_del_nivel']);
        $this->assertNull($sin_definitiva['nota_asignatura']);

        // **El `(float)` no es adorno.** La nota viaja como `CAST(… AS DOUBLE)`, pero un
        // 60,0 se serializa en JSON como `60` y vuelve `int`, mientras que el 29,5 del
        // montaje anterior volvía `float`. Es la trampa del tipo que ficha 03-tests.md
        // —«el síntoma era un tipo»—: sin el cast, `assertSame` falla por la forma del
        // número y el mensaje habla del nivel, que es lo que de verdad se comprueba.
        $this->assertSame('sin_banda', $en_el_agujero['motivo_del_nivel']);
        $this->assertSame(60.0, (float) $en_el_agujero['nota_asignatura'],
            'El que cae en el hueco SÍ tiene nota: lo que no tiene es nivel.');
        $this->assertNull($en_el_agujero['desempenio']);

        $this->assertSame('sin_banda', $por_encima['motivo_del_nivel']);
        $this->assertSame(105.0, (float) $por_encima['nota_asignatura']);
        $this->assertNull($por_encima['desempenio'],
            'Por encima del techo tampoco hay banda, y taparlo escondería un dato malo.');
    }

    /**
     * **Y la frontera de enteros ya NO es un `sin_banda`** — `bd02f66`, sitio 14.
     *
     * Es el reverso del caso de arriba y el que impide que este controlador se quede otra
     * vez con la regla vieja: con la escala contigua por enteros y un 29,5, la banda llega
     * **hasta justo antes del primer entero de la siguiente**, así que cae en BAJO.
     *
     * Se comprueban **los dos sitios de la misma respuesta**, y ése es el punto: el nivel
     * de la asignatura sale del `left join` de `Grupo::detailed_materias_notafinal` —que
     * `bd02f66` arregló— y `promedio_desempenio` sale de `bandaDeLaNota()`, que **nació
     * con la regla vieja y se quedó fuera de aquel barrido**. Mientras no coincidieran,
     * el mismo alumno salía BAJO en la asignatura y sin nivel en el promedio, en el mismo
     * papel.
     */
    public function test_la_frontera_de_enteros_ya_no_deja_a_nadie_sin_banda(): void
    {
        $caso = $this->unCaso();
        $this->conLaEscala($caso, [['BAJO', 0, 29], ['ALTO', 30, 100]]);

        $alumno = $caso->alumnos[0];
        $this->conDefinitiva($caso, $alumno, 29.5);

        $r = $this->pedirDe($caso, [$alumno]);
        $asignatura = $this->laAsignaturaDe($caso, $alumno, $r);

        $this->assertSame(0, $r->json()[4]['asignaturas_sin_banda'],
            'Con `nota < porc_final + 1`, un 29,5 entre dos bandas contiguas ya no es un hueco.');
        $this->assertNull($asignatura['motivo_del_nivel']);
        $this->assertSame('BAJO', $asignatura['desempenio'],
            'Y cae en la de abajo: la regla no asciende a nadie.');

        // **Las dos mitades de la respuesta, con la misma regla.** Ésta es la que se
        // quedó atrás: el único alumno del boletín tiene 29,5 de promedio.
        $this->assertSame('BAJO', $r->json()[2][0]['promedio_desempenio'],
            'El promedio usa `bandaDeLaNota()`: si se queda con la regla vieja, el mismo '
            .'alumno sale BAJO en la asignatura y sin nivel en el promedio.');
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

    /**
     * **La que da sentido a la cuarta columna: renombrar la competencia NO cambia la
     * cabecera de un boletín ya impreso.**
     *
     * Es el gemelo exacto de los dos de arriba, un piso más arriba. Hasta esta entrega,
     * la cabecera de cada bloque salía de `competencias.definicion` **leída hoy**: la
     * celda guardaba de qué desempeño salió (`desempeno_id`) y a la competencia se
     * llegaba saltando por `desempenos.competencia_id`, y ese salto termina en una fila
     * **editable** — `CompetenciasController::putUpdate` hace `UPDATE … SET definicion`
     * sobre ella—. O sea que el papel del año pasado empezaba a decir otra cosa.
     *
     * **Se ve rojo quitando el congelado de la lectura**: con `repartirLasMarcas()`
     * dejando `'definicion' => $marca->definicion_competencia` a secas, este caso imprime
     * el texto nuevo. Medido antes de escribir el arreglo, y ésa es la única forma de
     * saber que este caso comprueba algo.
     */
    public function test_renombrar_la_competencia_no_cambia_la_cabecera_ya_impresa(): void
    {
        $caso = $this->unCaso();
        $escala = $this->conLaEscala($caso, [['BAJO', 0, 29], ['ALTO', 30, 100]]);
        $alumno = $caso->alumnos[0];

        $competencia = $this->unaCompetencia($caso, 'Resuelve problemas con números racionales');
        $desempeno = $this->unDesempeno($caso, 'Identifica fracciones equivalentes', $competencia);
        $this->laCelda($caso, $alumno, $desempeno, $escala['ALTO'], 'ALTO');

        // Dos años después el colegio reescribe la competencia en el plan de área.
        DB::table('competencias')->where('id', $competencia)
            ->update(['definicion' => 'Interpreta situaciones con números racionales']);

        $asignatura = $this->laAsignaturaDe($caso, $alumno);

        $this->assertCount(1, $asignatura['competencias']);
        $this->assertSame('Resuelve problemas con números racionales',
            $asignatura['competencias'][0]['definicion'],
            'La cabecera impresa es la copia congelada, no la definición de hoy: es el papel que '
            .'ya salió, y lo que decía no se puede recuperar de ningún otro sitio.');
    }

    /**
     * **Una fila de antes de la cuarta columna imprime la competencia de HOY**, que es lo
     * único que se puede hacer con ella.
     *
     * Las 12.294 filas de `simonbolivar` y todas las celdas guardadas antes de
     * `2026_09_14_100000_competencia_congelada` tienen `competencia` a `null` y **no la
     * van a tener nunca**: la migración es anulable y **sin back-fill** a propósito, porque
     * rellenarlas con el texto de hoy sería afirmar que se guardaron con él.
     *
     * Así que el vivo es el suelo y no el techo, y este caso es el que impide que alguien
     * «limpie» el `definicion` vivo del objeto por redundante.
     */
    public function test_una_celda_de_antes_de_la_columna_imprime_la_competencia_de_hoy(): void
    {
        $caso = $this->unCaso();
        $escala = $this->conLaEscala($caso, [['BAJO', 0, 29], ['ALTO', 30, 100]]);
        $alumno = $caso->alumnos[0];

        $competencia = $this->unaCompetencia($caso, 'La que el colegio tiene escrita hoy');
        $desempeno = $this->unDesempeno($caso, 'Su desempeño de siempre', $competencia);
        $this->laCeldaDeAntes($caso, $alumno, $desempeno, $escala['ALTO'], 'ALTO');

        $asignatura = $this->laAsignaturaDe($caso, $alumno);

        $this->assertSame('La que el colegio tiene escrita hoy',
            $asignatura['competencias'][0]['definicion'],
            'Sin copia congelada no hay nada que preferir, y la cabecera en blanco no es una opción.');
    }

    /**
     * **Y con las dos mezcladas en el mismo bloque, gana la congelada.**
     *
     * Es el estado real del día del despliegue y de todo el periodo siguiente: las celdas
     * de antes sin copia y las de después con ella, **bajo la misma competencia**. La
     * cabecera es una sola, así que hace falta una regla de grupo, y ésta es la que está
     * escrita en `repartirLasMarcas()`: *gana la primera copia congelada que aparezca; el
     * texto vivo, sólo si ninguna de sus celdas tiene*.
     *
     * El caso monta la mezcla **con la vieja primero** —`orden` 0 contra 1—, que es donde
     * un `??` sobre la primera fila daría el texto de hoy y este caso rojo.
     */
    public function test_con_una_celda_vieja_y_una_nueva_gana_el_texto_congelado(): void
    {
        $caso = $this->unCaso();
        $escala = $this->conLaEscala($caso, [['BAJO', 0, 29], ['ALTO', 30, 100]]);
        $alumno = $caso->alumnos[0];

        $competencia = $this->unaCompetencia($caso, 'Como se llamaba cuando se imprimió');
        $vieja = $this->unDesempeno($caso, 'El desempeño de antes', $competencia, 0);
        $nueva = $this->unDesempeno($caso, 'El desempeño de después', $competencia, 1);

        $this->laCeldaDeAntes($caso, $alumno, $vieja, $escala['ALTO'], 'ALTO');
        $this->laCelda($caso, $alumno, $nueva, $escala['ALTO'], 'ALTO');

        DB::table('competencias')->where('id', $competencia)
            ->update(['definicion' => 'Como la reescribieron después']);

        $asignatura = $this->laAsignaturaDe($caso, $alumno);

        $this->assertSame('Como se llamaba cuando se imprimió',
            $asignatura['competencias'][0]['definicion'],
            'Con una sola copia congelada en el bloque ya hay de dónde sacar lo que decía, '
            .'y es lo que manda: el texto vivo es el último recurso, no el primero.');

        $this->assertSame(['El desempeño de antes', 'El desempeño de después'],
            array_column($asignatura['competencias'][0]['desempenos'], 'texto'),
            'Y las dos celdas siguen en el mismo bloque: lo que agrupa es el id, no el texto.');
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

    /**
     * Una celda guardada, **tal como la deja `PUT desempenos/rejilla`**: con sus cuatro
     * copias congeladas, no sólo las tres de la Fase 4.
     *
     * Que este ayudante copie exactamente lo mismo que la ruta es lo que hace que los
     * casos de aquí hablen del camino que imprime y no de un montaje: si se quedara con
     * tres, el caso del renombrado de la competencia **pasaría por el motivo
     * equivocado** —no habría copia que preferir— y daría verde con el boletín roto.
     */
    private function laCelda(object $caso, int $alumnoId, int $desempenoId, int $escalaId, string $nivel): void
    {
        $desempeno = DB::table('desempenos')->where('id', $desempenoId)->first();

        DB::table('frases_asignatura')->insert([
            'alumno_id' => $alumnoId,
            'asignatura_id' => $caso->asignatura_id,
            'periodo_id' => $caso->periodo_id,
            // El texto **se copia**, que es lo que protege el boletín ya impreso.
            'frase' => (string) $desempeno->definicion,
            'desempeno_id' => $desempenoId,
            'escala_id' => $escalaId,
            'nivel' => $nivel,
            // Y la cuarta: **cómo se llamaba su competencia el día que se marcó**. `null`
            // cuando el desempeño no tiene (D10) o cuando la tiene borrada.
            'competencia' => $desempeno->competencia_id === null ? null
                : DB::table('competencias')->where('id', $desempeno->competencia_id)
                    ->whereNull('deleted_at')->value('definicion'),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Una celda escrita **antes** de la cuarta columna: con `competencia` a `null`. */
    private function laCeldaDeAntes(object $caso, int $alumnoId, int $desempenoId, int $escalaId, string $nivel): void
    {
        DB::table('frases_asignatura')->insert([
            'alumno_id' => $alumnoId,
            'asignatura_id' => $caso->asignatura_id,
            'periodo_id' => $caso->periodo_id,
            'frase' => (string) DB::table('desempenos')->where('id', $desempenoId)->value('definicion'),
            'desempeno_id' => $desempenoId,
            'escala_id' => $escalaId,
            'nivel' => $nivel,
            'competencia' => null,
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
