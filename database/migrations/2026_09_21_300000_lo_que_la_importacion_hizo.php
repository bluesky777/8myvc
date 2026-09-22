<?php

use App\Support\Ancla;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **Lo que la importación HIZO**, que hasta hoy se perdía al contestar.
 *
 * Fase 5 de «notas sin internet». `POST planilla-offline/importar` ya devuelve
 * `hechos` y `por_hoja` con lo que de verdad entró —salen del mismo recorrido que
 * lo escribió, no del plan—, pero eso **vive en la respuesta HTTP y se va con
 * ella**. El acta (`GET planilla-offline/acta/{id}`) tiene que poder contarlo
 * semanas después, cuando el docente y coordinación no están de acuerdo, y para
 * eso el recuento tiene que estar en la base.
 *
 * ## Por qué NO basta con el plan ni con `avance`
 *
 * Son tres cosas distintas y sólo la tercera sirve de acta:
 *
 * 1. **El plan** (lo que el ensayo prometió) no es lo que pasó. Una petición que
 *    se queda sin presupuesto escribe una fila y promete cuarenta; una que revienta
 *    a la mitad escribe las de antes del error. Un acta que contara el plan diría
 *    «entraron 312 notas» de una importación en la que entraron once.
 * 2. **`avance`** dice por qué fila iba, no qué escribió: una fila que sólo tenía
 *    casillas vacías cuenta igual que una que cambió cinco notas.
 * 3. **`avisos`** (la columna de al lado) guarda lo que no se supo traducir. Es la
 *    mitad mala del acta y ya se acumula bien; ésta es la otra mitad.
 *
 * ## Qué lleva dentro, y por qué una columna y no seis
 *
 * Lleva el recuento acumulado —notas escritas, borradas, faltas creadas y
 * borradas, filas descartadas, indicadores creados—, el mismo desglose **por
 * hoja**, los motivos por los que algo se quedó fuera, y el contexto que el acta
 * necesita imprimir en la cabecera: **quién subió, por cuenta de quién**, de qué
 * colegio y periodo es el libro, y cuántas pasadas hicieron falta.
 *
 * Una sola columna porque **nada de esto se consulta desde SQL**: el acta lee la
 * fila entera, la decodifica en PHP y la pinta. Seis columnas serían seis ALTERs
 * en dieciséis colegios para ganar unos `WHERE` que nadie va a escribir — y el
 * desglose por hoja no cabe en una columna escalar de todos modos.
 *
 * ## `longText` y no `json`, por lo mismo que sus dos hermanas
 *
 * Producción es **MariaDB 10.5**, donde `JSON` es un alias de `LONGTEXT` con un
 * `CHECK (json_valid(...))` detrás; el docker es MySQL 8, donde es un tipo nativo.
 * Declararlo `json()` deja dos esquemas que no son el mismo y un CHECK que puede
 * abortar una escritura **en los dieciséis y en ninguna suite de aquí**. Es la
 * misma decisión —y el mismo párrafo— de `2026_09_20_400000_lo_que_la_importacion_recuerda`
 * y de `2026_09_21_200000_el_valor_entero_de_la_auditoria`. Lo que se guarda es
 * JSON igual; lo que no depende del motor es el tipo de la columna.
 *
 * ## Anulable, y eso significa algo
 *
 * `NULL` es «esta importación es anterior al acta» —las de `tipo = 'alumnos'` y
 * las planillas de antes de esta migración—, no «no escribió nada», que se
 * escribe con los contadores en cero. El acta lo distingue: de una fila con
 * `hechos` en `NULL` contesta 404 con su motivo en vez de imprimir un acta de
 * ceros que parecería decir que la importación no hizo nada.
 *
 * ## Idempotente, por lo mismo que sus hermanas
 *
 * De las bases `%testing%` del docker la mayoría son de otras sesiones y no
 * tienen esta columna. Una migración que no comprueba antes revienta con
 * `Duplicate column name` en cuanto dos árboles la corren sobre la misma base.
 *
 * ## Volver atrás
 *
 * Aditiva pura: una columna nueva a la que no apunta nada. `down()` la tira y se
 * pierde el acta de lo ya importado — **no** ninguna nota: las notas están en
 * `notas`, esto es el recuento de cómo llegaron.
 */
class LoQueLaImportacionHizo extends Migration
{
    public function up()
    {
        Schema::table('importaciones', function (Blueprint $table) {
            if (! Schema::hasColumn('importaciones', 'hechos')) {
                $table->longText('hechos')->nullable()->after(Ancla::de($table, 'respuestas'));
            }
        });
    }

    public function down()
    {
        Schema::table('importaciones', function (Blueprint $table) {
            if (Schema::hasColumn('importaciones', 'hechos')) {
                $table->dropColumn('hechos');
            }
        });
    }
}
