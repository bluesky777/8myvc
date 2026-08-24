<?php

/**
 * Qué se queda fuera de la pantalla «qué se tocó en esta sesión», y por qué.
 *
 * Uso (dentro del contenedor):
 *
 *     docker exec 8myvc-app-1 php tools/historial-que-cuenta-de-menos.php
 *     docker exec -e DB_DATABASE=otrocolegio 8myvc-app-1 php tools/historial-que-cuenta-de-menos.php
 *
 * **Sólo lee.** Ni un `INSERT`, ni un `UPDATE`, ni un `DELETE`.
 *
 * ---
 *
 * ## La pregunta
 *
 * `PUT historiales/sesion` es la pantalla con la que el colegio contesta **«¿qué
 * tocó esta sesión?»** — la que pidió Joseth. Devuelve la fila de `historiales` y,
 * dentro, sus `bitacoras`. Esas bitácoras salen de **una** consulta
 * ([HistorialesController:135](../app/Http/Controllers/Historiales/HistorialesController.php#L135)):
 *
 *     SELECT b.*, a.nombres, a.apellidos, s.definicion FROM bitacoras b
 *       INNER JOIN alumnos a     ON b.affected_user_id = a.id AND a.deleted_at IS NULL
 *       INNER JOIN notas n       ON n.id = b.affected_element_id
 *       INNER JOIN subunidades s ON s.id = n.subunidad_id AND s.deleted_at IS NULL
 *      WHERE b.historial_id = ? AND b.deleted_at IS NULL
 *
 * Tres `INNER JOIN` y **ningún filtro por `affected_element_type`**. Eso tiene
 * cuatro consecuencias distintas y la herramienta las separa, porque **arreglar la
 * que no se ve no cambia lo que ve el usuario**:
 *
 * | # | Causa | Qué le pasa a la fila |
 * |---|---|---|
 * | 1 | la bitácora **no es de una nota** y se la une a `notas` igual | desaparece, o **sale atribuida a otra cosa** |
 * | 2 | la nota **ya no existe** | desaparece |
 * | 3 | la **subunidad** está borrada | desaparece |
 * | 4 | el **alumno** está borrado | desaparece |
 *
 * ## Y la que más duele es la 2, por una razón que no está a la vista
 *
 * `notas/destroy` es **`DELETE FROM notas WHERE id=?`**, un borrado **duro**, aunque
 * el modelo `Nota` use SoftDeletes
 * ([NotasController:756](../app/Http/Controllers/NotasController.php#L756)). Así que
 * al borrar una nota **la bitácora sobrevive y su nota no**, y el `INNER JOIN` la
 * pierde: la pantalla que existe para saber qué se tocó **se calla justo el caso
 * que más se reclama**.
 *
 * Y el borrado duro tiene una segunda consecuencia que afecta a esta misma
 * medición: **no deja rastro contable**. `COUNT(*) FROM notas WHERE deleted_at IS
 * NOT NULL` da **cero** y eso no significa «no se borran notas»: significa que las
 * borradas no están. **La causa 2 es la única de las cuatro cuya frecuencia no se
 * puede medir**, ni aquí ni en producción, y por eso se cuenta al revés — por las
 * bitácoras de tipo `Nota` cuya nota falta.
 *
 * ## Lo que este número es y lo que no es
 *
 * **La copia de desarrollo casi no tiene bitácoras con sesión** —se contaron 34— y
 * la mayoría las escribieron nuestras propias pruebas. Así que:
 *
 *   - **las proporciones de aquí NO son las de producción.** Se imprimen porque
 *     dicen qué causas están **activas**, no cada cuánto pasan;
 *   - **lo que sí generaliza son las precondiciones**, y ésas se miden sobre las
 *     tablas grandes: cuántas notas cuelgan de una subunidad borrada, cuántos
 *     alumnos están borrados, cuántos tipos de bitácora distintos se guardan.
 *
 * El `for` de los dieciséis colegios queda escrito al final y **no se corre desde
 * aquí**: es servidor.
 */

require __DIR__.'/../vendor/autoload.php';

// **A este fichero NO se le pasa Pint, y no es un descuido**: `tools/` no está en
// la lista del `composer.json` —sólo `app/` parcial, `routes`, `tests` y las
// migraciones— y aquí Pint hace daño. Su regla `fully_qualified_strict_types`
// acorta esta clase a `Kernel::class` y añade el `use` **debajo** de la línea que
// la usa; PHP lo resolvería igual porque los `use` no son sentencias, pero el
// contenedor recibe la cadena literal y revienta con `Class "Kernel" does not
// exist`. Cualificada entera, como en `coste-del-recalculo.php`.
$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$opciones = getopt('', ['help']);

if (isset($opciones['help'])) {
    echo file_get_contents(__FILE__, false, null, 0, 3400), PHP_EOL;
    exit(0);
}

$base = DB::connection()->getDatabaseName();

echo PHP_EOL."La sesión que cuenta de menos — base `{$base}`";
echo PHP_EOL.str_repeat('=', 86).PHP_EOL;

/* ── Población ────────────────────────────────────────────────────────────── */

$p = DB::selectOne('SELECT
    (SELECT COUNT(*) FROM historiales WHERE deleted_at IS NULL) AS historiales,
    (SELECT COUNT(*) FROM bitacoras WHERE deleted_at IS NULL) AS bitacoras,
    (SELECT COUNT(*) FROM bitacoras WHERE deleted_at IS NULL
        AND historial_id IS NOT NULL AND historial_id > 0) AS con_sesion,
    (SELECT COUNT(DISTINCT historial_id) FROM bitacoras WHERE deleted_at IS NULL
        AND historial_id IS NOT NULL AND historial_id > 0) AS sesiones_con_algo');

echo PHP_EOL.'POBLACIÓN';
echo PHP_EOL.sprintf('  sesiones anotadas (`historiales`) ............ %s', numero($p->historiales));
echo PHP_EOL.sprintf('  bitácoras vivas .............................. %s', numero($p->bitacoras));
echo PHP_EOL.sprintf('  de ésas, con una sesión detrás ............... %s', numero($p->con_sesion));
echo PHP_EOL.sprintf('  sesiones que tienen alguna bitácora .......... %s de %s',
    numero($p->sesiones_con_algo), numero($p->historiales));

// Un cero aquí no es «no pasa nada»: es «esta base no puede contestar». Se dice,
// porque la lectura contraria es la que hace archivar el asunto.
if ((int) $p->con_sesion === 0) {
    echo PHP_EOL.PHP_EOL.'  Ninguna bitácora tiene sesión detrás en esta base, así que **esta base no puede';
    echo PHP_EOL.'  contestar la pregunta**. No es que la pantalla esté bien: es que no hay nada';
    echo PHP_EOL.'  que enseñar. Las precondiciones de abajo sí valen.'.PHP_EOL;
}

/* ── El reparto, bitácora a bitácora ──────────────────────────────────────── */

// Las cuatro causas se atribuyen **en el orden en que la consulta las aplica**, y
// una fila puede caer por más de una. Se cuenta la primera que la tumba, y el
// orden va impreso: sin él, «14 por el alumno» y «14 por la nota» sumarían 28 de
// 14 filas y el reparto no cuadraría con el total.
$reparto = DB::select('SELECT b.affected_element_type AS tipo, COUNT(*) AS total,
        SUM(a.id IS NOT NULL AND n.id IS NOT NULL AND s.id IS NOT NULL) AS vuelve,
        SUM(a.id IS NULL) AS por_alumno,
        SUM(a.id IS NOT NULL AND n.id IS NULL) AS por_nota,
        SUM(a.id IS NOT NULL AND n.id IS NOT NULL AND s.id IS NULL) AS por_subunidad,
        SUM(b.affected_element_type <> "Nota"
            AND a.id IS NOT NULL AND n.id IS NOT NULL AND s.id IS NOT NULL) AS mal_atribuida,
        SUM(b.affected_element_type <> "Nota"
            AND n.id IS NOT NULL AND s.id IS NOT NULL) AS a_un_paso
      FROM bitacoras b
      LEFT JOIN alumnos a ON b.affected_user_id = a.id AND a.deleted_at IS NULL
      LEFT JOIN notas n ON n.id = b.affected_element_id
      LEFT JOIN subunidades s ON s.id = n.subunidad_id AND s.deleted_at IS NULL
     WHERE b.deleted_at IS NULL AND b.historial_id IS NOT NULL AND b.historial_id > 0
     GROUP BY b.affected_element_type
     ORDER BY total DESC');

if ($reparto !== []) {
    echo PHP_EOL.PHP_EOL.'QUÉ LE PASA A CADA BITÁCORA DE UNA SESIÓN';
    echo PHP_EOL.'  (las causas se atribuyen en el orden en que la consulta las aplica:';
    echo PHP_EOL.'   alumno, luego nota, luego subunidad — una fila puede fallar por varias)';
    echo PHP_EOL.PHP_EOL.sprintf('  %-30s %7s %7s %8s %7s %11s', 'tipo', 'total', 'vuelve', 'alumno', 'nota', 'subunidad');
    echo PHP_EOL.'  '.str_repeat('-', 76);

    $t = ['total' => 0, 'vuelve' => 0, 'por_alumno' => 0, 'por_nota' => 0,
        'por_subunidad' => 0, 'mal_atribuida' => 0, 'a_un_paso' => 0];

    foreach ($reparto as $fila) {
        echo PHP_EOL.sprintf('  %-30s %7d %7d %8d %7d %11d',
            substr((string) $fila->tipo, 0, 30), $fila->total, $fila->vuelve,
            $fila->por_alumno, $fila->por_nota, $fila->por_subunidad);

        foreach ($t as $k => $_) {
            $t[$k] += (int) $fila->{$k};
        }
    }

    echo PHP_EOL.'  '.str_repeat('-', 76);
    echo PHP_EOL.sprintf('  %-30s %7d %7d %8d %7d %11d', 'TOTAL',
        $t['total'], $t['vuelve'], $t['por_alumno'], $t['por_nota'], $t['por_subunidad']);

    $fuera = $t['total'] - $t['vuelve'];

    echo PHP_EOL.PHP_EOL.sprintf('  De las %d bitácoras de una sesión, la pantalla enseña %d y se calla %d (%d%%).',
        $t['total'], $t['vuelve'], $fuera,
        $t['total'] > 0 ? (int) round(100 * $fuera / $t['total']) : 0);

    /* La mala atribución, que es lo que NO es contar de menos. */
    echo PHP_EOL.PHP_EOL.'  Y la otra mitad, la que no se ve por contar filas:';
    echo PHP_EOL.sprintf('    bitácoras que NO son de una nota y vuelven igual (mal atribuidas) ... %d', $t['mal_atribuida']);
    echo PHP_EOL.sprintf('    ídem, a las que sólo las salva el join de `alumnos` .................. %d', $t['a_un_paso'] - $t['mal_atribuida']);

    if ($t['mal_atribuida'] === 0 && $t['a_un_paso'] > 0) {
        echo PHP_EOL.PHP_EOL.'  **Hoy no sale ninguna mal atribuida, y no es por diseño.** Las de arriba';
        echo PHP_EOL.'  pasan los joins de `notas` y `subunidades` —o sea que su `affected_element_id`,';
        echo PHP_EOL.'  que es un id de OTRA tabla, existe en `notas`— y lo único que las tumba es que';
        echo PHP_EOL.'  su `affected_user_id` no sea un alumno vivo. El día que una bitácora que no es';
        echo PHP_EOL.'  de nota lleve un alumno vivo —y `AcudientePideAjeno:alumno_id` **lleva un';
        echo PHP_EOL.'  alumno**— saldrá en la pantalla con el nombre y la subunidad de otra cosa.';
    }
}

/* ── Las precondiciones, que sí generalizan ───────────────────────────────── */

$pre = DB::selectOne('SELECT
    (SELECT COUNT(*) FROM notas) AS notas,
    (SELECT COUNT(*) FROM notas WHERE deleted_at IS NOT NULL) AS notas_marcadas,
    (SELECT COUNT(*) FROM notas n INNER JOIN subunidades s ON s.id = n.subunidad_id
        WHERE s.deleted_at IS NOT NULL) AS notas_en_sub_borrada,
    (SELECT COUNT(*) FROM alumnos) AS alumnos,
    (SELECT COUNT(*) FROM alumnos WHERE deleted_at IS NOT NULL) AS alumnos_borrados,
    (SELECT COUNT(DISTINCT affected_element_type) FROM bitacoras WHERE deleted_at IS NULL) AS tipos');

echo PHP_EOL.PHP_EOL.'LAS PRECONDICIONES — esto sí generaliza, no depende de cuántas bitácoras haya';
echo PHP_EOL.'  '.str_repeat('-', 76);
echo PHP_EOL.sprintf('  causa 1 · tipos distintos de bitácora que se guardan ......... %s', numero($pre->tipos));
echo PHP_EOL.'            y la consulta une TODOS con `notas`, sin filtrar por tipo';
echo PHP_EOL.sprintf('  causa 3 · notas que cuelgan de una subunidad borrada ......... %s de %s (%s%%)',
    numero($pre->notas_en_sub_borrada), numero($pre->notas),
    $pre->notas > 0 ? number_format(100 * $pre->notas_en_sub_borrada / $pre->notas, 2, ',', '.') : '0');
echo PHP_EOL.'            toda bitácora de una de esas notas desaparece';
echo PHP_EOL.sprintf('  causa 4 · alumnos borrados .................................. %s de %s (%s%%)',
    numero($pre->alumnos_borrados), numero($pre->alumnos),
    $pre->alumnos > 0 ? number_format(100 * $pre->alumnos_borrados / $pre->alumnos, 2, ',', '.') : '0');
echo PHP_EOL.'            toda bitácora de un alumno borrado desaparece';
echo PHP_EOL.PHP_EOL.sprintf('  causa 2 · notas con `deleted_at` puesto ..................... %s   <- NO es la medida',
    numero($pre->notas_marcadas));
echo PHP_EOL.'            `notas/destroy` es un DELETE **duro**, así que una nota borrada no';
echo PHP_EOL.'            deja fila que contar. Un cero aquí no dice que no se borren notas:';
echo PHP_EOL.'            dice que las borradas no están. **Es la única de las cuatro cuya';
echo PHP_EOL.'            frecuencia no se puede medir**, ni aquí ni en el servidor.';

echo PHP_EOL.PHP_EOL.'CUÁL SE VE ANTES';
echo PHP_EOL.'  '.str_repeat('-', 76);
echo PHP_EOL.'  **La 1, el tipo, y por mucho.** Se ve en toda sesión que haga algo que no sea';
echo PHP_EOL.'  teclear notas —abrir un boletín, crear una subunidad, pedir algo ajeno— o sea';
echo PHP_EOL.'  en casi todas. Las otras tres necesitan que alguien haya borrado algo.';
echo PHP_EOL.PHP_EOL.'  Y por eso el orden importa: **arreglar los `INNER JOIN` sin poner el filtro por';
echo PHP_EOL.'  tipo no cambia lo que ve el usuario** en la mayoría de las sesiones. La frase';
echo PHP_EOL.'  que se lleva es que la pantalla no cuenta de menos LAS NOTAS —ésas las cuenta';
echo PHP_EOL.'  bien— sino LA SESIÓN, que es lo que dice su nombre.';

echo PHP_EOL.PHP_EOL.'PARA LOS DIECISÉIS — se deja escrito, no se corre desde aquí';
echo PHP_EOL.'  '.str_repeat('-', 76);
echo PHP_EOL.'  for c in colegio1 colegio2 ... colegio16; do';
echo PHP_EOL.'      echo "== $c"; DB_DATABASE=$c php tools/historial-que-cuenta-de-menos.php;';
echo PHP_EOL.'  done';
echo PHP_EOL.PHP_EOL;

function numero(int|string|null $n): string
{
    return number_format((int) $n, 0, ',', '.');
}
