<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * Las dos rutas del detalle de matrículas, y la que borra notas sin papelera.
 *
 * `PUT detalles/eliminar-notas-periodo` hace un `DELETE FROM notas` **físico**:
 * no marca `deleted_at`, no hay papelera y no hay de dónde restaurar. El botón
 * que la llama se llama, en el propio `myvc_front`, *«Eliminar todas las notas de
 * este periodo (¡peligroso!)»*.
 *
 * Nadie había comprobado nunca qué responde. La [§08](../../docs/migracion/08-revision-idor.md)
 * la tenía apuntada en «escrituras sobre otro alumno» y no se cerró; la
 * [§27](../../docs/migracion/05-codigo-muerto-y-roto.md) —que dejó 25 de 26 rutas
 * pidiendo el permiso del sitio al que escriben— **no la vio**, porque su
 * inventario se hizo de los sitios que ya llamaban a `pueden_editar_notas` y no
 * de los que escriben en las notas.
 *
 * > **Una lista construida desde la comprobación no puede contener al que nunca
 * > comprobó.** Es la forma que tiene este fallo, y es distinta del detector con
 * > falsos positivos: aquí el detector era exacto y el conjunto estaba mal
 * > elegido.
 */
class BorrarNotasDeUnPeriodoTest extends CasoDeContrato
{
    /**
     * Con el periodo cerrado, el botón peligroso no borra nada.
     *
     * Se comprueba el 400 **y la fila**, que es lo que importa: un rechazo que
     * borra antes de contestar que no es la forma de fallo que este repo lleva
     * persiguiendo desde la §71, y aquí el borrado es físico.
     *
     * El 400 no es un descuido: es el código que devuelve `pueden_editar_notas()`
     * para un profesor con el interruptor cerrado, y lo comparte con las otras 25
     * rutas de la §27. Cambiarlo a 403 aquí sola sería que la misma negativa
     * llegara al front de dos maneras.
     */
    public function test_con_el_periodo_cerrado_no_borra_las_notas(): void
    {
        $c = $this->contexto();

        DB::table('periodos')->where('id', $c->periodo_id)->update(['profes_pueden_editar_notas' => 0]);

        $this->withToken($c->token)->putJson('/api/detalles/eliminar-notas-periodo', [
            'periodo_id' => $c->periodo_id,
            'alumno_id' => $c->alumno_id,
            'grupo_id' => $c->grupo_id,
        ])->assertStatus(400);

        $this->assertSame($c->notas, $this->notasDe($c),
            'Con el periodo cerrado se borraron las notas igual, y no hay papelera de dónde sacarlas.');
    }

    /**
     * Con el periodo abierto sigue borrando, que es para lo que existe.
     *
     * La otra mitad de la comprobación, y no es de adorno: un arreglo que cerrara
     * la ruta entera pasaría el test de arriba **y apagaría la pantalla en
     * dieciséis colegios**. Se mira el número de filas que dice haber borrado y
     * las que quedan, porque la ruta devuelve el recuento y ése es su contrato.
     */
    public function test_con_el_periodo_abierto_borra_y_dice_cuantas(): void
    {
        $c = $this->contexto();

        $this->assertGreaterThan(0, $c->notas,
            'El alumno del seed llegó sin notas en ese periodo: el test no mediría nada.');

        DB::table('periodos')->where('id', $c->periodo_id)->update(['profes_pueden_editar_notas' => 1]);

        $r = $this->withToken($c->token)->putJson('/api/detalles/eliminar-notas-periodo', [
            'periodo_id' => $c->periodo_id,
            'alumno_id' => $c->alumno_id,
            'grupo_id' => $c->grupo_id,
        ]);

        $r->assertStatus(200);

        $this->assertSame($c->notas, (int) $r->getContent(),
            'La ruta devuelve el número de filas borradas y es su contrato: el front lo enseña.');
        $this->assertSame(0, $this->notasDe($c), 'Dijo que borró y no borró.');
    }

    /**
     * Y borra sin papelera: las filas no están, no están marcadas.
     *
     * Se comprueba **contando sin filtrar `deleted_at`**, que es la única manera
     * de distinguir un borrado físico de uno blando. Contar con el filtro daría
     * cero en los dos casos y el test pasaría sin medir la diferencia que importa:
     * de un borrado blando se vuelve, de éste no.
     */
    public function test_el_borrado_es_fisico_y_no_deja_de_donde_volver(): void
    {
        $c = $this->contexto();

        DB::table('periodos')->where('id', $c->periodo_id)->update(['profes_pueden_editar_notas' => 1]);

        $this->withToken($c->token)->putJson('/api/detalles/eliminar-notas-periodo', [
            'periodo_id' => $c->periodo_id,
            'alumno_id' => $c->alumno_id,
            'grupo_id' => $c->grupo_id,
        ])->assertStatus(200);

        $this->assertSame(0, $this->notasDe($c, conPapelera: true),
            'Las notas quedaron marcadas en vez de borradas. Sería una mejora, pero cambia el '
            .'contrato: hoy la pantalla cuenta con que desaparecen.');
    }

    /**
     * El listado que alimenta esa pantalla cuesta una consulta por grupo y periodo.
     *
     * `putGruposPeriodos` recorre **todos** los grupos del año preguntando por
     * cada uno si el alumno tiene notas, y por cada grupo con notas recorre los
     * periodos, y por cada periodo las asignaturas. No es un fallo —contesta lo
     * que le piden— pero es el coste de abrir la pantalla desde la que se llama al
     * botón de arriba, y no estaba medido.
     *
     * Se cuenta por la forma de la consulta y no por el total, como en
     * `PlanillasAusenciasTest`: el total lo mueven el token y el contexto.
     */
    public function test_el_listado_de_grupos_y_periodos_pregunta_grupo_a_grupo(): void
    {
        $c = $this->contexto();

        $grupos = (int) DB::selectOne('SELECT COUNT(*) n FROM grupos WHERE year_id = ?', [$c->year_id])->n;

        $consultas = [];
        DB::listen(function ($q) use (&$consultas) {
            $consultas[] = preg_replace('/\s+/', ' ', $q->sql);
        });

        $r = $this->withToken($c->token)->putJson('/api/detalles/grupos-periodos', [
            'year_id' => $c->year_id,
            'alumno_id' => $c->alumno_id,
        ]);

        $r->assertStatus(200);

        // La comparación es sensible a mayúsculas y el SQL del controlador las
        // mezcla —`FROM notas n` con `inner join`—, así que la primera versión de
        // esto contó cero y dijo «el listado dejó de preguntar grupo a grupo».
        // Un contador que no encuentra nada tiene la misma cara que un arreglo.
        $porGrupo = count(array_filter($consultas,
            fn ($sql) => str_contains($sql, 'FROM notas n')
                && str_contains($sql, 'a.grupo_id=:grupo_id')
                && ! str_contains($sql, 'u.periodo_id')));

        $this->assertSame($grupos, $porGrupo,
            'El listado ha dejado de preguntar grupo a grupo. Si es porque se agrupó en una '
            .'consulta, este número baja y hay que cambiarlo aquí; si es porque llegan menos '
            .'grupos, el test dejó de medir.');

        // Y el listado de grupos **no filtra la papelera**: la consulta es
        // `SELECT * FROM grupos g WHERE g.year_id=:year_id` a secas, así que un
        // grupo borrado con notas del alumno dentro sale igual. Se fija porque es
        // lo contrario de lo que hace la rejilla de grupos del mismo módulo, y
        // porque de esa pantalla cuelga el botón que borra notas sin papelera.
        $deGrupos = array_values(array_filter($consultas,
            fn ($sql) => str_contains($sql, 'FROM grupos g WHERE')));

        $this->assertNotEmpty($deGrupos, 'No salió la consulta de grupos: el test no mediría nada.');
        $this->assertStringNotContainsString('deleted_at', $deGrupos[0],
            'El listado empezó a filtrar la papelera de grupos, y eso cambia lo que enseña la pantalla.');
    }

    // ---------------------------------------------------------------- ayudas

    /** Cuántas notas tiene el alumno en ese grupo y periodo. */
    private function notasDe(object $c, bool $conPapelera = false): int
    {
        $filtro = $conPapelera ? '' : ' AND n.deleted_at IS NULL';

        return (int) DB::selectOne('SELECT COUNT(*) n FROM notas n
            INNER JOIN subunidades s ON s.id = n.subunidad_id
            INNER JOIN unidades u ON u.id = s.unidad_id AND u.periodo_id = ?
            INNER JOIN asignaturas a ON a.id = u.asignatura_id AND a.grupo_id = ?
            WHERE n.alumno_id = ?'.$filtro, [$c->periodo_id, $c->grupo_id, $c->alumno_id])->n;
    }

    /**
     * Un profesor, y un alumno con notas de verdad en su grupo y periodo.
     *
     * Las notas se ponen aquí porque el seed no trae ninguna colgando de una
     * subunidad de este periodo, y un test que borre cero filas pasa las tres
     * veces sin medir nada. Van dos, en dos subunidades, para que el recuento que
     * devuelve la ruta sea distinguible de un uno accidental.
     */
    private function contexto(): object
    {
        $usuario = $this->usuarioDeTipo('Profesor');
        $token = $this->tokenDe($usuario->username);

        $periodo = DB::selectOne('SELECT p.id, p.year_id FROM periodos p
            INNER JOIN users u ON u.periodo_id = p.id WHERE u.id = ?', [$usuario->id]);

        $asignatura = DB::selectOne('SELECT a.id, a.grupo_id FROM asignaturas a
            INNER JOIN grupos g ON g.id = a.grupo_id AND g.year_id = ? AND g.deleted_at IS NULL
            INNER JOIN matriculas m ON m.grupo_id = g.id AND m.deleted_at IS NULL
                AND m.estado IN ("MATR","ASIS")
            WHERE a.deleted_at IS NULL ORDER BY a.id LIMIT 1', [$periodo->year_id]);

        $this->assertNotNull($asignatura, 'El seed necesita una asignatura con alumnos en el año del profesor.');

        $alumno = DB::selectOne('SELECT m.alumno_id FROM matriculas m
            WHERE m.grupo_id = ? AND m.deleted_at IS NULL AND m.estado IN ("MATR","ASIS")
            ORDER BY m.alumno_id LIMIT 1', [$asignatura->grupo_id]);

        $unidadId = DB::table('unidades')->insertGetId([
            'asignatura_id' => $asignatura->id,
            'periodo_id' => $periodo->id,
            'definicion' => 'Unidad de pruebas',
            'porcentaje' => 100,
            'orden' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ([3.5, 4.2] as $i => $nota) {
            $subunidadId = DB::table('subunidades')->insertGetId([
                'unidad_id' => $unidadId,
                'definicion' => 'Subunidad de pruebas '.$i,
                'porcentaje' => 50,
                'orden' => $i + 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('notas')->insert([
                'subunidad_id' => $subunidadId,
                'alumno_id' => $alumno->alumno_id,
                'nota' => $nota,
                'created_by' => $usuario->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $c = (object) [
            'token' => $token,
            'periodo_id' => (int) $periodo->id,
            'year_id' => (int) $periodo->year_id,
            'grupo_id' => (int) $asignatura->grupo_id,
            'alumno_id' => (int) $alumno->alumno_id,
        ];

        $c->notas = $this->notasDe($c);

        return $c;
    }
}
