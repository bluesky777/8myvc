<?php namespace App\Http\Controllers;

use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Services\Auditoria;
use App\Services\DefinitivasDeAsignatura;

use App\User;
use App\Models\Nota;
use App\Models\Profesor;
use App\Models\Unidad;
use App\Models\Subunidad;
use App\Models\Asignatura;
use App\Models\Grupo;
use App\Models\Alumno;
use App\Models\Bitacora;
use App\Models\FraseAsignatura;
use App\Http\Controllers\Informes\PuestosController;
use \Log;
use App\Support\EscalaDeNotas;
use App\Support\PeriodoDeLaFila;
use App\Support\NombreDelAlumno;


class NotasController extends Controller {

	/**
	 * Cuántas notas admite `putLote` de una vez.
	 *
	 * No es una regla del colegio: es el tope que separa un lote de un bucle de
	 * escrituras sin fin dentro de una transacción abierta. Una columna de un
	 * grupo grande son cuarenta y cinco notas, así que doscientas dejan margen de
	 * sobra para que la app agrupe varias columnas si algún día quiere.
	 */
	private const LOTE_MAXIMO = 200;




	public function putDetailed()
	{
		$user = User::fromToken();
		
		$profe_id 			= Request::input('profesor_id');
		$asignatura_id 		= Request::input('asignatura_id');
		$con_asignaturas 	= Request::input('con_asignaturas');

		$resultado = [];
		
		// Unidades con Subunidades
		// **Las quince columnas nombradas, y NO `SELECT *`.** Volver al asterisco
		// reintroduce el fallo, así que esto no es estilo: es la guarda.
		//
		// `notas-detailed-profesor.json` fija **las quince claves de cada unidad**
		// —la instantánea las lleva todas, más `subunidades` que añade el código—,
		// y esta consulta las devuelve tal cual al cliente. Así que **cualquier
		// columna nueva en `unidades` aparece sola en la respuesta**: la de la fase
		// 1 del boletín independiente (`alumno_id`) sería la decimosexta, y movería
		// la planilla del profesor sin que nadie lo hubiera pedido.
		//
		// Y es una forma de romper distinta de las otras tres del plan: **no
		// depende de qué código haya delante, depende de que la consulta diga
		// `*`.** Un `ALTER TABLE` la dispara contra el código viejo Y contra el
		// nuevo. Lo encontró `8myvc-9e` el 24 ago, midiendo su propia migración
		// contra los snapshots.
		//
		// La prueba de que esto está bien hecho es que el snapshot queda verde
		// **sin regenerarlo**: si hubiera que regenerarlo, la respuesta habría
		// cambiado y eso ya no es una guarda, es un cambio de contrato — y ése es
		// de Joseth, porque obliga a avisar al front y a Flutter.
		$unidadesT 			= DB::select(
			'SELECT u.id, u.definicion, u.porcentaje, u.periodo_id, u.asignatura_id,
					u.obligatoria, u.orden, u.por_defecto, u.fecha,
					u.created_by, u.updated_by, u.deleted_by,
					u.deleted_at, u.created_at, u.updated_at
			   FROM unidades u
			  WHERE u.asignatura_id=? and u.deleted_at is null and u.periodo_id=?
			  order by u.orden, u.id',
			[$asignatura_id, $user->periodo_id]
		);
		$unidades 			= [];
		$orden_duplicado 	= false;
		$orden_anterior 	= -5;
		
		$asignatura = (object)Asignatura::detallada($asignatura_id, $user->year_id);
		
		foreach ($unidadesT as $unidad) {
			$subunidades = DB::select('SELECT * FROM subunidades s WHERE s.unidad_id=? and s.deleted_at is null order by s.orden, s.id', [$unidad->id]);

			foreach ($subunidades as $subunidad) {
				Nota::verificarCrearNotas($asignatura->grupo_id, $subunidad, $user->user_id);
			}

			// A veces hay varios con el mismo número en el orden, debo encontrarlo y arreglarlo.
			if ($orden_anterior == $unidad->orden) {
				$orden_duplicado = true;
			}else{
				$orden_anterior = $unidad->orden;
			}

			$unidad->subunidades = $subunidades;
			if (count($subunidades) > 0) {
				array_push($unidades, $unidad);
			}
		}


		$unidadesT = Unidad::arreglarOrden($unidadesT, $asignatura_id, $user->periodo_id);
		

		// alumnos con sus notas
		$alumnos = Grupo::alumnos($asignatura->grupo_id);

		foreach ($alumnos as $alumno) {

			$userData = Alumno::userData($alumno->alumno_id);
			$alumno->userData = $userData;
			$frases = FraseAsignatura::deAlumno($asignatura->asignatura_id, $alumno->alumno_id, $user->periodo_id);
			$alumno->frases = $frases;

			// Ausencias
			$cons_aus = "SELECT  a.id, a.asignatura_id, a.alumno_id, a.periodo_id, a.cantidad_ausencia, a.cantidad_tardanza, a.entrada, a.fecha_hora, a.uploaded, a.created_by, a.tipo FROM ausencias a
						inner join periodos p on p.id=a.periodo_id and p.id=:per_id
						WHERE a.tipo='ausencia' and a.asignatura_id=:asignatura_id and a.alumno_id=:alumno_id and a.deleted_at is null;";
			$ausencias = DB::select($cons_aus, [":per_id" => $user->periodo_id, ':asignatura_id' => $asignatura->asignatura_id, ':alumno_id' => $alumno->alumno_id ]);
			$alumno->ausencias 			= $ausencias;
			$alumno->ausencias_count 	= count($ausencias);

			// Tardanzas
			$cons_tar = "SELECT  a.id, a.asignatura_id, a.alumno_id, a.periodo_id, a.cantidad_ausencia, a.cantidad_tardanza, a.entrada, a.fecha_hora, a.uploaded, a.created_by, a.tipo FROM ausencias a
						inner join periodos p on p.id=a.periodo_id and p.id=:per_id
						WHERE a.tipo='tardanza' and a.asignatura_id=:asignatura_id and a.alumno_id=:alumno_id and a.deleted_at is null;";
			$tardanzas = DB::select($cons_tar, [":per_id" => $user->periodo_id, ':asignatura_id' => $asignatura->asignatura_id, ':alumno_id' => $alumno->alumno_id ]);
			
			// Notas
			$cons = "SELECT n.id, n.nota, n.subunidad_id, n.alumno_id, n.created_by, n.updated_by, n.deleted_by, n.deleted_at, n.created_at, n.updated_at, u.asignatura_id,
							s.porcentaje/100 as subunidad_porc, u.porcentaje/100 as unidad_porc, s.definicion, s.porcentaje as subunidad_porcentaje, u.orden as orden_unidad, s.orden as orden_subunidad
						FROM notas n
						INNER JOIN alumnos a ON a.id=n.alumno_id and n.deleted_at is null
						INNER JOIN subunidades s ON s.id=n.subunidad_id and s.deleted_at is null
						INNER JOIN unidades u ON u.id=s.unidad_id and u.deleted_at is null and u.periodo_id=:per_id
						INNER JOIN asignaturas asi ON asi.id=u.asignatura_id and asi.deleted_at is null and asi.grupo_id=:grupo_id
						WHERE n.alumno_id=:alumno_id and asi.id=:asignatura_id and u.alumno_id <=> :alcance
						order by u.orden, s.orden;";

			// **BI-2.** Un solo periodo (`:per_id` es `$user->periodo_id`) y un solo
			// alumno, así que aquí SÍ vale el valor bindeado: no hay varios periodos
			// que puedan tener alcances distintos. Las de `NotasPerdidasController`
			// abarcan `p.numero <= N` y por eso allí va correlacionado.
			$notas = DB::select($cons, [":per_id" => $user->periodo_id, ':grupo_id' => $asignatura->grupo_id, ':alumno_id' => $alumno->alumno_id, ':asignatura_id' => $asignatura->asignatura_id,
				':alcance' => \App\Services\BoletinIndependiente::alcance((int) $alumno->alumno_id, (int) $user->periodo_id) ]);
			
			
			// Traemos las Definitivas
			$cons_nf  = 'SELECT a.id as alumno_id, a.no_matricula, nf1.periodo, u.username as updated_by_username,
							nf1.nota as nota_final, nf1.id as nf_id, nf1.recuperada, nf1.manual, nf1.updated_by, nf1.created_at, nf1.updated_at,
							cast(r1.DefMateria as decimal(4,1)) as def_materia_auto, r1.updated_at as updated_at_def, IF(nf1.updated_at > r1.updated_at, FALSE, TRUE) AS nfinal_desactualizada 
						FROM alumnos a 
						left join notas_finales nf1 on nf1.alumno_id=a.id and nf1.asignatura_id=:asign_id1 and nf1.periodo=:periodo
						left join users u on u.id=nf1.updated_by 
						left join (
							SELECT df1.alumno_id, df1.periodo_id, MAX(df1.updated_at) as updated_at, df1.numero_periodo, sum( df1.ValorUnidad ) DefMateria 
							FROM(
								SELECT n.alumno_id, u.periodo_id, u.id as unidad_id, p1.numero as numero_periodo, MAX(n.updated_at) as updated_at, 
									sum( ((u.porcentaje/100)*((s.porcentaje/100)*n.nota)) ) ValorUnidad
								FROM asignaturas asi 
								inner join unidades u on u.asignatura_id=asi.id and u.deleted_at is null
								inner join subunidades s on s.unidad_id=u.id and s.deleted_at is null
								inner join notas n on n.subunidad_id=s.id and n.deleted_at is null
								inner join periodos p1 on p1.numero='.$user->numero_periodo.' and p1.id=u.periodo_id and p1.deleted_at is null
								where asi.deleted_at is null and asi.id=:asign_id2
								group by n.alumno_id, s.unidad_id, s.id
							)df1
							group by df1.alumno_id, df1.periodo_id
						)r1 ON r1.alumno_id=a.id
						where a.deleted_at is null and a.id=:alumno_id';
				
			$nota_final = DB::select($cons_nf, [':asign_id1'=>$asignatura->asignatura_id, ':periodo'=>$user->numero_periodo, ':asign_id2'=>$asignatura->asignatura_id, ':alumno_id'=>$alumno->alumno_id])[0];
			
			// Fase 3 de 10-definitivas.md. **Aquí había un DELETE+INSERT por alumno
			// en CADA carga de esta pantalla**, sin preguntar si hacía falta: es el
			// tercer escritor de la §0 y una de las ventanas por las que se perdían
			// definitivas —entre el DELETE y el INSERT la fila no existe, y una
			// petición que muriera en medio la dejaba borrada—.
			//
			// Ahora se pregunta primero y **casi siempre no hace falta**: el sello de
			// versión mira las notas vivas y borradas, las unidades, las subunidades y
			// las matrículas del grupo, así que sólo recalcula cuando algo de eso se
			// movió después de la última definitiva.
			//
			// Y el `INSERT` que había aquí era **uno de los cinco sin guarda** que
			// impedían poner la clave única de la fase 2: al desaparecer, esa fase se
			// acerca un sitio más.
			if (DefinitivasDeAsignatura::estaDesactualizada(
				(int) $asignatura_id, (int) $user->periodo_id, (int) $alumno->alumno_id
			)) {
				DefinitivasDeAsignatura::recalcular(
					(int) $asignatura_id,
					(int) $user->periodo_id,
					(int) $user->user_id,
					(int) $alumno->alumno_id
				);

				$nota_final = DB::select($cons_nf, [':asign_id1'=>$asignatura->asignatura_id, ':periodo'=>$user->numero_periodo, ':asign_id2'=>$asignatura->asignatura_id, ':alumno_id'=>$alumno->alumno_id])[0];
			}
			
			

			$alumno->nota_final 		= $nota_final;
			$alumno->notas 				= $notas;
			$alumno->tardanzas 			= $tardanzas;
			$alumno->tardanzas_count 	= count($tardanzas);

		}
		
		
		// Traermos las asignaturas si las pidieron
		if ($con_asignaturas) {
			$asignaturas 				= Profesor::asignaturas($user->year_id, $profe_id);
			$resultado['asignaturas'] 	= $asignaturas;
		}

		
		$resultado['asignatura'] 	= $asignatura;
		$resultado['alumnos'] 		= $alumnos;
		$resultado['unidades'] 		= $unidades;
		

		return $resultado;
	}

	
	
	
	public function getAlumno($alumno_id='', $grupo_id='')
	{
		$user = User::fromToken();


		if ($user->alumnos_can_see_notas==false) {
			$usuario = User::find($user->user_id);
			if ($usuario->tipo == 'Alumno' || $usuario->tipo=='Acudiente') {
				return 'Sistema bloqueado. No puedes ver las notas';				
			}
		}

		if ($alumno_id=='') {
			if ($user->tipo == 'Alumno') {
				$alumno_id = $user->persona_id;
			}else{
				return abort(400, 'No hay id de alumno');
			}
		}

		$profesor_id = '';

		if ($user->tipo == 'Profesor') {
			$profesor_id = $user->persona_id;
		}
		

		$datos = Nota::alumnoPeriodosDetailed($alumno_id, $user->year_id, $profesor_id);

		
		// Definitivas hasta el tercer periodo para calcular nota faltante
		$puestosCtrl 	= new PuestosController();
		$consulta 		= $puestosCtrl->consulta_notas_finales_alumno3;
		$notas_asig     = DB::select($consulta, [ ':gr_id' => $grupo_id, ':alu_id' => $alumno_id, ':year_id' => $user->year_id, ':min' => $user->nota_minima_aceptada, ':alu_id2' => $alumno_id, ':year_id2' => $user->year_id ]);
		
		foreach ($notas_asig as $keyAsig => $asignatura) {
			$asignatura->nota_final_year = round($asignatura->nota_final_year);
		}
		$datos->notas_tercer_per = $notas_asig;
		// !! Definitivas
		
		
		if ($user->tipo == 'Acudiente') {
			if ($datos->pazysalvo){
				return [$datos];
			}else{
				return ['msg'=>'No está a pazysalvo'];
			}
		}
		
		return [$datos];
	}



	
	
	public function putAlumnoPeriodoGrupo()
	{
		$user = User::fromToken();


		if(($user->is_superuser) || $user->tipo == 'Profesor'){
			// Todo bien
		}else{
			return abort(403, 'No tienes permiso.');
		}

		$alumno_id 	= Request::input('alumno_id');
		$periodo_id = Request::input('periodo_id');
		$grupo_id 	= Request::input('grupo_id');

		$profesor_id = '';

		if ($user->tipo == 'Profesor') {
			$profesor_id = $user->persona_id;
		}
		
		$periodo 	= DB::select('SELECT * FROM periodos WHERE id=? and deleted_at is null', [ $periodo_id ])[0]; 


		Nota::alumnoPeriodoDetalle($periodo, $grupo_id, $alumno_id, $periodo->year_id, $profesor_id);


		return ['notas' => $periodo];
	}



	
	
	public function getShow($nota_id)
	{
		$user 	= User::fromToken();
		$nota 	= Nota::find($nota_id);
		return $nota;
	}

	
	


	
	public function putUpdate($id)
	{
		$user 	= User::fromToken();
		$now 	= Carbon::now('America/Bogota');
		
		// La nota no lleva periodo: cuelga de la subunidad y esa de la unidad,
		// que sí. Es una de las dos que la §27.1 daba por difíciles.
		$periodoDeLaNota = PeriodoDeLaFila::deNota($id);

		User::pueden_editar_notas($user, $periodoDeLaNota);

		// Que el número quepa en la escala del colegio. No lo comprobaba nadie:
		// los diez sitios que miran `porc_final` son para pintar la banda, no
		// para rechazar, así que el único guardián era el navegador — y de sus
		// tres pantallas hermanas una no guarda. En esta base hay 92 notas
		// fuera de rango por eso. Ver 18 §4.5.1.
		EscalaDeNotas::comprobar(Request::input('nota'), $periodoDeLaNota);

		try {

			$consulta 	= 'SELECT n.*, h.id as history_id FROM notas n, 
								(select * from historiales where user_id=? and deleted_at is null order by id desc limit 1 ) h 
							WHERE n.id=? and n.deleted_at is null ';

			$nota 		= DB::select($consulta, [$user->user_id, $id])[0];

			$bit_by 	= $user->user_id;
			$bit_hist 	= $nota->history_id;
			$bit_old 	= $nota->nota; 				// Guardo la nota antigua
			$bit_new 	= Request::input('nota'); 	// Guardo la nota nueva
			$bit_per 	= $user->periodo_id;

			$nota->nota 		= $bit_new;
			$nota->updated_at 	= $now;
			$nota->updated_by 	= $user->user_id;

			$consulta 	= 'UPDATE notas SET nota=?, updated_by=?, updated_at=? WHERE id=?';
			DB::update($consulta, [$bit_new, $user->user_id, $now, $id]);

			$consulta 	= 'INSERT INTO bitacoras (created_by, historial_id, affected_user_id, affected_person_type, affected_element_type, affected_element_id, affected_element_new_value_int, affected_element_old_value_int, created_at) 
						VALUES (?, ?, ?, "Al", "Nota", ?, ?, ?, ?)';

			DB::insert($consulta, [$bit_by, $bit_hist, $nota->alumno_id, $id, $bit_new, $bit_old, $now]);

			// El rastro nuevo, al lado del viejo (18 §4), y **dentro del mismo
			// `try` que el UPDATE**, que es donde dice que vaya la regla: después
			// de la escritura y después de la guarda (`pueden_editar_notas` está
			// arriba). Auditar antes de la guarda dejaría registrada una escritura
			// que nunca ocurrió.
			//
			// El periodo sale de `$periodoDeLaNota` —el de la fila— y no de
			// `$user->periodo_id`, que es el del profesor y puede no ser el mismo:
			// es la distinción que `$bit_per` tenía calculada arriba y nunca usó.
			$alumnoDeLaLinea = $nota->alumno_id === null ? null : (int) $nota->alumno_id;

			Auditoria::registrar()
				->editar('nota', (int) $id)
				->deAlumno($alumnoDeLaLinea, NombreDelAlumno::de($alumnoDeLaLinea))
				->en(periodo: $periodoDeLaNota)
				->de($bit_old)
				->a($bit_new)
				->guardar();
			
		} catch (\Exception $e) {
			abort(422, 'No se pudo guardar la nota');
		}

		// Fase 3 de docs/migracion/10-definitivas.md: **la definitiva se actualiza
		// al modificar la nota**, que era la petición de origen. Aquí había un
		// `if (Request::has('asignatura_id')) { # code... }` vacío — el hueco
		// donde esto iba a ir y nunca fue.
		//
		// **No se pide `asignatura_id` al cliente**: la nota lleva a su unidad y la
		// unidad sabe de qué asignatura y periodo es. Depender del cuerpo era una
		// de las formas de que el recálculo no ocurriera, porque el front no
		// siempre lo manda.
		//
		// Va **después** del `try`, no dentro: si la nota no se guardó, no hay nada
		// que recalcular, y meterlo dentro convertiría un fallo del recálculo en un
		// «no se pudo guardar la nota» que sería mentira — la nota sí se guardó.
		$recalculo = DefinitivasDeAsignatura::recalcularPorNota((int) $id, $user->user_id);

		// Y la definitiva resultante viaja **en esta misma respuesta**, como campo
		// nuevo. Sin ella la planilla necesita una petición más por cada nota
		// tecleada sólo para leer un entero que aquí ya está calculado: en una
		// rejilla de treinta alumnos por asignatura eso es el doble de viajes.
		//
		// **Es un campo añadido, no un cambio**: la nota se sigue devolviendo con
		// las mismas claves, así que los cuatro clientes que ya la leen no se
		// enteran. Y el front tiene que seguir tolerando que no venga, porque
		// `app/` es copia por colegio y durante el despliegue habrá colegios con
		// el código viejo — ahí este campo no existe.
		//
		// Puede venir `null` con la petición en 200: es cuando no hay fila en
		// `notas_finales` para ese alumno, que hoy son 11.988 sólo en la copia de
		// desarrollo. Rellenarlas es la fase 2.
		$nota->definitiva = $recalculo['definitiva'] ?? null;

		return (array)$nota;
	}

	/**
	 * Guardar varias notas de una vez.
	 *
	 * ## Lo que ahorra, y lo que NO ahorra
	 *
	 * **No ahorra el recálculo**, y va primero porque es el error que este método
	 * estuvo a punto de llevar escrito en la cabecera. Recalcular es barato:
	 * `tools/coste-del-recalculo.php` lo midió en **~1,7 ms** la consulta agregada
	 * y ~4 ms la nota entera. El *3×* que parecía haber al estrechar esa consulta a
	 * un alumno **era la caché** —una pasada en orden fijo, con la segunda variante
	 * cobrando el buffer pool que calentó la primera—; medido con medianas y
	 * alternando el orden queda en 1,26× sobre 1,7 ms, o sea ~0,35 ms por
	 * pulsación. El estrechamiento se escribió, se midió y **se revirtió**. Está en
	 * `docs/migracion/02-plan-rendimiento.md`, con las tres lecciones; no se vuelva
	 * a citar ese ahorro, aquí ni en ningún sitio.
	 *
	 * Lo que sí ahorra son **las treinta peticiones**, y ahí el número es otro: una
	 * columna de treinta alumnos paga treinta veces el coste fijo de **resolver
	 * quién pregunta**, que la §4 del 02 mide en **~40–80 ms**. Un orden de
	 * magnitud por encima del recálculo, y sin depender de ninguna caché.
	 * Recalcular una vez por par en vez de treinta es la consecuencia agradable de
	 * agrupar, no el motivo.
	 *
	 * ## Y lo que de verdad lo justifica no es velocidad, es corrección
	 *
	 * **Treinta peticiones son treinta transacciones independientes.** Una columna
	 * a medio guardar —se cayó la red, expiró el token, el profesor cerró la
	 * pantalla— deja notas escritas y definitivas calculadas sobre estados
	 * intermedios, que es la familia de fallos que la
	 * [fase 3](../../../docs/migracion/10-definitivas.md) vino a cerrar. Un lote es
	 * **una transacción y un recálculo**: entra entera o no entra.
	 *
	 * En un VPS lo primero no se notaría. Lo pide la app porque el hosting es
	 * compartido; lo segundo se nota en cualquier sitio.
	 *
	 * ## Lo que se hace en qué orden, que es casi todo el método
	 *
	 * 1. **Se resuelve el destino de cada nota antes de escribir nada**, y de paso
	 *    se apartan las que no llevan a ninguna unidad viva. Una nota inventada en
	 *    el cuerpo no puede tumbar el lote entero: el docente perdería las
	 *    veintinueve que sí eran buenas.
	 * 2. **El permiso se comprueba una vez, con la lista de periodos, y antes de
	 *    la primera escritura.** `pueden_editar_notas` acepta un array y lo cruza
	 *    con AND: si una sola nota cae en un periodo cerrado, no se escribe
	 *    ninguna. Es lo mismo que hace el reordenado de subunidades, y es lo
	 *    correcto — escribir media columna es peor que no escribir nada.
	 * 3. **Las escrituras van en una transacción**, que es lo que `putUpdate` no
	 *    tiene y aquí importa más: treinta filas a medio guardar dejan una columna
	 *    que no se corresponde con ninguna pulsación.
	 * 4. **El recálculo va fuera de la transacción y al final.** Fuera porque
	 *    `recalcular()` abre la suya y no tiene sentido alargar el bloqueo de las
	 *    notas mientras se agregan las definitivas; al final porque recalcular
	 *    entre nota y nota es justo lo que este endpoint viene a no hacer.
	 *
	 * ## Los ids de periodo van **únicos**, y no es cosmético
	 *
	 * `aplicarBanderasDelPeriodo` decide con `count($filas) === count($ids)`, para
	 * que un periodo borrado debajo de una fila cuente como cerrado en vez de
	 * regalar permiso. Pasarle la lista sin deduplicar convierte treinta notas del
	 * mismo periodo en treinta ids contra **una** fila, y la comprobación deniega
	 * **el lote entero** con un 400 que no significa nada. O sea que el caso
	 * normal de este endpoint —una columna, un periodo— sería justo el que nunca
	 * pasa.
	 *
	 * ## El historial se resuelve una vez, y puede faltar
	 *
	 * `putUpdate` lo saca con un cross join dentro del mismo SELECT de la nota, así
	 * que un usuario **sin ninguna sesión registrada** no trae fila, el `[0]`
	 * revienta y la respuesta es un 422 «no se pudo guardar la nota» sobre una nota
	 * que se podía guardar perfectamente. Aquí se pide aparte y una sola vez, y si
	 * no hay va `null`: `bitacoras.historial_id` lo admite, y que falte el rastro
	 * de la sesión no puede impedir que se guarde la nota **ni** que se anote la
	 * bitácora, que es lo que el colegio mira cuando alguien reclama.
	 *
	 * La bitácora es por lo demás **idéntica** a la de `putUpdate` —mismas
	 * columnas, mismos valores, una por nota—: es el rastro que lee el historial de
	 * la app, y un lote no puede dejar un rastro distinto del que deja teclear una
	 * a una.
	 *
	 * ## Despliegue
	 *
	 * `app/` es copia por colegio y `myvc_flutter` es **una sola app para los
	 * dieciséis**, así que la app no puede llamar aquí hasta que esto esté
	 * desplegado en todos: en el que faltara gastaría un 404 antes de caer al
	 * método viejo. Ver docs/DESPLIEGUE.md.
	 */
	public function putLote()
	{
		$user = User::fromToken();
		$now  = Carbon::now('America/Bogota');

		$pedidas = Request::input('notas');

		if (! is_array($pedidas) || $pedidas === []) {
			abort(422, 'Hace falta una lista de notas.');
		}

		// El tope no es una regla de negocio, es lo que separa un lote de una
		// denegación de servicio: sin él, un cuerpo con cien mil ids es un bucle de
		// cien mil consultas dentro de una transacción. Doscientas cubren de sobra
		// la pantalla que lo usa —una columna de un grupo grande son cuarenta y
		// cinco— y el cliente **tendrá que** partir en tandas por encima de eso.
		//
		// **Decía «la app ya sabe partir en tandas» y no lo sabe.** Medido el 24 ago
		// 2026 en los cuatro árboles —`myvc_front`, `myvc_front_2`, la fase 11 y
		// `myvc_flutter`—: **cero llamadas a `notas/lote`**. Ninguno lo llama
		// todavía; la app tiene su interruptor apagado esperando despliegue y el
		// front está escribiendo su agrupador ahora.
		//
		// **Y pasar del tope no avisa: aborta el lote entero con 422.** Por eso la
		// frase importaba más de lo que parece: el número estaba justificado dando
		// por hecha una capacidad del cliente que no existe, así que quien lo suba o
		// lo baje mañana creyendo que el cliente se adapta **rompe al cliente**, y
		// en silencio hasta que un grupo pase del tope.
		//
		// El tope se queda en 200. Lo que cambia es que la cabecera diga lo que hay.
		// Lo trajo `myvc-front-98` leyendo este controlador antes de diseñar contra
		// él — no releyendo la descripción, que es lo que no lo habría encontrado.
		if (count($pedidas) > self::LOTE_MAXIMO) {
			abort(422, 'El lote no puede pasar de '.self::LOTE_MAXIMO.' notas.');
		}

		$fallidas  = [];
		$aEscribir = [];
		$periodos  = [];
		$pares     = [];

		foreach ($pedidas as $posicion => $pedida) {
			$id    = is_array($pedida) ? ($pedida['id'] ?? null) : null;
			$valor = is_array($pedida) && array_key_exists('nota', $pedida) ? $pedida['nota'] : null;

			if (! is_numeric($id)) {
				$fallidas[] = ['id' => null, 'motivo' => 'La posición '.$posicion.' no trae un id de nota.'];

				continue;
			}

			$id = (int) $id;

			if (! is_numeric($valor)) {
				$fallidas[] = ['id' => $id, 'motivo' => 'La nota no es un número.'];

				continue;
			}

			// El mismo camino que usa el recalculador: la nota no sabe de qué
			// asignatura ni de qué periodo es —cuelga de la subunidad y ésa de la
			// unidad—, y aquí hacen falta las dos para agrupar el recálculo.
			$destino = DB::selectOne(
				'SELECT n.id, n.nota, n.alumno_id, u.asignatura_id, u.periodo_id
				   FROM notas n
				   INNER JOIN subunidades s ON s.id = n.subunidad_id AND s.deleted_at IS NULL
				   INNER JOIN unidades u ON u.id = s.unidad_id AND u.deleted_at IS NULL
				  WHERE n.id = ? AND n.deleted_at IS NULL',
				[$id]
			);

			if ($destino === null) {
				$fallidas[] = ['id' => $id, 'motivo' => 'No existe la nota, o su indicador ya no está.'];

				continue;
			}

			$aEscribir[] = ['id' => $id, 'valor' => $valor, 'destino' => $destino];

			$periodos[(int) $destino->periodo_id] = true;
			$pares[(int) $destino->asignatura_id.':'.(int) $destino->periodo_id] = [
				(int) $destino->asignatura_id,
				(int) $destino->periodo_id,
			];
		}

		// **Con las tres claves, aunque no haya nada que devolver dentro.** Una
		// respuesta a la que le falta `definitivas` obliga al front a distinguir
		// «vacío» de «no vino», y las dos cosas se pintan distinto: es la misma
		// razón por la que el alumno sin fila viaja con `nota: null` en vez de
		// omitirse.
		if ($aEscribir === []) {
			return ['guardadas' => 0, 'fallidas' => $fallidas, 'definitivas' => []];
		}

		// Antes de la primera escritura, y con los ids **únicos**: ver la nota de
		// arriba sobre `count($filas) === count($ids)`.
		User::pueden_editar_notas($user, array_keys($periodos));

		// La escala, y **después del permiso, no antes**. Ponerla en el bucle de
		// arriba parecía natural —está al lado de las otras dos validaciones de
		// forma— y era un fallo de verdad: con un periodo cerrado, las notas caían
		// en `fallidas` y la respuesta salía **200 con la lista** en vez del 400
		// del guard. O sea que un dato fuera de escala tapaba una respuesta de
		// autorización. Lo cazó `test_con_el_periodo_cerrado_el_lote_no_escribe_nada`,
		// que ya llevaba escrito «el permiso se está comprobando tarde».
		//
		// La regla, que vale para el resto de la fase 4: **la forma se valida
		// antes del permiso sólo cuando no depende de datos; lo que mira la base
		// va después.** Ver 18 §4.5.1.
		$conEscala = [];

		foreach ($aEscribir as $fila) {
			$noCabe = EscalaDeNotas::motivoSiNoCabe($fila['valor'], (int) $fila['destino']->periodo_id);

			if ($noCabe !== null) {
				$fallidas[] = ['id' => $fila['id'], 'motivo' => $noCabe];

				continue;
			}

			$conEscala[] = $fila;
		}

		$aEscribir = $conEscala;

		// Y otra vez el corte, porque la escala puede haberse llevado el lote
		// entero: sin esto se abriría una transacción para escribir cero notas y
		// se recalcularía una definitiva que nadie ha tocado.
		if ($aEscribir === []) {
			return ['guardadas' => 0, 'fallidas' => $fallidas, 'definitivas' => []];
		}

		$historial = DB::selectOne(
			'SELECT id FROM historiales WHERE user_id = ? AND deleted_at IS NULL ORDER BY id DESC LIMIT 1',
			[$user->user_id]
		);

		// Los nombres de las notas del lote, **en una consulta y fuera de la
		// transacción**: dentro del bucle `de()` ya no consulta. Fuera y no dentro
		// porque es una lectura que no necesita estar en la transacción, y meterla
		// alargaría lo que la transacción tiene abierto sin ninguna ganancia.
		NombreDelAlumno::deVarios(array_map(fn ($f) => $f['destino']->alumno_id, $aEscribir));

		$guardadas = DB::transaction(function () use ($aEscribir, $user, $now, $historial) {
			$hechas = 0;

			foreach ($aEscribir as $fila) {
				DB::update(
					'UPDATE notas SET nota=?, updated_by=?, updated_at=? WHERE id=?',
					[$fila['valor'], $user->user_id, $now, $fila['id']]
				);

				DB::insert(
					'INSERT INTO bitacoras (created_by, historial_id, affected_user_id, affected_person_type,
						affected_element_type, affected_element_id, affected_element_new_value_int,
						affected_element_old_value_int, created_at)
					 VALUES (?, ?, ?, "Al", "Nota", ?, ?, ?, ?)',
					[
						$user->user_id,
						$historial->id ?? null,
						$fila['destino']->alumno_id,
						$fila['id'],
						$fila['valor'],
						$fila['destino']->nota,
						$now,
					]
				);

				// El rastro nuevo, al lado del viejo (18 §4), y **dentro de la
				// transacción del lote**: si el lote se deshace, las líneas se
				// deshacen con él. Es la propiedad que `Auditoria` tiene por no
				// abrir transacción propia, y la que hoy le falta a `putUpdate`.
				//
				// Una línea por nota y no una por lote: el lote es un detalle del
				// transporte —el front manda una petición por rejilla—, y la
				// pregunta que la tabla contesta es «quién tocó ESTA nota».
				$alumnoDeLaLinea = $fila['destino']->alumno_id === null ? null : (int) $fila['destino']->alumno_id;

				Auditoria::registrar()
					->editar('nota', (int) $fila['id'])
					->deAlumno($alumnoDeLaLinea, NombreDelAlumno::de($alumnoDeLaLinea))
					->en(periodo: (int) $fila['destino']->periodo_id)
					->de($fila['destino']->nota)
					->a($fila['valor'])
					->guardar();

				$hechas++;
			}

			return $hechas;
		});

		// Y **una sola vez por par**, con la transacción de las notas ya cerrada.
		// Sin `soloAlumno` a propósito: el lote toca a varios alumnos del mismo
		// grupo y `calcular()` los agrega a todos en la misma consulta, así que
		// acotar por alumno sería pedir esa misma agregación una vez por cada uno.
		foreach ($pares as $par) {
			DefinitivasDeAsignatura::recalcular($par[0], $par[1], $user->user_id);
		}

		return [
			'guardadas' => $guardadas,
			'fallidas' => $fallidas,
			'definitivas' => $this->definitivasDelLote($aEscribir),
		];
	}

	/**
	 * Las definitivas con las que quedaron los alumnos que tocó el lote.
	 *
	 * **Es el mismo contrato que `putUpdate`, en plural.** Aquélla devuelve
	 * `definitiva` porque toca a un alumno; un lote es una columna, así que aquí es
	 * una lista con la misma forma de elemento. Que existan las dos evita que el
	 * front acabe con dos ideas distintas de lo mismo según por dónde guarde, y
	 * ahorra lo que ahorraba allí: **una petición más sólo para repintar celdas
	 * cuyo valor el servidor acaba de calcular**.
	 *
	 * **Se lee de la tabla y no de lo calculado**, por la misma razón que allí: el
	 * servicio respeta las filas `manual` y `recuperada`, así que lo calculado no
	 * es lo que hay guardado. Devolver lo calculado pintaría un número que la base
	 * no tiene, y justo en las filas que alguien puso a mano, que son las que más
	 * se miran.
	 *
	 * Y **una consulta por par, no por alumno**: el `IN` es lo que impide que
	 * devolver esto reintroduzca por la puerta de atrás las treinta consultas que
	 * el endpoint viene a quitar.
	 *
	 * ## El alumno sin fila viene igual, con `nota` en null
	 *
	 * Casi siempre la hay: `recalcular()` parte de las matrículas y crea la fila
	 * de todo el que esté matriculado (§9.1). Pero el lote puede llevar la nota de
	 * alguien que ya no lo está —un retirado, un `PREM`—, y ése no recibe fila.
	 *
	 * **Se manda el elemento con `nota` en null en vez de omitirlo**, y la
	 * diferencia es del lado del front: omitido, no puede distinguir «este alumno
	 * no tiene definitiva» de «este alumno no vino en la respuesta», y las dos
	 * cosas se pintan distinto. Es la misma decisión que tomó `putUpdate`, que
	 * devuelve `definitiva: null` en vez de no devolver la clave.
	 *
	 * @param  array<int, array{id:int, valor:mixed, destino:object}>  $aEscribir
	 * @return array<int, array<string, mixed>>
	 */
	private function definitivasDelLote(array $aEscribir): array
	{
		$porPar = [];

		foreach ($aEscribir as $fila) {
			$clave = (int)$fila['destino']->asignatura_id.':'.(int)$fila['destino']->periodo_id;
			$porPar[$clave]['asignatura'] = (int)$fila['destino']->asignatura_id;
			$porPar[$clave]['periodo'] = (int)$fila['destino']->periodo_id;
			$porPar[$clave]['alumnos'][(int)$fila['destino']->alumno_id] = true;
		}

		$definitivas = [];

		foreach ($porPar as $par) {
			$alumnos = array_keys($par['alumnos']);

			$filas = DB::select(
				'SELECT alumno_id, nota, manual, recuperada FROM notas_finales
				  WHERE asignatura_id = ? AND periodo_id = ?
				    AND alumno_id IN ('.implode(',', array_fill(0, count($alumnos), '?')).')
				  ORDER BY alumno_id, id',
				array_merge([$par['asignatura'], $par['periodo']], $alumnos)
			);

			// Sin la clave única de la fase 2 todavía puede haber duplicados. Se
			// conserva **el primero por id**, que es la misma fila que elige el
			// servicio con su `ORDER BY id LIMIT 1`: si aquí saliera la otra, la
			// pantalla pintaría un número distinto del que se acaba de escribir.
			$guardadas = [];

			foreach ($filas as $fila) {
				if (! isset($guardadas[(int)$fila->alumno_id])) {
					$guardadas[(int)$fila->alumno_id] = $fila;
				}
			}

			// Se recorren **los alumnos pedidos** y no las filas encontradas, que es
			// lo que hace que el que no tiene definitiva salga igual.
			foreach ($alumnos as $alumnoId) {
				$fila = $guardadas[$alumnoId] ?? null;

				$definitivas[] = [
					'alumno_id' => $alumnoId,
					'asignatura_id' => $par['asignatura'],
					'periodo_id' => $par['periodo'],
					'nota' => $fila === null ? null : (int)$fila->nota,
					'manual' => $fila !== null && (bool)$fila->manual,
					'recuperada' => $fila !== null && (bool)$fila->recuperada,
				];
			}
		}

		return $definitivas;
	}





	/**
	 * Borrar una nota también recalcula: quitar una nota cambia la definitiva
	 * tanto como cambiarla, y hasta hoy no la tocaba nadie.
	 *
	 * El orden importa y por eso se lee el destino **antes** del `DELETE`: éste es
	 * un borrado **físico**, así que después de ejecutarlo ya no hay forma de saber
	 * de qué asignatura y periodo era la nota. Es la misma razón por la que el
	 * sello de versión mira los `deleted_at` de las notas blandas y aquí no sirve
	 * de nada: no queda fila que sellar.
	 */
	public function deleteDestroy($id)
	{
		$user 	= User::fromToken();
		User::pueden_editar_notas($user, PeriodoDeLaFila::deNota($id));

		$donde = DB::selectOne(
			'SELECT u.asignatura_id, u.periodo_id, n.alumno_id
			   FROM notas n
			   INNER JOIN subunidades s ON s.id = n.subunidad_id
			   INNER JOIN unidades u ON u.id = s.unidad_id
			  WHERE n.id = ?',
			[$id]
		);

		$consulta 	= 'DELETE FROM notas WHERE id=?';
		DB::delete($consulta, [$id]);

		if ($donde !== null) {
			DefinitivasDeAsignatura::recalcular(
				(int) $donde->asignatura_id,
				(int) $donde->periodo_id,
				$user->user_id,
				(int) $donde->alumno_id
			);
		}

		return 'Eliminada';
	}
	
	
	
	// Para notas individuales en horario hoy
	public function putSubunidad()
	{
		$user 			= User::fromToken();
		$grupo_id 		= Request::input('grupo_id');
		$subunidad 		= Request::input('subunidad');
		$asignatura_id 	= Request::input('asignatura_id');
		$sub_id 		= $subunidad ? $subunidad["id"] : null;
		$nota_default 	= $subunidad ? $subunidad["nota_default"] : null;
		$now 			= Carbon::now('America/Bogota');


		$consulta = 'SELECT m.id as matricula_id, m.alumno_id, a.nombres, a.apellidos, a.sexo, a.user_id, a.fecha_nac, 
				m.grupo_id, m.estado, 
				u.imagen_id, IFNULL(i.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as imagen_nombre, 
				a.foto_id, IFNULL(i2.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as foto_nombre,
				m.fecha_retiro as fecha_retiro 
			FROM alumnos a 
			inner join matriculas m on a.id=m.alumno_id and m.grupo_id=? and (m.estado="MATR" or m.estado="ASIS" or m.estado="PREM") and m.deleted_at is null 
			left join users u on a.user_id=u.id and u.deleted_at is null
			left join images i on i.id=u.imagen_id and i.deleted_at is null
			left join images i2 on i2.id=a.foto_id and i2.deleted_at is null
			where a.deleted_at is null and m.deleted_at is null
			order by a.apellidos, a.nombres';
		
		$alumnos = DB::select($consulta, [$grupo_id]);
		
		
		foreach ($alumnos as $alumno) {
			
			if ($sub_id) {
				// §3.1 de 10-definitivas.md — **esto no guardaba nada, y de paso era
				// una inyección.** La cadena estaba entre comillas **dobles** pero
				// escrita con la sintaxis de concatenación de las simples:
				//
				//     "... (SELECT '.$sub_id.' as subunidad_id, ...)"
				//
				// En comillas dobles PHP **sí** interpola `$sub_id`, así que lo que
				// llegaba a MySQL era `'.123.'` — una cadena, no un número—, que en
				// una columna `int` vale **0** y la clave foránea a `subunidades`
				// rechaza. Por eso «no guarda nada»: el `WHERE NOT EXISTS` sí iba
				// parametrizado, así que cuando la nota ya existía no se intentaba
				// insertar y no se notaba; cuando no existía, reventaba.
				//
				// Y la otra mitad: **los cinco valores venían del cuerpo y entraban
				// interpolados**. Que la comilla de más los rompiera es lo único que
				// impedía que fuera explotable. Se liga todo.
				$consulta = 'INSERT INTO notas(subunidad_id, alumno_id, nota, created_by, created_at, updated_at)
						SELECT * FROM
						(SELECT ? as subunidad_id, ? as alumno_id, ? as nota, ? as created_by, ? as created_at, ? as updated_at) AS tmp
							WHERE NOT EXISTS (
								SELECT * from notas WHERE subunidad_id=? and alumno_id=? and deleted_at is null
							) LIMIT 1';

				DB::insert($consulta, [
					$sub_id, $alumno->alumno_id, $nota_default, $user->user_id, $now, $now,
					$sub_id, $alumno->alumno_id,
				]);
				
				// Notas
				$cons = "SELECT n.id, n.nota, n.subunidad_id, n.alumno_id, n.created_by, n.updated_by, n.deleted_by, n.deleted_at, n.created_at, n.updated_at
					FROM notas n
					WHERE n.alumno_id=:alumno_id and n.subunidad_id=:subunidad_id;";
			
				$nota = DB::select($cons, [':alumno_id' => $alumno->alumno_id, ':subunidad_id' => $sub_id ]);
				$alumno->nota 				= $nota[0];
			}
			
			
			
			$frases = FraseAsignatura::deAlumno($asignatura_id, $alumno->alumno_id, $user->periodo_id);
			$alumno->frases = $frases;

			// Ausencias
			$cons_aus = "SELECT  a.id, a.asignatura_id, a.alumno_id, a.periodo_id, a.cantidad_ausencia, a.cantidad_tardanza, a.entrada, a.fecha_hora, a.uploaded, a.created_by, a.tipo FROM ausencias a
					inner join periodos p on p.id=a.periodo_id and p.id=:per_id
					WHERE a.tipo='ausencia' and a.asignatura_id=:asignatura_id and a.alumno_id=:alumno_id and a.deleted_at is null;";
			$ausencias = DB::select($cons_aus, [":per_id" => $user->periodo_id, ':asignatura_id' => $asignatura_id, ':alumno_id' => $alumno->alumno_id ]);
			$alumno->ausencias 			= $ausencias;
			$alumno->ausencias_count 	= count($ausencias);

			// Tardanzas
			$cons_tar = "SELECT  a.id, a.asignatura_id, a.alumno_id, a.periodo_id, a.cantidad_ausencia, a.cantidad_tardanza, a.entrada, a.fecha_hora, a.uploaded, a.created_by, a.tipo FROM ausencias a
					inner join periodos p on p.id=a.periodo_id and p.id=:per_id
					WHERE a.tipo='tardanza' and a.asignatura_id=:asignatura_id and a.alumno_id=:alumno_id and a.deleted_at is null;";
			$tardanzas = DB::select($cons_tar, [":per_id" => $user->periodo_id, ':asignatura_id' => $asignatura_id, ':alumno_id' => $alumno->alumno_id ]);
			
			$alumno->tardanzas 			= $tardanzas;
			$alumno->tardanzas_count 	= count($tardanzas);


			// Uniformes
			$cons_uni = "SELECT u.id, u.asignatura_id, u.materia, u.alumno_id, u.periodo_id, u.contrario, u.sin_uniforme, u.incompleto, u.cabello, u.accesorios, u.otro1, u.camara, u.excusado, u.fecha_hora, u.uploaded, u.created_by, u.descripcion 
					FROM uniformes u
					inner join periodos p on p.id=u.periodo_id and p.id=:per_id
					WHERE u.asignatura_id=:asignatura_id and u.alumno_id=:alumno_id and u.deleted_at is null;";
			$uniformes = DB::select($cons_uni, [":per_id" => $user->periodo_id, ':asignatura_id' => $asignatura_id, ':alumno_id' => $alumno->alumno_id ]);
			
			$alumno->uniformes 			= $uniformes;
			$alumno->uniformes_count 	= count($uniformes);

		}

		// Fase 3 de 10-definitivas.md. **Una vez al final y no por alumno**: el
		// bucle de arriba crea la nota por defecto de esa subunidad para todos los
		// del grupo, así que lo que cambia es la asignatura entera y recalcularla
		// por alumno serían N consultas agregadas para el mismo resultado.
		//
		// El periodo sale de la **subunidad**, no de `$user->periodo_id`: la
		// subunidad cuelga de la unidad y la unidad sí lo lleva. Es la misma
		// distinción que hizo falta en el boletín —usar el periodo del que mira en
		// vez del de la fila es la §1.1—, y aquí es gratis porque `PeriodoDeLaFila`
		// ya sabe hacer ese camino.
		if ($sub_id) {
			$periodoDeLaSubunidad = PeriodoDeLaFila::deSubunidad($sub_id);

			if ($periodoDeLaSubunidad !== null && $asignatura_id) {
				DefinitivasDeAsignatura::recalcular(
					(int) $asignatura_id,
					$periodoDeLaSubunidad,
					$user->user_id
				);
			}
		}

		return [ 'alumnos'=> $alumnos ];
	}

}