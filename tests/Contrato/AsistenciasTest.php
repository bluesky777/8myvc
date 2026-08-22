<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * Las tres rutas de `Tardanzas\AsistenciasController`, que son **las vivas**.
 *
 * Hay dos copias casi idénticas de este controlador y la cobertura estaba en la
 * que no se usa: `AppMobile\AsistenciasAppController` tiene sus cinco rutas
 * cubiertas por `AsistenciasAppTest` desde la §57, que ya midió que **ningún
 * cliente la llama**. Ésta sí la llama `myvc_flutter` —una sola app para los
 * dieciséis colegios— y de sus cinco rutas había dos comprobadas.
 *
 * Que la copia muerta estuviera cubierta y la viva no es el hallazgo pequeño de
 * esta clase; el grande es que **ya han divergido**: la viva selecciona
 * `a.created_at` en sus cuatro consultas y la muerta no. No es casual, y se ve
 * desde el cliente: `AsistenciaModel.fromJson` de Flutter lee `created_at`.
 * Alguien lo añadió donde hacía falta y la copia se quedó atrás.
 *
 * Por eso el primer test compara las dos respuestas en vez de mirar solo ésta:
 * un test que fija una copia deja que la otra se vaya sin que nadie lo note, y
 * es lo que pasó.
 */
class AsistenciasTest extends CasoDeContrato
{
    /**
     * `detailed` trae lo que Flutter lee, y las dos copias solo difieren en una cosa.
     *
     * El cuerpo es el que manda `AlumTardanzaColeScreen`: `con_grupos` en falso y
     * el grupo. De ahí sale `alumnos`, y de cada alumno las cuatro listas ya
     * separadas —`tardanzas` y `ausencias` son las de la institución
     * (`entrada = 1`), `tardanzas_clase` y `ausencias_clase` las de cada clase—.
     *
     * Se comprueban las claves de cada falta una a una porque son el contrato de
     * `AsistenciaModel.fromJson`, y entre ellas `created_at`, que es justo la que
     * distingue las dos copias. Si algún día vuelven a coincidir, o divergen en
     * otra columna, este test lo dice con nombre en vez de dejarlo pasar.
     */
    public function test_detailed_trae_las_cuatro_listas_y_dice_en_que_difiere_la_copia(): void
    {
        $c = $this->contexto();
        $cuerpo = ['con_grupos' => false, 'grupo_id' => $c->grupo_id];

        $r = $this->withToken($c->token)->putJson('/api/asistencias/detailed', $cuerpo);
        $r->assertStatus(200);

        $alumnos = $r->json('alumnos');
        $this->assertNotEmpty($alumnos, 'El grupo pedido llegó sin alumnos: el test no mediría nada.');

        foreach (['tardanzas', 'ausencias', 'tardanzas_clase', 'ausencias_clase'] as $lista) {
            $this->assertArrayHasKey($lista, $alumnos[0],
                'Flutter separa las faltas por esta clave; sin ella la pantalla sale vacía.');
        }

        // La falta se busca en la respuesta y no se da por hecha: si el alumno del
        // seed llega sin ninguna, el `foreach` de abajo no recorre nada y el test
        // pasaría sin comprobar una sola clave. Es el seed vacío de siempre.
        $falta = $this->primeraFaltaDe($alumnos);

        $this->assertNotNull($falta, 'Ningún alumno del grupo trae faltas: hay que ponerle una al seed o el test no mide.');

        foreach (['id', 'alumno_id', 'asignatura_id', 'created_by', 'created_at',
            'entrada', 'fecha_hora', 'periodo_id', 'tipo'] as $clave) {
            $this->assertArrayHasKey($clave, $falta,
                '`AsistenciaModel.fromJson` lee esta clave; quitarla rompe la app de los dieciséis.');
        }

        // Y la copia muerta, con el mismo cuerpo: la diferencia medida el 22 ago
        // 2026 es exactamente `created_at`, y ninguna más.
        $gemela = $this->withToken($c->token)->putJson('/api/asistencias-app/detailed', $cuerpo);
        $gemela->assertStatus(200);

        $faltaGemela = $this->primeraFaltaDe($gemela->json('alumnos'));

        $this->assertNotNull($faltaGemela, 'La copia no devolvió faltas y la comparación no mediría nada.');

        $this->assertSame(['created_at'],
            array_values(array_diff(array_keys($falta), array_keys($faltaGemela))),
            'Las dos copias de este controlador han vuelto a moverse. La viva es '
            .'`Tardanzas\AsistenciasController` —la que llama myvc_flutter—; la otra '
            .'no la llama nadie. Ver 05 §57.');
    }

    /**
     * `poner-ausencia` escribe en el periodo que diga el cuerpo, igual que su copia.
     *
     * Se fija aquí y no solo en la copia porque **ésta es la que se ejecuta en los
     * dieciséis colegios**: lo que la copia muerta documentaba como contrato raro
     * resulta ser el contrato real. No se toca, por lo mismo que la §40: si
     * escribir en un periodo cerrado debe fallar es una decisión del colegio.
     */
    public function test_poner_ausencia_acepta_el_periodo_que_diga_el_cuerpo(): void
    {
        $c = $this->contexto();

        $otro = DB::selectOne('SELECT id FROM periodos WHERE id <> ? AND deleted_at IS NULL ORDER BY id LIMIT 1',
            [$c->periodo->id]);

        $this->assertNotNull($otro, 'El seed necesita un segundo periodo para que este test mida algo.');

        $r = $this->withToken($c->token)->putJson('/api/asistencias/poner-ausencia', [
            'alumno_id' => $c->alumno_id,
            'asignatura_id' => $c->asignatura_id,
            'cantidad_ausencia' => 1,
            'cantidad_tardanza' => 0,
            'entrada' => 1,
            'tipo' => 'ausencia',
            'fecha_hora' => '2026-08-22 07:00:00',
            'periodo_id' => $otro->id,
        ]);

        $r->assertStatus(200);

        $this->assertSame((int) $otro->id, (int) $r->json('periodo_id'),
            'La ausencia no se guardó en el periodo que mandó el cuerpo: alguien empezó a derivarlo, y eso cambia el contrato.');
        $this->assertNotNull($r->json('created_by'),
            'La ausencia se escribió sin autor.');
    }

    /**
     * `eliminar-ausencia` borra por el id del cuerpo, y **firma**.
     *
     * Ésta sí anota `deleted_by`, y por eso importa: es la mitad de la pareja que
     * destapó que `ausencias/destroy` —la ruta de las pantallas web y de la otra
     * mitad de Flutter— no anotaba nada. En la copia de producción del 22 ago
     * había 5.689 ausencias borradas y 5.684 sin autor; las cinco firmadas
     * salieron por aquí. Ver `AusenciasTest`.
     *
     * No mira de quién es la falta, y no es un fallo de autorización: quien llega
     * es personal y el personal lleva la asistencia del colegio. Lo que queda
     * abierto —quién puede borrar la falta de qué grupo— se decidió el 22 ago y
     * está escrito en `AusenciasController`.
     */
    public function test_eliminar_ausencia_borra_marca_la_fila_y_anota_quien_fue(): void
    {
        $c = $this->contexto();

        $id = DB::table('ausencias')->insertGetId([
            'alumno_id' => $c->alumno_id,
            'asignatura_id' => $c->asignatura_id,
            'cantidad_ausencia' => 1,
            'cantidad_tardanza' => 0,
            'entrada' => 1,
            'tipo' => 'ausencia',
            'fecha_hora' => '2026-08-22 07:00:00',
            'periodo_id' => $c->periodo->id,
            'uploaded' => 'created',
            'created_at' => '2026-08-22 07:00:00',
            'updated_at' => '2026-08-22 07:00:00',
        ]);

        $r = $this->withToken($c->token)->putJson('/api/asistencias/eliminar-ausencia', [
            'ausencia_id' => $id,
        ]);

        $r->assertStatus(200);
        $this->assertSame('Eliminada', $r->getContent(),
            'La respuesta es una cadena pelada y es el contrato: cambiarla rompería a quien la lea.');

        $fila = DB::selectOne('SELECT deleted_at, deleted_by, uploaded FROM ausencias WHERE id = ?', [$id]);

        $this->assertNotNull($fila->deleted_at, 'La ausencia no quedó borrada.');
        $this->assertSame('deleted', $fila->uploaded,
            'La marca de sincronización no se puso: es lo que el lector lee para saber qué borrar.');
        $this->assertSame((int) $c->user_id, (int) $fila->deleted_by,
            'Esta ruta sí firmaba el borrado y ha dejado de firmarlo.');
    }

    // ---------------------------------------------------------------- ayudas

    /** La primera falta que aparezca en cualquiera de las cuatro listas de cualquier alumno. */
    private function primeraFaltaDe(?array $alumnos): ?array
    {
        foreach ($alumnos ?? [] as $alumno) {
            foreach (['tardanzas', 'ausencias', 'tardanzas_clase', 'ausencias_clase'] as $lista) {
                if (! empty($alumno[$lista])) {
                    return $alumno[$lista][0];
                }
            }
        }

        return null;
    }

    /** Personal del colegio, su periodo, un grupo del año con alumnos y una falta dentro. */
    private function contexto(): object
    {
        $usuario = $this->usuarioDeTipo('Profesor');

        // El token se pide **antes** de leer el periodo: entrar mueve
        // `users.periodo_id` al vigente, y preguntarlo antes devuelve el del seed
        // —un año sin asignaturas—. Es la trampa que se llevó por delante la
        // primera versión de `AsistenciasAppTest`, con cara de que faltaba seed.
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

        // La falta se pone aquí y no se busca: el seed llega sin ninguna, y un
        // test que recorre listas vacías pasa sin comprobar nada. Van dos, una de
        // institución y otra de clase, porque las cuatro listas se separan por
        // `entrada` y con una sola la mitad del contrato no se mediría.
        foreach ([1, 0] as $entrada) {
            DB::table('ausencias')->insert([
                'alumno_id' => $alumno->alumno_id,
                'asignatura_id' => $asignatura->id,
                'periodo_id' => $periodo->id,
                'cantidad_ausencia' => 1,
                'cantidad_tardanza' => 0,
                'entrada' => $entrada,
                'tipo' => 'ausencia',
                'fecha_hora' => '2026-08-22 07:00:00',
                'uploaded' => 'created',
                'created_by' => $usuario->id,
                'created_at' => '2026-08-22 07:00:00',
                'updated_at' => '2026-08-22 07:00:00',
            ]);
        }

        return (object) [
            'token' => $token,
            'user_id' => $usuario->id,
            'periodo' => $periodo,
            'grupo_id' => $asignatura->grupo_id,
            'asignatura_id' => $asignatura->id,
            'alumno_id' => $alumno->alumno_id,
        ];
    }
}
