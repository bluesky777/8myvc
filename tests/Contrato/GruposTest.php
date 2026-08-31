<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Los grupos: la unidad sobre la que gira todo lo demás.
 *
 * Cierra el bloque P1. El grupo es de lo que cuelgan asignaturas, unidades,
 * subunidades y notas —seis saltos de `ON DELETE CASCADE`— así que aquí importan
 * dos cosas distintas: la forma de las siete lecturas que alimentan los
 * desplegables de media aplicación, y que las tres formas de borrarlo se sigan
 * comportando como se espera.
 */
class GruposTest extends CasoDeContrato
{
    /**
     * Las siete lecturas del controlador, con la clave que no puede venir vacía.
     *
     * Son consultas casi iguales que se diferencian en el filtro y en dos
     * columnas, y cada pantalla usa la suya. Ponerlas en un proveedor es lo que
     * hace visible que `getIndex`, `getCantAlumnos` y `putConCantidadAlumnos`
     * devuelven listas de grupos con columnas distintas: la primera no trae
     * `cant_alumnos`, y la tercera añade el desglose por sexo y la foto del
     * titular. Las dos que cuentan cuentan lo mismo desde el 31 ago 2026.
     */
    public static function lecturas(): array
    {
        return [
            'index' => ['GET', 'grupos', 'lista'],
            'cant-alumnos' => ['GET', 'grupos/cant-alumnos', 'lista'],
            'con-cantidad-alumnos' => ['PUT', 'grupos/con-cantidad-alumnos', 'objeto'],
            'con-paises-tipos' => ['GET', 'grupos/con-paises-tipos', 'objeto'],
            'con-disciplina' => ['PUT', 'grupos/con-disciplina', 'objeto'],
            'alumnos-con-datos' => ['PUT', 'grupos/alumnos-con-datos', 'objeto'],
        ];
    }

    /**
     * Lo que el seed deja sin cubrir aquí, y conviene saberlo antes de fiarse:
     * `con-disciplina.descripciones_typeahead` sale vacía siempre. Lee de
     * `dis_procesos`, que es una de las dos tablas que el generador de seed
     * omite a propósito por ser el dato más sensible del sistema.
     */
    #[DataProvider('lecturas')]
    public function test_la_forma_de_cada_lectura_de_grupos(string $verbo, string $ruta, string $tipo): void
    {
        [$grupo, $token] = $this->grupoYPersonal();

        // `alumnos-con-datos` pide el grupo como objeto y no como id suelto, igual
        // que las listas de matrículas. No es un capricho del test: con `grupo_id`
        // el método hace `return;` y responde con el cuerpo vacío, que no es JSON.
        $r = $this->json($verbo, "/api/{$ruta}", [
            'grupo_id' => $grupo->id,
            'grupo_actual' => ['id' => $grupo->id],
        ], ['Authorization' => 'Bearer '.$token]);

        $r->assertStatus(200);

        $cuerpo = $r->json();

        $this->assertNotEmpty($cuerpo, "{$verbo} {$ruta} salió vacío.");

        if ($tipo === 'lista') {
            $this->assertSame(range(0, count($cuerpo) - 1), array_keys($cuerpo),
                "{$ruta} dejó de devolver una lista.");
        }

        $this->compararConInstantanea('grupos-'.str_replace('/', '-', $ruta),
            $this->formaUnida($cuerpo));
    }

    /**
     * `GET api/grupos/next-year`: los grupos del año siguiente al de quien pide.
     *
     * **Se pregunta desde el año anterior, y no es un rodeo.** Con el año actual
     * de siempre —2025— la lista sale vacía: 2026 existe en producción con sus
     * trece grupos pero está BORRADO, y la consulta filtra `y.deleted_at is
     * null`. Retrasando el año actual a 2024, el siguiente es 2025, que sí está
     * vivo, y la consulta se ejecuta de verdad.
     *
     * **No basta con pedir un token de alguien de 2024.** `Services\Login`
     * reescribe `users.periodo_id` al periodo actual en cada inicio de sesión, y
     * el periodo actual sale de `years.actual`: entre para quien entre, acaba en
     * el año que marca esa columna. Por eso lo que se mueve es el año actual, y
     * antes de pedir el token. Lo deshace la transacción del test.
     *
     * Hasta el 20 ago 2026 este caso vivía en el proveedor de arriba con la
     * etiqueta `lista-vacia` y un snapshot que decía `[]`. Pasaba siempre, y no
     * comprobaba nada: es la pantalla con la que el colegio arma el año que
     * viene.
     */
    public function test_los_grupos_del_year_siguiente(): void
    {
        $grupo = $this->grupoConAlumnos();

        $anterior = DB::selectOne('SELECT anterior.id FROM years actual
            INNER JOIN years anterior ON anterior.year = actual.year - 1 AND anterior.deleted_at IS NULL
            WHERE actual.id = ?', [$grupo->year_id]);

        $this->assertNotNull($anterior,
            'El seed necesita el año anterior al del grupo para comprobar next-year.');

        DB::table('years')->update(['actual' => 0]);
        DB::table('years')->where('id', $anterior->id)->update(['actual' => 1]);

        $r = $this->getJson('/api/grupos/next-year',
            ['Authorization' => 'Bearer '.$this->tokenDelPersonalDe((int) $anterior->id)]);

        $r->assertStatus(200);

        $cuerpo = $r->json();

        $this->assertNotEmpty($cuerpo, 'next-year salió vacío preguntando desde el año anterior.');
        $this->assertContains($grupo->id, array_column($cuerpo, 'id'),
            'El grupo del seed no aparece entre los del año siguiente.');

        $this->compararConInstantanea('grupos-grupos-next-year', $this->formaUnida($cuerpo));
    }

    /**
     * Las dos cuentas de alumnos cuentan lo mismo, y un PREM no suma en ninguna.
     *
     * Hasta el 31 ago 2026 no era así: `con-cantidad-alumnos` sumaba también los
     * prematriculados y `cant-alumnos` no, y este test fijaba esa diferencia como
     * deliberada. La portada de `app2` junta las dos respuestas —la columna
     * «Alumnos» de una con «Hom» y «Muj» de la otra—, así que en lal la tabla
     * decía 199 matriculados y 126+95=221 por sexo. Dos números con el mismo
     * nombre en la misma fila no es una diferencia que nadie pueda sostener
     * leyendo la pantalla: se unificaron los tres contadores a ASIS y MATR.
     *
     * Se comprueban las dos direcciones, porque una sola no basta: que el PREM no
     * entre en ninguna de las dos cuentas, y que dentro de la segunda el
     * desglose por sexo sume exactamente su propio total —que es la resta que
     * hizo visible el fallo—.
     */
    public function test_las_dos_cuentas_de_alumnos_cuentan_lo_mismo(): void
    {
        [$grupo, $token] = $this->grupoYPersonal();
        $cab = ['Authorization' => 'Bearer '.$token];

        $antes = collect($this->getJson('/api/grupos/cant-alumnos', $cab)->json())
            ->firstWhere('id', $grupo->id)['cant_alumnos'];

        $this->assertGreaterThan(0, $antes, 'El grupo del seed necesita alumnos para este test.');

        DB::update('UPDATE matriculas SET estado = "PREM"
            WHERE id = (SELECT id FROM (SELECT MIN(m.id) id FROM matriculas m
                WHERE m.grupo_id = ? AND m.deleted_at IS NULL AND m.estado IN ("MATR","ASIS")) x)',
            [$grupo->id]);

        $sinPrem = collect($this->getJson('/api/grupos/cant-alumnos', $cab)->json())
            ->firstWhere('id', $grupo->id)['cant_alumnos'];

        // Esta devuelve {grupos, periodos_total}, no una lista: es la única de las
        // tres que además trae el movimiento por periodo de cada grupo.
        $fila = collect($this->putJson('/api/grupos/con-cantidad-alumnos', [], $cab)->json('grupos'))
            ->firstWhere('id', $grupo->id);

        $this->assertSame($antes - 1, $sinPrem,
            'Prematricular a un alumno tiene que restarlo de cant-alumnos.');
        $this->assertSame($sinPrem, $fila['cant_alumnos'],
            'Las dos cuentas de alumnos volvieron a contar poblaciones distintas.');
        $this->assertSame($fila['cant_alumnos'], $fila['cant_hombres'] + $fila['cant_mujeres'],
            'El desglose por sexo no suma el total del propio grupo.');
    }

    // ------------------------------------------------------------- El listado

    /**
     * El listado del grupo trae la dirección, que hasta hoy nunca trajo.
     *
     * La consulta hacía `(a.direccion + " - " + a.barrio) as direccion`, y en
     * MySQL el `+` es suma aritmética, no concatenación: las dos cadenas se
     * convertían a número, así que salía **0** cuando las dos tenían valor y
     * **null** si a alguna le faltaba. La pantalla llevaba imprimiendo eso desde
     * que se escribió la consulta.
     *
     * Arreglado el 19 ago 2026 con `CONCAT_WS`, no con `CONCAT`: `CONCAT`
     * devuelve null si un argumento es null, y un alumno sin barrio habría
     * perdido también la dirección. El `NULLIF` es para que la cadena vacía
     * cuente como ausente y no deje un « - » colgando.
     *
     * El test comprueba las tres combinaciones porque son las tres que hay en
     * datos reales, y las tres se comportan distinto.
     */
    public function test_el_listado_del_grupo_trae_la_direccion(): void
    {
        [$grupo, $token] = $this->grupoYPersonal();

        $alumnos = DB::select('SELECT a.id FROM alumnos a
            INNER JOIN matriculas m ON m.alumno_id = a.id AND m.grupo_id = ?
                AND m.deleted_at IS NULL AND m.estado IN ("PREM","MATR","ASIS")
            WHERE a.deleted_at IS NULL ORDER BY a.id LIMIT 3', [$grupo->id]);

        $this->assertCount(3, $alumnos, 'Hacen falta tres alumnos en el grupo para este test.');

        $casos = [
            [$alumnos[0]->id, 'Calle 5 #12-30', 'El Prado', 'Calle 5 #12-30 - El Prado'],
            [$alumnos[1]->id, 'Carrera 9 #4-11', null, 'Carrera 9 #4-11'],
            [$alumnos[2]->id, null, 'La Ceiba', 'La Ceiba'],
        ];

        foreach ($casos as [$id, $direccion, $barrio]) {
            DB::update('UPDATE alumnos SET direccion = ?, barrio = ? WHERE id = ?',
                [$direccion, $barrio, $id]);
        }

        $r = $this->getJson("/api/grupos/listado/{$grupo->id}", ['Authorization' => 'Bearer '.$token]);

        $r->assertStatus(200);

        $porAlumno = collect($r->json())->keyBy('alumno_id');

        foreach ($casos as [$id, , , $esperado]) {
            $this->assertSame($esperado, $porAlumno[$id]['direccion'],
                "La dirección del alumno {$id} no se compuso como debía.");
        }

        $this->compararConInstantanea('grupos-listado', $this->formaUnida($r->json()));
    }

    // ------------------------------------------------------------- El CRUD

    public function test_crear_actualizar_y_mostrar_un_grupo(): void
    {
        [$grupo, $token] = $this->grupoYPersonal();
        $cab = ['Authorization' => 'Bearer '.$token];

        $base = DB::selectOne('SELECT grado_id, titular_id FROM grupos WHERE id = ?', [$grupo->id]);

        $r = $this->postJson('/api/grupos/store', [
            'nombre' => 'Grupo de prueba',
            'abrev' => 'GP',
            'grado' => ['id' => $base->grado_id],
            'titular_id' => $base->titular_id,
            'valormatricula' => 100000,
            'valorpension' => 50000,
            'orden' => 99,
            'caritas' => 0,
        ], $cab);

        $r->assertStatus(201);

        $id = $r->json('id');

        $this->assertSame((int) $grupo->year_id, (int) $r->json('year_id'),
            'El grupo nuevo no se creó en el año del usuario.');

        $this->putJson('/api/grupos/update', [
            'id' => $id,
            'nombre' => 'Grupo renombrado',
            'abrev' => 'GR',
            'grado_id' => $base->grado_id,
            'valormatricula' => 200000,
            'valorpension' => 60000,
            'orden' => 98,
            'cupo' => 30,
        ], $cab)->assertStatus(200);

        $r = $this->getJson("/api/grupos/show/{$id}", $cab);

        $r->assertStatus(200)
            ->assertJsonPath('nombre', 'Grupo renombrado')
            ->assertJsonPath('cupo', 30);

        // show() adjunta el titular y el grado, que no vienen de la tabla grupos.
        $this->assertArrayHasKey('grado', $r->json());
        $this->assertSame($base->grado_id, $r->json('grado.id'));

        // Y el titular sigue puesto, que hasta el 23 ago 2026 no era así. El
        // `update` de arriba **no manda `titular_id`** —a propósito, es media
        // pantalla— y `putUpdate` lo escribía como null: este mismo test creaba el
        // grupo CON titular y lo comprobaba sin él dos líneas después. El snapshot
        // guardaba `titular => null`, o sea que **el contrato tenía dentro el
        // fallo**, que es lo que pasa cuando se fija lo que hay sin preguntarse por
        // qué es eso. Ver §153 y `CamposQueSeVacianTest`.
        $this->assertSame((int) $base->titular_id, (int) $r->json('titular_id'),
            'Editar un grupo sin mandar el titular volvió a quitárselo — §153.');

        $this->compararConInstantanea('grupos-show', $this->formaUnida($r->json()));
    }

    /** Un grupo sin `grado` no se crea: responde 422 en vez de dejar la fila a medias. */
    public function test_crear_un_grupo_sin_grado_es_422(): void
    {
        [, $token] = $this->grupoYPersonal();

        $this->postJson('/api/grupos/store', ['nombre' => 'Sin grado', 'abrev' => 'SG'],
            ['Authorization' => 'Bearer '.$token])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Datos incorrectos');
    }

    /**
     * Papelera: borrar, verlo en la papelera, restaurar.
     *
     * `destroy` es reversible y `forcedelete` no lo es en absoluto: cascadea por
     * clave foránea a 27 tablas y hasta seis saltos —grupos > asignaturas >
     * unidades > subunidades > notas—, o sea que se lleva las notas de todo el
     * mundo en las asignaturas del grupo. Aquí solo se prueba el camino
     * reversible; el destructivo, en el test de abajo y contra un grupo recién
     * creado.
     */
    public function test_borrar_y_restaurar_un_grupo(): void
    {
        [$grupo, $token] = $this->grupoYPersonal();
        $cab = ['Authorization' => 'Bearer '.$token];

        $this->deleteJson("/api/grupos/destroy/{$grupo->id}", [], $cab)->assertStatus(200);

        $this->assertNotNull(
            DB::selectOne('SELECT deleted_at FROM grupos WHERE id = ?', [$grupo->id])->deleted_at,
            'destroy no mandó el grupo a la papelera.');

        $enPapelera = array_column($this->getJson('/api/grupos/trashed', $cab)->json(), 'id');

        $this->assertContains((int) $grupo->id, $enPapelera,
            'El grupo borrado no aparece en la papelera.');

        $this->putJson("/api/grupos/restore/{$grupo->id}", [], $cab)->assertStatus(200);

        $this->assertNull(
            DB::selectOne('SELECT deleted_at FROM grupos WHERE id = ?', [$grupo->id])->deleted_at,
            'restore no sacó el grupo de la papelera.');
    }

    /**
     * `forcedelete` exige administrativo, y solo funciona desde la papelera.
     *
     * Era el endpoint más destructivo del sistema y el único de la papelera sin
     * ninguna comprobación: bastaba un token válido, y el de cualquier alumno
     * servía. El guard se puso en la Fase 6; esto es lo que impide que se caiga
     * otra vez, y de paso fija que un grupo que no está en la papelera da 404 en
     * vez de borrarse.
     *
     * Se hace sobre un grupo creado en el propio test —sin asignaturas ni notas
     * colgando— porque el cascade real se llevaría por delante medio seed y el
     * `assert` siguiente no probaría nada.
     */
    public function test_forcedelete_pide_administrativo_y_solo_desde_la_papelera(): void
    {
        [$grupo, $token] = $this->grupoYPersonal();
        $cab = ['Authorization' => 'Bearer '.$token];

        $base = DB::selectOne('SELECT grado_id FROM grupos WHERE id = ?', [$grupo->id]);

        $id = $this->postJson('/api/grupos/store', [
            'nombre' => 'Grupo desechable', 'abrev' => 'GD',
            'grado' => ['id' => $base->grado_id], 'orden' => 99,
            'valormatricula' => 0, 'valorpension' => 0, 'caritas' => 0,
        ], $cab)->json('id');

        $alumno = ['Authorization' => 'Bearer '.$this->tokenDe($this->usuarioDeTipo('Alumno')->username)];

        $this->deleteJson("/api/grupos/forcedelete/{$id}", [], $alumno)->assertStatus(403);

        $this->assertNotNull(DB::selectOne('SELECT id FROM grupos WHERE id = ?', [$id]),
            'Un alumno acaba de borrar un grupo definitivamente.');

        // Vivo, no en la papelera: onlyTrashed no lo encuentra.
        $this->deleteJson("/api/grupos/forcedelete/{$id}", [], $cab)->assertStatus(404);

        $this->deleteJson("/api/grupos/destroy/{$id}", [], $cab)->assertStatus(200);
        $this->deleteJson("/api/grupos/forcedelete/{$id}", [], $cab)->assertStatus(200);

        $this->assertNull(DB::selectOne('SELECT id FROM grupos WHERE id = ?', [$id]),
            'forcedelete dejó la fila.');
    }

    // ----------------------------------------------------------- Promovidos

    /**
     * El cálculo de promoción del grupo entero.
     *
     * `PromovidosController` es quien fija el dominio de `matriculas.promovido`
     * —«Automático», «Promovido (calculado|manual)», «No promovido (…)»,
     * «Promoción pendiente (…)»— del que depende toda la clasificación del acta
     * de evaluación. Aquí no se comprueba el criterio, que es cálculo de notas y
     * el §5 lo declara intocable: se comprueba la forma y que los valores que
     * salen sean del dominio que el acta sabe leer.
     */
    public function test_la_forma_del_calculo_de_promovidos(): void
    {
        [$grupo, $token] = $this->grupoYPersonal();

        $r = $this->putJson('/api/promovidos/calcular-grupo', ['grupo_id' => $grupo->id],
            ['Authorization' => 'Bearer '.$token]);

        $r->assertStatus(200);

        $this->compararConInstantanea('promovidos-calcular-grupo', $this->formaUnida($r->json()));
    }
}
