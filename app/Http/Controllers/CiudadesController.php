<?php namespace App\Http\Controllers;

use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\DB;

use App\Models\Ciudad;
use App\Models\Pais;
use App\Http\Controllers\Concerns\ResuelveElUsuario;


class CiudadesController extends Controller {
	use ResuelveElUsuario;

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


	/**
	 * Lo que abre la pantalla de editar la ficha de un alumno: su ciudad y los
	 * desplegables con los que se cambia.
	 *
	 * El `$pais[0]` estaba desnudo, y una ciudad SIN PAÍS no es hipotética: la
	 * columna admite NULL y `postGuardarCiudad` escribe `pais_id` tal como llega,
	 * así que basta guardar una ciudad sin país —dos rutas, ida y vuelta— para que
	 * esta responda 500 «Undefined array key 0» y la ficha de todo alumno nacido
	 * ahí deje de abrir. Se midió así, ejecutando las dos, y no leyendo esta.
	 * Ver 05 §85.
	 *
	 * Devuelve la misma forma con el país vacío en vez de reventar: **las seis
	 * claves se quedan**. Encogerla sería contrato con dieciséis copias del front,
	 * y quien la llama ya sabe tratar el `[]` de la ciudad que no existe.
	 */
	public function getDatosciudad($ciudad_id)
	{
		$ciudad = Ciudad::find($ciudad_id);
		if ($ciudad) {
			$pais = $this->getPaisdeciudad($ciudad->id);
			$pais = count($pais) > 0 ? $pais[0] : null;

			$departamentos = $pais ? $this->getDepartamentos($pais->id) : [];
			$ciudades = Ciudad::where('departamento' , $ciudad->departamento)->get();

			$result = array('ciudad' => $ciudad, 
							'ciudades' => $ciudades, 
							'departamento' => array('departamento'=>$ciudad->departamento), 
							'departamentos' => $departamentos,
							'pais'=> $pais,
							'paises' => Pais::all());
			return $result;
		}else{
			// 200 con [] y no 404: es lo que devuelve desde siempre y lo que el front
			// distingue de una respuesta con ciudad. No se juzgó; queda fijado.
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
		} catch (\Exception $e) {
			abort(422, 'Datos incorrectos');
		}
	}


	public function putActualizarCiudad()
	{
		// 404 y no 500: con un id que no existe, `find()` devolvía null y la línea
		// de abajo reventaba. Ver 05 §52.
		$city 				= Ciudad::findOrFail(Request::input('id'));
		$city->ciudad 		= Request::input('ciudad');
		$city->departamento = Request::input('departamento');
		$city->save();
		return $city;
	}

	public function putActualizarDepartamento()
	{
		$newDepart 	= Request::input('departamento');
		// Ver 05 §52: mismo caso que su hermana de arriba.
		$city 		= Ciudad::findOrFail(Request::input('id'));
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