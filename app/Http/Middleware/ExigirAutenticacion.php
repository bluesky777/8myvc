<?php

namespace App\Http\Middleware;

use App\User;
use Closure;
use Illuminate\Http\Request;

/**
 * Exige un token válido antes de dejar entrar al controlador.
 *
 * Este proyecto no tenía middleware de autenticación: cada método se defendía
 * solo llamando a `User::fromToken()`, y 58 rutas que escriben en la base se
 * habían quedado sin hacerlo. La auditoría que las encontró está en
 * docs/migracion/04-auditoria-autenticacion.md.
 *
 * **Por qué llama a `User::fromToken()` y no a `JWTAuth::parseToken()`, que
 * sería más barato.** Porque fromToken() no solo valida el token: distingue
 * "no hay token" (401 'No existe Token') de "expiró" (401 'Token ha expirado.')
 * y de "el usuario está inactivo" (400 'user_inactivo'), y el frontend
 * AngularJS ya reacciona a cada uno. Validar el token por otra vía daría
 * respuestas distintas para estas 58 rutas que para las otras 438, y eso son
 * dos contratos donde debería haber uno.
 *
 * El coste es una consulta más por petición en estas rutas. Es exactamente el
 * que ya pagan las otras 438, y es el precio de que dejen de ser públicas.
 *
 * Esto NO comprueba permisos: resolver al usuario prueba que hay token válido,
 * no que ese usuario pueda hacer lo que va a hacer. Un alumno con token pasa
 * este middleware. Los permisos van por método, como `exigirAdminUsuarios()`
 * en RolesController.
 */
class ExigirAutenticacion
{
    public function handle(Request $request, Closure $next)
    {
        // Aborta con 401 si no hay token, si expiró o si es inválido.
        // Ver app/User.php:85-99.
        User::fromToken(false, $request);

        return $next($request);
    }
}
