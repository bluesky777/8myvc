<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * Re-matricular a un alumno que estaba en la papelera.
 *
 * `Matricula::matricularUno()` tiene dos bucles casi idénticos: el primero
 * busca las matrículas **borradas** del alumno en ese año para revivir una, y el
 * segundo hace lo mismo con las vivas cuando el alumno cambia de grupo. El
 * primero llevaba `$matricula->nro_folio` donde el segundo lleva
 * `$matri->nro_folio` —una copia con el nombre sin cambiar—, y `$matricula` vale
 * `false` hasta que el bucle consigue revivir alguna.
 *
 * Lo que hacía por eso, según la versión de PHP:
 *
 *   - **PHP 7**: asignar una propiedad a `false` convertía `$matricula` en un
 *     `stdClass` vacío. Como un objeto es *truthy*, el `if ($matricula)` de la
 *     línea siguiente entraba siempre por la rama de «esta ya sobra, bórrala»,
 *     así que **ninguna matrícula se revivía nunca** y todas volvían a la
 *     papelera. Y al final `!$matricula` era falso, así que tampoco se creaba
 *     una nueva: el alumno se quedaba **sin matrícula** y la API respondía 200
 *     con un objeto de una sola propiedad.
 *   - **PHP 8**: la misma asignación es `Error: Attempt to assign property
 *     "nro_folio" on false`. O sea **500**.
 *
 * Este test fija el comportamiento que se quiere: la matrícula que estaba en la
 * papelera vuelve, con su estado y su folio. Ver
 * docs/migracion/12-larastan-nivel-7.md §1.
 */
class MatriculaReactivadaTest extends CasoDeContrato
{
    private function superusuario(int $yearId): string
    {
        $fila = DB::selectOne('SELECT u.username FROM users u
            INNER JOIN periodos p ON p.id = u.periodo_id AND p.deleted_at IS NULL
            WHERE u.is_superuser = 1 AND u.is_active = 1 AND u.deleted_at IS NULL
              AND p.year_id = ? ORDER BY u.id LIMIT 1', [$yearId]);

        $this->assertNotNull($fila, "El seed no tiene ningún superusuario en el año {$yearId}.");

        return $this->tokenDe($fila->username);
    }

    public function test_matricular_a_quien_tenia_la_matricula_en_la_papelera_la_revive(): void
    {
        $grupo = $this->grupoConAlumnos();
        $token = $this->superusuario((int) $grupo->year_id);

        $matricula = DB::selectOne('SELECT m.id, m.alumno_id FROM matriculas m
            INNER JOIN alumnos a ON a.id = m.alumno_id AND a.deleted_at IS NULL
            WHERE m.grupo_id = ? AND m.deleted_at IS NULL
              AND m.estado IN ("MATR","ASIS","PREM") ORDER BY m.id LIMIT 1', [$grupo->id]);

        $this->assertNotNull($matricula, "El grupo {$grupo->id} no tiene matrículas vivas.");

        // A la papelera, y sin folio: es el estado del que sale el alumno que
        // se retiró y vuelve, que es para lo que existe el primer bucle.
        DB::update('UPDATE matriculas SET deleted_at = NOW(), estado = "RETI", nro_folio = NULL
            WHERE id = ?', [$matricula->id]);

        $this->postJson('/api/matriculas/matricularuno', [
            'alumno_id' => $matricula->alumno_id,
            'grupo_id' => $grupo->id,
            'year_id' => $grupo->year_id,
        ], ['Authorization' => 'Bearer '.$token])->assertStatus(200);

        $fila = DB::selectOne('SELECT deleted_at, estado, nro_folio FROM matriculas WHERE id = ?',
            [$matricula->id]);

        $this->assertNull($fila->deleted_at, 'La matrícula siguió en la papelera.');
        $this->assertSame('MATR', $fila->estado, 'La matrícula revivió pero no quedó matriculada.');

        // **Y el folio NO se le pone, que es lo contrario de lo que este test pedía.**
        // Decisión de Joseth del 26 ago 2026: `{año}-{alumno_id}` **no es un folio** —un
        // folio es la hoja del libro de matrículas, y lo que se imprime en la constancia
        // está para que quien la lea vaya a comprobarla al archivo—. Medido: 1.612
        // fabricados así y **257 que nombran a otro alumno**
        // (docs/migracion/21-certificados-y-folios.md §2.2).
        //
        // El aserto se conserva **dado la vuelta** en vez de borrarse: esta clase existe
        // por un 500 en la rama de la papelera, y que esa rama toque o no el folio es
        // justo lo que hay que poder leer aquí dentro de seis meses.
        $this->assertNull($fila->nro_folio,
            'Revivir la matrícula le fabricó el folio «'.$fila->nro_folio.'», y nadie lo '
            .'escribió. Se llena a mano o se queda vacío; lo vigila `FolioQueNoSeFabricaTest`.');
    }

    /**
     * Un `year_id` que no existe es 404, y no una matrícula con el folio «-1234».
     *
     * El folio es `{año}-{alumno_id}` y es lo que el colegio escribe en el libro
     * de matrícula. `year_id` llega del cuerpo de la petición sin validar, y con
     * `Year::find()` un año inexistente devolvía null: `$year->year` daba un aviso
     * de PHP, la matrícula se creaba igual y el folio salía empezando por un
     * guion. Un dato malo escrito en silencio es peor que un error, que es la
     * misma lección de la §1.
     */
    public function test_un_ano_que_no_existe_no_crea_una_matricula_con_folio_roto(): void
    {
        $grupo = $this->grupoConAlumnos();
        $token = $this->superusuario((int) $grupo->year_id);

        $alumno = DB::selectOne('SELECT m.alumno_id FROM matriculas m
            WHERE m.grupo_id = ? AND m.deleted_at IS NULL ORDER BY m.id LIMIT 1', [$grupo->id]);

        $inexistente = ((int) DB::selectOne('SELECT MAX(id) m FROM years')->m) + 1000;

        $antes = (int) DB::selectOne('SELECT COUNT(*) n FROM matriculas')->n;

        $this->postJson('/api/matriculas/matricularuno', [
            'alumno_id' => $alumno->alumno_id,
            'grupo_id' => $grupo->id,
            'year_id' => $inexistente,
        ], ['Authorization' => 'Bearer '.$token])->assertStatus(404);

        $this->assertSame($antes, (int) DB::selectOne('SELECT COUNT(*) n FROM matriculas')->n,
            'Se creó una matrícula contra un año que no existe.');

        $this->assertSame(0, (int) DB::selectOne('SELECT COUNT(*) n FROM matriculas
            WHERE nro_folio LIKE "-%"')->n, 'Quedó una matrícula con el folio empezando por guion.');
    }
}
