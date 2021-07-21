<?php namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;


use DB;


class Alumno extends Model {
	use SoftDeletes;
	
	protected $table = 'alumnos';
	protected $dates = ['deleted_at', 'fecha_nac'];
	protected $softDelete = true;


	public function matriculas()
	{
		return $this->hasMany('Matricula');
	}

	public static function userData($alumno_id)
	{
		$consulta = 'SELECT a.user_id, u.username, a.sexo, u.email, a.fecha_nac,
					u.imagen_id, IFNULL(i.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as imagen_nombre, 
					a.foto_id, IFNULL(i2.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as foto_nombre
				from alumnos a 
				inner join users u on a.user_id=u.id and u.deleted_at is null
				left join images i on i.id=u.imagen_id and i.deleted_at is null
				left join images i2 on i2.id=a.foto_id and i2.deleted_at is null
				where a.id=? and a.deleted_at is null';

		$datos = DB::select($consulta, [$alumno_id]);
		if (count($datos)>0) {
			return $datos[0];
		}else{
			return [''=>null];
		}
	}


	public static function alumnoData($alumno_id, $year_id)
	{
		$consulta = 'SELECT a.id as alumno_id, a.nombres, a.apellidos, a.facebook, a.religion, a.nee, a.nee_descripcion,
						a.user_id, u.username, a.sexo, u.email, a.fecha_nac, a.pazysalvo, a.deuda,
						u.imagen_id, IFNULL(i.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as imagen_nombre, 
						a.foto_id, IFNULL(i2.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as foto_nombre,
						m.grupo_id, g.nombre as nombre_grupo, g.abrev as abrev_grupo, g.titular_id, g.orden, g.caritas
					from alumnos a 
					inner join matriculas m on m.alumno_id=a.id 
					inner join grupos g on g.id=m.grupo_id and g.year_id=? and g.deleted_at is null
					left join users u on a.user_id=u.id and u.deleted_at is null
					left join images i on i.id=u.imagen_id and i.deleted_at is null
					left join images i2 on i2.id=a.foto_id and i2.deleted_at is null
					where a.id=? and a.deleted_at is null';

		$datos = DB::select($consulta, [$year_id, $alumno_id]);
		if (count($datos)>0) {
			return $datos[0];
		}else{
			return false;
		}
		
	}

	public static function detailedNotas($alumno_id)
	{
		$consulta = 'SELECT a.user_id, u.username, a.sexo, u.email, a.fecha_nac,
				u.imagen_id, IFNULL(i.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as imagen_nombre, 
				a.foto_id, IFNULL(i2.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as foto_nombre
				from alumnos a 
				inner join users u on a.user_id=u.id
				left join images i on i.id=u.imagen_id
				left join images i2 on i2.id=a.foto_id
				where a.id=? and a.deleted_at is null';

		$datos = DB::select($consulta, [$alumno_id]);
		return $datos[0];
	}
}