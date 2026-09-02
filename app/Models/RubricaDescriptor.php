<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Una CELDA de la matriz: el texto que convierte una tabla de pesos en una
 * rúbrica. Sólo existen las celdas con texto; las vacías no tienen fila.
 *
 * Se reescriben enteras en cada guardado de la matriz (24 §4.5): no cuelga
 * nada de ellas y diferenciarlas costaría más que reescribirlas.
 *
 * `@property` a mano por lo que dice `Rubrica`.
 *
 * @property int $id
 * @property int $criterio_id
 * @property int $nivel_id
 * @property string $texto
 * @property ?string $created_at
 * @property ?string $updated_at
 */
class RubricaDescriptor extends Model
{
    protected $table = 'rubrica_descriptores';

    protected $fillable = [];
}
