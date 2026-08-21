<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * En qué año amanece este colegio, y si la respuesta es una sola.
 *
 * Todo el sistema entra al año que devuelve esta consulta:
 *
 *     SELECT id, year, actual FROM years WHERE actual=1 and deleted_at is null
 *
 * y la coge con `$anios[0]`, **sin `ORDER BY`**. Con una fila da igual. Con dos,
 * en qué año trabaja el colegio entero lo decide el orden en que MySQL devuelva
 * las filas ese día. Ver docs/migracion/05-codigo-muerto-y-roto.md §28.
 *
 * Los tres caminos que creaban años actuales de más están cerrados desde el 20
 * ago 2026 —«ahora NO es año actual» dejaba el año encendido por tres sitios
 * distintos, y restaurar un año de la papelera lo devolvía encendido al lado del
 * que ya lo estuviera—, pero **los datos de antes siguen ahí**: el arreglo impide
 * que vuelva a pasar, no deshace lo que pasó.
 *
 * Este comando existe porque la alternativa era pegar la consulta a mano en
 * dieciséis bases, y porque **hay una fecha**: Joseth contó el 21 ago 2026 que
 * «más o menos en octubre se crea el año siguiente copiando todo del anterior».
 * Esa copia es exactamente el momento en que un colegio con dos años actuales se
 * lleva la ambigüedad al año nuevo. Conviene haberlo corrido antes.
 *
 * Uso, en cada colegio:
 *
 *     php artisan anios:actuales
 *
 * No escribe nada. Sale con código 1 si hay algo que mirar, para que se note en
 * un bucle sobre los dieciséis.
 */
class AniosActuales extends Command
{
    protected $signature = 'anios:actuales';

    protected $description = 'Dice cuántos años actuales tiene este colegio, y avisa si no es exactamente uno';

    public function handle(): int
    {
        $base = DB::connection()->getDatabaseName();

        $actuales = DB::select('SELECT id, year, created_at, updated_at FROM years
                                WHERE actual = 1 AND deleted_at IS NULL ORDER BY id');

        $this->line('');
        $this->line('  base .......... '.$base);
        $this->line('  años actuales . '.count($actuales));
        $this->line('');

        foreach ($actuales as $anio) {
            $this->line(sprintf('    id %-6s year %-6s  modificado %s',
                $anio->id, $anio->year, $anio->updated_at ?? '(sin fecha)'));
        }

        // Los borrados con `actual=1` no se ven desde ninguna pantalla —todo lo
        // que lee el año filtra `deleted_at`— pero `years/restore/{id}` los
        // devuelve encendidos. Son la trampa de la §28.3, así que se enseñan
        // aunque hoy no molesten.
        $enPapelera = DB::select('SELECT id, year, deleted_at FROM years
                                  WHERE actual = 1 AND deleted_at IS NOT NULL ORDER BY id');

        if ($enPapelera !== []) {
            $this->line('');
            $this->line('  y en la papelera, encendidos (restaurarlos los devuelve así):');

            foreach ($enPapelera as $anio) {
                $this->line(sprintf('    id %-6s year %-6s  borrado %s',
                    $anio->id, $anio->year, $anio->deleted_at));
            }
        }

        $this->line('');

        if (count($actuales) === 1 && $enPapelera === []) {
            $this->info('  Un solo año actual y nada encendido en la papelera. Nada que hacer.');

            return self::SUCCESS;
        }

        if (count($actuales) === 0) {
            $this->error('  NINGÚN año actual. `Year::actual()` devuelve vacío y el colegio no entra.');
        }

        if (count($actuales) > 1) {
            $this->error('  MÁS DE UN año actual. En cuál entra el colegio lo decide MySQL, porque');
            $this->error('  la consulta que lo lee coge la primera fila y no lleva ORDER BY.');
        }

        if ($enPapelera !== []) {
            $this->warn('  Hay años encendidos en la papelera. Hoy no se ven, pero restaurar uno');
            $this->warn('  deja dos encendidos a la vez.');
        }

        $this->line('');
        $this->line('  Qué hacer: decidir cuál es el año bueno y apagar los demás. Apagarlos');
        $this->line('  desde la aplicación (years/set-actual sobre el bueno) apaga el resto.');
        $this->line('  No lo hace este comando: elegir el año de un colegio no es de un script.');
        $this->line('');

        return self::FAILURE;
    }
}
