<?php namespace App\Models;


use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use DB;


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
		
		$permisos = DB::select(DB::raw($consulta), array(':role_id' => $this->id));
		
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
}