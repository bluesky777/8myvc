<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * El lado del alumno de las actividades: abrir la que le toca, y solo esa.
 *
 * Sale de la cobertura del 21 de agosto. De las cinco rutas de
 * `MisActividadesController`, tres son las que alcanza un alumno y **ninguna
 * puede llevar `auth.personal`**: responder un examen es justo lo que hace un
 * alumno. Dos de las tres las cerró la §20 con una comprobación dentro
 * (`exigirQueLaResueltaSeaSuya`), y la tercera —`mi-actividad`, que es la puerta
 * de entrada— se quedó fuera porque su identificador no es
 * `actividad_resuelta_id` sino `actividad_id`: no era la misma pregunta. De
 * quién es el intento ya estaba resuelto; **a qué actividad se puede entrar** no
 * lo comprobaba nadie. Ver 05 §43.
 *
 * `ws_actividades` está vacía en el seed, así que las actividades las monta el
 * test y la transacción las deshace — la misma regla que ya usó `OpcionesTest`.
 */
class MisActividadesTest extends CasoDeContrato
{
    /**
     * Un alumno del año en curso, un grupo ajeno de ese mismo año, y las dos
     * asignaturas: la suya y la del ajeno.
     *
     * Dos trampas del seed, las dos ya pagadas antes en esta migración:
     *
     * 1. **El año no se elige.** `Services\Login` reescribe `users.periodo_id` al
     *    periodo del año `actual` en cada inicio de sesión, y los controladores
     *    calculan contra `$user->year_id`. Elegir al sujeto por el periodo que
     *    tiene guardado ANTES de entrar monta el examen en un año y lo pide desde
     *    otro: entonces el 403 sale por el año y no por el grupo, y el test pasa
     *    sin comprobar lo que dice. Lo documenta `tokenDelPersonalDe()`.
     * 2. **No hay ningún grupo ajeno.** El seed copia UN grupo por año —84 del
     *    año 7, 98 del 8— y el alumno está matriculado en los dos, así que un
     *    `grupo_id != el suyo` devuelve *el otro grupo suyo*, no uno ajeno. Es lo
     *    que ya costó 36 rutas mal medidas en la §16. El grupo ajeno hay que
     *    montarlo: lo que falta es una fila, no el estado de una que ya exista.
     */
    private function alumnoYGrupoAjeno(): object
    {
        $year = DB::selectOne('SELECT id FROM years WHERE actual = 1 AND deleted_at IS NULL ORDER BY year DESC LIMIT 1');

        $this->assertNotNull($year, 'El seed no tiene ningún año actual; el login no sabría dónde poner al alumno.');

        $alumno = DB::selectOne('SELECT u.username, a.id AS alumno_id, m.grupo_id
            FROM users u
            INNER JOIN alumnos a ON a.user_id = u.id AND a.deleted_at IS NULL
            INNER JOIN matriculas m ON m.alumno_id = a.id AND m.deleted_at IS NULL
                AND m.estado IN ("MATR","ASIS","PREM")
            INNER JOIN grupos g ON g.id = m.grupo_id AND g.deleted_at IS NULL AND g.year_id = ?
            WHERE u.tipo = "Alumno" AND u.is_active = 1 AND u.deleted_at IS NULL
            ORDER BY u.id LIMIT 1', [$year->id]);

        $this->assertNotNull($alumno, 'El seed no tiene ningún alumno matriculado en el año actual.');

        $periodo = DB::selectOne('SELECT id FROM periodos WHERE year_id = ? AND deleted_at IS NULL ORDER BY id LIMIT 1', [$year->id]);
        $mio = DB::selectOne('SELECT * FROM grupos WHERE id = ?', [$alumno->grupo_id]);
        $suya = DB::selectOne('SELECT * FROM asignaturas WHERE grupo_id = ? AND deleted_at IS NULL ORDER BY id LIMIT 1', [$mio->id]);

        $ajeno = $this->grupoAjenoDelMismoAnio((int) $mio->year_id);

        return (object) [
            'username' => $alumno->username,
            'persona_id' => (int) $alumno->alumno_id,
            'token' => $this->tokenDe($alumno->username),
            'periodo_id' => (int) $periodo->id,
            'grupo_propio' => (int) $mio->id,
            'asignatura_propia' => (int) $suya->id,
            'asignatura_ajena' => (int) $ajeno->asignatura_id,
        ];
    }

    /** Un examen con una pregunta de dos opciones, la segunda correcta. */
    private function montarExamen(int $asignatura, int $periodo, int $inAction = 1): object
    {
        DB::insert('INSERT INTO ws_actividades (asignatura_id, periodo_id, descripcion, tipo, compartida,
                        para_alumnos, can_upload, in_action, duracion_preg, duracion_exam, oportunidades,
                        one_by_one, contenido, created_at, updated_at)
                    VALUES (?, ?, "Examen", "E", 0, 0, 0, ?, 60, 3600, 1, 0, "", ?, ?)',
            [$asignatura, $periodo, $inAction, now(), now()]);
        $actividad = (int) DB::getPdo()->lastInsertId();

        DB::insert('INSERT INTO ws_preguntas (actividad_id, enunciado, orden, tipo_pregunta, puntos,
                        opcion_otra, created_at, updated_at)
                    VALUES (?, "¿Cuál es la capital de Colombia?", 1, "U", 1, 0, ?, ?)', [$actividad, now(), now()]);
        $pregunta = (int) DB::getPdo()->lastInsertId();

        $opciones = [];

        foreach ([['Medellín', 0], ['Bogotá', 1]] as $orden => [$texto, $correcta]) {
            DB::insert('INSERT INTO ws_opciones (pregunta_id, definicion, orden, is_correct, created_at, updated_at)
                        VALUES (?, ?, ?, ?, ?, ?)', [$pregunta, $texto, $orden, $correcta, now(), now()]);
            $opciones[] = (int) DB::getPdo()->lastInsertId();
        }

        return (object) ['actividad_id' => $actividad, 'pregunta_id' => $pregunta, 'opciones' => $opciones];
    }

    public function test_un_alumno_no_abre_el_examen_de_otro_grupo(): void
    {
        $alumno = $this->alumnoYGrupoAjeno();
        $examen = $this->montarExamen($alumno->asignatura_ajena, $alumno->periodo_id);

        $r = $this->withToken($alumno->token)
            ->putJson('/api/mis-actividades/mi-actividad', ['actividad_id' => $examen->actividad_id]);

        $r->assertStatus(403);
        $this->assertStringNotContainsString('capital de Colombia', $r->content(),
            'Le llega el enunciado de un examen que no es de ninguna asignatura suya.');
    }

    /**
     * La otra mitad, y la razón de que la comprobación vaya ANTES de crear el
     * intento: el método empieza abriendo uno, así que sin esto abrir el examen
     * de otro grupo dejaba una fila a nombre del que miraba — y esa fila es la
     * que sale luego en la pantalla de corregir de un profesor que no es el suyo.
     */
    public function test_abrir_el_examen_de_otro_grupo_no_le_abre_un_intento(): void
    {
        $alumno = $this->alumnoYGrupoAjeno();
        $examen = $this->montarExamen($alumno->asignatura_ajena, $alumno->periodo_id);

        $antes = DB::table('ws_actividades_resueltas')->count();

        $this->withToken($alumno->token)
            ->putJson('/api/mis-actividades/mi-actividad', ['actividad_id' => $examen->actividad_id]);

        $this->assertSame($antes, DB::table('ws_actividades_resueltas')->count());
    }

    /** Y la de su propio grupo se abre, que es lo que no se puede romper. */
    public function test_un_alumno_abre_el_examen_de_su_grupo(): void
    {
        $alumno = $this->alumnoYGrupoAjeno();
        $examen = $this->montarExamen($alumno->asignatura_propia, $alumno->periodo_id);

        $r = $this->withToken($alumno->token)
            ->putJson('/api/mis-actividades/mi-actividad', ['actividad_id' => $examen->actividad_id]);

        $r->assertStatus(200);
        $this->assertSame($examen->actividad_id, $r->json('actividad.id'));
        $this->assertSame('¿Cuál es la capital de Colombia?', $r->json('actividad.preguntas.0.enunciado'));
        $this->assertSame($alumno->persona_id, (int) $r->json('actividad_resuelta.persona_id'));
    }

    /**
     * Compartir con otro grupo es una función viva —`ws_actividades_compartidas`
     * es de donde saca sus grupos la pantalla de corregir—, así que un alumno del
     * grupo compartido tiene que poder abrirla aunque la asignatura no sea suya.
     * Comprobar solo la asignatura habría apagado el compartir sin que se notara.
     */
    public function test_un_alumno_abre_la_que_le_comparten_desde_otro_grupo(): void
    {
        $alumno = $this->alumnoYGrupoAjeno();
        $examen = $this->montarExamen($alumno->asignatura_ajena, $alumno->periodo_id);

        DB::insert('INSERT INTO ws_actividades_compartidas (actividad_id, grupo_id, created_at, updated_at)
                    VALUES (?, ?, ?, ?)', [$examen->actividad_id, $alumno->grupo_propio, now(), now()]);

        $this->withToken($alumno->token)
            ->putJson('/api/mis-actividades/mi-actividad', ['actividad_id' => $examen->actividad_id])
            ->assertStatus(200)
            ->assertJsonPath('actividad.id', $examen->actividad_id);
    }

    /**
     * Al personal no se le toca: `panel.mi_actividad` tiene dos entradas en el
     * front y son de bandos distintos —`misActividades.html`, la lista del
     * alumno, y `actividades.html`, la del profesor—, que es justo lo que impide
     * cerrar la ruta con `auth.personal`.
     */
    public function test_el_personal_sigue_abriendo_cualquier_actividad(): void
    {
        $alumno = $this->alumnoYGrupoAjeno();
        $examen = $this->montarExamen($alumno->asignatura_ajena, $alumno->periodo_id);

        $this->withToken($this->tokenDelPersonalLlano())
            ->putJson('/api/mis-actividades/mi-actividad', ['actividad_id' => $examen->actividad_id])
            ->assertStatus(200)
            ->assertJsonPath('actividad.id', $examen->actividad_id);
    }

    /**
     * Un id que no existe daba 500 **con el intento ya escrito**, porque
     * `datosActividadConRespuestas()` indexa con `[0]` el resultado de la
     * consulta. Ahora se resuelve la actividad antes de tocar nada.
     */
    public function test_una_actividad_que_no_existe_es_404(): void
    {
        $alumno = $this->usuarioDeTipo('Alumno');
        $maximo = (int) DB::table('ws_actividades')->max('id');

        $this->withToken($this->tokenDe($alumno->username))
            ->putJson('/api/mis-actividades/mi-actividad', ['actividad_id' => $maximo + 1000])
            ->assertStatus(404);
    }

    /**
     * La clave del examen no viaja.
     *
     * `datosActividadConRespuestas()` enumera las columnas de `ws_opciones` una a
     * una y deja fuera `is_correct`, que es una decisión y no una casualidad: un
     * `SELECT *` ahí le entrega al alumno las respuestas correctas con el
     * enunciado. Este test es lo que hace que se note el día que alguien lo
     * simplifique.
     */
    public function test_la_respuesta_correcta_no_sale_en_el_examen(): void
    {
        $alumno = $this->alumnoYGrupoAjeno();
        $examen = $this->montarExamen($alumno->asignatura_propia, $alumno->periodo_id);

        $r = $this->withToken($alumno->token)
            ->putJson('/api/mis-actividades/mi-actividad', ['actividad_id' => $examen->actividad_id])
            ->assertStatus(200);

        $opciones = $r->json('actividad.preguntas.0.opciones');

        $this->assertCount(2, $opciones);

        foreach ($opciones as $opcion) {
            $this->assertArrayNotHasKey('is_correct', $opcion, 'La clave del examen viaja al alumno.');
        }
    }

    /**
     * `in_action` es el interruptor con el que el profesor abre el examen, y hasta
     * el 21 ago 2026 no lo miraba nadie: se leía antes de que empezara. Decidido
     * por Joseth — se cierra. Ver 05 §43.1.
     */
    public function test_un_alumno_no_abre_un_examen_que_no_esta_en_accion(): void
    {
        $alumno = $this->alumnoYGrupoAjeno();
        $examen = $this->montarExamen($alumno->asignatura_propia, $alumno->periodo_id, inAction: 0);

        $r = $this->withToken($alumno->token)
            ->putJson('/api/mis-actividades/mi-actividad', ['actividad_id' => $examen->actividad_id]);

        $r->assertStatus(403);
        $this->assertStringNotContainsString('capital de Colombia', $r->content());
    }

    /** Y tampoco antes de la hora, si el profesor le puso una. */
    public function test_un_alumno_no_abre_un_examen_antes_de_su_hora(): void
    {
        $alumno = $this->alumnoYGrupoAjeno();
        $examen = $this->montarExamen($alumno->asignatura_propia, $alumno->periodo_id);

        DB::table('ws_actividades')->where('id', $examen->actividad_id)
            ->update(['inicia_at' => now('America/Bogota')->addDay()]);

        $this->withToken($alumno->token)
            ->putJson('/api/mis-actividades/mi-actividad', ['actividad_id' => $examen->actividad_id])
            ->assertStatus(403);
    }

    /** Pasada la hora sí, que es la otra mitad y la que se rompería sin darse cuenta. */
    public function test_un_alumno_abre_un_examen_cuya_hora_ya_paso(): void
    {
        $alumno = $this->alumnoYGrupoAjeno();
        $examen = $this->montarExamen($alumno->asignatura_propia, $alumno->periodo_id);

        DB::table('ws_actividades')->where('id', $examen->actividad_id)
            ->update(['inicia_at' => now('America/Bogota')->subDay()]);

        $this->withToken($alumno->token)
            ->putJson('/api/mis-actividades/mi-actividad', ['actividad_id' => $examen->actividad_id])
            ->assertStatus(200);
    }

    /**
     * El profesor la abre antes que nadie: eso ES la vista previa, y es la razón
     * de que la regla se aplique solo a la familia.
     */
    public function test_el_personal_abre_la_que_no_esta_en_accion(): void
    {
        $alumno = $this->alumnoYGrupoAjeno();
        $examen = $this->montarExamen($alumno->asignatura_propia, $alumno->periodo_id, inAction: 0);

        $this->withToken($this->tokenDelPersonalLlano())
            ->putJson('/api/mis-actividades/mi-actividad', ['actividad_id' => $examen->actividad_id])
            ->assertStatus(200);
    }

    /**
     * Entregar es entregar, desde el 21 ago 2026.
     *
     * `finalizar-actividad` ponía `terminado = true` y nadie volvía a mirar esa
     * columna, así que se seguía respondiendo después y el profesor corregía lo
     * último escrito. Decidido por Joseth — se cierra. La consecuencia elegida a
     * sabiendas: quien entregue sin querer se queda fuera, porque no hay ninguna
     * ruta que reabra un intento.
     */
    public function test_despues_de_entregar_ya_no_se_responde(): void
    {
        $alumno = $this->alumnoYGrupoAjeno();
        $examen = $this->montarExamen($alumno->asignatura_propia, $alumno->periodo_id);

        $resuelta = (int) $this->withToken($alumno->token)
            ->putJson('/api/mis-actividades/mi-actividad', ['actividad_id' => $examen->actividad_id])
            ->assertStatus(200)
            ->json('actividad_resuelta.id');

        $responder = fn (int $opcion) => $this->withToken($alumno->token)
            ->putJson('/api/mis-actividades/seleccionar-opcion', [
                'actividad_resuelta_id' => $resuelta,
                'pregunta_id' => $examen->pregunta_id,
                'tipo_pregunta' => 'U',
                'opcion_id' => $opcion,
            ]);

        $responder($examen->opciones[0])->assertStatus(201);

        $this->withToken($alumno->token)
            ->putJson('/api/mis-actividades/finalizar-actividad', ['actividad_resuelta_id' => $resuelta])
            ->assertStatus(200);

        $responder($examen->opciones[1])->assertStatus(403);

        $this->assertSame(
            $examen->opciones[0],
            (int) DB::table('ws_respuestas')->where('actividad_resuelta_id', $resuelta)->value('opcion_id'),
            'La respuesta que queda tiene que ser la que había al entregar.'
        );
    }
}
