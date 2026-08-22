<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * El observador del grupo y el listado de situaciones, mirados por el resultado.
 *
 * Tres rutas de `comportamiento` que ningún test miraba al 22 ago 2026, las tres
 * `auth.personal` y las tres `PUT` que **solo leen** — no escriben nada, el verbo
 * es del front.
 *
 * Lo que las junta no es la carpeta, es una pregunta: **qué pasa cuando el grupo
 * que se pide no es de este año.** Las tres arrancan resolviendo el grupo, y dos
 * de ellas lo hacen así:
 *
 *     $grupo = DB::select($consulta, [...])[0];
 *
 * El `[0]` da por hecho que la consulta devolvió fila. La consulta filtra por
 * `g.year_id = :year_id` con el año **de quien mira**, así que basta un
 * `grupo_id` de otro año —o inexistente, o borrado— para que el array llegue
 * vacío y la petición muera. No es un rechazo: es un 500.
 *
 * `docs/migracion/05-codigo-muerto-y-roto.md` §58 documenta la misma familia en
 * las votaciones. Aquí se fija lo que hacen hoy: son endpoints vivos en los
 * dieciséis colegios y el arreglo —404 en vez de 500— cambia lo que ve una
 * pantalla, que es decisión de Joseth.
 */
class ObservadorDelGrupoTest extends CasoDeContrato
{
    /** Personal del colegio del año que tiene grupos con alumnos. */
    private function personal(): object
    {
        $grupo = $this->grupoConAlumnos();

        $usuario = DB::selectOne('SELECT u.id, u.username FROM users u
            INNER JOIN periodos p ON p.id = u.periodo_id AND p.deleted_at IS NULL
            WHERE u.tipo = "Usuario" AND u.is_active = 1 AND u.deleted_at IS NULL
              AND p.year_id = ? ORDER BY u.id LIMIT 1', [$grupo->year_id]);

        $this->assertNotNull($usuario, "El seed no tiene ningún Usuario en el año {$grupo->year_id}.");

        return (object) [
            'token' => $this->tokenDe($usuario->username),
            'year_id' => (int) $grupo->year_id,
            'grupo_id' => (int) $grupo->id,
        ];
    }

    /**
     * Un grupo que existe pero es de otro año — el caso que revienta.
     *
     * No vale un id inventado: se busca uno **real** de otro año, para que el
     * test mida el filtro por `year_id` y no la ausencia de la fila. Son dos
     * caminos distintos hasta el mismo `[0]`, y solo uno demuestra que el año
     * es lo que decide.
     */
    private function grupoDeOtroAnno(int $yearId): ?int
    {
        $otro = DB::selectOne('SELECT g.id FROM grupos g
            WHERE g.deleted_at IS NULL AND g.year_id <> ? ORDER BY g.id LIMIT 1', [$yearId]);

        return $otro ? (int) $otro->id : null;
    }

    /** Un segundo grupo del mismo año, para que «todos» se distinga de «el mío». */
    private function otroGrupoDelAnno(int $yearId): int
    {
        $modelo = DB::selectOne('SELECT grado_id, orden FROM grupos
            WHERE year_id = ? AND deleted_at IS NULL ORDER BY id LIMIT 1', [$yearId]);

        $this->assertNotNull($modelo, "El seed no tiene ningún grupo en el año {$yearId}.");

        return (int) DB::table('grupos')->insertGetId([
            'nombre' => 'Grupo de prueba del alcance',
            'abrev' => 'PRU',
            'orden' => (int) $modelo->orden + 1,
            'grado_id' => $modelo->grado_id,
            'year_id' => $yearId,
        ]);
    }

    /**
     * El observador completo de un grupo del año propio contesta, y trae al
     * grupo con sus alumnos dentro.
     *
     * Es el camino bueno, y hace falta antes que el malo: sin él, un 500 en el
     * test siguiente podría venir de cualquier parte y no del `[0]`.
     */
    public function test_el_observador_completo_del_grupo_propio_trae_el_grupo_y_sus_alumnos(): void
    {
        $quien = $this->personal();

        $respuesta = $this->withToken($quien->token)
            ->putJson('api/comportamiento/observador-completo', ['grupo_id' => $quien->grupo_id])
            ->assertOk()
            ->json();

        $this->assertArrayHasKey('grupo', $respuesta, 'La respuesta no trae el grupo.');
        $this->assertSame($quien->grupo_id, (int) $respuesta['grupo']['id'],
            'El grupo devuelto no es el que se pidió.');
        $this->assertArrayHasKey('alumnos', $respuesta['grupo'],
            'Los alumnos no vienen dentro del grupo: la forma de la respuesta cambió.');
        $this->assertNotEmpty($respuesta['grupo']['alumnos'],
            'El grupo llegó sin alumnos: el seed cambió y este test dejaría de medir el camino bueno.');
        $this->assertArrayHasKey('imagenes', $respuesta,
            'Falta `imagenes`, que el front pinta junto al observador.');
    }

    /**
     * Pedir el observador de un grupo de otro año responde **500**.
     *
     * La consulta lleva `g.year_id = :year_id` con el año de quien mira, así que
     * para un grupo de otro año no devuelve fila y el `[0]` explota. El grupo
     * existe y no está borrado: lo único que falla es el año.
     *
     * Importa más de lo que parece porque **el año no lo elige quien llama**:
     * sale de `$user->year_id`, del contexto. Un coordinador que se cambia de año
     * y vuelve a una pantalla abierta manda el mismo `grupo_id` de antes y se
     * encuentra un 500 sin haber hecho nada raro.
     */
    public function test_el_observador_de_un_grupo_de_otro_anno_revienta_con_500(): void
    {
        $quien = $this->personal();
        $ajeno = $this->grupoDeOtroAnno($quien->year_id);

        if ($ajeno === null) {
            $this->markTestSkipped('El seed solo tiene grupos de un año: no se puede montar el caso.');
        }

        $this->withToken($quien->token)
            ->putJson('api/comportamiento/observador-completo', ['grupo_id' => $ajeno])
            ->assertStatus(500);

        $sigueVivo = DB::selectOne('SELECT id FROM grupos WHERE id = ? AND deleted_at IS NULL', [$ajeno]);
        $this->assertNotNull($sigueVivo,
            'El grupo de la prueba no existe: el 500 vendría de la fila que falta, no del año.');
    }

    /**
     * Y el observador por periodo revienta igual, porque es el mismo código.
     *
     * `putObservadorPeriodo()` es `putObservadorCompleto()` copiado, con una sola
     * diferencia: filtra las notas por `p.id = :periodo_id`. El bloque que
     * resuelve el grupo —incluido el `[0]`— es idéntico byte a byte. Se fija por
     * separado porque son dos rutas y el front llama a las dos: arreglar una y
     * no la otra es el §52, el bucle copiado en cinco controladores.
     */
    public function test_el_observador_por_periodo_revienta_igual_por_ser_el_mismo_codigo(): void
    {
        $quien = $this->personal();
        $ajeno = $this->grupoDeOtroAnno($quien->year_id);

        if ($ajeno === null) {
            $this->markTestSkipped('El seed solo tiene grupos de un año: no se puede montar el caso.');
        }

        $this->withToken($quien->token)
            ->putJson('api/comportamiento/observador-periodo', ['grupo_id' => $ajeno])
            ->assertStatus(500);
    }

    /**
     * Sin `grupo_id` ninguno de los dos rechaza: revientan igual.
     *
     * `Request::input('grupo_id')` devuelve `null`, la consulta no casa con nada
     * y se llega al mismo `[0]`. Un cuerpo vacío debería ser un 422 —es lo que
     * pide CLAUDE.md para el código nuevo— y hoy es un 500. Queda fijado para
     * que el día que alguien valide, este test lo diga.
     */
    public function test_sin_grupo_id_tampoco_hay_rechazo_sino_500(): void
    {
        $quien = $this->personal();

        $this->withToken($quien->token)
            ->putJson('api/comportamiento/observador-completo', [])
            ->assertStatus(500);
    }

    /**
     * `situaciones-por-grupos` no pregunta por ningún grupo: **devuelve el
     * colegio entero del año**, con los datos personales de cada alumno.
     *
     * No recibe `grupo_id` ni lo filtra por titular: recorre todos los grupos del
     * año de quien mira y, por cada uno, saca `nombres`, `apellidos`, `celular`,
     * `direccion`, `religion` y `fecha_nac` de sus alumnos. El guard es
     * `auth.personal`, así que **cualquier profesor lo alcanza**, no solo un
     * coordinador.
     *
     * Se fija lo que devuelve, no se cierra. Es la familia del §5 de
     * `09-pendientes.md`: Joseth decidió el 21 ago no cerrar las rutas de
     * `auth.personal` porque puede dejar fuera a quien hoy trabaja con ellas.
     * Lo que este test aporta es que **el alcance quede medido**, para que la
     * decisión se tome sabiendo qué sale.
     *
     * **El segundo grupo se monta aquí dentro y no se da por supuesto**: el seed
     * trae exactamente un grupo por año, y con uno solo la respuesta es idéntica
     * tanto si devuelve «todos» como si devolviera «el mío». Sería un verde que
     * no distingue nada — la sexta vez que este repo tropieza con lo mismo, según
     * el §0.0. La transacción del test lo deshace.
     */
    public function test_situaciones_por_grupos_devuelve_todos_los_grupos_del_anno(): void
    {
        $quien = $this->personal();
        $this->otroGrupoDelAnno($quien->year_id);

        $respuesta = $this->withToken($quien->token)
            ->putJson('api/comportamiento/situaciones-por-grupos', [])
            ->assertOk()
            ->json();

        $this->assertArrayHasKey('grupos', $respuesta);

        $delAnno = DB::selectOne('SELECT COUNT(*) n FROM grupos
            WHERE deleted_at IS NULL AND year_id = ?', [$quien->year_id]);

        $this->assertSame((int) $delAnno->n, count($respuesta['grupos']),
            'No devuelve todos los grupos del año: el alcance cambió y hay que volver a medirlo.');
        $this->assertGreaterThan(1, count($respuesta['grupos']),
            'El seed tiene un solo grupo, así que este test no distingue «el suyo» de «todos».');
    }
}
