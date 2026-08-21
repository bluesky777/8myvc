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
     * Crear, editar, mandar a la papelera y restaurar alumnos.
     *
     * **Hoy es la misma condición que `puedeBorrarAlumnos`, y por eso son dos
     * métodos y no uno.** El día 21 ago 2026 estaba escrita a mano siete veces
     * dentro de `AlumnosController` —era la última copia que quedaba del criterio
     * que esta clase existe para no volver a tener repartido—, así que traerla
     * aquí no cambia nada y hace que la pregunta pendiente de quién es el
     * «Secretario» ([05 §30.2](../../docs/migracion/05-codigo-muerto-y-roto.md))
     * se conteste en una línea en vez de en ocho.
     *
     * Lo que **no** se hizo fue fundirlas en una sola: crear un alumno y
     * borrarlo definitivamente —20 tablas en cascada— son la misma condición hoy
     * por herencia, no porque nadie haya decidido que deban serlo. Con dos
     * nombres se pueden separar el día que se decida; con uno, hay que volver a
     * repartirlas.
     */
    public static function puedeEditarAlumnos($user): bool
    {
        return self::puedeBorrarAlumnos($user);
    }

    /**
     * Solo superusuario. Para lo que arrastra el esquema entero.
     */
    public static function esSuperusuario($user): bool
    {
        return (bool) ($user->is_superuser ?? false);
    }

    /**
     * El `is_superuser` que de verdad se puede conceder.
     *
     * Cuatro sitios lo copiaban del cuerpo de la petición sin mirar quién la
     * manda: `profesores/store`, las dos ramas de `profesores/update/{id}` y
     * `alumnos/store`. La de profesores es la cara: solo pide `auth.personal`,
     * así que cualquiera de los 51 profesores creaba **una cuenta de
     * superusuario con el nombre y la contraseña que quisiera** y entraba con
     * ella. No hace falta tomar la cuenta de nadie: se fabrica una.
     *
     * La regla es la que el código no llegaba a escribir: **un permiso no se
     * concede a sí mismo**. Solo un superusuario puede crear otro; para el resto
     * el campo vale 0, venga como venga.
     *
     * Devuelve `int` y no `bool` a propósito: la columna es `tinyint(1)` y
     * `sanarInputUser()` metía un `false` de PHP, que es la familia de la
     * [§13](../../docs/migracion/05-codigo-muerto-y-roto.md) — el mismo campo
     * saliendo como `false` en la respuesta que lo crea y como `0` en las demás.
     */
    public static function concederSuperusuario($user, $pedido): int
    {
        return (self::esSuperusuario($user) && $pedido) ? 1 : 0;
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
