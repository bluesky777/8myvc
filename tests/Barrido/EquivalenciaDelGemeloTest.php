<?php

namespace Tests\Barrido;

use App\Http\Controllers\BolfinalesController;
use App\User;
use Illuminate\Http\Request as PeticionHttp;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\Attributes\Group;
use Tests\Contrato\CasoDeContrato;

/**
 * Los dos `GROUP BY` del gemelo dan **exactamente** lo que daban las 2.960
 * consultas de una en una.
 *
 *     docker exec -w /app/.worktrees/79 -e DB_TEST_DATABASE=simonbolivar_testing_79 \
 *         8myvc-app-1 php artisan test --group=barrido --filter=EquivalenciaDelGemeloTest
 *
 * ## Por qué esto no lo puede comprobar una cota de consultas
 *
 * Porque el fallo que hay que impedir **tiene el mismo número de consultas y otro
 * resultado**. Son dos, y las dos salen solas al escribir este arreglo:
 *
 *  - **copiar el SQL del hermano.** `Informes/BolfinalesController` agrega con
 *    `m.estado = "MATR"` a secas; el original de **este** fichero filtra
 *    `(m.estado="MATR" or m.estado="ASIS")`. El SQL del hermano es correcto **en el
 *    hermano**; aquí borraría del recuento a los alumnos en `ASIS`. Y
 *    `Grupo::alumnos()` trae `MATR`, `ASIS` y `PREM`, así que el grupo los tiene.
 *  - **fundir los dos mapas en uno.** Las dos consultas cuentan «notas perdidas» y
 *    **no son la misma**: una une con `matriculas` y no mira `deleted_at`; la otra
 *    filtra `deleted_at` en subunidades y unidades y no une con `matriculas`.
 *
 * Las dos dejarían la cota de consultas en verde.
 *
 * ## Cómo se comprueba: recalculando con el SQL viejo, no con el nuevo
 *
 * El SQL de referencia de este fichero está **copiado literalmente del controlador
 * antes del arreglo** (commit anterior a esta rama). Compararlo contra una segunda
 * versión del `GROUP BY` sería tautológico: los dos serían la misma idea escrita
 * dos veces, y una idea equivocada dos veces da verde.
 *
 * Por eso vive en `barrido` y no en la suite: **ejecuta el boletín final entero y
 * luego lo recalcula a mano**, 2.960 consultas de una en una. Es caro a propósito.
 *
 * ## Y el conjunto de alumnos, que es la lectura que se pierde
 *
 * Además de comparar valor a valor, compara **qué alumnos acaban con
 * `asignaturas_perdidas` en el resultado**. Un mapa con un cero de más no cambia un
 * número: **saca a un alumno entero del informe**, porque las asignaturas sin
 * ningún periodo perdido se borran con `unset` y el alumno se queda sin la
 * propiedad. Ése es el daño que se ve mirando el resultado y no el estado.
 */
#[Group('barrido')]
class EquivalenciaDelGemeloTest extends CasoDeContrato
{
    /**
     * El SQL de `asignaturasPerdidasDeAlumno`, **tal como estaba antes del
     * arreglo**. No se toca para «mejorarlo»: su valor es ser el viejo.
     */
    private const SQL_VIEJO_PERDIDAS =
        'SELECT distinct n.nota, n.id as nota_id, n.alumno_id,  s.id as subunidad_id, s.definicion, u.id as unidad_id, u.periodo_id
            from notas n, subunidades s, unidades u, asignaturas a, matriculas m
            where n.subunidad_id=s.id and s.unidad_id=u.id and u.periodo_id=:periodo_id
            and u.asignatura_id=a.id and m.alumno_id=n.alumno_id and m.deleted_at is null and (m.estado="MATR" or m.estado="ASIS")
            and a.id=:asignatura_id and n.alumno_id=:alumno_id and n.nota < :nota_minima;';

    /**
     * El SQL de `definitivasMateriasXPeriodo`, también el viejo, literal.
     */
    private const SQL_VIEJO_DEFINITIVAS =
        'SELECT COUNT(n.id) as notas_perdidas
            from notas n
            inner join subunidades s on s.id=n.subunidad_id and s.deleted_at is null
            inner join unidades u on u.id=s.unidad_id and u.periodo_id=:periodo_id and u.asignatura_id=:asignatura_id and u.deleted_at is null
            where n.nota < :nota_minima and n.alumno_id=:alumno_id;';

    /**
     * Ordena los tres niveles del mapa por clave, **y sin esto el test da rojo con
     * los datos bien**.
     *
     * `assertSame` sobre arrays es `===`, y para arrays `===` exige **las mismas
     * claves, los mismos valores Y EL MISMO ORDEN**. Los dos mapas se construyen
     * recorriendo cosas distintas —el observado sigue el orden de
     * `Grupo::detailed_materias()`, que ordena por área, materia y asignatura; el
     * esperado recorre las asignaturas por `id` y los periodos por `numero`—, así
     * que sin normalizar el orden fallaría **comparando exactamente los mismos
     * números**.
     *
     * Es la mitad del instrumento que no se ve hasta que se corre: un rojo aquí se
     * habría leído como «el arreglo cambió los números», que es la conclusión
     * contraria a la verdadera.
     *
     * @param  array<int, array<int, array<int, int>>>  $mapa
     * @return array<int, array<int, array<int, int>>>
     */
    private static function ordenado(array $mapa): array
    {
        ksort($mapa);

        foreach ($mapa as $alumno => $porAsignatura) {
            ksort($porAsignatura);

            foreach ($porAsignatura as $asignatura => $porPeriodo) {
                ksort($porPeriodo);
                $porAsignatura[$asignatura] = $porPeriodo;
            }

            $mapa[$alumno] = $porAsignatura;
        }

        return $mapa;
    }

    public function test_los_dos_group_by_dan_lo_mismo_que_las_consultas_de_una_en_una(): void
    {
        $grupo = $this->grupoConAlumnos();
        $token = $this->tokenDelPersonalDe((int) $grupo->year_id);

        $peticion = PeticionHttp::create('/api/certificados-estudio/certificado-grupo/'.$grupo->id, 'GET');
        $peticion->headers->set('Authorization', 'Bearer '.$token);
        $this->app->instance('request', $peticion);
        Facade::clearResolvedInstance('request');

        $user = User::fromToken();
        $this->assertNotNull($user, 'Sin contexto de usuario esto no mide el camino real.');

        $consultasDelBoletin = 0;
        DB::listen(function () use (&$consultasDelBoletin) {
            $consultasDelBoletin++;
        });

        $desde = hrtime(true);
        [, , $alumnos] = (new BolfinalesController)->detailedNotasGrupo($grupo->id, $user);
        $ms = (hrtime(true) - $desde) / 1e6;

        // **Se congela AQUÍ, y no se lee al final.** El contador entra en la clausura
        // **por referencia**, y `DB::listen` no se puede quitar —no hay `unlisten`—:
        // el recálculo a mano de más abajo son 2.960 consultas más que siguen cayendo
        // en la misma variable. Leerla en el informe daba **3.412 «consultas del
        // boletín»** cuando el boletín hacía 452 y el resto era el propio test
        // midiéndose a sí mismo.
        //
        // El número era correcto; lo que estaba mal era **sobre qué tramo se contó**,
        // y eso no se ve mirando el resultado, porque 3.412 es perfectamente creíble
        // —se parece muchísimo a las 3.820 de antes del arreglo, que es justo lo que
        // lo hacía pasar por bueno—.
        $delBoletin = $consultasDelBoletin;

        $this->assertGreaterThan(0, $delBoletin,
            'El oyente no contó ninguna consulta: el informe de abajo no mide nada.');

        // ---------------------------------------------------------- lo observado
        $observadoPerdidas = [];
        $observadoDefinitivas = [];
        $conPerdidas = [];

        foreach ($alumnos as $alumno) {
            $alu = (int) $alumno->alumno_id;

            foreach ($alumno->asignaturas as $asignatura) {
                foreach ($asignatura->definitivas as $definitiva) {
                    // Los periodos ficticios de relleno llevan -1 y no salen de la base.
                    if ((int) $definitiva->periodo_id === -1) {
                        continue;
                    }

                    $observadoDefinitivas[$alu][(int) $asignatura->asignatura_id][(int) $definitiva->periodo_id]
                        = (int) $definitiva->notas_perdidas;
                }
            }

            if (! isset($alumno->asignaturas_perdidas)) {
                continue;
            }

            $conPerdidas[] = $alu;

            foreach ($alumno->asignaturas_perdidas as $asignatura) {
                foreach ($asignatura->periodos as $periodo) {
                    $observadoPerdidas[$alu][(int) $asignatura->asignatura_id][(int) $periodo->id]
                        = (int) $periodo->cantNotasPerdidas;
                }
            }
        }

        // ------------------------------------------- lo esperado, con el SQL viejo
        $asignaturasDelGrupo = DB::select(
            'SELECT id AS asignatura_id FROM asignaturas WHERE grupo_id = ? AND deleted_at IS NULL ORDER BY id',
            [$grupo->id]
        );

        $periodosDelAnio = DB::select(
            'SELECT id FROM periodos WHERE year_id = ? AND deleted_at IS NULL ORDER BY numero',
            [$user->year_id]
        );

        $esperadoPerdidas = [];
        $esperadoDefinitivas = [];
        $consultasAMano = 0;
        $conPerdidasEsperado = [];

        foreach ($alumnos as $alumno) {
            $alu = (int) $alumno->alumno_id;

            foreach ($asignaturasDelGrupo as $asignatura) {
                $asig = (int) $asignatura->asignatura_id;

                foreach ($periodosDelAnio as $periodo) {
                    $per = (int) $periodo->id;

                    $filas = DB::select(self::SQL_VIEJO_PERDIDAS, [
                        ':periodo_id' => $per,
                        ':asignatura_id' => $asig,
                        ':alumno_id' => $alu,
                        ':nota_minima' => User::$nota_minima_aceptada,
                    ]);
                    $consultasAMano++;

                    if (count($filas) > 0) {
                        $esperadoPerdidas[$alu][$asig][$per] = count($filas);
                        $conPerdidasEsperado[$alu] = true;
                    }

                    $def = DB::select(self::SQL_VIEJO_DEFINITIVAS, [
                        ':periodo_id' => $per,
                        ':asignatura_id' => $asig,
                        ':nota_minima' => User::$nota_minima_aceptada,
                        ':alumno_id' => $alu,
                    ]);
                    $consultasAMano++;

                    // Sólo se comparan los pares que el informe llegó a calcular:
                    // `definitivasMateriasXPeriodo` no inventa filas para periodos en
                    // los que la asignatura no tiene definitiva.
                    if (isset($observadoDefinitivas[$alu][$asig][$per])) {
                        $esperadoDefinitivas[$alu][$asig][$per] = (int) $def[0]->notas_perdidas;
                    }
                }
            }
        }

        $this->informe($grupo, $user, $alumnos, $delBoletin, $consultasAMano, $ms,
            $esperadoPerdidas, $conPerdidas);

        // ------------------------------------------------------------- los asertos
        $this->assertNotSame([], $esperadoPerdidas,
            'El SQL viejo no encontró ni una nota perdida en todo el grupo: la comparación de '
            .'abajo sería «vacío contra vacío», que pasa siempre. Este seed no sirve para este test.');

        $this->assertSame(self::ordenado($esperadoPerdidas), self::ordenado($observadoPerdidas),
            'El mapa de `perdidasPorAlumnoDelGrupo()` NO reproduce lo que daban las consultas '
            .'de una en una. Los dos sospechosos, por orden: que se haya copiado el '
            .'`m.estado = "MATR"` del hermano —que borra a los alumnos en ASIS— o que se hayan '
            .'fundido los dos mapas en uno.');

        $this->assertSame(self::ordenado($esperadoDefinitivas), self::ordenado($observadoDefinitivas),
            'El mapa de `perdidasPorDefinitivaDelGrupo()` NO reproduce lo que daba el `COUNT()` '
            .'por definitiva. Ojo con los `deleted_at` de subunidades y unidades, que esta '
            .'consulta sí filtra y la otra no.');

        sort($conPerdidas);
        $esperadoConPerdidas = array_keys($conPerdidasEsperado);
        sort($esperadoConPerdidas);

        $this->assertSame($esperadoConPerdidas, $conPerdidas,
            'El CONJUNTO DE ALUMNOS que acaba con `asignaturas_perdidas` en el informe cambió. '
            .'Esto no es un número que se mueve: es un alumno que entra o sale del informe, '
            .'porque las asignaturas sin periodos perdidos se borran con `unset` y el alumno '
            .'se queda sin la propiedad.');
    }

    /**
     * @param  array<int, object>  $alumnos
     * @param  array<int, array<int, array<int, int>>>  $esperadoPerdidas
     * @param  array<int, int>  $conPerdidas
     */
    private function informe(object $grupo, object $user, $alumnos, int $delBoletin,
        int $aMano, float $ms, array $esperadoPerdidas, array $conPerdidas): void
    {
        $forma = DB::selectOne(
            'SELECT (SELECT COUNT(*) FROM asignaturas a WHERE a.grupo_id = ? AND a.deleted_at IS NULL) AS asignaturas,
                    (SELECT COUNT(*) FROM periodos p WHERE p.year_id = ? AND p.deleted_at IS NULL) AS periodos',
            [$grupo->id, $user->year_id]
        );

        $pares = 0;
        foreach ($esperadoPerdidas as $porAsignatura) {
            foreach ($porAsignatura as $porPeriodo) {
                $pares += count($porPeriodo);
            }
        }

        fwrite(STDERR, sprintf(
            "\n  Equivalencia del gemelo de la raíz — el resultado, no la cota\n".
            "  %s\n".
            "  grupo %d · %d alumnos × %d asignaturas × %d periodos\n".
            "  base `%s`\n".
            "  %6d  consultas del boletín entero      %.0f ms\n".
            "  %6d  consultas del recálculo a mano (el SQL viejo, de una en una)\n".
            "  %6d  pares (alumno, asignatura, periodo) con notas perdidas\n".
            "  %6d  alumnos con `asignaturas_perdidas` en el informe\n".
            "  %s\n\n",
            str_repeat('-', 70),
            $grupo->id, count($alumnos), $forma->asignaturas, $forma->periodos,
            DB::connection()->getDatabaseName(),
            $delBoletin, $ms, $aMano, $pares, count($conPerdidas),
            str_repeat('-', 70)
        ));
    }
}
