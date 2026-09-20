<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * **Cómo se encuentra el formulario que lleva un código.** Uno solo, y compartido
 * por las cuatro rutas que lo hacen.
 *
 * Existe por un agujero medido el 20 sep 2026, unas horas después de crearlo: al
 * entrar `PUT informes/formularios-inscripcion/codigo/{codigo}` —secretaría corrige
 * el código— se guardó el anterior en `codigo_anterior` **y sólo la ruta del
 * personal aprendió a buscar por él**. Las dos públicas, que son justo las que usa
 * la familia, seguían con `WHERE codigo=?`:
 *
 *     POST colillas-inscripcion/{codigo}          subir el comprobante
 *     POST pagos-inscripcion/{codigo}/checkout    pagar en línea
 *
 * O sea que **corregir un código dejaba a la familia sin poder pagar**: el papel que
 * tiene en la mano lleva el código viejo, y las dos le contestaban *«No encontramos
 * ese formulario»*.
 *
 * ## Lo que lo hacía peor es que era SILENCIOSO PARA LAS DOS PARTES
 *
 * Secretaría corrige creyendo que es inocuo —nada en la pantalla le dice que acaba
 * de invalidar un papel que está en una casa— y la familia se estrella contra un 404
 * que no explica nada y que no tiene a quién reportar, porque **no tiene cuenta**.
 * Nadie en el colegio se entera: no hay error, no hay registro, no hay llamada. Sólo
 * una inscripción que no se paga.
 *
 * ## Y por eso es una CLASE y no tres consultas arregladas
 *
 * Arreglar las dos `WHERE codigo=?` habría tapado el agujero de hoy y dejado el de
 * mañana: la siguiente ruta que reciba un código —y este módulo lleva catorce— se
 * escribiría con la consulta obvia, que es la mala. **Un sitio compartido convierte
 * «acordarse» en «no tener que acordarse».**
 *
 * Es el mismo argumento por el que `membrete()` reusa la consulta del certificado:
 * dos consultas distintas para la misma pregunta acaban contestando cosas distintas.
 */
class OrdenDeInscripcion
{
    /**
     * Las columnas que necesita quien resuelve un código. **`SELECT` explícito y no
     * `*`**: esta tabla la leen rutas públicas, y un `*` reparte a la respuesta
     * cualquier columna que gane la tabla —quién la vendió, de qué alumno es— sin
     * que nadie lo decida. Es la regla de `lo-que-reparte-una-columna`, aplicada
     * donde más duele.
     */
    private const COLUMNAS = 'id, codigo, codigo_anterior, year_id, year_campana, lote_id,
        modo, alumno_id, grupo_id, grado_id, cierra, valor, vendida_at, estado, matricula_id';

    /**
     * El formulario que lleva ese código, **buscando también por el código viejo**.
     *
     * Devuelve `null` en dos casos que **quien llama tiene que distinguir**: que el
     * código no tenga forma de código nuestro —lo dice `esValido()`, y eso es un
     * **422**, «revíselo»— y que la tenga y no exista, que es un **404**. Las dos
     * acaban en `null` aquí porque esto no decide códigos HTTP; el que llama sí.
     *
     * Lo que **no** se hace es adivinar: si el código no valida, no se consulta la
     * base. No es sólo ahorro — es lo que impide que estas rutas públicas sirvan para
     * tantear la tabla, porque el carácter de control rechaza 28 de cada 29 cadenas
     * antes de que toquen disco.
     */
    public static function porCodigo(?string $codigo): ?object
    {
        if (! CodigoDeInscripcion::esValido($codigo)) {
            return null;
        }

        $normal = CodigoDeInscripcion::normalizar($codigo);

        $orden = DB::selectOne('SELECT '.self::COLUMNAS.' FROM ordenes_inscripcion
            WHERE codigo=? AND deleted_at IS NULL', [$normal]);

        if ($orden) {
            $orden->encontrado_por = 'codigo';

            return $orden;
        }

        // El papel viejo. `ORDER BY id DESC` porque `codigo_anterior` **no es único**
        // —a propósito: exigir unicidad ahí haría fallar una corrección legítima— y
        // si dos filas lo tuvieran, la última es la que lo retiró más tarde.
        $orden = DB::selectOne('SELECT '.self::COLUMNAS.' FROM ordenes_inscripcion
            WHERE codigo_anterior=? AND deleted_at IS NULL ORDER BY id DESC', [$normal]);

        if (! $orden) {
            return null;
        }

        $orden->encontrado_por = 'codigo_anterior';

        return $orden;
    }

    /**
     * Si el código tiene forma de código nuestro. Quien llama lo usa para separar el
     * **422** del **404**, que le dicen cosas distintas a quien lo teclea: *«está mal
     * copiado»* y *«no existe»*.
     */
    public static function tieneForma(?string $codigo): bool
    {
        return CodigoDeInscripcion::esValido($codigo);
    }
}
