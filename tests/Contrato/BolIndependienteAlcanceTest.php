<?php

namespace Tests\Contrato;

use App\Services\BoletinIndependiente;
use Illuminate\Database\UniqueConstraintViolationException;
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
 * `COALESCE(bip.aplica, 1)` que vuelve— **las 309 unidades de esta
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
        // **La precondición es ahora más fuerte que antes, y por eso se conserva.**
        // Se comprobaba que `matriculas.boletin_independiente` fuera 0 en todas las
        // filas; ahora se comprueba que la tabla **esté vacía**. «Ninguna fila» es más
        // difícil de romper sin querer que «una columna a 0 en todas partes», que es
        // justo lo que un `DEFAULT 1` mal puesto en un colegio haría saltar por los
        // aires sin un solo error en el log.
        $marcadas = DB::selectOne('SELECT COUNT(*) AS n FROM bol_ind_periodos')->n;

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

    public function test_marcado_en_el_periodo_el_alcance_es_su_propio_id(): void
    {
        $a = $this->unAlumnoMatriculado();

        $this->marcarIndependiente((int) $a->alumno_id, (int) $a->periodo_id);

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

        $this->marcarIndependiente((int) $a->alumno_id, (int) $a->periodo_id);

        $antes = [
            'unidades' => DB::selectOne('SELECT COUNT(*) AS n FROM unidades')->n,
            'subunidades' => DB::selectOne('SELECT COUNT(*) AS n FROM subunidades')->n,
            'notas' => DB::selectOne('SELECT COUNT(*) AS n FROM notas')->n,
        ];

        $this->marcarIndependiente((int) $a->alumno_id, (int) $a->periodo_id, aplica: false);

        $this->assertNull(
            BoletinIndependiente::alcance((int) $a->alumno_id, (int) $a->periodo_id),
            'Con `aplica = 0` el alumno vuelve a las unidades del grupo en ESE periodo.'
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

        $this->marcarIndependiente((int) $a->alumno_id, (int) $a->periodo_id, aplica: false);
        $this->marcarIndependiente((int) $a->alumno_id, (int) $a->periodo_id);

        $this->assertSame((int) $a->alumno_id,
            BoletinIndependiente::alcance((int) $a->alumno_id, (int) $a->periodo_id));
    }

    /**
     * **Marcar un periodo no repinta los demás**, que es la decisión 7 entera.
     *
     * Es el test que este fichero no tenía y que habría cazado el fallo. En sus
     * palabras:
     *
     * > «A veces el estudiante tuvo un periodo normal y en el segundo tuvo un
     * > accidente donde ya se le tiene que crear un boletín aparte, pero no se le
     * > puede borrar el boletín del primer periodo: **tienen que convivir**.»
     *
     * Con el default anterior —fila ausente = «lo que diga la matrícula»— esto era
     * imposible: marcar al alumno en octubre le cambiaba el alcance de **todos** los
     * periodos del año, incluido el primero, ya impreso y entregado. Y no daba
     * ningún error: el boletín de septiembre simplemente pasaba a buscar unas
     * unidades propias que en septiembre no existían, y salía en blanco.
     *
     * **El test se pone rojo con un solo carácter**: devolver el
     * `COALESCE(bip.aplica, 0)` de `BoletinIndependiente` al `1` que tenía.
     */
    public function test_marcar_un_periodo_no_toca_el_alcance_de_los_demas(): void
    {
        $grupo = $this->grupoConAlumnos();
        $periodos = $this->periodosDelAnioDelGrupo($grupo->id);

        $this->assertGreaterThan(1, count($periodos),
            'Hacen falta dos periodos en el año para que «convivir» signifique algo.');

        $alumno = DB::selectOne(
            'SELECT m.alumno_id FROM matriculas m
              WHERE m.grupo_id = ? AND m.deleted_at IS NULL
                AND m.estado IN ("MATR","ASIS") LIMIT 1',
            [$grupo->id]
        );

        $this->assertNotNull($alumno, 'El seed no tiene alumno matriculado en este grupo.');

        $alumnoId = (int) $alumno->alumno_id;

        // El accidente: se le marca el SEGUNDO periodo, en octubre, con el primero ya
        // impreso.
        $this->marcarIndependiente($alumnoId, $periodos[1]);

        $this->assertSame($alumnoId, BoletinIndependiente::alcance($alumnoId, $periodos[1]),
            'El periodo que se acaba de marcar tiene que ir por boletín independiente.');

        // Y la mitad que importa: todos los demás siguen yendo con el grupo.
        foreach ($periodos as $i => $periodoId) {
            if ($i === 1) {
                continue;
            }

            $this->assertNull(BoletinIndependiente::alcance($alumnoId, $periodoId),
                'Marcar el periodo '.$periodos[1].' le cambió el alcance al periodo '.$periodoId.'. '
                .'Con el default al revés esto pasa en silencio: el boletín del primer periodo, '
                .'ya entregado, se repinta buscando unas unidades propias que ese periodo no '
                .'tiene, y sale en blanco sin un solo error.');
        }
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

        $this->expectException(UniqueConstraintViolationException::class);
        $insertar(0);
    }

    /*
     * Los dos tests del interruptor de puestos se han ido con su columna a la
     * FASE 2 (ver el comentario en `App\Services\BoletinIndependiente`). No se
     * borran por molestos: la columna que probaban ya no existe en esta fase.
     *
     * Cuando vuelvan, el caso que los hace valer algo **es el empate**:
     * `Nota::puestoAlumno` arranca en 1 y suma 1 por cada promedio estrictamente
     * mayor, así que es 1-based **igual que la posición de fila que pinta el
     * front**. Sin empates los dos caminos dan el mismo número y el test pasa sin
     * probar nada; con empates, la fila da `1,2,3,4` y el puesto da `1,1,3,4`.
     */
}
