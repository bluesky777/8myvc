<?php namespace App\Models;

use App\Support\SellaConElReloj;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

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
	use SellaConElReloj;
	protected $softDelete = true;



	// Desde el 30 sep 2026 el rastro va sólo a `auditoria` (contrato 5): se fueron
	// `crear()` y `saveUpdateNota()`, que no tenían llamadores. El modelo queda para
	// los lectores y la limpieza; `CentinelaDeLosEscritoresDeBitacoraTest` vigila
	// que nadie vuelva a escribir aquí.
}
