<?php namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Request;

use App\User;
use App\Models\Year;
use App\Services\Auditoria;
use App\Support\Autoriza;
use App\Services\BoletinIndependiente;
use App\Services\DefinitivasDeAsignatura;
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
use App\Support\CierreDeLoNoCalificado;
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
		/*
		 * AQUI HABIA UN `$user = User::fromToken();` y se fue con el filtro por usuario de las imagenes
		 * (mas abajo): era su unico uso. No hace de guardia -- `fromToken()` devuelve null en vez de
		 * cortar, y quien corta es el middleware `auth.token` que envuelve a todo `routes/api.php`.
		 */
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

		/*
		 * LAS PUBLICADAS SON DEL COLEGIO, NO DE QUIEN ENTRA  *(19 sep 2026, pedido)*.
		 *
		 * Aqui ponia `WHERE user_id=? and publica=true`, y era la UNICA de las seis consultas de
		 * imagenes del backend que filtraba por usuario. Las otras cinco --`ComportamientoController`
		 * (dos), `ObservadorHorizontalController`, `InformesController` y el de portadas-- piden
		 * `publica=true` a secas, porque para eso se publica una imagen: para que la vea el resto del
		 * personal del colegio.
		 *
		 * El efecto del filtro se veia en la configuracion de certificados: el membrete que subia el
		 * rector NO le salia a la secretaria, y la pantalla no tenia forma de explicar por que faltaba.
		 *
		 * **NO SE AÑADE `deleted_at is null`**, y eso es a proposito aunque las otras cinco si lo
		 * lleven: una plantilla de certificado puede estar apuntando a una imagen de la papelera, y el
		 * dia que esta consulta deje de devolverla el desplegable de `app2` enseñaria «(sin imagen)»
		 * para una plantilla que SI tiene imagen puesta -- y guardar desde ahi la borraria de verdad,
		 * porque `putUpdate` pone la columna a null cuando no le mandan la imagen. El front ya las
		 * marca y las manda al final de la lista; ver `comunes/selector-imagen` en `myvc_front`.
		 */
		$consulta = 'SELECT * FROM images WHERE publica=true';
		$imagenes = DB::select($consulta);



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
			// La tercera hermana, del 20 sep 2026. Se copia como las otras dos: el año
			// que se abre hereda el título que el colegio venía usando, no el defecto.
			$year->titulo_constancia_estudio     = $pasado->titulo_constancia_estudio;
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
			// Y la sexta, la fase 4 del doc 43 (D3, 20 sep 2026): qué pasa al cerrar
			// con lo que nadie calificó. Se hereda por lo mismo que las cinco de
			// arriba —es una decisión del SIEE del colegio, no algo que se vuelva a
			// tomar cada enero— y el defecto que reaparecería tiene pinta de decisión:
			// el colegio que eligió `fuera` amanecería en `cero` y **el primer periodo
			// que cerrara le pondría ceros a todo lo que sus docentes no hubieran
			// calificado**, que es justo lo que había decidido no hacer. El centinela
			// del año nuevo es el que no deja olvidar esta línea.
			$year->cierre_sin_calificar 		 = $pasado->cierre_sin_calificar;
			// Y la séptima, del 22 sep 2026: si los docentes de este colegio editan lo
			// que el colegio puso en la plantilla. Se hereda por lo mismo que las seis de
			// arriba, y el defecto que reaparecería tiene pinta de decisión — es el caso
			// más claro de todos: el colegio que abrió la mano amanecería con el candado
			// puesto cada enero, y lo que vería es **exactamente lo que reportaron el 21
			// sep**, sus docentes sin poder tocar el porcentaje de la unidad que acaban de
			// sembrar. Un 403 en enero, sin nadie que lo haya decidido y con el rastro
			// apuntando al candado en vez de a esta línea que faltaría.
			$year->profes_pueden_editar_plantilla = $pasado->profes_pueden_editar_plantilla;
			// El año nuevo hereda la elección del anterior por lo mismo que las de
			// arriba: es una decisión del SIEE del colegio, no algo que se vuelva a
			// tomar cada enero. Sin esta línea, un colegio que imprime sin número
			// vuelve a imprimirlo con número al abrir el año y **nadie lo pide** —se
			// descubre en el primer boletín que sale a casa.
			$year->mostrar_nota_numerica_boletin = $pasado->mostrar_nota_numerica_boletin;
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

			// LA SELECCIÓN DE CAMPOS DEL FORMULARIO DE INSCRIPCIÓN SÍ SE COPIA, y su
			// tabla hermana `ordenes_inscripcion` NO. Las dos nacieron el mismo día y
			// están decididas al revés, así que el porqué va aquí y no en dos sitios:
			//
			//   config_formulario_   SE COPIA   Es lo que el colegio eligió que pida su
			//   inscripcion                     formulario. No copiarla devuelve la
			//                                   selección al defecto cada enero y obliga
			//                                   a reconfigurarla — que es literalmente el
			//                                   fallo que pagó `desempenos_por_defecto`
			//                                   el 13 sep 2026.
			//
			//   ordenes_             NO SE      Cada fila es un PAPEL IMPRESO de la
			//   inscripcion          COPIA      campaña de ese año, con su código, su
			//                                   cobro y quién lo vendió. Copiarlas
			//                                   fabricaría códigos de formularios que
			//                                   nadie imprimió y cobros que nadie hizo.
			//                                   Va declarada en `DATOS_DEL_ANIO`.
			//
			// Lo que las separa es lo mismo que separa la competencia de la rúbrica:
			// una es *lo que el colegio escribió para decir cómo trabaja*, la otra es
			// *lo que ocurrió porque ese año se vivió*.
			$config_form_ant = DB::select('SELECT campos FROM config_formulario_inscripcion WHERE year_id=?;', [$pasado->id]);

			if (count($config_form_ant) > 0) {
				DB::insert('INSERT INTO config_formulario_inscripcion(year_id, campos, created_by, created_at, updated_at) VALUES(?,?,?,?,?)',
					[ $year->id, $config_form_ant[0]->campos, $user->user_id, $ahora, $ahora ]);
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

		/// Y AL FINAL, EL PLAN DE ÁREA: `desempenos_por_defecto`.
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
			$this->copiarLosJefesDeArea($pasado, $year, $user->user_id, $ahora);
			$this->copiarLaPlantillaDelCompromiso($pasado, $year, $user->user_id, $ahora);
		}

		return $year;
	}


	/**
	 * La configuración y los textos del compromiso académico — **el encargo es esto**.
	 *
	 * Diseño en `myvc_front/COMPROMISOS-ACADEMICOS.md` §8. La petición fue literal:
	 * *«que no tengan que estar seleccionando y editando las secciones cada
	 * periodo»*, y esta línea es la mitad de la respuesta. La otra mitad —que sea
	 * del año y no del periodo— la fija el esquema.
	 *
	 * Se hereda todo, y la regla para saberlo es la que ya separó
	 * `config_formulario_inscripcion` de `ordenes_inscripcion` unas líneas más
	 * arriba: **lo que el colegio escribió para decir cómo trabaja se copia; lo que
	 * ocurrió porque ese año se vivió, no**. Las dos tablas de aquí son de las
	 * primeras. Los compromisos de los alumnos, que son de las segundas, no las
	 * toca este método y tendrán que ir declarados en `DATOS_DEL_ANIO` el día que
	 * existan.
	 *
	 * **Y los firmantes de aquí SÍ se heredan, al revés que `years.firmantes_acta`.**
	 * Merece la línea porque parecen lo mismo y están decididos al contrario:
	 * aquélla guarda **personas** —nombre, cargo y cédula— y por eso se confirma
	 * cada año (decisión de Joseth, 31 ago 2026: *un acta firmada por quien ya no
	 * está es peor que un acta sin firmantes*). `config_compromiso.firmantes`
	 * guarda **rótulos de cargo**: «Coordinación Académica», «Acudiente». Un cargo
	 * no se va del colegio en diciembre, y los nombres que van debajo salen de
	 * `Year::datos()`, que ya se confirma por su lado.
	 *
	 * Si el colegio nunca configuró nada, no hay filas y no se copia nada: la
	 * ausencia significa «los defectos de `PlantillaDelCompromiso`», no «un
	 * documento en blanco». Es lo mismo que hace `config_formulario_inscripcion`.
	 */
	private function copiarLaPlantillaDelCompromiso(Year $pasado, Year $year, int $user_id, Carbon $ahora): void
	{
		$config = DB::select('SELECT regla, corte, primaria_activa, primaria_materia_1_id, primaria_materia_2_id,'
			.' plazo_label, plazo_dias, dias_reclamacion, titulo, subtitulo, muestra_escudo, muestra_foto,'
			.' muestra_resolucion, col_periodos, col_falta, firmantes, canal_papel, canal_push, canal_correo,'
			.' firma_digital, pide_segunda_firma FROM config_compromiso WHERE year_id=?;', [$pasado->id]);

		if (count($config) > 0) {
			$c = $config[0];

			/*
			 * **Las dos materias del parágrafo de primaria se copian tal cual, y pueden
			 * apuntar a una materia en la papelera.** Es el mismo caso que el jefe de área
			 * de aquí abajo y se resuelve igual: la clave ajena la acepta porque `materias`
			 * tiene borrado lógico, no se filtra —filtrar sería decidir por el colegio— y
			 * se deja dicho en el log, porque la diferencia entre «el colegio no usaba el
			 * parágrafo» y «lo usaba sobre una materia que ya no existe» no se reconstruye
			 * después si nadie la escribe.
			 *
			 * `materias` no lleva `year_id`: es del colegio, no del año. Por eso el id
			 * sigue valiendo en el año nuevo y no hay que remapear nada, al revés que
			 * `desempenos_por_defecto.periodo_id`.
			 */
			DB::insert('INSERT INTO config_compromiso(year_id, regla, corte, primaria_activa, primaria_materia_1_id,'
				.' primaria_materia_2_id, plazo_label, plazo_dias, dias_reclamacion, titulo, subtitulo,'
				.' muestra_escudo, muestra_foto, muestra_resolucion, col_periodos, col_falta, firmantes,'
				.' canal_papel, canal_push, canal_correo, firma_digital, pide_segunda_firma,'
				.' created_by, created_at, updated_at)'
				.' VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
				[
					$year->id, $c->regla, $c->corte, $c->primaria_activa, $c->primaria_materia_1_id,
					$c->primaria_materia_2_id, $c->plazo_label, $c->plazo_dias, $c->dias_reclamacion,
					$c->titulo, $c->subtitulo, $c->muestra_escudo, $c->muestra_foto, $c->muestra_resolucion,
					$c->col_periodos, $c->col_falta, $c->firmantes, $c->canal_papel, $c->canal_push,
					$c->canal_correo, $c->firma_digital, $c->pide_segunda_firma,
					$user_id, $ahora, $ahora,
				]);

			if ((int) $c->primaria_activa === 1) {
				$vivas = DB::select('SELECT COUNT(*) AS cuantas FROM materias WHERE id IN (?,?) AND deleted_at is null;',
					[$c->primaria_materia_1_id, $c->primaria_materia_2_id]);

				if ((int) $vivas[0]->cuantas < 2) {
					Log::warning('Crear el año '.$year->year.' heredó el parágrafo de primaria del compromiso apuntando a una materia que ya no existe.', [
						'year_id_nuevo'  => $year->id,
						'year_id_pasado' => $pasado->id,
						'materias'       => [$c->primaria_materia_1_id, $c->primaria_materia_2_id],
					]);
				}
			}
		}

		/*
		 * Los bloques van **por `orden` y luego por `id`**, y el orden importa: es el
		 * orden en que se imprimen. Un `ORDER BY id` a secas devolvería el orden en que
		 * el colegio los creó, que después de un arrastre ya no es el orden del papel.
		 */
		$bloques = DB::select('SELECT clave, orden, activo, titulo, cuerpo FROM compromiso_bloques'
			.' WHERE year_id=? ORDER BY orden, id;', [$pasado->id]);

		foreach ($bloques as $bloque) {
			DB::insert('INSERT INTO compromiso_bloques(year_id, clave, orden, activo, titulo, cuerpo,'
				.' created_by, created_at, updated_at) VALUES(?,?,?,?,?,?,?,?,?)',
				[
					$year->id, $bloque->clave, $bloque->orden, $bloque->activo,
					$bloque->titulo, $bloque->cuerpo, $user_id, $ahora, $ahora,
				]);
		}
	}


	/**
	 * Los jefes de área del año anterior — **decisión de Joseth, 17 sep 2026**.
	 *
	 * La pregunta la levantó `CentinelaDeLasTablasDelAnioNuevoTest` el día que entró
	 * `jefes_de_area`: la tabla lleva `year_id`, así que alguien tenía que decir si
	 * el año nuevo la hereda. **Hereda**, por el precedente del plan de área de aquí
	 * arriba: la estructura académica se repite con retoques y rehacer las 22 filas
	 * cada enero es trabajo que nadie quiere.
	 *
	 * **Y NO se filtra por contrato, que era la otra salida y se descartó.** El año
	 * nuevo puede nacer con un jefe que todavía no ha renovado —los contratos
	 * tampoco se copian, precisamente porque se firman cada año— y eso es aceptado:
	 * se corrige desde la pantalla, que es un clic, y el orden en que el colegio crea
	 * el año y firma los contratos no debería cambiar quién sale de jefe.
	 *
	 * **Mucho más simple que `copiarElPlanDeArea`, y por una razón de esquema**: esta
	 * tabla sólo tiene `year_id` propio. `area_id` y `profesor_id` apuntan a tablas
	 * **globales al colegio** —`areas` no tiene año, y `profesores` tampoco—, así que
	 * los ids siguen valiendo en el año nuevo y no hay nada que remapear. El plan de
	 * área necesita todo aquel baile porque su `periodo_id` sí cambia de año.
	 *
	 * Las columnas van nombradas y no `*`, por lo mismo que el método de al lado: un
	 * `*` funcionaría hoy y se callaría la próxima vez que esta tabla gane una
	 * columna.
	 */
	private function copiarLosJefesDeArea(Year $pasado, Year $year, int $user_id, Carbon $ahora): void
	{
		$jefes = DB::select('SELECT area_id, profesor_id FROM jefes_de_area WHERE year_id=? ORDER BY id;', [$pasado->id]);

		$en_papelera = [];

		foreach ($jefes as $jefe) {
			DB::insert('INSERT INTO jefes_de_area(year_id, area_id, profesor_id, created_by, created_at, updated_at) VALUES(?,?,?,?,?,?)',
				[$year->id, $jefe->area_id, $jefe->profesor_id, $user_id, $ahora, $ahora]);
		}

		/*
		 * **Un jefe en la papelera se copia igual, y se dice.** Es la consecuencia
		 * de no filtrar: `profesores` tiene borrado lógico, así que la fila sigue ahí
		 * y la clave ajena la acepta, pero ese docente ya no sale en ninguna lista y
		 * su área abriría el año con un jefe que la pantalla no sabe pintar.
		 *
		 * No se salta —saltárselo sería implementar por la puerta de atrás el filtro
		 * que se descartó— pero tampoco se calla: la diferencia entre «el colegio no
		 * tenía jefes» y «los tenía y uno ya no existe» no se puede reconstruir
		 * después si nadie la escribe.
		 */
		foreach ($jefes as $jefe) {
			$vivo = DB::selectOne('SELECT id FROM profesores WHERE id=? AND deleted_at is null;', [$jefe->profesor_id]);

			if ($vivo === null) {
				$en_papelera[] = (int) $jefe->area_id;
			}
		}

		if ($en_papelera !== []) {
			Log::warning('Crear el año '.$year->year.' heredó '.count($en_papelera).' jefaturas de área cuyo docente está en la papelera.', [
				'year_id_nuevo'  => $year->id,
				'year_id_pasado' => $pasado->id,
				'areas'          => $en_papelera,
			]);
		}
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
	 * El **plan de área** del colegio viaja al año nuevo: `desempenos_por_defecto`.
	 *
	 * Es la tabla por año que trajo la Fase 3 del
	 * [35](../../../docs/migracion/35-el-modelo-de-evaluacion-del-colegio.md), y
	 * **nadie la copiaba**. Sin esto, el colegio que escribe su plan de área en
	 * 2026 lo encuentra **vacío en enero de 2027** y lo reescribe entero: es
	 * exactamente la §1.bis del doc 28 —las subunidades por defecto sin copiarse
	 * durante años— por la misma puerta y con la misma cara, o sea **ninguna**. No
	 * rompe nada el día que pasa, no deja una línea en ningún log, y se nota en
	 * enero, cuando ya no hay quien lo relacione con haber creado un año.
	 *
	 * > **Eran DOS tablas hasta el 17 sep 2026**, y la otra —`competencias`, con su
	 * > tabla de equivalencias para remapear `desempenos_por_defecto.competencia_id`—
	 * > se fue entera con el modelo plano
	 * > ([39](../../../docs/migracion/39-el-modelo-plano-por-competencias.md)): el
	 * > boletín no agrupa por competencia, así que no hay padre que copiar ni que
	 * > remapear. **Este método fue lo primero que se rompió al borrarla** —`POST
	 * > years` es un `SELECT … FROM competencias` en la línea 1— y ése es el aviso
	 * > que deja escrito: una tabla por año no se borra sin mirar quién la copia.
	 *
	 * ## Lo que hace cara a esta copia: la fila apunta a un periodo
	 *
	 * Las escalas, las frases y los requisitos son filas sueltas: se copian con un
	 * `INSERT` y ya está. Aquí no. **El desempeño cuelga de un periodo**
	 * (`periodo_id`, NOT NULL), y los periodos nacen con **ids nuevos** en el año
	 * nuevo. Copiar sin remapear deja cada fila apuntando al periodo del **año
	 * viejo**:
	 *
	 *   - la clave ajena lo acepta —`periodos.id` sigue existiendo, sólo que es de
	 *     otro año—, así que no hay error;
	 *   - `GET desempenos` las enseña igual, porque filtra por `d.year_id`; y
	 *   - el boletín no encuentra ni una, porque lee por `year_id` **y**
	 *     `periodo_id` a la vez.
	 *
	 * O sea: 200, pantalla llena, boletín vacío. Es la forma de fallar de esta
	 * casa, y por eso el orden de aquí abajo —los periodos primero, con su tabla de
	 * equivalencias por número— no es una preferencia de estilo.
	 *
	 * ## El ALCANCE viaja con la fila
	 *
	 * `materia_id` y `grado_id` dicen **a quién va dirigida** la fila, igual que
	 * `nivel_educativo_id` y `materia_id` en `unidades_por_defecto`, y **`NULL`
	 * significa «a todos»** en los dos. Así que no copiarlos no sería «se pierde
	 * una columna»: sería **la fila escapándose de su alcance** — el desempeño que
	 * el colegio escribió para 6.º convertido en desempeño de todo el colegio, con
	 * un 200 y sin un error. Es el fallo que ya pagó la plantilla en
	 * `2026_09_05_200000_alcance_de_la_plantilla`, y aquí se evita antes.
	 *
	 * ## La referencia que puede no tener destino: el periodo, y se salta la fila
	 *
	 * `periodo_id` es NOT NULL, así que no hay «sin periodo» que valga. Se busca el
	 * periodo del año nuevo **con el mismo número**, que es la misma equivalencia
	 * que ya usan los dos interruptores de `crearLosPeriodos` —y, desde el 17 sep,
	 * `DesempenosController::putCopiarPlantilla`—. Si el año viejo tenía un quinto
	 * periodo —o el suyo está en la papelera—, esa fila **no tiene a dónde ir** y se
	 * queda. Ponerla en el periodo 4 sería inventarse el plan del colegio; dejarle
	 * el id viejo sería escribir a mano el fallo que este método existe para evitar.
	 * Se cuenta y se dice en el log, porque un salto silencioso es media línea de
	 * código y una tarde de enero.
	 *
	 * ## `created_at` sí se pone
	 *
	 * Con `$ahora`, como las subunidades y los requisitos. La auditoría de las
	 * nueve tablas que copia este método (13 sep 2026) encontró que la copia de
	 * `unidades_por_defecto` **no lo pone** y esas filas nacen sin fecha; no se
	 * arregla aquí porque mueve filas que este cambio no viene a mover, pero la
	 * tabla nueva **no hereda el descuido**.
	 *
	 * @param list<Periodo> $periodos_nuevos los cuatro que acaba de crear `crearLosPeriodos`
	 */
	private function copiarElPlanDeArea(Year $pasado, Year $year, array $periodos_nuevos, int $user_id, Carbon $ahora): void
	{
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

		/// Y EL PLAN DE ÁREA
		//
		// Las columnas van nombradas en el `SELECT` por lo mismo que en el `INSERT`:
		// un `*` aquí seguiría funcionando hoy y volvería a callarse la próxima vez
		// que alguien añada una columna a esta tabla. Es la regla de
		// `DesempenosController` —«nunca `SELECT *`»— y la de la copia de
		// `unidades_por_defecto` de aquí arriba.
		$desempenos_ant = DB::select('SELECT id, materia_id, grado_id, periodo_id, tipo, definicion, orden
			FROM desempenos_por_defecto WHERE year_id=? AND deleted_at is null ORDER BY id;', [$pasado->id]);

		$sin_periodo = [];

		foreach ($desempenos_ant as $desempeno) {
			$numero  = $numero_del_viejo[(int) $desempeno->periodo_id] ?? null;
			$destino = $numero === null ? null : ($nuevo_por_numero[$numero] ?? null);

			if ($destino === null) {
				$sin_periodo[] = (int) $desempeno->id;
				continue;
			}

			DB::insert('INSERT INTO desempenos_por_defecto(year_id, materia_id, grado_id, periodo_id, tipo, definicion, orden, created_by, created_at, updated_at) VALUES(?,?,?,?,?,?,?,?,?,?)',
				[$year->id, $desempeno->materia_id, $desempeno->grado_id, $destino,
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

		// **Las TRES políticas del año, y cada una es opcional** (14 y 22 sep 2026).
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

		// **Y la tercera política, que NO es un enum y por eso no entra en el bucle**
		// (22 sep 2026): si los docentes de este colegio pueden cambiar lo que el
		// colegio puso en la plantilla. Misma ruta y mismo dueño que las dos de
		// arriba —es otra política de evaluación del mismo año, en la misma pantalla—,
		// pero su validación es la de un booleano y meterla en `$columnas` habría
		// pedido inventar un `['0','1']` que no dice lo que pasa.
		//
		// **`FILTER_NULL_ON_FAILURE` y no un `(bool)` a pelo.** `(bool) 'no'` es
		// `true` y `(bool) '0'` es `false`: castear deja pasar cualquier cadena y la
		// convierte en «sí», que es la peor de las dos direcciones — abrir la
		// plantilla de un colegio porque alguien mandó una palabra. Con el filtro,
		// `true/false`, `1/0` y `"true"/"false"` entran y **todo lo demás es 422 con
		// el nombre del campo delante**, que es la forma de esta ruta.
		if (Request::has('profes_pueden_editar_plantilla')) {
			$abierta = filter_var(
				Request::input('profes_pueden_editar_plantilla'),
				FILTER_VALIDATE_BOOLEAN,
				FILTER_NULL_ON_FAILURE
			);

			if ($abierta === null) {
				abort(422, '`profes_pueden_editar_plantilla` tiene que ser verdadero o falso.');
			}

			$pedidos['profes_pueden_editar_plantilla'] = $abierta ? 1 : 0;
		}

		// **Sin ningún campo NO es un 200 vacío**, que sería la familia de
		// `tools/respuestas-que-mienten.py`: quien la reciba creería que guardó algo.
		// Antes esto salía por el 422 de `modelo_evaluacion` cuando faltaba; ahora que
		// los dos son opcionales, hace falta decirlo.
		if ($pedidos === []) {
			abort(422, 'Hace falta `modelo_evaluacion`, `reparto_subunidades` o `profes_pueden_editar_plantilla`.');
		}

		$year_id = Request::input('year_id', $user->year_id ?? null);

		if (! is_numeric($year_id)) {
			abort(422, 'Hace falta `year_id` y la sesión no trae ninguno.');
		}

		// `findOrFail` y no una consulta cruda: el modelo lleva `SoftDeletes`, así
		// que un año en la papelera es 404 aquí. Escribirle la configuración a un
		// año borrado no le sirve a nadie y reaparecería con `years/restore`.
		$year = Year::findOrFail((int) $year_id);

		// **Y un año cerrado sólo lo cambia un superusuario** — decisión de Joseth del
		// 15 sep 2026, que sube la lista del año cerrado de quince a **dieciséis**.
		//
		// Esta ruta recibe el `year_id` **por el cuerpo** y hasta hoy no miraba el año:
		// su único guard era `puedeEditarPlantillaNotas`. Así que cualquiera con ese
		// permiso podía poner 2023 en `promedio` y **reescribir las definitivas
		// guardadas de un año cerrado** — boletines ya impresos y firmados, con otros
		// números la próxima vez que se impriman.
		//
		// **Y el doc 28 §5.5 prometía que eso no podía pasar**: *«el interruptor es del
		// año, así que un año cerrado conserva su modo para siempre… Ésa es la
		// garantía, y es la misma de §4»*. **No es la misma**, y ahí está la raíz: la de
		// §4 es **estructural** —ninguna nota apunta a una fila de plantilla, así que no
		// hay nada que romper— y ésta necesitaba **un permiso que nadie escribió**,
		// porque el modo se lee vivo en cada cálculo del año. *Una garantía de
		// construcción y una costumbre se escriben igual, y por eso ésta se heredó tres
		// documentos sin que nadie la comprobara.*
		//
		// Lo levantó `myvc_front`; esta sesión se había hecho la pregunta construyendo
		// el 422 de más abajo y la apartó **sin escribirla en ninguna parte**, que es lo
		// que la dejó sin existir para nadie más.
		//
		// **Superusuario y no «nadie»**, que era la otra opción y la que dice la letra
		// del plan: un colegio que cierre un año con el modo equivocado se quedaría sin
		// más salida que un `UPDATE` a mano. Con el candado sigue pudiendo, y el 422 de
		// abajo le pone delante cuántas definitivas recalcula — que es lo que convierte
		// el paso en una decisión informada en vez de una pared.
		Autoriza::exigirEscrituraEnElAnio($user, (int) $year->id, 'Ese año');

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

		// **Y ahora se recalcula, que es la mitad que faltaba desde el 15 sep 2026.**
		//
		// El 422 de `avisarDeLoQueRecalcula` cuenta cuántas definitivas cambian y pide
		// `acepto_recalcular`, y hasta hoy ahí se acababa: el método guardaba el año y
		// se iba. O sea que el aviso decía la verdad —«esto recalcula N definitivas»— y
		// **nadie las recalculaba**. Lo que producía no era un salto sino deriva: las
		// guardadas seguían en el modo viejo mientras las pantallas ya calculaban con
		// el nuevo, y se iban reescribiendo asignatura a asignatura según alguien las
		// fuera tocando, durante días.
		//
		// **El sello no podía taparlo**, y por eso no bastaba con dejarlo al recálculo
		// perezoso: `DefinitivasDeAsignatura::selloDeVersion()` mira `notas`,
		// `unidades`, `subunidades` y `matriculas` — **no mira `years`**. Girar este
		// interruptor no mueve ninguna de las cuatro, así que las definitivas del año
		// quedaban declaradas al día con el reparto viejo dentro. Es el segundo de los
		// dos agujeros del recorrido del 17 sep ([10](../../../docs/migracion/10-definitivas.md)),
		// y el otro —desmarcar `manual`— tiene la misma forma: una escritura que cambia
		// el resultado sin tocar nada de lo que el sello vigila.
		//
		// Se hace **síncrono y no perezoso** porque es el único momento en que se sabe
		// que hay que hacerlo. Medido el 17 sep en la copia de desarrollo sobre el año
		// en curso: **536 pares (asignatura, periodo), 1.680 definitivas escritas,
		// 2,5 s**. Es caro para una petición y barato para lo que es — un cambio de
		// configuración que ocurre una vez al año y que ya viene detrás de un 422 con
		// el recuento delante.
		$recalculadas = null;

		if (isset($antes['reparto_subunidades'])
			&& (string) $antes['reparto_subunidades'] !== (string) $year->reparto_subunidades) {
			$recalculadas = $this->recalcularElAnioEntero((int) $year->id, (int) $user->user_id);
		}

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
			// Entero y no booleano: es lo que trae `ContextoDeUsuario` en sus cuatro ramas,
			// y dos representaciones del mismo interruptor son dos ramas en cada front.
			'profes_pueden_editar_plantilla' => (int) $year->profes_pueden_editar_plantilla,
			'anterior' => $anterior,
			'desempeno_displayname' => $year->desempeno_displayname,
			'desempenos_displayname' => $year->desempenos_displayname,
			'genero_desempeno' => $year->genero_desempeno,
			// `null` cuando no se tocó el reparto — que no es lo mismo que `0`, y por eso
			// no se rellena con cero: `0` dice «se recalculó y no cambió ninguna»,
			// `null` dice «no había nada que recalcular».
			'recalculadas' => $recalculadas,
		];
	}

	/**
	 * Recalcula todas las definitivas de un año, por pares (asignatura, periodo).
	 *
	 * Sólo lo llama el cambio de `reparto_subunidades`, que es lo único que cambia
	 * el resultado de **todas** las asignaturas a la vez sin tocar ninguna nota.
	 *
	 * ## Los pares salen de `unidades`, no de `asignaturas` × `periodos`
	 *
	 * Y la diferencia no es de rendimiento. `DefinitivasDeAsignatura::recalcular()`
	 * no escribe nada cuando la asignatura no tiene unidades vivas en ese periodo
	 * —decisión de Joseth del 28 ago, para que borrar la última unidad no escriba
	 * treinta ceros—, así que el producto cartesiano pediría miles de recálculos que
	 * el servicio descartaría de todos modos. Preguntando por `unidades` se piden
	 * **sólo los pares que existen**: 536 en el año en curso de la copia de
	 * desarrollo, frente a los 1.219 × 4 del cartesiano.
	 *
	 * **`manual` y `recuperada` quedan fuera solas**, sin filtro aquí: las respeta el
	 * servicio, que es donde esa regla vive desde la fase 1. Repetirla aquí sería el
	 * quinto sitio que decide lo mismo, que es justo lo que el recalculador único
	 * vino a quitar.
	 *
	 * **No va en una transacción que lo envuelva todo.** Cada par abre la suya dentro
	 * del servicio; envolver los 536 en una sola dejaría la tabla de definitivas del
	 * colegio bloqueada dos segundos y medio, y un fallo a mitad no deja nada
	 * inconsistente — deja definitivas recalculadas y definitivas por recalcular, que
	 * es exactamente el estado del que se viene y el que el aviso ya describe.
	 *
	 * @return array{pares:int, escritas:int}
	 */
	private function recalcularElAnioEntero(int $yearId, int $porUsuario): array
	{
		$pares = DB::select(
			'SELECT DISTINCT u.asignatura_id, u.periodo_id
			   FROM unidades u
			   INNER JOIN periodos p ON p.id = u.periodo_id AND p.deleted_at IS NULL AND p.year_id = ?
			   INNER JOIN asignaturas a ON a.id = u.asignatura_id AND a.deleted_at IS NULL
			  WHERE u.deleted_at IS NULL',
			[$yearId]
		);

		$escritas = 0;

		foreach ($pares as $par) {
			$recalculo = DefinitivasDeAsignatura::recalcular(
				(int) $par->asignatura_id,
				(int) $par->periodo_id,
				$porUsuario
			);

			$escritas += (int) ($recalculo['escritas'] ?? 0);
		}

		return ['pares' => count($pares), 'escritas' => $escritas];
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
				SELECT CAST(COALESCE(c.suma / NULLIF(c.peso_evaluado, 0), 0) AS DECIMAL(7,4)) - nf.nota AS dif
				  FROM notas_finales nf
				  INNER JOIN periodos p ON p.id = nf.periodo_id AND p.deleted_at IS NULL
				  LEFT JOIN (
					SELECT n.alumno_id, u.asignatura_id, u.periodo_id,
					       u.alumno_id AS dueno,
					       SUM('.RepartoDeLaNota::aportacionALaDefinitiva($despues).') AS suma,
					       SUM(CASE WHEN n.nota IS NULL THEN 0 ELSE ('.RepartoDeLaNota::pesoDeLaNota($despues).') END) AS peso_evaluado
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

	/**
	 * Si el boletín imprime el número además del texto del desempeño.
	 *
	 * `PUT years/toggle-mostrar-nota-numerica`. Encargo de Joseth del 17 sep 2026
	 * por la sesión de `myvc_front`: deja de ser un «boletín tipo 5» que se elige al
	 * imprimir y pasa a ser configuración del año, porque es una decisión del SIEE.
	 *
	 *     apagado  ->  sólo el texto del desempeño
	 *     encendido -> el número Y el desempeño
	 *
	 * ## Por qué gasta una ruta, que en este repositorio no es gratis
	 *
	 * Porque el permiso no cabe en ninguna de las que ya hay. La columna **no puede**
	 * ir por `years/toggle-cambiar-valor` —ésa escribe cualquier columna de `years`
	 * con sólo `auth.personal`, o sea las 74 cuentas del personal— y tampoco por
	 * `years/modelo-evaluacion`, que exige `can_edit_plantilla_notas`: es **otro**
	 * permiso, y meter dos en una ruta la convierte en una ruta sin criterio.
	 *
	 * Así que la elección real era «una ruta nueva» o «este interruptor lo mueve
	 * cualquier docente», y la segunda no es lo que se decidió. Va con el nombre de
	 * su familia —`years/toggle-…`, como las otras cinco— y con `auth.personal` en
	 * la ruta más el permiso **dentro**, que es la forma de `plantilla-notas/`.
	 *
	 * ## El permiso se pregunta ANTES de resolver el año
	 *
	 * `Year::findOrFail()` contesta **404** a un año que no existe, y hacerlo primero
	 * convertiría «no tienes permiso» en «ese año no existe» para quien no debería
	 * estar preguntando — un 404 que confirma o desmiente la existencia de un año a
	 * quien no puede tocarlo. Es barato ponerlo en el orden correcto y no hay ninguna
	 * razón para el otro.
	 */
	public function putToggleMostrarNotaNumerica(){
		$user = User::fromToken();

		Autoriza::exigir(
			Autoriza::puedeCambiarLaNotaNumerica($user),
			'Solo un superusuario, secretario, coordinador académico o rector puede '
				.'cambiar si el boletín muestra la nota numérica.'
		);

		$year_id = Request::input('year_id');

		// **`can` se lee igual que en las otras cinco de esta familia**, con el `(bool)`
		// delante, y eso hace que cualquier cadena no vacía valga por «sí» —incluida
		// `"false"`—. Se conserva a propósito: `myvc_flutter` es una sola app para los
		// dieciséis y una versión vieja convive meses, así que cambiar la forma de leer
		// este campo aquí y no en las otras cinco dejaría dos criterios para el mismo
		// `can` según el interruptor. La familia se endurece entera o no se endurece.
		$can = (bool) Request::input('can');

		$year = Year::findOrFail($year_id);
		$year->mostrar_nota_numerica_boletin = $can;
		$year->updated_by = $user->user_id;
		$year->save();

		// Devuelve el valor y no una frase suelta: la pantalla de configuración pinta
		// este interruptor al lado de `solo_escalas_valorativas`, que es el otro que
		// vacía un número —con otro alcance y la polaridad invertida—, y ahí conviene
		// que el cliente confirme lo que quedó guardado en vez de deducirlo de lo que
		// mandó.
		return [
			'year_id' => (int) $year->id,
			'mostrar_nota_numerica_boletin' => (bool) $year->mostrar_nota_numerica_boletin,
		];
	}

	/**
	 * Qué pasa al CERRAR el periodo con las casillas que nadie calificó.
	 * `PUT years/cierre-sin-calificar` -> `years.cierre_sin_calificar`.
	 *
	 * **D3** del [43](../../docs/migracion/43-lo-que-todavia-no-se-ha-calificado.md),
	 * fase 4, autorizada por Joseth el 20 sep 2026: *«lo elige cada rector»*, con tres
	 * salidas —`cero`, `fuera`, `bloquear`— y **`cero` de fábrica**, que es el
	 * comportamiento de hoy. Qué hace cada una y por qué son dos columnas y no una,
	 * en {@see \App\Support\CierreDeLoNoCalificado}.
	 *
	 * ## Ruta propia y NO `years/toggle-cambiar-valor`, que sí podría
	 *
	 * Aquélla escribe cualquier columna de `years` que exista con sólo
	 * `auth.personal`, así que esto **no es una imposibilidad: es una decisión**. Y
	 * tiene el mismo motivo que tuvo `modelo_evaluacion`: **esta columna tiene dueño**.
	 * Colgada del genérico, el permiso de abajo se saltaría en una línea y no lo diría
	 * nada. Por eso está excluida allí, en la lista `$conDueno` de
	 * {@see putToggleCambiarValor} — *al darle dueño a una columna se repasan todos los
	 * caminos que escriben esa tabla, no sólo el que se está tocando*.
	 *
	 * Tampoco cabía en `years/modelo-evaluacion`, que es donde viven las otras dos
	 * políticas del año: aquéllas van con `can_edit_plantilla_notas` y ésta no —el
	 * permiso es otro, y meterla ahí sería ensanchar aquel método a dos permisos.
	 *
	 * ## El permiso va DENTRO, y eso es una decisión
	 *
	 * `auth.personal` en la ruta —cierra la puerta a alumnos y acudientes antes de
	 * tocar el controlador— y `Autoriza::puedeElegirQuePasaAlCerrar` aquí dentro. La
	 * familia `years/*` va con `auth.personal` y nada dentro; éste va con el otro
	 * grupo, el de `toggle-mostrar-nota-numerica`, y el porqué entero está en el
	 * docblock de ese método de `Autoriza`. En una línea: `auth.personal` deja pasar a
	 * las 74 cuentas de personal, de las que **53 son docentes**, y esta columna decide
	 * si a un alumno le cuentan como cero **las casillas que su profesor no
	 * calificó**. Es el único de los interruptores del año en el que quien lo pulsa
	 * puede ser parte interesada.
	 *
	 * **Cerrar el periodo no se estrecha**: sigue en `auth.personal` y nada dentro, con
	 * sus tres clientes intactos. Se estrecha elegir la política, no aplicarla.
	 *
	 * ## Lo que NO lleva, y va escrito para que no se lea como un olvido
	 *
	 * **No lleva `Autoriza::exigirEscrituraEnElAnio`**, que sí lleva
	 * `years/modelo-evaluacion` desde el 15 sep. Y no es simetría rota: allí hacía
	 * falta porque **el modo se lee vivo en cada cálculo del año**, así que cambiarlo
	 * reescribía definitivas de un año cerrado. Aquí el cálculo **no lee esta
	 * columna**: lee `periodos.cierre_sin_calificar`, que sólo escribe el cierre. Esto
	 * no puede alcanzar un periodo cerrado ni aunque se quiera, y un guard que no
	 * protege nada es peor que no ponerlo — el día que alguien lo lea creerá que hay
	 * algo protegido ahí.
	 *
	 * Devuelve el valor guardado y no una frase: la pantalla lo pinta como un selector
	 * de tres opciones, y conviene que confirme lo que quedó en vez de deducirlo de lo
	 * que mandó.
	 */
	public function putCierreSinCalificar(){
		$user = User::fromToken();

		Autoriza::exigir(
			Autoriza::puedeElegirQuePasaAlCerrar($user),
			'Solo un superusuario, secretario, coordinador académico o rector puede '
				.'elegir qué pasa al cerrar con lo que no se calificó.'
		);

		// **No se lee con `(bool)` como los doce interruptores hermanos**, y no puede:
		// esto no es un sí/no, son tres salidas. Por eso tampoco arrastra la laxitud de
		// aquéllos —donde `"false"` vale por «sí»—: aquí lo que no está en la lista se
		// rechaza con 422.
		$valor = Request::input('valor');

		// El `enum` de MySQL rechazaría el valor raro, pero **con el `sql_mode` de estos
		// servidores no lanza: guarda la cadena vacía y devuelve 200**. Es la misma
		// familia que `modelo_evaluacion`, y por eso la lista vive en
		// `CierreDeLoNoCalificado` y un test la cruza contra `SHOW COLUMNS`.
		if (! is_string($valor) || ! in_array($valor, CierreDeLoNoCalificado::SALIDAS, true)) {
			abort(422, '`valor` tiene que ser '
				.implode(', ', CierreDeLoNoCalificado::SALIDAS).'.');
		}

		$year_id = Request::input('year_id', $user->year_id ?? null);

		if (! is_numeric($year_id)) {
			abort(422, 'Hace falta `year_id` y la sesión no trae ninguno.');
		}

		// `findOrFail` y no una consulta cruda, como `putModeloEvaluacion`: el modelo
		// lleva `SoftDeletes`, así que un año en la papelera es 404. Escribirle la
		// configuración a un año borrado no le sirve a nadie y reaparecería con
		// `years/restore`.
		$year = Year::findOrFail((int) $year_id);

		$year->cierre_sin_calificar = $valor;
		$year->updated_by = $user->user_id;
		$year->save();

		return [
			'year_id' => (int) $year->id,
			'cierre_sin_calificar' => (string) $year->cierre_sin_calificar,
		];
	}

	/**
	 * Abrir y cerrar la campaña de prematrícula, que son DOS interruptores distintos.
	 *
	 *     PUT years/toggle-prematricula-nuevos    ->  years.prematr_nuevos
	 *     PUT years/toggle-prematricula-antiguos  ->  years.prematr_antiguos
	 *
	 * Encargo de Joseth del 19 sep 2026, y llegó por el camino incómodo: la pantalla de
	 * ajustes del año de `app2` ya pintaba los dos interruptores y las dos rutas
	 * contestaban **404**. El front las dejó escritas con el aviso puesto y el precio
	 * delante (`app2/src/app/datos/years.ts`), que es exactamente lo que hay que hacer
	 * cuando una pantalla necesita una ruta que no existe.
	 *
	 * ## Lo que esto viene a quitar, y está medido
	 *
	 * Hasta hoy **ninguna aplicación podía escribir estas dos columnas**:
	 *
	 *   - `years/guardar-cambios` nombra veintiún campos y ninguno es éste;
	 *   - no había ninguna ruta que las nombrara — `grep prematr routes/api/*.php` sólo
	 *     saca las de matricular;
	 *   - lo único que las escribía en todo el backend es **crear un año**
	 *     (`postStore`), que las copia del año anterior.
	 *
	 * O sea que abrir la campaña de 2027 era heredar el valor bueno del año pasado o un
	 * `UPDATE` a mano en la base, colegio por colegio. Es `profesores.tono` otra vez:
	 * una columna que lee media aplicación —el enlace público del login, la portada del
	 * acudiente y los modos de los formularios de inscripción— y que no escribe nadie.
	 *
	 * ## Dos rutas, y NO `years/toggle-cambiar-valor`, que sí podría
	 *
	 * Aquélla escribe cualquier columna de `years` con este mismo `auth.personal`, así
	 * que esto no es una imposibilidad: es una decisión de forma. Los otros diez
	 * interruptores del año son `{year_id, can}` contra una ruta propia, y
	 * `toggle-cambiar-valor` pide el **nombre de la columna dentro del cuerpo**. Sería el
	 * único de los doce en el que el cliente nombra la columna, y un endpoint con forma
	 * distinta al de al lado es el que un día se llama mal. Por lo mismo se descartó una
	 * sola ruta con `flujo: 'nuevos'|'antiguos'`: sale más barata en el recuento del
	 * router y deja doce interruptores con once formas.
	 *
	 * ## El permiso: cualquiera del personal, y está DECIDIDO, no olvidado
	 *
	 * Decisión de Joseth del 19 sep 2026, con las dos poblaciones delante: se quedan en
	 * `auth.personal` y **sin permiso dentro** —las 74 cuentas de personal de la copia de
	 * desarrollo—, igual que los otros diez interruptores del año.
	 *
	 * Se escribe aquí porque la decisión de anteayer fue la contraria
	 * —`putToggleMostrarNotaNumerica`, 18 sep, puso el permiso dentro— y quien lea las
	 * dos seguidas va a concluir que a ésta se le olvidó. No se le olvidó, y el caso ni
	 * siquiera es el más inocente: `prematr_nuevos` enciende el enlace público de la
	 * pantalla de entrada, o sea **una puerta que se ve desde internet sin cuenta**. Aun
	 * así la llave es la de la familia. El día que se quiera estrechar, el sitio son
	 * estos dos métodos —un `Autoriza::exigir` delante, antes de `findOrFail`— y la
	 * pantalla de `app2`, que hoy va tras el rol `Admin`.
	 *
	 * ## `can` se lee con `(bool)`, como los otros once
	 *
	 * Con lo que eso arrastra —cualquier cadena no vacía vale por «sí», `"false"`
	 * incluida—. El porqué está entero en `putToggleMostrarNotaNumerica` y es el mismo:
	 * `myvc_flutter` es una sola app para los dieciséis y la familia se endurece entera
	 * o no se endurece.
	 */
	public function putTogglePrematriculaNuevos(){
		$user = User::fromToken();

		$year_id 	= 	Request::input('year_id');
		$can 		= 	(bool) Request::input('can');

		$year = Year::findOrFail($year_id);
		$year->prematr_nuevos = $can;
		$year->updated_by = $user->user_id;
		$year->save();

		if ($can) { return 'Prematrícula ABIERTA para estudiantes nuevos.';
		} else { return 'Prematrícula CERRADA para estudiantes nuevos.';}
	}

	/**
	 * La otra mitad de la campaña: los que ya están.
	 *
	 * Escribe `years.prematr_antiguos`, que es lo que ve en su portada un acudiente que
	 * ya es del colegio (`ChangeAskedController`, tres sitios) — no el enlace público.
	 * Todo lo demás —las dos rutas, el permiso, la forma del cuerpo— está en el docblock
	 * de su gemela, aquí encima.
	 */
	public function putTogglePrematriculaAntiguos(){
		$user = User::fromToken();

		$year_id 	= 	Request::input('year_id');
		$can 		= 	(bool) Request::input('can');

		$year = Year::findOrFail($year_id);
		$year->prematr_antiguos = $can;
		$year->updated_by = $user->user_id;
		$year->save();

		if ($can) { return 'Prematrícula ABIERTA para los alumnos que ya están.';
		} else { return 'Prematrícula CERRADA para los alumnos que ya están.';}
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
		// **Y el 18 sep 2026 entró la tercera, y con ella el mensaje deja de poder ser
		// uno solo.** `mostrar_nota_numerica_boletin` también tiene dueño, pero **no el
		// mismo**: no va por `years/modelo-evaluacion` ni exige
		// `can_edit_plantilla_notas`, sino por su propia ruta y con el permiso de la
		// decisión de Joseth —superusuario, Secretario, Coord académico y Rector—.
		//
		// Así que cada columna trae **su** ruta. Escrito de la forma corta —una lista de
		// nombres y un mensaje fijo— esta tercera habría mandado a quien la intentara a
		// `years/modelo-evaluacion`, donde no se puede escribir, y el rastro de eso es
		// una persona probando un endpoint que no era y concluyendo que no tiene
		// permiso. Es el fallo de la §3.4 del 10 otra vez: **dos causas distintas con la
		// misma cara**, y de las caras, la que manda a investigar al sitio equivocado.
		$conDueno = [
			'modelo_evaluacion' => ['El modelo de evaluación', 'years/modelo-evaluacion',
				'el permiso de la plantilla de notas'],
			'reparto_subunidades' => ['El reparto de las subunidades', 'years/modelo-evaluacion',
				'el permiso de la plantilla de notas'],
			// **Y la quinta, del 22 sep 2026.** Sin este corte la ruta sería peor que
			// decorativa: `auth.personal` son 74 cuentas y 53 de ellas son docentes, o
			// sea que **cualquier docente se abriría en una línea el candado que le
			// frena la plantilla**, que es exactamente lo que este interruptor existe
			// para que decida el colegio.
			'profes_pueden_editar_plantilla' => ['Quién edita la plantilla de notas',
				'years/modelo-evaluacion', 'el permiso de la plantilla de notas'],
			'mostrar_nota_numerica_boletin' => ['La nota numérica del boletín',
				'years/toggle-mostrar-nota-numerica',
				'ser superusuario, Secretario, Coord académico o Rector'],
			// **Y la cuarta, del 20 sep 2026 (fase 4 del doc 43, D3).** Tiene dueño y
			// es el mismo que la de arriba, pero por otra ruta — que es exactamente
			// por lo que este bloque dejó de ser un mensaje fijo: cada columna trae la
			// suya, y mandar a alguien a la ruta que no es deja el rastro de una
			// persona probando un endpoint donde no se puede escribir y concluyendo
			// que no tiene permiso.
			//
			// Sin este corte, `auth.personal` —74 cuentas, 53 docentes— escribiría en
			// una línea la columna que decide si a un alumno le cuentan como cero las
			// casillas que su profesor no calificó.
			'cierre_sin_calificar' => ['Qué pasa al cerrar con lo no calificado',
				'years/cierre-sin-calificar',
				'ser superusuario, Secretario, Coord académico o Rector'],
		];

		$normalizado = strtolower(trim((string) $campo));

		if (isset($conDueno[$normalizado])) {
			[$que, $ruta, $permiso] = $conDueno[$normalizado];

			abort(422, $que.' se cambia con '.$ruta.', que exige '.$permiso.'.');
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
		//
		// **Las claves de esta lista tienen que ser las de `Year::TITULOS_POR_DEFECTO`,
		// y eso lo ata `TitulosDelCertificadoTest`** en vez de dejarlo a que alguien se
		// acuerde: el rótulo se escribe a mano porque es lo que lee una persona en el
		// 422, pero **el conjunto no puede quedarse corto**. La tercera —el título de la
		// constancia de estudio— entró el 20 sep 2026 y es justo el caso que el test
		// existe para cazar: la columna se añade en la constante, y sin este renglón
		// `toggle-cambiar-valor` la dejaría vaciar por la puerta de al lado.
		$conInvarianteDeValor = [
			'titulo_certificado_final' => 'El título del certificado final',
			'titulo_certificado_periodos' => 'El título del certificado por periodos',
			'titulo_constancia_estudio' => 'El título de la constancia de estudio',
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

		$columna 	= ColumnaSegura::exigir('years', $campo);

		/*
		 * **El valor de antes, leído antes de pisarlo.** Esta ruta es «guardar un campo
		 * suelto» de la rejilla y escribe CUALQUIER columna de `years` menos dos: o sea
		 * que por aquí pasan las políticas que gobiernan al colegio entero —si el
		 * boletín muestra puestos, si las notas perdidas se ignoran, qué pasa al cerrar
		 * con lo no calificado—. Que una de ésas cambie y nadie sepa quién ni desde qué
		 * valor es el caso que el CLAUDE.md nombra por su nombre: «qué política aplica
		 * al colegio entero» no puede depender de la memoria de nadie.
		 *
		 * Una consulta por clave primaria en un interruptor que se toca a mano y de uno
		 * en uno. No hay lote por aquí.
		 */
		$antes 		= DB::selectOne("SELECT {$columna} AS valor FROM years WHERE id = ?", [$year_id]);

		$consulta 	= 'UPDATE years SET '.$columna.'=:valor, updated_by=:modificador, updated_at=:fecha WHERE id=:year_id';
		$datos 		= [ ':valor' => $valor, ':modificador' => $user->user_id, ':fecha' => $now, ':year_id' => $year_id ];

		// El año existe —se acaba de comprobar—, así que llegar aquí es haber guardado,
		// cambiara algo o no. `DB::update` devuelve filas AFECTADAS y MySQL da 0 cuando el
		// valor ya era ése: apagar un interruptor que ya estaba apagado contestaba
		// «No guardado» con 200 y el estado correcto.
		DB::update($consulta, $datos);

		Auditoria::registrar()
			->editar('year_config', (int) $year_id)
			->en(year: (int) $year_id)
			->de($antes->valor ?? null)
			->a($valor)
			->resumen('Cambió '.trim($columna, '`').' del año lectivo')
			->guardar();

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
