<?php namespace App\Http\Controllers\Piars;

use App\Services\Auditoria;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Piars\Utils\UploadDocuments;
use App\Support\HtmlDelEditor;

use Carbon\Carbon;
use Illuminate\Support\Facades\File;
use \Log;
use App\Http\Controllers\Concerns\ResuelveElUsuario;

class PiarsAlumnosController extends Controller {
	use ResuelveElUsuario;

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


	public function postDocument()
	{

		Request::validate([
			'file' => 'required',
			'alumno_id' => 'required',
		]);

		$field = Request::input('documentField');

		// campos seguros para evitar ataques sql injection
		$validFields = ['documento1', 'documento2'];

		if (!in_array($field, $validFields)) {
			return response()->json(['error' => 'Invalid'], 400);
		}

		$now 		= Carbon::now('America/Bogota');
		$alumno_id 	= Request::input('alumno_id');

		$consulta 		= 'SELECT * FROM piars_alumnos WHERE alumno_id=?';
		$alumno_piar 	= DB::select($consulta, [$alumno_id]);

		// El archivo se guardaba ANTES de mirar si existía la fila, y si no
		// existía el método terminaba con `$document` sin definir: 500, y el
		// archivo ya escrito en disco sin nada que lo apuntara. La fila la crea
		// `PiarsAlumnoUtils::getAlumnosPiar` al pedir el grupo, así que no
		// haberla significa que ese alumno no tiene PIAR, no un fallo interno.
		if (count($alumno_piar) === 0) {
			abort(404, 'El alumno no tiene PIAR.');
		}

		$fullPath = UploadDocuments::save_document($this->user);

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

		$consulta = "UPDATE piars_alumnos SET $field=?, history=? WHERE alumno_id=?";
		$document = DB::update($consulta, [$fullPath, $arr, $alumno_id]);

		/*
		 * Un PIAR es información de discapacidad de un menor: quién le sube o le
		 * quita un documento es exactamente la clase de pregunta para la que existe
		 * este rastro. El sujeto va como `deAlumno()` porque la fila de
		 * `piars_alumnos` es del alumno y es así como la encuentra la pantalla.
		 *
		 * `$field` no sale del cuerpo sin mirar: viene filtrado por el `in_array`
		 * contra `$validFields` cincuenta líneas más arriba, que es lo que impide
		 * que esta cadena acabe dentro del `UPDATE` de ahí encima.
		 */
		Auditoria::registrar()
			->crear('piar')
			->deAlumno((int) $alumno_id)
			->a(['campo' => $field, 'documento' => $fullPath])
			->resumen('Subió el documento '.$field.' del PIAR')
			->guardar();

		// `documento` es nuevo. El nombre final lo decide el servidor —carpeta
		// `user_<user_id>/` y `(1)`, `(2)`… al chocar, ver SafeUpload— así que
		// el cliente no puede deducirlo: sin esto pintaba un enlace roto hasta
		// que se recargaba la página. `document` se mantiene por si algo lo lee.
		return ['document' => $document, 'documento' => $fullPath];
	}

	public function putField()
	{
		$now = Carbon::now('America/Bogota');

		$id = Request::input('id');
		$field = Request::input('field');
		$text = Request::input('text');
		$updated_at = $now;
		$updated_by = $this->user->user_id;

		// campos seguros para evitar ataques sql injection
		$validFields = ['valoracion_pedagogica', 'ajustes_generales', 'reporte'];
		if (!in_array($field, $validFields)) {
			return response()->json(['error' => 'Invalid'], 400);
		}

		// El texto es HTML del editor y el cliente lo pinta como HTML: lo que no
		// pase por aquí se ejecuta en la sesión de quien abra el PIAR.
		$text = HtmlDelEditor::limpiar($text);

		$consulta = "UPDATE piars_alumnos
			SET $field=?, updated_at=?, updated_by=?
			WHERE id=?";
		$piars = DB::update($consulta, [
			$text, $updated_at, $updated_by, $id,
		]);

		/*
		 * Sin `de()`: el texto de antes exigiría una lectura más y este campo es HTML
		 * del editor, que puede ser largo. Lo que se guarda es QUÉ campo se tocó y
		 * cuándo; el contenido anterior es el hueco conocido de esta línea y se cierra
		 * el día que la pantalla del PIAR pida ver versiones.
		 */
		$delPiar = DB::selectOne('SELECT alumno_id, year_id FROM piars_alumnos WHERE id = ?', [$id]);
		Auditoria::registrar()
			->editar('piar', (int) $id)
			->deAlumno($delPiar ? (int) $delPiar->alumno_id : null)
			->en(year: $delPiar ? (int) $delPiar->year_id : null)
			->resumen('Editó el campo '.$field.' del PIAR')
			->guardar();

    return ['piars' => $piars];
	}

	public function deleteDocument($alumno_id)
	{

		$now 				= Carbon::now('America/Bogota');
		// `file_name` no lleva un nombre de archivo sino la COLUMNA a vaciar
		// (`documento1` o `documento2`); el nombre viene de la fila. Se conserva
		// la clave porque es el contrato que ya usa el cliente.
		$field 			= Request::input('file_name');

		$consulta = 'SELECT * FROM piars_alumnos WHERE alumno_id=?';
		$alumno_piar = DB::select($consulta, [$alumno_id]);

		// campos seguros para evitar ataques sql injection
		$validFields = ['documento1', 'documento2'];
		if (!in_array($field, $validFields)) {
			return response()->json(['error' => 'Invalid'], 400);
		}

		// Sin fila no hay nada que borrar. Antes se caía por `$document` sin
		// definir, que era un 500 diciendo «no existe».
		if (count($alumno_piar) === 0) {
			abort(404, 'El alumno no tiene PIAR.');
		}

		$documentValue1 = $alumno_piar[0]->documento1;
		$documentValue2 = $alumno_piar[0]->documento2;
		$fileToDelete = '';

		if ($field == 'documento1') {
			$fileToDelete = $documentValue1;
			$documentValue1 = null;
		}

		if ($field == 'documento2') {
			$fileToDelete = $documentValue2;
			$documentValue2 = null;
		}

		$record = [
			'documento1' => $documentValue1,
			'documento2' => $documentValue2,
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

		$consulta = "UPDATE piars_alumnos SET $field=null, history=? WHERE alumno_id=?";
		$document = DB::update($consulta, [$arr, $alumno_id]);

		/*
		 * **El valor viejo se anota aunque el fichero se borre del disco doce líneas
		 * más abajo.** `$fileToDelete` es lo único que queda de él en cuanto corre
		 * ese `File::delete()`: sin esta línea, «¿quién quitó el diagnóstico de este
		 * niño?» no tiene respuesta en ninguna parte.
		 */
		Auditoria::registrar()
			->borrar('piar')
			->deAlumno((int) $alumno_id)
			->de(['campo' => $field, 'documento' => $fileToDelete])
			->resumen('Quitó el documento '.$field.' del PIAR — el fichero se borra del disco')
			->guardar();

		$filename 	= 'uploads/'.$fileToDelete;
	
		if (File::exists($filename)) {
			File::delete($filename);
		}else{
			Log::info($filename . ' -- Al parecer NO existe archivo: ' . $filename);
		}
		return ['document' => $document];
	}
}