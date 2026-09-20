<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lo que una importación tiene que recordar para poder reanudarse de verdad.
 *
 * La tabla `importaciones` (20 ago 2026) ya sabía **por dónde iba**. Lo que no
 * sabía es **qué se le contestó** ni **qué no entendió**, y las dos cosas hacen
 * falta el día que alguien retoma una importación cortada.
 *
 * ## Por qué no basta con devolverlo en la respuesta de la subida
 *
 * Lo midió la Fase 2 dibujando la pantalla del escenario 8, y son dos razones
 * distintas:
 *
 * 1. **Los avisos que hay que enseñar son de una tanda que terminó antes.** La
 *    pantalla dice «de las 63 filas ya escritas, 4 llevaron un valor que no se
 *    reconoció», y esas 63 se escribieron en otro proceso, otro navegador y
 *    puede que hace meses. Si el aviso vive sólo en la respuesta de aquella
 *    subida, se fue con ella y esas filas no se pueden mirar nunca más.
 * 2. **Y «seguir donde se quedó» promete conservar lo que el usuario contestó**
 *    —el mapa de columnas, las equivalencias, las decisiones sobre repetidos—.
 *    Sin eso, reanudar obliga a contestarlo todo otra vez con pasos saltados,
 *    que es **peor** que empezar de cero.
 *
 * La segunda es la que autorizó la migración: la primera sola se podría haber
 * apañado con un informe al final.
 *
 * ## `longText` y no `json`, a propósito
 *
 * Producción es **MariaDB 10.5**, donde `JSON` es un alias de `LONGTEXT` con un
 * `CHECK (json_valid(...))` detrás; el docker es MySQL 8, donde es un tipo
 * nativo. Declararlo `json()` deja dos esquemas que no son el mismo y un CHECK
 * que puede abortar una escritura en los dieciséis y en ninguna suite de aquí.
 * Lo que se guarda es JSON igual — lo serializa y lo lee el punto de control—,
 * pero el tipo no depende del motor.
 *
 * ## Idempotente, y no es cosmética
 *
 * De las bases `%testing%` del docker, la mayoría son de otras sesiones y no
 * tienen estas columnas. Una migración que no comprueba antes revienta con
 * `Duplicate column name` en cuanto dos árboles la corren sobre la misma base.
 */
class LoQueLaImportacionRecuerda extends Migration
{
    public function up()
    {
        Schema::table('importaciones', function (Blueprint $table) {
            if (! Schema::hasColumn('importaciones', 'avisos')) {
                /*
                 * Lo que el traductor no supo leer, tal cual lo deja
                 * `ImporterFixer::$avisos`: fila, campo, valor y motivo. Se
                 * guarda al terminar cada subida y se ACUMULA, porque una
                 * importación reanudada tiene avisos de las dos tandas y la
                 * pantalla los enseña juntos.
                 */
                $table->longText('avisos')->nullable()->after('error');
            }

            if (! Schema::hasColumn('importaciones', 'respuestas')) {
                /*
                 * Lo que contestó la persona: el mapa de columnas, las
                 * equivalencias de vocabulario y qué hacer con cada repetido.
                 * Viaja en el cuerpo de la subida, no en una ruta propia — es
                 * parte de «sube esto con estas instrucciones», no un recurso
                 * aparte.
                 */
                $table->longText('respuestas')->nullable()->after('avisos');
            }
        });
    }

    public function down()
    {
        Schema::table('importaciones', function (Blueprint $table) {
            if (Schema::hasColumn('importaciones', 'respuestas')) {
                $table->dropColumn('respuestas');
            }

            if (Schema::hasColumn('importaciones', 'avisos')) {
                $table->dropColumn('avisos');
            }
        });
    }
}
