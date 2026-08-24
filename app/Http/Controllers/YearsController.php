<?php namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;

use App\User;
use App\Models\Year;
use App\Services\Auditoria;
use App\Support\Autoriza;
use App\Models\Periodo;
use App\Models\ConfigCertificado;
use App\Models\ImageModel;
use App\Models\Grupo;
use App\Models\Asignatura;
use App\Models\EscalaDeValoracion;
use App\Models\Frase;
use App\Models\Unidad;
use Carbon\Carbon;
use App\Support\ColumnaSegura;


class YearsController extends Controller {

	public function getIndex()
	{
		$user = User::fromToken();

		$consulta 	= 'SELECT y.*, i.nombre as logo FROM years y left join images i ON i.id=y.logo_id and i.deleted_at is null WHERE y.deleted_at is null';
		$years 		= DB::select($consulta);

		foreach ($years as $year) {
			$consulta 			= 'SELECT * FROM periodos WHERE year_id=? and deleted_at is null';
			$year->periodos 	= DB::select($consulta, [$year->id]);
		}

		return $years;
	}


	public function getColegio()
	{
		$user = User::fromToken();

		$consulta = 'SELECT * FROM years WHERE deleted_at is null';
		$years    = DB::select($consulta);


		foreach ($years as $year) {
			$consulta       = 'SELECT * FROM periodos WHERE year_id=? and deleted_at is null';
			$year->periodos = DB::select($consulta, [$year->id]);

			$consulta      = 'SELECT * FROM escalas_de_valoracion WHERE year_id=? and deleted_at is null order by orden asc';
			$year->escalas = DB::select($consulta, [$year->id]);
		}

		$consulta = 'SELECT * FROM config_certificados';
		$certif   = DB::select($consulta);

		$consulta = 'SELECT * FROM images WHERE user_id=? and publica=true';
		$imagenes = DB::select($consulta, [$user->user_id]);



		$result = ['years' => $years, 'certificados' => $certif, 'imagenes' => $imagenes];

		return $result;
	}


	public function postStore()
	{
		$user = User::fromToken();

		$year = new Year;

		$year->year                   = Request::input('year');
		$year->nombre_colegio         = Request::input('nombre_colegio');
		$year->abrev_colegio          = Request::input('abrev_colegio');
		$year->nota_minima_aceptada   = Request::input('nota_minima_aceptada');
		$year->resolucion             = Request::input('resolucion');
		$year->codigo_dane            = Request::input('codigo_dane');
		$year->encabezado_certificado = Request::input('encabezado_certificado');

		$actual_pedido  = (bool) Request::input('actual');
		$year->actual   = Request::input('actual');
		$year->telefono = Request::input('telefono');
		$year->celular  = Request::input('celular');
		
		$year->unidad_displayname      = Request::input('unidad_displayname');
		$year->unidades_displayname    = Request::input('unidades_displayname');
		$year->genero_unidad           = Request::input('genero_unidad');
		$year->subunidad_displayname   = Request::input('subunidad_displayname');
		$year->subunidades_displayname = Request::input('subunidades_displayname');
		$year->genero_subunidad        = Request::input('genero_subunidad');
		
		$year->website               = Request::input('website');
		$year->website_myvc          = Request::input('website_myvc');
		$year->alumnos_can_see_notas = Request::input('alumnos_can_see_notas');

		$year->save();

		$year_id_nuevo = $year->id;

		if ($year->actual) {
			Year::where('actual', true)->update(['actual'=>false]);
		}

		$year 				= Year::find($year_id_nuevo);
		// El mismo `= 1` de putSetActual, y con el mismo efecto: crear un año
		// pidiendo `actual: false` apagaba a los demás y encendía éste igual. El
		// front manda siempre `actual: true` (`YearsCtrl.fixControles`), así que
		// esto no cambia lo que hace la pantalla; cierra el segundo camino por el
		// que aparecen dos años actuales.
		$year->actual 		= $actual_pedido ? 1 : 0;
		$year->created_by 	= $user->user_id;
		$year->save();

		// Creamos un periodo
		DB::insert('INSERT INTO periodos(numero, actual, year_id) VALUES(1, 1, ?)', [$year->id]);

		// NECESITARÉ MUCHO DEL AÑO ANTERIOR
		$year_ante = $year->year - 1;
		$pasado = Year::where('year', $year_ante)->first();

		if ($pasado) {
			$year->ciudad_id                     = $pasado->ciudad_id;
			$year->logo_id                       = $pasado->logo_id;
			$year->rector_id                     = $pasado->rector_id;
			$year->secretario_id                 = $pasado->secretario_id;
			$year->tesorero_id                   = $pasado->tesorero_id;
			$year->coordinador_academico_id      = $pasado->coordinador_academico_id;
			$year->coordinador_disciplinario_id  = $pasado->coordinador_disciplinario_id;
			$year->capellan_id                   = $pasado->capellan_id;
			$year->psicorientador_id             = $pasado->psicorientador_id;
			$year->config_certificado_estudio_id = $pasado->config_certificado_estudio_id;
			$year->cant_areas_pierde_year        = $pasado->cant_areas_pierde_year;
			$year->cant_asignatura_pierde_year   = $pasado->cant_asignatura_pierde_year;
			$year->contador_certificados         = $pasado->contador_certificados;
			$year->contador_folios               = $pasado->contador_folios;
			$year->nota_minima_aceptada          = $pasado->nota_minima_aceptada;
			$year->resolucion                    = $pasado->resolucion;
			$year->codigo_dane                   = $pasado->codigo_dane;
			$year->encabezado_certificado        = $pasado->encabezado_certificado;
			$year->compromiso_familiar_label     = $pasado->compromiso_familiar_label;
			$year->mensaje_aprobo_con_pendientes = $pasado->mensaje_aprobo_con_pendientes;
			$year->minu_hora_clase     		 	 = $pasado->minu_hora_clase;
			$year->mostrar_nota_comport_boletin  = $pasado->mostrar_nota_comport_boletin;
			$year->mostrar_puesto_boletin  		 = $pasado->mostrar_puesto_boletin;
			$year->msg_when_students_blocked  	 = $pasado->msg_when_students_blocked;
			$year->profes_can_edit_alumnos  	 = $pasado->profes_can_edit_alumnos;
			$year->puestos_alfabeticamente  	 = $pasado->puestos_alfabeticamente;
			$year->show_fortaleza_bol  	 		 = $pasado->show_fortaleza_bol;
			$year->show_subasignaturas_en_finales = $pasado->show_subasignaturas_en_finales;
			$year->si_recupera_materia_recup_indicador = $pasado->si_recupera_materia_recup_indicador;
			$year->solo_escalas_valorativas 	 = $pasado->solo_escalas_valorativas;
			$year->year_pasado_en_bol 			 = $pasado->year_pasado_en_bol;
			$year->titulo_rector 				 = $pasado->titulo_rector;

			$year->save();
			
			/// COPIAREMOS LAS ESCALAS DE VALORACIÓN
			$escalas_ant = EscalaDeValoracion::where('year_id', $pasado->id)->get();

			foreach ($escalas_ant as $key => $escalas) {
				$newEsc                    = new EscalaDeValoracion;
				$newEsc->desempenio        = $escalas->desempenio;
				$newEsc->valoracion        = $escalas->valoracion;
				$newEsc->porc_inicial      = $escalas->porc_inicial;
				$newEsc->porc_final        = $escalas->porc_final;
				$newEsc->descripcion       = $escalas->descripcion;
				$newEsc->orden             = $escalas->orden;
				$newEsc->perdido           = $escalas->perdido;
				$newEsc->year_id           = $year->id;
				$newEsc->icono_infantil    = $escalas->icono_infantil;
				$newEsc->icono_adolescente = $escalas->icono_adolescente;
				$newEsc->save();
			}

			/// COPIAREMOS LAS FRASES
			$frases_ant = Frase::where('year_id', $pasado->id)->get();

			foreach ($frases_ant as $key => $frases) {
				$newFra = new Frase;
				$newFra->frase 			= $frases->frase;
				$newFra->tipo_frase 	= $frases->tipo_frase;
				$newFra->year_id 		= $year->id;
				$newFra->save();
			}

			/// COPIAREMOS LAS UNIDADES POR DEFECTO
			$unidades_ant = DB::select('SELECT * FROM unidades_por_defecto WHERE year_id=? AND deleted_at is null;', [$pasado->id]);

			foreach ($unidades_ant as $key => $unidad) {
				DB::insert('INSERT INTO unidades_por_defecto(definicion, porcentaje, year_id, obligatoria, orden, created_by) VALUES(?,?,?,?,?,?)', 
					[$unidad->definicion, $unidad->porcentaje, $year->id, $unidad->obligatoria, $unidad->orden, $unidad->created_by]);
			}

			/// COPIAREMOS LAS CONFIGURACIONES DE DISCIPLINA Y ORDINALES
			$dis_configuraciones = DB::select('SELECT * FROM dis_configuraciones WHERE year_id=? AND deleted_at is null;', [$pasado->id]);
			if (count($dis_configuraciones) > 0) {
				$dis = $dis_configuraciones[0];
				
				// Los dos `INSERT` de aquí abajo eran los únicos de las cuatro escrituras
				// que hay en estas dos tablas que **no** ponían fecha: los otros tres
				// —`GruposController:265` y los dos de `OrdinalesController`— sí. Como
				// esto sólo corre al crear un año, la fila mal nacía **una vez por año y
				// por colegio**, y las que hay ya escritas están medidas: en el seed,
				// **14 de 17 ordinales y 7 de 9 configuraciones** vienen con `created_at`
				// nulo — o sea todos los años creados por esta ruta, del 3 en adelante.
				//
				// Hoy no lo lee nadie: los listados de disciplina ordenan por `ordinal`,
				// no por fecha, y ningún cliente pide esa columna. Se arregla porque la
				// pregunta «cuándo apareció esta fila» es la que no se puede contestar
				// después, y porque tres de cuatro sitios ya lo hacían bien.
				$now_dis = Carbon::now('America/Bogota');

				DB::insert('INSERT INTO dis_configuraciones(year_id, reinicia_por_periodo, falta_tipo1_displayname, faltas_tipo1_displayname, genero_falta_t1, falta_tipo2_displayname, faltas_tipo2_displayname, genero_falta_t2, 
					falta_tipo3_displayname, faltas_tipo3_displayname, genero_falta_t3, cant_tard_to_ft1, cant_ft1_to_ft2, cant_ft2_to_ft3,
					nombre_col1, nombre_col2, nombre_col3, definicion_ft1, definicion_ft2, definicion_ft3, created_at, updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', 
					[ $year->id, $dis->reinicia_por_periodo, $dis->falta_tipo1_displayname, $dis->faltas_tipo1_displayname, $dis->genero_falta_t1, $dis->falta_tipo2_displayname, $dis->faltas_tipo2_displayname, $dis->genero_falta_t2, 
					$dis->falta_tipo3_displayname, $dis->faltas_tipo3_displayname, $dis->genero_falta_t3, $dis->cant_tard_to_ft1, $dis->cant_ft1_to_ft2, $dis->cant_ft2_to_ft3, 
					$dis->nombre_col1, $dis->nombre_col2, $dis->nombre_col3, $dis->definicion_ft1, $dis->definicion_ft2, $dis->definicion_ft3, $now_dis, $now_dis ]);
					
				$dis_ordinales = DB::select('SELECT * FROM dis_ordinales WHERE year_id=? AND deleted_at is null;', [$pasado->id]);
					
				foreach ($dis_ordinales as $key => $ord) {
					DB::insert('INSERT INTO dis_ordinales(year_id, tipo, ordinal, descripcion, pagina, created_at, updated_at) VALUES(?,?,?,?,?,?,?)', 
						[ $year->id, $ord->tipo, $ord->ordinal, $ord->descripcion, $ord->pagina, $now_dis, $now_dis ]);
				}
			}
			
			/// AHORA COPIAMOS LOS GRUPOS Y ASIGNATURAS DEL AÑO PASADO AL NUEVO AÑO.
			$grupos_ant = Grupo::where('year_id', $pasado->id)->get();
			
			foreach ($grupos_ant as $key => $grupo) {
				$newGr = new Grupo;
				$newGr->nombre 			= $grupo->nombre;
				$newGr->abrev 			= $grupo->abrev;
				$newGr->year_id 		= $year->id;
				$newGr->grado_id 		= $grupo->grado_id;
				$newGr->valormatricula 	= $grupo->valormatricula;
				$newGr->valorpension 	= $grupo->valorpension;
				$newGr->orden 			= $grupo->orden;
				$newGr->cupo 			= $grupo->cupo;
				$newGr->caritas 		= $grupo->caritas;
				$newGr->save();

				$asigs_ant = Asignatura::where('grupo_id', $grupo->id)->get();
				
				for ($i=0; $i < count($asigs_ant); $i++) { 
					$newAsig = new Asignatura;
					$newAsig->materia_id 	= $asigs_ant[$i]->materia_id;
					$newAsig->grupo_id 		= $newGr->id;
					$newAsig->creditos 		= $asigs_ant[$i]->creditos;
					$newAsig->orden 		= $asigs_ant[$i]->orden;
					$newAsig->save();
				}
				$grupo->asigs_ant = $asigs_ant;
			}
			$year->grupos_ant = $grupos_ant;
		}
		return $year;
	}


	public function putUseractive($year_id)
	{
		$user = User::fromToken();
		$usuario = User::findOrFail($user->user_id);
		$peri = Periodo::where('year_id', $year_id)->where('numero', $user->numero_periodo)->first();

		if ($peri) {
			$usuario->periodo_id = $peri->id;
		}else{
			$peris = Periodo::where('year_id', $year_id)->get();

			if (count($peris) > 0) {
				$peri = $peris[count($peris)-1];
				$usuario->periodo_id = $peri->id;
			}else{
				abort(400, 'Año sin ningún periodo.');
			}
			
		}

		$usuario->save();

		return $peri;
	}





	public function putGuardarCambios()
	{
		$user = User::fromToken();
		$now 	= Carbon::now('America/Bogota');
		$year = Year::findOrFail(Request::input('id'));
		
		try {
			// **Lo que el cuerpo no trae, no se toca.** Antes iba `Request::input('x')`
			// a secas en las veintiuna, así que un `PUT {"id": 1}` de una línea dejaba
			// el año sin nombre de colegio, sin resolución, sin código DANE, sin rector
			// y sin los nombres de unidad y subunidad —que se imprimen en el boletín de
			// todos los alumnos— y contestaba 200. Es la §68 otra vez: un campo que no
			// se manda no es un campo que no cambia, es un campo que se pisa. §93.
			//
			// Se conserva el valor actual en vez de contestar 422 porque el único
			// cliente que llama a esto —`YearsCtrl.guardar_cambios`, en `myvc_front`—
			// manda el objeto `year` entero, y hay dieciséis copias de ese front con
			// distinta antigüedad: un 422 rompería a la que mande veinte de veintiuno.
			// Conservar no puede romper a nadie.
			//
			// El defecto sólo tapa la clave AUSENTE, no la que llega en `null`: eso es
			// una petición diciendo «bórralo», y sigue borrando. Lo que hace con ella el
			// esquema está medido en §93.2 y no es lo que parece.
			$compromiso_familiar = $year->compromiso_familiar_label;

			if (Request::has('compromiso_familiar_label')) {
				$compromiso_familiar = null;

				if (Request::input('compromiso_familiar_label') != '' && Request::input('compromiso_familiar_label') != null) {
					$compromiso_familiar = Request::input('compromiso_familiar_label');
				}
			}

			$year->nombre_colegio            = Request::input('nombre_colegio', $year->nombre_colegio);
			$year->abrev_colegio             = Request::input('abrev_colegio', $year->abrev_colegio);
			$year->year                      = Request::input('year', $year->year);
			$year->rector_id                 = Request::input('rector_id', $year->rector_id);
			$year->secretario_id             = Request::input('secretario_id', $year->secretario_id);
			$year->tesorero_id               = Request::input('tesorero_id', $year->tesorero_id);
			$year->resolucion                = Request::input('resolucion', $year->resolucion);
			$year->codigo_dane               = Request::input('codigo_dane', $year->codigo_dane);
			$year->telefono                  = Request::input('telefono', $year->telefono);
			$year->celular                   = Request::input('celular', $year->celular);
			$year->website                   = Request::input('website', $year->website);
			$year->website_myvc              = Request::input('website_myvc', $year->website_myvc);
			$year->msg_when_students_blocked = Request::input('msg_when_students_blocked', $year->msg_when_students_blocked);
			$year->unidad_displayname        = Request::input('unidad_displayname', $year->unidad_displayname);
			$year->unidades_displayname      = Request::input('unidades_displayname', $year->unidades_displayname);
			$year->genero_unidad             = Request::input('genero_unidad', $year->genero_unidad);
			$year->subunidad_displayname     = Request::input('subunidad_displayname', $year->subunidad_displayname);
			$year->subunidades_displayname   = Request::input('subunidades_displayname', $year->subunidades_displayname);
			$year->genero_subunidad          = Request::input('genero_subunidad', $year->genero_subunidad);
			$year->alumnos_can_see_notas     = Request::input('alumnos_can_see_notas', $year->alumnos_can_see_notas);
			$year->compromiso_familiar_label = $compromiso_familiar;
			$year->updated_by                = $user->user_id;

			$year->save();
			
			
			// El ingreso sale del token (fase 2 de 18-auditoria.md). El `[0]` que
			// había reventaba para quien no tuviera ninguna sesión anotada, y aquí
			// eso caía en el `catch` de abajo: 422 «Datos incorrectos» **con el año
			// ya guardado**.
			$bit_by 	= $user->user_id;
			$bit_hist 	= isset($user->historial_id) && is_numeric($user->historial_id)
				? (int) $user->historial_id
				: null;

			$consulta 	= 'INSERT INTO bitacoras (created_by, historial_id, affected_element_type, affected_element_id, created_at, affected_element_new_value_string) 
					VALUES (?,?,?,?,?,?)';

			// `$year->id` y no `Request::input('id')`, que es lo que había. Es el
			// **único de los diez escritores de bitácora** que derivaba el sujeto de
			// la fila del CUERPO en vez de la fila leída; los otros nueve ya usan
			// `$nota->alumno_id` o `$subunidad->id`. Medido en
			// docs/migracion/noche-2026-08-24/med-2.md, y es la lección de la §50:
			// *«qué MÁS lee este identificador del cuerpo»*.
			//
			// La fila está garantizada desde la línea 298 —`Year::findOrFail(...)`,
			// fuera del `try`, así que un id que no existe es 404 antes de llegar
			// aquí—, o sea que `$year->id` es el id de la fila que se acaba de
			// guardar. No hay que fiarse de nada.
			//
			// **Hoy no cambia ningún resultado, y por eso hay que decir qué arregla:**
			// `config/database.php` lleva `strict => false`, así que un `id` no
			// numérico se convierte en silencio al entrar en la columna `int` y las
			// dos formas guardan lo mismo. Con el modo estricto puesto —que es un
			// endurecimiento razonable y no está descartado— la vieja lanzaría **después
			// de `$year->save()`**, y como el `catch` de abajo contesta `abort(422)`,
			// el año quedaría **cambiado**, el cliente leería «Datos incorrectos» y del
			// rastro no quedaría nada. Era un fallo latente que la configuración tapa.
			DB::insert($consulta, [ $bit_by, $bit_hist, 'YEAR CONFIGURACION', $year->id, $now, (string) $year ]);

			// El rastro nuevo, al lado del viejo (18 §4). El décimo escritor, y el
			// que ya traía arreglado el sujeto: `$year->id` sale de la fila leída
			// con `findOrFail`, no de `Request::input('id')`.
			//
			// `(string) $year` y no `$year->toArray()`: el modelo se serializa a
			// JSON al convertirlo a cadena, así que lo que entra en `valor_nuevo`
			// ya es la estructura entera y no un `"[object]"`. Es la misma cadena
			// que recibe `bitacoras`, y aquí sí cabe entera —`valor_nuevo` es
			// `json`— mientras que allí va a un `varchar`.
			//
			// **Sin `de()`, y eso es una ausencia con motivo**: la fila vieja ya se
			// perdió doce líneas más arriba, cuando el modelo se fue rellenando
			// campo a campo con `Request::input(..., $year->…)`. Recuperarla exige
			// releer el año antes de tocarlo, y eso es un cambio de forma del
			// método que no es de este lote; queda anotado en aud-4 §5.
			Auditoria::registrar()
				->editar('year_config', (int) $year->id)
				->en(year: (int) $year->id)
				->a((string) $year)
				->guardar();

			return $year;
		} catch (\Exception $e) {
			abort(422, 'Datos incorrectos');
		}
	}

	public function putSetActual(){
		$user = User::fromToken();

		$year_id 	= 	Request::input('year_id');
		$actual 	= 	(bool) Request::input('can');

		if ($actual) {
			Year::where('actual', true)->update(['actual'=>false]);
		}

		$year = Year::findOrFail($year_id);
		// Era `= 1` a secas, o sea que destildar la casilla marcaba el año como
		// actual y devolvía «Ahora NO es año actual». De ahí salen los años con
		// `actual=1` de más que hay en la base: el front es una casilla por año
		// (`years.html`, ng-false-value="0") y quien la apaga cree que la apagó.
		// Lo que se rompe con eso está en Services\Login::ponerEnElPeriodoActual,
		// que se queda con el PRIMERO de los años actuales y no tiene ORDER BY.
		// Ver docs/migracion/05-codigo-muerto-y-roto.md §28.
		$year->actual = $actual ? 1 : 0;
		$year->save();

		if ($actual) { return 'Ahora es año actual.';
		} else { return 'Ahora NO es año actual';}
	}

	public function putAlumnosCanSeeNotas(){
		$user = User::fromToken();

		$year_id 	= 	Request::input('year_id');
		$can 		= 	(bool) Request::input('can');

		$year = Year::findOrFail($year_id);
		$year->alumnos_can_see_notas = $can;
		$year->save();

		if ($can) { return 'Ahora pueden ver sus notas.';
		} else { return 'Ahora NO pueden ver sus notas';}
	}


	public function putProfesCanEditAlumnos(){
		$user = User::fromToken();

		$year_id 	= 	Request::input('year_id');
		$can 		= 	(bool) Request::input('can');

		$year = Year::findOrFail($year_id);
		$year->profes_can_edit_alumnos = $can;
		$year->save();

		if ($can) { return 'Ahora docentes pueden editar alumnos.';
		} else { return 'Ahora docentes NO pueden editar alumnos';}
	}

	public function putToggleMostrarPuestosEnBoletin(){
		$user = User::fromToken();

		$year_id 	= 	Request::input('year_id');
		$can 		= 	(bool) Request::input('can');

		$year = Year::findOrFail($year_id);
		$year->mostrar_puesto_boletin = $can;
		$year->save();

		if ($can) { return 'Ahora se mostrarán los puestos en el boletín.';
		}else{ return 'Ahora NO se mostrarán los puestos en el boletín';}
		
	}

	public function putToggleMostrarNotaComportEnBoletin(){
		$user = User::fromToken();

		$year_id 	= 	Request::input('year_id');
		$can 		= 	(bool) Request::input('can');

		$year = Year::findOrFail($year_id);
		$year->mostrar_nota_comport_boletin = $can;
		$year->save();

		if ($can) { return 'Ahora se mostrará la nota de comportamiento en el boletín.';
		} else { return 'Ahora NO se mostrarán la nota de comportamiento en el boletín';}
	}


	// Mostrar todas las materias al docente al entrar ignorando el horario
	public function putMostrarTodasMaterias(){
		$user = User::fromToken();

		$year_id 	= 	Request::input('year_id');
		$can 		= 	(bool) Request::input('can');

		$year = Year::findOrFail($year_id);
		$year->show_materias_todas = $can;
		$year->save();

		if ($can) { return 'Le apareceran todas las materias al docente ignorando el horario.';
		}else{ return 'Se mostrarán solo las materias del horario.';}

	}


	public function putToggleMostrarAnioPasadoEnBoletin(){
		$user = User::fromToken();

		$year_id 	= 	Request::input('year_id');
		$can 		= 	(bool) Request::input('can');

		$year = Year::findOrFail($year_id);
		$year->year_pasado_en_bol = $can;
		$year->save();

		if ($can) { return 'Ahora se mostrarán indicadores perdidos del año pasado en el boletín.';
		}else{ return 'Ahora NO se mostrarán indicadores perdidos del año pasado en el boletín';}
		
	}

	public function putToggleSoloValorativas(){
		$user = User::fromToken();

		$year_id 	= 	Request::input('year_id');
		$can 		= 	(bool) Request::input('can');

		$year = Year::findOrFail($year_id);
		$year->solo_escalas_valorativas = $can;
		$year->save();

		if ($can) {
			return 'Ahora se mostrarán SOLO cualitativo.';
		} else {
			return 'Ahora se mostrarán cantitativo (números de las notas).';
		}
	}

	public function putToggleCambiarValor(){
		$user 		= User::fromToken();
		$now 		= Carbon::now('America/Bogota');

		$year_id 	= 	Request::input('year_id');
		$valor 		= 	Request::input('valor');
		$campo 		= 	Request::input('campo');

		// `actual` no, y es la única excluida. Esta ruta es el «guardar un campo
		// suelto» de la rejilla y escribe cualquier columna que exista —eso no es un
		// agujero: quien pasa `auth.personal` ya las escribe todas por
		// `years/guardar-cambios`—, pero `actual` tiene invariante (uno solo) y una
		// ruta propia que lo mantiene, `years/set-actual`, que apaga a los demás.
		//
		// Por aquí se podía encender un segundo año actual, y no se queda en la fila:
		// `Services\Login::ponerEnElPeriodoActual` hace `WHERE actual=1` y se queda con
		// el primero SIN `ORDER BY`, o sea el de id más bajo. Medido: encender 2018
		// con 2025 encendido muda a todo el colegio a 2018 en el siguiente inicio de
		// sesión. Es la §28 alcanzada por otra puerta. §94.
		if (strtolower(trim((string) $campo)) === 'actual') {
			abort(422, 'El año actual se cambia con years/set-actual, que apaga a los demás.');
		}

		$consulta 	= 'UPDATE years SET '.ColumnaSegura::exigir('years', $campo).'=:valor, updated_by=:modificador, updated_at=:fecha WHERE id=:year_id';
		$datos 		= [ ':valor' => $valor, ':modificador' => $user->user_id, ':fecha' => $now, ':year_id' => $year_id ];
		$res = DB::update($consulta, $datos);

		if($res)
			return 'Guardado';
		else
			return 'No guardado';
	}


	public function putToggleIgnorarNotasPerdidas(){
		$user = User::fromToken();

		$year_id 	= 	Request::input('year_id');
		$can 		= 	(bool) Request::input('can');

		$year = Year::findOrFail($year_id);
		$year->si_recupera_materia_recup_indicador = $can;
		$year->save();

		if ($can) { return 'Ahora se ignorarán las notas perdidas si gana la materia.';
		} else { return 'Ahora NO se ignorarán las notas perdidas si gana la materia';}
	}

	public function deleteDelete($id)
	{
		$user = User::fromToken();
		
		$year = Year::findOrFail($id);

		// Un año en la papelera no puede ser el año actual del colegio, y hasta
		// hoy podía: en la base hay uno así —2026, borrado, con `actual=1`—.
		// Hoy no se ve, porque todo lo que lee el año actual filtra `deleted_at`;
		// la trampa es `years/restore/{id}`, que lo devolvería encendido junto al
		// que lo esté, y ahí `Login::ponerEnElPeriodoActual` se queda con el
		// PRIMERO de los dos y no tiene ORDER BY. Además, `putSetActual` apaga a
		// los demás con Eloquent, que no ve los borrados: el de la papelera se
		// libraba de todas las apagadas.
		//
		// No cambia nada de lo que hoy calcula nadie —para todos los lectores ese
		// año ya no estaba—: pone en la fila lo que todos ya deducían.
		// Ver docs/migracion/05-codigo-muerto-y-roto.md §28.
		$year->actual = 0;
		$year->save();

		$year->delete();

		return $year;
	}

	public function deleteDestroy($id)
	{
		$user = User::fromToken();

		// Se llama "destroy" pero hace forceDelete: borrado físico de un año, que
		// por las FK ON DELETE CASCADE arrastra 59 tablas hasta 7 saltos de
		// profundidad. Es el borrado de mayor alcance del sistema y no tenía
		// ninguna comprobación más allá de tener un token.
		Autoriza::exigir(Autoriza::esSuperusuario($user),
			'Solo un superusuario puede eliminar un año definitivamente.');

		$year = Year::onlyTrashed()->findOrFail($id);
		$year->forceDelete();

		return $year;
	}

	/*
	 * Restaurar pide lo mismo que borrar definitivamente, y hasta el 22 ago 2026
	 * no pedía nada.
	 *
	 * Cada operación de la papelera es una pareja, y el 21 ago se cerró **una
	 * mitad de cada una**: `forcedelete` quedó anclado a superusuario y `restore`,
	 * en el mismo controlador, se quedó como estaba — bastaba `auth.personal`, o
	 * sea cualquiera de los 51 profesores. La cabecera de `Autoriza` nombra los
	 * cinco sitios de los que venía aquello: grupos, perfiles, profesores, years y
	 * editnota. Son los mismos cinco.
	 *
	 * El criterio es el del gemelo destructivo y no uno nuevo, a propósito: la
	 * regla de `Autoriza` es que crear un rol no regale permisos, y
	 * `esAdministrativo` incluiría al `Secretario` del día que exista sin que
	 * nadie lo haya pedido. Hoy los dos criterios son las mismas diez personas
	 * —`is_superuser` y el rol `Admin` coinciden fila por fila, §28.4— y la
	 * pantalla de papelera del front ya se enseña sólo con `hasRoleOrPerm('admin')`,
	 * así que **nadie pierde un botón que hoy vea**. Subirlo a `esAdministrativo`
	 * es una palabra el día que se decida; está anotado en 09 §5.
	 */
	public function putRestore($id)
	{
		Autoriza::exigir(Autoriza::esSuperusuario(User::fromToken()),
			'No tienes permiso para restaurar años.');

		$year = Year::onlyTrashed()->findOrFail($id);

		$year->restore();
		return $year;
	}


	public function getTrashed()
	{
		$years = Year::onlyTrashed()->get();
		return $years;
	}
}
