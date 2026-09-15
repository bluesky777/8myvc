<?php namespace App\Http\Controllers;

use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\DB;

use App\Support\Autoriza;
use App\Support\CamposQueVinieron;
use App\User;
use App\Models\Frase;
use App\Services\Auditoria;


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

		Auditoria::registrar()
			->crear('frase', (int) $frase->id)
			->en(year: (int) $frase->year_id)
			->a(['frase' => $frase->frase, 'tipo_frase' => $frase->tipo_frase])
			->guardar();

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
	 * §84 — **lo que este método no comprobaba, y comprueba desde el 14 sep 2026**:
	 * `getIndex` filtra por `year_id` y esto no, así que se podía editar una frase
	 * que no sale en el propio listado — y **una frase editada cambia lo que dice
	 * un boletín ya impreso**, porque `FraseAsignatura::deAlumno` hace
	 * `IFNULL(f.frase, fa.frase)`: para las frases de catálogo **gana el texto de
	 * hoy**, no el del día que se puso.
	 *
	 * Sigue pudiéndose, y sigue haciendo falta —la 05 §27.4 lo decidió a propósito
	 * y por buen motivo—, pero ya no por cualquiera de los 74 del personal. Ver
	 * `Autoriza::puedeEscribirEnUnAnioCerrado`.
	 */
	public function putUpdate($id)
	{
		$user = User::fromToken();
		$vinieron = CamposQueVinieron::capturar();

		$frase = Frase::findOrFail($id);

		Autoriza::exigirEscrituraEnElAnio($user, $frase->year_id, 'Esa frase');

		// El texto de antes, capturado **antes** de los dos `if`. Son 426 frases en
		// la copia de producción, escritas a mano por el colegio una a una y que no
		// se regeneran de ningún sitio (§81): reescribir una es un cambio que hay
		// que poder deshacer leyendo el rastro.
		$antes = ['frase' => $frase->frase, 'tipo_frase' => $frase->tipo_frase];

		if ($vinieron->trae('frase'))      { $frase->frase      = Request::input('frase'); }
		if ($vinieron->trae('tipo_frase')) { $frase->tipo_frase = Request::input('tipo_frase'); }

		$frase->save();

		Auditoria::registrar()
			->editar('frase', (int) $frase->id)
			->en(year: (int) $frase->year_id)
			->de($antes)
			->a(['frase' => $frase->frase, 'tipo_frase' => $frase->tipo_frase])
			->guardar();

		return $frase;
	}


	/**
	 * Lo mismo que `putUpdate` sobre el año cerrado, y con un matiz propio: borrar
	 * una frase de catálogo **no vacía el boletín que la usaba**. `deAlumno` hace
	 * `IFNULL(f.frase, fa.frase)` con un `LEFT JOIN … AND f.deleted_at IS NULL`, o
	 * sea que al irse la del catálogo el boletín cae en la copia de
	 * `frases_asignatura.frase`, que es de cuando se puso. El daño es otro y menor
	 * que el de editarla — por eso aquí no hay aviso de población, sólo el candado
	 * del año.
	 */
	public function deleteDestroy($id)
	{
		$user = User::fromToken();

		$frase = Frase::findOrFail($id);

		// Antes de `Auditoria::registrar()`: un intento que va a acabar en 403 no
		// puede dejar escrita una línea de borrado que no ocurrió.
		Autoriza::exigirEscrituraEnElAnio($user, $frase->year_id, 'Esa frase');

		// Antes del `delete()`: el texto es lo que hay que poder leer después.
		Auditoria::registrar()
			->borrar('frase', (int) $frase->id)
			->en(year: (int) $frase->year_id)
			->de(['frase' => $frase->frase, 'tipo_frase' => $frase->tipo_frase])
			->guardar();

		$frase->delete();

		return $frase;
	}

}