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
 * Los cuatro métodos son **el mismo código**, `findOrFail($id)` y `delete()`,
 * sin comprobar año, dueño ni estado de la urna. Pero hacen **tres cosas
 * distintas**, y la diferencia no está en el controlador: está en si el modelo
 * lleva el trait `SoftDeletes` y en si la tabla tiene la columna `deleted_at`.
 * Las dos condiciones se pusieron por separado y no cuadran entre sí:
 *
 * | Ruta | Trait en el modelo | Columna en la tabla | Qué pasa de verdad |
 * |---|---|---|---|
 * | `votaciones/destroy` | sí | sí | borrado lógico; los hijos sobreviven |
 * | `candidatos/destroy` | sí | sí | borrado lógico; los votos sobreviven |
 * | `aspiraciones/destroy` | **no** | sí | borrado **físico**, y la cascada del esquema se lleva candidatos y **votos** |
 * | `participantes/destroy` | sí | **no** | **500**: escribe en una columna que no existe |
 *
 * Nada de esto se ve leyendo el controlador, que es idéntico en los cuatro. Sale
 * de mirar el resultado, que es el criterio de `docs/migracion/03-tests.md`.
 *
 * **Estos tests fijan lo que hace hoy, no lo que debería hacer.** Son endpoints
 * vivos en los dieciséis colegios: el 500 de participantes y el borrado físico
 * de aspiraciones están documentados en `05-codigo-muerto-y-roto.md` §58, no
 * arreglados aquí. Arreglarlos cambia lo que ve una pantalla y eso lo decide
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
     * Una elección con todo dentro: aspiración, dos candidatos, un participante
     * y un voto emitido.
     *
     * El voto es la pieza que importa. Sin un voto de verdad en `vt_votos` las
     * cuatro rutas parecen hacer lo mismo —desaparece una fila— y **la cascada
     * no se ve**, que es justo lo que hay que medir aquí.
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

        $participanteId = DB::table('vt_participantes')->insertGetId([
            'votacion_id' => $votacionId,
            'grupo_profes_acudientes' => 'PROFESORES',
            'locked' => 0,
            'intentos' => 0,
        ]);

        $votoId = DB::table('vt_votos')->insertGetId([
            'user_id' => $quien->user_id,
            'candidato_id' => $candidatos[0],
            'locked' => 0,
        ]);

        return (object) [
            'votacion_id' => $votacionId,
            'aspiracion_id' => $aspiracionId,
            'candidatos' => $candidatos,
            'participante_id' => $participanteId,
            'voto_id' => $votoId,
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
     * El esquema declara `ON DELETE CASCADE` de `vt_aspiraciones`,
     * `vt_participantes` y `vt_candidatos` hacia arriba, así que la intención
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
        $this->assertSame(1, $this->vivas('vt_participantes', 'votacion_id', $eleccion->votacion_id),
            'El participante se fue con la votación.');
        $this->assertSame(2, $this->vivas('vt_candidatos', 'aspiracion_id', $eleccion->aspiracion_id),
            'Los candidatos se fueron con la votación.');
        $this->assertSame(1, $this->vivas('vt_votos', 'candidato_id', $eleccion->candidatos[0]),
            'El voto se fue con la votación.');
    }

    /**
     * Borrar una aspiración **destruye los votos de verdad**, sin papelera.
     *
     * `VtAspiracion` es el único de los cinco modelos que **no** lleva el trait
     * —lo importa en la cabecera y no lo usa dentro de la clase—, así que aquí
     * `delete()` sí manda un `DELETE` a MySQL. Y entonces la cascada del esquema
     * hace su trabajo: `vt_candidatos.aspiracion_id` cae, y con ella
     * `vt_votos.candidato_id`.
     *
     * O sea: **el escrutinio de una elección se puede borrar de forma
     * irreversible con una sola llamada**, aunque las tablas de candidatos y
     * votos tengan su `deleted_at` puesto y nadie lo vaya a rellenar. Es la
     * diferencia entre esta ruta y su vecina de arriba, y no se ve en el
     * controlador porque el controlador es idéntico.
     */
    public function test_borrar_una_aspiracion_es_fisico_y_arrastra_candidatos_y_votos(): void
    {
        $quien = $this->personal();
        $eleccion = $this->eleccionConUnVoto($quien);

        $this->withToken($quien->token)
            ->deleteJson("api/aspiraciones/destroy/{$eleccion->aspiracion_id}")
            ->assertOk();

        $aspiracion = DB::selectOne('SELECT id FROM vt_aspiraciones WHERE id = ?', [$eleccion->aspiracion_id]);
        $this->assertNull($aspiracion,
            'La aspiración sigue en la tabla: el borrado sería lógico y este test ya no describe lo que pasa.');

        $candidatos = DB::selectOne('SELECT COUNT(*) n FROM vt_candidatos WHERE aspiracion_id = ?',
            [$eleccion->aspiracion_id]);
        $this->assertSame(0, (int) $candidatos->n,
            'Los candidatos sobrevivieron: la cascada del esquema no disparó.');

        $voto = DB::selectOne('SELECT COUNT(*) n FROM vt_votos WHERE id = ?', [$eleccion->voto_id]);
        $this->assertSame(0, (int) $voto->n,
            'El voto sobrevivió. Si esto falla, alguien arregló la cascada — mira 05 §58 antes de tocar el test.');
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

    /**
     * Borrar un participante **responde 500**, y no borra nada.
     *
     * `VtParticipante` lleva `use SoftDeletes` dentro de la clase, pero
     * `vt_participantes` es la única de las cinco tablas `vt_*` **sin columna
     * `deleted_at`** —lo dice `database/schema/mysql-schema.sql`—. El trait
     * traduce el `delete()` a `UPDATE ... SET deleted_at = ?`, MySQL contesta
     * que esa columna no existe y la petición muere.
     *
     * El modelo lleva además `protected $softDelete = true`, que es la sintaxis
     * de Laravel 4 y hoy no la lee nadie: dos formas de pedir lo mismo, ninguna
     * de las dos comprobada contra el esquema. Como la lectura del censo va por
     * SQL crudo, el resto del módulo funciona y **el fallo solo asoma al
     * borrar** — por eso ha sobrevivido a la migración entera.
     *
     * Se fija el 500 en vez de arreglarlo porque el arreglo es una decisión: o
     * se le añade la columna a la tabla (migración, y el borrado pasa a ser
     * lógico) o se le quita el trait al modelo (y pasa a ser físico, con la
     * cascada del esquema detrás). Ver 05 §58.
     */
    public function test_borrar_un_participante_responde_500_y_no_borra_nada(): void
    {
        $quien = $this->personal();
        $eleccion = $this->eleccionConUnVoto($quien);

        $this->withToken($quien->token)
            ->deleteJson("api/participantes/destroy/{$eleccion->participante_id}")
            ->assertStatus(500);

        $participante = DB::selectOne('SELECT id FROM vt_participantes WHERE id = ?',
            [$eleccion->participante_id]);
        $this->assertNotNull($participante,
            'El participante se borró: alguien arregló la columna o el trait. Mira 05 §58 antes de tocar el test.');
    }
}
