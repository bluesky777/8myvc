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

        // **Y con el tope de la escala.** Dicho al derecho es el mecanismo del
        // colegio: se empieza el periodo con el comportamiento entero y se le baja
        // a quien lo pierda. Dicho al revés es «un GET que califica de
        // sobresaliente a todo el grupo». Las dos frases describen la misma fila,
        // así que **cuál de las dos es depende de una decisión del colegio** y no
        // del código. Se fija el valor para que ese día se vea lo que se cambia.
        $maxima = DB::selectOne('SELECT porc_final FROM escalas_de_valoracion e
            INNER JOIN periodos p ON p.year_id = e.year_id AND p.id = ?
            WHERE e.deleted_at IS NULL ORDER BY e.orden DESC LIMIT 1', [$periodo]);

        $this->assertSame((int) $maxima->porc_final,
            (int) DB::table('nota_comportamiento')->whereIn('alumno_id', $ids)
                ->where('periodo_id', $periodo)->max('nota'),
            'Las notas que nacen al abrir la rejilla dejaron de ser el tope de la escala — §133.');

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
     * Con el periodo cerrado: se lee y **no se escribe**. Hasta hoy sí escribía.
     *
     * `profes_pueden_editar_notas` es el interruptor con el que el colegio cierra
     * la rejilla. Con él apagado, `nota_comportamiento/store`, `/update`,
     * `/crear` y `/destroy` contestan **400** —lo hace `User::pueden_editar_notas()`—
     * y `detailed` contestaba **200 y escribía** las filas: era la única de las
     * cinco que no preguntaba.
     *
     * Se comprueban las dos en la misma petición de test a propósito: lo que hay
     * que ver no es que una escribiera, es que **la de al lado no podía**.
     *
     * El arreglo no lleva `abort()`, y ése es el fondo del asunto: apagar la
     * lectura para arreglar la escritura habría sido peor que el fallo. El
     * criterio es el que ya decidió Joseth para `unidades/de-asignatura-periodo`
     * (§47.2), que es la misma forma exacta — *«enseña lo que hay y no crea
     * nada»*—, con `permiteEditarNotas()` booleana en vez de la que aborta.
     */
    public function test_con_el_periodo_cerrado_se_lee_la_rejilla_y_no_se_escribe(): void
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

        // Y el GET **sigue dejando leer**, que es la mitad que un `abort()` habría
        // apagado: con el periodo cerrado es justo la rejilla que el profesor va a
        // querer consultar.
        $r = $this->withToken($token)->getJson('/api/nota_comportamiento/detailed/'.$grupo->id);
        $r->assertStatus(200);

        $escritas = DB::table('nota_comportamiento')->whereIn('alumno_id', $ids)
            ->where('periodo_id', $periodo)->count();

        $this->assertSame(0, $escritas,
            'Con el periodo cerrado el GET volvió a escribir notas de comportamiento — §133.');

        // Y la respuesta sigue trayendo a los alumnos, con su nota **sin `id`**,
        // que es la rama que el front ya distingue (`if (nota.id) actualizar; else
        // crear`). Si esto se vacía, la rejilla se queda en blanco y el arreglo
        // habría roto la lectura para arreglar la escritura.
        $alumnosEnLaRespuesta = $r->json()[1];

        $this->assertNotEmpty($alumnosEnLaRespuesta,
            'Con el periodo cerrado la rejilla se quedó sin alumnos: se rompió la lectura.');

        $this->assertNull($alumnosEnLaRespuesta[0]['nota']['id'] ?? null,
            'Con el periodo cerrado la nota vino con `id`: entonces se creó la fila — §133.');
    }

    /**
     * Y con el periodo ABIERTO sigue creando, que es la mitad que se apaga sola.
     *
     * Sin esto, cambiar `permiteEditarNotas` por un `false` fijo daría verde el
     * test de arriba y habría dejado la rejilla sin poder inicializarse **nunca**.
     * Es la comprobación al revés escrita como test.
     */
    public function test_con_el_periodo_abierto_la_rejilla_se_sigue_inicializando(): void
    {
        $usuario = $this->usuarioDeTipo('Profesor');
        $token = $this->tokenDe($usuario->username);
        $grupo = $this->grupoConAlumnos();
        $periodo = (int) DB::table('users')->where('id', $usuario->id)->value('periodo_id');

        DB::table('periodos')->where('id', $periodo)->update(['profes_pueden_editar_notas' => 1]);

        $alumnos = DB::select('SELECT m.alumno_id FROM matriculas m
            WHERE m.grupo_id = ? AND m.deleted_at IS NULL
              AND m.estado IN ("MATR","ASIS","PREM")', [$grupo->id]);
        $ids = array_map(static fn ($a) => (int) $a->alumno_id, $alumnos);

        DB::table('nota_comportamiento')->whereIn('alumno_id', $ids)
            ->where('periodo_id', $periodo)->delete();

        $this->withToken($token)->getJson('/api/nota_comportamiento/detailed/'.$grupo->id)
            ->assertStatus(200);

        $this->assertGreaterThan(0,
            DB::table('nota_comportamiento')->whereIn('alumno_id', $ids)
                ->where('periodo_id', $periodo)->count(),
            'Con el periodo abierto la rejilla dejó de inicializarse: el arreglo del §133 se '
            .'pasó de largo y ahora no se puede calificar el comportamiento de nadie.');

        // **Y en el periodo de QUIEN MIRA, no en el del grupo.** `crearVerifNota()`
        // recibe `$user->periodo_id`, así que dos personas en periodos distintos
        // abriendo la misma rejilla crean filas en periodos distintos. Se fija
        // medido y **no juzgado**: puede ser lo correcto —cada uno califica su
        // periodo— pero leído al revés es «la pantalla escribe donde esté el que
        // entra», y eso hay que escribirlo, no suponerlo.
        $enOtros = DB::table('nota_comportamiento')->whereIn('alumno_id', $ids)
            ->where('periodo_id', '<>', $periodo)
            ->where('created_at', '>=', '2026-08-23 00:00:00')->count();

        $this->assertSame(0, $enOtros,
            'La rejilla creó filas en un periodo que no es el de quien la abrió — §133.');
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
     * Las dos de `importar` están rotas, y por dónde — §135.
     *
     * `GET api/importar` no recibe ningún fichero: lee una **ruta fija dentro del
     * propio código**, `app/Http/Controllers/Alumnos/archivos/alumnos.xls`, y a
     * partir de ahí **crea alumnos y cuentas de usuario en masa**. Esa carpeta no
     * existe en el repositorio, así que la ruta no puede funcionar.
     *
     * Se fija el error exacto en vez de borrarlas porque **tienen ruta**, y borrar
     * un endpoint enrutado convierte un 500 en un 404 sin decirle a nadie qué
     * pretendía hacer esa pantalla. Y lo que pretendía importa: es la única
     * importación del sistema que **no recibe el fichero del cliente**, o sea un
     * resto de una migración hecha a mano que quedó enchufada a la API.
     *
     * Lo peligroso no es que esté rota: es **lo que haría si alguien creara esa
     * carpeta**. Un `GET` con `auth.personal` que da de alta alumnos y cuentas es
     * la peor forma posible de una importación, y hoy solo lo impide un fichero
     * ausente.
     */
    public function test_las_dos_de_importar_estan_rotas_y_por_donde(): void
    {
        $token = $this->tokenDe($this->usuarioDeTipo('Profesor')->username);

        $alumnosAntes = DB::table('alumnos')->count();
        $usuariosAntes = DB::table('users')->count();

        $r = $this->withToken($token)->getJson('/api/importar');

        $this->assertSame(500, $r->status(),
            'La importación de ruta fija dejó de dar 500: si alguien creó '
            .'`app/Http/Controllers/Alumnos/archivos/`, **esta ruta ya da de alta alumnos y '
            .'cuentas por un GET** y hay que cerrarla hoy — §135.');

        $this->assertSame($alumnosAntes, DB::table('alumnos')->count(),
            'La importación rota alcanzó a crear alumnos antes de reventar.');

        $this->assertSame($usuariosAntes, DB::table('users')->count(),
            'La importación rota alcanzó a crear cuentas antes de reventar.');

        $year = DB::table('years')->where('actual', 1)->value('year');

        $this->assertSame(500,
            $this->withToken($token)->getJson('/api/importar/modificar/'.$year)->status(),
            '`importar/modificar` dejó de dar 500 — mídela otra vez, escribe con `DB::update`.');
    }

    /**
     * La sexta: un GET **sin guard ninguno** que borra — §136.
     *
     * `GET definitivas_periodos/arreglar-duplicados` es la única de las seis cuya
     * ruta no lleva **ni `auth.personal`**: solo el `auth.token` que va por defecto
     * a toda la API. Y borra filas de definitivas recorriendo **grupos × alumnos ×
     * asignaturas** del colegio entero.
     *
     * Está en la lista de exenciones de `AutorizacionTest` con su motivo escrito
     * —«`pueden_modificar_definitivas()` corta a todo el que no sea superusuario o
     * profesor con permiso»— y **eso es cierto**. Pero una exención dice quién no
     * pasa; **nadie había mirado qué contesta**, que es la mitad que este test
     * añade. Es el patrón de esta noche: la tabla de rutas no es la autoridad
     * sobre si algo está defendido, **ni para bien ni para mal**.
     *
     * Se comprueba **solo el rechazo** a propósito. El camino de éxito recorre
     * tres bucles anidados sobre todo el colegio y **lo que está congelado es
     * justo eso**: el cálculo de las definitivas espera a que termine la
     * migración. Medir la puerta no toca el cálculo; ejecutarlo, sí.
     */
    public function test_el_get_que_borra_definitivas_no_lo_alcanza_una_familia(): void
    {
        $antes = DB::table('notas_finales')->count();

        foreach (['Alumno', 'Acudiente'] as $tipo) {
            $token = $this->tokenDe($this->usuarioDeTipo($tipo)->username);

            $r = $this->withToken($token)->getJson('/api/definitivas_periodos/arreglar-duplicados');

            $this->assertContains($r->status(), [400, 403],
                "Un {$tipo} entró en el GET que borra definitivas del colegio entero. Su ruta no "
                .'lleva `auth.personal`: lo único que la defiende es '
                .'`pueden_modificar_definitivas()` dentro del método — §136.');
        }

        $this->assertSame($antes, DB::table('notas_finales')->count(),
            'El rechazo llegó a borrar definitivas antes de contestar que no — §136.');
    }

    /**
     * `folios/iniciar` **era** un GET que reescribía las matrículas del colegio entero.
     * **Ya no escribe: contesta 409.** La mina de la §134 está desactivada.
     *
     * ## Lo que era, porque el test no puede perderlo al cambiar
     *
     * `UPDATE matriculas ... SET nro_folio = CONCAT(year, "-", alumno_id)` sobre **todas**
     * las del año en curso a las que les faltara el folio, con `auth.personal` —o sea
     * cualquiera de los 51 profesores— y por un verbo que un navegador puede repetir solo.
     * Lo único que la salvaba de ser destructiva es que **solo tocaba las vacías**: era
     * idempotente por la condición, no por el diseño.
     *
     * ## Por qué deja de escribir, que no es una decisión de esta clase
     *
     * Porque lo que escribía **no era un folio**. Un folio es la hoja del libro de
     * matrículas, y lo que se imprime en la constancia está para que quien la lea vaya a
     * comprobarla al archivo; `2025-1234` es el id del alumno con el año delante y no lleva
     * a ninguna parte. **Este endpoint es la máquina que fabricó 1.612 de ellos**
     * (docs/migracion/21-certificados-y-folios.md §2.2). Decisión de Joseth del 26 ago 2026.
     *
     * ## Y por qué el test se queda aquí en vez de borrarse
     *
     * Esta clase es el censo de **los GET que escriben**. Que uno deje de escribir es
     * justamente lo que hay que poder leer en ella dentro de seis meses: borrar el test
     * dejaría la §134 contada como una mina viva en el documento y sin nada que la
     * contradiga. Lo que se comprueba ahora es lo contrario de antes —**que NO escribe**— y
     * eso también es un contrato.
     *
     * La otra mitad —que la respuesta sea 409 y no 404, y que no llegue con el `UPDATE` ya
     * hecho— vive en `FolioQueNoSeFabricaTest`, junto con el barrido que impide que alguien
     * vuelva a fabricar folios por cualquiera de los otros seis sitios.
     */
    public function test_folios_iniciar_ya_no_escribe_nada(): void
    {
        $token = $this->tokenDe($this->usuarioDeTipo('Profesor')->username);

        $sinFolio = DB::selectOne('SELECT m.id FROM matriculas m
            INNER JOIN grupos g ON g.id = m.grupo_id AND g.deleted_at IS NULL
            INNER JOIN years y ON y.id = g.year_id AND y.actual = 1
            LIMIT 1');

        $this->assertNotNull($sinFolio, 'El seed no tiene matrículas en el año actual.');

        // Se vacía una a propósito: sin nada que rellenar, «no rellenó» y «no había nada
        // que rellenar» dan el mismo verde, y de las dos lecturas la falsa es la que hace
        // archivar el asunto.
        DB::table('matriculas')->where('id', $sinFolio->id)->update(['nro_folio' => null]);

        $this->withToken($token)->getJson('/api/folios/iniciar')->assertStatus(409);

        $this->assertNull(DB::table('matriculas')->where('id', $sinFolio->id)->value('nro_folio'),
            'Contestó 409 y aun así rellenó el folio: llegó con el `UPDATE` ya hecho, que es '
            .'el mismo modo de fallo que la §136 —rechazar después de escribir—.');
    }
}
