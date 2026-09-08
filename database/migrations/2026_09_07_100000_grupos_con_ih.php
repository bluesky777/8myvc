<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * `grupos.ih`: la intensidad horaria del grupo — cuántas horas de clase se dictan
 * a la semana en ese curso. Pedida por Joseth el 7 sep 2026.
 *
 * ## Qué problema resuelve, dicho por quien lo pidió
 *
 * La IH de cada asignatura ya existe —`asignaturas.creditos`— y es la que el
 * horario gasta ficha a ficha. Lo que NO existía es **contra qué sumarla**: si
 * Matemáticas de Cuarto se tecleó con 3 donde tocaba 4, la suma del grupo baja una
 * hora y **no hay nada que lo note** hasta que el programa de horarios no puede
 * colocar todas las fichas o le sobran casillas. Ahí el error ya está a dos
 * pantallas y a varios días de distancia de donde se cometió.
 *
 * Con la IH del grupo, esa cuenta se puede hacer **en la pantalla de asignaturas y
 * al entrar**: Σ `asignaturas.creditos` del grupo contra `grupos.ih`.
 *
 * ## `NULL` y no `0`, y no es una preferencia
 *
 * Ningún colegio tiene este dato hoy: los quince despliegan esta columna vacía. En
 * `0` sería «este grupo no da ninguna hora a la semana», que es falso de los ~200
 * grupos vivos de cada base, y la pantalla de asignaturas empezaría a gritar que
 * sobran horas en todos ellos el día del despliegue. En `NULL` es **«nadie lo ha
 * puesto todavía»**, y el aviso se queda callado hasta que alguien lo escriba, que
 * es la misma regla que ya usa `asignaturas.creditos` —y por la que
 * `HorarioController` cuenta sus «asignaciones sin IH» aparte en vez de compararlas
 * contra cero.
 *
 * ## Va al FINAL de la tabla a propósito
 *
 * Sin `after()`. Es la lección del despliegue que dejó escrita
 * `2026_09_02_100000_nivelaciones_columnas`: **nadie sabe qué MySQL corren los
 * colegios**, y `ADD COLUMN` en medio de la tabla sólo es `ALGORITHM=INSTANT` desde
 * 8.0.29 — antes reconstruye la tabla entera. `grupos` son decenas de filas y no
 * llegaría a notarse, pero la posición no compra nada que justifique averiguarlo:
 * ningún lector de esta columna la busca por posición.
 *
 * Lo que sí mueve, y hay que regenerar al desplegar en el árbol de tests: las
 * instantáneas de contrato de las lecturas de `grupos` que leen con `SELECT *`.
 */
class GruposConIh extends Migration
{
    public function up()
    {
        Schema::table('grupos', function (Blueprint $tabla) {
            // Anulable y sin defecto: ver la cabecera. `integer` y no `unsignedTinyInteger`
            // porque las otras cifras de esta tabla —`cupo`, `orden`— son `int` y una
            // semana no tiene un tope que este esquema pueda defender mejor que la pantalla.
            $tabla->integer('ih')->nullable();
        });
    }

    /*
     * Volver atrás es exacto y barato: la columna no la lee ningún cálculo, sólo
     * la pintan dos pantallas. Lo que se pierde es la IH que cada colegio hubiera
     * escrito, y con ella el aviso de descuadre — no las asignaturas, que siguen
     * teniendo la suya.
     */
    public function down()
    {
        Schema::table('grupos', function (Blueprint $tabla) {
            $tabla->dropColumn('ih');
        });
    }
}
