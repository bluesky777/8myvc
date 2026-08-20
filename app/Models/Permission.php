<?php namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Extendía `Zizaco\Entrust\EntrustPermission`, y ese paquete no está instalado.
 *
 * No es que se quitara en esta migración: no aparece en `composer.json` ni en
 * `composer.lock`, así que llevaba fuera desde antes. Cargar esta clase era un
 * fatal, y con ella caían `GET api/permissions` y `PUT api/roles/addpermission`.
 *
 * De Entrust solo se usaba la tabla y `attachPermission()`. La tabla se declara
 * aquí y el attach lo hace ahora `RolesController` con un INSERT, igual que su
 * hermano `putRemovepermission` ya hacía el DELETE.
 */
/**
 * Las columnas de `permissions`, tal como están en el esquema congelado.
 *
 * Generado desde database/schema/mysql-schema.sql — no se edita a mano.
 * Ver tools/columnas-en-los-modelos.php.
 *
 * --- columnas de la tabla, generadas por tools/columnas-en-los-modelos.php ---
 *
 * @property int $id
 * @property string $name
 * @property ?string $display_name
 * @property ?string $description
 * @property ?string $deleted_at
 * @property ?string $created_at
 * @property ?string $updated_at
 * --- fin de las columnas generadas ---
 */
class Permission extends Model
{
	use SoftDeletes;

	protected $table = 'permissions';

	protected $fillable = ['name', 'display_name', 'description'];
}
