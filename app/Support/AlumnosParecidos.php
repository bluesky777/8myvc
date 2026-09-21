<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * ¿Este alumno ya está en el sistema?
 *
 * **POR QUÉ HACÍA FALTA OTRO BUSCADOR, habiendo dos.** `alumnos/personas-check` y
 * `alumnos/documento-check` ya existen y la pantalla de alta los llama, pero devuelven
 * cuatro campos —nombres, apellidos, documento, `deleted_at`— y con eso lo único que se
 * puede hacer es lo que se hacía: un aviso amarillo que dice «no lo crees dos veces,
 * búscalo en Alumnos». El propio código lo admite en
 * `app2/src/app/paginas/alumnos-nuevo/alumnos-nuevo.ts:297`: *«Los buscadores de
 * duplicados. Avisan; no rellenan nada.»* Y no bloquean: `falta()` exige nombre y grupo,
 * nunca el duplicado.
 *
 * El resultado, medido en el docker el 21 sep 2026 (colegio `simonbolivar`):
 *
 *     27 documentos repetidos  ->  55 fichas
 *     63 nombres repetidos     -> 130 fichas
 *
 * Y el patrón es siempre el mismo, un año nuevo y una ficha nueva:
 *
 *     id  25  DAVID ALEJANDRO ARAQUE GUERRERO  creado 2018  años 1,5,6,7,8  178 definitivas
 *     id 521  David Alejandro Araque Guerrero  creado 2019  años 2,3         80 definitivas
 *
 * **La decisión no se puede tomar con cuatro campos.** Para que quien da el alta pueda
 * decir «es éste» hace falta ver de qué año viene, en qué grupo estuvo, si se retiró y si
 * trae notas. Eso es lo que devuelve esto y lo que no devolvía ninguno de los dos.
 *
 * **Y `alumnos.documento` no es UNIQUE ni puede serlo** —`ImportarController.php:516-521`:
 * hay filas históricas con el documento vacío o repetido y el ALTER fallaría en los colegios
 * que las tengan—. O sea que la base **no va a parar esto**: se para aquí o no se para.
 */
class AlumnosParecidos
{
    /**
     * Cuántos candidatos se devuelven. Quien da un alta mira tres tarjetas, no cuarenta; y
     * si hay cuarenta, el problema no es el alta.
     */
    private const TOPE = 8;

    /**
     * Los tres grados, de más a menos seguro. El front decide con esto si **bloquea** el
     * alta o sólo avisa, así que el nombre dice el criterio y no la consecuencia: qué se
     * hace con un `documento` es una decisión de pantalla y puede cambiar sin tocar esto.
     */
    public const DOCUMENTO = 'documento';
    public const NOMBRE_EXACTO = 'nombre_exacto';
    public const PARECIDO = 'parecido';

    /**
     * @return array{candidatos: list<array<string,mixed>>, hay_fuertes: bool}
     */
    public static function buscar(?string $nombres, ?string $apellidos, ?string $documento): array
    {
        $documento = trim((string) $documento);
        $nombres   = trim((string) $nombres);
        $apellidos = trim((string) $apellidos);

        $porDocumento = $documento === '' ? [] : self::porDocumento($documento);
        $porNombre    = self::porNombre($nombres, $apellidos);

        // El documento manda: si una ficha sale por las dos, se queda con el grado fuerte.
        $candidatos = [];
        foreach ([$porDocumento, $porNombre] as $lote) {
            foreach ($lote as $fila) {
                $candidatos[(int) $fila->id] ??= $fila;
            }
        }

        if ($candidatos === []) {
            return ['candidatos' => [], 'hay_fuertes' => false];
        }

        $candidatos = array_slice($candidatos, 0, self::TOPE, true);
        $contexto   = self::contextoDe(array_keys($candidatos));

        $salida = [];
        foreach ($candidatos as $id => $fila) {
            $salida[] = [
                'alumno_id'        => $id,
                'nombres'          => $fila->nombres,
                'apellidos'        => $fila->apellidos,
                'documento'        => $fila->documento,
                'tipo_doc_nombre'  => $fila->tipo_doc_nombre,
                'sexo'             => $fila->sexo,
                'fecha_nac'        => $fila->fecha_nac,
                'foto_nombre'      => $fila->foto_nombre,
                'en_papelera'      => $fila->deleted_at !== null,
                'coincidencia'     => $fila->coincidencia,
                'matriculas'       => $contexto[$id]['matriculas'] ?? 0,
                'ultimo_year'      => $contexto[$id]['ultimo_year'] ?? null,
                'ultimo_grupo'     => $contexto[$id]['ultimo_grupo'] ?? null,
                'ultimo_estado'    => $contexto[$id]['ultimo_estado'] ?? null,
                'definitivas'      => $contexto[$id]['definitivas'] ?? 0,
            ];
        }

        return [
            'candidatos'  => $salida,
            // Lo que hace que la pantalla pare en seco, y no sólo pinte amarillo.
            'hay_fuertes' => (bool) array_filter($salida, static fn ($c) => $c['coincidencia'] !== self::PARECIDO),
        ];
    }

    /** @return list<object> */
    private static function porDocumento(string $documento): array
    {
        /*
         * **Con los de la papelera dentro, a propósito.** El caso que más duele es
         * justamente el del alumno que se retiró hace años: quien da el alta no lo
         * encuentra buscando, lo crea de nuevo, y aparecen las dos fichas. Si este
         * buscador tampoco lo ve, la pantalla repite el error que existe para evitar.
         */
        return DB::select(self::SELECT.' WHERE TRIM(a.documento) = ? ORDER BY a.id LIMIT '.self::TOPE,
            [self::DOCUMENTO, $documento]);
    }

    /** @return list<object> */
    private static function porNombre(string $nombres, string $apellidos): array
    {
        $completo = trim($nombres.' '.$apellidos);

        if (mb_strlen($completo) < 3) {
            return [];
        }

        /*
         * EL EXACTO CASA «DAVID ALEJANDRO» CON «David Alejandro» sin hacer nada: la colación
         * de las columnas es `utf8mb4_unicode_ci`, o sea que ignora mayúsculas y acentos. Es
         * exactamente la forma que tiene el duplicado real del docker, y por eso el grado
         * fuerte se calcula con un `=` y no con un `LIKE`.
         */
        $exactos = $apellidos === '' ? [] : DB::select(
            self::SELECT.' WHERE TRIM(a.nombres) = ? AND TRIM(COALESCE(a.apellidos, "")) = ?
                ORDER BY a.id LIMIT '.self::TOPE,
            [self::NOMBRE_EXACTO, $nombres, $apellidos]);

        // Y el flojo: todas las palabras, en cualquier orden y en cualquiera de las dos mitades.
        $palabras = preg_split('/\s+/', $completo, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $condiciones = [];
        $valores = [self::PARECIDO];

        foreach ($palabras as $palabra) {
            $condiciones[] = 'CONCAT(a.nombres, " ", COALESCE(a.apellidos, "")) LIKE ?';
            $valores[] = '%'.$palabra.'%';
        }

        $parecidos = DB::select(
            self::SELECT.' WHERE ('.implode(' AND ', $condiciones).') ORDER BY a.id LIMIT '.self::TOPE,
            $valores);

        return array_merge($exactos, $parecidos);
    }

    /**
     * De qué año viene, dónde estuvo y si trae notas.
     *
     * **Sin esto la tarjeta no sirve para decidir.** «David Alejandro Araque Guerrero,
     * 1020310677» es lo mismo en las dos fichas del docker; lo que las distingue es que una
     * llega hasta el año 8 con 178 definitivas y la otra se quedó en el 3 con 80.
     *
     * **Público porque lo comparte `DuplicadosDeAlumnos`**: la tarjeta del alta y la fila de la
     * pantalla de duplicados enseñan lo mismo, y dos consultas distintas para el mismo dato son
     * dos consultas que acaban discrepando.
     *
     * @param  list<int>  $ids
     * @return array<int,array<string,mixed>>
     */
    public static function contextoDe(array $ids): array
    {
        $huecos = implode(',', array_fill(0, count($ids), '?'));

        /*
         * El último año se decide por `years.year` —el número del año lectivo— y no por
         * `grupos.year_id`: el id crece por orden de creación, que casi siempre coincide,
         * pero «casi siempre» en una tarjeta que se usa para decidir es un fallo esperando.
         */
        $filas = DB::select(
            'SELECT x.alumno_id, x.matriculas, y.year AS ultimo_year, x.grupo_nombre AS ultimo_grupo, x.estado AS ultimo_estado
             FROM (
                SELECT m.alumno_id, COUNT(*) OVER (PARTITION BY m.alumno_id) AS matriculas,
                       g.year_id, g.nombre AS grupo_nombre, m.estado,
                       ROW_NUMBER() OVER (PARTITION BY m.alumno_id ORDER BY yy.year DESC, m.id DESC) AS puesto
                FROM matriculas m
                INNER JOIN grupos g ON g.id = m.grupo_id
                INNER JOIN years yy ON yy.id = g.year_id
                WHERE m.alumno_id IN ('.$huecos.') AND m.deleted_at IS NULL
             ) x
             INNER JOIN years y ON y.id = x.year_id
             WHERE x.puesto = 1', $ids);

        $notas = DB::select(
            'SELECT alumno_id, COUNT(*) AS definitivas FROM notas_finales
             WHERE alumno_id IN ('.$huecos.') GROUP BY alumno_id', $ids);

        $contexto = [];
        foreach ($filas as $f) {
            $contexto[(int) $f->alumno_id] = [
                'matriculas'    => (int) $f->matriculas,
                'ultimo_year'   => (int) $f->ultimo_year,
                'ultimo_grupo'  => $f->ultimo_grupo,
                'ultimo_estado' => $f->ultimo_estado,
            ];
        }
        foreach ($notas as $n) {
            $contexto[(int) $n->alumno_id]['definitivas'] = (int) $n->definitivas;
        }

        return $contexto;
    }

    /** El `SELECT` de las tres consultas. El primer `?` es el grado, para no repetirlo fuera. */
    private const SELECT = 'SELECT a.id, a.nombres, a.apellidos, a.documento, a.sexo, a.fecha_nac,
            a.deleted_at, i.nombre AS foto_nombre, td.abrev AS tipo_doc_nombre, ? AS coincidencia
        FROM alumnos a
        LEFT JOIN images i ON i.id = a.foto_id AND i.deleted_at IS NULL
        LEFT JOIN tipos_documentos td ON td.id = a.tipo_doc';
}
