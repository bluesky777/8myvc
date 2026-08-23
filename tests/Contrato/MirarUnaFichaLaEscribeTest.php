<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * Mirar la ficha de un alumno le crea los requisitos que le faltan — §146.
 *
 * Lo destapó la curva de profundidad, y **no podía verlo ningún barrido en
 * línea**: la escritura está a dos saltos del método de la ruta.
 *
 * ```
 * AlumnosController::putShow
 *   -> comprobar_alumno_con_grupos
 *      -> traer_requisitos_detalle   INSERT INTO requisitos_alumno(... "falta" ...)
 * ```
 *
 * Es la forma del §133 —una pantalla que se fabrica al abrirla— pero en un
 * endpoint **llamado `show`**, que es la palabra con la que este repo nombra las
 * lecturas. El verbo es `PUT` porque esta API usa `PUT` para leer con cuerpo, así
 * que **ni el nombre ni el verbo avisan**.
 *
 * Y lo alcanza el propio alumno: `putShow` no lleva guard de ruta, se defiende
 * por dentro dejando que un alumno pida **lo suyo**, y ese camino pasa por el
 * mismo sitio. O sea que **un alumno abriendo su propia ficha se crea sus propias
 * filas de «falta»**.
 *
 * ## Hoy no escribe nada, y por eso hay que fijar las dos cosas
 *
 * **En la base entera hay CERO filas de `requisitos_matricula`** —cero en los
 * ocho años— así que el bucle que inserta no da ni una vuelta. La escritura está
 * ahí, alcanzable y sin candado, **dormida porque la tabla que la alimenta está
 * vacía**.
 *
 * Es la cuarta mina de esta serie, y la de mecha más corta: las otras esperan a
 * una pantalla nueva, a una carpeta o a una línea de `SELECT`; ésta espera a que
 * **un colegio use una función que ya tiene** — definir los requisitos de
 * matrícula. Ese día, cada visita a una ficha empieza a escribir.
 *
 * Por eso esta clase fija **las dos**: que hoy no escribe, y qué escribe en
 * cuanto haya un requisito definido. Un test que solo mirara hoy diría «no
 * escribe» y estaría en verde el día que empiece.
 */
class MirarUnaFichaLaEscribeTest extends CasoDeContrato
{
    /**
     * Abrir la ficha crea las filas de requisitos que falten, con estado «falta».
     *
     * Se vacían primero las del alumno para que el número de después signifique
     * algo. La transacción del test lo deshace.
     */
    public function test_hoy_abrir_una_ficha_no_escribe_nada(): void
    {
        $token = $this->tokenDe($this->usuarioDeTipo('Profesor')->username);
        $alumno = $this->alumnoMatriculado();

        $this->assertSame(0, DB::table('requisitos_matricula')->count(),
            'Ya hay requisitos definidos en la base: entonces la mina del §146 está ARMADA y '
            .'cada visita a una ficha escribe. Mídelo y decide hoy.');

        $antes = DB::table('requisitos_alumno')->count();

        $this->withToken($token)->putJson('/api/alumnos/show', [
            'id' => $alumno->id, 'con_grupos' => false,
        ])->assertStatus(200);

        $this->assertSame($antes, DB::table('requisitos_alumno')->count(),
            'Mirar una ficha escribió, sin requisitos definidos: entonces escribe por otro '
            .'camino que el §146 no describe.');
    }

    /**
     * Y en cuanto hay **un** requisito definido, mirar la ficha escribe.
     *
     * El requisito se crea aquí dentro —la transacción del test lo deshace—
     * porque en la base no hay ninguno. **Sin este test, el §146 sería
     * indistinguible de un endpoint que no escribe**, y quedaría en verde
     * precisamente el día que empiece a hacerlo.
     */
    public function test_con_un_requisito_definido_mirar_la_ficha_ya_escribe(): void
    {
        $usuario = $this->usuarioDeTipo('Profesor');
        $token = $this->tokenDe($usuario->username);
        $alumno = $this->alumnoMatriculado();

        $requisitoId = $this->defineUnRequisitoEn($alumno->year_id);

        DB::table('requisitos_alumno')->where('alumno_id', $alumno->id)->delete();

        $this->withToken($token)->putJson('/api/alumnos/show', [
            'id' => $alumno->id, 'con_grupos' => false,
        ])->assertStatus(200);

        $creadas = DB::table('requisitos_alumno')->where('alumno_id', $alumno->id)
            ->where('requisito_id', $requisitoId)->get();

        $this->assertGreaterThan(0, $creadas->count(),
            'Con un requisito definido, mirar la ficha dejó de crearle la fila al alumno. Si se '
            .'arregló, este test se cambia con el porqué — §146.');

        foreach ($creadas as $fila) {
            $this->assertSame('falta', $fila->estado,
                'Las filas que nacen al abrir la ficha dejaron de nacer en «falta» — §146.');
        }
    }

    /**
     * Y el propio alumno se las crea a sí mismo.
     *
     * `putShow` no lleva guard de ruta: se defiende por dentro dejando pasar al
     * alumno **sobre lo suyo**, que es correcto para una lectura. Lo que nadie
     * había mirado es que ese mismo camino **escribe**. Es el patrón de la noche
     * —la tabla de rutas no dice si algo está defendido, ni tampoco si escribe—
     * llevado a su forma más incómoda: **la defensa funciona y aun así deja pasar
     * una escritura que nadie sabía que existía.**
     */
    public function test_un_alumno_abriendo_su_propia_ficha_se_crea_las_filas(): void
    {
        $cuenta = $this->usuarioDeTipo('Alumno');
        $token = $this->tokenDe($cuenta->username);
        $alumnoId = (int) DB::table('alumnos')->where('user_id', $cuenta->id)->value('id');

        $year = DB::selectOne('SELECT g.year_id FROM matriculas m
            INNER JOIN grupos g ON g.id = m.grupo_id AND g.deleted_at IS NULL
            WHERE m.alumno_id = ? AND m.deleted_at IS NULL LIMIT 1', [$alumnoId]);

        $this->assertNotNull($year, 'Esa cuenta de alumno no tiene matrícula.');

        $this->defineUnRequisitoEn((int) $year->year_id);
        DB::table('requisitos_alumno')->where('alumno_id', $alumnoId)->delete();

        $this->withToken($token)->putJson('/api/alumnos/show', [
            'id' => $alumnoId, 'con_grupos' => false,
        ])->assertStatus(200);

        $this->assertGreaterThan(0,
            DB::table('requisitos_alumno')->where('alumno_id', $alumnoId)->count(),
            'Un alumno dejó de crearse sus propias filas de requisitos al abrir su ficha — §146.');
    }

    /**
     * Y la ficha de otro sigue cerrada, que es la mitad que importa no perder.
     *
     * La defensa de dentro es correcta y **no se toca**: lo que este test fija es
     * que sigue estando, para que quien arregle la escritura no se lleve por
     * delante la lectura.
     */
    public function test_un_alumno_no_abre_la_ficha_de_otro(): void
    {
        $cuenta = $this->usuarioDeTipo('Alumno');
        $token = $this->tokenDe($cuenta->username);
        $mio = (int) DB::table('alumnos')->where('user_id', $cuenta->id)->value('id');

        $otro = DB::selectOne('SELECT id FROM alumnos WHERE id <> ? AND deleted_at IS NULL
            ORDER BY id LIMIT 1', [$mio]);

        $antes = DB::table('requisitos_alumno')->where('alumno_id', $otro->id)->count();

        $this->assertSame(403,
            $this->withToken($token)->putJson('/api/alumnos/show', ['id' => $otro->id])->status(),
            'Un alumno abrió la ficha de otro.');

        $this->assertSame($antes,
            DB::table('requisitos_alumno')->where('alumno_id', $otro->id)->count(),
            'El rechazo llegó a crearle requisitos al alumno ajeno antes de contestar que no.');
    }

    /**
     * Repetir no duplica: crea lo que falta y nada más.
     *
     * Es la mitad tranquilizadora, y hay que medirla y no suponerla — si además
     * duplicara, cada visita a una ficha ensancharía la tabla y la pantalla de
     * requisitos enseñaría la misma fila varias veces.
     */
    public function test_abrir_la_ficha_dos_veces_no_duplica(): void
    {
        $token = $this->tokenDe($this->usuarioDeTipo('Profesor')->username);
        $alumno = $this->alumnoMatriculado();
        $this->defineUnRequisitoEn($alumno->year_id);

        DB::table('requisitos_alumno')->where('alumno_id', $alumno->id)->delete();

        $cuerpo = ['id' => $alumno->id, 'con_grupos' => false];

        $this->withToken($token)->putJson('/api/alumnos/show', $cuerpo)->assertStatus(200);
        $primera = DB::table('requisitos_alumno')->where('alumno_id', $alumno->id)->count();

        $this->withToken($token)->putJson('/api/alumnos/show', $cuerpo)->assertStatus(200);
        $segunda = DB::table('requisitos_alumno')->where('alumno_id', $alumno->id)->count();

        $this->assertSame($primera, $segunda,
            "Abrir la ficha dos veces pasó de {$primera} a {$segunda} filas de requisitos — §146.");
    }

    /** Un alumno con matrícula, y el año de esa matrícula. */
    private function alumnoMatriculado(): object
    {
        $fila = DB::selectOne('SELECT a.id, g.year_id FROM alumnos a
            INNER JOIN matriculas m ON m.alumno_id = a.id AND m.deleted_at IS NULL
            INNER JOIN grupos g ON g.id = m.grupo_id AND g.deleted_at IS NULL
            WHERE a.deleted_at IS NULL ORDER BY a.id LIMIT 1');

        $this->assertNotNull($fila, 'El seed no tiene ningún alumno matriculado.');

        return $fila;
    }

    /**
     * Define un requisito de matrícula en ese año. **Arma la mina a propósito.**
     *
     * En la base no hay ninguno —cero filas en los ocho años—, así que sin esto no
     * hay nada que medir. Se deshace con la transacción del test.
     */
    private function defineUnRequisitoEn(int $yearId): int
    {
        return (int) DB::table('requisitos_matricula')->insertGetId([
            'year_id' => $yearId,
            'orden' => 1,
            'requisito' => 'Fotocopia del documento (prueba del lote T)',
            'created_at' => '2026-08-23 03:00:00',
            'updated_at' => '2026-08-23 03:00:00',
        ]);
    }
}
