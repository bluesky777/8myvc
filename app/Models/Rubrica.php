<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * La cabecera de una rúbrica: a qué año y a qué asignatura pertenece, y si es
 * plantilla. La matriz vive en `RubricaCriterio`, `RubricaNivel` y
 * `RubricaDescriptor`; lo que el docente marcó, en `RubricaValoracion`.
 *
 * Es la §2 de `docs/migracion/26-rubricas.md`. La regla que gobierna todo el
 * dominio está en la §1: **la rúbrica produce la nota y nada más** — ningún
 * informe lee estas tablas, y por eso los modelos no tienen ni una consulta:
 * las lee y escribe `RubricasController`, con SQL, como el resto del repo.
 *
 * **Las `@property` van escritas a mano**, a diferencia de los 47 modelos del
 * esquema congelado: `tools/columnas-en-los-modelos.php` las genera desde
 * `database/schema/mysql-schema.sql`, y las tablas que nacen por migración no
 * están en ese volcado. Cuando el volcado se regenere desde producción con
 * estas tablas dentro, la herramienta las reescribirá y esta nota sobra.
 *
 * @property int $id
 * @property int $year_id
 * @property ?int $asignatura_id
 * @property string $nombre
 * @property ?string $descripcion
 * @property int $es_plantilla
 * @property ?int $created_by
 * @property ?int $updated_by
 * @property ?int $deleted_by
 * @property ?string $deleted_at
 * @property ?string $created_at
 * @property ?string $updated_at
 */
class Rubrica extends Model
{
    use SoftDeletes;

    protected $table = 'rubricas';

    protected $fillable = [];

    /** El vocabulario cerrado de `rubrica_valoraciones.momento` (plan §3.7). */
    public const MOMENTOS = ['original', 'nivelacion'];
}
