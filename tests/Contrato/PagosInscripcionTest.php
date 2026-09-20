<?php

namespace Tests\Contrato;

use App\Services\Pasarela\Wompi;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\DB;

/**
 * **El pago en línea del formulario de inscripción.**
 *
 *     POST pagos-inscripcion/{codigo}/checkout   PÚBLICA
 *     POST pagos-inscripcion/webhook             PÚBLICA
 *
 * Las dos son públicas y la segunda **maneja dinero sin que haya nadie
 * identificado al otro lado**, así que aquí lo que hay que comprobar no es que
 * conteste 200: es **qué NO deja pasar**, y que lo que la protege sea un mecanismo
 * y no una costumbre.
 *
 * Las cuatro propiedades que no se ven mirando la respuesta, y que son el motivo
 * de que este fichero exista:
 *
 *   1. **La firma de integridad es la que dice la pasarela**, no la que dice
 *      nuestro propio método. Por eso el test la recalcula con la fórmula
 *      documentada y no llamando a `Wompi::firmaDeIntegridad()`: comparar una
 *      función consigo misma pasa siempre, incluso cuando la fórmula está mal.
 *   2. **El secreto de integridad no sale nunca** en ninguna respuesta.
 *   3. **Aprobado no basta: tiene que estar aprobado por lo que pedimos.** Un
 *      importe distinto no aprueba el formulario aunque la pasarela lo confirme.
 *   4. **Cuando hay llave privada, manda la reconsulta.** Un evento que diga
 *      «aprobado» sobre una transacción que la pasarela declara rechazada **no
 *      aprueba nada**, que es exactamente para lo que existe la regla del doc 40
 *      §4.
 *
 * Y una de forma, que también es de seguridad: **un evento con una referencia que
 * no es nuestra no puede hacernos salir a internet.** El cliente HTTP de ese test
 * está armado para reventar si alguien lo llama.
 */
class PagosInscripcionTest extends CasoDeContrato
{
    private const CHECKOUT = '/api/pagos-inscripcion/';

    private const WEBHOOK = '/api/pagos-inscripcion/webhook';

    private const PUBLICA = 'pub_test_UNA_LLAVE_PUBLICA';

    private const INTEGRIDAD = 'test_integrity_UN_SECRETO';

    private const EVENTOS = 'test_events_OTRO_SECRETO';

    private const PRIVADA = 'prv_test_LA_QUE_TOCA_DINERO';

    // ─────────────────────────────── el checkout ───────────────────────────────

    /**
     * **El colegio que no configuró nada no tiene esta ruta.**
     *
     * Es la regla 1 del doc 40 §5 —*el back no publica lo que está apagado*— y es
     * 404 y no 403 a propósito: no es que no le dejen pagar, es que aquí no hay
     * dónde. Un 403 le diría a la familia que existe un pago en línea al que no
     * tiene acceso.
     */
    public function test_sin_credenciales_el_checkout_no_existe(): void
    {
        $codigo = $this->unCodigoAcunado();

        $this->postJson(self::CHECKOUT.$codigo.'/checkout')->assertStatus(404);
    }

    /**
     * **`activa=1` no enciende nada por sí solo.**
     *
     * El criterio de encendido son las credenciales (doc 40 §5, regla 2). Este test
     * es el que impide que alguien «arregle» el servicio mirando sólo el
     * interruptor: con la fila puesta y activa, pero sin llave, sigue sin haber
     * pagos.
     */
    public function test_el_interruptor_activa_no_enciende_sin_llaves(): void
    {
        $this->configurar(['llave_publica' => null, 'secreto_integridad' => null]);

        $codigo = $this->unCodigoAcunado();

        $this->postJson(self::CHECKOUT.$codigo.'/checkout')->assertStatus(404);
    }

    /**
     * **Lo que no tiene precio no se cobra, y se dice cuál es el problema.**
     *
     * El colegio que todavía no ha decidido cuánto cuesta su formulario **no puede
     * cobrarlo**, y lo que recibe es un 422 que nombra lo que falta en vez de un 500
     * o de un cobro de cero pesos. El mensaje es parte del contrato: las dos
     * alternativas eran inventarse el importe en el servidor —cobrar una cifra que
     * nadie decidió— o aceptarlo del cliente, que es **que la familia elija cuánto
     * paga**.
     *
     * Este test nació cuando `valor` no lo escribía nadie y saltaba siempre; ahora
     * que hay por dónde poner el precio, sigue valiendo para el caso que de verdad
     * ocurre — el colegio que no lo ha configurado.
     */
    public function test_un_formulario_sin_precio_no_se_puede_pagar(): void
    {
        $this->configurar();

        $codigo = $this->unCodigoAcunado();

        $r = $this->postJson(self::CHECKOUT.$codigo.'/checkout');

        $r->assertStatus(422);
        $this->assertStringContainsString('precio', strtolower((string) $r->json('message')));

        $this->assertSame(0, (int) DB::selectOne('SELECT COUNT(*) c FROM pagos_inscripcion')->c,
            'Se creó una fila de pago para un formulario que no tiene importe.');
    }

    /**
     * **La firma es la que dice la pasarela**, recalculada aquí con su fórmula.
     *
     * `SHA256(referencia + centavos + moneda + secreto)`, de
     * `docs.wompi.co/docs/colombia/widget-checkout-web/`. Se comprueba contra la
     * fórmula y no contra `Wompi::firmaDeIntegridad()` a propósito: llamar a
     * nuestro propio método compararía la función consigo misma y pasaría también
     * el día que la fórmula estuviera mal.
     */
    public function test_el_checkout_devuelve_los_campos_firmados_de_la_pasarela(): void
    {
        $this->configurar();

        $this->ponerPrecio(30000);
        $codigo = $this->unCodigoAcunado();

        $r = $this->postJson(self::CHECKOUT.$codigo.'/checkout');

        $r->assertStatus(200);

        $campos = $r->json('campos');

        $this->assertSame(Wompi::CHECKOUT, $r->json('url'));
        $this->assertSame(self::PUBLICA, $campos['public-key']);
        $this->assertSame('COP', $campos['currency']);
        $this->assertSame(3000000, $campos['amount-in-cents'], 'El importe viaja en CENTAVOS.');
        $this->assertStringStartsWith($codigo.'-', $campos['reference'],
            'La referencia tiene que llevar el código dentro para poder cuadrar un extracto a mano.');

        $esperada = hash('sha256',
            $campos['reference'].$campos['amount-in-cents'].$campos['currency'].self::INTEGRIDAD);

        $this->assertSame($esperada, $campos['signature:integrity']);

        $fila = DB::selectOne('SELECT * FROM pagos_inscripcion');

        $this->assertSame('CREADO', $fila->estado);
        $this->assertSame(3000000, (int) $fila->monto_centavos);
        $this->assertNull($fila->transaccion_id, 'El id de la pasarela no existe hasta que llega el webhook.');
    }

    /**
     * **La firma ata el importe.** Control del test de arriba.
     *
     * Sin esto, aquella comprobación sólo diría que devolvemos *algún* hash. Lo que
     * hace que la firma sirva para algo es que **cambiar el importe la invalide**:
     * el checkout viaja por la URL del navegador, así que sin esa propiedad la
     * familia podría pagar un peso por un formulario de treinta mil.
     */
    public function test_la_firma_cambia_si_cambia_el_importe(): void
    {
        $this->configurar();

        $this->ponerPrecio(30000);
        $codigo = $this->unCodigoAcunado();

        $campos = $this->postJson(self::CHECKOUT.$codigo.'/checkout')->json('campos');

        $conOtroImporte = hash('sha256',
            $campos['reference'].'100'.$campos['currency'].self::INTEGRIDAD);

        $this->assertNotSame($conOtroImporte, $campos['signature:integrity'],
            'La firma no depende del importe: se podría cambiar el precio en la URL.');
    }

    /**
     * **El secreto de integridad no sale nunca.**
     *
     * Es el único secreto de esta familia que viaja cerca del navegador, y la
     * respuesta del checkout es lo que el front recibe entero. Si alguna vez sale,
     * cualquiera puede firmar cualquier importe.
     */
    public function test_el_secreto_de_integridad_no_viaja_en_la_respuesta(): void
    {
        $this->configurar();

        $this->ponerPrecio(30000);
        $codigo = $this->unCodigoAcunado();

        $crudo = $this->postJson(self::CHECKOUT.$codigo.'/checkout')->getContent();

        $this->assertStringNotContainsString(self::INTEGRIDAD, (string) $crudo);
        $this->assertStringNotContainsString(self::PRIVADA, (string) $crudo);
        $this->assertStringNotContainsString(self::EVENTOS, (string) $crudo);
    }

    public function test_un_codigo_con_erratas_no_llega_a_la_base(): void
    {
        $this->configurar();

        $this->postJson(self::CHECKOUT.'2027-AAAAAA/checkout')->assertStatus(422);

        $this->assertSame(0, (int) DB::selectOne('SELECT COUNT(*) c FROM pagos_inscripcion')->c);
    }

    /**
     * **Un formulario pagado no se vuelve a cobrar.**
     *
     * Cobrar dos veces es el error de esta familia que no se deshace con un
     * `UPDATE`: hay que devolver dinero.
     */
    public function test_un_formulario_ya_pagado_no_se_cobra_otra_vez(): void
    {
        $this->configurar();

        $this->ponerPrecio(30000);
        $codigo = $this->unCodigoAcunado();

        DB::update('UPDATE ordenes_inscripcion SET estado="PAGADA" WHERE codigo=?', [$codigo]);

        $this->postJson(self::CHECKOUT.$codigo.'/checkout')->assertStatus(422);
    }

    /**
     * **El tope de verdad es de la fila, no del reloj.**
     *
     * Igual que en la colilla: un limitador se reinicia cada hora y esto no.
     */
    public function test_una_orden_no_admite_intentos_de_pago_infinitos(): void
    {
        $this->configurar();

        $this->ponerPrecio(30000);
        $codigo = $this->unCodigoAcunado();

        for ($i = 0; $i < 10; $i++) {
            $this->postJson(self::CHECKOUT.$codigo.'/checkout')->assertStatus(200);
        }

        $this->postJson(self::CHECKOUT.$codigo.'/checkout')->assertStatus(429);
    }

    // ─────────────────────────────── el webhook ────────────────────────────────

    public function test_sin_secreto_de_eventos_el_webhook_no_existe(): void
    {
        $this->configurar(['secreto_eventos' => null]);

        $this->postJson(self::WEBHOOK, [])->assertStatus(404);
    }

    /**
     * **Una referencia que no es nuestra no nos hace salir a internet.**
     *
     * Ésta es la defensa de verdad de esta ruta, y no el limitador: la referencia se
     * busca con una consulta indexada **antes** de calcular la firma y antes de
     * preguntarle nada a la pasarela. El cliente HTTP de este test revienta si
     * alguien lo llama, así que si el orden se invierte, este test se cae.
     */
    public function test_un_evento_con_referencia_ajena_no_sale_a_internet(): void
    {
        $this->configurar(['llave_privada' => self::PRIVADA]);
        $this->pasarelaQueRevientaSiLaLlaman();

        $r = $this->postJson(self::WEBHOOK, $this->evento('NO-ES-NUESTRA', 'APPROVED', 3000000));

        $r->assertStatus(200);
        $this->assertTrue($r->json('ignorado'));
    }

    /**
     * **Una firma que no cuadra es un 401 y no toca nada.**
     */
    public function test_un_evento_mal_firmado_no_aprueba_nada(): void
    {
        $this->configurar();

        [$codigo, $referencia] = $this->unPagoAbierto();

        $cuerpo = $this->evento($referencia, 'APPROVED', 3000000);
        $cuerpo['signature']['checksum'] = str_repeat('a', 64);

        $this->postJson(self::WEBHOOK, $cuerpo)->assertStatus(401);

        $this->assertSame('CREADO', $this->pago($referencia)->estado);
        $this->assertSame('IMPRESA', $this->orden($codigo)->estado);
    }

    /**
     * El camino bueno **sin llave privada**: decide la firma, y queda escrito que
     * fue la firma quien lo decidió.
     */
    public function test_un_evento_bien_firmado_aprueba_el_pago_y_la_orden(): void
    {
        $this->configurar();

        [$codigo, $referencia] = $this->unPagoAbierto();

        $r = $this->postJson(self::WEBHOOK, $this->evento($referencia, 'APPROVED', 3000000));

        $r->assertStatus(200);
        $this->assertTrue($r->json('aprobado'));

        $pago = $this->pago($referencia);

        $this->assertSame('APROBADO', $pago->estado);
        $this->assertSame('APPROVED', $pago->estado_pasarela);
        $this->assertSame('firma', $pago->verificado_por,
            'Sin llave privada lo decide la firma, y eso tiene que quedar escrito en la fila.');
        $this->assertNotNull($pago->verificado_at);
        $this->assertNotNull($pago->respuesta, 'Sin la respuesta guardada no hay con qué cuadrar un extracto.');

        $this->assertSame('PAGADA', $this->orden($codigo)->estado);
    }

    /**
     * **Aprobado no basta: tiene que estar aprobado POR LO QUE PEDIMOS.**
     *
     * Es la comprobación que más dinero vale de este fichero. Sin ella, una
     * transacción de mil pesos aprobada de verdad —y firmada de verdad, porque la
     * firma la calcula la pasarela sobre lo que pasó— pagaría un formulario de
     * treinta mil.
     */
    public function test_un_importe_distinto_no_paga_el_formulario(): void
    {
        $this->configurar();

        [$codigo, $referencia] = $this->unPagoAbierto();

        $r = $this->postJson(self::WEBHOOK, $this->evento($referencia, 'APPROVED', 100000));

        $r->assertStatus(200);
        $this->assertTrue($r->json('descuadrado'));

        $this->assertSame('ERROR', $this->pago($referencia)->estado);
        $this->assertSame('IMPRESA', $this->orden($codigo)->estado,
            'Un pago por otro importe movió la orden.');
    }

    /**
     * **Con llave privada manda la pasarela, no el cuerpo del evento.**
     *
     * Es la regla del doc 40 §4 puesta a prueba en la única forma que la demuestra:
     * el evento —bien firmado— dice `APPROVED` y la pasarela dice `DECLINED`. Si
     * ganara el cuerpo, el formulario quedaría pagado.
     */
    public function test_cuando_hay_llave_privada_manda_lo_que_conteste_la_pasarela(): void
    {
        $this->configurar(['llave_privada' => self::PRIVADA]);

        [$codigo, $referencia] = $this->unPagoAbierto();

        $this->pasarelaQueContesta([[
            'id' => 'tx-1', 'reference' => $referencia,
            'status' => 'DECLINED', 'amount_in_cents' => 3000000, 'currency' => 'COP',
        ]]);

        $r = $this->postJson(self::WEBHOOK, $this->evento($referencia, 'APPROVED', 3000000));

        $r->assertStatus(200);
        $this->assertTrue($r->json('rechazado'));

        $pago = $this->pago($referencia);

        $this->assertSame('RECHAZADO', $pago->estado);
        $this->assertSame('reconsulta', $pago->verificado_por);
        $this->assertSame('IMPRESA', $this->orden($codigo)->estado);
    }

    /**
     * **«No sé» no es «no».**
     *
     * Si la pasarela no contesta, el pago **no** se marca rechazado: se contesta 503
     * para que vuelva. Dar por rechazado un pago porque el hosting tuvo un problema
     * de red es la forma cara de equivocarse aquí, y no deja rastro de que pasó.
     */
    public function test_si_la_pasarela_no_contesta_el_pago_queda_como_estaba(): void
    {
        $this->configurar(['llave_privada' => self::PRIVADA]);

        [$codigo, $referencia] = $this->unPagoAbierto();

        $this->pasarelaQueRevientaSiLaLlaman();

        $this->postJson(self::WEBHOOK, $this->evento($referencia, 'APPROVED', 3000000))
            ->assertStatus(503);

        $this->assertSame('CREADO', $this->pago($referencia)->estado);
        $this->assertSame('IMPRESA', $this->orden($codigo)->estado);
    }

    /**
     * **La transacción que nos mandan a mirar tiene que ser la de ESTA referencia.**
     *
     * Sin esta comprobación, un evento que apunte a una transacción aprobada ajena
     * —una de otro colegio, o una propia de otro formulario— aprobaría éste.
     */
    public function test_una_transaccion_de_otra_referencia_no_aprueba_esta(): void
    {
        $this->configurar(['llave_privada' => self::PRIVADA]);

        [$codigo, $referencia] = $this->unPagoAbierto();

        $this->pasarelaQueContesta([[
            'id' => 'tx-ajena', 'reference' => 'DE-OTRO-FORMULARIO',
            'status' => 'APPROVED', 'amount_in_cents' => 3000000, 'currency' => 'COP',
        ]]);

        $r = $this->postJson(self::WEBHOOK, $this->evento($referencia, 'APPROVED', 3000000));

        $r->assertStatus(200);
        $this->assertTrue($r->json('ignorado'));

        $this->assertSame('CREADO', $this->pago($referencia)->estado);
        $this->assertSame('IMPRESA', $this->orden($codigo)->estado);
    }

    /**
     * **`PENDING` no es un rechazo.**
     *
     * La transacción sigue viva y la pasarela volverá a avisar. Marcarla rechazada
     * aquí la dejaría muerta en nuestra base y viva en la suya — y entonces el
     * evento bueno, cuando llegue, ya no encontraría un pago que aprobar.
     */
    public function test_pendiente_no_se_escribe_como_rechazado(): void
    {
        $this->configurar();

        [$codigo, $referencia] = $this->unPagoAbierto();

        $r = $this->postJson(self::WEBHOOK, $this->evento($referencia, 'PENDING', 3000000));

        $r->assertStatus(200);
        $this->assertTrue($r->json('pendiente'));

        $this->assertSame('CREADO', $this->pago($referencia)->estado);
        $this->assertSame('PENDING', $this->pago($referencia)->estado_pasarela);
        $this->assertSame('IMPRESA', $this->orden($codigo)->estado);
    }

    /**
     * **La pasarela reenvía el mismo evento.** El segundo no vuelve a escribir.
     */
    public function test_el_mismo_evento_dos_veces_no_aprueba_dos_veces(): void
    {
        $this->configurar();

        [, $referencia] = $this->unPagoAbierto();

        $this->postJson(self::WEBHOOK, $this->evento($referencia, 'APPROVED', 3000000))
            ->assertStatus(200);

        $antes = $this->pago($referencia)->verificado_at;

        $r = $this->postJson(self::WEBHOOK, $this->evento($referencia, 'APPROVED', 3000000));

        $r->assertStatus(200);
        $this->assertTrue($r->json('ya'));
        $this->assertSame($antes, $this->pago($referencia)->verificado_at);
    }

    /**
     * **En modo firma sólo se puede creer lo que la firma cubre.**
     *
     * Wompi deja elegir qué propiedades entran en el checksum. Si `transaction.status`
     * se queda fuera, un evento **genuino** capturado y reenviado con el estado
     * cambiado seguiría validando — y el estado es justo lo que decide si esto se
     * paga. El importe no corre ese riesgo porque se compara contra el nuestro, que
     * sale de nuestra base y no del evento.
     *
     * No se contesta 401 —el evento es auténtico— ni 503 —reintentar no lo arregla—:
     * se deja el pago en ERROR con la respuesta guardada y el registro diciendo cuál
     * es el arreglo.
     */
    public function test_en_modo_firma_no_se_cree_un_estado_que_la_firma_no_cubre(): void
    {
        $this->configurar();

        [$codigo, $referencia] = $this->unPagoAbierto();

        $cuerpo = $this->evento($referencia, 'APPROVED', 3000000);

        // La misma firma, pero calculada sin el estado dentro.
        $cuerpo['signature']['properties'] = ['transaction.id', 'transaction.amount_in_cents'];
        $cuerpo['signature']['checksum'] = strtoupper(hash('sha256',
            'tx-13000000'.$cuerpo['timestamp'].self::EVENTOS));

        $r = $this->postJson(self::WEBHOOK, $cuerpo);

        $r->assertStatus(200);
        $this->assertTrue($r->json('sin_comprobar'));

        $this->assertSame('ERROR', $this->pago($referencia)->estado);
        $this->assertSame('IMPRESA', $this->orden($codigo)->estado,
            'Se pagó un formulario con un estado que nadie había firmado.');
    }

    /**
     * Y con **llave privada** el mismo evento sí se resuelve: la reconsulta no
     * necesita que la firma cubra nada, porque no se cree el evento.
     */
    public function test_con_llave_privada_da_igual_que_la_firma_no_cubra_el_estado(): void
    {
        $this->configurar(['llave_privada' => self::PRIVADA]);

        [$codigo, $referencia] = $this->unPagoAbierto();

        $this->pasarelaQueContesta([[
            'id' => 'tx-1', 'reference' => $referencia,
            'status' => 'APPROVED', 'amount_in_cents' => 3000000, 'currency' => 'COP',
        ]]);

        $cuerpo = $this->evento($referencia, 'APPROVED', 3000000);
        $cuerpo['signature']['properties'] = ['transaction.id', 'transaction.amount_in_cents'];
        $cuerpo['signature']['checksum'] = strtoupper(hash('sha256',
            'tx-13000000'.$cuerpo['timestamp'].self::EVENTOS));

        $this->postJson(self::WEBHOOK, $cuerpo)->assertStatus(200);

        $this->assertSame('APROBADO', $this->pago($referencia)->estado);
        $this->assertSame('reconsulta', $this->pago($referencia)->verificado_por);
        $this->assertSame('PAGADA', $this->orden($codigo)->estado);
    }

    /**
     * **La firma se comprueba sobre lo que LLEGÓ, no sobre lo que el framework dejó.**
     *
     * `TrimStrings` y `ConvertEmptyStringsToNull` son globales a toda esta API, así
     * que `Request::all()` no devuelve el cuerpo: devuelve el cuerpo **ya
     * modificado**. Sobre una firma eso es fatal y silencioso — el hash se
     * calcularía sobre un valor distinto del que firmó la pasarela y **fallarían
     * todos los pagos**, con el motivo a dos middlewares de distancia del sitio
     * donde se ve.
     *
     * El valor con un espacio al final es **sintético a propósito**: hoy ninguna de
     * las propiedades que Wompi firma lo tiene, así que las dos formas de leer el
     * cuerpo dan el mismo hash y este test es el único sitio donde se nota la
     * diferencia. Está para el día que firmen una que sí.
     */
    public function test_la_firma_se_comprueba_sobre_el_cuerpo_crudo(): void
    {
        $this->configurar();

        [$codigo, $referencia] = $this->unPagoAbierto();

        $cuerpo = $this->evento($referencia, 'APPROVED', 3000000);

        // Un id con un espacio al final: `TrimStrings` se lo comería, y el hash se
        // calcula CON él porque así viajó.
        $cuerpo['data']['transaction']['id'] = 'tx-1 ';
        $cuerpo['signature']['checksum'] = strtoupper(hash('sha256',
            'tx-1 APPROVED3000000'.$cuerpo['timestamp'].self::EVENTOS));

        $r = $this->postJson(self::WEBHOOK, $cuerpo);

        $r->assertStatus(200);
        $this->assertTrue($r->json('aprobado'),
            'La firma se calculó sobre el cuerpo recortado por el middleware, no sobre el que llegó.');

        $this->assertSame('PAGADA', $this->orden($codigo)->estado);
    }

    /**
     * Un evento que no es de transacción —Wompi manda más— no es un error.
     */
    public function test_un_evento_que_no_es_de_transaccion_se_ignora(): void
    {
        $this->configurar();

        $r = $this->postJson(self::WEBHOOK, ['event' => 'nequi_token.updated', 'data' => []]);

        $r->assertStatus(200);
        $this->assertTrue($r->json('ignorado'));
    }

    // ─────────────────────────────── andamiaje ─────────────────────────────────

    /**
     * @param  array<string, string|null>  $cambios
     */
    private function configurar(array $cambios = []): void
    {
        $fila = array_merge([
            'llave_publica' => self::PUBLICA,
            'secreto_integridad' => self::INTEGRIDAD,
            'secreto_eventos' => self::EVENTOS,
            'llave_privada' => null,
        ], $cambios);

        DB::insert('INSERT INTO config_pasarela
            (proveedor, ambiente, llave_publica, secreto_integridad, secreto_eventos,
             llave_privada, url_retorno, activa, created_at, updated_at)
            VALUES (?,"pruebas",?,?,?,?,?,1,NOW(),NOW())', [
            Wompi::PROVEEDOR, $fila['llave_publica'], $fila['secreto_integridad'],
            $fila['secreto_eventos'], $fila['llave_privada'], 'https://colegio.test/gracias',
        ]);
    }

    private function unCodigoAcunado(): string
    {
        $r = $this->withToken($this->tokenDelPersonalLlano())
            ->postJson('/api/informes/formularios-inscripcion', ['modo' => 'nuevos', 'cantidad' => 1]);

        $r->assertStatus(200);

        return $r->json('formularios.0.codigo');
    }

    /**
     * El precio de la campaña, **por la ruta de verdad**.
     *
     * Hasta que Joseth eligió de dónde salía el precio (19 sep 2026), esta ayudante
     * escribía `ordenes_inscripcion.valor` con un `UPDATE` a mano porque **no lo
     * escribía nadie en toda la API**. Ahora lo escribe `putCampos`, así que el test
     * va por ahí: un atajo aquí comprobaría un camino que en producción no existe.
     *
     * **Se llama ANTES de acuñar, y eso no es orden de conveniencia**: el precio se
     * estampa en la orden al acuñarla, así que ponerlo después no cambia un
     * formulario ya impreso — que es justo la propiedad que hace correcto todo esto.
     */
    private function ponerPrecio(int $pesos): void
    {
        $this->withToken($this->tokenDelPersonalLlano())
            ->putJson('/api/informes/formularios-inscripcion/campos',
                ['campos' => ['nombres'], 'valor' => $pesos])
            ->assertStatus(200);
    }

    /**
     * Un formulario de $30.000 con su checkout ya abierto.
     *
     * @return array{0: string, 1: string} el código y la referencia del pago
     */
    private function unPagoAbierto(): array
    {
        $this->ponerPrecio(30000);
        $codigo = $this->unCodigoAcunado();

        $campos = $this->postJson(self::CHECKOUT.$codigo.'/checkout')->json('campos');

        return [$codigo, $campos['reference']];
    }

    /**
     * Un evento **firmado como lo firma la pasarela**, no como lo firmamos nosotros.
     *
     * El checksum se calcula aquí con la fórmula documentada —los valores que
     * apuntan `signature.properties` dentro de `data`, el `timestamp` y el secreto—
     * por el mismo motivo que la firma de integridad: si el test llamara a nuestro
     * propio método, pasaría también el día que la fórmula estuviera mal.
     *
     * @return array<string, mixed>
     */
    private function evento(string $referencia, string $estado, int $centavos): array
    {
        $transaccion = [
            'id' => 'tx-1',
            'reference' => $referencia,
            'status' => $estado,
            'amount_in_cents' => $centavos,
            'currency' => 'COP',
        ];

        $propiedades = ['transaction.id', 'transaction.status', 'transaction.amount_in_cents'];
        $timestamp = 1758300000;

        $cadena = $transaccion['id'].$transaccion['status'].$transaccion['amount_in_cents'];

        return [
            'event' => 'transaction.updated',
            'data' => ['transaction' => $transaccion],
            'timestamp' => $timestamp,
            'signature' => [
                'properties' => $propiedades,
                'checksum' => strtoupper(hash('sha256', $cadena.$timestamp.self::EVENTOS)),
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $transacciones
     */
    private function pasarelaQueContesta(array $transacciones): void
    {
        $respuestas = array_map(
            fn ($t) => new Response(200, ['Content-Type' => 'application/json'],
                (string) json_encode(['data' => $t])),
            $transacciones
        );

        $this->app->instance(Wompi::class, new Wompi(
            new Client(['handler' => HandlerStack::create(new MockHandler($respuestas))])
        ));

        $this->olvidarControladores();
    }

    /**
     * Un cliente sin respuestas: **la primera llamada revienta**.
     *
     * Sirve para dos cosas opuestas y por eso está una sola vez: para comprobar que
     * NO se sale a internet cuando no toca, y para simular una pasarela caída.
     */
    private function pasarelaQueRevientaSiLaLlaman(): void
    {
        $this->app->instance(Wompi::class, new Wompi(
            new Client(['handler' => HandlerStack::create(new MockHandler([]))])
        ));

        $this->olvidarControladores();
    }

    private function pago(string $referencia): object
    {
        return DB::selectOne('SELECT * FROM pagos_inscripcion WHERE referencia=?', [$referencia]);
    }

    private function orden(string $codigo): object
    {
        return DB::selectOne('SELECT * FROM ordenes_inscripcion WHERE codigo=?', [$codigo]);
    }
}
