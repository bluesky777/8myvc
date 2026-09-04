<?php namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;


use Illuminate\Support\Facades\DB;
/**
 * Las columnas de `profesores`, tal como están en el esquema congelado.
 *
 * Generado desde database/schema/mysql-schema.sql — no se edita a mano.
 * Ver tools/columnas-en-los-modelos.php.
 *
 * --- columnas de la tabla, generadas por tools/columnas-en-los-modelos.php ---
 *
 * @property int $id
 * @property string $nombres
 * @property ?string $apellidos
 * @property string $sexo
 * @property ?int $foto_id
 * @property ?int $firma_id
 * @property ?string $permiso_hasta
 * @property ?int $tipo_doc
 * @property ?string $num_doc
 * @property ?int $ciudad_doc
 * @property ?string $fecha_nac
 * @property ?int $ciudad_nac
 * @property ?string $titulo
 * @property ?string $estado_civil
 * @property ?string $barrio
 * @property ?string $direccion
 * @property ?string $telefono
 * @property ?string $celular
 * @property ?string $facebook
 * @property ?string $email
 * @property ?string $tipo_profesor
 * @property ?string $tono
 * @property ?int $user_id
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
 * @property \App\User $user  el usuario recién creado, para devolverlo junto al profesor
 */


class Profesor extends Model {
	use SoftDeletes;
	
	protected $fillable = [];
	protected $table = "profesores";

	protected $dates = ['deleted_at', 'created_at'];
	protected $softDelete = true;



	public static function detallado($profesor_id)
	{
		$consulta = 'SELECT p.id as profesor_id, p.nombres as nombres_profesor, p.apellidos as apellidos_profesor,
						p.user_id, u.username, p.sexo, u.email, p.fecha_nac, p.tipo_doc, p.num_doc,
						p.ciudad_doc, p.ciudad_nac, p.titulo, p.estado_civil, p.barrio, p.direccion,
						p.telefono, p.celular, p.facebook, p.email, p.tipo_profesor,
						u.imagen_id, IFNULL(i.nombre, IF(p.sexo="F","default_female.png", "default_male.png")) as imagen_nombre, 
						p.foto_id, IFNULL(i2.nombre, IF(p.sexo="F","default_female.png", "default_male.png")) as foto_nombre
					from profesores p
					left join users u on p.user_id=u.id and u.deleted_at is null
					left join images i on i.id=u.imagen_id and i.deleted_at is null
					left join images i2 on i2.id=p.foto_id and i2.deleted_at is null
					where p.id=? and p.deleted_at is null';

		$profesor = DB::select($consulta, array($profesor_id));
		return $profesor[0];
	}

	

	public static function asignaturas($year_id, $profesor_id)
	{
		$consulta = 'SELECT a.id as asignatura_id, a.grupo_id, a.profesor_id, a.creditos, a.orden,
							m.materia, m.alias as alias_materia, g.nombre as nombre_grupo, g.abrev as abrev_grupo, g.titular_id, g.caritas,
							gr.nivel_educativo_id
						FROM asignaturas a
						inner join materias m on m.id=a.materia_id and m.deleted_at is null
						inner join grupos g on g.id=a.grupo_id and g.year_id=:year_id and g.deleted_at is null
						inner join grados gr on gr.id=g.grado_id and gr.deleted_at is null 
						where a.profesor_id=:profesor_id and a.deleted_at is null
						order by g.orden, a.orden, m.materia, m.alias, a.id';

		$asignaturas = DB::select($consulta, array(':year_id' => $year_id, ':profesor_id' => $profesor_id));

		return $asignaturas;
	}




	public static function fromyear($year_id)
	{
		$consulta = 'SELECT p.id, p.nombres, p.apellidos, p.sexo,
						p.foto_id, p.titulo, p.facebook, p.email, p.tipo_profesor, p.user_id
					from profesores p
					inner join contratos c on c.profesor_id=p.id and p.deleted_at is null   
					where c.year_id=:year_id and c.deleted_at is null';

		$profesores = DB::select($consulta, [':year_id' => $year_id]);

		return $profesores;
	}

	/**
	 * Los docentes con contrato del año, **solo con lo que se pinta en un select**.
	 *
	 * `contratos()`, que es de donde salía esto, devuelve el expediente entero de
	 * cada docente: `num_doc`, `fecha_nac`, `estado_civil`, `barrio`, `direccion`,
	 * `telefono`, `celular`, `facebook`, `email` y su `is_superuser`. La pantalla
	 * que lo pedía es la de asignar materias a grupos, y usa **cuatro campos** —lo
	 * dice su propia interfaz en `AsignaturasCtrl.ts` (`profesor_id`, `nombres`,
	 * `apellidos`) y su plantilla, que además pinta `foto_nombre`—.
	 *
	 * Se hace aquí y **no** recortando `contratos()`, que se queda como está: su
	 * otro llamante es `GET api/contratos`, que lo lee la app de Flutter desde
	 * pantallas de familia y cuyo recorte es una decisión del colegio pendiente
	 * (09 §5, 05 §14.4). Tocar el método compartido metería esa decisión por la
	 * puerta de atrás y en los dieciséis colegios a la vez.
	 *
	 * Ver 05 §51.
	 */
	public static function paraElegirEnAsignaturas($year_id)
	{
		return DB::select(
			'SELECT p.id as profesor_id, p.nombres, p.apellidos, p.foto_id,
					IFNULL(i.nombre, IF(p.sexo="F","default_female.png", "default_male.png")) as foto_nombre
			 FROM profesores p
			 INNER JOIN contratos c ON c.profesor_id=p.id AND c.year_id=:year_id AND c.deleted_at IS NULL
			 LEFT JOIN images i ON i.id=p.foto_id AND i.deleted_at IS NULL
			 WHERE p.deleted_at IS NULL
			 ORDER BY p.nombres, p.apellidos',
			[':year_id' => $year_id]
		);
	}

	public static function contratos($year_id)
	{
		
		// **Esta consulta llevaba la ficha personal completa del docente, y la ruta
		// que la sirve no pide más que un token.** O sea que `GET api/contratos`
		// entregaba a cualquier alumno el documento de identidad, la fecha de
		// nacimiento, el estado civil, el barrio, **el domicilio, el teléfono fijo
		// y el móvil** de los dieciséis docentes del colegio — y el `is_superuser`
		// de cada uno, que además dice a quién apuntar. En una sola llamada y sin
		// tener que nombrar a nadie: la lista viene entera.
		//
		// Lo midió `myvc-front-ce` el 24 ago 2026 **entrando con un token de
		// alumno de verdad**, que es la primera vez que esta ruta se probaba así.
		// Estaba descrito en la 05 §14.4 desde el 21 ago con el diagnóstico
		// correcto —*«sólo la usa para pasar de un id a un nombre; lo que hay que
		// recortar es la respuesta, no la puerta»*— y sin tocar, porque recortar
		// parecía cambiar el contrato de los dieciséis colegios a la vez.
		//
		// **No lo cambia, y eso está medido en los dos clientes y en los dos
		// llamantes de aquí:**
		//
		//   · `myvc_front` — los seis consumidores de `contratos()` leen id,
		//     nombre y foto. Las pantallas que sí pintan dirección y teléfono
		//     —`profesoresEdit`, `profesoresNew`, `listadoProfesores`— **no se
		//     alimentan de aquí**: usan `ProfesoresApi.listado()` y `Api.crear()`;
		//   · `myvc_flutter` — `DocenteModel` tiene **cuatro campos y ningún
		//     otro**: `profesor_id`, `nombre_completo`, `foto_nombre`, `user_id`;
		//   · y `NotasPerdidasController`, el otro llamante de este método, lee
		//     **sólo `profesor_id`**.
		//
		// Once consumidores, y ninguno toca lo que se quita. Por eso esto es
		// quitar campos que no lee nadie y no un cambio de contrato.
		//
		// **Lo personal sigue estando donde ya se pide `auth.personal`**: quien
		// administra profesores lo ve por su ruta, que es la que lo comprueba.
		$consulta = 'SELECT p.id as profesor_id, p.nombres, p.apellidos, concat(p.nombres, " ", p.apellidos) as nombre_completo, p.sexo, p.foto_id,
					p.tipo_profesor, p.user_id,
					c.id as contrato_id, c.year_id,
					IFNULL(i.nombre, IF(p.sexo="F","default_female.png", "default_male.png")) as foto_nombre
				from profesores p
				inner join contratos c on c.profesor_id=p.id and c.year_id=:year_id and c.deleted_at is null
				left join users u on p.user_id=u.id and u.deleted_at is null
				LEFT JOIN images i on i.id=p.foto_id and i.deleted_at is null
				where p.deleted_at is null
				order by p.nombres, p.apellidos';

		$profesores = DB::select($consulta, array(':year_id'=>$year_id));

		return $profesores;
	}

}