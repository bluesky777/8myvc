<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Las consultas que no pueden volver a recorrer la tabla entera.
 *
 * No comprueba que el índice exista —eso sería comprobar que la migración se
 * ejecutó, que ya lo dice `migrate`—. Comprueba lo que de verdad importa y lo
 * pregunta a MySQL: que para estas consultas **existe un índice aplicable**.
 * Es la diferencia entre «hay un índice llamado así» y «este índice sirve para
 * esta consulta», y solo la segunda sobrevive a que alguien reordene un WHERE o
 * cambie una columna del compuesto.
 *
 * `possible_keys` vacío significa que MySQL no tiene ningún índice que pudiera
 * considerar: recorrido completo obligatorio. Es una propiedad del esquema y no
 * del volumen de datos, así que la base de tests —pequeña— la contesta igual
 * que la de un colegio con un millón de notas.
 *
 * Las tres consultas están copiadas de donde viven, no inventadas para el test.
 * Si alguna cambia allí y aquí no, esto deja de proteger nada: por eso cada
 * caso dice de qué fichero salió.
 */
class IndicesTest extends CasoDeContrato
{
    #[DataProvider('consultasQueNoDebenRecorrerLaTabla')]
    public function test_la_consulta_puede_usar_un_indice(string $sql, array $valores, string $donde): void
    {
        $plan = DB::select('EXPLAIN '.$sql, $valores);

        $sinIndice = array_filter($plan, fn ($paso) => empty($paso->possible_keys) && ! str_starts_with((string) $paso->table, '<'));

        $this->assertEmpty(
            $sinIndice,
            "La consulta de {$donde} volvió a quedarse sin índice aplicable.\n".
            'Tablas sin candidato: '.implode(', ', array_map(fn ($p) => $p->table, $sinIndice))."\n".
            "Si el WHERE cambió, el índice de la migración 2026_08_20_100000 hay que cambiarlo con él.\n".
            'Para volver a medirlo todo: tools/indices-que-faltan.php'
        );
    }

    public static function consultasQueNoDebenRecorrerLaTabla(): array
    {
        return [
            'el guard de acudiente, en cada petición suya' => [
                'SELECT id FROM parentescos WHERE alumno_id=? and acudiente_id=? and deleted_at is null',
                [1, 1],
                'app/Http/Middleware/ExigirPersonaPropia.php',
            ],
            'los acudientes de un alumno' => [
                'SELECT acudiente_id FROM parentescos WHERE alumno_id=? and deleted_at is null',
                [1],
                'app/Models/Acudiente.php',
            ],
            'los acudidos de un acudiente' => [
                'SELECT alumno_id FROM parentescos WHERE acudiente_id=? and deleted_at is null',
                [1],
                'app/Exports/AcudientesExport.php',
            ],
            'las frases del boletín, una por asignatura de cada alumno' => [
                'SELECT fa.id FROM frases_asignatura fa
                    where fa.deleted_at is null and fa.alumno_id=? and fa.asignatura_id=? and fa.periodo_id=?',
                [1, 1, 1],
                'app/Models/FraseAsignatura.php',
            ],
            'las imágenes de una persona' => [
                'SELECT * FROM images WHERE user_id=? and publica=true',
                [1],
                'app/Http/Controllers/YearsController.php',
            ],
        ];
    }
}
