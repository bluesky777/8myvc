<?php namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

use DB;
use File;
use \Log;
use App\User;


class ImageModel extends Model {
	protected $fillable = [];

	protected $table = 'images';

	use SoftDeletes;
	protected $softDelete = true;



	public static function DatosImagen($imagen_id, $user_id)
	{
		$datos_imagen = null;
		$oficiales		= [];
		
		$oficiales	= DB::select('SELECT a.nombres, i.nombre, a.apellidos, a.sexo, "alumno" as usuario, i.id FROM images i 
							inner join alumnos a on a.foto_id=i.id and i.id=? and a.deleted_at is null', [$imagen_id]);
		
		$imagen_alu = DB::select('SELECT p.nombres, i.nombre, p.apellidos, p.sexo, "profesor", i.id FROM images i 
							inner join profesores p on p.foto_id=i.id and i.id=? and p.deleted_at is null', [$imagen_id]);
							
		$imagen_acu = DB::select('SELECT a.nombres, i.nombre, a.apellidos, a.sexo, "acudiente", i.id FROM images i 
							inner join acudientes a on a.foto_id=i.id and i.id=? and a.deleted_at is null', [$imagen_id]);
		
		$oficiales	= array_merge($oficiales, $imagen_alu, $imagen_acu);
		/*
		$consulta = 'SELECT a.nombres, i.nombre, a.apellidos, a.sexo, "alumno" as usuario, i.id  FROM images i 
				inner join alumnos a on a.foto_id=i.id and i.id=:imagen_id1
				UNION 
				SELECT p.nombres, i.nombre, p.apellidos, p.sexo, "profesor", i.id FROM images i 
				inner join profesores p on p.foto_id=i.id  and i.id=:imagen_id2
				UNION 
				SELECT a.nombres, i.nombre, a.apellidos, a.sexo, "acudiente", i.id FROM images i 
				inner join acudientes a on a.foto_id=i.id  and i.id=:imagen_id3';

		$oficiales = DB::select(DB::raw($consulta), array(
					':imagen_id1'	=> $imagen_id,
					':imagen_id2'	=> $imagen_id,
					':imagen_id3'	=> $imagen_id,
				));
		*/
		
		
		$imagen_dat = DB::select('SELECT u.username, u.tipo, u.id FROM images i INNER JOIN users u ON u.id=i.created_by and u.deleted_at is null WHERE i.id=?', [ $imagen_id ]);
		
		$imagen_creator = [];
		
		if (count($imagen_dat)>0) {
			$imagen_dat = $imagen_dat[0];
			
			if ($imagen_dat->tipo == 'Alumno') {
				
				$dato_al	= DB::select('SELECT a.nombres, a.apellidos, a.sexo, "alumno" as usuario, a.id FROM alumnos a WHERE a.id=? and a.deleted_at is null', [$imagen_dat->id]);
				
				if (count($dato_al)>0) {
					$imagen_creator = $dato_al[0];
				}
				
			}elseif ($imagen_dat->tipo == 'Profesor') {
				
				$dato_al	= DB::select('SELECT a.nombres, a.apellidos, a.sexo, "profesor" as usuario, a.id FROM profesor a WHERE a.id=? and a.deleted_at is null', [$imagen_dat->id]);
				
				if (count($dato_al)>0) {
					$imagen_creator = $dato_al[0];
				}
				
			}elseif ($imagen_dat->tipo == 'Acudiente') {
				
				$dato_al	= DB::select('SELECT a.nombres, a.apellidos, a.sexo, "acudiente" as usuario, a.id FROM acudientes a WHERE a.id=? and a.deleted_at is null', [$imagen_dat->id]);
				
				if (count($dato_al)>0) {
					$imagen_creator = $dato_al[0];
				}
				
			}elseif ($imagen_dat->tipo == 'Usuario') {
				
				$dato_al	= DB::select('SELECT a.username as nombres, "" as apellidos, a.sexo, "usuario" as usuario, a.id FROM users a WHERE a.id=? and a.deleted_at is null', [$imagen_dat->id]);
				
				if (count($dato_al)>0) {
					$imagen_creator = $dato_al[0];
				}
				
			}
		}
		

		$consulta = 'SELECT u.username, i.nombre, u.sexo, i.id FROM images i 
				inner join users u on u.imagen_id=i.id  and i.id=:imagen_id';

		$de_usuario = DB::select(DB::raw($consulta), array(
					':imagen_id'	=> $imagen_id
				));

		$datos_imagen = ['oficiales' => $oficiales, 'de_usuario' => $de_usuario, 'imagen_creator' => $imagen_creator];

		return $datos_imagen;
	}



	public static function eliminar_imagen_y_enlaces($imagen_id)
	{
		$img 		= ImageModel::findOrFail($imagen_id);
		$filename 	= 'images/perfil/'.$img->nombre;
		
		if (File::exists($filename)) {
			File::delete($filename);
		}else{
			Log::info($imagen_id . ' -- Al parecer NO existe imagen: ' . $filename);
		}
		
		$img->delete();

		// Elimino cualquier referencia que otros tengan a esa imagen borrada.
		$alumnos = Alumno::where('foto_id', $imagen_id)->get();
		foreach ($alumnos as $alum) {
			$alum->foto_id = null;
			$alum->save();
		}
		
		$profesores = Profesor::where('foto_id', $imagen_id)->get();
		foreach ($profesores as $prof) {
			$prof->foto_id = null;
			$prof->save();
		}
		$profesores = Profesor::where('firma_id', $imagen_id)->get();
		foreach ($profesores as $prof) {
			$prof->firma_id = null;
			$prof->save();
		}
		
		$acudientes = Acudiente::where('foto_id', $imagen_id)->get();
		foreach ($acudientes as $acud) {
			$acud->foto_id = null;
			$acud->save();
		}
		$users = User::where('imagen_id', $imagen_id)->get();
		foreach ($users as $user) {
			$user->imagen_id = null;
			$user->save();
		}
		$years = Year::where('logo_id', $imagen_id)->get();
		foreach ($years as $year) {
			$year->logo_id = null;
			$year->save();
		}
		
		
	}


}