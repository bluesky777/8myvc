<?php

namespace App\Support;

/**
 * Lo que el colegio puso en la plantilla no lo cambia un docente.
 *
 * **P6 del modelo de evaluación**, decidida por Joseth el 19 sep 2026. Una unidad
 * o una subunidad con `por_defecto = 1` es **una copia de la plantilla del
 * colegio** —`unidades_por_defecto` / `subunidades_por_defecto`—, sembrada por
 * `UnidadesController` la primera vez que alguien abre la pantalla de la
 * asignatura. Copiar por dentro y comportarse como referencia por fuera es la
 * decisión escrita (P6.bis, 17 sep): compartir la fila se descartó porque una
 * unidad **es un peso**, entra en cada definitiva, y corregir el 70 % en octubre
 * recalcularía boletines del periodo 1 que ya fueron a casa.
 *
 * Lo que faltaba era la otra mitad: **si es copia y nadie la protege, el docente
 * la edita y la plantilla no vale nada**. Hasta hoy `PUT unidades/update/{id}`
 * llevaba sólo `auth.personal`, así que cualquier docente podía renombrar y
 * recambiar el porcentaje de una fila del colegio. Ese agujero no era una
 * regresión: llevaba abierto desde siempre.
 *
 * ## Lo que NO bloquea, y es la mitad que hace esto usable
 *
 * **Añadir subunidades dentro de una unidad del colegio sigue permitido** — es el
 * trabajo del docente (D14) y es exactamente lo que el colegio espera de él.
 * Aquí sólo se frenan **el nombre y el porcentaje de una fila que es del
 * colegio**. Lo que el docente creó (`por_defecto = 0`) no lo mira este candado.
 *
 * ## Se compara el VALOR, no la presencia del campo
 *
 * Un cliente que reenvía el formulario entero manda `definicion` y `porcentaje`
 * aunque el usuario no los haya tocado. Si el candado mirase *«¿vino el campo?»*,
 * guardar un cambio legítimo —el orden, una subunidad nueva— contestaría 403 y la
 * pantalla parecería averiada. Mirando *«¿cambia el valor?»* el candado sólo salta
 * cuando de verdad se intenta cambiar algo, y reenviar lo mismo es lo que siempre
 * fue: no hacer nada.
 *
 * Esto **no es laxitud**: un cliente que quiera cambiar el valor tiene que
 * mandarlo distinto, y entonces salta. Lo que se evita es el 403 a quien no
 * cambió nada.
 *
 * ## Por qué 403 y no 422
 *
 * El cuerpo es correcto y los valores son válidos: lo que falta es **permiso**.
 * 422 haría que el front enseñara «revisa los campos», que es mentira — no hay
 * nada que revisar, hay que pedirle a la coordinación que lo cambie en la
 * plantilla.
 */
class CandadoDeLaPlantilla
{
    /**
     * Los dos campos que el colegio se reserva. El resto de la fila no es suyo.
     */
    public const CAMPOS = ['definicion', 'porcentaje'];

    /**
     * Frena el cambio si la fila es del colegio y quien llama no manda en ella.
     *
     * @param  object  $usuario  el contexto de `User::fromToken()`
     * @param  object  $fila  la unidad o subunidad **como está guardada**
     * @param  array<string, mixed>  $pedidos  valor pedido por campo; `null` es «no lo manda»
     * @param  string  $que  «unidad» o «subunidad», para el mensaje
     */
    public static function exigir(object $usuario, object $fila, array $pedidos, string $que): void
    {
        // El orden de las dos comprobaciones importa por lo que cuesta cada una:
        // `por_defecto` ya está en la fila que el llamante acaba de cargar, y
        // `puedeEditarPlantillaNotas` puede mirar `perms`. Primero la gratis.
        if (empty($fila->por_defecto)) {
            return;
        }

        if (Autoriza::puedeEditarPlantillaNotas($usuario)) {
            return;
        }

        foreach (self::CAMPOS as $campo) {
            if (! array_key_exists($campo, $pedidos)) {
                continue;
            }

            if (self::cambia($fila->$campo ?? null, $pedidos[$campo])) {
                abort(403, self::mensaje($que, $campo));
            }
        }
    }

    /**
     * El mismo candado para reordenar: mover una fila del colegio no es del docente.
     *
     * `unidades/update-orden` no escribe ni el nombre ni el porcentaje, así que la
     * frase de P6 —«rechazan el cambio de nombre y de porcentaje»— **no dice nada
     * de esta ruta si se lee al pie de la letra**, y sin embargo P6 la nombra. Se
     * resuelve por lo único que esa ruta puede cambiar: **el orden**. Queda
     * anotado porque es una interpretación y no una lectura.
     *
     * Y por eso **se compara el orden pedido con el guardado**: el cliente manda la
     * rejilla entera cuando el docente mueve UNA fila suya, así que rechazar por
     * «viene una fila del colegio en la lista» rompería el caso normal. Salta sólo
     * si la fila del colegio **se mueve de sitio**.
     *
     * @param  object  $fila  la unidad **como está guardada**
     * @param  mixed  $orden  el orden pedido para ella
     */
    public static function exigirOrden(object $usuario, object $fila, $orden): void
    {
        if (empty($fila->por_defecto)) {
            return;
        }

        if (Autoriza::puedeEditarPlantillaNotas($usuario)) {
            return;
        }

        if (self::cambia($fila->orden ?? null, $orden)) {
            abort(403, 'Esa unidad la puso el colegio en la plantilla: no se puede mover de sitio. Pídeselo a la coordinación.');
        }
    }

    /**
     * ¿El valor pedido es distinto del guardado?
     *
     * Comparar con `!=` a secas diría que `70` y `'70'` son iguales —bien— pero
     * también que `0` y `''` lo son, y que `'Logro'` y `0` lo son en PHP 7. Se
     * comparan **como texto y sin espacios de los bordes**, que es lo que el
     * cliente manda y lo que la base guarda, y el `TrimStrings` global ya recortó
     * lo que entró por HTTP.
     */
    private static function cambia($guardado, $pedido): bool
    {
        if ($pedido === null) {
            return false;
        }

        return trim((string) $guardado) !== trim((string) $pedido);
    }

    private static function mensaje(string $que, string $campo): string
    {
        $cual = $campo === 'porcentaje' ? 'el porcentaje' : 'el nombre';

        return "Esa {$que} la puso el colegio en la plantilla: no se puede cambiar {$cual}. "
            .'Pídeselo a la coordinación, que lo corrige en la plantilla para todos.';
    }
}
