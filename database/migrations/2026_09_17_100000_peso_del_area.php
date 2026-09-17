<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Cuánto pesa una asignatura dentro de su área.
 *
 * Es **P5.bis** (Joseth, 17 sep 2026): *«esto se debe cuadrar en /asignaturas
 * donde se crea una fila que tiene su materia, grupo, profesor, ih, etc.»*.
 * Sustituye a la tabla que P5 había planeado y que nunca llegó a crearse.
 *
 * ## Por qué cuelga de la asignatura y no de (área, grado)
 *
 * El área se calcula **por grupo**: `Area.php` suma las definitivas de las
 * asignaturas de ese grupo y divide por cuántas son. La asignatura ES materia ×
 * grupo, así que es la fila que ya tiene las dos coordenadas del reparto. Colgarlo
 * de (área, grado) obligaría a una tabla nueva y a resolver el grado por grupo en
 * cada lectura, para acabar en el mismo sitio.
 *
 * **Y el grano queda más fino de lo que P5 escribió**: 6.ºA y 6.ºB pueden repartir
 * distinto. En `simonbolivar` no se nota —trece grupos y trece grados, uno por
 * grado— pero en un colegio con varios grupos por grado son varias filas donde
 * antes había una. Es una consecuencia aceptada, no un descuido.
 *
 * ## NULL es «no repartida», y por eso los dieciséis colegios no se enteran
 *
 * Toda fila nace `NULL`, y `NULL` significa **«nadie ha repartido esta área»**.
 * `Area.php` sigue promediando exactamente igual mientras las asignaturas de un
 * (grupo, área) no tengan **todas** su peso y no sumen 100. O sea que esto no
 * cambia ni una definitiva hasta que alguien escriba en la pantalla.
 *
 * ## Por qué NO se reutiliza `creditos`
 *
 * `asignaturas.creditos` es **intensidad horaria**: contra ella cuadra `grupos.ih`
 * y el programa de horarios, que vive en **otro repositorio**
 * (`~/DESARROLLOS/myvc_horarios`). Reutilizarla le daría dos significados a la
 * misma columna y uno de los dos tiene dueño fuera de aquí. Es la advertencia que
 * `CORRECCIONES-MODELO-DE-EVALUACION.md` §H7 dejó escrita, y sigue en pie.
 *
 * *(En muchos colegios el peso del área **es** la intensidad horaria. Eso no
 * justifica compartir la columna: justifica que la pantalla pueda ofrecer «repartir
 * según la IH» como un botón que **escribe aquí**.)*
 *
 * ## `unsignedTinyInteger`, que llega justo
 *
 * El rango es 0–255 y el valor válido es 0–100, así que la columna no puede
 * guardar un porcentaje imposible ni siquiera si alguien salta la validación. Lo
 * que sí puede guardar es un reparto que no sume 100 entre sus hermanas, y eso no
 * es cosa del tipo: se comprueba al escribir y se enseña en el panel de arriba,
 * que es donde el colegio puede arreglarlo.
 *
 * ## Sin `after`
 *
 * Igual que `alcance_de_la_plantilla`: `ADD COLUMN … AFTER x` ata la migración al
 * orden de su tanda y falla con una base que ya venga de otro camino.
 *
 * ## Volver atrás
 *
 * Aditiva pura: `down()` quita la columna y **no pierde ninguna nota**. Lo único
 * que se pierde es el reparto que el colegio hubiera escrito, que al volver el
 * código viejo tampoco lee nadie.
 */
class PesoDelArea extends Migration
{
    public function up()
    {
        Schema::table('asignaturas', function (Blueprint $tabla) {
            $tabla->unsignedTinyInteger('porcentaje_area')->nullable();
        });
    }

    public function down()
    {
        Schema::table('asignaturas', function (Blueprint $tabla) {
            $tabla->dropColumn('porcentaje_area');
        });
    }
}
