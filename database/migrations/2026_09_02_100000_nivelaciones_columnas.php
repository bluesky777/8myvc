<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Nivelaciones: las columnas. Fase 1 del plan (`myvc_front/PLAN-NIVELACIONES-Y-RUBRICAS.md`
 * §3.2, §3.3 y §3.5), tareas A3, A8 y A9 del reparto, contrato en
 * `docs/migracion/22-nivelaciones.md`.
 *
 * ## Esto fueron TRES migraciones hasta el 4 sep 2026, y la fusión es lo que
 * ## cumple la regla que las tres declaraban
 *
 * Eran `2026_09_02_100000_nivelaciones_columnas`, `2026_09_02_200000_nivelacion_de_la_definitiva`
 * (tarea A8) y `2026_09_02_300000_acta_de_la_recuperacion_final` (tarea A9). Se
 * fusionaron aquí **antes de desplegarse nunca**, con el despliegue congelado por
 * Joseth a la espera de la Play Store; el día que salgan, esto ya no se puede hacer.
 *
 * **La fusión no es recuento: quita una reconstrucción de tabla en el peor caso.**
 * Las tres cabeceras pedían lo mismo a gritos —*«UN `Schema::table` por tabla, y esto
 * es de lo que se rompe»*— y entre ficheros se contradecían: la de las 20:00 abría un
 * **segundo** `ALTER` sobre `notas_finales`, la tabla que la de las 10:00 acababa de
 * reconstruir. Ahora las cinco columnas de `notas_finales` van en **una sola** llamada,
 * como las cinco de `notas`.
 *
 * Y eso muerde justo donde está la única incógnita abierta del día del despliegue:
 * **nadie sabe qué MySQL corren los diecisiete colegios** —no está en ninguno de los dos
 * documentos de despliegue—, y en 5.7 cada sentencia `ALTER` reconstruye la tabla entera
 * bloqueando las escrituras. Medido: **4.870 ms contra 11,8**. En 8.0 los `ADD COLUMN`
 * son `INSTANT` y esto no se nota; en 5.7 es una reconstrucción de `notas_finales`
 * menos. *La fusión es reducción de riesgo, no menos ficheros.*
 *
 * ## El orden FÍSICO de las columnas es el mismo que producían las tres, y se comprobó
 *
 * Importa porque este proyecto lee con `SELECT *` por todas partes y las instantáneas de
 * contrato fijan el orden de los campos. Las tres por separado dejaban
 * `notas_finales` así: `nota, nota_original, nota_nivelacion, nivelada_at, nivelada_por,
 * nivelacion_obs` —la de las 20:00 metía `nota_nivelacion` **entre** dos que ya existían,
 * con `after('nota_original')`—. La cadena de `after` de aquí abajo reproduce esa misma
 * posición columna a columna. **Si alguien reordena estas declaraciones «para que se lean
 * mejor», mueve campos de sitio en respuestas vivas y lo que se rompe son las
 * instantáneas, no la migración.**
 *
 * ## Aditiva pura: `nota` NO cambia de significado
 *
 * Lo intuitivo era que `notas.nota` pasara a ser la original y se añadiera
 * `nota_recuperacion`. Rompía a todo el que ya lee `notas.nota` —boletines,
 * certificados, puestos, definitivas, `myvc_flutter` y el front legacy—, y con
 * diecisiete colegios desplegados uno a uno eso es «entre el día del backend y el del
 * front, los boletines imprimen la nota perdida». Se hace al revés: **`nota` sigue
 * siendo la vigente**, la que va al boletín, y lo nuevo cuelga al lado. Ningún
 * lector existente se entera. Es la decisión 5 del plan, y la propiedad que hace
 * que esta migración se pueda desplegar a los diecisiete **sin avisar**.
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
 * ## Las dos que le faltaban al acta de la DEFINITIVA (lo que era A8)
 *
 * El plan (§3.3) sólo pedía tres para `notas_finales` —`nota_original`, `nivelada_at`,
 * `nivelada_por`—. Al escribir el endpoint se vio que el mismo argumento que justificó
 * `notas.nota_nivelacion` vale igual en el nivel de la asignatura, y por eso entraron
 * con su decisión escrita en vez de colarse sin más:
 *
 * - **`nota_nivelacion`** — bajo la regla `topada`, una definitiva de 28 que
 *   niveló 45 queda en 35, y sin esta columna **el 45 no está en ninguna parte**.
 *   Es el art. 16 del 1290 otra vez: «el estado de la evaluación con sus
 *   novedades». Una novedad cuyo resultado no se guarda no está registrada.
 * - **`nivelacion_obs`** — con qué actividad se superó. `notas` la tiene y la
 *   constancia de desempeño se imprime desde los dos niveles; que el indicador
 *   pueda decir «taller de refuerzo» y la asignatura no, es una asimetría que
 *   aparecería el día de imprimir, no hoy.
 *
 * `recuperada` **sigue significando lo mismo** (`1` ⇔ viene de una nivelación) y
 * `manual` también. Lo que se gana es que ahora la fila puede decir de dónde venía
 * y con qué.
 *
 * ## El acta de la recuperación del AÑO (lo que era A9)
 *
 * Contrato en `docs/migracion/22-nivelaciones.md` §6 y §9.
 *
 * `recuperacion_final` es **el único sitio del proyecto que ya guardaba la nota de
 * la recuperación aparte** en vez de pisar la original: por eso el plan (§3.3) dice
 * que del nivel del año «sólo falta la pantalla». Lo que no tiene es el acta —
 * cuándo, quién y con qué actividad—, y el art. 16 del 1290 pide «las novedades
 * académicas»: una novedad sin fecha ni responsable no es una novedad.
 *
 * Las tres nacen `NULL`. **No se rellenan hacia atrás**: las recuperaciones ya
 * escritas se quedan sin acta, porque `updated_by` dice quién la tocó la última vez
 * y `updated_at` cuándo, y **no es lo mismo** que quién registró la recuperación.
 * Copiar uno en otro sería inventar un acta, que es exactamente lo que el §6.6 del
 * reparto prohíbe hacer desde `bitacoras`.
 *
 * `recuperacion_final.year` guarda el **número** del año y no el id, y convertirlo
 * es un refactor de permisos ya analizado y decidido en `PeriodoDeLaFila`
 * (`todosLosDelAnio`, y de ahí sale que esta tabla exija **todos** los periodos
 * abiertos en vez de uno). El plan lo deja fuera a propósito (§7) y esta migración
 * también: mezclarlo convertiría tres columnas de acta en un cambio de claves.
 *
 * `observacion` y no `nivelacion_obs` como en las otras dos tablas: aquí la fila
 * **entera** es la recuperación, así que el prefijo no distingue nada de nada. En
 * `notas` y `notas_finales` sí, porque la fila existe antes de la nivelación.
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
 * ADD` — y por eso las de `notas_finales` van ahora igual. Quien añada la sexta el
 * mes que viene la mete en **este** bloque si la migración no ha salido, y en un
 * bloque único propio si ya salió.
 *
 * Eso no es pérdida de datos: es una ventana en la que los docentes no pueden
 * calificar. En semana de cierre de periodo es un incidente. Antes de programarla:
 * `SELECT VERSION()` colegio por colegio, y en los que no sean 8.0, fuera de
 * horario y con copia previa de `notas` (§6.2 del reparto).
 *
 * ## `years.regla_nivelacion` le pone una política a los diecisiete de golpe
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
 * **Y es la columna de la que cuelga el despliegue entero**: la nombra
 * `ContextoDeUsuario::construir()` en las cuatro ramas del `switch`, y ese `SELECT` lo
 * dispara el propio guard. Con el código nuevo y la base sin migrar no se puede ni
 * iniciar sesión (`docs/DESPLIEGUE.md` §⛔). También es la que `2026_09_04_100000_horario_versiones`
 * necesita ya puesta para su `->after('regla_nivelacion')`: **esta migración va antes que
 * la del horario, y el prefijo `09_02` es lo único que lo garantiza.**
 *
 * ## Lo que esta migración NO hace, a propósito
 *
 * No rellena `nota_original` desde `bitacoras`: sus fechas están en dos zonas sin
 * nada en la fila que diga cuál (`create_auditoria_table` §4.1), y reconstruir
 * historia de ahí es inventarla en una constancia firmada. **`nota_original`
 * empieza vacía en los diecisiete colegios** y se llena sólo con lo que se nivele de
 * aquí en adelante (§6.6 del reparto).
 *
 * ## Y una trampa que nace CON la fusión: una base a medias queda inalcanzable
 *
 * Al conservar el nombre más antiguo de las tres, una base que tuviera aplicada la
 * vieja `2026_09_02_100000` **y no** las otras dos ve esta migración como `Ran` y
 * **sus columnas nuevas no llegan nunca**: `migrate` no la vuelve a correr y no hay
 * fichero que pedirle. La salida es **reconstruir la base, no migrarla**
 * (`tools/construir-bd-test.sh`). A los diecisiete colegios no les afecta —no tienen
 * ninguna de las ocho—; a las bases de sesión sí, y `simonbolivar_testing_h` está
 * exactamente en ese estado desde antes de esta fusión.
 *
 * La base de desarrollo `simonbolivar` tiene las tres aplicadas, así que su esquema es
 * correcto y **le quedan dos filas fantasma** en `migrations` apuntando a ficheros que
 * ya no existen. No hace nada: `migrate:status` sólo lista las que tienen fichero.
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

        // Las CINCO de `notas_finales`, también en una sola llamada — tres venían de
        // aquí y dos de la que era `2026_09_02_200000`. El orden de los `after`
        // reproduce exactamente la posición física que dejaban las dos por separado:
        // `nota_nivelacion` va ENTRE `nota_original` y `nivelada_at`. Ver la cabecera.
        //
        // `decimal(7,4)` en las tres de nota para que casen con `nota` desde
        // `2026_08_30_200000_notas_finales_en_decimal`: guardar la nivelación con menos
        // precisión que la nota que produce sería perder decimales justo en la
        // comparación que el boletín imprime.
        Schema::table('notas_finales', function (Blueprint $tabla) {
            $tabla->decimal('nota_original', 7, 4)->nullable()->default(null)->after('nota');
            $tabla->decimal('nota_nivelacion', 7, 4)->nullable()->default(null)->after('nota_original');
            $tabla->dateTime('nivelada_at')->nullable()->default(null)->after('nota_nivelacion');
            $tabla->integer('nivelada_por')->nullable()->default(null)->after('nivelada_at');
            $tabla->string('nivelacion_obs', 255)->nullable()->default(null)->after('nivelada_por');
        });

        // Las tres del acta de la recuperación del año, lo que era
        // `2026_09_02_300000`. Una sola llamada por lo mismo que arriba:
        // `recuperacion_final` es pequeña, pero el criterio no depende del tamaño
        // —depende del número de sentencias— y quien copie este bloque para otra
        // tabla se lleva el criterio con él.
        Schema::table('recuperacion_final', function (Blueprint $tabla) {
            $tabla->dateTime('nivelada_at')->nullable()->default(null)->after('nota');
            $tabla->integer('nivelada_por')->nullable()->default(null)->after('nivelada_at');
            $tabla->string('observacion', 255)->nullable()->default(null)->after('nivelada_por');
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
     * todo sigue leyéndose igual (§6.8 del reparto) — y con ellas las actas de la
     * definitiva y de la recuperación del año. Las notas que produjeron **no** se
     * pierden. También en un solo ALTER por tabla, por lo mismo que arriba.
     */
    public function down()
    {
        Schema::table('notas', function (Blueprint $tabla) {
            $tabla->dropColumn(['nota_original', 'nota_nivelacion', 'nivelada_at', 'nivelada_por', 'nivelacion_obs']);
        });

        Schema::table('notas_finales', function (Blueprint $tabla) {
            $tabla->dropColumn(['nota_original', 'nota_nivelacion', 'nivelada_at', 'nivelada_por', 'nivelacion_obs']);
        });

        Schema::table('recuperacion_final', function (Blueprint $tabla) {
            $tabla->dropColumn(['nivelada_at', 'nivelada_por', 'observacion']);
        });

        Schema::table('years', function (Blueprint $tabla) {
            $tabla->dropColumn('regla_nivelacion');
        });
    }
}
