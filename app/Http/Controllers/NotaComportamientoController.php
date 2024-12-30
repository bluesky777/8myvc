<?php namespace App\Http\Controllers;

use Request;
use DB;

use App\User;
use App\Models\NotaComportamiento;
use App\Models\Grupo;
use App\Models\Alumno;
use App\Models\Frase;

use Carbon\Carbon;


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

		$consulta = 'UPDATE dis_libro_rojo SET '.$campo.'=:valor WHERE id=:libro_id';
		DB::update($consulta, [$valor, $libro_id]);

		return 'Cambiado';
	}

	public function getDetailed($grupo_id)
	{
		$user = User::fromToken();
		$nota_max = DB::select('SELECT id, desempenio, porc_inicial, porc_final FROM escalas_de_valoracion 
					where deleted_at is null and year_id=? order by orden desc limit 1', [$user->year_id])[0];
		$nota_max = $nota_max->porc_final;
		$alumnos = Grupo::alumnos($grupo_id);

		foreach ($alumnos as $alumno) {

			//$userData = Alumno::userData($alumno->alumno_id);
			//$alumno->userData = $userData;
			$alumno->escrita 		= 'escribir';
			$alumno->tipo_frase 	= ['tipo_frase' => 'Todas'];

			$nota = NotaComportamiento::crearVerifNota($alumno->alumno_id, $user->periodo_id, $nota_max);

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

		$nota = new NotaComportamiento;

		$nota->alumno_id	=	Request::input('alumno_id');
		$nota->periodo_id	=	$user->periodo_id;
		$nota->nota			=	Request::input('nota');

		$nota->save();
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

		$nota = NotaComportamiento::findOrFail($id);

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
		return $nota;
	}



	public function putCrear()
	{
		$user 	= User::fromToken();
		$now 	= Carbon::now('America/Bogota');

		DB::insert('INSERT INTO nota_comportamiento (alumno_id, periodo_id, nota, created_at, updated_at) VALUES (?,?,?,?,?)', 
			[ Request::input('alumno_id'), Request::input('periodo_id'), Request::input('nota'), $now, $now ]);

		$last_id = DB::getPdo()->lastInsertId();


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
		$nota = NotaComportamiento::findOrFail($id);
		$nota->delete();

		return $nota;
	}

}