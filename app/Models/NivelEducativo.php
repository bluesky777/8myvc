<?php namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
/**
 * Las columnas de `niveles_educativos`, tal como están en el esquema congelado.
 *
 * Generado desde database/schema/mysql-schema.sql — no se edita a mano.
 * Ver tools/columnas-en-los-modelos.php.
 *
 * --- columnas de la tabla, generadas por tools/columnas-en-los-modelos.php ---
 *
 * @property int $id
 * @property string $nombre
 * @property string $abrev
 * @property int $orden
 * @property int $created_by
 * @property int $updated_by
 * @property int $deleted_by
 * @property string $deleted_at
 * @property string $created_at
 * @property string $updated_at
 * --- fin de las columnas generadas ---
 */



class NivelEducativo extends Model {
	protected $fillable = [];
	protected $table = "niveles_educativos";

	use SoftDeletes;
	protected $softDelete = true;
}