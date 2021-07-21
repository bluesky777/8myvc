<?php namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;


use DB;


class VtCandidato extends Model {
	protected $fillable = [];
	protected $table = "vt_candidatos";

	use SoftDeletes;
	protected $softDelete = true;

	public static function porAspiracion($aspiracion_id, $year_id)
	{
		/*
		$consulta = 'SELECT c.id as candidato_id, c.plancha, c.numero, usus.persona_id, 
					usus.nombres, usus.apellidos, usus.user_id, usus.username, usus.tipo, usus.imagen_id, usus.imagen_nombre, usus.nombre_grupo, usus.abrev_grupo, 
					usus.foto_id, usus.foto_nombre, usus.imagen_id, usus.imagen_nombre
				FROM vt_candidatos c 
				INNER JOIN users u ON u.id=c.user_id and c.aspiracion_id=:aspiracion_id
				inner join (
					
				SELECT p.id as persona_id, p.nombres, p.apellidos, p.user_id, u.username, 
					("Pr") as tipo, p.sexo, 
					u.imagen_id, IFNULL(i.nombre, IF(p.sexo="F","default_female.png", "default_male.png")) as imagen_nombre, 
					p.foto_id, IFNULL(i2.nombre, IF(p.sexo="F","default_female.png", "default_male.png")) as foto_nombre, 
					"N/A" as grupo_id, ("N/A") as nombre_grupo, ("N/A") as abrev_grupo, "N/A" as year_id  
					from profesores p 
					inner join users u on p.user_id=u.id
					left join images i on i.id=u.imagen_id
					left join images i2 on i2.id=p.foto_id
					where p.deleted_at is null
				union
				SELECT a.id as persona_id, a.nombres, a.apellidos, a.user_id, u.username, 
					("Al") as tipo, a.sexo, 
					u.imagen_id, IFNULL(i.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as imagen_nombre, 
					a.foto_id, IFNULL(i2.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as foto_nombre, 
					g.id as grupo_id, g.nombre as nombre_grupo, g.abrev as abrev_grupo, g.year_id
					from alumnos a 
					inner join users u on a.user_id=u.id
					inner join matriculas m on m.alumno_id=a.id and (m.estado="MATR" or m.estado="ASIS" or m.estado="PREM")
					inner join grupos g on g.id=m.grupo_id
					left join images i on i.id=u.imagen_id
					left join images i2 on i2.id=a.foto_id
					where a.deleted_at is null
				union
				SELECT ac.id as persona_id, ac.nombres, ac.apellidos, ac.user_id, u.username, 
					("Acu") as tipo, ac.sexo, 
					u.imagen_id, IFNULL(i.nombre, IF(ac.sexo="F","default_female.png", "default_male.png")) as imagen_nombre, 
					ac.foto_id, IFNULL(i2.nombre, IF(ac.sexo="F","default_female.png", "default_male.png")) as foto_nombre, 
					"N/A" as grupo_id, ("N/A") as nombre_grupo, ("N/A") as abrev_grupo, "N/A" as year_id
					from acudientes ac 
					inner join users u on ac.user_id=u.id
					left join images i on i.id=u.imagen_id
					left join images i2 on i2.id=ac.foto_id
					where ac.deleted_at is null
				union
				SELECT u.id as persona_id, "" as nombres, "" as apellidos, u.id as user_id, u.username,
					("Usu") as tipo, u.sexo, 
					u.imagen_id, IFNULL(i.nombre, IF(u.sexo="F","default_female.png", "default_male.png")) as imagen_nombre, 
					u.imagen_id as foto_id, IFNULL(i.nombre, IF(u.sexo="F","default_female.png", "default_male.png")) as foto_nombre, 
					"N/A" as grupo_id, ("N/A") as nombre_grupo, ("N/A") as abrev_grupo, "N/A" as year_id  
					from users u
					left join images i on i.id=u.imagen_id 
					where u.id not in (SELECT p.user_id
								from profesores p 
								inner join users u on p.user_id=u.id
							union
							SELECT a.user_id
								from alumnos a 
								inner join users u on a.user_id=u.id
							union
							SELECT ac.user_id
								from acudientes ac 
								inner join users u on ac.user_id=u.id
						)
					and u.deleted_at is null ) usus
					on usus.user_id=c.user_id
				where c.deleted_at is null and usus.year_id=:year_id order by c.plancha';
		*/
		$consulta = 'SELECT c.id as candidato_id, c.plancha, c.numero, usus.persona_id, 
					usus.nombres, usus.apellidos, usus.user_id, usus.username, usus.tipo, usus.imagen_id, usus.imagen_nombre, usus.nombre_grupo, usus.abrev_grupo, 
					usus.foto_id, usus.foto_nombre, usus.imagen_id, usus.imagen_nombre
				FROM vt_candidatos c 
				INNER JOIN users u ON u.id=c.user_id and c.aspiracion_id=:aspiracion_id
				inner join (
					
				SELECT a.id as persona_id, a.nombres, a.apellidos, a.user_id, u.username, 
						("Al") as tipo, a.sexo, 
						u.imagen_id, IFNULL(i.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as imagen_nombre, 
						a.foto_id, IFNULL(i2.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as foto_nombre, 
						g.id as grupo_id, g.nombre as nombre_grupo, g.abrev as abrev_grupo, g.year_id
					from alumnos a 
					inner join users u on a.user_id=u.id
					inner join matriculas m on m.alumno_id=a.id and (m.estado="MATR" or m.estado="ASIS" or m.estado="PREM")
					inner join grupos g on g.id=m.grupo_id
					left join images i on i.id=u.imagen_id
					left join images i2 on i2.id=a.foto_id
					where a.deleted_at is null
				 ) usus
					on usus.user_id=c.user_id
				where c.deleted_at is null and usus.year_id=:year_id order by c.plancha';
				
		$datos = array(
			':aspiracion_id'	=> $aspiracion_id,
			':year_id'			=> $year_id);

		$candidatos = DB::select($consulta, $datos);

		return $candidatos;
	}

	public static function porAspiracionAnterior($aspiracion_id, $year_id)
	{
		$consulta = 'SELECT c.id as candidato_id, c.plancha, c.numero, usus.persona_id, vp.id as participante_id, 
					usus.nombres, usus.apellidos, usus.user_id, usus.username, usus.tipo, usus.imagen_id, usus.imagen_nombre, usus.nombre_grupo, usus.abrev_grupo 
				FROM vt_candidatos c 
				inner join vt_participantes vp on c.aspiracion_id = :aspiracion_id and vp.id=c.user_id
				inner join (
					
				SELECT p.id as persona_id, p.nombres, p.apellidos, p.user_id, u.username, 
					("Pr") as tipo, p.sexo, u.email, p.fecha_nac, p.ciudad_nac, 
					u.imagen_id, IFNULL(i.nombre, IF(p.sexo="F","default_female.png", "default_male.png")) as imagen_nombre, 
					p.foto_id, IFNULL(i2.nombre, IF(p.sexo="F","default_female.png", "default_male.png")) as foto_nombre, 
					"N/A" as grupo_id, ("N/A") as nombre_grupo, ("N/A") as abrev_grupo, "N/A" as year_id  
					from profesores p 
					inner join users u on p.user_id=u.id
					left join images i on i.id=u.imagen_id
					left join images i2 on i2.id=p.foto_id
					where p.deleted_at is null
				union
				SELECT a.id as persona_id, a.nombres, a.apellidos, a.user_id, u.username, 
					("Al") as tipo, a.sexo, u.email, a.fecha_nac, a.ciudad_nac, 
					u.imagen_id, IFNULL(i.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as imagen_nombre, 
					a.foto_id, IFNULL(i2.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as foto_nombre, 
					g.id as grupo_id, g.nombre as nombre_grupo, g.abrev as abrev_grupo, g.year_id
					from alumnos a 
					inner join users u on a.user_id=u.id
					inner join matriculas m on m.alumno_id=a.id and (m.estado="MATR" or m.estado="ASIS" or m.estado="PREM")
					inner join grupos g on g.id=m.grupo_id
					left join images i on i.id=u.imagen_id
					left join images i2 on i2.id=a.foto_id
					where a.deleted_at is null
				union
				SELECT ac.id as persona_id, ac.nombres, ac.apellidos, ac.user_id, u.username, 
					("Pr") as tipo, ac.sexo, u.email, ac.fecha_nac, ac.ciudad_nac, 
					u.imagen_id, IFNULL(i.nombre, IF(ac.sexo="F","default_female.png", "default_male.png")) as imagen_nombre, 
					ac.foto_id, IFNULL(i2.nombre, IF(ac.sexo="F","default_female.png", "default_male.png")) as foto_nombre, 
					"N/A" as grupo_id, ("N/A") as nombre_grupo, ("N/A") as abrev_grupo, "N/A" as year_id
					from acudientes ac 
					inner join users u on ac.user_id=u.id
					left join images i on i.id=u.imagen_id
					left join images i2 on i2.id=ac.foto_id
					where ac.deleted_at is null
				union
				SELECT u.id as persona_id, "" as nombres, "" as apellidos, u.id as user_id, u.username,
					("Us") as tipo, u.sexo, u.email, "N/A" as fecha_nac, "N/A" as ciudad_nac, 
					u.imagen_id, IFNULL(i.nombre, IF(u.sexo="F","default_female.png", "default_male.png")) as imagen_nombre, 
					u.imagen_id as foto_id, IFNULL(i.nombre, IF(u.sexo="F","default_female.png", "default_male.png")) as foto_nombre, 
					"N/A" as grupo_id, ("N/A") as nombre_grupo, ("N/A") as abrev_grupo, "N/A" as year_id  
					from users u
					left join images i on i.id=u.imagen_id 
					where u.id not in (SELECT p.user_id
								from profesores p 
								inner join users u on p.user_id=u.id
							union
							SELECT a.user_id
								from alumnos a 
								inner join users u on a.user_id=u.id
							union
							SELECT ac.user_id
								from acudientes ac 
								inner join users u on ac.user_id=u.id
						)
					and u.deleted_at is null ) usus
					on usus.user_id=vp.user_id
				where c.deleted_at is null and usus.year_id=:year_id';

		$datos = array(
			':aspiracion_id'	=> $aspiracion_id,
			':year_id'			=> $year_id);

		$candidatos = DB::select($consulta, $datos);

		return $candidatos;
	}
	
}