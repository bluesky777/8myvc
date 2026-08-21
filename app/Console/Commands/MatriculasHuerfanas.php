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

    protected $description = 'Lista los alumnos cuyas matrículas de un año están todas en la papelera';

    public function handle(): int
    {
        $base = DB::connection()->getDatabaseName();
        $soloYear = $this->option('year');

        $enPapelera = (int) DB::selectOne('SELECT COUNT(*) n FROM matriculas WHERE deleted_at IS NOT NULL')->n;

        $this->line('');
        $this->line('  base ......................... '.$base);
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
}
