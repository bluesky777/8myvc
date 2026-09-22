<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Lo que el colegio puso en la plantilla no lo cambia un docente.
 *
 * **P6 del modelo de evaluación**, decidida por Joseth el 19 sep 2026. Una unidad
 * o una subunidad con `por_defecto = 1` es **una copia de la plantilla del
 * colegio** —`unidades_por_defecto` / `subunidades_por_defecto`—, sembrada por
 * `UnidadesController` la primera vez que alguien abre la pantalla de la
 * asignatura. Copiar por dentro y comportarse como referencia por fuera es la
 * decisión escrita (P6.bis, 17 sep): compartir la fila se descartó porque una
 * unidad **es un peso**, entra en cada definitiva, y corregir el 70 % en octubre
 * recalcularía boletines del periodo 1 que ya fueron a casa.
 *
 * Lo que faltaba era la otra mitad: **si es copia y nadie la protege, el docente
 * la edita y la plantilla no vale nada**. Hasta hoy `PUT unidades/update/{id}`
 * llevaba sólo `auth.personal`, así que cualquier docente podía renombrar y
 * recambiar el porcentaje de una fila del colegio. Ese agujero no era una
 * regresión: llevaba abierto desde siempre.
 *
 * ## Lo que NO bloquea, y es la mitad que hace esto usable
 *
 * **Añadir subunidades dentro de una unidad del colegio sigue permitido** — es el
 * trabajo del docente (D14) y es exactamente lo que el colegio espera de él.
 * Aquí sólo se frenan **el nombre y el porcentaje de una fila que es del
 * colegio**. Lo que el docente creó (`por_defecto = 0`) no lo mira este candado.
 *
 * ## Se compara el VALOR, no la presencia del campo
 *
 * Un cliente que reenvía el formulario entero manda `definicion` y `porcentaje`
 * aunque el usuario no los haya tocado. Si el candado mirase *«¿vino el campo?»*,
 * guardar un cambio legítimo —el orden, una subunidad nueva— contestaría 403 y la
 * pantalla parecería averiada. Mirando *«¿cambia el valor?»* el candado sólo salta
 * cuando de verdad se intenta cambiar algo, y reenviar lo mismo es lo que siempre
 * fue: no hacer nada.
 *
 * ## LAS DOS CORRECCIONES DEL 19 SEP, y las dos las encontró otra sesión
 *
 * La primera versión de esta clase se fundió con dos agujeros. Los destapó
 * `8myvc-47` comparándola contra su propia implementación de lo mismo —escrita en
 * paralelo y sin vernos— y **los dos se reprodujeron aquí antes de aceptarlos**.
 * Se dejan escritos porque los dos son fáciles de volver a cometer:
 *
 * **1. Un `null` PEDIDO no es «no vino»: es borrar.** Medido en el contenedor,
 * con un cuerpo `{"porcentaje": null}`:
 *
 *     Request::input('porcentaje', 70)  ->  NULL     <- esto es lo que se escribe
 *     array_key_exists('porcentaje')    ->  true
 *
 * O sea que el defecto de `Request::input()` **tapa la clave ausente pero no el
 * null escrito** —es la frontera de la §93—, y un candado que tratara `null` como
 * «no cambia» dejaría pasar justo la escritura más destructiva de las tres que
 * puede recibir el campo: la que se lleva el peso de la unidad en la definitiva.
 * De ahí que `cambia()` diga que **sí** cambia cuando el pedido es `null` y había
 * algo guardado.
 *
 * **Y por eso el llamante pasa lo que DE VERDAD llegó** —`Request::all()`— y no
 * `['x' => Request::input('x')]`: construido así, la clave existe siempre y el
 * `null` de «no vino» y el `null` de «bórralo» se vuelven indistinguibles **antes
 * de llegar aquí**, donde ya no hay nada que decidir.
 *
 * **2. Reordenar no se comprueba fila a fila DENTRO del bucle.** Ver
 * `exigirElLote()`, que lo cuenta entero.
 *
 * ## Por qué 403 y no 422
 *
 * El cuerpo es correcto y los valores son válidos: lo que falta es **permiso**.
 * 422 haría que el front enseñara «revisa los campos», que es mentira — no hay
 * nada que revisar, hay que pedirle a la coordinación que lo cambie en la
 * plantilla.
 *
 * ## Estuvo apagado unas horas el 21 sep 2026, y el porqué importa
 *
 * A la mañana siguiente de desplegarlo, los colegios reportaron que los docentes no
 * podían cambiar el porcentaje de subunidades **con las que llevaban meses
 * trabajando**. El candado hacía lo que se decidió; lo que no significa lo que
 * parece es la columna: `por_defecto = 1` no quiere decir «la puso la coordinación»,
 * quiere decir «salió de la plantilla del año», y la marca el sembrador viejo
 * (`UnidadesController:173`, `true` literal) en **toda** unidad y subunidad que crea
 * la primera vez que alguien abre una asignatura sin unidades. O sea casi todas.
 * `created_by` tampoco las distingue: es el propio docente que abrió la pantalla.
 *
 * **Joseth lo resolvió el mismo día y contra la propuesta que se le llevó**, que era
 * distinguir cuáles se había hecho suyas el docente:
 *
 * > Que el sistema cree la unidad al abrir una asignatura vacía **no la hace del
 * > docente**. La creó el sistema, sale de la plantilla, y por defecto es. El docente
 * > no la edita. Y si la edita alguien con `can_edit_plantilla_notas`, **la auditoría
 * > tiene que decir quién**.
 *
 * Así que el interruptor de suspensión **desaparece** —una constante que ya no puede
 * cambiar es código muerto con forma de decisión— y lo que entra en su lugar es la
 * línea de auditoría en los dos `putUpdate`. Vuelve lo que reportaron los colegios y
 * se acepta a sabiendas: si hay que revisarlo, se revisa con una medición delante.
 *
 * ## Y SE REVISÓ EL 22 SEP, sin la medición y por el motivo correcto
 *
 * El interruptor vuelve, pero **no como la constante que se quitó**: como
 * `years.profes_pueden_editar_plantilla`, una columna que el colegio gira desde su
 * pantalla del plan de evaluación. Joseth lo dijo así —*«prefiero que haya un
 * booleano a nivel colegio, para que se pueda elegir si los docentes pueden
 * editarlos o no, aunque sean por defecto»*— y la diferencia con el 21 sep es
 * entera: aquella constante era una decisión del código disfrazada de interruptor, y
 * ésta es del colegio.
 *
 * Lo que se dio por medir y no se midió era la pregunta equivocada. La medición
 * buscaba **cuántas** filas del colegio estaba editando un docente, para decidir si
 * el candado sobraba; y P6 no se cae por un número, se cae porque **la regla no es
 * la misma en los dieciséis colegios**. Un colegio con el plan de área cerrado
 * quiere el candado; otro siembra la plantilla como punto de partida y espera que su
 * docente la ajuste. Con una sola respuesta escrita en el código, uno de los dos
 * está siempre equivocado.
 *
 * **De fábrica es 0**, o sea el comportamiento del 21 sep: desplegar esto no cambia
 * nada en ningún colegio, y los que se quejaron siguen quejándose hasta que alguien
 * lo encienda. Eso es lo elegido — el otro defecto abriría la plantilla de los
 * dieciséis a la vez sin que ningún rector lo hubiera pedido.
 *
 * **Y con el interruptor encendido, una fila del colegio se comporta como una del
 * docente**: se renombra, se repesa, **se borra y se mueve de sitio**. No es sólo
 * levantar `CAMPOS`. La parte de mover la hace `exigirElLote()`; borrar no pasa por
 * aquí —esta API nunca lo frenó, sólo lo frenaba la pantalla de `app2`— y eso queda
 * escrito porque es la asimetría que se encuentra quien venga a buscarla aquí.
 */
class CandadoDeLaPlantilla
{
    /**
     * Los dos campos que el colegio se reserva. El resto de la fila no es suyo.
     */
    public const CAMPOS = ['definicion', 'porcentaje'];

    /**
     * Frena el cambio si la fila es del colegio y quien llama no manda en ella.
     *
     * @param  object  $usuario  el contexto de `User::fromToken()`
     * @param  object  $fila  la unidad o subunidad **como está guardada**
     * @param  array<string, mixed>  $llego  lo que de verdad trajo la petición
     *                                       (`Request::all()`), no una lista
     *                                       construida con `input()` — ver el
     *                                       docblock de la clase
     * @param  string  $que  «unidad» o «subunidad», para el mensaje
     */
    public static function exigir(object $usuario, object $fila, array $llego, string $que): void
    {
        // El orden de las dos comprobaciones importa por lo que cuesta cada una:
        // `por_defecto` ya está en la fila que el llamante acaba de cargar, y
        // `puedeEditarPlantillaNotas` puede mirar `perms`. Primero la gratis.
        if (empty($fila->por_defecto)) {
            return;
        }

        if (Autoriza::puedeEditarPlantillaNotas($usuario)) {
            return;
        }

        // **Y el interruptor del colegio, que es el tercero y el más caro** (22 sep
        // 2026, `years.profes_pueden_editar_plantilla`). Va el último de los tres a
        // propósito, por lo que cuesta cada uno: `por_defecto` ya venía en la fila
        // que el llamante cargó, el permiso puede salir de `perms`, y esto es una
        // consulta. Puesto el primero, la pagaría **toda** escritura sobre una
        // unidad; puesto aquí sólo la paga la que de verdad iba a ser un 403.
        if (self::abiertaALosDocentes($fila, $que)) {
            return;
        }

        foreach (self::CAMPOS as $campo) {
            if (! array_key_exists($campo, $llego)) {
                continue;
            }

            if (self::cambia($fila->$campo ?? null, $llego[$campo])) {
                abort(403, self::mensaje($que, $campo));
            }
        }
    }

    /**
     * El candado del reordenado, **para el lote entero y antes de escribir nada**.
     *
     * ## Por qué no va fila a fila dentro del bucle, que es como nació
     *
     * `putUpdateOrden` escribe fila a fila. Preguntando **dentro** del bucle, un
     * lote que lleve primero una unidad del docente y después una del colegio
     * **deja la primera ya guardada** y aborta con 403 en la segunda: la rejilla
     * queda medio movida y el docente ve un error. No hay transacción alrededor.
     *
     * Y es el invariante que ese método **ya respetaba a propósito** para el
     * periodo —`User::pueden_editar_notas` está FUERA del bucle—, con su gemelo de
     * subunidades diciéndolo con todas las letras: *«basta que una esté en periodo
     * cerrado para que no pase ninguna: escribir la mitad de un reordenado es peor
     * que no escribir nada»* (§27). La primera versión de este candado lo rompió
     * **escribiendo al lado un comentario correcto sobre por qué la comprobación
     * tiene que ser por fila** — que lo es; lo que estaba mal era *cuándo*.
     *
     * ## Y por qué se compara el orden en vez de rechazar por estar en la lista
     *
     * El cliente manda **la rejilla entera** cuando el docente mueve una fila
     * suya, así que las del colegio viajan siempre. Rechazar por *«viene una fila
     * del colegio»* haría imposible reordenar lo propio. Salta sólo si una del
     * colegio **se mueve de sitio**.
     *
     * Cuesta **una consulta** que la versión de dentro del bucle no hacía. Es el
     * precio de no escribir a medias, y se paga.
     *
     * @param  array<int, int>  $ordenes  id de unidad => orden pedido
     */
    public static function exigirElLote(object $usuario, array $ordenes): void
    {
        if ($ordenes === [] || Autoriza::puedeEditarPlantillaNotas($usuario)) {
            return;
        }

        // **El interruptor del colegio entra AQUÍ, en la consulta que ya se hacía, y
        // no en un `if` de más arriba** (22 sep 2026): el lote llega con ids y no con
        // un año, y resolverlo aparte sería una segunda consulta para contestar lo
        // que ésta ya sabe en cuanto pasa por `periodos`. Filtrando por la columna,
        // las filas de un colegio que abrió la mano **no llegan a la lista de
        // bloqueadas** y el bucle de abajo no tiene nada que mirar.
        //
        // Y va por fila y no por lote aunque el lote sea de una sola asignatura: es
        // gratis —el `JOIN` ya está— y deja de depender de un invariante que nadie
        // escribió. El día que un reordenado cruce dos años, cada fila responde por
        // el suyo.
        $delColegio = DB::table('unidades')
            ->join('periodos', 'periodos.id', '=', 'unidades.periodo_id')
            ->join('years', 'years.id', '=', 'periodos.year_id')
            ->whereIn('unidades.id', array_keys($ordenes))
            ->where('unidades.por_defecto', 1)
            ->where('years.profes_pueden_editar_plantilla', 0)
            ->whereNull('unidades.deleted_at')
            ->get(['unidades.id', 'unidades.orden']);

        foreach ($delColegio as $fila) {
            $id = (int) $fila->id;

            if (! array_key_exists($id, $ordenes)) {
                continue;
            }

            if (self::cambia($fila->orden, $ordenes[$id])) {
                abort(403, 'Esa unidad la puso el colegio en la plantilla: no se puede mover de sitio. '
                    .'Pídeselo a la coordinación, que lo corrige en la plantilla para todos.');
            }
        }
    }

    /**
     * ¿Este colegio deja que sus docentes editen lo que puso en la plantilla?
     *
     * `years.profes_pueden_editar_plantilla`, la cuarta política del año — decisión
     * de Joseth del 22 sep 2026, que **corrige P6**. La regla no es la misma en los
     * dieciséis colegios, así que el código dejó de elegirla; lo que queda aquí es
     * leerla.
     *
     * ## Se lee el año de LA FILA, no el de la sesión
     *
     * `$usuario->year_id` estaba a mano y es la respuesta equivocada: un docente
     * con la sesión en 2026 que corrige una unidad de 2023 —que se puede, mientras
     * aquel periodo siguiera abierto, y está decidido así en la §27.4— pasaría o no
     * pasaría según lo que el colegio haya elegido **este** año. La política es del
     * año al que pertenece la fila, igual que `modelo_evaluacion` y por la misma
     * razón: *un año cerrado conserva el suyo para siempre*.
     *
     * ## Dos consultas y no una, y es la forma barata
     *
     * La unidad cuelga de `periodos` y la subunidad de `unidades`, así que el camino
     * al año tiene un salto más en un caso que en el otro. Se distinguen por `$que`,
     * que el llamante ya pasa para el mensaje — no hace falta preguntarle a la fila
     * de qué clase es.
     *
     * **Un `false` cuando no se encuentra el año**, que es el lado seguro: si la
     * fila cuelga de un periodo huérfano, lo que pasa es que el candado sigue
     * puesto. El otro defecto abriría la plantilla por un dato roto.
     */
    private static function abiertaALosDocentes(object $fila, string $que): bool
    {
        if ($que === 'subunidad') {
            $abierta = DB::selectOne(
                'SELECT y.profes_pueden_editar_plantilla AS abierta
                   FROM unidades u
                   JOIN periodos p ON p.id = u.periodo_id
                   JOIN years y ON y.id = p.year_id
                  WHERE u.id = ?',
                [(int) ($fila->unidad_id ?? 0)]
            );
        } else {
            $abierta = DB::selectOne(
                'SELECT y.profes_pueden_editar_plantilla AS abierta
                   FROM periodos p
                   JOIN years y ON y.id = p.year_id
                  WHERE p.id = ?',
                [(int) ($fila->periodo_id ?? 0)]
            );
        }

        return $abierta !== null && (bool) $abierta->abierta;
    }

    /**
     * ¿El valor pedido es distinto del guardado?
     *
     * Tres casos y los tres costaron algo:
     *
     * - **`null` pedido con algo guardado ES un cambio** —borrar—. Ver el
     *   docblock de la clase: tratarlo como «no cambia» era el agujero nº 1.
     * - **Dos números se comparan como números.** `"70.00"` contra `70` es el
     *   mismo peso, y compararlos como texto daba un **403 falso** a quien no
     *   cambió nada — que es el modo de fallo que esta clase entera intenta
     *   evitar.
     * - **El resto, como texto y sin espacios de los bordes**, que es lo que el
     *   cliente manda y lo que la base guarda; el `TrimStrings` global ya recortó
     *   lo que entró por HTTP.
     */
    private static function cambia($guardado, $pedido): bool
    {
        if ($pedido === null) {
            return $guardado !== null;
        }

        if (is_numeric($guardado) && is_numeric($pedido)) {
            return (float) $guardado !== (float) $pedido;
        }

        return trim((string) $guardado) !== trim((string) $pedido);
    }

    private static function mensaje(string $que, string $campo): string
    {
        $cual = $campo === 'porcentaje' ? 'el porcentaje' : 'el nombre';

        return "Esa {$que} la puso el colegio en la plantilla: no se puede cambiar {$cual}. "
            .'Pídeselo a la coordinación, que lo corrige en la plantilla para todos.';
    }
}
