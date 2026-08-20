<?php namespace App\Models;

use Illuminate\Database\Eloquent\Model;
/**
 * Las columnas de `config_certificados`, tal como están en el esquema congelado.
 *
 * Generado desde database/schema/mysql-schema.sql — no se edita a mano.
 * Ver tools/columnas-en-los-modelos.php.
 *
 * --- columnas de la tabla, generadas por tools/columnas-en-los-modelos.php ---
 *
 * @property int $id
 * @property string $nombre
 * @property int $encabezado_img_id
 * @property int $encabezado_width
 * @property int $encabezado_height
 * @property int $encabezado_margin_top
 * @property int $encabezado_margin_left
 * @property int $encabezado_solo_primera_pagina
 * @property int $piepagina_img_id
 * @property int $piepagina_width
 * @property int $piepagina_height
 * @property int $piepagina_margin_bottom
 * @property int $piepagina_margin_left
 * @property int $piepagina_solo_ultima_pagina
 * @property int $created_by
 * @property int $updated_by
 * @property string $created_at
 * @property string $updated_at
 * --- fin de las columnas generadas ---
 */



class ConfigCertificado extends Model {
	protected $table = 'config_certificados';




	
	
}