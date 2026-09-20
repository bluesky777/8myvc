<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * **Una casilla sin calificar deja de valer cero y pasa a no valer nada.**
 *
 * El porqué entero está en `docs/migracion/43-lo-que-todavia-no-se-ha-calificado.md`.
 * Aquí va lo que hace falta para leer esta migración sin abrirlo, y las tres cosas
 * que no se pueden deducir del código.
 *
 * ## El fallo, en una línea
 *
 * `notas.nota` es `int NOT NULL DEFAULT 0` y la fila **nace con el indicador**: los
 * cuatro `INSERT` de `notas` siembran una fila por alumno en cuanto se crea la
 * subunidad. Desde ese instante el indicador **pesa en la definitiva**, calificado o
 * no, porque la fórmula suma `peso × nota` sobre todas las filas que existan. A
 * mitad de periodo eso no dice «cómo va el alumno»: dice «cuánto del periodo entero
 * lleva ganado», y pintado con la escala del colegio sale BAJO de casi todo el mundo.
 *
 * Medido contra `simonbolivar` el 19 sep 2026 a las 20:08, en el árbol principal
 * sobre `ffd7b52` — **un colegio, el de la copia de desarrollo, no los dieciséis**:
 * en el periodo 2 de 2025, a medio calificar, de **767** pares alumno-asignatura con
 * alguna nota puesta salían **767 en rojo**, **539 no estaban perdidos** y **258 iban
 * en SUPERIOR**.
 *
 * ## Por qué `NULL` y no una columna `calificada_at` al lado
 *
 * Decisión de Joseth del 19 sep (D6 del doc 43), **contra** la que traía esta sesión.
 * Una columna nueva funciona, pero deja la regla *«no cuentes lo que no está
 * calificado»* a cargo de que **cada una de las 990 consultas crudas se acuerde**.
 * Con `NULL` la regla deja de ser una norma y pasa a ser aritmética:
 *
 *     SUM(peso * NULL)  ->  la fila no entra, sin que nadie lo pida
 *     NULL < 30         ->  NULL, o sea que tampoco cuenta como perdida
 *
 * *Una consulta que nadie actualice deja de mentir sola, en vez de seguir mintiendo
 * en silencio.* Y los tres clientes ya estaban escritos para esto —
 * `LibroNotasApi.dart` declara `final double? nota` y `bool get puesta => nota !=
 * null`, `promedio-ponderado.ts` hace `if (nota.nota === null …) { continue; }`, y la
 * app vieja evalúa `Number(null) === 0`—: **el backend era el único que mandaba un
 * cero**.
 *
 * ## LO QUE ESTA MIGRACIÓN NO HACE, Y ES LA MITAD DE SU SEGURIDAD
 *
 * **No toca los periodos cerrados.** El `UPDATE` de abajo filtra por
 * `profes_pueden_editar_notas = 1`, que es lo que el colegio apaga al cerrar y la
 * única marca de «cerrado» que existe hoy. Sin ese filtro, el primer recálculo de un
 * periodo cerrado dejaría de contar sus huecos y **movería definitivas que ya se
 * imprimieron y se firmaron** — y el recálculo no es hipotético: lo dispara cualquier
 * escritura en la asignatura, vía `DefinitivasDeAsignatura::recalcular`.
 *
 * En la copia de desarrollo, el reparto:
 *
 * | población | filas |
 * |---|---|
 * | notas vivas | 1.166.608 |
 * | en periodos **cerrados** — no se tocan | 1.053.592 |
 * | en periodos **abiertos** | 49.884 |
 * | **las que pasan a `NULL`** | **20.655 — el 1,8 % de la tabla** |
 *
 * En los cerrados hay **46.482** filas que cumplen el criterio y **se quedan en 0 a
 * propósito**. Que un periodo cerrado no cambie no depende de que nadie lo recalcule:
 * depende de que **el `UPDATE` no lo alcanza**.
 *
 * ## EL CRITERIO DEL RELLENO ES UN PROXY, Y POR ESO VA ESCRITO AQUÍ
 *
 * `updated_by IS NULL AND created_at <=> updated_at` significa *«nadie ha tocado esta
 * fila desde que se sembró»*. No es una columna que alguien escribiera con esta
 * intención: es una huella. Medido sobre el 1,17 M de filas vivas, de los **102.401**
 * ceros **98.461 (96,2 %)** nunca los tocó nadie y **3.940 (3,8 %)** los tecleó un
 * docente — y esos 3.940 **se quedan en 0**, que es lo que tienen que hacer.
 *
 * El proxy se comprobó **año por año** antes de usarlo, porque una columna que no
 * existiera en los años viejos lo rompería en silencio: el porcentaje de filas sin
 * `updated_by` va del 5,3 % al 10,9 % en los siete años completos, 45 % en 2025 (a
 * medio calificar) y 90 % en 2026 (recién sembrado). **No hay ningún año en que la
 * columna falte del todo.**
 *
 * > **Y no se usa para nada más que este relleno.** A partir de aquí la pregunta
 * > *«¿está calificada?»* se contesta con `nota IS NULL`, que sí es una declaración.
 * > Un proxy sirve para reconstruir el pasado una vez; dejarlo gobernando el futuro es
 * > lo que hace que una cifra envejezca sin que nada se ponga rojo.
 *
 * ## EL `ALTER` HAY QUE MEDIRLO CONTRA MARIADB, Y AQUÍ NO SE PUEDE
 *
 * `ALGORITHM=INPLACE, LOCK=NONE` sobre las 1.166.608 filas tardó **8 s** — **en el
 * MySQL 8.0.42 del docker**. Producción corre **MariaDB 10.5.25** (`SELECT
 * VERSION()`, Joseth, 5 sep 2026), y ahí ni el algoritmo ni el tiempo están medidos.
 * Antes de desplegar esto en los dieciséis: `tools/ensayo-de-la-tanda.sh` sobre copia
 * de un colegio de verdad. **No se escribe `ALGORITHM=INPLACE` en el `ALTER`**: si
 * MariaDB no puede hacerlo así, con la cláusula puesta la migración **falla**, y sin
 * ella cae a copia y termina. Un despliegue a las tres de la mañana prefiere lento a
 * roto.
 *
 * ## EL `NOT NULL` ESTABA HACIENDO DE GUARDA POR ACCIDENTE
 *
 * Esto es lo que **no** se puede desplegar solo, y por eso va en el mismo commit que
 * `NotasController::putUpdate`. Aquel método hace `UPDATE notas SET nota = ?` con
 * `Request::input('nota')`, que devuelve `null` **tanto si vino vacía como si no
 * vino**. Hoy eso es inofensivo *por accidente*: contra una columna `NOT NULL`, un
 * cuerpo sin `nota` aborta en producción (MariaDB estricto, `1048`). **En cuanto la
 * columna admita nulos, ese error desaparece y se convierte en un borrado
 * silencioso.** Quitar un `NOT NULL` no es sólo permitir un valor más: es retirarle la
 * última validación a los que no validan.
 */
return new class extends Migration
{
    public function up()
    {
        // Sin `ALGORITHM`/`LOCK` a propósito: ver la cabecera. Que MariaDB elija.
        DB::statement('ALTER TABLE notas MODIFY COLUMN nota int NULL DEFAULT NULL');

        // Sólo lo que vive en un periodo ABIERTO. El `JOIN` a `periodos` es el filtro
        // entero de seguridad de esta migración, no una comodidad para acotar.
        //
        // Las tres tablas del camino llevan `deleted_at IS NULL` porque una nota cuya
        // subunidad se borró no tiene periodo del que preguntar, y con `INNER JOIN` se
        // queda fuera — que es lo correcto: si no hay periodo, no hay forma de saber si
        // está abierto, y ante la duda no se toca.
        $vaciadas = DB::update(
            'UPDATE notas n
               INNER JOIN subunidades s ON s.id = n.subunidad_id AND s.deleted_at IS NULL
               INNER JOIN unidades    u ON u.id = s.unidad_id    AND u.deleted_at IS NULL
               INNER JOIN periodos    p ON p.id = u.periodo_id   AND p.deleted_at IS NULL
                 SET n.nota = NULL
               WHERE n.deleted_at IS NULL
                 AND p.profes_pueden_editar_notas = 1
                 AND n.updated_by IS NULL
                 AND n.created_at <=> n.updated_at'
        );

        // Se imprime porque **es el único número de esta migración que nadie puede
        // recalcular después**: en cuanto las filas están en NULL, el criterio que las
        // eligió ya no distingue nada. Quien despliegue lo apunta por colegio; en la
        // copia de desarrollo fueron 20.655.
        echo "  notas vaciadas (periodos abiertos, nunca tocadas): {$vaciadas}\n";
    }

    public function down()
    {
        // **El `down` no devuelve las notas a donde estaban, y no puede.** Un `NULL` de
        // aquí sale de una fila que valía `nota_default` —casi siempre 0, a veces 40—, y
        // esa información se perdió al vaciarla. Lo único honesto es dejarlas en 0, que
        // es el valor con el que la columna vuelve a ser `NOT NULL`, y decirlo:
        // revertir esto **no restaura el estado anterior**, lo aproxima.
        //
        // Se escribe igual porque sin `down` la tanda entera deja de poder revertirse, y
        // porque 0 es exactamente lo que esas filas aportaban a la definitiva antes.
        DB::update('UPDATE notas SET nota = 0 WHERE nota IS NULL');

        DB::statement('ALTER TABLE notas MODIFY COLUMN nota int NOT NULL DEFAULT 0');
    }
};
