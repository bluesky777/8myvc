<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Repara las horas que se guardaron DOS VECES, en las tres columnas medidas.
 *
 * ## Qué se rompió
 *
 * `Carbon::now('America/Bogota')->format('Y-m-d G:H:i')`. `G` y `H` son las dos
 * la hora del día —una sin cero delante y otra con él—, así que el formato era
 * `hora:hora:minutos` y **los segundos no llegaban nunca**. Las 21:07:33 se
 * guardaron como **21:21:07**. Los dos sitios que escribían así están arreglados
 * desde el 23 ago 2026 (05 §121 y §123); **las filas ya escritas no las arregla
 * un commit**, y eso es la decisión C del 09.
 *
 * ## Por qué SÍ se puede reparar, que es lo que desbloqueó la decisión
 *
 * El campo de los minutos se quedó con la HORA y el de los segundos con el
 * MINUTO real, o sea que **la hora y el minuto siguen dentro del dato**:
 *
 *     real 07:15:33  ->  guardado 07:07:15  ->  reparado 07:15:00
 *
 * Se recuperan hora y minuto; **se pierden los segundos**, que se fueron el día
 * que se escribió. Hasta el 6 sep 2026 esto se leía como «no se puede
 * recuperar», y lo que no se recupera es sólo el segundo (05 §250).
 *
 * ## A quién le toca, medido en los DIECISIETE colegios el 6 sep 2026
 *
 * `tools/hora-escrita-dos-veces.php` en el servidor: **17 medidos, 0 no medidos,
 * 13 con daño**. 708 filas de `change_asked.deleted_at` en doce colegios y
 * ~25.200 de `ausencias.created_at`/`updated_at` en ocho, seis columnas al 100 %.
 * **`ausencias.fecha_hora` NO está dañada en ninguno** y por eso no se toca: es
 * la hora a la que llegó tarde el alumno, la escribe una persona.
 *
 * ## EL PRECIO, y va aquí porque no desaparece por no escribirlo
 *
 * La firma `HOUR = MINUTE` **tiene falsos positivos**: una fila escrita bien a
 * las 21:21 también la cumple, ~1 de cada 60. Esta migración **las repara
 * también**, y a ésas les cambia el minuto. Donde la columna salió al 100 % eso
 * es despreciable; en `cads_itagui`, que salió 90 de 214, son unas 3 o 4 filas
 * buenas. **Es el coste aceptado de la decisión, no un descuido.**
 *
 * ## Por qué excluye `SECOND(col) = 0`, que es lo que la hace segura de repetir
 *
 * Una fila ya reparada por nosotros queda con **SECOND = 0**. Sin esta
 * exclusión, una segunda pasada sobre una fila cuyo segundo coincide con su hora
 * —21:21:21 → 21:21:00 → **21:00:00**— la volvería a mover, y esta vez sin
 * arreglo. Con la exclusión, correrla dos veces no cambia nada la segunda vez.
 *
 * **Lo que cuesta:** las filas cuyo minuto real era `00` se quedan sin reparar,
 * porque son indistinguibles de una ya reparada. Es ~1 de cada 60 de las
 * dañadas. *Se prefiere no repararlas a arriesgarse a estropearlas.*
 *
 * ## `down()` NO devuelve el dato, y aquí está el porqué
 *
 * La vuelta atrás es aritméticamente posible —de `HH:mm:00` sale `HH:HH:mm`—
 * **pero no se puede saber qué filas tocó esta migración**: una fila sana
 * escrita a las 14:30:00 tiene exactamente esa forma, y un `down()` a ciegas la
 * convertiría en 14:14:30. O sea que **desandar estropearía filas que estaban
 * bien**. El camino de vuelta de esta migración es la copia de seguridad de
 * antes de correrla, no su `down()`.
 *
 * ## NO ENTRA EN LA TANDA DEL DÍA 10
 *
 * Joseth la congeló en SIETE migraciones el 5 sep 2026, y ya se ensayó sobre esas
 * siete. Ésta sería la octava. Vive en su rama y **no se funde en `main` hasta
 * que el despliegue del día 10 esté hecho**.
 */
return new class extends Migration
{
    /**
     * Las sentencias, expuestas para que un test las ejecute LAS MISMAS.
     *
     * No es aseo: si el test escribiera su propio SQL «equivalente», estaría
     * comprobando el SQL del test. Cada entrada es [rótulo, UPDATE, SELECT que
     * cuenta lo que queda por reparar].
     *
     * @return array<int, array{0: string, 1: string, 2: string}>
     */
    public static function sentencias(): array
    {
        $columnas = [
            ['change_asked.deleted_at', 'change_asked', 'deleted_at', ''],
            ['ausencias.created_at', 'ausencias', 'created_at', ' AND uploaded IS NOT NULL'],
            ['ausencias.updated_at', 'ausencias', 'updated_at', ' AND uploaded IS NOT NULL'],
        ];

        $sentencias = [];

        foreach ($columnas as [$rotulo, $tabla, $col, $extra]) {
            // `HOUR = MINUTE` es la firma; `SECOND <> 0` es lo que la hace
            // repetible. El `IS NOT NULL` va delante porque las tres columnas
            // son anulables y `HOUR(NULL)` no es comparable.
            $donde = "`{$col}` IS NOT NULL"
                ." AND HOUR(`{$col}`) = MINUTE(`{$col}`)"
                ." AND SECOND(`{$col}`) <> 0"
                .$extra;

            $sentencias[] = [
                $rotulo,
                // TIMESTAMP(fecha, hora) y MAKETIME existen igual en MySQL 8 y en
                // MariaDB 10.5, que es lo que corre producción.
                "UPDATE `{$tabla}` SET `{$col}` = TIMESTAMP(DATE(`{$col}`), "
                    ."MAKETIME(HOUR(`{$col}`), SECOND(`{$col}`), 0)) WHERE {$donde}",
                "SELECT COUNT(*) AS n FROM `{$tabla}` WHERE {$donde}",
            ];
        }

        return $sentencias;
    }

    public function up(): void
    {
        foreach (self::sentencias() as [$rotulo, $update, $cuenta]) {
            $antes = (int) DB::selectOne($cuenta)->n;
            $tocadas = DB::update($update);
            $despues = (int) DB::selectOne($cuenta)->n;

            // Se imprime porque esto corre a las tres de la mañana en diecisiete
            // colegios y «migrated» a secas no dice si tocó 0 o 8.000 filas. Y el
            // `quedan` tiene que salir 0: si no, la sentencia no hizo lo que cree.
            echo sprintf(
                "    %-28s reparadas %6d   (habia %6d, quedan %d)\n",
                $rotulo, $tocadas, $antes, $despues
            );
        }
    }

    public function down(): void
    {
        // A propósito no deshace nada. El porqué está en la cabecera: la vuelta
        // es aritméticamente posible pero **no se sabe qué filas se tocaron**, y
        // aplicarla a ciegas estropearía filas que siempre estuvieron bien.
        echo "    reparar_la_hora: down() no restaura — la vuelta es la copia de seguridad,\n"
            ."    no esta migracion. El porque, en su cabecera.\n";
    }
};
