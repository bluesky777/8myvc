<?php

use App\Support\Ancla;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * **¿Puede el docente cambiar lo que el colegio puso en la plantilla?** Lo decide
 * el colegio, y hasta hoy no podía decidirlo.
 *
 * ```
 * years.profes_pueden_editar_plantilla  tinyint(1) NOT NULL DEFAULT 0
 * ```
 *
 * Decisión de Joseth del 22 sep 2026, y **corrige la suya del 19**. `P6` —*«lo que
 * el colegio puso en la plantilla no lo cambia un docente»*— se escribió como una
 * regla del producto, sin interruptor, y `App\Support\CandadoDeLaPlantilla` la hace
 * cumplir mirando `por_defecto = 1`. El 21 sep los colegios reportaron que sus
 * docentes no podían cambiar el porcentaje de subunidades con las que llevaban
 * meses trabajando; se revisó, se dejó puesto a sabiendas y se apuntó *«si hay que
 * revisarlo, se revisa con una medición delante»*. Lo que faltaba no era la
 * medición: era que **la regla no es la misma en los dieciséis colegios**, y por eso
 * no le toca al código elegirla.
 *
 * ## Es la CUARTA política del año, y por eso va aquí
 *
 * Al lado de `modelo_evaluacion`, `reparto_subunidades` y `cierre_sin_calificar`:
 * del **año** y no del colegio ni del grupo (D1), porque un año cerrado tiene que
 * conservar la suya para siempre — quien mire en 2028 por qué una unidad de 2026
 * tiene el porcentaje que tiene necesita saber quién podía tocarla entonces. Se
 * escribe por `PUT years/modelo-evaluacion`, que ya es la ruta de las dos primeras
 * y ya exige `can_edit_plantilla_notas` (D13: **cero permisos nuevos**), y se copia
 * al año siguiente en `YearsController::postStore` por lo mismo que
 * `regla_nivelacion`: el colegio que lo encendió no amanece con el defecto cada
 * enero.
 *
 * ## El valor de fábrica es 0, y NO es lo mismo que la decisión
 *
 * 0 es **el comportamiento de hoy**: el candado sigue frenando y desplegar esto no
 * cambia nada en ningún colegio. Naciendo en 1, el despliegue abriría la plantilla
 * en los dieciséis a la vez sin que ningún rector lo hubiera pedido — que es
 * exactamente el error que esta columna existe para no volver a cometer, sólo que
 * entrando por la otra puerta.
 *
 * Los colegios que se quejaron el 21 sep siguen quejándose hasta que alguien lo
 * encienda. **Eso es lo elegido**, y es la diferencia entre una regla y un defecto:
 * la regla la puso el código y no se podía discutir; el defecto se cambia en una
 * pantalla.
 *
 * ## Qué abre, exactamente
 *
 * Con la columna en 1, una fila con `por_defecto = 1` se comporta **como una del
 * docente**: cambiarle el nombre y el porcentaje, borrarla y moverla de sitio.
 * Decisión de Joseth del 22 sep, sobre la alternativa de abrir sólo los dos campos
 * de `CandadoDeLaPlantilla::CAMPOS` — se descartó porque una fila que se puede
 * renombrar y repesar pero no borrar no es «del docente», es una fila con una
 * excepción que hay que explicar en la pantalla.
 *
 * `can_edit_plantilla_notas` **no se toca**: quien lo tenga sigue pasando con la
 * columna en 0, que es lo que ya hace. Son dos preguntas distintas —*«¿puede este
 * usuario?»* y *«¿abre este colegio la mano?»*— y se responden por separado.
 *
 * ## Volver atrás
 *
 * Aditiva pura: `down()` quita la columna y no pierde una nota. Lo que se pierde es
 * qué eligió cada colegio, y con el código viejo puesto eso no lo lee nadie —el
 * candado vuelve a frenar siempre, que es de donde venimos. Vale el «Paso 4» de
 * `docs/DESPLIEGUE.md` tal cual.
 */
class QuienEditaLaPlantilla extends Migration
{
    public function up()
    {
        Schema::table('years', function (Blueprint $tabla) {
            // Pegada a las otras tres políticas del año, que es con lo que se lee.
            // El sitio no es cosmético en este proyecto —se lee con `SELECT *` por
            // todas partes y las instantáneas de contrato fijan el orden de los
            // campos—, pero el ancla va por `Ancla::de` porque los dieciséis han
            // derivado y una columna que aquí lleva días puede no estar allí.
            $tabla->boolean('profes_pueden_editar_plantilla')
                ->default(false)
                ->after(Ancla::de($tabla, 'cierre_sin_calificar'));
        });
    }

    public function down()
    {
        Schema::table('years', function (Blueprint $tabla) {
            $tabla->dropColumn('profes_pueden_editar_plantilla');
        });
    }
}
