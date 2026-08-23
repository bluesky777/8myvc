<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * §87 — Los ordinales de disciplina: el manual de convivencia del colegio.
 *
 * `OrdinalesController` era **1 de 6 rutas comprobadas**, el peor que quedaba, y
 * ya se había leído entero la noche del 21 buscando otra cosa —de ahí salió una
 * inyección, la §55—. Volver con otra pregunta es justo cuando se escapan las de
 * esta: **medir una ruta no es haberla juzgado**, y un fichero con anotaciones
 * recientes parece mirado.
 *
 * La pregunta de este lote era quién escribe aquí y con qué comprobación. La
 * respuesta útil no salió de leer el controlador sino de **greppear la tabla en
 * todo `app/`**: `dis_ordinales` no es una lista de configuración, es de dónde
 * salen el artículo, el texto y la página que se imprimen en la falta de un
 * alumno. La leen `Models\Disciplina` —y con ella **los tres boletines** y la
 * pantalla de inicio del propio alumno— y `YearsController`, que los copia al año
 * nuevo. `dis_proceso_ordinales` guarda **solo el id**: no hay copia del texto.
 *
 * De ahí las dos que importan, y las dos se miran desde donde se ven —lo que el
 * alumno recibe— y no desde la fila:
 *
 * - **Editar un ordinal reescribe la falta ya sancionada.**
 * - **Borrarlo deja la falta en pie y sin el artículo que citaba.**
 *
 * Ninguna se arregla aquí: las dos son decisión del colegio (¿se congela el texto
 * al sancionar? ¿se puede borrar un ordinal citado?). Se miden y se fijan.
 */
class OrdinalesTest extends CasoDeContrato
{
    private function tokenDelPersonal(): string
    {
        return $this->tokenDe($this->usuarioDeTipo('Usuario')->username);
    }

    /** El año del token, que NO es el del seed: `Login` reescribe el periodo al entrar. */
    private function contextoDelPersonal(): array
    {
        $usuario = $this->usuarioDeTipo('Usuario');
        $token = $this->tokenDe($usuario->username);
        $periodo = DB::table('users')->where('id', $usuario->id)->value('periodo_id');

        return [$token, (int) DB::table('periodos')->where('id', $periodo)->value('year_id')];
    }

    private function unOrdinal(string $token, int $yearId, string $tipo = 'T1'): int
    {
        $r = $this->withToken($token)->postJson('/api/ordinales/store', [
            'year_id' => $yearId,
            'ordinal' => 'Artículo 12',
            'tipo' => $tipo,
            'descripcion' => 'Vocabulario soez en clase',
            'pagina' => '34',
        ]);
        $r->assertStatus(200);

        return (int) $r->json('id');
    }

    /** Alta, edición, valor suelto y borrado: el camino que no puede cambiar. */
    public function test_el_camino_bueno_de_un_ordinal(): void
    {
        [$token, $year] = $this->contextoDelPersonal();

        $id = $this->unOrdinal($token, $year);

        // 200 y no 201 aunque sea un alta: la respuesta es la fila releída con un
        // `DB::select`, no un modelo Eloquent. Contrasta con `ciudades/guardar-ciudad`,
        // que sí usa Eloquent y por eso sale en 201 (§85).
        $fila = DB::table('dis_ordinales')->where('id', $id)->first();
        $this->assertSame('Artículo 12', $fila->ordinal);
        $this->assertSame($year, (int) $fila->year_id);
        $this->assertNull($fila->updated_by, 'El alta no firma: `postStore` no escribe `updated_by`.');

        $this->withToken($token)->putJson('/api/ordinales/update', [
            'id' => $id, 'tipo' => 'T2', 'ordinal' => 'Artículo 13',
            'descripcion' => 'Otra cosa', 'pagina' => '35',
        ])->assertStatus(200)->assertSee('Cambiado');

        $fila = DB::table('dis_ordinales')->where('id', $id)->first();
        $this->assertSame('Artículo 13', $fila->ordinal);
        $this->assertNotNull($fila->updated_by, 'Editar sí firma.');

        $this->withToken($token)->putJson('/api/ordinales/guardar-valor', [
            'ordinal_id' => $id, 'propiedad' => 'descripcion', 'valor' => 'Por el valor suelto',
        ])->assertStatus(200)->assertSee('Cambiado');

        $this->assertSame('Por el valor suelto',
            DB::table('dis_ordinales')->where('id', $id)->value('descripcion'));

        $this->withToken($token)->putJson('/api/ordinales/destroy', ['ordinal_id' => $id])
            ->assertStatus(200)->assertSee('Eliminado');

        $fila = DB::table('dis_ordinales')->where('id', $id)->first();
        $this->assertNotNull($fila, 'El borrado es blando.');
        $this->assertNotNull($fila->deleted_at);
        $this->assertNotNull($fila->deleted_by, 'Y este sí firma quién borró.');
    }

    /** La configuración del módulo va por la misma forma {propiedad, valor}. */
    public function test_la_configuracion_de_disciplina_se_guarda_por_valor_suelto(): void
    {
        [$token, $year] = $this->contextoDelPersonal();

        $config = DB::table('dis_configuraciones')->where('year_id', $year)
            ->whereNull('deleted_at')->orderBy('id')->first();
        $this->assertNotNull($config, 'El seed no tiene configuración de disciplina de este año.');

        $this->withToken($token)->putJson('/api/ordinales/guardar-valor-config', [
            'config_id' => $config->id, 'propiedad' => 'cant_tard_to_ft1', 'valor' => 7,
        ])->assertStatus(200)->assertSee('Cambiado');

        $this->assertSame(7,
            (int) DB::table('dis_configuraciones')->where('id', $config->id)->value('cant_tard_to_ft1'));
    }

    /**
     * El nombre de la columna sí está comprobado, y es la §55 en pie.
     *
     * Las dos rutas de valor suelto concatenan el NOMBRE de la columna, que en SQL
     * no se puede parametrizar. `ColumnaSegura` lo valida **contra el esquema
     * real**, no contra una lista escrita a mano.
     */
    public function test_la_propiedad_de_las_dos_de_valor_suelto_esta_comprobada(): void
    {
        [$token, $year] = $this->contextoDelPersonal();
        $id = $this->unOrdinal($token, $year);
        $config = DB::table('dis_configuraciones')->whereNull('deleted_at')->orderBy('id')->value('id');

        foreach (['no_existe_esta_columna', 'deleted_at', 'id', 'ordinal=1, pagina'] as $propiedad) {
            $this->withToken($token)->putJson('/api/ordinales/guardar-valor',
                ['ordinal_id' => $id, 'propiedad' => $propiedad, 'valor' => 'x'])->assertStatus(422);

            $this->withToken($token)->putJson('/api/ordinales/guardar-valor-config',
                ['config_id' => $config, 'propiedad' => $propiedad, 'valor' => 'x'])->assertStatus(422);
        }

        $this->assertNull(DB::table('dis_ordinales')->where('id', $id)->value('deleted_at'),
            'Ni siquiera el intento de escribir `deleted_at` llega a la fila.');
    }

    /**
     * Un cuerpo vacío en el alta: 500, y **lo para el esquema, no el código**.
     *
     * `dis_ordinales.year_id` es `NOT NULL` con clave ajena a `years`. Es la misma
     * forma que la §78 —nueve catálogos y cuatro respuestas distintas al mismo
     * cuerpo vacío, separadas por el esquema— y la misma que `disciplina/store`
     * (§86). Lo que salva la situación es que **no escribe**.
     */
    public function test_un_alta_sin_year_id_la_para_el_esquema(): void
    {
        $token = $this->tokenDelPersonal();
        $antes = DB::table('dis_ordinales')->count();

        $this->withToken($token)->postJson('/api/ordinales/store', [])->assertStatus(500);

        $this->assertSame($antes, DB::table('dis_ordinales')->count());
    }

    /**
     * §87.1 — Cuatro rutas confirman una escritura que no ocurrió.
     *
     * Sin su identificador en el cuerpo, las cuatro contestan 200 con «Cambiado» o
     * «Eliminado» y **no tocan una sola fila**: el `WHERE id=?` compara contra null
     * y no casa. Un cliente que pierda el campo por el camino ve «guardado».
     *
     * Es la familia de `respuestas-que-mienten.py` por un camino que la herramienta
     * no ve: no hay nada que «frene» la escritura, es que el `WHERE` no encuentra a
     * quién escribir. Queda escrito como una ceguera del detector, no como un fallo
     * suyo.
     *
     * **Lo que había que descartar y queda descartado con número: no es masivo.**
     * `WHERE id = NULL` no casa con ninguna fila, no con todas. Si algún día
     * alguien reescribe esto con un `WHERE` distinto, estos contadores caen.
     *
     * No se arregla: pasarlas a 422 es visible en dieciséis colegios y hoy nadie ha
     * medido qué manda cada uno de los cuatro clientes. Se anota.
     */
    public function test_las_cuatro_confirman_lo_que_no_hicieron_y_no_tocan_nada(): void
    {
        $token = $this->tokenDelPersonal();

        $ordinalesAntes = DB::table('dis_ordinales')->get()->toArray();
        $configAntes = DB::table('dis_configuraciones')->get()->toArray();

        $this->withToken($token)->putJson('/api/ordinales/update',
            ['tipo' => 'MASIVO', 'ordinal' => 'MASIVO', 'descripcion' => 'MASIVO', 'pagina' => 'MASIVO'])
            ->assertStatus(200)->assertSee('Cambiado');

        $this->withToken($token)->putJson('/api/ordinales/guardar-valor',
            ['propiedad' => 'pagina', 'valor' => 'MASIVO'])->assertStatus(200)->assertSee('Cambiado');

        $this->withToken($token)->putJson('/api/ordinales/guardar-valor-config',
            ['propiedad' => 'nombre_col1', 'valor' => 'MASIVO'])->assertStatus(200)->assertSee('Cambiado');

        $this->withToken($token)->putJson('/api/ordinales/destroy', [])
            ->assertStatus(200)->assertSee('Eliminado');

        $this->assertEquals($ordinalesAntes, DB::table('dis_ordinales')->get()->toArray(),
            'Ni una fila de `dis_ordinales` cambió: el WHERE no casó con nada, y menos con todo.');
        $this->assertEquals($configAntes, DB::table('dis_configuraciones')->get()->toArray(),
            'Ni una de `dis_configuraciones`.');
    }

    /** Y con un id que no existe, lo mismo: 200 y ninguna fila. */
    public function test_un_id_que_no_existe_tambien_confirma(): void
    {
        $token = $this->tokenDelPersonal();
        $inexistente = ((int) DB::table('dis_ordinales')->max('id')) + 1000;
        $antes = DB::table('dis_ordinales')->get()->toArray();

        $this->withToken($token)->putJson('/api/ordinales/update',
            ['id' => $inexistente, 'tipo' => 'X', 'ordinal' => 'X', 'descripcion' => 'X', 'pagina' => 'X'])
            ->assertStatus(200);
        $this->withToken($token)->putJson('/api/ordinales/destroy', ['ordinal_id' => $inexistente])
            ->assertStatus(200);

        $this->assertEquals($antes, DB::table('dis_ordinales')->get()->toArray());
    }

    /**
     * §87.2 — El manual de convivencia de otro año se edita desde este.
     *
     * Ninguna de las cinco compara el año del ordinal con el del usuario, y
     * `postStore` toma el `year_id` **del cuerpo**. Los ordinales son de un año
     * —`YearsController` los copia al abrir el siguiente, precisamente para que
     * cada año tenga los suyos—, así que tocar los de un año cerrado reescribe el
     * artículo citado en faltas ya sancionadas de ese año.
     *
     * `ordinales/destroy` sale además en `identificadores-del-cuerpo.py` como
     * identificador sin comprobar propiedad. Aquí queda juzgado: **no es un falso
     * positivo**, y lo que no comprueba no es de quién es el ordinal —son todos del
     * mismo colegio— sino **de qué año**.
     *
     * Las 44 rutas de escritura de la configuración del colegio llevan solo
     * `auth.personal` y **Joseth decidió no cerrarlas** para no dejar fuera a un
     * coordinador sin rol. Esto es de esa familia: se mide, no se cierra.
     */
    public function test_se_edita_y_se_borra_el_ordinal_de_otro_anio(): void
    {
        [$token, $miYear] = $this->contextoDelPersonal();

        $otroYear = (int) DB::table('years')->whereNull('deleted_at')
            ->where('id', '!=', $miYear)->orderBy('id')->value('id');
        $this->assertNotSame(0, $otroYear, 'El seed no tiene un segundo año.');

        // Se crea directamente en el año ajeno, con el `year_id` del cuerpo.
        $ajeno = $this->unOrdinal($token, $otroYear, 'DE-OTRO-AÑO');
        $this->assertSame($otroYear, (int) DB::table('dis_ordinales')->where('id', $ajeno)->value('year_id'),
            'El alta escribe en el año que venga en el cuerpo, no en el del usuario.');

        $this->withToken($token)->putJson('/api/ordinales/update', [
            'id' => $ajeno, 'tipo' => 'T9', 'ordinal' => 'Reescrito desde otro año',
            'descripcion' => 'x', 'pagina' => '1',
        ])->assertStatus(200);

        $this->assertSame('Reescrito desde otro año',
            DB::table('dis_ordinales')->where('id', $ajeno)->value('ordinal'));

        // Y el valor suelto puede mover el ordinal de año: `year_id` es una columna
        // real y `ColumnaSegura` solo prohíbe el id y las de auditoría.
        $this->withToken($token)->putJson('/api/ordinales/guardar-valor',
            ['ordinal_id' => $ajeno, 'propiedad' => 'year_id', 'valor' => $miYear])->assertStatus(200);

        $this->assertSame($miYear, (int) DB::table('dis_ordinales')->where('id', $ajeno)->value('year_id'),
            'Un ordinal cambia de año por la ruta de guardar un campo suelto.');

        $this->withToken($token)->putJson('/api/ordinales/destroy', ['ordinal_id' => $ajeno])
            ->assertStatus(200);
        $this->assertNotNull(DB::table('dis_ordinales')->where('id', $ajeno)->value('deleted_at'));
    }

    /**
     * §87.3 — Editar un ordinal reescribe la falta que ya estaba sancionada.
     *
     * Y se mira desde donde se ve: `ChangesAsked/to-me`, la pantalla de inicio del
     * **propio alumno**. `dis_proceso_ordinales` guarda solo el id, así que el
     * texto de la falta se resuelve al leerla. Cambiar el ordinal cambia lo que el
     * alumno —y el boletín impreso— dicen que hizo.
     *
     * No se arregla: congelar el texto al sancionar es decisión del colegio, y
     * además hoy es lo que permite corregir una errata del manual.
     */
    public function test_editar_un_ordinal_reescribe_la_falta_ya_sancionada(): void
    {
        [$token, $year] = $this->contextoDelPersonal();
        [$tokenAlumno, $ctx] = $this->alumnoConSuFalta($token, $year);

        $situacion = $this->situacionesDelAlumno($tokenAlumno)[0];
        $this->assertSame('Artículo 12', $situacion['ordinal']);
        $this->assertSame('Vocabulario soez en clase', $situacion['descrip_ord']);
        $this->assertSame('34', $situacion['pagina']);

        $this->withToken($token)->putJson('/api/ordinales/update', [
            'id' => $ctx['ordinal'], 'tipo' => 'T1', 'ordinal' => 'Artículo 99',
            'descripcion' => 'Agresión física a un compañero', 'pagina' => '78',
        ])->assertStatus(200);

        $situacion = $this->situacionesDelAlumno($tokenAlumno)[0];
        $this->assertSame($ctx['proceso'], (int) $situacion['id'], 'Es la misma falta.');
        $this->assertSame('Artículo 99', $situacion['ordinal']);
        $this->assertSame('Agresión física a un compañero', $situacion['descrip_ord'],
            'El alumno ve otra falta sin que nadie haya tocado su falta.');
        $this->assertSame('78', $situacion['pagina']);
    }

    /**
     * §87.4 — Un ordinal citado por una falta ya no se borra: 422, y la falta
     * conserva su artículo.
     *
     * Este test medía el daño y hoy mide que la puerta está cerrada. Lo que medía:
     * el `LEFT JOIN` de `Models\Disciplina` lleva `o.deleted_at is null`, así que
     * al borrar el ordinal la falta **seguía saliendo en el observador del alumno**
     * con `ordinal`, `descrip_ord` y `pagina` en null. Por la regla de la §70.2 eso
     * es «un hueco visible» y no «esconder la fila» — pero **aquí el hueco es el
     * contenido**: una falta sin su artículo ya no dice qué norma se incumplió, y
     * es un registro disciplinario de un menor.
     *
     * **Joseth, 23 ago 2026: se impide, y el aviso dice cuántas dependen.** Las dos
     * mitades: que corta con 422 **y que el alumno sigue viendo su artículo**.
     */
    public function test_un_ordinal_citado_por_una_falta_no_se_borra(): void
    {
        [$token, $year] = $this->contextoDelPersonal();
        [$tokenAlumno, $ctx] = $this->alumnoConSuFalta($token, $year);

        $this->assertSame('Artículo 12', $this->situacionesDelAlumno($tokenAlumno)[0]['ordinal']);

        $this->withToken($token)->putJson('/api/ordinales/destroy', ['ordinal_id' => $ctx['ordinal']])
            ->assertStatus(422);

        $this->assertNull(DB::table('dis_ordinales')->where('id', $ctx['ordinal'])->value('deleted_at'),
            'Contestó 422 y aun así borró el ordinal.');

        $situaciones = $this->situacionesDelAlumno($tokenAlumno);

        $this->assertCount(1, $situaciones);
        $this->assertSame($ctx['proceso'], (int) $situaciones[0]['id']);
        $this->assertSame('Artículo 12', $situaciones[0]['ordinal'],
            'La falta perdió su artículo: el 422 llegó después de borrar.');

        $this->assertSame(1, DB::table('dis_proceso_ordinales')
            ->where('proceso_id', $ctx['proceso'])->whereNull('deleted_at')->count());
    }

    /** Una familia no toca ninguna de las cinco. */
    public function test_una_familia_no_toca_el_manual_de_convivencia(): void
    {
        [$token, $year] = $this->contextoDelPersonal();
        $id = $this->unOrdinal($token, $year);
        $antes = DB::table('dis_ordinales')->get()->toArray();

        foreach (['Alumno', 'Acudiente'] as $tipo) {
            $suyo = $this->tokenDe($this->usuarioDeTipo($tipo)->username);

            $this->withToken($suyo)->postJson('/api/ordinales/store',
                ['year_id' => $year, 'ordinal' => 'X', 'tipo' => 'X'])->assertStatus(403);
            $this->withToken($suyo)->putJson('/api/ordinales/update',
                ['id' => $id, 'ordinal' => 'X'])->assertStatus(403);
            $this->withToken($suyo)->putJson('/api/ordinales/guardar-valor',
                ['ordinal_id' => $id, 'propiedad' => 'pagina', 'valor' => 'X'])->assertStatus(403);
            $this->withToken($suyo)->putJson('/api/ordinales/guardar-valor-config',
                ['config_id' => 1, 'propiedad' => 'nombre_col1', 'valor' => 'X'])->assertStatus(403);
            $this->withToken($suyo)->putJson('/api/ordinales/destroy',
                ['ordinal_id' => $id])->assertStatus(403);
        }

        $this->assertEquals($antes, DB::table('dis_ordinales')->get()->toArray());
    }

    /**
     * Un alumno con una falta abierta contra un ordinal recién creado.
     *
     * El año no se elige: `Services\Login` reescribe `users.periodo_id` al periodo
     * `actual` en cada inicio de sesión, así que el año efectivo del token no es el
     * que trae el seed. Se lee del contexto DESPUÉS de pedir el token, que es la
     * trampa que ha vaciado más de un informe sin que fallara nada.
     *
     * @return array{0:string,1:array{ordinal:int,proceso:int}}
     */
    private function alumnoConSuFalta(string $tokenPersonal, int $year): array
    {
        $usuario = $this->usuarioDeTipo('Alumno');
        $tokenAlumno = $this->tokenDe($usuario->username);

        $periodo = (int) DB::table('users')->where('id', $usuario->id)->value('periodo_id');
        $yearAlumno = (int) DB::table('periodos')->where('id', $periodo)->value('year_id');
        $alumnoId = (int) DB::table('alumnos')->where('user_id', $usuario->id)->value('id');

        $this->assertSame($year, $yearAlumno,
            'El alumno y el personal tienen que estar en el mismo año o la falta no se ve.');

        $ordinal = $this->unOrdinal($tokenPersonal, $year);

        $this->withToken($tokenPersonal)->postJson('/api/disciplina/store', [
            'year_id' => $year,
            'alumno_id' => $alumnoId,
            'periodo_id' => $periodo,
            'descripcion' => 'Falta con su artículo citado',
            'tipo_situacion' => 1,
            'selected_ordinales' => [['id' => $ordinal]],
        ])->assertStatus(200);

        return [$tokenAlumno, [
            'ordinal' => $ordinal,
            'proceso' => (int) DB::table('dis_procesos')->orderByDesc('id')->value('id'),
        ]];
    }

    /** Lo que el alumno ve de sus propias faltas en su pantalla de inicio. */
    private function situacionesDelAlumno(string $tokenAlumno): array
    {
        return $this->withToken($tokenAlumno)->getJson('/api/ChangesAsked/to-me')
            ->assertStatus(200)->json('situaciones');
    }
}
