<?php

namespace App\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El ancla de un `->after()`, sólo si esa columna existe en ESTE colegio.
 *
 * **Existe por una caída del 20 sep 2026, a mitad del despliegue de los dieciséis.**
 * `2026_09_20_300000_el_dia_de_matriculas` añade `requisitos_matricula.bloquea` con
 * `->after('orden')`, y en `fortul` esa tabla **no tiene `orden`**:
 *
 *     SQLSTATE[42S22]: Unknown column 'orden' in 'requisitos_matricula'
 *
 * La migración era defensiva con lo que escribe —`if (! Schema::hasColumn(…, 'bloquea'))`—
 * y **no con el sitio donde lo pone**. Y ahí está la lección, que es la que hace falta
 * escribir: el esquema congelado de este repositorio es el de la copia de desarrollo, y
 * **los dieciséis han derivado**. Una columna que aquí lleva años puede no estar allí.
 *
 * **Lo que costó no fue la migración que falló, fue lo que venía detrás.** `migrate` se
 * para en la primera que revienta, así que ese colegio se quedó con el código nuevo y
 * SIETE migraciones sin correr — entre ellas `periodos.cierre_sin_calificar`, que
 * `DefinitivasDeAsignatura` nombra en un `SELECT` **cada vez que un docente guarda una
 * nota**. El síntoma no fue «no salió una columna en su sitio»: fue 500 al calificar.
 *
 * ## Por qué devuelve `null` y no lanza
 *
 * `after(null)` no emite cláusula: `modifyAfter` de la gramática hace `is_null` antes de
 * escribir nada. O sea que **sin ancla la columna se añade igual, al final de la tabla**,
 * que es exactamente lo que se quiere: el orden de las columnas es cosmético —ninguna
 * consulta de este repositorio depende de él, todas nombran las columnas— y **una tabla
 * con las columnas en otro orden es infinitamente mejor que un colegio sin migrar**.
 *
 * No se usa para elegir SI se añade la columna: eso lo sigue decidiendo el
 * `Schema::hasColumn` de cada migración, que es otra pregunta.
 *
 * ## Y lo que se pierde, dicho en vez de descubierto
 *
 * Un ancla que apunta a una columna **creada en el mismo `Schema::table`** —`cerrado_at`
 * detrás de `cerrado_por`, las cinco de nivelaciones en cadena— ahora contesta `null`:
 * cuando se construye el `Blueprint` esa columna todavía no está en la base. Esas
 * columnas se van al final de la tabla en vez de quedar en fila.
 *
 * **Es cosmético y se acepta a sabiendas.** Ninguna consulta de este repositorio depende
 * del orden: todas nombran las columnas. Lo que no es cosmético es un `migrate` que se
 * para a la mitad, y eso es lo que esto compra.
 */
class Ancla
{
    /** El nombre del ancla si la columna existe aquí; `null` si no, y entonces no hay `AFTER`. */
    public static function de(Blueprint $tabla, string $columna): ?string
    {
        return Schema::hasColumn($tabla->getTable(), $columna) ? $columna : null;
    }
}
