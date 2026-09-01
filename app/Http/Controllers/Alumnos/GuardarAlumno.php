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
use App\Support\FilaQueSeVaAEscribir;



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

				FilaQueSeVaAEscribir::exigir('users', 'id', $user_id, 'Esa cuenta de usuario');

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

				// **404 y ya no el 400 que puso la §9.5.** Esta rama era la única que
				// distinguía «no existe» de «no cambió nada», y lo hacía con un código
				// distinto del que ahora usan las otras dos. Una misma ruta contestando
				// dos códigos para la misma condición es peor que cualquiera de los dos:
				// el cliente tendría que aprenderse cuál toca según la propiedad que
				// mande. Es contrato, y está anotado como tal.
				if ($matricula === null) {
					abort(404, 'Ese alumno no tiene matrícula en este año.');
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

				FilaQueSeVaAEscribir::exigir('alumnos', 'id', $alumno_id, 'Ese alumno');

				$consulta = 'UPDATE alumnos SET '.ColumnaSegura::exigir('alumnos', $propiedad).'=:valor, updated_by=:modificador, updated_at=:fecha WHERE id=:alumno_id';
				$datos 		= [
					':valor'		=> $valor, 
					':modificador'	=> $user->user_id, 
					':fecha' 		=> $now,
					':alumno_id'	=> $alumno_id
				];
			break;
		}
		
		
		/*
		 * **Ya no se mira lo que devuelve `DB::update`, y ésa es la opción A entera.**
		 * Devuelve filas AFECTADAS, y MySQL da 0 cuando el UPDATE no cambia ningún valor
		 * — no cuando no encuentra la fila. Con eso, `'No guardado'` juntaba «el valor ya
		 * era ése» con «esa fila no existe», y **guardar dos veces lo mismo contestaba
		 * «No guardado» con 200 y el estado correcto**.
		 *
		 * Ahora la fila se comprueba arriba, rama por rama: si no está, la petición ya ha
		 * cortado con 404. Llegar hasta aquí significa que la fila existe, así que el
		 * único resultado posible es «guardado» — cambiara algo o no. Un fallo real de la
		 * base no pasa por esta línea: lanza excepción y sale 500, igual que antes.
		 *
		 * `'No guardado'` desaparece de los dos métodos de este fichero. 09 §13, opción A,
		 * decidida por Joseth el 1 sep 2026.
		 */
		DB::update($consulta, $datos);

		return 'Guardado';

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
				FilaQueSeVaAEscribir::exigir('users', 'id', $user_acud_id, 'Esa cuenta de usuario');
				$consulta 	= 'UPDATE users SET username=:valor, updated_by=:modificador, updated_at=:fecha WHERE id=:user_id';
				$datos 		= [ ':valor' => $valor, ':modificador' => $user_id, ':fecha' => $now, ':user_id' => $user_acud_id ];
				break;
			
			case 'parentesco':
				FilaQueSeVaAEscribir::exigir('parentescos', 'id', $parentesco_id, 'Ese parentesco');
				$consulta 	= 'UPDATE parentescos SET parentesco=:valor, updated_by=:modificador, updated_at=:fecha WHERE id=:parentesco_id';
				$datos 		= [ ':valor' => $valor, ':modificador' => $user_id, ':fecha' => $now, ':parentesco_id' => $parentesco_id ];
				break;
			
			default:
				FilaQueSeVaAEscribir::exigir('acudientes', 'id', $acudiente_id, 'Ese acudiente');
				$consulta = 'UPDATE acudientes SET '.ColumnaSegura::exigir('acudientes', $propiedad).'=:valor, updated_by=:modificador, updated_at=:fecha WHERE id=:acudiente_id';
				$datos 		= [
					':valor'		=> $valor, 
					':modificador'	=> $user_id, 
					':fecha' 		=> $now,
					':acudiente_id'	=> $acudiente_id
				];
				break;
		}
		
		
		/*
		 * **Ya no se mira lo que devuelve `DB::update`, y ésa es la opción A entera.**
		 * Devuelve filas AFECTADAS, y MySQL da 0 cuando el UPDATE no cambia ningún valor
		 * — no cuando no encuentra la fila. Con eso, `'No guardado'` juntaba «el valor ya
		 * era ése» con «esa fila no existe», y **guardar dos veces lo mismo contestaba
		 * «No guardado» con 200 y el estado correcto**.
		 *
		 * Ahora la fila se comprueba arriba, rama por rama: si no está, la petición ya ha
		 * cortado con 404. Llegar hasta aquí significa que la fila existe, así que el
		 * único resultado posible es «guardado» — cambiara algo o no. Un fallo real de la
		 * base no pasa por esta línea: lanza excepción y sale 500, igual que antes.
		 *
		 * `'No guardado'` desaparece de los dos métodos de este fichero. 09 §13, opción A,
		 * decidida por Joseth el 1 sep 2026.
		 */
		DB::update($consulta, $datos);

		return 'Guardado';

	}



}