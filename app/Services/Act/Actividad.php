<?php

namespace App\Services\Act;

use App\Support\Autoriza;
use App\Support\EscalaDeNotas;
use App\Support\Reloj;
use Illuminate\Support\Facades\DB;

/**
 * LO COMÚN DE UNA ACTIVIDAD NUEVA: cargarla, su estado efectivo y quién manda en ella.
 *
 * Contrato: `myvc_front/docs/funciones/ACTIVIDADES-CONTRATO.md` §2.1 y §2.2.
 *
 * **Una actividad nueva es la que tiene `modo`.** Las del módulo viejo (`modo IS NULL`) no existen
 * para `act/*`: 404, igual que una borrada. Así ninguna ruta nueva puede tocar lo viejo.
 */
class Actividad
{
    public const MODOS = ['tarea', 'cuestionario', 'encuesta'];

    /** La actividad nueva, o 404. */
    public static function cargar($id): object
    {
        $act = DB::selectOne(
            'SELECT * FROM ws_actividades WHERE id = ? AND modo IS NOT NULL AND deleted_at IS NULL',
            [(int) $id]
        );

        if (! $act) {
            abort(404, 'Esa actividad no existe.');
        }

        return $act;
    }

    /**
     * El estado que ve todo el mundo (§2.1). Se calcula al leer: **no hay cron que cambie
     * `estado`**, así que una actividad publicada con `cierra_at` vencido está cerrada aunque la
     * columna siga diciendo `publicada`.
     *
     * Las horas se comparan como texto `AAAA-MM-DD HH:MM:SS` en hora de Colombia, que es como las
     * escribe la API (ver `Reloj`); el orden del texto es el del tiempo.
     */
    public static function estado(object $act, ?string $ahora = null): string
    {
        $ahora ??= Reloj::ahora()->format('Y-m-d H:i:s');

        switch ($act->estado) {
            case 'borrador':
            case 'por_aprobar':
                return $act->estado;
            case 'cerrada':
                return 'cerrada';
        }

        if ($act->publica_at !== null && $act->publica_at > $ahora) {
            return 'programada';
        }

        if ($act->cierra_at !== null && $act->cierra_at <= $ahora) {
            return 'cerrada';
        }

        return 'abierta';
    }

    /**
     * La entrega tardía (§2.1): una tarea que acepta entregas tras `cierra_at`, vencida por la
     * fecha y **no cerrada a mano**. Es la única forma de responder fuera de `abierta`.
     */
    public static function aceptaTarde(object $act): bool
    {
        return $act->modo === 'tarea'
            && (bool) $act->recibir_tarde
            && $act->estado === 'publicada'
            && self::estado($act) === 'cerrada';
    }

    /** Dueño = quien la creó; en lo nuevo `created_by` guarda `users.id` (§1.1). */
    public static function esDueno(object $act, object $user): bool
    {
        return (int) $act->created_by === (int) ($user->user_id ?? 0);
    }

    public static function esDirectivo(object $user): bool
    {
        return Autoriza::puedeAprobarActividades($user);
    }

    /** Todo lo que edita, publica, cierra o califica exige ser el dueño (§2.2). */
    public static function exigirDueno(object $act, object $user): void
    {
        if (! self::esDueno($act, $user)) {
            abort(403, 'Esta actividad no es tuya.');
        }
    }

    /** Los directivos LEEN resultados, entregas y «faltan» de cualquiera, pero no editan. */
    public static function exigirDuenoODirectivo(object $act, object $user): void
    {
        if (! self::esDueno($act, $user) && ! self::esDirectivo($user)) {
            abort(403, 'Esta actividad no es tuya.');
        }
    }

    /** Hojas enviadas: deciden qué se puede editar de una actividad publicada (§2.9). */
    public static function hojasEnviadas(int $actividadId): int
    {
        return (int) DB::selectOne(
            'SELECT COUNT(*) AS n FROM ws_actividades_resueltas
              WHERE actividad_id = ? AND terminado = 1 AND deleted_at IS NULL',
            [$actividadId]
        )->n;
    }

    /** Las que tienen algo enviado: hojas terminadas o entregas entregadas. */
    public static function tieneRespuestas(int $actividadId): bool
    {
        if (self::hojasEnviadas($actividadId) > 0) {
            return true;
        }

        return DB::selectOne(
            'SELECT 1 AS si FROM ws_entregas WHERE actividad_id = ? AND entregada_at IS NOT NULL LIMIT 1',
            [$actividadId]
        ) !== null;
    }

    /**
     * Si los resultados de la encuesta ya están compartidos (§2.10): cerrada —a mano o por la
     * fecha, que no hay cron— y con `comparte_resultados` distinto de `no`. A quién le llegan lo
     * decide quien pregunta: `respondieron` exige hoja terminada; `todos`, ser destinatario.
     */
    public static function compartidos(object $act): bool
    {
        return $act->modo === 'encuesta'
            && in_array($act->comparte_resultados, ['respondieron', 'todos'], true)
            && self::estado($act) === 'cerrada';
    }

    /** `AAAA-MM-DD HH:MM:SS` de ahora, en hora de Colombia. */
    public static function ahora(): string
    {
        return Reloj::ahora()->format('Y-m-d H:i:s');
    }

    /** La nota más alta de la escala del año; 100 si el año no tiene escala. */
    public static function maximoDeLaEscala(int $yearId): int
    {
        return EscalaDeNotas::maximo($yearId) ?? 100;
    }
}
