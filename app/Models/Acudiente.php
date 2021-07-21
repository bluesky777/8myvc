<?php namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Acudiente extends Model {
	use SoftDeletes;
	protected $fillable = [];
	
	protected $dates = ['deleted_at', 'created_at'];
	protected $softDelete = true;


	public static $consulta_acudientes_de_alumno = 'SELECT ac.id, ac.nombres, ac.apellidos, ac.sexo, ac.fecha_nac, ac.ciudad_nac, c1.ciudad as ciudad_nac_nombre, ac.ciudad_doc, c2.ciudad as ciudad_doc_nombre, ac.telefono, pa.parentesco, pa.observaciones, pa.id as parentesco_id, ac.user_id, 
							ac.celular, ac.ocupacion, ac.email, ac.barrio, ac.direccion, ac.tipo_doc, ac.documento, ac.created_by, ac.updated_by, ac.created_at, ac.updated_at, 
							ac.foto_id, IFNULL(i.nombre, IF(ac.sexo="F","default_female.png", "default_male.png")) as foto_nombre, 
							u.username, u.is_active
						FROM parentescos pa
						left join acudientes ac on ac.id=pa.acudiente_id and ac.deleted_at is null
						left join users u on ac.user_id=u.id and u.deleted_at is null
						left join images i on i.id=ac.foto_id and i.deleted_at is null
						left join ciudades c1 on c1.id=ac.ciudad_nac and c1.deleted_at is null
						left join ciudades c2 on c2.id=ac.ciudad_doc and c2.deleted_at is null
						WHERE pa.alumno_id=? and pa.deleted_at is null';
						

	public static $consulta_alumnos_de_acudiente = 'SELECT a.no_matricula, a.nombres, a.apellidos, a.sexo, a.user_id, 
							a.fecha_nac, a.tipo_doc, a.documento, a.tipo_sangre, a.eps, a.telefono, a.celular, 
							a.direccion, a.barrio, a.estrato, a.religion, a.email, a.facebook, a.created_by, a.updated_by,
							a.pazysalvo, a.deuda, 
							u.username, u.is_superuser, u.is_active,
							p.parentesco, p.observaciones, gr.nombre_grupo
						FROM alumnos a 
						inner join parentescos p on p.alumno_id=a.id and p.acudiente_id=?
						left join users u on a.user_id=u.id and u.deleted_at is null
						left join 
							(SELECT m.alumno_id, g.orden, g.nombre as nombre_grupo FROM matriculas m 
							INNER JOIN grupos g on g.id=m.grupo_id and g.deleted_at is null and g.year_id=?
							WHERE m.deleted_at is null and (m.estado="ASIS" or m.estado="MATR")
							) as gr
							on gr.alumno_id=a.id
						where a.deleted_at is null and p.deleted_at is null
						order by gr.orden, a.apellidos, a.nombres';

	public static $acudiente_vacio = ['id' => '', 'nombres' => '', 'apellidos' => '', 'sexo' => '', 'es_acudiente' => '', 'fecha_nac' => '', 'ciudad_nac_nombre' => '', 'ciudad_doc_nombre' => '', 'departamento_doc_nombre' => '', 'telefono' => '', 'parentesco' => '', 'observaciones' => '', 
				'celular' => '', 'ocupacion' => '', 'email' => '', 'barrio' => '', 'direccion' => '', 'tipo_doc' => '', 'tipo_doc_nombre' => '', 'documento' => '', 'username' => '', 'is_active' => ''];


}