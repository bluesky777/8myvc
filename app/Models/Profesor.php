<?php namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;


use DB;


class Profesor extends Model {
	use SoftDeletes;
	
	protected $fillable = [];
	protected $table = "profesores";

	protected $dates = ['deleted_at', 'created_at'];
	protected $softDelete = true;



	public static function detallado($profesor_id)
	{
		$consulta = 'SELECT p.id as profesor_id, p.nombres as nombres_profesor, p.apellidos as apellidos_profesor,
						p.user_id, u.username, p.sexo, u.email, p.fecha_nac, p.tipo_doc, p.num_doc,
						p.ciudad_doc, p.ciudad_nac, p.titulo, p.estado_civil, p.barrio, p.direccion,
						p.telefono, p.celular, p.facebook, p.email, p.tipo_profesor,
						u.imagen_id, IFNULL(i.nombre, IF(p.sexo="F","default_female.png", "default_male.png")) as imagen_nombre, 
						p.foto_id, IFNULL(i2.nombre, IF(p.sexo="F","default_female.png", "default_male.png")) as foto_nombre
					from profesores p
					left join users u on p.user_id=u.id and u.deleted_at is null
					left join images i on i.id=u.imagen_id and i.deleted_at is null
					left join images i2 on i2.id=p.foto_id and i2.deleted_at is null
					where p.id=? and p.deleted_at is null';

		$profesor = DB::select(DB::raw($consulta), array($profesor_id));
		return $profesor[0];
	}

	

	public static function asignaturas($year_id, $profesor_id)
	{
		$consulta = 'SELECT a.id as asignatura_id, a.grupo_id, a.profesor_id, a.creditos, a.orden,
							m.materia, m.alias as alias_materia, g.nombre as nombre_grupo, g.abrev as abrev_grupo, g.titular_id, g.caritas,
							gr.nivel_educativo_id
						FROM asignaturas a
						inner join materias m on m.id=a.materia_id and m.deleted_at is null
						inner join grupos g on g.id=a.grupo_id and g.year_id=:year_id and g.deleted_at is null
						inner join grados gr on gr.id=g.grado_id and gr.deleted_at is null 
						where a.profesor_id=:profesor_id and a.deleted_at is null
						order by g.orden, a.orden, m.materia, m.alias, a.id';

		$asignaturas = DB::select(DB::raw($consulta), array(':year_id' => $year_id, ':profesor_id' => $profesor_id));

		return $asignaturas;
	}




	public static function fromyear($year_id)
	{
		$consulta = 'SELECT p.id, p.nombres, p.apellidos, p.sexo,
						p.foto_id, p.titulo, p.facebook, p.email, p.tipo_profesor, p.user_id
					from profesores p
					inner join contratos c on c.profesor_id=p.id and p.deleted_at is null   
					where c.year_id=:year_id and c.deleted_at is null';

		$profesores = DB::select($consulta, [':year_id' => $year_id]);

		return $profesores;
	}

	public static function contratos($year_id)
	{
		
		$consulta = 'SELECT p.id as profesor_id, p.nombres, p.apellidos, concat(p.nombres, " ", p.apellidos) as nombre_completo, p.sexo, p.foto_id, p.tipo_doc,
					p.num_doc, p.ciudad_doc, p.fecha_nac, p.ciudad_nac, p.titulo,
					p.estado_civil, p.barrio, p.direccion, p.telefono, p.celular,
					p.facebook, p.email, p.tipo_profesor, p.user_id, u.username,
					u.email as email_usu, u.imagen_id, u.is_superuser,
					c.id as contrato_id, c.year_id,
					p.foto_id, IFNULL(i.nombre, IF(p.sexo="F","default_female.png", "default_male.png")) as foto_nombre
				from profesores p
				inner join contratos c on c.profesor_id=p.id and c.year_id=:year_id and c.deleted_at is null
				left join users u on p.user_id=u.id and u.deleted_at is null
				LEFT JOIN images i on i.id=p.foto_id and i.deleted_at is null
				where p.deleted_at is null
				order by p.nombres, p.apellidos';

		$profesores = DB::select($consulta, array(':year_id'=>$year_id));

		return $profesores;
	}

}