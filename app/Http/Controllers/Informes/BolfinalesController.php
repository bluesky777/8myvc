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
use \Log;


class BolfinalesController extends Controller {


	private $escalas_val = [];

	/**
	 * Los periodos del año — **una consulta por petición, no una por alumno por
	 * asignatura**.
	 *
	 * Es la causa medida del §176: `PUT bolfinales/detailed-notas-year-group` daba
	 * **504 tras 60 s** en el grupo 97, y no era el tamaño —el grupo que revienta
	 * es más pequeño que el que responde—. Esta consulta estaba dentro del bucle de
	 * asignaturas, que a su vez está dentro del de alumnos, y **no depende ni del
	 * alumno ni de la asignatura: sólo del año**. Medido en la base de tests con un
	 * grupo de 37 alumnos × 10 asignaturas: **408 ejecuciones en una sola llamada**,
	 * de 3.763 consultas de la petición. Con esto, **una**.
	 *
	 * ## Devuelve COPIAS, y eso no es una precaución: es el arreglo
	 *
	 * `array_map(clone)` y no el array cacheado tal cual, porque **quien recibe
	 * estos objetos les escribe encima**: `asignaturasPerdidasDeAlumno` pone
	 * `$periodo->cantNotasPerdidas` en cada uno, y el bucle de alumnos pone
	 * `$periodoAlone->cant_perdidas`. Compartir los objetos haría que **todas las
	 * asignaturas mostraran la cuenta de la última**, y eso **no lo ve ninguna cota
	 * de consultas**: es un cambio de resultado con el mismo número de consultas.
	 *
	 * Lo fija `BoletinFinalConsultaInvarianteTest::test_cada_asignatura_perdida_conserva_su_propia_cuenta`,
	 * escrito **antes** que este método justamente para impedir la versión sin
	 * `clone` — que es la que sale sola al leer «saca la consulta del bucle».
	 *
	 * El `clone` es superficial y basta: son filas de `periodos`, o sea `stdClass`
	 * con escalares dentro.
	 *
	 * ## Y el memo vive en los `attributes` de la petición, no en una propiedad
	 *
	 * **La primera versión lo guardaba en `private array $periodosPorAnio` y estaba
	 * mal.** `Illuminate\Routing\Route::getController()` **memoiza la instancia del
	 * controlador** en el objeto `Route`, que vive en la colección del router: o sea
	 * que el controlador **sobrevive a la petición** en cualquier proceso que atienda
	 * más de una. Hoy en php-fpm cada petición es un proceso y no se nota; **en la
	 * suite sí**, y ahí se vio — el informe daba **0 consultas** en vez de 1 porque la
	 * pasada descartada ya había llenado el memo, y un memo de periodos que cruza
	 * peticiones sirve datos viejos en cuanto alguien edita un periodo.
	 *
	 * Los `attributes` de la petición son el sitio que este proyecto ya eligió para
	 * esto, y por la misma razón escrita: `User::fromToken()` guarda ahí el contexto
	 * **y no en una propiedad del servicio, que sobreviviría a la petición bajo
	 * Octane** ([02 §4](../../../../docs/migracion/02-plan-rendimiento.md)).
	 *
	 * Lo encontró un número que no cuadraba: **0 donde tenía que haber 1**, con dos
	 * medidas del mismo trabajo dando cosas distintas.
	 *
	 * `array<object>` y no `list<object>` en la anotación: `DB::select` devuelve
	 * `array` sin prometer claves consecutivas, así que larastan no puede probar
	 * que sea una lista y el nivel 7 lo dice. Prometer más de lo que se sabe es lo
	 * que hace que una anotación mienta.
	 *
	 * @return array<object>
	 */
	private function periodosDelAnio($year_id)
	{
		// El tope viene del cuerpo, así que entra en la clave: una misma petición no
		// lo cambia a mitad, pero dos años en la misma petición sí son dos consultas.
		$hasta 		= Request::input('periodo_a_calcular');
		$clave 		= 'bolfinales.periodos.'.$year_id.':'.($hasta ?: '');
		$peticion 	= Request::instance();

		if (! $peticion->attributes->has($clave)) {
			$peticion->attributes->set($clave, $hasta
				? DB::select('SELECT * FROM periodos WHERE year_id=? and numero<=? and deleted_at is null', [$year_id, $hasta])
				: DB::select('SELECT * FROM periodos WHERE year_id=? and deleted_at is null', [$year_id]));
		}

		return array_map(static fn ($periodo) => clone $periodo, $peticion->attributes->get($clave));
	}



	public function putDetailedNotasYearGroup($grupo_id)
	{
		$user = User::fromToken();

		$boletines = $this->detailedNotasGrupo($grupo_id, $user);

		//$grupo->alumnos = $alumnos;
		//$grupo->asignaturas = $asignaturas;
		//return (array)$grupo;

		return $boletines;


	}



	public function putDetailedNotasYear($grupo_id)
	{
		$user = User::fromToken();

		
		$requested_alumnos = '';

		if (Request::has('requested_alumnos')) {
			$requested_alumnos = Request::get('requested_alumnos');
		}

		$boletines = $this->detailedNotasGrupo($grupo_id, $user, $requested_alumnos);


		return $boletines;


	}

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
		
		
		if (Request::has('aumentar_contador')) {
			// **`==` quemaba un folio oficial por creer que le decian «si».** En PHP
			// cualquier cadena no vacia que no sea `'0'` es cierta, asi que la cadena
			// `"false"` -- que es lo que manda un cliente que cree estar diciendo «no
			// subas» -- entraba aqui y gastaba un numero. Es la misma comparacion laxa
			// que ya se corrigio DOCE LINEAS MAS ARRIBA en este fichero (`year_selected`,
			// donde `0 == 'true'` era cierto): se arreglo la de al lado porque dio un
			// sintoma visible, y esta solo gastaba un numero que nadie echa en falta.
			//
			// **El cambio es estrictamente asimetrico hacia el lado seguro, y ese es el
			// motivo por el que entra sin esperar a nadie.** `FILTER_VALIDATE_BOOLEAN`
			// quema con `true`, `1`, `'1'`, `'true'`, `'yes'`, `'on'`; deja de quemar con
			// `'false'`, `'0'`, `''`, `'si'` y cualquier otra cadena. **Todo lo que deja
			// de quemar, dejaba de deber quemarse, y no hay ni un solo valor que hoy no
			// queme y manana si.** En una cuenta de papel oficial la direccion irreversible
			// es quemar: un folio no quemado se quema despues, uno quemado no vuelve.
			//
			// Esto **no sustituye la cura del front** que pide la 05 §225 -- que tiene que
			// OMITIR la clave, no mandar `false` --: la respalda, porque las copias de
			// `myvc_front` desplegadas en los dieciseis colegios pueden ir a versiones
			// distintas y esta medicion no las ve.
			if (filter_var(Request::input('aumentar_contador'), FILTER_VALIDATE_BOOLEAN)) {
				// **Leer y escribir el consecutivo van en UNA transaccion, con `FOR UPDATE`
				// sobre la fila del year.** Sin eso son dos sentencias sueltas: dos
				// secretarias abriendo el "Certificado periodos" a la vez leen las dos 143 y
				// escriben las dos 144, y se llevan **dos folios oficiales con el mismo
				// consecutivo** (05 §195.2 y §225). Un numero saltado se justifica ante quien
				// reclama; uno repetido, no -- por eso esto no es un problema de coste.
				//
				// El patron es el mismo que la fase 3 de las definitivas dejo puesto en
				// `DefinitivasPeriodosController::putUpdate`: `DB::transaction` + `SELECT ...
				// FOR UPDATE` sobre la fila que se va a pisar. Se copia de ahi a proposito y
				// no se inventa otro.
				DB::transaction(function () {
					// Sigue siendo `DB::select(...)[0]` y no `selectOne`: si no hubiera year
					// `actual=1` esto falla igual que antes. Cambiarlo a un null-check haria
					// que el endpoint contestara 200 sin subir el contador, que es una
					// conducta nueva -- y arreglar la carrera no es el sitio donde estrenarla.
					//
					// El `ORDER BY id LIMIT 1` SI es nuevo, y hace falta para que `FOR UPDATE`
					// signifique algo: sin orden, dos peticiones que encontraran mas de un year
					// `actual=1` podrian bloquear filas distintas -- cada una la «suya»-- y el
					// bloqueo no las excluiria. Con orden fijo las dos piden la misma primero,
					// que es lo que convierte el bloqueo en exclusion. Es tambien lo que hace
					// `DefinitivasPeriodosController::putUpdate`, de donde viene el patron.
					// (Medido en la base de test: un solo year `actual=1`, asi que hoy elige la
					// misma fila que el `[0]` de antes; el orden esta por lo que garantiza
					// cuando eso no se cumpla, no por lo que cambia hoy.)
					$contador = DB::select('SELECT id, contador_certificados FROM years WHERE deleted_at is null and actual=1 ORDER BY id LIMIT 1 FOR UPDATE')[0];
					// La tabla years tiene la PK en `id`, no en `year_id`. El parametro ya era
					// $contador->id, solo la columna del WHERE estaba mal: el UPDATE lanzaba
					// "Unknown column 'year_id'" y devolvia 500. Solo se notaba en el
					// "Certificado periodos", porque es el unico que manda aumentar_contador.
					//
					// El cast a int no es cosmetico. years.contador_certificados es VARCHAR y en
					// varios clientes vale '' (cadena vacia), que YearsController copia de un year
					// al siguiente al crearlo, asi que se propaga desde el primer year. Desde PHP 8
					// ('' + 1) lanza TypeError: Unsupported operand types, no un warning, de modo
					// que el endpoint devolvia 500 antes incluso de ejecutar el UPDATE -- el array
					// de argumentos se evalua primero. (int)'' es 0, asi que el contador arranca
					// en 1 donde estaba vacio, y (int)'12' sigue siendo 12 donde ya tenia valor.
					DB::update('UPDATE years SET contador_certificados=? WHERE id=?', [(int)$contador->contador_certificados+1, $contador->id]);
				});
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
		
		// Las dos ramas viven ahora en periodosDelAnio(), que además cachea: era la
		// misma consulta que los bucles de más abajo repetían 407 veces.
		$year->periodos = $this->periodosDelAnio($user->year_id);

		// **El recuento de notas perdidas, UNA vez por grupo y no una por
		// (alumno x asignatura x periodo).** Ver 05 §210: de las 3.763 consultas
		// de esta petición, **2.602 —el 78%— eran estos dos bucles anidados**, y de
		// ahí salía el 504 de 60 s del grupo 105. Un grupo de 38 alumnos x 15
		// asignaturas x 4 periodos son 2.280 consultas que contestan lo que una
		// sola `GROUP BY` contesta entera.
		//
		// **No va en una propiedad de la clase, y eso no es estilo:** la §210
		// registró que un memo en propiedad privada **sobrevive a la petición**
		// porque `Route::getController()` memoiza la instancia, así que la segunda
		// petición leería el recuento de la primera. Va por parámetro.
		$perdidasDelGrupo = $this->perdidasPorAlumnoDelGrupo($grupo_id, $alumnos, $year->periodos);

		// **El segundo bucle anidado, y su consulta NO es la misma que la de
		// arriba** — filtra `deleted_at` en subunidades y unidades, y no une con
		// `matriculas`. Dos mapas y no uno, a propósito: fundirlos daría números
		// distintos de los de hoy, y este lote no puede cambiar la respuesta.
		// Eran 1.122 consultas de las 1.876 que quedaban.
		$perdidasPorDefinitiva = $this->perdidasPorDefinitivaDelGrupo($grupo_id, $alumnos);

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
			$this->definitivasMateriasXPeriodo($alumno, $grupo_id, $user->year_id, $year->periodos, $periodo_a_calcular, $user->si_recupera_materia_recup_indicador, $perdidasPorDefinitiva );

			
			
			$consulta = 'SELECT r.*, m.materia, m.alias, m.area_id FROM recuperacion_final r 
				INNER JOIN asignaturas a ON a.id=r.asignatura_id and a.deleted_at is null
				INNER JOIN materias m ON m.id=a.materia_id and m.deleted_at is null
				WHERE alumno_id=? and year=?';
				
			$alumno->recuperaciones = DB::select($consulta, [$alumno->alumno_id, $user->year]);

			$canti_recu = count($alumno->recuperaciones);
			for ($k=0; $k < $canti_recu; $k++) { 
				$recu = $alumno->recuperaciones[$k];
				
				$consulta = 'SELECT ar.* FROM areas ar 
					INNER JOIN materias m ON m.area_id=ar.id and m.deleted_at is null
					INNER JOIN asignaturas a ON a.materia_id=m.id and a.deleted_at is null
					WHERE ar.id=? and ar.deleted_at is null';
					
				$canti_asignaturas_en_area = count(DB::select($consulta, [$recu->area_id]));
				
				if ($canti_asignaturas_en_area > 0) {
					$recu->es_area = true;
	
					$alumno->cant_lost_areas = $alumno->cant_lost_areas - 1;
				}
			}

			
			$alumno->cant_lost_asig = $alumno->cant_lost_asig - count($alumno->recuperaciones);

	
			$asignaturas_perdidas = $this->asignaturasPerdidasDeAlumno($alumno, $grupo_id, $user->year_id, $perdidasDelGrupo, $year->periodos);

			if (count($asignaturas_perdidas) > 0) {
				
				$alumno->asignaturas_perdidas = $asignaturas_perdidas;
				$alumno->notas_perdidas_year = 0;
				
				// **La misma consulta que `$year->periodos`, una vez por alumno.**
				// Era la segunda mitad de la invariante: la primera estaba en el
				// bucle de asignaturas —408 veces— y ésta en el de alumnos, 38.
				// Idéntica en las dos ramas, así que se reutiliza la ya resuelta.
				//
				// **Con `clone`, por lo mismo que en `asignaturasPerdidasDeAlumno`:**
				// el bucle de abajo escribe `cant_perdidas` DENTRO de cada periodo,
				// así que compartir los objetos entre alumnos haría que todos
				// acumularan sobre la cuenta del anterior. Aquí el fallo sería peor
				// que allí —números crecientes, no repetidos— y tampoco lo vería
				// ninguna cota de consultas.
				$alumno->periodos_con_perdidas = array_map(fn ($p) => clone $p, $year->periodos);

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


		foreach ($alumnos as $alumno) {
			
			$alumno->puesto = Nota::puestoAlumno($alumno->promedio, $alumnos);
			
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


		return [$grupo, $year, $response_alumnos, $this->escalas_val];
	}

	/**
	 * @param  array<int, array<int, array<int, int>>>|null  $perdidasPorDefinitiva  ver perdidasPorDefinitivaDelGrupo()
	 */
	public function definitivasMateriasXPeriodo(&$alumno, $grupo_id, $year_id, $periodos, $per_calcular=null, $si_recupera_materia_recup_indicador=false, $perdidasPorDefinitiva=null)
	{
		$deEsteAlumno = $perdidasPorDefinitiva[(int) $alumno->alumno_id] ?? [];


		$alumno->asignaturas	= Grupo::detailed_materias($grupo_id);

		$alumno->promedio = 0;
		$alumno->cant_lost_asig = 0;
		$alumno->ausencias = 0;
		$alumno->tardanzas = 0;
		$alumno->total_creditos = 0;
		$alumno->notas_perdidas = 0;
		
		$sqlPeriodo = '';
		if ($per_calcular) {
			$sqlPeriodo = 'and nf.periodo<=:periodo';
		}
		
		
		
		foreach ($alumno->asignaturas as $asignatura) {

			$alumno->total_creditos += $asignatura->creditos;
						
			$consulta = 'SELECT nf.*, nf.nota as DefMateria, aus.cantidad_ausencia, tar.cantidad_tardanza
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
					
					// Cuántas notas tiene perdidas por cada definitiva, del mapa del
					// grupo. Era una consulta por (alumno x asignatura x definitiva):
					// 1.122 en una sola petición.
					//
					// **Un par sin fila es 0, y eso NO es un atajo**: `COUNT()`
					// devuelve siempre exactamente una fila, así que el original
					// entraba siempre en el `if` y asignaba el número — incluidos los
					// periodos ficticios con `periodo_id = -1`, que daban 0. Dejarlo
					// sin asignar cambiaría la respuesta.
					$definitiva->notas_perdidas = $deEsteAlumno[(int) $asignatura->asignatura_id][(int) $definitiva->periodo_id] ?? 0;

					$notas_perd += $definitiva->notas_perdidas;
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
		
		if (count($alumno->asignaturas) > 0) {
			$alumno->promedio = $alumno->promedio / count($alumno->asignaturas);
		}else{
			$alumno->promedio = 0;
		}
		

		$escala = $this->valoracion($alumno->promedio);
		if ($escala) {
			$alumno->desempenio = $escala->desempenio;
		}


		// Nota promedio de comportamiento
		// $per_calcular ya llega a este metodo; pasarlo limita el comportamiento a los
		// periodos con numero <= el elegido, igual que ya se hacia con year->periodos,
		// las definitivas y periodos_con_perdidas. Sin esto el "Certificado periodos"
		// mostraba los 4 periodos y promediaba sobre todo el year.
		$alumno->nota_comportamiento_year 	= NotaComportamiento::nota_promedio_year($alumno->alumno_id, $year_id, $per_calcular);
		$alumno->notas_comportamiento 		= NotaComportamiento::todas_year($alumno->alumno_id, $year_id, $per_calcular);
		
		$escala = $this->valoracion($alumno->nota_comportamiento_year);
		if ($escala) {
			$alumno->nota_comportamiento_year_desempenio = $escala->desempenio;
		}

		// Frases comportamiento
		for ($i=0, $canti = count($alumno->notas_comportamiento); $i < $canti; $i++) { 
			$nota = $alumno->notas_comportamiento[$i];

			$consulta = 'SELECT * FROM (
							SELECT d.id as definicion_id, d.comportamiento_id, d.frase_id, 
								f.frase, f.tipo_frase, f.year_id
							FROM definiciones_comportamiento d
							inner join frases f on d.frase_id=f.id and d.deleted_at is null 
							where d.comportamiento_id=:comportamiento1_id and f.deleted_at is null
						union
							select d2.id as definicion_id, d2.comportamiento_id, d2.frase_id, 
								d2.frase, null as tipo_frase, null as year_id
							from definiciones_comportamiento d2 where d2.deleted_at is null and d2.frase is not null                  
							and d2.comportamiento_id=:comportamiento2_id 
							
						) defi';

			$definiciones = DB::select($consulta, ['comportamiento1_id' => $nota->id, 'comportamiento2_id' => $nota->id]);
			
			$nota->definiciones = $definiciones;

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



	/**
	 * El recuento de notas perdidas de TODO el grupo, en una sola consulta.
	 *
	 * Sustituye a las 2.280 que hacía el bucle anidado —una por (alumno x
	 * asignatura x periodo)—, que eran **el 78% de las 3.763 consultas** de esta
	 * petición y de donde salía el **504 de 60 s** del grupo 105 (05 §210).
	 *
	 * **La consulta es la misma, agregada.** No se «mejoró» de paso: mismos
	 * `JOIN`, mismo `m.estado="MATR"` sin filtro de grupo, mismas tablas sin
	 * `deleted_at`. Lo único que cambia es `COUNT(DISTINCT n.id)` en vez de traer
	 * las filas y contarlas en PHP — y el `DISTINCT` **hace falta por lo mismo que
	 * lo hacía antes**: el `JOIN` con `matriculas` multiplica cuando un alumno
	 * tiene varias matrículas, y el original lo deduplicaba con `SELECT distinct`.
	 * Quitarlo cambiaría los números, que es justo lo que este lote no puede hacer.
	 *
	 * @param  array<int, object>  $alumnos
	 * @param  array<int, object>  $periodos
	 * @return array<int, array<int, array<int, int>>>  [alumno_id][asignatura_id][periodo_id] => perdidas
	 */
	private function perdidasPorAlumnoDelGrupo($grupo_id, $alumnos, $periodos)
	{
		$alumnoIds  = array_values(array_filter(array_map(fn ($a) => (int) ($a->alumno_id ?? 0), $alumnos)));
		$periodoIds = array_values(array_filter(array_map(fn ($p) => (int) ($p->id ?? 0), $periodos)));

		// Sin alumnos o sin periodos no hay nada que contar, y una `IN ()` vacía es
		// un error de sintaxis en MySQL — no una consulta que devuelve nada.
		if ($alumnoIds === [] || $periodoIds === []) {
			return [];
		}

		$huecosAlu = implode(',', array_fill(0, count($alumnoIds), '?'));
		$huecosPer = implode(',', array_fill(0, count($periodoIds), '?'));

		$filas = DB::select(
			'SELECT n.alumno_id, u.asignatura_id, u.periodo_id, COUNT(DISTINCT n.id) AS perdidas
			   FROM notas n, subunidades s, unidades u, asignaturas a, matriculas m
			  WHERE n.subunidad_id = s.id AND s.unidad_id = u.id
				AND u.asignatura_id = a.id AND a.grupo_id = ?
				AND m.alumno_id = n.alumno_id AND m.deleted_at IS NULL AND m.estado = "MATR"
				AND n.alumno_id IN ('.$huecosAlu.')
				AND u.periodo_id IN ('.$huecosPer.')
				AND n.nota < ?
			  GROUP BY n.alumno_id, u.asignatura_id, u.periodo_id',
			array_merge([$grupo_id], $alumnoIds, $periodoIds, [User::$nota_minima_aceptada])
		);

		$mapa = [];

		foreach ($filas as $fila) {
			$mapa[(int) $fila->alumno_id][(int) $fila->asignatura_id][(int) $fila->periodo_id] = (int) $fila->perdidas;
		}

		return $mapa;
	}

	/**
	 * El recuento de notas perdidas **por definitiva**, de todo el grupo, en una
	 * consulta.
	 *
	 * **Es un mapa distinto del de `perdidasPorAlumnoDelGrupo()` porque la
	 * consulta original es distinta**, aunque las dos cuenten «notas perdidas»:
	 * ésta filtra `deleted_at` en subunidades y unidades y **no une con
	 * `matriculas`**. Fundir las dos daría números distintos de los de hoy en las
	 * filas que difieren, y este lote no puede cambiar la respuesta.
	 *
	 * `COUNT(n.id)` y no `COUNT(DISTINCT n.id)`: aquí no hay `matriculas` que
	 * multiplique, y el original tampoco deduplicaba.
	 *
	 * @param  array<int, object>  $alumnos
	 * @return array<int, array<int, array<int, int>>>  [alumno_id][asignatura_id][periodo_id] => perdidas
	 */
	private function perdidasPorDefinitivaDelGrupo($grupo_id, $alumnos)
	{
		$alumnoIds = array_values(array_filter(array_map(fn ($a) => (int) ($a->alumno_id ?? 0), $alumnos)));

		if ($alumnoIds === []) {
			return [];
		}

		$huecos = implode(',', array_fill(0, count($alumnoIds), '?'));

		$filas = DB::select(
			'SELECT n.alumno_id, u.asignatura_id, u.periodo_id, COUNT(n.id) AS notas_perdidas
			   FROM notas n
			   INNER JOIN subunidades s ON s.id = n.subunidad_id AND s.deleted_at IS NULL
			   INNER JOIN unidades u ON u.id = s.unidad_id AND u.deleted_at IS NULL
			   INNER JOIN asignaturas a ON a.id = u.asignatura_id AND a.grupo_id = ?
			  WHERE n.nota < ? AND n.alumno_id IN ('.$huecos.')
			  GROUP BY n.alumno_id, u.asignatura_id, u.periodo_id',
			array_merge([$grupo_id, User::$nota_minima_aceptada], $alumnoIds)
		);

		$mapa = [];

		foreach ($filas as $fila) {
			$mapa[(int) $fila->alumno_id][(int) $fila->asignatura_id][(int) $fila->periodo_id] = (int) $fila->notas_perdidas;
		}

		return $mapa;
	}

	/**
	 * @param  array<int, array<int, array<int, int>>>  $perdidasDelGrupo  ver perdidasPorAlumnoDelGrupo()
	 * @param  array<int, object>  $periodosDelAnio
	 */
	public function asignaturasPerdidasDeAlumno($alumno, $grupo_id, $year_id, $perdidasDelGrupo = null, $periodosDelAnio = null)
	{
		$asignaturas	= Grupo::detailed_materias($grupo_id);

		$deEsteAlumno = $perdidasDelGrupo[(int) $alumno->alumno_id] ?? [];

		foreach ($asignaturas as $keyAsig => $asignatura) {
			// **`clone` y no el mismo objeto, y esto no es prudencia.** El bucle de
			// abajo escribe `cantNotasPerdidas` DENTRO del periodo, así que compartir
			// los objetos entre asignaturas haría que todas mostraran la cuenta de la
			// última. Antes no pasaba porque cada asignatura hacía su propio
			// `DB::select` y recibía objetos nuevos; al sacar la consulta del bucle,
			// el clon es lo que conserva ese comportamiento.
			//
			// No lo ve ninguna cota de consultas —mismo número de consultas,
			// resultado distinto— y por eso tiene su propio test, escrito antes de
			// tocar esto y verificado cayendo: `BoletinFinalConsultaInvarianteTest`.
			//
			// **Las dos ramas son de la fusión de `12` y `ad`, que sacaron esta misma
			// consulta del bucle por caminos distintos y las dos con test.** Cuando el
			// llamador ya los tiene resueltos —el camino del boletín— se clonan los
			// suyos; cuando llama alguien que no los pasa, `periodosDelAnio()` los
			// memoiza en la petición y devuelve clones igual. El parámetro es
			// opcional y el método es `public`, así que el segundo camino cubre a un
			// llamador que no los pase; **hoy no hay ninguno** — la única llamada es
			// la de la línea 281 y pasa los cinco argumentos.
			//
			// **Y el motivo que esta coordinación escribió aquí al fundir era falso:**
			// decía «así los llaman sus gemelos». No los llaman: `Boletines`,
			// `Boletines2`, `Boletines3`, `Editnota`, `Promovidos`,
			// `CertificadosPersona`, `NotasActuales` y el `BolfinalesController` de la
			// raíz **tienen cada uno su propia copia** del método dentro de su clase
			// —nueve definiciones, [05 §224](../../../../docs/migracion/05-codigo-muerto-y-roto.md)—.
			// Lo corrigió `8myvc-12` leyendo el comentario, y se arregla aquí y no en
			// un documento porque **la razón escrita al lado es lo que alguien va a
			// creer dentro de seis meses**: con la anterior, se habría puesto a buscar
			// llamadas de tres argumentos que no existen.
			$asignatura->periodos = $periodosDelAnio !== null
				? array_map(fn ($p) => clone $p, $periodosDelAnio)
				: $this->periodosDelAnio($year_id);

			$asignatura->cantTotal = 0;

			foreach ($asignatura->periodos as $keyPer => $periodo) {

				// Del mapa del grupo, no de una consulta por periodo. Un par sin
				// fila en el `GROUP BY` es cero perdidas, que es lo mismo que
				// devolvía `count()` sobre un resultado vacío.
				$periodo->cantNotasPerdidas = $deEsteAlumno[(int) $asignatura->asignatura_id][(int) $periodo->id] ?? 0;

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

			$asignatura->unidades = Unidad::deAsignatura($asignatura->asignatura_id, $periodo_id);

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



	
	
	/**
	 * El consecutivo que va impreso en el certificado, fijado a mano.
	 *
	 * Antes esto era **una linea sin comprobar nada**: metia
	 * `Request::input('contador')` en la columna y contestaba 200. Mandarle
	 * `'no soy un numero'` dejaba eso escrito en el consecutivo del colegio, y de
	 * ahi sale el siguiente certificado -- `(int)'no soy un numero'` es 0, o sea que
	 * la numeracion oficial se reiniciaba en 1 (05 §195.4 y §225).
	 *
	 * `contador` es **VARCHAR en el esquema**, asi que la base no lo iba a parar:
	 * la comprobacion tiene que estar aqui.
	 */
	public function putCambiarContadorCertificados()
	{
		$contador = $this->consecutivoValidado();

		DB::update('UPDATE years SET contador_certificados=? WHERE actual=1 and deleted_at is null', [ $contador ]);

		return 'Cambiado';
	}

	/**
	 * La misma puerta sobre la otra columna, y **no la habia nombrado nadie**: la
	 * lista de la manana habla solo del de certificados. Es la pregunta «¿quien mas
	 * hace esto mismo?» aplicada al sitio donde se encontro, y la respuesta no era
	 * «uno» (05 §225).
	 */
	public function putCambiarContadorFolios()
	{
		$contador = $this->consecutivoValidado();

		DB::update('UPDATE years SET contador_folios=? WHERE actual=1 and deleted_at is null', [ $contador ]);

		return 'Cambiado';
	}

	/**
	 * El `contador` del cuerpo, o **422** si no es un consecutivo.
	 *
	 * 422 y no 400, que es lo que devuelve el legacy de al lado: en codigo nuevo van
	 * los codigos correctos. Y **aborta antes de escribir**, porque las dos mitades
	 * se pueden cumplir por separado -- un 422 que igualmente escribe deja el
	 * consecutivo roto y ademas miente.
	 *
	 * ## Por que `^\d+$` y no `FILTER_VALIDATE_INT`, que era lo primero que se puso
	 *
	 * Porque **`filter_var('007', FILTER_VALIDATE_INT)` es `false`**, y `'007'` es
	 * exactamente lo que manda la pantalla: `certificadoEstudioDir.html` es un
	 * `<input ng-model="year.contador_certificados">` **sin `type="number"`**, o sea
	 * que AngularJS manda **la cadena tal cual la trajo el backend**, y el relleno
	 * esta ahi: en `simonbolivar_testing_e0`, **7 de los 8 years vivos** llevan ceros
	 * a la izquierda (`007`, `021`, `022`, `037`, `044`, `045`, `060`) y el octavo es
	 * el actual, que solo se libra por haber pasado de tres digitos. Validar con
	 * `FILTER_VALIDATE_INT` habria contestado **422 a la pantalla que hoy funciona**
	 * en todos los colegios con relleno -- una validacion que rompe el caso bueno.
	 *
	 * `^\d+$` dice las tres cosas a la vez y sin ese efecto: **digitos**, **entero**
	 * y **no negativo** -- un negativo no existe en un talonario, y `'-1'` no casa.
	 * De paso rechaza lo que `is_numeric` habria dejado pasar (`'1.5'`, `'1e3'`), que
	 * tampoco son consecutivos de papel oficial.
	 *
	 * Se admite **0** (y `'000'`): es como arranca un colegio nuevo, y
	 * `YearsController` lo copia de un year al siguiente.
	 *
	 * ## Y por que devuelve la cadena y no el entero
	 *
	 * La columna es `VARCHAR` y el relleno a tres digitos **es la convencion**. Que
	 * este metodo devolviera un `int` convertiria `'007'` en `'7'` **en un sitio
	 * donde hoy no pasa**, y eso es cambiar el numero impreso en un papel oficial:
	 * decision de formato, no de validacion. Va anotada en el documento del lote.
	 */
	private function consecutivoValidado(): string
	{
		$contador = Request::input('contador');

		// El bool va aparte: PHP castea `true` a `'1'`, asi que sin esto un cuerpo con
		// `contador: true` fijaria el consecutivo del colegio en 1.
		if (is_bool($contador) || is_array($contador) || $contador === null) {
			abort(422, 'El consecutivo debe ser un numero entero.');
		}

		$contador = trim((string) $contador);

		if (! preg_match('/^\d+$/', $contador)) {
			abort(422, 'El consecutivo debe ser un numero entero no negativo.');
		}

		return $contador;
	}




}