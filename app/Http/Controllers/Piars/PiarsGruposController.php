<?php namespace App\Http\Controllers\Piars;

use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;

use App\User;
use App\Support\HtmlDelEditor;
use App\Http\Controllers\Piars\Utils\PiarsGrupoUtils;
use App\Http\Controllers\Piars\Utils\PiarsAlumnoUtils;
use Carbon\Carbon;
use App\Models\Profesor;
use App\Models\Grupo;
use App\Http\Controllers\Concerns\ResuelveElUsuario;

class PiarsGruposController extends Controller {
	use ResuelveElUsuario;

	public function getGrupos()
	{
		$user = User::fromToken();

		$consulta = 'SELECT g.id, g.nombre, g.abrev, g.orden, gra.orden as orden_grado, g.grado_id, g.year_id, g.titular_id, g.cupo, 
						p.nombres as nombres_titular, p.apellidos as apellidos_titular, 
						g.created_at, g.updated_at, gra.nombre as nombre_grado,
						p.foto_id, IFNULL(i.nombre, IF(p.sexo="F","default_female.png", "default_male.png")) as foto_nombre,
						p.firma_id, i2.nombre as firma_titular_nombre
					FROM grupos g
					INNER JOIN grados gra on gra.id=g.grado_id and g.year_id=:year_id
					LEFT JOIN profesores p on p.id=g.titular_id and p.deleted_at is null
					left join images i on p.foto_id=i.id and i.deleted_at is null
					left join images i2 on p.firma_id=i2.id and i.deleted_at is null
					WHERE g.deleted_at is null
					ORDER BY g.orden';

		$grados = DB::select($consulta, [':year_id'=>$user->year_id] );

		return $grados;
	}

	public function getContextoDeGrupo($grupo_id)
	{
		$consulta = 'SELECT pg.id, pg.grupo_id, pg.titular_id, pg.year_id, pg.caracterizacion_grupo, pg.updated_at, pg.updated_by  
			FROM piars_grupos pg
			INNER JOIN grupos gr on gr.id=pg.grupo_id and gr.deleted_at is null
			WHERE pg.grupo_id=?';

		$piars = DB::select($consulta, [$grupo_id]);

		$grupo = Grupo::datos($grupo_id);

		$piarsGrupoUtils = new PiarsGrupoUtils();
		$piarsAlumnosUtils = new PiarsAlumnoUtils();
		$alumnos = $piarsAlumnosUtils->getAlumnosDeGrupo($grupo_id);

		if (count($piars) == 0) {
			$piarsGrupoUtils->createContextoGrupo($grupo_id);
			$piars = DB::select($consulta, [$grupo_id]);
		}

		$alumnos_piar = $piarsAlumnosUtils->getAlumnosPiar($grupo_id, $this->user->user_id, $alumnos);
		
		if ($this->user->is_superuser) {
			$piarsAlumnosUtils->getAcudientes($alumnos_piar);
		}
		// El `else` escribía `$piarsAlumnosUtils->acudientes = []`, y eso no era
		// lo que parecía: `getAcudientes()` cuelga `acudientes` de CADA ALUMNO,
		// no del objeto de utilidades. Nadie leía esa propiedad, así que la rama
		// no hacía nada — salvo crear una propiedad dinámica sobre una clase
		// normal, que en PHP 8.2 es una deprecación y en PHP 9 será un error.
		//
		// Se borra sin cambiar comportamiento. Lo que queda por decidir es otra
		// cosa: si un profesor que no es superusuario debería ver `acudientes: []`
		// en cada alumno en vez de que la clave no aparezca.

		$piarsAlumnosUtils->getMatriculas($alumnos_piar);

		return [
			'data' => [
				'familiarContext' => $piars,
				'alumnos' => $alumnos,
				'alumnos_piar' => $alumnos_piar,
				'grupo' => $grupo,
			]
		];
	}

	public function putContextoDeGrupo()
	{
		$now = Carbon::now('America/Bogota');

		$id = Request::input('id');
		$caracterizacion_grupo = Request::input('caracterizacion_grupo');
		$updated_at = $now;
		$updated_by = $this->user->user_id;

		// El texto es HTML del editor y el cliente lo pinta como HTML: lo que no
		// pase por aquí se ejecuta en la sesión de quien abra el PIAR.
		$caracterizacion_grupo = HtmlDelEditor::limpiar($caracterizacion_grupo);

		$consulta = 'UPDATE piars_grupos
			SET caracterizacion_grupo=?, updated_at=?, updated_by=?
			WHERE id=?';
		$piars = DB::update($consulta, [
			$caracterizacion_grupo, $updated_at, $updated_by, $id,  
		]);

    return ['piars' => $piars];
	}
}
