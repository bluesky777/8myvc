<?php namespace App\Http\Controllers\Perfiles;

use App\Http\Controllers\Controller;

use Request;
use DB;
use File;
use Image;
use \stdClass;

use App\User;
use App\Models\ImageModel;
use App\Models\Year;
use App\Models\Alumno;
use App\Models\Profesor;
use App\Models\Acudiente;
use App\Models\ChangeAsked;
use Carbon\Carbon;


class ImagesUsuariosController extends Controller {

	
	public function putImagenesDeUsuario()
	{
		$user = User::fromToken();
		$consulta = 'SELECT * FROM images WHERE user_id=:user_id and (publica is null or publica=false) and deleted_at is null';
		return DB::select($consulta, [ ':user_id'	=> Request::input('usuario_id') ]);
	}


	
	public function putMoveImgToMe()
	{
		$user = User::fromToken();
		$consulta = 'UPDATE images SET user_id=:user_id, updated_at=:ahora, updated_by=:user2_id WHERE id=:img_id and deleted_at is null';
		return DB::update($consulta, [ ':img_id' => Request::input('img_id'), ':user_id' => $user->user_id, ':ahora' => Carbon::now('America/Bogota'), ':user2_id' => $user->user_id ]);
	}


	public function putRotarimagen($imagen_id)
	{
		$imagen = ImageModel::findOrFail($imagen_id);

		$folderName = $imagen->nombre;
		$img_dir = 'images/perfil/'.$folderName;
		//return $img_dir;
		$img = Image::make($img_dir)->rotate(-90);
	

		$img->save();

		return $imagen->nombre;
	}


	public function putRotarImagenIzquierda($imagen_id)
	{
		$imagen = ImageModel::findOrFail($imagen_id);

		$folderName = $imagen->nombre;
		$img_dir = 'images/perfil/'.$folderName;
		//return $img_dir;
		$img = Image::make($img_dir);

		$img->rotate(90);

		$img->save();

		return $imagen->nombre;
	}


	public function putCambiarImagenUnUsuario($user_id)
	{
		$user = User::fromToken();

		$usu 				= User::findOrFail($user_id);
		$usu->imagen_id 	= Request::input('imagen_id');
		$usu->save();

		$img 				= ImageModel::find($usu->imagen_id);
		if ($img) {
			$img->user_id 		= $user_id;
			$img->updated_by 	= $user->user_id;
			$img->publica 		= false;
			$img->save();
			return $img;
		} else {
			return 'Cambiada';
		}
		
	}


	public function putCambiarFotoUnUsuario($user_id)
	{
		$user 	= User::fromToken(); // Logueado
		$usu 	= User::findOrFail($user_id); // persona a la que le cambiaremos la foto

		// Solo puede cambiarle a alguien si es profesor o superuser
		if ($user->tipo == 'Profesor' or $user->is_superuser) {
			
			$persona = new stdClass();

			switch ($usu->tipo) {
				case 'Alumno':
					$alumno = Alumno::where('user_id', $user_id)->first();
					$persona = $alumno;
					break;

				case 'Profesor':
					$profesor = Profesor::where('user_id', $user_id)->first();
					$persona = $profesor;
					break;

				case 'Acudiente':
					$acudiente = Acudiente::where('user_id', $user_id)->first();
					$persona = $acudiente;
					break;

			}
			
			
			$img_id 			= Request::input('imagen_id');
			$img 				= ImageModel::find($img_id);


			$persona->foto_id = $img_id ? $img_id : null;
			$persona->save();
			
			if ($img){
				$img->user_id 		= $user_id;
				$img->updated_by 	= $user->user_id;
				$img->publica 		= false;
				$img->save();
			}
			
			return $persona;
		}


	}




	public function putCambiarFirmaUnProfe($profe_id)
	{
		$user 	= User::fromToken(); // Logueado

		// Solo puede cambiarle a alguien si es profesor o superuser
		if ($user->tipo == 'Profesor' or $user->is_superuser) {
			
			$img_id 			= Request::input('imagen_id');
			$img 				= ImageModel::find($img_id);


			$profesor 				= Profesor::findOrFail($profe_id);
			$img_id 				= Request::input('imagen_id');
			$profesor->firma_id 	= $img_id ? $img_id : null;
			$profesor->updated_by 	= $user->user_id;
			$profesor->save();
			
			if ($img){
				$img->user_id 		= $profesor->user_id;
				$img->updated_by 	= $user->user_id;
				$img->publica 		= false;
				$img->save();
			}

		}else{
			return 'No tienes permiso';
		}

		return $profesor;
	}






	public function putCambiarImagenPerfil($user_id)
	{
		$user 		= User::fromToken();

		$usu 		= User::findOrFail($user_id);
		$image_id 	= Request::input('imagen_id');

		if ($user->is_superuser) {
			$usu->imagen_id = $image_id;
			$usu->save();
			return $usu;
		}else{
			$pedido = ChangeAsked::verificar_pedido_actual($user_id, $user->year_id, $user->tipo);

			if ($pedido->data_id) {
				$consulta = 'UPDATE change_asked_data SET image_id_new=:image_id WHERE id=:data_id';
				DB::update($consulta, [ ':image_id'	=> $image_id, ':data_id'	=> $pedido->data_id ]);
				$pedido = ChangeAsked::verificar_pedido_actual($user_id, $user->year_id, $user->tipo);
			}else{

				$consulta 	= 'INSERT INTO change_asked_data(image_id_new) VALUES(:image_id)';
				DB::insert($consulta, [ ':image_id'	=> $image_id ]);
				$last_id 	= DB::getPdo()->lastInsertId();

				$consulta 	= 'UPDATE change_asked SET data_id=:data_id WHERE id=:asked_id';
				DB::update($consulta, [ ':data_id'	=> $last_id, ':asked_id' => $pedido->asked_id ]);

				$pedido 	= ChangeAsked::verificar_pedido_actual($user_id, $user->year_id, $user->tipo);
			
			}
			

			return ['pedido' => $pedido];
		}
		
	}



	public function putCambiarImagenOficial($user_id)
	{
		$user 		= User::fromToken();
		$foto_id 	= Request::input('foto_id');

		if (!$foto_id) {
			return abort(400, 'Debe seleccionar una foto.');
		}

		$usu = User::findOrFail($user_id);

		$pedido = ChangeAsked::verificar_pedido_actual($user_id, $user->year_id, $user->tipo);

		if ($pedido->data_id) {
			$consulta = 'UPDATE change_asked_data SET foto_id_new=:foto_id WHERE id=:data_id';
			DB::update($consulta, [ ':foto_id'	=> $foto_id, ':data_id'	=> $pedido->data_id ]);
			$pedido = ChangeAsked::verificar_pedido_actual($user_id, $user->year_id, $user->tipo);
		}else{

			$consulta 	= 'INSERT INTO change_asked_data(foto_id_new) VALUES(:foto_id)';
			DB::insert($consulta, [ ':foto_id'	=> $foto_id ]);
			$last_id 	= DB::getPdo()->lastInsertId();

			$consulta 	= 'UPDATE change_asked SET data_id=:data_id WHERE id=:asked_id';
			DB::update($consulta, [ ':data_id'	=> $last_id, ':asked_id' => $pedido->asked_id ]);

			$pedido 	= ChangeAsked::verificar_pedido_actual($user_id, $user->year_id, $user->tipo);
		
		}


		return ['pedido' => $pedido];
	}




	public function deleteDestroy($id)
	{
		$img = ImageModel::findOrFail($id);
		
		$filename = 'images/perfil/'.$img->nombre;


		// Debería crear un código que impida borrar si la imagen es usada.


		if (File::exists($filename)) {
			File::delete($filename);
			$img->delete();
		}else{
			return 'No se encuentra la imagen a eliminar. '.$img->nombre;
		}


		// Elimino cualquier referencia que otros tengan a esa imagen borrada.
		$alumnos = Alumno::where('foto_id', $id)->get();
		foreach ($alumnos as $alum) {
			$alum->foto_id = null;
			$alum->save();
		}
		$profesores = Profesor::where('foto_id', $id)->get();
		foreach ($profesores as $prof) {
			$prof->foto_id = null;
			$prof->save();
		}
		$acudientes = Acudiente::where('foto_id', $id)->get();
		foreach ($acudientes as $acud) {
			$acud->foto_id = null;
			$acud->save();
		}
		$users = User::where('imagen_id', $id)->get();
		foreach ($users as $user) {
			$user->imagen_id = null;
			$user->save();
		}
		$years = Year::where('logo_id', $id)->get();
		foreach ($years as $year) {
			$year->logo_id = null;
			$year->save();
		}
		
		$asks = ChangeAsked::where('oficial_image_id', $id);

		if (count($asks) > 0) {
			if (method_exists( $asks, 'destroy') ){
				$asks->destroy();
			}
		}
		
		

		
		return $img;
	}

}