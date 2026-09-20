<?php

namespace App\Http\Controllers\Matriculas;

use App\Http\Controllers\Concerns\ResuelveElUsuario;
use App\Http\Controllers\Controller;
use App\Support\Autoriza;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;

/**
 * **El día de matrículas, atendido desde el teléfono.**
 *
 * Quien atiende una estación es *«un docente de pie en un aula, con una fila
 * delante y papeles en la otra mano»*. No tiene computador: tiene el teléfono con
 * el que ya toma asistencia. El contrato y los porqués están en
 * [`46`](../../../../docs/migracion/46-las-estaciones-en-la-app.md); las doce
 * pantallas, en `myvc_flutter/docs/estaciones.md`.
 *
 * ## QUÉ ES UNA ESTACIÓN, que no es una fila de ninguna tabla
 *
 * **Una estación es el conjunto de `requisitos_matricula` que comparten `orden`.**
 * Decidido por Joseth el 20 sep 2026: *«el número impreso en la cartulina es el
 * `orden` que ya existe»* ([44](../../../../docs/migracion/44-el-dia-de-matriculas.md) §4).
 *
 * Por eso `{nro}` **es un `orden`, no un id**, y por eso una estación puede tener
 * varios requisitos —«Documentos» y «Carpeta» pueden ser la estación 2— y
 * `marcar` acepta la lista de cuáles se cierran. Un colegio que no haya numerado
 * sus pasos los tiene todos en la **estación 0**, que es un número válido y es lo
 * que hay hoy en la copia de desarrollo: una fila, `orden = 0`.
 *
 * ## LA COLA SE APOYA EN `cerrado_at`, NO EN `estado`, Y ESO ES LO QUE LA SOSTIENE
 *
 * `requisitos_alumno.estado` es un `varchar(255)` **sin vocabulario cerrado**, y
 * `postAlumno` escribe tal cual lo que venga en el cuerpo — hay tres pantallas
 * viejas escribiendo ahí, desplegadas en los dieciséis colegios. **El desacuerdo no
 * es hipotético: existe hoy.** `AlumnosController:899` inserta `"falta"` en
 * minúscula mientras el defecto de la tabla es `'Falta'` con mayúscula, y medido en
 * la copia de desarrollo los 12 marcas vivas dicen `falta`.
 *
 * Una cola que preguntase `estado = 'Cumple'` **borraría a una persona de la fila
 * de la estación siguiente** el día que una pantalla vieja escribiera otra cosa —y
 * nadie se enteraría, ni ella, que está esperando de pie—. Una que pregunte
 * `cerrado_at IS NOT NULL` es inmune: esa columna la escribe **sólo** `postAlumno`,
 * y comparando con `mb_strtolower`.
 *
 * **Eso no cierra el §2 del 46, y no se finge que sí.** Migrar el vocabulario de
 * los dieciséis colegios sigue pendiente y es un trabajo; lo que esto hace es que
 * **la cola no dependa de él**. Lo que sí entra aquí es la trampa que quedaba: ver
 * `RequisitosController::postAlumno`, donde `cerrado_at` se escribía con `COALESCE`
 * y **reabrir un paso no la limpiaba**.
 *
 * ## LA COLA ES UNA CONSULTA, NO UNA BANDEJA — y esa es la decisión que sostiene todo
 *
 * La cola de la estación 3 **no se llena** con avisos que le manda la 2: se
 * calcula. La diferencia se ve el día que algo falla —*una bandeja con un aviso
 * perdido deja a una familia invisible para siempre y nadie sabe que falta; una
 * consulta con un aviso perdido la deja en la fila igual, sólo que la pantalla
 * tardó en enterarse*—. Por eso no hay nada que marcar como leído: no hay nada que
 * vaciar, hay una pregunta que se vuelve a hacer.
 *
 * ## QUIÉN ESTÁ EN LA COLA DE LA ESTACIÓN N
 *
 *     el paso N ABIERTO   +   el paso ANTERIOR CERRADO ENTERO
 *
 * «Cerrado entero» es **todos** los requisitos de esa estación con `cerrado_at`, no
 * alguno: una estación de dos papeles no está hecha con uno. Y «el anterior» es el
 * mayor `orden` menor que N **que exista este año**, no `N - 1`: un colegio que
 * numere 1, 2, 5 no tiene ninguna cola rota por los huecos.
 *
 * **La primera estación es el caso que no encaja, y se resuelve diciéndolo en vez
 * de inventando.** No tiene anterior, así que la regla de arriba metería en su cola
 * a los mil trescientos alumnos del colegio — que no es una cola, es un censo. Lo
 * que hace este código es: **en la primera estación la cola son los que ya entraron
 * al recorrido y le deben ese paso**, o sea quien tiene algún paso cerrado y ése
 * no. Eso recoge exactamente a quien hay que recoger —**el salteado**, que hizo la
 * 2 sin pasar por la 1, y **el devuelto**, que vuelve desde la 4—.
 *
 * Y quien no ha cerrado nada **no sale en ninguna cola**, que es lo correcto: no ha
 * llegado. A esa persona la atiende la primera estación **escaneando su papel o
 * buscándola por el nombre**, que son las pantallas 03 y 10 y no necesitan cola.
 * *Inventar una marca de «llegó» habría sido una columna que no escribe nadie.*
 *
 * ## EL PERMISO: `auth.personal` EN LA RUTA Y NADA DENTRO, salvo UNA
 *
 * **Decidido por Joseth el 20 sep 2026**: *«cualquiera del personal puede cerrar,
 * pero queda con su nombre y su hora»*. Así que el 403 por estación **no existe** y
 * `rol_id` se descartó con motivo escrito. Lo que protege un paso no es un
 * middleware: es **la firma visible** (`cerrado_por`/`cerrado_at`), **el deshacer**
 * y **el motivo escrito que lee la familia**.
 *
 * La única con candado dentro es `putNotaResuelta`, y el porqué está en
 * `Autoriza::puedeResolverNotaDeEstacion`: una nota pendiente es un aviso que le
 * estorba justo a quien tiene prisa.
 *
 * ## EL AÑO ES EL DE LA SESIÓN, NUNCA EL DE LA URL
 *
 * Los requisitos son por año y quien atiende una estación está trabajando el día de
 * matrículas **de su año**. Aceptarlo por parámetro dejaría cerrar el recorrido de
 * una campaña vieja sin darse cuenta. Es la misma regla que `getRecorrido`.
 */
class EstacionesController extends Controller
{
    use ResuelveElUsuario;

    /**
     * El vocabulario cerrado de un resultado, que es lo único que este módulo
     * escribe en `estado`.
     *
     * **No se le impone al `estado` de la columna**, que sigue aceptando cualquier
     * cosa por las tres pantallas viejas: se le impone a **esta** ruta, que no tiene
     * ningún llamante desplegado y por tanto puede rechazar sin romper a nadie. Lo
     * que llegue fuera de la lista contesta **422 y no se guarda**, que es la mitad
     * del §2 del 46 que sí se puede pagar hoy.
     */
    private const RESULTADOS = [
        'cumple' => 'Cumple',
        'observado' => 'Observado',
        'devuelto' => 'Devuelto',
    ];

    /**
     * Los estados de matrícula que están en el embudo del día.
     *
     * `FORM` **no entra**: es «la familia se llevó el papel», o sea que ni siquiera
     * ha vuelto. Los otros cuatro son gente que el colegio ya cuenta como suya para
     * este año.
     */
    private const EN_EL_EMBUDO = ['MATR', 'ASIS', 'PREM', 'PREA'];

    // ------------------------------------------------------------------
    // Las cinco lecturas
    // ------------------------------------------------------------------

    /**
     * **El recorrido del colegio, y cuántos esperan en cada estación.**
     *
     * Pantalla 01. La app **no cablea ni un nombre ni un número**: un colegio tendrá
     * cinco estaciones y otro tres, uno llamará «Académico» a lo que otro llama
     * «Coordinación». Todo viene de aquí.
     *
     * ## `puedo_atender` viaja SIEMPRE EN `true`, y es a propósito
     *
     * El contrato del 46 lo pedía cuando la estación iba a tener dueño. Joseth
     * decidió que no lo tiene, así que hoy **es verdad para las 74 cuentas de
     * personal**. Se conserva el campo en vez de quitarlo porque **la app es una
     * sola para dieciséis colegios y una versión vieja convive meses**: el día que
     * alguien quiera acotar quién atiende qué, la respuesta cambia de valor y
     * ninguna app se rompe. Quitarlo hoy y devolverlo mañana sí las rompería.
     *
     * ## `mi_estacion` es `null` y NO se deduce de `editable_por_profe_id`
     *
     * Esa columna existe en `requisitos_matricula` y el reflejo es usarla. **No la
     * escribe ni la lee nadie en toda la API** —medido: 0 filas con valor en la copia
     * de desarrollo, y `YearsController` la excluye a propósito al copiar un año—,
     * así que deducir de ahí «mi estación» sería leer una columna que nadie rellena:
     * el renglón saldría vacío en los diecisiete para siempre. Es `profesores.tono`,
     * y este repo lleva seis en un mes.
     *
     * **Quien recuerda la estación es la app**, que es lo que dice su propia
     * pantalla 01 —*«se recuerda: mañana la app abre ahí»*—. El campo se queda en la
     * respuesta por el mismo motivo que `puedo_atender`.
     */
    public function getIndex()
    {
        $user = $this->user;
        $yearId = (int) $user->year_id;

        $estaciones = $this->recorrido($yearId);
        $esperando = $this->cuantosEsperan($yearId, $estaciones);

        $salida = [];

        foreach ($estaciones as $nro => $estacion) {
            $salida[] = [
                'nro' => $nro,
                'nombre' => $estacion['nombre'],
                'descripcion' => $estacion['descripcion'],
                'bloquea' => $estacion['bloquea'],
                'requisitos' => $estacion['requisitos'],
                'esperando' => $esperando[$nro]['n'] ?? 0,
                'puedo_atender' => true,
            ];
        }

        $anio = DB::selectOne('SELECT id, year, actual FROM years WHERE id=? AND deleted_at IS NULL',
            [$yearId]);

        return [
            'campana' => [
                'year_id' => $yearId,
                'year' => $anio->year ?? null,
                // **«Abierta» es que el colegio configuró estaciones**, y no un
                // interruptor nuevo. Si no hay ninguna, la app no enseña la entrada
                // del menú — que es mejor que una pantalla vacía que parece rota.
                'abierta' => count($estaciones) > 0,
            ],
            'estaciones' => $salida,
            'mi_estacion' => null,
        ];
    }

    /**
     * **Los que me llegan.** Pantalla 02.
     *
     * Arriba de la lista van las tres cifras del día —atendidos, esperando y espera
     * media— porque quien atiende necesita saber si el tapón es suyo o de la
     * estación de al lado.
     *
     * ## `notas_total` viaja AQUÍ y no en una llamada aparte
     *
     * El globo de la app tiene que salir sobre cada renglón de la cola. Una pantalla
     * que preguntara «¿y notas?» alumno por alumno **no dibujaría la cola: la
     * dibujaría cuatro segundos después**, en un patio con mala señal.
     */
    public function getCola($nro)
    {
        $user = $this->user;
        $yearId = (int) $user->year_id;
        $nro = $this->numeroDeEstacion($nro);

        $estaciones = $this->recorrido($yearId);

        if (! isset($estaciones[$nro])) {
            abort(404, 'Esa estación no existe en el recorrido de este año.');
        }

        $pasos = $this->pasosPorAlumno($yearId, $estaciones);
        $cola = $this->quienesEsperan($nro, $estaciones, $pasos);

        $personas = $this->datosDeLosAlumnos($yearId, array_keys($cola));
        $notas = $this->notasPorAlumno(array_keys($cola));

        $filas = [];
        $ultimo = null;

        foreach ($cola as $alumnoId => $porque) {
            if (! isset($personas[$alumnoId])) {
                // Tiene marcas del recorrido pero su matrícula de este año no está
                // en el embudo —se dio de baja, o la fila está en `FORM`—. No es un
                // error: es alguien que ya no viene, y no tiene que ocupar sitio en
                // la cola de nadie.
                continue;
            }

            $suyas = $notas[$alumnoId] ?? ['total' => 0, 'pendientes' => 0, 'reservadas' => 0, 'ultimo' => null];

            $filas[] = [
                'alumno_id' => $alumnoId,
                'nombres' => $personas[$alumnoId]->nombres,
                'apellidos' => $personas[$alumnoId]->apellidos,
                'documento' => $personas[$alumnoId]->documento,
                'grupo' => $personas[$alumnoId]->grupo,
                'llego_at' => $porque['llego_at'],
                'devuelto_antes' => $porque['devuelto'],
                'avisos' => $porque['avisos'],
                'notas_total' => $suyas['total'],
                'notas_pendientes' => $suyas['pendientes'],
            ];

            $ultimo = $this->masReciente($ultimo, $porque['llego_at'], $suyas['ultimo']);
        }

        // Por hora de llegada: el que lleva más esperando, primero. Es la única
        // ordenación que no hay que explicarle a nadie en una fila.
        usort($filas, function ($a, $b) {
            return strcmp((string) $a['llego_at'], (string) $b['llego_at'])
                ?: strcmp($a['apellidos'].$a['nombres'], $b['apellidos'].$b['nombres']);
        });

        return [
            'nro' => $nro,
            'nombre' => $estaciones[$nro]['nombre'],
            'al_dia_at' => $ultimo,
            'atendidos_hoy' => $this->atendidosHoy($estaciones[$nro]['requisitos']),
            'cola' => $filas,
        ];
    }

    /**
     * **¿Ha cambiado algo?** — unos 300 bytes, cada veinte segundos, ocho horas.
     *
     * Es el caso de [34](../../../../docs/migracion/34-la-huella-de-sincronizacion.md)
     * otra vez y por el mismo motivo: **ninguna de las lecturas de arriba manda
     * `ETag` ni `Last-Modified`**, así que hoy preguntar barato no se puede. Con diez
     * estaciones abiertas una jornada son unas 14.400 peticiones de 300 bytes; pedir
     * la cola entera cada veinte segundos serían las mismas multiplicadas por cien.
     *
     * ## SON TRES CIFRAS Y EL 34 PEDÍA DOS, y la tercera la encontró un test
     *
     * `ultimo_cambio` **no ve que alguien salió de la cola** —cerrar el paso no
     * borra ninguna fila, y el `MAX` de los que quedan puede no moverse— y el conteo
     * sí. Al revés, el conteo no ve que a alguien le corrigieron la observación. Esas
     * son las dos del 34.
     *
     * **La tercera es `notas`, y tapa un agujero que ninguna de las dos ve:
     * `timestamp` tiene precisión de SEGUNDO.** Una nota escrita en el mismo segundo
     * en que se cerró el paso anterior **no mueve el `MAX`** —los dos sellos son la
     * misma cadena— y tampoco mueve `n`, porque una nota no cambia quién espera. Con
     * dos cifras esa nota sería **invisible hasta el siguiente cambio de la cola**, y
     * si no hay ninguno, invisible para siempre.
     *
     * No es un artefacto del test que lo destapó: en un patio, cerrar un paso y que
     * el de al lado escriba una nota **en el mismo segundo** es media mañana de un
     * sábado. El conteo de notas lo ve porque pasa de N a N+1, que es la misma
     * medicina que el 34 recetó para los borrados: *cuando el reloj no puede, cuenta.*
     *
     * ## Y LA REGLA DURA DEL 34, QUE AQUÍ ES LO QUE HACE APARECER EL GLOBO
     *
     * **Se calcula sobre lo que devuelve la cola, no sobre la tabla.** Y eso, que
     * suena a purismo, es exactamente lo que hace que **una nota escrita en la
     * estación 5 mueva la huella de la estación 2**: la cola de la 2 publica
     * `notas_total` de **todas** las estaciones de esa persona, así que su huella
     * tiene que mirarlas todas.
     *
     * Calculada «por estación anotada» —que es el reflejo— la huella de la 2 **no se
     * movería**, y el globo que el tesorero dejó puesto el lunes saldría cuando
     * alguien recargase a mano: o sea, cuando ya no sirve. *Es una trampa que se ve
     * sola si la huella está bien hecha y no se ve nunca si está mal*, y por eso la
     * fija un test.
     *
     * Entra además `requisitos_matricula.updated_at`: si el colegio añade un paso o
     * cambia un `bloquea`, las colas se recolocan y ninguna fila de
     * `requisitos_alumno` se ha movido.
     */
    public function getHuella()
    {
        $user = $this->user;
        $yearId = (int) $user->year_id;

        $estaciones = $this->recorrido($yearId);
        $esperando = $this->cuantosEsperan($yearId, $estaciones);

        $porEstacion = [];

        foreach ($estaciones as $nro => $estacion) {
            $porEstacion[(string) $nro] = [
                'n' => $esperando[$nro]['n'] ?? 0,
                'notas' => $esperando[$nro]['notas'] ?? 0,
                'ultimo_cambio' => $esperando[$nro]['ultimo'] ?? null,
            ];
        }

        return ['por_estacion' => $porEstacion];
    }

    /**
     * **EL TABLERO DEL DÍA.** Pantalla 15 de `myvc_front/PANTALLAS-MATRICULA.md`.
     *
     * *«Cuántos pasaron por cada estación, dónde está el tapón —34 minutos en la 3
     * con un solo coordinador, mientras la 4 y la 5 están libres—, los que se fueron
     * sin terminar y por qué.»*
     *
     * ## NO DEVUELVE EL INFORME DE CAMPAÑA, Y ESO ES UNA DECISIÓN
     *
     * Esa misma pantalla pide además *«formularios vendidos por caja y por web,
     * recaudado, y cuántos compraron el formulario y no aparecieron»*. **Eso ya
     * existe**: `GET informes/formularios-inscripcion/campana`, entregada el 20 sep
     * ([41](../../../../docs/migracion/41-el-formulario-de-inscripcion.md) §9), y
     * devuelve exactamente esas tres cosas con `sin_volver` dentro.
     *
     * Duplicarlo aquí serían dos consultas que contestan lo mismo, dos sitios que
     * mantener y **dos cifras que algún día se contradirán** — que es el fallo que
     * aquel documento ya deja avisado en su propio cuerpo (`resumen.matriculados`
     * contra `por_estado.MATRICULADA`). La pantalla llama a las dos y las pinta
     * juntas; el tablero es **del día**, no de la campaña.
     *
     * ## «LOS QUE SE FUERON SIN TERMINAR» SE DEVUELVE EN CRUDO, Y NO SE DECIDE AQUÍ
     *
     * El servidor **no sabe quién se fue**: nadie ficha a la salida. Lo que sí sabe
     * es quién entró al recorrido, cuántos pasos le faltan y **desde cuándo no se
     * mueve**, y eso es lo que viaja: `sin_terminar` con su `ultima_marca`.
     *
     * Poner aquí un umbral —«más de 45 minutos parado es que se fue»— sería inventar
     * un dato y publicarlo como medido. Un colegio con una sola estación de
     * orientación tiene esperas de una hora **que son normales**, y otro con cinco
     * ventanillas las tiene de cinco minutos. *Quien puede elegir ese número es quien
     * mira el patio, no esta consulta.*
     *
     * ## EL TAPÓN SE CALCULA CON LA ESPERA, NO CON LA COLA
     *
     * El reflejo es «la estación con más gente esperando». **Es la medida
     * equivocada**: una estación con doce personas que despacha en un minuto va mejor
     * que una con tres que lleva media hora con las mismas tres. Lo que duele en un
     * patio es el tiempo, así que el tapón es **la mayor espera media**, y la cola
     * viaja al lado para que la pantalla pueda enseñar las dos.
     *
     * Y `null` cuando no hay nadie esperando en ninguna: **«no hay tapón» y «no hay
     * datos» no se pueden leer igual**, que es la misma regla que
     * `deriva-del-horario.php` aplica al salir con `2` en vez de con `0`.
     *
     * ## EL PERMISO ES `auth.personal` Y NO SE ESTRECHA, con el motivo
     *
     * Es la pantalla del rector, así que la tentación es pedir un rol. **No enseña
     * nada que quien atiende no vea ya**: los nombres de `sin_terminar` son los
     * mismos que salen en `GET estaciones/{nro}/cola`, que llevan las 75 cuentas de
     * personal desde el 20 sep. Un permiso nuevo aquí no taparía ningún dato; sólo
     * daría la impresión de que sí.
     */
    public function getTablero()
    {
        $user = $this->user;
        $yearId = (int) $user->year_id;
        $ahora = Carbon::now('America/Bogota');

        $estaciones = $this->recorrido($yearId);
        $pasos = $this->pasosPorAlumno($yearId, $estaciones);

        $filas = [];
        $tapon = null;

        foreach ($estaciones as $nro => $estacion) {
            $cola = $this->quienesEsperan($nro, $estaciones, $pasos);

            $esperas = [];

            foreach ($cola as $porque) {
                if ($porque['llego_at'] === null) {
                    // Está en la cola y no se sabe desde cuándo: entró al recorrido
                    // sin que nada quedara sellado. **No cuenta como espera cero**,
                    // que bajaría la media y taparía justo el tapón que se busca.
                    continue;
                }

                $esperas[] = max(0, $ahora->diffInMinutes(Carbon::parse($porque['llego_at']), true));
            }

            $media = count($esperas) > 0 ? (int) round(array_sum($esperas) / count($esperas)) : null;

            $filas[] = [
                'nro' => $nro,
                'nombre' => $estacion['nombre'],
                'bloquea' => $estacion['bloquea'],
                'esperando' => count($cola),
                'atendidos_hoy' => $this->atendidosHoy($estacion['requisitos']),
                'espera_media_min' => $media,
                'espera_maxima_min' => count($esperas) > 0 ? max($esperas) : null,
            ];

            if ($media !== null && ($tapon === null || $media > $tapon['espera_media_min'])) {
                $tapon = ['nro' => $nro, 'nombre' => $estacion['nombre'],
                    'espera_media_min' => $media, 'esperando' => count($cola)];
            }
        }

        return [
            'year_id' => $yearId,
            // La hora del servidor, **y no la del teléfono**: un tablero proyectado en
            // una pared se mira durante horas, y sin este sello no hay forma de saber
            // si lo que se ve es de ahora o de cuando se abrió la pestaña.
            'generado_at' => $ahora->toDateTimeString(),
            'estaciones' => $filas,
            'tapon' => $tapon,
            'salteados' => $this->salteadosDeHoy($yearId, $ahora),
            'sin_terminar' => $this->sinTerminar($yearId, $estaciones, $pasos),
            'totales' => $this->totalesDelDia($estaciones, $pasos),
        ];
    }

    /**
     * **La ficha: los N pasos de esta persona.** Pantallas 04 y 11.
     *
     * ## La MISMA ruta sirve para atender y para mirar, y las distingue `?estacion=`
     *
     * La 04 la abre quien atiende —y necesita saber si puede atenderlo o hay que
     * devolverlo— y la 11 la abre **cualquiera del personal** cuando una mamá
     * pregunta *«¿mi hija en qué va?»*. Es la misma información; lo que cambia es si
     * hay una estación desde la que se pregunta.
     *
     * Sin `?estacion=`, `puede_atenderlo` sale `true` y `si_no` `null`: **nadie está
     * pidiendo atenderlo**, así que contestar «no puede» sería contestar a una
     * pregunta que no se hizo.
     *
     * ## `obligatorio` NO viaja, y eso es una decisión del 20 sep
     *
     * El contrato del 46 lo listaba junto a `bloquea`. Joseth describió **un**
     * interruptor —«obligatoria antes de continuar u opcional»— y el 44 §2 dejó
     * escrito qué se pierde con eso. Publicar los dos campos con el mismo valor
     * invitaría a la app a distinguir dos cosas que aquí son una.
     */
    public function getAlumno($alumno_id)
    {
        if (! is_numeric($alumno_id)) {
            abort(422, 'El alumno no es válido.');
        }

        return $this->ficha((int) $alumno_id, null);
    }

    /**
     * **La misma ficha, por el código del papel.** Pantalla 03, la del QR.
     *
     * Lee **el código del formulario de inscripción** que ya existe
     * ([41](../../../../docs/migracion/41-el-formulario-de-inscripcion.md)), no uno
     * nuevo: acuñar otro sería un segundo papel para la misma familia.
     *
     * ## El 409 que contesta cuando el papel todavía no es de nadie
     *
     * Un formulario del modo `nuevos` nace **sin alumno**, y hasta que secretaría lo
     * ata con `PUT formularios-inscripcion/codigo/{codigo}/alumno` no hay recorrido
     * que enseñar. Contestar 404 diría «ese código no existe», que es falso y manda
     * a quien atiende a buscar un problema que no tiene. Contesta **409 con lo que
     * hay que hacer**, que es lo que la pantalla puede convertir en un botón.
     */
    public function getCodigo($codigo)
    {
        $codigo = trim((string) $codigo);

        if ($codigo === '') {
            abort(422, 'Falta el código.');
        }

        // `codigo_anterior` entra en la búsqueda a propósito: **el papel viejo está
        // en casa de una familia** y quien lo teclee tiene que llegar a su orden.
        $orden = DB::selectOne('SELECT id, codigo, alumno_id, estado, year_campana
            FROM ordenes_inscripcion
            WHERE (codigo=? OR codigo_anterior=?) AND deleted_at IS NULL
            ORDER BY id DESC LIMIT 1', [$codigo, $codigo]);

        if (! $orden) {
            abort(404, 'Ese código no existe.');
        }

        if ($orden->alumno_id === null) {
            abort(409, 'Ese formulario todavía no está atado a ningún alumno. '
                .'Hay que asignárselo antes de empezar el recorrido.');
        }

        return $this->ficha((int) $orden->alumno_id, null);
    }

    // ------------------------------------------------------------------
    // Las cuatro escrituras
    // ------------------------------------------------------------------

    /**
     * **Cerrar el paso.** Pantallas 05, 06 y 07.
     *
     * `cumple` · `observado` · `devuelto`, y **`devuelto` exige motivo escrito**: sin
     * texto contesta 422. No es una validación de cortesía — *«lo que escribas aquí
     * lo lee la familia, tal cual, en su celular»*—, y un desplegable de motivos
     * cerrados haría que el día que el caso no esté en la lista se elija el más
     * parecido y **la familia reciba una mentira**.
     *
     * ## `devuelto` NO cierra, y por eso `cerrado_at` se limpia
     *
     * Un paso devuelto es un paso que **sigue debiéndose**: la persona tiene que
     * volver. Si `devuelto` escribiera `cerrado_at`, la cola de la estación
     * siguiente se la llevaría y la de ésta la perdería — exactamente al revés de lo
     * que hay que hacer.
     *
     * ## La fila se CREA si no existe, y eso no es comodidad
     *
     * `requisitos_alumno` se rellena de forma perezosa: la crea
     * `AlumnosController` cuando alguien abre la matrícula de ese alumno. O sea que
     * **el primero que llegue a una estación puede no tener fila**, y sin crearla el
     * `UPDATE` no escribiría nada y la ruta contestaría que todo fue bien.
     *
     * ## La firma se escribe con `COALESCE` y el `updated_by` no
     *
     * `cerrado_por` es **quién lo chuleó**; `updated_by` es quién tocó la fila por
     * última vez. Corregir una observación después no reescribe la firma, que es
     * justo lo que la trazabilidad del día necesita (44 §5).
     */
    public function putMarcar($nro)
    {
        $user = $this->user;
        $yearId = (int) $user->year_id;
        $nro = $this->numeroDeEstacion($nro);

        $estaciones = $this->recorrido($yearId);

        if (! isset($estaciones[$nro])) {
            abort(404, 'Esa estación no existe en el recorrido de este año.');
        }

        $alumnoId = (int) Request::input('alumno_id');

        if ($alumnoId <= 0) {
            abort(422, 'Falta el alumno.');
        }

        $crudo = mb_strtolower(trim((string) Request::input('resultado')));

        if (! isset(self::RESULTADOS[$crudo])) {
            abort(422, 'El resultado tiene que ser uno de: '
                .implode(', ', array_keys(self::RESULTADOS)).'.');
        }

        $estado = self::RESULTADOS[$crudo];
        $motivo = trim((string) Request::input('motivo', ''));

        if ($crudo === 'devuelto' && $motivo === '') {
            abort(422, 'Para devolver hace falta escribir el motivo: lo lee la familia.');
        }

        $alumno = DB::selectOne('SELECT id FROM alumnos WHERE id=? AND deleted_at IS NULL', [$alumnoId]);

        if (! $alumno) {
            abort(404, 'Ese alumno no existe.');
        }

        $delaEstacion = array_column($estaciones[$nro]['requisitos'], 'id');
        $pedidos = Request::input('requisitos');

        if (is_array($pedidos) && count($pedidos) > 0) {
            $pedidos = array_values(array_intersect(
                array_map('intval', $pedidos), $delaEstacion));

            if (count($pedidos) === 0) {
                abort(422, 'Ninguno de esos requisitos pertenece a esta estación.');
            }
        } else {
            // Sin lista se cierra la estación entera, que es lo que hace la pantalla
            // cuando el paso tiene un solo requisito — o sea, casi siempre.
            $pedidos = $delaEstacion;
        }

        $ahora = Carbon::now('America/Bogota');
        $observacion = Request::has('observacion')
            ? Request::input('observacion')
            : null;

        foreach ($pedidos as $requisitoId) {
            $this->escribirElPaso($alumnoId, (int) $requisitoId, $estado, $crudo,
                $motivo, $observacion, $ahora, (int) $user->user_id);
        }

        return [
            'guardado' => true,
            'estacion' => $nro,
            'resultado' => $estado,
            'cerrado_por' => $this->nombreDe((int) $user->user_id),
            'cerrado_at' => $crudo === 'devuelto' ? null : $ahora->toDateTimeString(),
            // Lo que la pantalla 07 necesita para decir «a qué estación pasa».
            'siguiente' => $this->siguienteDe($nro, $estaciones, $alumnoId, $yearId),
            'recorrido' => $this->ficha($alumnoId, $nro),
        ];
    }

    /**
     * **El que llega salteado: registra el intento y NO escribe el paso.**
     *
     * Pantalla 08. *«No lo atiendas todavía: le falta la 1»* lo dice la pantalla, no
     * el profesor — hoy eso depende de que quien atiende mire bien la hoja, y con
     * fila detrás no mira.
     *
     * ## Que NO escriba el paso es lo que la hace útil, y por eso hay tabla aparte
     *
     * El que llega a la 4 sin haber pasado por la 3 **no deja marca en el paso 4**:
     * se registra el intento. Así el tablero del rector puede decir *«en la 4 se
     * presentan doce sin pasar por la 3 — el cartel está mal puesto, o la 3 está
     * tapada»* **sin ensuciar el recorrido de esa familia** con un paso que no
     * ocurrió.
     *
     * Las dos mitades de esa frase necesitan sitios distintos, y por eso
     * `envios_estacion` existe: meter el intento en `notas_estacion` lo contaría en
     * el globo de la ficha, que es justo lo que esto no debe hacer.
     */
    public function putEnviarA($nro, $destino)
    {
        $user = $this->user;
        $yearId = (int) $user->year_id;
        $nro = $this->numeroDeEstacion($nro);
        $destino = $this->numeroDeEstacion($destino);

        $estaciones = $this->recorrido($yearId);

        foreach ([$nro, $destino] as $cual) {
            if (! isset($estaciones[$cual])) {
                abort(404, 'La estación '.$cual.' no existe en el recorrido de este año.');
            }
        }

        $alumnoId = (int) Request::input('alumno_id');

        if ($alumnoId <= 0) {
            abort(422, 'Falta el alumno.');
        }

        $alumno = DB::selectOne('SELECT id FROM alumnos WHERE id=? AND deleted_at IS NULL', [$alumnoId]);

        if (! $alumno) {
            abort(404, 'Ese alumno no existe.');
        }

        $ahora = Carbon::now('America/Bogota');

        DB::insert('INSERT INTO envios_estacion
            (year_id, alumno_id, desde_orden, hacia_orden, enviado_por, created_at, updated_at)
            VALUES (?,?,?,?,?,?,?)',
            [$yearId, $alumnoId, $nro, $destino, (int) $user->user_id, $ahora, $ahora]);

        return [
            'registrado' => true,
            'desde' => $nro,
            'hacia' => $destino,
            'donde' => $estaciones[$destino]['nombre'],
            // No se escribió ningún paso, y se dice: la pantalla no debe pintar un
            // chulo verde por esto.
            'paso_escrito' => false,
        ];
    }

    /**
     * **Una nota en CUALQUIER estación, la tuya o no.** Pantalla 12.
     *
     * *«Puede ser que se adelante el tesorero a poner una nota antes de empezar el
     * proceso»* — y eso no es un caso raro: **es lo más útil que puede pasar**. El
     * tesorero sabe el lunes que esa familia tiene un saldo pendiente y la estación
     * 5 la ve el sábado; entre esas dos fechas la información existe y no la ve
     * nadie.
     *
     * ## No le avisa a nadie: espera ahí
     *
     * Decidido por Joseth el 20 sep 2026. El globo ya hace el trabajo, y lo ve
     * **cualquiera** que abra la ficha. Un aviso añadiría ruido a un canal que el
     * día de matrículas ya está lleno, y su única ventaja sería adelantar unas horas
     * algo que **nadie puede resolver hasta que la familia llegue**.
     *
     * ## Una nota NO es `motivo_devolucion`, y vive en otra tabla por eso
     *
     * El motivo pertenece al paso, **lo lee la familia** y va en su columna; la nota
     * es entre el personal y la familia no la ve nunca. El día que compartan sitio,
     * un comentario interno acaba en el celular de una mamá.
     */
    public function postNota($nro)
    {
        $user = $this->user;
        $yearId = (int) $user->year_id;
        $nro = $this->numeroDeEstacion($nro);

        $estaciones = $this->recorrido($yearId);

        if (! isset($estaciones[$nro])) {
            abort(404, 'Esa estación no existe en el recorrido de este año.');
        }

        $alumnoId = (int) Request::input('alumno_id');
        $texto = trim((string) Request::input('texto', ''));

        if ($alumnoId <= 0) {
            abort(422, 'Falta el alumno.');
        }

        if ($texto === '') {
            abort(422, 'Una nota sin texto no es una nota.');
        }

        $alumno = DB::selectOne('SELECT id FROM alumnos WHERE id=? AND deleted_at IS NULL', [$alumnoId]);

        if (! $alumno) {
            abort(404, 'Ese alumno no existe.');
        }

        // La nota se cuelga de un requisito concreto y no de la estación, para que la
        // ficha sepa sobre qué círculo pintar el globo. Sin `requisito_id` va al
        // primero de esa estación, que es el caso normal: casi todas tienen uno.
        $delaEstacion = array_column($estaciones[$nro]['requisitos'], 'id');
        $requisitoId = (int) Request::input('requisito_id', 0);

        if ($requisitoId <= 0 || ! in_array($requisitoId, $delaEstacion, true)) {
            $requisitoId = (int) $delaEstacion[0];
        }

        $ahora = Carbon::now('America/Bogota');

        DB::insert('INSERT INTO notas_estacion
            (requisito_id, alumno_id, texto, pendiente, reservada, escrita_por, created_at, updated_at)
            VALUES (?,?,?,?,?,?,?,?)', [
            $requisitoId,
            $alumnoId,
            $texto,
            Request::boolean('pendiente') ? 1 : 0,
            Request::boolean('reservada') ? 1 : 0,
            (int) $user->user_id,
            $ahora,
            $ahora,
        ]);

        return [
            'guardada' => true,
            'id' => (int) DB::getPdo()->lastInsertId(),
            'estacion' => $nro,
            'requisito_id' => $requisitoId,
        ];
    }

    /**
     * **Dar por resuelta una nota pendiente.**
     *
     * ## ESTA RUTA NO ESTABA EN EL CONTRATO, Y SIN ELLA DOS COLUMNAS NACEN MUERTAS
     *
     * El 46 §3.3 lista **ocho** rutas y describe, en su tabla de permisos, quién
     * puede *«dar por resuelta una pendiente»*; `myvc_flutter/docs/estaciones.md`
     * §2.10 describe **el botón** que lo hace, con su texto cuando está apagado. Pero
     * ninguna de las ocho escribe `resuelta_por` ni `resuelta_at`.
     *
     * O sea que el contrato, tal cual, **crea dos columnas que no escribe nadie
     * nunca y un permiso que no gobierna nada** — es `profesores.tono`, que en este
     * repo ya va por la sexta vez en un mes, y esta vez estaba **dentro del propio
     * documento que lo cita como error**.
     *
     * **Así que son nueve y no ocho, y se cuenta y se dice.** Es la §1.4 otra vez: un
     * hueco que el documento no vio, encontrado al construirlo y no al revisarlo.
     *
     * ## Y aquí SÍ hay candado, que es el único de las nueve
     *
     * `Autoriza::puedeResolverNotaDeEstacion` — quien la escribió, o `Admin`,
     * `Secretario` o `Rector`. **Si cualquiera pudiera apagarla, la nota del tesorero
     * la apagaría el primero a quien le estorbe para cerrar su paso**, que es
     * exactamente el escenario contra el que se escribió.
     *
     * Contesta **403 con el motivo dentro**, porque la pantalla lo pinta: *«esta nota
     * la puso Tesorería; puede darla por resuelta quien la escribió, o Secretaría,
     * Rectoría o un administrador»* — es lo que convierte un botón muerto en una
     * instrucción.
     */
    public function putNotaResuelta($id)
    {
        $user = $this->user;

        if (! is_numeric($id)) {
            abort(422, 'La nota no es válida.');
        }

        $nota = DB::selectOne('SELECT n.id, n.escrita_por, n.pendiente, n.resuelta_at
            FROM notas_estacion n
            INNER JOIN requisitos_matricula r ON r.id=n.requisito_id AND r.deleted_at IS NULL
            WHERE n.id=? AND n.deleted_at IS NULL AND r.year_id=?',
            [(int) $id, (int) $user->year_id]);

        if (! $nota) {
            abort(404, 'Esa nota no existe.');
        }

        Autoriza::exigir(
            Autoriza::puedeResolverNotaDeEstacion($user, (int) $nota->escrita_por),
            'Esta nota puede darla por resuelta quien la escribió, o Secretaría, Rectoría '
            .'o un administrador.');

        $ahora = Carbon::now('America/Bogota');

        // **Con `COALESCE`, igual que la firma del paso**: quien la resolvió de verdad
        // es el primero, y una segunda pulsación no le quita el nombre.
        DB::update('UPDATE notas_estacion
            SET resuelta_por=COALESCE(resuelta_por,?), resuelta_at=COALESCE(resuelta_at,?), updated_at=?
            WHERE id=?', [(int) $user->user_id, $ahora, $ahora, (int) $nota->id]);

        $ya = DB::selectOne('SELECT resuelta_por, resuelta_at FROM notas_estacion WHERE id=?',
            [(int) $nota->id]);

        return [
            'resuelta' => true,
            'id' => (int) $nota->id,
            'resuelta_por' => $this->nombreDe((int) $ya->resuelta_por),
            'resuelta_at' => $ya->resuelta_at,
        ];
    }

    // ------------------------------------------------------------------
    // Lo de dentro
    // ------------------------------------------------------------------

    /**
     * Las estaciones del año, **agrupadas por `orden`** y en orden.
     *
     * Devuelve `[nro => ['nombre','descripcion','bloquea','requisitos'=>[…]]]`.
     *
     * El nombre de la estación es el del **primer** requisito de ese grupo, y su
     * `bloquea` es `true` si **alguno** de sus requisitos bloquea: una estación
     * frena si frena cualquiera de los papeles que se entregan en ella.
     */
    private function recorrido(int $yearId): array
    {
        $filas = DB::select('SELECT id, orden, requisito, descripcion, bloquea
            FROM requisitos_matricula
            WHERE year_id=? AND deleted_at IS NULL
            ORDER BY orden, id', [$yearId]);

        $estaciones = [];

        foreach ($filas as $fila) {
            $nro = (int) $fila->orden;

            if (! isset($estaciones[$nro])) {
                $estaciones[$nro] = [
                    'nombre' => $fila->requisito,
                    'descripcion' => $fila->descripcion,
                    'bloquea' => false,
                    'requisitos' => [],
                ];
            }

            $estaciones[$nro]['bloquea'] = $estaciones[$nro]['bloquea'] || (bool) $fila->bloquea;
            $estaciones[$nro]['requisitos'][] = [
                'id' => (int) $fila->id,
                'requisito' => $fila->requisito,
                'descripcion' => $fila->descripcion,
                'bloquea' => (bool) $fila->bloquea,
            ];
        }

        return $estaciones;
    }

    /**
     * El estado de cada paso de cada alumno, en **una sola consulta**.
     *
     * Devuelve `[alumno_id => [nro => ['cerrados','total','ultimo','devuelto']]]`.
     *
     * `total` sale del **catálogo** y no de las filas existentes, y no es lo mismo:
     * `requisitos_alumno` se rellena de forma perezosa, así que un alumno puede
     * tener una fila de las dos de una estación. Contando sobre lo que existe,
     * **cerrar una de dos daría la estación por hecha**.
     */
    private function pasosPorAlumno(int $yearId, array $estaciones): array
    {
        $filas = DB::select('SELECT ra.alumno_id, r.orden,
                ra.cerrado_at, ra.estado, ra.updated_at, ra.updated_by
            FROM requisitos_alumno ra
            INNER JOIN requisitos_matricula r ON r.id=ra.requisito_id AND r.deleted_at IS NULL
            WHERE r.year_id=?', [$yearId]);

        $porAlumno = [];

        foreach ($filas as $fila) {
            $alumno = (int) $fila->alumno_id;
            $nro = (int) $fila->orden;

            if (! isset($porAlumno[$alumno][$nro])) {
                $porAlumno[$alumno][$nro] = [
                    'cerrados' => 0,
                    'total' => count($estaciones[$nro]['requisitos'] ?? []),
                    'ultimo' => null,
                    'devuelto' => false,
                    'tocado' => false,
                ];
            }

            if ($fila->cerrado_at !== null) {
                $porAlumno[$alumno][$nro]['cerrados']++;
                $porAlumno[$alumno][$nro]['ultimo'] = $this->masReciente(
                    $porAlumno[$alumno][$nro]['ultimo'], $fila->cerrado_at);
            }

            if (mb_strtolower(trim((string) $fila->estado)) === 'devuelto') {
                $porAlumno[$alumno][$nro]['devuelto'] = true;
            }

            // **«Alguien del personal ha tocado este paso»**, que es lo que distingue
            // a quien está en el colegio hoy de los mil trescientos que no vinieron.
            //
            // Es `updated_by` y no `cerrado_at`, y la diferencia la encontró un test:
            // con `cerrado_at` **quien reabre un paso desaparece de todas las colas**
            // —no ha cerrado nada— que es exactamente la desaparición silenciosa que
            // este módulo existe para impedir.
            //
            // La fila que crea `AlumnosController:899` al abrir una matrícula deja
            // `updated_by` en NULL, así que abrirle la ficha a alguien **no** lo mete
            // en ninguna cola. Sólo lo mete que una estación escriba en su paso.
            if ($fila->updated_by !== null) {
                $porAlumno[$alumno][$nro]['tocado'] = true;
                $porAlumno[$alumno][$nro]['ultimo'] = $this->masReciente(
                    $porAlumno[$alumno][$nro]['ultimo'], $fila->updated_at);
            }
        }

        return $porAlumno;
    }

    /** ¿Tiene esta persona la estación N cerrada **entera**? */
    /**
     * **Los intentos de hoy de quien llegó salteado**, agrupados por par de estaciones.
     *
     * Es lo que hace útil a `envios_estacion`, que nace vacía y **no la lee nadie más**:
     * *«en la 4 se presentan doce sin pasar por la 3 — el cartel está mal puesto, o la 3
     * está tapada»*. Sin esta lectura, aquella tabla sería una columna de `profesores.tono`
     * con otro nombre.
     *
     * **Sólo los de hoy**, y no los del año: un día de matrículas es una jornada, y un
     * acumulado de la campaña entera enterraría el cartel mal puesto de esta mañana bajo
     * los tres sábados anteriores.
     */
    private function salteadosDeHoy(int $yearId, Carbon $ahora): array
    {
        $filas = DB::select('SELECT desde_orden, hacia_orden, COUNT(*) AS n
            FROM envios_estacion
            WHERE year_id=? AND created_at >= ?
            GROUP BY desde_orden, hacia_orden
            ORDER BY n DESC, desde_orden, hacia_orden',
            [$yearId, $ahora->copy()->startOfDay()]);

        return array_map(fn ($fila) => [
            'desde' => (int) $fila->desde_orden,
            'hacia' => (int) $fila->hacia_orden,
            'n' => (int) $fila->n,
        ], $filas);
    }

    /**
     * **Quién entró al recorrido y no lo ha terminado**, con desde cuándo no se mueve.
     *
     * Ver el docblock de `getTablero`: aquí **no se decide quién se fue**. Se devuelve el
     * hecho —entró, le faltan N, su última marca es de tal hora— y el umbral lo pone quien
     * mira el patio.
     *
     * **El tope es 200 y se dice cuando corta** (`sin_terminar_recortada`), que es la misma
     * regla que el informe de campaña aplica a `sin_volver`: una lista truncada en silencio
     * se lee como una lista completa, y en un tablero eso son familias que nadie va a
     * buscar.
     */
    private function sinTerminar(int $yearId, array $estaciones, array $pasos): array
    {
        if (count($estaciones) === 0) {
            return ['total' => 0, 'recortada' => false, 'personas' => []];
        }

        $pendientes = [];

        foreach ($pasos as $alumnoId => $suyos) {
            $faltan = 0;
            $entro = false;

            foreach ($estaciones as $nro => $_) {
                if ($this->cerrada($suyos, $nro, $estaciones)) {
                    $entro = true;

                    continue;
                }

                // `tocado` —o sea `updated_by` no nulo— es lo que distingue «pasó por
                // aquí» de «la fila la creó `AlumnosController` al matricularlo». Es el
                // mismo marcador que usa la cola de la primera estación, y por el mismo
                // motivo: sobrevive a reabrir un paso.
                $entro = $entro || (bool) ($suyos[$nro]['tocado'] ?? false);
                $faltan++;
            }

            if (! $entro || $faltan === 0) {
                continue;
            }

            $pendientes[$alumnoId] = ['faltan' => $faltan, 'ultima' => $this->ultimoDeTodos($suyos)];
        }

        $total = count($pendientes);
        $ids = array_slice(array_keys($pendientes), 0, 200);
        $personas = $this->datosDeLosAlumnos($yearId, $ids);

        $filas = [];

        foreach ($ids as $alumnoId) {
            if (! isset($personas[$alumnoId])) {
                // Tiene marcas del recorrido y su matrícula de este año no está en el
                // embudo. No es un error: es alguien que ya no viene, y no puede contar
                // como «se fue sin terminar» — nunca empezó este año.
                $total--;

                continue;
            }

            $filas[] = [
                'alumno_id' => $alumnoId,
                'nombres' => $personas[$alumnoId]->nombres,
                'apellidos' => $personas[$alumnoId]->apellidos,
                'grupo' => $personas[$alumnoId]->grupo,
                'faltan' => $pendientes[$alumnoId]['faltan'],
                'ultima_marca' => $pendientes[$alumnoId]['ultima'],
            ];
        }

        usort($filas, fn ($a, $b) => strcmp((string) $a['ultima_marca'], (string) $b['ultima_marca']));

        return [
            'total' => $total,
            'recortada' => $total > count($filas),
            'personas' => $filas,
        ];
    }

    /**
     * Las tres cifras de cabecera: cuántos están dentro del recorrido, cuántos lo
     * terminaron y cuántos no lo han empezado.
     *
     * **`sin_empezar` sale de los que tienen fila y no la han tocado**, no del censo de
     * matriculados del año: esta consulta no puede decir cuántas familias faltan por
     * venir, porque nadie le dice al sistema a quién espera el colegio hoy. *Lo que se
     * cuenta es lo que se sabe.*
     */
    private function totalesDelDia(array $estaciones, array $pasos): array
    {
        $dentro = 0;
        $completos = 0;
        $sinEmpezar = 0;

        foreach ($pasos as $suyos) {
            $cerradas = 0;
            $tocado = false;

            foreach ($estaciones as $nro => $_) {
                if ($this->cerrada($suyos, $nro, $estaciones)) {
                    $cerradas++;
                }

                $tocado = $tocado || (bool) ($suyos[$nro]['tocado'] ?? false);
            }

            if ($cerradas === count($estaciones) && count($estaciones) > 0) {
                $completos++;
            } elseif ($tocado || $cerradas > 0) {
                $dentro++;
            } else {
                $sinEmpezar++;
            }
        }

        return ['en_el_recorrido' => $dentro, 'completos' => $completos, 'sin_empezar' => $sinEmpezar];
    }

    private function cerrada(array $pasosDelAlumno, int $nro, array $estaciones): bool
    {
        $cuantos = count($estaciones[$nro]['requisitos'] ?? []);

        if ($cuantos === 0) {
            return false;
        }

        return ($pasosDelAlumno[$nro]['cerrados'] ?? 0) >= $cuantos;
    }

    /**
     * Quiénes esperan en la estación N. Ver el docblock de la clase para la regla y
     * para por qué la primera estación es distinta.
     *
     * Devuelve `[alumno_id => ['llego_at','devuelto','avisos']]`.
     */
    private function quienesEsperan(int $nro, array $estaciones, array $pasos): array
    {
        $numeros = array_keys($estaciones);
        $anterior = null;

        foreach ($numeros as $candidato) {
            if ($candidato < $nro) {
                $anterior = $candidato;
            }
        }

        $cola = [];

        foreach ($pasos as $alumnoId => $suyos) {
            if ($this->cerrada($suyos, $nro, $estaciones)) {
                continue;                       // ya pasó por aquí
            }

            $avisos = [];

            if ($anterior === null) {
                // La primera: los que ya entraron al recorrido y le deben este paso.
                $entro = false;
                $masTarde = false;

                foreach ($numeros as $otro) {
                    if (($suyos[$otro]['tocado'] ?? false)
                        || $this->cerrada($suyos, $otro, $estaciones)) {
                        $entro = true;
                    }

                    if ($otro > $nro && $this->cerrada($suyos, $otro, $estaciones)) {
                        $masTarde = true;
                    }
                }

                if (! $entro) {
                    continue;                   // no ha llegado
                }

                if ($masTarde) {
                    $avisos[] = ['tipo' => 'salteado',
                        'texto' => 'Cerró una estación posterior sin pasar por ésta.'];
                }
            } elseif (! $this->cerrada($suyos, $anterior, $estaciones)) {
                continue;                       // todavía le toca la anterior
            }

            $devuelto = (bool) ($suyos[$nro]['devuelto'] ?? false);

            if ($devuelto) {
                $avisos[] = ['tipo' => 'devuelto',
                    'texto' => 'Ya estuvo aquí y se le devolvió.'];
            }

            $cola[$alumnoId] = [
                // Cuándo entró en esta cola: cuándo cerró la anterior. En la primera
                // estación no hay anterior, así que es lo último que se movió en su
                // recorrido — que es lo que la pantalla necesita para ordenar.
                'llego_at' => $anterior !== null
                    ? ($suyos[$anterior]['ultimo'] ?? null)
                    : $this->ultimoDeTodos($suyos),
                'devuelto' => $devuelto,
                'avisos' => $avisos,
            ];
        }

        return $cola;
    }

    /**
     * Cuántos esperan en cada estación **y cuándo se movió por última vez lo que esa
     * cola devuelve**. Es lo que alimenta la huella y el `esperando` del índice.
     */
    private function cuantosEsperan(int $yearId, array $estaciones): array
    {
        $pasos = $this->pasosPorAlumno($yearId, $estaciones);

        // El catálogo también mueve la huella: añadir un paso recoloca las colas sin
        // que ninguna fila de `requisitos_alumno` se haya tocado.
        $catalogo = DB::selectOne('SELECT MAX(updated_at) AS ultimo
            FROM requisitos_matricula WHERE year_id=? AND deleted_at IS NULL', [$yearId]);

        $alumnos = array_keys($pasos);
        $notas = $this->notasPorAlumno($alumnos);

        $salida = [];

        foreach ($estaciones as $nro => $_) {
            $cola = $this->quienesEsperan($nro, $estaciones, $pasos);
            $ultimo = $catalogo->ultimo ?? null;
            $cuantasNotas = 0;

            foreach ($cola as $alumnoId => $porque) {
                // **La regla dura del 34**: sobre lo que devuelve la cola, no sobre la
                // tabla. La cola publica `notas_total` de TODAS las estaciones de esa
                // persona, así que una nota en la 5 tiene que mover la huella de la 2.
                $ultimo = $this->masReciente(
                    $ultimo, $porque['llego_at'], $notas[$alumnoId]['ultimo'] ?? null);
                $cuantasNotas += $notas[$alumnoId]['total'] ?? 0;
            }

            $salida[$nro] = [
                'n' => count($cola),
                'notas' => $cuantasNotas,
                'ultimo' => $ultimo,
            ];
        }

        return $salida;
    }

    /** Las notas de cada persona, contadas para el globo. */
    private function notasPorAlumno(array $alumnos): array
    {
        if (count($alumnos) === 0) {
            return [];
        }

        $huecos = implode(',', array_fill(0, count($alumnos), '?'));

        $filas = DB::select('SELECT alumno_id,
                COUNT(*) AS total,
                SUM(CASE WHEN pendiente=1 AND resuelta_at IS NULL THEN 1 ELSE 0 END) AS pendientes,
                SUM(CASE WHEN reservada=1 THEN 1 ELSE 0 END) AS reservadas,
                MAX(updated_at) AS ultimo
            FROM notas_estacion
            WHERE alumno_id IN ('.$huecos.') AND deleted_at IS NULL
            GROUP BY alumno_id', array_map('intval', $alumnos));

        $salida = [];

        foreach ($filas as $fila) {
            $salida[(int) $fila->alumno_id] = [
                'total' => (int) $fila->total,
                'pendientes' => (int) $fila->pendientes,
                'reservadas' => (int) $fila->reservadas,
                'ultimo' => $fila->ultimo,
            ];
        }

        return $salida;
    }

    /**
     * Nombre, apellido, documento y grupo de los de la cola.
     *
     * El grupo va por subconsulta y no por `JOIN` **para que un alumno con dos
     * matrículas en el mismo año no salga dos veces en la fila**, que es la clase de
     * duplicado que en una cola se lee como dos personas.
     *
     * ## EL AÑO DE UNA MATRÍCULA NO ESTÁ EN `matriculas`, y el reflejo es escribirlo
     *
     * `matriculas` **no tiene `year_id`**: el año viaja por `grupos.year_id`, o sea
     * a través del grupo. Se comprobó contra el esquema después de escribir
     * `m.year_id=?` y ver el `Unknown column`, que es la forma barata de descubrirlo;
     * la cara es que la columna existiera en otra tabla y filtrara por otra cosa.
     * Es como ya lo hace `RequisitosController::putListadoObservaciones`.
     */
    private function datosDeLosAlumnos(int $yearId, array $alumnos): array
    {
        if (count($alumnos) === 0) {
            return [];
        }

        $huecos = implode(',', array_fill(0, count($alumnos), '?'));
        $estados = implode(',', array_fill(0, count(self::EN_EL_EMBUDO), '?'));

        $filas = DB::select('SELECT a.id, a.nombres, a.apellidos, a.documento,
                (SELECT g.abrev FROM matriculas m
                   INNER JOIN grupos g ON g.id=m.grupo_id AND g.deleted_at IS NULL AND g.year_id=?
                  WHERE m.alumno_id=a.id AND m.deleted_at IS NULL
                    AND m.estado IN ('.$estados.')
                  ORDER BY m.id DESC LIMIT 1) AS grupo
            FROM alumnos a
            WHERE a.id IN ('.$huecos.') AND a.deleted_at IS NULL
              AND EXISTS (SELECT 1 FROM matriculas m2
                           INNER JOIN grupos g2 ON g2.id=m2.grupo_id AND g2.deleted_at IS NULL
                             AND g2.year_id=?
                           WHERE m2.alumno_id=a.id AND m2.deleted_at IS NULL
                             AND m2.estado IN ('.$estados.'))',
            array_merge([$yearId], self::EN_EL_EMBUDO, array_map('intval', $alumnos),
                [$yearId], self::EN_EL_EMBUDO));

        $salida = [];

        foreach ($filas as $fila) {
            $salida[(int) $fila->id] = $fila;
        }

        return $salida;
    }

    /**
     * La ficha entera de una persona. La comparten `getAlumno`, `getCodigo` y la
     * respuesta de `putMarcar`, para que las tres digan exactamente lo mismo.
     */
    private function ficha(int $alumnoId, ?int $desde)
    {
        $user = $this->user;
        $yearId = (int) $user->year_id;

        $alumno = DB::selectOne('SELECT id, nombres, apellidos, documento
            FROM alumnos WHERE id=? AND deleted_at IS NULL', [$alumnoId]);

        if (! $alumno) {
            abort(404, 'Ese alumno no existe.');
        }

        if ($desde === null && Request::has('estacion')) {
            $desde = $this->numeroDeEstacion(Request::input('estacion'));
        }

        $estaciones = $this->recorrido($yearId);

        $marcas = DB::select('SELECT ra.requisito_id, ra.estado, ra.descripcion,
                ra.motivo_devolucion, ra.cerrado_por, ra.cerrado_at,
                p.nombres AS firma_nombres, p.apellidos AS firma_apellidos
            FROM requisitos_alumno ra
            INNER JOIN requisitos_matricula r ON r.id=ra.requisito_id AND r.deleted_at IS NULL
            LEFT JOIN users u ON u.id=ra.cerrado_por AND u.deleted_at IS NULL
            -- `profesores.user_id`, NO `users.profesor_id`: las dos columnas existen
            -- y la segunda está VACÍA (0 filas frente a 47). Escrito al revés, el
            -- renglón «cerrado por» saldría en blanco en los diecisiete sin que nada
            -- fallara. Es la misma comprobación que dejó escrita `getRecorrido`.
            LEFT JOIN profesores p ON p.user_id=u.id AND p.deleted_at IS NULL
            WHERE ra.alumno_id=? AND r.year_id=?', [$alumnoId, $yearId]);

        $porRequisito = [];

        foreach ($marcas as $marca) {
            $porRequisito[(int) $marca->requisito_id] = $marca;
        }

        $notas = $this->notasDeLaFicha($alumnoId, $yearId);

        $pasos = [];
        $devolverA = null;

        foreach ($estaciones as $nro => $estacion) {
            $cerrados = 0;
            $devuelto = false;
            $motivo = null;
            $firma = null;
            $firmaAt = null;
            $requisitos = [];
            $cuenta = ['total' => 0, 'pendientes' => 0, 'reservadas' => 0];
            $detalle = [];

            foreach ($estacion['requisitos'] as $requisito) {
                $marca = $porRequisito[$requisito['id']] ?? null;

                if ($marca && $marca->cerrado_at !== null) {
                    $cerrados++;
                    $firma = $firma ?? trim(($marca->firma_nombres ?? '').' '.($marca->firma_apellidos ?? ''));
                    $firmaAt = $this->masReciente($firmaAt, $marca->cerrado_at);
                }

                if ($marca && mb_strtolower(trim((string) $marca->estado)) === 'devuelto') {
                    $devuelto = true;
                    $motivo = $motivo ?? $marca->motivo_devolucion;
                }

                foreach (($notas[$requisito['id']] ?? []) as $nota) {
                    $cuenta['total']++;
                    $cuenta['pendientes'] += ($nota['pendiente'] && ! $nota['resuelta']) ? 1 : 0;
                    $cuenta['reservadas'] += $nota['reservada'] ? 1 : 0;
                    $detalle[] = $nota;
                }

                $requisitos[] = [
                    'id' => $requisito['id'],
                    'requisito' => $requisito['requisito'],
                    'bloquea' => $requisito['bloquea'],
                    'estado' => $marca->estado ?? null,
                    'cerrado_at' => $marca->cerrado_at ?? null,
                    'observacion' => $marca->descripcion ?? null,
                ];
            }

            $cerrada = count($estacion['requisitos']) > 0
                && $cerrados >= count($estacion['requisitos']);

            // El primero que bloquea y no está cerrado es a donde hay que devolverla:
            // ir al último sería mandarla al final de un recorrido que no ha hecho.
            if (! $cerrada && $estacion['bloquea'] && $devolverA === null
                && ($desde === null || $nro < $desde)) {
                $devolverA = ['devolver_a_nro' => $nro, 'donde' => $estacion['nombre']];
            }

            $pasos[] = [
                'nro' => $nro,
                'nombre' => $estacion['nombre'],
                'estado' => $devuelto ? 'Devuelto' : ($cerrada ? 'Cumple' : 'Falta'),
                'cerrada' => $cerrada,
                'cerrado_por' => ($firma === '' ? null : $firma),
                'cerrado_at' => $firmaAt,
                'motivo' => $motivo,
                'bloquea' => $estacion['bloquea'],
                'mio' => $desde !== null && $nro === $desde,
                'requisitos' => $requisitos,
                'notas' => $cuenta,
                'notas_detalle' => $detalle,
            ];
        }

        return [
            'persona' => $alumno,
            'acudiente' => $this->acudienteDe($alumnoId),
            'codigo' => $this->codigoDe($alumnoId),
            'estacion' => $desde,
            'pasos' => $pasos,
            // Sin `?estacion=` nadie está pidiendo atenderlo, así que contestar «no
            // puede» sería contestar a una pregunta que no se hizo.
            'puede_atenderlo' => $desde === null ? true : $devolverA === null,
            'si_no' => $desde === null ? null : $devolverA,
        ];
    }

    /**
     * Las notas de esta persona, agrupadas por requisito y **con el texto recortado
     * cuando es reservada**.
     *
     * ## Que el número incluya las reservadas es una decisión, no un descuido
     *
     * *«Si hay algo que tesorería deba mirar, le llega la señal sin el texto»*
     * (`PANTALLAS-MATRICULA.md`, pantalla 11). **Esconder que una nota existe es peor
     * que esconder su contenido**: quien ve el globo y no puede abrirlo sabe a quién
     * preguntarle; quien no ve nada, no pregunta.
     *
     * ## Quién SÍ lee el texto de una reservada — y esto lo decidió el código, no el 46
     *
     * El 46 §3.3 dice «cualquiera del personal, **salvo las reservadas**» y no dice
     * quién sí. Leído al pie de la letra no lo leería **ni quien la escribió**, que
     * no puede ser lo que se quería. Lo que entra es el conjunto más pequeño que hace
     * la regla coherente: **quien la escribió y quien puede darla por resuelta** —o
     * sea el mismo `Autoriza::puedeResolverNotaDeEstacion`—. Queda declarado como
     * decisión abierta en el 46 §5 en vez de resuelto en silencio.
     */
    private function notasDeLaFicha(int $alumnoId, int $yearId): array
    {
        $filas = DB::select('SELECT n.id, n.requisito_id, n.texto, n.pendiente, n.reservada,
                n.escrita_por, n.resuelta_por, n.resuelta_at, n.created_at,
                p.nombres AS de_nombres, p.apellidos AS de_apellidos
            FROM notas_estacion n
            INNER JOIN requisitos_matricula r ON r.id=n.requisito_id AND r.deleted_at IS NULL
            LEFT JOIN users u ON u.id=n.escrita_por AND u.deleted_at IS NULL
            LEFT JOIN profesores p ON p.user_id=u.id AND p.deleted_at IS NULL
            WHERE n.alumno_id=? AND r.year_id=? AND n.deleted_at IS NULL
            ORDER BY n.created_at, n.id', [$alumnoId, $yearId]);

        $salida = [];

        foreach ($filas as $fila) {
            $reservada = (bool) $fila->reservada;
            $puede = Autoriza::puedeResolverNotaDeEstacion($this->user, (int) $fila->escrita_por);

            $salida[(int) $fila->requisito_id][] = [
                'id' => (int) $fila->id,
                'texto' => ($reservada && ! $puede) ? null : $fila->texto,
                'reservada' => $reservada,
                'pendiente' => (bool) $fila->pendiente,
                'resuelta' => $fila->resuelta_at !== null,
                'de' => trim(($fila->de_nombres ?? '').' '.($fila->de_apellidos ?? '')) ?: null,
                'cuando' => $fila->created_at,
                // Lo que enciende o apaga el botón de la pantalla, calculado aquí y no
                // en la app: **la app es una sola para dieciséis colegios** y el rol
                // de quien mira no lo sabe.
                'puedo_resolverla' => $puede,
            ];
        }

        return $salida;
    }

    /**
     * El acudiente, para poder llamar a alguien desde la fila.
     *
     * La tabla es **`parentescos`** —no hay ninguna `acudiente_alumno`, aunque el
     * nombre lo sugiera— y el orden es `is_acudiente DESC`, que es el mismo que usa
     * `AcudientesController`: un alumno tiene varios parientes registrados y **sólo
     * uno es el acudiente**. Ordenando por id se devolvería al primero que alguien
     * tecleó, que puede ser un tío.
     */
    private function acudienteDe(int $alumnoId)
    {
        return DB::selectOne('SELECT ac.id, ac.nombres, ac.apellidos, ac.celular, ac.telefono
            FROM parentescos pa
            INNER JOIN acudientes ac ON ac.id=pa.acudiente_id AND ac.deleted_at IS NULL
            WHERE pa.alumno_id=? AND pa.deleted_at IS NULL
            ORDER BY ac.is_acudiente DESC, ac.id LIMIT 1', [$alumnoId]);
    }

    /** El código del formulario de este alumno, si tiene uno. */
    private function codigoDe(int $alumnoId)
    {
        $orden = DB::selectOne('SELECT codigo FROM ordenes_inscripcion
            WHERE alumno_id=? AND deleted_at IS NULL ORDER BY id DESC LIMIT 1', [$alumnoId]);

        return $orden->codigo ?? null;
    }

    /** Cuántos se han cerrado hoy en esta estación. La cifra de la cabecera. */
    private function atendidosHoy(array $requisitos): int
    {
        if (count($requisitos) === 0) {
            return 0;
        }

        $ids = array_column($requisitos, 'id');
        $huecos = implode(',', array_fill(0, count($ids), '?'));

        $fila = DB::selectOne('SELECT COUNT(DISTINCT alumno_id) AS n
            FROM requisitos_alumno
            WHERE requisito_id IN ('.$huecos.') AND cerrado_at >= ?',
            array_merge($ids, [Carbon::now('America/Bogota')->startOfDay()]));

        return (int) ($fila->n ?? 0);
    }

    /** A qué estación pasa después de cerrar ésta. Lo que pinta la pantalla 07. */
    private function siguienteDe(int $nro, array $estaciones, int $alumnoId, int $yearId)
    {
        $pasos = $this->pasosPorAlumno($yearId, $estaciones);
        $suyos = $pasos[$alumnoId] ?? [];

        foreach ($estaciones as $candidato => $estacion) {
            if ($candidato > $nro && ! $this->cerrada($suyos, $candidato, $estaciones)) {
                return ['nro' => $candidato, 'donde' => $estacion['nombre']];
            }
        }

        return null;
    }

    /**
     * Escribe un paso. Crea la fila si no existe — ver el docblock de `putMarcar`.
     */
    private function escribirElPaso(int $alumnoId, int $requisitoId, string $estado,
        string $crudo, string $motivo, $observacion, Carbon $ahora, int $quien): void
    {
        $fila = DB::selectOne('SELECT id FROM requisitos_alumno
            WHERE alumno_id=? AND requisito_id=? ORDER BY id LIMIT 1',
            [$alumnoId, $requisitoId]);

        if (! $fila) {
            // **Sin nombrar `estado`**: la columna es `NOT NULL DEFAULT 'Falta'`, y dejar
            // que la base ponga su defecto es lo correcto — el `UPDATE` de dos líneas más
            // abajo escribe el resultado de verdad. Nombrarla aquí obligaría a elegir un
            // valor intermedio, y el que se eligió primero fue `Cumple`: una fila que
            // existe un instante diciendo lo contrario de lo que va a decir.
            DB::insert('INSERT INTO requisitos_alumno
                (alumno_id, requisito_id, created_at, updated_at)
                VALUES (?,?,?,?)',
                [$alumnoId, $requisitoId, $ahora, $ahora]);

            $fila = DB::selectOne('SELECT id FROM requisitos_alumno WHERE id=?',
                [DB::getPdo()->lastInsertId()]);
        }

        $sets = ['estado=?'];
        $valores = [$estado];

        if ($observacion !== null) {
            $sets[] = 'descripcion=?';
            $valores[] = $observacion;
        }

        if ($crudo === 'devuelto') {
            // Devolver **reabre** el paso: la persona tiene que volver, así que la
            // cola de esta estación tiene que seguir viéndola y la de la siguiente no.
            $sets[] = 'motivo_devolucion=?';
            $valores[] = $motivo;
            $sets[] = 'cerrado_por=NULL';
            $sets[] = 'cerrado_at=NULL';
        } else {
            // Un paso que se cierra bien deja de tener motivo de devolución: el que
            // había describe una vuelta anterior y la familia no debe volver a leerlo.
            $sets[] = 'motivo_devolucion=NULL';
            $sets[] = 'cerrado_por=COALESCE(cerrado_por,?)';
            $valores[] = $quien;
            $sets[] = 'cerrado_at=COALESCE(cerrado_at,?)';
            $valores[] = $ahora;
        }

        $sets[] = 'updated_by=?';
        $valores[] = $quien;
        $sets[] = 'updated_at=?';
        $valores[] = $ahora;
        $valores[] = (int) $fila->id;

        DB::update('UPDATE requisitos_alumno SET '.implode(', ', $sets).' WHERE id=?', $valores);
    }

    /** El nombre de una cuenta del personal, por `profesores.user_id`. */
    private function nombreDe(int $userId): ?string
    {
        if ($userId <= 0) {
            return null;
        }

        $fila = DB::selectOne('SELECT p.nombres, p.apellidos
            FROM users u
            LEFT JOIN profesores p ON p.user_id=u.id AND p.deleted_at IS NULL
            WHERE u.id=? AND u.deleted_at IS NULL', [$userId]);

        if (! $fila) {
            return null;
        }

        // **De las 22 cuentas de tipo `Usuario` —los administrativos— NINGUNA tiene
        // ficha en `profesores`** (medido el 20 sep 2026). O sea que secretaría, que
        // es quien más usa esto, saldría sin nombre. Se cae al `username`, que existe
        // siempre, en vez de devolver `null`.
        $nombre = trim(($fila->nombres ?? '').' '.($fila->apellidos ?? ''));

        if ($nombre !== '') {
            return $nombre;
        }

        $cuenta = DB::selectOne('SELECT username FROM users WHERE id=?', [$userId]);

        return $cuenta->username ?? null;
    }

    /** `{nro}` es un `orden`, y **0 es válido**. */
    private function numeroDeEstacion($nro): int
    {
        if (! is_numeric($nro) || (int) $nro < 0) {
            abort(422, 'El número de estación no es válido.');
        }

        return (int) $nro;
    }

    private function ultimoDeTodos(array $pasosDelAlumno): ?string
    {
        $ultimo = null;

        foreach ($pasosDelAlumno as $paso) {
            $ultimo = $this->masReciente($ultimo, $paso['ultimo'] ?? null);
        }

        return $ultimo;
    }

    /**
     * El mayor de varias marcas de tiempo, ignorando las que faltan.
     *
     * Compara **como cadena** porque todas vienen de MySQL en `Y-m-d H:i:s`, que es
     * un formato en el que el orden lexicográfico y el cronológico coinciden.
     */
    private function masReciente(...$marcas): ?string
    {
        $mayor = null;

        foreach ($marcas as $marca) {
            if ($marca === null || $marca === '') {
                continue;
            }

            $marca = (string) $marca;

            if ($mayor === null || strcmp($marca, $mayor) > 0) {
                $mayor = $marca;
            }
        }

        return $mayor;
    }
}
