<?php namespace App\Http\Controllers\Disciplina;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

use App\User;
use App\Models\NotaComportamiento;
use App\Services\Auditoria;
use App\Models\Grupo;
use App\Models\Alumno;
use App\Models\Frase;

use Carbon\Carbon;
use App\Support\NombreDelAlumno;


class DisciplinaController extends Controller {
	
	
	private $consulta_alumno = 'SELECT m.id as matricula_id, m.alumno_id, a.no_matricula, a.nombres, a.apellidos, a.sexo, a.user_id, 
			a.fecha_nac, a.ciudad_nac, a.celular, a.direccion, a.religion, t.tipo as tipo_doc, t.abrev as tipo_doc_abrev, a.documento, a.no_matricula, 
			m.grupo_id, m.estado, m.nuevo, m.repitente, username,
			u.imagen_id, IFNULL(i.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as imagen_nombre, 
			a.foto_id, IFNULL(i2.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as foto_nombre
		FROM alumnos a 
		inner join matriculas m on a.id=m.alumno_id and m.deleted_at is null
		left join users u on a.user_id=u.id and u.deleted_at is null
		left join tipos_documentos t on a.tipo_doc=t.id and t.deleted_at is null
		left join images i on i.id=u.imagen_id and i.deleted_at is null
		left join images i2 on i2.id=a.foto_id and i2.deleted_at is null
		where a.id=? and a.deleted_at is null';



	/**
	 * La ficha con la que estas tres rutas repintan la pantalla del observador.
	 *
	 * El `[0]` de las tres estaba desnudo: con un `alumno_id` del cuerpo que no
	 * existe —o que existe y no tiene matrícula viva, porque la consulta lleva un
	 * INNER JOIN a `matriculas`— salía «Undefined array key 0», o sea **500 donde
	 * tocaba 404** (05 §52, §86). Y lo caro no era el código: para cuando revienta,
	 * el UPDATE del proceso disciplinario YA SE HIZO, así que el front recibe un
	 * error sobre una escritura que sí ocurrió y vuelve a mandarla. Medido en
	 * DisciplinaUpdateTest.
	 */
	private function fichaDelAlumno($alumno_id)
	{
		$alumno = DB::select($this->consulta_alumno, [$alumno_id]);

		if (count($alumno) === 0) {
			abort(404, 'No existe la ficha del alumno pedido.');
		}

		return $alumno[0];
	}


	public function putAlumnos()
	{
		$user 		= User::fromToken();
		$now 		= Carbon::now('America/Bogota');
		$grupo_id 	= Request::input('grupo_id');
		$year_id 	= Request::input('year_id', $user->year_id);
		
		$cons_periodos 	= 'SELECT id, numero FROM periodos WHERE year_id=? and deleted_at is null';
		$periodos 		= DB::select($cons_periodos, [$year_id]);
		
		$alumnos = Grupo::alumnos($grupo_id);
		
		$cant_al = count($alumnos);
		
		for ($i=0; $i < $cant_al; $i++) {
			
			$this->datosAlumno($alumnos[$i], $year_id, $periodos);
			
		}

		return ['alumnos' => $alumnos];
	}


	/**
	 * La ficha de disciplina de un alumno, para **él y para su familia**.
	 *
	 * Lo pide la app. Hasta hoy la pantalla de disciplina existía y funcionaba
	 * para el personal, y el alumno y el acudiente no entraban: los cuatro
	 * controladores que tocan `dis_procesos` llevan `auth.personal` en **todas**
	 * sus rutas, que aborta con 403 a `Alumno` y `Acudiente`. No era pudor, era
	 * que nadie había escrito la puerta de lectura.
	 *
	 * ## La guarda ya existía y hace exactamente esto
	 *
	 * `boletin.propio:sin-paz-y-salvo`, no `auth.personal`. `ExigirBoletinPropio`
	 * deja pasar de largo a quien no es alumno ni acudiente, y a los que lo son
	 * les comprueba que el `alumno_id` pedido sea el suyo o el de un acudido.
	 *
	 * **El modo `sin-paz-y-salvo` es el correcto y merece la frase**: retener el
	 * boletín de quien debe es una cosa, y esconderle a una familia la situación
	 * disciplinaria de su hijo es otra, y esa nadie la ha pedido. Es la misma
	 * decisión que ya se tomó para `notas/alumno` y para `matriculas/prematricular`.
	 *
	 * **Sin id significa «lo mío»**, y eso lo resuelve este método y no la guarda:
	 * el middleware, al no ver alumno concreto, deja pasar —no hay nada que
	 * proteger todavía—. Aquí se traduce a `persona_id` para un `Alumno` y se
	 * responde 400 a los demás, que es letra por letra lo que hace
	 * `NotasController::getAlumno`. Un acudiente **tiene** que decir de cuál de
	 * sus acudidos habla.
	 *
	 * ## La forma es la de `PUT disciplina/alumnos`, y es el punto entero
	 *
	 * `alumno` sale con las mismas claves que un elemento de aquella respuesta:
	 * sus `periodoN[]` con sus `proceso_ordinales`, sus `uniformes_perN[]`, sus
	 * `tardanzas_perN[]` y sus contadores `perN_cant_tN`. **No es comodidad**: así
	 * la app reutiliza `AlumnoDisciplinaModel` y `FichaDisciplinaScreen` tal cual,
	 * en modo lectura, y esa pantalla ya está escrita y probada. Con otra forma
	 * habría que escribir un modelo nuevo y una pantalla nueva para enseñar lo
	 * mismo. Hay un test que compara las dos respuestas **clave a clave**, porque
	 * es lo único que sostiene esa promesa cuando alguien toque cualquiera de las
	 * dos.
	 *
	 * `config` y `ordinales` van porque la ficha los necesita para pintar: los
	 * tres tipos se llaman como los llame el colegio (`falta_tipoN_displayname`) y
	 * los ordinales de cada situación se resuelven contra el catálogo del año.
	 * **No van `grupos` ni `descripciones_typeahead`**: eso es del editor, y aquí
	 * no se escribe nada.
	 *
	 * ## Y aquí NO se crea la configuración si falta
	 *
	 * Sus dos hermanas —`grupos/con-disciplina` y `ordinales/ordinales`— insertan
	 * una fila en `dis_configuraciones` cuando el año no la tiene. Aquí no, y es
	 * deliberado: **esta ruta la abre una familia**, y una lectura que escribe es
	 * la forma más silenciosa de que un endpoint de sólo lectura deje de serlo.
	 * Sin fila se devuelve `config: null` y el cliente usa sus valores por
	 * defecto, que es lo que ya hace hoy cuando la respuesta no la trae.
	 */
	public function getMisFichas($alumno_id = '')
	{
		$user = User::fromToken();

		if ($alumno_id === '' || $alumno_id === null) {
			if ($user->tipo == 'Alumno') {
				$alumno_id = $user->persona_id;
			}else{
				return abort(400, 'No hay id de alumno');
			}
		}

		// **El año sale del alumno, no de quien pregunta**, y esto no es un detalle
		// de estilo: es lo único que hace que este endpoint sirva para un
		// acudiente. Ver `anoDeLaFicha()`.
		$year_id = $this->anoDeLaFicha((int)$alumno_id, $user);

		$alumno = $this->fichaConFormaDeGrupo((int)$alumno_id, (int)$year_id);

		$this->datosAlumno($alumno, $year_id);

		// `SELECT c.*` y no una lista de columnas, igual que sus hermanas: el
		// colegio renombra los tres tipos desde su pantalla de configuración y la
		// ficha pinta lo que diga esa fila.
		$config = DB::select(
			'SELECT c.* FROM dis_configuraciones c WHERE c.year_id=? and c.deleted_at is null',
			[$year_id]
		);

		$ordinales = DB::select(
			'SELECT c.* FROM dis_ordinales c WHERE c.year_id=? and c.deleted_at is null',
			[$year_id]
		);

		return [
			'alumno' => (array)$alumno,
			'config' => $config[0] ?? null,
			'ordinales' => $ordinales,
		];
	}


	/**
	 * De qué año es la ficha: **del alumno, no de quien la pide**.
	 *
	 * Lo natural es `$user->year_id`, y era lo que ponía aquí. Falla en un caso
	 * concreto y frecuente: **el colegio pasa de año y la familia no ha vuelto a
	 * entrar.** `users.periodo_id` sigue apuntando al año viejo,
	 * `ContextoDeUsuario` lo lee **en cada petición**, y con él la ficha se
	 * buscaba en un año donde el alumno no tiene matrícula: **404 sobre una ficha
	 * que existe**, y justo cuando la familia abre la app a ver el curso nuevo.
	 *
	 * `Login::ponerEnElPeriodoActual()` lo repara, pero **sólo al entrar**, y no
	 * hay nada que lo mueva con la sesión abierta. Vale para los cuatro tipos: no
	 * es un problema de los acudientes, es de cualquier sesión que sobreviva al
	 * cambio de año.
	 *
	 * > **Cuidado con el test que lo fija.** La primera versión pedía el token
	 * > *después* de mover el periodo, así que el login lo reparaba y el caso
	 * > pasaba en verde **con el fallo dentro**. Hay que tomar el token primero.
	 *
	 * Y hay un problema mayor detrás que **esto no resuelve ni pretende**:
	 * `09-pendientes.md` §8 lleva medidos sobre producción los mil acudientes con
	 * `periodo_id = 1` —el periodo 1 de 2018— y espera una decisión de Joseth,
	 * porque toca boletines, notas y definitivas. Aquí sólo se hace que la ficha
	 * **no dependa de eso**.
	 *
	 * No es un parche, además: **la ficha es del alumno, así que su año es el del
	 * alumno.** Para quien tiene el `year_id` bien —que es el caso normal— da
	 * exactamente lo mismo.
	 *
	 * Se prefiere el año **activo** si el alumno tiene matrícula viva en él, y si
	 * no, el más reciente — que es lo que hace que la ficha de un egresado siga
	 * saliendo en vez de dar 404.
	 */
	private function anoDeLaFicha(int $alumno_id, $user)
	{
		$fila = DB::selectOne(
			'SELECT g.year_id
			   FROM matriculas m
			   INNER JOIN grupos g ON g.id = m.grupo_id AND g.deleted_at IS NULL
			   INNER JOIN years y ON y.id = g.year_id AND y.deleted_at IS NULL
			  WHERE m.alumno_id = ? AND m.deleted_at IS NULL
			    AND (m.estado = "MATR" OR m.estado = "ASIS" OR m.estado = "PREM")
			  ORDER BY y.actual DESC, y.year DESC
			  LIMIT 1',
			[$alumno_id]
		);

		// Sin matrícula viva no hay año del que tirar, y se vuelve al del usuario:
		// `fichaConFormaDeGrupo` responderá 404, que es la respuesta correcta y la
		// que ya tiene su test.
		return $fila->year_id ?? $user->year_id;
	}


	/**
	 * La ficha de un alumno **con las columnas de `Grupo::alumnos`**.
	 *
	 * Existe porque las dos consultas de este repo que devuelven un alumno para la
	 * pantalla de disciplina **no traen lo mismo**: `Grupo::alumnos` —la que usa
	 * `putAlumnos`— añade `nro_folio`, `nee`, `nee_descripcion`, `promovido`,
	 * `promedio`, `cant_asign_perdidas` y `cant_areas_perdidas`, y
	 * `$consulta_alumno` —la de `fichaDelAlumno`, que usan las tres escrituras— no.
	 *
	 * Reusar cualquiera de las dos habría sido más corto y habría roto la promesa:
	 * el contrato con la app es que esto venga **igual que un elemento de
	 * `PUT disciplina/alumnos`**, y `fichaDelAlumno` se queda a siete columnas. Se
	 * copia la lista de `Grupo::alumnos` en vez de llamarla porque aquélla parte
	 * del grupo y aquí se parte del alumno.
	 *
	 * **El año hace falta y no sobra**: un alumno tiene una matrícula por año, y
	 * sin filtrar saldrían varias filas y la ficha sería la del año que MySQL
	 * quisiera devolver primero. Se filtra por el año del grupo, que es donde vive.
	 *
	 * 404 y no 500 cuando no hay ficha, que es la lección de la §52: el `[0]`
	 * desnudo de las tres escrituras daba «Undefined array key 0» con un
	 * `alumno_id` sin matrícula viva.
	 */
	private function fichaConFormaDeGrupo(int $alumno_id, int $year_id)
	{
		$consulta = 'SELECT m.id as matricula_id, m.alumno_id, a.no_matricula, m.nro_folio, a.nombres, a.apellidos, a.sexo, a.user_id, a.nee, a.nee_descripcion,
						a.fecha_nac, a.ciudad_nac, a.celular, a.direccion, a.religion, t.tipo as tipo_doc, t.abrev as tipo_doc_abrev, a.documento, a.no_matricula,
						m.grupo_id, m.estado, m.nuevo, m.repitente, username, m.promovido, m.promedio, m.cant_asign_perdidas, m.cant_areas_perdidas,
						u.imagen_id, IFNULL(i.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as imagen_nombre,
						a.foto_id, IFNULL(i2.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as foto_nombre
					FROM alumnos a
					inner join matriculas m on a.id=m.alumno_id and (m.estado="MATR" or m.estado="ASIS" or m.estado="PREM") and m.deleted_at is null
					inner join grupos g on g.id=m.grupo_id and g.year_id=? and g.deleted_at is null
					left join users u on a.user_id=u.id and u.deleted_at is null
					left join tipos_documentos t on a.tipo_doc=t.id and t.deleted_at is null
					left join images i on i.id=u.imagen_id and i.deleted_at is null
					left join images i2 on i2.id=a.foto_id and i2.deleted_at is null
					where a.id=? and a.deleted_at is null
					order by m.id desc';

		$alumno = DB::select($consulta, [$year_id, $alumno_id]);

		if (count($alumno) === 0) {
			abort(404, 'No existe la ficha del alumno pedido.');
		}

		return $alumno[0];
	}
	
	
	
	public function datosAlumno(&$alumno, $year_id, $periodos=false){
		
		if ($periodos==false) {
			
			$cons_periodos 	= 'SELECT id, numero FROM periodos WHERE year_id=? and deleted_at is null';
			$periodos 		= DB::select($cons_periodos, [$year_id]);
			
		}



		for ($j=0; $j < count($periodos); $j++) { 

			// Traigo tardanzas al colegio
			$consulta 	= 'SELECT a.* FROM ausencias a WHERE a.alumno_id=? and a.periodo_id=? and a.entrada=1 and (a.cantidad_tardanza>0 or a.cantidad_tardanza is null) and a.deleted_at is null';
			$tardanzas 	= DB::select($consulta, [ $alumno->alumno_id, $periodos[$j]->id ]);
			$name 		= 'tardanzas_per' . $periodos[$j]->numero;
			$alumno->{$name} = $tardanzas;


			// Traigo fallas de uniformes
			$consulta 	= 'SELECT a.* FROM uniformes a WHERE a.alumno_id=? and a.periodo_id=? and a.deleted_at is null';
			$uniformes 	= DB::select($consulta, [ $alumno->alumno_id, $periodos[$j]->id ]);
			$name 		= 'uniformes_per' . $periodos[$j]->numero;
			$alumno->{$name} = $uniformes;


			// Traido los procesos
			$consulta 	= 'SELECT d.*, SUBSTRING(d.fecha_hora_aprox, 1, 10) as fecha_corta, CONCAT(p.nombres, " ", p.apellidos) as profesor_nombre,
					? as periodo_numero
				FROM dis_procesos d 
				LEFT JOIN profesores p ON p.id=d.profesor_id and p.deleted_at is null
				WHERE alumno_id=? and d.periodo_id=? and d.deleted_at is null';

			$procesos 	= DB::select($consulta, [ $periodos[$j]->numero, $alumno->alumno_id, $periodos[$j]->id ]);

			for ($k=0; $k < count($procesos); $k++) { 
				$consulta 	= 'SELECT d.* FROM dis_proceso_ordinales d WHERE proceso_id=? and d.deleted_at is null';
				$procesos[$k]->proceso_ordinales 	= DB::select($consulta, [ $procesos[$k]->id ]);
			}
			$name 		= 'periodo' . $periodos[$j]->numero;
			$alumno->{$name} = $procesos;
			
			if ($periodos[$j]->numero == 1) {
				$alumno->per1_cant_t1 = 0;
				$alumno->per1_cant_t2 = 0;
				$alumno->per1_cant_t3 = 0;
				
				for ($k=0; $k < count($procesos); $k++) { 
					if ($procesos[$k]->tipo_situacion == 1 && $procesos[$k]->become_id == null) {
						$alumno->per1_cant_t1++;
					}elseif($procesos[$k]->tipo_situacion == 2 && $procesos[$k]->become_id == null) {
						$alumno->per1_cant_t2++;
					}elseif($procesos[$k]->tipo_situacion == 3 && $procesos[$k]->become_id == null) {
						$alumno->per1_cant_t3++;
					}
				}
			}
			
			if ($periodos[$j]->numero == 2) {
				$alumno->per2_cant_t1 = 0;
				$alumno->per2_cant_t2 = 0;
				$alumno->per2_cant_t3 = 0;
				
				for ($k=0; $k < count($procesos); $k++) { 
					if ($procesos[$k]->tipo_situacion == 1 && $procesos[$k]->become_id == null) {
						$alumno->per2_cant_t1++;
					}elseif($procesos[$k]->tipo_situacion == 2 && $procesos[$k]->become_id == null) {
						$alumno->per2_cant_t2++;
					}elseif($procesos[$k]->tipo_situacion == 3 && $procesos[$k]->become_id == null) {
						$alumno->per2_cant_t3++;
					}
				}
			}
			
			if ($periodos[$j]->numero == 3) {
				$alumno->per3_cant_t1 = 0;
				$alumno->per3_cant_t2 = 0;
				$alumno->per3_cant_t3 = 0;
				
				for ($k=0; $k < count($procesos); $k++) { 
					if ($procesos[$k]->tipo_situacion == 1 && $procesos[$k]->become_id == null) {
						$alumno->per3_cant_t1++;
					}elseif($procesos[$k]->tipo_situacion == 2 && $procesos[$k]->become_id == null) {
						$alumno->per3_cant_t2++;
					}elseif($procesos[$k]->tipo_situacion == 3 && $procesos[$k]->become_id == null) {
						$alumno->per3_cant_t3++;
					}
				}
			}
			
			if ($periodos[$j]->numero == 4) {
				$alumno->per4_cant_t1 = 0;
				$alumno->per4_cant_t2 = 0;
				$alumno->per4_cant_t3 = 0;
				
				for ($k=0; $k < count($procesos); $k++) { 
					if ($procesos[$k]->tipo_situacion == 1 && $procesos[$k]->become_id == null) {
						$alumno->per4_cant_t1++;
					}elseif($procesos[$k]->tipo_situacion == 2 && $procesos[$k]->become_id == null) {
						$alumno->per4_cant_t2++;
					}elseif($procesos[$k]->tipo_situacion == 3 && $procesos[$k]->become_id == null) {
						$alumno->per4_cant_t3++;
					}
				}
			}
			
		}
		
	}

	

	
	public function postStore()
	{
		$user 	        		= User::fromToken();
		$now 					= Carbon::now('America/Bogota');
		$year_id     			= Request::input('year_id');
		$alumno_id     			= Request::input('alumno_id');
		$periodo_id     		= Request::input('periodo_id');
		$descripcion    		= Request::input('descripcion');
		$testigos    			= Request::input('testigos');
		$descargo    			= Request::input('descargo');
		$tipo_situacion 		= Request::input('tipo_situacion');
		$profesor_id 			= Request::input('profesor') ? Request::input('profesor')['profesor_id'] : null;
		$fecha_hora_aprox 		= Request::input('fecha_hora_aprox');
		$deriva_de_tardanzas 	= Request::input('deriva_de_tardanzas', 0);
		$dependencias 			= Request::input('dependencias');
		
		$depe_t1 	= 0;
		$depe_t2 	= 0;
		

		if ($fecha_hora_aprox) {
			$fecha_hora_aprox 	= Carbon::parse($fecha_hora_aprox);
		}

		
		// Inserto el proceso
		$consulta = 'INSERT INTO dis_procesos(year_id, alumno_id, periodo_id, fecha_hora_aprox, descripcion, testigos, descargo, 
			tipo_situacion, profesor_id, deriva_de_tardanzas, created_at, updated_at, added_by) 
			VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)';
			
		$datos = [ $year_id, $alumno_id, $periodo_id, $fecha_hora_aprox, $descripcion, $testigos, $descargo, $tipo_situacion, $profesor_id, $deriva_de_tardanzas, $now, $now, $user->user_id ];
		
		DB::insert($consulta, $datos);
		
		// Traemos el proceso
		$last_id = DB::getPdo()->lastInsertId();

		/*
		 * El alta del proceso disciplinario, que hasta hoy no dejaba rastro de
		 * ninguna clase — ni en `bitacoras` ni en ninguna otra parte.
		 *
		 * **Los ordinales van dentro de `valor_nuevo` y no como líneas propias**, y
		 * es una decisión, no un atajo: `dis_proceso_ordinales` **no está en
		 * `Auditoria::ENTIDADES`**. Añadir un nombre a esa constante es editar el
		 * vocabulario cerrado del servicio, y eso es una decisión que no abre un
		 * lote solo (18 §2.3: el vocabulario se cierra en el servicio justamente
		 * para que ampliarlo sea un acto y no un `string` suelto). Queda anotado en
		 * aud-4 §5.
		 *
		 * Y para la pantalla no se pierde nada: los ordinales de un proceso son
		 * parte de lo que ese proceso es, no filas que alguien consulte por su
		 * cuenta.
		 */
		$alumnoDeLaLinea = is_numeric($alumno_id) ? (int) $alumno_id : null;

		Auditoria::registrar()
			->crear('dis_proceso', (int) $last_id)
			->deAlumno($alumnoDeLaLinea, NombreDelAlumno::de($alumnoDeLaLinea))
			->en(periodo: is_numeric($periodo_id) ? (int) $periodo_id : null,
				year: is_numeric($year_id) ? (int) $year_id : null)
			->a([
				'descripcion' => $descripcion,
				'tipo_situacion' => $tipo_situacion,
				'testigos' => $testigos,
				'descargo' => $descargo,
				'profesor_id' => $profesor_id,
				'fecha_hora_aprox' => $fecha_hora_aprox instanceof Carbon ? $fecha_hora_aprox->toDateTimeString() : null,
				'deriva_de_tardanzas' => $deriva_de_tardanzas,
				'ordinales' => array_map(
					fn ($o) => $o['id'] ?? null,
					is_array(Request::input('selected_ordinales')) ? Request::input('selected_ordinales') : []
				),
			])
			->guardar();
		
		
		// Insertamos cada ordinal
		$selected_ordinales = Request::input('selected_ordinales');
		if (is_array($selected_ordinales)) {
			for ($i=0; $i < count($selected_ordinales); $i++) { 
				$consulta = 'INSERT INTO dis_proceso_ordinales(ordinal_id, proceso_id, added_by, created_at, updated_at) VALUES(?,?,?,?,?)';
				DB::insert($consulta, [ $selected_ordinales[$i]['id'], $last_id, $user->user_id, $now, $now ]);
				
			}
		}
		
		
		// Modificamos las faltas de las que depende de este proceso
		if (is_array($dependencias)) {
			for ($i=0; $i < count($dependencias); $i++) { 
				// **Cada dependencia es OTRA falta**, y esta línea cuelga de ella y no
				// del proceso nuevo. Es la diferencia entera: al ver el historial de
				// la falta de octubre hay que poder leer que en noviembre se la
				// declaró derivante de otra — mirando el proceso nuevo eso no se ve.
				//
				// Lo señaló el detector, no la lectura: `postStore` salió como «audita
				// menos veces de las que escribe», que es la pregunta que una lista de
				// métodos no contesta.
				$antesDep = $this->fotoDelProceso($dependencias[$i]['id']);

				$consulta = 'UPDATE dis_procesos SET become_id=? WHERE id=?';
				DB::update($consulta, [ $last_id, $dependencias[$i]['id'] ]);

				$alumnoDeLaLinea = isset($antesDep['alumno_id']) && is_numeric($antesDep['alumno_id']) ? (int) $antesDep['alumno_id'] : null;

				Auditoria::registrar()
					->editar('dis_proceso', is_numeric($dependencias[$i]['id']) ? (int) $dependencias[$i]['id'] : null)
					->deAlumno($alumnoDeLaLinea, NombreDelAlumno::de($alumnoDeLaLinea))
					->en(periodo: isset($antesDep['periodo_id']) && is_numeric($antesDep['periodo_id']) ? (int) $antesDep['periodo_id'] : null)
					->de(['become_id' => $antesDep['become_id'] ?? null])
					->a(['become_id' => $last_id])
					->resumen('Esta falta pasó a derivar en la '.$last_id)
					->guardar();
			}
		}
		
		
		
		
		$alumno 	= $this->fichaDelAlumno($alumno_id);

		$this->datosAlumno($alumno, $year_id);
		
		return (array)$alumno;
	}



	/**
	 * La foto de un proceso disciplinario, para poder decir «de qué a qué».
	 *
	 * Una consulta por clave primaria, y sólo las columnas que un proceso ES: la
	 * descripción, la situación, el testigo, el descargo y de qué falta deriva.
	 * No se trae la ficha del alumno ni los ordinales — eso es lo que hace que la
	 * línea siga leyéndose cuando esas filas ya no existan (18 §2.4).
	 */
	private function fotoDelProceso($procesoId): ?array
	{
		$fila = DB::selectOne(
			'SELECT alumno_id, periodo_id, year_id, descripcion, tipo_situacion,
					testigos, descargo, profesor_id, fecha_hora_aprox, become_id
			   FROM dis_procesos WHERE id = ?',
			[$procesoId]
		);

		return $fila === null ? null : (array) $fila;
	}

	public function putCambiarSituacionDerivante()
	{
		$user 	        	= User::fromToken();
		$now 				= Carbon::now('America/Bogota');
		
		$id     			= Request::input('id');
		$become_id     		= Request::input('become_id');


		// **Esta ruta no escribe ni `updated_by` ni `updated_at`** —lo dice el
		// comentario de al lado, y es la decisión de quien lo escribió—, así que
		// hasta hoy cambiar de qué falta deriva una situación **no dejaba ni
		// siquiera un autor en la propia fila**. La línea de auditoría es todo el
		// rastro que hay, y por eso aquí importa más que en sus vecinas.
		$antes = $this->fotoDelProceso($id);

		$consulta 	= 'UPDATE dis_procesos SET become_id=? WHERE id=?'; // No creo que sea chévere poner la fecha y modificador
		$datos 		= [ $become_id, $id ];
		
		DB::update($consulta, $datos);

		$alumnoDeLaLinea = isset($antes['alumno_id']) && is_numeric($antes['alumno_id']) ? (int) $antes['alumno_id'] : null;

		Auditoria::registrar()
			->editar('dis_proceso', is_numeric($id) ? (int) $id : null)
			->deAlumno($alumnoDeLaLinea, NombreDelAlumno::de($alumnoDeLaLinea))
			->en(periodo: isset($antes['periodo_id']) && is_numeric($antes['periodo_id']) ? (int) $antes['periodo_id'] : null)
			->de(['become_id' => $antes['become_id'] ?? null])
			->a(['become_id' => $become_id])
			->resumen('Cambió de qué situación deriva la falta '.$id)
			->guardar();
		
		return 'Guardado';
	}
	
	
	public function putUpdate()
	{
		$user 	        	= User::fromToken();
		$now 				= Carbon::now('America/Bogota');
		
		$alumno_id     		= Request::input('alumno_id');
		$proceso_id     	= Request::input('id');
		$year_id     		= Request::input('year_id', $user->year_id);
		$descripcion 		= Request::input('descripcion');
		$tipo_situacion 	= Request::input('tipo_situacion');
		$ordinales 			= Request::input('proceso_ordinales');
		$dependencias 		= Request::input('dependencias');
		$profesor 			= Request::input('profesor');
		$fecha_hora_aprox 	= Request::input('fecha_hora_aprox');
		$testigos 			= Request::input('testigos');
		$descargo 			= Request::input('descargo');
		
		if ($fecha_hora_aprox) {
			$fecha_hora_aprox 	= Carbon::parse($fecha_hora_aprox);
		}
		
		if ($profesor) {
			$profesor = $profesor['profesor_id'];
		}
		

		// La foto de antes, leída **antes** del UPDATE. Es la única forma de que la
		// línea diga de qué a qué: la descripción y el descargo de un proceso
		// disciplinario son texto escrito a mano, y reescribirlos es exactamente lo
		// que un acudiente puede reclamar.
		$antes = $this->fotoDelProceso($proceso_id);

		$consulta = 'UPDATE dis_procesos SET descripcion=?, tipo_situacion=?,
			profesor_id=?, fecha_hora_aprox=?, testigos=?, descargo=?, updated_by=?, updated_at=? WHERE id=?';
		
		$datos 		= [ $descripcion, $tipo_situacion, $profesor, $fecha_hora_aprox, $testigos, $descargo, $user->user_id, $now, $proceso_id ];
		DB::update($consulta, $datos);

		$alumnoDeLaLinea = is_numeric($alumno_id) ? (int) $alumno_id : null;

		Auditoria::registrar()
			->editar('dis_proceso', is_numeric($proceso_id) ? (int) $proceso_id : null)
			->deAlumno($alumnoDeLaLinea, NombreDelAlumno::de($alumnoDeLaLinea))
			->en(periodo: isset($antes['periodo_id']) && is_numeric($antes['periodo_id']) ? (int) $antes['periodo_id'] : null,
				year: is_numeric($year_id) ? (int) $year_id : null)
			->de($antes === null ? null : [
				'descripcion' => $antes['descripcion'],
				'tipo_situacion' => $antes['tipo_situacion'],
				'testigos' => $antes['testigos'],
				'descargo' => $antes['descargo'],
				'profesor_id' => $antes['profesor_id'],
				'fecha_hora_aprox' => $antes['fecha_hora_aprox'],
			])
			->a([
				'descripcion' => $descripcion,
				'tipo_situacion' => $tipo_situacion,
				'testigos' => $testigos,
				'descargo' => $descargo,
				'profesor_id' => $profesor,
				'fecha_hora_aprox' => $fecha_hora_aprox instanceof Carbon ? $fecha_hora_aprox->toDateTimeString() : null,
			])
			->guardar();
		
		// Modificamos los procesos que llevaron a esta falta.
		// El `is_array` no es defensa por si acaso: `dependencias` es opcional en el
		// cuerpo, y sin él `count(null)` es un TypeError en PHP 8 — 500 con el UPDATE
		// de arriba ya escrito. Su hermana `postStore` sí lo preguntaba desde siempre,
		// y esa asimetría es la que lo escondió. Ver 05 §86.
		$dependencias = is_array($dependencias) ? $dependencias : [];

		for ($i=0; $i < count($dependencias); $i++) { 

			if (array_key_exists('asignado', $dependencias[$i])) {
				
				$consulta 	= 'UPDATE dis_procesos SET become_id=? WHERE id=?'; // No creo que sea chévere poner la fecha y modificador
				$datos 		= [ $proceso_id, $dependencias[$i]['id'] ];
				
			}else{
				
				$consulta 	= 'UPDATE dis_procesos SET become_id=null WHERE id=?'; // No creo que sea chévere poner la fecha y modificador
				$datos 		= [ $dependencias[$i]['id'] ];
				
			}

			// El mismo caso que en `postStore`, y aquí con las dos direcciones: la
			// rama de arriba ata esta falta al proceso y la de abajo **la desata**.
			// Desatarla es la que no deja ningún otro rastro —esta ruta no escribe
			// ni `updated_by`— y es la que borra una relación entre dos faltas
			// disciplinarias de un menor.
			$antesDep = $this->fotoDelProceso($dependencias[$i]['id']);
			$nuevoBecome = array_key_exists('asignado', $dependencias[$i]) ? $proceso_id : null;

			DB::update($consulta, $datos);

			$alumnoDeLaLinea = isset($antesDep['alumno_id']) && is_numeric($antesDep['alumno_id']) ? (int) $antesDep['alumno_id'] : null;

			Auditoria::registrar()
				->editar('dis_proceso', is_numeric($dependencias[$i]['id']) ? (int) $dependencias[$i]['id'] : null)
				->deAlumno($alumnoDeLaLinea, NombreDelAlumno::de($alumnoDeLaLinea))
				->en(periodo: isset($antesDep['periodo_id']) && is_numeric($antesDep['periodo_id']) ? (int) $antesDep['periodo_id'] : null)
				->de(['become_id' => $antesDep['become_id'] ?? null])
				->a(['become_id' => $nuevoBecome])
				->resumen($nuevoBecome === null
					? 'Esta falta dejó de derivar en ninguna otra'
					: 'Esta falta pasó a derivar en la '.$nuevoBecome)
				->guardar();
			
		}
		
		$alumno 	= $this->fichaDelAlumno($alumno_id);
		$this->datosAlumno($alumno, $year_id);

		return (array)$alumno;
	}
	
	public function putQuitarOrdinal()
	{
		$user 	        	= User::fromToken();
		$now 				= Carbon::now('America/Bogota');
		$proceso_id     	= Request::input('proceso_id');
		$ordinal_id     	= Request::input('id');
		
		$consulta 	= 'UPDATE dis_proceso_ordinales SET deleted_at=?, deleted_by=? WHERE proceso_id=? and ordinal_id=?'; 
		$datos 		= [ $now, $user->user_id, $proceso_id, $ordinal_id ];
		
		DB::update($consulta, $datos);

		// La línea cuelga del **proceso** y no del ordinal quitado, por lo mismo
		// que en `postStore`: `dis_proceso_ordinales` no está en el vocabulario, y
		// ampliarlo es una decisión de otro lote. Colgarla del proceso además es lo
		// que la pantalla quiere — quitarle un ordinal a una falta es un cambio de
		// esa falta, y así sale en su historial y no en uno aparte.
		$proceso = $this->fotoDelProceso($proceso_id);

		$alumnoDeLaLinea = isset($proceso['alumno_id']) && is_numeric($proceso['alumno_id']) ? (int) $proceso['alumno_id'] : null;

		Auditoria::registrar()
			->editar('dis_proceso', is_numeric($proceso_id) ? (int) $proceso_id : null)
			->deAlumno($alumnoDeLaLinea, NombreDelAlumno::de($alumnoDeLaLinea))
			->en(periodo: isset($proceso['periodo_id']) && is_numeric($proceso['periodo_id']) ? (int) $proceso['periodo_id'] : null)
			->de(['ordinal_id' => $ordinal_id])
			->resumen('Quitó el ordinal '.$ordinal_id.' de la falta '.$proceso_id)
			->guardar();
		
		return 'Quitado';
	}

	public function postAsignarOrdinal()
	{
		$user 	        	= User::fromToken();
		$now 				= Carbon::now('America/Bogota');
		$proceso_id     	= Request::input('proceso_id');
		$ordinal_id     	= Request::input('id');
		
		$consulta 	= 'INSERT INTO dis_proceso_ordinales(ordinal_id, proceso_id, added_by, created_at, updated_at) VALUES(?,?,?,?,?)'; 
		$datos 		= [ $ordinal_id, $proceso_id, $user->user_id, $now, $now ];
		
		DB::update($consulta, $datos);
		
		$last_id = DB::getPdo()->lastInsertId();
		$ordinal = DB::select('SELECT * FROM dis_proceso_ordinales WHERE id=?', [$last_id])[0]; 

		// La simétrica de `putQuitarOrdinal`, y colgada del mismo sitio: poner y
		// quitar el mismo ordinal tienen que salir en el mismo historial o no se
		// pueden leer como lo que son.
		$proceso = $this->fotoDelProceso($proceso_id);

		$alumnoDeLaLinea = isset($proceso['alumno_id']) && is_numeric($proceso['alumno_id']) ? (int) $proceso['alumno_id'] : null;

		Auditoria::registrar()
			->editar('dis_proceso', is_numeric($proceso_id) ? (int) $proceso_id : null)
			->deAlumno($alumnoDeLaLinea, NombreDelAlumno::de($alumnoDeLaLinea))
			->en(periodo: isset($proceso['periodo_id']) && is_numeric($proceso['periodo_id']) ? (int) $proceso['periodo_id'] : null)
			->a(['ordinal_id' => $ordinal_id])
			->resumen('Asignó el ordinal '.$ordinal_id.' a la falta '.$proceso_id)
			->guardar();
		
		return (array)$ordinal;
	}

	public function putDestroy()
	{
		$user 	        	= User::fromToken();
		$now 				= Carbon::now('America/Bogota');
		$proceso_id     	= Request::input('proceso_id');
		$alumno_id     		= Request::input('alumno_id');
		
		
		// **Antes** del borrado: lo que se borró es lo que hay que poder leer
		// después. Y es la pregunta que ningún detector de escrituras encuentra —no
		// «quién escribe aquí» sino «quién puede quitar de aquí»—: borrar una falta
		// disciplinaria la quita de la ficha del alumno, y `deleted_by` dice quién
		// pero no dice qué.
		$antes = $this->fotoDelProceso($proceso_id);

		$consulta 	= 'UPDATE dis_procesos SET deleted_at=?, deleted_by=? WHERE id=?'; 
		$datos 		= [ $now, $user->user_id, $proceso_id ];
		
		DB::update($consulta, $datos);

		$alumnoDeLaLinea = is_numeric($alumno_id) ? (int) $alumno_id : null;

		Auditoria::registrar()
			->borrar('dis_proceso', is_numeric($proceso_id) ? (int) $proceso_id : null)
			->deAlumno($alumnoDeLaLinea, NombreDelAlumno::de($alumnoDeLaLinea))
			->en(periodo: isset($antes['periodo_id']) && is_numeric($antes['periodo_id']) ? (int) $antes['periodo_id'] : null)
			->de($antes)
			->guardar();
		
		$alumno 	= $this->fichaDelAlumno($alumno_id);

		$this->datosAlumno($alumno, $user->year_id);
		
		return (array)$alumno;
	}
	
	

	

}