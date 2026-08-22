<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * Los requisitos de matrícula, mirados por lo que escriben y no por lo que
 * contestan.
 *
 * Cuatro rutas que ningún test miraba al 22 ago 2026, las cuatro
 * `auth.personal`. Lo que las junta es una pregunta: **qué contestan cuando no
 * han escrito nada.**
 *
 * Las dos de actualizar hacen un `UPDATE ... WHERE id=?` con el id que llega del
 * cuerpo, sin comprobar que exista ni de qué año es, y devuelven la cadena
 * `'Actualizado'` **pase lo que pase**. MySQL no se queja de un `WHERE` que no
 * casa con ninguna fila: afecta a cero y sigue. Así que el 200 y el
 * «Actualizado» no dicen que se haya escrito — es la familia que
 * `tools/respuestas-que-mienten.py` existe para encontrar, y la del §54.
 *
 * Los tests miran `updated_at` y `updated_by` en la tabla, no el cuerpo de la
 * respuesta. Es el criterio de `docs/migracion/03-tests.md`: el resultado, no el
 * 200.
 *
 * Se fija lo que hacen hoy. Devolver 404 cuando no se escribe nada cambia lo que
 * ve una pantalla viva en los dieciséis colegios, y eso lo decide Joseth.
 */
class RequisitosDeMatriculaTest extends CasoDeContrato
{
    /** Personal del colegio, del año que tiene grupos con alumnos. */
    private function personal(): object
    {
        $grupo = $this->grupoConAlumnos();

        $usuario = DB::selectOne('SELECT u.id, u.username FROM users u
            INNER JOIN periodos p ON p.id = u.periodo_id AND p.deleted_at IS NULL
            WHERE u.tipo = "Usuario" AND u.is_active = 1 AND u.deleted_at IS NULL
              AND p.year_id = ? ORDER BY u.id LIMIT 1', [$grupo->year_id]);

        $this->assertNotNull($usuario, "El seed no tiene ningún Usuario en el año {$grupo->year_id}.");

        // **El token se pide antes de leer nada del contexto.** Entrar mueve
        // `users.periodo_id` al periodo vigente, así que preguntar por el año
        // antes del login devuelve el del seed y todo lo que cuelga sale vacío.
        // Costó cinco tests en rojo la noche del 21 al 22 de agosto, con la cara
        // de «falta seed».
        $token = $this->tokenDe($usuario->username);

        return (object) [
            'token' => $token,
            'user_id' => (int) $usuario->id,
            'year_id' => (int) $grupo->year_id,
        ];
    }

    /** Un requisito de matrícula recién puesto, del año que se diga. */
    private function requisito(int $yearId, string $nombre = 'Requisito de prueba'): int
    {
        return (int) DB::table('requisitos_matricula')->insertGetId([
            'year_id' => $yearId,
            'requisito' => $nombre,
            'descripcion' => 'Puesto por el test',
            'orden' => 0,
        ]);
    }

    /**
     * Crear un requisito lo escribe y lo devuelve entero.
     *
     * El camino bueno, que hace falta antes que los malos: sin él, una tabla que
     * no se toca en los tests siguientes podría ser porque el endpoint no
     * funciona en absoluto.
     */
    public function test_crear_un_requisito_lo_escribe_y_lo_devuelve(): void
    {
        $quien = $this->personal();

        $respuesta = $this->withToken($quien->token)
            ->postJson('api/requisitos/store', [
                'requisito' => 'Fotocopia del documento',
                'descripcion' => 'Ampliada al 150%',
                'year_id' => $quien->year_id,
            ])
            ->assertOk()
            ->json();

        $this->assertArrayHasKey('requisito', $respuesta);
        $id = (int) $respuesta['requisito']['id'];

        $enLaTabla = DB::selectOne('SELECT requisito, descripcion, year_id, updated_by
            FROM requisitos_matricula WHERE id = ?', [$id]);

        $this->assertNotNull($enLaTabla, 'El requisito no llegó a la tabla.');
        $this->assertSame('Fotocopia del documento', $enLaTabla->requisito);
        $this->assertSame($quien->year_id, (int) $enLaTabla->year_id);
        $this->assertSame($quien->user_id, (int) $enLaTabla->updated_by,
            'No queda anotado quién lo creó.');
    }

    /**
     * Actualizar un requisito que no existe contesta «Actualizado» igual.
     *
     * `putUpdate()` hace `UPDATE requisitos_matricula SET ... WHERE id=?` y
     * devuelve la cadena sin mirar cuántas filas tocó. Con un id que no existe,
     * MySQL afecta a cero y no se queja.
     *
     * Se comprueba **contando la tabla antes y después**: si el endpoint hubiera
     * insertado algo en vez de actualizar, el conteo lo diría y la cadena no.
     */
    public function test_actualizar_un_requisito_inexistente_contesta_actualizado_sin_escribir(): void
    {
        $quien = $this->personal();

        $inexistente = (int) DB::selectOne('SELECT IFNULL(MAX(id),0) + 1000 AS libre
            FROM requisitos_matricula')->libre;

        $antes = (int) DB::selectOne('SELECT COUNT(*) n FROM requisitos_matricula')->n;

        $this->withToken($quien->token)
            ->putJson('api/requisitos/update', [
                'id' => $inexistente,
                'requisito' => 'Nombre que no llega a ninguna parte',
                'descripcion' => 'Ni esta descripción',
            ])
            ->assertOk()
            ->assertSee('Actualizado');

        $despues = (int) DB::selectOne('SELECT COUNT(*) n FROM requisitos_matricula')->n;
        $this->assertSame($antes, $despues,
            'La tabla cambió de tamaño: el endpoint insertó en vez de actualizar.');

        $this->assertNull(
            DB::selectOne('SELECT id FROM requisitos_matricula WHERE id = ?', [$inexistente]),
            'Apareció una fila con ese id.');
    }

    /**
     * Y un requisito **de otro año** sí se actualiza, sin que nadie lo mire.
     *
     * El `UPDATE` no lleva `year_id` en el `WHERE`, y el año de quien llama no
     * pinta nada. Así que el mismo endpoint que no distingue un id inexistente
     * tampoco distingue uno ajeno: escribe.
     *
     * Es la familia del §5 de [09-pendientes.md] —rutas de estructura con solo
     * `auth.personal`, que Joseth decidió el 21 ago no cerrar— así que aquí se
     * mide el alcance y no se cierra nada.
     */
    public function test_actualizar_un_requisito_de_otro_anno_sí_escribe(): void
    {
        $quien = $this->personal();

        $otroAnno = DB::selectOne('SELECT id FROM years WHERE id <> ? AND deleted_at IS NULL
            ORDER BY id LIMIT 1', [$quien->year_id]);

        if ($otroAnno === null) {
            $this->markTestSkipped('El seed solo tiene un año: no se puede montar el caso.');
        }

        $ajeno = $this->requisito((int) $otroAnno->id, 'Requisito del otro año');

        $this->withToken($quien->token)
            ->putJson('api/requisitos/update', [
                'id' => $ajeno,
                'requisito' => 'Reescrito desde otro año',
                'descripcion' => 'Y la descripción también',
            ])
            ->assertOk();

        $despues = DB::selectOne('SELECT requisito, year_id, updated_by
            FROM requisitos_matricula WHERE id = ?', [$ajeno]);

        $this->assertSame('Reescrito desde otro año', $despues->requisito,
            'No lo escribió: alguien añadió la comprobación de año y hay que revisar esta sección.');
        $this->assertSame((int) $otroAnno->id, (int) $despues->year_id,
            'Le cambió el año además del nombre.');
        $this->assertSame($quien->user_id, (int) $despues->updated_by);
    }

    /**
     * Marcar el requisito de un alumno que no existe: «Actualizado» otra vez.
     *
     * `postAlumno()` es la misma forma sobre `requisitos_alumno`, la tabla que
     * dice si un alumno entregó cada papel. Con un `requisito_alumno_id` que no
     * existe no escribe nada y contesta igual.
     *
     * Importa más que su gemelo de arriba porque es **el estado de la matrícula
     * de una persona**: una pantalla que dé por hecho el «Actualizado» puede
     * enseñar como entregado algo que no se guardó.
     */
    public function test_marcar_el_requisito_de_un_alumno_inexistente_contesta_actualizado(): void
    {
        $quien = $this->personal();

        $inexistente = (int) DB::selectOne('SELECT IFNULL(MAX(id),0) + 1000 AS libre
            FROM requisitos_alumno')->libre;

        $antes = (int) DB::selectOne('SELECT COUNT(*) n FROM requisitos_alumno')->n;

        $this->withToken($quien->token)
            ->postJson('api/requisitos/alumno', [
                'requisito_alumno_id' => $inexistente,
                'estado' => 'Entregado',
                'descripcion' => 'Lo trajo el lunes',
            ])
            ->assertOk()
            ->assertSee('Actualizado');

        $despues = (int) DB::selectOne('SELECT COUNT(*) n FROM requisitos_alumno')->n;
        $this->assertSame($antes, $despues,
            'La tabla cambió de tamaño: insertó en vez de actualizar.');
    }

    /**
     * El listado de observaciones acepta el `year_id` del cuerpo, y devuelve el
     * de otro año si se lo piden.
     *
     * `Request::input('year_id', $this->user->year_id)` — el año propio es solo
     * el valor por defecto. Lo que sale por cada requisito son `nombres`,
     * `apellidos` y `celular` de los alumnos que tienen observación.
     *
     * Se fija el alcance, no se cierra: misma familia que el §5. Lo que aporta
     * el test es que la decisión se tome sabiendo qué sale.
     */
    public function test_el_listado_de_observaciones_obedece_al_anno_del_cuerpo(): void
    {
        $quien = $this->personal();

        $otroAnno = DB::selectOne('SELECT id FROM years WHERE id <> ? AND deleted_at IS NULL
            ORDER BY id LIMIT 1', [$quien->year_id]);

        if ($otroAnno === null) {
            $this->markTestSkipped('El seed solo tiene un año: no se puede montar el caso.');
        }

        $marca = $this->requisito((int) $otroAnno->id, 'Requisito solo del otro año');

        $respuesta = $this->withToken($quien->token)
            ->putJson('api/requisitos/listado-observaciones', ['year_id' => $otroAnno->id])
            ->assertOk()
            ->json();

        $ids = array_map(
            static fn (array $r): int => (int) $r['id'],
            is_array($respuesta) && isset($respuesta[0]) ? $respuesta : ($respuesta['requisitos'] ?? [])
        );

        $this->assertContains($marca, $ids,
            'No devolvió el requisito del otro año: ya no obedece al `year_id` del cuerpo.');
    }
}
