<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * El jefe de área (director de área), **por año** — y la mina que se lleva por
 * delante.
 *
 * Decisión de Joseth, 17 sep 2026, sobre una pantalla que se estaba construyendo
 * contra `areas.jefe_id`: *«el área no puede tener dueño directamente porque cada
 * año es un dueño diferente (director de área) y no puedo cambiar años pasados por
 * configurar el actual»*.
 *
 * ## Por qué la columna vieja no podía cumplirlo
 *
 * `areas` **no tiene `year_id`**: el área es global al colegio y se comparte entre
 * todos los años. Así que `areas.jefe_id` es **un solo valor para toda la
 * historia**, y escribir el director de 2026 cambia quién figura en 2024, 2023 y
 * 2019. Es el invariante que `years.modelo_evaluacion` sí respeta —un año cerrado
 * conserva el suyo para siempre— y esa columna no puede respetarlo, porque el año
 * no aparece por ningún lado en su tabla.
 *
 * ## Y la razón por la que esto no podía quedarse para después
 *
 * `areas.jefe_id` llevaba una clave ajena **`ON DELETE CASCADE` hacia
 * `profesores`**, y `CASCADE` en esa dirección borra la **fila hija**, que es **el
 * ÁREA**. Hay un borrado real, no lógico:
 * `ProfesoresController::deleteForcedelete` hace `$profesor->forceDelete()`
 * —papelera, sólo superusuario, pero existe—. La cadena, medida sobre el área 11
 * de la copia de desarrollo el 17 sep 2026:
 *
 *     borrar el profesor  ->  borra el ÁREA
 *       materias.area_id CASCADE          ->       3 materias
 *       asignaturas.materia_id CASCADE    ->     233 asignaturas (TODOS los años)
 *       notas_finales.asignatura_id       ->  22.822 notas finales
 *       unidades.asignatura_id            ->   3.306 unidades
 *
 * No son las asignaturas de ese profesor: son **las de todos los profesores de esa
 * área, de todos los años y todos los grupos**. Vaciar la papelera de un docente
 * que fuera jefe de área se llevaría la historia académica del área entera.
 *
 * **Hoy no puede pasar por una sola razón**: las 22 áreas de la copia de desarrollo
 * tienen `jefe_id = NULL`, y hasta el 17 sep **ningún camino del código escribía esa
 * columna**. O sea que la pantalla que se estaba construyendo era exactamente lo que
 * la habría armado. El comentario de `deleteForcedelete` dice *«31 tablas en
 * cascada, siete saltos»*, pero ese recuento se hizo con la columna muerta: **no
 * cuenta este camino**.
 *
 * En los dieciséis colegios **no se puede ver desde aquí** si alguna fila está
 * rellena. Lo esperable es que estén todas a `NULL` —nadie tenía cómo escribirla—
 * pero eso es inferencia, no medición: si alguien la rellenó desde phpMyAdmin, esta
 * migración es justo lo que lo desactiva.
 *
 * ## La tabla nueva arregla las dos cosas con la misma línea
 *
 * Con la jefatura en su propia fila, el `CASCADE` de `profesor_id` borra **la
 * jefatura** y no el área — que es lo que cualquiera espera que pase al borrar un
 * profesor. No hace falta ninguna guarda en el código: **lo arregla la dirección de
 * la clave ajena**, que es la clase de invariante que no se salta nadie.
 *
 * ## Sin borrado lógico, y con `UNIQUE` de verdad
 *
 * Una jefatura es una **asignación**: se pone y se quita. No hay nada que rescatar
 * de la papelera —quién fue jefe y cuándo lo guarda `auditoria`, que registra por
 * ruta— y hay precedente de sobra: **31 de las 90 tablas** del volcado no tienen
 * `deleted_at`, entre ellas `permission_role` y `piars_asignaturas`, que son de esta
 * misma especie.
 *
 * Y eso es lo que permite que `UNIQUE (year_id, area_id)` sea **real**: un área
 * tiene **un** jefe por año, y lo impide la base y no un `if`. Con borrado lógico el
 * índice habría bloqueado volver a nombrar jefe tras quitar uno, y la unicidad
 * habría acabado siendo una regla escrita en el controlador — o sea, una que se
 * salta el primer camino que nadie repase.
 *
 * ## Qué se mueve al quitar la columna, medido y no supuesto
 *
 * Este proyecto lee con `SELECT *` por todas partes —`MateriasController:21` y los
 * `SELECT ar.*` de `BolfinalesController` y `PromovidosController`—, así que perder
 * una columna quita una clave de respuestas que nadie tocó. Contado con
 * `tools/lo-que-reparte-una-columna.py areas`: de **128** instantáneas, **2** llevan
 * la fila entera de `areas` y son las que se mueven —`muestreo-areas.json` y
 * `muestreo-materias.json`—. Las demás son proyecciones nombradas y son inmunes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('jefes_de_area', function (Blueprint $tabla) {
            $tabla->increments('id');

            $tabla->unsignedInteger('year_id');
            $tabla->unsignedInteger('area_id');
            $tabla->unsignedInteger('profesor_id');

            $tabla->integer('created_by')->nullable();
            $tabla->integer('updated_by')->nullable();
            $tabla->timestamps();

            // **Un área, un jefe, un año** — y lo dice la base. Ver el docblock:
            // sin borrado lógico, este índice puede ser `UNIQUE` de verdad.
            $tabla->unique(['year_id', 'area_id'], 'jefes_de_area_unico');

            // Y el índice por profesor, que es la otra pregunta que se hace:
            // «¿de qué áreas es jefe esta persona?».
            $tabla->index('profesor_id', 'jefes_de_area_profesor');

            $tabla->foreign('year_id')->references('id')->on('years')->onDelete('cascade');
            $tabla->foreign('area_id')->references('id')->on('areas')->onDelete('cascade');

            // **Ésta es la que desarma la mina.** Borrar el profesor borra su
            // jefatura, no el área.
            $tabla->foreign('profesor_id')->references('id')->on('profesores')->onDelete('cascade');
        });

        /*
         * **La columna vieja se va, y hay que quitar la clave ajena primero.**
         * MySQL no deja soltar una columna que sostiene un `FOREIGN KEY`, y el
         * índice que la acompaña se va con ella.
         *
         * No se migra ningún dato: la columna nunca tuvo escritor, así que no hay
         * jefe que trasladar. Si algún colegio la tuviera rellena a mano, ese valor
         * se pierde — y es preferible a dejar la mina armada por un dato que nadie
         * puso desde una pantalla.
         */
        Schema::table('areas', function (Blueprint $tabla) {
            $tabla->dropForeign('areas_jefe_id_foreign');
            $tabla->dropColumn('jefe_id');
        });
    }

    public function down(): void
    {
        Schema::table('areas', function (Blueprint $tabla) {
            $tabla->unsignedInteger('jefe_id')->nullable()->after('alias');

            // Se repone **tal como estaba**, `CASCADE` incluido, porque un `down`
            // que arregla de paso deja la base en un estado que nunca existió y que
            // nadie podría reproducir.
            $tabla->foreign('jefe_id')->references('id')->on('profesores')->onDelete('cascade');
        });

        Schema::dropIfExists('jefes_de_area');
    }
};
