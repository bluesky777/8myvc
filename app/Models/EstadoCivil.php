<?php namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;



class EstadoCivil extends Model {
	protected $fillable = [];
	protected $table = "estados_civiles";

	use SoftDeletes;
	protected $softDelete = true;
}