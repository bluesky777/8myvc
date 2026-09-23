<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * **Lo que cada colegio adapta del compromiso académico, guardado por año.**
 *
 * Diseño en `myvc_front/COMPROMISOS-ACADEMICOS.md` §8. Dos tablas y ninguna
 * columna nueva en `years`, y las dos cosas son decisiones que conviene poder
 * releer.
 *
 * ## El encargo, dicho en una línea
 *
 * *«Que no tengan que estar seleccionando y editando las secciones cada
 * periodo.»* Así que lo primero que hay que fijar es **cada cuánto cambia esto**,
 * porque de ahí sale la clave ajena:
 *
 *   - **Nada de esta configuración es por periodo.** Ni el corte, ni la regla, ni
 *     el texto, ni los firmantes. Lo único que es por periodo es el compromiso
 *     mismo.
 *   - **Es por AÑO**, y por año y no por colegio porque el colegio ya es la
 *     instalación: cada uno tiene su base. `year_id` es la única clave posible.
 *
 * De ahí que la segunda mitad del encargo sea la que de verdad cuesta: por año
 * significa **que en enero hay que heredarla**, y ése es el trabajo de
 * `YearsController::postStore`, no de esta migración.
 *
 * ## Por qué NO son columnas de `years`
 *
 * Era el camino corto, y hay precedente para él: `texto_acta_eval`,
 * `firmantes_acta`, `titulo_certificado_final` y los dos interruptores del
 * certificado viven ahí. Pero medido antes de elegir:
 *
 *   - `years` tiene **80 columnas vivas** —64 del volcado congelado más 16 que
 *     han entrado por migración en 2026— y el bloque de copia de `postStore` son
 *     **56 asignaciones a mano, una por línea** (`YearsController.php:158-297`).
 *     *(La cabecera de `CentinelaDeLasColumnasDelAnioNuevoTest` dice «61 de 68»;
 *     es de agosto y está desfasada. El test no falla por eso porque mide con
 *     `SHOW COLUMNS` en vivo, pero el texto ya no dice la verdad.)*
 *   - Esta configuración son **diecisiete** valores. Meterlos ahí deja `years` en
 *     97 columnas y ese bloque en 73 líneas, y convierte cada interruptor futuro
 *     del módulo en una decisión de centinela por separado.
 *   - Y `years` sale con `SELECT *` en tres métodos de su controlador y va
 *     incrustada en `datos_basicos`, así que **diecisiete columnas mueven ~20
 *     instantáneas de contrato** (`tests/Contrato/Snapshots/*years*.json` y todos
 *     los `boletines*`, `bolfinales*`, `muestreo-*`). Con tabla aparte no se
 *     mueve ninguna.
 *
 * Con tabla propia, la herencia de enero es **una sola línea** en `postStore`
 * —`copiarLaPlantillaDelCompromiso()`, al lado de `copiarLosJefesDeArea()`— y
 * cubre los diecisiete valores y los ocho bloques a la vez.
 *
 * Y hay precedente exacto y reciente: **`config_formulario_inscripcion`**
 * (`2026_09_19_100000_formulario_de_inscripcion.php:235-256`), que es 1:1 con el
 * año por el mismo motivo —*«es del año, no del formulario: la eligió el colegio
 * una vez y la heredan todos los impresos»*—, se crea perezosa con un
 * `ON DUPLICATE KEY UPDATE` y se copia en `YearsController.php:461-466`. Estas
 * dos tablas son su misma familia y se comportan igual.
 *
 * **Lo que esto NO evita, y hay que decirlo:** `CentinelaDeLasTablasDelAnioNuevoTest`
 * saca las tablas por año de `information_schema` contra la base viva. Estas dos
 * aparecen en su censo **el día que se corra esta migración**, y salen en rojo
 * hasta que `postStore` las copie. Eso es a propósito: es el mecanismo que impide
 * que la herencia se olvide, que es justo el fallo que el encargo pide evitar.
 *
 * ## Dos tablas y no una
 *
 * Porque son dos formas distintas y se consultan distinto:
 *
 *   - `config_compromiso` es **1:1 con el año** y la lee el servidor para
 *     **calcular**: `regla` y `corte` deciden a qué alumnos se les propone
 *     compromiso, y eso es un `WHERE`, no un texto. Por eso van en columnas y no
 *     dentro de un JSON: un blob no se puede consultar.
 *   - `compromiso_bloques` es una **lista ordenada de textos largos** que el
 *     colegio enciende, apaga, reordena y reescribe. Es la misma forma que
 *     `desempenos_por_defecto` —la tabla por año que nadie copiaba hasta el 13
 *     sep 2026— y se resuelve igual.
 *
 * ## `text` y no `json`, y una sola lista
 *
 * `firmantes` guarda JSON dentro de una columna `text`, que es como lo hace ya
 * `years.firmantes_acta` (`Informes/ActasEvaluacionController.php:709`,
 * `json_decode` sobre `text`). No se usa el tipo `json` de MySQL en ningún sitio
 * del proyecto y esta migración no lo estrena.
 *
 * Y es la **única** columna con JSON dentro. Todo lo demás son columnas de
 * verdad, incluidos los interruptores, porque la pantalla de reglas cuenta
 * alumnos contra ellos.
 *
 * ## Los ocho bloques no se siembran aquí
 *
 * Esta migración crea las tablas vacías. Los textos por defecto viven en PHP
 * (`App\Support\PlantillaDelCompromiso`) y las filas sólo aparecen **cuando el
 * colegio guarda por primera vez**. Tres razones:
 *
 *   1. Sembrar 8 filas × 16 colegios × cada año que se abra es ruido para los
 *      colegios que no usen el módulo.
 *   2. Un `UPDATE` sobre filas existentes es exactamente lo que el Paso 0 de
 *      `docs/DESPLIEGUE.md` obliga a mirar antes de migrar. Esta migración es
 *      **aditiva pura**: crea dos tablas y no toca una sola fila que ya exista.
 *   3. Y el riesgo de que un defecto cambiado mañana altere un papel ya firmado
 *      no existe, porque **el compromiso congela su texto al crearse**
 *      (`compromisos.texto`, §3.1 del diseño).
 */
class LaPlantillaDelCompromiso extends Migration
{
    public function up()
    {
        /*
         * UNA `Schema::create` POR TABLA, y en este orden.
         *
         * `compromiso_bloques` no depende de `config_compromiso` —las dos cuelgan
         * de `years` y de nada más—, así que el orden es sólo de lectura: primero
         * lo que gobierna el cálculo, después lo que gobierna el papel.
         *
         * **Y cada una con su guarda**, no por elegancia: varias sesiones corren
         * sobre el mismo docker y una de ellas puede haber creado ya la tabla. Es
         * la misma forma que `2026_09_21_100000_descargas_de_planilla.php:81-83`.
         */
        if (Schema::hasTable('config_compromiso')) {
            echo "  config_compromiso: ya existe, no se toca.\n";
        } else {
            Schema::create('config_compromiso', function (Blueprint $tabla) {
                $tabla->increments('id');

                $tabla->unsignedInteger('year_id');

                /* ── Lo que decide a QUIÉN se le propone un compromiso ── */

                /*
                 * `area` o `asignatura`. Hereda lo que el colegio ya tiene decidido para
                 * la reprobación del año —`usa_areas = years.cant_areas_pierde_year > 0`,
                 * `Informes/ActasEvaluacionController.php:148`— pero se guarda aparte
                 * **porque son dos preguntas distintas**: un colegio puede reprobar el
                 * año por áreas y querer el seguimiento del periodo por asignaturas, que
                 * es más fino. Nacer heredado y poder separarse es lo que pidió el
                 * encargo.
                 */
                $tabla->string('regla', 12)->default('area');

                /*
                 * Cuántas perdidas hacen falta. **No es el corte del año**, y es el error
                 * que más caro sale: `years.cant_areas_pierde_year` vale 3 en
                 * `simonbolivar`, y un compromiso que llegue a las 3 llega cuando el
                 * alumno ya está en el límite. El defecto es 3 porque es el número que
                 * Joseth confirmó el 20 sep 2026 para la hoja de riesgo del semáforo
                 * (`myvc_front/app2/src/app/informes/semaforo/riesgo-del-grupo.ts:69`),
                 * y que las dos pantallas señalen a los mismos alumnos vale más que
                 * afinar el número antes de que nadie lo use.
                 */
                $tabla->unsignedTinyInteger('corte')->default(3);

                /*
                 * El parágrafo del SIEP: en 1.º, 2.º y 3.º la promoción se circunscribe a
                 * dos asignaturas. **Hoy esto no existe en ninguna parte del sistema** —ni
                 * columna, ni consulta— así que nace apagado: encenderlo es una decisión
                 * del colegio, no un defecto que se le aparece.
                 *
                 * Apunta a `materias` y no a `asignaturas` porque la asignatura es de un
                 * grupo concreto y esto vale para tres grados enteros.
                 */
                $tabla->boolean('primaria_activa')->default(false);
                $tabla->unsignedInteger('primaria_materia_1_id')->nullable();
                $tabla->unsignedInteger('primaria_materia_2_id')->nullable();

                /* ── El plazo, que es lo que el papel promete ── */

                $tabla->string('plazo_label', 120)->default('Semana de nivelaciones');
                $tabla->unsignedTinyInteger('plazo_dias')->default(5);

                /*
                 * Días hábiles para reclamar el resultado. Sale impreso en el papel, así
                 * que es una promesa del colegio y no un ajuste interno: el plazo empieza
                 * a correr en `compromisos.resultado_entregado_at` (R4 del diseño), no
                 * antes.
                 */
                $tabla->unsignedTinyInteger('dias_reclamacion')->default(5);

                /* ── El encabezado del papel ── */

                $tabla->string('titulo', 160)->nullable();
                $tabla->string('subtitulo', 160)->nullable();
                $tabla->boolean('muestra_escudo')->default(true);
                $tabla->boolean('muestra_foto')->default(true);
                $tabla->boolean('muestra_resolucion')->default(true);

                /* ── Las columnas de la tabla de perdidas ── */

                $tabla->boolean('col_periodos')->default(true);
                $tabla->boolean('col_falta')->default(true);

                /*
                 * Los rótulos de firma, en JSON dentro de `text`, como
                 * `years.firmantes_acta`.
                 *
                 * **Y aquí guarda CARGOS, no personas**, que es la diferencia con su
                 * hermana y la razón de que esta lista sí se herede en enero.
                 * `firmantes_acta` está en la lista de las que nacen vacías a propósito
                 * —«los firmantes se confirman cada año» (Joseth, 31 ago 2026)— porque
                 * guarda nombres y cédulas, y un acta firmada por quien ya no está es
                 * peor que un acta sin firmantes. Esto guarda «Coord. Académico»,
                 * «Titular», «Acudiente», «Estudiante»: los cargos no se van del colegio
                 * en diciembre, y las personas detrás de ellos salen de `Year::datos()`,
                 * que ya se confirma cada año por su lado.
                 */
                $tabla->text('firmantes')->nullable();

                /* ── Entrega y firma ── */

                $tabla->boolean('canal_papel')->default(true);
                $tabla->boolean('canal_push')->default(true);

                /*
                 * El correo nace APAGADO, y es el único de los tres canales que lo hace.
                 * Medido el 22 sep 2026 sobre el volcado de `simonbolivar`: de 1.085
                 * acudientes, **100 tienen correo y 1.020 celular**. Un canal encendido
                 * por defecto que alcanza al 9 % es peor que uno apagado, porque el
                 * colegio cree que avisó.
                 */
                $tabla->boolean('canal_correo')->default(false);

                $tabla->boolean('firma_digital')->default(true);

                /*
                 * La segunda firma (R4). Nace encendida porque es la que arranca el plazo
                 * de reclamación: sin ella el expediente demuestra que el colegio avisó
                 * del problema, no que informó de cómo terminó.
                 */
                $tabla->boolean('pide_segunda_firma')->default(true);

                $tabla->unsignedInteger('created_by')->nullable();
                $tabla->unsignedInteger('updated_by')->nullable();
                $tabla->timestamps();

                /*
                 * Una fila por año y no más. El `unique` es la regla, no una optimización:
                 * dos filas de configuración para el mismo año son dos respuestas a la
                 * misma pregunta, y el código tendría que elegir una.
                 */
                $tabla->unique('year_id', 'config_compromiso_del_anio');

                $tabla->foreign('year_id')->references('id')->on('years')->onDelete('cascade');
                $tabla->foreign('primaria_materia_1_id')->references('id')->on('materias')->onDelete('set null');
                $tabla->foreign('primaria_materia_2_id')->references('id')->on('materias')->onDelete('set null');
            });
        }

        if (Schema::hasTable('compromiso_bloques')) {
            echo "  compromiso_bloques: ya existe, no se toca.\n";

            return;
        }

        Schema::create('compromiso_bloques', function (Blueprint $tabla) {
            $tabla->increments('id');

            $tabla->unsignedInteger('year_id');

            /*
             * La clave estable del bloque: `cita`, `marco`, `considerando`,
             * `determina`, `actores`, `declaracion`, `plan`, `datos`. Es lo que
             * empareja la fila con su defecto en `PlantillaDelCompromiso`, y lo que
             * permite añadir un bloque nuevo al catálogo sin tocar las filas que los
             * colegios ya escribieron.
             *
             * `orden` es aparte **porque el colegio los reordena**: la clave dice qué
             * bloque es, el orden dice dónde va. Mezclarlos obligaría a reescribir
             * ocho filas para mover una.
             */
            $tabla->string('clave', 40);
            $tabla->unsignedSmallInteger('orden')->default(0);
            $tabla->boolean('activo')->default(true);

            /*
             * El encabezado que sale centrado y en mayúsculas («Y CONSIDERANDO QUE:»).
             * `nullable` porque tres de los ocho bloques del formato del Bethel no
             * llevan ninguno: son párrafos corridos.
             */
            $tabla->string('titulo', 160)->nullable();

            /*
             * El texto. `text` y no `string` porque el bloque más largo del formato
             * del Bethel —el Artículo 37, 38, 39 y su parágrafo— pasa de 1.200
             * caracteres él solo.
             */
            $tabla->text('cuerpo')->nullable();

            $tabla->unsignedInteger('created_by')->nullable();
            $tabla->unsignedInteger('updated_by')->nullable();
            $tabla->timestamps();

            /*
             * La lectura es siempre «los bloques de este año, en su orden», así que el
             * índice va en ese orden y no en el alfabético.
             */
            $tabla->index(['year_id', 'orden'], 'compromiso_bloques_del_anio');

            // Un bloque no puede estar dos veces en el mismo año: la segunda fila
            // sería otra respuesta a «qué dice el bloque `considerando`».
            $tabla->unique(['year_id', 'clave'], 'compromiso_bloques_clave_del_anio');

            $tabla->foreign('year_id')->references('id')->on('years')->onDelete('cascade');
        });
    }

    /**
     * Qué se pierde al volver atrás: **la configuración que cada colegio escribió**.
     *
     * Los textos de los ocho bloques, los firmantes, el corte y la regla. No se
     * pierde ningún compromiso ni ninguna nota —esas tablas son de otra migración—
     * pero sí lo que el colegio tecleó en la pantalla de la plantilla, y eso no se
     * reconstruye: los defectos de `PlantillaDelCompromiso` son los genéricos, no
     * los suyos.
     *
     * En orden inverso al `up()`, que aquí da igual porque ninguna de las dos
     * apunta a la otra, pero es la forma de la casa y la que hará falta el día que
     * `compromisos` cuelgue de `config_compromiso`.
     */
    public function down()
    {
        Schema::dropIfExists('compromiso_bloques');
        Schema::dropIfExists('config_compromiso');
    }
}
