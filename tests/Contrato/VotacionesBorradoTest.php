<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * Qué se lleva por delante cada `destroy` de las votaciones, y de qué manera.
 *
 * Las cuatro rutas de borrado del módulo eran, el 22 ago 2026, **las únicas del
 * dominio que no miraba ningún test**: 29 de las 36 rutas `Vt*` ya tenían la
 * respuesta comprobada y las que faltaban eran justo los cuatro `destroy` más
 * tres de `votaciones`. Que lo no mirado sean los borrados no es casualidad
 * estadística — un `destroy` es lo más caro de probar a mano y lo único que no
 * se puede deshacer en producción.
 *
 * Los métodos eran **el mismo código**, `findOrFail($id)` y `delete()`, sin
 * comprobar año, dueño ni estado de la urna. Desde el rediseño (11 §8)
 * `votaciones/destroy` y `aspiraciones/destroy` pasan por
 * `VtVotacion::exigirAdministrable()` y `candidatos/destroy` sigue sin guard de
 * dueño. Pero el borrado en sí hace **cosas distintas** con el mismo `delete()`,
 * y la diferencia no está en el controlador: está en si el modelo
 * lleva el trait `SoftDeletes` y en si la tabla tiene la columna `deleted_at`.
 * Las dos condiciones se pusieron por separado y no cuadran entre sí:
 *
 * | Ruta | Trait en el modelo | Columna en la tabla | Qué pasa de verdad |
 * |---|---|---|---|
 * | `votaciones/destroy` | sí | sí | borrado lógico; los hijos sobreviven |
 * | `candidatos/destroy` | sí | sí | borrado lógico; los votos sobreviven |
 * | `aspiraciones/destroy` | **no** | sí | borrado **físico**, y la cascada del esquema se lleva candidatos y **votos** |
 *
 * **Se fue una fila, `participantes/destroy`** —el 500 que fijaba este fichero—,
 * con la tabla `vt_participantes` y las nueve rutas `participantes/*` en el
 * rediseño del 22 sep 2026 (`11-votaciones.md` §8, punto 1). Su test se borró con
 * ella: el conjunto de rutas lo vigila `RutasTest`.
 *
 * Nada de esto se ve leyendo el controlador, que es idéntico en los cuatro. Sale
 * de mirar el resultado, que es el criterio de `docs/migracion/03-tests.md`.
 *
 * **Estos tests fijan lo que hace hoy, no lo que debería hacer.** Son endpoints
 * vivos en los dieciséis colegios: el borrado físico de aspiraciones está
 * documentado en `05-codigo-muerto-y-roto.md` §58, no arreglado aquí. Arreglarlos cambia lo que ve una pantalla y eso lo decide
 * Joseth. Ver también `11-votaciones.md`.
 */
class VotacionesBorradoTest extends CasoDeContrato
{
    /**
     * Personal del colegio del año que tiene alumnos, que es el que sirve.
     *
     * Mismo motivo que en `VotacionesTest`: los candidatos han de ser alumnos
     * matriculados en el año de la votación, y los del seed están en los años 7
     * y 8. Partir del año del primer profesor deja la elección sin gente.
     */
    private function personal(): object
    {
        $grupo = $this->grupoConAlumnos();

        $usuario = DB::selectOne('SELECT u.id, u.username FROM users u
            INNER JOIN periodos p ON p.id = u.periodo_id AND p.deleted_at IS NULL
            WHERE u.tipo = "Usuario" AND u.is_active = 1 AND u.deleted_at IS NULL
              AND p.year_id = ? ORDER BY u.id LIMIT 1', [$grupo->year_id]);

        $this->assertNotNull($usuario, "El seed no tiene ningún Usuario en el año {$grupo->year_id}.");

        return (object) [
            'user_id' => (int) $usuario->id,
            'year_id' => (int) $grupo->year_id,
            'token' => $this->tokenDe($usuario->username),
        ];
    }

    /**
     * Una elección con todo dentro: aspiración, dos candidatos, un voto a un
     * candidato y un voto en blanco.
     *
     * El voto es la pieza que importa. Sin un voto de verdad en `vt_votos` las
     * rutas parecen hacer lo mismo —desaparece una fila— y **la cascada no se
     * ve**, que es justo lo que hay que medir aquí.
     *
     * Desde el rediseño (11 §8, punto 4) el voto lleva `votacion_id` y
     * `aspiracion_id` propios, los dos con `ON DELETE CASCADE` en el esquema
     * migrado, y el blanco es `candidato_id` NULL: por eso el blanco va aparte,
     * porque es el que sólo cuelga de la aspiración y no de un candidato. Y los
     * dos votos son de **personas distintas**, porque el índice único
     * `(votacion_id, aspiracion_id, user_id)` no deja votar dos veces el mismo cargo.
     */
    private function eleccionConUnVoto(object $quien): object
    {
        $votacionId = DB::table('vt_votaciones')->insertGetId([
            'user_id' => $quien->user_id,
            'year_id' => $quien->year_id,
            'nombre' => 'Elección para medir los borrados',
            'votan_profes' => 1,
            'votan_acudientes' => 1,
            'locked' => 0,
            'actual' => 0,
            'in_action' => 1,
            'can_see_results' => 0,
        ]);

        $aspiracionId = DB::table('vt_aspiraciones')->insertGetId([
            'votacion_id' => $votacionId,
            'aspiracion' => 'PERSONERO',
        ]);

        $personas = DB::select('SELECT DISTINCT a.user_id FROM alumnos a
            INNER JOIN users u ON u.id = a.user_id AND u.deleted_at IS NULL
            INNER JOIN matriculas m ON m.alumno_id = a.id AND m.deleted_at IS NULL
                AND m.estado IN ("MATR","ASIS","PREM")
            INNER JOIN grupos g ON g.id = m.grupo_id AND g.year_id = ?
            WHERE a.deleted_at IS NULL
            ORDER BY a.user_id LIMIT 2', [$quien->year_id]);

        $this->assertCount(2, $personas,
            "El seed no tiene dos alumnos matriculados en el año {$quien->year_id} para poner de candidatos.");

        $candidatos = [];
        foreach ($personas as $numero => $persona) {
            $candidatos[] = DB::table('vt_candidatos')->insertGetId([
                'user_id' => $persona->user_id,
                'aspiracion_id' => $aspiracionId,
                'plancha' => $numero + 1,
                'numero' => $numero + 1,
                'locked' => 0,
            ]);
        }

        $votoId = DB::table('vt_votos')->insertGetId([
            'user_id' => $quien->user_id,
            'votacion_id' => $votacionId,
            'aspiracion_id' => $aspiracionId,
            'candidato_id' => $candidatos[0],
            'locked' => 0,
        ]);

        $blancoId = DB::table('vt_votos')->insertGetId([
            'user_id' => $personas[0]->user_id,
            'votacion_id' => $votacionId,
            'aspiracion_id' => $aspiracionId,
            'candidato_id' => null,
            'locked' => 0,
        ]);

        return (object) [
            'votacion_id' => $votacionId,
            'aspiracion_id' => $aspiracionId,
            'candidatos' => $candidatos,
            'voto_id' => $votoId,
            'blanco_id' => $blancoId,
        ];
    }

    /** Cuántas filas vivas quedan, contando a mano para no heredar el filtro del modelo. */
    private function vivas(string $tabla, string $columna, int $valor): int
    {
        $tieneBorradoLogico = ! empty(DB::select("SHOW COLUMNS FROM `{$tabla}` LIKE 'deleted_at'"));
        $sql = "SELECT COUNT(*) n FROM `{$tabla}` WHERE `{$columna}` = ?"
            .($tieneBorradoLogico ? ' AND deleted_at IS NULL' : '');

        return (int) DB::selectOne($sql, [$valor])->n;
    }

    /**
     * Borrar la votación entera **no toca a sus hijos**, y esa es la sorpresa.
     *
     * El esquema migrado declara `ON DELETE CASCADE` hacia `vt_votaciones` desde
     * `vt_aspiraciones`, `vt_votos`, `vt_mesas`, `vt_actas` y `vt_grupos_votacion`
     * (medido en `information_schema` el 23 sep 2026), así que la intención
     * escrita en la base era que borrar una votación se lo llevara todo. Pero
     * `VtVotacion` sí lleva `SoftDeletes`: el `DELETE` nunca llega a MySQL —es un
     * `UPDATE deleted_at`— y **la cascada del esquema no dispara**. La fila padre
     * se queda, invisible para el modelo y visible para cualquier consulta cruda
     * de las 990 que hay en el proyecto.
     */
    public function test_borrar_la_votacion_es_logico_y_deja_vivos_a_sus_hijos(): void
    {
        $quien = $this->personal();
        $eleccion = $this->eleccionConUnVoto($quien);

        $this->withToken($quien->token)
            ->deleteJson("api/votaciones/destroy/{$eleccion->votacion_id}")
            ->assertOk();

        $votacion = DB::selectOne('SELECT deleted_at FROM vt_votaciones WHERE id = ?', [$eleccion->votacion_id]);
        $this->assertNotNull($votacion, 'La fila desapareció: el borrado sería físico, no lógico.');
        $this->assertNotNull($votacion->deleted_at, 'La votación sigue viva: no se marcó como borrada.');

        $this->assertSame(1, $this->vivas('vt_aspiraciones', 'votacion_id', $eleccion->votacion_id),
            'La aspiración se fue con la votación: la cascada del esquema habría disparado.');
        $this->assertSame(2, $this->vivas('vt_candidatos', 'aspiracion_id', $eleccion->aspiracion_id),
            'Los candidatos se fueron con la votación.');
        $this->assertSame(2, $this->vivas('vt_votos', 'votacion_id', $eleccion->votacion_id),
            'Los votos se fueron con la votación.');
    }

    /**
     * Un cargo con votos **no se borra**: 409, y no cae nada.
     *
     * `VtAspiracion` no lleva `SoftDeletes`, así que su `delete()` es un `DELETE`
     * de verdad, y el esquema cuelga de `vt_aspiraciones` en cascada los
     * candidatos, `vt_votos.aspiracion_id` —el voto en blanco incluido, que no
     * tiene candidato— y `vt_acta_votos.aspiracion_id`. **Hasta el 24 sep 2026 esta
     * ruta borraba así el escrutinio del cargo con una sola llamada** (05 §58.1,
     * agravado por la FK del rediseño, 11 §8 punto 4). Ahora contesta 409 en
     * cuanto hay un voto, digital o de papel.
     */
    public function test_un_cargo_con_votos_no_se_borra(): void
    {
        $quien = $this->personal();
        $eleccion = $this->eleccionConUnVoto($quien);

        $this->withToken($quien->token)
            ->deleteJson("api/aspiraciones/destroy/{$eleccion->aspiracion_id}")
            ->assertStatus(409);

        $this->assertSame(1, $this->vivas('vt_aspiraciones', 'id', $eleccion->aspiracion_id));
        $this->assertSame(2, $this->vivas('vt_candidatos', 'aspiracion_id', $eleccion->aspiracion_id));
        $this->assertSame(2, (int) DB::selectOne('SELECT COUNT(*) n FROM vt_votos WHERE id IN (?, ?)',
            [$eleccion->voto_id, $eleccion->blanco_id])->n, 'Un 409 no se lleva ningún voto.');
    }

    /**
     * Y las cifras de un acta de papel cuentan igual que un voto digital: sin
     * esto, borrar el cargo se llevaría el acta por `vt_acta_votos.aspiracion_id`,
     * **firmada o no**, y un acta firmada es justo lo que no se puede deshacer.
     */
    public function test_un_cargo_con_cifras_de_acta_no_se_borra(): void
    {
        $quien = $this->personal();
        $eleccion = $this->eleccionConUnVoto($quien);

        DB::table('vt_votos')->whereIn('id', [$eleccion->voto_id, $eleccion->blanco_id])->delete();

        $actaId = DB::table('vt_actas')->insertGetId([
            'votacion_id' => $eleccion->votacion_id,
            'grupo_id' => $this->grupoConAlumnos()->id,
            'conto_user_id' => $quien->user_id,
            'firmada_por' => $quien->user_id,
            'firmada_en' => '2026-09-24 10:00:00',
        ]);
        $cifraId = DB::table('vt_acta_votos')->insertGetId([
            'acta_id' => $actaId,
            'aspiracion_id' => $eleccion->aspiracion_id,
            'candidato_id' => $eleccion->candidatos[0],
            'cantidad' => 12,
        ]);

        $this->withToken($quien->token)
            ->deleteJson("api/aspiraciones/destroy/{$eleccion->aspiracion_id}")
            ->assertStatus(409);

        $this->assertSame(1, $this->vivas('vt_aspiraciones', 'id', $eleccion->aspiracion_id));
        $this->assertNotNull(DB::selectOne('SELECT id FROM vt_acta_votos WHERE id = ?', [$cifraId]),
            'La cifra del acta firmada se fue en cascada.');
    }

    /**
     * Sin votos ni cifras, el cargo **sí** se borra, y de verdad: es el caso de
     * montar la elección y equivocarse de cargo. La cascada se lleva sus
     * candidatos, que tampoco tienen a nadie que los haya votado.
     */
    public function test_un_cargo_sin_votos_se_borra_con_sus_candidatos(): void
    {
        $quien = $this->personal();
        $eleccion = $this->eleccionConUnVoto($quien);

        DB::table('vt_votos')->whereIn('id', [$eleccion->voto_id, $eleccion->blanco_id])->delete();

        $this->withToken($quien->token)
            ->deleteJson("api/aspiraciones/destroy/{$eleccion->aspiracion_id}")
            ->assertOk();

        $this->assertNull(DB::selectOne('SELECT id FROM vt_aspiraciones WHERE id = ?', [$eleccion->aspiracion_id]),
            'El borrado del cargo es físico; si queda la fila, alguien le puso SoftDeletes y este test ya no describe lo que pasa.');
        $this->assertSame(0, (int) DB::selectOne('SELECT COUNT(*) n FROM vt_candidatos WHERE aspiracion_id = ?',
            [$eleccion->aspiracion_id])->n, 'Los candidatos sobrevivieron: la cascada del esquema no disparó.');
    }

    /**
     * Quitar un candidato de la elección de otro es **403**, y el candidato sigue.
     *
     * Hasta el 24 sep 2026 `candidatos/destroy` era el único borrado del módulo
     * sin `exigirAdministrable()`: cualquiera de las cuentas de `auth.personal`
     * lo hacía. El control es el test de abajo, donde el dueño sí puede.
     */
    public function test_el_personal_no_quita_un_candidato_de_la_eleccion_de_otro(): void
    {
        $duenio = $this->personal();
        $eleccion = $this->eleccionConUnVoto($duenio);

        $otro = DB::selectOne('SELECT u.id, u.username FROM users u
            WHERE u.tipo = "Usuario" AND u.is_active = 1 AND u.deleted_at IS NULL
              AND u.is_superuser = 0 AND u.id <> ? ORDER BY u.id LIMIT 1', [$duenio->user_id]);
        $this->assertNotNull($otro, 'El seed no tiene otro Usuario que no sea superusuario.');

        $this->withToken($this->tokenDe($otro->username))
            ->deleteJson("api/candidatos/destroy/{$eleccion->candidatos[0]}")
            ->assertStatus(403);

        $this->assertSame(2, $this->vivas('vt_candidatos', 'aspiracion_id', $eleccion->aspiracion_id),
            'Un 403 no quita a nadie de la papeleta.');
    }

    /**
     * Borrar un candidato es lógico, y **el voto que le dieron se queda**.
     *
     * `VtCandidato` sí lleva el trait, así que la cascada hacia `vt_votos` no
     * dispara. El voto sobrevive apuntando a un candidato que ya no está en
     * ninguna papeleta: no se pierde el escrutinio, pero queda un voto que no
     * suma a nadie. Es lo contrario del caso de la aspiración, con el mismo
     * código de controlador.
     */
    public function test_borrar_un_candidato_es_logico_y_su_voto_sigue_ahi(): void
    {
        $quien = $this->personal();
        $eleccion = $this->eleccionConUnVoto($quien);

        $this->withToken($quien->token)
            ->deleteJson("api/candidatos/destroy/{$eleccion->candidatos[0]}")
            ->assertOk();

        $candidato = DB::selectOne('SELECT deleted_at FROM vt_candidatos WHERE id = ?', [$eleccion->candidatos[0]]);
        $this->assertNotNull($candidato, 'El candidato desapareció: el borrado sería físico.');
        $this->assertNotNull($candidato->deleted_at, 'El candidato sigue vivo: no se marcó como borrado.');

        $voto = DB::selectOne('SELECT deleted_at FROM vt_votos WHERE id = ?', [$eleccion->voto_id]);
        $this->assertNotNull($voto, 'El voto se borró en cascada con su candidato.');
        $this->assertNull($voto->deleted_at, 'El voto quedó marcado como borrado, y nadie lo marcó.');
    }
}
