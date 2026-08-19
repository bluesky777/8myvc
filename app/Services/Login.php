<?php

namespace App\Services;

use App\User;
use Browser;
use Carbon\Carbon;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * El trámite de entrar: comprobar la contraseña y dejar constancia.
 *
 * Lo que NO hace: emitir tokens. Eso es App\Services\Sesion. Están separados
 * porque hay dos maneras de entrar y solo cambia el token que devuelven:
 * `POST /api/auth/login` da el par acceso+refresco, y `POST login/credentials`
 * —la ruta vieja, que se mantiene para que un front sin actualizar siga
 * funcionando— da un solo token largo. Todo lo demás es idéntico, y estaba
 * escrito una sola vez en LoginController; se saca aquí para que siga
 * estándolo.
 *
 * Los errores salen como HttpResponseException con la respuesta exacta de
 * siempre. No se usa `abort()` porque el cuerpo que genera —{"message": ...}—
 * no es el que el frontend lee: espera {"error": "invalid_credentials"}.
 */
class Login
{
    private $entorno = 'Desktop';

    private $direccion = '';

    /**
     * Comprueba las credenciales, apunta la entrada en `historiales` y pone al
     * usuario en el periodo actual si se había quedado en otro año.
     *
     * @return array{usuario: User, cambia_anio: int|null}
     */
    public function entrar(Request $peticion): array
    {
        $username = (string) $peticion->input('username');
        $clave = (string) $peticion->input('password');
        $ahora = Carbon::now('America/Bogota');

        $this->datosDelEntorno();

        // El limitador global era de 60/min para toda la API, o sea 86.400 intentos
        // de contraseña al día por IP. Este es específico del par IP+usuario, para
        // que un atacante no pueda probar contra muchas cuentas desde una IP ni
        // contra una cuenta desde muchas.
        $claveLimite = 'login:'.sha1($this->direccion.'|'.$username);

        if (RateLimiter::tooManyAttempts($claveLimite, 5)) {
            throw new HttpResponseException(response()->json([
                'error' => 'too_many_attempts',
                'segundos' => RateLimiter::availableIn($claveLimite),
            ], 429));
        }

        $fila = $this->filaDeUsuario($username);

        if ($fila === null || ! Hash::check($clave, $fila->password)) {
            RateLimiter::hit($claveLimite, 900);

            $this->anotarIntentoFallido($username, $ahora);

            throw new HttpResponseException(response()->json(['error' => 'invalid_credentials'], 400));
        }

        RateLimiter::clear($claveLimite);

        if (! $fila->is_active) {
            abort(400, 'Usuario invalidado');
        }

        $this->anotarEntrada($fila, $ahora);

        $usuario = User::find($fila->id);

        if ($usuario === null) {
            throw new HttpResponseException(response()->json(['error' => 'invalid_credentials'], 400));
        }

        return [
            'usuario' => $usuario,
            'cambia_anio' => $this->ponerEnElPeriodoActual($fila),
        ];
    }

    /**
     * El usuario por nombre, ya filtrando los borrados.
     *
     * Antes esto eran dos pasos: `auth()->attempt()` primero y una consulta
     * después. `attempt()` no filtra `deleted_at` —App\User no usa SoftDeletes—
     * así que un usuario borrado pasaba la contraseña, y la consulta de después
     * no devolvía filas: el `[0]` que le seguía reventaba con "Undefined array
     * key 0" y el login contestaba **500**. Ahora contesta 400
     * invalid_credentials, que es lo que es.
     */
    private function filaDeUsuario(string $username): ?object
    {
        $filas = DB::select(
            'SELECT u.id, u.tipo, u.password, u.periodo_id, p.year_id, u.is_active
             FROM users u
             LEFT JOIN periodos p ON p.id = u.periodo_id AND p.deleted_at IS NULL
             WHERE u.username = ? AND u.deleted_at IS NULL',
            [$username]
        );

        return $filas[0] ?? null;
    }

    private function anotarIntentoFallido(string $username, Carbon $ahora): void
    {
        $maquina = 'Intento login>> Entorno: '.$this->entorno.', Dirección: '.$this->direccion
            .', plataforma: '.Browser::browserEngine()
            .', platfamilia: '.Browser::platformFamily()
            .', device_fami: '.Browser::deviceFamily()
            .', device_model: '.Browser::deviceModel();

        DB::insert(
            'INSERT INTO bitacoras (descripcion, affected_person_name, affected_element_type, created_at, created_by)
             VALUES (?, ?, "intento_login", ?, 0)',
            [$maquina, $username, $ahora]
        );
    }

    private function anotarEntrada(object $fila, Carbon $ahora): void
    {
        DB::insert(
            'INSERT INTO historiales(user_id, tipo, ip, browser_name, browser_version, browser_family, browser_engine, entorno, platform_name, platform_family, device_family, device_model, device_grade, updated_at, created_at)
             VALUES(:user_id, :tipo, :ip, :browser_name, :browser_version, :browser_family, :browser_engine, :entorno, :platform_name, :platform_family, :device_family, :device_model, :device_grade, :updated_at, :created_at)',
            [
                ':user_id' => $fila->id,
                ':tipo' => $fila->tipo,
                ':ip' => $this->direccion,
                ':browser_name' => Browser::browserName(),
                ':browser_version' => Browser::browserVersion(),
                ':browser_family' => Browser::browserFamily(),
                ':browser_engine' => Browser::browserEngine(),
                ':entorno' => $this->entorno,
                ':platform_name' => Browser::browserEngine(),
                ':platform_family' => Browser::platformFamily(),
                ':device_family' => Browser::deviceFamily(),
                ':device_model' => Browser::deviceModel(),
                ':device_grade' => Browser::mobileGrade(),
                ':updated_at' => $ahora,
                ':created_at' => $ahora,
            ]
        );
    }

    /**
     * Si el usuario se quedó en el periodo de otro año, se le pasa al actual.
     *
     * Devuelve el id del periodo nuevo cuando ha cambiado —es el `cambia_anio`
     * de la respuesta, que el frontend usa para recargar la configuración del
     * colegio— y null cuando no había nada que cambiar.
     */
    private function ponerEnElPeriodoActual(object $fila): ?int
    {
        $anios = DB::select('SELECT id, year, actual FROM years WHERE actual=1 and deleted_at is null');

        if ($anios === []) {
            return null;
        }

        $anio = $anios[0];

        $periodos = DB::select(
            'SELECT id, actual FROM periodos WHERE actual=1 and year_id=? and deleted_at is null',
            [$anio->id]
        );

        if (! ($fila->periodo_id > 0) || count($periodos) === 0) {
            return null;
        }

        $periodo = $periodos[0];

        if ($anio->id != $fila->year_id || $periodo->id != $fila->periodo_id) {
            DB::update('UPDATE users SET periodo_id=? WHERE id=?', [$periodo->id, $fila->id]);

            return (int) $periodo->id;
        }

        return null;
    }

    private function datosDelEntorno(): void
    {
        if (Browser::isMobile()) {
            $this->entorno = 'Mobile';
        } elseif (Browser::isTablet()) {
            $this->entorno = 'Tablet';
        } elseif (Browser::isBot()) {
            $this->entorno = 'Bot';
        }

        if (! empty($_SERVER['HTTP_CLIENT_IP'])) {
            $this->direccion = $_SERVER['HTTP_CLIENT_IP'];
        }
        if (! empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $this->direccion = $_SERVER['HTTP_X_FORWARDED_FOR'];
        }
        if (! empty($_SERVER['REMOTE_ADDR'])) {
            $this->direccion = $_SERVER['REMOTE_ADDR'];
        }
    }
}
