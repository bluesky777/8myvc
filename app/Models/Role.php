<?php namespace App\Models;


use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
/**
 * Las columnas de `roles`, tal como están en el esquema congelado.
 *
 * Generado desde database/schema/mysql-schema.sql — no se edita a mano.
 * Ver tools/columnas-en-los-modelos.php.
 *
 * --- columnas de la tabla, generadas por tools/columnas-en-los-modelos.php ---
 *
 * @property int $id
 * @property string $name
 * @property ?int $created_by
 * @property ?int $updated_by
 * @property ?int $deleted_by
 * @property ?string $display_name
 * @property ?string $description
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
 * @property array $perms  los permisos del rol, resueltos aparte
 */


class Role extends Model
{

	use SoftDeletes;
	protected $softDelete = true;

	/**
     * The users that belong to the role.
     */
    public function users()
    {
        return $this->belongsToMany('App\User');
    }


	// Devolveremos los permisos del rol, detallado o solo el texto nombre.
	public function permissions($detailed=false)
	{

		$consulta = 'SELECT pm.name, pm.display_name, pm.description from permission_role pmr
				inner join permissions pm on pm.id = pmr.permission_id 
					and pmr.role_id = :role_id';
		
		$permisos = DB::select($consulta, array(':role_id' => $this->id));
		
		$perms = array();

		foreach ($permisos as $permiso) {
			if ($detailed) {
				array_push($perms, $permiso);
			}else{
				array_push($perms, $permiso->name);
			}
		}

		return $perms;
	}


	public static function allConPermisos($detailed=false)
	{
		$roles = Role::all();

		foreach ($roles as $rol) {
			$rol->perms = $rol->permissions($detailed);
		}
		return $roles;

	}

	public static function getUserRoles($user_id) {
		$consulta = 'SELECT ru.role_id, r.name FROM users u 
			INNER JOIN role_user ru ON ru.user_id = u.id
			INNER JOIN roles r ON r.id = ru.role_id and r.deleted_at is null
			WHERE u.id = :user_id';
		$roles = DB::select($consulta, array(
			':user_id'		=> $user_id,
		));
		return $roles;
	}

	/**
	 * El rol `Coord disciplinario`, el cuarto de la familia que no gobierna nada.
	 *
	 * Existe en la tabla y tiene gente dentro, pero **ya no lo llama nadie**. Sus
	 * dos únicos llamantes estaban en `AusenciasController`, calculándolo para
	 * tirarlo a la basura en un `if` de cuerpo vacío; el 22 ago 2026 Joseth
	 * decidió que corregir y borrar una falta se queda abierto al personal y el
	 * cálculo muerto se retiró. El porqué —y qué clientes habría que publicar
	 * antes si algún día se cierra— está escrito en ese controlador.
	 *
	 * Falla al revés que Psicólogo y Enfermero: aquellos **cerraban de más**
	 * preguntando por un `users.tipo` que no toma ese valor nunca; éste no cerró
	 * nada. Se deja el método, y no se borra, porque el día que el colegio decida
	 * quién corrige una falta es aquí donde se va a buscar.
	 *
	 * Era una copia a mano del bucle de `hasRole()`. Ahora lo llama.
	 */
	public static function isCoorDisciplinario($user_id) {
		return Role::hasRole($user_id, 'Coord disciplinario');
	}

	public static function isSecretario($user_id) {
		return Role::hasRole($user_id, 'Secretario');
	}

	/**
	 * El rol `Enfermero`, la tercera de la misma familia.
	 *
	 * `EnfermeriaController::putGuardarValor` preguntaba
	 * `$this->user->tipo == 'Enfermero'` **con el mismo comentario al lado que la
	 * rama del psicólogo** —«Debo verificar que tenga rol Enfermero. Por ahora lo
	 * dejo Usuario para que funcione»— y `tipo` no toma ese valor nunca. La
	 * consecuencia era la misma: cierra de más. La enfermera del colegio no podía
	 * escribir los antecedentes médicos salvo que fuera superusuaria.
	 * Ver docs/migracion/05-codigo-muerto-y-roto.md §41.2.
	 */
	public static function isEnfermero($user_id) {
		return Role::hasRole($user_id, 'Enfermero');
	}

	/**
	 * El rol `Psicólogo`, que sí existe desde 2019 y no gobernaba nada.
	 *
	 * Lo asigna `users/crear-psicologo` (inserta el role_id 11 a pelo) y tiene
	 * cuatro personas dentro. El único sitio que quería preguntar por él
	 * comparaba `users.tipo` con `'Psicólogo'`, y `tipo` no toma ese valor
	 * nunca. Ver docs/migracion/05-codigo-muerto-y-roto.md §30.2.
	 *
	 * El nombre lleva tilde en la tabla y por eso está escrito con tilde aquí:
	 * la comparación de `hasRole()` es en PHP y no la salva la collation.
	 */
	public static function isPsicologo($user_id) {
		return Role::hasRole($user_id, 'Psicólogo');
	}

	public static function hasRole($user_id, $role) {
		$roles = Role::getUserRoles($user_id);
		$isRole = false;
		for ($i=0; $i < count($roles); $i++) { 
			if ($roles[$i]->name == $role) {
				$isRole = true;
				break;
			}
		}
		return $isRole;
	}
}