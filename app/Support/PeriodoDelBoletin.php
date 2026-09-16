<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;

/**
 * El periodo que pide el cuerpo de un boletín, cuando no es el activo.
 *
 * ## Por qué existe: un boletín de un periodo cerrado no tenía camino para la familia
 *
 * Regla de Joseth, 15 sep 2026: **«no importa si el periodo cerró, siempre se puede
 * imprimir boletines del mismo»**. Medido ese día, quién podía y quién no:
 *
 * - **El personal ya podía**, y no por estas rutas: `PUT periodos/useractive/{id}`
 *   mueve su contexto a cualquier periodo vivo de cualquier año —es el selector de
 *   la barra de arriba— y el boletín sale del periodo nuevo.
 * - **El alumno y el acudiente podían ver sus NOTAS** de todos los periodos, porque
 *   `GET notas/alumno` devuelve el año entero de una vez y la app pinta un
 *   desplegable.
 * - **Su BOLETÍN no tenía camino**: las ocho `PUT …/detailed-notas[-group]` imprimen
 *   `$user->periodo_id` y `periodos/useractive` es `auth.personal`. Estaban clavados
 *   al periodo activo del colegio **sin nada con que moverlo**.
 *
 * Así que lo que falta no era «que el boletín acepte un periodo» —para el personal
 * eso llevaba años funcionando— sino **la puerta que la familia no tenía**. Por eso
 * el campo es opcional y su ausencia es exactamente el comportamiento de siempre:
 * ningún cliente desplegado cambia de conducta por esto.
 *
 * ## El campo NO se llama `periodo_a_calcular`, y ése es el error que evita
 *
 * Ese nombre **ya está cogido y significa otra cosa**: `putDetailedNotasGroup` lo lee
 * del cuerpo desde siempre y se lo pasa a `Periodo::hastaPeriodoN`, que decide **qué
 * periodos salen en la columna acumulada del año**, no de qué periodo es el boletín.
 * Reutilizarlo habría cambiado en silencio un renglón que nadie mira mientras prueba
 * otro.
 *
 * ## Dentro del año del usuario, y por qué eso no es una limitación disfrazada
 *
 * El periodo pedido tiene que ser del año en el que está el usuario. No es
 * prudencia: los cuatro controladores leen `$user->year_id` en una docena de sitios
 * —escalas de valoración, rótulos, el año pasado en el boletín— y un periodo de otro
 * año haría un boletín **mezclando dos años sin avisar**. Para ver 2024 se cambia de
 * año arriba, que es el producto que ya existe.
 *
 * ## Mueve el contexto de ESTA petición y no toca la base
 *
 * `$user` es el `stdClass` que arma `ContextoDeUsuario` para la petición: cambiarle
 * el periodo aquí alcanza a los doce sitios que lo leen sin tocar ninguno, y **no
 * escribe `users.periodo_id`** —que es lo que hace `periodos/useractive` y lo que
 * dejaría al alumno aparcado en un periodo viejo la próxima vez que entre—.
 */
class PeriodoDelBoletin
{
    /**
     * La fila del periodo pedido, o `null` si el cuerpo no pide ninguno.
     *
     * 422 —y no 404— cuando el id no vale: lo que llega mal es un campo del cuerpo,
     * no el recurso de la URL, y el boletín de ese grupo sí existe.
     */
    public static function pedido($user): ?object
    {
        $pedido = Request::input('periodo_id');

        if ($pedido === null || $pedido === '') {
            return null;
        }

        if (! is_numeric($pedido) || (int) $pedido <= 0) {
            abort(422, "'periodo_id' no es un identificador.");
        }

        $fila = DB::selectOne(
            'SELECT id, numero, year_id, profes_pueden_editar_notas, profes_pueden_nivelar
               FROM periodos WHERE id = ? AND deleted_at IS NULL',
            [(int) $pedido]
        );

        if ($fila === null) {
            abort(422, 'Ese periodo no existe.');
        }

        if ((int) $fila->year_id !== (int) ($user->year_id ?? 0)) {
            abort(422, 'Ese periodo no es del año en el que estás; cambia de año antes de pedirlo.');
        }

        return $fila;
    }

    /**
     * Pone el contexto de esta petición en el periodo pedido.
     *
     * Van los cuatro campos que el contexto saca de la fila de `periodos`, no sólo
     * los dos que usa el boletín: `numero_periodo` lo lee `allNotasAlumno` para
     * cortar `notas_finales` con `periodo <= ?`, y los dos interruptores gobiernan
     * quién puede escribir. **Dejar los interruptores del periodo activo mientras el
     * resto mira otro periodo es la clase de mezcla que luego no se sabe leer**, así
     * que se mueven juntos o no se mueve ninguno.
     */
    public static function aplicar($user, object $periodo): void
    {
        $user->periodo_id = (int) $periodo->id;
        $user->numero_periodo = (int) $periodo->numero;
        $user->profes_pueden_editar_notas = (int) $periodo->profes_pueden_editar_notas;
        $user->profes_pueden_nivelar = (int) $periodo->profes_pueden_nivelar;
    }
}
