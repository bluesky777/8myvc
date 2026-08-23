<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * §143–145 — `PUT matriculas/prematricular`: **la única escritura que alcanza una
 * familia**.
 *
 * Lo encontró el lote I contestando otra pregunta. Las demás rutas que un
 * acudiente toca son lecturas; ésta escribe en `matriculas`, que es la tabla que
 * dice si un alumno está en el colegio y en qué grupo.
 *
 * **Lo que sí funciona, y hay que decirlo primero**: el guard
 * `boletin.propio:sin-paz-y-salvo` comprueba **de quién es el alumno**, y lo hace
 * bien — con un alumno ajeno son 403. Lo que nadie comprueba es **de qué año es el
 * grupo** ni **qué estado se pide**, y las dos cosas vienen del cuerpo.
 *
 * ## Las tres cosas, y solo una se arregla aquí
 *
 * | § | Qué | Qué se hace |
 * |---|---|---|
 * | §143 | Un acudiente cambia la matrícula **del año en curso** de su propio acudido, con el `estado` que mande | **medido y fijado** — es regla de negocio |
 * | §144 | Contestaba **500 después de haber escrito** | **arreglado**: 404, y el mensaje dice que la escritura se hizo |
 * | §145 | Desde la pantalla real **no se alcanza ninguna de las dos** | medido en el front |
 *
 * Las dos primeras **no van en el mismo commit a propósito**: la del año la decide
 * el colegio, y meterlas juntas haría indesplegable la del 500, que es la que se
 * puede desplegar sola.
 */
class PrematricularDeUnaFamiliaTest extends CasoDeContrato
{
    /** Un acudiente con cuenta y una matrícula viva de un acudido suyo. */
    private function acudienteConAcudidoMatriculado(): object
    {
        $fila = DB::selectOne('SELECT u.username, ac.id AS acudiente_id, a.id AS alumno_id,
                m.id AS matricula_id, m.grupo_id, g.year_id
            FROM acudientes ac
            INNER JOIN users u ON u.id = ac.user_id AND u.is_active = 1 AND u.deleted_at IS NULL
            INNER JOIN parentescos pa ON pa.acudiente_id = ac.id AND pa.deleted_at IS NULL
            INNER JOIN alumnos a ON a.id = pa.alumno_id AND a.deleted_at IS NULL
            INNER JOIN matriculas m ON m.alumno_id = a.id AND m.deleted_at IS NULL
            INNER JOIN grupos g ON g.id = m.grupo_id AND g.deleted_at IS NULL
            INNER JOIN years y ON y.id = g.year_id AND y.deleted_at IS NULL
            WHERE ac.deleted_at IS NULL AND u.tipo = "Acudiente" ORDER BY m.id LIMIT 1');

        $this->assertNotNull($fila, 'El seed necesita un acudiente con un acudido matriculado.');

        return $fila;
    }

    /**
     * §144 — Contestaba **500 después de haber escrito**, y ahora contesta 404.
     *
     * La consulta final busca la matrícula en el año `$user->year + $anio_sig`, y
     * ese año puede no existir: en el seed, **2026 está en la papelera**. Así que
     * el `[0]` caía sobre una consulta vacía **con la fila ya cambiada**.
     *
     * > El 500 **no acusaba a la ruta**: acusaba al año que falta. Lo que acusa a
     * > la ruta es **lo que ya escribió antes de llegar ahí**, y eso el código de
     * > salida no lo arregla — por eso el mensaje lo dice.
     *
     * Es la peor forma posible en esta ruta en concreto, porque es la única que
     * alcanza una familia: `AnunciosDir.ts` lee `r.matricula.prematriculado`
     * **dentro del `.then`**, así que con un error no llega nunca y lo natural es
     * volver a darle al botón.
     */
    public function test_sin_el_ano_siguiente_contesta_404_y_no_500(): void
    {
        $ctx = $this->acudienteConAcudidoMatriculado();
        $token = $this->tokenDe($ctx->username);

        $antes = DB::table('matriculas')->where('id', $ctx->matricula_id)->first();

        $r = $this->withToken($token)->putJson('/api/matriculas/prematricular',
            ['alumno_id' => $ctx->alumno_id, 'grupo_id' => $ctx->grupo_id]);

        $r->assertStatus(404);
        $this->assertStringContainsString('se guardó', (string) $r->json('message'),
            'El mensaje tiene que decir que la escritura sí ocurrió: es lo único que el código de estado no cuenta.');

        // Y la otra mitad del mismo hecho, que es la que importa: escribió.
        $despues = DB::table('matriculas')->where('id', $ctx->matricula_id)->first();
        $this->assertNotEquals($antes->estado, $despues->estado,
            'La prematrícula se hizo. El 404 habla de la respuesta, no de la escritura.');
        $this->assertSame('PREM', $despues->estado);
        $this->assertNotNull($despues->prematriculado);
    }

    /**
     * Y el camino bueno sigue devolviendo la matrícula, con su forma entera.
     *
     * `anio_sig` sale del cuerpo con defecto 1, así que mandando `0` la consulta
     * final mira el año en curso —donde sí hay matrícula— y contesta 200. Queda
     * fijado porque **es el cliente quien elige en qué año se busca la respuesta**,
     * y eso no se ve leyendo la ruta.
     */
    public function test_el_camino_bueno_devuelve_la_matricula(): void
    {
        $ctx = $this->acudienteConAcudidoMatriculado();

        $r = $this->withToken($this->tokenDe($ctx->username))->putJson('/api/matriculas/prematricular',
            ['alumno_id' => $ctx->alumno_id, 'grupo_id' => $ctx->grupo_id, 'anio_sig' => 0]);

        $r->assertStatus(200);
        $this->assertSame(
            ['matricula_id', 'alumno_id', 'no_matricula', 'nombres', 'apellidos', 'grupo_nombre',
                'grupo_abrev', 'estado', 'nuevo', 'repitente', 'prematriculado', 'fecha_matricula',
                'year_id', 'year'],
            array_keys($r->json('matricula')),
            'La forma de la respuesta es contrato: `AnunciosDir.ts` lee `matricula.prematriculado`.');
    }

    /**
     * §143 — Lo que el guard **no** mira: el año del grupo y el estado pedido.
     *
     * `boletin.propio:sin-paz-y-salvo` comprueba de quién es el alumno y lo hace
     * bien. Pero `grupo_id` y `estado` vienen del cuerpo, y **la escritura va al
     * año de ese grupo**. Con su propio acudido —justo lo que el guard permite— un
     * acudiente le pone a la matrícula **del año en curso** cualquiera de los cinco
     * estados, incluido **`MATR` con su `fecha_matricula`**.
     *
     * **No se arregla**: que el grupo tenga que ser del año siguiente es una regla
     * de negocio y la decide el colegio. Se mide y se fija, que es lo que hay que
     * tener delante el día que se decida.
     */
    public function test_una_familia_escribe_cualquier_estado_en_el_ano_en_curso(): void
    {
        $ctx = $this->acudienteConAcudidoMatriculado();
        $token = $this->tokenDe($ctx->username);

        $anioDelGrupo = (int) DB::table('years')->where('id', $ctx->year_id)->value('year');
        $anioDelToken = (int) DB::table('years')
            ->where('id', DB::table('periodos')
                ->where('id', DB::table('users')->where('username', $ctx->username)->value('periodo_id'))
                ->value('year_id'))->value('year');

        foreach (['MATR' => 'fecha_matricula', 'PREA' => 'prematriculado', 'ASIS' => null, 'FORM' => null] as $estado => $columna) {
            DB::table('matriculas')->where('id', $ctx->matricula_id)
                ->update(['estado' => 'RETI', 'fecha_matricula' => null, 'prematriculado' => null]);

            $this->withToken($token)->putJson('/api/matriculas/prematricular', [
                'alumno_id' => $ctx->alumno_id,
                'grupo_id' => $ctx->grupo_id,
                'estado' => $estado,
                'anio_sig' => 0,
            ])->assertStatus(200);

            $fila = DB::table('matriculas')->where('id', $ctx->matricula_id)->first();

            $this->assertSame($estado, $fila->estado,
                "Un acudiente escribe el estado `{$estado}` en la matrícula de su acudido.");
            if ($columna !== null) {
                $this->assertNotNull($fila->{$columna}, "Y con su `{$columna}` puesta.");
            }
            $this->assertSame((int) DB::table('users')->where('username', $ctx->username)->value('id'),
                (int) $fila->updated_by, 'Firmada por el acudiente, que es lo que la hace localizable.');
        }

        // Lo que hace que esto importe: el grupo es del año EN CURSO o anterior,
        // no del siguiente. Y partiendo de `RETI`, la llamada **deshace un retiro**.
        $this->assertLessThanOrEqual($anioDelToken, $anioDelGrupo,
            'Si esto cambia, el seed dejó de tener el caso y el test ya no mide lo que dice.');
    }

    /** Lo que el guard SÍ hace, y hay que fijar para que no se toque: un alumno ajeno, 403. */
    public function test_un_acudiente_no_toca_la_matricula_de_otro(): void
    {
        $ctx = $this->acudienteConAcudidoMatriculado();

        $ajeno = DB::selectOne('SELECT a.id FROM alumnos a WHERE a.deleted_at IS NULL
            AND a.id NOT IN (SELECT alumno_id FROM parentescos WHERE acudiente_id = ? AND deleted_at IS NULL)
            ORDER BY a.id LIMIT 1', [$ctx->acudiente_id]);
        $this->assertNotNull($ajeno, 'El seed necesita un alumno que no sea de ese acudiente.');

        $antes = DB::table('matriculas')->where('alumno_id', $ajeno->id)->get()->toArray();

        $this->withToken($this->tokenDe($ctx->username))->putJson('/api/matriculas/prematricular',
            ['alumno_id' => $ajeno->id, 'grupo_id' => $ctx->grupo_id])->assertStatus(403);

        $this->assertEquals($antes, DB::table('matriculas')->where('alumno_id', $ajeno->id)->get()->toArray());
    }

    /** Y un grupo que no existe se para **antes** de escribir, que es lo correcto. */
    public function test_un_grupo_que_no_existe_se_para_antes_de_escribir(): void
    {
        $ctx = $this->acudienteConAcudidoMatriculado();
        $antes = DB::table('matriculas')->where('id', $ctx->matricula_id)->first();

        // 400 y no 404: es el mensaje que ya devolvía, y lo lee el front.
        $this->withToken($this->tokenDe($ctx->username))->putJson('/api/matriculas/prematricular',
            ['alumno_id' => $ctx->alumno_id, 'grupo_id' => ((int) DB::table('grupos')->max('id')) + 1000])
            ->assertStatus(400);

        $this->assertEquals($antes, DB::table('matriculas')->where('id', $ctx->matricula_id)->first(),
            'La comprobación del grupo va delante de las escrituras, y ahí sí está bien puesta.');
    }
}
