<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * **El compromiso académico de un alumno, y el dictamen de cada docente sobre él.**
 *
 * Diseño en `myvc_front/COMPROMISOS-ACADEMICOS.md` §3.1. Su hermana
 * `2026_09_22_100000_la_plantilla_del_compromiso.php` creó lo que el colegio
 * **escribe una vez al año**; ésta crea lo que **le pasa a un alumno concreto**, y
 * las dos mitades se leen juntas: la de allá es configuración y se hereda en
 * enero, la de aquí es historia y no (§8.3, y el apartado del centinela más
 * abajo).
 *
 * ## Lo que NO lleva, que es la decisión más importante del esquema
 *
 * **Ninguna columna de sanción, de matrícula condicional ni de pérdida del cupo.**
 * No es un olvido ni una fase 2: es §1.3 del diseño.
 *
 * El papel que hoy circula —el del Bethel— dice en «SE DETERMINA QUE» que el
 * incumplimiento puede llegar *«hasta inclusive, la cancelación del Contrato de
 * Matrícula y la pérdida del cupo»*. Eso ya no es una acción de apoyo: es una
 * **sanción disciplinaria**, y la Corte Constitucional sólo la valida tras debido
 * proceso completo —cargos, traslado de pruebas, descargos, proporcionalidad—
 * (T-1207/00, T-360/08, T-004/24). Un compromiso académico que lleve esa cláusula
 * dentro y **no** venga de un proceso disciplinario es exactamente el papel que se
 * cae en tutela: el colegio cree que está blindado porque hay una firma, y lo que
 * tiene es una sanción impuesta sin proceso.
 *
 * Por eso la separación se hace **aquí**, en el esquema, y no en la pantalla:
 *
 *   - Un **texto** que el colegio escribe es suyo y puede decir lo que quiera; vive
 *     en `compromiso_bloques` y el sistema no se lo propone (`determina` nace sin
 *     esa cláusula, §8.4).
 *   - Una **columna** es otra cosa: es un dato que el sistema pide, rellena, filtra
 *     e imprime, o sea el software presentando la sanción como el desenlace normal
 *     del apoyo. `condicional`, `pierde_cupo` o `sancion_id` convertirían las
 *     dieciséis instalaciones en eso, y ninguna de las tres se puede añadir después
 *     «por si acaso» sin volver a tener esta conversación.
 *
 * Lo disciplinario tiene su sitio y ya está modelado en otra parte
 * (`dis_procesos`, `dis_acciones_restaurativas`). Cruzarlo con esto es trabajo de
 * un `dis_proceso`, no de una columna de esta tabla.
 *
 * ## Dos tablas y no una (§3.1)
 *
 *   1. **El veredicto es por asignatura y por docente**, no por compromiso. El
 *      encargo lo dice con estas palabras: *«cada docente pueda ir al compromiso y
 *      decir cuáles son los muchachos que tenían compromiso conmigo aquí en
 *      matemática»*. Con una sola tabla —tres asignaturas en una columna de texto—
 *      esa consulta no existe, y `GET compromisos/mios` tampoco.
 *   2. **`nota_al_crear` tiene que congelarse.** Si el papel se reimprime en
 *      diciembre y recalcula, dice otra cosa que la copia que firmó el padre en
 *      septiembre, y entonces el documento no prueba nada. Es el mismo argumento
 *      del art. 16 que sostuvo `notas_finales.nota_original` en las nivelaciones.
 *   3. **Un alumno tiene varios compromisos a lo largo del año** —*«en el segundo
 *      periodo volvió a perder… ahí ya le van a salir dos»*—, así que la cabecera se
 *      repite y las líneas no.
 *
 * ## Las fechas son el núcleo, y por eso son tantas
 *
 * §1.4: **no hay ninguna sentencia que diga que una firma blinda al colegio**. Lo
 * que protege frente a una tutela por no promoción es el **rastro documental
 * fechado**, y de ahí salen los cuatro requisitos que esta tabla tiene que poder
 * demostrar. Cada pareja de columnas es un paso del flujo de §5:
 *
 *     R1  created_at                          el compromiso existió ANTES del plazo
 *     R2  entregado_at + acuse_at             se avisó, y consta que llegó
 *     R3  veredicto_at + veredicto_por        quién dictaminó, y cuándo (en el item)
 *     R4  resultado_entregado_at              se informó CÓMO TERMINÓ
 *         + resultado_acuse_at                y consta que eso también llegó
 *
 * **La pareja de la vuelta no es un adorno y es lo que no sabe hacer ningún formato
 * del país** (§1.5): el plazo para reclamar **no empieza a correr el día en que el
 * docente escribe el veredicto, sino el día en que el acudiente se entera**. Sin
 * `resultado_entregado_at` el colegio no puede sostener que el plazo venció, y un
 * expediente con firma de entrada y sin firma de salida demuestra que se avisó del
 * problema, no que se informó del resultado.
 *
 * Y `resultado_acuse_tipo` es la columna que resuelve el caso que hoy no queda
 * escrito en ninguna parte: el acudiente que **no está de acuerdo**. Si la única
 * salida es firmar, el desacuerdo desaparece del expediente y reaparece meses
 * después como tutela; con `'reclama'` y `reclamacion_texto` vive dentro del propio
 * documento, con su fecha. `reclamacion_vence` se calcula al notificar
 * —`resultado_entregado_at` + `config_compromiso.dias_reclamacion` días hábiles— y
 * se **guarda**, porque los días hábiles dependen del calendario del colegio y
 * recalcularlos en diciembre daría otra fecha que la que se imprimió.
 *
 * ## `decimal(7,4)` en las dos notas, medido y no recordado
 *
 * El diseño lo dice de memoria; la base dice dos cosas distintas y hay que elegir
 * la que corresponde (medido contra el docker, 22 sep 2026):
 *
 *     notas.nota           int             ← la casilla que teclea el docente
 *     notas_finales.nota   decimal(7,4)    ← la DEFINITIVA del periodo
 *
 * `notas_finales` pasó a decimal en `2026_08_30_200000_notas_finales_en_decimal`, y
 * `nota_original` / `nota_nivelacion` la siguieron allí con la misma precisión el 2
 * sep. Lo que el compromiso congela **no es una casilla, es una definitiva** —la
 * nota con la que se pierde la asignatura o el área en ese periodo— y la del área
 * es además un promedio. Guardarla en `int` redondearía 34,6667 a 35 y el papel
 * firmado diría un número distinto del boletín: exactamente el fallo del que habla
 * la razón 2 de §3.1, producido por el tipo de la columna.
 *
 * ## Qué dejan aquí las tres decisiones del 22 sep 2026 (§6)
 *
 *   - **D3 — el veredicto lo pone el docente de la asignatura; el titular ve el de
 *     todos y rellena los que falten al cierre.** Por eso `profesor_id` y
 *     `veredicto_por` son **dos columnas y no una**: la primera dice a quién le
 *     tocaba, la segunda quién lo firmó. Un veredicto del titular y uno del docente
 *     no valen lo mismo y el papel tiene que poder decirlo.
 *   - **D4 — si el alumno ya niveló por la vía normal, se propone el resultado leído
 *     de la nivelación y el docente lo confirma.** Propuesto no es escrito: el item
 *     nace en `'en_espera'` y sigue ahí hasta que el docente lo confirma, porque el
 *     veredicto es suyo y tiene que poder discrepar de la nota. Por eso `resultado`
 *     es anulable y `nota_al_cerrar` también. Y **`asistio` siempre es manual**: la
 *     base no sabe quién fue a la nivelación.
 *   - **D5 — el parágrafo de primaria se implementa.** No deja ni una columna aquí:
 *     el interruptor y las dos materias viven en `config_compromiso` y lo que falta
 *     es el cálculo. Lo que esta tabla aporta es que `regla` y `cantidad_perdidas`
 *     se guardan **congeladas en el compromiso** y no se leen de la configuración al
 *     imprimir: un colegio que apague el parágrafo en noviembre no puede cambiar lo
 *     que dice un papel firmado en septiembre.
 *
 * ## Sin `json` y sin `enum`, por dos motivos distintos
 *
 * **`json` no**, porque producción corre **MariaDB 10.5**: `JSON_TABLE` y `->>` no
 * existen allí aunque el docker (MySQL 8) los acepte (`CLAUDE.md:419-421`). Aquí
 * además no haría falta ni queriendo: no hay una sola lista que guardar.
 *
 * **`enum` tampoco, y no por MariaDB** —tres migraciones de 2026 lo usan en `years`
 * y funciona—. Es por lo que cuesta después: `estado` y `resultado` son vocabularios
 * que van a crecer (D8 deja abierto qué pasa con el expediente «notificado sin
 * acuse»), y añadir un valor a un `enum` es un `MODIFY COLUMN` sobre una tabla cuyas
 * filas son **documentos firmados** — justo la clase de migración que el Paso 0 de
 * `docs/DESPLIEGUE.md` obliga a mirar colegio por colegio. Con `varchar` el valor
 * nuevo no toca ninguna fila. Y hay una segunda razón: `regla` se compara con
 * `config_compromiso.regla`, que ya es `string(12)`; dos vocabularios con dos tipos
 * distintos se separan en silencio.
 *
 * ## Claves ajenas: dos clases de columna, y una regla para cada una
 *
 * La tabla tiene punteros de dos naturalezas y **no se tratan igual**:
 *
 *   - **«De qué es»** —`matricula_id`, `year_id`, `compromiso_id`, `asignatura_id`,
 *     `area_id`, `profesor_id`— llevan clave ajena. Sin su destino la fila no se
 *     puede ni leer.
 *   - **«Quién firmó»** —`creado_por`, `acuse_por`, `cerrado_por`,
 *     `resultado_acuse_por`, `veredicto_por`— **no llevan ninguna**, igual que
 *     `notas.nivelada_por` y `notas_finales.nivelada_por`, que son `integer` pelado
 *     desde el 2 sep 2026 y por esta misma razón.
 *
 * No es comodidad, está medido: **la papelera borra de verdad**.
 * `ProfesoresController.php:666` hace `forceDelete` sobre un profesor y arrastra
 * *«31 tablas en cascada, siete saltos»*; `GruposController.php:929` lo hace sobre
 * un grupo y arrastra 27. Con ese endpoint delante, las tres salidas de una clave
 * ajena en una columna de firma son malas:
 *
 *     cascade   borrar un profesor borraría el renglón de un documento firmado
 *     set null  borrar un profesor dejaría un veredicto sin autor, que es la prueba
 *     restrict  borrar un profesor pasaría a dar un 1451 en una pantalla que hoy va
 *
 * Sin clave ajena, el id sobrevive al borrado y el nombre se resuelve por `left
 * join`; si la persona ya no está, el papel imprime lo que sepa. Es lo contrario de
 * lo que hizo `dis_procesos.added_by → users ON DELETE CASCADE`, que borra un
 * proceso disciplinario entero porque alguien dio de baja una cuenta.
 *
 * `profesor_id` **sí** la lleva, con `set null`, porque ahí es un puntero y no una
 * firma: `asignaturas.profesor_id` ya es anulable —una asignatura puede no tener
 * docente asignado— y es exactamente lo que decidió
 * `descargas_de_planilla.profesor_id`.
 *
 * **`matricula_id` es la primera clave ajena del esquema que apunta a
 * `matriculas`** (medido: cero `REFERENCES matriculas` en
 * `database/schema/mysql-schema.sql`). No hay nada que la impida —`matriculas.id`
 * es `int unsigned`, InnoDB, con `PRIMARY KEY (id)`— y se pone en `cascade` a
 * propósito: si alguien borra de verdad una matrícula, el compromiso de esa
 * matrícula no es un huérfano que conservar, es un papel de un alumno que ya no
 * está en ese grupo. Ese borrado hoy viene siempre del grupo, y el grupo ya se
 * lleva las matrículas por la misma vía.
 *
 * ## El `unique` que NO va, que es lo que hay que explicar
 *
 * §3.1 dice que *«la clave natural es `(matricula_id, periodo)`»*, y aun así aquí va
 * un **índice** y no un `unique`. Tres razones:
 *
 *   1. Es una clave de **lectura**, no una regla. El diseño la nombra para decir que
 *      *«el historial se lee solo»*, y en ningún punto dice que dos compromisos del
 *      mismo periodo estén prohibidos. Los hay legítimos: el que se abre después de
 *      un «no niveló», y el de área junto al de asignatura de un colegio que use las
 *      dos reglas. Un `unique` los prohibiría para siempre y sólo se podría quitar
 *      con un `ALTER` sobre documentos firmados.
 *   2. **No convive con el borrado lógico.** Todas sus vecinas —`matriculas`,
 *      `acudientes`, `asignaturas`, `profesores`— tienen `deleted_at`, y el día que
 *      ésta lo tenga un compromiso anulado bloquearía la creación del bueno. El
 *      error saldría como `1062 Duplicate entry` en mitad de una creación **en
 *      lote**, que es la pantalla más cara de este módulo (con corte 1 en
 *      `simonbolivar` salen 505 candidatos, §6 D1).
 *   3. Lo que de verdad hay que evitar —el doble clic del botón grupal— se evita en
 *      el controlador, donde se puede **contestar** («este alumno ya tiene uno de
 *      este periodo, ¿lo abro?») en vez de reventar con un 1062. El índice de abajo
 *      hace que esa comprobación sea una lectura indexada.
 *
 * En `compromiso_items` sí hay dos `unique`, y ahí la razón es la contraria: ver su
 * comentario.
 *
 * ## Aditiva pura, y el centinela que se pondrá rojo a propósito
 *
 * **Crea dos tablas y no toca una sola fila que ya exista** — Paso 0 de
 * `docs/DESPLIEGUE.md`: el `UPDATE` que hay que mirar antes de migrar aquí no
 * existe. No siembra nada: un colegio que no use el módulo se queda con dos tablas
 * vacías.
 *
 * Y las dos `create` van con su guarda y su `echo` porque **varias sesiones corren
 * sobre el mismo docker** y una puede haber creado ya la tabla; es la forma de
 * `2026_09_21_100000_descargas_de_planilla.php:81-83` y la de su hermana.
 *
 * **`compromisos` lleva `year_id` y NO se hereda en enero.** El día que esta
 * migración corra, `CentinelaDeLasTablasDelAnioNuevoTest` la verá aparecer en su
 * censo de `information_schema` y se pondrá **rojo** hasta que se declare en
 * `DATOS_DEL_ANIO` — que es lo correcto y lo que §8.3 dejó escrito por adelantado:
 * *«lo que el colegio escribió para decir cómo trabaja se copia; lo que ocurrió
 * porque ese año se vivió, no»*. Un compromiso copiado a enero sería un papel con
 * notas congeladas de otro año y dos firmas que nadie dio.
 *
 * `compromiso_items` **no lleva `year_id` y no es un olvido**: cuelga de
 * `compromiso_id` y el año lo lleva su cabecera. Ese centinela no la mira, como no
 * mira `colillas_inscripcion` ni `notas_estacion`; y su respuesta sería la misma.
 */
class ElCompromisoAcademico extends Migration
{
    public function up()
    {
        /*
         * `compromisos` primero porque `compromiso_items` apunta a ella. Aquí el
         * orden sí importa: la clave ajena de la segunda no se puede crear antes que
         * la primera.
         */
        if (Schema::hasTable('compromisos')) {
            echo "  compromisos: ya existe, no se toca.\n";
        } else {
            Schema::create('compromisos', function (Blueprint $tabla) {
                $tabla->increments('id');

                /*
                 * La matrícula y no el alumno: un compromiso es de un alumno **en un
                 * grupo y en un año**, y es la matrícula la que lo dice. El grado que
                 * sale impreso se lee por ahí, y el alumno que se cambia de grupo en
                 * octubre no arrastra el papel del grupo anterior.
                 */
                $tabla->unsignedInteger('matricula_id');

                /*
                 * Redundante con `matricula → grupo → year`, y a propósito: es por
                 * donde filtran todas las pantallas del sistema, y es lo que hace que
                 * el centinela de enero vea esta tabla y obligue a decidirla (arriba).
                 */
                $tabla->unsignedInteger('year_id');

                /*
                 * El ordinal, 1..4, y no `periodo_id`. Es lo que se imprime («cursado
                 * el 50 % del año»), es la mitad de la clave natural de §3.1, y es lo
                 * que obliga D6.2: el renglón del boletín tiene que poder decir **de
                 * qué periodo es el compromiso**, porque el resultado llega después de
                 * cerrarlo y si no se lee al revés —el boletín del periodo 4 llevando
                 * el compromiso del 3—.
                 */
                $tabla->unsignedTinyInteger('periodo');

                /* ── Con qué regla se contó, congelada ── */

                /*
                 * `asignatura` o `area`. Se **copia** de `config_compromiso.regla` al
                 * crear y no se lee de allí al imprimir: la configuración es de hoy y
                 * el papel es del día en que se firmó. Mismo tipo que su origen
                 * (`string(12)`) para que las dos se comparen sin sorpresas.
                 */
                $tabla->string('regla', 12)->default('area');

                /*
                 * Cuántas perdía cuando se le creó. Congelada por el mismo motivo, y
                 * con un motivo extra que trae D5: con el parágrafo de primaria
                 * encendido este número no es el total del alumno sino el de las dos
                 * materias que el colegio eligió, y esa configuración puede cambiar.
                 */
                $tabla->unsignedTinyInteger('cantidad_perdidas')->default(0);

                /*
                 * «Cursado el N % del año escolar». Sale de `periodo × 25` hoy, y se
                 * guarda porque no todos los colegios tienen cuatro periodos: en uno
                 * de tres el mismo `periodo = 2` es 67 % y no 50 %. Un papel no puede
                 * cambiar de porcentaje porque el colegio reordenara su calendario.
                 */
                $tabla->unsignedTinyInteger('porcentaje_ano')->default(0);

                /*
                 * **El texto del compromiso, congelado al crear.** Es la copia que se
                 * firmó. Si se leyera de `compromiso_bloques` al imprimir, un colegio
                 * que retoque su plantilla en diciembre reescribiría un documento
                 * firmado en septiembre — la razón 2 de §3.1 aplicada al texto, y la
                 * que hace que `compromiso_bloques` pueda editarse sin miedo.
                 */
                $tabla->text('texto')->nullable();

                /*
                 * El plazo que el papel promete: la semana de nivelaciones. R1 se lee
                 * contra esto —`created_at` es anterior a `plazo_desde`— y es lo que
                 * demuestra que el apoyo se ofreció **antes**, no que se documentó
                 * después.
                 */
                $tabla->date('plazo_desde')->nullable();
                $tabla->date('plazo_hasta')->nullable();

                /*
                 * `borrador` → `entregado` → `cerrado` → `notificado`. El último es el
                 * de R4: cerrado es que el colegio ya sabe el resultado, notificado es
                 * que **la familia** ya lo sabe, y entre los dos está el plazo de
                 * reclamación. Que sean dos estados y no uno es todo el punto de §5.10.
                 */
                $tabla->string('estado', 12)->default('borrador');

                /*
                 * Quién lo creó (`users.id`), sin clave ajena — ver la cabecera. No es
                 * anulable: un compromiso que no diga quién lo abrió no sirve como
                 * prueba de nada, y el servidor siempre lo sabe.
                 */
                $tabla->unsignedInteger('creado_por');

                /*
                 * R1: `created_at` **no se edita nunca**. El esquema aporta que la
                 * columna exista y nada más la escriba; el modelo no expone un
                 * `setCreatedAt` y el controlador no la acepta del cliente.
                 */
                $tabla->timestamps();

                /* ── La ida: se entrega y el acudiente acusa (R2, §5.4-5.6) ── */

                /*
                 * `dateTime` y no `timestamp` en todas las fechas de prueba, que es lo
                 * que ya decidió `auditoria` (18 §1.2) y repitieron las nivelaciones:
                 * `TIMESTAMP` convierte con la zona de la sesión de MySQL y nadie la
                 * fija. Una fecha que se imprime en un documento no puede depender de
                 * eso.
                 */
                $tabla->dateTime('entregado_at')->nullable();

                // 'push' | 'correo' | 'papel' | 'app'. Se guarda POR QUÉ CANAL y no
                // sólo que se entregó: el papel imprime cuál de los dos ocurrió (D7,
                // la aceptación digital acompaña a la firma en papel, no la sustituye).
                $tabla->string('entrega_canal', 20)->nullable();

                $tabla->dateTime('acuse_at')->nullable();

                // `acudientes.id` — la primera firma. Sin clave ajena (cabecera).
                $tabla->unsignedInteger('acuse_por')->nullable();
                $tabla->string('acuse_canal', 20)->nullable();

                /* ── El cierre: el colegio ya tiene todos los veredictos ── */

                $tabla->dateTime('cerrado_at')->nullable();
                $tabla->unsignedInteger('cerrado_por')->nullable();

                /* ── Y la vuelta del resultado (R4), que es la pareja que falta en
                 *    todos los formatos del país ── */

                /*
                 * El día que el colegio informa **cómo terminó**. Es el que arranca el
                 * plazo de reclamación, y no `cerrado_at`: el plazo no corre desde que
                 * el docente escribe el veredicto sino desde que el acudiente se
                 * entera.
                 */
                $tabla->dateTime('resultado_entregado_at')->nullable();
                $tabla->string('resultado_canal', 20)->nullable();

                // La SEGUNDA firma. Mismo valor probatorio que la primera y por eso
                // mismas columnas: quién, cuándo, por dónde.
                $tabla->dateTime('resultado_acuse_at')->nullable();
                $tabla->unsignedInteger('resultado_acuse_por')->nullable();

                /*
                 * `enterado` | `reclama`. **La columna que deja constancia del
                 * desacuerdo.** Si la única salida fuese firmar, el acudiente que no
                 * está de acuerdo no quedaría escrito en ninguna parte y reaparecería
                 * meses después como tutela.
                 */
                $tabla->string('resultado_acuse_tipo', 10)->nullable();
                $tabla->text('reclamacion_texto')->nullable();

                /*
                 * Se calcula al notificar —`resultado_entregado_at` +
                 * `config_compromiso.dias_reclamacion` días hábiles— y se guarda: los
                 * días hábiles dependen del calendario del colegio, y recalcular esta
                 * fecha en diciembre daría una distinta de la impresa. Con D8 en la
                 * opción (a), es esta fecha la que deja el expediente en firme cuando
                 * nadie firma.
                 */
                $tabla->date('reclamacion_vence')->nullable();

                /*
                 * El tablero del coordinador: «los compromisos de ESTE año y ESTE
                 * periodo, por estado» —`GET compromisos` filtra por grupo, periodo y
                 * estado, y el recuento de §5.8 («12 de 18 con veredicto») se saca de
                 * aquí—. El orden de las columnas es ése porque es el orden en que se
                 * fijan: el año siempre, el periodo casi siempre, el estado a veces. Y
                 * de paso sirve de índice a la clave ajena de `year_id`.
                 */
                $tabla->index(['year_id', 'periodo', 'estado'], 'compromisos_del_periodo');

                /*
                 * La clave natural de §3.1, como índice y no como `unique` (cabecera).
                 * Sirve a tres lecturas: el historial del alumno, la pantalla de la
                 * familia (`GET compromisos/de-alumno`), el renglón del boletín del
                 * periodo (D6.2) — y a la comprobación de duplicado que sustituye al
                 * `unique`.
                 */
                $tabla->index(['matricula_id', 'periodo'], 'compromisos_del_alumno');

                $tabla->foreign('matricula_id')->references('id')->on('matriculas')->onDelete('cascade');
                $tabla->foreign('year_id')->references('id')->on('years')->onDelete('cascade');
            });
        }

        if (Schema::hasTable('compromiso_items')) {
            echo "  compromiso_items: ya existe, no se toca.\n";
        } else {
            Schema::create('compromiso_items', function (Blueprint $tabla) {
                $tabla->increments('id');

                $tabla->unsignedInteger('compromiso_id');

                /*
                 * Una de las dos, nunca las dos, según `compromisos.regla`. No se
                 * fuerza con un `CHECK` a propósito: el Schema Builder no tiene API
                 * para uno, MySQL 5.7 —donde todavía corre parte del parque— los
                 * **ignora en silencio**, y una restricción que existe en unos
                 * colegios y en otros no es peor que ninguna, porque el código tendría
                 * que defenderse igual. Lo comprueba el controlador al crear.
                 */
                $tabla->unsignedInteger('asignatura_id')->nullable();
                $tabla->unsignedInteger('area_id')->nullable();

                /*
                 * A quién le TOCA el veredicto: el docente de la asignatura (D3).
                 * Anulable porque `asignaturas.profesor_id` lo es —una asignatura
                 * puede no tener docente asignado, y un año que acaba de nacer las
                 * tiene todas así— y porque entonces lo rellena el titular al cierre.
                 * Es un puntero, no una firma: por eso sí lleva clave ajena.
                 */
                $tabla->unsignedInteger('profesor_id')->nullable();

                /*
                 * **La nota congelada**, y la razón de que esta tabla exista (razón 2
                 * de §3.1). `decimal(7,4)` porque es una **definitiva** y las
                 * definitivas son `notas_finales.nota decimal(7,4)` desde el 30 ago
                 * 2026 —no `notas.nota`, que es `int` y es la casilla del docente—; la
                 * del área es además un promedio. Ver la cabecera.
                 *
                 * No anulable: un item sin la nota que lo justifica es el papel que
                 * recalcula, o sea justo lo que esto viene a impedir. Que el `INSERT`
                 * falle es la respuesta correcta.
                 */
                $tabla->decimal('nota_al_crear', 7, 4);

                /*
                 * **Lo único nuevo que aporta el veredicto**, y siempre manual: la base
                 * no sabe quién fue a la nivelación (D4). Tres estados y no dos:
                 * `null` es «el docente no ha contestado», que no es lo mismo que «no
                 * asistió» — y la diferencia es la que sostiene R3 del colegio, porque
                 * «se le convocó y no fue» es prueba y «nadie contestó» no.
                 */
                $tabla->boolean('asistio')->nullable();

                /*
                 * `nivelo` | `no_nivelo` | `en_espera`. Nace nulo y, con D4, el
                 * controlador puede **proponer** el leído de `notas.nota_nivelacion`;
                 * propuesto no es escrito: hasta que el docente confirma se queda en
                 * `en_espera`, porque el veredicto es suyo y tiene que poder discrepar
                 * de la nota.
                 */
                $tabla->string('resultado', 12)->nullable();

                // La nota con la que se cerró. Mismo tipo que su hermana por el mismo
                // motivo: las dos se imprimen una al lado de la otra.
                $tabla->decimal('nota_al_cerrar', 7, 4)->nullable();

                $tabla->string('observacion', 255)->nullable();

                /*
                 * R3: **quién dictaminó y cuándo, con fecha y autor propios**,
                 * distintos de los del compromiso. Con D3 estas dos columnas son las
                 * que distinguen el veredicto del docente del que rellenó el titular al
                 * cierre, y el papel tiene que poder decirlo. `veredicto_por` apunta a
                 * `profesores.id` y **no lleva clave ajena** (cabecera): la papelera
                 * borra profesores de verdad y una firma no se borra con su firmante.
                 */
                $tabla->unsignedInteger('veredicto_por')->nullable();
                $tabla->dateTime('veredicto_at')->nullable();

                $tabla->timestamps();

                /*
                 * **Aquí el `unique` sí procede**, al revés que en la cabecera: la misma
                 * asignatura dos veces en el mismo compromiso no es un caso legítimo,
                 * es un renglón repetido en un documento firmado y dos veredictos que
                 * se contradicen. El reintento de una creación en lote es el camino
                 * real hacia él.
                 *
                 * Y son **dos** `unique` y no uno con las tres columnas, aprovechando
                 * que en SQL dos `NULL` nunca colisionan: el de abajo sólo vigila las
                 * filas de asignatura —en las de área, `asignatura_id` es nulo y el
                 * índice las deja pasar— y el otro sólo las de área. Uno solo sobre
                 * `(compromiso_id, asignatura_id, area_id)` no vigilaría ninguna de las
                 * dos, porque siempre habría un nulo dentro. Vale igual en MySQL y en
                 * MariaDB 10.5.
                 *
                 * El primero hace además de índice de la clave ajena `compromiso_id`,
                 * que es la lectura de «el compromiso con sus items» para el papel.
                 */
                $tabla->unique(['compromiso_id', 'asignatura_id'], 'compromiso_items_asignatura_unica');
                $tabla->unique(['compromiso_id', 'area_id'], 'compromiso_items_area_unica');

                /*
                 * `GET compromisos/mios`: «mis items sin veredicto». Por eso el orden es
                 * docente y después resultado —el pendiente es `resultado IS NULL`— y no
                 * al revés: un índice que empiece por `resultado` tiene tres valores y
                 * no descarta nada.
                 */
                $tabla->index(['profesor_id', 'resultado'], 'compromiso_items_del_docente');

                /*
                 * `cascade` desde la cabecera: un item sin su compromiso no es nada.
                 *
                 * Y `cascade` también en `asignatura_id` y `area_id`, que parece
                 * discutible y no lo es: el borrado duro que de verdad ocurre es el del
                 * grupo (`GruposController.php:929`, *«27 tablas en cascada»*), y ése
                 * se lleva por delante las asignaturas **y las matrículas**, o sea que
                 * la cabecera de este item ya se ha ido por su propia clave ajena. Un
                 * `restrict` aquí no salvaría el documento: sólo convertiría ese
                 * borrado en un 1451.
                 */
                $tabla->foreign('compromiso_id')->references('id')->on('compromisos')->onDelete('cascade');
                $tabla->foreign('asignatura_id')->references('id')->on('asignaturas')->onDelete('cascade');
                $tabla->foreign('area_id')->references('id')->on('areas')->onDelete('cascade');
                $tabla->foreign('profesor_id')->references('id')->on('profesores')->onDelete('set null');
            });
        }
    }

    /**
     * Qué se pierde al volver atrás: **los expedientes enteros**.
     *
     * No es configuración, que se vuelve a teclear: son los compromisos firmados de
     * los alumnos con sus notas congeladas, las dos firmas del acudiente con sus
     * fechas, los veredictos de cada docente y las reclamaciones escritas. **Nada de
     * eso se reconstruye**: `notas` ya no dice lo que decía en septiembre, y una
     * firma no se vuelve a pedir.
     *
     * No se pierde ninguna nota ni ninguna configuración del módulo —`notas`,
     * `notas_finales`, `config_compromiso` y `compromiso_bloques` son de otras
     * migraciones y ésta no las toca—, así que un `rollback` de esta tanda deja el
     * colegio como estaba **menos** los expedientes.
     *
     * En orden inverso al `up()`, y aquí no es cortesía: `compromiso_items` apunta a
     * `compromisos` y al revés fallaría.
     */
    public function down()
    {
        Schema::dropIfExists('compromiso_items');
        Schema::dropIfExists('compromisos');
    }
}
