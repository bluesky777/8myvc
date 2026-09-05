<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * El permiso `can_edit_plantilla_notas`, y **a quién NO se le siembra**.
 *
 * Es la única migración de esquema que le queda a la **Entrega 1** de
 * [28-competencias-e-indicadores.md](../../docs/migracion/28-competencias-e-indicadores.md)
 * (§5.1.a): las dos decisiones de Joseth del 2 sep —la plantilla por año y el
 * candado binario— se comieron una la columna `numero_periodo` y otra las cuatro
 * `can_change_*`. Queda ésta, calcada de
 * `2026_08_25_200000_create_permiso_can_view_auditoria`.
 *
 * Gobierna las nueve rutas de `plantilla-notas`, que son las que sacan la
 * plantilla del colegio de phpMyAdmin. **Un docente no configura la plantilla del
 * colegio**: ése es justo el punto de la entrega.
 *
 * ## Por qué ésta NO reparte, al revés que `can_view_auditoria`
 *
 * Aquélla **cerraba**: seis rutas vivas iban con `auth.personal` y cualquiera del
 * personal leía el rastro de su rector, así que no sembrarla habría dejado a los
 * dieciséis colegios sin una pantalla que ya usaban. Aquí no hay nada que quitar
 * —**hoy no existe ninguna ruta que edite la plantilla**, se edita a mano en la
 * base—, así que sembrarlo a un rol que nadie ha nombrado sería conceder de paso
 * lo que nadie pidió: [[crear-rol-no-regala-permisos]] en su forma literal.
 *
 * **Y no deja el módulo inerte el primer día**, que es la trampa de no sembrar:
 * `Autoriza::puedeEditarPlantillaNotas()` deja pasar al superusuario por encima,
 * como todos los criterios de esa clase. O sea que el día del despliegue la
 * pantalla la pueden usar los superusuarios de cada colegio —once en
 * `simonbolivar`— y el colegio decide **desde su pantalla de roles, sin
 * migración** si además la usa rectoría o coordinación.
 *
 * > ⚠️ **Eso es una decisión que hay que tomar colegio a colegio y conviene
 * > tomarla el día del despliegue, no descubrirla.** La diferencia con
 * > `Coord académico` —que existe desde 2018 y tiene **cero usuarios**— es que
 * > aquí el vacío no deja la función inerte: la deja **sólo para superusuarios**,
 * > que es un estado razonable y por eso no se nota. Si dentro de un año nadie
 * > usa la plantilla, ésta es la primera fila que hay que mirar.
 *
 * ## Y lo que este permiso hará ADEMÁS, cuando llegue el candado
 *
 * La decisión 5 de Joseth (2 sep 2026) deja exento del candado del docente a
 * quien tenga este permiso: quien puso la plantilla puede corregir una errata en
 * **una** asignatura sin cambiar la del colegio entero. **El candado no entra en
 * esta tanda** —cambia respuestas de éxito por 403 en nueve rutas que ya existen y
 * §5.1.e exige contar antes el censo de `por_defecto = 1` en los diecisiete, que
 * no se puede correr desde una sesión—, así que hoy este permiso **sólo abre**.
 * Va dicho aquí porque el día que el candado entre, esta fila cambia de significado
 * sin que nadie toque esta migración.
 *
 * ## No toca el seed de tests, y no podría
 *
 * `database/dumps/test-seed.sql` hace `TRUNCATE TABLE permissions` y
 * `permission_role`, y las migraciones corren ANTES del seed en
 * `tools/construir-bd-test.sh`: lo que escriba aquí se lo lleva por delante. Los
 * tests se montan permiso y rol dentro de su propia transacción, que además es la
 * única forma de comprobar el caso que importa —**tener el permiso sin
 * `is_superuser`**— sin depender de a quién se lo haya dado un colegio.
 */
class CreatePermisoCanEditPlantillaNotas extends Migration
{
    private const PERMISO = 'can_edit_plantilla_notas';

    public function up()
    {
        // Dieciséis bases con vidas separadas y `permissions.name` es UNIQUE: si
        // alguna lo tiene ya creado a mano, esto no puede reventar por eso.
        $yaExiste = DB::table('permissions')->where('name', self::PERMISO)->exists();

        if ($yaExiste) {
            return;
        }

        DB::table('permissions')->insert([
            'name' => self::PERMISO,
            'display_name' => 'Editar la plantilla de notas del colegio',
            'description' => 'Crear y cambiar las unidades y subunidades por defecto del año, '
                .'que son las que se siembran solas en la rejilla de cada asignatura. '
                .'Es del colegio, no del aula: lo que se toque aquí llega a todos los docentes.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down()
    {
        $permiso = DB::table('permissions')->where('name', self::PERMISO)->value('id');

        if ($permiso === null) {
            return;
        }

        // Lo haría solo la clave foránea —`permission_role_permission_id_foreign`
        // es `ON DELETE CASCADE`— y va explícito por lo mismo que en
        // `can_view_auditoria`: una migración que se apoya en un `CASCADE` que no
        // se ve en el fichero es la que sorprende el día que cambia el esquema.
        // Aquí `up()` no reparte a nadie, pero un colegio puede haberlo repartido
        // desde su pantalla de roles y esas filas son suyas, no de la migración.
        DB::table('permission_role')->where('permission_id', $permiso)->delete();
        DB::table('permissions')->where('id', $permiso)->delete();
    }
}
