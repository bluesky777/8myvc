<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * Copiar unidades de un periodo a otro, que era la puerta de atrás del candado.
 *
 * `PUT periodos/copiar` crea unidades, subunidades y —si se lo piden— **notas** en
 * el periodo que diga el cuerpo. Las cuatro rutas que hacen eso mismo de una en una
 * —`unidades/store`, `unidades/update`, `subunidades/store`, `subunidades/update`—
 * piden permiso desde la §27. Ésta no lo pedía: un profesor no podía crear una
 * unidad en un periodo cerrado a mano, y sí copiar treinta de golpe.
 *
 * **Lo encontró una herramienta que estaba mal**, y eso es la mitad de lo que hay
 * que contar: `tools/escrituras-en-las-notas.py` se escribió esa misma mañana para
 * la §77 y sólo miraba SQL crudo. Aquí las notas se escriben con `new Nota` y
 * `save()`, así que no salió en la lista. Ver 05 §80.
 */
class CopiarUnidadesTest extends CasoDeContrato
{
    /**
     * Con el periodo destino cerrado no copia, y no deja nada a medias.
     *
     * Lo que se comprueba no es el 400 sino **que no queda ninguna unidad nueva**:
     * el método escribe dentro de un `foreach` sin transacción, así que un guard mal
     * puesto —después del primer `save()` en vez de antes— pasaría un test que
     * mirase sólo el código de respuesta y dejaría la rejilla del destino a medias.
     */
    public function test_con_el_periodo_destino_cerrado_no_copia_nada(): void
    {
        $c = $this->contexto();

        DB::table('periodos')->where('id', $c->destino)->update(['profes_pueden_editar_notas' => 0]);
        DB::table('periodos')->where('id', $c->origen)->update(['profes_pueden_editar_notas' => 1]);

        $antes = $this->unidadesEn($c->asignatura_id, $c->destino);

        $this->withToken($c->token)->putJson('/api/periodos/copiar', $this->cuerpo($c))
            ->assertStatus(400);

        $this->assertSame($antes, $this->unidadesEn($c->asignatura_id, $c->destino),
            'Con el destino cerrado se copiaron unidades igual.');
    }

    /**
     * El permiso es del **destino**, no del origen: del origen sólo se lee.
     *
     * Es la mitad que distingue el arreglo de un candado puesto de cualquier
     * manera. Copiar de un periodo cerrado a uno abierto tiene que funcionar —es lo
     * que hace un profesor en enero, traerse la estructura del año pasado— y un
     * guard sobre el periodo del token, o sobre el origen, lo apagaría.
     */
    public function test_copiar_desde_un_periodo_cerrado_a_uno_abierto_si_funciona(): void
    {
        $c = $this->contexto();

        DB::table('periodos')->where('id', $c->origen)->update(['profes_pueden_editar_notas' => 0]);
        DB::table('periodos')->where('id', $c->destino)->update(['profes_pueden_editar_notas' => 1]);

        $antes = $this->unidadesEn($c->asignatura_id, $c->destino);

        $r = $this->withToken($c->token)->putJson('/api/periodos/copiar', $this->cuerpo($c));

        $r->assertStatus(200);

        $this->assertSame($antes + 1, $this->unidadesEn($c->asignatura_id, $c->destino),
            'Copiar desde un periodo cerrado dejó de funcionar, y eso apaga la pantalla de enero.');
    }

    /**
     * Copiar las notas exige que sea el mismo grupo, y eso ya estaba y se fija.
     *
     * `if ($copiar_notas and $grupo_to_id == $grupo_from_id)` es una guarda que puso
     * el autor y que tiene sentido: las notas son de un alumno, y copiarlas a otro
     * grupo las pondría en alumnos que no las sacaron. Se fija porque **es la única
     * comprobación que este método tenía**, y porque un refactor que la quite no
     * rompe nada visible — hasta que alguien copia entre grupos.
     */
    public function test_las_notas_no_se_copian_entre_grupos_distintos(): void
    {
        $c = $this->contexto();

        DB::table('periodos')->whereIn('id', [$c->origen, $c->destino])
            ->update(['profes_pueden_editar_notas' => 1]);

        $antes = (int) DB::selectOne('SELECT COUNT(*) n FROM notas')->n;

        $r = $this->withToken($c->token)->putJson('/api/periodos/copiar',
            ['copiar_notas' => true, 'grupo_to_id' => $c->grupo_id + 1000] + $this->cuerpo($c));

        $r->assertStatus(200);

        $this->assertSame(0, (int) $r->json('notas_copiadas'),
            'Se copiaron notas a un grupo distinto: son de un alumno y acabarían en otro.');
        $this->assertSame($antes, (int) DB::selectOne('SELECT COUNT(*) n FROM notas')->n,
            'El recuento dijo cero y aun así se escribieron notas.');
    }

    // ---------------------------------------------------------------- ayudas

    private function cuerpo(object $c): array
    {
        return [
            'grupo_from_id' => $c->grupo_id,
            'grupo_to_id' => $c->grupo_id,
            'asignatura_to_id' => $c->asignatura_id,
            'copiar_subunidades' => true,
            'copiar_notas' => false,
            'periodo_from_id' => $c->origen,
            'periodo_to_id' => $c->destino,
            'unidades_ids' => [$c->unidad_id],
        ];
    }

    private function unidadesEn(int $asignaturaId, int $periodoId): int
    {
        return (int) DB::selectOne('SELECT COUNT(*) n FROM unidades
            WHERE asignatura_id = ? AND periodo_id = ? AND deleted_at IS NULL',
            [$asignaturaId, $periodoId])->n;
    }

    /** Un profesor, su asignatura, y una unidad suya en un periodo para copiar a otro. */
    private function contexto(): object
    {
        $usuario = $this->usuarioDeTipo('Profesor');
        $token = $this->tokenDe($usuario->username);

        $periodo = DB::selectOne('SELECT p.id, p.year_id FROM periodos p
            INNER JOIN users u ON u.periodo_id = p.id WHERE u.id = ?', [$usuario->id]);

        $otro = DB::selectOne('SELECT id FROM periodos
            WHERE year_id = ? AND id <> ? AND deleted_at IS NULL ORDER BY id LIMIT 1',
            [$periodo->year_id, $periodo->id]);

        $this->assertNotNull($otro, 'El seed necesita dos periodos en el año del profesor.');

        $asignatura = DB::selectOne('SELECT a.id, a.grupo_id FROM asignaturas a
            INNER JOIN grupos g ON g.id = a.grupo_id AND g.year_id = ? AND g.deleted_at IS NULL
            WHERE a.deleted_at IS NULL ORDER BY a.id LIMIT 1', [$periodo->year_id]);

        $this->assertNotNull($asignatura, 'El seed necesita una asignatura en el año del profesor.');

        // La unidad de origen se monta aquí: el seed llega sin ninguna en este
        // periodo, y copiar una lista vacía escribe cero filas y pasa los tres tests
        // sin medir nada. Es el seed vacío, que va por diez.
        $unidadId = DB::table('unidades')->insertGetId([
            'asignatura_id' => $asignatura->id,
            'periodo_id' => $periodo->id,
            'definicion' => 'Unidad de origen',
            'porcentaje' => 100,
            'orden' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (object) [
            'token' => $token,
            'origen' => (int) $periodo->id,
            'destino' => (int) $otro->id,
            'asignatura_id' => (int) $asignatura->id,
            'grupo_id' => (int) $asignatura->grupo_id,
            'unidad_id' => $unidadId,
        ];
    }
}
