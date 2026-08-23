<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * Los GET que escriben, y el que además se salta el candado del periodo — §133.
 *
 * Un `GET` que muta es una mina por su propia forma: es el verbo que los
 * navegadores reintentan, precargan y cachean, y el único que nadie mira dos
 * veces al leer una tabla de rutas. Barridas las **122 rutas GET** de la API
 * contra el cuerpo de su método, **diez** contienen una escritura. De esas diez,
 * **cuatro son falsos positivos** —tres `Excel::create()`, que no es la base, y
 * uno de mi propio extractor, que se pasó de largo hasta el método siguiente— y
 * **seis escriben de verdad**. Y una de las seis escribe en **dos tablas**, no en
 * una: la segunda es `dis_libro_rojo`, y esa no la nombra el endpoint.
 *
 * Esta clase fija las dos que nadie había medido. Las otras cuatro ya están
 * juzgadas o son de otro lote, y quedan nombradas en el documento del lote P.
 *
 * **Lo que hace grave a `nota_comportamiento/detailed` no es que escriba: es que
 * es la única de las cinco escrituras de su propio controlador que NO pregunta
 * por el interruptor del periodo.** Las otras cuatro llaman a
 * `User::pueden_editar_notas()`; ésta crea la nota de comportamiento de **cada
 * alumno del grupo, con la nota máxima**, solo por abrir la rejilla.
 *
 * Y no salía en `tools/escrituras-en-las-notas.py`. No es un fallo nuevo de la
 * herramienta: es **la tercera ceguera que ella misma lleva escrita** —solo mira
 * SQL crudo—, y aquí la escritura es un `->save()` de Eloquent **dentro de un
 * modelo**, a dos saltos del controlador.
 */
class UnGetQueEscribeTest extends CasoDeContrato
{
    /**
     * Abrir la rejilla de comportamiento **crea** una nota por alumno.
     *
     * Se mide contando filas antes y después de una petición que, por su verbo,
     * no debería dejar rastro. El grupo se limpia primero para que el número no
     * dependa de lo que ya hubiera: lo que se fija es **que escribe**, no cuánto.
     */
    public function test_abrir_la_rejilla_de_comportamiento_crea_las_notas(): void
    {
        $usuario = $this->usuarioDeTipo('Profesor');
        $token = $this->tokenDe($usuario->username);
        $grupo = $this->grupoConAlumnos();

        $periodo = (int) DB::table('users')->where('id', $usuario->id)->value('periodo_id');

        $alumnos = DB::select('SELECT m.alumno_id FROM matriculas m
            WHERE m.grupo_id = ? AND m.deleted_at IS NULL
              AND m.estado IN ("MATR","ASIS","PREM")', [$grupo->id]);

        $ids = array_map(static fn ($a) => (int) $a->alumno_id, $alumnos);
        $this->assertNotEmpty($ids, 'El grupo elegido no tiene alumnos.');

        // Se vacía a propósito: la transacción del test lo deshace, y así el
        // número de después no depende de lo que hubiera antes.
        DB::table('nota_comportamiento')->whereIn('alumno_id', $ids)
            ->where('periodo_id', $periodo)->delete();
        DB::table('dis_libro_rojo')->whereIn('alumno_id', $ids)->delete();

        $antes = DB::table('nota_comportamiento')->whereIn('alumno_id', $ids)
            ->where('periodo_id', $periodo)->count();

        $this->assertSame(0, $antes, 'No quedó vacío antes de medir.');

        $this->withToken($token)->getJson('/api/nota_comportamiento/detailed/'.$grupo->id)
            ->assertStatus(200);

        $despues = DB::table('nota_comportamiento')->whereIn('alumno_id', $ids)
            ->where('periodo_id', $periodo)->count();

        $this->assertGreaterThan(0, $despues,
            'Un GET dejó de escribir: si se arregló, este test se cambia con el porqué — §133.');

        // **Y en una segunda tabla**, que es la que no se ve leyendo el nombre del
        // endpoint: `dis_libro_rojo`, el libro rojo de disciplina. Abrir la
        // rejilla de comportamiento le abre a cada alumno del grupo su fila de
        // libro rojo del año. Está vacía —es un contenedor, no una anotación— pero
        // el número de filas de esa tabla deja de significar «alumnos con libro
        // abierto» y pasa a significar «alumnos cuya rejilla alguien miró».
        $year = (int) DB::table('periodos')->where('id', $periodo)->value('year_id');

        $this->assertGreaterThan(0,
            DB::table('dis_libro_rojo')->whereIn('alumno_id', $ids)->where('year_id', $year)->count(),
            'El GET dejó de crear el libro rojo: si se arregló, aquí va el porqué — §133.');
    }

    /**
     * Y escribe **también con el periodo cerrado**, que es lo que la separa de sus
     * cuatro hermanas.
     *
     * `profes_pueden_editar_notas` es el interruptor con el que el colegio cierra
     * la rejilla. Con él apagado, `nota_comportamiento/update`, `/store`,
     * `/frases-check` y `/guardar-libro` contestan **400** —lo hace
     * `User::pueden_editar_notas()`— y `detailed` contesta 200 **y escribe**.
     *
     * Se comprueban las dos en la misma petición de test a propósito: lo que hay
     * que ver no es que una escriba, es que **la de al lado no puede**.
     */
    public function test_con_el_periodo_cerrado_las_hermanas_frenan_y_el_get_escribe(): void
    {
        $usuario = $this->usuarioDeTipo('Profesor');
        $token = $this->tokenDe($usuario->username);
        $grupo = $this->grupoConAlumnos();
        $periodo = (int) DB::table('users')->where('id', $usuario->id)->value('periodo_id');

        // El colegio cierra la rejilla: es un interruptor del periodo.
        DB::table('periodos')->where('id', $periodo)->update(['profes_pueden_editar_notas' => 0]);

        $alumnos = DB::select('SELECT m.alumno_id FROM matriculas m
            WHERE m.grupo_id = ? AND m.deleted_at IS NULL
              AND m.estado IN ("MATR","ASIS","PREM")', [$grupo->id]);
        $ids = array_map(static fn ($a) => (int) $a->alumno_id, $alumnos);

        DB::table('nota_comportamiento')->whereIn('alumno_id', $ids)
            ->where('periodo_id', $periodo)->delete();

        // La hermana que sí pregunta: frena. `store` es POST, no PUT — con el
        // verbo equivocado contesta 405 y el test habría medido el router en vez
        // del candado, que es la clase de verde hueco que hay que evitar aquí.
        $this->assertSame(400,
            $this->withToken($token)->postJson('/api/nota_comportamiento/store', [
                'alumno_id' => $ids[0], 'periodo_id' => $periodo, 'nota' => 50,
            ])->status(),
            'Con el periodo cerrado, `nota_comportamiento/store` dejó de frenar: entonces el '
            .'interruptor no está donde este test cree y hay que volver a medirlo.');

        // Y el GET, que no pregunta: pasa y escribe.
        $this->withToken($token)->getJson('/api/nota_comportamiento/detailed/'.$grupo->id)
            ->assertStatus(200);

        $escritas = DB::table('nota_comportamiento')->whereIn('alumno_id', $ids)
            ->where('periodo_id', $periodo)->count();

        $this->assertGreaterThan(0, $escritas,
            'El GET dejó de escribir con el periodo cerrado — si se arregló, aquí va el porqué '
            .'y qué se decidió devolver cuando la nota no existe. §133');
    }

    /**
     * Lo que sí se puede afirmar sin decidir nada: **crea lo que falta y no toca
     * lo que hay**.
     *
     * Es la mitad tranquilizadora del §133 y hay que medirla, no suponerla: si
     * además pisara las notas ya puestas, abrir la rejilla con el periodo cerrado
     * **borraría el trabajo del profesor**, y eso sí sería un incidente y no una
     * mina. `firstOrNew` + `if (! $nota->id)` es lo que lo evita, y esta es la
     * prueba de que hace lo que parece.
     *
     * Se fija también **la nota con la que nace**, que es la máxima de la escala:
     * el alumno empieza el periodo con el comportamiento entero y se le baja. Ese
     * valor es una decisión del colegio escrita en el código, y cambiarlo cambia
     * la nota de partida de todos.
     */
    public function test_la_rejilla_no_pisa_las_notas_que_ya_estan(): void
    {
        $usuario = $this->usuarioDeTipo('Profesor');
        $token = $this->tokenDe($usuario->username);
        $grupo = $this->grupoConAlumnos();
        $periodo = (int) DB::table('users')->where('id', $usuario->id)->value('periodo_id');

        $alumnos = DB::select('SELECT m.alumno_id FROM matriculas m
            WHERE m.grupo_id = ? AND m.deleted_at IS NULL
              AND m.estado IN ("MATR","ASIS","PREM") ORDER BY m.alumno_id', [$grupo->id]);
        $ids = array_map(static fn ($a) => (int) $a->alumno_id, $alumnos);
        $this->assertNotEmpty($ids, 'El grupo elegido no tiene alumnos.');

        DB::table('nota_comportamiento')->whereIn('alumno_id', $ids)
            ->where('periodo_id', $periodo)->delete();

        // Uno con nota puesta a mano —la del profesor— y el resto sin fila.
        DB::table('nota_comportamiento')->insert([
            'alumno_id' => $ids[0], 'periodo_id' => $periodo, 'nota' => 31,
            'created_at' => '2026-08-23 03:00:00', 'updated_at' => '2026-08-23 03:00:00',
        ]);

        $this->withToken($token)->getJson('/api/nota_comportamiento/detailed/'.$grupo->id)
            ->assertStatus(200);

        $this->assertSame(31,
            (int) DB::table('nota_comportamiento')->where('alumno_id', $ids[0])
                ->where('periodo_id', $periodo)->value('nota'),
            'Abrir la rejilla pisó una nota de comportamiento ya puesta. Eso no es una mina: '
            .'es borrarle el trabajo al profesor — §133.');

        if (count($ids) > 1) {
            $nacida = DB::table('nota_comportamiento')->where('alumno_id', $ids[1])
                ->where('periodo_id', $periodo)->value('nota');

            $maxima = DB::selectOne('SELECT porc_final FROM escalas_de_valoracion e
                INNER JOIN periodos p ON p.year_id = e.year_id AND p.id = ?
                WHERE e.deleted_at IS NULL ORDER BY e.orden DESC LIMIT 1', [$periodo]);

            $this->assertSame((int) $maxima->porc_final, (int) $nacida,
                'La nota con la que nace una fila dejó de ser la máxima de la escala: es la nota '
                .'de partida de todos los alumnos y cambiarla es una decisión del colegio — §133.');
        }
    }

    /**
     * `folios/iniciar` es un GET que reescribe las matrículas del colegio entero.
     *
     * `UPDATE matriculas ... SET nro_folio = CONCAT(year, "-", alumno_id)` sobre
     * **todas** las del año en curso a las que les falte el folio, con
     * `auth.personal` — o sea cualquiera de los 51 profesores, y por un verbo que
     * un navegador puede repetir solo.
     *
     * Lo que salva a ésta de ser destructiva es que **solo toca las vacías**
     * (`nro_folio is null OR nro_folio=""`), así que repetirla no cambia nada: es
     * idempotente por la condición, no por el diseño. Se fija eso, que es lo que
     * hay que no perder si alguien la reescribe — y se mide que **no la llama
     * ningún cliente**, así que hoy es una mina y no un fallo vivo.
     */
    public function test_folios_iniciar_solo_rellena_los_vacios_y_repetirlo_no_cambia_nada(): void
    {
        $token = $this->tokenDe($this->usuarioDeTipo('Profesor')->username);

        $conFolio = DB::selectOne('SELECT m.id, m.nro_folio FROM matriculas m
            INNER JOIN grupos g ON g.id = m.grupo_id AND g.deleted_at IS NULL
            INNER JOIN years y ON y.id = g.year_id AND y.actual = 1
            WHERE m.nro_folio IS NOT NULL AND m.nro_folio <> "" LIMIT 1');

        $sinFolio = DB::selectOne('SELECT m.id FROM matriculas m
            INNER JOIN grupos g ON g.id = m.grupo_id AND g.deleted_at IS NULL
            INNER JOIN years y ON y.id = g.year_id AND y.actual = 1
            LIMIT 1');

        $this->assertNotNull($sinFolio, 'El seed no tiene matrículas en el año actual.');

        // Se vacía una a propósito para que haya algo que rellenar.
        DB::table('matriculas')->where('id', $sinFolio->id)->update(['nro_folio' => null]);

        $this->withToken($token)->getJson('/api/folios/iniciar')->assertStatus(200);

        $this->assertNotNull(DB::table('matriculas')->where('id', $sinFolio->id)->value('nro_folio'),
            'Un GET dejó de rellenar los folios vacíos — §134.');

        if ($conFolio !== null) {
            $this->assertSame($conFolio->nro_folio,
                DB::table('matriculas')->where('id', $conFolio->id)->value('nro_folio'),
                'Empezó a pisar folios que ya estaban puestos: eso sí sería destructivo — §134.');
        }

        // Repetirlo no toca nada: la condición es la que lo hace idempotente.
        $r = $this->withToken($token)->getJson('/api/folios/iniciar');
        $r->assertStatus(200);
    }
}
