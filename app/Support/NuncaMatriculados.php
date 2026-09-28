<?php

namespace App\Support;

use App\Services\Auditoria;
use Illuminate\Support\Facades\DB;

/**
 * Los alumnos con ficha y SIN NINGUNA matrícula en la historia del colegio, y cómo se limpian
 * *(27 sep 2026)*.
 *
 * **DE DÓNDE SALEN, medido en la copia de lalvirtual del 25 sep 2026** (`lalvirtual_25sep_1851`):
 * 28 fichas, y 24 tienen al lado otra ficha de la misma persona que SÍ está matriculada. No son
 * alumnos que vayan a llegar: son altas que fallaron a medias. `AlumnosController::postStore` guarda
 * el alumno y su usuario, después la matrícula, sin transacción; si algo revienta en medio, el
 * `catch` contesta «422 Datos incorrectos» con la ficha ya creada, y quien la daba de alta lo
 * intenta otra vez:
 *
 *     894..898  JOSEPH JATNIEL CEPEDA ALARCÓN   usuarios 1116873883, 11168738831..34   (2021-11-12)
 *     913       JOSEPH JATNIEL CEPEDA ALARCON   CEPEDAALARCON, matriculado             (2021-11-26)
 *
 * Cada reintento le suma un número al username (`username_no_repetido`), y así se reconocen.
 * No es cosa de 2021: las cuatro ALEXANDRA AROCA LOZANO (1139..1142) son del 18 nov 2025.
 *
 * **POR QUÉ BORRAR AQUÍ ES SEGURO, y cuándo no.** Una ficha sin matrícula no tiene notas, ni
 * asistencias, ni disciplina: todo eso cuelga de un grupo. Pero puede tener otras cosas —los
 * boletines de otros colegios (`anos_externos`), acudientes, comentarios—, y ésas no se tiran:
 * se unen a la otra ficha con `FusionDeAlumnos`, que las mueve. Así que la regla es una sola y
 * la pregunta la hace la base, no una lista: **se puede borrar si `FusionDeAlumnos::loQueCuelga`
 * sale vacío**. Y se vuelve a preguntar dentro de la transacción del borrado, porque entre que
 * la pantalla cargó y que alguien pulsó pudo cambiar.
 *
 * `bitacoras.affected_user_id` NO cuenta, aunque se llame así: en las notas viejas guarda el id
 * del ALUMNO, no el del usuario (la ficha 647 «tiene» 2.210 bitácoras de notas que son del alumno
 * 741). Contarlas habría dejado sin borrar la mitad de los 28 por un dato que no es suyo.
 */
class NuncaMatriculados
{
    /** @return list<array<string,mixed>> */
    public static function listar(): array
    {
        $filas = DB::select('SELECT a.id AS alumno_id, a.nombres, a.apellidos, a.sexo, a.documento, a.user_id,
                u.username, u.is_active AS usuario_activo, a.created_at AS creado, cu.username AS creado_por,
                IFNULL(i.nombre, IF(a.sexo = "F", "default_female.png", "default_male.png")) AS foto_nombre,
                (SELECT COUNT(*) FROM anos_externos ae WHERE ae.alumno_id = a.id AND ae.deleted_at IS NULL) AS anos_externos,
                (SELECT MAX(ae.year) FROM anos_externos ae WHERE ae.alumno_id = a.id AND ae.deleted_at IS NULL) AS ultimo_ano,
                (SELECT GROUP_CONCAT(DISTINCT ae.colegio_nombre ORDER BY ae.year DESC SEPARATOR " · ")
                   FROM anos_externos ae WHERE ae.alumno_id = a.id AND ae.deleted_at IS NULL) AS colegios,
                (SELECT COUNT(*) FROM historiales h WHERE h.user_id = a.user_id) AS sesiones,
                (SELECT MAX(h.created_at) FROM historiales h WHERE h.user_id = a.user_id) AS ultima_sesion
            FROM alumnos a
            LEFT JOIN users u ON u.id = a.user_id
            LEFT JOIN users cu ON cu.id = a.created_by
            LEFT JOIN images i ON i.id = a.foto_id AND i.deleted_at IS NULL
            WHERE a.deleted_at IS NULL
              AND NOT EXISTS (SELECT 1 FROM matriculas m WHERE m.alumno_id = a.id)
            ORDER BY a.created_at DESC, a.id DESC');

        $gemelosDe = [];
        $todos = [];
        foreach ($filas as $f) {
            $gemelosDe[(int) $f->alumno_id] = self::gemelos($f);
            $todos[] = (int) $f->alumno_id;
            foreach ($gemelosDe[(int) $f->alumno_id] as $g) {
                $todos[] = $g['alumno_id'];
            }
        }
        $fichas = DuplicadosDeAlumnos::fichas(array_values(array_unique($todos)));

        return array_map(static function ($f) use ($gemelosDe, $fichas) {
            $id = (int) $f->alumno_id;
            $cuelga = FusionDeAlumnos::loQueCuelga($id);

            return [
                ...(array) $f,
                'ficha' => $fichas[$id],
                'gemelos' => array_map(static fn ($g) => [
                    ...$fichas[$g['alumno_id']],
                    'coincidencia' => $g['coincidencia'],
                ], $gemelosDe[$id]),
                'cuelga' => $cuelga,
                'borrable' => $cuelga === [],
            ];
        }, $filas);
    }

    /**
     * Las otras fichas de la misma persona que sí se matricularon, con el buscador del alta
     * (`AlumnosParecidos`): el mismo criterio que habría parado el duplicado si hubiera existido.
     * Sin las de la papelera (no se puede unir a una ficha borrada) ni las que tampoco tienen
     * matrícula (salen en esta misma lista).
     *
     * @return list<array{alumno_id: int, coincidencia: string}>
     */
    private static function gemelos(object $f): array
    {
        $candidatos = AlumnosParecidos::buscar($f->nombres, $f->apellidos, $f->documento)['candidatos'];

        return array_values(array_map(
            static fn ($c) => ['alumno_id' => (int) $c['alumno_id'], 'coincidencia' => $c['coincidencia']],
            array_filter($candidatos, static fn ($c) => (int) $c['alumno_id'] !== (int) $f->alumno_id
                && ! $c['en_papelera'] && $c['matriculas'] > 0)));
    }

    /**
     * A la papelera, con su cuenta desactivada. Sólo si de verdad no tiene nada.
     *
     * La cuenta se DESACTIVA, como en `FusionDeAlumnos::resolverLaCuenta`: si no, el chico que
     * entró alguna vez con el usuario del alta fallida (el 1116873883 tiene tres sesiones de
     * 2022) sigue entrando a una ficha que ya no existe. Si la cuenta la usa otra persona, no se
     * toca.
     *
     * @return array{alumno_id: int, usuario_desactivado: string|null}
     */
    public static function descartar(int $id, ?int $quien): array
    {
        return DB::transaction(function () use ($id, $quien) {
            $a = DB::table('alumnos')->where('id', $id)->whereNull('deleted_at')->lockForUpdate()->first();
            if (! $a) {
                abort(404, 'Ese alumno no existe o ya está en la papelera.');
            }

            $cuelga = FusionDeAlumnos::loQueCuelga($id);
            if ($cuelga !== []) {
                abort(422, 'No se borra: tiene '.implode(', ', array_map(
                    static fn ($c) => $c['filas'].' en '.$c['tabla'], $cuelga)).'. Únelo a su otra ficha para no perderlo.');
            }

            DB::table('alumnos')->where('id', $id)->update([
                'deleted_at' => Reloj::ahora(),
                'deleted_by' => $quien,
                'updated_at' => Reloj::ahora(),
            ]);

            $desactivado = null;
            if ($a->user_id) {
                $compartida = DB::table('alumnos')->where('user_id', $a->user_id)->where('id', '<>', $id)->whereNull('deleted_at')->exists()
                    || DB::table('profesores')->where('user_id', $a->user_id)->exists()
                    || DB::table('acudientes')->where('user_id', $a->user_id)->exists();
                if (! $compartida) {
                    DB::table('users')->where('id', $a->user_id)->update([
                        'is_active' => 0, 'updated_at' => Reloj::ahora(), 'updated_by' => $quien,
                    ]);
                    $desactivado = DB::table('users')->where('id', $a->user_id)->value('username');
                }
            }

            Auditoria::registrar()
                ->borrar('alumno', $id)
                ->deAlumno($id, trim($a->nombres.' '.$a->apellidos))
                ->resumen('Borró una ficha que nunca se matriculó y no tenía nada'
                    .($desactivado ? '; desactivó su usuario «'.$desactivado.'»' : ''))
                ->guardar();

            return ['alumno_id' => $id, 'usuario_desactivado' => $desactivado];
        });
    }
}
