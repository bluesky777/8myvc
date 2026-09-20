<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Perfiles\Publicaciones;
use App\Models\Ausencia;
use App\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * El muro **para la app**, que es el mismo muro sin lo que la app no pinta.
 *
 * Pedido por `myvc_flutter` (`docs/backend-pendiente.md` §5) y autorizado por
 * Joseth el 19 sep 2026 con el precio delante: **una ruta nueva**, la 613.
 *
 * ## Por qué una ruta y no una línea en la vieja
 *
 * La propuesta barata era no mandar `eventos` a quien no lo pinta, sin estrenar
 * ruta. **No se puede, y el motivo se midió**: este backend **no tiene forma de
 * distinguir la app del front web**. Los dos llegan con el mismo token y el
 * mismo `tipo`, y no hay cabecera de cliente —`version_minima_app` viaja hacia
 * la app, no desde ella—. O sea que «no mandes `eventos` a un acudiente» le
 * quita también el calendario al acudiente que abre el panel **en el
 * navegador**, donde sí se pinta: su carga inicial sale justamente de
 * `ChangesAsked/to-me` (`myvc_front`, `AnunciosCtrl.ts:1485`).
 *
 * Una ruta aparte no le quita nada a nadie **y envejece bien**: las versiones
 * viejas de la app —que conviven meses, porque es una sola app para los
 * dieciséis— siguen llamando a la de siempre y siguen funcionando.
 *
 * ## Lo que se ahorra, medido en la copia de desarrollo (19 sep 2026)
 *
 * ```
 * eventos, las 10 columnas que manda hoy to-me : 593 filas  128,0 KB
 * ```
 *
 * Y el dato que conviene tener delante antes de tocar nada más: **de esas 593
 * filas ninguna es de 2026**. Van de 2019 a 2025, 86 son anteriores a 2024, y
 * nadie ha curado esa tabla. O sea que la app se descarga 128 KB de un
 * calendario en el que no hay un solo evento del año en curso. Filtrar por año
 * es otra conversación —hoy dejaría el calendario vacío en el front web, que es
 * la verdad pero parece una avería— y por eso no se hace aquí.
 *
 * ## Cinco claves y no tres, y ésta es la parte que el encargo no traía
 *
 * §5 pide `publicaciones`, `alumnos` y `horario_hoy`. **La app lee cinco**
 * (`MuroApi.dart`, `cuerpo['...']`): las tres más `horario_version_id` y
 * `ausencias_periodo`. Las dos que faltaban no son adorno:
 *
 * - **`horario_version_id`** existe por una decisión de Joseth del 2 sep para
 *   que `horario_hoy: []` deje de significar dos cosas.
 *
 *   **Y el fallo que evita NO es el mensaje falso de agosto, que es lo que este
 *   comentario decía hasta que `myvc_flutter-c2` lo corrigió el 19 sep.**
 *   `HorarioDeHoy.tomar` hace `_clases = versionOficial == null ? null :
 *   clasesDeHoy`, así que con la clave ausente `seSabe` vale **`false`** y la app
 *   **no dice nada**: `MuroScreen` esconde el bloque del horario y el filtro
 *   «sólo las de hoy» de `NotasScreen` se queda apagado. O sea que omitirla
 *   **apaga la función en silencio** en vez de mentir.
 *
 *   Es un fallo más barato que el de agosto y exactamente igual de invisible —y
 *   ese comportamiento es deliberado del lado de la app: una clave ausente cuenta
 *   como `null` porque *«un servidor que todavía no tiene ese commit desplegado es
 *   exactamente un servidor del que no se sabe si hay horario»*—. La conclusión no
 *   se mueve: la clave viaja. Lo que se mueve es qué se rompe sin ella, y eso
 *   importa porque es lo que hay que buscar el día que alguien la quite.
 * - **`ausencias_periodo`** en la raíz es la asistencia del **propio alumno**
 *   (para un acudiente va colgada de cada acudido). Sin ella, `asistenciaPropia`
 *   vuelve a ser una lista vacía.
 *
 * ## Y cada rol recibe lo que hoy recibe, ni una clave más
 *
 * Un alumno **no** lleva `horario_hoy` hoy en `to-me`, y aquí tampoco. Añadírselo
 * sería una mejora —probablemente buena— pero es una decisión de producto, y una
 * ruta que se estrena para ahorrar peso no es el sitio donde se toma. Lo mismo al
 * revés: no se quita nada que la app lea.
 */
class MuroController extends Controller
{
    /**
     * Las ocho columnas de un acudido que lee `AcudidoModel.fromJson`.
     *
     * Van nombradas y **no se vuelve a `*`**: la consulta del acudiente en
     * `ChangeAskedController` trae treinta y tantas columnas —documento, eps,
     * tipo de sangre, dirección, teléfono— de las que la app lee ocho. Aquí no
     * se trata de ahorrar bytes: **son datos personales de un menor que no hacen
     * falta para pintar una ficha con foto y grupo**.
     */
    private const COLUMNAS_DEL_ACUDIDO = 'distinct(a.id) as alumno_id, a.nombres, a.apellidos, a.pazysalvo,
                    a.foto_id, IFNULL(i2.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as foto_nombre,
                    g.nombre as nombre_grupo, g.abrev as grupo_abrev, g.orden';

    public function getApp()
    {
        $user = User::fromToken();

        $muro = [
            'publicaciones' => Publicaciones::ultimas_publicaciones(
                $user->tipo === 'Profesor' ? 'Profesor' : 'Acudiente'
            ),
        ];

        // El horario sólo lo lleva quien lo lleva hoy en `to-me`: el docente. Y
        // las dos claves viajan **juntas o ninguna**, porque separadas son
        // exactamente la ambigüedad que `horario_version_id` vino a cerrar.
        if ($user->tipo === 'Profesor') {
            $dia = Carbon::now('America/Bogota')->dayOfWeek;

            // **`persona_id` y no `profesor_id`**, que es lo que usa la rama del
            // docente en `ChangeAskedController` (la de `profesor_id` es la del
            // superusuario, y para un Profesor esa propiedad **no existe** en el
            // contexto: da «Undefined property» y 500). Lo cazó el test del
            // horario; sin él habría salido el día del despliegue.
            $muro['horario_hoy'] = $this->asignaturasDeHoy(
                (int) $user->year_id, (int) $user->persona_id, (int) $user->periodo_id,
                $dia, (int) ($user->show_materias_todas ?? 0)
            );
            $muro['horario_version_id'] = $this->horarioOficialDelAnio((int) $user->year_id);
        }

        if ($user->tipo === 'Acudiente') {
            $muro['alumnos'] = $this->acudidos($user);
        }

        if ($user->tipo === 'Alumno') {
            $muro['ausencias_periodo'] = Ausencia::deAlumnoYear($user->persona_id, $user->year_id);
        }

        return $muro;
    }

    /**
     * Los acudidos, con sus faltas y **sin las seis llamadas que nadie mira**.
     *
     * El bucle de `ChangeAskedController` hace siete consultas por acudido
     * —`comportamiento`, `situaciones`, `ausencias_periodo`, `libro`,
     * `uniformes`, `prematricula` y `matri_next`— y la app sólo lee el
     * resultado de una. Aquí queda esa una. Para un acudiente con dos acudidos
     * eso son **doce consultas menos** por apertura de la app, y el argumento
     * no es el coste medio: es el pico que fabrica una notificación push, con
     * cientos de teléfonos abriendo a la vez contra un hosting de un núcleo.
     *
     * @return array<int, object>
     */
    private function acudidos($user): array
    {
        $alumnos = DB::select(
            'SELECT '.self::COLUMNAS_DEL_ACUDIDO.'
                FROM alumnos a
                INNER JOIN parentescos p ON p.alumno_id = a.id AND p.acudiente_id = ? AND p.deleted_at IS NULL
                LEFT JOIN images i2 ON i2.id = a.foto_id AND i2.deleted_at IS NULL
                LEFT JOIN matriculas m ON m.alumno_id = a.id AND m.deleted_at IS NULL
                     AND (m.estado = "ASIS" OR m.estado = "MATR" OR m.estado = "PREM")
                LEFT JOIN grupos g ON g.id = m.grupo_id AND g.deleted_at IS NULL AND g.year_id = ?
               WHERE a.deleted_at IS NULL AND g.nombre IS NOT NULL
               ORDER BY g.orden, a.apellidos, a.nombres',
            [$user->persona_id, $user->year_id]
        );

        foreach ($alumnos as $alumno) {
            $alumno->ausencias_periodo = Ausencia::deAlumnoYear($alumno->alumno_id, $user->year_id);
        }

        return $alumnos;
    }

    /**
     * La versión de horario marcada como oficial, o `null`.
     *
     * Calcada de `ChangeAskedController::horarioOficialDelAnio` **a propósito y
     * no por descuido**: son cinco líneas y una lectura por clave primaria, y
     * sacarlas a un sitio común obligaría a tocar el método viejo, que es el que
     * esta entrega se comprometió a no mover. Si algún día hay una tercera, se
     * comparte entonces.
     */
    private function horarioOficialDelAnio(int $yearId): ?int
    {
        $fila = DB::select('SELECT horario_version_id FROM years WHERE id = ?', [$yearId]);

        if ($fila === [] || $fila[0]->horario_version_id === null) {
            return null;
        }

        return (int) $fila[0]->horario_version_id;
    }

    /**
     * Las clases del docente hoy.
     *
     * Delega en el método de siempre para que **no haya dos formas de leer el
     * horario**: lo que cambie allí cambia aquí. Es lo contrario del caso de
     * arriba, y la diferencia es el tamaño — aquélla son cinco líneas, ésta es
     * la consulta de las siete columnas de día con su `switch`.
     *
     * @return array<int, object>
     */
    private function asignaturasDeHoy(int $yearId, int $profesorId, int $periodoId, int $dia, int $todas): array
    {
        return ChangeAskedController::asignaturas_dia($yearId, $profesorId, $periodoId, $dia, $todas);
    }
}
