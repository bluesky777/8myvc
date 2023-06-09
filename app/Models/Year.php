<?php namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

use DB;

use App\Models\Periodo;


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

	
	public static function datos($year_id, $actual=true)
	{
		if ($actual) {
			$consulta = 'SELECT y.id as year_id, y.year, y.nombre_colegio, y.abrev_colegio, y.ciudad_id, c.ciudad, c.departamento, y.resolucion, y.codigo_dane, y.mostrar_puesto_boletin, y.puestos_alfabeticamente, y.show_fortaleza_bol, y.mostrar_nota_comport_boletin,
							y.logo_id, iL.nombre as logo, y.img_encabezado_id, iE.nombre as img_encabezado, y.nota_minima_aceptada, y.minu_hora_clase, y.encabezado_certificado, y.config_certificado_estudio_id, y.si_recupera_materia_recup_indicador, y.cant_areas_pierde_year, y.cant_asignatura_pierde_year,
							y.caracter, y.calendario, y.jornada, y.contador_certificados, y.frase_final_certificado, y.contador_folios, y.texto_acta_eval, y.show_subasignaturas_en_finales, y.mensaje_aprobo_con_pendientes,
							y.msg_when_students_blocked, y.titulo_rector,
							
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
							y.caracter, y.calendario, y.jornada, y.contador_certificados, y.frase_final_certificado, y.contador_folios, y.texto_acta_eval, y.show_subasignaturas_en_finales, y.mensaje_aprobo_con_pendientes,
							y.msg_when_students_blocked, y.titulo_rector,

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
		$consulta = 'SELECT y.id as year_id, y.year, y.nombre_colegio, y.abrev_colegio, y.ciudad_id, c.ciudad, c.departamento, y.resolucion, y.texto_acta_eval,
						y.logo_id, iL.nombre as logo, y.img_encabezado_id, y.nota_minima_aceptada, iE.nombre as img_encabezado, y.encabezado_certificado, y.cant_areas_pierde_year, y.cant_asignatura_pierde_year,
						y.msg_when_students_blocked,

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

		$years = DB::select(DB::raw($consulta), array(':profesor_id' => $profesor_id));

		foreach ($years as $year) {
			$year->periodos = Periodo::where('year_id', $year->id)->get();
		}

		return $years;
	}

	
	
}