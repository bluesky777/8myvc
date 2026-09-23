<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * **Las dos columnas que el papel del compromiso prometía y no podía imprimir.**
 *
 * Diseño en `myvc_front/COMPROMISOS-ACADEMICOS.md` §8.8.1. Sus dos hermanas de esta
 * semana crearon lo que el colegio configura (`..._100000_la_plantilla_del_compromiso`)
 * y lo que le pasa a un alumno (`..._200000_el_compromiso_academico`); ésta cierra un
 * agujero que sólo se vio cuando alguien intentó **imprimir de verdad**.
 *
 * ## El agujero, dicho en una línea
 *
 * `config_compromiso` tiene dos interruptores —`col_periodos` («columna con las notas de
 * cada periodo») y `col_falta` («columna de inasistencias»)— que la pantalla de
 * configuración le ofrece al colegio, y **`compromiso_items` sólo congelaba
 * `nota_al_crear`**. Con eso, la hoja tenía dos salidas y las dos eran malas:
 *
 *   1. **Imprimir las columnas vacías.** Un documento que se firma con cuatro casillas
 *      en blanco donde el colegio pidió números.
 *   2. **Ir a buscarlas a `boletines/*` y `ausencias/*`.** Datos **vivos** en una hoja
 *      congelada, que es exactamente lo que prohíbe §3.1: si el papel se reimprime en
 *      diciembre y dice otra cosa que la copia firmada en septiembre, el documento no
 *      prueba nada. Es la razón 2 de §3.1 otra vez, esta vez por la puerta de atrás.
 *
 * Mientras tanto el front hace lo único honesto: la tabla sale sin ellas y la pantalla
 * dice que el colegio las pidió y por qué no están
 * (`myvc_front/app2/src/app/informes/compromiso/compromiso.ts:323-331`). Ese aviso es el
 * que esta migración viene a apagar, y de ahí sale el nombre `faltas_al_crear`: **lo
 * bautizó el front** (`compromiso.ts:108`) antes de que existiera.
 *
 * ## Todo `nullable`, y ésa es la decisión más importante del fichero
 *
 * **Cero y desconocido no son lo mismo en un documento que se firma.**
 *
 * Los compromisos creados antes de que estas cinco columnas existieran no las tienen, y
 * los que se creen desde hoy sí. Un `default 0` haría que los viejos afirmaran «este
 * alumno no faltó nunca» — una afirmación que nadie midió, impresa en un papel que un
 * acudiente firma. Con `NULL` la hoja puede decir «no se congeló» y dejar la casilla con
 * una raya, que es lo que ya hace con los marcadores que no se pueden resolver (§8.8.4:
 * *«un marcador que no se puede resolver no se borra»*).
 *
 * Por lo mismo la columna **no se rellena hacia atrás**. Reconstruir hoy las faltas de un
 * compromiso de septiembre sería leer `ausencias` **de hoy**, o sea justo el dato vivo
 * que esto viene a impedir; y la nota de un periodo ya nivelado ya no es la que se
 * imprimió. Un dato inventado con la cara del dato bueno es peor que la casilla vacía.
 *
 * ## `faltas_al_crear`: qué cuenta exactamente, medido antes de elegir el tipo
 *
 * Un recuento mal definido en un papel firmado es peor que no tenerlo, así que hay que
 * decir **cuál de los números del sistema es éste**. En el proyecto conviven dos
 * criterios sobre estas mismas filas, y `AusenciasController.php:124-129` ya lo tenía
 * escrito: *«unos endpoints cuentan filas con `COUNT(*)` y otros suman
 * `cantidad_ausencia`»*.
 *
 *     A) COUNT(au.id) WHERE cantidad_ausencia > 0      boletín FINAL, promovidos,
 *        agrupado por (alumno, periodo, asignatura)     certificados
 *        `BolfinalesController.php:218-223`
 *
 *     B) SUM(CASE WHEN tipo='ausencia' THEN cantidad_ausencia ELSE 0 END)
 *        `Nota::alumnoPeriodoDetalle` (`Nota.php:461-475`), y el mismo criterio escrito
 *        como SQL en `BoletinPorCompetenciasController::faltasPorAsignatura`
 *        (`BoletinPorCompetenciasController.php:770-777`)
 *
 * **Se congela el B**, y no por elegancia: el compromiso es de **un periodo**, y el
 * documento con el que tiene que cuadrar es el **boletín de ese periodo**, que es el que
 * la familia ya leyó. El A es el criterio del boletín *final*, que es otro papel.
 *
 * Medido el 22 sep 2026 contra `8myvc-database-1`, sobre filas vivas:
 *
 *     simonbolivar   46.478 filas    A = 44.309    B = 44.307    difieren en 2 (0,005 %)
 *     caz_zaragoza      914 filas    A =    502    B =    502    difieren en 0
 *
 * O sea que los dos criterios **casi** coinciden, y conviene saber por qué: porque
 * `cantidad_ausencia` vale 1 o `NULL` y nunca más —`MAX(cantidad_ausencia) = 1` en las
 * dos bases—, así que «sumar cantidades» y «contar filas» dan hoy el mismo número. La
 * frase del controlador —*«una fila puede valer más de una falta»*— **describe lo que el
 * esquema permite, no lo que hay dentro**. La diferencia de 2 filas son las que traen
 * `cantidad_ausencia = 1` con un `tipo` que no es `'ausencia'`.
 *
 * ### Son faltas de CLASE, y eso no hay que filtrarlo: sale solo
 *
 * §3.1 de la configuración dice *«cuenta faltas, nunca porcentaje: el sistema no tiene
 * días hábiles para calcularlo»*, así que esto es **un entero de sesiones de clase
 * perdidas**, no días y no un porcentaje. Y lo de «clase» está medido:
 *
 *     entrada = 1 (portería)   352/352 filas en zaragoza y 17/17 en simonbolivar
 *                              tienen `asignatura_id IS NULL`
 *
 * Como el item es de una asignatura (o de un área, que son sus asignaturas), el recuento
 * **nunca puede alcanzar una falta de portería**: no hay que excluirla, no llega. Es
 * exactamente lo que dice el esquema muerto de `df_asignaturas`, que sólo tiene
 * `perN_ausencias_clases` y no su pareja `_instituc` — la `_instituc` vive en
 * `df_alumnos`, que es por alumno, porque la falta de portería no es de ninguna materia.
 *
 * ### Y no se congelan las tardanzas
 *
 * El interruptor dice «columna de inasistencias», no «columna de asistencia». Sumar las
 * dos en una columna sería repetir el fallo que `AusenciasController.php:131-133` ya
 * señaló: *«la columna “Total” del informe viejo suma las dos sin decirlo»*. El día que
 * el colegio pida tardanzas en el papel, eso es otro interruptor y otra columna.
 *
 * ### El tipo: `unsignedSmallInteger`
 *
 * Es un recuento y no puede ser negativo. El techo medido por (alumno, asignatura,
 * periodo) es **93** en simonbolivar y 50 en zaragoza; un item de **área** suma las
 * asignaturas del área, así que el número real que se guardará es mayor que ése — y aun
 * multiplicándolo por las diez o doce asignaturas de un área queda a tres órdenes de
 * magnitud de los 65.535 que caben. `unsignedTinyInteger`, que es lo que usan `corte` y
 * `cantidad_perdidas`, **no vale**: 255 se alcanza con un área y un alumno que falte.
 *
 * ## Las notas por periodo: CUATRO columnas y no una, y aquí está el precedente
 *
 * La pregunta era una sola: cuatro `decimal(7,4)` o una columna que las lleve dentro. Se
 * miró qué hace el resto del proyecto con «varios periodos en una fila» antes de decidir,
 * y hay tres respuestas y ninguna es un blob:
 *
 *   - **`dis_libro_rojo`** — viva, escrita por
 *     `Disciplina/ComportamientoController.php:168-192`: `fecha_per1`, `per1_col1`,
 *     `per1_col2`, `per1_col3`, y lo mismo para 2, 3 y 4. Doce columnas de texto en una
 *     fila por alumno y año.
 *   - **`df_asignaturas`** — el precedente **exacto**, dato por dato:
 *     `perN_definitiva decimal(7,4)` y `perN_ausencias_clases int`, que es literalmente
 *     lo que esta migración añade. Va dicho con su asterisco: las seis tablas `df_*` son
 *     **código muerto censado** —cero filas y cero referencias en `app/` y `routes/`,
 *     `docs/migracion/09-pendientes.md` §c—, así que valen como precedente **de forma**,
 *     no como prueba de que alguien las mantiene.
 *   - **`df_alumnos`** — `perN_puntaje decimal(7,4)`, `perN_notas_perdidas`,
 *     `perN_ausencias_clases`, `perN_ausencias_instituc`.
 *
 * Y una razón que no es de precedente sino de motor: **producción es MariaDB 10.5**, o
 * sea sin `json`, sin `JSON_TABLE` y sin `->>` (el docker es MySQL 8 y los acepta: no
 * sirve de prueba). Una columna sola sería entonces un `text` con JSON o CSV dentro, y
 * eso ya lo decidió `config_compromiso` en su cabecera: *«un blob no se puede
 * consultar»*. Con cuatro columnas, «los compromisos cuyo periodo 3 bajó de 30» es un
 * `WHERE`; con un blob es PHP recorriendo filas.
 *
 * ### Que sean CUATRO está medido, y el riesgo va escrito
 *
 * Los 27 años de las tres bases del docker tienen **exactamente cuatro periodos,
 * numerados 1..4** (`SELECT COUNT(*) GROUP BY year_id` sobre `periodos`: simonbolivar 9
 * años, caz_zaragoza 10, micolev1_la_hermosa 8; ninguno con otro número).
 *
 *   - Un colegio de **tres** periodos deja `per4_nota` en `NULL`, y el `nullable` de
 *     arriba hace que eso se lea como «no lo hay», no como «sacó cero».
 *   - Un colegio de **cinco** perdería el quinto. Hoy no existe ninguno en el docker, y
 *     los trece colegios que no están aquí no se han medido. Queda escrito porque el día
 *     que aparezca, el arreglo es una sexta columna y no un rediseño — y el delator será
 *     que el papel enseñe cuatro casillas donde el boletín enseña cinco.
 *
 * ### El nombre y el tipo
 *
 * `perN_nota` con el prefijo delante, que es la forma del esquema (`per1_definitiva`,
 * `per1_puntaje`, `per1_col1`) y además el nombre exacto con el que
 * `Area::agrupar_asignaturas` ya devuelve este mismísimo número en memoria
 * (`Area.php:279-291`).
 *
 * `decimal(7,4)` y no `int`, por lo mismo que `nota_al_crear` y con la medición delante
 * (22 sep 2026, `SHOW COLUMNS` en vivo):
 *
 *     notas.nota           int             ← la casilla que teclea el docente
 *     notas_finales.nota   decimal(7,4)    ← la DEFINITIVA del periodo, que es ésta
 *
 * Lo que se congela sale de `notas_finales.nota`, y la del área es además un promedio
 * (`AVG(nf.nota)`, `CompromisosController.php:792`). En `int`, 34,6667 se imprimiría 35 y
 * el papel firmado diría un número distinto del boletín.
 *
 * ## `perN_nota` y `nota_al_crear` se solapan a propósito
 *
 * Para N = `compromisos.periodo`, las dos guardan el mismo número. No es redundancia
 * descuidada: `nota_al_crear` **no es anulable** y es la nota con la que se aplicó la
 * regla —la que justifica que este item exista—, mientras que las cuatro `perN_nota` son
 * **el renglón de la tabla** y pueden faltar enteras (un compromiso viejo, un colegio que
 * apagó `col_periodos`, un periodo que aún no se ha cursado). Quitar una para deducirla
 * de la otra ataría la existencia del item al interruptor de una columna del papel.
 *
 * Lo que sí hay que vigilar, y va en el informe para quien escriba `postStore`: **las dos
 * tienen que salir de la misma consulta**. Si no cuadran, el papel se contradice solo.
 *
 * ## Aditiva pura
 *
 * **Añade cinco columnas y no toca una sola fila que ya exista** — Paso 0 de
 * `docs/DESPLIEGUE.md`: aquí no hay `UPDATE` que mirar, ni siembra, ni relleno hacia
 * atrás. Un colegio que no use el módulo se queda con cinco columnas nulas en una tabla
 * vacía. Y no mueve ninguna instantánea de contrato: `compromiso_items` no sale con
 * `SELECT *` en ningún endpoint —`CompromisosController.php:1547` nombra sus columnas una
 * a una—, así que lo que se añada aquí no aparece en una respuesta hasta que alguien lo
 * escriba.
 *
 * Y va **con guarda columna por columna**, no por elegancia: varias sesiones corren sobre
 * el mismo docker y una puede haber corrido esto a medias. Es la forma de
 * `2026_09_22_800000_el_blanco_del_acta_es_uno_solo.php:129-133`, con la diferencia de
 * que aquí se mira cada columna y no la primera: un `up()` interrumpido entre la segunda
 * y la tercera tiene que poder terminar el trabajo al reintentarse.
 */
class ElCompromisoCongelaSusColumnas extends Migration
{
    /**
     * Las cinco, en el orden en que se añaden y en el que salen en el papel.
     *
     * @var list<string>
     */
    private const COLUMNAS = [
        'faltas_al_crear',
        'per1_nota',
        'per2_nota',
        'per3_nota',
        'per4_nota',
    ];

    public function up()
    {
        /*
         * Si la tabla no está, esta migración no tiene nada que hacer y **no es un
         * error**: su hermana `..._200000_el_compromiso_academico` la crea, y el orden
         * por nombre ya las pone en fila. El `return` limpio existe para el caso de un
         * `migrate` a medio camino en un docker compartido.
         */
        if (! Schema::hasTable('compromiso_items')) {
            echo "  compromiso_items: no existe todavía, no hay nada que congelar.\n";

            return;
        }

        $faltan = [];

        foreach (self::COLUMNAS as $columna) {
            if (! Schema::hasColumn('compromiso_items', $columna)) {
                $faltan[] = $columna;
            }
        }

        if ($faltan === []) {
            echo "  compromiso_items: las cinco columnas ya existen, no se toca.\n";

            return;
        }

        Schema::table('compromiso_items', function (Blueprint $tabla) use ($faltan) {
            /*
             * **Las inasistencias de esta asignatura (o de esta área) en el periodo del
             * compromiso, contadas el día en que se creó.**
             *
             * Sesiones de clase perdidas, criterio B de la cabecera: el mismo con el que
             * el boletín de ese periodo imprimió su columna de faltas. No son días, no
             * son tardanzas y no es un porcentaje —el sistema no tiene días hábiles para
             * calcularlo (§3.1)—. En un item de área es la **suma** de las asignaturas
             * del área, que es lo que hace `Area.php:77-81`.
             *
             * `NULL` es «no se congeló», y no «no faltó». Esa distinción es todo el
             * punto de esta migración: ver la cabecera.
             */
            if (in_array('faltas_al_crear', $faltan, true)) {
                $tabla->unsignedSmallInteger('faltas_al_crear')->nullable()->after('nota_al_crear');
            }

            /*
             * **La definitiva de cada periodo, congelada**: el renglón que `col_periodos`
             * quiere imprimir.
             *
             * `decimal(7,4)` porque sale de `notas_finales.nota`, que es decimal desde el
             * 30 ago 2026, y porque la del área es un promedio (`AVG`). Anulables las
             * cuatro, y cada una por su cuenta:
             *
             *   - el periodo aún no se ha cursado (un compromiso del 2 casi siempre deja
             *     el 3 y el 4 en nulo, y eso es la verdad, no un hueco);
             *   - el colegio tiene tres periodos y el cuarto no existe;
             *   - el docente no montó nada y no hay definitiva que leer;
             *   - el compromiso es anterior a esta migración.
             *
             * Los cuatro casos se leen igual desde el papel —una raya— y ninguno se
             * puede confundir con un cero, que en un colegio que califica sobre 50 con
             * mínima 30 es una afirmación grave.
             */
            foreach ([1, 2, 3, 4] as $n) {
                if (in_array('per'.$n.'_nota', $faltan, true)) {
                    $tabla->decimal('per'.$n.'_nota', 7, 4)
                        ->nullable()
                        ->after($n === 1 ? 'faltas_al_crear' : 'per'.($n - 1).'_nota');
                }
            }
        });

        echo '  compromiso_items: añadidas '.implode(', ', $faltan).".\n";
    }

    /**
     * Qué se pierde al volver atrás: **lo único que el papel no puede recalcular**.
     *
     * Las faltas y las cuatro notas por periodo de cada compromiso ya firmado. No se
     * pierde ningún compromiso, ningún veredicto, ninguna firma ni ninguna nota
     * —`compromisos`, `compromiso_items.nota_al_crear`, `notas_finales` y `ausencias` son
     * de otras migraciones y ésta no las toca—, así que un `rollback` deja el módulo
     * funcionando **menos** las dos columnas del papel, que vuelven a salir con su aviso.
     *
     * Y no se reconstruyen. Volver a correr el `up()` deja las columnas en `NULL`: el
     * número de septiembre ya no está en `ausencias` ni en `notas_finales`, porque esas
     * dos tablas siguen vivas y por eso existía esta migración.
     *
     * El `dropColumn` va con su guarda por lo mismo que el `up()`, y en una sola llamada
     * porque MySQL y MariaDB sueltan las cinco en un solo `ALTER`.
     */
    public function down()
    {
        if (! Schema::hasTable('compromiso_items')) {
            return;
        }

        $existentes = [];

        foreach (self::COLUMNAS as $columna) {
            if (Schema::hasColumn('compromiso_items', $columna)) {
                $existentes[] = $columna;
            }
        }

        if ($existentes === []) {
            return;
        }

        Schema::table('compromiso_items', function (Blueprint $tabla) use ($existentes) {
            $tabla->dropColumn($existentes);
        });
    }
}
