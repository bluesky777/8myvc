<?php

namespace App\Services\Act;

/**
 * LAS PREGUNTAS INTERACTIVAS: leer, validar y calificar `config` y `estado`.
 *
 * Contrato §2.4 (tanda 7). Una pregunta `interactiva` lleva en `ws_preguntas.config`
 * `{reto, cfg, respuesta}` y cada respuesta lleva en `ws_respuestas.estado` el estado del motor.
 * Los dos son JSON que arma el front y que el backend guarda **tal cual**: sólo los mira para
 * calificar (`Retos`).
 *
 * ## Por qué se leen del cuerpo crudo
 *
 * Laravel decodifica el cuerpo con arrays asociativos, y ahí `{}` y `[]` son lo mismo (`array()`),
 * igual que `{"0": "a"}` y `["a"]`. Para el motor no lo son: el estado inicial de `cestas` es
 * `{w: {}}`, y si vuelve como `{w: []}` el borrador pierde lo que el alumno pone en las canastas
 * (las propiedades de un array no salen en `JSON.stringify`). Por eso `config` y `estado` se
 * vuelven a leer del cuerpo crudo con objetos (`stdClass`), y sólo si el cuerpo no es JSON (un
 * formulario) se usa lo que decodificó Laravel.
 */
class Interactivas
{
    public const TIPO = 'interactiva';

    /** Tope del texto de la respuesta correcta que el editor guarda junto al cfg. */
    private const TOPE_RESPUESTA = 2000;

    /**
     * El valor en `$camino` del cuerpo JSON crudo, con los objetos como `stdClass`.
     *
     * @param  list<string|int>  $camino
     * @return array{0: bool, 1: mixed} si estaba, y el valor
     */
    public static function delCuerpo(array $camino): array
    {
        $peticion = request();
        $raiz = $peticion->attributes->get('act.cuerpo_crudo');

        if ($raiz === null) {
            try {
                $raiz = ['ok' => true, 'v' => json_decode((string) $peticion->getContent(), false, 512, JSON_THROW_ON_ERROR)];
            } catch (\JsonException) {
                $raiz = ['ok' => false, 'v' => null];
            }

            $peticion->attributes->set('act.cuerpo_crudo', $raiz);
        }

        if (! $raiz['ok']) {
            return [false, null];
        }

        $v = $raiz['v'];

        foreach ($camino as $k) {
            if ($v instanceof \stdClass && property_exists($v, (string) $k)) {
                $v = $v->{(string) $k};
            } elseif (is_array($v) && is_int($k) && array_key_exists($k, $v)) {
                $v = $v[$k];
            } else {
                return [false, null];
            }
        }

        return [true, $v];
    }

    /**
     * El valor crudo de `$camino`, o, si el cuerpo no es JSON, el que leyó Laravel pasado a objetos.
     *
     * @param  list<string|int>  $camino
     */
    public static function crudo(array $camino, mixed $deLaravel): mixed
    {
        [$hay, $v] = self::delCuerpo($camino);

        if ($hay) {
            return $v;
        }

        return $deLaravel === null ? null : Retos::leer(Retos::json($deLaravel));
    }

    /**
     * `config` de una pregunta interactiva → el JSON que se guarda, o 422. Se queda sólo con
     * `reto`, `cfg` y `respuesta` (decidido por el contrato, revisable).
     */
    public static function configValida(mixed $config): string
    {
        if (! $config instanceof \stdClass) {
            abort(422, 'Una pregunta interactiva necesita su configuración: {reto, cfg}.');
        }

        $reto = $config->reto ?? null;

        if (! is_string($reto) || ! in_array($reto, Retos::CLAVES, true)) {
            abort(422, 'Ese reto interactivo no existe.');
        }

        $cfg = $config->cfg ?? null;

        if (! $cfg instanceof \stdClass) {
            abort(422, 'La configuración del reto (cfg) tiene que ser un objeto.');
        }

        $respuesta = $config->respuesta ?? null;

        if ($respuesta !== null && (! is_string($respuesta) || mb_strlen($respuesta) > self::TOPE_RESPUESTA)) {
            abort(422, 'La respuesta correcta del reto va como texto, de hasta '.self::TOPE_RESPUESTA.' caracteres.');
        }

        $json = Retos::json((object) ['reto' => $reto, 'cfg' => $cfg, 'respuesta' => $respuesta === null ? null : trim($respuesta)]);

        if (strlen($json) > Retos::TOPE_CONFIG) {
            abort(422, 'La configuración del reto pasa de '.number_format(Retos::TOPE_CONFIG, 0, ',', ' ').' bytes.');
        }

        return $json;
    }

    /** El `estado` de una respuesta → el JSON que se guarda (NULL si no hay), o 422. */
    public static function estadoValido(mixed $estado): ?string
    {
        if ($estado === null) {
            return null;
        }

        if (! $estado instanceof \stdClass) {
            abort(422, 'El estado de una pregunta interactiva va como un objeto.');
        }

        $json = Retos::json($estado);

        if (strlen($json) > Retos::TOPE_ESTADO) {
            abort(422, 'El estado de una pregunta interactiva pasa de '.number_format(Retos::TOPE_ESTADO, 0, ',', ' ').' bytes.');
        }

        return $json;
    }

    /** `config` guardado → objeto (o NULL si no se puede leer). */
    public static function config(?string $json): ?\stdClass
    {
        $c = Retos::leer($json);

        return $c instanceof \stdClass ? $c : null;
    }

    /** La de quien responde: sin `respuesta` (el `cfg` sí viaja: el motor lo necesita para pintar). */
    public static function sinRespuesta(?\stdClass $config): ?\stdClass
    {
        if ($config === null) {
            return null;
        }

        $c = clone $config;
        unset($c->respuesta);

        return $c;
    }

    /** La fracción [0, 1] de una respuesta. 0 si no hay estado o el config no se lee. */
    public static function fraccion(array $p, ?array $r): float
    {
        $config = $p['config'] ?? null;

        if (! $config instanceof \stdClass || ! is_string($config->reto ?? null) || $r === null) {
            return 0.0;
        }

        return Retos::fraccion($config->reto, $config->cfg ?? null, $r['estado'] ?? null);
    }

    /** Si el reto está terminado (el `done` del motor): es lo que cuenta como «respondida». */
    public static function terminada(array $p, ?array $r): bool
    {
        $config = $p['config'] ?? null;

        if (! $config instanceof \stdClass || ! is_string($config->reto ?? null) || $r === null) {
            return false;
        }

        return Retos::terminado($config->reto, $config->cfg ?? null, $r['estado'] ?? null);
    }

    /** Si dos config dicen el mismo reto con el mismo cfg (la `respuesta` es un texto y puede cambiar). */
    public static function mismoReto(?\stdClass $a, ?\stdClass $b): bool
    {
        $clave = fn (?\stdClass $c) => $c === null ? 'null' : Retos::json([$c->reto ?? null, $c->cfg ?? null]);

        return $clave($a) === $clave($b);
    }
}
