<?php

use App\Support\Ancla;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * **El modelo de evaluación es una elección del colegio, y se guarda por año.**
 *
 * Fase 1 de `docs/migracion/35-el-modelo-de-evaluacion-del-colegio.md` §2, sobre
 * la decisión **D1** de `myvc_front/DECISIONES-MODELO-DE-EVALUACION.md` (13 sep
 * 2026). Las cuatro columnas van juntas porque son la misma decisión: **cuál es
 * el modelo y cómo llama el colegio a lo que ese modelo trae**.
 *
 * ```
 * modelo_evaluacion        enum('ponderado','competencias') NOT NULL DEFAULT 'ponderado'
 * desempeno_displayname    varchar(255) NOT NULL DEFAULT 'Desempeño'
 * desempenos_displayname   varchar(255) NOT NULL DEFAULT 'Desempeños'
 * genero_desempeno         varchar(1)   NOT NULL DEFAULT 'M'
 * ```
 *
 * ## Por año, y no por colegio ni por grupo — D1
 *
 * Un año cerrado **conserva el suyo para siempre**, igual que la plantilla y las
 * definitivas (regla 1 de la §4 del doc 28). Se descartó `grupos.modelo_evaluacion`
 * —lo específico de preescolar ya lo resuelven `unidades_por_defecto.nivel_educativo_id`
 * y `grupos.caritas`, y `caritas` ya enseñó lo que cuesta una columna así: su
 * defecto la apagaba y nadie lo vio hasta que se midió— y se descartó deducirlo de
 * los datos, que no deja por escrito qué eligió el colegio.
 *
 * ## Los dieciséis colegios amanecen en `ponderado` y NO notan nada — pero eso no
 * ## es «la misma respuesta que hoy»
 *
 * En el **comportamiento**, sí: el enum no toca ni un cálculo (D3, y hay un test
 * que lo sujeta, `ModeloDeEvaluacionDelAnioTest`). En los **bytes**, no: aparecen
 * cuatro claves nuevas en el objeto `year` de las respuestas que lo publican, y
 * esas respuestas son muchas porque `YearsController::getIndex` hace `SELECT y.*`
 * y `getColegio` hace `SELECT *`. Es literalmente el caso de
 * `docs/migracion/30-lo-que-reparte-una-columna-nueva.md`. Por eso la fase
 * regenera instantáneas y el diff se lee: lo único que puede aparecer son estas
 * cuatro claves.
 *
 * ## `NOT NULL` con `DEFAULT` en las cuatro, y eso es una afirmación
 *
 * `'ponderado'` afirma que **hoy los dieciséis evalúan como siempre**, que es
 * cierto por construcción: lo otro no existe todavía. `'Desempeño'` / `'Desempeños'`
 * / `'M'` afirman el vocabulario del **Decreto 1290** —el vigente, donde
 * «desempeño» sale 16 veces y «logro» e «indicador» ninguna (D15)—, y cada colegio
 * lo cambia desde la pantalla de configuración del año, igual que ya hace con
 * unidad y subunidad. Ninguna nace `NULL`: un rótulo que se imprime en el boletín
 * no puede tener un estado «todavía no se sabe».
 *
 * ## Quién puede escribirlas, y por qué no es el mismo para las cuatro — D24
 *
 * Ésta es la parte que no se ve en el esquema y decide si la columna sirve:
 *
 * - Los **tres rótulos** entran en `YearsController::putGuardarCambios`, al lado de
 *   los seis que ya están y con su mismo guard (`auth.personal`): son rótulos, y
 *   quien puede renombrar «Subunidad» puede renombrar «Desempeño». **Cero rutas.**
 * - **`modelo_evaluacion` NO**, y ésa es la decisión: los dieciséis `years/*` de
 *   escritura son `auth.personal`, o sea que colgarla de ahí dejaría que
 *   **cualquier docente cambiara el modelo de evaluación del colegio entero** desde
 *   un `PUT` de dos campos. Va por **ruta propia** —`PUT years/modelo-evaluacion`,
 *   con `Autoriza::puedeEditarPlantillaNotas` dentro—, que es la forma de
 *   `PlantillaNotasController` y por el mismo motivo: lo que configura el colegio,
 *   el docente no lo toca.
 *
 * Y por eso esta migración va acompañada de **dos cierres**, no de uno: el camino
 * nuevo que la escribe, y el camino genérico que había que cerrar. `PUT
 * years/toggle-cambiar-valor` escribe **cualquier columna de `years` que exista**
 * (`ColumnaSegura::exigir('years', $campo)`) con guard `auth.personal`; su
 * comentario justificaba que eso no fuera un agujero diciendo *«quien pasa
 * `auth.personal` ya las escribe todas por `years/guardar-cambios`»*, y con esta
 * columna **esa frase deja de ser cierta**. Sin el cierre, D24 se salta por la
 * puerta de al lado y nada lo diría.
 *
 * Es, por tercera vez en este mismo plan, la familia de `profesores.tono` —columna
 * leída en todas partes y escrita en ninguna— vista **antes** de cometerla, más su
 * contraria: columna escribible por quien no debía.
 *
 * ## `enum` y no `varchar`, y no `tinyint`
 *
 * `enum` porque el conjunto lo decide este repo y es cerrado: un valor que no sea
 * uno de los dos **no puede llegar a la fila**, y eso es una defensa que no hay que
 * escribir. `varchar` dejaría que un cliente futuro guardara `'Competencias'` con
 * mayúscula y partiera en dos el `if` de todas las pantallas. Y no es `tinyint(1)`
 * a propósito: un booleano llamado `usa_competencias` no tiene sitio para el tercer
 * modelo del día que llegue, y además caería en el montón de
 * `tools/interruptores-que-nadie-lee.py`, que es exactamente lo que D1 descartó al
 * rechazar «un interruptor por entrega».
 *
 * ## UN `Schema::table` y un solo `ALTER`
 *
 * Por lo que ya dejó escrito `2026_09_02_100000_nivelaciones_columnas`: **nadie sabe
 * qué MySQL corren los diecisiete**, y en 5.7 cada sentencia `ALTER` reconstruye la
 * tabla entera bloqueando las escrituras. Aquí no muerde —`years` tiene una fila por
 * año, no un millón de notas—, pero el criterio no depende del tamaño y quien copie
 * este bloque para otra tabla se lleva el criterio con él.
 *
 * ## Dónde se colocan, que no es cosmético
 *
 * Este proyecto lee con `SELECT *` por todas partes y las instantáneas de contrato
 * fijan **el orden de los campos**. Las cuatro se colocan con `after()` y no al
 * final de la tabla:
 *
 * - `modelo_evaluacion` detrás de `horario_version_id`, o sea **pegada a
 *   `regla_nivelacion`**: son las dos políticas del año que el colegio elige una vez
 *   y gobiernan lo que se escribe después.
 * - los **tres rótulos** detrás de `genero_subunidad`, cerrando el bloque de
 *   vocabulario —unidad, subunidad, desempeño— que ya existe y que se lee junto en
 *   las cuatro ramas de `ContextoDeUsuario`.
 *
 * Quien reordene estas declaraciones «para que se lean mejor» mueve campos de sitio
 * en respuestas vivas, y lo que se rompe son las instantáneas, no la migración.
 *
 * ## Lo que esta migración NO hace, y no es un olvido
 *
 * 1. **No recalcula ni borra nada, ni ahora ni nunca** (D3). Cambiar el enum no
 *    toca una nota, una definitiva ni una frase: *volver atrás es cambiar el enum*.
 *    Esa propiedad es lo que hace que esta fase se pueda desplegar a los dieciséis
 *    sin avisar, y tiene su test.
 * 2. **No toca `subunidad_displayname`.** D15 dice que la pantalla de configuración
 *    deja de **sugerir** «Indicador», y eso es un cambio del front: el valor que cada
 *    colegio guardó **se queda como está**. Una migración que lo «arreglara» le
 *    cambiaría el rótulo del boletín a quien lo eligió a propósito.
 * 3. **No siembra ningún permiso.** `can_edit_plantilla_notas` ya existe
 *    (`2026_09_05_300000`) y es el que gobierna la ruta nueva: **cero permisos
 *    nuevos** (D13).
 * 4. **No crea ninguna tabla de competencias ni de desempeños.** Ésas son las fases
 *    2 y 3, y cada una va con su migración y su despliegue.
 *
 * ## Volver atrás
 *
 * Es aditiva pura, así que `down()` sólo quita las cuatro columnas y **no pierde
 * ninguna nota**: lo único que se pierde es qué modelo había elegido cada colegio,
 * que al volver el código viejo tampoco lo lee nadie. Vale aquí el «Paso 4» de
 * `docs/DESPLIEGUE.md` tal como está escrito, al revés que la migración de la Fase 0.
 */
class ModeloDeEvaluacionDelAnio extends Migration
{
    public function up()
    {
        // Las cuatro en UNA llamada: un solo ALTER. Ver la cabecera antes de partirlo.
        Schema::table('years', function (Blueprint $tabla) {
            $tabla->enum('modelo_evaluacion', ['ponderado', 'competencias'])
                ->default('ponderado')
                ->after(Ancla::de($tabla, 'horario_version_id'));

            $tabla->string('desempeno_displayname', 255)
                ->default('Desempeño')
                ->after(Ancla::de($tabla, 'genero_subunidad'));
            $tabla->string('desempenos_displayname', 255)
                ->default('Desempeños')
                ->after(Ancla::de($tabla, 'desempeno_displayname'));
            $tabla->string('genero_desempeno', 1)
                ->default('M')
                ->after(Ancla::de($tabla, 'desempenos_displayname'));
        });
    }

    public function down()
    {
        Schema::table('years', function (Blueprint $tabla) {
            $tabla->dropColumn([
                'modelo_evaluacion',
                'desempeno_displayname',
                'desempenos_displayname',
                'genero_desempeno',
            ]);
        });
    }
}
