<?php namespace App\Http\Controllers;

use Request;
use DB;

use App\User;
use App\Models\Pais;


class PaisesController extends Controller {


	public function getIndex()
	{
		$consulta = 'SELECT * FROM paises where deleted_at is null';
		return DB::select($consulta);
		//return Pais::all();
	}


	public function postStore()
	{
		
		DB::insert('INSERT INTO paises(pais) VALUES(?)', [Request::input('pais_new')]);
		
		$consulta = 'SELECT * FROM paises where deleted_at is null';
		return DB::select($consulta);
	}




	public function update($id)
	{
		$pais = Pais::findOrFail($id);
		try {

			$pais->pais		=	Request::input('pais');
			$pais->abrev	=	Request::input('abrev');
			$pais->save();

			$pais->save();
			
		} catch (Exception $e) {
			return $e;
		}
	}

	public function destroy($id)
	{
		$pais = Pais::findOrFail($id);
		$pais->delete();

		return $pais;
	}

}