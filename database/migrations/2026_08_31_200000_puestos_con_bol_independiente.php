<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * El interruptor del colegio para los puestos: `years.puestos_con_bol_independiente`.
 *
 * Es la decisión 3 de `docs/migracion/19-boletin-independiente.md` y la §7 entera.
 * **Por defecto 1, que es lo de hoy**: mientras nadie lo toque, el alumno con
 * boletín independiente sigue contando en el puesto exactamente como contaba antes
 * de que existiera esta columna.
 *
 * ## Es un interruptor del colegio y no una casilla de pantalla, y eso se decidió
 *
 * El puesto no sólo sale en la tabla de puestos: **se imprime en el boletín**
 * (`Informes\BoletinesController:235` y siete sitios más). Una casilla por pantalla
 * dejaría dos criterios para el mismo número, que es de donde salió
 * `DefinitivasDeAsignatura` con sus seis escritores. Con la columna en `years` hay
 * **un** valor por año y los ocho sitios preguntan al mismo servicio.
 *
 * ## Esta columna ya entró una vez y se RETIRÓ, y por eso vuelve ahora
 *
 * Entró el 24 ago 2026 con el esqueleto y se sacó el mismo día: movía las **tres
 * instantáneas** de `MuestreoDeLecturasTest` —`api/years`, `api/years/colegio` y
 * `api/years/trashed`, porque `YearsController:27` y `:43` leen con `SELECT *`— y
 * **no la consumía nadie**. Una columna que nadie lee moviendo tres respuestas vivas
 * es coste sin contrapartida. Vuelve **con quien la consume**: la fase 6, que es
 * este mismo lote.
 *
 * `Year::datos` y `Year::datos_basicos` **leen por columnas nombradas**, así que la
 * columna no se cuela sola en los boletines ni en los puestos: donde viaja, viaja
 * porque alguien la nombró.
 *
 * ## Qué cuesta desplegarla
 *
 * `ADD COLUMN` con un `DEFAULT` no nulo es `ALGORITHM=INSTANT` en MySQL 8.0 y
 * `years` son decenas de filas por colegio: no hay ventana ni bloqueo que valga la
 * pena medir. Lo que sí hay que decir en voz alta el día que un colegio lo ponga a
 * 0 está en la §7.1 del plan — **el puesto no se guarda en ninguna tabla que la API
 * lea**, se calcula al vuelo, así que reimprimir un boletín ya entregado dará otro
 * puesto. Reversible sí; sin rastro de lo impreso, también.
 */
class PuestosConBolIndependiente extends Migration
{
    public function up()
    {
        Schema::table('years', function (Blueprint $tabla) {
            // `default(1)` es «lo de hoy» y no una preferencia: con 0 por defecto,
            // el despliegue le cambiaría el puesto impreso a los quince colegios sin
            // que ninguno lo hubiera pedido.
            $tabla->boolean('puestos_con_bol_independiente')->default(1);
        });
    }

    /*
     * Volver atrás es exacto: la columna no guarda nada derivado y ningún cálculo
     * la escribe. Lo que se pierde es la elección del colegio que la hubiera puesto
     * a 0 — y ese colegio vuelve al comportamiento de hoy, que es el default.
     */
    public function down()
    {
        Schema::table('years', function (Blueprint $tabla) {
            $tabla->dropColumn('puestos_con_bol_independiente');
        });
    }
}
