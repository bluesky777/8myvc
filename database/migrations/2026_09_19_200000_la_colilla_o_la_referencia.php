<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * La familia puede mandar **la foto del recibo O el número de referencia**, y no
 * está obligada a las dos.
 *
 * Idea de Joseth (19 sep 2026) al contestar dónde debía vivir el fichero: *«buena
 * idea de que también dé la opción de imagen de colilla o el código de
 * referencia»*. Es mejor que las dos que yo le había puesto, y por un motivo que no
 * es de comodidad: **el camino de la referencia no sube ningún fichero**, así que
 * para esa mitad de los casos no hay almacenamiento, ni URL que se escape, ni nada
 * que un desconocido pueda subir. La familia que sólo tiene el número no se queda
 * fuera, y la que tiene la foto conserva su prueba.
 *
 * ## POR QUÉ ES UNA SEGUNDA MIGRACIÓN Y NO UN ARREGLO DE LA PRIMERA
 *
 * `2026_09_19_100000` **ya está fundida en `main`**. Mientras estuvo sólo en la rama
 * se corrigió en el sitio —el año de la campaña— porque nadie la había corrido; a
 * partir de fundir, editarla rompe en silencio a cualquiera que ya la haya
 * ejecutado: su tabla no cambia y su `migrations` dice que está aplicada. **Migración
 * o no existe**, y a partir de `main` eso significa una migración NUEVA.
 *
 * ## El invariante va en la BASE, no en un `if`
 *
 * Una colilla sin fichero y sin referencia es una fila que el tesorero **no puede
 * resolver**: no hay nada que mirar. El controlador lo comprueba, pero un `if` es una
 * regla y las reglas se saltan —por un `INSERT` a mano, por un método nuevo dentro de
 * un año—, así que el `CHECK` lo cierra por mecanismo.
 *
 * `CHECK` se aplica de verdad en **MySQL 8** (docker) y en **MariaDB 10.2+**
 * (producción corre 10.5), así que aquí no hay divergencia de motor — al contrario
 * que `JSON_TABLE` o `->>`, que es lo que el `CLAUDE.md` avisa de no usar.
 */
return new class extends Migration
{
    public function up()
    {
        Schema::table('colillas_inscripcion', function (Blueprint $tabla) {
            // El número que le dio el banco a la familia. No es nuestro y no lo
            // validamos contra nada: quien comprueba que ese pago llegó es el
            // tesorero, mirando su cuenta. Aquí sólo viaja para que sepa qué buscar.
            $tabla->string('referencia', 60)->nullable()->after('orden_id');
        });

        // Los tres del fichero pasan a anulables: en el camino de la referencia no
        // hay fichero que describir.
        DB::statement('ALTER TABLE colillas_inscripcion
            MODIFY archivo VARCHAR(255) NULL,
            MODIFY tipo VARCHAR(60) NULL,
            MODIFY bytes INT UNSIGNED NULL');

        DB::statement('ALTER TABLE colillas_inscripcion
            ADD CONSTRAINT colillas_con_algo_que_mirar
            CHECK (archivo IS NOT NULL OR referencia IS NOT NULL)');
    }

    public function down()
    {
        DB::statement('ALTER TABLE colillas_inscripcion DROP CONSTRAINT colillas_con_algo_que_mirar');

        Schema::table('colillas_inscripcion', function (Blueprint $tabla) {
            $tabla->dropColumn('referencia');
        });
    }
};
