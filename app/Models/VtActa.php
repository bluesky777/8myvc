<?php namespace App\Models;

use App\Support\SellaConElReloj;
use App\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * El acta de papel de un grupo: el recuento de lo que se votó sin pantalla.
 *
 * --- columnas de la tabla ---
 *
 * @property int $id
 * @property int $votacion_id
 * @property int $grupo_id
 * @property int $conto_user_id
 * @property ?int $firmada_por
 * @property ?string $firmada_en
 * @property ?string $observacion
 * @property ?string $created_at
 * @property ?string $updated_at
 * --- fin de las columnas ---
 *
 * # UN ACTA ES UN RECUENTO, NO UNA LISTA DE VOTOS
 *
 * Y es la diferencia entera con `vt_votos`: allí una fila es **una persona**
 * votando un cargo; aquí una fila de `vt_acta_votos` es **un número** —«a
 * Fulano, 14»—. Por eso un acta **no puede** guardarse como filas de `vt_votos`
 * con un usuario inventado: el índice único de allí lo impediría a la segunda
 * papeleta, y con razón.
 *
 * Las dos formas se suman al contar, no antes.
 *
 * Efecto secundario bueno: **el acta es más secreta que la pantalla**. De un
 * acta no se puede sacar a quién votó nadie, porque el dato no está.
 *
 * # `conto_user_id` Y `firmada_por` SON DOS PERSONAS
 *
 * Quien metió los números y quien los avala. La segunda puede no llegar nunca:
 * **un acta sin firmar es un acta válida**, sólo que provisional. Es la misma
 * distinción que `requisitos_alumno.cerrado_por` frente a `updated_by`.
 *
 * Ver `database/migrations/2026_09_22_700000_las_actas_de_papel.php`.
 */
class VtActa extends Model
{
    // Su tabla la escriben sentencias crudas con `Reloj::ahoraTexto()`, o sea en Bogotá:
    // un `->save()` sin el rasgo sellaría cinco horas movido en la misma columna.
    use SellaConElReloj;

    protected $table = 'vt_actas';

    protected $fillable = [];

    public function votacion()
    {
        return $this->belongsTo(VtVotacion::class, 'votacion_id');
    }

    public function grupo()
    {
        return $this->belongsTo(Grupo::class, 'grupo_id');
    }

    /** Quien metió los números. */
    public function conto()
    {
        return $this->belongsTo(User::class, 'conto_user_id');
    }

    /** Quien los avala, si alguien llegó a hacerlo. */
    public function firmante()
    {
        return $this->belongsTo(User::class, 'firmada_por');
    }

    /** Las cantidades, cargo a cargo. */
    public function votos()
    {
        return $this->hasMany(VtActaVoto::class, 'acta_id');
    }

    /**
     * Cuántos estudiantes tiene el grupo **para efectos de contar papeletas**.
     *
     * Es el tope de cada cargo en `VtActasController::putGrupo`: catorce votos a
     * personero en un grupo de doce es un error de conteo, y el sitio donde se
     * atrapa es la pantalla, no el `INSERT` (ver el docblock de `VtActaVoto`).
     *
     * ## NO ES `VtVotacion::censo()` ACOTADO A UN GRUPO, y la diferencia es la
     * ## razón entera de que exista este método
     *
     * Son dos reglas parecidas con **dos diferencias que aquí importan las dos**:
     *
     *   1. **No exige cuenta de usuario.** `censo()` lleva un `INNER JOIN users`
     *      porque *«se vota con un usuario»* —medido allí: 4 de 1.246 alumnos vivos
     *      no tienen `user_id`—. Aquí se vota **con un papel**, que no pide cuenta.
     *      Descontar a esos cuatro dejaría el tope por debajo de los papeles que de
     *      verdad hay en la caja, y el colegio recibiría un 422 por contar bien.
     *   2. **No mira `vt_grupos_votacion.participa`.** El grupo que vota en papel es
     *      justamente el que suele estar apartado de la urna digital —así está
     *      sembrado el ensayo 901: `grupo 70, participa = 0` con su acta al lado—,
     *      así que filtrar por ahí devolvería **cero** para todos los grupos que
     *      tienen acta. El tope sería 0 y ninguna acta se podría guardar.
     *
     * Lo que sí se conserva de `censo()`, porque es la misma pregunta:
     *
     *   - el **año de la votación** y no el del usuario;
     *   - `estado NOT IN ('RETI','DESE')`, leído de `VtVotacion::ESTADOS_QUE_NO_VOTAN`
     *     para que añadir un estado allí no deje este tope con un hueco;
     *   - **una fila por alumno**, con `MAX(id)` sobre las matrículas vivas del año:
     *     `matriculas` no tiene único sobre (alumno, año) y hay casos reales de dos
     *     vivas, así que sin desempatar el tope diría un alumno de más.
     */
    public static function estudiantesDelGrupo($grupo_id, $year_id): int
    {
        if (! $grupo_id || ! $year_id) {
            return 0;
        }

        $comodines = implode(', ', array_fill(0, count(VtVotacion::ESTADOS_QUE_NO_VOTAN), '?'));

        $consulta = 'SELECT COUNT(*) AS cuantos
                FROM matriculas m
                INNER JOIN grupos g ON g.id = m.grupo_id AND g.year_id = ? AND g.deleted_at IS NULL
                INNER JOIN alumnos a ON a.id = m.alumno_id AND a.deleted_at IS NULL
                WHERE m.deleted_at IS NULL
                  AND m.grupo_id = ?
                  AND m.estado NOT IN ('.$comodines.')
                  AND m.id = (SELECT MAX(m2.id)
                                FROM matriculas m2
                                INNER JOIN grupos g2 ON g2.id = m2.grupo_id AND g2.year_id = ? AND g2.deleted_at IS NULL
                               WHERE m2.alumno_id = m.alumno_id AND m2.deleted_at IS NULL)';

        $datos = array_merge([$year_id, $grupo_id], VtVotacion::ESTADOS_QUE_NO_VOTAN, [$year_id]);

        $fila = DB::selectOne($consulta, $datos);

        return $fila ? (int) $fila->cuantos : 0;
    }

    /**
     * El recuento de papel de una elección entera, listo para sumar al digital.
     *
     * Una fila por `(aspiracion_id, candidato_id)` con la suma de todas las actas
     * —`candidato_id` nulo es el blanco— y **cuántas actas** aportaron a cada una,
     * que es lo que el front pinta como *«63 de tres actas»*.
     *
     * Se agrupa por `candidato_id` y no por la columna generada porque lo que sale
     * de aquí se casa con los candidatos por su id, y el `0` no es un candidato.
     */
    public static function recuentoDeLaVotacion($votacion_id)
    {
        return DB::select('SELECT av.aspiracion_id, av.candidato_id,
                    SUM(av.cantidad) AS cantidad,
                    COUNT(DISTINCT av.acta_id) AS actas
                FROM vt_acta_votos av
                INNER JOIN vt_actas a ON a.id = av.acta_id
                WHERE a.votacion_id = ?
             GROUP BY av.aspiracion_id, av.candidato_id',
            [$votacion_id]);
    }
}
