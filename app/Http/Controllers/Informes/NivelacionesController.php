<?php

namespace App\Http\Controllers\Informes;

use App\Http\Controllers\Concerns\ResuelveElUsuario;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;

/**
 * **Las nivelaciones de un grupo, de una vez.**
 *
 *     PUT informes/nivelaciones-del-grupo   auth.personal
 *
 * Es la sección A del *acta de nivelación y recuperación* de `myvc_front`
 * (`INFORMES-NUEVOS-CIERRE.md` §3.6), y la pidió esa sesión el 20 sep 2026 con el
 * hueco medido: **de las cinco rutas que tocan nivelar, las cinco son de ESCRITURA
 * sobre una fila** —`notas/nivelar/lote`, `notas/nivelar/{id}` en PUT y DELETE,
 * `definitivas_periodos/nivelar` y `definitivas_periodos/update-recuperacion`—, así
 * que a la pregunta *«¿quién presentó nivelación en el grupo X?»* no contestaba nada.
 *
 * ## LO QUE SUSTITUYE, que es de donde sale que valga la pena
 *
 * Sin esto, el acta del grupo entero se saca **barriendo asignatura por asignatura
 * con `PUT notas/detailed`** y filtrando en el cliente. Eso tiene dos precios que no
 * son de estilo:
 *
 *  1. `notas/detailed` es **la consulta más pesada del proyecto** —así la tiene
 *     rotulada el propio front en `datos/notas.ts`— y un grupo de bachillerato tiene
 *     una docena de asignaturas.
 *  2. Las llamadas **no se pueden paralelizar**: el front las encadena a propósito
 *     porque «quien las paralelice con `forkJoin` va a tumbar el backend del colegio»
 *     (`datos/promovidos.ts`). O sea que el acta de un grupo es una docena de las
 *     consultas más caras, en serie, con barra de progreso.
 *
 * Aquí es **una consulta** que devuelve sólo las filas niveladas.
 *
 * ## ES `periodo_id` Y NO `year_id`, y la petición decía `year_id`
 *
 * La sección A es **por periodo**: lo que se nivela aquí es un indicador, y un
 * indicador vive en una unidad que tiene `periodo_id`. El propio diseño lo dice en su
 * tabla de parámetros (§3.4: *«Periodo — sólo si se piden indicadores»*). Lo que es
 * del año es la sección B, y ésa no pasa por aquí: sale de
 * `bolfinales/detailed-notas-year-group`, que desde el 20 sep 2026 ya trae las tres
 * columnas del acta.
 *
 * Es opcional y cae en el periodo del usuario, que es lo que hacen las demás.
 *
 * ## NIVELADA ES `nota_original IS NOT NULL`, y el cero cuenta
 *
 * No hay bandera de «está nivelada»: sería un segundo sitio donde mentir. El filtro va
 * **en la consulta** y no en el cliente, que es la mitad del ahorro; y se escribe `IS
 * NOT NULL` y no una comprobación de verdad laxa porque **un alumno que venía de cero
 * está nivelado** y un `if ($fila->nota_original)` lo dejaría fuera sin dar ningún
 * error. El front ya tropezó con esto y lo lleva escrito en `comunes/nivelacion.ts`.
 *
 * ## EL GUARD ES `auth.personal` Y NO LLEVA NADA DENTRO, dicho a propósito
 *
 * Esto **no abre ningún dato nuevo**: las mismas filas las sirve hoy `PUT
 * notas/detailed` con ese mismo `auth.personal`, asignatura por asignatura. Lo único
 * que cambia es que llegan en un viaje en vez de doce. Estrechar aquí y no allí sería
 * un cartel, no un candado — y dejaría el acta sin poder sacarla justo a quien la
 * firma. Queda escrito para que quien lea las dos seguidas distinga una decisión de un
 * olvido.
 */
class NivelacionesController extends Controller
{
    use ResuelveElUsuario;

    /**
     * Las filas de `notas` niveladas de un grupo y un periodo.
     *
     * Cuerpo: `grupo_id` (obligatorio), `periodo_id` y `asignatura_id` (opcionales).
     */
    public function putDelGrupo()
    {
        $user = $this->user;

        $grupo_id = (int) Request::input('grupo_id');

        if ($grupo_id <= 0) {
            abort(422, 'Falta el grupo del que se pide el acta.');
        }

        // 404 y no una lista vacía: un grupo que no existe y un grupo sin ninguna
        // nivelación se leen igual desde la pantalla, y de los dos sólo uno es un
        // error de quien llama.
        $grupo = DB::selectOne('SELECT id, year_id FROM grupos WHERE id=? and deleted_at is null', [$grupo_id]);

        if ($grupo === null) {
            abort(404, 'Ese grupo no existe.');
        }

        $periodo_id = Request::has('periodo_id')
            ? (int) Request::input('periodo_id')
            : (int) $user->periodo_id;

        // `asignatura_id` es el parámetro que el diseño pone para que «con una
        // asignatura el acta salga con una llamada». Aquí ya sale con una llamada
        // siempre, así que lo que acota es el PAPEL y no el coste: un acta de una
        // asignatura no debe imprimir las otras once.
        $asignatura_id = Request::has('asignatura_id') ? (int) Request::input('asignatura_id') : null;

        // **Las tres notas van SIN `CAST`, igual que en `PUT notas/detailed`.** Es la
        // corrección de un `CAST(... AS DOUBLE)` que se escribió aquí por costumbre: el
        // front compara el trío (`nota_original`, `nota_nivelacion`, `nota`) con
        // `loQueQueda()` para decir si la fila cuadra con la regla vigente del colegio, y
        // **esa comparación la hace con lo que le llega**. Si la pantalla de nivelaciones
        // recibiera un tipo y el acta otro, las dos podrían no decir lo mismo de la misma
        // fila — y la que se firma es el acta.
        //
        // **Los `JOIN` son los de `PUT notas/detailed` y no otros**, a propósito: si
        // esta consulta alcanzara filas que aquélla no alcanza, el acta y la pantalla
        // de nivelaciones dirían cosas distintas sobre el mismo grupo, y la que se
        // firma es ésta.
        //
        // **La única diferencia deliberada es `u.alumno_id`.** Allí va `<=> :alcance`
        // porque es de UN alumno; aquí el grupo entero lleva dentro alumnos con
        // boletín independiente, y cada uno tiene su propio alcance. `IS NULL OR =
        // n.alumno_id` es esa misma regla escrita para muchos: las unidades del grupo
        // valen para todos, y las unidades propias de un alumno **sólo para él**. Sin
        // la segunda mitad, un marcado aparecería en el acta con los indicadores de
        // otro.
        $cons = 'SELECT n.id, n.alumno_id, a.apellidos, a.nombres,
                        asi.id as asignatura_id, m.materia, m.alias,
                        u.id as unidad_id, u.definicion as unidad, u.periodo_id,
                        s.id as subunidad_id, s.definicion as indicador,
                        n.nota, n.nota_original, n.nota_nivelacion,
                        n.nivelada_at, n.nivelada_por, univ.username as nivelada_por_username,
                        n.nivelacion_obs
                   FROM notas n
                   LEFT JOIN users univ ON univ.id=n.nivelada_por
                   INNER JOIN alumnos a ON a.id=n.alumno_id and a.deleted_at is null
                   INNER JOIN subunidades s ON s.id=n.subunidad_id and s.deleted_at is null
                   INNER JOIN unidades u ON u.id=s.unidad_id and u.deleted_at is null and u.periodo_id=:periodo_id
                   INNER JOIN asignaturas asi ON asi.id=u.asignatura_id and asi.deleted_at is null and asi.grupo_id=:grupo_id
                   INNER JOIN materias m ON m.id=asi.materia_id and m.deleted_at is null
                  WHERE n.deleted_at is null
                    AND n.nota_original IS NOT NULL
                    AND (u.alumno_id IS NULL OR u.alumno_id = n.alumno_id)';

        $datos = [':periodo_id' => $periodo_id, ':grupo_id' => $grupo_id];

        if ($asignatura_id !== null) {
            $cons .= ' AND asi.id=:asignatura_id';
            $datos[':asignatura_id'] = $asignatura_id;
        }

        // El orden es el del PAPEL —como se lee el acta— y no el de la base: por
        // estudiante, y dentro de cada uno por asignatura y por el orden en que el
        // docente colocó la unidad y el indicador.
        $cons .= ' ORDER BY a.apellidos, a.nombres, asi.orden, u.orden, s.orden';

        $nivelaciones = DB::select($cons, $datos);

        // **Se devuelve con qué se contestó y no sólo la lista**, porque `periodo_id`
        // puede haberlo puesto el servidor: una hoja que imprime «periodo 3» tiene que
        // saber que le contestaron del 3, no suponerlo del token.
        return [
            'grupo_id' => $grupo_id,
            'year_id' => (int) $grupo->year_id,
            'periodo_id' => $periodo_id,
            'asignatura_id' => $asignatura_id,
            'nivelaciones' => $nivelaciones,
        ];
    }
}
