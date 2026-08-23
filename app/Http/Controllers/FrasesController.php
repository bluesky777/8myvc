<?php namespace App\Http\Controllers;

use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\DB;

use App\Support\CamposQueVinieron;
use App\User;
use App\Models\Frase;


class FrasesController extends Controller {


	public function getIndex()
	{
		$user = User::fromToken();

		$frases = Frase::where('year_id', '=', $user->year_id)->get();
		return $frases;
	}

	public function postStore()
	{
		$user = User::fromToken();
		
		$frase = new Frase;
		$frase->frase		= Request::input('frase');
		$frase->tipo_frase	= Request::input('tipo_frase');
		$frase->year_id		= $user->year_id;
		$frase->save();

		return $frase;
	}



	/**
	 * §81, la misma de `AreasController::putUpdate`: con el cuerpo vacío dejaba
	 * `tipo_frase` en `''` —es `NOT NULL`— y `frase` en `null`, y contestaba
	 * **200 devolviendo la fila ya vaciada**.
	 *
	 * Aquí pesa más que en los otros cinco porque las frases son texto escrito a
	 * mano por el colegio, una a una: son 426 en la copia de producción y no se
	 * regeneran de ningún sitio.
	 *
	 * §84 — **lo que este método NO comprueba**: `getIndex` filtra por
	 * `year_id`, y esto no. Se puede editar una frase que no sale en el propio
	 * listado. Es la misma forma que `EscalasDeValoracionController::deleteDestroy`,
	 * donde escribir en años pasados **está decidido a propósito** (05 §27.4),
	 * así que no se cierra por iniciativa propia: queda fijado por
	 * `EscrituraDeCatalogoDeOtroAnioTest` y anotado para el colegio.
	 */
	public function putUpdate($id)
	{
		$user = User::fromToken();
		$vinieron = CamposQueVinieron::capturar();

		$frase = Frase::findOrFail($id);

		if ($vinieron->trae('frase'))      { $frase->frase      = Request::input('frase'); }
		if ($vinieron->trae('tipo_frase')) { $frase->tipo_frase = Request::input('tipo_frase'); }

		$frase->save();
		return $frase;
	}


	public function deleteDestroy($id)
	{
		$user = User::fromToken();

		$frase = Frase::findOrFail($id);
		$frase->delete();
		return $frase;
	}

}