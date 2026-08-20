<?php namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;


use Illuminate\Support\Facades\DB;
/**
 * Las columnas de `alumnos`, tal como están en el esquema congelado.
 *
 * Generado desde database/schema/mysql-schema.sql — no se edita a mano.
 * Ver tools/columnas-en-los-modelos.php.
 *
 * --- columnas de la tabla, generadas por tools/columnas-en-los-modelos.php ---
 *
 * @property int $id
 * @property ?string $no_matricula
 * @property string $nombres
 * @property ?string $apellidos
 * @property string $sexo
 * @property ?int $user_id
 * @property ?string $fecha_nac
 * @property ?int $ciudad_nac
 * @property ?int $tipo_doc
 * @property ?string $documento
 * @property ?int $ciudad_doc
 * @property ?string $tipo_sangre
 * @property ?string $eps
 * @property ?string $telefono
 * @property ?string $celular
 * @property ?string $direccion
 * @property ?string $barrio
 * @property ?int $estrato
 * @property ?int $ciudad_resid
 * @property ?int $is_urbana
 * @property ?int $egresado
 * @property ?string $religion
 * @property ?string $email
 * @property ?string $facebook
 * @property ?int $foto_id
 * @property ?int $pazysalvo
 * @property ?int $deuda
 * @property ?string $discapacidad
 * @property ?int $has_sisben
 * @property ?int $nro_sisben
 * @property ?int $has_sisben_3
 * @property ?int $nro_sisben_3
 * @property int $nee
 * @property ?string $nee_descripcion
 * @property ?int $presencial
 * @property ?int $created_by
 * @property ?int $updated_by
 * @property ?int $deleted_by
 * @property ?string $deleted_at
 * @property ?string $created_at
 * @property ?string $updated_at
 * --- fin de las columnas generadas ---
 *
 * Y los atributos que NO son columnas: el código se los cuelga al modelo en
 * tiempo de ejecución para armar la respuesta, que es un patrón repetido por
 * todo el proyecto. Eloquent los guarda entre los atributos y salen en el JSON,
 * así que forman parte del contrato con el frontend igual que las columnas;
 * anotarlos es lo que permite que el análisis siga avisando de un nombre mal
 * escrito en vez de callarse con todos.
 *
 * @property \App\User $user  el usuario recién creado, para devolverlo junto al alumno
 * @property \App\Models\Grupo $grupo  el grupo de su matrícula, añadido al armar la respuesta
 */


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