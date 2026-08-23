<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * §121 — El pedido de cambio se cerraba con **la hora escrita dos veces**, y de
 * paso escribía en una tabla de depuración.
 *
 * `ChangeAskedController` no es de ningún lote y lleva dentro dos cosas que solo
 * se ven al ejecutar la ruta y **mirar la fila**, no la respuesta.
 *
 * ## La hora
 *
 * ```php
 * $dt = Carbon::now('America/Bogota')->format('Y-m-d G:H:i');
 * ```
 *
 * **`G` y `H` son las dos la hora del día** —una sin cero delante, la otra con
 * él—, así que el formato es `hora:hora:minutos` y **los segundos no llegan
 * nunca**: las 21:07:33 se guardan como **21:21:07**.
 *
 * Lo que lo hace comprobable sin discutirlo es que **la ruta escribe esa misma
 * columna dos veces, y la otra vez lo hace bien**: la rama de `asignatura`, ochenta
 * líneas más arriba, liga el `Carbon` directamente. Dos escrituras a
 * `change_asked.deleted_at` en el mismo método, una correcta y otra no. Ese
 * contraste es el que dice cuál de las dos es el arreglo.
 *
 * > **Las filas ya escritas llevan la hora mal**, y eso no lo arregla este commit.
 * > Quien lea la auditoría de un pedido cerrado antes de este despliegue está
 * > leyendo `hora:hora:minutos`. Va a PARA JOSETH porque es un dato, no un formato.
 *
 * Y **la población es de tres, no de una**, medido con un grep del formato en todo
 * `app/`: `Tardanzas/TSubirController:103` lo escribe igual y
 * `AusenciasController:177` **lee** con ese mismo formato. Los dos son de otros
 * lotes y quedan anotados; el de aquí es el único que se toca.
 *
 * ## Y los cinco `Debugging::pin`
 *
 * No eran comentarios: `Debugging::pin()` hace `new Debugging` y `save()`, o sea
 * **una fila de verdad en la tabla `debugging`**. Aceptar o rechazar un pedido de
 * cambio escribía hasta cinco, una de ellas con el texto `'ENTROOOOO'` dentro, en
 * los dieciséis colegios. Se quitan.
 */
class PedidoDeCambioLaHoraTest extends CasoDeContrato
{
    private function tokenDeSuperusuario(): string
    {
        $super = DB::selectOne('SELECT username FROM users
            WHERE is_superuser = 1 AND is_active = 1 AND deleted_at IS NULL ORDER BY id LIMIT 1');
        $this->assertNotNull($super, 'El seed no tiene superusuario.');

        return $this->tokenDe($super->username);
    }

    /**
     * Un pedido de cambio **sin nada pendiente**, que es el que llega a cerrarse.
     *
     * `change_asked` y `change_asked_data` están **vacías en el seed**, así que el
     * pedido se fabrica aquí: con todos los `_new` en null, `finalizar_si_no_hay_cambios`
     * da true y escribe el `deleted_at` que este test mira.
     */
    private function unPedidoSinNadaPendiente(): int
    {
        $usuario = DB::selectOne('SELECT id FROM users WHERE deleted_at IS NULL ORDER BY id LIMIT 1');

        $dataId = DB::table('change_asked_data')->insertGetId([
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return (int) DB::table('change_asked')->insertGetId([
            'asked_by_user_id' => $usuario->id,
            'data_id' => $dataId,
            'tipo_user' => 'Alumno',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * §121 — La hora que se guarda al cerrar el pedido es la hora de verdad.
     *
     * Se comprueba contra el reloj del propio contenedor, con un margen de un
     * minuto: lo que se afirma no es un valor exacto sino que **la hora y los
     * minutos son los que son**. Con el formato viejo, los minutos eran la hora
     * otra vez, así que a cualquier hora distinta de las 00:00 esta comparación
     * fallaba por más de un minuto.
     */
    public function test_al_cerrar_el_pedido_la_hora_es_la_hora(): void
    {
        $token = $this->tokenDeSuperusuario();
        $pedido = $this->unPedidoSinNadaPendiente();

        $ahora = \Carbon\Carbon::now('America/Bogota');

        $r = $this->withToken($token)->putJson('/api/ChangesAsked/rechazar',
            ['asked_id' => $pedido, 'tipo' => 'sexo']);

        $r->assertStatus(200);
        $this->assertTrue($r->json('finalizado'), 'Sin nada pendiente, el pedido se cierra.');

        $guardado = DB::table('change_asked')->where('id', $pedido)->value('deleted_at');
        $this->assertNotNull($guardado, 'Cerrar el pedido tiene que dejar su fecha.');

        // Se lee **en la zona en la que se escribió**. `config/app.php` dice UTC y
        // este código llama a `Carbon::now('America/Bogota')`, así que parsear a
        // secas da un instante cinco horas distinto y la comparación fallaría por
        // la zona en vez de por el formato. Las dos zonas conviven a propósito
        // hasta que se unifiquen (09 §2), y este test no es el sitio de decidirlo.
        $leida = \Carbon\Carbon::parse($guardado, 'America/Bogota');

        $this->assertSame($ahora->format('Y-m-d H'), $leida->format('Y-m-d H'),
            'El día y la hora tienen que coincidir.');
        $this->assertLessThanOrEqual(60, abs($leida->diffInSeconds($ahora)),
            'Y los minutos también: con `G:H:i` los minutos eran la hora repetida.');
    }

    /** Y queda firmado por quién lo cerró, que es lo que hace útil la fecha. */
    public function test_al_cerrar_el_pedido_queda_escrito_quien_fue(): void
    {
        $token = $this->tokenDeSuperusuario();
        $pedido = $this->unPedidoSinNadaPendiente();

        $this->withToken($token)->putJson('/api/ChangesAsked/rechazar',
            ['asked_id' => $pedido, 'tipo' => 'sexo'])->assertStatus(200);

        $fila = DB::table('change_asked')->where('id', $pedido)->first();
        $this->assertNotNull($fila->answered_by);
        $this->assertNotNull($fila->deleted_by);
        $this->assertSame((int) $fila->answered_by, (int) $fila->deleted_by);
    }

    /**
     * Cerrar un pedido **ya no escribe en la tabla de depuración**.
     *
     * Es el resultado que hay que mirar, no la ausencia de la llamada: `debugging`
     * está vacía en el seed —que sale de producción—, así que el volumen real no
     * se puede medir desde aquí; lo que sí se puede es fijar que esta ruta no la
     * toca.
     */
    public function test_cerrar_un_pedido_no_escribe_en_debugging(): void
    {
        $token = $this->tokenDeSuperusuario();
        $pedido = $this->unPedidoSinNadaPendiente();
        $antes = DB::table('debugging')->count();

        $this->withToken($token)->putJson('/api/ChangesAsked/rechazar',
            ['asked_id' => $pedido, 'tipo' => 'sexo'])->assertStatus(200);

        $this->assertSame($antes, DB::table('debugging')->count(),
            'La tabla de depuración no es un sitio donde escribir en producción.');
    }

    /** Un pedido que no existe sigue siendo 404, y solo el superusuario rechaza. */
    public function test_quien_rechaza_y_que_pedido(): void
    {
        $token = $this->tokenDeSuperusuario();
        $inexistente = ((int) DB::table('change_asked')->max('id')) + 1000;

        $this->withToken($token)->putJson('/api/ChangesAsked/rechazar',
            ['asked_id' => $inexistente, 'tipo' => 'sexo'])->assertStatus(404);

        $pedido = $this->unPedidoSinNadaPendiente();

        foreach (['Alumno', 'Acudiente'] as $tipo) {
            $this->withToken($this->tokenDe($this->usuarioDeTipo($tipo)->username))
                ->putJson('/api/ChangesAsked/rechazar', ['asked_id' => $pedido, 'tipo' => 'sexo'])
                ->assertStatus(403);
        }

        $this->assertNull(DB::table('change_asked')->where('id', $pedido)->value('deleted_at'));
    }
}
