<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * **Cómo se llamaba la COMPETENCIA el día que se marcó la casilla.**
 *
 * Es la cuarta columna de `frases_asignatura`, y es el mismo argumento de la
 * tercera —`nivel`, de `2026_09_13_400000_marca_del_desempeno`— **un piso más
 * arriba**. Las tres hermanas dicen lo mismo con tres tablas distintas:
 *
 *     frases_asignatura + frase_id     … + frase          la frase del catálogo
 *                       + escala_id    … + nivel          el nivel de la escala
 *                       + desempeno_id … + frase          el texto del desempeño
 *                       + (su competencia, por el salto)  → **competencia**   ← ésta
 *
 * **El id pinta y el texto imprime.** Lo dice entero el bloque «Tres columnas y no
 * una» de la migración de la Fase 4, y lo repite
 * [35](../../docs/migracion/35-el-modelo-de-evaluacion-del-colegio.md) en *«Lo que
 * sigue abierto de verdad»*.
 *
 * ## Qué está roto hoy, en una frase
 *
 * La cabecera de cada bloque del boletín por competencias sale de
 * `competencias.definicion` **leída hoy**: `frases_asignatura` guarda de qué
 * desempeño salió la celda (`desempeno_id`) pero **no de qué competencia**, y a la
 * competencia se llega saltando por `desempenos.competencia_id`. Las dos tablas
 * del salto son **editables y borrables**:
 *
 *   - `CompetenciasController::putUpdate` hace `UPDATE … SET definicion = …`
 *     **sobre la fila viva**, la misma que un boletín de 2026 alcanzaría por su id,
 *     así que **renombrar una competencia en 2028 cambia la cabecera de un boletín
 *     de 2026**;
 *   - y `YearsController::copiarElPlanDeArea` copia las competencias al año
 *     siguiente con un `INSERT` **sin el `id`**, o sea con ids nuevos: la de 2027 y
 *     la de 2026 son dos filas distintas y **cada una se puede renombrar por su
 *     lado**, que es la misma forma por la que las escalas necesitaron su copia.
 *
 * ## Por qué ahora y no «cuando duela»
 *
 * **Esta columna sólo sirve si existe ANTES del primer renombrado.** Lo que se
 * pierde no se recupera: el día que un colegio le corrija el texto a una
 * competencia, los boletines ya impresos de los años anteriores pasan a decir otra
 * cosa y **no hay de dónde sacar la que decían**. No es una optimización que se
 * pueda posponer sin coste: posponerla tiene un coste que crece solo.
 *
 * ## Anulable y sin back-fill, por lo mismo que las tres de la Fase 4
 *
 * `frases_asignatura` tiene **12.294 filas** en `simonbolivar` (medido en la Fase
 * 0) y **ninguna** salió de una rejilla. `NULL` aquí significa exactamente *«esta
 * fila no congeló ninguna competencia»*, y son **dos casos distintos que el
 * boletín tiene que tratar igual**:
 *
 *   - la fila es **vieja** —se escribió antes de esta migración—, y entonces no hay
 *     nada que decir de su competencia;
 *   - o el desempeño **no tiene competencia**, que es legal por **D10** y es el caso
 *     del desempeño suelto de la §4.3 del plan del front.
 *
 * Un back-fill con `competencias.definicion` de hoy sería **inventarse un dato**:
 * afirmaría que esa celda se guardó con el texto que la competencia tiene ahora, y
 * eso es justo lo que esta columna existe para no tener que suponer.
 *
 * ## `text` y no `varchar(255)`, y la diferencia importa
 *
 * `nivel` es `varchar(255)` porque es lo que mide `escalas_de_valoracion.desempenio`,
 * de donde copia. Aquí se copia de **`competencias.definicion`, que es `text`**
 * (`2026_09_13_200000_competencias`), igual que `frases_asignatura.frase` — que se
 * volvió `text` en `2026_09_05_100000_frase_del_boletin_en_text` justamente porque
 * un `varchar(255)` **cortaba sin avisar**: MySQL sin `STRICT_TRANS_TABLES` trunca y
 * devuelve 200. Una competencia del catálogo del MEN pasa de 255 con holgura.
 *
 * ## Ni índice ni clave ajena
 *
 * **Índice ninguno**: nadie busca *por* este texto. Se lee siempre acompañando a la
 * fila que ya se encontró por `alumno_id`/`asignatura_id`/`periodo_id`, que es lo
 * que resuelven los índices que ya hay.
 *
 * **FK ninguna**, y por el motivo de `2026_09_13_400000`: no hay a qué apuntar. Esto
 * no es un id, es una copia de texto — y ésa es la mitad del diseño. La competencia
 * de la que salió se sigue alcanzando por `desempenos.competencia_id`, que es quien
 * **agrupa**; esta columna sólo **imprime**.
 *
 * ## Ningún dato existente se mueve, y el boletín de hoy no cambia ni un byte
 *
 * Un `ADD COLUMN` anulable. Ni `UPDATE`, ni back-fill, ni `DEFAULT` que escriba
 * nada. `FraseAsignatura::deAlumno` —lo que leen los tres boletines de siempre—
 * **nombra sus columnas una a una** y no trae ésta.
 *
 * > **La puerta que sí se abre, la misma que dejó escrita la Fase 4:**
 * > `DELETE api/frases_asignatura/destroy/{id}` acaba en `return $frase;` con un
 * > modelo Eloquent, así que su respuesta pasa a traer **también** esta columna. Es
 * > aditivo y nadie la lee, pero queda dicho aquí en vez de descubrirse dentro de dos
 * > años.
 */
class CompetenciaCongelada extends Migration
{
    public function up()
    {
        Schema::table('frases_asignatura', function (Blueprint $tabla) {
            $tabla->text('competencia')->nullable()->after('nivel');
        });
    }

    /*
     * Se pierde de qué se llamaba la competencia y **no se pierde ni una frase**: el
     * texto del desempeño vive en `frase` y el nivel en `nivel`, que esta migración
     * no toca. El boletín vuelve a leer `competencias.definicion` de hoy, que es
     * exactamente lo que hacía antes de esta entrega.
     *
     * Es aditiva, así que el «Paso 4. Volver atrás» de `docs/DESPLIEGUE.md` vale tal
     * cual: el código vuelve y la columna se queda. Este `down()` es para poder
     * probar la migración, no para el despliegue.
     */
    public function down()
    {
        Schema::table('frases_asignatura', function (Blueprint $tabla) {
            $tabla->dropColumn('competencia');
        });
    }
}
