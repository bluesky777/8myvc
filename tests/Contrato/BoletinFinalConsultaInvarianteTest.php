<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * El boletín final pide **los periodos del año** una vez por alumno × asignatura.
 *
 * Es la causa medida del [§176](../../docs/migracion/05-codigo-muerto-y-roto.md):
 * `PUT bolfinales/detailed-notas-year-group/{grupo}` **da 504 tras 60 s** en el
 * grupo 97, y no es el tamaño —el grupo que revienta es más pequeño que el que
 * responde—. Es esta consulta:
 *
 *     // Informes/BolfinalesController::asignaturasPerdidasDeAlumno, dentro del bucle
 *     foreach ($asignaturas as $asignatura) {
 *         $asignatura->periodos = DB::select('SELECT * FROM periodos WHERE year_id=? …', [$year_id]);
 *
 * y el método se llama **una vez por alumno**. La consulta **no depende ni del
 * alumno ni de la asignatura: sólo del año**, así que en un grupo de 38 alumnos ×
 * 15 asignaturas son **570 ejecuciones para el mismo resultado**.
 *
 * Encaja con lo que midió el front por HTTP, y eso es lo que lo separa de una
 * sospecha de lectura: **el 94 con un alumno tarda 3 s y el 105 con 38×15 tarda
 * 63 s**, mientras el 95 y el 97 son indistinguibles teniendo 1,7× de diferencia
 * de hueco de definitivas — porque el multiplicador de fuera no era el hueco.
 *
 * ## Este test cuenta el TRABAJO, no el tiempo
 *
 * A propósito, y es la única forma de que sirva: **un aserto de milisegundos
 * dependería de la máquina** —esta noche la misma suite tardó 2.132 s y 593 s— y
 * **el número de consultas no**. Es el mismo criterio con el que se midió
 * `notas/lote`: de las tres cosas que se pueden contar ahí, la que sobrevive a la
 * carga es la de las consultas.
 *
 * ## Y la trampa de `DB::listen`, que aquí decide el resultado
 *
 * **Un oyente que no se engancha cuenta cero y parece un éxito.** Con una cota del
 * tipo «no más de N», cero es el mejor resultado posible: **el test pasaría
 * exactamente igual si no midiera nada**. Se cazó escribiendo el test de
 * `historiales/sesion` (§186.1) y aquí es peor, porque allí la cota era una
 * igualdad.
 *
 * Por eso el aserto lleva **las dos mitades**: que las de periodos no pasen de la
 * cota, y que el oyente **haya visto consultas de verdad**.
 */
class BoletinFinalConsultaInvarianteTest extends CasoDeContrato
{
    /**
     * La firma de la consulta que no depende del bucle.
     *
     * Casa las **dos** formas —con `numero<=?` y sin él, según venga
     * `periodo_a_calcular`— porque las dos son la misma invariante. Y se elige el
     * `FROM … WHERE` y no un `SELECT *`: `SELECT *` sale en media API.
     */
    private const FIRMA = 'FROM periodos WHERE year_id';

    /**
     * Cuántas veces puede pedir el año sus periodos en una sola llamada.
     *
     * **Tres y no una**, y el margen está medido en vez de elegido:
     * `detailedNotasGrupo` la hace una vez para su propio `$year->periodos`, y de
     * ahí cuelgan las dos ramas del `if ($periodo_a_calcular)`. Una cota de 1
     * fallaría por una consulta que no está en ningún bucle, o sea por algo que no
     * es este problema.
     *
     * Lo que la cota afirma es lo único que importa: **que el número no crezca con
     * los alumnos ni con las asignaturas.**
     */
    private const COTA = 3;

    /**
     * **Las dos ramas del `if`, y decir en cuál se contó es parte del número.**
     *
     * `asignaturasPerdidasDeAlumno` tiene dos caminos según venga
     * `periodo_a_calcular` en el cuerpo, y **son dos consultas distintas** —una
     * lleva `numero<=?` y la otra no—. Un test que sólo mande el cuerpo vacío mide
     * **una rama** y publica el número como si fuera del método.
     *
     * Es la forma general del aviso de `9e` sobre los predicados ambiguos: de los
     * caminos que existen, la suite ejerce los que ejerce, y **un número sin decir
     * en qué rama se contó es el que alguien usa mañana para justificar otra cosa**.
     *
     * @return array<string, array{array<string, mixed>}>
     */
    public static function ramasDelPeriodo(): array
    {
        return [
            'sin periodo_a_calcular' => [[]],
            'con periodo_a_calcular' => [['periodo_a_calcular' => 2]],
        ];
    }

    /**
     * Hoy este test **falla**, y el número de su mensaje es la medida del problema.
     *
     * @param  array<string, mixed>  $cuerpo
     */
    #[DataProvider('ramasDelPeriodo')]
    public function test_los_periodos_del_anio_no_se_piden_una_vez_por_alumno_y_asignatura(array $cuerpo): void
    {
        [$grupo, $token] = $this->grupoYPersonal();

        $forma = DB::selectOne(
            'SELECT
                (SELECT COUNT(DISTINCT m.alumno_id) FROM matriculas m
                  WHERE m.grupo_id = ? AND m.deleted_at IS NULL
                    AND m.estado IN ("MATR","ASIS","PREM")) AS alumnos,
                (SELECT COUNT(*) FROM asignaturas a
                  WHERE a.grupo_id = ? AND a.deleted_at IS NULL) AS asignaturas',
            [$grupo->id, $grupo->id]
        );

        $this->assertGreaterThan(1, (int) $forma->alumnos,
            'El montaje necesita varios alumnos: con uno, el bucle no multiplica y este test no vería nada.');
        $this->assertGreaterThan(1, (int) $forma->asignaturas,
            'El montaje necesita varias asignaturas, por lo mismo.');

        $deLaInvariante = 0;
        $todas = 0;

        DB::listen(function ($consulta) use (&$deLaInvariante, &$todas) {
            $todas++;

            if (str_contains($consulta->sql, self::FIRMA)) {
                $deLaInvariante++;
            }
        });

        $this->putJson('/api/bolfinales/detailed-notas-year-group/'.$grupo->id, $cuerpo,
            ['Authorization' => 'Bearer '.$token])->assertStatus(200);

        // **La mitad que impide el falso verde.** Sin esto, un oyente desenganchado
        // deja los dos contadores en cero y la cota de abajo se cumple sola.
        $this->assertGreaterThan(0, $todas,
            'El oyente de consultas no recibió ninguna, así que la cuenta de abajo no mide nada. '
            .'Es el modo de fallo que hace que este test pase sin comprobar.');

        $this->assertLessThanOrEqual(self::COTA, $deLaInvariante, sprintf(
            "Los periodos del año se pidieron %d veces en una sola llamada.\n"
            ."El grupo tiene %d alumnos y %d asignaturas (%d combinaciones), y esa consulta\n"
            ."no depende de ninguna de las dos: sólo del año. De las %d consultas de la\n"
            ."petición, %d son ésta.\n"
            .'Rama medida: %s.',
            $deLaInvariante, $forma->alumnos, $forma->asignaturas,
            $forma->alumnos * $forma->asignaturas, $todas, $deLaInvariante,
            $cuerpo === [] ? 'sin `periodo_a_calcular`' : 'con `periodo_a_calcular`'
        ));
    }

    /**
     * Y **cada asignatura sigue con su propia cuenta de notas perdidas**.
     *
     * Éste es el test que impide el arreglo ingenuo, y hace falta escribirlo
     * **antes** de arreglar nada: sacar la consulta del bucle y asignar el mismo
     * resultado a todas las asignaturas **comparte los objetos periodo**, y el
     * bucle los **muta** —`$periodo->cantNotasPerdidas = …`—. Con los objetos
     * compartidos, todas las asignaturas acabarían mostrando la cuenta de la
     * **última**, y eso no lo ve ninguna cota de consultas.
     *
     * Se comprueba sobre la respuesta y no sobre el estado: se piden los periodos
     * de cada asignatura de cada alumno y se afirma que **`cantTotal` cuadra con la
     * suma de sus periodos**. Si los objetos se comparten, la suma deja de cuadrar
     * en cuanto dos asignaturas tengan cuentas distintas.
     *
     * > El arreglo correcto es `clone` por asignatura. Este test es lo que lo
     * > obliga.
     */
    public function test_cada_asignatura_perdida_conserva_su_propia_cuenta(): void
    {
        [$grupo, $token] = $this->grupoYPersonal();

        $cuerpo = $this->putJson('/api/bolfinales/detailed-notas-year-group/'.$grupo->id, [],
            ['Authorization' => 'Bearer '.$token])->assertStatus(200)->json();

        $comprobadas = 0;

        foreach ($this->asignaturasPerdidasDe($cuerpo) as $asignatura) {
            $suma = 0;

            foreach ($asignatura['periodos'] ?? [] as $periodo) {
                $suma += (int) ($periodo['cantNotasPerdidas'] ?? 0);
            }

            $this->assertSame((int) $asignatura['cantTotal'], $suma,
                'El `cantTotal` de una asignatura no cuadra con la suma de sus periodos. '
                .'Si esto salta después de sacar la consulta del bucle, es que las asignaturas '
                .'están compartiendo los objetos `periodo` y hace falta un `clone`.');

            $comprobadas++;
        }

        // La población, porque «0 comprobadas» y «todas cuadran» se leen igual.
        $this->assertGreaterThan(0, $comprobadas,
            'Ningún alumno del grupo tiene asignaturas perdidas, así que este test no ha '
            .'comprobado nada. Hace falta un grupo con notas por debajo del mínimo.');
    }

    /**
     * Las asignaturas perdidas que vengan en la respuesta, de todos los alumnos.
     *
     * La respuesta del boletín final es una **tupla** y su forma la fija
     * `BoletinesTest`; aquí no se depende de la posición exacta, que es lo que
     * haría al test frágil por otro motivo: se busca **la forma** —un elemento con
     * `cantTotal` y `periodos` dentro— recorriendo lo que venga.
     *
     * @return list<array<string, mixed>>
     */
    private function asignaturasPerdidasDe(mixed $nodo): array
    {
        if (! is_array($nodo)) {
            return [];
        }

        if (array_key_exists('cantTotal', $nodo) && array_key_exists('periodos', $nodo)) {
            return [$nodo];
        }

        $encontradas = [];

        foreach ($nodo as $hijo) {
            foreach ($this->asignaturasPerdidasDe($hijo) as $a) {
                $encontradas[] = $a;
            }
        }

        return $encontradas;
    }
}
