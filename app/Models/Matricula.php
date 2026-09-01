<?php namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

use App\Models\Year;
use \Log;
/**
 * Las columnas de `matriculas`, tal como están en el esquema congelado.
 *
 * Generado desde database/schema/mysql-schema.sql — no se edita a mano.
 * Ver tools/columnas-en-los-modelos.php.
 *
 * --- columnas de la tabla, generadas por tools/columnas-en-los-modelos.php ---
 *
 * @property int $id
 * @property int $alumno_id
 * @property int $grupo_id
 * @property string $estado
 * @property ?string $prematriculado
 * @property ?string $fecha_retiro
 * @property ?string $fecha_matricula
 * @property ?string $fecha_pension
 * @property ?string $razon_retiro
 * @property ?string $programar
 * @property ?string $descripcion_recomendacion
 * @property ?string $efectuar_una
 * @property ?string $descripcion_efectuada
 * @property ?int $profes_editar_notas
 * @property ?int $nuevo
 * @property int $repitente
 * @property string $promovido
 * @property float $promedio
 * @property int $cant_asign_perdidas
 * @property int $cant_areas_perdidas
 * @property int $anios_in_cole
 * @property ?string $nro_folio
 * @property ?int $created_by
 * @property ?int $updated_by
 * @property ?int $deleted_by
 * @property ?string $deleted_at
 * @property ?string $created_at
 * @property ?string $updated_at
 * --- fin de las columnas generadas ---
 */

class Matricula extends Model {

	protected $table = 'matriculas';

	protected $fillable = [];

	use SoftDeletes;
	protected $softDelete = true;


	/**
	 * **Qué fila de `matriculas` es «la del año» — la regla, en un solo sitio.**
	 *
	 * Es la §9.5 del [plan](../../docs/migracion/19-boletin-independiente.md), y existe
	 * porque `matriculas` **no tiene clave única sobre (alumno, año)**: nada impide dos
	 * filas vivas del mismo alumno en el mismo año, y la lectura y la escritura elegían
	 * cada una la suya.
	 *
	 * | | Consulta | `m.deleted_at` | `g.deleted_at` | `ORDER BY` | Se queda con |
	 * |---|---|---|---|---|---|
	 * | **escribe** | `Alumnos\GuardarAlumno::valor` | **no filtra** | **no filtra** | **ninguno** | `[0]` |
	 * | **lee** | `AlumnosController::putShow` | filtra | filtra | `a.apellidos, a.nombres` | `[0]` |
	 *
	 * **Y el `ORDER BY` de la lectura no desempata nada**: para un solo alumno,
	 * ordenar por su apellido y su nombre es un empate total, así que las dos se quedan
	 * con «la primera que devuelva MySQL» y **nada garantiza que sea la misma**. Le
	 * pasa a `repitente`, `promovido` y `nro_folio`: se lee de una y se escribe en otra,
	 * y **nadie lo ve porque nadie mira esos campos al día siguiente**.
	 *
	 * > **Son TRES columnas y no cuatro.** La marca del boletín independiente salió de
	 * > aquí el 31 ago 2026: vive en `bol_ind_periodos`, que cuelga de
	 * > `(alumno_id, periodo_id)` **con clave única**, así que ahí no hay dos filas
	 * > entre las que equivocarse. Contarla sería contar un sitio que ya no existe.
	 *
	 * ## La decisión: la VIVA, y entre varias vivas, la MÁS RECIENTE
	 *
	 * Sale de lo que ya hace `matricularUno()` cincuenta líneas más abajo, que es el
	 * único sitio que crea matrículas: cuando encuentra varias del mismo año **activa
	 * una y borra las demás**. O sea que el sistema ya promete *«una viva por año»* — lo
	 * que falta es que quien lee y quien escribe **lean esa promesa igual** cuando no se
	 * cumple.
	 *
	 * Y entre dos vivas gana la más reciente porque una segunda fila sólo aparece si
	 * **alguien volvió a matricular**: el acto posterior sustituye al anterior. El
	 * `id DESC` no es decoración — `matriculas.created_at` es *nullable*, y sin él dos
	 * filas sin fecha volverían a quedar en manos del orden físico, que es justo el
	 * fallo del que va todo esto.
	 *
	 * ## Población medida, para que nadie lea esto como si fuera masivo
	 *
	 * En la copia de `simonbolivar` el 1 sep 2026, sobre **3.579 matrículas**:
	 *
	 * - **3.578** pares (alumno, año) con matrícula viva, y de ellos **uno solo** con
	 *   dos vivas — el alumno 1097 en el año 7, con `promovido` y `nro_folio`
	 *   **distintos** en las dos filas. Ése es el caso alcanzable hoy.
	 * - **cero** matrículas borradas en toda la tabla, y **cero** matrículas vivas
	 *   colgando de un grupo borrado. O sea que los dos filtros que le faltan al
	 *   escritor **hoy no cambian nada en este colegio**: son latentes, no activos. Se
	 *   ponen porque la promesa tiene que ser la misma en los dos lados, no porque se
	 *   estén disparando.
	 *
	 * **Un colegio, no quince.** Lo que se midió es la copia que hay delante.
	 */
	public const FILTRO_DEL_ANIO = 'm.deleted_at IS NULL AND g.deleted_at IS NULL';

	/**
	 * El desempate de `FILTRO_DEL_ANIO`, para el `ORDER BY`. Ver su docblock.
	 *
	 * Va aparte y no dentro porque en SQL crudo los dos trozos caen en cláusulas
	 * distintas. **Son una sola regla**: quien use uno sin el otro se queda con la
	 * mitad, y la mitad que falta es justo la que hoy está rota.
	 */
	public const ORDEN_DEL_ANIO = 'm.created_at DESC, m.id DESC';

	/**
	 * La matrícula del año de un alumno, o `null` si no tiene ninguna.
	 *
	 * Para quien sólo necesita **la fila**; quien ya tiene su propio `JOIN` grande
	 * —la ficha— pega las dos constantes de arriba a su consulta y se queda con
	 * `[0]`. Las dos formas responden lo mismo porque las dos citan la misma regla,
	 * que es el punto entero de la §9.5.
	 *
	 * Los alias `m` y `g` están dentro de las constantes, así que esta consulta los usa
	 * y no otros. Es el mismo trato que `BoletinIndependiente::JOIN_ESTADO`.
	 */
	public static function laDelAnio(int $alumno_id, int $year_id): ?object
	{
		return DB::selectOne(
			'SELECT m.id, m.alumno_id, m.grupo_id, m.estado, m.repitente, m.promovido, m.nro_folio,
			        m.created_at, g.year_id
			   FROM matriculas m
			  INNER JOIN grupos g ON g.id = m.grupo_id AND g.year_id = ?
			  WHERE m.alumno_id = ? AND '.self::FILTRO_DEL_ANIO.'
			  ORDER BY '.self::ORDEN_DEL_ANIO.'
			  LIMIT 1',
			[$year_id, $alumno_id]
		);
	}


	public static $consulta_asistentes_o_matriculados = 'SELECT m.id as matricula_id, m.alumno_id, m.nro_folio, a.no_matricula, a.nombres, a.apellidos, a.sexo, a.user_id, a.egresado,
							a.fecha_nac, a.ciudad_nac, c1.ciudad as ciudad_nac_nombre, a.tipo_doc, a.documento, a.ciudad_doc, c2.ciudad as ciudad_doc_nombre, a.tipo_sangre, a.eps, a.telefono, a.celular, 
							a.direccion, a.barrio, a.estrato, a.ciudad_resid, c3.ciudad as ciudad_resid_nombre, a.religion, a.email, a.facebook, a.created_by, a.updated_by,
							a.pazysalvo, a.presencial, m.promovido, a.deuda, m.grupo_id, m.prematriculado, 
							u.imagen_id, IFNULL(i.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as imagen_nombre, 
							u.username, u.is_superuser, u.is_active, a.nee, a.nee_descripcion,
							a.foto_id, IFNULL(i2.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as foto_nombre,
							m.fecha_retiro as fecha_retiro, m.estado, m.fecha_matricula, m.nuevo, m.repitente 
						FROM alumnos a 
						inner join matriculas m on a.id=m.alumno_id and m.grupo_id=:grupo_id and (m.estado="ASIS" or m.estado="MATR" or m.estado="PREM" or m.estado="PREA")
						left join users u on a.user_id=u.id and u.deleted_at is null
						left join images i on i.id=u.imagen_id and i.deleted_at is null
						left join images i2 on i2.id=a.foto_id and i2.deleted_at is null
						left join ciudades c1 on c1.id=a.ciudad_nac and c1.deleted_at is null
						left join ciudades c2 on c2.id=a.ciudad_doc and c2.deleted_at is null
						left join ciudades c3 on c3.id=a.ciudad_resid and c3.deleted_at is null
						where a.deleted_at is null and m.deleted_at is null
						order by a.apellidos, a.nombres';




	public static $consulta_asistentes_o_matriculados_simat = 'SELECT m.id as matricula_id, m.alumno_id, m.nro_folio, a.no_matricula, a.nombres, a.apellidos, a.sexo, a.user_id, a.egresado,
							a.fecha_nac, a.ciudad_nac, c1.departamento as departamento_nac_nombre, c1.ciudad as ciudad_nac_nombre, a.tipo_doc, t1.tipo as tipo_doc_name, a.documento, a.ciudad_doc, 
							c2.ciudad as ciudad_doc_nombre, c2.departamento as departamento_doc_nombre, a.tipo_sangre, a.eps, a.telefono, a.celular, 
							a.direccion, a.barrio, a.is_urbana, a.estrato, a.ciudad_resid, c3.ciudad as ciudad_resid_nombre, c3.departamento as departamento_resid_nombre, a.religion, a.email, a.facebook, a.created_by, a.updated_by,
							a.pazysalvo, a.deuda, m.grupo_id, a.is_urbana, IF(a.is_urbana, "SI", "NO") as es_urbana,
							t1.tipo as tipo_doc, t1.abrev as tipo_doc_abrev,
							u.imagen_id, IFNULL(i.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as imagen_nombre, 
							u.username, u.is_superuser, u.is_active, a.nee, a.nee_descripcion,
							a.foto_id, IFNULL(i2.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as foto_nombre,
							m.fecha_retiro as fecha_retiro, m.estado, m.fecha_matricula, m.nuevo, IF(m.nuevo, "SI", "NO") as es_nuevo, m.repitente,
							a.has_sisben, a.nro_sisben, a.has_sisben_3, a.nro_sisben_3 
						FROM alumnos a 
						inner join matriculas m on a.id=m.alumno_id and m.grupo_id=:grupo_id and (m.estado="ASIS" or m.estado="MATR" or m.estado="PREM" or m.estado="PREA")
						left join users u on a.user_id=u.id and u.deleted_at is null
						left join images i on i.id=u.imagen_id and i.deleted_at is null
						left join tipos_documentos t1 on t1.id=a.tipo_doc and t1.deleted_at is null
						left join images i2 on i2.id=a.foto_id and i2.deleted_at is null
						left join ciudades c1 on c1.id=a.ciudad_nac and c1.deleted_at is null
						left join ciudades c2 on c2.id=a.ciudad_doc and c2.deleted_at is null
						left join ciudades c3 on c3.id=a.ciudad_resid and c3.deleted_at is null
						where a.deleted_at is null and m.deleted_at is null
						order by a.apellidos, a.nombres';



	public static $consulta_parientes = 'SELECT ac.id, ac.nombres, ac.apellidos, ac.sexo, "Acudiente" as tipo,
							ac.fecha_nac, ac.ciudad_nac, c1.ciudad as ciudad_nac_nombre, ac.ciudad_doc,
							c2.ciudad as ciudad_doc_nombre, c2.departamento as departamento_doc_nombre, ac.telefono,
							pa.parentesco, pa.observaciones, pa.id as parentesco_id, ac.user_id, 
							ac.celular, ac.ocupacion, ac.email, ac.barrio, ac.direccion, ac.tipo_doc, t1.tipo as tipo_doc_nombre,
							ac.documento, ac.created_by, ac.updated_by, ac.created_at, ac.updated_at, 
							ac.foto_id, IFNULL(i.nombre, IF(ac.sexo="F","default_female.png", "default_male.png")) as foto_nombre, 
							u.username, u.is_active, ac.is_acudiente, IF(ac.is_acudiente, "SI", "NO") as es_acudiente
						FROM parentescos pa
						left join acudientes ac on ac.id=pa.acudiente_id and ac.deleted_at is null
						left join users u on ac.user_id=u.id and u.deleted_at is null
						left join images i on i.id=ac.foto_id and i.deleted_at is null
						left join tipos_documentos t1 on t1.id=ac.tipo_doc and t1.deleted_at is null
						left join ciudades c1 on c1.id=ac.ciudad_nac and c1.deleted_at is null
						left join ciudades c2 on c2.id=ac.ciudad_doc and c2.deleted_at is null
						WHERE pa.alumno_id=? and pa.deleted_at is null Order by ac.is_acudiente desc, ac.id ';



	public static function matricularUno($alumno_id, $grupo_id, $year_id=false, $user_id=null, $crear_matri=false)
	{
		if (!$year_id) {
			$year = Year::where('actual', true)->first();

			// Sin año actual no hay folio ni grupo al que matricular. Antes esto era
			// «Attempt to read property on null» y un 500 sin explicación; el caso
			// existe de verdad y lo diagnostica `php artisan anios:actuales`.
			if ($year === null) {
				abort(409, 'El colegio no tiene ningún año marcado como actual.');
			}

			$year_id = $year->id;
		}else{
			// `findOrFail` y no `find`: el folio es `{año}-{alumno_id}` y es lo que el
			// colegio escribe en el libro de matrícula. Con `find`, un `year_id` que no
			// existe devolvía null, `$year->year` daba un aviso y la matrícula se
			// creaba con el folio «-1234», sin que nadie se enterara. `year_id` llega
			// del cuerpo de la petición sin validar. Ver
			// docs/migracion/12-larastan-nivel-7.md §11.
			$year = Year::findOrFail((int) $year_id);
		}

		$matricula = false;

		if (!$crear_matri) {

			// Traigo matriculas del alumno este año aunque estén borradas
			$consulta = 'SELECT m.id, m.alumno_id, m.grupo_id, m.estado, g.year_id 
				FROM matriculas m 
				inner join grupos g 
					on m.alumno_id = :alumno_id and g.year_id = :year_id and m.grupo_id=g.id';

			$matriculas = DB::select($consulta, ['alumno_id'=>$alumno_id, 'year_id'=>$year_id]);
			

			// Busco entre las que están borradas para activar alguna y borrar las demás
			for ($i=0; $i < count($matriculas); $i++) { 

				$matri = Matricula::onlyTrashed()->where('id', $matriculas[$i]->id)->first();

				if ($matri) {
					/*
					 * **Aqui ya NO se fabrica `nro_folio`, y en otros tres sitios de este
					 * fichero tampoco.** Escribia `anio-alumno_id` --`2025-1234`--, y eso
					 * **no es la hoja de ningun libro**: un folio es una posicion en el libro
					 * de matriculas, y lo que se imprime en la constancia sirve para que quien
					 * la lea vaya a comprobarla al archivo. Medido en la copia local: **1.612
					 * folios fabricados asi, y otros 257 con esa forma que nombran a OTRO
					 * alumno** (docs/migracion/21-certificados-y-folios.md §2.2). Un folio en
					 * blanco es honesto; uno inventado no.
					 *
					 * Decision de Joseth del 26 ago 2026. Lo fija `FolioQueNoSeFabricaTest`:
					 * si alguien lo vuelve a poner, cae.
					 */

					// `$matri`, no `$matricula`: era una copia de la línea del bucle de abajo
					// con el nombre sin cambiar, y `$matricula` vale `false` en la primera
					// vuelta. Ver docs/migracion/12-larastan-nivel-7.md §1.
					if ($matricula) { // Si ya he encontrado en un elemento anterior una matrícula identica, es porque ya la he activado, no debo activar más. Por el contrario, debo borrarlas
						$matri->deleted_by		= $user_id;
						$matri->save();
						$matri->delete();
					}else{
						$matri->estado 			= 'MATR'; // Matriculado, Asistente o Retirado , Prem, Form
						$matri->fecha_retiro 	= null;
						$matri->grupo_id 		= $grupo_id;
						$matri->updated_by		= $user_id;
						$matri->save();
						$matri->restore();
						$matricula=$matri;
					}
				}
			}
			
			//Cuando estoy pasando de un grupo a otro, la matricula a modificar no necesariamente está en papelera así que:
			if ( count($matriculas) > 0 && $matricula == false ) {

				for ($i=0; $i < count($matriculas); $i++) { 

					$matri = Matricula::where('id', $matriculas[$i]->id)->first();
					if ($matri) {
						if ($matricula) { // Si ya he encontrado en un elemento anterior una matrícula identica, es porque ya la he activado, no debo activar más. Por el contrario, debo borrarlas
							$matri->deleted_by		= $user_id;
							$matri->save();
							$matri->delete();
						}else{
							$matri->estado 			= 'MATR'; // Matriculado, Asistente o Retirado
							$matri->fecha_retiro 	= null;
							$matri->grupo_id 		= $grupo_id;
							$matri->updated_by		= $user_id;
							$matri->save();
							$matricula=$matri;
						}
					}
				}
			}
		
		} // if !$crear_matri
		
		try {
			if (!$matricula) {
				$matricula = new Matricula;
				$matricula->alumno_id 	= $alumno_id;
				$matricula->grupo_id	= $grupo_id;
				$matricula->estado 		= 'MATR';
				$matricula->created_by	= $user_id;
				
				$now = Carbon::now('America/Bogota');
				//$now = new \DateTime();
				//$now->format('Y-m-d');

				$matricula->fecha_matricula = $now;

				$matricula->save();
			}else{
			}
			
		} catch (\Exception $e) {
			// se supone que esto nunca va a ocurrir, ya que eliminé todas las matrículas 
			// excepto la que concordara con el grupo, poniéndola en estado=MATR
			$matricula 				= Matricula::where('alumno_id', $alumno_id)->where('grupo_id', $grupo_id)->first();
			$matricula->estado 		= 'MATR';
			$matricula->updated_by	= $user_id;
			$matricula->save();
			
		}

		return $matricula;
	}


}



