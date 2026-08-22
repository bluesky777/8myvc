<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * Las cinco rutas de `AppMobile\AsistenciasAppController`, que estaban a 1 de 5.
 *
 * El controlador vive en `AppMobile/` y por el nombre parece el que usa
 * `myvc_flutter`. **No lo es**: el Flutter llama a `asistencias/detailed` y a
 * `ausencias/*`, que son otros dos controladores con los mismos nombres de
 * método. Está medido y contado en la
 * [§57](../../docs/migracion/05-codigo-muerto-y-roto.md). Estas cinco no las
 * llama ningún cliente hoy.
 *
 * Eso no las deja fuera: **están enrutadas y son `auth.personal`**, así que
 * cualquiera del personal las alcanza aunque ninguna pantalla las use. Lo que
 * este test fija es qué responden y qué escriben hoy, que es lo que hay que
 * saber antes de decidir si se arreglan o se quitan.
 */
class AsistenciasAppTest extends CasoDeContrato
{
    /**
     * `POST api/asistencias-app` no puede funcionar, y no escribe nada.
     *
     * Son dos fallos en el mismo método: la consulta lleva `:asignatura_id` y el
     * array de datos no lo incluye —PDO revienta antes de insertar— y la línea
     * siguiente hace `$datos->id = $id` sobre un **array**, que en PHP 8.4 es un
     * `Error`. Se deja rota (§57): los dos fallos son la especificación de lo
     * que la pantalla pretendía hacer, y un 404 la perdería.
     *
     * Lo que fija este test es que **falla sin dejar rastro**. Si algún día
     * empieza a escribir la fila y luego revienta, eso sí es otra cosa: sería
     * una ruta que miente al cliente después de haber escrito.
     */
    public function test_el_post_de_la_raiz_revienta_y_no_deja_ausencia(): void
    {
        $c = $this->contexto();

        $antes = $this->cuantasAusenciasDe($c->alumno_id);

        $r = $this->withToken($c->token)->postJson('/api/asistencias-app', [
            'alumno_id' => $c->alumno_id,
            'asignatura_id' => $c->asignatura_id,
            'cantidad_ausencia' => 1,
            'cantidad_tardanza' => 0,
            'entrada' => 1,
            'tipo' => 'ausencia',
            'fecha_hora' => '2026-08-22 07:00:00',
            'periodo_id' => $c->periodo->id,
        ]);

        $r->assertStatus(500);

        $this->assertSame($antes, $this->cuantasAusenciasDe($c->alumno_id),
            'El POST reventó pero dejó la fila escrita: entonces no es código roto, es una ruta que miente.');
    }

    /**
     * `detailed` devuelve los grupos del año del usuario y los alumnos del que se pida.
     *
     * El año sale del token y no del cuerpo, que es lo que hay que fijar: es la
     * diferencia con `datos-solo-alumnos`, aquí al lado, que sí lo lee del
     * cuerpo y por eso está en su propio test.
     */
    public function test_detailed_trae_los_grupos_del_ano_del_usuario(): void
    {
        $c = $this->contexto();

        $r = $this->withToken($c->token)->putJson('/api/asistencias-app/detailed', [
            'con_grupos' => true,
            'grupo_id' => $c->grupo_id,
        ]);

        $r->assertStatus(200);

        $cuerpo = $r->json();

        $this->assertArrayHasKey('grupos', $cuerpo);
        $this->assertArrayHasKey('alumnos', $cuerpo);
        $this->assertNotEmpty($cuerpo['alumnos'], 'El grupo pedido llegó sin alumnos: el test no mediría nada.');

        $anios = array_unique(array_column($cuerpo['grupos'], 'year_id'));

        $this->assertSame([$c->periodo->year_id], array_values($anios),
            'Los grupos que devuelve no son todos del año del usuario.');

        // Cada alumno trae sus cuatro recuentos: son los que la pantalla suma, y
        // que estén a 0 es una respuesta válida; que no estén es un cambio de
        // contrato.
        foreach (['ausencias_count', 'tardanzas_count', 'ausencias_clase_count', 'tardanzas_clase_count'] as $clave) {
            $this->assertArrayHasKey($clave, $cuerpo['alumnos'][0]);
        }
    }

    /**
     * `datos-solo-alumnos` lee el año **del cuerpo**, y por defecto el 4.
     *
     * No es un fallo de autorización —quien llega es personal, y el personal ve
     * el colegio— pero sí un contrato raro que conviene tener fijado: la misma
     * ruta devuelve un año u otro según lo que mande el cliente, y si no manda
     * nada devuelve el año cuyo id sea 4, que en un colegio cualquiera no tiene
     * por qué ser el actual ni existir.
     */
    public function test_datos_solo_alumnos_obedece_al_ano_del_cuerpo(): void
    {
        $c = $this->contexto();

        // Se pide el año que NO es el suyo a propósito. Pedir el propio no
        // distingue nada: la respuesta saldría igual si el año lo decidiera el
        // token, y el test pasaría sin medir. Es la misma trampa del seed vacío,
        // con otra cara.
        $ajeno = DB::selectOne('SELECT g.year_id FROM grupos g
            WHERE g.year_id <> ? AND g.deleted_at IS NULL
            GROUP BY g.year_id ORDER BY g.year_id LIMIT 1', [$c->periodo->year_id]);

        $this->assertNotNull($ajeno, 'El seed necesita grupos en un segundo año para que este test mida algo.');

        $r = $this->withToken($c->token)->getJson('/api/asistencias-app/datos-solo-alumnos?year_id='.$ajeno->year_id);

        $r->assertStatus(200);

        $grupos = $r->json('grupos');

        $this->assertNotEmpty($grupos, 'El año pedido llegó sin grupos: el test no mediría nada.');

        $anios = array_unique(array_column($grupos, 'year_id'));

        $this->assertSame([$ajeno->year_id], array_values($anios),
            'La ruta devolvió el año del token y no el del cuerpo: alguien empezó a derivarlo, y eso cambia el contrato.');
    }

    /**
     * `poner-ausencia` escribe en el periodo **que diga el cuerpo**, no en el suyo.
     *
     * Es la misma familia que la [§40](../../docs/migracion/05-codigo-muerto-y-roto.md):
     * crear una ausencia no comprueba el periodo y editarla sí. Aquí ni siquiera
     * se compara con el del token — el `periodo_id` entra tal cual desde
     * `Request::input` y se inserta.
     *
     * **Queda fijado, no arreglado**, por lo mismo que la §40: si escribir en un
     * periodo cerrado debe fallar es una decisión del colegio, y taparlo aquí
     * cerraría una pantalla en dieciséis sin avisar.
     */
    public function test_poner_ausencia_acepta_el_periodo_que_diga_el_cuerpo(): void
    {
        $c = $this->contexto();

        $otro = DB::selectOne('SELECT id FROM periodos WHERE id <> ? AND deleted_at IS NULL ORDER BY id LIMIT 1',
            [$c->periodo->id]);

        $this->assertNotNull($otro, 'El seed necesita un segundo periodo para que este test mida algo.');

        $r = $this->withToken($c->token)->putJson('/api/asistencias-app/poner-ausencia', [
            'alumno_id' => $c->alumno_id,
            'asignatura_id' => $c->asignatura_id,
            'cantidad_ausencia' => 1,
            'cantidad_tardanza' => 0,
            'entrada' => 0,
            'tipo' => 'ausencia',
            'fecha_hora' => '2026-08-22 07:00:00',
            'periodo_id' => $otro->id,
        ]);

        $r->assertStatus(200);

        $this->assertSame((int) $otro->id, (int) $r->json('periodo_id'),
            'La ausencia no se guardó en el periodo que mandó el cuerpo: alguien empezó a derivarlo, y eso cambia el contrato.');
    }

    /**
     * `eliminar-ausencia` borra por el id del cuerpo, sin preguntar de quién es.
     *
     * Quien llega es personal y el personal lleva la asistencia del colegio, así
     * que no es un fallo de autorización. Lo que se fija es lo otro: **no mira el
     * periodo** —ni el del token ni el de la fila— y el borrado es blando con
     * `uploaded = 'deleted'`, que es la marca que la app móvil usaba para
     * sincronizar. Esa marca es contrato con un cliente que hoy no existe.
     */
    public function test_eliminar_ausencia_borra_por_el_id_del_cuerpo_y_marca_la_fila(): void
    {
        $c = $this->contexto();

        $id = DB::table('ausencias')->insertGetId([
            'alumno_id' => $c->alumno_id,
            'asignatura_id' => $c->asignatura_id,
            'cantidad_ausencia' => 1,
            'cantidad_tardanza' => 0,
            'entrada' => 0,
            'tipo' => 'ausencia',
            'fecha_hora' => '2026-08-22 07:00:00',
            'periodo_id' => $c->periodo->id,
            'uploaded' => 'created',
            'created_at' => '2026-08-22 07:00:00',
            'updated_at' => '2026-08-22 07:00:00',
        ]);

        $r = $this->withToken($c->token)->putJson('/api/asistencias-app/eliminar-ausencia', [
            'ausencia_id' => $id,
        ]);

        $r->assertStatus(200);
        $this->assertSame('Eliminada', $r->getContent(),
            'La respuesta es una cadena pelada y es el contrato: cambiarla rompería a quien la lea.');

        $fila = DB::selectOne('SELECT deleted_at, uploaded FROM ausencias WHERE id = ?', [$id]);

        $this->assertNotNull($fila->deleted_at, 'La ausencia no quedó borrada.');
        $this->assertSame('deleted', $fila->uploaded,
            'La marca de sincronización no se puso: es lo que un cliente móvil leería para saber qué borrar.');
    }

    // ---------------------------------------------------------------- ayudas

    /** Personal del colegio, su periodo, y un grupo del año con alumnos dentro. */
    private function contexto(): object
    {
        $usuario = $this->usuarioDeTipo('Profesor');

        // El token se pide **antes** de leer el periodo, y no es cosmético:
        // entrar mueve `users.periodo_id` al periodo vigente, así que
        // preguntarlo antes devuelve el del seed —un año sin asignaturas— y
        // todo lo que cuelga de ahí sale vacío. La primera versión de este test
        // se cayó entera por ese orden, con el mensaje de que faltaba seed.
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

        return (object) [
            'token' => $token,
            'periodo' => $periodo,
            'grupo_id' => $asignatura->grupo_id,
            'asignatura_id' => $asignatura->id,
            'alumno_id' => $alumno->alumno_id,
        ];
    }

    private function cuantasAusenciasDe(int $alumno_id): int
    {
        return (int) DB::selectOne('SELECT COUNT(*) n FROM ausencias WHERE alumno_id = ?', [$alumno_id])->n;
    }
}
