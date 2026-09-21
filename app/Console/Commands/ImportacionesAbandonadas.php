<?php

namespace App\Console\Commands;

use App\Services\PuntoDeControlDeImportacion;
use Illuminate\Console\Command;

/**
 * Cierra lo que se quedó `en_proceso` sin que nadie vuelva a subirlo.
 *
 * No reanuda la importación — el porqué está en el docblock de
 * `PuntoDeControlDeImportacion::marcarAbandonadas()` — sólo evita que una fila
 * mienta para siempre diciendo que algo la sigue trabajando. Decisión de
 * Joseth (21 sep 2026): guardar el archivo en disco para que el cron reanude
 * de verdad se descartó por el coste — persistir datos de alumnos en los
 * dieciséis cPanel y el mismo tope de tiempo dentro del propio cron — así que
 * esto se queda en avisar, y sigue siendo una persona quien vuelve a subir el
 * archivo.
 */
class ImportacionesAbandonadas extends Command
{
    protected $signature = 'importaciones:marcar-abandonadas
                            {--minutos=10 : Minutos sin actividad antes de darla por abandonada}';

    protected $description = 'Marca fallida la importación en_proceso que lleva N minutos sin escribir un lote';

    public function handle(): int
    {
        $minutos = max(1, (int) $this->option('minutos'));

        $marcadas = PuntoDeControlDeImportacion::marcarAbandonadas($minutos);

        $this->info("Marcadas {$marcadas} importaciones abandonadas (sin actividad > {$minutos} min).");

        return 0;
    }
}
