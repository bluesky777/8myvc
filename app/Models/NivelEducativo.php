<?php namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;



class NivelEducativo extends Model {
	protected $fillable = [];
	protected $table = "niveles_educativos";

	use SoftDeletes;
	protected $softDelete = true;
}