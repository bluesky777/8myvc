<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResuelveElUsuario;
use App\Services\Auditoria;
use App\Support\Autoriza;
use App\Support\CatalogoDelMen;
use App\Support\Reloj;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;

/**
 * El plan de área del colegio: **una tabla, plana y por año**.
 *
 * El contrato es
 * [39-el-modelo-plano-por-competencias.md](../../../docs/migracion/39-el-modelo-plano-por-competencias.md),
 * que reescribe la Fase 3 del
 * [35](../../../docs/migracion/35-el-modelo-de-evaluacion-del-colegio.md) con
 * **D31**, **P1.bis**, **P1.ter** y **P1.quater** encima. El documento manda; si algo
 * de aquí discrepa de él, el que está mal es éste.
 *
 * ## Qué cambió el 17 sep, en tres frases
 *
 * Este fichero tenía **dos capas y una rejilla**: `desempenos_por_defecto` era el
 * catálogo del colegio, `desempenos` la copia por asignatura que sembraba un botón,
 * y `desempenos/rejilla` la pantalla donde el docente marcaba una casilla por alumno.
 *
 *  1. **D31 quitó la copia.** El colegio y el docente escriben **las mismas filas
 *     físicas**, así que no hay a dónde sembrar y no hay `por_defecto` que candar:
 *     el candado de la D14 —y las tres rutas que lo sostenían— se va entero.
 *  2. **P1.bis quitó la rejilla.** La casilla *era* el nivel, y el nivel ahora lo
 *     **deriva el boletín** de la definitiva de la asignatura. Nadie marca nada.
 *  3. **H3 y P3 quitaron la competencia.** El boletín es plano: no hay cabecera que
 *     agrupar, así que `competencia_id` —y la tabla `competencias` entera— sobra.
 *
 * Lo que queda es el CRUD del catálogo, `copiar` y el catálogo del MEN. Siete rutas
 * donde había veintiuna.
 *
 * ## Los nombres de los métodos dicen `Plantilla` y las URL ya no
 *
 * `getPlantilla` contesta `GET desempenos`, `putOrdenPlantilla` contesta
 * `PUT desempenos/orden`… **y no se renombran**: lo que es contrato es la URL, y
 * `CLAUDE.md` dice que renombrar métodos es cosmético y va después. El porqué de que
 * el segmento `plantilla` se cayera de la URL está en `routes/api/desempenos.php`.
 *
 * ## Quién escribe: dos puertas, y la segunda es la entrega
 *
 * Hasta hoy estas rutas exigían `puedeEditarPlantillaNotas`, que el docente **no
 * tiene** (D13, D28): su pantalla nacía en 403. **P1.quater** abre la segunda puerta
 * —`Autoriza::puedeEscribirDesempenos`, el permiso **con alcance**— y las tres reglas
 * que lo hacen defendible están escritas allí, no aquí. Lo que sí vive aquí es
 * `exigirEscrituraDelPlan`, que es quien decide **si además hace falta el periodo
 * abierto**: se lo pide al docente y no al colegio.
 *
 * ## El agujero que esto abre, y está escrito a propósito
 *
 * Con la copia borrada, **el texto del desempeño se lee vivo al imprimir**. Un
 * coordinador que corrija una errata en octubre **cambia el boletín del periodo 1 que
 * ya fue a casa**. Es la vuelta atrás de `2026_09_14_100000_competencia_congelada`,
 * que existía justo para eso, y va aceptado a sabiendas (§3 del 39): lo acota que las
 * filas son **por año**, así que la exposición real es dentro del mismo año. La
 * salida que no se implementó —pedirle el periodo abierto **también** al
 * coordinador— son tres líneas en `exigirEscrituraDelPlan` el día que Joseth lo diga.
 *
 * ## Por qué SQL con columnas nombradas y nunca `SELECT *`
 *
 * Por lo mismo que el resto del repo: esta tabla va a ganar columnas y un `SELECT *`
 * las repartiría solas a toda respuesta que devuelva un desempeño. Es la puerta por
 * la que `profesores.tono` se repartió a seis respuestas vivas sin que nadie lo
 * mandara.
 */
class DesempenosController extends Controller
{
    use ResuelveElUsuario;

    /**
     * `GET desempenos[?materia_id&grado_id&periodo_id]` — el plan de área del año.
     *
     * **Lo lee cualquier docente**, sin permiso ninguno, y eso es nuevo: hasta hoy
     * hasta leer pedía `can_edit_plantilla_notas`. Con D31 esta pantalla **es
     * también la del docente**, así que cerrarle la lectura sería cerrarle la
     * pantalla entera.
     *
     * Enseña **todas** las filas del año, no sólo las que le tocarían a alguien: es
     * la pantalla donde se escriben, así que una fila mal dirigida —a una materia
     * que ya no existe, o a un periodo de otro año— **tiene que verse** para poder
     * arreglarse. Es la misma razón por la que `PlantillaNotasController::getIndex`
     * lee `todasDelAnio` y no `unidadesPara`.
     *
     * `grupos` es la mitad que hace útil la pantalla: dice de un vistazo **qué
     * materias y qué periodos están todavía vacíos**, que es la mitad del trabajo
     * que esta entrega existe para quitar.
     *
     * @return array<string, mixed>
     */
    public function getPlantilla(): array
    {
        $yearId = (int) $this->user->year_id;

        $filtros = ['d.year_id = ?'];
        $valores = [$yearId];

        if (Request::has('materia_id')) {
            $filtros[] = 'd.materia_id = ?';
            $valores[] = $this->idObligatorio('materia_id');
        }

        // **`<=>` y no `=`**: `grado_id=` vacío es «las que valen para todos los
        // grados», que es un filtro legítimo de la pantalla y que con `= NULL` no
        // devolvería ninguna.
        if (Request::has('grado_id')) {
            $filtros[] = 'd.grado_id <=> ?';
            $valores[] = $this->idOpcional('grado_id');
        }

        if (Request::has('periodo_id')) {
            $filtros[] = 'd.periodo_id = ?';
            $valores[] = $this->idObligatorio('periodo_id');
        }

        $filas = DB::select(
            'SELECT d.id, d.definicion, d.tipo, d.orden, d.materia_id, d.grado_id, d.periodo_id
               FROM desempenos_por_defecto d
              WHERE '.implode(' AND ', $filtros).' AND d.deleted_at IS NULL
              ORDER BY d.materia_id, d.grado_id, d.periodo_id, d.orden, d.id',
            $valores
        );

        return [
            'year_id' => $yearId,
            'desempenos' => $this->catalogoConSusNombres($filas),
            'grupos' => $this->gruposDelCatalogo($filas),
        ];
    }

    /**
     * `GET desempenos/catalogo-men?materia_id=&grado_id=` — lo que el MEN publica
     * para **esa** materia y **ese** grado. **D11.**
     *
     * **Vivía en `competencias/catalogo-men` y se muda con la familia**, byte a
     * byte: mudarse cambia la URL, no las respuestas — códigos de error incluidos,
     * que es por lo que la materia y el grado que no existen siguen contestando
     * **404** y no el 422 del resto de este fichero.
     *
     * ## Los dos parámetros son obligatorios, y ésa es la decisión de diseño
     *
     * No devuelve el corpus entero, y no por tamaño sino por contrato: **los dos
     * parámetros son exactamente el alcance con el que se adopta**. El colegio
     * adopta «los estándares de Matemáticas de 6.º», no «los del MEN». Con eso la
     * respuesta está **acotada por construcción** —son los de un área y un conjunto
     * de grados— y no hay que paginar ni cachear nada nunca. Una ruta que puede
     * crecer sin tope no se afina después, y en este repositorio los informes ya
     * tardan de 24 a 63 segundos.
     *
     * ## Lo que NO cubre el MEN contesta 200 con la lista vacía y el motivo
     *
     * **Nunca 404.** Un 404 diría «esa ruta no existe» cuando lo cierto es «el MEN
     * no publica estándares de Educación Religiosa», y el colegio se quedaría
     * pensando que la pantalla está rota. Es población, no `OK`, y hay **tres noes
     * distintos** que la respuesta separa:
     *
     * | `motivo` | qué decirle al colegio |
     * |---|---|
     * | `sin_estandares` | *«El MEN no publica Estándares Básicos para esta área.»* Religión, Artes, Ed. Física y Tecnología (D11) |
     * | `sin_emparejar` | *«No supimos a qué área del MEN corresponde esta materia.»* |
     * | `preescolar` | *«Los Estándares Básicos empiezan en 1.º.»* No es un fallo del colegio |
     *
     * La respuesta lleva siempre `emparejamiento`, con **qué clave casó**, para que
     * la adivinanza se pueda auditar desde la pantalla. Y `area` a mano gana
     * siempre: el que sepa que su «Dimensión Comunicativa» es Lenguaje lo dice y
     * este emparejador no opina.
     *
     * ## `por_tipo` es la mitad que evita adoptar 49 filas queriendo cinco
     *
     * El catálogo trae **tres granos** —ver `CatalogoDelMen::competencias`— porque
     * el MEN no publica lo mismo para las seis áreas. La respuesta los cuenta por
     * separado y marca cuáles se adoptan por defecto, para que la pantalla pueda
     * decir *«5 enunciados, y 44 estándares si los quiere»* en vez de soltar las 49
     * en una lista sin explicar de qué es cada renglón.
     *
     * @return array<string, mixed>
     */
    public function getCatalogoMen(): array
    {
        $materiaId = $this->idObligatorio('materia_id');
        $gradoId = $this->idObligatorio('grado_id');

        $materia = $this->materiaDelCatalogoMen($materiaId);
        $grado = $this->gradoDelCatalogoMen($gradoId);

        $emparejamiento = $this->areaDeLaMateria($materia);
        $conjunto = CatalogoDelMen::conjuntoDeGrado($grado->abrev, $grado->nombre);

        $area = CatalogoDelMen::area($emparejamiento['area']);
        $competencias = $conjunto['conjunto'] === null
            ? []
            : CatalogoDelMen::competencias($emparejamiento['area'], $conjunto['conjunto']);

        $porTipo = [];
        $adoptables = 0;

        foreach ($competencias as $competencia) {
            $porTipo[$competencia['tipo']] = ($porTipo[$competencia['tipo']] ?? 0) + 1;

            if (in_array($competencia['tipo'], CatalogoDelMen::ADOPTABLES, true)) {
                $adoptables++;
            }
        }

        return [
            'version' => CatalogoDelMen::cargar()['version'],
            'materia_id' => $materiaId,
            'materia' => $materia->materia,
            'grado_id' => $gradoId,
            'grado' => $grado->nombre,
            'area_men' => $area === null ? null : [
                'clave' => $area['clave'],
                'nombre' => $area['nombre'],
                'documento' => $area['documento'],
            ],
            'conjunto' => $conjunto['conjunto'],
            'conjunto_nombre' => $conjunto['conjunto'] === null
                ? null
                : CatalogoDelMen::conjuntos()[$conjunto['conjunto']]['nombre'],
            'emparejamiento' => [
                'materia' => $emparejamiento['motivo'],
                'materia_por' => $emparejamiento['por'],
                'grado' => $conjunto['motivo'],
            ],
            'cubierta' => $competencias !== [],
            'motivo' => $this->motivoDelCatalogo($emparejamiento, $conjunto, $competencias),
            'por_tipo' => $porTipo,
            'tipos_que_se_adoptan' => CatalogoDelMen::ADOPTABLES,
            'adoptables' => $adoptables,
            'competencias' => $competencias,
            'areas_del_men' => CatalogoDelMen::areas(),
        ];
    }

    /**
     * `POST desempenos` — una fila del plan de área.
     *
     * **El cuerpo se valida ANTES de autorizar, y el orden es el contrario del que
     * tenía.** No es un descuido: el permiso ahora **es con alcance**, y el alcance
     * —materia, grado— viene en el cuerpo, así que no hay nada que autorizar hasta
     * saber qué pide. La consecuencia visible es que un cuerpo mal formado contesta
     * 422 aunque quien lo mande no pudiera escribir nunca.
     *
     * @return array<string, mixed>
     */
    public function postPlantilla(): array
    {
        $yearId = (int) $this->user->year_id;
        $materiaId = $this->idObligatorio('materia_id');
        $this->materiaDelColegio($materiaId);

        $gradoId = $this->idOpcional('grado_id');
        if ($gradoId !== null) {
            $this->gradoDelColegio($gradoId);
        }

        $periodoId = $this->idObligatorio('periodo_id');
        $this->periodoDelAnio($periodoId, $yearId);

        $this->exigirEscrituraDelPlan($materiaId, $gradoId, $periodoId);

        $id = (int) DB::table('desempenos_por_defecto')->insertGetId([
            'year_id' => $yearId,
            'materia_id' => $materiaId,
            'grado_id' => $gradoId,
            'periodo_id' => $periodoId,
            'tipo' => $this->tipo(null),
            'definicion' => $this->texto('definicion', null),
            'orden' => Request::has('orden')
                ? $this->enteroNoNegativo(Request::input('orden'), 'orden')
                : $this->siguienteOrdenDelCatalogo($yearId, $materiaId, $gradoId, $periodoId),
            'created_by' => (int) $this->user->user_id,
            'created_at' => Reloj::ahoraTexto(),
            'updated_at' => Reloj::ahoraTexto(),
        ]);

        return $this->filaDelCatalogo($id);
    }

    /**
     * `PUT desempenos/{id}`.
     *
     * **Cada campo por defecto vale lo que ya hay guardado**, que es la trampa (1)
     * de la §5.1.e: los clientes de esta casa mandan el objeto entero unas veces y
     * un trozo otras, y un campo ausente tomado por «ponlo a null» mandaría al
     * colegio entero una fila que era de 6.º.
     *
     * > **Se autoriza el alcance de ANTES y el de DESPUÉS, y las dos mitades hacen
     * > falta.** Este método puede **mover** una fila: cambiarle la materia, el
     * > grado o el periodo. Con sólo el alcance nuevo, un docente se llevaría a su
     * > materia una fila que no podía tocar; con sólo el viejo, cogería la suya de
     * > 6.º y le pondría `grado_id: null`, que es «todos los grados» — el alcance
     * > que §3 le niega, cogido por la puerta de atrás y en un solo `PUT`.
     *
     * @return array<string, mixed>
     */
    public function putPlantilla($id): array
    {
        $yearId = (int) $this->user->year_id;
        $fila = $this->filaDeCatalogoDelAnio($id);

        $materiaId = Request::has('materia_id')
            ? $this->idObligatorio('materia_id')
            : (int) $fila->materia_id;
        $this->materiaDelColegio($materiaId);

        $gradoId = Request::has('grado_id')
            ? $this->idOpcional('grado_id')
            : ($fila->grado_id === null ? null : (int) $fila->grado_id);
        if ($gradoId !== null) {
            $this->gradoDelColegio($gradoId);
        }

        $periodoId = Request::has('periodo_id')
            ? $this->idObligatorio('periodo_id')
            : (int) $fila->periodo_id;
        $this->periodoDelAnio($periodoId, $yearId);

        $this->exigirEscrituraDelPlan(
            (int) $fila->materia_id,
            $fila->grado_id === null ? null : (int) $fila->grado_id,
            (int) $fila->periodo_id
        );
        $this->exigirEscrituraDelPlan($materiaId, $gradoId, $periodoId);

        DB::table('desempenos_por_defecto')->where('id', $fila->id)->update([
            'materia_id' => $materiaId,
            'grado_id' => $gradoId,
            'periodo_id' => $periodoId,
            'tipo' => $this->tipo($fila->tipo),
            'definicion' => $this->texto('definicion', $fila->definicion),
            'orden' => Request::has('orden')
                ? $this->enteroNoNegativo(Request::input('orden'), 'orden')
                : (int) $fila->orden,
            'updated_by' => (int) $this->user->user_id,
            'updated_at' => Reloj::ahoraTexto(),
        ]);

        return $this->filaDelCatalogo((int) $fila->id);
    }

    /**
     * `DELETE desempenos/{id}` — a la papelera.
     *
     * **Y es un borrado del boletín de mañana, no del de ayer.** Con el modelo plano
     * el boletín lee el catálogo vivo, así que borrar una fila **quita esa línea de
     * lo que se imprima desde ahora**, también en periodos ya cerrados del mismo
     * año. Es la otra cara del agujero que la cabecera de esta clase acepta a
     * sabiendas: el papel del periodo 1 puede cambiar hasta que acabe el año.
     *
     * @return array<string, mixed>
     */
    public function deletePlantilla($id): array
    {
        $fila = $this->filaDeCatalogoDelAnio($id);

        $this->exigirEscrituraDelPlan(
            (int) $fila->materia_id,
            $fila->grado_id === null ? null : (int) $fila->grado_id,
            (int) $fila->periodo_id
        );

        $ahora = Reloj::ahoraTexto();

        DB::table('desempenos_por_defecto')->where('id', $fila->id)->update([
            'deleted_at' => $ahora,
            'deleted_by' => (int) $this->user->user_id,
            'updated_at' => $ahora,
        ]);

        return ['id' => (int) $fila->id];
    }

    /**
     * `PUT desempenos/orden` — reordena **un grupo**, en una llamada.
     *
     *     { "materia_id": 15, "grado_id": null, "periodo_id": 91,
     *       "orden": [7, 3, 5] }
     *
     * **La posición en la lista ES el `orden`**, y la lista tiene que traer
     * exactamente las filas vivas de ese grupo: una lista parcial deja huecos y
     * repetidos, que es el estado del que se viene.
     *
     * @return array<string, mixed>
     */
    public function putOrdenPlantilla(): array
    {
        $yearId = (int) $this->user->year_id;
        $materiaId = $this->idObligatorio('materia_id');
        $gradoId = $this->idOpcional('grado_id');
        $periodoId = $this->idObligatorio('periodo_id');

        $this->exigirEscrituraDelPlan($materiaId, $gradoId, $periodoId);

        $pedidos = $this->listaDeIds(Request::input('orden'), 'orden');
        $existentes = array_map(
            fn ($d) => (int) $d->id,
            $this->grupoDelCatalogo($yearId, $materiaId, $gradoId, $periodoId)
        );

        $this->exigirElConjuntoEntero($pedidos, $existentes, 'del plan de área');

        foreach ($pedidos as $posicion => $filaId) {
            DB::table('desempenos_por_defecto')->where('id', $filaId)->update([
                'orden' => $posicion,
                'updated_by' => (int) $this->user->user_id,
                'updated_at' => Reloj::ahoraTexto(),
            ]);
        }

        return ['reordenados' => count($pedidos)];
    }

    /**
     * `PUT desempenos/copiar` — traer el plan de área de otra parte.
     *
     * ```json
     * { "destino": { "materia_id": 15, "grado_id": 11, "periodo_id": 91 },
     *   "origen":  { "tipo": "grado", "grado_id": 10 } }
     * ```
     *
     * | `origen.tipo` | de dónde |
     * |---|---|
     * | `grado`   | otro grado o periodo **del mismo año** |
     * | `year`    | el mismo alcance **de otro año** — lo que evita reescribir el plan de área cada enero |
     *
     * **No hay origen `men`, y eso es la D12 entera**: *«los desempeños sólo se
     * sugieren desde el propio colegio. No hay catálogo externo posible: el MEN
     * publica estándares por conjunto de grados, y el desempeño **por periodo** es
     * exactamente lo que cada colegio decide en su plan de área»*. Los catorce SIEE
     * dicen «elaborados por la Institución». Quien venga a añadir aquí un
     * `origen.tipo = "men"` tiene que releer D12 primero: el catálogo del MEN vive
     * en `desempenos/catalogo-men` y **su grano no es éste**.
     *
     * ## Copiar de otro AÑO: el periodo se resuelve por NÚMERO
     *
     * **Y hasta el 17 sep 2026 no se resolvía, con un desenlace que no se
     * distingue de la verdad.** `origen.periodo_id` ausente valía `destino.periodo_id`,
     * o sea **un periodo del año DESTINO** — y `periodos` es por año, con ids
     * disjuntos (medido: `year_id` 1 a 9, ids 1-4, 5-8, 9-12…). Ese id no existe en
     * el año origen, el grupo sale vacío y la respuesta era **`200` con
     * `copiados: 0, saltadas_sin_catalogo: 1`**: el colegio pide «tráeme el plan de
     * 2025» y recibe algo que no se distingue de «2025 no tenía nada escrito».
     *
     * Se resuelve por `periodos.numero`, que es la misma equivalencia que usan
     * `YearsController::crearLosPeriodos` y `copiarElPlanDeArea`. **Si ese número no
     * existe en el año origen contesta 422**, que es lo contrario de callarse: el
     * año que no tiene cuarto periodo se dice, no se devuelve vacío.
     *
     * > **Lo que sigue sin comprobarse, y queda dicho:** un `origen.periodo_id`
     * > mandado **a mano** no se contrasta contra `origen.year_id`. Ahí el cliente
     * > eligió el periodo y el 200 vacío vuelve a ser ambiguo — pero es una elección
     * > suya y no un descuido nuestro, y comprobarlo cambia el contrato de un campo
     * > que hoy acepta cualquier id.
     *
     * La respuesta dice la **población**, con cuatro desenlaces, y
     * `saltadas_sin_catalogo` es el que delata un origen mal dirigido.
     *
     * @return array<string, mixed>
     */
    public function putCopiarPlantilla(): array
    {
        $yearId = (int) $this->user->year_id;
        $destino = $this->destinoDelCuerpo($yearId);

        $this->exigirEscrituraDelPlan(
            $destino['materia_id'], $destino['grado_id'], $destino['periodo_id']
        );

        $origen = Request::input('origen');
        $origen = is_array($origen) ? $origen : [];
        $tipo = $origen['tipo'] ?? null;

        if (! in_array($tipo, ['grado', 'year'], true)) {
            abort(422, '`origen.tipo` tiene que ser "grado" o "year". No hay origen "men": '
                .'el MEN publica estándares por conjunto de grados y el desempeño va por '
                .'periodo, así que no hay catálogo externo que traer (D12).');
        }

        $deYear = $yearId;

        if ($tipo === 'year') {
            $deYear = $this->comoId($origen['year_id'] ?? null, 'origen.year_id');

            if ($deYear === null) {
                abort(422, '`origen.year_id` hace falta para copiar de otro año.');
            }

            if (! DB::table('years')->where('id', $deYear)->whereNull('deleted_at')->exists()) {
                abort(422, '`origen.year_id` apunta a un año que no existe.');
            }
        }

        $deMateria = array_key_exists('materia_id', $origen)
            ? $this->comoId($origen['materia_id'], 'origen.materia_id')
            : $destino['materia_id'];

        if ($deMateria === null) {
            abort(422, '`origen.materia_id` no es un identificador.');
        }

        $deGrado = array_key_exists('grado_id', $origen)
            ? $this->comoId($origen['grado_id'], 'origen.grado_id')
            : $destino['grado_id'];

        if (array_key_exists('periodo_id', $origen)) {
            $dePeriodo = $this->comoId($origen['periodo_id'], 'origen.periodo_id');
        } elseif ($deYear === $yearId) {
            // Mismo año: el periodo del destino **es** un periodo de este año, así
            // que heredarlo dice lo que parece decir.
            $dePeriodo = $destino['periodo_id'];
        } else {
            $dePeriodo = $this->mismoNumeroEnElAnio($destino['periodo_id'], $deYear);
        }

        if ($dePeriodo === null) {
            abort(422, '`origen.periodo_id` no es un identificador.');
        }

        /*
         * **Y tiene que ser un periodo DE ESE AÑO, aunque venga escrito en el
         * cuerpo.** Sin esto quedaba abierta la misma mentira por la otra puerta:
         * `mismoNumeroEnElAnio` protege el caso de **omitirlo** —contesta 422 si el
         * año de origen no tiene ese número—, pero un `periodo_id` **mandado** se
         * usaba tal cual. Como los ids de `periodos` son por año y disjuntos, uno
         * del año destino no casa ninguna fila del origen y la respuesta era **200
         * con `copiados: 0`**, que no se distingue de «el año pasado no tenía nada
         * escrito». Son dos cosas distintas y el colegio no puede saber cuál le
         * tocó.
         *
         * Lo destapó el front (`myvc-front-47`, 17 sep) construyendo el diálogo de
         * «traer de otro año»: **mandaba justo ese cuerpo**, y midió las dos formas
         * —0 copiadas con el viejo, 2 y 3 con el bueno, sobre las mismas filas—.
         * O sea que no es un caso de laboratorio: es el que escribe un cliente que
         * no sabe que los periodos son por año, que es lo normal.
         *
         * No se reutiliza `periodoDelAnio` porque su mensaje dice «de este año» y
         * aquí el año es **el de origen**: un 422 que nombra el año equivocado
         * manda a buscar el fallo donde no está.
         */
        $existe = DB::table('periodos')->where('id', $dePeriodo)
            ->where('year_id', $deYear)->whereNull('deleted_at')->exists();

        if (! $existe) {
            abort(422, '`origen.periodo_id` no es un periodo del año de origen. Los '
                .'periodos son por año: mande el del año '.$deYear.', o no mande el '
                .'campo y se resolverá por número de periodo.');
        }

        /*
         * **El origen no puede ser el destino.** Copiar un grupo sobre sí mismo
         * daría `revisados = N`, `saltados_por_duplicado = N` y `copiados = 0`: un
         * 200 que parece que funcionó y no hizo nada.
         */
        if ($deYear === $yearId && $deMateria === $destino['materia_id']
            && $deGrado === $destino['grado_id'] && $dePeriodo === $destino['periodo_id']) {
            abort(422, 'El origen y el destino son el mismo grupo: no hay nada que copiar.');
        }

        $candidatos = $this->grupoDelCatalogo($deYear, $deMateria, $deGrado, $dePeriodo);
        $yaEstan = $this->grupoDelCatalogo(
            $yearId, $destino['materia_id'], $destino['grado_id'], $destino['periodo_id']
        );

        $textos = [];

        foreach ($yaEstan as $fila) {
            $textos[$this->normalizar((string) $fila->definicion)] = true;
        }

        $conteo = [
            'revisados' => count($candidatos),
            'copiados' => 0,
            'saltados_por_duplicado' => 0,
            'saltadas_sin_catalogo' => $candidatos === [] ? 1 : 0,
        ];

        $orden = $this->siguienteOrdenDelCatalogo(
            $yearId, $destino['materia_id'], $destino['grado_id'], $destino['periodo_id']
        );
        $ahora = Reloj::ahoraTexto();

        foreach ($candidatos as $candidato) {
            $normal = $this->normalizar((string) $candidato->definicion);

            if (isset($textos[$normal])) {
                $conteo['saltados_por_duplicado']++;

                continue;
            }

            DB::table('desempenos_por_defecto')->insert([
                'year_id' => $yearId,
                'materia_id' => $destino['materia_id'],
                'grado_id' => $destino['grado_id'],
                'periodo_id' => $destino['periodo_id'],
                'tipo' => $candidato->tipo,
                'definicion' => $candidato->definicion,
                'orden' => $orden++,
                'created_by' => (int) $this->user->user_id,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ]);

            $textos[$normal] = true;
            $conteo['copiados']++;
        }

        Auditoria::registrar()
            ->crear('desempeno')
            ->en(year: $yearId)
            ->a($conteo + ['origen' => $tipo, 'origen_year_id' => $deYear] + $destino)
            ->resumen(sprintf(
                'Copió el plan de área desde %s: %d de %d revisados',
                $tipo, $conteo['copiados'], $conteo['revisados']
            ))
            ->guardar();

        return $conteo + [
            'origen' => [
                'tipo' => $tipo, 'year_id' => $deYear, 'materia_id' => $deMateria,
                'grado_id' => $deGrado, 'periodo_id' => $dePeriodo,
            ],
            'destino' => $destino,
        ];
    }

    private const SIN_PERMISO = 'No tiene permiso para escribir el plan de área de esa materia y ese grado.';

    private const MAXIMO = 2000;

    /**
     * Las dos puertas de la **P1.quater**, y quién paga el periodo cerrado.
     *
     * `Autoriza::puedeEscribirDesempenos` contesta por las dos ramas a la vez, así
     * que aquí se pregunta primero por la del colegio **para saber por cuál entró**:
     * es lo único que distingue quién tiene que traer además el periodo abierto.
     *
     *  - **el colegio** (`can_edit_plantilla_notas`) escribe con el periodo cerrado,
     *    y es a propósito: el coordinador cierra las notas *para* congelar las
     *    notas, y sigue teniendo que poder montar el plan de área;
     *  - **el docente** no: `profes_pueden_editar_notas` sigue siendo lo que ya
     *    decide en toda esta API quién escribe en un periodo.
     *
     * Con el orden al revés —periodo primero— el coordinador recibiría un 403 por
     * una guarda que no es suya.
     */
    private function exigirEscrituraDelPlan(int $materiaId, ?int $gradoId, int $periodoId): void
    {
        if (Autoriza::puedeEditarPlantillaNotas($this->user)) {
            return;
        }

        Autoriza::exigir(
            Autoriza::puedeEscribirDesempenos(
                $this->user, (int) $this->user->year_id, $materiaId, $gradoId
            ),
            self::SIN_PERMISO
        );

        $this->exigirPeriodoAbierto($periodoId);
    }

    /**
     * **Un periodo cerrado sigue cerrado para el docente**, con permiso de materia
     * y sin él.
     *
     * Y contesta **403 y no el 400 de `User::pueden_editar_notas`**: es código
     * nuevo, y el 400 de aquel guard no se toca porque lo llaman cinco métodos de
     * definitivas desde Flutter. Es el mismo criterio que las rutas de nivelar
     * (doc 22 §1.5).
     */
    private function exigirPeriodoAbierto(int $periodoId): void
    {
        $periodo = DB::selectOne(
            'SELECT id, profes_pueden_editar_notas FROM periodos
              WHERE id = ? AND deleted_at IS NULL',
            [$periodoId]
        );

        if ($periodo === null) {
            abort(422, 'Ese periodo no existe.');
        }

        if ((int) $periodo->profes_pueden_editar_notas === 0) {
            abort(403, 'El periodo está cerrado: no se puede escribir en él.');
        }
    }

    /**
     * El periodo de **otro año** con el mismo número que éste.
     *
     * **Corta con 422 si ese año no tiene ese número**, y no devuelve `null`: un
     * `null` aquí acabaría en el 200 vacío que este método existe para quitar — ver
     * `putCopiarPlantilla`, donde está medido.
     */
    private function mismoNumeroEnElAnio(int $periodoId, int $yearId): int
    {
        $numero = DB::selectOne(
            'SELECT numero FROM periodos WHERE id = ? AND deleted_at IS NULL',
            [$periodoId]
        );

        // No puede pasar: `destinoDelCuerpo` ya comprobó que ese periodo existe y
        // que es de este año. Se comprueba igual porque lo contrario es leer
        // `->numero` de un `null` y contestar un 500 en vez de decir qué falta.
        if ($numero === null) {
            abort(422, '`destino.periodo_id` no es un periodo de este año.');
        }

        $suNumero = (int) $numero->numero;

        $equivalente = DB::selectOne(
            'SELECT id FROM periodos WHERE year_id = ? AND numero = ? AND deleted_at IS NULL
              ORDER BY id LIMIT 1',
            [$yearId, $suNumero]
        );

        if ($equivalente === null) {
            abort(422, "El año de origen no tiene un periodo número {$suNumero}. "
                .'Mande `origen.periodo_id` si quiere copiar de otro periodo.');
        }

        return (int) $equivalente->id;
    }

    /** @return list<object> */
    private function grupoDelCatalogo(int $yearId, int $materiaId, ?int $gradoId, int $periodoId): array
    {
        return array_values(DB::select(
            'SELECT id, definicion, tipo, orden, materia_id, grado_id, periodo_id
               FROM desempenos_por_defecto
              WHERE year_id = ? AND materia_id = ? AND grado_id <=> ? AND periodo_id = ?
                AND deleted_at IS NULL
              ORDER BY orden, id',
            [$yearId, $materiaId, $gradoId, $periodoId]
        ));
    }

    /**
     * El `destino` del cuerpo de `copiar`, comprobado contra el colegio.
     *
     * @return array{materia_id: int, grado_id: ?int, periodo_id: int}
     */
    private function destinoDelCuerpo(int $yearId): array
    {
        $destino = Request::input('destino');

        if (! is_array($destino)) {
            abort(422, '`destino` tiene que traer `materia_id` y `periodo_id`.');
        }

        $materiaId = $this->comoId($destino['materia_id'] ?? null, 'destino.materia_id');
        $periodoId = $this->comoId($destino['periodo_id'] ?? null, 'destino.periodo_id');

        if ($materiaId === null || $periodoId === null) {
            abort(422, '`destino` tiene que traer `materia_id` y `periodo_id`.');
        }

        $this->materiaDelColegio($materiaId);
        $this->periodoDelAnio($periodoId, $yearId);

        $gradoId = $this->comoId($destino['grado_id'] ?? null, 'destino.grado_id');
        if ($gradoId !== null) {
            $this->gradoDelColegio($gradoId);
        }

        return ['materia_id' => $materiaId, 'grado_id' => $gradoId, 'periodo_id' => $periodoId];
    }

    /**
     * @param  list<int>  $pedidos
     * @param  list<int>  $existentes
     */
    private function exigirElConjuntoEntero(array $pedidos, array $existentes, string $que): void
    {
        $comprobar = $pedidos;
        sort($comprobar);
        $ordenados = $existentes;
        sort($ordenados);

        if ($comprobar !== $ordenados) {
            abort(422, "La lista de `orden` tiene que traer exactamente los desempeños vivos {$que}: "
                .'una lista parcial deja huecos y repetidos en el orden.');
        }
    }

    /** @return array<string, mixed> */
    private function filaDelCatalogo(int $id): array
    {
        $fila = DB::selectOne(
            'SELECT id, definicion, tipo, orden, materia_id, grado_id, periodo_id
               FROM desempenos_por_defecto WHERE id = ?',
            [$id]
        );

        return $this->catalogoConSusNombres([$fila])[0];
    }

    /**
     * @param  list<object>  $filas
     * @return list<array<string, mixed>>
     */
    private function catalogoConSusNombres(array $filas): array
    {
        $materias = $this->catalogo('materias');
        $grados = $this->catalogo('grados');

        return array_map(function ($fila) use ($materias, $grados) {
            $materiaId = (int) $fila->materia_id;
            $gradoId = $fila->grado_id === null ? null : (int) $fila->grado_id;

            return [
                'id' => (int) $fila->id,
                'definicion' => $fila->definicion,
                'tipo' => $fila->tipo,
                'orden' => (int) $fila->orden,
                'materia_id' => $materiaId,
                'materia' => $materias[$materiaId] ?? null,
                'grado_id' => $gradoId,
                'grado' => $gradoId === null ? null : ($grados[$gradoId] ?? null),
                'periodo_id' => (int) $fila->periodo_id,
            ];
        }, $filas);
    }

    /**
     * @param  list<object>  $filas
     * @return list<array<string, mixed>>
     */
    private function gruposDelCatalogo(array $filas): array
    {
        $materias = $this->catalogo('materias');
        $grados = $this->catalogo('grados');
        $grupos = [];

        foreach ($filas as $fila) {
            $materiaId = (int) $fila->materia_id;
            $gradoId = $fila->grado_id === null ? null : (int) $fila->grado_id;
            $periodoId = (int) $fila->periodo_id;
            $clave = $materiaId.'|'.$gradoId.'|'.$periodoId;

            $grupos[$clave] ??= [
                'materia_id' => $materiaId,
                'materia' => $materias[$materiaId] ?? null,
                'grado_id' => $gradoId,
                'grado' => $gradoId === null ? null : ($grados[$gradoId] ?? null),
                'periodo_id' => $periodoId,
                'desempenos' => 0,
            ];

            $grupos[$clave]['desempenos']++;
        }

        return array_values($grupos);
    }

    /** @return array<int, string> */
    private function catalogo(string $tabla): array
    {
        $columna = $tabla === 'materias' ? 'materia' : 'nombre';
        $filas = DB::select("SELECT id, {$columna} AS nombre FROM {$tabla} WHERE deleted_at IS NULL");

        $porId = [];

        foreach ($filas as $fila) {
            $porId[(int) $fila->id] = (string) $fila->nombre;
        }

        return $porId;
    }

    private function siguienteOrdenDelCatalogo(int $yearId, int $materiaId, ?int $gradoId, int $periodoId): int
    {
        $fila = DB::selectOne(
            'SELECT COALESCE(MAX(orden), -1) + 1 AS siguiente FROM desempenos_por_defecto
              WHERE year_id = ? AND materia_id = ? AND grado_id <=> ? AND periodo_id = ?
                AND deleted_at IS NULL',
            [$yearId, $materiaId, $gradoId, $periodoId]
        );

        return (int) ($fila->siguiente ?? 0);
    }

    /**
     * La fila del plan de área, comprobando que es **del año del token**. Sin eso,
     * cualquiera con el permiso editaría el plan de área de un año cerrado desde el
     * token de éste.
     */
    private function filaDeCatalogoDelAnio($id): object
    {
        if (! $this->esIdentificador($id)) {
            abort(404, 'Ese desempeño del plan de área no existe.');
        }

        $fila = DB::selectOne(
            'SELECT id, definicion, tipo, orden, materia_id, grado_id, periodo_id
               FROM desempenos_por_defecto
              WHERE id = ? AND year_id = ? AND deleted_at IS NULL',
            [(int) $id, (int) $this->user->year_id]
        );

        if ($fila === null) {
            abort(404, 'Ese desempeño del plan de área no existe.');
        }

        return $fila;
    }

    private function materiaDelColegio(int $id): void
    {
        if (! DB::table('materias')->where('id', $id)->whereNull('deleted_at')->exists()) {
            abort(422, '`materia_id` apunta a algo que no existe.');
        }
    }

    private function gradoDelColegio(int $id): void
    {
        if (! DB::table('grados')->where('id', $id)->whereNull('deleted_at')->exists()) {
            abort(422, '`grado_id` apunta a algo que no existe.');
        }
    }

    /**
     * El periodo, **y que sea del año del token**. Un periodo de otro año en el plan
     * de área es una fila que nadie leería y que nadie entendería.
     */
    private function periodoDelAnio(int $id, int $yearId): void
    {
        $existe = DB::table('periodos')->where('id', $id)->where('year_id', $yearId)
            ->whereNull('deleted_at')->exists();

        if (! $existe) {
            abort(422, '`periodo_id` no es un periodo de este año.');
        }
    }

    /**
     * La marca del desempeño (D7): **anulable, corta y con las palabras del
     * colegio** —saber/hacer/ser, cognitivo/procedimental/actitudinal,
     * conceptual…—. Nace vacía y el que no la quiera no la ve.
     */
    private function tipo(?string $porDefecto): ?string
    {
        if (! Request::has('tipo')) {
            return $porDefecto;
        }

        $valor = Request::input('tipo');

        if ($valor === null || (is_string($valor) && trim($valor) === '')) {
            return null;
        }

        if (! is_string($valor)) {
            abort(422, '`tipo` tiene que ser texto.');
        }

        $valor = trim($valor);

        if (mb_strlen($valor) > 60) {
            abort(422, '`tipo` no cabe: son '.mb_strlen($valor).' caracteres y el máximo es 60.');
        }

        return $valor;
    }

    private function texto(string $campo, ?string $porDefecto): string
    {
        $valor = Request::has($campo) ? Request::input($campo) : $porDefecto;

        if (! is_string($valor) || trim($valor) === '') {
            abort(422, "`{$campo}` no puede ir vacío.");
        }

        $valor = trim($valor);

        if (mb_strlen($valor) > self::MAXIMO) {
            abort(422, "`{$campo}` no cabe: son ".mb_strlen($valor)
                .' caracteres y el máximo es '.self::MAXIMO.'.');
        }

        return $valor;
    }

    private function idObligatorio(string $campo): int
    {
        $valor = Request::input($campo);

        if (! $this->esIdentificador($valor)) {
            abort(422, "`{$campo}` hace falta y tiene que ser un identificador.");
        }

        return (int) $valor;
    }

    private function idOpcional(string $campo): ?int
    {
        if (! Request::has($campo)) {
            return null;
        }

        return $this->comoId(Request::input($campo), $campo);
    }

    private function comoId($valor, string $campo): ?int
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        if (! $this->esIdentificador($valor)) {
            abort(422, "`{$campo}` no es un identificador.");
        }

        return (int) $valor;
    }

    /**
     * @param  mixed  $valor
     * @return list<int>
     */
    private function listaDeIds($valor, string $campo): array
    {
        if (! is_array($valor) || $valor === []) {
            abort(422, "`{$campo}` tiene que ser una lista de identificadores.");
        }

        $ids = [];

        foreach ($valor as $uno) {
            if (! $this->esIdentificador($uno)) {
                abort(422, "`{$campo}` trae algo que no es un identificador.");
            }

            $ids[] = (int) $uno;
        }

        if (count(array_unique($ids)) !== count($ids)) {
            abort(422, "`{$campo}` trae identificadores repetidos.");
        }

        return $ids;
    }

    private function enteroNoNegativo($valor, string $campo): int
    {
        if (! is_scalar($valor) || is_bool($valor) || preg_match('/^\d+$/', (string) $valor) !== 1) {
            abort(422, "`{$campo}` tiene que ser un entero sin decimales y no negativo.");
        }

        return (int) $valor;
    }

    private function esIdentificador($valor): bool
    {
        return is_scalar($valor) && preg_match('/^\d+$/', (string) $valor) === 1 && (int) $valor > 0;
    }

    // ── Los ayudantes del catálogo del MEN ───────────────────────────────────
    //
    // Vienen enteros de `CompetenciasController`, que se borró. **Contestan 404 y
    // no el 422 del resto del fichero**, y no se unifican: mudar una ruta de
    // familia cambia su URL, no sus respuestas, y el front que ya la llama no tiene
    // por qué enterarse de que cambió de vecinos.

    /**
     * La frase que explica un catálogo vacío, o `null` si trajo algo.
     *
     * @param  array{area: ?string, motivo: string, por: ?string}  $emparejamiento
     * @param  array{conjunto: ?string, grado: ?int, motivo: string}  $conjunto
     * @param  list<array<string, mixed>>  $competencias
     */
    private function motivoDelCatalogo(array $emparejamiento, array $conjunto, array $competencias): ?string
    {
        if ($competencias !== []) {
            return null;
        }

        if ($conjunto['motivo'] === 'preescolar') {
            return 'Los Estándares Básicos de Competencias empiezan en 1.º: el MEN no publica '
                .'estándares para preescolar. No es un fallo del colegio.';
        }

        if ($conjunto['motivo'] !== 'emparejado') {
            return 'No se pudo saber a qué grado del 1.º al 11.º corresponde este grado del colegio.';
        }

        if ($emparejamiento['motivo'] === 'sin_estandares') {
            return 'El MEN no publica Estándares Básicos de Competencias para esta área. '
                .'Escriba los desempeños una vez y cópielos cada año con `origen.tipo = "year"`.';
        }

        if ($emparejamiento['area'] === null) {
            return 'No se reconoció a qué área del MEN corresponde esta materia. Los Estándares '
                .'Básicos llegan a Lenguaje, Matemáticas, Ciencias Naturales, Ciencias Sociales, '
                .'Competencias Ciudadanas e Inglés; si es una de ellas, mande `area` a mano.';
        }

        return 'El catálogo del MEN no trae nada para esta área en este conjunto de grados.';
    }

    /**
     * @param  object{materia: string}  $materia
     * @return array{area: ?string, motivo: string, por: ?string}
     */
    private function areaDeLaMateria(object $materia): array
    {
        $pedida = Request::input('area');

        if ($pedida !== null && $pedida !== '') {
            if (CatalogoDelMen::area((string) $pedida) === null) {
                abort(422, '`area` no es un área del catálogo del MEN.');
            }

            return ['area' => (string) $pedida, 'motivo' => 'a_mano', 'por' => null];
        }

        return CatalogoDelMen::areaDeMateria($materia->materia);
    }

    private function materiaDelCatalogoMen(int $id): object
    {
        $fila = DB::selectOne('SELECT id, materia FROM materias WHERE id = ? AND deleted_at IS NULL', [$id]);

        if ($fila === null) {
            abort(404, 'Esa materia no existe.');
        }

        return $fila;
    }

    private function gradoDelCatalogoMen(int $id): object
    {
        $fila = DB::selectOne(
            'SELECT id, nombre, abrev FROM grados WHERE id = ? AND deleted_at IS NULL',
            [$id]
        );

        if ($fila === null) {
            abort(404, 'Ese grado no existe.');
        }

        return $fila;
    }

    /** Sin tildes, sin mayúsculas y sin espacios de sobra, para comparar textos. */
    private function normalizar(string $texto): string
    {
        $texto = mb_strtolower($texto, 'UTF-8');

        $texto = strtr($texto, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u',
            'ñ' => 'n', 'ç' => 'c',
        ]);

        return trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9]+/', ' ', $texto) ?? '') ?? '');
    }
}
