<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * Las asignaturas de un grupo: su horario, su papelera y copiarlas de un grupo a otro.
 *
 * `AsignaturasController` estaba en 5 de 14 rutas comprobadas. Lo que no se
 * miraba es casi todo lo que escribe.
 */
class AsignaturasTest extends CasoDeContrato
{
    private function tokenDelPersonal(): string
    {
        return $this->tokenDe($this->usuarioDeTipo('Usuario')->username);
    }

    private function unaAsignatura(): object
    {
        $fila = DB::selectOne('SELECT a.id, a.grupo_id, a.materia_id, a.profesor_id, a.creditos, a.orden
            FROM asignaturas a
            INNER JOIN grupos g ON g.id = a.grupo_id AND g.deleted_at IS NULL
            WHERE a.deleted_at IS NULL ORDER BY a.id LIMIT 1');

        $this->assertNotNull($fila, 'El seed no tiene asignaturas vivas.');

        return $fila;
    }

    /**
     * El conmutador de día del horario escribe la columna que se le pide.
     *
     * Merece un test por cómo está escrito: la consulta lleva marcadores
     * **nombrados** (`:valor`, `:modificador`…) y las ataduras van en un array
     * **posicional**. Parece un fallo y no lo es —PDO las liga por posición, y se
     * comprobó ejecutándolo, no leyéndolo—. El test lo deja fijado por si algún
     * día esa mezcla deja de funcionar en una versión nueva.
     *
     * El nombre del día pasa por `ColumnaSegura::exigir`, así que no hay
     * inyección por ahí; se comprueba también que un nombre inventado no entra.
     */
    public function test_el_conmutador_de_dia_escribe_su_columna(): void
    {
        $token = $this->tokenDelPersonal();
        $asignatura = $this->unaAsignatura();

        foreach (['lunes', 'martes', 'miercoles', 'jueves', 'viernes'] as $dia) {
            foreach ([1, 0] as $valor) {
                $this->withToken($token)->putJson('/api/asignaturas/toggle-dia',
                    ['asignatura_id' => $asignatura->id, 'dia' => $dia, 'valor' => $valor])
                    ->assertStatus(200);

                $this->assertSame($valor,
                    (int) DB::table('asignaturas')->where('id', $asignatura->id)->value($dia),
                    "toggle-dia no dejó {$dia} en {$valor}.");
            }
        }
    }

    /**
     * `ColumnaSegura` impide la inyección, y **no** limita a los días.
     *
     * El nombre de la ruta dice «toggle-dia» y la comprobación dice otra cosa:
     * `ColumnaSegura::exigir('asignaturas', $dia)` acepta **cualquier columna que
     * exista en la tabla**. O sea que la misma ruta escribe `profesor_id`,
     * `materia_id` o `creditos`. No es un agujero —lleva `auth.personal` y quien
     * pasa ese guard ya puede escribir esas columnas por `asignaturas/update/{id}`—
     * pero tampoco es lo que el nombre promete, y el día que alguien apoye un
     * permiso en «esta ruta solo toca el horario» se llevará una sorpresa.
     *
     * Lo que sí hace `ColumnaSegura` es lo suyo: un nombre que no es una columna
     * no llega a la consulta. Eso es lo que se fija aquí, con las dos mitades.
     */
    public function test_el_conmutador_acepta_cualquier_columna_pero_no_una_inventada(): void
    {
        $token = $this->tokenDelPersonal();
        $asignatura = $this->unaAsignatura();

        // Mitad uno: una columna real que no es un día entra igual.
        $this->withToken($token)->putJson('/api/asignaturas/toggle-dia',
            ['asignatura_id' => $asignatura->id, 'dia' => 'creditos', 'valor' => 7])
            ->assertStatus(200);

        $this->assertSame(7,
            (int) DB::table('asignaturas')->where('id', $asignatura->id)->value('creditos'),
            'La ruta escribe cualquier columna de la tabla, no solo los días.');

        // Mitad dos: un nombre que no es columna no llega a la consulta.
        $r = $this->withToken($token)->putJson('/api/asignaturas/toggle-dia',
            ['asignatura_id' => $asignatura->id, 'dia' => 'lunes = 1, creditos', 'valor' => 99]);

        $this->assertNotSame(200, $r->status(),
            'ColumnaSegura dejó pasar un nombre que no es una columna.');
        $this->assertSame(7,
            (int) DB::table('asignaturas')->where('id', $asignatura->id)->value('creditos'));
    }

    /**
     * **§96. El conmutador genérico no escribe en una fila que no está.**
     *
     * `asignaturas/toggle-dia` es «guardar un campo suelto» de la rejilla, y su
     * `UPDATE` no llevaba `deleted_at is null` ni ningún `WHERE` que pudiera fallar:
     * contestaba **'Cambiado' pasara lo que pasara**. Las tres salidas se midieron y
     * ninguna se notaba desde fuera:
     *
     * - **sin `asignatura_id` ninguno**: 200 'Cambiado' y no escribía nada — la
     *   familia de `respuestas-que-mienten.py`, y la ruta sale además en
     *   `identificadores-del-cuerpo.py` como identificador del cuerpo sin comprobar;
     * - **sobre una asignatura de la papelera**: 200 y escribía de verdad, en una
     *   fila que ninguna pantalla enseña;
     * - y con `dia: 'profesor_id'`, **reasignaba el profesor** de esa fila, porque
     *   `ColumnaSegura` valida el nombre de la columna y no limita cuál.
     *
     * Esta ruta **ya tenía la respuesta comprobada** por los dos tests de aquí
     * arriba, escritos mirando la inyección. Medir una ruta no es haberla juzgado:
     * alguien preguntó qué código devuelve y nadie preguntó de qué fila.
     */
    public function test_el_conmutador_de_dia_no_escribe_en_la_papelera(): void
    {
        $token = $this->tokenDelPersonal();
        $asignatura = $this->unaAsignatura();

        // Un valor distinto del que intenta escribir la petición: si fuera el mismo,
        // el test pasaría también con el arreglo revertido.
        DB::table('asignaturas')->where('id', $asignatura->id)
            ->update(['lunes' => 0, 'deleted_at' => now()]);

        $this->withToken($token)->putJson('/api/asignaturas/toggle-dia',
            ['asignatura_id' => $asignatura->id, 'dia' => 'lunes', 'valor' => 1])
            ->assertStatus(404);

        $this->assertSame(0,
            (int) DB::table('asignaturas')->where('id', $asignatura->id)->value('lunes'),
            'No puede haber escrito en una fila de la papelera.');
    }

    /** Y sin `asignatura_id` ya no contesta que cambió algo, porque no cambia nada. */
    public function test_el_conmutador_de_dia_sin_id_ya_no_dice_que_cambio(): void
    {
        $token = $this->tokenDelPersonal();

        $r = $this->withToken($token)->putJson('/api/asignaturas/toggle-dia',
            ['dia' => 'lunes', 'valor' => 1]);

        $this->assertSame(404, $r->status(),
            'Contestaba 200 «Cambiado» sin escribir nada, que es la peor de las dos.');
    }

    /**
     * **§96. Editar una asignatura ya no borra los créditos ni el orden.**
     *
     * `putUpdate` escribía las cinco columnas con `Request::input('x')` a secas
     * excepto `profesor_id` —que ya llevaba defecto—, y **esa asimetría era lo único
     * que se veía**. Medido: un cuerpo con los tres ids dejaba `creditos` en null,
     * de 2 a NULL, y contestaba 200.
     *
     * Aquí el arreglo **sí** necesita `CamposQueVinieron` y no el defecto de
     * `Request::input()`, y es el único de los tres del lote que lo necesita:
     * `fixInputs()` hace tres `Request::merge()` antes de que el método lea nada, así
     * que a esa altura `has()` ya no distingue lo que mandó el cliente de lo que se
     * rellenó solo. Es literalmente el motivo por el que esa clase existe (§68).
     */
    public function test_editar_una_asignatura_no_borra_los_creditos(): void
    {
        $token = $this->tokenDelPersonal();
        $a = $this->unaAsignatura();
        DB::table('asignaturas')->where('id', $a->id)->update(['creditos' => 3, 'orden' => 7]);

        $this->withToken($token)->putJson('/api/asignaturas/update/'.$a->id, [
            'materia_id' => $a->materia_id,
            'grupo_id' => $a->grupo_id,
            'profesor_id' => $a->profesor_id,
        ])->assertStatus(200);

        $fila = DB::selectOne('SELECT * FROM asignaturas WHERE id = ?', [$a->id]);
        $this->assertSame(3, (int) $fila->creditos, 'Los créditos se pisaron a null.');
        $this->assertSame(7, (int) $fila->orden, 'El orden se pisó a null.');
        $this->assertSame((int) $a->materia_id, (int) $fila->materia_id);
    }

    /** Y lo que el cuerpo sí trae se escribe, incluido un cero y un null explícitos. */
    public function test_editar_una_asignatura_escribe_lo_que_trae(): void
    {
        $token = $this->tokenDelPersonal();
        $a = $this->unaAsignatura();
        DB::table('asignaturas')->where('id', $a->id)->update(['creditos' => 3]);

        $this->withToken($token)->putJson('/api/asignaturas/update/'.$a->id,
            ['creditos' => 0])->assertStatus(200);
        $this->assertSame(0, (int) DB::table('asignaturas')->where('id', $a->id)->value('creditos'),
            'Cero créditos es un valor, no una ausencia.');

        $this->withToken($token)->putJson('/api/asignaturas/update/'.$a->id,
            ['creditos' => null])->assertStatus(200);
        $this->assertNull(DB::table('asignaturas')->where('id', $a->id)->value('creditos'),
            'Mandar null es pedir que se quite, y tiene que quitarse.');
    }

    /**
     * **La forma anidada del cuerpo ya no revienta cuando la clave no viaja.**
     *
     * `fixInputs()` aplana `{profesor: {profesor_id}}` en `{profesor_id}`, y lo hacía
     * con `Request::input('profesor')['profesor_id']`: **indexar lo que devuelve
     * `input()` sin saber si es un array**. Con la clave ausente eso es indexar null,
     * que Laravel sube a excepción (§69) y aquí sale como **500**, porque este método
     * no está dentro de ningún `try`.
     *
     * Se llega desde la pantalla: `AsignaturasCtrl.editar` rellena `row.profesor` con
     * un `filter` por `profesor_id`, y en una asignatura **sin profesor** —la columna
     * es nulable— el filtro devuelve vacío y la clave no viaja. **El seed no tiene
     * ninguna asignatura así**, o sea que la población en producción está sin medir:
     * la condición se construye aquí, que es lo que hay que hacer cuando el seed no
     * la trae.
     */
    public function test_editar_una_asignatura_sin_profesor_no_revienta(): void
    {
        $token = $this->tokenDelPersonal();
        $a = $this->unaAsignatura();
        DB::table('asignaturas')->where('id', $a->id)->update(['profesor_id' => null]);

        // Lo que manda la pantalla con `row.profesor` sin resolver: la clave no viaja.
        $r = $this->withToken($token)->putJson('/api/asignaturas/update/'.$a->id, [
            'materia_id' => $a->materia_id,
            'grupo_id' => $a->grupo_id,
            'profesor_id' => null,
        ]);

        $r->assertStatus(200);
        $this->assertNull(DB::table('asignaturas')->where('id', $a->id)->value('profesor_id'));
    }

    /**
     * **§96. Un periodo o un profesor que no existen son 404, no un aviso de PHP.**
     *
     * `list-asignaturas-year/{profesor}/{periodo}` llamaba a `Year::de_un_periodo`,
     * que hace `Periodo::find(...)->year_id` sin comprobar la fila; y
     * `listasignaturas/{persona}` llama a `Profesor::detallado`, que acaba en
     * `return $profesor[0];`. Los dos daban **500**, y a los dos se llega sin
     * inventarse nada: con una fila de la **papelera**, que los dos descartan.
     */
    public function test_las_asignaturas_de_un_periodo_que_no_existe_son_404(): void
    {
        $token = $this->tokenDelPersonal();
        $profesor = DB::selectOne('SELECT id FROM profesores WHERE deleted_at IS NULL ORDER BY id LIMIT 1');
        $inventado = ((int) DB::table('periodos')->max('id')) + 1000;

        $this->withToken($token)
            ->getJson("/api/asignaturas/list-asignaturas-year/{$profesor->id}/{$inventado}")
            ->assertStatus(404);

        $enPapelera = DB::selectOne('SELECT id FROM periodos WHERE deleted_at IS NOT NULL
            ORDER BY id LIMIT 1');
        $this->assertNotNull($enPapelera, 'El seed tiene que traer un periodo en la papelera.');

        $this->withToken($token)
            ->getJson("/api/asignaturas/list-asignaturas-year/{$profesor->id}/{$enPapelera->id}")
            ->assertStatus(404);
    }

    /** Y con el par bueno sigue contestando su lista, que es lo que no puede cambiar. */
    public function test_las_asignaturas_de_un_periodo_bueno_siguen_saliendo(): void
    {
        $token = $this->tokenDelPersonal();
        $par = DB::selectOne('SELECT a.profesor_id, u.periodo_id FROM asignaturas a
            INNER JOIN unidades u ON u.asignatura_id = a.id AND u.deleted_at IS NULL
            WHERE a.deleted_at IS NULL AND a.profesor_id IS NOT NULL
            ORDER BY a.id LIMIT 1');
        $this->assertNotNull($par, 'El seed no tiene un profesor con unidades en un periodo.');

        $r = $this->withToken($token)
            ->getJson("/api/asignaturas/list-asignaturas-year/{$par->profesor_id}/{$par->periodo_id}");

        $r->assertStatus(200);
        $this->assertNotEmpty($r->json(),
            'El par bueno tiene que traer asignaturas, o el 404 de al lado no mide nada.');
        $this->assertArrayHasKey('unidades', $r->json()[0]);
    }

    /** El otro llamante de `Profesor::detallado`, por la misma puerta. */
    public function test_las_asignaturas_de_un_profesor_que_no_existe_son_404(): void
    {
        $token = $this->tokenDelPersonal();
        $inventado = ((int) DB::table('profesores')->max('id')) + 1000;

        $this->withToken($token)->getJson('/api/asignaturas/listasignaturas/'.$inventado)
            ->assertStatus(404);

        $enPapelera = DB::selectOne('SELECT id FROM profesores WHERE deleted_at IS NOT NULL
            ORDER BY id LIMIT 1');
        $this->withToken($token)->getJson('/api/asignaturas/listasignaturas/'.$enPapelera->id)
            ->assertStatus(404);
    }

    /**
     * Lo que se mide y **no** se toca: `detalle-asignatura` no distingue «no existe»
     * de «no tiene unidades».
     *
     * Con un id inventado, con uno de la papelera y **sin id ninguno** contesta lo
     * mismo: `{"unidades": [], "cantidad_notas": 0}`, en 200. No es un fallo con
     * consecuencia medible —la pantalla pinta una rejilla vacía, que es lo que hay—,
     * pero deja al cliente sin forma de distinguir las dos cosas, y eso es lo que se
     * fija aquí para que nadie apoye nada en ese vacío.
     */
    public function test_el_detalle_no_distingue_lo_que_no_existe_de_lo_vacio(): void
    {
        $token = $this->tokenDelPersonal();
        $inventada = ((int) DB::table('asignaturas')->max('id')) + 1000;
        $vacio = ['unidades' => [], 'cantidad_notas' => 0];

        foreach ([['asignatura_id' => $inventada], []] as $cuerpo) {
            $r = $this->withToken($token)->putJson('/api/asignaturas/detalle-asignatura', $cuerpo);
            $r->assertStatus(200);
            $this->assertSame($vacio, $r->json());
        }

        // Y con una de verdad trae sus unidades, o lo de arriba no compara nada.
        $conUnidades = DB::selectOne('SELECT asignatura_id FROM unidades
            WHERE deleted_at IS NULL ORDER BY id LIMIT 1');
        $r = $this->withToken($token)->putJson('/api/asignaturas/detalle-asignatura',
            ['asignatura_id' => $conUnidades->asignatura_id]);
        $r->assertStatus(200);
        $this->assertNotEmpty($r->json('unidades'));
    }

    /** La asignatura va a la papelera, aparece en ella y vuelve. */
    public function test_la_asignatura_va_a_la_papelera_y_vuelve(): void
    {
        $token = $this->tokenDelPersonal();

        // La papelera filtra por el año del usuario, así que hay que borrar una
        // de un grupo de ese año o el listado sale vacío y el test no mide nada.
        [$grupo, $tokenDelAnio] = $this->grupoYPersonal();

        $asignatura = DB::selectOne('SELECT id FROM asignaturas
            WHERE grupo_id = ? AND deleted_at IS NULL ORDER BY id LIMIT 1', [$grupo->id]);
        $this->assertNotNull($asignatura, 'El grupo del seed no tiene asignaturas.');

        $enPapelera = fn () => count($this->withToken($tokenDelAnio)
            ->getJson('/api/asignaturas/papelera')->json());
        $antes = $enPapelera();

        $this->withToken($tokenDelAnio)->deleteJson('/api/asignaturas/destroy/'.$asignatura->id)
            ->assertStatus(200);
        $this->assertNotNull(DB::table('asignaturas')->where('id', $asignatura->id)->value('deleted_at'));
        $this->assertSame($antes + 1, $enPapelera());

        $this->withToken($tokenDelAnio)->putJson('/api/asignaturas/restaurar',
            ['asignatura_id' => $asignatura->id])->assertStatus(200);
        $this->assertNull(DB::table('asignaturas')->where('id', $asignatura->id)->value('deleted_at'));
        $this->assertSame($antes, $enPapelera());
    }

    /**
     * Copiar las asignaturas de un grupo a otro las copia todas y no borra nada.
     *
     * Es un `INSERT` por asignatura sin mirar lo que ya hay en el destino, así
     * que llamarlo dos veces **duplica**. Se fija así: no es un fallo que se vaya
     * a arreglar a ciegas —quien copia sobre un grupo con asignaturas está
     * pidiendo algo que el endpoint no sabe resolver— pero tampoco es lo que
     * parece desde el nombre.
     */
    public function test_copiar_asignaturas_a_otro_grupo(): void
    {
        $token = $this->tokenDelPersonal();

        $origen = DB::selectOne('SELECT g.id, COUNT(a.id) cuantas FROM grupos g
            INNER JOIN asignaturas a ON a.grupo_id = g.id AND a.deleted_at IS NULL
            WHERE g.deleted_at IS NULL GROUP BY g.id ORDER BY cuantas DESC LIMIT 1');

        $destino = DB::selectOne('SELECT g.id FROM grupos g
            LEFT JOIN asignaturas a ON a.grupo_id = g.id AND a.deleted_at IS NULL
            WHERE g.deleted_at IS NULL AND g.id <> ?
            GROUP BY g.id HAVING COUNT(a.id) = 0 ORDER BY g.id LIMIT 1', [$origen->id]);

        if ($destino === null) {
            // Todos los grupos del seed tienen asignaturas: se monta uno vacío.
            $modelo = DB::selectOne('SELECT * FROM grupos WHERE deleted_at IS NULL ORDER BY id LIMIT 1');
            DB::table('grupos')->insert(['nombre' => 'Destino', 'abrev' => 'DST',
                'year_id' => $modelo->year_id, 'grado_id' => $modelo->grado_id,
                'created_at' => now(), 'updated_at' => now()]);
            $destino = (object) ['id' => DB::getPdo()->lastInsertId()];
        }

        $this->withToken($token)->postJson('/api/asignaturas/copiar',
            ['grupo_id_origen' => $origen->id, 'grupo_id_destino' => $destino->id])
            ->assertStatus(200);

        $this->assertSame((int) $origen->cuantas,
            DB::table('asignaturas')->where('grupo_id', $destino->id)->count(),
            'No se copiaron todas las asignaturas del grupo de origen.');

        $this->assertSame((int) $origen->cuantas,
            DB::table('asignaturas')->where('grupo_id', $origen->id)->whereNull('deleted_at')->count(),
            'Copiar no debe tocar el grupo de origen.');

        // Segunda pasada: duplica, y queda escrito.
        $this->withToken($token)->postJson('/api/asignaturas/copiar',
            ['grupo_id_origen' => $origen->id, 'grupo_id_destino' => $destino->id])
            ->assertStatus(200);

        $this->assertSame((int) $origen->cuantas * 2,
            DB::table('asignaturas')->where('grupo_id', $destino->id)->count(),
            'Copiar dos veces duplica: el endpoint no mira lo que ya hay en el destino.');
    }

    /** Una familia no toca la estructura de asignaturas del colegio. */
    public function test_una_familia_no_toca_las_asignaturas(): void
    {
        $asignatura = $this->unaAsignatura();

        foreach (['Alumno', 'Acudiente'] as $tipo) {
            $token = $this->tokenDe($this->usuarioDeTipo($tipo)->username);

            $this->withToken($token)->putJson('/api/asignaturas/toggle-dia',
                ['asignatura_id' => $asignatura->id, 'dia' => 'lunes', 'valor' => 1])
                ->assertStatus(403);
            $this->withToken($token)->deleteJson('/api/asignaturas/destroy/'.$asignatura->id)
                ->assertStatus(403);
            $this->withToken($token)->postJson('/api/asignaturas/copiar',
                ['grupo_id_origen' => $asignatura->grupo_id, 'grupo_id_destino' => $asignatura->grupo_id])
                ->assertStatus(403);
            $this->withToken($token)->getJson('/api/asignaturas/papelera')->assertStatus(403);
        }

        $this->assertNull(DB::table('asignaturas')->where('id', $asignatura->id)->value('deleted_at'));
    }
}
