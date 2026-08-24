<?php

namespace App\Http\Middleware;

use App\Services\Auditoria;
use App\Support\NombreDelAlumno;
use App\Support\Reloj;
use App\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Un alumno solo puede pedir SU boletín; un acudiente, solo el de sus acudidos.
 *
 * **Esta comprobación estaba escrita desde hacía años y no se ejecutaba nunca.**
 * Vivía en el constructor de `BoletinesController`, dentro de un
 * `try { ... } catch (\Throwable $th) { return 'Error'; }`. `abort()` lanza una
 * HttpException, que es un Throwable, así que el catch se la tragaba entera; y
 * un `return` en un constructor no detiene nada. Comprobado golpeando el
 * endpoint: con token de alumno, `PUT api/boletines/detailed-notas/{grupo}`
 * pidiendo el de un compañero respondía 200 con el boletín completo. Con token
 * de acudiente, igual, y sin mirar el paz y salvo.
 *
 * `Boletines2Controller` y `Boletines3Controller` son copias del primero con
 * otra maqueta y **no tenían ni la comprobación escrita**, así que arreglar solo
 * el primero habría dejado dos puertas abiertas al mismo dato.
 *
 * Por eso está aquí y no en un método: son once rutas en cuatro controladores, y
 * en el archivo de rutas se ve cuáles.
 *
 * Los `bitacoras` que insertaba el código original se conservan: son el rastro
 * que mira el colegio cuando alguien reclama.
 *
 * **El modo `sin-paz-y-salvo` (19 ago 2026).** La misma comprobación de
 * propiedad hacía falta en dos sitios donde retener por deuda NO aplica, y por
 * eso es un parámetro y no otro middleware:
 *
 * - `GET api/notas/alumno/{alumno_id?}`, donde un alumno podía leer las notas de
 *   cualquier compañero cambiando el número de la URL. Aquí el id llega por la
 *   **URL**, no en el cuerpo.
 *
 *   Sobre el paz y salvo conviene saber esto antes de decidir: **`myvc_front` ya
 *   lo aplica, y solo en el navegador.** `NotasAlumnoCtrl.seleccionarAcudido()`
 *   corta con un «Debe estar a paz y salvo» antes de llamar. O sea que la regla
 *   ya existe como intención del producto y hoy la sostiene únicamente el
 *   cliente, que es la mitad que se puede saltar. Si el colegio la confirma, es
 *   quitarle el parámetro a la ruta y borrar la comprobación del frontend.
 *
 * - `PUT api/matriculas/prematricular`, la única ESCRITURA de matrículas abierta
 *   a Alumno y Acudiente —la prematrícula del año siguiente la hace la familia
 *   desde su cuenta— y que no miraba de quién era el `alumno_id` del cuerpo. Con
 *   un token de alumno se le cambiaba el estado y el grupo a cualquier
 *   compañero: el mismo agujero que el de las notas, pero escribiendo. Joseth
 *   confirmó la regla el 19 ago 2026: **un acudiente solo puede prematricular a
 *   sus acudidos.**
 *
 *   Sin paz y salvo a propósito: retener el boletín de quien debe es una cosa, e
 *   impedirle matricularse el año siguiente es otra, y esa nadie la ha pedido.
 */
class ExigirBoletinPropio
{
    /**
     * @param  string  $modo  `boletin` (por defecto) o `sin-paz-y-salvo`. Ver la nota de la clase.
     */
    public function handle(Request $request, Closure $next, string $modo = 'boletin')
    {
        $usuario = User::fromToken(false, $request);

        if ($usuario->tipo !== 'Alumno' && $usuario->tipo !== 'Acudiente') {
            return $next($request);
        }

        $alumnoId = $this->alumnoPedido($request);

        if ($alumnoId === null) {
            // Sin alumno concreto no hay nada que proteger todavía: en
            // `notas/alumno` significa «las mías» —el controlador lo resuelve del
            // token para un Alumno y responde 400 a los demás— y en
            // `prematricular` la petición no llega a escribir, porque
            // `matriculas.alumno_id` es NOT NULL.
            if ($modo === 'sin-paz-y-salvo') {
                return $next($request);
            }

            // En `boletin`, sin alumno concreto se está pidiendo el grupo
            // entero. Es lo que hacen las rutas `-group`, que ni siquiera
            // aceptan el parámetro.
            $this->anotar($usuario, $usuario->tipo === 'Acudiente'
                ? 'AcudienteVerVariosBoletines' : 'AlumnoVerVariosBoletines');

            abort(403, 'Pedis más de lo que debes');
        }

        if ($usuario->tipo === 'Alumno') {
            if ($alumnoId !== (int) $usuario->persona_id) {
                $this->anotar($usuario, 'AlumnoVerBoletin', $alumnoId);

                abort(403, 'No puedes ver el de otros');
            }

            return $next($request);
        }

        $parentesco = DB::select(
            'SELECT id FROM parentescos WHERE alumno_id=? and acudiente_id=? and deleted_at is null',
            [$alumnoId, $usuario->persona_id]
        );

        if (count($parentesco) === 0) {
            $this->anotar($usuario, 'AcudienteVerBoletin', $alumnoId);

            abort(403, 'No es acudiente de este alumno. Lo siento.');
        }

        if ($modo === 'sin-paz-y-salvo') {
            return $next($request);
        }

        $alumno = DB::select('SELECT pazysalvo FROM alumnos WHERE id=? and deleted_at is null', [$alumnoId]);

        if (count($alumno) === 0 || ! $alumno[0]->pazysalvo) {
            $this->anotar($usuario, 'AcudienteVerBoletinSinPagar', $alumnoId);

            abort(403, 'No está a paz y salvo. Lo siento.');
        }

        return $next($request);
    }

    /**
     * El alumno del que se pide el informe, o null si se está pidiendo más de uno.
     *
     * Tres formas, porque los endpoints de esta familia no se pusieron de
     * acuerdo: la lista `requested_alumnos` (boletines, bolfinales, notas
     * actuales), el `alumno_id` suelto (certificados-persona, prematricular) y
     * el segmento de la URL (`notas/alumno/{alumno_id?}`).
     */
    private function alumnoPedido(Request $peticion): ?int
    {
        $pedidos = $peticion->input('requested_alumnos');

        if (is_array($pedidos)) {
            return count($pedidos) === 1 ? (int) ($pedidos[0]['alumno_id'] ?? 0) : null;
        }

        $suelto = $peticion->input('alumno_id') ?? $peticion->route('alumno_id');

        return $suelto === null || $suelto === '' ? null : (int) $suelto;
    }

    /**
     * Deja constancia del intento en `bitacoras`.
     *
     * `historial_id` puede quedar en null: el código original lo sacaba de
     * `DB::select(...)[0]` y reventaba si el usuario no tenía ninguna sesión
     * registrada — lo tapaba el mismo catch que anulaba la comprobación. Que
     * falte el historial no puede impedir que se rechace la petición.
     */
    private function anotar(object $usuario, string $tipo, ?int $alumnoId = null): void
    {
        // **El ingreso sale del token, no del último login de esta persona.**
        // Era `order by id desc limit 1` sobre `historiales`, que con el refresco
        // viviendo catorce días puede señalar un ingreso de hace meses (fase 2 de
        // 18-auditoria.md). Ahora viene en el contexto que ya resolvió
        // `auth.token`, y **no cuesta ninguna consulta**.
        //
        // Null si el token es anterior a la migración: NULL dice «no se sabe», y
        // la adivinanza decía «fue ése» y se equivocaba sin avisar.
        $historialId = isset($usuario->historial_id) && is_numeric($usuario->historial_id)
            ? (int) $usuario->historial_id
            : null;

        DB::insert(
            'INSERT INTO bitacoras (created_by, historial_id, affected_user_id, affected_person_type,
                affected_element_type, created_at)
             VALUES (?, ?, ?, "Al", ?, ?)',
            [
                $usuario->user_id,
                $historialId,
                $alumnoId,
                $tipo,
                // Ver el mismo comentario en ExigirPersonaPropia: `now()` es UTC y
                // esta columna la escriben otros siete en Bogotá. 18 §1.1.
                Reloj::ahora(),
            ]
        );

        /*
         * Igual que en `ExigirPersonaPropia`: se añade el rastro nuevo y **no se
         * retira el viejo**.
         *
         * `$alumnoId` puede ser null —las dos rutas `-group` piden el grupo
         * entero y no nombran a nadie—, y ahí `deAlumno(null)` es la respuesta
         * honesta: se pidió de más, no se pidió de alguien.
         */
        $alumnoDeLaLinea = $alumnoId;

        Auditoria::registrar()
            ->denegado('boletin', $alumnoId)
            ->deAlumno($alumnoDeLaLinea, NombreDelAlumno::de($alumnoDeLaLinea))
            ->resumen($tipo)
            ->guardar();
    }
}
