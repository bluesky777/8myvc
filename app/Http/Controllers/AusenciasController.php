<?php namespace App\Http\Controllers;


use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;
use DateTime;

use App\User;
use App\Models\Alumno;
use App\Models\Grupo;
use App\Models\Ausencia;
use App\Models\Asignatura;
use Carbon\Carbon;


/*
 * Las ausencias **no las cierra el interruptor del periodo**, y es una decisión.
 *
 * Hasta el 21 ago 2026 tres de estas rutas —guardar cambios, cambiar el tipo y
 * borrar— llamaban a `User::pueden_editar_notas()` y las dos que anotan no, así
 * que con el periodo cerrado un profesor podía apuntar una falta pero no
 * corregirla. Se le preguntó a Joseth cuál de las dos mitades estaba mal y
 * contestó la contraria de la que se esperaba: **«que poner asistencias no se
 * bloquee al bloquear periodos»**, y las tres que faltaban se liberaron también
 * —excusar una falta cuando el alumno trae la excusa es el mismo trabajo de
 * asistencia, no calificar—.
 *
 * `profes_pueden_editar_notas` es lo que dice su nombre: notas. Ver
 * docs/migracion/05-codigo-muerto-y-roto.md §40.
 */
class AusenciasController extends Controller {

	public function getIndex()
	{
		//
	}

	public function getDetailed($asignatura_id)
	{
		$user = User::fromToken();

		$asignatura = (object)Asignatura::detallada($asignatura_id, $user->year_id);
		
		$alumnos = Grupo::alumnos($asignatura->grupo_id);
		
		foreach ($alumnos as $alumno) {

			$userData = Alumno::userData($alumno->alumno_id);
			$alumno->userData = $userData;

			$consulta = 'SELECT * FROM ausencias a WHERE a.asignatura_id = ? and a.periodo_id = ? and a.alumno_id=? and a.deleted_at is null';

			$ausencias = DB::select($consulta, array($asignatura_id, $user->periodo_id, $alumno->alumno_id));

			foreach ($ausencias as $ausencia) {
				$ausencia->mes = date('n', strtotime($ausencia->fecha_hora)) - 1;
				$ausencia->dia = (int)(date('j', strtotime($ausencia->fecha_hora))) ;
			}
			
			$alumno->ausencias = $ausencias;
		}

		// No cambiar el orden!
		$resultado = [];
		array_push($resultado, $asignatura);
		array_push($resultado, $alumnos);

		return $resultado;
	}

	public function postStore()
	{
		$user = User::fromToken();
		
		$aus = new Ausencia;
		$aus->alumno_id 		= Request::input('alumno_id');
		$aus->asignatura_id 	= Request::input('asignatura_id', null);
		$aus->periodo_id		= $user->periodo_id;
		$aus->cantidad_ausencia	= Request::input('cantidad_ausencia', null);
		$aus->cantidad_tardanza	= Request::input('cantidad_tardanza', null);
		$aus->fecha_hora		= Request::input('fecha_hora', null);
		$aus->entrada			= Request::input('entrada', 0);
		$aus->created_by		= $user->user_id;
		
		if (Request::input('tipo')) {
			$aus->tipo = Request::input('tipo');
		}
		if ($aus->cantidad_ausencia) {
			$aus->tipo = 'ausencia';
		}
		if ($aus->cantidad_tardanza) {
			$aus->tipo = 'tardanza';
		}

		$aus->save();
		return $aus;
	}



	public function postAgregarAusencia()
	{
		$user = User::fromToken();
		
		$aus = new Ausencia;
		$aus->alumno_id 		= Request::input('alumno_id');
		$aus->asignatura_id 	= Request::input('asignatura_id', null);
		$aus->periodo_id		= $user->periodo_id;
		$aus->cantidad_ausencia	= 1;
		$aus->fecha_hora		= Carbon::parse(Request::input('now'));
		$aus->entrada			= Request::input('entrada', 0);
		$aus->created_by		= $user->user_id;
		$aus->tipo 				= 'ausencia';

		$aus->save();
		return $aus;
	}


	public function postAgregarTardanza()
	{
		$user = User::fromToken();
		
		$aus = new Ausencia;
		$aus->alumno_id 		= Request::input('alumno_id');
		$aus->asignatura_id 	= Request::input('asignatura_id', null);
		$aus->periodo_id		= $user->periodo_id;
		$aus->cantidad_tardanza	= 1;
		$aus->fecha_hora		= Carbon::parse(Request::input('now'));
		$aus->entrada			= Request::input('entrada', 0);
		$aus->created_by		= $user->user_id;
		$aus->tipo 				= 'tardanza';

		$aus->save();
		return $aus;
	}

	/*
	 * Corregir el día de una falta lo puede hacer **cualquiera del personal**, y
	 * es una decisión tomada, no un olvido.
	 *
	 * Aquí había una comprobación de permisos calculada y tirada a la basura:
	 *
	 *     $isCoorDisciplinario = Role::isCoorDisciplinario($user->user_id);
	 *     if (!$isCoorDisciplinario) {
	 *     }
	 *
	 * El cuerpo del `if` vacío, en éste método y en `deleteDestroy`. `myvc_front`
	 * ya lo había visto en la fase 11 y lo dejó apuntado por ser del backend.
	 * Leído en frío parece un descuido con arreglo obvio —rellenar el `if`— y es
	 * justo lo que no se puede hacer: **el rol no gobierna esto en ningún
	 * cliente**. El menú de AngularJS enseña «Asistencias» a `profesor`;
	 * `crearFaltaModal` repite el mismo botón «Eliminar» tres veces y solo uno
	 * mira el rol; y `myvc_flutter` —una sola app para los dieciséis colegios—
	 * borra desde la pantalla de asistencia del profesor sin mirar ninguno.
	 * Rellenar el `if` dejaría a los 51 profesores sin poder corregir una falta
	 * mal puesta, en dieciséis colegios y de golpe, por una app que no se puede
	 * publicar el mismo día.
	 *
	 * Joseth lo decidió el 22 ago 2026: **se queda abierto**, en la misma línea
	 * que el interruptor del periodo de la cabecera —corregir una falta es
	 * trabajo de asistencia—. Lo que se cerró en su lugar fue el rastro: ver
	 * `deleteDestroy`. Lo fija `AusenciasTest`, que además cuenta qué habría que
	 * publicar antes si algún día se cierra.
	 *
	 * `Role::isCoorDisciplinario()` se queda sin llamantes con esto, y es el
	 * cuarto rol de la familia que no gobierna nada — tras Psicólogo y Enfermero
	 * (05 §30.2), que fallaban al revés: cerraban de más.
	 */
	public function putGuardarCambiosAusencia()
	{
		$user = User::fromToken();

		/* Debo convertir string a fecha
		$dato = Request::input('fecha_hora', null);
		if ($dato) {
			$dato = DateTime::createFromFormat('Y-m-d G:H:i', $dato);
			return $dato;
		}
		*/
		$aus = Ausencia::findOrFail(Request::input('ausencia_id'));
		$aus->fecha_hora		= Request::input('fecha_hora', null);
		$aus->updated_by		= $user->user_id;

		$aus->save();
		return $aus;
	}

	public function putCambiarTipoAusencia()
	{
		$user = User::fromToken();
		
		$aus = Ausencia::findOrFail(Request::input('ausencia_id'));
		
		if (Request::input('new_tipo') == 'tardanza') {
			$aus->tipo					= 'tardanza';
			$aus->cantidad_tardanza		= $aus->cantidad_ausencia;
		}
		
		if (Request::input('new_tipo') == 'ausencia') {
			$aus->tipo					= 'ausencia';
			$aus->cantidad_ausencia		= $aus->cantidad_tardanza;
		}
		
		$aus->updated_by		= $user->user_id;
		$aus->save();
		return $aus;
	}

	/*
	 * Borrar una falta **la firma**, y hasta el 22 ago 2026 no la firmaba.
	 *
	 * Las otras dos rutas que borran una ausencia —la del lector y la de la app—
	 * ponen `deleted_by` antes del `delete()`; ésta, que es la de las tres
	 * pantallas web y la de Flutter, no ponía nada. En la copia de producción del
	 * 22 ago hay **5.689 ausencias borradas y 5.684 sin autor**: las cinco que lo
	 * tienen son las que pasaron por el lector.
	 *
	 * Importa justo por lo que se decidió el 22 ago: que corregir y borrar una
	 * falta siga abierto a cualquier profesor. Si no cierra el permiso, lo único
	 * que queda es el rastro — y el rastro estaba en blanco.
	 *
	 * El `save()` va antes del `delete()` y no es cosmético: el borrado suave de
	 * Eloquent escribe solo `deleted_at`, así que un `deleted_by` sin guardar se
	 * pierde. Es lo que hacen las dos hermanas.
	 */
	public function deleteDestroy($id)
	{
		$user = User::fromToken();

		$aus = Ausencia::findOrFail($id);
		$aus->deleted_by = $user->user_id;
		$aus->save();
		$aus->delete();
		return $aus;
	}

}