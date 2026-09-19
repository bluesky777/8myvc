<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * El formulario de inscripción impreso, su código, el recibo que sube la familia
 * y la selección de campos de cada colegio.
 *
 * Encargo de Joseth (19 sep 2026), pedido por la sesión de `myvc_front` que
 * construyó la pantalla. El contrato y las decisiones están en
 * `docs/migracion/41-el-formulario-de-inscripcion.md`.
 *
 * ## Lo que hay hoy, medido, y que explica por qué esto nace de cero
 *
 * En la copia de desarrollo el embudo ya se usa —2026: `FORM` 5, `PREM` 12,
 * `PREA` 3, `MATR` 14, `ASIS` 3— o sea que **el colegio ya cuenta formularios
 * entregados**: `FORM` significa literalmente «la familia se llevó el papel».
 * Lo que no existe es nada que ate ese papel al alumno que vuelve.
 *
 * Y **`matriculas.nro_folio` no es el sitio**, aunque lo parezca: son **1.720**
 * filas con formatos incompatibles entre sí (`2018-55`, `170`, `174`), tecleados
 * a mano durante años. Es el único registro histórico que el colegio tiene, y
 * escribir encima lo destruye.
 *
 * ## TRES tablas, y cada una por un motivo distinto
 *
 * - `ordenes_inscripcion` es **el código**: uno por formulario impreso, y vive
 *   desde que se imprime hasta que se convierte en matrícula.
 * - `colillas_inscripcion` es aparte porque **son varias por código**. Un recibo
 *   rechazado no se borra —es justo lo que el tesorero necesita ver la segunda
 *   vez— así que no cabe como columnas de la orden.
 * - `config_formulario_inscripcion` es aparte porque es **del año, no del
 *   formulario**: la eligió el colegio una vez y la heredan todos los impresos.
 *
 * ## El `UNIQUE (year_id, alumno_id)` ES el get-or-create
 *
 * El requisito de Joseth es «un código por alumno y año». Escrito como
 * comprueba-y-luego-inserta, dos secretarías reimprimiendo 5°A a la vez dejan dos
 * códigos para el mismo alumno y **el código deja de identificar a nadie**.
 *
 * Con el índice único, el segundo intento **choca** y el método reusa el que hay.
 * No es una precaución teórica: la mitad del
 * [10](../../docs/migracion/10-definitivas.md) existe porque `notas_finales` es
 * una caché sin clave única con seis escritores de comprueba-y-luego-inserta.
 *
 * **Y funciona para los dos modos con un solo índice**, que es la parte que hay
 * que entender antes de tocarlo: en el modo `nuevos` no hay alumno, así que
 * `alumno_id` va a NULL — y MySQL y MariaDB **permiten NULL repetido en un índice
 * único**. O sea que el índice obliga a un código por alumno en la renovación y
 * **no estorba** a los formularios en blanco, que son muchos y sin dueño.
 *
 * ## `year_id` es el año de DESTINO, no el del grupo
 *
 * Es la distinción que costó una vuelta con el front. En la renovación se elige
 * **el grupo del año actual** —el alumno está en 5°A de 2026— pero el código es
 * **de la campaña de 2027**, que es a lo que la familia se inscribe. Por eso se
 * guardan los dos: `year_id` el de destino y `grupo_id` el de origen, que es lo
 * que permite reimprimir «los de 5°A» el año que viene sin adivinar nada.
 *
 * El grupo de destino **no existe todavía y no tiene por qué**: el colegio abre la
 * campaña antes de crear los grupos, medido por el front (13 grupos en el año
 * actual, 0 en el siguiente).
 *
 * ## `campos` es `text` y no `json`
 *
 * Producción corre **MariaDB 10.5** y el docker MySQL 8. En MariaDB `json` es un
 * alias de `longtext` con un `CHECK`, y aquí no se gana nada con el tipo nativo:
 * **nadie consulta dentro**, se lee entero y se decodifica en PHP. Un `text` se
 * comporta igual en las dos. Es la misma decisión que `informes_recientes`.
 *
 * ## Lo que se guarda son CLAVES, no etiquetas
 *
 * `campos` lleva identificadores (`nombres`, `ac_celular`), no rótulos. Qué
 * significa cada uno vive en el catálogo del front. No es reparto de
 * conveniencia: **un campo del papel tiene que corresponder a una columna que
 * esta API sepa leer**, o la renovación lo imprimiría siempre en blanco. Si el
 * colegio pudiera inventar campos, tendría renglones que no rellena nadie nunca.
 *
 * ## `codigo` único en la tabla, no en el generador
 *
 * El generador es aleatorio, así que puede repetir. Que lo rechace la base y el
 * método reintente es una garantía; que el generador «no repita» es una
 * esperanza.
 */
return new class extends Migration
{
    public function up()
    {
        Schema::create('ordenes_inscripcion', function (Blueprint $tabla) {
            $tabla->increments('id');

            // `2027-4K7M2X`: año + 5 de un alfabeto sin O/0, I/1/L ni S/5, más un
            // carácter de control. Se dicta por teléfono sin equivocarse y un
            // tecleo malo da error en vez de OTRO alumno, que es la diferencia
            // que importa cuando quien teclea tiene cincuenta papeles delante.
            $tabla->string('codigo', 20);

            // El año de DESTINO (ver cabecera).
            $tabla->unsignedInteger('year_id');

            // El lote impreso. Sin esto, recargar la pantalla vuelve a acuñar y
            // una impresora atascada cuesta diez códigos.
            $tabla->char('lote_id', 36);

            $tabla->string('modo', 10);                          // nuevos | antiguos
            $tabla->unsignedInteger('alumno_id')->nullable();    // sólo en antiguos
            $tabla->unsignedInteger('grupo_id')->nullable();     // el de ORIGEN, año actual
            $tabla->unsignedInteger('grado_id')->nullable();     // opcional en nuevos

            // La frase que se imprime tal cual. Es texto libre y no una fecha a
            // propósito: lo que va al papel es lo que el colegio quiera decir, y
            // un selector de fechas elegiría el formato por ellos.
            $tabla->string('cierra', 120)->nullable();

            // Joseth confirmó que el formulario SE COBRA (19 sep 2026), así que el
            // código queda atado a un cobro: cuánto, quién lo vendió y cuándo. De
            // aquí sale además la lista de quién compró y no volvió, que hoy no
            // existe en ninguna parte y es dinero que el colegio ya recibió.
            $tabla->unsignedInteger('valor')->nullable();
            $tabla->unsignedInteger('vendida_por')->nullable();
            $tabla->timestamp('vendida_at')->nullable();

            // IMPRESA | PAGADA | APROBADA | RECHAZADA | MATRICULADA
            $tabla->string('estado', 12)->default('IMPRESA');

            // Cuando el aspirante se convierte en alumno, el código se queda
            // pegado: siempre se puede ir del papel a la matrícula y al revés.
            // Es el requisito literal de Joseth.
            $tabla->unsignedInteger('matricula_id')->nullable();

            $tabla->unsignedInteger('created_by')->nullable();
            $tabla->unsignedInteger('updated_by')->nullable();
            $tabla->timestamps();
            $tabla->softDeletes();

            $tabla->unique('codigo', 'ordenes_inscripcion_codigo');

            // El get-or-create de la renovación (ver cabecera). NULL repetido está
            // permitido, así que no estorba al modo `nuevos`.
            $tabla->unique(['year_id', 'alumno_id'], 'ordenes_inscripcion_alumno_anio');

            // Reimprimir un lote, que es la única lectura por lote.
            $tabla->index('lote_id', 'ordenes_inscripcion_lote');

            $tabla->foreign('year_id')->references('id')->on('years')->onDelete('cascade');
        });

        Schema::create('colillas_inscripcion', function (Blueprint $tabla) {
            $tabla->increments('id');
            $tabla->unsignedInteger('orden_id');

            // El nombre GENERADO, nunca el que mandó el desconocido. La ruta que
            // recibe esto es pública —la familia no tiene cuenta— y guardar el
            // nombre ajeno es cómo se acaba sirviendo lo que subió otro.
            $tabla->string('archivo');
            $tabla->string('tipo', 60);          // el medido del contenido, no la extensión
            $tabla->unsignedInteger('bytes');

            // De dónde vino. No identifica a nadie y es lo único que queda para
            // reconstruir un abuso desde una ruta sin token. 45 caracteres porque
            // una IPv6 con zona no cabe en menos.
            $tabla->string('subida_ip', 45)->nullable();

            // PENDIENTE | APROBADA | RECHAZADA
            $tabla->string('estado', 12)->default('PENDIENTE');

            // Un recibo rechazado NO se borra: es justo lo que el tesorero
            // necesita ver cuando la misma familia sube el segundo.
            $tabla->unsignedInteger('resuelta_por')->nullable();
            $tabla->timestamp('resuelta_at')->nullable();
            $tabla->string('motivo')->nullable();

            $tabla->timestamps();

            // La bandeja del tesorero, que es la única lectura que importa.
            $tabla->index(['estado', 'created_at'], 'colillas_inscripcion_bandeja');
            $tabla->index('orden_id', 'colillas_inscripcion_orden');

            $tabla->foreign('orden_id')->references('id')
                ->on('ordenes_inscripcion')->onDelete('cascade');
        });

        Schema::create('config_formulario_inscripcion', function (Blueprint $tabla) {
            $tabla->increments('id');
            $tabla->unsignedInteger('year_id');

            // Lista de claves del catálogo del front (ver cabecera). Vacía o
            // ausente significa «la selección por defecto», no «un formulario sin
            // campos»: un colegio que nunca configuró nada no quiere un papel en
            // blanco.
            $tabla->text('campos')->nullable();

            $tabla->unsignedInteger('created_by')->nullable();
            $tabla->unsignedInteger('updated_by')->nullable();
            $tabla->timestamps();

            // Una sola configuración por año. Es por año y no por colegio porque
            // un colegio puede pedir un dato nuevo en 2027 sin reescribir lo que
            // ya imprimió en 2026.
            $tabla->unique('year_id', 'config_formulario_inscripcion_anio');

            $tabla->foreign('year_id')->references('id')->on('years')->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('colillas_inscripcion');
        Schema::dropIfExists('config_formulario_inscripcion');
        Schema::dropIfExists('ordenes_inscripcion');
    }
};
