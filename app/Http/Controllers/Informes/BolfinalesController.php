<?php namespace App\Http\Controllers\Informes;


use App\Http\Controllers\Controller;


use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\DB;

use App\User;
use App\Services\Auditoria;
use App\Services\BoletinIndependiente;
use App\Support\Autoriza;
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
					$contador = DB::select('SELECT id, contador_certificados, usa_consecutivo_certificados FROM years WHERE deleted_at is null and actual=1 ORDER BY id LIMIT 1 FOR UPDATE')[0];

					/*
					 * **El colegio que no numera sus constancias no quema nada.** Decision de
					 * Joseth del 26 ago 2026: el consecutivo pasa a ser opcional por colegio
					 * (docs/migracion/21-certificados-y-folios.md).
					 *
					 * **El interruptor no estrena la conducta: le pone nombre a la que habia.**
					 * El front ya ocultaba el «No.» cuando `contador_certificados` estaba vacio
					 * --`hidden-print` sobre `.length == 0`--, y la migracion deriva el
					 * interruptor de justo eso, colegio a colegio. Lo que SI cambia, y es el
					 * arreglo: hasta hoy un colegio que no imprimia el numero **seguia
					 * gastandolo** en cada apertura, o sea que su contador subia solo y nadie lo
					 * miraba nunca.
					 *
					 * Va DENTRO de la transaccion, despues del `FOR UPDATE`, y no fuera: si se
					 * mirara antes, dos peticiones podrian leer el interruptor a la vez que
					 * alguien lo apaga. Cuesta lo mismo -- la fila ya esta leida.
					 */
					if (! $contador->usa_consecutivo_certificados) {
						return;
					}
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
					$anterior = $contador->contador_certificados;
					$nuevo    = (int)$anterior + 1;

					DB::update('UPDATE years SET contador_certificados=? WHERE id=?', [$nuevo, $contador->id]);

					// **El rastro de la quema, que es lo que hoy no existe en ninguna parte.**
					//
					// La 05 §231 fue a restar *contador - certificados emitidos* y **no encontro
					// minuendo**: ninguna tabla guarda un certificado emitido -- `config_certificados`
					// es maquetacion --, asi que **un numero quemado por abrir la pantalla es
					// indistinguible de uno emitido**, dos certificados con el mismo consecutivo no
					// se detectan despues, y *«¿cuantos emitimos este anio y a quien?»* no tiene
					// respuesta ni con acceso total a la base. Eso es peor que la carrera de la §225,
					// que esta linea de arriba ya cierra.
					//
					// Esta linea no la contesta entera -- para eso hace falta la tabla de emitidos,
					// que es decision de Joseth -- pero **separa las dos mitades de la pregunta**:
					// desde hoy queda escrito **quien, cuando, desde donde y de que numero a cual**
					// cada vez que se quema uno. A lo que sigue sin contestar es a quien se le
					// entrego el papel.
					//
					// Va **dentro de la transaccion** a proposito: `Auditoria` no abre ninguna suya,
					// asi que la linea entra en esta. Si el incremento se deshace, el rastro se
					// deshace con el -- un rastro de algo que no ocurrio es peor que ninguno.
					//
					// `valor_anterior` va **crudo** y `valor_nuevo` va como entero, que es
					// exactamente lo que paso: la columna es VARCHAR y el `(int)` **pierde el
					// relleno de ceros** (`'007'` -> `8`). Es la §6.1 de `noche-2026-08-25/cert-1.md`,
					// que no se toco porque es formato del papel y no un fallo; el rastro lo deja ver
					// en vez de taparlo escribiendo los dos igual.
					Auditoria::registrar()
						->editar('year_config', (int) $contador->id)
						->en(year: (int) $contador->id)
						->de($anterior)
						->a($nuevo)
						->resumen('Quemo un consecutivo de certificado: '.$anterior.' -> '.$nuevo)
						->guardar();
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
						
			$consulta = 'SELECT nf.*, CAST(nf.nota AS DOUBLE) AS nota, CAST(nf.nota AS DOUBLE) as DefMateria, aus.cantidad_ausencia, tar.cantidad_tardanza
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
	 * ## El alcance va al `WHERE` y correlacionado, y las dos mitades están medidas
	 *
	 * Esta consulta abarca **muchos alumnos y muchos periodos a la vez**
	 * (`n.alumno_id IN (…)`, `u.periodo_id IN (…)`), y la marca de boletín
	 * independiente es **por periodo**: un alumno puede ir aparte en el 3 y con el
	 * grupo en el 2. Un alcance resuelto fuera y bindeado una vez le daría a los
	 * demás periodos el del equivocado, y **no hay ningún error que lo señale**: no
	 * falta una fila ni sobra otra — salen las unidades de otro boletín. Por eso
	 * `alcanceCorrelacionado()`, que correlaciona por `u.periodo_id`. Lo fija
	 * `AlcanceCorrelacionadoPorPeriodoTest`.
	 *
	 * **Y no `JOIN_ESTADO`, aunque `matriculas m` esté en el ámbito.** El `FROM` de
	 * aquí es una lista de comas, y en MySQL la coma tiene **menos precedencia que
	 * `JOIN`**: el `ON` de un `LEFT JOIN` pegado detrás no alcanza a nombrar `u`.
	 * Medido contra el esquema real, no supuesto: `1054 Unknown column
	 * 'u.periodo_id' in 'on clause'` — o sea un 500, no una fila de más.
	 *
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
				AND u.alumno_id <=> '.BoletinIndependiente::alcanceCorrelacionado('n.alumno_id', 'u').'
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
	 * ## El alcance, correlacionado por el periodo de la unidad
	 *
	 * Es **más ancha aún que la de `perdidasPorAlumnoDelGrupo()`**: muchos alumnos
	 * (`n.alumno_id IN (…)`) y **ni siquiera filtra periodo**, así que abarca el año
	 * entero. Como la marca es por periodo, un alcance bindeado una sola vez
	 * repartiría el de un periodo a los cuatro sin ningún error que lo señalara;
	 * `alcanceCorrelacionado()` lo resuelve dentro, por `u.periodo_id`.
	 *
	 * Aquí no hay `matriculas` en el ámbito, así que `JOIN_ESTADO` no era ni una
	 * opción: ésta es la forma de la subconsulta correlacionada y la única que cabe.
	 *
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
				AND u.alumno_id <=> '.BoletinIndependiente::alcanceCorrelacionado('n.alumno_id', 'u').'
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

		$year = $this->yearDelConsecutivo('contador_certificados');

		$this->exigirQueElColegioLoUse($year, 'certificados');

		DB::update('UPDATE years SET contador_certificados=? WHERE actual=1 and deleted_at is null', [ $contador ]);

		$this->anotarElConsecutivo($year, 'certificados', $contador);

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

		$year = $this->yearDelConsecutivo('contador_folios');

		$this->exigirQueElColegioLoUse($year, 'folios');

		DB::update('UPDATE years SET contador_folios=? WHERE actual=1 and deleted_at is null', [ $contador ]);

		$this->anotarElConsecutivo($year, 'folios', $contador);

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
		/*
		 * **Fijar el consecutivo del colegio es de secretaria.** Decision de Joseth del
		 * 26 ago 2026; estaba escrita como un si o un no en `noche-2026-08-25/cert-1.md` §5
		 * y es la unica de las cuatro consecuencias de la 05 §195 que faltaba.
		 *
		 * Hasta hoy los dos endpoints llevaban solo `auth.personal`, o sea que **cualquiera
		 * del personal docente podia fijar el numero que va impreso en un papel oficial**.
		 * Restringirlo **le quita un control a alguien que hoy lo tiene delante** -- y por
		 * eso no entro con el resto del arreglo: ahi no habia asimetria y la decision era
		 * suya, a diferencia del `FILTER_VALIDATE_BOOLEAN` de la quema, que solo podia mover
		 * el resultado hacia el lado recuperable.
		 *
		 * **No hace falta guard nuevo ni permiso nuevo**, y eso es lo que la hace barata:
		 * `Autoriza::esAdministrativo()` -- superusuario o rol `Secretario` -- ya es
		 * literalmente *«la secretaria administra la estructura del colegio»*. Va aqui y no
		 * en las rutas **porque cubre los dos endpoints a la vez**: los dos pasan por este
		 * metodo, asi que no puede arreglarse uno y olvidarse el otro -- que es como
		 * `cambiar-contador-folios` se habia quedado sin nombrar hasta la §225.
		 *
		 * Y no se aprovecha para mover ninguna otra llamada de `esAdministrativo`: la regla
		 * de la casa es que **crear un rol no regala permisos**.
		 *
		 * **A quien se le nota, medido en los cuatro clientes** (8.351 ficheros de once
		 * arboles; poblacion en el documento del lote): `cambiar-contador-certificados` lo
		 * llaman dos pantallas, las dos de `myvc_front` -- la vieja
		 * (`certificadoEstudioDir.html`, un `<input>` con `ng-change`) y `app2`
		 * (`certificados-estudio.ts`) --, y **ninguna de las dos esconde el control por rol**,
		 * asi que un docente que hoy escriba ahi vera «Contador no guardado» en vez de
		 * escribir. `cambiar-contador-folios` **no lo llama nadie vivo**: el «Folio» de la
		 * pantalla vieja escribe `alumnos/guardar-valor` sobre `nro_folio`, que es otra cosa.
		 * `myvc_front_2` y `myvc_flutter`: cero sitios.
		 */
		Autoriza::exigir(Autoriza::esAdministrativo(User::fromToken()),
			'Fijar el consecutivo del colegio es de secretaria.');

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

	/**
	 * El year `actual` y **el valor que el consecutivo tenia antes de pisarlo**.
	 *
	 * Se lee antes del `UPDATE` porque `valor_anterior` no se puede reconstruir despues:
	 * el `UPDATE` ya lo piso y **no queda en ningun otro sitio** (05 §231 -- ninguna tabla
	 * guarda un certificado emitido).
	 *
	 * `ORDER BY id LIMIT 1` y no `[0]` suelto por lo mismo que el bloque de la quema: fija
	 * **cual** fila se nombra si algun colegio tuviera mas de un year `actual=1`. Y ahi hay
	 * un limite que conviene decir en vez de esconder: el `UPDATE` de abajo **no lleva
	 * LIMIT**, asi que en ese caso escribiria en todas y esta linea nombraria una. En la
	 * base de tests hay **un solo year `actual=1`**, que es donde esto se midio.
	 *
	 * **La columna sale de esta lista, no del parametro.** Es la 05 §233: diez sitios de
	 * `app/` meten una variable como nombre de columna en un SQL y los diez son seguros
	 * hoy por cinco mecanismos distintos, **ninguno de los cuales es `ColumnaSegura`**, la
	 * clase que existe para eso. Aqui el mecanismo es el `match` -- la proteccion vive en
	 * estas dos lineas y **no** en la del `SELECT` --, y queda escrito porque esa § pedia
	 * exactamente eso: que el dia que alguien lea la linea sepa donde mirar.
	 */
	private function yearDelConsecutivo(string $columna)
	{
		$sql = match ($columna) {
			'contador_certificados' => 'SELECT id, contador_certificados AS valor, usa_consecutivo_certificados AS usa FROM years WHERE actual=1 and deleted_at is null ORDER BY id LIMIT 1',
			'contador_folios'       => 'SELECT id, contador_folios AS valor, usa_folio_certificados AS usa FROM years WHERE actual=1 and deleted_at is null ORDER BY id LIMIT 1',
			// El `default` no es ceremonia de larastan: **dice en voz alta lo que la lista
			// significa**. Una columna que no este aqui no se lee -- no se cuela en el SQL
			// y no devuelve null en silencio, que seria dejar la escritura sin rastro sin
			// que fallara nada. Hoy es inalcanzable: los dos llamadores pasan literales.
			default => throw new \LogicException('Columna de consecutivo no permitida: '.$columna),
		};

		return DB::selectOne($sql);
	}

	/**
	 * **409 si este colegio no lleva ese contador.**
	 *
	 * Decision de Joseth del 26 ago 2026: los contadores del certificado son **opcionales
	 * por colegio** (docs/migracion/21-certificados-y-folios.md). Si el interruptor esta
	 * apagado, el numero no se imprime en el papel, asi que fijarlo no significa nada --
	 * y dejarlo pasar guardaria un valor que nadie va a ver y que el dia que se encienda
	 * el interruptor apareceria impreso sin que nadie lo pusiera ahi a proposito.
	 *
	 * **409 y no 403**: no es que quien llama no pueda, es que **la operacion no aplica en
	 * este colegio**. Y no es 422 --el de al lado, para un contador que no es un numero--
	 * porque el cuerpo puede estar perfectamente bien: lo que no encaja es el estado del
	 * sistema, que es literalmente lo que significa un conflicto.
	 *
	 * **Sin year `actual=1` no aborta**, y es a proposito: ahi el `UPDATE` no escribe nada
	 * y quien decide que hacer con eso es la conducta de siempre, no este metodo nuevo.
	 *
	 * @param  object|null  $year  la fila leida por `yearDelConsecutivo()`
	 */
	private function exigirQueElColegioLoUse($year, string $cual): void
	{
		if ($year !== null && ! $year->usa) {
			abort(409, 'Este colegio no lleva contador de '.$cual.' en sus certificados.');
		}
	}

	/**
	 * El rastro de haber fijado un consecutivo a mano.
	 *
	 * Hasta hoy esto **no escribia en ninguna bitacora**: `putCambiarContadorCertificados`
	 * no esta entre los ficheros que auditan, asi que *«el consecutivo del colegio esta en
	 * 500 y ayer estaba en 143»* no tenia a quien preguntarle. Y no es un detalle de
	 * higiene: el unico rastro de que se emitio un certificado **es que un contador subio**
	 * (05 §231), o sea que quien mueve el contador a mano borra la unica cuenta que hay.
	 *
	 * Va por `Auditoria` y no por `bitacoras` porque es **el unico escritor** del rastro
	 * nuevo (18 §4) y porque los cinco campos que aqui importan --quien, cuando, desde
	 * donde, que ruta y de que valor a cual-- los resuelve el, que es justo lo que cada
	 * sitio decidia distinto.
	 *
	 * **Sin year `actual=1` no se anota nada, y es correcto**: el `UPDATE` tampoco escribio
	 * ninguna fila. Un rastro de un cambio que no ocurrio es peor que no tenerlo.
	 *
	 * @param  object|null  $year  la fila leida ANTES del `UPDATE`
	 */
	private function anotarElConsecutivo($year, string $cual, string $contador): void
	{
		if ($year === null) {
			return;
		}

		Auditoria::registrar()
			->editar('year_config', (int) $year->id)
			->en(year: (int) $year->id)
			->de($year->valor)
			->a($contador)
			->resumen('Fijo a mano el contador de '.$cual.': '.$year->valor.' -> '.$contador)
			->guardar();
	}
}