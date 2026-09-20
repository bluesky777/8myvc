<?php

namespace App\Http\Controllers\Matriculas;

use App\Http\Controllers\Concerns\ResuelveElUsuario;
use App\Http\Controllers\Controller;
use App\Support\Autoriza;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;

/**
 * **EL LADO DEL COLEGIO DEL PORTAL DE ADMISIÓN.** Pantallas 4, 5 y 6 del lado del
 * colegio en `myvc_front/INVESTIGACION-MATRICULAS.md` §8: la bandeja, la ficha del
 * aspirante y la decisión. El contrato entero está en
 * `docs/migracion/47-el-portal-de-la-familia.md`.
 *
 * Cinco rutas. La familia escribe por `PortalInscripcionController`, que es público;
 * esto es lo que ve y decide el colegio.
 *
 * ## EL PERMISO VA PARTIDO, Y LAS DOS MITADES SON DECISIONES DISTINTAS
 *
 * **Cuatro con `auth.personal` y nada dentro**, que es lo que Joseth decidió el 20
 * sep para todo el día de matrículas: *«cualquiera del personal puede cerrar, pero
 * queda con su nombre y su hora»*. Revisar un documento es exactamente eso —el paso
 * que la estación 2 chulea en el patio, hecho desde el escritorio el martes— y agendar
 * una entrevista también.
 *
 * **Una con el permiso dentro**: `putDecision`, con `Autoriza::puedeDecidirAdmision`.
 * El porqué está en ese método, y en una línea: admitir no es un paso reversible que
 * corrige el de al lado, es **la respuesta del colegio a una familia**, y viaja al
 * portal en cuanto se escribe.
 *
 * ## LA FICHA LEE EL ALUMNO POR LA ORDEN, Y NO POR UNA COLUMNA PROPIA
 *
 * `aspirantes` **no tiene `alumno_id`** — ver la migración. El enlace del papel al
 * alumno vive en `ordenes_inscripcion` y lo escribe una ruta que ya existe. Aquí se
 * sigue, no se copia.
 */
class AspirantesController extends Controller
{
    use ResuelveElUsuario;

    /**
     * Los estados del embudo del que todavía no es alumno.
     *
     * El de los que ya lo son sigue viviendo en `matriculas.estado`
     * (`FORM`/`PREM`/`PREA`/`ASIS`/`MATR`), y el tablero suma los dos. **Dos tablas,
     * un solo embudo**, que es la decisión 2 de `INVESTIGACION-MATRICULAS.md` §7.
     */
    private const EMBUDO = ['FORMULARIO', 'DOCUMENTOS', 'ENTREVISTA', 'ADMITIDO', 'NO_ADMITIDO', 'MATRICULADO'];

    /** Lo que puede pasarle a un documento cuando lo mira quien revisa. */
    private const REVISIONES = ['RECIBIDO', 'DEVUELTO'];

    private const TIPOS_DE_CITA = ['entrevista', 'prueba', 'taller'];

    private const RESULTADOS_DE_CITA = ['PENDIENTE', 'APROBADO', 'CON_COMPROMISO', 'NO_APROBADO'];

    /** El tope de la bandeja. Se dice cuando corta — ver `getIndex`. */
    private const TOPE = 300;

    // ------------------------------------------------------------------
    // Las dos lecturas
    // ------------------------------------------------------------------

    /**
     * **La bandeja: quién está esperando algo del colegio.**
     *
     * Pantalla 4 del lado del colegio: *«secretaría ve documentos por revisar;
     * orientación, entrevistas del día; cartera, los paz y salvo; el rector, los que
     * esperan decisión»*.
     *
     * ## NO SE FILTRA POR ROL, y eso es una decisión medida
     *
     * Aquella pantalla la describe filtrada por rol. **Aquí el filtro es un parámetro
     * y no un permiso**, y el motivo es que en este esquema **el rol de una estación
     * no existe**: se descartó `requisitos_matricula.rol_id` el 20 sep con el motivo
     * escrito (44 §2), porque Joseth decidió que cierra cualquiera del personal.
     *
     * Filtrar aquí por el rol de quien pregunta sería **inventar ese concepto por la
     * puerta de atrás**, y dejaría la bandeja vacía en los dieciséis colegios: la
     * tabla `roles` es por colegio y no está garantizado que dos tengan las mismas
     * filas —en la base de tests hay 11 y en desarrollo 12—. La pantalla elige qué
     * mira; el servidor le da los tres filtros que puede sostener.
     *
     * ## EL TOPE SE DICE CUANDO CORTA
     *
     * Misma regla que `sin_volver` en el informe de campaña y que `sin_terminar` en el
     * tablero: **una lista truncada en silencio se lee como una lista completa**, y en
     * una bandeja eso son familias que nadie va a atender.
     */
    public function getIndex()
    {
        $year = $this->campanaActual();

        $donde = ['a.deleted_at IS NULL', 'a.year_campana = ?'];
        $valores = [$year];

        $embudo = mb_strtoupper(trim((string) Request::input('estado_embudo')));

        if ($embudo !== '') {
            if (! in_array($embudo, self::EMBUDO, true)) {
                abort(422, 'Ese estado del embudo no existe. Los que valen son: '
                    .implode(', ', self::EMBUDO).'.');
            }

            $donde[] = 'a.estado_embudo = ?';
            $valores[] = $embudo;
        }

        $busca = trim((string) Request::input('busca'));

        if ($busca !== '') {
            // Por nombre, apellido o documento, que es como pregunta quien tiene a la
            // familia delante. `CONCAT` y no tres `LIKE` sueltos: quien teclea «ana
            // gómez» está escribiendo un nombre completo, no dos campos.
            $donde[] = '(CONCAT(COALESCE(a.nombres,""), " ", COALESCE(a.apellidos,"")) LIKE ?'
                .' OR a.documento LIKE ? OR o.codigo LIKE ?)';
            $valores[] = '%'.$busca.'%';
            $valores[] = '%'.$busca.'%';
            $valores[] = '%'.mb_strtoupper($busca).'%';
        }

        $total = (int) (DB::selectOne('SELECT COUNT(*) AS n FROM aspirantes a
            INNER JOIN ordenes_inscripcion o ON o.id=a.orden_id AND o.deleted_at IS NULL
            WHERE '.implode(' AND ', $donde), $valores)->n ?? 0);

        $filas = DB::select('SELECT a.id, a.nombres, a.apellidos, a.documento, a.tipo_doc,
                a.grado_id, a.estado_embudo, a.acu_celular, a.updated_at,
                o.codigo, o.estado AS estado_orden, o.modo, o.alumno_id,
                (SELECT COUNT(*) FROM documentos_admision d
                  WHERE d.aspirante_id=a.id AND d.deleted_at IS NULL AND d.estado IN ("SUBIDO","PAPEL")) AS por_revisar,
                (SELECT COUNT(*) FROM citas_admision c
                  WHERE c.aspirante_id=a.id AND c.deleted_at IS NULL AND c.resultado="PENDIENTE") AS citas_pendientes
            FROM aspirantes a
            INNER JOIN ordenes_inscripcion o ON o.id=a.orden_id AND o.deleted_at IS NULL
            WHERE '.implode(' AND ', $donde).'
            ORDER BY a.apellidos, a.nombres, a.id
            LIMIT '.self::TOPE, $valores);

        return [
            'year_campana' => $year,
            'total' => $total,
            'recortada' => $total > count($filas),
            'aspirantes' => array_map(fn ($fila) => [
                'id' => (int) $fila->id,
                'nombres' => $fila->nombres,
                'apellidos' => $fila->apellidos,
                'tipo_doc' => $fila->tipo_doc,
                'documento' => $fila->documento,
                'grado_id' => $fila->grado_id === null ? null : (int) $fila->grado_id,
                'estado_embudo' => $fila->estado_embudo,
                'codigo' => $fila->codigo,
                'estado_orden' => $fila->estado_orden,
                'modo' => $fila->modo,
                // Por la orden, no por una columna de `aspirantes`. Ver la cabecera.
                'alumno_id' => $fila->alumno_id === null ? null : (int) $fila->alumno_id,
                'acu_celular' => $fila->acu_celular,
                'por_revisar' => (int) $fila->por_revisar,
                'citas_pendientes' => (int) $fila->citas_pendientes,
                'updated_at' => $fila->updated_at,
            ], $filas),
        ];
    }

    /**
     * **La ficha del aspirante.** Pantalla 5 del lado del colegio.
     *
     * Aquí sí sale todo: el formulario entero, los documentos **con su archivo**, las
     * citas **con su observación** y las reservadas incluidas. Es la diferencia con lo
     * que devuelve el portal, y no es un descuido: esto lo lee alguien con cuenta del
     * colegio, y aquello lo lee quien tenga un papel.
     */
    public function getFicha($id)
    {
        $aspirante = $this->aspirante($id);

        $orden = DB::selectOne('SELECT codigo, estado, modo, valor, cierra, alumno_id, matricula_id
            FROM ordenes_inscripcion WHERE id=? AND deleted_at IS NULL', [(int) $aspirante->orden_id]);

        $documentos = DB::select('SELECT d.id, d.requisito_id, d.estado, d.archivo, d.nombre_original,
                d.motivo_devolucion, d.devuelto_original, d.recibido_at, d.created_at AS enviado_at,
                r.requisito, r.orden AS estacion,
                p.nombres AS recibido_por_nombres, p.apellidos AS recibido_por_apellidos
            FROM documentos_admision d
            INNER JOIN requisitos_matricula r ON r.id=d.requisito_id AND r.deleted_at IS NULL
            LEFT JOIN users u ON u.id=d.recibido_por AND u.deleted_at IS NULL
            -- **`profesores.user_id` y no `users.profesor_id`**: las dos columnas
            -- existen y la segunda está vacía (0 filas contra 47, medido). Escrito al
            -- revés, «recibido por» saldría en blanco en los diecisiete sin fallar.
            LEFT JOIN profesores p ON p.user_id=u.id AND p.deleted_at IS NULL
            WHERE d.aspirante_id=? AND d.deleted_at IS NULL
            ORDER BY r.orden, r.id, d.id', [(int) $aspirante->id]);

        $citas = DB::select('SELECT c.id, c.tipo, c.cuando, c.donde, c.resultado, c.observacion,
                c.reservada, c.requisito_id
            FROM citas_admision c
            WHERE c.aspirante_id=? AND c.deleted_at IS NULL ORDER BY c.cuando, c.id',
            [(int) $aspirante->id]);

        return [
            'aspirante' => $aspirante,
            'orden' => $orden,
            'requisitos' => $this->requisitosDeLaCampana(),
            'documentos' => $documentos,
            'citas' => $citas,
        ];
    }

    // ------------------------------------------------------------------
    // Las tres escrituras
    // ------------------------------------------------------------------

    /**
     * **Revisar un documento: recibido, o devuelto con motivo.** Pantallas 06 y 08.
     *
     * ## SIN MOTIVO NO SE PUEDE DEVOLVER, Y LO IMPIDE EL SERVIDOR
     *
     * *«Un paso devuelto lleva motivo escrito, siempre. Es la diferencia entre una
     * llamada y ninguna.»* El diseño de la app ya lo hace cumplir en el botón
     * (`estaciones.md` §2.4), y eso no basta: hay cuatro clientes y el botón de uno no
     * protege a los otros tres. Aquí es un 422.
     *
     * ## Y EL MOTIVO VA A SU COLUMNA, QUE ES UN INVARIANTE Y NO UN DETALLE
     *
     * `documentos_admision.motivo_devolucion` existe aparte **porque lo lee la
     * familia**. El día que comparta sitio con una observación interna, un comentario
     * entre docentes acaba en el celular de una madre. Es la misma regla que ya fijó
     * `requisitos_alumno.motivo_devolucion` el 20 sep.
     *
     * ## `devuelto_original` ES LA ÚNICA PREGUNTA DEL MOSTRADOR
     *
     * *«Lo que llega en papel se marca con un botón que pregunta una sola cosa: ¿se
     * devuelve el original?»* No es un campo más: es lo que el colegio tiene que poder
     * demostrar en enero si una familia reclama su registro civil.
     */
    public function putDocumento($id, $documento_id)
    {
        $aspirante = $this->aspirante($id);

        if (! is_numeric($documento_id)) {
            abort(422, 'Ese documento no es válido.');
        }

        $documento = DB::selectOne('SELECT id, estado FROM documentos_admision
            WHERE id=? AND aspirante_id=? AND deleted_at IS NULL',
            [(int) $documento_id, (int) $aspirante->id]);

        if (! $documento) {
            abort(404, 'Ese documento no es de este aspirante.');
        }

        $estado = mb_strtoupper(trim((string) Request::input('estado')));

        if (! in_array($estado, self::REVISIONES, true)) {
            abort(422, 'La revisión sólo puede ser RECIBIDO o DEVUELTO.');
        }

        $motivo = trim((string) Request::input('motivo_devolucion'));

        if ($estado === 'DEVUELTO' && $motivo === '') {
            abort(422, 'Para devolver un documento hay que escribir el motivo: lo lee la familia.');
        }

        $ahora = Carbon::now('America/Bogota');

        DB::update('UPDATE documentos_admision
            SET estado=?, motivo_devolucion=?, devuelto_original=?, recibido_por=?, recibido_at=?, updated_at=?
            WHERE id=?',
            [
                $estado,
                // Al recibir se **limpia** el motivo de una devolución anterior: la
                // familia mandó otro y se lo aceptaron, así que dejar el texto viejo
                // en el portal diría que sigue rechazado.
                $estado === 'DEVUELTO' ? $motivo : null,
                Request::boolean('devuelto_original') ? 1 : 0,
                $this->user->user_id,
                $ahora,
                $ahora,
                (int) $documento->id,
            ]);

        return ['estado' => $estado, 'mensaje' => 'Documento '.mb_strtolower($estado).'.'];
    }

    /**
     * **Agendar una cita, o escribir su resultado.** Pantallas 10 y 11.
     *
     * ## UNA SOLA RUTA PARA LAS DOS COSAS, Y NO ES PEREZA
     *
     * El plan preveía dos —crear y resolver—. Son **una escritura sobre la misma fila**
     * y una cita por tipo y aspirante: la entrevista de orientación de Laura es una
     * cosa, se agenda, se mueve de hora y se resuelve. Dos rutas serían dos caminos a
     * la misma fila, y habría que mantener, documentar y probar los dos para siempre.
     *
     * Es el mismo caso que `accesos-favoritos`, que preveía tres rutas y entregó dos
     * porque el orden es una propiedad de la lista. **Quedarse corto respecto a lo
     * autorizado se cuenta y se dice; no se rellena para cuadrar.**
     *
     * ## `CON_COMPROMISO` ES UN RESULTADO Y NO UNA OBSERVACIÓN
     *
     * *«El compromiso no se pierde en una observación suelta: viaja al observador y
     * aparece cuando el docente abra su planilla en febrero.»* Un texto libre no se
     * puede buscar en febrero; una columna sí. Lo que este endpoint garantiza es que
     * el dato exista y sea consultable — **llevarlo al observador es de otra tanda**, y
     * se dice aquí para que no se lea como hecho.
     */
    public function putCita($id)
    {
        $aspirante = $this->aspirante($id);

        $tipo = mb_strtolower(trim((string) Request::input('tipo')));

        if ($tipo === '') {
            $tipo = 'entrevista';
        }

        if (! in_array($tipo, self::TIPOS_DE_CITA, true)) {
            abort(422, 'Ese tipo de cita no existe. Los que valen son: '
                .implode(', ', self::TIPOS_DE_CITA).'.');
        }

        $resultado = mb_strtoupper(trim((string) Request::input('resultado')));

        if ($resultado === '') {
            $resultado = 'PENDIENTE';
        }

        if (! in_array($resultado, self::RESULTADOS_DE_CITA, true)) {
            abort(422, 'Ese resultado no existe. Los que valen son: '
                .implode(', ', self::RESULTADOS_DE_CITA).'.');
        }

        $ahora = Carbon::now('America/Bogota');
        $cita = DB::selectOne('SELECT id FROM citas_admision
            WHERE aspirante_id=? AND tipo=? AND deleted_at IS NULL ORDER BY id LIMIT 1',
            [(int) $aspirante->id, $tipo]);

        $cuando = trim((string) Request::input('cuando'));
        $donde = trim((string) Request::input('donde'));

        if ($cita) {
            DB::update('UPDATE citas_admision
                SET cuando=?, donde=?, con_quien=?, resultado=?, observacion=?, reservada=?, updated_at=?
                WHERE id=?',
                [
                    $cuando === '' ? null : $cuando,
                    $donde === '' ? null : $donde,
                    $this->conQuien(),
                    $resultado,
                    Request::input('observacion'),
                    Request::boolean('reservada') ? 1 : 0,
                    $ahora,
                    (int) $cita->id,
                ]);

            return ['cita_id' => (int) $cita->id, 'tipo' => $tipo, 'resultado' => $resultado];
        }

        DB::insert('INSERT INTO citas_admision
            (aspirante_id, tipo, requisito_id, cuando, donde, con_quien, resultado, observacion,
             reservada, creada_por, created_at, updated_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
            [
                (int) $aspirante->id,
                $tipo,
                is_numeric(Request::input('requisito_id')) ? (int) Request::input('requisito_id') : null,
                $cuando === '' ? null : $cuando,
                $donde === '' ? null : $donde,
                $this->conQuien(),
                $resultado,
                Request::input('observacion'),
                Request::boolean('reservada') ? 1 : 0,
                $this->user->user_id,
                $ahora,
                $ahora,
            ]);

        return [
            'cita_id' => (int) DB::getPdo()->lastInsertId(),
            'tipo' => $tipo,
            'resultado' => $resultado,
        ];
    }

    /**
     * **La decisión: admitido o no.** La única con el permiso dentro.
     *
     * ## NO CREA EL ALUMNO, Y ESO ES DELIBERADO
     *
     * `INVESTIGACION-MATRICULAS.md` §7 dice *«al admitir se crea el `alumno` y su
     * `matricula`»*. **Aquí no**, y por dos medidas:
     *
     * 1. **`matriculas` tiene ocho escritores en `app/`** y ninguno es éste. Crear una
     *    novena forma de abrir una matrícula es exactamente cómo aparecieron los
     *    huérfanos que `MatriculasHuerfanas` va a buscar.
     * 2. **Asignar grupo y matricular ya es una pantalla, la 14**, con sus rutas
     *    (`matriculas/matricularuno`, `matriculas/matricular-en`) y su folio. Admitir
     *    y matricular son dos actos con días distintos de por medio: se admite en
     *    octubre y se matricula el sábado de matrículas.
     *
     * Lo que esta ruta escribe es **la decisión, con quién y cuándo**. Atar el papel al
     * alumno que secretaría cree después es
     * `PUT informes/formularios-inscripcion/codigo/{codigo}/alumno`, que existe.
     *
     * ## UN «NO ADMITIDO» LLEVA MOTIVO, IGUAL QUE UNA DEVOLUCIÓN
     *
     * Por lo mismo: sin motivo, la familia recibe un no y una llamada que nadie puede
     * contestar. Y `motivo_decision` **viaja al portal**, así que se escribe sabiendo
     * que lo lee la familia.
     */
    public function putDecision($id)
    {
        Autoriza::exigir(Autoriza::puedeDecidirAdmision($this->user),
            'Sólo secretaría o un superusuario pueden decidir una admisión.');

        $aspirante = $this->aspirante($id);

        $decision = mb_strtoupper(trim((string) Request::input('decision')));

        if (! in_array($decision, ['ADMITIDO', 'NO_ADMITIDO'], true)) {
            abort(422, 'La decisión sólo puede ser ADMITIDO o NO_ADMITIDO.');
        }

        $motivo = trim((string) Request::input('motivo_decision'));

        if ($decision === 'NO_ADMITIDO' && $motivo === '') {
            abort(422, 'Para no admitir hay que escribir el motivo: lo lee la familia.');
        }

        $ahora = Carbon::now('America/Bogota');

        DB::update('UPDATE aspirantes
            SET estado_embudo=?, motivo_decision=?, decidido_por=?, decidido_at=?, updated_by=?, updated_at=?
            WHERE id=?',
            [$decision, $motivo === '' ? null : $motivo, $this->user->user_id, $ahora,
                $this->user->user_id, $ahora, (int) $aspirante->id]);

        return ['estado_embudo' => $decision, 'decidido_at' => $ahora->toDateTimeString()];
    }

    // ------------------------------------------------------------------

    private function aspirante($id): object
    {
        if (! is_numeric($id)) {
            abort(422, 'Ese aspirante no es válido.');
        }

        $aspirante = DB::selectOne('SELECT * FROM aspirantes WHERE id=? AND deleted_at IS NULL',
            [(int) $id]);

        if (! $aspirante) {
            abort(404, 'Ese aspirante no existe.');
        }

        return $aspirante;
    }

    /**
     * La campaña que se está mirando.
     *
     * **El año de la sesión más uno NO vale**, y eso lo midió el formulario impreso
     * antes que esto: *«la campaña no siempre es la del año siguiente»* —un aspirante
     * que entra a mitad de curso, el estado `ASIS`, se inscribe al año EN CURSO—. Así
     * que se acepta por parámetro y, si no viene, se toma **la campaña que de verdad
     * tiene formularios**, que es un dato y no una cuenta.
     */
    private function campanaActual(): int
    {
        $pedida = Request::input('year_campana');

        if (is_numeric($pedida)) {
            return (int) $pedida;
        }

        $fila = DB::selectOne('SELECT MAX(year_campana) AS y FROM aspirantes WHERE deleted_at IS NULL');

        if ($fila && $fila->y !== null) {
            return (int) $fila->y;
        }

        // Ni un aspirante todavía: la campaña del año en curso más uno es lo único que
        // queda, y devolver una lista vacía de ella es correcto — es lo que hay.
        $year = DB::selectOne('SELECT year FROM years WHERE actual=1 AND deleted_at IS NULL LIMIT 1');

        return (int) ($year->year ?? 0) + 1;
    }

    /**
     * Con quién es la cita: quien la agenda, salvo que nombre a otro.
     *
     * **Devuelve `int` y no `?int` aunque la columna sea anulable**, y lo dijo larastan:
     * este método siempre tiene a alguien —quien llama tiene sesión—. Dejar el `?`
     * describiría un caso que no existe e invitaría a la siguiente lectura a defenderse
     * de un nulo que nunca llega. *La columna admite nulos porque una cita importada o
     * heredada podría no tener dueño; esta ruta siempre lo tiene.*
     */
    private function conQuien(): int
    {
        $pedido = Request::input('con_quien');

        if (is_numeric($pedido)) {
            return (int) $pedido;
        }

        return (int) $this->user->user_id;
    }

    /** El mismo catálogo que chulea la estación. Ver `PortalInscripcionController`. */
    private function requisitosDeLaCampana(): array
    {
        $filas = DB::select('SELECT r.id, r.orden AS estacion, r.requisito, r.descripcion, r.bloquea
            FROM requisitos_matricula r
            INNER JOIN years y ON y.id=r.year_id AND y.deleted_at IS NULL AND y.actual=1
            WHERE r.deleted_at IS NULL
            ORDER BY r.orden, r.id');

        return array_map(fn ($fila) => [
            'requisito_id' => (int) $fila->id,
            'estacion' => (int) $fila->estacion,
            'requisito' => $fila->requisito,
            'descripcion' => $fila->descripcion,
            'bloquea' => (bool) $fila->bloquea,
        ], $filas);
    }
}
