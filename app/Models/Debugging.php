<?php namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

use DB;

class Debugging extends Model {
	

	protected $table = 'debugging';
	

	public static function pin($accion, $dato1=null, $dato2=null, $created_by=null)
	{
		$deb 			= new Debugging;
		$deb->accion 	= $accion;
		$deb->dato1 	= $dato1;
		$deb->dato2 	= $dato2;
		$deb->created_by = $created_by;
		$deb->save();
		return $deb;
	}
}