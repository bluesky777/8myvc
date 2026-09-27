<?php

namespace App\Console\Commands;

use App\Services\Portal\ClienteDelPortal;
use App\Services\Portal\CuerpoDelPortal;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * La carga inicial de los años pasados: UNO por ejecución, del más viejo al más
 * nuevo (§4.2).
 *
 * **Sin estado local**: antes de mandar un año le pregunta al portal
 * `GET /api/envios/mios?anio=…`, firmado con la misma clave, y si ya lo tiene lo
 * salta. La única fuente de verdad sobre qué llegó es quien lo recibió; una marca
 * aquí diría «mandado» de un envío perdido en la red.
 *
 * Del viejo al nuevo y no al revés: si se corta a la mitad queda una historia
 * continua, y un gráfico con agujeros se lee como una caída.
 *
 * El año actual NO entra: ése lo manda `portal:enviar` cada noche como foto
 * diaria. Aquí sólo van los anteriores, cada uno como una foto retroactiva (§4.3).
 *
 * La respuesta del GET, tal como la da el receptor:
 * `200 {"codigo_dane": "…", "anios": [{"anio", "fotos", "ultima_fecha_corte", "es_retroactivo"}]}`.
 * El año está si aparece en `anios` con alguna foto. Cualquier otra cosa —otro
 * código, JSON sin `anios`— para el recorrido con error, en vez de adivinar.
 */
class PortalRespaldoInicial extends Command
{
    protected $signature = 'portal:respaldo-inicial';

    protected $description = 'Manda al portal el año pasado más viejo que aún no tenga (uno por ejecución)';

    public function handle(CuerpoDelPortal $armador): int
    {
        if (! ClienteDelPortal::configurado()) {
            $this->info('Sin PORTAL_URL / PORTAL_CLAVE en este .env: no se manda nada (no es un error).');

            return self::SUCCESS;
        }

        $dane = CuerpoDelPortal::codigoDane();
        if ($dane === null) {
            $this->error('`years.codigo_dane` está vacío: el portal no sabría de quién es el envío.');

            return self::FAILURE;
        }
        $cliente = ClienteDelPortal::desdeConfig((string) $dane);
        if ($cliente === null) {
            return self::SUCCESS;
        }

        $anios = DB::select('SELECT DISTINCT year FROM years
            WHERE deleted_at IS NULL
              AND year < (SELECT COALESCE(MAX(y2.year), 9999) FROM years y2 WHERE y2.actual = 1 AND y2.deleted_at IS NULL)
            ORDER BY year');

        foreach ($anios as $fila) {
            $anio = (int) $fila->year;
            $respuesta = $cliente->mios($anio);
            $lista = $respuesta->successful() ? $respuesta->json('anios') : null;

            if (! is_array($lista)) {
                $this->error("No se pudo saber si el portal tiene {$anio}: HTTP {$respuesta->status()}. Se para aquí.");

                return self::FAILURE;
            }
            $recibido = false;
            foreach ($lista as $a) {
                if (is_array($a) && (int) ($a['anio'] ?? 0) === $anio && (int) ($a['fotos'] ?? 0) > 0) {
                    $recibido = true;
                }
            }
            if ($recibido) {
                $this->line("{$anio}: ya está en el portal.");

                continue;
            }

            $json = CuerpoDelPortal::json($armador->armar($anio, retroactivo: true));

            // Uno por ejecución y termina, se mande bien o no (§4.2).
            return PortalEnviar::mandar($this, $json) ? self::SUCCESS : self::FAILURE;
        }

        $this->info('Todos los años pasados están ya en el portal.');

        return self::SUCCESS;
    }
}
