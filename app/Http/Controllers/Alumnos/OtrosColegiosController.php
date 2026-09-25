<?php

namespace App\Http\Controllers\Alumnos;

use App\Http\Controllers\Concerns\ResuelveElUsuario;
use App\Http\Controllers\Controller;
use App\Models\EscalaDeValoracion;
use App\Models\Year;
use App\Support\Autoriza;
use App\Support\NotaDeOtroColegio;
use App\Support\SafeUpload;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Str;

/**
 * EL ARCHIVO DE OTROS COLEGIOS Y DE AÑOS ANTIGUOS DE UN ALUMNO  *(24 sep 2026)*.
 *
 * Los años que un alumno cursó en otro colegio --o en éste, antes de MYVC-- y el boletín o
 * certificado de cada uno. Es el nivel 1 de `myvc_front/NOTAS-DE-OTRO-COLEGIO.md` §8: se guarda
 * el papel, no se leen notas. Tablas en la migración `2026_09_24_990000_el_archivo_de_otros_colegios`.
 *
 * **QUIÉN: quien edita alumnos** (`Autoriza::puedeEditarAlumnos`), para subir Y para ver. Lo
 * decidió Joseth: son boletines de un menor con notas de otro colegio.
 *
 * **LOS FICHEROS VAN EN `storage/`, NO EN `public/`.** Todo lo que el colegio sube hoy acaba en
 * `public/` y se sirve por URL sin token; eso vale para un logo, no para el certificado de un
 * alumno. Aquí se guardan en `storage/app/archivo-alumnos/{alumno_id}/` con un nombre aleatorio y
 * sólo salen por `getDocumento`, que pide token y permiso. La validación de la subida es la de
 * las colillas de inscripción (`ColillasInscripcionController::guardarElArchivoSiVino`).
 */
class OtrosColegiosController extends Controller
{
    use ResuelveElUsuario;

    private const EXTENSIONES = ['jpg', 'jpeg', 'png', 'pdf'];

    private const TIPOS = ['image/jpeg', 'image/png', 'application/pdf'];

    /** 10 MB: un certificado escaneado de varias páginas en PDF cabe. */
    private const MAXIMO_BYTES = 10 * 1024 * 1024;

    private function exigirPermiso(): void
    {
        Autoriza::exigir(Autoriza::puedeEditarAlumnos($this->user),
            'Sólo quien edita alumnos puede ver y subir los documentos de otros colegios.');
    }

    /** Los años del alumno, del más viejo al más nuevo, cada uno con sus documentos. */
    public function getDeAlumno($alumno_id)
    {
        $this->exigirPermiso();

        $anos = DB::select('SELECT ae.*, g.nombre AS grado_nombre,
                (SELECT COUNT(*) FROM notas_externas ne WHERE ne.ano_externo_id = ae.id AND ne.deleted_at IS NULL) AS notas
            FROM anos_externos ae
            LEFT JOIN grados g ON g.id = ae.grado_id
            WHERE ae.alumno_id = ? AND ae.deleted_at IS NULL
            ORDER BY ae.year, ae.id', [$alumno_id]);

        $documentos = DB::select('SELECT id, ano_externo_id, nombre_original, tipo, bytes, created_at
            FROM documentos_externos
            WHERE alumno_id = ? AND deleted_at IS NULL
            ORDER BY id', [$alumno_id]);

        foreach ($anos as $ano) {
            $ano->propio = (bool) $ano->propio;
            $ano->notas = (int) $ano->notas;
            $ano->documentos = array_values(array_filter($documentos,
                fn ($d) => (int) $d->ano_externo_id === (int) $ano->id));
        }

        return $anos;
    }

    public function postCrear($alumno_id)
    {
        $this->exigirPermiso();

        $datos = $this->datosDelAno();
        $datos['alumno_id'] = (int) $alumno_id;
        $datos['created_by'] = $this->user->user_id;
        $datos['created_at'] = $datos['updated_at'] = now();

        $id = DB::table('anos_externos')->insertGetId($datos);

        return ['id' => $id];
    }

    public function putActualizar($id)
    {
        $this->exigirPermiso();
        $this->anoOFallar($id);

        $datos = $this->datosDelAno();
        $datos['updated_by'] = $this->user->user_id;
        $datos['updated_at'] = now();

        DB::table('anos_externos')->where('id', $id)->update($datos);

        return ['id' => (int) $id];
    }

    /** Borrado suave del año y de sus documentos. El fichero se queda en disco: se puede recuperar. */
    public function deleteAno($id)
    {
        $this->exigirPermiso();
        $this->anoOFallar($id);

        DB::table('anos_externos')->where('id', $id)->update(['deleted_at' => now()]);
        DB::table('documentos_externos')->where('ano_externo_id', $id)->whereNull('deleted_at')
            ->update(['deleted_at' => now()]);

        return ['id' => (int) $id];
    }

    public function postSubirDocumento($id)
    {
        $this->exigirPermiso();
        $ano = $this->anoOFallar($id);

        $file = SafeUpload::archivoRecibido('file');

        if ($file->getSize() > self::MAXIMO_BYTES) {
            abort(422, 'El archivo pasa de 10 MB. Escanéalo con menos resolución o pártelo.');
        }

        $tipo = (string) $file->getMimeType();

        if (! in_array($tipo, self::TIPOS, true)) {
            abort(422, 'Sólo se aceptan PDF, JPG o PNG.');
        }

        $carpeta = storage_path('app/archivo-alumnos/'.(int) $ano->alumno_id);

        if (! File::exists($carpeta)) {
            File::makeDirectory($carpeta, 0750, true, true);
        }

        // Valida la extensión declarada y la real; el nombre se genera, nunca se hereda.
        $validado = SafeUpload::nombreDisponible($file, $carpeta, self::EXTENSIONES);
        $extension = strtolower((string) pathinfo($validado, PATHINFO_EXTENSION));
        $archivo = Str::random(40).'.'.$extension;
        $bytes = (int) $file->getSize();
        $original = mb_substr((string) $file->getClientOriginalName(), 0, 200);

        $file->move($carpeta, $archivo);

        $documento = DB::table('documentos_externos')->insertGetId([
            'ano_externo_id' => (int) $id,
            'alumno_id' => (int) $ano->alumno_id,
            'nombre_original' => $original !== '' ? $original : $archivo,
            'archivo' => $archivo,
            'tipo' => $tipo,
            'bytes' => $bytes,
            'created_by' => $this->user->user_id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['id' => $documento];
    }

    /** El fichero, con token y permiso. `inline` para que un PDF o una foto se vean en el navegador. */
    public function getDocumento($id)
    {
        $this->exigirPermiso();

        $documento = DB::selectOne('SELECT * FROM documentos_externos WHERE id = ? AND deleted_at IS NULL', [$id]);

        if (! $documento) {
            abort(404, 'Ese documento no existe o fue borrado.');
        }

        $ruta = storage_path('app/archivo-alumnos/'.(int) $documento->alumno_id.'/'.basename($documento->archivo));

        if (! File::exists($ruta)) {
            abort(404, 'El documento está registrado pero el fichero no está en el servidor.');
        }

        // `octet-stream` y no el tipo real: el front ya lo sabe (viene en la lista) y arma el `Blob`
        // con él. Un `image/*` aquí hacía que el nginx del docker --h5bp, `map $sent_http_content_type
        // $cors`-- añadiera SU `Access-Control-Allow-Origin` al de Laravel, y dos cabeceras iguales el
        // navegador las rechaza: la foto no se abría y el PDF sí. Medido el 24 sep 2026.
        return response()->file($ruta, [
            'Content-Type' => 'application/octet-stream',
            'Content-Disposition' => 'inline; filename="'.addcslashes($documento->nombre_original, '"\\').'"',
        ]);
    }

    public function deleteDocumento($id)
    {
        $this->exigirPermiso();

        $cambiadas = DB::table('documentos_externos')->where('id', $id)->whereNull('deleted_at')
            ->update(['deleted_at' => now()]);

        if (! $cambiadas) {
            abort(404, 'Ese documento no existe o ya estaba borrado.');
        }

        return ['id' => (int) $id];
    }

    /*
     * ─────────────────────────── NIVEL 2: LAS NOTAS, ESCRITAS A MANO ───────────────────────────
     *
     * Sólo la definitiva de cada asignatura (decisión de Joseth, 24 sep 2026), con la escala del
     * otro colegio para convertirla por tramos (`NotaDeOtroColegio`). Se guarda también la nota
     * como venía. Tabla en la migración `2026_09_24_995000_las_notas_de_otros_colegios`.
     */

    /** Las notas de un año, su escala de origen y la de destino, para convertir en pantalla. */
    public function getNotas($id)
    {
        $this->exigirPermiso();
        $ano = $this->anoOFallar($id);

        return [
            'escala' => $ano->escala_min === null ? null : [
                'min' => (float) $ano->escala_min, 'max' => (float) $ano->escala_max, 'aprueba' => (float) $ano->escala_aprueba,
            ],
            'grado_id' => $ano->grado_id,
            'destino' => NotaDeOtroColegio::destino(),
            'notas' => DB::select('SELECT id, materia_id, area_texto, asignatura_texto, intensidad, nota_original, nota, desempenio
                FROM notas_externas WHERE ano_externo_id = ? AND deleted_at IS NULL ORDER BY orden, id', [$id]),
        ];
    }

    /**
     * Guarda TODAS las notas del año de una vez: las que había se retiran (borrado suave) y entran
     * las que vienen. Es una tabla que se escribe entera desde un formulario, no celda a celda.
     */
    public function putNotas($id)
    {
        $this->exigirPermiso();
        $this->anoOFallar($id);

        $escala = (array) Request::input('escala', []);
        $origen = ['min' => (float) ($escala['min'] ?? 0), 'max' => (float) ($escala['max'] ?? 0), 'aprueba' => (float) ($escala['aprueba'] ?? 0)];

        if ($origen['max'] <= $origen['min']) {
            abort(422, 'La escala del otro colegio no cuadra: la máxima tiene que ser mayor que la mínima.');
        }

        $destino = NotaDeOtroColegio::destino();
        if ($destino === null) {
            abort(422, 'Este colegio no tiene escala de valoración en el año actual: no hay a qué convertir.');
        }

        $filas = [];
        foreach ((array) Request::input('notas', []) as $orden => $n) {
            $asignatura = trim((string) ($n['asignatura_texto'] ?? ''));
            $original = trim((string) ($n['nota_original'] ?? ''));
            if ($asignatura === '' || $original === '') { continue; }

            $convertida = NotaDeOtroColegio::convertir($original, $origen, $destino);
            $filas[] = [
                'ano_externo_id' => (int) $id,
                'materia_id' => ! empty($n['materia_id']) ? (int) $n['materia_id'] : null,
                'area_texto' => ($a = trim((string) ($n['area_texto'] ?? ''))) === '' ? null : mb_substr($a, 0, 160),
                'asignatura_texto' => mb_substr($asignatura, 0, 160),
                'intensidad' => is_numeric($n['intensidad'] ?? null) ? max(0, min(255, (int) $n['intensidad'])) : null,
                'nota_original' => mb_substr($original, 0, 12),
                'nota' => $convertida['nota'],
                'desempenio' => $convertida['desempenio'],
                'orden' => (int) $orden,
                'created_by' => $this->user->user_id,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        DB::transaction(function () use ($id, $origen, $filas) {
            DB::table('notas_externas')->where('ano_externo_id', $id)->whereNull('deleted_at')->update(['deleted_at' => now()]);
            if ($filas) { DB::table('notas_externas')->insert($filas); }

            $grado = Request::input('grado_id');
            DB::table('anos_externos')->where('id', $id)->update([
                'escala_min' => $origen['min'], 'escala_max' => $origen['max'], 'escala_aprueba' => $origen['aprueba'],
                'grado_id' => $grado ? (int) $grado : DB::raw('grado_id'),
                'updated_by' => $this->user->user_id, 'updated_at' => now(),
            ]);
        });

        return ['guardadas' => count($filas)];
    }

    /** Los grados del colegio, para elegir de cuál se toman las asignaturas. */
    public function getGrados()
    {
        $this->exigirPermiso();

        return DB::select('SELECT g.id, g.nombre, n.nombre AS nivel FROM grados g
            LEFT JOIN niveles_educativos n ON n.id = g.nivel_educativo_id
            WHERE g.deleted_at IS NULL ORDER BY g.orden, g.id');
    }

    /**
     * Las asignaturas que tiene HOY ese grado aquí --las del año actual--, con su área y su IH. Es
     * el punto de partida de la tabla: se escriben las notas encima, y lo que el otro colegio no
     * tenía se borra.
     */
    public function getMateriasDelGrado($grado_id)
    {
        $this->exigirPermiso();

        return DB::select('SELECT m.id AS materia_id, m.materia, ar.nombre AS area, MAX(a.creditos) AS creditos
            FROM asignaturas a
            INNER JOIN grupos g ON g.id = a.grupo_id AND g.deleted_at IS NULL AND g.grado_id = ?
            INNER JOIN years y ON y.id = g.year_id AND y.actual = 1
            INNER JOIN materias m ON m.id = a.materia_id AND m.deleted_at IS NULL
            LEFT JOIN areas ar ON ar.id = m.area_id AND ar.deleted_at IS NULL
            WHERE a.deleted_at IS NULL
            GROUP BY m.id, m.materia, ar.nombre, ar.orden, m.orden
            ORDER BY ar.orden, m.orden, m.materia', [$grado_id]);
    }

    /**
     * LOS AÑOS DE FUERA PARA EL CERTIFICADO DE TODOS LOS AÑOS, con la misma tupla que
     * `bolfinales/detailed-notas-year` --[grupo, year, alumnos, escalas]--, para que el componente
     * del certificado los pinte sin saber de dónde vienen. Más `externo`, con lo que cambia: dónde se
     * cursó, libro y folio, y la escala de origen.
     *
     * Sólo los años CON NOTAS: uno que sólo tiene el documento no es un certificado.
     *
     * **Permiso: el de ver certificados** (`auth.personal`, en la ruta), no el de editar alumnos:
     * quien imprime el certificado de un alumno tiene que ver todos sus años. Aquí no sale ningún
     * documento, sólo las notas.
     */
    public function getCertificadosDeAlumno($alumno_id)
    {
        $anos = DB::select('SELECT ae.*, g.nombre AS grado_nombre, n.nombre AS nivel
            FROM anos_externos ae
            LEFT JOIN grados g ON g.id = ae.grado_id
            LEFT JOIN niveles_educativos n ON n.id = g.nivel_educativo_id
            WHERE ae.alumno_id = ? AND ae.deleted_at IS NULL
              AND EXISTS (SELECT 1 FROM notas_externas ne WHERE ne.ano_externo_id = ae.id AND ne.deleted_at IS NULL)
            ORDER BY ae.year', [$alumno_id]);

        if (! $anos) { return []; }

        $alumno = DB::selectOne('SELECT a.id AS alumno_id, a.nombres, a.apellidos, a.documento, a.no_matricula,
                t.abrev AS tipo_doc_abrev
            FROM alumnos a LEFT JOIN tipos_documentos t ON t.id = a.tipo_doc AND t.deleted_at IS NULL
            WHERE a.id = ?', [$alumno_id]);

        $actual = DB::selectOne('SELECT id FROM years WHERE actual = 1 AND deleted_at IS NULL ORDER BY id DESC LIMIT 1');
        $destino = NotaDeOtroColegio::destino();
        $escalas = $destino['bandas'] ?? [];

        $certificados = [];
        foreach ($anos as $ano) {
            $notas = DB::select('SELECT * FROM notas_externas WHERE ano_externo_id = ? AND deleted_at IS NULL ORDER BY orden, id', [$ano->id]);

            $year = $actual ? Year::datos($actual->id) : (object) [];
            $year->year = (int) $ano->year;
            $year->periodos = [];

            $certificados[] = [
                ['nombre_grupo' => $ano->grado_texto ?: $ano->grado_nombre, 'nivel_educativo' => $ano->nivel],
                $year,
                [$this->alumnoDelCertificado($alumno, $notas, $escalas, $ano)],
                $escalas,
                [
                    'propio' => (bool) $ano->propio,
                    'colegio_nombre' => $ano->colegio_nombre,
                    'colegio_municipio' => $ano->colegio_municipio,
                    'libro' => $ano->libro,
                    'folio' => $ano->folio,
                    'escala' => $ano->escala_min === null ? null
                        : ['min' => (float) $ano->escala_min, 'max' => (float) $ano->escala_max, 'aprueba' => (float) $ano->escala_aprueba],
                ],
            ];
        }

        return $certificados;
    }

    /** El alumno con sus notas agrupadas por área, como lo trae `detailedNotasGrupo`. */
    private function alumnoDelCertificado(?object $alumno, array $notas, array $escalas, object $ano): array
    {
        $areas = [];
        foreach ($notas as $n) {
            $clave = $n->area_texto ?: $n->asignatura_texto;
            $areas[$clave] ??= ['area_nombre' => $clave, 'asignaturas' => []];
            $areas[$clave]['asignaturas'][] = [
                'materia' => $n->asignatura_texto,
                'creditos' => $n->intensidad,
                'promedio' => $n->nota === null ? null : (float) $n->nota,
                'desempenio' => $n->desempenio,
                'nota_original' => $n->nota_original,
                'definitivas' => [],
            ];
        }

        foreach ($areas as &$area) {
            $conNota = array_filter($area['asignaturas'], fn ($a) => $a['promedio'] !== null);
            $area['area_nota'] = $conNota ? round(array_sum(array_column($conNota, 'promedio')) / count($conNota)) : null;
            $area['area_desempenio'] = $area['area_nota'] === null ? null
                : (EscalaDeValoracion::valoracion($area['area_nota'], $escalas)->desempenio ?: null);
            $area['creditos'] = array_sum(array_map(fn ($a) => (int) $a['creditos'], $area['asignaturas']));
        }
        unset($area);

        $todas = array_filter(array_map(fn ($n) => $n->nota, $notas), fn ($v) => $v !== null);
        $promedio = $todas ? array_sum($todas) / count($todas) : null;

        return [
            'alumno_id' => (int) ($alumno->alumno_id ?? $ano->alumno_id),
            'nombres' => $alumno->nombres ?? '',
            'apellidos' => $alumno->apellidos ?? '',
            'documento' => $alumno->documento ?? '',
            'tipo_doc_abrev' => $alumno->tipo_doc_abrev ?? '',
            'no_matricula' => $alumno->no_matricula ?? '',
            'nro_folio' => $ano->folio,
            'areas' => array_values($areas),
            'total_creditos' => array_sum(array_map(fn ($n) => (int) $n->intensidad, $notas)),
            'promedio' => $promedio,
            'desempenio' => $promedio === null ? null : (EscalaDeValoracion::valoracion($promedio, $escalas)->desempenio ?: null),
            'recuperaciones' => [],
        ];
    }

    private function anoOFallar($id): object
    {
        $ano = DB::selectOne('SELECT * FROM anos_externos WHERE id = ? AND deleted_at IS NULL', [$id]);

        if (! $ano) {
            abort(404, 'Ese año no existe o fue borrado.');
        }

        return $ano;
    }

    /** Lo que se puede escribir de un año, validado. */
    private function datosDelAno(): array
    {
        $year = (int) Request::input('year');

        if ($year < 1950 || $year > (int) date('Y') + 1) {
            abort(422, 'El año tiene que ser un número de cuatro cifras, como 2023.');
        }

        $propio = (bool) Request::input('propio', false);
        $colegio = trim((string) Request::input('colegio_nombre', ''));

        if (! $propio && $colegio === '') {
            abort(422, 'Falta el nombre del colegio donde lo cursó.');
        }

        $texto = fn (string $campo, int $largo) => ($v = trim((string) Request::input($campo, ''))) === ''
            ? null : mb_substr($v, 0, $largo);

        $gradoId = Request::input('grado_id');

        return [
            'year' => $year,
            'grado_id' => $gradoId ? (int) $gradoId : null,
            'grado_texto' => $texto('grado_texto', 60),
            'propio' => $propio,
            'colegio_nombre' => $propio ? null : mb_substr($colegio, 0, 160),
            'colegio_municipio' => $propio ? null : $texto('colegio_municipio', 80),
            'libro' => $texto('libro', 20),
            'folio' => $texto('folio', 20),
            'observaciones' => $texto('observaciones', 2000),
        ];
    }
}
