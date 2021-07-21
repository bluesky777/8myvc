<?php namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

use DB;


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