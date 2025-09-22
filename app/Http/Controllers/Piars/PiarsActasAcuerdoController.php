<?php namespace App\Http\Controllers\Piars;

use Request;
use DB;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Piars\Utils\UploadDocuments;

use App\User;
use Carbon\Carbon;
use File;
use \Log;

class PiarsActasAcuerdoController extends Controller {
	public $user;

	public function __construct()
	{
		$this->user = User::fromToken();
	}

	public function getMatriculas($grupo_id)
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

	public function postDocument()
	{
		if ($this->user->tipo != 'Usuario' && $this->user->tipo != 'Profesor') {
			response()->json(['error' => 'Unknownthorized'], 400);
		}
		Request::validate([
			'file' => 'required',
			'alumno_id' => 'required',
			'year_id' => 'required',
		]);

		$now 		= Carbon::now('America/Bogota');
		$fullPath 	= UploadDocuments::save_document($this->user);
		$alumno_id 	= Request::input('alumno_id');
		$year_id 	= Request::input('year_id');

		$consulta 		= 'SELECT * FROM piars_actas_acuerdo WHERE alumno_id=? and year_id=?';
		$actas 	= DB::select($consulta, [$alumno_id, $year_id]);
		$document = '';

		if (count($actas) > 0) {
			$record = [
				'documento' => $actas[0]->documento,
				'updated_at' => $now,
				'updated_by' => $this->user->user_id,
				'updated_by_name' => $this->user->nombres . ' - ' . $this->user->username,
			];

			$arr = json_decode($actas[0]->history);
			$newArra = [];
			try {
				array_push($arr, $record);
				$newArra = $arr;
			} catch (\Throwable $th) {
				// nothing
			}
			$arr = json_encode($newArra);

			$consulta = "UPDATE piars_actas_acuerdo SET documento=?, history=? WHERE alumno_id=? and year_id=?";
			$document = DB::update($consulta, [$fullPath, $arr, $alumno_id, $year_id]);
		} else {
			$consulta_insert = 'INSERT INTO piars_actas_acuerdo(alumno_id, year_id, documento, history)
				VALUES(:alumno_id, :year_id, :documento, :history)';

			$record = [
				'documento' => $fullPath,
				'created_at' => $now,
				'updated_by' => $this->user->user_id,
				'updated_by_name' => $this->user->nombres . ' - ' . $this->user->username,
			];

			$arr = [];
			try {
				array_push($arr, $record);
			} catch (\Throwable $th) {
				// nothing
			}
			$arr = json_encode($arr);

			DB::insert($consulta_insert, [
				':alumno_id' => $alumno_id,
				':year_id' => $year_id,
				':documento' => $fullPath,
				':history' => $arr,
			]);
		}
		return ['document' => $document];
	}

	public function deleteDocument($alumno_id)
	{
		if ($this->user->tipo != 'Usuario' && $this->user->tipo != 'Profesor') {
			response()->json(['error' => 'Unknownthorized'], 400);
		}

		$now 				= Carbon::now('America/Bogota');
		$year_id 			= Request::input('yearId');

		$consulta = 'SELECT * FROM piars_actas_acuerdo WHERE alumno_id=? and year_id=?';
		$alumno_piar = DB::select($consulta, [$alumno_id, $year_id]);

		if (count($alumno_piar) > 0) {
			$documentValue = $alumno_piar[0]->documento;
			$fileToDelete = $documentValue;

			$record = [
				'documento' => $documentValue,
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

			$consulta = "UPDATE piars_actas_acuerdo SET documento=null, history=? WHERE alumno_id=? and year_id=?";
			$document = DB::update($consulta, [$arr, $alumno_id, $year_id]);

			$filename 	= 'uploads/'.$fileToDelete;
		
			if (File::exists($filename)) {
				File::delete($filename);
			}else{
				Log::info($imagen_id . ' -- Al parecer NO existe archivo: ' . $filename);
			}
		}
		return ['document' => $document];
	}
}