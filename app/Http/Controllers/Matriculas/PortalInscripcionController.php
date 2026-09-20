<?php

namespace App\Http\Controllers\Matriculas;

use App\Http\Controllers\Controller;
use App\Services\OrdenDeInscripcion;
use App\Support\SafeUpload;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Str;

/**
 * **EL PORTAL DE LA FAMILIA.** Pantallas 02, 04, 05 y 06 de
 * `myvc_front/PANTALLAS-MATRICULA.md`, y el contrato entero en
 * `docs/migracion/47-el-portal-de-la-familia.md`.
 *
 * Tres rutas, las tres **públicas**, y conviene leer por qué antes de copiarlas.
 *
 * ## POR QUÉ SON PÚBLICAS: NO ES COMODIDAD, ES QUE NO HAY CUENTA QUE PEDIR
 *
 * Es el mismo argumento que abrió las cuatro de `colillas-inscripcion` y
 * `pagos-inscripcion` el 19 y el 20 de septiembre: **quien llena el formulario es la
 * familia de un aspirante que todavía no es alumno**. No tiene fila en `users`, no
 * tiene fila en `acudientes`, y crearle una antes de que lo admitan es exactamente
 * el huérfano que `MatriculasHuerfanas` existe para ir a buscar.
 *
 * Así que no hay guard de propiedad que aplicar, y **la llave es el dato**.
 *
 * ## PERO AQUÍ LA LLAVE SOLA NO BASTA, Y ÉSA ES LA DIFERENCIA CON LAS CUATRO ANTERIORES
 *
 * `GET colillas-inscripcion/{codigo}` fijó la regla el 20 sep: **un código no puede
 * revelar el nombre de un menor**. Se dicta por teléfono y viaja en un papel que pasa
 * de mano en mano, así que aquella ruta devuelve *el trámite y no la persona*.
 *
 * **Este portal tiene que devolver la persona**: la familia entra a seguir llenando
 * lo que dejó a medias, y un formulario que no se puede releer no se puede terminar.
 * Con el código solo, quien se encontrara el papel leería el nombre, el documento y
 * los teléfonos de un menor.
 *
 * Por eso hay **un segundo factor, y es un dato que ya está en el formulario**: el
 * documento del aspirante. Y lo que hace que eso no sea un huevo y una gallina:
 *
 * ```
 * antes de la primera escritura   el código abre un formulario EN BLANCO
 *                                 -> no hay nada personal que revelar
 * después                         el código + el documento lo reabren
 *                                 -> la llave la eligió la familia, nadie se la dio
 * ```
 *
 * *La llave no se entrega: la escribe quien la va a usar.* Y si el aspirante todavía
 * no tiene documento —en preescolar el registro civil llega tarde, y el colegio
 * inscribe igual— el segundo factor es la **fecha de nacimiento**, que es el otro
 * dato que la familia siempre sabe y el papel no lleva impreso.
 *
 * ## LO QUE PROTEGE ESTAS TRES, EN EL ORDEN EN QUE ACTÚA
 *
 * 1. **El carácter de control del código**, comprobado antes de tocar la base:
 *    rechaza 28 de cada 29 cadenas sin una sola consulta.
 * 2. **El segundo factor**, en cuanto hay algo personal que enseñar.
 * 3. **Tres limitadores distintos**, uno por ruta. Y eso no es exceso: la clave de un
 *    limitador con nombre es `md5($limiterName.$limit->key)` —**sin el verbo y sin la
 *    ruta**—, así que dos rutas que compartan nombre comparten cubo. Costó un fallo
 *    reproducido el 20 sep, donde **preguntar consumía subidas** (41 §10).
 * 4. **El tope por fila**, que es lo único que no se reinicia con el reloj: un
 *    aspirante por orden, y los documentos acotados por el catálogo de requisitos del
 *    colegio.
 *
 * ## Y LO QUE NO ESCRIBE, QUE ES LA MITAD DEL DISEÑO
 *
 * **Nada de `alumnos`, nada de `matriculas`, nada de `users`.** Un aspirante vive en
 * su propia tabla hasta que alguien del colegio lo admite, y quien lo convierte es
 * `AspirantesController::putDecision` con nombre y hora. Si la familia nunca vuelve,
 * lo único que queda es una fila de `aspirantes` que no sale en ningún listado de
 * grupo, en ningún boletín y en ninguna constancia.
 */
class PortalInscripcionController extends Controller
{
    /**
     * Los estados de la orden en los que el formulario está pagado y se puede llenar.
     *
     * `IMPRESA` **no entra**: es un papel que salió por la impresora y que nadie ha
     * pagado. Dejar llenar el formulario antes de pagarlo es el pecado que
     * `PANTALLAS-MATRICULA.md` §2 le atribuye a la prematrícula pública de hoy —*«si
     * el pago no se confirma, no se escribe nada»*—.
     */
    private const PAGADAS = ['PAGADA', 'APROBADA', 'MATRICULADA'];

    private const EXTENSIONES = ['jpg', 'jpeg', 'png', 'pdf'];

    private const TIPOS = ['image/jpeg', 'image/png', 'application/pdf'];

    private const MAXIMO_BYTES = 5 * 1024 * 1024;

    /**
     * Los campos del formulario del aspirante que la familia puede escribir.
     *
     * **Lista blanca y no lista negra**, que es lo que impide que el día que
     * `aspirantes` gane una columna —`estado_embudo`, `alumno_id`, `decidido_por`— un
     * cuerpo de la calle pueda escribirla. Es la misma forma que `ColumnaSegura` le
     * da al resto del repo.
     */
    private const CAMPOS = [
        'nombres', 'apellidos', 'tipo_doc', 'documento', 'exp_lugar', 'fecha_nac',
        'grado_id', 'colegio_anterior',
        'acu_nombres', 'acu_apellidos', 'acu_documento', 'acu_celular', 'acu_email',
        'acu_parentesco',
        'tiene_condicion_salud', 'condicion_salud',
    ];

    // ------------------------------------------------------------------
    // Las tres de la familia
    // ------------------------------------------------------------------

    /**
     * **«¿Cómo va lo mío, y qué me falta?»** Pantallas 02 y 04.
     *
     * Con el código solo devuelve el trámite y el recorrido **sin la persona**. Con
     * el segundo factor —`?documento=` o `?fecha_nac=`— añade el formulario para
     * poder seguir llenándolo.
     *
     * ## `sin_verificar` NO ES UN ERROR, y por eso no es un 403
     *
     * Cuando ya hay datos y no viene el segundo factor, esto contesta **200** con
     * `verificado: false` y lo que se puede enseñar sin identificar a nadie. Un 403
     * dejaría a la pantalla sin saber si el código es malo, si el formulario no
     * existe o si sólo falta un campo, y las tres se le enseñan a la familia de forma
     * distinta.
     *
     * Lo que sí es 403 es **fallar** el segundo factor: ahí alguien ha afirmado algo
     * que no es cierto, y eso hay que decirlo.
     */
    public function getEstado(string $codigo)
    {
        $orden = $this->ordenDelCodigo($codigo);
        $aspirante = $this->aspiranteDe((int) $orden->id);

        $salida = [
            // **El código BUENO**, aunque haya entrado por el viejo: es lo único que
            // esta ruta le puede decir a quien tiene un papel desactualizado.
            'codigo' => $orden->codigo,
            'encontrado_por' => $orden->encontrado_por,
            'year_campana' => (int) $orden->year_campana,
            'modo' => $orden->modo,
            'estado' => $orden->estado,
            'pagado' => in_array($orden->estado, self::PAGADAS, true),
            'cierra' => $orden->cierra,
            // Si todavía no hay formulario, la familia entra por primera vez y no hay
            // nada que verificar. La pantalla lo usa para no pedir un documento que
            // nadie ha escrito aún.
            'tiene_formulario' => $aspirante !== null,
            'verificado' => false,
            'aspirante' => null,
            'documentos' => [],
            'citas' => [],
        ];

        if ($aspirante === null) {
            $salida['verificado'] = true;
            $salida['requisitos'] = $this->requisitosDeLaCampana();

            return $salida;
        }

        if (! $this->segundoFactorCoincide($aspirante)) {
            // Hay datos y no se ha identificado: se dice qué hay que presentar, y no
            // se filtra nada por el camino. **`pide` no dice el valor**, dice el
            // campo — que es la diferencia entre una pista y una respuesta.
            $salida['pide'] = $aspirante->documento !== null && $aspirante->documento !== ''
                ? 'documento'
                : 'fecha_nac';

            return $salida;
        }

        $salida['verificado'] = true;
        $salida['aspirante'] = $this->formularioDe($aspirante);
        $salida['estado_embudo'] = $aspirante->estado_embudo;
        $salida['requisitos'] = $this->requisitosDeLaCampana();
        $salida['documentos'] = $this->documentosDe((int) $aspirante->id);
        $salida['citas'] = $this->citasDe((int) $aspirante->id);

        return $salida;
    }

    /**
     * **Guardar el formulario.** Pantalla 05, los cuatro tramos que se guardan solos.
     *
     * ## ESCRIBE SÓLO LOS CAMPOS QUE VIENEN, y eso es el requisito y no una comodidad
     *
     * La pantalla *«guarda sola y se puede volver mañana»*, así que manda tramos
     * sueltos. Escribir los dieciséis campos siempre haría que guardar el tramo de
     * salud **borrara el nombre**, que es exactamente el fallo que este repo arregló
     * el 1 sep en `requisitos/alumno` y volvió a encontrar el 20 sep en el `estado`
     * de esa misma ruta. *Dos veces la misma forma: un `UPDATE` que nombra columnas
     * que quien llama no mencionó.*
     *
     * ## LA PRIMERA ESCRITURA NO PIDE SEGUNDO FACTOR Y LAS DEMÁS SÍ
     *
     * Ver la cabecera de la clase. Y hay una consecuencia que conviene tener delante:
     * **quien se encuentre un papel en blanco puede estrenar el formulario**. Es
     * cierto, y es preferible a la alternativa —que la familia no pueda entrar—,
     * porque un formulario estrenado por un desconocido **no matricula a nadie**: lo
     * único que hace es gastar un papel que el colegio ya cobró, y secretaría lo ve
     * en la bandeja con el nombre de un aspirante que no compró nada.
     */
    public function putFormulario(string $codigo)
    {
        $orden = $this->ordenDelCodigo($codigo);

        if (! in_array($orden->estado, self::PAGADAS, true)) {
            abort(409, 'Ese formulario todavía no está pagado. Cuando se confirme el pago se podrá llenar.');
        }

        $aspirante = $this->aspiranteDe((int) $orden->id);
        $ahora = Carbon::now('America/Bogota');

        if ($aspirante === null) {
            // **El `UNIQUE` de `orden_id` decide, no este `if`.** Quien llena esto está en
            // un teléfono con mala señal y da dos veces al botón: las dos peticiones
            // pueden llegar a la vez, encontrar las dos que no hay fila e intentar
            // insertarla. La segunda choca con el índice —que es exactamente para lo que
            // está— y **eso no es un error que deba ver la familia**: es que ya existe su
            // formulario. Se relee y se sigue.
            //
            // *Una comprobación previa nunca hace atómica una escritura; el índice sí.*
            try {
                DB::insert('INSERT INTO aspirantes (orden_id, year_campana, created_at, updated_at)
                    VALUES (?,?,?,?)',
                    [(int) $orden->id, (int) $orden->year_campana, $ahora, $ahora]);
            } catch (UniqueConstraintViolationException $e) {
                // La otra petición ganó. No hay nada que arreglar.
            }

            $aspirante = $this->aspiranteDe((int) $orden->id);

            // Y si la que ganó la carrera ya escribió datos, este cuerpo tiene que
            // presentar el segundo factor como cualquier otro: la fila ya no está en
            // blanco.
            if ($aspirante !== null && ! $this->segundoFactorCoincide($aspirante)) {
                abort(403, 'Para seguir llenando este formulario hay que confirmar el documento del aspirante.');
            }
        } elseif (! $this->segundoFactorCoincide($aspirante)) {
            abort(403, 'Para seguir llenando este formulario hay que confirmar el documento del aspirante.');
        }

        if ($aspirante === null) {
            abort(500, 'No se pudo abrir el formulario.');
        }

        $sets = [];
        $valores = [];

        foreach (self::CAMPOS as $campo) {
            if (! Request::has($campo)) {
                continue;
            }

            $sets[] = $campo.'=?';
            $valores[] = $this->valorDe($campo);
        }

        if (count($sets) === 0) {
            // Nada que escribir: se contesta lo que hay. **No es un error** —la
            // pantalla guarda sola y puede disparar sin cambios— y un 422 aquí le
            // pintaría un fallo rojo a quien no hizo nada malo.
            return $this->getEstado($orden->codigo);
        }

        $sets[] = 'updated_at=?';
        $valores[] = $ahora;
        $valores[] = (int) $aspirante->id;

        DB::update('UPDATE aspirantes SET '.implode(', ', $sets).' WHERE id=?', $valores);

        $fresco = $this->aspiranteDe((int) $orden->id);

        return [
            'guardado' => true,
            'aspirante' => $fresco === null ? null : $this->formularioDe($fresco),
        ];
    }

    /**
     * **Subir un documento, o avisar de que se lleva en papel.** Pantalla 06.
     *
     * ## SUBIR NO CIERRA EL PASO, Y POR ESO EL ESTADO ES `SUBIDO` Y NO `RECIBIDO`
     *
     * *«Tres finales por documento: subido, lo llevo en papel —marcado por ella, para
     * que la estación 2 sepa qué esperar— y devuelto con motivo. Subir no cierra el
     * paso: lo deja en revisión, que es la verdad.»*
     *
     * Decirle a la familia que ya está y devolvérselo en el patio es exactamente el
     * viaje que este módulo existe para ahorrar.
     *
     * ## `PAPEL` NO LLEVA FICHERO Y ES LA MITAD ÚTIL
     *
     * La familia que no tiene escáner marca «lo llevo en papel» y **la estación 2 sabe
     * qué esperar antes de que llegue la fila**. Sin esa opción, el que no puede subir
     * nada es indistinguible del que no ha hecho nada.
     *
     * ## UN DOCUMENTO PENDIENTE POR REQUISITO, y ahí está el tope de verdad
     *
     * El limitador protege la base; **lo que protege el disco es la cuenta por fila**.
     * Mientras haya uno sin revisar de ese requisito no se acepta otro: la familia
     * corrige el que mandó, y no puede llenar el disco con cincuenta fotos del mismo
     * papel. Es la regla que ya probó la colilla, con su número.
     */
    public function postDocumento(string $codigo, $requisito_id)
    {
        $orden = $this->ordenDelCodigo($codigo);

        if (! is_numeric($requisito_id)) {
            abort(422, 'Ese requisito no es válido.');
        }

        $aspirante = $this->aspiranteDe((int) $orden->id);

        if ($aspirante === null) {
            abort(409, 'Primero hay que llenar el formulario del aspirante.');
        }

        if (! $this->segundoFactorCoincide($aspirante)) {
            abort(403, 'Para subir documentos hay que confirmar el documento del aspirante.');
        }

        $requisito = DB::selectOne('SELECT r.id FROM requisitos_matricula r
            INNER JOIN years y ON y.id=r.year_id AND y.deleted_at IS NULL AND y.actual=1
            WHERE r.id=? AND r.deleted_at IS NULL', [(int) $requisito_id]);

        if (! $requisito) {
            abort(404, 'Ese requisito no existe en la campaña de este año.');
        }

        $pendiente = DB::selectOne('SELECT id FROM documentos_admision
            WHERE aspirante_id=? AND requisito_id=? AND estado IN ("SUBIDO","PAPEL")
              AND deleted_at IS NULL LIMIT 1',
            [(int) $aspirante->id, (int) $requisito->id]);

        if ($pendiente) {
            abort(409, 'Ya hay uno de ese documento esperando revisión. Espere a que lo revisen.');
        }

        $ahora = Carbon::now('America/Bogota');
        $enPapel = Request::boolean('en_papel');

        if ($enPapel) {
            DB::insert('INSERT INTO documentos_admision
                (requisito_id, aspirante_id, estado, created_at, updated_at) VALUES (?,?,?,?,?)',
                [(int) $requisito->id, (int) $aspirante->id, 'PAPEL', $ahora, $ahora]);

            return ['estado' => 'PAPEL', 'mensaje' => 'Anotado: lo lleva en papel el día de matrículas.'];
        }

        $archivo = $this->guardarElArchivo();

        DB::insert('INSERT INTO documentos_admision
            (requisito_id, aspirante_id, archivo, nombre_original, estado, created_at, updated_at)
            VALUES (?,?,?,?,?,?,?)',
            [(int) $requisito->id, (int) $aspirante->id, $archivo['nombre'], $archivo['original'],
                'SUBIDO', $ahora, $ahora]);

        return ['estado' => 'SUBIDO', 'mensaje' => 'Recibido. Queda en revisión.'];
    }

    // ------------------------------------------------------------------
    // Lo que sostiene a las tres
    // ------------------------------------------------------------------

    /**
     * La orden del papel, o el error exacto. **Tres respuestas y no dos**, porque
     * desde la pantalla «ese código no existe» y «ese código está mal escrito» se
     * corrigen de forma distinta.
     */
    private function ordenDelCodigo(string $codigo): object
    {
        if (! OrdenDeInscripcion::tieneForma($codigo)) {
            abort(422, 'Ese código no es válido. Revísalo: son el año y seis caracteres.');
        }

        $orden = OrdenDeInscripcion::porCodigo($codigo);

        if (! $orden) {
            abort(404, 'No encontramos ese formulario.');
        }

        return $orden;
    }

    private function aspiranteDe(int $ordenId): ?object
    {
        return DB::selectOne('SELECT * FROM aspirantes WHERE orden_id=? AND deleted_at IS NULL',
            [$ordenId]);
    }

    /**
     * **El segundo factor.** Ver la cabecera de la clase.
     *
     * Un formulario sin nombre ni documento no tiene nada que proteger, así que pasa:
     * es el papel recién estrenado, y exigirle una llave que nadie ha escrito todavía
     * sería cerrar la puerta por dentro.
     *
     * La comparación del documento **no distingue mayúsculas ni espacios** —lo teclea
     * una persona en un teléfono—, y la fecha se compara por su parte de fecha, no
     * por el sello entero.
     */
    private function segundoFactorCoincide(object $aspirante): bool
    {
        $documento = trim((string) ($aspirante->documento ?? ''));
        $nacimiento = trim((string) ($aspirante->fecha_nac ?? ''));
        $nombre = trim((string) ($aspirante->nombres ?? ''));

        if ($documento === '' && $nacimiento === '' && $nombre === '') {
            return true;
        }

        if ($documento !== '') {
            $pedido = mb_strtoupper(trim((string) Request::input('documento')));

            return $pedido !== '' && $pedido === mb_strtoupper($documento);
        }

        if ($nacimiento !== '') {
            $pedido = trim((string) Request::input('fecha_nac'));

            return $pedido !== '' && substr($nacimiento, 0, 10) === substr($pedido, 0, 10);
        }

        // Tiene nombre y no tiene ninguna de las dos llaves. **Se cierra**: hay un
        // dato de un menor dentro y nada con qué comprobar quién pregunta. La familia
        // lo desbloquea escribiendo el documento o la fecha, que es lo que la pantalla
        // le pide con `pide`.
        return false;
    }

    /** Lo que la familia puede ver de su propio formulario. */
    private function formularioDe(object $aspirante): array
    {
        $salida = [];

        foreach (self::CAMPOS as $campo) {
            $salida[$campo] = $aspirante->{$campo} ?? null;
        }

        $salida['tiene_condicion_salud'] = (bool) $aspirante->tiene_condicion_salud;

        return $salida;
    }

    /**
     * El recorrido de la campaña, para que la pantalla sepa qué documentos pedir.
     *
     * Es el **mismo catálogo** que chulea la estación 2 (`requisitos_matricula`), y
     * eso no es reutilización: es el requisito. *«El que hace el recorrido desde casa
     * y el que lo hace en la fila cierran los mismos requisitos y escriben en las
     * mismas filas»* (`PANTALLAS-MATRICULA.md` §0). Un catálogo propio para el portal
     * haría que tener el papel y tener el paso cerrado fueran dos verdades distintas.
     *
     * ## EL AÑO SALE DE `years.actual` Y NO DE LA SESIÓN, PORQUE AQUÍ NO HAY SESIÓN
     *
     * El resto de este dominio usa `$user->year_id`. **Estas tres rutas no tienen
     * usuario**, así que la única fuente posible es el año marcado como actual.
     *
     * Y eso obliga a que **el lado del colegio use el mismo criterio**
     * (`AspirantesController::requisitosDeLaCampana`), que si no las dos mitades
     * enseñarían **listas de documentos distintas**: la familia subiría lo que el
     * colegio no espera, y la estación 2 pediría lo que la familia no vio nunca. *No
     * es una simetría bonita: es que las dos caras del mismo paso tienen que estar de
     * acuerdo en cuál es el paso.*
     */
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

    /**
     * Lo que ya mandó, con el motivo si se lo devolvieron.
     *
     * **`archivo` no viaja**, por lo mismo que no viaja en la colilla: la URL es la
     * llave, y una respuesta pública no puede repartir el enlace a la foto del
     * registro civil de un menor. Lo que la familia necesita saber es si llegó y si se
     * lo aceptaron.
     */
    private function documentosDe(int $aspiranteId): array
    {
        $filas = DB::select('SELECT requisito_id, estado, motivo_devolucion, devuelto_original,
                created_at AS enviado_at, recibido_at
            FROM documentos_admision
            WHERE aspirante_id=? AND deleted_at IS NULL ORDER BY id', [$aspiranteId]);

        return array_map(fn ($fila) => [
            'requisito_id' => (int) $fila->requisito_id,
            'estado' => $fila->estado,
            // Sólo cuando explica una devolución: un texto pegado a un «recibido» es
            // información interna que nadie pidió enseñar. Misma regla que la colilla.
            'motivo_devolucion' => $fila->estado === 'DEVUELTO' ? $fila->motivo_devolucion : null,
            'devuelto_original' => (bool) $fila->devuelto_original,
            'enviado_at' => $fila->enviado_at,
            'recibido_at' => $fila->recibido_at,
        ], $filas);
    }

    /**
     * La cita, con día y hora. **Sin la observación y sin las reservadas**: lo que
     * Orientación escribe es entre el personal, y este endpoint lo lee cualquiera que
     * tenga el papel y la llave.
     */
    private function citasDe(int $aspiranteId): array
    {
        $filas = DB::select('SELECT tipo, cuando, donde, resultado
            FROM citas_admision
            WHERE aspirante_id=? AND reservada=0 AND deleted_at IS NULL ORDER BY cuando', [$aspiranteId]);

        return array_map(fn ($fila) => [
            'tipo' => $fila->tipo,
            'cuando' => $fila->cuando,
            'donde' => $fila->donde,
            'resultado' => $fila->resultado,
        ], $filas);
    }

    /**
     * El valor tal y como va a la columna.
     *
     * `tiene_condicion_salud` es lo único que no se guarda crudo: es un `tinyint` y
     * del cliente puede llegar `true`, `"1"` o `"si"`. `Request::boolean` es la misma
     * lectura que usa el resto del repo.
     */
    private function valorDe(string $campo)
    {
        if ($campo === 'tiene_condicion_salud') {
            return Request::boolean($campo) ? 1 : 0;
        }

        $valor = Request::input($campo);

        if ($campo === 'grado_id') {
            return is_numeric($valor) ? (int) $valor : null;
        }

        if (is_array($valor)) {
            abort(422, 'El campo '.$campo.' tiene que ser un texto.');
        }

        $texto = trim((string) $valor);

        return $texto === '' ? null : $texto;
    }

    /**
     * Guarda la foto o el PDF. **Copiado de la colilla a propósito y no compartido**:
     * las dos carpetas son distintas y las dos listas blancas podrían separarse el día
     * que un colegio acepte un formato más en una y no en la otra. Lo que sí es igual
     * —y es lo que importa— es la regla: **la extensión Y el contenido**, y el nombre
     * **se genera**, nunca se hereda del que mandó el desconocido.
     */
    private function guardarElArchivo(): array
    {
        if (Request::file('archivo') === null) {
            abort(422, 'No llegó ningún archivo. Adjunte una foto o un PDF, o marque que lo lleva en papel.');
        }

        $file = SafeUpload::archivoRecibido('archivo');

        if ($file->getSize() > self::MAXIMO_BYTES) {
            abort(422, 'El archivo pasa de 5 MB. Mande una foto más pequeña.');
        }

        if (! in_array((string) $file->getMimeType(), self::TIPOS, true)) {
            abort(422, 'Sólo aceptamos una foto (JPG o PNG) o un PDF.');
        }

        $carpeta = public_path('admision');

        if (! File::exists($carpeta)) {
            File::makeDirectory($carpeta, 0755, true, true);
        }

        $validado = SafeUpload::nombreDisponible($file, $carpeta, self::EXTENSIONES);
        $extension = strtolower((string) pathinfo($validado, PATHINFO_EXTENSION));

        // El nombre original se guarda **para enseñárselo a quien revisa**, y el
        // fichero se llama con cuarenta caracteres al azar. Los dos a la vez: el
        // primero es un dato, el segundo es la ruta, y confundirlos es lo que deja que
        // un desconocido elija dónde escribe el servidor.
        $original = mb_substr((string) $file->getClientOriginalName(), 0, 160);
        $nombre = Str::random(40).'.'.$extension;

        $file->move($carpeta, $nombre);

        return ['nombre' => 'admision/'.$nombre, 'original' => $original];
    }
}
