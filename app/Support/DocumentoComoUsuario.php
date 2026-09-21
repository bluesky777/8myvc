<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Poner el documento de identidad como nombre de usuario, sabiendo a quién le toca
 * y a quién no.
 *
 * **Por qué existe en vez de un `UPDATE IGNORE` más.** Las dos rutas viejas
 * —`poner-documento-como-username-alumnos` y `-acudientes`— son una sola sentencia:
 *
 *     UPDATE IGNORE users u INNER JOIN alumnos a ON …
 *     SET u.username = a.documento
 *     WHERE a.documento > 0 AND a.documento IS NOT NULL AND a.documento <> ''
 *
 * y responden siempre `{resultado: 'Usernames cambiados.'}`. Tres cosas se pierden
 * ahí, y las tres se miden en la base del docker (21 sep 2026, colegio simonbolivar):
 *
 *   · **Cuántos cambiaron.** `DB::select()` sobre un UPDATE no devuelve filas
 *     afectadas, y el controlador además descartaba el resultado. Cambiar 1.280 y
 *     cambiar 0 daban la misma respuesta. En el grupo 113 son 7 alumnos, 6 con
 *     documento y **3 que ya lo tenían puesto**: el trabajo real eran 3 cuentas, no 7.
 *   · **Quién chocó.** El `IGNORE` degrada el error 1062 de `users_username_unique`
 *     a warning y salta la fila **en silencio**. Dos hermanos con el mismo documento,
 *     o un documento que ya es el usuario de otra cuenta —incluida una borrada, que
 *     el índice UNIQUE no distingue—, se quedan como estaban sin que nadie se entere.
 *   · **Los documentos con letra.** `a.documento > 0` sobre una columna `varchar(255)`
 *     fuerza la coerción de MySQL: `TI1098…` vale 0 y queda fuera del `WHERE`. En el
 *     docker son 3 profesores y 1 acudiente que nunca se enteraron de que no cambiaron.
 *
 * **Y `total` cuadra con lo que promete la fila de la pantalla.** La consulta entra por la
 * persona —`alumnos`, `acudientes`, `profesores`— y el `users` va en LEFT JOIN, así que quien no
 * tiene cuenta sale contado en `sin_cuenta` en vez de desaparecer del recuento. La primera versión
 * entraba por `users` con un INNER JOIN y en el grupo 113 el desglose sumaba 7 mientras la
 * pantalla decía «14 matriculados»: los otros 7 no tenían cuenta, y nadie lo decía.
 *
 * Aquí se clasifica **antes** de escribir, y se escribe sólo lo que va a entrar. Eso
 * permite que la pantalla enseñe el desglose antes de que nadie pulse nada, que es de
 * lo que iba el cambio: `revisar()` y `aplicar()` devuelven la misma forma, y la
 * primera no toca la base.
 *
 * **El empate no lo gana el primero.** Si dos personas piden el mismo documento, las
 * dos se quedan como están y las dos salen en `en_conflicto`. El `IGNORE` le daba el
 * usuario al primero del join —un orden que nadie eligió— y dejaba al otro mudo. Con
 * dos filas iguales no hay respuesta correcta: la hay cuando alguien mira los dos
 * documentos y arregla el que está mal.
 */
class DocumentoComoUsuario
{
    /** @var list<string> */
    public const DESTINOS = ['alumnos', 'acudientes', 'profesores'];

    /**
     * Cuántos nombres se devuelven de cada lista. El desglose es para leerlo en
     * pantalla y arreglarlo a mano; 1.280 nombres no se leen ni se arreglan, y el
     * total va aparte para que la pantalla pueda decir «y 30 más».
     */
    private const TOPE_DE_LA_LISTA = 50;

    /** Los que se escriben en una sentencia. Un colegio son ~1.300 cuentas. */
    private const TAMANO_DEL_LOTE = 200;

    /**
     * Qué pasaría, sin que pase. No escribe.
     *
     * @return array<string,mixed>
     */
    public static function revisar(string $destino, ?int $grupoId): array
    {
        return self::clasificar($destino, $grupoId)['resumen'];
    }

    /**
     * Lo mismo, y además lo hace.
     *
     * @return array<string,mixed>
     */
    public static function aplicar(string $destino, ?int $grupoId, ?int $quien): array
    {
        $plan   = self::clasificar($destino, $grupoId);
        $nuevos = $plan['por_cambiar'];

        if ($nuevos !== []) {
            DB::transaction(function () use ($nuevos, $quien) {
                foreach (array_chunk($nuevos, self::TAMANO_DEL_LOTE, true) as $lote) {
                    self::escribirLote($lote, $quien);
                }
            });
        }

        $resumen = $plan['resumen'];

        // `revisar` promete y `aplicar` cuenta. Es el mismo número, pero el nombre
        // del campo dice cuál de las dos cosas lo miró.
        $resumen['cambiados'] = count($nuevos);
        unset($resumen['por_cambiar']);

        return $resumen;
    }

    /**
     * El reparto, que es lo único que decide algo.
     *
     * @return array{resumen: array<string,mixed>, por_cambiar: array<int,string>}
     */
    private static function clasificar(string $destino, ?int $grupoId): array
    {
        $candidatos = self::candidatos($destino, $grupoId);

        $sinDocumento = [];
        $sinCuenta    = [];
        $yaLoTenian   = 0;
        $enConflicto  = [];
        $aspirantes   = [];   // documento => list<fila>

        foreach ($candidatos as $fila) {
            $documento = trim((string) $fila->documento);

            // NI SIQUIERA TIENE CUENTA. Se cuenta aparte y no se calla, que es lo que pasaba
            // cuando la consulta entraba por `users` con un INNER JOIN: en el grupo 113 la
            // pantalla decía «14 matriculados» y el desglose sumaba 7, y los otros 7
            // desaparecían sin una palabra. Un alumno sin cuenta no es un alumno al que le
            // falte el documento: es uno al que hay que crearle el acceso, y son dos arreglos
            // distintos en dos pantallas distintas.
            if ($fila->user_id === null) {
                $sinCuenta[] = $fila->nombre;
                continue;
            }

            if ($documento === '') {
                $sinDocumento[] = $fila->nombre;
                continue;
            }

            // Ya está hecho. No es un cambio, y contarlo como tal es la mentira que
            // hacía que «7 alumnos» pareciera el trabajo pendiente cuando eran 3.
            if ((string) $fila->username === $documento) {
                $yaLoTenian++;
                continue;
            }

            $aspirantes[$documento][] = $fila;
        }

        $ocupados   = self::usuariosYaTomados(array_keys($aspirantes));
        $porCambiar = [];

        foreach ($aspirantes as $documento => $filas) {
            // Dos personas, un documento: ninguna se lo lleva. Ver la cabecera.
            if (count($filas) > 1) {
                foreach ($filas as $f) {
                    $enConflicto[] = self::conflicto($f, $documento,
                        'Hay '.count($filas).' personas con este mismo documento.');
                }
                continue;
            }

            $fila  = $filas[0];
            $dueno = $ocupados[$documento] ?? null;

            // El índice `users_username_unique` no mira `deleted_at`, así que una
            // cuenta borrada sigue reservando el nombre. Decirlo con esas palabras,
            // porque si no el secretario busca a esa persona y no la encuentra.
            if ($dueno !== null && (int) $dueno->id !== (int) $fila->user_id) {
                $enConflicto[] = self::conflicto($fila, $documento,
                    $dueno->borrado
                        ? 'Ese usuario lo tiene una cuenta borrada.'
                        : 'Ese usuario ya lo tiene otra persona.');
                continue;
            }

            $porCambiar[(int) $fila->user_id] = $documento;
        }

        return [
            'por_cambiar' => $porCambiar,
            'resumen'     => [
                'destino'             => $destino,
                'ambito'              => $grupoId === null ? 'colegio' : 'grupo',
                'grupo_id'            => $grupoId,
                'total'               => count($candidatos),
                'por_cambiar'         => count($porCambiar),
                'ya_lo_tenian'        => $yaLoTenian,
                'sin_cuenta'          => count($sinCuenta),
                'sin_cuenta_lista'    => array_slice($sinCuenta, 0, self::TOPE_DE_LA_LISTA),
                'sin_documento'       => count($sinDocumento),
                'sin_documento_lista' => array_slice($sinDocumento, 0, self::TOPE_DE_LA_LISTA),
                'en_conflicto'        => count($enConflicto),
                'en_conflicto_lista'  => array_slice($enConflicto, 0, self::TOPE_DE_LA_LISTA),
            ],
        ];
    }

    /** @return array{nombre: string, documento: string, motivo: string} */
    private static function conflicto(object $fila, string $documento, string $motivo): array
    {
        return [
            'nombre'    => (string) $fila->nombre,
            'documento' => $documento,
            'motivo'    => $motivo,
        ];
    }

    /**
     * Quién podría cambiar, con el usuario que tiene hoy.
     *
     * El `GROUP BY u.id` no es adorno: por el grupo se entra por `matriculas` y por
     * `parentescos`, y un acudiente con dos hijos en el mismo grupo saldría dos veces.
     *
     * **Los cuatro estados de `Matricula::$consulta_asistentes_o_matriculados`** (Models/
     * Matricula.php:159), que es la consulta que `matriculas/alumnos-con-grado-anterior` usa
     * para su lista `AlumnosActuales` — la que la pantalla cuenta en «N matriculados en el
     * grupo elegido». Si aquí se contara distinto, la fila prometería un número y el diálogo
     * enseñaría otro.
     *
     * Y eso pasó: la primera versión puso `IN ('MATR','ASIS')` copiándolo de
     * `MatriculasController:207`, que es **otro método del mismo fichero**. La pantalla decía
     * «14 matriculados» y el desglose sumaba 7; los 7 que faltaban eran los `PREM`/`PREA`, o
     * sea los prematriculados, que en enero son casi el grupo entero. La consulta era
     * correcta y la cita era falsa, que es peor: se lee y se aprueba.
     *
     * @return list<object>
     */
    private static function candidatos(string $destino, ?int $grupoId): array
    {
        $delGrupo = $grupoId !== null;

        if ($destino === 'profesores') {
            return DB::select('SELECT u.id AS user_id, u.username,
                    TRIM(COALESCE(p.num_doc, "")) AS documento,
                    TRIM(CONCAT(p.nombres, " ", COALESCE(p.apellidos, ""))) AS nombre
                FROM profesores p
                LEFT JOIN users u ON u.id = p.user_id AND u.deleted_at IS NULL AND u.tipo = "Profesor"
                WHERE p.deleted_at IS NULL
                GROUP BY p.id, u.id, u.username, documento, nombre
                ORDER BY nombre');
        }

        if ($destino === 'alumnos') {
            $sql = 'SELECT u.id AS user_id, u.username,
                    TRIM(COALESCE(a.documento, "")) AS documento,
                    TRIM(CONCAT(a.nombres, " ", COALESCE(a.apellidos, ""))) AS nombre
                FROM alumnos a '
                .($delGrupo
                    ? 'INNER JOIN matriculas m ON m.alumno_id = a.id AND m.deleted_at IS NULL
                           AND m.grupo_id = :grupo AND m.estado IN ("ASIS", "MATR", "PREM", "PREA") '
                    : '')
                .'LEFT JOIN users u ON u.id = a.user_id AND u.deleted_at IS NULL AND u.tipo = "Alumno"
                WHERE a.deleted_at IS NULL
                GROUP BY a.id, u.id, u.username, documento, nombre
                ORDER BY nombre';

            return DB::select($sql, $delGrupo ? ['grupo' => $grupoId] : []);
        }

        $sql = 'SELECT u.id AS user_id, u.username,
                TRIM(COALESCE(ac.documento, "")) AS documento,
                TRIM(CONCAT(ac.nombres, " ", COALESCE(ac.apellidos, ""))) AS nombre
            FROM acudientes ac '
            .($delGrupo
                ? 'INNER JOIN parentescos pa ON pa.acudiente_id = ac.id AND pa.deleted_at IS NULL
                   INNER JOIN alumnos a ON a.id = pa.alumno_id AND a.deleted_at IS NULL
                   INNER JOIN matriculas m ON m.alumno_id = a.id AND m.deleted_at IS NULL
                       AND m.grupo_id = :grupo AND m.estado IN ("ASIS", "MATR", "PREM", "PREA") '
                : '')
            .'LEFT JOIN users u ON u.id = ac.user_id AND u.deleted_at IS NULL AND u.tipo = "Acudiente"
            WHERE ac.deleted_at IS NULL
            GROUP BY ac.id, u.id, u.username, documento, nombre
            ORDER BY nombre';

        return DB::select($sql, $delGrupo ? ['grupo' => $grupoId] : []);
    }

    /**
     * De los documentos que se quieren usar, cuáles ya son el usuario de alguien.
     *
     * **Sin filtrar `deleted_at`**, a propósito: el índice UNIQUE tampoco lo filtra,
     * así que una cuenta borrada sigue bloqueando el nombre y el UPDATE fallaría
     * igual. `PerfilesController::putGuardarUsername` toma la misma decisión para el
     * cambio de uno en uno, y se dice ahí con las mismas palabras.
     *
     * @param  list<string>  $documentos
     * @return array<string,object>  documento => la cuenta que lo tiene
     */
    private static function usuariosYaTomados(array $documentos): array
    {
        $tomados = [];

        foreach (array_chunk($documentos, 1000) as $lote) {
            $huecos = implode(',', array_fill(0, count($lote), '?'));

            foreach (DB::select(
                "SELECT id, username, (deleted_at IS NOT NULL) AS borrado
                 FROM users WHERE username IN ($huecos)", $lote) as $fila) {
                $tomados[(string) $fila->username] = $fila;
            }
        }

        return $tomados;
    }

    /**
     * Un `UPDATE … CASE` por lote, no uno por persona.
     *
     * Y escribe `updated_at`/`updated_by`, que el camino viejo **no escribía**: la
     * cuenta cambiaba de nombre sin dejar rastro de quién ni cuándo, al revés que
     * `GuardarAlumno:160` para el cambio de uno en uno.
     *
     * @param  array<int,string>  $lote  user_id => documento
     */
    private static function escribirLote(array $lote, ?int $quien): void
    {
        $casos   = '';
        $valores = [];

        foreach ($lote as $userId => $documento) {
            $casos .= ' WHEN ? THEN ?';
            $valores[] = $userId;
            $valores[] = $documento;
        }

        $ids    = array_keys($lote);
        $huecos = implode(',', array_fill(0, count($ids), '?'));

        DB::update(
            "UPDATE users SET username = CASE id$casos END,
                    updated_at = ?, updated_by = ?
             WHERE id IN ($huecos)",
            array_merge($valores, [Reloj::ahora(), $quien], $ids)
        );
    }
}
