<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Los boletines: la forma del documento que el colegio imprime y entrega.
 *
 * Primero de los dos bloques P1 de la Fase 0.2. Lo que protege no es el cálculo
 * —el §5 del plan lo declara intocable— sino la ESTRUCTURA que la maqueta lee:
 * los cinco controladores de esta familia devuelven una tupla posicional
 * `[grupo, year, alumnos, escalas]` y `myvc_front` la desempaqueta por índice.
 * Cambiar el orden de ese array, o perder una clave de `alumno.asignaturas`, no
 * rompe ninguna prueba de las que ya hay y sale directo al papel.
 *
 * `AutorizacionTest` cubre QUIÉN puede pedir cada boletín. Aquí se cubre QUÉ
 * devuelve, que es lo otro que la migración puede romper sin avisar.
 */
class BoletinesTest extends CasoDeContrato
{
    /**
     * Las tres maquetas de periodo: cuántos elementos trae su tupla y de qué
     * cuelgan las notas del alumno.
     *
     * Van juntas en un proveedor porque son copias: `Boletines2Controller` y
     * `Boletines3Controller` se crearon duplicando el primero, y el agujero de
     * autorización existió justamente porque arreglar uno no arreglaba los otros
     * dos. Las dos columnas de al lado son en lo que ya se han separado, y no se
     * ve leyendo las rutas:
     *
     * - **`boletines3` devuelve tres elementos, no cuatro**: no manda las
     *   escalas de valoración. Es coherente con su maqueta, que agrupa por áreas
     *   y trae el desempeño ya resuelto en cada fila (`desempenio_per1`), así que
     *   no necesita la tabla para traducir la nota.
     * - **y hace `unset($alumno->asignaturas)`** antes de responder. Sus notas
     *   van en `areas`. Un test que mirase `asignaturas` en las tres daría por
     *   vacío un boletín que está completo.
     *
     * Van en el proveedor, a la vista, en vez de escondidas en un `assert`.
     */
    public static function familias(): array
    {
        return [
            'boletines' => ['boletines', 4, 'asignaturas'],
            'boletines2' => ['boletines2', 4, 'asignaturas'],
            'boletines3' => ['boletines3', 3, 'areas'],
        ];
    }

    /** Lo mismo para las dos de finales: preescolar tampoco manda las escalas. */
    public static function familiasDeFinales(): array
    {
        return [
            'bolfinales' => ['bolfinales', 4],
            'bolfinales-preescolar' => ['bolfinales-preescolar', 3],
        ];
    }

    /**
     * El grupo del seed y un usuario del colegio que lo pueda ver entero.
     *
     * El año importa y no se elige: los boletines calculan contra
     * `$user->year_id`, que sale del periodo del usuario, y `Services\Login` lo
     * reescribe al periodo `actual` en cada inicio de sesión. Si el año del
     * usuario no es el del grupo, la respuesta sale con la lista de alumnos
     * vacía y el test pasa sin haber calculado ningún boletín — la misma trampa
     * que costó tiempo en `NotasTest`.
     */
    private function grupoYPersonal(): array
    {
        $grupo = DB::selectOne('SELECT g.id, g.year_id FROM grupos g
            INNER JOIN matriculas m ON m.grupo_id = g.id AND m.deleted_at IS NULL
                AND m.estado IN ("MATR","ASIS","PREM")
            WHERE g.deleted_at IS NULL
            GROUP BY g.id, g.year_id ORDER BY COUNT(m.id) DESC, g.id LIMIT 1');

        $this->assertNotNull($grupo, 'El seed no tiene ningún grupo con alumnos matriculados.');

        $usuario = DB::selectOne('SELECT u.username FROM users u
            INNER JOIN periodos p ON p.id = u.periodo_id AND p.deleted_at IS NULL
            WHERE u.tipo = "Usuario" AND u.is_active = 1 AND u.deleted_at IS NULL
              AND p.year_id = ? ORDER BY u.id LIMIT 1', [$grupo->year_id]);

        $this->assertNotNull($usuario,
            "El seed no tiene ningún Usuario en el año {$grupo->year_id}, que es el del grupo.\n".
            'Sin eso el boletín sale vacío y el test no comprueba nada.');

        return [$grupo, $this->tokenDe($usuario->username)];
    }

    /** Un alumno del grupo, en el formato que manda el frontend. */
    private function unAlumnoDe(int $grupoId): array
    {
        $fila = DB::selectOne('SELECT a.id alumno_id FROM alumnos a
            INNER JOIN matriculas m ON m.alumno_id = a.id AND m.deleted_at IS NULL
                AND m.estado IN ("MATR","ASIS","PREM")
            WHERE m.grupo_id = ? AND a.deleted_at IS NULL ORDER BY a.id LIMIT 1', [$grupoId]);

        $this->assertNotNull($fila, "El grupo {$grupoId} no tiene alumnos matriculados.");

        return ['alumno_id' => $fila->alumno_id, 'grupo_id' => $grupoId];
    }

    /**
     * La forma de la respuesta, con cada posición nombrada, y el largo comprobado.
     *
     * **A esta respuesta no se le puede pasar `forma()` entera.** Para `forma()`
     * un array de claves 0..3 es una LISTA, y de una lista guarda solo la forma
     * del primer elemento — que es lo correcto cuando los elementos son
     * homogéneos, el caso de `alumnos`, y es desastroso aquí, donde la posición 0
     * es el grupo y la 3 son las escalas. El snapshot habría guardado el grupo,
     * tirado el boletín entero, y pasado siempre. Se descubrió al mirar el primer
     * `.json` generado, no leyendo el código.
     *
     * Comprobar el largo tampoco es puntillería: el frontend lee `respuesta[2]`
     * para los alumnos. Si alguna versión del framework serializara esto como
     * objeto con claves, el JSON seguiría siendo válido y la pantalla quedaría
     * en blanco.
     */
    private function formaDeLaTupla(array $cuerpo, array $nombres): array
    {
        $this->assertSame(range(0, count($nombres) - 1), array_keys($cuerpo),
            'La respuesta dejó de ser una tupla posicional de '.count($nombres).' elementos.');

        $forma = [];

        foreach ($nombres as $posicion => $nombre) {
            $forma[$nombre] = $this->forma($cuerpo[$posicion]);
        }

        return $forma;
    }

    /** Los nombres de las posiciones, recortados al largo que devuelve la familia. */
    private function posiciones(int $largo): array
    {
        return array_slice(['grupo', 'year', 'alumnos', 'escalas'], 0, $largo);
    }

    // ------------------------------------------------------- Boletín de periodo

    #[DataProvider('familias')]
    public function test_la_forma_del_boletin_de_un_alumno(string $familia, int $largo, string $notas): void
    {
        [$grupo, $token] = $this->grupoYPersonal();

        $r = $this->putJson("/api/{$familia}/detailed-notas/{$grupo->id}", [
            'requested_alumnos' => [$this->unAlumnoDe($grupo->id)],
        ], ['Authorization' => 'Bearer '.$token]);

        $r->assertStatus(200);

        $cuerpo = $r->json();

        $this->assertCount(1, $cuerpo[2],
            'Se pidió un alumno y volvieron '.count($cuerpo[2]).'.');
        $this->assertNotEmpty($cuerpo[2][0][$notas] ?? [],
            "El boletín salió sin `{$notas}`. Con esa lista vacía el test no comprueba\n".
            'nada: revisa que el año del usuario sea el del grupo.');

        $this->compararConInstantanea("{$familia}-detailed-notas",
            $this->formaDeLaTupla($cuerpo, $this->posiciones($largo)));
    }

    #[DataProvider('familias')]
    public function test_la_forma_del_boletin_del_grupo_entero(string $familia, int $largo, string $notas): void
    {
        [$grupo, $token] = $this->grupoYPersonal();

        $r = $this->putJson("/api/{$familia}/detailed-notas-group/{$grupo->id}", [],
            ['Authorization' => 'Bearer '.$token]);

        $r->assertStatus(200);

        $cuerpo = $r->json();

        $this->assertGreaterThan(1, count($cuerpo[2]),
            'El boletín de grupo trajo un alumno o ninguno.');
        $this->assertNotEmpty($cuerpo[2][0][$notas] ?? [], "El boletín salió sin `{$notas}`.");

        $this->compararConInstantanea("{$familia}-detailed-notas-group",
            $this->formaDeLaTupla($cuerpo, $this->posiciones($largo)));
    }

    /**
     * La única ruta GET de la familia, y la única que acepta el periodo por URL.
     *
     * Aquí el 3 va escrito y no viene del proveedor: **las tres familias
     * devuelven tres elementos en esta ruta**, incluidas las dos que sí mandan
     * las escalas en las otras. El acumulado del año se sirve sin la tabla de
     * desempeños en las tres maquetas. A simple vista las cuatro rutas de cada
     * controlador parecen la misma, y no lo son.
     *
     * El `de_usuario` de la URL no es decorativo ni es «un valor cualquiera que
     * funciona»: es el único junto con `todos` que hace que este informe calcule
     * algo. Ver el test de abajo.
     */
    #[DataProvider('familias')]
    public function test_la_forma_del_boletin_acumulado_del_year(string $familia): void
    {
        [$grupo, $token] = $this->grupoYPersonal();

        $r = $this->getJson("/api/{$familia}/detailed-notas-year/{$grupo->id}/de_usuario",
            ['Authorization' => 'Bearer '.$token]);

        $r->assertStatus(200);

        $cuerpo = $r->json();

        $this->assertNotEmpty($cuerpo[2], 'El acumulado del año salió sin alumnos.');
        $this->assertNotEmpty($cuerpo[2][0]['asignaturas'][0]['periodos'] ?? [],
            'El acumulado salió sin periodos, que es lo único que este informe acumula.');

        $this->compararConInstantanea("{$familia}-detailed-notas-year",
            $this->formaDeLaTupla($cuerpo, $this->posiciones(3)));
    }

    /**
     * Sin el segmento de la URL, el acumulado del año sale entero en ceros. Hoy.
     *
     * Encontrado escribiendo el test de arriba, al ver `periodos: []` en el
     * primer snapshot. **Hay dos funciones cuyos nombres se diferencian en una
     * letra y que no aceptan lo mismo:**
     *
     * - `Periodo::hastaPeriodoN($year_id, $periodo_a_calcular = 10)` toma un
     *   NÚMERO, y su 10 significa «hasta el periodo 10», o sea todos.
     * - `Periodo::hastaPeriodo($year_id, $periodos_a_calcular = 'de_usuario')`
     *   toma una CADENA, y solo entiende `de_colegio`, `de_usuario` y `todos`.
     *
     * `getDetailedNotasYear($grupo_id, $periodo_a_calcular = 10)` lleva el
     * default de la primera y se lo pasa por debajo a la segunda. Ninguna rama
     * del `if` casa con `10`, así que `$periodos` se queda en el `new stdClass()`
     * con el que se inicializa; el `foreach` no itera y el `count()` sobre un
     * stdClass lanza TypeError, que el `try/catch` de `alumnoAsignaturasPeriodosDetailed`
     * convierte en `nota = 0`. Respuesta 200, informe en blanco, sin una línea
     * en el log. Lo mismo en `boletines2` y `boletines3`: son copias.
     *
     * **Aquí no se arregla.** La Fase 0 escribe lo que hace hoy, y cuál de los
     * dos criterios es el correcto —«hasta el periodo del usuario» o «todos»—
     * lo decide el colegio, no el diff. Lo que hace este test es que el día que
     * se decida, se note si cambia.
     *
     * Que `subunidadesPerdidas` falte en la respuesta es la prueba de que la
     * excepción saltó: es la línea siguiente a la que revienta.
     */
    #[DataProvider('familias')]
    public function test_el_acumulado_del_year_sin_parametro_sale_en_ceros(string $familia): void
    {
        [$grupo, $token] = $this->grupoYPersonal();

        $r = $this->getJson("/api/{$familia}/detailed-notas-year/{$grupo->id}",
            ['Authorization' => 'Bearer '.$token]);

        $r->assertStatus(200);

        $alumno = $r->json('2.0');

        $this->assertSame(0, $alumno['promedio_year'],
            'El acumulado sin parámetro ya no sale en ceros. Si se arregló, borra este test.');

        $asignatura = $alumno['asignaturas'][0];

        $this->assertSame([], $asignatura['periodos']);
        $this->assertSame(0, $asignatura['nota_asignatura_year']);
        $this->assertArrayNotHasKey('subunidadesPerdidas', $asignatura,
            'Apareció `subunidadesPerdidas`: la excepción que la impedía ya no salta.');
    }

    // -------------------------------------------------------- Boletines finales

    #[DataProvider('familiasDeFinales')]
    public function test_la_forma_del_boletin_final_de_un_alumno(string $familia, int $largo): void
    {
        [$grupo, $token] = $this->grupoYPersonal();

        $r = $this->putJson("/api/{$familia}/detailed-notas-year/{$grupo->id}", [
            'requested_alumnos' => [$this->unAlumnoDe($grupo->id)],
        ], ['Authorization' => 'Bearer '.$token]);

        $r->assertStatus(200);

        $cuerpo = $r->json();

        $this->assertCount(1, $cuerpo[2],
            'Se pidió un alumno y volvieron '.count($cuerpo[2]).'.');

        $this->compararConInstantanea("{$familia}-detailed-notas-year",
            $this->formaDeLaTupla($cuerpo, $this->posiciones($largo)));
    }

    #[DataProvider('familiasDeFinales')]
    public function test_la_forma_del_boletin_final_del_grupo(string $familia, int $largo): void
    {
        [$grupo, $token] = $this->grupoYPersonal();

        $r = $this->putJson("/api/{$familia}/detailed-notas-year-group/{$grupo->id}", [],
            ['Authorization' => 'Bearer '.$token]);

        $r->assertStatus(200);

        $cuerpo = $r->json();

        $this->assertGreaterThan(1, count($cuerpo[2]),
            'El boletín final de grupo trajo un alumno o ninguno.');

        $this->compararConInstantanea("{$familia}-detailed-notas-year-group",
            $this->formaDeLaTupla($cuerpo, $this->posiciones($largo)));
    }

    // ------------------------------------------------------------- Contadores

    /**
     * Los dos contadores del año que mueve la pantalla de certificados.
     *
     * Se prueban porque son las dos únicas escrituras de `BolfinalesController`
     * y porque el UPDATE hermano de `detailedNotasGrupo` ya estuvo roto en
     * silencio: buscaba por `years.year_id`, columna que no existe, y respondía
     * 500 solo desde el «Certificado periodos», que es el único sitio que manda
     * `aumentar_contador`. El test lee el valor de vuelta en vez de conformarse
     * con el 200.
     */
    public function test_los_contadores_del_year_se_guardan(): void
    {
        [$grupo, $token] = $this->grupoYPersonal();
        $cab = ['Authorization' => 'Bearer '.$token];

        $casos = [
            'cambiar-contador-certificados' => 'contador_certificados',
            'cambiar-contador-folios' => 'contador_folios',
        ];

        foreach ($casos as $ruta => $columna) {
            $antes = DB::selectOne("SELECT {$columna} v FROM years WHERE id = ?", [$grupo->year_id])->v;
            $nuevo = (int) $antes + 7;

            $this->putJson("/api/bolfinales/{$ruta}", ['contador' => $nuevo], $cab)
                ->assertStatus(200);

            $this->assertSame($nuevo,
                (int) DB::selectOne("SELECT {$columna} v FROM years WHERE id = ?", [$grupo->year_id])->v,
                "PUT bolfinales/{$ruta} respondió 200 pero no guardó `years.{$columna}`.");
        }
    }
}
