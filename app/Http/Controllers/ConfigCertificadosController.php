<?php namespace App\Http\Controllers;

use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\DB;

use App\User;
use App\Models\ConfigCertificado;
use App\Models\Year;
use App\Support\AuditarFila;

class ConfigCertificadosController extends Controller {


	public function getIndex()
	{
		$user = User::fromToken();

		$certificados = ConfigCertificado::all();

		return $certificados;
	}

	

	public function postStore()
	{
		$user = User::fromToken();

		$certif = new ConfigCertificado;

		if (Request::input('encabezado_img_id')) {
			$certif->encabezado_img_id = Request::input('encabezado_img_id')['id'];
		}
		if (Request::input('piepagina_img_id')) {
			$certif->piepagina_img_id = Request::input('piepagina_img_id')['id'];
		}

		$certif->nombre 				= Request::input('nombre');
		$certif->encabezado_width 		= Request::input('encabezado_width');
		$certif->encabezado_height 		= Request::input('encabezado_height');
		$certif->encabezado_margin_top 	= Request::input('encabezado_margin_top');
		$certif->encabezado_margin_left = Request::input('encabezado_margin_left');
		$certif->encabezado_solo_primera_pagina = Request::input('encabezado_solo_primera_pagina', 0);
		$certif->piepagina_width 		= Request::input('piepagina_width');
		$certif->piepagina_height 		= Request::input('piepagina_height');
		$certif->piepagina_margin_bottom = Request::input('piepagina_margin_bottom');
		$certif->piepagina_margin_left 	= Request::input('piepagina_margin_left');
		$certif->piepagina_solo_ultima_pagina = Request::input('piepagina_solo_ultima_pagina', 0);
		$certif->created_by = $user->user_id;
		$certif->save();
		// Es del colegio, no de un año: va al año de la sesión.
		AuditarFila::creada('config_certificado', 'config_certificados', (int) $certif->id, (int) $user->year_id, 'Creó una configuración de certificado');


		return $certif;
	}



	public function putUpdate()
	{
		$user = User::fromToken();

		$certif = ConfigCertificado::findOrFail(Request::input('id'));

		if (Request::input('encabezado_img')) {
			$certif->encabezado_img_id = Request::input('encabezado_img')['id'];
		}else{
			$certif->encabezado_img_id = null;
		}
		
		if (Request::input('piepagina_img')) {
			$certif->piepagina_img_id = Request::input('piepagina_img')['id'];
		}else{
			$certif->piepagina_img_id = null;
		}
		
		$certif->nombre 				= Request::input('nombre');
		$certif->encabezado_width 		= Request::input('encabezado_width');
		$certif->encabezado_height 		= Request::input('encabezado_height');
		$certif->encabezado_margin_top 	= Request::input('encabezado_margin_top');
		$certif->encabezado_margin_left = Request::input('encabezado_margin_left');
		$certif->encabezado_solo_primera_pagina = Request::input('encabezado_solo_primera_pagina', 0);
		$certif->piepagina_width 		= Request::input('piepagina_width');
		$certif->piepagina_height 		= Request::input('piepagina_height');
		$certif->piepagina_margin_bottom = Request::input('piepagina_margin_bottom');
		$certif->piepagina_margin_left 	= Request::input('piepagina_margin_left');
		$certif->piepagina_solo_ultima_pagina = Request::input('piepagina_solo_ultima_pagina', 0);
		$certif->created_by = $user->user_id;
		AuditarFila::cambio('config_certificado', 'config_certificados', (int) $certif->id, fn () => $certif->save(), (int) $user->year_id, 'Cambió una configuración de certificado');


		return $certif;
	}

	

	public function putActual()
	{
		$user = User::fromToken();

		$year = Year::findOrFail(Request::input('year_id'));

		$year->config_certificado_estudio_id = Request::input('config_certificado_estudio_id');
		AuditarFila::cambio('year_config', 'years', (int) $year->id, fn () => $year->save(), (int) $year->id, 'Cambió el certificado de estudio del año');

		return 'Cambiado';
	}



	/**
	 * **Los textos del certificado del año.** Hoy son tres: el encabezado de siempre
	 * y los dos títulos que entraron el 15 sep 2026
	 * (`docs/migracion/38-los-titulos-del-certificado.md`).
	 *
	 * ## Los títulos entran AQUÍ y no en una ruta nueva
	 *
	 * Son texto del certificado del año, que es literalmente lo que este método
	 * escribe, y los edita **la misma pantalla** —*Colegio → Configurar
	 * certificados*, donde ya está el cuadro del encabezado—. Una ruta nueva en este
	 * repositorio es una decisión que mueve el contador de `CLAUDE.md` y tres
	 * snapshots; ésta no mueve ninguno. Es lo que se hizo con `reparto_subunidades`
	 * en `putModeloEvaluacion` y por el mismo motivo.
	 *
	 * El nombre del método y el de la ruta se quedan como están —`encabezado`—
	 * porque renombrar es cosmético y va después (`CLAUDE.md`), y porque el legacy
	 * llama a esa URL desde `ConfigCertificados.ts`.
	 *
	 * ## CADA CAMPO ES OPCIONAL, y eso CAMBIA lo que hacía este método
	 *
	 * Hasta hoy escribía `encabezado_certificado` viniera o no en el cuerpo, así que
	 * **un `PUT` que no lo mandara lo borraba**. Eso no era una decisión como la de
	 * `putUpdate()` con la imagen —donde el borrado es la función y hay un test que
	 * lo fija—: era el único campo que había, y el único cliente lo mandaba siempre.
	 *
	 * En cuanto hay tres, conservarlo sería un destructor silencioso: la pantalla que
	 * guarda un título **borraría el encabezado del certificado**, que es papel
	 * firmado. Así que cada campo que viene se escribe y el que no viene no se toca,
	 * que es la forma de `putModeloEvaluacion`.
	 *
	 * ## Sin ningún campo es 422, no un 200 que no escribió nada
	 *
	 * La familia de `tools/respuestas-que-mienten.py`: quien recibiera «Cambiado»
	 * creería que guardó algo. El **404 va antes** —`findOrFail` sigue siendo la
	 * primera línea— porque un año que no existe no es un cuerpo mal formado, y
	 * porque es lo que fija `ConfigCertificadosTest::test_un_id_que_no_existe_es_404`.
	 *
	 * ## Los títulos se validan aquí, y el tope también
	 *
	 * **Cadena vacía, no.** Un certificado sin título no es un estado que exista: el
	 * `@if` que lo leyera tendría que inventarse un texto de respaldo, y ese texto
	 * volvería a ser una cadena escrita dentro de la plantilla — justo lo que esto
	 * viene a quitar. El encabezado sí puede vaciarse: es un párrafo opcional y
	 * `NULL` en la base.
	 *
	 * **Y los 255 se comprueban aquí y no se le dejan a la columna**, porque el
	 * docker trunca en silencio y MariaDB aborta: el mismo tope da dos resultados
	 * distintos según el servidor, y ninguno de los dos es un mensaje para quien
	 * escribe (`docs/migracion/33-la-tilde-que-sql-no-ve.md`). Se cuenta con
	 * `mb_strlen` y no con `strlen` porque el defecto mismo —«CONSTANCIA DE
	 * DESEMPEÑO ACADÉMICO»— son 33 caracteres y 35 bytes.
	 *
	 * ## Lo que de verdad caza cada rama, dicho sin adornos
	 *
	 * Por HTTP, **el vacío no llega nunca a este `if`**: `TrimStrings` recorta todo lo
	 * que entra en las rutas de esta API y `ConvertEmptyStringsToNull` convierte el
	 * resultado en `null`, así que una cadena de espacios sale por el `is_string()` y
	 * no por el `trim() === ''`. El `trim` de aquí tampoco es el que recorta: cuando
	 * llega, el valor ya viene recortado.
	 *
	 * Se dejan los dos igualmente, y no es por costumbre: **son el respaldo del día que
	 * alguien excluya esta ruta de esos middlewares** —`TrimStrings` ya excluye las
	 * contraseñas, así que la lista no es teórica— y el único que vale para una llamada
	 * que no venga del `Kernel` HTTP. Lo que **no** se puede hacer es apoyarse en ellos
	 * para afirmar que el invariante lo sostiene este método: lo sostiene la suma de
	 * los tres, y por eso se escribe aquí cuál pone cada uno.
	 */
	public function putEncabezado()
	{
		$user = User::fromToken();

		$year = Year::findOrFail(Request::input('year_id'));

		$escritos = 0;

		if (Request::has('encabezado_certificado')) {
			$year->encabezado_certificado = Request::input('encabezado_certificado');
			$escritos++;
		}

		// La lista sale de `Year::TITULOS_POR_DEFECTO` y no se reescribe aquí: es la
		// misma que el test cruza con `SHOW COLUMNS`, así que un título nuevo entra por
		// un sitio y queda validado en éste sin que nadie se acuerde de venir.
		foreach (array_keys(Year::TITULOS_POR_DEFECTO) as $campo) {
			if (! Request::has($campo)) {
				continue;
			}

			$valor = Request::input($campo);

			if (! is_string($valor) || trim($valor) === '') {
				abort(422, "`{$campo}` no puede ir vacío: es el título que va impreso en el certificado.");
			}

			$valor = trim($valor);

			if (mb_strlen($valor) > Year::LARGO_DEL_TITULO) {
				abort(422, "`{$campo}` no puede pasar de ".Year::LARGO_DEL_TITULO.' caracteres.');
			}

			$year->{$campo} = $valor;
			$escritos++;
		}

		if ($escritos === 0) {
			abort(422, 'No se mandó ningún texto que cambiar.');
		}

		AuditarFila::cambio('year_config', 'years', (int) $year->id, fn () => $year->save(), (int) $year->id, 'Cambió los textos del certificado del año');

		return 'Cambiado';
	}


	public function deleteDestroy($id)
	{
		$user = User::fromToken();

		$certif = ConfigCertificado::findOrFail($id);
		AuditarFila::borrada('config_certificado', 'config_certificados', (int) $certif->id, (int) $user->year_id, 'Borró una configuración de certificado');
		$certif->delete();
		return $certif;
	}



}


