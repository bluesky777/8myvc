<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * Los periodos del año: cuál es el actual y quién puede editar notas en él.
 *
 * Es la hermana de `YearsTest`, y se escribió porque la [§28](05-codigo-muerto-y-roto.md)
 * encontró en `Years` tres veces la misma frase —`actual = 1` sin condición— y
 * había que mirar si estaba repetida aquí. **No lo está**: `putEstablecerActual`
 * apaga a los demás del año y enciende el pedido, que es lo correcto. Pero al
 * mirarlo salieron otras dos cosas, y están en la §31.
 */
class PeriodosTest extends CasoDeContrato
{
    private function tokenDelPersonal(): string
    {
        return $this->tokenDe($this->usuarioDeTipo('Usuario')->username);
    }

    /** @return list<int> Los periodos actuales de un año, que debe ser uno. */
    private function actualesDe(int $yearId): array
    {
        return array_map('intval', DB::table('periodos')->where('year_id', $yearId)
            ->where('actual', 1)->whereNull('deleted_at')->orderBy('id')->pluck('id')->all());
    }

    private function unAnioConPeriodos(): object
    {
        $fila = DB::selectOne('SELECT y.id, COUNT(p.id) cuantos FROM years y
            INNER JOIN periodos p ON p.year_id = y.id AND p.deleted_at IS NULL
            WHERE y.deleted_at IS NULL GROUP BY y.id HAVING cuantos > 1 ORDER BY y.id LIMIT 1');

        $this->assertNotNull($fila, 'El seed no tiene ningún año con más de un periodo.');

        return $fila;
    }

    /** Poner un periodo como actual deja exactamente uno en ese año. */
    public function test_establecer_actual_deja_uno_solo_en_el_ano(): void
    {
        $token = $this->tokenDelPersonal();
        $year = $this->unAnioConPeriodos();

        foreach (DB::table('periodos')->where('year_id', $year->id)->whereNull('deleted_at')
            ->orderBy('numero')->pluck('id') as $periodo) {

            $r = $this->withToken($token)->putJson('/api/periodos/establecer-actual/'.$periodo);

            $r->assertStatus(200);
            $this->assertSame([(int) $periodo], $this->actualesDe((int) $year->id),
                'Encender un periodo debe apagar a sus hermanos del mismo año.');
        }
    }

    /** Y no toca los de los otros años, que tienen el suyo. */
    public function test_establecer_actual_no_toca_los_otros_anos(): void
    {
        $token = $this->tokenDelPersonal();
        $year = $this->unAnioConPeriodos();

        $otros = DB::table('periodos')->where('year_id', '<>', $year->id)
            ->where('actual', 1)->whereNull('deleted_at')->orderBy('id')->pluck('id')->all();

        $this->assertNotEmpty($otros, 'Cada año del seed debería tener su periodo actual.');

        $periodo = DB::table('periodos')->where('year_id', $year->id)
            ->whereNull('deleted_at')->orderBy('numero')->value('id');

        $this->withToken($token)->putJson('/api/periodos/establecer-actual/'.$periodo)
            ->assertStatus(200);

        $this->assertSame($otros, DB::table('periodos')->where('year_id', '<>', $year->id)
            ->where('actual', 1)->whereNull('deleted_at')->orderBy('id')->pluck('id')->all());
    }

    /** Un periodo nuevo nace apagado y con los profesores pudiendo editar. */
    public function test_un_periodo_nuevo_nace_apagado(): void
    {
        $token = $this->tokenDelPersonal();
        $year = $this->unAnioConPeriodos();

        $antes = $this->actualesDe((int) $year->id);
        $numero = ((int) DB::table('periodos')->where('year_id', $year->id)->max('numero')) + 1;

        $r = $this->withToken($token)->postJson('/api/periodos/store/'.$year->id, [
            'numero' => $numero, 'fecha_inicio' => '2026-01-15', 'fecha_fin' => '2026-03-30',
            'fecha_plazo' => null, 'actual' => 1,
        ]);

        $r->assertStatus(201);
        $this->assertSame(0, $r->json('actual'),
            'El periodo nuevo se enciende aunque el cuerpo lo pida: el controlador fija 0.');
        $this->assertSame(1, $r->json('profes_pueden_editar_notas'));
        $this->assertSame(1, $r->json('profes_pueden_nivelar'));
        $this->assertSame($antes, $this->actualesDe((int) $year->id),
            'Crear un periodo no debe mover cuál es el actual.');
    }

    /**
     * Los dos conmutadores por periodo, que son el interruptor del §27.
     *
     * `profes_pueden_editar_notas` y `profes_pueden_nivelar` son las dos
     * banderas que consultan `User::pueden_editar_notas()` y
     * `pueden_modificar_definitivas()` desde 26 llamadas. Aquí se comprueba que
     * el conmutador las escribe; que el candado que las lee se puede rodear es la
     * [§27](05-codigo-muerto-y-roto.md), y tiene su propio test.
     */
    public function test_los_conmutadores_del_periodo_guardan(): void
    {
        $token = $this->tokenDelPersonal();
        $periodo = DB::selectOne('SELECT id FROM periodos WHERE deleted_at IS NULL ORDER BY id LIMIT 1');

        $conmutadores = [
            'periodos/toggle-profes-pueden-editar-notas' => 'profes_pueden_editar_notas',
            'periodos/toggle-profes-pueden-nivelar' => 'profes_pueden_nivelar',
        ];

        foreach ($conmutadores as $ruta => $columna) {
            foreach ([0, 1] as $valor) {
                $this->withToken($token)->putJson('/api/'.$ruta,
                    ['periodo_id' => $periodo->id, 'pueden' => $valor])->assertStatus(200);

                $this->assertSame($valor,
                    (int) DB::table('periodos')->where('id', $periodo->id)->value($columna),
                    "{$ruta} no dejó {$columna} en {$valor}.");
            }
        }
    }

    /** Las fechas se cambian una a una, que es como lo hace la pantalla. */
    public function test_las_fechas_se_cambian_una_a_una(): void
    {
        $token = $this->tokenDelPersonal();
        $periodo = DB::selectOne('SELECT id FROM periodos WHERE deleted_at IS NULL ORDER BY id LIMIT 1');

        $this->withToken($token)->putJson('/api/periodos/cambiar-fecha-inicio',
            ['periodo_id' => $periodo->id, 'fecha' => '2026-02-01'])->assertStatus(200);
        $this->withToken($token)->putJson('/api/periodos/cambiar-fecha-fin',
            ['periodo_id' => $periodo->id, 'fecha' => '2026-04-30'])->assertStatus(200);

        $fila = DB::table('periodos')->where('id', $periodo->id)->first();
        $this->assertSame('2026-02-01', (string) $fila->fecha_inicio);
        $this->assertSame('2026-04-30', (string) $fila->fecha_fin);
    }

    /**
     * `periodos/update/{id}` sigue rota, y da igual lo que se le mande.
     *
     * Escribe `$periodo->year`, y `periodos` **no tiene columna `year`** —tiene
     * `year_id`—, así que el `UPDATE` nombra una columna que no existe. Como el
     * atributo se asigna siempre, la fila siempre sale sucia: falla mandando
     * `year` y falla sin mandarlo.
     *
     * Se queda, según la regla de la casa: tiene ruta, y arreglarla **enciende**
     * una operación que hoy no existe. No es un detalle: su
     * `$periodo->actual = Request::input('actual')` no apaga a los hermanos, que
     * es exactamente el fallo de la [§28](05-codigo-muerto-y-roto.md) en la tabla
     * de al lado. Y ningún cliente la llama — el front tiene un endpoint por
     * campo, que es lo que se construye cuando el de «guardar todo» no funciona.
     */
    public function test_actualizar_un_periodo_entero_sigue_rota(): void
    {
        $token = $this->tokenDelPersonal();
        $periodo = DB::selectOne('SELECT * FROM periodos WHERE deleted_at IS NULL ORDER BY id LIMIT 1');

        $cuerpo = [
            'numero' => $periodo->numero, 'fecha_inicio' => $periodo->fecha_inicio,
            'fecha_fin' => $periodo->fecha_fin, 'actual' => 0, 'fecha_plazo' => null,
        ];

        $this->withToken($token)->putJson('/api/periodos/update/'.$periodo->id,
            $cuerpo + ['year' => 2025])->assertStatus(500);

        $this->withToken($token)->putJson('/api/periodos/update/'.$periodo->id, $cuerpo)
            ->assertStatus(500);

        $this->assertSame((int) $periodo->numero,
            (int) DB::table('periodos')->where('id', $periodo->id)->value('numero'),
            'La fila no debe haberse movido: el UPDATE ni llega a ejecutarse.');
    }

    /** Una familia no toca los periodos del colegio. */
    public function test_una_familia_no_toca_los_periodos(): void
    {
        $periodo = DB::selectOne('SELECT id, year_id, actual FROM periodos
            WHERE deleted_at IS NULL ORDER BY id LIMIT 1');

        foreach (['Alumno', 'Acudiente'] as $tipo) {
            $token = $this->tokenDe($this->usuarioDeTipo($tipo)->username);

            $this->withToken($token)->putJson('/api/periodos/establecer-actual/'.$periodo->id)
                ->assertStatus(403);
            $this->withToken($token)->putJson('/api/periodos/toggle-profes-pueden-editar-notas',
                ['periodo_id' => $periodo->id, 'pueden' => 1])->assertStatus(403);
            $this->withToken($token)->deleteJson('/api/periodos/destroy/'.$periodo->id)
                ->assertStatus(403);
        }

        $this->assertSame((int) $periodo->actual,
            (int) DB::table('periodos')->where('id', $periodo->id)->value('actual'));
        $this->assertNull(DB::table('periodos')->where('id', $periodo->id)->value('deleted_at'));
    }
}
