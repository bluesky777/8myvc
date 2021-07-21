<?php namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

use DB;
use Carbon\Carbon;

use App\Models\Year;
use \Log;

class Matricula extends Model {

	protected $table = 'matriculas';

	protected $fillable = [];

	use SoftDeletes;
	protected $softDelete = true;


	public static $consulta_asistentes_o_matriculados = 'SELECT m.id as matricula_id, m.alumno_id, a.no_matricula, a.nombres, a.apellidos, a.sexo, a.user_id, a.egresado,
							a.fecha_nac, a.ciudad_nac, c1.ciudad as ciudad_nac_nombre, a.tipo_doc, a.documento, a.ciudad_doc, c2.ciudad as ciudad_doc_nombre, a.tipo_sangre, a.eps, a.telefono, a.celular, 
							a.direccion, a.barrio, a.estrato, a.ciudad_resid, c3.ciudad as ciudad_resid_nombre, a.religion, a.email, a.facebook, a.created_by, a.updated_by,
							a.pazysalvo, m.promovido, a.deuda, m.grupo_id, m.prematriculado, 
							u.imagen_id, IFNULL(i.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as imagen_nombre, 
							u.username, u.is_superuser, u.is_active, a.nee, a.nee_descripcion,
							a.foto_id, IFNULL(i2.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as foto_nombre,
							m.fecha_retiro as fecha_retiro, m.estado, m.fecha_matricula, m.nuevo, m.repitente 
						FROM alumnos a 
						inner join matriculas m on a.id=m.alumno_id and m.grupo_id=:grupo_id and (m.estado="ASIS" or m.estado="MATR" or m.estado="PREM" or m.estado="PREA")
						left join users u on a.user_id=u.id and u.deleted_at is null
						left join images i on i.id=u.imagen_id and i.deleted_at is null
						left join images i2 on i2.id=a.foto_id and i2.deleted_at is null
						left join ciudades c1 on c1.id=a.ciudad_nac and c1.deleted_at is null
						left join ciudades c2 on c2.id=a.ciudad_doc and c2.deleted_at is null
						left join ciudades c3 on c3.id=a.ciudad_resid and c3.deleted_at is null
						where a.deleted_at is null and m.deleted_at is null
						order by a.apellidos, a.nombres';




	public static $consulta_asistentes_o_matriculados_simat = 'SELECT m.id as matricula_id, m.alumno_id, a.no_matricula, a.nombres, a.apellidos, a.sexo, a.user_id, a.egresado,
							a.fecha_nac, a.ciudad_nac, c1.departamento as departamento_nac_nombre, c1.ciudad as ciudad_nac_nombre, a.tipo_doc, t1.tipo as tipo_doc_name, a.documento, a.ciudad_doc, 
							c2.ciudad as ciudad_doc_nombre, c2.departamento as departamento_doc_nombre, a.tipo_sangre, a.eps, a.telefono, a.celular, 
							a.direccion, a.barrio, a.is_urbana, a.estrato, a.ciudad_resid, c3.ciudad as ciudad_resid_nombre, c3.departamento as departamento_resid_nombre, a.religion, a.email, a.facebook, a.created_by, a.updated_by,
							a.pazysalvo, a.deuda, m.grupo_id, a.is_urbana, IF(a.is_urbana, "SI", "NO") as es_urbana,
							t1.tipo as tipo_doc, t1.abrev as tipo_doc_abrev,
							u.imagen_id, IFNULL(i.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as imagen_nombre, 
							u.username, u.is_superuser, u.is_active, a.nee, a.nee_descripcion,
							a.foto_id, IFNULL(i2.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as foto_nombre,
							m.fecha_retiro as fecha_retiro, m.estado, m.fecha_matricula, m.nuevo, IF(m.nuevo, "SI", "NO") as es_nuevo, m.repitente,
							a.has_sisben, a.nro_sisben, a.has_sisben_3, a.nro_sisben_3 
						FROM alumnos a 
						inner join matriculas m on a.id=m.alumno_id and m.grupo_id=:grupo_id and (m.estado="ASIS" or m.estado="MATR" or m.estado="PREM" or m.estado="PREA")
						left join users u on a.user_id=u.id and u.deleted_at is null
						left join images i on i.id=u.imagen_id and i.deleted_at is null
						left join tipos_documentos t1 on t1.id=a.tipo_doc and t1.deleted_at is null
						left join images i2 on i2.id=a.foto_id and i2.deleted_at is null
						left join ciudades c1 on c1.id=a.ciudad_nac and c1.deleted_at is null
						left join ciudades c2 on c2.id=a.ciudad_doc and c2.deleted_at is null
						left join ciudades c3 on c3.id=a.ciudad_resid and c3.deleted_at is null
						where a.deleted_at is null and m.deleted_at is null
						order by a.apellidos, a.nombres';



	public static $consulta_parientes = 'SELECT ac.id, ac.nombres, ac.apellidos, ac.sexo, "Acudiente" as tipo, ac.fecha_nac, ac.ciudad_nac, c1.ciudad as ciudad_nac_nombre, ac.ciudad_doc, c2.ciudad as ciudad_doc_nombre, c2.departamento as departamento_doc_nombre, ac.telefono, pa.parentesco, pa.observaciones, pa.id as parentesco_id, ac.user_id, 
							ac.celular, ac.ocupacion, ac.email, ac.barrio, ac.direccion, ac.tipo_doc, t1.tipo as tipo_doc_nombre, ac.documento, ac.created_by, ac.updated_by, ac.created_at, ac.updated_at, 
							ac.foto_id, IFNULL(i.nombre, IF(ac.sexo="F","default_female.png", "default_male.png")) as foto_nombre, 
							u.username, u.is_active, ac.is_acudiente, IF(ac.is_acudiente, "SI", "NO") as es_acudiente
						FROM parentescos pa
						left join acudientes ac on ac.id=pa.acudiente_id and ac.deleted_at is null
						left join users u on ac.user_id=u.id and u.deleted_at is null
						left join images i on i.id=ac.foto_id and i.deleted_at is null
						left join tipos_documentos t1 on t1.id=ac.tipo_doc and t1.deleted_at is null
						left join ciudades c1 on c1.id=ac.ciudad_nac and c1.deleted_at is null
						left join ciudades c2 on c2.id=ac.ciudad_doc and c2.deleted_at is null
						WHERE pa.alumno_id=? and pa.deleted_at is null Order by ac.is_acudiente desc, ac.id ';



	public static function matricularUno($alumno_id, $grupo_id, $year_id=false, $user_id=null)
	{
		if (!$year_id) {
			$year = Year::where('actual', true)->first();
			$year_id = $year->id;
		}
		
		// Traigo matriculas del alumno este año aunque estén borradas
		$consulta = 'SELECT m.id, m.alumno_id, m.grupo_id, m.estado, g.year_id 
			FROM matriculas m 
			inner join grupos g 
				on m.alumno_id = :alumno_id and g.year_id = :year_id and m.grupo_id=g.id';

		$matriculas = DB::select($consulta, ['alumno_id'=>$alumno_id, 'year_id'=>$year_id]);
		$matricula = false;

		// Busco entre las que están borradas para activar alguna y borrar las demás
		for ($i=0; $i < count($matriculas); $i++) { 

			$matri = Matricula::onlyTrashed()->where('id', $matriculas[$i]->id)->first();

			if ($matri) {
				if ($matricula) { // Si ya he encontrado en un elemento anterior una matrícula identica, es porque ya la he activado, no debo activar más. Por el contrario, debo borrarlas
					$matri->deleted_by		= $user_id;
					$matri->save();
					$matri->delete();
				}else{
					$matri->estado 			= 'MATR'; // Matriculado, Asistente o Retirado , Prem, Form
					$matri->fecha_retiro 	= null;
					$matri->grupo_id 		= $grupo_id;
					$matri->updated_by		= $user_id;
					$matri->save();
					$matri->restore();
					$matricula=$matri;
				}
			}
		}
		
		Log::info('count($matriculas) > 0 && $matricula' . count($matriculas) .' - '. $matricula);
		//Cuando estoy pasando de un grupo a otro, la matricula a modificar no necesariamente está en papelera así que:
		if ( count($matriculas) > 0 && $matricula == false ) {
			Log::info('Encuentra más de una matrícula: '.count($matriculas));
			for ($i=0; $i < count($matriculas); $i++) { 

				$matri = Matricula::where('id', $matriculas[$i]->id)->first();
				
				if ($matri) {
					if ($matricula) { // Si ya he encontrado en un elemento anterior una matrícula identica, es porque ya la he activado, no debo activar más. Por el contrario, debo borrarlas
						$matri->deleted_by		= $user_id;
						$matri->save();
						$matri->delete();
					}else{
						$matri->estado 			= 'MATR'; // Matriculado, Asistente o Retirado
						$matri->fecha_retiro 	= null;
						$matri->grupo_id 		= $grupo_id;
						$matri->updated_by		= $user_id;
						$matri->save();
						$matricula=$matri;
					}
				}
			}
		}
		
		
		try {
			if (!$matricula) {
				$matricula = new Matricula;
				$matricula->alumno_id 	= $alumno_id;
				$matricula->grupo_id	= $grupo_id;
				$matricula->estado 		= 'MATR';
				$matricula->created_by	= $user_id;
				
				$now = Carbon::now('America/Bogota');
				//$now = new \DateTime();
				//$now->format('Y-m-d');

				$matricula->fecha_matricula = $now;

				$matricula->save();
				Log::info('Se creó matrícula . '.$matricula->id);
			}else{
				Log::info('No entra a la condición . '.$matricula);
			}
			
		} catch (Exception $e) {
			Log::info('Error creando nueva matrícula .');
			// se supone que esto nunca va a ocurrir, ya que eliminé todas las matrículas 
			// excepto la que concordara con el grupo, poniéndola en estado=MATR
			$matricula 				= Matricula::where('alumno_id', $alumno_id)->where('grupo_id', $grupo_id)->first();
			$matricula->estado 		= 'MATR';
			$matricula->updated_by	= $user_id;
			$matricula->save();
			
			Log::info('Por lo tanto, una modificada .' . $matricula->id);
		}

		return $matricula;
	}


	public function alumnos()
	{
		return $this->hasMany('Alumno');
	}

}



