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
