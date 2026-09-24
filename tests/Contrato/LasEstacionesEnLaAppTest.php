<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * **Las estaciones del día de matrículas, atendidas desde el teléfono.**
 *
 * Nueve rutas. El contrato está en `docs/migracion/46-las-estaciones-en-la-app.md`
 * y las doce pantallas en `myvc_flutter/docs/estaciones.md`.
 *
 * ## LO QUE ESTE FICHERO DEFIENDE DE VERDAD, que no es el 200
 *
 * Tres cosas, y ninguna se ve leyendo el código:
 *
 * 1. **Que la cola no dependa de `estado`.** Esa columna es un `varchar(255)` sin
 *    vocabulario cerrado que escriben tres pantallas viejas desplegadas en dieciséis
 *    colegios, y **el desacuerdo existe hoy**: `AlumnosController:899` inserta
 *    `"falta"` en minúscula mientras el defecto de la tabla es `'Falta'`. Una cola
 *    apoyada ahí borraría a una persona de la fila siguiente sin que nadie se
 *    entere. Lo fija `test_una_pantalla_vieja_no_borra_a_nadie_de_la_fila`.
 *
 * 2. **Que una nota escrita en la estación 5 mueva la huella de la 2.** Es la trampa
 *    de la §3.3 del 46, y es la que *«se ve sola si la huella está bien hecha y no
 *    se ve nunca si está mal»*: quien tiene delante a esa familia es el de la 2, y
 *    si su huella no se mueve el globo sale cuando alguien recargue a mano — o sea,
 *    cuando ya no sirve.
 *
 * 3. **Que devolver y reabrir DESCIERREN el paso.** `cerrado_at` se escribía con
 *    `COALESCE`, o sea una sola vez, así que desmarcar a alguien lo dejaba
 *    **invisible en su estación y visible en la siguiente**.
 *
 * ## Y una que se comprueba MIRANDO LA BASE y no la respuesta
 *
 * `enviar-a` promete que el salteado **no deja marca en el paso**. Un test que
 * mirase el 200 pasaría igual escribiéndolo. Hay que ir a ver que la fila no está.
 */
class LasEstacionesEnLaAppTest extends CasoDeContrato
{
    private const RUTA = '/api/estaciones';

    private ?string $token = null;

    private ?string $otro = null;

    // ------------------------------------------------------------------
    // El recorrido
    // ------------------------------------------------------------------

    public function test_sin_estaciones_configuradas_la_campana_esta_cerrada(): void
    {
        $r = $this->withToken($this->tokenLlano())->getJson(self::RUTA)->assertStatus(200);

        $this->assertFalse($r->json('campana.abierta'),
            'Sin pasos configurados la app no debe enseñar la entrada del menú.');
        $this->assertSame([], $r->json('estaciones'));
    }

    /**
     * **Una estación es un GRUPO de requisitos que comparten `orden`**, no una fila.
     *
     * Es la decisión de Joseth del 20 sep —*«el número impreso es el `orden` que ya
     * existe»*—, y de ella sale que la estación 2 pueda pedir dos papeles.
     */
    public function test_una_estacion_agrupa_los_requisitos_del_mismo_orden(): void
    {
        $this->unosPasos([
            ['orden' => 1, 'requisito' => 'Recepción', 'bloquea' => 1],
            ['orden' => 2, 'requisito' => 'Documentos', 'bloquea' => 1],
            ['orden' => 2, 'requisito' => 'Carpeta', 'bloquea' => 0],
        ]);

        $r = $this->withToken($this->tokenLlano())->getJson(self::RUTA)->assertStatus(200);

        $this->assertCount(2, $r->json('estaciones'),
            'Dos requisitos con el mismo `orden` son UNA estación, no dos.');
        $this->assertSame(2, $r->json('estaciones.1.nro'));
        $this->assertCount(2, $r->json('estaciones.1.requisitos'));
        $this->assertTrue($r->json('estaciones.1.bloquea'),
            'Una estación frena si frena cualquiera de los papeles que se entregan en ella.');
    }

    /**
     * **`orden` vale 0 y no es un caso raro: es lo que hay hoy.**
     *
     * La columna tiene defecto 0 y en la copia de desarrollo la única fila real vale
     * 0. Un colegio que no haya numerado sus pasos los tiene todos en la estación 0,
     * y `estaciones/0/cola` tiene que contestar 200 y no 422.
     */
    public function test_la_estacion_cero_es_una_estacion(): void
    {
        $this->unosPasos([['orden' => 0, 'requisito' => 'Sin numerar', 'bloquea' => 0]]);

        $this->withToken($this->tokenLlano())->getJson(self::RUTA.'/0/cola')
            ->assertStatus(200)->assertJsonPath('nro', 0);
    }

    // ------------------------------------------------------------------
    // La cola
    // ------------------------------------------------------------------

    /** La regla: el paso anterior cerrado y el mío abierto. */
    public function test_la_cola_son_los_que_cerraron_la_anterior(): void
    {
        [$ana, $beto] = $this->dosAlumnos();
        $this->unRecorridoDeTres();

        $this->cerrar($ana, 1);                 // Ana pasó Recepción: le toca la 2
        $this->cerrar($beto, 1);
        $this->cerrar($beto, 2);                // Beto ya hizo la 2: le toca la 3

        $this->assertSame([$ana], $this->colaDe(2), 'La cola de la 2 debería ser sólo Ana.');
        $this->assertSame([$beto], $this->colaDe(3), 'La cola de la 3 debería ser sólo Beto.');
    }

    /**
     * **Quien no ha cerrado nada NO sale en ninguna cola**, y eso es lo correcto: no
     * ha llegado.
     *
     * Es la decisión que evita que la cola de la primera estación sean los mil
     * trescientos alumnos del colegio — que no es una cola, es un censo. A esa
     * persona la atiende la primera estación **escaneando su papel o buscándola**,
     * que son las pantallas 03 y 10 y no necesitan cola.
     */
    public function test_quien_no_ha_llegado_no_ocupa_sitio_en_ninguna_cola(): void
    {
        $this->dosAlumnos();
        $this->unRecorridoDeTres();

        foreach ([1, 2, 3] as $nro) {
            $this->assertSame([], $this->colaDe($nro),
                "La estación {$nro} tiene a alguien que no ha cerrado un solo paso.");
        }
    }

    /**
     * **El salteado sí sale en la cola de la primera**, con su aviso.
     *
     * Hizo la 2 sin pasar por la 1. Si la primera estación no lo recogiera, nadie le
     * reclamaría nunca el paso que debe.
     */
    public function test_el_salteado_aparece_en_la_primera_con_su_aviso(): void
    {
        [$ana] = $this->dosAlumnos();
        $this->unRecorridoDeTres();

        $this->cerrar($ana, 2);                 // se saltó la 1

        $this->assertSame([$ana], $this->colaDe(1));

        $r = $this->withToken($this->tokenLlano())->getJson(self::RUTA.'/1/cola');
        $this->assertSame('salteado', $r->json('cola.0.avisos.0.tipo'));
    }

    /**
     * **Una estación de dos papeles no está hecha con uno.**
     *
     * El conteo sale del catálogo y no de las filas que existan: `requisitos_alumno`
     * se rellena de forma perezosa, así que contar sobre lo que hay daría la estación
     * por cerrada en cuanto se cierre la única fila creada.
     */
    public function test_cerrar_uno_de_dos_requisitos_no_cierra_la_estacion(): void
    {
        [$ana] = $this->dosAlumnos();
        $this->unosPasos([
            ['orden' => 1, 'requisito' => 'Recepción', 'bloquea' => 1],
            ['orden' => 2, 'requisito' => 'Documentos', 'bloquea' => 1],
            ['orden' => 2, 'requisito' => 'Carpeta', 'bloquea' => 0],
            ['orden' => 3, 'requisito' => 'Tesorería', 'bloquea' => 1],
        ]);

        $this->cerrar($ana, 1);
        $this->marcar($ana, 2, 'cumple', null, [$this->requisitoLlamado('Documentos')]);

        $this->assertSame([$ana], $this->colaDe(2),
            'Con uno de los dos papeles entregados la estación sigue siendo suya.');
        $this->assertSame([], $this->colaDe(3),
            'No puede estar ya en la 3: todavía le falta la Carpeta.');
    }

    /**
     * **EL CONTROL DE ESTE MÓDULO: una pantalla vieja no borra a nadie de la fila.**
     *
     * Las tres pantallas desplegadas escriben en `estado` lo que les da la gana, y el
     * desacuerdo **existe hoy** (`"falta"` en minúscula contra `'Falta'`). Aquí se
     * escribe `cumplió` —una cadena que no está en ningún vocabulario— por la ruta
     * vieja, y la cola tiene que seguir funcionando porque se apoya en `cerrado_at`.
     */
    public function test_una_pantalla_vieja_no_borra_a_nadie_de_la_fila(): void
    {
        [$ana] = $this->dosAlumnos();
        $this->unRecorridoDeTres();

        $this->unEstadoHeredado($ana, 1, 'cumplió con todo');

        $this->assertSame([$ana], $this->colaDe(2),
            'La cola se rompió con un `estado` que no está en ningún vocabulario: '
            .'está mirando `estado` en vez de `cerrado_at`.');
    }

    // ------------------------------------------------------------------
    // La huella
    // ------------------------------------------------------------------

    /**
     * **LA TRAMPA DE LA §3.3, y es la prueba que justifica el fichero entero.**
     *
     * Una nota escrita en la **estación 5** tiene que mover la huella de la
     * **estación 2**, porque es el de la 2 quien tiene delante a esa familia y es su
     * pantalla la que pinta el globo.
     *
     * Calculada «por estación anotada» —que es el reflejo natural— la huella de la 2
     * no se movería y el globo saldría cuando alguien recargase a mano. La regla que
     * lo resuelve no es nueva: es la del 34, *«se calcula sobre lo que devuelve la
     * lectura, no sobre la tabla»*, y la cola de la 2 devuelve `notas_total` de
     * **todas** las estaciones de esa persona.
     *
     * ## Y ESTE TEST ENCONTRÓ LA TERCERA CIFRA DE LA HUELLA
     *
     * Escrito primero mirando sólo `ultimo_cambio`, **falló** — y no por un error de
     * la huella: `timestamp` tiene precisión de **segundo**, así que la nota y el
     * cierre del paso anterior caen en el mismo sello y el `MAX` no se mueve. Con
     * `n` tampoco, porque una nota no cambia quién espera.
     *
     * O sea que la nota era **invisible**, y en producción también: en un patio,
     * cerrar un paso y que el de al lado escriba una nota en el mismo segundo es
     * media mañana de un sábado. Por eso la huella lleva `notas`, y por eso este
     * test compara **la entrada entera** de la estación y no un campo: lo que tiene
     * que cambiar es «lo que la 2 sabe», no una columna concreta.
     */
    public function test_una_nota_en_la_cinco_mueve_la_huella_de_la_dos(): void
    {
        [$ana] = $this->dosAlumnos();
        $this->unRecorridoDeTres();
        $this->cerrar($ana, 1);                 // Ana espera en la 2

        $antes = $this->huella();

        // El tesorero deja escrito en la estación 3 que esa familia debe algo.
        $this->withToken($this->tokenDeOtro())->postJson(self::RUTA.'/3/nota', [
            'alumno_id' => $ana,
            'texto' => 'Saldo pendiente de la matrícula anterior.',
            'pendiente' => true,
        ])->assertStatus(200);

        $despues = $this->huella();

        $this->assertNotSame($antes[2], $despues[2],
            'La huella de la estación 2 no se movió al escribir una nota en la 3. '
            .'Está calculada por estación anotada en vez de sobre lo que devuelve la cola, '
            .'así que el globo sólo saldría recargando a mano — o sea, cuando ya no sirve.');

        // Y la que se movió es la tercera cifra, no el reloj: con precisión de segundo
        // los dos sellos son el mismo. Si esta línea se cae, la huella volvió a tener
        // dos cifras y la de arriba pasa por casualidad.
        $this->assertSame(0, $antes[2]['notas']);
        $this->assertSame(1, $despues[2]['notas']);
    }

    /**
     * **Las dos cifras de la huella hacen falta, y ésta es la que se olvida.**
     *
     * Cerrar un paso no borra ninguna fila, así que el `MAX(updated_at)` puede
     * quedarse donde estaba; lo que sí cambia es **cuántos hay**. Es la regla literal
     * del 34: *«`updated_at` no ve un borrado y el conteo sí»*.
     */
    public function test_el_conteo_ve_que_alguien_salio_de_la_cola(): void
    {
        [$ana] = $this->dosAlumnos();
        $this->unRecorridoDeTres();
        $this->cerrar($ana, 1);

        $this->assertSame(1, $this->huella()[2]['n']);

        $this->cerrar($ana, 2);

        $this->assertSame(0, $this->huella()[2]['n'],
            'Ana ya cerró la 2 y sigue contada en su cola.');
        $this->assertSame(1, $this->huella()[3]['n']);
    }

    /** La huella es pequeña: es su única razón de existir. */
    public function test_la_huella_cabe_en_un_pellizco(): void
    {
        $this->unRecorridoDeTres();

        $r = $this->withToken($this->tokenLlano())->getJson(self::RUTA.'/huella')
            ->assertStatus(200);

        $this->assertLessThan(1024, strlen($r->getContent()),
            'La huella se pide cada veinte segundos durante ocho horas: '
            .'si no cabe en un pellizco no sirve para nada.');
    }

    /**
     * **Con las estaciones 0 y 1, la huella sigue siendo un objeto.** PHP guarda la
     * clave `"0"` como entero y `json_encode` saca 0..n-1 como lista; la app espera un
     * mapa y, con una lista, dejaba de ver llegar a nadie.
     */
    public function test_la_huella_es_un_objeto_aunque_se_numere_desde_cero(): void
    {
        $this->unosPasos([
            ['orden' => 0, 'requisito' => 'Recepción', 'bloquea' => 1],
            ['orden' => 1, 'requisito' => 'Documentos', 'bloquea' => 1],
        ]);

        $crudo = $this->withToken($this->tokenLlano())->getJson(self::RUTA.'/huella')
            ->assertStatus(200)->getContent();

        $this->assertStringStartsWith('{"por_estacion":{"0":', $crudo);
    }

    // ------------------------------------------------------------------
    // Marcar
    // ------------------------------------------------------------------

    /**
     * **El vocabulario cerrado, que es la mitad del §2 que sí se puede pagar hoy.**
     *
     * No se le impone a la columna —las tres pantallas viejas siguen escribiendo lo
     * que quieran— sino a esta ruta, que no tiene ningún llamante desplegado.
     */
    public function test_un_resultado_que_no_esta_en_la_lista_se_rechaza(): void
    {
        [$ana] = $this->dosAlumnos();
        $this->unRecorridoDeTres();

        $this->withToken($this->tokenLlano())->putJson(self::RUTA.'/1/marcar', [
            'alumno_id' => $ana,
            'resultado' => 'cumplió',
        ])->assertStatus(422);

        $this->assertSame(0, (int) DB::selectOne('SELECT COUNT(*) AS n FROM requisitos_alumno
            WHERE alumno_id=?', [$ana])->n,
            'Rechazó con 422 y escribió la fila igual.');
    }

    /** Devolver sin motivo no se puede: lo que se escribe ahí lo lee la familia. */
    public function test_devolver_sin_motivo_no_se_puede(): void
    {
        [$ana] = $this->dosAlumnos();
        $this->unRecorridoDeTres();

        $this->withToken($this->tokenLlano())->putJson(self::RUTA.'/1/marcar', [
            'alumno_id' => $ana,
            'resultado' => 'devuelto',
            'motivo' => '   ',
        ])->assertStatus(422);
    }

    /**
     * **Devolver NO cierra el paso**, y eso es lo que decide en qué fila espera.
     *
     * Si `devuelto` escribiera `cerrado_at`, la cola de la estación siguiente se la
     * llevaría y la de ésta la perdería — al revés de lo que hay que hacer.
     */
    public function test_devolver_deja_a_la_persona_en_esta_cola_y_no_en_la_siguiente(): void
    {
        [$ana] = $this->dosAlumnos();
        $this->unRecorridoDeTres();
        $this->cerrar($ana, 1);

        $this->marcar($ana, 2, 'devuelto', 'Falta la copia del documento.');

        $this->assertSame([$ana], $this->colaDe(2),
            'Devolver la sacó de la cola de la estación que la devolvió.');
        $this->assertSame([], $this->colaDe(3),
            'Devolver la metió en la cola de la estación siguiente.');

        $r = $this->withToken($this->tokenLlano())->getJson(self::RUTA.'/2/cola');
        $this->assertTrue($r->json('cola.0.devuelto_antes'));
    }

    /** El motivo llega a la ficha, que es donde lo lee la familia. */
    public function test_el_motivo_viaja_a_la_ficha(): void
    {
        [$ana] = $this->dosAlumnos();
        $this->unRecorridoDeTres();
        $this->marcar($ana, 1, 'devuelto', 'La copia no se lee.');

        $r = $this->withToken($this->tokenLlano())->getJson(self::RUTA.'/alumno/'.$ana)
            ->assertStatus(200);

        $this->assertSame('La copia no se lee.', $r->json('pasos.0.motivo'));
        $this->assertSame('Devuelto', $r->json('pasos.0.estado'));
    }

    /**
     * **La firma que devuelve `marcar` es la que enseña la ficha**, también para quien
     * no tiene ficha en `profesores` —secretaría entera—.
     *
     * Hasta el 24 sep 2026 `marcar` caía al `username` y la ficha no: el mismo paso,
     * cerrado por la misma persona, salía firmado en la pantalla de «cerrado» y **sin
     * firma** al volver a abrir a la persona. La firma con nombre y hora es lo único
     * que protege el paso desde que cierra cualquiera del personal. El autor de la
     * nota tenía el mismo hueco.
     */
    public function test_la_ficha_firma_igual_que_marcar_aunque_no_haya_ficha_de_profesor(): void
    {
        [$ana] = $this->dosAlumnos();
        $this->unRecorridoDeTres();

        $sinFicha = DB::selectOne('SELECT COUNT(*) AS n FROM profesores p
            INNER JOIN personal_access_tokens t ON t.tokenable_id=p.user_id
            WHERE p.deleted_at IS NULL AND t.token=?',
            [hash('sha256', explode('|', $this->tokenLlano(), 2)[1] ?? '')]);
        $this->assertSame(0, (int) $sinFicha->n,
            'El sujeto tiene ficha de profesor: este test no mediría el respaldo del username.');

        $firma = $this->withToken($this->tokenLlano())->putJson(self::RUTA.'/1/marcar', [
            'alumno_id' => $ana, 'resultado' => 'cumple',
        ])->assertStatus(200)->json('cerrado_por');

        $this->withToken($this->tokenLlano())->postJson(self::RUTA.'/2/nota', [
            'alumno_id' => $ana, 'texto' => 'Trae la EPS', 'pendiente' => true, 'reservada' => false,
        ])->assertStatus(200);

        $r = $this->withToken($this->tokenLlano())->getJson(self::RUTA.'/alumno/'.$ana)
            ->assertStatus(200);

        $this->assertNotNull($firma);
        $this->assertSame($firma, $r->json('pasos.0.cerrado_por'));
        $this->assertSame($firma, $r->json('pasos.1.notas_detalle.0.de'));
    }

    /**
     * **El recorrido del personal (`requisitos/recorrido`) ve la devolución como lo
     * que es**: un paso que se debe, con el motivo que se le lee a la familia.
     *
     * Hasta el 24 sep 2026 un paso devuelto salía `cumplido` —sólo `falta` contaba
     * como pendiente—, `puede_continuar` en verdadero, y el motivo no viajaba.
     */
    public function test_el_recorrido_no_da_por_cumplido_un_paso_devuelto(): void
    {
        [$ana] = $this->dosAlumnos();
        $this->unRecorridoDeTres();
        $this->marcar($ana, 1, 'devuelto', 'Falta el registro civil');

        $r = $this->withToken($this->tokenLlano())->getJson('/api/requisitos/recorrido/'.$ana)
            ->assertStatus(200);

        $this->assertFalse($r->json('pasos.0.cumplido'));
        $this->assertSame('Falta el registro civil', $r->json('pasos.0.motivo_devolucion'));
        $this->assertFalse($r->json('puede_continuar'));
        $this->assertSame(1, $r->json('devolver_a.estacion'));
    }

    /** Quien resuelve una nota queda en la auditoría CON el alumno de la nota. */
    public function test_resolver_una_nota_audita_el_alumno(): void
    {
        [$ana] = $this->dosAlumnos();
        $this->unRecorridoDeTres();

        $nota = $this->withToken($this->tokenLlano())->postJson(self::RUTA.'/2/nota', [
            'alumno_id' => $ana, 'texto' => 'Trae la EPS', 'pendiente' => true, 'reservada' => false,
        ])->assertStatus(200)->json('id');

        $this->withToken($this->tokenLlano())
            ->putJson(self::RUTA.'/nota/'.$nota.'/resuelta')->assertStatus(200);

        $linea = DB::selectOne('SELECT alumno_id FROM auditoria
            WHERE entidad="nota_estacion" AND entidad_id=? ORDER BY id DESC LIMIT 1', [$nota]);

        $this->assertNotNull($linea, 'Resolver la nota no dejó línea de auditoría.');
        $this->assertSame($ana, (int) $linea->alumno_id);
    }

    /**
     * **La fila se crea si no existe**, y sin esto la ruta contestaría que todo fue
     * bien sin escribir nada.
     *
     * `requisitos_alumno` se rellena de forma perezosa —la crea `AlumnosController`
     * al abrir la matrícula del alumno—, así que el primero que llegue a una
     * estación puede no tener fila.
     */
    public function test_marcar_a_quien_no_tiene_fila_la_crea(): void
    {
        [$ana] = $this->dosAlumnos();
        $this->unRecorridoDeTres();

        $this->assertSame(0, (int) DB::selectOne('SELECT COUNT(*) AS n FROM requisitos_alumno
            WHERE alumno_id=?', [$ana])->n);

        $this->marcar($ana, 1, 'cumple');

        $fila = DB::selectOne('SELECT estado, cerrado_at, cerrado_por FROM requisitos_alumno
            WHERE alumno_id=?', [$ana]);

        $this->assertNotNull($fila, 'No creó la fila: el UPDATE no escribió nada y contestó 200.');
        $this->assertSame('Cumple', $fila->estado);
        $this->assertNotNull($fila->cerrado_at);
        $this->assertNotNull($fila->cerrado_por);
    }

    /**
     * **La firma es de quien CIERRA, no de quien pasó por aquí el último.**
     *
     * Se escribe con `COALESCE`, así que corregir algo después no la reescribe. Es la
     * diferencia entre `cerrado_por` y `updated_by`, y es lo único que protege un
     * paso desde que cerrar lo puede hacer cualquiera del personal.
     */
    public function test_la_firma_no_la_reescribe_el_segundo_que_pasa(): void
    {
        [$ana] = $this->dosAlumnos();
        $this->unRecorridoDeTres();

        $this->marcar($ana, 1, 'cumple');
        $primero = DB::selectOne('SELECT cerrado_por, cerrado_at FROM requisitos_alumno
            WHERE alumno_id=?', [$ana]);

        $this->withToken($this->tokenDeOtro())->putJson(self::RUTA.'/1/marcar', [
            'alumno_id' => $ana,
            'resultado' => 'observado',
            'observacion' => 'Trajo la copia después.',
        ])->assertStatus(200);

        $segundo = DB::selectOne('SELECT cerrado_por, cerrado_at, updated_by FROM requisitos_alumno
            WHERE alumno_id=?', [$ana]);

        $this->assertSame((int) $primero->cerrado_por, (int) $segundo->cerrado_por,
            'El segundo que tocó la fila se quedó con la firma del cierre.');
        $this->assertSame($primero->cerrado_at, $segundo->cerrado_at);
        $this->assertNotSame((int) $primero->cerrado_por, (int) $segundo->updated_by,
            'Si `updated_by` no se movió, esta prueba no está comparando dos personas.');
    }

    /**
     * **Reabrir un paso por la RUTA VIEJA limpia la firma**, que es la línea que el
     * 46 §5 dejó pendiente.
     *
     * `cerrado_at` se escribía con `COALESCE`, o sea una sola vez. Sin esto, desmarcar
     * a alguien lo dejaba **invisible en su estación y visible en la siguiente**: la
     * cola de la 3 lo sigue teniendo por cerrado y la 2 ya no lo ve, y la familia
     * espera de pie en una fila en la que el sistema dice que no está.
     */
    public function test_reabrir_un_paso_lo_devuelve_a_su_cola(): void
    {
        [$ana] = $this->dosAlumnos();
        $this->unRecorridoDeTres();
        $this->marcar($ana, 1, 'cumple');

        $this->assertSame([$ana], $this->colaDe(2));

        $this->cerrarPorLaRutaVieja($ana, 1, 'falta');

        $this->assertNull(DB::selectOne('SELECT cerrado_at FROM requisitos_alumno
            WHERE alumno_id=?', [$ana])->cerrado_at,
            'Reabrir dejó `cerrado_at` puesta: la cola lo sigue viendo cerrado.');

        $this->assertSame([], $this->colaDe(2), 'Sigue en la cola de la 2 sin haber llegado.');
        $this->assertSame([$ana], $this->colaDe(1), 'Volvió a deber la 1 y no está en su cola.');
    }

    // ------------------------------------------------------------------
    // Enviar a
    // ------------------------------------------------------------------

    /**
     * **`enviar-a` registra el intento y NO escribe el paso.**
     *
     * Se comprueba **mirando la base**, no la respuesta: un test que mirase el 200
     * pasaría igual escribiendo la fila. Que no la escriba es lo que permite el
     * tablero del rector —*«en la 4 se presentan doce sin pasar por la 3»*— **sin
     * ensuciar el recorrido de esa familia** con un paso que no ocurrió.
     */
    public function test_enviar_a_registra_el_intento_y_no_toca_el_paso(): void
    {
        [$ana] = $this->dosAlumnos();
        $this->unRecorridoDeTres();

        $r = $this->withToken($this->tokenLlano())->putJson(self::RUTA.'/3/enviar-a/1',
            ['alumno_id' => $ana])->assertStatus(200);

        $this->assertFalse($r->json('paso_escrito'));

        $this->assertSame(0, (int) DB::selectOne('SELECT COUNT(*) AS n FROM requisitos_alumno
            WHERE alumno_id=?', [$ana])->n,
            'Escribió un paso que no ocurrió: eso ensucia el recorrido de la familia.');

        $envio = DB::selectOne('SELECT desde_orden, hacia_orden, enviado_por
            FROM envios_estacion WHERE alumno_id=?', [$ana]);

        $this->assertNotNull($envio, 'No registró el intento: el tablero no podrá contarlo.');
        $this->assertSame(3, (int) $envio->desde_orden);
        $this->assertSame(1, (int) $envio->hacia_orden);
    }

    // ------------------------------------------------------------------
    // Las notas y el globo
    // ------------------------------------------------------------------

    /**
     * **Una nota reservada CUENTA para el globo y no se lee.**
     *
     * *«Esconder que una nota existe es peor que esconder su contenido»*: quien ve el
     * globo y no puede abrirlo sabe a quién preguntarle; quien no ve nada, no
     * pregunta.
     */
    public function test_una_nota_reservada_cuenta_y_no_se_lee(): void
    {
        [$ana] = $this->dosAlumnos();
        $this->unRecorridoDeTres();

        $this->withToken($this->tokenDeOtro())->postJson(self::RUTA.'/2/nota', [
            'alumno_id' => $ana,
            'texto' => 'Lo que dijo orientación.',
            'reservada' => true,
        ])->assertStatus(200);

        $r = $this->withToken($this->tokenLlano())->getJson(self::RUTA.'/alumno/'.$ana)
            ->assertStatus(200);

        $this->assertSame(1, $r->json('pasos.1.notas.total'), 'El globo no cuenta la reservada.');
        $this->assertSame(1, $r->json('pasos.1.notas.reservadas'));
        $this->assertNull($r->json('pasos.1.notas_detalle.0.texto'),
            'El texto de una reservada salió a quien no puede leerlo.');

        // Y el texto NO está en ninguna parte del JSON, no sólo en su campo: un campo
        // de más en una respuesta no rompe nada y no se nota hasta que importa.
        $this->assertStringNotContainsString('orientación', $r->getContent());
    }

    /** Quien la escribió sí lee su propia reservada. */
    public function test_quien_escribio_la_reservada_la_lee(): void
    {
        [$ana] = $this->dosAlumnos();
        $this->unRecorridoDeTres();

        $this->withToken($this->tokenDeOtro())->postJson(self::RUTA.'/2/nota', [
            'alumno_id' => $ana,
            'texto' => 'Lo que dijo orientación.',
            'reservada' => true,
        ])->assertStatus(200);

        $r = $this->withToken($this->tokenDeOtro())->getJson(self::RUTA.'/alumno/'.$ana);

        $this->assertSame('Lo que dijo orientación.', $r->json('pasos.1.notas_detalle.0.texto'));
    }

    /**
     * **Una nota pendiente no la apaga el primero a quien le estorbe.**
     *
     * Es el escenario contra el que se escribió la regla: el que atiende la estación
     * necesita cerrar su paso y el aviso del tesorero le molesta. Contesta **403 con
     * el motivo dentro**, porque la pantalla lo pinta: eso convierte un botón muerto
     * en una instrucción.
     */
    public function test_un_tercero_no_puede_dar_por_resuelta_una_nota_ajena(): void
    {
        [$ana] = $this->dosAlumnos();
        $this->unRecorridoDeTres();

        $nota = $this->withToken($this->tokenDeOtro())->postJson(self::RUTA.'/2/nota', [
            'alumno_id' => $ana,
            'texto' => 'Saldo pendiente.',
            'pendiente' => true,
        ])->assertStatus(200)->json('id');

        $r = $this->withToken($this->tokenLlano())
            ->putJson(self::RUTA.'/nota/'.$nota.'/resuelta');

        $r->assertStatus(403);

        $this->assertNull(DB::selectOne('SELECT resuelta_at FROM notas_estacion WHERE id=?',
            [$nota])->resuelta_at,
            'Contestó 403 y la apagó igual.');
    }

    /**
     * **Quien la escribió sí puede**, y la firma queda con su nombre.
     *
     * El autor es el **personal llano**, a propósito: escrito con el superusuario de
     * `tokenDeOtro()` este test pasaría por dos motivos a la vez —ser el autor y ser
     * superusuario— y dejaría de probar el que dice su nombre.
     */
    public function test_quien_la_escribio_puede_darla_por_resuelta(): void
    {
        [$ana] = $this->dosAlumnos();
        $this->unRecorridoDeTres();

        $nota = $this->withToken($this->tokenLlano())->postJson(self::RUTA.'/2/nota', [
            'alumno_id' => $ana,
            'texto' => 'Saldo pendiente.',
            'pendiente' => true,
        ])->assertStatus(200)->json('id');

        $this->withToken($this->tokenLlano())
            ->putJson(self::RUTA.'/nota/'.$nota.'/resuelta')->assertStatus(200);

        $fila = DB::selectOne('SELECT resuelta_por, resuelta_at FROM notas_estacion WHERE id=?',
            [$nota]);

        $this->assertNotNull($fila->resuelta_at);
        $this->assertNotNull($fila->resuelta_por,
            'Sin `resuelta_por` las dos columnas de la migración nacen muertas.');
    }

    /**
     * **Un superusuario SIN el rol `Admin` también puede.** Decidido por Joseth el 20
     * sep 2026, con la medición delante.
     *
     * La regla escrita eran tres roles —`Admin`, `Secretario`, `Rector`— y medirlos
     * destapó que **`Admin` es un rol y el administrador de verdad es
     * `users.is_superuser`**: en la copia de desarrollo hay 12 superusuarios y sólo 10
     * con ese rol, así que **dos personas veían el botón apagado**.
     *
     * ## EL SEED NO PUEDE PROBAR ESTO SOLO, y por eso el caso se construye
     *
     * En la base de tests **los diez superusuarios tienen los diez el rol `Admin`**, así
     * que la rama nueva queda **tapada** por la vieja: cogiendo un superusuario del seed,
     * este test pasaría **exactamente igual sin la línea que viene a proteger**. Sería un
     * control verde sobre una población que no distingue las dos ramas — la clase de test
     * que se escribe sin darse cuenta.
     *
     * Así que se parte de un usuario llano **comprobando que no tiene ninguno de los tres
     * roles** y se le enciende `is_superuser` dentro de la transacción del test. Lo que
     * queda es exactamente la persona que Joseth decidió dejar entrar, y nada más.
     *
     * ## Y LA NOTA LA ESCRIBE OTRO, que es lo que este test tuvo mal al nacer
     *
     * Escrito primero con `tokenLlano()` como autor, **seguía verde con la rama nueva
     * quitada**: el superusuario que se construye sale de *ese mismo* usuario, así que
     * entraba por ser **el autor** y no por ser superusuario. Un control verde sobre un
     * sujeto que cumple dos condiciones a la vez.
     *
     * Lo delató apagar la línea y ver que **sólo caía uno de los dos tests nuevos**. Por
     * eso la nota la escribe `tokenDeOtro()`: el que resuelve tiene que ser ajeno a ella
     * o esto no mide lo que dice su nombre.
     */
    public function test_un_superusuario_sin_el_rol_admin_puede_resolver(): void
    {
        [$ana] = $this->dosAlumnos();
        $this->unRecorridoDeTres();

        // La escribe OTRO: si la escribiera el mismo, pasaría por la rama del autor.
        $nota = $this->withToken($this->tokenDeOtro())->postJson(self::RUTA.'/2/nota', [
            'alumno_id' => $ana,
            'texto' => 'Saldo pendiente.',
            'pendiente' => true,
        ])->assertStatus(200)->json('id');

        $super = $this->unSuperusuarioSinNingunRolDeEscape();

        $this->withToken($this->tokenDe($super))
            ->putJson(self::RUTA.'/nota/'.$nota.'/resuelta')->assertStatus(200);

        $this->assertNotNull(DB::selectOne('SELECT resuelta_at FROM notas_estacion WHERE id=?',
            [$nota])->resuelta_at);
    }

    /**
     * Y el que NO es superusuario sigue sin poder, que es la otra mitad: la decisión
     * ensancha la puerta, no la quita.
     */
    public function test_encender_el_superusuario_es_lo_que_cambia_la_respuesta(): void
    {
        [$ana] = $this->dosAlumnos();
        $this->unRecorridoDeTres();

        $nota = $this->withToken($this->tokenDeOtro())->postJson(self::RUTA.'/2/nota', [
            'alumno_id' => $ana,
            'texto' => 'Saldo pendiente.',
            'pendiente' => true,
        ])->assertStatus(200)->json('id');

        // El mismo usuario, la misma nota ajena, y lo único que cambia entre las dos
        // respuestas es la columna. Si esto no fuera así, el test de arriba estaría
        // midiendo cualquier otra cosa del sujeto que eligió.
        $llano = $this->usuarioLlanoDelPersonal()->username;

        $this->withToken($this->tokenDe($llano))
            ->putJson(self::RUTA.'/nota/'.$nota.'/resuelta')->assertStatus(403);

        DB::update('UPDATE users SET is_superuser=1 WHERE username=?', [$llano]);

        $this->withToken($this->tokenDe($llano))
            ->putJson(self::RUTA.'/nota/'.$nota.'/resuelta')->assertStatus(200);
    }

    /** Una nota sin texto no es una nota. */
    public function test_una_nota_vacia_se_rechaza(): void
    {
        [$ana] = $this->dosAlumnos();
        $this->unRecorridoDeTres();

        $this->withToken($this->tokenLlano())->postJson(self::RUTA.'/2/nota',
            ['alumno_id' => $ana, 'texto' => '   '])->assertStatus(422);
    }

    /**
     * **El globo viaja EN la cola y no en una llamada aparte.**
     *
     * Una pantalla que preguntara «¿y notas?» alumno por alumno no dibujaría la cola:
     * la dibujaría cuatro segundos después, en un patio con mala señal.
     */
    public function test_la_cola_trae_el_numero_del_globo(): void
    {
        [$ana] = $this->dosAlumnos();
        $this->unRecorridoDeTres();
        $this->cerrar($ana, 1);

        $this->withToken($this->tokenDeOtro())->postJson(self::RUTA.'/3/nota', [
            'alumno_id' => $ana, 'texto' => 'Debe algo.', 'pendiente' => true,
        ])->assertStatus(200);

        $r = $this->withToken($this->tokenLlano())->getJson(self::RUTA.'/2/cola');

        $this->assertSame(1, $r->json('cola.0.notas_total'),
            'La cola de la 2 no trae la nota que se escribió en la 3, y es la que hace el globo.');
        $this->assertSame(1, $r->json('cola.0.notas_pendientes'));
    }

    // ------------------------------------------------------------------
    // La ficha y el código
    // ------------------------------------------------------------------

    /**
     * **Sin `?estacion=` nadie está pidiendo atenderlo**, así que contestar «no
     * puede» sería contestar a una pregunta que no se hizo. Es la pantalla 11, la que
     * abre cualquiera cuando una mamá pregunta «¿mi hija en qué va?».
     */
    public function test_la_ficha_sin_estacion_no_opina_sobre_si_se_puede_atender(): void
    {
        [$ana] = $this->dosAlumnos();
        $this->unRecorridoDeTres();

        $r = $this->withToken($this->tokenLlano())->getJson(self::RUTA.'/alumno/'.$ana)
            ->assertStatus(200);

        $this->assertTrue($r->json('puede_atenderlo'));
        $this->assertNull($r->json('si_no'));
    }

    /**
     * **Con `?estacion=` dice a dónde devolverla, y al PRIMER paso que frena.**
     *
     * Ir al último sería mandarla al final de un recorrido que todavía no ha hecho.
     */
    public function test_la_ficha_desde_una_estacion_dice_a_donde_devolver(): void
    {
        [$ana] = $this->dosAlumnos();
        $this->unRecorridoDeTres();

        $r = $this->withToken($this->tokenLlano())
            ->getJson(self::RUTA.'/alumno/'.$ana.'?estacion=3')->assertStatus(200);

        $this->assertFalse($r->json('puede_atenderlo'));
        $this->assertSame(1, $r->json('si_no.devolver_a_nro'),
            'Devuelve a otra estación que no es la primera pendiente que frena.');
        $this->assertSame('Recepción', $r->json('si_no.donde'));
    }

    /**
     * **Un paso pendiente que NO bloquea informa y no frena.**
     *
     * Es la respuesta de Joseth —*«falta significa las dos cosas según la
     * estación»*— convertida en código. Un test que sólo mirase `puede_atenderlo`
     * pasaría igual el día que alguien hiciera bloquear a todos.
     */
    public function test_un_pendiente_que_no_bloquea_no_frena(): void
    {
        [$ana] = $this->dosAlumnos();
        $this->unosPasos([
            ['orden' => 1, 'requisito' => 'Encuesta', 'bloquea' => 0],
            ['orden' => 2, 'requisito' => 'Documentos', 'bloquea' => 1],
        ]);

        $r = $this->withToken($this->tokenLlano())
            ->getJson(self::RUTA.'/alumno/'.$ana.'?estacion=2')->assertStatus(200);

        $this->assertTrue($r->json('puede_atenderlo'),
            'Un paso opcional sin cerrar está frenando el recorrido.');
        $this->assertFalse($r->json('pasos.0.cerrada'),
            'Y aun así tiene que seguir saliendo como pendiente, informando.');
    }

    /**
     * **El 409 del papel que todavía no es de nadie.**
     *
     * Un formulario del modo `nuevos` nace sin alumno. Contestar 404 diría «ese
     * código no existe», que es falso, y mandaría a quien atiende a buscar un
     * problema que no tiene.
     */
    public function test_un_codigo_sin_alumno_contesta_409_y_no_404(): void
    {
        $year = $this->yearActual();

        DB::insert('INSERT INTO ordenes_inscripcion
            (codigo, year_id, year_campana, lote_id, modo, estado, created_at, updated_at)
            VALUES (?,?,?,?,?,?,NOW(),NOW())',
            ['2027-QQQQQ', $year, 2027, 'lote-de-prueba', 'nuevos', 'IMPRESA']);

        $this->withToken($this->tokenLlano())->getJson(self::RUTA.'/codigo/2027-QQQQQ')
            ->assertStatus(409);
    }

    public function test_un_codigo_que_no_existe_contesta_404(): void
    {
        $this->withToken($this->tokenLlano())->getJson(self::RUTA.'/codigo/2027-NADA')
            ->assertStatus(404);
    }

    /** El papel viejo tiene que seguir encontrando su orden. */
    public function test_el_codigo_anterior_sigue_encontrando_la_orden(): void
    {
        [$ana] = $this->dosAlumnos();
        $year = $this->yearActual();

        DB::insert('INSERT INTO ordenes_inscripcion
            (codigo, codigo_anterior, year_id, year_campana, lote_id, modo, alumno_id, estado, created_at, updated_at)
            VALUES (?,?,?,?,?,?,?,?,NOW(),NOW())',
            ['2027-NUEVO', '2027-VIEJO', $year, 2027, 'lote', 'antiguos', $ana, 'IMPRESA']);

        $r = $this->withToken($this->tokenLlano())->getJson(self::RUTA.'/codigo/2027-VIEJO')
            ->assertStatus(200);

        $this->assertSame($ana, $r->json('persona.id'));
    }

    // ------------------------------------------------------------------
    // El guard
    // ------------------------------------------------------------------

    /**
     * **Las nueve exigen token, y ninguna es pública ni puede serlo.**
     *
     * Quien atiende una estación tiene cuenta —es personal del colegio—, a diferencia
     * de la familia del aspirante, que es lo que obligó a abrir las tres del
     * formulario de inscripción.
     */
    public function test_ninguna_de_las_nueve_contesta_sin_token(): void
    {
        $llamadas = [
            ['getJson', self::RUTA],
            ['getJson', self::RUTA.'/huella'],
            ['getJson', self::RUTA.'/alumno/1'],
            ['getJson', self::RUTA.'/codigo/2027-XXXXX'],
            ['getJson', self::RUTA.'/1/cola'],
            ['putJson', self::RUTA.'/nota/1/resuelta'],
            ['putJson', self::RUTA.'/1/marcar'],
            ['putJson', self::RUTA.'/1/enviar-a/2'],
            ['postJson', self::RUTA.'/1/nota'],
        ];

        $this->assertCount(9, $llamadas, 'Son nueve rutas: el contrato decía ocho y la '
            .'novena es la que escribe `resuelta_por`. Si cambia el número, cambia esta lista.');

        $abiertas = [];

        foreach ($llamadas as [$verbo, $uri]) {
            if ($this->$verbo($uri)->getStatusCode() !== 401) {
                $abiertas[] = $verbo.' '.$uri;
            }
        }

        // Se juntan y se afirma una vez: con un `assertStatus` por vuelta, la
        // primera que fallara escondería a las otras ocho.
        $this->assertSame([], $abiertas, 'Estas rutas contestan sin token.');
    }

    /** Un alumno no atiende estaciones. */
    public function test_un_alumno_no_entra(): void
    {
        $this->withToken($this->tokenDeUnAlumno())->getJson(self::RUTA)->assertStatus(403);
    }

    // ------------------------------------------------------------------
    // Andamio
    // ------------------------------------------------------------------

    /** @return array{0:int,1:int} */
    private function dosAlumnos(): array
    {
        // El año NO está en `matriculas`: viaja por `grupos.year_id`. Es la misma
        // forma que usa el controlador y que `putListadoObservaciones`.
        $alumnos = DB::select('SELECT a.id FROM alumnos a
            INNER JOIN matriculas m ON m.alumno_id=a.id AND m.deleted_at IS NULL
                AND m.estado IN ("MATR","ASIS","PREM","PREA")
            INNER JOIN grupos g ON g.id=m.grupo_id AND g.deleted_at IS NULL AND g.year_id=?
            WHERE a.deleted_at IS NULL
            GROUP BY a.id ORDER BY a.id LIMIT 2', [$this->yearActual()]);

        $this->assertCount(2, $alumnos,
            'El seed no tiene dos alumnos matriculados en el año actual: esto no mediría nada.');

        return [(int) $alumnos[0]->id, (int) $alumnos[1]->id];
    }

    /**
     * Monta el recorrido **por la ruta** y no con un `INSERT`: así el escenario prueba
     * de paso que `postStore` guarda `orden` y `bloquea`.
     *
     * @param  list<array{orden:int,requisito:string,bloquea:int}>  $pasos
     */
    private function unosPasos(array $pasos): void
    {
        foreach ($pasos as $paso) {
            $this->withToken($this->tokenLlano())->postJson('/api/requisitos/store', [
                'year_id' => $this->yearActual(),
                'requisito' => $paso['requisito'],
                'descripcion' => '',
                'orden' => $paso['orden'],
                'bloquea' => (bool) $paso['bloquea'],
            ])->assertStatus(200);
        }
    }

    private function unRecorridoDeTres(): void
    {
        $this->unosPasos([
            ['orden' => 1, 'requisito' => 'Recepción', 'bloquea' => 1],
            ['orden' => 2, 'requisito' => 'Documentos', 'bloquea' => 1],
            ['orden' => 3, 'requisito' => 'Tesorería', 'bloquea' => 1],
        ]);
    }

    /** Cierra una estación entera como la cierra la app. */
    private function cerrar(int $alumnoId, int $nro): void
    {
        $this->marcar($alumnoId, $nro, 'cumple');
    }

    /** @param  list<int>  $requisitos */
    private function marcar(int $alumnoId, int $nro, string $resultado,
        ?string $motivo = null, array $requisitos = []): void
    {
        $cuerpo = ['alumno_id' => $alumnoId, 'resultado' => $resultado];

        if ($motivo !== null) {
            $cuerpo['motivo'] = $motivo;
        }

        if (count($requisitos) > 0) {
            $cuerpo['requisitos'] = $requisitos;
        }

        $this->withToken($this->tokenLlano())
            ->putJson(self::RUTA.'/'.$nro.'/marcar', $cuerpo)->assertStatus(200);
    }

    /**
     * **Un paso cerrado con un `estado` que no está en ningún vocabulario**, escrito
     * directamente en la fila.
     *
     * **Hasta el 20 sep 2026 esto pasaba por `requisitos/alumno`, y ya no puede**:
     * desde `App\Support\EstadosDelPaso` esa ruta rechaza con 422 lo que no esté en
     * la lista de seis. *Y eso no invalida lo que este test protege: lo refuerza.*
     *
     * El invariante es sobre **el dato en reposo, no sobre lo que la ruta acepta**.
     * `requisitos_alumno` lleva cinco años recibiendo lo que mandara cada pantalla, así
     * que en los dieciséis colegios **hay filas con estados que nadie reconoce** — y la
     * cola tiene que seguir siendo inmune a ellas aunque desde hoy no puedan entrar más.
     *
     * Escribirlo con un `UPDATE` es lo honesto: es exactamente como llegó ahí.
     */
    private function unEstadoHeredado(int $alumnoId, int $nro, string $estado): void
    {
        $requisito = DB::selectOne('SELECT id FROM requisitos_matricula
            WHERE year_id=? AND orden=? AND deleted_at IS NULL ORDER BY id LIMIT 1',
            [$this->yearActual(), $nro]);

        $this->assertNotNull($requisito, "No existe la estación {$nro}.");

        DB::insert('INSERT INTO requisitos_alumno
            (alumno_id, requisito_id, estado, updated_by, cerrado_por, cerrado_at, created_at, updated_at)
            VALUES (?,?,?,?,?,NOW(),NOW(),NOW())',
            [$alumnoId, $requisito->id, $estado, $this->unUsuarioCualquiera(), $this->unUsuarioCualquiera()]);
    }

    /** Cualquier cuenta viva, para que la fila tenga firma como la tendría de verdad. */
    private function unUsuarioCualquiera(): int
    {
        $fila = DB::selectOne('SELECT id FROM users WHERE deleted_at IS NULL ORDER BY id LIMIT 1');

        $this->assertNotNull($fila, 'El seed no tiene usuarios.');

        return (int) $fila->id;
    }

    /**
     * Cierra un paso **como lo cierra una pantalla vieja**: por `requisitos/alumno`,
     * con uno de los estados que esas pantallas mandan de verdad.
     */
    private function cerrarPorLaRutaVieja(int $alumnoId, int $nro, string $estado): void
    {
        $requisito = DB::selectOne('SELECT id FROM requisitos_matricula
            WHERE year_id=? AND orden=? AND deleted_at IS NULL ORDER BY id LIMIT 1',
            [$this->yearActual(), $nro]);

        $this->assertNotNull($requisito, "No existe la estación {$nro}.");

        $marca = DB::selectOne('SELECT id FROM requisitos_alumno
            WHERE alumno_id=? AND requisito_id=? LIMIT 1', [$alumnoId, $requisito->id]);

        if (! $marca) {
            DB::insert('INSERT INTO requisitos_alumno (alumno_id, requisito_id, estado, created_at, updated_at)
                VALUES (?,?,"falta",NOW(),NOW())', [$alumnoId, $requisito->id]);

            $marca = (object) ['id' => (int) DB::getPdo()->lastInsertId()];
        }

        $this->withToken($this->tokenLlano())->postJson('/api/requisitos/alumno',
            ['requisito_alumno_id' => $marca->id, 'estado' => $estado])->assertStatus(200);
    }

    /** @return list<int> los alumnos de la cola de esa estación, ordenados */
    private function colaDe(int $nro): array
    {
        $r = $this->withToken($this->tokenLlano())->getJson(self::RUTA.'/'.$nro.'/cola')
            ->assertStatus(200);

        $ids = array_map('intval', array_column($r->json('cola'), 'alumno_id'));
        sort($ids);

        return $ids;
    }

    /** @return array<int, array{n:int, notas:int, ultimo_cambio:string|null}> */
    private function huella(): array
    {
        return $this->withToken($this->tokenLlano())->getJson(self::RUTA.'/huella')
            ->assertStatus(200)->json('por_estacion');
    }

    private function requisitoLlamado(string $nombre): int
    {
        $fila = DB::selectOne('SELECT id FROM requisitos_matricula
            WHERE requisito=? AND deleted_at IS NULL ORDER BY id DESC LIMIT 1', [$nombre]);

        $this->assertNotNull($fila, "No existe el requisito «{$nombre}».");

        return (int) $fila->id;
    }

    private function yearActual(): int
    {
        return (int) DB::selectOne('SELECT id FROM years WHERE actual=1 AND deleted_at IS NULL')->id;
    }

    private function tokenLlano(): string
    {
        return $this->token ??= $this->tokenDelPersonalLlano();
    }

    /** Alguien del personal que NO es el de `tokenLlano`, para separar las dos firmas. */
    private function tokenDeOtro(): string
    {
        if ($this->otro !== null) {
            return $this->otro;
        }

        $otro = DB::selectOne('SELECT username FROM users
            WHERE is_superuser=1 AND deleted_at IS NULL AND is_active=1 LIMIT 1');

        $this->assertNotNull($otro, 'El seed no tiene superusuarios.');

        return $this->otro = $this->tokenDe($otro->username);
    }

    /**
     * Por el ayudante del repo y no por un `SELECT` propio: `usuarioDeTipo` elige uno
     * **cuyo contexto se puede construir**. Cogiendo el primero de `users` salía uno
     * al que `ContextoDeUsuario` no le arma la sesión, y la ruta contestaba **400**
     * en vez del 403 del guard — o sea que el test habría medido otra cosa y encima
     * habría pasado si lo hubiera escrito esperando 400.
     */
    /**
     * Un superusuario **sin `Admin`, `Secretario` ni `Rector`**, construido aquí porque
     * el seed no lo tiene: sus diez superusuarios llevan los diez el rol `Admin`.
     *
     * Se comprueba el punto de partida antes de tocar nada — si el llano que devuelve el
     * ayudante tuviera alguno de los tres, este test pasaría por el motivo viejo y la
     * línea nueva seguiría sin estar protegida.
     */
    private function unSuperusuarioSinNingunRolDeEscape(): string
    {
        $username = $this->usuarioLlanoDelPersonal()->username;

        $atajos = DB::selectOne('SELECT u.is_superuser,
                (SELECT COUNT(*) FROM role_user ru
                   INNER JOIN roles r ON r.id=ru.role_id
                  WHERE ru.user_id=u.id AND r.name IN ("Admin","Secretario","Rector")) AS roles
            FROM users u WHERE u.username=?', [$username]);

        $this->assertSame(0, (int) $atajos->is_superuser,
            'El sujeto ya era superusuario: este test no distinguiría las dos ramas.');
        $this->assertSame(0, (int) $atajos->roles,
            'El sujeto ya tiene uno de los tres roles de escape, así que pasaría por el '
            .'motivo viejo y la rama nueva seguiría sin protegerse.');

        DB::update('UPDATE users SET is_superuser=1 WHERE username=?', [$username]);

        return $username;
    }

    private function tokenDeUnAlumno(): string
    {
        return $this->tokenDe($this->usuarioDeTipo('Alumno')->username);
    }
}
