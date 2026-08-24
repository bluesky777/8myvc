<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * El esqueleto del boletín independiente: las cuatro decisiones de la §2 del
 * plan, y ni una línea de conducta.
 *
 * docs/migracion/19-boletin-independiente.md — fase 1. Y la medida de esta
 * noche está en docs/migracion/noche-2026-08-24/bi-1.md.
 *
 * **Todo lo de aquí es aditivo, y eso es el criterio de aceptación, no un
 * adorno:** `unidades.alumno_id` nace NULL en las que ya existen —o sea «del
 * grupo», que es todo lo que hay hoy—, `matriculas.boletin_independiente` nace
 * 0, `bol_ind_periodos` nace vacía y el interruptor de puestos nace en 1, que
 * es lo de hoy. Con la migración puesta y nadie marcado, **los 1.344 tests
 * pasan sin regenerar un solo snapshot** (§4 del plan). Un snapshot que haya
 * que regenerar aquí no es un snapshot que se regenera: es una consulta a la
 * que se le olvidó el alcance.
 *
 * **Por qué la marca va en `matriculas` y no en `alumnos`, donde vive `nee`:**
 * `alumnos` es global. La marca se arrastraría al año siguiente sin que nadie
 * la ponga y **repintaría los boletines de años pasados**. La matrícula es por
 * año y por grupo, que es el alcance real de la decisión.
 *
 * **Al desplegar** (§10 del plan): son las primeras migraciones que tocan
 * tablas de producción de los dieciséis colegios. `unidades` y `matriculas` son
 * grandes y vivas, y un `ALTER TABLE` sobre `unidades` bloquea la escritura de
 * notas mientras dura. Hay que medir el tamaño real de `unidades` en el colegio
 * más grande antes, y no va a la vez que la fase 2 de las definitivas: las dos
 * tocan el mismo camino de escritura y, si algo sale mal, con las dos dentro no
 * se sabe cuál fue.
 */
class BoletinIndependienteEsqueleto extends Migration
{
    public function up()
    {
        /*
         * 1. La unidad puede tener dueño. NULL = del grupo.
         *
         * El diseño entero cabe en esa frase (§3): las subunidades y las notas
         * no cambian —siguen colgando de la unidad y de la subunidad como
         * siempre—, y por eso `notas` y `notas_finales` no se tocan y el
         * independiente sale en puestos, actas y certificados sin escribir una
         * línea.
         *
         * **`nullable()` no es una comodidad: es la mitad del diseño.** El NULL
         * significa «del grupo», y se compara con `<=>` —el igual null-safe de
         * MySQL— para que una sola condición resuelva las dos ramas. Con `=` a
         * secas la rama del alumno normal devuelve cero filas y **todas las
         * definitivas del colegio se van a 0** sin un solo error en el log.
         */
        Schema::table('unidades', function (Blueprint $tabla) {
            $tabla->unsignedInteger('alumno_id')->nullable()->after('asignatura_id');

            /*
             * El índice lleva las tres columnas en el orden en que preguntan
             * las consultas que hay que acotar: **todas** las clasificadas como
             * «hay que acotarla» filtran por `asignatura_id` y `periodo_id`, y
             * el alcance se añade encima. Con `alumno_id` delante no lo usaría
             * ninguna. Medido en bi-1.md: 29 de las 75 lecturas de `unidades`.
             */
            $tabla->index(['asignatura_id', 'periodo_id', 'alumno_id'], 'unidades_alcance_index');

            /*
             * `ON DELETE CASCADE`, igual que las dos claves foráneas que ya
             * tiene la tabla: si se borra el alumno de verdad, sus unidades no
             * tienen a quién pertenecer. Las del grupo llevan NULL y una clave
             * foránea no mira los NULL, así que no las alcanza.
             */
            $tabla->foreign('alumno_id')->references('id')->on('alumnos')->onDelete('cascade');
        });

        /*
         * 2. La marca, por año, donde vive el año.
         *
         * `NOT NULL DEFAULT 0` y no nullable: aquí no hay un tercer estado que
         * signifique nada. O está marcado o no lo está; «este periodo no» es la
         * tabla de abajo, que es otra pregunta.
         */
        Schema::table('matriculas', function (Blueprint $tabla) {
            $tabla->boolean('boletin_independiente')->default(0)->after('repitente');
        });

        /*
         * 3. La excepción por periodo, que es la petición que decide el diseño:
         *
         *     «Si la marca no debe borrar los datos suministrados en ese
         *      periodo si los puso antes de marcar la opción, pero esos datos
         *      deben ser ignorados en los boletines.»
         *
         * O sea: **el dato y su visibilidad son dos cosas distintas** y hay que
         * guardarlas por separado. La fila que falta significa «lo que diga la
         * matrícula»; `aplica=0` significa «este periodo no, pero no borres
         * nada».
         *
         * **La clave única nace con la tabla, y es deliberado.** `notas_finales`
         * lleva sin ella desde 2014 y de ahí salen los tres síntomas del
         * 10-definitivas.md —definitivas que desaparecen, duplicadas, y notas
         * puestas que no aparecen—. Una tabla nueva sin clave única es el mismo
         * error cometido a sabiendas. Con ella el interruptor se escribe con un
         * `INSERT ... ON DUPLICATE KEY UPDATE` de una línea y **no hay ventana
         * de borrado**.
         */
        Schema::create('bol_ind_periodos', function (Blueprint $tabla) {
            $tabla->increments('id');
            $tabla->unsignedInteger('alumno_id');
            $tabla->unsignedInteger('periodo_id');
            $tabla->boolean('aplica')->default(1);
            $tabla->integer('updated_by')->nullable();
            $tabla->timestamps();

            $tabla->unique(['alumno_id', 'periodo_id'], 'bol_ind_periodos_unico');
            $tabla->foreign('alumno_id')->references('id')->on('alumnos')->onDelete('cascade');
            $tabla->foreign('periodo_id')->references('id')->on('periodos')->onDelete('cascade');
        });

        /*
         * 4. El interruptor de los puestos NO entra aquí: se va a la fase 2.
         *
         * Estaba escrito y medido, y se saca **por lo que costaba, no por lo que
         * valía**: `years.puestos_con_bol_independiente` movía las tres
         * instantáneas de `MuestreoDeLecturasTest` —`api/years`,
         * `api/years/colegio` y `api/years/trashed`— porque `YearsController:27`
         * y `:43` leen con `SELECT *`. Es la §5.ter de
         * ../../docs/migracion/noche-2026-08-24/bi-1.md.
         *
         * **Y se puede sacar porque no lo consume nada**: los ocho sitios que
         * copian `Nota::puestoAlumno` son de la fase 6, y las cuatro rutas de
         * puestos no calculan puesto —devuelven `promedio` y el front pinta la
         * posición de fila—. Así que aquí el interruptor sería una columna que
         * nadie lee moviendo tres respuestas vivas.
         *
         * **Entra con quien lo escriba**, que es lo coherente: un servicio que
         * decide sobre una columna que nadie escribe todavía tiene la mitad
         * positiva sin comprobar.
         *
         * Y la regla que ya está pagada, para cuando vuelva: **el servicio
         * contesta «¿está activado el interruptor?» y NUNCA «¿se enseña el
         * puesto?»**. El front esconde el puesto al `Acudiente` y al `Alumno`
         * aunque el año lo tenga activado; contestar lo segundo o le filtraría el
         * puesto a las familias por su cuenta o dejaría muerta esa regla, las dos
         * en silencio.
         */
    }

    public function down()
    {
        /*
         * Volver atrás **pierde datos** en cuanto alguien haya marcado a
         * alguien, y por eso se dice aquí en vez de en el parte: `down()` borra
         * la tabla del interruptor por periodo y la columna del dueño, o sea
         * que las unidades propias de un independiente **se quedan huérfanas**
         * y vuelven a leerse como del grupo. Mientras nadie esté marcado
         * —que es todo lo que hay esta noche— es exactamente reversible.
         */
        Schema::dropIfExists('bol_ind_periodos');

        Schema::table('matriculas', function (Blueprint $tabla) {
            $tabla->dropColumn('boletin_independiente');
        });

        Schema::table('unidades', function (Blueprint $tabla) {
            $tabla->dropForeign(['alumno_id']);
            $tabla->dropIndex('unidades_alcance_index');
            $tabla->dropColumn('alumno_id');
        });
    }
}
