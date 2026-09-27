<?php

namespace App\Services\Portal;

use App\Support\Reloj;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Firma y manda al portal (§3.1).
 *
 * ```
 * X-Portal-Colegio: {codigo_dane}
 * X-Portal-Fecha:   {ISO-8601 con offset}
 * X-Portal-Version: {version_emisor}
 * X-Portal-Firma:   hex(HMAC-SHA256(PORTAL_CLAVE, canonico))
 *
 * canonico = codigo_dane "\n" fecha "\n" version "\n" sha256(cuerpo_bytes_exactos)
 * ```
 *
 * **La clave es la cadena de `PORTAL_CLAVE` tal cual** (el hex que entrega el
 * portal, sin `hex2bin`): así la usa el receptor.
 *
 * Se firman **los bytes que se mandan**, no el JSON reserializado. Un GET firma el
 * sha256 del cuerpo vacío. La fecha va dentro de lo firmado —el portal rechaza
 * más de ~15 min de desviación— y por eso **se vuelve a firmar en cada intento**.
 */
final class ClienteDelPortal
{
    /** El receptor rechaza con 413 lo que pase de aquí; se comprueba antes de mandar. */
    public const TOPE_BYTES = 256 * 1024;

    public function __construct(
        private readonly string $url,
        private readonly string $clave,
        private readonly string $codigoDane,
    ) {}

    /** `null` si el `.env` de este colegio no tiene las dos (§3.0: no es un error). */
    public static function desdeConfig(string $codigoDane): ?self
    {
        $url = trim((string) config('portal.url'));
        $clave = (string) config('portal.clave');
        if ($url === '' || $clave === '') {
            return null;
        }

        return new self(rtrim($url, '/'), $clave, $codigoDane);
    }

    public static function configurado(): bool
    {
        return trim((string) config('portal.url')) !== '' && (string) config('portal.clave') !== '';
    }

    /** @return array<string, string> */
    public static function cabeceras(string $clave, string $codigoDane, string $fecha, int $version, string $cuerpo): array
    {
        $canonico = $codigoDane."\n".$fecha."\n".$version."\n".hash('sha256', $cuerpo);

        return [
            'X-Portal-Colegio' => $codigoDane,
            'X-Portal-Fecha' => $fecha,
            'X-Portal-Version' => (string) $version,
            'X-Portal-Firma' => hash_hmac('sha256', $canonico, $clave),
        ];
    }

    /**
     * POST del cuerpo, con tres intentos y espera creciente (§3.2). Reintenta sólo
     * red caída y 5xx, que es lo que el receptor dice que se reintenta. Un 4xx
     * (400/401/403/413/422, con `{resultado, motivo, envio_id}`) es una firma o un
     * cuerpo rechazados, y repetirlo da lo mismo. Éxito: 201 aceptado, 200
     * reemplazado (la misma noche otra vez).
     *
     * @param  callable(string):void  $avisar
     */
    public function enviar(string $cuerpo, callable $avisar): ?Response
    {
        /** @var list<int> $esperas */
        $esperas = array_values((array) config('portal.esperas', [15, 60]));
        $intentos = count($esperas) + 1;

        for ($i = 1; $i <= $intentos; $i++) {
            try {
                $respuesta = $this->peticion($cuerpo)
                    ->withBody($cuerpo, 'application/json')
                    ->post($this->url.'/api/envios');

                if ($respuesta->successful() || $respuesta->clientError()) {
                    return $respuesta;
                }
                $avisar("intento {$i}/{$intentos}: HTTP {$respuesta->status()}");
            } catch (ConnectionException $e) {
                $respuesta = null;
                $avisar("intento {$i}/{$intentos}: sin conexión ({$e->getMessage()})");
            }

            if ($i < $intentos && $esperas[$i - 1] > 0) {
                sleep($esperas[$i - 1]);
            }
        }

        return $respuesta;
    }

    /** GET firmado de `/api/envios/mios?anio=` (§4.2). Un solo intento: quien lo pide para en el primer fallo. */
    public function mios(int $anio): Response
    {
        return $this->peticion('')->get($this->url.'/api/envios/mios', ['anio' => $anio]);
    }

    private function peticion(string $cuerpo): PendingRequest
    {
        $fecha = Reloj::ahora()->setTimezone(Reloj::ZONA)->format('c');

        return Http::timeout((int) config('portal.timeout', 30))
            ->acceptJson()
            ->withHeaders(self::cabeceras($this->clave, $this->codigoDane, $fecha, CuerpoDelPortal::VERSION, $cuerpo));
    }
}
