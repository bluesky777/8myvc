<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Nivelaciones: las columnas. Fase 1 del plan (`myvc_front/PLAN-NIVELACIONES-Y-RUBRICAS.md`
 * §3.2, §3.3 y §3.5), tarea A3 del reparto, contrato en
 * `docs/migracion/22-nivelaciones.md`.
 *
 * ## Aditiva pura: `nota` NO cambia de significado
 *
 * Lo intuitivo era que `notas.nota` pasara a ser la original y se añadiera
 * `nota_recuperacion`. Rompía a todo el que ya lee `notas.nota` —boletines,
 * certificados, puestos, definitivas, `myvc_flutter` y el front legacy—, y con
 * quince colegios desplegados uno a uno eso es «entre el día del backend y el del
 * front, los boletines imprimen la nota perdida». Se hace al revés: **`nota` sigue
 * siendo la vigente**, la que va al boletín, y lo nuevo cuelga al lado. Ningún
 * lector existente se entera. Es la decisión 5 del plan, y la propiedad que hace
 * que esta migración se pueda desplegar a los quince **sin avisar**.
 *
 * ## Todo `NULL` salvo la regla, y `NULL` no es `0`
 *
 * `nota_original` en `NULL` es «nunca se niveló»; en `0` sería «se niveló viniendo
 * de cero». La celda está nivelada ⇔ `nota_original IS NOT NULL`, y no hay
 * bandera aparte porque sería un segundo sitio donde mentir. Lo mismo en
 * `notas_finales`, donde `recuperada` **conserva su significado** (`1` ⇔ viene de
 * una nivelación) y lo que se gana es poder decir de dónde venía.
 *
 * `nota_nivelacion` no estaba en el plan y hacía falta: bajo la regla `topada` un
 * 90 que queda en 70 desaparecería del sistema, y bajo `mayor` un 40 que no
 * supera al 55 no dejaría rastro de qué sacó el estudiante. El art. 16 del 1290
 * pide «el estado de la evaluación con sus novedades»; una nivelación cuyo
 * resultado no está escrito en ninguna parte no es una novedad registrada.
 * Aprobada por la coordinación el 2 sep 2026; trasladada a Joseth.
 *
 * ## UN `Schema::table` por tabla, y esto es de lo que se rompe
 *
 * En MySQL 8.0 un `ADD COLUMN` es `ALGORITHM=INSTANT` y no cuesta nada. **Pero no
 * todos los colegios están en 8.0** —`create_auditoria_table` ya deja escrito que
 * son cuentas de cPanel distintas y que 5.7 se comporta de otra manera, y la
 * versión de cada uno no está verificada—. En 5.7 cada sentencia `ALTER`
 * **reconstruye la tabla entera** bloqueando las escrituras mientras dura, y
 * `notas` es la tabla grande. Cinco `Schema::table` seguidos serían cinco
 * reconstrucciones en vez de una; por eso las cinco columnas de `notas` van en
 * **una sola** llamada, que Laravel emite como un solo `ALTER TABLE ... ADD, ADD,
 * ADD`. Quien añada la sexta el mes que viene la mete en **este** bloque si la
 * migración no ha salido, y en un bloque único propio si ya salió.
 *
 * Eso no es pérdida de datos: es una ventana en la que los docentes no pueden
 * calificar. En semana de cierre de periodo es un incidente. Antes de programarla:
 * `SELECT VERSION()` colegio por colegio, y en los que no sean 8.0, fuera de
 * horario y con copia previa de `notas` (§6.2 del reparto).
 *
 * ## `years.regla_nivelacion` le pone una política a los quince de golpe
 *
 * `NOT NULL DEFAULT 'topada'` —la redacción más frecuente en los SIEE: la
 * nivelación se topa en la mínima aprobatoria— es la única columna con valor de
 * nacimiento, y **afirma algo sobre el manual de convivencia de cada colegio**. No
 * hace nada hasta que el front de la fase 3 llegue a ese colegio, pero **hay que
 * confirmar con cada rectoría que su SIEE dice eso** antes de que la pantalla
 * exista (§6.7 del reparto). Está preguntado a Joseth; escribir la migración es
 * libre, cuándo se corre y en qué colegios es lo que espera decisión.
 *
 * Se copia de un año al siguiente en `YearsController::postStore`, con las demás:
 * el centinela de las columnas del año nuevo no deja que se olvide.
 *
 * ## Lo que esta migración NO hace, a propósito
 *
 * No rellena `nota_original` desde `bitacoras`: sus fechas están en dos zonas sin
 * nada en la fila que diga cuál (`create_auditoria_table` §4.1), y reconstruir
 * historia de ahí es inventarla en una constancia firmada. **`nota_original`
 * empieza vacía en los quince colegios** y se llena sólo con lo que se nivele de
 * aquí en adelante (§6.6 del reparto). Y no toca `recuperacion_final`: sus
 * metadatos de acta son la tarea A9, con su propia migración.
 */
class NivelacionesColumnas extends Migration
{
    public function up()
    {
        // Las cinco de `notas` en UNA llamada: un solo ALTER, una sola
        // reconstrucción en 5.7. Ver la cabecera antes de partirlo.
        Schema::table('notas', function (Blueprint $tabla) {
            // `integer` y no `decimal`: `notas.nota` es `int` y la original es
            // exactamente lo que había en `nota`. Cambiar el tipo aquí sería
            // guardar la misma nota con dos precisiones.
            $tabla->integer('nota_original')->nullable()->default(null)->after('nota');
            $tabla->integer('nota_nivelacion')->nullable()->default(null)->after('nota_original');
            // `dateTime` y no `timestamp`: es lo que decidió `auditoria` (18 §1.2)
            // —`TIMESTAMP` convierte con la zona de la sesión de MySQL y nadie la
            // fija—, y el acta de una nivelación es una fecha que se imprime.
            $tabla->dateTime('nivelada_at')->nullable()->default(null)->after('nota_nivelacion');
            $tabla->integer('nivelada_por')->nullable()->default(null)->after('nivelada_at');
            $tabla->string('nivelacion_obs', 255)->nullable()->default(null)->after('nivelada_por');
        });

        // Las tres de `notas_finales`, ídem. `decimal(7,4)` para que case con
        // `nota` desde `2026_08_30_200000_notas_finales_en_decimal`.
        Schema::table('notas_finales', function (Blueprint $tabla) {
            $tabla->decimal('nota_original', 7, 4)->nullable()->default(null)->after('nota');
            $tabla->dateTime('nivelada_at')->nullable()->default(null)->after('nota_original');
            $tabla->integer('nivelada_por')->nullable()->default(null)->after('nivelada_at');
        });

        // Una fila por colegio y año: aquí no hay ventana que medir. Lo que hay
        // es la política de nacimiento, ver la cabecera.
        Schema::table('years', function (Blueprint $tabla) {
            $tabla->string('regla_nivelacion', 20)->default('topada')->after('nota_minima_aceptada');
        });
    }

    /*
     * Volver atrás es quitar las columnas. Se pierden **las nivelaciones registradas
     * desde el despliegue y nada más**: `nota` nunca dejó de ser la vigente, así que
     * todo sigue leyéndose igual (§6.8 del reparto). También en un solo ALTER por
     * tabla, por lo mismo que arriba.
     */
    public function down()
    {
        Schema::table('notas', function (Blueprint $tabla) {
            $tabla->dropColumn(['nota_original', 'nota_nivelacion', 'nivelada_at', 'nivelada_por', 'nivelacion_obs']);
        });

        Schema::table('notas_finales', function (Blueprint $tabla) {
            $tabla->dropColumn(['nota_original', 'nivelada_at', 'nivelada_por']);
        });

        Schema::table('years', function (Blueprint $tabla) {
            $tabla->dropColumn('regla_nivelacion');
        });
    }
}
