<?php namespace App\Http\Controllers\CambiarUsuarios;


use App\Services\Auditoria;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use \Log;

use Carbon\Carbon;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\ResuelveElUsuario;
use App\Support\Autoriza;
use App\Support\ClaveNueva;
use App\Support\DocumentoComoUsuario;


class CambiarUsuariosController extends Controller {
	use ResuelveElUsuario;

	public function putPonerDocumentoComoUsernameAlumnos()
	{
		// Las cuatro rutas reescriben la cuenta de TODOS los alumnos o de todos
		// los acudientes del colegio. Con `auth.personal` a secas las disparaba
		// cualquiera de los 51 profesores.
		//
		// **`esAdministrativo` es una decisión de Joseth, no una herencia**, y hay
		// que decirlo aquí porque el que lea esto va a querer bajarlo: «puede
		// cambiarle la contraseña/username a los alumnos y acudientes solamente»
		// —21 ago 2026—, que es literalmente lo que hacen estas cuatro. La frase
		// está citada en SecretarioTest::test_las_masivas_de_alumnos_y_acudientes_si_son_suyas,
		// que es el test que la fija.
		//
		// El comentario que había aquí decía «mismo criterio que la papelera de
		// grupos y profesores», y **eso era falso**: esa papelera está en
		// `esSuperusuario` (GruposController:719,749,783). El guard era el
		// correcto y el precedente citado el equivocado, que es peor que no citar
		// ninguno — invita a «corregir» el guard hacia el precedente falso.
		// Corregido el 24 ago 2026 (09 §12). Ver app/Support/Autoriza.php.
		Autoriza::exigir(Autoriza::esAdministrativo($this->user),
			'Solo un administrativo puede cambiar las cuentas de todo el colegio.');

		$consulta = 'UPDATE IGNORE users u 
			INNER JOIN alumnos a ON a.user_id=u.id and a.deleted_at is null and u.tipo="Alumno"
			SET u.username=a.documento
			WHERE a.documento>0 and a.documento is not null and a.documento!="" and u.deleted_at is null';
		
		$res = DB::select($consulta);
		
		return [ 'resultado' => 'Usernames cambiados.' ];
	}



	public function putPonerDocumentoComoUsernameAcudientes()
	{
		// Las cuatro rutas reescriben la cuenta de TODOS los alumnos o de todos
		// los acudientes del colegio. Con `auth.personal` a secas las disparaba
		// cualquiera de los 51 profesores.
		//
		// **`esAdministrativo` es una decisión de Joseth, no una herencia**, y hay
		// que decirlo aquí porque el que lea esto va a querer bajarlo: «puede
		// cambiarle la contraseña/username a los alumnos y acudientes solamente»
		// —21 ago 2026—, que es literalmente lo que hacen estas cuatro. La frase
		// está citada en SecretarioTest::test_las_masivas_de_alumnos_y_acudientes_si_son_suyas,
		// que es el test que la fija.
		//
		// El comentario que había aquí decía «mismo criterio que la papelera de
		// grupos y profesores», y **eso era falso**: esa papelera está en
		// `esSuperusuario` (GruposController:719,749,783). El guard era el
		// correcto y el precedente citado el equivocado, que es peor que no citar
		// ninguno — invita a «corregir» el guard hacia el precedente falso.
		// Corregido el 24 ago 2026 (09 §12). Ver app/Support/Autoriza.php.
		Autoriza::exigir(Autoriza::esAdministrativo($this->user),
			'Solo un administrativo puede cambiar las cuentas de todo el colegio.');

		$consulta = 'UPDATE IGNORE users u 
			INNER JOIN acudientes a ON a.user_id=u.id and a.deleted_at is null and u.tipo="Acudiente"
			SET u.username=a.documento
			WHERE a.documento>0 and a.documento is not null and a.documento!="" and u.deleted_at is null';
		
		$res = DB::select($consulta);
		
		return [ 'resultado' => 'Usernames cambiados.' ];
	}



	public function putPonerPasswordTodosAlumnos()
	{
		// Las cuatro rutas reescriben la cuenta de TODOS los alumnos o de todos
		// los acudientes del colegio. Con `auth.personal` a secas las disparaba
		// cualquiera de los 51 profesores.
		//
		// **`esAdministrativo` es una decisión de Joseth, no una herencia**, y hay
		// que decirlo aquí porque el que lea esto va a querer bajarlo: «puede
		// cambiarle la contraseña/username a los alumnos y acudientes solamente»
		// —21 ago 2026—, que es literalmente lo que hacen estas cuatro. La frase
		// está citada en SecretarioTest::test_las_masivas_de_alumnos_y_acudientes_si_son_suyas,
		// que es el test que la fija.
		//
		// El comentario que había aquí decía «mismo criterio que la papelera de
		// grupos y profesores», y **eso era falso**: esa papelera está en
		// `esSuperusuario` (GruposController:719,749,783). El guard era el
		// correcto y el precedente citado el equivocado, que es peor que no citar
		// ninguno — invita a «corregir» el guard hacia el precedente falso.
		// Corregido el 24 ago 2026 (09 §12). Ver app/Support/Autoriza.php.
		Autoriza::exigir(Autoriza::esAdministrativo($this->user),
			'Solo un administrativo puede cambiar las cuentas de todo el colegio.');

		$password   = Hash::make(ClaveNueva::exigir('clave'));
		$consulta   = 'UPDATE users SET password=:texto WHERE tipo="Alumno";';
		
		$cambiadas = DB::update($consulta, [
			':texto'		=> $password
		]);

		/*
		 * **Esto le cambia la contraseña a TODO el colegio de una vez, y hasta hoy no
		 * dejaba rastro en ninguna de las dos tablas.** Es la escritura de mayor
		 * alcance de toda la API: un `UPDATE users` sin `WHERE id`, que en la base de
		 * desarrollo toca 2.358 cuentas. Que algo así no se pueda reconstruir después
		 * —quién lo hizo, cuándo y a cuántos alcanzó— es lo que este rastro existe para
		 * impedir.
		 *
		 * **Sin `de()` ni `a()`**: lo que se escribe es una contraseña. Se guarda el
		 * acto y su alcance; la clave no se reconstruye, se vuelve a cambiar.
		 *
		 * Una línea por el acto, no 2.358 por cuenta: es la regla del punto 3 del 18, y
		 * aquí además 2.358 líneas iguales harían ilegible la pantalla para siempre.
		 */
		Auditoria::registrar()
			->editar('usuario')
			->resumen('Cambió la contraseña de los '.$cambiadas.' alumnos del colegio')
			->guardar();

		return 'Contraseñas alumnos cambiadas';
	}


	public function putPonerPasswordTodosAcudientes()
	{
		// Las cuatro rutas reescriben la cuenta de TODOS los alumnos o de todos
		// los acudientes del colegio. Con `auth.personal` a secas las disparaba
		// cualquiera de los 51 profesores.
		//
		// **`esAdministrativo` es una decisión de Joseth, no una herencia**, y hay
		// que decirlo aquí porque el que lea esto va a querer bajarlo: «puede
		// cambiarle la contraseña/username a los alumnos y acudientes solamente»
		// —21 ago 2026—, que es literalmente lo que hacen estas cuatro. La frase
		// está citada en SecretarioTest::test_las_masivas_de_alumnos_y_acudientes_si_son_suyas,
		// que es el test que la fija.
		//
		// El comentario que había aquí decía «mismo criterio que la papelera de
		// grupos y profesores», y **eso era falso**: esa papelera está en
		// `esSuperusuario` (GruposController:719,749,783). El guard era el
		// correcto y el precedente citado el equivocado, que es peor que no citar
		// ninguno — invita a «corregir» el guard hacia el precedente falso.
		// Corregido el 24 ago 2026 (09 §12). Ver app/Support/Autoriza.php.
		Autoriza::exigir(Autoriza::esAdministrativo($this->user),
			'Solo un administrativo puede cambiar las cuentas de todo el colegio.');

		$password   = Hash::make(ClaveNueva::exigir('clave'));
		$consulta   = 'UPDATE users SET password=:texto WHERE tipo="Acudiente";';
		
		$cambiadas = DB::update($consulta, [
			':texto'		=> $password
		]);

		// Mismo caso que en los alumnos, y el porqué está escrito allí.
		Auditoria::registrar()
			->editar('usuario')
			->resumen('Cambió la contraseña de los '.$cambiadas.' acudientes del colegio')
			->guardar();

		return 'Contraseñas acudientes cambiadas';
	}


	/* ── El documento como nombre de usuario, sabiendo a quién le toca ──────────────────── */

	/*
	 * QUÉ PASARÍA. No escribe nada.
	 *
	 * Existe porque la pantalla tenía que enseñar el desglose ANTES de que nadie
	 * pulse: en el grupo 113 del docker son 7 alumnos, pero 1 no tiene documento y
	 * 3 ya lo tienen puesto, así que el trabajo real son 3 cuentas. Con las dos
	 * rutas viejas eso no se podía saber ni antes ni después —responden siempre
	 * `{resultado: 'Usernames cambiados.'}`—, y el secretario disparaba a ciegas
	 * sobre 1.280 cuentas. Ver App\Support\DocumentoComoUsuario.
	 */
	public function putRevisarDocumentoComoUsername()
	{
		[$destino, $grupoId] = $this->destinoYGrupo();

		return DocumentoComoUsuario::revisar($destino, $grupoId);
	}


	/*
	 * Y LO HACE.
	 *
	 * Las dos rutas viejas se quedan donde están: las llama el front que todavía no
	 * ha migrado, y su respuesta está fijada en `OperacionesMasivasTest` con un
	 * `assertExactJson`. Ésta es otra ruta, con otro contrato, y por eso puede
	 * devolver el recuento que aquéllas no pueden.
	 */
	public function putDocumentoComoUsername()
	{
		[$destino, $grupoId] = $this->destinoYGrupo();

		return DocumentoComoUsuario::aplicar($destino, $grupoId, $this->user->user_id ?? null);
	}


	/*
	 * El cuerpo de las dos, con su guard. Una sola copia porque un permiso que se
	 * escribe dos veces se corrige una.
	 *
	 * **LOS PROFESORES NO SON DE `esAdministrativo`, y es deliberado.** El criterio
	 * de las cuatro rutas viejas viene de una frase de Joseth del 21 ago 2026 citada
	 * arriba: «puede cambiarle la contraseña/username a los ALUMNOS Y ACUDIENTES
	 * solamente». Los profesores no estaban en esa lista, y la regla de la casa —la
	 * que dejó escrita `Autoriza`— es que **crear un permiso no puede regalar lo que
	 * nadie pidió**. Así que el destino nuevo se ancla a superusuario, que es donde
	 * ya está todo lo demás que toca cuentas de profesor.
	 *
	 * @return array{0: string, 1: int|null}
	 */
	private function destinoYGrupo(): array
	{
		$destino = Request::input('destino');

		if (! is_string($destino) || ! in_array($destino, DocumentoComoUsuario::DESTINOS, true)) {
			abort(422, 'El destino tiene que ser alumnos, acudientes o profesores.');
		}

		if ($destino === 'profesores') {
			Autoriza::exigir(Autoriza::esSuperusuario($this->user),
				'Solo un superusuario puede cambiar las cuentas de los profesores.');
		} else {
			Autoriza::exigir(Autoriza::esAdministrativo($this->user),
				'Solo un administrativo puede cambiar las cuentas de todo el colegio.');
		}

		$grupoId = Request::input('grupo_id');

		if ($grupoId === null || $grupoId === '') {
			return [$destino, null];
		}

		// El ámbito de grupo es de alumnos y acudientes. Un `grupo_id` con destino
		// `profesores` no se ignora en silencio: quien lo manda cree estar acotando
		// la operación a un grupo, y la operación sería del colegio entero.
		if ($destino === 'profesores') {
			abort(422, 'Los profesores se cambian en todo el colegio; no admiten grupo.');
		}

		if (! is_numeric($grupoId) || (int) $grupoId <= 0) {
			abort(422, 'El grupo no es válido.');
		}

		$existe = DB::selectOne('SELECT id FROM grupos WHERE id = ? AND deleted_at IS NULL', [(int) $grupoId]);

		if ($existe === null) {
			abort(404, 'Ese grupo no existe.');
		}

		return [$destino, (int) $grupoId];
	}


}
