<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResuelveElUsuario;
use App\Support\Autoriza;
use Illuminate\Support\Facades\DB;

/**
 * La huella de las cinco lecturas con las que la app de escritorio se sincroniza.
 *
 * `myvc_horarios` pregunta **cada minuto** si el colegio ha cambiado. Hasta hoy
 * eso eran **cinco viajes y 121.183 bytes por pregunta** —`years`, `grados`,
 * `grupos`, `profesores`, `asignaturas`—, medido el 7 sep 2026 contra el docker
 * con un token real. En una jornada de ocho horas son **58,2 MB** por escritorio
 * abierto (58.167.840 B; 55,5 MiB), y en 24 h serían 174,5 MB — *esa segunda cifra
 * no la paga nadie, porque el programa se cierra por la tarde, y va escrita sólo
 * para que nadie la use para decidir*.
 *
 * Y **no había forma de preguntar barato**: ninguna de las cinco manda `ETag` ni
 * `Last-Modified`, así que la petición condicional no existe y un 304 es
 * imposible.
 *
 * Esto es un viaje, una respuesta de bytes y **cinco cuentas en vez de traer 207
 * filas**: es la única salida de las tres que ahorra trabajo **también al
 * servidor**. El contrato entero y las tres opciones con su precio están en
 * `docs/migracion/34-la-huella-de-sincronizacion.md`.
 *
 * ## Las dos cifras por lectura, y por qué son DOS
 *
 * `(filas, ultimo_cambio)`. No es redundancia: **`updated_at` no ve un borrado**
 * —la fila que se va no deja timbre— y el número de filas sí. Con una sola de las
 * dos, borrar algo sería invisible hasta que alguien lo tocara.
 *
 * ## Y se calcula sobre LO QUE DEVUELVE CADA LECTURA, no sobre la tabla
 *
 * Es la restricción que decide si esto mide lo que dice medir. Las tablas y las
 * respuestas **no se parecen**, medido el 7 sep 2026:
 *
 *     tabla         filas en la tabla    filas que devuelve la lectura
 *     grados               16                      14
 *     grupos              118                      13
 *     profesores           53                      47
 *     asignaturas       1.459                     134
 *
 * Una huella de la tabla entera se movería con cosas que al cliente **no le
 * llegan** y —peor— **podría no moverse con cosas que sí**. Por eso cada consulta
 * de aquí repite los `WHERE` y los `JOIN` de su `getIndex`, incluido el año del
 * usuario, y por eso cambiarlos allí obliga a cambiarlos aquí: hay un test que
 * compara las dos poblaciones y se pone rojo si se separan.
 */
class SincronizacionController extends Controller
{
    use ResuelveElUsuario;

    /**
     * GET /api/sincronizacion/huella
     *
     * ## El bloque de `profesores` va aparte, y no por gusto
     *
     * De las cinco lecturas, cuatro se conforman con `auth.token`; **`GET
     * profesores` exige `auth.personal` en la ruta y `esAdministrativo` DENTRO del
     * método**. Medido el 7 sep 2026 sobre `simonbolivar`, esa escalera es:
     *
     *     auth.token         2.328 cuentas activas
     *     auth.personal         45
     *     esAdministrativo      10   (10 superusuarios + 0 secretarios)
     *
     * O sea que una huella que devolviera el movimiento de `profesores` a todo el
     * que pasa el guard de la ruta **se lo estaría contando a 35 personas que no
     * pueden leer esa tabla**. Que sean dos números no lo hace inocuo: «el
     * personal cambió hace un minuto» es información sobre personas.
     *
     * Así que el bloque se calcula **sólo si el que pregunta puede leerlo**, con el
     * mismo criterio que la lectura de verdad.
     *
     * ## Pero la CLAVE no desaparece: se queda a `null` y además se anuncia
     *
     * **La forma de la respuesta no cambia según quién pregunta.** `huellas` trae
     * siempre las cinco claves; la que no se puede ver vale `null` y su motivo va
     * en `omitidas`.
     *
     * Es la misma decisión que la 7 del `inadecuado`, tomada sobre **este mismo
     * cliente** y con su precio delante: allí se pudo quitar `profesor_id` de la
     * respuesta y **a propósito no se quitó**, se hizo anulable, *porque un lector
     * que exija la clave se rompe con el usuario raso y no con el administrativo* —
     * el fallo que sólo sale en producción **y en la mitad de las cuentas**. Aquí
     * pasaría igual: el escritorio lo usan administrativos, así que en pruebas
     * siempre saldrían las cinco.
     *
     * Y **las dos cosas juntas, no una**: `null` mantiene la forma, y `omitidas`
     * dice **por qué** — un `null` a secas y «no ha cambiado nada» se leen igual, y
     * de las dos lecturas la falsa es la que deja al escritorio con datos viejos
     * creyendo que está al día.
     */
    public function getHuella()
    {
        $anio = (int) $this->user->year_id;

        $huellas = [
            'years' => $this->deYears(),
            'grados' => $this->deGrados(),
            'grupos' => $this->deGrupos($anio),
            'asignaturas' => $this->deAsignaturas($anio),
        ];

        $omitidas = [];

        // La clave está SIEMPRE. Lo que cambia es si trae algo. Ver el docblock:
        // quitarla haría que la forma dependiera de quién pregunta, y el cliente
        // que la lea se rompería sólo con las cuentas que no son administrativas.
        $huellas['profesores'] = null;

        if (Autoriza::esAdministrativo($this->user)) {
            $huellas['profesores'] = $this->deProfesores($anio);
        } else {
            $omitidas['profesores'] = 'Necesitas permiso de administración para ver esta huella, '.
                'el mismo que para leer `GET profesores`.';
        }

        return [
            'year_id' => $anio,
            'huellas' => $huellas,
            'omitidas' => (object) $omitidas,
        ];
    }

    /**
     * `YearsController::getIndex` — todos los años vivos, **con sus periodos
     * dentro**.
     *
     * Los periodos entran en la huella porque **no son una columna decorativa: son
     * filas de otra tabla que viajan en la respuesta**. Un colegio que avanza de
     * periodo cambia lo que devuelve la lectura sin tocar `years`, y sin esto el
     * escritorio no se enteraría nunca.
     */
    private function deYears(): array
    {
        $fila = DB::selectOne(
            'SELECT COUNT(*) AS filas, MAX(y.updated_at) AS propio,
                    (SELECT MAX(p.updated_at) FROM periodos p
                      INNER JOIN years yy ON yy.id = p.year_id AND yy.deleted_at IS NULL
                      WHERE p.deleted_at IS NULL) AS unido
             FROM years y WHERE y.deleted_at IS NULL'
        );

        // Los periodos cuentan para la fecha pero NO para el número de filas: la
        // lectura devuelve un elemento por año, no por periodo, así que meterlos en
        // el conteo mentiría sobre el tamaño de la respuesta.
        return $this->huella($fila);
    }

    /** `GradosController::getIndex` — el `INNER JOIN` a niveles es suyo, no un adorno. */
    private function deGrados(): array
    {
        return $this->huella(DB::selectOne(
            'SELECT COUNT(*) AS filas, MAX(g.updated_at) AS propio, MAX(n.updated_at) AS unido
             FROM grados g
             INNER JOIN niveles_educativos n ON n.id = g.nivel_educativo_id
             WHERE g.deleted_at IS NULL'
        ));
    }

    /** `GruposController::getIndex` — filtrado por el año del usuario. */
    private function deGrupos(int $anio): array
    {
        return $this->huella(DB::selectOne(
            'SELECT COUNT(*) AS filas, MAX(g.updated_at) AS propio,
                    GREATEST(COALESCE(MAX(gra.updated_at), 0), COALESCE(MAX(p.updated_at), 0)) AS unido
             FROM grupos g
             INNER JOIN grados gra ON gra.id = g.grado_id AND g.year_id = ?
             LEFT JOIN profesores p ON p.id = g.titular_id
             WHERE g.deleted_at IS NULL',
            [$anio]
        ));
    }

    /** `AsignaturasController::getIndex` — el nombre de la materia y el área viajan dentro. */
    private function deAsignaturas(int $anio): array
    {
        return $this->huella(DB::selectOne(
            'SELECT COUNT(*) AS filas, MAX(a.updated_at) AS propio,
                    GREATEST(COALESCE(MAX(m.updated_at), 0), COALESCE(MAX(ar.updated_at), 0)) AS unido
             FROM asignaturas a
             INNER JOIN materias m ON m.id = a.materia_id AND m.deleted_at IS NULL
             LEFT JOIN areas ar ON ar.id = m.area_id AND ar.deleted_at IS NULL
             INNER JOIN grupos g ON g.id = a.grupo_id AND g.year_id = ? AND g.deleted_at IS NULL
             WHERE a.deleted_at IS NULL',
            [$anio]
        ));
    }

    /** `ProfesoresController::getIndex` — el usuario y el contrato del año viajan dentro. */
    private function deProfesores(int $anio): array
    {
        return $this->huella(DB::selectOne(
            'SELECT COUNT(*) AS filas, MAX(p.updated_at) AS propio,
                    GREATEST(COALESCE(MAX(u.updated_at), 0), COALESCE(MAX(c.updated_at), 0)) AS unido
             FROM profesores p
             LEFT JOIN users u ON p.user_id = u.id AND u.is_active = true
             LEFT JOIN contratos c ON c.profesor_id = p.id AND c.year_id = ? AND c.deleted_at IS NULL
             WHERE p.deleted_at IS NULL',
            [$anio]
        ));
    }

    /**
     * Las dos cifras, con la fecha ya resuelta entre la tabla y sus unidas.
     *
     * **La comparación se hace en PHP y no con `GREATEST` a secas sobre las dos.**
     * Mezclar un `datetime` con el `0` de un `COALESCE` obliga al motor a
     * convertir, y MariaDB 10.5 —que es producción— y MySQL 8 —que es el docker—
     * no tienen por qué coincidir en esa conversión. Aquí llegan las dos como
     * cadenas `Y-m-d H:i:s`, que **ordenan igual como texto que como fecha**, y la
     * comparación es de PHP. Es la lección de
     * `docs/migracion/33-la-tilde-que-sql-no-ve.md`: lo que se puede sacar del
     * motor, se saca.
     *
     * `null` en `ultimo_cambio` significa **«ninguna de las filas que se devuelven
     * tiene fecha»**, no «no hay filas»: para eso está `filas`.
     *
     * @param  object|null  $fila
     * @return array{filas: int, ultimo_cambio: string|null}
     */
    private function huella($fila): array
    {
        $candidatas = array_filter([
            $fila->propio ?? null,
            // El `0` que puede devolver un COALESCE sin filas no es una fecha.
            ($fila->unido ?? null) === '0' ? null : ($fila->unido ?? null),
        ], fn ($v) => is_string($v) && $v !== '' && $v !== '0');

        return [
            'filas' => (int) ($fila->filas ?? 0),
            'ultimo_cambio' => $candidatas === [] ? null : max($candidatas),
        ];
    }
}
