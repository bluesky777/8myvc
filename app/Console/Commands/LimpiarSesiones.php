<?php

namespace App\Console\Commands;

use App\Models\TokenDeSesion;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Borra de `personal_access_tokens` lo que ya no vale.
 *
 * En el caso normal la tabla no crece: al abrir sesión se tiran los tokens
 * caducados de ese usuario. Lo que esto recoge es lo de quien no vuelve a
 * entrar — el alumno que se gradúa, el profesor que se va — y las filas de las
 * rotaciones de refresco.
 *
 * No se programa en el scheduler porque en estos alojamientos no hay garantía
 * de que corra el cron de Laravel. Si en un colegio se puede, la línea está en
 * docs/DESPLIEGUE-REFERENCIA.md; y si no, correrlo a mano de vez en cuando basta.
 */
class LimpiarSesiones extends Command
{
    protected $signature = 'sesion:limpiar
                            {--dias=7 : Cuántos días se conservan los tokens ya caducados}';

    protected $description = 'Borra los tokens de sesión caducados hace más de N días';

    public function handle(): int
    {
        $dias = max(0, (int) $this->option('dias'));

        $corte = Carbon::now()->subDays($dias);

        // No se borra en cuanto caduca: una fila caducada y con
        // `reemplazado_por` puesto es lo que permite distinguir un refresco
        // reutilizado de uno que nunca existió. Borrarla el mismo día deja esa
        // señal en nada.
        $borrados = TokenDeSesion::query()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', $corte)
            ->delete();

        $this->info("Borrados {$borrados} tokens caducados antes de {$corte->toDateTimeString()}.");

        return 0;
    }
}
