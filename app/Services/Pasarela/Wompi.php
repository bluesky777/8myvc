<?php

namespace App\Services\Pasarela;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * La pasarela de pagos del colegio, **sin el SDK de Wompi**.
 *
 * Todo lo que hace falta es firmar una cadena con `hash('sha256', …)` y pedir una
 * URL con Guzzle, que está en el `composer.json` desde siempre. El SDK sería una
 * dependencia más **en dieciséis copias y con `vendor/` compartido por symlink**,
 * que es donde este proyecto paga caro cada dependencia nueva — y entraría también
 * en los colegios que dijeron que no querían pagos (doc 40 §5, regla 3).
 *
 * Sigue la forma de `Services\Notificaciones\EnvioFcm`, que es el otro sitio de
 * esta API que sale a internet: cliente inyectable por el constructor, todo lo de
 * fuera envuelto en `try`, y **nada que lance**.
 *
 * ## LO QUE SE COMPROBÓ ANTES DE ESCRIBIR ESTO (19 sep 2026)
 *
 * Contra la documentación de Wompi, no de memoria:
 *
 *   - Ambientes: `https://sandbox.wompi.co/v1` y `https://production.wompi.co/v1`,
 *     y **las llaves van atadas al ambiente** (`pub_test_` / `pub_prod_`).
 *   - Checkout Web: `https://checkout.wompi.co/p/`, por GET, con `public-key`,
 *     `currency`, `amount-in-cents`, `reference` y `signature:integrity`.
 *   - La firma de integridad es
 *     `SHA256(referencia + centavos + moneda + secreto_integridad)`, y la propia
 *     documentación avisa de que **tiene que calcularse en el servidor**. Ésa es
 *     la razón de que la ruta del checkout exista: si el front pudiera firmar,
 *     tendría el secreto, y con el secreto se puede firmar cualquier importe.
 *   - `GET /v1/transactions/{id}` va con la **llave privada**, no con la pública.
 *
 * El último punto **corrige al doc 40 §4**, que decía «con la llave pública» y
 * concluía de ahí que reconsultar era gratis. No lo es: exige guardar la llave
 * privada del colegio. El reparto de cerraduras que sale de eso está en la
 * cabecera de `2026_09_19_300000_la_pasarela_del_formulario.php` y se resume en
 * dos líneas: **la firma del evento es obligatoria y la reconsulta es opcional**,
 * y cada pago guarda cuál de las dos lo admitió.
 */
class Wompi
{
    public const PROVEEDOR = 'wompi';

    /** La página a la que se manda el navegador de la familia. */
    public const CHECKOUT = 'https://checkout.wompi.co/p/';

    private const BASES = [
        'produccion' => 'https://production.wompi.co/v1',
        'pruebas' => 'https://sandbox.wompi.co/v1',
    ];

    public function __construct(private ?Client $http = null) {}

    /**
     * La configuración con la que este colegio puede **cobrar**, o `null`.
     *
     * **El criterio de encendido son las credenciales, no el interruptor** (doc 40
     * §5, regla 2). `activa` puede apagar; lo que enciende es tener con qué firmar.
     * Por eso un colegio que nunca configuró nada no necesita acordarse de apagar
     * nada: sin configurar, esto devuelve `null` y las rutas contestan 404.
     */
    public function paraCobrar(): ?object
    {
        $fila = DB::selectOne('SELECT * FROM config_pasarela
            WHERE proveedor=? AND activa=1', [self::PROVEEDOR]);

        if ($fila === null) {
            return null;
        }

        return ($this->hay($fila->llave_publica) && $this->hay($fila->secreto_integridad))
            ? $fila
            : null;
    }

    /**
     * La configuración con la que se puede **admitir un webhook**, o `null`.
     *
     * No es la misma pregunta que la de arriba y por eso no es el mismo método: para
     * cobrar hace falta firmar el checkout, y para admitir un evento hace falta
     * poder comprobar su firma. Un colegio al que le falte `secreto_eventos` puede
     * mandar familias a pagar y **no puede enterarse de que pagaron** — que es un
     * estado malo, pero es mucho mejor que admitir eventos sin comprobar nada.
     */
    public function paraRecibirEventos(): ?object
    {
        $fila = DB::selectOne('SELECT * FROM config_pasarela
            WHERE proveedor=? AND activa=1', [self::PROVEEDOR]);

        return ($fila !== null && $this->hay($fila->secreto_eventos)) ? $fila : null;
    }

    /**
     * `SHA256(referencia + centavos + moneda + secreto)`.
     *
     * Lo que esta firma impide es concreto: el checkout viaja por la URL del
     * navegador, así que **sin firma la familia podría cambiar `amount-in-cents` a
     * 100 y pagar un peso**. Con ella, cambiar el importe invalida la firma y Wompi
     * rechaza la transacción antes de cobrar nada.
     */
    public function firmaDeIntegridad(string $referencia, int $centavos, string $moneda, object $cfg): string
    {
        return hash('sha256', $referencia.$centavos.$moneda.$cfg->secreto_integridad);
    }

    /**
     * La firma que Wompi manda con cada evento, recalculada aquí.
     *
     * `SHA256(valores + timestamp + secreto_eventos)`, donde los valores son los que
     * apuntan las rutas de `signature.properties` **dentro de `data`**, en ese orden
     * y no en otro. Devuelve `null` si el cuerpo no trae lo que hace falta, que es
     * distinto de «la firma no cuadra»: lo primero es un cuerpo que no es de Wompi y
     * lo segundo es uno que dice serlo.
     *
     * **El orden lo manda el evento y no nosotros.** Es lo que permite que Wompi
     * añada propiedades a la firma sin romper esto, y también por lo que no se puede
     * «arreglar» ordenándolas: firmar en otro orden da otro hash.
     *
     * @param  array<string, mixed>  $cuerpo
     */
    public function checksumDelEvento(array $cuerpo, object $cfg): ?string
    {
        $propiedades = $cuerpo['signature']['properties'] ?? null;
        $timestamp = $cuerpo['timestamp'] ?? null;
        $datos = $cuerpo['data'] ?? null;

        if (! is_array($propiedades) || $propiedades === [] || $timestamp === null || ! is_array($datos)) {
            return null;
        }

        $cadena = '';

        foreach ($propiedades as $ruta) {
            if (! is_string($ruta)) {
                return null;
            }

            $valor = $datos;

            foreach (explode('.', $ruta) as $paso) {
                if (! is_array($valor) || ! array_key_exists($paso, $valor)) {
                    return null;
                }

                $valor = $valor[$paso];
            }

            // Un valor que no es escalar no se puede concatenar, y convertirlo a
            // la fuerza daría una firma que no cuadra nunca sin decir por qué.
            if (is_array($valor) || is_object($valor)) {
                return null;
            }

            $cadena .= (string) $valor;
        }

        return hash('sha256', $cadena.$timestamp.$cfg->secreto_eventos);
    }

    /**
     * Le pregunta a la pasarela qué pasó con una transacción. **Es la autoridad.**
     *
     * Devuelve el bloque `data` o `null` si no se pudo preguntar — y las dos cosas
     * son distintas de «la transacción no existe»: quien llama tiene que tratar el
     * `null` como *«no sé»*, nunca como *«no está aprobada»*. Dar por rechazado un
     * pago porque el hosting tuvo un problema de red es la forma cara de
     * equivocarse aquí.
     *
     * @return array<string, mixed>|null
     */
    public function consultarTransaccion(string $id, object $cfg): ?array
    {
        if (! $this->hay($cfg->llave_privada)) {
            return null;
        }

        try {
            $respuesta = $this->http()->get($this->base($cfg).'/transactions/'.rawurlencode($id), [
                'headers' => ['Authorization' => 'Bearer '.$cfg->llave_privada],
                'timeout' => 15,
            ]);

            $json = json_decode((string) $respuesta->getBody(), true);

            return isset($json['data']) && is_array($json['data']) ? $json['data'] : null;
        } catch (\Throwable $e) {
            // Se registra y se devuelve «no sé». Una excepción aquí saldría por el
            // webhook como un 500, y un 500 le dice a la pasarela que reintente —
            // que es justo lo que hay que hacer— pero sin dejar rastro de por qué.
            Log::warning('Wompi no pudo confirmar la transacción '.$id.': '.$e->getMessage());

            return null;
        }
    }

    public function base(object $cfg): string
    {
        return self::BASES[$cfg->ambiente] ?? self::BASES['pruebas'];
    }

    private function hay(?string $valor): bool
    {
        return $valor !== null && trim($valor) !== '';
    }

    private function http(): Client
    {
        return $this->http ??= new Client;
    }
}
