<?php namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

use Illuminate\Support\Facades\DB;
use App\Models\Debugging;
/**
 * Las columnas de `grupos`, tal como están en el esquema congelado.
 *
 * Generado desde database/schema/mysql-schema.sql — no se edita a mano.
 * Ver tools/columnas-en-los-modelos.php.
 *
 * --- columnas de la tabla, generadas por tools/columnas-en-los-modelos.php ---
 *
 * @property int $id
 * @property string $nombre
 * @property ?string $abrev
 * @property int $year_id
 * @property ?int $titular_id
 * @property int $grado_id
 * @property ?int $valormatricula
 * @property ?int $valorpension
 * @property ?int $orden
 * @property int $caritas
 * @property ?int $cupo
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
 * @property \Illuminate\Database\Eloquent\Collection<int, \App\Models\Asignatura> $asigs_ant  las asignaturas del año anterior, para el traspaso de año
 * @property \App\Models\Profesor $titular  el profesor titular, resuelto aparte en la ficha del grupo
 * @property \App\Models\Grado $grado  el grado del grupo, resuelto aparte en la ficha del grupo
 */

class Grupo extends Model {
	use SoftDeletes;

	protected $fillable = [];
	protected $table = 'grupos';
	
	protected $dates = ['deleted_at', 'created_at'];
	protected $softDelete = true;
	
	
	public static $consulta_grupos_titularia = 'SELECT g.id, g.nombre, g.abrev, g.orden, gra.orden as orden_grado, g.grado_id, g.year_id, g.titular_id,
							p.nombres as nombres_titular, p.apellidos as apellidos_titular, p.titulo,
							g.created_at, g.updated_at, gra.nombre as nombre_grado 
						from grupos g
						inner join grados gra on gra.id=g.grado_id and g.year_id=:year_id 
						inner join profesores p on p.id=g.titular_id and p.id=:titular_id
						where g.deleted_at is null
						order by g.orden';


	/**
	 * Los alumnos de un grupo.
	 *
	 * ## `$periodo_id`: el badge del boletín independiente, y sólo si se pide
	 *
	 * Con un periodo, cada alumno sale además con **`bol_independiente_datos`**:
	 * `true` = **tiene un boletín aparte guardado en este periodo**, aunque el periodo
	 * vaya con el grupo. Es la §6.4 de
	 * [19-boletin-independiente.md](../../../docs/migracion/19-boletin-independiente.md),
	 * y es `tiene_datos` de la ficha aplanado al periodo del token.
	 *
	 * **El parámetro es opcional y el campo no viaja sin él, a propósito.** Este método
	 * lo llaman veinticinco sitios —asistencias, disciplina, boletines, certificados,
	 * planillas de ausencias—, y a casi ninguno le consta un periodo: ponérselo a todos
	 * sería una consulta más por llamada y un campo más en veinte respuestas para que
	 * lo lea una. Quien lo necesita es la **planilla**, que es donde el docente ve la
	 * lista y tiene que poder distinguir al alumno que trae datos propios guardados.
	 *
	 * ## Y el campo tiene nombre nuevo porque el que había mentía por omisión
	 *
	 * La §6.4 decía que el badge era `alumno.bol_independiente_periodo`, y lo levantó el
	 * front al ir a escribirlo: a esta lista **sólo llegan los que van con el grupo**
	 * —los que van aparte los quita `putDetailed`—, así que ese booleano valdría
	 * `false` en las treinta filas, siempre, en todos los colegios. **Un campo que no
	 * varía no es un campo pobre: es uno sobre el que alguien ramificará sin que su rama
	 * muerta se note nunca.** El que hace falta separa dos casos que sí se distinguen:
	 * el que tiene estructura propia guardada y el que nunca ha tenido nada.
	 *
	 * @param  int|string  $grupo_id
	 * @param  mixed  $con_retirados
	 */
	public static function alumnos($grupo_id, $con_retirados='', ?int $periodo_id = null)
	{
		$consulta = '';
		$matriculas = [];

		if ($con_retirados=='') {
			// Consulta con solo los matriculados
			$consulta = 'SELECT m.id as matricula_id, m.alumno_id, a.no_matricula, m.nro_folio, a.nombres, a.apellidos, a.sexo, a.user_id, a.nee, a.nee_descripcion,
							a.fecha_nac, a.ciudad_nac, a.celular, a.direccion, a.religion, t.tipo as tipo_doc, t.abrev as tipo_doc_abrev, a.documento, a.no_matricula, 
							m.grupo_id, m.estado, m.nuevo, m.repitente, username, m.promovido, m.promedio, m.cant_asign_perdidas, m.cant_areas_perdidas,
							u.imagen_id, IFNULL(i.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as imagen_nombre, 
							a.foto_id, IFNULL(i2.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as foto_nombre
						FROM alumnos a 
						inner join matriculas m on a.id=m.alumno_id and m.grupo_id=? and (m.estado="MATR" or m.estado="ASIS" or m.estado="PREM") and m.deleted_at is null
						left join users u on a.user_id=u.id and u.deleted_at is null
						left join tipos_documentos t on a.tipo_doc=t.id and t.deleted_at is null
						left join images i on i.id=u.imagen_id and i.deleted_at is null
						left join images i2 on i2.id=a.foto_id and i2.deleted_at is null
						where a.deleted_at is null and m.deleted_at is null
						order by a.apellidos, a.nombres';
		}else{
			// Consulta incluyendo los matriculados y retirados.
			// $consulta = 'SELECT m.id as matricula_id, m.alumno_id, a.no_matricula, a.nombres, a.apellidos, a.sexo, a.user_id, a.nee, a.nee_descripcion, 
			// 				a.fecha_nac, a.ciudad_nac, a.celular, a.direccion, a.religion, t.tipo as tipo_doc, t.abrev as tipo_doc_abrev, a.documento, a.no_matricula, 
			// 				m.grupo_id, m.estado, m.nuevo, m.repitente, m.promovido, m.promedio, m.cant_asign_perdidas, m.cant_areas_perdidas,
			// 				u.imagen_id, IFNULL(i.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as imagen_nombre, 
			// 				a.foto_id, IFNULL(i2.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as foto_nombre,
			// 				m.fecha_retiro as fecha_retiro 
			// 			FROM alumnos a 
			// 			inner join matriculas m on a.id=m.alumno_id and m.grupo_id=? and m.deleted_at is null 
			// 			left join users u on a.user_id=u.id and u.deleted_at is null
			// 			left join tipos_documentos t on a.tipo_doc=t.id and t.deleted_at is null
			// 			left join images i on i.id=u.imagen_id and i.deleted_at is null
			// 			left join images i2 on i2.id=a.foto_id and i2.deleted_at is null
			// 			where a.deleted_at is null and m.deleted_at is null
			// 			order by a.apellidos, a.nombres';


			// Las matrículas que se piden aparte de las vigentes: sirve para que un
			// retirado siga saliendo en el boletín si se le pide por su matrícula.
			//
			// Dos cosas que este bucle hacía mal desde 6bc08ac (31 ago 2021):
			//
			// 1. Daba por hecho que todo elemento trae `matricula_id`. El frontend
			//    manda `[{alumno_id, grupo_id}]` cuando un alumno pide SU boletín o
			//    un acudiente el de su acudido —así desde 2018—, así que esas dos
			//    pantallas respondían 500 "Undefined array key matricula_id". Cinco
			//    años rotas, y nadie lo notó porque el error salía en la consola del
			//    navegador y no en la pantalla.
			// 2. Metía el valor en el SQL concatenando. Cualquiera con token podía
			//    inyectar por `requested_alumnos[i].matricula_id`, y esto lo llaman
			//    casi todos los informes.
			$matriculas = [];

			foreach ((array) $con_retirados as $pedido) {
				if (is_array($pedido) && ! empty($pedido['matricula_id'])) {
					$matriculas[] = $pedido['matricula_id'];
				}
			}

			$sql_condicion = $matriculas === []
				? ''
				: ' or m.id in (' . implode(',', array_fill(0, count($matriculas), '?')) . ')';

			// Prueba para excluir retirados pero incluir a los actuales solicitados
			$consulta = 'SELECT m.id as matricula_id, m.alumno_id, m.nro_folio, a.no_matricula, a.nombres, a.apellidos, a.sexo, a.user_id, a.nee, a.nee_descripcion,
							a.fecha_nac, a.ciudad_nac, a.celular, a.direccion, a.religion, t.tipo as tipo_doc, t.abrev as tipo_doc_abrev, a.documento, a.no_matricula, 
							m.grupo_id, m.estado, m.nuevo, m.repitente, username, m.promovido, m.promedio, m.cant_asign_perdidas, m.cant_areas_perdidas,
							u.imagen_id, IFNULL(i.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as imagen_nombre, 
							a.foto_id, IFNULL(i2.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as foto_nombre
						FROM alumnos a 
						inner join matriculas m on a.id=m.alumno_id and m.grupo_id=? and ((m.estado="MATR" or m.estado="ASIS" or m.estado="PREM") '.$sql_condicion.' ) and m.deleted_at is null
						left join users u on a.user_id=u.id and u.deleted_at is null
						left join tipos_documentos t on a.tipo_doc=t.id and t.deleted_at is null
						left join images i on i.id=u.imagen_id and i.deleted_at is null
						left join images i2 on i2.id=a.foto_id and i2.deleted_at is null
						where a.deleted_at is null and m.deleted_at is null
						order by a.apellidos, a.nombres';


		}

		$alumnos = DB::select($consulta, array_merge([$grupo_id], $matriculas));

		if ($periodo_id !== null) {
			self::marcarLosQueTienenDatosPropios($alumnos, (int) $periodo_id);
		}

		return $alumnos;
	}

	/**
	 * Les cuelga `bol_independiente_datos` a los alumnos de una lista.
	 *
	 * **Una consulta para toda la lista y no una por alumno**: son treinta, y esto lo
	 * llama la planilla, que ya es de las páginas caras.
	 *
	 * **Y entra por `alumno_id IN (...)` y no sólo por el periodo**, que es lo primero
	 * que se escribe y lo que hay que no hacer. Con `WHERE u.periodo_id = ?` a secas
	 * MySQL usa `unidades_periodo_id_foreign` y **recorre las unidades de ese periodo
	 * en todo el colegio** —unas 4.200 de las 16.931 medidas— para quedarse con las de
	 * treinta alumnos. Con los ids delante usa `unidades_alumno_id_foreign`: treinta
	 * búsquedas que hoy, sin nadie marcado, no devuelven **ninguna** fila.
	 *
	 * El predicado —«tiene alguna unidad propia viva en ese periodo»— es **el mismo**
	 * que el `EXISTS` de `AlumnosController::bolIndependientePeriodos`, que es lo que
	 * la ficha llama `tiene_datos`. Están escritos dos veces porque las dos preguntas
	 * tienen forma distinta —allí cuatro periodos de un alumno, aquí treinta alumnos
	 * de un periodo— y lo que los ata no es una cadena compartida sino un test que
	 * comprueba que **contestan lo mismo** para el mismo par (alumno, periodo).
	 *
	 * **No se filtra por las asignaturas del grupo, y es deliberado**: el campo tiene
	 * que decir exactamente lo mismo que el de la ficha, que tampoco filtra. Acotarlo
	 * aquí haría que la ficha dijera «tiene datos» y el badge no, sobre el mismo
	 * alumno y el mismo periodo — dos sitios contestando distinto a la misma pregunta,
	 * que es de donde salió el recalculador único.
	 *
	 * @param  list<object>  $alumnos
	 */
	private static function marcarLosQueTienenDatosPropios(array $alumnos, int $periodo_id): void
	{
		if ($alumnos === []) {
			return;
		}

		$ids = array_values(array_unique(array_map(static fn ($a) => (int) $a->alumno_id, $alumnos)));
		$marcas = implode(',', array_fill(0, count($ids), '?'));

		$filas = DB::select(
			'SELECT DISTINCT u.alumno_id
			   FROM unidades u
			  WHERE u.alumno_id IN ('.$marcas.') AND u.periodo_id = ? AND u.deleted_at IS NULL',
			array_merge($ids, [$periodo_id])
		);

		$con_datos = array_flip(array_map(static fn ($f) => (int) $f->alumno_id, $filas));

		foreach ($alumnos as $alumno) {
			$alumno->bol_independiente_datos = isset($con_datos[(int) $alumno->alumno_id]);
		}
	}

	public static function detailed_materias($grupo_id, $profesor_id=null, $exceptuando=false)
	{
		$complemento = ''; // Para complementar la consulta
		if ($profesor_id) {
			if ($exceptuando) {
				$complemento = ' and p.id!='.$profesor_id. ' ';
			}else{
				$complemento = ' and p.id='.$profesor_id. ' ';
			}
		}

		$consulta = 'SELECT @rownum:=@rownum+1 AS indice, r.*
			FROM(SELECT a.id as asignatura_id, a.grupo_id, a.profesor_id, a.creditos, ar.orden as orden_area, m.orden as orden_materia, a.orden as orden_asignatura,
				m.materia, m.alias as alias_materia, m.area_id,
				p.nombres as nombres_profesor, p.apellidos as apellidos_profesor,
				p.foto_id, IFNULL(i.nombre, IF(p.sexo="F","default_female.png", "default_male.png")) as foto_nombre
			FROM (SELECT @rownum:=0) r, asignaturas a 
			inner join materias m on m.id=a.materia_id and m.deleted_at is null
			left join areas ar on ar.id=m.area_id and ar.deleted_at is null
			inner join profesores p on p.id=a.profesor_id and p.deleted_at is null
			left join images i on p.foto_id=i.id and i.deleted_at is null
			where a.grupo_id=:grupo_id and a.deleted_at is null
			order by ar.orden, m.orden, a.orden)r';

		$asignaturas = DB::select($consulta, [':grupo_id' => $grupo_id]);

		return $asignaturas;
	}

	

	

	public static function detailed_materias_notafinal($alumno_id, $grupo_id, $periodo_id, $year_id)
	{
		// **El par de la DEFINITIVA del periodo** — A10, 27 §2.1.
		//
		// `nota_asignatura` sigue siendo **la vigente**, la que ya se imprimía; al lado va
		// de dónde venía. `recuperada` no cambia de significado —1 ⇔ viene de una
		// nivelación— y lo que se gana es que ahora puede decir de qué nota.
		//
		// Los alias llevan el sufijo `_asignatura` como `nota_asignatura`, y no es
		// cosmético: de esta consulta cuelgan tres informes que **también** traen el par
		// del indicador (`Subunidad::deUnidadCalculada`), y dos claves `nota_original` en
		// la misma respuesta significando cosas distintas es la forma de que el papel
		// imprima la del nivel equivocado.
		//
		// El comentario va aquí y no dentro del SQL a propósito: un `--` dentro de la
		// cadena comentaría el resto de la consulta el día que alguien normalice los
		// saltos de línea.
		$consulta = 'SELECT a.id as asignatura_id, a.grupo_id, a.profesor_id, a.creditos, ar.orden as orden_area, m.orden as orden_materia, a.orden,
				m.materia, m.alias as alias_materia, m.area_id, ar.nombre as area_nombre, ar.alias as area_alias, a.materia_id, 
				p.nombres as nombres_profesor, p.apellidos as apellidos_profesor,
				p.foto_id, IFNULL(i.nombre, IF(p.sexo="F","default_female.png", "default_male.png")) as foto_nombre, 
				CAST(n.nota AS DOUBLE) as nota_asignatura, n.created_at, n.recuperada, n.manual, e.desempenio, n.id as nf_id,
				CAST(n.nota_original AS DOUBLE) as nota_original_asignatura, n.nivelada_at as nivelada_at_asignatura
			FROM asignaturas a 
			inner join materias m on m.id=a.materia_id and m.deleted_at is null
			left join areas ar on ar.id=m.area_id and ar.deleted_at is null
			left join notas_finales n on n.asignatura_id=a.id and n.alumno_id=:alumno_id and n.periodo_id=:periodo_id
			inner join profesores p on p.id=a.profesor_id and p.deleted_at is null 
			left join images i on p.foto_id=i.id and i.deleted_at is null
			left join escalas_de_valoracion e ON e.porc_inicial<=n.nota and e.porc_final>=n.nota and e.deleted_at is null and e.year_id=:year_id
			where a.grupo_id=:grupo_id and a.deleted_at is null
			order by ar.orden, m.orden, a.orden';

		$asignaturas = DB::select($consulta, [ ':alumno_id' => $alumno_id, ':periodo_id' => $periodo_id, ':year_id' => $year_id, ':grupo_id' => $grupo_id ]);

		return $asignaturas;
	}

	
	
	
	
	public static function detailed_materias_notas_finales($alumno_id, $grupo_id, $year_id, $num_periodo=4)
	{
		$asignaturas = [];
		
		if ($num_periodo == 1) {
			
			$consulta = 'SELECT @rownum:=@rownum+1 AS indice, r.*
						from(SELECT nf.asignatura_id, a.grupo_id, a.profesor_id, a.creditos, ar.orden as orden_area, m.orden as orden_materia, a.orden as orden_asignatura,
								m.materia, m.alias as alias_materia, ar.nombre as area_nombre, m.area_id, ar.alias as area_alias,
								p.nombres as nombres_profesor, p.apellidos as apellidos_profesor,
								p.foto_id, IFNULL(i.nombre, IF(p.sexo="F","default_female.png", "default_male.png")) as foto_nombre,
								nf.nota_final_per1, nf.nf_id_1, nf.nf_updated_at1, e.desempenio 
							FROM (SELECT @rownum:=0) r, periodos pe 
							inner join (
								select distinct nf.asignatura_id, nf.alumno_id, CAST(nf.nota AS DOUBLE) as nota_final_per1, nf.id as nf_id_1, nf.updated_at as nf_updated_at1, nf.periodo, nf.periodo_id  from notas_finales nf order by nf.id desc
							)nf on nf.alumno_id=:al1 and pe.numero=1 and pe.id=nf.periodo_id and pe.year_id=:year_id1 and pe.deleted_at is null
							left join escalas_de_valoracion e ON e.porc_inicial<=nf.nota_final_per1 and e.porc_final>=nf.nota_final_per1 and e.deleted_at is null and e.year_id=:year_id5
							right join asignaturas a on a.id=nf.asignatura_id and a.deleted_at is null
							inner join materias m on m.id=a.materia_id and m.deleted_at is null
							inner join areas ar on ar.id=m.area_id and ar.deleted_at is null
							inner join grupos g on g.id=a.grupo_id and g.deleted_at is null and g.id=:grupo_id
							inner join profesores p on p.id=a.profesor_id and p.deleted_at is null 
							left join images i on p.foto_id=i.id and i.deleted_at is null
							where a.deleted_at is null and a.profesor_id is not null
							order by ar.orden, m.orden, a.orden)r';

			$asignaturas = DB::select($consulta, [ ':al1' => $alumno_id, ':year_id1' => $year_id, ':grupo_id' => $grupo_id, ':year_id5' => $year_id]);

		}elseif ($num_periodo == 2) {
			
			$consulta = 'SELECT @rownum:=@rownum+1 AS indice,
						r1.*, r2.nota_final_per2, r2.nf_id_2, r2.nf_updated_at2, r2.desempenio
					FROM (SELECT @rownum:=0) r,
						(SELECT nf.asignatura_id, a.grupo_id, a.profesor_id, a.creditos, ar.orden as orden_area, m.orden as orden_materia, a.orden as orden_asignatura,
							m.materia, m.alias as alias_materia, ar.nombre as area_nombre, m.area_id, ar.alias as area_alias,
							p.nombres as nombres_profesor, p.apellidos as apellidos_profesor,
							p.foto_id, IFNULL(i.nombre, IF(p.sexo="F","default_female.png", "default_male.png")) as foto_nombre,
							nf.nota_final_per1, nf.nf_id_1, nf.nf_updated_at1 
						FROM periodos pe 
						inner join (
							select distinct nf.asignatura_id, nf.alumno_id, CAST(nf.nota AS DOUBLE) as nota_final_per1, nf.id as nf_id_1, nf.updated_at as nf_updated_at1, nf.periodo, nf.periodo_id  from notas_finales nf order by nf.id desc
						)nf on nf.alumno_id=:al1 and pe.numero=1 and pe.id=nf.periodo_id and pe.year_id=:year_id1 and pe.deleted_at is null
						right join asignaturas a on a.id=nf.asignatura_id and a.deleted_at is null
						inner join materias m on m.id=a.materia_id and m.deleted_at is null
						inner join areas ar on ar.id=m.area_id and ar.deleted_at is null
						inner join grupos g on g.id=a.grupo_id and g.deleted_at is null and g.id=:grupo_id
						inner join profesores p on p.id=a.profesor_id and p.deleted_at is null 
						left join images i on p.foto_id=i.id and i.deleted_at is null
						where a.deleted_at is null and a.profesor_id is not null
						)r1 
					left join 
						(SELECT nf.nota_final_per2, nf.nf_id_2, nf.nf_updated_at2, nf.asignatura_id, e.desempenio FROM periodos p 
						inner join (
								select distinct nf.asignatura_id, nf.alumno_id, CAST(nf.nota AS DOUBLE) as nota_final_per2, nf.id as nf_id_2, nf.updated_at as nf_updated_at2, nf.periodo, nf.periodo_id  from notas_finales nf order by nf.id desc
							)nf on nf.alumno_id=:al2 and p.numero=2 and p.id=nf.periodo_id and p.year_id=:year_id2 and p.deleted_at is null
							left join escalas_de_valoracion e ON e.porc_inicial<=nf.nota_final_per2 and e.porc_final>=nf.nota_final_per2 and e.deleted_at is null and e.year_id=:year_id5
						)r2 on r1.asignatura_id=r2.asignatura_id
					order by r1.orden_area, r1.orden_materia, r1.orden_asignatura';

			$asignaturas = DB::select($consulta, [ ':al1' => $alumno_id, ':year_id1' => $year_id, ':grupo_id' => $grupo_id, 
									':al2' => $alumno_id, ':year_id2' => $year_id, ':year_id5' => $year_id]);

		}elseif ($num_periodo == 3) {
			$consulta = 'SELECT @rownum:=@rownum+1 AS indice,
						r1.*, r2.nota_final_per2, r2.nf_id_2, r2.nf_updated_at2,
						r3.nota_final_per3, r3.nf_id_3, r3.nf_updated_at3, r3.desempenio
					FROM (SELECT @rownum:=0) r,
						(SELECT nf.asignatura_id, a.grupo_id, a.profesor_id, a.creditos, ar.orden as orden_area, m.orden as orden_materia, a.orden as orden_asignatura,
							m.materia, m.alias as alias_materia, ar.nombre as area_nombre, m.area_id, ar.alias as area_alias,
							p.nombres as nombres_profesor, p.apellidos as apellidos_profesor,
							p.foto_id, IFNULL(i.nombre, IF(p.sexo="F","default_female.png", "default_male.png")) as foto_nombre,
							nf.nota_final_per1, nf.nf_id_1, nf.nf_updated_at1 
						FROM periodos pe 
						inner join (
							select distinct nf.asignatura_id, nf.alumno_id, CAST(nf.nota AS DOUBLE) as nota_final_per1, nf.id as nf_id_1, nf.updated_at as nf_updated_at1, nf.periodo, nf.periodo_id  from notas_finales nf order by nf.id desc
						)nf on nf.alumno_id=:al1 and pe.numero=1 and pe.id=nf.periodo_id and pe.year_id=:year_id1 and pe.deleted_at is null
						right join asignaturas a on a.id=nf.asignatura_id and a.deleted_at is null
						inner join materias m on m.id=a.materia_id and m.deleted_at is null
						inner join areas ar on ar.id=m.area_id and ar.deleted_at is null
						inner join grupos g on g.id=a.grupo_id and g.deleted_at is null and g.id=:grupo_id
						inner join profesores p on p.id=a.profesor_id and p.deleted_at is null 
						left join images i on p.foto_id=i.id and i.deleted_at is null
						where a.deleted_at is null and a.profesor_id is not null
						)r1 
					left join 
						(SELECT nf.nota_final_per2, nf.nf_id_2, nf.nf_updated_at2, nf.asignatura_id FROM periodos p 
						inner join (
								select distinct nf.asignatura_id, nf.alumno_id, CAST(nf.nota AS DOUBLE) as nota_final_per2, nf.id as nf_id_2, nf.updated_at as nf_updated_at2, nf.periodo, nf.periodo_id  from notas_finales nf order by nf.id desc
							)nf on nf.alumno_id=:al2 and p.numero=2 and p.id=nf.periodo_id and p.year_id=:year_id2 and p.deleted_at is null
						)r2 on r1.asignatura_id=r2.asignatura_id
					left join 
						(SELECT nf.nota_final_per3, nf.nf_id_3, nf.nf_updated_at3, nf.asignatura_id, e.desempenio FROM periodos p 
						inner join (
								select distinct nf.asignatura_id, nf.alumno_id, CAST(nf.nota AS DOUBLE) as nota_final_per3, nf.id as nf_id_3, nf.updated_at as nf_updated_at3, nf.periodo, nf.periodo_id  from notas_finales nf order by nf.id desc
							)nf on nf.alumno_id=:al3 and p.numero=3 and p.id=nf.periodo_id and p.year_id=:year_id3 and p.deleted_at is null
							left join escalas_de_valoracion e ON e.porc_inicial<=nf.nota_final_per3 and e.porc_final>=nf.nota_final_per3 and e.deleted_at is null and e.year_id=:year_id5
						)r3 on r2.asignatura_id=r3.asignatura_id
					order by r1.orden_area, r1.orden_materia, r1.orden_asignatura';

			$asignaturas = DB::select($consulta, [ ':al1' => $alumno_id, ':year_id1' => $year_id, ':grupo_id' => $grupo_id, 
									':al2' => $alumno_id, ':year_id2' => $year_id, ':al3' => $alumno_id, ':year_id3' => $year_id, ':year_id5' => $year_id]);

		}elseif ($num_periodo == 4) {
			$consulta = 'SELECT @rownum:=@rownum+1 AS indice,
						r1.*, r2.nota_final_per2, r2.nf_id_2, r2.nf_updated_at2,
						r3.nota_final_per3, r3.nf_id_3, r3.nf_updated_at3,
						r4.nota_final_per4, r4.nf_id_4, r4.nf_updated_at4, r4.desempenio
					FROM (SELECT @rownum:=0) r,
						(SELECT nf.asignatura_id, a.grupo_id, a.profesor_id, a.creditos, ar.orden as orden_area, m.orden as orden_materia, a.orden as orden_asignatura,
							m.materia, m.alias as alias_materia, ar.nombre as area_nombre, m.area_id, ar.alias as area_alias,
							p.nombres as nombres_profesor, p.apellidos as apellidos_profesor,
							p.foto_id, IFNULL(i.nombre, IF(p.sexo="F","default_female.png", "default_male.png")) as foto_nombre,
							nf.nota_final_per1, nf.nf_id_1, nf.nf_updated_at1 
						FROM periodos pe 
						inner join (
							select distinct nf.asignatura_id, nf.alumno_id, CAST(nf.nota AS DOUBLE) as nota_final_per1, nf.id as nf_id_1, nf.updated_at as nf_updated_at1, nf.periodo, nf.periodo_id  from notas_finales nf order by nf.id desc
						)nf on nf.alumno_id=:al1 and pe.numero=1 and pe.id=nf.periodo_id and pe.year_id=:year_id1 and pe.deleted_at is null
						right join asignaturas a on a.id=nf.asignatura_id and a.deleted_at is null
						inner join materias m on m.id=a.materia_id and m.deleted_at is null
						inner join areas ar on ar.id=m.area_id and ar.deleted_at is null
						inner join grupos g on g.id=a.grupo_id and g.deleted_at is null and g.id=:grupo_id
						inner join profesores p on p.id=a.profesor_id and p.deleted_at is null 
						left join images i on p.foto_id=i.id and i.deleted_at is null
						where a.deleted_at is null and a.profesor_id is not null
						)r1 
					left join 
						(SELECT nf.nota_final_per2, nf.nf_id_2, nf.nf_updated_at2, nf.asignatura_id FROM periodos p 
						inner join (
								select distinct nf.asignatura_id, nf.alumno_id, CAST(nf.nota AS DOUBLE) as nota_final_per2, nf.id as nf_id_2, nf.updated_at as nf_updated_at2, nf.periodo, nf.periodo_id  from notas_finales nf order by nf.id desc
							)nf on nf.alumno_id=:al2 and p.numero=2 and p.id=nf.periodo_id and p.year_id=:year_id2 and p.deleted_at is null
						)r2 on r1.asignatura_id=r2.asignatura_id
					left join 
						(SELECT nf.nota_final_per3, nf.nf_id_3, nf.nf_updated_at3, nf.asignatura_id FROM periodos p 
						inner join (
								select distinct nf.asignatura_id, nf.alumno_id, CAST(nf.nota AS DOUBLE) as nota_final_per3, nf.id as nf_id_3, nf.updated_at as nf_updated_at3, nf.periodo, nf.periodo_id  from notas_finales nf order by nf.id desc
							)nf on nf.alumno_id=:al3 and p.numero=3 and p.id=nf.periodo_id and p.year_id=:year_id3 and p.deleted_at is null
						)r3 on r2.asignatura_id=r3.asignatura_id
					left join 
						(SELECT nf.nota_final_per4, nf.nf_id_4, nf.nf_updated_at4, nf.asignatura_id, e.desempenio FROM periodos p 
						inner join (
								select distinct nf.asignatura_id, nf.alumno_id, CAST(nf.nota AS DOUBLE) as nota_final_per4, nf.id as nf_id_4, nf.updated_at as nf_updated_at4, nf.periodo, nf.periodo_id  from notas_finales nf order by nf.id desc
							)nf on nf.alumno_id=:al4 and p.numero=4 and p.id=nf.periodo_id and p.year_id=:year_id4 and p.deleted_at is null
						left join escalas_de_valoracion e ON e.porc_inicial<=nf.nota_final_per4 and e.porc_final>=nf.nota_final_per4 and e.deleted_at is null and e.year_id=:year_id5
						)r4 on r3.asignatura_id=r4.asignatura_id
					order by r1.orden_area, r1.orden_materia, r1.orden_asignatura';

			$asignaturas = DB::select($consulta, [ ':al1' => $alumno_id, ':year_id1' => $year_id, ':grupo_id' => $grupo_id, 
									':al2' => $alumno_id, ':year_id2' => $year_id, ':al3' => $alumno_id, ':year_id3' => $year_id, ':al4' => $alumno_id, ':year_id4' => $year_id , ':year_id5' => $year_id ]);

		}
		
		return $asignaturas;
	}

	
	
	public static function datos($grupo_id)
	{
		$consulta = 'SELECT g.id as grupo_id, g.titular_id, g.nombre as nombre_grupo, g.abrev as abrev_grupo,
						g.caritas, g.grado_id, g.orden, 
						p.nombres as nombres_profesor, p.apellidos as apellidos_profesor,
						p.foto_id, IFNULL(i.nombre, IF(p.sexo="F","default_female.png", "default_male.png")) as foto_nombre,
						p.firma_id, i2.nombre as firma_titular_nombre
					FROM grupos g 
					left join grados gr on gr.id=g.grado_id and gr.deleted_at is null
					left join profesores p on p.id=g.titular_id and p.deleted_at is null
					left join images i on p.foto_id=i.id and i.deleted_at is null
					left join images i2 on p.firma_id=i2.id and i.deleted_at is null
					where g.id=:grupo_id and g.deleted_at is null';

		$datos = DB::select($consulta, [':grupo_id' => $grupo_id]);

		// **404 y no `[0]` a pelo.** Este `[0]` era un 500 —«Undefined array key
		// 0»— para cualquier grupo que no exista **o esté en la papelera**, porque
		// la consulta filtra `g.deleted_at is null`. Lo destapó `myvc-front-12`
		// verificando en Chrome el 24 ago 2026: `boletines/detailed-notas-group/1`
		// devolvía 500 y la pantalla no reventaba ni pintaba veneno, así que no lo
		// había visto nadie. El grupo 1 de la copia de producción existe y está
		// borrado **desde 2018**.
		//
		// Los diecisiete llamantes de este método hacen lo mismo —lo asignan y
		// usan sus campos—, o sea que ninguno sabe tratar la ausencia: para todos,
		// 404 es la respuesta correcta y es estrictamente mejor que la traza de
		// PHP que daban hasta hoy.
		if ($datos === []) {
			abort(404, 'El grupo no existe o está en la papelera.');
		}

		return $datos[0];
	}
}


