<?php

namespace App\Services\Act;

use Illuminate\Support\Facades\DB;

/**
 * LAS FORMAS JSON DEL MÓDULO DE ACTIVIDADES.
 *
 * Cada método arma uno de los tipos de §4 del contrato (`app2/src/app/datos/act.ts`), con los
 * mismos nombres de campo. **El front se fía de esto sin mirar el backend**: si un campo cambia de
 * nombre o de tipo aquí, cambia en `act.ts` en el mismo commit del contrato, nunca por su cuenta.
 *
 * Los booleanos salen como `true`/`false` y no como `0`/`1` (la base guarda `tinyint`): es lo que
 * declara el tipo TypeScript, y un `0` en una plantilla de Angular se pinta como «0».
 */
class Formas
{
    /**
     * Las preguntas de una actividad, completas (con correctas, explicación y error típico), en la
     * forma interna que usan el recorrido y el calificador. `sinSecretos()` quita lo que no ve quien
     * responde.
     *
     * @return list<array<string, mixed>>
     */
    public static function preguntas(int $actividadId): array
    {
        $filas = DB::select(
            'SELECT p.*, i.nombre AS imagen_nombre FROM ws_preguntas p
               LEFT JOIN images i ON i.id = p.imagen_id AND i.deleted_at IS NULL
              WHERE p.actividad_id = ? AND p.deleted_at IS NULL
              ORDER BY p.orden, p.id',
            [$actividadId]
        );

        if ($filas === []) {
            return [];
        }

        $ids = array_map(fn ($f) => (int) $f->id, $filas);
        $huecos = implode(',', array_fill(0, count($ids), '?'));

        $opciones = [];

        foreach (DB::select(
            "SELECT o.*, i.nombre AS imagen_nombre FROM ws_opciones o
               LEFT JOIN images i ON i.id = o.image_id AND i.deleted_at IS NULL
              WHERE o.pregunta_id IN ($huecos) ORDER BY o.orden, o.id",
            $ids
        ) as $o) {
            $opciones[(int) $o->pregunta_id][] = [
                'id' => (int) $o->id,
                'definicion' => (string) $o->definicion,
                'orden' => (int) $o->orden,
                'image_id' => $o->image_id === null ? null : (int) $o->image_id,
                'imagen_url' => self::urlDeImagen($o->imagen_nombre),
                'is_correct' => (bool) $o->is_correct,
                'error_tipico' => $o->error_tipico,
            ];
        }

        $condiciones = [];

        foreach (DB::select(
            "SELECT * FROM ws_condiciones WHERE pregunta_id IN ($huecos) ORDER BY pregunta_id, grupo, id",
            $ids
        ) as $c) {
            $condiciones[(int) $c->pregunta_id][(int) $c->grupo][] = [
                'depende_de_id' => (int) $c->depende_de_id,
                'operador' => $c->operador,
                'opcion_id' => $c->opcion_id === null ? null : (int) $c->opcion_id,
                'valor' => $c->valor,
            ];
        }

        return array_map(fn ($p) => [
            'id' => (int) $p->id,
            'orden' => (int) $p->orden,
            'seccion' => (int) $p->seccion,
            'tipo' => (string) $p->tipo_pregunta,
            'enunciado' => (string) $p->enunciado,
            'ayuda' => $p->ayuda,
            'obligatoria' => (bool) $p->obligatoria,
            'puntos' => (int) $p->puntos,
            'imagen_id' => $p->imagen_id === null ? null : (int) $p->imagen_id,
            'imagen_url' => self::urlDeImagen($p->imagen_nombre),
            'youtube_id' => $p->youtube_id,
            'youtube_inicio' => $p->youtube_inicio === null ? null : (int) $p->youtube_inicio,
            'youtube_fin' => $p->youtube_fin === null ? null : (int) $p->youtube_fin,
            'enlace_url' => $p->enlace_url,
            'escala_estilo' => $p->escala_estilo,
            'texto_arriba' => $p->texto_arriba,
            'texto_abajo' => $p->texto_abajo,
            'opcion_otra' => (bool) $p->opcion_otra,
            'aleatorias' => (bool) $p->aleatorias,
            'compartir' => (bool) $p->compartir,
            'explicacion' => $p->explicacion,
            'puntaje_parcial' => (bool) $p->puntaje_parcial,
            'opciones' => $opciones[(int) $p->id] ?? [],
            'condiciones' => array_values($condiciones[(int) $p->id] ?? []),
        ], $filas);
    }

    /** Una pregunta sin `is_correct`, `error_tipico` ni `explicacion`: la de quien responde. */
    public static function sinSecretos(array $p): array
    {
        unset($p['explicacion']);

        $p['opciones'] = array_map(function ($o) {
            unset($o['is_correct'], $o['error_tipico']);

            return $o;
        }, $p['opciones']);

        return $p;
    }

    /** `ActEditable`: la config, el estado y las preguntas completas. */
    public static function editable(object $act): array
    {
        $id = (int) $act->id;
        $subunidad = DB::selectOne('SELECT id FROM subunidades WHERE actividad_id = ? AND deleted_at IS NULL LIMIT 1', [$id]);

        return self::config($act) + [
            'id' => $id,
            'estado' => Actividad::estado($act),
            'requiere_aprobacion' => (bool) $act->requiere_aprobacion,
            'rechazo_motivo' => $act->rechazo_motivo,
            'subunidad_id' => $subunidad ? (int) $subunidad->id : null,
            'hojas_n' => Actividad::hojasEnviadas($id),
            // Si su indicador ya tiene alguna nota: decide si un cambio va por «Editar con notas».
            'tiene_notas' => $subunidad !== null && DB::selectOne(
                'SELECT 1 AS si FROM notas WHERE subunidad_id = ? AND nota IS NOT NULL AND deleted_at IS NULL LIMIT 1',
                [(int) $subunidad->id]
            ) !== null,
            'duplicada_de' => $act->duplicada_de === null ? null : (int) $act->duplicada_de,
            'preguntas' => self::preguntas($id),
        ];
    }

    /** `ConfigAct`, tal como se guardó. */
    public static function config(object $act): array
    {
        return [
            'modo' => $act->modo,
            'titulo' => (string) $act->titulo,
            'instrucciones' => $act->instrucciones,
            'alcance' => $act->alcance,
            'asignatura_id' => $act->asignatura_id === null ? null : (int) $act->asignatura_id,
            'grupo_id' => $act->grupo_id === null ? null : (int) $act->grupo_id,
            'responden' => $act->responden,
            'acudiente_por_hijo' => (bool) $act->acudiente_por_hijo,
            'anonimato' => $act->anonimato,
            'destinatarios' => Destinatarios::deActividad((int) $act->id),
            'publica_at' => $act->publica_at,
            'cierra_at' => $act->cierra_at,
            'recibir_tarde' => (bool) $act->recibir_tarde,
            'entrega' => self::entregaPedida($act),
            'califica' => (bool) $act->califica,
            'unidad_id' => $act->unidad_id === null ? null : (int) $act->unidad_id,
            'peso' => $act->peso === null ? null : (int) $act->peso,
            'nota_maxima' => $act->nota_maxima === null ? null : (int) $act->nota_maxima,
            'oportunidades' => max(1, (int) ($act->oportunidades ?? 1)),
            'mostrar_correctas' => $act->mostrar_correctas,
            'comparte_resultados' => $act->comparte_resultados,
            'avisos' => [
                'al_publicar' => (bool) $act->avisar_al_publicar,
                'recordar_horas_antes' => $act->recordar_horas_antes === null ? null : (int) $act->recordar_horas_antes,
                'en_calendario' => (bool) $act->en_calendario,
            ],
        ];
    }

    public static function entregaPedida(object $act): array
    {
        return [
            'texto' => (bool) $act->entrega_texto,
            'foto' => (bool) $act->entrega_foto,
            'archivo' => (bool) $act->entrega_archivo,
            'enlace' => (bool) $act->entrega_enlace,
        ];
    }

    /**
     * `ActEnBandeja`. `$porAlumno` es la entrada del acudiente «una vez por hijo» (una fila por
     * hijo); `$mio` lleva `mi_estado` y `mi_nota`, NULL en la vista `mias`.
     */
    public static function enBandeja(object $act, object $user, ?array $porAlumno = null, ?array $mio = null): array
    {
        $soyDueno = Actividad::esDueno($act, $user);
        $veCifras = $soyDueno || Actividad::esDirectivo($user);
        $conCuenta = array_filter(Destinatarios::resolver($act), fn ($e) => $e['user_id'] !== null);

        return [
            'id' => (int) $act->id,
            'modo' => $act->modo,
            'titulo' => (string) $act->titulo,
            'estado' => Actividad::estado($act),
            'donde' => self::donde($act),
            'publica_at' => $act->publica_at,
            'cierra_at' => $act->cierra_at,
            'anonimato' => $act->anonimato,
            'califica' => (bool) $act->califica,
            'soy_dueno' => $soyDueno,
            'creador' => self::personaDeUsuario((int) $act->created_by),
            'destinatarios_n' => count($conCuenta),
            'respondieron_n' => $veCifras ? self::respondieron($act) : null,
            'por_alumno' => $porAlumno !== null && $porAlumno['publico'] === 'acudiente' && $porAlumno['alumno_id'] !== null
                ? self::persona(null, $porAlumno['alumno_id'], (string) $porAlumno['hijo'], null)
                : null,
            'mi_estado' => $mio['mi_estado'] ?? null,
            'mi_nota' => $mio['mi_nota'] ?? null,
            'rechazo_motivo' => $act->rechazo_motivo,
            'year_id' => (int) $act->year_id,
        ];
    }

    /**
     * Cuántos respondieron: personas con hoja enviada (varios intentos cuentan una vez) o, en la
     * tarea, entregas entregadas. Es una cifra, no una lista: vale también en las anónimas.
     */
    public static function respondieron(object $act): int
    {
        $id = (int) $act->id;

        if ($act->modo === 'tarea') {
            return (int) DB::selectOne(
                'SELECT COUNT(*) AS n FROM ws_entregas WHERE actividad_id = ? AND entregada_at IS NOT NULL', [$id]
            )->n;
        }

        return (int) DB::selectOne(
            'SELECT COUNT(DISTINCT user_id, COALESCE(alumno_id, 0)) AS n FROM ws_actividades_resueltas
              WHERE actividad_id = ? AND terminado = 1 AND deleted_at IS NULL',
            [$id]
        )->n;
    }

    /** «Matemáticas · 7A», «7A», «6A, 6B y 2 más», «Todo el colegio», «Personal». */
    public static function donde(object $act): string
    {
        switch ($act->alcance) {
            case 'clase':
                $c = DB::selectOne(
                    'SELECT m.materia, g.nombre FROM asignaturas a
                      INNER JOIN materias m ON m.id = a.materia_id
                      INNER JOIN grupos g ON g.id = a.grupo_id
                      WHERE a.id = ?',
                    [(int) $act->asignatura_id]
                );

                return $c ? $c->materia.' · '.$c->nombre : 'Una clase';

            case 'grupo':
                $g = DB::selectOne('SELECT nombre FROM grupos WHERE id = ?', [(int) $act->grupo_id]);

                return $g ? (string) $g->nombre : 'Un grupo';

            case 'grupos':
                $nombres = [];

                foreach (Destinatarios::deActividad((int) $act->id) as $f) {
                    if ($f['grupo_id'] !== null) {
                        $nombres['g'.$f['grupo_id']] = DB::selectOne('SELECT nombre FROM grupos WHERE id = ?', [$f['grupo_id']])->nombre ?? '';
                    } elseif ($f['grado_id'] !== null) {
                        $nombres['d'.$f['grado_id']] = DB::selectOne('SELECT nombre FROM grados WHERE id = ?', [$f['grado_id']])->nombre ?? '';
                    }
                }

                $nombres = array_values(array_filter($nombres));

                if ($nombres === []) {
                    return 'Varios grupos';
                }

                $primeros = implode(', ', array_slice($nombres, 0, 3));

                return count($nombres) > 3 ? $primeros.' y '.(count($nombres) - 3).' más' : $primeros;

            case 'colegio':
                return 'Todo el colegio';

            default:
                return 'Personal';
        }
    }

    /** `PersonaCorta` de un usuario del personal (el creador). */
    public static function personaDeUsuario(int $userId): array
    {
        $u = DB::selectOne(
            'SELECT u.id, u.username, p.nombres, p.apellidos, COALESCE(ip.nombre, iu.nombre) AS foto
               FROM users u
               LEFT JOIN profesores p ON p.user_id = u.id AND p.deleted_at IS NULL
               LEFT JOIN images ip ON ip.id = p.foto_id AND ip.deleted_at IS NULL
               LEFT JOIN images iu ON iu.id = u.imagen_id AND iu.deleted_at IS NULL
              WHERE u.id = ?',
            [$userId]
        );

        if (! $u) {
            return self::persona($userId, null, 'Usuario '.$userId, null);
        }

        $nombre = $u->nombres !== null ? Destinatarios::nombre($u->nombres, $u->apellidos) : (string) $u->username;

        return self::persona((int) $u->id, null, $nombre, self::urlDeImagen($u->foto));
    }

    /** `PersonaCorta` de una entrada de `Destinatarios`. */
    public static function personaDeEntrada(array $e): array
    {
        $nombre = $e['hijo'] !== null ? $e['nombre'].' (acudiente de '.$e['hijo'].')' : $e['nombre'];

        return self::persona($e['user_id'], $e['alumno_id'], $nombre, self::urlDeImagen($e['foto']));
    }

    public static function persona(?int $userId, ?int $alumnoId, string $nombre, ?string $fotoUrl): array
    {
        return ['user_id' => $userId, 'alumno_id' => $alumnoId, 'nombre' => $nombre, 'foto_url' => $fotoUrl];
    }

    /** La URL pública de un fichero de `images` (viven en `public/images/perfil/`). */
    public static function urlDeImagen(?string $nombre): ?string
    {
        return $nombre === null || $nombre === '' ? null : url('images/perfil/'.$nombre);
    }

    /** `ArchivoAct`. */
    public static function archivo(?object $a): ?array
    {
        if (! $a) {
            return null;
        }

        return [
            'id' => (int) $a->id,
            'clase' => $a->clase,
            'nombre_original' => $a->nombre_original,
            'mime' => $a->mime,
            'bytes' => (int) $a->bytes,
            'ancho' => $a->ancho === null ? null : (int) $a->ancho,
            'alto' => $a->alto === null ? null : (int) $a->alto,
        ];
    }

    /** `EntregaAct`. */
    public static function entrega(?object $e): ?array
    {
        if (! $e) {
            return null;
        }

        $archivo = fn ($id) => $id === null ? null
            : self::archivo(DB::selectOne('SELECT * FROM ws_archivos WHERE id = ?', [(int) $id]));

        return [
            'alumno_id' => (int) $e->alumno_id,
            'texto' => $e->texto,
            'enlace' => $e->enlace,
            'foto' => $archivo($e->foto_id),
            'archivo' => $archivo($e->archivo_id),
            'entregada_at' => $e->entregada_at,
            'tarde' => (bool) $e->tarde,
            'nota' => $e->nota === null ? null : (int) $e->nota,
            'comentario' => $e->comentario,
            'calificada_at' => $e->calificada_at,
        ];
    }
}
