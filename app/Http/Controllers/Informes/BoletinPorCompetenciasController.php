<?php

namespace App\Http\Controllers\Informes;

use App\Http\Controllers\Concerns\ResuelveElUsuario;
use App\Http\Controllers\Controller;
use App\Models\DefinicionComportamiento;
use App\Models\Disciplina;
use App\Models\Grupo;
use App\Models\NotaComportamiento;
use App\Models\Year;
use App\Services\BoletinIndependiente;
use App\Services\DefinitivasDeAsignatura;
use App\Support\PeriodoDelBoletin;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;

/**
 * **El boletín por competencias** — la Fase 6 de
 * [35-el-modelo-de-evaluacion-del-colegio.md](../../../../docs/migracion/35-el-modelo-de-evaluacion-del-colegio.md),
 * con **D16** y **D17** encima.
 *
 * El plan de área de la asignatura línea a línea y las frases escritas a mano al
 * final: **el modelo es plano** (D31 y P3 del
 * [39](../../../../docs/migracion/39-el-modelo-plano-por-competencias.md) §4), que es
 * la fila del plan de área tal como la teclea un colegio y como la leen los catorce
 * SIEE del corpus (`myvc_front/PLAN-FRONT-MODELO-DE-EVALUACION.md` §4.3).
 *
 * ## No es una cuarta copia, y la Fase 5 es la que lo autoriza
 *
 * La medición de la Fase 5 —§«Fase 5» del doc 35, y `tools/comparar-los-tres-boletines.php`
 * si hay que rehacerla— dice que de los **547** caminos de campo de los tres boletines
 * de hoy, **211 son comunes a los tres (39 %)** y **todo lo exclusivo de cada uno es su
 * maqueta**: las subunidades del primero, el bloque `areas[]` del segundo, la tabla de
 * cuatro periodos del tercero.
 *
 * Así que esto **nace del dato común y no de un `cp`**: monta la respuesta llamando a
 * los mismos modelos que ellos —`Grupo::datos`, `Grupo::alumnos`,
 * `Grupo::detailed_materias_notafinal`, `NotaComportamiento`, `BoletinIndependiente`—
 * y le añade **un bloque propio**.
 *
 * Las tres divergencias que la Fase 5 encontró —el `number_format` sobre la definitiva
 * del año (115 pares que discrepan), el `PREM` que sólo mira uno y el
 * `years.solo_escalas_valorativas` que honran dos de tres— **no están aquí**, y hay que
 * decir por qué, porque no es que se arreglaran: las dos primeras viven en
 * `asignaturasPerdidasDeAlumno` y `datosYearPasado`, y **esta variante no tiene ni bloque
 * de asignaturas perdidas ni de año pasado** —es un informe de un periodo—; la tercera
 * vive en `encabezado_comportamiento_boletin`, que es **texto de maqueta** y lo escribe la
 * maqueta nueva. **Arreglarlas en los tres de hoy sigue siendo entrega propia y sigue sin
 * hacerse.**
 *
 * ## Son DOS rutas y el plan decía cuatro
 *
 * El doc 35 pedía *«4 rutas, calcadas de `boletines3`»*. Medido, son dos, y las otras
 * dos no se pueden calcar:
 *
 * - **`boletines3/destroy/{id}` no borra un boletín: manda un ALUMNO a la papelera.**
 *   Está escrito en su propio comentario (05 §89) y lo fija `BoletinesBorranAlumnosTest`
 *   con las cuatro puertas en el mismo caso. Copiarla sería **una quinta puerta** a la
 *   papelera, y de la familia de informes.
 * - **`…/detailed-notas-year` es byte a byte la misma en los tres** —sus tres
 *   instantáneas comparten md5, `054346c7…`— y **ningún front la llama**:
 *   `app2/src/app/datos/boletines.ts` declara exactamente dos métodos, `deAlumnos` y
 *   `deGrupo`, contra `detailed-notas/{g}` y `detailed-notas-group/{g}`. Una cuarta
 *   copia idéntica sería código muerto el día que entra.
 *
 * ## Lo que se imprime es el PLAN DE ÁREA entero, y el nivel se deriva
 *
 * **Salen todas las filas de `desempenos_por_defecto` que le tocan a la asignatura**
 * —las de su grado **y** las de «todos los grados», que acumulan y no compiten
 * (D25)—, tenga el alumno nota o no. Aquí ya no hay nada que marcar: la rejilla que
 * elegía casilla por casilla se fue con la **D31**, y con ella la decisión 10 —*«ningún
 * boletín afirma un nivel que nadie miró»*—, porque ya no hay nadie que mire. **El
 * nivel lo dice la definitiva.**
 *
 * Así que el nivel es **uno por asignatura, repetido en todas sus líneas** (P1.bis,
 * H4): la banda en la que cae `nota_asignatura` de **esa** asignatura en **ese**
 * periodo. Que se repita no es un descuido de la maqueta, es lo que significa —*«en
 * esta materia el alumno está en ALTO»*— y debajo, el plan de área que el colegio
 * escribió para ella.
 *
 * El otro bloque son **las frases escritas a mano** —la pantalla de
 * `frases_asignatura`, que no se toca—: **12.294 filas sólo en `simonbolivar`** (§4 del
 * 39), datos de producción de los dieciséis colegios que nunca vinieron de ninguna
 * rejilla. Salen **al final y sin nivel**, porque una frase a mano no es una fila de un
 * plan de área y no hay banda que ponerle.
 *
 * > **Y por eso la consulta de las frases NO nombra `frases_asignatura.desempeno_id`,
 * > ni `escala_id`, ni `nivel`, ni `competencia`.** Las cuatro columnas las añadió
 * > `2026_09_13_400000_marca_del_desempeno`, que se borra con la rejilla, y en los
 * > dieciséis colegios **nunca corrió**: el volcado de
 * > `database/schema/mysql-schema.sql` —que es la verdad del esquema— tiene
 * > `frases_asignatura` con **doce** columnas y ninguna de las cuatro. Nombrar una sería
 * > un `Unknown column` allí, y ésa es la clase de rojo que **este docker no puede
 * > enseñar**: aquí la migración sí corrió, así que las columnas existieron hasta que se
 * > podó la rejilla (§7 del 39) y una consulta que las nombrara habría pasado en verde
 * > el día de escribirla. Filtrar «las escritas a mano» por `desempeno_id IS NULL` sería
 * > exactamente esa trampa — y **no hace falta filtrar nada**, porque las de la rejilla
 * > no existen en ningún colegio.
 * >
 * > Lo que eso deja **en este docker y en ningún sitio más**: las filas que una sesión
 * > tecleó por la rejilla antes de podarla se quedaron sin la columna que las
 * > distinguía, así que se imprimen como frases a mano. No es un caso que pueda
 * > reproducir un colegio: es una base de desarrollo con la migración corrida.
 *
 * ## Lo que se imprime se lee VIVO, y eso deshace una migración a propósito
 *
 * Hasta la D31 aquí no se leía **nada** del catálogo de hoy: el texto, el nivel y la
 * cabecera estaban copiados en `frases_asignatura` el día que el docente marcó la
 * casilla, los tres por el mismo argumento —**el id pinta y el texto imprime**—. Sin
 * casilla que marcar no hay día en el que copiar, así que los tres se leen vivos: el
 * texto de `desempenos_por_defecto.definicion` y el nivel de la definitiva.
 *
 * **Eso deshace `2026_09_14_100000_competencia_congelada`**, cuyo comentario decía
 * literalmente que sin ella *«renombrar una competencia en 2028 cambiaba la cabecera de
 * un boletín de 2026»*. Hoy no hay cabecera, pero el cuerpo tiene el mismo problema:
 * **corregir una errata del plan de área en octubre cambia el papel del periodo 1 que
 * ya fue a casa**.
 *
 * **Es una consecuencia aceptada, no un descuido.** Está en la §3 del
 * [39](../../../../docs/migracion/39-el-modelo-plano-por-competencias.md) con sus dos
 * salidas y delante de Joseth:
 *
 *     aceptarlo (lo implementado)     el papel del periodo 1 puede cambiar hasta que acabe el año
 *     pedir periodo abierto TAMBIÉN   el coordinador no puede arreglar una errata del plan
 *     al coordinador                  una vez cerrado el periodo
 *
 * Lo que la acota es que las filas son **por año**: sólo la puede tocar quien esté
 * trabajando en ese año, así que la exposición real es **dentro del mismo año y después
 * de cerrar un periodo**. La segunda salida son tres líneas en
 * `Autoriza::puedeEscribirDesempenos` el día que Joseth lo diga — **y no se ven desde
 * aquí**: este controlador sólo lee, así que lo que protege el papel se decide donde se
 * escribe.
 *
 * ## `caritas`: el nivel va en TEXTO, siempre (D17)
 *
 * `grupos.caritas` viaja en `grupo.caritas` y **el icono nunca va solo**: cada línea
 * lleva su `nivel` —el texto de la banda en la que cayó la definitiva de su asignatura—
 * y, además, `icono_infantil` / `icono_adolescente` como adorno. El Decreto 2247 art.
 * 10 y el 1411/2022 piden *«informes descriptivos … de corte cualitativo»*, y una
 * carita no lo es. **El front no puede pintar sólo el icono porque el texto siempre
 * está.**
 *
 * > **Y `caritas` se toca con el guante puesto**: es la columna de la §153 de
 * > `GruposController`, que tenía defecto `false` y ese defecto la apagaba, así que
 * > corregirle el nombre a un grupo de preescolar le cambiaba la forma de evaluar. Aquí
 * > sólo **se lee**, nunca se escribe, y se lee de `Grupo::datos`, que ya la traía.
 *
 * ## Los dos motivos por los que un nivel sale vacío, y no son el mismo
 *
 * La Fase 4 los separó y aquí se respetan:
 *
 *   - **`sin_definitiva`** — el alumno no tiene `notas_finales` en esa asignatura y ese
 *     periodo. No hay nota que traducir.
 *   - **`sin_banda`** — sí tiene nota, y **ninguna banda de la escala la cubre**. Es el
 *     [36](../../../../docs/migracion/36-la-nota-decimal-y-las-bandas-enteras.md): la
 *     nota es `DECIMAL(7,4)` y las bandas siguen siendo `int`, así que hay un hueco en
 *     cada frontera y **cuatro alumnos del año en curso ya imprimen el nivel vacío hoy**,
 *     en los tres boletines, con 200 y sin una línea en el log.
 *
 * Los dos acaban impresos distinto porque **`poblacion` los cuenta por separado**, que
 * es lo único que hace que un nivel que falta se vea **antes** de que salga el papel.
 *
 * **Y ahora cada uno se lleva por delante TODAS las líneas de su asignatura**, no una
 * celda: el nivel es uno por asignatura, así que una definitiva que falta imprime en
 * blanco el nivel de sus cinco desempeños. Se cuentan igual **por asignatura** —que es
 * donde se decide— y no por línea, porque contarlos por línea multiplicaría un fallo
 * por el tamaño del plan de área y haría parecer grave el de la materia que más
 * desempeños tiene escritos.
 *
 * ## `poblacion` contesta otra pregunta, porque la de antes ya no existe
 *
 * Contaba `desempenos_del_grupo` frente a `desempenos_impresos` —*«cuántas casillas
 * nadie miró»*—. Sin casillas, eso no es una pregunta. La que sí se va a hacer, porque
 * es el fallo que de verdad va a ocurrir, es **`asignaturas_sin_catalogo`: cuántas
 * asignaturas imprimieron sin una sola fila de plan de área** para su materia y su
 * grado. Es el `saltadas_sin_catalogo` de `desempenos/copiar`, pero en el papel: la
 * asignatura sale con su nota y su nivel y **debajo no hay nada**, que desde la pantalla
 * se ve igual que un colegio que no imprime desempeños.
 *
 * `con_nivel` y `sin_nivel` cuentan **sólo las líneas de catálogo**, y por eso suman
 * exactamente `desempenos_impresos`: una frase escrita a mano no puede tener nivel, así
 * que contarla en `sin_nivel` sería denunciar un fallo que no ha pasado.
 */
class BoletinPorCompetenciasController extends Controller
{
    use ResuelveElUsuario;

    /** @var array<int,\stdClass>|null */
    private $escalas_val = null;

    /**
     * La escala del año, una vez por petición.
     *
     * Copiada en intención de los tres de siempre —`escalasVal()`— **y sin su
     * try/catch**: el de allí se tragaba cualquier error y dejaba la propiedad en
     * `null`, y el boletín salía con los desempeños en blanco en vez de fallar. Un
     * informe mudo se imprime y se entrega.
     *
     * **Y con `ORDER BY orden, id`, que allí no está.** Esto viaja como leyenda del pie
     * y además es lo que recorre `bandaDeLaNota`: sin orden, MySQL puede devolver las
     * bandas en cualquiera, y la leyenda de un boletín cambiaría de fila entre dos
     * impresiones del mismo día.
     *
     * @return array<int,\stdClass>
     */
    private function escalasVal(): array
    {
        if ($this->escalas_val === null) {
            $this->escalas_val = DB::select(
                'SELECT * FROM escalas_de_valoracion WHERE year_id = ? AND deleted_at IS NULL ORDER BY orden, id',
                [$this->user->year_id]
            );
        }

        return $this->escalas_val;
    }

    /**
     * `PUT boletines-competencias/detailed-notas/{grupo_id}` — los alumnos que se pidan.
     *
     * `requested_alumnos` es la lista que arma la pantalla de informes; el grupo va en
     * la URL porque es por donde se acotan las asignaturas. Cuerpo vacío = el grupo
     * entero, igual que en los tres de siempre.
     */
    public function putDetailedNotas($grupo_id)
    {
        return $this->boletinDelGrupo(
            (int) $grupo_id,
            Request::input('requested_alumnos', '')
        );
    }

    /**
     * `PUT boletines-competencias/detailed-notas-group/{grupo_id}` — el grupo entero.
     *
     * **Es otro método y no el mismo con el cuerpo vacío**, porque así son las tres
     * familias de hoy y porque el front tiene dos métodos, `deAlumnos` y `deGrupo`.
     */
    public function putDetailedNotasGroup($grupo_id)
    {
        return $this->boletinDelGrupo((int) $grupo_id, '');
    }

    /**
     * @param  array<int,mixed>|string  $requested_alumnos
     * @return array{0:\stdClass,1:\stdClass,2:array<int,\stdClass>,3:array<int,\stdClass>,4:array<string,int|bool>}
     */
    private function boletinDelGrupo(int $grupo_id, $requested_alumnos): array
    {
        // `periodo_id` opcional, y aquí va en el privado y no en los dos públicos
        // porque las dos rutas entran por este método: puesto arriba se escribiría
        // dos veces y se olvidaría una. Sin el campo, el periodo activo de siempre;
        // el porqué de todo esto está en `PeriodoDelBoletin`.
        $pedido = PeriodoDelBoletin::pedido($this->user);

        if ($pedido !== null) {
            PeriodoDelBoletin::aplicar($this->user, $pedido);
        }

        $grupo = Grupo::datos($grupo_id);
        $year = Year::datos($this->user->year_id);

        /*
         * **`Grupo::alumnos` con el segundo argumento devuelve un SUPERCONJUNTO, no lo
         * que se le pide.** Con `$requested_alumnos` no vacío entra por la otra rama de
         * ese método, que trae **todos los matriculados vigentes MÁS** los retirados que
         * se hayan pedido por `matricula_id`. El filtro lo hace quien llama, y los tres
         * boletines de siempre lo hacen en un segundo `foreach`.
         *
         * Escribir aquí «pido uno y me llega uno» es un boletín de grupo entero saliendo
         * por la ruta de un alumno — que en `boletin.propio` significa **el boletín de
         * los treinta compañeros en la cuenta de un acudiente**. Por eso el filtro está
         * escrito y probado, y por eso está aquí abajo y no dentro del bucle.
         */
        $alumnos = Grupo::alumnos($grupo_id, $requested_alumnos);
        $periodo_id = (int) $this->user->periodo_id;

        $grupo->cantidad_alumnos = count($alumnos);
        $year->periodo = $this->user->numero_periodo;

        /*
         * **Este boletín nació imprimiendo lo que hubiera, como los otros doce.** Es
         * la §5 del recorrido del 17 sep
         * ([10](../../../../docs/migracion/10-definitivas.md)): el único informe que
         * preguntaba por el sello antes de pintar era `BoletinesController`, y una
         * familia nueva no hereda lo que no tiene test. Se cablea ahora y no dentro de
         * un mes porque `myvc_front` está construyendo esta pantalla esta semana, y
         * retrofitear un aviso a una pantalla ya pintada sale más caro.
         *
         * La regla es la de Joseth del 17 sep —**reparar el periodo abierto, avisar en
         * los cerrados**— y vive entera en el servicio, así que aquí no se decide
         * nada: sólo se dice **de qué** se pregunta.
         *
         * **Las dos guardas del llamante, y ninguna sobra:**
         *
         *   1. `$pedido === null` — la regla del 15 sep, que es más fuerte que la del
         *      17: quien pide un periodo concreto **está leyendo**, tenga ese periodo
         *      el interruptor levantado o no. Sin esto, abrir el boletín de un periodo
         *      cerrado que todavía admite notas lo reescribiría, que es exactamente lo
         *      que aquella decisión prohibió.
         *   2. `$soloAlumno` sale de **lo que se pidió**, no de `$alumnos`: ese método
         *      devuelve un superconjunto (ver el bloque de arriba), así que contar sus
         *      filas diría «treinta» para una petición de uno y ensancharía la
         *      escritura al grupo entero **desde una ruta `boletin.propio`**. Es el
         *      mismo criterio que usa `BoletinesController`, y por el mismo motivo.
         *
         * ## Y va AQUÍ ARRIBA, no abajo con el resto de la población
         *
         * Que las tres cuentas se devuelvan dentro de `$poblacion` invita a poner esta
         * llamada al final, junto a ellas, donde encaja sin romper nada. **Sería un
         * fallo silencioso**: en este boletín el **nivel se deriva de la definitiva**
         * —`bandaDeLaNota()`, D31—, así que reparar después del bucle dejaría la base
         * al día y devolvería el boletín con el nivel viejo dentro.
         *
         * Y aquí eso pesa más que en los otros tres boletines, que es lo que decide el
         * orden en que conviene cablear los doce que faltan: **una definitiva atrasada
         * aquí no imprime un número viejo, imprime una PALABRA equivocada** —«BAJO»
         * donde debería poner «ALTO»—. De una cifra rara el que la lee puede
         * desconfiar; de una valoración traducida, no.
         */
        $alDia = ['reparadas' => 0, 'asignaturas' => []];

        if ($pedido === null) {
            $unoSolo = is_array($requested_alumnos) && count($requested_alumnos) === 1
                ? (int) $requested_alumnos[0]['alumno_id']
                : null;

            $alDia = DefinitivasDeAsignatura::ponerAlDiaUnInforme(
                $grupo_id, $periodo_id, (int) $this->user->user_id, $unoSolo
            );
        }

        /*
         * **El plan de área se lee UNA vez para el grupo entero, y no por alumno.**
         * No es optimización de galería: es lo que dice el dato. Una fila de
         * `desempenos_por_defecto` se dirige a (año, materia, grado, periodo) y
         * **ninguna de las cuatro depende del alumno**, así que leerlo dentro del
         * bucle sería la misma consulta treinta veces con el mismo resultado. Lo que
         * sí es por alumno son las frases escritas a mano, y ésas siguen dentro.
         */
        $catalogo = $this->catalogoDelGrupo(
            $grupo_id,
            (int) $this->user->year_id,
            $grupo->grado_id === null ? null : (int) $grupo->grado_id,
            $periodo_id
        );

        $conteos = [];

        foreach ($alumnos as $alumno) {
            $conteos[(int) $alumno->alumno_id] = $this->boletinDelAlumno(
                $alumno, $grupo_id, $periodo_id, (bool) $grupo->caritas, $catalogo
            );
        }

        /*
         * El puesto lo decide el servicio, no un `foreach` — fase 6 del
         * [19](../../../../docs/migracion/19-boletin-independiente.md) §7. Con
         * `years.puestos_con_bol_independiente` en 1 —el default y lo de los dieciséis
         * colegios— esto es lo mismo que en los tres de siempre, fila por fila.
         *
         * Va **sobre la lista entera y antes de filtrar**, que no es un detalle: un
         * puesto es una posición relativa, así que calcularlo sobre el alumno que se
         * pidió le daría el primero a cualquiera que pida su propio boletín.
         */
        BoletinIndependiente::ponerPuestos($alumnos, [$periodo_id], (int) $this->user->year_id);

        $respuesta = $this->soloLosPedidos($alumnos, $requested_alumnos);

        // La población es **la de lo que se imprime**, no la del grupo: quien pide un
        // alumno recibe el recuento de ese alumno.
        $poblacion = [
            'alumnos' => count($respuesta),
            'asignaturas' => 0,
            // El fallo que sí va a ocurrir: el plan de área que el colegio no escribió
            // para esa materia y ese grado. Sustituye al par `desempenos_del_grupo` /
            // `desempenos_impresos`, que preguntaba por casillas que ya no existen.
            'asignaturas_sin_catalogo' => 0,
            'desempenos_impresos' => 0,
            'frases_sueltas' => 0,
            'con_nivel' => 0,
            'sin_nivel' => 0,
            'asignaturas_sin_definitiva' => 0,
            'asignaturas_sin_banda' => 0,
        ];

        foreach ($respuesta as $alumno) {
            foreach ($conteos[(int) $alumno->alumno_id] as $clave => $cuantos) {
                $poblacion[$clave] += $cuantos;
            }
        }

        $poblacion['caritas'] = (bool) $grupo->caritas;

        // **El aviso va en la población y no en una clave suelta**, porque es
        // exactamente lo que este bloque ya es: el recuento de lo que se imprimió. Un
        // «0 por detrás» sin el resto de la población no distingue *«miré doce
        // asignaturas y ninguna lo estaba»* de *«no miré nada»* — la regla del
        // CLAUDE.md, y aquí muerde porque con un periodo pedido **no se mira**.
        //
        // Las dos cuentas van separadas y el front las pinta distinto: `atrasadas` se
        // repara sola y `faltan` **no la arregla nadie** hasta el punto 6 de la fase 2
        // —ni el recálculo ni el botón de Informes—, así que sumarlas en un número
        // haría que el coordinador pulsara esperando que bajara y no bajara.
        $poblacion['definitivas_reparadas'] = $alDia['reparadas'];
        $poblacion['definitivas_atrasadas'] = array_sum(array_column($alDia['asignaturas'], 'atrasadas'));
        $poblacion['definitivas_que_faltan'] = array_sum(array_column($alDia['asignaturas'], 'faltan'));

        return [$grupo, $year, $respuesta, $this->escalasVal(), $poblacion];
    }

    /**
     * Los que se pidieron, y en el orden en que venían del grupo.
     *
     * @param  array<int,\stdClass>  $alumnos
     * @param  array<int,mixed>|string  $requested_alumnos  tal cual llega del cuerpo: aquí no hay tipo
     * @return array<int,\stdClass>
     */
    private function soloLosPedidos(array $alumnos, $requested_alumnos): array
    {
        if (! is_array($requested_alumnos) || $requested_alumnos === []) {
            return array_values($alumnos);
        }

        $pedidos = [];

        foreach ($requested_alumnos as $pedido) {
            if (is_array($pedido) && isset($pedido['alumno_id'])) {
                $pedidos[(int) $pedido['alumno_id']] = true;
            }
        }

        if ($pedidos === []) {
            return array_values($alumnos);
        }

        return array_values(array_filter(
            $alumnos,
            fn ($alumno) => isset($pedidos[(int) $alumno->alumno_id])
        ));
    }

    /**
     * Un alumno: sus asignaturas con su nota, su nivel y su árbol de competencias.
     *
     * Devuelve **sus contadores** en vez de sumarlos en el sitio, porque la población de
     * la respuesta es la de los alumnos que se imprimen y aquí todavía no se sabe
     * cuáles son: el boletín se calcula para el grupo entero —hace falta para el
     * puesto— y se filtra después.
     *
     * @param  array<int,list<\stdClass>>  $catalogo  el plan de área del grupo, por `asignatura_id`
     * @return array<string,int>
     */
    private function boletinDelAlumno(\stdClass $alumno, int $grupo_id, int $periodo_id, bool $caritas, array $catalogo): array
    {
        $alumno_id = (int) $alumno->alumno_id;

        $asignaturas = Grupo::detailed_materias_notafinal(
            $alumno_id, $grupo_id, $periodo_id, $this->user->year_id
        );

        // Dos consultas por alumno para lo que los tres de siempre piden una por
        // asignatura. No es optimización de galería: la Fase 5 midió **1.061
        // consultas** en una petición de UN alumno del primero.
        $faltas = $this->faltasPorAsignatura($alumno_id, $periodo_id);
        $frases = $this->frasesDelAlumno($alumno_id, $grupo_id, $periodo_id);

        $conteo = [
            'asignaturas' => 0,
            'asignaturas_sin_catalogo' => 0,
            'desempenos_impresos' => 0,
            'frases_sueltas' => 0,
            'con_nivel' => 0,
            'sin_nivel' => 0,
            'asignaturas_sin_definitiva' => 0,
            'asignaturas_sin_banda' => 0,
        ];

        $suma = 0;

        foreach ($asignaturas as $asignatura) {
            $conteo['asignaturas']++;
            $asignatura_id = (int) $asignatura->asignatura_id;

            $asignatura->total_ausencias = (int) ($faltas[$asignatura_id]->total_ausencias ?? 0);
            $asignatura->total_tardanzas = (int) ($faltas[$asignatura_id]->total_tardanzas ?? 0);

            /*
             * **Los dos motivos, separados como los separó la Fase 4.**
             * `nota_asignatura` sale de un `left join` a `notas_finales` y `desempenio`
             * de otro `left join` a `escalas_de_valoracion`: que el segundo venga nulo
             * con el primero puesto **no** es «no tiene nota», es «su nota no cae en
             * ninguna banda» (doc 36). Imprimirlos igual es lo que hace que nadie vea
             * el segundo hasta que el papel sale sin nivel.
             */
            $asignatura->motivo_del_nivel = match (true) {
                $asignatura->nota_asignatura === null => 'sin_definitiva',
                $asignatura->desempenio === null => 'sin_banda',
                default => null,
            };

            match ($asignatura->motivo_del_nivel) {
                'sin_definitiva' => $conteo['asignaturas_sin_definitiva']++,
                'sin_banda' => $conteo['asignaturas_sin_banda']++,
                default => null,
            };

            $asignatura->bol_independiente = BoletinIndependiente::aplica($alumno_id, $periodo_id);

            $this->ponerLosDesempenos(
                $asignatura,
                $catalogo[$asignatura_id] ?? [],
                $frases[$asignatura_id] ?? [],
                $caritas,
                $conteo
            );

            $suma += (float) $asignatura->nota_asignatura;
        }

        $alumno->asignaturas = $asignaturas;
        $alumno->promedio = count($asignaturas) === 0 ? 0 : $suma / count($asignaturas);

        $banda = $this->bandaDeLaNota((float) $alumno->promedio);
        $alumno->promedio_desempenio = $banda === null ? null : $banda->desempenio;

        $this->ponerElComportamiento($alumno, $alumno_id, $periodo_id);

        $alumno->situaciones = Disciplina::situaciones_year($alumno_id, $this->user->year_id, $periodo_id);

        return $conteo;
    }

    /**
     * Las líneas que se imprimen debajo de una asignatura: **el plan de área primero
     * y las frases escritas a mano al final**, en un solo array y sin cabeceras.
     *
     * **El nivel se calcula UNA vez por asignatura y se copia en todas sus líneas.**
     * No es un atajo: es lo que dice P1.bis —el nivel se deriva de la definitiva— y
     * significa que aquí no hay ninguna decisión por línea que tomar. Calcularlo dentro
     * del bucle sería recorrer la escala una vez por desempeño para obtener siempre la
     * misma banda.
     *
     * @param  list<\stdClass>  $catalogo  las filas de `desempenos_por_defecto` que le tocan
     * @param  list<\stdClass>  $frases  las de `frases_asignatura`, escritas a mano
     * @param  array<string,int>  $conteo
     */
    private function ponerLosDesempenos(\stdClass $asignatura, array $catalogo, array $frases, bool $caritas, array &$conteo): void
    {
        /*
         * **La misma nota que ya trae la asignatura, y la misma regla.**
         * `$asignatura->desempenio` viene del `left join` de
         * `Grupo::detailed_materias_notafinal`, que compara igual que `bandaDeLaNota`
         * —lo sostiene `CentinelaDeLaReglaDeLaBandaTest`—, así que estas dos líneas no
         * pueden discrepar de `motivo_del_nivel`: el nivel sale vacío **exactamente**
         * cuando ese motivo está puesto, y por eso el motivo explica el papel.
         */
        $banda = $this->bandaDeLaNota(
            $asignatura->nota_asignatura === null ? null : (float) $asignatura->nota_asignatura
        );

        // El plan de área que el colegio no escribió para esta materia y este grado.
        // Se cuenta aquí y no al leer el catálogo porque lo que importa es **lo que
        // se imprimió en blanco**, que es por asignatura y por alumno.
        if ($catalogo === []) {
            $conteo['asignaturas_sin_catalogo']++;
        }

        $lineas = [];

        foreach ($catalogo as $fila) {
            $conteo['desempenos_impresos']++;
            $conteo[$banda === null ? 'sin_nivel' : 'con_nivel']++;

            $lineas[] = (object) [
                'desempeno_id' => (int) $fila->id,
                'frase_asignatura_id' => null,
                'texto' => $this->conElPrefijo($banda, (string) $fila->definicion),
                'tipo' => $fila->tipo,
                'orden' => (int) $fila->orden,
                // `grado_id` a `null` es «todos los grados», y viaja porque es lo único
                // que distingue una fila que el docente puede editar de una que no
                // (§3 del 39). Sin ella, la pantalla no puede explicar el candado.
                'grado_id' => $fila->grado_id === null ? null : (int) $fila->grado_id,
                // D17: **el texto siempre**, tenga o no `caritas` el grupo.
                'escala_id' => $banda === null ? null : (int) $banda->id,
                'nivel' => $banda === null ? null : $banda->desempenio,
                // Y el icono como adorno, sólo cuando el grupo lo pidió. Nunca solo.
                'icono_infantil' => $caritas && $banda !== null ? $banda->icono_infantil : null,
                'icono_adolescente' => $caritas && $banda !== null ? $banda->icono_adolescente : null,
                'origen' => 'catalogo',
            ];
        }

        /*
         * **Las de a mano, al final y sin nivel.** No llevan `escala_id` ni icono, y no
         * es que se hayan quedado sin él: una frase que escribió el docente para ESE
         * alumno no es una fila de un plan de área, así que ponerle la banda de la
         * asignatura sería afirmar sobre ella algo que nadie dijo.
         */
        foreach ($frases as $frase) {
            $conteo['frases_sueltas']++;

            $lineas[] = (object) [
                'desempeno_id' => null,
                'frase_asignatura_id' => (int) $frase->frase_asignatura_id,
                'texto' => $frase->texto,
                'tipo' => null,
                'orden' => null,
                'grado_id' => null,
                'escala_id' => null,
                'nivel' => null,
                'icono_infantil' => null,
                'icono_adolescente' => null,
                'origen' => 'frase',
            ];
        }

        $asignatura->desempenos = $lineas;
    }

    /**
     * El texto de la banda delante del desempeño, cuando el colegio lo ha escrito.
     *
     * `escalas_de_valoracion.descripcion` es la frase del SIEE —*«El estudiante alcanza
     * de manera superior…»*— y va **delante**, que es como la imprimen los boletines de
     * papel del corpus (P2, H5).
     *
     * **Vacía = exactamente como hoy**, y el `trim` es lo que decide las dos cosas a la
     * vez. Medido en el docker el 17 sep 2026: **36 escalas vivas, 5 con `descripcion`
     * a `NULL`, 31 a cadena vacía y NINGUNA con texto**, así que hoy esto no cambia un
     * solo boletín — el día que cambie será porque un colegio escribió la frase de su
     * SIEE, que es cuando tiene que cambiar. `NULL` y `''` significan lo mismo aquí, y
     * ese 5 / 31 es el porqué: la columna las reparte según qué versión del formulario
     * la escribió, y tratarlas distinto imprimiría un espacio de más en unos colegios y
     * no en otros.
     */
    private function conElPrefijo(?\stdClass $banda, string $texto): string
    {
        $prefijo = $banda === null ? '' : trim((string) $banda->descripcion);

        return $prefijo === '' ? $texto : $prefijo.' '.$texto;
    }

    /**
     * El plan de área del grupo entero, **en una consulta y no una por asignatura**.
     *
     * Una fila de `desempenos_por_defecto` se dirige a (año, materia, grado, periodo) y
     * lo que la convierte en líneas de un boletín son las **asignaturas** del grupo, que
     * son las que ponen la materia. Por eso el `INNER JOIN` es por `materia_id` y el
     * resultado sale ya repartido por `asignatura_id`: un grupo de diez asignaturas son
     * diez listas y una sola ida a la base.
     *
     * **`grado_id IS NULL` acumula, no compite** (D25): una fila de «todos los grados» y
     * una del grado se imprimen **las dos**. Ése `OR` es la regla entera, y es también
     * lo que hace segura la única rama que aquí no se puede probar: `grupos.grado_id` es
     * **`NOT NULL`** —comprobado en el volcado y en el docker—, así que el `?int` nunca
     * llega nulo hoy; y si llegara, `= NULL` no es cierto nunca y la consulta devolvería
     * **sólo las de «todos los grados»**, que es exactamente lo que le toca a un grupo
     * sin grado. Degrada bien, que es lo que se le pide a una rama que nadie recorre.
     *
     * *(El comentario va aquí y no dentro del SQL a propósito: un `--` dentro de la
     * cadena comentaría el resto de la consulta el día que alguien normalice los saltos
     * de línea. Es el aviso de `Grupo::detailed_materias_notafinal`.)*
     *
     * El `ORDER BY` es **el mismo que el de la pantalla donde se escribe**
     * —`DesempenosController::catalogoPara`—, y eso es lo que decide su primera clave:
     * las de «todos los grados» arriba y las del grado debajo. Un boletín que ordenara
     * estas mismas filas de otra manera que la pantalla en la que el colegio las tecleó
     * sería un fallo que sólo se ve comparando dos papeles. **Y `id` detrás de `orden`**
     * porque `orden` es `int NOT NULL DEFAULT 0` y empata: un orden que empata sin
     * desempate es un boletín que cambia de forma entre dos impresiones del mismo día
     * (03-tests.md, «`ORDER BY` que empata»).
     *
     * @return array<int,list<\stdClass>> por `asignatura_id`
     */
    private function catalogoDelGrupo(int $grupo_id, int $year_id, ?int $grado_id, int $periodo_id): array
    {
        $consulta = 'SELECT a.id AS asignatura_id,
                            d.id, d.definicion, d.tipo, d.orden, d.grado_id
                       FROM asignaturas a
                       INNER JOIN desempenos_por_defecto d
                               ON d.materia_id = a.materia_id
                              AND d.year_id = :year_id
                              AND d.periodo_id = :periodo_id
                              AND (d.grado_id IS NULL OR d.grado_id = :grado_id)
                              AND d.deleted_at IS NULL
                      WHERE a.grupo_id = :grupo_id AND a.deleted_at IS NULL
                      ORDER BY a.id, d.grado_id IS NOT NULL, d.orden, d.id';

        $filas = DB::select($consulta, [
            ':year_id' => $year_id,
            ':periodo_id' => $periodo_id,
            ':grado_id' => $grado_id,
            ':grupo_id' => $grupo_id,
        ]);

        $por_asignatura = [];

        foreach ($filas as $fila) {
            $por_asignatura[(int) $fila->asignatura_id][] = $fila;
        }

        return $por_asignatura;
    }

    /**
     * Las frases escritas a mano de un alumno en el grupo, **en una consulta**.
     *
     * Los tres boletines de hoy llaman a `FraseAsignatura::deAlumno` **una vez por
     * asignatura**; aquí es una por alumno.
     *
     * **`IFNULL(f.frase, fa.frase)` y no una sola de las dos**, que es lo que hace la
     * pantalla de siempre: `frase_id` apunta al banco de frases del colegio y `frase` es
     * lo que se tecleó a mano, y una fila tiene una u otra.
     *
     * **Aquí no se filtra por `desempeno_id`, y no es un olvido**: esa columna la añadió
     * la migración de la rejilla, que se borra, y en los dieciséis colegios **nunca
     * existió** — nombrarla sería un `Unknown column` allí con la suite verde aquí. El
     * porqué largo está en el docblock de la clase; lo que importa en este método es que
     * **todas las filas de esta tabla son frases escritas a mano**, porque las otras no
     * las pudo crear nadie: su única pantalla nunca se desplegó (§7 del 39).
     *
     * *(El comentario va aquí y no dentro del SQL a propósito: un `--` dentro de la
     * cadena comentaría el resto de la consulta el día que alguien normalice los saltos
     * de línea. Es el aviso de `Grupo::detailed_materias_notafinal`.)*
     *
     * `ORDER BY fa.id` y no por texto: es el orden en que el docente las escribió, que
     * es el único que existe en esta tabla, y desempata siempre.
     *
     * @return array<int,list<\stdClass>> por `asignatura_id`
     */
    private function frasesDelAlumno(int $alumno_id, int $grupo_id, int $periodo_id): array
    {
        $consulta = 'SELECT fa.id AS frase_asignatura_id, fa.asignatura_id,
                            IFNULL(f.frase, fa.frase) AS texto
                       FROM frases_asignatura fa
                       INNER JOIN asignaturas a ON a.id = fa.asignatura_id AND a.deleted_at IS NULL AND a.grupo_id = :grupo_id
                       LEFT JOIN frases f ON f.id = fa.frase_id AND f.deleted_at IS NULL
                      WHERE fa.deleted_at IS NULL AND fa.alumno_id = :alumno_id AND fa.periodo_id = :periodo_id
                      ORDER BY fa.asignatura_id, fa.id';

        $filas = DB::select($consulta, [
            ':grupo_id' => $grupo_id,
            ':alumno_id' => $alumno_id,
            ':periodo_id' => $periodo_id,
        ]);

        $por_asignatura = [];

        foreach ($filas as $fila) {
            $por_asignatura[(int) $fila->asignatura_id][] = $fila;
        }

        return $por_asignatura;
    }

    /**
     * Faltas y tardanzas por asignatura, **en una consulta y no en una por asignatura**.
     *
     * Suma `cantidad_ausencia` y `cantidad_tardanza` —no cuenta filas— porque es lo que
     * hacen los tres de siempre en su bucle: una fila de `ausencias` puede valer más de
     * una falta.
     *
     * @return array<int,\stdClass> por `asignatura_id`
     */
    private function faltasPorAsignatura(int $alumno_id, int $periodo_id): array
    {
        $filas = DB::select(
            'SELECT asignatura_id,
                    SUM(CASE WHEN tipo = "ausencia" THEN cantidad_ausencia ELSE 0 END) AS total_ausencias,
                    SUM(CASE WHEN tipo = "tardanza" THEN cantidad_tardanza ELSE 0 END) AS total_tardanzas
               FROM ausencias
              WHERE alumno_id = ? AND periodo_id = ? AND deleted_at IS NULL
              GROUP BY asignatura_id',
            [$alumno_id, $periodo_id]
        );

        $por_asignatura = [];

        foreach ($filas as $fila) {
            $por_asignatura[(int) $fila->asignatura_id] = $fila;
        }

        return $por_asignatura;
    }

    /**
     * La banda de la escala en la que cae una nota, o `null` si no cae en ninguna.
     *
     * **`null` es una respuesta, no un fallo.** El doc 36 lo midió: la nota es
     * `DECIMAL(7,4)` desde `2026_08_30_200000` y las bandas siguen siendo `int`, así que
     * hay un hueco en cada frontera y hoy ya hay cuatro definitivas del año en curso
     * dentro de uno.
     *
     * ## Y por qué NO se usa `EscalaDeValoracion::valoracion`, que hace justo esto
     *
     * Porque **no hace justo esto**, y la diferencia se imprime:
     *
     *     public static function valoracion($nota, $escalas_val)
     *     {
     *         $nota = round($nota);                          // ← redondea
     *         …
     *         return (object)[ 'desempenio' => '' ];         // ← y nunca devuelve null
     *     }
     *
     * Los tres boletines de hoy usan **las dos reglas a la vez y en la misma respuesta**:
     * el nivel de cada asignatura sale del `left join` a `escalas_de_valoracion` de
     * `Grupo::detailed_materias_notafinal`, que **no redondea** y deja `NULL`; y
     * `promedio_desempenio` sale de `valoracion()`, que **sí redondea** y devuelve `''`.
     * O sea que un 29,5 con la escala cortada en 29/30 imprime la asignatura sin nivel y
     * el promedio como «ALTO», en el mismo papel.
     *
     * Aquí se usa **una sola regla**: sin redondear y con `null` cuando no cae, que es
     * lo que hace que `motivo_del_nivel` signifique algo — con `''` el hueco queda
     * escondido detrás de una cadena vacía que parece un nivel.
     *
     * > ⚠️ **Este párrafo decía que la regla «coincide con lo que el `left join` ya hace
     * > por asignatura, que es lo que no se puede cambiar sin tocar los boletines de
     * > siempre». Las dos mitades dejaron de ser ciertas el 13 sep 2026** (`bd02f66`):
     * > el `left join` **sí** se cambió —en los trece sitios, junto con los boletines de
     * > siempre— y pasó a `nota < porc_final + 1`, así que esta comparación **se quedó
     * > sola con la regla vieja** y reintrodujo aquí dentro el doble criterio que el
     * > doc 36 describe para los boletines viejos: la asignatura sin nivel y el promedio
     * > con uno, en el mismo papel.
     * >
     * > **La alineación era deliberada y por eso el arreglo no era sólo el operador**:
     * > una justificación que dice lo contrario de lo que hace es peor que ninguna,
     * > porque la ninguna te manda a leer el código. Lo destapó `myvc-front-50` leyendo
     * > este docblock, no la suite: **ningún test miraba esta comparación**.
     * >
     * > Hoy la alineación la sostiene `CentinelaDeLaReglaDeLaBandaTest`, que falla si
     * > alguien vuelve a escribir `<= porc_final` en `app/`. **Eso es lo que la hace una
     * > alineación y no una coincidencia.**
     */
    private function bandaDeLaNota(?float $nota): ?\stdClass
    {
        if ($nota === null) {
            return null;
        }

        foreach ($this->escalasVal() as $banda) {
            if ($nota >= $banda->porc_inicial && $nota < $banda->porc_final + 1) {
                return $banda;
            }
        }

        return null;
    }

    /**
     * El comportamiento del periodo con sus definiciones.
     *
     * **`NotaComportamiento::nota_comportamiento` no siempre devuelve un objeto.** Sin
     * fila devuelve `["notas_finales" => []]`, que es un array **no vacío** y por tanto
     * *truthy*: el `if ($comportamiento)` de los tres boletines de siempre entra, y el
     * `->definiciones` de dentro revienta. Por eso allí hay un `try/catch` que asigna
     * `$alumno->comportamiento['definiciones']` en el `catch` — o sea que el campo sale
     * **con una forma u otra según si el alumno tenía nota**, y el front recibe a veces
     * un objeto y a veces un array.
     *
     * Aquí se mira el tipo en vez de atrapar el error, que es lo mismo sin el `catch`
     * que se traga cualquier otra cosa que pase por ahí.
     *
     * Sin el `encabezado_comportamiento_boletin` de aquéllos: es **texto de maqueta**
     * —«Su comportamiento fue…», conjugado por sexo— y esa frase la escribe la maqueta
     * nueva. La Fase 5 midió que `boletines3` ni siquiera honra dentro de ella
     * `years.solo_escalas_valorativas`, que es una bandera del colegio.
     */
    private function ponerElComportamiento(\stdClass $alumno, int $alumno_id, int $periodo_id): void
    {
        $comportamiento = NotaComportamiento::nota_comportamiento(
            $alumno_id, $periodo_id, $this->user->year_id, $this->escalasVal()
        );

        if (! is_object($comportamiento)) {
            $alumno->comportamiento = null;

            return;
        }

        $comportamiento->definiciones = DefinicionComportamiento::frases($comportamiento->id);
        $alumno->comportamiento = $comportamiento;
    }
}
