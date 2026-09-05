<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResuelveElUsuario;
use App\Services\Auditoria;
use App\Support\AlcanceDeLaPlantilla;
use App\Support\Autoriza;
use App\Support\Reloj;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;

/**
 * La plantilla de notas del colegio: `unidades_por_defecto` y
 * `subunidades_por_defecto`, que hasta hoy **se editaban a mano en phpMyAdmin**.
 *
 * Es la **Entrega 1** de
 * [28-competencias-e-indicadores.md](../../../docs/migracion/28-competencias-e-indicadores.md)
 * (§5.1) más el alcance de la **Entrega 7** (§5.7.a). El documento manda; si algo
 * de aquí discrepa de él, el que está mal es éste.
 *
 * ## Las cinco reglas de la §4, que gobiernan cada método de este fichero
 *
 * 1. **Ninguna nota apunta nunca a una fila de plantilla.** Se copia, siempre.
 *    Por eso `putSembrar` hace `INSERT` en `unidades` y no una referencia.
 * 2. **Nada se siembra en un periodo cerrado.**
 * 3. **La fórmula de la definitiva no cambia.** Aquí no se calcula ninguna.
 * 4. **Nada se siembra encima de lo que ya tiene notas.**
 * 5. **`unidades.alumno_id IS NULL` en todo lo que hable del reparto del curso.**
 *    El boletín independiente mete filas con dueño en estas mismas tablas, y una
 *    plantilla que las cuente se lleva por delante el reparto de treinta alumnos:
 *    ya pasó, medido — 51 estudiantes y una asignatura al 110 %.
 *
 * ## La plantilla SIEMBRA, NO MANDA
 *
 * Es el invariante que protege los boletines de los años pasados y por eso está
 * escrito aquí arriba: **ningún informe vuelve a leer estas tablas**. Cambiar una
 * fila de plantilla no mueve una sola nota ya puesta, y por eso el CRUD de este
 * fichero **no lleva auditoría y `putSembrar` sí**: lo que tiene consecuencia es
 * la copia, no el original.
 *
 * ## Por qué SQL y no Eloquent, y por qué nunca `SELECT *`
 *
 * Por lo mismo que el resto del repo. Y nombrar las columnas no es estilo: la
 * cabecera de `UnidadesController` ya avisa de que aquel `SELECT *` sobre
 * `unidades_por_defecto` repartiría sola cualquier columna nueva — y esta tanda
 * añade dos.
 */
class PlantillaNotasController extends Controller
{
    use ResuelveElUsuario;

    /**
     * `GET plantilla-notas` — la plantilla del año del token, unidades con sus
     * subunidades.
     *
     * Enseña **todas** las filas del año, no sólo las que le tocarían a alguien:
     * es la pantalla donde el colegio las escribe, así que una fila mal dirigida
     * —a un nivel que ya no existe, por ejemplo— tiene que verse para poder
     * arreglarse. Por eso lee `todasDelAnio` y no `unidadesPara`.
     *
     * **`repartos` es la mitad que hace útil esta pantalla.** La suma que tiene
     * que dar 100 **no es la de la tabla entera**: desde que una fila puede ir
     * dirigida a un nivel o a una materia, lo que se aplica junto es el grupo de
     * filas que comparte destino. Se agrupa por el par `(nivel, materia)` tal
     * como está escrito, y eso es exactamente el conjunto que se siembra junto —
     * la precedencia elige **una grada entera**, y dentro de las candidatas de una
     * asignatura cada grada es un solo par. Ver `AlcanceDeLaPlantilla`.
     *
     * @return array<string, mixed>
     */
    public function getIndex(): array
    {
        Autoriza::exigir(
            Autoriza::puedeEditarPlantillaNotas($this->user),
            self::SIN_PERMISO
        );

        $yearId = (int) $this->user->year_id;
        $unidades = AlcanceDeLaPlantilla::todasDelAnio($yearId);

        $nivelesPorId = $this->catalogo('niveles_educativos');
        $materiasPorId = $this->catalogo('materias');

        $salida = [];
        $repartos = [];

        foreach ($unidades as $unidad) {
            $nivelId = $unidad->nivel_educativo_id === null ? null : (int) $unidad->nivel_educativo_id;
            $materiaId = $unidad->materia_id === null ? null : (int) $unidad->materia_id;

            $salida[] = [
                'id' => (int) $unidad->id,
                'definicion' => $unidad->definicion,
                'porcentaje' => (int) $unidad->porcentaje,
                'obligatoria' => (int) $unidad->obligatoria,
                'orden' => $unidad->orden === null ? null : (int) $unidad->orden,
                'nivel_educativo_id' => $nivelId,
                'nivel_educativo' => $nivelId === null ? null : ($nivelesPorId[$nivelId] ?? null),
                'materia_id' => $materiaId,
                'materia' => $materiaId === null ? null : ($materiasPorId[$materiaId] ?? null),
                'grada' => AlcanceDeLaPlantilla::grada($nivelId, $materiaId),
                'subunidades' => $this->subunidadesDe((int) $unidad->id),
            ];

            $clave = $nivelId.'|'.$materiaId;
            $repartos[$clave] ??= [
                'nivel_educativo_id' => $nivelId,
                'materia_id' => $materiaId,
                'grada' => AlcanceDeLaPlantilla::grada($nivelId, $materiaId),
                'unidades' => 0,
                'suma_porcentajes' => 0,
            ];
            $repartos[$clave]['unidades']++;
            $repartos[$clave]['suma_porcentajes'] += (int) $unidad->porcentaje;
        }

        return [
            'year_id' => $yearId,
            'unidades' => $salida,
            'repartos' => array_values($repartos),
        ];
    }

    /**
     * `POST plantilla-notas/unidad` — crear una unidad de plantilla.
     *
     * **No bloquea si la suma no da 100, y devuelve la suma.** Bloquear cada fila
     * haría imposible llegar a 100: hay que pasar por estados intermedios, y una
     * pantalla que no deja guardar el 40 % porque todavía no existe el 60 % no se
     * puede usar. El aviso va donde duele —en `putSembrar`, §5.1.d—, no donde
     * estorba; aquí sólo se devuelve el número para que el front lo pinte en rojo.
     *
     * @return array<string, mixed>
     */
    public function postUnidad(): array
    {
        Autoriza::exigir(
            Autoriza::puedeEditarPlantillaNotas($this->user),
            self::SIN_PERMISO
        );

        $yearId = (int) $this->user->year_id;
        $nivelId = $this->idDeCatalogo('nivel_educativo_id', 'niveles_educativos', null);
        $materiaId = $this->idDeCatalogo('materia_id', 'materias', null);

        $id = (int) DB::table('unidades_por_defecto')->insertGetId([
            'definicion' => $this->texto('definicion', null),
            'porcentaje' => $this->porcentaje('porcentaje', 0),
            'year_id' => $yearId,
            'obligatoria' => $this->booleano('obligatoria', 0),
            'orden' => $this->orden($yearId),
            'nivel_educativo_id' => $nivelId,
            'materia_id' => $materiaId,
            'created_by' => (int) $this->user->user_id,
            'created_at' => Reloj::ahoraTexto(),
            'updated_at' => Reloj::ahoraTexto(),
        ]);

        return $this->unidadConSuReparto($id);
    }

    /**
     * `PUT plantilla-notas/unidad/{id}`.
     *
     * **Cada campo por defecto vale lo que ya hay guardado.** No es comodidad: es
     * la misma regla que la trampa (1) de §5.1.e — los clientes de esta casa
     * mandan el objeto entero unas veces y un trozo otras, y un campo ausente
     * tomado por «ponlo a null» borraría el porcentaje de una fila del colegio en
     * un guardado que sólo quería cambiar el texto.
     *
     * @return array<string, mixed>
     */
    public function putUnidad($id): array
    {
        Autoriza::exigir(
            Autoriza::puedeEditarPlantillaNotas($this->user),
            self::SIN_PERMISO
        );

        $unidad = $this->unidadDelAnio($id);

        DB::table('unidades_por_defecto')->where('id', $unidad->id)->update([
            'definicion' => $this->texto('definicion', $unidad->definicion),
            'porcentaje' => $this->porcentaje('porcentaje', (int) $unidad->porcentaje),
            'obligatoria' => $this->booleano('obligatoria', (int) $unidad->obligatoria),
            'orden' => Request::has('orden')
                ? $this->enteroNoNegativo(Request::input('orden'), 'orden')
                : $unidad->orden,
            'nivel_educativo_id' => $this->idDeCatalogo(
                'nivel_educativo_id', 'niveles_educativos',
                $unidad->nivel_educativo_id === null ? null : (int) $unidad->nivel_educativo_id
            ),
            'materia_id' => $this->idDeCatalogo(
                'materia_id', 'materias',
                $unidad->materia_id === null ? null : (int) $unidad->materia_id
            ),
            'updated_by' => (int) $this->user->user_id,
            'updated_at' => Reloj::ahoraTexto(),
        ]);

        return $this->unidadConSuReparto((int) $unidad->id);
    }

    /**
     * `DELETE plantilla-notas/unidad/{id}` — a la papelera, con sus subunidades.
     *
     * **Las subunidades se borran a mano y no por la clave foránea.** La FK de
     * `subunidades_por_defecto` es `ON DELETE CASCADE`, que sólo actúa en un
     * borrado **físico**; aquí es un borrado lógico, así que sin este `UPDATE` las
     * subunidades quedarían vivas colgando de una unidad en la papelera —
     * invisibles en la pantalla y **cazadas por el sembrador**, que lee la
     * plantilla por `unidad_defec_id` y no vuelve a preguntar por el padre.
     *
     * @return array<string, mixed>
     */
    public function deleteUnidad($id): array
    {
        Autoriza::exigir(
            Autoriza::puedeEditarPlantillaNotas($this->user),
            self::SIN_PERMISO
        );

        $unidad = $this->unidadDelAnio($id);
        $ahora = Reloj::ahoraTexto();

        $subunidades = DB::table('subunidades_por_defecto')
            ->where('unidad_defec_id', $unidad->id)
            ->whereNull('deleted_at')
            ->update([
                'deleted_at' => $ahora,
                'deleted_by' => (int) $this->user->user_id,
                'updated_at' => $ahora,
            ]);

        DB::table('unidades_por_defecto')->where('id', $unidad->id)->update([
            'deleted_at' => $ahora,
            'deleted_by' => (int) $this->user->user_id,
            'updated_at' => $ahora,
        ]);

        return [
            'id' => (int) $unidad->id,
            'subunidades_borradas' => (int) $subunidades,
        ];
    }

    /**
     * `POST plantilla-notas/subunidad`.
     *
     * @return array<string, mixed>
     */
    public function postSubunidad(): array
    {
        Autoriza::exigir(
            Autoriza::puedeEditarPlantillaNotas($this->user),
            self::SIN_PERMISO
        );

        $unidad = $this->unidadDelAnio(Request::input('unidad_id'), 422);

        $id = (int) DB::table('subunidades_por_defecto')->insertGetId([
            'definicion' => $this->texto('definicion', null),
            'porcentaje' => $this->porcentaje('porcentaje', 0),
            'unidad_defec_id' => (int) $unidad->id,
            'nota_default' => Request::has('nota_default') && Request::input('nota_default') !== null
                ? $this->enteroNoNegativo(Request::input('nota_default'), 'nota_default')
                : null,
            'obligatoria' => $this->booleano('obligatoria', 0),
            'orden' => $this->ordenDeSubunidad((int) $unidad->id),
            'created_by' => (int) $this->user->user_id,
            'created_at' => Reloj::ahoraTexto(),
            'updated_at' => Reloj::ahoraTexto(),
        ]);

        return $this->unidadConSuReparto((int) $unidad->id) + ['subunidad_id' => $id];
    }

    /**
     * `PUT plantilla-notas/subunidad/{id}`.
     *
     * @return array<string, mixed>
     */
    public function putSubunidad($id): array
    {
        Autoriza::exigir(
            Autoriza::puedeEditarPlantillaNotas($this->user),
            self::SIN_PERMISO
        );

        $subunidad = $this->subunidadDelAnio($id);

        DB::table('subunidades_por_defecto')->where('id', $subunidad->id)->update([
            'definicion' => $this->texto('definicion', $subunidad->definicion),
            'porcentaje' => $this->porcentaje('porcentaje', (int) $subunidad->porcentaje),
            'nota_default' => Request::has('nota_default')
                ? (Request::input('nota_default') === null
                    ? null
                    : $this->enteroNoNegativo(Request::input('nota_default'), 'nota_default'))
                : $subunidad->nota_default,
            'obligatoria' => $this->booleano('obligatoria', (int) $subunidad->obligatoria),
            'orden' => Request::has('orden')
                ? $this->enteroNoNegativo(Request::input('orden'), 'orden')
                : $subunidad->orden,
            'updated_by' => (int) $this->user->user_id,
            'updated_at' => Reloj::ahoraTexto(),
        ]);

        return $this->unidadConSuReparto((int) $subunidad->unidad_defec_id)
            + ['subunidad_id' => (int) $subunidad->id];
    }

    /**
     * `DELETE plantilla-notas/subunidad/{id}` — a la papelera.
     *
     * @return array<string, mixed>
     */
    public function deleteSubunidad($id): array
    {
        Autoriza::exigir(
            Autoriza::puedeEditarPlantillaNotas($this->user),
            self::SIN_PERMISO
        );

        $subunidad = $this->subunidadDelAnio($id);
        $ahora = Reloj::ahoraTexto();

        DB::table('subunidades_por_defecto')->where('id', $subunidad->id)->update([
            'deleted_at' => $ahora,
            'deleted_by' => (int) $this->user->user_id,
            'updated_at' => $ahora,
        ]);

        return ['id' => (int) $subunidad->id];
    }

    /**
     * `PUT plantilla-notas/orden` — reordena unidades y subunidades **en una
     * llamada**.
     *
     * Una sola llamada y no una por fila porque arrastrar tres unidades de sitio
     * son tres peticiones que pueden llegar desordenadas, y el resultado de una
     * reordenación a medias es un `orden` duplicado — que es justo lo que
     * `Unidad::arreglarOrden` existe para tapar en la otra tabla.
     *
     * Cuerpo, las dos claves opcionales y al menos una obligatoria:
     *
     *     { "unidades": [3, 1, 2],
     *       "subunidades": [ {"unidad_id": 3, "orden": [7, 5]} ] }
     *
     * **Las listas son el orden, no un mapa de posiciones**: la posición en la
     * lista es el `orden` que se escribe. Y son **completas por conjunto**: una
     * lista de unidades tiene que traer todas las del año, y la de una unidad
     * todas sus subunidades. Una lista parcial dejaría huecos y repetidos, y es
     * exactamente el estado del que se viene.
     *
     * @return array<string, mixed>
     */
    public function putOrden(): array
    {
        Autoriza::exigir(
            Autoriza::puedeEditarPlantillaNotas($this->user),
            self::SIN_PERMISO
        );

        $yearId = (int) $this->user->year_id;
        $unidades = Request::input('unidades');
        $subunidades = Request::input('subunidades');

        if ($unidades === null && $subunidades === null) {
            abort(422, 'Hace falta `unidades`, `subunidades` o las dos.');
        }

        $movidasUnidades = 0;
        $movidasSubunidades = 0;

        if ($unidades !== null) {
            $movidasUnidades = $this->reordenar(
                'unidades_por_defecto',
                $this->listaDeIds($unidades, 'unidades'),
                array_map(fn ($u) => (int) $u->id, AlcanceDeLaPlantilla::todasDelAnio($yearId)),
                'unidades'
            );
        }

        if ($subunidades !== null) {
            if (! is_array($subunidades)) {
                abort(422, '`subunidades` tiene que ser una lista de {unidad_id, orden}.');
            }

            foreach ($subunidades as $grupo) {
                if (! is_array($grupo) || ! array_key_exists('unidad_id', $grupo) || ! array_key_exists('orden', $grupo)) {
                    abort(422, 'Cada entrada de `subunidades` tiene que traer `unidad_id` y `orden`.');
                }

                $unidad = $this->unidadDelAnio($grupo['unidad_id'], 422);

                $movidasSubunidades += $this->reordenar(
                    'subunidades_por_defecto',
                    $this->listaDeIds($grupo['orden'], 'orden'),
                    array_map(
                        fn ($s) => (int) $s['id'],
                        $this->subunidadesDe((int) $unidad->id)
                    ),
                    'subunidades de la unidad '.$unidad->id
                );
            }
        }

        return [
            'unidades_reordenadas' => $movidasUnidades,
            'subunidades_reordenadas' => $movidasSubunidades,
        ];
    }

    /**
     * `PUT plantilla-notas/sembrar` — **el único botón peligroso de esta entrega**
     * (§5.1.c).
     *
     * Aplica la plantilla a las asignaturas del año que todavía no la tienen. Es
     * el que el colegio va a querer el día que cambie la plantilla a mitad de año,
     * y el que puede hacer daño, así que su contrato es todo restricciones:
     *
     * - **Sólo el año del token y sólo periodos abiertos** (regla 2).
     * - Por defecto **sólo asignatura+periodo con cero unidades de curso**
     *   (`alumno_id IS NULL`). Nunca toca una asignatura ya montada.
     * - Con `reemplazar: true` siembra además las que tengan unidades **pero cero
     *   notas puestas**. Una asignatura con **una sola nota no se toca jamás**, y
     *   sale en la respuesta con su motivo.
     * - Nunca toca filas con `alumno_id IS NOT NULL` (regla 5).
     *
     * ## La respuesta dice la POBLACIÓN, no `OK`
     *
     * Un «0 sembradas» tiene que poder distinguirse de «no revisé nada». Es la
     * regla de `tools/` aplicada a un endpoint, y aquí hay **seis** desenlaces
     * distintos, no uno.
     *
     * > **Tres claves no se llaman como en el documento, y es a propósito.** §5.1.c
     * > proponía `saltadas_por_independiente`, y esa clave **valdría cero siempre**:
     * > las filas con dueño no se cuentan al decidir si una asignatura está montada
     * > —eso es justo la regla 5— así que no hacen saltar nada. Un contador que no
     * > puede subir no dice si la regla corrió; el que está aquí, `independientes_respetadas`,
     * > **sube cuando había filas con dueño y se dejaron intactas**, que es lo que
     * > alguien querría comprobar. Y `saltadas_por_estructura` y `saltadas_sin_plantilla`
     * > salen de partir en dos lo que el documento dejaba junto: «ya estaba montada»
     * > y «no hay ninguna fila de plantilla que le toque» son desenlaces distintos, y
     * > el segundo es el que delata una plantilla mal dirigida.
     *
     * ## El 422 del porcentaje va aquí, y por grupo de alcance
     *
     * §5.1.d pide 422 si la suma de las unidades aplicables no es 100, salvo
     * `acepto_desviacion: true`. Con el alcance de la Entrega 7 esa suma **ya no es
     * una**: lo que se aplica junto es el grupo de filas que comparte destino, así
     * que se comprueban los grupos que de verdad se van a sembrar y el 422 los
     * nombra uno a uno. Comprobar la suma de la tabla entera daría 200 en cuanto un
     * colegio tuviera una plantilla de preescolar y otra general, **las dos
     * correctas**.
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
        $reemplazar = $this->booleano('reemplazar', 0) === 1;
        $aceptoDesviacion = $this->booleano('acepto_desviacion', 0) === 1;

        $periodos = DB::select(
            'SELECT id, numero, profes_pueden_editar_notas
               FROM periodos
              WHERE year_id = ? AND deleted_at IS NULL
              ORDER BY numero, id',
            [$yearId]
        );

        $asignaturas = DB::select(
            'SELECT a.id, a.materia_id, g2.nivel_educativo_id
               FROM asignaturas a
               JOIN grupos g  ON g.id = a.grupo_id AND g.deleted_at IS NULL
               JOIN grados g2 ON g2.id = g.grado_id AND g2.deleted_at IS NULL
              WHERE g.year_id = ? AND a.deleted_at IS NULL
              ORDER BY a.id',
            [$yearId]
        );

        $conteo = [
            'revisadas' => 0,
            'sembradas' => 0,
            'saltadas_por_estructura' => 0,
            'saltadas_por_notas' => 0,
            'saltadas_por_periodo_cerrado' => 0,
            'saltadas_sin_plantilla' => 0,
            'independientes_respetadas' => 0,
        ];

        // Las combinaciones de alcance que de verdad se van a aplicar, para
        // comprobar sus sumas ANTES de escribir nada. Se calcula sobre las
        // asignaturas reales y no sobre la tabla: una plantilla dirigida a un nivel
        // que ningún grupo tiene no le estropea el despliegue a nadie.
        $plantillaPorClave = [];
        $candidatas = [];

        foreach ($periodos as $periodo) {
            foreach ($asignaturas as $asignatura) {
                $conteo['revisadas']++;

                if ((int) $periodo->profes_pueden_editar_notas === 0) {
                    $conteo['saltadas_por_periodo_cerrado']++;

                    continue;
                }

                $nivelId = $asignatura->nivel_educativo_id === null ? null : (int) $asignatura->nivel_educativo_id;
                $materiaId = $asignatura->materia_id === null ? null : (int) $asignatura->materia_id;
                $clave = $nivelId.'|'.$materiaId;

                $plantillaPorClave[$clave] ??= AlcanceDeLaPlantilla::unidadesPara($yearId, $nivelId, $materiaId);

                if ($plantillaPorClave[$clave] === []) {
                    $conteo['saltadas_sin_plantilla']++;

                    continue;
                }

                $estado = $this->estadoDeLaRejilla((int) $asignatura->id, (int) $periodo->id);

                if ($estado->con_dueno > 0) {
                    $conteo['independientes_respetadas'] += $estado->con_dueno;
                }

                if ($estado->de_curso > 0) {
                    if (! $reemplazar) {
                        $conteo['saltadas_por_estructura']++;

                        continue;
                    }

                    if ($estado->notas > 0) {
                        $conteo['saltadas_por_notas']++;

                        continue;
                    }
                }

                $candidatas[] = [
                    'asignatura_id' => (int) $asignatura->id,
                    'periodo_id' => (int) $periodo->id,
                    'clave' => $clave,
                    'tenia_estructura' => $estado->de_curso > 0,
                ];
            }
        }

        $this->exigirRepartosCompletos($candidatas, $plantillaPorClave, $aceptoDesviacion);

        foreach ($candidatas as $candidata) {
            if ($candidata['tenia_estructura']) {
                $this->vaciarRejillaDelCurso($candidata['asignatura_id'], $candidata['periodo_id']);
            }

            $this->sembrarEn(
                $candidata['asignatura_id'],
                $candidata['periodo_id'],
                $plantillaPorClave[$candidata['clave']]
            );

            $conteo['sembradas']++;
        }

        /*
         * **Una línea de auditoría y no una por asignatura.** Son hasta
         * asignaturas × periodos escrituras en una petición, y mil líneas de
         * bitácora no se leen: lo que alguien va a querer saber dentro de un año es
         * quién apretó el botón, cuándo y cuánto movió. La línea se escribe
         * **siempre**, también con `sembradas = 0`, porque «alguien lo apretó y no
         * pasó nada» es exactamente el suceso que se va a investigar.
         */
        Auditoria::registrar()
            ->crear('unidad')
            ->en(year: $yearId)
            ->a($conteo)
            ->resumen(sprintf(
                'Sembró la plantilla del año: %d de %d asignatura+periodo revisadas%s',
                $conteo['sembradas'],
                $conteo['revisadas'],
                $reemplazar ? ', con reemplazar' : ''
            ))
            ->guardar();

        return $conteo;
    }

    private const SIN_PERMISO = 'No tiene permiso para editar la plantilla de notas del colegio.';

    /**
     * Las filas de plantilla de cada grupo de alcance que se va a sembrar tienen
     * que sumar 100. Si no, 422 con los grupos nombrados — salvo
     * `acepto_desviacion`.
     *
     * @param  list<array<string, mixed>>  $candidatas
     * @param  array<string, list<object>>  $plantillaPorClave
     */
    private function exigirRepartosCompletos(array $candidatas, array $plantillaPorClave, bool $acepto): void
    {
        if ($acepto || $candidatas === []) {
            return;
        }

        $desviados = [];

        foreach (array_unique(array_column($candidatas, 'clave')) as $clave) {
            $suma = array_sum(array_map(fn ($u) => (int) $u->porcentaje, $plantillaPorClave[$clave]));

            if ($suma === 100) {
                continue;
            }

            [$nivelId, $materiaId] = array_map(
                fn ($t) => $t === '' ? null : (int) $t,
                explode('|', $clave)
            );

            $desviados[] = [
                'nivel_educativo_id' => $nivelId,
                'materia_id' => $materiaId,
                'unidades' => count($plantillaPorClave[$clave]),
                'suma_porcentajes' => $suma,
            ];
        }

        if ($desviados === []) {
            return;
        }

        abort(response()->json([
            'message' => 'Hay repartos de la plantilla que no suman 100. Mande `acepto_desviacion` '
                .'para sembrarlos igual.',
            'repartos_desviados' => $desviados,
        ], 422));
    }

    /**
     * Cuántas unidades de curso, cuántas con dueño y cuántas notas tiene una
     * asignatura+periodo. Las tres en una consulta porque se preguntan siempre a la
     * vez y son asignaturas × periodos.
     */
    private function estadoDeLaRejilla(int $asignaturaId, int $periodoId): object
    {
        $fila = DB::select(
            'SELECT
                SUM(u.alumno_id IS NULL)     AS de_curso,
                SUM(u.alumno_id IS NOT NULL) AS con_dueno,
                (SELECT COUNT(*)
                   FROM notas n
                   JOIN subunidades s ON s.id = n.subunidad_id AND s.deleted_at IS NULL
                   JOIN unidades u2   ON u2.id = s.unidad_id   AND u2.deleted_at IS NULL
                  WHERE u2.asignatura_id = ? AND u2.periodo_id = ? AND u2.alumno_id IS NULL
                    AND n.deleted_at IS NULL) AS notas
               FROM unidades u
              WHERE u.asignatura_id = ? AND u.periodo_id = ? AND u.deleted_at IS NULL',
            [$asignaturaId, $periodoId, $asignaturaId, $periodoId]
        );

        return (object) [
            'de_curso' => (int) ($fila[0]->de_curso ?? 0),
            'con_dueno' => (int) ($fila[0]->con_dueno ?? 0),
            'notas' => (int) ($fila[0]->notas ?? 0),
        ];
    }

    /**
     * Manda a la papelera la rejilla **del curso** de una asignatura+periodo, con
     * sus subunidades. Sólo se llama cuando ya se ha comprobado que no tiene
     * ninguna nota.
     *
     * **`alumno_id IS NULL` en las dos consultas**: es la regla 5, y es lo que
     * impide que reemplazar la plantilla del curso se lleve por delante el reparto
     * de un estudiante con boletín independiente.
     */
    private function vaciarRejillaDelCurso(int $asignaturaId, int $periodoId): void
    {
        $ahora = Reloj::ahoraTexto();
        $usuario = (int) $this->user->user_id;

        DB::update(
            'UPDATE subunidades s
               JOIN unidades u ON u.id = s.unidad_id
                SET s.deleted_at = ?, s.deleted_by = ?, s.updated_at = ?
              WHERE u.asignatura_id = ? AND u.periodo_id = ? AND u.alumno_id IS NULL
                AND u.deleted_at IS NULL AND s.deleted_at IS NULL',
            [$ahora, $usuario, $ahora, $asignaturaId, $periodoId]
        );

        DB::update(
            'UPDATE unidades
                SET deleted_at = ?, deleted_by = ?, updated_at = ?
              WHERE asignatura_id = ? AND periodo_id = ? AND alumno_id IS NULL AND deleted_at IS NULL',
            [$ahora, $usuario, $ahora, $asignaturaId, $periodoId]
        );
    }

    /**
     * Copia la plantilla a una asignatura+periodo. **`por_defecto` a 1**, que es lo
     * que marca la fila como del colegio y lo que el candado de la decisión 5 leerá
     * el día que entre — la misma marca que pone el sembrador viejo
     * (`UnidadesController:158`), y por el mismo motivo.
     *
     * @param  list<object>  $plantilla
     */
    private function sembrarEn(int $asignaturaId, int $periodoId, array $plantilla): void
    {
        $ahora = Reloj::ahoraTexto();
        $usuario = (int) $this->user->user_id;

        foreach ($plantilla as $unidad) {
            DB::insert(
                'INSERT INTO unidades(definicion, porcentaje, periodo_id, asignatura_id, obligatoria,
                                      orden, por_defecto, created_by, created_at)
                 VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $unidad->definicion, $unidad->porcentaje, $periodoId, $asignaturaId,
                    $unidad->obligatoria, $unidad->orden, true, $usuario, $ahora,
                ]
            );

            $unidadId = (int) DB::getPdo()->lastInsertId();

            foreach ($this->subunidadesDe((int) $unidad->id) as $subunidad) {
                DB::insert(
                    'INSERT INTO subunidades(definicion, porcentaje, unidad_id, nota_default, obligatoria,
                                             orden, por_defecto, created_by, created_at)
                     VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [
                        $subunidad['definicion'], $subunidad['porcentaje'], $unidadId,
                        $subunidad['nota_default'], $subunidad['obligatoria'], $subunidad['orden'],
                        true, $usuario, $ahora,
                    ]
                );
            }
        }
    }

    /**
     * Las subunidades vivas de una unidad de plantilla, con las columnas nombradas.
     *
     * @return list<array<string, mixed>>
     */
    private function subunidadesDe(int $unidadId): array
    {
        $filas = DB::select(
            'SELECT id, definicion, porcentaje, nota_default, obligatoria, orden
               FROM subunidades_por_defecto
              WHERE unidad_defec_id = ? AND deleted_at IS NULL
              ORDER BY orden, id',
            [$unidadId]
        );

        return array_values(array_map(fn ($s) => [
            'id' => (int) $s->id,
            'definicion' => $s->definicion,
            'porcentaje' => (int) $s->porcentaje,
            'nota_default' => $s->nota_default === null ? null : (int) $s->nota_default,
            'obligatoria' => (int) $s->obligatoria,
            'orden' => $s->orden === null ? null : (int) $s->orden,
        ], $filas));
    }

    /**
     * La unidad de plantilla, comprobando que es **del año del token**.
     *
     * El año no es decoración: sin él, cualquiera con el permiso editaría la
     * plantilla de un año cerrado desde el token de éste, que es la regla 5 de §4
     * por su otra cara — *no se toca nada de un año cerrado, en ningún camino*.
     */
    private function unidadDelAnio($id, int $codigo = 404): object
    {
        if (! $this->esIdentificador($id)) {
            abort($codigo, 'Esa unidad de plantilla no existe.');
        }

        $filas = DB::select(
            'SELECT id, definicion, porcentaje, obligatoria, orden, nivel_educativo_id, materia_id
               FROM unidades_por_defecto
              WHERE id = ? AND year_id = ? AND deleted_at IS NULL',
            [(int) $id, (int) $this->user->year_id]
        );

        if ($filas === []) {
            abort($codigo, 'Esa unidad de plantilla no existe.');
        }

        return $filas[0];
    }

    private function subunidadDelAnio($id): object
    {
        if (! $this->esIdentificador($id)) {
            abort(404, 'Esa subunidad de plantilla no existe.');
        }

        $filas = DB::select(
            'SELECT s.id, s.definicion, s.porcentaje, s.nota_default, s.obligatoria, s.orden,
                    s.unidad_defec_id
               FROM subunidades_por_defecto s
               JOIN unidades_por_defecto u ON u.id = s.unidad_defec_id AND u.deleted_at IS NULL
              WHERE s.id = ? AND u.year_id = ? AND s.deleted_at IS NULL',
            [(int) $id, (int) $this->user->year_id]
        );

        if ($filas === []) {
            abort(404, 'Esa subunidad de plantilla no existe.');
        }

        return $filas[0];
    }

    /**
     * Una unidad con su reparto: lo que devuelven las cuatro escrituras, para que
     * el front pueda pintar la suma en rojo sin volver a pedir la plantilla entera.
     *
     * @return array<string, mixed>
     */
    private function unidadConSuReparto(int $unidadId): array
    {
        $unidad = DB::select(
            'SELECT id, definicion, porcentaje, obligatoria, orden, nivel_educativo_id, materia_id
               FROM unidades_por_defecto WHERE id = ?',
            [$unidadId]
        )[0];

        $nivelId = $unidad->nivel_educativo_id === null ? null : (int) $unidad->nivel_educativo_id;
        $materiaId = $unidad->materia_id === null ? null : (int) $unidad->materia_id;

        $hermanas = DB::select(
            'SELECT COUNT(*) AS unidades, COALESCE(SUM(porcentaje), 0) AS suma
               FROM unidades_por_defecto
              WHERE year_id = ? AND deleted_at IS NULL
                AND nivel_educativo_id <=> ? AND materia_id <=> ?',
            [(int) $this->user->year_id, $nivelId, $materiaId]
        )[0];

        return [
            'id' => (int) $unidad->id,
            'definicion' => $unidad->definicion,
            'porcentaje' => (int) $unidad->porcentaje,
            'obligatoria' => (int) $unidad->obligatoria,
            'orden' => $unidad->orden === null ? null : (int) $unidad->orden,
            'nivel_educativo_id' => $nivelId,
            'materia_id' => $materiaId,
            'grada' => AlcanceDeLaPlantilla::grada($nivelId, $materiaId),
            'subunidades' => $this->subunidadesDe((int) $unidad->id),
            'reparto' => [
                'nivel_educativo_id' => $nivelId,
                'materia_id' => $materiaId,
                'unidades' => (int) $hermanas->unidades,
                'suma_porcentajes' => (int) $hermanas->suma,
            ],
        ];
    }

    /**
     * Escribe `orden` según la posición en la lista. Exige que la lista traiga
     * **exactamente** el conjunto que hay: ni de más ni de menos.
     *
     * @param  list<int>  $pedidos
     * @param  list<int>  $existentes
     */
    private function reordenar(string $tabla, array $pedidos, array $existentes, string $que): int
    {
        $comprobar = $pedidos;
        sort($comprobar);
        $ordenados = $existentes;
        sort($ordenados);

        if ($comprobar !== $ordenados) {
            abort(422, "La lista de {$que} tiene que traer exactamente las que hay: "
                .'una lista parcial deja huecos y repetidos en el orden.');
        }

        // La posición en la lista ES el orden. Se escribe después de comprobar el
        // conjunto entero, no fila a fila: media reordenación aplicada es el estado
        // del que se viene.
        foreach ($pedidos as $posicion => $id) {
            DB::table($tabla)->where('id', $id)->update([
                'orden' => $posicion,
                'updated_by' => (int) $this->user->user_id,
                'updated_at' => Reloj::ahoraTexto(),
            ]);
        }

        return count($pedidos);
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

    /**
     * El nombre legible de un catálogo, por id. Los dos se llaman distinto:
     * `niveles_educativos.nombre` y **`materias.materia`**.
     *
     * @return array<int, string>
     */
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

    /**
     * Un id de catálogo que puede venir nulo. **El nulo es «sin restricción», y
     * hay que poder ponerlo**: es la vuelta atrás de dirigir una fila.
     *
     * Que la fila exista se comprueba aquí y no con una clave foránea, y el porqué
     * está en la migración: la FK sólo tenía salidas peores.
     */
    private function idDeCatalogo(string $campo, string $tabla, ?int $porDefecto): ?int
    {
        if (! Request::has($campo)) {
            return $porDefecto;
        }

        $valor = Request::input($campo);

        if ($valor === null || $valor === '') {
            return null;
        }

        if (! $this->esIdentificador($valor)) {
            abort(422, "`{$campo}` no es un identificador.");
        }

        $existe = DB::table($tabla)->where('id', (int) $valor)->whereNull('deleted_at')->exists();

        if (! $existe) {
            abort(422, "`{$campo}` apunta a algo que no existe.");
        }

        return (int) $valor;
    }

    private function texto(string $campo, ?string $porDefecto): string
    {
        $valor = Request::has($campo) ? Request::input($campo) : $porDefecto;

        if (! is_string($valor) || trim($valor) === '') {
            abort(422, "`{$campo}` no puede ir vacío.");
        }

        $valor = trim($valor);

        /*
         * `definicion` es `varchar(255)` en las dos tablas de plantilla y MySQL
         * aquí **no está en modo estricto**: un texto más largo entra recortado,
         * devuelve 200 y nadie se entera. Es exactamente lo que la §1.ter acaba de
         * medir en `frases_asignatura` —626 frases cortadas a mitad de palabra, ya
         * impresas en boletines— y esta fila se **copia** a todas las asignaturas
         * del colegio, así que un corte aquí se multiplica. 422 y no truncado.
         */
        if (mb_strlen($valor) > 255) {
            abort(422, "`{$campo}` no cabe: son ".mb_strlen($valor).' caracteres y el máximo es 255.');
        }

        return $valor;
    }

    private function porcentaje(string $campo, int $porDefecto): int
    {
        if (! Request::has($campo)) {
            return $porDefecto;
        }

        $valor = $this->enteroNoNegativo(Request::input($campo), $campo);

        if ($valor > 100) {
            abort(422, "`{$campo}` no puede pasar de 100.");
        }

        return $valor;
    }

    private function booleano(string $campo, int $porDefecto): int
    {
        if (! Request::has($campo)) {
            return $porDefecto;
        }

        $leido = filter_var(Request::input($campo), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        if ($leido === null) {
            abort(422, "`{$campo}` tiene que ser verdadero o falso.");
        }

        return $leido ? 1 : 0;
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

    /** El siguiente hueco de orden del año, para que una fila nueva caiga al final. */
    private function orden(int $yearId): int
    {
        if (Request::has('orden')) {
            return $this->enteroNoNegativo(Request::input('orden'), 'orden');
        }

        return (int) DB::table('unidades_por_defecto')
            ->where('year_id', $yearId)->whereNull('deleted_at')->count();
    }

    private function ordenDeSubunidad(int $unidadId): int
    {
        if (Request::has('orden')) {
            return $this->enteroNoNegativo(Request::input('orden'), 'orden');
        }

        return (int) DB::table('subunidades_por_defecto')
            ->where('unidad_defec_id', $unidadId)->whereNull('deleted_at')->count();
    }
}
