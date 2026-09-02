<?php namespace App\Http\Controllers\Informes;


use App\Http\Controllers\Controller;


use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\DB;

use App\User;
use App\Models\Year;
use App\Models\Grupo;
use App\Models\Periodo;
use App\Models\Asignatura;
use App\Models\Subunidad;
use App\Models\Unidad;
use App\Models\Profesor;
use App\Models\Nota;
use App\Models\ConfigCertificado;
use App\Models\EscalaDeValoracion;
use App\Models\Debugging;
use App\Models\NotaComportamiento;
use App\Models\Area;
use App\Services\BoletinIndependiente;
use \Log;


class CertificadosPersonaController extends Controller {


	private $escalas_val = [];





	public function putIndex()
	{
		$user = User::fromToken();

		
		$alumno_id = Request::get('alumno_id');

		// `m.*` explícito y luego `g.*`, y NO se vuelve al `SELECT *` de antes: con
		// él, `matriculas.boletin_independiente` (24 ago 2026,
		// 19-boletin-independiente.md) salía en esta respuesta y **no hay instantánea
		// que lo cace**. Esa columna se retiró el 31 ago 2026 (§2.2) sin mover nada,
		// justamente porque esta lista no la nombraba; la regla vale para la
		// siguiente. §5.ter de noche-2026-08-24/bi-1.md.
		//
		// El orden se conserva a propósito: `SELECT *` daba primero las columnas de
		// `matriculas` y después las de `grupos`, y en las repetidas —`id`,
		// `created_at`…— gana la última, o sea `grupos`. Invertirlo cambiaría
		// `id` sin tocar una sola clave del cuerpo.
		$matriculas = DB::select('SELECT m.id, m.alumno_id, m.grupo_id, m.estado, m.prematriculado, m.fecha_retiro, m.fecha_matricula, m.fecha_pension, m.razon_retiro, m.programar, m.descripcion_recomendacion, m.efectuar_una, m.descripcion_efectuada, m.profes_editar_notas, m.nuevo, m.repitente, m.promovido, m.promedio, m.cant_asign_perdidas, m.cant_areas_perdidas, m.anios_in_cole, m.nro_folio, m.created_by, m.updated_by, m.deleted_by, m.deleted_at, m.created_at, m.updated_at, g.* FROM matriculas m 
			INNER JOIN grupos g ON g.id=m.grupo_id and g.deleted_at is null
			WHERE m.deleted_at is null and m.alumno_id=?', [$alumno_id]);


		return $matriculas;


	}

	/*
	 * ⚠️ ESTE MÉTODO NO LO ALCANZA NADIE, y lo que sigue debajo tampoco.
	 *
	 * La única ruta de este controlador es `PUT certificados-persona` → `putIndex()`,
	 * que devuelve **matrículas** y no baja aquí. `detailedNotasGrupo` tiene **cero
	 * llamadores en todo `app/`**, nadie extiende la clase, y está medido dos veces por
	 * caminos independientes en [05 §211 y §218](../../../../docs/migracion/05-codigo-muerto-y-roto.md):
	 * **445 de las 1.019 líneas de este fichero son inalcanzables**, y estas 150 son
	 * parte de ellas.
	 *
	 * **Se anota y no se borra**, que es la regla del repo para el código muerto que
	 * cuelga de un fichero con ruta. Y se anota **aquí arriba** porque el 05 guarda el
	 * error exacto que este sitio ya provocó una vez: *«con `CertificadosPersonaController`
	 * se dijo "hay que arreglarlo" **y estaba muerto**»*.
	 *
	 * **Lo que eso significa para la §6.4 del 19:** el `asignatura->bol_independiente`
	 * que se emite más abajo **no llega a ningún cliente**. Está escrito para que, si
	 * alguien resucita este camino, nazca correcto — no porque el certificado lo mande.
	 * Si el front necesita la nota flotante en un certificado de verdad, **el sitio no
	 * es éste** y hay que medir cuál es antes de tocarlo.
	 */
	public function detailedNotasGrupo($grupo_id, $user, $requested_alumnos='')
	{

		$this->escalas_val = DB::select('SELECT * FROM escalas_de_valoracion WHERE year_id=? AND deleted_at is null', [$user->year_id]);

		$year_actual = true;
		if (Request::has('year_selected')) {
			// Aquí había un `|| ... == 'true'` que en PHP 7 atrapaba los valores falsy
			// —`0 == 'true'` era cierto— y en PHP 8 ya no se alcanza nunca.
			if (Request::input('year_selected') == true) {
				$year_actual = false;
			}
		}
		
		
		
		
		
		$grupo			= Grupo::datos($grupo_id);
		$year			= Year::datos($user->year_id, $year_actual);
		$alumnos		= Grupo::alumnos($grupo_id, $requested_alumnos);
		
		
		$year_notas		= Year::datos($user->year_id);
		$year->cant_areas_pierde_year 		= $year_notas->cant_areas_pierde_year;
		$year->cant_asignatura_pierde_year 	= $year_notas->cant_asignatura_pierde_year;
		$year->minu_hora_clase 				= $year_notas->minu_hora_clase;
		
		
		$periodo_a_calcular = Request::input('periodo_a_calcular');
		
		if ($periodo_a_calcular) {
			$year->periodos = DB::select('SELECT * FROM periodos WHERE year_id=? and numero<=? and deleted_at is null', [$user->year_id, $periodo_a_calcular]);
			//$year->periodos = Periodo::where('year_id', $user->year_id)->where('numero', '<=', $periodo_a_calcular)->get();
		}else{
			$year->periodos = DB::select('SELECT * FROM periodos WHERE year_id=? and deleted_at is null', [$user->year_id]);
			//$year->periodos = Periodo::where('year_id', $user->year_id)->get();
		}

		$cons = 'SELECT c.*, i.nombre as encabezado_nombre, i2.nombre as piepagina_nombre 
				FROM config_certificados c 
				left join images i on i.id=c.encabezado_img_id and i.deleted_at is null
				left join images i2 on i2.id=c.piepagina_img_id and i2.deleted_at is null
					where c.id=?';
		$config_certificado = DB::select($cons, [$year->config_certificado_estudio_id]);
		if (count($config_certificado) > 0) {
			$year->config_certificado = $config_certificado[0];
		}


		$cons = 'SELECT n.nombre as nivel_educativo FROM niveles_educativos n
				inner join grados gra on gra.nivel_educativo_id=n.id and gra.deleted_at is null
				inner join grupos gru on gru.grado_id=gra.id and gru.id=? and gru.deleted_at is null
				where n.deleted_at is null';

		$niveles = DB::select($cons, [$grupo_id]);
		if (count($niveles) > 0) {
			$grupo->nivel_educativo = $niveles[0]->nivel_educativo;
		}



		


		$grupo->cantidad_alumnos = count($alumnos);

		$response_alumnos = [];
		

		foreach ($alumnos as $alumno) {

			// Todas las materias con sus unidades y subunides
			$this->definitivasMateriasXPeriodo($alumno, $grupo_id, $user->year_id, $year->periodos, $periodo_a_calcular, $user->si_recupera_materia_recup_indicador );

			
			
						// **Las ocho de `recuperacion_final` nombradas, y no el asterisco**, por lo mismo
			// que la de `notas_finales`: los metadatos de acta que A9 le añada no salen
			// impresos hasta que alguien los nombre aquí. Y ésta es la que ningún snapshot
			// veía: en el seed no hay ni una fila de recuperación, así que la forma guardada
			// dice `[]`. Por eso su test **inserta una**.
			$consulta = 'SELECT r.id, r.alumno_id, r.asignatura_id, r.year, r.nota, r.updated_by, r.created_at, r.updated_at, m.materia, m.alias FROM recuperacion_final r 
				INNER JOIN asignaturas a ON a.id=r.asignatura_id and a.deleted_at is null
				INNER JOIN materias m ON m.id=a.materia_id and m.deleted_at is null
				WHERE alumno_id=? and year=?';
				
			$alumno->recuperaciones = DB::select($consulta, [$alumno->alumno_id, $user->year]);

			
			$alumno->cant_lost_asig = $alumno->cant_lost_asig - count($alumno->recuperaciones);

	
			$asignaturas_perdidas = $this->asignaturasPerdidasDeAlumno($alumno, $grupo_id, $user->year_id);

			if (count($asignaturas_perdidas) > 0) {
				
				$alumno->asignaturas_perdidas = $asignaturas_perdidas;
				$alumno->notas_perdidas_year = 0;
				
				if ($periodo_a_calcular) {
					$alumno->periodos_con_perdidas = DB::select('SELECT * FROM periodos WHERE year_id=? and numero<=? and deleted_at is null', [$user->year_id, $periodo_a_calcular]);
				}else{
					$alumno->periodos_con_perdidas = DB::select('SELECT * FROM periodos WHERE year_id=? and deleted_at is null', [$user->year_id]);
				}

				foreach ($alumno->periodos_con_perdidas as $keyPerA => $periodoAlone) {

					$periodoAlone->cant_perdidas = 0;
					
					foreach ($alumno->asignaturas_perdidas as $keyAsig => $asignatura_perdida) {

						foreach ($asignatura_perdida->periodos as $keyPer => $periodo) {

							if ($periodoAlone->id == $periodo->id) {
								if ($periodo->id == $periodoAlone->id) {
									$periodoAlone->cant_perdidas += $periodo->cantNotasPerdidas;
								}
								
							}
						}
					}

					$alumno->notas_perdidas_year += $periodoAlone->cant_perdidas;
					
				}
			}
		}


		/*
		 * EL PUESTO LO DECIDE EL SERVICIO, no este `foreach` — fase 6 del
		 * [19](../../../../docs/migracion/19-boletin-independiente.md), §7.
		 *
		 * Aquí había `Nota::puestoAlumno($alumno->promedio, $alumnos)` dentro del bucle, y
		 * ese mismo cálculo estaba **copiado en ocho sitios**. `puestoAlumno` sigue
		 * intacta y sigue siendo pura: lo que cambia es **quién entra en la lista contra
		 * la que se cuenta**, y eso lo decide `years.puestos_con_bol_independiente`.
		 *
		 * Con el interruptor en 1 —el default— esto es exactamente lo de antes. Con 0, el
		 * alumno con boletín independiente sale del recuento: su puesto viaja `null`
		 * (decisión 6) y **a los demás les cambia el suyo**.
		 *
		 * **Y aquí se le pasan VARIOS periodos, que es la diferencia con los tres
		 * boletines de periodo.** Este promedio se calcula sobre las definitivas de todos
		 * los periodos que `$year->periodos` trae, así que basta con que el alumno haya
		 * ido aparte en **uno** de ellos para que su promedio no se haya calculado sobre
		 * el reparto del grupo. Preguntar sólo por el periodo del token dejaría dentro del
		 * recuento a quien tuvo el accidente en el segundo y hoy va con el grupo — y
		 * preguntar por el año entero sacaría a quien lo tuvo en un periodo que este
		 * informe no está promediando. Los periodos que se promedian son los que deciden.
		 */
		BoletinIndependiente::ponerPuestos(
			$alumnos,
			array_map(fn ($periodo) => (int) $periodo->id, $year->periodos),
			(int) $user->year_id
		);

		foreach ($alumnos as $alumno) {
			
			if ($requested_alumnos == '') {

				array_push($response_alumnos, $alumno);

			}else{

				foreach ($requested_alumnos as $req_alumno) {
					
					if ($req_alumno['alumno_id'] == $alumno->alumno_id) {
						array_push($response_alumnos, $alumno);
					}
				}
			}
			

		}


		return array($grupo, $year, $response_alumnos, $this->escalas_val);
	}

	public function definitivasMateriasXPeriodo(&$alumno, $grupo_id, $year_id, $periodos, $per_calcular=null, $si_recupera_materia_recup_indicador=false)
	{

		$alumno->asignaturas	= Grupo::detailed_materias($grupo_id);

		$alumno->promedio = 0;
		$alumno->cant_lost_asig = 0;
		$alumno->ausencias = 0;

		/*
		 * `bol_independiente`: **este documento es el suyo, no el del grupo** — §6.4 del
		 * [19](../../../../docs/migracion/19-boletin-independiente.md).
		 *
		 * **Es un dato del ALUMNO puesto en cada asignatura**, no una propiedad de la
		 * asignatura: la marca cuelga de `(alumno_id, periodo_id)`, así que vale lo mismo
		 * en todas las suyas. Se emite por asignatura porque el front pinta la nota al
		 * lado de cada bloque.
		 *
		 * **Y aquí decide «alguno de los periodos que este documento cubre»**, igual que
		 * el puesto y por lo mismo: este certificado promedia los periodos que le
		 * pasan en `$periodos`, que son los del año o los de «hasta el N». Un alumno que pasó un
		 * periodo aparte tiene dentro de estas cifras una definitiva que **no se calculó
		 * sobre el reparto del grupo**, y eso es justo lo que la nota avisa.
		 *
		 * **No se rotula el papel**: lo que el front pinta con esto es una nota flotante
		 * que se ve en pantalla y **desaparece al imprimir**. Si el campo no viaja, no se
		 * pinta nada y nadie se entera.
		 */
		$bol_independiente = BoletinIndependiente::aplicaEnAlguno((int) $alumno->alumno_id, array_map(fn ($periodo) => (int) $periodo->id, $periodos));

		$alumno->tardanzas = 0;
		$alumno->total_creditos = 0;
		$alumno->notas_perdidas = 0;
		
		$sqlPeriodo = '';
		if ($per_calcular) {
			$sqlPeriodo = 'and nf.periodo<=:periodo';
		}
		
		
		
		foreach ($alumno->asignaturas as $asignatura) {

			$asignatura->bol_independiente = $bol_independiente;

			$alumno->total_creditos += $asignatura->creditos;
						
						// **Las once columnas de `notas_finales` nombradas, y no el asterisco** — 27 §4.
			// Con `nf` en asterisco, cada columna nueva de la tabla sale **sola** en este
			// informe el día que corra una migración, con este mismo código y sin que nadie
			// haya decidido enseñarla: las tres de la nivelación —`nota_original`,
			// `nivelada_at`, `nivelada_por`— habrían llegado al front en la ventana entre el
			// despliegue de A3 y la impresión del par, que son semanas. Es la guarda que ya
			// llevan `notas/detailed` (NotasController:52) y `certificados-persona::putIndex`.
			//
			// `nota` va **una sola vez**, en DOUBLE: antes viajaba dos veces —la cruda del
			// asterisco y la casteada— y en PDO gana la última, que es ésta. Por eso la lista
			// no la repite y la respuesta no cambia ni un campo.
			$consulta = 'SELECT nf.id, nf.alumno_id, nf.asignatura_id, nf.periodo_id, nf.periodo, CAST(nf.nota AS DOUBLE) AS nota, CAST(nf.nota AS DOUBLE) as DefMateria,
						nf.recuperada, nf.manual, nf.updated_by, nf.created_at, nf.updated_at, aus.cantidad_ausencia, tar.cantidad_tardanza
						FROM notas_finales nf
						INNER JOIN periodos p on p.year_id=:year_id and p.id=nf.periodo_id '.$sqlPeriodo.' and p.deleted_at is null
						left join (
								select count(au.id) as cantidad_ausencia, au.alumno_id, au.periodo_id, au.asignatura_id
								from ausencias au 
								where au.deleted_at is null and au.cantidad_ausencia > 0
								group by au.alumno_id, au.periodo_id, au.asignatura_id
								
								)as aus on aus.alumno_id=nf.alumno_id and aus.asignatura_id=nf.asignatura_id and aus.periodo_id=nf.periodo_id
						left join (
								select count(au.id) as cantidad_tardanza, au.alumno_id, au.periodo_id, au.asignatura_id
								from ausencias au 
								where au.deleted_at is null and au.cantidad_tardanza > 0
								group by au.alumno_id, au.periodo_id, au.asignatura_id
									
						)as tar on tar.alumno_id=nf.alumno_id and tar.asignatura_id=nf.asignatura_id and tar.periodo_id=nf.periodo_id
						WHERE nf.alumno_id=:alumno_id and nf.asignatura_id=:asignatura_id
						ORDER BY nf.periodo';
					
			if ($per_calcular) {
				$paramentros = [
					':year_id'		=> $year_id,
					':periodo' 		=> $per_calcular, 
					':alumno_id'	=> $alumno->alumno_id, 
					':asignatura_id'=> $asignatura->asignatura_id
				];
			}else{
				$paramentros = [
					':year_id'		=> $year_id,
					':alumno_id'	=> $alumno->alumno_id, 
					':asignatura_id'=> $asignatura->asignatura_id
				];
			}
				
			
			$asignatura->definitivas = DB::select($consulta, $paramentros);




			// Agrego Periodos ficticios al array para llenar la tabla con espacios vacios.
			$per_faltantes = count($periodos) - count($asignatura->definitivas);

			if($per_faltantes > 0){
				for($i=0; $i<$per_faltantes; $i++){
					$prov = (object)['DefMateria'=>0,'cantidad_ausencia'=>0,'cantidad_tardanza'=>0,'periodo_id'=>-1,'manual'=>0];
					array_push($asignatura->definitivas, $prov);
				}
			}


			// Hallamos las ausencias y tardanzas
			$suma_def = 0;
			$suma_aus = 0;
			$suma_tar = 0;
			$notas_perd = 0;
			
			foreach ($asignatura->definitivas as $keydef => $definitiva) {
				
				
				$suma_def += (float)$definitiva->DefMateria;
				$suma_aus += (int)$definitiva->cantidad_ausencia;
				$suma_tar += (int)$definitiva->cantidad_tardanza;
				
				
				if(($si_recupera_materia_recup_indicador && $definitiva->DefMateria >= User::$nota_minima_aceptada) || ( $definitiva->manual==1 && $definitiva->DefMateria >= User::$nota_minima_aceptada)){
					// No se cuentan las notas perdidas
				}else{
					
					// Cuantas notas tiene perdidas por cada definitiva
					$consul = 'SELECT COUNT(n.id) as notas_perdidas
						from notas n
						inner join subunidades s on s.id=n.subunidad_id and s.deleted_at is null
						inner join unidades u on u.id=s.unidad_id and u.periodo_id=:periodo_id and u.asignatura_id=:asignatura_id and u.deleted_at is null
						where n.nota < :nota_minima and n.alumno_id=:alumno_id;';

					$definitiva->notas_perdidas = DB::select($consul, array(
											':periodo_id'	=> $definitiva->periodo_id,
											':asignatura_id'=> $asignatura->asignatura_id,
											':nota_minima'	=> User::$nota_minima_aceptada,
											':alumno_id'	=> $alumno->alumno_id ));

					if (count($definitiva->notas_perdidas) > 0) {
						$definitiva->notas_perdidas = $definitiva->notas_perdidas[0]->notas_perdidas;
						$notas_perd += $definitiva->notas_perdidas;
					}
				}
				
			}
			$asignatura->promedio 			= $suma_def / count($asignatura->definitivas);
			$asignatura->nota_asignatura 	= $asignatura->promedio;
			$asignatura->ausencias 			= $suma_aus;
			$asignatura->tardanzas 			= $suma_tar;
			$asignatura->notas_perdidas 	= $notas_perd;

			$escala = $this->valoracion($asignatura->promedio);

			if ($escala) {
				$asignatura->desempenio 	= $escala->desempenio;
				$asignatura->perdido 		= $escala->perdido;
				$asignatura->valoracion 	= $escala->valoracion;
			}
			

			$alumno->promedio += $asignatura->promedio;
			$alumno->ausencias += $asignatura->ausencias;
			$alumno->tardanzas += $asignatura->tardanzas;
			$alumno->notas_perdidas += $asignatura->notas_perdidas;



			// Si es un promedio perdido, debo sumarlo como una asignatura perdida
			if (round($asignatura->promedio) < User::$nota_minima_aceptada) {
				$alumno->cant_lost_asig += 1;
			}

		}

		$alumno->promedio = $alumno->promedio / count($alumno->asignaturas);

		$escala = $this->valoracion($alumno->promedio);
		if ($escala) {
			$alumno->desempenio = $escala->desempenio;
		}


		// Nota promedio de comportamiento
		$alumno->nota_comportamiento_year 	= NotaComportamiento::nota_promedio_year($alumno->alumno_id, $year_id);
		$alumno->notas_comportamiento 		= NotaComportamiento::todas_year($alumno->alumno_id, $year_id);
		
		$escala = $this->valoracion($alumno->nota_comportamiento_year);
		if ($escala) {
			$alumno->nota_comportamiento_year_desempenio = $escala->desempenio;
		}
		
		// Agrupamos por áreas
		$areas = Area::agrupar_asignaturas($grupo_id, $alumno->asignaturas, $this->escalas_val);		
		$cant_lost_areas = 0;
		
		for ($k=0; $k < count($areas); $k++) { 
			if ($areas[$k]->area_nota < User::$nota_minima_aceptada){
				$cant_lost_areas = $cant_lost_areas + 1;
			}
		}
		
		$alumno->areas 				= $areas;
		$alumno->cant_lost_areas 	= $cant_lost_areas;

		return $alumno;
	}




	public function valoracion($nota)
	{
		$nota = round($nota);

		foreach ($this->escalas_val as $key => $escala_val) {
			//Debugging::pin($escala_val->porc_inicial, $escala_val->porc_final, $nota);

			if (($escala_val->porc_inicial <= $nota) &&  ($escala_val->porc_final >= $nota)) {
				return $escala_val;
			}
		}
		return [];
	}



	public function asignaturasPerdidasDeAlumno($alumno, $grupo_id, $year_id)
	{
		$asignaturas	= Grupo::detailed_materias($grupo_id);


		foreach ($asignaturas as $keyAsig => $asignatura) {
			$periodo_a_calcular = Request::input('periodo_a_calcular');
			
			if ($periodo_a_calcular) {
				$asignatura->periodos = DB::select('SELECT * FROM periodos WHERE year_id=? and numero<=? and deleted_at is null', [$year_id, $periodo_a_calcular]);
			}else{
				$asignatura->periodos = DB::select('SELECT * FROM periodos WHERE year_id=? and deleted_at is null', [$year_id]);;
			}
			
			

			$asignatura->cantTotal = 0;

			foreach ($asignatura->periodos as $keyPer => $periodo) {

				
				$consulta = 'SELECT distinct n.nota, n.id as nota_id, n.alumno_id,  s.id as subunidad_id, s.definicion, u.id as unidad_id, u.periodo_id
						from notas n, subunidades s, unidades u, asignaturas a, matriculas m
						where n.subunidad_id=s.id and s.unidad_id=u.id and u.periodo_id=:periodo_id 
						and u.asignatura_id=a.id and m.alumno_id=n.alumno_id and m.deleted_at is null and m.estado="MATR"
						and a.id=:asignatura_id and n.alumno_id=:alumno_id and n.nota < :nota_minima;';

				$notas_perdidas = DB::select($consulta, array(
									':periodo_id'		=> $periodo->id, 
									':asignatura_id'	=> $asignatura->asignatura_id, 
									':alumno_id'		=> $alumno->alumno_id,
									':nota_minima'		=> User::$nota_minima_aceptada
								));

				$periodo->cantNotasPerdidas = count($notas_perdidas);

				$asignatura->cantTotal += $periodo->cantNotasPerdidas;


				if ($periodo->cantNotasPerdidas == 0) {
					unset($asignatura->periodos[$keyPer]);
				}
				
				
			}

			if (count($asignatura->periodos) == 0) {
				unset($asignaturas[$keyAsig]);
			}

			$hasPeriodosConPerdidas = false;

			foreach ($asignatura->periodos as $keyPer => $periodo) {
				if ($periodo->cantNotasPerdidas > 0) {
					$hasPeriodosConPerdidas = true;
				}
			}

			if (!$hasPeriodosConPerdidas) {
				unset($asignaturas[$keyAsig]);
			}

		}

		return $asignaturas;

	}

	public function periodosPerdidosDeAlumno($alumno, $grupo_id, $year_id, $periodos)
	{
		//$periodos = Periodo::where('year_id', '=', $year_id)->get();

		foreach ($periodos as $key => $periodo) {
			$periodo->asignaturas = $this->asignaturasPerdidasDeAlumnoPorPeriodo($alumno->alumno_id, $grupo_id, $periodo->id);

			if (count($periodo->asignaturas)==0) {
				unset($periodos[$key]);
			}
		}
	}

	public function asignaturasPerdidasDeAlumnoPorPeriodo($alumno_id, $grupo_id, $periodo_id)
	{


		$asignaturas	= Grupo::detailed_materias($grupo_id);

		foreach ($asignaturas as $keyAsig => $asignatura) {

			$asignatura->unidades = Unidad::deAsignatura($asignatura->asignatura_id, $periodo_id, $alumno_id);

			foreach ($asignatura->unidades as $keyUni => $unidad) {
				$unidad->subunidades = Subunidad::perdidasDeUnidad($unidad->unidad_id, $alumno_id);

				if (count($unidad->subunidades) == 0) {
					unset($asignatura->unidades[$keyUni]);
				}
			}
			if (count($asignatura->unidades) == 0) {
				unset($asignaturas[$keyAsig]);
			}
		}


		return $asignaturas;
	}







}