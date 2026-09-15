<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Si la fila que se va a escribir es de un año que el colegio ya dejó atrás.
 *
 * Es el hermano de {@see PeriodoDeLaFila} un piso más arriba, y existe por la
 * misma razón: **la pregunta se dice en una frase y se contesta distinto en cada
 * llamada**, así que vive en un sitio y no repartida por tres controladores.
 *
 * Contesta **el hecho** —¿está cerrado ese año?— y nada más. Quién puede
 * escribir en él lo decide {@see Autoriza::puedeEscribirEnUnAnioCerrado}, que es
 * la clase donde este repositorio guarda los criterios de autorización con su
 * nombre.
 *
 * ## «Cerrado» es ANTERIOR al actual, no «distinto del actual» — y la diferencia
 * ## no es teórica
 *
 * Medido el 14 sep 2026 en la copia de desarrollo de `simonbolivar`: la tabla
 * `years` tiene **2026 creado con `actual = 0`** mientras el año en curso es
 * **2025**. O sea que el colegio prepara el año siguiente antes de conmutarlo, y
 * una regla escrita como *«bloquea todo lo que no sea el actual»* **le cerraría
 * al colegio el año que está montando** — que es justo la pantalla donde se
 * copian las escalas y el plan de área.
 *
 * Por eso la comparación es `year < referencia` y se hace sobre `years.year` —el
 * número, `int` en el esquema— y no sobre `years.id`: el id es el orden en que se
 * crearon las filas, que hoy coincide y no es lo que se está preguntando.
 *
 * ## Cuando hay VARIOS años con `actual = 1`, gana el más viejo — a propósito
 *
 * No es una hipótesis: `YearsController::putSetActual` marcaba el año como actual
 * **al destildar la casilla** (`= 1` en vez de `= $actual ? 1 : 0`), y de ahí
 * salen «los años con `actual=1` de más que hay en la base» que su propio
 * comentario documenta. El fallo está arreglado.
 *
 * **Lo que no está medido, y se dice en vez de suponerse:** cuántas filas así
 * quedaron. En las dos bases a mano el 14 sep 2026 —la copia de desarrollo de
 * `simonbolivar` y el seed de tests— hay **exactamente un `actual = 1` vivo** en
 * cada una, así que este caso **no se ha podido reproducir**: el seed tiene 2026
 * con `deleted_at` puesto y la de desarrollo lo tiene vivo pero con `actual = 0`.
 * O sea que lo de abajo es una decisión de diseño tomada a ciegas sobre una
 * población desconocida, que es exactamente cuando conviene elegir la salida que
 * degrada al comportamiento de antes.
 *
 * Con dos años marcados, cuál es «el actual» no lo puede saber esta función. De
 * las dos salidas se toma la **permisiva** —el más viejo, o sea la referencia más
 * baja, o sea menos años cerrados—, y el motivo es el reparto de daños:
 *
 *   - de más (`MIN`): la protección queda más floja en un colegio que ya tiene el
 *     dato malo, y todo lo demás sigue comportándose **exactamente como hoy**;
 *   - de menos (`MAX`): a alguien le sale un 403 al guardar en el año que su
 *     colegio considera el corriente, **sin ninguna pantalla que explique por
 *     qué**.
 *
 * Esta función existe para cerrar un agujero de la API, no para arbitrar un dato
 * malo. Quien quiera apretarlo cambia `MIN` por `MAX` sabiendo qué está eligiendo.
 *
 * ## Sin caché, y eso es deliberado
 *
 * Son pantallas de administración —escalas, frases, contratos— que se tocan un
 * puñado de veces al año, y la consulta es una fila por clave primaria. Una
 * caché estática se llevaría por delante los tests, que ejecutan muchas
 * peticiones con datos distintos dentro del mismo proceso.
 */
class AnioCerrado
{
    /**
     * `true` sólo si se puede demostrar que ese año es anterior al corriente.
     *
     * **`null` y «no se sabe» devuelven `false`, o sea "adelante"**, y es la misma
     * regla que `PeriodoDeLaFila`: lo que no se puede derivar vuelve al
     * comportamiento de antes en vez de inventarse un bloqueo. Pasa de verdad en
     * uno de los tres llamantes —`contratos.year_id` **es anulable** en el esquema,
     * y por eso la §78 tuvo que cerrar los contratos huérfanos— así que aquí no
     * hay fila de la que derivar nada.
     */
    public static function estaCerrado($yearId): bool
    {
        if ($yearId === null || $yearId === '') {
            return false;
        }

        $referencia = self::anioCorriente();

        if ($referencia === null) {
            return false;
        }

        $suyo = self::numeroDelAnio($yearId);

        if ($suyo === null) {
            return false;
        }

        return $suyo < $referencia;
    }

    /**
     * El número (2025), no el id. `null` si esa fila no está.
     *
     * No filtra `deleted_at`: un año en la papelera sigue siendo el año de esa
     * fila, y lo que se está preguntando es de cuándo es, no si el año se puede
     * usar.
     */
    public static function numeroDelAnio($yearId): ?int
    {
        $fila = DB::selectOne('SELECT year FROM years WHERE id = ?', [$yearId]);

        return $fila === null ? null : (int) $fila->year;
    }

    /**
     * El número del año que el colegio tiene por corriente, o `null` si no hay
     * ninguno marcado.
     *
     * `null` no es un caso imposible: `years.actual` no tiene ningún invariante
     * en el esquema —es la columna que `PUT years/toggle-cambiar-valor` excluye
     * justamente por eso— y un colegio recién copiado puede no tener ninguno
     * puesto todavía. Ahí no se cierra nada, que es lo que hacía esta API antes
     * de esta función.
     *
     * **No se usa `Year::actual()`** a propósito: hace `DB::select(...)[0]` y
     * revienta con 500 cuando no hay ninguno. Es uno de los dos modelos
     * congelados de `LosDosModelosCongeladosTest`, y lo que allí se decidió es
     * que el arreglo lo pone **el llamante** según lo que quiera contestar. Esto
     * quiere contestar `null`.
     */
    public static function anioCorriente(): ?int
    {
        $fila = DB::selectOne('SELECT MIN(year) AS year FROM years
            WHERE actual = 1 AND deleted_at IS NULL');

        return $fila === null || $fila->year === null ? null : (int) $fila->year;
    }
}
