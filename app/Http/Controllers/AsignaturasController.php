<?php namespace App\Http\Controllers;


use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;

use App\User;
use App\Models\Year;
use App\Models\Periodo;
use App\Models\Profesor;
use App\Models\Asignatura;
use App\Models\Unidad;
use Carbon\Carbon;

use App\Http\Controllers\Alumnos\Solicitudes;
use Illuminate\Support\Facades\Log;
use App\Support\CamposQueVinieron;
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

		// Columnas nombradas, no `u.*`: `unidades.alumno_id` existe desde el 24 ago
		// 2026 (19-boletin-independiente.md) y con `*` entraría en la respuesta,
		// moviendo la instantánea de contrato. §5.bis de noche-2026-08-24/bi-1.md.
		$cons_unidades 		= 'SELECT u.id, u.definicion, u.porcentaje, u.periodo_id, u.asignatura_id, u.obligatoria, u.orden, u.por_defecto, u.fecha, u.created_by, u.updated_by, u.deleted_by, u.deleted_at, u.created_at, u.updated_at, p.numero as numero_periodo FROM unidades u
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
		
		// El `UPDATE` de abajo no lleva `deleted_at is null` ni `WHERE` que falle: sin
		// esto, la ruta contestaba **'Cambiado' pasara lo que pasara**. Medido, y las
		// tres salidas eran distintas y ninguna se notaba:
		//
		// - sin `asignatura_id` ninguno: 200 'Cambiado' **sin escribir nada** — la
		//   familia de `respuestas-que-mienten.py`, y sale además en
		//   `identificadores-del-cuerpo.py` como id del cuerpo que no comprueba nadie;
		// - sobre una asignatura de la **papelera**: 200 y **escribe**, en una fila que
		//   ninguna pantalla enseña;
		// - y como `ColumnaSegura` valida el NOMBRE de la columna pero no limita cuál,
		//   con `dia: 'profesor_id'` esto **reasigna el profesor** de esa fila.
		//
		// Se cierra lo que no puede querer ningún cliente —una fila que no está— y se
		// deja lo que es decisión tomada: quien pasa `auth.personal` escribe las
		// asignaturas de todo el colegio, incluidas las de otros años, porque las 44
		// rutas de escritura de la configuración académica están abiertas a propósito.
		// §96.
		if (! Asignatura::where('id', $asignatura_id)->exists()) {
			abort(404, 'La asignatura no existe o está en la papelera.');
		}

		$consulta 	= 'UPDATE asignaturas SET '.ColumnaSegura::exigir('asignaturas', $dia).'=:valor, updated_by=:modificador, updated_at=:fecha WHERE id=:asignatura_id';
		DB::update($consulta, [$valor, $user->user_id, $now, $asignatura_id]);
		
		return 'Cambiado';
	}

	
	public function putUpdate($id)
	{
		$asignatura = Asignatura::findOrFail($id);

		// **Antes de `fixInputs()`, y aquí sí hace falta la clase.** `fixInputs` hace
		// tres `Request::merge()`, así que a partir de la línea siguiente
		// `Request::has('profesor_id')` es cierto aunque el cliente no lo mandara
		// nunca, y el defecto de `Request::input()` mediría otra cosa. Ése es
		// exactamente el motivo por el que `CamposQueVinieron` es una clase y no un
		// `if` — ver su docblock y la §68. En `years/guardar-cambios` y en
		// `unidades/update` no hay ningún `merge` y el defecto basta: mismo fallo, dos
		// herramientas, y el discriminador es si hay un `merge` delante.
		$vinieron = CamposQueVinieron::capturar();

		$this->fixInputs();

		// Lo que el cuerpo no trae, no se toca. Antes iba `Request::input('x')` a
		// secas en cuatro de las cinco —`profesor_id` ya llevaba defecto, y esa
		// asimetría es lo único que se veía—, así que un cuerpo con los tres ids
		// dejaba `creditos` y `orden` en null y contestaba 200. Medido: 2 -> NULL. §96.
		if ($vinieron->trae('materia_id') || $vinieron->trae('materia')) {
			$asignatura->materia_id	=	Request::input('materia_id');
		}

		if ($vinieron->trae('grupo_id') || $vinieron->trae('grupo')) {
			$asignatura->grupo_id	=	Request::input('grupo_id');
		}

		if ($vinieron->trae('profesor_id') || $vinieron->trae('profesor')) {
			$asignatura->profesor_id	=	Request::input('profesor_id');
		}

		if ($vinieron->trae('creditos')) {
			$asignatura->creditos	=	Request::input('creditos');
		}

		if ($vinieron->trae('orden')) {
			$asignatura->orden		=	Request::input('orden');
		}

		$asignatura->save();
		return $asignatura;
	}

	/**
	 * La pantalla manda `{profesor: {profesor_id}}` o `{profesor_id}`, según por dónde
	 * se guarde; esto aplana lo primero en lo segundo.
	 *
	 * Iba con `Request::input('profesor')['profesor_id']`, o sea **indexando lo que
	 * devuelve `input()` sin saber si es un array**. Con la clave ausente eso es
	 * indexar null: un aviso de PHP que Laravel sube a excepción porque
	 * `HandleExceptions::bootstrap` hace `error_reporting(-1)` — es la §69, y aquí
	 * salía como **500** en vez del 422 de allí, porque este método no está dentro de
	 * ningún `try`. Alcanzable desde la pantalla: `AsignaturasCtrl.editar` rellena
	 * `row.profesor` con un `filter` por `profesor_id`, y en una asignatura **sin
	 * profesor** —la columna es nulable— ese filtro devuelve vacío y la clave no
	 * viaja. En el seed hay 0 asignaturas así, o sea que la población en producción
	 * está sin medir: es una mina, no un fallo vivo comprobado.
	 *
	 * La notación con puntos de `input()` es nula-segura y hace lo mismo sin indexar
	 * nada. §96.
	 */
	private function fixInputs()
	{
		if (!Request::input('profesor_id') and Request::input('profesor.profesor_id')) {
			Request::merge(array('profesor_id' => Request::input('profesor.profesor_id') ) );
		}

		if (!Request::input('grupo_id') and Request::input('grupo.id')) {
			Request::merge(array('grupo_id' => Request::input('grupo.id') ) );
		}

		if (!Request::input('materia_id') and Request::input('materia.id')) {
			Request::merge(array('materia_id' => Request::input('materia.id') ) );
		}
	}


	public function getListasignaturas($persona_id='')
	{
		$user = User::fromToken();
		$info_profesor = false;

		if ($persona_id=='') {
			$persona_id = $user->persona_id;
		}else{
			// `Profesor::detallado` acaba en `return $profesor[0];` sin comprobar que
			// la consulta trajera fila: un id que no existe o uno de la papelera era
			// 500. Se para en el llamante y **no** en `App\Models\Profesor`, que lo
			// comparten seis de tres dominios. §96.
			if (! Profesor::where('id', $persona_id)->exists()) {
				abort(404, 'El profesor no existe o está en la papelera.');
			}

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

		// `Year::de_un_periodo` hace `Periodo::find(...)->year_id` sin comprobar la
		// fila: con un periodo que no existe **o uno de la papelera** —`find` respeta
		// el borrado suave— eso es 500. Se para aquí y no en el modelo aunque hoy sea
		// su único llamante: el 404 es una decisión de la ruta, y el modelo sólo sabe
		// de años. Mismo criterio que con `Profesor::detallado`. §96.
		if (! Periodo::where('id', $periodo_id)->exists()) {
			abort(404, 'El periodo no existe o está en la papelera.');
		}

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

