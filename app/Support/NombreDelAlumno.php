<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * El nombre de un alumno, para congelarlo dentro de una línea de auditoría.
 *
 * ## Por qué existe, que no es «para no repetir la consulta»
 *
 * `auditoria` guarda `alumno_nombre` **copiado dentro de la fila** y no una clave
 * foránea, a propósito (18 §2.4): la línea se tiene que poder leer dentro de tres
 * años aunque la nota, la matrícula y hasta el alumno se hayan borrado. Pero eso
 * sólo funciona si alguien pone el nombre, y el `alumno_id` suelto **no** lo pone.
 *
 * Y hay un criterio que lo convierte en obligatorio y no en adorno: una línea de
 * auditoría cuya descripción no se pueda leer **no cuenta como cableada**. Es lo
 * que le pasa hoy a `bitacoras`, medido contra el cuerpo crudo por el front el 25
 * ago 2026: `GET /api/bitacoras` manda `descripcion: null` en las 22 filas.
 *
 * > **La causa, comprobada, es que nadie la escribe — no que no se lea.**
 * > `BitacorasController::getIndex` hace `SELECT *`, así que la columna viaja; lo
 * > que pasa es que de los **diez** escritores de bitácora **sólo dos** la
 * > escriben (`Services\Login` y `Services\Sesion`), y los dos son sucesos de
 * > sesión. Un usuario normal lista sus propias filas —`getIndex` filtra por
 * > `created_by`— y las suyas salen todas de los otros ocho. De ahí las 22 en
 * > blanco. Son dos causas posibles con el mismo síntoma y ésta es la primera.
 *
 * `Auditoria` no repite ese error: `guardar()` siempre rellena `resumen`. Pero la
 * frase de serie se construye **con lo que hay en la fila**, así que sin el nombre
 * dice «Fulano borró ausencia 4821» — el verbo, el nombre de la entidad y un id.
 * Con él dice «Fulano borró ausencia 4821 de Ana Pérez», que es lo que alguien
 * puede leer sin salir a buscar a otras cinco tablas.
 *
 * ## El memo, y el único sitio donde importa
 *
 * Una consulta por línea es gratis en el caso normal —una petición escribe una
 * falta, una frase, una nota—, pero **hay dos caminos que escriben en bucle**: el
 * lector de tardanzas sube el recreo entero y `notas/lote` guarda una columna de
 * la rejilla. Ahí una consulta por fila duplicaría el coste del endpoint, que es
 * exactamente el tipo de regresión que este repositorio persigue con medición
 * delante (02: los 3.763 a 755 del boletín final).
 *
 * Por eso hay `deVarios()`: **una sola consulta para todo el lote**, antes de
 * empezar, que deja el memo lleno; dentro del bucle `de()` ya no consulta nada.
 *
 * El memo vive lo que vive la petición, que es lo que hace que sea seguro: un
 * nombre no cambia a mitad de una, y aunque cambiara, lo que la línea tiene que
 * guardar es **el nombre que tenía cuando ocurrió** — que es el que ya está en el
 * memo. En la suite de tests el proceso es uno solo y el memo dura toda la
 * corrida; se puede vaciar con `olvidar()` en el caso que renombre a alguien y
 * quiera comprobar el nombre nuevo.
 */
final class NombreDelAlumno
{
    /** @var array<int, string|null> */
    private static array $memo = [];

    /** El nombre completo, o `null` si el alumno no existe. */
    public static function de(?int $alumnoId): ?string
    {
        if ($alumnoId === null) {
            return null;
        }

        if (! array_key_exists($alumnoId, self::$memo)) {
            self::deVarios([$alumnoId]);
        }

        return self::$memo[$alumnoId] ?? null;
    }

    /**
     * Llena el memo para todo un lote con **una sola consulta**.
     *
     * Se llama antes de un bucle que escriba varias líneas. Los que ya están en el
     * memo no se vuelven a pedir, y si no queda ninguno por pedir no se consulta
     * nada — así llamarlo de más no cuesta.
     *
     * @param  array<int, int|string|null>  $ids
     */
    public static function deVarios(array $ids): void
    {
        $pendientes = [];

        foreach ($ids as $id) {
            if ($id !== null && is_numeric($id) && ! array_key_exists((int) $id, self::$memo)) {
                $pendientes[(int) $id] = true;
            }
        }

        if ($pendientes === []) {
            return;
        }

        $claves = array_keys($pendientes);

        // Los ids ya están pasados por `is_numeric` y convertidos a `int`, así que
        // interpolarlos no abre nada; con marcas de parámetro habría que construir
        // la lista igual y `DB::select` no acepta un array como un solo valor.
        $filas = DB::select(
            'SELECT id, nombres, apellidos FROM alumnos WHERE id IN ('.implode(',', $claves).')'
        );

        // Primero los que faltaban a null, y después los encontrados. Así un id que
        // no existe queda **memorizado como inexistente** y no se vuelve a pedir en
        // cada vuelta del bucle.
        foreach ($claves as $id) {
            self::$memo[$id] = null;
        }

        foreach ($filas as $fila) {
            $nombre = trim(($fila->nombres ?? '').' '.($fila->apellidos ?? ''));

            self::$memo[(int) $fila->id] = $nombre === '' ? null : $nombre;
        }
    }

    /** Vacía el memo. Para los tests que renombran a alguien y quieren el nombre nuevo. */
    public static function olvidar(): void
    {
        self::$memo = [];
    }
}
