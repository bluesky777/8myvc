<?php

namespace App\Services\Act;

/**
 * EL RECORRIDO: qué preguntas ve quien responde, según lo que ya respondió.
 *
 * Contrato §2.5. **El front (web y Flutter) evalúa con este mismo algoritmo** para pintar el
 * recorrido en vivo, y el servidor vuelve a evaluar al enviar: descarta las respuestas a preguntas
 * no visibles y exige las visibles obligatorias. Si este fichero cambia, cambian los dos clientes.
 *
 *   visible(P)  = P no tiene condiciones, o ALGÚN grupo tiene TODAS sus condiciones cumplidas.
 *   cumple(c)   = la pregunta de la que depende es visible Y fue respondida Y el operador acierta.
 *                 Sin responder no se cumple — **tampoco `no_es`** (decidido por el contrato).
 *
 * Como `depende_de` siempre es anterior (lo exige el controlador), basta una pasada en `orden`.
 *
 * Las preguntas llegan como arrays con `id`, `orden`, `tipo` y `condiciones` (lista de grupos, cada
 * uno lista de `{depende_de_id, operador, opcion_id, valor}`); las respuestas, indexadas por
 * `pregunta_id`, con la forma de `RespuestaEnviada`.
 */
class Recorrido
{
    /** Los tipos que se responden eligiendo opciones. */
    public const DE_OPCIONES = ['unica', 'multiple', 'sino', 'imagen_opciones', 'video'];

    /** Los tipos de texto libre. */
    public const DE_TEXTO = ['corta', 'parrafo'];

    /** @return list<int> los ids visibles, en orden */
    public static function visibles(array $preguntas, array $respuestas): array
    {
        usort($preguntas, fn ($a, $b) => [$a['orden'], $a['id']] <=> [$b['orden'], $b['id']]);

        $tipos = [];
        $visible = [];

        foreach ($preguntas as $p) {
            $tipos[$p['id']] = $p['tipo'];
        }

        foreach ($preguntas as $p) {
            $grupos = $p['condiciones'] ?? [];

            if ($grupos === []) {
                $visible[$p['id']] = true;

                continue;
            }

            $alguno = false;

            foreach ($grupos as $grupo) {
                $todas = $grupo !== [];

                foreach ($grupo as $c) {
                    $dep = (int) $c['depende_de_id'];

                    if (! ($visible[$dep] ?? false) || ! self::cumple($c, $tipos[$dep] ?? '', $respuestas[$dep] ?? null)) {
                        $todas = false;
                        break;
                    }
                }

                if ($todas) {
                    $alguno = true;
                    break;
                }
            }

            $visible[$p['id']] = $alguno;
        }

        return array_keys(array_filter($visible));
    }

    /** Si una respuesta dice algo; una vacía cuenta como no respondida. */
    public static function respondida(string $tipo, ?array $r): bool
    {
        if ($r === null) {
            return false;
        }

        if (in_array($tipo, self::DE_OPCIONES, true)) {
            return ! empty($r['opcion_ids']) || trim((string) ($r['texto'] ?? '')) !== '';
        }

        return match ($tipo) {
            'corta', 'parrafo' => trim((string) ($r['texto'] ?? '')) !== '',
            'escala' => isset($r['valor']) && $r['valor'] !== null,
            'fecha' => ! empty($r['fecha']),
            'archivo' => ! empty($r['archivo_id']),
            default => false,
        };
    }

    private static function cumple(array $c, string $tipo, ?array $r): bool
    {
        if (! self::respondida($tipo, $r)) {
            return false;
        }

        $es = self::es($c, $tipo, $r);

        return match ($c['operador']) {
            'es' => $es,
            'no_es' => ! $es,
            'contiene' => in_array($tipo, self::DE_TEXTO, true)
                ? str_contains(self::normalizar($r['texto'] ?? ''), self::normalizar($c['valor'] ?? ''))
                : $es,
            default => false,
        };
    }

    private static function es(array $c, string $tipo, array $r): bool
    {
        if (in_array($tipo, self::DE_OPCIONES, true)) {
            return $c['opcion_id'] !== null
                && in_array((int) $c['opcion_id'], array_map('intval', $r['opcion_ids'] ?? []), true);
        }

        return match ($tipo) {
            'escala' => (int) $r['valor'] === (int) ($c['valor'] ?? 0),
            'corta', 'parrafo' => self::normalizar($r['texto'] ?? '') === self::normalizar($c['valor'] ?? ''),
            'fecha' => (string) $r['fecha'] === trim((string) ($c['valor'] ?? '')),
            default => false,
        };
    }

    /**
     * Recortar, minúsculas, sin tildes, espacios repetidos a uno (§2.4). Lo usan las condiciones y
     * la respuesta corta del cuestionario, y tiene que dar lo mismo que la versión del front.
     */
    public static function normalizar(?string $texto): string
    {
        $t = mb_strtolower(trim((string) $texto), 'UTF-8');

        // Descomponer y quitar las marcas: el equivalente de `normalize('NFD')` + quitar
        // `̀-ͯ` que usa JavaScript. Ojo, también convierte la ñ en n —igual que allá—,
        // y eso es lo que importa: que los dos lados den la misma cadena.
        if (class_exists(\Normalizer::class)) {
            $t = (string) preg_replace('/\p{Mn}+/u', '', (string) \Normalizer::normalize($t, \Normalizer::FORM_D));
        } else {
            $t = strtr($t, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
        }

        return (string) preg_replace('/\s+/u', ' ', $t);
    }
}
