<?php

namespace Tests\Contrato;

use App\Services\DefinitivasDeAsignatura;
use Illuminate\Support\Facades\DB;

/**
 * El recalculador único de la fase 1 de
 * [10-definitivas.md](../../docs/migracion/10-definitivas.md).
 *
 * El plan dice cómo tiene que ser este test y no se improvisa:
 *
 * > El criterio del §«Tests de contrato» del CLAUDE.md aplica literal: **mirar el
 * > resultado, no el estado**. El test que sirve es el viaje de ida y vuelta:
 * > pongo una nota, pido la definitiva, la comparo; borro una nota, pido la
 * > definitiva, la comparo; cambio un porcentaje, ídem. Un test que compruebe que
 * > `nfinal_desactualizada` vale `1` no encuentra nada.
 *
 * Así que aquí no se comprueba ninguna bandera: se monta una asignatura con sus
 * unidades, sus subunidades y sus notas, se recalcula y **se compara el número
 * escrito con el que sale de multiplicar a mano**.
 *
 * Todo el andamiaje se monta en el test. `ws_actividades` no fue la única tabla
 * que llega vacía en el seed —la de notas de un grupo entero tampoco sirve, hace
 * falta controlar los porcentajes— y un test que se apoye en lo que haya no
 * distingue un cálculo bueno de uno que casualmente da lo mismo.
 */
class DefinitivasDeAsignaturaTest extends CasoDeContrato
{
    /** @var array<string,int> */
    private array $ids = [];

    /**
     * Una asignatura con dos unidades al 50%, cada una con dos subunidades al 50%,
     * y dos alumnos matriculados.
     *
     * Los pesos se eligen así para que la aritmética sea comprobable de cabeza:
     * cada nota pesa un cuarto. Con 4, 3, 5 y 2 la definitiva es 3,5 → **4** al
     * redondear, y ese redondeo es el del código (`decimal(4,0)`), no una elección
     * de este test.
     */
    private function montarAsignatura(): void
    {
        $grupo = DB::table('grupos')->whereNull('deleted_at')->orderBy('id')->first();
        $periodo = DB::table('periodos')->where('year_id', $grupo->year_id)
            ->whereNull('deleted_at')->orderBy('numero')->first();
        $materia = DB::table('materias')->whereNull('deleted_at')->orderBy('id')->first();
        $profesor = DB::table('profesores')->whereNull('deleted_at')->orderBy('id')->first();

        $this->assertNotNull($grupo, 'El seed necesita un grupo.');
        $this->assertNotNull($periodo, 'El seed necesita un periodo del año de ese grupo.');

        $this->ids['grupo'] = (int) $grupo->id;
        $this->ids['periodo'] = (int) $periodo->id;
        $this->ids['numero_periodo'] = (int) $periodo->numero;

        $this->ids['asignatura'] = (int) DB::table('asignaturas')->insertGetId([
            'materia_id' => $materia->id,
            'grupo_id' => $grupo->id,
            'profesor_id' => $profesor->id,
            'creditos' => 1,
            'orden' => 999,
        ]);

        foreach ([1, 2] as $n) {
            $this->ids["unidad{$n}"] = (int) DB::table('unidades')->insertGetId([
                'definicion' => "Unidad {$n}",
                'porcentaje' => 50,
                'periodo_id' => $periodo->id,
                'asignatura_id' => $this->ids['asignatura'],
                'orden' => $n,
            ]);

            foreach ([1, 2] as $m) {
                $this->ids["sub{$n}{$m}"] = (int) DB::table('subunidades')->insertGetId([
                    'definicion' => "Indicador {$n}.{$m}",
                    'porcentaje' => 50,
                    'unidad_id' => $this->ids["unidad{$n}"],
                    'orden' => $m,
                ]);
            }
        }

        // Dos alumnos matriculados del grupo. Se cogen de las matrículas vivas
        // porque el servicio parte de ahí, y montar matrículas nuevas metería en
        // el test la mitad del dominio de matrículas.
        $matriculas = DB::table('matriculas')
            ->where('grupo_id', $grupo->id)
            ->whereIn('estado', ['MATR', 'ASIS'])
            ->whereNull('deleted_at')
            ->orderBy('alumno_id')
            ->limit(2)
            ->pluck('alumno_id')
            ->all();

        $this->assertCount(2, $matriculas, 'El seed necesita dos alumnos matriculados en el grupo.');

        $this->ids['alumno1'] = (int) $matriculas[0];
        $this->ids['alumno2'] = (int) $matriculas[1];
    }

    private function ponerNota(string $sub, int $alumnoId, float $nota): int
    {
        return (int) DB::table('notas')->insertGetId([
            'nota' => $nota,
            'subunidad_id' => $this->ids[$sub],
            'alumno_id' => $alumnoId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function definitivaDe(int $alumnoId): ?object
    {
        return DB::table('notas_finales')
            ->where('alumno_id', $alumnoId)
            ->where('asignatura_id', $this->ids['asignatura'])
            ->where('periodo_id', $this->ids['periodo'])
            ->orderBy('id')
            ->first();
    }

    /**
     * El viaje de ida y vuelta: pongo notas, pido la definitiva, la comparo.
     *
     * 4, 3, 5 y 2, cada una a un cuarto → 3,5 → 4.
     */
    public function test_la_definitiva_es_la_suma_de_los_aportes(): void
    {
        $this->montarAsignatura();

        $this->ponerNota('sub11', $this->ids['alumno1'], 4);
        $this->ponerNota('sub12', $this->ids['alumno1'], 3);
        $this->ponerNota('sub21', $this->ids['alumno1'], 5);
        $this->ponerNota('sub22', $this->ids['alumno1'], 2);

        DefinitivasDeAsignatura::recalcular($this->ids['asignatura'], $this->ids['periodo']);

        $this->assertSame(4, (int) $this->definitivaDe($this->ids['alumno1'])->nota);
    }

    /**
     * Y al borrar una nota, la definitiva baja.
     *
     * Es el caso de la §4.2 —el `MAX` de hoy es ciego a los borrados y declara la
     * definitiva al día con un valor que ya no corresponde—, comprobado por el
     * número y no por la bandera. Sin la nota de 5, quedan 4, 3 y 2 → 2,25 → 2.
     */
    public function test_al_borrar_una_nota_la_definitiva_baja(): void
    {
        $this->montarAsignatura();

        $this->ponerNota('sub11', $this->ids['alumno1'], 4);
        $this->ponerNota('sub12', $this->ids['alumno1'], 3);
        $borrable = $this->ponerNota('sub21', $this->ids['alumno1'], 5);
        $this->ponerNota('sub22', $this->ids['alumno1'], 2);

        DefinitivasDeAsignatura::recalcular($this->ids['asignatura'], $this->ids['periodo']);
        $this->assertSame(4, (int) $this->definitivaDe($this->ids['alumno1'])->nota);

        DB::table('notas')->where('id', $borrable)->update(['deleted_at' => now()]);

        DefinitivasDeAsignatura::recalcular($this->ids['asignatura'], $this->ids['periodo']);
        $this->assertSame(2, (int) $this->definitivaDe($this->ids['alumno1'])->nota);
    }

    /**
     * Y al cambiar un porcentaje, también.
     *
     * §4.3: cambiar el peso de una unidad cambia la definitiva y **no toca ninguna
     * nota**, así que la comprobación de hoy no lo ve. Con la unidad 1 al 100% y
     * la 2 al 0%, sólo cuentan 4 y 3 → 3,5 → 4. Se elige ese reparto porque da un
     * número distinto del de partida en las dos direcciones que importan.
     */
    public function test_al_cambiar_un_porcentaje_la_definitiva_cambia(): void
    {
        $this->montarAsignatura();

        $this->ponerNota('sub11', $this->ids['alumno1'], 4);
        $this->ponerNota('sub12', $this->ids['alumno1'], 3);
        $this->ponerNota('sub21', $this->ids['alumno1'], 1);
        $this->ponerNota('sub22', $this->ids['alumno1'], 0);

        DefinitivasDeAsignatura::recalcular($this->ids['asignatura'], $this->ids['periodo']);
        // (4+3)/4 + (1+0)/4 = 1,75 + 0,25 = 2
        $this->assertSame(2, (int) $this->definitivaDe($this->ids['alumno1'])->nota);

        DB::table('unidades')->where('id', $this->ids['unidad1'])->update(['porcentaje' => 100]);
        DB::table('unidades')->where('id', $this->ids['unidad2'])->update(['porcentaje' => 0]);

        DefinitivasDeAsignatura::recalcular($this->ids['asignatura'], $this->ids['periodo']);
        // (4+3)/2 = 3,5 → 4
        $this->assertSame(4, (int) $this->definitivaDe($this->ids['alumno1'])->nota);
    }

    /**
     * El alumno sin ninguna nota recibe su fila igual.
     *
     * Es la §9.1, y es lo que separa este servicio de los seis escritores de hoy:
     * todos parten de `notas`, así que el alumno sin notas no aparece en el
     * cálculo, pierde la definitiva en el DELETE y no vuelve (§1.1, §1.3).
     */
    public function test_el_alumno_sin_notas_recibe_su_definitiva(): void
    {
        $this->montarAsignatura();

        $this->ponerNota('sub11', $this->ids['alumno1'], 4);

        DefinitivasDeAsignatura::recalcular($this->ids['asignatura'], $this->ids['periodo']);

        $delSegundo = $this->definitivaDe($this->ids['alumno2']);

        $this->assertNotNull($delSegundo, 'El alumno sin notas se quedó sin fila, que es la §1.');
        $this->assertSame(0, (int) $delSegundo->nota);
    }

    /** Y `periodo` se escribe derivado de `periodo_id`, nunca al revés (§2.1). */
    public function test_la_columna_periodo_se_deriva_del_periodo_id(): void
    {
        $this->montarAsignatura();
        $this->ponerNota('sub11', $this->ids['alumno1'], 4);

        DefinitivasDeAsignatura::recalcular($this->ids['asignatura'], $this->ids['periodo']);

        $this->assertSame($this->ids['numero_periodo'],
            (int) $this->definitivaDe($this->ids['alumno1'])->periodo);
    }

    /**
     * Una definitiva puesta a mano no la pisa el recálculo.
     *
     * Hoy esto se comprueba en cinco sitios con cinco redacciones distintas del
     * mismo `WHERE`; aquí es un único punto.
     */
    public function test_una_definitiva_manual_no_se_pisa(): void
    {
        $this->montarAsignatura();
        $this->ponerNota('sub11', $this->ids['alumno1'], 4);

        DB::table('notas_finales')->insert([
            'alumno_id' => $this->ids['alumno1'],
            'asignatura_id' => $this->ids['asignatura'],
            'periodo_id' => $this->ids['periodo'],
            'periodo' => $this->ids['numero_periodo'],
            'nota' => 5,
            'manual' => 1,
            'recuperada' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $resultado = DefinitivasDeAsignatura::recalcular(
            $this->ids['asignatura'], $this->ids['periodo']
        );

        $this->assertSame(5, (int) $this->definitivaDe($this->ids['alumno1'])->nota);
        $this->assertSame(1, $resultado['respetadas']);
    }

    /** Lo mismo con `recuperada`, que es la otra excepción que no se recalcula. */
    public function test_una_definitiva_recuperada_no_se_pisa(): void
    {
        $this->montarAsignatura();
        $this->ponerNota('sub11', $this->ids['alumno1'], 4);

        DB::table('notas_finales')->insert([
            'alumno_id' => $this->ids['alumno1'],
            'asignatura_id' => $this->ids['asignatura'],
            'periodo_id' => $this->ids['periodo'],
            'periodo' => $this->ids['numero_periodo'],
            'nota' => 3,
            'manual' => 0,
            'recuperada' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DefinitivasDeAsignatura::recalcular($this->ids['asignatura'], $this->ids['periodo']);

        $this->assertSame(3, (int) $this->definitivaDe($this->ids['alumno1'])->nota);
    }

    /**
     * Recalcular dos veces no duplica, que es el síntoma entero de la §2.
     *
     * Se comprueba contando filas y no mirando la respuesta: un duplicado no se ve
     * en lo que devuelve el servicio, se ve en la tabla — y es lo que hace que el
     * profesor vea dos definitivas editables.
     */
    public function test_recalcular_dos_veces_no_duplica(): void
    {
        $this->montarAsignatura();
        $this->ponerNota('sub11', $this->ids['alumno1'], 4);

        DefinitivasDeAsignatura::recalcular($this->ids['asignatura'], $this->ids['periodo']);
        DefinitivasDeAsignatura::recalcular($this->ids['asignatura'], $this->ids['periodo']);
        DefinitivasDeAsignatura::recalcular($this->ids['asignatura'], $this->ids['periodo']);

        $this->assertSame(1, DB::table('notas_finales')
            ->where('alumno_id', $this->ids['alumno1'])
            ->where('asignatura_id', $this->ids['asignatura'])
            ->where('periodo_id', $this->ids['periodo'])
            ->count());
    }

    /**
     * Y en ningún instante la definitiva deja de estar.
     *
     * Es la diferencia entre este servicio y los seis de hoy, y no se ve en el
     * resultado final: todos acaban con la fila puesta. Lo que cambia es que
     * aquéllos hacen DELETE y luego INSERT, así que una petición que muera en
     * medio —y el boletín recorre alumno × asignatura × periodo— la deja borrada.
     *
     * Se comprueba escuchando las consultas: **ninguna borra**.
     */
    public function test_el_recalculo_no_borra_nunca(): void
    {
        $this->montarAsignatura();
        $this->ponerNota('sub11', $this->ids['alumno1'], 4);

        DefinitivasDeAsignatura::recalcular($this->ids['asignatura'], $this->ids['periodo']);

        $borrados = [];
        DB::listen(function ($consulta) use (&$borrados) {
            // `delete` a secas casa con `deleted_at`, que sale en casi todas las
            // consultas de este proyecto. Lo que se busca es la SENTENCIA.
            if (preg_match('/^\s*delete\s/i', $consulta->sql) === 1) {
                $borrados[] = $consulta->sql;
            }
        });

        DefinitivasDeAsignatura::recalcular($this->ids['asignatura'], $this->ids['periodo']);

        $this->assertSame([], $borrados,
            'El recálculo borró algo, y no tener ventana de borrado es la mitad del arreglo.');
    }

    /**
     * El sello se mueve cuando cambia la estructura, que es lo que la §4.3 no ve.
     *
     * Se comprueba comparando el sello consigo mismo antes y después, y no
     * `nfinal_desactualizada`: la bandera es lo que hay que dejar de mirar.
     */
    public function test_el_sello_se_mueve_al_tocar_un_porcentaje(): void
    {
        $this->montarAsignatura();
        $this->ponerNota('sub11', $this->ids['alumno1'], 4);

        $antes = DefinitivasDeAsignatura::selloDeVersion(
            $this->ids['asignatura'], $this->ids['periodo']
        );

        $this->assertNotNull($antes);

        // Se empuja un segundo hacia adelante: `timestamp` guarda segundos y el
        // test corre en milisegundos, así que sin esto los dos sellos caerían en
        // el mismo segundo y el test pasaría sin medir nada — que es exactamente
        // el fallo del que avisa la §4.5.
        DB::table('unidades')->where('id', $this->ids['unidad1'])->update([
            'porcentaje' => 80,
            'updated_at' => DB::raw('DATE_ADD(NOW(), INTERVAL 5 SECOND)'),
        ]);

        $despues = DefinitivasDeAsignatura::selloDeVersion(
            $this->ids['asignatura'], $this->ids['periodo']
        );

        $this->assertGreaterThan(strtotime($antes), strtotime($despues),
            'Cambiar un porcentaje no movió el sello, que es la §4.3 entera.');
    }

    /**
     * Y se mueve al BORRAR una nota, que es donde el `MAX` de hoy baja.
     *
     * Es la §4.2 y la comprobación al revés de la de arriba: si el sello sólo
     * mirara las notas vivas, aquí bajaría en vez de subir.
     */
    public function test_el_sello_se_mueve_al_borrar_una_nota(): void
    {
        $this->montarAsignatura();
        $nota = $this->ponerNota('sub11', $this->ids['alumno1'], 4);

        $antes = DefinitivasDeAsignatura::selloDeVersion(
            $this->ids['asignatura'], $this->ids['periodo']
        );

        DB::table('notas')->where('id', $nota)->update([
            'deleted_at' => DB::raw('DATE_ADD(NOW(), INTERVAL 5 SECOND)'),
        ]);

        $despues = DefinitivasDeAsignatura::selloDeVersion(
            $this->ids['asignatura'], $this->ids['periodo']
        );

        $this->assertGreaterThan(strtotime($antes), strtotime($despues),
            'Borrar una nota bajó el sello en vez de subirlo: es la §4.2.');
    }

    /** Una definitiva recién escrita no está desactualizada. */
    public function test_una_definitiva_recien_escrita_esta_al_dia(): void
    {
        $this->montarAsignatura();
        $this->ponerNota('sub11', $this->ids['alumno1'], 4);

        DefinitivasDeAsignatura::recalcular($this->ids['asignatura'], $this->ids['periodo']);

        // El sello se empuja hacia atrás por lo mismo que el test de arriba lo
        // empuja hacia adelante: los dos caen en el mismo segundo y el empate se
        // resuelve recalculando, así que sin separarlos esto no mediría nada.
        DB::table('notas')->where('subunidad_id', $this->ids['sub11'])->update([
            'updated_at' => DB::raw('DATE_SUB(NOW(), INTERVAL 10 SECOND)'),
        ]);
        DB::table('unidades')->where('asignatura_id', $this->ids['asignatura'])->update([
            'updated_at' => DB::raw('DATE_SUB(NOW(), INTERVAL 10 SECOND)'),
        ]);
        DB::table('subunidades')->whereIn('unidad_id',
            [$this->ids['unidad1'], $this->ids['unidad2']])->update([
                'updated_at' => DB::raw('DATE_SUB(NOW(), INTERVAL 10 SECOND)'),
            ]);
        DB::table('matriculas')->where('grupo_id', $this->ids['grupo'])->update([
            'created_at' => DB::raw('DATE_SUB(NOW(), INTERVAL 10 SECOND)'),
        ]);

        $this->assertFalse(DefinitivasDeAsignatura::estaDesactualizada(
            $this->ids['asignatura'], $this->ids['periodo'], $this->ids['alumno1']
        ));
    }

    /** Y el alumno sin fila está desactualizado por definición (§9.1, §4.4). */
    public function test_sin_fila_esta_desactualizada(): void
    {
        $this->montarAsignatura();
        $this->ponerNota('sub11', $this->ids['alumno1'], 4);

        $this->assertTrue(DefinitivasDeAsignatura::estaDesactualizada(
            $this->ids['asignatura'], $this->ids['periodo'], $this->ids['alumno1']
        ));
    }

    /**
     * La suma de porcentajes se devuelve, no se corrige (§9.3).
     *
     * Es lo que permite señalar la asignatura mal configurada en la planilla en
     * vez de taparla normalizando.
     */
    public function test_la_suma_de_porcentajes_se_devuelve_tal_cual(): void
    {
        $this->montarAsignatura();
        $this->ponerNota('sub11', $this->ids['alumno1'], 4);

        $bien = DefinitivasDeAsignatura::recalcular(
            $this->ids['asignatura'], $this->ids['periodo']
        );
        $this->assertSame(100.0, $bien['porcentaje_unidades']);

        DB::table('unidades')->where('id', $this->ids['unidad2'])->update(['porcentaje' => 30]);

        $mal = DefinitivasDeAsignatura::recalcular(
            $this->ids['asignatura'], $this->ids['periodo']
        );
        $this->assertSame(80.0, $mal['porcentaje_unidades'],
            'La suma se normalizó o se corrigió, y la §9.3 dice que se vea.');
    }
}
