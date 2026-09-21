<?php

use App\Support\Ancla;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * **Cómo reparte el colegio el peso entre las subunidades: por porcentaje o por
 * promedio.** Y se guarda por año.
 *
 * Es la **fase 1** de la Entrega 5 de
 * `docs/migracion/28-competencias-e-indicadores.md` §5.5, sobre el encargo de
 * Joseth del 2 sep 2026: *«debería ser opcional que las subunidades se manejen con
 * porcentajes; que el colegio pueda elegir que sean tratados con promedios»*.
 * Autorizada por él el 14 sep junto con la fase 0 y la D30.
 *
 * ```
 * reparto_subunidades  enum('porcentaje','promedio') NOT NULL DEFAULT 'porcentaje'
 * ```
 *
 * ## Es lo que le quita trabajo al docente, y por eso NO toca las unidades
 *
 * En modo promedio el docente **deja de teclear porcentajes** y desaparece la clase
 * entera de fallos de «esto no suma 100». El interruptor es **sólo de subunidades**,
 * y el motivo está escrito en el §5.5 y no es la pereza: los porcentajes de las
 * **unidades** los pone el colegio una vez al año en la plantilla, así que no le
 * cuestan nada al docente; los de las **subunidades** los teclea él en cada
 * asignatura. *Se quita el que cuesta.*
 *
 * ## Por año, como `modelo_evaluacion`, y por la misma razón
 *
 * Un año cerrado **conserva el suyo para siempre**. Sin eso, encender el promedio
 * en 2027 recalcularía lo que dicen los boletines de 2024 la próxima vez que se
 * impriman — que es exactamente lo que este repositorio lleva dos días cerrando por
 * otras puertas (`docs/migracion/16-escribir-en-un-anio-pasado.md`).
 *
 * Se coloca **pegada a `modelo_evaluacion`**, que a su vez está pegada a
 * `regla_nivelacion`: son las tres políticas del año que el colegio elige una vez y
 * que gobiernan lo que se escribe después. El sitio no es cosmético — este proyecto
 * lee con `SELECT *` por todas partes y las instantáneas de contrato fijan **el
 * orden de los campos**.
 *
 * ## `NOT NULL` con `DEFAULT 'porcentaje'`, y eso es una afirmación
 *
 * Afirma que **hoy los dieciséis reparten por porcentaje**, que es cierto por
 * construcción: lo otro no existe todavía. Y hace que **todos los años pasados
 * sigan exactamente igual** sin tocar una fila.
 *
 * No nace `NULL` por lo mismo que las cuatro de `modelo_evaluacion`: una política
 * que decide cómo se calcula una nota no puede tener un estado «todavía no se
 * sabe», porque el `if` que la lee tendría que inventarse una tercera rama.
 *
 * ## Lo que esta migración NO hace, y no es un olvido
 *
 * 1. **No recalcula ni borra nada.** Cambiar el enum no toca una nota, una
 *    definitiva ni una frase. Es lo que hace que se pueda desplegar a los dieciséis
 *    sin avisar, y **volver atrás es cambiar el enum**.
 * 2. **No toca `subunidades.porcentaje`**, ni la borra ni la pone a 0 (doc 28 §5.5,
 *    y es la D20). En modo promedio simplemente **deja de gobernar**, y el valor
 *    que cada colegio tenía escrito sigue ahí — así que apagar el interruptor
 *    devuelve el reparto exacto que había.
 *
 *    > Eso es justo lo que permite que la **D30** funcione sin migrar datos: lo que
 *    > cambia es **lo que se sirve**, no lo que se guarda.
 * 3. **No siembra ningún permiso.** El día que haya pantalla, la gobierna
 *    `can_edit_plantilla_notas`, que ya existe desde `2026_09_05_300000`.
 *
 * ## Las instantáneas que mueve, contadas antes de correrla
 *
 * **Tres**, y son la terna que `docs/migracion/35-...md` §1.3 dejó medida: los
 * únicos tres caminos que publican **la fila entera** de `years` son
 * `YearsController::getIndex` y `getColegio` (comodín en SQL crudo) y `getTrashed`
 * (Eloquent). Las otras que mencionan columnas de `years` llevan **proyecciones
 * nombradas** y son inmunes.
 *
 * ## Volver atrás
 *
 * Aditiva pura: `down()` quita la columna y **no pierde ninguna nota**. Lo único
 * que se pierde es qué reparto había elegido cada colegio, que al volver el código
 * viejo tampoco lo lee nadie. Vale el «Paso 4» de `docs/DESPLIEGUE.md` tal cual.
 */
class RepartoDeLasSubunidades extends Migration
{
    public function up()
    {
        Schema::table('years', function (Blueprint $tabla) {
            $tabla->enum('reparto_subunidades', ['porcentaje', 'promedio'])
                ->default('porcentaje')
                ->after(Ancla::de($tabla, 'modelo_evaluacion'));
        });
    }

    public function down()
    {
        Schema::table('years', function (Blueprint $tabla) {
            $tabla->dropColumn('reparto_subunidades');
        });
    }
}
