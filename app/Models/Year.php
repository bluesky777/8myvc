<?php namespace App\Models;

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
 * Y los atributos que NO son columnas: el código se los cuelga al modelo en
 * tiempo de ejecución para armar la respuesta, que es un patrón repetido por
 * todo el proyecto. Eloquent los guarda entre los atributos y salen en el JSON,
 * así que forman parte del contrato con el frontend igual que las columnas;
 * anotarlos es lo que permite que el análisis siga avisando de un nombre mal
 * escrito en vez de callarse con todos.
 *
 * @property \Illuminate\Database\Eloquent\Collection<int, \App\Models\Grupo> $grupos_ant  los grupos del año anterior, para el traspaso de año
 */


class Year extends Model {
	protected $table = 'years';

	use SoftDeletes;
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
							y.caracter, y.calendario, y.jornada, y.contador_certificados, y.usa_consecutivo_certificados, y.frase_final_certificado, y.contador_folios, y.usa_folio_certificados, y.texto_acta_eval, y.show_subasignaturas_en_finales, y.mensaje_aprobo_con_pendientes,
							y.msg_when_students_blocked, y.titulo_rector, y.compromiso_familiar_label, y.solo_escalas_valorativas,
							
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
							y.caracter, y.calendario, y.jornada, y.contador_certificados, y.usa_consecutivo_certificados, y.frase_final_certificado, y.contador_folios, y.usa_folio_certificados, y.texto_acta_eval, y.show_subasignaturas_en_finales, y.mensaje_aprobo_con_pendientes,
							y.msg_when_students_blocked, y.titulo_rector, y.compromiso_familiar_label, y.solo_escalas_valorativas,

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
						y.msg_when_students_blocked, y.solo_escalas_valorativas,

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