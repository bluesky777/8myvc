<?php

namespace Tests\Contrato;

use App\Models\Role;
use App\Support\Autoriza;
use Illuminate\Support\Facades\DB;

/**
 * **§113. Qué decide de verdad un rol, medido y no leído.**
 *
 * Sale del barrido por tipo de token del lote I. Los números que lo motivan están
 * en [noche-2026-08-23/i.md](../../docs/migracion/noche-2026-08-23/i.md), y el que
 * manda es éste: **un `Usuario` con cero roles alcanza 145 rutas y un superusuario
 * 170**. Entre medias no hay ningún escalón de rol: los once que tiene el colegio
 * casi no separan nada.
 *
 * Este fichero no arregla nada —crear un rol o darle usuarios a otro cambia de golpe
 * quién puede qué en dieciséis colegios, y eso espera a Joseth en el
 * [09 §5](../../docs/migracion/09-pendientes.md)—. **Fija lo que hoy es cierto**, que
 * es lo que hace falta para que ese día se decida sobre un dato y, sobre todo, para
 * que quien cree el rol que falta **se entere de lo que acaba de mover** en vez de
 * descubrirlo en producción.
 */
class LoQueDecideUnRolTest extends CasoDeContrato
{
    /**
     * **`Autoriza::esAdministrativo()` es hoy `is_superuser` a secas.**
     *
     * Su segunda rama es `Role::isSecretario()`, que pregunta por un rol llamado
     * `'Secretario'` — y en la tabla `roles` **no existe**: los once son Admin,
     * Profesor, Alumno, Acudiente, Manager, Asistente, Enfermero, Coord
     * disciplinario, Coord académico, Rector y Psicólogo.
     *
     * El docblock del método deja pendiente «quién es el Secretario» pero no dice
     * que el rol al que pregunta no exista, así que **se lee como si tuviera dos
     * ramas cuando tiene una**. De ahí cuelgan las escrituras de alumnos, de
     * acudientes y los tres `forcedelete`.
     *
     * Si alguien crea ese rol, este test se pone rojo. **Eso es lo que hace**: no
     * impedirlo, avisar de que en ese momento cambia quién puede qué.
     */
    public function test_el_rol_secretario_no_existe_y_por_eso_administrativo_es_superusuario(): void
    {
        $existe = DB::table('roles')->where('name', 'Secretario')->exists();

        $this->assertFalse($existe,
            "Se ha creado el rol 'Secretario'. Eso NO es un fallo de este test: es que\n"
            ."`Autoriza::esAdministrativo()` acaba de dejar de ser `is_superuser` a secas,\n"
            .'y con él cambian las escrituras de alumnos, las de acudientes y los tres forcedelete.');

        // Y la consecuencia, comprobada por el camino que de verdad se usa: para
        // cualquiera que no sea superusuario, `esAdministrativo` es falso, lleve el
        // rol que lleve.
        $conRol = DB::selectOne('SELECT u.id FROM users u
            INNER JOIN role_user ru ON ru.user_id = u.id
            WHERE u.is_superuser = 0 AND u.deleted_at IS NULL ORDER BY u.id LIMIT 1');

        $this->assertNotNull($conRol, 'El seed tiene que traer alguien con rol y sin superusuario.');

        $this->assertFalse(Role::isSecretario($conRol->id),
            'Nadie puede ser Secretario mientras el rol no exista.');
        $this->assertFalse(Autoriza::esAdministrativo((object) ['is_superuser' => 0, 'user_id' => $conRol->id]));
        $this->assertTrue(Autoriza::esAdministrativo((object) ['is_superuser' => 1, 'user_id' => $conRol->id]));
    }

    /**
     * **Los 19 permisos cuelgan de un rol que no tiene a nadie.**
     *
     * Dieciséis de los diecinueve son del rol `Manager`; los otros tres son los
     * `can_work_like_*` de Profesor, Alumno y Acudiente, que tampoco los lee nadie.
     * Y `Manager` tiene **cero usuarios**, igual que `Asistente`, `Coord académico`
     * y `Rector`.
     *
     * El backend lee un permiso **en un solo sitio** —`RolesController:28`, con
     * `can_edit_usuarios`—, y como el único rol que lo tiene está vacío, ese `if`
     * sólo lo pasa un superusuario, que ya salió por el `return` de la línea de
     * arriba. O sea que **el sistema de permisos hoy no decide nada**.
     *
     * Se fija con el número, no con la ausencia: si mañana alguien mete usuarios en
     * `Manager`, este test cae y hay que ir a mirar qué acaba de abrirse.
     */
    public function test_los_permisos_cuelgan_de_un_rol_vacio(): void
    {
        $deManager = DB::table('permission_role')
            ->join('roles', 'roles.id', '=', 'permission_role.role_id')
            ->where('roles.name', 'Manager')->count();

        $this->assertSame(16, $deManager,
            'Cambió cuántos permisos cuelgan de Manager. Mira qué se abrió o se cerró.');

        foreach (['Manager', 'Asistente', 'Coord académico', 'Rector'] as $rol) {
            $cuantos = DB::table('role_user')
                ->join('roles', 'roles.id', '=', 'role_user.role_id')
                ->where('roles.name', $rol)->count();

            $this->assertSame(0, $cuantos,
                "El rol '{$rol}' ha dejado de estar vacío. Sus permisos empiezan a decidir hoy.");
        }
    }

    /**
     * Y los tres roles que **sí** deciden algo, para que la lista no se lea como
     * «los roles no sirven para nada».
     *
     * `Psicólogo` gobierna dos columnas de la ficha del alumno (`nee`,
     * `nee_descripcion`) en `AlumnosController`; `Enfermero`, los antecedentes en
     * `EnfermeriaController`; y `Coord disciplinario` **no gobierna nada todavía** —su
     * método existe y no tiene llamantes, y está documentado en el propio
     * `Role::isCoorDisciplinario()`—.
     *
     * Los tres existen en la tabla, así que la diferencia con `Secretario` no es que
     * el nombre esté mal escrito en un sitio: es que a ése le falta la fila.
     */
    public function test_los_tres_roles_que_deciden_existen(): void
    {
        foreach (['Psicólogo', 'Enfermero', 'Coord disciplinario'] as $rol) {
            $this->assertTrue(DB::table('roles')->where('name', $rol)->exists(),
                "El rol '{$rol}' ha desaparecido de la tabla, y hay código que pregunta por él.");
        }

        // La tilde de `Psicólogo` no es decorativa: `Role::hasRole()` compara en PHP
        // y no la salva la collation de MySQL. Si alguien la quita de la fila, la
        // rama del psicólogo deja de entrar sin que falle nada.
        $this->assertTrue(DB::table('roles')->where('name', 'Psicólogo')->exists(),
            'El rol Psicólogo lleva tilde en la tabla y la comparación de hasRole() es en PHP.');
    }

    /**
     * **El escalón está en `users.tipo`, no en el rol**, y esto lo fija por el lado
     * que se puede comprobar sin barrer 539 rutas.
     *
     * Un `Usuario` sin ningún rol pasa `auth.personal` igual que un profesor y que un
     * superusuario; un alumno y un acudiente no. Ésa es toda la separación que hay
     * antes de llegar al controlador, y es la que explica el 8 / 10 / 145 del barrido.
     */
    public function test_el_guard_del_personal_no_mira_ningun_rol(): void
    {
        $sinRol = DB::selectOne('SELECT u.username FROM users u
            INNER JOIN periodos p ON p.id = u.periodo_id AND p.deleted_at IS NULL
            WHERE u.tipo = "Usuario" AND u.is_active = 1 AND u.deleted_at IS NULL
              AND u.is_superuser = 0
              AND NOT EXISTS (SELECT 1 FROM role_user ru WHERE ru.user_id = u.id)
            ORDER BY u.id LIMIT 1');

        $this->assertNotNull($sinRol,
            'El seed tiene que traer un Usuario sin superusuario y sin ningún rol.');

        // `GET api/profesores` lleva `auth.personal`, comprobado en `routes/api/`
        // y no supuesto: el primer intento de este test usó `GET api/grupos`, que
        // **no lo lleva** —es uno de los catálogos que el 08 dejó abiertos a la
        // espera de decisión, y `AutorizacionTest` ya lo tiene anotado así—. Con
        // ella el caso salía 200 para un alumno y parecía que el guard no existía.
        $this->withToken($this->tokenDe($sinRol->username))
            ->getJson('/api/profesores')->assertStatus(200);

        foreach (['Alumno', 'Acudiente'] as $tipo) {
            $this->withToken($this->tokenDe($this->usuarioDeTipo($tipo)->username))
                ->getJson('/api/profesores')->assertStatus(403);
        }
    }
}
