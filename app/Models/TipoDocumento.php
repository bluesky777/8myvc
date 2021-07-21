<?php namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;



class TipoDocumento extends Model {
	protected $fillable = [];
	protected $table = "tipos_documentos";

	use SoftDeletes;
	protected $softDelete = true;
}