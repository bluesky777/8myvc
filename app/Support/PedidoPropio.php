<?php

namespace App\Support;

use App\User;
use Illuminate\Support\Facades\DB;

/**
 * De quién es el pedido de cambio que esta petición nombra.
 *
 * Existe por la §53 de docs/migracion/05-codigo-muerto-y-roto.md, y lo que la
 * hizo aparecer no es un fallo nuevo sino **la pregunta que la §50 dejó
 * escrita**: «¿qué MÁS lee este identificador del cuerpo?». `asked_id` viene del
 * cuerpo en seis métodos repartidos en dos controladores; la §39, la §49 y la
 * §50 cerraron los cinco de `ChangeAskedController` en tres pasadas distintas, y
 * el sexto —`ChangesAskedAssignment/ver-detalles`— se quedó porque cada pasada
 * entró por una ruta y arregló lo que esa ruta tocaba.
 *
 * La comprobación vivía como método privado del controlador que la estrenó. Al
 * aparecer el sexto sitio, copiarla habría sido escribir dos veces el criterio
 * que ya costó tres pasadas fijar una vez: **el dueño o el superusuario**, que
 * no se eligió sino que lo dicen los dos únicos llamantes del front —la lista
 * del propio docente y el panel de revisión, que es `getToMe()` y exige
 * `Usuario` con `is_superuser`—.
 *
 * Un pedido que no existe es **404 y no 403**: negar la lectura de una fila que
 * no está le dice a quien pregunta que sí está.
 */
class PedidoPropio
{
    /**
     * El pedido, si es suyo o si es superusuario. Aborta si no.
     *
     * Devuelve la fila —no un booleano— porque quien llama necesita después
     * `data_id` y `assignment_id`, y **derivarlos de aquí es medio arreglo de la
     * §49**: los dos venían también del cuerpo, así que aun siendo el dueño del
     * pedido se podían borrar los anexos de otro con solo nombrarlos.
     */
    public static function exigir($asked_id, string $mensaje = 'Solo puedes retirar un pedido tuyo.'): object
    {
        $pedido = DB::selectOne(
            'SELECT id, asked_by_user_id, data_id, assignment_id FROM change_asked WHERE id = ?',
            [$asked_id]
        );

        if (! $pedido) {
            abort(404, 'Ese pedido no existe.');
        }

        $user = User::fromToken();

        Autoriza::exigir(
            (int) $pedido->asked_by_user_id === (int) $user->user_id || Autoriza::esSuperusuario($user),
            $mensaje
        );

        return $pedido;
    }
}
