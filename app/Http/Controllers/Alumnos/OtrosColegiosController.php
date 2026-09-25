<?php

namespace App\Http\Controllers\Alumnos;

use App\Http\Controllers\Concerns\ResuelveElUsuario;
use App\Http\Controllers\Controller;
use App\Support\Autoriza;
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

        $anos = DB::select('SELECT ae.*, g.nombre AS grado_nombre
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

        return response()->file($ruta, [
            'Content-Type' => $documento->tipo,
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
