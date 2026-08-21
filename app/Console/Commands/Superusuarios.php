<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Quién tiene hoy la llave del colegio, y si el colegio lo sabe.
 *
 * `is_superuser` es el permiso más grande que existe aquí: la mitad de las
 * comprobaciones del sistema son `Autoriza::esSuperusuario()`, y varios
 * documentos de la migración razonan sobre «los diez Admin, que son exactamente
 * los diez `is_superuser`» —docs/migracion/06-autorizacion.md §4— como si fueran
 * diez personas.
 *
 * **No lo son.** En la copia de desarrollo, seis de esos diez se llaman
 * `admin(inhabilitado)`, `coordinacion(inhabilitado)`,
 * `convivencia2019(inhabilitado)`… y están `is_active = 1`, fuera de la papelera
 * y con su hash bcrypt intacto. O sea que **el colegio dio por inhabilitadas seis
 * cuentas de superusuario renombrándolas**, y el sistema no lee el nombre: lee la
 * bandera, y la bandera dice que sí.
 *
 * Eso no se arregla desde aquí. Apagar la cuenta de alguien es del colegio, y
 * puede que alguna de esas seis se siga usando pese al nombre. Lo que sí se
 * puede es dejar de suponer: este comando lista las que están encendidas para
 * que cada colegio confirme una a una.
 *
 * **Lo que NO puede decir**, y va escrito para que nadie le pida más: si una
 * cuenta sin marca en el nombre pertenece a alguien que sigue trabajando ahí.
 * Los superusuarios de este sistema son `tipo = 'Usuario'` y **no tienen ficha**
 * —no hay fila de alumno, profesor ni acudiente detrás—, así que no hay nada en
 * la base que diga quién es cada uno ni desde cuándo no entra. La marca en el
 * nombre es la única pista que dejó el colegio, y por eso es la que se busca.
 *
 * La forma de apagar una cuenta es `is_active = 0` —lo escribe
 * `perfiles/update`—, no el nombre.
 *
 * Uso, en cada colegio:
 *
 *     php artisan usuarios:superusuarios
 *
 * No escribe nada. Sale con código 1 si hay alguna encendida con marca de
 * inhabilitada, para que se note en un bucle sobre los dieciséis.
 */
class Superusuarios extends Command
{
    protected $signature = 'usuarios:superusuarios';

    protected $description = 'Lista los superusuarios activos y avisa de los que el nombre da por inhabilitados';

    /**
     * Cómo escribe un colegio que una cuenta ya no vale.
     *
     * Es una heurística y se dice: lo que hay en los datos es `(inhabilitado)`
     * pegado al nombre. Las demás formas van por si otro colegio usó la suya, y
     * si aparece una que no está aquí, la lista se queda corta **en silencio** —
     * de ahí que el comando imprima siempre el total y no solo las marcadas.
     */
    private const MARCAS = 'inhabilit|deshabilit|no.?usar|no.?utilizar|anulad|obsolet|retirad|antigu';

    public function handle(): int
    {
        $base = DB::connection()->getDatabaseName();

        $encendidos = DB::select('SELECT id, username, tipo, created_at FROM users
            WHERE is_superuser = 1 AND is_active = 1 AND deleted_at IS NULL ORDER BY id');

        $apagados = (int) DB::selectOne('SELECT COUNT(*) n FROM users
            WHERE is_superuser = 1 AND (is_active = 0 OR deleted_at IS NOT NULL)')->n;

        $marcados = array_values(array_filter($encendidos,
            fn (object $u) => preg_match('/'.self::MARCAS.'/i', (string) $u->username) === 1));

        $this->line('');
        $this->line('  base ............................ '.$base);
        $this->line('  SUPERUSUARIOS ENCENDIDOS ........ '.count($encendidos));
        $this->line('     y el nombre los da por rotos . '.count($marcados));
        $this->line('  apagados o en la papelera ....... '.$apagados);
        $this->line('');

        foreach ($encendidos as $u) {
            $marca = preg_match('/'.self::MARCAS.'/i', (string) $u->username) === 1;
            $linea = sprintf('    %-6s %-34s %-9s desde %s', $u->id,
                mb_substr((string) $u->username, 0, 34), $u->tipo, mb_substr((string) $u->created_at, 0, 10));

            $marca ? $this->error($linea.'   <-- el nombre dice que no debería estar encendida')
                   : $this->line($linea);
        }

        $this->line('');

        if ($marcados === []) {
            $this->info('  Ninguna cuenta encendida se llama a sí misma inhabilitada. Aun así,');
            $this->info('  la lista de arriba hay que confirmarla: la marca en el nombre es una');
            $this->info('  pista, no un inventario.');
            $this->line('');

            return self::SUCCESS;
        }

        $this->warn('  Esas cuentas entran, y entran como superusuario: la bandera que lee el');
        $this->warn('  sistema es `is_active`, no el nombre. Si de verdad sobran, lo que las');
        $this->warn('  apaga es `is_active = 0`; renombrarlas no hizo nada.');
        $this->line('');

        return self::FAILURE;
    }
}
