<?php

namespace Tests\Contrato;

use App\Services\Auditoria;
use Illuminate\Support\Facades\DB;

/**
 * Los diez escritores de `bitacoras`, ahora también en `auditoria`.
 *
 * Es la primera mitad de la fase 4 de
 * [18-auditoria.md](../../docs/migracion/18-auditoria.md), y el criterio de estos
 * casos es el de siempre: **se mira la fila que queda, no el 200**. Un escritor
 * de auditoría que conteste «bien» sin haber escrito nada es exactamente el
 * instrumento que tranquiliza sin medir, y aquí eso se nota más que en ningún
 * otro sitio porque `Auditoria::guardar()` **se traga cualquier excepción a
 * propósito** (18 §4.3): un error de tipos, una columna que no existe o una
 * entidad mal escrita **no rompen la petición** — devuelven `null`, dejan la
 * línea en el log y la respuesta sale 200 igual de contenta. Un caso que
 * comprobara el código de respuesta pasaría con el rastro entero perdido.
 *
 * Todos van por el viaje de ida y vuelta —se llama a la API de verdad— y no
 * invocando el servicio a mano. Es la única forma de comprobar lo que de verdad
 * se quiere saber: que la llamada está **dentro** del método, después de la
 * escritura y después de la guarda (18 §4.6).
 *
 * Y `auditoria` llega **vacía en el seed**, igual que `bitacoras`: un caso que
 * buscara una línea ya registrada pasaría sin comprobar nada. Por eso cada uno
 * cuenta lo que había antes o filtra por la fila que acaba de tocar.
 */
class AuditoriaDeLosDiezEscritoresTest extends CasoDeContrato
{
    private function tokenDeSuperusuario(): string
    {
        $super = DB::selectOne('SELECT username FROM users
            WHERE is_superuser = 1 AND is_active = 1 AND deleted_at IS NULL ORDER BY id LIMIT 1');

        return $this->tokenDe($super->username);
    }

    /** Las líneas de una entidad, más recientes primero. */
    private function lineasDe(string $entidad, ?int $id = null): array
    {
        $sql = 'SELECT * FROM auditoria WHERE entidad = ?';
        $par = [$entidad];

        if ($id !== null) {
            $sql .= ' AND entidad_id = ?';
            $par[] = $id;
        }

        return DB::select($sql.' ORDER BY id DESC', $par);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Las notas
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Editar una nota deja una línea con **los dos valores**, el alumno y el
     * periodo de la fila.
     *
     * El periodo es la parte que no se ve: `putUpdate` tenía calculado
     * `$bit_per = $user->periodo_id` —el del profesor— y no lo usaba. La línea
     * guarda el de la NOTA, que sale de `PeriodoDeLaFila::deNota()`, y son cosas
     * distintas en cuanto el profesor está mirando otro periodo.
     */
    public function test_editar_una_nota_deja_una_linea_con_los_dos_valores(): void
    {
        $token = $this->tokenDeSuperusuario();

        $nota = DB::selectOne('SELECT id, nota, alumno_id FROM notas WHERE deleted_at IS NULL ORDER BY id LIMIT 1');
        $this->assertNotNull($nota, 'El seed necesita una nota.');

        $vieja = (float) $nota->nota;
        $nueva = $vieja == 4.2 ? 3.1 : 4.2;

        $this->withToken($token)->putJson('/api/notas/update/'.$nota->id, ['nota' => $nueva])
            ->assertStatus(200);

        $lineas = $this->lineasDe('nota', (int) $nota->id);

        $this->assertCount(1, $lineas, 'Editar la nota no dejó exactamente una línea de auditoría.');

        $linea = $lineas[0];

        $this->assertSame(Auditoria::EDITAR, $linea->accion);
        $this->assertEquals($vieja, json_decode((string) $linea->valor_anterior));
        $this->assertEquals($nueva, json_decode((string) $linea->valor_nuevo));
        $this->assertEquals($nota->alumno_id, $linea->alumno_id, 'La línea no dice de qué alumno era la nota.');
        $this->assertNotNull($linea->actor_user_id, 'La línea no dice quién la cambió.');
        $this->assertSame('PUT notas/update/{id}', $linea->ruta,
            'La ruta se guarda con su patrón, no con la URL resuelta (18 §5).');

        $periodoDeLaNota = DB::selectOne(
            'SELECT u.periodo_id FROM notas n
               INNER JOIN subunidades s ON s.id = n.subunidad_id
               INNER JOIN unidades u ON u.id = s.unidad_id
              WHERE n.id = ?', [$nota->id]);

        $this->assertEquals($periodoDeLaNota->periodo_id, $linea->periodo_id,
            'La línea guarda el periodo del profesor y no el de la nota.');
    }

    /**
     * **El rastro viejo sigue escribiéndose.**
     *
     * Esto no es una redundancia con el caso de arriba: es la mitad del encargo
     * que se puede romper sin que ningún otro test se entere. `bitacoras` la
     * siguen leyendo dos pantallas del front (`historiales/nota-detalle` y su
     * gemela de definitivas) en los dieciséis colegios, y `app/` es **copia real
     * en cada uno**, así que retirar el `INSERT` viejo el día que se funda esto
     * dejaría esas pantallas vacías durante todo el despliegue. La retirada va
     * detrás del front (JUB-1), no delante.
     */
    public function test_el_rastro_viejo_sigue_escribiendose_al_lado_del_nuevo(): void
    {
        $token = $this->tokenDeSuperusuario();

        $nota = DB::selectOne('SELECT id, nota FROM notas WHERE deleted_at IS NULL ORDER BY id LIMIT 1');

        $antes = DB::table('bitacoras')->count();

        $this->withToken($token)->putJson('/api/notas/update/'.$nota->id,
            ['nota' => (float) $nota->nota == 4.2 ? 3.1 : 4.2])->assertStatus(200);

        $this->assertSame($antes + 1, DB::table('bitacoras')->count(),
            'El INSERT viejo de `bitacoras` dejó de escribirse. Añadir el rastro nuevo sí; '.
            'quitar el viejo va detrás del front y del despliegue.');

        $this->assertCount(1, $this->lineasDe('nota', (int) $nota->id),
            'Y el nuevo tampoco está: no se escribió ninguno de los dos.');
    }

    /**
     * El lote deja **una línea por nota**, y dentro de su transacción.
     *
     * Una por nota y no una por lote: el lote es un detalle del transporte —el
     * front manda una petición por rejilla— y la pregunta que la tabla contesta
     * es «quién tocó ESTA nota». Agregarlas por petición haría que buscar una
     * nota concreta no encontrara nada.
     */
    public function test_el_lote_deja_una_linea_por_cada_nota(): void
    {
        $token = $this->tokenDeSuperusuario();

        $notas = DB::select('SELECT n.id, n.nota FROM notas n
            INNER JOIN subunidades s ON s.id = n.subunidad_id AND s.deleted_at IS NULL
            INNER JOIN unidades u ON u.id = s.unidad_id AND u.deleted_at IS NULL
            WHERE n.deleted_at IS NULL ORDER BY n.id LIMIT 3');

        $this->assertCount(3, $notas, 'El seed necesita tres notas con su unidad viva.');

        $cuerpo = [];
        foreach ($notas as $n) {
            $cuerpo[] = ['id' => $n->id, 'nota' => (float) $n->nota == 4.0 ? 3.0 : 4.0];
        }

        $r = $this->withToken($token)->putJson('/api/notas/lote', ['notas' => $cuerpo]);
        $r->assertStatus(200);

        $guardadas = (int) $r->json('guardadas');
        $this->assertGreaterThan(0, $guardadas, 'El lote no guardó ninguna nota: el caso no mide nada.');

        $ids = array_map(fn ($n) => (int) $n->id, $notas);
        $lineas = DB::select('SELECT entidad_id FROM auditoria WHERE entidad = "nota" AND entidad_id IN ('
            .implode(',', $ids).')');

        $this->assertCount($guardadas, $lineas,
            'El lote guardó '.$guardadas.' notas y dejó '.count($lineas).' líneas de auditoría.');
    }

    /**
     * Borrar una nota deja una línea **con el valor que se fue**, y es la que
     * más falta hacía: el borrado es **físico**, así que después del `DELETE` no
     * queda fila, ni `deleted_at`, ni bitácora —`deleteDestroy` nunca escribió en
     * `bitacoras`—. Era el único escritor de `notas` sin rastro en ninguna de las
     * dos tablas (`tools/escrituras-sin-auditoria.php`, 2 sep 2026), y la
     * pregunta «¿quién borró la nota de este alumno?» no tenía respuesta en los
     * quince colegios.
     *
     * Es el A1 del plan de nivelaciones ([22](../../docs/migracion/22-nivelaciones.md)):
     * sin esto, borrar una nota nivelada se llevaría la nivelación y su acta sin
     * que nadie pudiera reconstruir ninguna de las dos.
     */
    public function test_borrar_una_nota_deja_una_linea_con_el_valor_que_se_fue(): void
    {
        $token = $this->tokenDeSuperusuario();

        $nota = DB::selectOne('SELECT n.id, n.nota, n.alumno_id, u.asignatura_id, u.periodo_id FROM notas n
            INNER JOIN subunidades s ON s.id = n.subunidad_id AND s.deleted_at IS NULL
            INNER JOIN unidades u ON u.id = s.unidad_id AND u.deleted_at IS NULL
            WHERE n.deleted_at IS NULL ORDER BY n.id LIMIT 1');

        $this->assertNotNull($nota, 'El seed necesita una nota con su unidad viva.');

        $this->withToken($token)->deleteJson('/api/notas/destroy/'.$nota->id)->assertStatus(200);

        $this->assertNull(DB::selectOne('SELECT id FROM notas WHERE id = ?', [$nota->id]),
            'El borrado es físico: la fila tiene que haberse ido.');

        $lineas = $this->lineasDe('nota', (int) $nota->id);

        $this->assertCount(1, $lineas, 'Borrar la nota no dejó exactamente una línea de auditoría.');

        $linea = $lineas[0];

        $this->assertSame(Auditoria::BORRAR, $linea->accion);
        $this->assertEquals((float) $nota->nota, json_decode((string) $linea->valor_anterior),
            'La línea no dice qué nota había: después del DELETE es el único sitio donde queda.');
        $this->assertNull($linea->valor_nuevo, 'Un borrado no tiene valor nuevo.');
        $this->assertEquals($nota->alumno_id, $linea->alumno_id, 'La línea no dice de qué alumno era la nota.');
        $this->assertEquals($nota->asignatura_id, $linea->asignatura_id);
        $this->assertEquals($nota->periodo_id, $linea->periodo_id,
            'La línea guarda el periodo del profesor y no el de la nota.');
        $this->assertNotNull($linea->actor_user_id, 'La línea no dice quién la borró.');
        $this->assertSame('DELETE notas/destroy/{id}', $linea->ruta);
    }

    /**
     * Y borrar lo que no existe **no deja línea**: un id sin fila no es un
     * borrado, y una línea de auditoría sobre nada es una mentira en una tabla
     * que se lee años después. El 200 se conserva tal cual —es el legacy y lo
     * leen cuatro clientes—; lo que se comprueba es que no se inventó un rastro.
     */
    public function test_borrar_una_nota_que_no_existe_no_inventa_una_linea(): void
    {
        $token = $this->tokenDeSuperusuario();

        $inexistente = (int) DB::selectOne('SELECT COALESCE(MAX(id), 0) + 1000 AS id FROM notas')->id;

        $antes = DB::table('auditoria')->count();

        $this->withToken($token)->deleteJson('/api/notas/destroy/'.$inexistente);

        $this->assertSame($antes, DB::table('auditoria')->count(),
            'Borrar una nota que no existe dejó una línea de auditoría.');
    }

    // ─────────────────────────────────────────────────────────────────────
    // Las definitivas
    // ─────────────────────────────────────────────────────────────────────

    /** La definitiva tecleada con `nf_id` — la que ya escribía en `bitacoras`. */
    public function test_teclear_una_definitiva_por_su_id_deja_linea(): void
    {
        $token = $this->tokenDeSuperusuario();

        $nf = DB::selectOne('SELECT nf.id, nf.nota, nf.alumno_id FROM notas_finales nf
            INNER JOIN periodos p ON p.id = nf.periodo_id AND p.deleted_at IS NULL
            ORDER BY nf.id LIMIT 1');
        $this->assertNotNull($nf, 'El seed necesita una definitiva con periodo.');

        $nueva = (float) $nf->nota == 4.0 ? 3.0 : 4.0;

        $this->withToken($token)->putJson('/api/definitivas_periodos/update', [
            'nf_id' => $nf->id, 'nota' => $nueva,
        ])->assertStatus(200);

        $lineas = $this->lineasDe('nota_final', (int) $nf->id);

        $this->assertCount(1, $lineas, 'Teclear la definitiva no dejó línea.');
        $this->assertSame(Auditoria::EDITAR, $lineas[0]->accion);
        $this->assertEquals($nueva, json_decode((string) $lineas[0]->valor_nuevo));
        $this->assertEquals($nf->alumno_id, $lineas[0]->alumno_id);
    }

    /**
     * **La rama sin `nf_id`, que no dejaba rastro en ninguna de las dos tablas.**
     *
     * No es uno de los diez escritores: es un hueco, y por eso este caso importa
     * más que sus vecinos. Lo señaló `tools/escrituras-sin-auditoria.php` al
     * volver a correrlo con los diez ya traducidos — el método pasó a «audita
     * menos veces de las que escribe», que es la pregunta que la lista de los
     * diez no puede contestar.
     *
     * Y es la rama que el front usa **de verdad** cuando no tiene `nf_id` a mano
     * (§2.3 de 10-definitivas.md), o sea que una definitiva tecleada a mano por
     * este camino era invisible para el colegio.
     */
    public function test_la_definitiva_sin_nf_id_tambien_deja_rastro(): void
    {
        $token = $this->tokenDeSuperusuario();

        $nf = DB::selectOne('SELECT nf.id, nf.nota, nf.alumno_id, nf.asignatura_id, p.numero
            FROM notas_finales nf
            INNER JOIN periodos p ON p.id = nf.periodo_id AND p.deleted_at IS NULL
            WHERE nf.alumno_id IS NOT NULL AND nf.asignatura_id IS NOT NULL
            ORDER BY nf.id LIMIT 1');
        $this->assertNotNull($nf, 'El seed necesita una definitiva con alumno, asignatura y periodo.');

        $nueva = (float) $nf->nota == 4.0 ? 3.0 : 4.0;

        $this->withToken($token)->putJson('/api/definitivas_periodos/update', [
            'alumno_id' => $nf->alumno_id,
            'asignatura_id' => $nf->asignatura_id,
            'num_periodo' => $nf->numero,
            'nota' => $nueva,
        ])->assertStatus(200);

        $lineas = $this->lineasDe('nota_final');

        $this->assertNotCount(0, $lineas,
            'La rama sin `nf_id` escribe la definitiva y no deja ninguna línea. '.
            'Es el camino que usa el front cuando no tiene el id a mano.');

        $this->assertEquals($nf->alumno_id, $lineas[0]->alumno_id, 'La línea no dice de qué alumno era.');
        $this->assertEquals($nf->asignatura_id, $lineas[0]->asignatura_id);
        $this->assertEquals($nueva, json_decode((string) $lineas[0]->valor_nuevo));
    }

    // ─────────────────────────────────────────────────────────────────────
    // Las subunidades y el año
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Crear una subunidad se registra como **alta**, no como edición.
     *
     * `bitacoras` lo escribía con `affected_element_type = "Nueva subunidad"`
     * —texto libre, y la tercera de las tres convenciones de nombre que conviven
     * en esa columna—. Aquí el verbo va en `accion` y el sujeto en `entidad`, que
     * es lo que permite agrupar sin conocerse la lista de memoria.
     */
    public function test_crear_una_subunidad_se_registra_como_alta(): void
    {
        $token = $this->tokenDeSuperusuario();

        $unidad = DB::selectOne('SELECT u.id FROM unidades u
            INNER JOIN asignaturas a ON a.id = u.asignatura_id AND a.deleted_at IS NULL
            WHERE u.deleted_at IS NULL ORDER BY u.id LIMIT 1');
        $this->assertNotNull($unidad, 'El seed necesita una unidad con asignatura.');

        $r = $this->withToken($token)->postJson('/api/subunidades', [
            'unidad_id' => $unidad->id,
            'definicion' => 'Prueba de auditoría',
            'porcentaje' => 10,
            'nota_default' => 0,
        ]);

        $this->assertContains($r->status(), [200, 201], 'No se creó la subunidad: '.$r->getContent());

        $lineas = $this->lineasDe('subunidad');

        $this->assertCount(1, $lineas, 'Crear la subunidad no dejó línea.');
        $this->assertSame(Auditoria::CREAR, $lineas[0]->accion,
            'El alta se registró con otro verbo: en la pantalla se leería como una edición.');

        $valor = json_decode((string) $lineas[0]->valor_nuevo, true);

        $this->assertSame('Prueba de auditoría', $valor['definicion'],
            'La definición y el porcentaje van como estructura, no pegados con ` -- `: '.
            'una definición con un guión dentro no se puede volver a separar.');
        $this->assertArrayHasKey('porcentaje', $valor);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Los dos sucesos de seguridad, que no son escrituras
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Un login fallido **no inventa un actor**.
     *
     * Es el cambio con más consecuencia de los diez y no se ve en la respuesta:
     * `bitacoras` escribe `created_by = 0` —un id de usuario que no existe
     * disfrazado de uno que sí—, así que cualquier `JOIN users` sobre esa tabla
     * pierde la fila o la cuenta mal. Un intento fallido no tiene actor por
     * definición: si lo tuviera, habría entrado. Lo único que se sabe es el
     * nombre que se tecleó, y para eso está `actor_intentado`.
     */
    public function test_un_login_fallido_no_inventa_un_actor(): void
    {
        // `auth/login` y no `login`: la ruta vieja contesta 200 a unas credenciales
        // malas —es otro asunto, y tiene dueño— pero las dos pasan por
        // `Services\Login` y las dos anotan el intento. Se usa la que da el 400
        // para no atar este caso a un fallo ajeno.
        $this->postJson('/api/auth/login', [
            'username' => 'nadie-de-este-colegio',
            'password' => 'lo-que-sea',
        ])->assertStatus(400);

        $lineas = $this->lineasDe('intento_login');

        $this->assertCount(1, $lineas, 'El intento fallido no dejó línea.');

        $linea = $lineas[0];

        $this->assertSame(Auditoria::DENEGADO, $linea->accion,
            'Un intento rechazado no es una escritura: no se guardó nada.');
        $this->assertNull($linea->actor_user_id,
            'La línea inventa un actor para alguien que no llegó a entrar.');
        $this->assertNull($linea->actor_nombre,
            '`actor_nombre` dice QUIÉN fue, y aquí no se sabe.');
        $this->assertSame('nadie-de-este-colegio', $linea->actor_intentado,
            'El nombre tecleado es lo único que se sabe y va en `actor_intentado`.');
    }

    /**
     * Pedir el boletín de otro se registra como **denegado**, no como edición.
     *
     * Es la distinción que `bitacoras` no sabe hacer: allí esto entra por la
     * misma puerta que un cambio de nota, con el tipo en texto libre
     * (`AlumnoVerBoletin`), y en una pantalla que agrupe por tipo aparece
     * diciendo lo contrario de lo que pasó. Aquí no lleva valor viejo ni valor
     * nuevo porque **no se escribió nada**, y eso se comprueba.
     */
    public function test_pedir_el_boletin_de_otro_se_registra_como_denegado(): void
    {
        $alumno = $this->usuarioDeTipo('Alumno');

        $otro = DB::selectOne('SELECT username FROM users
            WHERE id <> ? AND deleted_at IS NULL AND username <> "" ORDER BY id LIMIT 1', [$alumno->id]);
        $this->assertNotNull($otro, 'El seed necesita otra cuenta.');

        $this->withToken($this->tokenDe($alumno->username))
            ->getJson('/api/perfiles/username/'.rawurlencode($otro->username))
            ->assertStatus(403);

        $lineas = $this->lineasDe('persona');

        $this->assertCount(1, $lineas, 'El rechazo no dejó línea de auditoría.');

        $linea = $lineas[0];

        $this->assertSame(Auditoria::DENEGADO, $linea->accion);
        $this->assertNull($linea->valor_anterior, 'Un intento rechazado no tiene valor viejo: no se escribió nada.');
        $this->assertNull($linea->valor_nuevo, 'Un intento rechazado no tiene valor nuevo: no se escribió nada.');
        $this->assertNotNull($linea->actor_user_id, 'La línea no dice a quién se le dijo que no.');
    }
}
