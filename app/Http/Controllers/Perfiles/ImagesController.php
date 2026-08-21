<?php namespace App\Http\Controllers\Perfiles;

use App\Http\Controllers\Controller;

use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Intervention\Image\Laravel\Facades\Image;
use \stdClass;

use App\User;
use App\Models\ImageModel;
use App\Models\Year;
use App\Models\Alumno;
use App\Models\Profesor;
use App\Models\Acudiente;
use App\Models\ChangeAsked;
use App\Models\Debugging;
use App\Support\SafeUpload;

use Carbon\Carbon;
use \Log;
use App\Support\Autoriza;


class ImagesController extends Controller {

	public function getIndex()
	{
		$user = User::fromToken();
		

		$consulta = 'SELECT * FROM images
			WHERE user_id=:user_id and (publica is null or publica=false) and deleted_at is null';

		# 1. Imágenes privadas
		$imagenes_privadas = DB::select($consulta, [ ':user_id'	=> $user->user_id ]);

		$imagenes_publicas 	= [];
		$logo 				= [];
		$grupos 			= [];
		$profesores 		= [];

		if ($user->is_superuser || $user->tipo == 'Profesor') {

			# 2. Imágenes públicas
			$imagenes_publicas = ImageModel::where('publica', true)->get();

			$year = Year::datos($user->year_id);

			$logo = ['logo_id' => $year->logo_id, 'logo' => $year->logo];

			# 3. Grupos
			$consulta = 'SELECT g.id, g.nombre, g.abrev, g.orden, gra.orden as orden_grado, g.grado_id, g.year_id, g.titular_id,
				p.nombres as nombres_titular, p.apellidos as apellidos_titular, p.titulo, g.caritas, 
				g.created_at, g.updated_at, gra.nombre as nombre_grado 
				from grupos g
				inner join grados gra on gra.id=g.grado_id and g.year_id=:year_id 
				left join profesores p on p.id=g.titular_id
				where g.deleted_at is null
				order by g.orden';

			$grupos = DB::select($consulta, [':year_id'=>$user->year_id] );

			# 4. Profesores
			$consulta = 'SELECT p.id as profesor_id, p.nombres, p.apellidos, p.sexo, p.foto_id, p.tipo_doc,
						p.num_doc, p.ciudad_doc, p.fecha_nac, p.ciudad_nac, p.titulo,
						p.estado_civil, p.barrio, p.direccion, p.telefono, p.celular,
						p.facebook, p.email, p.tipo_profesor, p.user_id, u.username,
						u.email as email_usu, u.imagen_id, u.is_superuser,
						c.id as contrato_id, c.year_id,
						p.foto_id, IFNULL(i.nombre, IF(p.sexo="F","default_female.png", "default_male.png")) as foto_nombre,
						IFNULL(i2.nombre, IF(p.sexo="F","default_female.png", "default_male.png")) as imagen_nombre,
						p.firma_id, i3.nombre as firma_nombre
					from profesores p
					inner join contratos c on c.profesor_id=p.id and c.year_id=:year_id and c.deleted_at is null
					left join users u on p.user_id=u.id and u.deleted_at is null
					LEFT JOIN images i on i.id=p.foto_id and i.deleted_at is null
					LEFT JOIN images i2 on i2.id=u.imagen_id and i2.deleted_at is null
					LEFT JOIN images i3 on i3.id=p.firma_id and i3.deleted_at is null
					where p.deleted_at is null
					order by p.nombres, p.apellidos';

			$profesores = DB::select($consulta, [':year_id'=>$user->year_id] );

		}
		
		return array('imagenes_privadas' => $imagenes_privadas, 'imagenes_publicas' => $imagenes_publicas, 'logo' => $logo, 'grupos' => $grupos, 'profesores' => $profesores);
	}


	public function putDatosImagen()
	{
		//$user = User::fromToken();
		$user_id 	= Request::input('user_id');
		$imagen_id 	= Request::input('imagen_id');

		$datos_imagen = ImageModel::DatosImagen($imagen_id, $user_id);

		return $datos_imagen;
	}




	public function postStore()
	{
		$user = User::fromToken();

		if ($user->tipo == 'Acudiente' || $user->tipo == 'Alumno') {
			$imagenes_user 	= ImageModel::where('user_id', $user->user_id)->get();
			if (count($imagenes_user) > 2) {
				abort(400, 'No tiene permisos para subir más de 3 imágenes');
			}
		}

		$folder = 'images/perfil/';

		$newImg = $this->guardar_imagen($user);

		try {
			
			$img = Image::decodePath($folder . $newImg->nombre)->orient();
			$img->cover(200, 200);
			//$img->resize(200, null, function ($constraint) {
			//	$constraint->aspectRatio();
			//});
			$img->save();
		} catch (\Exception $e) {
			abort(422, 'No se pudo procesar la imagen.');
		}

		return $newImg;
	}



	public function postStoreIntacta()
	{
		$user = User::fromToken();
		
		$newImg = $this->guardar_imagen($user);
		$newImg->publica = true;
		$newImg->save();

		return $newImg;
	}


	public function postStoreFirma()
	{
		$user = User::fromToken();
		
		$newImg = $this->guardar_imagen($user);
		$newImg->publica = false;
		$newImg->save();

		return $newImg;
	}


	public function postStoreIntactaPrivada()
	{
		$user = User::fromToken();
		
		$newImg = $this->guardar_imagen($user);
		$newImg->publica = false;
		$newImg->save();

		return $newImg;
	}

	public function guardar_imagen($user)
	{
		$folderName = 'user_'.$user->user_id;
		$folder = 'images/perfil/'.$folderName;

		if (!File::exists($folder)) {
			// 0755 y no 0777: la carpeta la lee el servidor web y la escribe el
			// proceso de PHP, que es el mismo usuario. Dar escritura a todo el
			// mundo en una carpeta servida por HTTP es de las cosas que solo
			// pueden salir mal.
			File::makeDirectory($folder, 0755, true, true);
		}

		$file = Request::file("file");

		// Valida la extensión contra la lista blanca y resuelve colisiones
		// (foto.jpg, foto(1).jpg, foto(2).jpg…) igual que antes.
		$miImg = SafeUpload::nombreDisponible($file, $folder, SafeUpload::EXTENSIONES_IMAGEN);

		$file->move($folder, $miImg);
		
		$newImg 			= new ImageModel;
		$newImg->nombre 	= $folderName.'/'.$miImg;
		$newImg->user_id 	= $user->user_id;
		$newImg->created_by = $user->user_id;
		$newImg->save();

		return $newImg;
	}



	public function putPublicarImagen($imagen_id)
	{
		$user = User::fromToken();

		if ($user->tipo == 'Acudiente' || $user->tipo == 'Alumno') {
			return 'No tiene permisos para establecer imágenes públicas.';
		}

		$imagen 				= ImageModel::findOrFail($imagen_id);
		$imagen->publica 		= true;
		$imagen->updated_by 	= $user->user_id;
		$imagen->save();

		return $imagen->nombre;
	}

	public function putPrivatizarImagen($imagen_id)
	{
		$user 	= User::fromToken();
		$years 	= Year::where('logo_id', $imagen_id)->get();
		
		if (count($years) > 0) {
			return ['imagen' => ['is_logo_of_year' => $years[0]->year ] ];
		}


		$imagen 				= ImageModel::findOrFail($imagen_id);
		$imagen->publica 		= null;
		$imagen->updated_by 	= $user->user_id;
		$imagen->save();

		return $imagen->nombre;
	}




	/**
	 * El logo del colegio, que sale en **cada boletín y cada certificado**.
	 *
	 * Pedía solo `auth.personal`, así que cualquiera de los 51 profesores lo
	 * cambiaba. Su botón vive en la pestaña que el front enseña con
	 * `hasRoleOrPerm('admin')`: la misma situación de la §29.3 y el mismo cierre.
	 * De las cinco de esta familia es la que más alcance tiene. Ver 05 §36.
	 */
	public function putCambiarlogocolegio()
	{
		$user = User::fromToken();
		
		Autoriza::exigir(Autoriza::esAdministrativo($user),
			'No tienes permiso para cambiar el logo del colegio.');

		$year = Year::findOrFail($user->year_id);
		$year->logo_id = Request::input('logo_id');
		$year->save();
		return $year;
	}

	public function deleteDestroy($id)
	{
		$user 	= User::fromToken();
		$img 	= ImageModel::findOrFail($id);

		if ($img->created_by != $user->user_id and !$user->is_superuser ) {
			$pedido = ChangeAsked::verificar_pedido_actual($user->user_id, $user->year_id, $user->tipo);

			if ($pedido->data_id) {
				$consulta = 'UPDATE change_asked_data SET image_to_delete_id=:foto_id WHERE id=:data_id';
				DB::update($consulta, [ ':foto_id'	=> $id, ':data_id'	=> $pedido->data_id ]);
				$pedido = ChangeAsked::verificar_pedido_actual($user->user_id, $user->year_id, $user->tipo);
			}else{
				$dt = Carbon::now('America/Bogota');
				$consulta 	= 'INSERT INTO change_asked_data(image_to_delete_id, created_at, updated_at) VALUES(:foto_id, :created_at, :updated_at)';
				DB::insert($consulta, [ ':foto_id'	=> $id, ':created_at'	=> $dt, ':updated_at'	=> $dt ]);
				$last_id 	= DB::getPdo()->lastInsertId();

				$consulta 	= 'UPDATE change_asked SET data_id=:data_id WHERE id=:asked_id';
				DB::update($consulta, [ ':data_id'	=> $last_id, ':asked_id' => $pedido->asked_id ]);

				$pedido 	= ChangeAsked::verificar_pedido_actual($user->user_id, $user->year_id, $user->tipo);
			}
			return ['pedido' => $pedido];
		}
		
		ImageModel::eliminar_imagen_y_enlaces($id);
		

		
		return $img;
	}

}