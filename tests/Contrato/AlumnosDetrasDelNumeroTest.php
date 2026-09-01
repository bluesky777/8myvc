<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * Los alumnos detrás de cada número de «Alumnos por grupo».
 *
 * `GET grupos/{grupo_id}/alumnos-de/{que}` existe para que al pulsar una celda de
 * esa tabla se abra el listado de quienes la componen. **Lo único que tiene que
 * garantizar es el CUADRE**: si la celda dice 5, el listado trae 5. Por eso este
 * fichero no compara contra cifras escritas a mano —envejecen con el seed— sino
 * contra **las respuestas que alimentan la propia tabla**, `grupos/cant-alumnos`
 * y `grupos/con-cantidad-alumnos`: pregunta las dos cosas y las enfrenta.
 *
 * Así, el día que alguien cambie el `WHERE` de un contador y no el del listado
 * —o al revés— el test se pone rojo aunque las dos consultas sigan siendo
 * razonables por separado. Es el fallo que se quería impedir: una pantalla que
 * enseña seis alumnos debajo de un 5 no parece rota, parece un dato.
 *
 * Y la mitad que muerde de verdad: **cada test dice cuánta población revisó**. Un
 * cuadre de trece grupos con cero alumnos cada uno cuadra perfectamente y no
 * comprueba nada.
 */
class AlumnosDetrasDelNumeroTest extends CasoDeContrato
{
    /** El listado de una celda, ya comprobado que responde 200. */
    private function listado(string $token, int $grupoId, string $que, ?int $periodo = null): array
    {
        $url = "/api/grupos/{$grupoId}/alumnos-de/{$que}".($periodo === null ? '' : '?periodo='.$periodo);

        $r = $this->getJson($url, ['Authorization' => 'Bearer '.$token]);

        $r->assertStatus(200);

        $cuerpo = $r->json();

        $this->assertSame(count($cuerpo) === 0 ? [] : range(0, count($cuerpo) - 1), array_keys($cuerpo),
            "{$que} dejó de devolver una lista plana.");

        return $cuerpo;
    }

    /**
     * Las tres columnas que están siempre: Alumnos, Hom y Muj.
     *
     * Se recorren **todos los grupos del año**, no uno: las tres cifras salen de
     * dos endpoints distintos —«Alumnos» de `cant-alumnos`, el sexo de
     * `con-cantidad-alumnos`— y el descuadre de PREM del 31 ago 2026 vivía
     * justamente en la costura entre los dos.
     */
    public function test_alumnos_hombres_y_mujeres_cuadran_con_su_listado(): void
    {
        [$grupo, $token] = $this->grupoYPersonal();

        $cabecera = ['Authorization' => 'Bearer '.$token];

        $cant = $this->getJson('/api/grupos/cant-alumnos', $cabecera)->assertStatus(200)->json();
        $conSexo = $this->putJson('/api/grupos/con-cantidad-alumnos', [], $cabecera)->assertStatus(200)->json()['grupos'];

        $porGrupo = [];

        foreach ($conSexo as $fila) {
            $porGrupo[(int) $fila['id']] = $fila;
        }

        $revisados = 0;
        $alumnosContados = 0;

        foreach ($cant as $fila) {
            $id = (int) $fila['id'];
            $revisados++;
            $alumnosContados += (int) $fila['cant_alumnos'];

            $this->assertCount((int) $fila['cant_alumnos'], $this->listado($token, $id, 'alumnos'),
                "El grupo {$id} dice ".$fila['cant_alumnos'].' alumnos y su listado trae otra cosa.');

            if (! isset($porGrupo[$id])) {
                continue;
            }

            $hombres = $this->listado($token, $id, 'hombres');
            $mujeres = $this->listado($token, $id, 'mujeres');

            $this->assertCount((int) $porGrupo[$id]['cant_hombres'], $hombres,
                "El grupo {$id} dice ".$porGrupo[$id]['cant_hombres'].' hombres y su listado trae otra cosa.');

            $this->assertCount((int) $porGrupo[$id]['cant_mujeres'], $mujeres,
                "El grupo {$id} dice ".$porGrupo[$id]['cant_mujeres'].' mujeres y su listado trae otra cosa.');

            // Los tres listados se cruzan entre sí, y no solo contra su cifra: si a
            // uno se le escapa el filtro de estado, las cuentas pueden seguir
            // cuadrando cada una por su lado y el desglose dejar de ser un desglose.
            // Es la forma que tuvo el descuadre de PREM del 31 ago 2026.
            $todos = array_map('intval', array_column($this->listado($token, $id, 'alumnos'), 'alumno_id'));

            foreach ([...$hombres, ...$mujeres] as $fila) {
                $this->assertContains((int) $fila['alumno_id'], $todos,
                    'El alumno '.$fila['alumno_id']." sale en el desglose por sexo del grupo {$id} y no en su listado de alumnos.");
            }
        }

        $this->assertGreaterThan(0, $revisados, 'No se revisó ningún grupo: el cuadre no se ha comprobado.');
        $this->assertGreaterThan(0, $alumnosContados,
            "Se revisaron {$revisados} grupos y entre todos suman 0 alumnos: cuadran, y no comprueban nada.");

        fwrite(STDERR, "\n  ↳ cuadre comprobado sobre {$revisados} grupos y {$alumnosContados} alumnos\n");

        // Y la forma, que es lo que tipa el front. `grupoYPersonal` devuelve el
        // grupo con más matrículas del seed, así que este listado nunca sale vacío.
        $this->compararConInstantanea('grupos-alumnos-de-alumnos',
            $this->formaUnida($this->listado($token, (int) $grupo->id, 'alumnos')));
    }

    /**
     * Ret_N y Mat_N: las ocho columnas que solo aparecen cuando hay movimiento.
     *
     * **El movimiento se fabrica aquí y no se espera del seed.** En las bases de
     * los colegios esas celdas llegan vacías la mayor parte del año —el front las
     * esconde si ninguna trae número—, así que un test que se limitara a leer lo
     * que hay compararía ceros contra listas vacías para siempre.
     *
     * **Se fabrican cuatro movimientos, y dos van EXACTAMENTE EN EL BORDE**: uno
     * el día `fecha_inicio` y otro el día `fecha_fin`. Esos dos son la prueba del
     * arreglo del 31 ago 2026: hasta esa noche los contadores comparaban con `>` y
     * `<` estrictos y quien se movía el primer o el último día del periodo no
     * estaba en ninguna cifra. **Con los estrictos de vuelta, este test se pone
     * rojo por los dos lados a la vez** —la celda baja y el listado también—, que
     * es justo lo que no vale como comprobación si sólo se mira una de las dos:
     * descuadrar es lo único que este endpoint no puede hacer, y volver a los `>`
     * NO descuadra nada, sólo cuenta de menos. Por eso hay `assertContains` de los
     * dos del borde y no sólo `assertCount`.
     */
    public function test_los_retirados_y_los_matriculados_de_un_periodo_cuadran(): void
    {
        [$grupo, $token] = $this->grupoYPersonal();

        $cabecera = ['Authorization' => 'Bearer '.$token];

        $periodos = DB::select('SELECT id, fecha_inicio, fecha_fin FROM periodos
            WHERE year_id = ? AND deleted_at IS NULL ORDER BY numero', [$grupo->year_id]);

        $this->assertNotEmpty($periodos, 'El año del grupo del seed no tiene periodos.');

        // **Las fechas del periodo se ponen aquí, y ESO ES UN HALLAZGO, no un
        // atajo del test**: en el seed —copia de una base real— los cuatro periodos
        // del año actual tienen `fecha_inicio` y `fecha_fin` a NULL. Con nulos,
        // `m.fecha_retiro > NULL` no es ni verdadero ni falso: los dos contadores
        // devuelven 0 SIEMPRE, y las ocho columnas Ret_N/Mat_N de la tabla salen
        // vacías todo el año sin que nada esté roto. Es exactamente lo que ve el
        // front en su base local. Para comprobar el cuadre hace falta un periodo con
        // rango, así que se le pone uno; lo deshace la transacción del test.
        $n = 1;
        $inicio = '2025-01-10';
        $fin = '2025-03-20';
        $dentro = '2025-02-14';

        DB::table('periodos')->where('id', $periodos[0]->id)
            ->update(['fecha_inicio' => $inicio, 'fecha_fin' => $fin]);

        // Cuatro matrículas distintas del grupo: dos se mueven por dentro del
        // periodo y dos justo en los bordes. Lo deshace la transacción del test.
        $matriculas = DB::select('SELECT m.id, m.alumno_id FROM matriculas m
            INNER JOIN alumnos a ON a.id = m.alumno_id AND a.deleted_at IS NULL
            WHERE m.grupo_id = ? AND m.deleted_at IS NULL ORDER BY m.id LIMIT 4', [$grupo->id]);

        $this->assertCount(4, $matriculas, 'El grupo del seed necesita cuatro matrículas para montar el movimiento.');

        // Que sean de cuatro alumnos distintos no es decoración: si dos cayeran en
        // el mismo, `fechaDe` devolvería la fila del otro y las comprobaciones de
        // fecha dejarían de comprobar lo que dicen.
        $this->assertCount(4, array_unique(array_column($matriculas, 'alumno_id')),
            'Las cuatro matrículas del escenario tienen que ser de cuatro alumnos distintos.');

        DB::table('matriculas')->where('id', $matriculas[0]->id)
            ->update(['estado' => 'RETI', 'fecha_retiro' => $inicio]);

        DB::table('matriculas')->where('id', $matriculas[1]->id)
            ->update(['fecha_matricula' => $fin]);

        DB::table('matriculas')->where('id', $matriculas[2]->id)
            ->update(['estado' => 'DESE', 'fecha_retiro' => $dentro]);

        DB::table('matriculas')->where('id', $matriculas[3]->id)
            ->update(['fecha_matricula' => $dentro]);

        $conCantidad = $this->putJson('/api/grupos/con-cantidad-alumnos', [], $cabecera)->assertStatus(200)->json()['grupos'];

        $fila = null;

        foreach ($conCantidad as $candidata) {
            if ((int) $candidata['id'] === (int) $grupo->id) {
                $fila = $candidata;
            }
        }

        $this->assertNotNull($fila, 'El grupo con movimiento desapareció de con-cantidad-alumnos.');

        // La celda trae '' cuando es 0, que es lo que hace que el front esconda la
        // columna. Aquí interesa la cifra, y tiene que ser al menos la que se acaba
        // de fabricar.
        $celdaReti = (int) $fila['periodos_ret'][$n - 1]['cant_reti'];
        $celdaMatr = (int) $fila['periodos_matr'][$n - 1]['cant_matr'];

        $this->assertGreaterThanOrEqual(2, $celdaReti,
            'La celda Ret'.$n.' no cuenta los dos retiros fabricados: uno de ellos cae en el borde del periodo.');

        $this->assertGreaterThanOrEqual(2, $celdaMatr,
            'La celda Mat'.$n.' no cuenta las dos matrículas fabricadas: una de ellas cae en el borde del periodo.');

        $retirados = $this->listado($token, (int) $grupo->id, 'retirados', $n);
        $matriculados = $this->listado($token, (int) $grupo->id, 'matriculados', $n);

        $this->assertCount($celdaReti, $retirados, "Ret{$n} dice {$celdaReti} y su listado trae otra cosa.");
        $this->assertCount($celdaMatr, $matriculados, "Mat{$n} dice {$celdaMatr} y su listado trae otra cosa.");

        $enRetirados = array_map('intval', array_column($retirados, 'alumno_id'));
        $enMatriculados = array_map('intval', array_column($matriculados, 'alumno_id'));

        // Los dos del borde primero: son los que el `>` estricto se dejaba fuera, y
        // los que tienen que entrar desde el arreglo del 31 ago 2026.
        $this->assertContains((int) $matriculas[0]->alumno_id, $enRetirados,
            'El retirado EL DÍA fecha_inicio no está en el listado: vuelven a ser fechas estrictas.');

        $this->assertContains((int) $matriculas[1]->alumno_id, $enMatriculados,
            'El matriculado EL DÍA fecha_fin no está en el listado: vuelven a ser fechas estrictas.');

        $this->assertContains((int) $matriculas[2]->alumno_id, $enRetirados,
            'El alumno que se acaba de retirar no está en el listado de retirados.');

        $this->assertContains((int) $matriculas[3]->alumno_id, $enMatriculados,
            'El alumno que se acaba de matricular no está en el listado de matriculados.');

        // Y el desertor entra por el mismo sitio que el retirado: la celda cuenta
        // RETI y DESE, y el listado tiene que contar los dos o descuadra.
        $this->assertSame($dentro, $this->fechaDe($retirados, (int) $matriculas[2]->alumno_id, 'fecha_retiro'),
            'El desertor no trae su fecha_retiro.');

        // La fecha va en la fila porque es la explicación de la celda: sin ella el
        // modal no puede decir por qué ese alumno cuenta en ese periodo.
        $this->assertSame($inicio, $this->fechaDe($retirados, (int) $matriculas[0]->alumno_id, 'fecha_retiro'),
            'El retirado no trae su fecha_retiro.');

        $this->assertSame($fin, $this->fechaDe($matriculados, (int) $matriculas[1]->alumno_id, 'fecha_matricula'),
            'El matriculado no trae su fecha_matricula.');

        $this->compararConInstantanea('grupos-alumnos-de-matriculados', $this->formaUnida($matriculados));
        $this->compararConInstantanea('grupos-alumnos-de-retirados', $this->formaUnida($retirados));
    }

    /** La fecha que trae una fila concreta del listado. */
    private function fechaDe(array $listado, int $alumnoId, string $campo): ?string
    {
        foreach ($listado as $fila) {
            if ((int) $fila['alumno_id'] === $alumnoId) {
                return $fila[$campo];
            }
        }

        return null;
    }

    /**
     * Un grupo sin nadie contesta 200 con la lista vacía, no 404.
     *
     * Hay grupos abiertos con cero matriculados y su celda dice 0: el modal tiene
     * que poder decir «no hay ninguno». Un 404 ahí es un error de la aplicación
     * para un caso que es normal.
     */
    public function test_un_grupo_sin_alumnos_no_es_un_404(): void
    {
        [$grupo, $token] = $this->grupoYPersonal();

        $vacio = DB::table('grupos')->insertGetId([
            'nombre' => 'Grupo sin nadie',
            'abrev' => 'SIN',
            'year_id' => $grupo->year_id,
            'grado_id' => DB::table('grupos')->where('id', $grupo->id)->value('grado_id'),
            'orden' => 99,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame([], $this->listado($token, $vacio, 'alumnos'));
        $this->assertSame([], $this->listado($token, $vacio, 'hombres'));
    }

    /**
     * Lo que no se entiende se contesta con un 422, no con una lista vacía.
     *
     * Una lista vacía es una respuesta legítima —el grupo sin nadie de arriba—,
     * así que devolverla también cuando la pregunta está mal escrita haría
     * indistinguibles «no hay ninguno» y «me pediste algo que no existe».
     */
    public function test_lo_que_no_se_entiende_es_un_422(): void
    {
        [$grupo, $token] = $this->grupoYPersonal();

        $cabecera = ['Authorization' => 'Bearer '.$token];
        $id = (int) $grupo->id;

        $cuantos = count(DB::select('SELECT id FROM periodos WHERE year_id = ? AND deleted_at IS NULL', [$grupo->year_id]));

        $malas = [
            'un listado que no existe' => "/api/grupos/{$id}/alumnos-de/repitentes",
            'retirados sin periodo' => "/api/grupos/{$id}/alumnos-de/retirados",
            'matriculados sin periodo' => "/api/grupos/{$id}/alumnos-de/matriculados",
            'el periodo cero' => "/api/grupos/{$id}/alumnos-de/retirados?periodo=0",
            'un periodo que no hay' => "/api/grupos/{$id}/alumnos-de/matriculados?periodo=".($cuantos + 1),
            'un periodo que no es un número' => "/api/grupos/{$id}/alumnos-de/retirados?periodo=primero",
        ];

        foreach ($malas as $porque => $url) {
            $r = $this->getJson($url, $cabecera);

            $this->assertSame(422, $r->getStatusCode(), "Se esperaba 422 por {$porque} y llegó ".$r->getStatusCode().'.');
        }
    }
}
