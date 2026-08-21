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
     * Superusuario o Secretario. El criterio de secretaría, ya con dueño.
     *
     * Hasta el 21 ago 2026 esto valía exactamente `is_superuser`, porque el rol
     * `Secretario` **no existía** en la tabla `roles` — el aviso que había aquí
     * lo decía y proponía usar `Admin`. Se le preguntó a Joseth y la respuesta
     * fue otra: **rol nuevo**, porque la razón de existir del Secretario es una
     * secretaria docente **sin** `is_superuser`, y los diez `Admin` son
     * exactamente los diez `is_superuser`, así que con `Admin` el rol no
     * distinguiría a nadie. Lo crea
     * `2026_08_21_100000_create_rol_secretario`, sin dárselo a nadie.
     *
     * **Qué cubre este método, después de repasar sus seis llamadas una a una.**
     * El alcance que Joseth describió no es «un docente con más cosas» ni «un
     * superusuario con menos»: la secretaria administra la **estructura** del
     * colegio y es docente normal en **su propia aula**. De lo que colgaba de
     * aquí, le corresponden las cuatro masivas de `cambiar-usuarios/*`
     * —cambiarle el username o la contraseña a los alumnos y a los acudientes,
     * que es literalmente lo que dijo— y las dos ramas de `alumnos/guardar-valor`.
     *
     * **Lo que se sacó de aquí a `esSuperusuario` el mismo día**, porque crear el
     * rol se las habría regalado sin que nadie lo decidiera:
     *
     *   - `perfiles/creartodoslosusuarios`, que **crea cuentas** de alumnos,
     *     profesores y acudientes. «No crea usuarios» fue textual.
     *   - los tres `forcedelete` —perfiles, grupos y profesores—, que son borrado
     *     físico en cascada de 20, 27 y 31 tablas. La §28.4 ya había fijado que
     *     el borrado físico es solo de superusuario, y Joseth no lo nombró.
     *
     * La regla que se siguió para repartirlas, y que vale para la próxima:
     * **crear el rol no puede dar permisos que nadie pidió**. Todo lo que
     * colgaba de este método y no estaba en la lista de Joseth se ancló a
     * superusuario, que es donde ya estaba de hecho.
     */
    public static function esAdministrativo($user): bool
    {
        return (bool) ($user->is_superuser ?? false)
            || Role::isSecretario($user->user_id);
    }

    /**
     * Crear y editar acudientes.
     *
     * Los tres sitios de `AcudientesController` preguntaban
     * `$this->user->tipo == 'Secretario'`, y `users.tipo` solo toma los cuatro
     * valores del `switch` de `ContextoDeUsuario` —Usuario, Profesor, Alumno,
     * Acudiente—, así que era **siempre falso**: el criterio efectivo quedaba en
     * `is_superuser` (más `Profesor` en dos de los tres). Es el sitio donde la
     * §30.2 se veía desde fuera — un administrativo sin superusuario no podía
     * crear un acudiente.
     *
     * Se conserva la rama de `Profesor` de los dos primeros y la ausencia de esa
     * rama en el tercero: son criterios distintos escritos a propósito, y
     * unificarlos aquí sería colar una decisión dentro de un arreglo.
     */
    public static function puedeEditarAcudientes($user, bool $conDocentes = true): bool
    {
        if ($conDocentes && ($user->tipo ?? '') === 'Profesor') {
            return true;
        }

        return self::esAdministrativo($user);
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
