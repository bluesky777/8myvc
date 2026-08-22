<?php namespace App\Http\Controllers\Perfiles;

use App\Http\Controllers\Controller;

use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Intervention\Image\Laravel\Facades\Image;

use App\Support\Autoriza;
use App\User;
use App\Models\ImageModel;
use App\Models\Year;
use App\Models\Alumno;
use App\Models\Profesor;
use App\Models\Acudiente;
use App\Models\ChangeAsked;
use Carbon\Carbon;


class ImagesUsuariosController extends Controller {

	
	/**
	 * El álbum PRIVADO de otra persona, para el gestor de imágenes del administrador.
	 *
	 * **Estaba abierto a cualquiera con token**, y medido con el de un alumno del
	 * seed: 200 y las **162 imágenes privadas** de un superusuario, con su nombre
	 * de archivo dentro —que es la ruta con la que se piden—. Ver 05 §53.
	 *
	 * Lo que lo tuvo tapado no fue que nadie mirara la ruta, sino **el nombre de
	 * la clave**. La exención de `AutorizacionTest` decía «sin `user_id` significa
	 * "las mías", que es lo que devuelve», y el método no lee `user_id`: lee
	 * `usuario_id`. Las dos mitades de la frase eran falsas —sin la clave devuelve
	 * las imágenes cuyo `user_id` es NULL, no las de quien pregunta— y la lista de
	 * exenciones ya avisa dos líneas más arriba de que es de las pocas cosas del
	 * repo que se escriben creyendo al código en vez de midiéndolo. Es la segunda
	 * que se le cuela, después de `piars-alumnos/field` en la §35.
	 *
	 * El criterio no es nuevo: la pestaña «Imágenes de usuarios» del gestor de
	 * archivos es la única que llama aquí —`alumSelect` y `profeSelect` de
	 * `FileManagerCtrl`— y el front la enseña con `ng-if="hasRoleOrPerm('admin')"`.
	 * Es la situación de la §29.3 —el backend dos escalones por debajo de su
	 * propia pantalla— y se cierra con la decisión que ya tomó la §36 para sus
	 * cinco hermanas de este mismo controlador, no con una nueva.
	 */
	public function putImagenesDeUsuario()
	{
		Autoriza::exigir(Autoriza::esAdministrativo(User::fromToken()),
			'No tienes permiso para ver las imágenes de otra persona.');

		$consulta = 'SELECT * FROM images WHERE user_id=:user_id and (publica is null or publica=false) and deleted_at is null';
		return DB::select($consulta, [ ':user_id'	=> Request::input('usuario_id') ]);
	}


	
	public function putMoveImgToMe()
	{
		$user = User::fromToken();
		$consulta = 'UPDATE images SET user_id=:user_id, updated_at=:ahora, updated_by=:user2_id WHERE id=:img_id and deleted_at is null';
		return DB::update($consulta, [ ':img_id' => Request::input('img_id'), ':user_id' => $user->user_id, ':ahora' => Carbon::now('America/Bogota'), ':user2_id' => $user->user_id ]);
	}


	/**
	 * Gira a la DERECHA. La llama `ImagesUsersApi.rotarDerecha()` del frontend.
	 *
	 * El ángulo es +90 y no -90 porque `intervention/image` cambió de signo entre
	 * la v2 y la v4, y este proyecto saltó de una a otra en la Fase 4. La v2
	 * pasaba el ángulo tal cual a `imagerotate()`, que es antihorario; la v4 lo
	 * interpreta al revés —su `RotateModifier` lo documenta como «clockwise
	 * rotation angle»—. Con el -90 heredado, este endpoint giraba a la izquierda
	 * y su hermano a la derecha: los dos botones al revés.
	 */
	public function putRotarimagen($imagen_id)
	{
		$imagen = ImageModel::findOrFail($imagen_id);

		$img_dir = 'images/perfil/'.$imagen->nombre;

		Image::decodePath($img_dir)->rotate(90)->save();

		return $imagen->nombre;
	}


	/**
	 * Gira a la IZQUIERDA. Mismo cambio de signo que `putRotarimagen()`, y por la
	 * misma razón.
	 */
	public function putRotarImagenIzquierda($imagen_id)
	{
		$imagen = ImageModel::findOrFail($imagen_id);

		$img_dir = 'images/perfil/'.$imagen->nombre;

		Image::decodePath($img_dir)->rotate(-90)->save();

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

		// Solo puede cambiarle a alguien si es profesor o superuser.
		// Antes esto era un `if` sin `else`: quien no cumpliera —un administrativo
		// de tipo `Usuario` sin `is_superuser`, que el guard `auth.personal` deja
		// pasar— recibía **200 con el cuerpo vacío** y la foto sin cambiar. Es la
		// forma de la §37, y no se amplía a nadie: el que no podía sigue sin poder,
		// pero ahora se le dice.
		Autoriza::exigir(
			$user->tipo == 'Profesor' || $user->is_superuser,
			'Solo un profesor o un superusuario cambia la foto de otro.'
		);

		// La foto OFICIAL vive en la ficha de la persona —`alumnos.foto_id`,
		// `profesores.foto_id`, `acudientes.foto_id`—, y un `Usuario`
		// administrativo no tiene ficha: lo suyo es `users.imagen_id`, que cambia
		// la ruta hermana `cambiar-imagen-un-usuario`. El `switch` no tenía rama
		// para él ni `default`, así que `$persona` se quedaba en el `stdClass`
		// vacío con el que se inicializaba y `$persona->save()` era un **fatal**:
		// 500 en una operación que no puede existir. Ver 05 §44.
		$persona = match ($usu->tipo) {
			'Alumno' => Alumno::where('user_id', $user_id)->first(),
			'Profesor' => Profesor::where('user_id', $user_id)->first(),
			'Acudiente' => Acudiente::where('user_id', $user_id)->first(),
			default => abort(422, 'Un usuario administrativo no tiene foto oficial; su imagen es la del perfil.'),
		};

		// Y el otro camino al mismo fatal: la cuenta existe pero su ficha no
		// —borrada, o nunca creada—, y `first()` devuelve null.
		if (! $persona) {
			abort(404, 'Esa cuenta no tiene ficha a la que cambiarle la foto.');
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




	public function putCambiarFirmaUnProfe($profe_id)
	{
		$user 	= User::fromToken(); // Logueado

		// Tenía `else { return 'No tienes permiso'; }` — **con 200**. El front hace
		// `.then()` y dentro pinta la firma como cambiada: mueve la imagen de la
		// lista de privadas a las del usuario y actualiza `firma_id` en pantalla.
		// O sea que al administrativo que `auth.personal` deja pasar se le enseñaba
		// la firma puesta y al recargar no estaba. Misma familia que la §44 y la
		// §48; aquí el `else` existía y lo que mentía era el código. Ver 05 §48.2.
		Autoriza::exigir(
			$user->tipo == 'Profesor' || $user->is_superuser,
			'Solo un profesor o un superusuario cambia la firma de otro.'
		);

		$img_id 			= Request::input('imagen_id');
		$img 				= ImageModel::find($img_id);

		$profesor 				= Profesor::findOrFail($profe_id);
		$profesor->firma_id 	= $img_id ? $img_id : null;
		$profesor->updated_by 	= $user->user_id;
		$profesor->save();

		if ($img){
			$img->user_id 		= $profesor->user_id;
			$img->updated_by 	= $user->user_id;
			$img->publica 		= false;
			$img->save();
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
		
		// Y la sexta referencia: las peticiones de cambio que nombran la imagen.
		//
		// Aquí había un bloque que pretendía hacer esto y **nunca hizo nada**
		// —`count()` sobre un Builder, protegido por un `method_exists(…,
		// 'destroy')` que es false, contra `change_asked.oficial_image_id`, que
		// no existe en el esquema—. Con el salto a PHP 8 ese `count()` pasó de
		// warning a TypeError y el endpoint empezó a responder 500 con el
		// borrado ya hecho. 05-codigo-muerto-y-roto.md §13.1.
		//
		// **Decidido por Joseth el 20 ago 2026: se borra la petición**, no se
		// pone su referencia a `null`. Una petición que pide cambiar la foto por
		// una imagen que ya no está no es una petición a medias, es una que no
		// se puede conceder: dejarla viva es dejarle al administrativo algo que
		// solo puede rechazar. Se borra como la borra `putDestruir`, que es la
		// operación que ya existía para esto: de verdad y en las tres tablas,
		// porque ni `change_asked_data` ni `change_asked_assignment` tienen
		// `deleted_at`.
		//
		// Las cuatro columnas son las cuatro formas que tiene una petición de
		// nombrar una imagen; `image_to_delete_id` es la de «bórrame esta», que
		// con la imagen ya borrada tampoco tiene nada que pedir.
		$peticiones = DB::select(
			'SELECT c.id, c.data_id, c.assignment_id
			   FROM change_asked c
			   INNER JOIN change_asked_data d ON d.id = c.data_id
			  WHERE d.foto_id_new = ? OR d.image_id_new = ?
			     OR d.firma_id_new = ? OR d.image_to_delete_id = ?',
			[ $id, $id, $id, $id ]
		);

		foreach ($peticiones as $peticion) {
			// Las tres en una transacción: media petición borrada es peor que
			// ninguna, porque `$consulta_all` la lee por LEFT JOIN y saldría
			// entera con los campos del lado que quedó sin borrar.
			DB::transaction(function () use ($peticion) {
				DB::delete('DELETE FROM change_asked WHERE id = ?', [ $peticion->id ]);
				DB::delete('DELETE FROM change_asked_data WHERE id = ?', [ $peticion->data_id ]);

				// Una petición es una por usuario y año, y puede llevar dentro
				// un cambio de asignatura que no tiene nada que ver con la
				// imagen. Se va con ella —es lo que significa borrar la
				// petición, y es lo que hace `putDestruir`—, y por eso se anota:
				// es el único efecto de esta decisión que no se ve venir.
				if ($peticion->assignment_id !== null) {
					DB::delete('DELETE FROM change_asked_assignment WHERE id = ?', [ $peticion->assignment_id ]);
				}
			});
		}

		return $img;
	}

}