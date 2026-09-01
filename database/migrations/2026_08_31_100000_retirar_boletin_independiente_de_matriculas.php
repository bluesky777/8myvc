<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * `matriculas.boletin_independiente` se retira: una sola fuente para la marca.
 *
 * Es la respuesta a la pregunta 2 de la §2.1 de
 * `docs/migracion/19-boletin-independiente.md`, que la abrió la decisión 7 de
 * Joseth (31 ago 2026): **la marca es por periodo, no por año**. La pregunta era
 * si esta columna se queda de espejo del año o se retira, y la contesta el
 * backend porque es suya.
 *
 * ## Por qué se retira y no se queda de espejo
 *
 * Un espejo es un segundo escritor de un dato derivado, y este repositorio ya
 * sabe lo que cuesta: seis escritores de la definitiva con cinco criterios, de
 * donde salió `DefinitivasDeAsignatura`. Aquí el modo de fallo es peor que un
 * número raro — **dos columnas que discrepan en silencio significan un alumno que
 * vuelve al boletín del grupo sin que nadie lo vea**, y quien lo note lo ve en la
 * planilla de otro docente, sin nada que lo relacione con un interruptor que
 * alguien tocó en otra pantalla (§9.5).
 *
 * Y lo que se lleva por delante es más que la discrepancia:
 *
 *   - **la §9.5, para esta marca.** La columna vivía en una fila de `matriculas`,
 *     y `matriculas` **no tiene clave única sobre (alumno, año)**: la ficha elegía
 *     «la matrícula del año» filtrando y ordenando, `GuardarAlumno::valor` sin
 *     filtrar ni ordenar, y las dos se quedaban con `[0]`. `bol_ind_periodos`
 *     cuelga de `(alumno_id, periodo_id)` **con clave única**, así que no hay dos
 *     filas entre las que equivocarse. La §9.5 sigue viva para `repitente`,
 *     `promovido` y `nro_folio`; para esta marca deja de existir.
 *   - **treinta líneas de SQL en `BoletinIndependiente`**, que sólo estaban para
 *     derivar esa matrícula: entrar por `periodos`, bajar a `grupos` del mismo
 *     `year_id`, unir `matriculas` y desempatar con `ORDER BY … LIMIT 1`. Un
 *     periodo pertenece a un año y sólo a uno, así que **el año se hereda en vez
 *     de derivarse**.
 *
 * El campo de listado que el front necesita —«¿este alumno tiene algún periodo
 * marcado este año?»— sale derivado de esta misma tabla con un `EXISTS` sobre
 * `bol_ind_periodos_unico`, cuya columna izquierda es `alumno_id`. Un valor
 * derivado no puede discrepar de su fuente.
 *
 * ## Lo que cuesta desplegarla, medido y no supuesto
 *
 * `DROP COLUMN` en MySQL 8.0 admite `ALGORITHM=INSTANT` desde la 8.0.29, y el
 * contenedor va por la **8.0.42**. Medido sobre una copia real de `matriculas`
 * (3.542 filas, 0,4 MB): **15,2 ms**, sin reconstruir la tabla. Es la más barata
 * de las migraciones de este plan y la contraria del `ALTER TABLE` sobre
 * `unidades` que avisa la §10 — pero **el `sql_mode` y la versión de MySQL de los
 * quince cPanel no los conocemos**, así que si algún colegio no admitiera
 * `INSTANT` el peor caso es una reconstrucción de una tabla de 0,4 MB.
 *
 * ## Se puede correr sin ventana, y por qué
 *
 * La columna está a **0 en las 3.542 filas** y **no la lee ni la escribe nadie**
 * desde este mismo lote: era el propio `BoletinIndependiente` su único lector, y
 * ya no la nombra. Ningún cliente la envía ni la recibe — nunca llegó a viajar en
 * una respuesta. O sea que entre el `pull` y el `migrate` no hay ventana: el
 * código nuevo no la necesita y el viejo no la usaba para nada observable.
 *
 * **Y por eso se retira ahora y no «más adelante».** Hoy es una columna inerte que
 * nadie lee; cada día que se queda es un día más en que alguien puede leerla y
 * convertirla en la segunda fuente que este lote existe para no tener.
 */
class RetirarBoletinIndependienteDeMatriculas extends Migration
{
    public function up()
    {
        Schema::table('matriculas', function (Blueprint $tabla) {
            $tabla->dropColumn('boletin_independiente');
        });
    }

    /*
     * `down()` la devuelve **a 0 en todas las filas**, que es exactamente lo que
     * había: la columna nunca llegó a tener un 1 en ninguna base. No hay un dato
     * que reconstruir y por eso volver atrás es exacto, y no «exacto mientras
     * nadie haya marcado» como en la migración del esqueleto.
     *
     * Lo que `down()` NO devuelve es el sentido: el código que la leía se fue con
     * ella. Volver atrás aquí sin volver atrás el lote entero deja una columna
     * inerte, que es de donde venimos.
     */
    public function down()
    {
        Schema::table('matriculas', function (Blueprint $tabla) {
            $tabla->boolean('boletin_independiente')->default(0)->after('repitente');
        });
    }
}
