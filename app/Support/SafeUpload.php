<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Request;

/**
 * Validación de archivos subidos.
 *
 * Los dos puntos de subida del sistema (imágenes de perfil y documentos de PIAR)
 * guardaban el archivo con el nombre que enviaba el cliente, sin comprobar la
 * extensión ni el tipo real. Como ambos escriben dentro de public/, eso permitía
 * dejar un .php bajo el document root y ejecutarlo pidiendo su URL.
 *
 * Esta clase centraliza la comprobación para que no haya dos versiones de la
 * misma regla.
 */
class SafeUpload
{
    /** Extensiones permitidas para imágenes de perfil y compartidas. */
    public const EXTENSIONES_IMAGEN = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    /** Extensiones permitidas para documentos adjuntos. */
    public const EXTENSIONES_DOCUMENTO = [
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'odt', 'ods',
        'jpg', 'jpeg', 'png', 'gif', 'webp',
    ];

    /**
     * El archivo de un campo del formulario, o 422 si no hay exactamente uno.
     *
     * Existe porque `Request::file('x')` devuelve **un `UploadedFile` o un array**,
     * según el cliente mande `file` o `file[]`, y los dos puntos de subida
     * asumían lo primero. Con dos archivos en el mismo campo, `nombreDisponible()`
     * recibía un array donde su firma declara `?UploadedFile` y el TypeError salía
     * como **500**; medido antes de escribir esto. No es un agujero —lo que se
     * cuela no llega a guardarse— pero es un 500 en la única operación de subida
     * que tiene el sistema, y desde fuera un 500 no se distingue de «el servidor
     * está caído». Ver 05 §45.
     *
     * Se rechaza en vez de quedarse con el primero: quien manda dos archivos cree
     * que va a subir dos, y guardar uno en silencio es peor que decirle que no.
     * Ninguna pantalla manda `file[]` —comprobado en los cuatro clientes—, así que
     * esto no apaga nada.
     *
     * Lo señaló el nivel 7 de larastan en tres sitios a la vez; vive aquí y no en
     * cada uno por lo mismo que el resto de la clase: para que no haya dos
     * versiones de la misma regla.
     */
    public static function archivoRecibido(string $campo): UploadedFile
    {
        $file = Request::file($campo);

        // Esta rama no decide el control —el `instanceof` de abajo también
        // rechaza un array—, decide el MENSAJE. Se comprobó quitándola: el test
        // seguía verde. Se queda porque «sube los archivos de uno en uno» y «no se
        // recibió un archivo válido» le dicen cosas distintas a quien lo lee, y la
        // segunda manda a buscar el fallo donde no está.
        if (is_array($file)) {
            abort(422, 'Sube los archivos de uno en uno.');
        }

        if (! $file instanceof UploadedFile || ! $file->isValid()) {
            abort(422, 'No se recibió un archivo válido.');
        }

        return $file;
    }

    /**
     * Comprueba el archivo y devuelve un nombre seguro que aún no exista en la carpeta.
     *
     * Conserva el nombre original visible (los documentos de PIAR se identifican por
     * su nombre) pero neutraliza cualquier intento de aterrizar como ejecutable.
     *
     * @param  string  $carpeta  Carpeta destino, relativa al directorio público.
     * @param  string[]  $permitidas  Lista blanca de extensiones.
     */
    public static function nombreDisponible(?UploadedFile $file, string $carpeta, array $permitidas): string
    {
        if (! $file || ! $file->isValid()) {
            abort(422, 'No se recibió un archivo válido.');
        }

        $extension = self::extensionSegura($file, $permitidas);
        $base = self::baseSegura($file);

        $nombre = $base.'.'.$extension;

        // Mismo comportamiento de siempre: foto.jpg, foto(1).jpg, foto(2).jpg…
        $i = 0;
        while (file_exists($carpeta.'/'.$nombre)) {
            $i++;
            $nombre = $base.'('.$i.').'.$extension;
        }

        return $nombre;
    }

    /**
     * Extensiones que no deben aterrizar nunca bajo public/, ni declaradas por el
     * cliente ni deducidas del contenido.
     */
    private const PROHIBIDAS = [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phps', 'phar',
        'cgi', 'pl', 'py', 'rb', 'sh', 'bash', 'exe', 'com', 'bat', 'dll', 'so',
        'htaccess', 'htpasswd', 'ini', 'conf',
        'html', 'htm', 'shtml', 'xhtml', 'svg',
    ];

    /**
     * La extensión declarada por el cliente manda para el nombre final, y tiene
     * que estar en la lista blanca: eso es lo que impide que el archivo acabe
     * como .php bajo el document root.
     *
     * Sobre el contenido solo se comprueba que no sea algo ejecutable, no que
     * coincida con la extensión. Exigir coincidencia rompería subidas legítimas:
     * comprobado que un .docx se detecta como application/zip, cuya extensión
     * deducida es 'zip' y no 'docx'.
     */
    private static function extensionSegura(UploadedFile $file, array $permitidas): string
    {
        $declarada = strtolower($file->getClientOriginalExtension());

        if (! in_array($declarada, $permitidas, true) || in_array($declarada, self::PROHIBIDAS, true)) {
            abort(422, 'Tipo de archivo no permitido: .'.$declarada);
        }

        // extension() deduce del MIME real; devuelve '' si no reconoce el contenido.
        $real = strtolower((string) $file->extension());

        if ($real !== '' && in_array($real, self::PROHIBIDAS, true)) {
            abort(422, 'El contenido del archivo no corresponde a su extensión.');
        }

        return $declarada;
    }

    /**
     * El nombre que mandó el cliente, saneado, para **guardarlo o enseñarlo** —
     * nunca para decidir dónde aterriza un archivo.
     *
     * Es el caso de la importación de alumnos: `importaciones.archivo` guarda
     * cómo se llamaba la hoja para que un humano reconozca la fila. Ahí no se
     * escribe nada en disco, pero el nombre viene del cliente igual que en las
     * subidas, y lo que se guarda en una columna termina saliendo por una
     * pantalla. Pasa por el mismo saneado que las subidas y así
     * `getClientOriginalName()` sigue viviendo en un solo sitio, que es lo que
     * comprueba `GuardsDestructivosTest`.
     *
     * No valida la extensión contra ninguna lista: aquí no hay lista blanca que
     * aplicar, porque el archivo no se guarda. Solo se neutraliza.
     */
    public static function nombreParaGuardar(?UploadedFile $file): ?string
    {
        if (! $file) {
            return null;
        }

        $extension = mb_strtolower(preg_replace('/[^A-Za-z0-9]/', '', (string) $file->getClientOriginalExtension()));

        return self::baseSegura($file).($extension === '' ? '' : '.'.mb_substr($extension, 0, 10));
    }

    /**
     * Nombre base sin ruta, sin puntos y sin caracteres que el sistema de archivos
     * o el servidor web puedan interpretar.
     *
     * Los puntos intermedios se sustituyen para que "informe.php.pdf" quede como
     * "informe_php.pdf" y no dependa de cómo el servidor web parta la ruta.
     */
    private static function baseSegura(UploadedFile $file): string
    {
        $original = basename($file->getClientOriginalName());
        $base = pathinfo($original, PATHINFO_FILENAME);

        $base = str_replace("\0", '', $base);
        $base = preg_replace('/[^\p{L}\p{N} _\-()]+/u', '_', $base);
        $base = trim((string) $base, ' .');
        $base = mb_substr($base, 0, 120);

        return $base === '' ? 'archivo' : $base;
    }
}
