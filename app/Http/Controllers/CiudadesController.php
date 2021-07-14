<?php namespace App\Http\Controllers;

use Request;
use DB;

use App\User;
use App\Models\Ciudad;
use App\Models\Pais;


class CiudadesController extends Controller {


	public $user;
	
	public function __construct()
	{
		$this->user = User::fromToken();
	}

		

	public function getIndex()
	{
		return Ciudad::all();
	}

	
	// Se podrá eliminar cuando modifique AlumnoEditarCtrl
	public function getDepartamentos($pais_id)
	{	
		$consulta = 'SELECT distinct departamento FROM ciudades where pais_id = :pais and deleted_at is null order by departamento';
		return DB::select($consulta, ['pais' => $pais_id]);
	}

	public function getByDepartamento()
	{	
		$consulta = 'SELECT * FROM ciudades where departamento = :departamento and deleted_at is null order by ciudad';
		return DB::select($consulta, ['departamento' => Request::input('departamento') ]);
	}

	public function putDepartamentosById()
	{	
		//DB::enableQueryLog();
		
		$consulta = 'SELECT distinct departamento FROM ciudades where pais_id = :pais and deleted_at is null order by departamento';
		$departamentos = ['departamentos' => DB::select($consulta, ['pais' => Request::input('pais_id') ] ) ];
		//return $laQuery = DB::getQueryLog();

		return $departamentos;
	}

	public function getPaisdeciudad($ciudad_id)
	{	
		$consulta = 'SELECT paises.id, pais, abrev FROM paises, ciudades where paises.id = ciudades.pais_id and ciudades.id = :ciudad_id and ciudades.deleted_at is null and paises.deleted_at is null';
		return DB::select($consulta, ['ciudad_id' => $ciudad_id]);
	}

	public function getPorDepartamento($departamento)
	{
		return Ciudad::where('departamento', $departamento)->get();
	}


	public function getDatosciudad($ciudad_id)
	{
		$ciudad = Ciudad::find($ciudad_id);
		if ($ciudad) {
			$pais = $this->getPaisdeciudad($ciudad->id);

			$departamentos = $this->getDepartamentos($pais[0]->id);
			$ciudades = Ciudad::where('departamento' , $ciudad->departamento)->get();

			$result = array('ciudad' => $ciudad, 
							'ciudades' => $ciudades, 
							'departamento' => array('departamento'=>$ciudad->departamento), 
							'departamentos' => $departamentos,
							'pais'=> $pais[0],
							'paises' => Pais::all());
			return $result;
		}else{
			return [];
		}
		
	}


	public function postGuardarCiudad()
	{
		
		try {
			$ciudad = new Ciudad;
			$ciudad->ciudad			=	Request::input('ciudad');
			$ciudad->departamento	=	Request::input('departamento');
			$ciudad->pais_id		=	Request::input('pais_id');
			$ciudad->save();
			
			return $ciudad;
		} catch (Exception $e) {
			return $e;
		}
	}


	public function putActualizarCiudad()
	{
		$city 				= Ciudad::find(Request::input('id'));
		$city->ciudad 		= Request::input('ciudad');
		$city->departamento = Request::input('departamento');
		$city->save();
		return $city;
	}

	public function putActualizarDepartamento()
	{
		$newDepart 	= Request::input('departamento');
		$city 		= Ciudad::find(Request::input('id'));
		DB::table('ciudades')
            ->where('departamento', $city->departamento)
            ->update(['departamento' => $newDepart]);
		return $city;
	}



	public function deleteDestroy($id)
	{
		$ciudad = Ciudad::findOrFail($id);
		$ciudad->delete();

		return $ciudad;
	}

}