<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Quién no puede recuperar su contraseña, y quién se la puede resetear a otro.
 *
 * Son dos preguntas y el comando las contesta por separado a propósito, porque
 * en la copia de desarrollo la primera es enorme y la segunda es grave, y
 * mezclarlas hace que la grande tape a la grave.
 *
 * **Resetearse entre sí.** `putResetPassword` ata la contraseña nueva al correo
 * del token, pero el `username` llega en el cuerpo de la petición y
 * `password_reminders` no guarda a quién se le emitió el token — la tabla solo
 * tiene `email`, `token` y `created_at`—. O sea que un enlace de reseteo abre
 * **cualquier cuenta que comparta ese correo**: la protección existe y llega
 * exactamente hasta el borde del grupo. Lo demuestra
 * `tests/Contrato/ResetCorreoCompartidoTest.php`.
 *
 * **No poder recuperar.** Sin correo, o con un correo que no es una dirección,
 * `postRecuperarClave` aborta con 422 antes de mirar la base. Esas cuentas no
 * corren el riesgo de arriba —no se les puede emitir un enlace— pero dependen
 * de que un superusuario les ponga la contraseña a mano.
 *
 * Los grupos de riesgo se ordenan por lo que cuesta que pase, no por tamaño:
 *
 *   1. grupos con un **superusuario** dentro — cualquiera del grupo toma el colegio;
 *   2. grupos que **cruzan tipos** (un alumno y un profesor, por ejemplo);
 *   3. el resto — normalmente hermanos con el correo de un padre.
 *
 * Cuántas cuentas hay en cada grupo depende de los datos de cada colegio y no se
 * puede saber desde aquí. De ahí el comando.
 *
 * Uso, en cada colegio:
 *
 *     php artisan usuarios:correos-compartidos
 *     php artisan usuarios:correos-compartidos --todos
 *
 * No escribe nada. Sale con código 1 si hay algo que mirar, para que se note en
 * un bucle sobre los dieciséis.
 */
class CorreosCompartidos extends Command
{
    protected $signature = 'usuarios:correos-compartidos {--todos : Listar también los grupos sin superusuario ni cruce de tipos}';

    protected $description = 'Dice quién no puede recuperar su contraseña y qué cuentas se la pueden resetear entre sí';

    public function handle(): int
    {
        $base = DB::connection()->getDatabaseName();

        $activos = (int) DB::selectOne('SELECT COUNT(*) n FROM users
            WHERE deleted_at IS NULL AND is_active = 1')->n;

        $sinCorreo = (int) DB::selectOne('SELECT COUNT(*) n FROM users
            WHERE deleted_at IS NULL AND is_active = 1
              AND (email IS NULL OR email = "")')->n;

        $porCorreo = DB::select('SELECT email,
                                        COUNT(*) AS cuentas,
                                        SUM(is_superuser = 1) AS superusuarios,
                                        COUNT(DISTINCT tipo) AS tipos,
                                        GROUP_CONCAT(DISTINCT tipo ORDER BY tipo) AS cuales,
                                        GROUP_CONCAT(username ORDER BY id SEPARATOR ", ") AS quienes
                                 FROM users
                                 WHERE deleted_at IS NULL AND is_active = 1
                                   AND email IS NOT NULL AND email != ""
                                 GROUP BY email
                                 ORDER BY superusuarios DESC, tipos DESC, cuentas DESC');

        // La validez se comprueba en PHP y con la MISMA función que usa el
        // endpoint. Hacerlo con un LIKE en SQL daría otro criterio, y entonces
        // el diagnóstico y el código dirían cosas distintas sobre el mismo dato.
        $esDireccion = fn (object $g): bool => filter_var((string) $g->email, FILTER_VALIDATE_EMAIL) !== false;

        $noSonDireccion = array_values(array_filter($porCorreo, fn ($g) => ! $esDireccion($g)));
        $compartidos = array_values(array_filter($porCorreo,
            fn ($g) => $esDireccion($g) && (int) $g->cuentas > 1));

        $cuentasSinDireccion = array_sum(array_map(fn ($g) => (int) $g->cuentas, $noSonDireccion));
        $enJuego = array_sum(array_map(fn ($g) => (int) $g->cuentas, $compartidos));
        $noRecuperan = $sinCorreo + $cuentasSinDireccion;

        $this->line('');
        $this->line('  base ............................ '.$base);
        $this->line('  cuentas activas ................. '.$activos);
        $this->line('');
        $this->line('  NO PUEDEN RECUPERAR CONTRASEÑA .. '.$noRecuperan
            .($activos > 0 ? sprintf('  (%d%%)', (int) round(100 * $noRecuperan / $activos)) : ''));
        $this->line('     sin correo ................... '.$sinCorreo);
        $this->line('     el correo no es una dirección  '.$cuentasSinDireccion);

        foreach (array_slice($noSonDireccion, 0, 5) as $g) {
            $this->line(sprintf('        %5dx  %s', $g->cuentas, mb_substr((string) $g->email, 0, 40)));
        }

        if (count($noSonDireccion) > 5) {
            $this->line('        ... y '.(count($noSonDireccion) - 5).' formas más.');
        }

        $this->line('');
        $this->line('  SE RESETEAN ENTRE SÍ ............ '.$enJuego.' cuentas en '.count($compartidos).' grupos');
        $this->line('');

        if ($compartidos !== []) {
            $conSuper = array_values(array_filter($compartidos, fn ($g) => (int) $g->superusuarios > 0));
            $cruzados = array_values(array_filter($compartidos,
                fn ($g) => (int) $g->superusuarios === 0 && (int) $g->tipos > 1));
            $resto = array_values(array_filter($compartidos,
                fn ($g) => (int) $g->superusuarios === 0 && (int) $g->tipos === 1));

            if ($conSuper !== []) {
                $this->error('  CON SUPERUSUARIO DENTRO — cualquiera del grupo toma el colegio:');
                $this->pintar($conSuper);
            }

            if ($cruzados !== []) {
                $this->warn('  CRUZAN TIPOS — una cuenta de alumno o acudiente alcanza a una del personal:');
                $this->pintar($cruzados);
            }

            if ($resto !== []) {
                $this->line('  El resto ('.count($resto).' grupos, normalmente hermanos con el correo de un padre):');
                $this->pintar($this->option('todos') ? $resto : array_slice($resto, 0, 5));

                if (! $this->option('todos') && count($resto) > 5) {
                    $this->line('    ... y '.(count($resto) - 5).' más. Con --todos salen todos.');
                }
            }

            $this->line('  El arreglo de fondo es que `password_reminders` guarde a quién se le');
            $this->line('  emitió el token, para que `putResetPassword` deje de creerse el');
            $this->line('  `username` del cuerpo. Mientras tanto, lo que baja el riesgo es dar');
            $this->line('  correo propio a las cuentas de los dos primeros bloques.');
            $this->line('');
        }

        if ($noRecuperan === 0 && $compartidos === []) {
            $this->info('  Cada cuenta activa tiene su propia dirección. Nada que hacer.');
            $this->line('');

            return self::SUCCESS;
        }

        if ($compartidos === []) {
            $this->warn('  Ningún correo repetido: cada enlace abre una sola cuenta. Pero las');
            $this->warn('  '.$noRecuperan.' de arriba no pueden pedirlo, así que dependen de que un');
            $this->warn('  superusuario les ponga la contraseña a mano.');
            $this->line('');
        }

        return self::FAILURE;
    }

    /** @param  array<int, object>  $grupos */
    private function pintar(array $grupos): void
    {
        foreach ($grupos as $g) {
            $this->line(sprintf('    %5dx  %-34s  [%s]', $g->cuentas, mb_substr((string) $g->email, 0, 34), $g->cuales));
            $this->line('            '.mb_substr((string) $g->quienes, 0, 88));
        }

        $this->line('');
    }
}
