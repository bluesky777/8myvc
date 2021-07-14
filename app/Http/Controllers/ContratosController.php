<?php namespace App\Http\Controllers;



use Request;
use DB;

use App\User;
use App\Models\Contrato;
use App\Models\Profesor;



class ContratosController extends Controller {

	public function getIndex()
	{
		$user = User::fromToken();
		$profesores = Profesor::contratos($user->year_id);
		return $profesores;
	}

	public function postIndex()
	{

		$user = User::fromToken();

		$consulta = 'SELECT p.id as profesor_id, p.nombres
				from profesores p
				inner join contratos c on c.profesor_id=p.id and c.year_id=:year_id and c.profesor_id=:profesor_id and c.deleted_at is null 
				left join users u on p.user_id=u.id and u.is_Active=false
				where p.deleted_at is null';

		$contratado = DB::select($consulta, array(':year_id'=>$user->year_id, ':profesor_id' => Request::input('profesor_id')));

		if (count($contratado) > 0) {
			return response()->json([ 'contratado'=> true, 'msg'=> 'Profesor ya contratado' ], 400);
		}

		$contrato = new Contrato;
		$contrato->profesor_id	=	Request::input('profesor_id');
		$contrato->year_id		=	$user->year_id;
		$contrato->save();


		$consulta = 'SELECT p.id as profesor_id, p.nombres, p.apellidos, p.sexo, p.foto_id, p.tipo_doc,
					p.num_doc, p.ciudad_doc, p.fecha_nac, p.ciudad_nac, p.titulo,
					p.estado_civil, p.barrio, p.direccion, p.telefono, p.celular,
					p.facebook, p.email, p.tipo_profesor, p.user_id, u.username,
					u.email as email_usu, u.imagen_id, u.is_superuser,
					c.id as contrato_id, c.year_id
				from profesores p
				inner join contratos c on c.profesor_id=p.id and c.id=:contrato_id
				left join users u on p.user_id=u.id and u.is_Active=false
				where p.deleted_at is null';

		$profesor = DB::select(DB::raw($consulta), array(':contrato_id' => $contrato->id));

		return $profesor;
	}



	public function deleteDestroy($id)
	{
		$user = User::fromToken();

		$contr = Contrato::destroy($id);
		return $contr;
	}

}