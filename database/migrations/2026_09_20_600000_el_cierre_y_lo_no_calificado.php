<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * **Qué pasa al CERRAR el periodo con las casillas que nadie calificó.**
 *
 * Fase 4 de `docs/migracion/43-lo-que-todavia-no-se-ha-calificado.md`, decisión
 * **D3** de Joseth, autorizada el 20 sep 2026. Es la fase que impide que el arreglo
 * de la fase 0 se convierta en un agujero: **dejar de contar lo no calificado
 * durante el periodo es correcto; dejar de contarlo al cerrar es aprobar a quien no
 * entregó.** Es literalmente el aviso de Moodle que cita la §4 del documento.
 *
 * ```
 * years.cierre_sin_calificar    enum('cero','fuera','bloquear') NOT NULL DEFAULT 'cero'
 * periodos.cierre_sin_calificar enum('cero','fuera')            NULL     DEFAULT NULL
 * ```
 *
 * ## SON DOS COLUMNAS Y EL PLAN DECÍA UNA — y la segunda no es comodidad
 *
 * El plan de la §6 dice «2 rutas y **1** columna en `years`». Las rutas son dos; las
 * columnas son dos, y la de más es la que hace cumplir por mecanismo la regla que el
 * encargo pone por encima de todo: *un periodo ya cerrado no puede moverse por
 * esto*.
 *
 * `years.cierre_sin_calificar` es **la elección del rector**, y cambia cuando él
 * quiera. Si el cálculo la leyera, el día que un rector la moviera **se moverían las
 * definitivas de todos los periodos que ese año ya tiene cerrados** — boletines
 * impresos y firmados— sin que nadie tocara una nota. O sea que la elección no puede
 * ser lo que gobierna el cálculo de un periodo cerrado: lo que lo gobierna tiene que
 * ser **lo que se aplicó el día que se cerró**, y eso hay que congelarlo en algún
 * sitio. El sitio es el periodo, porque es de quien es el hecho.
 *
 * Es la forma exacta que ya tiene `notas_finales.nota_original` frente a la escala
 * del año, y la misma razón por la que `modelo_evaluacion` es del año y no del
 * colegio: **un año cerrado conserva el suyo para siempre**.
 *
 * `NULL` en `periodos` es un estado y no un hueco: *«este periodo no se ha cerrado
 * nunca por este camino»*. Son **todos** los periodos de los dieciséis el día del
 * despliegue, y con él el cálculo se comporta **byte por byte como hoy**.
 *
 * ## El `enum` de `periodos` tiene DOS valores y el de `years` tres, a propósito
 *
 * `bloquear` **no se aplica nunca**: es *«no dejes cerrar»*, así que su resultado es
 * que no hay cierre. Un periodo no puede quedar congelado en `bloquear` porque en
 * ese caso no llega a cerrarse. Dejar los tres valores en la columna de `periodos`
 * habría hecho representable un estado que no existe, y el día que alguien lo
 * encontrara escrito no habría forma de saber si es un bug o una decisión.
 *
 * ## El valor de fábrica es `cero`, y eso NO es lo mismo que la decisión
 *
 * Un interruptor nuevo nace con un valor en los **dieciséis colegios a la vez**, y
 * ese valor no lo ha elegido ningún rector: lo elige quien escribe esta migración.
 * Como hoy el cierre pone cero —la casilla vacía aporta lo mismo que un cero a la
 * definitiva, que no normaliza—, el defecto **tiene que ser `cero`**. Si naciera en
 * `fuera`, desplegar cambiaría el cierre de los dieciséis sin que nadie lo hubiera
 * pedido.
 *
 * Es literalmente el argumento de `RepartoDeLaNota::modoDelAnio`: *«el defecto no es
 * prudencia genérica: `porcentaje` es el comportamiento de hoy»*. **Elegir la opción
 * recomendada como valor de fábrica habría sido tomar la decisión de los dieciséis
 * rectores desde una migración.** No se re-litiga.
 *
 * ## Lo que esta migración NO hace, y ninguna de las dos cosas es un olvido
 *
 * 1. **No toca una sola nota ni una sola definitiva.** Es aditiva pura. Con las dos
 *    columnas puestas y nadie eligiendo nada, el sistema calcula y escribe
 *    exactamente lo que escribía ayer — y eso es comprobable: `periodos
 *    .cierre_sin_calificar` nace `NULL` en las 36 filas, que es la rama «como hoy»
 *    de `CierreDeLoNoCalificado::normalizaLaDefinitiva`.
 * 2. **No rellena hacia atrás.** Un periodo cerrado en 2021 no se marca como
 *    `'cero'` aunque de hecho su cierre puso ceros. Marcarlo sería afirmar que
 *    alguien tomó esa decisión, y no la tomó nadie: la tomó el `NOT NULL` de
 *    `notas.nota`. `NULL` dice la verdad —*«no se cerró por este camino»*— y da el
 *    mismo resultado.
 *
 * ## Las instantáneas, contadas antes de correrla con `tools/lo-que-reparte-una-columna.py`
 *
 * **Veinte, no tres.** La de `years` mueve **6** —las que publican la fila entera:
 * `muestreo-years`, `muestreo-years-colegio`, `muestreo-years-trashed`,
 * `years-store`, `years-guardar-cambios` y `years-delete`— y la de `periodos` mueve
 * **16**, de las que dos son las mismas. Ése es el precio de la columna congelada y
 * va dicho aquí porque es la mitad que no se ve al proponerla: `periodos` viaja
 * dentro del boletín, del año y de media docena de informes con `SELECT *`.
 *
 * A cambio, los tres clientes **reciben el estado** sin que haya que inventarles una
 * ruta: una pantalla que quiera decir «este periodo se cerró dejando fuera lo no
 * calificado» ya tiene el dato en el JSON que carga.
 *
 * ## MariaDB 10.5, que es lo que corre en los dieciséis
 *
 * Dos `ADD COLUMN` con `DEFAULT`, que desde la 10.4 son instantáneos y no
 * reconstruyen la tabla. Nada de `JSON_TABLE`, `LATERAL` ni `->>`. `periodos` tiene
 * **36 filas** en la copia de desarrollo y `years` **9**: aunque cayera a `COPY` no
 * habría nada que medir. No es el caso de la fase 0, que reconstruía `notas` entera.
 *
 * ## Volver atrás
 *
 * Aditiva pura: `down()` quita las dos columnas y **no pierde ninguna nota**. Lo que
 * se pierde es qué eligió cada rector y cómo se cerró cada periodo — y con el código
 * viejo puesto eso no lo lee nadie, porque el cálculo vuelve a ser el de siempre.
 * Vale el «Paso 4» de `docs/DESPLIEGUE.md` tal cual.
 */
class ElCierreYLoNoCalificado extends Migration
{
    public function up()
    {
        Schema::table('years', function (Blueprint $tabla) {
            // Pegada a `reparto_subunidades` y a `modelo_evaluacion`, que son las
            // otras dos políticas del año que el colegio elige una vez y que
            // gobiernan lo que se escribe después. El sitio no es cosmético: este
            // proyecto lee con `SELECT *` por todas partes y las instantáneas de
            // contrato fijan **el orden de los campos**.
            $tabla->enum('cierre_sin_calificar', ['cero', 'fuera', 'bloquear'])
                ->default('cero')
                ->after('reparto_subunidades');
        });

        Schema::table('periodos', function (Blueprint $tabla) {
            // Pegada a los dos interruptores del periodo, que es con lo que se lee:
            // `profes_pueden_editar_notas` dice si está cerrado y ésta dice **cómo**
            // se cerró.
            $tabla->enum('cierre_sin_calificar', ['cero', 'fuera'])
                ->nullable()
                ->default(null)
                ->after('profes_pueden_nivelar');
        });
    }

    public function down()
    {
        Schema::table('years', function (Blueprint $tabla) {
            $tabla->dropColumn('cierre_sin_calificar');
        });

        Schema::table('periodos', function (Blueprint $tabla) {
            $tabla->dropColumn('cierre_sin_calificar');
        });
    }
}
