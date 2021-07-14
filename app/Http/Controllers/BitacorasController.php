<?php namespace App\Http\Controllers;

use App\User;
use DB;
use Carbon\Carbon;

class BitacorasController extends Controller {

	public function getIndex($user_id='')
	{
		$user = User::fromToken();

		if ($user_id=='') {
			$user_id = $user->user_id;
		}

		$consulta = 'SELECT * FROM bitacoras where created_by=? order by id desc ';
		$bits = DB::select($consulta, array($user_id));

		return $bits;
	}

	public function postStore()
	{
		
	}



	public function putUpdate($id)
	{
		//
	}


	public function deleteDestroy($id)
	{
		$user 	= User::fromToken();
		$now 	= Carbon::now('America/Bogota');
		
		DB::update('UPDATE bitacoras SET deleted_at=? WHERE id=?', [$now, $id]);
		
		return 'Bitácora eliminada'; 
	}

}