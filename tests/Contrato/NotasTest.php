<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * Las notas: la rejilla del profesor, guardar una nota y quién puede ver qué.
 *
 * El tercero y último de los bloques P0 de la Fase 0.2, y el que protege la
 * tabla más grande del sistema (`notas`, 1.163.307 filas en producción). Es
 * también lo que el §5 del plan declara intocable —el cálculo de notas no lo
 * especifica nadie—, así que aquí no se corrige nada: se escribe lo que hace
 * hoy, para que se note si cambia.
 */
class NotasTest extends CasoDeContrato
{
    /**
     * Una asignatura del seed, su profesor y el periodo, todo cuadrado.
     *
     * Las tres cosas tienen que casar o el test no comprueba nada, y no es
     * evidente por qué: `putDetailed` solo recorre las unidades cuyo
     * `periodo_id` coincide con el del CONTEXTO del profesor, no con el de la
     * asignatura. Si se elige la asignatura por un lado y el periodo por otro,
     * la rejilla responde 200 con `unidades: []` y todo lo de debajo pasa sin
     * haber ejecutado nada. Pasó al escribir esto.
     *
     * Y el periodo del contexto no se puede elegir: `Services\Login` reescribe
     * `users.periodo_id` al periodo `actual` del año en cada inicio de sesión,
     * así que ponérselo a mano antes de pedir el token no sirve de nada. De ahí
     * el `per.actual = 1` de la consulta — se busca la asignatura que encaja en
     * el periodo, no al revés.
     *
     * **`years.actual = 1` hace falta además, y no se notaba con un solo año.**
     * `periodos.actual` marca el periodo actual DE SU AÑO, así que los nueve
     * años del seed tienen uno; el año actual del COLEGIO lo dice `years`. Al
     * ampliar el seed a dos años (20 ago 2026) el `ORDER BY a.id` pasó a elegir
     * una asignatura de 2024, el login le ponía al profesor el periodo de 2025,
     * y de ahí salieron los tres fallos: la rejilla en 500, y el periodo cerrado
     * dejando guardar porque el cerrado era el del otro año.
     */
    private function contexto(): object
    {
        $fila = DB::selectOne('SELECT a.id AS asignatura_id, a.grupo_id, a.profesor_id,
                u.id AS user_id, u.username, un.periodo_id
            FROM asignaturas a
            INNER JOIN profesores p ON p.id = a.profesor_id AND p.deleted_at IS NULL
            INNER JOIN users u ON u.id = p.user_id AND u.is_active = 1 AND u.deleted_at IS NULL
            INNER JOIN grupos g ON g.id = a.grupo_id AND g.deleted_at IS NULL
            INNER JOIN unidades un ON un.asignatura_id = a.id AND un.deleted_at IS NULL
            INNER JOIN periodos per ON per.id = un.periodo_id AND per.actual = 1
                AND per.year_id = g.year_id AND per.deleted_at IS NULL
            INNER JOIN years y ON y.id = g.year_id AND y.actual = 1 AND y.deleted_at IS NULL
            INNER JOIN subunidades s ON s.unidad_id = un.id AND s.deleted_at IS NULL
            INNER JOIN matriculas m ON m.grupo_id = a.grupo_id AND m.deleted_at IS NULL
                AND m.estado IN ("MATR", "ASIS", "PREM")
            WHERE a.deleted_at IS NULL
            ORDER BY a.id LIMIT 1');

        $this->assertNotNull($fila,
            'El seed necesita una asignatura con unidades en el periodo actual, subunidades y alumnos matriculados.');

        return $fila;
    }

    // --------------------------------------------------------------- Rejilla

    public function test_la_forma_de_la_rejilla_del_profesor(): void
    {
        $asignatura = $this->contexto();
        $token = $this->tokenDe($asignatura->username);

        $r = $this->putJson('/api/notas/detailed', [
            'asignatura_id' => $asignatura->asignatura_id,
            'profesor_id' => $asignatura->profesor_id,
        ], ['Authorization' => 'Bearer '.$token]);

        $r->assertStatus(200);
        $this->compararConInstantanea('notas-detailed-profesor', $this->forma($r->json()));

        $this->assertNotEmpty($r->json('unidades'),
            'Con la rejilla vacía este test no comprueba nada. Ver el comentario de contexto().');
        $this->assertNotEmpty($r->json('alumnos'));
    }

    /**
     * Abrir la rejilla crea las notas que falten, con el valor por defecto de
     * la subunidad.
     *
     * No es un detalle: es de dónde salen las 1.163.307 filas de `notas`. Un
     * alumno matriculado después de crearse la subunidad no tiene fila hasta que
     * alguien abre la rejilla, y esa es toda la creación que existe.
     */
    public function test_abrir_la_rejilla_crea_las_notas_que_faltan(): void
    {
        $asignatura = $this->contexto();
        $token = $this->tokenDe($asignatura->username);

        $subunidad = DB::selectOne('SELECT s.id, s.nota_default FROM subunidades s
            INNER JOIN unidades un ON un.id = s.unidad_id AND un.asignatura_id = ? AND un.periodo_id = ?
            WHERE s.deleted_at IS NULL AND un.deleted_at IS NULL ORDER BY s.id LIMIT 1',
            [$asignatura->asignatura_id, $asignatura->periodo_id]);

        $borradas = DB::table('notas')->where('subunidad_id', $subunidad->id)->count();
        $this->assertGreaterThan(0, $borradas, 'La subunidad elegida tiene que tener notas.');

        DB::table('notas')->where('subunidad_id', $subunidad->id)->delete();

        $this->putJson('/api/notas/detailed', [
            'asignatura_id' => $asignatura->asignatura_id,
            'profesor_id' => $asignatura->profesor_id,
        ], ['Authorization' => 'Bearer '.$token])->assertStatus(200);

        $recreadas = DB::table('notas')->where('subunidad_id', $subunidad->id)->get();

        $this->assertNotEmpty($recreadas, 'La rejilla no volvió a crear las notas que faltaban.');

        foreach ($recreadas as $nota) {
            $this->assertEquals($subunidad->nota_default, $nota->nota,
                'Una nota recién creada arranca en el valor por defecto de su subunidad.');
        }
    }

    // -------------------------------------------------------------- Guardar

    /**
     * Guardar una nota deja rastro en `bitacoras`, con el valor viejo y el nuevo.
     *
     * Es la única auditoría que hay sobre las notas, y de la que depende poder
     * responder «quién le cambió esta nota a mi hijo».
     */
    public function test_guardar_una_nota_deja_el_valor_viejo_y_el_nuevo_en_la_bitacora(): void
    {
        $asignatura = $this->contexto();
        $this->permitiendoEditarNotas($asignatura);
        $token = $this->tokenDe($asignatura->username);

        $nota = $this->unaNotaDe($asignatura);
        $this->permitiendoEditarLaNota($nota);
        $anterior = (int) $nota->nota;
        $nueva = $anterior === 4 ? 3 : 4;

        $r = $this->putJson("/api/notas/update/{$nota->id}", ['nota' => $nueva],
            ['Authorization' => 'Bearer '.$token]);

        $r->assertStatus(200);
        $this->compararConInstantanea('notas-update', $this->forma($r->json()));

        $this->assertEquals($nueva, DB::table('notas')->where('id', $nota->id)->value('nota'));

        $rastro = DB::table('bitacoras')
            ->where('affected_element_type', 'Nota')
            ->where('affected_element_id', $nota->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($rastro, 'Guardar una nota tiene que dejar una fila en bitacoras.');
        $this->assertEquals($nueva, $rastro->affected_element_new_value_int);
        $this->assertEquals($anterior, $rastro->affected_element_old_value_int);
        $this->assertEquals($asignatura->user_id, $rastro->created_by);
    }

    /**
     * Con el periodo cerrado a los profesores, guardar una nota es 400.
     *
     * `User::pueden_editar_notas()` lo decide por `periodos.profes_pueden_editar_notas`
     * del periodo que diga la petición —o el del contexto si no dice ninguno—.
     */
    public function test_con_el_periodo_cerrado_un_profesor_no_guarda_notas(): void
    {
        $asignatura = $this->contexto();
        $token = $this->tokenDe($asignatura->username);

        DB::table('periodos')->where('id', $asignatura->periodo_id)
            ->update(['profes_pueden_editar_notas' => 0]);

        $nota = $this->unaNotaDe($asignatura);

        $this->putJson("/api/notas/update/{$nota->id}", ['nota' => 4.5],
            ['Authorization' => 'Bearer '.$token])
            ->assertStatus(400);

        $this->assertEquals($nota->nota, DB::table('notas')->where('id', $nota->id)->value('nota'),
            'La nota no puede haber cambiado si la petición fue rechazada.');
    }

    public function test_un_alumno_no_puede_guardar_notas(): void
    {
        $asignatura = $this->contexto();
        $token = $this->tokenDe($this->usuarioDeTipo('Alumno')->username);

        $nota = $this->unaNotaDe($asignatura);

        $this->putJson("/api/notas/update/{$nota->id}", ['nota' => 5],
            ['Authorization' => 'Bearer '.$token])
            ->assertStatus(403);

        $this->assertEquals($nota->nota, DB::table('notas')->where('id', $nota->id)->value('nota'));
    }

    public function test_el_detalle_por_periodo_es_solo_de_profesores_y_superusuarios(): void
    {
        $token = $this->tokenDe($this->usuarioDeTipo('Acudiente')->username);

        $this->putJson('/api/notas/alumno-periodo-grupo',
            ['alumno_id' => 1, 'periodo_id' => 31, 'grupo_id' => 98],
            ['Authorization' => 'Bearer '.$token])
            ->assertStatus(403);
    }

    // ------------------------------------------------------------ Quién ve

    public function test_un_alumno_ve_sus_propias_notas_sin_pedir_id(): void
    {
        $usuario = $this->usuarioDeTipo('Alumno');
        $this->notasVisiblesParaAlumnos(true);
        $token = $this->tokenDe($usuario->username);

        $r = $this->getJson('/api/notas/alumno', ['Authorization' => 'Bearer '.$token]);

        $r->assertStatus(200);
        $this->compararConInstantanea('notas-alumno-propias', $this->forma($r->json()));
    }

    /**
     * Con `years.alumnos_can_see_notas` en 0, un alumno recibe la frase de
     * bloqueo — con estado 200 y como texto suelto, no como error.
     */
    public function test_el_colegio_puede_bloquear_las_notas_a_los_alumnos(): void
    {
        $usuario = $this->usuarioDeTipo('Alumno');
        $token = $this->tokenDe($usuario->username);

        $this->notasVisiblesParaAlumnos(false);

        $r = $this->getJson('/api/notas/alumno', ['Authorization' => 'Bearer '.$token]);

        // Estado 200 y cadena pelada, no JSON ni error: el controlador hace
        // `return '...'` y Laravel la sirve como texto. El frontend compara la
        // frase, así que la frase ES el contrato.
        $r->assertStatus(200);
        $this->assertSame('Sistema bloqueado. No puedes ver las notas', $r->getContent());
    }

    /**
     * Un alumno pidiendo las notas de otro por la URL.
     *
     * Es la pregunta que el §«lo que no revisé» del plan de seguridad dejó
     * escrita sin responder: «¿puede el alumno A pedir las notas del alumno B
     * cambiando el {id} de la URL?». Aquí queda respondida y fijada.
     *
     * `getAlumno($alumno_id)` solo deduce el alumno del token cuando el
     * parámetro viene vacío; si viene, lo usa sin comprobar de quién es. La
     * única restricción del método es `$profesor_id`, y se rellena solo para
     * Profesor.
     */
    public function test_un_alumno_no_puede_pedir_las_notas_de_otro(): void
    {
        $usuario = $this->usuarioDeTipo('Alumno');
        $this->notasVisiblesParaAlumnos(true);
        $token = $this->tokenDe($usuario->username);

        $propio = DB::table('alumnos')->where('user_id', $usuario->id)->value('id');

        $ajeno = DB::table('alumnos')
            ->join('matriculas', 'matriculas.alumno_id', '=', 'alumnos.id')
            ->whereNull('alumnos.deleted_at')
            ->whereNull('matriculas.deleted_at')
            ->whereIn('matriculas.estado', ['MATR', 'ASIS', 'PREM'])
            ->where('alumnos.id', '!=', $propio)
            ->orderBy('alumnos.id')
            ->value('alumnos.id');

        $this->assertNotNull($ajeno, 'Hace falta un segundo alumno matriculado en el seed.');

        $r = $this->getJson("/api/notas/alumno/{$ajeno}", ['Authorization' => 'Bearer '.$token]);

        $r->assertStatus(403);
    }

    /**
     * Un acudiente solo ve a sus acudidos, y los ve **aunque deba la pensión**.
     *
     * Lo segundo es una decisión, no un descuido. El guard de boletines sí
     * retiene el informe de quien no está a paz y salvo, y era tentador
     * heredarlo aquí de una vez; pero retener el boletín de fin de periodo es
     * una política que el colegio ya tenía tomada, y extenderla a las notas del
     * día a día sería una NUEVA, tomada por quien escribe el guard. Se deja como
     * estaba. Cambiarlo es cambiar una palabra en la ruta.
     */
    public function test_un_acudiente_ve_a_su_acudido_aunque_deba_pero_no_a_los_demas(): void
    {
        $vinculo = DB::selectOne('SELECT p.alumno_id, u.id AS user_id, u.username
            FROM parentescos p
            INNER JOIN acudientes ac ON ac.id = p.acudiente_id AND ac.deleted_at IS NULL
            INNER JOIN users u ON u.id = ac.user_id AND u.is_active = 1 AND u.deleted_at IS NULL
            INNER JOIN alumnos a ON a.id = p.alumno_id AND a.deleted_at IS NULL
            INNER JOIN matriculas m ON m.alumno_id = a.id AND m.deleted_at IS NULL
                AND m.estado IN ("MATR", "ASIS", "PREM")
            INNER JOIN periodos per ON per.id = u.periodo_id AND per.deleted_at IS NULL
            WHERE p.deleted_at IS NULL ORDER BY p.id LIMIT 1');

        $this->assertNotNull($vinculo, 'El seed necesita un acudiente con acudido matriculado.');

        $this->notasVisiblesParaAlumnos(true);

        DB::table('alumnos')->where('id', $vinculo->alumno_id)->update(['pazysalvo' => 0]);

        $token = $this->tokenDe($vinculo->username);

        $this->getJson("/api/notas/alumno/{$vinculo->alumno_id}",
            ['Authorization' => 'Bearer '.$token])
            ->assertStatus(200);

        $ajeno = DB::table('alumnos')
            ->join('matriculas', 'matriculas.alumno_id', '=', 'alumnos.id')
            ->whereNull('alumnos.deleted_at')
            ->whereIn('matriculas.estado', ['MATR', 'ASIS', 'PREM'])
            ->where('alumnos.id', '!=', $vinculo->alumno_id)
            ->orderBy('alumnos.id')
            ->value('alumnos.id');

        $this->getJson("/api/notas/alumno/{$ajeno}", ['Authorization' => 'Bearer '.$token])
            ->assertStatus(403);
    }

    /**
     * El intento rechazado queda anotado en `bitacoras`.
     *
     * Es el mismo rastro que ya dejaba el guard de boletines, y es lo que el
     * colegio mira cuando alguien reclama.
     */
    public function test_el_intento_de_ver_notas_ajenas_queda_anotado(): void
    {
        $usuario = $this->usuarioDeTipo('Alumno');
        $this->notasVisiblesParaAlumnos(true);
        $token = $this->tokenDe($usuario->username);

        $propio = DB::table('alumnos')->where('user_id', $usuario->id)->value('id');

        $ajeno = DB::table('alumnos')->whereNull('deleted_at')
            ->where('id', '!=', $propio)->orderBy('id')->value('id');

        $this->getJson("/api/notas/alumno/{$ajeno}", ['Authorization' => 'Bearer '.$token])
            ->assertStatus(403);

        $this->assertSame(1, DB::table('bitacoras')
            ->where('created_by', $usuario->id)
            ->where('affected_element_type', 'AlumnoVerBoletin')
            ->where('affected_user_id', $ajeno)
            ->count());
    }

    /**
     * `notas.nota` es una columna `int`, así que una nota decimal se redondea
     * al guardarla y nadie avisa.
     *
     * No se cambia —tocar el cálculo de notas es justo lo que el §5 del plan
     * protege—, pero conviene que esté escrito: un frontend que mande 3,5 se
     * encuentra un 4 guardado, sin error ni aviso.
     */
    public function test_una_nota_con_decimales_se_redondea_sin_avisar(): void
    {
        $asignatura = $this->contexto();
        $this->permitiendoEditarNotas($asignatura);
        $token = $this->tokenDe($asignatura->username);

        $nota = $this->unaNotaDe($asignatura);
        $this->permitiendoEditarLaNota($nota);

        $this->putJson("/api/notas/update/{$nota->id}", ['nota' => 3.5],
            ['Authorization' => 'Bearer '.$token])
            ->assertStatus(200);

        $this->assertSame(4, (int) DB::table('notas')->where('id', $nota->id)->value('nota'),
            'La columna es int: 3,5 se guarda como 4.');
    }

    // ---------------------------------------------------------------- Apoyos

    /** Una nota de una subunidad de esa asignatura. */
    private function unaNotaDe(object $asignatura): object
    {
        $nota = DB::selectOne('SELECT n.* FROM notas n
            INNER JOIN subunidades s ON s.id = n.subunidad_id AND s.deleted_at IS NULL
            INNER JOIN unidades un ON un.id = s.unidad_id AND un.asignatura_id = ? AND un.deleted_at IS NULL
            WHERE n.deleted_at IS NULL ORDER BY n.id LIMIT 1', [$asignatura->asignatura_id]);

        $this->assertNotNull($nota, 'El seed necesita alguna nota en esa asignatura.');

        return $nota;
    }

    private function permitiendoEditarNotas(object $contexto): void
    {
        DB::table('periodos')->where('id', $contexto->periodo_id)
            ->update(['profes_pueden_editar_notas' => 1]);
    }

    /**
     * Abre el periodo **de la nota**, que no es el mismo que el del profesor.
     *
     * Hasta el 21 ago 2026 bastaba con `permitiendoEditarNotas()` —el periodo del
     * usuario— porque el candado miraba ése y no el de la fila. Al arreglar la
     * §27 estos dos tests empezaron a dar 400, y tenían razón: la nota que elige
     * `unaNotaDe()` cuelga de una unidad de un periodo **cerrado**, y el profesor
     * la estaba editando igual. Que hiciera falta tocarlos es la prueba de que
     * el arreglo hace algo.
     */
    private function permitiendoEditarLaNota(object $nota): void
    {
        $periodo = DB::selectOne('SELECT un.periodo_id FROM notas n
            INNER JOIN subunidades s ON s.id = n.subunidad_id
            INNER JOIN unidades un ON un.id = s.unidad_id
            WHERE n.id = ?', [$nota->id]);

        $this->assertNotNull($periodo, 'La nota no cuelga de ninguna unidad con periodo.');

        DB::table('periodos')->where('id', $periodo->periodo_id)
            ->update(['profes_pueden_editar_notas' => 1]);
    }

    /**
     * Enciende o apaga `alumnos_can_see_notas` donde de verdad lo va a leer el
     * contexto: en el año ACTUAL del colegio.
     *
     * No en el año del periodo que el usuario tenga ahora, que es como estaba.
     * `Services\Login` reescribe `users.periodo_id` al periodo actual en cada
     * inicio de sesión, así que el usuario acaba en el año que marca
     * `years.actual`, no en el que estaba. Con un solo año en el seed las dos
     * cosas eran la misma; con dos, el helper encendía el permiso en 2024 y el
     * alumno entraba en 2025 y recibía la frase de bloqueo.
     */
    private function notasVisiblesParaAlumnos(bool $visibles): void
    {
        DB::table('years')->where('actual', 1)->whereNull('deleted_at')
            ->update(['alumnos_can_see_notas' => $visibles ? 1 : 0]);
    }
}
