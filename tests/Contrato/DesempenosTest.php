<?php

namespace Tests\Contrato;

use App\Support\Autoriza;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * Las doce rutas de `desempenos` — la **Fase 3** de
 * [35](../../docs/migracion/35-el-modelo-de-evaluacion-del-colegio.md).
 *
 * ## Qué existe esto para cazar, y ninguno de los cinco da error solo
 *
 * **1. Un candado decorativo.** Poner `por_defecto` sólo en `update` deja abierto
 * el rodeo de siempre —borrar y volver a crear, y la segunda fila nace libre— y
 * **el `update` en verde lo tapa**. Por eso el control no es «el update da 403»
 * sino **«apagar el candado tiene que poner rojos los TRES caminos»**: si sólo cae
 * uno, el rodeo sigue abierto y este fichero no lo diría.
 *
 * **2. Un candado que compara la presencia del campo y no el valor.** Los clientes
 * de esta casa mandan el objeto entero en cada guardado —está escrito en su propio
 * código, `myvc_flutter/lib/Http/UnidadesApi.dart` y
 * `myvc_front/app2/src/app/datos/subunidades.ts`—, así que un candado que mirase si
 * el campo **viene** rechazaría **todos** los guardados, incluidos los que no
 * cambian nada. Eso deja la app vieja de los dieciséis colegios sin poder guardar,
 * y el síntoma sería «no puedo guardar nada» y no «no puedo cambiar esto». Es la
 * trampa (1) de la §5.1.e, y su caso aquí es
 * `test_guardar_sin_cambiar_nada_sigue_dando_200`.
 *
 * **3. El `<=>` convertido en `=`.** `alumno_id = NULL` no empareja nunca, así que
 * el alumno normal se quedaría **sin desempeños, en 200 y con la lista vacía**, y
 * el boletín saldría sin el bloque. Nadie vería un error.
 *
 * **4. Un `sembrar` que se lleve por delante el trabajo del docente**, o el
 * reparto de un estudiante con boletín independiente. Las dos son escrituras
 * masivas y **las dos son mudas**: el 200 no falta nunca. Por eso lo que se mira
 * aquí es **la tabla**, no la respuesta.
 *
 * **5. Un sembrador que aplique la precedencia de la plantilla.** D25 dice que los
 * de «todos los grados» y los del grado **acumulan**, al revés que
 * `AlcanceDeLaPlantilla`, que elige **una grada entera**. Reutilizar aquella clase
 * —que es la tentación, porque el molde es el mismo— **escondería textos que el
 * colegio escribió**, y el docente no vería ningún error: vería menos renglones.
 */
class DesempenosTest extends CasoDeContrato
{
    /**
     * Las siete del colegio: seis sobre el catálogo más `sembrar`.
     *
     * @return array<string, array{string, string, array<string, mixed>}>
     */
    public static function lasSieteDelColegio(): array
    {
        return [
            'leer el plan de área' => ['getJson', 'desempenos/plantilla', []],
            'crear en el plan' => ['postJson', 'desempenos/plantilla', ['materia_id' => 1, 'periodo_id' => 1, 'definicion' => 'X']],
            'cambiar en el plan' => ['putJson', 'desempenos/plantilla/1', ['definicion' => 'X']],
            'borrar del plan' => ['deleteJson', 'desempenos/plantilla/1', []],
            'reordenar el plan' => ['putJson', 'desempenos/plantilla/orden', ['materia_id' => 1, 'periodo_id' => 1, 'orden' => [1]]],
            'copiar el plan' => ['putJson', 'desempenos/plantilla/copiar', [
                'destino' => ['materia_id' => 1, 'periodo_id' => 1],
                'origen' => ['tipo' => 'year', 'year_id' => 1],
            ]],
            'sembrar' => ['putJson', 'desempenos/sembrar', []],
        ];
    }

    #[Test]
    #[DataProvider('lasSieteDelColegio')]
    public function test_un_docente_llano_no_toca_el_plan_de_area(string $verbo, string $ruta, array $cuerpo): void
    {
        $antes = $this->censo();

        $r = $this->llamar($verbo, $ruta, $cuerpo, $this->tokenDelPersonalLlano());

        $r->assertStatus(403);
        $this->assertSame($antes, $this->censo(),
            'Contestó 403 y escribió igual: el criterio frena la respuesta pero no la escritura.');
    }

    // ─────────────────────────────────────────────────────────────────────
    // Sembrar
    // ─────────────────────────────────────────────────────────────────────

    /**
     * **D25 en la única forma en que se puede ver**: la fila de «todos los grados»
     * y la del grado se siembran **las dos**.
     *
     * Se ve roja aplicando la precedencia de la plantilla —una grada entera, la más
     * específica gana—, que es exactamente lo que no hay que reutilizar aquí.
     */
    #[Test]
    public function test_sembrar_acumula_la_de_todos_los_grados_y_la_del_grado(): void
    {
        $caso = $this->unCasoLimpio();

        $this->enElPlan($caso, ['grado_id' => null, 'definicion' => 'Para todos los grados']);
        $this->enElPlan($caso, ['definicion' => 'Sólo para este grado']);

        $r = $this->pedir('putJson', 'desempenos/sembrar');
        $r->assertStatus(200);
        $this->assertGreaterThan(0, $r->json('sembradas'));

        $textos = $this->textosDe($caso);

        $this->assertContains('Para todos los grados', $textos,
            'La de «todos los grados» no se sembró: alguien aplicó la precedencia de la '.
            'plantilla, donde gana la más específica. Aquí ACUMULAN (D25).');
        $this->assertContains('Sólo para este grado', $textos);
        $this->assertSame(2, count($textos),
            'Dos filas de catálogo, dos desempeños sembrados: ni uno de más ni uno de menos.');
    }

    /** Lo sembrado nace con la marca del colegio, que es el candado entero. */
    #[Test]
    public function test_lo_sembrado_nace_con_la_marca_del_colegio(): void
    {
        $caso = $this->unCasoLimpio();
        $this->enElPlan($caso, ['definicion' => 'La del colegio']);

        $this->pedir('putJson', 'desempenos/sembrar')->assertStatus(200);

        $fila = DB::selectOne('SELECT por_defecto FROM desempenos
                                WHERE asignatura_id = ? AND deleted_at IS NULL LIMIT 1',
            [$caso->asignatura_id]);

        $this->assertNotNull($fila);
        $this->assertSame(1, (int) $fila->por_defecto);
    }

    /**
     * **Lo sembrado sale con `orden` 0,1,2… y no con el del catálogo**, y este caso
     * existe porque el fallo contrario **no se ve leyendo y no da ningún error**.
     *
     * D25 junta dos grupos de catálogo en una asignatura, y **cada grupo numera su
     * `orden` desde 0 por su cuenta**. Copiándolo, la asignatura queda con dos filas
     * en la posición 0; y con `orden` duplicado **el docente no puede reordenar
     * nunca**: cualquier lista que mande mueve de sitio una fila del colegio y
     * recibe un 403 que no entiende.
     *
     * Lo destapó la prueba contra el docker —`tools/probar-desempenos-en-el-docker.php`,
     * donde «reordenar sin mover los del colegio» contestaba 403—, no la lectura del
     * código.
     */
    #[Test]
    public function test_lo_sembrado_sale_con_el_orden_renumerado(): void
    {
        $caso = $this->unCasoLimpio();

        // Dos grupos de catálogo distintos, y los dos con `orden` 0: es lo que pasa
        // en cuanto el colegio escribe uno general y uno del grado.
        $this->enElPlan($caso, ['grado_id' => null, 'definicion' => 'Para todos los grados', 'orden' => 0]);
        $this->enElPlan($caso, ['definicion' => 'Sólo para este grado', 'orden' => 0]);

        $this->pedir('putJson', 'desempenos/sembrar')->assertStatus(200);

        $ordenes = array_map(fn ($d) => (int) $d->orden, DB::select(
            'SELECT orden FROM desempenos WHERE asignatura_id = ? AND periodo_id = ?
               AND alumno_id IS NULL AND deleted_at IS NULL ORDER BY orden, id',
            [$caso->asignatura_id, $caso->periodo_id]
        ));

        $this->assertSame([0, 1], $ordenes,
            'Dos filas sembradas con el mismo `orden`: la planilla sale en un orden que depende '.
            'del id y el docente no puede reordenar sin recibir un 403.');
    }

    /** Regla 2: **nada se siembra en un periodo cerrado**, y se cuenta. */
    #[Test]
    public function test_sembrar_con_el_periodo_cerrado_no_escribe_nada(): void
    {
        $caso = $this->unCasoLimpio();
        $this->enElPlan($caso, ['definicion' => 'No debería sembrarse']);

        DB::table('periodos')->where('year_id', $caso->year_id)
            ->update(['profes_pueden_editar_notas' => 0]);

        $r = $this->pedir('putJson', 'desempenos/sembrar');

        $r->assertStatus(200);
        $this->assertSame(0, $r->json('sembradas'));
        $this->assertGreaterThan(0, $r->json('saltadas_por_periodo_cerrado'));
        $this->assertSame(0, $this->cuantosEn($caso));
    }

    /**
     * Regla 4: **nada encima de lo que ya tiene**. Y se reporta, que es lo que
     * distingue «no toqué esa» de «no revisé nada».
     */
    #[Test]
    public function test_sembrar_no_pisa_una_asignatura_que_ya_tiene_desempenos(): void
    {
        $caso = $this->unCasoLimpio();
        $this->enElPlan($caso, ['definicion' => 'Del plan de área']);
        $this->enLaAsignatura($caso, ['definicion' => 'El que escribió el docente']);

        $r = $this->pedir('putJson', 'desempenos/sembrar');

        $r->assertStatus(200);
        $this->assertGreaterThan(0, $r->json('saltadas_por_estructura'));
        $this->assertSame(['El que escribió el docente'], $this->textosDe($caso));
    }

    /**
     * Regla 5: **las filas con dueño se dejan**, y el contador que lo demuestra
     * **sube** — no es uno que valdría cero siempre.
     */
    #[Test]
    public function test_sembrar_respeta_las_filas_con_dueno_y_lo_cuenta(): void
    {
        $caso = $this->unCasoLimpio();
        $this->enElPlan($caso, ['definicion' => 'Del plan de área']);

        $alumno = (int) DB::table('alumnos')->whereNull('deleted_at')->orderBy('id')->value('id');
        $this->enLaAsignatura($caso, ['definicion' => 'El del alumno con PIAR', 'alumno_id' => $alumno]);

        $r = $this->pedir('putJson', 'desempenos/sembrar');

        $r->assertStatus(200);
        $this->assertGreaterThanOrEqual(1, $r->json('independientes_respetadas'));

        // Su fila sigue ahí y la del curso se sembró encima sin tocarla.
        $suyo = DB::table('desempenos')->where('asignatura_id', $caso->asignatura_id)
            ->where('alumno_id', $alumno)->whereNull('deleted_at')->count();
        $this->assertSame(1, $suyo);
    }

    /**
     * **`saltadas_sin_catalogo` es el que delata un catálogo mal dirigido**, y por
     * eso tiene que subir cuando no hay nada que sembrar en vez de dejar un 200
     * mudo.
     */
    #[Test]
    public function test_sin_catalogo_sembrar_lo_cuenta_en_vez_de_callarse(): void
    {
        $caso = $this->unCasoLimpio();

        $r = $this->pedir('putJson', 'desempenos/sembrar');

        $r->assertStatus(200);
        $this->assertGreaterThan(0, $r->json('revisadas'),
            'Un «0 sembradas» con «0 revisadas» significaría «no revisé nada», que es otra cosa.');
        $this->assertSame(0, $r->json('sembradas'));
        $this->assertGreaterThan(0, $r->json('saltadas_sin_catalogo'));
    }

    // ─────────────────────────────────────────────────────────────────────
    // El candado de la D14
    // ─────────────────────────────────────────────────────────────────────

    /**
     * **Los TRES caminos, y van en un proveedor por eso.**
     *
     * El control que los hace valer es «apagar el candado tiene que ponerlos rojos
     * los tres». Cubrir sólo `update` dejaría el rodeo —borrar y volver a crear—
     * abierto **y en verde**.
     *
     * `forcedelete` no está porque **no existe en esta familia**: no hay papelera,
     * así que el rodeo está cerrado por construcción. El día que alguien la añada,
     * este proveedor crece.
     *
     * @return array<string, array{string, string, array<string, mixed>}>
     */
    public static function losTresCaminos(): array
    {
        return [
            'cambiarlo' => ['putJson', '{id}', ['definicion' => 'Lo reescribo yo']],
            'borrarlo' => ['deleteJson', '{id}', []],
            'moverlo de sitio' => ['putJson', 'orden', []],
        ];
    }

    #[Test]
    #[DataProvider('losTresCaminos')]
    public function test_el_docente_no_puede_con_lo_que_sembro_el_colegio(
        string $verbo, string $ruta, array $cuerpo): void
    {
        $caso = $this->unCasoLimpio();
        $delColegio = $this->enLaAsignatura($caso, ['definicion' => 'Del colegio', 'por_defecto' => 1]);
        $suyo = $this->enLaAsignatura($caso, ['definicion' => 'Suyo', 'orden' => 1]);

        if ($ruta === 'orden') {
            $ruta = 'desempenos/orden';
            // Arrastrar el del colegio al segundo sitio: eso SÍ lo mueve.
            $cuerpo = [
                'asignatura_id' => $caso->asignatura_id,
                'periodo_id' => $caso->periodo_id,
                'orden' => [$suyo, $delColegio],
            ];
        } else {
            $ruta = 'desempenos/'.$delColegio;
        }

        $antes = $this->instantaneaDe($delColegio);

        $r = $this->llamar($verbo, $ruta, $cuerpo, $this->tokenDelPersonalLlano());

        $r->assertStatus(403);
        $this->assertSame($antes, $this->instantaneaDe($delColegio),
            'Contestó 403 y escribió igual.');
    }

    /**
     * **El rodeo entero, hecho a propósito y de una vez**: borrar la fila del
     * colegio y volver a crearla con el mismo texto.
     *
     * Es el caso que hace falta escribir aparte de los tres caminos, porque **es el
     * único que enseña para qué sirve candar `destroy`**. Sin el candado ahí, la
     * cadena `DELETE` + `POST` devuelve una fila con el mismo texto y
     * `por_defecto = 0` —**libre**—, y el candado de la D14 queda saltado sin haber
     * tocado una sola ruta prohibida.
     *
     * **Y para esto no hace falta ninguna papelera ni ningún `forcedelete`**: el
     * borrado de aquí es lógico y basta. Decir que «sin borrado físico el rodeo se
     * cierra solo» es falso, y este caso es el que lo demuestra.
     */
    #[Test]
    public function test_el_rodeo_de_borrar_y_volver_a_crear_esta_cerrado(): void
    {
        $caso = $this->unCasoLimpio();
        $delColegio = $this->enLaAsignatura($caso, [
            'definicion' => 'El que puso el colegio', 'por_defecto' => 1,
        ]);
        $token = $this->tokenDelPersonalLlano();

        // Paso 1 del rodeo: borrarlo. Aquí se tiene que acabar.
        $borrado = $this->llamar('deleteJson', 'desempenos/'.$delColegio, [], $token);

        $borrado->assertStatus(403);
        $this->assertNull(DB::table('desempenos')->where('id', $delColegio)->value('deleted_at'),
            'Contestó 403 y lo mandó a la papelera igual.');

        // Paso 2, el que no debe llegar a ocurrir: volver a crearlo. Se comprueba
        // igual, porque lo que hace daño no es el `POST` —el docente puede crear
        // los suyos— sino **que la fila del colegio haya desaparecido antes**.
        $this->assertSame(1,
            (int) DB::table('desempenos')->where('id', $delColegio)->value('por_defecto'),
            'La fila del colegio sigue siendo del colegio.');

        $copia = $this->llamar('postJson', 'desempenos', [
            'asignatura_id' => $caso->asignatura_id,
            'periodo_id' => $caso->periodo_id,
            'definicion' => 'El que puso el colegio',
        ], $token);

        $copia->assertStatus(200);
        $this->assertSame(0, $copia->json('por_defecto'),
            'Lo que crea el docente nace libre — y por eso el rodeo tenía que cortarse en el '.
            'borrado, no aquí.');

        // Y la del colegio sigue viva y candada al lado de la del docente: el
        // docente ha AÑADIDO, no ha sustituido.
        $this->assertSame(2, $this->cuantosEn($caso));
    }

    /**
     * **Y lo que el docente SÍ puede (D14): los suyos.** Sin permiso ninguno, los
     * crea, los edita y los borra.
     */
    #[Test]
    public function test_el_docente_anade_edita_y_borra_los_suyos(): void
    {
        $caso = $this->unCasoLimpio();
        $token = $this->tokenDelPersonalLlano();

        $creado = $this->llamar('postJson', 'desempenos', [
            'asignatura_id' => $caso->asignatura_id,
            'periodo_id' => $caso->periodo_id,
            'definicion' => 'Se me olvidó al área y lo pongo yo',
            'tipo' => 'Saber hacer',
        ], $token);

        $creado->assertStatus(200);
        $this->assertSame(0, $creado->json('por_defecto'),
            'Lo que escribe el docente nace LIBRE: si naciera candado, no podría ni editar lo suyo.');
        $this->assertSame('Saber hacer', $creado->json('tipo'));

        $id = $creado->json('id');

        $this->llamar('putJson', 'desempenos/'.$id, ['definicion' => 'Con otro texto'], $token)
            ->assertStatus(200);
        $this->llamar('deleteJson', 'desempenos/'.$id, [], $token)->assertStatus(200);

        $this->assertNotNull(DB::table('desempenos')->where('id', $id)->value('deleted_at'));
    }

    /**
     * **La trampa (1) de la §5.1.e**: el candado compara **valores**, no la
     * presencia del campo.
     *
     * Los clientes mandan el objeto entero siempre. Un candado que mirase si el
     * campo viene contestaría 403 a un guardado que no cambia nada, y eso deja a la
     * app vieja de los dieciséis colegios sin poder guardar **nunca**.
     */
    #[Test]
    public function test_guardar_sin_cambiar_nada_sigue_dando_200(): void
    {
        $caso = $this->unCasoLimpio();
        $delColegio = $this->enLaAsignatura($caso, [
            'definicion' => 'Del colegio', 'tipo' => 'Saber', 'por_defecto' => 1,
        ]);

        $r = $this->llamar('putJson', 'desempenos/'.$delColegio, [
            // El objeto entero, exactamente como está guardado.
            'definicion' => 'Del colegio',
            'tipo' => 'Saber',
            'orden' => 0,
        ], $this->tokenDelPersonalLlano());

        $r->assertStatus(200);
    }

    /**
     * **Y añadir uno propio al final NO mueve los del colegio**, así que reordenar
     * tiene que seguir funcionando. Es la otra mitad de la trampa (2): el criterio
     * no es «la lista nombra una fila sembrada» —las nombra todas— sino **«la deja
     * en otra posición»**.
     */
    #[Test]
    public function test_reordenar_sin_mover_los_del_colegio_si_se_puede(): void
    {
        $caso = $this->unCasoLimpio();
        $delColegio = $this->enLaAsignatura($caso, ['definicion' => 'Del colegio', 'por_defecto' => 1]);
        $suyo = $this->enLaAsignatura($caso, ['definicion' => 'Suyo', 'orden' => 1]);

        $r = $this->llamar('putJson', 'desempenos/orden', [
            'asignatura_id' => $caso->asignatura_id,
            'periodo_id' => $caso->periodo_id,
            'orden' => [$delColegio, $suyo],
        ], $this->tokenDelPersonalLlano());

        $r->assertStatus(200);
        $this->assertSame(2, $r->json('reordenados'));
    }

    /**
     * **Quien puso el plan de área sí puede corregir una errata en UNA
     * asignatura**, sin cambiar la del colegio entero. Es la cuarta pregunta de la
     * §5.1.e, y es lo que hace que el candado no acabe en una llamada a soporte.
     */
    #[Test]
    public function test_con_el_permiso_el_candado_se_levanta(): void
    {
        $usuario = $this->usuarioLlanoDelPersonal();

        $this->assertSame(0, (int) $usuario->is_superuser,
            'El sujeto NO puede ser superusuario: con la columna puesta, el verde no diría nada.');

        $caso = $this->unCasoLimpio();
        $delColegio = $this->enLaAsignatura($caso, ['definicion' => 'Del colegio', 'por_defecto' => 1]);

        $this->darPermisoDePlantilla((int) $usuario->id);

        $this->putJson('/api/desempenos/'.$delColegio, ['definicion' => 'Corregida la errata'], [
            'Authorization' => 'Bearer '.$this->tokenDe($usuario->username),
        ])->assertStatus(200);
    }

    /**
     * **Un periodo cerrado sigue cerrado para todo el mundo, con permiso y sin
     * él.** El candado es una guarda *más*, nunca en lugar de ésta.
     */
    #[Test]
    public function test_un_periodo_cerrado_lo_esta_tambien_para_quien_tiene_el_permiso(): void
    {
        $caso = $this->unCasoLimpio();
        $suyo = $this->enLaAsignatura($caso, ['definicion' => 'Suyo']);

        DB::table('periodos')->where('id', $caso->periodo_id)
            ->update(['profes_pueden_editar_notas' => 0]);

        $this->pedir('putJson', 'desempenos/'.$suyo, ['definicion' => 'No'])->assertStatus(403);
        $this->pedir('deleteJson', 'desempenos/'.$suyo)->assertStatus(403);
        $this->pedir('postJson', 'desempenos', [
            'asignatura_id' => $caso->asignatura_id,
            'periodo_id' => $caso->periodo_id,
            'definicion' => 'Tampoco',
        ])->assertStatus(403);
    }

    // ─────────────────────────────────────────────────────────────────────
    // La planilla y el `<=>`
    // ─────────────────────────────────────────────────────────────────────

    /**
     * **El alumno normal recibe los del grupo; el independiente, los suyos.**
     *
     * Con `alumno_id = ?` en vez de `<=>` el primero se queda con la lista vacía
     * **en 200**, que es el fallo que no se delata.
     */
    #[Test]
    public function test_el_alumno_normal_recibe_los_del_grupo_y_el_independiente_los_suyos(): void
    {
        $caso = $this->unCasoLimpio();

        $alumnos = DB::select('SELECT id FROM alumnos WHERE deleted_at IS NULL ORDER BY id LIMIT 2');
        $this->assertCount(2, $alumnos);

        $normal = (int) $alumnos[0]->id;
        $propio = (int) $alumnos[1]->id;

        $delGrupo = $this->enLaAsignatura($caso, ['definicion' => 'El del grupo']);
        $suyo = $this->enLaAsignatura($caso, ['definicion' => 'El del alumno con PIAR', 'alumno_id' => $propio]);

        $this->marcarIndependiente($propio, (int) $caso->periodo_id);

        $r = $this->pedir('getJson',
            "desempenos?asignatura_id={$caso->asignatura_id}&periodo_id={$caso->periodo_id}&alumno_id={$normal}");
        $r->assertStatus(200);
        $ids = array_column($r->json('desempenos'), 'id');

        $this->assertContains($delGrupo, $ids,
            'El alumno normal se quedó sin los del grupo: es el `= NULL` que no empareja nunca '.
            'y no da ningún error.');
        $this->assertNotContains($suyo, $ids);
        $this->assertFalse($r->json('independiente'));

        $suyos = $this->pedir('getJson',
            "desempenos?asignatura_id={$caso->asignatura_id}&periodo_id={$caso->periodo_id}&alumno_id={$propio}");
        $suyos->assertStatus(200);
        $idsPropio = array_column($suyos->json('desempenos'), 'id');

        $this->assertTrue($suyos->json('independiente'));
        $this->assertSame($propio, $suyos->json('alcance'));
        $this->assertContains($suyo, $idsPropio);
        $this->assertNotContains($delGrupo, $idsPropio);
    }

    /**
     * **El `GET` de la planilla NO escribe.** Es la diferencia con
     * `UnidadesController::getDeAsignaturaPeriodo`, que siembra al leer y está
     * fichado en el 05 §16 por eso. Se cuenta la tabla antes y después.
     */
    #[Test]
    public function test_leer_la_planilla_no_siembra_nada(): void
    {
        $caso = $this->unCasoLimpio();
        $this->enElPlan($caso, ['definicion' => 'Del plan de área']);

        $antes = $this->censo();

        $this->pedir('getJson',
            "desempenos?asignatura_id={$caso->asignatura_id}&periodo_id={$caso->periodo_id}")
            ->assertStatus(200);

        $this->assertSame($antes, $this->censo(),
            'El `GET` de la planilla escribió. Es exactamente el fallo del `GET` viejo, y no se '.
            'repite en código nuevo: sembrar es `PUT desempenos/sembrar`.');
    }

    // ─────────────────────────────────────────────────────────────────────
    // El catálogo
    // ─────────────────────────────────────────────────────────────────────

    /**
     * **No hay origen `men`, y eso es la D12 entera**: el MEN publica estándares
     * por conjunto de grados y el desempeño va **por periodo**, que es lo que cada
     * colegio decide en su plan de área. El 422 lo dice con esas palabras en vez de
     * contestar un «tipo no válido» que invitaría a implementarlo.
     */
    #[Test]
    public function test_copiar_no_acepta_el_origen_men(): void
    {
        $caso = $this->unCasoLimpio();

        $r = $this->pedir('putJson', 'desempenos/plantilla/copiar', [
            'destino' => ['materia_id' => $caso->materia_id, 'periodo_id' => $caso->periodo_id],
            'origen' => ['tipo' => 'men'],
        ]);

        $r->assertStatus(422);
        $this->assertStringContainsString('men', strtolower((string) $r->json('message')));
    }

    #[Test]
    public function test_copiar_de_otro_grado_trae_los_textos_y_no_duplica(): void
    {
        $caso = $this->unCasoLimpio();
        $otroGrado = (int) DB::table('grados')->where('id', '<>', $caso->grado_id)
            ->whereNull('deleted_at')->orderBy('id')->value('id');

        $this->enElPlan($caso, ['definicion' => 'El que ya estaba escrito']);

        $cuerpo = [
            'destino' => [
                'materia_id' => $caso->materia_id,
                'grado_id' => $otroGrado,
                'periodo_id' => $caso->periodo_id,
            ],
            'origen' => ['tipo' => 'grado', 'grado_id' => $caso->grado_id],
        ];

        $primera = $this->pedir('putJson', 'desempenos/plantilla/copiar', $cuerpo);
        $primera->assertStatus(200);
        $this->assertSame(1, $primera->json('copiados'));

        $segunda = $this->pedir('putJson', 'desempenos/plantilla/copiar', $cuerpo);
        $segunda->assertStatus(200);
        $this->assertSame(0, $segunda->json('copiados'));
        $this->assertSame(1, $segunda->json('saltados_por_duplicado'));
    }

    /** El origen igual al destino es un 200 que no hace nada: 422 con el motivo. */
    #[Test]
    public function test_copiar_un_grupo_sobre_si_mismo_es_422(): void
    {
        $caso = $this->unCasoLimpio();

        $this->pedir('putJson', 'desempenos/plantilla/copiar', [
            'destino' => [
                'materia_id' => $caso->materia_id,
                'grado_id' => $caso->grado_id,
                'periodo_id' => $caso->periodo_id,
            ],
            'origen' => ['tipo' => 'grado', 'grado_id' => $caso->grado_id],
        ])->assertStatus(422);
    }

    #[Test]
    public function test_reordenar_el_plan_exige_el_grupo_entero(): void
    {
        $caso = $this->unCasoLimpio();
        $uno = $this->enElPlan($caso, ['definicion' => 'Primero']);
        $otro = $this->enElPlan($caso, ['definicion' => 'Segundo', 'orden' => 1]);

        $base = [
            'materia_id' => $caso->materia_id,
            'grado_id' => $caso->grado_id,
            'periodo_id' => $caso->periodo_id,
        ];

        $this->pedir('putJson', 'desempenos/plantilla/orden', $base + ['orden' => [$uno]])
            ->assertStatus(422);

        $r = $this->pedir('putJson', 'desempenos/plantilla/orden', $base + ['orden' => [$otro, $uno]]);
        $r->assertStatus(200);
        $this->assertSame(0, (int) DB::table('desempenos_por_defecto')->where('id', $otro)->value('orden'));
    }

    /**
     * **El texto largo viaja entero**, que es `FraseLargaEnElBoletinTest` aplicado a
     * este camino. Con `definicion varchar(255)` este caso se ve rojo, y el síntoma
     * sería un 200 con el desempeño cortado a mitad de palabra **ya impreso**.
     */
    #[Test]
    public function test_un_desempeno_de_302_caracteres_no_se_corta(): void
    {
        $caso = $this->unCasoLimpio();
        $largo = str_repeat('Reconoce y explica con sus palabras el porqué. ', 7);
        $largo = mb_substr($largo, 0, 302);

        $this->assertSame(302, mb_strlen($largo));

        $r = $this->pedir('postJson', 'desempenos/plantilla', [
            'materia_id' => $caso->materia_id,
            'grado_id' => $caso->grado_id,
            'periodo_id' => $caso->periodo_id,
            'definicion' => $largo,
        ]);

        $r->assertStatus(200);
        $this->assertSame($largo, $r->json('definicion'));
        $this->assertSame($largo,
            DB::table('desempenos_por_defecto')->where('id', $r->json('id'))->value('definicion'));
    }

    /**
     * **Ningún informe de hoy lee las dos tablas nuevas**, y por eso un año sin
     * plan de área da el boletín de hoy.
     *
     * Es más fuerte que comparar una respuesta con las tablas vacías, porque **esa
     * comparación pasaría igual con el boletín ya enchufado**. El día que llegue el
     * boletín nuevo de la Fase 6, es él quien se añade a la lista, no esta regla la
     * que se borra.
     */
    #[Test]
    public function test_ningun_informe_de_hoy_lee_las_tablas_de_desempenos(): void
    {
        $miradas = array_merge(
            glob(app_path('Http/Controllers/Informes/*.php')) ?: [],
            [
                app_path('Models/Unidad.php'),
                app_path('Services/BoletinIndependiente.php'),
                app_path('Http/Controllers/UnidadesController.php'),
                app_path('Http/Controllers/NotasController.php'),
                app_path('Http/Controllers/FrasesAsignaturaController.php'),
            ]
        );

        $culpables = [];

        foreach ($miradas as $fichero) {
            if (! is_file($fichero)) {
                continue;
            }

            $texto = (string) file_get_contents($fichero);

            if (preg_match('/\b(FROM|JOIN|INTO|UPDATE|table\()\s*[\'"`]?desempenos/i', $texto) === 1) {
                $culpables[] = basename($fichero);
            }
        }

        $this->assertSame([], $culpables,
            'Un informe de los de hoy pasó a leer `desempenos`: '.implode(', ', $culpables).".\n".
            'Los tres boletines de hoy NO cambian (D16, y la §4 del doc 28).');
    }

    // ─────────────────────────────────────────────────────────────────────
    // Ayudantes
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Un grupo nuevo con una asignatura dentro, **sin un solo desempeño**, en el
     * año del token y con los periodos abiertos.
     *
     * Es lo único sobre lo que `sembrar` llega a hacer algo, así que es lo único
     * sobre lo que sus reglas se pueden medir. Y es **nuevo y no del seed** porque
     * «una asignatura limpia» no se puede sacar con un `WHERE`: la transacción del
     * test lo deshace al terminar.
     */
    private function unCasoLimpio(): object
    {
        $yearId = $this->anioDelToken();

        $molde = DB::selectOne('SELECT grado_id FROM grupos WHERE year_id = ? AND deleted_at IS NULL
                                ORDER BY id LIMIT 1', [$yearId]);
        $this->assertNotNull($molde, "El seed no tiene ningún grupo en el año {$yearId}.");

        $grupoId = DB::table('grupos')->insertGetId([
            'nombre' => 'Grupo limpio de desempeños', 'abrev' => 'LID', 'year_id' => $yearId,
            'grado_id' => $molde->grado_id, 'orden' => 97, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $materia = (int) DB::table('materias')->whereNull('deleted_at')->orderBy('id')->value('id');
        $profesor = (int) DB::table('profesores')->whereNull('deleted_at')->orderBy('id')->value('id');

        $asignaturaId = (int) DB::table('asignaturas')->insertGetId([
            'materia_id' => $materia, 'grupo_id' => $grupoId, 'profesor_id' => $profesor,
            'orden' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('periodos')->where('year_id', $yearId)->update(['profes_pueden_editar_notas' => 1]);

        $periodoId = (int) DB::table('periodos')->where('year_id', $yearId)
            ->whereNull('deleted_at')->orderBy('numero')->orderBy('id')->value('id');

        return (object) [
            'year_id' => $yearId,
            'grupo_id' => $grupoId,
            'grado_id' => (int) $molde->grado_id,
            'materia_id' => $materia,
            'asignatura_id' => $asignaturaId,
            'periodo_id' => $periodoId,
        ];
    }

    /** Una fila del plan de área dirigida a ese caso. */
    private function enElPlan(object $caso, array $campos = []): int
    {
        return (int) DB::table('desempenos_por_defecto')->insertGetId($campos + [
            'year_id' => $caso->year_id,
            'materia_id' => $caso->materia_id,
            'grado_id' => $caso->grado_id,
            'periodo_id' => $caso->periodo_id,
            'definicion' => 'Desempeño de prueba',
            'orden' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** Una fila ya en la asignatura, como la dejaría el sembrador o el docente. */
    private function enLaAsignatura(object $caso, array $campos = []): int
    {
        return (int) DB::table('desempenos')->insertGetId($campos + [
            'asignatura_id' => $caso->asignatura_id,
            'periodo_id' => $caso->periodo_id,
            'alumno_id' => null,
            'definicion' => 'Desempeño de prueba',
            'orden' => 0,
            'por_defecto' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return list<string> */
    private function textosDe(object $caso): array
    {
        return array_values(array_map(fn ($d) => (string) $d->definicion, DB::select(
            'SELECT definicion FROM desempenos
              WHERE asignatura_id = ? AND periodo_id = ? AND alumno_id IS NULL
                AND deleted_at IS NULL ORDER BY orden, id',
            [$caso->asignatura_id, $caso->periodo_id]
        )));
    }

    private function cuantosEn(object $caso): int
    {
        return (int) DB::table('desempenos')->where('asignatura_id', $caso->asignatura_id)
            ->whereNull('deleted_at')->count();
    }

    /**
     * Lo que hace falta comparar después de un 403: no qué contestó, **si
     * escribió**. Las dos tablas juntas, que es donde puede aparecer una fila que
     * nadie pidió.
     */
    private function censo(): string
    {
        return DB::table('desempenos_por_defecto')->whereNull('deleted_at')->count()
            .'/'.DB::table('desempenos')->whereNull('deleted_at')->count();
    }

    private function instantaneaDe(int $id): string
    {
        $fila = DB::selectOne('SELECT definicion, tipo, orden, competencia_id, deleted_at
                                FROM desempenos WHERE id = ?', [$id]);

        return json_encode((array) $fila) ?: '';
    }

    private function pedir(string $verbo, string $ruta, array $cuerpo = [])
    {
        return $this->llamar($verbo, $ruta, $cuerpo, $this->tokenDe($this->usuarioDeTipo('Usuario')->username));
    }

    /**
     * **`getJson` no acepta cuerpo y los otros tres sí**, y su segundo parámetro son
     * las cabeceras: pasarle el cuerpo ahí da un `TypeError` dentro de `json_encode`
     * que no se parece en nada a la causa.
     */
    private function llamar(string $verbo, string $ruta, array $cuerpo, string $token)
    {
        $cabeceras = ['Authorization' => 'Bearer '.$token];

        if ($verbo === 'getJson') {
            return $this->getJson("/api/{$ruta}", $cabeceras);
        }

        return $this->{$verbo}("/api/{$ruta}", $cuerpo, $cabeceras);
    }

    private function anioDelToken(): int
    {
        return (int) $this->pedir('getJson', 'desempenos/plantilla')->json('year_id');
    }

    /**
     * El permiso por rol, que es como llega de verdad al contexto. `test-seed.sql`
     * hace `TRUNCATE` de `permissions`, así que lo que siembre la migración **no
     * sobrevive a construir la base**.
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
