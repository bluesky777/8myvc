<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Una COLUMNA de la matriz: un nivel de desempeño y el puntaje que vale.
 *
 * Los niveles son de la rúbrica, no del criterio —una matriz con columnas
 * distintas por fila deja de ser legible y de imprimirse (plan §3.6)—. Se
 * siembran desde `escalas_de_valoracion` del año con el punto medio del tramo
 * (24 §4.2), pero el puntaje es del colegio y se edita.
 *
 * `@property` a mano por lo que dice `Rubrica`.
 *
 * @property int $id
 * @property int $rubrica_id
 * @property string $nombre
 * @property int $puntaje
 * @property int $orden
 * @property ?string $created_at
 * @property ?string $updated_at
 */
class RubricaNivel extends Model
{
    protected $table = 'rubrica_niveles';

    protected $fillable = [];
}
