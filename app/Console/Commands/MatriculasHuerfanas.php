<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Qué alumnos se quedaron sin matrícula viva en un año que sí tuvieron.
 *
 * Existe por el fallo de `Matricula::matricularUno()`
 * (docs/migracion/12-larastan-nivel-7.md §1): re-matricular a un alumno cuyas
 * matrículas de ese año estaban en la papelera **volvía a borrarlas todas y no
 * revivía ninguna**, y encima respondía 200. En PHP 8 eso pasó a ser un 500, así
 * que el fallo dejó de hacer daño el día que se vio; el daño de antes sigue
 * escrito en las bases.
 *
 * **Este comando no puede decir cuáles fueron culpa del fallo.** Un alumno con
 * todas sus matrículas del año en la papelera puede haber llegado ahí por
 * `matriculas/destroy`, que es una operación legítima. Lo que hace es acotar la
 * lista a mano de colegio: son los alumnos que **hoy no salen en ninguna lista,
 * ningún boletín y ninguna acta** de ese año, y el colegio sabe cuáles de ellos
 * estaban asistiendo. Decir más sería inventar.
 *
 * La primera cifra que imprime es la que más dice: **si el colegio no tiene
 * ninguna matrícula en la papelera, el fallo nunca se disparó ahí**. En la copia
 * de desarrollo son cero —los retiros se hacen con `estado='RETI'` y la fila se
 * queda viva—, así que es perfectamente posible que varios colegios salgan
 * limpios.
 *
 * **Y una segunda pregunta, que se contesta en el mismo viaje.** «Matriculado»
 * no significa lo mismo en todas las consultas de este proyecto: hay al menos
 * cinco listas distintas de `estado` repartidas por `app/`, y la diferencia entre
 * ellas decide si un alumno sale o no en una pantalla. Medido el 21 ago 2026,
 * contando las condiciones sobre `matriculas.estado`:
 *
 *     MATR 78 · ASIS 67 · PREM 40 · PREA 11 · FORM 8 · RETI 10 · DESE 7
 *
 * O sea que un alumno en **PREM** —prematriculado— sale en cuarenta sitios y no
 * sale en otros treinta y ocho, y uno en **PREA** o **FORM**, casi en ninguno.
 * Cuál de las dos formas es la correcta no lo puede decir un script; lo que sí
 * puede es decir **si a este colegio le pasa**, que es lo que no se sabe desde
 * aquí. En la copia de desarrollo no le pasa: 3.060 MATR, 479 RETI y una fila
 * suelta de cada uno de los demás. En un colegio que use la prematrícula —la
 * copia del año siguiente es de octubre— la respuesta puede ser otra.
 *
 * Uso, en cada colegio:
 *
 *     php artisan matriculas:huerfanas
 *     php artisan matriculas:huerfanas --year=2026    # solo ese año
 *
 * No escribe nada. Sale con código 1 si hay alguien que mirar, para que se note
 * en un bucle sobre los dieciséis.
 */
class MatriculasHuerfanas extends Command
{
    protected $signature = 'matriculas:huerfanas {--year= : Mirar solo ese año (el número, no el id)}';

    protected $description = 'Lista los alumnos sin matrícula viva, y en qué estados están las matrículas de este colegio';

    public function handle(): int
    {
        $base = DB::connection()->getDatabaseName();
        $soloYear = $this->option('year');

        $enPapelera = (int) DB::selectOne('SELECT COUNT(*) n FROM matriculas WHERE deleted_at IS NOT NULL')->n;

        $this->line('');
        $this->line('  base ......................... '.$base);

        $this->estadosDeEsteColegio($soloYear);

        $this->line('  matrículas en la papelera .... '.$enPapelera);

        if ($enPapelera === 0) {
            $this->line('');
            $this->info('  Ninguna matrícula en la papelera: el fallo de matricularUno no se');
            $this->info('  disparó nunca en este colegio. Nada que revisar.');
            $this->line('');

            return self::SUCCESS;
        }

        // Un alumno cuenta si tiene matrículas borradas en un año y **ninguna
        // viva** en ese mismo año. El año sale del grupo, no de la matrícula:
        // `matriculas` no tiene year_id, lo lleva `grupos`.
        $consulta = 'SELECT y.year, a.id AS alumno_id, a.apellidos, a.nombres,
                            COUNT(*) AS borradas, MAX(m.deleted_at) AS ultima
                     FROM matriculas m
                     INNER JOIN grupos g ON g.id = m.grupo_id
                     INNER JOIN years y ON y.id = g.year_id
                     INNER JOIN alumnos a ON a.id = m.alumno_id AND a.deleted_at IS NULL
                     WHERE m.deleted_at IS NOT NULL
                       AND NOT EXISTS (
                           SELECT 1 FROM matriculas m2
                           INNER JOIN grupos g2 ON g2.id = m2.grupo_id
                           WHERE m2.alumno_id = m.alumno_id
                             AND g2.year_id = y.id
                             AND m2.deleted_at IS NULL
                       )';

        $parametros = [];

        if ($soloYear !== null) {
            $consulta .= ' AND y.year = ?';
            $parametros[] = $soloYear;
        }

        $consulta .= ' GROUP BY y.year, a.id, a.apellidos, a.nombres
                       ORDER BY y.year DESC, a.apellidos, a.nombres';

        $huerfanos = DB::select($consulta, $parametros);

        $this->line('  alumnos sin matrícula viva ... '.count($huerfanos));
        $this->line('');

        if ($huerfanos === []) {
            $this->info('  Hay matrículas en la papelera, pero ningún alumno se quedó sin ninguna');
            $this->info('  viva en su año. Nada que revisar.');
            $this->line('');

            return self::SUCCESS;
        }

        $anioActual = null;

        foreach ($huerfanos as $fila) {
            if ($fila->year !== $anioActual) {
                $anioActual = $fila->year;
                $this->line('  año '.$anioActual);
            }

            $this->line(sprintf('    id %-7s %-40s  %d en papelera, la última el %s',
                $fila->alumno_id,
                mb_substr(trim($fila->apellidos.' '.$fila->nombres), 0, 40),
                $fila->borradas,
                $fila->ultima ?? '(sin fecha)'));
        }

        $this->line('');
        $this->warn('  Estos alumnos no salen hoy en ninguna lista, boletín ni acta de su año.');
        $this->line('');
        $this->line('  Qué hacer: preguntar al colegio cuáles estaban asistiendo. Al que sí,');
        $this->line('  volver a matricularlo desde la aplicación — que desde el arreglo de');
        $this->line('  matricularUno ya revive la matrícula en vez de borrarla otra vez.');
        $this->line('  No lo hace este comando: decidir quién estaba matriculado no es de un');
        $this->line('  script, y este no puede distinguir el fallo de una baja legítima.');
        $this->line('');

        return self::FAILURE;
    }

    /**
     * En qué estados están las matrículas vivas, y si alguno es de los ambiguos.
     *
     * No decide nada: imprime. Lo que hace útil imprimirlo es que **la respuesta
     * cambia de colegio a colegio** y de ella depende si las cinco listas de
     * `estado` que hay repartidas por `app/` son un problema real aquí o una
     * incoherencia dormida.
     */
    private function estadosDeEsteColegio(?string $soloYear): void
    {
        // MATR y ASIS los incluyen todas las variantes; RETI y DESE los excluyen
        // todas. Los tres de en medio son los que cambian según la consulta, y
        // por eso son los únicos que se señalan.
        $ambiguos = ['PREM', 'PREA', 'FORM'];

        $consulta = 'SELECT m.estado, COUNT(*) n FROM matriculas m
                     INNER JOIN grupos g ON g.id = m.grupo_id AND g.deleted_at IS NULL
                     INNER JOIN years y ON y.id = g.year_id
                     WHERE m.deleted_at IS NULL';

        $parametros = [];

        if ($soloYear !== null) {
            $consulta .= ' AND y.year = ?';
            $parametros[] = $soloYear;
        }

        $consulta .= ' GROUP BY m.estado ORDER BY n DESC';

        $filas = DB::select($consulta, $parametros);

        $this->line('  matrículas vivas por estado .. '
            .array_sum(array_map(fn ($f) => (int) $f->n, $filas)));

        $enDisputa = 0;

        foreach ($filas as $fila) {
            $estado = (string) $fila->estado;
            $dudoso = in_array($estado, $ambiguos, true);

            if ($dudoso) {
                $enDisputa += (int) $fila->n;
            }

            $this->line(sprintf('     %-6s %6s%s', $estado === '' ? '(vacío)' : $estado, $fila->n,
                $dudoso ? '   <-- sale en unas listas y en otras no' : ''));
        }

        if ($enDisputa > 0) {
            $this->warn('     '.$enDisputa.' '.($enDisputa === 1 ? 'matrícula' : 'matrículas')
                .' en un estado que unas consultas cuentan como');
            $this->warn('     matriculado y otras no (MATR 78 · ASIS 67 · PREM 40 · PREA 11 · FORM 8');
            $this->warn('     condiciones en app/). Cuál es la correcta no lo decide un script.');
        }

        $this->line('');
    }
}
