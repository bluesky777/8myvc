<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * El nombre de columna que llega del cliente, contra el esquema de verdad.
 *
 * `ColumnaSeguraTest` fija la parte pura —qué forma tiene un nombre de columna—
 * sin base de datos. Esto comprueba lo otro: que `Schema::getColumnListing()`
 * responde lo que se espera contra el esquema real, y que un endpoint de los
 * que concatenaban rechaza el intento y sigue aceptando lo legítimo.
 *
 * Se usa `asignaturas/toggle-dia` porque su columna es literalmente un día de
 * la semana: el caso más claro de propiedad que elige el cliente.
 */
class ColumnaConcatenadaTest extends CasoDeContrato
{
    public static function intentos(): array
    {
        return [
            'otra asignación pegada' => ['lunes=:valor, creditos=99, martes'],
            'comilla para salir' => ['lunes`=1, `martes'],
            'comentario' => ['lunes=1 -- '],
            'otra sentencia' => ['lunes=1; DROP TABLE asignaturas'],
            'columna que no existe' => ['columna_inventada'],
            'columna de auditoría' => ['deleted_at'],
        ];
    }

    private function asignatura(): object
    {
        return DB::select('SELECT id, lunes, creditos FROM asignaturas
            WHERE deleted_at IS NULL ORDER BY id LIMIT 1')[0];
    }

    #[DataProvider('intentos')]
    public function test_una_propiedad_que_no_es_una_columna_no_llega_al_sql(string $dia): void
    {
        $token = $this->tokenDe($this->usuarioDeTipo('Usuario')->username);
        $asignatura = $this->asignatura();

        $this->putJson('/api/asignaturas/toggle-dia', [
            'asignatura_id' => $asignatura->id,
            'dia' => $dia,
            'valor' => 1,
        ], ['Authorization' => 'Bearer '.$token])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Propiedad no válida.');

        $despues = DB::select('SELECT creditos FROM asignaturas WHERE id = ?', [$asignatura->id])[0];

        $this->assertSame((int) $asignatura->creditos, (int) $despues->creditos,
            'Ninguna otra columna debe haberse tocado.');
    }

    public function test_una_columna_de_verdad_sigue_funcionando(): void
    {
        $token = $this->tokenDe($this->usuarioDeTipo('Usuario')->username);
        $asignatura = $this->asignatura();
        $nuevo = ((int) $asignatura->lunes) === 1 ? 0 : 1;

        $this->putJson('/api/asignaturas/toggle-dia', [
            'asignatura_id' => $asignatura->id,
            'dia' => 'lunes',
            'valor' => $nuevo,
        ], ['Authorization' => 'Bearer '.$token])
            ->assertStatus(200);

        $this->assertSame($nuevo, (int) DB::select(
            'SELECT lunes FROM asignaturas WHERE id = ?', [$asignatura->id])[0]->lunes);
    }
}
