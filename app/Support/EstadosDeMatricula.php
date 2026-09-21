<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Qué puede valer `matriculas.estado`, que hasta hoy no lo decía nadie.
 *
 * La Fase 1 de la importación dejó cerrada **la puerta del tamaño**: un estado de
 * más de cuatro caracteres no se escribe, porque guardarlo cortado deja al alumno
 * fuera de las listas y eso no se nota hasta que alguien lo echa en falta.
 *
 * **Faltaba la otra puerta, y la encontró `myvc-front-51` el 21 sep 2026:** un
 * valor que CABE pero no existe —`ACTV`— pasaba los cuatro caracteres, se
 * escribía tal cual y dejaba al alumno **en un estado que no consulta ninguna
 * query**. El síntoma es idéntico al del truncado —desaparece de las listas— y el
 * camino es el contrario: no es que se estropee al guardarlo, es que se guarda
 * entero y no significa nada.
 *
 * ## El catálogo se MIDE, no se declara
 *
 * No hay tabla de estados: son convenciones repartidas por el SQL de
 * `Grupo`, `Matricula` y media docena de informes. Declarar aquí una lista corta
 * sería peor que el fallo — un colegio que use un octavo estado legítimo vería
 * sus matrículas marcadas como erróneas.
 *
 * Así que el catálogo es la **unión** de dos cosas:
 *
 * 1. Los que el código nombra en sus consultas: si una query pregunta por ellos,
 *    son válidos aunque este colegio no los use todavía.
 * 2. **Los que este colegio ya tiene escritos.** Medido en la copia de
 *    desarrollo: `MATR` 3.092, `RETI` 480, `PREM` 9, `FORM` 5, `ASIS` 4, `PREA` 3,
 *    `DESE` 1. Un colegio no puede quedar en falso por su propia historia.
 *
 * *Sale de la base y no de una constante por lo mismo que el vocabulario del
 * paso: una base dice qué pasó en un colegio, y aquí lo que hace falta es
 * exactamente eso — qué usa ESTE colegio.*
 *
 * ## Y lo que se hace con lo que no está NO es rechazarlo
 *
 * Se anota para que la persona lo decida, igual que un vocabulario que no se
 * reconoce. Rechazar de oficio convertiría este arreglo en el fallo de enfrente.
 */
class EstadosDeMatricula
{
    /**
     * Los que el código nombra en sus consultas, leídos de `Grupo`, `Matricula` y
     * el comentario de `Matricula:284`. Son válidos aunque un colegio no los use.
     *
     * @var list<string>
     */
    public const QUE_EL_CODIGO_NOMBRA = ['MATR', 'ASIS', 'PREM', 'PREA', 'RETI', 'FORM', 'DESE'];

    /** El tope de la columna. Lo que se pase de aquí es la otra puerta, la del tamaño. */
    public const LONGITUD = 4;

    /** @var list<string>|null */
    private static ?array $cache = null;

    /**
     * El catálogo de este colegio: lo que el código nombra más lo que ya está
     * escrito en su base.
     *
     * Se cachea por petición porque la importación pregunta una vez por fila y
     * son miles; no se cachea entre peticiones porque una matrícula nueva puede
     * estrenar un estado y el catálogo tiene que enterarse el mismo día.
     *
     * @return list<string>
     */
    public static function delColegio(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $suyos = DB::select("SELECT DISTINCT estado FROM matriculas
             WHERE deleted_at IS NULL AND estado IS NOT NULL AND TRIM(estado) <> ''");

        $valores = self::QUE_EL_CODIGO_NOMBRA;

        foreach ($suyos as $fila) {
            $valores[] = mb_strtoupper(trim((string) $fila->estado));
        }

        return self::$cache = array_values(array_unique($valores));
    }

    /** Se olvida el catálogo. Lo necesitan los tests, que estrenan estados dentro de su transacción. */
    public static function olvidar(): void
    {
        self::$cache = null;
    }

    /** Si cabe en la columna. La puerta del tamaño, que la Fase 1 ya cerraba. */
    public static function cabe(?string $valor): bool
    {
        return $valor === null || mb_strlen(trim($valor)) <= self::LONGITUD;
    }

    /**
     * Si además significa algo. La puerta del catálogo, que no cerraba nadie.
     *
     * Un valor que no cabe devuelve `false` aquí también, pero las dos preguntas
     * se hacen por separado porque **el motivo que se le enseña a la persona es
     * distinto** y las consecuencias que tiene que leer no son las mismas.
     */
    public static function existe(?string $valor): bool
    {
        if ($valor === null || trim($valor) === '') {
            return true;
        }

        return in_array(mb_strtoupper(trim($valor)), self::delColegio(), true);
    }
}
