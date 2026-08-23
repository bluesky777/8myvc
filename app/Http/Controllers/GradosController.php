<?php namespace App\Http\Controllers;

use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\DB;

use App\Support\CamposQueVinieron;
use App\Support\CatalogoEnUso;
use App\User;
use App\Models\NivelEducativo;
use App\Models\Grado;

class GradosController extends Controller {


	public function getIndex()
	{	
		$user = User::fromToken();
		$consulta = 'SELECT g.id, g.nombre, g.abrev, g.orden, g.nivel_educativo_id, g.created_at, g.updated_at, n.nombre as nombre_nivel 
			from grados g
			inner join niveles_educativos n on n.id=g.nivel_educativo_id and g.deleted_at is null
			order by g.orden';

		$grados = DB::select($consulta);

		return $grados;
	}

	
	public function postStore()
	{
		$user = User::fromToken();
		try {
			$grado = new Grado;
			$grado->nombre		=	Request::input('nombre');
			$grado->abrev		=	Request::input('abrev');
			$grado->orden		=	Request::input('orden');
			$grado->nivel_educativo_id =	Request::input('nivel')['id'];
			$grado->save();
			
			return $grado;
		} catch (\Exception $e) {
			abort(422, 'Datos incorrectos');
		}
	}


	public function getShow($id)
	{
		$grado = Grado::findOrFail($id);
		$nivel = NivelEducativo::findOrFail($grado->nivel_educativo_id);
		$grado->nivel = $nivel;
		return $grado;
	}

	/**
	 * §81, la misma de `AreasController::putUpdate`, y la que más costó ver de
	 * las seis **porque parecía la única sana**: con el cuerpo vacío contestaba
	 * 422, y un 422 se lee como «se validó».
	 *
	 * No se validaba nada. El 422 salía de `Request::input('nivel')['id']` sobre
	 * `null` —Laravel convierte el aviso de PHP en `ErrorException` y el
	 * `try/catch` de abajo la traduce—, o sea de **un error ajeno al campo que
	 * importa**. Con el cuerpo mínimo que pasa por delante de ese offset:
	 *
	 *     PUT grados/update/1  {"nivel":{"id":1}}
	 *       ->  200 "Cambiado", y `nombre` queda en '' (Prejardín se va)
	 *
	 * Es la lección de la noche escrita en un método: **una respuesta correcta
	 * por el motivo equivocado tapa exactamente lo que parece estar cubriendo**,
	 * y aquí tapaba a dos de los seis. Ver también `MateriasController::putUpdate`.
	 *
	 * La captura va **antes del `merge`** y no es colocación libre: `merge`
	 * mete `nivel` en la petición, así que después de esa línea `trae('nivel')`
	 * diría que sí aunque el cliente no lo mandara nunca. Lo avisa el docblock
	 * de `CamposQueVinieron`, y es el mismo tropiezo que la §68.
	 */
	public function putUpdate($id)
	{
		$vinieron = CamposQueVinieron::capturar();

		$grado = Grado::findOrFail($id);

		if (!Request::input('nivel') and Request::input('nivel_educativo_id')) {
			Request::merge(array('nivel' => array('id' => Request::input('nivel_educativo_id') ) ));
		}

		try {
			if ($vinieron->trae('nombre')) { $grado->nombre = Request::input('nombre'); }
			if ($vinieron->trae('abrev'))  { $grado->abrev  = Request::input('abrev'); }
			if ($vinieron->trae('orden'))  { $grado->orden  = Request::input('orden'); }

			// `nivel.id` en vez de `Request::input('nivel')['id']`: el offset sobre
			// null era lo que daba el 422 de arriba, y con él quitado el 422 sólo
			// puede venir ya de la base. Se escribe si vino por cualquiera de sus
			// dos nombres, que es lo que hace el `merge` de tres líneas más arriba.
			if ($vinieron->trae('nivel') or $vinieron->trae('nivel_educativo_id')) {
				$grado->nivel_educativo_id = Request::input('nivel.id');
			}

			$grado->save();
			return 'Cambiado';
		} catch (\Exception $e) {
			abort(422, 'Datos incorrectos');
		}
	}


	/**
	 * §70.3 — mandar un grado a la papelera **apagaba la planilla de todos los
	 * profesores de ese grado**, sin decir nada y sin forma de deshacerlo.
	 *
	 * Lo medido: `Profesor::asignaturas` une por `inner join grados … and
	 * gr.deleted_at is null`, así que al borrar el grado el profesor **deja de ver
	 * sus asignaturas y no puede poner notas**; mientras tanto la rejilla de
	 * `GruposController` une por el mismo grado **sin ese filtro**, así que desde
	 * administración el grupo sigue apareciendo y no se ve nada raro. Y este
	 * controlador **no tiene `restore`**: se arregla entrando a la base.
	 *
	 * **Joseth, 23 ago 2026: se impide, y el aviso dice cuántos grupos dependen.**
	 * Hoy lo bloquearía en 13 de los 14 grados vivos de la copia de producción —el
	 * decimocuarto no tiene grupos—, que es otra forma de decir que **esta ruta
	 * casi nunca se podía llamar sin romper algo.**
	 */
	public function deleteDestroy($id)
	{
		$grado = Grado::findOrFail($id);

		CatalogoEnUso::exigirQueNadieApunte('grupos', 'grado_id', $grado->id, 'grupos');

		$grado->delete();

		return 'Eliminado';
	}

}