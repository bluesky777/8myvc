<?php namespace App\Http\Controllers\Piars;

use Illuminate\Http\Request;
use DB;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Piars\Utils\UploadDocuments;
use App\Models\Year;
use App\Models\Periodo;
use App\Models\Grupo;

use App\User;
use Carbon\Carbon;

class PiarsConfigController extends Controller {
	public $user;

	public function __construct()
	{
		$this->user = User::fromToken();
	}

	public function getIndex()
	{
		$piars_config = DB::select('SELECT a.id, a.reporte_default, a.config, a.updated_at, a.updated_by
					FROM piars_config a')[0];

		$year = Year::datos($this->user->year_id);
		$year->periodos = Periodo::where('year_id', $this->user->year_id)->get();

		return [ "year" => $year, "piarsConfig" => $piars_config ];
	}

	public function putConfig(Request $request)
	{
		if ($this->user->is_superuser) {
			response()->json(['error' => 'Unknownthorized'], 400);
		}
		$request->validate([
			'id' => 'required',
		]);

		$piars_config = DB::select('SELECT a.id, a.reporte_default, a.config, a.updated_at, a.updated_by
        	FROM piars_config a')[0];

		if ($request->reporte_default == null) {
			$reporte_default = $piars_config->reporte_default;
		} else {
			$reporte_default = $request->reporte_default;
		}

		if ($request->config == null) {
			$config = $piars_config->config;
		} else {
			$config = $request->config;
		}

		// campos seguros para evitar ataques sql injection
		$validFields = ['documento1', 'documento2'];
		if (!in_array($field, $validFields)) {
			return response()->json(['error' => 'Invalid'], 400);
		}

		$now 		= Carbon::now('America/Bogota');

		$consulta 	= "UPDATE piars_config SET reporte_default=?, config=? WHERE id=? AND year_id=?";
		$document 	= DB::update($consulta, [$fullPath, $arr, $alumno_id, $this->user->year_id]);

		return ['document' => $document];
	}
}