<?php

use App\Support\Ancla;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * **El título que va impreso arriba de los dos certificados**, y se guarda por año.
 *
 * ```
 * titulo_certificado_final     varchar(255) NOT NULL DEFAULT 'CONSTANCIA DE DESEMPEÑO ACADÉMICO'
 * titulo_certificado_periodos  varchar(255) NOT NULL DEFAULT 'CONSTANCIA DE DESEMPEÑO ACADÉMICO PARCIAL'
 * ```
 *
 * Encargo de Joseth del 15 sep 2026: los dos certificados de *Informes → Finales*
 * —«Certificado final» y «Certificado periodos»— llevan el título **escrito dentro
 * de la plantilla**, y el colegio no puede cambiarlo. Con esto lo cambia, y sin
 * tocar código. El detalle entero está en
 * `docs/migracion/38-los-titulos-del-certificado.md`.
 *
 * ## SON DOS COLUMNAS PORQUE SON DOS PAPELES, aunque hoy digan lo mismo
 *
 * Los dos certificados comparten plantilla en los dos fronts —una sola directiva en
 * el legacy (`certificadoEstudioDir.html`), un solo componente en `app2`
 * (`certificado-estudio.html`, dos rutas)—, así que hoy su título es el mismo **por
 * construcción y no por decisión de nadie**. Una sola columna congelaría ese empate
 * para siempre, y son papeles distintos: uno certifica el año cerrado y el otro
 * «hasta el periodo que usted elija».
 *
 * Y por eso **no** van en `config_certificados`, que es donde parecería que van:
 * esa tabla es el **membrete** —imágenes de encabezado y pie con sus márgenes—, no
 * tiene `year_id` y **el año elige una sola fila** (`years.config_certificado_estudio_id`).
 * Dos títulos vivos a la vez no caben ahí. Aquí sí, al lado de
 * `encabezado_certificado` y `frase_final_certificado`, que son los otros dos textos
 * del mismo papel.
 *
 * ## ESTA MIGRACIÓN SÍ CAMBIA LO QUE SE IMPRIME, y va en mayúsculas a propósito
 *
 * Las dos últimas columnas de políticas del año —`modelo_evaluacion`,
 * `reparto_subunidades`— nacieron con el defecto que afirmaba «esto es lo que hacen
 * hoy los dieciséis», y no movían un papel. **Ésta no puede hacer esa afirmación,
 * porque hoy no hay un texto: hay tres**, medidos el 15 sep 2026 en los dos
 * repositorios de front:
 *
 *     legacy  certificadoEstudioDir.html:11    coal/coljordan  CERTIFICADO DE DESEMPEÑO
 *     legacy  certificadoEstudioDir.html:11    los otros 14    CONSTANCIA DE DESEMPEÑO
 *     app2    certificado-estudio.html:41      coal/coljordan  CERTIFICADO DE DESEMPEÑO
 *     app2    certificado-estudio.html:41      los otros 14    CONSTANCIA DE DESEMPEÑO ACADÉMICO
 *
 * Los dos fronts **ya se contradicen** para los mismos catorce colegios: `app2`
 * añadió «ACADÉMICO» al migrar la pantalla y nadie lo notó, porque los dos papeles
 * no se miran juntos nunca. Y `coal` y `coljordan` llevan otro título decidido por
 * `document.domain`, o sea por la URL desde la que se abrió el navegador.
 *
 * Joseth eligió el 15 sep 2026, con esas cuatro filas delante:
 *
 *  1. **El defecto del final es `CONSTANCIA DE DESEMPEÑO ACADÉMICO`** para los
 *     dieciséis. El legacy gana la palabra «ACADÉMICO» en los catorce el día del
 *     despliegue.
 *  2. **El del parcial lleva `PARCIAL` detrás**, y eso arregla de paso algo que el
 *     encargo no pedía: los dos papeles decían lo mismo **porque comparten
 *     plantilla**, así que un certificado emitido a mitad de año se leía como si
 *     certificara el año entero.
 *  3. **`coal` y `coljordan` amanecen con los defectos como todos**, y los corrigen
 *     desde la pantalla. Eso es lo que permite quitarle el título al condicional de
 *     dominio en vez de dejarlo vivo como respaldo: un título que depende de la URL
 *     no lo puede cambiar el colegio, que es justo el problema que esto viene a
 *     resolver.
 *
 * O sea que el día del despliegue **los dieciséis certificados cambian de título**.
 * Es una decisión tomada, no un efecto secundario, y por eso está escrita aquí y en
 * el §4 del doc 38: quien lea esta migración dentro de un año tiene que poder
 * distinguir *«se decidió»* de *«se coló»*.
 *
 * ## `NOT NULL` con `DEFAULT`, y no anulable
 *
 * `ALTER TABLE ... ADD COLUMN ... NOT NULL DEFAULT` rellena las filas que ya existen
 * con el defecto, así que los años cerrados quedan con el título puesto y no hay que
 * tocar una fila.
 *
 * No nace `NULL` porque **un certificado sin título no es un estado que exista**: el
 * `@if` que lo leyera tendría que inventarse un texto de respaldo, y ese texto sería
 * otra vez una cadena escrita dentro de la plantilla — exactamente lo que se está
 * quitando. Por lo mismo, la escritura **rechaza la cadena vacía** (422 en
 * `ConfigCertificadosController::putEncabezado`); el tope de 255 se valida ahí
 * también y no se le deja a la base, porque el docker trunca en silencio y MariaDB
 * aborta.
 *
 * ## Dónde se colocan, que no es cosmético
 *
 * Pegadas a `frase_final_certificado`, o sea dentro del bloque de textos del
 * certificado. Este proyecto lee con `SELECT *` por todas partes y las instantáneas
 * de contrato fijan **el orden de los campos**: el sitio se elige una vez.
 *
 * ## Las instantáneas que mueve, contadas antes de correrla
 *
 * **Veinticinco**, y se cuentan en dos tramos porque tienen causas distintas:
 *
 *  - **6** llevan la fila entera de `years` (`SELECT *` o Eloquent crudo) y se
 *    mueven solas: `muestreo-years.json`, `muestreo-years-colegio.json`,
 *    `muestreo-years-trashed.json`, `years-store.json`, `years-delete.json` y
 *    `years-guardar-cambios.json`. Medido con
 *    `tools/lo-que-reparte-una-columna.py years`.
 *  - **19** llevan la proyección nombrada de `Year::datos()` —36 de las 74 columnas
 *    el día de la medición, 38 de 76 con éstas— y
 *    **serían inmunes si no las tocara nadie** — pero las dos columnas tienen que
 *    entrar en esa proyección, que es por donde el certificado recibe el año. O sea
 *    que estas diecinueve las mueve **la edición del modelo**, no la migración.
 *
 * ## Volver atrás
 *
 * Aditiva pura: `down()` quita las dos columnas y no pierde ninguna nota. Lo único
 * que se pierde es el título que cada colegio hubiera escrito, que al volver el
 * código viejo tampoco lo leería nadie. Vale el «Paso 4» de `docs/DESPLIEGUE.md`
 * tal cual.
 */
class TitulosDelCertificado extends Migration
{
    /**
     * Los dos defectos, **y son distintos**.
     *
     * Decisión de Joseth del 15 sep 2026, sobre la primera versión de esta migración
     * que les puso el mismo: el certificado por periodos se emite con **el año sin
     * cerrar**, así que un título que no lo diga afirma en papel firmado algo que no
     * es. La palabra que los separa es la que sobra en el otro: `PARCIAL`.
     *
     * Y los dos conservan «constancia de desempeño» porque es el término del
     * **Decreto 1290 art. 17**, que es justamente el que habla de las constancias «con
     * los resultados de los informes periódicos» —o sea que la palabra le corresponde
     * al parcial todavía más que al final— (`docs/migracion/21-certificados-y-folios.md`
     * §1).
     *
     * **Con sus propios literales, no `Year::TITULOS_POR_DEFECTO`**: una migración es
     * lo que pasó un día concreto, y leer el defecto de una constante haría que
     * cambiarla reescribiera hacia atrás lo que esta migración hizo en los dieciséis
     * colegios. Que sigan coincidiendo lo comprueba
     * `TitulosDelCertificadoTest::el_defecto_del_modelo_y_el_de_la_base_son_el_mismo`,
     * que es lo que hace segura la duplicación.
     */
    private const DEFECTO_FINAL = 'CONSTANCIA DE DESEMPEÑO ACADÉMICO';

    private const DEFECTO_PERIODOS = 'CONSTANCIA DE DESEMPEÑO ACADÉMICO PARCIAL';

    public function up()
    {
        Schema::table('years', function (Blueprint $tabla) {
            $tabla->string('titulo_certificado_final', 255)
                ->default(self::DEFECTO_FINAL)
                ->after(Ancla::de($tabla, 'frase_final_certificado'));

            $tabla->string('titulo_certificado_periodos', 255)
                ->default(self::DEFECTO_PERIODOS)
                ->after(Ancla::de($tabla, 'titulo_certificado_final'));
        });
    }

    public function down()
    {
        Schema::table('years', function (Blueprint $tabla) {
            $tabla->dropColumn(['titulo_certificado_final', 'titulo_certificado_periodos']);
        });
    }
}
