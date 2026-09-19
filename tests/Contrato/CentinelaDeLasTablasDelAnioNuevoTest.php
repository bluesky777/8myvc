<?php

namespace Tests\Contrato;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Que `YearsController::postStore` no se deje ninguna **tabla** por año.
 *
 * Es el hermano de `CentinelaDeLasColumnasDelAnioNuevoTest`, y nace por la
 * puerta que aquél **no** vigila. Aquél compara las columnas vivas de `years`
 * con las que escribe `postStore`; ésta compara las **tablas que llevan
 * `year_id`** con las que ese mismo método copia. Son dos preguntas distintas y
 * las dos se han fallado ya:
 *
 *     puestos_con_bol_independiente  columna de `years` sin copiar   31 ago 2026
 *     subunidades_por_defecto        tabla hija sin copiar            2 sep 2026
 *     competencias                   tabla por año sin copiar        13 sep 2026  (tabla retirada el 17 sep)
 *     desempenos_por_defecto         tabla por año sin copiar        13 sep 2026
 *
 * Las dos últimas son las que pagan este fichero. Se crearon el 13 sep 2026
 * (Fases 2 y 3 del [35](../../docs/migracion/35-el-modelo-de-evaluacion-del-colegio.md)),
 * son por año, y **nadie las copiaba**: el colegio que escribiera su plan de área
 * en 2026 lo habría encontrado vacío en enero de 2027. Es la §1.bis del doc 28
 * otra vez —las subunidades por defecto estuvieron sin copiarse **durante años**—
 * y tiene la misma forma que la hace cara: **no rompe nada el día que pasa, no
 * deja una línea en ningún log, y se nota en enero**, cuando ya nadie relaciona
 * una pantalla vacía con haber creado un año hace dos semanas.
 *
 * ## De qué depende que esto siga sirviendo, que es lo que mató a la vigilancia anterior
 *
 * El doc 28 §5.0 dijo «este centinela no está escrito» y, mientras no lo estuvo,
 * lo único que impedía el fallo era **acordarse**. El alcance de
 * `unidades_por_defecto` se copió en septiembre *porque alguien se acordó*, no
 * porque nada lo impidiera; las competencias, dos semanas después, no.
 *
 * Así que lo que hay que defender no es «que la lista esté bien hoy» sino **que
 * no pueda quedarse vieja en silencio**. Cuatro mecanismos, y ninguno es una
 * lista a mano:
 *
 * 1. **La población se pregunta a la base, no a un fichero.** Las tablas por año
 *    salen de `information_schema` contra la base viva, así que una tabla creada
 *    mañana entra en el censo **sin que nadie toque este test**, y como no la
 *    copia nadie y no tiene excepción, sale roja el mismo día. Es la misma razón
 *    por la que el hermano usa `SHOW COLUMNS` y no el volcado congelado.
 * 2. **Lo que se copia se lee del fuente, siguiendo las llamadas.** No hay una
 *    lista de «tablas que se copian»: se leen los `INSERT` de `postStore` **y de
 *    los métodos privados que llama**, que es lo que hace que `periodos` —que se
 *    escribe en `crearLosPeriodos`— y el plan de área —en `copiarElPlanDeArea`—
 *    cuenten sin nombrarlos aquí.
 * 3. **Las excepciones caducan solas.** `ninguna_excepcion_sobra` borra la
 *    entrada en cuanto la tabla deja de existir o pasa a copiarse. Una excepción
 *    a algo que ya no pasa es exactamente cómo una lista deja de decir la verdad
 *    sin que nada falle.
 * 4. **Y lo declarado se comprueba contra lo que de verdad escribe crear un
 *    año**, en `YearsTest::test_crear_un_ano_no_escribe_en_las_tablas_de_datos_del_ano`.
 *    Ahí está la mitad que este fichero no puede ver: aquí se lee el fuente, allí
 *    se mira la base después de un `POST years/store`. **Dos fuentes que tienen
 *    que coincidir**, que es la regla de la casa para cualquier cifra que
 *    importe.
 *
 * ## Lo que este test comprueba, y lo que NO
 *
 * Comprueba que **cada tabla por año está nombrada**: o la copia `postStore`, o
 * está en una de las tres listas de excepciones **con su motivo**. Lo que no
 * comprueba es que la copia esté **completa** —una tabla que se copie dejándose
 * media columna pasa este centinela—; eso es la pregunta del hermano, columna a
 * columna, y para las tablas nuevas lo miran sus tests en `YearsTest`.
 *
 * Y no ve las **tablas hijas sin `year_id`**, que es por donde entró
 * `subunidades_por_defecto`: aquélla cuelga de `unidades_por_defecto`, no del
 * año. Ese tercer censo no existe todavía y **decirlo aquí es más honesto que
 * dejar creer que este fichero lo cubre**.
 */
class CentinelaDeLasTablasDelAnioNuevoTest extends TestCase
{
    private const FICHERO = 'app/Http/Controllers/YearsController.php';

    /** La tabla que el test crea y borra para verse a sí mismo en rojo. */
    private const SONDA = 'sonda_del_centinela_de_tablas';

    /**
     * Las que **no se copian porque son datos DEL año**, con el porqué de cada una.
     *
     * La línea que separa esta lista de las que se copian es una sola pregunta:
     * **¿esto lo escribió el colegio para decir cómo evalúa, o lo produjo el año
     * al vivirse?** Las escalas, las frases, los requisitos, la plantilla de notas
     * y el plan de área son lo primero: se escriben una vez y valen para siempre,
     * así que no copiarlas obliga al colegio a reescribirlas cada enero. Éstas son
     * lo segundo: un proceso disciplinario, un PIAR firmado, una votación o un
     * horario subido **pasaron en un año concreto**, y copiarlos al siguiente no
     * sería ahorrarle trabajo a nadie — sería **fabricar historia que no ocurrió**.
     *
     * ## El par que se lee al revés: el plan de área **sí** se copia y `rubricas` **no**
     *
     * > **Aquí ponía `competencias` y esa tabla ya no existe** (17 sep 2026): «competencia» y
     * > «desempeño» resultaron ser la misma cosa, sobraba un piso y quedó
     * > `desempenos_por_defecto` sola — `docs/migracion/39-el-modelo-plano-por-competencias.md`.
     * > **El razonamiento no se movió ni un milímetro**, sólo el nombre de la tabla, y por eso se
     * > corrige en vez de reescribirse: el par sigue siendo el sitio donde se ve por qué dos cosas
     * > que parecen iguales están decididas al revés.
     *
     * Son las dos únicas tablas de este censo que hablan de **cómo se evalúa una
     * materia**, están decididas en direcciones contrarias, y leídas seguidas
     * parecen una contradicción. No lo son, y el sitio donde se ve la diferencia
     * tiene que ser éste y no dos ficheros distintos:
     *
     *     desempenos_    SE COPIA      El plan de área. Lo escribe el colegio —o el
     *     por_defecto                  jefe de área— por **materia y grado**, una vez,
     *                                  y vale para siempre. No copiarlo obliga a
     *                                  reescribir el plan de área cada enero, que es
     *                                  literalmente el fallo que pagó este fichero el
     *                                  13 sep 2026.
     *
     *     rubricas       NO SE COPIA   La matriz criterios × niveles con la que **un
     *                                  docente** evaluó **una asignatura concreta** de
     *                                  ESE año. Cuelga de `asignatura_id` —que el año
     *                                  nuevo vuelve a crear con otro id— y sirvió para
     *                                  poner notas que ya están puestas. (D27.)
     *
     * O sea: **es la misma pregunta de arriba, contestada bien las dos veces.** La
     * competencia es *lo que el colegio escribió para decir cómo evalúa*; la rúbrica
     * es *lo que un docente usó para evaluar*, y sólo existe porque ese año se vivió.
     * El parecido engaña porque las dos son «texto de evaluación»; lo que las separa
     * es **de quién son y a qué se enganchan** — la competencia a una materia y un
     * grado, que en enero siguen ahí; la rúbrica a una asignatura de un año que ya
     * terminó.
     *
     * **Sin el porqué escrito, un centinela se convierte en un `@ignore` que nadie
     * vuelve a mirar**, y ése —no el falso positivo— es el modo de fallo que hay
     * que evitar: una lista de nombres a secas hace que la salida barata ante un
     * rojo sea añadir la tabla aquí, que es lo contrario de lo que este test
     * existe para forzar. Añadir una entrada **es tomar una decisión sobre el
     * colegio**. Si no se sabe el motivo, la tabla no va aquí: va copiada, o va a
     * `SIN_DECIDIR` y se pregunta.
     *
     * @var array<string, string>
     */
    public const DATOS_DEL_ANIO = [
        // La bitácora. Copiarla sería firmar en el año nuevo escrituras que hizo
        // otra gente en el año viejo: no es que sobre, es que sería falsa.
        'auditoria' => 'la bitácora de lo que pasó ESE año; copiarla fabricaría historia que no ocurrió',

        // Y es una decisión ya escrita **dos veces** en `postStore`: ni el titular
        // del grupo ni el docente de la asignatura se copian igual que los demás
        // porque **cuando se crea el año no hay ni un contrato en él**. La planta
        // se contrata cada año; copiar los contratos daría por contratada a gente
        // a la que nadie ha renovado, y eso se ve en nómina y en permisos.
        'contratos' => 'la planta se contrata cada año; copiarlos daría por contratada a gente sin renovar (ya decidido en postStore para titular_id y profesor_id)',

        // Las dos de disciplina que NO son configuración. `dis_configuraciones` y
        // `dis_ordinales` sí se copian —son el vocabulario y el articulado del
        // manual—; estas dos son lo que les pasó a alumnos concretos.
        'dis_libro_rojo' => 'el registro por alumno y periodo de ESE año, no el manual de convivencia',
        'dis_procesos' => 'los procesos disciplinarios abiertos ESE año, con su descargo y sus firmas',

        // El panel de «lo que sacaste esta semana» de la pantalla nueva de informes
        // (migración `2026_09_18_200000`). Es un registro de **lo que una persona
        // abrió en ESE año**: copiarlo diría que alguien sacó informes en un año
        // que acaba de empezar, o sea la misma falsedad que la bitácora.
        //
        // Y hay una razón de daño, no sólo de higiene: **`year_id` está en esa tabla
        // justamente para que un informe del año pasado no se repita de un clic**
        // —lo pidió así `myvc_front`, porque sacaría el papel equivocado—. Copiar las
        // filas al año nuevo les cambiaría el `year_id` y convertiría cada renglón
        // heredado en un botón que promete un informe de este año y trae los
        // parámetros del anterior: exactamente lo que esa columna existe para
        // impedir, hecho por el camino de copiarla.
        //
        // La lista se rehace sola en cuanto alguien abre un informe, así que no se
        // pierde nada que haya que reponer a mano.
        //
        // (Su hermana `accesos_favoritos` **no aparece aquí y no es un olvido**: no
        // tiene `year_id` —los favoritos son del menú, no de un año— así que este
        // centinela no la mira.)
        'informes_recientes' => 'lo que cada persona abrió ESE año; copiarlo fabricaría historia y, peor, heredaría botones que prometen este año con parámetros del anterior',

        // El puntero `years.horario_version_id` ya está excusado por lo mismo en
        // `CentinelaDeLasColumnasDelAnioNuevoTest::NACEN_VACIAS`, y las dos
        // excepciones tienen que decir lo mismo o una de las dos miente: cada año
        // tiene su horario y no se pisan (23 §5.2, decisión 13).
        'horario_versiones' => 'cada año tiene su horario y no se pisan; el puntero de years ya está excusado por lo mismo (23 §5.2)',

        // Los tres del PIAR. El Decreto 1421/2017 lo hace **anual**: se valora, se
        // acuerda con la familia y se firma cada año. Un PIAR copiado sería un
        // acuerdo que nadie volvió a firmar, y lleva documentos y firmas dentro.
        // (Aquí se citaba que las `competencias` con `alumno_id` sí viajaban. **Ya no
        // vale por partida doble, 17 sep 2026**: la tabla se fue, y la que queda
        // —`desempenos_por_defecto`— **no tiene `alumno_id`**, así que el plan de área
        // dirigido a un estudiante dejó de existir. El contraste que ilustraba sigue
        // siendo cierto sin ella: el plan de área es el texto que el colegio escribió
        // y viaja; el acta del PIAR se firmó ESE año y no.)
        'piars_actas_acuerdo' => 'el acta firmada con la familia ESE año (Decreto 1421/2017, el PIAR es anual)',
        'piars_alumnos' => 'la valoración pedagógica y los ajustes acordados ESE año, con sus documentos',
        'piars_grupos' => 'la caracterización del grupo de ESE año, y cuelga de un grupo que en el año nuevo tiene otro id',

        // **Decidida el 13 sep 2026 por Joseth (D27)**, después de haber estado
        // declarada `SIN_DECIDIR` desde que se escribió este centinela. Y es la que
        // se lee al revés que el plan de área, que **sí** se copia: el porqué de que
        // las dos estén bien está arriba, en el docblock de esta constante. Una rúbrica
        // es la matriz criterios × niveles que **produce la nota de una subunidad**
        // (26 §1, decisión 4 de Joseth), y lo que la hacía dudosa era su segunda
        // cara: con `es_plantilla = 1` y `asignatura_id` en NULL es una **rúbrica de
        // biblioteca**, sin dueño y escrita para reusarse, y ésa tenía la misma
        // pinta que `unidades_por_defecto`.
        //
        // **La respuesta fue que no: se quedan en su año.** Una rúbrica es trabajo
        // que un docente montó para evaluar algo concreto de ESE año — igual que el
        // libro rojo, los contratos o las votaciones, es lo que el año produjo al
        // vivirse y no lo que el colegio escribió para decir cómo evalúa. El
        // profesor que quiera reusar una **la vuelve a montar**, que es un coste
        // suyo y de una vez, y no el de encontrarse en enero rúbricas con su firma
        // que él no escribió ese año.
        //
        // Y de paso se cierra sola la parte cara, que era la que hacía de esto una
        // entrega y no un `INSERT`. **Contado contra el docker el 13 sep 2026, y no
        // heredado**: copiar una rúbrica son **tres tablas de definición**
        // —`rubrica_criterios` y `rubrica_niveles`, las dos con `rubrica_id`, y
        // `rubrica_descriptores`, que cuelga de las dos anteriores por
        // `criterio_id` + `nivel_id`— más el enganche `subunidades.rubrica_id`,
        // todo con los ids remapeados, y **ninguna lleva `year_id`**, o sea que
        // este centinela ni siquiera las vigilaría.
        //
        // **Y hay una cuarta hija que no se podría copiar ni queriendo**, que es el
        // argumento más corto de todos: `rubrica_valoraciones` cuelga de `nota_id`
        // —la valoración que un docente le puso a un alumno concreto con esa
        // rúbrica—. La mitad de esta familia de tablas **es dato de notas de ese
        // año** y está dicho en el esquema, no en una opinión.
        //
        // **Lo que la decisión cuesta hoy son cero filas**, y se dice para que nadie
        // lo confunda con una medida de su importancia: en la base de desarrollo las
        // cinco tablas de rúbricas están **vacías** (13 sep 2026). `simonbolivar` es
        // un colegio y la población de los dieciséis no se sabe desde aquí. Lo que
        // se decide es **qué pasa el enero en que las tenga**, no lo que pasa hoy.
        'rubricas' => 'el trabajo que un docente montó para evaluar algo de ESE año; el que quiera reusar una la vuelve a montar (decidido por Joseth el 13 sep 2026, D27; 35 §2.bis)',

        // **El otro par que hay que leer junto, y entró el 19 sep 2026** con el
        // formulario de inscripción impreso (`docs/migracion/41`). Igual que
        // `desempenos_por_defecto` y `rubricas`, son dos tablas nacidas el mismo día
        // y decididas al revés, y sin verlas seguidas la de abajo parece un olvido:
        //
        //     config_formulario_   SE COPIA   Lo que el colegio eligió que pida su
        //     inscripcion                     formulario. Está en `postStore`.
        //
        //     ordenes_inscripcion  NO         El papel impreso de ESA campaña.
        //
        // Cada fila de `ordenes_inscripcion` es un **formulario que existe en papel**:
        // su código —el que la familia teclea para volver—, cuánto costó, quién lo
        // vendió y cuándo. Copiarlas al año nuevo fabricaría **códigos de formularios
        // que nadie imprimió y cobros que nadie hizo**, y esos códigos entrarían en la
        // lista de «vendidos que no volvieron», que es precisamente la lista de
        // llamadas que esta tabla viene a producir. No es que sobre: sería falsa, como
        // `auditoria`.
        //
        // Y hay una segunda razón que la haría inútil aunque se copiara: el `UNIQUE
        // (year_id, alumno_id)` que sostiene el get-or-create de la renovación daría al
        // alumno un código del año nuevo **antes de que nadie le imprima nada**, o sea
        // que la primera reimpresión de verdad reusaría un código que nunca se entregó.
        //
        // `colillas_inscripcion` no aparece en este censo y es correcto: no tiene
        // `year_id`, cuelga de `orden_id`. Se va con su orden y no hay que decidirla.
        'ordenes_inscripcion' => 'cada fila es un formulario IMPRESO de esa campaña, con su cobro y quién lo vendió; copiarlas fabricaría papeles y cobros que no existieron (19 sep 2026, doc 41)',

        // Con sus fechas, su `locked` y su `actual` dentro: una votación copiada
        // nacería abierta o cerrada según cómo acabó la del año pasado.
        'vt_votaciones' => 'la elección que se convocó ESE año, con sus fechas y su estado',
    ];

    /**
     * Las que no se copian **porque no las lee nadie**, y su sitio es otro documento.
     *
     * No son datos del año ni configuración: son tablas **muertas**, ya censadas
     * como tales en [05](../../docs/migracion/05-codigo-muerto-y-roto.md) y
     * pendientes de borrarse. Van en su propia lista y no en la de arriba porque
     * el motivo por el que no se copian es distinto, y porque **el día que se
     * borren, esta lista se vacía sola** por `ninguna_excepcion_sobra` — que es
     * justo lo que se quiere: la excepción desaparece con su tabla, sin que nadie
     * se acuerde de venir aquí.
     *
     * Copiar una tabla muerta sería lo peor de los dos mundos: trabajo por año en
     * filas que nadie lee, y una pista falsa para el siguiente que se pregunte si
     * está viva.
     *
     * @var array<string, string>
     */
    public const TABLAS_MUERTAS = [
        'default_unidades' => 'el segundo par de plantilla, muerto: cero filas y nadie lo lee (28 §1.bis(b), 35 §5, D21)',
        'df_alumnos' => 'copia desnormalizada de las definitivas que alguien empezó y no terminó: cero filas (05, 09 §c)',
        'df_grupos' => 'ídem, del grupo: cero filas y ningún lector (05, 09 §c)',
    ];

    /**
     * Las que **todavía no se sabe**, y por eso tienen un test rojo esperándolas.
     *
     * **Hoy está vacía, y eso es lo que se quería.** `rubricas` fue la única que
     * llegó a aparcarse aquí, y se decidió el **13 sep 2026**: no se copia, va
     * arriba en `DATOS_DEL_ANIO` con su motivo. O sea que
     * `hay_tablas_por_anio_sin_decidir` está en verde **porque la pregunta se
     * contestó**, no porque nadie la mire — que es la única forma de verde que
     * vale aquí.
     *
     * **Y la lista se queda aunque esté vacía.** Borrarla al vaciarse sería quitar
     * la salida honesta justo antes de la siguiente tabla que no se sepa
     * clasificar, y entonces la única salida vuelve a ser escribirle a esa tabla un
     * motivo que suene bien en una de las dos listas de arriba.
     *
     * Ésta es la válvula que hace que las dos listas de arriba puedan decir la
     * verdad. Sin ella, la única salida ante una tabla que no se sabe clasificar
     * es escribirle un motivo que suene bien, y entonces la lista de excepciones
     * pasa a ser un sitio donde se aparcan dudas con cara de decisiones — que es
     * exactamente cómo murió la vigilancia anterior.
     *
     * **Y no es gratis aparcar aquí**: mientras esta lista no esté vacía,
     * `hay_tablas_por_anio_sin_decidir` está en rojo. Va en el grupo `rojo`, o sea
     * fuera de la corrida normal, por la razón de siempre —un rojo permanente
     * dentro de la suite convierte el verde en ruido—, pero se corre a propósito
     * (`php artisan test --group rojo`) y **nombra a quién hay que preguntarle**.
     * Es el mismo mecanismo que ya usan `SubunidadDeUnaUnidadConDuenoTest` y
     * `RecalculoPorUnidadConDuenoTest`: un fallo que espera una decisión de Joseth
     * no es un comentario, es un test.
     *
     * @var array<string, string>
     */
    public const SIN_DECIDIR = [
        // Vacía otra vez desde el 18 sep 2026: `jefes_de_area` estuvo aquí unas
        // horas y Joseth contestó que el año nuevo SÍ hereda, así que pasó a
        // copiarse en `YearsController::copiarLosJefesDeArea` y salió del censo por
        // la puerta buena. Lo que entra aquí es una tabla con `year_id` de la que
        // **no se sabe** si el año nuevo la hereda, con la pregunta escrita entera y
        // el nombre de quien la tiene que contestar — nunca un motivo provisional.

    ];

    #[Test]
    public function ninguna_tabla_por_anio_se_queda_sin_copiar_al_crear_el_anio(): void
    {
        $porAnio = $this->tablasPorAnio();
        $copiadas = $this->tablasQueCopiaCrearElAnio();

        // La población, antes que el veredicto: un «0 sin copiar» no distingue «las
        // 23 están decididas» de «no leí ninguna tabla» (CLAUDE.md). Y las dos
        // mitades se pueden quedar mudas por su cuenta — la de arriba si
        // `information_schema` contesta de otra base, la de abajo si alguien
        // renombra el método o cambia la forma de los `INSERT`.
        $this->assertGreaterThan(15, count($porAnio),
            'Sólo se han encontrado '.count($porAnio)." tablas con `year_id`, y son 23.\n".
            'Esto no es un aprobado: es que `information_schema` no ha contestado lo que se cree.');
        $this->assertGreaterThan(7, count($copiadas),
            'Sólo se han encontrado '.count($copiadas)." tablas copiadas por `postStore`, y son 10.\n".
            "Se leen del fuente siguiendo las llamadas a métodos privados, así que esto\n".
            'se rompe al renombrar un método o al escribir un `INSERT` de otra forma.');

        $decididas = array_merge(
            $copiadas,
            array_keys(self::DATOS_DEL_ANIO),
            array_keys(self::TABLAS_MUERTAS),
            array_keys(self::SIN_DECIDIR),
        );

        $huerfanas = array_values(array_diff($porAnio, $decididas));

        $this->assertSame([], $huerfanas, $this->porQueEsRojo($huerfanas, $porAnio, $copiadas));
    }

    /**
     * Y la dirección contraria, que es la que convierte la lista en un `@ignore`.
     *
     * Una excepción que ya no hace falta —porque la tabla se copia, o porque ya no
     * existe— **no da ningún error por sí sola**: se queda ahí, y el siguiente que
     * lea la lista la da por vigente. Es el mismo argumento del hermano y el mismo
     * por el que `AutopruebasDeLasHerramientasTest` no deja apuntar una
     * herramienta como «no concluyente» y olvidarla.
     */
    #[Test]
    public function ninguna_excepcion_sobra(): void
    {
        $porAnio = $this->tablasPorAnio();
        $copiadas = $this->tablasQueCopiaCrearElAnio();

        foreach ($this->excepciones() as $tabla => $motivo) {
            $this->assertContains($tabla, $porAnio,
                "`{$tabla}` está excusada de copiarse y **ya no es una tabla con `year_id`**.\n".
                'Sobra de la lista: una excepción a algo que no existe se lee como vigente.');

            $this->assertNotContains($tabla, $copiadas,
                "`{$tabla}` está en la lista de excepciones y `postStore` **sí la copia**.\n\n".
                "Una de las dos cosas está mal, y la que hay que mirar primero es la lista:\n".
                "si la tabla se copia, su excepción sobra y hay que borrarla. Dejarla es\n".
                'cómo una lista de excepciones deja de decir la verdad sin que nada falle.');

            $this->assertNotSame('', trim($motivo),
                "`{$tabla}` está excusada **sin motivo escrito**, que es un `@ignore` con otro nombre.");
        }

        // Y que ninguna esté en dos listas a la vez, que haría que borrarla de una
        // no la borrara de ninguna parte.
        $todas = array_merge(array_keys(self::DATOS_DEL_ANIO), array_keys(self::TABLAS_MUERTAS), array_keys(self::SIN_DECIDIR));
        $this->assertSame(count($todas), count(array_unique($todas)),
            'Hay una tabla excusada en dos listas a la vez: borrarla de una la dejaría excusada igual.');
    }

    /**
     * **Y que este centinela sepa ponerse rojo de verdad**, con una tabla sonda.
     *
     * *Un test que no se ha visto en rojo no prueba nada* (03 §«Un test que no se
     * ha visto en rojo»). Aquí eso no se puede comprobar revirtiendo un arreglo
     * —lo que vigila este fichero es que **aparezca** una tabla que todavía no
     * existe—, así que se comprueba al revés: se crea una tabla con `year_id` que
     * nadie copia, se mira que salga en la lista de huérfanas, y se borra.
     *
     * **Es una tabla de verdad y no un nombre inyectado en la comparación**,
     * porque lo que hay que probar no es el `array_diff` sino que el censo la
     * encuentra: si `information_schema` se preguntara mal —otra base, o
     * `TABLE_TYPE` mal filtrado—, el `array_diff` seguiría estando perfecto y el
     * centinela seguiría verde para siempre.
     *
     * Esta clase **no** usa `DatabaseTransactions` —igual que su hermano—, y aquí
     * eso deja de ser un detalle: `CREATE TABLE` hace *commit* implícito en MySQL,
     * así que dentro de una transacción de test se llevaría por delante el
     * aislamiento de la corrida. Sin transacción no hay nada que llevarse; el
     * `finally` borra la sonda pase lo que pase, y el `DROP` es `IF EXISTS` para
     * que una sonda huérfana de una corrida muerta no deje la base rota.
     */
    #[Test]
    public function el_centinela_caza_una_tabla_por_anio_que_nadie_copia(): void
    {
        $this->comprobarBaseDeTest();

        $this->assertSame([], $this->huerfanas(),
            'Antes de la sonda ya había huérfanas: este test no puede decir nada.');

        DB::statement('DROP TABLE IF EXISTS `'.self::SONDA.'`');
        DB::statement('CREATE TABLE `'.self::SONDA.'` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `year_id` int unsigned NOT NULL,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

        try {
            $this->assertContains(self::SONDA, $this->tablasPorAnio(),
                'El censo no vio una tabla con `year_id` recién creada: '.
                'el centinela estaría verde para siempre y no por buenos motivos.');

            $this->assertSame([self::SONDA], $this->huerfanas(),
                'Se creó una tabla con `year_id` que `postStore` no copia y el centinela NO se puso rojo.');
        } finally {
            DB::statement('DROP TABLE IF EXISTS `'.self::SONDA.'`');
        }

        $this->assertSame([], $this->huerfanas(),
            'La sonda no se borró: la base de tests se queda con una tabla de más.');
    }

    /**
     * El rojo a propósito: mientras haya tablas por año sin decidir, esto falla.
     *
     * Va en el grupo `rojo`, o sea **fuera de la corrida normal**, por lo mismo
     * que los otros dos: un rojo permanente dentro de la suite convierte el verde
     * en ruido y el siguiente fallo de verdad entra sin que salte nada. Se corre
     * pidiendo el grupo, y el día que la decisión se tome se vacía `SIN_DECIDIR`
     * —la tabla pasa a copiarse o a `DATOS_DEL_ANIO`— y este test se pone verde
     * solo. **Ese paso es lo que lo hace la red de la decisión y no una queja
     * archivada.**
     *
     * **Y ya ocurrió, que es lo que lo saca de la teoría.** `rubricas` entró aquí
     * el 13 sep 2026 con su pregunta escrita, Joseth la contestó el mismo día —no
     * se copian: se quedan en su año (D27)— y la tabla se movió a
     * `DATOS_DEL_ANIO`. Este test está verde desde entonces **por haberse
     * contestado la pregunta**, y ésa es la diferencia con un `@ignore`: el verde
     * de aquí tiene fecha y tiene autor.
     */
    #[Test]
    #[Group('rojo')]
    public function hay_tablas_por_anio_sin_decidir(): void
    {
        // **Se compara la lista ENTERA y el mensaje no la recorre**, y eso no es
        // estilo: es lo que queda cuando la lista está vacía, que desde D27 es el
        // estado bueno.
        //
        // Aquí había un `array_map` sobre `array_keys`/`array_values` para
        // imprimir «tabla — pregunta», y con `SIN_DECIDIR = []` **phpstan lo cazó
        // dos veces seguidas** (13 sep 2026): primero `arrayValues.empty`, y al
        // reescribirlo con un `foreach`, `foreach.emptyArray`. No es quisquilloso
        // y no se arregla cambiando de bucle: phpstan **conoce el valor de la
        // constante**, así que cualquier código que la recorra es código muerto
        // mientras esté vacía, y tiene razón. Este proyecto no usa baseline y las
        // excepciones de `phpstan.neon` van con nombre y motivo, así que la salida
        // no era anotarlo.
        //
        // Y la que queda es **mejor que la que había**: comparando el array entero
        // en vez de sus claves, el diff que imprime PHPUnit al fallar trae la
        // tabla **y su pregunta**, que antes sólo salían por el mensaje de mano.
        // Menos código y más información en el rojo.
        $this->assertSame([], self::SIN_DECIDIR,
            "Arriba está la tabla que lleva `year_id` y de la que **nadie ha decidido** si el\n".
            "año nuevo la hereda, con la pregunta que hay que contestar al lado.\n\n".
            "No es un fallo que arreglar escribiendo código: es una pregunta para Joseth.\n".
            "Cuando se conteste, la tabla se copia en `YearsController::postStore` o se\n".
            'mueve a `DATOS_DEL_ANIO` con el motivo, y esta lista se queda vacía.');
    }

    // ─────────────────────────────────────────────────────────────────────────

    /** @return array<string, string> las tres listas juntas, tabla => motivo. */
    private function excepciones(): array
    {
        return array_merge(self::DATOS_DEL_ANIO, self::TABLAS_MUERTAS, self::SIN_DECIDIR);
    }

    /** @return list<string> las tablas por año que nadie copia ni excusa. */
    private function huerfanas(): array
    {
        return array_values(array_diff(
            $this->tablasPorAnio(),
            array_merge($this->tablasQueCopiaCrearElAnio(), array_keys($this->excepciones())),
        ));
    }

    /**
     * Las tablas con `year_id`, preguntadas a la base viva y no al volcado.
     *
     * Por lo mismo que el hermano usa `SHOW COLUMNS`: el volcado congelado es el
     * esquema de **producción**, y las tablas que faltan ahí son justo las que
     * entraron por migración, o sea **justo las candidatas a olvidarse**. Un
     * centinela medido contra el volcado estaría mirando donde ninguna candidata
     * puede aparecer.
     *
     * `TABLE_TYPE = 'BASE TABLE'` deja fuera las vistas, que no se copian ni se
     * pueden copiar; `DATABASE()` en vez del nombre de la base para que valga en
     * la base de cada sesión (`simonbolivar_testing_x`).
     *
     * @return list<string>
     */
    private function tablasPorAnio(): array
    {
        $filas = DB::select(
            "SELECT c.TABLE_NAME AS tabla
               FROM information_schema.COLUMNS c
               JOIN information_schema.TABLES t
                 ON t.TABLE_SCHEMA = c.TABLE_SCHEMA AND t.TABLE_NAME = c.TABLE_NAME
              WHERE c.TABLE_SCHEMA = DATABASE()
                AND c.COLUMN_NAME = 'year_id'
                AND t.TABLE_TYPE = 'BASE TABLE'
              ORDER BY c.TABLE_NAME"
        );

        return array_values(array_map(static fn (object $f): string => (string) $f->tabla, $filas));
    }

    /**
     * Las tablas por año en las que `postStore` escribe, leídas del fuente.
     *
     * **Sigue las llamadas**, y ésa es la diferencia con el hermano. Aquél corta
     * el fichero desde `function postStore` hasta el siguiente `public function`,
     * que hoy incluye los privados de en medio **por dónde están escritos**: el
     * día que alguien mueva `crearLosPeriodos` cien líneas más abajo, ese corte
     * deja de cubrirlo y nadie se entera. Aquí se parte el fichero en métodos y se
     * recorre desde `postStore` lo que llama, así que el sitio donde estén
     * escritos da igual.
     *
     * Tres formas de escribir, porque en este método conviven las tres:
     *
     *   - `INSERT INTO tabla(...)` — la plantilla, los requisitos, disciplina y el
     *     plan de área;
     *   - `new Modelo` — las escalas, las frases, los grupos y los periodos, que
     *     no nombran su tabla en ninguna parte: se resuelve preguntándole al
     *     modelo (`getTable()`), no adivinando por el nombre;
     *   - `DB::table('x')->insert(...)` — no la usa hoy, y se mira igual porque es
     *     la forma en que se escribiría mañana.
     *
     * Lo que sale se cruza con las tablas por año: `new Year` y `new Asignatura`
     * aparecen aquí y no son tablas por año, así que caen solas. **No se filtra
     * por nombre**, por lo mismo que el hermano no filtra `$year->periodos`: la
     * única lista de tablas que hay es la de la base.
     *
     * @return list<string>
     */
    private function tablasQueCopiaCrearElAnio(): array
    {
        $fuente = $this->fuenteDelControlador();
        $codigo = $this->codigoAlcanzableDesde($fuente, 'postStore');

        $nombradas = [];

        preg_match_all('/INSERT\s+INTO\s+`?(\w+)`?/i', $codigo, $m);
        $nombradas = array_merge($nombradas, $m[1]);

        preg_match_all('/DB::table\(\s*[\'"](\w+)[\'"]\s*\)\s*->\s*(?:insert|insertGetId|insertOrIgnore|updateOrInsert)/i', $codigo, $m);
        $nombradas = array_merge($nombradas, $m[1]);

        preg_match_all('/\bnew\s+([A-Z]\w*)\b|\b([A-Z]\w*)::create\s*\(/', $codigo, $m);

        foreach (array_merge($m[1], $m[2]) as $clase) {
            $tabla = $this->tablaDelModelo($fuente, $clase);

            if ($tabla !== null) {
                $nombradas[] = $tabla;
            }
        }

        $porAnio = $this->tablasPorAnio();

        return array_values(array_unique(array_filter(
            $nombradas,
            static fn (string $t): bool => in_array($t, $porAnio, true)
        )));
    }

    /**
     * El código de un método **y el de todo lo que llama**, sin comentarios.
     *
     * Los comentarios fuera antes de mirar nada: sin eso el centinela cuenta texto
     * y no código, o sea que **un `INSERT` comentado sigue valiendo por escrito**.
     * Lo aprendió `CentinelaDeLasColumnasDelGrupoCopiadoTest` mutando el
     * controlador para ver si sabía ponerse rojo, y aquí importa el doble: este
     * fichero tiene párrafos enteros de comentario que **nombran tablas**.
     */
    private function codigoAlcanzableDesde(string $fuente, string $raiz): string
    {
        $metodos = $this->metodosDe($fuente);

        $this->assertArrayHasKey($raiz, $metodos,
            "No hay ningún `function {$raiz}` en ".self::FICHERO.".\n".
            'Si se renombró, este centinela dejó de vigilar nada — y no lo diría solo.');

        $vistos = [];
        $cola = [$raiz];

        while ($cola !== []) {
            $nombre = array_shift($cola);

            if (isset($vistos[$nombre]) || ! isset($metodos[$nombre])) {
                continue;
            }

            $vistos[$nombre] = $this->sinComentarios($metodos[$nombre]);

            preg_match_all('/\$this->(\w+)\s*\(/', $vistos[$nombre], $m);
            $cola = array_merge($cola, $m[1]);
        }

        return implode("\n", $vistos);
    }

    /**
     * El fichero partido por métodos: nombre => cuerpo (hasta el siguiente).
     *
     * **Con `[ \t]*` y no ` *`**: este controlador alinea con tabuladores, que es
     * la misma piedra que el hermano dejó anotada —contar con espacios le daba 50
     * asignaciones donde hay 61, y 50 es una cifra lo bastante creíble como para
     * no mirarla—.
     *
     * @return array<string, string>
     */
    private function metodosDe(string $fuente): array
    {
        preg_match_all(
            '/(?:^|\n)[ \t]*(?:public|private|protected)(?:\s+static)?\s+function\s+(\w+)/',
            $fuente, $m, PREG_OFFSET_CAPTURE
        );

        $metodos = [];
        $cuantos = count($m[0]);

        for ($i = 0; $i < $cuantos; $i++) {
            $desde = (int) $m[0][$i][1];
            $hasta = $i + 1 < $cuantos ? (int) $m[0][$i + 1][1] : strlen($fuente);

            $metodos[(string) $m[1][$i][0]] = substr($fuente, $desde, $hasta - $desde);
        }

        return $metodos;
    }

    private function sinComentarios(string $codigo): string
    {
        $codigo = (string) preg_replace('~/\*.*?\*/~s', '', $codigo);

        return (string) preg_replace('~//[^\n]*~', '', $codigo);
    }

    /**
     * La tabla de un modelo, preguntándosela a Eloquent.
     *
     * El nombre corto se resuelve con los `use` del fichero, que es lo que hace
     * que `Grupo` no se confunda con cualquier otro `Grupo` del proyecto. Lo que
     * no sea un modelo —`Carbon`, `Request`— devuelve `null` y se cae solo.
     */
    private function tablaDelModelo(string $fuente, string $corto): ?string
    {
        if ($corto === '') {
            return null;
        }

        preg_match_all('/^use\s+([\w\\\\]+);/m', $fuente, $m);

        $clase = null;

        foreach ($m[1] as $importada) {
            if (str_ends_with($importada, '\\'.$corto)) {
                $clase = $importada;
                break;
            }
        }

        $clase ??= 'App\\Models\\'.$corto;

        if (! class_exists($clase) || ! is_subclass_of($clase, Model::class)) {
            return null;
        }

        /** @var Model $modelo */
        $modelo = new $clase;

        return $modelo->getTable();
    }

    private function fuenteDelControlador(): string
    {
        $ruta = dirname(__DIR__, 2).'/'.self::FICHERO;
        $fuente = file_get_contents($ruta);

        $this->assertIsString($fuente, 'No se pudo leer '.self::FICHERO);

        return $fuente;
    }

    /** @param list<string> $huerfanas */
    private function porQueEsRojo(array $huerfanas, array $porAnio, array $copiadas): string
    {
        return "`YearsController::postStore` no dice nada de estas tablas por año:\n\n".
            '    '.implode("\n    ", $huerfanas)."\n\n".
            "No es un fallo del test: es una decisión sin tomar, y el año nuevo la está\n".
            "tomando solo. Una tabla por año que nadie copia deja al colegio reescribiendo\n".
            "en enero lo que ya había escrito —así estuvieron `subunidades_por_defecto`\n".
            "**durante años**— y el fallo no rompe nada el día que entra, no deja una línea\n".
            "en ningún log, y aparece en enero sin nada que lo relacione con haber creado\n".
            "un año.\n\n".
            "Hay TRES salidas, y las tres son escribir:\n".
            "  1. copiarla en `postStore`, junto a las que ya se copian. Y si sus filas se\n".
            "     apuntan entre ellas o a un periodo, **remapeando los ids**: mira\n".
            "     `copiarElPlanDeArea`, que es el caso resuelto;\n".
            "  2. meterla en DATOS_DEL_ANIO **con el motivo**, si lo que guarda pasó en ese\n".
            "     año y copiarlo sería fabricar historia;\n".
            "  3. o en SIN_DECIDIR **con la pregunta**, si de verdad no se sabe. Eso pone\n".
            "     rojo `hay_tablas_por_anio_sin_decidir` (grupo `rojo`) hasta que se decida,\n".
            "     que es como una duda deja de disfrazarse de decisión.\n\n".
            'Población: '.count($porAnio).' tablas con `year_id`, '.count($copiadas).' copiadas por postStore.';
    }

    /**
     * El mismo guardia que `CasoDeContrato`, porque esta clase hace `CREATE TABLE`.
     *
     * Esta familia de centinelas no usa `DatabaseTransactions` —no escribe nada—,
     * así que tampoco hereda de `CasoDeContrato` ni de su comprobación. Y aquí sí
     * hace falta: la sonda crea y borra una tabla de verdad, y eso contra la base
     * de trabajo no se deshace con un rollback.
     */
    private function comprobarBaseDeTest(): void
    {
        $conexion = config('database.default');
        $base = config("database.connections.{$conexion}.database");

        if (! preg_match('/_(testing|test)(_[a-z0-9]+)?$/', (string) $base)) {
            $this->fail(
                "Los tests apuntan a la base '{$base}', que no acaba en _testing.\n".
                'Este test crea y borra una tabla: abortando por si acaso.'
            );
        }
    }
}
