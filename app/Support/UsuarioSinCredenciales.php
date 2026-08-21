<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Una fila de `users` para colgarla de una respuesta, sin lo que no debe salir.
 *
 * Existe porque el proyecto tiene **dos formas de leer un usuario y solo una está
 * protegida**. El modelo `App\User` lleva `password` y `remember_token` en
 * `$hidden`, así que devolverlo entero en un JSON es seguro; pero aquí casi todo
 * se lee con `DB::select`, y una consulta cruda no tiene modelo al que ocultarle
 * nada: `SELECT * FROM users` trae el hash y lo cuelga de la respuesta.
 *
 * Eso es lo que pasaba en los siete `SELECT * FROM users` de
 * `ChangeAsked::extender_datos()` y `ChangeAskedDetails::extender_datos()`, que
 * cuelgan del pedido hasta tres usuarios —quien lo pide, a quién y sobre quién—.
 * Ver docs/migracion/05-codigo-muerto-y-roto.md §38.
 *
 * **Quita en vez de elegir, a propósito.** Una lista de columnas permitidas
 * habría dejado fuera cualquier columna nueva sin que nadie se enterara, y esto
 * va dentro de una respuesta que los clientes ya reciben. Quitando solo las dos
 * que son credenciales, la forma no cambia: sale exactamente lo mismo menos lo
 * que no debía estar.
 */
class UsuarioSinCredenciales
{
    /** Lo que nunca sale, se llame como se llame la consulta que lo trajo. */
    private const CREDENCIALES = ['password', 'remember_token'];

    /** La fila de `users`, o `null` si no existe o está borrada. */
    public static function porId($userId): ?object
    {
        if (! $userId) {
            return null;
        }

        $fila = DB::selectOne(
            'SELECT * FROM users WHERE id = ? AND deleted_at IS NULL',
            [$userId]
        );

        return $fila === null ? null : self::limpiar($fila);
    }

    /** Quita las credenciales de una fila que ya se tiene. */
    public static function limpiar(object $fila): object
    {
        foreach (self::CREDENCIALES as $columna) {
            unset($fila->{$columna});
        }

        return $fila;
    }
}
