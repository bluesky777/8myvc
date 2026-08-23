<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * Los catálogos del colegio: ordinales de disciplina, niveles, grados y tipos de documento.
 *
 * Hueco de cobertura elegido por dominio y no por número: de las 32 rutas de
 * `CiudadesController`, `Disciplina\OrdinalesController`, `NivelesEducativosController`,
 * `TipoDocumentoController` y `GradosController`, casi todas las de escritura llevan
 * `auth.personal` a secas.
 *
 * **Que lleven `auth.personal` a secas no es lo que se comprueba aquí.** Joseth decidió el
 * 21 ago 2026 no cerrar las 44 rutas de escritura de la configuración del colegio, porque
 * cerrarlas puede dejar fuera a un coordinador que hoy configura y no tiene el rol
 * (09-pendientes.md, «Y una cosa que no encaja con lo que se dio por hecho»). Es una
 * decisión tomada. Lo que faltaba de estas rutas es lo otro: **qué responden**.
 */
class CatalogosDelColegioTest extends CasoDeContrato
{
    /**
     * El `year_id` del cuerpo entra crudo en el SQL de los ordinales.
     *
     * `putOrdinales()` arma la primera de sus tres consultas concatenando:
     *
     *     'SELECT * FROM dis_ordinales WHERE year_id='.$year_id.' and deleted_at is null ...'
     *
     * y `$year_id` es `Request::input('year_id', $user->year_id)`, o sea el cuerpo. Las
     * otras dos consultas del MISMO método ligan el parámetro (`:year_id`). La asimetría
     * es lo que hace el test barato de leer: se manda un `year_id` con SQL dentro y
     * `ordinales` obedece al SQL mientras `tipos` sigue contestando por el año de verdad.
     *
     * `and` liga más fuerte que `or`, así que `2 OR 1=1` se lee como
     * `year_id=2 OR (1=1 and deleted_at is null)`: salen los ordinales de **todos los
     * años del colegio**, no los del año pedido.
     *
     * No es de la familia de `ColumnaSegura`, y por eso no lo tapó: allí lo que se
     * concatena es el NOMBRE de la columna y el valor va ligado. Aquí es el valor.
     *
     * Y la ruta **ya estaba cubierta** —`MuestreoDeLecturasConContextoTest` la golpea con
     * un `year_id` legítimo y compara la instantánea—, que es la lección que ya va por la
     * tercera vez en dos días: un test que fija lo que hay deja fijado también lo que
     * estaba mal, y a partir de ahí hay un verde que dice que es así.
     */
    public function test_el_year_de_los_ordinales_no_admite_sql_en_el_cuerpo(): void
    {
        $grupo = $this->grupoConAlumnos();
        $token = $this->tokenDelPersonalLlanoDe($grupo->year_id);

        $suyos = (int) DB::selectOne('SELECT COUNT(*) n FROM dis_ordinales
            WHERE year_id = ? AND deleted_at IS NULL', [$grupo->year_id])->n;

        $deTodoElColegio = (int) DB::selectOne('SELECT COUNT(*) n FROM dis_ordinales
            WHERE deleted_at IS NULL')->n;

        $this->assertGreaterThan(0, $suyos, 'El año del grupo no tiene ordinales: el test no mediría nada.');
        $this->assertGreaterThan($suyos, $deTodoElColegio,
            'El seed necesita ordinales de más de un año para que la fuga se note.');

        $cuerpo = $this->withToken($token)
            ->putJson('/api/ordinales/ordinales', ['year_id' => $grupo->year_id.' OR 1=1'])
            ->assertStatus(200)
            ->json();

        $this->assertCount($suyos, $cuerpo['ordinales'],
            'El `year_id` del cuerpo se concatena en el SQL: con `OR 1=1` salen los '.
            'ordinales de todos los años del colegio en vez de los del año pedido.');
    }

    /**
     * **Ya no se puede llegar al daño: un grado con grupos no se borra — 422.**
     *
     * Este test medía el daño y hoy mide que la puerta está cerrada. Lo que medía
     * —y sigue siendo verdad del mecanismo— es que mandar un grado a la papelera
     * **apagaba las asignaturas de sus profesores** mientras la rejilla de grupos
     * seguía enseñando el grupo como si nada: 1 asignatura antes y 0 después, con
     * el grupo intacto en `GET api/grupos` las dos veces.
     *
     * El mecanismo no era una cascada de la base —los seis modelos de catálogo son
     * de papelera, así que el `ON DELETE CASCADE` de `grupos.grado_id` no llega a
     * dispararse nunca—. Era que **cada consulta decide por su cuenta si mira
     * `deleted_at`**: `Profesor::asignaturas` une `inner join grados … and
     * gr.deleted_at is null`, y la rejilla de `GruposController` une por el mismo
     * grado **sin ese filtro**. La misma fila en la papelera escondía una pantalla
     * y no la otra.
     *
     * **Joseth, 23 ago 2026: se impide, y el aviso dice cuántos grupos dependen.**
     * Lo que este test afirma ahora son las dos mitades: que **corta con 422** y
     * que **el profesor conserva sus asignaturas**, que es lo único que distingue
     * «no dejó» de «dijo que no».
     *
     * **El mecanismo sigue armado y por eso se deja escrito**: nada impide que
     * vuelva por otro camino —un `forceDelete`, o un borrado hecho a mano en la
     * base—, y sigue sin haber ruta de `restore` para grados.
     */
    public function test_un_grado_con_grupos_no_se_borra_y_el_profesor_conserva_sus_asignaturas(): void
    {
        // El año tiene que ser el ACTUAL: `Services\Login` reescribe `users.periodo_id`
        // al entrar, así que el token acaba en el año actual y una asignatura de otro
        // año no la vería ni antes ni después — el test pasaría sin medir nada.
        $grupo = DB::selectOne('SELECT g.id, g.year_id, g.grado_id, a.profesor_id
            FROM grupos g
            INNER JOIN asignaturas a ON a.grupo_id = g.id AND a.deleted_at IS NULL
            INNER JOIN grados gr ON gr.id = g.grado_id AND gr.deleted_at IS NULL
            INNER JOIN years y ON y.id = g.year_id AND y.actual = 1 AND y.deleted_at IS NULL
            WHERE g.deleted_at IS NULL AND a.profesor_id IS NOT NULL
            ORDER BY g.id LIMIT 1');

        $this->assertNotNull($grupo, 'El seed necesita un grupo del año actual con asignatura y profesor.');

        $token = $this->tokenDelPersonalLlanoDe($grupo->year_id);

        $antes = $this->withToken($token)->getJson('/api/asignaturas/listasignaturas/'.$grupo->profesor_id);
        $this->assertGreaterThan(0, count($antes->json('asignaturas') ?? []),
            'Ese profesor no tiene asignaturas visibles antes de borrar: el test no mediría nada.');

        $gruposAntes = $this->withToken($token)->getJson('/api/grupos');
        $this->assertContains($grupo->id, array_column($gruposAntes->json() ?? [], 'id'));

        $this->olvidarControladores();
        $this->withToken($token)->deleteJson('/api/grados/destroy/'.$grupo->grado_id)
            ->assertStatus(422);
        $this->olvidarControladores();

        $this->assertNull(DB::table('grados')->where('id', $grupo->grado_id)->value('deleted_at'),
            'Contestó 422 y aun así mandó el grado a la papelera.');

        $despues = $this->withToken($token)->getJson('/api/asignaturas/listasignaturas/'.$grupo->profesor_id);
        $this->assertGreaterThan(0, count($despues->json('asignaturas') ?? []),
            'El profesor perdió sus asignaturas: el 422 llegó DESPUÉS de escribir, que es '
            .'la forma exacta que tenían la mitad de los hallazgos de la noche del 22 al 23.');

        $gruposDespues = $this->withToken($token)->getJson('/api/grupos');
        $this->assertContains($grupo->id, array_column($gruposDespues->json() ?? [], 'id'));
    }

    /**
     * El contraste, que es lo que convierte lo de arriba en una regla: **el mismo
     * gesto sobre otro catálogo no esconde a nadie.**
     *
     * `tipos_documentos` entra en las listas de alumnos por `left join … and
     * t.deleted_at is null`. Mandar uno a la papelera deja el hueco a la vista —el
     * alumno sale con el tipo vacío— en vez de quitar la fila.
     *
     * > **Con `left join` la papelera deja un hueco; con `inner join` esconde la fila
     * > entera.** Es la misma columna, el mismo `delete()` y dos consecuencias.
     */
    public function test_borrar_un_tipo_de_documento_deja_el_hueco_pero_no_esconde_al_alumno(): void
    {
        $grupo = $this->grupoConAlumnos();
        $token = $this->tokenDelPersonalLlanoDe($grupo->year_id);

        $tipo = DB::selectOne('SELECT id FROM tipos_documentos WHERE deleted_at IS NULL ORDER BY id LIMIT 1');
        $this->assertNotNull($tipo, 'El seed necesita un tipo de documento.');

        // La condición se construye: el alumno del seed puede no tener tipo puesto, y
        // entonces el «después» sería igual que el «antes» por otro motivo.
        $alumno = DB::selectOne('SELECT a.id FROM alumnos a
            INNER JOIN matriculas m ON m.alumno_id = a.id AND m.grupo_id = ? AND m.deleted_at IS NULL
            WHERE a.deleted_at IS NULL ORDER BY a.id LIMIT 1', [$grupo->id]);
        $this->assertNotNull($alumno, 'El grupo no tiene alumnos matriculados.');
        DB::update('UPDATE alumnos SET tipo_doc = ? WHERE id = ?', [$tipo->id, $alumno->id]);

        $antes = $this->withToken($token)->getJson('/api/nota_comportamiento/detailed/'.$grupo->id);
        $antes->assertStatus(200);
        // La respuesta es un array POSICIONAL —`[frases, alumnos, grupo]`, montado con
        // tres `array_push`—, así que los alumnos son el índice 1. Queda dicho porque
        // añadir un elemento en medio le cambia el sitio a todo lo de detrás.
        $filaAntes = collect($antes->json()[1] ?? [])->firstWhere('alumno_id', $alumno->id);
        $this->assertNotNull($filaAntes, 'El alumno no sale en la lista antes de borrar el tipo.');
        $this->assertNotNull($filaAntes['tipo_doc'], 'El alumno tiene que traer tipo de documento para que el test mida.');

        $this->olvidarControladores();
        $this->withToken($token)->deleteJson('/api/tiposdocumento/'.$tipo->id)->assertStatus(200);
        $this->olvidarControladores();

        $despues = $this->withToken($token)->getJson('/api/nota_comportamiento/detailed/'.$grupo->id);
        $filaDespues = collect($despues->json()[1] ?? [])->firstWhere('alumno_id', $alumno->id);

        $this->assertNotNull($filaDespues, 'El alumno desapareció de la lista: eso sería el caso del grado, no éste.');
        $this->assertNull($filaDespues['tipo_doc'], 'El tipo de documento tendría que quedar vacío.');
    }

    /**
     * Las escalas de valoración contestaban **«Guardado»** y **«En papelera»** sobre
     * filas que no existen. Es la familia de `tools/respuestas-que-mienten.py`, y
     * ahora son 404 — que en esta API significa «esa fila no está» desde la serie
     * §44/§47/§49/§50/§53.
     *
     * El `id` de `update` viaja en el **cuerpo** (la ruta es `PUT escalas/update` a
     * secas), así que el caso sin `id` es el mismo caso.
     */
    public function test_las_escalas_no_contestan_que_si_sobre_una_fila_que_no_existe(): void
    {
        $grupo = $this->grupoConAlumnos();
        $token = $this->tokenDelPersonalDe($grupo->year_id);

        $inexistente = ((int) DB::selectOne('SELECT MAX(id) m FROM escalas_de_valoracion')->m) + 5000;

        $this->withToken($token)->deleteJson('/api/escalas/destroy/'.$inexistente)
            ->assertStatus(404);

        $this->olvidarControladores();
        $this->withToken($token)->putJson('/api/escalas/update', [
            'id' => $inexistente, 'desempenio' => 'INVENTADO', 'porc_inicial' => 1, 'porc_final' => 2,
        ])->assertStatus(404);

        $this->olvidarControladores();
        $this->withToken($token)->putJson('/api/escalas/update', ['desempenio' => 'SIN ID'])
            ->assertStatus(404);
    }

    /**
     * Y el otro lado, que es el que impide arreglarlo de más: **guardar una escala
     * sin cambiarle nada sigue siendo «Guardado»**.
     *
     * Este caso existe por una trampa concreta: MySQL devuelve **0 filas afectadas
     * cuando el `UPDATE` no cambia ningún valor**, no sólo cuando no encuentra la
     * fila. Si el 404 se hubiera escrito contando filas afectadas —que es lo primero
     * que uno hace—, guardar dos veces lo mismo daría 404. Por eso la comprobación
     * es un `SELECT`. El mismo tropiezo se cazó escribiendo el UPSERT de las
     * definitivas (10-definitivas.md, fase 1).
     */
    public function test_guardar_una_escala_sin_cambiar_nada_sigue_siendo_guardado(): void
    {
        $grupo = $this->grupoConAlumnos();
        $token = $this->tokenDelPersonalLlanoDe($grupo->year_id);

        $escala = DB::selectOne('SELECT * FROM escalas_de_valoracion WHERE deleted_at IS NULL ORDER BY id LIMIT 1');
        $this->assertNotNull($escala, 'El seed necesita una escala de valoración.');

        $cuerpo = [
            'id' => $escala->id, 'desempenio' => $escala->desempenio, 'valoracion' => $escala->valoracion,
            'porc_inicial' => $escala->porc_inicial, 'porc_final' => $escala->porc_final,
            'orden' => $escala->orden, 'perdido' => $escala->perdido, 'descripcion' => $escala->descripcion,
        ];

        $this->withToken($token)->putJson('/api/escalas/update', $cuerpo)->assertStatus(200);
        $this->olvidarControladores();
        $this->withToken($token)->putJson('/api/escalas/update', $cuerpo)->assertStatus(200);
    }

    /**
     * Borrar una escala de verdad la manda a la papelera y desaparece del índice —el
     * viaje de ida y vuelta, no el 200—. Y con ella se fija una decisión ya tomada:
     * **la escala de otro año se puede borrar**, porque escribir en años pasados está
     * permitido (05 §27.4). Si alguien lo cierra, este caso lo dice.
     */
    public function test_borrar_una_escala_la_quita_del_indice_y_la_de_otro_anio_tambien_se_deja(): void
    {
        $grupo = $this->grupoConAlumnos();
        $token = $this->tokenDelPersonalLlanoDe($grupo->year_id);

        $indice = $this->withToken($token)->getJson('/api/escalas');
        $indice->assertStatus(200);
        $suyas = $indice->json() ?? [];
        $this->assertNotEmpty($suyas, 'El año del token no tiene escalas: el test no mediría nada.');

        $victima = $suyas[0]['id'];
        $anioDelToken = (int) DB::selectOne('SELECT year_id FROM escalas_de_valoracion WHERE id = ?', [$victima])->year_id;

        $this->olvidarControladores();
        $this->withToken($token)->deleteJson('/api/escalas/destroy/'.$victima)->assertStatus(200);
        $this->olvidarControladores();

        $despues = array_column($this->withToken($token)->getJson('/api/escalas')->json() ?? [], 'id');
        $this->assertNotContains($victima, $despues, 'La escala borrada sigue en el índice.');

        $ajena = DB::selectOne('SELECT id FROM escalas_de_valoracion
            WHERE deleted_at IS NULL AND year_id <> ? ORDER BY id LIMIT 1', [$anioDelToken]);

        if ($ajena === null) {
            $this->markTestIncomplete('El seed no trae escalas de otro año; la parte del año ajeno no se midió.');
        }

        $this->olvidarControladores();
        $this->withToken($token)->deleteJson('/api/escalas/destroy/'.$ajena->id)
            ->assertStatus(200);
    }
}
