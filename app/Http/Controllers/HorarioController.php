<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResuelveElUsuario;
use App\Support\Autoriza;

/**
 * Horario: versiones del horario de un año, y cuál de ellas es la oficial.
 *
 * El contrato es [23-horarios.md](../../../docs/migracion/23-horarios.md) (v2, 2
 * sep 2026) y este fichero lo sigue; si algo de aquí discrepa del documento, el
 * que está mal es éste.
 *
 * **El horario NO se cuadra aquí.** Se cuadra en un programa de escritorio (Tauri
 * 2 + Angular) con su propio fichero de proyecto, y a esta API le queda una cosa
 * mucho más pequeña: **guardar versiones del horario de un año y decir cuál es la
 * oficial**. Salones, disponibilidad, rejilla, timbres y pesos **no existen en el
 * servidor** (§4) — y ésa no es una omisión que haya que ir tapando: es la razón
 * de ser de la §6.
 *
 * ## Las tres reglas que gobiernan cada método
 *
 *   1. **Subir no es publicar.** Cada subida crea una versión; una pantalla web
 *      elige cuál es la oficial. Hasta que se elige, «Clases de hoy» sigue
 *      enseñando la anterior, y **una subida a medias no le llega a nadie**.
 *   2. **El servidor revalida las tres que puede y LO DICE** (opción B, decisión
 *      9). Grupo sin choque, docente sin choque y Σ lecciones = IH. Salón,
 *      disponibilidad y jornada quedan **nombradas como no comprobadas**, con su
 *      población dentro. Un `if` que comprueba la disponibilidad contra un dato
 *      que el servidor no tiene **no falla nunca**: pasa siempre, se ve verde y
 *      no comprueba nada. Aceptar y callar habría sido un «validado» encima de un
 *      horario ilegal.
 *   3. **Listar NO es descargar** (decisión 12). `GET horario/versiones` va con
 *      `auth.personal`, o sea los 53 docentes; devuelve nombre, fecha, quién,
 *      si es la oficial y el veredicto — **nunca el blob del proyecto ni las
 *      lecciones**. Un `SELECT *` ahí le entrega a cualquiera el fichero de
 *      proyecto entero del colegio.
 *
 * ## Estado: los tres métodos contestan 501, a propósito
 *
 * Este fichero es el **suelo** del módulo (lote A): las rutas, los guards y la
 * autorización, escritos y medidos. El cuerpo de los tres lo escriben los lotes B
 * y C. Un 501 «Not Implemented» dice exactamente lo que pasa —la ruta existe,
 * está autorizada y todavía no hace nada—, que es lo que un 404 o un 200 vacío no
 * dirían. **La comprobación de permiso va ANTES del 501 y no después**, porque el
 * criterio es de este lote: dejarla para el que escriba el cuerpo es cómo una
 * ruta acaba en producción sin ella.
 *
 * ## Por qué SQL y no Eloquent, el día que se escriban
 *
 * Por lo mismo que el resto del repo, y con la lección de `notas/detailed`
 * delante: **nunca `SELECT *`**, columnas escritas a mano. Aquí no es sólo higiene
 * — es la regla 3 de arriba.
 */
class HorarioController extends Controller
{
    use ResuelveElUsuario;

    /**
     * `POST horario/versiones` — sube una versión del horario de un año (§5.3).
     *
     * Guard de la ruta `auth.personal`; el criterio es
     * **`Autoriza::esAdministrativo`**, el mismo que pide `putCambiarlogocolegio`
     * y que es la referencia que dio Joseth (§5.4). Sube cualquier
     * administrativo; **publicar es otro criterio y otro método**.
     *
     * Lo que tiene que hacer el cuerpo cuando se escriba, en el orden en que el
     * documento lo pide:
     *
     *   - Del cuerpo llega `version: {nombre, year_id}` y `piezas[]` **y nada
     *     más**: `subida_por` sale del token, `created_at` del reloj del servidor
     *     y `comprobaciones` lo escribe el servidor (§5.2, correcciones 2 y 3).
     *   - `docentes[]` de cada pieza son **`profesores.id`, no `users.id`**
     *     (§5.2.1).
     *   - Cada elemento de `asignaciones` se explota a una fila de
     *     `horario_lecciones`. **La versión entra entera o no entra**: una
     *     transacción, y un 422 con las lecciones culpables **enumeradas**.
     *   - `year_id` puede ser de un año cerrado y eso está decidido (decisión 13).
     *
     * Y los cuatro 422 que el documento ya cerró, cada uno **nombrando la pieza**:
     * una pieza sin `asignatura_id` (§8: un proyecto armado sin MyVC no se puede
     * subir, y **nunca se empareja por nombres**), una asignación de **otro año**
     * (§6, por JOIN con `grupos.year_id`, porque `asignaturas` no tiene `year_id`),
     * una asignación **de la papelera** —hay 240— y un `dia` fuera de 0..6. La
     * asignación **sin IH** no es 422: va al veredicto como NO COMPROBADA,
     * nombrada y contada, porque un 422 convertiría un dato incompleto del colegio
     * en un módulo inutilizable.
     */
    public function postVersiones(): never
    {
        Autoriza::exigir(Autoriza::esAdministrativo($this->user),
            'No tienes permiso para subir una versión del horario.');

        abort(501, 'Subir una versión del horario todavía no está implementado.');
    }

    /**
     * `GET horario/versiones` — las versiones del año (§5.3).
     *
     * `auth.personal` y nada más: cualquier docente puede ver qué versiones hay
     * (decisión 12, más abierta que lo que proponían las dos sesiones). Tiene
     * sentido, porque el horario es un papel que acaba pegado en la puerta del
     * salón.
     *
     * **Y con eso, la condición que va antes que la ruta: listar no es
     * descargar.** Nombre, fecha, quién la subió, si es la oficial y su veredicto.
     * **Ni `proyecto` ni las lecciones.** El blob se descarga por otro camino y
     * con otro permiso el día que haga falta —sería una cuarta ruta, y no está
     * pedida ni autorizada (§10.2.3)—; hoy no hace falta ninguno.
     */
    public function getVersiones(): never
    {
        abort(501, 'Listar las versiones del horario todavía no está implementado.');
    }

    /**
     * `PUT horario/versiones/{id}/oficial` — marca cuál es la oficial (§5.3).
     *
     * Criterio **`Autoriza::puedePublicarHorario`**, que es método nuevo y no uno
     * de los que ya había: superusuario o el rol `Coord académico`. Secretaría
     * sube pero no publica.
     *
     * Marcar la oficial es un `UPDATE` de `years.horario_version_id` **y** la
     * derivación de la §7, las dos en la misma transacción — y ahí están las dos
     * trampas que el documento ya midió:
     *
     *   - **El alcance de la derivación es el AÑO ENTERO, no las filas de la
     *     versión.** Se pone todo el alcance del año a 0 y luego a 1 lo que trae
     *     la versión. Leído literal («recalcula las columnas de cada asignación
     *     desde las lecciones de esa versión») sólo se escribirían las que
     *     aparecen, así que una asignatura que la versión 2 quita **se quedaría
     *     con el `martes = 1` de la versión 1** y el docente seguiría viendo una
     *     clase que ya no existe, salida de una columna que nadie volvió a tocar.
     *   - **«Las asignaciones de este año» es un JOIN, no un `WHERE`**:
     *     `asignaturas` no tiene `year_id` y el año le llega por `grupos.year_id`.
     *     Equivocarse ahí publicando un año cerrado significaría **poner a cero
     *     las columnas del año abierto**, y con la decisión 13 eso no es teórico.
     *
     * Y va en el mismo lote, no después: **el fallo del sábado de la §2.1**
     * —`$dia + 1 = 7`, el `switch` sin caso 7 y «mañana» devolviendo todas las
     * asignaturas del docente— es invisible hoy porque las siete columnas están
     * vacías, y **se estrena el día que se rellenen**. Arreglarlo después
     * convierte el estreno del horario en un fallo nuevo.
     */
    public function putOficial($id): never
    {
        Autoriza::exigir(Autoriza::puedePublicarHorario($this->user),
            'No tienes permiso para marcar la versión oficial del horario.');

        abort(501, 'Marcar la versión oficial del horario todavía no está implementado.');
    }

    /**
     * Un 422 con el cuerpo entero, no sólo con el `message`.
     *
     * Copiado de `RubricasController::rechazar()`, y por la misma razón: `abort()`
     * a secas sólo sabe poner un texto, y **un error que sólo es texto obliga al
     * cliente a leerlo con expresiones regulares** para saber a qué pieza culpa.
     * Con las claves aparte, la pantalla del escritorio puede señalar la casilla.
     *
     * @param  array<string, mixed>  $cuerpo
     */
    protected function rechazar(array $cuerpo): never
    {
        abort(response()->json($cuerpo, 422));
    }

    /**
     * El 422 de una pieza concreta, con `pieza_id` y `motivo` **aparte** del
     * `message`.
     *
     * **El error dice su población, y eso no es adorno** (§6): «hay choques» no
     * distingue *«revisé las 345 y encontré tres»* de *«me rendí en la primera»*,
     * y de las dos lecturas la falsa es la que hace archivar el asunto. Por eso
     * `$revisadas` no es opcional por comodidad: es lo que separa un rechazo
     * legible de un `[]` que se lee como «todo bien» (§2).
     *
     * @param  ?int  $revisadas  cuántas piezas se llegaron a mirar, si se sabe
     */
    protected function rechazarPieza(string $piezaId, string $motivo, ?int $revisadas = null): never
    {
        $poblacion = $revisadas === null ? '' : " Se revisaron {$revisadas}.";

        $this->rechazar([
            'message' => "La pieza {$piezaId} no vale: {$motivo} Nada se escribió.{$poblacion}",
            'pieza_id' => $piezaId,
            'motivo' => $motivo,
            'piezas_revisadas' => $revisadas,
        ]);
    }
}
