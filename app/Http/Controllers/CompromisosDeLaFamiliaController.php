<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResuelveElUsuario;
use App\Support\Autoriza;
use App\Support\Reloj;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;

/**
 * **El lado de la familia del compromiso académico**: leerlo, y las DOS firmas.
 *
 *     GET compromisos/de-alumno/{alumno_id?}   persona.propia
 *     PUT compromisos/{id}/acuse               persona.propia
 *     PUT compromisos/{id}/acuse-resultado     persona.propia
 *
 * Diseño en `myvc_front/COMPROMISOS-ACADEMICOS.md` §3.3, §4.3, §4.4 y §5; el contrato,
 * en `app2/src/app/datos/compromisos.ts` (`deAlumno`, `acuse`, `acuseDelResultado`).
 *
 * ## Sin `auth.personal`, y el patrón ya existía
 *
 * `auth.personal` **aborta con 403 a `Alumno` y `Acudiente`**: es la puerta que cierra
 * el resto del módulo y por eso estas tres no la llevan. Llevan `persona.propia`, que
 * es la misma forma que estrenó `disciplina/mis-fichas/{alumno_id?}`
 * (`routes/api/disciplina.php:109`, la única de disciplina sin `auth.personal`): la
 * guarda deja pasar de largo a quien no es alumno ni acudiente, y a los que lo son les
 * comprueba que el `alumno_id` pedido sea el suyo o el de un acudido. **Sin id
 * significa «lo mío»**, y eso lo resuelve el controlador y no la guarda — letra por
 * letra lo que hace `DisciplinaController::getMisFichas`.
 *
 * ## LA TRAMPA DE LAS DOS RUTAS CON `{id}`, que es lo más importante de este fichero
 *
 * `ExigirPersonaPropia` mira una lista cerrada de nombres —`alumno_id`, `user_id`,
 * `persona_id`, `acudiente_id`, `profesor_id`, `matricula_id`, `imagen_id`…— y el
 * `{id}` genérico **sólo si la ruta declara a qué apunta** (`persona.propia:user_id`).
 * En `compromisos/{id}/acuse` ese `{id}` es de `compromisos`, que **no está en la
 * lista y no puede estarlo**: no es una persona.
 *
 * O sea que **el middleware no protege nada en esas dos rutas**. Un acudiente
 * autenticado que cambie el número de la URL firmaría el compromiso del hijo de otro,
 * que además es un documento con datos de un menor (Ley 1581, §4.4). La comprobación
 * la hace {@see compromisoDelAcudiente} **dentro del controlador**, y es la única que
 * hay. Quien mueva estas rutas o cambie el nombre del parámetro tiene que leer esto
 * antes: no es defensa en profundidad, es la defensa.
 *
 * En `GET compromisos/de-alumno/{alumno_id?}` sí muerde la guarda —el parámetro se
 * llama `alumno_id`, que está en la lista—, y aun así {@see exigirAlumnoSuyo} lo
 * vuelve a comprobar: el fichero de rutas lo escribe otra mano, y una ruta de datos de
 * menores no puede depender de que nadie se olvide de un middleware.
 *
 * ## Las dos firmas son dos, con el mismo valor y fechas distintas (R4, §1.4)
 *
 * La de ida —*«conozco el compromiso»*— y la de vuelta —*«conozco el resultado»*—.
 * No es un «visto»: **el plazo para reclamar no empieza a correr el día en que el
 * docente escribe el veredicto, sino el día en que el acudiente se entera**, y sin esa
 * segunda fecha el colegio no puede sostener que el plazo venció. Un compromiso con
 * firma de entrada y sin firma de salida demuestra que se avisó del problema y **no**
 * que se informó de cómo terminó.
 *
 * ## Firma electrónica, y qué se guarda de ella (§4.3, D7)
 *
 * La **Ley 527 de 1999** (art. 7) y el **Decreto 2364 de 2012** reconocen la firma
 * electrónica simple: *«códigos, contraseñas… que permitan identificar a una persona
 * en relación con un mensaje de datos»*, confiable y apropiada para el fin, sin
 * certificado de tercero y **válida hasta prueba en contrario**. Traducido: el
 * acudiente autenticado pulsando «Acepto» **es firma válida** si consta quién, cuándo,
 * desde dónde y **sobre qué texto exacto**. De las cuatro:
 *
 *   - **quién** → `acuse_por` / `resultado_acuse_por` (`acudientes.id`);
 *   - **cuándo** → `acuse_at` / `resultado_acuse_at`, en hora de Bogotá y en `DATETIME`;
 *   - **sobre qué texto** → `compromisos.texto`, congelado al crear (§3.1). Es la
 *     razón de que esa columna exista: si el texto se leyera de la plantilla al
 *     imprimir, la firma sería sobre un documento que puede cambiar después.
 *   - **desde dónde** → **no se guarda, y hay que decirlo.** No hay columna de IP en
 *     el esquema y esta tanda no añade migraciones. `Services\Auditoria` sí graba `ip`
 *     y `ruta`, pero su vocabulario de entidades es cerrado y no tiene `compromiso`.
 *     Queda anotado como lo que es: un hueco conocido, no un olvido.
 *
 * **D7: acompaña al papel, no lo sustituye.** Por eso se guarda `acuse_canal` y no
 * sólo la fecha: el papel imprime cuál de las dos ocurrió —«Aceptado en la plataforma
 * el 14/10/2026 a las 19:32 por Ana Gómez»— y el colegio sigue firmando en mano el día
 * de entrega de boletines.
 */
class CompromisosDeLaFamiliaController extends Controller
{
    use ResuelveElUsuario;

    /**
     * Por dónde llegó la firma cuando llega por aquí, y **lo pone el servidor**.
     *
     * El contrato manda `acuse(id)` con el cuerpo vacío a propósito: si el canal
     * viniera del cliente, un acudiente podría declarar `'papel'` desde el móvil y el
     * documento diría que firmó en mano una firma que fue electrónica. El canal
     * `'papel'` lo registra el colegio por su ruta, no la familia por ésta.
     */
    private const CANAL_DE_LA_FIRMA = 'app';

    /** Los dos botones de la segunda firma. «Enterado» y «no estoy de acuerdo» (§1.4). */
    private const TIPOS_DE_ACUSE = ['enterado', 'reclama'];

    /**
     * `reclamacion_texto` es `text` y aguanta 65.535 **bytes**, que no es lo mismo que
     * caracteres: en español cada tilde gasta dos. Cinco mil caracteres son de sobra
     * para una reclamación escrita a mano y evitan que alguien pegue un fichero en una
     * columna que después hay que leer en una pantalla.
     */
    private const LARGO_RECLAMACION = 5000;

    /**
     * **Los compromisos de un alumno, para él y para su familia.**
     *
     * Sin `alumno_id` devuelve los del alumno de la sesión; con él, los de ese hijo.
     *
     * ## Qué se devuelve y qué NO, que es §4.4 y no una preferencia
     *
     * El formato del Bethel ya cita la **Ley 1581 de 2012** y hace bien: esto lleva
     * datos de un menor y de su rendimiento. La regla del diseño es *«no enseñar más de
     * lo que hace falta»*, y aquí se concreta en cuatro recortes, tres de ellos con
     * consecuencia jurídica:
     *
     *  1. **Los `borrador` no salen.** Un compromiso que el coordinador está armando no
     *     se ha entregado a nadie: enseñárselo a la familia por la app sería entregarlo
     *     sin `entregado_at`, y R2 es justamente *«se avisó, y consta que llegó»*. El
     *     aviso lo da el colegio cuando decide darlo.
     *  2. **El veredicto no viaja hasta que el resultado se ha notificado.** Mientras
     *     `resultado_entregado_at` esté en nulo, los renglones salen con `asistio`,
     *     `resultado`, `nota_al_cerrar`, `observacion` y las columnas de R3 en `null`.
     *     **Es el punto entero de R4**: el plazo de reclamación corre desde que la
     *     familia se entera, y si la app se lo enseñara antes, la fecha que el colegio
     *     guarda como «se enteró» sería mentira y el plazo no se podría sostener. Un
     *     veredicto a medio escribir —el docente aún corrigiéndolo— tampoco es algo que
     *     se le enseñe a nadie como resultado.
     *  3. **`sugerido` nunca viaja.** Es D4, o sea *una sugerencia para el docente*
     *     leída de la nivelación; el item sigue como está hasta que él confirma.
     *     Mandárselo a la familia sería enseñarle un «no niveló» que ningún docente ha
     *     firmado. La clave sale igual, en `null`, porque el contrato la declara y una
     *     clave que a veces no viene obliga a distinguir «vacío» de «no vino».
     *  4. **Los ids internos del personal no viajan.** `creado_por` y `cerrado_por` son
     *     `users.id` y no le dicen nada a una familia; lo que el papel necesita es el
     *     **nombre** de quien abrió el expediente, y ése sí va. El contrato los declara
     *     anulables, así que van en `null` y la pantalla no cambia.
     *
     * Lo que **sí** va entero es el documento: el texto congelado, el plazo, la lista de
     * asignaturas con la nota con la que se abrió, las cuatro fechas de las dos idas y
     * vueltas y la reclamación si la hubo. Es suyo y es lo que tienen que poder leer y
     * guardar.
     *
     * ## Todos los años, y no sólo el actual
     *
     * `disciplina/mis-fichas` elige un año porque una ficha es de un año. Un compromiso
     * es un **expediente**, y el historial es medio módulo: *«en el segundo periodo
     * volvió a perder… ahí ya le van a salir dos»*. Y la T-226/20 lee el historial
     * **contra** el colegio si lo único que hizo fue repetir el mismo papel, así que la
     * familia tiene que poder verlo completo. Van ordenados del más nuevo al más viejo.
     *
     * ## El personal del colegio pasa, pero tiene que decir de quién
     *
     * `ExigirPersonaPropia` deja pasar de largo a quien no es alumno ni acudiente, y
     * aquí se les deja pasar igual —esta respuesta es **más estrecha** que la de
     * `GET compromisos/{id}`, así que no abre nada—. Lo que no se hace es adivinar el
     * alumno: sin id, un `Alumno` es él mismo y cualquier otro se lleva un 400, que es
     * letra por letra lo que hace `getMisFichas`. Un acudiente **tiene** que decir de
     * cuál de sus acudidos habla.
     */
    public function getDeAlumno($alumno_id = '')
    {
        $user = $this->user;

        if ($alumno_id === '' || $alumno_id === null) {
            if (($user->tipo ?? '') === 'Alumno') {
                $alumno_id = $user->persona_id;
            } else {
                return abort(400, 'No hay id de alumno');
            }
        }

        if (! is_numeric($alumno_id)) {
            abort(422, 'El alumno no es válido.');
        }

        $alumno_id = (int) $alumno_id;

        $this->exigirAlumnoSuyo($user, $alumno_id);

        $filas = DB::select(
            'SELECT c.id, c.year_id, c.periodo, c.matricula_id, c.regla, c.cantidad_perdidas,
                    c.porcentaje_ano, c.texto, c.plazo_desde, c.plazo_hasta, c.estado,
                    c.creado_por, c.created_at,
                    c.entregado_at, c.entrega_canal, c.acuse_at, c.acuse_por, c.acuse_canal,
                    c.cerrado_at, c.cerrado_por,
                    c.resultado_entregado_at, c.resultado_canal, c.resultado_acuse_at,
                    c.resultado_acuse_por, c.resultado_acuse_tipo, c.reclamacion_texto,
                    c.reclamacion_vence,
                    g.id AS grupo_id, g.nombre AS grupo,
                    al.id AS alumno_id, TRIM(CONCAT(al.nombres, " ", al.apellidos)) AS alumno,
                    al.documento AS documento_alumno,
                    ucreo.username AS creado_por_nombre,
                    y.year
               FROM compromisos c
               INNER JOIN matriculas ma ON ma.id = c.matricula_id AND ma.deleted_at IS NULL
               INNER JOIN grupos g ON g.id = ma.grupo_id AND g.deleted_at IS NULL
               INNER JOIN alumnos al ON al.id = ma.alumno_id AND al.deleted_at IS NULL
               INNER JOIN years y ON y.id = c.year_id AND y.deleted_at IS NULL
               LEFT JOIN users ucreo ON ucreo.id = c.creado_por
              WHERE ma.alumno_id = ?
                AND c.estado <> "borrador"
              ORDER BY y.year DESC, c.periodo DESC, c.id DESC',
            [$alumno_id]
        );

        if ($filas === []) {
            return ['compromisos' => []];
        }

        $items = $this->itemsDeLosCompromisos($filas);
        $acudientes = $this->acudientesDelAlumno($alumno_id, $filas);

        $compromisos = [];

        foreach ($filas as $fila) {
            $compromisos[] = $this->pintarCompromiso($fila, $items, $acudientes);
        }

        return ['compromisos' => $compromisos];
    }

    /**
     * **La PRIMERA firma**: el acudiente acepta el compromiso.
     *
     * ## Firma el acudiente, y sólo el acudiente
     *
     * §3.5 pone a «Acudiente / alumno» en la misma casilla para **ver**, y eso es
     * correcto; para **firmar** no, y la columna lo dice: `acuse_por` es `acudientes.id`
     * (cabecera de la migración, *«— la primera firma»*). El `persona_id` de un alumno
     * es `alumnos.id`, así que dejarle firmar escribiría un id de otra tabla en esa
     * columna y el expediente diría que firmó el acudiente número N, que es otra
     * persona. Es exactamente la trampa de §3.5 llevada a una escritura.
     *
     * Y hay un motivo que no es técnico: lo que blinda al colegio es el aviso **al
     * acudiente** (§1.4, pieza 2). Un compromiso firmado por el propio menor no prueba
     * que la familia se enteró.
     *
     * **El personal del colegio tampoco firma por aquí.** `ExigirPersonaPropia` deja
     * pasar de largo a quien no es alumno ni acudiente, así que sin esta comprobación
     * cualquier cuenta del colegio podría poner la firma de una familia — y eso no es
     * un acuse, es una firma puesta por el firmado.
     *
     * ## Sin entrega no hay acuse
     *
     * `entregado_at` es R2. Acusar recibo de algo que no se ha entregado deja un
     * expediente donde la familia consta enterada **antes** de que el colegio avisara,
     * y eso no se puede explicar en ninguna parte.
     *
     * ## La segunda pulsación NO pisa la fecha de la primera
     *
     * Lo garantizan dos cosas y no una: el `WHERE ... AND acuse_at IS NULL` del
     * `UPDATE` —que es lo que aguanta dos pestañas a la vez, donde un `if` no llega— y
     * la salida de arriba, que contesta 200 con la fecha que ya había. **Pulsar dos
     * veces no es un error del usuario**, así que no se le devuelve uno; lo que no
     * puede pasar es que la fecha que prueba cuándo se enteró se mueva a hoy.
     */
    public function putAcuse($id)
    {
        $acudiente_id = $this->acudienteQueFirma($this->user);
        $compromiso = $this->compromisoDelAcudiente((int) $id, $acudiente_id);

        if ($compromiso->entregado_at === null) {
            abort(422, 'Ese compromiso todavía no se ha entregado: no hay nada que aceptar.');
        }

        if ($compromiso->acuse_at !== null) {
            return 'Ya estaba aceptado el '.$this->humana($compromiso->acuse_at).'. No se cambia nada.';
        }

        $ahora = Reloj::ahoraTexto();

        DB::update(
            'UPDATE compromisos
                SET acuse_at = ?, acuse_por = ?, acuse_canal = ?, updated_at = ?
              WHERE id = ? AND acuse_at IS NULL',
            [$ahora, $acudiente_id, self::CANAL_DE_LA_FIRMA, $ahora, (int) $compromiso->id]
        );

        // **El `estado` no se toca**, y es a propósito: `borrador → entregado → cerrado
        // → notificado` es lo que hace el COLEGIO con el expediente, y el acuse es lo
        // que hace la familia. Son dos ejes distintos y por eso el acuse tiene columnas
        // propias. Moverlo aquí haría que un compromiso sin firmar y uno firmado se
        // leyeran igual en el tablero, que es justo lo que §6 D8 pide poder contar.
        return 'Recibido. Queda constancia de que usted aceptó el compromiso el '.$this->humana($ahora).'.';
    }

    /**
     * **La SEGUNDA firma**: enterado del resultado, o reclamación con su texto.
     *
     * Es el paso 12 de §5 y el que cierra el expediente: el papel sale con **dos firmas
     * del acudiente y dos fechas**, y entre ellas está todo lo que el colegio hizo.
     *
     * ## Los dos botones, y por qué el segundo existe
     *
     * *«Estoy enterado»* y *«No estoy de acuerdo»*. Si la única salida fuese firmar, el
     * desacuerdo no quedaría escrito en ninguna parte y **reaparecería meses después
     * como tutela**. Con `reclama` vive dentro del propio documento, con su fecha. Por
     * eso el texto es obligatorio ahí: una reclamación sin motivo no se puede contestar,
     * y el colegio tiene que poder contestarla (§1.4, pieza 4).
     *
     * ## QUÉ PASA SI EL PLAZO YA VENCIÓ — la decisión, y el porqué
     *
     * **Se acepta igual, y queda constancia de que llegó tarde.** No se rechaza.
     *
     * D8 elige la opción (a): *el plazo de reclamación vence solo y el expediente queda
     * «notificado sin acuse»*. Eso decide **cuándo el expediente queda en firme**, que
     * es una pregunta del colegio; no dice que haya que cerrarle el buzón a la familia,
     * que es otra. Las tres razones, en orden:
     *
     *  1. **Rechazar una reclamación tardía es el software borrando el desacuerdo.** Es
     *     literalmente el fallo que `resultado_acuse_tipo` existe para evitar: el
     *     acudiente que no está de acuerdo, sin sitio donde decirlo, vuelve por otra
     *     puerta. Un 422 no hace que el desacuerdo no exista; hace que no esté escrito.
     *  2. **La tardanza ya está probada sin columna nueva.** `resultado_acuse_at`
     *     comparada con `reclamacion_vence` —las dos guardadas, la segunda calculada al
     *     notificar y congelada— dice si llegó dentro o fuera. Inventar un estado
     *     «tardío» sería un tercer sitio donde decir lo que dos fechas ya dicen.
     *  3. **Aceptarla no reabre el plazo ni debilita al colegio.** Lo exigible es
     *     *notificar*, no *que el otro conteste*: la prueba del colegio es
     *     `resultado_entregado_at`, y ésa no se mueve. Lo único que cambia es que el
     *     expediente tiene dentro lo que la familia dijo, y con qué fecha lo dijo.
     *
     * La respuesta lo dice en voz alta —«fuera del plazo, que venció el …»— para que
     * nadie crea que reclamó a tiempo, y el papel lo imprime igual comparando las dos
     * fechas. Lo que este endpoint **no** hace es decidir si la reclamación tardía
     * prospera: eso es del colegio y se responde a mano, como todas.
     *
     * ## Sin resultado entregado no hay nada que acusar
     *
     * `resultado_entregado_at` en nulo significa que el colegio aún no ha informado del
     * resultado, y `getDeAlumno` no se lo ha enseñado. Firmar ahí sería acusar recibo de
     * algo que no se ha recibido.
     */
    public function putAcuseResultado($id)
    {
        $acudiente_id = $this->acudienteQueFirma($this->user);
        $compromiso = $this->compromisoDelAcudiente((int) $id, $acudiente_id);

        if ($compromiso->resultado_entregado_at === null) {
            abort(422, 'Todavía no se le ha entregado el resultado de ese compromiso.');
        }

        if ($compromiso->resultado_acuse_at !== null) {
            return 'Ya estaba firmado el '.$this->humana($compromiso->resultado_acuse_at).'. No se cambia nada.';
        }

        $tipo = Request::input('tipo');

        if (! is_string($tipo) || ! in_array($tipo, self::TIPOS_DE_ACUSE, true)) {
            abort(422, 'Hay que decir si queda enterado o si no está de acuerdo.');
        }

        $texto = $this->textoDeLaReclamacion($tipo);

        $ahora = Reloj::ahoraTexto();

        // La comparación es de DÍA contra DÍA: `reclamacion_vence` es `date` —el último
        // día hábil, no una hora— así que firmar a las 23:50 del día que vence está
        // dentro. Comparar una fecha con una marca de tiempo dejaría fuera casi un día
        // entero del plazo que el papel prometió.
        $vencio = $compromiso->reclamacion_vence !== null
            && Reloj::ahora()->toDateString() > $compromiso->reclamacion_vence;

        DB::update(
            'UPDATE compromisos
                SET resultado_acuse_at = ?, resultado_acuse_por = ?, resultado_acuse_tipo = ?,
                    reclamacion_texto = ?, updated_at = ?
              WHERE id = ? AND resultado_acuse_at IS NULL',
            [$ahora, $acudiente_id, $tipo, $texto, $ahora, (int) $compromiso->id]
        );

        $frase = $tipo === 'reclama'
            ? 'Su reclamación quedó registrada el '.$this->humana($ahora).'.'
            : 'Recibido. Queda constancia de que usted conoció el resultado el '.$this->humana($ahora).'.';

        if ($vencio) {
            // Se dice, no se esconde: la familia tiene derecho a saber que firmó fuera de
            // plazo el mismo día, y no cuando el colegio le conteste que llegó tarde.
            $frase .= ' El plazo para reclamar había vencido el '
                .(Reloj::desdeTexto($compromiso->reclamacion_vence.' 00:00:00')?->format('d/m/Y')
                    ?? $compromiso->reclamacion_vence)
                .', así que consta con esa fecha.';
        }

        return $frase;
    }

    /**
     * El texto de la reclamación, cuando la hay.
     *
     * Con `enterado` se guarda **`null` y no lo que venga**: un «estoy enterado» que
     * arrastrara un texto de reclamación dejaría un expediente que dice las dos cosas a
     * la vez, y el papel no sabría cuál imprimir.
     */
    private function textoDeLaReclamacion(string $tipo): ?string
    {
        if ($tipo !== 'reclama') {
            return null;
        }

        $texto = Request::input('texto');

        if (! is_string($texto)) {
            $texto = '';
        }

        $texto = trim($texto);

        if ($texto === '') {
            abort(422, 'Para decir que no está de acuerdo hay que escribir por qué: '
                .'una reclamación sin motivo no se puede contestar.');
        }

        if (mb_strlen($texto) > self::LARGO_RECLAMACION) {
            abort(422, 'La reclamación no puede pasar de '
                .number_format(self::LARGO_RECLAMACION, 0, ',', '.').' caracteres.');
        }

        return $texto;
    }

    /**
     * Quién firma: **un acudiente, y devuelve su `acudientes.id`**.
     *
     * Ver el porqué en el docblock de {@see putAcuse}. El mensaje nombra al alumno
     * aparte porque el caso frecuente es un estudiante de once pulsando el botón desde
     * su propia cuenta, y «no tienes permiso» a secas no le dice qué hacer.
     */
    private function acudienteQueFirma($user): int
    {
        Autoriza::exigir(
            ($user->tipo ?? '') === 'Acudiente',
            'El compromiso lo firma el acudiente desde su propia cuenta.'
        );

        $acudiente_id = (int) ($user->persona_id ?? 0);

        // Cinturón: para un `Acudiente`, `persona_id` ES `acudientes.id`
        // (`Services/ContextoDeUsuario.php`), y sin id no hay firma que guardar. Un 0 en
        // una columna de firma es un id que no existe disfrazado de id que sí, que es lo
        // que hacía `Login.php` con `created_by = 0`.
        Autoriza::exigir($acudiente_id > 0, 'Su cuenta no tiene ficha de acudiente asociada.');

        return $acudiente_id;
    }

    /**
     * El compromiso que se va a firmar, **comprobando que el alumno es suyo**.
     *
     * Es la comprobación que el middleware no puede hacer: ver la cabecera de la clase.
     *
     * 404 si no existe y 403 si no es suyo, que es la forma de `Support\PedidoPropio` y
     * la de `ExigirPersonaPropia` (*«Solo puedes consultar lo tuyo»*). Se consideró
     * contestar 404 a los dos casos para no confirmar que el documento existe; se deja
     * el 403 porque es lo que ya contesta el middleware en las rutas hermanas y **dos
     * respuestas distintas para la misma situación** son peores que la pista: la
     * pantalla de la familia tendría que tratar «no existe» y «no es tuyo» igual en una
     * ruta y distinto en otra.
     */
    private function compromisoDelAcudiente(int $id, int $acudiente_id): object
    {
        $fila = DB::selectOne(
            'SELECT c.id, c.estado, c.entregado_at, c.acuse_at,
                    c.resultado_entregado_at, c.resultado_acuse_at, c.reclamacion_vence,
                    ma.alumno_id
               FROM compromisos c
               INNER JOIN matriculas ma ON ma.id = c.matricula_id AND ma.deleted_at IS NULL
              WHERE c.id = ?',
            [$id]
        );

        if ($fila === null) {
            abort(404, 'Ese compromiso no existe.');
        }

        Autoriza::exigir(
            $this->esAcudienteDe($acudiente_id, (int) $fila->alumno_id),
            'Solo puedes firmar el compromiso de un hijo tuyo.'
        );

        return $fila;
    }

    /**
     * Que el alumno pedido sea el suyo, para la lectura.
     *
     * El personal del colegio pasa de largo, igual que en `ExigirPersonaPropia` y por lo
     * mismo: lo que puede ver el personal entre sí se decide en otro sitio, y esta
     * respuesta es más estrecha que la que ya tienen.
     *
     * Está duplicado con el middleware **a sabiendas**: el fichero de rutas lo escribe
     * otra mano y una ruta con datos de un menor no puede quedarse abierta porque a
     * alguien se le olvide un `persona.propia`. Cuesta una consulta indexada.
     */
    private function exigirAlumnoSuyo($user, int $alumno_id): void
    {
        $tipo = $user->tipo ?? '';

        if ($tipo === 'Alumno') {
            Autoriza::exigir((int) ($user->persona_id ?? 0) === $alumno_id, 'Solo puedes consultar lo tuyo');

            return;
        }

        if ($tipo === 'Acudiente') {
            Autoriza::exigir(
                $this->esAcudienteDe((int) ($user->persona_id ?? 0), $alumno_id),
                'Solo puedes consultar lo tuyo'
            );
        }
    }

    /** ¿Es acudiente de ese alumno? Una lectura indexada sobre `parentescos`. */
    private function esAcudienteDe(int $acudiente_id, int $alumno_id): bool
    {
        if ($acudiente_id <= 0 || $alumno_id <= 0) {
            return false;
        }

        return DB::selectOne(
            'SELECT id FROM parentescos
              WHERE acudiente_id = ? AND alumno_id = ? AND deleted_at IS NULL LIMIT 1',
            [$acudiente_id, $alumno_id]
        ) !== null;
    }

    /**
     * Los renglones de todos los compromisos de la lista, **de una consulta**.
     *
     * Uno por compromiso serían N consultas dentro de un bucle, que es de donde salió
     * `Support\ConsultasLentas`; y un alumno de bachillerato con cuatro periodos puede
     * tener cuatro compromisos con doce renglones cada uno.
     *
     * @param  array<int, object>  $compromisos
     * @return array<int, array<int, object>> indexado por `compromiso_id`
     */
    private function itemsDeLosCompromisos(array $compromisos): array
    {
        $ids = array_map(static fn (object $c): int => (int) $c->id, $compromisos);

        // Interpolados, y ya están pasados por `(int)`: `DB::select` no acepta un array
        // como un solo valor. Es lo mismo que hace `NombreDelAlumno::deVarios`.
        $filas = DB::select(
            'SELECT ci.id, ci.compromiso_id, ci.asignatura_id, ci.area_id, ci.profesor_id,
                    ci.nota_al_crear, ci.asistio, ci.resultado, ci.nota_al_cerrar, ci.observacion,
                    ci.veredicto_por, ci.veredicto_at,
                    COALESCE(mm.materia, ar.nombre) AS nombre,
                    TRIM(CONCAT(pr.nombres, " ", pr.apellidos)) AS profesor,
                    TRIM(CONCAT(vp.nombres, " ", vp.apellidos)) AS veredicto_por_nombre
               FROM compromiso_items ci
               LEFT JOIN asignaturas asi ON asi.id = ci.asignatura_id
               LEFT JOIN materias mm ON mm.id = asi.materia_id
               LEFT JOIN areas ar ON ar.id = ci.area_id
               LEFT JOIN profesores pr ON pr.id = ci.profesor_id
               LEFT JOIN profesores vp ON vp.id = ci.veredicto_por
              WHERE ci.compromiso_id IN ('.implode(',', $ids).')
              ORDER BY asi.orden, mm.materia, ar.nombre, ci.id'
        );

        $porCompromiso = [];

        foreach ($filas as $fila) {
            $porCompromiso[(int) $fila->compromiso_id][] = $fila;
        }

        return $porCompromiso;
    }

    /**
     * Los acudientes del alumno, para poder escribir en el papel **quién firmó**.
     *
     * El contrato pide `acudiente` y `documento_acudiente` en la cabecera, y la regla
     * que se sigue es: **si alguien firmó, el que firmó**; si no, el primero de
     * `parentescos`, que es el que el colegio tiene puesto como principal. Dos reglas y
     * no una porque son dos preguntas distintas —«a quién se le entrega» y «quién
     * aceptó»— y la segunda, cuando existe, es la que vale como prueba.
     *
     * `acuse_por` puede apuntar a un acudiente que ya no está en `parentescos` —se
     * separó, se le quitó el parentesco— y la firma sigue siendo válida: por eso los ids
     * firmantes entran en la consulta aunque no estén en la lista. Es la misma razón por
     * la que esas columnas no llevan clave ajena.
     *
     * @param  array<int, object>  $compromisos
     * @return array{mapa: array<int, object>, primero: ?int}
     */
    private function acudientesDelAlumno(int $alumno_id, array $compromisos): array
    {
        $parentescos = DB::select(
            'SELECT acudiente_id FROM parentescos
              WHERE alumno_id = ? AND deleted_at IS NULL ORDER BY id',
            [$alumno_id]
        );

        $ids = [];
        $primero = null;

        foreach ($parentescos as $fila) {
            $ids[(int) $fila->acudiente_id] = true;
            $primero ??= (int) $fila->acudiente_id;
        }

        foreach ($compromisos as $compromiso) {
            foreach (['acuse_por', 'resultado_acuse_por'] as $columna) {
                if ($compromiso->$columna !== null) {
                    $ids[(int) $compromiso->$columna] = true;
                }
            }
        }

        if ($ids === []) {
            return ['mapa' => [], 'primero' => null];
        }

        // Sin `deleted_at`: un acudiente en la papelera firmó igual, y el papel tiene que
        // poder decir su nombre. Es la misma decisión que `ExigirPersonaPropia` tomó con
        // las matrículas borradas —«una matrícula borrada sigue siendo de alguien»—.
        $filas = DB::select(
            'SELECT ac.id, TRIM(CONCAT(ac.nombres, " ", ac.apellidos)) AS nombre, ac.documento
               FROM acudientes ac WHERE ac.id IN ('.implode(',', array_keys($ids)).')'
        );

        $mapa = [];

        foreach ($filas as $fila) {
            $mapa[(int) $fila->id] = $fila;
        }

        return ['mapa' => $mapa, 'primero' => $primero];
    }

    /**
     * Un compromiso con la forma que declara el contrato, ya recortado para la familia.
     *
     * Los `(int)` y los `(float)` no son cosmética: PDO devuelve `tinyint` y `decimal`
     * como **cadenas**, y `"0"` es verdadero en TypeScript en cuanto alguien escriba un
     * `if` sin `=== true`. `porcentaje_ano` llegaría como `"75"` y el contrato lo declara
     * `number`.
     *
     * @param  array<int, array<int, object>>  $items
     * @param  array{mapa: array<int, object>, primero: ?int}  $acudientes
     * @return array<string, mixed>
     */
    private function pintarCompromiso(object $fila, array $items, array $acudientes): array
    {
        // **La bisagra de todo el recorte**: hasta que el resultado se entrega, la
        // familia ve el compromiso y no el veredicto. Ver el docblock de `getDeAlumno`.
        $notificado = $fila->resultado_entregado_at !== null;

        $firmante = $fila->acuse_por !== null
            ? ($acudientes['mapa'][(int) $fila->acuse_por] ?? null)
            : null;

        $firmante ??= $acudientes['primero'] !== null
            ? ($acudientes['mapa'][$acudientes['primero']] ?? null)
            : null;

        return [
            'id' => (int) $fila->id,
            'year_id' => (int) $fila->year_id,
            'periodo' => (int) $fila->periodo,

            'matricula_id' => (int) $fila->matricula_id,
            'alumno_id' => (int) $fila->alumno_id,
            'alumno' => $fila->alumno,
            'documento_alumno' => $fila->documento_alumno,
            'grupo_id' => (int) $fila->grupo_id,
            'grupo' => $fila->grupo,
            'acudiente' => $firmante->nombre ?? null,
            'documento_acudiente' => $firmante->documento ?? null,

            'regla' => $fila->regla,
            'cantidad_perdidas' => (int) $fila->cantidad_perdidas,
            'porcentaje_ano' => (int) $fila->porcentaje_ano,
            // El texto CONGELADO al crear, que es sobre lo que se firma (§4.3). No se
            // vuelve a resolver contra `compromiso_bloques`: si el colegio retoca su
            // plantilla en diciembre, esta familia tiene que seguir leyendo lo que aceptó.
            'texto' => (string) ($fila->texto ?? ''),
            'plazo_desde' => $fila->plazo_desde,
            'plazo_hasta' => $fila->plazo_hasta,
            'estado' => $fila->estado,

            // `creado_por` es un `users.id` del personal y no le dice nada a una familia;
            // el nombre sí, porque R1 es «quién abrió esto y cuándo». §4.4.
            'creado_por' => null,
            'creado_por_nombre' => $fila->creado_por_nombre,
            'created_at' => $fila->created_at,

            /* ── la ida ── */
            'entregado_at' => $fila->entregado_at,
            'entrega_canal' => $fila->entrega_canal,
            'acuse_at' => $fila->acuse_at,
            'acuse_por' => $fila->acuse_por === null ? null : (int) $fila->acuse_por,
            'acuse_canal' => $fila->acuse_canal,

            'cerrado_at' => $fila->cerrado_at,
            // Quién cerró es trabajo interno del colegio; la fecha sí importa, porque es
            // la que separa «el colegio ya lo sabe» de «la familia ya lo sabe».
            'cerrado_por' => null,

            /* ── y la vuelta del resultado ── */
            'resultado_entregado_at' => $fila->resultado_entregado_at,
            'resultado_canal' => $fila->resultado_canal,
            'resultado_acuse_at' => $fila->resultado_acuse_at,
            'resultado_acuse_por' => $fila->resultado_acuse_por === null
                ? null : (int) $fila->resultado_acuse_por,
            'resultado_acuse_tipo' => $fila->resultado_acuse_tipo,
            'reclamacion_texto' => $fila->reclamacion_texto,
            'reclamacion_vence' => $fila->reclamacion_vence,

            'items' => array_map(
                fn (object $item): array => $this->pintarItem($item, $notificado),
                $items[(int) $fila->id] ?? []
            ),
        ];
    }

    /**
     * Un renglón para la familia.
     *
     * Con `$notificado = false` el veredicto entero viaja en `null`: no es que no exista,
     * es que **todavía no se le ha comunicado**, y ésa es la diferencia que sostiene R4.
     * Las claves salen igual —el contrato las declara y una que a veces no viene obliga a
     * la pantalla a distinguir «vacío» de «no vino»—.
     *
     * @return array<string, mixed>
     */
    private function pintarItem(object $fila, bool $notificado): array
    {
        return [
            'id' => (int) $fila->id,
            'compromiso_id' => (int) $fila->compromiso_id,

            'asignatura_id' => $fila->asignatura_id === null ? null : (int) $fila->asignatura_id,
            'area_id' => $fila->area_id === null ? null : (int) $fila->area_id,
            'nombre' => (string) ($fila->nombre ?? ''),
            // Quién da la asignatura sí va: sale impreso y es a quien la familia pregunta.
            'profesor_id' => $fila->profesor_id === null ? null : (int) $fila->profesor_id,
            'profesor' => $fila->profesor === null || $fila->profesor === '' ? null : $fila->profesor,

            // La nota con la que se abrió, congelada. Es lo que el padre leyó al firmar, y
            // por eso no se recalcula nunca: un papel que en diciembre dijera otro número
            // que la copia de septiembre no probaría nada.
            'nota_al_crear' => (float) $fila->nota_al_crear,

            'asistio' => $notificado && $fila->asistio !== null ? (int) $fila->asistio : null,
            'resultado' => $notificado ? $fila->resultado : null,
            'nota_al_cerrar' => $notificado && $fila->nota_al_cerrar !== null
                ? (float) $fila->nota_al_cerrar : null,
            'observacion' => $notificado ? $fila->observacion : null,
            'veredicto_por' => $notificado && $fila->veredicto_por !== null
                ? (int) $fila->veredicto_por : null,
            'veredicto_por_nombre' => $notificado && $fila->veredicto_por_nombre !== null
                && $fila->veredicto_por_nombre !== '' ? $fila->veredicto_por_nombre : null,
            'veredicto_at' => $notificado ? $fila->veredicto_at : null,

            // D4 es una sugerencia PARA EL DOCENTE, leída de la nivelación. A la familia
            // no se le enseña un resultado que ningún docente ha firmado. §4.4.
            'sugerido' => null,
        ];
    }

    /**
     * Una fecha de la base, escrita como la escribe este proyecto.
     *
     * `Reloj::humana()` y **no `Carbon::parse()`**: lo guardado es hora de pared de
     * Bogotá dentro de un `DATETIME`, y esa cadena **no lleva la zona dentro**, así que
     * cualquier lector que no la diga la interpreta como UTC y la mueve cinco horas
     * devolviendo algo que parece correcto. Aquí ese texto va dentro de la frase que le
     * confirma a una familia a qué hora firmó.
     */
    private function humana(?string $fecha): string
    {
        return Reloj::humana($fecha) ?? (string) $fecha;
    }
}
