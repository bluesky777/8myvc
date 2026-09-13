<?php

namespace Tests\Contrato;

use App\Models\Year;
use App\Support\Autoriza;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * **El modelo de evaluación del colegio** — Fase 1 de
 * [35](../../docs/migracion/35-el-modelo-de-evaluacion-del-colegio.md) §2, sobre
 * D1, D3, D13, D15 y D24 de `myvc_front/DECISIONES-MODELO-DE-EVALUACION.md`.
 *
 * ## Las cinco cosas que existe para cazar, y ninguna da error sola
 *
 * **1. Que el interruptor toque un cálculo.** Es D3, y es la propiedad que hace
 * que esta fase se pueda desplegar a los dieciséis colegios sin avisar a nadie:
 * *volver atrás es cambiar el enum*. Un recálculo escondido detrás del modo
 * `competencias` no se vería el día del despliegue —todos amanecen en
 * `ponderado`— sino **el día que un colegio lo encienda**, con las definitivas ya
 * impresas. Por eso el caso no comprueba «devuelve 200»: compara las respuestas
 * **enteras** en los dos modos y cuenta las filas de todo lo que podría moverse.
 *
 * **2. Que cualquier docente cambie el modelo del colegio entero.** Es D24. El
 * guard de la ruta es `auth.personal`, que cierra la puerta a alumnos y
 * acudientes **y a nadie más**; lo que para a un profesor es
 * `Autoriza::puedeEditarPlantillaNotas` DENTRO del método. Sin el caso del
 * docente llano, quitarlo del controlador no pondría **nada** en rojo — es
 * exactamente el control que salvó a `putTonoDocente`.
 *
 * **3. Que la decisión se salte por la puerta de al lado.**
 * `PUT years/toggle-cambiar-valor` escribe **cualquier columna de `years` que
 * exista** con el mismo `auth.personal`, así que sin un corte explícito D24 vale
 * exactamente hasta que alguien mande `{campo: "modelo_evaluacion"}`. Es la mitad
 * de D24 que D24 no menciona.
 *
 * **4. Que `modelo_evaluacion` no llegue a la sesión.** El front decide con su
 * **presencia** si enseña lo nuevo —si el campo no llega, se comporta como
 * `ponderado`—, así que una columna que se escribe bien y no viaja deja la
 * pantalla apagada en los dieciséis **sin un solo error en ningún log**. Es la
 * familia de `profesores.tono` por la otra punta, y por eso se comprueba en las
 * **cuatro** ramas del contexto y no sólo en la del profesor.
 *
 * **5. Que el año nuevo pierda la elección del colegio.** El centinela de las
 * columnas del año nuevo mira el **fuente** —que la columna esté nombrada—, y una
 * escrita como `Request::input('x')` lo pasa igual. Aquí se mira el resultado.
 */
class ModeloDeEvaluacionDelAnioTest extends CasoDeContrato
{
    /** Las cuatro columnas que trae la Fase 1, con el valor con el que nacen. */
    private const NACEN_ASI = [
        'modelo_evaluacion' => 'ponderado',
        'desempeno_displayname' => 'Desempeño',
        'desempenos_displayname' => 'Desempeños',
        'genero_desempeno' => 'M',
    ];

    // ── Lo que el colegio tiene hoy ──────────────────────────────────────────

    /**
     * Los dieciséis amanecen en `ponderado`, que es lo que dice D1.
     *
     * **Y la población va delante del veredicto**: un «ninguno está en
     * competencias» no distingue «los revisé todos» de «no leí ninguno», que es la
     * forma de aprobado que este repo tiene fichada.
     */
    #[Test]
    public function todos_los_anios_nacen_en_ponderado_y_con_el_vocabulario_del_1290(): void
    {
        $anios = DB::select('SELECT id, modelo_evaluacion, desempeno_displayname,
            desempenos_displayname, genero_desempeno FROM years WHERE deleted_at IS NULL');

        $this->assertGreaterThan(0, count($anios),
            'No se leyó ningún año: este caso no estaría comprobando nada.');

        foreach ($anios as $anio) {
            foreach (self::NACEN_ASI as $columna => $valor) {
                $this->assertSame($valor, $anio->$columna,
                    "El año {$anio->id} nació con `{$columna}` = '{$anio->$columna}' y no '{$valor}'.\n"
                    .'La migración es aditiva y con DEFAULT: nadie ha tocado esto todavía.');
            }
        }
    }

    /**
     * El `enum` de la base y la lista del modelo no pueden separarse.
     *
     * Es la trampa de `Autoriza::PERMISO_*` escrita en su docblock: los dos
     * existen, dicen cosas distintas y **no falla nada** hasta que alguien guarda
     * el valor que sólo conoce uno de los dos. Aquí el síntoma sería un 422 para
     * un modelo que la base acepta, o un 500 al revés.
     */
    #[Test]
    public function la_lista_del_modelo_y_la_de_la_base_son_la_misma(): void
    {
        $tipo = (string) DB::selectOne('SHOW COLUMNS FROM years LIKE "modelo_evaluacion"')->Type;

        preg_match_all("/'([^']+)'/", $tipo, $coincidencias);

        $this->assertSame(Year::MODELOS_DE_EVALUACION, $coincidencias[1],
            "El `enum` de la base dice `{$tipo}` y `Year::MODELOS_DE_EVALUACION` dice otra cosa.");
    }

    // ── D3: el interruptor no recalcula ni borra nada ────────────────────────

    /**
     * **D3, primera mitad: encender el interruptor no toca una sola fila.**
     *
     * Es la propiedad que deja *volver atrás cambiando el enum*, y se comprueba
     * sobre **el endpoint**, no sobre un `UPDATE` a pelo: lo que puede recalcular o
     * borrar de más es el método, y un caso que moviera la columna por su cuenta
     * estaría midiendo a MySQL.
     *
     * El censo va de las cinco tablas que un recálculo movería más las escalas del
     * año. **Y se comprueba además que el enum cambió de verdad**, porque un
     * «ninguna fila se movió» es trivialmente cierto si el endpoint no hizo nada.
     */
    #[Test]
    public function encender_el_modelo_no_recalcula_ni_borra_nada(): void
    {
        $yearId = $this->anioActual();

        $this->ponerElModelo($yearId, 'ponderado');

        $censoAntes = $this->censo($yearId);

        $this->pedir('competencias', $yearId)->assertStatus(200);

        $censoDespues = $this->censo($yearId);

        $this->assertSame('competencias',
            DB::table('years')->where('id', $yearId)->value('modelo_evaluacion'),
            'El endpoint no escribió: el censo de abajo saldría igual sin demostrar nada.');

        $this->assertSame($censoAntes, $censoDespues,
            "Encender `competencias` movió filas, y no puede mover ninguna (D3): los desempeños\n"
            .'sembrados y las marcas se quedan en la base y dejan de pintarse. Si esto se pone '
            .'rojo, alguien le añadió un recálculo «de cortesía» a `putModeloEvaluacion`.');
    }

    /**
     * **D3, segunda mitad: ninguna de las lecturas cambia con el enum.**
     *
     * Cuatro de las cinco que el plan nombra —las unidades de una asignatura y
     * periodo y los **tres** boletines—; la quinta, la definitiva, va en el caso de
     * abajo porque **escribe** y mezclarla aquí medía otra cosa.
     *
     * **Por qué se compara contra `competencias` y no «contra antes de la
     * migración»**: lo segundo no se puede medir desde un test —la base ya está
     * migrada— y además comprobaría menos. Lo que hay que demostrar es que **ningún
     * cálculo cambia en ninguno de los dos modos**, y eso se pone rojo en el acto el
     * día que alguien meta un `if ($year->modelo_evaluacion === 'competencias')`
     * dentro de la fórmula.
     *
     * **La primera vuelta de lecturas se tira a propósito**, y sin eso el caso sería
     * ruido: `GET unidades/de-asignatura-periodo` **escribe** —cuando la asignatura
     * y el periodo no tienen unidades, las monta— y `calcular-grupo-periodo`
     * reescribe la rejilla entera con ids nuevos. Comparar la primera vuelta contra
     * la segunda mediría el montaje, no el enum.
     */
    #[Test]
    public function las_lecturas_no_cambian_con_el_enum(): void
    {
        [$grupo, $token] = $this->grupoYPersonal();

        $yearId = (int) $grupo->year_id;

        $this->ponerElModelo($yearId, 'ponderado');

        // Calentar lo que escribe, para que lo comparado sea sólo el enum.
        $this->lecturas($grupo, $token);

        $conPonderado = $this->lecturas($grupo, $token);

        $this->ponerElModelo($yearId, 'competencias');

        $conCompetencias = $this->lecturas($grupo, $token);

        $this->assertNotEmpty($conPonderado['boletines'][2] ?? [],
            'El boletín salió sin alumnos: con las cinco lecturas vacías este caso compararía '
            .'dos nadas y saldría verde.');

        $this->assertSame($conPonderado, $conCompetencias,
            "Una de las lecturas cambió al mover el enum, y D3 dice que ninguna puede:\n"
            ."«el interruptor gobierna lo que se ve y lo que se escribe, nunca el cálculo».\n"
            .'Si esto se pone rojo, lo que hay que mirar es qué `if` nuevo lee `modelo_evaluacion`.');
    }

    /**
     * Y la otra mitad de D3: el enum **se queda escrito**.
     *
     * Sin esto, el caso de arriba en verde sería compatible con un endpoint que no
     * escribe nada: las cinco lecturas saldrían iguales porque el modelo nunca
     * llegó a cambiar. Es la mitad que separa «no recalcula» de «no hace nada».
     */
    #[Test]
    public function el_modelo_que_se_guarda_es_el_que_se_lee(): void
    {
        $yearId = $this->anioActual();

        $this->pedir('competencias', $yearId)->assertStatus(200);

        $this->assertSame('competencias',
            DB::table('years')->where('id', $yearId)->value('modelo_evaluacion'),
            'El endpoint contestó 200 y no escribió: es la familia de `respuestas-que-mienten.py`.');

        $r = $this->pedir('ponderado', $yearId);

        $r->assertStatus(200);
        $this->assertSame('ponderado', $r->json('modelo_evaluacion'));
        $this->assertSame('competencias', $r->json('anterior'),
            'La respuesta tiene que decir de dónde venía: es lo que deja el cambio revisable '
            .'sin abrir la auditoría.');
    }

    /**
     * **D3, tercera mitad: la definitiva sale igual en los dos modos.**
     *
     * Va en su propio caso y no dentro de las cinco lecturas, y la razón se midió:
     * `PUT definitivas_periodos/calcular-grupo-periodo` **escribe**, y mezclado con
     * los tres boletines —que también tocan `notas_finales`— la rejilla del grupo
     * **crece entre una vuelta y la siguiente**. La primera versión de esto comparaba
     * las dos vueltas enteras y salió roja con nueve definitivas de más de un alumno:
     * un rojo verdadero sobre una pregunta mal hecha, porque **lo que había cambiado
     * no era el enum, era el número de veces que se había llamado a los escritores**.
     *
     * Aislado, el cálculo sí es comparable: se deja llegar al punto fijo con dos
     * vueltas en `ponderado` —y **se comprueba que llegó**, que es lo que separa esto
     * de esperar y confiar— y sólo entonces se mueve el enum.
     */
    #[Test]
    public function la_definitiva_sale_igual_en_los_dos_modos(): void
    {
        [$grupo, $token] = $this->grupoYPersonal();

        $yearId = (int) $grupo->year_id;
        $donde = $this->asignaturaConDefinitivas($grupo);

        $this->ponerElModelo($yearId, 'ponderado');

        $this->calcular($grupo, $donde, $token);
        $primera = $this->definitivasDe($grupo, $donde);

        $this->calcular($grupo, $donde, $token);
        $ponderado = $this->definitivasDe($grupo, $donde);

        $this->assertSame($primera, $ponderado,
            "El cálculo no llegó a un punto fijo en dos vueltas, así que compararlo con el otro\n"
            .'modo no diría nada del enum. Lo que hay que mirar es qué escribe `calcular-grupo-periodo` '
            .'la segunda vez que se le llama con lo mismo.');

        $this->ponerElModelo($yearId, 'competencias');

        $this->calcular($grupo, $donde, $token);
        $competencias = $this->definitivasDe($grupo, $donde);

        $this->assertNotEmpty($ponderado,
            'No hay ninguna definitiva que comparar: el caso saldría verde sin medir la fórmula.');

        $this->assertSame($ponderado, $competencias,
            "La definitiva cambió al mover el enum, y D3 dice que no puede: «la definitiva sale de\n"
            .'la misma fórmula en los dos modos» (regla 3 de la §4 del doc 28).');
    }

    // ── D24: quién puede, y por dónde ────────────────────────────────────────

    /**
     * **D24 entera, en un solo caso, porque las dos mitades sólo significan
     * juntas.**
     *
     * El 403 solo demostraría «hay un permiso»; el 200 solo, «los rótulos se
     * guardan». Lo que D24 decidió es **dónde se traza la línea**: el mismo
     * docente, con la misma sesión, **no** puede cambiar el modelo y **sí** puede
     * renombrar «Desempeño» — porque lo primero configura el colegio y lo segundo
     * es un rótulo, igual que «Subunidad».
     */
    #[Test]
    public function un_docente_llano_no_cambia_el_modelo_pero_si_renombra_el_desempeno(): void
    {
        $usuario = $this->usuarioLlanoDelPersonal();

        $this->assertSame(0, (int) $usuario->is_superuser,
            'El sujeto de este caso NO puede ser superusuario: con la columna puesta, el 403 '
            .'no diría nada sobre el permiso.');

        $token = $this->tokenDe($usuario->username);
        $yearId = $this->anioActual();
        $antes = DB::table('years')->where('id', $yearId)->value('modelo_evaluacion');

        $negado = $this->withToken($token)->putJson('/api/years/modelo-evaluacion', [
            'year_id' => $yearId,
            'modelo_evaluacion' => 'competencias',
        ]);

        $negado->assertStatus(403);
        $this->assertSame($antes, DB::table('years')->where('id', $yearId)->value('modelo_evaluacion'),
            'Contestó 403 y escribió igual: el criterio frena la respuesta pero no la escritura.');

        $permitido = $this->withToken($token)->putJson('/api/years/guardar-cambios', [
            'id' => $yearId,
            'desempeno_displayname' => 'Logro',
            'desempenos_displayname' => 'Logros',
            'genero_desempeno' => 'M',
        ]);

        $permitido->assertStatus(200);

        $fila = DB::table('years')->where('id', $yearId)->first();

        $this->assertSame('Logro', $fila->desempeno_displayname,
            'Los tres rótulos van en `guardar-cambios` con su mismo guard (D24): quien puede '
            .'renombrar «Subunidad» puede renombrar «Desempeño». Sin esto, la columna existiría '
            .'y no la podría escribir nadie, que es el caso `profesores.tono`.');
        $this->assertSame('Logros', $fila->desempenos_displayname);
    }

    /**
     * **El mismo usuario, con el permiso, sí entra.**
     *
     * Con un superusuario el verde no diría nada: pasa por encima del permiso. Y
     * el permiso se da **por rol**, que es como llega de verdad al contexto —
     * `ContextoDeUsuario` junta los permisos de todos los roles en `perms`, y un
     * atajo aquí comprobaría un camino que en producción no existe.
     */
    #[Test]
    public function con_el_permiso_y_sin_superusuario_si_cambia_el_modelo(): void
    {
        $usuario = $this->usuarioLlanoDelPersonal();

        $this->assertSame(0, (int) $usuario->is_superuser);

        $this->darPermisoDeLaPlantilla((int) $usuario->id);

        $yearId = $this->anioActual();

        $r = $this->withToken($this->tokenDe($usuario->username))
            ->putJson('/api/years/modelo-evaluacion', [
                'year_id' => $yearId,
                'modelo_evaluacion' => 'competencias',
            ]);

        $r->assertStatus(200);
        $this->assertSame('competencias',
            DB::table('years')->where('id', $yearId)->value('modelo_evaluacion'));
    }

    /**
     * **La puerta de al lado, que es la que D24 no menciona y dejaba abierta.**
     *
     * `PUT years/toggle-cambiar-valor` arma un `UPDATE years SET <campo>=:valor`
     * con cualquier columna que exista —`ColumnaSegura::exigir('years', $campo)`—
     * y su guard es `auth.personal`. El comentario que justificaba que eso no
     * fuera un agujero decía *«quien pasa `auth.personal` ya las escribe todas por
     * `years/guardar-cambios`»*, y con esta columna **esa frase dejó de ser
     * cierta**: `modelo_evaluacion` es justo la que no está en las veintiuna de
     * aquel método, a propósito.
     *
     * Se comprueba con un **superusuario**, que es el sujeto más fuerte posible:
     * si ni él la escribe por ahí, nadie la escribe. Y se mira la fila, porque un
     * 422 que escribe igual dejaría D24 en un mensaje de error.
     */
    #[Test]
    public function el_generico_de_years_no_puede_escribir_el_modelo(): void
    {
        $usuario = $this->usuarioDeTipo('Usuario');

        $this->assertSame(1, (int) $usuario->is_superuser,
            'Este caso necesita el sujeto MÁS fuerte: con uno llano, el corte podría venir del '
            .'permiso y no de la lista de columnas.');

        $yearId = $this->anioActual();
        $antes = DB::table('years')->where('id', $yearId)->value('modelo_evaluacion');

        $r = $this->withToken($this->tokenDe($usuario->username))
            ->putJson('/api/years/toggle-cambiar-valor', [
                'year_id' => $yearId,
                'campo' => 'modelo_evaluacion',
                'valor' => 'competencias',
            ]);

        $r->assertStatus(422);
        $this->assertSame($antes, DB::table('years')->where('id', $yearId)->value('modelo_evaluacion'),
            'El genérico contestó 422 y escribió igual, que es la peor de las dos formas de fallar.');
    }

    /** Un valor que no es del enum no entra, y no deja la fila a medias. */
    #[Test]
    public function un_modelo_que_no_existe_es_422_y_no_escribe(): void
    {
        $yearId = $this->anioActual();
        $antes = DB::table('years')->where('id', $yearId)->value('modelo_evaluacion');

        $this->pedir('por_competencias', $yearId)->assertStatus(422);

        $this->assertSame($antes, DB::table('years')->where('id', $yearId)->value('modelo_evaluacion'),
            'Un modelo inventado entró en la fila. El `enum` de MySQL guardaría la cadena vacía '
            .'sin modo estricto, que es la familia de `frases_asignatura` cortando a los 255.');
    }

    /** Un año que no existe es 404, no un 200 que no escribió nada. */
    #[Test]
    public function un_anio_que_no_existe_es_404(): void
    {
        $this->pedir('competencias', 999999999)->assertStatus(404);
    }

    // ── Que llegue al cliente ────────────────────────────────────────────────

    /**
     * **`modelo_evaluacion` viaja en la sesión, en las cuatro ramas.**
     *
     * El front se apoya en su **presencia** para decidir si enseña lo nuevo: si el
     * campo no llega, se comporta como `ponderado`. O sea que una columna escrita
     * y no publicada deja la pantalla apagada en los dieciséis sin que falle nada.
     *
     * En las cuatro y no sólo en la del profesor, por lo mismo que
     * `regla_nivelacion`: **una columna que está en tres de cuatro respuestas es
     * una rama muerta esperando** — ya pasó con `year_pasado_en_bol`, que faltaba
     * en la del acudiente y reventaba dos maquetas con «Undefined property» la
     * primera vez que las pidió una familia.
     */
    #[DataProvider('losCuatroTipos')]
    #[Test]
    public function el_modelo_viaja_en_el_contexto_de_los_cuatro_tipos(string $tipo): void
    {
        $usuario = $this->usuarioDeTipo($tipo);

        $r = $this->withToken($this->tokenDe($usuario->username))->postJson('/api/login');

        $r->assertStatus(200);

        foreach (array_keys(self::NACEN_ASI) as $clave) {
            $this->assertArrayHasKey($clave, (array) $r->json(),
                "El contexto de un {$tipo} no trae `{$clave}`.\n"
                .'El front decide por la PRESENCIA de `modelo_evaluacion`: sin ella se comporta '
                ."como `ponderado` y la pantalla nueva no aparece nunca.\n"
                .'Se añade en `ContextoDeUsuario::construir`, en la rama de este tipo.');
        }

        $this->assertSame('ponderado', $r->json('modelo_evaluacion'));
    }

    /** Y el valor que viaja es el que está escrito, no el defecto de la columna. */
    #[Test]
    public function el_contexto_trae_el_modelo_que_el_colegio_eligio(): void
    {
        $usuario = $this->usuarioDeTipo('Profesor');

        // El token primero: `Services\Login` reescribe `users.periodo_id` al
        // entrar, así que el año del contexto sólo se sabe después de entrar.
        $token = $this->tokenDe($usuario->username);

        $yearId = (int) DB::selectOne('SELECT p.year_id FROM users u
            INNER JOIN periodos p ON p.id = u.periodo_id WHERE u.id = ?', [$usuario->id])->year_id;

        DB::table('years')->where('id', $yearId)->update(['modelo_evaluacion' => 'competencias']);

        $r = $this->withToken($token)->postJson('/api/login');

        $r->assertStatus(200);
        $this->assertSame('competencias', $r->json('modelo_evaluacion'),
            'El contexto trae el defecto de la columna y no lo que hay en la fila: la sesión '
            .'estaría mintiendo sobre la configuración del colegio.');
    }

    /**
     * **El año nuevo hereda las cuatro.**
     *
     * `CentinelaDeLasColumnasDelAnioNuevoTest` vigila esto desde el **fuente** —que
     * la columna esté nombrada en `postStore`—, y una escrita como
     * `Request::input('x')` lo pasa igual. Aquí se mira el resultado, que es lo
     * otro: sin la herencia, el colegio que eligió `competencias` amanecería en
     * `ponderado` cada enero, con sus desempeños escritos y sin la pantalla que los
     * pinta. Es el caso de `puestos_con_bol_independiente`, donde el DEFAULT tenía
     * pinta de decisión.
     */
    #[Test]
    public function el_anio_nuevo_hereda_las_cuatro_columnas(): void
    {
        // **El sujeto sale del último año VIVO, y eso no es un detalle del seed.**
        // `postStore` copia con `Year::where('year', $pedido - 1)->first()`, que lleva
        // `SoftDeletes`: un año en la papelera **no se copia**. El seed tiene
        // justamente eso —2026 borrado y con `actual = 1`, la fila que arregla
        // `deleteDelete`—, así que pedir `max(year) + 1` como hace `YearsTest` daría
        // 2027 y el año anterior sería el borrado: **la copia no correría y este caso
        // saldría verde sin haber heredado nada.**
        $pasado = DB::selectOne('SELECT * FROM years WHERE deleted_at IS NULL
            ORDER BY year DESC, id DESC LIMIT 1');

        $this->assertNotNull($pasado, 'No hay año vivo del que heredar.');

        $siguiente = ((int) $pasado->year) + 1;

        DB::table('years')->where('id', $pasado->id)->update([
            'modelo_evaluacion' => 'competencias',
            'desempeno_displayname' => 'Indicador de desempeño',
            'desempenos_displayname' => 'Indicadores de desempeño',
            'genero_desempeno' => 'M',
        ]);

        $token = $this->tokenDe($this->usuarioDeTipo('Usuario')->username);

        $r = $this->withToken($token)->postJson('/api/years/store', [
            'year' => $siguiente,
            'actual' => false,
            'nombre_colegio' => $pasado->nombre_colegio,
            'abrev_colegio' => $pasado->abrev_colegio,
            'nota_minima_aceptada' => $pasado->nota_minima_aceptada,
            'resolucion' => $pasado->resolucion,
            'codigo_dane' => $pasado->codigo_dane,
            'encabezado_certificado' => $pasado->encabezado_certificado,
            'telefono' => $pasado->telefono,
            'celular' => $pasado->celular,
            'unidad_displayname' => $pasado->unidad_displayname,
            'unidades_displayname' => $pasado->unidades_displayname,
            'genero_unidad' => $pasado->genero_unidad,
            'subunidad_displayname' => $pasado->subunidad_displayname,
            'subunidades_displayname' => $pasado->subunidades_displayname,
            'genero_subunidad' => $pasado->genero_subunidad,
            'website' => $pasado->website,
            'website_myvc' => $pasado->website_myvc,
            'alumnos_can_see_notas' => $pasado->alumnos_can_see_notas,
        ]);

        $r->assertStatus(200);

        $nuevo = DB::table('years')->where('id', $r->json('id'))->first();

        $this->assertNotNull($nuevo, 'No se creó el año siguiente.');

        $this->assertSame('competencias', $nuevo->modelo_evaluacion,
            'El año nuevo amaneció en `ponderado` con el anterior en `competencias`.');
        $this->assertSame('Indicador de desempeño', $nuevo->desempeno_displayname);
        $this->assertSame('Indicadores de desempeño', $nuevo->desempenos_displayname);
        $this->assertSame('M', $nuevo->genero_desempeno);
    }

    // ── Ayudantes ────────────────────────────────────────────────────────────

    /** @return array<string, array{string}> */
    public static function losCuatroTipos(): array
    {
        return [
            'Profesor' => ['Profesor'],
            'Alumno' => ['Alumno'],
            'Acudiente' => ['Acudiente'],
            'Usuario' => ['Usuario'],
        ];
    }

    /** Con el token de un superusuario, que es quien puede el primer día. */
    private function pedir(string $modelo, int $yearId)
    {
        $usuario = $this->usuarioDeTipo('Usuario');

        $this->assertSame(1, (int) $usuario->is_superuser,
            'El sujeto de estos casos tiene que ser superusuario: sin él, el 403 del criterio '
            .'se leería como un fallo de la validación.');

        return $this->withToken($this->tokenDe($usuario->username))
            ->putJson('/api/years/modelo-evaluacion', [
                'year_id' => $yearId,
                'modelo_evaluacion' => $modelo,
            ]);
    }

    private function anioActual(): int
    {
        $id = DB::table('years')->where('actual', 1)->whereNull('deleted_at')->value('id');

        $this->assertNotNull($id, 'El seed no tiene año actual.');

        return (int) $id;
    }

    /** Se escribe a pelo: en D3 lo que se mide es el efecto del valor, no la ruta. */
    private function ponerElModelo(int $yearId, string $modelo): void
    {
        DB::table('years')->where('id', $yearId)->update(['modelo_evaluacion' => $modelo]);
    }

    /**
     * Las cinco lecturas que el plan nombra, en una sola estructura comparable.
     *
     * @return array<string, mixed>
     */
    private function lecturas(object $grupo, string $token): array
    {
        $alumno = $this->unAlumnoDelGrupo((int) $grupo->id);

        $salida = [];

        foreach (['boletines', 'boletines2', 'boletines3'] as $familia) {
            $salida[$familia] = $this->withToken($token)
                ->putJson("/api/{$familia}/detailed-notas/{$grupo->id}",
                    ['requested_alumnos' => [$alumno]])->json();
        }

        $asignatura = DB::selectOne('SELECT a.id, nf.periodo_id
            FROM asignaturas a
            INNER JOIN notas_finales nf ON nf.asignatura_id = a.id
            INNER JOIN periodos p ON p.id = nf.periodo_id AND p.deleted_at IS NULL
            WHERE a.grupo_id = ? AND a.deleted_at IS NULL
            ORDER BY a.id LIMIT 1', [$grupo->id]);

        $this->assertNotNull($asignatura,
            'El grupo elegido no tiene ninguna asignatura con definitivas: las dos lecturas de '
            .'notas saldrían vacías y este caso no compararía nada.');

        $salida['unidades'] = $this->withToken($token)->getJson(
            "/api/unidades/de-asignatura-periodo/{$asignatura->id}/{$asignatura->periodo_id}")->json();

        return $salida;
    }

    /** La asignatura y el periodo del grupo que ya tienen definitivas puestas. */
    private function asignaturaConDefinitivas(object $grupo): object
    {
        $fila = DB::selectOne('SELECT a.id, nf.periodo_id, p.numero
            FROM asignaturas a
            INNER JOIN notas_finales nf ON nf.asignatura_id = a.id
            INNER JOIN periodos p ON p.id = nf.periodo_id AND p.deleted_at IS NULL
            WHERE a.grupo_id = ? AND a.deleted_at IS NULL
            ORDER BY a.id LIMIT 1', [$grupo->id]);

        $this->assertNotNull($fila,
            'El grupo elegido no tiene ninguna asignatura con definitivas: las lecturas de notas '
            .'saldrían vacías y el caso no compararía nada.');

        return $fila;
    }

    private function calcular(object $grupo, object $donde, string $token): void
    {
        $this->withToken($token)
            ->putJson('/api/definitivas_periodos/calcular-grupo-periodo', [
                'grupo_id' => $grupo->id,
                'periodo_id' => $donde->periodo_id,
                'num_periodo' => $donde->numero,
            ])->assertStatus(200);
    }

    /**
     * La definitiva de cada alumno y asignatura del grupo, como texto comparable.
     *
     * **La respuesta del endpoint no sirve para esto**: `calcular-grupo-periodo`
     * devuelve la cadena `'Calculado'`, así que compararla sería comparar una
     * constante consigo misma y el caso saldría verde con la fórmula cambiada. Lo que
     * se compara es lo que dejó escrito.
     *
     * @return list<string>
     */
    private function definitivasDe(object $grupo, object $donde): array
    {
        return array_values(array_map(
            fn (object $f): string => $f->alumno_id.'/'.$f->asignatura_id.'='.$f->nota,
            DB::select('SELECT nf.alumno_id, nf.asignatura_id, nf.nota
                FROM notas_finales nf
                INNER JOIN asignaturas a ON a.id = nf.asignatura_id
                WHERE a.grupo_id = ? AND nf.periodo_id = ?
                ORDER BY nf.alumno_id, nf.asignatura_id', [$grupo->id, $donde->periodo_id])
        ));
    }

    /**
     * Cuántas filas hay en lo que un recálculo escondido movería.
     *
     * @return array<string, int>
     */
    private function censo(int $yearId): array
    {
        return [
            'notas' => (int) DB::selectOne('SELECT COUNT(*) n FROM notas')->n,
            'notas_finales' => (int) DB::selectOne('SELECT COUNT(*) n FROM notas_finales')->n,
            'unidades' => (int) DB::selectOne('SELECT COUNT(*) n FROM unidades')->n,
            'subunidades' => (int) DB::selectOne('SELECT COUNT(*) n FROM subunidades')->n,
            'frases_asignatura' => (int) DB::selectOne('SELECT COUNT(*) n FROM frases_asignatura')->n,
            'escalas' => (int) DB::selectOne(
                'SELECT COUNT(*) n FROM escalas_de_valoracion WHERE year_id = ?', [$yearId])->n,
        ];
    }

    /** @return array{alumno_id: int, grupo_id: int} */
    private function unAlumnoDelGrupo(int $grupoId): array
    {
        $fila = DB::selectOne('SELECT m.alumno_id FROM matriculas m
            WHERE m.grupo_id = ? AND m.deleted_at IS NULL
              AND m.estado IN ("MATR","ASIS","PREM") ORDER BY m.id LIMIT 1', [$grupoId]);

        $this->assertNotNull($fila, "El grupo {$grupoId} no tiene alumnos matriculados.");

        return ['alumno_id' => (int) $fila->alumno_id, 'grupo_id' => $grupoId];
    }

    /**
     * El permiso por rol, que es como llega de verdad al contexto. Calcado de
     * `PlantillaNotasTest`, y por el mismo motivo: `test-seed.sql` hace `TRUNCATE`
     * de `permissions`, así que lo que siembre la migración **no sobrevive a
     * construir la base** y un caso que se apoyara en ello estaría comprobando el
     * seed y no el código.
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
}
