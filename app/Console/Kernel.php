<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * The Artisan commands provided by your application.
     *
     * @var array
     */
    protected $commands = [
        //
    ];

    /**
     * Lo que corre solo, si el colegio tiene puesto el cron.
     *
     * **Un solo cron por colegio**, el de `schedule:run` cada minuto, y todo lo
     * demás se decide aquí. Es la diferencia entre añadir una tarea nueva
     * escribiendo una línea en este fichero, o entrar a dieciséis paneles de
     * cPanel a crear un cron cada vez.
     *
     * La línea exacta, con las dos trampas que tiene en cPanel —la ruta del PHP
     * 8.4 y el correo por cada ejecución—, está en docs/DESPLIEGUE.md.
     *
     * Si un colegio no tiene el cron puesto, no pasa nada: hoy aquí solo hay
     * limpieza, y `sesion:limpiar` se puede correr a mano de vez en cuando.
     */
    protected function schedule(Schedule $schedule)
    {
        // Los tokens de sesión de quien no vuelve a entrar — el alumno que se
        // gradúa, el profesor que se va. En el caso normal la tabla no crece:
        // al abrir sesión ya se tiran los caducados de ese usuario. Por eso
        // basta una vez por semana y no hace falta que sea de madrugada exacta.
        $schedule->command('sesion:limpiar')
            ->weeklyOn(0, '03:15')
            ->withoutOverlapping();

        // Los avisos push a las familias. **Cada quince minutos y no más
        // seguido**: agrupar es lo que hace que un docente pasando una columna
        // de treinta notas genere UN aviso y no treinta, y bajar la frecuencia
        // rompe justamente eso — con un minuto entre pasadas, la misma columna
        // se parte en varios avisos y el acudiente apaga las notificaciones.
        //
        // `withoutOverlapping` porque la marca de por dónde iba se guarda
        // DESPUÉS de publicar: dos pasadas solapadas leerían la misma marca y
        // mandarían el aviso dos veces.
        //
        // En el colegio que no tenga credenciales de Firebase esto no hace nada
        // y lo dice — es lo que va a pasar en los dieciséis hasta que se pongan.
        // Y no hace falta un cron nuevo: el de `schedule:run` ya está, uno por
        // colegio, y esa decisión es la que hace que añadir esto sean tres
        // líneas aquí en vez de dieciséis visitas a paneles de cPanel.
        $schedule->command('notificaciones:enviar')
            ->everyFifteenMinutes()
            ->withoutOverlapping();
    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
