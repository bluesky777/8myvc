<?php namespace App\Http\Controllers;


use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\DB;

use App\Support\CamposQueVinieron;
use App\User;
use App\Models\TipoDocumento;

class TipoDocumentoController extends Controller {


	public function index()
	{
		return TipoDocumento::all();
	}

	public function store()
	{
		try {
			$tipo 			= new TipoDocumento;
			$tipo->tipo		= Request::input('tipo');
			$tipo->abrev	= Request::input('abrev');
			$tipo->save();

			return $tipo;
		} catch (\Exception $e) {
			abort(422, 'Datos incorrectos');
		}
	}

	/**
	 * §81, la misma de `AreasController::putUpdate`, y el caso más ancho de los
	 * seis: **las dos columnas son `NOT NULL`**, así que el cuerpo vacío dejaba
	 * la fila entera en `["", ""]` y contestaba **200 con el cuerpo vacío**.
	 *
	 * Un tipo de documento sin `abrev` no es una fila fea: `abrev` es lo que se
	 * imprime al lado del número en boletines, certificados y listados, así que
	 * vaciarla se ve en todos los papeles del colegio a la vez y no hay pantalla
	 * que enseñe que se vació.
	 */
	public function update($id)
	{
		$vinieron = CamposQueVinieron::capturar();

		$tipo = TipoDocumento::findOrFail($id);
		try {
			if ($vinieron->trae('tipo'))  { $tipo->tipo  = Request::input('tipo'); }
			if ($vinieron->trae('abrev')) { $tipo->abrev = Request::input('abrev'); }

			$tipo->save();

		} catch (\Exception $e) {
			abort(422, 'Datos incorrectos');
		}
	}

	public function destroy($id)
	{
		$tipo = TipoDocumento::findOrFail($id);
		$tipo->delete();

		return $tipo;
	}

}