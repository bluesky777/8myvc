<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * `PUT notas/subunidad` **estuvo roto y ya no lo está** — §3.1, arreglado el 24
 * ago 2026 con la fase 3.
 *
 * Este documento se conserva entero porque **el diagnóstico es lo que vale**: la
 * causa, las dos hipótesis que la medición descartó y la red que resultó
 * sostenerlo. Lo único que cambia es el desenlace, y va al final.
 *
 * La §3.1 de [10-definitivas.md](../../docs/migracion/10-definitivas.md) lo tenía
 * anotado como *«`putSubunidad` no guarda nada: la consulta está en comillas
 * dobles con sintaxis de concatenación de simples»*. La causa está bien vista.
 * **El efecto no se sabía, y hay dos hipótesis que la medición descarta.**
 *
 * En comillas dobles PHP **sí** interpola la variable; lo que no hace es
 * concatenar, así que los `'.` y `.'` de alrededor se quedan como texto:
 *
 *     $sql = "SELECT '.$sub_id.' as subunidad_id"   con $sub_id = 7
 *     → SELECT '.7.' as subunidad_id
 *     → devuelve la CADENA «.7.»
 *
 * De ahí salía la hipótesis con la que se entró aquí, y era razonable: `notas`
 * tiene `strict => false`, así que MySQL coacciona `.7.` a su prefijo numérico y
 * **escribe una fila de basura** — apuntando a una subunidad que no es, y esa
 * fila entraría en el cálculo de la definitiva de quien fuera.
 *
 * **No pasa, y el motivo es el que interesa: `notas` lleva
 * `FOREIGN KEY (subunidad_id) REFERENCES subunidades(id)`.** El valor coaccionado
 * es 0, la subunidad 0 no existe y MySQL rechaza el INSERT. El endpoint responde
 * **500** y no escribe nada.
 *
 * Tres cosas que llevarse, y ninguna es el endpoint:
 *
 * 1. **La integridad la sostiene el esquema y no el código**, igual que encontró
 *    la [§4 de 13-actividades](13-actividades.md) en `ws_actividades_compartidas`.
 *
 *    **Aquí hubo una afirmación falsa y se deja escrita porque es instructiva.**
 *    Este comentario decía que `notas_finales` «no lleva ninguna de estas claves»
 *    y que por eso las inyecciones de esta noche podían escribir en ella. Medido
 *    contra `information_schema`: **`notas_finales` tiene tres claves ajenas**
 *    —`alumno_id`, `asignatura_id` y `periodo_id`— y `notas` tiene dos. Lo dice
 *    además la §2 del propio 10-definitivas, que al hablar del índice único
 *    aclara «solo hay tres índices de clave foránea».
 *
 *    Lo que le falta a `notas_finales` no es integridad referencial: es la
 *    **clave única** sobre `(alumno_id, asignatura_id, periodo_id)`. Que es lo
 *    que aquel documento dijo desde el principio y lo que la fase 2 viene a
 *    poner. La conclusión que se sacó de más no añadía un argumento nuevo a la
 *    fase 2 — solo lo repetía mal.
 * 2. «No guarda nada» y «responde 500» no son lo mismo para quien lo usa: lo
 *    primero es un botón que no hace nada, lo segundo es un error en pantalla. La
 *    §3.1 se queda corta y por eso este test existe.
 * 3. Y la hipótesis de la fila de basura no era tonta: **habría sido cierta sin la
 *    clave ajena**. Conviene recordarlo el día que alguien migre esa tabla.
 *
 * **No se arregla aquí.** Arreglar las comillas convierte un endpoint que hoy
 * falla en uno que crea notas para un grupo entero, y eso mueve definitivas en los
 * dieciséis colegios: es la fase 3 de aquel plan, que Joseth revisa antes.
 */
class SubunidadCreaLasNotasQueFaltanTest extends CasoDeContrato
{
    /**
     * La consulta que PHP construye de verdad, sin pasar por el endpoint.
     *
     * Va primero y aparte porque es la mitad que se puede afirmar sin depender del
     * seed: **la causa**. Lo de abajo mide el efecto, que sí depende de los datos.
     */
    public function test_las_comillas_dobles_producen_una_cadena_y_no_un_numero(): void
    {
        $subId = 7;

        // Exactamente la forma que tiene NotasController::putSubunidad.
        $sql = "SELECT '.$subId.' as subunidad_id";

        $this->assertSame("SELECT '.7.' as subunidad_id", $sql,
            'PHP dejó de interpolar en comillas dobles, o de tratar los puntos como texto.');

        $this->assertSame('.7.', DB::select($sql)[0]->subunidad_id,
            'MySQL devolvió algo distinto de la cadena, y toda la §3.1 depende de eso.');
    }

    /**
     * Y el endpoint, llamado como lo llama el front, responde **500**.
     *
     * Éste es el resultado que corrige a la vez el documento y la hipótesis con la
     * que se entró aquí. No «no guarda nada» en silencio, y tampoco escribe una
     * fila de basura: **la clave ajena de `notas` lo detiene**.
     *
     * La cadena `.34104.` se coacciona a su prefijo numérico —`0`— y `notas` tiene
     * `FOREIGN KEY (subunidad_id) REFERENCES subunidades(id)`. La subunidad 0 no
     * existe, así que MySQL rechaza el INSERT y el cliente recibe un 500.
     *
     * Es lo mismo que la [§4 de 13-actividades](13-actividades.md) encontró en
     * `ws_actividades_compartidas` y merece decirse igual: **la integridad la
     * sostiene el esquema y no el código**.
     *
     * *(Aquí decía además que `notas_finales` «no lleva ninguna clave ajena de
     * éstas». Es falso y la cabecera de esta clase ya lo corrige: tiene tres. Se
     * quita para que la frase mala no sobreviva a su corrección, que es como
     * vuelven.)*
     *
     * ## El desenlace, 24 ago 2026
     *
     * **Arreglado.** El `INSERT` va ligado, así que el endpoint crea la nota que
     * faltaba y **recalcula la definitiva** — es la fase 3 de
     * [10-definitivas.md](../../docs/migracion/10-definitivas.md), y Joseth la
     * revisó antes, que era la condición que este test ponía.
     *
     * Lo que se comprueba ahora es lo contrario de lo que se comprobaba: que
     * **no** responde 500 y que **sí** escribe. La red del esquema sigue estando
     * donde estaba; lo que cambia es que ya no hace falta que salte.
     */
    public function test_el_endpoint_ya_no_falla_y_crea_la_nota_que_faltaba(): void
    {
        $grupo = $this->grupoConAlumnos();
        $token = $this->tokenDelPersonalDe((int) $grupo->year_id);

        $subunidad = DB::selectOne(
            'SELECT s.id, s.nota_default FROM subunidades s
               INNER JOIN unidades u ON u.id = s.unidad_id AND u.deleted_at IS NULL
               INNER JOIN asignaturas a ON a.id = u.asignatura_id AND a.grupo_id = ?
              WHERE s.deleted_at IS NULL ORDER BY s.id LIMIT 1',
            [$grupo->id]
        );

        if ($subunidad === null) {
            $this->markTestSkipped('El seed no trae subunidades del grupo con más alumnos.');
        }

        DB::table('notas')->where('subunidad_id', $subunidad->id)->delete();

        $r = $this->withToken($token)->putJson('/api/notas/subunidad', [
            'grupo_id' => $grupo->id,
            'subunidad' => ['id' => $subunidad->id, 'nota_default' => $subunidad->nota_default ?? 0],
        ]);

        $r->assertStatus(200);

        $this->assertGreaterThan(0,
            DB::table('notas')->where('subunidad_id', $subunidad->id)->whereNull('deleted_at')->count(),
            'El endpoint volvió a no escribir nada: si el INSERT deja de ir ligado, '
            .'la cadena `.123.` se coacciona a 0 y la clave ajena lo rechaza otra vez.');
    }
}
