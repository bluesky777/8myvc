<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * El comando que busca alumnos sin matrícula viva en un año que sí tuvieron.
 *
 * Igual que `AniosActualesTest`, no comprueba que el comando esté bien:
 * comprueba que **distingue los tres casos**, que es para lo que se va a correr
 * en dieciséis bases. Y el caso que más importa es el segundo — hay matrículas
 * en la papelera pero nadie se quedó fuera— porque es el que un diagnóstico
 * perezoso confundiría con el tercero y haría revisar a mano dieciséis colegios
 * para nada.
 *
 * Ver docs/migracion/12-larastan-nivel-7.md §1.
 */
class MatriculasHuerfanasTest extends CasoDeContrato
{
    /**
     * Un alumno del seed, el año en que está matriculado y sus matrículas de ese año.
     *
     * El año sale del grupo: `matriculas` no tiene `year_id`. Y se pide el
     * alumno **por su año concreto** porque el del seed está matriculado en dos
     * —uno por año—, que es la trampa que ya costó 36 rutas mal medidas en
     * 05 §16.
     */
    private function alumnoConSuAnio(): object
    {
        $grupo = $this->grupoConAlumnos();

        $fila = DB::selectOne('SELECT m.alumno_id, g.year_id FROM matriculas m
            INNER JOIN grupos g ON g.id = m.grupo_id
            INNER JOIN alumnos a ON a.id = m.alumno_id AND a.deleted_at IS NULL
            WHERE m.grupo_id = ? AND m.deleted_at IS NULL ORDER BY m.id LIMIT 1', [$grupo->id]);

        $this->assertNotNull($fila, "El grupo {$grupo->id} no tiene matrículas vivas.");

        return $fila;
    }

    /** Manda a la papelera todas las matrículas de ese alumno en ese año. */
    private function aLaPapelera(int $alumnoId, int $yearId): int
    {
        return DB::update('UPDATE matriculas m
            INNER JOIN grupos g ON g.id = m.grupo_id
            SET m.deleted_at = NOW()
            WHERE m.alumno_id = ? AND g.year_id = ? AND m.deleted_at IS NULL',
            [$alumnoId, $yearId]);
    }

    public function test_sin_nada_en_la_papelera_dice_que_el_fallo_no_se_disparo(): void
    {
        DB::update('UPDATE matriculas SET deleted_at = NULL WHERE deleted_at IS NOT NULL');

        $this->comando('matriculas:huerfanas')
            ->expectsOutputToContain('el fallo de matricularUno no se')
            ->assertExitCode(0);
    }

    /**
     * Con una matrícula borrada pero otra viva del mismo año, el alumno no está fuera.
     *
     * Es el caso que separa un diagnóstico útil de uno que solo hace ruido: la
     * papelera de `matriculas` se llena por operaciones legítimas —cambiar de
     * grupo deja la anterior borrada— y contar eso como daño mandaría al colegio
     * a revisar alumnos que están perfectamente matriculados.
     */
    public function test_con_una_borrada_pero_otra_viva_del_mismo_ano_no_sale_nadie(): void
    {
        DB::update('UPDATE matriculas SET deleted_at = NULL WHERE deleted_at IS NOT NULL');

        $alumno = $this->alumnoConSuAnio();

        // Cualquier grupo de ese año sirve: lo que hace el caso no es de qué
        // grupo cuelga la borrada, sino que en ese año quede también una viva.
        $grupo = DB::selectOne('SELECT id FROM grupos WHERE year_id = ? AND deleted_at IS NULL
            ORDER BY id LIMIT 1', [$alumno->year_id]);

        $this->assertNotNull($grupo, "El año {$alumno->year_id} no tiene grupos vivos.");

        // Una segunda matrícula del mismo año, ya en la papelera: es lo que deja
        // atrás un cambio de grupo.
        DB::table('matriculas')->insert([
            'alumno_id' => $alumno->alumno_id,
            'grupo_id' => $grupo->id,
            'estado' => 'MATR',
            'deleted_at' => now(),
        ]);

        $this->comando('matriculas:huerfanas')
            ->expectsOutputToContain('ningún alumno se quedó sin ninguna')
            ->assertExitCode(0);
    }

    public function test_con_todas_en_la_papelera_lo_lista_y_falla(): void
    {
        DB::update('UPDATE matriculas SET deleted_at = NULL WHERE deleted_at IS NOT NULL');

        $alumno = $this->alumnoConSuAnio();

        $borradas = $this->aLaPapelera((int) $alumno->alumno_id, (int) $alumno->year_id);
        $this->assertGreaterThan(0, $borradas, 'No se pudo dejar al alumno sin matrículas vivas.');

        $this->comando('matriculas:huerfanas')
            ->expectsOutputToContain('no salen hoy en ninguna lista')
            ->assertExitCode(1);
    }

    /** El `--year` acota, que es lo que permite mirar solo el año en curso. */
    public function test_el_filtro_de_ano_deja_fuera_los_demas(): void
    {
        DB::update('UPDATE matriculas SET deleted_at = NULL WHERE deleted_at IS NOT NULL');

        $alumno = $this->alumnoConSuAnio();
        $this->aLaPapelera((int) $alumno->alumno_id, (int) $alumno->year_id);

        $otroAnio = DB::selectOne('SELECT year FROM years WHERE id != ? AND deleted_at IS NULL
            ORDER BY id LIMIT 1', [$alumno->year_id]);

        $this->assertNotNull($otroAnio, 'El seed necesita al menos dos años.');

        $this->comando('matriculas:huerfanas', ['--year' => $otroAnio->year])
            ->expectsOutputToContain('ningún alumno se quedó sin ninguna')
            ->assertExitCode(0);
    }
}
