<?php

namespace Tests\Contrato;

use App\Services\BoletinIndependiente;
use Illuminate\Support\Facades\DB;

/**
 * El servicio que decide de quién es una unidad, en sus dos sentidos.
 *
 * Fase 1 de [19-boletin-independiente.md](../../docs/migracion/19-boletin-independiente.md)
 * y §2 de [noche-2026-08-24/bi-1.md](../../docs/migracion/noche-2026-08-24/bi-1.md).
 *
 * **Lo que este test protege no es el servicio: es que la fase 1 sea aditiva.**
 * `alcance()` devuelve `null` para todo el mundo mientras nadie esté marcado, y
 * `u.alumno_id <=> NULL` selecciona exactamente las filas de siempre. Si algún
 * día ese `null` se convierte en un id por defecto —un `COALESCE` de más, un
 * `boletin_independiente` que nace en 1 en un colegio— **las 309 unidades de esta
 * base dejan de encontrarse y todas las definitivas se van a 0**, sin un solo
 * error en el log. Por eso el primer test es el del caso vacío, que es el que
 * parece que no comprueba nada.
 *
 * ## Por qué se prueba el servicio y no una respuesta HTTP
 *
 * Contra la costumbre de `tests/Contrato/`, que mira el resultado y no el estado.
 * Aquí la razón es que **en la fase 1 no hay ninguna respuesta que mirar**: no
 * existen las tres rutas, ni el `case` de `guardar-valor`, ni los campos de la
 * §6.4. La respuesta que sí se puede mirar —que ninguna cambie— es *toda la
 * suite*, y ése es el criterio de aceptación de la §4, no un test.
 *
 * Lo que este fichero añade es la mitad que la suite **no** puede ver: cómo se
 * comporta el servicio **con alguien marcado**, que hoy no le pasa a nadie.
 */
class BolIndependienteAlcanceTest extends CasoDeContrato
{
    protected function setUp(): void
    {
        parent::setUp();

        // La memoria del servicio es por petición, y una suite es un proceso:
        // sin esto, el primer test que marque a alguien le deja la respuesta
        // cacheada al siguiente y los dos pasan por la razón equivocada.
        BoletinIndependiente::olvidar();
    }

    /** Un (alumno, periodo) del seed que exista de verdad, con su matrícula viva. */
    private function unAlumnoMatriculado(): object
    {
        $fila = DB::select(
            'SELECT m.alumno_id, m.id AS matricula_id, p.id AS periodo_id
             FROM matriculas m
             INNER JOIN grupos g   ON g.id = m.grupo_id AND g.deleted_at IS NULL
             INNER JOIN periodos p ON p.year_id = g.year_id AND p.deleted_at IS NULL
             WHERE m.deleted_at IS NULL AND m.estado IN ("MATR", "ASIS")
             ORDER BY m.id
             LIMIT 1'
        );

        $this->assertNotEmpty($fila,
            'El seed no tiene ninguna matrícula viva con periodo: sin fila este test no comprueba nada.');

        return $fila[0];
    }

    public function test_sin_nadie_marcado_el_alcance_es_null_para_todos(): void
    {
        $marcadas = DB::selectOne('SELECT COUNT(*) AS n FROM matriculas WHERE boletin_independiente = 1')->n;

        $this->assertSame(0, (int) $marcadas,
            'La base de test nace con nadie marcado. Si esto falla, el resto de la suite '
            .'está midiendo otra cosa y el criterio de aceptación de la §4 no significa nada.');

        $a = $this->unAlumnoMatriculado();

        $this->assertNull(
            BoletinIndependiente::alcance((int) $a->alumno_id, (int) $a->periodo_id),
            'Con nadie marcado el alcance tiene que ser null: es lo que hace que '
            .'`u.alumno_id <=> :alcance` seleccione las unidades del grupo, o sea todas las de hoy.'
        );
        $this->assertFalse(BoletinIndependiente::aplica((int) $a->alumno_id, (int) $a->periodo_id));
    }

    public function test_marcado_en_la_matricula_el_alcance_es_su_propio_id(): void
    {
        $a = $this->unAlumnoMatriculado();

        DB::update('UPDATE matriculas SET boletin_independiente = 1 WHERE id = ?', [$a->matricula_id]);
        BoletinIndependiente::olvidar();

        $this->assertSame((int) $a->alumno_id,
            BoletinIndependiente::alcance((int) $a->alumno_id, (int) $a->periodo_id));
        $this->assertTrue(BoletinIndependiente::aplica((int) $a->alumno_id, (int) $a->periodo_id));
    }

    /**
     * El interruptor por periodo, que es la petición que decidió el diseño:
     * «este periodo no tiene boletín independiente» **sin borrar nada**.
     */
    public function test_aplica_cero_en_un_periodo_lo_devuelve_al_grupo_sin_borrar_nada(): void
    {
        $a = $this->unAlumnoMatriculado();

        DB::update('UPDATE matriculas SET boletin_independiente = 1 WHERE id = ?', [$a->matricula_id]);

        $antes = [
            'unidades' => DB::selectOne('SELECT COUNT(*) AS n FROM unidades')->n,
            'subunidades' => DB::selectOne('SELECT COUNT(*) AS n FROM subunidades')->n,
            'notas' => DB::selectOne('SELECT COUNT(*) AS n FROM notas')->n,
        ];

        DB::insert(
            'INSERT INTO bol_ind_periodos (alumno_id, periodo_id, aplica, created_at, updated_at)
             VALUES (?, ?, 0, NOW(), NOW())
             ON DUPLICATE KEY UPDATE aplica = 0',
            [$a->alumno_id, $a->periodo_id]
        );
        BoletinIndependiente::olvidar();

        $this->assertNull(
            BoletinIndependiente::alcance((int) $a->alumno_id, (int) $a->periodo_id),
            'Con `aplica = 0` el alumno vuelve a las unidades del grupo en ESE periodo, '
            .'aunque su matrícula siga marcada.'
        );

        // La mitad que no se ve en la respuesta, y es la que pidieron: apagar el
        // interruptor **no borra un solo dato**.
        foreach ($antes as $tabla => $n) {
            $this->assertSame((int) $n, (int) DB::selectOne("SELECT COUNT(*) AS n FROM {$tabla}")->n,
                "Apagar el interruptor del periodo borró filas de `{$tabla}`. La petición era "
                .'explícita: los datos se ignoran, no se borran.');
        }
    }

    /** Y encenderlo otra vez lo devuelve, sin que nadie haya vuelto a escribir la marca. */
    public function test_volver_a_encender_el_periodo_lo_devuelve_a_independiente(): void
    {
        $a = $this->unAlumnoMatriculado();

        DB::update('UPDATE matriculas SET boletin_independiente = 1 WHERE id = ?', [$a->matricula_id]);
        DB::insert(
            'INSERT INTO bol_ind_periodos (alumno_id, periodo_id, aplica, created_at, updated_at)
             VALUES (?, ?, 1, NOW(), NOW())
             ON DUPLICATE KEY UPDATE aplica = 1',
            [$a->alumno_id, $a->periodo_id]
        );
        BoletinIndependiente::olvidar();

        $this->assertSame((int) $a->alumno_id,
            BoletinIndependiente::alcance((int) $a->alumno_id, (int) $a->periodo_id));
    }

    /**
     * La clave única nace con la tabla, y es deliberado: `notas_finales` lleva sin
     * ella desde 2014 y de ahí salen los tres síntomas del 10-definitivas.md.
     */
    public function test_la_tabla_del_interruptor_no_admite_dos_filas_del_mismo_par(): void
    {
        $a = $this->unAlumnoMatriculado();

        $insertar = fn (int $aplica) => DB::insert(
            'INSERT INTO bol_ind_periodos (alumno_id, periodo_id, aplica, created_at, updated_at)
             VALUES (?, ?, ?, NOW(), NOW())',
            [$a->alumno_id, $a->periodo_id, $aplica]
        );

        $insertar(1);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        $insertar(0);
    }

    /**
     * El interruptor de puestos contesta «¿está activado?», nunca «¿se enseña?».
     *
     * La segunda regla —el front esconde el puesto al `Acudiente` y al `Alumno`
     * aunque el año lo active— vive en el front y **tiene que seguir viviendo
     * sólo ahí**: dos sitios decidiendo lo mismo con criterios distintos es de lo
     * que salió el recalculador único.
     */
    public function test_el_interruptor_de_puestos_nace_encendido_y_se_puede_apagar(): void
    {
        $year = DB::selectOne('SELECT id FROM years WHERE deleted_at IS NULL ORDER BY id LIMIT 1');
        $this->assertNotNull($year, 'El seed no tiene años vivos.');

        $this->assertTrue(BoletinIndependiente::puestosCuentanIndependientes((int) $year->id),
            'Nace en 1 = lo de hoy. Que naciera en 0 sacaría alumnos de los puestos '
            .'de dieciséis colegios el día del despliegue.');

        DB::update('UPDATE years SET puestos_con_bol_independiente = 0 WHERE id = ?', [$year->id]);

        $this->assertFalse(BoletinIndependiente::puestosCuentanIndependientes((int) $year->id));
    }

    /** Un año que no existe no es una orden del colegio de sacar a nadie de la lista. */
    public function test_un_year_desconocido_no_apaga_los_puestos(): void
    {
        $this->assertTrue(BoletinIndependiente::puestosCuentanIndependientes(999999999));
    }
}
