<?php namespace App\Models;

use App\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;


use Illuminate\Support\Facades\DB;
/**
 * Las columnas de `vt_candidatos`, tal como están en el esquema congelado.
 *
 * Generado desde database/schema/mysql-schema.sql — no se edita a mano.
 * Ver tools/columnas-en-los-modelos.php.
 *
 * --- columnas de la tabla, generadas por tools/columnas-en-los-modelos.php ---
 *
 * @property int $id
 * @property int $user_id
 * @property int $aspiracion_id
 * @property ?string $plancha
 * @property ?string $numero
 * @property int $locked
 * @property ?int $created_by
 * @property ?int $updated_by
 * @property ?int $deleted_by
 * @property ?string $deleted_at
 * @property ?string $created_at
 * @property ?string $updated_at
 * --- fin de las columnas generadas ---
 *
 * # LO QUE HAY QUE SABER ANTES DE ESCRIBIR UN TEST AQUÍ
 *
 * `porAspiracion()` —la consulta que arma la papeleta— **une sólo con
 * `alumnos`**, con matrícula en `MATR`/`ASIS`/`PREM` y filtrando por
 * `usus.year_id`. La versión comentada de justo encima unía además con
 * profesores, acudientes y usuarios sueltos.
 *
 * Consecuencia, y está medida: **un candidato cuyo `user_id` no sea el de un
 * alumno matriculado en ese año no da error, desaparece de la papeleta en
 * silencio** ([11 §1](../../docs/migracion/11-votaciones.md)). Un test que ponga
 * `user_id => 1` recibe una lista con un solo elemento —el «Voto en Blanco», que
 * se añade después— y un `assertNotEmpty` encima pasa sin haber mirado ningún
 * candidato.
 *
 * Y la consecuencia para el producto, **que esta tanda NO ha tocado**: un
 * profesor no puede aparecer en la papeleta, aunque `votan_profes` exista desde
 * 2014 y ahora `votan_administrativos` también. Descomentar la unión grande
 * enciende candidaturas nuevas en dieciséis colegios, así que es una decisión y
 * no un arreglo — queda como estaba y anotado aquí.
 *
 * # Y LO QUE ESTA CONSULTA DEVUELVE PERO LAS PUERTAS ABIERTAS YA NO MANDAN
 *
 * `porAspiracion()` sigue trayendo el `username` —que en estos colegios es el
 * **número de documento**—, porque `candidatos/store` y `mesas/{id}/abrir` son
 * respuestas de personal y ahí identifica a la persona. Lo que se le quitó, el 23
 * sep 2026, es a las **tres** respuestas que recibe cualquier alumno:
 * `votaciones/en-accion-inscrito`, `candidatos/conaspiraciones` y `votos/show`
 * —el tarjetón, que se cerró en la misma fecha y en una pasada aparte—. Lo recorta
 * `sinElDocumento()`, y ahí está el porqué y lo que se miró antes de quitarlo.
 */


class VtCandidato extends Model {
	protected $fillable = [];
	protected $table = "vt_candidatos";

	use SoftDeletes;
	protected $softDelete = true;

	public static function porAspiracion($aspiracion_id, $year_id)
	{
		/*
		$consulta = 'SELECT c.id as candidato_id, c.plancha, c.numero, usus.persona_id, 
					usus.nombres, usus.apellidos, usus.user_id, usus.username, usus.tipo, usus.imagen_id, usus.imagen_nombre, usus.nombre_grupo, usus.abrev_grupo, 
					usus.foto_id, usus.foto_nombre, usus.imagen_id, usus.imagen_nombre
				FROM vt_candidatos c 
				INNER JOIN users u ON u.id=c.user_id and c.aspiracion_id=:aspiracion_id
				inner join (
					
				SELECT p.id as persona_id, p.nombres, p.apellidos, p.user_id, u.username, 
					("Pr") as tipo, p.sexo, 
					u.imagen_id, IFNULL(i.nombre, IF(p.sexo="F","default_female.png", "default_male.png")) as imagen_nombre, 
					p.foto_id, IFNULL(i2.nombre, IF(p.sexo="F","default_female.png", "default_male.png")) as foto_nombre, 
					"N/A" as grupo_id, ("N/A") as nombre_grupo, ("N/A") as abrev_grupo, "N/A" as year_id  
					from profesores p 
					inner join users u on p.user_id=u.id
					left join images i on i.id=u.imagen_id
					left join images i2 on i2.id=p.foto_id
					where p.deleted_at is null
				union
				SELECT a.id as persona_id, a.nombres, a.apellidos, a.user_id, u.username, 
					("Al") as tipo, a.sexo, 
					u.imagen_id, IFNULL(i.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as imagen_nombre, 
					a.foto_id, IFNULL(i2.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as foto_nombre, 
					g.id as grupo_id, g.nombre as nombre_grupo, g.abrev as abrev_grupo, g.year_id
					from alumnos a 
					inner join users u on a.user_id=u.id
					inner join matriculas m on m.alumno_id=a.id and (m.estado="MATR" or m.estado="ASIS" or m.estado="PREM")
					inner join grupos g on g.id=m.grupo_id
					left join images i on i.id=u.imagen_id
					left join images i2 on i2.id=a.foto_id
					where a.deleted_at is null
				union
				SELECT ac.id as persona_id, ac.nombres, ac.apellidos, ac.user_id, u.username, 
					("Acu") as tipo, ac.sexo, 
					u.imagen_id, IFNULL(i.nombre, IF(ac.sexo="F","default_female.png", "default_male.png")) as imagen_nombre, 
					ac.foto_id, IFNULL(i2.nombre, IF(ac.sexo="F","default_female.png", "default_male.png")) as foto_nombre, 
					"N/A" as grupo_id, ("N/A") as nombre_grupo, ("N/A") as abrev_grupo, "N/A" as year_id
					from acudientes ac 
					inner join users u on ac.user_id=u.id
					left join images i on i.id=u.imagen_id
					left join images i2 on i2.id=ac.foto_id
					where ac.deleted_at is null
				union
				SELECT u.id as persona_id, "" as nombres, "" as apellidos, u.id as user_id, u.username,
					("Usu") as tipo, u.sexo, 
					u.imagen_id, IFNULL(i.nombre, IF(u.sexo="F","default_female.png", "default_male.png")) as imagen_nombre, 
					u.imagen_id as foto_id, IFNULL(i.nombre, IF(u.sexo="F","default_female.png", "default_male.png")) as foto_nombre, 
					"N/A" as grupo_id, ("N/A") as nombre_grupo, ("N/A") as abrev_grupo, "N/A" as year_id  
					from users u
					left join images i on i.id=u.imagen_id 
					where u.id not in (SELECT p.user_id
								from profesores p 
								inner join users u on p.user_id=u.id
							union
							SELECT a.user_id
								from alumnos a 
								inner join users u on a.user_id=u.id
							union
							SELECT ac.user_id
								from acudientes ac 
								inner join users u on ac.user_id=u.id
						)
					and u.deleted_at is null ) usus
					on usus.user_id=c.user_id
				where c.deleted_at is null and usus.year_id=:year_id order by c.plancha';
		*/
		$consulta = 'SELECT c.id as candidato_id, c.plancha, c.numero, usus.persona_id, 
					usus.nombres, usus.apellidos, usus.user_id, usus.username, usus.tipo, usus.imagen_id, usus.imagen_nombre, usus.nombre_grupo, usus.abrev_grupo, 
					usus.foto_id, usus.foto_nombre, usus.imagen_id, usus.imagen_nombre
				FROM vt_candidatos c 
				INNER JOIN users u ON u.id=c.user_id and c.aspiracion_id=:aspiracion_id
				inner join (
					
				SELECT a.id as persona_id, a.nombres, a.apellidos, a.user_id, u.username, 
						("Al") as tipo, a.sexo, 
						u.imagen_id, IFNULL(i.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as imagen_nombre, 
						a.foto_id, IFNULL(i2.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as foto_nombre, 
						g.id as grupo_id, g.nombre as nombre_grupo, g.abrev as abrev_grupo, g.year_id
					from alumnos a 
					inner join users u on a.user_id=u.id
					inner join matriculas m on m.alumno_id=a.id and (m.estado="MATR" or m.estado="ASIS" or m.estado="PREM")
					inner join grupos g on g.id=m.grupo_id
					left join images i on i.id=u.imagen_id
					left join images i2 on i2.id=a.foto_id
					where a.deleted_at is null
				 ) usus
					on usus.user_id=c.user_id
				where c.deleted_at is null and usus.year_id=:year_id order by c.plancha';
				
		$datos = array(
			':aspiracion_id'	=> $aspiracion_id,
			':year_id'			=> $year_id);

		$candidatos = DB::select($consulta, $datos);

		return $candidatos;
	}

	/**
	 * La misma papeleta **sin el número de documento de los candidatos**.
	 *
	 * `porAspiracion()` cuelga de cada candidato su `username`, y en estos colegios
	 * el `username` es el número de documento. Un tarjetón necesita nombre, número,
	 * plancha y foto; el documento de identidad de un menor, no — y esa papeleta la
	 * recibe **cualquier alumno** por tres puertas abiertas:
	 *
	 *   - `GET votaciones/en-accion-inscrito` (`VtVotacionesController`)
	 *   - `GET candidatos/conaspiraciones` (`VtCandidatosController`)
	 *   - `PUT votos/show` (`VtVotosController::putShow()`) — **la tercera, cerrada el
	 *     23 sep 2026**: es el tarjetón, tampoco lleva `auth.personal` y sirve la misma
	 *     lista a cualquiera que pida `permitir: true`. Se cerró aparte porque no
	 *     estaba en la lista del §9, que sólo contó las dos papeletas.
	 *
	 * Es el punto 1 de «lo que se vio en el mismo método y no se tocó» de la
	 * [11 §9](../../docs/migracion/11-votaciones.md), que lo dejó como decisión de
	 * producto con dos pantallas que mirar. Mirado el 23 sep 2026: **no lo pinta
	 * nadie** —cero lecturas en `app2/src/app/paginas/votaciones/` y
	 * `app2/src/app/datos/`, cero en el AngularJS congelado de
	 * `app/scripts/votaciones/`, y `myvc_flutter` no tiene dónde guardarlo—. El único
	 * `username` de la pantalla vieja de candidatos (`candidatos.html:20`) es el del
	 * desplegable de elegibles, que sale de otra respuesta.
	 *
	 * Para la tercera se volvió a mirar, porque sus consumidores son otros:
	 * `ResultadosCtrl` y `TarjetonesCtrl` —`app/scripts/votaciones/ResultadosCtrl.ts`,
	 * líneas 41 y 65— son los **únicos** que llaman a `VotosApi.resultados()`, y ni
	 * `tarjetones.html` ni `resultados.html` pintan `username`: cero apariciones en
	 * las dos plantillas. En `app2`, `VotosApi.resultados()` (`datos/votos.ts`) no la
	 * llama ninguna pantalla —sólo su propio spec—, y `myvc_flutter` no toca
	 * `votos/show`. Así que aquí tampoco hay front que desplegar.
	 *
	 * ## POR QUÉ SE RECORTA AQUÍ Y NO EN LA CONSULTA
	 *
	 * Porque de la misma consulta salen **dos respuestas de personal donde el
	 * documento sí identifica a la persona**: `candidatos/store` —la pantalla de
	 * inscribir, que acaba de elegir a alguien por su documento— y `mesas/{id}/abrir`.
	 * Quitarlo del SQL se las llevaría también. Lo que se quita son las tres puertas
	 * abiertas, que son las que lo entregaban a quien no debía.
	 *
	 * Mismo camino que `VtVotacion::sinElHash()`: recortar la fila cruda justo antes
	 * de devolverla, porque `$hidden` sólo actúa sobre Eloquent y esto es
	 * `DB::select`. Los campos técnicos —`user_id`, `persona_id`, `foto_id`,
	 * `imagen_id`— se quedan: con ellos se pinta la papeleta.
	 *
	 * @param  array<int, mixed>  $candidatos
	 * @return array<int, mixed>
	 */
	public static function sinElDocumento(array $candidatos): array
	{
		foreach ($candidatos as $candidato) {
			if (is_object($candidato)) {
				unset($candidato->username);
			}
		}

		return $candidatos;
	}

	/*
	 * `porAspiracionAnterior()` se ha ido con `vt_participantes`.
	 *
	 * Hacía `inner join vt_participantes vp on … vp.id=c.user_id` —comparaba un
	 * `users.id` con un id del censo, que son dos numeraciones distintas— y no la
	 * llamaba nadie: cero referencias en `app/`, `routes/` y `tests/`. Con la
	 * tabla tirada no habría podido ni ejecutarse, así que se borra en vez de
	 * dejarla rota. Ver
	 * `database/migrations/2026_09_22_400000_los_grupos_dejan_de_ser_un_censo.php`.
	 */

	/**
	 * Si esta persona puede aparecer en la papeleta de ese año.
	 *
	 * **La misma condición exacta que `porAspiracion()`**, y ese es todo el punto:
	 * alumno no borrado, con matrícula en `MATR`/`ASIS`/`PREM`, en un grupo de ese
	 * año. Si las dos consultas se separaran, `candidatos/store` volvería a aceptar
	 * inscripciones que la papeleta no enseña.
	 *
	 * Porque eso es lo que pasaba hasta hoy y está medido en la
	 * [11 §1](../../docs/migracion/11-votaciones.md): `postStore()` insertaba el
	 * `user_id` que viniera en el cuerpo —un docente, un acudiente, un alumno de
	 * otro año— **sin error y sin aviso**, y el candidato simplemente no salía en
	 * la lista. El colegio inscribe a alguien, lo ve guardado, y el día de la
	 * elección no está.
	 *
	 * ## POR QUÉ NO SE VALIDA CONTRA `VtVotacion::censo()`
	 *
	 * Son dos preguntas distintas y aquí hace falta la segunda: el censo dice
	 * **quién vota** —y excluye los grupos apartados con `participa = 0`—, y esto
	 * dice **quién sale en la papeleta**, que es lo que `porAspiracion()` puede
	 * enseñar. Un grupo apartado de la votación puede tener candidato; lo que no
	 * puede haber es un candidato que la papeleta no sepa pintar.
	 *
	 * ## Y POR QUÉ NO FILTRA `matriculas.deleted_at`
	 *
	 * Porque `porAspiracion()` tampoco lo filtra, y lo que esto tiene que
	 * contestar es «¿va a salir en la papeleta?», no «¿debería?». Añadir el filtro
	 * aquí dejaría fuera a alguien que la papeleta sí pinta, que es el mismo
	 * desajuste por el otro lado. El día que se le ponga el filtro a la consulta
	 * de la papeleta, se le pone a las dos.
	 */
	public static function elegible($user_id, $year_id): bool
	{
		if (! $user_id || ! $year_id) {
			return false;
		}

		$fila = DB::selectOne('SELECT a.id
			FROM alumnos a
			INNER JOIN matriculas m ON m.alumno_id = a.id
				 AND (m.estado = "MATR" OR m.estado = "ASIS" OR m.estado = "PREM")
			INNER JOIN grupos g ON g.id = m.grupo_id AND g.year_id = ? AND g.deleted_at IS NULL
			WHERE a.user_id = ? AND a.deleted_at IS NULL
			LIMIT 1', [$year_id, $user_id]);

		return $fila !== null;
	}

	public function aspiracion()
	{
		return $this->belongsTo(VtAspiracion::class, 'aspiracion_id');
	}

	/** La persona que se presenta. */
	public function usuario()
	{
		return $this->belongsTo(User::class, 'user_id');
	}

	/**
	 * Los votos que ha recibido.
	 *
	 * Los votos en blanco **no cuelgan de aquí** —su `candidato_id` es nulo—, así
	 * que esta relación cuenta candidatos con nombre. El blanco se cuenta con
	 * `VtVoto::enBlanco($aspiracion_id)`.
	 */
	public function votos()
	{
		return $this->hasMany(VtVoto::class, 'candidato_id');
	}
}
