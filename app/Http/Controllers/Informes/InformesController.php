<?php namespace App\Http\Controllers\Informes;

use App\Http\Controllers\Controller;

use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\DB;

use App\User;
use App\Models\Year;
use App\Models\Grupo;
use App\Models\Periodo;
use App\Models\Asignatura;
use App\Models\Subunidad;
use App\Models\Profesor;
use App\Services\DefinitivasDeAsignatura;


class InformesController extends Controller {

	public function putDatos()
	{
		$user 	= User::fromToken();
		$res 	= [];

		$year 	= Year::datos($user->year_id, true); // Datos del año actual
		
		$consulta = 'SELECT g.id, g.nombre, g.abrev, g.orden, gra.orden as orden_grado, g.grado_id, g.year_id, g.titular_id,
						p.nombres as nombres_titular, p.apellidos as apellidos_titular, p.titulo,
						g.created_at, g.updated_at, gra.nombre as nombre_grado 
					from grupos g
					inner join grados gra on gra.id=g.grado_id and g.year_id=:year_id 
					left join profesores p on p.id=g.titular_id
					where g.deleted_at is null
					order by g.orden';

		$grupos = DB::select($consulta, [':year_id'=>$user->year_id] );


		$consulta = 'SELECT p.id as profesor_id, p.nombres, p.apellidos, p.sexo, p.foto_id, p.tipo_doc,
						p.num_doc, p.ciudad_doc, p.fecha_nac, p.ciudad_nac, p.titulo,
						p.estado_civil, p.barrio, p.direccion, p.telefono, p.celular,
						p.facebook, p.email, p.tipo_profesor, p.user_id, u.username,
						u.email as email_usu, u.imagen_id, u.is_superuser,
						c.id as contrato_id, c.year_id,
						p.foto_id, IFNULL(i.nombre, IF(p.sexo="F","default_female.png", "default_male.png")) as foto_nombre
					from profesores p
					inner join contratos c on c.profesor_id=p.id and c.year_id=:year_id and c.deleted_at is null
					left join users u on p.user_id=u.id and u.deleted_at is null
					LEFT JOIN images i on i.id=p.foto_id and i.deleted_at is null
					where p.deleted_at is null
					order by p.nombres, p.apellidos';

		$profesores = DB::select($consulta, [':year_id'=>$user->year_id] );

		$consulta = 'SELECT * 
				from images i where i.deleted_at is null and i.publica=true';

		$imagenes = DB::select($consulta, [] );
		
		$periodos_desactualizados = $this->grupos_desactualizados($user);
		if (count($periodos_desactualizados)>0) {
			$res['periodos_desactualizados'] 	= $periodos_desactualizados;
		}
		
		// Los periodos con grupos aunque no estén desactualizados
		$consulta 	= 'SELECT * FROM periodos WHERE deleted_at is null and year_id=?';	
		$periodos 	= DB::select($consulta, [$user->year_id]);
		
		
		// **Estos `$grupos` pisan los de arriba y son los que se devuelven**: sin `ORDER BY`
		// salían por `id` y el desplegable de Informes ponía Jardín detrás de Quinto.
		if ($user->tipo == 'Profesor') {
			$consulta 	= 'SELECT *, id as grupo_id FROM grupos WHERE titular_id=? and deleted_at is null and year_id=? ORDER BY orden, id';
			$grupos 	= DB::select($consulta, [ $user->persona_id, $user->year_id ]);	
		}else{
			$consulta 	= 'SELECT *, id as grupo_id FROM grupos WHERE deleted_at is null and year_id=? ORDER BY orden, id';
			$grupos 	= DB::select($consulta, [ $user->year_id ]);	
		}
		
		
		$cant_pers = count($periodos);
		
		for ($i=0; $i < $cant_pers; $i++) { 
			$periodos[$i]->grupos = $grupos;		
		}
		
		

		$res['year'] 		= $year;
		$res['grupos'] 		= $grupos;
		$res['profesores'] 	= $profesores;
		$res['imagenes'] 	= $imagenes;
		$res['periodos_grupos'] 	= $periodos;
		

		return $res;
	}
	
	
	
	
	/**
	 * Qué grupos tienen definitivas por reparar, periodo a periodo.
	 *
	 * Es lo que el tablero de Informes pinta como «Notas finales desactualizadas» y
	 * lo que decide si salen los botones «Calcular definitivas per N».
	 *
	 * > **Desde el 25 sep 2026 lo mide `diferenciasDelGrupo()`**, que compara el valor
	 * > guardado con el calculado, y no `estadoDelGrupo()`, que compara fechas. En la
	 * > copia de `lal` el sello marcaba 1.068 definitivas y ninguna estaba atrasada por
	 * > una escritura; el porqué, en el docblock de `diferenciasDelGrupo()`. Lo de abajo
	 * > es la historia del sello y se conserva: explica por qué el detector de fechas
	 * > sigue sirviendo para decidir si recalcular, y no para dar la alarma.
	 *
	 * ## Desde el 17 sep 2026 lo mide `estadoDelGrupo()`, y antes NO medía esto
	 *
	 * Hasta esa fecha era una consulta propia:
	 * `MAX(notas.updated_at) > MAX(notas_finales.updated_at)` **por grupo entero** y
	 * unidos por `INNER JOIN`. O sea **un detector distinto del que usa el
	 * recalculador**, y los dos no contestaban la misma pregunta. Tres consecuencias,
	 * las tres estructurales y ninguna visible desde la pantalla:
	 *
	 * - **El `INNER JOIN` dejaba fuera al grupo sin ninguna definitiva** en ese
	 *   periodo. Las filas que faltan —las 11.988 de la fase 0 del
	 *   [10](../../../docs/migracion/10-definitivas.md)— eran **invisibles**, que es
	 *   justo el caso que más duele: sin fila, el puesto cuenta cero, el boletín
	 *   cuenta cero y la planilla borra al alumno de la lista (§6).
	 * - **Era un `MAX` por grupo, no por asignatura**: una definitiva tecleada a mano
	 *   en cualquier asignatura subía el `MAX` del grupo y **tapaba** las automáticas
	 *   atrasadas de todas las demás.
	 * - **Era ciega a los borrados**, como la comprobación vieja de la §4.2.
	 *
	 * Medido el 17 sep 2026 en la copia de desarrollo, año en curso, 13 grupos,
	 * comparando la consulta vieja contra `estadoDelGrupo()`:
	 *
	 * ```
	 * per1  tablero:0  servicio:3  | faltan:198  atrasadas:0
	 * per2  tablero:0  servicio:3  | faltan:205  atrasadas:0
	 * per3  tablero:0  servicio:3  | faltan:205  atrasadas:0
	 * per4  tablero:0  servicio:3  | faltan:205  atrasadas:0
	 * ```
	 *
	 * **La lista salía vacía con 205 definitivas sin existir**, y el bloque del
	 * tablero va dentro de un `ng-show`, así que el falso negativo **no se veía como
	 * un error: se veía como silencio**, que se lee igual que «no hay nada que
	 * hacer». El control positivo es que la misma consulta sí disparaba en otros años
	 * de esa base (2025: 2 grupos, 2024: 9), o sea que el cero era del detector.
	 *
	 * ## Lo que cuesta, y por qué se paga
	 *
	 * Una consulta por grupo y periodo: **13 × 4 = 52**, frente a las 4 de antes.
	 * Medido el 17 sep: `estadoDelGrupo()` son 2,5 ms por grupo, o sea **~130 ms** en
	 * una pantalla de coordinación que se abre pocas veces. Es lo que vale que la
	 * lista deje de mentir en la dirección que no se nota.
	 *
	 * ## Y el detector NO se acota al boletín independiente, a propósito
	 *
	 * Se conserva el criterio que ya tenía la consulta vieja y que comparten
	 * `selloDeVersion()` y `estadoDelGrupo()`: **sobre-aproximar** dice
	 * «desactualizado» de más y alguien recalcula sin necesidad —cuesta tiempo—;
	 * **acotar** dejaría que la nota de un independiente no marcara nada y el colegio
	 * serviría una definitiva vieja **sin un error en el log**. El independiente está
	 * en ese grupo y su definitiva es justo la que nadie va a echar de menos.
	 * Ver la §1.5 del reparto de la noche del 31 ago 2026 y
	 * docs/migracion/noche-2026-08-31/c.md.
	 */
	private function grupos_desactualizados(&$user){
		$periodos 	= DB::select(
			'SELECT * FROM periodos WHERE deleted_at is null and year_id=?',
			[$user->year_id]
		);

		$grupos 	= DB::select(
			'SELECT id, nombre, abrev FROM grupos WHERE deleted_at is null and year_id=? order by orden',
			[$user->year_id]
		);

		$result 	= [];

		foreach ($periodos as $periodo) {
			$desactualizados = [];

			foreach ($grupos as $grupo) {
				$estado 	= DefinitivasDeAsignatura::diferenciasDelGrupo((int) $grupo->id, (int) $periodo->id);

				$faltan 	= array_sum(array_column($estado, 'faltan'));
				$atrasadas 	= array_sum(array_column($estado, 'atrasadas'));

				if ($faltan === 0 && $atrasadas === 0) {
					continue;
				}

				// Las tres primeras claves son las que el front ya lee
				// (`informes.html:13` pinta `abrev` y manda `grupo_id`); las dos
				// últimas son nuevas y dicen **por qué** está marcado, que es lo que
				// la consulta vieja no podía distinguir.
				$desactualizados[] = (object) [
					'grupo_id' 		=> (int) $grupo->id,
					'nombre' 		=> $grupo->nombre,
					'abrev' 		=> $grupo->abrev,
					'faltan' 		=> $faltan,
					'atrasadas' 	=> $atrasadas,
				];
			}

			if (count($desactualizados) > 0) {
				$periodo->grupos = $desactualizados;
				$result[] = $periodo;
			}
		}

		return $result;
	}



	
	
	public function putCumpleanosPorMeses(){
		
		$user 	= User::fromToken();
		
		$meses = [
			['indice' => 1, 'mes' => 'Enero'],
			['indice' => 2, 'mes' => 'Febrero'],
			['indice' => 3, 'mes' => 'Marzo'],
			['indice' => 4, 'mes' => 'Abril'],
			['indice' => 5, 'mes' => 'Mayo'],
			['indice' => 6, 'mes' => 'Junio'],
			['indice' => 7, 'mes' => 'Julio'],
			['indice' => 8, 'mes' => 'Agosto'],
			['indice' => 9, 'mes' => 'Septiembre'],
			['indice' => 10, 'mes' => 'Octubre'],
			['indice' => 11, 'mes' => 'Noviembre'], 
			['indice' => 12, 'mes' => 'Diciembre'], 
		];
		
		for ($i=0; $i < count($meses); $i++) { 
			
			
			$consulta = 'SELECT m.id as matricula_id, m.alumno_id, a.no_matricula, a.nombres, a.apellidos, a.sexo, a.user_id, 
					a.fecha_nac, a.tipo_doc, a.documento, a.tipo_sangre, a.eps, a.telefono, a.celular, 
					a.direccion, a.barrio, a.estrato, a.religion, a.email, a.facebook, a.created_by, a.updated_by,
					a.pazysalvo, a.deuda, m.grupo_id, u.username, u.is_active,
					u.imagen_id, IFNULL(i.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as imagen_nombre, 
					a.foto_id, IFNULL(i2.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as foto_nombre,
					m.estado, m.fecha_matricula, m.nuevo, m.repitente,
					g.nombre as nombre_grupo, g.abrev as abrev_grupo, g.orden
				FROM alumnos a 
				inner join matriculas m on a.id=m.alumno_id and (m.estado="ASIS" or m.estado="MATR") 
				inner join grupos g on g.id=m.grupo_id and g.year_id=?
				left join users u on a.user_id=u.id and u.deleted_at is null
				left join images i on i.id=u.imagen_id and i.deleted_at is null
				left join images i2 on i2.id=a.foto_id and i2.deleted_at is null
				where a.deleted_at is null and m.deleted_at is null AND MONTH(fecha_nac) = ?
				order by g.orden, a.apellidos, a.nombres';
				
			$alumnos = DB::select($consulta, [$user->year_id, $meses[$i]['indice']]);

			$meses[$i]['alumnos'] = $alumnos;
		}
		
		return $meses;
	}





}
