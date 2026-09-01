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
     * El nombre exacto de la fila de `permissions`. Lo crea
     * `2026_08_25_200000_create_permiso_can_view_auditoria` y lo lee
     * `puedeVerAuditoria()`; si los dos no dicen la misma cadena, el permiso
     * existe y no lo tiene nadie **sin que falle nada**.
     */
    public const PERMISO_AUDITORIA = 'can_view_auditoria';

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
     * Los roles que la **decisión 5** pone a cargo de la marca del boletín
     * independiente. Superusuario va por encima, como siempre.
     *
     * @var list<string>
     */
    private const ROLES_QUE_MARCAN_BOLETIN_INDEPENDIENTE = ['Admin', 'Secretario', 'Rector'];

    /**
     * Marcar y desmarcar un periodo de un alumno como boletín independiente.
     * `PUT boletin-independiente/periodo`, §6.3 del
     * [19](../../docs/migracion/19-boletin-independiente.md).
     *
     * Es la **decisión 5** de Joseth (31 ago 2026): *administradores, secretario y
     * rector*, con el superusuario por encima. Y es **más estrecha que lo de hoy**:
     * la rama de propiedades de matrícula de `GuardarAlumno::valor` la escribe
     * también el **titular del grupo**, y aquí no. Marcar un boletín no es corregir
     * una casilla de la ficha: reparte de quién son las unidades de un periodo
     * entero, y eso lo decide el colegio, no el aula. El psicólogo tampoco entra —
     * la decisión no lo nombra, y [[crear-rol-no-regala-permisos]]: lo que nadie
     * pidió no se concede de paso.
     *
     * **Por qué NO es `esAdministrativo()`, que es lo primero que se prueba.** Aquél
     * es `is_superuser || Secretario` y **no incluye el rol `Admin`**, al que la
     * decisión 5 nombra explícitamente. Hoy los dos criterios admiten a la misma
     * gente, y eso es exactamente lo que lo hace peligroso: en `simonbolivar` los
     * diez `Admin` **son** los diez `is_superuser`, así que coinciden **por
     * población y no por definición**. El colegio que le dé `Admin` a alguien sin
     * `is_superuser` es el que descubre la diferencia — el paso 0 de
     * `DESPLIEGUE.md` en su forma exacta.
     *
     * **Y no se escribe con los nombres del encargo:** `Role::hasRoleOrPerm` es del
     * **front**. En este backend aparece en cinco comentarios de controlador y en
     * ninguna línea de código (§2.3 del plan).
     *
     * **Una sola consulta y no tres.** `Role::hasRole()` llama a
     * `Role::getUserRoles()`, que es una consulta entera por nombre preguntado: tres
     * `hasRole` seguidos son tres consultas idénticas en cada petición. Se pide la
     * lista una vez y se cruza aquí.
     *
     * **Y sale de `Role::getUserRoles()` y no de `$user->roles`, que ya viaja en el
     * contexto y sería gratis.** No es lo mismo: aquella consulta filtra
     * `r.deleted_at is null` y la del contexto **no**. Con `$user->roles` un rol
     * mandado a la papelera seguiría dando permiso aquí y no en `esAdministrativo()`
     * —que va por `Role::isSecretario()`—, o sea dos criterios de rol decidiendo
     * distinto en la misma clase. Se paga una consulta por no tener eso.
     */
    public static function puedeMarcarBoletinIndependiente($user): bool
    {
        if (self::esSuperusuario($user)) {
            return true;
        }

        $userId = $user->user_id ?? null;

        if ($userId === null) {
            return false;
        }

        foreach (Role::getUserRoles($userId) as $rol) {
            if (in_array($rol->name, self::ROLES_QUE_MARCAN_BOLETIN_INDEPENDIENTE, true)) {
                return true;
            }
        }

        return false;
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
     * Ver la auditoría **de otra persona**: sus ingresos, sus intentos fallidos
     * de entrar y quién cambió una nota.
     *
     * Es la pieza 1 de la **decisión 3** de `docs/migracion/18-auditoria.md`, y
     * las tres van juntas: permiso por rol, sembrado sólo a rectoría y
     * coordinación, y **lo propio se ve siempre sin permiso** (eso lo hace
     * `exigirVerAuditoriaDe`, no este método).
     *
     * **Por qué el criterio vive aquí y no en un middleware.** Las seis rutas
     * viejas reciben el identificador de tres sitios distintos —la URL, el
     * cuerpo con `user_id`, y el cuerpo con `historial_id`, que ni siquiera es
     * un usuario— así que un middleware tendría que saber de qué ruta viene
     * para saber dónde mirar. El motivo por el que esta clase existe es que el
     * criterio estaba copiado a mano en unos controladores y ausente en otros;
     * repartirlo otra vez, aunque fuera en un middleware, es el mismo error con
     * otra forma.
     */
    public static function puedeVerAuditoria($user): bool
    {
        if (self::esSuperusuario($user)) {
            return true;
        }

        // `perms` es la lista plana de nombres que arma `ContextoDeUsuario` con
        // los permisos de TODOS los roles del usuario, y viaja dentro del
        // contexto: retirar el permiso tiene efecto sin tocar la sesión.
        return in_array(self::PERMISO_AUDITORIA, (array) ($user->perms ?? []), true);
    }

    /**
     * Lo propio siempre; lo de otro sólo con el permiso.
     *
     * **El `null` no es «cualquiera», es «otro».** Cuatro de las seis rutas
     * reciben el identificador por el cuerpo, y un cuerpo sin esa clave llega
     * aquí como `null`. Si `null` cayera del lado de «es lo suyo», bastaría con
     * no mandar el campo para saltarse la comprobación — que es exactamente la
     * forma del agujero que esto viene a cerrar. Por eso la comparación es
     * contra un id concreto y todo lo demás exige permiso.
     */
    public static function exigirVerAuditoriaDe($user, $idDeUsuario): void
    {
        $propio = $user->user_id ?? null;

        if ($idDeUsuario !== null && $propio !== null && (int) $idDeUsuario === (int) $propio) {
            return;
        }

        self::exigir(
            self::puedeVerAuditoria($user),
            'No tiene permiso para ver la auditoría de otras personas'
        );
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
