<?php

namespace App\Support;

/**
 * **El vocabulario de `requisitos_alumno.estado`, que hasta hoy no existía.**
 *
 * El 46 §2 lo llamaba *«el agujero que hay que tapar ANTES de escribir ninguna
 * ruta»* y proponía cuatro valores: `Falta|Cumple|Observado|Devuelto`. **Esa lista
 * está mal**, y no por gusto: no contiene lo que escriben las pantallas que están
 * desplegadas en los dieciséis colegios. Rechazar lo que no estuviera en ella
 * habría roto la ficha del alumno el día del despliegue.
 *
 * ## LO QUE SE MIDIÓ, Y SE MIDIÓ EN LOS TRES ESCRITORES, NO EN UNA BASE
 *
 * Esta columna es un `varchar(255)` sin restricción, así que la pregunta *«¿qué
 * valores existen?»* no se contesta mirando un colegio —hay dieciséis y sólo se ve
 * uno—. Se contesta mirando **quién escribe**, que son tres y sólo tres, y los tres
 * tienen la lista cerrada en su código fuente:
 *
 * ```
 * myvc_front/app/scripts/alumnos/personaMatriculasDir.html   'falta' 'ya' 'n/a'   (v1, los 16)
 * myvc_front/app2/.../persona-matriculas.ts                  'falta' 'ya' 'n/a'   (ESTADOS_REQUISITO)
 * myvc_front/app2/.../prematriculas.ts                       observacion.estado ?? ''
 * ```
 *
 * Y en la copia de desarrollo, `SELECT estado, COUNT(*) FROM requisitos_alumno
 * GROUP BY estado` da **12 filas y todas `falta`** — en minúscula, mientras el
 * defecto de la columna es `'Falta'` en mayúscula. *O sea que ni siquiera el
 * defecto del esquema es un valor que alguien escriba.*
 *
 * **Contar los escritores es más fuerte que censar una base**: una base dice qué
 * pasó en un colegio, y los escritores dicen qué puede pasar en los dieciséis.
 *
 * ## EL CUARTO VALOR ES LA CADENA VACÍA, Y AHÍ HABÍA UN FALLO VIVO
 *
 * `prematriculas.ts::guardarObservacion` manda `estado: observacion.estado ?? ''`
 * al corregir una observación. Con `estado` nulo en la fila —y los hay: el `UPDATE`
 * que escribía `estado=NULL` cuando el cuerpo no lo traía estuvo vivo en los
 * dieciséis hasta el 1 sep 2026— lo que viaja es **la cadena vacía**.
 *
 * Y en `postAlumno`, `''` no es `falta` ni `devuelto`, así que **entra por la rama
 * de cerrar**:
 *
 * ```php
 * $reabre = $pedido === 'falta' || $pedido === 'devuelto';   // false para ''
 * // → cerrado_por = COALESCE(cerrado_por, quien escribió la observación)
 * // → cerrado_at  = COALESCE(cerrado_at,  ahora)
 * ```
 *
 * O sea: **corregir una tilde en una observación cierra el paso y lo firma** con el
 * nombre de quien corrigió el texto y la hora en que lo hizo. Sin error, con un
 * «Actualizado» de vuelta, y con el paso contando como cumplido en `getRecorrido`
 * —que lee *«cualquier cosa que no sea `falta`»*—.
 *
 * Es **exactamente la misma familia** que el fallo del 1 sep 2026, cometido por el
 * otro lado: aquél borraba el estado cuando no venía, éste lo cierra cuando viene
 * vacío. *Un endpoint que escribe lo que le manden acaba teniendo tantos fallos
 * como formas tenga de mandarle nada.*
 *
 * ## LO QUE ENTRA: SEIS VALORES Y UN HUECO QUE NO ES UN VALOR
 *
 * La lista es **la unión de lo medido**, no un conjunto elegido:
 *
 * ```
 * falta      las tres pantallas   nadie lo ha tocado — REABRE el paso
 * ya         las dos de ficha     cumplido, el «chulo» de toda la vida
 * n/a        las dos de ficha     no aplica a este alumno — cierra, y eso es correcto
 * cumple     la estación          cerrado
 * observado  la estación          cerrado, con un texto que viaja al siguiente
 * devuelto   la estación          no pasa, y el motivo NO puede estar vacío — REABRE
 * ```
 *
 * Y la cadena vacía **no se añade a la lista**: significa *«no toques la columna»*,
 * que es lo que de verdad quiere decir quien está editando una observación. Se
 * trata como si el campo no hubiera venido, que es la forma que ya tiene este
 * endpoint de decir «esto no lo estoy cambiando».
 *
 * ## POR QUÉ `n/a` CIERRA, QUE ES LA ÚNICA DE LAS SEIS QUE HAY QUE PENSAR
 *
 * «No aplica» no es «cumplido», y la tentación es tratarlo aparte. Pero lo que
 * decide la cola de la estación siguiente no es si el papel llegó: es **si esta
 * familia tiene algo que hacer aquí**. Un requisito que no le aplica a este alumno
 * no le puede impedir pasar a la estación 3, así que cierra — y así se comporta hoy,
 * por accidente. *Lo que cambia es que ahora está escrito y hay un test que lo
 * fija.*
 *
 * ## LA COMPARACIÓN VA EN MINÚSCULA Y ESO NO ES UNA CONCESIÓN
 *
 * Todo el código que ya lee esta columna compara con `mb_strtolower(trim(...))`
 * —`getRecorrido:103`, `pasosPorAlumno:835`, `ficha:1136`—, y por eso el desacuerdo
 * de mayúsculas que ya existe en la base **no ha roto nada todavía**. Esta clase
 * conserva ese criterio en vez de normalizar la base: migrar 'falta' a 'Falta' en
 * dieciséis colegios para cambiar una letra sería un `UPDATE` masivo cuyo único
 * efecto visible sería el que ya se consigue comparando bien.
 */
final class EstadosDelPaso
{
    /**
     * Los seis, en minúscula, que es como se comparan.
     *
     * El orden es el del relato: lo que no se ha tocado, lo que las pantallas
     * viejas escriben, y lo que escribe la estación.
     */
    public const TODOS = ['falta', 'ya', 'n/a', 'cumple', 'observado', 'devuelto'];

    /**
     * Los que **reabren** el paso, o sea los que limpian `cerrado_por`/`cerrado_at`.
     *
     * `devuelto` cuenta como reabrir por la misma razón que `falta`: un paso
     * devuelto es un paso que se sigue debiendo, y la familia tiene que volver a
     * aparecer en la cola de esa estación. Sin esto, desmarcar a alguien lo deja
     * **invisible en su estación y visible en la siguiente**.
     */
    public const REABREN = ['falta', 'devuelto'];

    /**
     * ¿Es uno de los seis?
     *
     * Devuelve `false` para la cadena vacía **a propósito**: vacío no es un estado,
     * es «no lo estoy tocando», y quien llama tiene que distinguirlos. Ver
     * `esVacio()`.
     */
    public static function valido(?string $crudo): bool
    {
        return in_array(self::normalizar($crudo), self::TODOS, true);
    }

    /**
     * ¿Viene vacío? Entonces **no es un estado**: es una petición que no habla de
     * esta columna.
     *
     * Existe como método propio y no como un `=== ''` suelto porque la diferencia
     * entre «vacío» y «desconocido» es justo la que costó el fallo de la cabecera:
     * los dos se leen igual en un `if` y significan cosas opuestas —uno hay que
     * ignorarlo y el otro hay que rechazarlo—.
     */
    public static function esVacio(?string $crudo): bool
    {
        return self::normalizar($crudo) === '';
    }

    /**
     * ¿Este estado deja el paso cerrado?
     *
     * Es la pregunta que hace la firma de quien cierra, y **no** la que hace la cola
     * de la estación: aquélla se apoya en `cerrado_at` y no en esta columna, que es
     * lo que la hace inmune al desacuerdo de mayúsculas (46 §8).
     */
    public static function cierra(?string $crudo): bool
    {
        $estado = self::normalizar($crudo);

        return $estado !== '' && ! in_array($estado, self::REABREN, true);
    }

    public static function normalizar(?string $crudo): string
    {
        return mb_strtolower(trim((string) $crudo));
    }

    /**
     * La lista para un mensaje de error, tal y como se la enseñamos a quien llama.
     */
    public static function lista(): string
    {
        return implode(', ', self::TODOS);
    }
}
