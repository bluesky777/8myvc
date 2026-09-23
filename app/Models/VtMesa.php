<?php namespace App\Models;

use App\Support\Reloj;
use App\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Una mesa de votación: un equipo, quien lo conduce y los grupos que pasan.
 *
 * --- columnas de la tabla ---
 *
 * @property int $id
 * @property int $votacion_id
 * @property string $nombre
 * @property int $activa
 * @property ?string $created_at
 * @property ?string $updated_at
 * --- fin de las columnas ---
 *
 * # QUÉ NO ES
 *
 * **No es el censo.** Quién puede votar lo contesta `VtVotacion::censo()`; esto
 * dice *dónde* y *con quién delante*. Un grupo sin mesa vota igual, salvo que la
 * elección lleve `solo_en_mesa = 1`, que es el único sitio donde las dos
 * preguntas se tocan.
 *
 * **Y no es la única forma de conducir una mesa.** Con
 * `vt_votaciones.titulares_conducen = 1` —el defecto— el titular conduce el suyo
 * sin que exista ninguna fila aquí. Al resolver «¿puede éste conducir el grupo
 * X?» hay que mirar las dos cosas, y ojo: `grupos.titular_id` apunta a
 * `profesores.id`, **no a `users.id`**.
 *
 * # `activa` NO ES `deleted_at`
 *
 * Una mesa se apaga cuando se acaba el papel y se vuelve a encender media hora
 * después. Eso conserva la mesa, su equipo y sus grupos, y el acta que cuelgue
 * de ella sigue teniendo sentido. Estas tablas no llevan papelera.
 *
 * Ver `database/migrations/2026_09_22_500000_las_mesas_de_votacion.php`.
 */
class VtMesa extends Model
{
    protected $table = 'vt_mesas';

    protected $fillable = [];

    /**
     * Cuánto vale una marca de sesión de mesa, en segundos.
     *
     * Diez minutos: lo que tarda alguien en leer un tarjetón con cuatro cargos y
     * decidirse, con margen. No es el candado que impide votar dos veces —ese es
     * el índice único de `vt_votos`—, es el que impide que la marca de las nueve
     * de la mañana siga sirviendo a las dos de la tarde.
     */
    public const SEGUNDOS_DE_SESION = 600;

    public function votacion()
    {
        return $this->belongsTo(VtVotacion::class, 'votacion_id');
    }

    /**
     * Quién conduce esta mesa. Varios, porque la gente se releva en el descanso.
     *
     * Son cuentas de `users` y no fichas de `profesores`: quien conduce puede ser
     * una cuenta de secretaría, que no tiene ficha.
     */
    public function usuarios()
    {
        return $this->belongsToMany(User::class, 'vt_mesa_usuarios', 'mesa_id', 'user_id')
            ->withTimestamps();
    }

    /** Los grupos que pasan por esta mesa. */
    public function grupos()
    {
        return $this->belongsToMany(Grupo::class, 'vt_mesa_grupos', 'mesa_id', 'grupo_id')
            ->withTimestamps();
    }

    /*
     * ─────────────────────────────────────────────────────────────────────────
     *  LA MARCA DE SESIÓN DE MESA — y por qué es un token firmado y no una fila
     * ─────────────────────────────────────────────────────────────────────────
     *
     * El problema que resuelve, en una frase: **en una mesa vota el alumno pero
     * la petición la manda la cuenta del que conduce**. O sea que `votos/store`
     * recibe un token de un docente y tiene que escribir una fila con el
     * `user_id` de otra persona. Sin nada más, eso es un endpoint que deja a
     * cualquier miembro del personal votar por cualquier alumno.
     *
     * La marca es lo que autoriza ese salto, y dice exactamente cuatro cosas:
     * **esta mesa, esta elección, este votante, este asistente**, con caducidad.
     * La firma la pone `config('app.key')` con HMAC-SHA256, así que el cliente no
     * puede fabricarla ni cambiarle el votante.
     *
     * ## POR QUÉ NO UNA FILA EN UNA TABLA, que era la otra opción
     *
     * 1. **No hay tabla y no puedo crearla**: las migraciones de esta tanda ya
     *    están escritas y cerradas, y una sesión de mesa que necesite un
     *    `ALTER` es una sesión que no se puede desplegar esta semana.
     * 2. **Una fila hay que borrarla**, y lo que no se borra se queda: una tabla
     *    de sesiones sin limpieza es basura que crece cada elección. El token
     *    caduca solo, sin cron y sin nadie.
     * 3. **No guarda nada que no esté ya guardado.** Lo que hay que auditar del
     *    día de la elección es el voto, y el voto ya lleva `mesa_id`,
     *    `asistido_por`, `origen` y `segundos` en su propia fila. La sesión no
     *    es un hecho que el colegio vaya a consultar: es un permiso de cinco
     *    minutos.
     *
     * ## LO QUE LA MARCA *NO* HACE, y hay que tenerlo claro
     *
     * **No impide votar dos veces.** Eso es el índice único
     * `vt_votos_un_voto_por_cargo`, y sigue siendo la base quien lo hace
     * cumplir: una marca robada y repetida choca con el 1062 igual que dos
     * pestañas del propio alumno. La marca sólo contesta «¿puede esta cuenta
     * escribir un voto a nombre de esta otra?».
     *
     * **Y no se revoca.** Apagar la mesa a los dos minutos de abrirla no
     * invalida la marca que ya salió; lo que la invalida es que el voto ya no
     * quepa, o que pasen los diez minutos. Es el precio de no tener fila, y es
     * el correcto: revocar sesiones no es lo que un colegio hace un martes por
     * la mañana.
     */

    /**
     * Sella una marca de sesión de mesa.
     *
     * `cuerpo.firma`, las dos partes en base64url para que viajen por JSON y por
     * una URL sin escaparse.
     */
    public static function sellarSesion(int $mesa_id, int $votacion_id, int $votante_user_id, int $asistido_por): string
    {
        $datos = [
            'mesa' => $mesa_id,
            'votacion' => $votacion_id,
            'votante' => $votante_user_id,
            'asistente' => $asistido_por,
            'exp' => Reloj::ahora()->getTimestamp() + self::SEGUNDOS_DE_SESION,
        ];

        $cuerpo = self::aBase64Url((string) json_encode($datos));

        return $cuerpo.'.'.self::firmaDe($cuerpo);
    }

    /**
     * Lee una marca de sesión y devuelve lo que dice, o `null` si no vale.
     *
     * `null` en los cuatro casos —vacía, mal formada, firma que no cuadra,
     * caducada— **a propósito**: quien la llama contesta un 403 y no tiene que
     * elegir entre cuatro mensajes que al de la mesa le dicen lo mismo, «vuelve
     * a abrir la papeleta».
     *
     * `hash_equals` y no `===`: comparar firmas carácter a carácter filtra por
     * el tiempo de respuesta cuántos coinciden.
     */
    public static function leerSesion($marca)
    {
        if (! is_string($marca) || $marca === '' || substr_count($marca, '.') !== 1) {
            return null;
        }

        [$cuerpo, $firma] = explode('.', $marca, 2);

        if (! hash_equals(self::firmaDe($cuerpo), $firma)) {
            return null;
        }

        $datos = json_decode((string) self::deBase64Url($cuerpo));

        if (! is_object($datos) || ! isset($datos->exp, $datos->mesa, $datos->votacion, $datos->votante, $datos->asistente)) {
            return null;
        }

        if ((int) $datos->exp < Reloj::ahora()->getTimestamp()) {
            return null;
        }

        return $datos;
    }

    private static function firmaDe(string $cuerpo): string
    {
        return self::aBase64Url(hash_hmac('sha256', $cuerpo, (string) config('app.key'), true));
    }

    private static function aBase64Url(string $crudo): string
    {
        return rtrim(strtr(base64_encode($crudo), '+/', '-_'), '=');
    }

    private static function deBase64Url(string $texto)
    {
        return base64_decode(strtr($texto, '-_', '+/'), true);
    }

    /*
     * ─────────────────────────────────────────────────────────────────────────
     *  QUIÉN CONDUCE, QUÉ GRUPOS PASAN
     * ─────────────────────────────────────────────────────────────────────────
     */

    /**
     * Si este usuario puede conducir esta mesa.
     *
     * Son **dos caminos** y hay que mirar los dos, que es lo que avisa la
     * cabecera de esta clase:
     *
     *   - una fila suya en `vt_mesa_usuarios`, o
     *   - ser titular de alguno de los grupos que la mesa atiende, cuando la
     *     elección lleva `titulares_conducen = 1` (el defecto).
     *
     * Y el paso que se olvida: `grupos.titular_id` apunta a `profesores.id`,
     * **no a `users.id`**, así que hay que pasar por `profesores.user_id`.
     */
    public static function conduce($mesa_id, $user_id, bool $titulares_conducen): bool
    {
        $propia = DB::selectOne('SELECT id FROM vt_mesa_usuarios WHERE mesa_id = ? AND user_id = ? LIMIT 1',
            [$mesa_id, $user_id]);

        if ($propia) {
            return true;
        }

        if (! $titulares_conducen) {
            return false;
        }

        $titular = DB::selectOne('SELECT mg.id
            FROM vt_mesa_grupos mg
            INNER JOIN grupos g ON g.id = mg.grupo_id AND g.deleted_at IS NULL
            INNER JOIN profesores p ON p.id = g.titular_id AND p.deleted_at IS NULL
            WHERE mg.mesa_id = ? AND p.user_id = ? LIMIT 1', [$mesa_id, $user_id]);

        return $titular !== null;
    }

    /** Si este grupo pasa por esta mesa. */
    public static function atiendeAlGrupo($mesa_id, $grupo_id): bool
    {
        if (! $grupo_id) {
            return false;
        }

        $fila = DB::selectOne('SELECT id FROM vt_mesa_grupos WHERE mesa_id = ? AND grupo_id = ? LIMIT 1',
            [$mesa_id, $grupo_id]);

        return $fila !== null;
    }

    /**
     * Los grupos del año de la elección de los que este usuario es titular.
     *
     * Es lo que convierte `titulares_conducen` en mesas de verdad: sin una sola
     * fila de `vt_mesas`, un docente ve su grupo en `mesas/mias`.
     *
     * @return array<int, object>
     */
    public static function gruposDondeEsTitular($user_id, $year_id)
    {
        if (! $year_id) {
            return [];
        }

        return DB::select('SELECT g.id, g.nombre, g.abrev, g.orden
            FROM grupos g
            INNER JOIN profesores p ON p.id = g.titular_id AND p.deleted_at IS NULL
            WHERE g.year_id = ? AND g.deleted_at IS NULL AND p.user_id = ?
            ORDER BY g.orden, g.nombre', [$year_id, $user_id]);
    }
}
