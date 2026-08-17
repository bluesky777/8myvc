<?php namespace App\Http\Controllers;

use Request;
use DB;

use App\User;
use App\Models\Role;
use App\Models\Permission;


class RolesController extends Controller {

	/**
	 * Estas rutas no tenían ninguna verificación: cualquiera sin token podía
	 * llamar a addroletouser y asignarse el rol que quisiera. Se exige token
	 * para leer y, además, el permiso can_edit_usuarios para escribir, que es
	 * el mismo que el frontend usa para dar acceso a la pantalla de usuarios.
	 */
	private function exigirAdminUsuarios()
	{
		$user = User::fromToken();

		if ($user->is_superuser) {
			return $user;
		}

		if (!is_array($user->perms) || !in_array('can_edit_usuarios', $user->perms)) {
			abort(403, 'No tienes permiso para administrar roles.');
		}

		return $user;
	}


	public function getIndex()
	{
		User::fromToken();

		$roles = Role::allConPermisos();
		return $roles;

	}
	public function getRolesconpermisos()
	{
		User::fromToken();

		$roles = Role::allConPermisos();
		return $roles;

	}

	public function putAddpermission($id)
	{
		$this->exigirAdminUsuarios();

		$rol = Role::find($id);
		$per = Permission::find(Request::input('permission_id'));

		$rol->attachPermission($per);

		return $per;

	}

	public function putAddroletouser($role_id)
	{
		$this->exigirAdminUsuarios();

		$rol = Role::find($role_id);
		$user = User::find(Request::input('user_id'));

		$roles = Role::getUserRoles($user->id);

		$found = false;
		for ($i=0; $i < count($roles); $i++) { 
			if ($roles[$i]->role_id == $role_id) {
				$found = true;
				break;
			}
		}
		
		if ($found) {
			abort(400, 'Usuario ya tiene ese role.');
		}else{
			$consulta = 'INSERT INTO role_user(user_id, role_id) 
				VALUES(:user_id, :role_id)';
			$roles = DB::select($consulta, array(
				':user_id'		=> $user->id,
				':role_id'		=> $rol->id,
			));
		}
		
		return $user;
	}

	public function putRemoveroletouser($role_id)
	{
		$this->exigirAdminUsuarios();

		$rol = Role::find($role_id);
		$user = User::find(Request::input('user_id'));

		// if (!$user->hasRole($rol->name)) {
		// 	abort(400, 'Usuario no tiene ese role para eliminar.');
		// }else{
		// 	$user->detachRole($rol);
		// 	$user->save();
		// }

		$consulta = 'DELETE FROM role_user WHERE user_id=:user_id AND role_id=:role_id';
		$roles = DB::delete($consulta, array(
			':user_id'		=> $user->id,
			':role_id'		=> $rol->id,
		));

		return $roles;
	}

	public function putRemovepermission($id)
	{
		$this->exigirAdminUsuarios();

		// OJO: esta línea está rota desde antes de este cambio. La facade Input
		// no existe desde Laravel 6, así que aquí revienta con "class not found".
		// El botón "quitar permiso" de la pantalla de roles no funciona hoy.
		// No lo arreglo aquí para no mezclar un cambio funcional con el de seguridad.
		//$rol = Role::find($id)->permissions()->detach(Input::get('permission_id'));
		$res = DB::delete('delete from permission_role where permission_id = ? AND role_id = ? ', array(Input::get('permission_id'), $id));
		return $res;

	}

}