<?php namespace App\Http\Controllers;


use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;

use App\User;
use App\Models\Year;
use App\Models\Profesor;
use App\Models\Asignatura;
use App\Models\Unidad;
use Carbon\Carbon;

use App\Http\Controllers\Alumnos\Solicitudes;
use Illuminate\Support\Facades\Log;
use App\Support\ColumnaSegura;


class AsignaturasController extends Controller {

	public function getIndex()
	{
		$user = User::fromToken();

		$consulta = 'SELECT a.id, a.materia_id, a.grupo_id, a.profesor_id, a.creditos, a.orden, a.domingo, a.lunes, a.martes, a.miercoles, a.jueves, a.viernes, a.sabado,
						a.created_by, a.updated_by, a.created_at, a.updated_at, ar.nombre as nombre_area, ar.alias as alias_area,
						m.materia as nombre_asignatura
					FROM asignaturas a
					inner join materias m on m.id=a.materia_id and m.deleted_at is null
					left join areas ar on ar.id=m.area_id and ar.deleted_at is null
					inner join grupos g on g.id=a.grupo_id and g.year_id=? and g.deleted_at is null
					where a.deleted_at is null
					order by g.orden, ar.orden, a.orden';

		$asignaturas 	= DB::select($consulta, [$user->year_id]);
		$cant 			= count($asignaturas);



		return $asignaturas;
	}

	
	

	public function putDetalleAsignatura()
	{
		//$user = User::fromToken();

		$cons_unidades 		= 'SELECT u.*, p.numero as numero_periodo FROM unidades u
			INNER JOIN periodos p ON u.periodo_id=p.id and p.deleted_at is null
			WHERE u.asignatura_id=? and u.deleted_at is null order by p.numero, u.orden, u.id';

		$unidades 	= DB::select($cons_unidades, [Request::input('asignatura_id')]);
		$cant 			= count($unidades);

		$cons_subunidades 	= 'SELECT * FROM subunidades WHERE unidad_id=? and deleted_at is null order by orden, id';
		$cons_notas 		= 'SELECT count(id) as cantidad FROM notas WHERE subunidad_id=? and deleted_at is null';
		$cantidad_notas 	= 0;

		for ($i=0; $i < $cant; $i++) { 
			$unidades[$i]->subunidades 	= DB::select($cons_subunidades, [$unidades[$i]->id] );

			for ($j=0; $j < count($unidades[$i]->subunidades); $j++) { 
				$unidades[$i]->subunidades[$j]->cantidad 	= DB::select($cons_notas, [$unidades[$i]->subunidades[$j]->id] )[0]->cantidad;
				$cantidad_notas 	+= $unidades[$i]->subunidades[$j]->cantidad;
			}
		}

		return ['unidades' => $unidades, 'cantidad_notas' => $cantidad_notas];
	}

	
	public function putDatosAsignaturas()
	{
		$user = User::fromToken();

		$consulta 	= 'SELECT * FROM materias WHERE deleted_at is null';
		$materias 	= DB::select($consulta);
		
		$consulta = 'SELECT g.id, g.nombre, g.abrev, g.orden, gra.orden as orden_grado, g.grado_id, g.year_id, g.titular_id,
			p.nombres as nombres_titular, p.apellidos as apellidos_titular, p.titulo, g.caritas, 
			g.created_at, g.updated_at, gra.nombre as nombre_grado 
			from grupos g
			inner join grados gra on gra.id=g.grado_id and g.year_id=:year_id 
			left join profesores p on p.id=g.titular_id
			where g.deleted_at is null
			order by g.orden';

		$grupos 	= DB::select($consulta, [':year_id'=>$user->year_id]);
		
		// Solo los cuatro campos que pinta la pantalla. Ver 05 §51.
		$profesores = Profesor::paraElegirEnAsignaturas($user->year_id);
		
		return [ 'materias' => $materias, 'grupos' => $grupos, 'profesores' => $profesores ];
	}

	
	
	public function postCopiar()
	{
		$user = User::fromToken();

		$consulta 		= 'SELECT * FROM asignaturas WHERE deleted_at is null and grupo_id=?';
		$asignaturas 	= DB::select($consulta, [Request::input('grupo_id_origen')]);
		
		for ($i=0; $i < count($asignaturas); $i++) { 

			$consulta 		= 'INSERT INTO asignaturas(materia_id, grupo_id, profesor_id, nuevo_responsable_id, creditos, orden) VALUES(?,?,?, ?,?,?)';
			DB::insert($consulta, [ $asignaturas[$i]->materia_id, Request::input('grupo_id_destino'), $asignaturas[$i]->profesor_id, $asignaturas[$i]->nuevo_responsable_id, $asignaturas[$i]->creditos, $asignaturas[$i]->orden ]);
			
		}
		
		
		return 'Asignaturas copiadas';
	}

	
	public function postIndex()
	{
		
		$this->fixInputs();

		$asignatura = new Asignatura;
		$asignatura->materia_id		=	Request::input('materia_id');
		$asignatura->grupo_id		=	Request::input('grupo_id');
		$asignatura->profesor_id	=	Request::input('profesor_id');
		$asignatura->creditos		=	Request::input('creditos');
		$asignatura->orden			=	Request::input('orden');
		$asignatura->save();

		return $asignatura;
	}

	public function getShow($asignatura_id)
	{
		$user = User::fromToken();
		$asignatura = Asignatura::detallada($asignatura_id, $user->year_id);
		return $asignatura;
	}


	public function putToggleDia()
	{
		$user 			= User::fromToken();
		$asignatura_id 	= Request::input('asignatura_id');
		$dia 			= Request::input('dia');
		$valor 			= Request::input('valor');
		$now 			= Carbon::now('America/Bogota');
		
		$consulta 	= 'UPDATE asignaturas SET '.ColumnaSegura::exigir('asignaturas', $dia).'=:valor, updated_by=:modificador, updated_at=:fecha WHERE id=:asignatura_id';
		DB::update($consulta, [$valor, $user->user_id, $now, $asignatura_id]);
		
		return 'Cambiado';
	}

	
	public function putUpdate($id)
	{
		$asignatura = Asignatura::findOrFail($id);

		$this->fixInputs();

		$asignatura->materia_id		=	Request::input('materia_id');
		$asignatura->grupo_id		=	Request::input('grupo_id');
		$asignatura->profesor_id	=	Request::input('profesor_id', $asignatura->profesor_id);
		$asignatura->creditos		=	Request::input('creditos');
		$asignatura->orden			=	Request::input('orden');

		$asignatura->save();
		return $asignatura;
	}

	private function fixInputs()
	{
		if (!Request::input('profesor_id') and Request::input('profesor')['profesor_id']) {
			Request::merge(array('profesor_id' => Request::input('profesor')['profesor_id'] ) );
		}

		if (!Request::input('grupo_id') and Request::input('grupo')['id']) {
			Request::merge(array('grupo_id' => Request::input('grupo')['id'] ) );
		}

		if (!Request::input('materia_id') and Request::input('materia')['id']) {
			Request::merge(array('materia_id' => Request::input('materia')['id'] ) );
		}
	}


	public function getListasignaturas($persona_id='')
	{
		$user = User::fromToken();
		$info_profesor = false;

		if ($persona_id=='') {
			$persona_id = $user->persona_id;
		}else{
			$info_profesor = Profesor::detallado($persona_id);
		}

		$consulta 		= '';
		$asignaturas 	= '';
		$pedidos 		= [];

		// Decía `case 'Profesor' or 'Usuario':`, que en PHP no es «uno u otro»:
		// es `case ('Profesor' or 'Usuario')`, o sea `case true`. Y como `switch`
		// compara con `==`, cualquier tipo no vacío entraba por aquí y el
		// `case 'Alumno'` de abajo no se ejecutaba nunca.
		//
		// Ese error de escritura era lo único que tapaba una fuga: la rama de
		// Alumno filtraba por `a.profesor_id = <persona_id del alumno>`, y los
		// ids de `alumnos` y de `profesores` son numeraciones distintas que se
		// solapan — 34 alumnos de la base de desarrollo verían las asignaturas
		// del profesor con su mismo id, uno de ellos 92. Ver
		// docs/migracion/05-codigo-muerto-y-roto.md §11.1.
		switch ($user->tipo) {
			case 'Profesor':
			case 'Usuario':
				$asignaturas = Profesor::asignaturas($user->year_id, $persona_id);

				foreach ($asignaturas as $asignatura) {

					$asignatura->unidades = Unidad::informacionAsignatura($asignatura->asignatura_id, $user->periodo_id);
					
				}

				if ($user->tipo == 'Profesor') {
					$solicitudes 	= new Solicitudes();
					$pedidos 		= $solicitudes->asignaturas_a_cambiar_de_profesor($user->user_id, $user->year_id);
					$res['pedidos']	= $pedidos;

					$consulta = 'SELECT g.id, g.nombre, g.abrev, g.orden, gra.orden as orden_grado, g.grado_id, g.year_id, g.titular_id,
							p.nombres as nombres_titular, p.apellidos as apellidos_titular, p.titulo,
							g.created_at, g.updated_at, gra.nombre as nombre_grado 
						from grupos g
						inner join grados gra on gra.id=g.grado_id and g.year_id=:year_id 
						left join profesores p on p.id=g.titular_id
						where g.deleted_at is null
						order by g.orden';

					$res['grupos'] = DB::select($consulta, [':year_id'=>$user->year_id] );


					$consulta = 'SELECT * from materias	where deleted_at is null order by materia';
					$res['materias'] = DB::select($consulta);


				}
				
				break;

			case 'Alumno':
				// Esta rama llevaba una consulta que preguntaba por
				// `a.profesor_id = :profesor_id` pasándole el `persona_id` del
				// alumno. No era «las asignaturas del alumno»: era «las del
				// profesor que por casualidad tenga ese número». Se retira, que
				// es lo único que se puede hacer sin decidir lo otro.
				//
				// La regla de acceso sí está decidida (Joseth, 20 ago 2026): un
				// alumno o acudiente solo puede alcanzar asignaturas **de su
				// grupo, o de todos sus grupos**. Devolver la lista vacía la
				// cumple y además no cambia lo que ve el cliente hoy, porque la
				// consulta de arriba tampoco devolvía nada.
				//
				// Lo que queda abierto es si esta pantalla debe enseñarle sus
				// asignaturas de verdad — las de su grupo, que ninguna de las dos
				// consultas miraba—. Eso es escribir la que nadie escribió, y va
				// aparte.
				$asignaturas = [];

				break;
			
			default:
				# code...
				break;
		}

		$res['asignaturas'] = $asignaturas;

		if ($info_profesor) {
			$res['info_profesor'] = $info_profesor;
		}

		
		$consulta = 'SELECT g.id, g.nombre, g.abrev, g.orden, g.grado_id, g.year_id, g.titular_id,
						p.nombres as nombres_titular, p.apellidos as apellidos_titular, p.titulo,
						g.created_at, g.updated_at, gra.nombre as nombre_grado, r.con_notas 
					from grupos g
					inner join grados gra on gra.id=g.grado_id and g.year_id=:year_id 
					inner join profesores p on p.id=g.titular_id and g.titular_id = :profe_id
					left join (
						select IF(count(n.id)>0, 1, 0) as con_notas, g.id as grupo_id 
						from nota_comportamiento n
						inner join matriculas m ON m.alumno_id=n.alumno_id and (m.estado="MATR" or m.estado="ASIS") and m.deleted_at is null
						inner join grupos g ON g.id=m.grupo_id and g.deleted_at is null and titular_id=:profe_id2 and g.year_id=:year_id2
						where n.periodo_id=:periodo_id
						group by g.id
					)r on r.grupo_id=g.id
					where g.deleted_at is null
					order by g.orden';

		$grados = DB::select($consulta, [':year_id'=>$user->year_id, ':profe_id' => $persona_id, ':profe_id2' => $persona_id, ':year_id2'=>$user->year_id, ':periodo_id'=>$user->periodo_id ] );

		$res['grados_comp'] = $grados;


		return $res;
	}



	// Solo las asignaturas para el popup del menú "planillas" de los profesores
	public function getListasignaturasAlone()
	{
		$user = User::fromToken();

		$persona_id = $user->persona_id;

		$consulta = '';
		$asignaturas = '';
		$asignaturas = Profesor::asignaturas($user->year_id, $persona_id);


		return $asignaturas;
	}

	public function getListAsignaturasYear($profesor_id, $periodo_id)
	{
		$user = User::fromToken();

		$year = Year::de_un_periodo($periodo_id);

		$asignaturas = Profesor::asignaturas($year->id, $profesor_id);

		foreach($asignaturas as $asignatura) {

			$asignatura->unidades = Unidad::informacionAsignatura($asignatura->asignatura_id, $periodo_id);
			
		}
		

		return $asignaturas;
	}



	public function getPapelera()
	{
		$user = User::fromToken();
		
		$consulta = 'SELECT a.id as asignatura_id, a.*, m.materia, m.area_id, p.nombres, p.apellidos, g.nombre as nombre_grupo, g.abrev as abrev_grupo FROM asignaturas a
					LEFT JOIN materias m ON m.id=a.materia_id and m.deleted_at is null
					LEFT JOIN profesores p ON p.id=a.profesor_id and p.deleted_at is null
					LEFT JOIN grupos g ON g.id=a.grupo_id and g.deleted_at is null
					WHERE a.deleted_at is not null and g.year_id=?';
					
		$asignaturas = DB::select($consulta, [$user->year_id]);

		return $asignaturas;
	}


	/*
	 * Restaurar obedece al año del que pide, igual que el listado de al lado.
	 *
	 * `getPapelera()` filtra `g.year_id = ?` con el año del token y esto hacía
	 * `UPDATE ... WHERE id=?` con lo que llegara en el cuerpo: el listado enseñaba
	 * un año y el botón alcanzaba todos. Es la asimetría entre las dos mitades de
	 * una misma pantalla, que es donde han salido casi todas.
	 *
	 * No es un permiso nuevo —quien llega ya es personal y ya podía restaurar las
	 * suyas—, es el mismo alcance que su listado. Y no lo llama ningún cliente: la
	 * papelera del front tiene tres rejillas y ninguna es ésta, así que era una
	 * mina y no un fallo vivo.
	 *
	 * 404 y no 403 a propósito: para quien pide, una asignatura de otro año no está
	 * prohibida — no está en su papelera.
	 */
	public function putRestaurar()
	{
		$user 				= User::fromToken();
		$asignatura_id 		= Request::input('asignatura_id');

		$consulta = 'UPDATE asignaturas a
					INNER JOIN grupos g ON g.id = a.grupo_id AND g.year_id = ?
					SET a.deleted_at = NULL
					WHERE a.id = ? AND a.deleted_at IS NOT NULL';

		$filas = DB::update($consulta, [$user->year_id, $asignatura_id]);

		if ($filas === 0) {
			return abort(404, 'Esa asignatura no está en la papelera de este año.');
		}

		return 'Retaurada';
	}


	public function deleteDestroy($id)
	{
		$asignatura = Asignatura::findOrFail($id);
		$asignatura->delete();

		return $asignatura;
	}

}

