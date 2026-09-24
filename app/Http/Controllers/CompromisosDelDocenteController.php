<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResuelveElUsuario;
use App\Services\Nivelacion;
use App\Support\Autoriza;
use App\Support\EscalaDeNotas;
use App\Support\Reloj;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;

/**
 * **El lado del docente del compromiso académico**: sus renglones y su veredicto.
 *
 *     GET compromisos/mios                      auth.personal
 *     PUT compromisos/items/{id}/veredicto      auth.personal
 *
 * Diseño en `myvc_front/COMPROMISOS-ACADEMICOS.md` §3 y §5; el porqué de cada columna,
 * en el docblock de `2026_09_22_200000_el_compromiso_academico`. El contrato con el
 * front ya está escrito y manda: `app2/src/app/datos/compromisos.ts`, clase
 * `CompromisosDeAlumnosApi` (`mios`, `veredicto`) e `interface ItemDelCompromiso`.
 *
 * Aquí va sólo lo que decide este controlador.
 *
 * ## La pantalla sale del encargo, palabra por palabra
 *
 * *«Cada docente pueda ir al compromiso y decir cuáles son los muchachos que tenían
 * compromiso conmigo aquí en matemática.»* De ahí que esto devuelva **items y no
 * compromisos**: el veredicto es por asignatura y por docente, que es la razón 1 de
 * §3.1 para que las tablas sean dos. Por eso el item viaja con `alumno`, `grupo` y
 * `periodo` dentro —el contrato los declara opcionales justo para esta pantalla— y
 * no hay que pedir la cabecera de cada compromiso para pintar la lista.
 *
 * ## D3, decidida el 22 sep 2026: son DOS personas y no una
 *
 * *«El docente de la asignatura pone el suyo, y el titular ve el de todos y rellena
 * los que falten al cierre.»* O sea que `GET compromisos/mios` de un titular devuelve
 * **también los renglones de su grupo que no son suyos**. Lo que eso evita está
 * escrito en la propia D3: *que un docente que no contesta bloquee el cierre para
 * siempre*.
 *
 * Y de ahí sale lo que el servidor tiene que guardar: `veredicto_por` **no es
 * `profesor_id`**. La primera dice **a quién le tocaba**, la segunda **quién lo
 * firmó**, y un veredicto del titular y uno del docente no valen lo mismo — el papel
 * tiene que poder decirlo (R3, §1.4).
 *
 * ## D4, decidida el mismo día: `sugerido` NO es el veredicto
 *
 * *«Si el alumno ya niveló por la vía normal, se propone el resultado leído de la
 * nivelación y el docente lo confirma.»* El item sigue como está —`en_espera`, o
 * nulo— hasta que alguien pulsa; `sugerido` es lo que la base ya sabe, puesto al lado
 * para no teclear dos veces lo mismo y para que el compromiso no diga «no niveló» de
 * alguien que sí. Ver {@see sugeridosDeLaNivelacion}, que explica de qué tabla sale.
 *
 * **Y `asistio` nunca se propone**: no hay lista de la semana de nivelaciones en
 * ninguna tabla, así que la base no sabe quién fue. Es el único dato verdaderamente
 * nuevo del veredicto (§2.2) y sólo lo pone una persona — ni se deduce ni se rellena
 * por omisión, que es de lo que habla el docblock de {@see putVeredicto}.
 *
 * ## Reglas de la casa que se siguen aquí y conviene no redescubrir
 *
 * SQL crudo con `DB::` y ningún modelo nuevo, igual que `CompromisosConfigController`,
 * que es del mismo módulo. Validación a mano con `abort(422)` y no `Validator::make`,
 * porque los mensajes los lee un docente y tienen que decir qué hacer. Y la hora sale
 * de `Reloj::ahoraTexto()` y **nunca de `NOW()`**: `config/database.php` no fija la
 * zona de la sesión, así que `NOW()` es el reloj de cada uno de los dieciséis cPanel
 * y `veredicto_at` acabaría con una hora distinta en cada colegio sin nada en la fila
 * que lo dijera.
 *
 * ## Lo que este controlador NO hace, escrito para que no se lea como un olvido
 *
 * **No escribe en `auditoria`.** No es descuido: el vocabulario de
 * `Auditoria::ENTIDADES` es cerrado y no tiene `compromiso` —añadirlo es una decisión
 * de quien escriba la creación y el cierre, que es donde hay varias escrituras por
 * petición—, y sobre todo porque **este documento lleva su propio rastro dentro**:
 * `veredicto_por` y `veredicto_at` son R3 y son los que se imprimen. Una segunda copia
 * más débil del mismo rastro es un sitio más donde discrepar.
 *
 * Lo que sí se pierde y hay que decirlo: `auditoria` guarda `ip` y `ruta`, y estas
 * columnas no. Para el veredicto da igual —lo firma personal autenticado del
 * colegio—; para la firma del acudiente **no**, y está anotado en
 * {@see CompromisosDeLaFamiliaController}.
 */
class CompromisosDelDocenteController extends Controller
{
    use ResuelveElUsuario;

    /**
     * Los tres valores de `compromiso_items.resultado`.
     *
     * Viven aquí y no en un `enum` de MySQL por lo que dice la cabecera de la
     * migración: `varchar` porque el vocabulario va a crecer y un `MODIFY COLUMN`
     * sobre una tabla de **documentos firmados** es la clase de migración que el Paso
     * 0 de `docs/DESPLIEGUE.md` obliga a mirar colegio por colegio. Y con `varchar`
     * hace falta esta lista, porque con el `sql_mode` de estos servidores un valor
     * raro **no lanza: se guarda y devuelve 200**.
     */
    private const RESULTADOS = ['nivelo', 'no_nivelo', 'en_espera'];

    /** `compromiso_items.observacion` es `varchar(255)`, y MySQL aquí **trunca** en vez de lanzar. */
    private const LARGO_OBSERVACION = 255;

    /**
     * **Los renglones que este docente tiene que contestar**, ordenados por grupo.
     *
     * Filtros, todos opcionales: `year_id` (por defecto el de la sesión), `periodo`
     * (el ordinal 1..4) y `pendientes`.
     *
     * ## «Pendiente» es `resultado IS NULL`, y no «asistio IS NULL»
     *
     * Es lo que dice el índice `compromiso_items_del_docente (profesor_id, resultado)`
     * de la migración, y el orden de sus dos columnas está elegido para esta consulta.
     * `asistio` no sirve de filtro porque tiene **tres** significados y `null` no es
     * «no asistió» —la diferencia es la que sostiene R3: *«se le convocó y no fue»* es
     * prueba y *«nadie contestó»* no—.
     *
     * ## Los `borrador` NO salen
     *
     * Un compromiso en borrador es un papel que el coordinador todavía está armando:
     * sus renglones pueden cambiar o desaparecer antes de entregarse. Pedirle un
     * veredicto a un docente sobre eso es pedirle que dictamine sobre un documento que
     * aún no existe para nadie — y el flujo de §5 pone el veredicto en el paso 7,
     * después de la entrega y de la semana de nivelaciones.
     *
     * ## Y NO se filtra por periodo por defecto
     *
     * El docente entra a esta pantalla en la semana de nivelaciones, que cae **entre**
     * dos periodos; y un compromiso del periodo 2 puede seguir sin veredicto en el 3.
     * Un filtro por el periodo de la sesión le escondería trabajo pendiente sin
     * decírselo. El parámetro está para acotar, no para cortar.
     *
     * ## Lo que viaja además de `items`, y por qué no rompe el contrato
     *
     * `profesor_id` —el de quien pregunta— sale en la respuesta. El contrato declara
     * `mios()` como `{ items: ItemDelCompromiso[] }` y una clave de más no rompe nada
     * en TypeScript, así que esto **añade** y no cambia.
     *
     * Existe porque D3 obliga a que la pantalla diga **«estás escribiendo por otro»**,
     * y esa frase es `item.profesor_id !== respuesta.profesor_id`: **dos números que
     * los dos vienen del servidor**. Escrita contra la sesión sería
     * `persona_id === profesor_id`, que es exactamente la trampa de §3.5 —`persona_id`
     * sale de una tabla distinta según el `tipo`, y para un `Usuario` administrativo
     * es `users.id`—. El front tiene `idDeProfesorDe()` para eso mismo, pero la
     * comparación correcta tiene que ser también la fácil de escribir.
     */
    public function getMios()
    {
        $user = $this->user;
        $profesor_id = $this->miProfesorId($user);

        // 403 y no una lista vacía. Una cuenta sin ficha de profesor —una secretaría,
        // por ejemplo— no tiene renglones, pero decírselo con `[]` se lee como «no
        // tienes nada pendiente» y lo que pasa es que esta pantalla no es suya.
        Autoriza::exigir(
            $profesor_id !== null,
            'Esta pantalla es de los docentes: su cuenta no tiene ficha de profesor asociada.'
        );

        $year_id = $this->anioDeLaPeticion(Request::input('year_id'));
        $periodo = $this->periodoOpcional(Request::input('periodo'));

        // `filter_var` y no `(bool)`: esto llega por la query string, así que el `false`
        // del cliente es la **cadena** `"false"`, y `(bool) "false"` es verdadero. Con
        // eso, «todos» se leería como «sólo pendientes» y el docente no vería lo que ya
        // contestó. Es la familia de fallos del 05 §13, en el sentido contrario.
        $solo_pendientes = filter_var(Request::input('pendientes'), FILTER_VALIDATE_BOOLEAN);

        /*
         * **`ci.profesor_id = :mio OR g.titular_id = :titular` es D3 entera**, y son dos
         * marcas de parámetro con el mismo valor a propósito: con el mismo nombre dos
         * veces la consulta depende de que PDO esté emulando las preparadas, y eso se
         * configura fuera de este fichero.
         *
         * `g.titular_id` y **no** `persona_id`: `grupos.titular_id` apunta a
         * `profesores.id` (§3.5, y lo lleva escrito `Models/VtMesa.php:215`), que es lo
         * mismo que resuelve {@see miProfesorId}. Los dos lados de la comparación son
         * ids de `profesores` o la comparación no se hace.
         *
         * Los nombres salen por `LEFT JOIN` y no por clave ajena porque `veredicto_por`
         * **no la lleva**: la papelera borra profesores de verdad —`forceDelete`, 31
         * tablas en cascada— y una firma no se borra con su firmante. Si la persona ya
         * no está, el renglón sale con el nombre en nulo y el id dentro, que es lo que
         * la cabecera de la migración eligió.
         */
        $consulta = 'SELECT ci.id, ci.compromiso_id, ci.asignatura_id, ci.area_id, ci.profesor_id,
                            ci.nota_al_crear, ci.asistio, ci.resultado, ci.nota_al_cerrar, ci.observacion,
                            ci.veredicto_por, ci.veredicto_at,
                            c.periodo, c.year_id, c.estado,
                            g.id AS grupo_id, g.nombre AS grupo, g.titular_id,
                            al.id AS alumno_id, TRIM(CONCAT(al.nombres, " ", al.apellidos)) AS alumno,
                            COALESCE(mm.materia, ar.nombre) AS nombre,
                            TRIM(CONCAT(pr.nombres, " ", pr.apellidos)) AS profesor,
                            fot.nombre AS foto_profesor,
                            TRIM(CONCAT(vp.nombres, " ", vp.apellidos)) AS veredicto_por_nombre
                       FROM compromiso_items ci
                       INNER JOIN compromisos c ON c.id = ci.compromiso_id
                       INNER JOIN matriculas ma ON ma.id = c.matricula_id AND ma.deleted_at IS NULL
                       INNER JOIN grupos g ON g.id = ma.grupo_id AND g.deleted_at IS NULL
                       INNER JOIN alumnos al ON al.id = ma.alumno_id AND al.deleted_at IS NULL
                       LEFT JOIN asignaturas asi ON asi.id = ci.asignatura_id
                       LEFT JOIN materias mm ON mm.id = asi.materia_id
                       LEFT JOIN areas ar ON ar.id = ci.area_id
                       LEFT JOIN profesores pr ON pr.id = ci.profesor_id
                       LEFT JOIN images fot ON fot.id = pr.foto_id AND fot.deleted_at IS NULL
                       LEFT JOIN profesores vp ON vp.id = ci.veredicto_por
                      WHERE c.year_id = :year_id
                        AND c.estado <> "borrador"
                        AND (ci.profesor_id = :mio OR g.titular_id = :titular)';

        $datos = [':year_id' => $year_id, ':mio' => $profesor_id, ':titular' => $profesor_id];

        if ($periodo !== null) {
            $consulta .= ' AND c.periodo = :periodo';
            $datos[':periodo'] = $periodo;
        }

        if ($solo_pendientes) {
            $consulta .= ' AND ci.resultado IS NULL';
        }

        // **El orden es el de la pantalla y empieza por el grupo**, que es lo que pide
        // el encargo: el docente trabaja un curso entero y después pasa al siguiente.
        // Dentro, por estudiante como en las listas del colegio —apellido primero— y
        // después por el orden en que el colegio colocó la asignatura, que es el del
        // boletín. `mm.materia` y `ar.nombre` desempatan y **no se pone el alias
        // `nombre`**: `grupos`, `areas` y `materias` tienen todas una columna que se
        // llama así y la ambigüedad la resolvería MySQL a su manera.
        $consulta .= ' ORDER BY g.orden, g.nombre, al.apellidos, al.nombres, asi.orden, mm.materia, ar.nombre, ci.id';

        $filas = DB::select($consulta, $datos);

        $sugeridos = $this->sugeridosDeLaNivelacion($filas, $year_id);

        $items = [];

        foreach ($filas as $fila) {
            $items[] = $this->pintarItem($fila, $sugeridos);
        }

        // Se devuelve **con qué se contestó** y no sólo la lista, igual que
        // `informes/nivelaciones-del-grupo`: `year_id` puede haberlo puesto el servidor,
        // y una pantalla que rotula «año 2026» tiene que saber que le contestaron de ése
        // y no suponerlo del token.
        return [
            'year_id' => $year_id,
            'periodo' => $periodo,
            'pendientes' => $solo_pendientes,
            'profesor_id' => $profesor_id,
            'items' => $items,
        ];
    }

    /**
     * **El veredicto de un renglón**: `asistio`, `resultado`, `observacion` y la nota
     * con la que se cierra.
     *
     * ## Quién puede escribirlo: las dos puertas de D3 y ninguna más
     *
     * El docente de esa asignatura, o el titular del grupo. **Coordinación no es una
     * tercera puerta**, y no por descuido: `compromiso_items.veredicto_por` apunta a
     * `profesores.id`, así que una cuenta sin ficha de profesor sólo podría firmar
     * dejando la columna en nulo — y un veredicto sin autor **es justamente lo que R3
     * viene a impedir**. Por eso quien no tenga ficha se lleva un 403 que lo dice, en
     * vez de una firma en blanco que nadie nota hasta que hay que imprimir el papel.
     *
     * La cuenta de coordinación que además es docente sí pasa: {@see miProfesorId}
     * mira también `users.profesor_id`, que es para lo que la consulta de
     * `ContextoDeUsuario` lo trae.
     *
     * ## Las dos ventanas de tiempo, y la segunda es la que importa
     *
     * - **`borrador` no**, por lo mismo que no sale en `getMios`: el papel todavía no
     *   existe para nadie.
     * - **Notificado tampoco.** En cuanto `resultado_entregado_at` está puesto, la
     *   familia ya sabe cómo terminó y el plazo de reclamación está corriendo (R4).
     *   Cambiar el veredicto ahí es **reescribir un documento ya comunicado**, y
     *   dejaría al colegio sosteniendo un plazo que empezó a contar sobre otro texto.
     *   Entre medias —`entregado` y `cerrado`— se puede escribir y corregir: `cerrado`
     *   significa que el colegio ya tiene los veredictos, no que la familia los tenga.
     *
     * ## `asistio` tiene TRES valores, y el que se hace mal es el tercero
     *
     * `compromiso_items.asistio` es `tinyint NULL` y la cabecera de la migración lo
     * deletrea: `true` es *se le convocó y fue*, `false` es *se le convocó y no fue*
     * —**eso es prueba**— y `null` es *el docente no ha contestado eso*, que no es lo
     * mismo. La diferencia es la que sostiene R3: «no fue» le sirve al colegio y «nadie
     * contestó» no.
     *
     * De ahí las dos reglas del método, y las dos van contra lo que sale solo:
     *
     *  - **Ausente o `null` se guarda como `NULL`**, no como 0. Escrito con
     *    `(int) (bool) Request::input('asistio')` —que es lo natural y lo que hacen los
     *    interruptores de la configuración— un cuerpo sin la clave se guardaría como
     *    **«no asistió»**: la afirmación más cara del documento, puesta por omisión, con
     *    autor y fecha, dentro de un papel que se firma. Y el caso real es el botón de
     *    D4 —*confirmar lo que propuso la nivelación*—, donde el docente está diciendo
     *    algo del resultado y nada de la asistencia.
     *  - **Basura sigue siendo 422.** Un `"quizá"` no puede caer del lado del `false`.
     *
     * `resultado` sí es obligatorio: es lo que se pulsa. Ver {@see veredictoValidado}.
     */
    public function putVeredicto($id)
    {
        $user = $this->user;
        $profesor_id = $this->miProfesorId($user);

        Autoriza::exigir(
            $profesor_id !== null,
            'El veredicto lo firma un docente: su cuenta no tiene ficha de profesor asociada, '
                .'y un veredicto sin autor no sirve de prueba.'
        );

        $item = $this->itemDelVeredicto((int) $id);

        // **Dos permisos y no uno, y se guarda cuál valió.** `suyo` es el docente de la
        // asignatura; `titular` es D3 —«rellena los que falten al cierre»—. La respuesta
        // lo dice en voz alta porque quien rellena por otro tiene que saber que está
        // firmando con su nombre.
        $suyo = $item->profesor_id !== null && (int) $item->profesor_id === $profesor_id;
        $titular = $item->titular_id !== null && (int) $item->titular_id === $profesor_id;

        Autoriza::exigir(
            $suyo || $titular,
            'Ese renglón no es de una asignatura suya y usted no es el titular de ese grupo.'
        );

        if ($item->estado === 'borrador') {
            abort(422, 'Ese compromiso todavía es un borrador: no se ha entregado y sus renglones pueden cambiar.');
        }

        if ($item->resultado_entregado_at !== null) {
            abort(422, 'El resultado de ese compromiso ya se le entregó a la familia el '
                .(Reloj::humana($item->resultado_entregado_at) ?? $item->resultado_entregado_at)
                .': el veredicto ya no se puede cambiar por aquí.');
        }

        $veredicto = $this->veredictoValidado((int) $item->year_id);

        $ahora = Reloj::ahoraTexto();

        // R3: `veredicto_por` y `veredicto_at` se escriben **siempre**, incluso cuando el
        // veredicto no cambia de valor. La pregunta que esas dos columnas contestan no es
        // «qué dice» sino «quién lo dijo y cuándo», y un reguardado es alguien firmando
        // otra vez. Es la primera regla de `Services\Auditoria`, aplicada a una columna.
        DB::update(
            'UPDATE compromiso_items
                SET asistio = ?, resultado = ?, nota_al_cerrar = ?, observacion = ?,
                    veredicto_por = ?, veredicto_at = ?, updated_at = ?
              WHERE id = ?',
            [
                $veredicto['asistio'], $veredicto['resultado'], $veredicto['nota_al_cerrar'],
                $veredicto['observacion'], $profesor_id, $ahora, $ahora, (int) $item->id,
            ]
        );

        $frase = $suyo
            ? 'Veredicto guardado.'
            : 'Veredicto guardado como titular del grupo: queda firmado a su nombre y el papel lo dirá.';

        // El recorte se dice, no se esconde. Va detrás y no en vez de: el veredicto SÍ
        // se guardó, y lo que cambia es que la observación entró corta. Ver el docblock
        // de `observacionValidada`.
        if ($veredicto['observacion_recortada']) {
            $frase .= ' La observación se guardó recortada a '.self::LARGO_OBSERVACION
                .' caracteres, que es lo que cabe en el papel.';
        }

        return $frase;
    }

    /**
     * **El id de profesor de quien pregunta, o `null` si no tiene ficha.**
     *
     * Es el espejo en PHP de `idDeProfesorDe()` del front
     * (`app2/src/app/core/sesion/permisos.ts`), y que sean el mismo criterio es lo que
     * impide que la pantalla enseñe un botón que la ruta contesta con 403.
     *
     * **No es `persona_id` a secas**, que es la trampa de §3.5 y está medida en
     * `Services/ContextoDeUsuario.php`: esa columna sale de una consulta distinta por
     * cada `tipo` y de una tabla distinta en cada una.
     *
     *     tipo 'Profesor'   ->  profesores.id      <- el único caso en que sirve
     *     tipo 'Alumno'     ->  alumnos.id
     *     tipo 'Acudiente'  ->  acudientes.id
     *     tipo 'Usuario'    ->  users.id           <- y trae `u.profesor_id` aparte, que es el bueno
     *
     * O sea que para una secretaria o un coordinador —los dos son `tipo: 'Usuario'`—
     * comparar `persona_id` con un `profesor_id` o con un `titular_id` **sólo puede
     * acertar por casualidad**, y en este colegio hay dos ids de `users` que coinciden
     * con un titular. Aquí el fallo no sería una pantalla rara: sería el veredicto de
     * un alumno firmado por quien no lo dio.
     *
     * Y se mira el **tipo** y no el rol `'profesor'`: `esDocente()` del front es
     * `rol || tipo`, y un `Usuario` con ese rol la pasa **con `persona_id` siendo
     * `users.id`**. Es la misma línea que ya lleva escrita
     * `PlanillaOfflineController::soyDocente()`.
     */
    private function miProfesorId($user): ?int
    {
        if (($user->tipo ?? '') === 'Profesor') {
            $propio = (int) ($user->persona_id ?? 0);

            return $propio > 0 ? $propio : null;
        }

        // `?? null` y no `->profesor_id` pelado: el contexto es un `stdClass` armado a
        // mano y la rama de `Profesor` no trae esa clave. Sin el `??`, leerla es un aviso
        // de PHP y Laravel los convierte en excepción — un 500 en la pantalla del docente.
        $asociado = (int) ($user->profesor_id ?? 0);

        return $asociado > 0 ? $asociado : null;
    }

    /**
     * El renglón con lo que hace falta para autorizarlo y fecharlo, de una consulta.
     *
     * `g.titular_id` viene de aquí y no de una segunda consulta porque la pregunta de
     * D3 —«¿es el titular del grupo de ESTE alumno?»— es del renglón concreto: un
     * docente puede ser titular de 7A y estar tocando un compromiso de 9B.
     *
     * 404 si no existe, que es la lección de la §52 de
     * `docs/migracion/05-codigo-muerto-y-roto.md`: un `[0]` desnudo sobre un id que no
     * está da «Undefined array key 0», o sea **500 donde tocaba 404**.
     */
    private function itemDelVeredicto(int $id): object
    {
        $fila = DB::selectOne(
            'SELECT ci.id, ci.compromiso_id, ci.profesor_id,
                    c.year_id, c.estado, c.resultado_entregado_at,
                    g.titular_id
               FROM compromiso_items ci
               INNER JOIN compromisos c ON c.id = ci.compromiso_id
               INNER JOIN matriculas ma ON ma.id = c.matricula_id AND ma.deleted_at IS NULL
               INNER JOIN grupos g ON g.id = ma.grupo_id AND g.deleted_at IS NULL
              WHERE ci.id = ?',
            [$id]
        );

        if ($fila === null) {
            abort(404, 'Ese renglón del compromiso no existe.');
        }

        return $fila;
    }

    /**
     * Lo que llega del cliente, comprobado uno a uno.
     *
     * Validación a mano y no `Validator::make`, que es lo que hace el resto de esta
     * familia: el mensaje lo lee un docente en mitad de la semana de nivelaciones y
     * tiene que decir qué hacer, no qué regla se incumplió.
     *
     * `observacion_recortada` no es un dato del veredicto: es lo que hace que el
     * recorte de {@see observacionValidada} se pueda **decir** en la respuesta en vez
     * de hacerse en silencio. Viaja aquí y no en una propiedad del controlador porque
     * el router reutiliza la instancia entre llamadas del mismo proceso (03-tests.md),
     * y un estado suyo se le quedaría pegado a la petición siguiente.
     *
     * @return array{asistio: ?int, resultado: string, nota_al_cerrar: ?float, observacion: ?string, observacion_recortada: bool}
     */
    private function veredictoValidado(int $year_id): array
    {
        $resultado = Request::input('resultado');

        if (! is_string($resultado) || ! in_array($resultado, self::RESULTADOS, true)) {
            abort(422, 'El resultado tiene que ser `'.implode('`, `', self::RESULTADOS).'`.');
        }

        $observacion = $this->observacionValidada();

        return [
            'asistio' => $this->asistioValidado(),
            'resultado' => $resultado,
            'nota_al_cerrar' => $this->notaAlCerrarValidada($year_id),
            'observacion' => $observacion['texto'],
            'observacion_recortada' => $observacion['recortada'],
        ];
    }

    /**
     * **La asistencia a la nivelación: sí, no, o «todavía no lo he dicho».**
     *
     * Los tres estados de la columna, y el `null` es tan respuesta como los otros dos
     * —ver el docblock de {@see putVeredicto}—. El contrato lo declara
     * `asistio: boolean | null` por lo mismo.
     *
     * `FILTER_NULL_ON_FAILURE` es la línea que hace el trabajo: **sin él, un `"quizá"`
     * se convierte en `false`** —«se le convocó y no fue», que es una afirmación con
     * consecuencias— sin dar ningún error. Con él, sólo pasan `true/false`, `1/0`,
     * `"1"/"0"`, `"true"/"false"`, `"yes"/"no"`, `"on"/"off"`, y lo demás es 422.
     *
     * Y la ausencia se distingue de la basura **antes** de llamarlo, porque para este
     * filtro `null` y `"pepe"` fallan igual y aquí no significan lo mismo.
     */
    private function asistioValidado(): ?int
    {
        $crudo = Request::input('asistio');

        if ($crudo === null || $crudo === '') {
            return null;
        }

        $asistio = filter_var($crudo, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        if ($asistio === null) {
            abort(422, 'La asistencia tiene que ser sí, no, o no decirse.');
        }

        // `(int)` y no el booleano de PHP: la columna es `tinyint(1)` y un `false` de PHP
        // llega a PDO como cadena vacía en algunas configuraciones, que es la familia de
        // fallos del 05 §13 — y aquí esa cadena vacía se guardaría como 0, o sea como la
        // afirmación contraria a la que el docente quiso dejar sin contestar.
        return (int) $asistio;
    }

    /**
     * La nota con la que se cierra el renglón: opcional, y dentro de la escala del
     * colegio.
     *
     * **Puede faltar y eso no es un error.** El docente que marca «asistió, en espera»
     * todavía no tiene definitiva, y obligarle a inventarse un número para poder
     * guardar la asistencia sería meter un dato falso en un documento firmado.
     *
     * Cuando viene, se comprueba con `EscalaDeNotas::comprobarEnAnio()` y no con un
     * rango escrito aquí: **la escala es del colegio** —hay uno que califica sobre 50—
     * y un 0-100 a mano mediría un colegio que no existe. Un año sin escala configurada
     * no bloquea, que es lo que ya decidió esa clase.
     */
    private function notaAlCerrarValidada(int $year_id): ?float
    {
        $valor = Request::input('nota_al_cerrar');

        if ($valor === null || $valor === '') {
            return null;
        }

        if (! is_numeric($valor)) {
            abort(422, 'La nota con la que se cierra tiene que ser un número.');
        }

        EscalaDeNotas::comprobarEnAnio($valor, $year_id);

        return (float) $valor;
    }

    /**
     * La observación del docente. Vacía se guarda como `NULL`.
     *
     * ## SE RECORTA AQUÍ, Y NI SE RECHAZA NI SE DEJA A LA BASE
     *
     * *Decisión de Joseth, 23 sep 2026.* Las tres salidas son distintas y las tres
     * tienen precio:
     *
     *  - **Dejarlo a la columna no se puede**, aunque el `varchar(255)` parezca que
     *    ya lo resuelve: **bajo `sql_mode` estricto MySQL no trunca, lanza**. Que hoy
     *    «funcione» depende de una configuración del servidor que no controlamos, y
     *    son **dieciséis cuentas de cPanel** que no tienen por qué coincidir. El
     *    fallo sería un 500 al guardar un veredicto, en el colegio que tenga el
     *    `sql_mode` estricto y sólo en ése — o sea el que no se reproduce en local.
     *  - **Un 422 que rechace** —que es lo que había aquí— le tira al docente lo que
     *    acaba de escribir, y esta pantalla está hecha para despachar **cuarenta
     *    filas seguidas**: lo que cuesta no es una frase, es la tanda.
     *  - **Recortar en silencio** es pérdida de datos, y esto acaba en un papel que
     *    se firma. **Media frase en un documento es peor que una frase corta**,
     *    porque nadie se entera hasta que lo tiene impreso delante de una familia.
     *
     * Así que se recorta **y se dice**: {@see putVeredicto} añade la frase a su
     * respuesta cuando `recortada` viene en `true`.
     *
     * `mb_substr` y no `substr`: el tope de la columna se cuenta en caracteres para
     * quien escribe, y cortar bytes por la mitad de una tilde deja un carácter roto
     * — la misma familia de fallo que esto viene a evitar, y más fea.
     *
     * @return array{texto: ?string, recortada: bool}
     */
    private function observacionValidada(): array
    {
        $valor = Request::input('observacion');

        if ($valor === null) {
            return ['texto' => null, 'recortada' => false];
        }

        if (! is_string($valor)) {
            abort(422, 'La observación tiene que ser un texto.');
        }

        $valor = trim($valor);

        if ($valor === '') {
            return ['texto' => null, 'recortada' => false];
        }

        if (mb_strlen($valor) > self::LARGO_OBSERVACION) {
            return [
                'texto' => mb_substr($valor, 0, self::LARGO_OBSERVACION),
                'recortada' => true,
            ];
        }

        return ['texto' => $valor, 'recortada' => false];
    }

    /**
     * **D4: lo que la nivelación ya dice, para proponerlo.**
     *
     * ## De qué tabla sale, que es la decisión entera
     *
     * De **`notas_finales`** y no de `notas`, aunque las dos tengan desde el 2 sep 2026
     * las mismas cinco columnas (§2.2). El motivo es el grano:
     *
     *     notas           una fila por INDICADOR   (subunidad × alumno), `nota` es `int`
     *     notas_finales   una fila por ASIGNATURA y PERIODO, `nota` es `decimal(7,4)`
     *
     * Un `compromiso_items` es exactamente *(asignatura, periodo)*, que es el grano de
     * `notas_finales`; y `nota_al_crear` es `decimal(7,4)` **porque congela una
     * definitiva**, no una casilla (cabecera de la migración). Leer `notas` obligaría a
     * resumir N indicadores en un veredicto de asignatura, y «niveló un indicador» no
     * es «niveló la asignatura»: el sistema estaría inventando el resumen y
     * proponiéndoselo al docente como si saliera de la base.
     *
     * ## «Nivelada» es `nota_original IS NOT NULL`, y el cero cuenta
     *
     * No hay bandera de «está nivelada» —sería un segundo sitio donde mentir— y la
     * comprobación va en el `WHERE` y no en PHP. Se escribe `IS NOT NULL` y no una
     * verdad laxa porque **un alumno que venía de cero está nivelado**, y un
     * `if ($fila->nota_original)` lo dejaría fuera sin dar ningún error. El front ya
     * tropezó con esto (`comunes/nivelacion.ts`) y `NivelacionesController` lo lleva
     * escrito.
     *
     * ## Qué se propone, y con qué número se decide
     *
     * `nota` es la **vigente** —`notas_finales.nota`, la que ya tiene aplicada la regla
     * del colegio (`topada`, `mayor` o `reemplaza`)— porque es la que acabaría en
     * `nota_al_cerrar`. Y `nivelo` / `no_nivelo` sale de compararla con
     * `years.nota_minima_aceptada`, que es la que define «perdida» en los ocho sitios
     * que la miran. Se lee con `Nivelacion::reglaDelAnio()` para no tener la mínima del
     * colegio escrita dos veces.
     *
     * ## Los renglones de ÁREA no llevan sugerencia, y es a propósito
     *
     * La nivelación se registra por asignatura; un área no tiene fila que nivelar —su
     * nota es un promedio—. Resumir las asignaturas del área en un «niveló» sería el
     * sistema **fabricando un veredicto que ningún docente escribió**, que es justo lo
     * que D4 prohíbe al decir *«propuesto no es escrito»*. Con `regla = 'area'` el
     * docente teclea, y la pantalla no tiene que fingir lo contrario: `sugerido` viene
     * `null`, que el contrato define como «no hay nivelación registrada».
     *
     * ## Una consulta y no una por renglón
     *
     * Se piden todos los alumnos y todas las asignaturas de la página de una vez y se
     * cruza en PHP por `alumno|asignatura|periodo`. Con un titular de bachillerato esto
     * son cincuenta renglones, y cincuenta consultas dentro de un bucle es de donde
     * salió `ConsultasLentas`.
     *
     * @param  array<int, object>  $filas
     * @return array<string, array{resultado: string, nota: float, observacion: ?string}>
     */
    private function sugeridosDeLaNivelacion(array $filas, int $year_id): array
    {
        $alumnos = [];
        $asignaturas = [];

        foreach ($filas as $fila) {
            if ($fila->asignatura_id === null) {
                continue;   // Los de área no tienen de dónde leer. Ver arriba.
            }

            $alumnos[(int) $fila->alumno_id] = true;
            $asignaturas[(int) $fila->asignatura_id] = true;
        }

        if ($alumnos === [] || $asignaturas === []) {
            return [];
        }

        $regla = Nivelacion::reglaDelAnio($year_id);

        // Sin año no hay mínima con la que decidir «niveló», y **no se cae a un número
        // por defecto**: un 70 inventado en un colegio que califica sobre 50 propondría
        // «no niveló» de todo el mundo. Sin dato, no se propone nada, que es lo que
        // `null` significa en el contrato.
        if ($regla === null) {
            return [];
        }

        $minima = (int) $regla['nota_minima'];

        // Los ids ya están pasados por `(int)`, así que interpolarlos no abre nada;
        // `DB::select` no acepta un array como un solo valor y construir las marcas a
        // mano deja la misma lista. Es lo que ya hace `NombreDelAlumno::deVarios`.
        $listaAlumnos = implode(',', array_keys($alumnos));
        $listaAsignaturas = implode(',', array_keys($asignaturas));

        $niveladas = DB::select(
            'SELECT nf.alumno_id, nf.asignatura_id, p.numero AS periodo,
                    nf.nota, nf.nivelacion_obs
               FROM notas_finales nf
               INNER JOIN periodos p ON p.id = nf.periodo_id AND p.deleted_at IS NULL AND p.year_id = ?
              WHERE nf.nota_original IS NOT NULL
                AND nf.alumno_id IN ('.$listaAlumnos.')
                AND nf.asignatura_id IN ('.$listaAsignaturas.')',
            [$year_id]
        );

        $mapa = [];

        foreach ($niveladas as $fila) {
            $nota = (float) $fila->nota;

            $mapa[$fila->alumno_id.'|'.$fila->asignatura_id.'|'.$fila->periodo] = [
                'resultado' => ! \App\Support\NotaImpresa::perdida($nota, $minima) ? 'nivelo' : 'no_nivelo',
                'nota' => $nota,
                'observacion' => $fila->nivelacion_obs,
            ];
        }

        return $mapa;
    }

    /**
     * Un renglón con los tipos que el contrato declara.
     *
     * **Los tipos importan tanto como los valores**, y es la misma lección que dejó
     * escrita `CompromisosConfigController::pintarConfig`: PDO devuelve los `tinyint` y
     * los `decimal` como **cadenas**, y `"0"` es verdadero para el front en cuanto
     * alguien escriba `if (item.asistio)` en TypeScript. `nota_al_crear` sale como
     * `"39.0000"` y `ItemDelCompromiso.nota_al_crear` es `number`: sin el cast, una
     * comparación numérica en la pantalla se hace por texto.
     *
     * `asistio` se castea a `int` y no a `bool` **a propósito**: son tres estados, no
     * dos, y el contrato lo declara `number | null` por eso mismo. `null` es «el docente
     * no ha contestado» y **no** «no asistió».
     *
     * @param  array<string, array{resultado: string, nota: float, observacion: ?string}>  $sugeridos
     * @return array<string, mixed>
     */
    private function pintarItem(object $fila, array $sugeridos): array
    {
        $clave = $fila->alumno_id.'|'.$fila->asignatura_id.'|'.$fila->periodo;

        return [
            'id' => (int) $fila->id,
            'compromiso_id' => (int) $fila->compromiso_id,

            'asignatura_id' => $fila->asignatura_id === null ? null : (int) $fila->asignatura_id,
            'area_id' => $fila->area_id === null ? null : (int) $fila->area_id,
            // El nombre LARGO y no el alias: `materias.alias` es la abreviatura de tres
            // letras para las columnas estrechas del boletín («MAT»), y esto es un
            // documento que se lee en prosa.
            'nombre' => (string) ($fila->nombre ?? ''),
            'profesor_id' => $fila->profesor_id === null ? null : (int) $fila->profesor_id,
            'profesor' => $fila->profesor === null || $fila->profesor === '' ? null : $fila->profesor,
            'foto_profesor' => $fila->foto_profesor ?? null,

            'nota_al_crear' => (float) $fila->nota_al_crear,

            'asistio' => $fila->asistio === null ? null : (int) $fila->asistio,
            'resultado' => $fila->resultado,
            'nota_al_cerrar' => $fila->nota_al_cerrar === null ? null : (float) $fila->nota_al_cerrar,
            'observacion' => $fila->observacion,
            'veredicto_por' => $fila->veredicto_por === null ? null : (int) $fila->veredicto_por,
            'veredicto_por_nombre' => $fila->veredicto_por_nombre === null || $fila->veredicto_por_nombre === ''
                ? null : $fila->veredicto_por_nombre,
            'veredicto_at' => $fila->veredicto_at,

            'sugerido' => $sugeridos[$clave] ?? null,

            /* Los tres que el contrato declara opcionales **para esta pantalla**: enseña
             * renglones de varios alumnos juntos, así que sin ellos la lista no se puede
             * ni ordenar ni leer. */
            'alumno' => $fila->alumno,
            'grupo' => $fila->grupo,
            'periodo' => (int) $fila->periodo,
        ];
    }

    /**
     * El año de la petición, comprobado.
     *
     * Por defecto el de la sesión. Un año que no existe —o que está en la papelera— es
     * **404** y no un 200 con la lista vacía, que se leería como «no tienes nada
     * pendiente».
     *
     * Copiado de `CompromisosConfigController::anioDeLaPeticion` y no heredado, por lo
     * mismo que aquél lo copió de `FormulariosInscripcionController`: subirlo a
     * `Controller` es meter una consulta a `years` en la clase base de los 96
     * controladores para que la usen tres. Ese día ya está más cerca, y cuando llegue el
     * cuarto es cuando toca el `trait`.
     */
    private function anioDeLaPeticion(mixed $year_id): int
    {
        if ($year_id === null || $year_id === '') {
            return (int) $this->user->year_id;
        }

        if (! is_numeric($year_id)) {
            abort(422, 'El año no es válido.');
        }

        $anio = DB::selectOne('SELECT id FROM years WHERE id=? AND deleted_at IS NULL', [(int) $year_id]);

        if (! $anio) {
            abort(404, 'Ese año lectivo no existe.');
        }

        return (int) $anio->id;
    }

    /**
     * El periodo del filtro, o `null` si no se pidió ninguno.
     *
     * Es el **ordinal** —lo que guarda `compromisos.periodo`— y no `periodo_id`: el
     * compromiso guarda el número porque es lo que se imprime («cursado el 50 % del
     * año») y lo que obliga D6.2 en el renglón del boletín.
     *
     * El tope es 12 y no 4 aunque los periodos sean cuatro: la columna es
     * `unsignedTinyInteger` y la propia migración deja escrito que hay colegios de tres.
     * Lo que se está evitando aquí es un teclazo, no un colegio raro.
     */
    private function periodoOpcional(mixed $periodo): ?int
    {
        if ($periodo === null || $periodo === '') {
            return null;
        }

        if (! is_numeric($periodo) || (float) $periodo != (int) $periodo
            || (int) $periodo < 1 || (int) $periodo > 12) {
            abort(422, 'El periodo tiene que ser un número entre 1 y 12.');
        }

        return (int) $periodo;
    }
}
