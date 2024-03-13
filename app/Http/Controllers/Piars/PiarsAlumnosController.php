<?php namespace App\Http\Controllers\Piars;

use Illuminate\Http\Request;
use DB;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Piars\Utils\UploadDocuments;

use App\User;
use Carbon\Carbon;

class PiarsAlumnosController extends Controller {
	public $user;

	public function __construct()
	{
		$this->user = User::fromToken();
	}

	public function getAlumnos($grupo_id)
	{
		$alumnos = DB::select('SELECT a.id, a.nombres, a.apellidos, a.sexo, m.estado,
						a.foto_id, IFNULL(i.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as foto_nombre, 
						m.estado  
					FROM alumnos a
					INNER JOIN matriculas m ON m.alumno_id=a.id and m.deleted_at is null and (m.estado="ASIS" or m.estado="MATR")
					LEFT JOIN images i on i.id=a.foto_id and i.deleted_at is null
					WHERE a.deleted_at is null and m.grupo_id=?'
					, [$grupo_id]);

		return ['alumnos' => $alumnos];
	}

	public function postDocument(Request $request)
	{
		if ($this->user->tipo != 'Usuario' && $this->user->tipo != 'Profesor') {
			response()->json(['error' => 'Unknownthorized'], 400);
		}
		$request->validate([
			'file' => 'required',
			'alumno_id' => 'required',
		]);

		$field = $request->documentField;

		// campos seguros para evitar ataques sql injection
		$validFields = ['documento1', 'documento2'];
		if (!in_array($field, $validFields)) {
			return response()->json(['error' => 'Invalid'], 400);
		}

		$now 				= Carbon::now('America/Bogota');
		$fullPath 	= UploadDocuments::save_document($this->user);
		$alumno_id 	= $request->alumno_id;

		$consulta = 'SELECT * FROM piars_alumnos WHERE alumno_id=? AND year_id=?';
		$alumno_piar = DB::select($consulta, [$request->alumno_id, $this->user->year_id]);

		if (count($alumno_piar) > 0) {
			$record = [
				'documento1' => $alumno_piar[0]->documento1,
				'documento2' => $alumno_piar[0]->documento2,
				'updated_at' => $now,
				'updated_by' => $this->user->user_id,
				'updated_by_name' => $this->user->nombres . ' - ' . $this->user->username,
			];

			$arr = json_decode($alumno_piar[0]->history);
			$newArra = [];
			try {
				array_push($arr, $record);
				$newArra = $arr;
			} catch (\Throwable $th) {
				// nothing
			}
			$arr = json_encode($newArra);

			$consulta = "UPDATE piars_alumnos SET $field=?, history=? WHERE alumno_id=? AND year_id=?";
			$document = DB::update($consulta, [$fullPath, $arr, $alumno_id, $this->user->year_id]);
		}
		return ['document' => $document];
	}
}