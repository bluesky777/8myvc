<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Crea `uniformes` en el colegio al que le falta, y en cualquier otro que aparezca.
 *
 * ## Por qué existe esta migración si la tabla YA se creó a mano
 *
 * El 5 sep 2026 Joseth la creó en `micolev1_amiguitosdejesus` desde phpMyAdmin con
 * `tools/crear-uniformes-donde-falta.sql`, porque era el único de los diecisiete
 * que no la tenía —87 tablas frente a 94— y **cinco ficheros de `app/` la
 * consultan**, tres de ellos en pantallas de todos los días: el panel de inicio de
 * un alumno (`ChangeAskedController:258`), el de un profesor con grupo (`:404`), la
 * planilla (`NotasController:1222`) y disciplina (`DisciplinaController:306`). Allí
 * contestaban **500** sin desplegar nada.
 *
 * Se hizo a mano por una razón con fecha: la tanda del día 10 estaba congelada en
 * siete migraciones y ya ensayada sobre esas siete; una octava obligaba a repetir
 * el ensayo antes de tocar dieciséis colegios.
 *
 * **Y eso dejó una deuda que es la que paga este fichero:** mientras la tabla exista
 * sólo porque alguien la escribió a mano, `database/schema/mysql-schema.sql` deja
 * de describir el esquema de los diecisiete, y `CLAUDE.md` dice *«ningún cambio de
 * esquema a mano: migración o no existe»*. Con esto vuelve a ser cierto.
 *
 * ## En amiguitos será un NO-OP, y eso es lo correcto
 *
 * `hasTable()` delante. En los diecisiete de hoy no hace nada: en dieciséis porque
 * la tienen de siempre y en el diecisiete porque ya se creó. **Su valor es el
 * colegio dieciocho** —uno nuevo se crea copiando la base de otro— y el día que una
 * copia venga de una base incompleta.
 *
 * ## El DDL no está escrito a mano
 *
 * Sale de `database/schema/mysql-schema.sql`, que es el volcado congelado desde
 * producción, y se comprobó que **ninguna migración ha tocado `uniformes` nunca**,
 * así que el volcado la describe entera. `descripcion` lleva `utf8mb4_general_ci`
 * y no la del resto de la tabla: es así en los dieciséis y se conserva.
 *
 * ## `down()` NO la borra
 *
 * Un `dropIfExists` aquí **destruiría las faltas de uniforme de dieciséis colegios
 * que siempre tuvieron la tabla**, porque una migración no sabe cuáles creó ella.
 * Deshacer un `hasTable()` es no hacer nada.
 *
 * ## NO ENTRA EN LA TANDA DEL DÍA 10
 *
 * Sería la octava —o la novena con la de la hora— de una tanda congelada en siete
 * y ya ensayada. Vive en su rama hasta que el despliegue esté hecho.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('uniformes')) {
            echo "    uniformes: ya existe, no se toca.\n";

            return;
        }

        Schema::create('uniformes', function (Blueprint $tabla) {
            $tabla->increments('id');
            $tabla->unsignedInteger('asignatura_id')->nullable();
            $tabla->string('materia', 250)->nullable();
            $tabla->unsignedInteger('alumno_id')->nullable();
            $tabla->unsignedInteger('periodo_id')->nullable();
            $tabla->boolean('contrario')->default(0);
            $tabla->boolean('sin_uniforme')->default(0);
            $tabla->boolean('incompleto')->default(0);
            $tabla->boolean('cabello')->default(0);
            $tabla->boolean('accesorios')->default(0);
            $tabla->boolean('camara')->default(0);
            $tabla->boolean('otro1')->default(0);
            $tabla->boolean('excusado')->default(0);
            // La collation de esta columna NO es la de la tabla, y en los dieciséis
            // colegios es así. Se copia el volcado, no se «arregla».
            $tabla->text('descripcion')->nullable()->collation('utf8mb4_general_ci');
            $tabla->dateTime('fecha_hora')->nullable();
            $tabla->string('uploaded', 20)->nullable();
            $tabla->integer('created_by')->nullable();
            $tabla->integer('updated_by')->nullable();
            $tabla->integer('deleted_by')->nullable();
            $tabla->timestamp('deleted_at')->nullable();
            $tabla->timestamp('created_at')->nullable();
            $tabla->timestamp('updated_at')->nullable();

            $tabla->foreign('asignatura_id', 'uniformes_asignatura_id_foreign')
                ->references('id')->on('asignaturas')->onDelete('cascade');
            $tabla->foreign('alumno_id', 'uniformes_alumno_id_foreign')
                ->references('id')->on('alumnos')->onDelete('cascade');
            $tabla->foreign('periodo_id', 'uniformes_periodo_id_foreign')
                ->references('id')->on('periodos')->onDelete('cascade');
        });

        echo "    uniformes: CREADA (no estaba).\n";
    }

    public function down(): void
    {
        // A propósito no borra nada: ver la cabecera. Un `dropIfExists` aquí se
        // llevaría las faltas de uniforme de los colegios que siempre la tuvieron.
        echo "    uniformes: down() no borra la tabla — se llevaria datos de otros colegios.\n";
    }
};
