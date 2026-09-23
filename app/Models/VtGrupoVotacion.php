<?php namespace App\Models;

use App\Support\SellaConElReloj;
use Illuminate\Database\Eloquent\Model;

/**
 * Las **excepciones** de una elección, grupo a grupo.
 *
 * --- columnas de la tabla ---
 *
 * @property int $id
 * @property int $votacion_id
 * @property int $grupo_id
 * @property int $participa
 * @property string $modo
 * @property ?string $created_at
 * @property ?string $updated_at
 * --- fin de las columnas ---
 *
 * # LO PRIMERO, porque cambia cómo se lee todo lo demás
 *
 * > **Sin filas, participan TODOS los grupos del año de la votación.**
 *
 * Esta tabla no es un censo: es la lista de lo que se aparta de la norma. Una
 * fila dice «este grupo queda fuera» (`participa = 0`), «este grupo vota en
 * mesa» (`modo = 'mesa'`), o las dos cosas.
 *
 * Sustituye a `vt_participantes`, que sí era un censo —y por eso una elección no
 * arrancaba hasta que alguien inscribía los veinte grupos, y el que se olvidaba
 * no votaba sin que nadie se enterara: el sistema no distinguía «no inscrito» de
 * «inscrito y sin votar»—. Ver
 * `database/migrations/2026_09_22_400000_los_grupos_dejan_de_ser_un_censo.php`.
 *
 * Quien pregunte «¿quién vota?» **no consulta esta tabla directamente**: llama a
 * `VtVotacion::censo()`, que es donde vive la regla entera.
 *
 * # SIN PAPELERA, a propósito
 *
 * Una excepción que sobra se borra. No hay nada que auditar en ella —lo que se
 * audita es el voto— y un `deleted_at` sin trait, o un trait sin columna, es el
 * fallo de la [05 §58](../../docs/migracion/05-codigo-muerto-y-roto.md) que se
 * llevó por delante `vt_participantes`.
 */
class VtGrupoVotacion extends Model
{
    // Su tabla la escriben sentencias crudas con `Reloj::ahoraTexto()`, o sea en Bogotá:
    // un `->save()` sin el rasgo sellaría cinco horas movido en la misma columna.
    use SellaConElReloj;

    protected $table = 'vt_grupos_votacion';

    protected $fillable = [];

    /** El grupo vota donde cada uno esté. Es el defecto. */
    public const MODO_SOLO = 'solo';

    /** El grupo vota en una mesa conducida por alguien del colegio. */
    public const MODO_MESA = 'mesa';

    /** Los dos valores que puede tomar `modo`; la columna mide cuatro por esto. */
    public const MODOS = [self::MODO_SOLO, self::MODO_MESA];

    public function votacion()
    {
        return $this->belongsTo(VtVotacion::class, 'votacion_id');
    }

    public function grupo()
    {
        return $this->belongsTo(Grupo::class, 'grupo_id');
    }
}
