<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * Las dos pantallas que contestan **«quién cambió esta nota»**, medidas por el
 * viaje de ida y vuelta: se cambia la nota **por la API** y después se le pregunta
 * a la pantalla si lo cuenta.
 *
 * Es la única forma de medirlas, y no por elegancia: **`bitacoras` llega vacía en
 * el seed**. Un caso que buscara un cambio ya registrado pasaría sin comprobar
 * nada, que es la trampa que ya va por la duodécima vez en este repo.
 *
 * Y de las dos, una nunca funcionó: ver [05 §73](../../docs/migracion/05-codigo-muerto-y-roto.md).
 *
 * | Pantalla | Ruta | Antes |
 * |---|---|---|
 * | modal de una nota (`NotasCtrl`) | `historiales/nota-detalle` | bien |
 * | modal de una definitiva (`PromocionarNotasCtrl`) | `historiales/nota-final-detalle` | **500 siempre** |
 */
class QuienCambioLaNotaTest extends CasoDeContrato
{
    private function tokenDeSuperusuario(): string
    {
        $super = DB::selectOne('SELECT username FROM users
            WHERE is_superuser = 1 AND is_active = 1 AND deleted_at IS NULL ORDER BY id LIMIT 1');

        return $this->tokenDe($super->username);
    }

    /**
     * Cambiar una nota queda anotado, y la pantalla lo cuenta con el valor viejo y
     * el nuevo.
     *
     * El `historial_id` de la bitácora sale de la fila de `historiales` que escribe
     * el **login**, así que este caso comprueba de paso algo que no se ve: sin esa
     * fila, `NotasController::putUpdate` no puede ni guardar —su primera consulta
     * cruza `notas` con el último historial del usuario y sin él devuelve cero
     * filas—.
     */
    public function test_cambiar_una_nota_queda_anotado_y_la_pantalla_lo_cuenta(): void
    {
        $token = $this->tokenDeSuperusuario();

        $nota = DB::selectOne('SELECT id, nota FROM notas WHERE deleted_at IS NULL ORDER BY id LIMIT 1');
        $this->assertNotNull($nota, 'El seed necesita una nota.');

        $vieja = (float) $nota->nota;
        $nueva = $vieja == 4.2 ? 3.1 : 4.2;

        $this->withToken($token)->putJson('/api/notas/update/'.$nota->id, ['nota' => $nueva])
            ->assertStatus(200);

        $this->olvidarControladores();

        $r = $this->withToken($token)->putJson('/api/historiales/nota-detalle', ['nota_id' => $nota->id]);
        $r->assertStatus(200);

        $cambios = $r->json('cambios');
        $this->assertCount(1, $cambios, 'La pantalla no cuenta el cambio que se acaba de hacer.');
        $this->assertSame((int) $nueva, (int) $cambios[0]['new_value']);
        $this->assertSame((int) $vieja, (int) $cambios[0]['old_value']);
        $this->assertNotEmpty($cambios[0]['creado_por'], 'No dice quién lo cambió.');
    }

    /**
     * La misma pregunta sobre una definitiva. **Contestaba 500 a todo el mundo**:
     * la consulta de la fila tenía una marca de parámetro y dos valores —copiados
     * de la de arriba, que sí lleva dos porque es un `UNION`— y con
     * `EMULATE_PREPARES` en false eso es `SQLSTATE[HY093]`.
     *
     * O sea que el modal de `PromocionarNotasCtrl` no ha abierto nunca. Se
     * comprueba el viaje entero y no sólo el 200: que el cambio salga con sus dos
     * valores es lo que distingue el arreglo de «ya no revienta».
     */
    public function test_cambiar_una_definitiva_queda_anotado_y_la_pantalla_lo_cuenta(): void
    {
        $token = $this->tokenDeSuperusuario();

        // Sin `deleted_at`: `notas_finales` no tiene papelera, que es justo lo que
        // hace irreversible el borrado de la §71.
        $nf = DB::selectOne('SELECT nf.id, nf.nota FROM notas_finales nf
            INNER JOIN periodos p ON p.id = nf.periodo_id AND p.deleted_at IS NULL
            ORDER BY nf.id LIMIT 1');
        $this->assertNotNull($nf, 'El seed necesita una definitiva con periodo.');

        $vieja = (float) $nf->nota;
        $nueva = $vieja == 4.0 ? 3.0 : 4.0;

        $this->withToken($token)->putJson('/api/definitivas_periodos/update', [
            'nf_id' => $nf->id, 'nota' => $nueva,
        ])->assertStatus(200);

        $this->olvidarControladores();

        $r = $this->withToken($token)->putJson('/api/historiales/nota-final-detalle', ['nf_id' => $nf->id]);
        $r->assertStatus(200);

        $cambios = $r->json('cambios');
        $this->assertCount(1, $cambios, 'La pantalla no cuenta el cambio de la definitiva.');
        $this->assertSame((int) $nueva, (int) $cambios[0]['new_value']);
        $this->assertSame((int) $vieja, (int) $cambios[0]['old_value']);
        $this->assertNotEmpty($r->json('nota'), 'No devuelve la fila de la definitiva.');
    }
}
