<?php namespace App\Http\Controllers;

use Request;
use DB;

use App\User;
use App\Models\Role;
use App\Models\Permission;


class RolesController extends Controller {


	public function getIndex()
	{
		$roles = Role::allConPermisos();
		return $roles;

	}
	public function getRolesconpermisos()
	{
		$roles = Role::allConPermisos();
		return $roles;

	}

	public function putAddpermission($id)
	{
		$rol = Role::find($id);
		$per = Permission::find(Request::input('permission_id'));

		$rol->attachPermission($per);

		return $per;

	}

	public function putAddroletouser($role_id)
	{
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
		//$rol = Role::find($id)->permissions()->detach(Input::get('permission_id'));
		$res = DB::delete('delete from permission_role where permission_id = ? AND role_id = ? ', array(Input::get('permission_id'), $id));
		return $res;

	}

}