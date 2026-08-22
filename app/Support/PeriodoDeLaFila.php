<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * De qué periodo es la fila que esta petición va a escribir.
 *
 * Existe por la §27 de docs/migracion/05-codigo-muerto-y-roto.md. El interruptor
 * con el que el colegio cierra un periodo a los profesores
 * —`periodos.profes_pueden_editar_notas` y `profes_pueden_nivelar`— se consultaba
 * **para el periodo que nombraba el cuerpo de la petición**, y la escritura iba a
 * otro sitio. Medido de punta a punta: con los cuatro periodos cerrados y luego
 * el primero abierto, `PUT uniformes/agregar` con `num_periodo=1` respondía 200 y
 * escribía la fila con el `periodo_id` del profesor, que seguía cerrado.
 *
 * O sea que el candado se abría **nombrando el periodo de al lado**.
 *
 * `num_periodo` no sobra: en la rejilla de definitivas es la única declaración
 * que hay de qué periodo se está editando, y esa pantalla existe justamente para
 * editar los cuatro. Lo que faltaba es lo único que lo convierte en candado:
 * **que la bandera que se comprueba sea la del periodo al que se escribe.** Eso
 * se dice en una frase y se resuelve en un sitio distinto en cada llamada, y por
 * eso los sitios distintos viven aquí y no repartidos por seis controladores.
 *
 * Decisión de Joseth, 21 ago 2026, entre las dos formas que la §27.1 dejó
 * escritas: derivar de la fila, no exigir que `num_periodo` y `periodo_id`
 * concuerden — que era media hora y no cerraba las dos que más pesan.
 *
 * **Todos los métodos devuelven `?int` y `null` significa «no se pudo derivar»**,
 * no «adelante»: quien llama vuelve entonces al comportamiento de antes. Pasa en
 * dos sitios y por una razón de esquema, no por descuido —`recuperacion_final`
 * **no tiene `periodo_id`**, se guarda por año—, así que ahí no hay fila de la
 * que derivar nada. La §27.1 decía que las 26 eran derivables; son 24.
 */
class PeriodoDeLaFila
{
    /** `notas_finales.periodo_id`. Las siete de definitivas salen de aquí. */
    public static function deNotaFinal($notaFinalId): ?int
    {
        return self::uno('SELECT periodo_id FROM notas_finales WHERE id = ?', $notaFinalId);
    }

    /** `unidades.periodo_id`, que es directo. */
    public static function deUnidad($unidadId): ?int
    {
        return self::uno('SELECT periodo_id FROM unidades WHERE id = ?', $unidadId);
    }

    /**
     * La subunidad no lo lleva: cuelga de la unidad, y la unidad sí.
     *
     * No se filtra `deleted_at` a propósito, ni aquí ni en las demás: dos de las
     * llamadas son `forcedelete`, que por definición trabajan sobre filas que
     * están en la papelera. Filtrarlo dejaría justo esas dos sin derivar.
     */
    public static function deSubunidad($subunidadId): ?int
    {
        return self::uno(
            'SELECT u.periodo_id FROM subunidades s
             INNER JOIN unidades u ON u.id = s.unidad_id
             WHERE s.id = ?',
            $subunidadId
        );
    }

    /** La nota cuelga de la subunidad, que cuelga de la unidad. */
    public static function deNota($notaId): ?int
    {
        return self::uno(
            'SELECT u.periodo_id FROM notas n
             INNER JOIN subunidades s ON s.id = n.subunidad_id
             INNER JOIN unidades u ON u.id = s.unidad_id
             WHERE n.id = ?',
            $notaId
        );
    }

    /**
     * `nota_comportamiento.periodo_id`.
     *
     * Llegó tarde: este controlador no comprobaba el periodo en **ninguna** de sus
     * ocho rutas, así que no estaba entre las 26 de la §27 — no había llamada que
     * arreglar, había que ponerla. Joseth decidió el 21 ago 2026 cerrarla como las
     * demás notas: sale en el boletín y el año tiene un conmutador para
     * enseñarla. Ver 05 §40.2.
     */
    public static function deNotaComportamiento($notaId): ?int
    {
        return self::uno('SELECT periodo_id FROM nota_comportamiento WHERE id = ?', $notaId);
    }

    /** `ausencias.periodo_id`. */
    public static function deAusencia($ausenciaId): ?int
    {
        return self::uno('SELECT periodo_id FROM ausencias WHERE id = ?', $ausenciaId);
    }

    /** `uniformes.periodo_id`, que es donde empezó todo esto. */
    public static function deUniforme($uniformeId): ?int
    {
        return self::uno('SELECT periodo_id FROM uniformes WHERE id = ?', $uniformeId);
    }

    /** `frases_asignatura.periodo_id`. */
    public static function deFraseAsignatura($fraseId): ?int
    {
        return self::uno('SELECT periodo_id FROM frases_asignatura WHERE id = ?', $fraseId);
    }

    /**
     * El periodo de un número, dentro del año del usuario.
     *
     * El año hace falta porque **el número solo no identifica un periodo**: cada
     * año tiene el suyo con su propio interruptor, y el 1 de un año puede estar
     * bloqueado con el 1 del siguiente abierto (05 §27.4). Los métodos de arriba
     * no lo necesitan porque un `periodo_id` ya lleva el año dentro.
     *
     * Esto **no** es la traducción de `num_periodo` que la §27 llama el fallo: se
     * usa donde la fila todavía no existe y la petición la va a **crear con ese
     * mismo número** —el `else` de `definitivas_periodos/update`, que inserta con
     * `periodo_id` sacado de aquí—. Ahí la declaración y la escritura son la
     * misma cosa, así que comprobar el declarado es comprobar el escrito.
     */
    public static function porNumero($user, $numero): ?int
    {
        if (! $numero) {
            return null;
        }

        return self::uno(
            'SELECT id FROM periodos WHERE numero = ? AND year_id = ? AND deleted_at IS NULL',
            $numero,
            $user->year_id ?? null
        );
    }

    /**
     * Todos los periodos vivos del año del usuario.
     *
     * Para lo que **no cuelga de un periodo sino del año**: la recuperación
     * final. `recuperacion_final` guarda alumno, asignatura, `year` y nota — no
     * tiene `periodo_id`—, así que no hay fila de la que derivar uno, y el
     * permiso que la gobierna (`profes_pueden_nivelar`) sí es por periodo.
     *
     * Decisión de Joseth, 21 ago 2026, entre las cuatro que se midieron: **se
     * exige que estén abiertos todos**, porque lo que se toca es del año entero.
     * Como `pueden_modificar_definitivas()` cruza la lista con AND, pasar aquí
     * los cuatro periodos significa exactamente eso.
     *
     * La otra cara, que quedó dicha al elegir: si el colegio deja cerrado el
     * periodo 1 y abre el 4, la recuperación final **no se puede tocar**. Es lo
     * que se eligió a sabiendas, no un efecto lateral.
     *
     * **Y el año es el del usuario, no el de la fila.** `recuperacion_final.year`
     * guarda el NÚMERO de año (2024), no el id, así que con un `rf_id` de otro
     * año el permiso que se comprueba no es el de esa fila: con 2024 cerrado y el
     * año en curso abierto, se toca 2024. Preguntado a Joseth el 21 ago 2026 y
     * contestado **se queda**: el front manda `{rf_id, nota}` desde la pantalla
     * del año en curso, así que ninguna pantalla llega aquí con un `rf_id` viejo.
     * Lo fija `PeriodoDeLaFilaTest`, que falla el día que se cierre — 05 §27.4.
     *
     * @return array<int>
     */
    public static function todosLosDelAnio($user): array
    {
        $filas = DB::select(
            'SELECT id FROM periodos WHERE year_id = ? AND deleted_at IS NULL',
            [$user->year_id ?? null]
        );

        return array_map(fn ($f) => (int) $f->id, $filas);
    }

    /** Varias filas a la vez, sin repetidos y sin los que no se pudieron derivar. */
    public static function deVariasSubunidades(array $subunidadIds): array
    {
        $periodos = array_map(fn ($id) => self::deSubunidad($id), $subunidadIds);

        return array_values(array_unique(array_filter($periodos, fn ($p) => $p !== null)));
    }

    /** Igual, para las unidades. */
    public static function deVariasUnidades(array $unidadIds): array
    {
        $periodos = array_map(fn ($id) => self::deUnidad($id), $unidadIds);

        return array_values(array_unique(array_filter($periodos, fn ($p) => $p !== null)));
    }

    private static function uno(string $consulta, ...$parametros): ?int
    {
        if (in_array(null, $parametros, true) || in_array('', $parametros, true)) {
            return null;
        }

        $fila = DB::selectOne($consulta, $parametros);

        return $fila === null ? null : (int) array_values((array) $fila)[0];
    }
}
