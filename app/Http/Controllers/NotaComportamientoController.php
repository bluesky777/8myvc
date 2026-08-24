<?php namespace App\Http\Controllers;

use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\DB;

use App\User;
use App\Models\NotaComportamiento;
use App\Models\Grupo;
use App\Models\Alumno;
use App\Models\Frase;

use Carbon\Carbon;
use App\Services\Auditoria;
use App\Support\ColumnaSegura;
use App\Support\NombreDelAlumno;
use App\Support\PeriodoDeLaFila;


class NotaComportamientoController extends Controller {

	public function getIndex()
	{
		$user = User::fromToken();
		return NotaComportamiento::all();
	}

	public function putGuardarLibro()
	{
		$user = User::fromToken();
		$valor = Request::input('valor');
		$campo = Request::input('campo');
		$libro_id = Request::input('libro_id');

		$columna = ColumnaSegura::exigir('dis_libro_rojo', $campo);

		// El valor de antes, leído por id **antes** de pisarlo. Es una consulta a
		// una fila por clave primaria, y es lo que permite que la línea diga «de
		// qué a qué» en vez de sólo «alguien tocó esto». En el libro rojo esa
		// diferencia es la que importa: es el registro disciplinario de un alumno.
		//
		// `$columna` sale de `ColumnaSegura::exigir`, que la comprueba contra el
		// esquema **y la devuelve ya entre acentos graves** — por eso aquí no se le
		// ponen otros. Ponérselos da ``per1_col1``, que es un error de sintaxis de
		// MySQL y no un fallo de permisos, así que se lee como cualquier otra cosa.
		$antes = DB::selectOne('SELECT '.$columna.' AS valor, alumno_id FROM dis_libro_rojo WHERE id = ?', [$libro_id]);

		$consulta = 'UPDATE dis_libro_rojo SET '.$columna.'=:valor WHERE id=:libro_id';
		DB::update($consulta, [$valor, $libro_id]);

		// Un reguardado sin cambio **también** deja línea, y está bien: MySQL
		// devuelve 0 filas afectadas al guardar el mismo valor encima, y alguien
		// tocó el libro igual. Se reconoce solo porque los dos valores quedan
		// iguales (18 §4.1) — por eso esta clase no recibe «cuántas filas salieron».
		//
		// **El `$antes === null` no es una guarda sobre «cuántas filas salieron»**,
		// que es lo que esta clase no admite a propósito: es que el libro pedido
		// **no existe**, y eso se sabe antes de escribir. Este método contesta
		// `'Cambiado'` con 200 igual —es la familia de `respuestas-que-mienten.py`,
		// y tiene su propio test—, así que la respuesta no distingue los dos casos.
		//
		// La línea se escribe en los dos, sin condicionar nada: lo que cambia es que
		// con el libro inexistente `valor_anterior` queda en **NULL de SQL**, que
		// significa «no había valor antes» (18 §5.3). O sea que la auditoría sí
		// distingue lo que la respuesta no distingue.
		$alumnoDelLibro = $antes === null || ! is_numeric($antes->alumno_id) ? null : (int) $antes->alumno_id;

		Auditoria::registrar()
			->editar('dis_libro_rojo', is_numeric($libro_id) ? (int) $libro_id : null)
			->deAlumno($alumnoDelLibro, NombreDelAlumno::de($alumnoDelLibro))
			->de($antes === null ? null : [$campo => $antes->valor])
			->a([$campo => $valor])
			->guardar();

		return 'Cambiado';
	}

	public function getDetailed($grupo_id)
	{
		$user = User::fromToken();
		$nota_max = DB::select('SELECT id, desempenio, porc_inicial, porc_final FROM escalas_de_valoracion 
					where deleted_at is null and year_id=? order by orden desc limit 1', [$user->year_id])[0];
		$nota_max = $nota_max->porc_final;
		$alumnos = Grupo::alumnos($grupo_id);

		// Una consulta para el grupo entero: este método puede crear el libro rojo
		// de los cuarenta alumnos en la primera visita, y cada alta lleva su línea.
		NombreDelAlumno::deVarios(array_column($alumnos, 'alumno_id'));

		foreach ($alumnos as $alumno) {

			//$userData = Alumno::userData($alumno->alumno_id);
			//$alumno->userData = $userData;
			$alumno->escrita 		= 'escribir';
			$alumno->tipo_frase 	= ['tipo_frase' => 'Todas'];

			// **Esta rejilla no se lee: se fabrica.** `crearVerifNota()` crea la nota
			// de comportamiento del alumno si no existe, y hasta hoy lo hacía
			// **también con el periodo cerrado** — era la única de las cinco
			// escrituras de este controlador que no preguntaba por el interruptor,
			// mientras `postStore`, `putUpdate`, `putCrear` y `deleteDestroy` sí.
			// Ver 05 §133.
			//
			// El criterio no se inventa aquí y no lleva `abort()`: es **el mismo que
			// decidió Joseth para `unidades/de-asignatura-periodo`** (§47.2), que es
			// exactamente la misma forma —un GET que lee y de paso crea—. Allí quedó
			// escrito: *«enseña lo que hay y no crea nada»*. Un `abort()` aquí
			// apagaría la LECTURA de la rejilla en un periodo cerrado, que es justo
			// la que un profesor va a querer consultar cuando esté cerrado.
			//
			// Por eso `permiteEditarNotas()` —booleana— y no `pueden_editar_notas()`.
			//
			// Y la nota sin crear no rompe al cliente: el front **ya distingue el
			// caso**, con un `if (nota.id) actualizar(); else crear();` en
			// `NotasAlumnoCtrl` y en `PromocionarNotasCtrl`. Sin `id` toma la rama de
			// crear, que con el periodo cerrado recibe su 400 de `putCrear` — el
			// mismo aviso que recibía antes al intentar guardar, pero sin haber
			// escrito nada por el camino.
			$puedeEscribir = User::permiteEditarNotas($user, (int) $user->periodo_id);

			$nota = $puedeEscribir
				? NotaComportamiento::crearVerifNota($alumno->alumno_id, $user->periodo_id, $nota_max)
				: NotaComportamiento::firstOrNew([
					'alumno_id' => $alumno->alumno_id,
					'periodo_id' => $user->periodo_id,
				]);

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

			$definiciones = DB::select($consulta, array('comportamiento1_id' => $nota->id, 'comportamiento2_id' => $nota->id));
			
			$alumno->definiciones = $definiciones;
			$alumno->nota = $nota;


			// Traido el libro
			$consulta 	= 'SELECT d.* FROM dis_libro_rojo d 
				WHERE alumno_id=? and d.year_id=? and d.deleted_at is null';
				
			$libro 	= DB::select($consulta, [ $alumno->alumno_id, $user->year_id ]);
			
			if (count($libro) > 0) {
				$libro = $libro[0];
			}else{
				$consulta_crear = 'INSERT INTO dis_libro_rojo (alumno_id, year_id, updated_by) VALUES (?,?,?)';
				DB::insert($consulta_crear, [ $alumno->alumno_id, $user->year_id, $user->user_id ]);

				// `porElSistema()` y no el profesor que abrió la pantalla, aunque
				// sea su `user_id` el que queda en `updated_by`.
				//
				// **Este método es un GET y escribe**: el libro rojo nace solo la
				// primera vez que alguien mira el grupo. Quien abrió la pantalla no
				// decidió crear nada, y anotarlo como suyo pondría en la pantalla de
				// la fase 5 «Fulano creó el libro rojo de cuarenta alumnos» — que es
				// la clase de ruido que hace que un historial deje de leerse (18 §4,
				// punto 3). Es el mismo motivo por el que la definitiva que
				// **recalcula** el sistema no entra como la que un profesor teclea.
				$alumnoDeLaLinea = $alumno->alumno_id === null ? null : (int) $alumno->alumno_id;

				Auditoria::registrar()
					->crear('dis_libro_rojo', (int) DB::getPdo()->lastInsertId())
					->porElSistema()
					->deAlumno($alumnoDeLaLinea, NombreDelAlumno::de($alumnoDeLaLinea))
					->en(year: (int) $user->year_id)
					->resumen('El libro rojo se creó al abrir la pantalla del grupo')
					->guardar();

				$consulta 	= 'SELECT d.* FROM dis_libro_rojo d 
					WHERE alumno_id=? and d.year_id=? and d.deleted_at is null';
					
				$libro 	= DB::select($consulta, [ $alumno->alumno_id, $user->year_id ]);
				if (count($libro) > 0) {
					$libro = $libro[0];
				}
			}
			$alumno->libro = $libro;


			// Traido los procesos
			$consulta 	= 'SELECT d.*, SUBSTRING(d.fecha_hora_aprox, 1, 10) as fecha_corta, CONCAT(p.nombres, " ", p.apellidos) as profesor_nombre 
				FROM dis_procesos d 
				LEFT JOIN profesores p ON p.id=d.profesor_id and p.deleted_at is null
				WHERE alumno_id=? and d.periodo_id=? and d.deleted_at is null';
				
			$procesos 	= DB::select($consulta, [ $alumno->alumno_id, $user->periodo_id ]);
			
			for ($k=0; $k < count($procesos); $k++) { 
				$consulta 	= 'SELECT d.* FROM dis_proceso_ordinales d WHERE proceso_id=? and d.deleted_at is null';
				$procesos[$k]->proceso_ordinales 	= DB::select($consulta, [ $procesos[$k]->id ]);
			}
			$alumno->procesos_disciplinarios = $procesos;
		}

		$frases = Frase::where('year_id', '=', $user->year_id)->get();
		$grupo = Grupo::find($grupo_id);

		$resultado = [];

		array_push($resultado, $frases);
		array_push($resultado, $alumnos);
		array_push($resultado, $grupo);

		return $resultado;
	}

	public function postStore()
	{
		$user = User::fromToken();
		// La fila se escribe en el periodo del profesor tres líneas más abajo.
		User::pueden_editar_notas($user, (int) $user->periodo_id);

		$nota = new NotaComportamiento;

		$nota->alumno_id	=	Request::input('alumno_id');
		$nota->periodo_id	=	$user->periodo_id;
		$nota->nota			=	Request::input('nota');

		$nota->save();

		$alumnoDeLaLinea = (int) $nota->alumno_id;

		Auditoria::registrar()
			->crear('comportamiento', (int) $nota->id)
			->deAlumno($alumnoDeLaLinea, NombreDelAlumno::de($alumnoDeLaLinea))
			->en(periodo: (int) $nota->periodo_id)
			->a(['nota' => $nota->nota])
			->guardar();

		return $nota;
	}


	
	
	public function putFrasesCheck()
	{
		$texto = Request::input('texto');

		$consulta = 'SELECT d.frase
			FROM definiciones_comportamiento d
			WHERE d.deleted_at is null and frase like :texto
			GROUP BY d.frase order by d.frase';
			// INNER JOIN matriculas para evitar que se repita. Sólo traerá los que tengan alguna matricula en el sistema.
	
		$res = DB::select($consulta, [':texto' => '%'.$texto.'%']);
		return [ 'frases' => $res ];

	}



	public function putUpdate($id)
	{
		$user = User::fromToken();
		User::pueden_editar_notas($user, PeriodoDeLaFila::deNotaComportamiento($id));

		$nota = NotaComportamiento::findOrFail($id);

		// Los tres valores de antes, capturados **antes** de que los `if` los
		// pisen. Después del primer `if` ya no se pueden leer, y sin ellos la
		// línea contaría que alguien cambió la nota de comportamiento pero no
		// desde qué — que es la mitad de la pregunta en un registro que el
		// acudiente puede reclamar.
		$antes = [
			'nota' => $nota->nota,
			'familiar_nota' => $nota->familiar_nota,
			'familiar_ausencias' => $nota->familiar_ausencias,
		];

		if (Request::has('nota')) {
			$nota->nota = Request::input('nota');
		}

		if (Request::has('familiar_nota')) {
			$nota->familiar_nota = Request::input('familiar_nota');
		}

		if (Request::has('familiar_ausencias')) {
			$nota->familiar_ausencias = Request::input('familiar_ausencias');
		}

		$nota->save();
		$nota = NotaComportamiento::findOrFail($id);

		$alumnoDeLaLinea = (int) $nota->alumno_id;

		Auditoria::registrar()
			->editar('comportamiento', (int) $nota->id)
			->deAlumno($alumnoDeLaLinea, NombreDelAlumno::de($alumnoDeLaLinea))
			->en(periodo: (int) $nota->periodo_id)
			->de($antes)
			->a([
				'nota' => $nota->nota,
				'familiar_nota' => $nota->familiar_nota,
				'familiar_ausencias' => $nota->familiar_ausencias,
			])
			->guardar();

		return $nota;
	}



	public function putCrear()
	{
		// Aquí el periodo lo nombra el cuerpo, y es también donde se escribe:
		// la comprobación tiene que mirar ése y no el del profesor. Es la
		// lección de la §27 aplicada a una llamada nueva.
		$user 	= User::fromToken();
		User::pueden_editar_notas($user, (int) Request::input('periodo_id'));
		$now 	= Carbon::now('America/Bogota');

		DB::insert('INSERT INTO nota_comportamiento (alumno_id, periodo_id, nota, created_at, updated_at) VALUES (?,?,?,?,?)', 
			[ Request::input('alumno_id'), Request::input('periodo_id'), Request::input('nota'), $now, $now ]);

		$last_id = DB::getPdo()->lastInsertId();

		// El periodo sale del CUERPO en este método y no del profesor —es donde se
		// escribe, y por eso es también lo que mira el permiso tres líneas arriba
		// (§27)—, así que la línea guarda ése.
		$alumnoDeLaLinea = is_numeric(Request::input('alumno_id')) ? (int) Request::input('alumno_id') : null;

		Auditoria::registrar()
			->crear('comportamiento', (int) $last_id)
			->deAlumno($alumnoDeLaLinea, NombreDelAlumno::de($alumnoDeLaLinea))
			->en(periodo: is_numeric(Request::input('periodo_id')) ? (int) Request::input('periodo_id') : null)
			->a(['nota' => Request::input('nota')])
			->guardar();


		$consulta = 'SELECT n.*, p.nombres, p.apellidos, p.sexo, p.id as titular_id,
				p.foto_id, IFNULL(i.nombre, IF(p.sexo="F","default_female.png", "default_male.png")) as foto_nombre
			FROM nota_comportamiento n
			inner join matriculas m on m.alumno_id=n.alumno_id and m.deleted_at is null
			inner join grupos g on g.id=m.grupo_id and g.deleted_at is null and g.year_id=:year_id
			inner join profesores p on p.id=g.titular_id and p.deleted_at is null 
			left join images i on i.id=p.foto_id and i.deleted_at is null
			where n.alumno_id=:alumno_id and n.periodo_id=:periodo_id and n.deleted_at is null';
			
		$nota_comportamiento = DB::select($consulta, [
			':year_id'		=>Request::input('year_id'), 
			':alumno_id'	=>Request::input('alumno_id'), 
			':periodo_id'	=>Request::input('periodo_id')
		]);

		if(count($nota_comportamiento) > 0){
			$nota_comportamiento = $nota_comportamiento[0];
		}else{
			$nota_comportamiento = [];
		}
		return ['nota_comport' => $nota_comportamiento];
	}


	public function deleteDestroy($id)
	{
		User::pueden_editar_notas(User::fromToken(), PeriodoDeLaFila::deNotaComportamiento($id));
		$nota = NotaComportamiento::findOrFail($id);

		// Antes del `delete()` y con `de(...)`: lo que se borró es lo que hay que
		// poder leer después.
		$alumnoDeLaLinea = (int) $nota->alumno_id;

		Auditoria::registrar()
			->borrar('comportamiento', (int) $nota->id)
			->deAlumno($alumnoDeLaLinea, NombreDelAlumno::de($alumnoDeLaLinea))
			->en(periodo: (int) $nota->periodo_id)
			->de(['nota' => $nota->nota])
			->guardar();

		$nota->delete();

		return $nota;
	}

}