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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;

/**
 * **El boletín por competencias** — la Fase 6 de
 * [35-el-modelo-de-evaluacion-del-colegio.md](../../../../docs/migracion/35-el-modelo-de-evaluacion-del-colegio.md),
 * con **D16** y **D17** encima.
 *
 * La competencia arriba, sus desempeños debajo y los sueltos al final, que es la fila
 * del plan de área tal como la teclea un colegio y como la leen los catorce SIEE del
 * corpus (`myvc_front/PLAN-FRONT-MODELO-DE-EVALUACION.md` §4.3).
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
 * ## Lo que se imprime es lo que el docente guardó, y ni un nivel más
 *
 * **Sólo salen las filas que existen en `frases_asignatura`.** Un desempeño del grupo
 * que nadie haya marcado **no aparece**, ni siquiera en blanco: es la decisión 10 y la
 * Fase 4 la sostiene por el otro lado —*«ningún boletín afirma un nivel que nadie
 * miró»*—. Lo que la respuesta sí hace es **contarlo**: `poblacion.desempenos_del_grupo`
 * frente a `poblacion.desempenos_impresos` dice cuántas casillas quedaron sin mirar,
 * que es la pregunta que el colegio hace antes de imprimir.
 *
 * ## `caritas`: el nivel va en TEXTO, siempre (D17)
 *
 * `grupos.caritas` viaja en `grupo.caritas` y **el icono nunca va solo**: cada
 * desempeño lleva su `nivel` —el texto congelado el día que se puso— y, además,
 * `icono_infantil` / `icono_adolescente` como adorno. El Decreto 2247 art. 10 y el
 * 1411/2022 piden *«informes descriptivos … de corte cualitativo»*, y una carita no lo
 * es. **El front no puede pintar sólo el icono porque el texto siempre está.**
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

        $conteos = [];

        foreach ($alumnos as $alumno) {
            $conteos[(int) $alumno->alumno_id] = $this->boletinDelAlumno(
                $alumno, $grupo_id, $periodo_id, (bool) $grupo->caritas
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
            'desempenos_del_grupo' => $this->cuantosDesempenosTieneElGrupo($grupo_id, $periodo_id),
            'competencias' => 0,
            'desempenos_impresos' => 0,
            'desempenos_sueltos' => 0,
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
     * @return array<string,int>
     */
    private function boletinDelAlumno(\stdClass $alumno, int $grupo_id, int $periodo_id, bool $caritas): array
    {
        $alumno_id = (int) $alumno->alumno_id;

        $asignaturas = Grupo::detailed_materias_notafinal(
            $alumno_id, $grupo_id, $periodo_id, $this->user->year_id
        );

        // Dos consultas por alumno para lo que los tres de siempre piden una por
        // asignatura. No es optimización de galería: la Fase 5 midió **1.061
        // consultas** en una petición de UN alumno del primero.
        $faltas = $this->faltasPorAsignatura($alumno_id, $periodo_id);
        $marcas = $this->marcasDelAlumno($alumno_id, $grupo_id, $periodo_id);

        $conteo = [
            'asignaturas' => 0,
            'competencias' => 0,
            'desempenos_impresos' => 0,
            'desempenos_sueltos' => 0,
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

            $this->repartirLasMarcas($asignatura, $marcas[$asignatura_id] ?? [], $caritas, $conteo);

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
     * La competencia arriba, sus desempeños debajo, y los sueltos al final.
     *
     * @param  array<int,\stdClass>  $marcas
     * @param  array<string,int>  $conteo
     */
    private function repartirLasMarcas(\stdClass $asignatura, array $marcas, bool $caritas, array &$conteo): void
    {
        $competencias = [];
        $sueltos = [];

        foreach ($marcas as $marca) {
            $fila = $this->filaDelDesempeno($marca, $caritas);

            if ($fila->nivel === null) {
                $conteo['sin_nivel']++;
            } else {
                $conteo['con_nivel']++;
            }

            /*
             * **Suelto es lo que no cuelga de una competencia**, y son dos casos que
             * el front tiene que poder distinguir, por eso va `origen`:
             *
             *   - `frase`   — una frase escrita a mano en la pantalla de siempre
             *                 (`desempeno_id IS NULL`). Son las **12.294** filas que ya
             *                 hay en `simonbolivar` y ninguna vino de una rejilla.
             *   - `rejilla` — una celda de un desempeño que el colegio dejó **sin
             *                 competencia** (`desempenos.competencia_id IS NULL`), que
             *                 es legal: D5 permite el desempeño suelto.
             *
             * Las dos se imprimen al final y **con su nivel si lo tienen**: una frase a
             * mano no lo tiene y un desempeño suelto sí.
             */
            if ($marca->competencia_id === null) {
                $conteo[$fila->origen === 'frase' ? 'frases_sueltas' : 'desempenos_sueltos']++;
                $sueltos[] = $fila;

                continue;
            }

            $clave = (int) $marca->competencia_id;

            if (! isset($competencias[$clave])) {
                $conteo['competencias']++;
                $competencias[$clave] = (object) [
                    'competencia_id' => $clave,
                    /*
                     * **Y esto NO está congelado, a diferencia del texto del desempeño.**
                     * `frases_asignatura` copia `frase` el día que se guarda —es el
                     * argumento entero de la tercera columna de la Fase 4—, pero **no hay
                     * dónde copiar el texto de la competencia**: aquí se lee de
                     * `competencias.definicion`, o sea el de hoy. Renombrar una
                     * competencia en 2028 **cambia la cabecera de un boletín de 2026**.
                     * Queda dicho en vez de descubrirse dentro de dos años: taparlo es una
                     * cuarta columna y una entrega propia.
                     */
                    'definicion' => $marca->definicion_competencia,
                    'codigo_men' => $marca->codigo_men,
                    'orden' => (int) $marca->orden_competencia,
                    'desempenos' => [],
                ];
            }

            $conteo['desempenos_impresos']++;
            $competencias[$clave]->desempenos[] = $fila;
        }

        $asignatura->competencias = array_values($competencias);
        $asignatura->desempenos_sueltos = $sueltos;
    }

    /**
     * Una fila de lo que se imprime debajo de la asignatura.
     *
     * **El texto es el congelado, no el del catálogo de hoy.** `texto` sale de
     * `frases_asignatura`, que es donde la Fase 4 lo copió, y `desempenos.definicion`
     * **no se emite**: emitir los dos invita a imprimir el que no es, que es justo lo
     * que la tercera columna de la Fase 4 existe para evitar.
     */
    private function filaDelDesempeno(\stdClass $marca, bool $caritas): \stdClass
    {
        return (object) [
            'frase_asignatura_id' => (int) $marca->frase_asignatura_id,
            'desempeno_id' => $marca->desempeno_id === null ? null : (int) $marca->desempeno_id,
            'texto' => $marca->texto,
            'tipo' => $marca->tipo_desempeno,
            'orden' => $marca->orden_desempeno === null ? null : (int) $marca->orden_desempeno,
            // D17: **el texto siempre**, tenga o no `caritas` el grupo.
            'escala_id' => $marca->escala_id === null ? null : (int) $marca->escala_id,
            'nivel' => $marca->nivel,
            // Y el icono como adorno, sólo cuando el grupo lo pidió. Nunca solo.
            'icono_infantil' => $caritas ? $marca->icono_infantil : null,
            'icono_adolescente' => $caritas ? $marca->icono_adolescente : null,
            'origen' => $marca->desempeno_id === null ? 'frase' : 'rejilla',
        ];
    }

    /**
     * Todas las marcas de un alumno en el grupo, **en una consulta**.
     *
     * Los tres boletines de hoy llaman a `FraseAsignatura::deAlumno` **una vez por
     * asignatura**; aquí es una por alumno y trae además de qué casilla salió cada una
     * y con qué nivel.
     *
     * **`c.id AS competencia_id` y no `d.competencia_id`**, que es la diferencia entre
     * imprimir bien e imprimir una cabecera en blanco: el `left join` filtra
     * `c.deleted_at IS NULL`, así que con la competencia borrada `d.competencia_id` sigue
     * puesto y `c.*` viene todo nulo. Leyendo el de `desempenos`, ese desempeño abriría
     * un bloque con el título vacío; leyendo el de `competencias` cae entre los sueltos,
     * que es donde estaría si nunca hubiera tenido una.
     *
     * *(El comentario va aquí y no dentro del SQL a propósito: un `--` dentro de la
     * cadena comentaría el resto de la consulta el día que alguien normalice los saltos
     * de línea. Es el aviso de `Grupo::detailed_materias_notafinal`.)*
     *
     * El `ORDER BY` es el contrato de la maqueta: competencia por su orden, y dentro el
     * desempeño por el suyo. **Las tres claves llevan su `id` detrás** porque `orden`
     * empata —es `int NOT NULL DEFAULT 0` en las dos tablas— y un orden que empata sin
     * desempate es un boletín que cambia de forma entre dos impresiones del mismo día
     * (03-tests.md, «`ORDER BY` que empata»).
     *
     * @return array<int,array<int,\stdClass>> por `asignatura_id`
     */
    private function marcasDelAlumno(int $alumno_id, int $grupo_id, int $periodo_id): array
    {
        $consulta = 'SELECT fa.id AS frase_asignatura_id, fa.asignatura_id,
                            IFNULL(f.frase, fa.frase) AS texto,
                            fa.desempeno_id, fa.escala_id, fa.nivel,
                            d.tipo AS tipo_desempeno, d.orden AS orden_desempeno,
                            c.id AS competencia_id,
                            c.definicion AS definicion_competencia, c.codigo_men, c.orden AS orden_competencia,
                            e.icono_infantil, e.icono_adolescente
                       FROM frases_asignatura fa
                       INNER JOIN asignaturas a ON a.id = fa.asignatura_id AND a.deleted_at IS NULL AND a.grupo_id = :grupo_id
                       LEFT JOIN frases f ON f.id = fa.frase_id AND f.deleted_at IS NULL
                       LEFT JOIN desempenos d ON d.id = fa.desempeno_id AND d.deleted_at IS NULL
                       LEFT JOIN competencias c ON c.id = d.competencia_id AND c.deleted_at IS NULL
                       LEFT JOIN escalas_de_valoracion e ON e.id = fa.escala_id AND e.deleted_at IS NULL
                      WHERE fa.deleted_at IS NULL AND fa.alumno_id = :alumno_id AND fa.periodo_id = :periodo_id
                      ORDER BY fa.asignatura_id,
                               c.orden IS NULL, c.orden, c.id,
                               d.orden, d.id, fa.id';

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
     * ## La regla es la de `bd02f66`, y este método era el SITIO 14
     *
     *     porc_inicial <= nota  AND  nota < porc_final + 1
     *
     * **Nació con la regla vieja y se quedó fuera del barrido que la cambió.**
     * `2026_08_30_200000` volvió `notas_finales.nota` un `decimal(7,4)` y las bandas
     * siguieron siendo `int`, así que una escala contigua por enteros —BAJO 0-29, BÁSICO
     * 30-39…— dejaba un hueco en cada frontera y un 45,5 no casaba con ninguna. `bd02f66`
     * lo cerró **en trece sitios** el 13 sep 2026. Éste es el catorce: se escribió esa
     * misma tarde, entró en `main` **media hora antes** de aquel barrido y su censo no lo
     * alcanzó.
     *
     * **Y no era cosmético: rompía dentro de esta misma respuesta lo que este docblock
     * prometía.** `Grupo::detailed_materias_notafinal` —que `bd02f66` sí arregló— da el
     * nivel de cada asignatura con la regla nueva, y esto daba `promedio_desempenio` con
     * la vieja. Con la escala cortada en 29/30 y un 29,5, la asignatura salía **BAJO** y
     * el promedio **sin nivel**, en el mismo papel. Es exactamente la avería que este
     * método existía para no repetir, cometida por quedarse quieto mientras los demás se
     * movían.
     *
     * > **La lección, que es la que vale para el siguiente:** un barrido de «los trece
     * > sitios» se cuenta con un `grep` **el día que se funde**, no el día que se
     * > escribe. Entre una cosa y otra cabe una entrega entera — cupo ésta.
     *
     * ## `null` sigue siendo una respuesta, no un fallo
     *
     * Con las fronteras cerradas quedan **dos** formas legítimas de no caer en ninguna
     * banda, y las dos son del colegio, no del redondeo:
     *
     * 1. **una escala con un agujero de verdad** —`[0,59]` y `[61,100]`, y un 60—, que es
     *    algo que el colegio escribió así;
     * 2. **una nota por encima del techo** de la banda más alta. Son las **nueve** que
     *    `bd02f66` dejó a la vista al arreglar las otras cuatro: un dato malo, y taparlo
     *    lo escondería.
     *
     * Por eso aquí se devuelve `null` y no el `(object)['desempenio' => '']` que devuelve
     * `EscalaDeValoracion::valoracion`: con la cadena vacía, los dos casos de arriba se
     * imprimen como si tuvieran nivel y `motivo_del_nivel` deja de significar nada.
     */
    private function bandaDeLaNota(?float $nota): ?\stdClass
    {
        if ($nota === null) {
            return null;
        }

        foreach ($this->escalasVal() as $banda) {
            // `nota < porc_final + 1`, no `nota <= porc_final`: la banda llega hasta justo
            // antes del primer entero de la siguiente. Ver el docblock — es la regla de
            // `bd02f66` y este método fue el sitio que se quedó sin ella.
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

    /**
     * Cuántos desempeños tiene puestos el grupo en el periodo — el denominador.
     *
     * Cuenta los del reparto del curso (`alumno_id IS NULL`) **y** los de los boletines
     * independientes, porque los dos se pueden marcar. Es el número contra el que se lee
     * `desempenos_impresos`: la distancia entre los dos son las casillas que nadie miró.
     */
    private function cuantosDesempenosTieneElGrupo(int $grupo_id, int $periodo_id): int
    {
        $fila = DB::selectOne(
            'SELECT COUNT(*) AS cuantos
               FROM desempenos d
               INNER JOIN asignaturas a ON a.id = d.asignatura_id AND a.deleted_at IS NULL
              WHERE a.grupo_id = ? AND d.periodo_id = ? AND d.deleted_at IS NULL',
            [$grupo_id, $periodo_id]
        );

        return (int) ($fila->cuantos ?? 0);
    }
}
