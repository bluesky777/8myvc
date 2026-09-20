<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Los eventos del calendario **del año en curso**, y en un solo sitio.
 *
 * Decidido por Joseth el 20 sep 2026 con lo medido delante y sabiendo lo que
 * cuesta (abajo, «lo que esto apaga»).
 *
 * ## El problema, medido
 *
 * La tabla `calendario` **no la ha curado nadie**: en la copia de desarrollo
 * tiene **593 filas visibles, de 2019 a 2025, y ni una de 2026**. Las seis
 * consultas que la leían no tenían filtro de año ni de fecha, así que cada
 * lectura arrastraba el calendario entero desde 2019:
 *
 * ```
 * to-me, con las 10 columnas nombradas : 593 filas  128,0 KB
 * calendario/this-year, con `SELECT *` : 593 filas  215,5 KB
 * ```
 *
 * El recorte de columnas del 2 sep 2026 arregló las cinco de `ChangeAsked` y
 * **se saltó `calendario/this-year`**, que es la que llama el botón
 * «Actualizar» del panel: por eso una manda 128 KB y la otra 215,5 con los
 * mismos datos.
 *
 * ## Por qué solape y no `YEAR(start)`
 *
 * `start < {año+1}-01-01 AND COALESCE(end, start) >= {año}-01-01`. Con
 * `YEAR(start) = año` a secas, un evento que empieza el 20 de diciembre y
 * termina en enero **desaparece del año en el que termina**. Y es la misma
 * forma que ya usa `CalendarioController::eventosManualesDelRango` para su
 * rango, así que **las dos maneras de leer el calendario coinciden** en vez de
 * discrepar en los bordes — que era una de las cosas raras de antes: el panel
 * cargaba una lista al entrar y otra distinta al pulsar «Actualizar».
 *
 * `COALESCE` porque `end` es nullable: un evento de un solo día no lo trae.
 *
 * ## Lo que NO hace, para que nadie lo busque aquí
 *
 * **No decide las columnas.** Cada llamante manda las suyas: `ChangeAsked` las
 * diez del recorte del 2 sep, `calendario/this-year` las diecisiete que
 * conservan su forma. Lo único que esta clase unifica es **qué filas**.
 *
 * ## Lo que esto apaga, y va escrito porque se verá antes que el ahorro
 *
 * **Hoy, en un colegio cuyo año en curso sea 2026, el calendario del panel sale
 * vacío.** No es una avería: es que **no hay ni un evento de 2026 cargado**. La
 * alternativa era seguir mandando el de 2019 para que la pantalla pareciera
 * llena, que es peor de las dos maneras — cuesta 215 KB por apertura y enseña
 * fechas de hace siete años.
 *
 * Quien vea el calendario vacío después de desplegar esto **no tiene que
 * revertirlo**: tiene que cargar el año.
 *
 * ## Y estrecha dos instantáneas de FORMA, que no es lo que parece
 *
 * `muestreo-ChangesAsked-to-me` y `muestreo-calendario-this-year` pasan de
 * `'end' => 'null|string'` a `'end' => 'null'` (y una de `updated_by` igual).
 * **No es que el contrato haya cambiado**: es que la muestra encogió y en las
 * filas del año en curso del seed ningún evento tiene `end`.
 *
 * O sea que esas dos instantáneas **se han vuelto más estrechas de lo que el
 * endpoint puede devolver**. El día que el seed —o el colegio— tenga un evento
 * de varios días en el año en curso, volverán a `null|string` y **eso no será
 * una regresión**: será la muestra recuperando lo que la respuesta siempre pudo
 * dar. Se regeneran y se sigue.
 *
 * ## Y el año sale del token, no del reloj
 *
 * `$user->year`, que es el año lectivo de la persona. Un colegio que todavía
 * esté cerrando 2025 en enero de 2027 sigue viendo su calendario; uno que ya
 * abrió 2027 ve el suyo. Con `Carbon::now()->year` los dos verían el del
 * calendario gregoriano, que no es el año del que habla este sistema.
 */
final class EventosDelAnio
{
    /**
     * Las diez columnas del recorte del 2 sep, que son las que usa `ChangeAsked`.
     *
     * **Es el valor por defecto, no la única lista, y eso es deliberado.**
     * `calendario/this-year` devuelve **diecisiete** —las que tenía la tabla
     * antes de la migración del calendario, en ese orden— para que su respuesta
     * no se mueva ni una clave, y eso lo decidió la tanda de `feat/calendario`
     * con su porqué escrito. Esta clase comparte **la regla del año**, que es lo
     * nuevo; estrechar de paso a diez habría deshecho aquella decisión sin
     * discutirla, que es la forma más fácil de romper algo en un repositorio con
     * seis sesiones a la vez.
     */
    public const COLUMNAS_DEL_RECORTE = 'id, title, start, end, allDay, solo_profes, cumple_alumno_id, cumple_profe_id, url, created_by_nombres';

    /**
     * @param  bool  $conLosInternos  si ve también los `solo_profes` (personal del colegio)
     * @param  string  $columnas  las de quien llama; por defecto las diez del recorte
     * @return array<int, object>
     */
    public static function delAnio(int $anio, bool $conLosInternos, string $columnas = self::COLUMNAS_DEL_RECORTE): array
    {
        $sql = 'SELECT '.$columnas.' FROM calendario
                 WHERE deleted_at IS NULL
                   AND start < ? AND COALESCE(end, start) >= ?';

        if (! $conLosInternos) {
            $sql .= ' AND solo_profes = 0';
        }

        return DB::select($sql.' ORDER BY start, id', [
            ($anio + 1).'-01-01',
            $anio.'-01-01',
        ]);
    }
}
