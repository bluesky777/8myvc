<?php namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

use DB;
use App\Models\Debugging;

class Grupo extends Model {
	use SoftDeletes;

	protected $fillable = [];
	protected $table = 'grupos';
	
	protected $dates = ['deleted_at', 'created_at'];
	protected $softDelete = true;
	
	
	public static $consulta_grupos_titularia = 'SELECT g.id, g.nombre, g.abrev, g.orden, gra.orden as orden_grado, g.grado_id, g.year_id, g.titular_id,
							p.nombres as nombres_titular, p.apellidos as apellidos_titular, p.titulo,
							g.created_at, g.updated_at, gra.nombre as nombre_grado 
						from grupos g
						inner join grados gra on gra.id=g.grado_id and g.year_id=:year_id 
						inner join profesores p on p.id=g.titular_id and p.id=:titular_id
						where g.deleted_at is null
						order by g.orden';


	public static function alumnos($grupo_id, $con_retirados='')
	{
		$consulta = '';

		if ($con_retirados=='') {
			// Consulta con solo los matriculados
			$consulta = 'SELECT m.id as matricula_id, m.alumno_id, a.no_matricula, a.nombres, a.apellidos, a.sexo, a.user_id, a.nee, a.nee_descripcion,
							a.fecha_nac, a.ciudad_nac, a.celular, a.direccion, a.religion, t.tipo as tipo_doc, t.abrev as tipo_doc_abrev, a.documento, a.no_matricula, 
							m.grupo_id, m.estado, m.nuevo, m.repitente, username, m.promovido, m.promedio, m.cant_asign_perdidas, m.cant_areas_perdidas,
							u.imagen_id, IFNULL(i.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as imagen_nombre, 
							a.foto_id, IFNULL(i2.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as foto_nombre
						FROM alumnos a 
						inner join matriculas m on a.id=m.alumno_id and m.grupo_id=? and (m.estado="MATR" or m.estado="ASIS" or m.estado="PREM") and m.deleted_at is null
						left join users u on a.user_id=u.id and u.deleted_at is null
						left join tipos_documentos t on a.tipo_doc=t.id and t.deleted_at is null
						left join images i on i.id=u.imagen_id and i.deleted_at is null
						left join images i2 on i2.id=a.foto_id and i2.deleted_at is null
						where a.deleted_at is null and m.deleted_at is null
						order by a.apellidos, a.nombres';
		}else{
			// Consulta incluyendo los matriculados y retirados.
			// $consulta = 'SELECT m.id as matricula_id, m.alumno_id, a.no_matricula, a.nombres, a.apellidos, a.sexo, a.user_id, a.nee, a.nee_descripcion, 
			// 				a.fecha_nac, a.ciudad_nac, a.celular, a.direccion, a.religion, t.tipo as tipo_doc, t.abrev as tipo_doc_abrev, a.documento, a.no_matricula, 
			// 				m.grupo_id, m.estado, m.nuevo, m.repitente, m.promovido, m.promedio, m.cant_asign_perdidas, m.cant_areas_perdidas,
			// 				u.imagen_id, IFNULL(i.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as imagen_nombre, 
			// 				a.foto_id, IFNULL(i2.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as foto_nombre,
			// 				m.fecha_retiro as fecha_retiro 
			// 			FROM alumnos a 
			// 			inner join matriculas m on a.id=m.alumno_id and m.grupo_id=? and m.deleted_at is null 
			// 			left join users u on a.user_id=u.id and u.deleted_at is null
			// 			left join tipos_documentos t on a.tipo_doc=t.id and t.deleted_at is null
			// 			left join images i on i.id=u.imagen_id and i.deleted_at is null
			// 			left join images i2 on i2.id=a.foto_id and i2.deleted_at is null
			// 			where a.deleted_at is null and m.deleted_at is null
			// 			order by a.apellidos, a.nombres';


			$sql_condicion = '';
			$canti_retirados = count($con_retirados);

			for ($i=0; $i < $canti_retirados; $i++) { 
				$sql_condicion .= ' or m.id="'.$con_retirados[$i]['matricula_id'].'"';
			}

			// Prueba para excluir retirados pero incluir a los actuales solicitados
			$consulta = 'SELECT m.id as matricula_id, m.alumno_id, a.no_matricula, a.nombres, a.apellidos, a.sexo, a.user_id, a.nee, a.nee_descripcion,
							a.fecha_nac, a.ciudad_nac, a.celular, a.direccion, a.religion, t.tipo as tipo_doc, t.abrev as tipo_doc_abrev, a.documento, a.no_matricula, 
							m.grupo_id, m.estado, m.nuevo, m.repitente, username, m.promovido, m.promedio, m.cant_asign_perdidas, m.cant_areas_perdidas,
							u.imagen_id, IFNULL(i.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as imagen_nombre, 
							a.foto_id, IFNULL(i2.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as foto_nombre
						FROM alumnos a 
						inner join matriculas m on a.id=m.alumno_id and m.grupo_id=? and ((m.estado="MATR" or m.estado="ASIS" or m.estado="PREM") '.$sql_condicion.' ) and m.deleted_at is null
						left join users u on a.user_id=u.id and u.deleted_at is null
						left join tipos_documentos t on a.tipo_doc=t.id and t.deleted_at is null
						left join images i on i.id=u.imagen_id and i.deleted_at is null
						left join images i2 on i2.id=a.foto_id and i2.deleted_at is null
						where a.deleted_at is null and m.deleted_at is null
						order by a.apellidos, a.nombres';


		}

		$alumnos = DB::select($consulta, [$grupo_id]);

		return $alumnos;
	}

	public static function detailed_materias($grupo_id, $profesor_id=null, $exceptuando=false)
	{
		$complemento = ''; // Para complementar la consulta
		if ($profesor_id) {
			if ($exceptuando) {
				$complemento = ' and p.id!='.$profesor_id. ' ';
			}else{
				$complemento = ' and p.id='.$profesor_id. ' ';
			}
		}

		$consulta = 'SELECT @rownum:=@rownum+1 AS indice, r.*
			FROM(SELECT a.id as asignatura_id, a.grupo_id, a.profesor_id, a.creditos, ar.orden as orden_area, m.orden as orden_materia, a.orden as orden_asignatura,
				m.materia, m.alias as alias_materia, m.area_id,
				p.nombres as nombres_profesor, p.apellidos as apellidos_profesor,
				p.foto_id, IFNULL(i.nombre, IF(p.sexo="F","default_female.png", "default_male.png")) as foto_nombre
			FROM (SELECT @rownum:=0) r, asignaturas a 
			inner join materias m on m.id=a.materia_id and m.deleted_at is null
			left join areas ar on ar.id=m.area_id and ar.deleted_at is null
			inner join profesores p on p.id=a.profesor_id and p.deleted_at is null
			left join images i on p.foto_id=i.id and i.deleted_at is null
			where a.grupo_id=:grupo_id and a.deleted_at is null
			order by ar.orden, m.orden, a.orden)r';

		$asignaturas = DB::select($consulta, [':grupo_id' => $grupo_id]);

		return $asignaturas;
	}

	

	

	public static function detailed_materias_notafinal($alumno_id, $grupo_id, $periodo_id, $year_id)
	{
		$consulta = 'SELECT a.id as asignatura_id, a.grupo_id, a.profesor_id, a.creditos, ar.orden as orden_area, m.orden as orden_materia, a.orden,
				m.materia, m.alias as alias_materia, m.area_id, ar.nombre as area_nombre, ar.alias as area_alias, a.materia_id, 
				p.nombres as nombres_profesor, p.apellidos as apellidos_profesor,
				p.foto_id, IFNULL(i.nombre, IF(p.sexo="F","default_female.png", "default_male.png")) as foto_nombre, 
				n.nota as nota_asignatura, n.created_at, n.recuperada, n.manual, e.desempenio, n.id as nf_id
			FROM asignaturas a 
			inner join materias m on m.id=a.materia_id and m.deleted_at is null
			left join areas ar on ar.id=m.area_id and ar.deleted_at is null
			left join notas_finales n on n.asignatura_id=a.id and n.alumno_id=:alumno_id and n.periodo_id=:periodo_id
			inner join profesores p on p.id=a.profesor_id and p.deleted_at is null 
			left join images i on p.foto_id=i.id and i.deleted_at is null
			left join escalas_de_valoracion e ON e.porc_inicial<=n.nota and e.porc_final>=n.nota and e.deleted_at is null and e.year_id=:year_id
			where a.grupo_id=:grupo_id and a.deleted_at is null
			order by ar.orden, m.orden, a.orden';

		$asignaturas = DB::select($consulta, [ ':alumno_id' => $alumno_id, ':periodo_id' => $periodo_id, ':year_id' => $year_id, ':grupo_id' => $grupo_id ]);

		return $asignaturas;
	}

	
	
	
	
	public static function detailed_materias_notas_finales($alumno_id, $grupo_id, $year_id, $num_periodo=4)
	{
		$asignaturas = [];
		
		if ($num_periodo == 1) {
			
			$consulta = 'SELECT @rownum:=@rownum+1 AS indice, r.*
						from(SELECT nf.asignatura_id, a.grupo_id, a.profesor_id, a.creditos, ar.orden as orden_area, m.orden as orden_materia, a.orden as orden_asignatura,
								m.materia, m.alias as alias_materia, ar.nombre as area_nombre, m.area_id, ar.alias as area_alias,
								p.nombres as nombres_profesor, p.apellidos as apellidos_profesor,
								p.foto_id, IFNULL(i.nombre, IF(p.sexo="F","default_female.png", "default_male.png")) as foto_nombre,
								nf.nota_final_per1, nf.nf_id_1, nf.nf_updated_at1, e.desempenio 
							FROM (SELECT @rownum:=0) r, periodos pe 
							inner join (
								select distinct nf.asignatura_id, nf.alumno_id, nf.nota as nota_final_per1, nf.id as nf_id_1, nf.updated_at as nf_updated_at1, nf.periodo, nf.periodo_id  from notas_finales nf order by nf.id desc
							)nf on nf.alumno_id=:al1 and pe.numero=1 and pe.id=nf.periodo_id and pe.year_id=:year_id1 and pe.deleted_at is null
							left join escalas_de_valoracion e ON e.porc_inicial<=nf.nota_final_per1 and e.porc_final>=nf.nota_final_per1 and e.deleted_at is null and e.year_id=:year_id5
							right join asignaturas a on a.id=nf.asignatura_id and a.deleted_at is null
							inner join materias m on m.id=a.materia_id and m.deleted_at is null
							inner join areas ar on ar.id=m.area_id and ar.deleted_at is null
							inner join grupos g on g.id=a.grupo_id and g.deleted_at is null and g.id=:grupo_id
							inner join profesores p on p.id=a.profesor_id and p.deleted_at is null 
							left join images i on p.foto_id=i.id and i.deleted_at is null
							where a.deleted_at is null and a.profesor_id is not null
							order by ar.orden, m.orden, a.orden)r';

			$asignaturas = DB::select($consulta, [ ':al1' => $alumno_id, ':year_id1' => $year_id, ':grupo_id' => $grupo_id, ':year_id5' => $year_id]);

		}elseif ($num_periodo == 2) {
			
			$consulta = 'SELECT @rownum:=@rownum+1 AS indice,
						r1.*, r2.nota_final_per2, r2.nf_id_2, r2.nf_updated_at2, r2.desempenio
					FROM (SELECT @rownum:=0) r,
						(SELECT nf.asignatura_id, a.grupo_id, a.profesor_id, a.creditos, ar.orden as orden_area, m.orden as orden_materia, a.orden as orden_asignatura,
							m.materia, m.alias as alias_materia, ar.nombre as area_nombre, m.area_id, ar.alias as area_alias,
							p.nombres as nombres_profesor, p.apellidos as apellidos_profesor,
							p.foto_id, IFNULL(i.nombre, IF(p.sexo="F","default_female.png", "default_male.png")) as foto_nombre,
							nf.nota_final_per1, nf.nf_id_1, nf.nf_updated_at1 
						FROM periodos pe 
						inner join (
							select distinct nf.asignatura_id, nf.alumno_id, nf.nota as nota_final_per1, nf.id as nf_id_1, nf.updated_at as nf_updated_at1, nf.periodo, nf.periodo_id  from notas_finales nf order by nf.id desc
						)nf on nf.alumno_id=:al1 and pe.numero=1 and pe.id=nf.periodo_id and pe.year_id=:year_id1 and pe.deleted_at is null
						right join asignaturas a on a.id=nf.asignatura_id and a.deleted_at is null
						inner join materias m on m.id=a.materia_id and m.deleted_at is null
						inner join areas ar on ar.id=m.area_id and ar.deleted_at is null
						inner join grupos g on g.id=a.grupo_id and g.deleted_at is null and g.id=:grupo_id
						inner join profesores p on p.id=a.profesor_id and p.deleted_at is null 
						left join images i on p.foto_id=i.id and i.deleted_at is null
						where a.deleted_at is null and a.profesor_id is not null
						)r1 
					left join 
						(SELECT nf.nota_final_per2, nf.nf_id_2, nf.nf_updated_at2, nf.asignatura_id, e.desempenio FROM periodos p 
						inner join (
								select distinct nf.asignatura_id, nf.alumno_id, nf.nota as nota_final_per2, nf.id as nf_id_2, nf.updated_at as nf_updated_at2, nf.periodo, nf.periodo_id  from notas_finales nf order by nf.id desc
							)nf on nf.alumno_id=:al2 and p.numero=2 and p.id=nf.periodo_id and p.year_id=:year_id2 and p.deleted_at is null
							left join escalas_de_valoracion e ON e.porc_inicial<=nf.nota_final_per2 and e.porc_final>=nf.nota_final_per2 and e.deleted_at is null and e.year_id=:year_id5
						)r2 on r1.asignatura_id=r2.asignatura_id
					order by r1.orden_area, r1.orden_materia, r1.orden_asignatura';

			$asignaturas = DB::select($consulta, [ ':al1' => $alumno_id, ':year_id1' => $year_id, ':grupo_id' => $grupo_id, 
									':al2' => $alumno_id, ':year_id2' => $year_id, ':year_id5' => $year_id]);

		}elseif ($num_periodo == 3) {
			$consulta = 'SELECT @rownum:=@rownum+1 AS indice,
						r1.*, r2.nota_final_per2, r2.nf_id_2, r2.nf_updated_at2,
						r3.nota_final_per3, r3.nf_id_3, r3.nf_updated_at3, r3.desempenio
					FROM (SELECT @rownum:=0) r,
						(SELECT nf.asignatura_id, a.grupo_id, a.profesor_id, a.creditos, ar.orden as orden_area, m.orden as orden_materia, a.orden as orden_asignatura,
							m.materia, m.alias as alias_materia, ar.nombre as area_nombre, m.area_id, ar.alias as area_alias,
							p.nombres as nombres_profesor, p.apellidos as apellidos_profesor,
							p.foto_id, IFNULL(i.nombre, IF(p.sexo="F","default_female.png", "default_male.png")) as foto_nombre,
							nf.nota_final_per1, nf.nf_id_1, nf.nf_updated_at1 
						FROM periodos pe 
						inner join (
							select distinct nf.asignatura_id, nf.alumno_id, nf.nota as nota_final_per1, nf.id as nf_id_1, nf.updated_at as nf_updated_at1, nf.periodo, nf.periodo_id  from notas_finales nf order by nf.id desc
						)nf on nf.alumno_id=:al1 and pe.numero=1 and pe.id=nf.periodo_id and pe.year_id=:year_id1 and pe.deleted_at is null
						right join asignaturas a on a.id=nf.asignatura_id and a.deleted_at is null
						inner join materias m on m.id=a.materia_id and m.deleted_at is null
						inner join areas ar on ar.id=m.area_id and ar.deleted_at is null
						inner join grupos g on g.id=a.grupo_id and g.deleted_at is null and g.id=:grupo_id
						inner join profesores p on p.id=a.profesor_id and p.deleted_at is null 
						left join images i on p.foto_id=i.id and i.deleted_at is null
						where a.deleted_at is null and a.profesor_id is not null
						)r1 
					left join 
						(SELECT nf.nota_final_per2, nf.nf_id_2, nf.nf_updated_at2, nf.asignatura_id FROM periodos p 
						inner join (
								select distinct nf.asignatura_id, nf.alumno_id, nf.nota as nota_final_per2, nf.id as nf_id_2, nf.updated_at as nf_updated_at2, nf.periodo, nf.periodo_id  from notas_finales nf order by nf.id desc
							)nf on nf.alumno_id=:al2 and p.numero=2 and p.id=nf.periodo_id and p.year_id=:year_id2 and p.deleted_at is null
						)r2 on r1.asignatura_id=r2.asignatura_id
					left join 
						(SELECT nf.nota_final_per3, nf.nf_id_3, nf.nf_updated_at3, nf.asignatura_id, e.desempenio FROM periodos p 
						inner join (
								select distinct nf.asignatura_id, nf.alumno_id, nf.nota as nota_final_per3, nf.id as nf_id_3, nf.updated_at as nf_updated_at3, nf.periodo, nf.periodo_id  from notas_finales nf order by nf.id desc
							)nf on nf.alumno_id=:al3 and p.numero=3 and p.id=nf.periodo_id and p.year_id=:year_id3 and p.deleted_at is null
							left join escalas_de_valoracion e ON e.porc_inicial<=nf.nota_final_per3 and e.porc_final>=nf.nota_final_per3 and e.deleted_at is null and e.year_id=:year_id5
						)r3 on r2.asignatura_id=r3.asignatura_id
					order by r1.orden_area, r1.orden_materia, r1.orden_asignatura';

			$asignaturas = DB::select($consulta, [ ':al1' => $alumno_id, ':year_id1' => $year_id, ':grupo_id' => $grupo_id, 
									':al2' => $alumno_id, ':year_id2' => $year_id, ':al3' => $alumno_id, ':year_id3' => $year_id, ':year_id5' => $year_id]);

		}elseif ($num_periodo == 4) {
			$consulta = 'SELECT @rownum:=@rownum+1 AS indice,
						r1.*, r2.nota_final_per2, r2.nf_id_2, r2.nf_updated_at2,
						r3.nota_final_per3, r3.nf_id_3, r3.nf_updated_at3,
						r4.nota_final_per4, r4.nf_id_4, r4.nf_updated_at4, r4.desempenio
					FROM (SELECT @rownum:=0) r,
						(SELECT nf.asignatura_id, a.grupo_id, a.profesor_id, a.creditos, ar.orden as orden_area, m.orden as orden_materia, a.orden as orden_asignatura,
							m.materia, m.alias as alias_materia, ar.nombre as area_nombre, m.area_id, ar.alias as area_alias,
							p.nombres as nombres_profesor, p.apellidos as apellidos_profesor,
							p.foto_id, IFNULL(i.nombre, IF(p.sexo="F","default_female.png", "default_male.png")) as foto_nombre,
							nf.nota_final_per1, nf.nf_id_1, nf.nf_updated_at1 
						FROM periodos pe 
						inner join (
							select distinct nf.asignatura_id, nf.alumno_id, nf.nota as nota_final_per1, nf.id as nf_id_1, nf.updated_at as nf_updated_at1, nf.periodo, nf.periodo_id  from notas_finales nf order by nf.id desc
						)nf on nf.alumno_id=:al1 and pe.numero=1 and pe.id=nf.periodo_id and pe.year_id=:year_id1 and pe.deleted_at is null
						right join asignaturas a on a.id=nf.asignatura_id and a.deleted_at is null
						inner join materias m on m.id=a.materia_id and m.deleted_at is null
						inner join areas ar on ar.id=m.area_id and ar.deleted_at is null
						inner join grupos g on g.id=a.grupo_id and g.deleted_at is null and g.id=:grupo_id
						inner join profesores p on p.id=a.profesor_id and p.deleted_at is null 
						left join images i on p.foto_id=i.id and i.deleted_at is null
						where a.deleted_at is null and a.profesor_id is not null
						)r1 
					left join 
						(SELECT nf.nota_final_per2, nf.nf_id_2, nf.nf_updated_at2, nf.asignatura_id FROM periodos p 
						inner join (
								select distinct nf.asignatura_id, nf.alumno_id, nf.nota as nota_final_per2, nf.id as nf_id_2, nf.updated_at as nf_updated_at2, nf.periodo, nf.periodo_id  from notas_finales nf order by nf.id desc
							)nf on nf.alumno_id=:al2 and p.numero=2 and p.id=nf.periodo_id and p.year_id=:year_id2 and p.deleted_at is null
						)r2 on r1.asignatura_id=r2.asignatura_id
					left join 
						(SELECT nf.nota_final_per3, nf.nf_id_3, nf.nf_updated_at3, nf.asignatura_id FROM periodos p 
						inner join (
								select distinct nf.asignatura_id, nf.alumno_id, nf.nota as nota_final_per3, nf.id as nf_id_3, nf.updated_at as nf_updated_at3, nf.periodo, nf.periodo_id  from notas_finales nf order by nf.id desc
							)nf on nf.alumno_id=:al3 and p.numero=3 and p.id=nf.periodo_id and p.year_id=:year_id3 and p.deleted_at is null
						)r3 on r2.asignatura_id=r3.asignatura_id
					left join 
						(SELECT nf.nota_final_per4, nf.nf_id_4, nf.nf_updated_at4, nf.asignatura_id, e.desempenio FROM periodos p 
						inner join (
								select distinct nf.asignatura_id, nf.alumno_id, nf.nota as nota_final_per4, nf.id as nf_id_4, nf.updated_at as nf_updated_at4, nf.periodo, nf.periodo_id  from notas_finales nf order by nf.id desc
							)nf on nf.alumno_id=:al4 and p.numero=4 and p.id=nf.periodo_id and p.year_id=:year_id4 and p.deleted_at is null
						left join escalas_de_valoracion e ON e.porc_inicial<=nf.nota_final_per4 and e.porc_final>=nf.nota_final_per4 and e.deleted_at is null and e.year_id=:year_id5
						)r4 on r3.asignatura_id=r4.asignatura_id
					order by r1.orden_area, r1.orden_materia, r1.orden_asignatura';

			$asignaturas = DB::select($consulta, [ ':al1' => $alumno_id, ':year_id1' => $year_id, ':grupo_id' => $grupo_id, 
									':al2' => $alumno_id, ':year_id2' => $year_id, ':al3' => $alumno_id, ':year_id3' => $year_id, ':al4' => $alumno_id, ':year_id4' => $year_id , ':year_id5' => $year_id ]);

		}
		
		return $asignaturas;
	}

	
	
	public function materias()
	{
		return $this->belongsToMany('Materia', 'asignaturas');
	}

	public function asignaturas()
	{
		return $this->hasMany('Asignatura');
	}

	public static function datos($grupo_id)
	{
		$consulta = 'SELECT g.id as grupo_id, g.titular_id, g.nombre as nombre_grupo, g.abrev as abrev_grupo,
						g.caritas, g.grado_id, g.orden, 
						p.nombres as nombres_profesor, p.apellidos as apellidos_profesor,
						p.foto_id, IFNULL(i.nombre, IF(p.sexo="F","default_female.png", "default_male.png")) as foto_nombre,
						p.firma_id, i2.nombre as firma_titular_nombre
					FROM grupos g 
					left join grados gr on gr.id=g.grado_id and gr.deleted_at is null
					left join profesores p on p.id=g.titular_id and p.deleted_at is null
					left join images i on p.foto_id=i.id and i.deleted_at is null
					left join images i2 on p.firma_id=i2.id and i.deleted_at is null
					where g.id=:grupo_id and g.deleted_at is null';

		$datos = DB::select($consulta, [':grupo_id' => $grupo_id])[0];

		return $datos;
	}
}


