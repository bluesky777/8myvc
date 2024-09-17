<?php namespace App\Http\Controllers;


use Request;
use DB;

use App\User;
use App\Models\ChangeAsked;
use App\Models\ChangeAskedDetails;
use App\Models\Alumno;
use App\Models\Profesor;
use App\Models\Acudiente;
use App\Models\Year;
use App\Models\Debugging;
use App\Models\ImageModel;
use App\Models\Ausencia;
use App\Models\NotaComportamiento;
use App\Models\Disciplina;
use App\Models\DefinicionComportamiento;

use App\Http\Controllers\Alumnos\Solicitudes;
use App\Http\Controllers\Perfiles\Publicaciones;

use Carbon\Carbon;
use \DateTime;
use \Log;


class ChangeAskedController extends Controller {


	public function getToMe()
	{
		$user = User::fromToken();
		
		
		// toca quitar los campos somebody, ya que esta consulta solo será para buscar los pedidos que han hecho alumnos.
		if ($user->tipo == 'Usuario' && $user->is_superuser) {

			$consulta = 'SELECT m.id as matricula_id, m.alumno_id, a.no_matricula, a.nombres, a.apellidos, a.sexo, a.user_id, u.username, 
							a.fecha_nac, a.ciudad_nac, a.celular, a.direccion, a.religion,
							m.grupo_id, m.estado, g.nombre as grupo_nombre, g.abrev as grupo_abrev,
							u.imagen_id, IFNULL(i.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as imagen_nombre, 
							a.foto_id, IFNULL(i2.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as foto_nombre,
							c.id as asked_id, c.asked_by_user_id, c.asked_to_user_id
						FROM alumnos a 
						inner join matriculas m on a.id=m.alumno_id and m.deleted_at is null
						inner join grupos g on g.id=m.grupo_id and g.year_id=? and g.deleted_at is null
						inner join users u on a.user_id=u.id and u.deleted_at is null
						inner join change_asked c on c.asked_by_user_id=u.id and c.deleted_at is null
						left join images i on i.id=u.imagen_id and i.deleted_at is null
						left join images i2 on i2.id=a.foto_id and i2.deleted_at is null
						where c.answered_by is null and a.deleted_at is null and m.deleted_at is null
						order by a.apellidos, a.nombres';

			$cambios_alum = DB::select($consulta, [$user->year_id]);

			# Solicitudes de asignaturas de Profesores
			$solicitudes 	= new Solicitudes();
			$pedidos 		= $solicitudes->todas_solicitudes_de_profesores($user->year_id);
			
			
			# Historial de sesiones
			$historial = DB::select('SELECT h.*, count(b.id) as cant_cambios FROM historiales h  
								left join bitacoras b  on b.historial_id=h.id 
								where h.user_id=?
								group by h.id
								order by h.created_at desc 
								limit 50', [ $user->user_id ]);

			
			# Intentos de Logueo Fallidos
			$intentos_fallidos = DB::select('SELECT * FROM bitacoras 
							WHERE affected_element_type="intento_login" and affected_person_name=? and deleted_at is null 
							order by created_at desc limit 50', 
							[ $user->username ]);

			
			# Datos de los docentes de este año
			$profes_actuales = $this->datos_de_docentes_este_anio($user);

			
			# Mis publicaciones
			$mis_publicaciones = DB::select('SELECT * FROM publicaciones 
				WHERE persona_id=? order by updated_at desc limit 10', 
				[ $user->persona_id ]);


			
			# Las publicaciones
			$publicaciones = Publicaciones::ultimas_publicaciones('Usuario');

			# Calendario
			$eventos = DB::select('SELECT * FROM calendario WHERE deleted_at is null');

			
			
			# Asignaturas hoy
			$horario_hoy = [];
			$horario_manana = [];

			if ($user->profesor_id) {
				$now = Carbon::now('America/Bogota');
				$dia = $now->dayOfWeek;
				$horario_hoy 	= $this->asignaturas_dia($user->year_id, $user->profesor_id, $user->periodo_id, $dia, $user->show_materias_todas);
				$horario_manana = $this->asignaturas_dia($user->year_id, $user->profesor_id, $user->periodo_id, $dia+1);
			}
			
			
			
			return [ 'alumnos'=>$cambios_alum, 'profesores'=> $pedidos, 'historial'=> $historial, 'intentos_fallidos'=> 
				$intentos_fallidos, 'profes_actuales' => $profes_actuales, 'mis_publicaciones' => $mis_publicaciones,
				'publicaciones' => $publicaciones, 'eventos' => $eventos, 'horario_hoy' => $horario_hoy, 'horario_manana' => $horario_manana ];

			
		}elseif ($user->tipo == 'Profesor') {

			$consulta = 'SELECT m.id as matricula_id, m.alumno_id, a.no_matricula, a.nombres, a.apellidos, a.sexo, a.user_id, u.username, 
							a.fecha_nac, a.ciudad_nac, a.celular, a.direccion, a.religion,
							m.grupo_id, m.estado, g.nombre as grupo_nombre, g.abrev as grupo_abrev,
							u.imagen_id, IFNULL(i.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as imagen_nombre, 
							a.foto_id, IFNULL(i2.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as foto_nombre,
							c.id as asked_id, c.asked_by_user_id, c.asked_to_user_id
						FROM alumnos a 
						inner join matriculas m on a.id=m.alumno_id and m.deleted_at is null
						inner join grupos g on g.id=m.grupo_id and g.year_id=? and g.titular_id=? and g.deleted_at is null
						inner join users u on a.user_id=u.id and u.deleted_at is null
						inner join change_asked c on c.asked_by_user_id=u.id and c.deleted_at is null
						left join images i on i.id=u.imagen_id and i.deleted_at is null
						left join images i2 on i2.id=a.foto_id and i2.deleted_at is null
						where c.answered_by is null and a.deleted_at is null and m.deleted_at is null
						order by a.apellidos, a.nombres';

			$cambios_alum = DB::select($consulta, [$user->year_id, $user->persona_id]);
			
			
			# Historial de sesiones
			$historial = DB::select('SELECT h.*, count(b.id) as cant_cambios FROM historiales h  
								left join bitacoras b  on b.historial_id=h.id  
								where h.user_id=?
								group by h.id
								order by h.created_at desc 
								limit 50', [ $user->user_id ]);

			
			# Intentos de Logueo Fallidos
			$intentos_fallidos = DB::select('SELECT * FROM bitacoras 
							WHERE affected_element_type="intento_login" and affected_person_name=? and deleted_at is null 
							order by created_at desc limit 50', 
							[ $user->username ]);

			
							
			# Datos de los docentes de este año
			$profes_actuales = [];
			if (Request::input('anchoWindow') > 500) {
				$profes_actuales = $this->datos_de_docentes_este_anio($user, true);
			}
			
			
			
			
			
			# Asignaturas hoy -  
			$now = Carbon::now('America/Bogota');
			$dia = $now->dayOfWeek;
			$horario_hoy 	= $this->asignaturas_dia($user->year_id, $user->persona_id, $user->periodo_id, $dia, $user->show_materias_todas);
			$horario_manana = $this->asignaturas_dia($user->year_id, $user->persona_id, $user->periodo_id, $dia+1);

			
			
			# Mis publicaciones
			$mis_publicaciones = DB::select('SELECT * FROM publicaciones 
				WHERE persona_id=? order by updated_at desc limit 10', 
				[ $user->persona_id ]);

				
			# Las publicaciones
			$publicaciones = Publicaciones::ultimas_publicaciones('Profesor');

			
			# Calendario
			$eventos = DB::select('SELECT * FROM calendario WHERE deleted_at is null');

			
							
			return [ 'alumnos'=>$cambios_alum, 'profesores'=>[], 'historial'=> $historial, 'intentos_fallidos'=> $intentos_fallidos, 
				'profes_actuales' => $profes_actuales, 'mis_publicaciones' => $mis_publicaciones,
				'publicaciones' => $publicaciones, 'eventos' => $eventos, 'horario_hoy' => $horario_hoy, 'horario_manana' => $horario_manana ];
		
		
		}elseif ($user->tipo == 'Alumno') {
			$consulta 	= 'SELECT d.* FROM dis_libro_rojo d 
				WHERE alumno_id=? and d.year_id=? and d.deleted_at is null';

			$libro 	= DB::select($consulta, [ $user->persona_id, $user->year_id ]);

			if (count($libro) > 0) {
				$libro = $libro[0];
			}

			$ausencias 			= Ausencia::deAlumnoYear($user->persona_id, $user->year_id);
			
			# Datos de los docentes de este año
			$profes_actuales = $this->datos_de_docentes_este_anio($user, false);
			
			$comportamiento 	= NotaComportamiento::notas_comportamiento_year($user->persona_id, $user->year_id);

			// Uniformes
			$cons_uni = "SELECT u.id, u.asignatura_id, u.materia, u.alumno_id, u.periodo_id, u.contrario, u.sin_uniforme, u.incompleto, u.cabello, u.accesorios, u.otro1, u.camara, u.excusado, u.fecha_hora, u.uploaded, u.created_by, u.descripcion,
						p.numero 
					FROM uniformes u
					inner join periodos p on p.id=u.periodo_id and p.year_id=:year_id
					WHERE u.alumno_id=:alumno_id and u.deleted_at is null;";
			$uniformes = DB::select($cons_uni, [":year_id" => $user->year_id, ':alumno_id' => $user->persona_id ]);
			

			// Situaciones
			$situaciones 		= Disciplina::situaciones_year_alumno($user->persona_id, $user->year_id);

			
			# Las publicaciones
			$publicaciones = Publicaciones::ultimas_publicaciones('Alumno');

			
			# Calendario
			$eventos = DB::select('SELECT * FROM calendario WHERE solo_profes=0 and deleted_at is null');


			# PREMATRICULAS SIGUIENTE AÑO
			$alumnos = [];
			$grados_sig = [];

			if ($user->prematr_antiguos) {
				$prematricula = new \stdClass;
				$prematricula->estado = null;

				$consulta 		= 'SELECT distinct(a.id) as alumno_id, a.no_matricula, a.nombres, a.apellidos, a.sexo, a.user_id, m.estado, m.id as matricula_id,
						u.imagen_id, IFNULL(i.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as imagen_nombre, 
						a.foto_id, IFNULL(i2.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as foto_nombre,
						g.nombre as nombre_grupo, g.orden
					FROM alumnos a 
					left join users u on a.user_id=u.id and u.deleted_at is null
					left join images i on i.id=u.imagen_id and i.deleted_at is null
					left join images i2 on i2.id=a.foto_id and i2.deleted_at is null
					left join matriculas m on m.alumno_id=a.id and m.deleted_at is null
					left join grupos g on g.id=m.grupo_id and g.deleted_at is null
					inner join years y on y.id=g.year_id and y.deleted_at is null and y.year=?
					where a.id=? and a.deleted_at is null and g.nombre is not null
					order by g.orden, a.apellidos, a.nombres';
				

				$prematricula_f = DB::select($consulta, [$user->year+1, $user->persona_id]);
				
				if (count($prematricula_f) > 0) {
					$prematricula = clone $prematricula_f[0];
					$prematricula->prematricula = clone $prematricula_f[0];
				}else{

					$prematricula->nombres 			= $user->nombres;
					$prematricula->apellidos 		= $user->apellidos;
					$prematricula->imagen_nombre 	= $user->imagen_nombre;
					$prematricula->foto_nombre 		= $user->foto_nombre;
					$prematricula->nombre_grupo 	= $user->nombre_grupo;
					$prematricula->sexo 			= $user->sexo;
					$prematricula->alumno_id 		= $user->persona_id;
					
				}

				$consulta = 'SELECT m.id as matricula_id, m.alumno_id, a.no_matricula, a.nombres, a.apellidos, g.nombre as grupo_nombre, g.abrev as grupo_abrev, m.grupo_id, m.estado, m.nuevo, m.repitente, m.prematriculado, m.fecha_matricula, y.id as year_id, y.year as year,
						m.programar, m.descripcion_recomendacion, m.efectuar_una, m.descripcion_efectuada 
					FROM alumnos a 
					inner join matriculas m on a.id=m.alumno_id and a.id=:alumno_id 
					INNER JOIN grupos g ON g.id=m.grupo_id AND g.deleted_at is null
					INNER JOIN years y ON y.id=g.year_id AND y.deleted_at is null and y.year=:anio
					where a.deleted_at is null and m.deleted_at is null
					order by y.year, g.orden';

				$matri_next = DB::select($consulta, [ ':alumno_id' => $user->persona_id, ':anio'=> ($user->year+1) ] );
				
				$prematricula->next_year = [];
				if (count($matri_next) > 0) {
					$prematricula->next_year = $matri_next[0];
				}else{

					$consulta = 'SELECT y.id as year_id, y.year as year
						FROM years y where y.deleted_at is null and y.year=:anio';

					$matri_next = DB::select($consulta, [ ':anio'=> ($user->year+1) ] );

					if (count($matri_next) > 0) {
						$prematricula->next_year = $matri_next[0];
					}
				}

				array_push($alumnos, $prematricula);
				
				// Grupos próximo año
				$consulta = 'SELECT g.id, g.nombre, g.abrev, g.orden, g.grado_id, g.year_id, g.titular_id, g.created_at, g.updated_at
					from grupos g
					inner join years y on y.id=g.year_id and y.year=:anio and y.deleted_at is null
					where g.deleted_at is null order by g.orden';
				
				$grados_sig = DB::select($consulta, [':anio'=> ($user->year+1) ] );
					
			}

			return [
				'alumnos' => $alumnos, 'ausencias_periodo'=>$ausencias, 'situaciones'=> $situaciones,
				'comportamiento'=>$comportamiento, 'uniformes'=>$uniformes, 'profes_actuales' => $profes_actuales,
				'publicaciones' => $publicaciones, 'eventos' => $eventos, 'grados_sig' => $grados_sig,
				'libro' => $libro,
			];

			
		}elseif ($user->tipo == 'Acudiente') {
			
			$consulta 		= 'SELECT distinct(a.id) as alumno_id, a.no_matricula, a.nombres, a.apellidos, a.sexo, a.user_id, 
								a.fecha_nac, a.tipo_doc, a.documento, a.tipo_sangre, a.eps, a.telefono, a.celular, 
								a.direccion, a.barrio, a.estrato, a.religion, a.email, a.facebook, a.created_by, a.updated_by,
								a.pazysalvo, a.deuda, 
								u.username, u.is_superuser, u.is_active,
								u.imagen_id, IFNULL(i.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as imagen_nombre, 
								a.foto_id, IFNULL(i2.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as foto_nombre,
								p.parentesco, p.observaciones, g.nombre as nombre_grupo, g.orden
							FROM alumnos a 
							inner join parentescos p on p.alumno_id=a.id and p.acudiente_id=?
							left join users u on a.user_id=u.id and u.deleted_at is null
							left join images i on i.id=u.imagen_id and i.deleted_at is null
							left join images i2 on i2.id=a.foto_id and i2.deleted_at is null
							left join matriculas m on m.alumno_id=a.id and m.deleted_at is null and (m.estado="ASIS" or m.estado="MATR" or m.estado="PREM")
							left join grupos g on g.id=m.grupo_id and g.deleted_at is null and g.year_id=?
							where a.deleted_at is null and p.deleted_at is null  and g.nombre is not null
							order by g.orden, a.apellidos, a.nombres';
							
			$alumnos 	= DB::select($consulta, [ $user->persona_id, $user->year_id ]);	

			for ($i=0; $i < count($alumnos); $i++) { 
				$alumnos[$i]->comportamiento 		= NotaComportamiento::notas_comportamiento_year($alumnos[$i]->alumno_id, $user->year_id);
				$alumnos[$i]->situaciones 			= Disciplina::situaciones_year($alumnos[$i]->alumno_id, $user->year_id, $user->periodo_id);
				$alumnos[$i]->ausencias_periodo 	= Ausencia::deAlumnoYear($alumnos[$i]->alumno_id, $user->year_id);
				
				$consulta 	= 'SELECT d.* FROM dis_libro_rojo d 
					WHERE alumno_id=? and d.year_id=? and d.deleted_at is null';

				$libro 	= DB::select($consulta, [ $alumnos[$i]->alumno_id, $user->year_id ]);

				if (count($libro) > 0) {
					$libro = $libro[0];
				}
				$alumnos[$i]->libro 	= $libro;
				

				// Uniformes
				$cons_uni = "SELECT u.id, u.asignatura_id, u.materia, u.alumno_id, u.periodo_id, u.contrario, u.sin_uniforme, u.incompleto, u.cabello, u.accesorios, u.otro1, u.camara, u.excusado, u.fecha_hora, u.uploaded, u.created_by, u.descripcion,
							p.numero 
						FROM uniformes u
						inner join periodos p on p.id=u.periodo_id and p.year_id=:year_id
						WHERE u.alumno_id=:alumno_id and u.deleted_at is null;";
				$uniformes = DB::select($cons_uni, [":year_id" => $user->year_id, ':alumno_id' => $alumnos[$i]->alumno_id ]);
				$alumnos[$i]->uniformes 	= $uniformes;


				# PREMATRICULAS SIGUIENTE AÑO
				if ($user->prematr_antiguos) {
					$alumnos[$i]->prematricula = ['estado' => null];

					$consulta 		= 'SELECT distinct(a.id) as alumno_id, a.no_matricula, a.nombres, a.apellidos, a.sexo, a.user_id, m.estado, m.id as matricula_id,
							u.imagen_id, IFNULL(i.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as imagen_nombre, 
							a.foto_id, IFNULL(i2.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as foto_nombre,
							g.nombre as nombre_grupo, g.orden
						FROM alumnos a 
						left join users u on a.user_id=u.id and u.deleted_at is null
						left join images i on i.id=u.imagen_id and i.deleted_at is null
						left join images i2 on i2.id=a.foto_id and i2.deleted_at is null
						left join matriculas m on m.alumno_id=a.id and m.deleted_at is null
						left join grupos g on g.id=m.grupo_id and g.deleted_at is null
						inner join years y on y.id=g.year_id and y.deleted_at is null and y.year=?
						where a.id=? and a.deleted_at is null and g.nombre is not null
						order by g.orden, a.apellidos, a.nombres';
					

					$prematricula = DB::select($consulta, [$user->year+1, $alumnos[$i]->alumno_id]);
					
					if (count($prematricula) > 0) {
						$alumnos[$i]->prematricula = $prematricula[0];
					}
					
					$consulta = 'SELECT m.id as matricula_id, m.alumno_id, a.no_matricula, a.nombres, a.apellidos, g.nombre as grupo_nombre, g.abrev as grupo_abrev, m.grupo_id, m.estado, m.nuevo, m.repitente, m.prematriculado, m.fecha_matricula, y.id as year_id, y.year as year,
							m.programar, m.descripcion_recomendacion, m.efectuar_una, m.descripcion_efectuada 
						FROM alumnos a 
						inner join matriculas m on a.id=m.alumno_id and a.id=:alumno_id 
						INNER JOIN grupos g ON g.id=m.grupo_id AND g.deleted_at is null
						INNER JOIN years y ON y.id=g.year_id AND y.deleted_at is null and y.year=:anio
						where a.deleted_at is null and m.deleted_at is null
						order by y.year, g.orden';

					$matri_next = DB::select($consulta, [ ':alumno_id' => $alumnos[$i]->alumno_id, ':anio'=> ($user->year+1) ] );
					
					$alumnos[$i]->next_year = [];
					if (count($matri_next) > 0) {
						$alumnos[$i]->next_year = $matri_next[0];
					}else{

						$consulta = 'SELECT y.id as year_id, y.year as year
							FROM years y where y.deleted_at is null and y.year=:anio';

						$matri_next = DB::select($consulta, [ ':anio'=> ($user->year+1) ] );

						if (count($matri_next) > 0) {
							$alumnos[$i]->next_year = $matri_next[0];
						}
					}
				}
			}

			$grados_sig = [];
			
			if ($user->prematr_antiguos) {

				// Grupos próximo año
				$consulta = 'SELECT g.id, g.nombre, g.abrev, g.orden, g.grado_id, g.year_id, g.titular_id, g.created_at, g.updated_at
					from grupos g
					inner join years y on y.id=g.year_id and y.year=:anio and y.deleted_at is null
					where g.deleted_at is null order by g.orden';
				
				$grados_sig = DB::select($consulta, [':anio'=> ($user->year+1) ] );
				
			}

			# Las publicaciones
			$publicaciones 		= Publicaciones::ultimas_publicaciones('Acudiente');
			
			# Calendario
			$eventos = DB::select('SELECT * FROM calendario WHERE solo_profes=0 and deleted_at is null');

			
			
			return [ 'alumnos' => $alumnos, 
				'publicaciones' => $publicaciones, 'eventos' => $eventos, 'grados_sig' => $grados_sig ];
		
		}elseif ($user->tipo == 'Usuario') {
			

			# Las publicaciones
			$publicaciones = Publicaciones::ultimas_publicaciones('Acudiente');

			# Calendario
			$eventos = DB::select('SELECT * FROM calendario WHERE solo_profes=0 and deleted_at is null');

			
			return [ 'publicaciones' => $publicaciones, 'eventos' => $eventos ];
		}
		
		return ['msg'=> 'No puedes ver pedidos'];
	}


	
	
	
	private function datos_de_docentes_este_anio($user, $con_historial=true){
		
		# Datos de los docentes de este año
		if ($con_historial) {
			$profes_actuales = DB::select('SELECT p.id as profesor_id, p.nombres, p.apellidos, p.sexo, p.foto_id, p.user_id, r.fecha_ingreso, h.id, h.entorno, h.device_family, h.platform_family, h.browser_family, h.ip, 
										u.username, p.telefono, p.celular, p.email, p.fecha_nac, r2.cant_asignaturas,
										u.imagen_id, IFNULL(i.nombre, IF(p.sexo="F","default_female.png", "default_male.png")) as imagen_nombre, 
										p.foto_id, IFNULL(i2.nombre, IF(p.sexo="F","default_female.png", "default_male.png")) as foto_nombre
									FROM contratos c
									inner join profesores p on p.id=c.profesor_id and p.deleted_at is null and c.deleted_at is null
									left join (
										select max(h.created_at) fecha_ingreso, h.user_id from historiales h WHERE h.deleted_at is null group by h.user_id
									)r ON r.user_id=p.user_id
									left join historiales h on h.user_id=r.user_id and r.fecha_ingreso=h.created_at and h.deleted_at is null
									left join users u on p.user_id=u.id and u.deleted_at is null
									left join images i on i.id=u.imagen_id and i.deleted_at is null
									left join images i2 on i2.id=p.foto_id and i2.deleted_at is null
									left join (
										SELECT COUNT(*) as cant_asignaturas, a.profesor_id FROM asignaturas a 
										INNER JOIN grupos g ON g.id=a.grupo_id and g.deleted_at is null and g.year_id=?
										WHERE a.deleted_at is null 
										group by a.profesor_id
									)r2 on r2.profesor_id=p.id
									where  c.year_id=? order by p.nombres, p.apellidos', 
									[ $user->year_id, $user->year_id ]);
		
		}else{
			$profes_actuales = DB::select('SELECT p.id as profesor_id, p.nombres, p.apellidos, p.sexo, p.foto_id, r2.cant_asignaturas,
											p.foto_id, IFNULL(i2.nombre, IF(p.sexo="F","default_female.png", "default_male.png")) as foto_nombre
										FROM contratos c
										inner join profesores p on p.id=c.profesor_id and p.deleted_at is null and c.deleted_at is null
										left join images i2 on i2.id=p.foto_id and i2.deleted_at is null
										left join (
											SELECT COUNT(*) as cant_asignaturas, a.profesor_id FROM asignaturas a 
											INNER JOIN grupos g ON g.id=a.grupo_id and g.deleted_at is null and g.year_id=?
											WHERE a.deleted_at is null 
											group by a.profesor_id
										)r2 on r2.profesor_id=p.id
										where  c.year_id=?', 
									[ $user->year_id, $user->year_id ]);
		
		}
		
		$cant = count($profes_actuales);			
		
		for ($i=0; $i < $cant; $i++) { 
			
			if ($profes_actuales[$i]->cant_asignaturas) {
				$porcentaje = DB::select('SELECT sum(if( r2.porc_uni=100, 1, 0)) uni_correctas, SUM(r2.sub_correctas) sub_correctas
										FROM (
											SELECT  sum(u.porcentaje) porc_uni, u.asignatura_id, IF(count(r.porc_unidad)>0, 0, 1) sub_correctas, a.profesor_id
											FROM unidades u
											inner join asignaturas a ON a.id=u.asignatura_id and a.deleted_at is null
											inner join grupos g ON g.id=a.grupo_id and g.deleted_at is null and g.year_id=?
											left join (
												SELECT sum(s.porcentaje) as porc_unidad, s.unidad_id
												FROM subunidades s
												inner join unidades u ON u.id=s.unidad_id and s.deleted_at is null and u.deleted_at is null
												group by s.unidad_id having sum(s.porcentaje) < 100 or sum(s.porcentaje) > 100 
											)r on r.unidad_id=u.id
											where u.periodo_id=? and u.deleted_at is null and a.profesor_id=?
											group by u.asignatura_id
										)r2', 
									[ $user->year_id, $user->periodo_id, $profes_actuales[$i]->profesor_id ]);
								
				$avance_comport = DB::select('SELECT COUNT(*) as cant_grupos_comport, sum(r2.con_notas) as cant_grupos_con_notas
											from (SELECT g.id as grupo_id, g.nombre, r.con_notas 
																from grupos g
																inner join grados gra on gra.id=g.grado_id and g.year_id=?
																inner join profesores p on p.id=g.titular_id and g.titular_id = ?
																left join (
																	select IF(count(n.id)>0, 1, 0) as con_notas, g.id as grupo_id 
																	from nota_comportamiento n
																	inner join matriculas m ON m.alumno_id=n.alumno_id and (m.estado="MATR" or m.estado="ASIS") and m.deleted_at is null
																	inner join grupos g ON g.id=m.grupo_id and g.deleted_at is null and titular_id=? and g.year_id=?
																	where n.periodo_id=?
																	group by g.id
																)r on r.grupo_id=g.id
																where g.deleted_at is null
											)r2', [ $user->year_id, $profes_actuales[$i]->profesor_id, $profes_actuales[$i]->profesor_id, $user->year_id, $user->periodo_id ]);					
									
				if (count($porcentaje) > 0) {
					
					$profes_actuales[$i]->porcentaje = ($porcentaje[0]->uni_correctas*100 / $profes_actuales[$i]->cant_asignaturas)/2 + ($porcentaje[0]->sub_correctas*100 / $profes_actuales[$i]->cant_asignaturas)/2;
					
					if (count($avance_comport) > 0) {
						$avance_comport = $avance_comport[0];
						$diffe = ($avance_comport->cant_grupos_comport - $avance_comport->cant_grupos_con_notas) * 5;
						$profes_actuales[$i]->porcentaje = $profes_actuales[$i]->porcentaje - $diffe;
						
						if ($profes_actuales[$i]->porcentaje < 0) {
							$profes_actuales[$i]->porcentaje = 0;
						}
					}
					
				}else{
					$profes_actuales[$i]->porcentaje = 0;
				}
				
			}else{
				$profes_actuales[$i]->porcentaje = 100;
			}
			
			
		}
		return $profes_actuales;
	}


	
	public function putVerDetalles(){
		$user 		= User::fromToken();
		$asked_id 	= Request::input('asked_id');
		$detalles 	= ChangeAskedDetails::detalles($asked_id);
		return [ 'detalles' => $detalles ];
	}



	public function putAceptarAlumno()
	{
		$user 			= User::fromToken();

		$asked_id 		= Request::input('asked_id');
		$tipo 			= Request::input('tipo');
		$data_id 		= Request::input('data_id');
		$valor_nuevo 	= Request::input('valor_nuevo');

		$pedido 		= ChangeAsked::pedido($asked_id);

		if ($tipo == "img_perfil") {
			$this->cambiarImgAlumno($pedido);
			$consulta = 'UPDATE change_asked_data SET image_id_accepted=true WHERE id=:data_id';
			DB::select($consulta, [ ':data_id' => $data_id ]);
			$pedido->image_id_accepted 	= true;
		}

		if ($tipo == "foto_oficial") {
			if ($pedido->tipo_user == 'Profesor') {
				$this->cambiarOficialProfesor($pedido);
			} else if ($pedido->tipo_user == 'Alumno') {
				$this->cambiarOficialAlumno($pedido);
			}
			$consulta = 'UPDATE change_asked_data SET foto_id_accepted=true WHERE id=:data_id';
			DB::select($consulta, [ ':data_id' => $data_id ]);
			$pedido->foto_id_accepted 	= true;			
		}
		
		if ($tipo == "img_delete") {
			ImageModel::eliminar_imagen_y_enlaces($pedido->image_to_delete_id);
			$consulta = 'UPDATE change_asked_data SET image_to_delete_accepted=true WHERE id=:data_id';
			DB::select($consulta, [ ':data_id' => $data_id ]);
			$pedido->image_to_delete_accepted 	= true;
		}
		
		if ($tipo == "nombres") {
			$consulta = 'UPDATE alumnos SET nombres=:nombres WHERE id=:id';
			DB::select($consulta, [ ':nombres' => $valor_nuevo, ':id' => Request::input('alumno_id') ]);
			$consulta = 'UPDATE change_asked_data SET nombres_accepted=true WHERE id=:data_id';
			DB::select($consulta, [ ':data_id' => $data_id ]);
			$pedido->nombres_accepted 	= true;
		}
		if ($tipo == "apellidos") {
			$consulta = 'UPDATE alumnos SET apellidos=:apellidos WHERE id=:id';
			DB::select($consulta, [ ':apellidos' => $valor_nuevo, ':id' => Request::input('alumno_id') ]);
			$consulta = 'UPDATE change_asked_data SET apellidos_accepted=true WHERE id=:data_id';
			DB::select($consulta, [ ':data_id' => $data_id ]);
			$pedido->apellidos_accepted 	= true;
		}
		if ($tipo == "sexo") {
			$consulta = 'UPDATE alumnos SET sexo=:sexo WHERE id=:id';
			DB::select($consulta, [ ':sexo' => $valor_nuevo, ':id' => Request::input('alumno_id') ]);
			$consulta = 'UPDATE change_asked_data SET sexo_accepted=true WHERE id=:data_id';
			DB::select($consulta, [ ':data_id' => $data_id ]);
			$pedido->sexo_accepted 	= true;
		}
		if ($tipo == "fecha_nac") {
			$consulta = 'UPDATE alumnos SET fecha_nac=:fecha_nac WHERE id=:id';
			DB::select($consulta, [ ':fecha_nac' => $valor_nuevo, ':id' => Request::input('alumno_id') ]);
			$consulta = 'UPDATE change_asked_data SET fecha_nac_accepted=true WHERE id=:data_id';
			DB::select($consulta, [ ':data_id' => $data_id ]);
			$pedido->fecha_nac_accepted 	= true;
		}
		
		$finalizado 	= $this->finalizar_si_no_hay_cambios($pedido, $user->user_id);

		return [ 'finalizado'=> $finalizado, 'msg'=>'Cambio aceptado con éxito'];
	}


	public function putAceptarAsignatura()
	{
		$user 			= User::fromToken();
		$pedido 		= Request::input('pedido');
		$now 			= Carbon::now('America/Bogota');

		if ($pedido['materia_to_add_id'] > 0) {
			
			if ($pedido['asignatura_actual']['ocupada']) {
				
				$consulta = 'UPDATE asignaturas SET profesor_id=:profesor_id, creditos=:creditos, updated_by=:updated_by, updated_at=:updated_at
								WHERE id=:id';
				DB::update($consulta, [
						':profesor_id' 	=> $pedido['profesor_id'], 
						':creditos' 	=> $pedido['creditos_new'], 
						':updated_by'	=> $user->user_id, 
						':updated_at' 	=> $now, 
						':id' 			=> $pedido['asignatura_actual']['asignatura_id']
				]);

			}else{

				$consulta = 'INSERT INTO asignaturas(materia_id, grupo_id, profesor_id, creditos, orden, created_by, created_at) 
										VALUES(:materia_id, :grupo_id, :profesor_id, :creditos, 1, :created_by, :created_at)';
				DB::insert($consulta, [
						':materia_id' 	=> $pedido['materia_to_add_id'], 
						':grupo_id' 	=> $pedido['grupo_to_add_id'], 
						':profesor_id' 	=> $pedido['profesor_id'], 
						':creditos' 	=> $pedido['creditos_new'], 
						':created_by'	=> $user->user_id, 
						':created_at' 	=> $now
				]);
			}

			$consulta = 'UPDATE change_asked_assignment SET asignatura_to_remove_accepted=true, materia_to_add_accepted=true, creditos_accepted=true, updated_at=:updated_at 
						WHERE id=:assignment_id';

			DB::update($consulta, [ ':updated_at' => $now, ':assignment_id' => $pedido['assignment_id'] ]);

		} else if($pedido['asignatura_to_remove_id'] > 0) {
			
			$consulta = 'UPDATE asignaturas SET deleted_at=:deleted_at, deleted_by=:deleted_by WHERE id=:asignatura_id';
			DB::update($consulta, [
					':deleted_at' 		=> $now, 
					':deleted_by' 		=> $pedido['asked_by_user_id'], 
					':asignatura_id' 	=> $pedido['asignatura_to_remove_id'], 
			]);
			$consulta = 'UPDATE change_asked_assignment SET asignatura_to_remove_accepted=true, materia_to_add_accepted=true, creditos_accepted=true, updated_at=:updated_at 
						WHERE id=:assignment_id';

			DB::update($consulta, [ ':updated_at' => $now, ':assignment_id' => $pedido['assignment_id'] ]);


		}

		$consulta = 'UPDATE change_asked SET accepted_at=:accepted_at, answered_by=:answered_by	WHERE id=:asked_id';
		DB::update($consulta, [ ':accepted_at' => $now, ':answered_by' => $user->user_id, ':asked_id' => $pedido['asked_id'] ]);


		$pedido['asignatura_to_remove_accepted'] 	= true;
		$pedido['materia_to_add_accepted'] 			= true;
		$pedido['creditos_accepted'] 				= true;
		
		return [ 'finalizado'=> true, 'msg'=>'Cambio aceptado con éxito'];
	}



	public function putRechazar()
	{
		$user 		= User::fromToken();
		$now 		= Carbon::now('America/Bogota');

		$asked_id 	= Request::input('asked_id');
		$tipo 		= Request::input('tipo');
		$data_id 	= Request::input('data_id');


		$pedido 	= ChangeAsked::pedido($asked_id);

		if ($tipo == "img_perfil") {
			$consulta = 'UPDATE change_asked_data SET image_id_accepted=false, updated_at=:updated_at WHERE id=:data_id';
			DB::update($consulta, [ ':updated_at' => $now, ':data_id' => $data_id ]);
			$pedido->image_id_accepted 	= false;
		}

		if ($tipo == "foto_oficial") {
			$consulta = 'UPDATE change_asked_data SET foto_id_accepted=false, updated_at=:updated_at WHERE id=:data_id';
			DB::update($consulta, [ ':updated_at' => $now, ':data_id' => $data_id ]);
			$pedido->foto_id_accepted 	= false;
		}

		if ($tipo == "img_delete") {
			$consulta = 'UPDATE change_asked_data SET image_to_delete_accepted=false, updated_at=:updated_at WHERE id=:data_id';
			DB::update($consulta, [ ':updated_at' => $now, ':data_id' => $data_id ]);
			$pedido->image_to_delete_accepted 	= false;
		}

		if ($tipo == "nombres") {
			$consulta = 'UPDATE change_asked_data SET nombres_accepted=false, updated_at=:updated_at WHERE id=:data_id';
			DB::update($consulta, [ ':updated_at' => $now, ':data_id' => $data_id ]);
			$pedido->sexo_accepted 	= false;
		}
		if ($tipo == "apellidos") {
			$consulta = 'UPDATE change_asked_data SET apellidos_accepted=false, updated_at=:updated_at WHERE id=:data_id';
			DB::update($consulta, [ ':updated_at' => $now, ':data_id' => $data_id ]);
			$pedido->sexo_accepted 	= false;
		}
		if ($tipo == "sexo") {
			$consulta = 'UPDATE change_asked_data SET sexo_accepted=false, updated_at=:updated_at WHERE id=:data_id';
			DB::update($consulta, [ ':updated_at' => $now, ':data_id' => $data_id ]);
			$pedido->sexo_accepted 	= false;
		}
		if ($tipo == "fecha_nac") {
			$consulta = 'UPDATE change_asked_data SET fecha_nac_accepted=false, updated_at=:updated_at WHERE id=:data_id';
			DB::update($consulta, [ ':updated_at' => $now, ':data_id' => $data_id ]);
			$pedido->fecha_nac_accepted 	= false;
		}

		if ($tipo == "asignatura") {
			
			$assignment_id 	= Request::input('assignment_id');
			
			$consulta = 'UPDATE change_asked_assignment SET asignatura_to_remove_accepted=false, materia_to_add_accepted=false, creditos_accepted=false, updated_at=:updated_at 
						WHERE id=:assignment_id';
			DB::update($consulta, [ ':updated_at' => $now, ':assignment_id' => $assignment_id ]);
			$consulta = 'UPDATE change_asked SET answered_by=:user_id, deleted_by=:user_id2, deleted_at=:dt WHERE id=:asked_id';
			DB::update($consulta, [ ':user_id' => $user->user_id, ':user_id2' => $user->user_id, ':dt' => $now, ':asked_id' => $asked_id ]);
			return [ 'finalizado'=> true, 'msg'=>'Cambio rechazado con éxito'];
		}

		$finalizado = $this->finalizar_si_no_hay_cambios($pedido, $user->user_id);

		return [ 'finalizado'=> $finalizado, 'msg'=>'Cambio rechazado con éxito'];
	}


	public function cambiarOficialAlumno($pedido)
	{
		$alumno = Alumno::where('user_id', $pedido->asked_by_user_id)->first();
		$alumno->foto_id = $pedido->foto_id_new;
		$alumno->save();
		return $alumno;
	}

	public function cambiarOficialProfesor($pedido)
	{
		$prof = Profesor::where('user_id', $pedido->asked_by_user_id)->first();
		$prof->foto_id = $pedido->foto_id_new;
		$prof->save();
		return $prof;
	}


	public function cambiarImgAlumno($pedido)
	{
		$usu = User::findOrFail($pedido->asked_by_user_id);
		$usu->imagen_id = $pedido->image_id_new;
		$usu->save();
		return $usu;
		
	}

	public function finalizar_si_no_hay_cambios($pedido, $user_id)
	{
		Debugging::pin('Pedido');
		if ( ($pedido->pazysalvo_new===null 	or $pedido->pazysalvo_accepted!==null) and
			($pedido->foto_id_new===null 		or $pedido->foto_id_accepted!==null) and
			($pedido->image_id_new===null 		or $pedido->image_id_accepted!==null) and
			($pedido->firma_id_new===null 		or $pedido->firma_id_accepted!==null) and
			($pedido->image_to_delete_id===null or $pedido->image_to_delete_accepted!==null) and
			($pedido->nombres_new===null 		or $pedido->nombres_accepted!==null) and
			($pedido->apellidos_new===null 		or $pedido->apellidos_accepted!==null) and
			($pedido->sexo_new===null 			or $pedido->sexo_accepted!==null) and
			($pedido->fecha_nac_new===null 		or $pedido->fecha_nac_accepted!==null)
			) 
		{
			Debugging::pin('Pedido', 'ENTROOOOO');
			$dt = Carbon::now('America/Bogota')->format('Y-m-d G:H:i');
			$consulta = 'UPDATE change_asked SET answered_by=:user_id, deleted_by=:user_id2, deleted_at=:dt WHERE id=:asked_id';
			DB::update($consulta, [ ':user_id' => $user_id, ':user_id2' => $user_id, ':dt' => $dt, ':asked_id' => $pedido->asked_id ]);
			return true;
		}

		return false;
		
	}




	public function putSolicitarCambios()
	{
		$user = User::fromToken();

		$tipo 	= Request::input('tipo');
		$id 	= Request::input('persona_id');
		
		if ($tipo == 'Al') {
			$alumno = Alumno::where('id', $id)->first();


			$cambios = [];

			if (($alumno->nombres != Request::input('nombres')) && Request::input('nombres')) {
				$cambios['nombres'] = Request::input('nombres');
			}

			if (($alumno->apellidos != Request::input('apellidos')) && Request::input('apellidos')) {
				$cambios['apellidos'] = Request::input('apellidos');
			}

			if (($alumno->sexo != Request::input('sexo')) && Request::input('sexo')) {
				$cambios['sexo'] = Request::input('sexo');
			}

			if (($alumno->fecha_nac != Request::input('fecha_nac')) && Request::input('fecha_nac')) {
				//$fecha_nac_new = $date = Carbon::createFromFormat('Y-m-d', Request::input('fecha_nac'));
				$fecha_nac_new = Carbon::parse(Request::input('fecha_nac'));
				$fecha_nac_old = $alumno->fecha_nac;
				
				if ($alumno->fecha_nac) {
					$fecha_nac_old = $alumno->fecha_nac->format('Y-m-d');
				}
				

				if ($fecha_nac_new != $fecha_nac_old) {
					$cambios['fecha_nac'] 		= $fecha_nac_new;
					$cambios['fecha_nac_old'] 	= $fecha_nac_old;
				}

			}

			if (Request::has('ciudad_nac')) {
				$ciudad_id = null;

				if (Request::input('ciudad_nac')['id']) {
					$ciudad_id = Request::input('ciudad_nac')['id'];
				}else{
					$ciudad_id = Request::input('ciudad_nac');
				}
				if (($alumno->ciudad_nac != $ciudad_id) && $ciudad_id) {
					$cambios['ciudad_nac'] = $ciudad_id;
				}
			}
			
			if (count($cambios) > 0) {
				$this->crear_o_modificar_datos_de_pedido($user, $cambios);
			}
			

			return count($cambios) . '';

		}


	}
	
	
	private $creado = false;
	public function crear_o_modificar_datos_de_pedido($user, $cambios){
		$pedido = ChangeAsked::verificar_pedido_actual($user->user_id, $user->year_id, $user->tipo);

		if ($pedido->data_id) {
			Debugging::pin('Tiene data_id');
			if (array_key_exists('nombres', $cambios)) {
				$consulta = 'UPDATE change_asked_data SET nombres_new=:nombres WHERE id=:data_id';
				DB::update($consulta, [ ':nombres'	=> $cambios['nombres'], ':data_id'	=> $pedido->data_id ]);
				Debugging::pin('UPDATE nombres');
			}
			if (array_key_exists('apellidos', $cambios)) {
				$consulta = 'UPDATE change_asked_data SET apellidos_new=:apellidos WHERE id=:data_id';
				DB::update($consulta, [ ':apellidos'	=> $cambios['apellidos'], ':data_id'	=> $pedido->data_id ]);
			}
			if (array_key_exists('sexo', $cambios)) {
				$consulta = 'UPDATE change_asked_data SET sexo_new=:sexo WHERE id=:data_id';
				DB::update($consulta, [ ':sexo'	=> $cambios['sexo'], ':data_id'	=> $pedido->data_id ]);
			}
			if (array_key_exists('fecha_nac', $cambios)) {
				$consulta = 'UPDATE change_asked_data SET fecha_nac_new=:fecha_nac WHERE id=:data_id';
				DB::update($consulta, [ ':fecha_nac'	=> $cambios['fecha_nac'], ':data_id'	=> $pedido->data_id ]);
			}

		}else{
			//Debugging::pin(' NO  Tiene data_id');
			
			if (!$this->creado) {
				if (array_key_exists('nombres', $cambios)) {
					$consulta 	= 'INSERT INTO change_asked_data(nombres_new) VALUES(:nombres)';
					DB::insert($consulta, [ ':nombres'	=> $cambios['nombres'] ]);
					$this->cambiar_data_id($pedido);
					$this->creado = true;
					$this->crear_o_modificar_datos_de_pedido($user, $cambios);
				}
				if (array_key_exists('apellidos', $cambios)) {
					$consulta 	= 'INSERT INTO change_asked_data(apellidos_new) VALUES(:apellidos)';
					DB::insert($consulta, [ ':apellidos'	=> $cambios['apellidos'] ]);
					$this->cambiar_data_id($pedido);
					$this->creado = true;
					$this->crear_o_modificar_datos_de_pedido($user, $cambios);
				}
				if (array_key_exists('sexo', $cambios)) {
					$consulta 	= 'INSERT INTO change_asked_data(sexo_new) VALUES(:sexo)';
					DB::insert($consulta, [ ':sexo'	=> $cambios['sexo'] ]);
					$this->cambiar_data_id($pedido);
					$this->creado = true;
					$this->crear_o_modificar_datos_de_pedido($user, $cambios);
				}
				if (array_key_exists('fecha_nac', $cambios)) {
					$consulta 	= 'INSERT INTO change_asked_data(fecha_nac_new) VALUES(:fecha_nac_new)';
					DB::insert($consulta, [ ':fecha_nac_new'	=> $cambios['fecha_nac'] ]);
					$this->cambiar_data_id($pedido);
					$this->creado = true;
					$this->crear_o_modificar_datos_de_pedido($user, $cambios);
				}
				
				$pedido 	= ChangeAsked::verificar_pedido_actual($user->user_id, $user->year_id, $user->tipo);
			}
			
		
		}
	}
	
	public function cambiar_data_id($pedido){
		$last_id 	= DB::getPdo()->lastInsertId();
		Debugging::pin('$last_id', $last_id);
		$consulta 	= 'UPDATE change_asked SET data_id=:data_id WHERE id=:asked_id';
		DB::update($consulta, [ ':data_id'	=> $last_id, ':asked_id' => $pedido->asked_id ]);
	}



	public function putDestruir()
	{
		$user 			= User::fromToken();
		$asked_id 		= Request::input('asked_id');
		$data_id 		= Request::input('data_id');
		$assignment_id 	= Request::input('assignment_id');

		$consulta = 'DELETE FROM change_asked WHERE id=:asked_id';
		$borrar = DB::delete($consulta, [ ':asked_id' => $asked_id ]);
		
		$consulta = 'DELETE FROM change_asked_data WHERE id=:data_id';
		$borrar = DB::delete($consulta, [ ':data_id' => $data_id ]);
		
		$consulta = 'DELETE FROM change_asked_assignment WHERE id=:assignment_id';
		$borrar = DB::delete($consulta, [ ':assignment_id' => $assignment_id ]);
		


		return [ 'borrar' => $borrar ];
	}

	public function putDestruirPedidoAsignatura()
	{
		$user 			= User::fromToken();
		$asked_id 		= Request::input('asked_id');
		$assignment_id 	= Request::input('assignment_id');

		$consulta = 'DELETE FROM change_asked WHERE id=:asked_id';
		$borrar = DB::delete($consulta, [ ':asked_id' => $asked_id ]);
		
		$consulta = 'DELETE FROM change_asked_assignment WHERE id=:assignment_id';
		$borrar = DB::delete($consulta, [ ':assignment_id' => $assignment_id ]);
		


		return [ 'borrar' => $borrar ];
	}



	private function asignaturas_dia($year_id, $profesor_id, $periodo_id, $dia, $show_materias_todas=0)
	{
		$dia_cond = ' '; // Para que salgan todos

		if (!$show_materias_todas){

			switch ($dia) {
				case 0:
					$dia_cond = ' and domingo=1 ';
					break;
				case 1:
					$dia_cond = ' and lunes=1 ';
					break;
				case 2:
					$dia_cond = ' and martes=1 ';
					break;
				case 3:
					$dia_cond = ' and miercoles=1 ';
					break;
				case 4:
					$dia_cond = ' and jueves=1 ';
					break;
				case 5:
					$dia_cond = ' and viernes=1 ';
					break;
				case 6:
					$dia_cond = ' and sabado=1 ';
					break;

			}
		}



		$consulta = 'SELECT a.id as asignatura_id, a.grupo_id, a.profesor_id, a.creditos, a.orden,
				m.materia, m.alias as alias_materia, g.nombre as nombre_grupo, g.abrev as abrev_grupo, g.titular_id, g.caritas
			FROM asignaturas a
			inner join materias m on m.id=a.materia_id and m.deleted_at is null
			inner join grupos g on g.id=a.grupo_id and g.year_id=? and g.deleted_at is null
			where a.profesor_id=? and a.deleted_at is null '. $dia_cond .'
			order by g.orden, a.orden, m.materia, m.alias, a.id';
		
		$asignaturas = DB::select($consulta, [$year_id, $profesor_id]);
		
		
		for ($i=0; $i < count($asignaturas); $i++) { 
			
			$consulta 		= 'SELECT * FROM unidades WHERE asignatura_id=? and periodo_id=? and deleted_at is null';
			$unidades 		= DB::select($consulta, [$asignaturas[$i]->asignatura_id, $periodo_id]);
			
			foreach ($unidades as $unidad) {

				$subunidades 			= DB::select('SELECT * FROM subunidades WHERE unidad_id=? and deleted_at is null', [$unidad->id]);
				$unidad->subunidades 	= $subunidades;
	
			}
			
			$asignaturas[$i]->unidades = $unidades;
		}
		
		return $asignaturas;
	}


}