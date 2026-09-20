<?php

namespace App\Http\Controllers\Perfiles;

use App\Http\Controllers\Controller;
use App\Support\HtmlDelEditor;
use App\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;

/**
 * El calendario del colegio.
 *
 * **Sus cuatro rechazos respondían `404, 'No tienes permiso'`**, o sea un código
 * y un mensaje que dicen cosas distintas: el cuerpo habla de permisos y el
 * código dice que la ruta o la fila no existen. En un API donde 404 significa
 * «esa fila no está» en todas partes —y donde se acaba de gastar una serie
 * entera en que lo signifique—, esto es la contraria de un 200 que miente.
 *
 * Pasan a 403. El front no mira el código en ninguna de las cuatro: pinta el
 * mensaje del cuerpo con `toastr.error`. Ver 05 §54.
 */
class CalendarioController extends Controller
{
    /**
     * **Las columnas de `calendario` que salen por las respuestas viejas, y NO
     * es una limpieza: es lo que mantiene esas respuestas EXACTAMENTE iguales.**
     *
     * Con `SELECT *`, las tres columnas que esta épica añadió a la tabla
     * —`descripcion`, `recordatorio_minutos`, `recordatorio_enviado_at`— se
     * colaban solas en **siete** respuestas ya desplegadas: las dos de
     * `putThisYear()` y **cinco de `ChangeAskedController`**, que también hace
     * `SELECT * FROM calendario`. Las leen la aplicación vieja y `myvc_flutter`
     * en los quince colegios.
     *
     * **Se vio porque movió dos instantáneas —`muestreo-calendario-this-year` y
     * `muestreo-ChangesAsked-to-me`—, no porque nadie lo pensara.** La épica se
     * encargó explícitamente de «no tocar `this-year`», y **no tocarla era
     * justamente lo que la cambiaba**: añadir una columna a la tabla es tocar
     * todas las respuestas que la leen con `*`.
     *
     * Es exactamente lo que se pagó el 24 ago 2026 en los cuatro `SELECT *`
     * sobre `matriculas`, y por el mismo motivo: **la regla no caduca con estas
     * tres columnas**. La próxima que se añada a `calendario` entra por `*`
     * igual de callada, así que esta lista se amplía **a sabiendas** o no se
     * amplía. Y por eso vive en un solo sitio: con la lista copiada siete veces,
     * la siguiente columna entraría por las seis que alguien olvidara.
     *
     * El orden es el que tenía la tabla **antes** de la migración, que es el que
     * devolvía `SELECT *`: `descripcion` nació con `after('title')`, y con `*`
     * habría cambiado además el orden de las claves del JSON.
     */
    public const COLUMNAS = 'id, created_by, created_by_nombres, title, allDay, start, end, type,
                             cumple_alumno_id, cumple_profe_id, solo_profes, url,
                             deleted_by, updated_by, deleted_at, created_at, updated_at';

    /**
     * **Quién ve los eventos internos lo decide el token, no el cuerpo.** Ver 05 §150.
     *
     * `calendario.solo_profes` es el interruptor con el que el colegio marca un
     * evento como interno, y hasta hoy el booleano que decidía si se aplicaba
     * llegaba **en el cuerpo de la petición** (`is_prof_admin`). La columna
     * funcionaba: sin la bandera, un alumno veía exactamente los públicos. Lo que
     * fallaba era de dónde salía el dato. Medido con token de alumno mandando
     * `is_prof_admin=true`: recibía los eventos `solo_profes = 1`.
     *
     * **El criterio es el que ya usan las otras cuatro rutas de este mismo
     * controlador**: `($user->tipo == 'Profesor') || $user->is_superuser`. No es
     * uno nuevo, que es lo que evita acabar con cuatro criterios para el mismo
     * módulo.
     *
     * El candidato alternativo era el de `ExigirPersonal` —«no es alumno ni
     * acudiente»— y **se descartó midiendo, no razonando**. El front manda
     * `IS_PROF_ADMIN = hasRoleOrPerm(['admin', 'profesor'])`, o sea un criterio de
     * **rol**, así que la pregunta era si hay personal con rol `Admin` y sin
     * `is_superuser`, que con «no es familia» ganaría acceso y hoy no lo tiene.
     * Contado en la base: de las **20 cuentas de tipo `Usuario`**, **10 son
     * superusuario y tienen el rol `Admin`, y las otras 10 no tienen ninguno de
     * los dos**. Los dos conjuntos coinciden, así que este `if` **reproduce
     * exactamente lo que ve hoy cada persona** y lo único que cambia es de dónde
     * sale el dato.
     *
     * Con «no es familia» habrían **ganado** acceso a los eventos internos diez
     * cuentas administrativas —secretaría, coordinación, enfermería, rectoría—.
     * Puede que sea lo que el colegio quiere; no lo decide un arreglo. Queda
     * anotado: **si `solo_profes` significa «solo profesores» o «solo personal»
     * es una pregunta para Joseth**, y hoy significa lo primero.
     *
     * La ruta **no lleva `auth.personal`** y no debe llevarlo: el calendario
     * público es de todo el mundo. Lo que se filtra son las filas.
     *
     * Ningún cliente lo usa como conmutador de pantalla: en las 23 ramas de
     * `myvc_front` hay **una sola** llamada —`AnunciosCtrl.ts:482`—, y manda
     * justamente ese predicado de rol. `myvc_front_2` y `myvc_flutter` no la
     * llaman. Así que esto es un arreglo, no un cambio de contrato.
     *
     * Lo fija `CalendarioInternoTest`, con las dos mitades: que la familia deje de
     * verlos y que **el personal los siga viendo sin mandar nada**.
     */
    public function putThisYear()
    {
        $user = User::fromToken();

        // El mismo `if` que `putCrearEvento`, `putGuardarEvento`,
        // `putEliminarEvento` y `putSincronizarCumples` treinta líneas más abajo.
        $puedeVerLosInternos = ($user->tipo == 'Profesor') || $user->is_superuser;

        // Las columnas nombradas, no `SELECT *`. El porqué está en `COLUMNAS`.
        if ($puedeVerLosInternos) {
            $eventos = DB::select('SELECT '.self::COLUMNAS.' FROM calendario WHERE deleted_at is null');
        } else {
            $eventos = DB::select('SELECT '.self::COLUMNAS.' FROM calendario WHERE solo_profes=0 and deleted_at is null');
        }

        return $eventos;
    }

    /**
     * Crear un evento, ahora con cuerpo, recordatorio y para quién es.
     *
     * Los tres campos nuevos son **opcionales**, y ésa es la condición de que
     * esto sea aditivo: la aplicación vieja y `myvc_flutter` siguen mandando lo
     * de siempre y siguen creando exactamente el mismo evento que antes.
     *
     * `descripcion` pasa por `HtmlDelEditor::limpiar`, **la misma lista blanca
     * que los seis campos del PIAR**, porque el front la pinta con el mismo pipe
     * de HTML rico. Si se toca una lista hay que tocar la otra: la de aquí y la
     * de `html-rico.pipe.ts` en `app2` son la misma a propósito.
     */
    public function putCrearEvento()
    {
        $user = User::fromToken();

        if (! $this->esPersonalDelColegio($user)) {
            return abort(403, 'No tienes permiso');
        }

        $now = Carbon::now('America/Bogota');
        $destinatarios = $this->destinatariosDelCuerpo();
        $recordatorio = $this->recordatorioDelCuerpo();
        $nombres = $user->tipo == 'Usuario' ? $user->username : ($user->nombres.' '.$user->apellidos);

        // El espejo sale de los destinatarios cuando el cliente los manda, y del
        // cuerpo cuando no — que es como la aplicación vieja sigue pudiendo
        // crear un evento interno. Ver `espejoDeSoloProfes()`.
        $solo_profes = $destinatarios === null
            ? Request::input('solo_profes', 0)
            : $this->espejoDeSoloProfes($destinatarios);

        $last_id = DB::transaction(function () use ($user, $now, $nombres, $solo_profes, $recordatorio, $destinatarios) {
            $consulta = 'INSERT INTO calendario(created_by, created_by_nombres, title, descripcion, start, end, allDay, solo_profes, recordatorio_minutos, created_at, updated_at)
                        VALUES(:created_by, :created_by_nombres, :title, :descripcion, :start, :end, :allDay, :solo_profes, :recordatorio_minutos, :created_at, :updated_at)';

            DB::insert($consulta, [
                ':created_by' => $user->user_id,
                ':created_by_nombres' => $nombres,
                ':title' => Request::input('title'),
                ':descripcion' => HtmlDelEditor::limpiar(Request::input('descripcion')),
                ':start' => Request::input('start'),
                ':end' => Request::input('end'),
                ':allDay' => Request::input('allDay'),
                ':solo_profes' => $solo_profes,
                ':recordatorio_minutos' => $recordatorio,
                ':created_at' => $now,
                ':updated_at' => $now,
            ]);

            $id = DB::getPdo()->lastInsertId();

            $this->guardarDestinatarios((int) $id, $destinatarios, $now);

            return $id;
        });

        return ['evento_id' => $last_id];
    }

    /**
     * Guardar un evento. Igual que crear, con **una regla de más**: lo que el
     * cliente no manda, no se toca.
     *
     * `descripcion` y `recordatorio_minutos` sólo entran en el `UPDATE` si el
     * cuerpo trae la clave, y los destinatarios sólo se reescriben si trae la
     * suya. Sin eso, **la primera vez que alguien editara un evento desde la
     * aplicación vieja le borraría el cuerpo y el reparto** —esos clientes no
     * mandan campos que no conocen— y no habría ningún error que lo delatara.
     *
     * El precio es que un cliente viejo puede dejar `solo_profes` diciendo una
     * cosa y las filas de destinatarios otra. No es grave y se lee en un orden
     * fijo: para las familias **mandan las filas**, y `solo_profes` sólo decide
     * cuando no hay ninguna.
     */
    public function putGuardarEvento()
    {
        $user = User::fromToken();

        if (! $this->esPersonalDelColegio($user)) {
            return abort(403, 'No tienes permiso');
        }

        $now = Carbon::now('America/Bogota');
        $destinatarios = $this->destinatariosDelCuerpo();
        $start = null;
        $end = null;

        if (Request::input('start')) {
            $start = Carbon::parse(Request::input('start'));
        }
        if (Request::input('end')) {
            $end = Carbon::parse(Request::input('end'));
        }

        $campos = ['updated_by = ?', 'title = ?', 'start = ?', 'end = ?', 'allDay = ?', 'solo_profes = ?', 'updated_at = ?'];
        $valores = [
            $user->user_id,
            Request::input('title'),
            $start,
            $end,
            Request::input('allDay'),
            $destinatarios === null
                ? Request::input('solo_profes', 0)
                : $this->espejoDeSoloProfes($destinatarios),
            $now,
        ];

        if (Request::has('descripcion')) {
            $campos[] = 'descripcion = ?';
            $valores[] = HtmlDelEditor::limpiar(Request::input('descripcion'));
        }

        if (Request::has('recordatorio_minutos')) {
            $campos[] = 'recordatorio_minutos = ?';
            $valores[] = $this->recordatorioDelCuerpo();
        }

        $valores[] = Request::input('id');

        DB::transaction(function () use ($campos, $valores, $destinatarios, $now) {
            DB::update('UPDATE calendario SET '.implode(', ', $campos).' WHERE id = ?', $valores);

            $this->guardarDestinatarios((int) Request::input('id'), $destinatarios, $now);
        });

        return 'Modificado';
    }

    public function putEliminarEvento()
    {
        $user = User::fromToken();
        if (($user->tipo == 'Profesor') || $user->is_superuser) {
            $now = Carbon::now('America/Bogota');

            $consulta = 'UPDATE calendario SET deleted_at=:deleted_at, deleted_by=:deleted_by WHERE id=:id';
            DB::update($consulta, [
                ':deleted_at' => $now,
                ':deleted_by' => $user->user_id,
                ':id' => Request::input('id'),
            ]);

            return 'Eliminado';
        } else {
            return abort(403, 'No tienes permiso');
        }
    }

    public function putSincronizarCumples()
    {
        $user = User::fromToken();
        $nombres = $user->tipo == 'Usuario' ? $user->username : ($user->nombres.' '.$user->apellidos);

        if (($user->tipo == 'Profesor') || $user->is_superuser) {
            $now = Carbon::now('America/Bogota');

            $consulta = 'DELETE FROM calendario WHERE cumple_alumno_id is not null or cumple_profe_id is not null';
            DB::delete($consulta);

            // El nombre entraba aqui SIN LIGAR, dentro de unas comillas dobles del SQL. No llega
            // del cuerpo de esta peticion --por eso ningun detector de asimetria lo veia-- sino
            // de la fila del usuario, y esa fila la escribe el cuerpo de OTRA ruta: `postStore`
            // de ProfesoresController asigna `nombres` desde `Request::input` y no exige nada,
            // asi que quien pueda crear un profesor elige el texto que acaba dentro de este SQL.
            // Se guarda por una puerta y detona por otra. Lo fija CalendarioCumplesTest.
            $consulta = 'INSERT INTO calendario(created_by, created_by_nombres, title, start, allDay, cumple_alumno_id, created_at, updated_at)
                SELECT ? as created_by, ? as created_by_nombres, CONCAT("Cumple ", CONCAT(a.nombres, " ", a.apellidos), "(", g.abrev, ")") as title, 
                    CONCAT(REPLACE(a.fecha_nac, SUBSTRING_INDEX(a.fecha_nac, "-", 1), ?), " 05:00:00") as start, 1 as allDay, a.id as cumple_alumno_id, ? as created_at, ? as updated_at
                FROM alumnos a
                INNER JOIN matriculas m ON m.alumno_id=a.id and m.deleted_at is null and a.fecha_nac is not null
                INNER JOIN grupos g ON g.id=m.grupo_id and g.year_id=? and g.deleted_at is null';

            DB::insert($consulta, [$user->user_id, $nombres, $user->year, $now, $now, $user->year_id]);

            // Lo mismo un piso mas abajo: mismo `$nombres`, misma via.
            $consulta = 'INSERT INTO calendario(created_by, created_by_nombres, title, start, allDay, cumple_profe_id, created_at, updated_at)
                SELECT ? as created_by, ? as created_by_nombres, CONCAT("Cumple ", CONCAT(a.nombres, " ", a.apellidos), "(docente)") as title, 
                    CONCAT(REPLACE(a.fecha_nac, SUBSTRING_INDEX(a.fecha_nac, "-", 1), ?), " 05:00:00") as start, 1 as allDay, a.id as cumple_profe_id, ? as created_at, ? as updated_at
                FROM profesores a
                INNER JOIN contratos c ON c.profesor_id=a.id and c.year_id=? and c.deleted_at is null and a.fecha_nac is not null';

            DB::insert($consulta, [$user->user_id, $nombres, $user->year, $now, $now, $user->year_id]);

            return 'Sincronizados';

        } else {
            return abort(403, 'No tienes permiso');
        }
    }

    /** Los tres públicos a los que puede ir dirigido un evento. Vocabulario cerrado aquí. */
    private const PUBLICOS = ['personal', 'alumnos', 'acudientes'];

    /** Cuatro semanas. Un recordatorio más lejano no es un recordatorio, es otro evento. */
    private const MAX_RECORDATORIO = 40320;

    /**
     * **El mes que pide la pantalla, con los cumpleaños CALCULADOS y las filas
     * que no le tocan a quien pregunta fuera de la respuesta.**
     *
     * Cuerpo: `{"year": 2026, "mes": 9}`. Contesta `{desde, hasta, eventos}`.
     *
     * ## Por qué los cumpleaños ya no son filas
     *
     * `putSincronizarCumples()` —treinta líneas más abajo, intacta— los genera
     * como filas de `calendario` y hace dos cosas que no tienen arreglo dentro
     * de ese diseño:
     *
     * 1. **Sella el año**: sustituye el año de nacimiento por `$user->year`, así
     *    que los cumpleaños generados un año **no existen** al año siguiente.
     * 2. **Congela la matrícula**: une contra los grupos de `$user->year_id`, o
     *    sea que la lista es una foto del día en que alguien pulsó el botón.
     *
     * Y empieza por un `DELETE` sin `WHERE` de año, así que pulsarlo desde un
     * año lectivo con poca gente matriculada **cambia el calendario entero por
     * el de ese año, sin error y sin poder deshacerlo**.
     *
     * Calcularlos al pedir el mes quita las tres cosas a la vez: no hay estado
     * que sincronizar, así que no hay estado que se pueda quedar viejo.
     *
     * ## `clave`, que es obligatoria y no es adorno
     *
     * Un cumpleaños **no tiene `id`** —no es una fila—, y el `track` de Angular
     * necesita algo estable con lo que distinguir dos elementos de la lista. Sin
     * `clave` el front no puede pintar. Formato: `manual:1460` para los eventos
     * de la tabla, `cumple_alumno:345:2026-09-22` para los calculados. **Única
     * dentro de una respuesta**, que es lo único que el front necesita.
     *
     * ## Las 507 filas de cumpleaños viejas NO salen por aquí
     *
     * La consulta de eventos manuales excluye las que llevan `cumple_alumno_id`
     * o `cumple_profe_id`. Sin ese filtro, cada cumpleaños se pintaría **dos
     * veces** —la fila vieja como `origen: "manual"` y el calculado— mientras
     * las 507 sigan en la base, que es hasta que la pantalla nueva funcione.
     * Siguen intactas y `calendario/this-year` las sigue devolviendo.
     */
    public function putMes()
    {
        $user = User::fromToken();

        $year = filter_var(Request::input('year'), FILTER_VALIDATE_INT);
        $mes = filter_var(Request::input('mes'), FILTER_VALIDATE_INT);

        if ($year === false || $year < 2000 || $year > 2100) {
            abort(422, 'El año tiene que ser un número entre 2000 y 2100.');
        }

        if ($mes === false || $mes < 1 || $mes > 12) {
            abort(422, 'El mes tiene que ser un número entre 1 y 12.');
        }

        [$desde, $hasta] = $this->rejillaDelMes($year, $mes);

        return $this->respuestaDelRango($user, $desde, $hasta);
    }

    /**
     * Lo que viene, para el mini calendario de la portada.
     *
     * Cuerpo: `{"dias": 30}`. Misma forma de item y mismo filtrado por token que
     * `putMes()`; lo único que cambia es de dónde sale el rango — de hoy, en la
     * zona de Bogotá que ya usa el resto del controlador, y no de un mes pedido.
     */
    public function putProximos()
    {
        $user = User::fromToken();

        $dias = filter_var(Request::input('dias', 30), FILTER_VALIDATE_INT);

        if ($dias === false || $dias < 1 || $dias > 365) {
            abort(422, 'Los días tienen que ser un número entre 1 y 365.');
        }

        $desde = Carbon::now('America/Bogota')->startOfDay();

        return $this->respuestaDelRango($user, $desde, $desde->copy()->addDays($dias));
    }

    /**
     * El rango que hay que pedir para pintar el mes, **en semanas completas**.
     *
     *   `desde` = el lunes en o antes de (día 1 − 7 días)
     *   `hasta` = el domingo en o antes de (último día + 7 días)
     *
     * Para septiembre de 2026 da **2026-08-24 → 2026-10-04**, que es el ejemplo
     * del contrato.
     *
     * **La rejilla enseña días del mes anterior y del siguiente**, y por eso el
     * rango no puede ser el mes: un evento del 30 de agosto se pinta en la
     * primera fila de septiembre, y sin margen llegaría vacío. El margen no es
     * fijo —sale entre 7 y 13 días por delante y entre 6 y 7 por detrás— porque
     * lo que se redondea son **semanas**, y así el rango cubre tanto una rejilla
     * que empieza en lunes como una que empieza en domingo. Comprobado por la
     * coordinación del front sobre 132 meses (2024–2034) contra las dos
     * rejillas, cero fallos.
     *
     * **El front lee `desde`/`hasta` de la respuesta y no recalcula esto**, que
     * es lo que permite ensanchar el margen algún día sin desplegar el front.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function rejillaDelMes(int $year, int $mes): array
    {
        $primero = Carbon::create($year, $mes, 1, 0, 0, 0, 'America/Bogota');
        $ultimo = $primero->copy()->endOfMonth()->startOfDay();

        $desde = $primero->copy()->subDays(7)->startOfWeek(Carbon::MONDAY);

        // El domingo **en o antes de** `último + 7`, que no es lo que devuelve
        // `endOfWeek()`: ése da el domingo en o DESPUÉS, y para septiembre de
        // 2026 saldría el 11 de octubre en vez del 4.
        $hasta = $ultimo->copy()->addDays(7)->startOfWeek(Carbon::MONDAY)->subDay();

        return [$desde, $hasta];
    }

    /**
     * Los eventos del rango que le tocan a este token, ya ordenados.
     *
     * El orden es `start` y, a igualdad, `clave`. **Determinista a propósito**:
     * el `track` del front y las instantáneas de contrato comparan listas, y una
     * lista que sale en otro orden en cada corrida no compara nada.
     */
    private function respuestaDelRango(object $user, Carbon $desde, Carbon $hasta): array
    {
        $eventos = array_merge(
            $this->eventosManualesDelRango($user, $desde, $hasta),
            $this->cumplesDelRango($user, $desde, $hasta)
        );

        usort($eventos, fn ($a, $b) => [$a['start'], $a['clave']] <=> [$b['start'], $b['clave']]);

        return [
            'desde' => $desde->toDateString(),
            'hasta' => $hasta->toDateString(),
            'eventos' => $eventos,
        ];
    }

    /**
     * Los eventos de la tabla que caen en el rango y le tocan a este token.
     *
     * ## El rango se pregunta por SOLAPE, no por `start`
     *
     * `c.start < findelrango AND COALESCE(c.end, c.start) >= desde`. Con un
     * filtro sobre `start` a secas, un evento que empieza el 25 de agosto y
     * acaba el 30 de septiembre —una semana cultural, un periodo— **no saldría
     * en septiembre en absoluto**, y el front no tendría con qué pintar la barra
     * que lo cruza. `COALESCE` porque `end` es nullable y un evento de un solo
     * día no lo trae.
     *
     * El `end` abierto por arriba es exclusivo (`hasta + 1 día`) para que un
     * evento a las 23:30 del último día entre, que es lo que no haría comparar
     * contra la fecha pelada.
     */
    private function eventosManualesDelRango(object $user, Carbon $desde, Carbon $hasta): array
    {
        $parametros = [$hasta->copy()->addDay()->toDateString(), $desde->toDateString()];
        $visibilidad = '';

        if (! $this->esPersonalDelColegio($user)) {
            [$visibilidad, $extra] = $this->filtroDeDestinatarios($user);
            $parametros = array_merge($parametros, $extra);
        }

        $filas = DB::select('SELECT c.id, c.title, c.descripcion, c.start, c.end, c.allDay,
                                    c.solo_profes, c.url, c.recordatorio_minutos, c.created_by_nombres
                               FROM calendario c
                              WHERE c.deleted_at IS NULL
                                AND c.cumple_alumno_id IS NULL
                                AND c.cumple_profe_id IS NULL
                                AND c.start < ?
                                AND COALESCE(c.end, c.start) >= ?'.$visibilidad, $parametros);

        $porEvento = $this->destinatariosDe(array_values(array_map(fn ($f) => (int) $f->id, $filas)));

        $eventos = [];

        foreach ($filas as $fila) {
            $id = (int) $fila->id;
            $suyos = $porEvento[$id] ?? [];

            // «Sin filas y `solo_profes = 1`» se devuelve como **exactamente una
            // fila implícita `('personal', null)`**, y no como un caso aparte que
            // el front tenga que deducir mirando `solo_profes`. Son todos los
            // eventos internos que ya existen y los que sigan creando la
            // aplicación vieja y `myvc_flutter`, que no saben de la tabla.
            //
            // Con esto, `destinatarios: []` significa «público» y nada más, y el
            // día del paso 8 «mandar un aviso» es «una fila, un tema» sin tener
            // que acordarse de este caso justo ahí.
            if ($suyos === [] && (int) $fila->solo_profes === 1) {
                $suyos = [$this->filaDeDestinatario('personal', null, null, null)];
            }

            $eventos[] = [
                'clave' => 'manual:'.$id,
                'origen' => 'manual',
                'id' => $id,
                'persona_id' => null,
                'title' => $fila->title,
                'descripcion' => $fila->descripcion,
                'start' => $fila->start,
                'end' => $fila->end,
                'allDay' => (int) $fila->allDay,
                'solo_profes' => (int) $fila->solo_profes,
                'url' => $fila->url,
                'recordatorio_minutos' => $fila->recordatorio_minutos === null ? null : (int) $fila->recordatorio_minutos,
                'created_by_nombres' => $fila->created_by_nombres,
                'destinatarios' => $suyos,
            ];
        }

        return $eventos;
    }

    /**
     * **El filtro que decide qué NO viaja.** Sólo se aplica a quien no es
     * personal del colegio; ver `esPersonalDelColegio()` para el porqué.
     *
     * Las dos ramas son las dos formas de que un evento le toque a alguien:
     *
     * 1. **No tiene ni una fila de destinatarios y `solo_profes = 0`** — es
     *    público, que es el comportamiento de hoy y **sigue siendo el defecto**.
     *    Los eventos que ya existen no tienen filas y no cambian de significado.
     * 2. **Tiene una fila que le nombra**, por público y por grupo. `grupo_id`
     *    NULL en la fila significa «todos los de ese público».
     *
     * Lo que queda fuera de las dos es lo que no viaja: **sin filas y
     * `solo_profes = 1`** no entra por ninguna, que es como los eventos internos
     * siguen siendo internos sin que la tabla nueva sepa de ellos.
     *
     * @return array{0: string, 1: list<mixed>}
     */
    private function filtroDeDestinatarios(object $user): array
    {
        [$publicos, $grupos] = $this->aQuienLeToca($user);

        $soloLoPublico = ' AND NOT EXISTS (SELECT 1 FROM calendario_destinatarios d
                                            WHERE d.calendario_id = c.id)
                           AND c.solo_profes = 0';

        // Un administrativo sin superusuario cae aquí: no es personal para este
        // controlador (ver `esPersonalDelColegio`) y tampoco es familia de
        // nadie, así que ve exactamente los públicos. Es lo que ve hoy.
        if ($publicos === []) {
            return [$soloLoPublico, []];
        }

        $porGrupo = 'd.grupo_id IS NULL';
        $parametros = $publicos;

        if ($grupos !== []) {
            $porGrupo .= ' OR d.grupo_id IN ('.implode(', ', array_fill(0, count($grupos), '?')).')';
            $parametros = array_merge($parametros, $grupos);
        }

        $sql = ' AND ( ( NOT EXISTS (SELECT 1 FROM calendario_destinatarios d
                                      WHERE d.calendario_id = c.id)
                         AND c.solo_profes = 0 )
                       OR EXISTS (SELECT 1 FROM calendario_destinatarios d
                                   WHERE d.calendario_id = c.id
                                     AND d.publico IN ('.implode(', ', array_fill(0, count($publicos), '?')).')
                                     AND ('.$porGrupo.') ) )';

        return [$sql, $parametros];
    }

    /**
     * A qué públicos y a qué grupos pertenece quien pregunta.
     *
     * **Un acudiente entra también por `alumnos`**, y no es una licencia: es la
     * regla de negocio de la casa, *«un alumno solo ve lo suyo; un acudiente, lo
     * suyo y lo completo de sus acudidos»*. Un evento dirigido a los alumnos de
     * un grupo le interesa exactamente igual al padre de un alumno de ese grupo,
     * y dejarlo fuera obligaría al colegio a repartir cada evento dos veces para
     * que llegara a las familias — un reparto que se hace dos veces se hace mal
     * una de las dos.
     *
     * @return array{0: list<string>, 1: list<int>}
     */
    private function aQuienLeToca(object $user): array
    {
        if ($user->tipo == 'Alumno') {
            return [['alumnos'], [(int) $user->grupo_id]];
        }

        if ($user->tipo == 'Acudiente') {
            return [['alumnos', 'acudientes'], $this->gruposDeLosAcudidos($user)];
        }

        return [[], []];
    }

    /**
     * Los grupos de los acudidos de este acudiente, **en el año del token**.
     *
     * El año importa: `parentescos` no lo tiene —un parentesco no caduca— así
     * que sin `g.year_id` un acudiente con un hijo que salió del colegio hace
     * tres años seguiría recibiendo lo de aquel grupo.
     *
     * @return list<int>
     */
    private function gruposDeLosAcudidos(object $user): array
    {
        $filas = DB::select('SELECT DISTINCT g.id
                               FROM parentescos p
                              INNER JOIN matriculas m ON m.alumno_id = p.alumno_id AND m.deleted_at IS NULL
                              INNER JOIN grupos g ON g.id = m.grupo_id AND g.deleted_at IS NULL
                                     AND g.year_id = ?
                              WHERE p.acudiente_id = ? AND p.deleted_at IS NULL',
            [$user->year_id, $user->persona_id]);

        return array_values(array_map(fn ($f) => (int) $f->id, $filas));
    }

    /**
     * Los destinatarios de varios eventos de un golpe, indexados por evento.
     *
     * Una consulta para todos y no una por evento: `putMes()` devuelve seis
     * semanas de calendario, y preguntar dentro del bucle sería el patrón que
     * `tools/consultas-en-bucle.py` existe para encontrar.
     *
     * El `LEFT JOIN` a `grupos` es lo que hace que el front pinte «Séptimo» y no
     * «101». Es `LEFT` y no `INNER` porque **`grupo_id` NULL significa "todos"**
     * y con un `INNER` esas filas —las más comunes, las de la casilla «todos»—
     * desaparecerían de la respuesta.
     *
     * @param  list<int>  $ids
     * @return array<int, list<array<string, mixed>>>
     */
    private function destinatariosDe(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $filas = DB::select('SELECT d.calendario_id, d.publico, d.grupo_id,
                                    g.nombre AS grupo_nombre, g.abrev AS grupo_abrev
                               FROM calendario_destinatarios d
                               LEFT JOIN grupos g ON g.id = d.grupo_id
                              WHERE d.calendario_id IN ('.implode(', ', array_fill(0, count($ids), '?')).')
                              ORDER BY d.publico, d.grupo_id, d.id', $ids);

        $porEvento = [];

        foreach ($filas as $fila) {
            $porEvento[(int) $fila->calendario_id][] = $this->filaDeDestinatario(
                $fila->publico,
                $fila->grupo_id === null ? null : (int) $fila->grupo_id,
                $fila->grupo_nombre,
                $fila->grupo_abrev
            );
        }

        return $porEvento;
    }

    /**
     * La forma de una fila de destinatario, en un solo sitio.
     *
     * Van **los dos** nombres del grupo porque el corto cabe en la etiqueta del
     * detalle del día y el largo no, y cuál se pinta es decisión de la pantalla.
     * Los tres campos de grupo son `null` a la vez cuando `grupo_id` lo es.
     *
     * @return array<string, mixed>
     */
    private function filaDeDestinatario(string $publico, ?int $grupoId, ?string $nombre, ?string $abrev): array
    {
        return [
            'publico' => $publico,
            'grupo_id' => $grupoId,
            'grupo_nombre' => $nombre,
            'grupo_abrev' => $abrev,
        ];
    }

    /**
     * Los cumpleaños del rango, **calculados**, nunca leídos de `calendario`.
     *
     * ## Se proyectan en PHP y no en SQL, y es deliberado
     *
     * La forma en SQL sería `STR_TO_DATE(CONCAT(?, DATE_FORMAT(fecha_nac,
     * '-%m-%d')), '%Y-%m-%d')`, y con un nacido el **29 de febrero** en un año
     * que no es bisiesto eso devuelve **NULL** con un aviso — o sea que su
     * cumpleaños desaparecería del calendario tres de cada cuatro años **sin que
     * nada fallara**. Aquí se proyecta al 28, que es lo que hace todo el mundo, y
     * cabe en una línea que se lee.
     *
     * La población son los matriculados y los contratados del año del token, unos
     * cientos: traerlos y filtrar en PHP cuesta dos consultas sin bucle, y ningún
     * índice ayudaría a un `MONTH(fecha_nac)` de todas formas.
     *
     * ## EL `year_id` DEL TOKEN ES LA DECISIÓN ENTERA. NO SE TOCA.
     *
     * Joseth, 1 sep 2026: *«Todos, pero sólo con matrículas del año presente, no
     * los retirados el año pasado.»* O sea que **no se filtra por `estado`** —los
     * retirados de este año salen, como salen hoy con el botón— y lo que tenía
     * que quedar fuera son los de **años anteriores**.
     *
     * **Lo único que cumple eso es el `g.year_id = ?` de estas dos consultas**,
     * con el año del token. No es un detalle de la consulta: es la condición
     * entera. Quien lo quite, o lo cambie por un `IN (años)` para que el
     * calendario enseñe más, **devuelve los retirados de cursos anteriores** —y
     * no lo canta nada: ni error, ni test de estado en rojo, sólo aparecen
     * nombres de gente que se fue hace tres años.
     *
     * De paso es lo que hace que mirar el calendario de un año pasado enseñe a
     * los que estaban **entonces**.
     *
     * Efecto conocido y correcto: en un año recién creado y **con la matrícula
     * sin hacer, esto sale casi vacío**. Ver la §9.2 del 22.
     *
     * ## Y por eso NO hay filtro de `estado` aquí
     *
     * Medido contra el docker de trabajo, año 8: **377 `MATR`, 116 `RETI`, 1
     * `ASIS`**. Filtrar no sería un matiz —es casi uno de cada cuatro— y sería
     * además lo contrario de lo que se decidió.
     *
     * El `GROUP BY` tampoco es adorno: `matriculas` **no tiene clave única sobre
     * (alumno, año)** —es la §9.5 del boletín independiente—, así que un alumno
     * con dos matrículas daría dos veces el mismo cumpleaños, con la **misma
     * `clave`**, y el `track` del front rompe con claves repetidas.
     */
    private function cumplesDelRango(object $user, Carbon $desde, Carbon $hasta): array
    {
        $alumnos = DB::select('SELECT a.id, a.nombres, a.apellidos, a.fecha_nac, MIN(g.abrev) AS abrev
                                 FROM alumnos a
                                INNER JOIN matriculas m ON m.alumno_id = a.id AND m.deleted_at IS NULL
                                INNER JOIN grupos g ON g.id = m.grupo_id AND g.deleted_at IS NULL
                                       AND g.year_id = ?
                                WHERE a.deleted_at IS NULL AND a.fecha_nac IS NOT NULL
                                GROUP BY a.id, a.nombres, a.apellidos, a.fecha_nac', [$user->year_id]);

        $profesores = DB::select('SELECT p.id, p.nombres, p.apellidos, p.fecha_nac
                                    FROM profesores p
                                   INNER JOIN contratos c ON c.profesor_id = p.id AND c.deleted_at IS NULL
                                          AND c.year_id = ?
                                   WHERE p.deleted_at IS NULL AND p.fecha_nac IS NOT NULL
                                   GROUP BY p.id, p.nombres, p.apellidos, p.fecha_nac', [$user->year_id]);

        $eventos = [];

        foreach ($alumnos as $alumno) {
            foreach ($this->cumplesEnElRango((string) $alumno->fecha_nac, $desde, $hasta) as $cuando) {
                $eventos[] = $this->itemDeCumple('cumple_alumno', (int) $alumno->id,
                    $alumno->nombres.' '.$alumno->apellidos, '('.$alumno->abrev.')', $cuando);
            }
        }

        foreach ($profesores as $profesor) {
            foreach ($this->cumplesEnElRango((string) $profesor->fecha_nac, $desde, $hasta) as $cuando) {
                $eventos[] = $this->itemDeCumple('cumple_profe', (int) $profesor->id,
                    $profesor->nombres.' '.$profesor->apellidos, '(docente)', $cuando);
            }
        }

        return $eventos;
    }

    /**
     * En qué días del rango cae el cumpleaños de quien nació ese día.
     *
     * Se prueban los dos años que puede tocar el rango —el de `desde` y el de
     * `hasta`, que son el mismo o consecutivos porque el rango nunca llega al
     * año— en vez de «el año pedido»: el rango de enero entra en diciembre del
     * anterior y el de diciembre en enero del siguiente, y con un solo año esos
     * cumpleaños del margen no saldrían.
     *
     * @return list<Carbon>
     */
    private function cumplesEnElRango(string $fechaNac, Carbon $desde, Carbon $hasta): array
    {
        $nacimiento = Carbon::parse($fechaNac);
        $fechas = [];

        for ($anio = $desde->year; $anio <= $hasta->year; $anio++) {
            $dia = $nacimiento->day;
            $mes = $nacimiento->month;

            // Nacido un 29 de febrero, en un año que no es bisiesto: se pinta el
            // 28. La alternativa es que su cumpleaños no exista tres de cada
            // cuatro años, que es un parte de fallo y no una decisión.
            if ($mes === 2 && $dia === 29 && ! Carbon::create($anio, 1, 1)->isLeapYear()) {
                $dia = 28;
            }

            $cumple = Carbon::create($anio, $mes, $dia, 5, 0, 0, 'America/Bogota');

            if ($cumple->toDateString() >= $desde->toDateString()
                && $cumple->toDateString() <= $hasta->toDateString()) {
                $fechas[] = $cumple;
            }
        }

        return $fechas;
    }

    /**
     * Un cumpleaños con la forma de un evento.
     *
     * El título y la hora reproducen **exactamente** los de las filas que
     * generaba `putSincronizarCumples()` —`"Cumple Nombre Apellido(6A)"`, sin
     * espacio antes del paréntesis, y las 05:00— para que la pantalla nueva
     * enseñe lo mismo que la vieja mientras las dos convivan.
     *
     * `created_by_nombres` es `null` y no el nombre de quien pregunta: un
     * cumpleaños **no lo creó nadie**. De paso, ahí es donde entraba sin ligar el
     * nombre del usuario en la inyección que fija `CalendarioCumplesTest`; aquí
     * no hay SQL que construir con él.
     *
     * @return array<string, mixed>
     */
    private function itemDeCumple(string $origen, int $personaId, string $nombre, string $sufijo, Carbon $cuando): array
    {
        return [
            'clave' => $origen.':'.$personaId.':'.$cuando->toDateString(),
            'origen' => $origen,
            'id' => null,
            'persona_id' => $personaId,
            'title' => 'Cumple '.$nombre.$sufijo,
            'descripcion' => null,
            'start' => $cuando->format('Y-m-d H:i:s'),
            'end' => null,
            'allDay' => 1,
            'solo_profes' => 0,
            'url' => null,
            'recordatorio_minutos' => null,
            'created_by_nombres' => null,
            'destinatarios' => [],
        ];
    }

    /**
     * **Quién es «personal del colegio» para este controlador.**
     *
     * Es el mismo criterio que ya usan las cinco rutas de aquí —`tipo ==
     * 'Profesor' || is_superuser`— y no uno nuevo, que es lo que evita acabar
     * con dos criterios para el mismo módulo. El porqué está medido en el
     * docblock de `putThisYear()`: el candidato de «no es alumno ni acudiente»
     * habría **ampliado** el calendario interno a diez cuentas administrativas.
     *
     * `putThisYear()`, `putEliminarEvento()` y `putSincronizarCumples()` siguen
     * escribiéndolo a mano y **no se han tocado a propósito**: `this-year` la
     * leen la aplicación vieja y `myvc_flutter` en los quince colegios, y esta
     * épica es aditiva. Quien toque una de las tres, que use esto.
     *
     * ## Y el personal ve TODO, sin pasar por el filtro de destinatarios
     *
     * Es una decisión y no un olvido. Hoy, por `this-year`, el personal ve todos
     * los eventos; si `calendario/mes` le escondiera los dirigidos a los alumnos
     * de un grupo, **el docente que acaba de crear «Salida de 7º» no lo vería en
     * su propio calendario**, y la pantalla enseñaría menos que la que sustituye.
     * Los destinatarios existen para no llenar de ruido a las familias, no para
     * esconderle el calendario al colegio: `solo_profes` esconde cosas **de** las
     * familias, nunca del personal.
     */
    private function esPersonalDelColegio(object $user): bool
    {
        return ($user->tipo == 'Profesor') || $user->is_superuser;
    }

    /**
     * Los destinatarios que manda el cuerpo, ya validados y **deduplicados**.
     *
     * Devuelve `null` cuando el cuerpo **no trae la clave**, y eso no es lo
     * mismo que traerla vacía:
     *
     *   - `null`  → el cliente no sabe de destinatarios. **Modo viejo**: no se
     *               tocan las filas y `solo_profes` sale del cuerpo, como
     *               siempre. Es lo que mandan la aplicación vieja y
     *               `myvc_flutter`, desplegadas en los quince colegios.
     *   - `[]`    → el cliente dice «para todos». Evento público.
     *
     * Sin esa distinción, la primera vez que la aplicación vieja guardara un
     * evento le borraría el reparto, o —peor— un evento interno suyo se volvería
     * público porque el espejo de `solo_profes` se calcularía sobre una lista
     * vacía que el cliente nunca mandó.
     *
     * **El deduplicado es aquí y no un índice único en la tabla**: MySQL trata
     * cada NULL como distinto de los demás, así que dos filas
     * `('alumnos', NULL)` —la misma frase, «todos los alumnos», que es justo la
     * que se marca con una casilla— caben las dos bajo un `UNIQUE`. Ver la
     * migración.
     *
     * @return list<array{publico: string, grupo_id: int|null}>|null
     */
    private function destinatariosDelCuerpo(): ?array
    {
        if (! Request::has('destinatarios')) {
            return null;
        }

        $crudos = Request::input('destinatarios');

        if ($crudos === null) {
            return null;
        }

        if (! is_array($crudos)) {
            abort(422, 'El campo `destinatarios` tiene que ser una lista.');
        }

        $limpios = [];

        foreach ($crudos as $fila) {
            if (! is_array($fila) || ! isset($fila['publico']) || ! in_array($fila['publico'], self::PUBLICOS, true)) {
                abort(422, 'Cada destinatario necesita un `publico`, y tiene que ser uno de: '
                    .implode(', ', self::PUBLICOS).'.');
            }

            $grupo = $fila['grupo_id'] ?? null;

            if ($grupo !== null) {
                $grupo = filter_var($grupo, FILTER_VALIDATE_INT);

                if ($grupo === false || $grupo <= 0) {
                    abort(422, 'El `grupo_id` de un destinatario tiene que ser un número o `null`.');
                }
            }

            $limpios[$fila['publico'].':'.($grupo ?? '')] = [
                'publico' => $fila['publico'],
                'grupo_id' => $grupo,
            ];
        }

        return array_values($limpios);
    }

    /**
     * **`solo_profes` NO SE RETIRA: pasa a ser un espejo, y se escribe en cada
     * guardado.** 1 cuando las únicas filas de destinatarios son de `personal`,
     * 0 en cualquier otro caso.
     *
     * ## No lo quites
     *
     * `calendario/this-year` sigue leyendo esta columna desde **la aplicación
     * vieja y `myvc_flutter`**, y las dos están desplegadas en los quince
     * colegios. Si se deja de escribir, un evento marcado «sólo personal» desde
     * la pantalla nueva se ve **público** allí — y **no da ningún error**, que es
     * lo que hace que no se descubra hasta que alguien pregunte por qué un padre
     * sabía lo de la reunión de profesores.
     *
     * Vive mientras vivan esos dos clientes. El día que ninguno lea `this-year`,
     * esta columna se puede retirar y no antes.
     *
     * @param  list<array{publico: string, grupo_id: int|null}>  $destinatarios
     */
    private function espejoDeSoloProfes(array $destinatarios): int
    {
        if ($destinatarios === []) {
            return 0;
        }

        foreach ($destinatarios as $destinatario) {
            if ($destinatario['publico'] !== 'personal') {
                return 0;
            }
        }

        return 1;
    }

    /**
     * Reescribe el reparto de un evento: borra lo que había e inserta lo nuevo.
     *
     * Borrar e insertar —y no un `INSERT ... ON DUPLICATE KEY UPDATE`— porque la
     * tabla **no puede tener clave única** sobre `(evento, público, grupo)`: los
     * NULL de `grupo_id` no chocan entre sí en MySQL. Va dentro de la
     * transacción de quien llama, así que no hay ventana en la que el evento
     * esté sin destinatarios.
     *
     * Con `$destinatarios` a `null` no toca nada: es un cliente que no sabe de
     * esto, y borrarle el reparto sería la peor forma de tratarlo.
     *
     * @param  list<array{publico: string, grupo_id: int|null}>|null  $destinatarios
     */
    private function guardarDestinatarios(int $eventoId, ?array $destinatarios, Carbon $now): void
    {
        if ($destinatarios === null) {
            return;
        }

        DB::delete('DELETE FROM calendario_destinatarios WHERE calendario_id = ?', [$eventoId]);

        foreach ($destinatarios as $destinatario) {
            DB::insert('INSERT INTO calendario_destinatarios(calendario_id, publico, grupo_id, created_at, updated_at)
                        VALUES(?, ?, ?, ?, ?)',
                [$eventoId, $destinatario['publico'], $destinatario['grupo_id'], $now, $now]);
        }
    }

    /**
     * Los minutos de antelación del recordatorio, validados. `null` = no avisar.
     *
     * El tope son cuatro semanas: más allá el «recordatorio» cae en otro mes que
     * el del evento y deja de ser un recordatorio.
     */
    private function recordatorioDelCuerpo(): ?int
    {
        $valor = Request::input('recordatorio_minutos');

        if ($valor === null || $valor === '') {
            return null;
        }

        $minutos = filter_var($valor, FILTER_VALIDATE_INT);

        if ($minutos === false || $minutos < 0 || $minutos > self::MAX_RECORDATORIO) {
            abort(422, 'El recordatorio tiene que ser un número de minutos entre 0 y '.self::MAX_RECORDATORIO.'.');
        }

        return $minutos;
    }
}
