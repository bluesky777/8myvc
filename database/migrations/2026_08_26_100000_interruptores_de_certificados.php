<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Los dos interruptores del certificado: **el consecutivo y el folio, por colegio**.
 *
 * Decisión de Joseth del 26 ago 2026: *«hay colegios a los que no les importa llevar esos
 * contadores o folios; que tengan la opción»*. El porqué de cada uno y lo que se midió
 * antes de proponerlo están en `docs/migracion/21-certificados-y-folios.md`.
 *
 * ## Por qué en `years` y no en `config_certificados`
 *
 * Porque **`years` ES la configuración del colegio en este sistema**, versionada por año:
 * lleva `nombre_colegio`, `codigo_dane`, `resolucion` y una docena de interruptores
 * `tinyint(1)` del mismo estilo (`mostrar_puesto_boletin`, `alumnos_can_see_notas`,
 * `show_fortaleza_bol`), y `YearsController` los **copia de un año al siguiente**.
 * `config_certificados` es **maquetación** —imágenes de encabezado y pie, márgenes— y no
 * tiene nada que ver con si el colegio numera sus constancias.
 *
 * Van **al lado de las dos columnas que gobiernan**, que además es donde los va a buscar
 * quien lea `contador_certificados` y se pregunte quién decide si eso se imprime.
 *
 * ## Lo que hace que esto sea seguro en quince producciones: **no hay valor por defecto**
 *
 * **Los dos interruptores se derivan de lo que cada colegio hace HOY**, fila a fila, y no
 * se siembra un `1` ni un `0` a ciegas:
 *
 *     usa_consecutivo_certificados = (contador_certificados <> '')
 *     usa_folio_certificados       = (contador_folios       <> '')
 *
 * Y la derivación no es una suposición: **hoy el interruptor ya existe, escondido en el
 * vacío de esos dos `varchar`**, y lo ejerce el front. Las dos casillas del certificado se
 * ocultan solas cuando la columna está vacía, en la misma plantilla:
 *
 *     ng-class="{'hidden-print': year.contador_certificados.length == 0}"   (el «No.»)
 *     ng-class="{'hidden-print': year.contador_folios.length == 0}"         (el «Folio:»)
 *
 * O sea que esta migración **no estrena una conducta: le pone nombre a la que ya había**.
 * El día del despliegue **ningún colegio imprime nada distinto** — que es la única forma
 * de meter una decisión de formato en papel oficial sin avisar a quince secretarías.
 *
 * *(Medido en la copia local de `simonbolivar`, que es **un** colegio de los quince: sus 8
 * años vivos tienen los dos contadores no vacíos, así que ahí los dos salen encendidos.
 * En los otros catorce sale lo que diga su base, y por eso la derivación va en SQL y no en
 * una lista escrita a mano.)*
 *
 * ## Lo que esta migración NO hace, y no es olvido
 *
 * **No toca `matriculas.nro_folio`.** Hay 1.869 folios fabricados por el sistema
 * —`año-alumno_id`, que no es la hoja de ningún libro, y 257 de ellos nombran a **otro**
 * alumno— y borrarlos **cambia lo que hoy sale impreso**. Que se limpien o se dejen es
 * decisión de Joseth y es un `UPDATE`, no una migración. Lo que sí entra en este lote es
 * **dejar de fabricar más**.
 */
class InterruptoresDeCertificados extends Migration
{
    public function up()
    {
        Schema::table('years', function (Blueprint $tabla) {
            // Sin `default` en el esquema **a propósito**: el valor de cada fila lo pone el
            // `UPDATE` de abajo desde lo que ese colegio hace hoy. Un default aquí sería
            // exactamente la suposición que esta migración existe para no hacer. Lleva 0
            // sólo porque la columna es NOT NULL y las filas ya existen; se pisa acto
            // seguido, y el `after` las deja pegadas a lo que gobiernan.
            $tabla->boolean('usa_consecutivo_certificados')->default(0)->after('contador_certificados');
            $tabla->boolean('usa_folio_certificados')->default(0)->after('contador_folios');
        });

        // La derivación, en una sentencia y sobre TODAS las filas —incluidas las de la
        // papelera—: un año borrado que vuelva no puede volver con el certificado cambiado.
        DB::update("UPDATE years
            SET usa_consecutivo_certificados = (contador_certificados <> ''),
                usa_folio_certificados       = (contador_folios       <> '')");
    }

    public function down()
    {
        Schema::table('years', function (Blueprint $tabla) {
            $tabla->dropColumn(['usa_consecutivo_certificados', 'usa_folio_certificados']);
        });
    }
}
