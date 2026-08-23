<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * §141 — Un centinela que no es falsy: el «no hay nota» que dice que sí hay.
 *
 * `NotaComportamiento::nota_comportamiento()` tiene dos salidas y devuelven
 * cosas de tipo distinto:
 *
 *     return $nota;                        // hay nota: un OBJETO
 *     return [ "notas_finales" => [] ];    // no hay: un ARRAY
 *
 * Y los cinco llamantes preguntan `if ($nota)`. **Un array no vacío es truthy**,
 * así que el `if` pasa y la línea siguiente lee una propiedad de un array:
 *
 *     PUT api/notas-actuales-alumnos/{grupo}
 *       -> 500  Attempt to read property "nota" on array
 *
 *               @ NotasActualesAlumnosController.php:351
 *
 * Medido con token de acudiente **y** con token del personal: los dos. No es un
 * fallo de autorización ni de familia — es del grupo entero, y lo dispara **un
 * solo alumno al que le falte la nota del periodo**.
 *
 * ## El centinela NO se toca, y no es prudencia
 *
 * `["notas_finales" => []]` no es un centinela improvisado: **está moldeado**.
 * `alumno.comportamiento.notas_finales` se recorre con `ng-repeat` en **cuatro
 * plantillas de boletín** de `myvc_front` —`boletinAlumnoDir.html`, `…Dir2`,
 * `…Dir3` y `…Dir5`—, así que cambiar el modelo a `null` se la quita a las
 * cuatro. Se para en el llamante, que es además lo que ya se decidió esta misma
 * noche para `Profesor::detallado()` por un camino distinto.
 *
 * ## Dónde se para, que son más sitios de los que revientan
 *
 * `encabezado_comportamiento_boletin()` está **copiada en cinco controladores**
 * de `Informes/` y ninguna de las cinco distinguía el array. Se arreglan las
 * cinco, no sólo la que revienta hoy:
 *
 * > **Es la misma forma, no el mismo fallo probado.** Que las otras cuatro
 * > revienten depende de a qué alumno le falte la nota y de que
 * > `mostrar_nota_comport_boletin` esté encendido —lo está en el año en curso—.
 * > Arreglar sólo la que se está mirando es lo que ha costado tres series esta
 * > noche.
 *
 * ## Y la forma de la respuesta no cambia
 *
 * Tres de los cinco llamantes ya sobrevivían **por accidente**: su
 * `catch (\Throwable)` escribe `$alumno->comportamiento['definiciones'] = []`.
 * O sea que el array que ve el cliente lleva **hoy** esa clave. Los dos que no
 * tenían `catch` ahora la escriben también, en un `else` explícito: si no, el
 * arreglo del 500 le habría cambiado la forma a la respuesta de dos rutas.
 */
class CentinelaDelComportamientoTest extends CasoDeContrato
{
    /**
     * El grupo entero se pide sin reventar, con familia y con personal.
     *
     * Los dos tokens y no uno: el 500 no era de autorización, y medirlo sólo con
     * el acudiente lo habría dejado pareciendo un problema de familias — que es
     * justo la clase de conclusión que luego se arrastra a otra población.
     */
    public function test_el_grupo_con_un_alumno_sin_nota_no_revienta(): void
    {
        [$grupo, $sinNota] = $this->grupoConUnAlumnoSinNota();

        // Cada token pide lo que le corresponde, y eso NO es un detalle del
        // test: una familia que pide el grupo entero recibe 403 de
        // `boletin.propio`, que está bien y no es lo que se mide aquí. La
        // primera versión pedía el grupo con los dos tokens y el 403 de la
        // familia se leía como si el arreglo hubiera fallado.
        $casos = [
            'personal' => [$this->tokenDelPersonalDe($grupo->year_id), $this->pidiendoAlGrupo($grupo->id)],
            'familia' => [$this->tokenDeUnaFamiliaDe($sinNota), $this->pidiendoPor($sinNota)],
        ];

        foreach ($casos as $quien => [$token, $cuerpo]) {
            if ($token === null) {
                continue;
            }

            $r = $this->withToken($token)->putJson("/api/notas-actuales-alumnos/{$grupo->id}", $cuerpo);

            $this->assertSame(200, $r->status(),
                "`notas-actuales-alumnos` revienta para {$quien} porque el alumno {$sinNota} no tiene "
                .'nota de comportamiento del periodo. Es el array truthy de la §141.');

            $this->olvidarControladores();
        }
    }

    /**
     * Y el alumno sin nota sale con la forma que las plantillas esperan.
     *
     * Es la mitad que impide «arreglarlo» con un `try/catch` alrededor: eso
     * también daría 200, y dejaría al alumno fuera del boletín sin que nadie lo
     * viera. Lo que se afirma es **lo que llega**, no el código.
     */
    public function test_el_alumno_sin_nota_conserva_notas_finales_y_definiciones(): void
    {
        [$grupo, $sinNota] = $this->grupoConUnAlumnoSinNota();

        $r = $this->withToken($this->tokenDelPersonalDe($grupo->year_id))
            ->putJson("/api/notas-actuales-alumnos/{$grupo->id}",
                $this->pidiendoAlGrupo($grupo->id))->assertStatus(200);

        $suyo = $this->comportamientoDe($r->json(), $sinNota);

        $this->assertNotNull($suyo, "El alumno {$sinNota} no sale en la respuesta del grupo.");
        $this->assertIsArray($suyo['comportamiento'],
            'El alumno sin nota ya no recibe el array centinela. Si el modelo pasó a devolver `null`, '
            .'las cuatro plantillas de boletín que recorren `comportamiento.notas_finales` con '
            .'`ng-repeat` se quedan sin la clave.');

        $this->assertSame(['notas_finales', 'definiciones'], array_keys($suyo['comportamiento']),
            'Cambió la forma del comportamiento de un alumno sin nota. Las dos claves son las que '
            .'los tres llamantes con `catch (\Throwable)` ya producían hoy.');
    }

    /**
     * Y el alumno que sí tiene nota sigue recibiéndola entera.
     *
     * La otra mitad, y la que un `is_object` mal puesto rompería en silencio:
     * mandar a todo el mundo por la rama del centinela también da 200, y el
     * boletín saldría sin la nota de comportamiento de nadie.
     */
    public function test_el_alumno_con_nota_la_sigue_recibiendo(): void
    {
        [$grupo, $sinNota] = $this->grupoConUnAlumnoSinNota();

        // Otro alumno del MISMO grupo, al que no se le ha quitado nada: el
        // grupo lleva ahora los dos casos a la vez, que es la situación real.
        // El filtro de `estado` es el MISMO que el de `pidiendoAlGrupo()`, y no
        // por simetría: sin él se elegía a un alumno con matrícula en otro
        // estado, que no entra en la lista que se pide, y el test decía «no sale
        // en la respuesta» — un mensaje que apunta al código y era del test.
        $conNota = DB::selectOne('SELECT m.alumno_id FROM matriculas m
            INNER JOIN nota_comportamiento nc ON nc.alumno_id = m.alumno_id AND nc.deleted_at IS NULL
            WHERE m.grupo_id = ? AND m.deleted_at IS NULL AND m.estado IN ("MATR","ASIS","PREM")
              AND m.alumno_id <> ? LIMIT 1',
            [$grupo->id, $sinNota]);

        $this->assertNotNull($conNota,
            'Ningún otro alumno del grupo tiene nota de comportamiento. Se afirma en vez de saltar '
            .'el test: un `markTestSkipped` se lee igual que un verde en la línea de resumen, y esta '
            .'noche ya ha escondido un caso (§122).');

        $r = $this->withToken($this->tokenDelPersonalDe($grupo->year_id))
            ->putJson("/api/notas-actuales-alumnos/{$grupo->id}",
                $this->pidiendoAlGrupo($grupo->id))->assertStatus(200);

        $suyo = $this->comportamientoDe($r->json(), (int) $conNota->alumno_id);

        $this->assertNotNull($suyo, "El alumno {$conNota->alumno_id} no sale en la respuesta.");
        $this->assertArrayHasKey('nota', (array) $suyo['comportamiento'],
            'El alumno que SÍ tiene nota dejó de recibirla: el `is_object` está mandando a todo el '
            .'mundo por la rama del centinela y el 200 lo tapa.');
    }

    /**
     * §142 — Pedir el grupo sin `requested_alumnos` es un 500 seguro.
     *
     * No es del centinela ni tiene que ver con las notas: la ruta declara
     * `Request::input('requested_alumnos', '')` —una CADENA por defecto— y
     * doce líneas más abajo le hace `foreach`. Cualquiera que llame sin esa
     * clave recibe `foreach() argument must be of type array|object, string
     * given`, con nota o sin ella y con cualquier token.
     *
     * **Se fija y no se arregla**, y no por pereza: el bucle interior sólo
     * procesa a los alumnos que aparecen en la lista, así que un `[]` por
     * defecto devolvería 200 con el grupo vacío — un 200 hueco, que en este
     * repo es peor que el error. La otra salida es 422, que es el código
     * correcto pero cambia lo que recibe una ruta enrutada. **Cuál de las dos
     * es una decisión**, y R se abrió por el boletín de una familia.
     *
     * Con ruta y roto se documenta (CLAUDE.md).
     */
    public function test_pedir_el_grupo_sin_la_lista_de_alumnos_sigue_siendo_500(): void
    {
        [$grupo] = $this->grupoConUnAlumnoSinNota();

        $r = $this->withToken($this->tokenDelPersonalDe($grupo->year_id))
            ->putJson("/api/notas-actuales-alumnos/{$grupo->id}", []);

        $this->assertSame(500, $r->status(),
            'Cambió lo que contesta `notas-actuales-alumnos` sin `requested_alumnos`. Si se arregló, '
            .'hay que decidir entre 200 con el grupo vacío —un 200 hueco— y 422, y escribirlo aquí.');

        $this->assertStringContainsString('foreach() argument must be of type array|object',
            (string) $r->json('message'),
            'Sigue siendo 500 pero por otro motivo: entonces es otro fallo y hay que mirarlo.');
    }

    /**
     * El cuerpo que manda el cliente de verdad: la lista de alumnos del grupo.
     *
     * Hace falta porque sin ella la ruta da el 500 de la §142, que no tiene nada
     * que ver con lo que estos tests miden. Medirlo con el cuerpo vacío habría
     * dejado los tres tests en rojo por un motivo ajeno **y** habría escondido
     * que el arreglo del centinela sí funcionaba.
     *
     * @return array{requested_alumnos: list<array{alumno_id: int, matricula_id: int}>}
     */
    private function pidiendoAlGrupo(int $grupoId): array
    {
        $filas = DB::select('SELECT m.alumno_id, m.id matricula_id FROM matriculas m
            WHERE m.grupo_id = ? AND m.deleted_at IS NULL AND m.estado IN ("MATR","ASIS","PREM")
            ORDER BY m.alumno_id', [$grupoId]);

        // `array_values` para que sea una `list` y no un `array`: sin él larastan
        // 7 no puede garantizar que las claves sean 0..n y falla el tipo de
        // vuelta. `DB::select` ya devuelve una lista, pero eso el analizador no
        // lo sabe.
        return ['requested_alumnos' => array_values(array_map(
            fn ($f) => ['alumno_id' => (int) $f->alumno_id, 'matricula_id' => (int) $f->matricula_id],
            $filas))];
    }

    /**
     * Un grupo del año en curso y un alumno suyo **al que se le quita la nota**.
     *
     * La condición se **provoca**, no se busca en el seed, y eso no es comodidad:
     * la primera versión de este helper la buscaba con un `LEFT JOIN … IS NULL`
     * contra el periodo `actual` y no encontraba nada, aunque el 500 estuviera
     * reproducido delante. El motivo es que
     * `NotasActualesAlumnosController` no usa el periodo del usuario ni el
     * `actual`: coge **el de cada alumno**, `$periodo_id = $alumno->periodo_id`
     * (línea 134). Buscar por el periodo equivocado daba «no hay ningún caso»
     * sobre un caso que existía.
     *
     * Se borran **todas** las notas del alumno, y por eso mismo: así el test no
     * necesita saber qué periodo elegirá el controlador. Corre dentro de la
     * transacción de cada test, así que no deja nada detrás.
     *
     * @return array{0: object, 1: int}
     */
    private function grupoConUnAlumnoSinNota(): array
    {
        // Se prefiere un alumno CON acudiente para que el mismo caso sirva a los
        // dos tokens; si el seed no tuviera ninguno, vale cualquiera y la mitad
        // de la familia se salta sola.
        $fila = DB::selectOne('SELECT m.grupo_id, g.year_id, m.alumno_id
            FROM matriculas m
            INNER JOIN grupos g ON g.id = m.grupo_id AND g.deleted_at IS NULL
            INNER JOIN years y ON y.id = g.year_id AND y.actual = 1 AND y.deleted_at IS NULL
            INNER JOIN parentescos p ON p.alumno_id = m.alumno_id AND p.deleted_at IS NULL
            INNER JOIN acudientes ac ON ac.id = p.acudiente_id AND ac.deleted_at IS NULL
            INNER JOIN users u ON u.id = ac.user_id AND u.tipo = "Acudiente" AND u.is_active = 1
            INNER JOIN nota_comportamiento nc ON nc.alumno_id = m.alumno_id AND nc.deleted_at IS NULL
            WHERE m.deleted_at IS NULL AND m.estado IN ("MATR","ASIS","PREM")
            ORDER BY m.grupo_id, m.alumno_id LIMIT 1');

        $this->assertNotNull($fila, 'El seed no tiene ningún alumno matriculado en el año en curso.');

        $borradas = DB::delete('DELETE FROM nota_comportamiento WHERE alumno_id = ?', [$fila->alumno_id]);

        $this->assertGreaterThan(0, $borradas,
            'Ese alumno ya no tenía ninguna nota de comportamiento, así que quitársela no cambia '
            .'nada y el test no distinguiría la rama del centinela de la buena.');

        return [(object) ['id' => (int) $fila->grupo_id, 'year_id' => (int) $fila->year_id],
            (int) $fila->alumno_id];
    }

    /** El token del acudiente DE ESE ALUMNO, o null si no tiene ninguno en el seed. */
    private function tokenDeUnaFamiliaDe(int $alumnoId): ?string
    {
        $fila = DB::selectOne('SELECT u.username FROM users u
            INNER JOIN acudientes ac ON ac.user_id = u.id AND ac.deleted_at IS NULL
            INNER JOIN parentescos p ON p.acudiente_id = ac.id AND p.deleted_at IS NULL AND p.alumno_id = ?
            WHERE u.tipo = "Acudiente" AND u.is_active = 1 AND u.deleted_at IS NULL LIMIT 1', [$alumnoId]);

        if ($fila === null) {
            return null;
        }

        // El paz y salvo decide desde el 18 ago 2026, y un 403 por deuda taparía
        // el 500 que se está midiendo.
        DB::update('UPDATE alumnos SET pazysalvo = 1 WHERE id = ?', [$alumnoId]);

        return $this->tokenDe($fila->username);
    }

    /** @return array{requested_alumnos: list<array{alumno_id: int, matricula_id: int}>} */
    private function pidiendoPor(int $alumnoId): array
    {
        $m = DB::selectOne('SELECT id FROM matriculas WHERE alumno_id = ? AND deleted_at IS NULL LIMIT 1',
            [$alumnoId]);

        return ['requested_alumnos' => [['alumno_id' => $alumnoId, 'matricula_id' => (int) $m->id]]];
    }

    /**
     * El `comportamiento` de un alumno dentro de la respuesta.
     *
     * Dos cosas que hubo que medir en vez de suponer, y las dos daban mensajes
     * que se leían como fallos del código:
     *
     *  - la respuesta es una **tupla**, no una lista: `detailedNotasGrupo()`
     *    termina en `return array($grupo, $year, $response_alumnos)` (línea 129),
     *    así que los alumnos son el tercer elemento y no una clave `alumnos`;
     *  - y `comportamiento` **no cuelga del alumno sino de cada periodo suyo**,
     *    porque el bloque que lo calcula trabaja sobre `$alumno->periodos[$i]`.
     *
     * Con `$grupo['alumnos']` el test decía «el alumno no sale en la respuesta»
     * y con el alumno decía «Undefined array key comportamiento». Ninguno de los
     * dos mensajes apuntaba al test, que era donde estaba el error.
     *
     * @param  array<int, mixed>  $cuerpo
     * @return array<string, mixed>|null
     */
    private function comportamientoDe(array $cuerpo, int $alumnoId): ?array
    {
        foreach ($cuerpo[2] ?? [] as $a) {
            if ((int) ($a['alumno_id'] ?? 0) !== $alumnoId) {
                continue;
            }

            foreach ($a['periodos'] ?? [] as $periodo) {
                if (array_key_exists('comportamiento', $periodo)) {
                    return ['comportamiento' => $periodo['comportamiento']];
                }
            }
        }

        return null;
    }
}
