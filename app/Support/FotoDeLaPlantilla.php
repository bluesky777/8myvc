<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * **Cómo quedó la plantilla la última vez que se propagó**, y qué ha cambiado desde entonces
 * (`myvc_front/PLAN-COSAS-PENDIENTES.md` §2.3). Tabla `plantilla_fotos`.
 *
 * - `tomar()` la llama `PlantillaNotasController::putSembrar` al terminar.
 * - `cambios()` compara la plantilla viva con la última foto, fila por fila y por `id`. Es lo
 *   que pinta la banda de `/plan-evaluacion` y lo que cuenta el pendiente `plantilla_sin_propagar`.
 * - `descartar()` devuelve la plantilla a la foto. **No toca ninguna asignatura**: las
 *   asignaturas ya tienen la plantilla de la foto, porque es la que se propagó.
 *
 * Sin foto, `cambios()` devuelve `null`: no se sabe, y no se inventa.
 */
class FotoDeLaPlantilla
{
    private const DE_UNIDAD = ['definicion', 'porcentaje', 'obligatoria', 'orden', 'nivel_educativo_id', 'materia_id'];

    private const DE_SUBUNIDAD = ['unidad_defec_id', 'definicion', 'porcentaje', 'nota_default', 'obligatoria', 'orden', 'inicia_at', 'finaliza_at'];

    public static function tomar(int $yearId, ?int $userId): void
    {
        $ahora = Reloj::ahoraTexto();

        DB::table('plantilla_fotos')->insert([
            'year_id' => $yearId,
            'foto' => json_encode(self::viva($yearId), JSON_UNESCAPED_UNICODE),
            'tomada_por' => $userId,
            'created_at' => $ahora,
            'updated_at' => $ahora,
        ]);
    }

    /** @return ?object `{id, created_at, tomada_por, quien, foto: {unidades, subunidades}}` */
    public static function ultima(int $yearId): ?object
    {
        $f = DB::selectOne('SELECT f.id, f.foto, f.created_at, f.tomada_por,
                COALESCE(NULLIF(TRIM(CONCAT(IFNULL(p.nombres, ""), " ", IFNULL(p.apellidos, ""))), ""), u.username) AS quien
            FROM plantilla_fotos f
            LEFT JOIN users u ON u.id = f.tomada_por
            LEFT JOIN profesores p ON p.user_id = u.id AND p.deleted_at IS NULL
            WHERE f.year_id = ? ORDER BY f.id DESC LIMIT 1', [$yearId]);

        if ($f === null) {
            return null;
        }

        $f->foto = json_decode($f->foto, true);

        return $f;
    }

    /** `15 de septiembre`. `created_at` se escribe con `Reloj`, así que ya es hora de Bogotá. */
    public static function cuando(object $foto): string
    {
        return Reloj::desdeTexto($foto->created_at)->locale('es')->isoFormat('D [de] MMMM');
    }

    /**
     * Lo que cambió desde la última foto, en frases para leer. `null` si nunca se tomó una.
     *
     * @return ?list<array{tipo: 'mas'|'menos'|'cambia', texto: string}>
     */
    public static function cambios(int $yearId): ?array
    {
        $foto = self::ultima($yearId);

        if ($foto === null) {
            return null;
        }

        $antes = $foto->foto;
        $hoy = self::viva($yearId);
        $salida = [];
        $orden = false;

        $nombreU = static fn (array $u) => '«'.$u['definicion'].'»';

        foreach ($hoy['unidades'] as $id => $u) {
            $a = $antes['unidades'][$id] ?? null;

            if ($a === null) {
                $salida[] = ['tipo' => 'mas', 'texto' => 'Unidad nueva '.$nombreU($u).' ('.(int) $u['porcentaje'].' %)'];

                continue;
            }

            if ($a['definicion'] !== $u['definicion']) {
                $salida[] = ['tipo' => 'cambia', 'texto' => $nombreU($a).' ahora se llama '.$nombreU($u)];
            }
            if ((int) $a['porcentaje'] !== (int) $u['porcentaje']) {
                $salida[] = ['tipo' => 'cambia', 'texto' => $nombreU($u).': '.(int) $a['porcentaje'].' % → '.(int) $u['porcentaje'].' %'];
            }
            if ((int) $a['obligatoria'] !== (int) $u['obligatoria']) {
                $salida[] = ['tipo' => 'cambia', 'texto' => $nombreU($u).((int) $u['obligatoria'] === 1 ? ' ahora es obligatoria' : ' ya no es obligatoria')];
            }
            if ($a['nivel_educativo_id'] != $u['nivel_educativo_id'] || $a['materia_id'] != $u['materia_id']) {
                $salida[] = ['tipo' => 'cambia', 'texto' => $nombreU($u).' cambió de asignaturas a las que va'];
            }
            $orden = $orden || $a['orden'] != $u['orden'];
        }

        foreach ($antes['unidades'] as $id => $a) {
            if (! isset($hoy['unidades'][$id])) {
                $salida[] = ['tipo' => 'menos', 'texto' => 'Se quitó la unidad '.$nombreU($a)];
            }
        }

        // La unidad de una subunidad, por su nombre de hoy o, si ya no existe, el de la foto.
        $deUnidad = static function ($id) use ($hoy, $antes, $nombreU) {
            $u = $hoy['unidades'][$id] ?? $antes['unidades'][$id] ?? null;

            return $u === null ? '' : ' en '.$nombreU($u);
        };

        foreach ($hoy['subunidades'] as $id => $s) {
            $a = $antes['subunidades'][$id] ?? null;
            $nombre = '«'.$s['definicion'].'»';

            if ($a === null) {
                // La de una unidad nueva ya la dice «Unidad nueva»: listarla sería ruido.
                if (isset($antes['unidades'][$s['unidad_defec_id']])) {
                    $salida[] = ['tipo' => 'mas', 'texto' => 'Subunidad nueva '.$nombre.$deUnidad($s['unidad_defec_id'])];
                }

                continue;
            }

            if ($a['unidad_defec_id'] != $s['unidad_defec_id']) {
                $salida[] = ['tipo' => 'cambia', 'texto' => $nombre.' pasó'.str_replace(' en ', ' de ', $deUnidad($a['unidad_defec_id'])).' a'.str_replace(' en ', ' ', $deUnidad($s['unidad_defec_id']))];
            }
            if ($a['definicion'] !== $s['definicion']) {
                $salida[] = ['tipo' => 'cambia', 'texto' => '«'.$a['definicion'].'» ahora se llama '.$nombre];
            }
            if ((int) $a['porcentaje'] !== (int) $s['porcentaje']) {
                $salida[] = ['tipo' => 'cambia', 'texto' => $nombre.$deUnidad($s['unidad_defec_id']).': '.(int) $a['porcentaje'].' % → '.(int) $s['porcentaje'].' %'];
            }
            if ((int) $a['obligatoria'] !== (int) $s['obligatoria'] || $a['nota_default'] != $s['nota_default']
                || $a['inicia_at'] != $s['inicia_at'] || $a['finaliza_at'] != $s['finaliza_at']) {
                $salida[] = ['tipo' => 'cambia', 'texto' => $nombre.$deUnidad($s['unidad_defec_id']).' cambió de ajustes'];
            }
            $orden = $orden || $a['orden'] != $s['orden'];
        }

        foreach ($antes['subunidades'] as $id => $a) {
            // La de una unidad quitada ya la dice «Se quitó la unidad».
            if (! isset($hoy['subunidades'][$id]) && isset($hoy['unidades'][$a['unidad_defec_id']])) {
                $salida[] = ['tipo' => 'menos', 'texto' => 'Se quitó la subunidad «'.$a['definicion'].'»'.str_replace(' en ', ' de ', $deUnidad($a['unidad_defec_id']))];
            }
        }

        if ($orden) {
            $salida[] = ['tipo' => 'cambia', 'texto' => 'Cambió el orden de las filas'];
        }

        return $salida;
    }

    /**
     * Devuelve la plantilla del año a la última foto. Las filas de la foto se reviven con su
     * mismo `id` —las asignaturas sembradas no guardan el id de la plantilla, pero la auditoría
     * sí— y las que no estaban se borran como borra la pantalla: `deleted_at`.
     *
     * @return ?array{restauradas: int, quitadas: int} `null` si no hay foto.
     */
    public static function descartar(int $yearId, ?int $userId): ?array
    {
        $foto = self::ultima($yearId);

        if ($foto === null) {
            return null;
        }

        $antes = $foto->foto;
        $hoy = self::viva($yearId);
        $ahora = Reloj::ahoraTexto();
        $cuenta = ['restauradas' => 0, 'quitadas' => 0];

        DB::transaction(function () use ($antes, $hoy, $yearId, $userId, $ahora, &$cuenta) {
            foreach ($antes['unidades'] as $id => $u) {
                if (($hoy['unidades'][$id] ?? null) === $u) {
                    continue;
                }
                DB::table('unidades_por_defecto')->updateOrInsert(['id' => (int) $id], array_merge($u, [
                    'year_id' => $yearId, 'deleted_at' => null, 'deleted_by' => null, 'updated_by' => $userId, 'updated_at' => $ahora,
                ]));
                $cuenta['restauradas']++;
            }

            foreach ($antes['subunidades'] as $id => $s) {
                if (($hoy['subunidades'][$id] ?? null) === $s) {
                    continue;
                }
                DB::table('subunidades_por_defecto')->updateOrInsert(['id' => (int) $id], array_merge($s, [
                    'deleted_at' => null, 'deleted_by' => null, 'updated_by' => $userId, 'updated_at' => $ahora,
                ]));
                $cuenta['restauradas']++;
            }

            $borrar = ['deleted_at' => $ahora, 'deleted_by' => $userId];

            $sobranS = array_keys(array_diff_key($hoy['subunidades'], $antes['subunidades']));
            if ($sobranS !== []) {
                $cuenta['quitadas'] += DB::table('subunidades_por_defecto')->whereIn('id', $sobranS)->update($borrar);
            }

            $sobranU = array_keys(array_diff_key($hoy['unidades'], $antes['unidades']));
            if ($sobranU !== []) {
                $cuenta['quitadas'] += DB::table('unidades_por_defecto')->whereIn('id', $sobranU)->update($borrar);
            }
        });

        return $cuenta;
    }

    /** @return array{unidades: array<int, array<string, mixed>>, subunidades: array<int, array<string, mixed>>} */
    private static function viva(int $yearId): array
    {
        $unidades = [];
        foreach (DB::select('SELECT id, '.implode(', ', self::DE_UNIDAD).' FROM unidades_por_defecto
                WHERE year_id = ? AND deleted_at IS NULL ORDER BY id', [$yearId]) as $u) {
            $fila = (array) $u;
            unset($fila['id']);
            $unidades[(int) $u->id] = $fila;
        }

        $subunidades = [];
        foreach (DB::select('SELECT s.id, s.'.implode(', s.', self::DE_SUBUNIDAD).' FROM subunidades_por_defecto s
                INNER JOIN unidades_por_defecto u ON u.id = s.unidad_defec_id AND u.deleted_at IS NULL AND u.year_id = ?
                WHERE s.deleted_at IS NULL ORDER BY s.id', [$yearId]) as $s) {
            $fila = (array) $s;
            unset($fila['id']);
            $subunidades[(int) $s->id] = $fila;
        }

        return ['unidades' => $unidades, 'subunidades' => $subunidades];
    }
}
