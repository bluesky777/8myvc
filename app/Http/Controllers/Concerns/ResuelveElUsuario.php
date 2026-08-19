<?php

namespace App\Http\Controllers\Concerns;

use App\User;

/**
 * Da `$this->user` a un controlador resolviéndolo en la primera lectura.
 *
 * **Por qué no en el constructor, que es donde estaba.** 24 controladores hacían
 * `$this->user = User::fromToken();` en el constructor. Laravel instancia el
 * controlador para leerle el middleware, así que `php artisan route:list`
 * abortaba con 401 antes de imprimir nada, y `route:cache` era imposible. Ver
 * docs/migracion/02-plan-rendimiento.md §3.
 *
 * Ninguno de esos controladores declaraba `$user`: lo creaban como propiedad
 * dinámica. Por eso este `__get` entra — PHP solo lo llama cuando la propiedad
 * no existe. Si algún día alguien vuelve a declarar `public $user;` en una clase
 * que use este trait, el trait deja de tener efecto en silencio; el test
 * `test_ningun_controlador_declara_user` lo impide.
 *
 * **Por qué devuelve por referencia.** Los cuatro controladores de boletines
 * pasan `$this->user` a un parámetro `&$user`. Con un `__get` normal PHP avisa
 * "Indirect modification of overloaded property has no effect", y Laravel
 * convierte ese aviso en excepción: 500 en cada boletín.
 *
 * La anotación es para el análisis estático: un `__get` no le dice a phpstan
 * qué propiedades existen, así que sin ella los 320 `$this->user` de los
 * controladores salen como «propiedad no definida» y tapan lo que sí importa.
 *
 * @property User $user
 */
trait ResuelveElUsuario
{
    /** Null hasta que alguien lea `$this->user`. Una sola resolución por objeto. */
    private $usuarioDeLaPeticion = null;

    public function &__get($nombre)
    {
        if ($nombre === 'user') {
            if ($this->usuarioDeLaPeticion === null) {
                // Aborta con 401 si no hay token, si expiró o si es inválido.
                $this->usuarioDeLaPeticion = User::fromToken();
            }

            return $this->usuarioDeLaPeticion;
        }

        // Cualquier otra propiedad se comporta igual que antes de existir este
        // __get: aviso de PHP y null. Laravel convierte el aviso en excepción,
        // que es exactamente lo que pasaba con la propiedad inexistente.
        trigger_error('Undefined property: '.static::class.'::$'.$nombre, E_USER_WARNING);

        $nulo = null;

        return $nulo;
    }
}
