<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResuelveElUsuario;
use App\Services\Auditoria;
use App\Services\BoletinIndependiente;
use App\Support\Autoriza;
use App\Support\Reloj;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;

/**
 * Los desempeños: `desempenos_por_defecto` → `desempenos`.
 *
 * Es la **Fase 3** de
 * [35-el-modelo-de-evaluacion-del-colegio.md](../../../docs/migracion/35-el-modelo-de-evaluacion-del-colegio.md),
 * con **D5**, **D7**, **D10**, **D12**, **D13**, **D14** y **D25** encima. El
 * documento manda; si algo de aquí discrepa de él, el que está mal es éste —
 * **salvo los dos sitios donde discrepa a propósito**, que están dichos abajo con
 * su motivo.
 *
 * ## Las dos mitades, y quién escribe en cada una
 *
 * | | quién | qué es |
 * |---|---|---|
 * | `desempenos_por_defecto` | **el colegio**, con `can_edit_plantilla_notas` (D13) | el plan de área: un texto por materia + grado + periodo, escrito una vez |
 * | `desempenos` | **el docente**, en su asignatura y su periodo (D14) | lo que se imprime; lo siembra `sembrar` y el docente añade los suyos |
 *
 * ## Y una tercera mitad, que es la **Fase 4**: la rejilla premarcada
 *
 * `GET desempenos/rejilla` y `PUT desempenos/rejilla`, con **D6** y **D23**. La
 * rejilla cruza **los alumnos del grupo × los desempeños de la asignatura** y cada
 * casilla **es un nivel de `escalas_de_valoracion`** —no un sí/no—, premarcado con
 * el que al alumno le toca por su definitiva. **El `GET` no escribe nada**; la fila
 * existe cuando el docente guarda, y la fila está en **`frases_asignatura`**, que es
 * lo que los tres boletines ya leen.
 *
 * Está aquí y no en un controlador propio porque **es la pantalla de los
 * desempeños**: sus columnas son las filas de `desempenos`, comparte el `<=>` del
 * boletín independiente, comparte `exigirPeriodoAbierto()` y comparte los
 * validadores de identificador. Un `RejillaController` los copiaría todos.
 *
 * ## Las reglas de la §4 del doc 28, una por una y dónde viven aquí
 *
 * 1. **El catálogo siembra, no manda.** `sembrar` **copia el texto**; ningún
 *    desempeño enlaza a su fila de catálogo. Corregir una errata en 2028 no puede
 *    cambiar un boletín impreso en 2026.
 * 2. **Nada se siembra en un periodo cerrado**, y eso vale también para las
 *    escrituras de una en una: un periodo cerrado sigue cerrado **para todo el
 *    mundo, con permiso y sin él**. El candado de la D14 es *una guarda más*,
 *    nunca en lugar de la que ya estaba.
 * 3. **La fórmula de la definitiva no cambia.** Aquí no se calcula ninguna nota,
 *    ni se lee una.
 * 4. **Nada se siembra encima de lo que ya tiene.** Una asignatura+periodo con
 *    desempeños de curso **no se toca jamás**, y sale contada.
 * 5. **`alumno_id IS NULL` es el reparto del curso**, y se lee con **`<=>`**.
 *
 * ## Dos sitios donde este fichero NO sigue al plan, y por qué
 *
 * **1. Son doce rutas y el plan dice ocho — porque las ocho no dejan sitio a la
 * D14.** El plan lista seis sobre el catálogo, `sembrar` y el `GET` de la
 * planilla; y en el párrafo siguiente pide un candado *«en cuatro caminos:
 * `update`, `destroy`, `forcedelete` y `orden`»*, que son caminos sobre
 * **`desempenos`** —la tabla de instancia— y **no existen en esas ocho**. Con las
 * ocho, el candado no tiene nada que candar y *«el docente añade los suyos»* no se
 * entrega. Faltaban cuatro: `POST`, `PUT {id}`, `DELETE {id}` y `orden` sobre
 * `desempenos`. **Es el mismo caso que la §1.4**, que subió el total del plan de
 * 20 a 22 al descubrir que nadie podía escribir `modelo_evaluacion`: un hueco que
 * el documento no vio, no una ruta de más. **El total del plan pasa de 22 a 26.**
 *
 * **2. El candado son TRES caminos y el plan dice cuatro** —`update`, `destroy` y
 * `orden`—, y la diferencia es **sólo** que `forcedelete` no existe en esta
 * familia. **Los tres van candados, y `destroy` es el que no se puede dejar
 * fuera**: es un borrado **lógico**, así que un docente que pudiera llamarlo
 * borraría la fila del colegio y **la volvería a crear con `por_defecto = 0`, o
 * sea libre** — el candado de la D14 saltado entero sin tocar una ruta prohibida.
 * Es el rodeo que la §5.1.e midió sobre los nueve caminos de `unidades`, donde ya
 * pasó.
 *
 * > **No confundir las dos cosas, porque la confusión invita a dejar `destroy`
 * > abierto.** No tener `forcedelete` **no cierra el rodeo**: lo que lo cierra es
 * > candar `destroy`. Lo que sí ahorra es **un cuarto camino que habría que
 * > candar** — una superficie que no existe, no un agujero que se tapa solo.
 *
 * ## Lo que esta fase NO trajo, y se hizo detrás · **HECHO el 13 sep 2026**
 *
 * **`competencias` y `desempenos_por_defecto` son tablas POR AÑO, y cuando esta
 * fase entró no las copiaba nadie al crear el año siguiente.** El colegio que
 * escribiera su plan de área en 2026 lo habría encontrado **vacío en enero de
 * 2027** y lo habría reescrito entero.
 *
 * No se arregló aquí por un motivo de fontanería y no de criterio: lo hace
 * `YearsController::postStore`, que es de la **Fase 1**, y esta rama salió de un
 * commit anterior a ella — tocarlo desde aquí fabricaba un conflicto en vez de
 * arreglar nada. Fue **sobre `main`, detrás de las dos**:
 * `YearsController::copiarElPlanDeArea`, con los tres remapeos que hacen falta
 * —año, periodo y competencia padre—, y sus pruebas en `YearsTest`.
 *
 * Y era de los fallos caros: **no rompe nada hasta enero, y en enero no hay ninguna
 * línea en el log que lo explique**. Es exactamente la §1.bis del doc 28, donde las
 * subunidades por defecto se quedaron sin copiar **durante años** sin que nadie lo
 * viera. `CentinelaDeLasColumnasDelAnioNuevoTest` **no puede cazarlo**: vigila
 * columnas de `years`, no tablas hijas. El que sí, y que nació de esto, es
 * `CentinelaDeLasTablasDelAnioNuevoTest`, con su lista de excepciones y el motivo al
 * lado de cada una (censo de las 23 tablas por año en la §2.bis del 35).
 *
 * ## Y una advertencia del plan que NO se hereda, porque no aplica
 *
 * El plan avisa de que `PlantillaNotasController::exigirRepartosCompletos` devuelve
 * **42 entradas** en su 422 por agrupar por destino y no por plantilla, y pide que
 * `sembrar` *«nombre en su error las filas de catálogo que están mal»*. Aquí
 * **ese error no existe y no hay que inventarlo**: aquel 422 comprueba que los
 * porcentajes sumen 100, y **un desempeño no lleva porcentaje**. `sembrar` de esta
 * familia no tiene ningún 422 de esa clase; lo que dice lo dice en los contadores.
 *
 * ## Por qué SQL con columnas nombradas y nunca `SELECT *`
 *
 * Por lo mismo que el resto del repo, y con el motivo extra de siempre: estas dos
 * tablas van a ganar columnas —la Fase 4 ya trae tres— y un `SELECT *` las
 * repartiría solas a toda respuesta que devuelva un desempeño. Es la puerta por la
 * que `profesores.tono` se repartió a seis respuestas vivas sin que nadie lo
 * mandara.
 */
class DesempenosController extends Controller
{
    use ResuelveElUsuario;

    // ─────────────────────────────────────────────────────────────────────────
    // El catálogo del colegio: `desempenos_por_defecto`
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * `GET desempenos/plantilla[?materia_id&grado_id&periodo_id]` — el plan de
     * área del año.
     *
     * Enseña **todas** las filas del año, no sólo las que le tocarían a alguien:
     * es la pantalla donde el colegio las escribe, así que una fila mal dirigida
     * —a una materia que ya no existe, o a un periodo de otro año— **tiene que
     * verse** para poder arreglarse. Es la misma razón por la que
     * `PlantillaNotasController::getIndex` lee `todasDelAnio` y no `unidadesPara`.
     *
     * `grupos` es la mitad que hace útil la pantalla: dice de un vistazo **qué
     * materias y qué periodos están todavía vacíos**, que es la mitad del trabajo
     * que esta entrega existe para quitar.
     *
     * @return array<string, mixed>
     */
    public function getPlantilla(): array
    {
        Autoriza::exigir(
            Autoriza::puedeEditarPlantillaNotas($this->user),
            self::SIN_PERMISO
        );

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
            'SELECT d.id, d.definicion, d.tipo, d.orden, d.materia_id, d.grado_id,
                    d.periodo_id, d.competencia_id
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
     * `POST desempenos/plantilla` — una fila del plan de área.
     *
     * @return array<string, mixed>
     */
    public function postPlantilla(): array
    {
        Autoriza::exigir(
            Autoriza::puedeEditarPlantillaNotas($this->user),
            self::SIN_PERMISO
        );

        $yearId = (int) $this->user->year_id;
        $materiaId = $this->idObligatorio('materia_id');
        $this->materiaDelColegio($materiaId);

        $gradoId = $this->idOpcional('grado_id');
        if ($gradoId !== null) {
            $this->gradoDelColegio($gradoId);
        }

        $periodoId = $this->idObligatorio('periodo_id');
        $this->periodoDelAnio($periodoId, $yearId);

        $competenciaId = $this->idOpcional('competencia_id');
        if ($competenciaId !== null) {
            $this->competenciaDelAnio($competenciaId, $yearId);
        }

        $id = (int) DB::table('desempenos_por_defecto')->insertGetId([
            'year_id' => $yearId,
            'materia_id' => $materiaId,
            'grado_id' => $gradoId,
            'periodo_id' => $periodoId,
            'competencia_id' => $competenciaId,
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
     * `PUT desempenos/plantilla/{id}`.
     *
     * **Cada campo por defecto vale lo que ya hay guardado**, que es la trampa (1)
     * de la §5.1.e: los clientes de esta casa mandan el objeto entero unas veces y
     * un trozo otras, y un campo ausente tomado por «ponlo a null» mandaría al
     * colegio entero una fila que era de 6.º.
     *
     * @return array<string, mixed>
     */
    public function putPlantilla($id): array
    {
        Autoriza::exigir(
            Autoriza::puedeEditarPlantillaNotas($this->user),
            self::SIN_PERMISO
        );

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

        $competenciaId = Request::has('competencia_id')
            ? $this->idOpcional('competencia_id')
            : ($fila->competencia_id === null ? null : (int) $fila->competencia_id);
        if ($competenciaId !== null) {
            $this->competenciaDelAnio($competenciaId, $yearId);
        }

        DB::table('desempenos_por_defecto')->where('id', $fila->id)->update([
            'materia_id' => $materiaId,
            'grado_id' => $gradoId,
            'periodo_id' => $periodoId,
            'competencia_id' => $competenciaId,
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
     * `DELETE desempenos/plantilla/{id}` — a la papelera.
     *
     * **Y no toca lo ya sembrado**, que es el invariante entero: las filas que se
     * copiaron a las asignaturas **son del docente desde que se copiaron**. Borrar
     * del catálogo cambia lo que se sembrará mañana, nunca lo que ya se imprimió.
     *
     * @return array<string, mixed>
     */
    public function deletePlantilla($id): array
    {
        Autoriza::exigir(
            Autoriza::puedeEditarPlantillaNotas($this->user),
            self::SIN_PERMISO
        );

        $fila = $this->filaDeCatalogoDelAnio($id);
        $ahora = Reloj::ahoraTexto();

        DB::table('desempenos_por_defecto')->where('id', $fila->id)->update([
            'deleted_at' => $ahora,
            'deleted_by' => (int) $this->user->user_id,
            'updated_at' => $ahora,
        ]);

        return ['id' => (int) $fila->id];
    }

    /**
     * `PUT desempenos/plantilla/orden` — reordena **un grupo**, en una llamada.
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
        Autoriza::exigir(
            Autoriza::puedeEditarPlantillaNotas($this->user),
            self::SIN_PERMISO
        );

        $yearId = (int) $this->user->year_id;
        $materiaId = $this->idObligatorio('materia_id');
        $gradoId = $this->idOpcional('grado_id');
        $periodoId = $this->idObligatorio('periodo_id');

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
     * `PUT desempenos/plantilla/copiar` — traer el plan de área de otra parte.
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
     * en `competencias/catalogo-men` y **su grano no es éste**.
     *
     * La respuesta dice la **población**, con los mismos cuatro desenlaces que
     * `competencias/copiar`, y `saltadas_sin_catalogo` es el que delata un origen
     * mal dirigido.
     *
     * @return array<string, mixed>
     */
    public function putCopiarPlantilla(): array
    {
        Autoriza::exigir(
            Autoriza::puedeEditarPlantillaNotas($this->user),
            self::SIN_PERMISO
        );

        $yearId = (int) $this->user->year_id;
        $destino = $this->destinoDelCuerpo($yearId);
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

        $dePeriodo = array_key_exists('periodo_id', $origen)
            ? $this->comoId($origen['periodo_id'], 'origen.periodo_id')
            : $destino['periodo_id'];

        if ($dePeriodo === null) {
            abort(422, '`origen.periodo_id` no es un identificador.');
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

            /*
             * **`competencia_id` NO viaja en la copia, y no es un olvido.** Una
             * competencia es **del año y del grado**, así que el id de la del año
             * pasado —o el de otro grado— apuntaría a una fila que no le
             * corresponde al destino. Copiar el texto y **perder el padre** es lo
             * único que no miente: el colegio vuelve a colgarlo de la competencia
             * que toque, que es un clic, y mientras tanto el desempeño es válido
             * (D10: el padre es opcional).
             */
            DB::table('desempenos_por_defecto')->insert([
                'year_id' => $yearId,
                'materia_id' => $destino['materia_id'],
                'grado_id' => $destino['grado_id'],
                'periodo_id' => $destino['periodo_id'],
                'competencia_id' => null,
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

    /**
     * `PUT desempenos/sembrar` — **el único botón peligroso de esta fase**.
     *
     * Copia el plan de área a las asignaturas del año que todavía no lo tienen.
     * **Es explícito y no cuelga de un `GET`**: el `GET` que escribe
     * —`UnidadesController::getDeAsignaturaPeriodo`— está fichado en
     * [05](../../../docs/migracion/05-codigo-muerto-y-roto.md) §16, y que el doc 28
     * §8 diga que no se le quita al viejo **no es permiso para escribir otro**.
     *
     * ## Lo que NO tiene, y es deliberado: `reemplazar`
     *
     * `PlantillaNotasController::putSembrar` lo tiene porque **la plantilla reparte
     * el 100 %**: una asignatura montada con el reparto viejo hay que poder
     * rehacerla. Aquí no hay porcentajes y no hay nada que cuadre: una asignatura
     * que ya tiene desempeños **es trabajo del docente**, y machacarlo es
     * exactamente lo que la regla 4 prohíbe. Sale contada en
     * `saltadas_por_estructura` y el docente borra los suyos si quiere volver a
     * sembrar.
     *
     * ## La respuesta dice la POBLACIÓN, no `OK`
     *
     * Un «0 sembradas» tiene que poder distinguirse de «no revisé nada», y hay
     * **cinco** desenlaces:
     *
     * - `revisadas` — asignatura × periodo miradas;
     * - `sembradas`;
     * - `saltadas_por_periodo_cerrado` — regla 2;
     * - `saltadas_por_estructura` — ya tenía desempeños de curso (regla 4);
     * - `saltadas_sin_catalogo` — **el que delata un catálogo mal dirigido**: el
     *   colegio escribió el plan de área de «Matemáticas, 6.º» y su grupo de 6.º es
     *   de otra materia, o el periodo es de otro año;
     * - `independientes_respetadas` — **sube cuando había filas con dueño y se
     *   dejaron** (regla 5). No es `saltadas_por_independiente`, que valdría cero
     *   siempre porque esas filas no hacen saltar nada: un contador que no puede
     *   subir no dice si la regla corrió.
     *
     * ## Acumula por grado, no compite (D25)
     *
     * Un desempeño de «Matemáticas, todos los grados» y otro de «Matemáticas, 6.º»
     * se siembran **los dos**, ordenados por `orden`. Y **no se reutiliza
     * `App\Support\AlcanceDeLaPlantilla`**: sus gradas existen para no mezclar
     * repartos que suman 100, y aplicarlas aquí **escondería textos que el colegio
     * escribió**.
     *
     * @return array<string, mixed>
     */
    public function putSembrar(): array
    {
        Autoriza::exigir(
            Autoriza::puedeEditarPlantillaNotas($this->user),
            self::SIN_PERMISO
        );

        $yearId = (int) $this->user->year_id;

        $periodos = DB::select(
            'SELECT id, numero, profes_pueden_editar_notas
               FROM periodos WHERE year_id = ? AND deleted_at IS NULL
              ORDER BY numero, id',
            [$yearId]
        );

        $asignaturas = DB::select(
            'SELECT a.id, a.materia_id, g.grado_id
               FROM asignaturas a
               JOIN grupos g ON g.id = a.grupo_id AND g.deleted_at IS NULL
              WHERE g.year_id = ? AND a.deleted_at IS NULL
              ORDER BY a.id',
            [$yearId]
        );

        $conteo = [
            'revisadas' => 0,
            'sembradas' => 0,
            'saltadas_por_estructura' => 0,
            'saltadas_por_periodo_cerrado' => 0,
            'saltadas_sin_catalogo' => 0,
            'independientes_respetadas' => 0,
        ];

        $catalogoPorClave = [];
        $ahora = Reloj::ahoraTexto();
        $usuario = (int) $this->user->user_id;

        foreach ($periodos as $periodo) {
            foreach ($asignaturas as $asignatura) {
                $conteo['revisadas']++;

                if ((int) $periodo->profes_pueden_editar_notas === 0) {
                    $conteo['saltadas_por_periodo_cerrado']++;

                    continue;
                }

                $materiaId = $asignatura->materia_id === null ? null : (int) $asignatura->materia_id;
                $gradoId = $asignatura->grado_id === null ? null : (int) $asignatura->grado_id;
                $clave = $materiaId.'|'.$gradoId.'|'.$periodo->id;

                $catalogoPorClave[$clave] ??= $this->catalogoPara(
                    $yearId, $materiaId, $gradoId, (int) $periodo->id
                );

                if ($catalogoPorClave[$clave] === []) {
                    $conteo['saltadas_sin_catalogo']++;

                    continue;
                }

                $estado = $this->estadoDeLaAsignatura((int) $asignatura->id, (int) $periodo->id);

                if ($estado->con_dueno > 0) {
                    $conteo['independientes_respetadas'] += $estado->con_dueno;
                }

                if ($estado->de_curso > 0) {
                    $conteo['saltadas_por_estructura']++;

                    continue;
                }

                /*
                 * **El `orden` se RENUMERA al sembrar, no se copia — y esto lo
                 * destapó la prueba contra el docker, no la lectura.**
                 *
                 * D25 junta dos grupos de catálogo en una sola asignatura: el de
                 * «todos los grados» y el del grado. **Cada uno numera su `orden`
                 * desde 0 por su cuenta**, así que copiarlo deja la asignatura con
                 * dos filas en la posición 0 — y con `orden` duplicado la planilla
                 * sale en un orden que depende del `id`, **y el docente no puede
                 * reordenar sin empujar el candado**: cualquier lista que mande
                 * mueve de sitio una fila del colegio y recibe un 403.
                 *
                 * Es el mismo fallo que `Unidad::arreglarOrden` existe para tapar
                 * en la otra tabla, y aquí se evita en origen porque
                 * `catalogoPara()` ya devuelve las dos listas **en el orden bueno**:
                 * primero las de «todos los grados» y luego las del grado.
                 */
                $posicion = 0;

                foreach ($catalogoPorClave[$clave] as $fila) {
                    DB::table('desempenos')->insert([
                        'asignatura_id' => (int) $asignatura->id,
                        'periodo_id' => (int) $periodo->id,
                        'alumno_id' => null,
                        'competencia_id' => $fila->competencia_id === null ? null : (int) $fila->competencia_id,
                        'tipo' => $fila->tipo,
                        'definicion' => $fila->definicion,
                        'orden' => $posicion++,
                        // **La marca del colegio**, que es el candado de la D14.
                        'por_defecto' => 1,
                        'created_by' => $usuario,
                        'created_at' => $ahora,
                        'updated_at' => $ahora,
                    ]);
                }

                $conteo['sembradas']++;
            }
        }

        /*
         * **Una línea de auditoría y no una por asignatura**, y se escribe
         * **siempre**, también con `sembradas = 0`: «alguien lo apretó y no pasó
         * nada» es exactamente el suceso que se va a investigar dentro de un año.
         * Es el criterio de `putSembrar` de la plantilla, y por lo mismo: **lo que
         * tiene consecuencia es la copia, no el original**, así que el CRUD del
         * catálogo no lleva auditoría y esto sí.
         */
        Auditoria::registrar()
            ->crear('desempeno')
            ->en(year: $yearId)
            ->a($conteo)
            ->resumen(sprintf(
                'Sembró el plan de área del año: %d de %d asignatura+periodo revisadas',
                $conteo['sembradas'],
                $conteo['revisadas']
            ))
            ->guardar();

        return $conteo;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Lo que ve el docente: `desempenos`
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * `GET desempenos?asignatura_id=&periodo_id=[&alumno_id=]` — lo que lee la
     * planilla.
     *
     * **No escribe nada.** Es la diferencia con
     * `UnidadesController::getDeAsignaturaPeriodo`, que siembra al leer y está
     * fichado por eso: aquí sembrar es `PUT desempenos/sembrar`, y punto.
     *
     * Con `alumno_id` devuelve **los de ese alumno**, resueltos con
     * `BoletinIndependiente::alcance()` y **`<=>`**: `NULL <=> NULL` es verdad y el
     * alumno normal recibe los del grupo; con `= NULL` se quedaría **sin ninguno,
     * en 200 y sin error**, y el boletín saldría mudo. La respuesta dice **con qué
     * alcance leyó** para que una lista vacía se pueda explicar.
     *
     * Cada fila viaja con `por_defecto`, que es lo que el front necesita para
     * pintar en gris lo que el docente no puede tocar **antes** de que lo intente:
     * un candado que sólo se conoce por el 403 es un candado que se descubre
     * perdiendo lo escrito.
     *
     * @return array<string, mixed>
     */
    public function getIndex(): array
    {
        $asignaturaId = $this->idObligatorio('asignatura_id');
        $periodoId = $this->idObligatorio('periodo_id');
        $alumnoId = $this->idOpcional('alumno_id');

        $alcance = $alumnoId === null ? null : BoletinIndependiente::alcance($alumnoId, $periodoId);

        $filtros = ['d.asignatura_id = ?', 'd.periodo_id = ?'];
        $valores = [$asignaturaId, $periodoId];

        if ($alumnoId !== null) {
            $filtros[] = 'd.alumno_id <=> ?';
            $valores[] = $alcance;
        }

        $filas = DB::select(
            'SELECT d.id, d.definicion, d.tipo, d.orden, d.competencia_id, d.alumno_id,
                    d.por_defecto, c.definicion AS competencia
               FROM desempenos d
               LEFT JOIN competencias c ON c.id = d.competencia_id AND c.deleted_at IS NULL
              WHERE '.implode(' AND ', $filtros).' AND d.deleted_at IS NULL
              ORDER BY d.orden, d.id',
            $valores
        );

        return [
            'asignatura_id' => $asignaturaId,
            'periodo_id' => $periodoId,
            'alumno_id' => $alumnoId,
            // Lo que se comparó con `<=>`: `null` es «va con el grupo».
            'alcance' => $alcance,
            'independiente' => $alcance !== null,
            'desempenos' => array_map(fn ($d) => [
                'id' => (int) $d->id,
                'definicion' => $d->definicion,
                'tipo' => $d->tipo,
                'orden' => (int) $d->orden,
                'competencia_id' => $d->competencia_id === null ? null : (int) $d->competencia_id,
                'competencia' => $d->competencia,
                'alumno_id' => $d->alumno_id === null ? null : (int) $d->alumno_id,
                'por_defecto' => (int) $d->por_defecto,
            ], $filas),
        ];
    }

    /**
     * `POST desempenos` — **lo que el docente SÍ puede** (D14): añadir los suyos.
     *
     * *«Si al área se le olvidó un desempeño, el periodo no se pierde»* — y es la
     * diferencia entre una pantalla que se usa y una que se abandona.
     *
     * **Nace con `por_defecto = 0` siempre, y el cliente no puede decir otra cosa.**
     * Dejar que lo mandara sería dejar que cualquier docente marcara su fila como
     * «del colegio» —o desmarcara la del colegio al reescribirla—, que es la única
     * forma que tiene este candado de no valer nada.
     *
     * @return array<string, mixed>
     */
    public function postStore(): array
    {
        $asignaturaId = $this->idObligatorio('asignatura_id');
        $periodoId = $this->idObligatorio('periodo_id');

        $this->asignaturaQueExiste($asignaturaId);
        $this->exigirPeriodoAbierto($periodoId);

        $alumnoId = $this->idOpcional('alumno_id');
        if ($alumnoId !== null) {
            $this->alumnoDelColegio($alumnoId);
        }

        $competenciaId = $this->idOpcional('competencia_id');
        if ($competenciaId !== null) {
            $this->competenciaDelAnio($competenciaId, (int) $this->user->year_id);
        }

        $id = (int) DB::table('desempenos')->insertGetId([
            'asignatura_id' => $asignaturaId,
            'periodo_id' => $periodoId,
            'alumno_id' => $alumnoId,
            'competencia_id' => $competenciaId,
            'tipo' => $this->tipo(null),
            'definicion' => $this->texto('definicion', null),
            'orden' => Request::has('orden')
                ? $this->enteroNoNegativo(Request::input('orden'), 'orden')
                : $this->siguienteOrdenDeLaAsignatura($asignaturaId, $periodoId, $alumnoId),
            'por_defecto' => 0,
            'created_by' => (int) $this->user->user_id,
            'created_at' => Reloj::ahoraTexto(),
            'updated_at' => Reloj::ahoraTexto(),
        ]);

        return $this->filaDeLaAsignatura($id);
    }

    /**
     * `PUT desempenos/{id}` — **primer camino del candado**.
     *
     * @return array<string, mixed>
     */
    public function putUpdate($id): array
    {
        $fila = $this->desempenoQueExiste($id);
        $this->exigirPeriodoAbierto((int) $fila->periodo_id);

        $competenciaId = Request::has('competencia_id')
            ? $this->idOpcional('competencia_id')
            : ($fila->competencia_id === null ? null : (int) $fila->competencia_id);
        if ($competenciaId !== null) {
            $this->competenciaDelAnio($competenciaId, (int) $this->user->year_id);
        }

        $nuevos = [
            'competencia_id' => $competenciaId,
            'tipo' => $this->tipo($fila->tipo),
            'definicion' => $this->texto('definicion', $fila->definicion),
            'orden' => Request::has('orden')
                ? $this->enteroNoNegativo(Request::input('orden'), 'orden')
                : (int) $fila->orden,
        ];

        $this->exigirElCandado($fila, $nuevos);

        DB::table('desempenos')->where('id', $fila->id)->update($nuevos + [
            'updated_by' => (int) $this->user->user_id,
            'updated_at' => Reloj::ahoraTexto(),
        ]);

        return $this->filaDeLaAsignatura((int) $fila->id);
    }

    /**
     * `DELETE desempenos/{id}` — **segundo camino del candado**, y el que hace que
     * el primero no sea decorativo.
     *
     * Un desempeño que el docente no puede editar pero **sí borrar** es un
     * desempeño que puede editar en dos pasos —borrar y volver a crear—, y la
     * segunda vez nace con `por_defecto = 0`, o sea **ya libre**. Es el rodeo que
     * la §5.1.e midió en los nueve caminos de `unidades`.
     *
     * **Y el borrado es lógico, que es justo lo que lo hace peligroso**: no hace
     * falta ninguna papelera ni ningún `forcedelete` para dar el rodeo — basta con
     * este `DELETE` y un `POST` detrás. Por eso el candado va aquí y no «se cierra
     * solo» por no tener borrado físico.
     *
     * @return array<string, mixed>
     */
    public function deleteDestroy($id): array
    {
        $fila = $this->desempenoQueExiste($id);
        $this->exigirPeriodoAbierto((int) $fila->periodo_id);
        $this->exigirElCandado($fila, null);

        $ahora = Reloj::ahoraTexto();

        DB::table('desempenos')->where('id', $fila->id)->update([
            'deleted_at' => $ahora,
            'deleted_by' => (int) $this->user->user_id,
            'updated_at' => $ahora,
        ]);

        return ['id' => (int) $fila->id];
    }

    /**
     * `PUT desempenos/orden` — **tercer camino del candado**.
     *
     *     { "asignatura_id": 4, "periodo_id": 91, "alumno_id": null,
     *       "orden": [7, 3, 5] }
     *
     * **El criterio NO es «la lista nombra una fila del colegio»** —las nombra
     * siempre, están todas—, **sino «la deja en otra posición»**. Añadir un
     * desempeño propio al final no mueve ninguno del colegio y tiene que seguir
     * funcionando; arrastrar el del colegio a otro sitio, no. Es literalmente lo
     * que dice la trampa (2) de la §5.1.e sobre `update-orden`.
     *
     * @return array<string, mixed>
     */
    public function putOrden(): array
    {
        $asignaturaId = $this->idObligatorio('asignatura_id');
        $periodoId = $this->idObligatorio('periodo_id');
        $alumnoId = $this->idOpcional('alumno_id');

        $this->asignaturaQueExiste($asignaturaId);
        $this->exigirPeriodoAbierto($periodoId);

        $pedidos = $this->listaDeIds(Request::input('orden'), 'orden');
        $filas = $this->deLaAsignatura($asignaturaId, $periodoId, $alumnoId);

        $this->exigirElConjuntoEntero(
            $pedidos,
            array_map(fn ($d) => (int) $d->id, $filas),
            'de la asignatura'
        );

        $porId = [];

        foreach ($filas as $fila) {
            $porId[(int) $fila->id] = $fila;
        }

        foreach ($pedidos as $posicion => $filaId) {
            $this->exigirElCandado($porId[$filaId], ['orden' => $posicion]);
        }

        foreach ($pedidos as $posicion => $filaId) {
            DB::table('desempenos')->where('id', $filaId)->update([
                'orden' => $posicion,
                'updated_by' => (int) $this->user->user_id,
                'updated_at' => Reloj::ahoraTexto(),
            ]);
        }

        return ['reordenados' => count($pedidos)];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // La rejilla premarcada: `frases_asignatura`
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * `GET desempenos/rejilla?asignatura_id=&periodo_id=` — la rejilla del
     * docente, **premarcada y sin escribir una sola fila**.
     *
     * Es la **Fase 4** y es la **D23**: *«la casilla de la rejilla ES el nivel»*.
     * Cada cruce alumno × desempeño se abre con el nivel que al alumno le toca por
     * su definitiva, y **la fila de `frases_asignatura` sólo existe cuando el
     * docente guarda** (`PUT desempenos/rejilla`). Eso es lo que mantiene en pie la
     * decisión 10 del doc 28: **ningún boletín afirma un nivel que nadie miró**.
     *
     * > **Premarcar no es escribir, y ése es el contrato entero.** Lo sujeta
     * > `test_abrir_la_rejilla_no_escribe_ni_una_fila`, que cuenta
     * > `frases_asignatura` antes y después. Sin él, «premarcada» se convierte en
     * > «marcada de oficio» por un descuido de una línea y nadie lo vería: la
     * > respuesta sería idéntica.
     *
     * ## Los TRES estados de una celda, y por qué el front no tiene que adivinar
     *
     * Cada celda viaja con `estado` y, cuando está vacía, con el `motivo`:
     *
     * | `estado` | `motivo` | qué pinta el front |
     * |---|---|---|
     * | `propuesto` | `null` | el nivel **calculado**, marcado pero **sin guardar**: la base no lo sabe |
     * | `guardado` | `null` | el nivel que hay **en la base**, con su `frase_asignatura_id` |
     * | `vacia` | `sin_definitiva` | el alumno **no tiene** definitiva en esa asignatura y periodo |
     * | `vacia` | `sin_banda` | **sí tiene** nota y **ninguna banda la recoge** |
     *
     * **`sin_definitiva` y `sin_banda` son dos cosas distintas y acaban impresas
     * distinto** (D23, punto 2): *no tener nota* y *sacar 0* no son lo mismo. Por eso
     * no se aplanan a un solo «vacía»: la primera es un alumno al que falta
     * calificar y la segunda es **la escala del colegio mal montada**.
     *
     * Y por eso **`sin_banda` se enseña aquí**: ésta es la única pantalla donde una
     * escala con un agujero se ve **antes** de salir impresa en un boletín. Va en la
     * celda, en el alumno y en el recuento.
     *
     * ## `sin_banda` no es un caso de laboratorio, y esto no estaba en el plan
     *
     * El plan lo describe como *«las bandas pueden no cubrir la recta: con 0-59 y
     * 61-100, un 60 no casa con ninguna»*, o sea una escala mal escrita por el
     * colegio. **Medido contra el docker el 13 sep 2026, hay una segunda causa y ya
     * ha ocurrido**: `2026_08_30_200000_notas_finales_en_decimal` —que ya está en
     * `main`— volvió `notas_finales.nota` un `DECIMAL(7,4)`, y
     * `escalas_de_valoracion.porc_inicial` / `porc_final` **siguen siendo `int`**.
     *
     * Las bandas de `simonbolivar` son 0-29 / 30-39 / 40-45 / 46-50: contiguas **en
     * enteros**, con **un hueco en cada frontera**. De las 127.748 definitivas de la
     * base de desarrollo, **13 no casan con ninguna banda**, y **cuatro son por el
     * decimal**: `45.5000`, `45.0050`, `45.0500` —entre 40-45 y 46-50— y `39.3000`
     * —entre 30-39 y 40-45—. Las otras nueve son notas por encima del techo de la
     * escala (51 a 56 con la escala acabando en 50).
     *
     * Así que este contador **va a valer más que cero en colegios con la escala bien
     * escrita**, y el día que alguien recalcule definitivas con la columna ya
     * decimal va a crecer. No se arregla desde aquí —ensanchar las bandas es una
     * decisión del colegio sobre su SIEE— pero **se cuenta**, que es lo que permite
     * verlo.
     *
     * ## El nivel sale de la DEFINITIVA, no de la unidad
     *
     * `Unidad::deAsignaturaCalculada` hace este mismo cruce **por unidad**; la
     * rejilla es **por asignatura y periodo**. Se reutiliza **la forma**
     * —`porc_inicial <= nota <= porc_final`, con `year_id`— y no el método. La nota
     * es la definitiva y vive en `notas_finales`: **una consulta por asignatura y
     * periodo**, no una por alumno.
     *
     * > **Y el cruce se hace en PHP, no con un `LEFT JOIN`.** Nada obliga a que las
     * > bandas de un año no se solapen, y un `JOIN` que empareje dos bandas
     * > **duplica la fila del alumno** — lo que se ve entonces no es un error, es un
     * > alumno repetido en la rejilla. Recorriendo la escala ya ordenada por `orden`
     * > gana siempre la primera, que es determinista y se puede explicar. Y la
     * > escala son cuatro filas: no hay nada que optimizar.
     *
     * ## El nombre del nivel es de cada colegio
     *
     * `escalas_de_valoracion.desempenio` es texto libre, así que aquí **no se
     * suponen cuatro bandas ni los nombres**: se devuelve `escala` entera, en su
     * `orden`, y el front pinta los botones que haya. Un colegio con cinco niveles o
     * con «Excelente/Sobresaliente/Aceptable» funciona sin tocar una línea.
     *
     * ## Quién ve esto
     *
     * `auth.personal`, como las doce de la Fase 3, y **nada más**. La comprobación
     * de *«que la asignatura sea del profesor»* —que `FrasesAsignaturaController::postStore`
     * tampoco hace (§1.8)— **no se añade aquí a propósito**: la pediría también
     * `GET desempenos`, `GET notas` y media familia de planillas, así que ponerla en
     * una ruta sola deja el agujero abierto por las otras y de paso deja fuera al
     * coordinador que entra a revisar. Es una entrega propia, con su censo delante.
     * Lo que **sí** entra aquí es la otra mitad de §1.8, que es la que escribe: que
     * el alumno esté en el grupo de la asignatura — ver `putRejilla`.
     *
     * @return array<string, mixed>
     */
    public function getRejilla(): array
    {
        $asignaturaId = $this->idObligatorio('asignatura_id');
        $periodoId = $this->idObligatorio('periodo_id');

        $contexto = $this->contextoDeLaRejilla($asignaturaId, $periodoId);
        $alumnos = $this->alumnosDeLaRejilla($contexto->grupo_id, $periodoId);
        $escala = $this->escalaDelAnio($contexto->year_id);
        $desempenos = $this->desempenosDeLaRejilla($asignaturaId, $periodoId, $alumnos);
        $definitivas = $this->definitivasDe($asignaturaId, $periodoId);
        $guardadas = $this->celdasGuardadas($asignaturaId, $periodoId, array_keys($alumnos));

        $poblacion = [
            'alumnos' => count($alumnos),
            'desempenos' => count($desempenos),
            'niveles' => count($escala),
            'celdas' => 0,
            'alumnos_con_nivel' => 0,
            'alumnos_sin_definitiva' => 0,
            'alumnos_sin_banda' => 0,
            'celdas_guardadas' => 0,
            'celdas_propuestas' => 0,
            'celdas_vacias' => 0,
        ];

        $filasDeAlumno = [];
        $celdas = [];

        foreach ($alumnos as $alumnoId => $alumno) {
            $nota = $definitivas[$alumnoId] ?? null;
            $banda = $nota === null ? null : $this->bandaDeLaNota($nota, $escala);

            $motivoDelAlumno = match (true) {
                $nota === null => 'sin_definitiva',
                $banda === null => 'sin_banda',
                default => null,
            };

            match ($motivoDelAlumno) {
                'sin_definitiva' => $poblacion['alumnos_sin_definitiva']++,
                'sin_banda' => $poblacion['alumnos_sin_banda']++,
                default => $poblacion['alumnos_con_nivel']++,
            };

            $filasDeAlumno[] = [
                'alumno_id' => $alumnoId,
                'nombres' => $alumno->nombres,
                'apellidos' => $alumno->apellidos,
                // Lo que se comparó con `<=>`: `null` es «va con el grupo».
                'alcance' => $alumno->alcance,
                'independiente' => $alumno->alcance !== null,
                'nota' => $nota,
                'escala_id' => $banda === null ? null : (int) $banda->id,
                'nivel' => $banda === null ? null : $banda->desempenio,
                'motivo' => $motivoDelAlumno,
            ];

            foreach ($desempenos as $desempeno) {
                /*
                 * **Esto ES el `<=>`, escrito en PHP.** Un desempeño con dueño
                 * —`alumno_id` no nulo— es del boletín independiente de ESE alumno
                 * y de nadie más; uno con `alumno_id NULL` es el reparto del curso
                 * y le toca a quien vaya con el grupo. `===` entre dos `?int` casa
                 * `null` con `null` igual que el operador de MySQL; con `==` a
                 * secas, `null == 0` sería verdad y un desempeño con dueño se
                 * colaría en la fila de todo el mundo.
                 */
                if ($desempeno->alumno_id !== $alumno->alcance) {
                    continue;
                }

                $poblacion['celdas']++;
                $guardada = $guardadas[$alumnoId.':'.((int) $desempeno->id)] ?? null;

                if ($guardada !== null) {
                    $poblacion['celdas_guardadas']++;
                    $celdas[] = [
                        'alumno_id' => $alumnoId,
                        'desempeno_id' => (int) $desempeno->id,
                        'estado' => 'guardado',
                        'motivo' => null,
                        'escala_id' => $guardada->escala_id === null ? null : (int) $guardada->escala_id,
                        // **El texto congelado, no el de hoy**: es lo que se imprime.
                        'nivel' => $guardada->nivel,
                        'frase_asignatura_id' => (int) $guardada->id,
                    ];

                    continue;
                }

                if ($banda === null) {
                    $poblacion['celdas_vacias']++;
                    $celdas[] = [
                        'alumno_id' => $alumnoId,
                        'desempeno_id' => (int) $desempeno->id,
                        'estado' => 'vacia',
                        'motivo' => $motivoDelAlumno,
                        'escala_id' => null,
                        'nivel' => null,
                        'frase_asignatura_id' => null,
                    ];

                    continue;
                }

                $poblacion['celdas_propuestas']++;
                $celdas[] = [
                    'alumno_id' => $alumnoId,
                    'desempeno_id' => (int) $desempeno->id,
                    'estado' => 'propuesto',
                    'motivo' => null,
                    'escala_id' => (int) $banda->id,
                    'nivel' => $banda->desempenio,
                    // **`null` y no un id**: una celda propuesta no tiene fila.
                    'frase_asignatura_id' => null,
                ];
            }
        }

        return [
            'asignatura_id' => $asignaturaId,
            'periodo_id' => $periodoId,
            'grupo_id' => $contexto->grupo_id,
            'year_id' => $contexto->year_id,
            // Para que el front sepa **antes** de dejar tocar la rejilla si el
            // `PUT` le va a contestar 403. Un candado que sólo se conoce por el
            // error es un candado que se descubre perdiendo lo escrito.
            'periodo_abierto' => $contexto->periodo_abierto,
            'escala' => $this->escalaParaElFront($escala),
            'desempenos' => $this->desempenosParaElFront($desempenos),
            'alumnos' => $filasDeAlumno,
            'celdas' => $celdas,
            'poblacion' => $poblacion,
        ];
    }

    /**
     * `PUT desempenos/rejilla` — lo que el docente confirma.
     *
     *     { "asignatura_id": 4, "periodo_id": 91,
     *       "celdas": [ {"alumno_id": 3, "desempeno_id": 55, "escala_id": 17},
     *                   {"alumno_id": 4, "desempeno_id": 55, "escala_id": null} ] }
     *
     * **Una llamada y no ~300.** Es la medición que justifica la ruta:
     * `FrasesAsignaturaController::postStore` guarda **una frase por petición**, y
     * una rejilla son 30 alumnos × 10 desempeños.
     *
     * ## Qué escribe, y dónde
     *
     * En **`frases_asignatura`**, que es lo que los tres boletines ya leen: **no
     * añade ni una consulta** al camino que tarda 24-63 s. Cada celda con nivel es
     * una fila con:
     *
     *   - `frase` ← **el texto del desempeño, copiado** (D9). Es lo que protege los
     *     boletines viejos: corregir el desempeño en 2028 no cambia lo impreso en
     *     2026 porque lo impreso es esta copia.
     *
     *     > **Y la copia se rehace al volver a guardar esa celda, que es la mitad
     *     > que hay que decir para no prometer de más.** Medido contra el docker:
     *     > cambiado el texto del desempeño y reguardado el mismo nivel, la celda
     *     > sale `cambiadas: 1` y `frase` pasa a ser el texto nuevo. Es lo que se
     *     > quiere —el docente que corrige una errata y guarda espera verla
     *     > corregida— y **lo que de verdad protege un boletín de un año pasado no
     *     > es la copia, es que su periodo esté cerrado**: sin un guardado nuevo no
     *     > se mueve una letra, y un periodo cerrado no admite guardados.
     *   - `desempeno_id` ← de qué casilla salió, que es lo único que permite volver
     *     a pintar la rejilla sin comparar cadenas de 200 caracteres.
     *   - `escala_id` + `nivel` ← el id **y el nombre** del nivel (D23). Dos
     *     columnas y no una, por el mismo motivo que `frase` frente a `frase_id`:
     *     `escalas_de_valoracion` es **editable**, así que renombrar «Básico» en
     *     2028 cambiaría un boletín de 2026 si sólo se guardara el id.
     *
     * **`escala_id: null` borra la celda** (borrado lógico), que es como el docente
     * dice «a este alumno este desempeño no se le pone». No deja fila, así que el
     * boletín no imprime nada por ella.
     *
     * ## Las frases escritas a mano no se tocan, y eso es la mitad del diseño
     *
     * `frases_asignatura` tiene 12.294 filas en `simonbolivar` y **ninguna salió de
     * una rejilla**. La rejilla mira **sólo** las filas con `desempeno_id IS NOT
     * NULL`: las de la pantalla de frases de siempre no se leen, no se cambian y no
     * se borran. Las dos pantallas escriben en la misma tabla sin comerse la una a
     * la otra. Va comprobado en `test_la_rejilla_no_toca_las_frases_escritas_a_mano`.
     *
     * ## O entra todo o no entra nada — y aquí este método se aparta del plan
     *
     * El plan pide devolver `saltadas_por_periodo_cerrado` y
     * `saltadas_por_no_ser_del_grupo` **y**, tres renglones más abajo, que un
     * periodo cerrado no escriba nada y que un alumno ajeno sea **422**. Las dos
     * cosas no caben a la vez: **con el corte entero esos dos contadores no pueden
     * valer nunca más que cero**, y un contador que no puede moverse es un contador
     * que alguien leerá como «no pasó» cuando lo que pasó es que no existe.
     *
     * Se elige el corte, por tres motivos y ninguno es de estilo:
     *
     *   1. **Es lo que piden los tests del propio plan** —*«marcar en un periodo
     *      cerrado no escribe nada»*, *«a un alumno que no es del grupo → 422, y no
     *      escribe»*—, que son la parte del plan que está medida.
     *   2. **Es la forma de la Fase 3.** `exigirPeriodoAbierto()` contesta 403 para
     *      la llamada entera; hacer aquí lo contrario dejaría dos rutas hermanas
     *      decidiendo distinto sobre el mismo periodo.
     *   3. **Un 200 que guardó media rejilla es una respuesta que miente.** El
     *      docente ve «guardado» y veinte alumnos se quedaron fuera.
     *
     * Y **todo se comprueba antes de escribir la primera fila**, no sobre la marcha:
     * con las comprobaciones dentro del bucle, la celda 1 ya estaría escrita cuando
     * la 40 aborta, y `DatabaseTransactions` **no** salva a nadie fuera de los tests.
     *
     * ## La población, que es lo que se devuelve en vez de un «listo»
     *
     * `{recibidas, escritas, cambiadas, sin_cambio, borradas, ya_vacias, alumnos,
     * desempenos}`. `sin_cambio` y `ya_vacias` no son relleno: son la diferencia
     * entre *«se guardó y no cambió nada»* y *«no se guardó»*, que es justo lo que
     * un docente pregunta cuando cree que perdió el trabajo.
     *
     * @return array<string, mixed>
     */
    public function putRejilla(): array
    {
        $asignaturaId = $this->idObligatorio('asignatura_id');
        $periodoId = $this->idObligatorio('periodo_id');

        $contexto = $this->contextoDeLaRejilla($asignaturaId, $periodoId);

        // **Un periodo cerrado sigue cerrado para todo el mundo.** 403, y antes de
        // mirar el cuerpo: lo que no se puede escribir no se valida.
        $this->exigirPeriodoAbierto($periodoId);

        $pedidas = $this->celdasDelCuerpo();

        $alumnos = $this->alumnosDeLaRejilla($contexto->grupo_id, $periodoId);

        $escala = [];
        foreach ($this->escalaDelAnio($contexto->year_id) as $banda) {
            $escala[(int) $banda->id] = $banda;
        }

        $desempenos = [];
        foreach ($this->desempenosDeLaRejilla($asignaturaId, $periodoId, $alumnos) as $desempeno) {
            $desempenos[(int) $desempeno->id] = $desempeno;
        }

        // ── Todo, antes de escribir nada ────────────────────────────────────
        foreach ($pedidas as $celda) {
            /*
             * **La comprobación que hoy falta y que entra aquí** (§1.8): que el
             * alumno esté en el grupo de la asignatura. `postStore` de
             * `FrasesAsignaturaController` no la hace, así que con su ruta se le
             * puede poner una frase de boletín a **cualquier alumno del colegio**.
             * Esto es código nuevo, así que contesta **422 y no el 400** de
             * `User::pueden_editar_notas`, que no se toca porque lo llaman cinco
             * métodos de definitivas desde Flutter.
             */
            if (! isset($alumnos[$celda['alumno_id']])) {
                abort(422, "El alumno {$celda['alumno_id']} no está matriculado en el grupo de esa asignatura.");
            }

            if (! isset($desempenos[$celda['desempeno_id']])) {
                abort(422, "El desempeño {$celda['desempeno_id']} no es de esa asignatura y ese periodo.");
            }

            // El `<=>` otra vez, y en el camino que escribe: un desempeño del
            // boletín independiente de un alumno no se le pone a otro.
            if ($desempenos[$celda['desempeno_id']]->alumno_id !== $alumnos[$celda['alumno_id']]->alcance) {
                abort(422, "El desempeño {$celda['desempeno_id']} no le toca al alumno {$celda['alumno_id']}: "
                    .'uno de los dos va por boletín independiente y el otro no.');
            }

            if ($celda['escala_id'] !== null && ! isset($escala[$celda['escala_id']])) {
                abort(422, "`escala_id` {$celda['escala_id']} no es un nivel de la escala de valoración de este año.");
            }
        }

        // ── Y ahora sí ──────────────────────────────────────────────────────
        $guardadas = $this->celdasGuardadas($asignaturaId, $periodoId, array_keys($alumnos));

        $conteo = [
            'recibidas' => count($pedidas),
            'escritas' => 0,
            'cambiadas' => 0,
            'sin_cambio' => 0,
            'borradas' => 0,
            'ya_vacias' => 0,
            'alumnos' => count($alumnos),
            'desempenos' => count($desempenos),
        ];

        $ahora = Reloj::ahoraTexto();
        $usuario = (int) $this->user->user_id;

        foreach ($pedidas as $celda) {
            $fila = $guardadas[$celda['alumno_id'].':'.$celda['desempeno_id']] ?? null;

            if ($celda['escala_id'] === null) {
                if ($fila === null) {
                    $conteo['ya_vacias']++;

                    continue;
                }

                // Borrado **lógico**, como todo lo de esta tabla: la frase del
                // boletín de un alumno es lo que un acudiente nota que falta.
                DB::table('frases_asignatura')->where('id', $fila->id)->update([
                    'deleted_at' => $ahora,
                    'deleted_by' => $usuario,
                    'updated_at' => $ahora,
                ]);

                $conteo['borradas']++;

                continue;
            }

            $banda = $escala[$celda['escala_id']];
            $texto = $desempenos[$celda['desempeno_id']]->definicion;

            $valores = [
                'frase' => $texto,
                'desempeno_id' => $celda['desempeno_id'],
                'escala_id' => (int) $banda->id,
                // **El nombre del nivel, copiado el día que se puso.** Ver la
                // migración: sin esta columna, renombrar la escala en 2028 cambia
                // un boletín de 2026.
                'nivel' => $banda->desempenio,
            ];

            if ($fila === null) {
                DB::table('frases_asignatura')->insert($valores + [
                    'alumno_id' => $celda['alumno_id'],
                    'asignatura_id' => $asignaturaId,
                    'periodo_id' => $periodoId,
                    // `null` a propósito: esto **no** sale del catálogo de frases.
                    // `FraseAsignatura::deAlumno` hace `IFNULL(f.frase, fa.frase)`,
                    // así que con un `frase_id` el boletín imprimiría la frase del
                    // catálogo en lugar del desempeño.
                    'frase_id' => null,
                    'created_by' => $usuario,
                    'created_at' => $ahora,
                    'updated_at' => $ahora,
                ]);

                $conteo['escritas']++;

                continue;
            }

            if ((int) $fila->escala_id === (int) $banda->id
                && $fila->nivel === $banda->desempenio
                && $fila->frase === $texto) {
                $conteo['sin_cambio']++;

                continue;
            }

            DB::table('frases_asignatura')->where('id', $fila->id)->update($valores + [
                'updated_by' => $usuario,
                'updated_at' => $ahora,
            ]);

            $conteo['cambiadas']++;
        }

        /*
         * **Una línea y no trescientas**, y se escribe **siempre**, también con
         * `escritas = 0`: «alguien le dio a guardar y no pasó nada» es exactamente
         * el suceso que se va a investigar dentro de un año. Es el criterio de
         * `putSembrar`.
         *
         * Sin `deAlumno()`: esto es el grupo entero, y poner ahí a uno de los
         * treinta sería peor que no poner a ninguno.
         */
        Auditoria::registrar()
            ->editar('frase_asignatura')
            ->en(
                asignatura: $asignaturaId,
                periodo: $periodoId,
                grupo: $contexto->grupo_id,
                year: $contexto->year_id
            )
            ->a($conteo)
            ->resumen(sprintf(
                'Guardó la rejilla de desempeños: %d celdas, %d escritas, %d cambiadas, %d borradas',
                $conteo['recibidas'],
                $conteo['escritas'],
                $conteo['cambiadas'],
                $conteo['borradas']
            ))
            ->guardar();

        return $conteo;
    }

    private const SIN_PERMISO = 'No tiene permiso para editar el plan de área del colegio.';

    /**
     * **El 403 del candado se lee en un móvil**, porque en el `myvc_flutter` viejo
     * es lo único que va a ver el docente. La §5.1.e lo pide con estas palabras.
     */
    private const CANDADO = 'Este desempeño lo puso el colegio y no se puede cambiar aquí.';

    private const MAXIMO = 2000;

    /**
     * El candado de la D14, **comparando VALORES y no la presencia del campo**.
     *
     * Es la trampa (1) de la §5.1.e, medida sobre los clientes de verdad:
     * `myvc_flutter/lib/Http/UnidadesApi.dart` manda `nota_default` *«siempre,
     * aunque no se haya tocado»*, y `myvc_front/app2/src/app/datos/subunidades.ts`
     * dice que *«los tres se escriben siempre»*. Un candado que mirara si el campo
     * **viene** rechazaría **todos** los guardados, incluidos los que no cambian
     * nada — y sobre todo los de la app vieja, que sigue en los dieciséis colegios.
     *
     * Con la comparación por valor, **la app vieja sigue funcionando entera**
     * mientras el docente no intente cambiar de verdad una fila del colegio. El 403
     * sólo aparece cuando alguien empuja el candado.
     *
     * `$nuevos === null` es el borrado, donde no hay valor que comparar: una fila
     * del colegio no se borra, y punto.
     *
     * @param  array<string, mixed>|null  $nuevos
     */
    private function exigirElCandado(object $fila, ?array $nuevos): void
    {
        if ((int) $fila->por_defecto === 0) {
            return;
        }

        // **Quien puso el plan de área puede corregir una errata en UNA asignatura
        // sin cambiar la del colegio entero.** Es la cuarta pregunta de la §5.1.e,
        // y es lo que hace que el candado no se convierta en una llamada a soporte.
        if (Autoriza::puedeEditarPlantillaNotas($this->user)) {
            return;
        }

        if ($nuevos === null) {
            abort(403, self::CANDADO);
        }

        foreach ($nuevos as $campo => $valor) {
            // `!=` y no `!==`: lo que llega de la petición es cadena y lo que sale
            // de MySQL también, pero `orden` viaja como entero en un lado y como
            // cadena en el otro. Comparar tipos aquí daría 403 en un guardado que
            // no cambia nada, que es justo lo que la trampa (1) prohíbe.
            if ($valor != ($fila->{$campo} ?? null)) {
                abort(403, self::CANDADO);
            }
        }
    }

    /**
     * **Un periodo cerrado sigue cerrado para todo el mundo, con permiso y sin
     * él.** El candado de la D14 es una guarda **más**, nunca en lugar de ésta.
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
     * Las filas de catálogo que le tocan a una asignatura: **las del grado Y las
     * de «todos los grados»**, juntas (D25).
     *
     * @return list<object>
     */
    private function catalogoPara(int $yearId, ?int $materiaId, ?int $gradoId, int $periodoId): array
    {
        if ($materiaId === null) {
            return [];
        }

        return array_values(DB::select(
            'SELECT id, definicion, tipo, orden, competencia_id, grado_id
               FROM desempenos_por_defecto
              WHERE year_id = ? AND materia_id = ? AND periodo_id = ?
                AND (grado_id IS NULL'.($gradoId === null ? ')' : ' OR grado_id = ?)').'
                AND deleted_at IS NULL
              ORDER BY grado_id IS NOT NULL, orden, id',
            $gradoId === null
                ? [$yearId, $materiaId, $periodoId]
                : [$yearId, $materiaId, $periodoId, $gradoId]
        ));
    }

    /**
     * Cuántos desempeños de curso y cuántos con dueño tiene una asignatura+periodo.
     * Los dos en una consulta porque se preguntan siempre a la vez y son
     * asignaturas × periodos.
     */
    private function estadoDeLaAsignatura(int $asignaturaId, int $periodoId): object
    {
        $fila = DB::selectOne(
            'SELECT SUM(alumno_id IS NULL) AS de_curso, SUM(alumno_id IS NOT NULL) AS con_dueno
               FROM desempenos
              WHERE asignatura_id = ? AND periodo_id = ? AND deleted_at IS NULL',
            [$asignaturaId, $periodoId]
        );

        return (object) [
            'de_curso' => (int) ($fila->de_curso ?? 0),
            'con_dueno' => (int) ($fila->con_dueno ?? 0),
        ];
    }

    /** @return list<object> */
    private function grupoDelCatalogo(int $yearId, int $materiaId, ?int $gradoId, int $periodoId): array
    {
        return array_values(DB::select(
            'SELECT id, definicion, tipo, orden, materia_id, grado_id, periodo_id, competencia_id
               FROM desempenos_por_defecto
              WHERE year_id = ? AND materia_id = ? AND grado_id <=> ? AND periodo_id = ?
                AND deleted_at IS NULL
              ORDER BY orden, id',
            [$yearId, $materiaId, $gradoId, $periodoId]
        ));
    }

    /** @return list<object> */
    private function deLaAsignatura(int $asignaturaId, int $periodoId, ?int $alumnoId): array
    {
        return array_values(DB::select(
            'SELECT id, definicion, tipo, orden, competencia_id, alumno_id, por_defecto
               FROM desempenos
              WHERE asignatura_id = ? AND periodo_id = ? AND alumno_id <=> ?
                AND deleted_at IS NULL
              ORDER BY orden, id',
            [$asignaturaId, $periodoId, $alumnoId]
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
            'SELECT id, definicion, tipo, orden, materia_id, grado_id, periodo_id, competencia_id
               FROM desempenos_por_defecto WHERE id = ?',
            [$id]
        );

        return $this->catalogoConSusNombres([$fila])[0];
    }

    /** @return array<string, mixed> */
    private function filaDeLaAsignatura(int $id): array
    {
        $fila = DB::selectOne(
            'SELECT d.id, d.definicion, d.tipo, d.orden, d.competencia_id, d.alumno_id,
                    d.por_defecto, d.asignatura_id, d.periodo_id, c.definicion AS competencia
               FROM desempenos d
               LEFT JOIN competencias c ON c.id = d.competencia_id AND c.deleted_at IS NULL
              WHERE d.id = ?',
            [$id]
        );

        return [
            'id' => (int) $fila->id,
            'asignatura_id' => (int) $fila->asignatura_id,
            'periodo_id' => (int) $fila->periodo_id,
            'definicion' => $fila->definicion,
            'tipo' => $fila->tipo,
            'orden' => (int) $fila->orden,
            'competencia_id' => $fila->competencia_id === null ? null : (int) $fila->competencia_id,
            'competencia' => $fila->competencia,
            'alumno_id' => $fila->alumno_id === null ? null : (int) $fila->alumno_id,
            'por_defecto' => (int) $fila->por_defecto,
        ];
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
                'competencia_id' => $fila->competencia_id === null ? null : (int) $fila->competencia_id,
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

    private function siguienteOrdenDeLaAsignatura(int $asignaturaId, int $periodoId, ?int $alumnoId): int
    {
        $fila = DB::selectOne(
            'SELECT COALESCE(MAX(orden), -1) + 1 AS siguiente FROM desempenos
              WHERE asignatura_id = ? AND periodo_id = ? AND alumno_id <=> ?
                AND deleted_at IS NULL',
            [$asignaturaId, $periodoId, $alumnoId]
        );

        return (int) ($fila->siguiente ?? 0);
    }

    /**
     * La fila de catálogo, comprobando que es **del año del token**. Sin eso,
     * cualquiera con el permiso editaría el plan de área de un año cerrado desde el
     * token de éste.
     */
    private function filaDeCatalogoDelAnio($id): object
    {
        if (! $this->esIdentificador($id)) {
            abort(404, 'Ese desempeño del plan de área no existe.');
        }

        $fila = DB::selectOne(
            'SELECT id, definicion, tipo, orden, materia_id, grado_id, periodo_id, competencia_id
               FROM desempenos_por_defecto
              WHERE id = ? AND year_id = ? AND deleted_at IS NULL',
            [(int) $id, (int) $this->user->year_id]
        );

        if ($fila === null) {
            abort(404, 'Ese desempeño del plan de área no existe.');
        }

        return $fila;
    }

    private function desempenoQueExiste($id): object
    {
        if (! $this->esIdentificador($id)) {
            abort(404, 'Ese desempeño no existe.');
        }

        $fila = DB::selectOne(
            'SELECT id, definicion, tipo, orden, competencia_id, alumno_id, por_defecto,
                    asignatura_id, periodo_id
               FROM desempenos WHERE id = ? AND deleted_at IS NULL',
            [(int) $id]
        );

        if ($fila === null) {
            abort(404, 'Ese desempeño no existe.');
        }

        return $fila;
    }

    private function asignaturaQueExiste(int $id): void
    {
        $existe = DB::table('asignaturas')->where('id', $id)->whereNull('deleted_at')->exists();

        if (! $existe) {
            abort(422, '`asignatura_id` apunta a algo que no existe.');
        }
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

    private function alumnoDelColegio(int $id): void
    {
        if (! DB::table('alumnos')->where('id', $id)->whereNull('deleted_at')->exists()) {
            abort(422, '`alumno_id` apunta a alguien que no existe.');
        }
    }

    /**
     * El periodo, **y que sea del año del token**. Un periodo de otro año en el
     * catálogo es una fila que no se sembrará nunca y que nadie entendería.
     */
    private function periodoDelAnio(int $id, int $yearId): void
    {
        $existe = DB::table('periodos')->where('id', $id)->where('year_id', $yearId)
            ->whereNull('deleted_at')->exists();

        if (! $existe) {
            abort(422, '`periodo_id` no es un periodo de este año.');
        }
    }

    private function competenciaDelAnio(int $id, int $yearId): void
    {
        $existe = DB::table('competencias')->where('id', $id)->where('year_id', $yearId)
            ->whereNull('deleted_at')->exists();

        if (! $existe) {
            abort(422, '`competencia_id` no es una competencia de este año.');
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

    // ── Los ayudantes de la rejilla ──────────────────────────────────────────

    /**
     * El grupo, el año y si el periodo está abierto — **de una asignatura**.
     *
     * El año sale del **grupo de la asignatura** y no de `$this->user->year_id`, y
     * la diferencia importa: la escala de valoración es **por año**, así que pedir
     * la rejilla de una asignatura de 2024 con un token de 2026 tiene que premarcar
     * con las bandas de **2024**. Con el año del token saldría premarcada con una
     * escala que ese boletín nunca usó, en 200 y sin ningún error.
     *
     * Y por eso el periodo se comprueba contra **ese** año y no contra el del token.
     */
    private function contextoDeLaRejilla(int $asignaturaId, int $periodoId): object
    {
        $asignatura = DB::selectOne(
            'SELECT a.id, a.grupo_id, g.year_id
               FROM asignaturas a
               INNER JOIN grupos g ON g.id = a.grupo_id AND g.deleted_at IS NULL
              WHERE a.id = ? AND a.deleted_at IS NULL',
            [$asignaturaId]
        );

        if ($asignatura === null) {
            abort(422, '`asignatura_id` apunta a algo que no existe.');
        }

        $periodo = DB::selectOne(
            'SELECT id, year_id, profes_pueden_editar_notas FROM periodos
              WHERE id = ? AND deleted_at IS NULL',
            [$periodoId]
        );

        if ($periodo === null || (int) $periodo->year_id !== (int) $asignatura->year_id) {
            abort(422, '`periodo_id` no es un periodo del año de esa asignatura.');
        }

        return (object) [
            'grupo_id' => (int) $asignatura->grupo_id,
            'year_id' => (int) $asignatura->year_id,
            'periodo_abierto' => (int) $periodo->profes_pueden_editar_notas === 1,
        ];
    }

    /**
     * Los alumnos de la rejilla, **con su alcance ya resuelto**, en una consulta.
     *
     * ## La población es la del boletín, y eso es una decisión
     *
     * `MATR`, `ASIS` y `PREM`, que es lo que mira `Grupo::alumnos` — o sea **los
     * mismos que salen impresos**. Una rejilla con más alumnos que el boletín pide
     * trabajo que no se imprime; con menos, deja renglones vacíos en el papel.
     *
     * > **No se reutiliza `BoletinIndependiente::delGrupo()`**, y no es por gusto:
     * > filtra `estado IN ("MATR","ASIS")` y **se deja fuera `PREM`**. Son dos
     * > poblaciones distintas del mismo grupo, y aquí hace falta la del boletín.
     * > Queda anotado porque las dos parecen la misma consulta.
     *
     * ## El periodo va bindeado, y aquí eso SÍ es correcto
     *
     * `BoletinIndependiente::alcanceCorrelacionado()` existe porque hay consultas
     * que abarcan varios periodos y un valor bindeado una sola vez resolvería el
     * resto con el alcance del equivocado —lo cazó `AlcanceCorrelacionadoPorPeriodoTest`—.
     * **La rejilla es de un periodo y sólo uno**, así que la constante `ALCANCE` con
     * el `LEFT JOIN` de un periodo es exacta. Se dice porque la forma se parece a la
     * que allí estaba mal.
     *
     * @return array<int, object> alumno_id => {alumno_id, nombres, apellidos, alcance}
     */
    private function alumnosDeLaRejilla(int $grupoId, int $periodoId): array
    {
        $filas = DB::select(
            'SELECT m.alumno_id, al.nombres, al.apellidos,
                    '.BoletinIndependiente::ALCANCE.' AS alcance
               FROM matriculas m
               INNER JOIN alumnos al ON al.id = m.alumno_id AND al.deleted_at IS NULL
               LEFT JOIN bol_ind_periodos bip
                      ON bip.alumno_id = m.alumno_id AND bip.periodo_id = ?
              WHERE m.grupo_id = ? AND m.deleted_at IS NULL
                AND m.estado IN ("MATR", "ASIS", "PREM")
              ORDER BY al.apellidos, al.nombres, m.alumno_id',
            [$periodoId, $grupoId]
        );

        $alumnos = [];

        foreach ($filas as $fila) {
            // Indexado por alumno, que además **colapsa las matrículas repetidas**:
            // `matriculas` no tiene clave única sobre (alumno, grupo) y nada impide
            // dos filas vivas del mismo alumno — es la §9.5 del boletín
            // independiente. Un alumno dos veces en la rejilla sería una fila
            // duplicada que el docente rellenaría dos veces.
            $alumnos[(int) $fila->alumno_id] = (object) [
                'alumno_id' => (int) $fila->alumno_id,
                'nombres' => $fila->nombres,
                'apellidos' => $fila->apellidos,
                'alcance' => $fila->alcance === null ? null : (int) $fila->alcance,
            ];
        }

        return $alumnos;
    }

    /**
     * La escala de valoración del año, **en su `orden`**.
     *
     * `ORDER BY orden, id` y no por `porc_inicial`: `orden` es la columna que el
     * colegio maneja en su pantalla de escalas, y es la que decide en qué orden se
     * pintan los botones. Desempatar por `id` deja el resultado determinista aunque
     * dos filas compartan `orden`.
     *
     * @return list<object>
     */
    private function escalaDelAnio(int $yearId): array
    {
        return array_values(DB::select(
            'SELECT id, desempenio, valoracion, porc_inicial, porc_final, orden, perdido
               FROM escalas_de_valoracion
              WHERE year_id = ? AND deleted_at IS NULL
              ORDER BY orden, id',
            [$yearId]
        ));
    }

    /**
     * Los desempeños que son columna de la rejilla: **los del curso, más los de los
     * alumnos que vayan por boletín independiente en ese periodo**.
     *
     * @param  array<int, object>  $alumnos
     * @return list<object>
     */
    private function desempenosDeLaRejilla(int $asignaturaId, int $periodoId, array $alumnos): array
    {
        $marcados = [];

        foreach ($alumnos as $alumno) {
            if ($alumno->alcance !== null) {
                $marcados[$alumno->alcance] = true;
            }
        }

        $duenos = array_keys($marcados);

        // `d.alumno_id IS NULL` es el reparto del curso (regla 5 de la §4). Los
        // marcados se añaden por su id: sin esta rama, un alumno con boletín
        // independiente abriría **la rejilla sin ninguna columna** y en 200.
        $filtro = 'd.alumno_id IS NULL';
        $valores = [$asignaturaId, $periodoId];

        if ($duenos !== []) {
            $filtro = '('.$filtro.' OR d.alumno_id IN ('
                .implode(', ', array_fill(0, count($duenos), '?')).'))';
            $valores = array_merge($valores, $duenos);
        }

        return array_values(DB::select(
            'SELECT d.id, d.definicion, d.tipo, d.orden, d.competencia_id, d.alumno_id,
                    d.por_defecto, c.definicion AS competencia
               FROM desempenos d
               LEFT JOIN competencias c ON c.id = d.competencia_id AND c.deleted_at IS NULL
              WHERE d.asignatura_id = ? AND d.periodo_id = ? AND '.$filtro.'
                AND d.deleted_at IS NULL
              ORDER BY d.alumno_id IS NOT NULL, d.orden, d.id',
            $valores
        ));
    }

    /**
     * La definitiva de cada alumno en esa asignatura y ese periodo. **Una consulta
     * por asignatura y periodo**, no una por alumno.
     *
     * `ORDER BY nf.id` y el último gana, que es el mismo criterio que
     * `Grupo::notasFinales` —`order by nf.id desc` y quedarse con la primera—.
     * **Y no es teórico**: medido el 13 sep 2026, `simonbolivar` tiene **un**
     * `(alumno, asignatura, periodo)` con dos filas en `notas_finales`. Sin un
     * desempate escrito, la premarcada de ese alumno dependería del orden que le
     * diera el motor ese día.
     *
     * @return array<int, float>
     */
    private function definitivasDe(int $asignaturaId, int $periodoId): array
    {
        $filas = DB::select(
            'SELECT nf.alumno_id, nf.nota FROM notas_finales nf
              WHERE nf.asignatura_id = ? AND nf.periodo_id = ?
              ORDER BY nf.id',
            [$asignaturaId, $periodoId]
        );

        $definitivas = [];

        foreach ($filas as $fila) {
            if ($fila->alumno_id === null) {
                continue;
            }

            // `(float)` y no `(int)`: la columna es `DECIMAL(7,4)` desde
            // `2026_08_30_200000` y llega como cadena. Redondearla aquí volvería a
            // meter por la puerta de atrás el mismo defecto que esa migración quitó.
            $definitivas[(int) $fila->alumno_id] = (float) $fila->nota;
        }

        return $definitivas;
    }

    /**
     * Las celdas **que ya están guardadas**, indexadas por `alumno:desempeno`.
     *
     * ## `desempeno_id IS NOT NULL`, y ésta es la línea que separa dos pantallas
     *
     * `frases_asignatura` tiene 12.294 filas en `simonbolivar` y **ninguna** salió
     * de una rejilla: son las frases que el docente escribe a mano en la pantalla de
     * siempre. Sin este filtro, la rejilla las leería como celdas, las repintaría y
     * el `PUT` **las borraría** con un `escala_id: null` que el front manda sin
     * saberlo.
     *
     * ## `alumno_id IN (…)` delante, y **dos** cláusulas sostienen el índice
     *
     * `WHERE asignatura_id = ? AND periodo_id = ?` a secas **recorre la tabla
     * entera**: el índice que había hasta hoy es
     * `frases_asignatura_alumno_asig_periodo_index (alumno_id, asignatura_id,
     * periodo_id)`, de `2026_08_20_100000`, y a esa consulta le falta su columna de
     * la izquierda. Medido con `EXPLAIN` el 13 sep 2026 sobre `simonbolivar`:
     * **`type = ALL`, `possible_keys = NULL`, 13.218 filas** — en cada apertura de
     * la rejilla.
     *
     * Lo salvan dos cláusulas distintas, **cada una por su índice**, y las dos están
     * escritas aquí a propósito:
     *
     *   - `alumno_id IN (…)` —los alumnos del grupo, que la rejilla ya tiene que
     *     traer— entra por la primera columna de aquel compuesto;
     *   - `desempeno_id IS NOT NULL` entra por `frases_asignatura_desempeno_index`,
     *     que estrena la migración de esta fase.
     *
     * **Y hay que decir que son dos, porque la primera versión de esto decía que era
     * una y su test pasaba igual quitándola.** Va comprobado en
     * `test_la_consulta_de_la_rejilla_no_recorre_la_tabla`, que mira `possible_keys`
     * **y `type`**, y que necesitó las dos: con `possible_keys` a secas daba verde
     * quitando el acotado por alumno, y con `type !== 'ALL'` a secas daba verde
     * quitando las dos cláusulas —porque con el `ORDER BY fa.id` delante el recorrido
     * completo entra **por la PRIMARY** y se llama `index`, no `ALL`—.
     *
     * @param  list<int>  $alumnoIds
     * @return array<string, object>
     */
    private function celdasGuardadas(int $asignaturaId, int $periodoId, array $alumnoIds): array
    {
        if ($alumnoIds === []) {
            return [];
        }

        $filas = DB::select(
            'SELECT fa.id, fa.alumno_id, fa.desempeno_id, fa.escala_id, fa.nivel, fa.frase
               FROM frases_asignatura fa
              WHERE fa.alumno_id IN ('.implode(', ', array_fill(0, count($alumnoIds), '?')).')
                AND fa.asignatura_id = ? AND fa.periodo_id = ?
                AND fa.desempeno_id IS NOT NULL
                AND fa.deleted_at IS NULL
              ORDER BY fa.id',
            array_merge($alumnoIds, [$asignaturaId, $periodoId])
        );

        $celdas = [];

        foreach ($filas as $fila) {
            // Nada impide dos filas vivas para la misma celda —esta tabla no tiene
            // ninguna clave única—, así que gana **la última escrita** y se dice.
            $celdas[((int) $fila->alumno_id).':'.((int) $fila->desempeno_id)] = $fila;
        }

        return $celdas;
    }

    /**
     * En qué banda cae una nota. **La forma de `Unidad::deAsignaturaCalculada`
     * —`porc_inicial <= nota <= porc_final`— y no el método**, que cruza por unidad.
     *
     * Recorre la escala **ya ordenada por `orden`**, así que con bandas solapadas
     * gana la primera y el resultado es determinista. Un `LEFT JOIN` en SQL
     * duplicaría la fila del alumno en ese caso, que es un fallo que se ve como un
     * alumno repetido y no como una escala mal montada.
     *
     * `null` es *«ninguna banda la recoge»*, y eso es `sin_banda`.
     *
     * @param  list<object>  $escala
     */
    private function bandaDeLaNota(float $nota, array $escala): ?object
    {
        foreach ($escala as $banda) {
            if ((float) $banda->porc_inicial <= $nota && $nota <= (float) $banda->porc_final) {
                return $banda;
            }
        }

        return null;
    }

    /**
     * Las celdas del cuerpo del `PUT`, comprobadas de forma **antes** de mirar si
     * existen.
     *
     * > **`escala_id` ausente NO es `escala_id: null`, y por eso se exige.** Con un
     * > `??` a `null`, un front que se dejara el campo **borraría la rejilla
     * > entera** — y con un 200 delante. Aquí lo ambiguo borra, así que lo ambiguo
     * > se rechaza: el que quiere vaciar una celda lo escribe.
     *
     * @return list<array{alumno_id: int, desempeno_id: int, escala_id: ?int}>
     */
    private function celdasDelCuerpo(): array
    {
        $celdas = Request::input('celdas');

        if (! is_array($celdas) || $celdas === []) {
            abort(422, '`celdas` tiene que ser una lista de casillas '
                .'`{alumno_id, desempeno_id, escala_id}`, con `escala_id` a `null` para vaciarla.');
        }

        $salida = [];
        $vistas = [];

        foreach ($celdas as $celda) {
            if (! is_array($celda)) {
                abort(422, '`celdas` trae algo que no es una casilla.');
            }

            $alumnoId = $this->comoId($celda['alumno_id'] ?? null, 'celdas.alumno_id');
            $desempenoId = $this->comoId($celda['desempeno_id'] ?? null, 'celdas.desempeno_id');

            if ($alumnoId === null || $desempenoId === null) {
                abort(422, 'Cada casilla tiene que traer `alumno_id` y `desempeno_id`.');
            }

            if (! array_key_exists('escala_id', $celda)) {
                abort(422, 'Cada casilla tiene que traer `escala_id`: el nivel, o `null` para vaciarla. '
                    .'Omitirlo borraría la casilla sin que nadie lo hubiera pedido.');
            }

            $escalaId = $this->comoId($celda['escala_id'], 'celdas.escala_id');

            $clave = $alumnoId.':'.$desempenoId;

            if (isset($vistas[$clave])) {
                abort(422, "La casilla del alumno {$alumnoId} y el desempeño {$desempenoId} viene dos veces: "
                    .'la última ganaría en silencio, y eso no lo ha pedido nadie.');
            }

            $vistas[$clave] = true;

            $salida[] = [
                'alumno_id' => $alumnoId,
                'desempeno_id' => $desempenoId,
                'escala_id' => $escalaId,
            ];
        }

        return $salida;
    }

    /**
     * @param  list<object>  $escala
     * @return list<array<string, mixed>>
     */
    private function escalaParaElFront(array $escala): array
    {
        $salida = [];

        foreach ($escala as $banda) {
            $salida[] = [
                'id' => (int) $banda->id,
                // **`nivel` y no `desempenio`.** La columna se llama `desempenio` y
                // en esta pantalla «desempeño» ya es la otra cosa —el texto del
                // catálogo—; dos significados con el mismo nombre en la misma
                // respuesta es una confusión garantizada en el front.
                'nivel' => $banda->desempenio,
                'valoracion' => $banda->valoracion,
                'porc_inicial' => (int) $banda->porc_inicial,
                'porc_final' => (int) $banda->porc_final,
                'orden' => (int) $banda->orden,
                'perdido' => (int) $banda->perdido,
            ];
        }

        return $salida;
    }

    /**
     * @param  list<object>  $desempenos
     * @return list<array<string, mixed>>
     */
    private function desempenosParaElFront(array $desempenos): array
    {
        $salida = [];

        foreach ($desempenos as $desempeno) {
            $salida[] = [
                'id' => (int) $desempeno->id,
                'definicion' => $desempeno->definicion,
                'tipo' => $desempeno->tipo,
                'orden' => (int) $desempeno->orden,
                'competencia_id' => $desempeno->competencia_id === null ? null : (int) $desempeno->competencia_id,
                'competencia' => $desempeno->competencia,
                // `null` es «es del curso»; con un id, esa columna es **sólo** de
                // ese alumno y el front no la pinta en las demás filas.
                'alumno_id' => $desempeno->alumno_id === null ? null : (int) $desempeno->alumno_id,
                'por_defecto' => (int) $desempeno->por_defecto,
            ];
        }

        return $salida;
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
