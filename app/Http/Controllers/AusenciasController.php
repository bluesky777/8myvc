<?php namespace App\Http\Controllers;


use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;
use DateTime;

use App\User;
use App\Models\Alumno;
use App\Models\Grupo;
use App\Models\Ausencia;
use App\Services\Auditoria;
use App\Support\Reloj;
use App\Models\Asignatura;
use Carbon\Carbon;
use App\Support\NombreDelAlumno;


/*
 * Las ausencias **no las cierra el interruptor del periodo**, y es una decisión.
 *
 * Hasta el 21 ago 2026 tres de estas rutas —guardar cambios, cambiar el tipo y
 * borrar— llamaban a `User::pueden_editar_notas()` y las dos que anotan no, así
 * que con el periodo cerrado un profesor podía apuntar una falta pero no
 * corregirla. Se le preguntó a Joseth cuál de las dos mitades estaba mal y
 * contestó la contraria de la que se esperaba: **«que poner asistencias no se
 * bloquee al bloquear periodos»**, y las tres que faltaban se liberaron también
 * —excusar una falta cuando el alumno trae la excusa es el mismo trabajo de
 * asistencia, no calificar—.
 *
 * `profes_pueden_editar_notas` es lo que dice su nombre: notas. Ver
 * docs/migracion/05-codigo-muerto-y-roto.md §40.
 */
class AusenciasController extends Controller {

	public function getIndex()
	{
		//
	}

	public function getDetailed($asignatura_id)
	{
		$user = User::fromToken();

		$asignatura = (object)Asignatura::detallada($asignatura_id, $user->year_id);
		
		$alumnos = Grupo::alumnos($asignatura->grupo_id);
		
		foreach ($alumnos as $alumno) {

			$userData = Alumno::userData($alumno->alumno_id);
			$alumno->userData = $userData;

			$consulta = 'SELECT * FROM ausencias a WHERE a.asignatura_id = ? and a.periodo_id = ? and a.alumno_id=? and a.deleted_at is null';

			$ausencias = DB::select($consulta, array($asignatura_id, $user->periodo_id, $alumno->alumno_id));

			foreach ($ausencias as $ausencia) {
				$ausencia->mes = date('n', strtotime($ausencia->fecha_hora)) - 1;
				$ausencia->dia = (int)(date('j', strtotime($ausencia->fecha_hora))) ;
			}
			
			$alumno->ausencias = $ausencias;
		}

		// No cambiar el orden!
		$resultado = [];
		array_push($resultado, $asignatura);
		array_push($resultado, $alumnos);

		return $resultado;
	}

	/**
	 * La línea de auditoría de una falta, que es idéntica en las seis rutas.
	 *
	 * Se saca a un ayudante y no se copia seis veces por el motivo que este
	 * módulo ya conoce: `crearFaltaModal` repite el mismo botón «Eliminar» tres
	 * veces y **solo uno mira el rol**, que es exactamente lo que pasa cuando la
	 * misma decisión se escribe en varios sitios. Seis copias de esto acabarían
	 * siendo seis criterios, que es de lo que viene la fase 3 entera.
	 *
	 * El nombre del alumno **se congela dentro de la línea**, y cuesta una consulta
	 * por petición: cada una de estas seis rutas escribe **una** falta, así que no
	 * hay bucle que multiplicarlo. (Los dos caminos que sí escriben en bucle —el
	 * lector de tardanzas y `notas/lote`— resuelven el lote entero de una vez con
	 * `NombreDelAlumno::deVarios()`.)
	 *
	 * No es adorno: sin el nombre, la frase de serie dice «Fulano borró ausencia
	 * 4821» —un verbo, una entidad y un id—, y **una línea cuya descripción no se
	 * puede leer no cuenta como cableada**. Es lo que le pasa hoy a `bitacoras`,
	 * medido contra el cuerpo crudo: manda `descripcion: null` en las 22 filas.
	 */
	private function anotar(string $accion, Ausencia $aus): void
	{
		$alumnoDeLaLinea = $aus->alumno_id === null ? null : (int) $aus->alumno_id;

		$linea = Auditoria::registrar()
			->deAlumno($alumnoDeLaLinea, NombreDelAlumno::de($alumnoDeLaLinea))
			->en(asignatura: $aus->asignatura_id === null ? null : (int) $aus->asignatura_id,
				periodo: $aus->periodo_id === null ? null : (int) $aus->periodo_id);

		$valor = [
			'tipo' => $aus->tipo,
			'fecha_hora' => (string) $aus->fecha_hora,
			'cantidad_ausencia' => $aus->cantidad_ausencia,
			'cantidad_tardanza' => $aus->cantidad_tardanza,
		];

		match ($accion) {
			Auditoria::CREAR => $linea->crear('ausencia', (int) $aus->id)->a($valor),
			Auditoria::BORRAR => $linea->borrar('ausencia', (int) $aus->id)->de($valor),
			default => $linea->editar('ausencia', (int) $aus->id)->a($valor),
		};

		$linea->guardar();
	}


	public function postStore()
	{
		$user = User::fromToken();
		
		$aus = new Ausencia;
		$aus->alumno_id 		= Request::input('alumno_id');
		$aus->asignatura_id 	= Request::input('asignatura_id', null);
		$aus->periodo_id		= $user->periodo_id;
		$aus->cantidad_ausencia	= Request::input('cantidad_ausencia', null);
		$aus->cantidad_tardanza	= Request::input('cantidad_tardanza', null);
		// Sin fecha, la falta es de hoy. Una `fecha_hora` en null deja una falta
		// que cuenta en los totales y no está en ningún día: no sale al filtrar por
		// fecha ni se puede saber después a cuál era. El cliente que no manda el
		// campo está anotando la de ahora, que es lo que ya hacen sus dos vecinas
		// —`agregar-ausencia` y `agregar-tardanza`, donde `Carbon::parse(null)` es
		// ahora—. Se deja pasar el valor recibido tal cual para no cambiarle el
		// formato a quien sí lo manda.
		//
		// `Reloj::ahora()` y no `Carbon::now()`: esto acaba en una columna, y la
		// aplicación guarda en Bogotá aunque `config/app.php` siga en UTC (18,
		// decisión 1). Lo cazó `RelojUnicoTest` — con `Carbon::now()` la falta
		// anotada después de las 19:00 se habría escrito con la fecha de mañana.
		$aus->fecha_hora		= Request::input('fecha_hora') ?: Reloj::ahora();
		$aus->entrada			= Request::input('entrada', 0);
		$aus->created_by		= $user->user_id;
		
		if (Request::input('tipo')) {
			$aus->tipo = Request::input('tipo');
		}
		if ($aus->cantidad_ausencia) {
			$aus->tipo = 'ausencia';
		}
		if ($aus->cantidad_tardanza) {
			$aus->tipo = 'tardanza';
		}

		$aus->save();
		// Hasta hoy anotar una falta no dejaba rastro de ningún tipo: ni en
		// `bitacoras` ni en ninguna otra parte. Una falta que sale en el boletín
		// y en el observador, y nadie sabía quién la había puesto.
		$this->anotar(Auditoria::CREAR, $aus);

		return $aus;
	}



	public function postAgregarAusencia()
	{
		$user = User::fromToken();
		
		$aus = new Ausencia;
		$aus->alumno_id 		= Request::input('alumno_id');
		$aus->asignatura_id 	= Request::input('asignatura_id', null);
		$aus->periodo_id		= $user->periodo_id;
		$aus->cantidad_ausencia	= 1;
		$aus->fecha_hora		= Carbon::parse(Request::input('now'));
		$aus->entrada			= Request::input('entrada', 0);
		$aus->created_by		= $user->user_id;
		$aus->tipo 				= 'ausencia';

		$aus->save();

		$this->anotar(Auditoria::CREAR, $aus);

		return $aus;
	}


	public function postAgregarTardanza()
	{
		$user = User::fromToken();
		
		$aus = new Ausencia;
		$aus->alumno_id 		= Request::input('alumno_id');
		$aus->asignatura_id 	= Request::input('asignatura_id', null);
		$aus->periodo_id		= $user->periodo_id;
		$aus->cantidad_tardanza	= 1;
		$aus->fecha_hora		= Carbon::parse(Request::input('now'));
		$aus->entrada			= Request::input('entrada', 0);
		$aus->created_by		= $user->user_id;
		$aus->tipo 				= 'tardanza';

		$aus->save();

		$this->anotar(Auditoria::CREAR, $aus);

		return $aus;
	}

	/*
	 * Corregir el día de una falta lo puede hacer **cualquiera del personal**, y
	 * es una decisión tomada, no un olvido.
	 *
	 * Aquí había una comprobación de permisos calculada y tirada a la basura:
	 *
	 *     $isCoorDisciplinario = Role::isCoorDisciplinario($user->user_id);
	 *     if (!$isCoorDisciplinario) {
	 *     }
	 *
	 * El cuerpo del `if` vacío, en éste método y en `deleteDestroy`. `myvc_front`
	 * ya lo había visto en la fase 11 y lo dejó apuntado por ser del backend.
	 * Leído en frío parece un descuido con arreglo obvio —rellenar el `if`— y es
	 * justo lo que no se puede hacer: **el rol no gobierna esto en ningún
	 * cliente**. El menú de AngularJS enseña «Asistencias» a `profesor`;
	 * `crearFaltaModal` repite el mismo botón «Eliminar» tres veces y solo uno
	 * mira el rol; y `myvc_flutter` —una sola app para los dieciséis colegios—
	 * borra desde la pantalla de asistencia del profesor sin mirar ninguno.
	 * Rellenar el `if` dejaría a los 51 profesores sin poder corregir una falta
	 * mal puesta, en dieciséis colegios y de golpe, por una app que no se puede
	 * publicar el mismo día.
	 *
	 * Joseth lo decidió el 22 ago 2026: **se queda abierto**, en la misma línea
	 * que el interruptor del periodo de la cabecera —corregir una falta es
	 * trabajo de asistencia—. Lo que se cerró en su lugar fue el rastro: ver
	 * `deleteDestroy`. Lo fija `AusenciasTest`, que además cuenta qué habría que
	 * publicar antes si algún día se cierra.
	 *
	 * `Role::isCoorDisciplinario()` se queda sin llamantes con esto, y es el
	 * cuarto rol de la familia que no gobierna nada — tras Psicólogo y Enfermero
	 * (05 §30.2), que fallaban al revés: cerraban de más.
	 */
	public function putGuardarCambiosAusencia()
	{
		$user = User::fromToken();

		/* Debo convertir string a fecha
		$dato = Request::input('fecha_hora', null);
		if ($dato) {
			$dato = DateTime::createFromFormat('Y-m-d G:H:i', $dato);
			return $dato;
		}
		*/
		$aus = Ausencia::findOrFail(Request::input('ausencia_id'));
		$aus->fecha_hora		= Request::input('fecha_hora', null);
		$aus->updated_by		= $user->user_id;

		$aus->save();

		// Corregir el día de una falta lo puede hacer cualquiera del personal —es
		// una decisión tomada, no un olvido (ver la cabecera de este método)—, y
		// justamente por eso el rastro es lo único que queda.
		$this->anotar(Auditoria::EDITAR, $aus);

		return $aus;
	}

	public function putCambiarTipoAusencia()
	{
		$user = User::fromToken();
		
		$aus = Ausencia::findOrFail(Request::input('ausencia_id'));
		
		if (Request::input('new_tipo') == 'tardanza') {
			$aus->tipo					= 'tardanza';
			$aus->cantidad_tardanza		= $aus->cantidad_ausencia;
		}
		
		if (Request::input('new_tipo') == 'ausencia') {
			$aus->tipo					= 'ausencia';
			$aus->cantidad_ausencia		= $aus->cantidad_tardanza;
		}
		
		$aus->updated_by		= $user->user_id;
		$aus->save();

		$this->anotar(Auditoria::EDITAR, $aus);

		return $aus;
	}

	/*
	 * Borrar una falta **la firma**, y hasta el 22 ago 2026 no la firmaba.
	 *
	 * Las otras dos rutas que borran una ausencia —la del lector y la de la app—
	 * ponen `deleted_by` antes del `delete()`; ésta, que es la de las tres
	 * pantallas web y la de Flutter, no ponía nada. En la copia de producción del
	 * 22 ago hay **5.689 ausencias borradas y 5.684 sin autor**: las cinco que lo
	 * tienen son las que pasaron por el lector.
	 *
	 * Importa justo por lo que se decidió el 22 ago: que corregir y borrar una
	 * falta siga abierto a cualquier profesor. Si no cierra el permiso, lo único
	 * que queda es el rastro — y el rastro estaba en blanco.
	 *
	 * El `save()` va antes del `delete()` y no es cosmético: el borrado suave de
	 * Eloquent escribe solo `deleted_at`, así que un `deleted_by` sin guardar se
	 * pierde. Es lo que hacen las dos hermanas.
	 */
	public function deleteDestroy($id)
	{
		$user = User::fromToken();

		$aus = Ausencia::findOrFail($id);
		$aus->deleted_by = $user->user_id;
		$aus->save();

		// **Antes** del `delete()`, y con `de(...)`: la línea guarda lo que la
		// falta ERA. Después del borrado suave la fila sigue ahí, pero la
		// pregunta que el colegio hace cuando alguien reclama es «qué falta se
		// borró», y eso es el valor viejo.
		//
		// Es la mitad que faltaba de lo que se cerró el 22 ago: `deleted_by` dice
		// quién, y en la copia de producción de ese día había **5.689 ausencias
		// borradas y 5.684 sin autor**. `deleted_by` no dice cuándo ni qué; esta
		// línea sí, y no se puede borrar.
		$this->anotar(Auditoria::BORRAR, $aus);

		$aus->delete();

		return $aus;
	}

}