<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * Los pedidos de cambio, que llevaban usuarios enteros colgados.
 *
 * `ChangeAsked::extender_datos()` cuelga del pedido hasta **tres filas de
 * `users`** —quien lo pide, a quién se le pide y sobre quién— y las trae con
 * `SELECT * FROM users`. Los siete sitios que lo hacen son consultas crudas, así
 * que **el `$hidden` del modelo `User` no interviene**: `password` viene en la
 * fila y sale en la respuesta.
 *
 * Es el mismo error de forma que el `return $perfil->password . ...` de la §36:
 * la protección existe, es la correcta, y no cubre el camino que se usa. Con
 * `DB::select` no hay modelo al que ocultarle nada.
 *
 * Y la ruta que lo devuelve, `images-users/cambiar-imagen-perfil/{user_id}`,
 * lleva `persona.propia`: la usa **una familia** sobre su propia foto.
 *
 * Ver docs/migracion/05-codigo-muerto-y-roto.md §38.
 */
class PedidosDeCambioTest extends CasoDeContrato
{
    /** Todos los valores de una respuesta, sin importar cómo estén anidados. */
    private function hojasDe(mixed $valor): array
    {
        if (! is_array($valor)) {
            return [$valor];
        }

        $hojas = [];
        foreach ($valor as $v) {
            $hojas = array_merge($hojas, $this->hojasDe($v));
        }

        return $hojas;
    }

    /**
     * Un alumno cambia su foto y la respuesta no trae el hash de nadie.
     *
     * El alumno no es superusuario, así que su cambio no se aplica: se convierte
     * en un **pedido** que alguien tiene que aceptar. Y el pedido volvía con las
     * filas de `users` completas dentro.
     */
    public function test_el_pedido_de_cambio_no_devuelve_ningun_hash(): void
    {
        $alumno = $this->usuarioDeTipo('Alumno');

        $imagen = DB::table('images')->insertGetId([
            'nombre' => 'la-foto-del-alumno.png',
            'publica' => 0,
            'user_id' => $alumno->id,
            'created_by' => $alumno->id,
        ]);

        $r = $this->withToken($this->tokenDe($alumno->username))
            ->putJson('/api/images-users/cambiar-imagen-perfil/'.$alumno->id, [
                'user_id' => $alumno->id,
                'imagen_id' => $imagen,
            ]);

        $r->assertStatus(200);

        foreach ($this->hojasDe($r->json()) as $valor) {
            $this->assertFalse(
                is_string($valor) && str_starts_with($valor, '$2y$'),
                'La respuesta del pedido de cambio lleva un hash bcrypt dentro.'
            );
        }
    }

    /**
     * Y la mitad que importa que siga: el pedido se crea igual.
     *
     * Quitar columnas de una respuesta es fácil de hacer de más. Esto fija que lo
     * que la pantalla necesita —que exista el pedido y se sepa quién lo hizo—
     * sigue estando.
     */
    public function test_el_pedido_se_sigue_creando_y_dice_quien_lo_hizo(): void
    {
        $alumno = $this->usuarioDeTipo('Alumno');

        $imagen = DB::table('images')->insertGetId([
            'nombre' => 'otra-foto.png',
            'publica' => 0,
            'user_id' => $alumno->id,
            'created_by' => $alumno->id,
        ]);

        $r = $this->withToken($this->tokenDe($alumno->username))
            ->putJson('/api/images-users/cambiar-imagen-perfil/'.$alumno->id, [
                'user_id' => $alumno->id,
                'imagen_id' => $imagen,
            ]);

        $r->assertStatus(200);

        $this->assertNotNull($r->json('pedido'), 'Dejó de venir el pedido.');
        $this->assertSame((int) $alumno->id, (int) $r->json('pedido.asked_by_user_id'));
        $this->assertNotNull($r->json('pedido.asked_by_user.username'),
            'El pedido dejó de decir quién lo hizo.');
    }
}
