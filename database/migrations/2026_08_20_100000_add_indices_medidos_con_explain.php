<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Los tres índices que el EXPLAIN justifica, y ninguno más.
 *
 * Es el paso 12 del plan de rendimiento, con la regla que el propio plan pone
 * delante: «no adivines. Añadir índices a ciegas en una tabla de 1,16 millones
 * de filas ralentiza las escrituras y ocupa disco sin garantía de ganancia».
 *
 * De dónde salen: `tools/indices-que-faltan.php` le pasa EXPLAIN a las 493
 * consultas distintas que ejecuta la suite de contrato y se queda con las que
 * recorren una tabla entera teniendo `possible_keys` vacío — o sea, aquellas
 * para las que NO EXISTE un índice que MySQL pudiera considerar. Eso es una
 * propiedad del esquema y no del volumen, así que se ve igual con el seed
 * pequeño que con la base de un colegio.
 *
 * Salieron 16 tablas. Aquí van tres, y las trece que faltan están en
 * docs/migracion/02-plan-rendimiento.md con el motivo de cada una. El criterio
 * para entrar fue el mismo en los tres casos: **la tabla no tiene NINGÚN índice
 * para esa columna, crece sin techo, y la consulta está en un camino que se
 * recorre muchas veces** — un guard de cada petición, o una llamada por
 * asignatura dentro de cada boletín. Lo que se quedó fuera falla alguna de las
 * tres: catálogos de nueve filas (`years.actual`), columnas de dos valores
 * (`images.publica`, `users.is_active`) donde un índice no ahorra nada, y
 * `bitacoras`, que es el caso interesante — se lee en una pantalla de
 * administración de vez en cuando y se le INSERTA en cada petición que pasa por
 * un guard. Ahí el índice se paga siempre y se cobra rara vez, y esa cuenta no
 * se decide con EXPLAIN: hace falta el registro de consultas lentas de
 * producción (paso 3, `CONSULTAS_LENTAS_MS`).
 *
 * **Al desplegar.** Son tres `ALTER TABLE` sobre tablas que en un colegio
 * grande tienen decenas de miles de filas. MySQL 8 los hace en línea, pero en
 * un alojamiento compartido tardan; se corren fuera de horario de clase, colegio
 * a colegio, como todo lo demás. `down()` los quita, así que volver atrás es
 * inmediato.
 */
class AddIndicesMedidosConExplain extends Migration
{
    public function up()
    {
        /*
         * Quién es acudiente de quién. Sin un solo índice hasta hoy.
         *
         * La consulta `WHERE alumno_id=? and acudiente_id=?` la hacen los dos
         * middlewares de autorización —ExigirPersonaPropia y ExigirBoletinPropio—,
         * así que se ejecuta en CADA petición que hace un acudiente. Es el guard
         * que cerró los 27 IDOR del 19 de agosto: se recorría la tabla entera
         * para responder una pregunta de sí o no.
         *
         * El compuesto empieza por `alumno_id` porque así sirve también para las
         * consultas que solo preguntan por el alumno (los acudientes de fulano);
         * el suelto de `acudiente_id` hace falta aparte porque el prefijo por la
         * izquierda no cubre la segunda columna, y por ahí entran los JOIN de
         * `AcudientesExport` y del importador.
         */
        Schema::table('parentescos', function (Blueprint $table) {
            $table->index(['alumno_id', 'acudiente_id'], 'parentescos_alumno_acudiente_index');
            $table->index('acudiente_id', 'parentescos_acudiente_id_index');
        });

        /*
         * Las frases del boletín. 11.446 filas en producción y ningún índice.
         *
         * `FraseAsignatura::deAlumno()` filtra por las tres a la vez, y no se
         * llama una vez por boletín: se llama una vez por ASIGNATURA de cada
         * alumno. Un grupo de treinta con doce asignaturas son 360 recorridos
         * completos de la tabla para imprimir un juego de boletines.
         */
        Schema::table('frases_asignatura', function (Blueprint $table) {
            $table->index(['alumno_id', 'asignatura_id', 'periodo_id'], 'frases_asignatura_alumno_asig_periodo_index');
        });

        /*
         * Las imágenes de cada persona. Tampoco tenía ningún índice secundario.
         *
         * Crece sin techo —cada foto de cada alumno y cada profesor, de todos
         * los años— y se filtra por `user_id` en la galería del perfil, al
         * guardar una imagen y al pedir el logo del colegio.
         */
        Schema::table('images', function (Blueprint $table) {
            $table->index('user_id', 'images_user_id_index');
        });
    }

    public function down()
    {
        Schema::table('parentescos', function (Blueprint $table) {
            $table->dropIndex('parentescos_alumno_acudiente_index');
            $table->dropIndex('parentescos_acudiente_id_index');
        });

        Schema::table('frases_asignatura', function (Blueprint $table) {
            $table->dropIndex('frases_asignatura_alumno_asig_periodo_index');
        });

        Schema::table('images', function (Blueprint $table) {
            $table->dropIndex('images_user_id_index');
        });
    }
}
