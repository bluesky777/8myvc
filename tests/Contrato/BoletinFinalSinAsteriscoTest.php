<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * El boletín final no manda columnas que nadie decidió enseñar.
 *
 * Es la §4 de [25-nivelaciones-en-los-informes.md](../../docs/migracion/25-nivelaciones-en-los-informes.md),
 * y la escribe el carril de informes **antes** que A10 a propósito: el momento del
 * riesgo no es cuando se escriba la impresión del par, es **cuando corra la migración
 * de A3 en ese colegio**, con el código de hoy. Entre las dos cosas hay semanas, y en
 * esa ventana `Informes/BolfinalesController` mandaba al front `nota_original`,
 * `nivelada_at` y `nivelada_por` **sin que nadie lo hubiera decidido**, sólo porque su
 * consulta decía `nf.*`.
 *
 * ## Qué comprueba, y por qué así
 *
 * **Las claves que llegan al cliente, no el SQL.** Un test que buscara el asterisco en
 * el fichero comprobaría la escritura, no el resultado: pasaría con un `SELECT` bien
 * escrito que después mezclara la fila con otra cosa, y se pondría rojo por un
 * comentario que citara el asterisco —que es exactamente lo que llevan esas dos
 * consultas al lado—. Lo que se fija aquí es **la lista exacta de claves de cada
 * definitiva y de cada recuperación** que sale en la respuesta.
 *
 * Así, el día que alguien vuelva al asterisco, este test se pone rojo **con el nombre
 * de la columna nueva en el mensaje**, que es lo que hace falta para decidir si se
 * enseña o no.
 *
 * ## Las recuperaciones se INSERTAN, y es la mitad del test
 *
 * `recuperacion_final` está **vacía en el seed**: los snapshots de forma del boletín
 * final guardan `"recuperaciones": []`, o sea que la consulta de `:359` —la que también
 * tenía asterisco— **no la mira ningún snapshot existente**. Un test que se fiara del
 * seed daría verde con el asterisco puesto. Por eso se inserta una fila dentro de la
 * transacción del test: sin ella esto no comprueba la mitad de lo que dice su nombre.
 *
 * ## Por qué `certificados-persona` no está aquí
 *
 * Su `detailedNotasGrupo` —donde viven sus dos consultas— **no lo alcanza nadie**: la
 * única ruta del controlador es `putIndex`, que devuelve matrículas. Está medido dos
 * veces en [05 §211 y §218](../../docs/migracion/05-codigo-muerto-y-roto.md), y
 * `BolIndependienteRotuloTest` ya lo deja fuera por lo mismo. **Sus columnas se
 * nombraron igual** —el fichero es del carril y la regla es la misma— pero un test que
 * lo diera por vivo estaría mintiendo sobre qué llega a un cliente.
 */
class BoletinFinalSinAsteriscoTest extends CasoDeContrato
{
    /**
     * Las once columnas de `notas_finales` que la consulta nombra. **Tienen que estar
     * todas**: si falta una, alguien recortó la lista y el boletín dejó de imprimir algo.
     *
     * `nota` aparece **una vez**: con el asterisco viajaba dos veces —la cruda y la
     * casteada a DOUBLE— y en PDO gana la última. Es la razón de que nombrar las
     * columnas no cambie ni un campo de la respuesta.
     *
     * @var list<string>
     */
    private const COLUMNAS_DE_UNA_DEFINITIVA = [
        'alumno_id', 'asignatura_id', 'created_at', 'id', 'manual', 'nota',
        'periodo', 'periodo_id', 'recuperada', 'updated_at', 'updated_by',
        // Las tres de la nivelación, abiertas por A10: el boletín final imprime el par
        // (25 §2.1). Entran en la lista de arriba y no en las calculadas porque **van
        // siempre**, con `null` cuando esa definitiva no se ha nivelado — una clave que
        // a veces no viene obliga a distinguir «vacío» de «no vino» (22 §3.1).
        'nota_original', 'nivelada_at', 'nivelada_por',
    ];

    /**
     * Lo que el informe **calcula** y cuelga de cada definitiva, además de las columnas.
     *
     * `notas_perdidas` va aquí y no arriba porque **es condicional** (`:604`, sólo
     * cuando hay perdidas de ese alumno en esa asignatura): exigirla siempre pondría
     * rojo un boletín sin pendientes, que no es lo que este test mira.
     *
     * @var list<string>
     */
    private const CALCULADAS_DE_UNA_DEFINITIVA = [
        'DefMateria', 'cantidad_ausencia', 'cantidad_tardanza', 'notas_perdidas',
    ];

    /** Las ocho columnas de `recuperacion_final` que la consulta nombra. @var list<string> */
    private const COLUMNAS_DE_UNA_RECUPERACION = [
        'alumno_id', 'asignatura_id', 'created_at', 'id', 'nota', 'updated_at', 'updated_by', 'year',
    ];

    /**
     * Lo que el informe añade a cada recuperación: las tres de `materias` que la
     * consulta pide con nombre, y `es_area`, que **es condicional** (`:382`, sólo si el
     * área tiene asignaturas).
     *
     * @var list<string>
     */
    private const CALCULADAS_DE_UNA_RECUPERACION = ['materia', 'alias', 'area_id', 'es_area'];

    /**
     * El grupo del seed, un alumno suyo y el token que ve el grupo entero.
     *
     * @return array{grupo: object, alumno: int, token: string}
     */
    private function escenario(): array
    {
        [$grupo, $token] = $this->grupoYPersonal();

        $alumno = DB::selectOne(
            'SELECT m.alumno_id FROM matriculas m
              WHERE m.grupo_id = ? AND m.deleted_at IS NULL AND m.estado IN ("MATR","ASIS")
              ORDER BY m.alumno_id LIMIT 1',
            [$grupo->id]
        );

        $this->assertNotNull($alumno, 'El grupo del seed no tiene alumnos matriculados.');

        return ['grupo' => $grupo, 'alumno' => (int) $alumno->alumno_id, 'token' => $token];
    }

    /** @return array<string, mixed> el boletín final de ese alumno */
    private function boletinFinalDe(array $e): array
    {
        $r = $this->putJson("/api/bolfinales/detailed-notas-year/{$e['grupo']->id}", [
            'requested_alumnos' => [['alumno_id' => $e['alumno'], 'grupo_id' => (int) $e['grupo']->id]],
        ], ['Authorization' => 'Bearer '.$e['token']]);

        $r->assertStatus(200);

        $cuerpo = $r->json();

        $this->assertNotEmpty($cuerpo[2], 'El boletín final salió sin alumnos.');

        return $cuerpo[2][0];
    }

    /**
     * Todas las listas de definitivas del boletín, vengan colgadas de donde vengan.
     *
     * **Busca en vez de navegar**, como hace `BolIndependienteRotuloTest`: las
     * asignaturas cuelgan de `alumno` y también de `areas[]`, y una ruta fija dejaría
     * fuera la mitad sin que se notara.
     *
     * @return list<array<string, mixed>>
     */
    private function definitivas(mixed $nodo, string $clave = 'definitivas'): array
    {
        $encontradas = [];

        if (is_array($nodo)) {
            foreach ($nodo as $k => $v) {
                if ($k === $clave && is_array($v)) {
                    foreach ($v as $fila) {
                        if (is_array($fila)) {
                            $encontradas[] = $fila;
                        }
                    }

                    continue;
                }

                $encontradas = array_merge($encontradas, $this->definitivas($v, $clave));
            }
        }

        return $encontradas;
    }

    public function test_una_definitiva_del_boletin_final_trae_solo_las_claves_decididas(): void
    {
        $e = $this->escenario();

        $definitivas = $this->definitivas($this->boletinFinalDe($e));

        $this->assertNotEmpty($definitivas,
            'El boletín final salió sin ninguna definitiva: este test no comprobó nada. '
            .'Sin población, «ninguna columna de más» es indistinguible de «no miré».');

        $reales = 0;

        foreach ($definitivas as $definitiva) {
            // **Los periodos ficticios no se miran, y hay que decir por qué.** Cuando a
            // una asignatura le faltan periodos, `:568` empuja al array
            // `{DefMateria:0, cantidad_ausencia:0, cantidad_tardanza:0, periodo_id:-1,
            // manual:0}` para que la tabla del papel salga cuadrada. Esa fila **no viene
            // de `notas_finales`**: no tiene `id` y no puede traer una columna nueva de
            // la tabla. Exigirle las once pondría rojo el test por una fila de relleno,
            // que no es lo que mira.
            if (! array_key_exists('id', $definitiva)) {
                continue;
            }

            $reales++;

            $this->comprobar(
                array_keys($definitiva),
                self::COLUMNAS_DE_UNA_DEFINITIVA,
                self::CALCULADAS_DE_UNA_DEFINITIVA,
                'una definitiva del boletín final',
                'la consulta de `definitivasMateriasXPeriodo` volvió al asterisco sobre `notas_finales`'
            );
        }

        $this->assertGreaterThan(0, $reales,
            'Todas las definitivas del boletín eran periodos de relleno: no se comprobó ninguna fila real.');
    }

    /**
     * El aserto de las dos: **ninguna clave fuera de lo decidido**, y las columnas de
     * la tabla siempre presentes.
     *
     * No es un `assertSame` contra una lista fija, y la diferencia importa: hay campos
     * que el informe cuelga **sólo a veces** —`notas_perdidas`, `es_area`—, así que una
     * lista cerrada se pondría roja por un alumno sin pendientes en vez de por una
     * columna nueva. Lo que este test existe para cazar es lo de más.
     *
     * @param  list<string>  $claves
     * @param  list<string>  $columnas
     * @param  list<string>  $calculadas
     */
    private function comprobar(array $claves, array $columnas, array $calculadas, string $que, string $causa): void
    {
        $deMas = array_values(array_diff($claves, $columnas, $calculadas));

        $this->assertSame([], $deMas,
            "Salen claves que nadie decidió enseñar en {$que}: ".implode(', ', $deMas)."\n"
            ."Si vienen de una migración, {$causa} y esas columnas están llegando al "
            .'front sin decisión. Ver 25 §4.');

        $faltan = array_values(array_diff($columnas, $claves));

        $this->assertSame([], $faltan,
            "Faltan columnas en {$que}: ".implode(', ', $faltan)."\n"
            .'Alguien recortó la lista de la consulta y el informe dejó de traerlas.');
    }

    /**
     * La recuperación del año    /**
     * La recuperación del año, con una fila insertada: en el seed no hay ninguna.
     *
     * La fila se inserta sobre una asignatura **del grupo del alumno**, porque la
     * consulta une `asignaturas` y `materias`: con una asignatura cualquiera el
     * `INNER JOIN` la descartaría y el test volvería a mirar una lista vacía —verde y
     * sin haber comprobado nada, que es contra lo que existe—.
     */
    public function test_una_recuperacion_del_anio_trae_solo_las_claves_decididas(): void
    {
        $e = $this->escenario();

        $asignatura = DB::selectOne(
            'SELECT a.id FROM asignaturas a
              INNER JOIN materias m ON m.id = a.materia_id AND m.deleted_at IS NULL
              WHERE a.grupo_id = ? AND a.deleted_at IS NULL ORDER BY a.id LIMIT 1',
            [$e['grupo']->id]
        );

        $this->assertNotNull($asignatura, 'El grupo del seed no tiene asignaturas con materia.');

        $anio = DB::selectOne('SELECT y.year FROM years y WHERE y.id = ?', [$e['grupo']->year_id]);

        DB::insert(
            'INSERT INTO recuperacion_final (alumno_id, asignatura_id, year, nota, updated_by, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, NOW(), NOW())',
            [$e['alumno'], $asignatura->id, $anio->year, 75, null]
        );

        $recuperaciones = $this->boletinFinalDe($e)['recuperaciones'] ?? [];

        $this->assertNotEmpty($recuperaciones,
            'La recuperación insertada no salió en el boletín: el test no comprobó nada.');

        foreach ($recuperaciones as $recuperacion) {
            $this->comprobar(
                array_keys($recuperacion),
                self::COLUMNAS_DE_UNA_RECUPERACION,
                self::CALCULADAS_DE_UNA_RECUPERACION,
                'una recuperación del año',
                'la consulta de las recuperaciones de `detailedNotasGrupo` volvió al asterisco'
            );
        }
    }
}
