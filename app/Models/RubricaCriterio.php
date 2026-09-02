<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Una FILA de la matriz: qué se evalúa y cuánto pesa.
 *
 * `peso` es entero y no se normaliza, como `unidades.porcentaje`: la suma de
 * una rúbrica puede no dar 100 y se avisa en pantalla (26 §3). Sin softdelete:
 * un criterio con valoraciones no se puede quitar (26 §4.5), y uno sin ellas se
 * borra de verdad porque no deja rastro en ninguna nota.
 *
 * `@property` a mano por lo que dice `Rubrica`.
 *
 * @property int $id
 * @property int $rubrica_id
 * @property string $definicion
 * @property int $peso
 * @property int $orden
 * @property ?string $created_at
 * @property ?string $updated_at
 */
class RubricaCriterio extends Model
{
    protected $table = 'rubrica_criterios';

    protected $fillable = [];
}
