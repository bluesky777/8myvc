<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * El color del docente, decidido por Joseth el 4 sep 2026.
 *
 * Contrato en [23-horarios.md §9.bis.3](../../docs/migracion/23-horarios.md). Lo
 * pidió `myvc_horarios`: **`tono` hace falta para seis de los ocho informes** del
 * escritorio, y es «opcional para que salga una hoja y obligatorio para que salga
 * **la misma** hoja» — sin él la web pinta los colores cambiados y **nada se pone
 * rojo**.
 *
 * ## Por qué una columna y no las otras dos salidas
 *
 * La pregunta llegó aquí porque **`profesores` no tenía dónde poner un color**: sus
 * 27 columnas, medidas el 4 sep 2026, no incluyen ninguna. O sea que el hallazgo de
 * `myvc_horarios` —«ninguno de los 22 docentes trae `tono`»— y el de este lado **se
 * leían igual y eran cosas distintas**: allí el dato está previsto y vacío; aquí no
 * había dónde ponerlo. Las otras dos salidas las descartó Joseth: dejarlo declarado
 * como *«esta API no puede saberlo»* para siempre, y **leer el blob del proyecto para
 * extraer los colores** — que rozaba la decisión 12 (*listar no es descargar*) y era
 * la única que tocaba el fichero de proyecto.
 *
 * ## Nace vacía en los diecisiete, y el nulo seguirá siendo el caso NORMAL
 *
 * `nullable()` sin defecto: `NULL` es **«nadie ha repartido los colores todavía»**, y
 * eso va a ser cierto en todos los colegios el día que la columna salga. Por eso el
 * contrato de la cuarta ruta fija `tono` como `string | null` **y no como `string`**:
 * un tipo que no admita el nulo se rompe el primer día en los diecisiete a la vez.
 * *La decisión cambia dónde vive el dato, no que hoy no exista.*
 *
 * ## SIN `after`, y es a propósito
 *
 * `2026_09_04_100000_horario_versiones` metió su columna con
 * `->after('regla_nivelacion')` y con eso se ató a otra migración de su misma tanda:
 * `ADD COLUMN … AFTER x` con una `x` que no existe **falla**, así que no hay camino
 * «sólo esa migración». Aquí no hace falta ese orden —el sitio de una columna en la
 * tabla no lo lee nadie—, así que no se compra la dependencia. Va sola al final.
 *
 * ## Se reparte SOLA a SEIS respuestas vivas, y por eso se avisa al front
 *
 * Medido el 4 sep 2026 leyendo los `return` uno a uno: `ProfesoresController` devuelve
 * el modelo Eloquent entero en `postStore`, `putUpdate`, **`deleteDestroy`**,
 * **`deleteForcedelete`** y `putRestore`, y `GruposController::getShow` mete la ficha
 * del titular dentro del grupo. Ninguna de las seis nombra sus columnas, así que
 * **`tono` aparece en las seis sin que nadie la mande** — igual que le pasó a `years.horario_version_id` con los tres `SELECT *`
 * de `YearsController`. Vale `null` en todos, así que es lo más inofensivo que se le
 * puede mandar a los cuatro clientes, **pero es un campo nuevo y se manda dicho, no
 * descubierto**.
 *
 * Las estáticas del modelo (`detallado`, `fromyear`, `paraElegirEnAsignaturas`,
 * `contratos`, `asignaturas`) **nombran sus columnas una a una** y no se mueven — se
 * comprobaron las cinco. **`ProfesoresController::getShow` es de éstas y no de las de
 * arriba**: usa `Profesor::detallado()`, así que no trae `tono`.
 *
 * > **Este bloque decía «cinco» y nombraba un «`getShow` de papelera» que no existe.**
 * > Lo corrigió `8myvc-e0` el 4 sep 2026 leyendo los `return` uno a uno, que es lo que
 * > yo no hice: conté los sitios que **recordaba** en vez de los que hay. Las dos de la
 * > papelera son `deleteDestroy` y `deleteForcedelete`, y el `getShow` que sí existe es
 * > justo el que **no** reparte nada.
 *
 * ## Nadie la escribe todavía, y quien la escriba tiene que validar la longitud
 *
 * Esta tanda **sólo crea la columna**: no hay ninguna ruta que la ponga. El día que
 * exista, la longitud **se valida con 422 y no se deja truncar**: en MySQL no
 * estricto un valor más largo entra recortado y sin decir nada, y un color truncado
 * no da error — pinta otro color. Es la misma regla que `horario_lecciones.pieza_id`
 * (§5.1), donde el truncado fusionaba dos piezas en una.
 */
class TonoDelDocente extends Migration
{
    public function up()
    {
        Schema::table('profesores', function (Blueprint $tabla) {
            $tabla->string('tono', 32)->nullable();
        });
    }

    public function down()
    {
        Schema::table('profesores', function (Blueprint $tabla) {
            $tabla->dropColumn('tono');
        });
    }
}
