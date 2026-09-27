<?php

namespace App\Services;

use App\Support\AuditarFila;
use App\Support\NotaDeOtroColegio;
use App\Support\ParecidoDeNombres;
use App\Support\Reloj;
use Illuminate\Support\Facades\DB;

/**
 * BOLETINES DE OTROS COLEGIOS, EN LOTE  *(26 sep 2026)*, `myvc_front/NOTAS-DE-OTRO-COLEGIO.md` §11.
 *
 * Las tres puertas --la IA personal del secretario, el chat de MyVc y el guion de Joseth-- traen lo
 * mismo: filas con el formato de §11.1, una por nota. Esto es lo que viene después, y se escribe
 * una sola vez:
 *
 *   1. AGRUPAR por (alumno, año): cada grupo es un boletín.
 *   2. EMPAREJAR el alumno --por documento exacto y, si no, por parecido de nombre--, la materia
 *      --por la equivalencia aprendida, por el nombre exacto o por parecido-- y el grado.
 *   3. CONVERTIR cada nota a la escala de este colegio (`NotaDeOtroColegio`).
 *   4. COMPARAR con lo que ya hay: el mismo alumno y el mismo año ya guardados, o la misma materia
 *      dos veces en lo pegado. **Lo repetido no se resuelve solo**: se enseñan las diferencias y
 *      quien trae los boletines elige cómo queda (pedido por Joseth el 26 sep).
 *
 * `ensayo()` hace 1-4 y no escribe nada. `aplicar()` vuelve a hacer el ensayo --lo que se escribe
 * sale de la base de ahora, no de lo que la pantalla vio hace un rato-- y aplica las decisiones.
 */
class LoteDeOtrosColegios
{
    /** Desde aquí el alumno emparejado por nombre se da por bueno sin preguntar. */
    public const NOMBRE_SEGURO = 0.92;

    /**
     * Desde aquí una materia se SUGIERE (nunca se empareja sola). Más bajo que el de personas: los
     * catálogos juntan varias en un nombre largo --«Ciencias naturales y educación ambiental»-- y
     * «Ciencias Naturales» se queda en 0,5 contra él. Medido en el docker el 26 sep.
     */
    public const MATERIA_SUGERIDA = 0.4;

    /** @var array<int, string> id => «nombres apellidos» */
    private array $alumnos = [];

    /** @var array<string, int> documento => id */
    private array $porDocumento = [];

    /** @var array<int, string> id => nombre de la materia */
    private array $materias = [];

    /** @var array<string, int> texto normalizado => materia_id */
    private array $equivalencias = [];

    /** @var array<int, string> id => nombre del grado */
    private array $grados = [];

    private ?array $destino;

    public function __construct()
    {
        foreach (DB::select('SELECT id, nombres, apellidos, documento FROM alumnos WHERE deleted_at IS NULL') as $a) {
            $this->alumnos[(int) $a->id] = trim($a->nombres.' '.($a->apellidos ?? ''));
            $doc = self::documento($a->documento);
            if ($doc !== '') {
                $this->porDocumento[$doc] ??= (int) $a->id;
            }
        }
        foreach (DB::select('SELECT id, materia FROM materias WHERE deleted_at IS NULL') as $m) {
            $this->materias[(int) $m->id] = (string) $m->materia;
        }
        foreach (DB::select('SELECT texto, materia_id FROM equivalencias_materias') as $e) {
            $this->equivalencias[$e->texto] = (int) $e->materia_id;
        }
        foreach (DB::select('SELECT id, nombre FROM grados WHERE deleted_at IS NULL') as $g) {
            $this->grados[(int) $g->id] = (string) $g->nombre;
        }
        $this->destino = NotaDeOtroColegio::destino();
    }

    /* ── 1. el ensayo ─────────────────────────────────────────────────────────────────────── */

    /**
     * @param  list<array<string, mixed>>  $filas  el formato de §11.1
     * @return array{grupos: list<array<string, mixed>>, resumen: array<string, int>}
     */
    public function ensayo(array $filas, array $decisiones = []): array
    {
        if ($this->destino === null) {
            abort(422, 'Este colegio no tiene escala de valoración en el año actual: no hay a qué convertir.');
        }

        $grupos = [];
        foreach (array_values($filas) as $n => $fila) {
            $fila = $this->limpiar($fila);
            if ($fila['alumno'] === '' && $fila['documento'] === '') {
                continue;
            }
            if ($fila['materia'] === '' || $fila['nota'] === '') {
                continue;
            }

            $clave = ($fila['documento'] !== '' ? 'doc:'.$fila['documento'] : 'nom:'.ParecidoDeNombres::normalizar($fila['alumno'])).'|'.$fila['year'];
            $grupos[$clave] ??= ['clave' => $clave, 'filas' => [], 'primera' => $fila];
            $grupos[$clave]['filas'][] = ['n' => $n] + $fila;
        }

        /* El alumno que eligió quien revisa manda: con él se busca lo que ya hay guardado. */
        $salida = array_map(fn (array $g) => $this->grupo($g, (int) ($decisiones[$g['clave']]['alumno_id'] ?? 0) ?: null), array_values($grupos));

        return [
            'grupos' => $salida,
            'resumen' => [
                'grupos' => count($salida),
                'filas' => array_sum(array_map(fn ($g) => count($g['filas']), $salida)),
                'alumnos_sin_emparejar' => count(array_filter($salida, fn ($g) => $g['alumno'] === null)),
                'materias_sin_emparejar' => array_sum(array_map(
                    fn ($g) => count(array_filter($g['filas'], fn ($f) => $f['materia'] === null)), $salida)),
                'repetidos' => count(array_filter($salida, fn ($g) => $g['existente'] !== null || $g['repetidas'] !== [])),
            ],
        ];
    }

    private function grupo(array $g, ?int $elegido = null): array
    {
        $p = $g['primera'];
        [$alumno, $candidatos] = $this->emparejarAlumno($p['documento'], $p['alumno']);
        if ($elegido !== null && isset($this->alumnos[$elegido])) {
            $alumno = $this->alumnoBreve($elegido, 1.0, 'elegido');
        }
        $escala = $this->escala($p);

        $filas = array_map(function (array $f) use ($escala) {
            [$materia, $candidatos] = $this->emparejarMateria($f['materia']);
            $convertida = $escala === null
                ? ['nota' => null, 'desempenio' => null]
                : NotaDeOtroColegio::convertir($f['nota'], $escala, $this->destino);

            return [
                'n' => $f['n'],
                'materia_texto' => $f['materia'],
                'materia' => $materia,
                'candidatos' => $candidatos,
                'nota_original' => $f['nota'],
                'nota' => $convertida['nota'],
                'desempenio' => $convertida['desempenio'],
                'archivo' => $f['archivo'] ?: null,
            ];
        }, $g['filas']);

        $dudas = [];
        if ($alumno === null) {
            $dudas[] = $candidatos
                ? 'El alumno no es seguro: elige cuál de los parecidos es.'
                : 'No hay ningún alumno con ese documento ni con un nombre parecido.';
        }
        if ($escala === null) {
            $dudas[] = 'Falta la escala del otro colegio (mínima, máxima y con cuánto se aprueba).';
        }

        $existente = $alumno === null ? null : $this->existente($alumno['id'], $p['year']);

        return [
            'clave' => $g['clave'],
            'year' => $p['year'],
            'alumno_texto' => $p['alumno'],
            'documento' => $p['documento'] ?: null,
            'alumno' => $alumno,
            'candidatos' => $candidatos,
            'colegio' => $p['colegio'] ?: null,
            'municipio' => $p['municipio'] ?: null,
            'grado_texto' => $p['grado'] ?: null,
            'grado_id' => $this->emparejarGrado($p['grado']),
            'escala' => $escala,
            'filas' => $filas,
            'repetidas' => $this->repetidas($filas),
            'existente' => $existente,
            'comparacion' => $existente === null ? null : $this->comparar($existente, $p, $escala, $filas),
            'opciones' => $existente === null
                ? ['crear', 'omitir']
                : array_values(array_filter([
                    'conservar', 'reemplazar',
                    $this->mismaEscala($existente['escala'], $escala) ? 'combinar' : null,
                ])),
            'cursado_aqui' => $alumno !== null && $this->cursadoAqui($alumno['id'], $p['year']),
            'dudas' => $dudas,
        ];
    }

    /* ── 2. emparejar ─────────────────────────────────────────────────────────────────────── */

    /** @return array{0: ?array, 1: list<array>} el alumno seguro, o null y los candidatos */
    private function emparejarAlumno(string $documento, string $nombre): array
    {
        if ($documento !== '' && isset($this->porDocumento[$documento])) {
            $id = $this->porDocumento[$documento];

            return [$this->alumnoBreve($id, 1.0, 'documento'), []];
        }

        if ($nombre === '') {
            return [null, []];
        }

        $mejores = ParecidoDeNombres::mejores($nombre, $this->alumnos);
        $candidatos = array_map(fn ($m) => $this->alumnoBreve((int) $m['clave'], round($m['parecido'], 2), 'nombre'), $mejores);

        /* Seguro sólo si es muy parecido Y no hay otro casi igual: dos «Juan Pérez» se preguntan. */
        $primero = $mejores[0] ?? null;
        $segundo = $mejores[1] ?? null;
        if ($primero && $primero['parecido'] >= self::NOMBRE_SEGURO
            && (! $segundo || $primero['parecido'] - $segundo['parecido'] >= 0.05)) {
            return [$candidatos[0], []];
        }

        return [null, $candidatos];
    }

    private function alumnoBreve(int $id, float $parecido, string $como): array
    {
        $doc = array_search($id, $this->porDocumento, true);

        return ['id' => $id, 'nombre' => $this->alumnos[$id] ?? '', 'documento' => $doc === false ? null : (string) $doc,
            'parecido' => $parecido, 'como' => $como];
    }

    /** @return array{0: ?array, 1: list<array>} */
    private function emparejarMateria(string $texto): array
    {
        $llano = ParecidoDeNombres::normalizar($texto);

        if (isset($this->equivalencias[$llano], $this->materias[$this->equivalencias[$llano]])) {
            $id = $this->equivalencias[$llano];

            return [['id' => $id, 'nombre' => $this->materias[$id], 'como' => 'aprendida'], []];
        }

        foreach ($this->materias as $id => $nombre) {
            if (ParecidoDeNombres::normalizar($nombre) === $llano) {
                return [['id' => $id, 'nombre' => $nombre, 'como' => 'exacta'], []];
            }
        }

        /*
         * «CIENCIAS NATURALES» DENTRO DE «CIENCIAS NATURALES Y EDUCACIÓN AMBIENTAL» VA PRIMERO. El
         * parecido de las cadenas enteras ponía delante «CIENCIAS ECONÓMICAS» (más corta, casi igual
         * de larga que lo escrito), y la pantalla ofrecía esa con «Usar». Si todas las palabras
         * escritas están en el nombre del catálogo, sube a 0,9. Medido en el docker el 26 sep.
         */
        $escritas = ParecidoDeNombres::palabras($texto);
        $puntuadas = [];
        foreach ($this->materias as $id => $nombre) {
            $parecido = ParecidoDeNombres::entre($texto, $nombre);
            if ($escritas !== [] && array_diff($escritas, ParecidoDeNombres::palabras($nombre)) === []) {
                $parecido = max($parecido, 0.9);
            }
            if ($parecido >= self::MATERIA_SUGERIDA) {
                $puntuadas[] = ['id' => $id, 'nombre' => $nombre, 'parecido' => round($parecido, 2)];
            }
        }
        usort($puntuadas, static fn ($a, $b) => $b['parecido'] <=> $a['parecido'] ?: $a['id'] <=> $b['id']);

        return [null, array_slice($puntuadas, 0, ParecidoDeNombres::CUANTOS)];
    }

    private function emparejarGrado(string $texto): ?int
    {
        if ($texto === '') {
            return null;
        }
        $mejor = ParecidoDeNombres::mejores($texto, $this->grados, 0.8, 1)[0] ?? null;

        return $mejor ? (int) $mejor['clave'] : null;
    }

    /* ── 4. lo repetido ───────────────────────────────────────────────────────────────────── */

    /** La clave con la que se comparan dos notas: la materia emparejada, o el texto si no hay. */
    private static function claveDeMateria(?int $materiaId, string $texto): string
    {
        return $materiaId !== null ? 'm:'.$materiaId : 't:'.ParecidoDeNombres::normalizar($texto);
    }

    /** La misma materia dos veces en lo pegado, con notas distintas. Iguales no son conflicto. */
    private function repetidas(array $filas): array
    {
        $por = [];
        foreach ($filas as $f) {
            $por[self::claveDeMateria($f['materia']['id'] ?? null, $f['materia_texto'])][] = $f;
        }

        $salida = [];
        foreach ($por as $clave => $mismas) {
            $notas = array_unique(array_map(fn ($f) => $f['nota_original'], $mismas));
            if (count($mismas) > 1 && count($notas) > 1) {
                $salida[] = [
                    'clave' => $clave,
                    'materia' => $mismas[0]['materia']['nombre'] ?? $mismas[0]['materia_texto'],
                    'opciones' => array_map(fn ($f) => ['n' => $f['n'], 'nota_original' => $f['nota_original'], 'archivo' => $f['archivo']], $mismas),
                ];
            }
        }

        return $salida;
    }

    private function existente(int $alumnoId, int $year): ?array
    {
        $ano = DB::selectOne('SELECT ae.*, g.nombre AS grado_nombre FROM anos_externos ae
            LEFT JOIN grados g ON g.id = ae.grado_id
            WHERE ae.alumno_id = ? AND ae.year = ? AND ae.deleted_at IS NULL ORDER BY ae.id LIMIT 1', [$alumnoId, $year]);

        if (! $ano) {
            return null;
        }

        return [
            'id' => (int) $ano->id,
            'colegio' => $ano->propio ? 'Este colegio' : $ano->colegio_nombre,
            'municipio' => $ano->colegio_municipio,
            'grado' => $ano->grado_nombre ?? $ano->grado_texto,
            'escala' => $ano->escala_min === null ? null
                : ['min' => (float) $ano->escala_min, 'max' => (float) $ano->escala_max, 'aprueba' => (float) $ano->escala_aprueba],
            'notas' => array_map(fn ($n) => [
                'id' => (int) $n->id,
                'materia_id' => $n->materia_id === null ? null : (int) $n->materia_id,
                'asignatura_texto' => $n->asignatura_texto,
                'nota_original' => $n->nota_original,
                'nota' => $n->nota === null ? null : (float) $n->nota,
            ], DB::select('SELECT id, materia_id, asignatura_texto, nota_original, nota FROM notas_externas
                WHERE ano_externo_id = ? AND deleted_at IS NULL ORDER BY orden, id', [$ano->id])),
        ];
    }

    /**
     * LO QUE HAY FRENTE A LO QUE LLEGA, materia por materia y en la cabecera. Cada materia sale en
     * uno de cuatro estados: igual, distinta, sólo en lo que hay, sólo en lo nuevo.
     */
    private function comparar(array $hay, array $p, ?array $escala, array $filas): array
    {
        $encabezado = [];
        foreach ([
            ['campo' => 'Colegio', 'hay' => $hay['colegio'], 'nuevo' => $p['colegio'] ?: null],
            ['campo' => 'Grado', 'hay' => $hay['grado'], 'nuevo' => $p['grado'] ?: null],
            ['campo' => 'Escala', 'hay' => self::textoEscala($hay['escala']), 'nuevo' => self::textoEscala($escala)],
        ] as $c) {
            $distinto = ParecidoDeNombres::normalizar((string) $c['hay']) !== ParecidoDeNombres::normalizar((string) $c['nuevo']);
            $encabezado[] = $c + ['distinto' => $distinto];
        }

        $materias = [];
        foreach ($hay['notas'] as $n) {
            $clave = self::claveDeMateria($n['materia_id'], $n['asignatura_texto']);
            $materias[$clave] = ['clave' => $clave, 'asignatura' => $n['asignatura_texto'], 'hay' => $n['nota_original'], 'nuevo' => null];
        }
        foreach ($filas as $f) {
            $clave = self::claveDeMateria($f['materia']['id'] ?? null, $f['materia_texto']);
            $materias[$clave] ??= ['clave' => $clave, 'asignatura' => $f['materia']['nombre'] ?? $f['materia_texto'], 'hay' => null, 'nuevo' => null];
            $materias[$clave]['nuevo'] ??= $f['nota_original'];
        }

        foreach ($materias as &$m) {
            $m['estado'] = match (true) {
                $m['hay'] === null => 'solo_nuevo',
                $m['nuevo'] === null => 'solo_hay',
                ParecidoDeNombres::normalizar((string) $m['hay']) === ParecidoDeNombres::normalizar((string) $m['nuevo']) => 'igual',
                default => 'distinta',
            };
        }

        return [
            'encabezado' => $encabezado,
            'materias' => array_values($materias),
            'iguales' => count(array_filter($materias, fn ($m) => $m['estado'] === 'igual')) === count($materias)
                && ! array_filter($encabezado, fn ($c) => $c['distinto']),
        ];
    }

    private function cursadoAqui(int $alumnoId, int $year): bool
    {
        return DB::selectOne('SELECT 1 AS si FROM matriculas m
            INNER JOIN grupos g ON g.id = m.grupo_id
            INNER JOIN years y ON y.id = g.year_id
            WHERE m.alumno_id = ? AND y.year = ? AND m.deleted_at IS NULL LIMIT 1', [$alumnoId, $year]) !== null;
    }

    /* ── 5. aplicar ───────────────────────────────────────────────────────────────────────── */

    /**
     * Las decisiones van por grupo, con su `clave`:
     *
     *   accion      crear | omitir, o con uno existente: conservar | reemplazar | combinar
     *   alumno_id   el elegido entre los candidatos, cuando el emparejamiento no era seguro
     *   materias    { n de la fila: materia_id } para las que no se emparejaron o se corrigen
     *   repetidas   { clave de materia: n de la fila que se queda }
     *   combinar    { clave de materia: 'hay' | 'nuevo' } para las distintas
     *
     * Todo o nada: un grupo sin decidir aborta el lote entero con el porqué, antes de escribir.
     *
     * @return array{creados: int, reemplazados: int, combinados: int, conservados: int, omitidos: int, notas: int, aprendidas: int, anos: array<string, int>}
     */
    public function aplicar(array $filas, array $decisiones, int $userId): array
    {
        $ensayo = $this->ensayo($filas, $decisiones);
        $cuenta = ['creados' => 0, 'reemplazados' => 0, 'combinados' => 0, 'conservados' => 0, 'omitidos' => 0, 'notas' => 0, 'aprendidas' => 0, 'alumnos_nuevos' => 0,
            /* clave del grupo => id del año escrito: con él se archiva después la foto o el PDF (`postSubirDocumento`). */
            'anos' => []];
        $plan = [];

        foreach ($ensayo['grupos'] as $g) {
            $d = (array) ($decisiones[$g['clave']] ?? []);
            $accion = (string) ($d['accion'] ?? ($g['existente'] === null ? 'crear' : ''));

            if ($accion === 'omitir' || $accion === 'conservar') {
                $cuenta[$accion === 'omitir' ? 'omitidos' : 'conservados']++;

                continue;
            }

            $quien = "«{$g['alumno_texto']}» ({$g['year']})";

            $nuevo = $this->alumnoNuevo($d, $quien);
            $alumnoId = $nuevo !== null ? null
                : (isset($d['alumno_id']) ? (int) $d['alumno_id'] : ($g['alumno']['id'] ?? null));
            if ($nuevo === null && (! $alumnoId || ! isset($this->alumnos[$alumnoId]))) {
                abort(422, "Falta elegir el alumno de {$quien}, o crearlo.");
            }
            if ($g['escala'] === null) {
                abort(422, "Falta la escala del otro colegio en {$quien}.");
            }

            /* Si el alumno lo eligió quien revisa, lo que había se vuelve a mirar con ESE alumno. Uno nuevo no tiene nada. */
            $existente = $nuevo !== null ? null
                : ($alumnoId === ($g['alumno']['id'] ?? null) ? $g['existente'] : $this->existente($alumnoId, $g['year']));
            if ($existente !== null && ! in_array($accion, ['reemplazar', 'combinar'], true)) {
                abort(422, "{$quien} ya tiene ese año guardado: elige conservar, reemplazar o combinar.");
            }
            if ($existente === null && $accion !== 'crear') {
                $accion = 'crear';
            }
            if ($accion === 'combinar' && ! $this->mismaEscala($existente['escala'], $g['escala'])) {
                abort(422, "{$quien}: las escalas son distintas y no se pueden combinar; elige reemplazar o conservar.");
            }

            $nuevas = $this->notasElegidas($g, $d, $quien);
            $plan[] = compact('g', 'd', 'accion', 'alumnoId', 'nuevo', 'existente', 'nuevas');
        }

        DB::transaction(function () use ($plan, $userId, &$cuenta) {
            /*
             * UN ALUMNO NUEVO SE CREA UNA VEZ aunque traiga varios años --un certificado de tres años
             * son tres grupos--: la segunda vez se reconoce por su documento o, sin él, por el nombre.
             */
            $creados = [];
            foreach ($plan as ['g' => $g, 'd' => $d, 'accion' => $accion, 'alumnoId' => $alumnoId, 'nuevo' => $nuevo, 'existente' => $existente, 'nuevas' => $nuevas]) {
                $ahora = Reloj::ahora();

                if ($nuevo !== null) {
                    $llave = $nuevo['documento'] ?? ParecidoDeNombres::normalizar($nuevo['nombres'].' '.$nuevo['apellidos']);
                    if (! isset($creados[$llave])) {
                        $creados[$llave] = $this->crearAlumno($nuevo, $userId);
                        $cuenta['alumnos_nuevos']++;
                    }
                    $alumnoId = $creados[$llave];
                }

                if ($accion === 'crear') {
                    $anoId = DB::table('anos_externos')->insertGetId($this->cabecera($g, $userId) + [
                        'alumno_id' => $alumnoId, 'year' => $g['year'], 'propio' => false,
                        'created_by' => $userId, 'created_at' => $ahora, 'updated_at' => $ahora,
                    ]);
                    $cuenta['creados']++;
                } else {
                    $anoId = $existente['id'];
                }

                if ($accion === 'combinar') {
                    /* Se quedan las de lo que hay que no se reemplazan; entran las nuevas elegidas. */
                    $eleccion = (array) ($d['combinar'] ?? []);
                    $claveDe = fn ($n) => self::claveDeMateria($n['materia_id'], $n['asignatura_texto']);
                    $nuevasPorClave = [];
                    foreach ($nuevas as $n) {
                        $nuevasPorClave[$claveDe($n)] = $n;
                    }

                    $retirar = [];
                    foreach ($existente['notas'] as $vieja) {
                        $clave = $claveDe($vieja);
                        if (isset($nuevasPorClave[$clave]) && ($eleccion[$clave] ?? 'hay') === 'nuevo') {
                            $retirar[] = $vieja['id'];
                        } elseif (isset($nuevasPorClave[$clave])) {
                            unset($nuevasPorClave[$clave]);
                        }
                    }
                    if ($retirar) {
                        DB::table('notas_externas')->whereIn('id', $retirar)->update(['deleted_at' => $ahora]);
                    }
                    $nuevas = array_values($nuevasPorClave);
                    $orden = count($existente['notas']);
                    $cuenta['combinados']++;
                } else {
                    if ($accion === 'reemplazar') {
                        DB::table('notas_externas')->where('ano_externo_id', $anoId)->whereNull('deleted_at')->update(['deleted_at' => $ahora]);
                        DB::table('anos_externos')->where('id', $anoId)->update($this->cabecera($g, $userId) + ['updated_at' => $ahora]);
                        $cuenta['reemplazados']++;
                    }
                    $orden = 0;
                }

                foreach ($nuevas as $i => $n) {
                    DB::table('notas_externas')->insert($n + [
                        'ano_externo_id' => $anoId, 'orden' => $orden + $i,
                        'created_by' => $userId, 'created_at' => $ahora, 'updated_at' => $ahora,
                    ]);
                }
                $cuenta['anos'][$g['clave']] = (int) $anoId;
                $cuenta['notas'] += count($nuevas);
                $cuenta['aprendidas'] += $this->aprender($g, $d, $userId);
            }
        });

        return $cuenta;
    }

    /**
     * EL ALUMNO QUE NO ESTÁ EN EL COLEGIO  *(27 sep 2026, pedido)*: un boletín de alguien que no
     * tiene ficha --llega nuevo, o nunca se matriculó aquí--. Se crea la ficha MÍNIMA: nombres,
     * apellidos, sexo (la columna no admite nulo) y documento si lo hay. Sin usuario ni matrícula: eso
     * es matricularlo, y va por su pantalla. Si luego resulta repetido, `/duplicados` lo une.
     *
     * @return array{nombres: string, apellidos: string, sexo: string, documento: ?string}|null
     */
    private function alumnoNuevo(array $d, string $quien): ?array
    {
        $n = $d['alumno_nuevo'] ?? null;
        if (! is_array($n)) {
            return null;
        }

        $nombres = mb_strtoupper(trim((string) ($n['nombres'] ?? '')));
        $apellidos = mb_strtoupper(trim((string) ($n['apellidos'] ?? '')));
        $sexo = strtoupper(trim((string) ($n['sexo'] ?? '')));
        $documento = self::documento($n['documento'] ?? '');

        if ($nombres === '' || $apellidos === '' || ! in_array($sexo, ['M', 'F'], true)) {
            abort(422, "Para crear el alumno de {$quien} faltan los nombres, los apellidos o el sexo.");
        }
        if ($documento !== '' && isset($this->porDocumento[$documento])) {
            abort(422, "{$quien}: ya hay un alumno con el documento {$documento}; elígelo en vez de crearlo.");
        }

        return ['nombres' => mb_substr($nombres, 0, 60), 'apellidos' => mb_substr($apellidos, 0, 60),
            'sexo' => $sexo, 'documento' => $documento === '' ? null : $documento];
    }

    private function crearAlumno(array $nuevo, int $userId): int
    {
        $ahora = Reloj::ahora();
        $id = (int) DB::table('alumnos')->insertGetId($nuevo + [
            'created_by' => $userId, 'created_at' => $ahora, 'updated_at' => $ahora,
        ]);
        AuditarFila::creada('alumno', 'alumnos', $id, null,
            'Creó el alumno «'.$nuevo['nombres'].' '.$nuevo['apellidos'].'» al traer boletines de otros colegios');

        return $id;
    }

    /** Las notas que se van a escribir de un grupo: repetidas resueltas y materias corregidas. */
    private function notasElegidas(array $g, array $d, string $quien): array
    {
        $materiasElegidas = (array) ($d['materias'] ?? []);
        $repetidasElegidas = (array) ($d['repetidas'] ?? []);
        $conflictivas = [];
        foreach ($g['repetidas'] as $r) {
            $conflictivas[$r['clave']] = $r;
        }

        $salida = [];
        $vistas = [];
        foreach ($g['filas'] as $f) {
            $materiaId = isset($materiasElegidas[$f['n']]) ? ((int) $materiasElegidas[$f['n']] ?: null) : ($f['materia']['id'] ?? null);
            $clave = self::claveDeMateria($f['materia']['id'] ?? null, $f['materia_texto']);

            if (isset($conflictivas[$clave])) {
                if (! isset($repetidasElegidas[$clave])) {
                    abort(422, "{$quien}: «{$conflictivas[$clave]['materia']}» viene repetida con notas distintas; elige cuál se queda.");
                }
                if ((int) $repetidasElegidas[$clave] !== $f['n']) {
                    continue;
                }
            } elseif (isset($vistas[$clave])) {
                continue;   // repetida con la misma nota: basta una
            }
            $vistas[$clave] = true;

            $salida[] = [
                'materia_id' => $materiaId,
                'area_texto' => null,
                'asignatura_texto' => mb_substr($f['materia_texto'], 0, 160),
                'intensidad' => null,
                'nota_original' => mb_substr($f['nota_original'], 0, 12),
                'nota' => $f['nota'],
                'desempenio' => $f['desempenio'],
            ];
        }

        return $salida;
    }

    /** Aprende las materias que quien revisa emparejó a mano, y las parecidas que confirmó. */
    private function aprender(array $g, array $d, int $userId): int
    {
        $nuevas = 0;
        $elegidas = (array) ($d['materias'] ?? []);
        foreach ($g['filas'] as $f) {
            $materiaId = isset($elegidas[$f['n']]) ? (int) $elegidas[$f['n']] : null;
            $texto = ParecidoDeNombres::normalizar($f['materia_texto']);
            if (! $materiaId || $texto === '' || isset($this->equivalencias[$texto])) {
                continue;
            }

            DB::table('equivalencias_materias')->insertOrIgnore([
                'texto' => mb_substr($texto, 0, 160), 'materia_id' => $materiaId,
                'created_by' => $userId, 'created_at' => Reloj::ahora(), 'updated_at' => Reloj::ahora(),
            ]);
            $this->equivalencias[$texto] = $materiaId;
            $nuevas++;
        }

        return $nuevas;
    }

    private function cabecera(array $g, int $userId): array
    {
        return [
            'grado_id' => $g['grado_id'],
            'grado_texto' => $g['grado_texto'] === null ? null : mb_substr($g['grado_texto'], 0, 60),
            'colegio_nombre' => $g['colegio'] === null ? null : mb_substr($g['colegio'], 0, 160),
            'colegio_municipio' => $g['municipio'] === null ? null : mb_substr($g['municipio'], 0, 80),
            'escala_min' => $g['escala']['min'], 'escala_max' => $g['escala']['max'], 'escala_aprueba' => $g['escala']['aprueba'],
            'updated_by' => $userId,
        ];
    }

    /* ── utilidades ───────────────────────────────────────────────────────────────────────── */

    private function limpiar(array $f): array
    {
        $t = fn (string $k) => trim((string) ($f[$k] ?? ''));

        return [
            'documento' => self::documento($f['documento'] ?? ''),
            'alumno' => $t('alumno'),
            'year' => (int) ($f['year'] ?? $f['año'] ?? 0),
            'grado' => $t('grado'),
            'colegio' => $t('colegio'),
            'municipio' => $t('municipio'),
            'materia' => $t('materia'),
            'nota' => $t('nota'),
            'escala_min' => $f['escala_min'] ?? null,
            'escala_max' => $f['escala_max'] ?? null,
            'escala_aprueba' => $f['escala_aprueba'] ?? null,
            'archivo' => $t('archivo'),
        ];
    }

    private function escala(array $p): ?array
    {
        $num = fn ($v) => is_numeric(str_replace(',', '.', (string) $v)) ? (float) str_replace(',', '.', (string) $v) : null;
        $min = $num($p['escala_min']);
        $max = $num($p['escala_max']);
        $aprueba = $num($p['escala_aprueba']);

        if ($min === null || $max === null || $aprueba === null || $max <= $min) {
            return null;
        }

        return ['min' => $min, 'max' => $max, 'aprueba' => $aprueba];
    }

    private function mismaEscala(?array $a, ?array $b): bool
    {
        return $a !== null && $b !== null
            && abs($a['min'] - $b['min']) < 0.001 && abs($a['max'] - $b['max']) < 0.001 && abs($a['aprueba'] - $b['aprueba']) < 0.001;
    }

    private static function textoEscala(?array $e): ?string
    {
        return $e === null ? null : sprintf('%s a %s, aprueba con %s', +$e['min'], +$e['max'], +$e['aprueba']);
    }

    /** El documento sin puntos, espacios ni guiones: «1.085.234.567» y «1085234567» son el mismo. */
    private static function documento($v): string
    {
        return preg_replace('/[^0-9A-Za-z]/', '', (string) $v) ?? '';
    }
}
