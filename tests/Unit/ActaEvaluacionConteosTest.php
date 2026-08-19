<?php

namespace Tests\Unit;

use App\Http\Controllers\Informes\ActasEvaluacionController;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Conteos del acta de evaluación y promoción.
 *
 * Cada prueba de aquí corresponde a un error concreto que el informe tenía y que producía números
 * que no cuadraban en un documento que se firma. Los nombres dicen qué se rompía.
 *
 * No arrancan Laravel ni tocan la base de datos: los conteos son funciones puras sobre filas de
 * matrícula, y probarlos así es lo que permite fijar los casos borde (fecha nula, sexo sin dato,
 * 'Automático') sin montar un año lectivo entero.
 */
class ActaEvaluacionConteosTest extends TestCase
{
    private $controlador;

    protected function setUp(): void
    {
        parent::setUp();
        $this->controlador = new ActasEvaluacionController;
    }

    private function invocar($metodo, array $args = [])
    {
        $m = new ReflectionMethod(ActasEvaluacionController::class, $metodo);
        $m->setAccessible(true);

        return $m->invokeArgs($this->controlador, $args);
    }

    /**
     * Construye una fila de matrícula ya normalizada, como la deja prepararMatricula().
     */
    private function matricula(array $campos = [])
    {
        static $id = 0;
        $id++;

        $m = (object) array_merge([
            'matricula_id' => $id,
            'alumno_id' => $id,
            'grupo_id' => 1,
            'estado' => 'MATR',
            'fecha_matricula' => '2026-02-01',
            'fecha_retiro' => null,
            'fecha_nac' => '2010-05-05',
            'razon_retiro' => '',
            'nuevo' => 0,
            'repitente' => 0,
            'promovido' => 'Promovido (calculado)',
            'promedio' => 80,
            'cant_asign_perdidas' => 0,
            'cant_areas_perdidas' => 0,
            'sexo' => 'M',
            'nombres' => 'Nombre',
            'apellidos' => 'Apellido',
        ], $campos);

        $this->invocar('prepararMatricula', [$m]);

        return $m;
    }

    // ---------------------------------------------------------------- clasificación de promoción

    /**
     * 'Automático' es el DEFAULT de matriculas.promovido, o sea el valor de todo alumno cuya
     * promoción nunca se calculó. La versión anterior hacía strpos($promovido, 'No promovido') y
     * mandaba todo lo que no coincidiera a "promovido", así que 'Automático' se imprimía como
     * promovido -- y con cant_asign_perdidas en su DEFAULT 0, engordaba el balde "promovido con
     * cero asignaturas pendientes".
     */
    public function test_automatico_no_cuenta_como_promovido(): void
    {
        $this->assertSame('SIN_DEFINIR', $this->invocar('clasificarPromocion', ['Automático']));
        $this->assertSame('SIN_DEFINIR', $this->invocar('clasificarPromocion', ['']));
        $this->assertSame('SIN_DEFINIR', $this->invocar('clasificarPromocion', [null]));
    }

    /**
     * 'Promoción pendiente' es un tercer estado real: el boletín final y el certificado de estudio
     * lo imprimen como "aprobó con áreas pendientes". El acta lo contaba como promovido, así que
     * dos documentos oficiales del mismo sistema se contradecían sobre el mismo alumno.
     */
    public function test_promocion_pendiente_es_su_propio_estado(): void
    {
        $this->assertSame('PENDIENTE', $this->invocar('clasificarPromocion', ['Promoción pendiente (calculado)']));
        $this->assertSame('PENDIENTE', $this->invocar('clasificarPromocion', ['Promoción pendiente (manual)']));
        // Sin tilde y en mayúsculas, por si alguien lo escribió a mano.
        $this->assertSame('PENDIENTE', $this->invocar('clasificarPromocion', ['PROMOCION PENDIENTE']));
    }

    /**
     * 'no promovido' contiene 'promovido': si se compara en el orden equivocado, todo reprobado
     * sale promovido. Y la comparación era sensible a mayúsculas.
     */
    public function test_no_promovido_se_reconoce_antes_que_promovido(): void
    {
        $this->assertSame('NO_PROMOVIDO', $this->invocar('clasificarPromocion', ['No promovido (calculado)']));
        $this->assertSame('NO_PROMOVIDO', $this->invocar('clasificarPromocion', ['No promovido (manual)']));
        $this->assertSame('NO_PROMOVIDO', $this->invocar('clasificarPromocion', ['NO PROMOVIDO']));
        $this->assertSame('PROMOVIDO', $this->invocar('clasificarPromocion', ['Promovido (calculado)']));
    }

    // ------------------------------------------------------------------------------------ sexo

    /**
     * Antes, todo `else` de `if (sexo == 'M')` contaba como femenino: sexo nulo, vacío o en
     * minúscula engordaba la columna de mujeres.
     */
    public function test_sexo_sin_dato_no_cuenta_como_femenino(): void
    {
        $this->assertSame('M', $this->invocar('sexoNormalizado', ['M']));
        $this->assertSame('M', $this->invocar('sexoNormalizado', ['m']));
        $this->assertSame('F', $this->invocar('sexoNormalizado', ['F']));
        $this->assertSame('SD', $this->invocar('sexoNormalizado', [null]));
        $this->assertSame('SD', $this->invocar('sexoNormalizado', ['']));
        $this->assertSame('SD', $this->invocar('sexoNormalizado', ['X']));
    }

    // -------------------------------------------------------------------------------- movimiento

    /**
     * El error central: "Total estudiantes que iniciaron año escolar" filtraba
     * estado IN (PREM,MATR,ASIS), así que quien inició el año y después se retiró NO contaba como
     * que lo inició. El número quedaba subestimado exactamente en la cantidad de retirados.
     */
    public function test_quien_inicio_el_anio_y_se_retiro_cuenta_como_que_inicio(): void
    {
        $alumnos = [
            $this->matricula(['fecha_matricula' => '2026-02-01', 'estado' => 'MATR']),
            $this->matricula(['fecha_matricula' => '2026-02-01', 'estado' => 'RETI', 'fecha_retiro' => '2026-08-10']),
            $this->matricula(['fecha_matricula' => '2026-02-01', 'estado' => 'DESE', 'fecha_retiro' => '2026-09-15']),
        ];

        $r = $this->invocar('resumenMovimiento', [$alumnos, '2026-04-15']);

        $this->assertSame(3, $r->iniciaron->total, 'los tres iniciaron el año, se hayan ido o no');
        $this->assertSame(1, $r->retirados->total);
        $this->assertSame(1, $r->desertores->total);
        $this->assertSame(1, $r->terminaron->total);
    }

    /**
     * "Estudiantes que terminaron el año escolar" no tenía filtro de fecha y usaba el mismo filtro
     * de estado que "iniciaron"/"ingresaron", así que era por construcción iniciaron+ingresaron:
     * incapaz de mostrar un solo caso de deserción. El cuadre ahora se cumple siempre.
     */
    public function test_el_cuadre_se_cumple_por_construccion(): void
    {
        $alumnos = [
            $this->matricula(['fecha_matricula' => '2026-02-01']),
            $this->matricula(['fecha_matricula' => '2026-02-01', 'estado' => 'RETI', 'fecha_retiro' => '2026-08-10']),
            $this->matricula(['fecha_matricula' => '2026-06-01']),
            $this->matricula(['fecha_matricula' => '2026-06-01', 'estado' => 'DESE', 'fecha_retiro' => '2026-10-01']),
            $this->matricula(['fecha_matricula' => '2026-02-01', 'estado' => 'ASIS']),
        ];

        $r = $this->invocar('resumenMovimiento', [$alumnos, '2026-04-15']);

        $this->assertTrue($r->cuadra, 'iniciaron + ingresaron - retirados - desertores = terminaron');
        $this->assertSame(0, $r->descuadre);
        $this->assertSame(3, $r->iniciaron->total);
        $this->assertSame(2, $r->ingresaron->total);
        $this->assertSame(3, $r->terminaron->total, 'ASIS también termina el año');
    }

    /**
     * fecha_matricula es nullable. "Iniciaron" filtraba <= corte y "ingresaron" > corte, y NULL
     * falla ambas -- pero "terminaron" no filtraba por fecha. Esos alumnos desaparecían de dos
     * filas y aparecían en la tercera, sin dejar rastro. Ahora tienen su propio contador.
     */
    public function test_matricula_sin_fecha_no_desaparece(): void
    {
        $alumnos = [
            $this->matricula(['fecha_matricula' => null]),
            $this->matricula(['fecha_matricula' => '']),
            $this->matricula(['fecha_matricula' => '2026-02-01']),
        ];

        $r = $this->invocar('resumenMovimiento', [$alumnos, '2026-04-15']);

        $this->assertSame(2, $r->sin_fecha_matricula->total);
        $this->assertSame(1, $r->iniciaron->total);
        $this->assertSame(0, $r->ingresaron->total);
        $this->assertTrue($r->cuadra, 'los sin fecha entran al cuadre en vez de romperlo');
    }

    /**
     * Los contadores de sexo salían de un recorrido distinto al del total: cant_terminaron_m/_f se
     * contaban sobre la consulta sin ASIS y cant_terminaron sobre la que sí lo incluía, así que
     * M + F no daba el total y los porcentajes no sumaban 100%.
     */
    public function test_hombres_mas_mujeres_mas_sin_dato_da_el_total(): void
    {
        $alumnos = [
            $this->matricula(['sexo' => 'M']),
            $this->matricula(['sexo' => 'F']),
            $this->matricula(['sexo' => null]),
            $this->matricula(['sexo' => 'f']),
        ];

        $r = $this->invocar('resumenMovimiento', [$alumnos, '2026-04-15']);
        $c = $r->terminaron;

        $this->assertSame(4, $c->total);
        $this->assertSame($c->total, $c->m + $c->f + $c->sd);
        $this->assertSame(1, $c->m);
        $this->assertSame(2, $c->f);
        $this->assertSame(1, $c->sd);
    }

    /**
     * Cada contador lleva los matricula_id que lo componen, para que la pantalla abra exactamente
     * las filas que produjeron el número. Un número que no se puede reconciliar contando nombres
     * está mal.
     */
    public function test_cada_contador_lleva_los_ids_que_lo_componen(): void
    {
        $a = $this->matricula(['estado' => 'RETI', 'fecha_retiro' => '2026-08-10']);
        $b = $this->matricula(['estado' => 'MATR']);

        $r = $this->invocar('resumenMovimiento', [[$a, $b], '2026-04-15']);

        $this->assertSame([$a->matricula_id], $r->retirados->ids);
        $this->assertSame([$b->matricula_id], $r->terminaron->ids);
        $this->assertCount($r->retirados->total, $r->retirados->ids);
    }

    /**
     * periodos.fecha_fin es nullable y hay colegios con el calendario sin llenar -- la base local
     * de pruebas es uno: sus cuatro periodos tienen fecha_inicio y fecha_fin en NULL.
     *
     * Sin corte, "inició el año" e "ingresó después" no se pueden distinguir. La versión anterior
     * comparaba contra NULL en SQL, que da NULL, y publicaba "iniciaron: 0" sin explicación. Meter
     * a todos en "ingresaron durante el año" tampoco vale: es afirmar algo que los datos no dicen.
     */
    public function test_sin_calendario_de_periodos_no_se_inventa_la_clasificacion(): void
    {
        $alumnos = [
            $this->matricula(['fecha_matricula' => '2026-01-15']),
            $this->matricula(['fecha_matricula' => '2026-06-20']),
            $this->matricula(['fecha_matricula' => null]),
        ];

        $r = $this->invocar('resumenMovimiento', [$alumnos, null, false]);

        $this->assertSame(0, $r->iniciaron->total, 'sin corte no se afirma quién inició');
        $this->assertSame(0, $r->ingresaron->total, 'ni quién ingresó después');
        $this->assertSame(2, $r->sin_clasificar->total);
        $this->assertSame(1, $r->sin_fecha_matricula->total);
        $this->assertTrue($r->cuadra, 'y el cuadre se sigue cumpliendo');
    }

    public function test_con_calendario_sí_se_clasifica(): void
    {
        $alumnos = [
            $this->matricula(['fecha_matricula' => '2026-02-01']),
            $this->matricula(['fecha_matricula' => '2026-06-20']),
        ];

        $r = $this->invocar('resumenMovimiento', [$alumnos, '2026-04-15', true]);

        $this->assertSame(1, $r->iniciaron->total);
        $this->assertSame(1, $r->ingresaron->total);
        $this->assertSame(0, $r->sin_clasificar->total);
    }

    // --------------------------------------------------------------------------------- promoción

    /**
     * Los promovidos/no promovidos se contaban sobre TODOS los alumnos, retirados y desertores
     * incluidos. Un retirado no se promueve ni se reprueba, y contarlo era lo que hacía que
     * "Total PROMOVIDOS" superara a "terminaron".
     */
    public function test_los_retirados_no_entran_en_la_promocion(): void
    {
        $alumnos = [
            $this->matricula(['estado' => 'MATR', 'promovido' => 'Promovido (calculado)']),
            $this->matricula(['estado' => 'RETI', 'fecha_retiro' => '2026-08-10', 'promovido' => 'Automático']),
            $this->matricula(['estado' => 'DESE', 'fecha_retiro' => '2026-09-10', 'promovido' => 'Automático']),
        ];

        $p = $this->invocar('resumenPromocion', [$alumnos, false]);

        $this->assertSame(1, $p->evaluados->total, 'sólo se evalúa a quien terminó el año');
        $this->assertSame(1, $p->total_promovidos->total);
        $this->assertSame(0, $p->sin_definir->total);
        $this->assertTrue($p->cuadra);
    }

    /**
     * El balde se elegía con `cant_asign_perdidas == N || cant_areas_perdidas == N`. Como
     * cant_areas_perdidas es NOT NULL DEFAULT 0, en un colegio que no lleva áreas la condición
     * `... || cant_areas_perdidas == 0` era cierta para TODO promovido: todos caían en el balde 0
     * y promovidos_1_perdidas era permanentemente cero.
     */
    public function test_el_balde_de_una_pendiente_es_alcanzable_sin_areas(): void
    {
        $alumnos = [
            $this->matricula(['promovido' => 'Promovido (calculado)', 'cant_asign_perdidas' => 1, 'cant_areas_perdidas' => 0]),
            $this->matricula(['promovido' => 'Promovido (calculado)', 'cant_asign_perdidas' => 0, 'cant_areas_perdidas' => 0]),
        ];

        $p = $this->invocar('resumenPromocion', [$alumnos, false]);

        $this->assertSame(1, $p->promovidos_1->total, 'una asignatura pendiente va al balde 1, no al 0');
        $this->assertSame(1, $p->promovidos_0->total);
    }

    /**
     * Con el año configurado por áreas se usa cant_areas_perdidas, sin mezclar las dos métricas en
     * un OR que hacía los baldes ambiguos y dependientes del orden de los if.
     */
    public function test_con_areas_manda_la_cantidad_de_areas(): void
    {
        $alumnos = [
            $this->matricula(['promovido' => 'Promovido (calculado)', 'cant_asign_perdidas' => 3, 'cant_areas_perdidas' => 1]),
        ];

        $p = $this->invocar('resumenPromocion', [$alumnos, true]);

        $this->assertSame(1, $p->promovidos_1->total);
        $this->assertSame(0, $p->promovidos_otros->total);
    }

    /**
     * "Total NO PROMOVIDOS" era la suma de los baldes 2+3+4, así que un no promovido con 0 ó 1
     * pendientes -- decisión válida de la comisión, vía 'No promovido (manual)' -- no entraba en
     * ningún balde y el total subcontaba.
     */
    public function test_no_promovido_con_pocas_pendientes_no_se_pierde(): void
    {
        $alumnos = [
            $this->matricula(['promovido' => 'No promovido (manual)', 'cant_asign_perdidas' => 0]),
            $this->matricula(['promovido' => 'No promovido (calculado)', 'cant_asign_perdidas' => 3]),
        ];

        $p = $this->invocar('resumenPromocion', [$alumnos, false]);

        $this->assertSame(2, $p->total_no_promovidos->total, 'el total es un conteo real, no la suma de baldes');
        $this->assertSame(1, $p->no_promovidos_otros->total);
        $this->assertSame(1, $p->no_promovidos_3->total);
        $this->assertTrue($p->cuadra);
    }

    // ------------------------------------------------------------------------ movimiento por periodo

    /**
     * El informe no tenía ninguna dimensión de periodo: cant_retirados era un único número anual
     * derivado del estado actual, no de fecha_retiro. En el template había una tabla
     * "Cantidades por periodos" comentada y con las celdas vacías.
     */
    public function test_los_retiros_caen_en_el_periodo_de_su_fecha(): void
    {
        $periodos = [
            (object) ['id' => 1, 'numero' => 1, 'fecha_inicio' => '2026-02-01', 'fecha_fin' => '2026-04-15'],
            (object) ['id' => 2, 'numero' => 2, 'fecha_inicio' => '2026-04-16', 'fecha_fin' => '2026-06-30'],
            (object) ['id' => 3, 'numero' => 3, 'fecha_inicio' => '2026-07-01', 'fecha_fin' => '2026-09-15'],
        ];

        $alumnos = [
            $this->matricula(['estado' => 'RETI', 'fecha_retiro' => '2026-05-10']),
            $this->matricula(['estado' => 'DESE', 'fecha_retiro' => '2026-08-01']),
            $this->matricula(['estado' => 'MATR', 'fecha_matricula' => '2026-05-02']),
        ];

        $mov = $this->invocar('movimientoPorPeriodo', [$alumnos, $periodos]);

        $this->assertSame(1, $mov->filas[1]->retiros->total, 'retiro de mayo va al periodo 2');
        $this->assertSame(1, $mov->filas[2]->deserciones->total, 'deserción de agosto va al periodo 3');
        $this->assertSame(1, $mov->filas[1]->ingresos->total, 'ingreso de mayo va al periodo 2');
        $this->assertSame(0, $mov->filas[0]->retiros->total);
    }

    /**
     * Una fecha que no cae en ningún periodo suele delatar periodos mal configurados o un retiro
     * con fecha de otro año. Va a su propio balde en vez de evaporarse.
     */
    public function test_las_fechas_fuera_del_calendario_no_se_pierden(): void
    {
        $periodos = [
            (object) ['id' => 1, 'numero' => 1, 'fecha_inicio' => '2026-02-01', 'fecha_fin' => '2026-04-15'],
        ];

        $alumnos = [
            $this->matricula(['estado' => 'RETI', 'fecha_retiro' => '2025-11-30']),
        ];

        $mov = $this->invocar('movimientoPorPeriodo', [$alumnos, $periodos]);

        $this->assertSame(0, $mov->filas[0]->retiros->total);
        $this->assertSame(1, $mov->fuera_calendario->retiros->total);
    }

    // ------------------------------------------------------------------------------- duplicados

    /**
     * matriculas no tiene UNIQUE(alumno_id, grupo_id), sólo índices simples. Dos filas del mismo
     * alumno en el mismo grupo se cuentan dos veces en TODO, porque los conteos son sobre filas.
     * Es la clase de cosa que produce "los números no cuadran" sin dejar rastro.
     */
    public function test_se_detectan_matriculas_duplicadas(): void
    {
        $a = $this->matricula(['alumno_id' => 77, 'grupo_id' => 3, 'apellidos' => 'Pérez', 'nombres' => 'Ana']);
        $b = $this->matricula(['alumno_id' => 77, 'grupo_id' => 3, 'apellidos' => 'Pérez', 'nombres' => 'Ana']);
        $c = $this->matricula(['alumno_id' => 88, 'grupo_id' => 3]);

        $dups = $this->invocar('buscarDuplicados', [[$a, $b, $c]]);

        $this->assertCount(1, $dups);
        $this->assertSame(77, $dups[0]->alumno_id);
        $this->assertSame('Pérez Ana', $dups[0]->nombre);
    }

    // ----------------------------------------------------------------------------- razones y perfil

    public function test_las_razones_de_retiro_se_agrupan_y_ordenan(): void
    {
        $alumnos = [
            $this->matricula(['estado' => 'RETI', 'fecha_retiro' => '2026-05-01', 'razon_retiro' => 'Cambio de ciudad']),
            $this->matricula(['estado' => 'RETI', 'fecha_retiro' => '2026-06-01', 'razon_retiro' => 'cambio de ciudad']),
            $this->matricula(['estado' => 'DESE', 'fecha_retiro' => '2026-07-01', 'razon_retiro' => '']),
            $this->matricula(['estado' => 'MATR']),
        ];

        $razones = $this->invocar('razonesDeRetiro', [$alumnos]);

        $this->assertCount(2, $razones);
        $this->assertSame(2, $razones[0]->contador->total, 'se agrupa sin distinguir mayúsculas');
        $this->assertSame('(sin registrar)', $razones[1]->razon);
    }

    /**
     * Extraedad medida contra la edad modal del propio grupo: sin un mapeo confiable de grado a
     * edad esperada, es lo que se puede afirmar con los datos que hay.
     */
    public function test_extraedad_se_mide_contra_la_edad_modal_del_grupo(): void
    {
        $alumnos = [
            $this->matricula(['fecha_nac' => '2015-01-01']),
            $this->matricula(['fecha_nac' => '2015-01-01']),
            $this->matricula(['fecha_nac' => '2015-01-01']),
            $this->matricula(['fecha_nac' => '2012-01-01']),
            $this->matricula(['fecha_nac' => null]),
        ];

        $perfil = $this->invocar('perfilDelGrupo', [$alumnos]);

        $this->assertSame(1, $perfil->extraedad->total, 'sólo el de tres años más');
        $this->assertNotNull($perfil->edad_modal);
    }

    public function test_repitentes_y_nuevos_se_cuentan(): void
    {
        $alumnos = [
            $this->matricula(['repitente' => 1, 'nuevo' => 0]),
            $this->matricula(['repitente' => 0, 'nuevo' => 1]),
            $this->matricula(['repitente' => 1, 'nuevo' => 1]),
        ];

        $perfil = $this->invocar('perfilDelGrupo', [$alumnos]);

        $this->assertSame(2, $perfil->repitentes->total);
        $this->assertSame(2, $perfil->nuevos->total);
    }
}
