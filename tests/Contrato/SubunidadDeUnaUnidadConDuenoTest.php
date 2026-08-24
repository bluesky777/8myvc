<?php

namespace Tests\Contrato;

use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;

/**
 * Añadir una subunidad a una unidad **con dueño** le crea notas a todo el grupo.
 *
 * **ROJO a propósito.** Es la red de quien encienda el boletín independiente, que
 * puede ser otra sesión dentro de dos noches, y está puesta **en el sitio exacto
 * donde se rompe** en vez de en un párrafo.
 *
 *     docker exec -w /app/.worktrees/12 -e DB_TEST_DATABASE=simonbolivar_testing_12 \
 *         8myvc-app-1 php artisan test --group=rojo
 *
 * ## Dónde está el fallo, y por qué no lo ve el censo de BI-2
 *
 * `SubunidadesController::postIndex` deriva el grupo desde la unidad, y **esa
 * lectura es impecable** — `unidades WHERE u.id = ?`, una fila por su id, del
 * cajón «bien por construcción» del inventario de BI-1, y comprobada a mano en la
 * muestra de BI-2. Lo que hace con el resultado, no:
 *
 *     $grupo = ... FROM unidades u INNER JOIN asignaturas a ... WHERE u.id = ?
 *     Nota::verificarCrearNotas($grupo->grupo_id, $subunidad, $user->user_id);
 *
 * Y `Nota::verificarCrearNotas($grupo_id, ...)` **recibe un grupo y recorre
 * `Grupo::alumnos($grupo_id)` insertando una nota por cada uno**. No sabe —no
 * puede saber— que la unidad de la que cuelga esa subunidad **es de un solo
 * alumno**.
 *
 * > **El alcance no se pierde en la lectura: se pierde en el traspaso.** La
 * > clasificación de BI-1 va por lectura y **no puede ver que una lectura segura
 * > entregue su resultado a una insegura**. No es un fallo del detector —no es su
 * > pregunta—: es un agujero del método, y por eso este caso no está entre las 59
 * > de BI-2. Ver `docs/migracion/noche-2026-08-25/bi-2.md`.
 *
 * ## Hoy no puede fallar solo, y por eso el test le pone el dueño
 *
 * `unidades.alumno_id` es NULL para todas las filas de los dieciséis colegios —la
 * columna la creó el esqueleto de BI-1 y **nadie la escribe todavía**—, así que
 * este fallo **nace inocuo y se arma solo el día que alguien marque al primer
 * alumno**, que es literalmente el objeto del [19](../../docs/migracion/19-boletin-independiente.md).
 *
 * El test **pone el dueño dentro de su propia transacción** y ejerce el alta. Eso
 * es lo que lo convierte en una red y no en una predicción.
 *
 * ## Qué lo pondría verde
 *
 * **Que `verificarCrearNotas` reciba el alcance en vez de derivarlo.** Hoy su
 * firma es `($grupo_id, $subunidad, $user_id)` y la de la unidad no llega nunca.
 * Con el dueño delante —o con la lista de alumnos ya resuelta por quien sabe de
 * quién es la unidad— el alta crea **una** nota y este test pasa a verde.
 *
 * **No se arregla aquí:** toca `app/`, o sea dieciséis despliegues, y **la decisión
 * de qué significa una unidad con dueño es del [19 §2](../../docs/migracion/19-boletin-independiente.md),
 * que espera a Joseth.** Esto sólo deja el fallo fijado y con su forma exacta.
 */
#[Group('rojo')]
class SubunidadDeUnaUnidadConDuenoTest extends CasoDeContrato
{
    public function test_una_subunidad_de_una_unidad_con_dueno_no_deberia_crear_notas_al_grupo(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);

        [$grupo, $token] = $this->grupoYPersonal();

        // Una unidad de una asignatura de este grupo, y un alumno matriculado en él.
        $unidad = DB::selectOne(
            'SELECT u.id FROM unidades u
               INNER JOIN asignaturas a ON a.id = u.asignatura_id AND a.grupo_id = ?
              WHERE u.deleted_at IS NULL LIMIT 1',
            [$grupo->id]
        );

        $dueno = DB::selectOne(
            'SELECT m.alumno_id FROM matriculas m
              WHERE m.grupo_id = ? AND m.deleted_at IS NULL
                AND m.estado IN ("MATR","ASIS","PREM") LIMIT 1',
            [$grupo->id]
        );

        $this->assertNotNull($unidad, 'El seed no tiene una unidad en este grupo: el test no ejerce nada.');
        $this->assertNotNull($dueno, 'El seed no tiene un alumno matriculado en este grupo.');

        $enElGrupo = (int) DB::selectOne(
            'SELECT COUNT(DISTINCT m.alumno_id) c FROM matriculas m
              WHERE m.grupo_id = ? AND m.deleted_at IS NULL
                AND m.estado IN ("MATR","ASIS","PREM")',
            [$grupo->id]
        )->c;

        // **La precondición, y sin ella el test no mide nada:** si el grupo tuviera
        // un solo alumno, «una nota» y «una por alumno» serían el mismo número y
        // esto pasaría en verde sin ejercer la regla. Es la misma vacuna que la del
        // acudiente sin acudidos en `MisActividadesIdAjenoTest`.
        $this->assertGreaterThan(1, $enElGrupo,
            'Este grupo tiene un solo alumno: «una nota» y «una por alumno» coinciden y el test '
            .'no distinguiría nada.');

        // Le ponemos dueño a la unidad. Dentro de la transacción del test, así que
        // no sale de aquí.
        DB::update('UPDATE unidades SET alumno_id = ? WHERE id = ?', [$dueno->alumno_id, $unidad->id]);

        $r = $this->postJson('/api/subunidades', [
            'unidad_id' => $unidad->id,
            'definicion' => 'Subunidad de una unidad con dueño',
            'porcentaje' => 10,
            'nota_default' => 0,
        ], ['Authorization' => 'Bearer '.$token]);

        // **201 y no 200**, y se comprueba en vez de darse por hecho: la primera
        // versión de este test pedía 200 y cayó ahí — o sea **roja por el motivo
        // equivocado**, que es peor que verde. Un rojo que no es el rojo que dice
        // su nombre no es una red: es ruido con la cara de una red.
        $r->assertStatus(201);

        $subunidadId = $r->json('id');
        $this->assertNotNull($subunidadId, 'El alta no devolvió la subunidad creada.');

        $conNota = DB::select(
            'SELECT DISTINCT alumno_id FROM notas WHERE subunidad_id = ? AND deleted_at IS NULL',
            [$subunidadId]
        );

        $this->assertCount(1, $conNota,
            'Se crearon notas para '.count($conNota).' alumnos de los '.$enElGrupo.' del grupo, '
            .'sobre una subunidad que cuelga de una unidad cuyo dueño es el alumno '
            .$dueno->alumno_id.'. `Nota::verificarCrearNotas` recibe un `grupo_id` y recorre '
            .'`Grupo::alumnos()`: nadie le dice de quién es la unidad. La lectura que deriva el '
            .'grupo es correcta; lo que se pierde es el alcance AL ENTREGARLO.');

        $this->assertSame((int) $dueno->alumno_id, (int) $conNota[0]->alumno_id,
            'La única nota creada no es la del dueño de la unidad.');
    }
}
