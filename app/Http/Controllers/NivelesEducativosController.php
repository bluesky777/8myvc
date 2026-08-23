<?php namespace App\Http\Controllers;

use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\DB;

use App\Support\CamposQueVinieron;
use App\User;
use App\Models\NivelEducativo;


class NivelesEducativosController extends Controller {

	public function getIndex()
	{
		return NivelEducativo::orderBy("orden")->get();
	}


	public function postStore()
	{
		try {
			$nivel = new NivelEducativo;
			$nivel->nombre	=	Request::input('nombre');
			$nivel->abrev	=	Request::input('abrev');
			$nivel->orden	=	Request::input('orden');
			$nivel->save();

			return $nivel;
		} catch (\Exception $e) {
			abort(422, 'Datos incorrectos');
		}
	}


	public function getShow($id)
	{
		return NivelEducativo::findOrFail($id);
	}


	/**
	 * §81, la misma de `AreasController::putUpdate`: con el cuerpo vacío dejaba
	 * `nombre` en `''` —es `NOT NULL`— y contestaba **200 devolviendo el nivel
	 * ya vacío**. Son cuatro filas en todo el colegio y de ellas cuelgan los
	 * catorce grados.
	 *
	 * El `try/catch` de aquí abajo **no lo impedía y no podía impedirlo**: con
	 * `strict => false` el `UPDATE` no lanza nada que catchear, sólo un aviso
	 * 1048. Es la diferencia con `postStore`, donde el mismo `try/catch` sí ve
	 * el error porque el `INSERT` sí lo lanza (05 §78).
	 */
	public function putUpdate($id)
	{
		$vinieron = CamposQueVinieron::capturar();

		$nivel = NivelEducativo::findOrFail($id);
		try {
			if ($vinieron->trae('nombre')) { $nivel->nombre = Request::input('nombre'); }
			if ($vinieron->trae('abrev'))  { $nivel->abrev  = Request::input('abrev'); }
			if ($vinieron->trae('orden'))  { $nivel->orden  = Request::input('orden'); }

			$nivel->save();
			return $nivel;
		} catch (\Exception $e) {
			abort(422, 'Datos incorrectos');
		}
	}


	public function deleteDestroy($id)
	{
		$nivel = NivelEducativo::findOrFail($id);
		$nivel->delete();

		return $nivel;
	}

}