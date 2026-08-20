<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * La tabla que `PUT api/prematriculas/llevo-formulario` lleva años dando por hecha.
 *
 * `llevo_formulario` no está en database/schema/mysql-schema.sql —el volcado de la base real, las
 * 90 tablas— ni en la de desarrollo. Nunca existió. El método empieza con un
 * `DELETE FROM llevo_formulario`, así que la ruta era un 500 seguro desde siempre; se descubrió
 * escribiendo los tests de contrato del P1. Es el mismo caso que `failed_jobs`: código que da por
 * hecha una tabla que nadie creó.
 *
 * **Lo que esta migración NO resuelve, y hay que decidir.** El sistema ya registra «llevó el
 * formulario» por otro camino: `matriculas.estado = 'FORM'`, que es lo que escribe
 * `AlumnosController` al crear el alumno y lo que lee `AlumnosFormularios` en la pantalla de
 * prematrículas. O sea que hay dos mecanismos para el mismo hecho, uno vivo y otro que nunca
 * llegó a funcionar, y **nadie lee esta tabla todavía**. Crearla hace que el endpoint guarde lo
 * que dice que guarda; cuál de los dos mecanismos se queda es cosa del colegio y del frontend.
 *
 * Se crea en vez de borrar la ruta porque la pantalla la llama: quitarla convierte un 500 en un
 * 404, que no es mejor. Y se elige esto antes que reescribir el método contra `matriculas.estado`
 * porque eso sería mover la columna de la que cuelgan boletines, actas y deudores adivinando qué
 * hace el botón cuando se desmarca.
 *
 * `unique(alumno_id, year)` porque el método borra por esa pareja antes de insertar: es la clave
 * real del registro y sin el índice dos peticiones a la vez dejan dos filas.
 */
class CreateLlevoFormularioTable extends Migration
{
    public function up()
    {
        Schema::create('llevo_formulario', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('alumno_id');
            $table->integer('year');
            $table->boolean('llevo_formulario')->default(false);
            $table->timestamps();

            $table->unique(['alumno_id', 'year']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('llevo_formulario');
    }
}
