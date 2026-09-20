<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * **Fase 0 de [43](../../docs/migracion/43-lo-que-todavia-no-se-ha-calificado.md):
 * una casilla sin calificar deja de valer cero y pasa a no valer nada.**
 *
 * El porqué está en el documento y en la cabecera de la migración
 * `2026_09_19_500000_la_casilla_vacia`. Aquí sólo se fija el comportamiento, y las
 * cuatro cosas que fija son distintas entre sí:
 *
 *   1. **cómo NACE** una casilla — vacía, no en `subunidades.nota_default`;
 *   2. **cómo se QUITA** una nota — `update` con el vacío explícito (D7);
 *   3. **qué NO es quitarla** — un cuerpo sin la clave `nota`, que es 422;
 *   4. **que el 0 del docente sigue siendo un 0** y no se confunde con lo anterior.
 *
 * ## Lo que este test NO puede comprobar todavía, y conviene saberlo
 *
 * En la definitiva de hoy —suma de aportes, sin normalizar— **una casilla vacía y
 * un cero aportan lo mismo: nada**. Así que la diferencia entre las dos no se ve en
 * `notas_finales`, se ve en la fila. Por eso aquí se mira **el valor guardado** y no
 * sólo el número de la definitiva.
 *
 * Quien lea esto buscando «¿y entonces qué arregla la fase 0?»: arregla que el dato
 * **pueda** decir la verdad. Lo que la lee es la fase 1 —la nota parcial y la
 * cobertura—, y hasta que entre, lo único que cambia de cara al usuario es que una
 * subunidad con `nota_default > 0` deja de regalar nota al nacer (4.417 subunidades
 * de 36.705 en la copia de desarrollo, el 12 %).
 *
 * ## El montaje
 *
 * Dos unidades al 50 % con dos subunidades al 50 % cada una y una nota de 20 en
 * cada casilla: cada nota pesa **un cuarto** y la definitiva sale **20** de cabeza.
 * Es el mismo montaje que `EditarUnaNotaActualizaLaDefinitivaTest`, y es copia a
 * propósito: la aritmética de un seed real no se puede hacer mentalmente, y un test
 * cuyo número esperado hay que calcular con un script no se relee nunca.
 */
class LaCasillaVaciaTest extends CasoDeContrato
{
    /**
     * **La guarda que sustituye al `NOT NULL`, y es el test que justifica el resto.**
     *
     * `Request::input('nota')` devuelve `null` igual si la clave vino vacía que si no
     * vino. Mientras la columna fue `NOT NULL`, un cuerpo incompleto abortaba en
     * producción; con la columna anulable sería un borrado silencioso. O sea que la
     * integridad de la columna estaba haciendo de validación de entrada **por
     * accidente**, y este test es lo que la repone a propósito.
     *
     * Se comprueba lo segundo además del 422: **que la nota sigue ahí**. Un 422 que
     * hubiera borrado antes de contestar cumpliría la primera mitad y sería el mismo
     * fallo.
     */
    public function test_un_cuerpo_sin_nota_es_422_y_no_borra_nada(): void
    {
        [$token, $ctx] = $this->asignaturaConNotas();
        $notaId = $ctx['notas'][0];

        $this->withToken($token)
            ->putJson("/api/notas/update/{$notaId}", [])
            ->assertStatus(422);

        $this->assertSame(20, (int) DB::table('notas')->where('id', $notaId)->value('nota'),
            'El cuerpo sin `nota` borró la nota: la guarda contesta 422 pero escribe antes.');

        $this->assertSame(20.0, $this->definitivaDe($ctx),
            'La definitiva se movió con una petición que se rechazó.');
    }

    /**
     * Quitar la nota: el vacío explícito la deja sin calificar (D7).
     *
     * `20 → null` en una de las cuatro casillas: la definitiva baja de **20** a
     * **15**, porque el cuarto que aportaba deja de aportar.
     */
    public function test_el_vacio_explicito_quita_la_nota(): void
    {
        [$token, $ctx] = $this->asignaturaConNotas();
        $notaId = $ctx['notas'][0];

        $this->withToken($token)
            ->putJson("/api/notas/update/{$notaId}", ['nota' => null])
            ->assertStatus(200);

        $this->assertNull(DB::table('notas')->where('id', $notaId)->value('nota'),
            'Mandar la nota vacía no la quitó.');

        $this->assertSame(15.0, $this->definitivaDe($ctx),
            'La casilla quedó vacía pero la definitiva sigue contándola.');
    }

    /**
     * Y la cadena vacía hace lo mismo, **sin que este código haga nada para
     * conseguirlo**: `ConvertEmptyStringsToNull` está en el kernel global y convierte
     * `""` en `null` antes de que la petición llegue al controlador.
     *
     * Se prueba porque es la forma que manda un `<input type="number">` al que le
     * borran el contenido, o sea **la forma real del gesto que esto viene a
     * soportar** — y porque depende de un middleware global que alguien podría
     * quitar algún día sin relacionarlo con las notas.
     */
    public function test_la_cadena_vacia_tambien_quita_la_nota(): void
    {
        [$token, $ctx] = $this->asignaturaConNotas();
        $notaId = $ctx['notas'][0];

        $this->withToken($token)
            ->putJson("/api/notas/update/{$notaId}", ['nota' => ''])
            ->assertStatus(200);

        $this->assertNull(DB::table('notas')->where('id', $notaId)->value('nota'),
            'La cadena vacía no llegó como null: ¿se quitó ConvertEmptyStringsToNull del kernel?');
    }

    /**
     * **El cero del docente sigue siendo un cero.**
     *
     * Es la mitad del arreglo que se olvida: distinguir «no calificada» de «cero» no
     * sirve de nada si al guardar un 0 se acaba en `NULL`. En la copia de desarrollo
     * hay **3.940** ceros que tecleó una persona, y son notas de verdad.
     *
     * La definitiva baja igual a 15 —un cero y un vacío aportan lo mismo a una suma
     * sin normalizar—, así que **lo que distingue este test del anterior es la fila**,
     * no el número. Ver la cabecera.
     */
    public function test_el_cero_que_teclea_el_docente_se_guarda_como_cero(): void
    {
        [$token, $ctx] = $this->asignaturaConNotas();
        $notaId = $ctx['notas'][0];

        $this->withToken($token)
            ->putJson("/api/notas/update/{$notaId}", ['nota' => 0])
            ->assertStatus(200);

        $guardada = DB::table('notas')->where('id', $notaId)->value('nota');

        $this->assertNotNull($guardada, 'El 0 del docente se guardó como «sin calificar».');
        $this->assertSame(0, (int) $guardada);
    }

    /**
     * Una casilla recién sembrada **nace sin nota**, aunque su subunidad tenga puesto
     * un valor por defecto.
     *
     * Se usa una subunidad con `nota_default = 40` a propósito: con 0 —que es lo que
     * tienen el 88 % de las subunidades— este test pasaría igual con el código viejo,
     * porque `0` y «no calificada» son indistinguibles mirando sólo el número. **Con
     * 40, la única forma de que salga `NULL` es que la siembra haya cambiado.**
     */
    public function test_una_casilla_nace_vacia_aunque_la_subunidad_tenga_nota_por_defecto(): void
    {
        [$token, $ctx] = $this->asignaturaConNotas();

        $notaId = $ctx['notas'][0];
        $subunidadId = (int) DB::table('notas')->where('id', $notaId)->value('subunidad_id');

        DB::table('subunidades')->where('id', $subunidadId)->update(['nota_default' => 40]);
        DB::table('notas')->where('id', $notaId)->delete();

        // La rejilla del profesor es la que repone las casillas que faltan.
        $this->withToken($token)->putJson('/api/notas/detailed', [
            'asignatura_id' => $ctx['asignatura'],
            'grupo_id' => $ctx['grupo'],
        ])->assertStatus(200);

        $repuesta = DB::table('notas')
            ->where('subunidad_id', $subunidadId)
            ->where('alumno_id', $ctx['alumno'])
            ->whereNull('deleted_at')
            ->first();

        $this->assertNotNull($repuesta, 'La rejilla dejó de reponer la casilla que faltaba.');
        $this->assertNull($repuesta->nota,
            'La casilla nació con el `nota_default` de su subunidad: la siembra sigue regalando nota.');
    }

    /**
     * El lote: el vacío **explícito** quita la nota.
     */
    public function test_el_lote_quita_la_nota_con_el_vacio_explicito(): void
    {
        [$token, $ctx] = $this->asignaturaConNotas();
        $notaId = $ctx['notas'][0];

        $r = $this->withToken($token)->putJson('/api/notas/lote', [
            'notas' => [['id' => $notaId, 'nota' => null]],
        ])->assertStatus(200);

        $this->assertSame(1, $r->json('guardadas'));
        $this->assertSame([], $r->json('fallidas'));
        $this->assertNull(DB::table('notas')->where('id', $notaId)->value('nota'));
    }

    /**
     * Y el ítem **sin la clave** sigue siendo un fallo, con su propio motivo.
     *
     * Es la misma línea que en `putUpdate` y por la misma razón: mandar medio cuerpo
     * no puede ser la forma de borrar una nota. Lo que cambia respecto a `putUpdate`
     * es que aquí el fallo es **por ítem** y el resto del lote se guarda, que es el
     * contrato que ya tenía esta ruta.
     */
    public function test_el_lote_rechaza_el_item_sin_la_clave_nota(): void
    {
        [$token, $ctx] = $this->asignaturaConNotas();
        [$sinClave, $conNota] = [$ctx['notas'][0], $ctx['notas'][1]];

        $r = $this->withToken($token)->putJson('/api/notas/lote', [
            'notas' => [
                ['id' => $sinClave],
                ['id' => $conNota, 'nota' => 30],
            ],
        ])->assertStatus(200);

        $this->assertSame(1, $r->json('guardadas'), 'El ítem bueno del lote no se guardó.');
        $this->assertSame($sinClave, $r->json('fallidas.0.id'));
        $this->assertStringContainsString('Para quitarla, mándala vacía', $r->json('fallidas.0.motivo'));

        $this->assertSame(20, (int) DB::table('notas')->where('id', $sinClave)->value('nota'),
            'El ítem sin la clave `nota` borró la nota en vez de caer en `fallidas`.');
        $this->assertSame(30, (int) DB::table('notas')->where('id', $conNota)->value('nota'));
    }

    private function definitivaDe(array $ctx): ?float
    {
        $valor = DB::table('notas_finales')
            ->where('alumno_id', $ctx['alumno'])
            ->where('asignatura_id', $ctx['asignatura'])
            ->where('periodo_id', $ctx['periodo'])
            ->value('nota');

        return $valor === null ? null : (float) $valor;
    }

    /**
     * Dos unidades al 50 %, dos subunidades al 50 % en cada una, y una nota de 20 en
     * cada casilla para dos alumnos. La definitiva del primero sale 20.
     *
     * Copiado de `EditarUnaNotaActualizaLaDefinitivaTest::asignaturaConNotas` con dos
     * añadidos que aquí hacen falta: devuelve el `grupo` —lo pide `notas/detailed`— y
     * **dos** ids de nota del mismo alumno, para el test del lote.
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function asignaturaConNotas(): array
    {
        // Profesor y periodo abierto: `User::pueden_editar_notas` sólo deja pasar a un
        // `Profesor` o a un superusuario, y además mira el interruptor del periodo. Con
        // cualquiera de las dos mal, estos tests fallarían por el guard y no por lo que
        // miden.
        $profesor = $this->usuarioDeTipo('Profesor');
        $token = $this->tokenDe($profesor->username);

        $suyo = DB::selectOne('SELECT p.id, p.year_id FROM periodos p
            INNER JOIN users u ON u.periodo_id = p.id WHERE u.id = ?', [$profesor->id]);

        $this->assertNotNull($suyo, 'El profesor del seed se quedó sin periodo al entrar.');

        DB::table('periodos')->where('year_id', $suyo->year_id)
            ->update(['profes_pueden_editar_notas' => 1]);

        $periodoId = (int) $suyo->id;

        $asignatura = DB::selectOne('SELECT a.id, a.grupo_id FROM asignaturas a
            INNER JOIN grupos g ON g.id = a.grupo_id AND g.deleted_at IS NULL
            INNER JOIN periodos p ON p.year_id = g.year_id AND p.id = ?
            WHERE a.deleted_at IS NULL AND EXISTS (
                SELECT 1 FROM matriculas m WHERE m.grupo_id = a.grupo_id AND m.deleted_at IS NULL
            ) ORDER BY a.id LIMIT 1', [$periodoId]);

        $this->assertNotNull($asignatura, 'El seed no tiene una asignatura con matrículas en el año del usuario.');

        $alumnos = DB::select('SELECT DISTINCT m.alumno_id FROM matriculas m
            WHERE m.grupo_id = ? AND m.deleted_at IS NULL ORDER BY m.alumno_id LIMIT 2',
            [$asignatura->grupo_id]);

        $this->assertCount(2, $alumnos, 'El montaje necesita dos alumnos matriculados.');

        $notas = [];

        foreach ([1, 2] as $numeroUnidad) {
            $unidadId = DB::table('unidades')->insertGetId([
                'asignatura_id' => $asignatura->id,
                'periodo_id' => $periodoId,
                'definicion' => 'UNIDAD DE PRUEBA '.$numeroUnidad,
                'porcentaje' => 50,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ([1, 2] as $numeroSub) {
                $subId = DB::table('subunidades')->insertGetId([
                    'unidad_id' => $unidadId,
                    'definicion' => 'SUB '.$numeroUnidad.'.'.$numeroSub,
                    'porcentaje' => 50,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                foreach ($alumnos as $alumno) {
                    $notaId = DB::table('notas')->insertGetId([
                        'subunidad_id' => $subId,
                        'alumno_id' => $alumno->alumno_id,
                        'nota' => 20,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    if ((int) $alumno->alumno_id === (int) $alumnos[0]->alumno_id) {
                        $notas[] = $notaId;
                    }
                }
            }
        }

        $ctx = [
            'asignatura' => (int) $asignatura->id,
            'grupo' => (int) $asignatura->grupo_id,
            'periodo' => $periodoId,
            'alumno' => (int) $alumnos[0]->alumno_id,
            'notas' => $notas,
        ];

        // La definitiva no existe hasta que algo la calcula. Se dispara con una
        // escritura inocua —guardar en una nota el valor que ya tenía— para que los
        // tests puedan comparar contra un número de partida en vez de contra `null`.
        $this->withToken($token)
            ->putJson("/api/notas/update/{$notas[3]}", ['nota' => 20])
            ->assertStatus(200);

        $this->assertSame(20.0, $this->definitivaDe($ctx),
            'El montaje no dejó la definitiva en 20: la aritmética de todos estos tests parte de ahí.');

        return [$token, $ctx];
    }
}
