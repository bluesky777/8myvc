<?php

namespace App\Services;

use App\Support\EscalaDeNotas;
use App\Support\NombreDelAlumno;
use App\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ESCRIBIR NOTAS DE LA PLANILLA: permiso, escala, una transacción con bitácora y auditoría por nota,
 * y la definitiva recalculada una vez por par asignatura × periodo.
 *
 * Es la segunda mitad de `NotasController::putLote` sacada a un servicio **sin cambiar nada** (el
 * contrato de `notas/lote` sigue en el controlador, que arma el lote y la respuesta), para que el
 * módulo de actividades (`App\Services\Act\Planilla`, contrato §2.8) lleve la nota de una tarea o de
 * un cuestionario a la planilla por el mismo camino y deje el mismo rastro que teclearla.
 *
 * Cada elemento de `$aEscribir` es `['id' => nota_id, 'valor' => int|null, 'destino' => destino()]`.
 */
class EscrituraDeNotas
{
    /**
     * La nota con su asignatura y su periodo, o `null` si no existe o su indicador ya no está. El
     * mismo camino que usa el recalculador: la nota no sabe de qué asignatura ni de qué periodo es
     * —cuelga de la subunidad y ésa de la unidad—, y hacen falta las dos para agrupar el recálculo.
     */
    public static function destino(int $notaId): ?object
    {
        return DB::selectOne(
            'SELECT n.id, n.nota, n.alumno_id, u.asignatura_id, u.periodo_id
			   FROM notas n
			   INNER JOIN subunidades s ON s.id = n.subunidad_id AND s.deleted_at IS NULL
			   INNER JOIN unidades u ON u.id = s.unidad_id AND u.deleted_at IS NULL
			  WHERE n.id = ? AND n.deleted_at IS NULL',
            [$notaId]
        );
    }

    /**
     * Del permiso al recálculo. Devuelve cuántas se guardaron, las fallidas (las que llegaron más las
     * que no caben en la escala) y las que se escribieron de verdad.
     *
     * @param  list<array{id:int, valor:mixed, destino:object}>  $aEscribir
     * @param  list<array{id:?int, motivo:string}>  $fallidas
     * @return array{guardadas:int, fallidas:list<array>, escritas:list<array>}
     */
    public static function guardar(array $aEscribir, array $fallidas, object $user, Carbon $now, bool $conPermiso = true): array
    {
        if ($aEscribir === []) {
            return ['guardadas' => 0, 'fallidas' => $fallidas, 'escritas' => []];
        }

        $periodos = [];
        $pares = [];

        foreach ($aEscribir as $fila) {
            $periodos[(int) $fila['destino']->periodo_id] = true;
            $pares[(int) $fila['destino']->asignatura_id.':'.(int) $fila['destino']->periodo_id] = [
                (int) $fila['destino']->asignatura_id,
                (int) $fila['destino']->periodo_id,
            ];
        }

        // Antes de la primera escritura, y con los ids **únicos**: ver la nota de
        // arriba sobre `count($filas) === count($ids)`.
        // `$conPermiso = false` sólo lo pide una escritura automática (la nota de un cuestionario
        // que envía el alumno, §2.8 del contrato de actividades): quien la desencadena no es quien
        // tiene el permiso, y lo que se escribe lo decidió el docente al publicar.
        if ($conPermiso) {
            User::pueden_editar_notas($user, array_keys($periodos), array_map(fn ($par) => $par[0], array_values($pares)));
        }

        // La escala, y **después del permiso, no antes**. Ponerla en el bucle de
        // arriba parecía natural —está al lado de las otras dos validaciones de
        // forma— y era un fallo de verdad: con un periodo cerrado, las notas caían
        // en `fallidas` y la respuesta salía **200 con la lista** en vez del 400
        // del guard. O sea que un dato fuera de escala tapaba una respuesta de
        // autorización. Lo cazó `test_con_el_periodo_cerrado_el_lote_no_escribe_nada`,
        // que ya llevaba escrito «el permiso se está comprobando tarde».
        //
        // La regla, que vale para el resto de la fase 4: **la forma se valida
        // antes del permiso sólo cuando no depende de datos; lo que mira la base
        // va después.** Ver 18 §4.5.1.
        $conEscala = [];

        foreach ($aEscribir as $fila) {
            $noCabe = EscalaDeNotas::motivoSiNoCabe($fila['valor'], (int) $fila['destino']->periodo_id);

            if ($noCabe !== null) {
                $fallidas[] = ['id' => $fila['id'], 'motivo' => $noCabe];

                continue;
            }

            $conEscala[] = $fila;
        }

        $aEscribir = $conEscala;

        // Y otra vez el corte, porque la escala puede haberse llevado el lote
        // entero: sin esto se abriría una transacción para escribir cero notas y
        // se recalcularía una definitiva que nadie ha tocado.
        if ($aEscribir === []) {
            return ['guardadas' => 0, 'fallidas' => $fallidas, 'escritas' => []];
        }

        // El ingreso sale del token (fase 2 de 18-auditoria.md), y con él se va una
        // consulta por lote.
        $historialId = self::historialDelToken($user);

        // Los nombres de las notas del lote, **en una consulta y fuera de la
        // transacción**: dentro del bucle `de()` ya no consulta. Fuera y no dentro
        // porque es una lectura que no necesita estar en la transacción, y meterla
        // alargaría lo que la transacción tiene abierto sin ninguna ganancia.
        NombreDelAlumno::deVarios(array_map(fn ($f) => $f['destino']->alumno_id, $aEscribir));

        $guardadas = DB::transaction(function () use ($aEscribir, $user, $now, $historialId) {
            $hechas = 0;

            foreach ($aEscribir as $fila) {
                DB::update(
                    'UPDATE notas SET nota=?, updated_by=?, updated_at=? WHERE id=?',
                    [$fila['valor'], $user->user_id, $now, $fila['id']]
                );

                self::bitacora($user, $historialId, $fila['destino']->alumno_id, $fila['id'], $fila['valor'], $fila['destino']->nota, $now);

                // El rastro nuevo, al lado del viejo (18 §4), y **dentro de la
                // transacción del lote**: si el lote se deshace, las líneas se
                // deshacen con él. Es la propiedad que `Auditoria` tiene por no
                // abrir transacción propia, y la que hoy le falta a `putUpdate`.
                //
                // Una línea por nota y no una por lote: el lote es un detalle del
                // transporte —el front manda una petición por rejilla—, y la
                // pregunta que la tabla contesta es «quién tocó ESTA nota».
                $alumnoDeLaLinea = $fila['destino']->alumno_id === null ? null : (int) $fila['destino']->alumno_id;

                // Sin línea si la nota no cambió: ver `mismaNota`.
                if (! self::mismaNota($fila['destino']->nota, $fila['valor'])) {
                    Auditoria::registrar()
                        ->editar('nota', (int) $fila['id'])
                        ->deAlumno($alumnoDeLaLinea, NombreDelAlumno::de($alumnoDeLaLinea))
                        ->en(periodo: (int) $fila['destino']->periodo_id)
                        ->de($fila['destino']->nota)
                        ->a($fila['valor'])
                        ->guardar();
                }

                $hechas++;
            }

            return $hechas;
        });

        // Y **una sola vez por par**, con la transacción de las notas ya cerrada.
        // Sin `soloAlumno` a propósito: el lote toca a varios alumnos del mismo
        // grupo y `calcular()` los agrega a todos en la misma consulta, así que
        // acotar por alumno sería pedir esa misma agregación una vez por cada uno.
        foreach ($pares as $par) {
            DefinitivasDeAsignatura::recalcular($par[0], $par[1], $user->user_id);
        }

        return ['guardadas' => $guardadas, 'fallidas' => $fallidas, 'escritas' => $aEscribir];
    }

    /** La línea de `bitacoras` que dejan `putUpdate`, `putLote` y nivelar, para que el historial de la app la lea igual. */
    public static function bitacora(object $user, ?int $historialId, $alumnoId, int $notaId, $nueva, $vieja, $now): void
    {
        DB::insert(
            'INSERT INTO bitacoras (created_by, historial_id, affected_user_id, affected_person_type,
				affected_element_type, affected_element_id, affected_element_new_value_int,
				affected_element_old_value_int, created_at)
			 VALUES (?, ?, ?, "Al", "Nota", ?, ?, ?, ?)',
            [$user->user_id, $historialId, $alumnoId, $notaId, $nueva, $vieja, $now]
        );
    }

    /** El ingreso del token (fase 2 de 18-auditoria.md), o `null` si el token es anterior. */
    public static function historialDelToken(object $user): ?int
    {
        return isset($user->historial_id) && is_numeric($user->historial_id) ? (int) $user->historial_id : null;
    }

    /**
     * Si dos valores de nota son la misma nota: `35`, `'35'` y `35.0` sí; `null` sólo con
     * `null`. Un guardado que no cambia nada no deja línea de auditoría.
     */
    public static function mismaNota(mixed $antes, mixed $despues): bool
    {
        $vacio = fn ($v) => $v === null || $v === '';
        if ($vacio($antes) || $vacio($despues)) {
            return $vacio($antes) && $vacio($despues);
        }

        return is_numeric($antes) && is_numeric($despues)
            ? (float) $antes === (float) $despues
            : (string) $antes === (string) $despues;
    }
}
