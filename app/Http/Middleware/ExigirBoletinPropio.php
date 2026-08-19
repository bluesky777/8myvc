<?php

namespace App\Http\Middleware;

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
 */
class ExigirBoletinPropio
{
    public function handle(Request $request, Closure $next)
    {
        $usuario = User::fromToken(false, $request);

        if ($usuario->tipo !== 'Alumno' && $usuario->tipo !== 'Acudiente') {
            return $next($request);
        }

        $alumnoId = $this->alumnoPedido($request);

        // Sin alumno concreto se está pidiendo el grupo entero. Es lo que hacen
        // las rutas `-group`, que ni siquiera aceptan el parámetro.
        if ($alumnoId === null) {
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
     * Dos formas, porque los endpoints de esta familia no se pusieron de acuerdo:
     * la lista `requested_alumnos` (boletines, bolfinales, notas actuales) y el
     * `alumno_id` suelto (certificados-persona).
     */
    private function alumnoPedido(Request $peticion): ?int
    {
        $pedidos = $peticion->input('requested_alumnos');

        if (is_array($pedidos)) {
            return count($pedidos) === 1 ? (int) ($pedidos[0]['alumno_id'] ?? 0) : null;
        }

        $suelto = $peticion->input('alumno_id');

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
        $historial = DB::select(
            'SELECT id FROM historiales WHERE user_id=? and deleted_at is null order by id desc limit 1',
            [$usuario->user_id]
        );

        DB::insert(
            'INSERT INTO bitacoras (created_by, historial_id, affected_user_id, affected_person_type,
                affected_element_type, created_at)
             VALUES (?, ?, ?, "Al", ?, ?)',
            [
                $usuario->user_id,
                $historial[0]->id ?? null,
                $alumnoId,
                $tipo,
                now(),
            ]
        );
    }
}
