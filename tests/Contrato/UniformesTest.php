<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * Los uniformes, y el interruptor con el que el colegio cierra el periodo.
 *
 * `UniformesController` era el tercero de los cinco controladores que la
 * cobertura del 20 de agosto dio con cero respuestas comprobadas. Sus cuatro
 * rutas son las únicas de la API cuya única autorización, más allá de
 * `auth.personal`, es `User::pueden_editar_notas()` — el interruptor por periodo
 * con el que el colegio cierra la edición a los profesores.
 *
 * Ese interruptor lo comparten 26 llamadas de 7 controladores, casi todas en el
 * camino de las notas. Aquí se comprueba por uniformes porque es el único de los
 * siete que se puede montar entero sin tocar el cálculo de notas.
 *
 * Ver docs/migracion/05-codigo-muerto-y-roto.md §27.
 */
class UniformesTest extends CasoDeContrato
{
    /** El profesor del seed, y su periodo tal como queda DESPUÉS de entrar. */
    private function profesorYSuPeriodo(): array
    {
        $prof = $this->usuarioDeTipo('Profesor');
        $token = $this->tokenDe($prof->username);

        // `Services\Login` reescribe `users.periodo_id` al periodo actual en cada
        // inicio de sesión, así que el periodo de antes de entrar no es el que
        // usa el controlador. Leerlo antes deja el test midiendo otro año.
        $periodo = DB::selectOne('SELECT p.id, p.numero, p.year_id FROM periodos p
            INNER JOIN users u ON u.periodo_id = p.id WHERE u.id = ?', [$prof->id]);

        $this->assertNotNull($periodo, 'El profesor del seed se quedó sin periodo al entrar.');

        return [$token, $periodo];
    }

    private function cuerpoDeUniforme(): array
    {
        $alumno = DB::selectOne('SELECT id FROM alumnos WHERE deleted_at IS NULL ORDER BY id LIMIT 1');

        return [
            'alumno_id' => $alumno->id,
            'asignatura_id' => null,
            'materia' => 'Sociales',
            'descripcion' => 'sin corbata',
            'fecha_hora' => '2026-08-20 07:00:00',
        ];
    }

    /** El profesor anota un uniforme y le vuelve la fila que acaba de escribir. */
    public function test_el_profesor_anota_un_uniforme(): void
    {
        [$token, $periodo] = $this->profesorYSuPeriodo();

        DB::table('periodos')->where('id', $periodo->id)->update(['profes_pueden_editar_notas' => 1]);

        $antes = DB::selectOne('SELECT COUNT(*) c FROM uniformes')->c;

        $r = $this->withToken($token)->putJson('/api/uniformes/agregar',
            $this->cuerpoDeUniforme() + ['sin_uniforme' => 1]);

        $r->assertStatus(200);
        $this->assertSame($antes + 1, DB::selectOne('SELECT COUNT(*) c FROM uniformes')->c);
        $this->assertSame((int) $periodo->id, $r->json('uniforme.periodo_id'),
            'El uniforme no se escribió en el periodo del profesor.');
        $this->assertSame(1, $r->json('uniforme.sin_uniforme'));
        $this->assertSame('sin corbata', $r->json('uniforme.descripcion'));
    }

    /** Y lo corrige y lo borra, que son las otras dos rutas vivas. */
    public function test_el_profesor_corrige_y_borra_el_uniforme(): void
    {
        [$token, $periodo] = $this->profesorYSuPeriodo();

        DB::table('periodos')->where('id', $periodo->id)->update(['profes_pueden_editar_notas' => 1]);

        $cuerpo = $this->cuerpoDeUniforme();
        $id = $this->withToken($token)->putJson('/api/uniformes/agregar', $cuerpo)->json('uniforme.id');

        $this->withToken($token)->putJson('/api/uniformes/actualizar', [
            'id' => $id, 'contrario' => 1, 'sin_uniforme' => 0, 'incompleto' => 0,
            'cabello' => 0, 'accesorios' => 0, 'camara' => 0, 'otro1' => 0, 'excusado' => 0,
            'descripcion' => 'corregido', 'fecha_hora' => '2026-08-20 08:00:00',
        ])->assertStatus(200);

        $this->assertSame('corregido',
            DB::table('uniformes')->where('id', $id)->value('descripcion'));

        $r = $this->withToken($token)->putJson('/api/uniformes/eliminar',
            ['uniforme_id' => $id] + $cuerpo);

        $r->assertStatus(200);
        $this->assertNotNull(DB::table('uniformes')->where('id', $id)->value('deleted_at'),
            'El borrado de uniformes es blando; la fila debe quedar marcada.');
        $this->assertIsInt($r->json('uniformes_count'));
    }

    /** Con el periodo cerrado a los profesores, no anota. Es para lo que existe el interruptor. */
    public function test_el_periodo_cerrado_frena_al_profesor(): void
    {
        [$token, $periodo] = $this->profesorYSuPeriodo();

        DB::table('periodos')->where('year_id', $periodo->year_id)
            ->update(['profes_pueden_editar_notas' => 0]);

        $antes = DB::selectOne('SELECT COUNT(*) c FROM uniformes')->c;

        $this->withToken($token)->putJson('/api/uniformes/agregar', $this->cuerpoDeUniforme())
            ->assertStatus(400);

        $this->assertSame($antes, DB::selectOne('SELECT COUNT(*) c FROM uniformes')->c);
    }

    /**
     * Y con el periodo cerrado, nombrar otro abierto **ya no lo abre**.
     *
     * Este test existía al revés: afirmaba el fallo a propósito y decía «el día
     * que se arregle, este test falla, y ese es su trabajo». Se arregló el 21 ago
     * 2026 y falló, así que se le da la vuelta aquí — conservando lo que medía,
     * que es lo que vale: **la respuesta y la fila escrita, no el 200**.
     *
     * `pueden_editar_notas()` miraba la bandera del periodo cuyo NÚMERO venía en
     * `num_periodo`, y el cuerpo lo elige el cliente; la escritura iba al periodo
     * del usuario. O sea que el interruptor con el que el colegio cierra el
     * periodo se abría nombrando el periodo de al lado. Ahora la bandera que se
     * comprueba es la del periodo **al que se escribe**, derivado en
     * `App\Support\PeriodoDeLaFila`. Ver 05 §27.
     */
    public function test_nombrar_otro_periodo_ya_no_abre_el_cerrado(): void
    {
        [$token, $periodo] = $this->profesorYSuPeriodo();

        DB::table('periodos')->where('year_id', $periodo->year_id)
            ->update(['profes_pueden_editar_notas' => 0]);

        $otro = DB::selectOne('SELECT id, numero FROM periodos
            WHERE year_id = ? AND id <> ? AND deleted_at IS NULL ORDER BY numero LIMIT 1',
            [$periodo->year_id, $periodo->id]);

        $this->assertNotNull($otro, 'El año del profesor tiene un solo periodo: no se puede medir esto.');

        DB::table('periodos')->where('id', $otro->id)->update(['profes_pueden_editar_notas' => 1]);

        $antes = DB::selectOne('SELECT COUNT(*) c FROM uniformes')->c;

        $this->withToken($token)->putJson('/api/uniformes/agregar',
            $this->cuerpoDeUniforme() + ['num_periodo' => $otro->numero])
            ->assertStatus(400);

        $this->assertSame($antes, DB::selectOne('SELECT COUNT(*) c FROM uniformes')->c,
            'La fila se escribió de todas formas en el periodo cerrado.');
    }

    /**
     * Y la otra mitad, que es la que impide que el arreglo sea un candado tonto:
     * nombrando el periodo abierto **y escribiendo en él**, sigue pasando.
     *
     * Es lo que la §27.1 protegía: `num_periodo` no sobra, en la rejilla de
     * definitivas es la única declaración que hay de qué periodo se edita. Si el
     * arreglo hubiera sido «ignorar `num_periodo`», esta pantalla se habría
     * apagado para los tres periodos que no son el del usuario.
     */
    public function test_escribir_en_el_periodo_abierto_sigue_pasando(): void
    {
        [$token, $periodo] = $this->profesorYSuPeriodo();

        DB::table('periodos')->where('year_id', $periodo->year_id)
            ->update(['profes_pueden_editar_notas' => 0]);

        $otro = DB::selectOne('SELECT id, numero FROM periodos
            WHERE year_id = ? AND id <> ? AND deleted_at IS NULL ORDER BY numero LIMIT 1',
            [$periodo->year_id, $periodo->id]);

        $this->assertNotNull($otro, 'El año del profesor tiene un solo periodo: no se puede medir esto.');

        DB::table('periodos')->where('id', $otro->id)->update(['profes_pueden_editar_notas' => 1]);

        $r = $this->withToken($token)->putJson('/api/uniformes/agregar',
            $this->cuerpoDeUniforme() + ['num_periodo' => $otro->numero, 'periodo_id' => $otro->id]);

        $r->assertStatus(200);
        $this->assertSame((int) $otro->id, $r->json('uniforme.periodo_id'),
            'La fila tenía que quedar en el periodo abierto, que es al que se pidió escribir.');
    }

    /** Un alumno no anota uniformes: la ruta es `auth.personal`. */
    public function test_una_familia_no_anota_uniformes(): void
    {
        foreach (['Alumno', 'Acudiente'] as $tipo) {
            $token = $this->tokenDe($this->usuarioDeTipo($tipo)->username);

            $this->withToken($token)->putJson('/api/uniformes/agregar', $this->cuerpoDeUniforme())
                ->assertStatus(403);
        }
    }

    /**
     * `uniformes/guardar-cambios` sigue rota, y su autor ya lo sabía.
     *
     * Lee `$propiedad`, `$valor`, `$user` y `$user_id` sin que existan, y tiene
     * `// No la estoy usando actualmente` encima. Está en la §6.5 y en la §7 con
     * su entrada en `phpstan.neon`; el test fija el error para que el día que se
     * arregle se note.
     */
    public function test_guardar_cambios_sigue_rota(): void
    {
        [$token, $periodo] = $this->profesorYSuPeriodo();

        DB::table('periodos')->where('id', $periodo->id)->update(['profes_pueden_editar_notas' => 1]);

        $this->withToken($token)->putJson('/api/uniformes/guardar-cambios',
            ['id' => 1, 'propiedad' => 'descripcion', 'valor' => 'x'])->assertStatus(500);
    }
}
