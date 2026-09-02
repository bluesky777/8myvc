<?php namespace App\Http\Controllers\Historiales;

use App\Http\Controllers\Controller;

use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\DB;

use App\Support\Autoriza;
use App\User;
use App\Models\Year;
use App\Models\Grupo;

use App\Http\Controllers\Historiales\HistorialCalc;


class HistorialesController extends Controller {

	public function putNotaDetalle()
	{
		$user 	    = User::fromToken();
		$nota_id    = Request::input('nota_id');
		$res 	    = [];

		// **Esto no tiene mitad «lo tuyo»: la pregunta es por una NOTA, no por una
		// persona.** Devuelve quién la cambió, con nombre y apellidos, así que el
		// único reparto posible es el permiso. Decisión 4 de 18-auditoria.md, que
		// además lo dice en voz alta: hasta hoy esto lo gobernaba `califica` —que
		// tiene cualquiera que ponga notas— y **un profesor que hoy entra puede
		// dejar de poder**. Es el endurecimiento buscado, no un efecto colateral.
		// Si un colegio quiere que sigan entrando, se les siembra el permiso; la
		// respuesta no es quitar esta línea.
		Autoriza::exigir(
			Autoriza::puedeVerAuditoria($user),
			'No tiene permiso para ver la auditoría de otras personas'
		);


		$consulta 	= '(SELECT b.id as bit_id, b.created_by as created_by_user_id, b.historial_id, b.created_at, b.affected_element_new_value_int as new_value, b.affected_element_old_value_int as old_value, concat(p.nombres, " ", p.apellidos) as creado_por
							FROM bitacoras b 
							inner join users u on u.id=b.created_by
							inner join profesores p on p.user_id=u.id
							where b.affected_element_type="Nota" and b.affected_element_id=?)
						UNION 
						(SELECT b.id as bit_id, b.created_by as created_by_user_id, b.historial_id, b.created_at, b.affected_element_new_value_int as new_value, b.affected_element_old_value_int as old_value, u.username as creado_por
							FROM bitacoras b 
							inner join users u on u.id=b.created_by AND u.tipo<>"Profesor"
							where b.affected_element_type="Nota" and b.affected_element_id=?)';
		

		$bita = DB::select($consulta, [$nota_id, $nota_id] );
		
		
		// **Las diez columnas nombradas y no `n.*`**, desde el 2 sep 2026: esta fila
		// se devuelve en `$res['nota']`, así que con el asterisco las cinco columnas
		// de la nivelación (`2026_09_02_100000_nivelaciones_columnas`) **ya estaban
		// viajando por aquí sin que nadie lo hubiera decidido** — y sin que nada lo
		// dijera, porque esta ruta no tiene instantánea de forma. Lo encontró
		// `tools/rutas-sin-instantanea.php`, que existe para eso.
		//
		// **Congelada** (22 §3.4): esta pantalla contesta «quién cambió esta nota»
		// leyendo `bitacoras`, y el par original/nivelación se pinta en la planilla y
		// en el editor, que lo reciben nombrado. El día que el historial quiera
		// enseñar la nivelación, se añade aquí **con su decisión**, no de rebote.
		//
		// El `UNION` conserva sus dos ramas: la primera resuelve el nombre por
		// `profesores` y la segunda cae al `username` cuando quien creó la nota no es
		// un profesor. Las columnas tienen que ser **las mismas y en el mismo orden**
		// en las dos, o MySQL las cruza por posición y el historial enseña una nota
		// con el id de otra.
		$columnas = 'n.id, n.nota, n.subunidad_id, n.alumno_id, n.created_by, n.updated_by,
							n.deleted_by, n.deleted_at, n.created_at, n.updated_at';

		$consulta 	= '(SELECT '.$columnas.', concat(p.nombres, " ", p.apellidos) as creado_por, u2.username as modificado_por
							FROM notas n 
							inner join users u on u.id=n.created_by
							inner join profesores p on p.user_id=u.id
							left join users u2 on u2.id=n.updated_by
							where n.id=?)
						UNION
						(SELECT '.$columnas.', u.username as creado_por, u2.username as modificado_por
							FROM notas n 
							inner join users u on u.id=n.created_by
							left join users u2 on u2.id=n.updated_by
							where n.id=?)';
		

		$nota = DB::select($consulta, [$nota_id, $nota_id] );
		if(count($nota)>0){
			$nota = $nota[0];
		}


		$res['cambios'] 	= $bita;
		$res['nota'] 	    = $nota;
		

		return $res;
	}
	
	
	

	public function putNotaFinalDetalle()
	{
		$user 	    = User::fromToken();
		$nf_id    	= Request::input('nf_id');
		$res 	    = [];

		// Misma regla y mismo motivo que `putNotaDetalle`: la pregunta es por una
		// definitiva, no por una persona, así que no hay mitad «lo tuyo» que
		// dejar abierta. AUD-5, decisión 4 de 18-auditoria.md.
		Autoriza::exigir(
			Autoriza::puedeVerAuditoria($user),
			'No tiene permiso para ver la auditoría de otras personas'
		);

		$consulta 	= '(SELECT b.id as bit_id, b.created_by as created_by_user_id, b.historial_id, b.created_at, b.affected_element_new_value_int as new_value, b.affected_element_old_value_int as old_value, concat(p.nombres, " ", p.apellidos) as creado_por
							FROM bitacoras b 
							inner join users u on u.id=b.created_by
							inner join profesores p on p.user_id=u.id
							where b.affected_element_type="NF_UPDATE" and b.affected_element_id=?)
						UNION 
						(SELECT b.id as bit_id, b.created_by as created_by_user_id, b.historial_id, b.created_at, b.affected_element_new_value_int as new_value, b.affected_element_old_value_int as old_value, u.username as creado_por
							FROM bitacoras b 
							inner join users u on u.id=b.created_by AND u.tipo<>"Profesor"
							where b.affected_element_type="NF_UPDATE" and b.affected_element_id=?)';
		

		$bita = DB::select($consulta, [$nf_id, $nf_id] );
		
		
		// Una marca de parámetro y **un** valor. Llevaba dos —copiados de la consulta
		// de arriba, que sí tiene dos porque es un UNION— y con `EMULATE_PREPARES` en
		// false eso es `SQLSTATE[HY093]: Invalid parameter number`: la pantalla «quién
		// cambió esta definitiva» contestaba **500 a todo el mundo, siempre**. Medido
		// el 22 ago 2026; ver 05 §73.
		// Lo mismo que su gemela de arriba y por lo mismo: esta fila se devuelve, así
		// que con `n.*` las cinco columnas nuevas de `notas_finales` —tres de
		// `2026_09_02_100000` y dos de `2026_09_02_200000`— salían solas. Congelada.
		$consulta 	= 'SELECT n.id, n.alumno_id, n.asignatura_id, n.periodo_id, n.periodo,
							CAST(n.nota AS DOUBLE) AS nota, n.recuperada, n.manual, n.updated_by,
							n.created_at, n.updated_at, u2.username as modificado_por
							FROM notas_finales n 
							left join users u2 on u2.id=n.updated_by
							where n.id=?';
		

		$nota = DB::select($consulta, [$nf_id] );
		if(count($nota)>0){
			$nota = $nota[0];
		}


		$res['cambios'] 	= $bita;
		$res['nota'] 	    = $nota;
		

		return $res;
	}
	
	
	
	
	public function putSesion()
	{
		$user           = User::fromToken();
		$historial_id   = Request::input('historial_id');
		$tipo           = Request::input('tipo');


		$historial 	            = DB::select('SELECT h.*, u.username FROM historiales h INNER JOIN users u ON u.id=h.user_id WHERE h.id=?', [$historial_id] );
		if (count($historial) > 0) {
		
			$historial = $historial[0];

			// El identificador que llega es el del INGRESO, no el de la persona,
			// así que de quién es sólo se sabe después de traer la fila — por eso
			// la comprobación va aquí dentro y no antes. La respuesta incluye
			// `u.username`, o sea que sin esto se lee el nombre de cuenta de
			// cualquiera. AUD-5, decisión 3 de 18-auditoria.md.
			Autoriza::exigirVerAuditoriaDe($user, $historial->user_id);
			/* Se supone que debe ser con el user_id, pero la embarré
			$consulta   = 'SELECT b.*, a.nombres, a.apellidos, s.definicion FROM bitacoras b
						inner join alumnos a ON b.affected_user_id=a.user_id and a.deleted_at is null
						inner join notas n ON n.id=b.affected_element_id
						inner join subunidades s ON s.id=n.subunidad_id and s.deleted_at is null
						WHERE b.historial_id=? and b.deleted_at is null';
			*/
			
			$consulta   = 'SELECT b.*, a.nombres, a.apellidos, s.definicion FROM bitacoras b
						inner join alumnos a ON b.affected_user_id=a.id and a.deleted_at is null
						inner join notas n ON n.id=b.affected_element_id
						inner join subunidades s ON s.id=n.subunidad_id and s.deleted_at is null
						WHERE b.historial_id=? and b.deleted_at is null';
						
			$bitacoras_notas 	= DB::select($consulta, [$historial_id] );
			
			$historial->bitacoras 	= $bitacoras_notas;
		
		}else{
			return abort(400, 'No hay historial');
		}
		
		
		return ['historial'=>$historial];
	}
	


	
	public function putDeUsuario()
	{
		$user           	= User::fromToken();
		$user_id   			= Request::input('user_id');

		// **El IDOR que el plan manda cerrar en esta fase.** `$user` se resolvía
		// y NO se usaba: la ruta cogía `user_id` del cuerpo y devolvía las
		// sesiones y **los intentos de login fallidos** de quien fuera. Con
		// `auth.personal` bastaba con ser del personal. Ver 08-revision-idor.md y
		// la fase 5 de 18-auditoria.md, que lo separa a propósito de la decisión
		// 3: comprobar que quien pregunta puede preguntar por ESE usuario hace
		// falta con cualquier respuesta que se dé a «quién ve la auditoría».
		Autoriza::exigirVerAuditoriaDe($user, $user_id);

		$historialCalc 		= new HistorialCalc();
		
		$historial 			= $historialCalc->historial_sesiones_de_usuario($user_id);
		$intentos_fallidos 	= $historialCalc->intentos_fallidos_de_usuario($user_id);
		
		return ['historial'=>$historial, 'intentos_fallidos'=>$intentos_fallidos];
	}
	




}