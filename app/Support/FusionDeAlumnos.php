<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Unir dos fichas del mismo alumno en una.
 *
 * **NO EXISTÍA NADA.** Hasta hoy, dos fichas del mismo chico sólo se podían «arreglar» borrando
 * una con `alumnos/forcedelete`, que se lleva por delante sus matrículas, sus notas, sus
 * inasistencias y su disciplina. O sea: la única salida era perder la mitad del expediente.
 *
 * Y el repo ya sabía que esto hacía falta y por qué daba miedo —`EnsayoDeLaImportacion.php:457`:
 *
 *   > «Es el chico que pasa de Registro Civil a Tarjeta de Identidad, que es el caso más común de
 *   > todos y hoy se duplica en silencio. No decide nada: deja las dos filas al lado para que una
 *   > persona elija, porque **fusionar dos expedientes mal es de lo poco aquí que no se deshace
 *   > con un `DELETE`**.»
 *
 * Esa frase manda sobre todo el fichero. De ahí las tres reglas:
 *
 *   1. **`revisar()` antes que `fusionar()`**, y `revisar()` no escribe. Nadie une dos
 *      expedientes sin ver antes cuántas filas se mueven y qué choca.
 *   2. **Los choques de notas no se resuelven solos.** Si las dos fichas tienen definitiva en la
 *      misma asignatura y periodo, las dos van a la pantalla y una persona elige. Quedarse con la
 *      del superviviente «porque sí» es tirar una nota de un boletín sin que nadie lo sepa.
 *   3. **La ficha vacía va a la papelera, no al `forceDelete`.** Si la fusión salió mal, con la
 *      fila viva todavía se puede mirar qué pasó; con un borrado físico, no.
 *
 * ── CÓMO SE DESCUBRE QUÉ HAY QUE MOVER ──────────────────────────────────────────────────────
 *
 * **Se pregunta a la base, no se escribe una lista.** Las tablas con `alumno_id` son 27 hoy y
 * eran 19 en el dump de agosto; ocho entraron después por migraciones. Una lista escrita a mano
 * se queda vieja **en silencio**: la tabla nueva no se mueve, sus filas se quedan apuntando a una
 * ficha borrada, y nadie se entera hasta que alguien abre esa pantalla meses después.
 *
 * Así que la lista sale de `information_schema` en cada llamada, y **se enseña entera en la
 * pantalla con el número de filas de cada tabla**: quien fusiona ve exactamente qué se mueve.
 *
 * ── LO QUE NO CUELGA DE `alumno_id` Y HAY QUE NOMBRAR A MANO ────────────────────────────────
 *
 * Tres sitios guardan al alumno con otro nombre de columna, así que el descubrimiento no los ve:
 * `ws_actividades_resueltas.persona_id`, y `comentarios` / `publicaciones`, que son polimórficas
 * (`persona_id` + `tipo_persona = 'Alumno'`). Están en `LAS_DE_OTRO_NOMBRE`.
 *
 * `df_asignaturas.alumno_id_df` NO está, y es correcto: apunta a `df_alumnos.id`, no a
 * `alumnos.id`, así que se arrastra sola cuando se mueve `df_alumnos`.
 */
class FusionDeAlumnos
{
    /**
     * Lo que NO se mueve aunque tenga `alumno_id`.
     *
     * `auditoria` es el rastro de quién hizo qué: reescribirlo sería falsificar el registro de lo
     * que pasó de verdad. La fila dice «se tocó la ficha 521» y esa ficha existía cuando se tocó.
     */
    private const NO_TOCAR = ['auditoria'];

    /** Las que guardan al alumno con otra columna. Ver la cabecera. */
    private const LAS_DE_OTRO_NOMBRE = [
        ['tabla' => 'ws_actividades_resueltas', 'columna' => 'persona_id', 'tipo' => null],
        ['tabla' => 'comentarios',              'columna' => 'persona_id', 'tipo' => 'Alumno'],
        ['tabla' => 'publicaciones',            'columna' => 'persona_id', 'tipo' => 'Alumno'],
    ];

    /**
     * Las que tienen UNIQUE con `alumno_id` dentro: un `UPDATE` ciego **revienta con error SQL**
     * en vez de crear un duplicado. Se resuelven antes, quedándose con la del superviviente.
     *
     * Son las dos únicas del esquema, y no son académicas —una marca de boletín independiente y
     * una orden de inscripción—, así que no se manda a nadie a elegir por ellas.
     */
    private const CON_UNICO = [
        ['tabla' => 'bol_ind_periodos',    'con' => ['periodo_id']],
        ['tabla' => 'ordenes_inscripcion', 'con' => ['year_campana']],
    ];

    /**
     * Los choques que SÍ decide una persona. Ver la regla 2 de la cabecera.
     *
     * `notas` —las de subunidad— no está: una subunidad pertenece a una asignatura de un grupo, y
     * dos fichas del mismo chico no comparten subunidad salvo que hayan estado en el mismo grupo
     * a la vez. Cuando pasa, se arrastra sin más y la pantalla lo cuenta como fila movida.
     */
    private const A_MANO = [
        'notas_finales'       => ['asignatura_id', 'periodo_id'],
        'nota_comportamiento' => ['periodo_id'],
    ];

    /**
     * Qué pasaría. No escribe nada.
     *
     * @return array<string,mixed>
     */
    public static function revisar(int $origen, int $destino): array
    {
        [$fichaOrigen, $fichaDestino] = self::lasDosFichas($origen, $destino);

        $mueve = [];
        foreach (self::tablasConAlumno() as $tabla) {
            $n = (int) DB::selectOne("SELECT COUNT(*) AS n FROM `$tabla` WHERE alumno_id = ?", [$origen])->n;
            if ($n > 0) {
                $mueve[] = ['tabla' => $tabla, 'filas' => $n];
            }
        }

        foreach (self::LAS_DE_OTRO_NOMBRE as $otra) {
            if (! self::existeTabla($otra['tabla'])) { continue; }

            $sql = "SELECT COUNT(*) AS n FROM `{$otra['tabla']}` WHERE {$otra['columna']} = ?";
            $valores = [$origen];
            if ($otra['tipo'] !== null) {
                $sql .= ' AND tipo_persona = ?';
                $valores[] = $otra['tipo'];
            }

            $n = (int) DB::selectOne($sql, $valores)->n;
            if ($n > 0) {
                $mueve[] = ['tabla' => $otra['tabla'], 'filas' => $n];
            }
        }

        return [
            'origen'          => $fichaOrigen,
            'destino'         => $fichaDestino,
            'mueve'           => $mueve,
            'filas_totales'   => array_sum(array_column($mueve, 'filas')),
            'choques'         => self::choques($origen, $destino),
            'matriculas'      => self::matriculasQueChocan($origen, $destino),
            'cuenta_de_acceso'=> self::quePasaConLaCuenta($fichaOrigen, $fichaDestino),
        ];
    }

    /**
     * Y lo hace.
     *
     * `$decisiones` viene de la pantalla: `['notas_finales' => ['12_3' => 'origen'], …]`, donde la
     * clave es la de `choques()` y el valor dice qué ficha se queda con esa nota. **Lo que no
     * venga decidido se queda con la del destino**, que es el superviviente: es la única opción
     * que no pierde nada silenciosamente, porque la fila del origen se borra igual en los dos
     * casos y así al menos gana la que ya estaba donde el alumno sigue.
     *
     * @param  array<string,array<string,string>>  $decisiones
     * @return array<string,mixed>
     */
    public static function fusionar(int $origen, int $destino, array $decisiones, ?int $quien): array
    {
        [$fichaOrigen, $fichaDestino] = self::lasDosFichas($origen, $destino);

        $movidas = 0;
        $resueltos = 0;

        DB::transaction(function () use ($origen, $destino, $decisiones, $quien, &$movidas, &$resueltos) {
            // 1. Los choques que decide una persona, ANTES de mover nada.
            foreach (self::A_MANO as $tabla => $columnas) {
                if (! self::existeTabla($tabla)) { continue; }

                foreach (self::choquesDe($tabla, $columnas, $origen, $destino) as $choque) {
                    $gana = $decisiones[$tabla][$choque['clave']] ?? 'destino';
                    $perdedor = $gana === 'origen' ? $destino : $origen;

                    $donde = ['alumno_id' => $perdedor];
                    foreach ($columnas as $c) {
                        $donde[$c] = $choque['columnas'][$c];
                    }

                    DB::table($tabla)->where($donde)->delete();
                    $resueltos++;
                }
            }

            // 2. Las de índice único: se queda la del destino y se tira la del origen.
            foreach (self::CON_UNICO as $u) {
                if (! self::existeTabla($u['tabla'])) { continue; }

                foreach (self::choquesDe($u['tabla'], $u['con'], $origen, $destino) as $choque) {
                    $donde = ['alumno_id' => $origen];
                    foreach ($u['con'] as $c) {
                        $donde[$c] = $choque['columnas'][$c];
                    }
                    DB::table($u['tabla'])->where($donde)->delete();
                }
            }

            // 3. Todo lo demás, tal cual.
            foreach (self::tablasConAlumno() as $tabla) {
                $movidas += DB::update("UPDATE `$tabla` SET alumno_id = ? WHERE alumno_id = ?", [$destino, $origen]);
            }

            foreach (self::LAS_DE_OTRO_NOMBRE as $otra) {
                if (! self::existeTabla($otra['tabla'])) { continue; }

                $sql = "UPDATE `{$otra['tabla']}` SET {$otra['columna']} = ? WHERE {$otra['columna']} = ?";
                $valores = [$destino, $origen];
                if ($otra['tipo'] !== null) {
                    $sql .= ' AND tipo_persona = ?';
                    $valores[] = $otra['tipo'];
                }
                $movidas += DB::update($sql, $valores);
            }

            // 4. La cuenta de acceso. Ver `quePasaConLaCuenta()`.
            self::resolverLaCuenta($origen, $destino, $quien);

            /*
             * 5. Y la ficha vacía a la papelera. `deleted_by` para que se sepa quién la dejó así:
             * el `forcedelete` de al lado no deja ni eso.
             */
            DB::table('alumnos')->where('id', $origen)->update([
                'deleted_at' => Reloj::ahora(),
                'deleted_by' => $quien,
                'updated_at' => Reloj::ahora(),
            ]);
        });

        return [
            'origen'     => $fichaOrigen,
            'destino'    => $fichaDestino,
            'movidas'    => $movidas,
            'resueltos'  => $resueltos,
        ];
    }

    /* ── Lo que se enseña antes de decidir ───────────────────────────────────────────────── */

    /**
     * Las notas que están en las dos fichas, con los dos valores al lado.
     *
     * @return array<string,list<array<string,mixed>>>
     */
    private static function choques(int $origen, int $destino): array
    {
        $salida = [];

        foreach (self::A_MANO as $tabla => $columnas) {
            if (! self::existeTabla($tabla)) { continue; }

            $salida[$tabla] = self::choquesDe($tabla, $columnas, $origen, $destino, true);
        }

        return $salida;
    }

    /**
     * @param  list<string>  $columnas
     * @return list<array<string,mixed>>
     */
    private static function choquesDe(string $tabla, array $columnas, int $origen, int $destino, bool $conDetalle = false): array
    {
        $llaves = implode(', ', array_map(static fn ($c) => "o.$c", $columnas));
        $union  = implode(' AND ', array_map(static fn ($c) => "d.$c = o.$c", $columnas));

        // `nota` existe en las dos tablas de `A_MANO`; es lo que se compara para elegir.
        $extra = $conDetalle ? ', o.nota AS nota_origen, d.nota AS nota_destino' : '';

        $filas = DB::select(
            "SELECT $llaves $extra FROM `$tabla` o
             INNER JOIN `$tabla` d ON $union AND d.alumno_id = ?
             WHERE o.alumno_id = ?", [$destino, $origen]);

        return array_map(static function ($f) use ($columnas, $conDetalle) {
            $valores = [];
            foreach ($columnas as $c) {
                $valores[$c] = $f->$c;
            }

            return [
                // La clave con la que la pantalla devuelve su decisión. Simple a propósito.
                'clave'    => implode('_', array_values($valores)),
                'columnas' => $valores,
            ] + ($conDetalle ? ['nota_origen' => $f->nota_origen, 'nota_destino' => $f->nota_destino] : []);
        }, $filas);
    }

    /**
     * Las dos matrículas en el mismo grupo, que la base permite y nadie avisa.
     *
     * No se borran solas: quedarse con dos matrículas del mismo alumno en el mismo grupo no
     * rompe nada inmediato pero duplica al chico en las listas, así que se cuenta y se dice.
     *
     * @return list<array<string,mixed>>
     */
    private static function matriculasQueChocan(int $origen, int $destino): array
    {
        return DB::select(
            'SELECT o.grupo_id, g.nombre AS grupo, y.year, o.estado AS estado_origen, d.estado AS estado_destino
             FROM matriculas o
             INNER JOIN matriculas d ON d.grupo_id = o.grupo_id AND d.alumno_id = ? AND d.deleted_at IS NULL
             INNER JOIN grupos g ON g.id = o.grupo_id
             INNER JOIN years y ON y.id = g.year_id
             WHERE o.alumno_id = ? AND o.deleted_at IS NULL', [$destino, $origen]);
    }

    /* ── La cuenta de acceso ─────────────────────────────────────────────────────────────── */

    /**
     * `users.username` es UNIQUE, así que las dos cuentas no se pueden juntar: hay que decidir
     * cuál queda viva. Se dice antes, en la pantalla, porque quien fusiona necesita saber con qué
     * usuario va a entrar el chico mañana.
     *
     * @return array<string,mixed>
     */
    private static function quePasaConLaCuenta(object $origen, object $destino): array
    {
        if ($destino->user_id === null && $origen->user_id !== null) {
            return ['accion' => 'se_traslada', 'username' => $origen->username];
        }

        if ($origen->user_id !== null) {
            return ['accion' => 'se_desactiva', 'username' => $origen->username, 'queda' => $destino->username];
        }

        return ['accion' => 'nada', 'queda' => $destino->username];
    }

    private static function resolverLaCuenta(int $origen, int $destino, ?int $quien): void
    {
        [$fichaOrigen, $fichaDestino] = self::lasDosFichas($origen, $destino);

        if ($fichaOrigen->user_id === null) {
            return;
        }

        // El superviviente no tenía cuenta: se queda con la del otro y el chico entra igual.
        if ($fichaDestino->user_id === null) {
            DB::table('alumnos')->where('id', $destino)->update([
                'user_id' => $fichaOrigen->user_id, 'updated_at' => Reloj::ahora(), 'updated_by' => $quien,
            ]);
            DB::table('alumnos')->where('id', $origen)->update(['user_id' => null]);

            return;
        }

        /*
         * Dos cuentas, y el username es UNIQUE: la del duplicado se DESACTIVA en vez de borrarse.
         * Borrarla dejaría huérfanos sus tokens, su historial de sesiones y sus votos; desactivarla
         * cierra la puerta y conserva el rastro. Ver el mapa de lo que cuelga de `users`.
         */
        DB::table('users')->where('id', $fichaOrigen->user_id)->update([
            'is_active' => 0, 'updated_at' => Reloj::ahora(), 'updated_by' => $quien,
        ]);
    }

    /* ── Ayudas ──────────────────────────────────────────────────────────────────────────── */

    /** @return array{0: object, 1: object} */
    private static function lasDosFichas(int $origen, int $destino): array
    {
        if ($origen === $destino) {
            abort(422, 'Las dos fichas son la misma.');
        }

        $consulta = 'SELECT a.id, a.nombres, a.apellidos, a.documento, a.user_id, u.username
            FROM alumnos a LEFT JOIN users u ON u.id = a.user_id
            WHERE a.id = ? AND a.deleted_at IS NULL';

        $o = DB::selectOne($consulta, [$origen]);
        $d = DB::selectOne($consulta, [$destino]);

        if ($o === null || $d === null) {
            abort(404, 'Alguna de las dos fichas no existe o ya está en la papelera.');
        }

        return [$o, $d];
    }

    /**
     * Las tablas con `alumno_id`, preguntadas a la base. Ver la cabecera.
     *
     * @return list<string>
     */
    private static function tablasConAlumno(): array
    {
        $filas = DB::select(
            "SELECT TABLE_NAME AS t FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'alumno_id'
             ORDER BY TABLE_NAME");

        return array_values(array_filter(
            array_map(static fn ($f) => $f->t, $filas),
            static fn ($t) => ! in_array($t, self::NO_TOCAR, true)));
    }

    private static function existeTabla(string $tabla): bool
    {
        return DB::selectOne(
            'SELECT COUNT(*) AS n FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$tabla])->n > 0;
    }
}
