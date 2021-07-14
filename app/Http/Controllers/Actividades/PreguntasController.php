<?php namespace App\Http\Controllers\Actividades;

use App\Http\Controllers\Controller;

use Request;
use DB;

use App\User;
use App\Models\WsActividad;
use App\Models\WsPregunta;
use App\Models\WsOpcion;


class PreguntasController extends Controller {


	public function postCrear()
	{
		$user = User::fromToken();

		$preg 					= new WsPregunta;
		$preg->actividad_id 	= Request::input('actividad_id');
		$preg->tipo_pregunta 	= 'Test'; // Test, Multiple, Texto, Lista, Ordenar, Cuadrícula
		$preg->orden 			= Request::input('orden');
		$preg->added_by 		= $user->user_id;
		$preg->save();


		$opcion 				= new WsOpcion();
		$opcion->definicion 	= 'Opción 1';
		$opcion->pregunta_id 	= $preg->id;
		$opcion->orden 			= 0;
		$opcion->is_correct 	= true;
		$opcion->save();

		$preg->opciones = [$opcion];

		return $preg;
	}


	public function putEdicion()
	{
		$user 	= User::fromToken();
		
		$datos 	= [];

		$consulta 	= 'SELECT p.id, TRUE as is_preg, p.actividad_id, p.enunciado, p.orden, p.added_by, p.created_at, p.updated_at, NULL as is_cuadricula,
							p.opcion_otra, p.ayuda, p.tipo_pregunta, p.puntos, p.duracion, p.aleatorias, p.texto_arriba, p.texto_abajo 
						FROM ws_preguntas p 
						WHERE p.id=? and p.deleted_at is null
						ORDER BY p.order, p.id';
		$Pregunta 	= DB::select($consulta, [ Request::input('pregunta_id') ])[0];


		$consulta = 'SELECT o.id, o.pregunta_id, o.definicion, o.image_id, o.orden, o.is_correct, o.created_at, o.updated_at 
				FROM ws_opciones o
				where o.pregunta_id=:pregunta_id';

		$opciones = DB::select($consulta, [ ':pregunta_id' => Request::input('pregunta_id') ] );
		$Pregunta->opciones = $opciones;
		
		$datos['pregunta'] = $Pregunta;
		
		return $datos;
	}


	public function putGuardar()
	{
		$user 	= User::fromToken();
		
		$preg 					= WsPregunta::find(Request::input('id'));
		$preg->enunciado 		= Request::input('enunciado');
		$preg->ayuda 			= Request::input('ayuda');
		$preg->puntos 			= Request::input('puntos');
		$preg->duracion 		= Request::input('duracion');
		$preg->aleatorias 		= Request::input('aleatorias');
		$preg->texto_arriba 	= Request::input('texto_arriba');
		$preg->texto_abajo 		= Request::input('texto_abajo');
		$preg->save();

		return $preg;
	}



	public function putToggleOpcionOtra()
	{
		$user 	= User::fromToken();
		
		$preg 					= WsPregunta::find(Request::input('id'));
		$preg->opcion_otra 		= Request::input('opcion_otra');
		$preg->save();

		return 'Opción OTRA cambiada';
	}



	public function putUpdateOrden()
	{
		$user 	= User::fromToken();
		
		$sortHash = Request::input('sortHash');

		for($row = 0; $row < count($sortHash); $row++){
			foreach($sortHash[$row] as $key => $value){

				$preg 			= WsPregunta::find((int)$key);
				$preg->orden 	= (int)$value;
				$preg->save();
			}
		}

		return 'Ordenado correctamente';
	}



	public function putDuplicarPregunta()
	{
		$user 			= User::fromToken();

		$puntos 		= Request::input('puntos');
		$opcion_otra 	= Request::input('opcion_otra');
		
		if (!$puntos) {			$puntos = 0;}
		if (!$opcion_otra) {	$opcion_otra = 0;}
		
		$preg 					= new WsPregunta();
		$preg->actividad_id 	= Request::input('actividad_id');
		$preg->contenido_id 	= Request::input('contenido_id');
		$preg->enunciado 		= Request::input('enunciado');
		$preg->ayuda 			= Request::input('ayuda');
		$preg->puntos 			= $puntos;
		$preg->duracion 		= Request::input('duracion');
		$preg->aleatorias 		= Request::input('aleatorias');
		$preg->texto_arriba 	= Request::input('texto_arriba');
		$preg->texto_abajo 		= Request::input('texto_abajo');
		$preg->tipo_pregunta 	= Request::input('tipo_pregunta');
		$preg->opcion_otra 		= $opcion_otra;
		$preg->orden 			= Request::input('orden'); // Debo modificarlo
		$preg->added_by 		= $user->user_id;
		$preg->save();
		
		$newopciones = [];
		$opciones = Request::input('opciones');
		$cant = count($opciones);

		for ($i=0; $i < $cant; $i++) { 
		
			$opcion 				= new WsOpcion();
			$opcion->definicion 	= $opciones[$i]['definicion'];
			$opcion->pregunta_id 	= $preg->id;
			$opcion->orden 			= $opciones[$i]['orden'];
			$opcion->is_correct 	= $opciones[$i]['is_correct'];
			$opcion->save();

			array_push($newopciones, $opcion);

		}

		$preg->opciones = $newopciones;

		return $preg;
	}



	public function deleteDestroy($id)
	{
		$user = User::fromToken();

		$preg = WsPregunta::findOrFail($id);
		$preg->delete();

		return $preg;
	}

}