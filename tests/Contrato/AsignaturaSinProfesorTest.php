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
 * **Y la decisión que esta clase daba por pendiente se tomó el mismo día.** Aquí
 * estaba escrito que abrir la planilla sin profesor «espera a Joseth»; lo contestó
 * él y está aplicado —`Asignatura::detallada()` une `profesores` por `LEFT`—, así
 * que **ese caso ya no es 404: la planilla abre y devuelve 200**. Lo cubre
 * `PlanillaSinProfesorTest`, de la rama que trajo el cambio.
 *
 * **Sólo se vio al fusionar las dos ramas**, y por eso conviene dejarlo escrito: dos
 * sesiones escribieron un test cada una para el mismo caso con expectativas
 * **opuestas**, y las dos estaban en verde en su propio árbol. Ninguna podía verlo
 * sin la otra delante.
 *
 * De los cuatro casos de aquí quedan **tres**, y son los que no dependían de esa
 * decisión: «no existe», «no es de este año» y que el cuerpo del 404 no viaje vacío.
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

    /*
     * **Aquí vivía `test_sin_profesor_el_mensaje_lo_dice`, y se retira, no se
     * arregla.** Comprobaba que una asignatura sin profesor diera 404 con el mensaje
     * «Esa asignatura todavía no tiene profesor asignado». **Ese 404 ya no existe**:
     * desde que `detallada()` une `profesores` por `LEFT`, la planilla abre y
     * contesta 200 — que es lo que Joseth decidió el 2 sep 2026.
     *
     * No se reescribe para que espere 200 porque **eso ya está probado**, y mejor, en
     * `PlanillaSinProfesorTest`: aquélla es la clase de la rama que hizo el cambio y
     * comprueba lo que ahora importa —que la planilla abre y que el profesor viaja
     * nulo—. Dejar aquí una segunda versión del mismo caso sería mantener dos tests
     * para una regla, que es como se llega a que uno de los dos se quede atrás.
     *
     * Lo que sí queda escrito, porque es lo que costó verlo: **este test y el de la
     * otra rama afirmaban cosas opuestas del mismo caso y los dos estaban en verde**,
     * cada uno en su árbol. Sólo el merge lo enseñó.
     */

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

        // **Se cambió el caso que lo dispara, no la comprobación.** Antes usaba una
        // asignatura sin profesor, y ése dejó de ser 404 el 2 sep 2026. Lo que este
        // test comprueba —que el texto del 404 llegue al cliente— no dependía de aquel
        // caso: sirve cualquiera que siga siendo 404, y el más estable es el de la
        // asignatura que no existe, que no depende de ningún dato del seed.
        $inexistente = (int) DB::selectOne('SELECT MAX(id) + 1000 AS id FROM asignaturas')->id;

        $this->assertNotSame('', $this->mensajeDeLaRejilla($token, $inexistente),
            'Un 404 con `message` vacío deja al front pintando un rojo genérico: el texto se pierde.');
    }
}
