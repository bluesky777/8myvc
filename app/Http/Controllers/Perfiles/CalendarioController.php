<?php

namespace App\Http\Controllers\Perfiles;

use App\Http\Controllers\Controller;
use App\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;

/**
 * El calendario del colegio.
 *
 * **Sus cuatro rechazos respondían `404, 'No tienes permiso'`**, o sea un código
 * y un mensaje que dicen cosas distintas: el cuerpo habla de permisos y el
 * código dice que la ruta o la fila no existen. En un API donde 404 significa
 * «esa fila no está» en todas partes —y donde se acaba de gastar una serie
 * entera en que lo signifique—, esto es la contraria de un 200 que miente.
 *
 * Pasan a 403. El front no mira el código en ninguna de las cuatro: pinta el
 * mensaje del cuerpo con `toastr.error`. Ver 05 §54.
 */
class CalendarioController extends Controller
{
    /**
     * **Quién ve los eventos internos lo decide el token, no el cuerpo.** Ver 05 §150.
     *
     * `calendario.solo_profes` es el interruptor con el que el colegio marca un
     * evento como interno, y hasta hoy el booleano que decidía si se aplicaba
     * llegaba **en el cuerpo de la petición** (`is_prof_admin`). La columna
     * funcionaba: sin la bandera, un alumno veía exactamente los públicos. Lo que
     * fallaba era de dónde salía el dato. Medido con token de alumno mandando
     * `is_prof_admin=true`: recibía los eventos `solo_profes = 1`.
     *
     * **El criterio es el que ya usan las otras cuatro rutas de este mismo
     * controlador**: `($user->tipo == 'Profesor') || $user->is_superuser`. No es
     * uno nuevo, que es lo que evita acabar con cuatro criterios para el mismo
     * módulo.
     *
     * El candidato alternativo era el de `ExigirPersonal` —«no es alumno ni
     * acudiente»— y **se descartó midiendo, no razonando**. El front manda
     * `IS_PROF_ADMIN = hasRoleOrPerm(['admin', 'profesor'])`, o sea un criterio de
     * **rol**, así que la pregunta era si hay personal con rol `Admin` y sin
     * `is_superuser`, que con «no es familia» ganaría acceso y hoy no lo tiene.
     * Contado en la base: de las **20 cuentas de tipo `Usuario`**, **10 son
     * superusuario y tienen el rol `Admin`, y las otras 10 no tienen ninguno de
     * los dos**. Los dos conjuntos coinciden, así que este `if` **reproduce
     * exactamente lo que ve hoy cada persona** y lo único que cambia es de dónde
     * sale el dato.
     *
     * Con «no es familia» habrían **ganado** acceso a los eventos internos diez
     * cuentas administrativas —secretaría, coordinación, enfermería, rectoría—.
     * Puede que sea lo que el colegio quiere; no lo decide un arreglo. Queda
     * anotado: **si `solo_profes` significa «solo profesores» o «solo personal»
     * es una pregunta para Joseth**, y hoy significa lo primero.
     *
     * La ruta **no lleva `auth.personal`** y no debe llevarlo: el calendario
     * público es de todo el mundo. Lo que se filtra son las filas.
     *
     * Ningún cliente lo usa como conmutador de pantalla: en las 23 ramas de
     * `myvc_front` hay **una sola** llamada —`AnunciosCtrl.ts:482`—, y manda
     * justamente ese predicado de rol. `myvc_front_2` y `myvc_flutter` no la
     * llaman. Así que esto es un arreglo, no un cambio de contrato.
     *
     * Lo fija `CalendarioInternoTest`, con las dos mitades: que la familia deje de
     * verlos y que **el personal los siga viendo sin mandar nada**.
     */
    public function putThisYear()
    {
        $user = User::fromToken();

        // El mismo `if` que `putCrearEvento`, `putGuardarEvento`,
        // `putEliminarEvento` y `putSincronizarCumples` treinta líneas más abajo.
        $puedeVerLosInternos = ($user->tipo == 'Profesor') || $user->is_superuser;

        if ($puedeVerLosInternos) {
            $eventos = DB::select('SELECT * FROM calendario WHERE deleted_at is null');
        } else {
            $eventos = DB::select('SELECT * FROM calendario WHERE solo_profes=0 and deleted_at is null');
        }

        return $eventos;
    }

    public function putCrearEvento()
    {
        $user = User::fromToken();
        if (($user->tipo == 'Profesor') || $user->is_superuser) {
            $now = Carbon::now('America/Bogota');

            $title = Request::input('title');
            $start = Request::input('start');
            $end = Request::input('end');
            $allDay = Request::input('allDay');
            $solo_profes = Request::input('solo_profes', 0);
            $nombres = $user->tipo == 'Usuario' ? $user->username : ($user->nombres.' '.$user->apellidos);

            $consulta = 'INSERT INTO calendario(created_by, created_by_nombres, title, start, end, allDay, solo_profes, created_at, updated_at) 
                        VALUES(:created_by, :created_by_nombres, :title, :start, :end, :allDay, :solo_profes, :created_at, :updated_at)';
            DB::insert($consulta, [
                ':created_by' => $user->user_id,
                ':created_by_nombres' => $nombres,
                ':title' => $title,
                ':start' => $start,
                ':end' => $end,
                ':allDay' => $allDay,
                ':solo_profes' => $solo_profes,
                ':created_at' => $now,
                ':updated_at' => $now,
            ]);

            $last_id = DB::getPdo()->lastInsertId();

            return ['evento_id' => $last_id];
        } else {
            return abort(403, 'No tienes permiso');
        }

    }

    public function putGuardarEvento()
    {
        $user = User::fromToken();
        if (($user->tipo == 'Profesor') || $user->is_superuser) {
            $now = Carbon::now('America/Bogota');

            $title = Request::input('title');
            $start = null;
            $end = null;
            $allDay = Request::input('allDay');
            $solo_profes = Request::input('solo_profes', 0);
            $nombres = $user->tipo == 'Usuario' ? $user->username : ($user->nombres.' '.$user->apellidos);

            if (Request::input('start')) {
                $start = Carbon::parse(Request::input('start'));
            }
            if (Request::input('end')) {
                $end = Carbon::parse(Request::input('end'));
            }

            $consulta = 'UPDATE calendario SET updated_by=:updated_by, title=:title, 
                        start=:start, end=:end, allDay=:allDay, solo_profes=:solo_profes, updated_at=:updated_at
                        WHERE id=:id';
            DB::update($consulta, [
                ':updated_by' => $user->user_id,
                ':title' => $title,
                ':start' => $start,
                ':end' => $end,
                ':allDay' => $allDay,
                ':solo_profes' => $solo_profes,
                ':updated_at' => $now,
                ':id' => Request::input('id'),
            ]);

            return 'Modificado';
        } else {
            return abort(403, 'No tienes permiso');
        }
    }

    public function putEliminarEvento()
    {
        $user = User::fromToken();
        if (($user->tipo == 'Profesor') || $user->is_superuser) {
            $now = Carbon::now('America/Bogota');

            $consulta = 'UPDATE calendario SET deleted_at=:deleted_at, deleted_by=:deleted_by WHERE id=:id';
            DB::update($consulta, [
                ':deleted_at' => $now,
                ':deleted_by' => $user->user_id,
                ':id' => Request::input('id'),
            ]);

            return 'Eliminado';
        } else {
            return abort(403, 'No tienes permiso');
        }
    }

    public function putSincronizarCumples()
    {
        $user = User::fromToken();
        $nombres = $user->tipo == 'Usuario' ? $user->username : ($user->nombres.' '.$user->apellidos);

        if (($user->tipo == 'Profesor') || $user->is_superuser) {
            $now = Carbon::now('America/Bogota');

            $consulta = 'DELETE FROM calendario WHERE cumple_alumno_id is not null or cumple_profe_id is not null';
            DB::delete($consulta);

            // El nombre entraba aqui SIN LIGAR, dentro de unas comillas dobles del SQL. No llega
            // del cuerpo de esta peticion --por eso ningun detector de asimetria lo veia-- sino
            // de la fila del usuario, y esa fila la escribe el cuerpo de OTRA ruta: `postStore`
            // de ProfesoresController asigna `nombres` desde `Request::input` y no exige nada,
            // asi que quien pueda crear un profesor elige el texto que acaba dentro de este SQL.
            // Se guarda por una puerta y detona por otra. Lo fija CalendarioCumplesTest.
            $consulta = 'INSERT INTO calendario(created_by, created_by_nombres, title, start, allDay, cumple_alumno_id, created_at, updated_at)
                SELECT ? as created_by, ? as created_by_nombres, CONCAT("Cumple ", CONCAT(a.nombres, " ", a.apellidos), "(", g.abrev, ")") as title, 
                    CONCAT(REPLACE(a.fecha_nac, SUBSTRING_INDEX(a.fecha_nac, "-", 1), ?), " 05:00:00") as start, 1 as allDay, a.id as cumple_alumno_id, ? as created_at, ? as updated_at
                FROM alumnos a
                INNER JOIN matriculas m ON m.alumno_id=a.id and m.deleted_at is null and a.fecha_nac is not null
                INNER JOIN grupos g ON g.id=m.grupo_id and g.year_id=? and g.deleted_at is null';

            DB::insert($consulta, [$user->user_id, $nombres, $user->year, $now, $now, $user->year_id]);

            // Lo mismo un piso mas abajo: mismo `$nombres`, misma via.
            $consulta = 'INSERT INTO calendario(created_by, created_by_nombres, title, start, allDay, cumple_profe_id, created_at, updated_at)
                SELECT ? as created_by, ? as created_by_nombres, CONCAT("Cumple ", CONCAT(a.nombres, " ", a.apellidos), "(docente)") as title, 
                    CONCAT(REPLACE(a.fecha_nac, SUBSTRING_INDEX(a.fecha_nac, "-", 1), ?), " 05:00:00") as start, 1 as allDay, a.id as cumple_profe_id, ? as created_at, ? as updated_at
                FROM profesores a
                INNER JOIN contratos c ON c.profesor_id=a.id and c.year_id=? and c.deleted_at is null and a.fecha_nac is not null';

            DB::insert($consulta, [$user->user_id, $nombres, $user->year, $now, $now, $user->year_id]);

            return 'Sincronizados';

        } else {
            return abort(403, 'No tienes permiso');
        }
    }
}
