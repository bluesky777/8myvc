<?php

namespace Tests\Contrato;

use App\Models\Year;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

/**
 * **Lo que el catálogo de `/informes` pidió el 20 sep 2026, y que no existía.**
 *
 * Tres informes del catálogo de `myvc_front` —directorio del grupo, citación al
 * acudiente y acta de nivelación— se quedaron sin construir porque el backend no daba
 * el dato. Esto fija las cuatro piezas que lo destraban:
 *
 *     Acudiente::$consulta_alumnos_de_acudiente  ->  `alumno_id`   directorio del grupo
 *     PUT informes/nivelaciones-del-grupo        ->  sección A     acta de nivelación
 *     PUT ausencias/de-alumno                    ->  las faltas    citación
 *     GET informes/membrete                      ->  firmar        constancia de estudio
 *
 * **Lo que cada caso mira es el RESULTADO y no el 200**: qué claves trae la respuesta y
 * qué filas deja fuera, que es lo único que distingue un endpoint que contesta de uno
 * que sirve.
 */
class InformesDelCatalogoTest extends CasoDeContrato
{
    /**
     * **El directorio del grupo se monta invirtiendo `acudientes/datos`, y para eso
     * hace falta la llave del alumno.**
     *
     * Esa respuesta viene del revés —acudientes con sus alumnos colgando— y el informe
     * la necesita al derecho. **Cruzar por `documento` no vale**: en el docker, 68 de
     * los 378 alumnos con matrícula viva de 2025 no tienen documento, o sea 68 renglones
     * del directorio sin teléfono de casa, por un cruce fallido y en silencio.
     */
    #[Test]
    public function los_alumnos_de_un_acudiente_traen_su_id(): void
    {
        [$grupo, $token] = $this->grupoYPersonal();

        $r = $this->putJson('/api/acudientes/datos', ['grupo_actual' => ['id' => $grupo->id]],
            ['Authorization' => 'Bearer '.$token]);

        $r->assertStatus(200);

        $acudientes = $r->json('acudientes');

        $this->assertNotSame([], $acudientes, 'Sin acudientes este test no prueba nada.');

        $vistos = 0;

        // **Los alumnos cuelgan de `subGridOptions.data` y NO de `->alumnos`**, que es
        // donde los pone `AcudientesExport` para el Excel. Son dos consumidores de la
        // misma consulta con dos envoltorios distintos, y este test mira el que usa la
        // pantalla.
        foreach ($acudientes as $acudiente) {
            foreach ($acudiente['subGridOptions']['data'] ?? [] as $alumno) {
                $this->assertArrayHasKey('alumno_id', $alumno,
                    'Sin `alumno_id` el directorio del grupo hay que cruzarlo por documento, y '
                    .'el 18% de los alumnos del docker no tiene documento.');

                $this->assertNotNull($alumno['alumno_id']);
                $vistos++;
            }
        }

        $this->assertGreaterThan(0, $vistos, 'Ningún acudiente del grupo tiene alumnos colgando.');
    }

    /**
     * Prepara una nivelación de indicador en el grupo y devuelve con qué se hizo.
     *
     * **`nota_original` se pone a `0` a propósito**: «está nivelada» es
     * `nota_original !== null`, y un `if ($fila->nota_original)` dejaría fuera justo a
     * quien venía de cero, sin dar ningún error. Si esa comprobación se escribe laxa
     * algún día, este caso se pone rojo.
     *
     * @return array{nota_id: int, alumno_id: int, asignatura_id: int, periodo_id: int, user_id: int}
     */
    private function nivelarUnIndicador(int $grupoId): array
    {
        $fila = DB::selectOne('SELECT n.id as nota_id, n.alumno_id, asi.id as asignatura_id, u.periodo_id
                FROM notas n
                INNER JOIN subunidades s ON s.id=n.subunidad_id and s.deleted_at is null
                INNER JOIN unidades u ON u.id=s.unidad_id and u.deleted_at is null
                INNER JOIN asignaturas asi ON asi.id=u.asignatura_id and asi.deleted_at is null
                WHERE asi.grupo_id=? and n.deleted_at is null and u.alumno_id is null
                ORDER BY n.id LIMIT 1', [$grupoId]);

        $this->assertNotNull($fila, 'El seed no tiene ninguna nota en este grupo: no hay qué nivelar.');

        $quien = DB::selectOne('SELECT id FROM users WHERE deleted_at is null ORDER BY id LIMIT 1');

        DB::update('UPDATE notas SET nota_original=0, nota_nivelacion=70, nota=70,
                nivelada_at=?, nivelada_por=?, nivelacion_obs=? WHERE id=?',
            ['2026-09-18 10:00:00', $quien->id, 'Taller de superación y sustentación', $fila->nota_id]);

        return [
            'nota_id' => (int) $fila->nota_id,
            'alumno_id' => (int) $fila->alumno_id,
            'asignatura_id' => (int) $fila->asignatura_id,
            'periodo_id' => (int) $fila->periodo_id,
            'user_id' => (int) $quien->id,
        ];
    }

    /**
     * El acta trae la fila nivelada entera: qué sacó, qué queda, cuándo, quién y con qué.
     *
     * Hasta hoy esto se sacaba barriendo asignatura por asignatura con `notas/detailed`
     * —la consulta más pesada del proyecto— y en serie.
     */
    #[Test]
    public function el_acta_del_grupo_trae_cada_nivelacion_con_su_quien_y_su_cuando(): void
    {
        [$grupo, $token] = $this->grupoYPersonal();

        $puesta = $this->nivelarUnIndicador((int) $grupo->id);

        $r = $this->putJson('/api/informes/nivelaciones-del-grupo', [
            'grupo_id' => $grupo->id,
            'periodo_id' => $puesta['periodo_id'],
        ], ['Authorization' => 'Bearer '.$token]);

        $r->assertStatus(200);

        $filas = collect($r->json('nivelaciones'))->where('id', $puesta['nota_id']);

        $this->assertCount(1, $filas,
            'La nivelación que se acaba de escribir no salió en el acta del grupo.');

        $fila = $filas->first();

        // **`assertEquals` y no `assertSame`, y el motivo va escrito**: la fila llega por
        // JSON, donde `0.0` se codifica `0` y vuelve como entero. Lo que este caso mira no
        // es el tipo —eso lo fija la ausencia de `CAST`, para que coincida con
        // `notas/detailed`— sino que **el cero no se perdió por el camino**: que la fila
        // esté aquí ya lo demuestra el `assertCount` de arriba, porque un filtro laxo la
        // habría dejado fuera.
        $this->assertNotNull($fila['nota_original'],
            'El cero se perdió: «está nivelada» es `nota_original !== null`, y el 0 cuenta.');
        $this->assertEquals(0, $fila['nota_original']);
        $this->assertEquals(70, $fila['nota_nivelacion']);
        $this->assertSame('2026-09-18 10:00:00', $fila['nivelada_at']);
        $this->assertSame($puesta['user_id'], (int) $fila['nivelada_por']);
        $this->assertSame('Taller de superación y sustentación', $fila['nivelacion_obs']);

        // **El nombre, que es la mitad del acta**: un acta sin responsable no es un acta.
        // Sale de `users.username` y no de `profesores` porque `nivelada_por` guarda un id
        // de `users` y ninguna cuenta administrativa tiene ficha de profesor.
        $esperado = DB::selectOne('SELECT username FROM users WHERE id=?', [$puesta['user_id']]);

        $this->assertSame($esperado->username, $fila['nivelada_por_username'],
            'El acta no dice quién niveló.');

        // Y el estudiante y el indicador, que es lo que se imprime en las dos primeras
        // columnas de la hoja.
        $this->assertArrayHasKey('apellidos', $fila);
        $this->assertArrayHasKey('indicador', $fila);
        $this->assertArrayHasKey('materia', $fila);
    }

    /**
     * **Y sobre todo: lo que NO está nivelado no sale.**
     *
     * Es la mitad del ahorro. Si el filtro no estuviera en la consulta, esto devolvería
     * todas las notas del grupo y el cliente tendría que filtrar —que es exactamente lo
     * que hace hoy con `notas/detailed` y lo que este endpoint viene a quitar—.
     */
    #[Test]
    public function las_notas_sin_nivelar_no_entran_en_el_acta(): void
    {
        [$grupo, $token] = $this->grupoYPersonal();

        $puesta = $this->nivelarUnIndicador((int) $grupo->id);

        $r = $this->putJson('/api/informes/nivelaciones-del-grupo', [
            'grupo_id' => $grupo->id,
            'periodo_id' => $puesta['periodo_id'],
        ], ['Authorization' => 'Bearer '.$token]);

        $r->assertStatus(200);

        $devueltas = collect($r->json('nivelaciones'));

        $this->assertGreaterThan(0, $devueltas->count());

        foreach ($devueltas as $fila) {
            $this->assertNotNull($fila['nota_original'],
                'Salió en el acta una nota que no está nivelada: el filtro `nota_original IS NOT '
                .'NULL` no está haciendo su trabajo y el acta imprimiría el grupo entero.');
        }

        // Y el denominador, que es lo que convierte esto en una medición: en ese periodo
        // hay muchas más notas que niveladas.
        $todas = DB::selectOne('SELECT COUNT(*) as n FROM notas n
                INNER JOIN subunidades s ON s.id=n.subunidad_id and s.deleted_at is null
                INNER JOIN unidades u ON u.id=s.unidad_id and u.deleted_at is null and u.periodo_id=?
                INNER JOIN asignaturas asi ON asi.id=u.asignatura_id and asi.deleted_at is null and asi.grupo_id=?
                WHERE n.deleted_at is null', [$puesta['periodo_id'], $grupo->id]);

        $this->assertGreaterThan($devueltas->count(), (int) $todas->n,
            'Si el acta devuelve tantas filas como notas hay, no está filtrando nada.');
    }

    /** Con `asignatura_id` sale sólo esa, que es para lo que existe el parámetro. */
    #[Test]
    public function el_acta_se_puede_pedir_de_una_sola_asignatura(): void
    {
        [$grupo, $token] = $this->grupoYPersonal();

        $puesta = $this->nivelarUnIndicador((int) $grupo->id);

        $r = $this->putJson('/api/informes/nivelaciones-del-grupo', [
            'grupo_id' => $grupo->id,
            'periodo_id' => $puesta['periodo_id'],
            'asignatura_id' => $puesta['asignatura_id'],
        ], ['Authorization' => 'Bearer '.$token]);

        $r->assertStatus(200);
        $this->assertSame($puesta['asignatura_id'], $r->json('asignatura_id'));

        foreach ($r->json('nivelaciones') as $fila) {
            $this->assertSame($puesta['asignatura_id'], (int) $fila['asignatura_id']);
        }
    }

    /** Un grupo que no existe es 404 y no una lista vacía, que se leen igual. */
    #[Test]
    public function el_acta_de_un_grupo_que_no_existe_es_404(): void
    {
        [$grupo, $token] = $this->grupoYPersonal();

        $this->putJson('/api/informes/nivelaciones-del-grupo', ['grupo_id' => 99999999],
            ['Authorization' => 'Bearer '.$token])->assertStatus(404);

        $this->putJson('/api/informes/nivelaciones-del-grupo', [],
            ['Authorization' => 'Bearer '.$token])->assertStatus(422);
    }

    /**
     * **La citación trae las faltas de UN alumno de UN año, con su etiqueta.**
     *
     * Y no agrega: en este proyecto conviven dos criterios de recuento sobre estos mismos
     * datos —contar filas y sumar `cantidad_ausencia`—, que dan números distintos. Un
     * total aquí sería un tercero.
     */
    #[Test]
    public function la_citacion_trae_las_faltas_del_alumno_de_ese_anio(): void
    {
        [$grupo, $token] = $this->grupoYPersonal();

        $alumno = DB::selectOne('SELECT m.alumno_id FROM matriculas m
            WHERE m.grupo_id=? and m.deleted_at is null and m.estado in ("MATR","ASIS")
            ORDER BY m.alumno_id LIMIT 1', [$grupo->id]);

        $this->assertNotNull($alumno, 'El grupo no tiene alumnos matriculados.');

        $periodo = DB::selectOne('SELECT id FROM periodos WHERE year_id=? and deleted_at is null ORDER BY numero LIMIT 1',
            [$grupo->year_id]);
        $asignatura = DB::selectOne('SELECT id FROM asignaturas WHERE grupo_id=? and deleted_at is null ORDER BY id LIMIT 1',
            [$grupo->id]);

        DB::insert('INSERT INTO ausencias (asignatura_id, alumno_id, periodo_id, cantidad_ausencia, entrada, tipo, fecha_hora, created_at, updated_at)
            VALUES (?,?,?,?,?,?,?,NOW(),NOW())',
            [$asignatura->id, $alumno->alumno_id, $periodo->id, 1, 0, 'ausencia', '2026-09-10 07:00:00']);

        $r = $this->putJson('/api/ausencias/de-alumno', [
            'alumno_id' => $alumno->alumno_id,
            'year_id' => $grupo->year_id,
        ], ['Authorization' => 'Bearer '.$token]);

        $r->assertStatus(200);

        $filas = collect($r->json('ausencias'));

        $this->assertGreaterThan(0, $filas->count(), 'La falta que se acaba de escribir no salió.');

        $puesta = $filas->firstWhere('fecha_hora', '2026-09-10 07:00:00');

        $this->assertNotNull($puesta, 'La falta escrita para este test no está en la respuesta.');
        $this->assertSame('ausencia', $puesta['tipo'],
            '`tipo` es el único discriminador que existe entre una falta y una llegada tarde.');
        $this->assertSame((int) $periodo->id, (int) $puesta['periodo_id']);

        // Todas las filas son de este alumno. Es lo que separa esto de
        // `planillas/ver-ausencias`, que devuelve el colegio entero para citar a uno.
        foreach ($filas as $fila) {
            $this->assertSame((int) $alumno->alumno_id, (int) $fila['alumno_id']);
        }
    }

    /**
     * **Una falta de otro año no entra**, y ésa es la única regla dura de esta consulta:
     * `ausencias` no tiene columna de año, así que el año entra por `periodos`. Sin ese
     * `INNER JOIN` la citación de 2026 imprimiría las faltas de 2024.
     */
    #[Test]
    public function la_citacion_no_mezcla_anios(): void
    {
        [$grupo, $token] = $this->grupoYPersonal();

        $alumno = DB::selectOne('SELECT m.alumno_id FROM matriculas m
            WHERE m.grupo_id=? and m.deleted_at is null and m.estado in ("MATR","ASIS")
            ORDER BY m.alumno_id LIMIT 1', [$grupo->id]);

        $otro = DB::selectOne('SELECT p.id, p.year_id FROM periodos p
            WHERE p.year_id <> ? and p.deleted_at is null ORDER BY p.id LIMIT 1', [$grupo->year_id]);

        $this->assertNotNull($otro, 'El seed sólo tiene un año: este test no puede probar nada.');

        DB::insert('INSERT INTO ausencias (alumno_id, periodo_id, cantidad_ausencia, entrada, tipo, fecha_hora, created_at, updated_at)
            VALUES (?,?,?,?,?,?,NOW(),NOW())',
            [$alumno->alumno_id, $otro->id, 1, 0, 'ausencia', '2024-03-04 07:00:00']);

        $r = $this->putJson('/api/ausencias/de-alumno', [
            'alumno_id' => $alumno->alumno_id,
            'year_id' => $grupo->year_id,
        ], ['Authorization' => 'Bearer '.$token]);

        $r->assertStatus(200);

        foreach ($r->json('ausencias') as $fila) {
            $this->assertNotSame('2024-03-04 07:00:00', $fila['fecha_hora'],
                'Salió una falta de otro año: el `INNER JOIN` con `periodos` no está atando el año.');
        }

        // Y al pedir el otro año sí sale, que es lo que demuestra que la fila existe y que
        // lo que la dejó fuera fue el filtro y no un error al escribirla.
        $r2 = $this->putJson('/api/ausencias/de-alumno', [
            'alumno_id' => $alumno->alumno_id,
            'year_id' => $otro->year_id,
        ], ['Authorization' => 'Bearer '.$token]);

        $r2->assertStatus(200);

        $this->assertNotNull(collect($r2->json('ausencias'))->firstWhere('fecha_hora', '2024-03-04 07:00:00'),
            'La fila no aparece en su propio año: el control de este test no controla nada.');
    }

    /** Un alumno que no existe es 404. */
    #[Test]
    public function la_citacion_de_un_alumno_que_no_existe_es_404(): void
    {
        [, $token] = $this->grupoYPersonal();

        $this->putJson('/api/ausencias/de-alumno', ['alumno_id' => 99999999],
            ['Authorization' => 'Bearer '.$token])->assertStatus(404);

        $this->putJson('/api/ausencias/de-alumno', [],
            ['Authorization' => 'Bearer '.$token])->assertStatus(422);
    }

    /**
     * **El membrete trae lo que hace falta para firmar un papel**, que hasta hoy se le
     * pedía a `GET piars-config` —la configuración de la ruta de inclusión—.
     */
    #[Test]
    public function el_membrete_trae_al_rector_y_los_titulos(): void
    {
        [, $token] = $this->grupoYPersonal();

        $r = $this->getJson('/api/informes/membrete', ['Authorization' => 'Bearer '.$token]);

        $r->assertStatus(200);

        foreach (['nombre_colegio', 'ciudad', 'resolucion', 'codigo_dane', 'titulo_constancia_estudio'] as $clave) {
            $this->assertArrayHasKey($clave, $r->json(),
                "Sin `{$clave}` la constancia no se puede encabezar ni firmar.");
        }

        // Y es lo mismo que daba el rodeo por `piars-config`, que es lo que hace que
        // cambiar de una a otra no mueva ni un campo del papel.
        $viejo = $this->getJson('/api/piars-config', ['Authorization' => 'Bearer '.$token]);

        $viejo->assertStatus(200);

        $this->assertSame(
            $viejo->json('year.nombre_colegio'),
            $r->json('nombre_colegio'),
            'El membrete y el rodeo por `piars-config` ya no dicen lo mismo.'
        );
    }

    /**
     * **Las dos ramas de `Year::datos()` devuelven las mismas claves**, y por eso caben
     * en una sola ruta.
     *
     * Si no fuera así, una respuesta que cambia de forma según un parámetro opcional es
     * la que el cliente tipa una vez y rompe la otra.
     */
    #[Test]
    public function el_membrete_de_un_anio_cerrado_tiene_la_misma_forma(): void
    {
        [$grupo, $token] = $this->grupoYPersonal();

        $actual = $this->getJson('/api/informes/membrete', ['Authorization' => 'Bearer '.$token]);
        $delAnio = $this->getJson('/api/informes/membrete?year_id='.$grupo->year_id,
            ['Authorization' => 'Bearer '.$token]);

        $actual->assertStatus(200);
        $delAnio->assertStatus(200);

        $a = array_keys($actual->json());
        $b = array_keys($delAnio->json());
        sort($a);
        sort($b);

        $this->assertSame($a, $b,
            'Las dos ramas de `Year::datos()` ya no devuelven las mismas claves: entonces esto '
            .'tienen que ser dos rutas, no una con un parámetro opcional.');
    }

    /** Un año que no existe es 404 y no el 500 de `DB::select(...)[0]`. */
    #[Test]
    public function el_membrete_de_un_anio_que_no_existe_es_404(): void
    {
        [, $token] = $this->grupoYPersonal();

        $this->getJson('/api/informes/membrete?year_id=99999999',
            ['Authorization' => 'Bearer '.$token])->assertStatus(404);
    }

    /**
     * **El título de la constancia es del AÑO**, que es la razón entera de que sea una
     * columna de `years` y no un literal en la plantilla: de los años cerrados se siguen
     * pidiendo papeles.
     */
    #[Test]
    public function el_titulo_de_la_constancia_lo_pone_el_anio(): void
    {
        [$grupo, $token] = $this->grupoYPersonal();

        DB::table('years')->where('id', $grupo->year_id)
            ->update(['titulo_constancia_estudio' => 'CONSTANCIA DE ESTUDIO Y CONVIVENCIA']);

        $r = $this->getJson('/api/informes/membrete?year_id='.$grupo->year_id,
            ['Authorization' => 'Bearer '.$token]);

        $r->assertStatus(200);

        $this->assertSame('CONSTANCIA DE ESTUDIO Y CONVIVENCIA', $r->json('titulo_constancia_estudio'));

        // Y el defecto, para que el colegio que no lo toque imprima lo que imprimía.
        $this->assertSame('CONSTANCIA DE ESTUDIO', Year::TITULOS_POR_DEFECTO['titulo_constancia_estudio']);
    }
}
