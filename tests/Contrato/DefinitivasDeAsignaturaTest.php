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
     * cada nota pesa un cuarto. Con 4, 3, 5 y 2 la definitiva es **3,5**.
     *
     * Este bloque decía «3,5 → **4** al redondear, y ese redondeo es el del código
     * (`decimal(4,0)`)». Era cierto y **dejó de serlo** con la migración
     * `2026_08_30_200000_notas_finales_en_decimal`: la columna es `DECIMAL(7,4)` y
     * el cálculo ya no redondea. El 4 era el defecto que empataba los puestos, así
     * que los números de estos tests bajan a su valor exacto **a propósito**.
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

        // 4 y 3 al 50% dan 3,5 en la unidad 1; 5 y 2, otro 3,5 en la 2; las dos al
        // 50% → **3,5**. Antes de la migración esto valía 4, y ese 4 es el que
        // hacía que dos alumnos distintos compartieran puesto.
        $this->assertSame(3.5, (float) $this->definitivaDe($this->ids['alumno1'])->nota);
    }

    /**
     * Y los decimales que se guardan son **cuatro**, no dos.
     *
     * Este test existe para que la escala de la columna no se pueda aflojar sin que
     * algo se ponga rojo. `DECIMAL(6,2)` era la propuesta que llegó con el encargo,
     * y sobre la base real **volvía a redondear 21.148 de 125.352 definitivas**
     * (16,9 %): el mismo defecto por la puerta de atrás y ya sin nadie mirando.
     *
     * La fórmula es `SUM(nota * pct_sub * pct_uni / 10000)` con los tres factores
     * enteros, así que **cuatro decimales es exacto y no un margen**. Aquí se monta
     * el caso que lo enseña: con la unidad al 33 % y la subunidad al 33 %, un 4 vale
     * `4 × 33 × 33 / 10000` = **0,4356**. En `int` era 0; en `(6,2)`, 0,44.
     */
    public function test_la_definitiva_guarda_cuatro_decimales(): void
    {
        $this->montarAsignatura();

        DB::table('unidades')->where('id', $this->ids['unidad1'])->update(['porcentaje' => 33]);
        DB::table('unidades')->where('id', $this->ids['unidad2'])->update(['porcentaje' => 67]);
        DB::table('subunidades')->where('id', $this->ids['sub11'])->update(['porcentaje' => 33]);
        DB::table('subunidades')->where('id', $this->ids['sub12'])->update(['porcentaje' => 67]);

        $this->ponerNota('sub11', $this->ids['alumno1'], 4);
        $this->ponerNota('sub12', $this->ids['alumno1'], 0);
        $this->ponerNota('sub21', $this->ids['alumno1'], 0);
        $this->ponerNota('sub22', $this->ids['alumno1'], 0);

        DefinitivasDeAsignatura::recalcular($this->ids['asignatura'], $this->ids['periodo']);

        $this->assertSame(0.4356, (float) $this->definitivaDe($this->ids['alumno1'])->nota);
    }

    /**
     * Y al borrar una nota, la definitiva baja.
     *
     * Es el caso de la §4.2 —el `MAX` de hoy es ciego a los borrados y declara la
     * definitiva al día con un valor que ya no corresponde—, comprobado por el
     * número y no por la bandera. Sin la nota de 5, quedan 4, 3 y 2 → **2,25**.
     * (Decía «→ 2»: ese último salto es el que quitó la migración.)
     */
    public function test_al_borrar_una_nota_la_definitiva_baja(): void
    {
        $this->montarAsignatura();

        $this->ponerNota('sub11', $this->ids['alumno1'], 4);
        $this->ponerNota('sub12', $this->ids['alumno1'], 3);
        $borrable = $this->ponerNota('sub21', $this->ids['alumno1'], 5);
        $this->ponerNota('sub22', $this->ids['alumno1'], 2);

        DefinitivasDeAsignatura::recalcular($this->ids['asignatura'], $this->ids['periodo']);
        $this->assertSame(3.5, (float) $this->definitivaDe($this->ids['alumno1'])->nota);

        DB::table('notas')->where('id', $borrable)->update(['deleted_at' => now()]);

        DefinitivasDeAsignatura::recalcular($this->ids['asignatura'], $this->ids['periodo']);
        $this->assertSame(2.25, (float) $this->definitivaDe($this->ids['alumno1'])->nota);
    }

    /**
     * Y al cambiar un porcentaje, también.
     *
     * §4.3: cambiar el peso de una unidad cambia la definitiva y **no toca ninguna
     * nota**, así que la comprobación de hoy no lo ve. Con la unidad 1 al 100% y
     * la 2 al 0%, sólo cuentan 4 y 3 → **3,5**. Se elige ese reparto porque da un
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
        // (4+3)/4 + (1+0)/4 = 1,75 + 0,25 = 2 — aquí el exacto ya era entero
        $this->assertSame(2.0, (float) $this->definitivaDe($this->ids['alumno1'])->nota);

        DB::table('unidades')->where('id', $this->ids['unidad1'])->update(['porcentaje' => 100]);
        DB::table('unidades')->where('id', $this->ids['unidad2'])->update(['porcentaje' => 0]);

        DefinitivasDeAsignatura::recalcular($this->ids['asignatura'], $this->ids['periodo']);
        // (4+3)/2 = 3,5, y ahora se guarda 3,5
        $this->assertSame(3.5, (float) $this->definitivaDe($this->ids['alumno1'])->nota);
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

    /**
     * Empuja el sello diez segundos hacia atrás, como hace
     * `test_una_definitiva_recien_escrita_esta_al_dia` y por lo mismo: la
     * definitiva y lo que la produce caen en el mismo segundo, y **el empate se
     * resuelve recalculando** (§4.5). Sin separarlos, «recién recalculada» sale
     * desactualizada y el test no mide lo que dice medir.
     */
    private function envejecerElSello(): void
    {
        $subunidades = [$this->ids['sub11'], $this->ids['sub12'],
            $this->ids['sub21'], $this->ids['sub22']];

        DB::table('notas')->whereIn('subunidad_id', $subunidades)->update([
            'updated_at' => DB::raw('DATE_SUB(NOW(), INTERVAL 10 SECOND)'),
        ]);
        DB::table('unidades')->where('asignatura_id', $this->ids['asignatura'])->update([
            'updated_at' => DB::raw('DATE_SUB(NOW(), INTERVAL 10 SECOND)'),
        ]);
        DB::table('subunidades')->whereIn('id', $subunidades)->update([
            'updated_at' => DB::raw('DATE_SUB(NOW(), INTERVAL 10 SECOND)'),
        ]);
        DB::table('matriculas')->where('grupo_id', $this->ids['grupo'])->update([
            'created_at' => DB::raw('DATE_SUB(NOW(), INTERVAL 10 SECOND)'),
        ]);
    }

    /**
     * Los alumnos que el recalculador mira: matriculados vivos del grupo, MATR o
     * ASIS. Es el mismo conjunto de `calcular()`, y comparar contra otro haría que
     * este test dijera que las dos formas discrepan cuando lo que discrepa es el
     * test.
     *
     * @return array<int, int>
     */
    private function matriculadosDelGrupo(): array
    {
        return array_map('intval', DB::table('matriculas')
            ->where('grupo_id', $this->ids['grupo'])
            ->whereIn('estado', ['MATR', 'ASIS'])
            ->whereNull('deleted_at')
            ->pluck('alumno_id')
            ->all());
    }

    /**
     * El control que importa: la consulta agregada tiene que contestar **la misma
     * pregunta** que preguntar alumno por alumno.
     *
     * `estadoDelGrupo()` es rápida por ser otra consulta, no por ser la misma
     * escrita mejor, así que la equivalencia no se puede dar por buena: se compara
     * asignatura por asignatura contra `estaDesactualizada()`, que es el método que
     * ya usa el boletín y el que define qué significa «desactualizada».
     *
     * Se monta a propósito un grupo con las dos cosas dentro —una asignatura recién
     * recalculada y otra con una nota puesta después— para que la comparación no sea
     * entre dos listas de todo-verde, que coincidirían aunque el detector estuviera
     * roto.
     */
    public function test_el_estado_del_grupo_dice_lo_mismo_que_preguntar_una_a_una(): void
    {
        $this->montarAsignatura();

        $this->ponerNota('sub11', $this->ids['alumno1'], 4);
        DefinitivasDeAsignatura::recalcular($this->ids['asignatura'], $this->ids['periodo']);

        // Y una nota puesta DESPUÉS del recálculo: la asignatura vuelve a estar por
        // detrás, y el grupo queda con casos en las dos direcciones.
        $this->ponerNota('sub12', $this->ids['alumno2'], 3);

        $estado = DefinitivasDeAsignatura::estadoDelGrupo($this->ids['grupo'], $this->ids['periodo']);
        $alumnos = $this->matriculadosDelGrupo();

        $this->assertNotEmpty($estado, 'El grupo del seed no tiene asignaturas vivas: sin población, esto no comprueba nada.');
        $this->assertNotEmpty($alumnos, 'El grupo del seed no tiene matriculados vivos.');

        foreach ($estado as $fila) {
            $unaAUna = false;

            foreach ($alumnos as $alumnoId) {
                if (DefinitivasDeAsignatura::estaDesactualizada(
                    $fila['asignatura_id'], $this->ids['periodo'], $alumnoId
                )) {
                    $unaAUna = true;
                    break;
                }
            }

            $this->assertSame($unaAUna, $fila['desactualizada'],
                "La asignatura {$fila['asignatura_id']} sale distinta preguntada de las dos formas.");
        }
    }

    /**
     * Y la población va en la respuesta, porque un «0 desactualizadas» sin ella no
     * distingue «revisé treinta» de «no revisé nada».
     */
    public function test_el_estado_del_grupo_dice_a_cuantos_alumnos_miro(): void
    {
        $this->montarAsignatura();

        $estado = DefinitivasDeAsignatura::estadoDelGrupo($this->ids['grupo'], $this->ids['periodo']);
        $mia = $this->laDeLaAsignaturaMontada($estado);

        $this->assertSame(count($this->matriculadosDelGrupo()), $mia['alumnos']);
    }

    /**
     * Lo que cuesta cada forma, medido y no supuesto.
     *
     * Medido el 27 ago 2026 sobre la base de tests: **1 consulta** la agregada
     * contra **506** preguntando una a una en ese grupo —10 asignaturas × 28
     * alumnos—. No son 560 porque `estaDesactualizada()` corta antes cuando no hay
     * fila: la mitad de los pares gasta una consulta en vez de dos. En un grupo real
     * de secundaria (~12 × ~30) el orden es el mismo, y es lo que hace inviable
     * cablear `estaDesactualizada()` en un informe de grupo.
     *
     * La aserción es sobre la **relación**, no sobre el número: los números de un
     * grupo dependen del seed y envejecen; que preguntar una a una cueste más de una
     * consulta por par es una propiedad del código.
     */
    public function test_el_estado_del_grupo_cuesta_una_sola_consulta(): void
    {
        $this->montarAsignatura();
        $this->ponerNota('sub11', $this->ids['alumno1'], 4);

        $alumnos = $this->matriculadosDelGrupo();

        DB::enableQueryLog();
        DB::flushQueryLog();
        $estado = DefinitivasDeAsignatura::estadoDelGrupo($this->ids['grupo'], $this->ids['periodo']);
        $agregada = count(DB::getQueryLog());

        DB::flushQueryLog();
        foreach ($estado as $fila) {
            foreach ($alumnos as $alumnoId) {
                DefinitivasDeAsignatura::estaDesactualizada(
                    $fila['asignatura_id'], $this->ids['periodo'], $alumnoId
                );
            }
        }
        $unaAUna = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(1, $agregada,
            'El estado del grupo dejó de ser una sola consulta: revisar antes de cablearlo a un informe.');
        $this->assertGreaterThan($agregada, $unaAUna,
            "Preguntar una a una costó {$unaAUna} consultas y la agregada {$agregada}: si ya no hay diferencia, este método sobra.");
    }

    /**
     * Sin recalcular, todas las filas faltan — y faltar es estar desactualizada.
     *
     * Es la §9.1 vista desde el otro lado: la fila existe siempre que exista la
     * matrícula, así que un matriculado sin fila no es «todavía no hay nota», es un
     * estado que hay que reparar. Son las 11.988 que midió la fase 0.
     */
    public function test_los_matriculados_sin_fila_cuentan_como_que_faltan(): void
    {
        $this->montarAsignatura();
        $this->ponerNota('sub11', $this->ids['alumno1'], 4);

        $mia = $this->laDeLaAsignaturaMontada(
            DefinitivasDeAsignatura::estadoDelGrupo($this->ids['grupo'], $this->ids['periodo'])
        );

        $this->assertSame(count($this->matriculadosDelGrupo()), $mia['faltan']);
        $this->assertTrue($mia['desactualizada']);

        DefinitivasDeAsignatura::recalcular($this->ids['asignatura'], $this->ids['periodo']);
        $this->envejecerElSello();

        $despues = $this->laDeLaAsignaturaMontada(
            DefinitivasDeAsignatura::estadoDelGrupo($this->ids['grupo'], $this->ids['periodo'])
        );

        $this->assertSame(0, $despues['faltan']);
        $this->assertFalse($despues['desactualizada'],
            'Recién recalculada y sigue saliendo desactualizada: un informe que repare entraría en bucle.');
    }

    /**
     * Y al borrar una nota vuelve a salir desactualizada.
     *
     * Es la §4.2 —el `MAX` de hoy es ciego a los borrados— comprobada sobre el grupo
     * entero, que es donde va a mirar un informe.
     */
    public function test_al_borrar_una_nota_el_grupo_vuelve_a_salir_desactualizado(): void
    {
        $this->montarAsignatura();

        $borrable = $this->ponerNota('sub11', $this->ids['alumno1'], 4);
        DefinitivasDeAsignatura::recalcular($this->ids['asignatura'], $this->ids['periodo']);
        $this->envejecerElSello();

        $this->assertFalse($this->laDeLaAsignaturaMontada(
            DefinitivasDeAsignatura::estadoDelGrupo($this->ids['grupo'], $this->ids['periodo'])
        )['desactualizada']);

        DB::table('notas')->where('id', $borrable)->update(['deleted_at' => now()]);

        $this->assertTrue($this->laDeLaAsignaturaMontada(
            DefinitivasDeAsignatura::estadoDelGrupo($this->ids['grupo'], $this->ids['periodo'])
        )['desactualizada'], 'Borrar una nota dejó la definitiva declarada al día: es exactamente la §4.2.');
    }

    /**
     * Una definitiva puesta a mano no cuenta como atrasada.
     *
     * No se recalcula nunca (regla 4), así que preguntar si está por detrás no
     * significa nada para ella. Si contara, un informe que repare la miraría en cada
     * carga y no la arreglaría jamás.
     */
    public function test_una_definitiva_manual_no_sale_atrasada(): void
    {
        $this->montarAsignatura();

        $this->ponerNota('sub11', $this->ids['alumno1'], 4);
        DefinitivasDeAsignatura::recalcular($this->ids['asignatura'], $this->ids['periodo']);

        // Todas a mano, y una nota nueva detrás que movería el sello.
        DB::table('notas_finales')
            ->where('asignatura_id', $this->ids['asignatura'])
            ->where('periodo_id', $this->ids['periodo'])
            ->update(['manual' => 1]);

        $this->ponerNota('sub12', $this->ids['alumno1'], 1);

        $mia = $this->laDeLaAsignaturaMontada(
            DefinitivasDeAsignatura::estadoDelGrupo($this->ids['grupo'], $this->ids['periodo'])
        );

        $this->assertSame(0, $mia['atrasadas']);
        $this->assertFalse($mia['desactualizada']);
    }

    /**
     * La fila de la asignatura que monta este test, de entre las del grupo.
     *
     * @param  array<int, array{asignatura_id:int, sello:string|null, alumnos:int, faltan:int, atrasadas:int, desactualizada:bool}>  $estado
     * @return array{asignatura_id:int, sello:string|null, alumnos:int, faltan:int, atrasadas:int, desactualizada:bool}
     */
    private function laDeLaAsignaturaMontada(array $estado): array
    {
        foreach ($estado as $fila) {
            if ($fila['asignatura_id'] === $this->ids['asignatura']) {
                return $fila;
            }
        }

        $this->fail('La asignatura montada no salió en el estado del grupo.');
    }
}
