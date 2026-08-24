<?php namespace App\Http\Controllers;

use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\DB;

use App\User;
use App\Models\DefinicionComportamiento;
use App\Services\Auditoria;

/**
 * §82. Aquí vivían `show($id)` —cuerpo vacío— y `update($id)`, y **ninguno de
 * los dos estaba enrutado**: `routes/api/disciplina.php` sólo publica index,
 * los dos store y destroy. Se borran por la regla de CLAUDE.md —sin ruta y roto
 * se borra— y `update` además llegaba roto: tenía entera la §81, o sea que el
 * día que alguien le pusiera una ruta habría vaciado la definición con el
 * cuerpo vacío. Un método muerto con un fallo dentro es peor que uno muerto.
 */
class DefinicionesComportamientoController extends Controller {


	public function getIndex()
	{
		return DefinicionComportamiento::all();
	}


	public function postStore()
	{
		$def = new DefinicionComportamiento;
		$def->comportamiento_id	=	Request::input('comportamiento_id');
		$def->frase_id			=	Request::input('frase_id');
		//$def->fecha			=	Request::input('fecha');

		$def->save();

		// Una definición de comportamiento es una frase pegada al observador de un
		// alumno, y **esta ruta no comprueba nada**: ni periodo ni año (§82). El
		// rastro es lo único que queda, igual que en las ausencias.
		Auditoria::registrar()
			->crear('definicion_comportamiento', (int) $def->id)
			->a(['comportamiento_id' => $def->comportamiento_id, 'frase_id' => $def->frase_id])
			->guardar();

		return $def;
	}

	public function postStoreEscrita()
	{
		$def = new DefinicionComportamiento;
		$def->comportamiento_id	=	Request::input('comportamiento_id');
		$def->frase				=	Request::input('frase');
		//$def->fecha			=	Request::input('fecha');

		$def->save();

		// La gemela de arriba, con la frase escrita a mano en vez de elegida del
		// catálogo. Se audita igual y con la misma entidad: para quien lea el
		// observador dentro de tres años son la misma cosa, y `valor_nuevo` ya
		// distingue cuál de las dos columnas se rellenó.
		Auditoria::registrar()
			->crear('definicion_comportamiento', (int) $def->id)
			->a(['comportamiento_id' => $def->comportamiento_id, 'frase' => $def->frase])
			->guardar();

		return $def;
	}

	/**
	 * §82. Borrar una definición que no existe contestaba **200 con el texto
	 * plano `No se encontró`**, y sus nueve hermanas de catálogo contestan 404.
	 *
	 * Los dos motivos por los que esto no es cosmético:
	 *
	 *  - **200 es lo que el front usa para decidir que la fila se fue.** La
	 *    rejilla de definiciones de comportamiento la quita de la pantalla, y la
	 *    fila sigue en la base hasta que alguien recarga. Es la familia que
	 *    persigue `tools/respuestas-que-mienten.py` (05 §37, §45).
	 *  - El cuerpo **no es JSON**. Todas las demás respuestas de error de esta
	 *    API son un objeto con `message`; ésta es una cadena suelta, así que un
	 *    cliente que la parsee ni siquiera saca el motivo.
	 *
	 * `findOrFail` y no un `abort` a mano, que es como lo dicen las nueve
	 * hermanas y lo que deja el mensaje igual que el suyo.
	 */
	public function deleteDestroy($id)
	{
		$def = DefinicionComportamiento::findOrFail($id);

		// Antes del `delete()`: lo que se borró del observador de un alumno es
		// justo lo que hay que poder leer después de que se borre.
		Auditoria::registrar()
			->borrar('definicion_comportamiento', (int) $def->id)
			->de([
				'comportamiento_id' => $def->comportamiento_id,
				'frase_id' => $def->frase_id,
				'frase' => $def->frase,
			])
			->guardar();

		$def->delete();

		return $def;
	}

}