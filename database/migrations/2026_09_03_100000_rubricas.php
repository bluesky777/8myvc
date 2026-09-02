<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Rúbricas: cinco tablas nuevas y una columna NULL en `subunidades`.
 *
 * Es la §2 de `docs/migracion/26-rubricas.md`, que sale del §3.6 del plan de
 * nivelaciones y rúbricas (`myvc_front/PLAN-NIVELACIONES-Y-RUBRICAS.md`). La
 * propiedad que hace barata la migración es la decisión 4 de Joseth (2 sep
 * 2026): **la rúbrica produce la nota**, y por eso ninguna de estas tablas la
 * lee nadie que imprima un boletín. Definitivas, puestos y certificados siguen
 * leyendo `notas.nota` como siempre.
 *
 * ## Ningún dato existente se toca
 *
 * Cinco `CREATE TABLE` y un `ADD COLUMN ... NULL`. No hay `UPDATE`, ni
 * back-fill, ni `DROP`. Volver atrás es borrar las cinco y la columna, y no
 * pierde nada que existiera antes de correrla (tareas §6.8).
 *
 * El `ADD COLUMN` sobre `subunidades` es `INSTANT` en MySQL 8.0 —el contenedor
 * va por la 8.0.42— y la tabla no es de las grandes. En un colegio que fuera
 * 5.7 reconstruiría, y por eso va con el aviso de la §6.2 de las tareas:
 * `SELECT VERSION()` antes de programarla.
 *
 * ## El prefijo `09_03`
 *
 * El carril A lleva `2026_09_02_*` y éste `2026_09_03_*` (tareas §4.1), para
 * que el orden de ejecución sea determinista aunque no haya dependencia entre
 * las dos: ninguna tabla de aquí mira las columnas que A añade a `notas`.
 *
 * ## Las dos foráneas que NO cascadan
 *
 * Todo el esquema congelado es `ON DELETE CASCADE`. Aquí hay dos `SET NULL`,
 * las dos por la misma razón: **una rúbrica es trabajo del docente, no un
 * atributo de la fila que la usa**.
 *
 *   - `rubricas.asignatura_id`: el borrado físico de una asignatura (que ya
 *     cascada 27 tablas) se llevaría también las rúbricas que otras subunidades
 *     reutilizan como plantilla.
 *   - `subunidades.rubrica_id`: el borrado normal de una rúbrica es softdelete,
 *     que la foránea no ve; quien lo defiende es `DELETE rubricas/{id}` (§4.6),
 *     que no borra una rúbrica en uso. El `SET NULL` es sólo para el borrado
 *     físico, que aquí no tiene ruta.
 *
 * ## `momento` nace con la tabla
 *
 * Era la tarea C9, después de calificar. Va dentro de la clave única de
 * `rubrica_valoraciones` —una nota puede tener la valoración del antes y la del
 * después de nivelar (plan §3.7)—, y cambiar una clave única con datos es un
 * `ALTER` que reconstruye. Ahora cuesta cero.
 */
class Rubricas extends Migration
{
    public function up()
    {
        Schema::create('rubricas', function (Blueprint $tabla) {
            $tabla->increments('id');
            $tabla->unsignedInteger('year_id');
            $tabla->unsignedInteger('asignatura_id')->nullable();
            $tabla->string('nombre');
            $tabla->text('descripcion')->nullable();
            // `es_plantilla` no cambia nada del cálculo: es lo que hace que la
            // rúbrica aparezca en el selector de cualquier subunidad del año y
            // no sólo en las de su asignatura (§4.1).
            $tabla->boolean('es_plantilla')->default(0);
            $tabla->integer('created_by')->nullable();
            $tabla->integer('updated_by')->nullable();
            $tabla->integer('deleted_by')->nullable();
            $tabla->timestamps();
            $tabla->softDeletes();

            $tabla->foreign('year_id')->references('id')->on('years')->onDelete('cascade');
            $tabla->foreign('asignatura_id')->references('id')->on('asignaturas')->onDelete('set null');
        });

        Schema::create('rubrica_criterios', function (Blueprint $tabla) {
            $tabla->increments('id');
            $tabla->unsignedInteger('rubrica_id');
            $tabla->text('definicion');
            // Entero y sin normalizar, como `unidades.porcentaje`: la suma puede
            // no dar 100 y se avisa en pantalla, no se corrige por detrás (§3).
            $tabla->integer('peso')->default(0);
            $tabla->integer('orden')->default(0);
            $tabla->timestamps();

            $tabla->foreign('rubrica_id')->references('id')->on('rubricas')->onDelete('cascade');
        });

        Schema::create('rubrica_niveles', function (Blueprint $tabla) {
            $tabla->increments('id');
            $tabla->unsignedInteger('rubrica_id');
            $tabla->string('nombre', 60);
            $tabla->integer('puntaje');
            $tabla->integer('orden')->default(0);
            $tabla->timestamps();

            $tabla->foreign('rubrica_id')->references('id')->on('rubricas')->onDelete('cascade');
        });

        Schema::create('rubrica_descriptores', function (Blueprint $tabla) {
            $tabla->increments('id');
            $tabla->unsignedInteger('criterio_id');
            $tabla->unsignedInteger('nivel_id');
            $tabla->text('texto');
            $tabla->timestamps();

            // Una celda por par. Los descriptores se reescriben enteros en cada
            // guardado (§4.5) y la clave es lo que impide que un guardado a
            // medias deje dos textos para la misma celda.
            $tabla->unique(['criterio_id', 'nivel_id'], 'rubrica_descriptores_celda');
            $tabla->foreign('criterio_id')->references('id')->on('rubrica_criterios')->onDelete('cascade');
            $tabla->foreign('nivel_id')->references('id')->on('rubrica_niveles')->onDelete('cascade');
        });

        Schema::create('rubrica_valoraciones', function (Blueprint $tabla) {
            $tabla->increments('id');
            // `notas.id` es `bigint unsigned`; con `unsignedInteger` la foránea
            // no se puede crear (errno 150) y el `migrate` muere a medias.
            $tabla->unsignedBigInteger('nota_id');
            $tabla->unsignedInteger('criterio_id');
            $tabla->unsignedInteger('nivel_id');
            $tabla->string('momento', 12)->default('original');
            $tabla->string('comentario', 255)->nullable();
            $tabla->integer('created_by')->nullable();
            $tabla->integer('updated_by')->nullable();
            $tabla->timestamps();

            // Una marca por (nota, criterio, momento). Es la clave sobre la que
            // escribe el `INSERT ... ON DUPLICATE KEY UPDATE` de
            // `rubricas/valorar`: la misma forma que `bol_ind_periodos_unico`,
            // y por lo mismo — sin ventana de borrado.
            $tabla->unique(['nota_id', 'criterio_id', 'momento'], 'rubrica_valoraciones_marca');
            $tabla->foreign('nota_id')->references('id')->on('notas')->onDelete('cascade');
            $tabla->foreign('criterio_id')->references('id')->on('rubrica_criterios')->onDelete('cascade');
            $tabla->foreign('nivel_id')->references('id')->on('rubrica_niveles')->onDelete('cascade');
        });

        Schema::table('subunidades', function (Blueprint $tabla) {
            $tabla->unsignedInteger('rubrica_id')->nullable()->after('actividad_id');
            $tabla->foreign('rubrica_id')->references('id')->on('rubricas')->onDelete('set null');
        });
    }

    /*
     * Exacto: lo que había antes de `up()` era que ninguna de estas tablas
     * existía y `subunidades` no tenía la columna. Lo único que se pierde son
     * las rúbricas y las valoraciones registradas desde entonces; las notas
     * que produjeron siguen en `notas.nota`, que esta migración nunca tocó.
     *
     * El orden es el inverso del de arriba porque las foráneas mandan: la
     * columna de `subunidades` apunta a `rubricas`, y las valoraciones a
     * criterios y niveles.
     */
    public function down()
    {
        Schema::table('subunidades', function (Blueprint $tabla) {
            $tabla->dropForeign(['rubrica_id']);
            $tabla->dropColumn('rubrica_id');
        });

        Schema::dropIfExists('rubrica_valoraciones');
        Schema::dropIfExists('rubrica_descriptores');
        Schema::dropIfExists('rubrica_niveles');
        Schema::dropIfExists('rubrica_criterios');
        Schema::dropIfExists('rubricas');
    }
}
