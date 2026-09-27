<?php

namespace App\Console\Commands;

use App\Services\Portal\ClienteDelPortal;
use App\Services\Portal\CuerpoDelPortal;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Manda al portal de la Unión el resumen de un año de este colegio.
 *
 * Diseño: `myvc_ucn/docs/01-diseno-tecnico.md` §2–§4. Lo que hay que saber:
 *
 * - **Sin `PORTAL_URL` y `PORTAL_CLAVE` no es un error** (§3.0): lo dice y sale
 *   con 0. Es el caso de cada colegio hasta que se le reparta su clave.
 * - **No lleva marca local** (§3.2): la idempotencia vive en el receptor, que
 *   reemplaza la foto de la misma `(colegio, año, fecha_corte)`. Repetir es gratis;
 *   por eso reintenta sin coordinar nada.
 * - `--anio` manda ese año y termina (la carga inicial, §4.2); sin él, el año
 *   actual de `years`.
 */
class PortalEnviar extends Command
{
    protected $signature = 'portal:enviar
                            {--anio= : El año a mandar; por defecto el actual de `years`}
                            {--seco : Imprime el cuerpo y no manda nada}
                            {--retroactivo : Marca el envío como carga inicial (§4.3)}';

    protected $description = 'Manda al portal de la Unión el resumen agregado de un año de este colegio';

    public function handle(CuerpoDelPortal $armador): int
    {
        $seco = (bool) $this->option('seco');

        // Antes de consultar nada: en un colegio sin clave esto corre cada noche
        // y no tiene por qué tocar la base.
        if (! $seco && ! ClienteDelPortal::configurado()) {
            $this->info('Sin PORTAL_URL / PORTAL_CLAVE en este .env: no se manda nada (no es un error).');

            return self::SUCCESS;
        }

        $anio = $this->anio();
        if ($anio === null) {
            $this->error('No hay año actual en `years` y no se pasó --anio.');

            return self::FAILURE;
        }

        $json = CuerpoDelPortal::json($armador->armar($anio, (bool) $this->option('retroactivo')));

        if ($seco) {
            $this->line($json);

            return self::SUCCESS;
        }

        return self::mandar($this, $json) ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Firma y manda un cuerpo ya armado. Lo comparte `portal:respaldo-inicial`.
     */
    public static function mandar(Command $comando, string $json): bool
    {
        /** @var array{sobre: array{codigo_dane: string|null, anio: int}} $cuerpo */
        $cuerpo = json_decode($json, true);
        $dane = $cuerpo['sobre']['codigo_dane'];
        if ($dane === null || trim($dane) === '') {
            // Éste SÍ es un fallo: con clave puesta y sin código DANE el portal no
            // puede saber de quién es, y hay que arreglarlo en `years`.
            $comando->error('`years.codigo_dane` está vacío: el portal no sabría de quién es el envío.');

            return false;
        }

        if (strlen($json) > ClienteDelPortal::TOPE_BYTES) {
            $comando->error('El cuerpo pesa '.strlen($json).' bytes y el portal no acepta más de '.ClienteDelPortal::TOPE_BYTES.'.');

            return false;
        }

        $cliente = ClienteDelPortal::desdeConfig($dane);
        if ($cliente === null) {
            $comando->info('Sin PORTAL_URL / PORTAL_CLAVE en este .env: no se manda nada (no es un error).');

            return true;
        }

        $respuesta = $cliente->enviar($json, fn (string $m) => $comando->warn($m));

        if ($respuesta !== null && $respuesta->successful()) {
            $comando->info("Enviado {$cuerpo['sobre']['anio']}: HTTP {$respuesta->status()}.");

            return true;
        }

        $comando->error('No se pudo enviar '.$cuerpo['sobre']['anio'].': '
            .($respuesta === null ? 'sin conexión' : 'HTTP '.$respuesta->status().' '.mb_substr($respuesta->body(), 0, 300)));

        return false;
    }

    private function anio(): ?int
    {
        $opcion = $this->option('anio');
        if ($opcion !== null && $opcion !== '') {
            return (int) $opcion;
        }

        // Si hay dos actuales (`anios:actuales` avisa de eso), el más reciente.
        $fila = DB::selectOne('SELECT MAX(year) AS year FROM years WHERE actual = 1 AND deleted_at IS NULL');

        return $fila?->year === null ? null : (int) $fila->year;
    }
}
