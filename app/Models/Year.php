<?php namespace App\Models;

use App\Support\SellaConElReloj;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

use Illuminate\Support\Facades\DB;

use App\Models\Periodo;
/**
 * Las columnas de `years`, tal como están en el esquema congelado.
 *
 * Generado desde database/schema/mysql-schema.sql — no se edita a mano.
 * Ver tools/columnas-en-los-modelos.php.
 *
 * --- columnas de la tabla, generadas por tools/columnas-en-los-modelos.php ---
 *
 * @property int $id
 * @property int $year
 * @property string $nombre_colegio
 * @property ?string $abrev_colegio
 * @property string $genero_colegio
 * @property ?string $ciudad_id
 * @property ?int $logo_id
 * @property ?int $img_encabezado_id
 * @property ?int $rector_id
 * @property ?int $secretario_id
 * @property ?int $tesorero_id
 * @property ?int $coordinador_academico_id
 * @property ?int $coordinador_disciplinario_id
 * @property ?int $capellan_id
 * @property ?int $psicorientador_id
 * @property string $nota_minima_aceptada
 * @property ?int $minu_hora_clase
 * @property string $unidad_displayname
 * @property string $unidades_displayname
 * @property string $genero_unidad
 * @property string $subunidad_displayname
 * @property string $subunidades_displayname
 * @property string $genero_subunidad
 * @property ?string $resolucion
 * @property ?string $codigo_dane
 * @property ?string $caracter
 * @property ?string $calendario
 * @property ?string $jornada
 * @property ?string $encabezado_certificado
 * @property ?string $frase_final_certificado
 * @property int $actual
 * @property ?string $telefono
 * @property ?string $celular
 * @property ?string $website
 * @property ?string $website_myvc
 * @property int $alumnos_can_see_notas
 * @property int $profes_can_edit_alumnos
 * @property int $mostrar_puesto_boletin
 * @property int $puestos_alfabeticamente
 * @property ?string $titulo_rector
 * @property int $mostrar_nota_comport_boletin
 * @property int $si_recupera_materia_recup_indicador
 * @property int $year_pasado_en_bol
 * @property int $show_fortaleza_bol
 * @property int $solo_escalas_valorativas
 * @property int $mostrar_nota_numerica_boletin
 * @property ?int $config_certificado_estudio_id
 * @property ?int $cant_areas_pierde_year
 * @property ?int $cant_asignatura_pierde_year
 * @property int $show_subasignaturas_en_finales
 * @property int $mensaje_aprobo_con_pendientes
 * @property int $show_materias_todas
 * @property ?string $msg_when_students_blocked
 * @property string $contador_certificados
 * @property string $contador_folios
 * @property ?string $texto_acta_eval
 * @property ?int $prematr_antiguos
 * @property ?int $prematr_nuevos
 * @property ?string $compromiso_familiar_label
 * @property ?int $created_by
 * @property ?int $updated_by
 * @property ?int $deleted_by
 * @property ?string $deleted_at
 * @property ?string $created_at
 * @property ?string $updated_at
 * --- fin de las columnas generadas ---
 *
 * Y la regla de nivelación del año, por migración
 * (`2026_09_02_100000_nivelaciones_columnas`): `topada` | `mayor` | `reemplaza`,
 * se aplica **al escribir** la nivelación (22 §1.4) y se copia al año siguiente
 * en `YearsController::postStore`.
 *
 * @property string $regla_nivelacion
 *
 * Y el puntero a la versión **oficial** del horario del año, por migración
 * (`2026_09_04_100000_horario_versiones`, 23 §5.1). Es un puntero y no una
 * bandera `horario_versiones.oficial` porque MySQL no tiene índices parciales:
 * una bandera no se puede atar a «como mucho una por año», y el día que hubiera
 * dos en verdadero quien leyera `WHERE oficial = 1 LIMIT 1` se llevaría una de
 * las dos sin que se pusiera nada rojo. `NULL` es «este año todavía no tiene
 * oficial», que es un estado. **No se copia al año siguiente**: ver
 * `CentinelaDeLasColumnasDelAnioNuevoTest::NACEN_VACIAS`.
 *
 * **Y no se escribe por `PUT years/guardar-cambios`, aunque llegue por ahí.** El
 * front viejo manda el objeto `year` entero tal como se lo dio `GET years/colegio`,
 * así que esta clave le vuelve al servidor en cada guardado de la pantalla de
 * colegio. Lo que la ignora es que `YearsController::putGuardarCambios` asigna
 * **campo a campo** y ésta no está en su lista. Simplificar ese método a asignación
 * masiva abriría un camino **sin permiso** para escribir la versión oficial —y con
 * el valor **caducado** que la página tenía al cargarse—. El porqué entero está
 * pegado a la columna, en `2026_09_04_100000_horario_versiones`.
 *
 * @property ?int $horario_version_id
 *
 * Y las cuatro de la **Fase 1 del modelo de evaluación** por migración
 * (`2026_09_13_100000_modelo_de_evaluacion_del_anio`, 35 §2), que son **una sola
 * decisión**: cuál es el modelo y cómo llama el colegio a lo que ese modelo trae.
 *
 * `modelo_evaluacion` es del AÑO y no del colegio ni del grupo (D1): un año cerrado
 * conserva el suyo para siempre, igual que la plantilla y las definitivas. Gobierna
 * **qué se ve y qué se pide escribir, nunca un cálculo** (D3) — la definitiva sale
 * de la misma fórmula en los dos modos, y de ahí la propiedad que no hay que
 * perder: *volver atrás es cambiar el enum*.
 *
 * **Y no se escribe por `PUT years/guardar-cambios` ni por
 * `PUT years/toggle-cambiar-valor`, aunque las dos podrían.** Tiene ruta propia
 * —`PUT years/modelo-evaluacion`, con `can_edit_plantilla_notas` dentro— porque los
 * dieciséis `years/*` de escritura son `auth.personal`, o sea que colgarla de
 * cualquiera de ellos dejaría que **cualquier docente cambiara el modelo de
 * evaluación del colegio entero**. Es D24, y el corte del genérico está escrito en
 * `YearsController::putToggleCambiarValor`.
 *
 * Los **tres rótulos** sí van en `putGuardarCambios`, al lado de los seis de unidad
 * y subunidad y con su mismo guard: son vocabulario, y quien puede renombrar
 * «Subunidad» puede renombrar «Desempeño». La palabra es la del **Decreto 1290**
 * —el vigente, donde «desempeño» sale 16 veces y «logro» e «indicador» ninguna
 * (D15)—, y cada colegio la cambia desde su pantalla.
 *
 * Las cuatro se copian al año siguiente en `YearsController::postStore` y viajan en
 * las **cuatro** ramas de `ContextoDeUsuario`, por lo mismo que `regla_nivelacion`.
 *
 * ## NINGÚN CONTROLADOR LEE `modelo_evaluacion`, y es a propósito (15 sep 2026)
 *
 * Se escribe en `putModeloEvaluacion`, viaja al front en las cuatro ramas de
 * `ContextoDeUsuario` **y ahí se acaba**. Ni `DesempenosController`, ni
 * `CompetenciasController`, ni `BoletinPorCompetenciasController` la consultan para
 * decidir nada: con el año en `ponderado`, las diecinueve rutas de competencias y
 * desempeños responden igual que con el año en `competencias`.
 *
 * **Eso tiene toda la pinta de `profesores.tono` —una columna que nadie lee— y no lo
 * es.** Es D3 literalmente: el modelo gobierna **qué se ve y qué se pide escribir**
 * —el menú, si la planilla sigue pidiendo el texto del logro, qué boletín se imprime
 * por defecto—, y eso son decisiones **del cliente**, no de la API. Lo que la columna
 * hace aquí es **viajar**, y eso ya lo hace.
 *
 * Confirmado por Joseth el 15 sep 2026 al ponerle delante la alternativa: que el
 * backend contestara 403 en esas rutas con el año en `ponderado`. **Se descartó**, y
 * el motivo es el que hay que conservar — con ese `if`, *volver atrás dejaría de ser
 * cambiar el enum*: el colegio que apagara el modelo perdería el acceso a los
 * desempeños que ya había escrito, y D3 existe precisamente para que apagar no
 * destruya nada.
 *
 * Así que si alguien viene a «arreglar» esta columna sin lector, lo que tiene que
 * saber es que el lector está en los cuatro fronts.
 *
 * @property string $modelo_evaluacion
 * @property string $desempeno_displayname
 * @property string $desempenos_displayname
 * @property string $genero_desempeno
 *
 * Y los **dos títulos de los certificados**, por migración
 * (`2026_09_15_100000_titulos_del_certificado`, doc 38): el texto que va impreso
 * arriba de «Certificado final» y de «Certificado periodos», que hasta ese día
 * estaba escrito dentro de la plantilla de los dos fronts y el colegio no podía
 * cambiar.
 *
 * **Son dos y no una** porque son dos papeles: uno certifica el año cerrado y el otro
 * «hasta el periodo que usted elija». Hasta el 15 sep decían lo mismo, y no porque
 * nadie lo decidiera sino porque **comparten plantilla** en los dos fronts — el
 * defecto del parcial lleva `PARCIAL` detrás justamente para deshacer ese empate. Y
 * van en `years` y no en `config_certificados` porque el año elige **una sola** fila
 * de aquélla (`config_certificado_estudio_id`) y aquí hacen falta dos títulos vivos a
 * la vez.
 *
 * `NOT NULL` con defecto: un certificado sin título no es un estado. Se escriben
 * por `PUT certificados/encabezado`, que es la ruta de los textos del certificado,
 * y se copian al año siguiente en `YearsController::postStore` por lo mismo que
 * `regla_nivelacion` — el colegio que escribió el suyo no amanece con el defecto
 * cada enero.
 *
 * @property string $titulo_certificado_final
 * @property string $titulo_certificado_periodos
 * @property string $titulo_constancia_estudio
 *
 * Y **qué pasa al cerrar el periodo con lo que nadie calificó**, por migración
 * (`2026_09_20_600000_el_cierre_y_lo_no_calificado`, fase 4 del doc 43, **D3** de
 * Joseth del 20 sep 2026): `cero` | `fuera` | `bloquear`, con **`cero` de fábrica**,
 * que es el comportamiento de hoy — la casilla vacía aporta lo mismo que un cero a una
 * definitiva que no normaliza.
 *
 * Es **la elección del rector**, y es la tercera política del año que se elige una vez,
 * al lado de `modelo_evaluacion` y `reparto_subunidades`. **Pero a diferencia de
 * aquéllas, el cálculo NO la lee**: lo que gobierna una definitiva es
 * `periodos.cierre_sin_calificar`, que congela lo que se aplicó el día del cierre. Por
 * eso cambiar ésta no puede mover un boletín ya impreso, y por eso esta ruta no lleva
 * `exigirEscrituraEnElAnio` y `years/modelo-evaluacion` sí. El porqué entero está en
 * `App\Support\CierreDeLoNoCalificado`.
 *
 * Se escribe por `PUT years/cierre-sin-calificar` —con `Autoriza::puedeElegirQuePasaAlCerrar`
 * dentro, no con el `auth.personal` a secas de los otros once interruptores— y **no
 * por `years/toggle-cambiar-valor`**, donde está excluida: tiene dueño. Se copia al año
 * siguiente en `YearsController::postStore` por lo mismo que `regla_nivelacion`.
 *
 * @property string $cierre_sin_calificar
 *
 * Y los atributos que NO son columnas: el código se los cuelga al modelo en
 * tiempo de ejecución para armar la respuesta, que es un patrón repetido por
 * todo el proyecto. Eloquent los guarda entre los atributos y salen en el JSON,
 * así que forman parte del contrato con el frontend igual que las columnas;
 * anotarlos es lo que permite que el análisis siga avisando de un nombre mal
 * escrito en vez de callarse con todos.
 *
 * @property \Illuminate\Database\Eloquent\Collection<int, \App\Models\Grupo> $grupos_ant  los grupos del año anterior, para el traspaso de año
 * @property list<\App\Models\Periodo> $periodos  los periodos que `YearsController::postStore` acaba de crear, para que la respuesta traiga el año montado
 */


class Year extends Model {
	protected $table = 'years';

	/**
	 * Los dos modelos de evaluación, **en el mismo orden que el `enum` de la base**.
	 *
	 * Vive aquí y no en el controlador porque es una propiedad de la columna, y la
	 * lista se compara contra `SHOW COLUMNS` en
	 * `ModeloDeEvaluacionDelAnioTest::la_lista_del_modelo_y_la_de_la_base_son_la_misma`.
	 * Es la trampa que ya lleva escrita `Autoriza::PERMISO_*`: dos sitios que dicen
	 * una cadena y **no falla nada** hasta que alguien guarda el valor que sólo
	 * conoce uno de los dos.
	 *
	 * `ponderado` es el de hoy —el logro va en la columna de la planilla— y
	 * `competencias` el nuevo —el logro va aparte de la nota— (D2). Los nombres que
	 * ve el colegio NO son éstos: los pone el front, y son frases enteras.
	 *
	 * @var list<string>
	 */
	public const MODELOS_DE_EVALUACION = ['ponderado', 'competencias'];

	/**
	 * Cómo reparte el colegio el peso entre las subunidades de una unidad.
	 *
	 * `porcentaje` es lo de siempre —cada subunidad pesa lo que diga su columna— y
	 * `promedio` las hace pesar igual, `1/n`. Entrega 5 del doc 28, encargo de
	 * Joseth del 2 sep 2026: lo que le quita al docente es teclear porcentajes y la
	 * clase entera de fallos de «esto no suma 100».
	 *
	 * **La lista vive aquí y un test comprueba que dice lo mismo que el `enum` de la
	 * columna**, por lo mismo que su hermana: con el `sql_mode` de estos servidores,
	 * un valor fuera del `enum` **no lanza — guarda la cadena vacía y devuelve
	 * 200**.
	 */
	public const REPARTOS_DE_SUBUNIDADES = ['porcentaje', 'promedio'];

	/**
	 * Los títulos con los que nacen los dos certificados, **y los mismos que el
	 * `DEFAULT` de sus columnas**.
	 *
	 * Viven aquí por lo mismo que `MODELOS_DE_EVALUACION`: son una propiedad de la
	 * columna, y dos sitios que dicen una cadena se separan sin que falle nada. Los
	 * compara contra `SHOW COLUMNS` el test
	 * `TitulosDelCertificadoTest::el_defecto_del_modelo_y_el_de_la_base_son_el_mismo`.
	 *
	 * **SON DISTINTOS, y ésa es la mitad que el encargo no pedía.** El certificado por
	 * periodos se emite con **el año sin cerrar** —«calcula hasta el periodo que usted
	 * elija», dice su propio botón— y hasta hoy decía lo mismo que el final, no porque
	 * nadie lo decidiera sino porque **comparten plantilla** en los dos fronts. La
	 * palabra que los separa es `PARCIAL` (Joseth, 15 sep 2026).
	 *
	 * Los dos conservan «constancia de desempeño» porque es el término del **Decreto
	 * 1290 art. 17**, que es justamente el que habla de las constancias «con los
	 * resultados de los informes periódicos» —o sea que le corresponde al parcial
	 * todavía más que al final— (doc 21 §1).
	 *
	 * **Y no son «lo que se imprime hoy», que es lo que hay que saber de esta
	 * constante.** El 15 sep 2026 se imprimían TRES textos distintos —el legacy decía
	 * «CONSTANCIA DE DESEMPEÑO», `app2` le había añadido «ACADÉMICO» al migrar la
	 * pantalla, y `coal` y `coljordan` decían «CERTIFICADO DE DESEMPEÑO» por
	 * `document.domain`—. Éstos son los que Joseth eligió para los dieciséis ese día,
	 * sabiendo que el papel de los dieciséis cambia. El porqué, en el §4 del doc 38.
	 *
	 * El array va indexado **por el nombre de la columna** y no por un alias, para que
	 * el que lo recorra no tenga que traducir nada: es la lista que el controlador
	 * valida y la que el test cruza con la base.
	 *
	 * @var array<string, string>
	 */
	public const TITULOS_POR_DEFECTO = [
		'titulo_certificado_final' => 'CONSTANCIA DE DESEMPEÑO ACADÉMICO',
		'titulo_certificado_periodos' => 'CONSTANCIA DE DESEMPEÑO ACADÉMICO PARCIAL',
		// La tercera, del 20 sep 2026: el título de la constancia de estudio. Entra
		// **sólo aquí** y con eso queda validada y escribible, porque `putEncabezado`
		// recorre esta constante en vez de repetir la lista — que es exactamente para
		// lo que se dejó escrito así el 15 sep. Lo que no se deriva solo es el corte de
		// `years/toggle-cambiar-valor`, y eso lo ata un test.
		'titulo_constancia_estudio' => 'CONSTANCIA DE ESTUDIO',
	];

	/**
	 * Lo que cabe en `titulo_certificado_final` y en `titulo_certificado_periodos`,
	 * **en caracteres y no en bytes**.
	 *
	 * Se comprueba en el controlador y no se le deja a la columna: el docker trunca
	 * en silencio y MariaDB aborta, así que el mismo tope da dos resultados
	 * distintos según el servidor y ninguno de los dos le dice nada a quien escribe.
	 * Un título es papel firmado — truncarlo sin avisar es peor que rechazarlo.
	 */
	public const LARGO_DEL_TITULO = 255;

	use SoftDeletes;
	use SellaConElReloj;
	protected $softDelete = true;

	public static function actual()
	{
		$consulta 	= "SELECT * FROM years WHERE actual=true and deleted_at is null";
		$year 		= DB::select($consulta)[0];
		return $year;
	}

	public static function de_un_periodo($periodo_id)
	{
		$periodo = Periodo::find($periodo_id);
		$year = Year::find($periodo->year_id);
		return $year;
	}

	
	/**
	 * Los datos del colegio para un informe.
	 *
	 * **`$actual` no es un parámetro suelto: es una regla de negocio**, y de las
	 * que un refactor bienintencionado borra por parecer un descuido. Con `true`
	 * —que es como lo llama casi todo— los firmantes salen del año **actual** y no
	 * del año del informe, a propósito: un boletín de hace tres años se firma con
	 * el rector y el secretario de hoy, porque **el rector de aquel año puede que
	 * ya no trabaje en el colegio** y un informe hay que poder firmarlo cuando se
	 * imprime. Contado por Joseth el 21 ago 2026; no estaba escrito en ninguna
	 * parte. Ver docs/migracion/05-codigo-muerto-y-roto.md §28.3.
	 *
	 * Con `false` salen los del año que se pide, que es lo que quiere quien está
	 * mirando ese año y no imprimiendo nada.
	 */
	public static function datos($year_id, $actual=true)
	{
		if ($actual) {
			$consulta = 'SELECT y.id as year_id, y.year, y.nombre_colegio, y.abrev_colegio, y.ciudad_id, c.ciudad, c.departamento, y.resolucion, y.codigo_dane, y.mostrar_puesto_boletin, y.puestos_alfabeticamente, y.show_fortaleza_bol, y.mostrar_nota_comport_boletin,
							y.logo_id, iL.nombre as logo, y.img_encabezado_id, iE.nombre as img_encabezado, y.nota_minima_aceptada, y.minu_hora_clase, y.encabezado_certificado, y.config_certificado_estudio_id, y.si_recupera_materia_recup_indicador, y.cant_areas_pierde_year, y.cant_asignatura_pierde_year,
							y.caracter, y.calendario, y.jornada, y.contador_certificados, y.usa_consecutivo_certificados, y.frase_final_certificado, y.titulo_certificado_final, y.titulo_certificado_periodos, y.titulo_constancia_estudio, y.contador_folios, y.usa_folio_certificados, y.texto_acta_eval, y.show_subasignaturas_en_finales, y.mensaje_aprobo_con_pendientes,
							y.msg_when_students_blocked, y.titulo_rector, y.compromiso_familiar_label, y.solo_escalas_valorativas, y.mostrar_nota_numerica_boletin,
							
							y.secretario_id, pSec.nombres as nombres_secretario, pSec.apellidos as apellidos_secretario, pSec.sexo as sexo_secretario, pSec.num_doc as secretario_documento,
							pSec.foto_id as secre_foto_id, IFNULL(iSec.nombre, IF(pSec.sexo="F","default_female.png", "default_male.png")) as secre_foto_nombre,
							pSec.firma_id as secre_firma_id, iFS.nombre as secre_firma, 

							y.rector_id, pRec.nombres as nombres_rector, pRec.apellidos as apellidos_rector, pRec.sexo as sexo_rector, pRec.num_doc as rector_documento,
							pRec.foto_id as rector_foto_id, IFNULL(iRec.nombre, IF(pRec.sexo="F","default_female.png", "default_male.png")) as rector_foto_nombre,
							pRec.firma_id as rector_firma_id, iFR.nombre as rector_firma

						FROM years y
						left join ciudades c on c.id=y.ciudad_id and c.deleted_at is null
						left join profesores pRec on pRec.id=y.rector_id and pRec.deleted_at is null
						left join profesores pSec on pSec.id=y.secretario_id and pSec.deleted_at is null

						left join images iL on y.logo_id=iL.id and iL.deleted_at is null
						left join images iE on y.img_encabezado_id=iE.id and iE.deleted_at is null

						left join images iFR on pRec.firma_id=iFR.id and iFR.deleted_at is null
						left join images iFS on pSec.firma_id=iFS.id and iFS.deleted_at is null
						left join images iRec on pRec.foto_id=iRec.id and iRec.deleted_at is null
						left join images iSec on pSec.foto_id=iSec.id and iSec.deleted_at is null

						where y.actual=true and y.deleted_at is null';

			$datos = DB::select($consulta)[0];

			return $datos;
		}else{
			$consulta = 'SELECT y.id as year_id, y.year, y.nombre_colegio, y.abrev_colegio, y.ciudad_id, c.ciudad, c.departamento, y.resolucion, y.codigo_dane, y.mostrar_puesto_boletin, y.puestos_alfabeticamente, y.show_fortaleza_bol, y.mostrar_nota_comport_boletin, 
							y.logo_id, iL.nombre as logo, y.img_encabezado_id, y.nota_minima_aceptada, y.minu_hora_clase, iE.nombre as img_encabezado, y.encabezado_certificado, y.config_certificado_estudio_id, y.si_recupera_materia_recup_indicador, y.cant_areas_pierde_year, y.cant_asignatura_pierde_year,
							y.caracter, y.calendario, y.jornada, y.contador_certificados, y.usa_consecutivo_certificados, y.frase_final_certificado, y.titulo_certificado_final, y.titulo_certificado_periodos, y.titulo_constancia_estudio, y.contador_folios, y.usa_folio_certificados, y.texto_acta_eval, y.show_subasignaturas_en_finales, y.mensaje_aprobo_con_pendientes,
							y.msg_when_students_blocked, y.titulo_rector, y.compromiso_familiar_label, y.solo_escalas_valorativas, y.mostrar_nota_numerica_boletin,

							y.secretario_id, pSec.nombres as nombres_secretario, pSec.apellidos as apellidos_secretario, pSec.sexo as sexo_secretario, pSec.num_doc as secretario_documento,
							pSec.foto_id as secre_foto_id, IFNULL(iSec.nombre, IF(pSec.sexo="F","default_female.png", "default_male.png")) as secre_foto_nombre,
							pSec.firma_id as secre_firma_id, iFS.nombre as secre_firma, 

							y.rector_id, pRec.nombres as nombres_rector, pRec.apellidos as apellidos_rector, pRec.sexo as sexo_rector, pRec.num_doc as rector_documento,
							pRec.foto_id as rector_foto_id, IFNULL(iRec.nombre, IF(pRec.sexo="F","default_female.png", "default_male.png")) as rector_foto_nombre,
							pRec.firma_id as rector_firma_id, iFR.nombre as rector_firma

						FROM years y
						left join ciudades c on c.id=y.ciudad_id and c.deleted_at is null
						left join profesores pRec on pRec.id=y.rector_id and pRec.deleted_at is null
						left join profesores pSec on pSec.id=y.secretario_id and pSec.deleted_at is null

						left join images iL on y.logo_id=iL.id and iL.deleted_at is null
						left join images iE on y.img_encabezado_id=iE.id and iE.deleted_at is null

						left join images iFR on pRec.firma_id=iFR.id and iFR.deleted_at is null
						left join images iFS on pSec.firma_id=iFS.id and iFS.deleted_at is null
						left join images iRec on pRec.foto_id=iRec.id and iRec.deleted_at is null
						left join images iSec on pSec.foto_id=iSec.id and iSec.deleted_at is null

						where y.id=:year_id and y.deleted_at is null';

			$datos = DB::select($consulta, [':year_id' => $year_id])[0];

			return $datos;
		}
		
	}

	
	public static function datos_basicos($year_id)
	{
		$consulta = 'SELECT y.id as year_id, y.year, y.nombre_colegio, y.abrev_colegio, y.ciudad_id, c.ciudad, c.departamento, y.resolucion, y.texto_acta_eval, y.titulo_rector,
						y.logo_id, iL.nombre as logo, y.img_encabezado_id, y.nota_minima_aceptada, iE.nombre as img_encabezado, y.encabezado_certificado, y.cant_areas_pierde_year, y.cant_asignatura_pierde_year,
						y.msg_when_students_blocked, y.solo_escalas_valorativas, y.mostrar_nota_numerica_boletin,

						y.secretario_id, pSec.nombres as nombres_secretario, pSec.apellidos as apellidos_secretario, pSec.sexo as sexo_secretario,
						y.rector_id, pRec.nombres as nombres_rector, pRec.apellidos as apellidos_rector, pRec.sexo as sexo_rector

					FROM years y 
					left join ciudades c on c.id=y.ciudad_id and c.deleted_at is null
					left join profesores pRec on pRec.id=y.rector_id and pRec.deleted_at is null
					left join profesores pSec on pSec.id=y.secretario_id and pSec.deleted_at is null

					left join images iL on y.logo_id=iL.id and iL.deleted_at is null
					left join images iE on y.img_encabezado_id=iE.id and iE.deleted_at is null

					where y.id=:year_id and y.deleted_at is null';

		$datos = DB::select($consulta, [':year_id' => $year_id])[0];

		return $datos;
	}

	public static function de_un_profesor($profesor_id)
	{
		$consulta = 'SELECT y.id, y.year, y.nombre_colegio, y.abrev_colegio FROM years y
					inner join contratos c on c.year_id=y.id and c.profesor_id = :profesor_id and c.deleted_at is null
					where y.deleted_at is null';

		$years = DB::select($consulta, array(':profesor_id' => $profesor_id));

		foreach ($years as $year) {
			$year->periodos = Periodo::where('year_id', $year->id)->get();
		}

		return $years;
	}

	
	
}