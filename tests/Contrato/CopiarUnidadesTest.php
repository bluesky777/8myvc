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

    /**
     * **§9.4: copiar se lleva TAMBIÉN las unidades del independiente.**
     *
     * `unidades_ids` la arma el front desde la pantalla de estructura, que enseña
     * la del grupo: las de un marcado no están en esa lista y **nadie las echa de
     * menos hasta abrir su boletín**. Si copiar no las trae, el periodo nuevo
     * empieza con él sin una sola unidad, su definitiva sale 0 y no hay ningún
     * error — la §9.1 entrando por la puerta de copiar.
     *
     * El caso hay que **construirlo**: con nadie marcado no existe ninguna unidad
     * con dueño, así que la forma correcta y la incorrecta dan el mismo verde.
     * Comprobado en rojo contra el código viejo, que copiaba sólo la lista pedida.
     */
    public function test_copiar_se_lleva_las_unidades_del_independiente(): void
    {
        $c = $this->contexto();

        DB::table('periodos')->whereIn('id', [$c->origen, $c->destino])
            ->update(['profes_pueden_editar_notas' => 1]);

        // Va aparte **en el destino**, que es el periodo que decide.
        $this->marcarIndependiente($c->alumno_id, $c->destino);
        $this->unidadDe($c, $c->origen, $c->alumno_id);

        // **Dos del grupo y UNA suya**, y no una y una: con los dos lados valiendo
        // lo mismo, contar el conjunto contrario da el mismo número y el test pasa
        // en verde con la forma ingenua. Lo levantó el lote A esta misma noche.
        $otraDelGrupo = $this->unidadDe($c, $c->origen, null);

        $antesSuyas = $this->unidadesDe($c->asignatura_id, $c->destino, $c->alumno_id);
        $antesDelGrupo = $this->unidadesDe($c->asignatura_id, $c->destino, null);

        $r = $this->withToken($c->token)->putJson('/api/periodos/copiar',
            ['unidades_ids' => [$c->unidad_id, $otraDelGrupo]] + $this->cuerpo($c));

        $r->assertStatus(200);

        $this->assertSame($antesSuyas + 1,
            $this->unidadesDe($c->asignatura_id, $c->destino, $c->alumno_id),
            'El periodo nuevo empezó sin las unidades del independiente: su definitiva '
            .'va a salir 0 y nadie recibe un error.');
        $this->assertSame($antesDelGrupo + 2,
            $this->unidadesDe($c->asignatura_id, $c->destino, null),
            'Las del grupo no llegaron enteras, o llegó de más una que no es suya.');

        // El desglose de la respuesta: dos son de la lista que mandó el front y una
        // la puso el backend. Si el reparto se invierte, los números lo dicen.
        $this->assertSame(2, (int) $r->json('unidades_copiadas'));
        $this->assertSame(1, (int) $r->json('unidades_de_independientes_copiadas'));
    }

    /**
     * Y **sólo** las de quien siga marcado en el destino, que es la otra mitad.
     *
     * La marca es por periodo desde el 31 ago 2026 (decisión 7): un alumno que fue
     * aparte en el 1 y vuelve con el grupo en el 2 no se lleva sus unidades al 2, o
     * el segundo periodo le saldría aparte sin que nadie lo hubiera pedido.
     *
     * **Éste no se pone rojo contra el código viejo** —que no copiaba ninguna con
     * dueño y por tanto acierta por no hacer nada— y aun así se escribe: lo que
     * fija es que el arreglo mire el destino y no el origen, que es el único sitio
     * donde una implementación razonable se equivoca.
     */
    public function test_no_copia_las_del_que_ya_no_va_aparte_en_el_destino(): void
    {
        $c = $this->contexto();

        DB::table('periodos')->whereIn('id', [$c->origen, $c->destino])
            ->update(['profes_pueden_editar_notas' => 1]);

        $this->marcarIndependiente($c->alumno_id, $c->origen);
        $this->marcarIndependiente($c->alumno_id, $c->destino, aplica: false);
        $this->unidadDe($c, $c->origen, $c->alumno_id);
        $otraDelGrupo = $this->unidadDe($c, $c->origen, null);

        $antesSuyas = $this->unidadesDe($c->asignatura_id, $c->destino, $c->alumno_id);
        $antesDelGrupo = $this->unidadesDe($c->asignatura_id, $c->destino, null);

        $r = $this->withToken($c->token)->putJson('/api/periodos/copiar',
            ['unidades_ids' => [$c->unidad_id, $otraDelGrupo]] + $this->cuerpo($c));

        $r->assertStatus(200);

        $this->assertSame($antesSuyas,
            $this->unidadesDe($c->asignatura_id, $c->destino, $c->alumno_id),
            'Se copiaron las unidades de un alumno que en el destino va con el grupo: '
            .'el segundo periodo le sale aparte sin que nadie lo pidiera.');
        // Y las del grupo sí llegan: sin esto, un arreglo que no copie NADA pasaría.
        $this->assertSame($antesDelGrupo + 2,
            $this->unidadesDe($c->asignatura_id, $c->destino, null),
            'Dejaron de copiarse también las del grupo, y entonces esto no mide nada.');
        $this->assertSame(0, (int) $r->json('unidades_de_independientes_copiadas'));
    }

    /**
     * **La forma «de más»: el dueño viaja con la unidad.**
     *
     * Si el front pide explícitamente la unidad de un independiente, el código
     * viejo la copiaba **sin `alumno_id`** —`new Unidad` no lo tocaba—, o sea que
     * creaba una unidad **del grupo** con el contenido de uno solo. Ahí las
     * definitivas de los treinta se calculan con un reparto que no es el suyo y no
     * se mueve nada en el log.
     *
     * Se comprueba por las dos caras: que aparece una suya **y** que no aparece
     * ninguna del grupo. Rojo contra el código viejo por la segunda.
     */
    public function test_una_unidad_con_dueno_no_se_copia_como_del_grupo(): void
    {
        $c = $this->contexto();

        DB::table('periodos')->whereIn('id', [$c->origen, $c->destino])
            ->update(['profes_pueden_editar_notas' => 1]);

        $this->marcarIndependiente($c->alumno_id, $c->destino);
        $suya = $this->unidadDe($c, $c->origen, $c->alumno_id);

        $delGrupo = $this->unidadesDe($c->asignatura_id, $c->destino, null);
        $delAlumno = $this->unidadesDe($c->asignatura_id, $c->destino, $c->alumno_id);

        $r = $this->withToken($c->token)->putJson('/api/periodos/copiar',
            ['unidades_ids' => [$suya]] + $this->cuerpo($c));

        $r->assertStatus(200);

        $this->assertSame($delGrupo, $this->unidadesDe($c->asignatura_id, $c->destino, null),
            'La unidad de un independiente se copió como una del grupo: las definitivas '
            .'de los treinta salen con un reparto que no es el suyo.');
        $this->assertSame($delAlumno + 1,
            $this->unidadesDe($c->asignatura_id, $c->destino, $c->alumno_id),
            'La unidad pedida no llegó al destino con su dueño.');

        // La pidió el front, así que cuenta como suya y **no** como añadida por
        // nosotros: es la línea que separa los dos campos.
        $this->assertSame(1, (int) $r->json('unidades_copiadas'));
        $this->assertSame(0, (int) $r->json('unidades_de_independientes_copiadas'));
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

    /** Las de un boletín concreto: `null` es el del grupo. `<=>` y nunca `=`. */
    private function unidadesDe(int $asignaturaId, int $periodoId, ?int $alumnoId): int
    {
        return (int) DB::selectOne('SELECT COUNT(*) n FROM unidades
            WHERE asignatura_id = ? AND periodo_id = ? AND deleted_at IS NULL
              AND alumno_id <=> ?',
            [$asignaturaId, $periodoId, $alumnoId])->n;
    }

    /** Una unidad en un periodo, de un alumno o del grupo (`null`). El seed no trae. */
    private function unidadDe(object $c, int $periodoId, ?int $alumnoId): int
    {
        return DB::table('unidades')->insertGetId([
            'asignatura_id' => $c->asignatura_id,
            'periodo_id' => $periodoId,
            'alumno_id' => $alumnoId,
            'definicion' => $alumnoId === null ? 'Otra del grupo' : 'Unidad propia del independiente',
            'porcentaje' => 100,
            'orden' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
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

        // El `EXISTS` es de la noche del boletín independiente: los tres casos nuevos
        // necesitan **un alumno del grupo** para marcarlo, y una asignatura de un
        // grupo vacío los dejaría sin caso que construir. Si el seed ya daba una con
        // alumnos —lo hace—, no cambia cuál se elige ni mueve los tres de arriba.
        $asignatura = DB::selectOne('SELECT a.id, a.grupo_id FROM asignaturas a
            INNER JOIN grupos g ON g.id = a.grupo_id AND g.year_id = ? AND g.deleted_at IS NULL
            WHERE a.deleted_at IS NULL
              AND EXISTS (SELECT 1 FROM matriculas m WHERE m.grupo_id = g.id
                            AND m.deleted_at IS NULL AND m.estado IN ("MATR", "ASIS"))
            ORDER BY a.id LIMIT 1', [$periodo->year_id]);

        $this->assertNotNull($asignatura, 'El seed necesita una asignatura con alumnos en el año del profesor.');

        $alumno = DB::selectOne('SELECT m.alumno_id FROM matriculas m
            WHERE m.grupo_id = ? AND m.deleted_at IS NULL AND m.estado IN ("MATR", "ASIS")
            ORDER BY m.alumno_id LIMIT 1', [$asignatura->grupo_id]);

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
            'alumno_id' => (int) $alumno->alumno_id,
        ];
    }
}
