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
