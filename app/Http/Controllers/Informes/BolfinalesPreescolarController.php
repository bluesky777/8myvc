<?php namespace App\Http\Controllers\Informes;


use App\Http\Controllers\Controller;


use Carbon\Carbon;
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
use App\Services\Auditoria;
use App\Services\BoletinIndependiente;
use \Log;


class BolfinalesPreescolarController extends Controller {


	private $escalas_val = [];
	

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
		
		
		
		
		
		$grupo			= Grupo::datos($grupo_id);
		$year			= Year::datos($user->year_id, $year_actual);
		$alumnos		= Grupo::alumnos($grupo_id, $requested_alumnos);
		
		
		$year_notas		= Year::datos($user->year_id);
		$year->minu_hora_clase 				= $year_notas->minu_hora_clase;
		
		

		$cons = 'SELECT c.*, i.nombre as encabezado_nombre, i2.nombre as piepagina_nombre 
				FROM config_certificados c 
				left join images i on i.id=c.encabezado_img_id and i.deleted_at is null
				left join images i2 on i2.id=c.piepagina_img_id and i2.deleted_at is null
					where c.id=?';
		$config_certificado = DB::select($cons, [$year->config_certificado_estudio_id]);
		if (count($config_certificado) > 0) {
			$year->config_certificado = $config_certificado[0];
		}


		// Creo que puedo borrarlo
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
		

		/*
		 * Los periodos del año, una vez para todo el grupo.
		 *
		 * Este controlador **no nombra `periodo` en ninguna otra línea**: el boletín de
		 * preescolar es del año entero. Así que la marca que le toca es «¿fue aparte en
		 * ALGUNO de los periodos del año?», que es la misma regla que el puesto y por lo
		 * mismo — dentro de estas cifras hay una definitiva que no se calculó sobre el
		 * reparto del grupo.
		 *
		 * Se resuelve aquí y no dentro de `definitivasMateriasXPeriodo` para no hacer la
		 * consulta una vez por alumno: son treinta y la respuesta es la misma.
		 */
		$periodos_del_anio = array_map(
			fn ($periodo) => (int) $periodo->id,
			Periodo::hastaPeriodoN($user->year_id)
		);

		foreach ($alumnos as $alumno) {

			// Todas las materias con sus unidades y subunides
			$this->definitivasMateriasXPeriodo($alumno, $grupo_id, $user->year_id, $user);

			/*
			 * `bol_independiente`: **este boletín es el suyo, no el del grupo** — §6.4 del
			 * [19](../../../../docs/migracion/19-boletin-independiente.md).
			 *
			 * **Es un dato del ALUMNO puesto en cada asignatura**, no una propiedad de la
			 * asignatura: la marca cuelga de `(alumno_id, periodo_id)` y vale lo mismo en
			 * todas las suyas. Se emite por asignatura porque el front pinta la nota al
			 * lado de cada bloque, y subirla obligaría a cada plantilla a ir a buscarla.
			 *
			 * **No se rotula el papel**: es una nota flotante que se ve en pantalla y
			 * desaparece al imprimir. Si el campo no viaja no se pinta nada y **nadie se
			 * entera** — el front no inventa la marca en el cliente. Por eso los cinco
			 * sitios lo emiten a la vez: si lo emite uno y otro no, los otros mienten.
			 */
			$bol_independiente = BoletinIndependiente::aplicaEnAlguno((int) $alumno->alumno_id, $periodos_del_anio);

			foreach ($alumno->asignaturas as $asignatura) {
				$asignatura->bol_independiente = $bol_independiente;
			}

		}


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


		return array($grupo, $year, $response_alumnos);
	}

	public function definitivasMateriasXPeriodo(&$alumno, $grupo_id, $year_id, $user)
	{

		$alumno->asignaturas	= Grupo::detailed_materias($grupo_id);

		$alumno->ausencias = 0;
		$alumno->tardanzas = 0;
		$alumno->total_creditos = 0;
		
		

		foreach ($alumno->asignaturas as $asignatura) {

			$alumno->total_creditos += $asignatura->creditos;
						
			$consulta_aus = 'SELECT count(a.id) as cantidad_ausencia FROM ausencias a
				WHERE a.alumno_id=:alumno_id and a.asignatura_id=:asignatura_id and a.cantidad_ausencia > 0';
			
			$consulta_tar = 'SELECT count(a.id) as cantidad_tardanzas FROM ausencias a
				WHERE a.alumno_id=:alumno_id and a.asignatura_id=:asignatura_id and a.cantidad_tardanza > 0';
					

			$paramentros = [
				':alumno_id'	=> $alumno->alumno_id, 
				':asignatura_id'=> $asignatura->asignatura_id
			];
				
			
			$asignatura->ausencias = DB::select($consulta_aus, $paramentros)[0];
			$asignatura->tardanzas = DB::select($consulta_tar, $paramentros)[0];
			
			
			// `deleted_at is null` desde que la tabla tiene papelera (21 ago 2026): sin
			// esto, una frase borrada seguiría saliendo impresa en el boletín, que es
			// el único sitio donde se vería el fallo.
			$consulta = 'SELECT * FROM frases_preescolar WHERE asignatura_id=? AND deleted_at IS NULL';
			$asignatura->frases = DB::select($consulta, [$asignatura->asignatura_id]);

		}

		// Nota promedio de comportamiento
		$alumno->nota_comportamiento_year 	= NotaComportamiento::nota_promedio_year($alumno->alumno_id, $year_id);
		
		$escala = $this->valoracion($alumno->nota_comportamiento_year);
		if ($escala) {
			$alumno->nota_comportamiento_year_desempenio = $escala->desempenio;
		}
		
		$alumno->encabezado_comportamiento = $this->encabezado_comportamiento_boletin($alumno->nota_comportamiento_year, $user->nota_minima_aceptada, $user->mostrar_nota_comport_boletin, $alumno->sexo);
		
		
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




	private function encabezado_comportamiento_boletin($nota, $nota_minima_aceptada, $mostrar_nota_comport, $sexo){
		
		$icono 		= '';
		
		if ($sexo == 'F') {
			$icono = 'fa-male';
		}else{
			$icono = 'fa-female';
		}
		
		// §166. Esta quinta copia **no se toca, y no hay nada que arreglar aquí**.
		//
		// Se llegó a cambiarla a `is_object($nota)` al arreglar las otras cuatro
		// —la §141, el centinela `["notas_finales" => []]`— y se revirtió. El
		// motivo: **no recibe lo mismo que ellas.** Su único llamante (línea 209)
		// le pasa `$alumno->nota_comportamiento_year`, que sale de
		// `NotaComportamiento::nota_promedio_year()` y es **siempre un `(int)`**
		// —un promedio, o `0` si no hay notas—. Nunca es el objeto ni el array.
		//
		// Con un número, `is_object` es siempre falso: el cambio habría apagado
		// la cabecera de comportamiento del boletín de preescolar **en silencio
		// y con los tests en verde**.
		//
		// Y por lo mismo, `$la_nota = $nota;` de ocho líneas más abajo **está
		// bien**, aunque las otras cuatro copias digan `$la_nota = $nota->nota;`:
		// allí `$nota` es la fila y aquí ya es la nota. La asimetría es real y no
		// es un fallo — se anotó como sospecha al ver el aviso `binaryOp.invalid`
		// de larastan, y ese aviso lo estaba provocando el `is_object` de más, no
		// el código de aquí.
		//
		// **Ampliar un arreglo a «todas las copias» sin comprobar qué recibe cada
		// una es la forma de romper la que estaba bien.**
		if ($nota) {
			$clase 		= '';
			$la_nota 	= '';
			$escala = '';
			
			if ( $mostrar_nota_comport ) {
				$la_nota = $nota;
				if ($la_nota < $nota_minima_aceptada) {
					$clase = ' nota-perdida-bold ';
				}
				$escala = EscalaDeValoracion::valoracion($la_nota, $this->escalas_val)->desempenio;
			}
			
			
			
			$res = '<div class="row comportamiento-head">
						<div class="col-lg-10 col-xs-10 comportamiento-title"><i style="padding-right: 5px;" class="fa '.$icono.'"></i>  Comportamiento</div>
						<div style="padding: 0px; text-align: center;" class="col-lg-1 col-xs-1 comportamiento-desempenio ">'.$escala.'</div>
						<div class="col-lg-1 col-xs-1 comportamiento-nota '. $clase .'">'.$la_nota.'</div>
					</div>';
			
		}else{
			$res = '<div class="row comportamiento-head">
						<div class="col-lg-10 col-xs-10 comportamiento-title"><i style="padding-right: 5px;" class="fa '.$icono.'"></i>  Comportamiento</div>
					</div>';
		}
		return $res;
	}
	
	
	
	public function putCrearFrase(){
		
		$user = User::fromToken();
		
		DB::insert('INSERT INTO frases_preescolar(asignatura_id, definicion) VALUES(?,?);', [ Request::input('asignatura_id'), '' ]);
		
		$last_id = DB::getPdo()->lastInsertId();
		$res = DB::select('SELECT * FROM frases_preescolar WHERE id=?;', [ $last_id ])[0];

		// La frase nace vacía —el `''` de arriba— y se rellena en `putGuardarFrase`,
		// así que el alta y el texto son dos líneas distintas del historial. Está
		// bien que lo sean: en el boletín de preescolar **no hay notas, hay frases**,
		// y quién escribió cada una es la pregunta entera.
		Auditoria::registrar()
			->crear('frase_preescolar', (int) $last_id)
			->en(asignatura: is_numeric($res->asignatura_id) ? (int) $res->asignatura_id : null)
			->a(['definicion' => $res->definicion])
			->guardar();
		
		return (array)$res;
	}
	
	
	
	public function putGuardarFrase(){
		
		$user = User::fromToken();
		
		$asignatura_id 	= Request::input('asignatura_id');
		$definicion 	= Request::input('definicion');
		$id 			= Request::input('id');
		
		// El texto de antes, leído antes de pisarlo: lo que se reescribe aquí es
		// texto escrito a mano por un profesor y que sale impreso en el boletín de
		// un niño. Sin `de(...)` la línea diría que alguien lo cambió y no desde qué.
		$antes = DB::selectOne('SELECT asignatura_id, definicion FROM frases_preescolar WHERE id = ?', [$id]);

		DB::update('UPDATE frases_preescolar SET asignatura_id=?, definicion=? WHERE id=? AND deleted_at IS NULL;', [ $asignatura_id, $definicion, $id ]);

		Auditoria::registrar()
			->editar('frase_preescolar', is_numeric($id) ? (int) $id : null)
			->en(asignatura: is_numeric($asignatura_id) ? (int) $asignatura_id : null)
			->de($antes === null ? null : ['asignatura_id' => $antes->asignatura_id, 'definicion' => $antes->definicion])
			->a(['asignatura_id' => $asignatura_id, 'definicion' => $definicion])
			->guardar();
		
		return 'Cambiada';
	}
	
	
	/**
	 * Manda la frase a la papelera, que hasta el 21 ago 2026 no existía.
	 *
	 * Era un `DELETE` físico, y `frases_preescolar` era la única tabla de contenido
	 * de este módulo sin `deleted_at`. Lo que se borra aquí es texto que escribió un
	 * profesor y que sale impreso en el boletín de un niño de preescolar — en ese
	 * boletín no hay notas, hay frases.
	 *
	 * El daño no era el borrado sino la expectativa: en el resto del sistema todo va
	 * a la papelera, así que quien pulsa «eliminar» aquí cree que puede deshacerlo.
	 * Decidido por Joseth con la medición delante (14-certificados.md §7.2).
	 *
	 * Se escribe con `DB::update` y no con Eloquent para no cambiar nada más de este
	 * método: sigue respondiendo `'ELIMINADA'` aunque el id no exista, que es un
	 * fallo aparte —el de 14 §7.1— y tiene su propio test.
	 */
	public function putEliminarFrase(){
		
		$user = User::fromToken();
		
		$id 			= Request::input('id');
		
		// **Antes** del borrado, y con el texto dentro. Este método responde
		// `'ELIMINADA'` aunque el id no exista (14 §7.1), así que la respuesta no
		// distingue haber borrado de no haber borrado nada: la línea de auditoría
		// **sí**, porque `de(...)` sale de la fila leída y queda en null cuando no
		// había fila. Es la diferencia entre mirar el resultado y mirar el 200.
		$antes = DB::selectOne('SELECT asignatura_id, definicion FROM frases_preescolar WHERE id = ? AND deleted_at IS NULL', [$id]);

		DB::update('UPDATE frases_preescolar SET deleted_at=? WHERE id=? AND deleted_at IS NULL;',
			[ Carbon::now('America/Bogota'), $id ]);

		Auditoria::registrar()
			->borrar('frase_preescolar', is_numeric($id) ? (int) $id : null)
			->en(asignatura: $antes !== null && is_numeric($antes->asignatura_id) ? (int) $antes->asignatura_id : null)
			->de($antes === null ? null : ['asignatura_id' => $antes->asignatura_id, 'definicion' => $antes->definicion])
			->guardar();
		
		return 'ELIMINADA';
	}
	
	
	
	
	
	




}