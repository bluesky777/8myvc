<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Hace que los `timestamps` automáticos de Eloquent salgan del reloj de la casa.
 *
 * ## Qué arregla
 *
 * `Model::freshTimestamp()` devuelve `Carbon::now()`, que usa la zona de
 * `config/app.php` —**UTC**, y ahí sigue por la decisión 2 del
 * [18](../../docs/migracion/18-auditoria.md)—. Todo lo que este sistema escribe a
 * mano pasa por {@see Reloj} y va en **Bogotá**. O sea que un `->save()` y un
 * `DB::insert` sobre **la misma tabla** dejan la misma columna con cinco horas de
 * diferencia, y en la fila no queda nada que diga cuál es cuál.
 *
 * No es una hipótesis. Medido el 21 sep 2026 en la copia de `caz_zaragoza`,
 * emparejando cada nota con la subunidad de la que nace —se crean **en la misma
 * petición**, así que entre sus `created_at` no debería haber hueco—:
 *
 * | diferencia | pares | quién escribe |
 * |---|---:|---|
 * | 18.000 s exactos (5 h) | 34.903 | alta normal: `Subunidad->save()` (Eloquent, UTC) y sus notas por `Nota::verificarCrearNotas` (Bogotá) |
 * | 0 s | 6.188 | `PeriodosController::putCopiar`, que crea las dos con `new Nota` + `save()`: **las dos en UTC** |
 *
 * Y las dos familias van de 2018 a 2026 entremezcladas.
 *
 * ## Por qué se arregla en el que ESCRIBE y no en el que lee
 *
 * Porque el que lee no puede. Sumarle cinco horas a `subunidades` en el detector
 * de definitivas arreglaría los 34.903 pares y **rompería los 6.188** que ya
 * estaban alineados. Es la misma conclusión a la que llegó `bitacoras.created_at`
 * —12 filas en UTC contra 74 en Bogotá— y por la que existe {@see Reloj}.
 *
 * ## Lo que cuesta, y por qué no se pone en TODOS los modelos
 *
 * Esto **no** se sube a un modelo base. Hay tablas cuya fecha se compara contra un
 * `now()` de UTC generado en PHP —las expiraciones de sesión y los tokens son el
 * caso claro—, y moverles el reloj cinco horas les cambia la vida útil. Por eso
 * el rasgo se pone **tabla por tabla**, y sólo donde la columna ya convive con
 * fechas escritas en Bogotá.
 *
 * Hoy son las cuatro que alimentan el sello de las definitivas
 * (`DefinitivasDeAsignatura::selloDeVersion`): `notas`, `subunidades`, `unidades`
 * y `matriculas`. Las cuatro se escriben ya por los dos caminos.
 *
 * **Las filas viejas no las toca nadie**: las de UTC se quedan cinco horas por
 * delante hasta que alguien las vuelva a guardar. Repararlas es otra decisión, con
 * su medición y su herramienta, como se hizo en
 * `2026_09_06_100000_reparar_la_hora_escrita_dos_veces`.
 *
 * @see Reloj  la decisión de fondo: lo que se guarda va en Bogotá
 */
trait SellaConElReloj
{
    /**
     * La hora con la que Eloquent rellena `created_at`, `updated_at` y —por
     * `SoftDeletes`— `deleted_at`.
     *
     * Los tres importan: el sello de las definitivas mira `updated_at` **y**
     * `deleted_at`, que es como ve que alguien borró una nota (§4.2 del 10).
     */
    public function freshTimestamp(): Carbon
    {
        // `Illuminate\Support\Carbon` y no el de la librería: es el que declara
        // `Model::freshTimestamp()`, y devolver el padre le quita a Eloquent los
        // métodos que le añade Laravel. `instance()` no toca la hora ni la zona.
        return Carbon::instance(Reloj::ahora());
    }
}
