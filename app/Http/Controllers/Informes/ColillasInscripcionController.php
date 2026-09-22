<?php

namespace App\Http\Controllers\Informes;

use App\Http\Controllers\Concerns\ResuelveElUsuario;
use App\Http\Controllers\Controller;
use App\Services\OrdenDeInscripcion;
use App\Support\Autoriza;
use App\Support\Reloj;
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
        if (! OrdenDeInscripcion::tieneForma($codigo)) {
            abort(422, 'Ese código no es válido. Revísalo: son el año y seis caracteres.');
        }

        // **Y busca también por el código VIEJO**, que es lo que esta línea no hacía
        // hasta el 20 sep 2026 por la tarde. Si secretaría corrigió el código, el
        // papel que la familia tiene en la mano lleva el anterior — y sin esto se
        // estrellaba aquí contra un «No encontramos ese formulario» que no puede
        // reportarle a nadie, porque no tiene cuenta. Ver `OrdenDeInscripcion`.
        $orden = OrdenDeInscripcion::porCodigo($codigo);

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

        // Y no `NOW()`: ver el 53 §1. `config/database.php` no fija la zona de la
        // sesión, así que `NOW()` es el reloj del cPanel de cada colegio, y esta
        // columna es la que le dice a la familia cuándo mandó su comprobante.
        $ahora = Reloj::ahoraTexto();

        DB::insert('INSERT INTO colillas_inscripcion
            (orden_id, referencia, archivo, tipo, bytes, subida_ip, estado, created_at, updated_at)
            VALUES (?,?,?,?,?,?,"PENDIENTE",?,?)', [
            $orden->id, $referencia,
            $archivo['nombre'] ?? null, $archivo['tipo'] ?? null, $archivo['bytes'] ?? null,
            substr((string) Request::ip(), 0, 45),
            $ahora, $ahora,
        ]);

        // Lo que se le contesta a la familia **no lleva nada suyo dentro** y no dice
        // si el pago está bien: eso lo decide una persona. Un «recibido» que
        // pareciera una aprobación sería peor que no contestar.
        return ['recibido' => true,
            'mensaje' => 'Recibimos su comprobante. El colegio lo revisará y le avisará.'];
    }

    /**
     * **«¿Cómo va mi inscripción?»** — la contesta la familia, sin cuenta.
     *
     * Autorizada por Joseth el 20 sep 2026. Cierra el único hueco que quedaba en
     * este flujo, y estaba medido: **de las catorce rutas del formulario, las tres
     * públicas eran las tres de ESCRITURA y ninguna lectura lo era.** O sea que la
     * familia mandaba su comprobante y **no tenía forma de saber si se lo aprobaron,
     * se lo rechazaron, ni por qué**.
     *
     * El motivo del rechazo ya se guardaba —`putRechazar` lo exige, y no por
     * formulismo: sin texto no se puede rechazar— pero **sólo lo veía el personal**.
     * El aviso que debía cerrarlo iba por correo, y el correo de esta API está en
     * rojo desde el 2 sep: `lalvirtual.com`, el `MAIL_FROM_ADDRESS` de quince
     * colegios, **no está registrado**, y falla callado.
     *
     * **Por eso esto no espera al correo**: la familia entra con el código que ya
     * lleva impreso en el papel. Es *pull* en vez de *push*, y para este
     * destinatario **no es el mejor canal: es el único que existe**.
     *
     * ## Y la cifra que justificaba esto estaba mal DOS veces, así que va medida
     *
     * Esta línea decía *«sólo el 9,2 % de los acudientes tiene correo»*, del doc 42.
     * Ese 9,2 % es `acudientes.email` —la **ficha**— y **todo lo que manda correo
     * busca por `users.email`, la CUENTA** (`LoginController`, cuatro consultas y
     * las cuatro sobre esa columna). Lo levantó `8myvc-9a` el 20 sep 2026: por esa
     * columna eran **0 de 1.085**, y su arreglo los dejó en **91**.
     *
     * **Pero ninguna de las dos cifras es la de aquí, y ése es el fondo del asunto:
     * las dos cuentan acudientes de alumnos YA MATRICULADOS.** Quien paga un
     * formulario de inscripción es la familia de un **aspirante**, que por
     * definición no tiene fila en `users` ni en `acudientes` — es el motivo entero
     * de que estas rutas sean públicas. Y remedido el 20 sep: **este flujo no le
     * pide el correo en ningún momento y ninguna de sus tres tablas tiene esa
     * columna**.
     *
     * Así que para un aspirante el correo no es un canal malo: **no es un canal**.
     * Un `SELECT` no lo habría dicho —no hay dónde mirar—, y las dos cifras que
     * circulaban eran ciertas sobre una población que no es ésta.
     *
     * ## Es PÚBLICA, así que lo que decide su forma es qué es inocuo
     *
     * La llave es el código, y el código **se dicta por teléfono y viaja en un papel
     * que pasa de mano en mano**. Así que la pregunta al elegir cada campo no fue
     * «¿le sirve a la familia?» sino **«¿qué pasa si esto lo lee alguien que se
     * encontró el papel?»**:
     *
     *     SÍ   el estado, el valor, la fecha límite, en qué va el comprobante y el
     *          motivo del rechazo — que el tesorero escribe PARA la familia
     *     NO   el nombre del alumno, su documento, sus teléfonos, de qué grupo es
     *     NO   el nombre del fichero del recibo: la URL es la llave (41 §5), y
     *          saber un código no puede dar el recibo que subió otro
     *     NO   quién lo resolvió ni desde qué IP se subió
     *
     * **Un código no puede revelar el nombre de un menor**, y ésa es la línea. Lo que
     * sale de aquí describe un trámite, no a una persona.
     *
     * ## Y encuentra por el código VIEJO, que es la mitad del motivo de que exista
     *
     * Si secretaría corrigió el código, el papel que la familia tiene lleva el
     * anterior. `encontrado_por` lo dice, para que la pantalla pueda enseñarle **cuál
     * es el bueno ahora** — que es justo lo que nadie le va a decir por teléfono.
     */
    public function getEstado(string $codigo)
    {
        if (! OrdenDeInscripcion::tieneForma($codigo)) {
            abort(422, 'Ese código no es válido. Revísalo: son el año y seis caracteres.');
        }

        $orden = OrdenDeInscripcion::porCodigo($codigo);

        if (! $orden) {
            abort(404, 'No encontramos ese formulario.');
        }

        // Sin `archivo`, sin `resuelta_por` y sin `subida_ip`: ver la cabecera. Van
        // todos —son tres como mucho por orden, y el tope lo pone la base— porque
        // quien mandó dos rechazados necesita leer los dos motivos para no mandar
        // un tercero igual.
        $comprobantes = DB::select('SELECT estado, motivo, created_at AS enviado_at,
                resuelta_at AS resuelto_at
            FROM colillas_inscripcion WHERE orden_id=? ORDER BY id', [$orden->id]);

        $pendientes = 0;

        foreach ($comprobantes as $comprobante) {
            // El motivo sólo viaja cuando explica un rechazo. En una aprobación no
            // hay nada que corregir, y un texto interno del tesorero pegado a un
            // «aprobado» es información que nadie pidió enseñar.
            if ($comprobante->estado !== 'RECHAZADA') {
                $comprobante->motivo = null;
            }

            if ($comprobante->estado === 'PENDIENTE') {
                $pendientes++;
            }
        }

        $quedan = max(0, self::MAXIMO_POR_ORDEN - count($comprobantes));

        return [
            // **El código BUENO**, aunque haya entrado por el viejo. Es lo único que
            // esta ruta le puede decir a alguien que tiene un papel desactualizado.
            'codigo' => $orden->codigo,
            'encontrado_por' => $orden->encontrado_por,
            'year_campana' => (int) $orden->year_campana,
            'estado' => $orden->estado,
            'pagado' => in_array($orden->estado, ['PAGADA', 'APROBADA', 'MATRICULADA'], true),
            'valor' => $orden->valor === null ? null : (int) $orden->valor,
            'cierra' => $orden->cierra,
            'comprobantes' => $comprobantes,
            // Las dos condiciones que `postSubir` comprueba de verdad, dichas ANTES
            // de que la familia gaste una subida. Sin esto, se entera con un 429
            // después de elegir la foto.
            'puede_enviar_otro' => $pendientes === 0 && $quedan > 0,
            'comprobantes_restantes' => $quedan,
        ];
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

        // Una sola lectura del reloj para la colilla y para la orden: la resolución
        // y el avance son el mismo acto, y con dos lecturas la orden podría quedar
        // sellada un segundo antes que la decisión que la movió.
        $ahora = Reloj::ahoraTexto();

        DB::update('UPDATE colillas_inscripcion
            SET estado=?, resuelta_por=?, resuelta_at=?, motivo=?, updated_at=?
            WHERE id=? AND estado="PENDIENTE"',
            [$estado, $user->user_id, $ahora,
                $motivo !== '' ? mb_substr($motivo, 0, 255) : null, $ahora, $id]);

        // La orden avanza sólo al aprobar. Un rechazo **no la mueve hacia atrás**:
        // el formulario sigue impreso y vendido, que es lo que dice `estado`.
        if ($estado === 'APROBADA') {
            DB::update('UPDATE ordenes_inscripcion SET estado="PAGADA", updated_by=?, updated_at=?
                WHERE id=? AND estado="IMPRESA"', [$user->user_id, $ahora, $colilla->orden_id]);
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
