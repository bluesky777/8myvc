<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
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
     * **`AlumnosSinMatricula` sale vacía aquí**, y no es un descuido: los 56
     * alumnos del grupo del año anterior están todos matriculados en el actual,
     * así que el `NOT IN` no deja pasar a ninguno. La consulta —la más enredada
     * de las tres— la cubre
     * test_los_del_grado_anterior_sin_matricular_salen_en_su_lista, que se
     * fabrica el caso desmatriculando a uno.
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
     * La tercera lista: los del grado anterior que todavía no se han matriculado.
     *
     * Es la consulta del `NOT IN`, y hasta el 20 ago 2026 **no la ejecutaba
     * nadie**. Primero porque el seed tenía un año solo y no había grado
     * anterior; y después de ampliarlo a dos, porque los 56 alumnos de Tercero
     * 2024 siguen todos en Cuarto 2025 y el `NOT IN` los descarta a todos. Una
     * lista vacía pasa cualquier comprobación.
     *
     * Así que el caso se fabrica: se desmatricula a uno del grupo actual, y
     * entonces tiene que aparecer. Es la situación real de la pantalla —el
     * alumno que estuvo el año pasado y este todavía no ha vuelto—, y es
     * exactamente para quien existe.
     *
     * El borrado es blando y lo deshace la transacción del test.
     */
    public function test_los_del_grado_anterior_sin_matricular_salen_en_su_lista(): void
    {
        [$grupo, $token] = $this->grupoYPersonal();

        $anterior = DB::selectOne('SELECT g.id, g.grado_id, y.year FROM grupos g
            INNER JOIN years y ON y.id = g.year_id AND y.deleted_at IS NULL
            INNER JOIN matriculas m ON m.grupo_id = g.id AND m.deleted_at IS NULL
            WHERE g.deleted_at IS NULL
              AND y.year = (SELECT y2.year - 1 FROM grupos g2
                            INNER JOIN years y2 ON y2.id = g2.year_id WHERE g2.id = ?)
            GROUP BY g.id, g.grado_id, y.year ORDER BY COUNT(m.id) DESC LIMIT 1', [$grupo->id]);

        $this->assertNotNull($anterior, 'El seed necesita un grupo del año anterior.');

        // Uno que esté en los dos grupos: es el que, al quitarle la matrícula de
        // este año, se convierte en candidato.
        $alumno = DB::selectOne('SELECT m2.alumno_id, m2.id AS matricula_actual
            FROM matriculas m1
            INNER JOIN matriculas m2 ON m2.alumno_id = m1.alumno_id AND m2.grupo_id = ?
                AND m2.deleted_at IS NULL
            WHERE m1.grupo_id = ? AND m1.deleted_at IS NULL
            ORDER BY m2.alumno_id LIMIT 1', [$grupo->id, $anterior->id]);

        $this->assertNotNull($alumno, 'Ningún alumno del año anterior sigue en el grupo actual.');

        DB::table('matriculas')->where('id', $alumno->matricula_actual)->update(['deleted_at' => now()]);

        $r = $this->putJson('/api/matriculas/alumnos-con-grado-anterior', [
            'grupo_actual' => ['id' => $grupo->id],
            'grado_ant_id' => $anterior->grado_id,
            'year_ant' => $anterior->year,
        ], ['Authorization' => 'Bearer '.$token]);

        $r->assertStatus(200);

        $sinMatricula = $r->json('AlumnosSinMatricula');

        $this->assertNotEmpty($sinMatricula,
            'El alumno desmatriculado no aparece como candidato del grado anterior.');
        $this->assertContains($alumno->alumno_id, array_column($sinMatricula, 'alumno_id'));

        $this->compararConInstantanea('matriculas-alumnos-sin-matricula',
            $this->formaUnida($sinMatricula));
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
     * Y **no comprobaba de qué alumno se trataba**. `alumno_id` llega en el
     * cuerpo de la petición, así que con un token de alumno se movía la matrícula
     * de cualquier compañero: cambiarle el estado y meterlo en otro grupo. Es el
     * mismo agujero que el IDOR de notas del P0 pero de ESCRITURA, y se comprobó
     * contra el seed antes de cerrarlo: respondía 200 y la fila quedaba escrita.
     *
     * Cerrado el 19 ago 2026 con `boletin.propio:sin-paz-y-salvo`, el middleware
     * que ya hacía esta misma comprobación para los boletines y que entiende el
     * `alumno_id` suelto. Joseth confirmó la regla: **un acudiente solo puede
     * prematricular a sus acudidos.**
     *
     * Sin paz y salvo a propósito: retener el boletín de quien debe es una cosa e
     * impedirle matricularse el año siguiente es otra, y esa nadie la ha pedido.
     */
    public function test_un_alumno_no_puede_prematricular_a_un_companero(): void
    {
        [$yo, $mio, $companero, $grupo] = $this->alumnoYCompanero();

        $this->putJson('/api/matriculas/prematricular', [
            'alumno_id' => $companero->id,
            'grupo_id' => $grupo->id,
            'estado' => 'PREM',
            'anio_sig' => 0,
        ], ['Authorization' => 'Bearer '.$this->tokenDe($yo->username)])
            ->assertStatus(403)
            ->assertJsonPath('message', 'No puedes ver el de otros');

        $this->assertNotSame('PREM',
            DB::selectOne('SELECT estado e FROM matriculas
                WHERE alumno_id = ? AND grupo_id = ? AND deleted_at IS NULL',
                [$companero->id, $grupo->id])->e,
            'El rechazo llegó tarde: la matrícula del compañero ya estaba escrita.');
    }

    /** Y sí puede prematricularse él, que es para lo que la ruta está abierta. */
    public function test_un_alumno_si_puede_prematricularse(): void
    {
        [$yo, $mio, , $grupo] = $this->alumnoYCompanero();

        $r = $this->putJson('/api/matriculas/prematricular', [
            'alumno_id' => $mio->id,
            'grupo_id' => $grupo->id,
            'estado' => 'PREM',
            'anio_sig' => 0,
        ], ['Authorization' => 'Bearer '.$this->tokenDe($yo->username)]);

        $this->assertNotSame(403, $r->getStatusCode(),
            'El guard rechaza a un alumno prematriculándose a sí mismo.');
    }

    /** El acudiente, solo a sus acudidos. */
    public function test_un_acudiente_solo_prematricula_a_sus_acudidos(): void
    {
        $grupo = $this->grupoConAlumnos();

        $suyo = DB::selectOne('SELECT u.username, p.alumno_id FROM users u
            INNER JOIN acudientes ac ON ac.user_id = u.id AND ac.deleted_at IS NULL
            INNER JOIN parentescos p ON p.acudiente_id = ac.id AND p.deleted_at IS NULL
            INNER JOIN matriculas m ON m.alumno_id = p.alumno_id AND m.grupo_id = ?
                AND m.deleted_at IS NULL
            INNER JOIN periodos per ON per.id = u.periodo_id
            WHERE u.tipo = "Acudiente" AND u.is_active = 1 AND u.deleted_at IS NULL
            ORDER BY u.id LIMIT 1', [$grupo->id]);

        $this->assertNotNull($suyo, 'El seed no tiene un acudiente con acudido en el grupo.');

        $ajeno = DB::selectOne('SELECT a.id FROM alumnos a
            INNER JOIN matriculas m ON m.alumno_id = a.id AND m.grupo_id = ? AND m.deleted_at IS NULL
            WHERE a.id <> ? AND a.deleted_at IS NULL
              AND a.id NOT IN (SELECT p.alumno_id FROM parentescos p
                  INNER JOIN acudientes ac ON ac.id = p.acudiente_id
                  INNER JOIN users u ON u.id = ac.user_id AND u.username = ?
                  WHERE p.deleted_at IS NULL)
            ORDER BY a.id LIMIT 1', [$grupo->id, $suyo->alumno_id, $suyo->username]);

        $cab = ['Authorization' => 'Bearer '.$this->tokenDe($suyo->username)];
        $cuerpo = ['grupo_id' => $grupo->id, 'estado' => 'PREM', 'anio_sig' => 0];

        $this->putJson('/api/matriculas/prematricular',
            $cuerpo + ['alumno_id' => $ajeno->id], $cab)
            ->assertStatus(403)
            ->assertJsonPath('message', 'No es acudiente de este alumno. Lo siento.');

        // Y su acudido sí, aunque deba: el paz y salvo no aplica aquí.
        DB::update('UPDATE alumnos SET pazysalvo = 0 WHERE id = ?', [$suyo->alumno_id]);

        $this->assertNotSame(403,
            $this->putJson('/api/matriculas/prematricular',
                $cuerpo + ['alumno_id' => $suyo->alumno_id], $cab)->getStatusCode(),
            'El paz y salvo se está aplicando a la prematrícula, y no debe.');
    }

    /** El usuario alumno del seed, su ficha, un compañero suyo y el grupo. */
    private function alumnoYCompanero(): array
    {
        $grupo = $this->grupoConAlumnos();
        $yo = $this->usuarioDeTipo('Alumno');

        $mio = DB::selectOne('SELECT id FROM alumnos WHERE user_id = ? AND deleted_at IS NULL', [$yo->id]);

        $companero = DB::selectOne('SELECT a.id FROM alumnos a
            INNER JOIN matriculas m ON m.alumno_id = a.id AND m.grupo_id = ? AND m.deleted_at IS NULL
            WHERE a.id <> ? AND a.deleted_at IS NULL ORDER BY a.id LIMIT 1', [$grupo->id, $mio->id]);

        return [$yo, $mio, $companero, $grupo];
    }

    // ---------------------------------------------------------- Prematrículas

    /**
     * Quién llevó el formulario es `matriculas.estado = 'FORM'`, y solo eso.
     *
     * Había dos mecanismos para el mismo dato. El que **no** funcionaba era
     * `PUT api/prematriculas/llevo-formulario`, que escribía en una tabla
     * `llevo_formulario` inexistente —no está en el volcado de la base real ni en
     * la de desarrollo— y empezaba con un `DELETE` contra ella: 500 seguro desde
     * siempre. Se borró con su ruta el 19 ago 2026 en vez de crearle la tabla,
     * porque nadie la leía y el dato ya vive en `matriculas`.
     *
     * El que sí funciona es este: `matriculas/prematricular` con `estado=FORM`,
     * que **inserta y también actualiza**, que es lo que hace el administrador al
     * marcar o desmarcar. Lo lee `AlumnosFormularios` en la pantalla de
     * prematrículas.
     *
     * El test cubre las dos mitades —marcar sobre una matrícula que ya existe y
     * volver a moverla— porque la que estaba rota no probaba ninguna, y porque una
     * ruta borrada solo es segura de borrar si lo que la sustituye está cubierto.
     */
    public function test_marcar_que_llevo_formulario_va_por_el_estado_de_la_matricula(): void
    {
        $grupo = $this->grupoConAlumnos();
        $token = $this->superusuario((int) $grupo->year_id);
        $matricula = $this->unaMatricula((int) $grupo->id);
        $cab = ['Authorization' => 'Bearer '.$token];

        $cuerpo = ['alumno_id' => $matricula->alumno_id, 'grupo_id' => $grupo->id, 'anio_sig' => 0];

        $this->putJson('/api/matriculas/prematricular', $cuerpo + ['estado' => 'FORM'], $cab)
            ->assertStatus(200);

        $this->assertSame('FORM', $this->estadoDe((int) $matricula->id),
            'Marcar «llevó formulario» no dejó la matrícula en FORM.');

        // Y desmarcar es moverla al otro estado de la pantalla, no borrar nada.
        $this->putJson('/api/matriculas/prematricular', $cuerpo + ['estado' => 'PREM'], $cab)
            ->assertStatus(200);

        $this->assertSame('PREM', $this->estadoDe((int) $matricula->id));
    }

    /** Y la ruta que no funcionaba ya no está, para que nadie la reviva sin leer por qué. */
    public function test_la_ruta_de_llevo_formulario_ya_no_existe(): void
    {
        $rutas = [];

        foreach (Route::getRoutes() as $ruta) {
            $rutas[] = $ruta->uri();
        }

        $this->assertNotContains('api/prematriculas/llevo-formulario', $rutas,
            "Volvió `prematriculas/llevo-formulario`. Escribía en una tabla que no existe y\n".
            'el dato va en `matriculas.estado`. Ver PrematriculasController.');
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
