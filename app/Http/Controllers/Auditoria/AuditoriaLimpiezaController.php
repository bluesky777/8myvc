<?php

namespace App\Http\Controllers\Auditoria;

use App\Http\Controllers\Concerns\ResuelveElUsuario;
use App\Http\Controllers\Controller;
use App\Services\Auditoria;
use App\Support\Autoriza;
use App\Support\LimpiezaDeAuditoria;
use App\Support\Reloj;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * La pestaña «Limpieza» de `/auditoria` (contrato 4): borrar el historial hasta una
 * fecha. **Borra datos de producción**, así que todo se decide aquí y no en el cliente:
 *
 * - sólo el superusuario (`Autoriza::esSuperusuario`), no `veAuditoria`;
 * - la fecha es ayer como mucho: hoy o una futura, 422;
 * - el `POST` pide la palabra `BORRAR` y vuelve a calcular la previa él mismo;
 * - la limpieza deja su fila en `auditoria_limpiezas` y su línea `limpieza` en
 *   `auditoria`, que ninguna limpieza borra.
 *
 * El cómo —qué se borra de cada tabla, en tandas— está en `Support\LimpiezaDeAuditoria`.
 */
class AuditoriaLimpiezaController extends Controller
{
    use ResuelveElUsuario;

    public const CONFIRMACION = 'BORRAR';

    /** `GET auditoria/limpieza/previa?hasta=&incluir=` — sólo lectura. */
    public function getPrevia(Request $peticion): JsonResponse
    {
        $this->exigirSuperusuario();

        [$hasta, $incluir, $error] = $this->entrada($peticion->query('hasta'), $peticion->query('incluir'), true);
        if ($error !== null) {
            return $error;
        }

        return response()->json(LimpiezaDeAuditoria::previa($hasta, $incluir));
    }

    /** `POST auditoria/limpieza` con `{hasta, incluir, confirmacion: "BORRAR"}`. */
    public function postLimpiar(Request $peticion): JsonResponse
    {
        $this->exigirSuperusuario();

        [$hasta, $incluir, $error] = $this->entrada($peticion->input('hasta'), $peticion->input('incluir'), false);
        if ($error !== null) {
            return $error;
        }
        if ($peticion->input('confirmacion') !== self::CONFIRMACION) {
            return response()->json(['message' => 'Para borrar hay que escribir '.self::CONFIRMACION.'.'], 422);
        }

        @set_time_limit(0);
        ignore_user_abort(true);

        $previa = LimpiezaDeAuditoria::previa($hasta, $incluir);
        $usuario = $this->user;
        $inicio = Reloj::ahoraTexto();

        $id = (int) DB::table('auditoria_limpiezas')->insertGetId([
            'user_id' => (int) $usuario->user_id,
            'actor_nombre' => mb_substr(trim(($usuario->nombres ?? '').' '.($usuario->apellidos ?? '')) ?: (string) ($usuario->username ?? ''), 0, 120),
            'hasta' => $hasta,
            'incluir' => implode(',', $incluir),
            'previa_auditoria' => $previa['cuenta']['auditoria'],
            'previa_importaciones' => $previa['cuenta']['importaciones'],
            'previa_bitacoras' => $previa['cuenta']['bitacoras'],
            'previa_historiales' => $previa['cuenta']['historiales'],
            'inicio' => $inicio,
            'estado' => LimpiezaDeAuditoria::EN_PROCESO,
        ]);

        // La línea va ANTES de borrar: si la petición muere a mitad, la constancia ya está.
        // Su fecha es de hoy y su entidad es `limpieza`: ninguna limpieza la alcanza.
        $total = array_sum($previa['cuenta']);
        Auditoria::registrar()->borrar(LimpiezaDeAuditoria::ENTIDAD, $id)
            ->a(['hasta' => $hasta, 'incluir' => $incluir, 'previa' => $previa['cuenta']])
            ->resumen(mb_substr("Borró el historial hasta el $hasta (".implode(', ', $incluir)."): $total filas previstas", 0, 255))
            ->guardar();

        try {
            $borrados = LimpiezaDeAuditoria::borrar($hasta, $incluir, $id);
        } catch (Throwable $e) {
            DB::table('auditoria_limpiezas')->where('id', $id)->update([
                'estado' => LimpiezaDeAuditoria::CORTADA,
                'fin' => Reloj::ahoraTexto(),
                'error' => mb_substr($e->getMessage(), 0, 2000),
            ]);
            report($e);

            // 200 y `estado: cortada`, no 500: la pantalla enseña lo que sí se borró.
            return response()->json([
                'message' => 'La limpieza se cortó a mitad. Lo ya borrado queda borrado; vuelve a lanzarla con la misma fecha para terminarla.',
                ...$this->resultado($id),
            ]);
        }

        DB::table('auditoria_limpiezas')->where('id', $id)->update([
            'estado' => LimpiezaDeAuditoria::TERMINADA,
            'fin' => Reloj::ahoraTexto(),
        ]);

        return response()->json(['cuenta' => $borrados] + $this->resultado($id));
    }

    /** `GET auditoria/limpieza/historial` — las limpiezas hechas. */
    public function getHistorial(): JsonResponse
    {
        $this->exigirSuperusuario();

        return response()->json(['filas' => LimpiezaDeAuditoria::historial()]);
    }

    private function exigirSuperusuario(): void
    {
        Autoriza::exigir(
            Autoriza::esSuperusuario($this->user),
            'Limpiar el historial es sólo de un superadministrador.'
        );
    }

    /** @return array<string, mixed> la fila de la limpieza, con la forma del historial */
    private function resultado(int $id): array
    {
        foreach (LimpiezaDeAuditoria::historial() as $fila) {
            if ($fila['id'] === $id) {
                return $fila;
            }
        }

        return ['id' => $id];
    }

    /**
     * La fecha y las casillas, validadas. `incluir` llega como lista (`POST`) o separada
     * por comas (`GET`); en la previa, si no viene, son las marcadas de serie.
     *
     * @return array{0: string, 1: list<string>, 2: JsonResponse|null}
     */
    private function entrada(mixed $hasta, mixed $incluir, bool $porDefecto): array
    {
        $mal = fn (string $m) => ['', [], response()->json(['message' => $m], 422)];

        if (! is_string($hasta) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $hasta, $m) !== 1
            || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return $mal("La fecha 'hasta' tiene que ser AAAA-MM-DD.");
        }
        $ayer = LimpiezaDeAuditoria::ayer();
        if ($hasta > $ayer) {
            return $mal("La fecha 'hasta' puede ser, como mucho, ayer ($ayer).");
        }

        if (($incluir === null || $incluir === '') && $porDefecto) {
            $incluir = LimpiezaDeAuditoria::POR_DEFECTO;
        }
        if (is_string($incluir)) {
            $incluir = array_map('trim', explode(',', $incluir));
        }
        if (! is_array($incluir) || $incluir === [] || array_filter($incluir, fn ($t) => ! is_string($t)) !== []) {
            return $mal("Hay que elegir qué borrar: 'incluir' es una lista de ".implode(', ', LimpiezaDeAuditoria::TABLAS).'.');
        }
        $desconocidas = array_diff($incluir, LimpiezaDeAuditoria::TABLAS);
        if ($desconocidas !== []) {
            return $mal("'incluir' no conoce: ".implode(', ', $desconocidas).'.');
        }

        // En el orden de las casillas, sin repetidos.
        return [$hasta, array_values(array_intersect(LimpiezaDeAuditoria::TABLAS, $incluir)), null];
    }
}
