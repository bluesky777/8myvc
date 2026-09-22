<?php

namespace App\Http\Controllers;

use App\Exports\LibroDeNotas;
use App\Http\Controllers\Concerns\ResuelveElUsuario;
use App\Services\ActaDeLaImportacion;
use App\Services\EnsayoDeLaPlanilla;
use App\Services\EscrituraDeNotasImportadas;
use App\Services\LaPlanillaQueSeDescarga;
use App\Services\LaPlanillaQueSeSube;
use App\Services\PuntoDeControlDeImportacion;
use App\Services\RespuestasDeLaPlanilla;
use App\Support\Autoriza;
use App\Support\Reloj;
use App\Support\SafeUpload;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * **La planilla de notas en Excel, para trabajar sin internet.** Fase 1: la
 * descarga, y sólo la descarga.
 *
 * El plan entero está en `myvc_front/PLAN-NOTAS-SIN-INTERNET.md` y el resumen de
 * lo construido en
 * [49](../../../docs/migracion/49-la-planilla-sin-internet.md). El encargo de
 * Joseth que decide el orden de las fases es literal: *«que la app también permita
 * descargarlo aunque no permita importarlo»*. Esto ya le sirve a un docente —baja,
 * pasa las notas en el bus y las teclea cuando llegue— y se despliega sin nada de
 * lo demás.
 *
 * ## TRES RUTAS NUEVAS Y NINGUNA BANDERA SOBRE LAS DE SIEMPRE (D8)
 *
 * `notas/detailed`, `notas/lote`, `notas/update`, `ausencias/*` y `notas/nivelar/*`
 * **no se tocan**. Tienen instantánea de contrato con cuatro clientes, uno de
 * ellos versiones viejas de `myvc_flutter` que conviven meses. Es el mismo caso
 * que `notas/nivelar/*`, que se hicieron rutas nuevas en vez de una bandera para
 * que un 95 tecleado desde un móvil viejo no se guardara como 70.
 *
 * ## LAS TRES SON DE LECTURA, ASÍ QUE **NO EXIGEN PERIODO ABIERTO**
 *
 * Es la D1 y es lo primero que se rompería copiando la guarda de al lado: el
 * docente *«quiere guardarse el año entero en su carpeta»*, y para eso tiene que
 * poder bajar periodos cerrados. Lo que cambia con el periodo cerrado es **el
 * archivo**: banda roja en la portada y `-consulta` en el nombre, porque ése no se
 * va a poder subir. La guarda del periodo abierto vive en la fase 2, donde se
 * escribe.
 *
 * ## Y NO SE ESCRIBE NADA EN `notas`
 *
 * Ni siembra ni recálculo. El porqué entero está en
 * `App\Services\LaPlanillaQueSeDescarga`: `putDetailed` hace las dos cosas, y un
 * docente bajándose cuatro periodos sembraría cuatro periodos de filas en un
 * colegio en producción.
 *
 * Lo único que se escribe es **la fila de auditoría de la descarga**
 * (`descargas_de_planilla`), que es rastro de que el fichero salió y no un cambio
 * en los datos del colegio.
 */
class PlanillaOfflineController extends Controller
{
    use ResuelveElUsuario;

    /**
     * `GET planilla-offline/periodos` — qué puede bajar este docente, y de qué
     * periodos.
     *
     * Es lo que alimenta la pantalla corta de la §6.1 del plan. **Sale de un
     * endpoint y no de la sesión** porque tiene que saber **cuáles están abiertos**:
     * sin eso la pantalla no puede pintar el aviso de la copia de consulta, y el
     * docente descubriría que su libro no se puede subir al intentar subirlo.
     *
     * `?profesor_id=` para que coordinación mire lo de otro (D4). Ver
     * {@see profesorPedido}.
     *
     * @return array<string, mixed>
     */
    public function getPeriodos()
    {
        $profesor = $this->profesorPedido();
        $anio = LaPlanillaQueSeDescarga::cabeceraDelAnio((int) $this->user->year_id);

        $asignaturas = LaPlanillaQueSeDescarga::asignaturasDelProfesor($profesor->id, $anio->year_id);
        $ids = array_map(static fn ($a) => (int) $a->asignatura_id, $asignaturas);

        $periodos = [];

        foreach (LaPlanillaQueSeDescarga::periodosDelAnio($anio->year_id) as $periodo) {
            $recuentos = LaPlanillaQueSeDescarga::recuentosDelPeriodo($ids, $periodo->id);

            $deEstePeriodo = [];

            foreach ($asignaturas as $asignatura) {
                $recuento = $recuentos[(int) $asignatura->asignatura_id] ?? null;

                /*
                 * **Aquí NO se filtra por «tiene indicadores», y eso es un arreglo del
                 * 21 sep 2026, no una omisión.**
                 *
                 * Hasta esa tarde esta lista se saltaba las asignaturas con cero
                 * indicadores y **el generador del libro no**, así que las dos rutas
                 * contaban distinto. Medido contra `caz_zaragoza` conduciendo las tres
                 * rutas con el token de `administrador`: el docente 3 tiene **26**
                 * asignaturas en el año 10 y sólo **21** tienen unidades en el periodo
                 * 39, o sea que `/periodos` decía 21 y `libro/39` bajaba un `.xlsx` con
                 * **26 hojas de asignatura**.
                 *
                 * Y no es una diferencia de números: la pantalla pinta «Descargar el
                 * libro (21 hojas)» y bajan 26, y **la tabla de la portada del propio
                 * libro no cuadra con sus pestañas**. El botón mentía.
                 *
                 * **Manda el libro y no la pantalla**, por la D12: una asignatura sin
                 * indicadores es justo el caso para el que existen las columnas de
                 * reserva — el docente se lleva la hoja, propone los indicadores y los
                 * trae de vuelta. Esconderla de la lista y metérsela en el archivo era
                 * lo peor de las dos opciones: ni la ve para elegirla ni se libra de
                 * ella.
                 *
                 * Sale con `indicadores: 0` y `sin_pasar: 0`, que es lo que el front
                 * necesita para pintarla distinta si quiere. Lo que ata las dos rutas
                 * es `PlanillaOfflineTest::las_hojas_del_libro_son_las_asignaturas_que_lista_periodos`,
                 * porque este pareado **no lo sujetaba nada**.
                 */
                $deEstePeriodo[] = [
                    'asignatura_id' => (int) $asignatura->asignatura_id,
                    'grupo_id' => (int) $asignatura->grupo_id,
                    'materia' => $asignatura->materia,
                    'alias_materia' => $asignatura->alias_materia,
                    'nombre_grupo' => $asignatura->nombre_grupo,
                    'abrev_grupo' => $asignatura->abrev_grupo,
                    'alumnos' => $recuento->alumnos,
                    'indicadores' => $recuento->indicadores,
                    'sin_pasar' => $recuento->sin_pasar,
                ];
            }

            $periodos[] = [
                'id' => $periodo->id,
                'numero' => $periodo->numero,
                // `abierto` es `periodos.profes_pueden_editar_notas`, la única marca de
                // «cerrado» que existe hoy. **Aquí no decide si se puede descargar**
                // (siempre se puede, D1): decide cómo sale rotulado el archivo.
                'abierto' => $periodo->abierto,
                'asignaturas' => $deEstePeriodo,
            ];
        }

        return [
            'year_id' => $anio->year_id,
            'periodo_actual_id' => LaPlanillaQueSeDescarga::periodoActualDelAnio($anio->year_id),
            'nota_minima' => $anio->nota_minima,
            'escala_maxima' => $anio->escala_maxima,
            'escala_minima' => $anio->escala_minima,
            'unidad_displayname' => $anio->unidad_displayname,
            'unidades_displayname' => $anio->unidades_displayname,
            'subunidad_displayname' => $anio->subunidad_displayname,
            'subunidades_displayname' => $anio->subunidades_displayname,
            'reparto' => $anio->reparto,
            'profesor' => ['id' => $profesor->id, 'nombre' => $profesor->nombre],
            'periodos' => $periodos,
        ];
    }

    /**
     * `GET planilla-offline/libro/{periodo_id}` — el `.xlsx` con todas sus hojas.
     *
     * `?asignaturas=301,302` para bajar sólo unas cuantas, `?profesor_id=` para la
     * D4. Sin `asignaturas` entran todas las que tengan indicadores en ese periodo.
     *
     * **El año sale del periodo, no del token.** Es lo que hace posible la D1 sin
     * cambiarle la sesión al docente: pedir el periodo 1 de un año pasado no puede
     * exigir que el usuario esté «situado» en ese año, porque `Services\Login`
     * reescribe su periodo al actual en cada inicio de sesión.
     */
    public function getLibro($periodo_id): BinaryFileResponse
    {
        $periodo = $this->periodoPedido((int) $periodo_id);
        $profesor = $this->profesorPedido();

        $suyas = LaPlanillaQueSeDescarga::asignaturasDelProfesor($profesor->id, $periodo->year_id);
        $ids = array_map(static fn ($a) => (int) $a->asignatura_id, $suyas);

        $pedidas = $this->asignaturasPedidas();

        if ($pedidas !== null) {
            $ajenas = array_values(array_diff($pedidas, $ids));

            // **403 y no «las ignoro en silencio».** Pedir una asignatura que no es
            // suya es o un error de la pantalla o un intento; las dos cosas tienen que
            // verse. Descartarlas calladamente devolvería un libro con menos hojas de
            // las pedidas y nadie sabría por qué.
            Autoriza::exigir(
                $ajenas === [],
                'Esas asignaturas no son de este docente en ese periodo: '.implode(', ', $ajenas).'.'
            );

            $ids = array_values(array_intersect($ids, $pedidas));
        }

        return $this->entregar($periodo, $profesor, $ids);
    }

    /**
     * `GET planilla-offline/planilla/{asignatura_id}/{periodo_id}` — el mismo libro
     * con **una sola hoja**.
     *
     * Es la segunda puerta de la §6.1: el botón de la barra de
     * `planilla-notas/:asignatura_id`, que baja **esa** planilla sin preguntar
     * nada, y la que usa Flutter desde su lista de asignaturas.
     *
     * **El mismo libro y no un formato aparte**, y eso es lo que le da su valor: la
     * portada, la hoja `_myvc`, la firma y la protección son idénticas, así que la
     * fase 2 tiene **un** lector y no dos. Un libro de una hoja es un libro de `n`
     * hojas con `n = 1`.
     */
    public function getPlanilla($asignatura_id, $periodo_id): BinaryFileResponse
    {
        $periodo = $this->periodoPedido((int) $periodo_id);

        $dueno = LaPlanillaQueSeDescarga::duenoDeLaAsignatura((int) $asignatura_id);

        if ($dueno === null) {
            abort(404, 'Esa asignatura no existe o está borrada.');
        }

        if ($dueno->year_id !== $periodo->year_id) {
            // No es un permiso: es que la pregunta no se sostiene. Una asignatura vive
            // en un grupo y un grupo en un año; pedirle el periodo de otro año es pedir
            // una planilla que nunca existió.
            abort(422, 'Esa asignatura no es del año de ese periodo.');
        }

        $profesor = $this->profesorDeLaAsignatura($dueno);

        return $this->entregar($periodo, $profesor, [$dueno->asignatura_id]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // FASE 2 — el ensayo y la escritura
    //
    // Las dos rutas de la D8, y las dos con el mismo contrato que el importador
    // de alumnos: **multipart con el fichero y `respuestas` en JSON**, porque las
    // instrucciones son parte de «sube esto así» y no un recurso aparte.
    //
    // La diferencia que las separa se lee en una línea: `ensayo` **no escribe** y
    // `importar` **es reanudable**. Todo lo demás —el lector, el diagnóstico, las
    // decisiones— es exactamente el mismo código, y tiene que serlo: lo que se
    // promete y lo que se hace salen del mismo recorrido.
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * `POST planilla-offline/ensayo` — **qué va a pasar si subo esto**.
     *
     * No escribe una sola fila. Ni un `INSERT`, ni un `UPDATE`, ni una
     * transacción: lo fija
     * `PlanillaOfflineImportarTest::el_ensayo_no_escribe_ni_una_fila`, que cuenta
     * cuatro tablas antes y después.
     *
     * Acepta **las mismas instrucciones que la subida**, y ésa es la pieza que hace
     * verdad a las demás: corregir, volver a ensayar, ver el plan **corregido** y
     * subir. Sin esa vuelta, lo que se enseña es el plan de antes de las
     * correcciones y la promesa se rompe justo donde más duele.
     */
    public function postEnsayo()
    {
        if (! Request::hasFile('file')) {
            return response()->json(['ok' => false, 'msg' => 'No se encontró archivo.'], 422);
        }

        $archivo = request()->file('file');
        $huella = hash_file('sha256', $archivo->getRealPath());
        $respuestas = new RespuestasDeLaPlanilla($this->respuestasDelCuerpo() ?? []);

        if ($respuestas->sonDeOtroFichero($huella)) {
            return response()->json([
                'ok' => false,
                'msg' => 'Las instrucciones que llegaron son de otro archivo. '
                       .'Vuelva a revisarlo antes de subirlo.',
            ], 422);
        }

        // **UN FICHERO ILEGIBLE CONTESTA 422 EN JSON, NO UN 500 EN HTML.**
        // `LaPlanillaQueSeSube::abrir` no lanza: un archivo que no se puede abrir es
        // un peldaño 5 con su motivo, y el motivo es justo lo que la pantalla
        // necesita para ofrecer «descargar el libro correcto». Un 500 llega al
        // navegador sin cabeceras de CORS y se convierte en «no se pudo leer el
        // archivo» — la lección que `postEnsayo` de alumnos ya pagó.
        $lector = LaPlanillaQueSeSube::abrir($archivo->getRealPath());

        try {
            $ensayo = new EnsayoDeLaPlanilla($lector, $respuestas, $this->user);
            $diagnostico = $ensayo->estudiar();
        } finally {
            $lector->cerrar();
        }

        return response()->json(array_merge($diagnostico, [
            // LA HUELLA DEL FICHERO QUE SE ESTUDIÓ, y no es informativa: es lo que
            // ata este plan a un fichero concreto. Con ella delante, la pantalla
            // puede decir «esto que te enseñé era de otro fichero» antes de que
            // nadie pulse. Es el mismo sha256 con el que
            // `PuntoDeControlDeImportacion` reconoce «el mismo archivo».
            'huella' => $huella,

            // Va en la respuesta y no sólo en la documentación porque es lo que la
            // pantalla le promete a quien pulsa. Si algún día dejara de ser cierto,
            // esta línea es una mentira que se lee.
            'escribe' => false,
        ]));
    }

    /**
     * `POST planilla-offline/importar` — escribe, y **es reanudable**.
     *
     * ## El orden es el que importa
     *
     * 1. Se abre el libro y se comprueba **quién puede subirlo** (403 si no puede).
     * 2. Se toma el cerrojo del archivo, para que dos pestañas no reanuden lo mismo.
     * 3. Se ensaya **con el punto de control**, así que lo ya aplicado ni se
     *    estudia.
     * 4. Si hay bloqueos, **422 y no se escribe nada**.
     * 5. Se aplica el plan, fila a fila, cada una en su transacción.
     * 6. Se anota **lo que entró** en `importaciones.hechos`, que es lo que el acta
     *    lee cuando alguien pregunta semanas después.
     *
     * ## La autorización, que aquí es más estrecha que en la descarga
     *
     * Las tres rutas de la fase 1 son de lectura y su puerta es ancha a propósito
     * —coordinación puede bajar la planilla de un docente—. **Ésta no**:
     *
     * - **Se exige periodo abierto**, y se exige por hoja: la F3 dice que un periodo
     *   cerrado se lleva por delante sus hojas *y nada más*, así que no es una
     *   guarda que tire la petición sino un filtro que deja pasar el resto. Si no
     *   queda ninguna hoja que escribir, 422 con los motivos. **Subir *por* un
     *   docente no relaja esto**: el permiso de la D4 no abre un periodo cerrado.
     * - **El docente sólo escribe lo suyo, y coordinación escribe el de otro
     *   diciéndolo.** Es la D4, y desde la fase 5 está abierta: quien tiene
     *   {@see Autoriza::puedeSubirLaPlanillaDeOtro} pasa esta puerta, y lo que le
     *   espera dentro es el bloqueo `subir_por_otro` —que hay que resolver a mano—
     *   y el acta de lo que entró. Quien no lo tiene sigue recibiendo 403 aquí.
     *
     * ## Y por qué la confirmación NO se comprueba en este método
     *
     * Porque **el 422 de los bloqueos ya la hace cumplir, y lo hace antes de
     * escribir nada**: `subir_por_otro` es un bloqueo como los demás, así que sin
     * la sección `por_otro` en las instrucciones el paso 4 devuelve 422 **con el
     * plan entero sin aplicar**. Repetir aquí la comprobación sería una segunda
     * puerta que hay que mantener de acuerdo con la primera, y el día que dejaran
     * de estarlo la que mandaría sería la de abajo.
     */
    public function postImportar()
    {
        if (! Request::hasFile('file')) {
            return response()->json(['ok' => false, 'msg' => 'No se encontró archivo.'], 422);
        }

        $archivo = request()->file('file');
        $huella = hash_file('sha256', $archivo->getRealPath());
        $respuestas = new RespuestasDeLaPlanilla($this->respuestasDelCuerpo() ?? []);

        if ($respuestas->sonDeOtroFichero($huella)) {
            return response()->json([
                'ok' => false,
                'msg' => 'Las instrucciones que llegaron son de otro archivo. '
                       .'Vuelva a revisarlo antes de subirlo.',
            ], 422);
        }

        $lector = LaPlanillaQueSeSube::abrir($archivo->getRealPath());

        if ($lector->cabecera === null) {
            $lector->cerrar();

            return response()->json([
                'ok' => false,
                'peldano' => $lector->peldano,
                'msg' => $lector->motivo
                    ?? 'Este archivo no trae la hoja interna que dice qué columna es qué indicador. '
                       .'Pase primero por el ensayo para ver qué le pasa.',
            ], 422);
        }

        $this->exigirPoderSubirEsteLibro($lector);

        $year = (int) ($lector->cabecera['year'] ?? 0);

        // **El cerrojo, y aquí hace falta por lo mismo que en alumnos**: `abrir()`
        // reanuda sin bloquear nada, así que dos peticiones a la vez con el mismo
        // archivo reanudarían la misma fila. Se suelta al terminar, y si el proceso
        // muere se suelta solo al caerse la conexión.
        if (! PuntoDeControlDeImportacion::tomarCerrojo('planilla', $huella, $year)) {
            $lector->cerrar();

            return response()->json([
                'ok' => false,
                'msg' => 'Esa misma planilla se está subiendo ahora mismo en otra ventana. '
                       .'Espere a que termine antes de volver a subirla.',
            ], 409);
        }

        $punto = PuntoDeControlDeImportacion::abrir(
            'planilla', $huella, $year, SafeUpload::nombreParaGuardar($archivo), $this->user->user_id
        );

        // Se guardan ANTES de leer una sola fila, porque el caso en que hacen falta
        // es precisamente aquel en el que esto se corta a la mitad.
        $punto->guardarRespuestas($this->respuestasDelCuerpo());

        try {
            // **El presupuesto de la subida es el de la subida, no el del ensayo.**
            // Son dos números distintos en `config/importacion.php` y significan
            // cosas distintas: el del ensayo es «o cabe o se recorta» —no puede
            // reanudarse— y el de la subida es «cuánto avanzo en esta petición»,
            // porque lo que no quepa lo continúa la siguiente. Leer aquí el del
            // ensayo ataría la reanudación a un número que se puso para otra cosa.
            $ensayo = new EnsayoDeLaPlanilla(
                $lector, $respuestas, $this->user,
                (float) config('importacion.segundos_por_peticion', 20),
                $punto
            );
            $diagnostico = $ensayo->estudiar();

            if ($diagnostico['bloqueos'] !== []) {
                // **422 y no se escribe nada.** Un bloqueo es «este libro no se puede
                // subir»: si se escribiera lo que sí se puede y se avisara del resto,
                // el docente tendría media planilla dentro y un error delante.
                return response()->json([
                    'ok' => false,
                    'msg' => 'Esta planilla no se puede subir todavía.',
                    'bloqueos' => $diagnostico['bloqueos'],
                    'peldano' => $diagnostico['peldano'],
                ], 422);
            }

            $entran = array_values(array_filter($ensayo->plan, static fn ($h) => $h['entra'] === true));

            if ($entran === []) {
                // **«No entró nada» también es un acta, y es la que hace falta.** Aquí
                // el libro era legítimo y aun así no se escribió una línea —el periodo
                // se cerró mientras el docente pasaba las notas, casi siempre—, así que
                // lo que hay que poder contestar después es *por qué*, hoja por hoja.
                // Sin esto, esta fila de `importaciones` quedaría sin `hechos` y el acta
                // diría «es anterior al acta», que es falso.
                $this->anotarLoHecho(
                    $punto, $diagnostico,
                    new EscrituraDeNotasImportadas($this->user, $punto),
                    $ensayo,
                    $this->contextoDeLaImportacion($diagnostico, $punto)
                );

                return response()->json([
                    'ok' => false,
                    'msg' => 'Ninguna hoja de este libro se puede escribir ahora mismo.',
                    'por_hoja' => $diagnostico['por_hoja'],
                    'hojas' => $diagnostico['hojas'],
                ], 422);
            }

            $contexto = $this->contextoDeLaImportacion($diagnostico, $punto);

            // **El rastro lleva las dos personas cuando son dos** (D4). Sin esto, la
            // línea de auditoría de cada nota dice que la editó coordinación y el
            // docente cuyo libro entró no aparece en ningún sitio — que es justo el
            // dato que se reclama el día que se reclama algo.
            $escritura = new EscrituraDeNotasImportadas(
                $this->user, $punto, $contexto['por_cuenta_de']['nombre'] ?? null
            );

            try {
                $escritura->aplicar($ensayo->plan, (int) $diagnostico['totales']['filas_descartadas']);
            } catch (\Throwable $e) {
                // La fila queda en 'fallida', que se reanuda igual que 'en_proceso':
                // lo escrito hasta aquí está anotado y la siguiente subida del mismo
                // archivo continúa desde ahí.
                //
                // **Y el acta se escribe igual, antes de relanzar.** Lo que entró antes
                // del error está escrito en `notas`; un acta que sólo contara las
                // pasadas que acabaron bien sería un acta que no cuenta lo que entró,
                // y el caso en que alguien la pide es precisamente éste.
                $this->anotarLoHecho($punto, $diagnostico, $escritura, $ensayo, $contexto);
                $punto->fallar($e);
                throw $e;
            }

            $punto->anotarElTotal($ensayo->filasDelLibro);
            $this->anotarLoHecho($punto, $diagnostico, $escritura, $ensayo, $contexto);

            // **Sólo se cierra si de verdad terminó.** Si se acabó el tiempo, la fila
            // se queda en `en_proceso` con su avance, que es justo lo que `abrir()`
            // sabe reanudar en la petición siguiente.
            if (! $ensayo->recortado) {
                $punto->completar();
            }

            return response()->json([
                'ok' => true,

                // **`terminado` y no «completo»**, y la diferencia es de quien la lee:
                // un `false` aquí no es un error, es «queda archivo». El asistente **no
                // reenvía solo**: enseña el recuento y ofrece «Continuar donde se
                // quedó», que vuelve a mandar el mismo fichero.
                'terminado' => ! $ensayo->recortado,
                'huella' => $huella,

                // Lo hecho de verdad, no lo prometido. El plan se puede comparar con
                // esto —es la tabla de «prometido contra hecho» del importador de
                // alumnos— y por eso los dos salen del mismo recorrido.
                'hechos' => $escritura->hechos,
                'por_hoja' => $escritura->porHoja,

                'reanudada' => $punto->reanudada(),

                // Frases, no números: «entraron 312 notas» no le dice nada a nadie si
                // además se cayó una hoja entera y nadie lo dijo.
                'avisos' => $ensayo->avisos(),
                'respuestas' => $diagnostico['respuestas'],

                // Lo que no está en el contrato del front y viaja igual, porque es lo
                // único con lo que se puede leer un incidente desde fuera: el `id` de
                // la fila de `importaciones`, por dónde iba y qué indicadores creó la F9.
                'importacion_id' => $punto->id(),
                'filas_del_libro' => $ensayo->filasDelLibro,
                'filas_estudiadas' => $ensayo->filasEstudiadas,
                'filas_ya_estaban_hechas' => $ensayo->filasYaHechas,
                'indicadores_creados' => $escritura->indicadoresCreados,
            ]);
        } finally {
            // **Se suelta DESPUÉS de cerrar la importación, no antes**: soltarlo
            // dentro del `try` dejaría que otra petición entrara, encontrara la fila
            // todavía en `en_proceso` y la reanudara mientras ésta la cierra.
            PuntoDeControlDeImportacion::soltarCerrojo('planilla', $huella, $year);
            $lector->cerrar();
        }
    }

    /**
     * **Quién puede subir ESTE libro.** 403 con el motivo, no en silencio.
     *
     * Dos puertas, y en ese orden:
     *
     * 1. **El dueño**, que es el caso de siempre y el de los cincuenta y tres
     *    docentes: si el libro lleva su `profesor_id`, pasa.
     * 2. **Coordinación** (D4, fase 5): {@see Autoriza::puedeSubirLaPlanillaDeOtro}
     *    —superusuario o `can_edit_plantilla_notas`—. **Más estrecha que la de
     *    bajar**: `Secretario` baja el libro de un docente y no lo sube. El porqué
     *    está entero en el docblock de ese método.
     *
     * ## Aquí se contesta «puede», y en el ensayo se contesta «quiso»
     *
     * Esta puerta deja pasar a coordinación **sin preguntar nada más**, y no es un
     * descuido: dentro le espera el bloqueo `subir_por_otro`, que hay que resolver
     * a mano con la sección `por_otro` de las instrucciones. Las dos preguntas son
     * distintas y por eso están en sitios distintos — el permiso vale para los
     * cincuenta y tres docentes del colegio, así que confundirse de archivo es
     * escribir las notas de un grupo que nadie ha mirado, y lo único que lo evita
     * es tener que **leer de quién es el libro** antes de que pase nada.
     *
     * Y lo que esta puerta **no** relaja: el periodo sigue teniendo que estar
     * abierto. Subir *por* un docente no es subir *a* un periodo cerrado.
     *
     * `persona_id` y no `user_id` — para un `Profesor` es `profesores.id`, que es lo
     * que compara `asignaturas.profesor_id`; para un administrativo es `users.id`, o
     * sea **un número que casaría con la ficha de otra persona**. De ahí el
     * `tipo === 'Profesor'`, que es la misma trampa que ya tiene escrita
     * {@see profesorPedido}.
     */
    private function exigirPoderSubirEsteLibro(LaPlanillaQueSeSube $lector): void
    {
        $propio = ($this->user->tipo ?? '') === 'Profesor' ? (int) $this->user->persona_id : null;
        $delLibro = (int) ($lector->cabecera['profesor_id'] ?? 0);

        if ($propio !== null && $propio === $delLibro) {
            return;
        }

        if (Autoriza::puedeSubirLaPlanillaDeOtro($this->user)) {
            return;
        }

        $lector->cerrar();

        Autoriza::exigir(false,
            'Esta planilla es de otro docente, y cada docente sólo puede subir la suya. '
            .'Subir la planilla de otro es cosa de coordinación académica.'
        );
    }

    /**
     * Las dos personas y el libro, tal y como el acta los va a imprimir.
     *
     * Se arma aquí —y no dentro del acta— porque es el único sitio donde están a la
     * vez la sesión de quien sube y la cabecera del libro que se está subiendo.
     *
     * **Los nombres se guardan, los ids también.** Los ids para poder decidir quién
     * puede pedir el acta, y los nombres porque una cuenta se borra y entonces el
     * nombre guardado es lo único que queda de quién fue. Es la misma regla que ya
     * sigue `auditoria.actor_nombre`.
     *
     * `por_cuenta_de` es `null` cuando el libro es de quien lo sube, y no una copia
     * de la misma persona: un acta que dijera «por cuenta de sí mismo» en las
     * cincuenta y tres subidas normales convertiría en ruido el único renglón que
     * importa en la subida rara.
     *
     * @param  array<string, mixed>  $diagnostico
     * @return array<string, mixed>
     */
    private function contextoDeLaImportacion(array $diagnostico, PuntoDeControlDeImportacion $punto): array
    {
        $libro = $diagnostico['libro'] ?? [];
        $profesor = $libro['profesor'] ?? [];

        $esMio = ($libro['es_mio'] ?? false) === true;

        return [
            'subio' => [
                'user_id' => (int) $this->user->user_id,
                'nombre' => trim((string) ($this->user->nombres ?? '').' '.($this->user->apellidos ?? ''))
                    ?: ($this->user->username ?? null),
                'tipo' => $this->user->tipo ?? null,
            ],
            'por_cuenta_de' => $esMio ? null : [
                'profesor_id' => $profesor['id'] ?? null,
                'nombre' => $profesor['nombre'] ?? null,
            ],
            'libro' => [
                'profesor_id' => $profesor['id'] ?? null,
                'profesor' => $profesor['nombre'] ?? null,
                'year_id' => $libro['year_id'] ?? null,
                'periodo_id' => $libro['periodo_id'] ?? null,
                'periodo_numero' => $libro['periodo_numero'] ?? null,
                'descargado_at' => $libro['descargado_at'] ?? null,
            ],
            'reanudada' => $punto->reanudada(),
        ];
    }

    /**
     * Deja escrito en la fila de `importaciones` **lo que esta pasada hizo**.
     *
     * Las dos columnas, y son dos porque contestan dos preguntas distintas:
     *
     * - **`hechos`** — el recuento de lo que entró, por hoja, con las dos personas.
     *   Es el acta.
     * - **`avisos`** — lo que no entró y **no tiene hoja**: la F4 (un «4,5» que
     *   aparece en seis hojas) y la F5. Se acumulan aparte porque se agrupan por
     *   valor y no por hoja, y porque esa columna ya existía y ya sabe acumular.
     *
     * **Esta familia no las escribía.** `postImportar` las devolvía en la respuesta
     * y las perdía al contestar; el importador de alumnos sí las guardaba desde la
     * migración de septiembre. Es el agujero que la fase 5 viene a cerrar, y la
     * frase que lo resume es la del encargo: *el acta tiene que contar lo que entró,
     * no lo que se prometió*.
     *
     * @param  array<string, mixed>  $diagnostico
     * @param  array<string, mixed>  $contexto
     */
    private function anotarLoHecho(PuntoDeControlDeImportacion $punto, array $diagnostico,
        EscrituraDeNotasImportadas $escritura, EnsayoDeLaPlanilla $ensayo, array $contexto): void
    {
        $punto->guardarHechos(ActaDeLaImportacion::deLaPasada($diagnostico, $escritura, $contexto));
        $punto->guardarAvisos($ensayo->avisos());
    }

    /**
     * `GET planilla-offline/acta/{importacion_id}` — **el acta de lo que entró**.
     *
     * Un `.xlsx`. El porqué —que en este backend no hay librería de PDF y que los
     * informes los imprime el front desde el navegador— está entero en la cabecera
     * de {@see ActaDeLaImportacion}.
     *
     * ## Quién la puede pedir, y por qué son exactamente tres
     *
     * 1. **Quien la subió.** Es su propio recibo.
     * 2. **El docente dueño del libro.** Es el que más derecho tiene a saber qué se
     *    escribió en sus notas, y es la mitad que hace útil a la fase entera: sin
     *    esto, coordinación podría subir por él y él no tendría con qué comprobarlo.
     * 3. **Quien puede subir por otro** ({@see Autoriza::puedeSubirLaPlanillaDeOtro}).
     *    Quien puede hacerlo tiene que poder revisarlo — el suyo y el de sus
     *    compañeros de coordinación.
     *
     * **Un docente no puede leer el acta de otro**, y el 403 lo dice con su motivo:
     * el acta lleva dentro los nombres de los alumnos de un grupo y el recuento de
     * sus notas, o sea lo mismo que la planilla, y la puerta de la planilla ya es
     * ésta. Que un docente pudiera pedir actas por `id` sería un listado de las
     * notas del colegio a razón de una petición por número.
     *
     * ## Los dos 404, que no son el mismo
     *
     * - **No existe, o no es de una planilla.** Las importaciones de alumnos viven
     *   en la misma tabla y no tienen acta: pedirla es pedir un documento que no
     *   existe para esa fila.
     * - **Es de una planilla y no tiene `hechos`.** Sólo puede pasar con las de
     *   antes de esta migración. Se contesta 404 **con ese motivo** y no un acta de
     *   ceros, que se leería como «no entró nada» — que es falso y es peor que no
     *   contestar.
     */
    public function getActa($importacion_id): BinaryFileResponse
    {
        $fila = DB::selectOne(
            'SELECT id, tipo, huella, archivo, year, estado, error, created_by, inicio, fin,
                    created_at, hechos, avisos
               FROM importaciones WHERE id = ?',
            [(int) $importacion_id]
        );

        if ($fila === null || $fila->tipo !== 'planilla') {
            abort(404, 'No hay ninguna importación de planilla con ese número.');
        }

        $hechos = json_decode((string) ($fila->hechos ?? ''), true);
        $hechos = is_array($hechos) ? $hechos : [];

        $this->exigirPoderVerElActa($fila, $hechos);

        if ($hechos === []) {
            abort(404, 'Esa importación no tiene acta: o se quedó en un bloqueo y no llegó a mirar '
                .'ninguna hoja, o es anterior a que el acta existiera. Se guardó por dónde iba, '
                .'pero no el recuento de lo que entró.');
        }

        $avisos = json_decode((string) ($fila->avisos ?? ''), true);

        // LAS FECHAS SE CONVIERTEN ANTES DE QUE EL ACTA LAS VEA.
        //
        // `importaciones` es la excepción declarada de `RelojUnicoTest`: se escribe
        // entera con `now()`, o sea en **UTC**, y ahí se queda. La decisión de mover
        // la tabla sigue siendo de quien lleve las importaciones; el porqué largo
        // está en `Alumnos/ImportarController.php` §«LAS FECHAS SE CONVIERTEN AL
        // LEER», que hace esto mismo para la pantalla.
        //
        // **Y el acta no lo hacía**, así que hasta el 21 sep 2026 el Excel decía
        // «Empezó a las 14:41» donde la pantalla de esa misma fila decía las 9:41.
        // La misma tabla leída de dos maneras en el mismo repo, y el Excel es el que
        // se imprime y se archiva. Ver el 53 §4.2.
        //
        // La conversión vive en el servicio y no aquí a propósito: eran tres
        // lectores de la misma fila y sólo uno se acordaba.
        $fila = PuntoDeControlDeImportacion::enLaHoraDelColegio($fila);

        $libro = ActaDeLaImportacion::construir($fila, $hechos, is_array($avisos) ? $avisos : []);

        $ruta = tempnam(sys_get_temp_dir(), 'acta-importacion-');

        if ($ruta === false) {
            abort(500, 'No se pudo preparar el acta.');
        }

        (new Xlsx($libro))->save($ruta);
        $libro->disconnectWorksheets();

        // `deleteFileAfterSend`, por lo mismo que la descarga de la planilla: el acta
        // lleva dentro los nombres de los alumnos, y `storage/` de dieciséis colegios
        // acumulando una copia por consulta es un archivo que nadie mira nunca.
        return response()->download($ruta, ActaDeLaImportacion::nombreDeArchivo($fila, $hechos), [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    /**
     * Las tres puertas del acta. **403 con el motivo**, que aquí es media respuesta.
     *
     * El orden es el barato primero: quien subió se decide con la columna que ya
     * está en la fila, y el dueño del libro con el `profesor_id` que el acta guardó.
     * Ninguna de las dos consulta nada.
     *
     * Si `hechos` está vacío —una importación anterior al acta— no se sabe de quién
     * era el libro, así que la puerta del docente dueño **no se puede abrir**: queda
     * quien la subió y quien tenga el permiso. Se prefiere quedarse corto: un 403 de
     * más manda a preguntar, y uno de menos enseña las notas de un grupo ajeno.
     *
     * @param  array<string, mixed>  $hechos
     */
    private function exigirPoderVerElActa(object $fila, array $hechos): void
    {
        if ($fila->created_by !== null && (int) $fila->created_by === (int) $this->user->user_id) {
            return;
        }

        $delLibro = $hechos['contexto']['libro']['profesor_id'] ?? null;
        $propio = ($this->user->tipo ?? '') === 'Profesor' ? (int) $this->user->persona_id : null;

        if ($delLibro !== null && $propio !== null && (int) $delLibro === $propio) {
            return;
        }

        Autoriza::exigir(
            Autoriza::puedeSubirLaPlanillaDeOtro($this->user),
            'El acta de una importación la pueden ver quien la subió, el docente dueño del libro '
            .'y coordinación académica. Ésta no es suya.'
        );
    }

    /**
     * Las instrucciones del cuerpo, que llegan como JSON dentro de un multipart.
     *
     * Igual que en `ImportarController`: llegan como **cadena** porque van en el
     * mismo `FormData` que el fichero, y ahí no hay tipos. Un array vacío es
     * `null` —«esta subida no traía instrucciones»—, que no es lo mismo que «olvida
     * las que te di».
     *
     * @return ?array<string, mixed>
     */
    private function respuestasDelCuerpo(): ?array
    {
        $crudo = Request::input('respuestas');

        if (is_string($crudo)) {
            $crudo = json_decode($crudo, true);
        }

        return is_array($crudo) && $crudo !== [] ? $crudo : null;
    }

    /**
     * Arma el libro, lo escribe, anota la descarga y lo entrega.
     *
     * @param  list<int>  $asignaturaIds
     */
    private function entregar(object $periodo, object $profesor, array $asignaturaIds): BinaryFileResponse
    {
        $anio = LaPlanillaQueSeDescarga::cabeceraDelAnio($periodo->year_id);
        $escalas = LaPlanillaQueSeDescarga::escalasDelAnio($periodo->year_id);
        $recuentos = LaPlanillaQueSeDescarga::recuentosDelPeriodo($asignaturaIds, $periodo->id);

        $planillas = [];

        foreach ($asignaturaIds as $id) {
            $planillas[] = LaPlanillaQueSeDescarga::planilla($id, $periodo->id);
        }

        $libro = new LibroDeNotas(
            $anio,
            $periodo,
            $profesor,
            $planillas,
            $escalas,
            $recuentos,
            Reloj::ahora()->format('d/m/Y H:i')
        );

        $ruta = $this->escribir($libro);
        $nombre = $libro->nombreDeArchivo();

        $this->anotarLaDescarga($periodo, $profesor, $asignaturaIds, $nombre, $ruta);

        // `deleteFileAfterSend`: el `.xlsx` se arma en el temporal del sistema y se
        // borra al terminar la respuesta. Sin esto, `storage/` de cada uno de los
        // dieciséis colegios acumularía un libro por descarga y nadie los miraría
        // nunca — y los libros llevan dentro los nombres de los alumnos.
        return response()->download($ruta, $nombre, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    /** Escribe el libro en un fichero temporal y devuelve su ruta. */
    private function escribir(LibroDeNotas $libro): string
    {
        $hoja = $libro->construir();

        $ruta = tempnam(sys_get_temp_dir(), 'planilla-offline-');

        if ($ruta === false) {
            abort(500, 'No se pudo preparar el archivo de la planilla.');
        }

        (new Xlsx($hoja))->save($ruta);

        // **Se suelta la memoria a mano.** Un libro de quince asignaturas con sus
        // comentarios y sus validaciones es mucho objeto vivo, y PhpSpreadsheet deja
        // referencias cruzadas que el recolector no resuelve solo.
        $hoja->disconnectWorksheets();

        return $ruta;
    }

    /**
     * La fila de `descargas_de_planilla`.
     *
     * **Es lo único que esta familia escribe**, y escribe rastro, no datos del
     * colegio. Sirve a la auditoría —por aquí sale la planilla entera de un grupo
     * con los nombres de los alumnos— y al asistente de la fase 2, que necesita
     * saber **cuándo** se bajó el libro para poder escribir la frase de la pantalla
     * de choques.
     *
     * @param  list<int>  $asignaturaIds
     */
    private function anotarLaDescarga(
        object $periodo, object $profesor, array $asignaturaIds, string $nombre, string $ruta
    ): void {
        $ahora = Reloj::ahoraTexto();

        DB::insert(
            'INSERT INTO descargas_de_planilla
                (user_id, profesor_id, year_id, periodo_id, periodo_abierto, asignaturas,
                 hojas, nombre_archivo, huella, bytes, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                (int) $this->user->user_id,
                $profesor->id,
                $periodo->year_id,
                $periodo->id,
                $periodo->abierto ? 1 : 0,
                implode(',', $asignaturaIds),
                count($asignaturaIds),
                $nombre,
                hash_file('sha256', $ruta),
                (int) (filesize($ruta) ?: 0),
                // Y no `NOW()`, que es lo que decía aquí. `config/database.php` no
                // fija la zona de la sesión, así que `NOW()` es el reloj del cPanel
                // de cada colegio. Y esta fecha **se compara**: el acta la pone al
                // lado de la de la importación para decir si el libro que se subió
                // es el que se descargó. Con dos relojes esa comparación es un
                // sorteo. Ver el 53 §1.
                $ahora, $ahora,
            ]
        );
    }

    /**
     * El periodo que se pide, con su año y si está abierto. 404 si no existe.
     *
     * **No comprueba que esté abierto**, y ésa es la regla de esta familia entera.
     * Ver la cabecera de la clase.
     */
    private function periodoPedido(int $periodoId): object
    {
        $fila = DB::selectOne(
            'SELECT p.id, p.numero, p.year_id, p.profes_pueden_editar_notas
               FROM periodos p
              WHERE p.id = ? AND p.deleted_at IS NULL',
            [$periodoId]
        );

        if ($fila === null) {
            abort(404, 'Ese periodo no existe.');
        }

        return (object) [
            'id' => (int) $fila->id,
            'numero' => (int) $fila->numero,
            'year_id' => (int) $fila->year_id,
            'abierto' => (bool) $fila->profes_pueden_editar_notas,
        ];
    }

    /**
     * De qué docente es el libro que se está pidiendo.
     *
     * ## La regla, y por qué no puede ser `User::pueden_editar_notas`
     *
     * Aquel método deja pasar **el tipo `Profesor` y el superusuario, y nadie más**
     * (§3.4 del plan), o sea que un coordinador con rol de admin sin `is_superuser`
     * recibe 403 tenga el rol que tenga — y aquí hace falta que coordinación lea.
     * Además, pasar *por ser `Profesor`* dejaría que cualquier docente se bajara el
     * libro de un compañero, que es justo lo contrario de lo que hace falta.
     *
     * Así que:
     *
     *   - **Sin `?profesor_id=`**: el suyo. Si quien pregunta no es docente, no hay
     *     «el suyo» que valga y hay que decir de quién — 422, que es «falta un
     *     dato», no «no tienes permiso».
     *   - **Con `?profesor_id=` igual al suyo**: lo mismo.
     *   - **Con `?profesor_id=` de otro**: {@see Autoriza::puedeDescargarLaPlanillaDeOtro}.
     *
     * ## `persona_id` y no `user_id`
     *
     * `$user->persona_id` es el id de la **ficha**. Para un `Profesor` es
     * `profesores.id`, que es lo que compara `asignaturas.profesor_id`; para un
     * `Usuario` administrativo es `users.id`, **un número que casaría con la ficha
     * de otra persona**. Por eso la rama del propio docente exige `tipo ===
     * 'Profesor'` y no se conforma con que haya un `persona_id`: sin esa línea, el
     * administrativo número 5 heredaría las asignaturas del profesor número 5. Es
     * la misma trampa que `Autoriza::puedeEscribirDesempenos` ya tiene escrita.
     */
    private function profesorPedido(): object
    {
        $propio = ($this->user->tipo ?? '') === 'Profesor' ? (int) $this->user->persona_id : null;
        $pedido = Request::input('profesor_id');

        if ($pedido === null || $pedido === '') {
            if ($propio === null) {
                abort(422, 'Diga de qué docente quiere la planilla: falta `profesor_id`.');
            }

            return $this->fichaDelProfesor($propio);
        }

        if (! is_numeric($pedido)) {
            abort(422, '`profesor_id` tiene que ser un número.');
        }

        $pedido = (int) $pedido;

        if ($pedido !== $propio) {
            Autoriza::exigir(
                Autoriza::puedeDescargarLaPlanillaDeOtro($this->user),
                'Sólo coordinación o un superusuario pueden bajar la planilla de otro docente.'
            );
        }

        return $this->fichaDelProfesor($pedido);
    }

    /**
     * El docente de una asignatura concreta, para la ruta de una sola hoja.
     *
     * Aquí el docente **no viaja por parámetro**: sale de la asignatura. Así que la
     * comprobación es la contraria y es la que importa: **si la asignatura no es
     * suya, hace falta el permiso de la D4**.
     *
     * Una asignatura **sin docente asignado** (`asignaturas.profesor_id` es
     * anulable) sólo la puede bajar quien tenga ese permiso: no hay dueño a quien
     * preguntarle, así que dejarla abierta a cualquier docente sería regalar la
     * planilla de un grupo por una fila mal rellenada.
     */
    private function profesorDeLaAsignatura(object $dueno): object
    {
        $propio = ($this->user->tipo ?? '') === 'Profesor' ? (int) $this->user->persona_id : null;

        if ($dueno->profesor_id !== null && $dueno->profesor_id === $propio) {
            return $this->fichaDelProfesor($propio);
        }

        Autoriza::exigir(
            Autoriza::puedeDescargarLaPlanillaDeOtro($this->user),
            'Esa asignatura no es suya.'
        );

        if ($dueno->profesor_id === null) {
            return (object) ['id' => null, 'nombre' => 'Sin docente asignado'];
        }

        return $this->fichaDelProfesor($dueno->profesor_id);
    }

    /** Nombre y id del docente, para la portada del libro y para la respuesta. */
    private function fichaDelProfesor(int $profesorId): object
    {
        $fila = DB::selectOne(
            'SELECT p.id, p.nombres, p.apellidos FROM profesores p
              WHERE p.id = ? AND p.deleted_at IS NULL',
            [$profesorId]
        );

        if ($fila === null) {
            abort(404, 'Ese docente no existe.');
        }

        return (object) [
            'id' => (int) $fila->id,
            'nombre' => trim(($fila->nombres ?? '').' '.($fila->apellidos ?? '')),
        ];
    }

    /**
     * `?asignaturas=301,302` — qué hojas quiere, o `null` si las quiere todas.
     *
     * Se filtra a números y se quitan los repetidos **antes** de comprobar de quién
     * son: un `?asignaturas=301,301,abc` no puede acabar en un libro con la misma
     * hoja dos veces ni en una consulta con una cadena dentro.
     *
     * @return ?list<int>
     */
    private function asignaturasPedidas(): ?array
    {
        $crudo = Request::input('asignaturas');

        if ($crudo === null || $crudo === '') {
            return null;
        }

        $trozos = is_array($crudo) ? $crudo : explode(',', (string) $crudo);

        $ids = [];

        foreach ($trozos as $trozo) {
            $trozo = trim((string) $trozo);

            if ($trozo !== '' && ctype_digit($trozo)) {
                $ids[] = (int) $trozo;
            }
        }

        $ids = array_values(array_unique($ids));

        if ($ids === []) {
            abort(422, '`asignaturas` no trae ningún identificador válido.');
        }

        return $ids;
    }
}
