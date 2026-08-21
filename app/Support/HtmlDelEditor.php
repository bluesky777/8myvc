<?php

namespace App\Support;

use HTMLPurifier;
use HTMLPurifier_Config;
use Illuminate\Support\Facades\File;

/**
 * Limpia el HTML que llega del editor de texto enriquecido del PIAR.
 *
 * **Qué se guardaba antes.** Los seis campos de texto del PIAR
 * —`valoracion_pedagogica`, `ajustes_generales`, `reporte`, `apoyo_razonable`,
 * `seguimientos` y `caracterizacion_grupo`— se escribían tal cual llegaban, y
 * el Angular los pintaba con `bypassSecurityTrustHtml`, que es el sanitizador
 * de Angular desactivado a propósito. Un `<img src=x onerror=…>` guardado en
 * cualquiera de esos campos se ejecutaba en la sesión de quien abriera el PIAR,
 * y el token vive en `localStorage`.
 *
 * **Por qué hace falta aquí y no solo en el cliente.** El front ya sanea al
 * pintar, pero eso es un cliente de cuatro; el que limpia al escribir es el
 * único punto por el que pasan todos. Además la API no puede confiar en que el
 * cliente sanee: el endpoint acepta lo que le manden con curl.
 *
 * **Por qué la lista blanca es esta y no una más corta.** Es exactamente lo que
 * el esquema de ngx-editor puede generar con la barra de herramientas que tiene
 * configurada el PIAR (negrita, cursiva, subrayado, listas, títulos h1-h3,
 * enlace, línea horizontal, color de texto, color de fondo y alineación).
 * Recortarla más borraría formato que los docentes ya tienen escrito.
 *
 * **Y por qué el CSS va restringido a tres propiedades.** El color y la
 * alineación viajan como estilo en línea —`<span style="color:#f00">`,
 * `<p style="text-align:center">`—, así que el atributo `style` tiene que
 * sobrevivir. Pero con CSS libre se puede tapar la página entera con un
 * `position:fixed` y sacar datos con `background-image:url(...)`, que son
 * ataques sin JavaScript. Las tres propiedades son las que el editor escribe.
 */
class HtmlDelEditor
{
    /** Lo que el esquema de ngx-editor sabe generar. */
    private const ELEMENTOS = [
        'p', 'br', 'span', 'div',
        'strong', 'b', 'em', 'i', 'u', 's', 'strike', 'code', 'pre',
        'a', 'ul', 'ol', 'li',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'blockquote', 'hr', 'img',
    ];

    /**
     * `*.style` va aparte y en todos los elementos a propósito: el color se
     * aplica sobre `span` y la alineación sobre `p`, pero al pegar desde Word
     * el estilo aterriza en cualquier etiqueta. Lo que limita el daño no es en
     * qué elemento se permite el atributo, sino `CSS.AllowedProperties`.
     */
    private const ATRIBUTOS = [
        '*.style',
        'a.href', 'a.title', 'a.target', 'a.rel',
        'img.src', 'img.alt', 'img.width', 'img.height',
    ];

    private static ?HTMLPurifier $purificador = null;

    /**
     * Devuelve el HTML sin nada ejecutable, conservando el formato del editor.
     *
     * Un `null` entra y sale como `null`: los campos del PIAR son nullable y
     * convertirlos en cadena vacía cambiaría lo que ve el front, que distingue
     * «sin escribir» de «escrito y vaciado» para decidir si pone el texto por
     * defecto del informe.
     */
    public static function limpiar(?string $html): ?string
    {
        if ($html === null) {
            return null;
        }

        if (trim($html) === '') {
            return $html;
        }

        return self::purificador()->purify($html);
    }

    /**
     * Una sola instancia por petición: construir la configuración obliga a
     * HTMLPurifier a resolver su definición de HTML, que es la parte cara.
     */
    private static function purificador(): HTMLPurifier
    {
        if (self::$purificador !== null) {
            return self::$purificador;
        }

        $config = HTMLPurifier_Config::createDefault();

        $config->set('HTML.AllowedElements', self::ELEMENTOS);
        $config->set('HTML.AllowedAttributes', self::ATRIBUTOS);
        $config->set('CSS.AllowedProperties', 'color,background-color,text-align');

        // Sin esto `target="_blank"` se cae, y los enlaces del editor lo llevan.
        $config->set('Attr.AllowedFrameTargets', ['_blank']);
        $config->set('HTML.TargetBlank', false);

        // `javascript:` y `data:` fuera; el resto son los esquemas con los que
        // un docente enlaza de verdad.
        $config->set('URI.AllowedSchemes', [
            'http' => true,
            'https' => true,
            'mailto' => true,
        ]);

        // Por defecto HTMLPurifier serializa su caché dentro de `vendor/`, y
        // aquí `vendor/` es un symlink COMPARTIDO por los dieciséis colegios
        // (ver docs/DESPLIEGUE-REFERENCIA.md). Escribir ahí sería un colegio
        // pisando la caché de los otros, cuando no un fallo de permisos.
        $cache = storage_path('app/htmlpurifier');

        if (! File::exists($cache)) {
            File::makeDirectory($cache, 0755, true, true);
        }

        $config->set('Cache.SerializerPath', $cache);

        return self::$purificador = new HTMLPurifier($config);
    }
}
