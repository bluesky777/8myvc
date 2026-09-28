<?php

namespace App\Support;

/**
 * El `profesores.id` cuyas cosas mira una pantalla de docente sin docente en la URL
 * («mis clases», «mis titularías»).
 *
 *     tipo 'Profesor'  ->  su `persona_id`, que es un `profesores.id`
 *     tipo 'Usuario'   ->  `users.profesor_id`, el docente enlazado en su fila
 *                          (lo escribe `UsersController::putMiDocente`)
 *     cualquier otro   ->  null
 *
 * El `persona_id` de un `Usuario` es un `users.id`: compararlo con un `profesor_id`
 * sólo acierta por casualidad. Es la regla de `CompromisosDelDocenteController::miProfesorId`.
 *
 * **Sólo para LEER lo propio.** Las autorizaciones que exigen `tipo === 'Profesor'`
 * (`Autoriza`, la planilla offline) no pasan por aquí: el enlace es un «qué miro»,
 * no un «quién soy».
 */
final class DocenteDelUsuario
{
    public static function id(object $user): ?int
    {
        $tipo = $user->tipo ?? '';

        // `?? null`: el contexto es un `stdClass` y la rama de `Profesor` no trae `profesor_id`.
        $id = match ($tipo) {
            'Profesor' => (int) ($user->persona_id ?? 0),
            'Usuario' => (int) ($user->profesor_id ?? 0),
            default => 0,
        };

        return $id > 0 ? $id : null;
    }
}
