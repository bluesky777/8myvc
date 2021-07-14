<?php namespace App\Http\Controllers\Disciplina;

use App\Http\Controllers\Controller;
use Request;
use DB;

use App\User;
use App\Models\NotaComportamiento;
use App\Models\Grupo;
use App\Models\Alumno;
use App\Models\Frase;

use Carbon\Carbon;


class OrdinalesController extends Controller {


	public function putOrdinales()
	{
		$user 		= User::fromToken();
		$now 		= Carbon::now('America/Bogota');
		$year_id 	= Request::input('year_id', $user->year_id);

		$consulta = 'SELECT * FROM dis_ordinales WHERE year_id='.$year_id.' and deleted_at is null order by ordinal';
			
		$ordinales = DB::select($consulta);

		$consulta = 'SELECT c.* FROM dis_configuraciones c
			WHERE c.year_id=:year_id and c.deleted_at is null';
			
		$config = DB::select($consulta, [':year_id'		=> $year_id, ]);
		
		if (count($config) > 0) {
			$config = $config[0];
		}else{
			$consulta = 'INSERT INTO dis_configuraciones(year_id, created_at, updated_at) VALUES(?,?,?)';
			DB::insert($consulta, [$year_id, $now, $now]);
			
			$last_id = DB::getPdo()->lastInsertId();
			
			$consulta = 'SELECT c.* FROM dis_configuraciones c
				WHERE c.id=? and c.deleted_at is null';
				
			$config = DB::select($consulta, [$last_id])[0];
		
		}
		
		
		
		$consulta = 'SELECT distinct o.tipo FROM dis_ordinales o
			WHERE o.year_id=:year_id and o.deleted_at is null order by o.tipo';
			
		$tipos 	= DB::select($consulta, [':year_id'		=> $year_id,]);


		return ['ordinales' => $ordinales, 'configuracion' => $config, 'tipos' => $tipos ];
	}


	public function postStore()
	{
        $user 	        = User::fromToken();
		$now 	= Carbon::now('America/Bogota');

        $consulta = 'INSERT INTO dis_ordinales(year_id, ordinal, tipo, descripcion, pagina, created_at, updated_at) VALUES(?,?,?,?,?,?,?)';
        $datos 		= [
            Request::input('year_id'), 
            Request::input('ordinal'), 
            Request::input('tipo'),
            Request::input('descripcion'),
            Request::input('pagina'),
            $now,
            $now,
		];
		
		DB::insert($consulta, $datos);
		
		$last_id = DB::getPdo()->lastInsertId();
		$consulta = 'SELECT d.* FROM dis_ordinales d WHERE d.id=?';
			
		$ordinal = DB::select($consulta, [$last_id])[0];
        
		return (array)$ordinal;
	}


	public function putUpdate()
	{
		$user 	        = User::fromToken();
		$now 			= Carbon::now('America/Bogota');
		$ordinal_id     = Request::input('id');

        $consulta = 'UPDATE dis_ordinales SET tipo=?, ordinal=?, descripcion=?, pagina=?, updated_by=?, updated_at=? WHERE id=?';
        $datos 		= [
            Request::input('tipo'), 
            Request::input('ordinal'), 
            Request::input('descripcion'), 
            Request::input('pagina'), 
			$user->user_id,
            $now,
			$ordinal_id,
		];
		DB::update($consulta, $datos);
        
		return 'Cambiado';
	}


	public function putGuardarValor()
	{
		$user 	        = User::fromToken();
		$now 			= Carbon::now('America/Bogota');
		$ordinal_id     = Request::input('ordinal_id');
		$propiedad 		= Request::input('propiedad');

        $consulta = 'UPDATE dis_ordinales SET '.$propiedad.'=:valor, updated_by=:modificador, updated_at=:fecha WHERE id=:ordinal_id';
        $datos 		= [
            ':valor'		=> Request::input('valor'), 
            ':modificador'	=> $user->user_id, 
            ':fecha' 		=> $now,
            ':ordinal_id'	=> $ordinal_id
		];
		DB::update($consulta, $datos);
        
		return 'Cambiado';
	}


	public function putGuardarValorConfig()
	{
		$user 	        = User::fromToken();
		$now 			= Carbon::now('America/Bogota');
		$config_id     	= Request::input('config_id');
		$propiedad 		= Request::input('propiedad');

        $consulta = 'UPDATE dis_configuraciones SET '.$propiedad.'=:valor, updated_by=:modificador, updated_at=:fecha WHERE id=:id';
        $datos 		= [
            ':valor'		=> Request::input('valor'), 
            ':modificador'	=> $user->user_id, 
            ':fecha' 		=> $now,
            ':id'			=> $config_id
		];
		DB::update($consulta, $datos);
        
		return 'Cambiado';
	}

	
	public function putDestroy()
	{
		$user 	        = User::fromToken();
		$now 			= Carbon::now('America/Bogota');

        $consulta 		= 'UPDATE dis_ordinales SET deleted_at=?, deleted_by=? WHERE id=?';
		$datos 			= [$now, $user->user_id, Request::input('ordinal_id')];
		
		DB::delete($consulta, $datos);
        
		return 'Eliminado';
	}

}