<?php

namespace App\Http\Middleware;

use App\User;
use Closure;
use Illuminate\Http\Request;

/**
 * Deja pasar solo al personal del colegio: profesores y usuarios administrativos.
 *
 * **Por qué existe.** Cuatro controladores intentaban comprobar esto en su
 * constructor y ninguno lo conseguía:
 *
 *   - `RequisitosController` y `PrematriculasController` hacían
 *     `return 'No tienes permiso';` dentro del constructor. Un `return` en un
 *     constructor descarta el valor y la petición sigue. Comprobado: con token
 *     de alumno, `DELETE api/requisitos/destroy/{id}` respondía 200 "Eliminado".
 *   - `PiarsGruposController` escribía
 *     `!$this->user->is_superuser && !$this->user->tipo == 'Profesor'`, que PHP
 *     agrupa como `(!$tipo) == 'Profesor'` — nunca cierto. Con token de alumno,
 *     `GET api/piars-grupos/grupos` devolvía el listado entero.
 *   - `Boletines2Controller::deleteDestroy` y su gemelo de `boletines3` borran
 *     un alumno por id y no comprobaban nada en absoluto.
 *
 * **Por qué el criterio es "no es alumno ni acudiente" y no `is_superuser`,**
 * que es lo que decía el código muerto: el colegio tiene diez cuentas de tipo
 * Usuario sin superusuario —secretarías, coordinación— y exigir superusuario las
 * dejaría fuera de su propio trabajo. Lo que había que cerrar era la puerta a
 * alumnos y acudientes. Decisión de Joseth, 18 ago 2026.
 *
 * Esto NO sustituye a las comprobaciones por método: varias rutas de matrículas
 * exigen además `profes_can_edit_alumnos` o superusuario, y siguen exigiéndolo.
 */
class ExigirPersonal
{
    /** Los que no son personal del colegio. */
    private const FUERA = ['Alumno', 'Acudiente'];

    public function handle(Request $request, Closure $next)
    {
        $usuario = User::fromToken(false, $request);

        if (in_array($usuario->tipo, self::FUERA, true)) {
            abort(403, 'No tienes permiso');
        }

        return $next($request);
    }
}
