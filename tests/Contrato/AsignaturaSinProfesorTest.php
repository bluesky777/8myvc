<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * El 404 de `Asignatura::detallada` dice **cuál** de las cuatro cosas pasó.
 *
 * Lo trajo el front el 2 sep 2026: diez asignaturas del año actual cuya planilla
 * no abre y cuyo error dice *«Esa asignatura no es de este año»* — que es **falso**,
 * porque sí es de ese año y lo que le falta es profesor. Es la §3.4 del
 * [10](../../docs/migracion/10-definitivas.md): el mismo error para dos fallos
 * distintos manda a investigar a la persona equivocada.
 *
 * **No es un caso raro y está medido**: 146 de 1219 asignaturas vivas del seed no
 * tienen profesor, con cero apuntando a uno inexistente o borrado. No es
 * corrupción: es el estado normal de una asignatura sin docente todavía, y son
 * **134 de 134 en el año siguiente**.
 *
 * Lo que estos casos **no** comprueban, porque no cambió: qué peticiones pasan.
 * Las cuatro siguen siendo 404. Que la planilla deba abrirse sin profesor es una
 * decisión de producto que espera a Joseth.
 */
class AsignaturaSinProfesorTest extends CasoDeContrato
{
    private function tokenDeSuperusuario(): string
    {
        $super = DB::selectOne('SELECT username FROM users
            WHERE is_superuser = 1 AND is_active = 1 AND deleted_at IS NULL ORDER BY id LIMIT 1');

        return $this->tokenDe($super->username);
    }

    /** Una asignatura del año actual, con su profesor puesto. */
    private function unaAsignatura(): object
    {
        $fila = DB::selectOne('SELECT a.id, a.profesor_id, g.year_id
            FROM asignaturas a
            INNER JOIN grupos g ON g.id = a.grupo_id AND g.deleted_at IS NULL
            INNER JOIN years y ON y.id = g.year_id AND y.actual = 1 AND y.deleted_at IS NULL
            WHERE a.deleted_at IS NULL AND a.profesor_id IS NOT NULL
            ORDER BY a.id LIMIT 1');

        $this->assertNotNull($fila, 'El seed necesita una asignatura del año actual con profesor.');

        return $fila;
    }

    /** El mensaje que devuelve la rejilla para esa asignatura, sea cual sea. */
    private function mensajeDeLaRejilla(string $token, int $asignaturaId): string
    {
        $r = $this->withToken($token)->putJson('/api/notas/detailed', ['asignatura_id' => $asignaturaId]);

        $r->assertStatus(404);

        return (string) $r->json('message');
    }

    /**
     * **El caso que trajo el front**: sin profesor, el mensaje habla de profesor y
     * no del año.
     */
    public function test_sin_profesor_el_mensaje_lo_dice(): void
    {
        $token = $this->tokenDeSuperusuario();
        $asignatura = $this->unaAsignatura();

        DB::update('UPDATE asignaturas SET profesor_id = NULL WHERE id = ?', [$asignatura->id]);

        $this->assertSame(
            'Esa asignatura todavía no tiene profesor asignado',
            $this->mensajeDeLaRejilla($token, (int) $asignatura->id),
            'El mensaje sigue hablando del año: manda a investigar el año y el grupo, que están bien.'
        );
    }

    /** Y el de siempre se conserva para el caso que sí es del año equivocado. */
    public function test_de_otro_anio_sigue_diciendo_lo_de_siempre(): void
    {
        $token = $this->tokenDeSuperusuario();

        $ajena = DB::selectOne('SELECT a.id FROM asignaturas a
            INNER JOIN grupos g ON g.id = a.grupo_id AND g.deleted_at IS NULL
            INNER JOIN years y ON y.id = g.year_id AND y.actual = 0 AND y.deleted_at IS NULL
            WHERE a.deleted_at IS NULL AND a.profesor_id IS NOT NULL
            ORDER BY a.id LIMIT 1');

        $this->assertNotNull($ajena, 'El seed necesita una asignatura de un año que no es el actual.');

        $this->assertSame(
            'Esa asignatura no es de este año',
            $this->mensajeDeLaRejilla($token, (int) $ajena->id),
            'La causa de siempre tiene que conservar su mensaje de siempre.'
        );
    }

    /** Una que no existe se distingue de las otras dos. */
    public function test_la_que_no_existe_lo_dice(): void
    {
        $token = $this->tokenDeSuperusuario();
        $inexistente = (int) DB::selectOne('SELECT COALESCE(MAX(id), 0) + 1000 AS id FROM asignaturas')->id;

        $this->assertSame('Esa asignatura no existe', $this->mensajeDeLaRejilla($token, $inexistente));
    }

    /**
     * **El cuerpo del 404 llega al cliente**, y esto es lo que hace que escribir un
     * mensaje mejor sirva de algo.
     *
     * Medido aparte con `APP_DEBUG=false`, que es como corren los quince colegios:
     * `abort(404, 'texto')` devuelve `{"message": "texto"}` entero. Sólo el
     * `abort(404)` **sin** texto sale vacío, y en `app/` no queda ninguno vivo. El
     * front excluía el cuerpo de todos los 404 dando por hecho lo contrario.
     */
    public function test_el_cuerpo_del_404_no_viene_vacio(): void
    {
        $token = $this->tokenDeSuperusuario();
        $asignatura = $this->unaAsignatura();

        DB::update('UPDATE asignaturas SET profesor_id = NULL WHERE id = ?', [$asignatura->id]);

        $this->assertNotSame('', $this->mensajeDeLaRejilla($token, (int) $asignatura->id),
            'Un 404 con `message` vacío deja al front pintando un rojo genérico: el texto se pierde.');
    }
}
