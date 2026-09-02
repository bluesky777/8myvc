<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/**
 * **Una asignatura sin docente asignado abre su planilla.**
 *
 * `Asignatura::detallada()` unía `profesores` por `INNER JOIN`. Como
 * `asignaturas.profesor_id` es NULLABLE, una materia todavía sin docente no devolvía
 * ninguna fila, y el `abort(404)` de ese método se disparaba diciendo lo que no era:
 * «Esa asignatura no es de este año». Medido sobre la base de desarrollo el 2 sep
 * 2026: de **1219 asignaturas vivas, 146 sin `profesor_id`** —2 en 2019, **10 en el
 * año actual** y **las 134 de 134 de 2026**—, con cero apuntando a un profesor
 * inexistente y cero a uno borrado. No es corrupción: es cómo empieza un año.
 *
 * ## Por qué el escenario se construye aquí y no se busca en el seed
 *
 * Porque **el seed no tiene ninguna**, y un test que arranque con
 * `SELECT ... WHERE profesor_id IS NULL LIMIT 1` se saltaría solo el día que el seed
 * cambie —o peor, hoy—: `assertNotNull` sobre una fila que no existe convierte el
 * caso en un error de andamiaje que alguien marcará como `skip`. Aquí se toma una
 * asignatura que **sí** funciona, se le quita el profesor dentro de la transacción del
 * test y se comprueba que sigue abriendo. Así el escenario es el mismo cada vez y la
 * única diferencia con el caso verde de al lado es exactamente la que se está midiendo.
 *
 * **Comprobado en rojo contra el código de antes.** Con el `inner join profesores`
 * restaurado los tres casos fallan con 404; es lo que separa un test que mide el
 * arreglo de uno escrito después para acompañarlo.
 */
class PlanillaSinProfesorTest extends CasoDeContrato
{
    /**
     * Una asignatura del seed que hoy abre bien: con profesor, unidades del grupo en
     * el periodo actual y subunidades dentro.
     *
     * Es la misma forma de elegir que `PlanillaSinIndependientesTest::contexto()` y por
     * el mismo motivo: `putDetailed` sólo recorre las unidades del periodo del CONTEXTO,
     * así que elegir la asignatura por un lado y el periodo por otro deja la rejilla
     * vacía y el test pasa sin ejecutar nada.
     */
    private function contexto(): object
    {
        $fila = DB::selectOne('SELECT a.id AS asignatura_id, a.grupo_id, a.profesor_id,
                g.year_id, un.periodo_id
            FROM asignaturas a
            INNER JOIN profesores p ON p.id = a.profesor_id AND p.deleted_at IS NULL
            INNER JOIN grupos g ON g.id = a.grupo_id AND g.deleted_at IS NULL
            INNER JOIN unidades un ON un.asignatura_id = a.id AND un.deleted_at IS NULL
                AND un.alumno_id IS NULL
            INNER JOIN periodos per ON per.id = un.periodo_id AND per.actual = 1
                AND per.year_id = g.year_id AND per.deleted_at IS NULL
            INNER JOIN years y ON y.id = g.year_id AND y.actual = 1 AND y.deleted_at IS NULL
            INNER JOIN subunidades s ON s.unidad_id = un.id AND s.deleted_at IS NULL
            INNER JOIN matriculas m ON m.grupo_id = g.id AND m.deleted_at IS NULL
                AND m.estado IN ("MATR","ASIS","PREM")
            WHERE a.deleted_at IS NULL
            ORDER BY a.id LIMIT 1');

        $this->assertNotNull($fila,
            'El seed necesita una asignatura con profesor, unidades del grupo en el periodo '
            .'actual, subunidades y alumnos matriculados.');

        return $fila;
    }

    /** La planilla tal como la pide la pantalla del docente. */
    private function planilla(object $ctx, string $token): TestResponse
    {
        return $this->putJson('/api/notas/detailed', [
            'asignatura_id' => $ctx->asignatura_id,
        ], ['Authorization' => 'Bearer '.$token]);
    }

    // ------------------------------------------------------------------ (1)

    /**
     * **El caso que estaba roto**: sin `profesor_id`, la planilla abre y trae alumnos.
     *
     * Las dos mitades importan y por eso van juntas en un solo caso. Un 200 con
     * `alumnos: []` sería igual de inservible que el 404 —el docente abre la pantalla y
     * no hay a quién calificar—, y además pasaría inadvertido: la lista sale de
     * `Grupo::alumnos($asignatura->grupo_id)`, o sea **del grupo y no del profesor**,
     * que es justo el motivo por el que quitar el docente no puede vaciarla.
     */
    public function test_una_asignatura_sin_profesor_abre_la_planilla_con_sus_alumnos(): void
    {
        $ctx = $this->contexto();
        $token = $this->tokenDelPersonalDe((int) $ctx->year_id);

        // Antes de tocar nada: esta misma asignatura abre. Sin esta línea, un 200 al
        // final no distingue «lo arreglé» de «este escenario nunca estuvo roto».
        $this->planilla($ctx, $token)->assertStatus(200);

        DB::update('UPDATE asignaturas SET profesor_id = NULL WHERE id = ?', [$ctx->asignatura_id]);

        $r = $this->planilla($ctx, $token);

        $r->assertStatus(200);

        $cuerpo = $r->json();

        $this->assertArrayHasKey('alumnos', $cuerpo);
        $this->assertNotEmpty($cuerpo['alumnos'],
            'La planilla abrió pero sin alumnos. Salen del grupo, no del profesor: '
            .'una lista vacía aquí es tan inservible como el 404 que esto viene a cerrar.');
    }

    // ------------------------------------------------------------------ (2)

    /**
     * **Y el JSON dice que no hay profesor, en vez de mentir o desaparecer.**
     *
     * Este caso fija el contrato que se abre con el `LEFT`: los tres campos del docente
     * pasan a poder ser `null` donde antes no lo eran nunca. Es lo que tienen que
     * aprender los clientes —las cuatro plantillas que imprimen el nombre sin
     * comprobarlo van en la rama de `myvc-front-11`, a desplegarse a la vez que esto—,
     * y fijarlo aquí es lo que hace que un cambio futuro a `a.profesor_id` en el SELECT
     * no pase callado: ese campo viaja **dos veces** en la consulta y con PDO gana el
     * último, así que hoy vale `p.id`. Si alguien quita el duplicado sin pensarlo,
     * `profesor_id` volvería a traer un id con los nombres vacíos al lado, que es como
     * una plantilla acaba imprimiendo «Prof.: » sin que nadie sepa por qué.
     */
    public function test_el_json_trae_el_profesor_en_null_y_no_un_id_sin_nombre(): void
    {
        $ctx = $this->contexto();
        $token = $this->tokenDelPersonalDe((int) $ctx->year_id);

        DB::update('UPDATE asignaturas SET profesor_id = NULL WHERE id = ?', [$ctx->asignatura_id]);

        $asignatura = $this->planilla($ctx, $token)->assertStatus(200)->json('asignatura');

        $this->assertNotNull($asignatura, 'La respuesta ya no trae la asignatura.');
        // Las claves siguen viajando: lo que cambia es su valor, no la forma. Un
        // `?? null` aquí dejaría pasar que desaparecieran del JSON, que para el front
        // es otro fallo distinto y peor —`undefined` en vez de `null`—.
        $this->assertArrayHasKey('profesor_id', $asignatura);
        $this->assertArrayHasKey('nombres_profesor', $asignatura);
        $this->assertArrayHasKey('apellidos_profesor', $asignatura);

        $this->assertNull($asignatura['profesor_id']);
        $this->assertNull($asignatura['nombres_profesor']);
        $this->assertNull($asignatura['apellidos_profesor']);

        // Y lo que NO cambia: el grupo sigue ahí. Es lo que usan los cinco llamadores
        // de `detallada()` —ninguno lee el profesor— y lo que haría fallar de verdad a
        // esta pantalla si el `LEFT` se hubiera puesto en el join equivocado.
        $this->assertSame((int) $ctx->grupo_id, (int) $asignatura['grupo_id']);
    }

    // ------------------------------------------------------------------ (3)

    /**
     * **Un docente borrado deja la asignatura sin profesor, no sin asignatura.**
     *
     * Es la segunda mitad del cambio: al `ON` se le añadió `p.deleted_at is null`, que
     * el resto del fichero ya hacía y esta línea no. **Con `INNER` no se habría podido
     * añadir sin más**, porque habría hecho desaparecer la asignatura entera —un 404
     * nuevo cada vez que el colegio borra a un docente—; es el `LEFT` lo que lo vuelve
     * inocuo, y por eso las dos mitades van en el mismo commit y este caso las ata.
     *
     * En desarrollo hay **una** fila así (asignatura 187, de 2018, en la papelera), y no
     * se usa como escenario a propósito: está borrada y en un año que ningún token
     * mira, así que el test la construye sobre una asignatura viva.
     */
    public function test_un_profesor_borrado_no_hace_desaparecer_la_asignatura(): void
    {
        $ctx = $this->contexto();
        $token = $this->tokenDelPersonalDe((int) $ctx->year_id);

        DB::update('UPDATE profesores SET deleted_at = NOW() WHERE id = ?', [$ctx->profesor_id]);

        $r = $this->planilla($ctx, $token);

        $r->assertStatus(200);

        $asignatura = $r->json('asignatura');
        $this->assertNotNull($asignatura,
            'Borrar al docente hizo desaparecer la asignatura: eso es el `INNER` otra vez.');
        $this->assertArrayHasKey('nombres_profesor', $asignatura);
        $this->assertNull($asignatura['nombres_profesor'],
            'Sale el nombre de un profesor borrado.');
        $this->assertNotEmpty($r->json('alumnos'));
    }
}
