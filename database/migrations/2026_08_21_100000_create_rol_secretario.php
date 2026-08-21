<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * El rol `Secretario`, que el código llevaba años buscando y que no existía.
 *
 * `Role::isSecretario()` pregunta por un rol llamado exactamente así, y
 * `AcudientesController` preguntaba otra cosa —`users.tipo == 'Secretario'`, que
 * es imposible: `tipo` solo toma los cuatro valores del `switch` de
 * `ContextoDeUsuario`—. Once sitios preguntaban por el Secretario de dos maneras
 * que no podían ser las dos, y ninguna se cumplía nunca. La consecuencia visible
 * era la contraria de lo que la línea pretendía decir: **un administrativo sin
 * `is_superuser` no podía crear ni editar acudientes**.
 * Ver docs/migracion/05-codigo-muerto-y-roto.md §30.2.
 *
 * **Por qué un rol nuevo y no `Admin`.** Decisión de Joseth, 21 ago 2026: la
 * razón de existir del rol es una secretaria **docente** que no es superusuario,
 * y con `Admin` eso no se puede — los diez `Admin` son exactamente los diez
 * `is_superuser`, así que el rol no distinguiría a nadie.
 *
 * **Esta migración no le da el rol a nadie.** Crear la fila no cambia el
 * comportamiento de ningún colegio: `isSecretario()` sigue devolviendo `false`
 * para los 2.351 usuarios hasta que alguien asigne el rol en la pantalla de
 * usuarios. Eso es a propósito — el cambio de permisos entra colegio a colegio,
 * cuando cada uno decida quién es su secretaria, y no el día del despliegue.
 *
 * **Y no toca el seed de tests.** `database/dumps/test-seed.sql` se genera desde
 * la base real —`roles` se copia entera— y hace `TRUNCATE TABLE roles` antes de
 * insertar, así que se lleva por delante lo que ponga aquí: las migraciones
 * corren ANTES del seed en `tools/construir-bd-test.sh`. Los tests que necesitan
 * un Secretario se lo crean dentro de su propia transacción, que además es la
 * única forma de comprobar el caso que importa —tener el rol sin `is_superuser`—
 * sin depender de a quién se lo haya dado un colegio.
 */
class CreateRolSecretario extends Migration
{
    private const NOMBRE = 'Secretario';

    public function up()
    {
        // `roles.name` es UNIQUE y hay dieciséis bases que llevan vidas
        // separadas: si alguna ya lo tiene creado a mano, la migración no puede
        // reventar por eso.
        if (DB::table('roles')->where('name', self::NOMBRE)->exists()) {
            return;
        }

        DB::table('roles')->insert([
            'name' => self::NOMBRE,
            'display_name' => 'Secretario(a)',
            'description' => 'Administra la estructura del colegio: alumnos, matrículas, '
                .'materias, asignaturas, titulares, configuración del año y periodos. '
                .'No crea usuarios.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down()
    {
        // Solo si no se lo han dado a nadie. Borrar el rol con gente dentro
        // dejaría filas de `role_user` apuntando a un id que ya no está, y esa
        // tabla no tiene clave foránea que lo impida.
        $rol = DB::table('roles')->where('name', self::NOMBRE)->first();

        if ($rol === null || DB::table('role_user')->where('role_id', $rol->id)->exists()) {
            return;
        }

        DB::table('roles')->where('id', $rol->id)->delete();
    }
}
