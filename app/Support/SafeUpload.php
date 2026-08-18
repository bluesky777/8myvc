<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;

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

        $nombre = $base . '.' . $extension;

        // Mismo comportamiento de siempre: foto.jpg, foto(1).jpg, foto(2).jpg…
        $i = 0;
        while (file_exists($carpeta . '/' . $nombre)) {
            $i++;
            $nombre = $base . '(' . $i . ').' . $extension;
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
            abort(422, 'Tipo de archivo no permitido: .' . $declarada);
        }

        // extension() deduce del MIME real; devuelve '' si no reconoce el contenido.
        $real = strtolower((string) $file->extension());

        if ($real !== '' && in_array($real, self::PROHIBIDAS, true)) {
            abort(422, 'El contenido del archivo no corresponde a su extensión.');
        }

        return $declarada;
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
