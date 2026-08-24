<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * El permiso `can_view_auditoria`, y a quién se le siembra.
 *
 * Es la pieza 1 de la **decisión 3** de docs/migracion/18-auditoria.md: quién ve
 * la auditoría son «las tres cosas a la vez» —permiso por rol, sembrado sólo a
 * rectoría y coordinación, y cada quien ve siempre lo suyo sin permiso—. Las
 * otras dos piezas viven en `App\Support\Autoriza` y en los dos controladores.
 *
 * **Por qué ésta SÍ reparte y `create_rol_secretario` no.** Aquélla creaba un rol
 * vacío a propósito, para que el cambio entrara colegio a colegio. Aquí no se
 * puede: este permiso **no abre nada, cierra**. Las seis rutas viejas van hoy con
 * `auth.personal` y cualquiera del personal lee el rastro de cualquiera, incluido
 * el de su rector. Si esta migración no se lo diera a nadie, el día del despliegue
 * **los dieciséis colegios se quedarían sin la pantalla** — que es literalmente la
 * pregunta 2 de la ficha del lote AUD-5, puesta ahí como aviso. Sembrarlo a los
 * dos roles que la decisión nombra es lo que hace que esto sea un endurecimiento y
 * no una avería.
 *
 * **Y a quién NO se le siembra, que es la mitad que hay que leer.** A
 * `Coord disciplinario` no. La decisión 3 dice «rector y coordinación», y en
 * `roles` hay DOS coordinaciones —`Coord académico` y `Coord disciplinario`—:
 * quién lleva la disciplina no es obviamente quién puede ver quién cambió una
 * nota, y eso lo decide el colegio. Queda en el lado seguro; añadirlo es una fila
 * desde la pantalla de roles, sin migración.
 *
 * **Un profesor que hoy entra a `/panel/bitacora` puede dejar de poder**, y es
 * deliberado: hasta hoy eso lo gobernaba `califica`, que tiene cualquiera que
 * ponga notas. Está escrito en voz alta en la decisión 4. Si un colegio quiere que
 * sigan entrando, la respuesta no es revertir esto: es sembrarles el permiso.
 *
 * **No toca el seed de tests, y no podría.** `database/dumps/test-seed.sql` hace
 * `TRUNCATE TABLE permissions` y `permission_role` antes de insertar, y las
 * migraciones corren ANTES del seed en `tools/construir-bd-test.sh`: lo que
 * escriba aquí se lo lleva por delante. Los tests se montan permiso y rol dentro
 * de su propia transacción — que además es la única forma de comprobar el caso que
 * importa, **tener el permiso sin `is_superuser`**, sin depender de a quién se lo
 * haya dado un colegio. Es lo que ya dejó documentado `create_rol_secretario`.
 */
class CreatePermisoCanViewAuditoria extends Migration
{
    private const PERMISO = 'can_view_auditoria';

    /** Los de la tabla `roles`; ver arriba por qué no está `Coord disciplinario`. */
    private const ROLES = ['Rector', 'Coord académico'];

    public function up()
    {
        // Dieciséis bases con vidas separadas y `permissions.name` es UNIQUE: si
        // alguna lo tiene ya creado a mano, esto no puede reventar por eso.
        $permiso = DB::table('permissions')->where('name', self::PERMISO)->value('id');

        if ($permiso === null) {
            $permiso = DB::table('permissions')->insertGetId([
                'name' => self::PERMISO,
                'display_name' => 'Ver la auditoría',
                'description' => 'Ver el rastro de OTRAS personas: sus ingresos, sus intentos '
                    .'de entrar y quién cambió una nota. Lo propio se ve siempre, sin este permiso.',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        foreach (self::ROLES as $nombre) {
            $rol = DB::table('roles')->where('name', $nombre)->value('id');

            // Un colegio puede haber renombrado o borrado un rol en dieciséis
            // vidas separadas: que falte uno no puede impedir que el otro lo
            // reciba, y quedarse a medias es mejor que no sembrar ninguno.
            if ($rol === null) {
                continue;
            }

            $yaLoTiene = DB::table('permission_role')
                ->where('permission_id', $permiso)
                ->where('role_id', $rol)
                ->exists();

            if (! $yaLoTiene) {
                DB::table('permission_role')->insert([
                    'permission_id' => $permiso,
                    'role_id' => $rol,
                ]);
            }
        }
    }

    public function down()
    {
        $permiso = DB::table('permissions')->where('name', self::PERMISO)->value('id');

        if ($permiso === null) {
            return;
        }

        // El borrado de `permission_role` **lo haría solo** la clave foránea
        // —`permission_role_permission_id_foreign` es `ON DELETE CASCADE`, y aquí
        // sí la hay, al revés que en `role_user`, donde `create_rol_secretario`
        // tuvo que comprobar a mano—. Va explícito porque una migración que se
        // apoya en un `CASCADE` que no se ve en el fichero es la que sorprende el
        // día que alguien cambia el esquema.
        DB::table('permission_role')->where('permission_id', $permiso)->delete();
        DB::table('permissions')->where('id', $permiso)->delete();
    }
}
