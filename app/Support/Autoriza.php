<?php

namespace App\Support;

use App\Models\Role;

/**
 * Comprobaciones de autorización para las operaciones de alcance de colegio.
 *
 * Nació para las destructivas de la papelera y desde el 20 ago 2026 cubre
 * también las masivas de cuentas (`cambiar-usuarios/*`), que no borran nada pero
 * reescriben el nombre de usuario o la contraseña de TODOS los alumnos o de
 * todos los acudientes de golpe. El criterio es el mismo y por eso vive aquí:
 * son operaciones de colegio, no de aula.
 *
 * Existe porque el criterio estaba copiado a mano en unos controladores y ausente
 * en otros: alumnos/forcedelete comprobaba, unidades/forcedelete comprobaba otra
 * cosa, y grupos, perfiles, profesores, years y editnota no comprobaban nada. Con
 * la regla en un solo sitio no puede volver a divergir.
 *
 * Todas estas rutas hacen forceDelete(), que es borrado físico y dispara las FK
 * ON DELETE CASCADE del esquema. El alcance no es la fila que se ve:
 *
 *   years        59 tablas, 7 saltos   (prácticamente el histórico completo)
 *   profesores   31 tablas, 7 saltos
 *   grupos       27 tablas, 6 saltos   (llega a notas, 1.163.307 filas)
 *   alumnos      20 tablas, 4 saltos
 */
class Autoriza
{
    /**
     * Operaciones administrativas: papelera de grupos y profesores, y las
     * masivas de cuentas. Mismo criterio que ya usaba alumnos/forcedelete, sin
     * la rama de profesor: ni borrar definitivamente ni reiniciar la contraseña
     * del colegio entero son tarea docente.
     *
     * **Aviso medido el 20 ago 2026:** en la base de desarrollo no existe ningún
     * rol llamado `Secretario` —los once son Alumno, Acudiente, Profesor, Admin,
     * Psicólogo, Enfermero, Coord disciplinario, Manager, Asistente, Coord
     * académico y Rector—, así que aquí esta condición vale exactamente
     * `is_superuser`. El rol que sí existe y tiene gente dentro es `Admin`, con
     * diez. Si el nombre correcto es ése, se cambia en esta línea y arregla los
     * seis sitios a la vez; es justo para eso que la regla está en uno solo.
     * Anotado en docs/migracion/09-pendientes.md §5.
     */
    public static function esAdministrativo($user): bool
    {
        return (bool) ($user->is_superuser ?? false)
            || Role::isSecretario($user->user_id);
    }

    /**
     * Borrado definitivo de alumnos. Conserva la rama de profesor porque es la
     * que ya tenía AlumnosController::deleteForcedelete y hay colegios que la usan.
     */
    public static function puedeBorrarAlumnos($user): bool
    {
        if (($user->tipo ?? '') === 'Profesor' && ($user->profes_can_edit_alumnos ?? false)) {
            return true;
        }

        return self::esAdministrativo($user);
    }

    /**
     * Solo superusuario. Para lo que arrastra el esquema entero.
     */
    public static function esSuperusuario($user): bool
    {
        return (bool) ($user->is_superuser ?? false);
    }

    /**
     * Corta con 403 si no se cumple.
     */
    public static function exigir(bool $condicion, string $mensaje): void
    {
        if (! $condicion) {
            abort(403, $mensaje);
        }
    }
}
