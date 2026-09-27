<?php

namespace Tests\Contrato;

use App\Services\Portal\CuerpoDelPortal;
use Illuminate\Support\Facades\DB;

/**
 * El cuerpo que sale hacia el portal de la Unión, como especificación
 * (`myvc_ucn/docs/01-diseno-tecnico.md` §2.1.5 y §2.1.6).
 *
 * > **El snapshot NO se regenera para poner esto en verde.** Cambiar el conjunto
 * > de claves es cambiar el contrato con el portal: sube
 * > `CuerpoDelPortal::VERSION`, snapshot nuevo, y el viejo se queda.
 *
 * El snapshot tiene **60 rutas**, no las «61» que dice §2.1.6: el JSON de §2.1.1
 * aplanado da 55 (no 58) y el bloque `salud` añade 5 (`salud`, `salud.meses` y
 * las tres de dentro), no 3. Contado sobre el propio JSON del documento el 27 sep
 * 2026; el conjunto es exactamente el del documento, lo que no cuadraba era la
 * cuenta escrita.
 */
class PortalCuerpoTest extends CasoDeContrato
{
    use SiembraDelPortal;

    private const SNAPSHOT = __DIR__.'/Snapshots/portal-cuerpo-v2.json';

    /**
     * Con las tres listas llenas, que una lista vacía no dice qué claves lleva.
     *
     * @return array<string, mixed>
     */
    private function cuerpoCompleto(): array
    {
        $g = $this->sembrarGrado('PX9', 6, [40, 20]);
        $this->atencion($g['alumnos'][0], '2025-03-10 09:00:00');

        return $this->cuerpo();
    }

    public function test_el_conjunto_de_claves_no_ha_cambiado(): void
    {
        $rutas = $this->rutas($this->cuerpoCompleto());

        $esperadas = json_decode((string) file_get_contents(self::SNAPSHOT), true);

        $this->assertSame($esperadas, $rutas,
            'El conjunto de claves del cuerpo cambió. Eso es cambiar el contrato con el portal: '
            .'sube CuerpoDelPortal::VERSION y crea un snapshot nuevo; NO regeneres éste.');
        $this->assertCount(60, $rutas);
    }

    public function test_la_version_es_la_2(): void
    {
        $cuerpo = $this->cuerpoCompleto();

        $this->assertSame(2, $cuerpo['sobre']['version_emisor']);
        $this->assertSame(CuerpoDelPortal::VERSION, $cuerpo['sobre']['version_emisor']);
    }

    /**
     * Sólo tres listas declaradas, y dentro de ellas sólo escalares. Es la que
     * impide el «y de paso el detalle, que es un array pequeño».
     */
    public function test_todo_valor_es_escalar_o_lista_declarada(): void
    {
        $listas = ['escala.niveles', 'grados', 'salud.meses'];
        $secciones = ['sobre', 'colegio', 'matricula', 'academico', 'convivencia', 'escala', 'despliegue', 'salud'];

        $revisar = function (mixed $valor, string $ruta, bool $dentroDeLista) use (&$revisar, $listas, $secciones): void {
            if (! is_array($valor)) {
                $this->assertTrue($valor === null || is_scalar($valor), "{$ruta} no es escalar.");

                return;
            }
            if (array_is_list($valor)) {
                $this->assertContains($ruta, $listas, "{$ruta} es una lista y no está declarada.");
                foreach ($valor as $fila) {
                    $this->assertIsArray($fila);
                    foreach ($fila as $k => $v) {
                        $this->assertFalse(is_array($v), "{$ruta}[].{$k} no es escalar.");
                    }
                }

                return;
            }
            $this->assertTrue($ruta === '' || in_array($ruta, $secciones, true), "{$ruta} es un objeto donde se espera un escalar.");
            foreach ($valor as $k => $v) {
                $revisar($v, ltrim($ruta.'.'.$k, '.'), $dentroDeLista);
            }
        };

        $revisar($this->cuerpoCompleto(), '', false);
    }

    /**
     * §2.1.2: en un grado de tres, el promedio ES la nota de uno de tres. Visto en
     * rojo el 27 sep 2026 quitando la condición `$pequeno` de `grados()`.
     */
    public function test_los_grados_pequenos_no_llevan_derivadas(): void
    {
        $this->sembrarGrado('PX3', 3, [45, 10, 12, 14]);

        $g = $this->grado($this->cuerpo(), 'PX3');

        $this->assertSame(3, $g['alumnos_activos'], 'El recuento de cabezas sí va.');
        $this->assertSame(2, $g['alumnos_f']);
        $this->assertSame(1, $g['alumnos_m']);
        $this->assertNull($g['promedio_del_grado']);
        $this->assertNull($g['alumnos_sin_perdidas']);
        $this->assertNull($g['alumnos_con_1_2_perdidas']);
        $this->assertNull($g['alumnos_con_3_o_mas_perdidas']);
    }

    /**
     * Un grado de seis con UN solo alumno calificado: el «promedio del grado» sería
     * su nota. La regla de §2.1.2 cuenta activos y aquí no bastaría.
     */
    public function test_un_grado_grande_con_un_solo_calificado_no_manda_su_nota(): void
    {
        $g = $this->sembrarGrado('PX6', 6, [33]);
        // Cinco de los seis, todavía sin calificar.
        DB::table('notas_finales')->whereIn('alumno_id', array_slice($g['alumnos'], 1))->delete();

        $grado = $this->grado($this->cuerpo(), 'PX6');

        $this->assertSame(6, $grado['alumnos_activos']);
        $this->assertNull($grado['promedio_del_grado']);
        $this->assertSame(6, $grado['alumnos_sin_perdidas'], 'Los cubos cuentan cabezas y sí van.');
    }

    public function test_los_cubos_y_las_perdidas_cuadran_con_los_activos(): void
    {
        // Mínima 30: 20 pierde, 40 no. Tres pérdidas en tres asignaturas.
        $this->sembrarGrado('PX7', 5, [20, 25, 29, 40]);

        $cuerpo = $this->cuerpo();
        $g = $this->grado($cuerpo, 'PX7');

        $this->assertSame(5, $g['alumnos_con_3_o_mas_perdidas']);
        $this->assertSame(0, $g['alumnos_sin_perdidas']);
        $this->assertEqualsWithDelta(28.5, $g['promedio_del_grado'], 0.001);

        $a = $cuerpo['academico'];
        $this->assertSame($cuerpo['matricula']['alumnos_activos'],
            $a['alumnos_sin_perdidas'] + $a['alumnos_con_1_2_perdidas'] + $a['alumnos_con_3_o_mas_perdidas']);
    }

    /**
     * El remitente es el DANE de hoy aunque el año mandado lleve otro: en
     * `micolev1_la_hermosa` 2019 tiene el DANE de otro colegio y la carga inicial
     * salía firmada como él (401 del portal, 27 sep 2026).
     */
    public function test_el_codigo_dane_es_el_del_anio_actual_y_no_el_del_anio_mandado(): void
    {
        // El año mandado (2025) deja de ser actual y lleva el DANE de otro; el actual
        // pasa a ser 2024 con el suyo.
        DB::table('years')->update(['actual' => 0]);
        DB::table('years')->where('year', self::ANIO)->update(['codigo_dane' => '999999999999']);
        DB::table('years')->where('year', self::ANIO - 1)->update(['actual' => 1, 'codigo_dane' => '481794005085']);
        $hoy = (object) ['codigo_dane' => '481794005085'];

        $this->assertSame((string) $hoy->codigo_dane, $this->cuerpo()['sobre']['codigo_dane']);
    }

    /**
     * El receptor declara `ausencias_del_periodo` no anulable: un año sin periodo
     * actual (lo normal en uno pasado) manda el último, no null.
     */
    public function test_sin_periodo_actual_las_ausencias_no_van_a_null(): void
    {
        DB::table('periodos')->where('year_id', $this->yearId())->update(['actual' => 0]);

        $cuerpo = $this->cuerpo();

        $this->assertNull($cuerpo['colegio']['periodo_actual']);
        $this->assertIsInt($cuerpo['convivencia']['ausencias_del_periodo']);
    }

    /**
     * El periodo en curso del año en curso no cuenta: sus casillas sin calificar
     * están a 0 y harían perder a medio colegio (medido en `caz_zaragoza`).
     */
    public function test_el_periodo_en_curso_no_cuenta_para_perdidas_ni_promedio(): void
    {
        $g = $this->sembrarGrado('PX8', 5, [40, 40, 40]);
        $enCurso = DB::selectOne('SELECT id, numero FROM periodos WHERE year_id = ? AND actual = 1 AND deleted_at IS NULL', [$this->yearId()]);
        $this->assertNotNull($enCurso, 'La base de tests tiene que traer un periodo actual en 2025.');
        $this->assertSame(1, (int) DB::selectOne('SELECT actual FROM years WHERE id = ?', [$this->yearId()])->actual);

        foreach (DB::select('SELECT alumno_id, asignatura_id FROM notas_finales WHERE alumno_id IN ('.implode(',', $g['alumnos']).')') as $nf) {
            DB::table('notas_finales')->insert([
                'alumno_id' => $nf->alumno_id, 'asignatura_id' => $nf->asignatura_id,
                'periodo_id' => $enCurso->id, 'periodo' => $enCurso->numero, 'nota' => 0,
            ]);
        }

        $grado = $this->grado($this->cuerpo(), 'PX8');

        $this->assertSame(5, $grado['alumnos_sin_perdidas']);
        $this->assertEqualsWithDelta(40.0, $grado['promedio_del_grado'], 0.001);
    }

    /**
     * Aplana a rutas con punto; las listas, con `[]`. Ordenadas y sin repetir.
     *
     * @param  array<string, mixed>  $cuerpo
     * @return list<string>
     */
    private function rutas(array $cuerpo): array
    {
        $rutas = [];
        $andar = function (mixed $v, string $ruta) use (&$andar, &$rutas): void {
            if (! is_array($v)) {
                return;
            }
            if (array_is_list($v)) {
                foreach ($v as $fila) {
                    $andar($fila, $ruta.'[]');
                }

                return;
            }
            foreach ($v as $k => $x) {
                $r = $ruta === '' ? (string) $k : $ruta.'.'.$k;
                $rutas[$r] = true;
                $andar($x, $r);
            }
        };
        $andar($cuerpo, '');

        $lista = array_keys($rutas);
        sort($lista, SORT_STRING);

        return $lista;
    }
}
