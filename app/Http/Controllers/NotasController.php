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
		// **BI-1: `u.alumno_id IS NULL`, porque esta rejilla es la DEL GRUPO.** Es
		// la forma de acotar de la §1.5 del reparto —«si una consulta quiere a
		// propósito las del grupo, lo correcto es `IS NULL`, que sí es alcance»—, y
		// aquí no hay ambigüedad: la planilla del profesor enseña el reparto del
		// curso, y el del independiente se edita por `PUT boletin-independiente/planilla`.
		//
		// **Y esto no pinta de más: escribe de más.** Estas unidades son las que
		// alimentan a `Nota::verificarCrearNotas` doce líneas más abajo, que siembra
		// **una nota a cada alumno del grupo por cada subunidad que reciba**. Sin la
		// condición, una sola unidad de un independiente le mete a los treinta una
		// fila en `notas` dentro de las subunidades de ese alumno, y de ahí sale la
		// forma «de más» de la §9.2 con la definitiva inflada. No hace falta que
		// nadie mire la pantalla dos veces: la primera carga ya lo dejó escrito.
		$unidadesT 			= DB::select(
			'SELECT u.id, u.definicion, u.porcentaje, u.periodo_id, u.asignatura_id,
					u.obligatoria, u.orden, u.por_defecto, u.fecha,
					u.created_by, u.updated_by, u.deleted_by,
					u.deleted_at, u.created_at, u.updated_at
			   FROM unidades u
			  WHERE u.asignatura_id=? and u.deleted_at is null and u.periodo_id=?
			    and u.alumno_id is null
			  order by u.orden, u.id',
			[$asignatura_id, $user->periodo_id]
		);
		$unidades 			= [];
		$orden_duplicado 	= false;
		$orden_anterior 	= -5;
		
		$asignatura = (object)Asignatura::detallada($asignatura_id, $user->year_id);
		
		foreach ($unidadesT as $unidad) {
			// **Las diecisiete columnas nombradas, y NO `SELECT *`**, por la misma
			// razón que las quince de `unidades` doce líneas más arriba: estas filas
			// se cuelgan de `$unidad->subunidades` y **viajan al cliente**, así que
			// cualquier columna nueva en `subunidades` aparece sola en la planilla del
			// profesor. Pasó el 2 sep 2026 con `subunidades.rubrica_id`, del carril de
			// rúbricas: `NotasTest::la_forma_de_la_rejilla_del_profesor` salió en rojo
			// con `rubrica_id` de más, sin que nadie tocara este método.
			//
			// Es la cuarta vez esta noche que la misma forma muerde —`notas`,
			// `notas_finales`, `subunidades`— y siempre igual: **columna nueva + `*` +
			// campo que sale al cliente sin que nadie lo decida**. La regla está en la
			// §3.4 de docs/migracion/22-nivelaciones.md: una columna viaja porque
			// alguien la nombró.
			$subunidades = DB::select(
				'SELECT s.id, s.definicion, s.porcentaje, s.unidad_id, s.nota_default, s.obligatoria,
						s.orden, s.por_defecto, s.inicia_at, s.finaliza_at, s.actividad_id,
						s.created_by, s.updated_by, s.deleted_by, s.deleted_at, s.created_at, s.updated_at
				   FROM subunidades s
				  WHERE s.unidad_id=? and s.deleted_at is null
				  order by s.orden, s.id',
				[$unidad->id]
			);

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
		//
		// **El periodo va como tercer argumento, y es lo que enciende el badge.** Con él
		// cada alumno sale además con `bol_independiente_datos` (§6.4 del 19): `true` =
		// **tiene un boletín aparte guardado en este periodo aunque el periodo vaya con el
		// grupo**. Sin el argumento el campo no viaja, y ése es el defecto: `Grupo::alumnos`
		// lo llaman veinticinco sitios y emitirlo siempre metería una consulta y un campo
		// en veinte respuestas que no tienen que ver con esto.
		//
		// **Es el periodo del token, no el de la unidad**, porque el badge es del alumno
		// tal como lo está mirando quien tiene la planilla abierta — la misma pantalla y el
		// mismo periodo con los que se decide, cuatro líneas más abajo, quién sale de la
		// lista por independiente.
		//
		// **Los dos campos no dicen lo mismo y por eso conviven**: de `alumnos` ya se han
		// quitado los que van aparte, así que el badge NO señala «va por independiente»
		// —eso lo dice `independientes`— sino «este que sí está en tu planilla tiene datos
		// suyos guardados que no se están usando». Es el estado `aplica = false` con
		// `tiene_datos = true` de la ficha, aplanado.
		$alumnos = Grupo::alumnos($asignatura->grupo_id, '', (int) $user->periodo_id);

		// **FASE 3 del 19: esta planilla deja de enseñar a los independientes.**
		// Es la petición literal del colegio —*«que no aparezca en esa planilla de
		// notas normales»*— y sin ella el docente les pondría notas en la rejilla
		// del curso creyendo que son las suyas.
		//
		// **Y se devuelve `independientes` para que la pantalla lo diga.** Un alumno
		// que desaparece de una lista sin explicación es un alumno que el docente da
		// por perdido y va a buscar a secretaría; con el array, la planilla puede
		// escribir «hay 1 alumno con boletín aparte» y no hay nada que buscar.
		//
		// **Sin `aplica` dentro**: este array lista justo a los que tienen alcance,
		// así que `aplica` valdría `true` por construcción. Un campo constante no es
		// un campo pobre — es uno sobre el que alguien ramificará sin que su rama
		// muerta se note nunca (§6.4).
		//
		// **Las dos listas se parten de una sola pasada sobre `$alumnos`**, y eso es
		// lo que garantiza que sean complementarias. Preguntarle la lista de
		// independientes a otra consulta —`BoletinIndependiente::delGrupo()`— habría
		// metido una segunda población en juego: ésa cuenta `MATR` y `ASIS`, y
		// `Grupo::alumnos()` trae además los `PREM`. Dos fuentes que pueden
		// discrepar acaban discrepando, y aquí discrepar es un alumno que no sale en
		// ninguna de las dos.
		//
		// Los campos van **tal como vienen** de `Grupo::alumnos()`, sin castear: así
		// `independientes[].alumno_id` tiene por construcción el mismo tipo que
		// `alumnos[].alumno_id`, en vez de que lo decida un `(int)` de aquí.
		$independientes = [];
		$del_grupo      = [];

		foreach ($alumnos as $alumno) {
			if (\App\Services\BoletinIndependiente::aplica((int) $alumno->alumno_id, (int) $user->periodo_id)) {
				$independientes[] = [
					'alumno_id' => $alumno->alumno_id,
					'nombres'   => $alumno->nombres,
					'apellidos' => $alumno->apellidos,
				];

				continue;
			}

			$del_grupo[] = $alumno;
		}

		$alumnos = $del_grupo;

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
			// **A7: las seis de la nivelación viajan AQUÍ, y sólo aquí** (22 §3.1).
			// Van nombradas, como el resto: es la misma guarda que impidió que
			// `rubrica_id` se colara por las subunidades. Y van **siempre**, con `null`
			// cuando la nota no está nivelada — una clave que a veces no viene obliga
			// al front a distinguir «vacío» de «no vino», que es la decisión que ya
			// tomó `notas/lote` con `definitivas`.
			//
			// **La celda está nivelada ⇔ `nota_original !== null`.** No hay bandera
			// aparte: sería un segundo sitio donde mentir.
			//
			// `nivelada_por_username` sale del `LEFT JOIN` con `users` para que el pie
			// del diálogo —quién y cuándo— no cueste otra petición; es la misma
			// convención que `updated_by_username` en la definitiva, treinta líneas
			// más abajo. `LEFT` y no `INNER`: sin nivelar no hay usuario, y con `INNER`
			// **desaparecerían las notas sin nivelar**, que son casi todas.
			//
			// Y esto NO rompe a `myvc_flutter`, que está medido y no supuesto:
			// `NotaDelLibro.fromJson` (`lib/Http/LibroNotasApi.dart:331`) lee tres
			// claves por nombre y no mira nada más; no hay deserialización estricta en
			// el proyecto. Ver 22 §3.2bis.
			$cons = "SELECT n.id, n.nota, n.subunidad_id, n.alumno_id, n.created_by, n.updated_by, n.deleted_by, n.deleted_at, n.created_at, n.updated_at, u.asignatura_id,
							n.nota_original, n.nota_nivelacion, n.nivelada_at, n.nivelada_por,
							univ.username as nivelada_por_username, n.nivelacion_obs,
							s.porcentaje/100 as subunidad_porc, u.porcentaje/100 as unidad_porc, s.definicion, s.porcentaje as subunidad_porcentaje, u.orden as orden_unidad, s.orden as orden_subunidad
						FROM notas n
						LEFT JOIN users univ ON univ.id=n.nivelada_por
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
			
			
			// **BI-1.** La definitiva automática que esta pantalla pinta al lado de la
			// guardada. Sin alcance le suma a un marcado **las notas que conserva en
			// las subunidades del grupo** —marcar no las borra, y eso es la petición
			// literal del colegio— **más** las de sus unidades propias: la columna
			// «automática» sale inflada justo al lado de la correcta, o sea acusando
			// de estar mal a la que está bien, y quien pulse «actualizar» guarda el
			// número inflado. Es el mismo fallo que se cerró en
			// `NotaFinal::consultaAlumnosGrupoNotaFinal`, por la otra pantalla.
			//
			// **Correlacionado y no `JOIN_ESTADO`**: dentro de la derivada no hay
			// `matriculas`, y el alumno lo da `n.alumno_id`. Y va al `WHERE` porque
			// `notas n` entra DESPUÉS de `unidades u` — un `ON` no puede nombrar una
			// tabla que todavía no está en el ámbito.
			//
			// Aquí sí hace falta aunque `$alumnos` ya no traiga independientes: la
			// derivada agrupa por `n.alumno_id` **sobre la asignatura entera** y
			// luego se une por `r1.alumno_id=a.id`, así que quien filtra fuera no
			// filtra dentro.
			// Traemos las Definitivas
			// **A7, la otra mitad** (22 §3.2): las cuatro del acta de la definitiva del
			// periodo. `recuperada` **no cambia de significado** —`1` sigue queriendo
			// decir que viene de una nivelación—; lo que se gana es que ahora puede
			// decir **de dónde venía**. Escribirlas es A8; que la forma exista ya
			// desbloquea a B7, que es el editor de la definitiva.
			//
			// `nota_original` sale como `DOUBLE` igual que `nota_final`, y por lo
			// mismo: la columna es `DECIMAL(7,4)` desde `2026_08_30_200000` y PDO la
			// trae como cadena; sin el cast, el front tendría el par en dos tipos
			// distintos y el que compara los dos números se equivoca sin enterarse.
			$cons_nf  = 'SELECT a.id as alumno_id, a.no_matricula, nf1.periodo, u.username as updated_by_username,
							CAST(nf1.nota_original AS DOUBLE) as nota_original, nf1.nivelada_at, nf1.nivelada_por,
							univ.username as nivelada_por_username,
							CAST(nf1.nota AS DOUBLE) as nota_final, nf1.id as nf_id, nf1.recuperada, nf1.manual, nf1.updated_by, nf1.created_at, nf1.updated_at,
							cast(r1.DefMateria as decimal(7,4)) as def_materia_auto, r1.updated_at as updated_at_def, IF(nf1.updated_at > r1.updated_at, FALSE, TRUE) AS nfinal_desactualizada 
						FROM alumnos a 
						left join notas_finales nf1 on nf1.alumno_id=a.id and nf1.asignatura_id=:asign_id1 and nf1.periodo=:periodo
						left join users u on u.id=nf1.updated_by 
						left join users univ on univ.id=nf1.nivelada_por 
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
								  and u.alumno_id <=> '.\App\Services\BoletinIndependiente::alcanceCorrelacionado('n.alumno_id', 'u').'
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
		$resultado['independientes'] = $independientes;
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
		

		// El cuarto argumento es lo que arregla «en notas de alumno no se pueden
		// editar notas»: con el usuario en la mano, `alumnoPeriodosDetailed` CREA
		// las filas de `notas` que falten, con la nota por defecto de la subunidad,
		// igual que lleva haciendo `notas/detailed` desde siempre. Sin fila no hay
		// `id`, y sin `id` el front acababa mandando `notas/update/undefined`.
		//
		// Quién crea y en qué periodo lo decide `Nota::quienCreaLasNotas`, no esto:
		// un alumno mirando su boletín no siembra nada, y con el periodo cerrado
		// sólo el superusuario. Está razonado ahí. Aquí se pasa **el usuario**, no
		// su id, para que la decisión no dependa de que el llamante se acuerde —ver
		// `alumnoPeriodoDetalle`, por donde entra también `alumno-periodo-grupo`—.
		$datos = Nota::alumnoPeriodosDetailed($alumno_id, $user->year_id, $profesor_id, $user);

		
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


		// El séptimo argumento por el mismo motivo que en `getAlumno`, y **es el
		// otro camino de 05 §47.2**: esta ruta la pide «Promocionar notas»
		// (`PromocionarNotasCtrl`, `app2/paginas/promocionar-notas`), que pinta las
		// mismas casillas y las guarda con el mismo `NotasApi.actualizar(nota.id)`.
		// O sea que arreglar sólo `notas/alumno` dejaba el `notas/update/undefined`
		// vivo en la pantalla gemela, que es justo donde más se nota: ahí se copian
		// las notas al periodo de destino, y el destino es el que no tiene filas.
		//
		// Quién crea lo decide `Nota::quienCreaLasNotas` con ESTE periodo —el del
		// cuerpo, que aquí sí es explícito—, no el del contexto del usuario.
		Nota::alumnoPeriodoDetalle($periodo, $grupo_id, $alumno_id, $periodo->year_id, $profesor_id, null, $user);


		return ['notas' => $periodo];
	}



	
	
	public function getShow($nota_id)
	{
		$user 	= User::fromToken();
		// Las diez columnas nombradas y no `find()` a secas, por lo mismo que en
		// `putUpdate`: `find()` trae la fila entera y las cinco de la nivelación
		// habrían movido `notas-show.json` solas. Un `ALTER TABLE` no cambia un
		// contrato; lo nuevo viaja por `notas/detailed` y por `notas/nivelar/*`.
		$nota 	= Nota::select(['id', 'nota', 'subunidad_id', 'alumno_id', 'created_by', 'updated_by',
			'deleted_by', 'deleted_at', 'created_at', 'updated_at'])->find($nota_id);
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

			// **El producto cartesiano con `historiales` se va entero**, y con él dos
			// cosas: la adivinanza del ingreso y un fallo latente.
			//
			// La adivinanza es la fase 2 de 18-auditoria.md — era el último login de
			// esta persona, no la sesión que hace el cambio.
			//
			// El fallo latente es la forma de la consulta: **si el usuario no tenía
			// ninguna fila en `historiales`, el cruce devolvía CERO filas** y el `[0]`
			// de abajo reventaba con «Undefined array key 0». O sea que la escritura
			// fallaba por no encontrar un INGRESO, no por nada de la propia fila.
			//
			// Ahora la consulta pregunta sólo por lo que quiere saber: la fila de
			// `notas`.
			// **Las diez columnas nombradas, y NO `n.*`**, desde el 2 sep 2026: este
			// método DEVUELVE `$nota`, y con el asterisco las cinco columnas de la
			// nivelación (`2026_09_02_100000_nivelaciones_columnas`) habrían viajado
			// solas en la respuesta de `PUT notas/update/{id}`, que leen los cuatro
			// clientes y fija `notas-update.json`. Es la misma guarda que `putDetailed`
			// tiene sobre `unidades`: un `ALTER TABLE` no puede cambiar un contrato.
			//
			// Y es la mitad de la promesa del A6 (22 §7): `notas/update` **no cambia
			// ni una línea de comportamiento**, y un `*` es justo lo que la rompería
			// sin que nadie tocara este método. Lo nuevo viaja por `notas/detailed` y
			// por los endpoints de nivelar, que nacen con ello.
			$consulta 	= 'SELECT n.id, n.nota, n.subunidad_id, n.alumno_id, n.created_by, n.updated_by,
							n.deleted_by, n.deleted_at, n.created_at, n.updated_at
						   FROM notas n WHERE n.id=? and n.deleted_at is null';

			$nota 		= DB::select($consulta, [$id])[0];

			$bit_by 	= $user->user_id;
			$bit_hist 	= isset($user->historial_id) && is_numeric($user->historial_id)
				? (int) $user->historial_id
				: null;
			// **`history_id` se vuelve a colgar de la fila, y no es cosmético: este
			// método DEVUELVE `$nota`**, así que esa columna viajaba en el cuerpo de
			// `PUT notas/update/{id}` — llegaba de rebote, por el `SELECT n.*, h.id`
			// del cruce, pero llegaba. Quitarla habría sido retirarle un campo a
			// cuatro clientes sin decírselo.
			//
			// Lo cazó el snapshot `notas-update`, que es para lo que está. Lo que sí
			// cambia es **el valor**: antes era el último ingreso de esta persona y
			// ahora es el de esta sesión —o null mientras el token sea anterior a la
			// migración—. Eso va en el parte.
			$nota->history_id = $bit_hist;

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

		// El ingreso sale del token (fase 2 de 18-auditoria.md), y con él se va una
		// consulta por lote.
		$historialId = isset($user->historial_id) && is_numeric($user->historial_id)
			? (int) $user->historial_id
			: null;

		// Los nombres de las notas del lote, **en una consulta y fuera de la
		// transacción**: dentro del bucle `de()` ya no consulta. Fuera y no dentro
		// porque es una lectura que no necesita estar en la transacción, y meterla
		// alargaría lo que la transacción tiene abierto sin ninguna ganancia.
		NombreDelAlumno::deVarios(array_map(fn ($f) => $f['destino']->alumno_id, $aEscribir));

		$guardadas = DB::transaction(function () use ($aEscribir, $user, $now, $historialId) {
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
						$historialId,
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
					// `(float)`: la columna es `DECIMAL` desde
					// `2026_08_30_200000_notas_finales_en_decimal` y PDO la trae como
					// cadena. `(int)` truncaba hacia abajo; el `(float)` conserva los
					// decimales **y** deja el valor como número en el JSON.
					'nota' => $fila === null ? null : (float)$fila->nota,
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

		// `LEFT JOIN` y no `INNER`, y no es estilo: con `INNER` una nota cuya
		// unidad ya se borró no traía fila, y de ella no quedaba **nada** — ni
		// recálculo (correcto: no hay par que recalcular) ni rastro (incorrecto:
		// la nota existía y alguien la borró). Con `LEFT` la fila viene siempre que
		// la nota exista, y el par sólo cuando hay unidad viva.
		$donde = DB::selectOne(
			'SELECT n.nota, n.alumno_id, n.subunidad_id, u.asignatura_id, u.periodo_id
			   FROM notas n
			   LEFT JOIN subunidades s ON s.id = n.subunidad_id
			   LEFT JOIN unidades u ON u.id = s.unidad_id
			  WHERE n.id = ?',
			[$id]
		);

		$consulta 	= 'DELETE FROM notas WHERE id=?';
		$borradas 	= DB::delete($consulta, [$id]);

		// Éste era **el único escritor de `notas` sin rastro en ninguna de las dos
		// tablas** —lo dijo `tools/escrituras-sin-auditoria.php` el 2 sep 2026:
		// `putUpdate` y `putLote` ya auditaban, `deleteDestroy` 1:0—, y es el que
		// menos puede permitírselo: el borrado es **físico**, así que después de
		// esta línea no queda fila, ni `deleted_at`, ni bitácora que diga qué
		// nota había. Sin esto, «¿quién borró la nota de este alumno?» no tenía
		// respuesta en los quince colegios. Es el A1 del plan de nivelaciones
		// (22-nivelaciones.md), y también un agujero por sí solo.
		//
		// Sólo si el `DELETE` afectó una fila: un id que no existía no es un
		// borrado, y una línea de auditoría sobre nada es la forma de mentira que
		// más caro sale en una tabla que se lee años después. Por eso `$donde` se
		// lee **antes** del `DELETE` —después ya no hay de dónde— y la línea se
		// escribe **después**, cuando se sabe que ocurrió (18 §4.6).
		//
		// Sin `INSERT` en `bitacoras`: el borrado nunca lo escribió ahí, y la
		// pantalla que lee `bitacoras` busca por el id de una nota que ya no
		// existe. El rastro nuevo es el único que puede contestar la pregunta.
		if ($borradas > 0 && $donde !== null) {
			$alumnoDeLaLinea = $donde->alumno_id === null ? null : (int) $donde->alumno_id;

			Auditoria::registrar()
				->borrar('nota', (int) $id)
				->deAlumno($alumnoDeLaLinea, NombreDelAlumno::de($alumnoDeLaLinea))
				->en(
					asignatura: $donde->asignatura_id === null ? null : (int) $donde->asignatura_id,
					periodo: $donde->periodo_id === null ? null : (int) $donde->periodo_id,
				)
				->de($donde->nota)
				->guardar();
		}

		if ($donde !== null && $donde->asignatura_id !== null) {
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


	// ─────────────────────────────────────────────────────────────────────
	// Nivelaciones — docs/migracion/22-nivelaciones.md
	//
	// **Endpoints NUEVOS, y no una bandera sobre `putUpdate`.** `myvc_flutter` es
	// una sola app para los quince colegios y una versión vieja convive con este
	// backend durante meses: si `notas/update` aprendiera a nivelar, un docente
	// calificando desde el móvil mandaría un 95 por el camino de siempre y, con la
	// regla `topada`, se guardaría 70. Sin error y sin aviso. Es la §6.1 del
	// reparto, y `NivelarUnaNotaTest` fija que esos dos no cambian.
	//
	// La regla vive en `App\Services\Nivelacion` (A4) y aquí sólo se llama.
	// ─────────────────────────────────────────────────────────────────────

	/**
	 * `PUT notas/nivelar/{id}` — registrar (o sustituir, §1.3; o corregir la
	 * original, §1.6) la nivelación de un indicador.
	 *
	 * El orden es el de `putLote`, que es el que ya se pagó: la forma que no mira la
	 * base se valida primero; luego la fila (404); luego el permiso **con el periodo
	 * de la nota** (403); luego lo que mira la base para validar (la escala, 422).
	 * Así un dato fuera de escala no tapa una respuesta de autorización.
	 */
	public function putNivelar($id)
	{
		$user = User::fromToken();
		$now  = Carbon::now('America/Bogota');

		$forma = $this->formaDeUnaNivelacion(Request::all(), exigirNivelacion: false);

		if ($forma['motivo'] !== null) {
			abort(422, $forma['motivo']);
		}

		$fila = $this->filaParaNivelar((int) $id);

		if ($fila === null) {
			abort(404, 'No existe la nota, o su indicador ya no está.');
		}

		if (! User::puedeNivelar($user, (int) $fila->periodo_id)) {
			abort(403, 'No tienes permiso para nivelar en este periodo.');
		}

		// §1.6: `nota_original` sola sólo vale en una nota YA nivelada; en una sin
		// nivelar, la original se corrige por `notas/update`, que es corrección.
		if ($forma['nivelacion'] === null && $forma['original'] === null) {
			abort(422, 'Hace falta nota_nivelacion.');
		}

		if ($forma['original'] !== null && $fila->nota_original === null) {
			abort(422, 'Esta nota no está nivelada: la valoración inicial se corrige con notas/update.');
		}

		foreach ([$forma['nivelacion'], $forma['original']] as $valor) {
			if ($valor !== null) {
				EscalaDeNotas::comprobar($valor, (int) $fila->periodo_id);
			}
		}

		$historialId = $this->historialDelToken($user);

		$resultado = DB::transaction(fn () => $this->nivelarLaFila($fila, $forma, $user, $now, $historialId));

		// Fuera de la transacción y después, como en `putUpdate`: el recálculo abre
		// la suya y un fallo suyo no puede convertirse en «no se pudo nivelar».
		$recalculo = DefinitivasDeAsignatura::recalcularPorNota((int) $id, $user->user_id);

		return $this->notaNivelada((int) $id, $resultado['regla_aplicada'], $recalculo['definitiva'] ?? null);
	}

	/**
	 * `DELETE notas/nivelar/{id}` — quitar la nivelación: `nota` vuelve a
	 * `nota_original` y las cinco del acta a NULL (22 §2). Es la vuelta atrás del
	 * docente que niveló cuando quería corregir (§6.5 del reparto).
	 */
	public function deleteNivelar($id)
	{
		$user = User::fromToken();
		$now  = Carbon::now('America/Bogota');

		$fila = $this->filaParaNivelar((int) $id);

		if ($fila === null) {
			abort(404, 'No existe la nota, o su indicador ya no está.');
		}

		if (! User::puedeNivelar($user, (int) $fila->periodo_id)) {
			abort(403, 'No tienes permiso para nivelar en este periodo.');
		}

		// **409 y no 200 vacío**: un `DELETE` que contesta 200 sobre algo que no
		// existía es una respuesta que miente, y el front pintaría «nivelación
		// retirada» sobre una celda que nunca la tuvo.
		if ($fila->nota_original === null) {
			abort(409, 'Esta nota no tiene ninguna nivelación que quitar.');
		}

		$historialId = $this->historialDelToken($user);

		DB::transaction(function () use ($fila, $user, $now, $historialId) {
			$original = (int) $fila->nota_original;

			DB::update(
				'UPDATE notas SET nota=?, nota_original=NULL, nota_nivelacion=NULL, nivelada_at=NULL,
					nivelada_por=NULL, nivelacion_obs=NULL, updated_by=?, updated_at=? WHERE id=?',
				[$original, $user->user_id, $now, $fila->id]
			);

			$this->bitacoraDeNota($fila, (int) $fila->nota, $original, $user, $now, $historialId);

			$alumno = $fila->alumno_id === null ? null : (int) $fila->alumno_id;

			Auditoria::registrar()
				->quitarNivelacion('nota', (int) $fila->id)
				->deAlumno($alumno, NombreDelAlumno::de($alumno))
				->en(asignatura: (int) $fila->asignatura_id, periodo: (int) $fila->periodo_id)
				->de((int) $fila->nota)
				->a($original)
				->resumen('Quitó la nivelación (nivelación '.$fila->nota_nivelacion.'); vuelve la valoración inicial '.$original.'.')
				->guardar();
		});

		$recalculo = DefinitivasDeAsignatura::recalcularPorNota((int) $id, $user->user_id);

		return $this->notaNivelada((int) $id, null, $recalculo['definitiva'] ?? null);
	}

	/**
	 * `PUT notas/nivelar/lote` — la semana de nivelaciones (22 §4).
	 *
	 * Los tres desenlaces de `putLote`, con los mismos nombres: éxito parcial con
	 * `fallidas[]` y 200; el permiso comprobado **una vez, con los periodos únicos,
	 * antes de la primera escritura**, que tumba el lote entero con 403; y los dos
	 * 422 de forma. Más `niveladas[]`, que `putLote` no necesita porque allí lo que
	 * se escribe es lo que se mandó y aquí lo que queda en `nota` lo decide la
	 * regla.
	 */
	public function putNivelarLote()
	{
		$user = User::fromToken();
		$now  = Carbon::now('America/Bogota');

		$pedidas = Request::input('notas');

		if (! is_array($pedidas) || $pedidas === []) {
			abort(422, 'Hace falta una lista de notas.');
		}

		if (count($pedidas) > self::LOTE_MAXIMO) {
			abort(422, 'El lote no puede pasar de '.self::LOTE_MAXIMO.' notas.');
		}

		$fallidas  = [];
		$aNivelar  = [];
		$periodos  = [];
		$pares     = [];

		foreach ($pedidas as $posicion => $pedida) {
			$id = is_array($pedida) ? ($pedida['id'] ?? null) : null;

			if (! is_numeric($id)) {
				$fallidas[] = ['id' => null, 'motivo' => 'La posición '.$posicion.' no trae un id de nota.'];

				continue;
			}

			$id = (int) $id;

			// Sin `is_array` otra vez: si no lo fuera, `$id` habría salido `null` y la
			// posición ya estaría en `fallidas` con su motivo. Lo señaló larastan.
			$forma = $this->formaDeUnaNivelacion($pedida, exigirNivelacion: true);

			if ($forma['motivo'] !== null) {
				$fallidas[] = ['id' => $id, 'motivo' => $forma['motivo']];

				continue;
			}

			$fila = $this->filaParaNivelar($id);

			if ($fila === null) {
				$fallidas[] = ['id' => $id, 'motivo' => 'No existe la nota, o su indicador ya no está.'];

				continue;
			}

			$aNivelar[] = ['id' => $id, 'forma' => $forma, 'fila' => $fila];

			$periodos[(int) $fila->periodo_id] = true;
			$pares[(int) $fila->asignatura_id.':'.(int) $fila->periodo_id] = [(int) $fila->asignatura_id, (int) $fila->periodo_id];
		}

		if ($aNivelar === []) {
			return ['guardadas' => 0, 'fallidas' => $fallidas, 'niveladas' => [], 'definitivas' => []];
		}

		if (! User::puedeNivelar($user, array_keys($periodos))) {
			abort(403, 'No tienes permiso para nivelar en este periodo.');
		}

		// La escala **después** del permiso, como en `putLote` y por lo mismo.
		$conEscala = [];

		foreach ($aNivelar as $item) {
			$noCabe = EscalaDeNotas::motivoSiNoCabe($item['forma']['nivelacion'], (int) $item['fila']->periodo_id);

			if ($noCabe !== null) {
				$fallidas[] = ['id' => $item['id'], 'motivo' => $noCabe];

				continue;
			}

			$conEscala[] = $item;
		}

		$aNivelar = $conEscala;

		if ($aNivelar === []) {
			return ['guardadas' => 0, 'fallidas' => $fallidas, 'niveladas' => [], 'definitivas' => []];
		}

		// La regla de cada año se resuelve **antes** de abrir la transacción: si un
		// año lleva una regla que no es de las tres, el lote entero se rechaza sin
		// escribir nada, en vez de morir a medias dentro.
		foreach ($aNivelar as $item) {
			$this->reglaValidaDelAnio((int) $item['fila']->year_id);
		}

		$historialId = $this->historialDelToken($user);

		NombreDelAlumno::deVarios(array_map(fn ($i) => $i['fila']->alumno_id, $aNivelar));

		$niveladas = DB::transaction(function () use ($aNivelar, $user, $now, $historialId) {
			$hechas = [];

			foreach ($aNivelar as $item) {
				$resultado = $this->nivelarLaFila($item['fila'], $item['forma'], $user, $now, $historialId);
				$hechas[]  = $this->notaNivelada($item['id'], $resultado['regla_aplicada'], null, conDefinitiva: false);
			}

			return $hechas;
		});

		foreach ($pares as $par) {
			DefinitivasDeAsignatura::recalcular($par[0], $par[1], $user->user_id);
		}

		// `definitivasDelLote` espera la forma de `putLote`; `destino` es la fila.
		$paraDefinitivas = array_map(fn ($i) => ['id' => $i['id'], 'valor' => null, 'destino' => $i['fila']], $aNivelar);

		return [
			'guardadas' => count($niveladas),
			'fallidas' => $fallidas,
			'niveladas' => $niveladas,
			'definitivas' => $this->definitivasDelLote($paraDefinitivas),
		];
	}

	/**
	 * La forma de una nivelación, sin mirar la base: qué trae el cuerpo y si vale.
	 *
	 * Devuelve `motivo` con el texto del 422 o de la `fallida`, y los tres valores
	 * ya limpios. `exigirNivelacion` es `true` en el lote —allí no hay corrección
	 * de la original— y `false` en el `PUT` suelto, donde `nota_original` sola es
	 * la §1.6.
	 *
	 * @param  array<string, mixed>  $cuerpo
	 * @return array{motivo: ?string, nivelacion: ?int, original: ?int, obs: ?string, fecha: ?string}
	 */
	private function formaDeUnaNivelacion(array $cuerpo, bool $exigirNivelacion): array
	{
		$vacio = ['motivo' => null, 'nivelacion' => null, 'original' => null, 'obs' => null, 'fecha' => null];

		$nivelacion = $cuerpo['nota_nivelacion'] ?? null;
		$original   = $cuerpo['nota_original'] ?? null;

		if ($nivelacion === null && ($exigirNivelacion || ! array_key_exists('nota_original', $cuerpo))) {
			return ['motivo' => $exigirNivelacion ? 'La nota no es un número.' : 'Hace falta nota_nivelacion.'] + $vacio;
		}

		if ($nivelacion !== null && ! is_numeric($nivelacion)) {
			return ['motivo' => $exigirNivelacion ? 'La nota no es un número.' : 'Hace falta nota_nivelacion.'] + $vacio;
		}

		if ($original !== null && ! is_numeric($original)) {
			return ['motivo' => 'La valoración inicial no es un número.'] + $vacio;
		}

		$obs = $cuerpo['observacion'] ?? null;

		if ($obs !== null && ! is_string($obs)) {
			return ['motivo' => 'La observación tiene que ser texto.'] + $vacio;
		}

		$obs = $obs === null || trim($obs) === '' ? null : trim($obs);

		if ($obs !== null && mb_strlen($obs) > 255) {
			return ['motivo' => 'La observación no puede pasar de 255 caracteres.'] + $vacio;
		}

		$fecha = $cuerpo['fecha'] ?? null;

		if ($fecha !== null && $fecha !== '') {
			$leida = $this->fechaDelActa($fecha);

			if ($leida === null) {
				return ['motivo' => 'La fecha de la nivelación no es válida.'] + $vacio;
			}

			$fecha = $leida;
		} else {
			$fecha = null;
		}

		return [
			'motivo' => null,
			'nivelacion' => $nivelacion === null ? null : (int) $nivelacion,
			'original' => $original === null ? null : (int) $original,
			'obs' => $obs,
			'fecha' => $fecha,
		];
	}

	/**
	 * `YYYY-MM-DD` o `YYYY-MM-DD HH:MM:SS`, en Bogotá, y **no futura**: un acta con
	 * fecha de mañana no es un acta. Devuelve el texto listo para la columna o
	 * `null` si no se puede leer.
	 */
	private function fechaDelActa(mixed $texto): ?string
	{
		if (! is_string($texto)) {
			return null;
		}

		$texto = trim($texto);

		foreach (['Y-m-d H:i:s', 'Y-m-d'] as $formato) {
			$fecha = \DateTime::createFromFormat('!'.$formato, $texto, new \DateTimeZone('America/Bogota'));

			if ($fecha !== false && $fecha->format($formato) === $texto) {
				if ($fecha > new \DateTime('now', new \DateTimeZone('America/Bogota'))) {
					return null;
				}

				return $fecha->format('Y-m-d H:i:s');
			}
		}

		return null;
	}

	/**
	 * La fila de `notas` con lo que hace falta para nivelarla: su destino (para
	 * el permiso y el recálculo) y su año (para la regla). `INNER JOIN` con la
	 * unidad y el periodo vivos: una nota huérfana no se puede nivelar, y aquí eso
	 * es un 404 y no un 500.
	 */
	private function filaParaNivelar(int $id): ?object
	{
		return DB::selectOne(
			'SELECT n.id, n.nota, n.nota_original, n.nota_nivelacion, n.nivelada_at, n.nivelada_por,
					n.nivelacion_obs, n.alumno_id, n.subunidad_id,
					u.asignatura_id, u.periodo_id, p.year_id
			   FROM notas n
			   INNER JOIN subunidades s ON s.id = n.subunidad_id AND s.deleted_at IS NULL
			   INNER JOIN unidades u ON u.id = s.unidad_id AND u.deleted_at IS NULL
			   INNER JOIN periodos p ON p.id = u.periodo_id AND p.deleted_at IS NULL
			  WHERE n.id = ? AND n.deleted_at IS NULL',
			[$id]
		);
	}

	/**
	 * La regla del año, o 422 si no es de las tres. `Nivelacion` lanza en vez de
	 * caer a `topada`, y aquí se convierte en un error que dice dónde arreglarlo.
	 *
	 * @return array{regla: string, nota_minima: int}
	 */
	private function reglaValidaDelAnio(int $yearId): array
	{
		$config = \App\Services\Nivelacion::reglaDelAnio($yearId);

		if ($config === null || ! \App\Services\Nivelacion::esRegla($config['regla'])) {
			abort(422, 'La regla de nivelación del año («'.($config['regla'] ?? '').'») no es válida: corríjala en los ajustes del año.');
		}

		return $config;
	}

	/**
	 * Escribe una nivelación en una fila ya cargada y validada. **Dentro de la
	 * transacción del llamante**: es lo que hace que el lote entre entero o no
	 * entre, y que la auditoría se deshaga con él.
	 *
	 * Las tres variantes de la §1: primera nivelación (`nota_original` nace de la
	 * vigente), sustitución (§1.3: la original se conserva) y corrección de la
	 * original (§1.6: se registra como `editar`, no como `nivelar`).
	 *
	 * @param  array{nivelacion: ?int, original: ?int, obs: ?string, fecha: ?string}  $forma
	 * @return array{regla_aplicada: array{regla: string, nota_minima: int, explicacion: string}}
	 */
	private function nivelarLaFila(object $fila, array $forma, object $user, Carbon $now, ?int $historialId): array
	{
		$config = $this->reglaValidaDelAnio((int) $fila->year_id);

		$yaNivelada = $fila->nota_original !== null;
		$original   = $forma['original'] ?? ($yaNivelada ? (int) $fila->nota_original : (int) $fila->nota);
		$nivelacion = $forma['nivelacion'] ?? (int) $fila->nota_nivelacion;
		$vigente    = (int) $fila->nota;

		$aplicada = \App\Services\Nivelacion::aplicar($config['regla'], $original, $nivelacion, $config['nota_minima']);
		$nueva    = $aplicada['nota'];

		// El acta se reescribe cuando llega una nivelación (primera o sustituta);
		// una corrección sola de la original la deja como estaba.
		$esCorreccionSola = $forma['nivelacion'] === null;

		$niveladaAt  = $esCorreccionSola ? $fila->nivelada_at : ($forma['fecha'] ?? $now->format('Y-m-d H:i:s'));
		$niveladaPor = $esCorreccionSola ? $fila->nivelada_por : $user->user_id;
		$obs         = $esCorreccionSola ? $fila->nivelacion_obs : $forma['obs'];

		DB::update(
			'UPDATE notas SET nota=?, nota_original=?, nota_nivelacion=?, nivelada_at=?, nivelada_por=?,
				nivelacion_obs=?, updated_by=?, updated_at=? WHERE id=?',
			[$nueva, $original, $nivelacion, $niveladaAt, $niveladaPor, $obs, $user->user_id, $now, $fila->id]
		);

		// El rastro viejo, **idéntico al de `putUpdate`**: es lo que lee el
		// historial de la app, y una nivelación no puede dejar un rastro distinto
		// del que deja teclear la nota.
		$this->bitacoraDeNota($fila, $vigente, $nueva, $user, $now, $historialId);

		$alumno = $fila->alumno_id === null ? null : (int) $fila->alumno_id;

		$linea = Auditoria::registrar();

		if ($esCorreccionSola) {
			$linea->editar('nota', (int) $fila->id)
				->de((int) $fila->nota_original)
				->a($original)
				->resumen('Valoración inicial corregida '.$fila->nota_original.' → '.$original
					.'; queda '.$nueva.' por regla '.$config['regla'].'.');
		} else {
			$linea->nivelar('nota', (int) $fila->id)
				->de($vigente)
				->a($nueva)
				->resumen(($yaNivelada ? 'Nivelación sustituida' : 'Nivelación').': '.$nivelacion
					.' sobre '.$original.', regla '.$config['regla'].'; queda '.$nueva.'.');
		}

		$linea->deAlumno($alumno, NombreDelAlumno::de($alumno))
			->en(asignatura: (int) $fila->asignatura_id, periodo: (int) $fila->periodo_id)
			->guardar();

		return [
			'regla_aplicada' => [
				'regla' => $config['regla'],
				'nota_minima' => $config['nota_minima'],
				'explicacion' => $aplicada['explicacion'],
			],
		];
	}

	/** La línea de `bitacoras` que dejan `putUpdate` y `putLote`, para que el historial de la app la lea igual. */
	private function bitacoraDeNota(object $fila, int $vieja, int $nueva, object $user, Carbon $now, ?int $historialId): void
	{
		DB::insert(
			'INSERT INTO bitacoras (created_by, historial_id, affected_user_id, affected_person_type,
				affected_element_type, affected_element_id, affected_element_new_value_int,
				affected_element_old_value_int, created_at)
			 VALUES (?, ?, ?, "Al", "Nota", ?, ?, ?, ?)',
			[$user->user_id, $historialId, $fila->alumno_id, $fila->id, $nueva, $vieja, $now]
		);
	}

	/** El ingreso del token (fase 2 de 18-auditoria.md), o `null` si el token es anterior. */
	private function historialDelToken(object $user): ?int
	{
		return isset($user->historial_id) && is_numeric($user->historial_id) ? (int) $user->historial_id : null;
	}

	/**
	 * La respuesta de la §1.2, leída **de la tabla y no de lo calculado**: lo que
	 * se devuelve es lo que quedó escrito. `regla_aplicada` es `null` en el
	 * `DELETE`; `definitiva` no viaja en los elementos del lote, que la llevan una
	 * vez por alumno en `definitivas`.
	 *
	 * @param  array{regla: string, nota_minima: int, explicacion: string}|null  $reglaAplicada
	 * @param  array<string, mixed>|null  $definitiva
	 * @return array<string, mixed>
	 */
	private function notaNivelada(int $id, ?array $reglaAplicada, ?array $definitiva, bool $conDefinitiva = true): array
	{
		$n = DB::selectOne(
			'SELECT n.id, n.alumno_id, n.subunidad_id, n.nota, n.nota_original, n.nota_nivelacion,
					n.nivelada_at, n.nivelada_por, us.username AS nivelada_por_username,
					n.nivelacion_obs, n.updated_at
			   FROM notas n
			   LEFT JOIN users us ON us.id = n.nivelada_por
			  WHERE n.id = ?',
			[$id]
		);

		$entero = fn ($v) => $v === null ? null : (int) $v;

		$respuesta = [
			'id' => (int) $n->id,
			'alumno_id' => $entero($n->alumno_id),
			'subunidad_id' => $entero($n->subunidad_id),
			'nota' => (int) $n->nota,
			'nota_original' => $entero($n->nota_original),
			'nota_nivelacion' => $entero($n->nota_nivelacion),
			'nivelada_at' => $n->nivelada_at,
			'nivelada_por' => $entero($n->nivelada_por),
			'nivelada_por_username' => $n->nivelada_por_username,
			'nivelacion_obs' => $n->nivelacion_obs,
			'updated_at' => $n->updated_at,
			'regla_aplicada' => $reglaAplicada,
		];

		if ($conDefinitiva) {
			$respuesta['definitiva'] = $definitiva;
		}

		return $respuesta;
	}

}