<?php namespace App\Http\Controllers\Matriculas;

use App\Services\Auditoria;
use App\Http\Controllers\Controller;

use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\DB;

use Carbon\Carbon;

use \Log;
use App\Http\Controllers\Concerns\ResuelveElUsuario;
use App\Support\ColumnaSegura;
use App\Models\Role;


/**
 * Los antecedentes y los sucesos de enfermería.
 *
 * **Sus cuatro rechazos respondían 401, y un 401 aquí no es un código mal
 * elegido: es una orden al frontend.** `Sesion.ts` intercepta todo 401 que no
 * venga de una ruta de sesión, pide una renovación de tokens y reenvía la
 * petición; si la renovación falla —el refresco ya rotado en otra pestaña, por
 * ejemplo— llama a `sesion.expirar('token')`, que borra los tokens, avisa con
 * «La sesión ha expirado» y manda al login.
 *
 * O sea que a quien no tiene el permiso no se le decía «no puedes»: se le
 * **rotaba la sesión en cada intento**, y en la carrera que el propio front
 * documenta se le echaba de la plataforma. Eso se reporta como «me saca», que
 * manda a mirar el código de sesión —donde no está el fallo—, y no como «no
 * tengo permiso».
 *
 * Pasan a 403, que es lo que hace el resto de la API y lo que este front ya
 * sabe pintar: el `.catch` de cada llamada enseña el mensaje del cuerpo.
 * Ningún cliente leía el 401 de aquí para otra cosa. Ver 05 §54.
 */
class EnfermeriaController extends Controller {
	use ResuelveElUsuario;

	public function putDatos()
	{
		$now 				= Carbon::now('America/Bogota');
		
        $consulta          = 'SELECT * FROM antecedentes WHERE alumno_id=?';
        $antecedentes      = DB::select($consulta, [Request::input('alumno_id')]);
		
		if (count($antecedentes) == 0) {
			$consulta          = 'INSERT INTO antecedentes(alumno_id, updated_by, created_at, updated_at) VALUES(?,?,?,?)';
			// La asignación era muerta: la línea de abajo la pisa con el SELECT.
			DB::insert($consulta, [Request::input('alumno_id'), $this->user->user_id, $now, $now ]);

			// La ficha nace vacía al abrir la pantalla: lo que se anota es que existe
			// desde este día y por quién, no un cambio de datos que todavía no hay.
			Auditoria::registrar()
				->crear('antecedente', (int) DB::getPdo()->lastInsertId())
				->deAlumno((int) Request::input('alumno_id'))
				->resumen('Abrió la ficha de antecedentes del alumno')
				->guardar();
			
			$consulta          = 'SELECT * FROM antecedentes WHERE alumno_id=?';
			$antecedentes      = DB::select($consulta, [Request::input('alumno_id')]);
			
		}
		
		
        $consulta = 'SELECT r.*, u.username as created_by_name, u2.username as updated_by_name FROM registros_enfermeria r
			LEFT JOIN users u ON u.id=r.created_by and u.deleted_at is null
			LEFT JOIN users u2 ON u2.id=r.updated_by and u2.deleted_at is null
			WHERE alumno_id=?';
			
        $registros_enfermeria 		= DB::select($consulta, [Request::input('alumno_id')]);
		
        
        return [ 'antecedentes'=>$antecedentes[0], 'registros_enfermeria'=>$registros_enfermeria ];
	}
	


	public function putGuardarValor()
	{
		// El comentario que había aquí decía «Debo verificar que tenga rol
		// Enfermero. Por ahora lo dejo Usuario para que funcione», y lo escrito
		// debajo comparaba `tipo` con 'Enfermero', un valor que `tipo` no toma
		// nunca —solo son Usuario, Profesor, Alumno y Acudiente, las cuatro ramas
		// del switch de ContextoDeUsuario—. O sea que la rama no se ejecutaba y
		// **la enfermera del colegio no podía escribir los antecedentes médicos**
		// salvo que fuera superusuaria.
		//
		// Es la tercera de la misma familia: el Secretario y el Psicólogo de la
		// §30.2, con la misma forma y el mismo comentario del autor al lado. Se
		// arregla con la decisión que Joseth ya tomó allí: el criterio es el rol,
		// que existe y tiene gente dentro. Ver 05 §41.2.
		if($this->user->is_superuser || Role::isEnfermero($this->user->user_id)){
			$now 				= Carbon::now('America/Bogota');
			$propiedad 			= Request::input('propiedad');
			
			$columna           = ColumnaSegura::exigir('antecedentes', $propiedad);
			$antecId           = (int) Request::input('antec_id');

			// El valor de antes, leído por clave primaria antes de pisarlo. Son datos
			// de salud de un menor: que la ficha diga hoy «ninguna alergia» y no se
			// sepa qué decía ayer ni quién lo cambió es el caso que este rastro existe
			// para contestar.
			$antes             = DB::selectOne("SELECT {$columna} AS valor, alumno_id FROM antecedentes WHERE id = ?", [$antecId]);

			$consulta          = 'UPDATE antecedentes SET '.$columna.'=:valor, updated_by=:modificador, updated_at=:fecha WHERE id=:antec_id';
			$antecedentes      = DB::update($consulta, [':valor'=>Request::input('valor'), ':modificador'=>$this->user->user_id, ':fecha'=>$now, ':antec_id'=>$antecId]);

			Auditoria::registrar()
				->editar('antecedente', $antecId)
				->deAlumno($antes === null ? null : (int) $antes->alumno_id)
				->de($antes->valor ?? null)
				->a(Request::input('valor'))
				->resumen('Cambió '.trim($columna, '`').' en los antecedentes')
				->guardar();
				

			return 'Cambios guardados';
		}else{
			return abort(403, 'No puedes cambiar');
		}
			
	}
	

	public function postCrearSuceso()
	{
		// Debo verificar que tenga rol Enfermero. Por ahora lo dejo Usuario para que funcione
		if($this->user->is_superuser || $this->user->tipo == 'Usuario'){
			$now 				= Carbon::now('America/Bogota');
			$fecha_creacion 	= Carbon::parse(Request::input('fecha_suceso'));
			
			$consulta          = 'INSERT INTO registros_enfermeria
				(alumno_id, fecha_suceso, signo_fc, signo_fr, signo_t, signo_glu, signo_spo2, signo_pa_dia, signo_pa_sis, asignatura, motivo_consulta, descripcion_suceso, created_by, created_at, updated_at) 
				VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)';
			// Asignación muerta: `$antecedentes` no se lee hasta que :135 lo reasigna —y
			// esa línea, doce más abajo, YA usa `DB::update`. La forma correcta estaba al
			// lado en el mismo fichero.
			DB::insert($consulta, [ Request::input('alumno_id'), Request::input('fecha_suceso'), Request::input('signo_fc'), 
				Request::input('signo_fr'), Request::input('signo_t'), Request::input('signo_glu'), Request::input('signo_spo2'), 
				Request::input('signo_pa_dia'), Request::input('signo_pa_sis'), Request::input('asignatura'), Request::input('motivo_consulta'), Request::input('descripcion_suceso'), $this->user->user_id, $now, $now ]);
				
			$last_id 	    = DB::getPdo()->lastInsertId();

			Auditoria::registrar()
				->crear('registro_enfermeria', (int) $last_id)
				->deAlumno((int) Request::input('alumno_id'))
				->a(['fecha_suceso' => Request::input('fecha_suceso'), 'motivo' => Request::input('motivo_consulta')])
				->resumen('Registró un suceso de enfermería')
				->guardar();

			
			$consulta          = 'SELECT * FROM registros_enfermeria WHERE id=?';
			$registro_enfermeria      = DB::select($consulta, [ $last_id]);
				
			return (array)$registro_enfermeria[0];
		}else{
			return abort(403, 'No puedes cambiar');
		}
			
	}
	

	public function putGuardarValorSuceso()
	{
		// Debo verificar que tenga rol Enfermero. Por ahora lo dejo Usuario para que funcione
		if($this->user->is_superuser || $this->user->tipo == 'Usuario'){
			$now 				= Carbon::now('America/Bogota');
			$propiedad 			= Request::input('propiedad');
			
			$columna           = ColumnaSegura::exigir('registros_enfermeria', $propiedad);
			$sucesoId          = (int) Request::input('suceso_id');
			$antes             = DB::selectOne("SELECT {$columna} AS valor, alumno_id FROM registros_enfermeria WHERE id = ?", [$sucesoId]);

			$consulta          = 'UPDATE registros_enfermeria SET '.$columna.'=:valor, updated_by=:modificador, updated_at=:fecha WHERE id=:suceso_id';
			$antecedentes      = DB::update($consulta, [':valor'=>Request::input('valor'), ':modificador'=>$this->user->user_id, ':fecha'=>$now, ':suceso_id'=>$sucesoId]);

			Auditoria::registrar()
				->editar('registro_enfermeria', $sucesoId)
				->deAlumno($antes === null ? null : (int) $antes->alumno_id)
				->de($antes->valor ?? null)
				->a(Request::input('valor'))
				->resumen('Cambió '.trim($columna, '`').' en un suceso de enfermería')
				->guardar();
				

			return 'Cambios guardados';
		}else{
			return abort(403, 'No puedes cambiar');
		}
			
	}
	

	public function deleteDestroy($id)
	{
		// Debo verificar que tenga rol Enfermero. Por ahora lo dejo Usuario para que funcione
		if($this->user->is_superuser || $this->user->tipo == 'Usuario'){
			$now 				= Carbon::now('America/Bogota');
			
			$consulta          = 'DELETE FROM registros_enfermeria WHERE id=?';

			/*
			 * **Se lee antes porque el borrado es físico.** `registros_enfermeria` no
			 * tiene `deleted_at`: en cuanto corre el `DELETE`, del suceso —cuándo fue,
			 * por qué y a quién— no queda nada. Y quien lo borra no necesita ser
			 * enfermero: el `if` de arriba deja pasar a cualquier `Usuario`, que son
			 * 22 cuentas. Lo uno con lo otro es la definición de un rastro necesario.
			 */
			$fila              = DB::selectOne('SELECT * FROM registros_enfermeria WHERE id = ?', [$id]);

			DB::delete($consulta, [ $id ]);

			if ($fila !== null) {
				Auditoria::registrar()
					->borrar('registro_enfermeria', (int) $id)
					->deAlumno((int) $fila->alumno_id)
					->de((array) $fila)
					->resumen('Borró un suceso de enfermería — borrado físico, no hay papelera')
					->guardar();
			}
				
			return 'Eliminado';
		}else{
			return abort(403, 'No puedes eliminar');
		}
			
	}
	





}