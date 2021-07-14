<?php namespace App\Http\Controllers\Informes;

use Request;
use DB;

use App\User;
use App\Models\Year;
use App\Models\Grupo;
use App\Models\Area;
use Carbon\Carbon;
use App\Http\Controllers\Controller;
use \Log;


class ActasEvaluacionController extends Controller {


	private $periodos = [];


	public function putActaEvaluacionPromocion()
	{
		$user 		= User::fromToken();
		$year 		= Year::datos_basicos($user->year_id);
		$periodos 	= DB::select('SELECT * FROM periodos WHERE year_id=? and deleted_at is null order by numero', [$user->year_id]);
		$periodo1 	= $periodos[0];

		
		$consulta = 'SELECT g.id, g.nombre, g.abrev, g.orden, gra.orden as orden_grado, g.grado_id, g.year_id, g.titular_id,
			p.nombres as nombres_titular, p.apellidos as apellidos_titular, p.titulo,
			g.created_at, g.updated_at, gra.nombre as nombre_grado 
			from grupos g
			inner join grados gra on gra.id=g.grado_id and g.year_id=:year_id 
			left join profesores p on p.id=g.titular_id
			where g.deleted_at is null
			order by g.orden';

		$grupos = DB::select($consulta, [':year_id'=>$user->year_id] );

		// Recorremos los grupos
		for ($i=0; $i < count($grupos); $i++) { 
			

			// Alumnos
			$consulta = 'SELECT m.id as matricula_id, m.alumno_id, a.no_matricula, a.nombres, a.apellidos, a.sexo, a.user_id, a.egresado,
					a.fecha_nac, a.ciudad_nac, c1.departamento as departamento_nac_nombre, c1.ciudad as ciudad_nac_nombre, a.tipo_doc, t1.tipo as tipo_doc_name, a.documento, a.ciudad_doc, 
					a.tipo_sangre, a.eps, CONCAT(a.telefono, " / ", a.celular) as telefonos, 
					a.direccion, a.barrio, a.estrato, a.ciudad_resid, a.religion, a.email, a.facebook, a.created_by, a.updated_by,
					a.pazysalvo, a.deuda, m.grupo_id, a.is_urbana, IF(a.is_urbana, "Urbano", "Rural") as es_urbana,
                    a.created_by, u2.username as creado_por,
					t1.tipo as tipo_doc, t1.abrev as tipo_doc_abrev,
					u.imagen_id, IFNULL(i.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as imagen_nombre, 
					u.username, u.is_superuser, u.is_active,
					a.foto_id, IFNULL(i2.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as foto_nombre,
					m.fecha_retiro as fecha_retiro, m.estado, m.fecha_matricula, m.nuevo, IF(m.nuevo, "SI", "NO") as es_nuevo, m.repitente,
                    m.promovido, m.promedio, m.cant_asign_perdidas, m.cant_areas_perdidas, m.anios_in_cole, 
					a.has_sisben, a.nro_sisben, a.has_sisben_3, a.nro_sisben_3 
				FROM alumnos a 
				inner join matriculas m on a.id=m.alumno_id and m.grupo_id=:grupo_id and (m.estado="MATR" or m.estado="PREM" or m.estado="DESE" or m.estado="RETI")
				left join users u on a.user_id=u.id and u.deleted_at is null
                left join users u2 on a.created_by=u.id and u2.deleted_at is null
				left join images i on i.id=u.imagen_id and i.deleted_at is null
				left join tipos_documentos t1 on t1.id=a.tipo_doc and t1.deleted_at is null
				left join images i2 on i2.id=a.foto_id and i2.deleted_at is null
				left join ciudades c1 on c1.id=a.ciudad_nac and c1.deleted_at is null
				where a.deleted_at is null and m.deleted_at is null
				order by a.apellidos, a.nombres';
			
			$grupos[$i]->alumnos = DB::select($consulta, [':grupo_id' => $grupos[$i]->id]);

			
			$promovidos_0_perdidas 			= 0;
			$promovidos_0_perdidas_f 		= 0;
			$promovidos_0_perdidas_m 		= 0;

			$promovidos_1_perdidas 			= 0;
			$promovidos_1_perdidas_f 		= 0;
			$promovidos_1_perdidas_m 		= 0;

			$no_promovidos_2_perdidas 		= 0;
			$no_promovidos_2_perdidas_f 	= 0;
			$no_promovidos_2_perdidas_m 	= 0;
			$no_promovidos_3_perdidas 		= 0;
			$no_promovidos_3_perdidas_f 	= 0;
			$no_promovidos_3_perdidas_m 	= 0;
			$no_promovidos_4_perdidas 		= 0;
			$no_promovidos_4_perdidas_f 	= 0;
			$no_promovidos_4_perdidas_m 	= 0;

			$grupos[$i]->cant_terminaron 	= 0;
			$grupos[$i]->cant_terminaron_m 	= 0;
			$grupos[$i]->cant_terminaron_f 	= 0;
			$grupos[$i]->cant_desertores 	= 0;
			$grupos[$i]->cant_desertores_m 	= 0;
			$grupos[$i]->cant_desertores_f 	= 0;
			$grupos[$i]->cant_retirados 	= 0;
			$grupos[$i]->cant_retirados_m 	= 0;
			$grupos[$i]->cant_retirados_f 	= 0;
			
			$cantA = count($grupos[$i]->alumnos);

			// Recorremos los alumnos
			for ($j=0; $j < $cantA; $j++) { 
				// Edad
				if ($grupos[$i]->alumnos[$j]->fecha_nac) {
					$anio 	= date('Y', strtotime( $grupos[$i]->alumnos[$j]->fecha_nac) );
					$mes 	= date('m', strtotime( $grupos[$i]->alumnos[$j]->fecha_nac) );
					$dia 	= date('d', strtotime( $grupos[$i]->alumnos[$j]->fecha_nac) );
					$grupos[$i]->alumnos[$j]->edad = Carbon::createFromDate($anio, $mes, $dia)->age;
				}else{
					$grupos[$i]->alumnos[$j]->edad = '';
				}
				
				// Promedio
				if ($grupos[$i]->alumnos[$j]->promedio > 0) {
					$grupos[$i]->alumnos[$j]->promedio = round($grupos[$i]->alumnos[$j]->promedio, 1);
				}else{
					$grupos[$i]->alumnos[$j]->promedio = '';
				}

				// Estado matrícula

				if ($grupos[$i]->alumnos[$j]->estado == 'PREM' || $grupos[$i]->alumnos[$j]->estado == 'ASIS' || $grupos[$i]->alumnos[$j]->estado == 'MATR') {
					//$grupos[$i]->cant_terminaron 	+= 1;

					if ($grupos[$i]->alumnos[$j]->sexo == 'M') {
						$grupos[$i]->cant_terminaron_m 	+= 1;
					}else{
						$grupos[$i]->cant_terminaron_f 	+= 1;
					}
					
				}else if($grupos[$i]->alumnos[$j]->estado == 'DESE'){
					$grupos[$i]->cant_desertores 	+= 1;

					if ($grupos[$i]->alumnos[$j]->sexo == 'M') {
						$grupos[$i]->cant_desertores_m 	+= 1;
					}else{
						$grupos[$i]->cant_desertores_f 	+= 1;
					}

				}else if($grupos[$i]->alumnos[$j]->estado == 'RETI'){
					$grupos[$i]->cant_retirados 	+= 1;

					if ($grupos[$i]->alumnos[$j]->sexo == 'M') {
						$grupos[$i]->cant_retirados_m 	+= 1;
					}else{
						$grupos[$i]->cant_retirados_f 	+= 1;
					}
				}

				// Promovido?
				if (strpos( $grupos[$i]->alumnos[$j]->promovido, 'No promovido') !==false ) {
					$grupos[$i]->alumnos[$j]->promovido = 'No';

					// Contamos no promovidos
					if ($grupos[$i]->alumnos[$j]->cant_asign_perdidas == 2 || $grupos[$i]->alumnos[$j]->cant_areas_perdidas == 2) {
						
						$no_promovidos_2_perdidas 	+= 1;
						if($grupos[$i]->alumnos[$j]->sexo == 'M'){
							$no_promovidos_2_perdidas_m 	+= 1;
						}else{
							$no_promovidos_2_perdidas_f 	+= 1;
						}

					}else if ($grupos[$i]->alumnos[$j]->cant_asign_perdidas == 3 || $grupos[$i]->alumnos[$j]->cant_areas_perdidas == 3) {
						
						$no_promovidos_3_perdidas 	+= 1;
						if($grupos[$i]->alumnos[$j]->sexo == 'M'){
							$no_promovidos_3_perdidas_m 	+= 1;
						}else{
							$no_promovidos_3_perdidas_f 	+= 1;
						}

					}else if ($grupos[$i]->alumnos[$j]->cant_asign_perdidas > 3 || $grupos[$i]->alumnos[$j]->cant_areas_perdidas > 3) {

						$no_promovidos_4_perdidas 	+= 1;
						if($grupos[$i]->alumnos[$j]->sexo == 'M'){
							$no_promovidos_4_perdidas_m 	+= 1;
						}else{
							$no_promovidos_4_perdidas_f 	+= 1;
						}
					}

				}else{
					$grupos[$i]->alumnos[$j]->promovido = 'Si';
					
					// Contamos promovidos
					if ($grupos[$i]->alumnos[$j]->cant_asign_perdidas == 0 || $grupos[$i]->alumnos[$j]->cant_areas_perdidas == 0) {
						
						$promovidos_0_perdidas 	+= 1;
						if($grupos[$i]->alumnos[$j]->sexo == 'M'){
							$promovidos_0_perdidas_m 	+= 1;
						}else{
							$promovidos_0_perdidas_f 	+= 1;
						}

					}else if ($grupos[$i]->alumnos[$j]->cant_asign_perdidas == 1 || $grupos[$i]->alumnos[$j]->cant_areas_perdidas == 1) {
						
						$promovidos_1_perdidas 	+= 1;
						if($grupos[$i]->alumnos[$j]->sexo == 'M'){
							$promovidos_1_perdidas_m 	+= 1;
						}else{
							$promovidos_1_perdidas_f 	+= 1;
						}

					}

				}

				
				

			}
			
			// Matriculados en fechas...
			$consulta = 'SELECT m.id as matricula_id, a.id as alumno_id, a.nombres, a.apellidos, a.fecha_nac, m.fecha_matricula, m.fecha_retiro, m.estado, a.sexo, a.no_matricula, a.created_at
				from matriculas m 
				INNER JOIN alumnos a ON a.id=m.alumno_id and a.deleted_at is null
				where m.fecha_matricula<=? and m.deleted_at is null and (m.estado="PREM" or m.estado="MATR" or m.estado="ASIS") and m.grupo_id=? order by a.apellidos';

			$grupos[$i]->cant_matri_per1 				= DB::select($consulta, [$periodo1->fecha_fin, $grupos[$i]->id]);

			$consulta = 'SELECT count(m.id) as cant_alumnos
				from matriculas m 
				INNER JOIN alumnos a ON a.id=m.alumno_id and a.deleted_at is null and a.sexo="M"
				where m.fecha_matricula<=? and m.deleted_at is null and (m.estado="PREM" or m.estado="MATR" or m.estado="ASIS") and m.grupo_id=?';

			$grupos[$i]->cant_matri_per1_m 				= DB::select($consulta, [$periodo1->fecha_fin, $grupos[$i]->id])[0]->cant_alumnos;

			$consulta = 'SELECT count(m.id) as cant_alumnos
				from matriculas m 
				INNER JOIN alumnos a ON a.id=m.alumno_id and a.deleted_at is null
				where m.fecha_matricula>? and m.deleted_at is null and (m.estado="PREM" or m.estado="MATR" or m.estado="ASIS") and m.grupo_id=?';

			$grupos[$i]->cant_matri_despues 			= DB::select($consulta, [$periodo1->fecha_fin, $grupos[$i]->id])[0]->cant_alumnos;

			$consulta = 'SELECT count(m.id) as cant_alumnos
				from matriculas m 
				INNER JOIN alumnos a ON a.id=m.alumno_id and a.deleted_at is null and a.sexo="M"
				where m.fecha_matricula>? and m.deleted_at is null and (m.estado="PREM" or m.estado="MATR" or m.estado="ASIS") and m.grupo_id=?';

			$grupos[$i]->cant_matri_despues_m 			= DB::select($consulta, [$periodo1->fecha_fin, $grupos[$i]->id])[0]->cant_alumnos;
			
			
			// Que terminaron 
			$consulta = 'SELECT m.id as matricula_id, a.id as alumno_id, a.nombres, a.apellidos, a.fecha_nac, m.fecha_matricula, m.fecha_retiro, m.estado, a.sexo, a.no_matricula, a.created_at
				from matriculas m 
				INNER JOIN alumnos a ON a.id=m.alumno_id and a.deleted_at is null
				where m.deleted_at is null and (m.estado="PREM" or m.estado="MATR" or m.estado="ASIS") and m.grupo_id=? order by a.apellidos';

			$grupos[$i]->cant_terminaron 				= DB::select($consulta, [$grupos[$i]->id]);



			// Datos estadísticos del grupo
			$grupos[$i]->promovidos_0_perdidas 			= $promovidos_0_perdidas;
			$grupos[$i]->promovidos_0_perdidas_f 		= $promovidos_0_perdidas_f;
			$grupos[$i]->promovidos_0_perdidas_m 		= $promovidos_0_perdidas_m;
			$grupos[$i]->promovidos_1_perdidas 			= $promovidos_1_perdidas;
			$grupos[$i]->promovidos_1_perdidas_f 		= $promovidos_1_perdidas_f;
			$grupos[$i]->promovidos_1_perdidas_m 		= $promovidos_1_perdidas_m;

			$grupos[$i]->no_promovidos_2_perdidas 		= $no_promovidos_2_perdidas;
			$grupos[$i]->no_promovidos_2_perdidas_f 	= $no_promovidos_2_perdidas_f;
			$grupos[$i]->no_promovidos_2_perdidas_m 	= $no_promovidos_2_perdidas_m;
			$grupos[$i]->no_promovidos_3_perdidas 		= $no_promovidos_3_perdidas;
			$grupos[$i]->no_promovidos_3_perdidas_f 	= $no_promovidos_3_perdidas_f;
			$grupos[$i]->no_promovidos_3_perdidas_m 	= $no_promovidos_3_perdidas_m;
			$grupos[$i]->no_promovidos_4_perdidas 		= $no_promovidos_4_perdidas;
			$grupos[$i]->no_promovidos_4_perdidas_f 	= $no_promovidos_4_perdidas_f;
			$grupos[$i]->no_promovidos_4_perdidas_m 	= $no_promovidos_4_perdidas_m;

		}


		return [ 'grupos' => $grupos, 'year' => $year];
	}




	public function putDetalle()
	{
		$user 			= User::fromToken();
		$alumno_id 		= Request::input('alumno_id');
        

        $consulta = 'SELECT m.id as matricula_id, m.alumno_id, a.no_matricula, a.nombres, a.apellidos, a.sexo, a.user_id, 
                a.fecha_nac, a.ciudad_nac, a.celular, a.direccion, a.religion, t.tipo as tipo_doc, t.abrev as tipo_doc_abrev, a.documento, a.no_matricula, 
                m.grupo_id, m.estado, m.nuevo, m.repitente, username, a.created_at, 
                u.imagen_id, IFNULL(i.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as imagen_nombre, 
                a.foto_id, IFNULL(i2.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as foto_nombre
            FROM alumnos a 
            inner join matriculas m on a.id=m.alumno_id and m.grupo_id=?
            left join users u on a.user_id=u.id and u.deleted_at is null
            left join tipos_documentos t on a.tipo_doc=t.id and t.deleted_at is null
            left join images i on i.id=u.imagen_id and i.deleted_at is null
            left join images i2 on i2.id=a.foto_id and i2.deleted_at is null
            where a.deleted_at is null and m.deleted_at is null
            order by a.apellidos, a.nombres';

        $alumnos = DB::select($consulta, [ Request::input('grupo_id') ]);

		// Años de estadía
		$consulta = 'SELECT y.year, m.*, g.nombre, m.id as matricula_id
			FROM matriculas m
			INNER JOIN alumnos a ON a.id=m.alumno_id and m.deleted_at is null and a.deleted_at is null
			INNER JOIN grupos g ON g.id=m.grupo_id and g.deleted_at is null
			INNER JOIN years y ON g.year_id=y.id and y.deleted_at is null
			WHERE a.id=? order by y.year';

		$anios = DB::select($consulta, [$alumno_id]);

		
        return [ 'alumnos' => $alumnos, 'matriculas' => $anios ];
    }








}