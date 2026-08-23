<?php

namespace App\Services\Notificaciones;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Publica en un tema de Firebase Cloud Messaging, **sin el SDK de Google**.
 *
 * La API HTTP v1 pide un token de OAuth firmado con la cuenta de servicio. Eso
 * son dos cosas —firmar un JWT y canjearlo— y las dos se hacen con lo que ya hay
 * en el proyecto: `openssl_sign` de PHP y Guzzle, que está en el
 * `composer.json` desde siempre. Añadir `google/apiclient` sería una dependencia
 * más que mantener **en dieciséis copias y con `vendor/` compartido por
 * symlink**, que es donde este proyecto paga caro cada dependencia nueva.
 *
 * ## Lo comprobado antes de escribir esto (24 ago 2026)
 *
 * El hosting **deja salir por HTTPS** a `oauth2.googleapis.com` y a
 * `fcm.googleapis.com` —los dos contestan—, ejecuta artisan (Laravel 13.26.1,
 * PHP 8.4.24) y el cron dispara. O sea que esto es viable y no hace falta el
 * plan B del plan. Detalle en `myvc_flutter/docs/notificaciones.md`.
 *
 * ## El token se cachea la hora que dura
 *
 * Un token de OAuth vale 3.600 segundos. Pedir uno por cada aviso serían dos
 * viajes a Google en vez de uno, y el comando corre cada quince minutos: sin
 * caché son 96 canjes al día por colegio para nada. Se guarda 55 minutos, con
 * cinco de margen para no usar uno que caduque en pleno vuelo.
 */
class EnvioFcm implements Publicador
{
    private const CLAVE_TOKEN = 'notificaciones.fcm.token';

    private const ALCANCE = 'https://www.googleapis.com/auth/firebase.messaging';

    public function __construct(private ?Client $http = null) {}

    public function estaConfigurado(): bool
    {
        $proyecto = config('notificaciones.fcm.proyecto');
        $ruta = config('notificaciones.fcm.credenciales');

        return ! empty($proyecto) && ! empty($ruta) && is_readable((string) $ruta);
    }

    /**
     * @param  array<string, string>  $datos
     */
    public function publicar(string $tema, string $titulo, string $cuerpo, array $datos = []): bool
    {
        if (! $this->estaConfigurado()) {
            return false;
        }

        $token = $this->token();

        if ($token === null) {
            return false;
        }

        $proyecto = (string) config('notificaciones.fcm.proyecto');

        try {
            $this->http()->post(
                'https://fcm.googleapis.com/v1/projects/'.$proyecto.'/messages:send',
                [
                    'headers' => ['Authorization' => 'Bearer '.$token],
                    'json' => [
                        'message' => [
                            'topic' => $tema,
                            'notification' => ['title' => $titulo, 'body' => $cuerpo],
                            'data' => $datos,
                        ],
                    ],
                    'timeout' => 15,
                ]
            );

            return true;
        } catch (\Throwable $e) {
            // **Se registra y se sigue, no se lanza.** Un tema que falla no puede
            // llevarse por delante los otros quince avisos de la misma pasada, y
            // el comando lo corre un cron que no lee nadie: una excepción aquí es
            // un aviso perdido y ninguna señal.
            Log::warning('FCM no pudo publicar en '.$tema.': '.$e->getMessage());

            return false;
        }
    }

    /**
     * El token de OAuth, del caché o recién canjeado.
     */
    private function token(): ?string
    {
        $guardado = Cache::get(self::CLAVE_TOKEN);

        if (is_string($guardado) && $guardado !== '') {
            return $guardado;
        }

        $credenciales = $this->credenciales();

        if ($credenciales === null) {
            return null;
        }

        $jwt = $this->jwtFirmado($credenciales);

        if ($jwt === null) {
            return null;
        }

        try {
            $respuesta = $this->http()->post('https://oauth2.googleapis.com/token', [
                'form_params' => [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion' => $jwt,
                ],
                'timeout' => 15,
            ]);

            $cuerpo = json_decode((string) $respuesta->getBody(), true);
            $token = is_array($cuerpo) ? ($cuerpo['access_token'] ?? null) : null;

            if (! is_string($token) || $token === '') {
                return null;
            }

            // 55 y no 60: cinco minutos de margen para no salir con uno que
            // caduque mientras se está usando.
            Cache::put(self::CLAVE_TOKEN, $token, now()->addMinutes(55));

            return $token;
        } catch (\Throwable $e) {
            Log::warning('FCM no pudo conseguir el token de OAuth: '.$e->getMessage());

            return null;
        }
    }

    /**
     * El JWT que se canjea por el token, firmado con la clave privada de la
     * cuenta de servicio.
     *
     * `RS256` es lo que pide Google, y `openssl_sign` con `OPENSSL_ALGO_SHA256`
     * es exactamente eso. El `base64url` no es el `base64` de PHP: cambia dos
     * caracteres y quita el relleno, y equivocarse ahí da un token que Google
     * rechaza con un mensaje que no lo dice.
     *
     * @param  array<string, mixed>  $credenciales
     */
    private function jwtFirmado(array $credenciales): ?string
    {
        $correo = $credenciales['client_email'] ?? null;
        $clave = $credenciales['private_key'] ?? null;

        if (! is_string($correo) || ! is_string($clave)) {
            Log::warning('El JSON de Firebase no trae client_email o private_key.');

            return null;
        }

        $ahora = time();

        $cabecera = $this->base64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $carga = $this->base64url(json_encode([
            'iss' => $correo,
            'scope' => self::ALCANCE,
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => $ahora,
            'exp' => $ahora + 3600,
        ]));

        $firma = '';

        if (! openssl_sign($cabecera.'.'.$carga, $firma, $clave, OPENSSL_ALGO_SHA256)) {
            Log::warning('No se pudo firmar el JWT de Firebase: la clave privada no sirve.');

            return null;
        }

        return $cabecera.'.'.$carga.'.'.$this->base64url($firma);
    }

    /** @return array<string, mixed>|null */
    private function credenciales(): ?array
    {
        $ruta = (string) config('notificaciones.fcm.credenciales');

        if (! is_readable($ruta)) {
            return null;
        }

        $json = json_decode((string) file_get_contents($ruta), true);

        return is_array($json) ? $json : null;
    }

    private function base64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private function http(): Client
    {
        return $this->http ??= new Client;
    }
}
