<?php namespace App\Http\Controllers;


use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;
use Carbon\Carbon;

use App\User;
use App\Models\Year;
use App\Models\Profesor;
use App\Models\Asignatura;
use App\Models\Unidad;
use App\Models\Grupo;
use App\Models\NotaFinal;
use App\Models\Debugging;
use App\Http\Controllers\Alumnos\Definitivas;
use \Log;

use App\Http\Controllers\Alumnos\Solicitudes;
use App\Services\Auditoria;
use App\Services\BoletinIndependiente;
use App\Support\EscalaDeNotas;
use App\Support\PeriodoDeLaFila;
use App\Support\NombreDelAlumno;
use App\Services\Nivelacion;


class DefinitivasPeriodosController extends Controller {

	/**
	 * Las once columnas que `notas_finales` tenía antes de la nivelación, para las
	 * dos ramas de `putUpdate` **sin `nf_id`**, que devuelven la fila al cliente.
	 *
	 * Con `SELECT *` esas dos respuestas ganaban solas las cinco columnas nuevas
	 * —tres de `2026_09_02_100000` y dos de `2026_09_02_200000`— **sin que nadie
	 * tocara el método**, y ninguna instantánea lo habría cazado. Lo señaló
	 * `tools/filas-enteras-al-cliente.php`.
	 *
	 * **Congelada a propósito** (22 §3.4): `definitivas_periodos/update` lo llama
	 * `myvc_flutter` y el §6.1 del reparto dice que ese camino no cambia. El acta
	 * de la nivelación viaja por `editnota/alum-asignatura`, que es la pantalla que
	 * la edita, y por `definitivas_periodos/nivelar`, que nació con ella.
	 *
	 * El `CAST` se queda dentro: la columna es `DECIMAL(7,4)` y PDO la trae como
	 * cadena.
	 */
	private const COLUMNAS_DE_LA_DEFINITIVA = 'SELECT id, alumno_id, asignatura_id, periodo_id, periodo,
			CAST(nota AS DOUBLE) AS nota, recuperada, manual, updated_by, created_at, updated_at';

	/**
	 * La rejilla de definitivas: **los cuatro periodos a la vez, en cuatro columnas**.
	 *
	 * Y por eso es la pantalla que más necesita `bol_independiente_aparte_en` (§7 de
	 * la cola de la noche del 31 ago 2026, forma fijada por el front):
	 *
	 *     "bol_independiente_aparte_en": [2, 3]   // los `numero`; [] si ninguno
	 *
	 * **Un booleano aplanado no serviría aquí**, que es justo el argumento con el que
	 * el front lo pidió: enseñando las cuatro columnas a la vez, un `true` no dice
	 * **cuál** de las cuatro celdas es la rara — y la celda rara es lo que el docente
	 * está mirando cuando ve la nota de un estudiante que no vio en su planilla, o un
	 * 0 sin explicación, que es la §9.1 hecha visible.
	 *
	 * **El nombre es distinto del `bol_independiente_periodos` de la ficha a
	 * propósito.** Aquél carga `[{periodo_id, numero, aplica, tiene_datos}]`; éste es
	 * una lista plana de `numero`. Un mismo nombre con dos formas según por dónde
	 * salga es una trampa que este módulo ya ha pisado dos veces.
	 */
	public function getIndex()
	{
		$user 			= User::fromToken();

		if ($user->tipo == 'Profesor') {
			$profe_id = $user->persona_id;
		} else if($user->is_superuser){
			$profe_id = Request::input('profesor_id');
		}
		
		
		$definitivas 	= new Definitivas();
		$asignaturas 	= $definitivas->asignaturas_docente($profe_id, $user->year_id);
		
		$cantAsig 		= count($asignaturas);

		// UNA consulta para toda la respuesta, y eso no es prematuro: aquí son treinta
		// alumnos por cuatro periodos y por cada asignatura del docente. Preguntando
		// par a par con `BoletinIndependiente::aplica()` serían ciento veinte lecturas
		// por grupo aunque la memoria del servicio las acorte, y `delGrupo()` es por
		// `(grupo, periodo)`, o sea cuatro por grupo. Aquí no hace falta `tiene_datos`
		// —eso es la ficha—, así que es una lectura de `bol_ind_periodos` **sin
		// `EXISTS`** y cabe entera en una. Quien decide sigue siendo el servicio.
		$aparte_en = BoletinIndependiente::aparteEnPorAlumno((int) $user->year_id);
		
		for ($i=0; $i < $cantAsig; $i++) { 
			
			$asignaturas[$i]->alumnos = NotaFinal::alumnos_grupo_nota_final($asignaturas[$i]->grupo_id, $asignaturas[$i]->asignatura_id, $user->user_id);

			foreach ($asignaturas[$i]->alumnos['alumnos'] as $alumno) {
				// `[]` y no `null` para el que va con el grupo: una lista vacía se
				// recorre igual que una llena y no obliga al front a decidir qué
				// significa una ausencia. Este módulo ya perdió una semana por leer una
				// ausencia al revés (§6.4 del 19).
				$alumno->bol_independiente_aparte_en = $aparte_en[(int) $alumno->alumno_id] ?? [];
			}
			
		}
		
		return $asignaturas;
	}


	/**
	 * **Retirado: no puede terminar, y por el camino borraba las definitivas puestas
	 * a mano.** Ver 05 §71.
	 *
	 * Lo que hacía, medido —no leído— el 22 ago 2026 sobre una asignatura con 164
	 * definitivas, cuatro de ellas manuales:
	 *
	 * 1. `Definitivas::calcular_notas_finales_asignatura` empieza por
	 *    `DELETE FROM notas_finales WHERE asignatura_id=? and (manual is null or
	 *    manual=1)`. Es un DELETE de verdad, no la papelera, **sin filtro de periodo
	 *    ni de año**, y el criterio está **invertido**: se lleva justo las manuales.
	 *    Medido: 164 → 160 filas, y las **cuatro manuales a cero**. Las automáticas
	 *    se pueden recalcular; las de a mano las escribió una persona.
	 * 2. Después consulta `g.asignatura_id`, columna que `grupos` no tiene, así que
	 *    revienta con 500 — **con el borrado ya hecho**, porque no hay transacción.
	 * 3. Y el id que recibe es `Request::input('profesor_id')` usado como
	 *    `asignatura_id`, con el `// Aquí un error por arreglar` del propio autor al
	 *    lado: quien lo llamara creyendo que recalcula lo suyo, borraría lo de otra
	 *    asignatura cualquiera.
	 *
	 * Así que **nunca ha calculado nada**: sólo destruye. Se corta aquí, antes de
	 * escribir, y no se borra la ruta —la regla de este repo es que un endpoint
	 * enrutado y roto se documenta, porque borrarlo convierte un 500 en un 404 sin
	 * decirle a nadie qué pretendía—. Ningún cliente lo llama: `myvc_front` tiene el
	 * método en `DefinitivasPeriodosApi.ts:57` y **ninguna pantalla lo usa**.
	 *
	 * Recalcular una asignatura de verdad es `App\Services\DefinitivasDeAsignatura`,
	 * que ya existe (fase 1); cablearlo aquí es la fase 3 y retirar el botón, la 5.
	 * Ver 10-definitivas.md.
	 */
	public function putCalcularNotasFinalesAsignatura()
	{
		abort(410, 'Este cálculo está retirado: borraba las definitivas puestas a mano y nunca llegó a calcular ninguna.');
	}


	

	public function putCalcularGrupoPeriodo()
	{
		$user 			= User::fromToken();
		$grupo_id 		= Request::input('grupo_id');
		$periodo_id 	= Request::input('periodo_id');
		$num_periodo 	= Request::input('num_periodo');
		$now 			= Carbon::now('America/Bogota');

		if ($user->tipo == 'Profesor' || $user->is_superuser) {
			//$profesor_id 	= Request::input('profesor_id');
		}else{
			return abort(400, 'No tienes privilegios.');
		}
		
		DB::delete('DELETE nf FROM notas_finales nf INNER JOIN asignaturas a ON a.id=nf.asignatura_id and a.grupo_id=? 
					WHERE (nf.manual is null or nf.manual=0) and (nf.recuperada is null or nf.recuperada=0) and nf.periodo_id=?', 
					[ $grupo_id, $periodo_id ]);
		
		// `decimal(7,4)` y no `decimal(4,0)`: es el **gemelo** del `CAST` de
		// `DefinitivasDeAsignatura`, y los dos escriben la misma columna. Dejarlo en
		// entero aquí sería peor que no haber migrado nada — la definitiva tendría
		// decimales o no según **por qué botón** se hubiera recalculado, y el propio
		// alumno cambiaría de puesto al pulsar el otro. Este método está condenado
		// (es uno de los seis escritores que la fase 3 de 10-definitivas.md sustituye)
		// pero sigue desplegado en los quince colegios, que es lo que obliga a
		// moverlo hoy.
		$consulta = 'SELECT nt.alumno_id, asi.id as asignatura_id, nt.periodo_id, cast(sum(nt.ValorNota) as decimal(7,4)) as nota_asignatura
				FROM asignaturas asi 
				inner join 
					(select u.asignatura_id, n.alumno_id, u.periodo_id, sum( ((u.porcentaje/100)*((s.porcentaje/100)*n.nota)) ) ValorNota
					from unidades u 
					inner join subunidades s on s.unidad_id=u.id and s.deleted_at is null and u.periodo_id=:periodo_id
					inner join notas n on n.subunidad_id=s.id and n.deleted_at is null
					inner join asignaturas asi2 on asi2.id=u.asignatura_id and asi2.deleted_at is null and asi2.grupo_id=:grupo_id
					where  u.deleted_at is null
					  and u.alumno_id <=> '.\App\Services\BoletinIndependiente::alcanceCorrelacionado('n.alumno_id', 'u').'
					group by n.alumno_id, u.id, s.id
				) nt ON asi.id=nt.asignatura_id and asi.grupo_id=:grupo_id2 
				where asi.deleted_at is null
				group by nt.alumno_id, asi.id, nt.periodo_id';
			
		$defi_autos = DB::select($consulta, [ ':periodo_id'=>$periodo_id, ':grupo_id'=>$grupo_id, ':grupo_id2'=>$grupo_id ]);
		$cant_def = count($defi_autos);
					
		for ($i=0; $i < $cant_def; $i++) {

			$nota_asignatura = 0;
			if ($defi_autos[$i]->nota_asignatura) {
				$nota_asignatura = $defi_autos[$i]->nota_asignatura;
			}

			// **Los diez valores van ligados; antes iban concatenados.** Dos de ellos
			// —`$num_periodo` y `$periodo_id`— salen directos de `Request::input`
			// veinte líneas más arriba, así que esto era una inyección sobre un
			// INSERT en `notas_finales`, dentro de un bucle y al alcance de
			// cualquiera de los 51 profesores que pasa `auth.personal`.
			//
			// Lo encontró otra sesión el 22 ago 2026 mirando el hermano de esta
			// forma en `Disciplina\OrdinalesController::putOrdinales`, que tenía la
			// misma concatenación en un SELECT. Aquí pesa más porque **escribe**.
			//
			// Se liga y nada más: ni una línea de lógica. Este método es uno de los
			// seis escritores que la fase 3 de 10-definitivas.md va a sustituir por
			// `DefinitivasDeAsignatura`, así que el bloque entero está condenado —
			// pero **sigue desplegado en los dieciséis colegios** y la fase 3 no
			// tiene fecha, que es lo que decide que valga la pena arreglarlo ahora.
			$consulta = 'INSERT INTO notas_finales(alumno_id, asignatura_id, periodo_id, periodo, nota, recuperada, manual, updated_by, created_at, updated_at) 
						SELECT * FROM (SELECT ? as alumno_id, ? as asignatura_id, ? as periodo_id, ? as periodo,
						? as nota_asignatura, 0 as recuperada, 0 as manual, ? as crea, ? as fecha, ? as fecha2) AS tmp
						WHERE NOT EXISTS (
							SELECT id FROM notas_finales WHERE alumno_id=? and asignatura_id=? and periodo_id=?
						) LIMIT 1';

			// **Uno de los DOS que el censo del 05 §191 no listaba** — y los dos escriben
			// `notas_finales`, que es la tabla de la que va la mitad del plan. Un censo de
			// «qué escribe» hecho por el nombre del método los dejaba fuera justo ahí.
			//
			// Y la otra mitad de este método **ya está hecha**: su lectura de `unidades`
			// se acotó al boletín independiente en DEF-108, en su propia pasada y su
			// propio commit. **Fueron dos y no una a propósito**: cambiar la palabra no
			// mueve la conducta y acotar la lectura sí, y mezclarlas habría quitado la
			// única propiedad que hacía seguro este cambio.
			DB::insert($consulta, [
				$defi_autos[$i]->alumno_id, $defi_autos[$i]->asignatura_id,
				$defi_autos[$i]->periodo_id, $num_periodo,
				$nota_asignatura, $user->user_id, $now, $now,
				$defi_autos[$i]->alumno_id, $defi_autos[$i]->asignatura_id, $periodo_id,
			]);
			
		}
		
		return 'Calculado';
	}


	
	
	
	public function putUpdate()
	{
		$user 			= User::fromToken();
		// Las dos ramas de este método escriben en sitios distintos y por eso el
		// periodo se saca distinto:
		//
		//   - con `nf_id` se ACTUALIZA una nota final que ya existe, así que manda
		//     su `periodo_id` y no lo que diga el cuerpo. Es la llamada de la
		//     rejilla de definitivas, la que la §27.1 daba por la más difícil: el
		//     front manda `nf_id` y `num_periodo` sin `periodo_id`.
		//   - sin `nf_id` se INSERTA una fila nueva con `periodo_id` sacado de
		//     `num_periodo` veinte líneas más abajo. Ahí declarar y escribir son la
		//     misma cosa, así que comprobar el declarado sí es comprobar el escrito.
		// Y antes de la guarda, comprobar que **viene el campo con el que se va a
		// decidir**. Sin esto, un cuerpo sin `nf_id` ni `num_periodo` llega a
		// `PeriodoDeLaFila::porNumero($user, null)`, no resuelve ningún periodo y
		// el rechazo sale **por la guarda de permisos**: el profesor lee «no
		// tienes permiso para modificar definitivas» cuando lo que pasa es que
		// falta un dato.
		//
		// Es la §3.4 de docs/migracion/10-definitivas.md —el backend contesta el
		// mismo error para dos fallos distintos— y aquí es de los caros, porque el
		// mensaje **manda a investigar a la persona equivocada**: quien lo reciba
		// va a mirar los roles del profesor y no el cuerpo de la petición.
		//
		// Lo destapó `myvc-front-9a` el 24 ago 2026, escribiendo la fase 4: yo le
		// dije que mandara `periodo_id` —que este método no lee— y fue a
		// comprobarlo antes de mandarlo.
		if (! Request::input('nf_id') && Request::input('num_periodo') === null) {
			abort(422, 'Falta `num_periodo`: sin `nf_id` hay que decir en qué periodo se escribe.');
		}

		User::pueden_modificar_definitivas($user, Request::input('nf_id')
			? PeriodoDeLaFila::deNotaFinal(Request::input('nf_id'))
			: PeriodoDeLaFila::porNumero($user, Request::input('num_periodo')));
		
		$now 		= Carbon::now('America/Bogota');
		
		if (Request::input('nf_id')) {
			$nf_id 		= Request::input('nf_id');
			
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
			// `notas_finales`.
			$consulta 	= 'SELECT n.* FROM notas_finales n WHERE n.id=? ';

			$nota 		= DB::select($consulta, [$nf_id])[0];

			$bit_by 	= $user->user_id;
			$bit_hist 	= isset($user->historial_id) && is_numeric($user->historial_id)
				? (int) $user->historial_id
				: null;
			$bit_old 	= $nota->nota; 				// Guardo la nota antigua
			// La definitiva tecleada a mano pasa por la misma escala que una nota
			// suelta: es el otro sitio por donde entra un número a pelo. 18 §4.5.1.
			EscalaDeNotas::comprobar(Request::input('nota'), PeriodoDeLaFila::deNotaFinal($nf_id));

			$bit_new 	= Request::input('nota'); 	// Guardo la nota nueva
			$bit_per 	= $user->periodo_id;

			
			$consulta 	= 'UPDATE notas_finales SET nota=?, manual=true, updated_by=?, updated_at=? WHERE id=?';
			DB::update($consulta, [ Request::input('nota'), $user->user_id, $now, $nf_id ]);
			
			$consulta 	= 'INSERT INTO bitacoras (created_by, historial_id, affected_user_id, affected_person_type, affected_element_type, affected_element_id, affected_element_new_value_int, affected_element_old_value_int, created_at) 
						VALUES (?, ?, ?, "Al", "NF_UPDATE", ?, ?, ?, ?)';

			// **Los dos valores van redondeados A PROPÓSITO, y sólo aquí.**
			// `affected_element_new_value_int` y `_old_value_int` son columnas `int`, y
			// desde que `notas_finales.nota` es `DECIMAL(7,4)` lo que llega es `43.7500`.
			// Meter eso en un `int` no es inocuo: **con `sql_mode` vacío —el del
			// contenedor— MySQL lo redondea en silencio a 44, y con `STRICT_TRANS_TABLES`
			// lo rechaza**, o sea un 500 al guardar una definitiva a mano. Como no
			// sabemos el `sql_mode` de los quince cPanel, se decide aquí y no allí.
			//
			// El decimal exacto **no se pierde**: el rastro nuevo de tres líneas más
			// abajo guarda `valor_anterior`/`valor_nuevo` en columnas **JSON**, así que
			// la verdad queda en `auditoria` y en `bitacoras` queda el entero de siempre.
			// Ensanchar las dos columnas de `bitacoras` sería lo correcto, pero las
			// comparten `Nota`, `Nueva subunidad` y `AlumnoPideAjeno:user_id`: es una
			// migración con su propia decisión, no un efecto secundario de ésta.
			DB::insert($consulta, [$bit_by, $bit_hist, $nota->alumno_id, $nf_id,
				(int) round((float) $bit_new), (int) round((float) $bit_old), $now]);

			// El rastro nuevo, al lado del viejo (18 §4). `nota_final` y no
			// `"NF_UPDATE"`: en `bitacoras` esta columna es texto libre y hoy
			// conviven ahí `Nota`, `NF_UPDATE`, `Nueva subunidad` y
			// `AlumnoPideAjeno:user_id`, así que agrupar por tipo obliga a
			// conocerse la lista de memoria.
			//
			// **Esta línea es la mitad humana de una distinción que hoy no
			// existe.** La misma fila de `notas_finales` la escribe también
			// `DefinitivasDeAsignatura::recalcular`, y ésa es del sistema: sin
			// separarlas, la pantalla se llena de recálculos automáticos y la
			// definitiva que alguien tecleó deja de poder encontrarse. Aquí es una
			// persona, y por eso el actor sale del contexto y no de
			// `porElSistema()`.
			$alumnoDeLaLinea = $nota->alumno_id === null ? null : (int) $nota->alumno_id;

			Auditoria::registrar()
				->editar('nota_final', (int) $nf_id)
				->deAlumno($alumnoDeLaLinea, NombreDelAlumno::de($alumnoDeLaLinea))
				->en(periodo: $nota->periodo_id === null ? null : (int) $nota->periodo_id)
				->de($bit_old)
				->a($bit_new)
				->guardar();

			return 'Cambiada';
		}else{

			$num_periodo 	= Request::input('num_periodo');
			$periodos 		= DB::select('SELECT * FROM periodos WHERE deleted_at is null and numero=? and year_id=?', [$num_periodo, $user->year_id]);
			
			if (count($periodos) > 0) {
				$periodo = $periodos[0];
			}else{
				return abort(400, 'No existe el peridoo.');
			}

			// **Esto era un INSERT incondicional, y el front llega aquí justo cuando
			// la fila puede existir ya** — es la rama de «no tengo `nf_id` a mano»
			// (§2.3). De ahí salen los duplicados auto+manual de la §2, y con la
			// clave única de la fase 2 sería **un 500 al profesor tecleando una
			// definitiva**, que es el peor sitio donde ponerlo.
			//
			// Ahora decide por existencia, como hace `DefinitivasDeAsignatura`. **No
			// se usa `ON DUPLICATE KEY UPDATE`**: la clave única todavía no está, así
			// que esa forma no dispararía nunca y se comportaría como el INSERT de
			// antes — el mismo error que la fase 1 ya documentó en su propio UPSERT.
			//
			// Y va en transacción porque entre el SELECT y el INSERT hay una ventana:
			// dos profesores tecleando la misma celda a la vez crearían dos filas.
			// La tercera puerta por la que entra una definitiva a pelo, y la que
			// más fácil se queda fuera: no tiene `nf_id`, así que la escala se
			// resuelve por el periodo que se acaba de comprobar arriba. Va ANTES
			// de la transacción — abortar dentro haría un rollback de una
			// transacción que no llegó a escribir nada. 18 §4.5.1.
			EscalaDeNotas::comprobar(Request::input('nota'), (int) $periodo->id);

			return DB::transaction(function () use ($user, $now, $num_periodo, $periodo) {

				// `nota` además de `id`: la fila ya se está bloqueando con `FOR
				// UPDATE`, así que traer la columna no cuesta una consulta más y es
				// lo que permite que la línea de auditoría diga **de qué a qué**.
				// Sin ella el rastro contaría que alguien tocó la definitiva pero no
				// qué había antes, que es la mitad de la pregunta.
				$existente = DB::selectOne(
					'SELECT id, nota FROM notas_finales
					  WHERE alumno_id = ? AND asignatura_id = ? AND periodo_id = ?
					  ORDER BY id LIMIT 1 FOR UPDATE',
					[Request::input('alumno_id'), Request::input('asignatura_id'), $periodo->id]
				);

				if ($existente !== null) {
					DB::update(
						'UPDATE notas_finales
							SET nota = ?, periodo = ?, manual = 1, updated_by = ?, updated_at = ?
						  WHERE id = ?',
						[Request::input('nota'), $num_periodo, $user->user_id, $now, $existente->id]
					);

					/*
					 * **Esta rama no escribía en `bitacoras` y no lo hacía nadie
					 * por ella**: no es uno de los diez escritores, es un hueco.
					 *
					 * Lo señaló `tools/escrituras-sin-auditoria.php` al volver a
					 * correrlo con los diez ya traducidos — el método pasó a
					 * «audita menos veces de las que escribe», que es la única
					 * pregunta que el detector contesta bien y que no se puede
					 * contestar leyendo la lista de los diez.
					 *
					 * Y es la rama que el front usa de verdad cuando no tiene
					 * `nf_id` a mano (§2.3), o sea que una definitiva tecleada a
					 * mano por aquí era **invisible** para el colegio.
					 */
					$alumnoDeLaLinea = (int) Request::input('alumno_id');

					Auditoria::registrar()
						->editar('nota_final', (int) $existente->id)
						->deAlumno($alumnoDeLaLinea, NombreDelAlumno::de($alumnoDeLaLinea))
						->en(asignatura: (int) Request::input('asignatura_id'), periodo: (int) $periodo->id)
						->de($existente->nota)
						->a(Request::input('nota'))
						->guardar();

					return DB::select(self::COLUMNAS_DE_LA_DEFINITIVA.' FROM notas_finales WHERE id=?', [$existente->id]);
				}

				$consulta = 'INSERT INTO notas_finales(alumno_id, asignatura_id, periodo_id, periodo, nota, recuperada, manual, updated_by, created_at, updated_at)
					VALUES(:alumno_id, :asignatura_id, :periodo_id, :periodo, :nota, :recuperada, :manual, :updated_by, :created_at, :updated_at)';

				DB::insert($consulta, [':alumno_id' => Request::input('alumno_id'), ':asignatura_id' => Request::input('asignatura_id'), ':periodo_id' => $periodo->id,
								':periodo' => $num_periodo, ':nota' => Request::input('nota'), ':recuperada' => 0, ':manual' => 1, ':updated_by' => $user->user_id, ':created_at' => $now, ':updated_at' => $now ]);

				$last_id = DB::getPdo()->lastInsertId();

				// La otra mitad del mismo hueco: aquí la definitiva **nace**, y sin
				// esta línea la pantalla enseñaría ediciones de una fila cuyo
				// origen no consta. `crear` y no `editar`, y sin `de()`: no había
				// valor anterior, que es distinto de que el anterior fuera null
				// (18 §5.3).
				$alumnoDeLaLinea = (int) Request::input('alumno_id');

				Auditoria::registrar()
					->crear('nota_final', (int) $last_id)
					->deAlumno($alumnoDeLaLinea, NombreDelAlumno::de($alumnoDeLaLinea))
					->en(asignatura: (int) Request::input('asignatura_id'), periodo: (int) $periodo->id)
					->a(Request::input('nota'))
					->guardar();

				return DB::select(self::COLUMNAS_DE_LA_DEFINITIVA.' FROM notas_finales WHERE id=?', [$last_id]);
			});
		}
		
		
	}


	
	
	/**
	 * `PUT definitivas_periodos/nivelar` — nivelar la definitiva del periodo (A8,
	 * 22 §6).
	 *
	 * **Endpoint nuevo, como los del indicador y por lo mismo**: `putUpdate` teclea
	 * la definitiva a mano y lo llama `myvc_flutter` (`DefinitivasApi.dart`); si
	 * aprendiera a nivelar, una definitiva tecleada desde el móvil se guardaría
	 * topada. Aquél no cambia ni una línea, y el test lo fija.
	 *
	 * ## Lo que hace distinto del indicador, y no es opcional
	 *
	 * Nivelar la definitiva **enciende `recuperada` y `manual`**, que es lo que ya
	 * hace `putUpdate` con `manual` y lo que el plan (§3.4) dice que pasa: la
	 * desengancha del recálculo, así que `DefinitivasDeAsignatura` la respeta y no
	 * la pisa la próxima vez que alguien abra la planilla. Sin eso, la nivelación
	 * duraría hasta el primer recálculo y desaparecería sin que nadie tocara nada.
	 *
	 * `recuperada` **no cambia de significado**: sigue queriendo decir «viene de una
	 * nivelación». Lo que se gana es que ahora la fila dice de dónde venía.
	 *
	 * ## El guard
	 *
	 * `profes_pueden_nivelar` del periodo **de la fila**, igual que el del
	 * indicador, y **403** porque es código nuevo. El guard viejo
	 * `pueden_modificar_definitivas` conserva su 400 intacto: lo llaman cinco
	 * métodos de esta misma clase desde la app.
	 */
	public function putNivelar()
	{
		$user = User::fromToken();
		$now  = Carbon::now('America/Bogota');

		$nfId = Request::input('nf_id');

		if (! is_numeric($nfId)) {
			abort(422, 'Hace falta nf_id.');
		}

		$nivelacion = Request::input('nota_nivelacion');

		if (! is_numeric($nivelacion)) {
			abort(422, 'Hace falta nota_nivelacion.');
		}

		$fila = DB::selectOne(
			'SELECT nf.id, nf.alumno_id, nf.asignatura_id, nf.periodo_id, nf.periodo,
					CAST(nf.nota AS DOUBLE) AS nota, CAST(nf.nota_original AS DOUBLE) AS nota_original,
					CAST(nf.nota_nivelacion AS DOUBLE) AS nota_nivelacion, nf.recuperada, nf.manual,
					nf.nivelada_at, nf.nivelada_por, nf.nivelacion_obs, p.year_id
			   FROM notas_finales nf
			   INNER JOIN periodos p ON p.id = nf.periodo_id AND p.deleted_at IS NULL
			  WHERE nf.id = ?',
			[(int) $nfId]
		);

		if ($fila === null) {
			abort(404, 'No existe esa definitiva, o su periodo ya no está.');
		}

		if (! User::puedeNivelar($user, (int) $fila->periodo_id)) {
			abort(403, 'No tienes permiso para nivelar en este periodo.');
		}

		EscalaDeNotas::comprobar($nivelacion, (int) $fila->periodo_id);

		$observacion = Request::input('observacion');

		if ($observacion !== null && ! is_string($observacion)) {
			abort(422, 'La observación tiene que ser texto.');
		}

		$observacion = $observacion === null || trim($observacion) === '' ? null : trim($observacion);

		if ($observacion !== null && mb_strlen($observacion) > 255) {
			abort(422, 'La observación no puede pasar de 255 caracteres.');
		}

		$config = Nivelacion::reglaDelAnio((int) $fila->year_id);

		if ($config === null || ! Nivelacion::esRegla($config['regla'])) {
			abort(422, 'La regla de nivelación del año («'.($config['regla'] ?? '').'») no es válida: corríjala en los ajustes del año.');
		}

		// **La original se conserva si ya estaba nivelada**, igual que en el
		// indicador (§1.3): repetir sustituye la nivelación, no apila ni pisa la
		// valoración con la que ya era nivelada.
		$original = $fila->nota_original !== null ? (float) $fila->nota_original : (float) $fila->nota;
		$vigente  = (float) $fila->nota;

		// La regla trabaja en enteros —es la escala del colegio—, y la definitiva es
		// `DECIMAL(7,4)` porque la produce una suma ponderada. Se redondea **sólo
		// para decidir**, y lo que se guarda es lo que la regla eligió: con `mayor`
		// puede ser la original con sus decimales intactos.
		$aplicada = Nivelacion::aplicar(
			$config['regla'], (int) round($original), (int) round((float) $nivelacion), $config['nota_minima']
		);

		$nueva = $config['regla'] === Nivelacion::MAYOR && $original >= (float) $nivelacion
			? $original
			: (float) $aplicada['nota'];

		$fecha = Request::input('fecha');
		$niveladaAt = $fecha === null || $fecha === '' ? $now->format('Y-m-d H:i:s') : $this->fechaDelActa($fecha);

		if ($niveladaAt === null) {
			abort(422, 'La fecha de la nivelación no es válida.');
		}

		DB::transaction(function () use ($fila, $nueva, $original, $nivelacion, $niveladaAt, $observacion, $user, $now, $vigente, $config, $aplicada) {
			DB::update(
				'UPDATE notas_finales SET nota=?, nota_original=?, nota_nivelacion=?, nivelada_at=?,
					nivelada_por=?, nivelacion_obs=?, recuperada=1, manual=1, updated_by=?, updated_at=?
				 WHERE id=?',
				[$nueva, $original, $nivelacion, $niveladaAt, $user->user_id, $observacion,
					$user->user_id, $now, $fila->id]
			);

			// El rastro viejo, con los dos valores **redondeados**: las columnas de
			// `bitacoras` son `int` y con `sql_mode` vacío MySQL redondearía en
			// silencio, con `STRICT_TRANS_TABLES` daría 500. Es la misma decisión que
			// tomó `putUpdate` cuando la nota pasó a `DECIMAL`, y por eso se copia en
			// vez de inventarse otra.
			DB::insert(
				'INSERT INTO bitacoras (created_by, historial_id, affected_user_id, affected_person_type,
					affected_element_type, affected_element_id, affected_element_new_value_int,
					affected_element_old_value_int, created_at)
				 VALUES (?, ?, ?, "Al", "NF_UPDATE", ?, ?, ?, ?)',
				[
					$user->user_id,
					isset($user->historial_id) && is_numeric($user->historial_id) ? (int) $user->historial_id : null,
					$fila->alumno_id,
					$fila->id,
					(int) round($nueva),
					(int) round($vigente),
					$now,
				]
			);

			$alumnoDeLaLinea = $fila->alumno_id === null ? null : (int) $fila->alumno_id;

			Auditoria::registrar()
				->nivelar('nota_final', (int) $fila->id)
				->deAlumno($alumnoDeLaLinea, NombreDelAlumno::de($alumnoDeLaLinea))
				->en(
					asignatura: $fila->asignatura_id === null ? null : (int) $fila->asignatura_id,
					periodo: (int) $fila->periodo_id,
				)
				->de($vigente)
				->a($nueva)
				->resumen('Nivelación de la definitiva: '.$nivelacion.' sobre '.$original
					.', regla '.$config['regla'].'; queda '.$aplicada['nota'].'. Queda marcada como recuperada y manual.')
				->guardar();
		});

		return $this->definitivaNivelada((int) $fila->id, [
			'regla' => $config['regla'],
			'nota_minima' => $config['nota_minima'],
			'explicacion' => $aplicada['explicacion'],
		]);
	}

	/**
	 * `YYYY-MM-DD` o `YYYY-MM-DD HH:MM:SS`, en Bogotá y no futura. Gemela de la de
	 * `NotasController`: son dos controladores y una copia de doce líneas es
	 * preferible a un `Support` nuevo con un solo uso por lado — el día que haya un
	 * tercero, se saca.
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
	 * La observación y la fecha del acta de una recuperación del año, validadas.
	 *
	 * Las dos opcionales: sin ellas, la fecha es la del servidor y la observación
	 * queda `null`. Es lo que hace que el cliente de hoy —que manda sólo
	 * `{rf_id, nota}`— siga funcionando exactamente igual.
	 *
	 * @return array{fecha: string, observacion: ?string}
	 */
	private function actaDeLaRecuperacion(Carbon $now): array
	{
		$observacion = Request::input('observacion');

		if ($observacion !== null && ! is_string($observacion)) {
			abort(422, 'La observación tiene que ser texto.');
		}

		$observacion = $observacion === null || trim($observacion) === '' ? null : trim($observacion);

		if ($observacion !== null && mb_strlen($observacion) > 255) {
			abort(422, 'La observación no puede pasar de 255 caracteres.');
		}

		$fecha = Request::input('fecha');

		if ($fecha === null || $fecha === '') {
			return ['fecha' => $now->format('Y-m-d H:i:s'), 'observacion' => $observacion];
		}

		$leida = $this->fechaDelActa($fecha);

		if ($leida === null) {
			abort(422, 'La fecha de la nivelación no es válida.');
		}

		return ['fecha' => $leida, 'observacion' => $observacion];
	}

	/**
	 * La fila como la devuelve el endpoint: **leída de la tabla**, no de lo
	 * calculado, por la misma razón que en el lote de notas — lo que se devuelve
	 * tiene que ser lo que quedó escrito.
	 *
	 * @param  array{regla: string, nota_minima: int, explicacion: string}  $reglaAplicada
	 * @return array<string, mixed>
	 */
	private function definitivaNivelada(int $id, array $reglaAplicada): array
	{
		$f = DB::selectOne(
			'SELECT nf.id, nf.alumno_id, nf.asignatura_id, nf.periodo_id, nf.periodo,
					CAST(nf.nota AS DOUBLE) AS nota, CAST(nf.nota_original AS DOUBLE) AS nota_original,
					CAST(nf.nota_nivelacion AS DOUBLE) AS nota_nivelacion,
					nf.nivelada_at, nf.nivelada_por, us.username AS nivelada_por_username,
					nf.nivelacion_obs, nf.recuperada, nf.manual, nf.updated_at
			   FROM notas_finales nf
			   LEFT JOIN users us ON us.id = nf.nivelada_por
			  WHERE nf.id = ?',
			[$id]
		);

		return [
			'nf_id' => (int) $f->id,
			'alumno_id' => $f->alumno_id === null ? null : (int) $f->alumno_id,
			'asignatura_id' => $f->asignatura_id === null ? null : (int) $f->asignatura_id,
			'periodo_id' => $f->periodo_id === null ? null : (int) $f->periodo_id,
			'periodo' => $f->periodo === null ? null : (int) $f->periodo,
			'nota' => (float) $f->nota,
			'nota_original' => $f->nota_original === null ? null : (float) $f->nota_original,
			'nota_nivelacion' => $f->nota_nivelacion === null ? null : (float) $f->nota_nivelacion,
			'nivelada_at' => $f->nivelada_at,
			'nivelada_por' => $f->nivelada_por === null ? null : (int) $f->nivelada_por,
			'nivelada_por_username' => $f->nivelada_por_username,
			'nivelacion_obs' => $f->nivelacion_obs,
			'recuperada' => (bool) $f->recuperada,
			'manual' => (bool) $f->manual,
			'updated_at' => $f->updated_at,
			'regla_aplicada' => $reglaAplicada,
		];
	}


	public function putUpdateRecuperacion()
	{
		$user 			= User::fromToken();
		// `recuperacion_final` NO tiene `periodo_id` —guarda alumno, asignatura,
		// `year` y nota—, así que no hay fila de la que derivar un periodo. Lo que
		// se toca es del AÑO, y por eso se exigen abiertos **todos** los periodos
		// del año en vez de uno. Decisión de Joseth, 21 ago 2026.
		//
		// Antes se leía `num_periodo` del cuerpo, que es el hueco de la §27: la
		// nivelación se abría nombrando un periodo cualquiera. Medido en el front:
		// `DefinitivasPeriodosCtrl` manda `{rf_id, nota}` y nunca `num_periodo`,
		// así que esto no cambia ninguna pantalla — cierra la puerta, no la usa
		// nadie.
		User::pueden_modificar_definitivas($user, PeriodoDeLaFila::todosLosDelAnio($user));
		
		$now 		= Carbon::now('America/Bogota');
		
		if (Request::input('rf_id')) {
			$rf_id 		= Request::input('rf_id');
			
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
			// `recuperacion_final`.
			$consulta 	= 'SELECT n.* FROM recuperacion_final n WHERE n.id=? ';

			$nota 		= DB::select($consulta, [$rf_id])[0];

			$bit_by 	= $user->user_id;
			$bit_hist 	= isset($user->historial_id) && is_numeric($user->historial_id)
				? (int) $user->historial_id
				: null;
			$bit_old 	= $nota->nota; 				// Guardo la nota antigua

			// La recuperación final es una nota como las demás y se teclea igual:
			// si no cabe en la escala, tampoco cabe aquí. Pero **esta tabla no
			// tiene `periodo_id`** —guarda `year` directo, como explica la
			// cabecera del método—, así que la escala se resuelve por el año de la
			// propia fila y no por un periodo inventado. 18 §4.5.1.
			EscalaDeNotas::comprobarEnAnio(Request::input('nota'), $nota->year === null ? null : (int) $nota->year);

			$bit_new 	= Request::input('nota'); 	// Guardo la nota nueva

			
			// **A9: el acta, en la misma escritura** (22 §9). Esta tabla ya guardaba
			// la nota de la recuperación aparte —es el único sitio del proyecto que
			// lo hacía bien— y lo que le faltaba era **cuándo, quién y con qué**: el
			// art. 16 del 1290 pide las novedades académicas, y una novedad sin
			// fecha ni responsable no es una novedad.
			//
			// **Aquí la fila ENTERA es la recuperación**, así que cada escritura es
			// el acta: no hace falta un endpoint aparte como en el indicador y la
			// definitiva, donde la fila existe antes de la nivelación y hay que
			// distinguir corregir de nivelar.
			//
			// `observacion` y `fecha` son **opcionales**: el único cliente que llama
			// hoy manda `{rf_id, nota}` (`DefinitivasPeriodosCtrl`, medido) y sigue
			// funcionando igual, con la fecha del servidor y sin observación.
			$acta = $this->actaDeLaRecuperacion($now);

			$consulta 	= 'UPDATE recuperacion_final SET nota=?, nivelada_at=?, nivelada_por=?, observacion=?,
							updated_by=?, updated_at=? WHERE id=?';
			DB::update($consulta, [ Request::input('nota'), $acta['fecha'], $user->user_id, $acta['observacion'],
				$user->user_id, $now, $rf_id ]);
			
			$consulta 	= 'INSERT INTO bitacoras (created_by, historial_id, affected_user_id, affected_person_type, affected_element_type, affected_element_id, affected_element_new_value_int, affected_element_old_value_int, created_at) 
						VALUES (?, ?, ?, "Al", "RF_UPDATE", ?, ?, ?, ?)';

			DB::insert($consulta, [$bit_by, $bit_hist, $nota->alumno_id, $rf_id, $bit_new, $bit_old, $now]);

			// El rastro nuevo, al lado del viejo (18 §4).
			//
			// `en(year: ...)` y no `periodo:`, y ésa es la única diferencia con su
			// gemela de arriba: **`recuperacion_final` no tiene `periodo_id`**,
			// guarda `year` directo —lo dice la cabecera del método y lo usa la
			// comprobación de escala tres líneas más arriba—. Pasarle el periodo
			// del profesor habría rellenado la columna con un dato que no es de
			// esta fila, que es la forma de mentira que más caro sale en una tabla
			// que se lee años después.
			$alumnoDeLaLinea = $nota->alumno_id === null ? null : (int) $nota->alumno_id;

			Auditoria::registrar()
				->editar('recuperacion_final', (int) $rf_id)
				->deAlumno($alumnoDeLaLinea, NombreDelAlumno::de($alumnoDeLaLinea))
				->en(year: $nota->year === null ? null : (int) $nota->year)
				->de($bit_old)
				->a($bit_new)
				->guardar();

			return 'Cambiada';
		}else{


			// El acta también al crear, y por la misma razón: la recuperación que se
			// registra hoy es la que alguien tendrá que justificar dentro de dos
			// años, cuando el acudiente pida la constancia del art. 17.
			$acta = $this->actaDeLaRecuperacion($now);

			$consulta = 'INSERT INTO recuperacion_final(alumno_id, asignatura_id, year, nota, nivelada_at, nivelada_por, observacion, updated_by, created_at, updated_at) 
				VALUES(:alumno_id, :asignatura_id, :year, :nota, :nivelada_at, :nivelada_por, :observacion, :updated_by, :created_at, :updated_at)';
	
			DB::insert($consulta, [':alumno_id' => Request::input('alumno_id'), ':asignatura_id' => Request::input('asignatura_id'), 
							':year' => $user->year, ':nota' => Request::input('nota'),
							':nivelada_at' => $acta['fecha'], ':nivelada_por' => $user->user_id, ':observacion' => $acta['observacion'],
							':updated_by' => $user->user_id, ':created_at' => $now, ':updated_at' => $now ]);
			
			$last_id = DB::getPdo()->lastInsertId();

			// El mismo hueco que su gemela de arriba, y por el mismo camino: la
			// recuperación final que se crea a mano no dejaba rastro en ninguna de
			// las dos tablas. `$user->year` y no un periodo: esta tabla guarda el
			// año directo.
			$alumnoDeLaLinea = (int) Request::input('alumno_id');

			Auditoria::registrar()
				->crear('recuperacion_final', (int) $last_id)
				->deAlumno($alumnoDeLaLinea, NombreDelAlumno::de($alumnoDeLaLinea))
				->en(asignatura: (int) Request::input('asignatura_id'), year: is_numeric($user->year) ? (int) $user->year : null)
				->a(Request::input('nota'))
				->guardar();

			// **Las diez columnas nombradas y no `SELECT *`**, con las tres del acta
			// dentro **a propósito** (22 §3.4): la pantalla del año (B8) las pinta.
			// Nombradas porque este método devuelve la fila al cliente, y con el
			// asterisco la siguiente columna que entre en esta tabla viajaría sola.
			return (array) DB::select(
				'SELECT id, alumno_id, asignatura_id, year, nota, nivelada_at, nivelada_por, observacion,
						updated_by, created_at, updated_at
				   FROM recuperacion_final WHERE id=?',
				[$last_id]
			)[0];
		}
		
		
	}


	
	public function getArreglarDuplicados()
	{
		$user 			= User::fromToken();
		// El mismo periodo que el método usa cuatro líneas más abajo para buscar
		// los duplicados que va a arreglar.
		User::pueden_modificar_definitivas($user, (int) Request::input('periodo_id', $user->periodo_id));
		
		$now 		= Carbon::now('America/Bogota');
		$res 		= [];
		$periodo_id = $user->periodo_id;

		if (Request::has('periodo_id')) {
			$periodo_id 		= Request::input('periodo_id');
		}
		
		
		$consulta = 'SELECT id FROM grupos g WHERE g.year_id=? and g.deleted_at is null';
		$grupos = DB::select($consulta, [$user->year_id]);
		//Log::info('$user->year_id '.$user->year_id . ' - '.$periodo_id);

		for ($i=0; $i < count($grupos); $i++) { 
			$grupo = $grupos[$i];


			// **Una columna, no las veintiocho.** De la fila de `matriculas` este
			// método usa **sólo `alumno_id`** —cuatro bucles más abajo, en la
			// consulta de `notas_finales`— y el `SELECT *` traía las 28 por cada
			// alumno de cada grupo del año.
			//
			// Vino por el aviso de que `matriculas.boletin_independiente` (fase 1
			// del boletín independiente, columna **retirada el 31 ago 2026**) se
			// filtraría por aquí. **Comprobado: por aquí NO se filtra** — el método devuelve `$res`, que son conteos de
			// `DELETE`, y estas filas no salen nunca hacia el cliente. Así que el
			// motivo bueno no es el contrato: es que **pedir 28 columnas para leer
			// una, dentro de un bucle sobre todos los grupos del año, es trabajo
			// que no hace falta**.
			//
			// Y con la columna nombrada la otra preocupación se cierra igual y para
			// siempre: **volver a `*` la reabre**, así que esto no es estilo.
			$consulta = 'SELECT m.alumno_id FROM matriculas m WHERE m.grupo_id=? and m.deleted_at is null';
			$alumnos = DB::select($consulta, [$grupo->id]);
			$canti_alum = count($alumnos);

			for ($j=0; $j < $canti_alum; $j++) { 
				$alumno = $alumnos[$j];


				$consulta = 'SELECT * FROM asignaturas a WHERE a.grupo_id=? and a.deleted_at is null';
				$asignaturas = DB::select($consulta, [$grupo->id]);
				$canti_asig = count($asignaturas);


				for ($k=0; $k < $canti_asig; $k++) { 
					$asignatura = $asignaturas[$k];


					$consulta = 'SELECT n.id FROM notas_finales n 
						WHERE n.asignatura_id=? and alumno_id=? and  n.periodo_id=? and n.manual=1
						order by n.id ';
					$nota_ult = DB::select($consulta, [$asignatura->id, $alumno->alumno_id, $periodo_id]);
					//Log::info('$asignatura->id ' . $asignatura->id . ' - ' . $alumno->alumno_id. ' - ' .$periodo_id);

					if (count($nota_ult) > 1) {
						$nota_ult = $nota_ult[count($nota_ult)-1];
						//Log::info('mayor ' . $nota_ult->id);
						
						$consulta = 'DELETE FROM notas_finales
							WHERE asignatura_id=? and alumno_id=? and  periodo_id=? and id!=?';
						$nota_elim = DB::delete($consulta, [$asignatura->id, $alumno->alumno_id, $periodo_id, $nota_ult->id]);
						array_push($res, $nota_elim);
					}else{
						//Log::info('MENOR ');
					}


				}
			}

		}
		
		return $res;
	}


	public function putToggleRecuperada()
	{
		$user 			= User::fromToken();
		User::pueden_modificar_definitivas($user, PeriodoDeLaFila::deNotaFinal(Request::input('nf_id')));
		
		if ($user->tipo == 'Profesor' || ($user->is_superuser)) {
			// No pasa nada
		}else{
			return abort(403, 'No tienes privilegios.');
		}
		$now 		= Carbon::now('America/Bogota');
		$recu 		= Request::input('recuperada');
		
		if ($recu) {
			$consulta 	= 'UPDATE notas_finales SET recuperada=?, manual=?, updated_by=?, updated_at=? WHERE id=?';
			DB::update($consulta, [ $recu, true, $user->user_id, $now, Request::input('nf_id') ]);
		}else{
			$consulta 	= 'UPDATE notas_finales SET recuperada=?, updated_by=?, updated_at=? WHERE id=?';
			DB::update($consulta, [ $recu, $user->user_id, $now, Request::input('nf_id') ]);
		}
		
		return 'Cambiada';
	}


	public function putEliminarRecuperada()
	{
		$user 			= User::fromToken();
		// La otra de `recuperacion_final`, del año y no de un periodo. Ver la de
		// `putUpdateRecuperacion` más arriba.
		User::pueden_modificar_definitivas($user, PeriodoDeLaFila::todosLosDelAnio($user));
		
		if ($user->tipo == 'Profesor' || ($user->is_superuser)) {
			// No pasa nada
		}else{
			return abort(403, 'No tienes privilegios.');
		}
		
		$consulta 	= 'DELETE FROM recuperacion_final WHERE id=?';
		DB::update($consulta, [ Request::input('rf_id') ]);

		
		return 'Eliminada';
	}



	public function putToggleManual()
	{
		$user 			= User::fromToken();
		User::pueden_modificar_definitivas($user, PeriodoDeLaFila::deNotaFinal(Request::input('nf_id')));
		
		if ($user->tipo == 'Profesor' || ($user->is_superuser)) {
			// No pasa nada
		}else{
			return abort(403, 'No tienes privilegios.');
		}
		$now 		= Carbon::now('America/Bogota');
		$manual 	= Request::input('manual');
		if ($manual){
			$consulta 	= 'UPDATE notas_finales SET manual=?, updated_by=?, updated_at=? WHERE id=?';
			DB::update($consulta, [ $manual, $user->user_id, $now, Request::input('nf_id') ]);
		}else{
			$consulta 	= 'UPDATE notas_finales SET manual=?, recuperada=?, updated_by=?, updated_at=? WHERE id=?';
			DB::update($consulta, [ $manual, false, $user->user_id, $now, Request::input('nf_id') ]);
		}
		
		return 'Cambiada';
	}


	public function deleteDestroy($id)
	{
		$user 	= User::fromToken();
		User::pueden_modificar_definitivas($user, PeriodoDeLaFila::deNotaFinal($id));
		$consulta 	= 'DELETE FROM notas_finales WHERE id=?';
		DB::delete($consulta, [$id]);

		return 'Eliminada';
	}


}

