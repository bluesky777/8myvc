<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * La reparación de las horas escritas dos veces, y sobre todo lo que NO toca.
 *
 * La migración de esta reparación escribe en tres columnas de dos tablas vivas
 * en diecisiete colegios, con ~26.000 filas medidas. Lo que hay que fijar aquí
 * no es que repare —eso es una resta— sino **dónde se para**: una reparación de
 * datos que muerde de más no avisa, porque el resultado sigue siendo una fecha
 * válida.
 *
 * Ejecuta **las sentencias de la propia migración**, no una copia «equivalente»
 * escrita aquí: si el test tuviera su propio SQL, estaría comprobando el SQL del
 * test. Por eso la migración las expone en `sentencias()`.
 *
 * Todo corre dentro de la transacción del test, así que las filas que inserta se
 * van al terminar.
 */
class RepararLaHoraEscritaDosVecesTest extends TestCase
{
    use DatabaseTransactions;

    /** @return array<int, array{0: string, 1: string, 2: string}> */
    private function sentencias(): array
    {
        $migracion = require base_path(
            'database/migrations/2026_09_06_100000_reparar_la_hora_escrita_dos_veces.php'
        );

        return $migracion::sentencias();
    }

    private function reparar(): void
    {
        foreach ($this->sentencias() as [, $update]) {
            DB::update($update);
        }
    }

    /**
     * Mete una fila en `change_asked` con el `deleted_at` que se le diga.
     *
     * `asked_by_user_id` es la única columna obligatoria sin valor por defecto de
     * esa tabla —comprobado contra `database/schema/mysql-schema.sql`—, así que es
     * lo único que hay que rellenar. Lo que mira esta prueba es `deleted_at`.
     */
    private function pedidoConDeletedAt(?string $deletedAt): int
    {
        return (int) DB::table('change_asked')->insertGetId([
            'asked_by_user_id' => 1,
            'deleted_at' => $deletedAt,
            'created_at' => '2026-01-01 10:30:00',
            'updated_at' => '2026-01-01 10:30:00',
        ]);
    }

    private function deletedAtDe(int $id): ?string
    {
        return DB::table('change_asked')->where('id', $id)->value('deleted_at');
    }

    /**
     * El caso que da nombre a todo: 21:07:33 se guardó 21:21:07 y vuelve a 21:07.
     *
     * Los segundos salen en 00 y no en 33: se perdieron el día que se escribió,
     * no ahora. Que la prueba lo diga con el número delante es lo que evita que
     * alguien lea «reparado» como «como si no hubiera pasado».
     */
    public function test_devuelve_la_hora_y_el_minuto_y_pierde_los_segundos(): void
    {
        $id = $this->pedidoConDeletedAt('2026-03-04 21:21:07');

        $this->reparar();

        $this->assertSame('2026-03-04 21:07:00', $this->deletedAtDe($id));
    }

    /**
     * La hora de una cifra, que es la mitad que había que medir y no suponer.
     *
     * A las 09:59:01 la cadena salía `2026-03-04 9:09:59` —`G` da "9" y `H` da
     * "09"— y los dos motores la guardan como 09:09:59. La firma se mantiene y
     * la reparación también.
     */
    public function test_repara_igual_las_horas_de_una_cifra(): void
    {
        $id = $this->pedidoConDeletedAt('2026-03-04 09:09:59');

        $this->reparar();

        $this->assertSame('2026-03-04 09:59:00', $this->deletedAtDe($id));
    }

    /**
     * Correrla dos veces no mueve nada la segunda, y ESTE es el caso que lo prueba.
     *
     * 21:21:21 es la fila que una segunda pasada sin la exclusión de
     * `SECOND = 0` convertiría en 21:00:00 — reparada una vez queda 21:21:00, que
     * vuelve a cumplir `HOUR = MINUTE`. Con la exclusión, la segunda pasada la
     * deja en paz.
     */
    public function test_repetirla_no_vuelve_a_mover_la_fila_que_mas_lo_arriesga(): void
    {
        $id = $this->pedidoConDeletedAt('2026-03-04 21:21:21');

        $this->reparar();
        $primera = $this->deletedAtDe($id);

        $this->reparar();
        $segunda = $this->deletedAtDe($id);

        $this->assertSame('2026-03-04 21:21:00', $primera);
        $this->assertSame($primera, $segunda, 'la segunda pasada movió la fila');
    }

    /**
     * Una fila con SECOND = 0 no se toca, y esto es una RENUNCIA, no un acierto.
     *
     * `21:21:00` puede ser una fila dañada cuyo minuto real era 00, o una ya
     * reparada, o una sana escrita a las 21:21:00. **No se distinguen**, y la
     * migración prefiere no repararla a arriesgarse a estropearla. Se fija aquí
     * para que quede claro que las filas cuyo minuto real era `00` se quedan mal.
     */
    public function test_no_toca_las_filas_con_los_segundos_en_cero(): void
    {
        $id = $this->pedidoConDeletedAt('2026-03-04 21:21:00');

        $this->reparar();

        $this->assertSame('2026-03-04 21:21:00', $this->deletedAtDe($id));
    }

    /**
     * Una fila sana normal no se toca. Es la mayoría del mundo.
     */
    public function test_no_toca_una_fila_sana(): void
    {
        $id = $this->pedidoConDeletedAt('2026-03-04 18:14:44');

        $this->reparar();

        $this->assertSame('2026-03-04 18:14:44', $this->deletedAtDe($id));
    }

    /**
     * EL PRECIO, fijado por escrito: una fila SANA escrita a las 21:21 SÍ se mueve.
     *
     * Es el falso positivo de la firma, ~1 de cada 60, y la decisión fue
     * aceptarlo. Se comprueba **a propósito** para que nadie lo descubra en
     * producción creyendo que es un fallo: si algún día se decide no pagarlo,
     * este test se pone rojo y obliga a mirar la cabecera de la migración.
     */
    public function test_tambien_mueve_una_fila_sana_escrita_en_el_minuto_de_su_hora(): void
    {
        $id = $this->pedidoConDeletedAt('2026-03-04 21:21:35');

        $this->reparar();

        $this->assertSame(
            '2026-03-04 21:35:00',
            $this->deletedAtDe($id),
            'si esto cambia, la decisión sobre los falsos positivos cambió'
        );
    }

    /**
     * Un `deleted_at` nulo no revienta ni se convierte en una fecha.
     *
     * `HOUR(NULL)` no es comparable, así que el `IS NOT NULL` del `WHERE` es lo
     * único que separa «no aplica» de un `UPDATE` con aritmética sobre nulos.
     */
    public function test_una_fila_sin_deleted_at_se_queda_nula(): void
    {
        $id = $this->pedidoConDeletedAt(null);

        $this->reparar();

        $this->assertNull($this->deletedAtDe($id));
    }

    /**
     * Las ausencias del alta NORMAL no se tocan, y esta es la que más importa.
     *
     * En la copia de desarrollo son **52.157 filas** con `uploaded IS NULL`
     * frente a **7** del lector de tardanzas. Si el filtro se cayera, la
     * reparación pasaría de ~26.000 filas en diecisiete colegios a cientos de
     * miles, y sobre datos que nunca escribió el bug.
     */
    public function test_no_toca_las_ausencias_que_no_subio_el_lector(): void
    {
        $sana = DB::table('ausencias')->insertGetId([
            'uploaded' => null,
            'created_at' => '2026-03-04 21:21:07',
            'updated_at' => '2026-03-04 21:21:07',
        ]);
        $delLector = DB::table('ausencias')->insertGetId([
            'uploaded' => 'created',
            'created_at' => '2026-03-04 21:21:07',
            'updated_at' => '2026-03-04 21:21:07',
        ]);

        $this->reparar();

        $this->assertSame(
            '2026-03-04 21:21:07',
            DB::table('ausencias')->where('id', $sana)->value('created_at'),
            'se reparó una ausencia que no subió el lector'
        );
        $this->assertSame(
            '2026-03-04 21:07:00',
            DB::table('ausencias')->where('id', $delLector)->value('created_at')
        );
    }

    /**
     * `uploaded = 'deleted'` también entra, y por eso el filtro es `IS NOT NULL`.
     *
     * El mismo bucle de `TSubirController` marca unas filas `created` y otras
     * `deleted`, así que una fila escrita por el camino roto y borrada después
     * ya no se llama `created`. El filtro estrecho perdía el 29 % de la población
     * en la copia de desarrollo (05 §248).
     */
    public function test_tambien_repara_las_que_el_lector_marco_como_borradas(): void
    {
        $id = DB::table('ausencias')->insertGetId([
            'uploaded' => 'deleted',
            'created_at' => '2026-03-04 21:21:07',
            'updated_at' => '2026-03-04 21:21:07',
        ]);

        $this->reparar();

        $this->assertSame(
            '2026-03-04 21:07:00',
            DB::table('ausencias')->where('id', $id)->value('created_at')
        );
    }

    /**
     * `ausencias.fecha_hora` NO se toca, y es la columna que de verdad importa.
     *
     * Es la hora a la que llegó tarde el alumno. La escribe una persona y salió
     * en ruido en los diecisiete colegios (05 §250). Que una reparación de marcas
     * de auditoría acabe moviendo la hora de llegada de alguien es el peor final
     * posible de este lote, así que se fija aquí.
     */
    public function test_no_toca_la_hora_a_la_que_llego_tarde_el_alumno(): void
    {
        $id = DB::table('ausencias')->insertGetId([
            'uploaded' => 'created',
            'fecha_hora' => '2026-03-04 07:07:15',
            'created_at' => '2026-03-04 18:14:44',
            'updated_at' => '2026-03-04 18:14:44',
        ]);

        $this->reparar();

        $this->assertSame(
            '2026-03-04 07:07:15',
            DB::table('ausencias')->where('id', $id)->value('fecha_hora'),
            'la reparación tocó fecha_hora, que no es suya'
        );
    }

    /**
     * La cuenta que la migración imprime tiene que quedar en cero.
     *
     * No es adorno: la migración imprime «quedan N» después de reparar, y si ese
     * número no baja a 0 es que el `UPDATE` y el `SELECT` no están mirando la
     * misma población — que es la forma que tendría este lote de mentir con la
     * cara de haber funcionado.
     */
    public function test_despues_de_reparar_no_queda_ninguna_por_reparar(): void
    {
        $this->pedidoConDeletedAt('2026-03-04 21:21:07');
        $this->pedidoConDeletedAt('2026-03-04 09:09:59');
        DB::table('ausencias')->insert([
            'uploaded' => 'created',
            'created_at' => '2026-03-04 21:21:07',
            'updated_at' => '2026-03-04 21:21:07',
        ]);

        $this->reparar();

        foreach ($this->sentencias() as [$rotulo, , $cuenta]) {
            $this->assertSame(
                0,
                (int) DB::selectOne($cuenta)->n,
                "quedan filas por reparar en {$rotulo}"
            );
        }
    }
}
