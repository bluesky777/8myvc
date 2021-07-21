<?php namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;



class Materia extends Model {
	use SoftDeletes;
	
	protected $fillable = [];

	protected $dates = ['deleted_at', 'created_at'];
	protected $softDelete = true;
}