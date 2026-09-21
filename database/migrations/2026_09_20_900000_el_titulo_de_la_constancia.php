<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * **El título de la constancia de estudio, y se guarda por año.**
 *
 * ```
 * titulo_constancia_estudio  varchar(255) NOT NULL DEFAULT 'CONSTANCIA DE ESTUDIO'
 * ```
 *
 * La pidió `myvc_front` el 20 sep 2026 al entregar el informe de constancia de
 * estudio, y **la autorizó Joseth ese día con el precio delante**: mueve seis
 * instantáneas, medidas antes de escribirla con `tools/lo-que-reparte-una-columna.py`
 * —`muestreo-years.json`, `muestreo-years-colegio.json`, `muestreo-years-trashed.json`,
 * `years-store.json`, `years-guardar-cambios.json` y `years-delete.json`, que son las
 * seis respuestas que llevan la fila de `years` entera—.
 *
 * ## ES LA TERCERA HERMANA, y por la misma razón que las dos primeras
 *
 * `titulo_certificado_final` y `titulo_certificado_periodos` entraron el 15 sep
 * (`2026_09_15_100000`, doc 38) porque el título iba **escrito dentro de la plantilla
 * del front** y el colegio no podía cambiarlo. La constancia de estudio es el tercer
 * papel de esa misma familia y nació con el mismo defecto: hoy su título es el literal
 * `CONSTANCIA DE ESTUDIO`, escrito en el informe nuevo.
 *
 * **Y va por año y no en `config_certificados` por lo mismo que sus dos hermanas**:
 * de los años cerrados se siguen pidiendo papeles, así que el título tiene que ser el
 * que el colegio usaba **ese** año. `config_certificados` es el membrete —imágenes y
 * márgenes—, no tiene `year_id` y el año elige una sola fila de ella.
 *
 * ## EL DEFECTO SÍ AFIRMA «ESTO ES LO QUE HACEN HOY LOS DIECISÉIS», y aquí se puede
 *
 * Es la diferencia con la migración del 15 sep, que **no podía** hacer esa afirmación
 * porque había tres textos distintos repartidos por dos fronts. Aquí sólo hay uno:
 * el informe de constancia de estudio es **de esta semana** y todavía no está en
 * ningún colegio, así que el literal que imprime hoy —`CONSTANCIA DE ESTUDIO`— es el
 * único texto que existe. El día del despliegue ningún papel cambia.
 *
 * **El front ya la lee de forma opcional**: si la columna no ha llegado a ese colegio
 * imprime el literal, y el día que llega usa el título del año sin tocar código. O sea
 * que esta migración y el despliegue del front **no tienen que ir juntos**, que es lo
 * que las hace baratas de soltar.
 */
return new class extends Migration
{
    /**
     * **Con su propio literal y no `Year::TITULOS_POR_DEFECTO`**, igual que su hermana
     * del 15 sep y por la misma razón: una migración es lo que pasó un día concreto, y
     * leer el defecto de una constante haría que cambiarla reescribiera hacia atrás lo
     * que esta migración hizo en los dieciséis colegios. Que los dos sigan coincidiendo
     * lo comprueba `TitulosDelCertificadoTest`, que cruza la constante con `SHOW
     * COLUMNS` — y por eso la duplicación es segura.
     */
    private const DEFECTO = 'CONSTANCIA DE ESTUDIO';

    public function up()
    {
        Schema::table('years', function (Blueprint $tabla) {
            $tabla->string('titulo_constancia_estudio', 255)
                ->default(self::DEFECTO)
                ->after('titulo_certificado_periodos');
        });
    }

    public function down()
    {
        Schema::table('years', function (Blueprint $tabla) {
            $tabla->dropColumn('titulo_constancia_estudio');
        });
    }
};
