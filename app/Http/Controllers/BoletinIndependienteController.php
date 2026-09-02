<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResuelveElUsuario;
use App\Models\Asignatura;
use App\Services\BoletinIndependiente;
use App\Services\DefinitivasDeAsignatura;
use App\Support\Autoriza;
use App\Support\Reloj;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;

/**
 * La marca del boletín independiente: **el único sitio que la escribe**.
 *
 * Fase 2 de [19-boletin-independiente.md](../../../docs/migracion/19-boletin-independiente.md).
 * Aquí sólo vive la escritura; **de quién es una unidad lo decide
 * `App\Services\BoletinIndependiente`** y no se vuelve a escribir esa regla, que
 * es lo mismo que se hizo con `DefinitivasDeAsignatura` y por lo mismo: con dos
 * sitios decidiendo, la planilla acaba contando una cosa y el papel impreso otra.
 *
 * ## Por qué la escritura es una ruta propia y no un `case` de `alumnos/guardar-valor`
 *
 * Lo era en el plan hasta la **decisión 7** (31 ago 2026), cuando la marca dejó de
 * ser del año y pasó a ser **por periodo**: `PUT alumnos/guardar-valor` escribe
 * columnas de `matriculas` y esto ya no es una columna de `matriculas`. Con la
 * decisión 7 ésta es la **única** escritura de la marca que existe, y por eso subió
 * de la fase 4 a la fase 2 — sin ella la fase 3 no tendría cómo montar un caso.
 */
class BoletinIndependienteController extends Controller
{
    use ResuelveElUsuario;

    /**
     * `PUT boletin-independiente/periodo` — «este alumno, en este periodo, va aparte».
     *
     * ```jsonc
     * { "alumno_id": 3311, "periodo_id": 91, "aplica": false }
     * → { "alumno_id": 3311, "periodo_id": 91, "aplica": false }
     * ```
     *
     * ## `periodo_id` viene del CUERPO, y lo corrigió el front con razón
     *
     * El plan decía «el periodo es el del usuario», copiado de `notas/detailed`. Con
     * esa forma **la ficha no puede marcar el periodo del accidente**: el del token es
     * el activo, y el accidente casi nunca es el activo. Un backend que lo sacara del
     * token marcaría **siempre el activo, en silencio y con 200** — que es el modo de
     * fallo que este módulo lleva dos revisiones quitando.
     *
     * **Y por eso entra una guarda que antes no hacía falta**, la familia de
     * `tools/identificadores-del-cuerpo.py`: un `alumno_id` y un `periodo_id` que
     * llegan sueltos del cliente **no tienen por qué tener nada que ver**. La clave
     * foránea sólo obliga a que los dos existan. Se comprueban las dos cosas:
     *
     *   1. que el periodo sea de un año sobre el que quien llama puede actuar — el
     *      del token, que es de donde la ficha saca los cuatro que enseña;
     *   2. que el alumno **esté matriculado en el año de ese periodo**.
     *
     * Sin la 2 se escribe una fila que `BoletinIndependiente::consultar()` devolvería
     * como buena, y esa lectura **ya no lo comprueba a propósito** (§2.2 del plan):
     * ponerlo en la lectura lo cobraría en cada boletín impreso para defenderse de un
     * estado que el escritor no debe dejar crear.
     *
     * ## Sí acepta un periodo CERRADO, y es decisión tomada (§2.4)
     *
     * Las tres guardas de periodo cerrado de `app/User.php` —`pueden_editar_notas`,
     * `permiteEditarNotas` y `exigirPeriodoAbiertoParaNotas`— **muerden sólo a
     * `tipo == 'Profesor'`**, y quien marca, por la decisión 5, es `tipo = 'Usuario'`.
     * Ponerles una guarda sería escribir una regla nueva, no aplicar la que hay. Y el
     * requisito la quiere así: el caso es el colegio que cierra el periodo 2 y sólo
     * entonces cae en que el alumno lo necesitaba aparte. La otra salida sería
     * **reabrir el periodo**, que le abre la planilla entera a los 51 docentes.
     *
     * ## No borra nada, nunca
     *
     * Ni una fila de `unidades`, `subunidades` ni `notas`. Es la petición literal del
     * colegio —*«no debe borrar los datos … pero esos datos deben ser ignorados»*— y
     * lo fija un test que apaga y enciende contando las filas antes y después.
     */
    public function putPeriodo()
    {
        // El permiso ANTES de mirar el cuerpo: si fuera al revés, quien no puede
        // marcar distinguiría un alumno que existe de uno que no por el código de
        // error. El 422 de un id inventado es información sobre la base.
        Autoriza::exigir(
            Autoriza::puedeMarcarBoletinIndependiente($this->user),
            'No tienes permiso para marcar el boletín independiente de un alumno.'
        );

        $alumnoId = $this->idDelCuerpo('alumno_id');
        $periodoId = $this->idDelCuerpo('periodo_id');
        $aplica = $this->aplicaDelCuerpo();

        $periodo = DB::selectOne(
            'SELECT p.id, p.year_id FROM periodos p WHERE p.id = ? AND p.deleted_at IS NULL',
            [$periodoId]
        );

        if ($periodo === null) {
            abort(404, 'Ese periodo no existe.');
        }

        // (1) El año sobre el que quien llama puede actuar es el suyo, y no es una
        // cautela de más: la ficha saca los cuatro periodos que enseña de
        // `$user->year_id` (§6.4), así que un `periodo_id` de otro año no viene de la
        // pantalla — viene de alguien que lo escribió a mano. Es 403 y no 404 porque
        // el periodo existe: decir «no existe» sería mentir sobre la base.
        if ((int) $periodo->year_id !== (int) $this->user->year_id) {
            abort(403, 'Ese periodo no es del año en el que estás trabajando.');
        }

        // (2) Y el alumno tiene que estar matriculado en el año de ESE periodo.
        //
        // **Sin filtrar por `estado`, y es deliberado.** Lo que esta guarda defiende
        // es que el alumno y el periodo tengan que ver el uno con el otro; el estado
        // de la matrícula es otra pregunta y no la ha contestado nadie. Filtrar por
        // MATR/ASIS dejaría fuera al retirado a mitad de año, que es justamente de
        // quien se imprime un boletín con lo que alcanzó a cursar.
        $matriculado = DB::selectOne(
            'SELECT m.id
               FROM matriculas m
               INNER JOIN grupos g ON g.id = m.grupo_id AND g.deleted_at IS NULL
              WHERE m.alumno_id = ? AND m.deleted_at IS NULL AND g.year_id = ?
              LIMIT 1',
            [$alumnoId, $periodo->year_id]
        );

        if ($matriculado === null) {
            abort(422, 'Ese alumno no está matriculado en el año de ese periodo.');
        }

        $ahora = Reloj::ahoraTexto();

        DB::transaction(function () use ($alumnoId, $periodoId, $aplica, $ahora) {
            // `INSERT ... ON DUPLICATE KEY UPDATE` sobre `bol_ind_periodos_unico
            // (alumno_id, periodo_id)`. La clave única nació con la tabla justamente
            // para que esto sea una sentencia y **no haya ventana de borrado**: un
            // `DELETE` + `INSERT` deja al alumno un instante sin fila, y una fila
            // ausente significa «va con el grupo» — o sea el boletín entero
            // parpadeando para quien lea en ese instante.
            DB::insert(
                'INSERT INTO bol_ind_periodos (alumno_id, periodo_id, aplica, updated_by, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE aplica = VALUES(aplica),
                                         updated_by = VALUES(updated_by),
                                         updated_at = VALUES(updated_at)',
                [$alumnoId, $periodoId, $aplica ? 1 : 0, $this->user->user_id, $ahora, $ahora]
            );

            // **La fila se escribe diciendo que no; no se borra.** Sin fila es «nunca
            // estuvo marcado», y eso no es lo mismo que «este periodo va con el
            // grupo, con sus datos guardados» — que es uno de los cuatro estados que
            // la ficha pinta (§6.4).
            if (! $aplica) {
                $this->sembrarLasNotasQueFaltan($alumnoId, $periodoId, (int) $this->user->year_id, $ahora);
            }

            // **Lo que acabamos de escribir invalida lo que el servicio cacheó, y sin
            // esto la MISMA petición sigue contestando con lo de antes.**
            //
            // `BoletinIndependiente` memoiza `alcance(alumno, periodo)` en una estática
            // que «vive lo que vive la petición». Eso es una caché de lectura y está
            // bien mientras la petición sólo lea; **ésta es la única del sistema que
            // escribe esa respuesta**, así que es la única que puede dejarla mintiendo.
            //
            // Hoy no se ve —el sembrado de aquí arriba va por SQL y no pregunta al
            // servicio—, y por eso conviene decir dónde muerde mañana: la ruta de la
            // §6.1 (`boletin-independiente/planilla`) **lee el alcance en la misma
            // petición en la que se puede haber escrito**, y sin esta línea devolvería
            // la planilla del alumno que era **antes** de marcarlo, en 200 y sin un
            // error en ningún sitio. Es la forma exacta de fallo que este módulo lleva
            // tres revisiones quitando.
            //
            // Lo destapó un rojo que parecía de otra cosa: dos casos de `BoletinesTest`
            // fallando **sólo dentro de la suite**, porque en un proceso de tests la
            // estática sobrevive al `DatabaseTransactions` que sí deshace la base.
            // Aquello se cerró en `CasoDeContrato::setUp()`, que es higiene de test;
            // **esto es lo otro que aquel rojo estaba señalando**, y es de producción.
            BoletinIndependiente::olvidar();
        });

        return [
            'alumno_id' => $alumnoId,
            'periodo_id' => $periodoId,
            'aplica' => $aplica,
        ];
    }

    /**
     * `PUT boletin-independiente/planilla` — la pantalla del docente, entera, en una
     * petición. §6.1 del [19](../../../docs/migracion/19-boletin-independiente.md).
     *
     * ```jsonc
     * { "asignatura_id": 812 }   // el periodo es el del token, como en notas/detailed
     * ```
     *
     * ## A quién lista, y no es «todo el grupo»
     *
     * **A quien tiene un boletín aparte en esta asignatura**, que son dos casos y no
     * uno: los que van aparte **y los que tienen estructura propia guardada aunque el
     * periodo vaya con el grupo** (`aplica: false`). Los segundos son justo los que en
     * la planilla del curso llevan el badge `bol_independiente_datos`, así que las dos
     * pantallas hablan del mismo conjunto y **una no puede enseñar a alguien que la
     * otra no conozca**. Un alumno sin marca y sin nada suyo no sale: no hay nada que
     * gobernarle aquí.
     *
     * ## Sus unidades se leen por PROPIEDAD y NO por alcance — y ésta es la trampa
     *
     * `Unidad::deAsignatura()` resuelve el **alcance**, que para un `aplica: false`
     * devuelve **las del grupo**. Aquí eso sería lo contrario de lo que se pide: la §1
     * dice que al desmarcar *«no debe borrar los datos … pero esos datos deben ser
     * ignorados»*, y esta pantalla es precisamente **donde se ven los datos que se
     * están ignorando**. Con el alcance, un `aplica: false` saldría con la estructura
     * del curso pintada como si fuera suya, y el docente creería que su boletín aparte
     * se ha llenado solo.
     *
     * Así que la condición es `u.alumno_id = :alumno` —**afirmación de propiedad**— y no
     * `u.alumno_id <=> :alcance`. **Es el mismo predicado que `tiene_datos`**, y es el
     * segundo sitio del módulo donde `<=>` sería el error y no el acierto: `<=>`
     * contesta *«¿qué unidades le tocan?»* y aquí se pregunta *«¿cuáles son suyas?»*.
     * La regla completa está en la §1.6 del reparto de la noche, con este caso dentro.
     *
     * ## Un vacío dice POR QUÉ está vacío
     *
     * Lo pidió el front y la razón es suya: *«un vacío que no dice por qué se lee como
     * "no hay datos" cuando lo que hay es un fallo»*. Y **no se contesta 400 para decir
     * "no hay"**: los tres casos son estados legítimos y llegan en 200 con `motivo`.
     *
     * | `motivo` | Qué pasó |
     * |---|---|
     * | `vaciada` | tuvo unidades propias y hoy están **todas borradas**. Sólo se sabe mirando `deleted_at`, y es distinto de no haber tenido nunca |
     * | `asignatura_sin_montar` | **tampoco hay unidades del grupo**: el docente no ha entrado. No es culpa de la marca y les pasa igual a los treinta |
     * | `sin_estructura_propia` | el grupo sí las tiene y **este alumno no**. Es la §9.1 y es el único que la pantalla tiene que gritar |
     *
     * **`vaciada` se comprueba PRIMERO**, y el orden no es indiferente: es un hecho
     * sobre **este alumno**, mientras que `asignatura_sin_montar` es uno sobre la
     * asignatura. Al revés, el alumno al que alguien le vació el boletín en una
     * asignatura que además está sin montar saldría como «el docente no ha entrado», y
     * es exactamente lo contrario de lo que pasó.
     *
     * ## `porcentaje_unidades` se devuelve y NO se corrige
     *
     * Regla 2 de `DefinitivasDeAsignatura` y [10 §9.3](../../../docs/migracion/10-definitivas.md):
     * una estructura mal configurada da una definitiva rara y **que se note es lo que la
     * delata**. La pantalla lo pinta en rojo; el backend no lo arregla por detrás.
     */
    public function putPlanilla()
    {
        $asignaturaId = $this->idDelCuerpo('asignatura_id');
        $periodoId = (int) $this->user->periodo_id;

        // **El 404 de una asignatura de otro año NO se escribe aquí: lo tira
        // `detallada()`**, que une por el año del token y ya aborta con «Esa asignatura
        // no es de este año» (05 §16, arreglado el 19 ago). Comprobar otra vez aquí
        // sería un segundo sitio decidiendo lo mismo, y el día que los mensajes
        // discreparan nadie sabría cuál está viendo el colegio. Es 404 y no 403 porque
        // desde esta pantalla no hay forma de pedirla —el desplegable sale del año— y
        // decir «no tienes permiso» manda a buscar un permiso que no falta.
        //
        // Devuelve un **array**, no un objeto, y así viaja al JSON: se castea sólo para
        // leerle `grupo_id` aquí dentro. Cambiarlo movería la forma de la respuesta.
        $asignatura = Asignatura::detallada($asignaturaId, (int) $this->user->year_id);
        $grupoId = (int) ((object) $asignatura)->grupo_id;

        $periodo = DB::selectOne(
            'SELECT p.id, p.numero FROM periodos p WHERE p.id = ? AND p.deleted_at IS NULL',
            [$periodoId]
        );

        if ($periodo === null) {
            abort(404, 'El periodo de tu sesión ya no existe.');
        }

        // El reparto del GRUPO, una vez y no por alumno: los tres `motivo` lo comparan y
        // no cambia entre filas.
        $unidadesDelGrupo = (int) DB::selectOne(
            'SELECT COUNT(*) c FROM unidades
              WHERE asignatura_id = ? AND periodo_id = ? AND alumno_id IS NULL AND deleted_at IS NULL',
            [$asignaturaId, $periodoId]
        )->c;

        return [
            'asignatura' => $asignatura,
            'periodo' => ['periodo_id' => (int) $periodo->id, 'numero' => (int) $periodo->numero],
            'alumnos' => $this->alumnosConBoletinAparte($grupoId, $asignaturaId, $periodoId, $unidadesDelGrupo),
            'estructura_del_grupo' => $this->estructuraDelGrupo($asignaturaId),
        ];
    }

    /**
     * Los que tienen boletín aparte en esta asignatura, con su estructura dentro.
     *
     * **Una consulta para saber quiénes son**, no una por alumno del grupo: entra por
     * `matriculas` del grupo de la asignatura y se queda con los que tienen fila de
     * marca **o** unidad propia viva. Las dos mitades del `OR` son las dos que la
     * pantalla gobierna.
     *
     * @return list<array<string, mixed>>
     */
    private function alumnosConBoletinAparte(int $grupoId, int $asignaturaId, int $periodoId, int $unidadesDelGrupo): array
    {
        $filas = DB::select(
            'SELECT DISTINCT a.id AS alumno_id, a.nombres, a.apellidos, a.foto_id,
                    IFNULL(i.nombre, IF(a.sexo = "F", "default_female.png", "default_male.png")) AS foto_nombre,
                    IF(COALESCE(bip.aplica, 0) = 1, 1, 0) AS aplica
               FROM matriculas m
               INNER JOIN alumnos a ON a.id = m.alumno_id AND a.deleted_at IS NULL
               LEFT JOIN images i ON i.id = a.foto_id AND i.deleted_at IS NULL
               LEFT JOIN bol_ind_periodos bip ON bip.alumno_id = a.id AND bip.periodo_id = ?
              WHERE m.grupo_id = ? AND m.deleted_at IS NULL
                AND m.estado IN ("MATR", "ASIS", "PREM")
                AND (COALESCE(bip.aplica, 0) = 1
                     OR EXISTS (SELECT 1 FROM unidades u
                                 WHERE u.alumno_id = a.id AND u.periodo_id = ?
                                   AND u.asignatura_id = ? AND u.deleted_at IS NULL))
              ORDER BY a.apellidos, a.nombres',
            [$periodoId, $grupoId, $periodoId, $asignaturaId]
        );

        $salida = [];

        foreach ($filas as $fila) {
            $alumnoId = (int) $fila->alumno_id;

            $alumno = [
                'alumno_id' => $alumnoId,
                'nombres' => $fila->nombres,
                'apellidos' => $fila->apellidos,
                'foto_id' => $fila->foto_id === null ? null : (int) $fila->foto_id,
                'foto_nombre' => $fila->foto_nombre,
                'aplica' => (bool) $fila->aplica,
                'porcentaje_unidades' => DefinitivasDeAsignatura::porcentajeDeLasUnidades($asignaturaId, $periodoId, $alumnoId),
                'definitiva' => $this->definitivaDe($alumnoId, $asignaturaId, $periodoId),
                'unidades' => $this->unidadesPropias($alumnoId, $asignaturaId, $periodoId),
            ];

            if ($alumno['unidades'] === []) {
                $alumno['motivo'] = $this->motivoDelVacio($alumnoId, $asignaturaId, $periodoId, $unidadesDelGrupo);
            }

            $salida[] = $alumno;
        }

        return $salida;
    }

    /**
     * Las unidades **propias** de un alumno, con sus subunidades y la nota de cada una.
     *
     * `u.alumno_id = ?` y no `<=>` — ver el docblock de `putPlanilla()`: aquí se
     * pregunta de quién SON, no cuáles le tocan.
     *
     * @return list<array<string, mixed>>
     */
    private function unidadesPropias(int $alumnoId, int $asignaturaId, int $periodoId): array
    {
        // Una consulta para las unidades y sus subunidades con la nota dentro, en vez de
        // una por unidad: son treinta alumnos por pantalla y el patrón de este módulo ya
        // costó once consultas por boletín impreso.
        //
        // El `LEFT JOIN` de `notas` es `LEFT` a propósito: una subunidad recién creada no
        // tiene fila todavía, y esta ruta **no siembra** —lee—, así que la casilla viaja
        // con `nota: null` y la pantalla la pinta vacía en vez de perderse la subunidad.
        $filas = DB::select(
            'SELECT u.id AS unidad_id, u.definicion AS definicion_unidad, u.porcentaje AS porcentaje_unidad, u.orden AS orden_unidad,
                    s.id AS subunidad_id, s.definicion AS definicion_subunidad, s.porcentaje AS porcentaje_subunidad,
                    s.orden AS orden_subunidad, s.nota_default,
                    n.id AS nota_id, n.nota
               FROM unidades u
               LEFT JOIN subunidades s ON s.unidad_id = u.id AND s.deleted_at IS NULL
               LEFT JOIN notas n ON n.subunidad_id = s.id AND n.alumno_id = ? AND n.deleted_at IS NULL
              WHERE u.asignatura_id = ? AND u.periodo_id = ? AND u.alumno_id = ? AND u.deleted_at IS NULL
              ORDER BY u.orden, u.id, s.orden, s.id',
            [$alumnoId, $asignaturaId, $periodoId, $alumnoId]
        );

        $unidades = [];

        foreach ($filas as $fila) {
            $unidadId = (int) $fila->unidad_id;

            if (! isset($unidades[$unidadId])) {
                $unidades[$unidadId] = [
                    'unidad_id' => $unidadId,
                    'definicion' => $fila->definicion_unidad,
                    'porcentaje' => (int) $fila->porcentaje_unidad,
                    'orden' => (int) $fila->orden_unidad,
                    'subunidades' => [],
                ];
            }

            // Una unidad sin subunidades vivas llega con `subunidad_id` a NULL por el
            // `LEFT JOIN`, y tiene que salir **con la lista vacía y no desaparecer**: es
            // una unidad que suma porcentaje y no tiene dónde poner nota, o sea la mitad
            // de un boletín mal montado. Esconderla dejaría la suma sin explicación.
            if ($fila->subunidad_id === null) {
                continue;
            }

            $unidades[$unidadId]['subunidades'][] = [
                'subunidad_id' => (int) $fila->subunidad_id,
                'definicion' => $fila->definicion_subunidad,
                'porcentaje' => (int) $fila->porcentaje_subunidad,
                'orden' => (int) $fila->orden_subunidad,
                'nota' => $fila->nota_id === null
                    ? null
                    : ['id' => (int) $fila->nota_id, 'nota' => (int) $fila->nota],
            ];
        }

        return array_values($unidades);
    }

    /**
     * La definitiva guardada de ese alumno en esa asignatura y periodo, o `null`.
     *
     * **`CAST(... AS DOUBLE)`, y no es adorno.** `notas_finales.nota` es `DECIMAL(7,4)`
     * desde el 30 ago 2026 y **PDO devuelve un `DECIMAL` como cadena**: sin el cast este
     * campo saldría `"78.0000"` donde las otras diecisiete respuestas del sistema mandan
     * un número. Es exactamente lo que costó veinte instantáneas aquella noche.
     *
     * @return array<string, mixed>|null
     */
    private function definitivaDe(int $alumnoId, int $asignaturaId, int $periodoId): ?array
    {
        $fila = DB::selectOne(
            'SELECT CAST(nf.nota AS DOUBLE) AS nota, nf.manual, nf.recuperada
               FROM notas_finales nf
              WHERE nf.alumno_id = ? AND nf.asignatura_id = ? AND nf.periodo_id = ?
              ORDER BY nf.id DESC LIMIT 1',
            [$alumnoId, $asignaturaId, $periodoId]
        );

        // `ORDER BY id DESC LIMIT 1` es una degradación consciente y no un descuido:
        // `notas_finales` **no tiene clave única** sobre (alumno, asignatura, periodo)
        // —es el 10-definitivas.md, de donde salen las definitivas duplicadas— así que
        // puede haber dos. Se elige la última escrita, que es lo que hacen las demás
        // lecturas; el día que la clave única entre, este `LIMIT` sobra y no estorba.
        if ($fila === null) {
            return null;
        }

        return [
            'nota' => (float) $fila->nota,
            'manual' => (bool) $fila->manual,
            'recuperada' => (bool) $fila->recuperada,
        ];
    }

    /** Por qué está vacía la lista de unidades de un alumno. Ver `putPlanilla()`. */
    private function motivoDelVacio(int $alumnoId, int $asignaturaId, int $periodoId, int $unidadesDelGrupo): string
    {
        $vaciadas = (int) DB::selectOne(
            'SELECT COUNT(*) c FROM unidades
              WHERE alumno_id = ? AND asignatura_id = ? AND periodo_id = ? AND deleted_at IS NOT NULL',
            [$alumnoId, $asignaturaId, $periodoId]
        )->c;

        if ($vaciadas > 0) {
            return 'vaciada';
        }

        return $unidadesDelGrupo === 0 ? 'asignatura_sin_montar' : 'sin_estructura_propia';
    }

    /**
     * El recuento de la estructura **del grupo** por periodo del año, para la vista
     * previa del diálogo de copiar.
     *
     * ## Existe porque la alternativa está envenenada
     *
     * Con `origen.tipo: "grupo"` la única otra fuente sería
     * `GET unidades/de-asignatura-periodo/{asignatura}/{periodo}`, **y esa ruta
     * escribe**: si esa asignatura no tiene unidades en ese periodo y quien mira puede
     * editar, **inserta las unidades y subunidades por defecto del año** —y las inserta
     * **sin `alumno_id`**, o sea del grupo—, y `Unidad::arreglarOrden` reescribe `orden`
     * en cada lectura. **Una vista previa montaría el periodo entero del curso.**
     *
     * **Y esa ruta no se cambia**: que lea y escriba es decisión tomada
     * ([05 §47.2](../../../docs/migracion/05-codigo-muerto-y-roto.md), Joseth) y con el
     * periodo abierto crea queriendo. Lo que se arregla es que el front no tenga que
     * llamarla.
     *
     * `porcentaje_unidades` lleva **el mismo nombre y el mismo número** que el de cada
     * alumno de esta respuesta, para que la pantalla no tenga dos campos que significan
     * lo mismo. Sale del mismo método, con `null` de alcance, que es «el boletín del
     * grupo».
     *
     * @return list<array<string, mixed>>
     */
    private function estructuraDelGrupo(int $asignaturaId): array
    {
        // Los cuatro periodos del año y su recuento, en una consulta. Cuenta las
        // unidades **del grupo** (`u.alumno_id IS NULL`), que es lo que el diálogo va a
        // copiar; contar las de todo el mundo diría «se van a copiar 12 unidades» cuando
        // se van a copiar 4.
        $filas = DB::select(
            'SELECT p.id AS periodo_id, p.numero,
                    COUNT(DISTINCT u.id) AS unidades,
                    COUNT(s.id) AS subunidades
               FROM periodos p
               LEFT JOIN unidades u ON u.periodo_id = p.id AND u.asignatura_id = ?
                                   AND u.alumno_id IS NULL AND u.deleted_at IS NULL
               LEFT JOIN subunidades s ON s.unidad_id = u.id AND s.deleted_at IS NULL
              WHERE p.year_id = ? AND p.deleted_at IS NULL
              GROUP BY p.id, p.numero
              ORDER BY p.numero, p.id',
            [$asignaturaId, $this->user->year_id]
        );

        return array_values(array_map(fn ($f) => [
            'periodo_id' => (int) $f->periodo_id,
            'numero' => (int) $f->numero,
            'unidades' => (int) $f->unidades,
            'subunidades' => (int) $f->subunidades,
            'porcentaje_unidades' => DefinitivasDeAsignatura::porcentajeDeLasUnidades($asignaturaId, (int) $f->periodo_id, null),
        ], $filas));
    }

    /**
     * `PUT boletin-independiente/marcados` — **quién lleva boletín aparte en un
     * periodo, y a quién se le está quedando sin montar.** §13 del
     * [19](../../../docs/migracion/19-boletin-independiente.md).
     *
     * ```jsonc
     * { "periodo_id": 30 }
     * ```
     *
     * ## Existe porque hoy esa pregunta NO tiene forma de contestarse
     *
     * La marca vive en `bol_ind_periodos` y lo único que la asoma al cliente es
     * `independientes`, **dentro de `PUT notas/detailed`, que es por asignatura**. Para
     * saber quién está marcado en un colegio había que barrerlo asignatura por
     * asignatura, y ésa es exactamente la pantalla que el front no podía construir.
     *
     * ## Los cuatro recuentos son la fila entera, y el denominador es la trampa
     *
     * `asignaturas` / `montadas` / `sin_unidades` / (`notas_puestas`, `notas_totales`).
     * Sin ellos hay que entrar en cada estudiante para saber si le falta algo, y
     * entonces la lista no sirve para lo que existe.
     *
     * **Y el denominador son TODAS sus asignaturas, no las que alguien tocó.** Es la
     * decisión 1 —la marca vale para todas— leída hasta el final: marcado en el periodo
     * 2, un alumno de trece materias va aparte **en las trece**, así que las que nadie
     * le montó son `sin_unidades` y son el riesgo. El diseño que llegó del front contaba
     * *«4 de 5»* sobre trece; **habría subestimado la §9.1 en ocho**, que es la única
     * dirección en la que esta pantalla no puede equivocarse: quien lee «1 sin unidades»
     * arregla una y se va.
     *
     * ## `notas_puestas` es `updated_by`, y NO `nota > 0` — medido
     *
     * `notas.nota` es `int NOT NULL DEFAULT 0` y la fila **nace sembrada** con
     * `subunidades.nota_default`, así que ni «existe la fila» ni «vale más que cero»
     * contestan *«¿la calificó alguien?»*. Sobre las **1.166.138 notas vivas** de
     * `simonbolivar` (1 sep 2026):
     *
     * | | Filas |
     * |---|---|
     * | `updated_by IS NOT NULL` — alguien la tecleó | **1.046.033** |
     * | sin `updated_by` y `nota = 0` — sembrada y sin calificar | **98.402** |
     * | sin `updated_by` y `nota > 0` — sembrada con un default ≠ 0 y nunca tocada | **21.703** |
     * | con `updated_by` y `nota = 0` — **un cero tecleado queriendo** | **3.939** |
     *
     * **`nota > 0` etiquetaría mal 25.642 filas en un solo colegio, y en las dos
     * direcciones.** Las últimas 3.939 son el §4 del [10](../../../docs/migracion/10-definitivas.md)
     * del revés: un cero real leído como «no hay nada».
     *
     * **Es un proxy y se dice en voz alta:** se sostiene en que la siembra
     * (`Nota::verificarCrearNota`, `sembrarLasNotasQueFaltan`) **no** escribe
     * `updated_by` y en que las dos escrituras de nota —`PUT notas/update/{id}` y
     * `notas/lote`— **sí**. El día que un tercer camino escriba una nota sin firmarla,
     * este recuento miente y no hay nada que lo avise.
     *
     * ## `sin_casilla` no es «falta la nota», y por eso viaja aparte
     *
     * Una subunidad **sin fila de `notas`** no se puede teclear: sin `id` no hay
     * `PUT notas/update/{id}` al que llamar y nadie la crea después. Se llega ahí
     * copiando con `con_notas: false`. Mezclarla con las que faltan por calificar
     * mandaría a arreglarla al sitio equivocado —al teclado, en vez de a copiar con
     * notas o al alta de la subunidad—, así que es su propio número.
     *
     * ## Quién sale en la lista: los MARCADOS, y no «los que tienen datos»
     *
     * Sólo `aplica = 1`. El otro estado real —desmarcado con estructura propia
     * guardada, el `false`/`true` de la §6.4— **no entra**: la pregunta de esta
     * pantalla es *«¿quién va aparte este periodo y lo tiene listo?»*, y meter a quien
     * no va aparte diluiría el único aviso que la pantalla existe para dar. Ese estado
     * ya se ve donde tiene sentido, que es la ficha.
     *
     * ## `sin_matricula`, para que nadie desaparezca en silencio
     *
     * Un marcado sin matrícula viva en el año del periodo **no tiene grupo, luego no
     * tiene asignaturas, luego su fila no diría nada** — y se cae del `INNER JOIN`. Que
     * se caiga está bien; que se caiga **sin decirlo** es la forma de fallo de este
     * repo. Viaja el recuento: si es distinto de cero, alguien tiene una marca colgada
     * de un año en el que ese alumno no está.
     */
    public function putMarcados()
    {
        $periodoId = $this->idDelCuerpo('periodo_id');

        // La guarda **devuelve** el periodo, y por eso aquí no hay una segunda consulta ni
        // un `if ($periodo === null)` detrás: una rama muerta que nadie puede ejecutar es
        // una rama sobre la que alguien ramificará algún día sin que se note.
        $periodo = $this->exigirPeriodoDelAnio($periodoId, 'periodo_id');

        $yearId = (int) $this->user->year_id;
        $profesorId = $this->profesorDelAlcance();

        $filas = DB::select(
            'SELECT a.id AS alumno_id, a.nombres, a.apellidos, a.foto_id,
                    IFNULL(i.nombre, IF(a.sexo = "F", "default_female.png", "default_male.png")) AS foto_nombre,
                    g.id AS grupo_id, g.nombre AS nombre_grupo
               FROM bol_ind_periodos bip
               INNER JOIN alumnos a ON a.id = bip.alumno_id AND a.deleted_at IS NULL
               LEFT JOIN images i ON i.id = a.foto_id AND i.deleted_at IS NULL
               INNER JOIN matriculas m ON m.id = '.self::MATRICULA_DEL_ANIO.'
               INNER JOIN grupos g ON g.id = m.grupo_id
              WHERE bip.periodo_id = ? AND bip.aplica = 1
              ORDER BY a.apellidos, a.nombres, a.id',
            [$yearId, $periodoId]
        );

        $marcados = (int) DB::selectOne(
            'SELECT COUNT(*) c FROM bol_ind_periodos bip
               INNER JOIN alumnos a ON a.id = bip.alumno_id AND a.deleted_at IS NULL
              WHERE bip.periodo_id = ? AND bip.aplica = 1',
            [$periodoId]
        )->c;

        $porAsignatura = $this->asignaturasDeLosMarcados($periodoId, $yearId);
        $porNotas = $this->notasDeLosMarcados($periodoId, array_map(static fn ($f) => (int) $f->alumno_id, $filas));

        $alumnos = [];

        foreach ($filas as $fila) {
            $alumnoId = (int) $fila->alumno_id;
            $suyas = $porAsignatura[$alumnoId] ?? [];

            // El alcance se aplica **aquí y no en la consulta** para poder mandar las dos
            // cifras: `asignaturas` es lo que ve quien pregunta y `asignaturas_del_alumno`
            // el total. Es la misma razón por la que el detalle manda las dos — sin el
            // total, un docente con una sola materia cree que el estudiante sólo tiene una
            // y lo da por terminado.
            $enAlcance = $profesorId === null
                ? $suyas
                : array_values(array_filter($suyas, static fn ($a) => $a['profesor_id'] === $profesorId));

            // Un docente que no le da ninguna materia a este alumno no lo ve. No es un
            // filtro cosmético: su lista dice «ninguno de TUS estudiantes», que no es lo
            // mismo que «ninguno», y esa distinción se pierde si la fila llega vacía.
            if ($profesorId !== null && $enAlcance === []) {
                continue;
            }

            $montadas = count(array_filter($enAlcance, static fn ($a) => $a['propias'] > 0));

            // Las casillas se cuentan **sobre el mismo conjunto que el denominador**: el grupo
            // que salió del desempate, y las materias de quien pregunta si el alcance recorta.
            $grupoId = (int) $fila->grupo_id;
            $notas = array_filter(
                $porNotas[$alumnoId] ?? [],
                static fn ($n) => $n['grupo_id'] === $grupoId
                    && ($profesorId === null || $n['profesor_id'] === $profesorId)
            );

            $alumnos[] = [
                'alumno_id' => $alumnoId,
                'nombres' => $fila->nombres,
                'apellidos' => $fila->apellidos,
                'foto_id' => $fila->foto_id === null ? null : (int) $fila->foto_id,
                'foto_nombre' => $fila->foto_nombre,
                'grupo_id' => (int) $fila->grupo_id,
                'nombre_grupo' => $fila->nombre_grupo,
                'asignaturas' => count($enAlcance),
                'asignaturas_del_alumno' => count($suyas),
                'montadas' => $montadas,
                'sin_unidades' => count($enAlcance) - $montadas,
                'notas_puestas' => (int) array_sum(array_column($notas, 'puestas')),
                'notas_totales' => (int) array_sum(array_column($notas, 'totales')),
                'sin_casilla' => (int) array_sum(array_column($notas, 'sin_casilla')),
            ];
        }

        return [
            'periodo' => ['periodo_id' => (int) $periodo->id, 'numero' => (int) $periodo->numero],
            'alcance' => $profesorId === null ? 'todas' : 'mias',
            'alumnos' => $alumnos,
            // Los marcados que no llegaron a la lista. Ver el docblock: cero es lo normal.
            'sin_matricula' => $marcados - count($filas),
        ];
    }

    /**
     * `PUT boletin-independiente/alumno` — **un alumno, un periodo, y todas sus
     * asignaturas** con unidades, notas, definitiva y faltas. §13 del
     * [19](../../../docs/migracion/19-boletin-independiente.md).
     *
     * ```jsonc
     * { "alumno_id": 3311, "periodo_id": 30 }
     * ```
     *
     * Es `putPlanilla()` con **los ejes cambiados**: allí una asignatura con sus alumnos
     * marcados, aquí un alumno con sus asignaturas. Los nombres de campo son los mismos
     * a propósito —`motivo`, `porcentaje_unidades`, `unidades`, `definitiva`—: dos campos
     * que significan lo mismo con dos nombres acaban divergiendo, y este módulo ya lo
     * pagó con `bol_independiente_periodo` / `_periodos` / `_aparte_en` / `_datos`.
     *
     * ## `aplica` va en el PERIODO y **no en cada asignatura**, y esto es lo que corrigió el diseño
     *
     * El diseño que llegó del front traía `aplica` por asignatura y una fila gris *«Con
     * el grupo»* dentro de un periodo marcado. **Ese estado no existe**: la marca cuelga
     * de `(alumno_id, periodo_id)` —sin `asignatura_id`— y la decisión 1 dice que vale
     * para **todas** las asignaturas. Un `aplica` por asignatura llegaría **constante**,
     * que es el fallo que el propio front cazó el 31 ago en `bol_independiente_periodo`
     * y en `aplica` dentro de `independientes` (§6.4): *«un campo que no varía no es un
     * campo pobre, es un campo que miente por omisión»*.
     *
     * **Y aquí la rama muerta hacía daño, que es lo que lo separa de las otras dos.** Si
     * el gris no puede venir de `aplica`, la pantalla lo deriva de lo único que varía
     * —`unidades: []`— y entonces **la asignatura sin estructura propia, la que va a
     * sacar definitiva cero, se pinta gris y tranquila**: el estado más peligroso con el
     * color del más calmado. Lo que varía por asignatura es `motivo`, que ya existe y ya
     * distingue los tres casos.
     *
     * ## El grupo del alumno se DESEMPATA, y la respuesta dice cuál salió
     *
     * `matriculas` **no tiene clave única sobre (alumno, grupo)** —de ahí venía el
     * `LIMIT 1` que la decisión 7 pudo por fin quitar de `alcanceCorrelacionado()`— así
     * que dentro de un año puede haber empate. El periodo fija el año; dentro de él se
     * toma **la matrícula viva de `id` mayor**, que es el mismo desempate que
     * `definitivaDe()` hace con `notas_finales` y por el mismo motivo: elegir la última
     * escrita en vez de reventar. **`alumno.grupo_id` viaja siempre**, así que el día que
     * un colegio tenga a alguien con dos matrículas del mismo año se ve cuál se eligió,
     * en vez de quedar en que la pantalla «salió rara».
     *
     * ## `asignaturas_del_alumno` es el total AUNQUE el alcance sea `mias`
     *
     * El front escribe «ves 3 de las 13 asignaturas». Sin el total, un docente con una
     * sola materia cree que el estudiante sólo tiene una y lo da por terminado.
     *
     * ## Las faltas van DENTRO de la asignatura, y no se reutiliza `ausencias/detailed`
     *
     * Una falta es *a esa clase*: cuelga de la asignatura y del periodo. Y
     * `GET ausencias/detailed/{asignatura_id}` **no sirve tal cual** por dos razones
     * medidas: devuelve el **grupo entero** con un `Alumno::userData` por cabeza, y
     * filtra por **`$user->periodo_id`** —el del token, no el que se pide—, que es
     * justamente el periodo que esta pantalla casi nunca está mirando.
     *
     * **`tipo` es el único discriminador que hay.** Contados los vivos de `simonbolivar`
     * el 1 sep 2026: `ausencia` 44.393 y `tardanza` 2.077, **y nada más**. No hay columna
     * de excusa en `ausencias` —el `excusado` del esquema es de `uniformes`, otra tabla—
     * así que el `con_excusa` que pedía el diseño **no lo puede contestar nadie hoy** y
     * salió del contrato: un `excusa: false` constante habría sido la cuarta constante de
     * este módulo en dos semanas, y la primera con una migración detrás. Si algún día
     * apareciera un `tipo` distinto de los dos, la falta saldría en `detalle` y **en
     * ninguno de los dos recuentos**; se ve comparando con el tamaño de `detalle`.
     *
     * ## Por qué NO se reutiliza `piars-asignaturas/asignaturas/{grupo}/{alumno}`
     *
     * Es la que parece hecha a medida y es la trampa de siempre: ese `GET` **escribe** —
     * `getCreatePiarAsignatura` **dentro del `for`** crea la fila de PIAR de cada
     * asignatura al listar—. Misma familia que `GET unidades/de-asignatura-periodo`, que
     * monta el periodo del curso al leerlo (05 §47.2).
     */
    public function putAlumno()
    {
        $alumnoId = $this->idDelCuerpo('alumno_id');
        $periodoId = $this->idDelCuerpo('periodo_id');
        $periodo = $this->exigirPeriodoDelAnio($periodoId, 'periodo_id');

        $yearId = (int) $this->user->year_id;
        $profesorId = $this->profesorDelAlcance();

        $alumno = DB::selectOne(
            'SELECT a.id AS alumno_id, a.nombres, a.apellidos, a.foto_id,
                    IFNULL(i.nombre, IF(a.sexo = "F", "default_female.png", "default_male.png")) AS foto_nombre,
                    g.id AS grupo_id, g.nombre AS nombre_grupo
               FROM alumnos a
               LEFT JOIN images i ON i.id = a.foto_id AND i.deleted_at IS NULL
               INNER JOIN matriculas m ON m.id = '.self::MATRICULA_DEL_ANIO.'
               INNER JOIN grupos g ON g.id = m.grupo_id
              WHERE a.id = ? AND a.deleted_at IS NULL',
            [$yearId, $alumnoId]
        );

        // 404 y no una lista vacía: un alumno sin matrícula viva en el año de ese periodo
        // **no tiene grupo**, así que no hay asignaturas que enseñar y un `[]` se leería
        // como «no tiene nada montado», que es otra cosa y es la que asusta.
        if ($alumno === null) {
            abort(404, 'Ese alumno no tiene matrícula en el año de ese periodo.');
        }

        // Los dos estados del periodo para ESTE alumno, que son los mismos dos que la ficha
        // enseña por `bol_independiente_periodos` (§6.4) aplanados a uno solo. `aplica` sale
        // con `COALESCE(..., 0)` porque **la fila que falta significa «va con el grupo»** —la
        // decisión 7—, y `tiene_datos` es el `EXISTS` sobre `unidades` que el navegador no
        // puede contestar: los cuatro cruces de `aplica` × `tiene_datos` significan cosas
        // distintas, y el que hay que gritar es `true`/`false`.
        $estado = DB::selectOne(
            'SELECT IF(COALESCE(bip.aplica, 0) = 1, 1, 0) AS aplica,
                    EXISTS (SELECT 1 FROM unidades u
                             WHERE u.alumno_id = ? AND u.periodo_id = ? AND u.deleted_at IS NULL) AS tiene_datos
               FROM (SELECT 1) x
               LEFT JOIN bol_ind_periodos bip ON bip.alumno_id = ? AND bip.periodo_id = ?',
            [$alumnoId, $periodoId, $alumnoId, $periodoId]
        );

        $todas = DB::select(
            'SELECT asg.id AS asignatura_id, asg.profesor_id, asg.orden,
                    mat.materia, mat.alias AS alias_materia,
                    pro.nombres AS nombres_profesor, pro.apellidos AS apellidos_profesor
               FROM asignaturas asg
               INNER JOIN materias mat ON mat.id = asg.materia_id AND mat.deleted_at IS NULL
               LEFT JOIN profesores pro ON pro.id = asg.profesor_id AND pro.deleted_at IS NULL
              WHERE asg.grupo_id = ? AND asg.deleted_at IS NULL
              ORDER BY asg.orden, mat.materia, mat.alias, asg.id',
            [(int) $alumno->grupo_id]
        );

        // `profesores` entra por `LEFT JOIN` y no por `INNER` —al revés que
        // `Asignatura::detallada()`—: `asignaturas.profesor_id` es NULLABLE, y una materia
        // sin docente asignado **es un caso real al empezar el año**. Con `INNER` esa
        // asignatura desaparecería de la lista, que es justo lo que esta pantalla no puede
        // hacer: la que no se ve es la que nadie monta.
        $enAlcance = $profesorId === null
            ? $todas
            : array_values(array_filter($todas, static fn ($a) => (int) $a->profesor_id === $profesorId));

        $ids = array_map(static fn ($a) => (int) $a->asignatura_id, $enAlcance);

        $unidades = $this->unidadesPropiasDeVarias($alumnoId, $ids, $periodoId);
        $definitivas = $this->definitivasDeVarias($alumnoId, $ids, $periodoId);
        $delGrupo = $this->unidadesDelGrupoDeVarias($ids, $periodoId);
        $vaciadas = $this->vaciadasDeVarias($alumnoId, $ids, $periodoId);
        $faltas = $this->faltasDeVarias($alumnoId, $ids, $periodoId);

        $asignaturas = [];

        foreach ($enAlcance as $fila) {
            $asignaturaId = (int) $fila->asignatura_id;

            $asignatura = [
                'asignatura_id' => $asignaturaId,
                'materia' => $fila->materia,
                'alias_materia' => $fila->alias_materia,
                'profesor_id' => $fila->profesor_id === null ? null : (int) $fila->profesor_id,
                'nombres_profesor' => $fila->nombres_profesor,
                'apellidos_profesor' => $fila->apellidos_profesor,
                // `porcentaje_unidades` sale del MISMO método que la planilla y que el
                // recalculador —`DefinitivasDeAsignatura`—, y se devuelve **sin corregir**:
                // regla 2 de ese servicio y §9.3 del 10. Cuesta una consulta por asignatura y
                // se paga: un segundo sitio calculando el reparto es de donde salió el
                // recalculador único.
                'porcentaje_unidades' => DefinitivasDeAsignatura::porcentajeDeLasUnidades($asignaturaId, $periodoId, $alumnoId),
                'definitiva' => $definitivas[$asignaturaId] ?? null,
                'unidades' => $unidades[$asignaturaId] ?? [],
                'faltas' => $faltas[$asignaturaId] ?? ['ausencias' => 0, 'tardanzas' => 0, 'detalle' => []],
            ];

            if ($asignatura['unidades'] === []) {
                $asignatura['motivo'] = ($vaciadas[$asignaturaId] ?? 0) > 0
                    ? 'vaciada'
                    : (($delGrupo[$asignaturaId] ?? 0) === 0 ? 'asignatura_sin_montar' : 'sin_estructura_propia');
            }

            $asignaturas[] = $asignatura;
        }

        return [
            'alumno' => [
                'alumno_id' => (int) $alumno->alumno_id,
                'nombres' => $alumno->nombres,
                'apellidos' => $alumno->apellidos,
                'foto_id' => $alumno->foto_id === null ? null : (int) $alumno->foto_id,
                'foto_nombre' => $alumno->foto_nombre,
                'grupo_id' => (int) $alumno->grupo_id,
                'nombre_grupo' => $alumno->nombre_grupo,
            ],
            'periodo' => [
                'periodo_id' => (int) $periodo->id,
                'numero' => (int) $periodo->numero,
                'aplica' => (bool) $estado->aplica,
                'tiene_datos' => (bool) $estado->tiene_datos,
            ],
            'alcance' => $profesorId === null ? 'todas' : 'mias',
            'asignaturas_del_alumno' => count($todas),
            'asignaturas' => $asignaturas,
        ];
    }

    /**
     * La matrícula del alumno en el año, **desempatada**, como subconsulta escalar.
     *
     * Se usa con `INNER JOIN matriculas m ON m.id = ` delante, y espera dos cosas en el
     * ámbito: `a.id` (el alumno) y un `?` con el `year_id` **antes** de los demás
     * parámetros de la consulta.
     *
     * `MAX(m2.id)` y no `LIMIT 1`: `matriculas` no tiene clave única sobre
     * (alumno, grupo) —§9.5— y nada impide dos filas vivas del mismo alumno en el mismo
     * año. Se elige la última matriculada, que es el mismo criterio con el que
     * `definitivaDe()` desempata `notas_finales`. Los tres estados son los de
     * `alumnosConBoletinAparte()`, no los dos de `delGrupo()`: quien está `PREM`
     * —promovido -- sigue teniendo boletín de este periodo.
     */
    private const MATRICULA_DEL_ANIO =
        '(SELECT MAX(m2.id) FROM matriculas m2
            INNER JOIN grupos g2 ON g2.id = m2.grupo_id AND g2.deleted_at IS NULL AND g2.year_id = ?
           WHERE m2.alumno_id = a.id AND m2.deleted_at IS NULL
             AND m2.estado IN ("MATR", "ASIS", "PREM"))';

    /**
     * `null` si quien llama ve todas las asignaturas; su `profesores.id` si sólo las suyas.
     *
     * ## El precedente existía y estaba ROTO para este uso, y por eso no se reutiliza
     *
     * `PiarsAsignaturasController::getAsignaturas($grupo_id, $alumno_id)` hace este mismo
     * reparto, y en la rama del docente llama a `Profesor::asignaturas($year_id,
     * $persona_id)` — que filtra por `profesor_id` y `year_id` y **nunca por el grupo**:
     * el `$grupo_id` del argumento **no se usa en esa rama**. Un docente de cinco grupos
     * recibe hoy, mirando la ficha de un alumno de 8-B, sus materias de los cinco.
     *
     * Aquí `mias` es la **intersección**: se devuelve el id y el filtro se aplica sobre
     * las asignaturas **del grupo del alumno**, que es de donde salen las dos listas.
     */
    private function profesorDelAlcance(): ?int
    {
        return $this->user->tipo === 'Profesor' ? (int) $this->user->persona_id : null;
    }

    /**
     * Por cada marcado del periodo, sus asignaturas y cuántas unidades propias tiene en
     * cada una. **Una consulta para toda la lista**, no una por alumno.
     *
     * Es el mismo salto de grano que ya justificó `BoletinIndependiente::aparteEnPorAlumno()`
     * frente a `delGrupo()`: la alternativa es *(marcados × asignaturas)* consultas sobre
     * una pantalla que se abre para mirar el colegio entero.
     *
     * @return array<int, list<array{asignatura_id: int, profesor_id: ?int, propias: int}>>
     */
    private function asignaturasDeLosMarcados(int $periodoId, int $yearId): array
    {
        $filas = DB::select(
            'SELECT bip.alumno_id, asg.id AS asignatura_id, asg.profesor_id,
                    (SELECT COUNT(*) FROM unidades u
                      WHERE u.asignatura_id = asg.id AND u.periodo_id = bip.periodo_id
                        AND u.alumno_id = bip.alumno_id AND u.deleted_at IS NULL) AS propias
               FROM bol_ind_periodos bip
               INNER JOIN alumnos a ON a.id = bip.alumno_id AND a.deleted_at IS NULL
               INNER JOIN matriculas m ON m.id = '.self::MATRICULA_DEL_ANIO.'
               INNER JOIN asignaturas asg ON asg.grupo_id = m.grupo_id AND asg.deleted_at IS NULL
              WHERE bip.periodo_id = ? AND bip.aplica = 1',
            [$yearId, $periodoId]
        );

        $mapa = [];

        foreach ($filas as $fila) {
            $mapa[(int) $fila->alumno_id][] = [
                'asignatura_id' => (int) $fila->asignatura_id,
                'profesor_id' => $fila->profesor_id === null ? null : (int) $fila->profesor_id,
                'propias' => (int) $fila->propias,
            ];
        }

        return $mapa;
    }

    /**
     * Por cada marcado, el recuento de casillas de **sus** unidades, partido por docente
     * y por grupo para que el alcance y el desempate se apliquen sin repetir la consulta.
     *
     * `puestas` es `updated_by IS NOT NULL` y `sin_casilla` es la subunidad **sin fila**
     * de nota. El porqué de los dos, medido, está en el docblock de `putMarcados()`.
     *
     * **`grupo_id` viaja porque si no, los dos recuentos de una fila se contradicen.** Un
     * alumno con dos matrículas vivas del mismo año —que `matriculas` no impide, §9.5—
     * puede tener unidades propias en asignaturas de los dos grupos: `asignaturas` cuenta
     * las del grupo **desempatado** y esto contaría las de los dos, así que `notas_totales`
     * saldría de un conjunto más grande que su propio denominador **sin que nada lo diga**.
     *
     * @param  list<int>  $alumnoIds
     * @return array<int, list<array{profesor_id: ?int, grupo_id: int, totales: int, puestas: int, sin_casilla: int}>>
     */
    private function notasDeLosMarcados(int $periodoId, array $alumnoIds): array
    {
        if ($alumnoIds === []) {
            return [];
        }

        $huecos = implode(',', array_fill(0, count($alumnoIds), '?'));

        $filas = DB::select(
            'SELECT u.alumno_id, asg.profesor_id, asg.grupo_id,
                    COUNT(s.id) AS totales,
                    SUM(n.id IS NOT NULL AND n.updated_by IS NOT NULL) AS puestas,
                    SUM(n.id IS NULL) AS sin_casilla
               FROM unidades u
               INNER JOIN asignaturas asg ON asg.id = u.asignatura_id AND asg.deleted_at IS NULL
               INNER JOIN subunidades s ON s.unidad_id = u.id AND s.deleted_at IS NULL
               LEFT JOIN notas n ON n.subunidad_id = s.id AND n.alumno_id = u.alumno_id
                                AND n.deleted_at IS NULL
              WHERE u.periodo_id = ? AND u.deleted_at IS NULL
                AND u.alumno_id IN ('.$huecos.')
              GROUP BY u.alumno_id, asg.profesor_id, asg.grupo_id',
            array_merge([$periodoId], $alumnoIds)
        );

        $mapa = [];

        foreach ($filas as $fila) {
            $mapa[(int) $fila->alumno_id][] = [
                'profesor_id' => $fila->profesor_id === null ? null : (int) $fila->profesor_id,
                'grupo_id' => (int) $fila->grupo_id,
                'totales' => (int) $fila->totales,
                'puestas' => (int) $fila->puestas,
                'sin_casilla' => (int) $fila->sin_casilla,
            ];
        }

        return $mapa;
    }

    /**
     * Las unidades propias del alumno en VARIAS asignaturas, agrupadas por asignatura.
     *
     * Es `unidadesPropias()` un escalón más arriba y con la misma forma dentro —el front
     * pidió que `unidades` fuera **idéntico** al de `planilla`, y lo es—. Trece
     * asignaturas son trece llamadas al de una; ésta es una.
     *
     * El `LEFT JOIN` de `notas` sigue siendo `LEFT` por lo mismo que allí: una subunidad
     * sin fila viaja con `nota: null` y la pantalla la pinta vacía en vez de perderla.
     *
     * @param  list<int>  $asignaturaIds
     * @return array<int, list<array<string, mixed>>>
     */
    private function unidadesPropiasDeVarias(int $alumnoId, array $asignaturaIds, int $periodoId): array
    {
        if ($asignaturaIds === []) {
            return [];
        }

        $huecos = implode(',', array_fill(0, count($asignaturaIds), '?'));

        $filas = DB::select(
            'SELECT u.asignatura_id,
                    u.id AS unidad_id, u.definicion AS definicion_unidad, u.porcentaje AS porcentaje_unidad, u.orden AS orden_unidad,
                    s.id AS subunidad_id, s.definicion AS definicion_subunidad, s.porcentaje AS porcentaje_subunidad,
                    s.orden AS orden_subunidad, s.nota_default,
                    n.id AS nota_id, n.nota
               FROM unidades u
               LEFT JOIN subunidades s ON s.unidad_id = u.id AND s.deleted_at IS NULL
               LEFT JOIN notas n ON n.subunidad_id = s.id AND n.alumno_id = ? AND n.deleted_at IS NULL
              WHERE u.periodo_id = ? AND u.alumno_id = ? AND u.deleted_at IS NULL
                AND u.asignatura_id IN ('.$huecos.')
              ORDER BY u.asignatura_id, u.orden, u.id, s.orden, s.id',
            array_merge([$alumnoId, $periodoId, $alumnoId], $asignaturaIds)
        );

        $porAsignatura = [];
        $indice = [];

        foreach ($filas as $fila) {
            $asignaturaId = (int) $fila->asignatura_id;
            $unidadId = (int) $fila->unidad_id;

            if (! isset($indice[$asignaturaId][$unidadId])) {
                $porAsignatura[$asignaturaId][] = [
                    'unidad_id' => $unidadId,
                    'definicion' => $fila->definicion_unidad,
                    'porcentaje' => (int) $fila->porcentaje_unidad,
                    'orden' => (int) $fila->orden_unidad,
                    'subunidades' => [],
                ];

                $indice[$asignaturaId][$unidadId] = count($porAsignatura[$asignaturaId]) - 1;
            }

            // Una unidad sin subunidades vivas sale **con la lista vacía y no desaparece**:
            // suma porcentaje y no tiene dónde poner nota, o sea la mitad de un boletín mal
            // montado. Esconderla dejaría la suma sin explicación. Igual que en `planilla`.
            if ($fila->subunidad_id === null) {
                continue;
            }

            $porAsignatura[$asignaturaId][$indice[$asignaturaId][$unidadId]]['subunidades'][] = [
                'subunidad_id' => (int) $fila->subunidad_id,
                'definicion' => $fila->definicion_subunidad,
                'porcentaje' => (int) $fila->porcentaje_subunidad,
                'orden' => (int) $fila->orden_subunidad,
                'nota' => $fila->nota_id === null
                    ? null
                    : ['id' => (int) $fila->nota_id, 'nota' => (int) $fila->nota],
            ];
        }

        // `array_values` por asignatura, igual que hace `unidadesPropias()` al final y por
        // lo mismo: lo que viaja al JSON tiene que ser una **lista**. Aquí las claves ya son
        // correlativas por construcción —sólo se hace `[] =`—, así que en ejecución no
        // cambia nada; lo que cambia es que deja de depender de que nadie escriba un día un
        // `unset` en medio, que es el día que ese array sale al JSON como objeto.
        return array_map(static fn (array $unidades) => array_values($unidades), $porAsignatura);
    }

    /**
     * La definitiva guardada en VARIAS asignaturas. Mismo `CAST` y mismo desempate que
     * `definitivaDe()`, que es de donde sale la regla: `notas_finales` **no tiene clave
     * única** sobre (alumno, asignatura, periodo) y puede haber dos, así que se elige la
     * última escrita.
     *
     * @param  list<int>  $asignaturaIds
     * @return array<int, array<string, mixed>>
     */
    private function definitivasDeVarias(int $alumnoId, array $asignaturaIds, int $periodoId): array
    {
        if ($asignaturaIds === []) {
            return [];
        }

        $huecos = implode(',', array_fill(0, count($asignaturaIds), '?'));

        $filas = DB::select(
            'SELECT nf.asignatura_id, CAST(nf.nota AS DOUBLE) AS nota, nf.manual, nf.recuperada
               FROM notas_finales nf
              WHERE nf.alumno_id = ? AND nf.periodo_id = ?
                AND nf.asignatura_id IN ('.$huecos.')
              ORDER BY nf.id ASC',
            array_merge([$alumnoId, $periodoId], $asignaturaIds)
        );

        $mapa = [];

        // `ORDER BY id ASC` y sobrescribir: la última que se escribe en el mapa es la de
        // `id` mayor, o sea la misma que elegiría el `ORDER BY id DESC LIMIT 1` de
        // `definitivaDe()`. Se hace así y no con `DESC` + `isset` porque un `isset` sobre
        // un valor que puede ser `null` es la clase de detalle que se lee mal al mantenerlo.
        foreach ($filas as $fila) {
            $mapa[(int) $fila->asignatura_id] = [
                'nota' => (float) $fila->nota,
                'manual' => (bool) $fila->manual,
                'recuperada' => (bool) $fila->recuperada,
            ];
        }

        return $mapa;
    }

    /**
     * Cuántas unidades **del grupo** hay en cada asignatura, para distinguir
     * `asignatura_sin_montar` de `sin_estructura_propia`. `u.alumno_id IS NULL`.
     *
     * @param  list<int>  $asignaturaIds
     * @return array<int, int>
     */
    private function unidadesDelGrupoDeVarias(array $asignaturaIds, int $periodoId): array
    {
        return $this->contarPorAsignatura(
            'SELECT u.asignatura_id, COUNT(*) c FROM unidades u
              WHERE u.periodo_id = ? AND u.alumno_id IS NULL AND u.deleted_at IS NULL
                AND u.asignatura_id IN (%s)
              GROUP BY u.asignatura_id',
            [$periodoId],
            $asignaturaIds
        );
    }

    /**
     * Cuántas unidades propias **borradas** tiene el alumno en cada asignatura: es lo
     * único que distingue `vaciada` de no haber tenido nunca nada.
     *
     * @param  list<int>  $asignaturaIds
     * @return array<int, int>
     */
    private function vaciadasDeVarias(int $alumnoId, array $asignaturaIds, int $periodoId): array
    {
        return $this->contarPorAsignatura(
            'SELECT u.asignatura_id, COUNT(*) c FROM unidades u
              WHERE u.periodo_id = ? AND u.alumno_id = ? AND u.deleted_at IS NOT NULL
                AND u.asignatura_id IN (%s)
              GROUP BY u.asignatura_id',
            [$periodoId, $alumnoId],
            $asignaturaIds
        );
    }

    /**
     * Las faltas y tardanzas del alumno en VARIAS asignaturas, con su detalle.
     *
     * `tipo` es el único discriminador que tiene la tabla —ver el docblock de
     * `putAlumno()`—: una fila con un `tipo` distinto de los dos saldría en `detalle` y
     * en ninguno de los dos recuentos, y se ve comparando con el tamaño de `detalle`.
     *
     * @param  list<int>  $asignaturaIds
     * @return array<int, array<string, mixed>>
     */
    private function faltasDeVarias(int $alumnoId, array $asignaturaIds, int $periodoId): array
    {
        if ($asignaturaIds === []) {
            return [];
        }

        $huecos = implode(',', array_fill(0, count($asignaturaIds), '?'));

        $filas = DB::select(
            'SELECT au.id, au.asignatura_id, au.fecha_hora, au.tipo
               FROM ausencias au
              WHERE au.alumno_id = ? AND au.periodo_id = ? AND au.deleted_at IS NULL
                AND au.asignatura_id IN ('.$huecos.')
              ORDER BY au.fecha_hora, au.id',
            array_merge([$alumnoId, $periodoId], $asignaturaIds)
        );

        $mapa = [];

        foreach ($filas as $fila) {
            $asignaturaId = (int) $fila->asignatura_id;

            if (! isset($mapa[$asignaturaId])) {
                $mapa[$asignaturaId] = ['ausencias' => 0, 'tardanzas' => 0, 'detalle' => []];
            }

            if ($fila->tipo === 'ausencia') {
                $mapa[$asignaturaId]['ausencias']++;
            } elseif ($fila->tipo === 'tardanza') {
                $mapa[$asignaturaId]['tardanzas']++;
            }

            $mapa[$asignaturaId]['detalle'][] = [
                'id' => (int) $fila->id,
                'fecha_hora' => $fila->fecha_hora,
                'tipo' => $fila->tipo,
            ];
        }

        return $mapa;
    }

    /**
     * El `COUNT(*) … GROUP BY asignatura_id` de los dos recuentos del `motivo`, que son
     * la misma sentencia con otro `WHERE`. `%s` es el hueco de la lista de ids.
     *
     * @param  list<int|string>  $parametros
     * @param  list<int>  $asignaturaIds
     * @return array<int, int>
     */
    private function contarPorAsignatura(string $plantilla, array $parametros, array $asignaturaIds): array
    {
        if ($asignaturaIds === []) {
            return [];
        }

        $filas = DB::select(
            sprintf($plantilla, implode(',', array_fill(0, count($asignaturaIds), '?'))),
            array_merge($parametros, $asignaturaIds)
        );

        $mapa = [];

        foreach ($filas as $fila) {
            $mapa[(int) $fila->asignatura_id] = (int) $fila->c;
        }

        return $mapa;
    }

    /**
     * `POST boletin-independiente/copiar` — montarle a alguien la estructura que ya
     * existe, en vez de a mano. §6.2 del
     * [19](../../../docs/migracion/19-boletin-independiente.md).
     *
     * ```jsonc
     * { "asignatura_id": 812, "periodo_id": 93,          // el DESTINO
     *   "alumnos_destino": [3311, 3402],
     *   "origen": { "tipo": "grupo",  "periodo_id": 91 },
     *   //     o : { "tipo": "alumno", "alumno_id": 2199, "periodo_id": 91 },
     *   "con_notas": false, "si_ya_tiene": "saltar" }
     * ```
     *
     * ## DOS orígenes, y el segundo es el caso normal
     *
     * El plan tenía **uno solo implícito** —otro alumno, misma asignatura, mismo
     * periodo— y **el caso corriente no cabía**: el estudiante que vuelve y sigue el
     * plan del curso, copiando **del periodo que sí está montado**. Encargo de Joseth:
     * *«tanto de otro boletín que se le creó de manera independiente a otro estudiante
     * como de las unidades/sub específicas de asignaturas en algún periodo»*.
     *
     * ## LA TRAMPA: los dos orígenes se leen con alcances CONTRARIOS
     *
     * | `origen.tipo` | Qué filas lee |
     * |---|---|
     * | `grupo` | **`u.alumno_id IS NULL`** |
     * | `alumno` | **`u.alumno_id = origen.alumno_id`** |
     *
     * Las dos preguntas viven **en el mismo método**, y un `=` copiado a la rama del
     * grupo **devuelve cero filas y copia una estructura vacía en 200** — el fallo mudo
     * de siempre. Por eso las dos ramas están escritas aparte y con nombre, en vez de
     * con un parámetro que alguien pueda pasar al revés, y hay un test que **cuenta las
     * filas copiadas por cada rama**: un cero no se distingue de un éxito mirando el
     * código de estado.
     *
     * ## Sólo la misma asignatura, con 422
     *
     * `origen.asignatura_id` **no existe**. `asignaturas` es `(materia_id, grupo_id)` y
     * **no tiene `periodo_id`**, así que «la misma asignatura en otro periodo» ya cubre
     * el caso entero; lo que ese campo abriría es **otra materia o, peor, otro grupo** —
     * un id del cuerpo que no comprueba nadie, con el docente de 5A tirando de la
     * estructura de 11B. **Y esa puerta ya existe y es otra**: `PUT periodos/copiar`.
     * Dos puertas para la misma operación con reglas distintas es de donde salió el
     * recalculador único.
     *
     * ## El destino se comprueba contra el periodo de DESTINO
     *
     * Sólo se copia a quien va por independiente en `periodo_id`. Quien no, vuelve como
     * `resultado: "no_marcado"` **y nunca como 400**: la pantalla los está listando y
     * que uno se desmarque entre la carga y el clic es normal, no un error de nadie.
     *
     * ## Una transacción para todo, y el recálculo FUERA
     *
     * Es lo que aprendió `PUT notas/lote`: media copia deja definitivas calculadas sobre
     * estados intermedios. Y **no se reutiliza `PUT periodos/copiar`**, que escribe en un
     * `foreach` **sin transacción** — su propio test de contrato lo fija.
     */
    public function postCopiar()
    {
        $asignaturaId = $this->idDelCuerpo('asignatura_id');
        $periodoId = $this->idDelCuerpo('periodo_id');

        // `detallada()` tira el 404 de una asignatura de otro año, igual que en
        // `putPlanilla()`: un solo sitio decide eso.
        $asignatura = Asignatura::detallada($asignaturaId, (int) $this->user->year_id);
        $grupoId = (int) ((object) $asignatura)->grupo_id;

        $this->exigirPeriodoDelAnio($periodoId, 'periodo_id');

        $destinos = $this->alumnosDestinoDelCuerpo();
        $origen = $this->origenDelCuerpo($periodoId);
        $siYaTiene = $this->siYaTieneDelCuerpo();
        $conNotas = $this->banderaDelCuerpo('con_notas');

        // **El 422 que el front no pidió y hay que poner.** Copiar la estructura del
        // periodo 1 al 3 es preparar la planilla; copiar **también las notas** es
        // escribir en el 3 las calificaciones del 1. Eso no es una copia, es inventar un
        // dato — y **el navegador no puede decidirlo**, porque desde la pantalla las dos
        // casillas parecen igual de inocentes.
        if ($conNotas && $origen['periodo_id'] !== $periodoId) {
            abort(422, 'No se pueden copiar las notas entre periodos distintos: eso escribiría en '
                .'este periodo las calificaciones del otro.');
        }

        $unidadesOrigen = $this->unidadesDelOrigen($origen, $asignaturaId);

        $resultados = DB::transaction(function () use (
            $destinos, $origen, $unidadesOrigen, $asignaturaId, $periodoId, $grupoId, $siYaTiene, $conNotas
        ) {
            $salida = [];

            foreach ($destinos as $alumnoId) {
                $salida[] = $this->copiarleA(
                    $alumnoId, $origen, $unidadesOrigen, $asignaturaId, $periodoId, $grupoId, $siYaTiene, $conNotas
                );
            }

            return $salida;
        });

        // **Fuera de la transacción y uno por alumno.** Dentro, el recálculo vería
        // estados intermedios —la mitad de las unidades puestas— y escribiría
        // definitivas sobre una estructura que aún no existe. Por alumno y no por
        // asignatura porque lo que cambió es **su** boletín y no el reparto del curso:
        // recalcular el grupo entero reescribiría las treinta definitivas para arreglar
        // una.
        foreach ($resultados as $fila) {
            if ($fila['resultado'] === 'copiado') {
                DefinitivasDeAsignatura::recalcular($asignaturaId, $periodoId, $this->user->user_id, $fila['alumno_id']);
            }
        }

        // La suma se lee **después** del recálculo y de la transacción, que es cuando ya
        // es la de verdad. Y no se corrige: que `anadir` deje un 160 **se ve, y que se
        // vea es lo que lo delata** (regla 2 de `DefinitivasDeAsignatura`).
        foreach ($resultados as $i => $fila) {
            $resultados[$i]['porcentaje_unidades'] =
                DefinitivasDeAsignatura::porcentajeDeLasUnidades($asignaturaId, $periodoId, $fila['alumno_id']);
        }

        return [
            'origen' => [
                'tipo' => $origen['tipo'],
                'periodo_id' => $origen['periodo_id'],
                'alumno_id' => $origen['alumno_id'],
                'unidades' => count($unidadesOrigen),
                'subunidades' => array_sum(array_map(static fn ($u) => count($u['subunidades']), $unidadesOrigen)),
            ],
            'destinos' => $resultados,
        ];
    }

    /**
     * Copiarle la estructura a UN alumno. Devuelve su fila de `destinos`.
     *
     * @param  array{tipo: string, periodo_id: int, alumno_id: ?int}  $origen
     * @param  list<array<string, mixed>>  $unidadesOrigen
     * @return array<string, mixed>
     */
    private function copiarleA(
        int $alumnoId, array $origen, array $unidadesOrigen,
        int $asignaturaId, int $periodoId, int $grupoId, string $siYaTiene, bool $conNotas
    ): array {
        // **Contra el periodo de DESTINO.** Quien dejó de ir por independiente entre que
        // la pantalla cargó y el clic vuelve así y no como un error: la pantalla lo
        // estaba listando de buena fe.
        if (! BoletinIndependiente::aplica($alumnoId, $periodoId)) {
            return ['alumno_id' => $alumnoId, 'resultado' => 'no_marcado'];
        }

        $suyas = DB::select(
            'SELECT id FROM unidades
              WHERE alumno_id = ? AND asignatura_id = ? AND periodo_id = ? AND deleted_at IS NULL',
            [$alumnoId, $asignaturaId, $periodoId]
        );

        $retiradas = ['unidades' => 0, 'notas_que_dejan_de_contar' => 0];

        if ($suyas !== []) {
            if ($siYaTiene === 'saltar') {
                return ['alumno_id' => $alumnoId, 'resultado' => 'saltado', 'motivo' => 'ya_tiene_estructura'];
            }

            if ($siYaTiene === 'reemplazar') {
                $retiradas = $this->retirarLasSuyas($suyas, $alumnoId);
            }
            // `anadir` no retira nada: se suman a las que ya tiene, y **la suma puede
            // pasar de 100 y no se corrige**.
        }

        $copiadas = ['unidades' => 0, 'subunidades' => 0, 'notas' => 0];

        foreach ($unidadesOrigen as $unidad) {
            $nuevaUnidad = DB::table('unidades')->insertGetId([
                'definicion' => $unidad['definicion'],
                'porcentaje' => $unidad['porcentaje'],
                'periodo_id' => $periodoId,
                'asignatura_id' => $asignaturaId,
                'alumno_id' => $alumnoId,
                'obligatoria' => $unidad['obligatoria'],
                'orden' => $unidad['orden'],
                'created_by' => $this->user->user_id,
                'created_at' => Reloj::ahoraTexto(),
                'updated_at' => Reloj::ahoraTexto(),
            ]);

            $copiadas['unidades']++;

            foreach ($unidad['subunidades'] as $sub) {
                $nuevaSub = DB::table('subunidades')->insertGetId([
                    'definicion' => $sub['definicion'],
                    'porcentaje' => $sub['porcentaje'],
                    'unidad_id' => $nuevaUnidad,
                    'nota_default' => $sub['nota_default'],
                    'obligatoria' => $sub['obligatoria'],
                    'orden' => $sub['orden'],
                    'inicia_at' => $sub['inicia_at'],
                    'finaliza_at' => $sub['finaliza_at'],
                    'created_by' => $this->user->user_id,
                    'created_at' => Reloj::ahoraTexto(),
                    'updated_at' => Reloj::ahoraTexto(),
                ]);

                $copiadas['subunidades']++;

                if ($conNotas) {
                    $copiadas['notas'] += $this->copiarLaNota($sub['subunidad_id'], $nuevaSub, $origen, $alumnoId);
                }
            }
        }

        return [
            'alumno_id' => $alumnoId,
            'resultado' => 'copiado',
            'copiadas' => $copiadas,
            'retiradas' => $retiradas,
        ];
    }

    /**
     * La nota que le toca a una subunidad copiada, y **de quién es depende del origen**.
     *
     * **Son dos casos y no uno con un parámetro**, y quien escriba sólo el segundo creerá
     * que ha hecho los dos: en los dos el SQL sale de `notas n` por `subunidad_id`, y lo
     * único que cambia es **de quién es el `n.alumno_id`**.
     *
     * - `origen.tipo = "grupo"` → las notas que **el propio alumno de destino ya tenía**
     *   en las subunidades del curso. Es lo que hace útil la operación: iba en la
     *   planilla, se le marca a mitad de periodo y **se lleva lo suyo** en vez de empezar
     *   en blanco. Es la §9.3 por la otra puerta.
     * - `origen.tipo = "alumno"` → las **del alumno de origen**. Eso es calificar a
     *   varios de golpe, y por eso `con_notas` es un botón aparte que nace apagado.
     *
     * @param  array{tipo: string, periodo_id: int, alumno_id: ?int}  $origen
     * @return int  1 si copió una nota, 0 si no había.
     */
    private function copiarLaNota(int $subunidadOrigen, int $subunidadNueva, array $origen, int $alumnoDestino): int
    {
        $dueno = $origen['tipo'] === 'alumno' ? (int) $origen['alumno_id'] : $alumnoDestino;

        $nota = DB::selectOne(
            'SELECT nota FROM notas WHERE subunidad_id = ? AND alumno_id = ? AND deleted_at IS NULL
              ORDER BY id DESC LIMIT 1',
            [$subunidadOrigen, $dueno]
        );

        if ($nota === null) {
            return 0;
        }

        DB::table('notas')->insert([
            'nota' => $nota->nota,
            'subunidad_id' => $subunidadNueva,
            'alumno_id' => $alumnoDestino,
            'created_by' => $this->user->user_id,
            'created_at' => Reloj::ahoraTexto(),
            'updated_at' => Reloj::ahoraTexto(),
        ]);

        return 1;
    }

    /**
     * Retira las unidades **propias** del destino. `reemplazar`.
     *
     * ## No borra ni una nota, y el «¿está seguro?» no puede decir que sí
     *
     * Medido en `UnidadesController::deleteDestroy`: retirar una unidad es un **borrado
     * en blando de la unidad y de nada más**. Las subunidades y las notas **conservan su
     * `deleted_at` a null** y siguen ahí; salen de los cálculos porque cada lectura une
     * `u.deleted_at IS NULL`, no porque se hayan ido. Y **`PUT unidades/restore/{id}` la
     * devuelve entera, con sus subunidades y sus notas dentro** — la papelera ya existe y
     * ya está enrutada.
     *
     * Por eso el campo se llama **`notas_que_dejan_de_contar`** y no `notas_borradas`. No
     * es un matiz de nombre: *«se borrarán 9 notas»* es **falso**, y asusta de una forma
     * que hace que el docente no use el botón.
     *
     * ## Y sólo toca las del destino. Jamás una del grupo ni una de otro alumno
     *
     * Retirar por `(asignatura_id, periodo_id)` sin el dueño **le vaciaría la planilla a
     * los treinta**, en 200 y sin un error. Lo garantiza el `SELECT` que arma `$ids`, que
     * filtra por `alumno_id = destino`, y eso **sí** lo comprueba
     * `test_reemplazar_no_toca_las_del_grupo_ni_las_de_otro` por los dos lados.
     *
     * **El `AND alumno_id = ?` del `UPDATE` de abajo es un segundo candado y NINGÚN TEST
     * PUEDE ALCANZARLO**, porque el primero ya se cumple: quitarlo no pone nada en rojo
     * (comprobado, R15). Se deja igualmente —el día que alguien cambie de dónde salen los
     * ids, la escritura sigue acotada— pero **queda escrito que es defensa y no garantía
     * medida**, que es la diferencia que esta noche ya costó una vez: un comentario que
     * documenta una protección con su razón, y que al quitarla no se pone rojo, es un
     * comentario haciéndose pasar por un test.
     *
     * @param  list<object>  $suyas
     * @return array{unidades: int, notas_que_dejan_de_contar: int}
     */
    private function retirarLasSuyas(array $suyas, int $alumnoId): array
    {
        $ids = array_map(static fn ($u) => (int) $u->id, $suyas);
        $marcas = implode(',', array_fill(0, count($ids), '?'));

        // Se cuentan ANTES de retirar: después, la misma consulta seguiría contándolas
        // —el borrado es de la unidad y no de la nota—, así que el número saldría igual
        // y no diría nada. Contarlo aquí es lo que hace que la cifra signifique algo.
        $dejanDeContar = (int) DB::selectOne(
            'SELECT COUNT(*) c FROM notas n
               INNER JOIN subunidades s ON s.id = n.subunidad_id AND s.deleted_at IS NULL
              WHERE s.unidad_id IN ('.$marcas.') AND n.alumno_id = ? AND n.deleted_at IS NULL',
            array_merge($ids, [$alumnoId])
        )->c;

        DB::update(
            'UPDATE unidades SET deleted_at = ?, deleted_by = ?
              WHERE id IN ('.$marcas.') AND alumno_id = ? AND deleted_at IS NULL',
            array_merge([Reloj::ahoraTexto(), $this->user->user_id], $ids, [$alumnoId])
        );

        return ['unidades' => count($ids), 'notas_que_dejan_de_contar' => $dejanDeContar];
    }

    /**
     * Las unidades del origen con sus subunidades dentro, **leídas por la rama que toca**.
     *
     * Las dos ramas van separadas y con su condición escrita entera, en vez de con una
     * variable que alguien pueda pasar al revés: es la trampa de esta ruta.
     *
     * @param  array{tipo: string, periodo_id: int, alumno_id: ?int}  $origen
     * @return list<array<string, mixed>>
     */
    private function unidadesDelOrigen(array $origen, int $asignaturaId): array
    {
        if ($origen['tipo'] === 'grupo') {
            // **`IS NULL`, que es «del curso».** Un `= algo` aquí devuelve cero filas y
            // copia una estructura vacía en 200.
            $filas = DB::select(
                'SELECT u.id, u.definicion, u.porcentaje, u.obligatoria, u.orden
                   FROM unidades u
                  WHERE u.asignatura_id = ? AND u.periodo_id = ? AND u.alumno_id IS NULL
                    AND u.deleted_at IS NULL
                  ORDER BY u.orden, u.id',
                [$asignaturaId, $origen['periodo_id']]
            );
        } else {
            // **`= origen.alumno_id`, que es «de ése y de nadie más».** Un `IS NULL` aquí
            // copiaría el curso creyendo que copia a la persona.
            $filas = DB::select(
                'SELECT u.id, u.definicion, u.porcentaje, u.obligatoria, u.orden
                   FROM unidades u
                  WHERE u.asignatura_id = ? AND u.periodo_id = ? AND u.alumno_id = ?
                    AND u.deleted_at IS NULL
                  ORDER BY u.orden, u.id',
                [$asignaturaId, $origen['periodo_id'], $origen['alumno_id']]
            );
        }

        $salida = [];

        foreach ($filas as $fila) {
            $salida[] = [
                'definicion' => $fila->definicion,
                'porcentaje' => $fila->porcentaje,
                'obligatoria' => $fila->obligatoria,
                'orden' => $fila->orden,
                'subunidades' => array_map(static fn ($s) => [
                    'subunidad_id' => (int) $s->id,
                    'definicion' => $s->definicion,
                    'porcentaje' => $s->porcentaje,
                    'nota_default' => $s->nota_default,
                    'obligatoria' => $s->obligatoria,
                    'orden' => $s->orden,
                    'inicia_at' => $s->inicia_at,
                    'finaliza_at' => $s->finaliza_at,
                ], DB::select(
                    'SELECT s.id, s.definicion, s.porcentaje, s.nota_default, s.obligatoria,
                            s.orden, s.inicia_at, s.finaliza_at
                       FROM subunidades s
                      WHERE s.unidad_id = ? AND s.deleted_at IS NULL
                      ORDER BY s.orden, s.id',
                    [$fila->id]
                )),
            ];
        }

        return $salida;
    }

    /**
     * El bloque `origen`, validado.
     *
     * @return array{tipo: string, periodo_id: int, alumno_id: ?int}
     */
    private function origenDelCuerpo(int $periodoDestino): array
    {
        $tipo = Request::input('origen.tipo');

        if (! in_array($tipo, ['grupo', 'alumno'], true)) {
            abort(422, "'origen.tipo' tiene que ser 'grupo' o 'alumno'.");
        }

        $periodoOrigen = $this->idDelCuerpo('origen.periodo_id');
        $this->exigirPeriodoDelAnio($periodoOrigen, 'origen.periodo_id');

        // **`origen.asignatura_id` se rechaza en vez de ignorarse.** Ignorar un campo que
        // el cliente manda es la peor de las dos salidas: el docente cree que copió de
        // otra asignatura y copió de la suya, en 200. Ver el docblock de `postCopiar()`.
        if (Request::input('origen.asignatura_id') !== null) {
            abort(422, 'Sólo se puede copiar dentro de la misma asignatura. Para copiar entre '
                .'asignaturas está `PUT periodos/copiar`.');
        }

        $alumnoOrigen = null;

        if ($tipo === 'alumno') {
            $alumnoOrigen = $this->idDelCuerpo('origen.alumno_id');
        }

        // Copiarle a alguien de sí mismo es un no-op caro: crea una copia de sus propias
        // unidades y le duplica la suma. Se corta aquí y no en el bucle, porque el
        // destino puede ser una lista y el error es del origen.
        if ($tipo === 'alumno' && $periodoOrigen === $periodoDestino
            && in_array($alumnoOrigen, $this->alumnosDestinoDelCuerpo(), true)) {
            abort(422, 'Un alumno no puede copiarse de sí mismo en el mismo periodo.');
        }

        return ['tipo' => $tipo, 'periodo_id' => $periodoOrigen, 'alumno_id' => $alumnoOrigen];
    }

    /** @return list<int> */
    private function alumnosDestinoDelCuerpo(): array
    {
        $pedidos = Request::input('alumnos_destino');

        if (! is_array($pedidos) || $pedidos === []) {
            abort(422, "Falta 'alumnos_destino' o está vacío.");
        }

        $ids = [];

        foreach ($pedidos as $pedido) {
            if (! is_scalar($pedido) || ! preg_match('/^\d+$/', (string) $pedido) || (int) $pedido <= 0) {
                abort(422, "'alumnos_destino' lleva algo que no es un identificador.");
            }

            $ids[] = (int) $pedido;
        }

        // Sin repetidos: el mismo alumno dos veces en la lista le copiaría la estructura
        // dos veces y le dejaría la suma al doble, en 200 y sin que nada lo señale.
        return array_values(array_unique($ids));
    }

    /** `saltar` (defecto) · `anadir` · `reemplazar`. */
    private function siYaTieneDelCuerpo(): string
    {
        $valor = Request::input('si_ya_tiene', 'saltar');

        if (! in_array($valor, ['saltar', 'anadir', 'reemplazar'], true)) {
            abort(422, "'si_ya_tiene' tiene que ser 'saltar', 'anadir' o 'reemplazar'.");
        }

        return $valor;
    }

    /**
     * Una bandera del cuerpo, con el mismo vocabulario cerrado que `aplica`.
     *
     * Ausente vale `false` — aquí sí, al revés que en `aplica`: `con_notas` **nace
     * apagado** por decisión (copiar estructura es preparar; copiar notas es calificar),
     * así que «no lo mandé» y «no» son lo mismo. Lo que no vale es una cadena cualquiera.
     */
    private function banderaDelCuerpo(string $campo): bool
    {
        $valor = Request::input($campo);

        if ($valor === null || $valor === '') {
            return false;
        }

        $leido = filter_var($valor, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        if ($leido === null) {
            abort(422, "'{$campo}' tiene que ser verdadero o falso.");
        }

        return $leido;
    }

    /** Que un periodo del cuerpo exista y sea del año del token; lo devuelve. Ver `putPeriodo()`. */
    private function exigirPeriodoDelAnio(int $periodoId, string $campo): object
    {
        $periodo = DB::selectOne(
            'SELECT p.id, p.numero, p.year_id FROM periodos p WHERE p.id = ? AND p.deleted_at IS NULL',
            [$periodoId]
        );

        if ($periodo === null) {
            abort(404, "El periodo de '{$campo}' no existe.");
        }

        if ((int) $periodo->year_id !== (int) $this->user->year_id) {
            abort(403, "El periodo de '{$campo}' no es del año en el que estás trabajando.");
        }

        // **Devuelve el periodo, y eso es lo que evita la rama muerta.** Las dos lecturas de
        // la §13 necesitan el `numero` justo después de esta guarda; con un `void` cada una
        // tendría que volver a buscarlo y escribir detrás un `if (=== null)` que **nadie
        // puede ejecutar** —la guarda ya abortó—, y este repo ya sabe cómo acaban las ramas
        // que no se pueden alcanzar: alguien ramifica sobre ellas y no se nota nunca.
        return $periodo;
    }

    /**
     * Al APAGAR la marca, las casillas del grupo que a este alumno le faltan.
     *
     * ## Por qué existe: la §9.3, el alumno que se desmarca a mitad de periodo
     *
     * Se marca, el docente le monta sus unidades y le pone notas, y a la semana el
     * colegio dice «este periodo no». El alumno vuelve a la planilla del grupo y **no
     * tiene notas en las subunidades del grupo**. `Nota::verificarCrearNotas` se las
     * crea en la siguiente carga de `/notas` —ya lo hace hoy con cualquier alumno
     * nuevo—, pero **desde `myvc_flutter`, que no llama a `/notas` nunca**, esa
     * ventana dura días.
     *
     * ## Y por qué NO pregunta `User::permiteEditarNotas`, que es la trampa del lote
     *
     * `Nota::verificarCrearNotas` → `quienCreaLasNotas` → `User::permiteEditarNotas`
     * termina en `is_superuser || tipo == 'Profesor'`. **Un secretario o un rector que
     * no sean superusuarios reciben `false` — también con el periodo ABIERTO**: la
     * gente que la decisión 5 acaba de poner a cargo de esta ruta es exactamente la
     * que no sembraría nada, en silencio.
     *
     * Y hoy no se vería: en `simonbolivar` los roles `Rector` y `Secretario` tienen
     * **cero personas** y los diez `Admin` son los diez `is_superuser`, así que
     * **funcionaría por coincidencia de población** — la forma exacta del paso 0 de
     * `DESPLIEGUE.md`. El colegio que le dé el rol a un secretario de verdad es el
     * que lo descubre.
     *
     * La razón de fondo es que **la pregunta es otra**. `permiteEditarNotas` contesta
     * *«¿puedes editar notas?»*; aquí la pregunta es *«acabas de devolver a este
     * alumno a la planilla del grupo, ¿le dejamos las casillas puestas?»*. Las filas
     * que esto crea son **notas sin valor**, con `nota_default`: no crearlas es el
     * daño. Se firman con el `user_id` de quien llamó, que es quien tomó la decisión.
     *
     * ## Una sentencia y no un bucle, y el `GROUP BY` no es adorno
     *
     * `matriculas` **no tiene clave única sobre (alumno, año)** —es la §9.5, viva para
     * todo lo que no sea esta marca—, así que un alumno con dos matrículas vivas en el
     * mismo año entra dos veces por el `JOIN` y **el `INSERT` metería la misma casilla
     * dos veces**, que es precisamente el estado que `verificarCrearNota` evita con su
     * `NOT EXISTS` y que un `NOT EXISTS` no puede evitar dentro de una sola sentencia.
     * `GROUP BY s.id` colapsa las filas repetidas antes de insertar. Y entrar por
     * todas sus matrículas del año, en vez de elegir una, es lo que hace que aquí no
     * haya ninguna fila que acertar.
     *
     * `u.alumno_id IS NULL` es el alcance del **grupo**, escrito a mano y no con
     * `BoletinIndependiente::ALCANCE`: aquí no se pregunta de quién es la unidad, se
     * afirma cuál se quiere. Son las del curso, que son las que al alumno le van a
     * faltar.
     */
    private function sembrarLasNotasQueFaltan(int $alumnoId, int $periodoId, int $yearId, string $ahora): void
    {
        DB::insert(
            'INSERT INTO notas (subunidad_id, alumno_id, nota, created_by, created_at, updated_at)
             SELECT s.id, ?, s.nota_default, ?, ?, ?
               FROM matriculas m
               INNER JOIN grupos g ON g.id = m.grupo_id AND g.deleted_at IS NULL AND g.year_id = ?
               INNER JOIN asignaturas asg ON asg.grupo_id = g.id AND asg.deleted_at IS NULL
               INNER JOIN unidades u ON u.asignatura_id = asg.id
                                    AND u.periodo_id = ?
                                    AND u.alumno_id IS NULL
                                    AND u.deleted_at IS NULL
               INNER JOIN subunidades s ON s.unidad_id = u.id AND s.deleted_at IS NULL
              WHERE m.alumno_id = ?
                AND m.deleted_at IS NULL
                AND NOT EXISTS (
                    SELECT 1 FROM notas n
                     WHERE n.subunidad_id = s.id AND n.alumno_id = ? AND n.deleted_at IS NULL
                )
              GROUP BY s.id',
            [$alumnoId, $this->user->user_id, $ahora, $ahora, $yearId, $periodoId, $alumnoId, $alumnoId]
        );
    }

    /**
     * Un identificador del cuerpo, o 422.
     *
     * `Request::input()` devuelve lo que le manden: `"3311"`, `"abc"`, un array o
     * nada. Un `(int)` a secas convierte `"abc"` en **0** y sigue, y un 0 llega a la
     * consulta como un id perfectamente válido que no encuentra nada — 404 donde el
     * problema era el cuerpo.
     */
    private function idDelCuerpo(string $campo): int
    {
        $valor = Request::input($campo);

        if (! is_scalar($valor) || ! preg_match('/^\d+$/', (string) $valor) || (int) $valor <= 0) {
            abort(422, "Falta '{$campo}' o no es un identificador.");
        }

        return (int) $valor;
    }

    /**
     * `aplica`, con un vocabulario cerrado y sin «cualquier cadena vale por sí».
     *
     * Es la familia de `tools/verdad-laxa-que-escribe.py`: un `if ($valor)` de PHP
     * hace que `"false"`, `"no"` y `"0.0"` valgan **true**, y aquí eso no es un campo
     * cosmético — gobierna a qué boletín pertenece un periodo entero. `false` mal
     * leído como `true` **esconde al alumno de la planilla del grupo** y nadie recibe
     * un error.
     *
     * `FILTER_VALIDATE_BOOLEAN` con `FILTER_NULL_ON_FAILURE` acepta el vocabulario de
     * PHP —`1/0`, `true/false`, `"on"/"off"`, `"yes"/"no"`— y devuelve `null` para
     * todo lo demás, que aquí es un 422. La cadena vacía se rechaza aparte: ese filtro
     * la lee como `false`, y «no mandé el campo» no puede significar «apágalo».
     */
    private function aplicaDelCuerpo(): bool
    {
        $valor = Request::input('aplica');

        if ($valor === null || $valor === '') {
            abort(422, "Falta 'aplica'.");
        }

        $leido = filter_var($valor, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        if ($leido === null) {
            abort(422, "'aplica' tiene que ser verdadero o falso.");
        }

        return $leido;
    }
}
