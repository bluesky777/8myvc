<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * **El recorrido del día de matrículas** — fase 1 del proceso de admisión.
 *
 *     GET requisitos/recorrido/{alumno_id}   ¿puede continuar, o a dónde se devuelve?
 *
 * Lo que este fichero defiende es el requisito literal de Joseth: *«si una estación
 * busca al estudiante y ve que el requisito 2 no está marcado y esta es la estación
 * 4, entonces le dice que se devuelva a la estación 3»*.
 *
 * ## Y sobre todo defiende lo que decidió DESPUÉS, que es lo que lo hace desplegable
 *
 * A la pregunta *«¿«falta» significa que la familia no entregó, o que nadie lo
 * marcó?»* contestó **«las dos cosas, según la estación»**. Eso convierte `bloquea`
 * de lujo en necesidad: **el bloqueo no se puede encender de golpe**. Donde el dato
 * es fiable frena; donde nadie marca, informa y deja pasar.
 *
 * Por eso aquí hay dos pruebas que parecen la misma y no lo son: **un pendiente que
 * NO bloquea tiene que salir en la lista y NO frenar**. Un test que sólo mirase
 * `puede_continuar` pasaría igual el día que alguien hiciera bloquear a todos.
 */
class ElDiaDeMatriculasTest extends CasoDeContrato
{
    private const RUTA = '/api/requisitos';

    private ?string $token = null;

    public function test_sin_pasos_configurados_puede_continuar(): void
    {
        $alumno = $this->unAlumno();

        $r = $this->withToken($this->tokenLlano())
            ->getJson(self::RUTA.'/recorrido/'.$alumno->id);

        $r->assertStatus(200);
        $this->assertTrue($r->json('puede_continuar'));
        $this->assertNull($r->json('devolver_a'));
        $this->assertSame([], $r->json('pendientes'));
    }

    /**
     * **El requisito literal**: el primero que bloquea es a donde se devuelve.
     *
     * Y se comprueba que es **el primero** y no el último: mandarla al último sería
     * mandarla al final de un recorrido que todavía no ha hecho.
     */
    public function test_devuelve_a_la_primera_estacion_que_bloquea(): void
    {
        $alumno = $this->unAlumno();
        $this->unosPasos([
            ['orden' => 1, 'requisito' => 'Recepción', 'bloquea' => 1],
            ['orden' => 2, 'requisito' => 'Documentos', 'bloquea' => 1],
            ['orden' => 3, 'requisito' => 'Académico', 'bloquea' => 1],
        ]);

        // La familia cerró la 1 y llega a la 4 sin haber pasado por la 2 ni la 3.
        $this->marcar($alumno->id, 'Recepción', 'ya');

        $r = $this->withToken($this->tokenLlano())
            ->getJson(self::RUTA.'/recorrido/'.$alumno->id)->assertStatus(200);

        $this->assertFalse($r->json('puede_continuar'));
        $this->assertSame(2, $r->json('devolver_a.estacion'),
            'Devuelve a otra estación que no es la primera pendiente que frena.');
        $this->assertSame('Documentos', $r->json('devolver_a.requisito'),
            'El 409 dice el número pero no qué falta, así que en la estación no saben qué pedir.');
    }

    /**
     * **La mitad que hace esto desplegable**: un pendiente que NO bloquea **sale en
     * la lista y no frena**.
     *
     * Es la respuesta de Joseth del 20 sep —«falta» significa dos cosas según la
     * estación— convertida en comportamiento. Donde nadie marca, el recorrido
     * informa en vez de mandar de vuelta a una familia que sí entregó.
     */
    public function test_un_pendiente_que_no_bloquea_informa_pero_no_frena(): void
    {
        $alumno = $this->unAlumno();
        $this->unosPasos([
            ['orden' => 1, 'requisito' => 'Recepción', 'bloquea' => 1],
            ['orden' => 2, 'requisito' => 'Encuesta de transporte', 'bloquea' => 0],
        ]);

        $this->marcar($alumno->id, 'Recepción', 'ya');

        $r = $this->withToken($this->tokenLlano())
            ->getJson(self::RUTA.'/recorrido/'.$alumno->id)->assertStatus(200);

        $this->assertTrue($r->json('puede_continuar'),
            'Un paso opcional está frenando la cola. El colegio no puede encender el '
            .'bloqueo estación por estación si todo bloquea igual.');

        $this->assertNull($r->json('devolver_a'));

        $this->assertSame(['Encuesta de transporte'], array_column($r->json('pendientes'), 'requisito'),
            'El paso opcional pendiente no sale en la lista, así que nadie se acuerda de él nunca.');

        $this->assertFalse($r->json('pendientes.0.bloquea'));
    }

    /**
     * **Un paso que nadie ha tocado no tiene fila en `requisitos_alumno`**, y es
     * justo el caso de quien acaba de llegar.
     *
     * Se comprueba porque el `JOIN` natural sería `INNER` y dejaría el recorrido de
     * la mañana **vacío**: cero pendientes y «puede continuar», que es lo contrario
     * de la verdad.
     */
    public function test_un_paso_sin_marcar_cuenta_como_pendiente(): void
    {
        $alumno = $this->unAlumno();
        $this->unosPasos([['orden' => 1, 'requisito' => 'Documentos', 'bloquea' => 1]]);

        $this->assertSame(0, (int) DB::selectOne('SELECT COUNT(*) c FROM requisitos_alumno
            WHERE alumno_id=?', [$alumno->id])->c, 'El escenario no es el que dice.');

        $r = $this->withToken($this->tokenLlano())
            ->getJson(self::RUTA.'/recorrido/'.$alumno->id)->assertStatus(200);

        $this->assertFalse($r->json('puede_continuar'),
            'Quien acaba de llegar sale como «puede continuar»: el JOIN se está comiendo '
            .'los pasos que nadie ha tocado, que son todos los de la mañana.');
        $this->assertCount(1, $r->json('pendientes'));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // La firma de quien cierra
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * **`cerrado_por` no es `updated_by`**, y la prueba es que tocar la observación
     * después **no reescribe quién cerró**.
     *
     * Sin esa diferencia, al final del día la columna diría quién pasó por ahí el
     * último, que no es la pregunta que se hace nadie.
     */
    public function test_quien_cierra_queda_con_su_nombre_y_su_hora(): void
    {
        $alumno = $this->unAlumno();
        $this->unosPasos([['orden' => 1, 'requisito' => 'Documentos', 'bloquea' => 1]]);

        $this->marcar($alumno->id, 'Documentos', 'ya');

        $fila = DB::selectOne('SELECT ra.cerrado_por, ra.cerrado_at, ra.updated_by
            FROM requisitos_alumno ra WHERE ra.alumno_id=?', [$alumno->id]);

        $this->assertNotNull($fila->cerrado_por, 'Nadie firmó el cierre.');
        $this->assertNotNull($fila->cerrado_at);

        $quienCerro = $fila->cerrado_por;

        // Otra persona corrige la observación más tarde.
        $marca = DB::selectOne('SELECT id FROM requisitos_alumno WHERE alumno_id=?', [$alumno->id]);

        $this->withToken($this->tokenDeOtro())->postJson(self::RUTA.'/alumno',
            ['requisito_alumno_id' => $marca->id, 'descripcion' => 'nota añadida después'])
            ->assertStatus(200);

        $despues = DB::selectOne('SELECT cerrado_por, updated_by FROM requisitos_alumno
            WHERE id=?', [$marca->id]);

        $this->assertSame((int) $quienCerro, (int) $despues->cerrado_por,
            'Corregir una observación reescribió quién cerró el paso. `cerrado_por` es quien '
            .'lo chuleó, no quien tocó la fila por última vez.');

        $this->assertNotSame((int) $quienCerro, (int) $despues->updated_by,
            'El escenario no mide nada: las dos personas son la misma.');
    }

    /** Y el recorrido lo enseña con nombre, no con un id. */
    public function test_el_recorrido_dice_quien_cerro_cada_paso(): void
    {
        $alumno = $this->unAlumno();
        $this->unosPasos([['orden' => 1, 'requisito' => 'Documentos', 'bloquea' => 1]]);
        $this->marcar($alumno->id, 'Documentos', 'ya');

        $paso = $this->withToken($this->tokenLlano())
            ->getJson(self::RUTA.'/recorrido/'.$alumno->id)->assertStatus(200)->json('pasos.0');

        $this->assertTrue($paso['cumplido']);
        $this->assertNotNull($paso['cerrado_at']);
        $this->assertArrayHasKey('cerrado_por_nombres', $paso);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Lo que la pantalla vieja no puede romper
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * **La pantalla vieja no puede apagar el recorrido sin querer.**
     *
     * Está desplegada en los dieciséis colegios y manda `requisito` y `descripcion` y
     * nada más. Si `putUpdate` escribiera `orden` y `bloquea` incondicionalmente,
     * **corregir una tilde en el nombre de un paso desharía el recorrido del día** —
     * sin error y sin que nadie lo note hasta la cola.
     *
     * Es el mismo caso que el `valor` del formulario de inscripción, donde la
     * pantalla vieja «no revienta: apaga el cobro sin querer». Allí se avisó; aquí se
     * impide, y lo fija esto.
     */
    public function test_editar_el_texto_de_un_paso_no_apaga_su_bloqueo(): void
    {
        $this->unosPasos([['orden' => 3, 'requisito' => 'Documentos', 'bloquea' => 1]]);

        $paso = DB::selectOne('SELECT id FROM requisitos_matricula
            WHERE requisito="Documentos" AND deleted_at IS NULL ORDER BY id DESC');

        // Exactamente lo que manda la pantalla vieja: los dos textos y nada más.
        $this->withToken($this->tokenLlano())->putJson(self::RUTA.'/update', [
            'id' => $paso->id,
            'requisito' => 'Documentós',
            'descripcion' => 'con tilde',
        ])->assertStatus(200);

        $despues = DB::selectOne('SELECT orden, bloquea FROM requisitos_matricula WHERE id=?',
            [$paso->id]);

        $this->assertSame(3, (int) $despues->orden,
            'Editar el texto movió el número de estación: el recorrido del día se deshizo.');
        $this->assertSame(1, (int) $despues->bloquea,
            'Editar el texto apagó el bloqueo. Una pantalla desplegada en dieciséis colegios '
            .'no puede desactivar un freno corrigiendo una tilde.');
    }

    // ─────────────────────────────────────────────────────────────────────────

    public function test_un_alumno_que_no_existe_es_404_y_uno_invalido_422(): void
    {
        $token = $this->tokenLlano();

        $this->withToken($token)->getJson(self::RUTA.'/recorrido/999999999')->assertStatus(404);
        $this->withToken($token)->getJson(self::RUTA.'/recorrido/no-es-un-id')->assertStatus(422);
    }

    public function test_un_alumno_no_mira_el_recorrido_de_nadie(): void
    {
        $token = $this->tokenDe($this->usuarioDeTipo('Alumno')->username);

        $this->withToken($token)->getJson(self::RUTA.'/recorrido/'.$this->unAlumno()->id)
            ->assertStatus(403);
    }

    public function test_sin_token_no_contesta(): void
    {
        $this->getJson(self::RUTA.'/recorrido/1')->assertStatus(401);
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function unAlumno(): object
    {
        $alumno = DB::selectOne('SELECT id FROM alumnos WHERE deleted_at IS NULL ORDER BY id LIMIT 1');

        $this->assertNotNull($alumno, 'El seed no tiene alumnos: esto no mediría nada.');

        return $alumno;
    }

    /**
     * Monta el recorrido del año de la sesión **a través de la ruta**, no con un
     * `INSERT`: así el escenario prueba de paso que `postStore` guarda `orden` y
     * `bloquea`, que es la mitad de la configuración.
     *
     * @param  list<array{orden: int, requisito: string, bloquea: int}>  $pasos
     */
    private function unosPasos(array $pasos): void
    {
        $year = (int) DB::selectOne('SELECT id FROM years WHERE actual=1 AND deleted_at IS NULL')->id;

        foreach ($pasos as $paso) {
            $this->withToken($this->tokenLlano())->postJson(self::RUTA.'/store', [
                'year_id' => $year,
                'requisito' => $paso['requisito'],
                'descripcion' => '',
                'orden' => $paso['orden'],
                'bloquea' => (bool) $paso['bloquea'],
            ])->assertStatus(200);
        }
    }

    /**
     * Cierra un paso como lo cierra una estación: por la ruta.
     *
     * **El estado era `'ok'` hasta el 20 sep 2026 y ahora es `'ya'`**, que es lo que
     * manda de verdad la ficha del alumno en los dieciséis colegios. `'ok'` no lo
     * escribe nadie: se lo inventó este test cuando `estado` era un `varchar` que
     * aceptaba cualquier cosa, y desde que existe `App\Support\EstadosDelPaso` la ruta
     * lo rechaza con 422.
     *
     * *Los cinco rojos que eso produjo son el candado funcionando en su primer contacto
     * con el código que ya estaba* — y el arreglo es mandar un valor real, no ensanchar
     * la lista para que quepa uno que se inventó una prueba.
     */
    private function marcar(int $alumnoId, string $requisito, string $estado): void
    {
        $paso = DB::selectOne('SELECT id FROM requisitos_matricula
            WHERE requisito=? AND deleted_at IS NULL ORDER BY id DESC', [$requisito]);

        $this->assertNotNull($paso, "No existe el paso «{$requisito}».");

        DB::insert('INSERT INTO requisitos_alumno (alumno_id, requisito_id, estado, created_at, updated_at)
            VALUES (?,?,"falta",NOW(),NOW())', [$alumnoId, $paso->id]);

        $marca = (int) DB::getPdo()->lastInsertId();

        $this->withToken($this->tokenLlano())->postJson(self::RUTA.'/alumno',
            ['requisito_alumno_id' => $marca, 'estado' => $estado])->assertStatus(200);
    }

    private function tokenLlano(): string
    {
        return $this->token ??= $this->tokenDelPersonalLlano();
    }

    /** Alguien del personal que NO es el de `tokenLlano`, para separar las dos firmas. */
    private function tokenDeOtro(): string
    {
        $otro = DB::selectOne('SELECT username FROM users
            WHERE is_superuser=1 AND deleted_at IS NULL AND is_active=1 LIMIT 1');

        $this->assertNotNull($otro, 'El seed no tiene superusuarios.');

        return $this->tokenDe($otro->username);
    }
}
