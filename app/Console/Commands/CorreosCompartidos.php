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
 * **Compartir correo.** Hasta el 21 ago 2026 esto era un agujero: el enlace abría
 * cualquier cuenta del grupo, porque `putResetPassword` se creía el `username`
 * del cuerpo. Ya no —`password_reminders` guarda a quién se le emitió el token—,
 * y por eso este comando dejó de decir «se resetean entre sí»: **decirlo ahora
 * mandaría a arreglar algo que está arreglado**, que es la única forma que tiene
 * un diagnóstico de hacer daño.
 *
 * Lo que queda es de recuperación y no de seguridad, y es la otra cara del mismo
 * arreglo: `postRecuperarClave` busca el correo y se queda con `$persona[0]`, o
 * sea **la cuenta de id más bajo del grupo**. Las demás no pueden pedir un enlace
 * nunca — antes lo lograban nombrándose en el cuerpo, que era justo el agujero.
 * Ocho cuentas en la copia de desarrollo. Por eso suman en el bloque de arriba.
 *
 * **No poder recuperar.** Sin correo, o con un correo que no es una dirección,
 * `postRecuperarClave` aborta con 422 antes de mirar la base. Esas cuentas
 * dependen de que un superusuario les ponga la contraseña a mano.
 *
 * Los grupos se siguen ordenando por lo que cuesta, no por tamaño, y el motivo
 * cambió con el arreglo: ya no es que el grupo se alcance entre sí, es que
 * **quien lea ese buzón resetea la cuenta a la que le toque el enlace**, y a cuál
 * le toca lo decide un `id`:
 *
 *   1. grupos con un **superusuario** dentro — si es el de id más bajo, el enlace
 *      del colegio entero llega a un buzón de familia;
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
                                        GROUP_CONCAT(username ORDER BY id SEPARATOR ", ") AS quienes,
                                        SUBSTRING_INDEX(GROUP_CONCAT(username ORDER BY id), ",", 1) AS primera
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

        // Todas menos la de id más bajo de cada grupo: `postRecuperarClave` se
        // queda con `$persona[0]`, así que a las demás no se les puede emitir un
        // enlace. Suman aquí porque el efecto para su dueño es el mismo que no
        // tener correo, y contarlas aparte las dejaría fuera del número que se
        // mira.
        $noPrimeras = array_sum(array_map(fn ($g) => (int) $g->cuentas - 1, $compartidos));

        $noRecuperan = $sinCorreo + $cuentasSinDireccion + $noPrimeras;

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

        $this->line('     lo comparten y no son la 1ª .. '.$noPrimeras);

        $this->line('');
        $this->line('  COMPARTEN CORREO ................ '.$enJuego.' cuentas en '.count($compartidos).' grupos');
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

            $this->line('  El enlace de cada grupo va a la cuenta de id más bajo, que arriba sale');
            $this->line('  como «recibe el enlace». Las demás no pueden pedirlo: darles correo');
            $this->line('  propio es lo que las devuelve al circuito, y de paso saca al superusuario');
            $this->line('  de un buzón que lee una familia.');
            $this->line('');
        }

        if ($noRecuperan === 0 && $compartidos === []) {
            $this->info('  Cada cuenta activa tiene su propia dirección. Nada que hacer.');
            $this->line('');

            return self::SUCCESS;
        }

        if ($compartidos === []) {
            $this->warn('  Ningún correo repetido: cada cuenta activa tiene el suyo. Pero las');
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
            $this->line('            recibe el enlace: '.$g->primera);
        }

        $this->line('');
    }
}
