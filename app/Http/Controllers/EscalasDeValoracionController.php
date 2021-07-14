<?php namespace App\Http\Controllers;


//use Request;
use Illuminate\Http\Request;
use DB;
use Carbon\Carbon;

use App\User;
use App\Models\EscalaDeValoracion;


class EscalasDeValoracionController extends Controller {

	public function getIndex()
	{
		$user 	= User::fromToken();

		$consulta 	= 'SELECT * FROM escalas_de_valoracion WHERE year_id=? and deleted_at is null order by orden asc';
		$year_id 	= $user->year_id ? $user->year_id : 1;
		$escalas 	= DB::select($consulta, [$year_id]);

		return $escalas;
	}


	public function postStore()
	{
		$user 	= User::fromToken();
		$now 	= Carbon::now('America/Bogota');

		$consulta 	= 'INSERT INTO escalas_de_valoracion(desempenio, orden, valoracion, porc_inicial, porc_final, year_id, perdido, created_at) 
														VALUES("SUPERIOR", 5, "S", 91, 100, ?, 0, ?)';
		DB::insert($consulta, [ $user->year_id, $now ]);

		$consulta 	= 'SELECT * FROM escalas_de_valoracion WHERE year_id=? and deleted_at is null order by id desc';
		$escala 	= DB::select($consulta, [$user->year_id])[0];


		return (array)$escala;
	}


	public function putUpdate(Request $request)
	{
		$user 	= User::fromToken();
		$now 	= Carbon::now('America/Bogota');

		$consulta 	= 'UPDATE escalas_de_valoracion SET porc_inicial=:ini, porc_final=:fin, desempenio=:desemp, descripcion=:descripcion, icono_adolescente=:adolesc, icono_infantil=:infantil, orden=:orden, perdido=:perdido, valoracion=:valoracion, updated_at=:updated_at
						WHERE id=:id';
		$escalas 	= DB::update($consulta, [ ':ini' => $request->porc_inicial, ':fin' => $request->porc_final, ':desemp' => $request->desempenio, ':descripcion' => $request->descripcion, ':adolesc' => $request->icono_adolescente, ':infantil' => $request->icono_infantil, ':orden' => $request->orden, ':perdido' => $request->perdido, ':valoracion' => $request->valoracion, 'updated_at' => $now, ':id' => $request->id ]);

		return 'Guardado';

	}


	public function deleteDestroy($id)
	{
		$user 	= User::fromToken();
		$now 	= Carbon::now('America/Bogota');

		$consulta 	= 'UPDATE escalas_de_valoracion SET deleted_at=?  WHERE `id`=?';
		$escalas 	= DB::update($consulta, [ $now, $id ]);

		return 'En papelera';
	}

}