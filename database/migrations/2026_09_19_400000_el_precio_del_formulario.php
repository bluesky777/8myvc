<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * **Cuánto cuesta el formulario de inscripción de esta campaña.**
 *
 * Decidido por Joseth el 19 sep 2026 entre tres formas, con el precio de cada una
 * delante (`docs/migracion/41-el-formulario-de-inscripcion.md` §7): un precio por
 * campaña que el colegio pone una vez, frente a teclearlo en cada impresión o a no
 * cobrar.
 *
 * ## POR QUÉ ESTA COLUMNA EXISTE: PORQUE `ordenes_inscripcion.valor` NO LA ESCRIBÍA NADIE
 *
 * Aquella columna entró el mismo día con el comentario *«el código queda atado a un
 * cobro: cuánto, quién lo vendió y cuándo»* y se escribieron **dos de las tres**: el
 * `INSERT` que acuña no la nombraba, y en todo `app/` no había otra escritura. La
 * bandeja del tesorero ya la **leía**, así que enseñaba `null` en los diecisiete, y
 * el checkout del pago en línea —construido encima— no tenía importe que cobrar.
 *
 * Es `profesores.tono` otra vez, y lo que lo destapó **no fue ningún barrido**:
 * `interruptores-que-nadie-lee.py` mira `tinyint(1)` y esto es un `int`, así que no
 * podía verlo ni corriéndolo. Lo destapó **que la función siguiente necesitó el dato
 * y no estaba**. Merece quedar escrito: construir encima encuentra huecos que un
 * detector no busca, porque el detector sólo enumera las formas que alguien ya
 * imaginó.
 *
 * ## VA DONDE VAN LOS CAMPOS, Y NO ES COMODIDAD
 *
 * Misma tabla, misma fila, misma clave (`year_id`) y la misma ruta de escritura, así
 * que **no gasta ninguna ruta nueva**. La alternativa —una tabla o una ruta propia
 * para un entero— haría que las dos mitades de la misma pantalla de configuración se
 * guardaran por caminos distintos, que es exactamente el tipo de asimetría que un día
 * hace que alguien guarde una y pierda la otra.
 *
 * **La clave es `year_id` y no `year_campana`, igual que los campos**, y conviene
 * decir por qué aunque el precio sea «de la campaña»: `year_campana` puede ser un año
 * cuya fila de `years` **todavía no existe** —el colegio abre la campaña antes de
 * crear el año—, así que no se le puede colgar una clave ajena. Se configura desde el
 * año en el que se está trabajando, que es el que siempre existe.
 *
 * ## Y EL PRECIO SE **ESTAMPA**, NO SE REFERENCIA
 *
 * Al acuñar, este valor se copia a `ordenes_inscripcion.valor` y allí se queda. Es la
 * mitad que hace que esto sea correcto y no sólo cómodo: **subir el precio en marzo no
 * puede reescribir lo que se vendió en enero**. Una clave ajena a esta fila haría
 * justo eso —cambiaría el histórico de lo ya cobrado al editar la configuración— y el
 * síntoma sería que la bandeja del tesorero enseña un importe distinto del que la
 * familia pagó, meses después y sin nada que lo explique.
 *
 * Dicho al revés: *esta columna es lo que el colegio cobra a partir de ahora; la de
 * `ordenes_inscripcion` es lo que cobró.* Son dos cosas y por eso son dos columnas.
 */
return new class extends Migration
{
    public function up()
    {
        Schema::table('config_formulario_inscripcion', function (Blueprint $tabla) {
            // En pesos enteros, como `ordenes_inscripcion.valor`, y no en centavos:
            // los centavos son cosa de la pasarela y la conversión vive en el
            // checkout, en un solo sitio. Colombia no usa decimales en el precio de
            // un formulario.
            //
            // Anulable porque **no tenerlo es un estado legítimo**: el colegio que
            // todavía no ha decidido el precio no es el mismo que el que puso cero, y
            // el checkout los trata igual sólo porque en los dos casos no hay nada que
            // cobrar.
            $tabla->unsignedInteger('valor')->nullable()->after('campos');
        });
    }

    public function down()
    {
        Schema::table('config_formulario_inscripcion', function (Blueprint $tabla) {
            $tabla->dropColumn('valor');
        });
    }
};
