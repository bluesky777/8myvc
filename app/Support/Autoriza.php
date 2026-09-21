<?php

namespace App\Support;

use App\Models\Role;
use Illuminate\Support\Facades\DB;

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
     * El nombre exacto de la fila de `permissions`. Lo crea
     * `2026_09_05_300000_create_permiso_can_edit_plantilla_notas` y lo lee
     * `puedeEditarPlantillaNotas()`; misma trampa que arriba, y por eso la misma
     * forma: si los dos no dicen la misma cadena, **el permiso existe y no lo
     * tiene nadie sin que falle nada**, y el síntoma sería una pantalla que sólo
     * funciona para superusuarios sin que nadie sepa por qué.
     */
    public const PERMISO_PLANTILLA_NOTAS = 'can_edit_plantilla_notas';

    /**
     * Superusuario o Secretario. El criterio de secretaría, ya con dueño.
     *
     * Hasta el 21 ago 2026 esto valía exactamente `is_superuser`, porque el rol
     * `Secretario` **no existía** en la tabla `roles` — el aviso que había aquí
     * lo decía y proponía usar `Admin`. Se le preguntó a Joseth y la respuesta
     * fue otra: **rol nuevo**, porque la razón de existir del Secretario es una
     * secretaria docente **sin** `is_superuser`, y el rol `Admin` no distinguiría
     * a nadie: se lo llevan los mismos que ya tienen la columna. Lo crea
     * `2026_08_21_100000_create_rol_secretario`, sin dárselo a nadie.
     *
     * > **Aquí decía «los diez `Admin` son exactamente los diez `is_superuser`», y
     * > eso ha dejado de ser cierto.** Remedido el 4 sep 2026 sobre `simonbolivar`:
     * > **11 con `is_superuser`, 10 con el rol `Admin`, 10 en los dos** — o sea que
     * > `Admin` es hoy un **subconjunto estricto**, y hay un superusuario sin el rol.
     * > **El razonamiento no se cae** —el rol sigue sin distinguir a nadie útil, que
     * > es lo que decidió crear `Secretario`— **pero el hecho sí**, y estaba escrito
     * > como igualdad. Importa fuera de aquí: `myvc_front` decide por el **rol**
     * > (`tieneAlgunRol(['admin'])`) donde esta API decide por la **columna**, así
     * > que **no son dos umbrales distintos del mismo criterio: son dos criterios de
     * > clase distinta**, y ya discrepan en una persona. Medido en un colegio y en la
     * > copia de desarrollo; en los otros quince no ha mirado nadie.
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
     * Los roles que deciden si el boletín imprime el número además del desempeño.
     * Superusuario va por encima, como siempre.
     *
     * **`Coord académico` lleva tilde y aquí eso importa.** La comparación la hace
     * `Role::hasRole()` en PHP, byte a byte contra lo que devuelve la tabla, así que
     * el literal de este fichero tiene que estar en UTF-8 igual que la fila. No es
     * una precaución de manual: el 18 sep 2026, midiendo quién entraba en este
     * mismo conjunto, un `WHERE r.name IN ('Coord académico')` desde el cliente
     * `mysql` devolvió **cero filas** teniendo un titular, y la conclusión
     * —«ese rol no lo tiene nadie»— estuvo a punto de irse en un mensaje. Es
     * [33-la-tilde-que-sql-no-ve](../../docs/migracion/33-la-tilde-que-sql-no-ve.md).
     * Contado por `role_id` hay **uno**, y **no es superusuario**, o sea que este
     * rol añade a alguien de verdad.
     *
     * @var list<string>
     */
    private const ROLES_QUE_CAMBIAN_LA_NOTA_NUMERICA = ['Secretario', 'Coord académico', 'Rector'];

    /**
     * Los roles que deciden si un aspirante entra al colegio. Superusuario va por
     * encima, como siempre.
     *
     * **`Secretario` se conserva y no es decorativo aquí, aunque en la base de tests
     * ese rol no exista** —`LoQueDecideUnRolTest` lo fija—: hasta el 20 sep 2026 esto
     * era `esAdministrativo()`, o sea `is_superuser || Secretario`, y quitarlo sería
     * estrechar un permiso que nadie mandó estrechar mientras se ensancha otro.
     *
     * La tilde de `Coord académico` importa por lo mismo que en la constante de
     * arriba: se compara en PHP contra lo que devuelve la tabla, nunca en SQL.
     *
     * @var list<string>
     */
    private const ROLES_QUE_DECIDEN_LA_ADMISION = ['Secretario', 'Coord académico'];

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
     * gente, y eso es exactamente lo que lo hace peligroso: coinciden **por
     * población y no por definición**. El colegio que le dé `Admin` a alguien sin
     * `is_superuser` es el que descubre la diferencia — el paso 0 de
     * `DESPLIEGUE.md` en su forma exacta.
     *
     * > **Este párrafo decía «los diez `Admin` SON los diez `is_superuser`», y eso
     * > llevaba caducado desde el 4 sep 2026 — corregido el 18.** Lo incómodo es que
     * > **la corrección ya estaba en este mismo fichero**, en el docblock de
     * > `esAdministrativo()` cincuenta líneas más arriba: allí se remidió el 4 sep y
     * > se escribió que `Admin` es un **subconjunto estricto**. Se arregló el sitio
     * > donde se descubrió y no el hermano que decía lo mismo. *Una cifra corregida
     * > en un sitio no corrige a su gemela: al remedir algo hay que buscar dónde más
     * > está escrito.*
     * >
     * > Remedido el 18 sep 2026 sobre `simonbolivar`, y **las dos direcciones
     * > contadas por separado**, que es lo que da el argumento entero:
     * >
     * > ```sql
     * > SELECT COUNT(*) FROM users WHERE deleted_at IS NULL AND is_superuser=1;      -- 11
     * > SELECT COUNT(DISTINCT u.id) FROM users u INNER JOIN role_user ru
     * >        ON ru.user_id=u.id AND ru.role_id=1 WHERE u.deleted_at IS NULL;       -- 10
     * > -- con la bandera y sin el rol ................................................ 1
     * > -- con el rol y sin la bandera ................................................ 0
     * > ```
     * >
     * > **El cero es el que importa aquí**: hoy nadie entra por `Admin` que no
     * > entrara ya por la columna, así que este método **no admite a nadie de más**.
     * > Lo que el uno significa es lo contrario y es de quien decida por el rol: un
     * > criterio escrito con `Admin` **deja fuera** a un superusuario. Eso es lo que
     * > le pasa a `myvc_front`, que decide por rol donde esta API decide por columna
     * > — levantado por esa sesión el 18 sep con la misma medida.
     * >
     * > De un colegio, el de desarrollo. En los otros quince no ha mirado nadie.
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
     * Marcar cuál es la versión **oficial** del horario de un año.
     * `PUT horario/versiones/{id}/oficial`, §5.4 del
     * [23](../../docs/migracion/23-horarios.md).
     *
     * Es la **decisión 10** de Joseth (2 sep 2026): *marca la oficial un
     * superusuario o el coordinador académico*. Secretaría **sube** todas las
     * versiones que quiera —está en `esAdministrativo()`— pero **no elige la que
     * ve el colegio**. Es la asimetría que pidió desde el principio: *subir no
     * publica*.
     *
     * **Por qué es un método nuevo y no uno de los que ya hay.** No es
     * `esSuperusuario` (deja fuera al coordinador) ni `esAdministrativo` (mete al
     * `Secretario`, que la decisión no nombra). Ensanchar cualquiera de los dos
     * habría colado esta decisión en los **otros seis sitios** que los leen; la
     * regla de esta clase es que un criterio nuevo se escribe con su nombre.
     *
     * **La regla nace correcta e INERTE, y hay que saberlo antes de leerla.** El
     * rol `Coord académico` existe desde 2018 y **tiene cero usuarios en los
     * dieciséis colegios en producción**, así que allí la oficial la marcan los 11
     * superusuarios y nadie más.
     *
     * > **Decía «cero usuarios en `simonbolivar`» y eso ya es falso en el docker
     * > desde el 6 sep 2026**: se creó `coord.academico.prueba` (`users.id` 2449)
     * > **en la base de desarrollo local** para poder ejercitar esta rama, que
     * > hasta ese día no la había probado nadie —no por descuido, sino porque no
     * > había a quién pedirle un token—. La escalera medida está en
     * > [32 §2](../../docs/migracion/32-la-entrada-de-la-app-de-escritorio.md).
     * >
     * > Se corrige la frase en vez de dejarla porque **una afirmación que el
     * > lector puede comprobar y ve fallar deja de ser creída para todo lo demás
     * > que dice este docblock**, y aquí abajo hay cosas que importan más. En
     * > producción sigue siendo cierta y por eso el enunciado no se retira: se le
     * > pone dónde vale. Asignar el rol es operación de cada colegio —quince decisiones,
     * no una nuestra (decisión 11)—; lo que sería un error es leer «también el
     * coordinador académico» y suponer que ya hay alguien detrás.
     *
     * ## Dar ese rol reparte HOY DOS permisos, no uno
     *
     * `can_view_auditoria` se le siembra a `Coord académico` desde el 25 ago 2026
     * (`2026_08_25_200000_create_permiso_can_view_auditoria`, que siembra
     * `['Rector', 'Coord académico']`). Así que dárselo a una persona le da
     * **publicar el horario del colegio Y ver el rastro de auditoría de otras
     * personas** —quién cambió qué nota, los ingresos ajenos— en un solo
     * movimiento. Es [[crear-rol-no-regala-permisos]] por su otra cara: crear un
     * rol no regala permisos, pero **dárselo a alguien sí le regala todos los que
     * ya cuelgan de él**.
     *
     * Y el enunciado no se puede escribir más ancho de lo que es: la segunda
     * mitad **no cuelga del rol en el código, cuelga de una fila**.
     * `puedeVerAuditoria()` no pregunta por ningún rol, lee
     * `in_array('can_view_auditoria', $user->perms)`. El acoplamiento es **por
     * dato y por colegio** —existe donde aquella migración corrió y nadie retiró
     * la fila, y ella misma hace `continue` si el rol no está—, mientras que este
     * método pregunta por el rol directamente. Van juntos en los colegios donde
     * esa fila está, y puede no estarlo en el catorce.
     *
     * **Ojo al leer un test en verde**: `test-seed.sql` hace `TRUNCATE` de
     * `permissions` y `permission_role`, así que **en la base de tests ese
     * acoplamiento no existe**. Un test que fabrique el rol para probar este
     * método no hereda `can_view_auditoria`, y su verde **no demuestra** que los
     * dos permisos vayan separados en producción, donde van juntos.
     *
     * ## Y la ruta lleva además `auth.personal`, que no es este criterio
     *
     * El guard de la ruta cierra la puerta a alumnos y acudientes **antes de
     * tocar el controlador**, y es la forma de la referencia que dio Joseth
     * (`myimages/cambiarlogocolegio`: guard en la ruta, `Autoriza` dentro). Hoy no
     * le quita el permiso a nadie que la decisión nombre —superusuarios y
     * secretaría son `Usuario` o `Profesor`—, pero deja un borde que conviene
     * conocer antes de depurarlo: **el día que un colegio le dé `Coord académico`
     * a una cuenta de tipo `Acudiente`, el 403 lo pone el guard y no este
     * método**, y quien lo investigue va a mirar aquí primero.
     */
    public static function puedePublicarHorario($user): bool
    {
        if (self::esSuperusuario($user)) {
            return true;
        }

        $userId = $user->user_id ?? null;

        return $userId !== null && Role::isCoordAcademico($userId);
    }

    /**
     * Cambiar si el boletín imprime el número además del texto del desempeño.
     * `PUT years/toggle-mostrar-nota-numerica`, columna
     * `years.mostrar_nota_numerica_boletin`.
     *
     * Decisión de Joseth del 17 sep 2026: **superusuario, Secretario, Coord
     * académico y Rector**. Medido ese día en la copia de desarrollo: **12**
     * personas, frente a las **74** que tiene el personal entero.
     *
     * ## Por qué no es ninguno de los que ya hay
     *
     * No es `esAdministrativo()` —`is_superuser || Secretario`—, que deja fuera al
     * coordinador y al rector. No es `puedePublicarHorario()` —superusuario o
     * coordinador—, que deja fuera a secretaría. Y **no es
     * `puedeMarcarBoletinIndependiente()`, que es la que más se le parece y es la
     * trampa**: aquélla es `Admin`, `Secretario` y `Rector`; ésta cambia `Admin`
     * por `Coord académico`. Las dos frases suenan igual leídas en voz alta y
     * admiten a gente distinta.
     *
     * Esa diferencia se levantó y se preguntó, no se dedujo: la decisión 5 era
     * *«administradores, secretario y rector»* y ésta salió como *«superadmin,
     * secretario y coord académico»*, a lo que Joseth añadió el rector. Lo que
     * queda es que aquí decide **lo académico** y allí **la administración del
     * colegio**, que es coherente con lo que cada una hace.
     *
     * ## `is_superuser` y NO el rol `Admin`, que es la otra mitad de la trampa
     *
     * La decisión dijo «superadmin». Se implementa con la **columna**
     * `is_superuser` y no con el rol `Admin`, igual que `puedePublicarHorario()`.
     * Hoy da lo mismo —los diez `Admin` de `simonbolivar` son diez de los once
     * `is_superuser`— y por eso es peligroso: **coinciden por población, no por
     * definición**. El colegio que le dé `Admin` a alguien sin la bandera es el que
     * descubre la diferencia, y ahí el conjunto habría cambiado sin que nadie lo
     * notara.
     *
     * ## Una sola consulta
     *
     * Misma razón que en la vecina: `Role::hasRole()` es una consulta por nombre
     * preguntado, así que tres seguidos son tres consultas idénticas. Se pide la
     * lista una vez y se cruza aquí, y sale de `Role::getUserRoles()` —que filtra
     * `r.deleted_at is null`— y no de `$user->roles`, que no lo filtra.
     */
    public static function puedeCambiarLaNotaNumerica($user): bool
    {
        if (self::esSuperusuario($user)) {
            return true;
        }

        $userId = $user->user_id ?? null;

        if ($userId === null) {
            return false;
        }

        foreach (Role::getUserRoles($userId) as $rol) {
            if (in_array($rol->name, self::ROLES_QUE_CAMBIAN_LA_NOTA_NUMERICA, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Quién elige qué pasa al cerrar con las casillas que nadie calificó.
     * `PUT years/cierre-sin-calificar`, columna `years.cierre_sin_calificar`
     * (**D3** del [43](../../docs/migracion/43-lo-que-todavia-no-se-ha-calificado.md),
     * fase 4, 20 sep 2026).
     *
     * **Mismo conjunto que `puedeCambiarLaNotaNumerica`: superusuario, Secretario,
     * Coord académico y Rector** — 12 personas de las 74 que tiene el personal en la
     * copia de desarrollo. Y se escribe aquí con su propio nombre, no se llama a
     * aquélla desde el controlador, por la regla de esta clase: *un criterio con
     * nombre propio se puede mover sin perseguir sus copias*.
     *
     * ## Por qué el permiso va DENTRO y no se hereda de la familia
     *
     * Los doce interruptores de `years/*` van con `auth.personal` y nada dentro, salvo
     * `toggle-mostrar-nota-numerica`. Éste va con el segundo grupo, y la razón no es
     * simetría: es **de quién es el interés**.
     *
     * `auth.personal` deja pasar a las **74** cuentas de personal, de las que **53 son
     * docentes**. Esta columna decide si a un alumno le cuentan como cero las casillas
     * **que su profesor no calificó**. Puesta en `fuera`, la consecuencia de no haber
     * calificado desaparece del boletín; puesta en `cero`, aparece entera. O sea que
     * con el permiso de la familia **el docente que no calificó podría borrar la
     * huella de no haber calificado**, y para el colegio entero, desde una pantalla de
     * ajustes. Es el único de los doce interruptores del año en el que el que lo pulsa
     * puede ser parte interesada.
     *
     * Y es el mismo criterio que decidió `toggle-mostrar-nota-numerica` el 17 sep:
     * **aquí decide lo académico**, no la administración del colegio, y lo que se
     * decide sale impreso en un papel firmado.
     *
     * ## Lo que NO se estrecha, y va escrito para que no se lea como un olvido
     *
     * **Cerrar el periodo sigue siendo de las 74.** `PUT
     * periodos/toggle-profes-pueden-editar-notas` no se toca: lo llaman los tres
     * clientes, es reversible y es el trabajo normal de secretaría. Lo que se estrecha
     * es **elegir la política**, que es de todo el año y de todos los docentes a la
     * vez; aplicarla es el cierre, y el cierre se queda donde estaba.
     */
    public static function puedeElegirQuePasaAlCerrar($user): bool
    {
        return self::puedeCambiarLaNotaNumerica($user);
    }

    /**
     * Quién ata el papel de inscripción a un alumno, y quién corrige su código.
     *
     * Decidido por Joseth el 20 sep 2026 con las dos poblaciones delante:
     * **`auth.personal` en la ruta y el criterio aquí dentro**, igual que la
     * bandeja del tesorero y que `can_edit_plantilla_notas`.
     *
     * El porqué no es simetría con las otras cuatro rutas del formulario —que van
     * con `auth.personal` a secas— sino qué decide cada escritura. Imprimir un
     * formulario en blanco no dice de quién es nada; **atarlo a un alumno decide de
     * quién es un cobro**, y corregir su código cambia lo que lleva impreso un papel
     * que está en casa de una familia. `auth.personal` deja pasar a las 74 cuentas
     * de personal, de las que **53 son docentes** que no tienen nada que ver con la
     * ventanilla.
     *
     * **No se reusó `esAdministrativo()` directamente**, aunque hoy devuelva
     * exactamente lo mismo. Ese método lo comparten quince llamadas de dominios que
     * no se parecen a éste, y el día que alguien lo ensanche —crear un rol no puede
     * regalar permisos que nadie pidió, que es la regla que ya está escrita en su
     * propio docblock— esta puerta se ensancharía con él **sin que nadie lo
     * decidiera**. Un método con nombre propio es lo que hace que ese día haya que
     * venir aquí a decirlo.
     */
    public static function puedeAtarFormularios($user): bool
    {
        return self::esAdministrativo($user);
    }

    /**
     * Quién decide si un aspirante entra al colegio.
     *
     * **Es la escritura más estrecha de todo el proceso de admisión, y la única del
     * portal que no va con `auth.personal` a secas.** El resto del módulo lo decidió
     * Joseth el 20 sep en la dirección contraria —*«cerrar un paso lo puede hacer
     * cualquiera del personal, con su nombre y su hora»*—, y eso está bien para un
     * paso: es reversible, lo ve la familia y lo corrige el de al lado.
     *
     * Admitir no es un paso. Es **la respuesta del colegio a una familia**, se dice una
     * vez y se dice fuera: un «no admitido» escrito por equivocación viaja al portal y
     * lo lee la madre antes de que nadie se entere. `auth.personal` deja pasar a las 75
     * cuentas de personal, de las que **53 son docentes**, y ninguno de ellos admite a
     * nadie en ningún colegio.
     *
     * **Se escribe con nombre propio y no llamando a `esAdministrativo()` desde la
     * ruta**, por lo mismo que `puedeAtarFormularios`: ese método lo comparten quince
     * llamadas de dominios que no se parecen a éste, y el día que alguien lo ensanche
     * esta puerta se ensancharía con él sin que nadie lo decidiera.
     *
     * ## EL COORDINADOR ACADÉMICO ENTRA — decisión de Joseth del 20 sep 2026
     *
     * Textual: *«el coordinador académico puede admitir estudiantes también.»* Con eso
     * esto **deja de ser `esAdministrativo()`** y pasa a la forma de
     * `puedeCambiarLaNotaNumerica`: la lista de roles de arriba, cruzada contra
     * `Role::getUserRoles()` en **una sola consulta**.
     *
     * **Medido por `role_id` y no por nombre**, que es la regla de
     * [33](../../docs/migracion/33-la-tilde-que-sql-no-ve.md) —un `WHERE r.name IN
     * ('Coord académico')` desde el cliente `mysql` devuelve cero filas teniendo
     * titular—. Remedido en la copia de desarrollo el 20 sep 2026:
     *
     * ```
     * rol  1  Admin              10 titulares, los 10 superusuarios
     * rol  9  Coord académico     1 titular,  NO superusuario   <- el que entra
     * rol 10  Rector              0
     * rol 12  Secretario          0
     *
     * quien admitía (superusuario o Secretario) ......... 12
     * con `Coord académico` dentro ...................... 13   de 75 de personal
     * ```
     *
     * O sea que **añade a una persona de verdad**, no a un conjunto vacío: es la
     * diferencia entre esta decisión y la de `Rector`, que hoy sería inerte.
     *
     * > **Y `Rector` NO se mete de paso, aunque la pregunta de abajo lo nombre.**
     * > Joseth nombró **un** rol. Meterlo sería [[crear-rol-no-regala-permisos]] al
     * > revés —*lo que nadie pidió no se concede*— y encima no cambiaría nada medible:
     * > cero titulares. Es una línea el día que él lo diga.
     *
     * > **Lo que sigue abierto y es de Joseth**, dicho aquí para que no se lea como
     * > cerrado: `INVESTIGACION-MATRICULAS.md` §10.4 pregunta *«¿quién admite en un
     * > colegio típico: el rector solo, o un comité?»*. La respuesta del 20 sep
     * > contesta **media**: nombra a quién añadir, no si el colegio típico lo decide
     * > en comité. Mientras tanto son trece, y la forma de lista hace que el día que
     * > conteste sea una línea y no un rediseño.
     */
    public static function puedeDecidirAdmision($user): bool
    {
        if (self::esSuperusuario($user)) {
            return true;
        }

        $userId = $user->user_id ?? null;

        if ($userId === null) {
            return false;
        }

        foreach (Role::getUserRoles($userId) as $rol) {
            if (in_array($rol->name, self::ROLES_QUE_DECIDEN_LA_ADMISION, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Quién aprueba o rechaza la colilla del pago de un formulario.
     *
     * Decidido por Joseth el 19 sep 2026: **el tesorero, y si no hay, secretaría.**
     * El respaldo no es un adorno — `years.tesorero_id` está **en NULL en los cuatro
     * años de la copia de desarrollo** y no lo lee nadie en toda la API, así que sin
     * la segunda mitad el pago no lo podría aprobar nadie hasta que alguien se
     * acordara de nombrar a un tesorero.
     *
     * ## `tesorero_id` es un `profesores.id`, NO un `users.id`
     *
     * Lo dice el esquema —`Year::datos` hace `left join profesores pRec on
     * pRec.id=y.rector_id`— y comparar contra `user_id` es el reflejo natural y
     * **está mal**. Medido en la base de desarrollo: el id 5 es la profesora
     * MARYELINE y el usuario 5 es MARYOLY, o sea que el error no daría un 403
     * ruidoso: **le daría permiso de aprobar pagos a otra persona**. Es la nota del
     * `CLAUDE.md` —«`user_id` es el id de `users`; `persona_id` es el de la ficha»—
     * con consecuencia de dinero.
     */
    public static function puedeResolverColillas($user, int $yearId): bool
    {
        if (self::esAdministrativo($user)) {
            return true;
        }

        $persona = $user->persona_id ?? null;

        if ($persona === null) {
            return false;
        }

        $anio = DB::selectOne('SELECT tesorero_id FROM years WHERE id=? AND deleted_at IS NULL',
            [$yearId]);

        return $anio !== null
            && $anio->tesorero_id !== null
            && (int) $anio->tesorero_id === (int) $persona;
    }

    /**
     * Solo superusuario. Para lo que arrastra el esquema entero.
     */
    public static function esSuperusuario($user): bool
    {
        return (bool) ($user->is_superuser ?? false);
    }

    /**
     * Quién puede escribir en un año que el colegio ya cerró.
     *
     * **Decisión de Joseth, 14 sep 2026: sólo superusuarios**, y con las dos
     * poblaciones delante —74 cuentas de personal, 11 superusuarios—.
     *
     * ## Esto AFINA la decisión del 24 ago, no la revierte
     *
     * Aquélla —`docs/migracion/16-escribir-en-un-anio-pasado.md`, con las cuatro
     * pantallas delante— dijo que **moverse por un año pasado y escribir en él es
     * el producto**, y sigue siéndolo: no se cierra ninguna pantalla y no se
     * bloquea ningún año. Lo que decía literalmente es que *«un usuario **con
     * permisos** puede ir al año pasado y cambiar las frases y situaciones, lo
     * mismo que las escalas»*, y **quién era ese usuario no se había escrito
     * nunca en el backend**: las cinco escrituras iban con `auth.personal`, o sea
     * cualquiera de los 74.
     *
     * ## Por qué no rompe la pantalla que el doc 16 dijo que se rompería
     *
     * Ese documento avisa de que cerrar esto *«rompe el panel de Colegio ▸ Años
     * para los siete años que no son el actual»* y que **lo notarían los diez
     * `admin`**. Medido el 14 sep 2026 en la copia de desarrollo de
     * `simonbolivar`: el rol `Admin` son **10 personas y las diez son
     * superusuarias**, y ningún permiso de rol está repartido a nadie en ese
     * colegio (`can_edit_years`: 0 personas). O sea que **aquí «admin» es
     * `is_superuser`** y los diez que usan el panel lo siguen usando igual.
     *
     * **Es de UN colegio y no de los dieciséis**, que es la parte que hay que
     * comprobar el día del despliegue: un colegio que le haya dado el panel a
     * alguien sin `is_superuser` lo va a notar. Está anotado en el doc 16.
     *
     * ## Y por qué el criterio se escribe con su nombre en vez de llamar a
     * ## `esSuperusuario` desde los tres controladores
     *
     * Por la regla de esta clase, la misma que dejó escrita
     * `puedeEditarPlantillaNotas`: un criterio con nombre propio **se puede
     * mover sin perseguir sus copias**. El día que un colegio pida que rectoría
     * corrija una errata de 2024, esto pasa a mirar un permiso y cambia en un
     * sitio; con `esSuperusuario` copiado en cinco métodos, cambia en cinco o en
     * cuatro.
     */
    public static function puedeEscribirEnUnAnioCerrado($user): bool
    {
        return self::esSuperusuario($user);
    }

    /**
     * Corta con 403 si la fila es de un año cerrado y quien escribe no puede.
     *
     * `$que` nombra lo que se estaba tocando —«la escala de valoración», «la
     * frase»— porque el mensaje acaba en la pantalla de alguien y *«no tiene
     * permiso»* a secas manda a mirar los roles, que es el sitio equivocado: lo
     * que falta no es un permiso, es que el año ya pasó.
     */
    public static function exigirEscrituraEnElAnio($user, $yearId, string $que): void
    {
        if (! AnioCerrado::estaCerrado($yearId)) {
            return;
        }

        self::exigir(
            self::puedeEscribirEnUnAnioCerrado($user),
            $que.' es de un año ya cerrado; sólo un superusuario puede modificarla.'
        );
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
     * Editar la **plantilla de notas del colegio** — las nueve rutas de
     * `plantilla-notas`, §5.1.b de
     * [28](../../docs/migracion/28-competencias-e-indicadores.md).
     *
     * Superusuario **o** quien tenga `can_edit_plantilla_notas`. No es
     * `esAdministrativo` —que mete al `Secretario`, a quien nadie ha nombrado
     * para esto— ni `puedePublicarHorario` —que es otro criterio de otro módulo—:
     * la regla de esta clase es que **un criterio nuevo se escribe con su
     * nombre**, porque ensanchar uno de los que ya hay colaría esta decisión en
     * los otros sitios que lo leen.
     *
     * **Lo que gobierna, y por qué el listón está aquí y no en `auth.personal`.**
     * Una fila de esta plantilla **multiplica**: un 90 % escrito aquí es un 90 %
     * en todas las asignaturas del colegio que se siembren a partir de mañana. Es
     * una decisión de colegio con la forma de una casilla de aula, y ésa es
     * exactamente la clase de cosa que el guard de la ruta no distingue —
     * `auth.personal` deja pasar a cualquier docente.
     *
     * **Nace repartido a nadie, y eso es deliberado**: su migración no siembra
     * ningún rol (ver el fichero, que explica por qué ésta no reparte y
     * `can_view_auditoria` sí). El día del despliegue la pantalla es de los
     * superusuarios de cada colegio, y darle el permiso a rectoría o a
     * coordinación es una fila desde la pantalla de roles, sin migración.
     */
    public static function puedeEditarPlantillaNotas($user): bool
    {
        if (self::esSuperusuario($user)) {
            return true;
        }

        // `perms` es la lista plana de nombres que arma `ContextoDeUsuario` con
        // los permisos de TODOS los roles del usuario, y viaja dentro del
        // contexto: retirar el permiso tiene efecto sin tocar la sesión.
        return in_array(self::PERMISO_PLANTILLA_NOTAS, (array) ($user->perms ?? []), true);
    }

    /**
     * Escribir el **plan de área** —`desempenos_por_defecto`—, §3 de
     * [39](../../docs/migracion/39-el-modelo-plano-por-competencias.md).
     *
     * **Es un permiso con alcance, y ésa es la pieza entera.** Hasta hoy esas
     * rutas exigían `puedeEditarPlantillaNotas`, que el docente **no tiene** (D13,
     * D28: va a Coordinación académica), así que la pantalla del docente nacía en
     * 403. **P1.quater** pide una segunda puerta, y es ésta.
     *
     * Verdad si se cumple **una** de las dos:
     *
     *  1. `puedeEditarPlantillaNotas` — el colegio escribe en cualquier (materia,
     *     grado) del año; o
     *  2. el docente **da esa materia en ese grado y ese año**, o sea tiene una
     *     asignatura viva en un grupo vivo que case con los tres.
     *
     * ## Las tres reglas que lo hacen defendible, y ninguna es de estilo
     *
     * **`$gradoId === null` es sólo del colegio.** Una fila de «todos los grados»
     * alcanza a grados que ese docente no da, así que dejársela editar sería
     * darle por la puerta de atrás el alcance que este permiso le niega por la de
     * delante. Con el grado a `null` sólo pasa la rama 1, y por eso el `return`
     * está **antes** de la consulta y no dentro de ella: un `grado_id <=> NULL`
     * casaría con la fila del colegio.
     *
     * **La rama 2 exige `tipo === 'Profesor'`, y no es defensivo.**
     * `$user->persona_id` es el id de la **ficha**, no el de `users`: para un
     * `Profesor` es `profesores.id`, que es lo que compara `asignaturas.profesor_id`,
     * pero para un `Usuario` administrativo es `users.id` — un número que casaría
     * con la ficha de **otra persona**. Sin esta línea, el administrativo número 5
     * heredaría las asignaturas del profesor número 5.
     *
     * **`nuevo_responsable_id` NO cuenta, y se comprobó antes de escribirlo al
     * revés.** Sus cuatro apariciones en `app/` son una `@property`, un `INSERT` al
     * duplicar asignaturas y una copia al renovar el año: **ningún camino de
     * lectura la usa para decidir quién da una asignatura**, y en el docker hay
     * **0 de 1.219** asignaturas vivas con ella puesta (17 sep 2026). Meterla aquí
     * sería inventarle un significado que el repo no le da y **ensanchar el
     * permiso** con una columna que nadie escribe. Si algún colegio la usa como
     * «el docente que sustituye», eso es una decisión aparte y se toma con el dato
     * delante.
     *
     * **El periodo cerrado NO se comprueba aquí**, y también es a propósito: la
     * rama 2 lo pide y la rama 1 no —el coordinador cierra las notas *para*
     * congelar las notas, y sigue teniendo que poder montar el plan de área—, así
     * que quien decide es quien sabe por qué rama entró. Lo hace
     * `DesempenosController::exigirEscrituraDelPlan`, que llama a
     * `exigirPeriodoAbierto` **sólo cuando la rama 1 no valió**.
     *
     * @param  int  $yearId  el año del token: el plan de área es por año
     * @param  ?int  $gradoId  `null` es «todos los grados», o sea del colegio
     */
    public static function puedeEscribirDesempenos($user, int $yearId, int $materiaId, ?int $gradoId): bool
    {
        if (self::puedeEditarPlantillaNotas($user)) {
            return true;
        }

        if ($gradoId === null) {
            return false;
        }

        if (($user->tipo ?? null) !== 'Profesor') {
            return false;
        }

        $profesorId = $user->persona_id ?? null;

        if ($profesorId === null) {
            return false;
        }

        return DB::selectOne(
            'SELECT 1 AS si FROM asignaturas a
               INNER JOIN grupos g ON g.id = a.grupo_id AND g.deleted_at IS NULL
              WHERE a.deleted_at IS NULL
                AND a.profesor_id = ?
                AND g.year_id = ? AND a.materia_id = ? AND g.grado_id = ?
              LIMIT 1',
            [(int) $profesorId, $yearId, $materiaId, $gradoId]
        ) !== null;
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
     * Quién puede dar por resuelta una nota pendiente de una estación.
     *
     * **Decidido por Joseth el 20 sep 2026: quien la escribió, o `Admin`,
     * `Secretario` o `Rector`** (46 §3.3, `myvc_flutter/docs/estaciones.md` §2.10).
     * Sustituye a lo que el documento pedía —«el dueño de esa estación»—, que dejó
     * de existir el mismo día: desde que **cerrar un paso lo puede hacer cualquiera
     * del personal**, la estación no tiene dueño.
     *
     * ## Es el ÚNICO permiso de este módulo, y eso es la decisión
     *
     * Las otras ocho rutas van con `auth.personal` y nada dentro. Aquí sí hay
     * candado porque **una nota pendiente es un aviso que le estorba a quien tiene
     * prisa**: el tesorero escribe el lunes «esta familia debe matrícula» y el
     * sábado, en la cola, el que atiende la estación 5 necesita cerrar su paso. Si
     * pudiera apagar la nota él mismo, el aviso no protegería nada. Por eso la
     * puerta de escape es **nominal** —Secretaría o Rectoría— y no anónima.
     *
     * ## Se pregunta por NOMBRE de rol y no por id, y eso NO es descuido
     *
     * El 46 dice «`Admin` (1), `Secretario` (12) o `Rector` (10)», y los ids son
     * correctos **en la copia de desarrollo**. Pero `roles` es una tabla **por
     * colegio** —cada uno es una copia de la base— y no está garantizado que las
     * dieciséis tengan las mismas filas. Medido el 20 sep 2026:
     *
     *     simonbolivar (desarrollo)      12 roles, `Secretario` es el 12
     *     la base de tests del repo      11 roles, `Secretario` NO EXISTE
     *
     * O sea que **un `role_id IN (1,10,12)` escrito de memoria habría dado permiso
     * a quien tuviera el rol 12 en un colegio donde el 12 es otra cosa**. `hasRole`
     * compara el nombre literal y devuelve `false` limpiamente si el rol no existe,
     * que es el comportamiento que hace falta aquí.
     *
     * Los tres nombres **no llevan tilde**, así que no les aplica la trampa de
     * `docs/migracion/33-la-tilde-que-sql-no-ve.md` — que sí mordería a
     * `Coord académico`. Va dicho porque la próxima vez puede no ser así.
     *
     * ## Y EL SUPERUSUARIO TAMBIÉN — decidido por Joseth el 20 sep 2026
     *
     * **La regla escrita eran tres roles, y el hueco lo destapó medirlos.** `Admin` es
     * **un rol**; el administrador de verdad de este sistema es la columna
     * `users.is_superuser`, y **no son el mismo conjunto**. Medido sobre `simonbolivar`
     * antes de preguntar:
     *
     *     superusuarios vivos                12
     *     con el rol `Admin`                 10
     *     superusuario SIN el rol `Admin`     2      <- veían el botón apagado
     *     con el rol `Admin` sin superusuario 0
     *
     * Se le puso delante con esas dos personas dentro y **contestó que sí**. O sea que
     * la regla no se ensanchó por cuenta propia —eso habría sido *«crear un rol regala
     * permisos que nadie pidió»* al revés— sino con la medición delante, que es la
     * única forma en que esta clase debería moverse.
     *
     * ## `esSuperusuario()` y NO `esAdministrativo()`, aunque hoy sobre
     *
     * `esAdministrativo` es `is_superuser || isSecretario`, o sea **dos tercios de lo
     * que hace falta aquí**, y la tentación es usarlo y añadir sólo `Admin` y `Rector`.
     * No se hace, por el mismo motivo que ya está escrito en `puedeAtarFormularios`:
     * ese método lo comparten quince llamadas de dominios que no se parecen a éste, y
     * el día que alguien lo ensanche **esta puerta se ensancharía con él sin que nadie
     * lo decidiera**. `esSuperusuario` lee una columna y nada más.
     *
     * ## LO QUE EL SEED NO PUEDE PROBAR, y por eso su test fabrica la condición
     *
     * En la base de tests **los diez superusuarios tienen los diez el rol `Admin`**
     * (medido el 20 sep 2026), así que esta rama queda **tapada por la de `Admin`**: un
     * test que cogiera un superusuario del seed pasaría **exactamente igual sin esta
     * línea**. Por eso `LasEstacionesEnLaAppTest` construye el caso —un usuario llano al
     * que le enciende `is_superuser` dentro de su transacción— en vez de buscarlo.
     *
     * *Un control verde sobre una población que no distingue las dos ramas no prueba
     * nada, y es la clase de test que se escribe sin darse cuenta.*
     *
     * @param  object  $user  el `stdClass` de `User::fromToken()`
     * @param  int|null  $escritaPor  `users.id` de quien escribió la nota
     */
    public static function puedeResolverNotaDeEstacion($user, ?int $escritaPor): bool
    {
        $quien = (int) ($user->user_id ?? 0);

        if ($quien > 0 && $escritaPor !== null && $quien === $escritaPor) {
            return true;
        }

        if ($quien <= 0) {
            return false;
        }

        if (self::esSuperusuario($user)) {
            return true;
        }

        return Role::hasRole($quien, 'Admin')
            || Role::hasRole($quien, 'Secretario')
            || Role::hasRole($quien, 'Rector');
    }

    /**
     * **Bajarse el libro de Excel de OTRO docente** — las tres rutas de
     * `planilla-offline/*` con `?profesor_id=` distinto del propio.
     *
     * Fase 1 de `myvc_front/PLAN-NOTAS-SIN-INTERNET.md`, y nace de su §3.4, que es
     * una medición y no una opinión:
     *
     * ```php
     * // app/User.php:377 — pueden_editar_notas
     * if ($user->tipo == 'Profesor' && $user->profes_pueden_editar_notas == 0) abort(400, ...);
     * else if (($user->is_superuser) || $user->tipo == 'Profesor') { }
     * else abort(403, 'No tienes permiso.');
     * ```
     *
     * Ahí pasan **el tipo `Profesor` y el superusuario, y nadie más**. Un
     * coordinador con rol de admin que no sea superusuario recibe 403 tenga el rol
     * que tenga. O sea que la D4 —*coordinación puede subir el libro de cualquier
     * docente*— **no es una casilla: es un camino de autorización que no existía**,
     * y por eso se escribe aquí y no parcheando `pueden_editar_notas`, de donde
     * cuelgan los cuatro clientes.
     *
     * ## Por qué esta puerta es MÁS ancha que la de escribir, y puede serlo
     *
     * Las tres rutas son de **lectura**. Lo que sale por ellas es la planilla que
     * esa persona ya puede ver por la web —`notas/detailed`, `planillas/*`,
     * `bolfinales/*` van todas con `auth.personal`— sólo que en un `.xlsx`. La
     * escritura de la fase 2 **volverá a autorizar cada nota** contra «¿es esta
     * asignatura de este docente?» y contra el periodo, así que un libro bajado por
     * coordinación no permite escribir nada que su dueño no pudiera escribir.
     *
     * Verdad si se cumple **una** de las dos, y las dos están escritas ya:
     *
     *  1. {@see esAdministrativo} — superusuario o `Secretario`. Es quien administra
     *     la estructura del colegio.
     *  2. {@see puedeEditarPlantillaNotas} — superusuario o `can_edit_plantilla_notas`,
     *     que es **el permiso de coordinación académica** (D13 y D28 del doc 28). Es
     *     exactamente el alcance que la D4 nombra.
     *
     * ## Lo que NO entra, y es la mitad del método
     *
     * **Un docente cualquiera no pasa por ninguna de las dos ramas.** Ni por ser
     * `Profesor`, ni por ser titular de un grupo, ni por dar clase en él. Es la
     * diferencia con `pueden_editar_notas`, que deja pasar a los 53 docentes por el
     * tipo: aquí *tipo `Profesor`* no es un permiso, y por eso pedir el libro de un
     * compañero da 403.
     *
     * **Y no incluye `auth.personal`**, que es lo que ya pone la ruta: esto se
     * pregunta **además**, dentro del método, siguiendo la regla del `CLAUDE.md`
     * para cuando lo que decide la ruta es un dato del colegio entero y no del aula.
     */
    public static function puedeDescargarLaPlanillaDeOtro($user): bool
    {
        return self::esAdministrativo($user) || self::puedeEditarPlantillaNotas($user);
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
