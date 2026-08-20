<?php namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

use Illuminate\Support\Facades\DB;
/**
 * Las columnas de `bitacoras`, tal como están en el esquema congelado.
 *
 * Generado desde database/schema/mysql-schema.sql — no se edita a mano.
 * Ver tools/columnas-en-los-modelos.php.
 *
 * --- columnas de la tabla, generadas por tools/columnas-en-los-modelos.php ---
 *
 * @property int $id
 * @property int $created_by
 * @property ?int $historial_id
 * @property ?string $descripcion
 * @property ?int $affected_user_id
 * @property ?int $affected_person_id
 * @property ?string $affected_person_name
 * @property ?string $affected_person_type
 * @property ?string $affected_element_type
 * @property ?int $affected_element_id
 * @property ?string $affected_element_new_value_string
 * @property ?string $affected_element_old_value_string
 * @property ?int $affected_element_new_value_int
 * @property ?int $affected_element_old_value_int
 * @property ?int $periodo_id
 * @property ?int $deleted_by
 * @property ?string $deleted_at
 * @property ?string $created_at
 * @property ?string $updated_at
 * --- fin de las columnas generadas ---
 */


class Bitacora extends Model {
	protected $fillable = [];

	use SoftDeletes;
	protected $softDelete = true;



	public static function crear($user_id)
	{
		$bit = new Bitacora;
		$bit->created_by = $user_id;
		return $bit;
	}


	public function saveUpdateNota($nota)
	{

		$consulta = 'SELECT s.id as subunidad_id, s.definicion as definicion_subunidad, s.porcentaje, s.unidad_id,
						al.nombres, al.id as alumno_id, al.user_id, al.nombres, al.apellidos
					from subunidades s
					inner join unidades u on u.id=s.unidad_id and s.id=:subunidad_id
					inner join alumnos al on al.id=:alumno_id';

		$datos = DB::select($consulta, array(
			':subunidad_id' => $nota->subunidad_id, 
			':alumno_id'	=> $nota->alumno_id))[0];

		$datos = (object)$datos;

		$this->affected_element_type 	= 'Nota';
		$this->affected_element_id 		= $nota->id;
		$this->affected_user_id 		= $datos->user_id;
		$this->affected_person_id 		= $datos->alumno_id;
		$this->affected_person_type 	= 'Al';

		/*
		$this->descripcion = 'Cambió la nota al alumno "' . $this->affected_person_name . '", de "'.$this->affected_element_old_value_int.'" por "'.$this->affected_element_new_value_int.'" 
			en la subunidad "'.$datos->definicion_subunidad.'" en la materia "'.$datos->materia.'", 
			periodo "'.$datos->numero_periodo.'".';
		*/
		
		$this->save();
		return $this;
	}
}