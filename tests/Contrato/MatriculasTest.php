<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Las matrículas: quién está en qué grupo, desde cuándo y hasta cuándo.
 *
 * Segundo bloque P1 de la Fase 0.2. `matriculas.estado` es el campo del que
 * cuelga casi todo el sistema —los boletines listan por él, el acta cuenta por
 * él, el export de deudores filtra por él— y lo mueven quince endpoints de
 * escritura, cada uno con su propia copia del mismo `if` de permisos. Aquí se
 * fija qué transición hace cada uno y quién puede pedirla.
 *
 * Lo que NO se toca es el criterio: si retirar debería exigir fecha, o si un
 * acudiente debería poder prematricular, lo decide el colegio. Se escribe lo que
 * hace hoy para que se note el día que cambie.
 */
class MatriculasTest extends CasoDeContrato
{
    /**
     * Un superusuario, que es lo que exigen las escrituras de este controlador.
     *
     * `tokenDelPersonalDe()` no sirve aquí: devuelve el primer `Usuario` del
     * año, y el `if` que llevan los quince métodos es
     * `(tipo == 'Profesor' && profes_can_edit_alumnos) || is_superuser`. Un
     * `Usuario` del colegio SIN superusuario —una secretaria— recibe 400 «No
     * tiene permisos para editar», aunque sí pase los guards de `auth.personal`.
     * Son dos escalas de permiso distintas conviviendo, y esa es la de aquí.
     */
    private function superusuario(int $yearId): string
    {
        $fila = DB::selectOne('SELECT u.username FROM users u
            INNER JOIN periodos p ON p.id = u.periodo_id AND p.deleted_at IS NULL
            WHERE u.is_superuser = 1 AND u.is_active = 1 AND u.deleted_at IS NULL
              AND p.year_id = ? ORDER BY u.id LIMIT 1', [$yearId]);

        $this->assertNotNull($fila, "El seed no tiene ningún superusuario en el año {$yearId}.");

        return $this->tokenDe($fila->username);
    }

    /** Una matrícula viva del grupo del seed, con su alumno. */
    private function unaMatricula(int $grupoId): object
    {
        $fila = DB::selectOne('SELECT m.id, m.alumno_id, m.estado, m.nuevo FROM matriculas m
            INNER JOIN alumnos a ON a.id = m.alumno_id AND a.deleted_at IS NULL
            WHERE m.grupo_id = ? AND m.deleted_at IS NULL
              AND m.estado IN ("MATR","ASIS","PREM") ORDER BY m.id LIMIT 1', [$grupoId]);

        $this->assertNotNull($fila, "El grupo {$grupoId} no tiene matrículas vivas.");

        return $fila;
    }

    private function estadoDe(int $matriculaId): ?string
    {
        $fila = DB::selectOne('SELECT estado FROM matriculas WHERE id = ?', [$matriculaId]);

        return $fila?->estado;
    }

    // ------------------------------------------------------------- Las lecturas

    /**
     * La pantalla de matrículas: los del grupo, los que se fueron y los del grado anterior.
     *
     * `alumnos-con-grado-anterior` devuelve las tres listas por separado y
     * `alumnos-grado-anterior` las mismas tres pegadas con UNION. Las dos existen
     * y las dos están ruteadas; el snapshot de cada una es lo que enseña en qué
     * se diferencian de verdad.
     *
     * El `year_ant` que se manda es el del grupo menos uno, que es lo que hace la
     * pantalla.
     *
     * **`AlumnosSinMatricula` sale vacía con el seed, y seguirá saliendo.** El
     * seed es una rebanada de un solo grupo de un solo año, así que no hay ningún
     * grupo del grado anterior del que sacar candidatos. Se deja escrito porque un
     * snapshot con una lista vacía pasa siempre: esa tercera consulta —la del
     * `NOT IN`, la más enredada de las tres— **no la cubre nadie**. Para cubrirla
     * hace falta un seed con dos años, y eso es trabajo de la P2.
     */
    public function test_la_forma_de_las_tres_listas_de_la_pantalla_de_matriculas(): void
    {
        [$grupo, $token] = $this->grupoYPersonal();

        $contexto = DB::selectOne('SELECT y.year, g.grado_id FROM grupos g
            INNER JOIN years y ON y.id = g.year_id WHERE g.id = ?', [$grupo->id]);

        $cuerpo = [
            'grupo_actual' => ['id' => $grupo->id],
            'grado_ant_id' => $contexto->grado_id,
            'year_ant' => $contexto->year - 1,
        ];

        $r = $this->putJson('/api/matriculas/alumnos-con-grado-anterior', $cuerpo,
            ['Authorization' => 'Bearer '.$token]);

        $r->assertStatus(200);

        $this->assertSame(['AlumnosActuales', 'AlumnosDesertRetir', 'AlumnosSinMatricula'],
            array_keys($r->json()), 'Cambiaron las tres listas de la pantalla de matrículas.');

        $this->assertNotEmpty($r->json('AlumnosActuales'), 'El grupo salió sin alumnos actuales.');

        $this->compararConInstantanea('matriculas-alumnos-con-grado-anterior',
            $this->formaUnida($r->json()));
    }

    /**
     * La misma pantalla trae la maqueta de la tabla de acudientes dentro del JSON.
     *
     * `subGridOptions` lleva plantillas de Angular —`ng-click`, `uib-tooltip`— y
     * anchos de columna escritos en PHP. Es de las cosas que la migración puede
     * romper sin que nadie lo note hasta que la rejilla de acudientes sale sin
     * botones, y no se ve en la forma: `columnDefs` es una lista de objetos con
     * las mismas claves.
     *
     * Se comprueban por nombre las columnas y que los cuatro botones sigan ahí.
     */
    public function test_la_rejilla_de_acudientes_viene_maquetada_desde_el_backend(): void
    {
        [$grupo, $token] = $this->grupoYPersonal();

        $contexto = DB::selectOne('SELECT y.year, g.grado_id FROM grupos g
            INNER JOIN years y ON y.id = g.year_id WHERE g.id = ?', [$grupo->id]);

        $r = $this->putJson('/api/matriculas/alumnos-con-grado-anterior', [
            'grupo_actual' => ['id' => $grupo->id],
            'grado_ant_id' => $contexto->grado_id,
            'year_ant' => $contexto->year - 1,
        ], ['Authorization' => 'Bearer '.$token]);

        $rejilla = $r->json('AlumnosActuales.0.subGridOptions');

        $this->assertTrue($rejilla['enableCellEditOnFocus']);

        $this->assertSame(
            ['edicion', 'Id', 'Nombres', 'Apellidos', 'Sex', 'Parentesco', 'Usuario', 'Documento',
                'Ciudad doc', 'Fecha nac', 'Ciudad nac', 'Teléfono', 'Celular', 'Ocupación',
                'Email', 'Barrio', 'Dirección'],
            array_column($rejilla['columnDefs'], 'name'),
            'Cambiaron las columnas de la rejilla de acudientes.');

        foreach (['cambiarAcudiente', 'quitarAcudiente', 'asignarAOtro', 'agregarAcudiente'] as $accion) {
            $this->assertStringContainsString($accion, $rejilla['columnDefs'][0]['cellTemplate'],
                "La rejilla de acudientes perdió el botón «{$accion}».");
        }

        // La fila en blanco del final es la que hace aparecer el botón «Agregar...».
        $this->assertSame(['nombres' => null], end($rejilla['data']),
            'Sin la fila vacía del final, la rejilla no ofrece añadir acudiente.');
    }

    public function test_la_forma_de_la_lista_unida_del_grado_anterior(): void
    {
        [$grupo, $token] = $this->grupoYPersonal();

        $contexto = DB::selectOne('SELECT y.year, g.grado_id FROM grupos g
            INNER JOIN years y ON y.id = g.year_id WHERE g.id = ?', [$grupo->id]);

        $r = $this->putJson('/api/matriculas/alumnos-grado-anterior', [
            'grupo_actual' => ['id' => $grupo->id],
            'grado_ant_id' => $contexto->grado_id,
            'year_ant' => $contexto->year - 1,
        ], ['Authorization' => 'Bearer '.$token]);

        $r->assertStatus(200);

        $this->assertNotEmpty($r->json(), 'La lista unida salió vacía.');

        $this->compararConInstantanea('matriculas-alumnos-grado-anterior',
            $this->formaUnida($r->json()));
    }

    /** Sin `grupo_actual` las dos responden 200 con el cuerpo vacío, no 422. */
    #[DataProvider('listasDelGradoAnterior')]
    public function test_sin_grupo_las_listas_responden_vacio(string $ruta): void
    {
        [$grupo, $token] = $this->grupoYPersonal();

        $r = $this->putJson("/api/matriculas/{$ruta}", [], ['Authorization' => 'Bearer '.$token]);

        $r->assertStatus(200);
        $this->assertSame('', $r->getContent(),
            'Dejó de responder con el cuerpo vacío. El `return;` sin valor es lo que hay hoy.');
    }

    public static function listasDelGradoAnterior(): array
    {
        return [
            'con-grado-anterior' => ['alumnos-con-grado-anterior'],
            'grado-anterior' => ['alumnos-grado-anterior'],
        ];
    }

    // ------------------------------------------------------ Las transiciones

    /**
     * Cada endpoint de estado deja la matrícula donde dice su nombre.
     *
     * Son cinco rutas que hacen lo mismo con un literal distinto, escritas cinco
     * veces. El proveedor las pone en una tabla para que se vea que la única
     * diferencia entre ellas es el estado, y para que añadir una sexta sea una
     * línea.
     */
    public static function transiciones(): array
    {
        return [
            'retirar' => ['retirar', 'PUT', 'RETI'],
            'desertar' => ['desertar', 'PUT', 'DESE'],
            'set-asistente' => ['set-asistente', 'PUT', 'ASIS'],
            're-matricularuno' => ['re-matricularuno', 'PUT', 'MATR'],
        ];
    }

    #[DataProvider('transiciones')]
    public function test_cada_ruta_deja_la_matricula_en_su_estado(string $ruta, string $verbo, string $estado): void
    {
        $grupo = $this->grupoConAlumnos();
        $token = $this->superusuario((int) $grupo->year_id);
        $matricula = $this->unaMatricula((int) $grupo->id);

        $this->json($verbo, "/api/matriculas/{$ruta}", [
            'matricula_id' => $matricula->id,
            'fecha_retiro' => '2026-08-19',
        ], ['Authorization' => 'Bearer '.$token])->assertStatus(200);

        $this->assertSame($estado, $this->estadoDe((int) $matricula->id),
            "PUT matriculas/{$ruta} no dejó la matrícula en {$estado}.");
    }

    /**
     * Re-matricular pone el número de folio si no lo tenía, y no lo pisa si lo tenía.
     *
     * El folio es `{año}-{alumno_id}` y es lo que el colegio escribe en el libro
     * de matrícula. Que no se sobrescriba importa: un folio ya asignado apunta a
     * una página de un libro físico.
     */
    public function test_re_matricular_asigna_el_folio_solo_si_falta(): void
    {
        $grupo = $this->grupoConAlumnos();
        $token = $this->superusuario((int) $grupo->year_id);
        $matricula = $this->unaMatricula((int) $grupo->id);
        $cab = ['Authorization' => 'Bearer '.$token];

        DB::update('UPDATE matriculas SET nro_folio = NULL WHERE id = ?', [$matricula->id]);

        $this->putJson('/api/matriculas/re-matricularuno', ['matricula_id' => $matricula->id], $cab)
            ->assertStatus(200);

        $folio = DB::selectOne('SELECT nro_folio f FROM matriculas WHERE id = ?', [$matricula->id])->f;
        $anio = DB::selectOne('SELECT y.year FROM matriculas m
            INNER JOIN grupos g ON g.id = m.grupo_id INNER JOIN years y ON y.id = g.year_id
            WHERE m.id = ?', [$matricula->id])->year;

        $this->assertSame("{$anio}-{$matricula->alumno_id}", $folio);

        DB::update('UPDATE matriculas SET nro_folio = "NO-TOCAR" WHERE id = ?', [$matricula->id]);

        $this->putJson('/api/matriculas/re-matricularuno', ['matricula_id' => $matricula->id], $cab)
            ->assertStatus(200);

        $this->assertSame('NO-TOCAR',
            DB::selectOne('SELECT nro_folio f FROM matriculas WHERE id = ?', [$matricula->id])->f,
            'Re-matricular pisó un número de folio ya asignado.');
    }

    public function test_las_dos_fechas_y_los_dos_interruptores_se_guardan(): void
    {
        $grupo = $this->grupoConAlumnos();
        $token = $this->superusuario((int) $grupo->year_id);
        $matricula = $this->unaMatricula((int) $grupo->id);
        $cab = ['Authorization' => 'Bearer '.$token];

        $this->putJson('/api/matriculas/cambiar-fecha-matricula',
            ['matricula_id' => $matricula->id, 'fecha_matricula' => '2026-02-03'], $cab)->assertStatus(200);

        $this->putJson('/api/matriculas/cambiar-fecha-retiro',
            ['matricula_id' => $matricula->id, 'fecha_retiro' => '2026-10-15'], $cab)->assertStatus(200);

        $this->putJson('/api/matriculas/toggle-nuevo',
            ['matricula_id' => $matricula->id, 'is_nuevo' => 1], $cab)->assertStatus(200);

        $this->putJson('/api/matriculas/set-promovido',
            ['matricula_id' => $matricula->id, 'valor' => 'No promovido (manual)'], $cab)->assertStatus(200);

        $fila = DB::selectOne('SELECT fecha_matricula, fecha_retiro, nuevo, promovido
            FROM matriculas WHERE id = ?', [$matricula->id]);

        $this->assertSame('2026-02-03', $fila->fecha_matricula);
        $this->assertSame('2026-10-15', $fila->fecha_retiro);
        $this->assertSame(1, (int) $fila->nuevo);
        $this->assertSame('No promovido (manual)', $fila->promovido);
    }

    /**
     * `set-promovido` sin valor vuelve a «Automático», que es el default de la columna.
     *
     * No es un detalle de estilo: `Automático` es el valor que
     * `ActasEvaluacionController` clasifica como SIN_DEFINIR, y toda la
     * distinción entre «no se calculó» y «se decidió que no» cuelga de él.
     */
    public function test_set_promovido_sin_valor_vuelve_a_automatico(): void
    {
        $grupo = $this->grupoConAlumnos();
        $token = $this->superusuario((int) $grupo->year_id);
        $matricula = $this->unaMatricula((int) $grupo->id);

        $this->putJson('/api/matriculas/set-promovido', ['matricula_id' => $matricula->id],
            ['Authorization' => 'Bearer '.$token])->assertStatus(200);

        $this->assertSame('Automático',
            DB::selectOne('SELECT promovido p FROM matriculas WHERE id = ?', [$matricula->id])->p);
    }

    /**
     * Las dos formas de quitar una matrícula, que no son la misma.
     *
     * `destroy` la marca RETI y la manda a la papelera —reversible—; y
     * `quitar-prematricula` hace un `DELETE FROM matriculas` de verdad, sin
     * papelera y sin comprobar el estado, así que borra también una matrícula
     * MATR de un alumno del año en curso. Está marcada «// Inutil:» en el código
     * y sigue ruteada.
     */
    public function test_destroy_manda_a_la_papelera_y_quitar_prematricula_borra_de_verdad(): void
    {
        $grupo = $this->grupoConAlumnos();
        $token = $this->superusuario((int) $grupo->year_id);
        $cab = ['Authorization' => 'Bearer '.$token];

        $matricula = $this->unaMatricula((int) $grupo->id);

        $this->deleteJson("/api/matriculas/destroy/{$matricula->id}", [], $cab)->assertStatus(200);

        $fila = DB::selectOne('SELECT estado, deleted_at FROM matriculas WHERE id = ?', [$matricula->id]);

        $this->assertNotNull($fila, 'destroy borró la fila en vez de mandarla a la papelera.');
        $this->assertSame('RETI', $fila->estado);
        $this->assertNotNull($fila->deleted_at);

        $otra = $this->unaMatricula((int) $grupo->id);

        $this->putJson('/api/matriculas/quitar-prematricula', ['matricula_id' => $otra->id], $cab)
            ->assertStatus(200);

        $this->assertNull(DB::selectOne('SELECT id FROM matriculas WHERE id = ?', [$otra->id]),
            'quitar-prematricula dejó rastro: hoy hace un DELETE sin papelera.');
    }

    // ------------------------------------------------------------- Los permisos

    /**
     * Las escrituras piden superusuario, y responden 400 —no 403— a quien no lo es.
     *
     * El 400 es el del código legacy: `abort('400', 'No tiene permisos para
     * editar')`, quince veces copiado. Se fija tal cual porque el frontend lo
     * está leyendo así; corregirlo a 403 es de la fase de códigos de error, no
     * de esta.
     *
     * Lo que sí importa comprobar aquí es que el guard existe en las quince, y no
     * en catorce.
     */
    public function test_ninguna_escritura_de_matriculas_acepta_a_quien_no_es_superusuario(): void
    {
        $grupo = $this->grupoConAlumnos();
        $matricula = $this->unaMatricula((int) $grupo->id);

        // Un Usuario del colegio sin superusuario: pasa `auth.personal` y no pasa esto.
        $fila = DB::selectOne('SELECT u.username FROM users u
            INNER JOIN periodos p ON p.id = u.periodo_id
            WHERE u.tipo = "Usuario" AND u.is_superuser = 0 AND u.is_active = 1
              AND u.deleted_at IS NULL AND p.year_id = ? ORDER BY u.id LIMIT 1', [$grupo->year_id]);

        $this->assertNotNull($fila, 'El seed no tiene ningún Usuario sin superusuario en el año del grupo.');

        $cab = ['Authorization' => 'Bearer '.$this->tokenDe($fila->username)];
        $cuerpo = ['matricula_id' => $matricula->id, 'alumno_id' => $matricula->alumno_id,
            'grupo_id' => $grupo->id, 'year_id' => $grupo->year_id, 'fecha_retiro' => '2026-08-19'];

        $rutas = [
            ['POST', 'matricularuno'], ['POST', 'matricular-en'], ['PUT', 're-matricularuno'],
            ['PUT', 'set-promovido'], ['PUT', 'set-asistente'], ['PUT', 'set-new-asistente'],
            ['PUT', 'cambiar-fecha-retiro'], ['PUT', 'cambiar-fecha-matricula'],
            ['PUT', 'toggle-nuevo'], ['PUT', 'retirar'], ['PUT', 'quitar-prematricula'],
            ['PUT', 'desertar'],
        ];

        foreach ($rutas as [$verbo, $ruta]) {
            $this->json($verbo, "/api/matriculas/{$ruta}", $cuerpo, $cab)
                ->assertStatus(400)
                ->assertJsonPath('message', 'No tiene permisos para editar');
        }

        $this->deleteJson("/api/matriculas/destroy/{$matricula->id}", [], $cab)
            ->assertStatus(400);

        $this->assertSame($matricula->estado, $this->estadoDe((int) $matricula->id),
            'Alguna de las rutas rechazadas alcanzó a escribir.');
    }

    /**
     * `prematricular` es la excepción: la abren también a Alumno y a Acudiente.
     *
     * Y **no comprueba de qué alumno se trata**. `alumno_id` llega en el cuerpo
     * de la petición, así que con un token de alumno se puede mover la matrícula
     * de cualquier compañero: cambiarle el estado y meterlo en otro grupo.
     * Comprobado contra el seed, responde 200 y la fila queda escrita.
     *
     * Es el mismo agujero que el IDOR de notas del P0 pero de ESCRITURA, y no se
     * cierra aquí por lo mismo que no se cerró el paz y salvo de las notas: la
     * apertura a Alumno y Acudiente es deliberada —la prematrícula del año
     * siguiente la hace la familia desde su cuenta— y acotarla a «el suyo» es una
     * decisión del colegio con efecto en producción desde el despliegue. Hay
     * middleware para hacerlo en una línea (`boletin.propio`, que ya entiende el
     * `alumno_id` suelto).
     *
     * **Este test pasa hoy y debe fallar el día que se cierre.** Cuando eso pase,
     * la respuesta correcta es cambiarlo por su contrario, no borrarlo.
     */
    public function test_hoy_un_alumno_puede_prematricular_a_un_companero(): void
    {
        $grupo = $this->grupoConAlumnos();
        $yo = $this->usuarioDeTipo('Alumno');

        $mio = DB::selectOne('SELECT id FROM alumnos WHERE user_id = ? AND deleted_at IS NULL', [$yo->id]);

        $companero = DB::selectOne('SELECT a.id FROM alumnos a
            INNER JOIN matriculas m ON m.alumno_id = a.id AND m.grupo_id = ? AND m.deleted_at IS NULL
            WHERE a.id <> ? AND a.deleted_at IS NULL ORDER BY a.id LIMIT 1', [$grupo->id, $mio->id]);

        $this->putJson('/api/matriculas/prematricular', [
            'alumno_id' => $companero->id,
            'grupo_id' => $grupo->id,
            'estado' => 'PREM',
            'anio_sig' => 0,
        ], ['Authorization' => 'Bearer '.$this->tokenDe($yo->username)])->assertStatus(200);

        $this->assertSame('PREM',
            DB::selectOne('SELECT estado e FROM matriculas
                WHERE alumno_id = ? AND grupo_id = ? AND deleted_at IS NULL',
                [$companero->id, $grupo->id])->e,
            "El agujero se cerró: un alumno ya no puede mover la matrícula de otro.\n".
            'Cámbialo por su contrario en vez de borrarlo.');
    }

    // ---------------------------------------------------------- Prematrículas

    /**
     * `prematriculas/llevo-formulario` escribe en una tabla que no existe.
     *
     * `llevo_formulario` no está en `database/schema/mysql-schema.sql` —que es el
     * volcado de la base real, las 90 tablas— ni en la de desarrollo. El método
     * hace `DELETE FROM llevo_formulario` de entrada, así que la ruta es un 500
     * seguro en producción desde siempre.
     *
     * Es el mismo caso que `failed_jobs`, ya anotado en el documento de tests:
     * código que da por hecha una tabla que nadie creó. Se fija como está —el
     * test dice «hoy revienta»— porque crear la tabla es decidir un esquema, y
     * quitar la ruta es decidir que la pantalla que la llama sobra. Ninguna de
     * las dos es cosa de la Fase 0.
     */
    public function test_llevo_formulario_revienta_porque_su_tabla_no_existe(): void
    {
        $grupo = $this->grupoConAlumnos();
        $matricula = $this->unaMatricula((int) $grupo->id);

        $this->assertFalse(
            \Illuminate\Support\Facades\Schema::hasTable('llevo_formulario'),
            "La tabla `llevo_formulario` ya existe. Si se creó a propósito, este test\n".
            'sobra y hay que escribir el que compruebe que la ruta guarda.');

        $this->withoutExceptionHandling();

        $this->expectException(\Illuminate\Database\QueryException::class);

        $this->putJson('/api/prematriculas/llevo-formulario', [
            'alumno_id' => $matricula->alumno_id,
            'llevo_formulario' => 1,
            'year' => 2026,
        ], ['Authorization' => 'Bearer '.$this->superusuario((int) $grupo->year_id)]);
    }

    /**
     * Las tres listas de prematrículas son las de matrículas con otro filtro.
     *
     * `PrematriculasController` es una copia de `MatriculasController` para la
     * pantalla del año siguiente. Las rutas llevan `auth.personal`, que las de
     * matrículas no llevan — otra asimetría entre copias, como la de los
     * boletines.
     *
     * Aquí salen vacías tres de las cuatro listas —`AlumnosFormularios`,
     * `AlumnosPrematriculadosA` y `AlumnosSinMatricula`— por lo mismo que en el
     * test de arriba: el seed tiene un año y un grupo. El snapshot registra la
     * forma completa de la respuesta, que es lo que protege de que desaparezca una
     * de las cuatro claves, pero el contenido de esas tres no lo comprueba nadie.
     */
    public function test_la_forma_de_las_listas_de_prematriculas(): void
    {
        [$grupo, $token] = $this->grupoYPersonal();

        $contexto = DB::selectOne('SELECT y.year, g.grado_id FROM grupos g
            INNER JOIN years y ON y.id = g.year_id WHERE g.id = ?', [$grupo->id]);

        $cuerpo = [
            'grupo_actual' => ['id' => $grupo->id],
            'grado_ant_id' => $contexto->grado_id,
            'year_ant' => $contexto->year - 1,
        ];

        $r = $this->putJson('/api/prematriculas/alumnos-con-grado-anterior', $cuerpo,
            ['Authorization' => 'Bearer '.$token]);

        $r->assertStatus(200);

        $this->compararConInstantanea('prematriculas-alumnos-con-grado-anterior',
            $this->formaUnida($r->json()));
    }
}
