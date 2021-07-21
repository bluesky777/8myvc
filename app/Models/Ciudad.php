<?php namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;


class Ciudad extends Model {
	protected $table = 'ciudades';

	use SoftDeletes;
	protected $softDelete = true;
}