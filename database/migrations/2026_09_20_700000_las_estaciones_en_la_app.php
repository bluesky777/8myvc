<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * **Las estaciones del día de matrículas, atendidas desde el teléfono.**
 *
 * El contrato y los porqués están en
 * `docs/migracion/46-las-estaciones-en-la-app.md`; las doce pantallas, en
 * `myvc_flutter/docs/estaciones.md`. Esto es lo que hace falta debajo.
 *
 * ## UNA COLUMNA Y DOS TABLAS, Y LO QUE NO ENTRA PESA IGUAL
 *
 * La §4 del 46 pedía una tanda mucho mayor. De ella queda **esto**, y las demás
 * las cerró una respuesta o una medición, no un recorte:
 *
 *   bloquea            YA ESTÁ. Migración `2026_09_20_300000` (fase 1 del 44).
 *   cerrado_por/_at    YA ESTÁN. La misma.
 *   estacion_nro       NO: el número impreso **es** `requisitos_matricula.orden`.
 *   rol_id             NO: cierra cualquiera del personal, con su nombre y su hora.
 *   tipo, obligatorio, NO: `obligatorio` lo colapsó Joseth dentro de `bloquea` (44
 *   dias_limite        §2), y los otros dos no los pidió nadie.
 *
 *   aspirante_id       NO, Y ESTE ES EL ÚNICO QUE SE DESCARTA POR MEDICIÓN NUEVA.
 *                      Ver abajo.
 *
 * ## POR QUÉ NO HAY `aspirante_id`, MEDIDO Y NO SUPUESTO
 *
 * El contrato del 46 escribe `alumno_id|aspirante_id` en las ocho rutas. Un
 * «aspirante» sería una fila de `ordenes_inscripcion` en modo `nuevos`, o sea un
 * formulario impreso que todavía no está atado a nadie. **Y esa fila no tiene
 * nombre**: sus columnas son `codigo, year_id, year_campana, lote_id, modo,
 * alumno_id, grupo_id, grado_id, cierra, valor, vendida_por, vendida_at, estado,
 * matricula_id, codigo_anterior` — ni nombres, ni apellidos, ni documento.
 *
 * O sea que una cola de aspirantes **serían renglones en blanco**: la app no
 * podría pintar a quién tiene delante. Quien captura esos datos es el portal de la
 * familia, que es la fase 2, y el 44 §6 ya lo dice con todas las letras —*«nada de
 * portal público, aspirantes, pagos ni firma»*—.
 *
 * Y hay un segundo motivo, del esquema: `requisitos_alumno.alumno_id` es **NOT
 * NULL con clave ajena a `alumnos`**. Admitir aspirantes obliga a anularla, que en
 * MariaDB 10.5 **no es una columna nueva**: es un `ALTER` que reescribe la tabla.
 * Pagarlo hoy por una columna que nadie puede escribir es el error contrario al que
 * este repo lleva un mes evitando.
 *
 * **El camino queda abierto y es barato**: el día que el portal capture al
 * aspirante, entra su columna y una rama en las consultas. Lo que no se hace es
 * abrir el hueco antes de que exista quien lo llene — es `profesores.tono`, y van
 * seis en un mes.
 *
 * ## `motivo_devolucion` ES UNA COLUMNA PROPIA, Y ESO ES UN INVARIANTE
 *
 * `requisitos_alumno.descripcion` ya guarda la observación, y la tentación es
 * meter ahí el motivo de una devolución. **No caben juntos**, y no por sitio:
 *
 *     el motivo      lo escribe el personal PARA QUE LO LEA LA FAMILIA
 *     la observación y la nota son ENTRE EL PERSONAL
 *
 * El día que compartan columna, un comentario interno acaba en el celular de una
 * mamá. Separarlos en el esquema es lo que hace que eso no dependa de que cada
 * pantalla se acuerde.
 *
 * ## `notas_estacion`: POR QUÉ ES UNA TABLA Y NO COLUMNAS
 *
 * Son **varias por paso y por persona**, con autor, hora y estado propio, y el
 * globo de la app cuenta las de **todas** las estaciones de esa persona. Como
 * columnas no cabría ni la segunda.
 *
 * `reservada` cuenta para el globo y esconde el texto (46 §3.3): **esconder que
 * una nota existe es peor que esconder su contenido** — quien ve el globo y no
 * puede abrirlo sabe a quién preguntar; quien no ve nada, no pregunta.
 *
 * ## `envios_estacion`: LA TABLA QUE LA §4 NO PREVIÓ, Y SIN ELLA LA §3.4 MIENTE
 *
 * La §3.4 promete que el que llega salteado **no deja marca en el paso** y que el
 * intento **sí queda registrado**, para que el tablero del rector pueda decir *«en
 * la 4 se presentan doce sin pasar por la 3 — el cartel está mal puesto»*.
 *
 * Las dos mitades de esa frase necesitan sitios distintos, y la §4 sólo dio uno.
 * Meter el intento en `notas_estacion` lo contaría en el globo, que es justo lo que
 * la §3.4 dice que no hay que hacer; no meterlo en ninguna parte convierte
 * `enviar-a` en una ruta que no escribe nada y no sirve para nada.
 *
 * **Así que la tanda crece en una tabla respecto a lo autorizado, y se dice.** Es
 * seis columnas sobre una tabla que nace vacía.
 *
 * ## NINGUNA INSTANTÁNEA SE MUEVE POR LAS COLUMNAS, medido y no supuesto
 *
 * `RequisitosController::putIndex` hace `SELECT *` sobre `requisitos_matricula`, así
 * que una columna suya se reparte sola — es la trampa de
 * `columna-nueva-mas-select-asterisco`. Aquí la columna nueva va en
 * `requisitos_alumno`, y comprobado con `tools/lo-que-reparte-una-columna.py`:
 *
 *     requisitos_alumno   ->  0 instantáneas se mueven
 *
 * Y lo que eso significa **no es «no hay riesgo»**: es que esas rutas no tienen
 * instantánea de contrato. El riesgo no está tapado, está sin medir — por eso este
 * módulo trae los suyos.
 *
 * ## En MariaDB 10.5 esto entra al instante
 *
 * Una columna anulable sobre una tabla pequeña (12 filas en la copia de
 * desarrollo) y dos tablas que nacen vacías. La misma medida que dejó escrita la
 * tanda de `notas`.
 */
return new class extends Migration
{
    public function up()
    {
        if (! Schema::hasColumn('requisitos_alumno', 'motivo_devolucion')) {
            Schema::table('requisitos_alumno', function (Blueprint $tabla) {
                // Lo lee la familia. Ver la cabecera: no comparte sitio con
                // `descripcion`, que es la observación entre el personal.
                $tabla->text('motivo_devolucion')->nullable()->after('descripcion');
            });
        } else {
            echo "  requisitos_alumno.motivo_devolucion: ya existe, no se toca.\n";
        }

        if (! Schema::hasTable('notas_estacion')) {
            Schema::create('notas_estacion', function (Blueprint $tabla) {
                $tabla->increments('id');

                // El paso al que se cuelga la nota, o sea la fila de
                // `requisitos_matricula`. **No es la estación**: una estación puede
                // tener varios requisitos con el mismo `orden`, y la nota se pega a
                // uno concreto para que la ficha sepa dónde pintar el globo.
                $tabla->unsignedInteger('requisito_id');
                $tabla->unsignedInteger('alumno_id');

                $tabla->text('texto');

                // «Hay algo sin resolver»: el globo sale ámbar en vez de pizarra.
                // Nace en 0 porque la mayoría de las notas son informativas y una
                // pendiente por defecto convertiría el globo en ruido.
                $tabla->boolean('pendiente')->default(false);

                // Cuenta para el globo y **esconde el texto**. Ver la cabecera.
                $tabla->boolean('reservada')->default(false);

                $tabla->unsignedInteger('escrita_por');

                // Quién la dio por resuelta y cuándo. Las escribe
                // `PUT estaciones/nota/{id}/resuelta`, que es la novena ruta y
                // existe precisamente para que estas dos columnas no nazcan muertas
                // — ver el docblock de ese método.
                $tabla->unsignedInteger('resuelta_por')->nullable();
                $tabla->timestamp('resuelta_at')->nullable();

                $tabla->timestamps();
                $tabla->softDeletes();

                // La pregunta de la ficha: «las notas de esta persona», de todas sus
                // estaciones a la vez. Es la que hace el globo, y es la única que se
                // hace por cada alumno de la cola.
                $tabla->index(['alumno_id', 'deleted_at'], 'notas_estacion_alumno');
                $tabla->index('requisito_id', 'notas_estacion_requisito');

                $tabla->foreign('requisito_id')->references('id')->on('requisitos_matricula')->onDelete('cascade');
                $tabla->foreign('alumno_id')->references('id')->on('alumnos')->onDelete('cascade');
            });
        } else {
            echo "  notas_estacion: ya existe, no se toca.\n";
        }

        if (! Schema::hasTable('envios_estacion')) {
            Schema::create('envios_estacion', function (Blueprint $tabla) {
                $tabla->increments('id');

                $tabla->unsignedInteger('year_id');
                $tabla->unsignedInteger('alumno_id');

                // Los dos son `requisitos_matricula.orden`, o sea el número impreso
                // en la cartulina, y **no** claves ajenas: una estación es un grupo
                // de pasos que comparten `orden`, no una fila. Guardar el número es
                // lo que hace que el tablero siga contando bien después de que el
                // colegio renombre o parta un paso.
                $tabla->unsignedInteger('desde_orden');
                $tabla->unsignedInteger('hacia_orden');

                $tabla->unsignedInteger('enviado_por');
                $tabla->timestamps();

                // La única lectura: el tablero del día, «cuántos intentos hubo hoy y
                // entre qué estaciones».
                $tabla->index(['year_id', 'created_at'], 'envios_estacion_year_fecha');
                $tabla->index('alumno_id', 'envios_estacion_alumno');

                $tabla->foreign('alumno_id')->references('id')->on('alumnos')->onDelete('cascade');
            });
        } else {
            echo "  envios_estacion: ya existe, no se toca.\n";
        }
    }

    public function down()
    {
        Schema::dropIfExists('envios_estacion');
        Schema::dropIfExists('notas_estacion');

        if (Schema::hasColumn('requisitos_alumno', 'motivo_devolucion')) {
            Schema::table('requisitos_alumno', function (Blueprint $tabla) {
                $tabla->dropColumn('motivo_devolucion');
            });
        }
    }
};
