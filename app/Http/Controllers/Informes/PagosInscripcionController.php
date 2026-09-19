<?php

namespace App\Http\Controllers\Informes;

use App\Http\Controllers\Controller;
use App\Services\CodigoDeInscripcion;
use App\Services\Pasarela\Wompi;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Str;

/**
 * **El pago en línea del formulario de inscripción**, que es el camino alternativo
 * a la colilla.
 *
 *     POST pagos-inscripcion/{codigo}/checkout   PÚBLICA   la abre la familia
 *     POST pagos-inscripcion/webhook             PÚBLICA   la llama la pasarela
 *
 * Son las dos que faltaban de las diez que autorizó Joseth el 19 sep 2026
 * (`docs/migracion/41-el-formulario-de-inscripcion.md` §7), y las dos son públicas
 * por el mismo motivo que la colilla: **quien paga es la familia de un aspirante
 * que todavía no es alumno**, no tiene cuenta y no puede tenerla. La segunda ni
 * siquiera la llama una persona.
 *
 * ## LAS DOS SON UNA SOLA COSA, Y POR ESO NO SE PUEDE ENTREGAR MEDIA
 *
 * El checkout **no cobra**: prepara y firma lo que el navegador le va a enseñar a
 * la pasarela. Quien se entera de que el dinero llegó es el webhook. Con sólo el
 * primero, una familia paga de verdad y **en MYVC no consta nada**, que es peor
 * que no tener pagos en línea: el colegio cobró dos veces o no dejó inscribirse a
 * quien ya pagó.
 *
 * ## EL WEBHOOK NO SE CREE LO QUE LE LLEGA
 *
 * Es la regla dura del doc 40 §4 y aquí está implementada **con una corrección
 * medida el mismo día**: aquel documento daba por hecho que reconsultar la
 * transacción iba con la llave pública y que por tanto era gratis. Va con la
 * **privada**. El reparto que sale de eso —y el porqué de cada mitad— está en la
 * cabecera de `2026_09_19_300000_la_pasarela_del_formulario.php`; en corto:
 *
 *   1. **La firma del evento es obligatoria.** Sin `secreto_eventos` no se admite
 *      nada, y una firma que no cuadra es un 401. Es lo que Wompi recomienda.
 *   2. **La reconsulta manda cuando hay llave privada.** Lo que diga la pasarela
 *      gana sobre lo que diga el cuerpo, siempre.
 *   3. **Y el importe se compara.** Aprobado no basta: tiene que estar aprobado
 *      *por lo que pedimos*. Sin esta comprobación, una transacción de mil pesos
 *      aprobada de verdad pagaría un formulario de treinta mil.
 *
 * Cada pago guarda en `verificado_por` cuál de las dos lo admitió, porque una
 * comprobación opcional sin rastro es una que nadie sabe si está encendida.
 *
 * ## LO QUE NO TIENE PRECIO NO SE COBRA
 *
 * `ordenes_inscripcion.valor` existe desde la primera migración y **no lo escribe
 * nadie**: comprobado el 19 sep 2026 con un `grep` de las escrituras de esa tabla
 * en todo `app/` — el único `INSERT` no la nombra y el único `UPDATE` toca
 * `estado`. O sea que hoy todas las órdenes valen `NULL`.
 *
 * Eso no se tapa aquí. El checkout **contesta 422 y lo dice**, porque las dos
 * alternativas son peores: cobrar un importe inventado por el servidor, o dejar que
 * el importe lo mande el cliente —y entonces la familia elige cuánto paga—. Quién
 * pone ese precio y dónde se guarda es una decisión de Joseth que está escrita en
 * el doc 41 §8; **este 422 es el sitio donde se nota que falta**, y es a propósito
 * que se note.
 */
class PagosInscripcionController extends Controller
{
    /**
     * Cuántas veces se puede abrir el checkout de una misma orden.
     *
     * Como en la colilla, **el tope de verdad es éste y no el limitador**: es una
     * regla de la fila y no se reinicia con el reloj. Diez da para que una familia
     * lo intente con dos tarjetas y un PSE que se cae, y corta la fila infinita de
     * referencias que produciría un bucle.
     */
    private const MAXIMO_POR_ORDEN = 10;

    /**
     * Los estados de una orden que ya no admiten un pago nuevo.
     *
     * `IMPRESA` es la única que sí. Cobrar dos veces el mismo formulario es el
     * error que no se puede deshacer con un `UPDATE`.
     */
    private const YA_NO_SE_COBRA = ['PAGADA', 'APROBADA', 'MATRICULADA'];

    public function __construct(private Wompi $pasarela) {}

    /**
     * La familia abre el checkout. **Ruta pública.**
     *
     * Devuelve los campos **con el nombre exacto que espera la pasarela**
     * (`amount-in-cents`, `signature:integrity`) en vez de traducirlos a nombres
     * nuestros: el front sólo tiene que montar el formulario y mandarlo. Una capa de
     * traducción aquí sería un sitio más donde el contrato puede desincronizarse sin
     * que nada se ponga rojo.
     */
    public function postCheckout(string $codigo)
    {
        // Lo mismo que en la colilla y por lo mismo: si el código no cuadra consigo
        // mismo no existe, y eso se sabe sin tocar la base.
        if (! CodigoDeInscripcion::esValido($codigo)) {
            abort(422, 'Ese código no es válido. Revísalo: son el año y seis caracteres.');
        }

        $cfg = $this->pasarela->paraCobrar();

        // **El back no publica lo que está apagado** (doc 40 §5, regla 1). Un colegio
        // sin credenciales no tiene pagos en línea, así que esto no existe para él —
        // y por eso es 404 y no 403: no es que no le dejen, es que no hay.
        if ($cfg === null) {
            abort(404, 'Este colegio no recibe pagos en línea.');
        }

        $orden = DB::selectOne('SELECT id, codigo, estado, valor FROM ordenes_inscripcion
            WHERE codigo=? AND deleted_at IS NULL',
            [CodigoDeInscripcion::normalizar($codigo)]);

        if (! $orden) {
            abort(404, 'No encontramos ese formulario.');
        }

        if (in_array($orden->estado, self::YA_NO_SE_COBRA, true)) {
            abort(422, 'Este formulario ya está pagado.');
        }

        // Ver la cabecera: hoy esto salta siempre, y es el sitio donde se nota que
        // `valor` no lo escribe nadie.
        if ($orden->valor === null || (int) $orden->valor <= 0) {
            abort(422, 'Este formulario todavía no tiene precio. El colegio tiene que ponerlo antes de cobrarlo.');
        }

        $cuantos = DB::selectOne('SELECT COUNT(*) c FROM pagos_inscripcion WHERE orden_id=?',
            [$orden->id]);

        if ((int) $cuantos->c >= self::MAXIMO_POR_ORDEN) {
            abort(429, 'Este formulario ya tiene demasiados intentos de pago. Hable con el colegio.');
        }

        $centavos = (int) $orden->valor * 100;
        $moneda = 'COP';

        // La referencia lleva el código dentro para poder cuadrar un extracto a
        // mano, y una cola aleatoria porque **cada intento es una referencia nueva**:
        // la pasarela no deja reusar la de una transacción que ya existe.
        $referencia = $orden->codigo.'-'.Str::upper(Str::random(6));

        DB::insert('INSERT INTO pagos_inscripcion
            (orden_id, proveedor, referencia, monto_centavos, moneda, estado, creada_ip, created_at, updated_at)
            VALUES (?,?,?,?,?,"CREADO",?,NOW(),NOW())', [
            $orden->id, Wompi::PROVEEDOR, $referencia, $centavos, $moneda,
            substr((string) Request::ip(), 0, 45),
        ]);

        return [
            'proveedor' => Wompi::PROVEEDOR,
            'url' => Wompi::CHECKOUT,
            'campos' => array_filter([
                'public-key' => $cfg->llave_publica,
                'currency' => $moneda,
                'amount-in-cents' => $centavos,
                'reference' => $referencia,
                'signature:integrity' => $this->pasarela->firmaDeIntegridad($referencia, $centavos, $moneda, $cfg),
                'redirect-url' => $cfg->url_retorno,
            ], fn ($v) => $v !== null && $v !== ''),
        ];
    }

    /**
     * La pasarela avisa de que algo pasó. **Ruta pública, y no la llama una persona.**
     *
     * ## POR QUÉ CASI TODO ACABA EN 200
     *
     * Un código de error aquí no lo lee nadie: le dice a la pasarela **que
     * reintente**. Así que el código de respuesta es una instrucción, no un
     * diagnóstico, y se elige por lo que queremos que pase después:
     *
     *   200  lo hemos resuelto, o no es asunto nuestro y no lo será nunca
     *        (un evento que no es de transacción, o una referencia que no existe
     *        aquí: reintentarlo mil veces no va a hacer que aparezca).
     *   401  la firma no cuadra. No reintentes: no vienes de donde dices.
     *   503  no hemos podido comprobarlo **ahora** — la pasarela no contestó.
     *        Vuelve, que esto sí puede cambiar.
     *
     * La diferencia entre el 200 de «no es nuestro» y el 503 de «no lo sé» es la
     * que decide si un pago de verdad se pierde en silencio.
     */
    public function postWebhook()
    {
        $cfg = $this->pasarela->paraRecibirEventos();

        if ($cfg === null) {
            abort(404, 'Este colegio no recibe pagos en línea.');
        }

        // **El cuerpo CRUDO, no `Request::all()`.** Una firma se comprueba sobre los
        // bytes que llegaron, y lo que devuelve `Request::all()` ya ha pasado por
        // `TrimStrings` y `ConvertEmptyStringsToNull`, que son globales a toda esta
        // API. Hoy ninguna de las propiedades que Wompi firma tiene espacios ni
        // viene vacía, así que las dos formas dan el mismo hash — pero el día que
        // firmen una que sí, la comprobación fallaría **para todos los pagos** y el
        // motivo estaría a dos middlewares de distancia del sitio donde se ve.
        $cuerpo = json_decode((string) Request::getContent(), true);

        if (! is_array($cuerpo)) {
            return ['ignorado' => true];
        }

        $transaccion = $cuerpo['data']['transaction'] ?? null;

        // Wompi manda más eventos que los de transacción. Los demás no son un error
        // ni van a serlo nunca.
        if (! is_array($transaccion)) {
            return ['ignorado' => true];
        }

        $referencia = (string) ($transaccion['reference'] ?? '');

        $pago = $referencia === '' ? null : DB::selectOne(
            'SELECT * FROM pagos_inscripcion WHERE referencia=?', [$referencia]);

        // **Esto va antes que la firma a propósito**, y es lo que de verdad protege
        // a esta ruta: una referencia que no es nuestra se descarta con una consulta
        // indexada, sin calcular nada y sobre todo **sin salir a internet**. Sin este
        // orden, cualquiera podría hacernos consultar a la pasarela a su ritmo.
        if ($pago === null) {
            return ['ignorado' => true];
        }

        // La pasarela reenvía el mismo evento más de una vez. Repetir el trabajo
        // sería inofensivo, pero decirlo es más barato que volver a preguntar.
        if ($pago->estado === 'APROBADO') {
            return ['ya' => true];
        }

        if (! $this->laFirmaCuadra($cuerpo, $cfg)) {
            abort(401, 'La firma del evento no cuadra.');
        }

        // Con llave privada manda la pasarela; sin ella, manda la firma que se acaba
        // de comprobar. Ver la cabecera.
        $confirmada = $transaccion;
        $como = 'firma';

        // **En modo firma sólo se puede creer lo que la firma CUBRE.** Wompi deja
        // elegir qué propiedades entran en el checksum, y las que se queden fuera
        // viajan sin proteger: un evento genuino capturado y reenviado con el
        // `status` cambiado seguiría validando. El importe no corre ese riesgo
        // —se compara contra el nuestro, que sale de nuestra base y no del
        // evento—, pero el estado sí, y es el que decide si esto se paga.
        //
        // No es un 401: el evento es auténtico. Es que **con esa configuración no
        // podemos comprobarlo**, y eso no se arregla reintentando, así que se
        // contesta 200 y se deja dicho en el registro cuál es el arreglo — firmar
        // esas propiedades, o dar la llave privada.
        if (empty($cfg->llave_privada)) {
            $firmadas = (array) ($cuerpo['signature']['properties'] ?? []);

            if (! in_array('transaction.status', $firmadas, true)) {
                $this->guardar($pago, 'ERROR', (string) ($transaccion['status'] ?? ''), $como, $transaccion);

                Log::warning('Webhook de pagos: la firma de '.$referencia
                    .' no cubre `transaction.status`. Añádela en Wompi o configure la llave privada.');

                return ['sin_comprobar' => true];
            }
        } else {
            $id = (string) ($transaccion['id'] ?? '');

            $datos = $id === '' ? null : $this->pasarela->consultarTransaccion($id, $cfg);

            if ($datos === null) {
                // «No sé» no es «no». Se le pide a la pasarela que vuelva.
                abort(503, 'No pudimos confirmar el pago con la pasarela. Reintente.');
            }

            // La transacción que nos manda a mirar tiene que ser la de ESTA
            // referencia. Sin esta línea, un evento que apunte a una transacción
            // aprobada ajena aprobaría nuestro formulario.
            if ((string) ($datos['reference'] ?? '') !== $referencia) {
                Log::warning('Webhook de pagos: la transacción '.$id.' no es de la referencia '.$referencia);

                return ['ignorado' => true];
            }

            $confirmada = $datos;
            $como = 'reconsulta';
        }

        return $this->resolverElPago($pago, $confirmada, $como);
    }

    /**
     * @param  array<string, mixed>  $t
     */
    private function resolverElPago(object $pago, array $t, string $como)
    {
        $estado = (string) ($t['status'] ?? '');
        $centavos = (int) ($t['amount_in_cents'] ?? 0);
        $moneda = (string) ($t['currency'] ?? $pago->moneda);

        // **Aprobado no basta: tiene que estar aprobado por lo que pedimos.** Si el
        // importe o la moneda no son los que se firmaron, esto no es el pago de este
        // formulario aunque la pasarela diga que está aprobado.
        $cuadra = $centavos === (int) $pago->monto_centavos && $moneda === $pago->moneda;

        if ($estado === 'APPROVED' && ! $cuadra) {
            $this->guardar($pago, 'ERROR', $estado, $como, $t);

            Log::warning('Webhook de pagos: importe distinto en '.$pago->referencia
                .' (pedimos '.$pago->monto_centavos.' '.$pago->moneda
                .', confirman '.$centavos.' '.$moneda.')');

            return ['descuadrado' => true];
        }

        if ($estado === 'APPROVED') {
            $this->guardar($pago, 'APROBADO', $estado, $como, $t);

            // La orden avanza igual que con la colilla, y con la misma cautela: sólo
            // desde `IMPRESA`. Una que ya avanzó no vuelve atrás ni se re-escribe.
            //
            // `updated_by` **no se toca**: aquí no hay ninguna persona a la que
            // atribuirle esto, y poner el id de nadie sería inventarse un firmante.
            DB::update('UPDATE ordenes_inscripcion SET estado="PAGADA", updated_at=NOW()
                WHERE id=? AND estado="IMPRESA"', [$pago->orden_id]);

            return ['aprobado' => true];
        }

        // `PENDING` **no es un rechazo** y no se escribe como tal: la transacción
        // sigue viva y la pasarela volverá a avisar. Marcarla RECHAZADO aquí dejaría
        // el pago muerto en nuestra base y vivo en la suya.
        if (in_array($estado, ['DECLINED', 'VOIDED', 'ERROR'], true)) {
            $this->guardar($pago, 'RECHAZADO', $estado, $como, $t);

            return ['rechazado' => true];
        }

        $this->guardar($pago, $pago->estado, $estado, $como, $t);

        return ['pendiente' => true];
    }

    /**
     * @param  array<string, mixed>  $t
     */
    private function guardar(object $pago, string $nuestro, string $suyo, string $como, array $t): void
    {
        DB::update('UPDATE pagos_inscripcion
            SET estado=?, estado_pasarela=?, transaccion_id=?, verificado_por=?,
                verificado_at=NOW(), respuesta=?, updated_at=NOW()
            WHERE id=?', [
            $nuestro,
            mb_substr($suyo, 0, 30),
            mb_substr((string) ($t['id'] ?? ''), 0, 64) ?: null,
            $como,
            json_encode($t, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $pago->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $cuerpo
     */
    private function laFirmaCuadra(array $cuerpo, object $cfg): bool
    {
        $nuestra = $this->pasarela->checksumDelEvento($cuerpo, $cfg);

        if ($nuestra === null) {
            return false;
        }

        $suya = (string) ($cuerpo['signature']['checksum']
            ?? Request::header('X-Event-Checksum', ''));

        // `hash_equals` y no `===`: comparar dos hashes carácter a carácter filtra
        // por el tiempo cuántos coinciden, que es cómo se adivina uno sin saberlo.
        // Y en minúsculas los dos, porque el hexadecimal de Wompi viene en mayúsculas.
        return $suya !== '' && hash_equals(strtolower($nuestra), strtolower($suya));
    }
}
