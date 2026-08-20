<?php namespace App\Models;


use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
/**
 * Las columnas de `roles`, tal como están en el esquema congelado.
 *
 * Generado desde database/schema/mysql-schema.sql — no se edita a mano.
 * Ver tools/columnas-en-los-modelos.php.
 *
 * --- columnas de la tabla, generadas por tools/columnas-en-los-modelos.php ---
 *
 * @property int $id
 * @property string $name
 * @property int $created_by
 * @property int $updated_by
 * @property int $deleted_by
 * @property string $display_name
 * @property string $description
 * @property string $deleted_at
 * @property string $created_at
 * @property string $updated_at
 * --- fin de las columnas generadas ---
 *
 * Y los atributos que NO son columnas: el código se los cuelga al modelo en
 * tiempo de ejecución para armar la respuesta, que es un patrón repetido por
 * todo el proyecto. Eloquent los guarda entre los atributos y salen en el JSON,
 * así que forman parte del contrato con el frontend igual que las columnas;
 * anotarlos es lo que permite que el análisis siga avisando de un nombre mal
 * escrito en vez de callarse con todos.
 *
 * @property array $perms  los permisos del rol, resueltos aparte
 */


class Role extends Model
{

	use SoftDeletes;
	protected $softDelete = true;

	/**
     * The users that belong to the role.
     */
    public function users()
    {
        return $this->belongsToMany('App\User');
    }


	// Devolveremos los permisos del rol, detallado o solo el texto nombre.
	public function permissions($detailed=false)
	{

		$consulta = 'SELECT pm.name, pm.display_name, pm.description from permission_role pmr
				inner join permissions pm on pm.id = pmr.permission_id 
					and pmr.role_id = :role_id';
		
		$permisos = DB::select($consulta, array(':role_id' => $this->id));
		
		$perms = array();

		foreach ($permisos as $permiso) {
			if ($detailed) {
				array_push($perms, $permiso);
			}else{
				array_push($perms, $permiso->name);
			}
		}

		return $perms;
	}


	public static function allConPermisos($detailed=false)
	{
		$roles = Role::all();

		foreach ($roles as $rol) {
			$rol->perms = $rol->permissions($detailed);
		}
		return $roles;

	}

	public static function getUserRoles($user_id) {
		$consulta = 'SELECT ru.role_id, r.name FROM users u 
			INNER JOIN role_user ru ON ru.user_id = u.id
			INNER JOIN roles r ON r.id = ru.role_id and r.deleted_at is null
			WHERE u.id = :user_id';
		$roles = DB::select($consulta, array(
			':user_id'		=> $user_id,
		));
		return $roles;
	}

	public static function isCoorDisciplinario($user_id) {
		$roles = Role::getUserRoles($user_id);
		$isCoorDisciplinario = false;
		for ($i=0; $i < count($roles); $i++) { 
			if ($roles[$i]->name == 'Coord disciplinario') {
				$isCoorDisciplinario = true;
				break;
			}
		}
		return $isCoorDisciplinario;
	}

	public static function isSecretario($user_id) {
		return Role::hasRole($user_id, 'Secretario');
	}

	public static function hasRole($user_id, $role) {
		$roles = Role::getUserRoles($user_id);
		$isRole = false;
		for ($i=0; $i < count($roles); $i++) { 
			if ($roles[$i]->name == $role) {
				$isRole = true;
				break;
			}
		}
		return $isRole;
	}
}