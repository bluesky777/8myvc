<?php namespace App\Http\Controllers;

use App\Support\Autoriza;
use App\User;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

/**
 * El registro de lo que hace cada usuario: quién cambió qué, sobre quién.
 *
 * `bitacoras` guarda `descripcion`, `affected_person_name` y los valores viejo y
 * nuevo de lo que se tocó. Es el rastro con el que un colegio contesta «¿quién
 * cambió esta nota?», y las dos rutas que quedan vivas —listar y borrar— llevan
 * `auth.personal`. Lo que no había era nadie mirando el resultado de borrar.
 * Ver 05 §88.
 */
class BitacorasController extends Controller {

	public function getIndex($user_id='')
	{
		$user = User::fromToken();

		if ($user_id=='') {
			$user_id = $user->user_id;
		}

		// **Sin id te da lo tuyo; CON id te daba la de cualquiera.** Iba con
		// `auth.personal` y nada más, así que los 51 profesores podían leer la
		// bitácora de un compañero — o la de su rector — poniendo su número en la
		// URL. Decisión 3 de 18-auditoria.md: lo propio siempre, lo de otro con
		// `can_view_auditoria`. AUD-5.
		Autoriza::exigirVerAuditoriaDe($user, $user_id);

		// El `deleted_at is null` no estaba, y sin él el borrado de la línea de
		// abajo no borraba NADA de lo que se ve: la fila quedaba marcada y seguía
		// saliendo en este mismo listado. Se vio ejecutando las dos seguidas —
		// borrar y volver a listar—, no leyendo ninguna de las dos. Ver 05 §88.
		$consulta = 'SELECT * FROM bitacoras where created_by=? and deleted_at is null order by id desc ';
		$bits = DB::select($consulta, array($user_id));

		return $bits;
	}


	public function deleteDestroy($id)
	{
		$user 	= User::fromToken();
		$now 	= Carbon::now('America/Bogota');

		// `deleted_by` se quedaba en null teniendo el `$user` ya resuelto dos líneas
		// arriba. En un registro de auditoría eso es lo peor que puede faltar:
		// borrar el rastro no dejaba rastro. Ver 05 §88.
		DB::update('UPDATE bitacoras SET deleted_at=?, deleted_by=? WHERE id=?', [$now, $user->user_id, $id]);

		return 'Bitácora eliminada';
	}

}
