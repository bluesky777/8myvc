<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Lo que el docente marcó: para una nota, en un criterio, qué nivel.
 *
 * Cuelga de `nota_id` y no de `(alumno_id, subunidad_id)` porque la fila de
 * `notas` es la unidad de trabajo, y borrarla se lleva sus marcas (plan §3.6).
 * `momento` distingue la valoración del antes y la del después de nivelar
 * (plan §3.7); nace con la tabla y no en una migración aparte porque va dentro
 * de la clave única `(nota_id, criterio_id, momento)`.
 *
 * **Esta tabla no produce la nota por sí sola**: `rubricas/valorar` la calcula
 * a partir de aquí y la devuelve, y quien la escribe en `notas.nota` es
 * `notas/update`, tal como está (26 §1).
 *
 * `@property` a mano por lo que dice `Rubrica`.
 *
 * @property int $id
 * @property int $nota_id
 * @property int $criterio_id
 * @property int $nivel_id
 * @property string $momento
 * @property ?string $comentario
 * @property ?int $created_by
 * @property ?int $updated_by
 * @property ?string $created_at
 * @property ?string $updated_at
 */
class RubricaValoracion extends Model
{
    protected $table = 'rubrica_valoraciones';

    protected $fillable = [];
}
