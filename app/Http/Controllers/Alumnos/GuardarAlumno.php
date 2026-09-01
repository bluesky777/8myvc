<?php namespace App\Http\Controllers\Alumnos;



use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Carbon\Carbon;

use App\User;
use App\Models\Year;
use App\Models\Periodo;
use \Log;
use App\Support\ColumnaSegura;



class GuardarAlumno {


	public function valor($user, $propiedad, $valor, $user_id=false, $year_id=false, $alumno_id=false)
	{

		$consulta 	= '';
		$datos 		= [];
		$now 		= Carbon::now('America/Bogota');
		
		if (!$alumno_id) {
			$alumno_id 	= Request::input('alumno_id');
		}
		

		if ($propiedad == 'fecha_nac' || $propiedad == 'fecha_retiro' || $propiedad == 'prematriculado')
			$valor = Carbon::parse($valor);

		switch ($propiedad) {
			case 'username':
			case 'email':
			case 'is_active':
				
				if (!$user_id) {
					$user_id 	= Request::input('user_id');
				}
				$consulta 	= 'UPDATE users SET '.$propiedad.'=:valor, updated_by=:modificador, updated_at=:fecha WHERE id=:user_id';
				$datos 		= [ ':valor' => $valor, ':modificador' => $user->user_id, ':fecha' => $now, ':user_id' => $user_id ];

			break;
			
			case 'nuevo':
			case 'fecha_pension':
			case 'fecha_retiro':
			case 'fecha_matricula':
			case 'razon_retiro':
			case 'repitente':
			case 'prematriculado':
			case 'programar':
			case 'descripcion_recomendacion':
			case 'efectuar_una':
			case 'promovido':
			case 'descripcion_efectuada':
			case 'nro_folio':
			
				/*
				 * **Cuál es la matrícula del año la decide `Matricula`, y ya no esta
				 * consulta.** Es la §9.5 del plan, y era el fallo que nadie ve porque
				 * nadie mira estos campos al día siguiente: aquí se escribía en `[0]` de
				 * una consulta **sin `ORDER BY`, sin `m.deleted_at` y sin `g.deleted_at`**,
				 * mientras la ficha leía `[0]` de otra que sí filtra y ordena por
				 * `a.apellidos` —un empate total para un solo alumno—. Con dos matrículas
				 * vivas del mismo año, **se lee de una y se escribe en otra**, y las tres
				 * columnas que salen por aquí son `repitente`, `promovido` y `nro_folio`.
				 *
				 * Lo que había además de eso, y no vuelve:
				 *
				 *   - el `// Tengo confusión con INNER o LEFT grupos` del autor, que era
				 *     exactamente esta pregunta sin contestar;
				 *   - y cuatro columnas seleccionadas —`a.id`, `a.user_id`, `g.id`,
				 *     `g.titular_id`— **que no lee nadie**: sólo se usaba `matricula_id`.
				 *
				 * El 400 se conserva tal cual. Es raro que un no-controlador devuelva una
				 * respuesta HTTP, pero cambiarlo aquí es cambiarle el contrato a los cinco
				 * llamadores de `AlumnosController`, y esto no va de eso.
				 */
				$matricula = \App\Models\Matricula::laDelAnio((int) $alumno_id, (int) $year_id);

				if ($matricula === null) {
					return response()->json([ 'No encontrado'=> false, 'msg'=> 'Alumno no encontrado' ], 400);
				}

				$consulta = 'UPDATE matriculas SET '.$propiedad.'=:valor, updated_by=:modificador, updated_at=:fecha WHERE id=:matricula_id';
				$datos 		= [
					':valor'		=> $valor, 
					':modificador'	=> $user->user_id, 
					':fecha' 		=> $now,
					':matricula_id'	=> $matricula->id
				];
			break;
			
			default:
				
				$consulta = 'UPDATE alumnos SET '.ColumnaSegura::exigir('alumnos', $propiedad).'=:valor, updated_by=:modificador, updated_at=:fecha WHERE id=:alumno_id';
				$datos 		= [
					':valor'		=> $valor, 
					':modificador'	=> $user->user_id, 
					':fecha' 		=> $now,
					':alumno_id'	=> $alumno_id
				];
			break;
		}
		
		
		$res = DB::update($consulta, $datos);

		if($res)
			return 'Guardado';
		else
			return 'No guardado';

	}



	public function valorAcudiente($acudiente_id, $parentesco_id, $user_acud_id, $propiedad, $valor, $user_id)
	{

		$consulta 	= '';
		$datos 		= [];
		$now 		= Carbon::now('America/Bogota');

		if ($propiedad == 'fecha_nac')
			$valor = Carbon::parse($valor);

		switch ($propiedad) {
			case 'username':
				$consulta 	= 'UPDATE users SET username=:valor, updated_by=:modificador, updated_at=:fecha WHERE id=:user_id';
				$datos 		= [ ':valor' => $valor, ':modificador' => $user_id, ':fecha' => $now, ':user_id' => $user_acud_id ];
				break;
			
			case 'parentesco':
				$consulta 	= 'UPDATE parentescos SET parentesco=:valor, updated_by=:modificador, updated_at=:fecha WHERE id=:parentesco_id';
				$datos 		= [ ':valor' => $valor, ':modificador' => $user_id, ':fecha' => $now, ':parentesco_id' => $parentesco_id ];
				break;
			
			default:
				$consulta = 'UPDATE acudientes SET '.ColumnaSegura::exigir('acudientes', $propiedad).'=:valor, updated_by=:modificador, updated_at=:fecha WHERE id=:acudiente_id';
				$datos 		= [
					':valor'		=> $valor, 
					':modificador'	=> $user_id, 
					':fecha' 		=> $now,
					':acudiente_id'	=> $acudiente_id
				];
				break;
		}
		
		
		$res = DB::update($consulta, $datos);

		if($res)
			return 'Guardado';
		else
			return 'No guardado';

	}



}