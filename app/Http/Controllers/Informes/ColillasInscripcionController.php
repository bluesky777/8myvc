<?php

namespace App\Http\Controllers\Informes;

use App\Http\Controllers\Concerns\ResuelveElUsuario;
use App\Http\Controllers\Controller;
use App\Services\CodigoDeInscripcion;
use App\Support\Autoriza;
use App\Support\SafeUpload;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Str;

/**
 * **El comprobante del pago del formulario de inscripción**, y su aprobación.
 *
 *     POST colillas-inscripcion/{codigo}      PÚBLICA   la manda la familia
 *     GET  colillas-inscripcion/pendientes    tesorero  su bandeja
 *     PUT  colillas-inscripcion/{id}/aprobar  tesorero
 *     PUT  colillas-inscripcion/{id}/rechazar tesorero
 *
 * Lo describió Joseth el 19 sep 2026: la familia paga donde quiera, manda el
 * comprobante, **le llega el aviso al tesorero**, él comprueba que el dinero llegó
 * de verdad y **sólo entonces aprueba**. Lo importante de ese orden es que aquí no
 * se decide nada sobre el dinero: esto sólo transporta una prueba hasta la persona
 * que la sabe leer.
 *
 * ## FOTO **O** REFERENCIA, y la segunda es la buena
 *
 * Idea de Joseth, y mejor que las dos que se le habían propuesto: **el camino de la
 * referencia no sube ningún fichero**, así que para esa mitad de los casos no hay
 * almacenamiento, ni URL que se escape, ni nada que un desconocido pueda subir. La
 * familia que sólo tiene el número no se queda fuera y la que tiene la foto conserva
 * su prueba. Que haya **al menos una de las dos** no lo vigila este método: lo cierra
 * un `CHECK` en la tabla, porque un `if` es una regla y las reglas se saltan.
 *
 * ## LA ÚNICA RUTA PÚBLICA DE ESTA API QUE RECIBE UN FICHERO
 *
 * Quien sube no tiene cuenta —es la familia de un aspirante que todavía no es
 * alumno— así que no hay token que comprobar. Lo que la protege son cuatro cosas, y
 * **sólo la tercera es de verdad un tope**:
 *
 *   1. **El código tiene que cuadrar.** El carácter de control se comprueba *antes*
 *      de tocar la base: un código inventado no llega ni a una consulta.
 *   2. **`throttle:colilla`**, por IP y por código a la vez (ver `RouteServiceProvider`).
 *   3. **Tres comprobantes por orden y UNA sola pendiente.** Esto es lo que acota el
 *      disco y la bandeja del tesorero, y **es una regla de la fila, no del reloj**:
 *      un limitador se reinicia cada hora y esto no.
 *   4. **Lista blanca de tipos, por extensión Y por contenido.** Sin `svg` y sin
 *      `html`, que es lo que de verdad importa: servidos desde el dominio del
 *      colegio **ejecutan JavaScript en ese origen**. Un PDF o un JPEG que no sea lo
 *      que dice ser es un fichero roto; un SVG es un agujero.
 *
 * Y para un DDoS de verdad no vale nada de esto: eso se para en el borde, y es una
 * decisión de hosting.
 *
 * ## El fichero vive bajo `public/`, decidido por Joseth con el precio delante
 *
 * Es lo que hace el resto de la casa —las fotos de perfil y **los documentos del
 * PIAR**, que son valoraciones de menores y bastante más delicadas que un recibo—,
 * así que tratar la colilla aparte habría sido aplicarle una vara más estricta que a
 * lo que ya está guardado.
 *
 * **El precio, dicho una vez: la URL es la llave.** No caduca, no se revoca y no
 * pregunta quién eres. Por eso el nombre del fichero es **aleatorio y nunca el
 * código**: si el fichero se llamara como el formulario, saber un código —que la
 * familia dicta por teléfono— daría el recibo.
 *
 * ## `public_path()` y no la ruta relativa de la casa
 *
 * `ImagesController` y `UploadDocuments` escriben en `'images/perfil/'` y
 * `'uploads/'`, que son **rutas relativas al directorio de trabajo del proceso**:
 * bajo PHP-FPM eso es `public/`, pero desde `artisan` o desde un test es la raíz del
 * proyecto. `public_path()` apunta al mismo sitio en producción y **al mismo sitio en
 * los tests**, que es lo que permite que esto se pruebe de verdad en vez de a ojo.
 */
class ColillasInscripcionController extends Controller
{
    use ResuelveElUsuario;

    /** Sin `svg` y sin nada que el navegador ejecute. Ver la cabecera. */
    private const EXTENSIONES = ['jpg', 'jpeg', 'png', 'pdf'];

    /** Y el contenido tiene que decir lo mismo que la extensión. */
    private const TIPOS = ['image/jpeg', 'image/png', 'application/pdf'];

    /** 5 MB. Una foto de un recibo hecha con un móvil cabe de sobra. */
    private const MAXIMO_BYTES = 5 * 1024 * 1024;

    /**
     * Cuántos comprobantes admite una orden.
     *
     * **Éste es el tope de verdad**, no el limitador: acota el disco por formulario
     * y la bandeja del tesorero, y no se reinicia nunca. Tres da para equivocarse
     * dos veces.
     */
    private const MAXIMO_POR_ORDEN = 3;

    private const LARGO_REFERENCIA = 60;

    /**
     * La familia manda su comprobante. **Ruta pública.**
     */
    public function postSubir(string $codigo)
    {
        // Antes que nada y antes de tocar la base: si el código no cuadra consigo
        // mismo, no existe y no hay nada que consultar. Es gratis y quita de en
        // medio todo lo que no sea un código de verdad.
        if (! CodigoDeInscripcion::esValido($codigo)) {
            abort(422, 'Ese código no es válido. Revísalo: son el año y seis caracteres.');
        }

        $orden = DB::selectOne('SELECT id, estado FROM ordenes_inscripcion
            WHERE codigo=? AND deleted_at IS NULL',
            [CodigoDeInscripcion::normalizar($codigo)]);

        if (! $orden) {
            abort(404, 'No encontramos ese formulario.');
        }

        $cuantas = DB::selectOne('SELECT COUNT(*) c,
                SUM(estado="PENDIENTE") pendientes
            FROM colillas_inscripcion WHERE orden_id=?', [$orden->id]);

        if ((int) $cuantas->pendientes > 0) {
            abort(429, 'Ya hay un comprobante esperando revisión para este formulario.');
        }

        if ((int) $cuantas->c >= self::MAXIMO_POR_ORDEN) {
            abort(429, 'Este formulario ya tiene el máximo de comprobantes. Hable con el colegio.');
        }

        $referencia = $this->referenciaValidada();
        $archivo = $this->guardarElArchivoSiVino();

        if ($archivo === null && $referencia === null) {
            abort(422, 'Mande la foto del recibo o el número de referencia.');
        }

        DB::insert('INSERT INTO colillas_inscripcion
            (orden_id, referencia, archivo, tipo, bytes, subida_ip, estado, created_at, updated_at)
            VALUES (?,?,?,?,?,?,"PENDIENTE",NOW(),NOW())', [
            $orden->id, $referencia,
            $archivo['nombre'] ?? null, $archivo['tipo'] ?? null, $archivo['bytes'] ?? null,
            substr((string) Request::ip(), 0, 45),
        ]);

        // Lo que se le contesta a la familia **no lleva nada suyo dentro** y no dice
        // si el pago está bien: eso lo decide una persona. Un «recibido» que
        // pareciera una aprobación sería peor que no contestar.
        return ['recibido' => true,
            'mensaje' => 'Recibimos su comprobante. El colegio lo revisará y le avisará.'];
    }

    /**
     * La bandeja del tesorero.
     */
    public function getPendientes()
    {
        $user = $this->user;

        Autoriza::exigir(Autoriza::puedeResolverColillas($user, (int) $user->year_id),
            'No tiene permiso para revisar los comprobantes de pago.');

        return ['pendientes' => DB::select('SELECT c.id, c.referencia, c.archivo, c.tipo, c.bytes,
                c.created_at, o.codigo, o.valor, o.modo, o.year_campana,
                a.nombres, a.apellidos, a.documento
            FROM colillas_inscripcion c
            INNER JOIN ordenes_inscripcion o ON o.id=c.orden_id AND o.deleted_at IS NULL
            LEFT JOIN alumnos a ON a.id=o.alumno_id AND a.deleted_at IS NULL
            WHERE c.estado="PENDIENTE"
            ORDER BY c.created_at')];
    }

    public function putAprobar(int $id)
    {
        return $this->resolver($id, 'APROBADA');
    }

    public function putRechazar(int $id)
    {
        return $this->resolver($id, 'RECHAZADA');
    }

    /**
     * Aprobar y rechazar son **la misma escritura con distinto valor**, y por eso
     * comparten método aunque tengan ruta propia: las rutas separadas son para que
     * la bitácora y el router digan cuál se llamó, no porque hagan cosas distintas.
     *
     * **Un comprobante resuelto no se vuelve a tocar.** Sin eso, aprobar dos veces
     * dejaría dos fechas y dos firmantes sobre la misma fila y nadie sabría cuál
     * valió; y rechazar algo ya aprobado deshaba una decisión de dinero sin dejar
     * rastro de que hubo dos.
     */
    private function resolver(int $id, string $estado)
    {
        $user = $this->user;

        Autoriza::exigir(Autoriza::puedeResolverColillas($user, (int) $user->year_id),
            'No tiene permiso para resolver los comprobantes de pago.');

        $colilla = DB::selectOne('SELECT id, estado, orden_id FROM colillas_inscripcion WHERE id=?', [$id]);

        if (! $colilla) {
            abort(404, 'Ese comprobante no existe.');
        }

        if ($colilla->estado !== 'PENDIENTE') {
            abort(422, 'Ese comprobante ya estaba '.strtolower($colilla->estado).'.');
        }

        $motivo = trim((string) Request::input('motivo'));

        if ($estado === 'RECHAZADA' && $motivo === '') {
            abort(422, 'Diga por qué lo rechaza: la familia tiene que saber qué corregir.');
        }

        DB::update('UPDATE colillas_inscripcion
            SET estado=?, resuelta_por=?, resuelta_at=NOW(), motivo=?, updated_at=NOW()
            WHERE id=? AND estado="PENDIENTE"',
            [$estado, $user->user_id, $motivo !== '' ? mb_substr($motivo, 0, 255) : null, $id]);

        // La orden avanza sólo al aprobar. Un rechazo **no la mueve hacia atrás**:
        // el formulario sigue impreso y vendido, que es lo que dice `estado`.
        if ($estado === 'APROBADA') {
            DB::update('UPDATE ordenes_inscripcion SET estado="PAGADA", updated_by=?, updated_at=NOW()
                WHERE id=? AND estado="IMPRESA"', [$user->user_id, $colilla->orden_id]);
        }

        return $estado === 'APROBADA' ? 'Comprobante aprobado.' : 'Comprobante rechazado.';
    }

    /**
     * @return array{nombre: string, tipo: string, bytes: int}|null
     */
    private function guardarElArchivoSiVino(): ?array
    {
        if (Request::file('archivo') === null) {
            return null;
        }

        $file = SafeUpload::archivoRecibido('archivo');

        if ($file->getSize() > self::MAXIMO_BYTES) {
            abort(422, 'El archivo pasa de 5 MB. Mande una foto más pequeña.');
        }

        $tipo = (string) $file->getMimeType();

        // La extensión **y** el contenido. `SafeUpload` ya compara las dos y rechaza
        // lo ejecutable; esto añade la lista blanca positiva del contenido, que es lo
        // que impide que un `.jpg` que por dentro es otra cosa entre igual.
        if (! in_array($tipo, self::TIPOS, true)) {
            abort(422, 'Sólo aceptamos una foto (JPG o PNG) o un PDF.');
        }

        $carpeta = public_path('colillas');

        if (! File::exists($carpeta)) {
            File::makeDirectory($carpeta, 0755, true, true);
        }

        // `nombreDisponible` valida la extensión —declarada y real— y de ahí se toma
        // sólo eso: **el nombre se genera**, nunca se hereda del que mandó el
        // desconocido y nunca es el código. Ver la cabecera.
        $validado = SafeUpload::nombreDisponible($file, $carpeta, self::EXTENSIONES);
        $extension = strtolower((string) pathinfo($validado, PATHINFO_EXTENSION));

        $nombre = Str::random(40).'.'.$extension;
        $bytes = (int) $file->getSize();

        $file->move($carpeta, $nombre);

        return ['nombre' => 'colillas/'.$nombre, 'tipo' => $tipo, 'bytes' => $bytes];
    }

    private function referenciaValidada(): ?string
    {
        $referencia = Request::input('referencia');

        if ($referencia === null || trim((string) $referencia) === '') {
            return null;
        }

        $referencia = trim((string) $referencia);

        // El tope se valida aquí y no se le deja a la base: el docker trunca en
        // silencio y MariaDB 10.5 aborta.
        if (mb_strlen($referencia) > self::LARGO_REFERENCIA) {
            abort(422, 'El número de referencia no puede pasar de '.self::LARGO_REFERENCIA.' caracteres.');
        }

        // Un número de banco, no una frase. No se valida contra ningún catálogo
        // —quien comprueba que ese pago llegó es el tesorero, mirando su cuenta—
        // pero sí que sea algo que se pueda buscar.
        if (preg_match('/^[A-Za-z0-9\-\/ .]{3,}$/', $referencia) !== 1) {
            abort(422, 'Ese número de referencia no parece un número de recibo.');
        }

        return $referencia;
    }
}
