<?php namespace App\Http\Controllers;



use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\DB;

use App\User;
use App\Models\Contrato;
use App\Models\Profesor;



class ContratosController extends Controller {

	public function getIndex()
	{
		$user = User::fromToken();
		$profesores = Profesor::contratos($user->year_id);
		return $profesores;
	}

	public function postIndex()
	{

		$user = User::fromToken();

		$consulta = 'SELECT p.id as profesor_id, p.nombres
				from profesores p
				inner join contratos c on c.profesor_id=p.id and c.year_id=:year_id and c.profesor_id=:profesor_id and c.deleted_at is null 
				left join users u on p.user_id=u.id and u.is_Active=false
				where p.deleted_at is null';

		$contratado = DB::select($consulta, array(':year_id'=>$user->year_id, ':profesor_id' => Request::input('profesor_id')));

		if (count($contratado) > 0) {
			return response()->json([ 'contratado'=> true, 'msg'=> 'Profesor ya contratado' ], 400);
		}

		/*
		 * Sin este bloque, contratar a un profesor que no existe **escribía la fila
		 * igual** y contestaba 200 con un array vacío.
		 *
		 * De los nueve catálogos del colegio, éste es el único que llega a escribir
		 * con el cuerpo vacío, y lo que separa a los otros ocho no es su código —es
		 * el esquema—: todos tienen una columna `NOT NULL` que rechaza el `INSERT`,
		 * y `contratos` no tiene ninguna (`profesor_id` y `year_id` son nulables).
		 * Ver 05 §78.
		 *
		 * El `SELECT` de después une por `profesores`, así que con un contrato
		 * huérfano no devuelve nada: 200 con `[]`. Y `ProfesoresCtrl` ya se defendía
		 * de ese caso —«sería un backend distinto del documentado»— enseñando
		 * «contratado para este año» y no tocando las rejillas. O sea que la
		 * pantalla decía que sí mientras aquí quedaba una fila sin profesor.
		 *
		 * En la copia de producción hay **cero contratos huérfanos** de 164, así que
		 * esto era una mina y no un fallo vivo: el front siempre manda un id bueno.
		 * Se cierra porque el día que un cliente mande uno malo, lo que queda es una
		 * fila que no se puede ni ver ni quitar desde ninguna pantalla.
		 */
		$existe = DB::selectOne('SELECT id FROM profesores WHERE id = ? AND deleted_at IS NULL',
			[Request::input('profesor_id')]);

		if (! $existe) {
			return abort(422, 'Ese profesor no existe.');
		}

		$contrato = new Contrato;
		$contrato->profesor_id	=	Request::input('profesor_id');
		$contrato->year_id		=	$user->year_id;
		$contrato->save();


		$consulta = 'SELECT p.id as profesor_id, p.nombres, p.apellidos, p.sexo, p.foto_id, p.tipo_doc,
					p.num_doc, p.ciudad_doc, p.fecha_nac, p.ciudad_nac, p.titulo,
					p.estado_civil, p.barrio, p.direccion, p.telefono, p.celular,
					p.facebook, p.email, p.tipo_profesor, p.user_id, u.username,
					u.email as email_usu, u.imagen_id, u.is_superuser,
					c.id as contrato_id, c.year_id
				from profesores p
				inner join contratos c on c.profesor_id=p.id and c.id=:contrato_id
				left join users u on p.user_id=u.id and u.is_Active=false
				where p.deleted_at is null';

		$profesor = DB::select($consulta, array(':contrato_id' => $contrato->id));

		return $profesor;
	}



	/**
	 * §82. La otra mitad de `postIndex`, y la que se quedó abierta cuando se
	 * cerró aquélla.
	 *
	 * `Contrato::destroy($id)` devuelve **cuántas filas borró**, así que un id
	 * que no existe contestaba **200 con el cuerpo `0`**. Un cuerpo que es el
	 * número cero: el front lo lee como respuesta buena —es un 200— y quita al
	 * profesor de la rejilla de contratados sin que se haya borrado nada.
	 *
	 * Es exactamente la asimetría que dejó la §78: arriba se cerró que contratar
	 * a un profesor inexistente escribiera una fila huérfana, y aquí abajo
	 * descontratar un contrato inexistente seguía diciendo que sí. **Cerrar una
	 * serie sobre una población no la cierra sobre la de al lado** — la misma
	 * lección que la papelera de la §76.
	 *
	 * Se mira `deleted_at` a propósito: un contrato ya en la papelera no está, y
	 * volver a borrarlo tampoco es «hecho». Es el criterio que ya usa
	 * `EscalasDeValoracionController::exigirQueLaEscalaExista`.
	 *
	 * §84 — **lo que sigue sin comprobar, y no se cierra aquí**: `getIndex`
	 * lista los contratos de `$user->year_id` y esto borra el de cualquier año.
	 * Medido: un usuario del año 8 borra el contrato 124, que es del año 7, y
	 * recibe 200. Escribir en años pasados **está decidido a propósito** en esta
	 * API (05 §27.4), así que queda fijado por un test y anotado, no cerrado.
	 */
	public function deleteDestroy($id)
	{
		$user = User::fromToken();

		$existe = DB::selectOne('SELECT id FROM contratos WHERE id = ? AND deleted_at IS NULL', [$id]);

		if (! $existe) {
			abort(404, 'Ese contrato no existe.');
		}

		$contr = Contrato::destroy($id);
		return $contr;
	}

}