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
 * ## Lo que esta fase NO trae, y es una entrega propia con fecha
 *
 * **`competencias` y `desempenos_por_defecto` son tablas POR AÑO, y hoy nadie las
 * copia al crear el año siguiente.** El colegio que escriba su plan de área en
 * 2026 lo encontrará **vacío en enero de 2027** y lo reescribirá entero.
 *
 * No se arregla aquí por un motivo de fontanería y no de criterio: lo hace
 * `YearsController::postStore`, que es de la **Fase 1**, y esta rama sale de un
 * commit anterior a ella — tocarlo desde aquí fabrica un conflicto en vez de
 * arreglar nada. **Va sobre `main`, detrás de las dos.**
 *
 * Y es de los fallos caros: **no rompe nada hasta enero, y en enero no hay ninguna
 * línea en el log que lo explique**. Es exactamente la §1.bis del doc 28, donde las
 * subunidades por defecto se quedaron sin copiar **durante años** sin que nadie lo
 * viera. `CentinelaDeLasColumnasDelAnioNuevoTest` **no puede cazarlo**: vigila
 * columnas de `years`, no tablas hijas. El centinela que haría falta es el hermano
 * suyo para tablas, con su lista de excepciones y el motivo al lado de cada una.
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
