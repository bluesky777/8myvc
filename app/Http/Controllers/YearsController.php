<?php namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Request;

use App\User;
use App\Models\Year;
use App\Services\Auditoria;
use App\Support\Autoriza;
use App\Services\BoletinIndependiente;
use App\Support\RepartoDeLaNota;
use App\Models\Periodo;
use App\Models\ConfigCertificado;
use App\Models\ImageModel;
use App\Models\Grupo;
use App\Models\Asignatura;
use App\Models\EscalaDeValoracion;
use App\Models\Frase;
use App\Models\Unidad;
use Carbon\Carbon;
use App\Support\ColumnaSegura;
use App\Support\FilaQueSeVaAEscribir;
use App\Services\CalendarioDePeriodos;


class YearsController extends Controller {

	public function getIndex()
	{
		$user = User::fromToken();

		$consulta 	= 'SELECT y.*, i.nombre as logo FROM years y left join images i ON i.id=y.logo_id and i.deleted_at is null WHERE y.deleted_at is null';
		$years 		= DB::select($consulta);

		foreach ($years as $year) {
			$consulta 			= 'SELECT * FROM periodos WHERE year_id=? and deleted_at is null';
			$year->periodos 	= DB::select($consulta, [$year->id]);
		}

		return $years;
	}


	public function getColegio()
	{
		$user = User::fromToken();

		$consulta = 'SELECT * FROM years WHERE deleted_at is null';
		$years    = DB::select($consulta);


		foreach ($years as $year) {
			$consulta       = 'SELECT * FROM periodos WHERE year_id=? and deleted_at is null';
			$year->periodos = DB::select($consulta, [$year->id]);

			$consulta      = 'SELECT * FROM escalas_de_valoracion WHERE year_id=? and deleted_at is null order by orden asc';
			$year->escalas = DB::select($consulta, [$year->id]);
		}

		$consulta = 'SELECT * FROM config_certificados';
		$certif   = DB::select($consulta);

		$consulta = 'SELECT * FROM images WHERE user_id=? and publica=true';
		$imagenes = DB::select($consulta, [$user->user_id]);



		$result = ['years' => $years, 'certificados' => $certif, 'imagenes' => $imagenes];

		return $result;
	}


	public function postStore()
	{
		$user  = User::fromToken();
		$ahora = Carbon::now('America/Bogota');

		$year = new Year;

		$year->year                   = Request::input('year');
		$year->nombre_colegio         = Request::input('nombre_colegio');
		$year->abrev_colegio          = Request::input('abrev_colegio');
		$year->nota_minima_aceptada   = Request::input('nota_minima_aceptada');
		$year->resolucion             = Request::input('resolucion');
		$year->codigo_dane            = Request::input('codigo_dane');
		$year->encabezado_certificado = Request::input('encabezado_certificado');

		$actual_pedido  = (bool) Request::input('actual');
		$year->actual   = Request::input('actual');
		$year->telefono = Request::input('telefono');
		$year->celular  = Request::input('celular');
		
		$year->unidad_displayname      = Request::input('unidad_displayname');
		$year->unidades_displayname    = Request::input('unidades_displayname');
		$year->genero_unidad           = Request::input('genero_unidad');
		$year->subunidad_displayname   = Request::input('subunidad_displayname');
		$year->subunidades_displayname = Request::input('subunidades_displayname');
		$year->genero_subunidad        = Request::input('genero_subunidad');
		
		$year->website               = Request::input('website');
		$year->website_myvc          = Request::input('website_myvc');
		$year->alumnos_can_see_notas = Request::input('alumnos_can_see_notas');

		$year->save();

		$year_id_nuevo = $year->id;

		if ($year->actual) {
			Year::where('actual', true)->update(['actual'=>false]);
		}

		$year 				= Year::find($year_id_nuevo);
		// El mismo `= 1` de putSetActual, y con el mismo efecto: crear un año
		// pidiendo `actual: false` apagaba a los demás y encendía éste igual. El
		// front manda siempre `actual: true` (`YearsCtrl.fixControles`), así que
		// esto no cambia lo que hace la pantalla; cierra el segundo camino por el
		// que aparecen dos años actuales.
		$year->actual 		= $actual_pedido ? 1 : 0;
		$year->created_by 	= $user->user_id;
		$year->save();

		// Los periodos se crean **al final**, y no aquí, que es donde estaban. El
		// motivo es el orden: sus fechas se calculan desde `years.calendario`, y esa
		// columna la copia del año anterior el bloque de abajo. Creándolos antes, un
		// colegio de calendario B estrenaba el año con las fechas del A.

		// NECESITARÉ MUCHO DEL AÑO ANTERIOR
		$year_ante = $year->year - 1;
		$pasado = Year::where('year', $year_ante)->first();

		if ($pasado) {
			$year->ciudad_id                     = $pasado->ciudad_id;
			$year->logo_id                       = $pasado->logo_id;
			$year->rector_id                     = $pasado->rector_id;
			$year->secretario_id                 = $pasado->secretario_id;
			$year->tesorero_id                   = $pasado->tesorero_id;
			$year->coordinador_academico_id      = $pasado->coordinador_academico_id;
			$year->coordinador_disciplinario_id  = $pasado->coordinador_disciplinario_id;
			$year->capellan_id                   = $pasado->capellan_id;
			$year->psicorientador_id             = $pasado->psicorientador_id;
			$year->config_certificado_estudio_id = $pasado->config_certificado_estudio_id;
			$year->cant_areas_pierde_year        = $pasado->cant_areas_pierde_year;
			$year->cant_asignatura_pierde_year   = $pasado->cant_asignatura_pierde_year;
			$year->contador_certificados         = $pasado->contador_certificados;
			$year->contador_folios               = $pasado->contador_folios;
			// Los dos interruptores del certificado van con sus contadores: un colegio que
			// numera sus constancias las sigue numerando en el anio nuevo sin volver a
			// configurarlo (21 §2.3). Sin esta copia nacerian apagados y el certificado del
			// anio nuevo saldria sin numero, que es un cambio en papel oficial que nadie pidio.
			$year->usa_consecutivo_certificados  = $pasado->usa_consecutivo_certificados;
			$year->usa_folio_certificados        = $pasado->usa_folio_certificados;
			$year->nota_minima_aceptada          = $pasado->nota_minima_aceptada;
			$year->resolucion                    = $pasado->resolucion;
			$year->codigo_dane                   = $pasado->codigo_dane;
			$year->encabezado_certificado        = $pasado->encabezado_certificado;
			// Los dos títulos de los certificados (doc 38), y van pegados al encabezado
			// porque son los otros dos textos del mismo papel. Se heredan por lo mismo que
			// `regla_nivelacion` y las cuatro del modelo de evaluación: **el colegio que
			// escribió el suyo amanecería con el defecto cada enero**, y el defecto tiene
			// pinta de decisión. Aquí muerde más que en aquéllas, porque `coal` y
			// `coljordan` nacen con un título que no es el que quieren y lo corrigen a mano
			// (§4 del 38): sin esta copia lo corregirían **otra vez cada año**.
			$year->titulo_certificado_final      = $pasado->titulo_certificado_final;
			$year->titulo_certificado_periodos   = $pasado->titulo_certificado_periodos;
			$year->compromiso_familiar_label     = $pasado->compromiso_familiar_label;
			$year->mensaje_aprobo_con_pendientes = $pasado->mensaje_aprobo_con_pendientes;
			$year->minu_hora_clase     		 	 = $pasado->minu_hora_clase;
			$year->mostrar_nota_comport_boletin  = $pasado->mostrar_nota_comport_boletin;
			$year->mostrar_puesto_boletin  		 = $pasado->mostrar_puesto_boletin;
			$year->msg_when_students_blocked  	 = $pasado->msg_when_students_blocked;
			$year->profes_can_edit_alumnos  	 = $pasado->profes_can_edit_alumnos;
			$year->puestos_alfabeticamente  	 = $pasado->puestos_alfabeticamente;
			// El interruptor de la fase 6 del boletín independiente (31 ago 2026), y va
			// aquí y no en el bloque de abajo porque **sus dos vecinas de esta lista son
			// los otros dos interruptores de puesto**: quien lea estas tres líneas tiene
			// que poder dar por hecho que las tres se comportan igual.
			//
			// Sin esta copia el año nuevo nace con el `DEFAULT 1` de la columna, así que
			// **el colegio que lo puso a 0 lo recupera a 1 el enero siguiente**, sin que
			// nadie toque nada y sin un solo error. Y lo que reaparece no es un valor
			// cualquiera: es el puesto impreso de todos los alumnos del grupo moviéndose
			// —§7 del 19—, que es un cambio en papel firmado que nadie pidió.
			//
			// Es la misma familia que las diez de abajo y no una más: aquéllas hacían
			// **perder** una configuración, y ésta hace **resucitar** la contraria a la
			// elegida, que es peor porque el defecto tiene pinta de decisión.
			$year->puestos_con_bol_independiente = $pasado->puestos_con_bol_independiente;
			$year->show_fortaleza_bol  	 		 = $pasado->show_fortaleza_bol;
			$year->show_subasignaturas_en_finales = $pasado->show_subasignaturas_en_finales;
			$year->si_recupera_materia_recup_indicador = $pasado->si_recupera_materia_recup_indicador;
			// La regla de nivelación (22 §5) va con sus vecinas y no en el `DEFAULT`
			// de la columna: sin esta línea el colegio que eligió `mayor` amanecería
			// en `topada` cada enero, y la diferencia se imprime en el boletín de
			// cada nivelado. Es exactamente el caso de `puestos_con_bol_independiente`
			// de arriba, y el centinela del año nuevo es el que no deja olvidarla.
			$year->regla_nivelacion 			 = $pasado->regla_nivelacion;
			// Las cuatro del modelo de evaluación (35 §2, Fase 1). Van juntas porque
			// son una sola decisión, y se heredan por lo mismo que `regla_nivelacion`:
			// sin estas líneas, **el colegio que eligió `competencias` amanecería en
			// `ponderado` cada enero**, con sus desempeños escritos y sin la pantalla
			// que los pinta, y el vocabulario que se imprime en el boletín volvería a
			// decir «Desempeño» donde el colegio puso «Logro». El defecto de la columna
			// tiene pinta de decisión: es exactamente el caso de
			// `puestos_con_bol_independiente`, y el centinela del año nuevo es el que
			// no deja olvidarlas.
			$year->modelo_evaluacion 			 = $pasado->modelo_evaluacion;
			$year->desempeno_displayname 		 = $pasado->desempeno_displayname;
			$year->desempenos_displayname 		 = $pasado->desempenos_displayname;
			$year->genero_desempeno 			 = $pasado->genero_desempeno;
			// Y la quinta, del mismo sitio y por el mismo motivo (28 §5.5, Entrega 5):
			// el colegio que eligió `promedio` amanecería en `porcentaje` cada enero.
			// **Aquí eso no es una pantalla apagada: son las notas.** Ese defecto
			// vuelve a repartir por `subunidades.porcentaje` —una columna que en un
			// colegio de promedio nadie ha vuelto a cuadrar, porque el modo existe
			// justamente para no tener que tecleársela— y la definitiva de cada
			// asignatura del año nuevo sale de otra cuenta sin que nadie lo pida.
			//
			// Lo cazó el centinela del año nuevo, que es para lo que está: la Entrega 5
			// entró con la columna, los dieciséis sitios y las dos representaciones, y
			// **sin esta línea**. No la cazó ningún test de la entrega porque la
			// entrega no tenía ninguno.
			$year->reparto_subunidades 			 = $pasado->reparto_subunidades;
			$year->solo_escalas_valorativas 	 = $pasado->solo_escalas_valorativas;
			$year->year_pasado_en_bol 			 = $pasado->year_pasado_en_bol;
			$year->titulo_rector 				 = $pasado->titulo_rector;

			// Las diez que faltaban (30 ago 2026). No es una lista de deseos: son las
			// diez columnas de `years` que ni pide el cuerpo ni copiaba este bloque, o
			// sea las diez que el año nuevo perdía **cada vez**, y cuatro de ellas se
			// imprimen en papel oficial.
			//
			// `caracter`, `calendario` y `jornada` salen literalmente en el certificado
			// de estudio —«de carácter {{ caracter }}, calendario {{ calendario }},
			// jornada {{ jornada }}», en `certificadoEstudioDir.html`—, y como las tres
			// tienen defecto en el esquema, el año nuevo no salía en blanco: salía
			// diciendo «Privado», «A» y «Mañana y tarde» **fuera cual fuera el colegio**,
			// que es peor que vacío porque nadie lo nota. `frase_final_certificado` es la
			// frase de cierre de ese mismo papel y sí nacía vacía.
			//
			// `texto_acta_eval` es el texto del acta de evaluación y promoción, y
			// `prematr_nuevos` decide si el login enseña el enlace público de
			// prematrícula del año siguiente. `calendario`, además, es de la que salen
			// las fechas de los cuatro periodos de más abajo.
			$year->genero_colegio     = $pasado->genero_colegio;
			$year->img_encabezado_id  = $pasado->img_encabezado_id;
			$year->caracter           = $pasado->caracter;
			$year->calendario         = $pasado->calendario;
			$year->jornada            = $pasado->jornada;
			$year->frase_final_certificado = $pasado->frase_final_certificado;
			$year->texto_acta_eval    = $pasado->texto_acta_eval;
			$year->show_materias_todas = $pasado->show_materias_todas;
			$year->prematr_antiguos   = $pasado->prematr_antiguos;
			$year->prematr_nuevos     = $pasado->prematr_nuevos;

			$year->save();
			
			/// COPIAREMOS LAS ESCALAS DE VALORACIÓN
			$escalas_ant = EscalaDeValoracion::where('year_id', $pasado->id)->get();

			foreach ($escalas_ant as $key => $escalas) {
				$newEsc                    = new EscalaDeValoracion;
				$newEsc->desempenio        = $escalas->desempenio;
				$newEsc->valoracion        = $escalas->valoracion;
				$newEsc->porc_inicial      = $escalas->porc_inicial;
				$newEsc->porc_final        = $escalas->porc_final;
				$newEsc->descripcion       = $escalas->descripcion;
				$newEsc->orden             = $escalas->orden;
				$newEsc->perdido           = $escalas->perdido;
				$newEsc->year_id           = $year->id;
				$newEsc->icono_infantil    = $escalas->icono_infantil;
				$newEsc->icono_adolescente = $escalas->icono_adolescente;
				$newEsc->save();
			}

			/// COPIAREMOS LAS FRASES
			$frases_ant = Frase::where('year_id', $pasado->id)->get();

			foreach ($frases_ant as $key => $frases) {
				$newFra = new Frase;
				$newFra->frase 			= $frases->frase;
				$newFra->tipo_frase 	= $frases->tipo_frase;
				$newFra->year_id 		= $year->id;
				$newFra->save();
			}

			/// COPIAREMOS LAS UNIDADES POR DEFECTO **Y SUS SUBUNIDADES**
			//
			// Hasta el 2 sep 2026 este bloque copiaba sólo la mitad de arriba. Y como
			// las unidades copiadas nacen con **ids nuevos**, las subunidades del año
			// viejo se quedaban colgadas de las unidades viejas: **ninguna llegaba al
			// año nuevo**. La plantilla del colegio amanecía con contenedores sin
			// casillas, el primer docente que abría su asignatura disparaba el
			// sembrador de `UnidadesController::getDeAsignaturaPeriodo` —que copia lo
			// que haya— y la rejilla salía **sin un solo sitio donde poner una nota**,
			// con un 200 y sin un error en ningún log.
			//
			// Es la familia de `puestos_con_bol_independiente` (31 ago 2026) entrando
			// por la puerta que su centinela **no** vigila: aquél cuenta las columnas
			// de `years`, y esto no es una columna sino una **tabla hija**. Un censo de
			// tablas con `year_id` tampoco lo habría cazado —`subunidades_por_defecto`
			// no tiene `year_id`, cuelga de `unidades_por_defecto`—, y por eso el
			// centinela que faltaría es otro: el de las **tablas** que se copian.
			//
			// **Y ese centinela existe desde el 13 sep 2026**, once días y dos tablas
			// después: es `CentinelaDeLasTablasDelAnioNuevoTest`, y lo pagaron
			// `competencias` y `desempenos_por_defecto` entrando por esta misma puerta.
			// **Lo que sigue sin cubrir es justo lo que este párrafo describe**, y por eso
			// se queda escrito entero: aquél censa las tablas **con `year_id`**, así que
			// `subunidades_por_defecto` le sigue siendo invisible.
			// `CentinelaDeLasColumnasDelAnioNuevoTest` compara las **68 columnas de
			// `years`** con las que escribe `postStore`, una por una: no mira ninguna
			// tabla hija. La columna de alcance que se añadió a `unidades_por_defecto`
			// en `2026_09_05_200000` sí viaja —está unas líneas más abajo, y tiene su
			// test—, pero **eso fue porque alguien se acordó**, no porque nada lo
			// impidiera. `subunidades_por_defecto` tiene hoy exactamente la misma forma
			// —`SELECT *` arriba, `INSERT` con columnas nombradas abajo— y **entra por
			// la misma puerta el día que la Entrega 7 le añada una columna**.
			//
			// **ESTO ARREGLA EL SEMBRADOR, NO LO YA SEMBRADO.** Un año copiado antes de
			// este commit sigue con sus unidades por defecto vacías, y **ningún camino
			// de este código las repone**. Cuántos años y cuántos colegios están así
			// **no se sabe**: se mide con la consulta de la §1.bis de
			// `docs/migracion/28-competencias-e-indicadores.md`, corriéndola en los
			// diecisiete del servidor. Lo dice aquí porque es aquí donde alguien va a
			// venir dentro de dos meses a leer «arreglado».
			// **Y el ALCANCE viaja con la fila, desde el 4 sep 2026.** Es la mitad que
			// no se ve de la decisión 8 de Joseth (§5.7.a del 28): desde
			// `2026_09_05_200000_alcance_de_la_plantilla`, una fila puede ir dirigida a
			// un nivel educativo y/o a una materia, y este `INSERT` **nombra sus
			// columnas**.
			//
			// Sin las dos de abajo el fallo no habría sido «no se copia el alcance»,
			// que se nota: habrían nacido a NULL, y **NULL aquí significa “a todos”**.
			// O sea que la plantilla de UNA fila de preescolar —la que existe para que
			// la docente deje de teclear el mismo logro dos veces— se le habría
			// sembrado en enero **a todo el bachillerato**, con su única casilla al
			// 100 %, un 200 y ningún error. No es «no se copió»: es **la fila
			// escapándose de su alcance**, y es la misma familia del fallo que este
			// mismo bloque arregló arriba, sólo que en la dirección contraria — allí la
			// plantilla llegaba vacía, aquí llegaría a quien no era.
			//
			// Las columnas van nombradas en el `SELECT` por lo mismo que en el
			// `INSERT`: un `*` aquí seguiría funcionando hoy y volvería a callarse la
			// próxima vez que alguien añada una columna a esta tabla.
			$unidades_ant = DB::select('SELECT id, definicion, porcentaje, obligatoria, orden, created_by, nivel_educativo_id, materia_id FROM unidades_por_defecto WHERE year_id=? AND deleted_at is null;', [$pasado->id]);

			foreach ($unidades_ant as $key => $unidad) {
				DB::insert('INSERT INTO unidades_por_defecto(definicion, porcentaje, year_id, obligatoria, orden, created_by, nivel_educativo_id, materia_id) VALUES(?,?,?,?,?,?,?,?)',
					[$unidad->definicion, $unidad->porcentaje, $year->id, $unidad->obligatoria, $unidad->orden, $unidad->created_by, $unidad->nivel_educativo_id, $unidad->materia_id]);

				// **Dentro del bucle y justo después del `INSERT` de su unidad.** Leído
				// una línea más abajo —fuera del bucle, o después de insertar las
				// subunidades— las cinco acaban bajo la misma unidad y el reparto del
				// colegio queda 100/0 en vez de 50/50, con la misma cantidad de filas.
				// Es la misma forma que ya usa el sembrador de `UnidadesController:159`.
				$unidad_nueva_id = DB::getPdo()->lastInsertId();

				$subunidades_ant = DB::select('SELECT * FROM subunidades_por_defecto WHERE unidad_defec_id=? AND deleted_at is null;', [$unidad->id]);

				foreach ($subunidades_ant as $subunidad) {
					// **`inicia_at` y `finaliza_at` NO se copian**, y es lo mismo que ya
					// se decidió con `editable_por_profe_id` de los requisitos aquí
					// abajo: son fechas **del año viejo**. Copiadas, la plantilla del año
					// nuevo nacería con casillas que abrieron y cerraron hace doce meses
					// —o sea vencidas el día uno—, que es la forma de fallar que este
					// fichero lleva pagada dos veces: una configuración que aparece sola
					// y con pinta de haberla tomado alguien.
					//
					// `created_at` sí va, con `$ahora`. La línea de la unidad de arriba
					// no lo pone y esa fila nace sin fecha: es el mismo defecto que se
					// arregló en el bloque de disciplina, **y no se toca aquí** porque
					// cambiarlo mueve filas que este commit no viene a mover.
					DB::insert('INSERT INTO subunidades_por_defecto(definicion, porcentaje, unidad_defec_id, nota_default, obligatoria, orden, created_by, created_at, updated_at) VALUES(?,?,?,?,?,?,?,?,?)',
						[$subunidad->definicion, $subunidad->porcentaje, $unidad_nueva_id, $subunidad->nota_default, $subunidad->obligatoria, $subunidad->orden, $user->user_id, $ahora, $ahora]);
				}
			}

			/// COPIAREMOS LOS REQUISITOS DE MATRÍCULA
			// `requisitos_matricula` es por año igual que las escalas y las frases, y
			// era la única de las tablas de configuración que no se copiaba: el año
			// nuevo nacía sin ningún requisito y la pantalla de matrículas salía vacía,
			// con el colegio volviendo a escribir la misma lista todos los años.
			//
			// No se copia `editable_por_profe_id`, que es lo mismo que se decidió con
			// el docente de las asignaturas y por el mismo motivo: apunta a una persona
			// de la planta, y **cuando se crea el año no hay ni un contrato en él**.
			// Además, ningún método de `Matriculas\RequisitosController` la escribe, así
			// que lo que hubiera ahí se puso a mano en la base.
			$requisitos_ant = DB::select('SELECT * FROM requisitos_matricula WHERE year_id=? AND deleted_at is null ORDER BY orden, id;', [$pasado->id]);

			foreach ($requisitos_ant as $requisito) {
				DB::insert('INSERT INTO requisitos_matricula(year_id, orden, requisito, descripcion, updated_by, created_at, updated_at) VALUES(?,?,?,?,?,?,?)',
					[ $year->id, $requisito->orden, $requisito->requisito, $requisito->descripcion, $user->user_id, $ahora, $ahora ]);
			}

			/// COPIAREMOS LAS CONFIGURACIONES DE DISCIPLINA Y ORDINALES
			$dis_configuraciones = DB::select('SELECT * FROM dis_configuraciones WHERE year_id=? AND deleted_at is null;', [$pasado->id]);
			if (count($dis_configuraciones) > 0) {
				$dis = $dis_configuraciones[0];
				
				// Los dos `INSERT` de aquí abajo eran los únicos de las cuatro escrituras
				// que hay en estas dos tablas que **no** ponían fecha: los otros tres
				// —`GruposController:265` y los dos de `OrdinalesController`— sí. Como
				// esto sólo corre al crear un año, la fila mal nacía **una vez por año y
				// por colegio**, y las que hay ya escritas están medidas: en el seed,
				// **14 de 17 ordinales y 7 de 9 configuraciones** vienen con `created_at`
				// nulo — o sea todos los años creados por esta ruta, del 3 en adelante.
				//
				// Hoy no lo lee nadie: los listados de disciplina ordenan por `ordinal`,
				// no por fecha, y ningún cliente pide esa columna. Se arregla porque la
				// pregunta «cuándo apareció esta fila» es la que no se puede contestar
				// después, y porque tres de cuatro sitios ya lo hacían bien.
				$now_dis = $ahora;

				DB::insert('INSERT INTO dis_configuraciones(year_id, reinicia_por_periodo, falta_tipo1_displayname, faltas_tipo1_displayname, genero_falta_t1, falta_tipo2_displayname, faltas_tipo2_displayname, genero_falta_t2, 
					falta_tipo3_displayname, faltas_tipo3_displayname, genero_falta_t3, cant_tard_to_ft1, cant_ft1_to_ft2, cant_ft2_to_ft3,
					nombre_col1, nombre_col2, nombre_col3, definicion_ft1, definicion_ft2, definicion_ft3, created_at, updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', 
					[ $year->id, $dis->reinicia_por_periodo, $dis->falta_tipo1_displayname, $dis->faltas_tipo1_displayname, $dis->genero_falta_t1, $dis->falta_tipo2_displayname, $dis->faltas_tipo2_displayname, $dis->genero_falta_t2, 
					$dis->falta_tipo3_displayname, $dis->faltas_tipo3_displayname, $dis->genero_falta_t3, $dis->cant_tard_to_ft1, $dis->cant_ft1_to_ft2, $dis->cant_ft2_to_ft3, 
					$dis->nombre_col1, $dis->nombre_col2, $dis->nombre_col3, $dis->definicion_ft1, $dis->definicion_ft2, $dis->definicion_ft3, $now_dis, $now_dis ]);
					
				$dis_ordinales = DB::select('SELECT * FROM dis_ordinales WHERE year_id=? AND deleted_at is null;', [$pasado->id]);
					
				foreach ($dis_ordinales as $key => $ord) {
					DB::insert('INSERT INTO dis_ordinales(year_id, tipo, ordinal, descripcion, pagina, created_at, updated_at) VALUES(?,?,?,?,?,?,?)', 
						[ $year->id, $ord->tipo, $ord->ordinal, $ord->descripcion, $ord->pagina, $now_dis, $now_dis ]);
				}
			}
			
			/// AHORA COPIAMOS LOS GRUPOS Y ASIGNATURAS DEL AÑO PASADO AL NUEVO AÑO.
			//
			// El grupo va **sin `titular_id`**, y eso no es un olvido (30 ago 2026). El
			// listado de grupos hace `left join profesores p on p.id=g.titular_id` —join
			// directo, sin pasar por `contratos`—, así que un titular copiado no sale en
			// blanco: sale **con nombre y apellidos**, como si estuviera en la planta del
			// año nuevo. Y `GruposEditCtrl` lo lee del grupo cargado y no de la lista de
			// contratados, así que se conserva al guardar. Un dato que se ve y parece
			// cierto no es un borrador pendiente. Decisión de Joseth; el docente de la
			// asignatura, doce líneas más abajo, se copia justo por lo contrario.
			$grupos_ant = Grupo::where('year_id', $pasado->id)->get();
			
			foreach ($grupos_ant as $key => $grupo) {
				$newGr = new Grupo;
				$newGr->nombre 			= $grupo->nombre;
				$newGr->abrev 			= $grupo->abrev;
				$newGr->year_id 		= $year->id;
				$newGr->grado_id 		= $grupo->grado_id;
				$newGr->valormatricula 	= $grupo->valormatricula;
				$newGr->valorpension 	= $grupo->valorpension;
				$newGr->orden 			= $grupo->orden;
				$newGr->cupo 			= $grupo->cupo;
				$newGr->caritas 		= $grupo->caritas;
				// La intensidad horaria del grupo se hereda, igual que la de sus asignaturas en el
				// bucle de dentro (`creditos`). Y las dos hacen falta o no sirve ninguna:
				// la pantalla de asignaturas avisa comparando Σ `creditos` contra `ih`, así que
				// copiar una sola no deja el aviso a medias — lo **apaga**, porque `ih` en NULL
				// es «nadie la ha puesto» y ahí la comparación se calla a propósito
				// (`2026_09_07_100000_grupos_con_ih`).
				//
				// Y se apagaría en **enero**, que es el único mes en que sirve: el año nuevo es
				// cuando se arma el horario y cuando un 3 tecleado donde iban 4 todavía se
				// puede corregir sin mover fichas ya colocadas. Un fallo que sólo se puede ver
				// una vez al año no lo encuentra nadie usando: lo encuentra quien lo va a
				// buscar, y por eso lo vigila `CentinelaDeLasColumnasDelGrupoCopiadoTest`.
				$newGr->ih 				= $grupo->ih;
				$newGr->save();

				$asigs_ant = Asignatura::where('grupo_id', $grupo->id)->get();
				
				for ($i=0; $i < count($asigs_ant); $i++) { 
					$newAsig = new Asignatura;
					$newAsig->materia_id 	= $asigs_ant[$i]->materia_id;
					$newAsig->grupo_id 		= $newGr->id;
					$newAsig->creditos 		= $asigs_ant[$i]->creditos;
					$newAsig->orden 		= $asigs_ant[$i]->orden;
					// El docente y su suplente SÍ se copian (30 ago 2026), y es lo que ya
					// hacía `POST asignaturas/copiar` de grupo a grupo: esta ruta era la
					// única de las dos que no lo hacía.
					//
					// Cuando se crea el año no hay ni un contrato en él, así que el docente
					// copiado no está contratado todavía — y ahí está la gracia, no el
					// problema. La columna «Profesor» de la rejilla resuelve el nombre
					// **filtrando la lista de contratados** (`AsignaturasCtrl`, y esa lista
					// sale de `Profesor::paraElegirEnAsignaturas`), así que la celda sale en
					// blanco hasta que se le hace el contrato, y entonces **aparece sola**.
					// El reparto del año pasado queda de borrador y se va materializando
					// según se contrata, en vez de perderse.
					//
					// Es al revés que el titular del grupo, que se copiaría **visible** y por
					// eso no se copia. Y no cambia la lección de la medición: en el seed, 1 de
					// 10 asignaturas heredaría un docente sin contrato — lo que cambia es que
					// eso ya no es un dato equivocado en silencio, es uno pendiente.
					$newAsig->profesor_id 	= $asigs_ant[$i]->profesor_id;
					$newAsig->nuevo_responsable_id = $asigs_ant[$i]->nuevo_responsable_id;
					$newAsig->save();
				}
				$grupo->asigs_ant = $asigs_ant;
			}
			$year->grupos_ant = $grupos_ant;
		}

		$periodos = $this->crearLosPeriodos($year, $pasado, $user->user_id, $ahora);
		$year->periodos = $periodos;

		/// Y AL FINAL, EL PLAN DE ÁREA: `competencias` y `desempenos_por_defecto`.
		//
		// **Va DETRÁS de los periodos, y ése es el orden entero.**
		// `desempenos_por_defecto.periodo_id` es NOT NULL y apunta a `periodos`, así
		// que hasta que los cuatro del año nuevo no existen no hay a dónde
		// remapearlo. Escrito más arriba —junto a las escalas y las frases, que es
		// donde parece que va— cada desempeño del año nuevo nacería colgado de un
		// periodo del año VIEJO: la clave ajena lo aceptaría, la pantalla del plan
		// de área lo enseñaría igual, y la planilla del docente no encontraría ni
		// uno, porque lee por `year_id` **y** `periodo_id` a la vez.
		if ($pasado) {
			$this->copiarElPlanDeArea($pasado, $year, $periodos, $user->user_id, $ahora);
		}

		return $year;
	}


	/**
	 * Los cuatro periodos del año recién creado, con fechas.
	 *
	 * Hasta el 30 ago 2026 aquí se insertaba **uno**, con `numero=1`, `actual=1`, sin
	 * fechas, sin `created_at` y sin `created_by`. La consecuencia se ve en la base
	 * del colegio del seed: sus ocho años viejos tienen los cuatro periodos —puestos
	 * a mano, uno a uno, después— y **el único año creado por esta ruta tiene uno**.
	 * Cuatro es decisión de Joseth (`CalendarioDePeriodos::CANTIDAD`).
	 *
	 * De dónde salen las fechas está explicado en `CalendarioDePeriodos`. Que salgan
	 * de algún sitio importa más de lo que parece: `ActasEvaluacionController` reparte
	 * las ausencias por periodo **contra `fecha_inicio` y `fecha_fin`**, y con las
	 * cuatro en NULL el acta manda todas las faltas al balde `fuera_calendario`.
	 *
	 * Los dos interruptores del periodo —`profes_pueden_editar_notas` y
	 * `profes_pueden_nivelar`— se copian del periodo del mismo número del año
	 * anterior, y sólo caen al `1` del esquema cuando no hay de dónde copiarlos. No
	 * es un detalle: en el seed hay años con los cuatro periodos **cerrados** a la
	 * edición, y nacer abiertos abre la planilla de notas de todo un año lectivo a
	 * los 51 docentes sin que nadie lo haya pedido.
	 *
	 * @return list<Periodo>
	 */
	private function crearLosPeriodos(Year $year, ?Year $pasado, int $user_id, Carbon $ahora): array
	{
		$del_anterior = $pasado
			? DB::select('SELECT * FROM periodos WHERE year_id=? AND deleted_at is null ORDER BY numero, id;', [$pasado->id])
			: [];

		$interruptores = [];

		foreach ($del_anterior as $periodo) {
			$interruptores[(int) $periodo->numero] ??= [
				'editar'  => (int) $periodo->profes_pueden_editar_notas,
				'nivelar' => (int) $periodo->profes_pueden_nivelar,
			];
		}

		$fechas = CalendarioDePeriodos::para(
			(int) $year->year,
			$pasado ? (int) $pasado->year : null,
			$del_anterior,
			$year->calendario,
		);

		$periodos = [];

		foreach ($fechas as $fecha) {
			$numero = $fecha['numero'];

			$periodo                             = new Periodo;
			$periodo->numero                     = $numero;
			$periodo->fecha_inicio               = $fecha['fecha_inicio'];
			$periodo->fecha_fin                  = $fecha['fecha_fin'];
			$periodo->fecha_plazo                = $fecha['fecha_plazo'];
			// El primero, y sólo el primero: un año con dos periodos actuales deja a
			// `Login::ponerEnElPeriodoActual` eligiendo por el orden en que salgan de
			// la base. Antes esto se cumplía solo, porque el periodo era uno.
			$periodo->actual                     = $numero === 1 ? 1 : 0;
			$periodo->profes_pueden_editar_notas = $interruptores[$numero]['editar']  ?? 1;
			$periodo->profes_pueden_nivelar      = $interruptores[$numero]['nivelar'] ?? 1;
			$periodo->year_id                    = $year->id;
			$periodo->created_by                 = $user_id;
			// Explícitas y en Bogotá, como las de `dis_configuraciones` de aquí arriba:
			// la app corre en UTC (`config/app.php`), así que dejárselas a Eloquent
			// pondría estas cuatro filas cinco horas por delante de las que escribe el
			// resto de este mismo método.
			$periodo->created_at                 = $ahora;
			$periodo->updated_at                 = $ahora;
			$periodo->save();

			$periodos[] = $periodo;
		}

		return $periodos;
	}


	/**
	 * El **plan de área** del colegio viaja al año nuevo: `competencias` y
	 * `desempenos_por_defecto`.
	 *
	 * Son las dos tablas por año que trajeron las Fases 2 y 3 del
	 * [35](../../../docs/migracion/35-el-modelo-de-evaluacion-del-colegio.md), y
	 * **nadie las copiaba**. Sin esto, el colegio que escribe su plan de área en
	 * 2026 lo encuentra **vacío en enero de 2027** y lo reescribe entero: es
	 * exactamente la §1.bis del doc 28 —las subunidades por defecto sin copiarse
	 * durante años— por la misma puerta y con la misma cara, o sea **ninguna**. No
	 * rompe nada el día que pasa, no deja una línea en ningún log, y se nota en
	 * enero, cuando ya no hay quien lo relacione con haber creado un año.
	 *
	 * ## Lo que hace cara a esta copia: las filas se apuntan entre ellas
	 *
	 * Las escalas, las frases y los requisitos son filas sueltas: se copian con un
	 * `INSERT` y ya está. Aquí no. **El desempeño cuelga de la competencia**
	 * (`competencia_id`, D10) **y de un periodo** (`periodo_id`, NOT NULL), y las
	 * dos cosas nacen con **ids nuevos** en el año nuevo. Copiar las dos tablas
	 * sin remapear deja cada desempeño apuntando a la competencia y al periodo del
	 * **año viejo**:
	 *
	 *   - la clave ajena lo acepta —`competencias.id` y `periodos.id` siguen
	 *     existiendo, sólo que son de otro año—, así que no hay error;
	 *   - `GET desempenos/plantilla` los enseña igual, porque filtra por
	 *     `d.year_id`; y
	 *   - la planilla del docente no encuentra ni uno, porque
	 *     `catalogoPara()` lee por `year_id` **y** `periodo_id` a la vez.
	 *
	 * O sea: 200, pantalla llena, rejilla vacía. Es la forma de fallar de esta
	 * casa, y por eso el orden de aquí abajo —competencias primero, con su tabla
	 * de equivalencias— no es una preferencia de estilo.
	 *
	 * ## El ALCANCE viaja con la fila, y eso incluye `alumno_id`
	 *
	 * `materia_id`, `grado_id` y `alumno_id` dicen **a quién va dirigida** la
	 * fila, igual que `nivel_educativo_id` y `materia_id` en
	 * `unidades_por_defecto`, y **`NULL` significa «a todos»** en los tres. Así
	 * que no copiarlos no sería «se pierde una columna»: sería **la fila
	 * escapándose de su alcance** — la competencia que el colegio escribió para
	 * UN alumno con PIAR (Decreto 1421/2017) convertida en competencia de todo el
	 * grado, con un 200 y sin un error. Es el fallo que ya pagó la plantilla en
	 * `2026_09_05_200000_alcance_de_la_plantilla`, y aquí se evita antes.
	 *
	 * **Y la fila dirigida a un alumno se copia entera, no se deja atrás.** Dos
	 * razones, y la segunda es la que decide: la pantalla del colegio
	 * (`GET competencias` sin `alumno_id`) **enseña todas las filas del año**,
	 * también las dirigidas, así que una que sobre **se ve y se borra**; y una
	 * competencia con dueño sólo llega a alguien si ese alumno vuelve a tener
	 * boletín independiente el año nuevo —`BoletinIndependiente::alcance()`—, o
	 * sea justo cuando el colegio la quiere. Dejarla atrás sería el caso
	 * contrario: el alumno con PIAR es el único que amanecería sin su plan.
	 *
	 * ## Las dos referencias que pueden no tener destino, y qué se hace con cada una
	 *
	 * **El periodo: se salta la fila.** `periodo_id` es NOT NULL, así que no hay
	 * «sin periodo» que valga. Se busca el periodo del año nuevo **con el mismo
	 * número**, que es la misma equivalencia que ya usan los dos interruptores de
	 * `crearLosPeriodos`. Si el año viejo tenía un quinto periodo —o el suyo está
	 * en la papelera—, esa fila **no tiene a dónde ir** y se queda. Ponerla en el
	 * periodo 4 sería inventarse el plan del colegio; dejarle el id viejo sería
	 * escribir a mano el fallo que este método existe para evitar. Se cuenta y se
	 * dice en el log, porque un salto silencioso es media línea de código y una
	 * tarde de enero.
	 *
	 * **La competencia: la fila viaja huérfana.** El padre es **opcional** (D10),
	 * así que un desempeño sin competencia es una fila legítima que el colegio ve
	 * y puede volver a colgar. Conservar el id viejo, en cambio, sería un
	 * desempeño del año nuevo colgado de una competencia del año pasado — la
	 * referencia cruzada de arriba, escrita a propósito. Sólo puede pasar si la
	 * competencia padre está en la papelera: `postStore` de `DesempenosController`
	 * exige que sea del año al escribir.
	 *
	 * ## `created_at` sí se pone
	 *
	 * Con `$ahora`, como las subunidades y los requisitos. La auditoría de las
	 * nueve tablas que copia este método (13 sep 2026) encontró que la copia de
	 * `unidades_por_defecto` **no lo pone** y esas filas nacen sin fecha; no se
	 * arregla aquí porque mueve filas que este cambio no viene a mover, pero las
	 * dos tablas nuevas **no heredan el descuido**.
	 *
	 * @param list<Periodo> $periodos_nuevos los cuatro que acaba de crear `crearLosPeriodos`
	 */
	private function copiarElPlanDeArea(Year $pasado, Year $year, array $periodos_nuevos, int $user_id, Carbon $ahora): void
	{
		/// LAS COMPETENCIAS, Y SU TABLA DE EQUIVALENCIAS
		//
		// Las columnas van nombradas en el `SELECT` por lo mismo que en el
		// `INSERT`: un `*` aquí seguiría funcionando hoy y volvería a callarse la
		// próxima vez que alguien añada una columna a esta tabla. Es la regla de
		// `CompetenciasController` —«nunca `SELECT *`»— y la de la copia de
		// `unidades_por_defecto` de aquí arriba.
		$competencias_ant = DB::select('SELECT id, materia_id, grado_id, alumno_id, definicion, orden, codigo_men
			FROM competencias WHERE year_id=? AND deleted_at is null ORDER BY id;', [$pasado->id]);

		$equivalencia = [];

		foreach ($competencias_ant as $competencia) {
			DB::insert('INSERT INTO competencias(year_id, materia_id, grado_id, alumno_id, definicion, orden, codigo_men, created_by, created_at, updated_at) VALUES(?,?,?,?,?,?,?,?,?,?)',
				[$year->id, $competencia->materia_id, $competencia->grado_id, $competencia->alumno_id,
				$competencia->definicion, $competencia->orden, $competencia->codigo_men, $user_id, $ahora, $ahora]);

			// Dentro del bucle y justo detrás de su `INSERT`, igual que en la copia de
			// las subunidades: leído una línea más abajo, todos los desempeños
			// acabarían colgados de la última competencia.
			$equivalencia[(int) $competencia->id] = (int) DB::getPdo()->lastInsertId();
		}

		/// LOS PERIODOS, POR NÚMERO
		//
		// `crearLosPeriodos` acaba de devolverlos, así que el lado nuevo no se
		// vuelve a leer de la base. El viejo sí, porque de la fila del desempeño
		// sólo viene el id.
		$numero_del_viejo = [];

		foreach (DB::select('SELECT id, numero FROM periodos WHERE year_id=? AND deleted_at is null;', [$pasado->id]) as $periodo) {
			$numero_del_viejo[(int) $periodo->id] = (int) $periodo->numero;
		}

		$nuevo_por_numero = [];

		foreach ($periodos_nuevos as $periodo) {
			$nuevo_por_numero[(int) $periodo->numero] = (int) $periodo->id;
		}

		/// Y LOS DESEMPEÑOS POR DEFECTO
		$desempenos_ant = DB::select('SELECT id, materia_id, grado_id, periodo_id, competencia_id, tipo, definicion, orden
			FROM desempenos_por_defecto WHERE year_id=? AND deleted_at is null ORDER BY id;', [$pasado->id]);

		$sin_periodo = [];

		foreach ($desempenos_ant as $desempeno) {
			$numero  = $numero_del_viejo[(int) $desempeno->periodo_id] ?? null;
			$destino = $numero === null ? null : ($nuevo_por_numero[$numero] ?? null);

			if ($destino === null) {
				$sin_periodo[] = (int) $desempeno->id;
				continue;
			}

			$padre = $desempeno->competencia_id === null
				? null
				: ($equivalencia[(int) $desempeno->competencia_id] ?? null);

			DB::insert('INSERT INTO desempenos_por_defecto(year_id, materia_id, grado_id, periodo_id, competencia_id, tipo, definicion, orden, created_by, created_at, updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?)',
				[$year->id, $desempeno->materia_id, $desempeno->grado_id, $destino, $padre,
				$desempeno->tipo, $desempeno->definicion, $desempeno->orden, $user_id, $ahora, $ahora]);
		}

		// **La única línea de log de este método, y existe por lo que cuesta no
		// tenerla.** Un desempeño saltado es plan de área que el colegio escribió y
		// que el año nuevo no tiene; sin esto, la diferencia entre «no había nada
		// que copiar» y «había y no cupo» no se puede reconstruir después. No es un
		// error —la fila de origen está mal dirigida, no la copia—, y por eso es un
		// aviso con su población y no una excepción que tumbe la creación del año.
		if ($sin_periodo !== []) {
			Log::warning('Crear el año '.$year->year.' dejó atrás '.count($sin_periodo).' desempeños por defecto: su periodo del año anterior no tiene equivalente.', [
				'year_id_nuevo'   => $year->id,
				'year_id_pasado'  => $pasado->id,
				'desempenos'      => $sin_periodo,
				'periodos_nuevos' => array_keys($nuevo_por_numero),
			]);
		}
	}


	public function putUseractive($year_id)
	{
		$user = User::fromToken();
		$usuario = User::findOrFail($user->user_id);
		$peri = Periodo::where('year_id', $year_id)->where('numero', $user->numero_periodo)->first();

		if ($peri) {
			$usuario->periodo_id = $peri->id;
		}else{
			$peris = Periodo::where('year_id', $year_id)->get();

			if (count($peris) > 0) {
				$peri = $peris[count($peris)-1];
				$usuario->periodo_id = $peri->id;
			}else{
				abort(400, 'Año sin ningún periodo.');
			}
			
		}

		$usuario->save();

		return $peri;
	}





	public function putGuardarCambios()
	{
		$user = User::fromToken();
		$now 	= Carbon::now('America/Bogota');
		$year = Year::findOrFail(Request::input('id'));
		
		try {
			// **Lo que el cuerpo no trae, no se toca.** Antes iba `Request::input('x')`
			// a secas en las veintiuna, así que un `PUT {"id": 1}` de una línea dejaba
			// el año sin nombre de colegio, sin resolución, sin código DANE, sin rector
			// y sin los nombres de unidad y subunidad —que se imprimen en el boletín de
			// todos los alumnos— y contestaba 200. Es la §68 otra vez: un campo que no
			// se manda no es un campo que no cambia, es un campo que se pisa. §93.
			//
			// Se conserva el valor actual en vez de contestar 422 porque el único
			// cliente que llama a esto —`YearsCtrl.guardar_cambios`, en `myvc_front`—
			// manda el objeto `year` entero, y hay dieciséis copias de ese front con
			// distinta antigüedad: un 422 rompería a la que mande veinte de veintiuno.
			// Conservar no puede romper a nadie.
			//
			// El defecto sólo tapa la clave AUSENTE, no la que llega en `null`: eso es
			// una petición diciendo «bórralo», y sigue borrando. Lo que hace con ella el
			// esquema está medido en §93.2 y no es lo que parece.
			$compromiso_familiar = $year->compromiso_familiar_label;

			if (Request::has('compromiso_familiar_label')) {
				$compromiso_familiar = null;

				if (Request::input('compromiso_familiar_label') != '' && Request::input('compromiso_familiar_label') != null) {
					$compromiso_familiar = Request::input('compromiso_familiar_label');
				}
			}

			$year->nombre_colegio            = Request::input('nombre_colegio', $year->nombre_colegio);
			$year->abrev_colegio             = Request::input('abrev_colegio', $year->abrev_colegio);
			$year->year                      = Request::input('year', $year->year);
			$year->rector_id                 = Request::input('rector_id', $year->rector_id);
			$year->secretario_id             = Request::input('secretario_id', $year->secretario_id);
			$year->tesorero_id               = Request::input('tesorero_id', $year->tesorero_id);
			$year->resolucion                = Request::input('resolucion', $year->resolucion);
			$year->codigo_dane               = Request::input('codigo_dane', $year->codigo_dane);
			$year->telefono                  = Request::input('telefono', $year->telefono);
			$year->celular                   = Request::input('celular', $year->celular);
			$year->website                   = Request::input('website', $year->website);
			$year->website_myvc              = Request::input('website_myvc', $year->website_myvc);
			$year->msg_when_students_blocked = Request::input('msg_when_students_blocked', $year->msg_when_students_blocked);
			$year->unidad_displayname        = Request::input('unidad_displayname', $year->unidad_displayname);
			$year->unidades_displayname      = Request::input('unidades_displayname', $year->unidades_displayname);
			$year->genero_unidad             = Request::input('genero_unidad', $year->genero_unidad);
			$year->subunidad_displayname     = Request::input('subunidad_displayname', $year->subunidad_displayname);
			$year->subunidades_displayname   = Request::input('subunidades_displayname', $year->subunidades_displayname);
			$year->genero_subunidad          = Request::input('genero_subunidad', $year->genero_subunidad);
			// Los tres rótulos del desempeño, aquí y no en una ruta propia: **D24 lo
			// partió en dos a propósito**. Son vocabulario y comparten guard con sus
			// seis vecinas —quien puede renombrar «Subunidad» puede renombrar
			// «Desempeño»—, mientras que `modelo_evaluacion` se va a
			// `PUT years/modelo-evaluacion` con `can_edit_plantilla_notas` dentro.
			//
			// Y tienen que estar en esta lista o **no las escribiría nadie**: este
			// método asigna campo a campo, que es justo lo que dejó a `profesores.tono`
			// leída en todas partes y escrita en ninguna.
			$year->desempeno_displayname     = Request::input('desempeno_displayname', $year->desempeno_displayname);
			$year->desempenos_displayname    = Request::input('desempenos_displayname', $year->desempenos_displayname);
			$year->genero_desempeno          = Request::input('genero_desempeno', $year->genero_desempeno);
			$year->alumnos_can_see_notas     = Request::input('alumnos_can_see_notas', $year->alumnos_can_see_notas);
			$year->compromiso_familiar_label = $compromiso_familiar;
			$year->updated_by                = $user->user_id;

			$year->save();
			
			
			// El ingreso sale del token (fase 2 de 18-auditoria.md). El `[0]` que
			// había reventaba para quien no tuviera ninguna sesión anotada, y aquí
			// eso caía en el `catch` de abajo: 422 «Datos incorrectos» **con el año
			// ya guardado**.
			$bit_by 	= $user->user_id;
			$bit_hist 	= isset($user->historial_id) && is_numeric($user->historial_id)
				? (int) $user->historial_id
				: null;

			$consulta 	= 'INSERT INTO bitacoras (created_by, historial_id, affected_element_type, affected_element_id, created_at, affected_element_new_value_string) 
					VALUES (?,?,?,?,?,?)';

			// `$year->id` y no `Request::input('id')`, que es lo que había. Es el
			// **único de los diez escritores de bitácora** que derivaba el sujeto de
			// la fila del CUERPO en vez de la fila leída; los otros nueve ya usan
			// `$nota->alumno_id` o `$subunidad->id`. Medido en
			// docs/migracion/noche-2026-08-24/med-2.md, y es la lección de la §50:
			// *«qué MÁS lee este identificador del cuerpo»*.
			//
			// La fila está garantizada desde la línea 298 —`Year::findOrFail(...)`,
			// fuera del `try`, así que un id que no existe es 404 antes de llegar
			// aquí—, o sea que `$year->id` es el id de la fila que se acaba de
			// guardar. No hay que fiarse de nada.
			//
			// **Hoy no cambia ningún resultado, y por eso hay que decir qué arregla:**
			// `config/database.php` lleva `strict => false`, así que un `id` no
			// numérico se convierte en silencio al entrar en la columna `int` y las
			// dos formas guardan lo mismo. Con el modo estricto puesto —que es un
			// endurecimiento razonable y no está descartado— la vieja lanzaría **después
			// de `$year->save()`**, y como el `catch` de abajo contesta `abort(422)`,
			// el año quedaría **cambiado**, el cliente leería «Datos incorrectos» y del
			// rastro no quedaría nada. Era un fallo latente que la configuración tapa.
			DB::insert($consulta, [ $bit_by, $bit_hist, 'YEAR CONFIGURACION', $year->id, $now, (string) $year ]);

			// El rastro nuevo, al lado del viejo (18 §4). El décimo escritor, y el
			// que ya traía arreglado el sujeto: `$year->id` sale de la fila leída
			// con `findOrFail`, no de `Request::input('id')`.
			//
			// `(string) $year` y no `$year->toArray()`: el modelo se serializa a
			// JSON al convertirlo a cadena, así que lo que entra en `valor_nuevo`
			// ya es la estructura entera y no un `"[object]"`. Es la misma cadena
			// que recibe `bitacoras`, y aquí sí cabe entera —`valor_nuevo` es
			// `json`— mientras que allí va a un `varchar`.
			//
			// **Sin `de()`, y eso es una ausencia con motivo**: la fila vieja ya se
			// perdió doce líneas más arriba, cuando el modelo se fue rellenando
			// campo a campo con `Request::input(..., $year->…)`. Recuperarla exige
			// releer el año antes de tocarlo, y eso es un cambio de forma del
			// método que no es de este lote; queda anotado en aud-4 §5.
			Auditoria::registrar()
				->editar('year_config', (int) $year->id)
				->en(year: (int) $year->id)
				->a((string) $year)
				->guardar();

			return $year;
		} catch (\Exception $e) {
			abort(422, 'Datos incorrectos');
		}
	}

	/**
	 * `PUT years/modelo-evaluacion` — **el colegio elige su modelo de evaluación.**
	 *
	 * Fase 1 de `docs/migracion/35-el-modelo-de-evaluacion-del-colegio.md` §2, y la
	 * ruta que **D24** creó a propósito el 13 sep 2026. Las dos mitades del porqué,
	 * porque ninguna se ve leyendo el código:
	 *
	 * 1. **`putGuardarCambios` nombra veintiuna columnas una a una**, así que una
	 *    columna nueva metida ahí no la escribiría nadie —ni el superusuario— y se
	 *    leería `'ponderado'` en los dieciséis colegios para siempre. Es
	 *    `profesores.tono`, visto antes de cometerlo.
	 * 2. **Y los dieciséis `years/*` de escritura son `auth.personal`**, o sea que
	 *    colgarla de cualquiera de ellos dejaría que **cualquier docente cambiara el
	 *    modelo de evaluación del colegio entero** desde un `PUT` de dos campos.
	 *
	 * Por eso: `auth.personal` en la ruta —que cierra la puerta a alumnos y
	 * acudientes antes de tocar este método— y `puedeEditarPlantillaNotas`
	 * **dentro**, que es la forma de `PlantillaNotasController` y el mismo permiso
	 * (`can_edit_plantilla_notas`, D13: **cero permisos nuevos**). Lo que configura
	 * el colegio, el docente no lo toca.
	 *
	 * > **Y la puerta de al lado está cerrada en `putToggleCambiarValor`**, que
	 * > escribe cualquier columna de `years` con sólo `auth.personal`. Sin aquel
	 * > corte esta ruta sería decorativa. Las dos mitades van juntas o no vale
	 * > ninguna.
	 *
	 * ## Los tres rótulos NO se escriben aquí
	 *
	 * `desempeno_displayname`, `desempenos_displayname` y `genero_desempeno` van en
	 * `putGuardarCambios`, con las seis de unidad y subunidad y con su mismo guard:
	 * son rótulos, y quien puede renombrar «Subunidad» puede renombrar «Desempeño»
	 * (D24). Mezclarlos aquí le pediría a la pantalla de vocabulario un permiso que
	 * no necesita.
	 *
	 * ## Esto NO recalcula ni borra nada — D3, y es la propiedad que no hay que perder
	 *
	 * *Volver atrás es cambiar el enum.* Los desempeños sembrados y las marcas se
	 * quedan en la base y dejan de pintarse; **no se recalcula ni una definitiva**.
	 * Por eso el método hace un `save()` de una columna y nada más: cualquier cosa
	 * que se añada aquí —un recálculo «de cortesía», un borrado de lo que el otro
	 * modelo no usa— rompe la única razón por la que esta fase se puede desplegar a
	 * los dieciséis colegios sin avisar a nadie. Lo sujeta
	 * `ModeloDeEvaluacionDelAnioTest::cambiar_el_modelo_no_recalcula_ni_borra_nada`.
	 *
	 * ## Qué año
	 *
	 * El del cuerpo si viene, y si no **el de la sesión**. El modelo es del año
	 * (D1: un año cerrado conserva el suyo para siempre), así que el identificador
	 * tiene que poder decirse; y el defecto existe porque la pantalla que lo va a
	 * llamar está mirando un año concreto y no tiene por qué repetirlo. Un año que
	 * no existe —o que está en la papelera— es **404** y no un 200 que no escribió
	 * nada.
	 *
	 * ## La respuesta trae `anterior`
	 *
	 * Para que el cambio sea revisable sin abrir la auditoría: la pantalla puede
	 * decir «pasó de ponderado a competencias» con lo que ya tiene. Y trae los tres
	 * rótulos porque quien acaba de encender el modelo los va a pintar en la misma
	 * pantalla, y así no hace una segunda llamada.
	 */
	public function putModeloEvaluacion()
	{
		$user = User::fromToken();

		Autoriza::exigir(
			Autoriza::puedeEditarPlantillaNotas($user),
			'No tiene permiso para cambiar el modelo de evaluación del colegio.'
		);

		// **Las DOS políticas del año, y cada una es opcional** (14 sep 2026).
		//
		// Hasta hoy esta ruta sólo escribía `modelo_evaluacion`. Entra
		// `reparto_subunidades` —la Entrega 5— **aquí y no en una ruta propia**, por
		// decisión de Joseth: es otra política de evaluación del mismo año, con el
		// mismo dueño (`can_edit_plantilla_notas`) y en la misma pantalla de
		// configuración. Una ruta nueva en este repositorio es una decisión que mueve
		// el contador y tres snapshots; ésta no mueve ninguno.
		//
		// **Cada campo que viene se valida y se escribe; el que no viene no se toca.**
		// Es la §68 —«un campo que no se manda no es un campo que no cambia»— aplicada
		// al revés y a propósito: aquí lo correcto ES no tocarlo, porque son dos
		// políticas independientes y la pantalla puede cambiar una sola.
		$columnas = [
			'modelo_evaluacion' => Year::MODELOS_DE_EVALUACION,
			'reparto_subunidades' => Year::REPARTOS_DE_SUBUNIDADES,
		];

		$pedidos = [];

		foreach ($columnas as $campo => $validos) {
			if (! Request::has($campo)) {
				continue;
			}

			$valor = Request::input($campo);

			// El `enum` de MySQL rechazaría el valor raro, pero **con el `sql_mode` de
			// estos servidores no lanza: guarda la cadena vacía y devuelve 200**, que es
			// la misma familia de `frases_asignatura` cortando a los 255. Las listas viven
			// en el modelo y un test comprueba que dicen lo mismo que las columnas.
			if (! is_string($valor) || ! in_array($valor, $validos, true)) {
				abort(422, "`{$campo}` tiene que ser ".implode(' o ', $validos).'.');
			}

			$pedidos[$campo] = $valor;
		}

		// **Sin ningún campo NO es un 200 vacío**, que sería la familia de
		// `tools/respuestas-que-mienten.py`: quien la reciba creería que guardó algo.
		// Antes esto salía por el 422 de `modelo_evaluacion` cuando faltaba; ahora que
		// los dos son opcionales, hace falta decirlo.
		if ($pedidos === []) {
			abort(422, 'Hace falta `modelo_evaluacion` o `reparto_subunidades`.');
		}

		$year_id = Request::input('year_id', $user->year_id ?? null);

		if (! is_numeric($year_id)) {
			abort(422, 'Hace falta `year_id` y la sesión no trae ninguno.');
		}

		// `findOrFail` y no una consulta cruda: el modelo lleva `SoftDeletes`, así
		// que un año en la papelera es 404 aquí. Escribirle la configuración a un
		// año borrado no le sirve a nadie y reaparecería con `years/restore`.
		$year = Year::findOrFail((int) $year_id);

		// **El aviso va AQUÍ: después de resolver el año y antes de tocar la fila.**
		// Se lee `$year->reparto_subunidades` mientras todavía dice lo de antes; el
		// bucle de abajo lo pisa.
		if (isset($pedidos['reparto_subunidades'])) {
			$this->avisarDeLoQueRecalcula(
				(int) $year->id,
				(string) $year->reparto_subunidades,
				$pedidos['reparto_subunidades']
			);
		}

		$antes = [];

		// **El renglón del rastro se arma AQUÍ, en la vuelta que tiene las dos
		// mitades a la vez**, y no en un segundo bucle sobre `$pedidos` que vuelva a
		// buscar `$antes[$campo]`. Larastan nivel 7 no puede demostrar que las dos
		// listas tengan las mismas claves —`offsetAccess.notFound`— y tiene razón en
		// no poder: hoy las tienen porque las llena el mismo bucle, y eso es un
		// invariante que no está escrito en ninguna parte. Con el «de» y el «a»
		// saliendo de la misma iteración no hay nada que descuadrar.
		$resumen = [];

		foreach ($pedidos as $campo => $valor) {
			$anteriorDelCampo = $year->{$campo};

			$antes[$campo] = $anteriorDelCampo;
			$resumen[] = "{$campo}: {$anteriorDelCampo} → {$valor}";

			$year->{$campo} = $valor;
		}

		$year->updated_by = $user->user_id;
		$year->save();

		// `anterior` a secas se conserva **sólo cuando se tocó el modelo**, porque es
		// lo que el front ya lee para decir «pasó de ponderado a competencias». Si un
		// día se retira, que sea con el front delante y no de paso.
		$anterior = $antes['modelo_evaluacion'] ?? null;

		// El rastro nuevo, sin el viejo: `bitacoras` tiene diez escritores fijados
		// por un centinela y esto no es uno de ellos. `year_config` es la entidad que
		// ya usa `putGuardarCambios` para lo mismo. `$resumen` viene armado de arriba.
		Auditoria::registrar()
			->editar('year_config', (int) $year->id)
			->en(year: (int) $year->id)
			->de($antes)
			->a($pedidos)
			->resumen('Cambió la configuración de evaluación del año — '.implode(', ', $resumen))
			->guardar();

		return [
			'year_id' => (int) $year->id,
			'modelo_evaluacion' => $year->modelo_evaluacion,
			'reparto_subunidades' => $year->reparto_subunidades,
			'anterior' => $anterior,
			'desempeno_displayname' => $year->desempeno_displayname,
			'desempenos_displayname' => $year->desempenos_displayname,
			'genero_desempeno' => $year->genero_desempeno,
		];
	}

	/**
	 * **422 con el recuento delante, salvo `acepto_recalcular`.** Doc 28 §5.5.
	 *
	 * Cambiar `reparto_subunidades` **cambia las definitivas guardadas del año**, y
	 * hasta el 15 sep 2026 era un clic, un 200 y ningún número. Lo levantó
	 * `myvc_front` preguntando con qué forma escribía su pestaña, y **no era una
	 * decisión que nadie hubiera tomado**: no estaba en el código, ni en la lista de
	 * cinco pendientes que dejó escrita el commit de la D30, ni en ninguna parte. Se
	 * cayó del encargo, como se cayeron las tres altas del año cerrado.
	 *
	 * ## Por qué el silencio era peor que un salto
	 *
	 * Este método **no recalcula nada**: guarda el año y se va. Así que girar el
	 * interruptor no producía un cambio visible de golpe, producía **deriva**: las
	 * definitivas guardadas seguían en el modo viejo mientras las pantallas ya
	 * calculaban con el nuevo, y se iban reescribiendo asignatura a asignatura según
	 * alguien las fuera tocando, durante días. Un salto se ve; una deriva se
	 * descubre en junio.
	 *
	 * Medido en la copia de desarrollo el 15 sep 2026 —año en curso de **un** colegio—:
	 * **8.022 definitivas** en 120 asignaturas, y de las 701 unidades con más de una
	 * subunidad, **333 tienen pesos desiguales**, que son exactamente las que cambian
	 * de resultado. El 47 %.
	 *
	 * ## La cuenta es de lo que SE VA A REESCRIBIR, no de lo que podría cambiar
	 *
	 * Tres decisiones, y las tres mueven el número:
	 *
	 *  1. **Se comparan las definitivas GUARDADAS con lo que darían en el modo
	 *     nuevo**, no los dos modos entre sí. Lo que el colegio va a ver moverse es
	 *     la fila que tiene.
	 *  2. **`manual` y `recuperada` quedan fuera**, porque `DefinitivasDeAsignatura`
	 *     no las reescribe —lo hace en un solo punto, su línea 363— así que contarlas
	 *     sería prometer un cambio que no va a ocurrir. En el año en curso de la
	 *     copia de desarrollo son 16 de 8.022.
	 *  3. **El alcance del boletín independiente va dentro**, con
	 *     `BoletinIndependiente::alcanceCorrelacionado`, que es el mismo que usa el
	 *     servicio que escribe. Sin él, a un alumno marcado se le compararía la
	 *     definitiva de sus unidades propias contra la del grupo y saldría un cambio
	 *     que no existe. Son 10 marcados y 21 unidades propias en esa copia: pocos, y
	 *     por eso mismo el error no se vería.
	 *
	 * Es la forma de `EscalasDeValoracionController::avisarDeLoQueArrastra`, y por lo
	 * mismo que allí: **una cifra que exagera se aprende a ignorar**, y entonces el
	 * aviso deja de avisar.
	 *
	 * **Y si no cambia ninguna, no hay 422**: encender el interruptor en un año sin
	 * notas, o donde todas las unidades tengan sus subunidades al mismo peso, no le
	 * pide confirmación a nadie porque no hay nada que confirmar.
	 */
	private function avisarDeLoQueRecalcula(int $yearId, string $antes, string $despues): void
	{
		// Reenviar el mismo modo que ya tiene no es un cambio. Sin esto, la pantalla
		// que guarda la configuración entera pediría confirmación por tocar otra cosa.
		if ($antes === $despues) {
			return;
		}

		if ($this->aceptaRecalcular()) {
			return;
		}

		$fila = DB::selectOne(
			'SELECT COUNT(*) AS cambian, MAX(ABS(t.dif)) AS salto
			   FROM (
				SELECT CAST(COALESCE(c.suma, 0) AS DECIMAL(7,4)) - nf.nota AS dif
				  FROM notas_finales nf
				  INNER JOIN periodos p ON p.id = nf.periodo_id AND p.deleted_at IS NULL
				  LEFT JOIN (
					SELECT n.alumno_id, u.asignatura_id, u.periodo_id,
					       u.alumno_id AS dueno,
					       SUM('.RepartoDeLaNota::aportacionALaDefinitiva($despues).') AS suma
					  FROM unidades u
					  INNER JOIN subunidades s ON s.unidad_id = u.id AND s.deleted_at IS NULL
					  INNER JOIN notas n ON n.subunidad_id = s.id AND n.deleted_at IS NULL
					  INNER JOIN periodos pu ON pu.id = u.periodo_id AND pu.deleted_at IS NULL
					 WHERE pu.year_id = ? AND u.deleted_at IS NULL
					 GROUP BY n.alumno_id, u.asignatura_id, u.periodo_id, u.alumno_id
				  ) c ON c.alumno_id = nf.alumno_id
				     AND c.asignatura_id = nf.asignatura_id
				     AND c.periodo_id = nf.periodo_id
				     AND c.dueno <=> '.BoletinIndependiente::alcanceCorrelacionado('nf.alumno_id', 'nf').'
				 WHERE p.year_id = ?
				   AND (nf.manual IS NULL OR nf.manual = 0)
				   AND (nf.recuperada IS NULL OR nf.recuperada = 0)
			   ) t
			  WHERE t.dif <> 0',
			[$yearId, $yearId]
		);

		$cambian = (int) ($fila->cambian ?? 0);

		if ($cambian === 0) {
			return;
		}

		// Dos decimales para enseñarlo: la columna es `decimal(7,4)` y un salto
		// escrito como `3.5000` se lee peor que `3.5`. El número que se compara
		// arriba no se recorta — esto es el rótulo, no la cuenta.
		$salto = round((float) $fila->salto, 2);

		abort(response()->json([
			'message' => 'Cambiar el reparto de las subunidades a `'.$despues.'` recalcula '
				.$cambian.' definitivas ya guardadas de ese año, y la que más se mueve cambia '
				.$salto.' puntos. Mande `acepto_recalcular` para cambiarlo igual.',
			'definitivas' => $cambian,
			'salto_mayor' => $salto,
			'de' => $antes,
			'a' => $despues,
			'year_id' => $yearId,
		], 422));
	}

	/**
	 * `acepto_recalcular`, y **sin verdad laxa**: `FILTER_VALIDATE_BOOLEAN` con
	 * `FILTER_NULL_ON_FAILURE`, que es lo que separa `"false"` y `"0"` de un sí.
	 *
	 * Calcado de `EscalasDeValoracionController::acepta` a propósito: son la misma
	 * llave y tienen que abrirse igual. Sin esto, **cualquier cadena** —`"no"`,
	 * `"nunca"`— valdría por «sí» y gobernaría el recálculo de un año entero, que es
	 * justo la familia que persigue `tools/verdad-laxa-que-escribe.py`.
	 */
	private function aceptaRecalcular(): bool
	{
		if (! Request::has('acepto_recalcular')) {
			return false;
		}

		$leido = filter_var(Request::input('acepto_recalcular'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

		if ($leido === null) {
			abort(422, '`acepto_recalcular` tiene que ser verdadero o falso.');
		}

		return $leido;
	}

	public function putSetActual(){
		$user = User::fromToken();

		$year_id 	= 	Request::input('year_id');
		$actual 	= 	(bool) Request::input('can');

		if ($actual) {
			Year::where('actual', true)->update(['actual'=>false]);
		}

		$year = Year::findOrFail($year_id);
		// Era `= 1` a secas, o sea que destildar la casilla marcaba el año como
		// actual y devolvía «Ahora NO es año actual». De ahí salen los años con
		// `actual=1` de más que hay en la base: el front es una casilla por año
		// (`years.html`, ng-false-value="0") y quien la apaga cree que la apagó.
		// Lo que se rompe con eso está en Services\Login::ponerEnElPeriodoActual,
		// que se queda con el PRIMERO de los años actuales y no tiene ORDER BY.
		// Ver docs/migracion/05-codigo-muerto-y-roto.md §28.
		$year->actual = $actual ? 1 : 0;
		$year->save();

		if ($actual) { return 'Ahora es año actual.';
		} else { return 'Ahora NO es año actual';}
	}

	public function putAlumnosCanSeeNotas(){
		$user = User::fromToken();

		$year_id 	= 	Request::input('year_id');
		$can 		= 	(bool) Request::input('can');

		$year = Year::findOrFail($year_id);
		$year->alumnos_can_see_notas = $can;
		$year->save();

		if ($can) { return 'Ahora pueden ver sus notas.';
		} else { return 'Ahora NO pueden ver sus notas';}
	}


	public function putProfesCanEditAlumnos(){
		$user = User::fromToken();

		$year_id 	= 	Request::input('year_id');
		$can 		= 	(bool) Request::input('can');

		$year = Year::findOrFail($year_id);
		$year->profes_can_edit_alumnos = $can;
		$year->save();

		if ($can) { return 'Ahora docentes pueden editar alumnos.';
		} else { return 'Ahora docentes NO pueden editar alumnos';}
	}

	public function putToggleMostrarPuestosEnBoletin(){
		$user = User::fromToken();

		$year_id 	= 	Request::input('year_id');
		$can 		= 	(bool) Request::input('can');

		$year = Year::findOrFail($year_id);
		$year->mostrar_puesto_boletin = $can;
		$year->save();

		if ($can) { return 'Ahora se mostrarán los puestos en el boletín.';
		}else{ return 'Ahora NO se mostrarán los puestos en el boletín';}
		
	}

	public function putToggleMostrarNotaComportEnBoletin(){
		$user = User::fromToken();

		$year_id 	= 	Request::input('year_id');
		$can 		= 	(bool) Request::input('can');

		$year = Year::findOrFail($year_id);
		$year->mostrar_nota_comport_boletin = $can;
		$year->save();

		if ($can) { return 'Ahora se mostrará la nota de comportamiento en el boletín.';
		} else { return 'Ahora NO se mostrarán la nota de comportamiento en el boletín';}
	}


	// Mostrar todas las materias al docente al entrar ignorando el horario
	public function putMostrarTodasMaterias(){
		$user = User::fromToken();

		$year_id 	= 	Request::input('year_id');
		$can 		= 	(bool) Request::input('can');

		$year = Year::findOrFail($year_id);
		$year->show_materias_todas = $can;
		$year->save();

		if ($can) { return 'Le apareceran todas las materias al docente ignorando el horario.';
		}else{ return 'Se mostrarán solo las materias del horario.';}

	}


	public function putToggleMostrarAnioPasadoEnBoletin(){
		$user = User::fromToken();

		$year_id 	= 	Request::input('year_id');
		$can 		= 	(bool) Request::input('can');

		$year = Year::findOrFail($year_id);
		$year->year_pasado_en_bol = $can;
		$year->save();

		if ($can) { return 'Ahora se mostrarán indicadores perdidos del año pasado en el boletín.';
		}else{ return 'Ahora NO se mostrarán indicadores perdidos del año pasado en el boletín';}
		
	}

	public function putToggleSoloValorativas(){
		$user = User::fromToken();

		$year_id 	= 	Request::input('year_id');
		$can 		= 	(bool) Request::input('can');

		$year = Year::findOrFail($year_id);
		$year->solo_escalas_valorativas = $can;
		$year->save();

		if ($can) {
			return 'Ahora se mostrarán SOLO cualitativo.';
		} else {
			return 'Ahora se mostrarán cantitativo (números de las notas).';
		}
	}

	public function putToggleCambiarValor(){
		$user 		= User::fromToken();
		$now 		= Carbon::now('America/Bogota');

		$year_id 	= 	Request::input('year_id');
		$valor 		= 	Request::input('valor');
		$campo 		= 	Request::input('campo');

		// `actual` no, y es la única excluida. Esta ruta es el «guardar un campo
		// suelto» de la rejilla y escribe cualquier columna que exista —eso no es un
		// agujero: quien pasa `auth.personal` ya las escribe todas por
		// `years/guardar-cambios`—, pero `actual` tiene invariante (uno solo) y una
		// ruta propia que lo mantiene, `years/set-actual`, que apaga a los demás.
		//
		// Por aquí se podía encender un segundo año actual, y no se queda en la fila:
		// `Services\Login::ponerEnElPeriodoActual` hace `WHERE actual=1` y se queda con
		// el primero SIN `ORDER BY`, o sea el de id más bajo. Medido: encender 2018
		// con 2025 encendido muda a todo el colegio a 2018 en el siguiente inicio de
		// sesión. Es la §28 alcanzada por otra puerta. §94.
		if (strtolower(trim((string) $campo)) === 'actual') {
			abort(422, 'El año actual se cambia con years/set-actual, que apaga a los demás.');
		}

		// **Y `modelo_evaluacion` tampoco, desde el 13 sep 2026.** Es la segunda
		// excluida y lo es por un motivo distinto del de `actual`: aquélla tiene un
		// invariante de fila —uno solo encendido—; ésta tiene **dueño**.
		//
		// D24 le dio ruta propia —`PUT years/modelo-evaluacion`, con
		// `can_edit_plantilla_notas` dentro— justamente para que **no la cambie
		// cualquier docente**, y esta ruta es `auth.personal`: sin este corte, la
		// decisión se salta aquí en una línea y no lo diría nada.
		//
		// Y fíjese en lo que eso le hace al comentario de arriba: el genérico se
		// defendía con *«quien pasa `auth.personal` ya las escribe todas por
		// `years/guardar-cambios`»*, y **con esta columna esa frase deja de ser
		// cierta** — es justo la que no está en las veintiuna de aquel método, a
		// propósito. El día que entre otra columna de `years` que no pueda escribir
		// todo el personal, su sitio es esta lista.
		// **Y el 14 sep 2026 entró la segunda, que es justo lo que el párrafo de
		// arriba decía que iba a pasar**: `reparto_subunidades`, la Entrega 5. Por eso
		// esto deja de ser un `if` por columna y pasa a ser una lista — con dos, copiar
		// el bloque es cómo se olvida la tercera.
		//
		// Las dos se escriben por `PUT years/modelo-evaluacion`, que exige
		// `can_edit_plantilla_notas` DENTRO. Esta ruta es `auth.personal`: sin este
		// corte, las dos decisiones se saltan aquí en una línea y no lo diría nada.
		$conDueno = [
			'modelo_evaluacion' => 'El modelo de evaluación',
			'reparto_subunidades' => 'El reparto de las subunidades',
		];

		$normalizado = strtolower(trim((string) $campo));

		if (isset($conDueno[$normalizado])) {
			abort(422, $conDueno[$normalizado].' se cambia con years/modelo-evaluacion, '
				.'que exige el permiso de la plantilla de notas.');
		}

		// **Y los dos títulos del certificado, desde el 15 sep 2026 (doc 38), que es un
		// TERCER motivo y por eso es una lista aparte y no dos renglones más arriba.**
		//
		// No tienen dueño: los escribe `PUT certificados/encabezado` con el mismo
		// `auth.personal` que esta ruta, así que cortarlos aquí **no le quita el campo a
		// nadie que pudiera escribirlo**. Lo que tienen es un **invariante de valor** —un
		// título no puede quedarse vacío, porque lo que se imprimiría entonces es una
		// cabecera en blanco en un papel firmado— y ese invariante lo mantiene aquella
		// ruta: rechaza la cadena vacía y el tope de 255.
		//
		// Esta ruta escribe `$valor` tal cual llega. Sin este corte, la validación de
		// allí sería un cartel de «no hagas X» con la puerta de al lado abierta, que es
		// exactamente la forma de agujero que este repositorio lleva contando: *un
		// invariante que se pide por escrito se salta; uno que se cierra por mecanismo,
		// no*. Y no es teórico — es la lección de `modelo_evaluacion` aplicada antes de
		// cometerla: al ponerle una regla a una columna se repasan **todos** los caminos
		// que escriben esa tabla, no sólo el que se está tocando.
		$conInvarianteDeValor = [
			'titulo_certificado_final' => 'El título del certificado final',
			'titulo_certificado_periodos' => 'El título del certificado por periodos',
		];

		if (isset($conInvarianteDeValor[$normalizado])) {
			abort(422, $conInvarianteDeValor[$normalizado].' se cambia con certificados/encabezado, '
				.'que comprueba que no quede vacío.');
		}

		/*
		 * **Opción A del [09 §13], y ésta llega ANTES que su pantalla** — que es lo que
		 * la hace distinta de las otras dos y por lo que va en su propio commit.
		 *
		 * Medido el 1 sep 2026: **ningún cliente llama a esta ruta**. Cero ficheros de
		 * código en `myvc_front`, `myvc_front_2` y `myvc_flutter`. Los cinco interruptores
		 * hermanos del año —`toggle-solo-valorativas`, `toggle-ignorar-notas-perdidas`,
		 * `toggle-mostrar-puestos-en-boletin`, `toggle-mostrar-nota-comport-en-boletin` y
		 * `toggle-mostrar-anio-pasado-en-boletin`— tienen **ruta propia** y **ninguno
		 * tiene este defecto**: devuelven una frase fija que no sale de `$res`. El
		 * genérico es la excepción, no la norma.
		 *
		 * Así que esto **no arregla una pantalla rota: impide que nazca rota**. El plan
		 * daba por hecho que `puestos_con_bol_independiente` (§7 del 19) se guardaría por
		 * aquí y que un rector leería «No guardado» al apagarlo; medido, esa columna **no
		 * tiene escritor en ningún front** —las cuatro pantallas de puestos y la cabecera
		 * del boletín final sólo la leen— y lo probable es que nazca con su propia ruta
		 * como sus cinco hermanos. La urgencia no era la que decía el plan; el momento
		 * barato, sí.
		 */
		FilaQueSeVaAEscribir::exigir('years', 'id', $year_id, 'Ese año lectivo');

		$consulta 	= 'UPDATE years SET '.ColumnaSegura::exigir('years', $campo).'=:valor, updated_by=:modificador, updated_at=:fecha WHERE id=:year_id';
		$datos 		= [ ':valor' => $valor, ':modificador' => $user->user_id, ':fecha' => $now, ':year_id' => $year_id ];

		// El año existe —se acaba de comprobar—, así que llegar aquí es haber guardado,
		// cambiara algo o no. `DB::update` devuelve filas AFECTADAS y MySQL da 0 cuando el
		// valor ya era ése: apagar un interruptor que ya estaba apagado contestaba
		// «No guardado» con 200 y el estado correcto.
		DB::update($consulta, $datos);

		return 'Guardado';
	}


	public function putToggleIgnorarNotasPerdidas(){
		$user = User::fromToken();

		$year_id 	= 	Request::input('year_id');
		$can 		= 	(bool) Request::input('can');

		$year = Year::findOrFail($year_id);
		$year->si_recupera_materia_recup_indicador = $can;
		$year->save();

		if ($can) { return 'Ahora se ignorarán las notas perdidas si gana la materia.';
		} else { return 'Ahora NO se ignorarán las notas perdidas si gana la materia';}
	}

	public function deleteDelete($id)
	{
		$user = User::fromToken();
		
		$year = Year::findOrFail($id);

		// Un año en la papelera no puede ser el año actual del colegio, y hasta
		// hoy podía: en la base hay uno así —2026, borrado, con `actual=1`—.
		// Hoy no se ve, porque todo lo que lee el año actual filtra `deleted_at`;
		// la trampa es `years/restore/{id}`, que lo devolvería encendido junto al
		// que lo esté, y ahí `Login::ponerEnElPeriodoActual` se queda con el
		// PRIMERO de los dos y no tiene ORDER BY. Además, `putSetActual` apaga a
		// los demás con Eloquent, que no ve los borrados: el de la papelera se
		// libraba de todas las apagadas.
		//
		// No cambia nada de lo que hoy calcula nadie —para todos los lectores ese
		// año ya no estaba—: pone en la fila lo que todos ya deducían.
		// Ver docs/migracion/05-codigo-muerto-y-roto.md §28.
		$year->actual = 0;
		$year->save();

		$year->delete();

		return $year;
	}

	public function deleteDestroy($id)
	{
		$user = User::fromToken();

		// Se llama "destroy" pero hace forceDelete: borrado físico de un año, que
		// por las FK ON DELETE CASCADE arrastra 59 tablas hasta 7 saltos de
		// profundidad. Es el borrado de mayor alcance del sistema y no tenía
		// ninguna comprobación más allá de tener un token.
		Autoriza::exigir(Autoriza::esSuperusuario($user),
			'Solo un superusuario puede eliminar un año definitivamente.');

		$year = Year::onlyTrashed()->findOrFail($id);
		$year->forceDelete();

		return $year;
	}

	/*
	 * Restaurar pide lo mismo que borrar definitivamente, y hasta el 22 ago 2026
	 * no pedía nada.
	 *
	 * Cada operación de la papelera es una pareja, y el 21 ago se cerró **una
	 * mitad de cada una**: `forcedelete` quedó anclado a superusuario y `restore`,
	 * en el mismo controlador, se quedó como estaba — bastaba `auth.personal`, o
	 * sea cualquiera de los 51 profesores. La cabecera de `Autoriza` nombra los
	 * cinco sitios de los que venía aquello: grupos, perfiles, profesores, years y
	 * editnota. Son los mismos cinco.
	 *
	 * El criterio es el del gemelo destructivo y no uno nuevo, a propósito: la
	 * regla de `Autoriza` es que crear un rol no regale permisos, y
	 * `esAdministrativo` incluiría al `Secretario` del día que exista sin que
	 * nadie lo haya pedido. Hoy los dos criterios son las mismas diez personas
	 * —`is_superuser` y el rol `Admin` coinciden fila por fila, §28.4— y la
	 * pantalla de papelera del front ya se enseña sólo con `hasRoleOrPerm('admin')`,
	 * así que **nadie pierde un botón que hoy vea**. Subirlo a `esAdministrativo`
	 * es una palabra el día que se decida; está anotado en 09 §5.
	 */
	public function putRestore($id)
	{
		Autoriza::exigir(Autoriza::esSuperusuario(User::fromToken()),
			'No tienes permiso para restaurar años.');

		$year = Year::onlyTrashed()->findOrFail($id);

		$year->restore();
		return $year;
	}


	public function getTrashed()
	{
		$years = Year::onlyTrashed()->get();
		return $years;
	}
}
