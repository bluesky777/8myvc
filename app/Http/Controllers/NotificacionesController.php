<?php

namespace App\Http\Controllers;

use App\Services\Notificaciones\TemasDeNotificacion;
use App\User;
use Illuminate\Support\Facades\DB;

/**
 * Los temas de notificación que le tocan a quien se identifica.
 *
 * **Es la pieza de seguridad del diseño de push entero**, y por eso es un
 * endpoint y no una fórmula en el teléfono. Firebase reparte por temas y el
 * teléfono se apunta él mismo, así que el nombre del tema es la única puerta: si
 * se llamara `alumno_345`, cualquiera con la app se apuntaría al `alumno_346` y
 * recibiría los avisos de un menor que no es suyo.
 *
 * Aquí se contesta con los temas ya derivados —HMAC-SHA256 con un secreto que
 * vive sólo en el servidor, ver `TemasDeNotificacion`— y **sólo con los que le
 * tocan a quien pregunta**: los suyos si es alumno, los de sus acudidos si es
 * acudiente. Nadie recibe el de otro y nadie puede calcularlo.
 *
 * El plan entero está en `myvc_flutter/docs/notificaciones.md`.
 */
class NotificacionesController extends Controller
{
    /**
     * `GET notificaciones/temas`.
     *
     * ## Quién recibe qué
     *
     * | Quien pregunta | Qué recibe |
     * |---|---|
     * | `Alumno` | los tres temas de su propia ficha, más los del colegio |
     * | `Acudiente` | los tres de **cada acudido**, más los del colegio |
     * | Personal | sólo los del colegio |
     *
     * **El personal no recibe temas de alumno y no es un olvido.** Un profesor no
     * necesita que le avisen de las notas que pone él, y dárselos convertiría a
     * cualquier cuenta de personal en una forma de conseguir el tema de cualquier
     * alumno — que es exactamente lo que este endpoint existe para impedir. El
     * día que el colegio pida avisos para el personal, serán temas suyos y otra
     * consulta.
     *
     * ## Por qué van los nombres dentro
     *
     * Porque el acudiente con tres acudidos tiene que poder apagar los avisos de
     * uno y no de los otros, y para eso la pantalla de preferencias necesita
     * decir de quién es cada interruptor. Es un nombre que el acudiente ya ve en
     * toda la app, no un dato nuevo.
     *
     * ## Y las preferencias no se guardan aquí
     *
     * Apagar «Notas» es que el teléfono se desapunte de ese tema: una llamada a
     * Google, **cero peticiones a este servidor, cero filas y cero consultas al
     * enviar**. El efecto secundario es el correcto además — las preferencias
     * quedan **por dispositivo**, así que el acudiente puede querer los avisos en
     * su teléfono y no en la tableta que usa el niño.
     */
    public function getTemas()
    {
        $user = User::fromToken();

        // Sin secreto no se deriva nada: devolver temas calculados con una clave
        // vacía haría que los dieciséis colegios compartieran el tema del alumno
        // 345, o sea que un acudiente de un colegio recibiría los avisos de un
        // alumno de otro. Es 503 y no 500 porque no es un fallo del código: es
        // una instalación a la que le falta el `APP_KEY`.
        if (! TemasDeNotificacion::hayComoDerivar()) {
            abort(503, 'Las notificaciones no están configuradas en este colegio.');
        }

        $alumnos = $this->alumnosDe($user);

        $temas = [];

        foreach ($alumnos as $alumno) {
            $temas[] = [
                'alumno_id' => (int) $alumno->id,
                'nombre' => trim($alumno->nombres.' '.$alumno->apellidos),
                'temas' => TemasDeNotificacion::todosLosDe((int) $alumno->id),
            ];
        }

        return [
            'alumnos' => $temas,
            // **Ya compuesto**, no la lista de nombres lógicos: el teléfono no
            // deriva ningún tema. Hasta el 26 ago 2026 aquí salían las dos cadenas
            // literales, iguales en los quince colegios y por tanto **el mismo tema
            // de Firebase para todos** — el proyecto es uno solo. Ver
            // `TemasDeNotificacion::delColegio()`.
            'colegio' => TemasDeNotificacion::todosLosDelColegio(),
        ];
    }

    /**
     * De qué alumnos puede recibir avisos quien pregunta.
     *
     * **Se exige matrícula viva**, y no es un detalle: sin ese filtro el
     * acudiente de un alumno que se fue del colegio hace tres años seguiría
     * recibiendo sus avisos. El `parentescos` no caduca solo.
     *
     * @return array<int, object>
     */
    private function alumnosDe(object $user): array
    {
        if ($user->tipo === 'Alumno') {
            return DB::select(
                'SELECT a.id, a.nombres, a.apellidos
                   FROM alumnos a
                   INNER JOIN matriculas m ON m.alumno_id = a.id AND m.deleted_at IS NULL
                        AND m.estado IN ("MATR", "ASIS", "PREM")
                  WHERE a.id = ? AND a.deleted_at IS NULL
                  GROUP BY a.id, a.nombres, a.apellidos',
                [$user->persona_id]
            );
        }

        if ($user->tipo === 'Acudiente') {
            return DB::select(
                'SELECT a.id, a.nombres, a.apellidos
                   FROM parentescos p
                   INNER JOIN alumnos a ON a.id = p.alumno_id AND a.deleted_at IS NULL
                   INNER JOIN matriculas m ON m.alumno_id = a.id AND m.deleted_at IS NULL
                        AND m.estado IN ("MATR", "ASIS", "PREM")
                  WHERE p.acudiente_id = ? AND p.deleted_at IS NULL
                  GROUP BY a.id, a.nombres, a.apellidos
                  ORDER BY a.apellidos, a.nombres',
                [$user->persona_id]
            );
        }

        return [];
    }
}
