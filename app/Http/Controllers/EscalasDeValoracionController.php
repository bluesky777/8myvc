<?php namespace App\Http\Controllers;


//use Request;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

use App\User;
use App\Models\EscalaDeValoracion;
use App\Support\Autoriza;


class EscalasDeValoracionController extends Controller {

	public function getIndex()
	{
		$user 	= User::fromToken();

		$consulta 	= 'SELECT * FROM escalas_de_valoracion WHERE year_id=? and deleted_at is null order by orden asc';
		$year_id 	= $user->year_id ? $user->year_id : 1;
		$escalas 	= DB::select($consulta, [$year_id]);

		return $escalas;
	}


	/**
	 * **Éste SÍ lleva el candado desde el 15 sep 2026**, y hasta entonces decía aquí
	 * que no lo llevaba a propósito. Merece la pena por qué cambió, porque **de los
	 * dos motivos que tenía, uno era falso y el otro sigue siendo cierto**.
	 *
	 * **El que era falso**: *«crear estampa el año del usuario —no uno del cuerpo—,
	 * así que no puede sembrar fuera de su año»*. Lo segundo no se sigue de lo
	 * primero: **el año de la sesión lo elige la barra de año**. `PUT
	 * years/useractive/{year_id}` es `auth.personal`, no mira si el año está cerrado
	 * y escribe `users.periodo_id` (`YearsController:758-778`), que es justo la
	 * columna de la que `ContextoDeUsuario:169` deriva el `year_id`. Lo levantó
	 * `myvc_front` implementando su lado; medido en la copia de desarrollo, `years`
	 * id 6 = 2023 con `actual = 0` y los `periodos` 22–25 colgando de él.
	 *
	 * **El que sigue siendo cierto**: la fila nace en **91–100**, por encima del
	 * techo de las escalas reales (la de `simonbolivar` acaba en 50), así que **una
	 * banda recién creada no recoge ninguna nota y no cambia ni un boletín**. Eso no
	 * ha dejado de ser verdad y por sí solo no justificaría el candado.
	 *
	 * **Lo que inclina la decisión es la asimetría con `deleteDestroy`**, que sí lo
	 * llevaba: cualquiera de los 74 podía crear una banda en 2023 y **no podía luego
	 * borrarla ni moverla**. La única puerta abierta era la que fabrica filas que
	 * nadie puede quitar — inofensivas de una en una y permanentes. Decisión de
	 * Joseth del 15 sep, que sube la lista de **diez a trece**;
	 * `docs/migracion/16-escribir-en-un-anio-pasado.md` lleva el antes y el después.
	 */
	public function postStore()
	{
		$user 	= User::fromToken();
		$now 	= Carbon::now('America/Bogota');

		Autoriza::exigirEscrituraEnElAnio($user, $user->year_id ?? null, 'Esa escala de valoración');

		$consulta 	= 'INSERT INTO escalas_de_valoracion(desempenio, orden, valoracion, porc_inicial, porc_final, year_id, perdido, created_at) 
														VALUES("SUPERIOR", 5, "S", 91, 100, ?, 0, ?)';
		DB::insert($consulta, [ $user->year_id, $now ]);

		$consulta 	= 'SELECT * FROM escalas_de_valoracion WHERE year_id=? and deleted_at is null order by id desc';
		$escala 	= DB::select($consulta, [$user->year_id])[0];


		return (array)$escala;
	}


	/**
	 * El id viene en el CUERPO —la ruta es `PUT escalas/update` a secas—, así que
	 * si no llega, o llega uno que no existe, el `UPDATE` no encuentra la fila.
	 *
	 * Antes contestaba «Guardado» igual. Es la familia que persigue
	 * `tools/respuestas-que-mienten.py`: una respuesta que dice que sí cuando fue
	 * que no es peor que un error, porque quien la lee deja de mirar (05 §37, §45).
	 * Ahora es 404, que en esta API significa «esa fila no está» desde la serie
	 * §44/§47/§49/§50/§53.
	 *
	 * **La comprobación es un SELECT y no las filas afectadas**, y eso no es un
	 * capricho: MySQL devuelve 0 filas afectadas cuando el UPDATE no cambia ningún
	 * valor, no sólo cuando no encuentra la fila. Contar filas aquí convertiría
	 * «guardar sin cambiar nada» en un 404. Es el mismo tropiezo que se cazó al
	 * escribir el UPSERT de las definitivas (10-definitivas.md, fase 1).
	 */
	/**
	 * §122 — La séptima de la §81, y la que ningún detector podía ver.
	 *
	 * La §81 cerró seis rutas de editar catálogo que **vaciaban la fila y
	 * contestaban 200**; el barrido posterior de esa misma operación por todo
	 * `app/` dio 28 métodos más. Ésta no salió en ninguna de las dos listas, y
	 * las dos la tenían delante:
	 *
	 *  - el barrido busca `find/findOrFail/first` **más** `Request::input(...)`,
	 *    y aquí la existencia se comprueba con un `SELECT` en un helper privado
	 *    y la escritura es un `DB::update` crudo. **El método no llegó a ser
	 *    candidato**: la población de partida no era `app/`, era la parte de
	 *    `app/` que usa Eloquent — y en este repo hay 990 consultas crudas;
	 *  - y en la §81 se cayó de la lista porque con el cuerpo vacío contesta
	 *    **404**, que es correcto —el id va en el cuerpo— pero **contesta a otra
	 *    pregunta**. La de verdad empieza justo después del id.
	 *
	 * Lo que quedaba escrito, medido el 23 ago 2026 con `PUT escalas/update` y el
	 * cuerpo `{"id":1}`:
	 *
	 *     SUPERIOR · S · 46-50 · orden 5   ->   '' · '' · 0-0 · orden 0
	 *
	 * Seis de las nueve columnas que escribe son `NOT NULL`, y con
	 * `strict => false` eso no es un error: es `''` y `0`. **`porc_inicial=0,
	 * porc_final=0` es la banda colapsada** en la tabla que decide cómo se pinta
	 * el desempeño en todos los boletines del año.
	 *
	 * El respaldo va con el defecto de `input()` y no con `CamposQueVinieron`
	 * —que es lo que usan las seis de la §81— porque el discriminador entre las
	 * dos está medido: la clase hace falta cuando hay un `Request::merge()` o un
	 * `sanarInput*` **antes** de leer, y este controlador no tiene ninguno de los
	 * dos.
	 *
	 * Y el defecto sale de la fila que ya está en la base **sin costar una
	 * consulta**: `exigirQueLaEscalaExista()` ya hacía ese `SELECT`, sólo que
	 * pedía `id`. Ahora devuelve la fila entera.
	 */
	public function putUpdate(Request $request)
	{
		$user 	= User::fromToken();
		$now 	= Carbon::now('America/Bogota');

		$actual = $this->exigirQueLaEscalaExista($request->id);

		// **Renombrar una banda de un año cerrado cambia lo que dicen sus boletines
		// ya impresos**, porque el papel no guarda la palabra: la busca cada vez
		// que se imprime. Decisión de Joseth del 14 sep 2026 — ver
		// `Autoriza::puedeEscribirEnUnAnioCerrado`, que explica por qué esto afina
		// y no revierte la del 24 ago.
		Autoriza::exigirEscrituraEnElAnio($user, $actual->year_id, 'Esa escala de valoración');

		// `input($clave, $defecto)` y no `?:` ni `??`: los dos ceros de esta
		// tabla son legítimos —`porc_inicial = 0` es el borde inferior de la
		// escala más baja y `perdido = 0` el valor normal de las que se
		// aprueban—, así que el respaldo tiene que mirar **si la clave vino**,
		// no si el valor es cierto. Hay un test para cada uno de los dos.
		$consulta 	= 'UPDATE escalas_de_valoracion SET porc_inicial=:ini, porc_final=:fin, desempenio=:desemp, descripcion=:descripcion, icono_adolescente=:adolesc, icono_infantil=:infantil, orden=:orden, perdido=:perdido, valoracion=:valoracion, updated_at=:updated_at
						WHERE id=:id';
		$escalas 	= DB::update($consulta, [
			':ini' 			=> $request->input('porc_inicial', $actual->porc_inicial),
			':fin' 			=> $request->input('porc_final', $actual->porc_final),
			':desemp' 		=> $request->input('desempenio', $actual->desempenio),
			':descripcion' 	=> $request->input('descripcion', $actual->descripcion),
			':adolesc' 		=> $request->input('icono_adolescente', $actual->icono_adolescente),
			':infantil' 	=> $request->input('icono_infantil', $actual->icono_infantil),
			':orden' 		=> $request->input('orden', $actual->orden),
			':perdido' 		=> $request->input('perdido', $actual->perdido),
			':valoracion' 	=> $request->input('valoracion', $actual->valoracion),
			'updated_at' 	=> $now,
			':id' 			=> $request->id,
		]);

		return 'Guardado';

	}


	/**
	 * A la papelera, no borrada: la columna es `deleted_at` y las escalas de un año
	 * pasado siguen decidiendo cómo se pinta el desempeño en los boletines de ese
	 * año.
	 *
	 * Sobre el 404, lo mismo que en `putUpdate`.
	 *
	 * ## Las dos cosas que este método comprueba desde el 14 sep 2026
	 *
	 * **1. El año.** Hasta hoy el docblock decía que *«se puede borrar la escala de
	 * otro año a propósito»* citando la 05 §27.4, y eso **sigue siendo cierto**: lo
	 * que cambia es quién. Joseth lo cerró en superusuarios con las dos poblaciones
	 * delante —74 cuentas de personal, 11 superusuarias—. El porqué entero, y por
	 * qué no rompe el panel de Colegio ▸ Años, está en
	 * `Autoriza::puedeEscribirEnUnAnioCerrado`.
	 *
	 * **2. A cuántos alcanza.** Borrar una banda **degradaba boletines ya impresos
	 * en silencio**: el papel no guarda la palabra —la busca cada vez cruzando la
	 * nota contra la escala viva— así que sin la banda no cae en ninguna y sale
	 * `▯▯▯▯` con la nota al lado. Sin error, sin log y sin ninguna pantalla donde
	 * se vea antes de imprimir. Medido el 14 sep 2026 en la copia de desarrollo de
	 * `simonbolivar`: la banda BÁSICO del año en curso la usan **1.620
	 * definitivas**, y la del año 7, **8.354**.
	 *
	 * Ahora es 422 con los dos números delante, y se borra si vuelven a mandarlo
	 * con `acepto_desviacion`. **Decisión de Joseth: avisar y dejar pasar, no
	 * prohibir**, y con su motivo, que es el que hace que prohibir fuera peor:
	 * *«igual pueden crear un nuevo listón que reemplace el que eliminaron»* — o
	 * sea que reestructurar la escala de un año **es una operación legítima**, y un
	 * 403 dejaría al colegio sin forma de hacerla.
	 *
	 * Es la forma de `PlantillaNotasController::exigirRepartosCompletos`, y por lo
	 * mismo: un aviso que se puede aceptar convierte un efecto invisible en una
	 * decisión, sin quitarle al colegio nada de lo que ya podía hacer.
	 *
	 * **La población viaja y no es de adorno**: `definitivas` es lo que pierde la
	 * palabra en los boletines.
	 *
	 * > **Eran DOS hasta el 17 sep 2026** —`celdas`, las casillas de la rejilla de
	 * > desempeños que apuntaban a esa banda por `frases_asignatura.escala_id`— y se
	 * > fue con la rejilla entera (modelo plano,
	 * > [39](../../../docs/migracion/39-el-modelo-plano-por-competencias.md)). **El
	 * > daño que contaba ya no existe**: sin celdas que guarden un `escala_id`, el
	 * > boletín por competencias deriva el nivel de la definitiva igual que el
	 * > papel de siempre, así que borrar una banda le hace exactamente lo que le
	 * > hace a los otros tres — y eso es lo que cuenta `definitivas`.
	 */
	public function deleteDestroy(Request $request, $id)
	{
		$user 	= User::fromToken();
		$now 	= Carbon::now('America/Bogota');

		$escala = $this->exigirQueLaEscalaExista($id);

		// Antes de mirar el cuerpo: lo que no se puede hacer no se valida.
		Autoriza::exigirEscrituraEnElAnio($user, $escala->year_id, 'Esa escala de valoración');

		$this->avisarDeLoQueArrastra($escala, $request);

		$consulta 	= 'UPDATE escalas_de_valoracion SET deleted_at=?  WHERE `id`=?';
		$escalas 	= DB::update($consulta, [ $now, $id ]);

		return 'En papelera';
	}


	/**
	 * 422 con la población delante, salvo `acepto_desviacion`.
	 *
	 * **Las dos consultas van acotadas al año de la banda**, y eso no es cosmético:
	 * sin el `INNER JOIN periodos` la cuenta suma los nueve años del colegio y
	 * diría 14.054 donde el daño real son 4.178. Una cifra que exagera se aprende a
	 * ignorar, y entonces el aviso deja de avisar.
	 *
	 * `notas_finales` **no tiene `deleted_at`** —comprobado en el esquema, no
	 * supuesto— así que no se filtra; el `deleted_at` que sí hay que mirar es el de
	 * `periodos`, que es por donde se llega al año.
	 *
	 * La regla de la banda es `porc_inicial <= nota < porc_final + 1`, la del doc
	 * 36 y `bd02f66`. **Escrita igual que en los otros trece sitios a propósito**:
	 * si aquí se contara con `<=` el aviso diría un número y el boletín pintaría
	 * otro, que es exactamente el fallo que aquel arreglo vino a cerrar.
	 *
	 * ## Recorre la tabla, y NO se le pone índice
	 *
	 * `notas_finales` no tiene índice aplicable por `nota` —es un rango— y son
	 * **127.891 filas** en la copia de producción.
	 *
	 * Se deja así **a propósito y con la medición delante**: en los nueve años de
	 * `simonbolivar` se han borrado **cero** bandas —36 vivas, ninguna con
	 * `deleted_at`—, o sea que esto corre casi nunca y sólo desde una pantalla de
	 * administración. Quien venga con `tools/indices-que-faltan.php` en la mano: el
	 * índice no falta, está descartado.
	 */
	private function avisarDeLoQueArrastra(object $escala, Request $request): void
	{
		if ($this->acepta($request)) {
			return;
		}

		$definitivas = (int) DB::selectOne(
			'SELECT COUNT(*) AS n FROM notas_finales nf
			   INNER JOIN periodos p ON p.id = nf.periodo_id AND p.deleted_at IS NULL
			  WHERE p.year_id = ? AND nf.nota >= ? AND nf.nota < ? + 1',
			[ $escala->year_id, $escala->porc_inicial, $escala->porc_final ]
		)->n;

		if ($definitivas === 0) {
			return;
		}

		abort(response()->json([
			'message' => 'Esa banda la están usando '.$definitivas.' definitivas de ese año: al '
				.'borrarla, sus boletines se imprimen sin el nivel. Mande `acepto_desviacion` '
				.'para borrarla igual.',
			'definitivas' => $definitivas,
			'year_id' => (int) $escala->year_id,
		], 422));
	}


	/**
	 * `acepto_desviacion`, y **sin verdad laxa**: `FILTER_VALIDATE_BOOLEAN` con
	 * `FILTER_NULL_ON_FAILURE`, que es lo que separa `"false"` y `"0"` de un sí.
	 *
	 * Sin esto, **cualquier cadena** —`"no"`, `"nunca"`— valdría por «sí» y
	 * gobernaría un borrado, que es justo la familia que persigue
	 * `tools/verdad-laxa-que-escribe.py`. Una llave que se abre con cualquier
	 * palabra no es una llave.
	 *
	 * Se lee con `input()` y no del cuerpo a secas para que valga también como
	 * `?acepto_desviacion=1`: el verbo es DELETE y no todos los clientes mandan
	 * cuerpo en un DELETE.
	 */
	private function acepta(Request $request): bool
	{
		if (! $request->has('acepto_desviacion')) {
			return false;
		}

		$leido = filter_var($request->input('acepto_desviacion'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

		if ($leido === null) {
			abort(422, '`acepto_desviacion` tiene que ser verdadero o falso.');
		}

		return $leido;
	}


	/**
	 * 404 si la fila no está. Mira también `deleted_at`: una escala que ya está en
	 * la papelera no está, y volver a borrarla no es «hecho».
	 *
	 * **Devuelve la fila entera y no sólo el id** (§122): `putUpdate` la usa de
	 * respaldo para las columnas que el cliente no mandó, y con el `SELECT` ya
	 * hecho aquí eso no cuesta una consulta de más. Pedía `id` porque hasta la
	 * §122 nadie necesitaba lo demás.
	 */
	private function exigirQueLaEscalaExista(mixed $id): object
	{
		$escala = DB::selectOne('SELECT * FROM escalas_de_valoracion
			WHERE id = ? AND deleted_at IS NULL', [ $id ]);

		if (! $escala) {
			abort(404, 'Esa escala de valoración no existe.');
		}

		return $escala;
	}

}