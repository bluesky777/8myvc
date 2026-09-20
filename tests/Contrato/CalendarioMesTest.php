<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * **`PUT calendario/mes`: el mes que pinta la pantalla, con los cumpleaños
 * calculados y sin las filas que no le tocan a quien pregunta.**
 *
 * Lo que esta clase fija no es que la ruta conteste 200 —eso ya lo diría
 * cualquier cosa— sino **quién recibe qué**, que es la pregunta del lote J en su
 * forma de siempre: *un test que fija un 200 no dice que la ruta esté bien, dice
 * que alguien miró otra cosa.*
 *
 * ## Lo que sustituye, y por qué no se podía arreglar donde estaba
 *
 * `putSincronizarCumples()` genera los cumpleaños como **filas** y hace dos
 * cosas que no tienen arreglo dentro de ese diseño: **sella el año** —cambia el
 * año de nacimiento por `$user->year`, así que los 507 cumpleaños de la base
 * caen todos en 2025 y en 2026 no hay ninguno— y **congela la matrícula**,
 * porque une contra los grupos de `$user->year_id` el día en que alguien pulsó
 * el botón. Y empieza por un `DELETE` sin `WHERE` de año.
 *
 * Los dos casos que fijan eso son
 * `test_los_cumples_se_calculan_sobre_el_anio_pedido` y su hermano de 2027: **se
 * ponen rojos en cuanto alguien vuelva a leer los cumpleaños de la tabla**, sin
 * necesidad de saber por qué.
 */
class CalendarioMesTest extends CasoDeContrato
{
    /** Pide el mes con ese token y devuelve la respuesta entera. */
    private function mes(string $token, int $year, int $numero): array
    {
        $r = $this->withToken($token)->putJson('/api/calendario/mes', ['year' => $year, 'mes' => $numero]);

        $r->assertStatus(200);

        return $r->json();
    }

    /** Las claves de los eventos que ve ese token en ese mes. */
    private function clavesQueVe(string $token, int $year = 2026, int $numero = 9): array
    {
        return array_column($this->mes($token, $year, $numero)['eventos'], 'clave');
    }

    /**
     * Un evento manual, dirigido a quien se diga.
     *
     * @param  list<array{publico: string, grupo_id: int|null}>  $destinatarios
     */
    private function evento(string $titulo, array $destinatarios = [], int $soloProfes = 0): int
    {
        $id = DB::table('calendario')->insertGetId([
            'title' => $titulo,
            'solo_profes' => $soloProfes,
            'allDay' => 1,
            'start' => '2026-09-15 08:00:00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ($destinatarios as $destinatario) {
            DB::table('calendario_destinatarios')->insert([
                'calendario_id' => $id,
                'publico' => $destinatario['publico'],
                'grupo_id' => $destinatario['grupo_id'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $id;
    }

    /** El grupo del alumno del seed, resuelto por el mismo camino que `ContextoDeUsuario`. */
    private function grupoDelAlumno(int $userId): int
    {
        $fila = DB::selectOne('SELECT g.id FROM users u
            INNER JOIN alumnos a ON a.user_id = u.id AND a.deleted_at IS NULL
            INNER JOIN matriculas m ON m.alumno_id = a.id AND m.deleted_at IS NULL
            INNER JOIN grupos g ON g.id = m.grupo_id AND g.deleted_at IS NULL
            INNER JOIN periodos p ON p.id = u.periodo_id AND p.year_id = g.year_id
            WHERE u.id = ? LIMIT 1', [$userId]);

        $this->assertNotNull($fila, 'El alumno del seed no resuelve grupo: sin eso este test no mide nada.');

        return (int) $fila->id;
    }

    /**
     * **El rango son semanas completas, y el ejemplo del contrato sale exacto.**
     *
     * Septiembre de 2026 → `2026-08-24` (lunes) … `2026-10-04` (domingo). El
     * front lee estas dos fechas de la respuesta en vez de recalcularlas, así
     * que son contrato: si cambian, cambia lo que pinta la rejilla.
     */
    public function test_el_rango_de_septiembre_de_2026_es_el_del_contrato(): void
    {
        $r = $this->mes($this->tokenDe($this->usuarioDeTipo('Profesor')->username), 2026, 9);

        $this->assertSame('2026-08-24', $r['desde']);
        $this->assertSame('2026-10-04', $r['hasta']);
    }

    /**
     * **Y aguanta en los 132 meses de 2024 a 2034, contra las DOS rejillas.**
     *
     * No se comprueba contra un número: se comprueba contra **la primera y la
     * última celda que pinta cada rejilla** —la que empieza en lunes y la que
     * empieza en domingo—, que es lo que el rango tiene que cubrir para que no
     * llegue un hueco vacío al borde del mes.
     *
     * Se hace en un solo caso y no con un proveedor porque cada mes serían dos
     * peticiones HTTP: aquí la regla se comprueba sobre las fechas, y basta un
     * mes real —el de arriba— para atar que la ruta usa esta regla y no otra.
     */
    public function test_el_rango_cubre_las_dos_rejillas_en_once_anios(): void
    {
        $token = $this->tokenDe($this->usuarioDeTipo('Profesor')->username);

        // Un mes de verdad por la ruta, para atar la regla al endpoint...
        $r = $this->mes($token, 2026, 2);
        $this->assertSame('2026-01-19', $r['desde'], 'Febrero de 2026 dejó de empezar en su lunes.');
        $this->assertSame('2026-03-01', $r['hasta'], 'Febrero de 2026 dejó de acabar en su domingo.');

        // ...y los 132 sobre la misma regla, que es donde están los bordes.
        for ($anio = 2024; $anio <= 2034; $anio++) {
            for ($numero = 1; $numero <= 12; $numero++) {
                $primero = new \DateTimeImmutable(sprintf('%04d-%02d-01', $anio, $numero));
                $ultimo = $primero->modify('last day of this month');

                $desde = $primero->modify('-7 days');
                $desde = $desde->modify('-'.((int) $desde->format('N') - 1).' days');

                $hasta = $ultimo->modify('+7 days');
                $hasta = $hasta->modify('-'.((int) $hasta->format('N') % 7).' days');

                $donde = sprintf('%04d-%02d', $anio, $numero);

                $this->assertSame('1', $desde->format('N'), "«desde» no cayó en lunes en {$donde}.");
                $this->assertSame('7', $hasta->format('N'), "«hasta» no cayó en domingo en {$donde}.");

                // Rejilla que empieza en lunes: primera y última celda.
                $celdaLunes = $primero->modify('-'.((int) $primero->format('N') - 1).' days');
                $finLunes = $ultimo->modify('+'.(7 - (int) $ultimo->format('N')).' days');

                // Rejilla que empieza en domingo.
                $celdaDomingo = $primero->modify('-'.((int) $primero->format('N') % 7).' days');
                $finDomingo = $ultimo->modify('+'.(6 - (int) $ultimo->format('N') % 7).' days');

                foreach ([$celdaLunes, $celdaDomingo] as $celda) {
                    $this->assertLessThanOrEqual($celda, $desde,
                        "El rango empieza después de la primera celda de la rejilla en {$donde}.");
                }

                foreach ([$finLunes, $finDomingo] as $celda) {
                    $this->assertGreaterThanOrEqual($celda, $hasta,
                        "El rango acaba antes de la última celda de la rejilla en {$donde}.");
                }
            }
        }
    }

    /**
     * **Los cumpleaños se calculan sobre el año PEDIDO, no sobre el sellado.**
     *
     * Éste es el caso que dice que el diseño cambió. Las 507 filas de la base
     * están selladas en 2025; si alguien volviera a leerlas, pedir 2026 no
     * devolvería ni un cumpleaños y este caso se pondría rojo.
     */
    public function test_los_cumples_se_calculan_sobre_el_anio_pedido(): void
    {
        $usuario = $this->usuarioDeTipo('Alumno');
        $token = $this->tokenDe($usuario->username);
        $grupo = $this->grupoDelAlumno((int) $usuario->id);

        $cumpleanero = DB::selectOne('SELECT a.id, a.fecha_nac FROM alumnos a
            INNER JOIN matriculas m ON m.alumno_id = a.id AND m.deleted_at IS NULL
            WHERE m.grupo_id = ? AND a.deleted_at IS NULL AND a.fecha_nac IS NOT NULL
            ORDER BY a.id LIMIT 1', [$grupo]);

        $this->assertNotNull($cumpleanero, 'Ningún alumno del grupo tiene `fecha_nac`: el test no mediría nada.');

        [, $mes, $dia] = explode('-', (string) $cumpleanero->fecha_nac);

        $claves = $this->clavesQueVe($token, 2026, (int) $mes);

        $this->assertContains("cumple_alumno:{$cumpleanero->id}:2026-{$mes}-{$dia}", $claves,
            'El cumpleaños no salió proyectado sobre 2026. Si se están leyendo las filas viejas, '
            .'están todas selladas en 2025 y en 2026 no hay ninguna.');
    }

    /**
     * Y el año siguiente da los mismos, que es la otra mitad: sin ella,
     * «funciona en 2026» podría ser un sello nuevo en vez de un cálculo.
     */
    public function test_los_cumples_del_anio_siguiente_tambien_existen(): void
    {
        $usuario = $this->usuarioDeTipo('Alumno');
        $token = $this->tokenDe($usuario->username);
        $grupo = $this->grupoDelAlumno((int) $usuario->id);

        $cumpleanero = DB::selectOne('SELECT a.id, a.fecha_nac FROM alumnos a
            INNER JOIN matriculas m ON m.alumno_id = a.id AND m.deleted_at IS NULL
            WHERE m.grupo_id = ? AND a.deleted_at IS NULL AND a.fecha_nac IS NOT NULL
            ORDER BY a.id LIMIT 1', [$grupo]);

        $this->assertNotNull($cumpleanero);

        [, $mes, $dia] = explode('-', (string) $cumpleanero->fecha_nac);

        $this->assertContains("cumple_alumno:{$cumpleanero->id}:2027-{$mes}-{$dia}",
            $this->clavesQueVe($token, 2027, (int) $mes),
            'En 2027 no hay cumpleaños: el año volvió a estar sellado en algún sitio.');
    }

    /**
     * **Las filas viejas de cumpleaños no se pintan por esta ruta.**
     *
     * Mientras las 507 sigan en la base —son la red hasta que la pantalla nueva
     * funcione—, devolverlas además del calculado pintaría cada cumpleaños dos
     * veces. Y el front no se quejaría: los dos caen el mismo día y el agrupado
     * diría «2 cumpleaños».
     */
    public function test_las_filas_viejas_de_cumples_no_salen_por_aqui(): void
    {
        $alumno = DB::selectOne('SELECT id FROM alumnos WHERE deleted_at IS NULL ORDER BY id LIMIT 1');

        $viejo = DB::table('calendario')->insertGetId([
            'title' => 'Cumple de fila vieja',
            'cumple_alumno_id' => $alumno->id,
            'solo_profes' => 0,
            'allDay' => 1,
            'start' => '2026-09-15 05:00:00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $claves = $this->clavesQueVe($this->tokenDe($this->usuarioDeTipo('Profesor')->username));

        $this->assertNotContains("manual:{$viejo}", $claves,
            'Una fila con `cumple_alumno_id` salió como evento manual: cada cumpleaños se pinta dos veces.');
    }

    /**
     * **`clave` es única dentro de la respuesta.** Sin eso el `track` de Angular
     * no puede pintar, y es la única garantía que el front nos pide de ella.
     */
    public function test_las_claves_no_se_repiten(): void
    {
        $claves = $this->clavesQueVe($this->tokenDe($this->usuarioDeTipo('Profesor')->username));

        $this->assertNotEmpty($claves, 'El mes salió vacío: así no se comprueba la unicidad de nada.');
        $this->assertSame(count($claves), count(array_unique($claves)),
            'Hay dos eventos con la misma `clave` en una respuesta.');
    }

    /** Y la respuesta viene ordenada por `start`, que es lo que el front no reordena. */
    public function test_los_eventos_vienen_ordenados_por_fecha(): void
    {
        $eventos = $this->mes($this->tokenDe($this->usuarioDeTipo('Profesor')->username), 2026, 9)['eventos'];

        $starts = array_column($eventos, 'start');
        $ordenados = $starts;
        sort($ordenados);

        $this->assertSame($ordenados, $starts, 'Los eventos no vienen ordenados por `start`.');
    }

    /**
     * **Un alumno no recibe el evento del grupo de al lado.** Es la razón entera
     * de que exista la tabla: el evento no se esconde en el front, **no viaja**.
     */
    public function test_un_alumno_no_recibe_lo_de_otro_grupo(): void
    {
        $usuario = $this->usuarioDeTipo('Alumno');
        $token = $this->tokenDe($usuario->username);
        $suyo = $this->grupoDelAlumno((int) $usuario->id);

        $otro = DB::selectOne('SELECT id FROM grupos WHERE id <> ? AND deleted_at IS NULL ORDER BY id LIMIT 1', [$suyo]);
        $this->assertNotNull($otro, 'El seed necesita un segundo grupo para que esto compare algo.');

        $ajeno = $this->evento('Salida del otro grupo', [['publico' => 'alumnos', 'grupo_id' => (int) $otro->id]]);
        $suyoEvento = $this->evento('Salida de mi grupo', [['publico' => 'alumnos', 'grupo_id' => $suyo]]);
        $todos = $this->evento('Para todos los alumnos', [['publico' => 'alumnos', 'grupo_id' => null]]);
        $publico = $this->evento('Público de toda la vida');
        $interno = $this->evento('Reunión de profesores', [], 1);
        $soloPersonal = $this->evento('Consejo académico', [['publico' => 'personal', 'grupo_id' => null]]);

        $claves = $this->clavesQueVe($token);

        $this->assertNotContains("manual:{$ajeno}", $claves,
            'El alumno recibió el evento dirigido a los alumnos de OTRO grupo.');
        $this->assertNotContains("manual:{$interno}", $claves,
            'El alumno recibió un evento `solo_profes = 1` sin filas: se rompió la compatibilidad hacia atrás.');
        $this->assertNotContains("manual:{$soloPersonal}", $claves,
            'El alumno recibió un evento dirigido sólo al personal.');

        $this->assertContains("manual:{$suyoEvento}", $claves, 'El alumno no recibió lo de SU grupo.');
        $this->assertContains("manual:{$todos}", $claves, 'El alumno no recibió lo dirigido a todos los alumnos.');
        $this->assertContains("manual:{$publico}", $claves,
            'El alumno dejó de recibir un evento sin destinatarios: «sin filas» ya no significa público, '
            .'y eso apaga el calendario de todos los eventos que ya existen.');
    }

    /**
     * **El personal lo ve todo, y es una decisión escrita, no un olvido.**
     *
     * Si el filtro se le aplicara, el docente que acaba de crear «Salida de 7º»
     * no la vería en su propio calendario y la pantalla nueva enseñaría **menos**
     * que la que sustituye. Los destinatarios existen para no llenar de ruido a
     * las familias, no para esconderle el calendario al colegio.
     */
    public function test_el_personal_lo_ve_todo(): void
    {
        $otro = DB::selectOne('SELECT id FROM grupos WHERE deleted_at IS NULL ORDER BY id LIMIT 1');

        $deUnGrupo = $this->evento('Salida de un grupo', [['publico' => 'alumnos', 'grupo_id' => (int) $otro->id]]);
        $interno = $this->evento('Reunión de profesores', [], 1);
        $deAcudientes = $this->evento('Entrega de boletines', [['publico' => 'acudientes', 'grupo_id' => null]]);

        $claves = $this->clavesQueVe($this->tokenDe($this->usuarioDeTipo('Profesor')->username));

        foreach ([$deUnGrupo, $interno, $deAcudientes] as $id) {
            $this->assertContains("manual:{$id}", $claves,
                'El personal dejó de ver un evento del calendario del colegio.');
        }
    }

    /**
     * **Un acudiente ve lo de los grupos de sus acudidos, y por los dos públicos.**
     *
     * La regla de la casa —*«un alumno solo ve lo suyo; un acudiente, lo suyo y lo
     * completo de sus acudidos»*— es la que dice que un evento dirigido a **los
     * alumnos** del grupo de su hijo también le llega: si no, el colegio tendría
     * que repartir cada evento dos veces para que llegara a las familias.
     *
     * ## El escenario se FABRICA, y hace falta decir por qué
     *
     * En el seed **ningún acudiente puede ver nada de un grupo**: los acudientes
     * tienen su `periodo_id` en un año sin grupos, así que
     * `gruposDeLosAcudidos()` devuelve la lista vacía para todos ellos. Un caso
     * que **buscara** el sujeto en el seed pasaría en verde sin haber comprobado
     * ni una fila — *un vacío se parece a un guard que funciona*. Así que el
     * parentesco se monta aquí dentro, en la transacción del test.
     *
     * ## Y el año se LEE del token, no se impone
     *
     * Primera versión de este caso: fijaba `users.periodo_id` al año del grupo y
     * montaba el parentesco allí. **Fallaba**, y el motivo es la trampa: `tokenDe()`
     * hace un login de verdad, y **el login reescribe `users.periodo_id`**. O sea
     * que el escenario se montaba en un año y el token preguntaba por otro.
     *
     * Se vio porque el caso se puso rojo. Podía perfectamente haber salido verde
     * por el otro lado —con el parentesco cayendo en un año sin eventos, todas
     * las comprobaciones «no lo recibe» habrían pasado— y entonces este caso
     * habría afirmado que el filtro funciona **sin haber filtrado nada**.
     * Por eso ahora se pide el token **primero** y el año sale de él.
     */
    public function test_un_acudiente_ve_lo_de_los_grupos_de_sus_acudidos(): void
    {
        $usuario = $this->usuarioDeTipo('Acudiente');

        $acudiente = DB::selectOne('SELECT id FROM acudientes WHERE user_id = ? AND deleted_at IS NULL',
            [$usuario->id]);
        $this->assertNotNull($acudiente, 'El acudiente del seed no tiene ficha.');

        // El token PRIMERO: el login fija el periodo, y con él el año del contexto.
        $token = $this->tokenDe($usuario->username);

        $anio = DB::selectOne('SELECT p.year_id FROM users u
            INNER JOIN periodos p ON p.id = u.periodo_id WHERE u.id = ?', [$usuario->id]);
        $this->assertNotNull($anio, 'El acudiente no resuelve año: el contexto no se podría montar.');

        $grupo = DB::selectOne('SELECT g.id, m.alumno_id FROM grupos g
             INNER JOIN matriculas m ON m.grupo_id = g.id AND m.deleted_at IS NULL
             WHERE g.year_id = ? AND g.deleted_at IS NULL ORDER BY g.id LIMIT 1', [$anio->year_id]);

        $this->assertNotNull($grupo,
            'No hay ningún grupo con alumnos en el año del acudiente: sin eso el caso pasaría '
            .'con la lista vacía y no comprobaría ni un filtro.');

        DB::table('parentescos')->insert([
            'acudiente_id' => $acudiente->id,
            'alumno_id' => $grupo->alumno_id,
            'parentesco' => 'Madre',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $suyo = (int) $grupo->id;

        $otro = DB::selectOne('SELECT id FROM grupos WHERE id <> ? AND deleted_at IS NULL ORDER BY id LIMIT 1', [$suyo]);
        $this->assertNotNull($otro, 'El seed necesita un segundo grupo para que esto compare algo.');

        $paraSusAlumnos = $this->evento('Salida del grupo del acudido', [['publico' => 'alumnos', 'grupo_id' => $suyo]]);
        $paraSusAcudientes = $this->evento('Reunión de padres del grupo', [['publico' => 'acudientes', 'grupo_id' => $suyo]]);
        $paraTodasLasFamilias = $this->evento('Escuela de padres', [['publico' => 'acudientes', 'grupo_id' => null]]);
        $ajeno = $this->evento('Reunión de padres de otro grupo', [['publico' => 'acudientes', 'grupo_id' => (int) $otro->id]]);
        $publico = $this->evento('Día de la familia');
        $interno = $this->evento('Reunión de profesores', [], 1);

        $claves = $this->clavesQueVe($token);

        $this->assertContains("manual:{$paraSusAcudientes}", $claves,
            'El acudiente no recibió la reunión de padres de SU grupo.');
        $this->assertContains("manual:{$paraSusAlumnos}", $claves,
            'El acudiente no recibió lo dirigido a los ALUMNOS del grupo de su acudido: '
            .'con eso el colegio tiene que repartir cada evento dos veces.');
        $this->assertContains("manual:{$paraTodasLasFamilias}", $claves,
            'El acudiente no recibió lo dirigido a todos los acudientes.');
        $this->assertContains("manual:{$publico}", $claves,
            'El acudiente dejó de recibir un evento sin destinatarios.');

        $this->assertNotContains("manual:{$ajeno}", $claves,
            'El acudiente recibió la reunión de padres de otro grupo.');
        $this->assertNotContains("manual:{$interno}", $claves,
            'El acudiente recibió un evento interno del colegio.');
    }

    /**
     * **«Sin filas y `solo_profes = 1`» viaja como una fila explícita
     * `('personal', null)`**, y no como un caso que el front tenga que deducir.
     *
     * Con esto `destinatarios: []` significa «público» y sólo eso, que es lo que
     * deja la etiqueta «para quién es» colgando de una sola fuente.
     */
    public function test_un_interno_sin_filas_viaja_como_una_fila_de_personal(): void
    {
        $interno = $this->evento('Reunión de profesores', [], 1);

        $eventos = $this->mes($this->tokenDe($this->usuarioDeTipo('Profesor')->username), 2026, 9)['eventos'];

        $suyo = null;
        foreach ($eventos as $evento) {
            if ($evento['clave'] === "manual:{$interno}") {
                $suyo = $evento;
            }
        }

        $this->assertNotNull($suyo, 'El personal no vio el evento interno.');
        $this->assertSame(
            [['publico' => 'personal', 'grupo_id' => null, 'grupo_nombre' => null, 'grupo_abrev' => null]],
            $suyo['destinatarios'],
            'Un evento interno sin filas dejó de viajar con su fila implícita de personal.');
    }

    /** El nombre del grupo viaja: la pantalla no puede pintar un `grupo_id`. */
    public function test_el_nombre_del_grupo_viaja_en_los_destinatarios(): void
    {
        $grupo = DB::selectOne('SELECT id, nombre, abrev FROM grupos WHERE deleted_at IS NULL ORDER BY id LIMIT 1');

        $id = $this->evento('Salida pedagógica', [['publico' => 'alumnos', 'grupo_id' => (int) $grupo->id]]);

        $eventos = $this->mes($this->tokenDe($this->usuarioDeTipo('Profesor')->username), 2026, 9)['eventos'];

        foreach ($eventos as $evento) {
            if ($evento['clave'] === "manual:{$id}") {
                $this->assertSame($grupo->nombre, $evento['destinatarios'][0]['grupo_nombre']);
                $this->assertSame($grupo->abrev, $evento['destinatarios'][0]['grupo_abrev']);

                return;
            }
        }

        $this->fail('El evento no salió en el mes.');
    }

    /** Año y mes fuera de rango son un 422, no un 400 ni un 500. */
    public function test_el_mes_malo_es_un_422(): void
    {
        $token = $this->tokenDe($this->usuarioDeTipo('Profesor')->username);

        foreach ([['year' => 2026, 'mes' => 13], ['year' => 2026, 'mes' => 0],
            ['year' => 1999, 'mes' => 9], ['year' => 'hola', 'mes' => 9], ['mes' => 9]] as $cuerpo) {
            $this->withToken($token)->putJson('/api/calendario/mes', $cuerpo)->assertStatus(422);
            $this->olvidarControladores();
        }
    }

    /** Y `proximos` contesta con la misma forma. */
    public function test_proximos_devuelve_la_misma_forma(): void
    {
        $token = $this->tokenDe($this->usuarioDeTipo('Profesor')->username);

        $r = $this->withToken($token)->putJson('/api/calendario/proximos', ['dias' => 30]);
        $r->assertStatus(200);

        $cuerpo = $r->json();

        $this->assertArrayHasKey('desde', $cuerpo);
        $this->assertArrayHasKey('hasta', $cuerpo);
        $this->assertIsArray($cuerpo['eventos']);

        $this->olvidarControladores();
        $this->withToken($token)->putJson('/api/calendario/proximos', ['dias' => 0])->assertStatus(422);
    }

    /**
     * **Todo item lleva los quince campos, siempre.** El front declara sus tipos
     * contra esta lista; un campo que a veces no viene es un `undefined` en una
     * pantalla, no un error aquí.
     */
    public function test_todos_los_items_llevan_los_mismos_campos(): void
    {
        $this->evento('Con destinatarios', [['publico' => 'alumnos', 'grupo_id' => null]]);

        $eventos = $this->mes($this->tokenDe($this->usuarioDeTipo('Profesor')->username), 2026, 9)['eventos'];

        $campos = ['clave', 'origen', 'id', 'persona_id', 'title', 'descripcion', 'start', 'end',
            'allDay', 'solo_profes', 'url', 'recordatorio_minutos', 'created_by_nombres', 'destinatarios'];

        $huboCumple = false;

        foreach ($eventos as $evento) {
            $this->assertSame($campos, array_keys($evento),
                'Un item del calendario no tiene los campos del contrato, o los tiene en otro orden.');

            if ($evento['origen'] !== 'manual') {
                $huboCumple = true;
                $this->assertNull($evento['id'], 'Un cumpleaños llegó con `id`: no es una fila.');
                $this->assertNotNull($evento['persona_id'], 'Un cumpleaños llegó sin `persona_id`.');
                $this->assertSame([], $evento['destinatarios'], 'Un cumpleaños llegó con destinatarios.');
                $this->assertSame(0, $evento['solo_profes'], 'Un cumpleaños llegó marcado como interno.');
            }
        }

        $this->assertTrue($huboCumple,
            'No salió ni un cumpleaños en el mes: las comprobaciones de su forma no se ejecutaron.');
    }

    /**
     * **La FORMA de la respuesta, en una instantánea.**
     *
     * Los otros casos comprueban qué campos hay y quién los recibe; éste fija
     * **de qué tipo es cada uno**, incluidos los de dentro de `destinatarios`, y
     * lo hace con `formaUnida()` —que une todos los elementos de la lista en vez
     * de quedarse con el primero— para que la respuesta describa a la vez un
     * evento manual y un cumpleaños.
     *
     * Existe por una razón concreta y reciente: durante la coordinación de esta
     * misma épica, el nombre del grupo viajó como `grupo` en un mensaje y como
     * `grupo_nombre` en otro. **Si el front hubiera elegido el malo no habría
     * fallado nada** —los campos son opcionales en su tipado, así que ni el
     * `typecheck` ni sus pruebas lo habrían cantado— y la etiqueta habría salido
     * vacía en producción. Un desacuerdo de nombres entre dos lados **no se cae
     * solo**: hace falta que un lado escriba la forma y el otro la copie literal.
     */
    public function test_la_forma_de_la_respuesta(): void
    {
        $grupo = DB::selectOne('SELECT id FROM grupos WHERE deleted_at IS NULL ORDER BY id LIMIT 1');

        DB::table('calendario')->insert([
            'title' => 'Feria de la ciencia',
            'descripcion' => '<p>Traer bata</p>',
            'recordatorio_minutos' => 15,
            'created_by_nombres' => 'Quien la creó',
            'solo_profes' => 0,
            'allDay' => 0,
            'start' => '2026-09-22 08:00:00',
            'end' => '2026-09-22 12:00:00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $evento = (int) DB::getPdo()->lastInsertId();

        DB::table('calendario_destinatarios')->insert([
            'calendario_id' => $evento,
            'publico' => 'alumnos',
            'grupo_id' => (int) $grupo->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $cuerpo = $this->mes($this->tokenDe($this->usuarioDeTipo('Profesor')->username), 2026, 9);

        $this->assertNotEmpty($cuerpo['eventos'],
            'El mes salió vacío: la instantánea guardaría una lista vacía y no fijaría ninguna forma.');

        $this->compararConInstantanea('calendario-mes', $this->formaUnida($cuerpo));
    }
}
