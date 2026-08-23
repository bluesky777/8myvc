<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * Las unidades: la rejilla con la que se calcula la nota de una asignatura.
 *
 * Sale de la cobertura —`UnidadesController` tenía 7 de sus 11 rutas sin ninguna
 * respuesta comprobada— y lo que encontró es la §27 otra vez: **el interruptor
 * con el que el colegio cierra el periodo no lo miraban tres de sus métodos**.
 * Ver 05 §47.
 *
 * Lo que hace decidible esto es que su controlador gemelo sí lo pide:
 * `SubunidadesController` comprueba el periodo al crear y al reordenar, y aquí
 * faltaba en los dos sitios equivalentes. No es una regla nueva —la §40 ya
 * decidió que el interruptor cierra las notas—, es la misma sin aplicar.
 */
class UnidadesTest extends CasoDeContrato
{
    /**
     * Un profesor con su periodo CERRADO, y una asignatura de su año.
     *
     * El periodo se lee **después** del token: `Services\Login` reescribe
     * `users.periodo_id` al entrar, así que leerlo antes mide otro año.
     */
    private function escenario(): object
    {
        $prof = $this->usuarioDeTipo('Profesor');
        $token = $this->tokenDe($prof->username);

        $suyo = DB::selectOne('SELECT p.id, p.year_id FROM periodos p
            INNER JOIN users u ON u.periodo_id = p.id WHERE u.id = ?', [$prof->id]);

        $this->assertNotNull($suyo, 'El profesor del seed se quedó sin periodo al entrar.');

        DB::table('periodos')->where('year_id', $suyo->year_id)
            ->update(['profes_pueden_editar_notas' => 0, 'profes_pueden_nivelar' => 0]);

        $asignatura = DB::selectOne('SELECT a.id FROM asignaturas a
            INNER JOIN grupos g ON g.id = a.grupo_id AND g.year_id = ? AND g.deleted_at IS NULL
            WHERE a.deleted_at IS NULL ORDER BY a.id LIMIT 1', [$suyo->year_id]);

        $this->assertNotNull($asignatura, 'El seed no tiene asignaturas en el año del profesor.');

        return (object) [
            'token' => $token,
            'periodo' => (int) $suyo->id,
            'year_id' => (int) $suyo->year_id,
            'asignatura' => (int) $asignatura->id,
        ];
    }

    private function abrirElPeriodo(int $yearId): void
    {
        DB::table('periodos')->where('year_id', $yearId)
            ->update(['profes_pueden_editar_notas' => 1, 'profes_pueden_nivelar' => 1]);
    }

    private function unidad(object $e): int
    {
        DB::insert('INSERT INTO unidades (definicion, porcentaje, periodo_id, asignatura_id, orden, created_at, updated_at)
                    VALUES ("Unidad de prueba", 50, ?, ?, 0, ?, ?)',
            [$e->periodo, $e->asignatura, now(), now()]);

        return (int) DB::getPdo()->lastInsertId();
    }

    /**
     * **§96. `unidades/update` con un cuerpo parcial borraba un peso de la nota
     * final, no una descripción.**
     *
     * La definitiva sale de `(u.porcentaje/100) * ((s.porcentaje/100) * n.nota)`, y
     * `NotaFinal::calcularAsignaturaPeriodo` se llama **en este mismo método**, diez
     * líneas después de guardar. Con `Request::input('porcentaje')` a secas, un
     * cuerpo que sólo trae `definicion` —corregirle la redacción a un logro— dejaba
     * el peso en null y **cambiaba la nota que va al boletín**, en 200 y sin avisar.
     *
     * Y el de la unidad es el factor de fuera: se lleva por delante **todas** las
     * subunidades que cuelgan de ella a la vez, no una. Su gemelo de subunidades
     * tiene el mismo fallo y lo cerró el lote C; éste pesa más por eso.
     *
     * Es la [§68](../../docs/migracion/05-codigo-muerto-y-roto.md) otra vez, y aquí
     * el defecto de `Request::input()` basta: este método no tiene ningún `merge()`
     * delante, así que la clave ausente se distingue sin `CamposQueVinieron`.
     */
    public function test_editar_la_definicion_no_borra_el_porcentaje(): void
    {
        $e = $this->escenario();
        $this->abrirElPeriodo($e->year_id);
        $id = $this->unidad($e);

        $this->withToken($e->token)->putJson('/api/unidades/update/'.$id,
            ['definicion' => 'Redacción corregida'])->assertStatus(200);

        $fila = DB::selectOne('SELECT definicion, porcentaje FROM unidades WHERE id = ?', [$id]);
        $this->assertSame('Redacción corregida', $fila->definicion);
        $this->assertSame(50, (int) $fila->porcentaje,
            'El peso de la unidad se pisó a null al corregir la redacción.');
    }

    /**
     * Los dos casos al revés, que son lo que separa este arreglo de uno parecido y
     * peor. **Los dos se midieron, y uno de los dos no medía lo que yo creía.**
     *
     * - **Un `0` que se manda es un 0**: un peso de cero es legítimo —una unidad que
     *   no cuenta— y el defecto no puede comérselo.
     * - **Un `null` que se manda es un null**: el cuerpo está diciendo «quítale el
     *   peso», y eso sigue funcionando.
     *
     * El primero **no distingue** el arreglo bueno del `?? ` que parece equivalente
     * —`0 ?? 50` es `0`, porque `??` sólo mira `null`—, y con él solo el test pasaba
     * igual con las dos versiones. **El que distingue es el segundo**, y ésa es toda
     * la diferencia entre los dos arreglos: `Request::input('x', $defecto)` tapa la
     * clave **ausente**, `?? ` tapa además la que llega en `null`. Es la misma
     * frontera que en `years/guardar-cambios` (§93).
     */
    public function test_un_cero_es_un_cero_y_un_null_es_un_null(): void
    {
        $e = $this->escenario();
        $this->abrirElPeriodo($e->year_id);

        $cero = $this->unidad($e);
        $this->withToken($e->token)->putJson('/api/unidades/update/'.$cero,
            ['definicion' => 'Unidad que no cuenta', 'porcentaje' => 0])->assertStatus(200);
        $this->assertSame(0,
            (int) DB::table('unidades')->where('id', $cero)->value('porcentaje'),
            'Un peso de cero es un valor, no una ausencia.');

        $nulo = $this->unidad($e);
        $this->withToken($e->token)->putJson('/api/unidades/update/'.$nulo,
            ['definicion' => 'Unidad sin peso', 'porcentaje' => null])->assertStatus(200);
        $this->assertNull(DB::table('unidades')->where('id', $nulo)->value('porcentaje'),
            'Mandar null es pedir que se quite el peso, y tiene que quitarse.');
    }

    /**
     * **§96. Un id que no lleva a ninguna fila es 404, no el aviso de PHP.**
     *
     * `PUT unidades/de-asignatura-periodo/{a}/{p}` hacía `DB::select(...)[0]` sobre
     * una consulta que puede no traer nada, y eso es un aviso que Laravel sube a
     * excepción: **500 con «Undefined array key 0» dentro de la respuesta**. No hace
     * falta inventarse un id para llegar: basta **una asignatura de la papelera**,
     * porque el `INNER JOIN` con `grupos` filtra `deleted_at`. Ese detalle es el que
     * hace que se le escape a todo el mundo.
     *
     * Y esta ruta **no es una lectura: escribe** —crea las unidades por defecto del
     * año si no hay ninguna—, así que pararlo antes vale doble.
     */
    public function test_la_rejilla_de_una_asignatura_que_no_existe_es_404(): void
    {
        $e = $this->escenario();
        $this->abrirElPeriodo($e->year_id);
        $inventada = ((int) DB::table('asignaturas')->max('id')) + 1000;

        $this->withToken($e->token)
            ->putJson("/api/unidades/de-asignatura-periodo/{$inventada}/{$e->periodo}")
            ->assertStatus(404);

        $this->assertSame(0,
            DB::table('unidades')->where('asignatura_id', $inventada)->count(),
            'Y sobre todo: no puede haber dejado unidades huérfanas detrás.');
    }

    /** La mitad que importa: la de la papelera, que es la que se alcanza sin inventar nada. */
    public function test_la_rejilla_de_una_asignatura_en_la_papelera_es_404(): void
    {
        $e = $this->escenario();
        $this->abrirElPeriodo($e->year_id);

        DB::table('asignaturas')->where('id', $e->asignatura)->update(['deleted_at' => now()]);

        $this->withToken($e->token)
            ->putJson("/api/unidades/de-asignatura-periodo/{$e->asignatura}/{$e->periodo}")
            ->assertStatus(404);
    }

    /**
     * **`unidades/de-profesor` con un profesor que no existe —o que está en la
     * papelera— era 500**, por lo mismo: `Profesor::detallado` acaba en
     * `return $profesor[0];` sin comprobar que la consulta trajera fila, y su `where`
     * descarta los borrados.
     *
     * El modelo lo comparten **seis llamantes de tres dominios**, así que se para en
     * el llamante y **no** en `App\Models\Profesor`: un `?? null` allí convertiría
     * seis 500 en seis comportamientos distintos sin haber medido cuál es el correcto
     * en cada pantalla, que no es arreglar la operación sino mover el fallo a donde
     * no lo vea un test. Lo encontró el lote E en el suyo y eligió el mismo 404.
     */
    public function test_las_unidades_de_un_profesor_que_no_existe_son_404(): void
    {
        $e = $this->escenario();
        $inventado = ((int) DB::table('profesores')->max('id')) + 1000;

        $this->withToken($e->token)->putJson('/api/unidades/de-profesor',
            ['profesor_id' => $inventado])->assertStatus(404);

        $enPapelera = DB::selectOne('SELECT id FROM profesores WHERE deleted_at IS NOT NULL
            ORDER BY id LIMIT 1');
        $this->assertNotNull($enPapelera, 'El seed tiene que traer un profesor en la papelera.');

        $this->withToken($e->token)->putJson('/api/unidades/de-profesor',
            ['profesor_id' => $enPapelera->id])->assertStatus(404);
    }

    /**
     * `unidades/forcedelete` mira el candado del periodo, y sólo alcanza la papelera.
     *
     * Las tres mitades: con el periodo cerrado no borra; una unidad **viva** no se
     * alcanza por aquí —es un `onlyTrashed`, y devuelve 404, que es lo correcto: el
     * borrado físico es sólo de lo que ya está en la papelera—; y con el periodo
     * abierto y la unidad dentro de la papelera, la fila desaparece de verdad.
     */
    public function test_el_borrado_fisico_de_una_unidad(): void
    {
        $e = $this->escenario();
        $id = $this->unidad($e);
        DB::table('unidades')->where('id', $id)->update(['deleted_at' => now()]);

        // Periodo cerrado: no.
        $this->withToken($e->token)->deleteJson('/api/unidades/forcedelete/'.$id)
            ->assertStatus(400);
        $this->assertTrue(DB::table('unidades')->where('id', $id)->exists());

        $this->abrirElPeriodo($e->year_id);

        // Una unidad viva no se alcanza por aquí.
        $viva = $this->unidad($e);
        $this->withToken($e->token)->deleteJson('/api/unidades/forcedelete/'.$viva)
            ->assertStatus(404);
        $this->assertTrue(DB::table('unidades')->where('id', $viva)->exists());

        // Y la de la papelera, con el periodo abierto, se va del todo.
        $this->withToken($e->token)->deleteJson('/api/unidades/forcedelete/'.$id)
            ->assertStatus(200);
        $this->assertFalse(DB::table('unidades')->where('id', $id)->exists(),
            'Esto es un borrado físico: la fila no debe quedar ni en la papelera.');
    }

    /**
     * Crear una unidad con el periodo cerrado devolvía **201**, mientras editarla
     * un segundo después devolvía 400. Y su gemelo `subunidades/store` sí lo
     * pedía desde la §27.
     */
    public function test_no_se_crea_una_unidad_con_el_periodo_cerrado(): void
    {
        $e = $this->escenario();
        $antes = DB::table('unidades')->where('asignatura_id', $e->asignatura)->count();

        $this->withToken($e->token)->postJson('/api/unidades', [
            'definicion' => 'Nueva', 'porcentaje' => 50, 'asignatura_id' => $e->asignatura,
        ])->assertStatus(400);

        $this->assertSame($antes, DB::table('unidades')->where('asignatura_id', $e->asignatura)->count());
    }

    /** Y con el periodo abierto sí, que es lo que no se puede romper. */
    public function test_con_el_periodo_abierto_la_unidad_se_crea(): void
    {
        $e = $this->escenario();
        $this->abrirElPeriodo($e->year_id);

        $r = $this->withToken($e->token)->postJson('/api/unidades', [
            'definicion' => 'Nueva', 'porcentaje' => 50, 'asignatura_id' => $e->asignatura,
        ]);

        $r->assertStatus(201);
        $this->assertSame('Nueva', $r->json('definicion'));
        $this->assertSame($e->periodo, (int) $r->json('periodo_id'));
    }

    /**
     * Reordenar es escribir en la rejilla, y no lo miraba nadie. El gemelo
     * `subunidades/update-orden-varias` sí, con su comentario de la §27 al lado.
     */
    public function test_no_se_reordenan_unidades_con_el_periodo_cerrado(): void
    {
        $e = $this->escenario();
        $unidad = $this->unidad($e);

        $this->withToken($e->token)->putJson('/api/unidades/update-orden', [
            'sortHash' => [[(string) $unidad => 7]],
        ])->assertStatus(400);

        $this->assertSame(0, (int) DB::table('unidades')->where('id', $unidad)->value('orden'));
    }

    public function test_con_el_periodo_abierto_se_reordenan(): void
    {
        $e = $this->escenario();
        $unidad = $this->unidad($e);
        $this->abrirElPeriodo($e->year_id);

        $this->withToken($e->token)->putJson('/api/unidades/update-orden', [
            'sortHash' => [[(string) $unidad => 7]],
        ])->assertStatus(200);

        $this->assertSame(7, (int) DB::table('unidades')->where('id', $unidad)->value('orden'));
    }

    /**
     * Restaurar devuelve la unidad con su `porcentaje` a la rejilla, así que es
     * escribir en las notas igual que borrarla — y `unidades/destroy` sí lo pedía.
     */
    public function test_no_se_restaura_una_unidad_con_el_periodo_cerrado(): void
    {
        $e = $this->escenario();
        $unidad = $this->unidad($e);
        DB::table('unidades')->where('id', $unidad)->update(['deleted_at' => now()]);

        $this->withToken($e->token)->putJson('/api/unidades/restore/'.$unidad, [])->assertStatus(400);

        $this->assertNotNull(DB::table('unidades')->where('id', $unidad)->value('deleted_at'),
            'Sigue en la papelera.');
    }

    public function test_con_el_periodo_abierto_se_restaura(): void
    {
        $e = $this->escenario();
        $unidad = $this->unidad($e);
        DB::table('unidades')->where('id', $unidad)->update(['deleted_at' => now()]);
        $this->abrirElPeriodo($e->year_id);

        $this->withToken($e->token)->putJson('/api/unidades/restore/'.$unidad, [])->assertStatus(200);

        $this->assertNull(DB::table('unidades')->where('id', $unidad)->value('deleted_at'));
    }

    /**
     * `Unidad::find()` devolvía null con un id que no existe y `->orden` sobre
     * null era un 500. Es la misma forma que la §44: el 500 tapaba que el cliente
     * había nombrado una fila que no está.
     */
    public function test_reordenar_una_unidad_que_no_existe_es_404(): void
    {
        $e = $this->escenario();
        $this->abrirElPeriodo($e->year_id);
        $maximo = (int) DB::table('unidades')->max('id');

        $this->withToken($e->token)->putJson('/api/unidades/update-orden', [
            'sortHash' => [[(string) ($maximo + 1000) => 1]],
        ])->assertStatus(404);
    }

    /** Cobertura de las dos lecturas del controlador que nadie miraba. */
    public function test_las_unidades_de_un_profesor_traen_sus_asignaturas(): void
    {
        $e = $this->escenario();
        $profesor = DB::selectOne('SELECT p.id FROM profesores p
            INNER JOIN asignaturas a ON a.profesor_id = p.id AND a.id = ?', [$e->asignatura]);

        $r = $this->withToken($e->token)
            ->putJson('/api/unidades/de-profesor', ['profesor_id' => $profesor->id]);

        $r->assertStatus(200);
        $this->assertNotNull($r->json('info_profesor'));
        $this->assertIsArray($r->json('asignaturas'));
    }

    public function test_las_unidades_eliminadas_de_una_asignatura(): void
    {
        $e = $this->escenario();
        $unidad = $this->unidad($e);
        DB::table('unidades')->where('id', $unidad)->update(['deleted_at' => now()]);

        $r = $this->withToken($e->token)->putJson('/api/unidades/eliminadas/'.$e->asignatura, []);

        $r->assertStatus(200);
        // La respuesta viene envuelta en `unidades_eliminadas`, no es una lista
        // suelta. Se comprobó leyéndola, que es de lo que va esta serie.
        $this->assertContains(
            $unidad,
            array_map(fn ($u) => (int) $u['id'], $r->json('unidades_eliminadas'))
        );
    }

    /**
     * El gemelo exacto, que salió del inventario hecho tras encontrar los tres de
     * arriba: `subunidades/restore` tenía la misma forma —`UPDATE ... SET
     * deleted_at=NULL` a pelo— mientras `subunidades/update`, en el mismo fichero,
     * sí pedía el periodo.
     *
     * **Este párrafo decía «`subunidades/update` y `subunidades/destroy`» y era
     * falso**: `destroy` no lo pedía, y se quedó sin pedirlo un mes porque la frase
     * que decía que sí estaba escrita al lado de un test verde. Se cerró el 22 ago
     * 2026 (05 §80) y lo fija `SubunidadDestroyTest`. Se deja escrito el error, y no
     * se corrige en silencio, porque **la lección es la frase, no la ruta**: una
     * afirmación sobre el código de al lado envejece igual que el código, y aquí
     * nació ya vieja.
     */
    public function test_no_se_restaura_una_subunidad_con_el_periodo_cerrado(): void
    {
        $e = $this->escenario();
        $unidad = $this->unidad($e);

        DB::insert('INSERT INTO subunidades (definicion, porcentaje, unidad_id, orden, created_at, updated_at, deleted_at)
                    VALUES ("Subunidad de prueba", 50, ?, 0, ?, ?, ?)', [$unidad, now(), now(), now()]);
        $subunidad = (int) DB::getPdo()->lastInsertId();

        $this->withToken($e->token)->putJson('/api/subunidades/restore/'.$subunidad, [])->assertStatus(400);

        $this->assertNotNull(DB::table('subunidades')->where('id', $subunidad)->value('deleted_at'));
    }

    public function test_con_el_periodo_abierto_la_subunidad_se_restaura(): void
    {
        $e = $this->escenario();
        $unidad = $this->unidad($e);

        DB::insert('INSERT INTO subunidades (definicion, porcentaje, unidad_id, orden, created_at, updated_at, deleted_at)
                    VALUES ("Subunidad de prueba", 50, ?, 0, ?, ?, ?)', [$unidad, now(), now(), now()]);
        $subunidad = (int) DB::getPdo()->lastInsertId();

        $this->abrirElPeriodo($e->year_id);

        $this->withToken($e->token)->putJson('/api/subunidades/restore/'.$subunidad, [])->assertStatus(200);

        $this->assertNull(DB::table('subunidades')->where('id', $subunidad)->value('deleted_at'));
    }

    /**
     * La pantalla con la que el profesor mira la rejilla lee y **de paso
     * escribe**: crea las unidades por defecto si no hay ninguna. Decidido por
     * Joseth el 21 ago 2026: con el periodo cerrado **enseña lo que hay y no crea
     * nada**. No puede llevar el 400 de sus hermanas porque sería apagarle la
     * vista de un periodo cerrado, que es justo la que querrá consultar.
     */
    public function test_la_rejilla_de_un_periodo_cerrado_no_crea_unidades(): void
    {
        $e = $this->escenario();

        // La asignatura del seed YA tiene unidades en ese periodo, así que no
        // sirve para medir esto: hace falta una sin ninguna, y el ayudante que
        // monta el grupo ajeno deja justo eso. Se descubrió porque el test falló
        // diciendo «4 no es 0».
        $limpia = (int) $this->grupoAjenoDelMismoAnio($e->year_id)->asignatura_id;

        // Y hace falta que HAYA unidades por defecto que copiar: `unidades_por_defecto`
        // está vacía en el seed —es una de las cuatro tablas de la §21.5— así que sin
        // esta fila el método sale por el `return ''` y el test pasaba también con el
        // arreglo desactivado. Se descubrió comprobando al revés: caía uno de los dos.
        DB::table('unidades_por_defecto')->insert([
            'definicion' => 'Por defecto', 'porcentaje' => 100, 'year_id' => $e->year_id,
            'obligatoria' => 0, 'orden' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertSame(0, DB::table('unidades')
            ->where('asignatura_id', $limpia)->where('periodo_id', $e->periodo)->count());

        $this->withToken($e->token)
            ->getJson("/api/unidades/de-asignatura-periodo/{$limpia}/{$e->periodo}")
            ->assertStatus(200);

        $this->assertSame(0, DB::table('unidades')
            ->where('asignatura_id', $limpia)->where('periodo_id', $e->periodo)->count(),
            'Con el periodo cerrado no se crea ninguna.');
    }

    /** Y con el periodo abierto sí las crea, que es para lo que existe. */
    public function test_con_el_periodo_abierto_la_rejilla_crea_las_por_defecto(): void
    {
        $e = $this->escenario();
        $limpia = (int) $this->grupoAjenoDelMismoAnio($e->year_id)->asignatura_id;
        $this->abrirElPeriodo($e->year_id);

        DB::table('unidades_por_defecto')->insert([
            'definicion' => 'Por defecto', 'porcentaje' => 100, 'year_id' => $e->year_id,
            'obligatoria' => 0, 'orden' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->withToken($e->token)
            ->getJson("/api/unidades/de-asignatura-periodo/{$limpia}/{$e->periodo}")
            ->assertStatus(200);

        $this->assertGreaterThan(0, DB::table('unidades')
            ->where('asignatura_id', $limpia)->where('periodo_id', $e->periodo)->count(),
            'Con el periodo abierto sí las crea, que es para lo que existe.');
    }

    /**
     * La otra mitad, y la que no se veía: `arreglarOrden()` no ordena la
     * respuesta, **reescribe `orden` en la tabla** en cada lectura. Sin esto la
     * §47 habría dejado tapado `unidades/update-orden` y abierto el mismo cambio
     * por el camino del GET.
     */
    public function test_leer_la_rejilla_cerrada_no_reescribe_el_orden(): void
    {
        $e = $this->escenario();
        $unidad = $this->unidad($e);
        DB::table('unidades')->where('id', $unidad)->update(['orden' => 9]);

        $this->withToken($e->token)
            ->getJson("/api/unidades/de-asignatura-periodo/{$e->asignatura}/{$e->periodo}")
            ->assertStatus(200);

        $this->assertSame(9, (int) DB::table('unidades')->where('id', $unidad)->value('orden'),
            'Con el periodo cerrado el orden se queda como estaba.');
    }
}
