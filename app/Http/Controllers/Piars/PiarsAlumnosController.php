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
		$request->validate([
			'file' => 'required',
			'alumno_id' => 'required',
		]);

		$fullPath = UploadDocuments::save_document($this->user);
		$alumno_id = $request->alumno_id;

		$consulta = 'UPDATE piars_alumnos SET documento1=? WHERE alumno_id=?';

		$document = DB::update($consulta, [$fullPath, $alumno_id]);

		return ['document' => $document];
	}
}