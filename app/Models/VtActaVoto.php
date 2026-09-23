<?php namespace App\Models;

use App\Support\SellaConElReloj;
use Illuminate\Database\Eloquent\Model;

/**
 * Una cantidad del acta: «a este candidato, en este cargo, tantos».
 *
 * --- columnas de la tabla ---
 *
 * @property int $id
 * @property int $acta_id
 * @property int $aspiracion_id
 * @property ?int $candidato_id
 * @property int $cantidad
 * @property int $candidato_o_blanco  generada: `COALESCE(candidato_id, 0)`. NO se escribe.
 * @property ?string $created_at
 * @property ?string $updated_at
 * --- fin de las columnas ---
 *
 * # `candidato_id` NULO ES EL VOTO EN BLANCO
 *
 * Misma convención que `VtVoto` desde el 22 sep 2026. Una forma y no dos.
 *
 * # EL ÚNICO TENÍA UN AGUJERO, Y LO CIERRA LA BASE DESDE EL 22 SEP 2026
 *
 * El índice de la 700000 era `(acta_id, aspiracion_id, candidato_id)`, y **en
 * MySQL y en MariaDB dos `NULL` no son iguales**: los candidatos con nombre
 * quedaban cerrados y **el blanco no**, así que dos filas de blanco del mismo
 * cargo del mismo acta entraban las dos y el recuento las sumaba — «blanco 3» y
 * «blanco 3» son seis blancos que nadie contó, sin error y sin log. Reproducido en
 * el docker antes de cerrarlo.
 *
 * Hoy el único es
 *
 * > `UNIQUE (acta_id, aspiracion_id, candidato_o_blanco)` —
 * > `vt_acta_votos_unico_con_blanco`
 *
 * con `candidato_o_blanco` **generada** —`COALESCE(candidato_id, 0)`, `VIRTUAL`—,
 * así que el segundo blanco contesta `1062` igual que el segundo candidato. La
 * regla ya no la sostiene el controlador, que es lo que la 700000 dejó pedido. El
 * porqué de `VIRTUAL` y no `STORED` está en
 * `database/migrations/2026_09_22_800000_el_blanco_del_acta_es_uno_solo.php`.
 *
 * **`candidato_o_blanco` no se escribe nunca**: es generada, y MySQL rechaza
 * cualquier `INSERT` o `UPDATE` que la nombre. El `0` vive sólo ahí; en
 * `candidato_id` el blanco sigue siendo `NULL`. Por lo mismo, un `->save()` de
 * Eloquent sobre un modelo recién leído no la manda —no está entre los `dirty`—,
 * pero **`VtActaVoto::create()` con la columna dentro reventaría**.
 *
 * # `cantidad` NO SE COMPARA CON EL CENSO EN LA BASE
 *
 * Un acta con más votos que alumnos es un error de conteo que el colegio tiene
 * que ver y corregir, no un `INSERT` rechazado a medianoche. Lo único imposible
 * para la base es una cantidad negativa, y eso lo cierra el `unsigned`.
 *
 * **Quien sí lo compara es `VtActasController::putGrupo`**, y con un 422 que
 * nombra el cargo: allí hay una pantalla delante y alguien que puede recontar las
 * papeletas. Son dos sitios distintos a propósito — la base protege lo imposible,
 * el controlador atrapa el error humano donde se puede corregir.
 */
class VtActaVoto extends Model
{
    // Su tabla la escriben sentencias crudas con `Reloj::ahoraTexto()`, o sea en Bogotá:
    // un `->save()` sin el rasgo sellaría cinco horas movido en la misma columna.
    use SellaConElReloj;

    protected $table = 'vt_acta_votos';

    protected $fillable = [];

    public function acta()
    {
        return $this->belongsTo(VtActa::class, 'acta_id');
    }

    public function aspiracion()
    {
        return $this->belongsTo(VtAspiracion::class, 'aspiracion_id');
    }

    /** Nulo = voto en blanco. */
    public function candidato()
    {
        return $this->belongsTo(VtCandidato::class, 'candidato_id');
    }
}
