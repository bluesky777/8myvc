<?php namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;


class Contrato extends Model {
	protected $fillable = [];

	use SoftDeletes;
	protected $softDelete = true;
}