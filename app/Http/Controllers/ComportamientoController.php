<?php namespace App\Http\Controllers;

use Request;
use DB;

use App\User;
use App\Models\NotaComportamiento;
use App\Models\Grupo;
use App\Models\Alumno;
use App\Models\Frase;

use Carbon\Carbon;


class ComportamientoController extends Controller {

	public function getIndex()
	{
		$user = User::fromToken();
		return NotaComportamiento::all();
	}



	public function putSituacionesPorGrupos()
	{
		$user = User::fromToken();

		

		return $nota;
	}


	/*
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
	*/

}