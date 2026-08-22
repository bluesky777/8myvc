<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/**
 * La segunda mitad del muestreo de la P2: las lecturas que necesitan contexto.
 *
 * Las del otro fichero solo necesitaban un token. Estas piden además un id que
 * exista en el seed y que encaje —el grupo con el año del usuario, el profesor
 * con una asignatura de ese grupo—, y eso es lo que las había dejado fuera.
 *
 * **Casi ninguna es un GET.** Es el patrón de este proyecto: se lee con `PUT` y
 * el filtro va en el cuerpo. Buscar «GET por controlador», que es como estaba
 * escrita la P2 en el plan, deja fuera a veinte controladores enteros por un
 * detalle de verbo. Lo que se muestrea es una LECTURA por controlador.
 *
 * Con esto, los controladores sin nada que los mire bajan de veinte a los que
 * solo tienen escrituras.
 */
class MuestreoDeLecturasConContextoTest extends CasoDeContrato
{
    private object $grupo;

    private string $token;

    /** El profesor con más asignaturas en el año del grupo. */
    private object $profesor;

    private object $alumno;

    private object $periodo;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->grupo, $this->token] = $this->grupoYPersonal();

        // No vale «el primer profesor»: el que no da clase en el año del grupo
        // devuelve la lista vacía en 200 y el test pasa sin haber calculado
        // nada. Es la misma trampa que tokenDelPersonalDe() resuelve para el
        // año, un escalón más abajo.
        $this->profesor = DB::selectOne('SELECT p.id FROM profesores p
            INNER JOIN asignaturas a ON a.profesor_id = p.id AND a.deleted_at IS NULL
            INNER JOIN grupos g ON g.id = a.grupo_id AND g.year_id = ?
            WHERE p.deleted_at IS NULL
            GROUP BY p.id ORDER BY COUNT(*) DESC, p.id LIMIT 1', [$this->grupo->year_id]);

        $this->alumno = DB::selectOne('SELECT a.id FROM alumnos a
            INNER JOIN matriculas m ON m.alumno_id = a.id AND m.grupo_id = ? AND m.deleted_at IS NULL
            WHERE a.deleted_at IS NULL ORDER BY a.id LIMIT 1', [$this->grupo->id]);

        $this->periodo = DB::selectOne('SELECT id, numero FROM periodos
            WHERE year_id = ? AND deleted_at IS NULL ORDER BY numero LIMIT 1', [$this->grupo->year_id]);

        $this->assertNotNull($this->profesor, 'El seed no tiene profesores con asignaturas en el año del grupo.');
        $this->assertNotNull($this->alumno, 'El grupo del seed no tiene alumnos.');
        $this->assertNotNull($this->periodo, 'El año del grupo no tiene periodos.');
    }

    public function test_las_dos_pantallas_de_actividades(): void
    {
        $asignatura = DB::selectOne('SELECT id FROM asignaturas
            WHERE grupo_id = ? AND deleted_at IS NULL ORDER BY id LIMIT 1', [$this->grupo->id]);

        // El módulo de actividades (las tablas `ws_*`) no entra en el seed, así
        // que lo que se comprueba es lo que la pantalla trae ANTES de tener
        // ninguna: los grupos y las asignaturas entre las que se elige. Es la
        // mitad que el profesor ve al entrar, y la que se rompería sola.
        $this->lectura('PUT', 'api/actividades/datos', [
            'grupo_id' => $this->grupo->id,
            'asign_id' => $asignatura->id,
        ], 'actividades-datos');

        $this->lectura('PUT', 'api/actividades/compartidas', [], 'actividades-compartidas');
    }

    public function test_los_ordinales_de_disciplina(): void
    {
        $this->lectura('PUT', 'api/ordinales/ordinales', [
            'year_id' => $this->grupo->year_id,
        ], 'ordinales');
    }

    public function test_el_calendario_del_year(): void
    {
        $this->lectura('PUT', 'api/calendario/this-year', [
            'is_prof_admin' => 1,
        ], 'calendario-this-year');
    }

    public function test_las_tres_listas_de_notas_perdidas(): void
    {
        $porPeriodo = [
            'periodo_a_calcular' => $this->periodo->numero,
            'solo_periodo' => 1,
        ];

        $this->lectura('PUT', 'api/notas-perdidas/todos', $porPeriodo, 'notas-perdidas-todos');

        $this->lectura('PUT', 'api/notas-perdidas/profesor-grupos',
            $porPeriodo + ['profesor_id' => $this->profesor->id],
            'notas-perdidas-profesor-grupos');

        $this->lectura('GET', 'api/notas-perdidas/show-profesor/'.$this->profesor->id,
            [], 'notas-perdidas-show-profesor');
    }

    public function test_la_busqueda_por_nombre_y_por_apellido(): void
    {
        // Se busca por una letra que el diccionario de anonimización usa mucho,
        // no por un nombre concreto: el seed se regenera y los nombres cambian.
        $this->lectura('PUT', 'api/buscar/por-nombre', ['texto_a_buscar' => 'a'], 'buscar-por-nombre');
        $this->lectura('PUT', 'api/buscar/por-apellido', ['texto_a_buscar' => 'a'], 'buscar-por-apellido');
    }

    public function test_los_dos_informes_del_colegio(): void
    {
        $this->lectura('PUT', 'api/informes/datos', [], 'informes-datos');

        // Doce meses, siempre doce: la lista se arma en PHP y no depende de que
        // alguien cumpla años. Por eso vale mirar el tamaño y no solo la forma.
        $r = $this->lectura('PUT', 'api/informes/cumpleanos-por-meses', [], 'informes-cumpleanos-por-meses');

        $this->assertCount(12, json_decode($r->getContent(), true), 'El informe de cumpleaños ya no trae los doce meses.');
    }

    public function test_las_asignaturas_del_piar_de_un_alumno(): void
    {
        $this->lectura('GET',
            'api/piars-asignaturas/asignaturas/'.$this->grupo->id.'/'.$this->alumno->id,
            [], 'piars-asignaturas');
    }

    public function test_la_planilla_de_ausencias_del_profesor(): void
    {
        $this->lectura('GET',
            'api/planillas-ausencias/show-profesor/'.$this->profesor->id,
            [], 'planillas-ausencias-show-profesor');
    }

    /**
     * Lo que la app de Flutter pide al arrancar.
     *
     * Pesa más que las otras: `myvc_flutter` es **una sola app para todos los
     * colegios**, así que un cambio en esta forma los rompe a todos a la vez y
     * no hay despliegue escalonado que lo amortigüe.
     */
    public function test_el_contexto_que_pide_la_app_movil(): void
    {
        $this->lectura('PUT', 'api/aplicacion-descargas/detailed', [
            'grupo_id' => $this->grupo->id,
            'year_id' => $this->grupo->year_id,
        ], 'aplicacion-descargas-detailed');
    }

    /**
     * Las notas actuales de unos alumnos concretos del grupo.
     *
     * `requested_alumnos` es una lista de OBJETOS con `alumno_id` dentro, no de
     * ids. Mandar ids da 500 con «Trying to access array offset on int», y no
     * hay nada que lo diga: el controlador recorre la lista y entra al índice.
     * Queda escrito aquí porque es lo primero que uno hace mal.
     */
    public function test_las_notas_actuales_de_un_grupo(): void
    {
        $r = $this->withToken($this->token)->putJson('/api/notas-actuales-alumnos/'.$this->grupo->id, [
            'periodo_a_calcular' => $this->periodo->numero,
            'requested_alumnos' => [['alumno_id' => $this->alumno->id]],
        ]);

        $r->assertStatus(200);

        $cuerpo = json_decode($r->getContent(), true);

        $this->assertCount(1, $cuerpo[2], 'Se pidió un alumno y no vino exactamente uno.');
        $this->assertSame($this->alumno->id, $cuerpo[2][0]['alumno_id']);

        // Es la tupla [grupo, year, alumnos] de los boletines, así que se le
        // nombra cada posición por lo mismo que allí: sin nombrarlas, la forma
        // une las tres en un objeto donde ya no se distingue de dónde salió cada
        // clave. El primer snapshot de esta ruta salió así.
        $this->compararConInstantanea('muestreo-notas-actuales-alumnos',
            $this->formaDeLaTupla($cuerpo, ['grupo', 'year', 'alumnos']));
    }

    /**
     * `PUT api/preguntas/edicion` ordena por una columna que no existe.
     *
     * `ORDER BY p.order` cuando la columna se llama `orden` —la misma consulta
     * la selecciona bien tres líneas más arriba—. Falla siempre, con datos o
     * sin ellos.
     *
     * Es el tercero de la misma familia, y la familia es lo interesante: los
     * tres fallos son SQL contra columnas que no existen, y **larastan pasó por
     * estos ficheros en la Fase 6 sin ver ninguno**, porque el error está dentro
     * de una cadena. Solo aparecen golpeando.
     */
    public function test_la_edicion_de_una_pregunta_sigue_rota(): void
    {
        $r = $this->withToken($this->token)->putJson('/api/preguntas/edicion', ['pregunta_id' => 1]);

        $r->assertStatus(500);

        $this->assertStringContainsString(
            "Unknown column 'p.order' in 'order clause'",
            (string) (json_decode($r->getContent(), true)['message'] ?? '')
        );
    }

    /**
     * Los dos certificados de estudio siguen sin su vista.
     *
     * `certificados.estudio` no está en el repo, así que las dos rutas fallan al
     * renderizar. Ya estaba documentado en el §6.5 del inventario de código
     * roto; queda aquí fijado porque son las ÚNICAS lecturas de este
     * controlador, y sin esto es el último que no mira nadie.
     *
     * Son además el único consumidor del `BolfinalesController` duplicado de la
     * raíz: el duplicado no está muerto, está vivo dentro de un camino que no
     * llega.
     */
    public function test_los_certificados_de_estudio_siguen_sin_su_vista(): void
    {
        foreach (['certificado-alumno', 'certificado-grupo'] as $cual) {
            $r = $this->withToken($this->token)->get('/api/certificados-estudio/'.$cual.'/'.$this->grupo->id);

            $r->assertStatus(500);

            $this->assertStringContainsString('certificados.estudio', $r->getContent(),
                "'{$cual}' falla, pero ya no por la vista que falta.");
        }
    }

    /**
     * Pedir por un id que no existe devuelve 500, no 404.
     *
     * La consulta hace `DB::select(...)[0]` sin mirar si vino algo. No es un
     * fallo de datos del seed —con la tabla llena pasaría igual con un id
     * cualquiera—, y se ve desde fuera: un 500 en el log del colegio que en
     * realidad era «eso no está».
     *
     * Se deja como está y se fija aquí. Cambiarlo a 404 es tocar el contrato de
     * una pantalla sin saber qué hace `myvc_front` con cada código, y eso es otro
     * trabajo.
     *
     * **Aquí estuvo también `ChangesAskedAssignment/ver-detalles`, y salió el 21
     * ago 2026 por la puerta de al lado** (05 §53). Es lo que este test enseña
     * mejor que ninguno: la ruta estaba medida —dos veces, incluso—, y lo que se
     * le preguntó fue «¿qué código devuelve con un id que no existe?». La
     * pregunta que faltaba era «¿y de quién es la fila que sí existe?»: entregaba
     * el documento, el teléfono y la dirección de cualquiera. **Medir una ruta no
     * es haberla juzgado.**
     */
    public function test_pedir_una_actividad_que_no_existe_da_500_en_vez_de_404(): void
    {
        $r = $this->withToken($this->token)
            ->putJson('/api/respuestas/actividad', ['actividad_id' => 999999]);

        $r->assertStatus(500);

        $this->assertStringContainsString(
            'Undefined array key 0',
            (string) (json_decode($r->getContent(), true)['message'] ?? ''),
            "'api/respuestas/actividad' falla, pero ya no por el índice sin comprobar."
        );
    }

    /**
     * Pide, comprueba que trajo algo, y guarda la forma.
     *
     * El «trajo algo» no es adorno: media docena de estas rutas devuelven 200
     * con la lista vacía si el id no encaja con el año del usuario, y entonces
     * el snapshot describe la nada y pasa para siempre.
     */
    private function lectura(string $verbo, string $uri, array $cuerpo, string $nombre): TestResponse
    {
        $r = $verbo === 'GET'
            ? $this->withToken($this->token)->getJson('/'.$uri)
            : $this->withToken($this->token)->putJson('/'.$uri, $cuerpo);

        $r->assertStatus(200);

        $datos = json_decode($r->getContent(), true);

        $this->assertNotSame([], $datos, "'{$uri}' respondió 200 y una lista vacía.");
        $this->assertNotNull($datos, "'{$uri}' no devolvió JSON.");

        $this->compararConInstantanea('muestreo-'.$nombre, $this->formaUnida($datos));

        return $r;
    }
}
