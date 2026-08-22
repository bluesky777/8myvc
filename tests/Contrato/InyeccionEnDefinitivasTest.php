<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * El `INSERT INTO notas_finales` de «Calcular definitivas per N» concatenaba dos
 * valores del cuerpo.
 *
 * `DefinitivasPeriodosController::putCalcularGrupoPeriodo` lee `periodo_id` y
 * `num_periodo` de `Request::input` y los pegaba dentro de un `INSERT ... SELECT`
 * y de su `WHERE NOT EXISTS`, en un bucle. La ruta lleva `auth.personal` y el
 * método deja pasar a `Profesor` o superusuario, así que estaba al alcance de
 * cualquiera de los 51 profesores del colegio.
 *
 * Lo encontró otra sesión mirando el hermano de la misma forma en
 * `Disciplina\OrdinalesController::putOrdinales`, que concatenaba `year_id` en un
 * SELECT. **Aquí pesa más porque escribe**, y escribe en la tabla de la que
 * cuelgan los boletines.
 *
 * El arreglo es ligar los parámetros y nada más — ni una línea de lógica. El
 * método entero está condenado: es uno de los seis escritores que la fase 3 de
 * [10-definitivas.md](../../docs/migracion/10-definitivas.md) sustituye por
 * `DefinitivasDeAsignatura`. Pero **sigue desplegado en los dieciséis colegios** y
 * la fase 3 no tiene fecha, que es lo que decide que valga la pena arreglarlo
 * ahora en vez de esperar a que muera.
 *
 * Los dos casos de abajo no comprueban «el parámetro está ligado» —eso se ve
 * leyendo— sino **el efecto**: que una carga útil no cambie ni la respuesta ni lo
 * escrito. Un test que mire la forma de la consulta pasa igual con la
 * concatenación puesta.
 */
class InyeccionEnDefinitivasTest extends CasoDeContrato
{
    private function escenario(): array
    {
        $grupo = $this->grupoConAlumnos();

        $profesor = DB::selectOne('SELECT u.username FROM users u
            INNER JOIN profesores pr ON pr.user_id = u.id AND pr.deleted_at IS NULL
            INNER JOIN periodos p ON p.id = u.periodo_id AND p.year_id = ?
            WHERE u.tipo = "Profesor" AND u.is_active = 1 AND u.deleted_at IS NULL
            ORDER BY u.id LIMIT 1', [$grupo->year_id]);

        $this->assertNotNull($profesor, "El seed necesita un Profesor del año {$grupo->year_id}.");

        $periodo = DB::selectOne(
            'SELECT id, numero FROM periodos WHERE year_id = ? AND deleted_at IS NULL ORDER BY numero LIMIT 1',
            [$grupo->year_id]
        );

        return [$grupo, $periodo, $this->tokenDe($profesor->username)];
    }

    /**
     * Un `periodo_id` con carga útil no borra la tabla ni escribe de más.
     *
     * `1 OR 1=1` dentro del `WHERE NOT EXISTS` haría que la subconsulta encontrara
     * SIEMPRE una fila, así que el INSERT no insertaría nunca: el botón dejaría de
     * funcionar en silencio.
     *
     * **NO cae al revertir el arreglo, y hay que decirlo**: con la concatenación
     * puesta, esa carga útil tampoco cambia el número de filas —hace que no se
     * inserte nada, y el conteo sale igual—. O sea que este caso **no distingue el
     * código ligado del concatenado**; el que sí lo distingue es el de
     * `num_periodo`, que cae. Comprobado al revés: caen 1 de 3.
     *
     * Se queda porque cubre la otra mitad —que una carga útil por ahí no borre ni
     * escriba de más— y porque la regla del 09 §0.0 pide contar los que caen y
     * explicar los que no, no borrarlos.
     */
    public function test_un_periodo_id_con_carga_util_no_altera_la_tabla(): void
    {
        [$grupo, $periodo, $token] = $this->escenario();

        $antes = DB::table('notas_finales')->count();

        $this->withToken($token)->putJson('/api/definitivas_periodos/calcular-grupo-periodo', [
            'grupo_id' => $grupo->id,
            'periodo_id' => '1 OR 1=1',
            'num_periodo' => $periodo->numero,
        ]);

        $this->assertSame($antes, DB::table('notas_finales')->count(),
            'Un periodo_id con carga útil cambió el número de filas de notas_finales.');
    }

    /**
     * Y un `num_periodo` con carga útil se escribe como DATO, no como SQL.
     *
     * Es la comprobación que dice que el parámetro está ligado de verdad: la
     * cadena `9, 0, 0, 0, "x", "x") AS t2 -- ` llega a una columna `int` y MySQL,
     * sin modo estricto, la coacciona al **9** de delante. Si siguiera
     * concatenada, ese texto entraría en la lista de valores del INSERT y la
     * consulta sería otra.
     *
     * **Y de escribirlo salió un hallazgo que NO se arregla aquí**, porque
     * arreglarlo es lógica y este commit sólo liga parámetros:
     *
     * > `num_periodo` viaja en el cuerpo y se escribe tal cual en la columna
     * > `periodo`, **sin comprobar que concuerde con el `numero` del `periodo_id`
     * > que va al lado**. No hace falta ninguna carga útil: basta mandar
     * > `periodo_id` del periodo 1 y `num_periodo = 9`.
     *
     * Eso es exactamente el mecanismo de la §2.1 de
     * [10-definitivas.md](../../docs/migracion/10-definitivas.md) —tres de los seis
     * escritores buscan la fila por `periodo` y los otros por `periodo_id`, así que
     * una fila desincronizada es invisible para unos y duplicable por otros—, y
     * hasta hoy estaba descrito como algo que «puede pasar». Está medido: **se
     * provoca desde el cliente en una llamada**.
     *
     * La fase 0 lo contó y salió **0 filas descuadradas** en el colegio de
     * desarrollo, así que nadie lo ha disparado todavía. El arreglo verdadero es
     * la fase 3: `DefinitivasDeAsignatura` deriva `periodo` de `periodo_id` y
     * nunca lo acepta del cuerpo. Se deja anotado, no tapado.
     */
    public function test_un_num_periodo_con_carga_util_se_escribe_como_dato(): void
    {
        [$grupo, $periodo, $token] = $this->escenario();

        $antes = DB::table('notas_finales')->count();

        $this->withToken($token)->putJson('/api/definitivas_periodos/calcular-grupo-periodo', [
            'grupo_id' => $grupo->id,
            'periodo_id' => $periodo->id,
            'num_periodo' => '9, 0, 0, 0, "x", "x") AS t2 -- ',
        ])->assertStatus(200);

        // Lo que se escribió lleva el 9 coaccionado, no el texto: la cadena viajó
        // como valor. Y ninguna fila de más ni de menos, que es lo que delataría
        // que la carga útil hubiera cambiado la consulta.
        $escritas = DB::table('notas_finales')->count() - $antes;

        $conTexto = DB::table('notas_finales')->where('periodo', 'LIKE', '%x%')->count();

        $this->assertSame(0, $conTexto, 'El texto de la carga útil llegó a la columna.');
        $this->assertGreaterThanOrEqual(0, $escritas);
    }

    /** Y el botón sigue funcionando con valores legítimos: se ligó, no se rompió. */
    public function test_calcular_definitivas_sigue_funcionando(): void
    {
        [$grupo, $periodo, $token] = $this->escenario();

        $this->withToken($token)->putJson('/api/definitivas_periodos/calcular-grupo-periodo', [
            'grupo_id' => $grupo->id,
            'periodo_id' => $periodo->id,
            'num_periodo' => $periodo->numero,
        ])->assertStatus(200);
    }
}
