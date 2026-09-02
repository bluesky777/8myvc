<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResuelveElUsuario;
use App\Models\Rubrica;
use App\Support\NombreDelAlumno;
use App\Support\Reloj;
use App\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Rúbricas: la matriz criterios × niveles que **produce** la nota de una subunidad.
 *
 * El contrato es [24-rubricas.md](../../../docs/migracion/24-rubricas.md) y este
 * fichero lo sigue; si algo de aquí discrepa del documento, el que está mal es
 * éste. Las tres reglas de su §1 gobiernan cada método:
 *
 *   1. **Produce la nota y nada más.** Ningún informe lee estas tablas.
 *   2. **No escribe `notas.nota`.** `putValorar` guarda las marcas y devuelve la
 *      nota calculada; escribirla es `PUT notas/update/{id}`, tal como está. Así
 *      el único escritor de notas sigue siendo el que ya tiene bitácora, escala y
 *      recálculo de definitivas, y Flutter no se entera (tareas §6.1).
 *   3. **Cinco tablas nuevas y una columna NULL.** Nada de lo que hay se toca.
 *
 * ## Por qué SQL y no Eloquent
 *
 * Por lo mismo que el resto del repo: 990 consultas crudas y los modelos usados
 * marginalmente. Los cinco modelos existen para que larastan y el lector sepan
 * qué columnas hay; las lecturas y escrituras van aquí, con nombres de columna
 * escritos y **nunca `SELECT *`** —la lección de `notas/detailed`: una columna
 * nueva en la tabla no puede aparecer sola en la respuesta—.
 */
class RubricasController extends Controller
{
    use ResuelveElUsuario;

    /**
     * `GET rubricas` — las del año del token (24 §4.1).
     *
     * Con `?asignatura_id=N`, las de esa asignatura **más las plantillas**: es lo
     * que el selector de una subunidad puede ofrecer.
     *
     * @return list<array<string, mixed>>
     */
    public function getIndex(): array
    {
        $parametros = [(int) $this->user->year_id];
        $filtro = '';

        if (Request::filled('asignatura_id')) {
            $filtro = ' AND (r.asignatura_id = ? OR r.es_plantilla = 1)';
            $parametros[] = $this->identificador(Request::input('asignatura_id'), 'asignatura_id');
        }

        $filas = DB::select(
            'SELECT r.id, r.nombre, r.descripcion, r.asignatura_id, r.es_plantilla, r.updated_at,
                    (SELECT COUNT(*) FROM rubrica_criterios c WHERE c.rubrica_id = r.id) AS criterios,
                    (SELECT COUNT(*) FROM rubrica_niveles n WHERE n.rubrica_id = r.id) AS niveles,
                    (SELECT COALESCE(SUM(c.peso), 0) FROM rubrica_criterios c WHERE c.rubrica_id = r.id) AS suma_pesos,
                    (SELECT COUNT(*) FROM subunidades s WHERE s.rubrica_id = r.id AND s.deleted_at IS NULL) AS subunidades_que_la_usan
               FROM rubricas r
              WHERE r.year_id = ? AND r.deleted_at IS NULL'.$filtro.'
              ORDER BY r.nombre, r.id',
            $parametros
        );

        return array_values(array_map(fn ($r) => [
            'id' => (int) $r->id,
            'nombre' => $r->nombre,
            'descripcion' => $r->descripcion,
            'asignatura_id' => $r->asignatura_id === null ? null : (int) $r->asignatura_id,
            'es_plantilla' => (int) $r->es_plantilla,
            'criterios' => (int) $r->criterios,
            'niveles' => (int) $r->niveles,
            'suma_pesos' => (int) $r->suma_pesos,
            'subunidades_que_la_usan' => (int) $r->subunidades_que_la_usan,
            'updated_at' => $r->updated_at,
        ], $filas));
    }

    /**
     * `GET rubricas/niveles-de-la-escala` — el sembrado (24 §4.2). **No escribe.**
     *
     * El puntaje propuesto es el **punto medio del tramo**: con él marcar todo
     * «Superior» da una nota Superior y todo «Bajo» da una Bajo. Con `porc_final`
     * todo «Bajo» daría 69, que es «casi aprobó». Es una propuesta: el puntaje es
     * del colegio y se edita en la matriz.
     *
     * @return list<array{nombre: string, puntaje: int, orden: int}>
     */
    public function getNivelesDeLaEscala(): array
    {
        $escalas = DB::select(
            'SELECT e.desempenio, e.porc_inicial, e.porc_final, e.orden
               FROM escalas_de_valoracion e
              WHERE e.year_id = ? AND e.deleted_at IS NULL
              ORDER BY e.orden DESC, e.id',
            [(int) $this->user->year_id]
        );

        return array_values(array_map(fn ($e) => [
            'nombre' => (string) $e->desempenio,
            'puntaje' => (int) round(((int) $e->porc_inicial + (int) $e->porc_final) / 2),
            'orden' => (int) $e->orden,
        ], $escalas));
    }

    /**
     * `GET rubricas/{id}` — la matriz entera (24 §4.3).
     *
     * @return array<string, mixed>
     */
    public function getShow($id): array
    {
        $rubrica = $this->rubricaDelAnio($id);

        return $this->matriz((int) $rubrica->id);
    }

    /**
     * `POST rubricas` — crear (24 §4.4).
     *
     * Mismo cuerpo que el `PUT`, con una diferencia: aquí ninguna fila puede traer
     * `id`, porque no hay nada que actualizar. Se rechaza en vez de ignorarse:
     * un `id` ignorado en silencio es la familia de `escalas/store`, que tira el
     * cuerpo entero y el cliente cree que lo guardó.
     *
     * @return JsonResponse
     */
    public function postStore()
    {
        $cuerpo = $this->cuerpoDeLaMatriz(nueva: true);
        $ahora = Reloj::ahoraTexto();
        $usuario = (int) $this->user->user_id;
        $anio = (int) $this->user->year_id;

        $id = DB::transaction(function () use ($cuerpo, $ahora, $usuario, $anio) {
            DB::insert(
                'INSERT INTO rubricas (year_id, asignatura_id, nombre, descripcion, es_plantilla, created_by, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [$anio, $cuerpo['asignatura_id'], $cuerpo['nombre'], $cuerpo['descripcion'], $cuerpo['es_plantilla'], $usuario, $ahora, $ahora]
            );

            $id = (int) DB::getPdo()->lastInsertId();

            $this->guardarMatriz($id, $cuerpo, $ahora);

            return $id;
        });

        return response()->json($this->matriz($id), 201);
    }

    /**
     * `PUT rubricas/{id}` — guardar la matriz (24 §4.5).
     *
     * Criterios y niveles: con `id` se actualizan, sin `id` se crean, los que
     * había y no vienen se borran. **Quitar uno que ya tiene valoraciones es 422
     * y no escribe nada**; se comprueba antes de abrir la transacción, para que
     * el rechazo no sea un rollback sino una respuesta.
     *
     * Cambiar el `peso` o el `puntaje` de uno ya usado sí se permite y **no
     * recalcula ninguna nota**: la que se escribió se escribió con la regla de
     * ese momento, como la regla de nivelación (plan §3.5).
     *
     * @return array<string, mixed>
     */
    public function putUpdate($id): array
    {
        $rubrica = $this->rubricaDelAnio($id);
        $rubricaId = (int) $rubrica->id;
        $cuerpo = $this->cuerpoDeLaMatriz(nueva: false, rubricaId: $rubricaId);
        $ahora = Reloj::ahoraTexto();
        $usuario = (int) $this->user->user_id;

        DB::transaction(function () use ($rubricaId, $cuerpo, $ahora, $usuario) {
            DB::update(
                'UPDATE rubricas SET nombre = ?, descripcion = ?, asignatura_id = ?, es_plantilla = ?, updated_by = ?, updated_at = ?
                  WHERE id = ?',
                [$cuerpo['nombre'], $cuerpo['descripcion'], $cuerpo['asignatura_id'], $cuerpo['es_plantilla'], $usuario, $ahora, $rubricaId]
            );

            $this->guardarMatriz($rubricaId, $cuerpo, $ahora);
        });

        return $this->matriz($rubricaId);
    }

    /**
     * `DELETE rubricas/{id}` — a la papelera (24 §4.6).
     *
     * No se borra una rúbrica que alguna subunidad viva esté usando: la foránea
     * es `SET NULL` y sólo mira el borrado físico, así que sin esta guarda un
     * softdelete dejaría subunidades apuntando a una rúbrica que `getCalificar`
     * ya no encuentra. Las valoraciones hechas no se tocan.
     *
     * @return array{id: int, deleted_at: string}
     */
    public function deleteDestroy($id): array
    {
        $rubrica = $this->rubricaDelAnio($id);
        $rubricaId = (int) $rubrica->id;

        $enUso = $this->subunidadesQueLaUsan($rubricaId);

        if ($enUso !== []) {
            $cuantas = count($enUso);
            $this->rechazar([
                'message' => "Esta rúbrica la usan {$cuantas} ".($cuantas === 1 ? 'subunidad' : 'subunidades').'. Desenlázala antes de borrarla.',
                'subunidades' => $enUso,
            ]);
        }

        $ahora = Reloj::ahoraTexto();

        DB::update(
            'UPDATE rubricas SET deleted_at = ?, deleted_by = ?, updated_at = ? WHERE id = ?',
            [$ahora, (int) $this->user->user_id, $ahora, $rubricaId]
        );

        return ['id' => $rubricaId, 'deleted_at' => $ahora];
    }

    /**
     * `PUT rubricas/subunidad/{subunidad_id}` — enlazar o desenlazar (24 §4.7).
     *
     * El año se saca de la subunidad y no del token: la subunidad es la que
     * manda, y una rúbrica de otro año no le sirve aunque sea del año de quien
     * llama. Desenlazar no borra las valoraciones: siguen en su tabla por si se
     * vuelve a enlazar, y `getCalificar` sólo enseña las de la rúbrica actual.
     *
     * @return array{subunidad_id: int, rubrica_id: ?int}
     */
    public function putSubunidad($subunidad_id): array
    {
        $subunidad = $this->subunidadViva($subunidad_id);

        if (! Request::has('rubrica_id')) {
            abort(422, "Falta 'rubrica_id' (null para desenlazar).");
        }

        $pedida = Request::input('rubrica_id');
        $rubricaId = null;

        if ($pedida !== null && $pedida !== '') {
            $rubricaId = $this->identificador($pedida, 'rubrica_id');

            $rubrica = DB::selectOne(
                'SELECT r.id, r.year_id FROM rubricas r WHERE r.id = ? AND r.deleted_at IS NULL',
                [$rubricaId]
            );

            if ($rubrica === null) {
                abort(422, 'Esa rúbrica no existe.');
            }

            if ((int) $rubrica->year_id !== (int) $subunidad->year_id) {
                abort(422, 'Esa rúbrica no es del año de la subunidad.');
            }
        }

        DB::update(
            'UPDATE subunidades SET rubrica_id = ?, updated_by = ?, updated_at = ? WHERE id = ?',
            [$rubricaId, (int) $this->user->user_id, Reloj::ahoraTexto(), (int) $subunidad->id]
        );

        return ['subunidad_id' => (int) $subunidad->id, 'rubrica_id' => $rubricaId];
    }

    /**
     * `GET rubricas/calificar/{subunidad_id}` — la lectura de las dos pantallas (24 §4.8).
     *
     * Los alumnos son los del conjunto de la planilla —`Grupo::alumnos()` sin
     * retirados: `MATR`, `ASIS`, `PREM`— y, si la unidad es de un boletín
     * independiente (`unidades.alumno_id`, 19 §3), ese alumno y nadie más.
     *
     * **`nota_id` puede ser `null` y este método no la crea.** La fila de `notas`
     * la siembra `notas/detailed` al abrir la planilla; crearla aquí sería
     * escribir notas, y este carril no escribe notas.
     *
     * @return array<string, mixed>
     */
    public function getCalificar($subunidad_id): array
    {
        $subunidad = $this->subunidadViva($subunidad_id);
        $subunidadId = (int) $subunidad->id;
        $momento = $this->momento(Request::input('momento'));

        $rubrica = null;

        if ($subunidad->rubrica_id !== null) {
            $viva = DB::selectOne(
                'SELECT r.id FROM rubricas r WHERE r.id = ? AND r.deleted_at IS NULL',
                [(int) $subunidad->rubrica_id]
            );
            $rubrica = $viva === null ? null : $this->matriz((int) $viva->id);
        }

        // Una subconsulta por alumno y no un LEFT JOIN: `notas` no tiene clave
        // única sobre (alumno, subunidad), y con el JOIN un alumno con dos filas
        // saldría dos veces en la lista del grupo.
        $notaDelAlumno = '(SELECT n.id FROM notas n
                            WHERE n.alumno_id = a.id AND n.subunidad_id = ? AND n.deleted_at IS NULL
                            ORDER BY n.id LIMIT 1) AS nota_id';

        if ($subunidad->alumno_independiente !== null) {
            $alumnos = DB::select(
                "SELECT a.id AS alumno_id, {$notaDelAlumno}
                   FROM alumnos a
                  WHERE a.id = ? AND a.deleted_at IS NULL",
                [$subunidadId, (int) $subunidad->alumno_independiente]
            );
        } else {
            $alumnos = DB::select(
                "SELECT a.id AS alumno_id, {$notaDelAlumno}
                   FROM alumnos a
                  INNER JOIN matriculas m ON m.alumno_id = a.id AND m.grupo_id = ?
                         AND m.estado IN ('MATR', 'ASIS', 'PREM') AND m.deleted_at IS NULL
                  WHERE a.deleted_at IS NULL
                  ORDER BY a.apellidos, a.nombres, a.id",
                [$subunidadId, (int) $subunidad->grupo_id]
            );
        }

        $notaIds = array_values(array_filter(array_map(fn ($a) => $a->nota_id === null ? null : (int) $a->nota_id, $alumnos)));
        $notas = [];
        $marcas = [];

        if ($notaIds !== []) {
            $marcadores = implode(',', array_fill(0, count($notaIds), '?'));

            foreach (DB::select("SELECT n.id, n.nota FROM notas n WHERE n.id IN ({$marcadores})", $notaIds) as $n) {
                $notas[(int) $n->id] = (int) $n->nota;
            }

            if ($rubrica !== null) {
                $filas = DB::select(
                    "SELECT v.nota_id, v.criterio_id, v.nivel_id, v.comentario
                       FROM rubrica_valoraciones v
                      INNER JOIN rubrica_criterios c ON c.id = v.criterio_id
                      WHERE v.nota_id IN ({$marcadores}) AND c.rubrica_id = ? AND v.momento = ?
                      ORDER BY v.nota_id, v.criterio_id",
                    array_merge($notaIds, [$rubrica['id'], $momento])
                );

                foreach ($filas as $v) {
                    $marcas[(int) $v->nota_id][] = [
                        'criterio_id' => (int) $v->criterio_id,
                        'nivel_id' => (int) $v->nivel_id,
                        'comentario' => $v->comentario,
                    ];
                }
            }
        }

        NombreDelAlumno::deVarios(array_map(fn ($a) => (int) $a->alumno_id, $alumnos));

        return [
            'subunidad' => [
                'id' => $subunidadId,
                'definicion' => $subunidad->definicion,
                'porcentaje' => (int) $subunidad->porcentaje,
                'unidad_id' => (int) $subunidad->unidad_id,
                'periodo_id' => (int) $subunidad->periodo_id,
                'asignatura_id' => (int) $subunidad->asignatura_id,
                'grupo_id' => (int) $subunidad->grupo_id,
            ],
            'rubrica' => $rubrica,
            'momento' => $momento,
            'alumnos' => array_map(function ($a) use ($notas, $marcas) {
                $notaId = $a->nota_id === null ? null : (int) $a->nota_id;

                return [
                    'alumno_id' => (int) $a->alumno_id,
                    'nombre' => NombreDelAlumno::de((int) $a->alumno_id),
                    'nota_id' => $notaId,
                    'nota' => $notaId === null ? null : ($notas[$notaId] ?? null),
                    'valoraciones' => $notaId === null ? [] : ($marcas[$notaId] ?? []),
                ];
            }, $alumnos),
        ];
    }

    /**
     * `PUT rubricas/valorar/{nota_id}` — marcar un alumno (24 §4.9).
     *
     * **El permiso es el de `notas/update`**, `User::pueden_editar_notas` sobre el
     * periodo de la nota, con sus mismos códigos: la llamada siguiente del front
     * es `notas/update` y tiene que fallar por lo mismo que ésta.
     *
     * Guarda por `(nota_id, criterio_id, momento)`: lo que viene se pisa, lo que
     * no viene se conserva, `nivel_id: null` borra. **No escribe `notas.nota`.**
     *
     * @return array<string, mixed>
     */
    public function putValorar($nota_id): array
    {
        $notaId = $this->identificadorDeRuta($nota_id, 'Esa nota no existe.');
        $nota = $this->notaConRubrica($notaId);

        if ($nota === null) {
            abort(404, 'Esa nota no existe.');
        }

        User::pueden_editar_notas($this->user, (int) $nota->periodo_id);

        $rubrica = $this->rubricaDeLaNota($nota);
        $momento = $this->momento(Request::input('momento'));
        $marcas = $this->valoracionesDelCuerpo(Request::input('valoraciones'), $rubrica);

        DB::transaction(function () use ($notaId, $momento, $marcas) {
            $this->escribirMarcas($notaId, $momento, $marcas);
        });

        return $this->resultadoDeValorar($notaId, $momento, $rubrica);
    }

    /**
     * `PUT rubricas/valorar-lote` — marcar el grupo (24 §4.10).
     *
     * Todas las notas de la misma subunidad, el permiso una vez sobre ese
     * periodo, y **un solo desenlace**: cualquier fila inválida es 422 con
     * `{message, fila, nota_id, motivo}` y nada escrito. Es distinto de
     * `notas/lote`, que salta y sigue, a propósito: marcar 45 rúbricas es un acto,
     * y un lote que deja 30 marcadas no se distingue del docente que no llegó a
     * las 15.
     *
     * @return array<string, mixed>
     */
    public function putValorarLote(): array
    {
        $filas = Request::input('notas');

        if (! is_array($filas) || $filas === []) {
            abort(422, 'Hace falta una lista de notas.');
        }

        $momento = $this->momento(Request::input('momento'));

        // Primero la forma de cada fila, sin tocar la base: un `nota_id` que no
        // es un id se rechaza antes de preguntar por él.
        $notaIds = [];

        foreach (array_values($filas) as $i => $fila) {
            if (! is_array($fila) || ! isset($fila['nota_id'])) {
                $this->rechazarFila($i, null, "Falta 'nota_id'.");
            }

            if (! $this->esIdentificador($fila['nota_id'])) {
                $this->rechazarFila($i, null, "'nota_id' no es un identificador.");
            }

            $notaIds[$i] = (int) $fila['nota_id'];
        }

        if (count(array_unique($notaIds)) !== count($notaIds)) {
            abort(422, 'Una nota viene dos veces en el lote.');
        }

        $marcadores = implode(',', array_fill(0, count($notaIds), '?'));
        $notas = [];

        foreach (DB::select($this->consultaDeNota("n.id IN ({$marcadores})"), array_values($notaIds)) as $n) {
            $notas[(int) $n->id] = $n;
        }

        $primera = null;

        foreach ($notaIds as $i => $notaId) {
            if (! isset($notas[$notaId])) {
                $this->rechazarFila($i, $notaId, 'Esa nota no existe.');
            }

            if ($primera === null) {
                $primera = $notas[$notaId];
            } elseif ((int) $notas[$notaId]->subunidad_id !== (int) $primera->subunidad_id) {
                $this->rechazarFila($i, $notaId, 'No es de la misma subunidad que la primera del lote.');
            }
        }

        User::pueden_editar_notas($this->user, (int) $primera->periodo_id);

        $rubrica = $this->rubricaDeLaNota($primera);

        $marcasPorNota = [];

        foreach (array_values($filas) as $i => $fila) {
            try {
                $marcasPorNota[$notaIds[$i]] = $this->valoracionesDelCuerpo($fila['valoraciones'] ?? null, $rubrica);
            } catch (HttpException $e) {
                if ($e->getStatusCode() !== 422) {
                    throw $e;
                }

                $this->rechazarFila($i, $notaIds[$i], $e->getMessage());
            }
        }

        DB::transaction(function () use ($marcasPorNota, $momento) {
            foreach ($marcasPorNota as $notaId => $marcas) {
                $this->escribirMarcas($notaId, $momento, $marcas);
            }
        });

        $resultado = [];

        foreach ($notaIds as $notaId) {
            $resultado[] = $this->resultadoDeValorar($notaId, $momento, $rubrica);
        }

        return ['momento' => $momento, 'notas' => $resultado];
    }

    // ------------------------------------------------------------------
    // Lecturas compartidas
    // ------------------------------------------------------------------

    /**
     * La rúbrica pedida por la ruta: 404 si no está, **403 si es de otro año**.
     * Existe, así que decir «no existe» sería mentir sobre la base — el criterio
     * de `boletin-independiente/periodo`.
     */
    private function rubricaDelAnio($id): object
    {
        $rubricaId = $this->identificadorDeRuta($id, 'Esa rúbrica no existe.');

        $rubrica = DB::selectOne(
            'SELECT r.id, r.year_id FROM rubricas r WHERE r.id = ? AND r.deleted_at IS NULL',
            [$rubricaId]
        );

        if ($rubrica === null) {
            abort(404, 'Esa rúbrica no existe.');
        }

        if ((int) $rubrica->year_id !== (int) $this->user->year_id) {
            abort(403, 'Esa rúbrica no es del año en el que estás trabajando.');
        }

        return $rubrica;
    }

    /**
     * El cuerpo de `GET rubricas/{id}`: cabecera, criterios por `orden`, niveles
     * por `orden` **descendente** (el mejor a la izquierda, como en la escala),
     * los descriptores como lista plana y las subunidades que la usan.
     *
     * @return array<string, mixed>
     */
    private function matriz(int $rubricaId): array
    {
        $r = DB::selectOne(
            'SELECT r.id, r.year_id, r.nombre, r.descripcion, r.asignatura_id, r.es_plantilla
               FROM rubricas r WHERE r.id = ?',
            [$rubricaId]
        );

        $criterios = array_map(fn ($c) => [
            'id' => (int) $c->id,
            'definicion' => $c->definicion,
            'peso' => (int) $c->peso,
            'orden' => (int) $c->orden,
        ], DB::select(
            'SELECT c.id, c.definicion, c.peso, c.orden FROM rubrica_criterios c WHERE c.rubrica_id = ? ORDER BY c.orden, c.id',
            [$rubricaId]
        ));

        $niveles = array_map(fn ($n) => [
            'id' => (int) $n->id,
            'nombre' => $n->nombre,
            'puntaje' => (int) $n->puntaje,
            'orden' => (int) $n->orden,
        ], DB::select(
            'SELECT n.id, n.nombre, n.puntaje, n.orden FROM rubrica_niveles n WHERE n.rubrica_id = ? ORDER BY n.orden DESC, n.id',
            [$rubricaId]
        ));

        $descriptores = array_map(fn ($d) => [
            'criterio_id' => (int) $d->criterio_id,
            'nivel_id' => (int) $d->nivel_id,
            'texto' => $d->texto,
        ], DB::select(
            'SELECT d.criterio_id, d.nivel_id, d.texto
               FROM rubrica_descriptores d
              INNER JOIN rubrica_criterios c ON c.id = d.criterio_id
              WHERE c.rubrica_id = ?
              ORDER BY d.criterio_id, d.nivel_id',
            [$rubricaId]
        ));

        return [
            'id' => (int) $r->id,
            'year_id' => (int) $r->year_id,
            'nombre' => $r->nombre,
            'descripcion' => $r->descripcion,
            'asignatura_id' => $r->asignatura_id === null ? null : (int) $r->asignatura_id,
            'es_plantilla' => (int) $r->es_plantilla,
            'suma_pesos' => array_sum(array_column($criterios, 'peso')),
            'criterios' => $criterios,
            'niveles' => $niveles,
            'descriptores' => $descriptores,
            'subunidades_que_la_usan' => $this->subunidadesQueLaUsan($rubricaId),
        ];
    }

    /** @return list<array{id: int, definicion: ?string, unidad_id: int}> */
    private function subunidadesQueLaUsan(int $rubricaId): array
    {
        return array_values(array_map(fn ($s) => [
            'id' => (int) $s->id,
            'definicion' => $s->definicion === null ? null : (string) $s->definicion,
            'unidad_id' => (int) $s->unidad_id,
        ], DB::select(
            'SELECT s.id, s.definicion, s.unidad_id FROM subunidades s WHERE s.rubrica_id = ? AND s.deleted_at IS NULL ORDER BY s.id',
            [$rubricaId]
        )));
    }

    /**
     * La subunidad con lo que cuelga de ella: periodo, año, asignatura, grupo y
     * —si la unidad es de un boletín independiente— su alumno.
     */
    private function subunidadViva($id): object
    {
        $subunidadId = $this->identificadorDeRuta($id, 'Esa subunidad no existe.');

        $subunidad = DB::selectOne(
            'SELECT s.id, s.definicion, s.porcentaje, s.unidad_id, s.rubrica_id,
                    u.periodo_id, u.asignatura_id, u.alumno_id AS alumno_independiente,
                    a.grupo_id, p.year_id
               FROM subunidades s
              INNER JOIN unidades u ON u.id = s.unidad_id
              INNER JOIN asignaturas a ON a.id = u.asignatura_id
              INNER JOIN periodos p ON p.id = u.periodo_id
              WHERE s.id = ? AND s.deleted_at IS NULL',
            [$subunidadId]
        );

        if ($subunidad === null) {
            abort(404, 'Esa subunidad no existe.');
        }

        return $subunidad;
    }

    /** La consulta de una nota con su subunidad, su rúbrica y su periodo. */
    private function consultaDeNota(string $condicion): string
    {
        return "SELECT n.id, n.alumno_id, n.subunidad_id, s.rubrica_id, u.periodo_id
                  FROM notas n
                 INNER JOIN subunidades s ON s.id = n.subunidad_id
                 INNER JOIN unidades u ON u.id = s.unidad_id
                 WHERE {$condicion} AND n.deleted_at IS NULL";
    }

    private function notaConRubrica(int $notaId): ?object
    {
        return DB::selectOne($this->consultaDeNota('n.id = ?'), [$notaId]);
    }

    /**
     * La matriz de la rúbrica enlazada a la subunidad de esta nota, o 422 si no
     * hay ninguna. Indexada por id para validar las marcas sin recorrer.
     *
     * @return array{id: int, criterios: array<int, array<string, mixed>>, niveles: array<int, array<string, mixed>>, orden: list<int>}
     */
    private function rubricaDeLaNota(object $nota): array
    {
        if ($nota->rubrica_id === null) {
            abort(422, 'La subunidad de esta nota no tiene rúbrica enlazada.');
        }

        $viva = DB::selectOne(
            'SELECT r.id FROM rubricas r WHERE r.id = ? AND r.deleted_at IS NULL',
            [(int) $nota->rubrica_id]
        );

        if ($viva === null) {
            abort(422, 'La rúbrica de esta subunidad está en la papelera.');
        }

        $matriz = $this->matriz((int) $viva->id);

        $criterios = [];
        foreach ($matriz['criterios'] as $c) {
            $criterios[$c['id']] = $c;
        }

        $niveles = [];
        foreach ($matriz['niveles'] as $n) {
            $niveles[$n['id']] = $n;
        }

        return [
            'id' => $matriz['id'],
            'criterios' => $criterios,
            'niveles' => $niveles,
            'orden' => array_column($matriz['criterios'], 'id'),
        ];
    }

    // ------------------------------------------------------------------
    // El cuerpo de la matriz (POST y PUT)
    // ------------------------------------------------------------------

    /**
     * Lee y valida el cuerpo de `POST rubricas` y `PUT rubricas/{id}`. Todo 422
     * sale de aquí **antes de escribir nada**.
     *
     * @return array<string, mixed>
     */
    private function cuerpoDeLaMatriz(bool $nueva, ?int $rubricaId = null): array
    {
        $nombre = Request::input('nombre');

        if (! is_string($nombre) || trim($nombre) === '') {
            abort(422, "Falta 'nombre'.");
        }

        if (mb_strlen($nombre) > 255) {
            abort(422, "'nombre' no puede pasar de 255 caracteres.");
        }

        $descripcion = Request::input('descripcion');

        if ($descripcion !== null && ! is_string($descripcion)) {
            abort(422, "'descripcion' tiene que ser texto.");
        }

        $descripcion = ($descripcion === null || trim($descripcion) === '') ? null : $descripcion;

        $asignaturaId = null;
        $pedida = Request::input('asignatura_id');

        if ($pedida !== null && $pedida !== '') {
            $asignaturaId = $this->identificador($pedida, 'asignatura_id');

            $delAnio = DB::selectOne(
                'SELECT a.id FROM asignaturas a
                  INNER JOIN grupos g ON g.id = a.grupo_id AND g.deleted_at IS NULL
                  WHERE a.id = ? AND a.deleted_at IS NULL AND g.year_id = ?',
                [$asignaturaId, (int) $this->user->year_id]
            );

            if ($delAnio === null) {
                abort(422, 'Esa asignatura no es del año en el que estás trabajando.');
            }
        }

        $esPlantilla = $this->bandera(Request::input('es_plantilla'), 'es_plantilla');

        $criterios = $this->filasDeLaMatriz(Request::input('criterios'), 'criterios', 'definicion', 'peso', $nueva);
        $niveles = $this->filasDeLaMatriz(Request::input('niveles'), 'niveles', 'nombre', 'puntaje', $nueva);

        foreach ($niveles as $n) {
            if (mb_strlen($n['texto']) > 60) {
                abort(422, 'El nombre de un nivel no puede pasar de 60 caracteres.');
            }
        }

        // Con `id`, la fila tiene que ser de ESTA rúbrica: un id de otra
        // rúbrica es la familia de `identificadores-del-cuerpo.py`.
        if (! $nueva) {
            $this->exigirFilasDeLaRubrica($rubricaId, 'rubrica_criterios', array_column($criterios, 'id'), 'criterio');
            $this->exigirFilasDeLaRubrica($rubricaId, 'rubrica_niveles', array_column($niveles, 'id'), 'nivel');
        }

        $descriptores = [];
        $pedidos = Request::input('descriptores');

        if ($pedidos !== null) {
            if (! is_array($pedidos)) {
                abort(422, "'descriptores' tiene que ser una lista.");
            }

            $vistos = [];

            foreach (array_values($pedidos) as $i => $d) {
                if (! is_array($d) || ! isset($d['fila'], $d['columna']) || ! array_key_exists('texto', $d)) {
                    abort(422, "El descriptor {$i} necesita 'fila', 'columna' y 'texto'.");
                }

                $fila = $this->indice($d['fila'], count($criterios), "fila del descriptor {$i}");
                $columna = $this->indice($d['columna'], count($niveles), "columna del descriptor {$i}");

                if ($d['texto'] !== null && ! is_string($d['texto'])) {
                    abort(422, "El texto del descriptor {$i} tiene que ser texto.");
                }

                if (isset($vistos["{$fila}-{$columna}"])) {
                    abort(422, "La celda ({$fila}, {$columna}) viene dos veces.");
                }

                $vistos["{$fila}-{$columna}"] = true;

                // Una celda vacía no tiene fila (24 §4.3).
                if ($d['texto'] === null || trim($d['texto']) === '') {
                    continue;
                }

                $descriptores[] = ['fila' => $fila, 'columna' => $columna, 'texto' => $d['texto']];
            }
        }

        return [
            'nombre' => $nombre,
            'descripcion' => $descripcion,
            'asignatura_id' => $asignaturaId,
            'es_plantilla' => $esPlantilla,
            'criterios' => $criterios,
            'niveles' => $niveles,
            'descriptores' => $descriptores,
        ];
    }

    /**
     * Las filas de `criterios` o `niveles`, normalizadas:
     * `{id?: int, texto: string, valor: int, orden: int}`.
     *
     * @return list<array{id: ?int, texto: string, valor: int, orden: int}>
     */
    private function filasDeLaMatriz($lista, string $campo, string $campoTexto, string $campoValor, bool $nueva): array
    {
        if ($lista === null) {
            return [];
        }

        if (! is_array($lista)) {
            abort(422, "'{$campo}' tiene que ser una lista.");
        }

        $filas = [];
        $ids = [];

        foreach (array_values($lista) as $i => $fila) {
            if (! is_array($fila)) {
                abort(422, "La fila {$i} de '{$campo}' no tiene forma.");
            }

            $texto = $fila[$campoTexto] ?? null;

            if (! is_string($texto) || trim($texto) === '') {
                abort(422, "La fila {$i} de '{$campo}' necesita '{$campoTexto}'.");
            }

            if (! array_key_exists($campoValor, $fila)) {
                abort(422, "La fila {$i} de '{$campo}' necesita '{$campoValor}'.");
            }

            $valor = $this->enteroNoNegativo($fila[$campoValor], "'{$campoValor}' de la fila {$i} de '{$campo}'");

            $orden = array_key_exists('orden', $fila) && $fila['orden'] !== null
                ? $this->enteroNoNegativo($fila['orden'], "'orden' de la fila {$i} de '{$campo}'")
                : $i + 1;

            $id = null;

            if (array_key_exists('id', $fila) && $fila['id'] !== null) {
                if ($nueva) {
                    abort(422, "Una rúbrica nueva no puede traer filas con 'id' (fila {$i} de '{$campo}').");
                }

                $id = $this->identificador($fila['id'], "'id' de la fila {$i} de '{$campo}'");

                if (isset($ids[$id])) {
                    abort(422, "El id {$id} viene dos veces en '{$campo}'.");
                }

                $ids[$id] = true;
            }

            $filas[] = ['id' => $id, 'texto' => $texto, 'valor' => $valor, 'orden' => $orden];
        }

        return $filas;
    }

    /** @param  list<?int>  $ids */
    private function exigirFilasDeLaRubrica(int $rubricaId, string $tabla, array $ids, string $nombre): void
    {
        $ids = array_values(array_filter($ids, fn ($id) => $id !== null));

        if ($ids === []) {
            return;
        }

        $marcadores = implode(',', array_fill(0, count($ids), '?'));
        $propios = array_map(
            fn ($f) => (int) $f->id,
            DB::select("SELECT t.id FROM {$tabla} t WHERE t.id IN ({$marcadores}) AND t.rubrica_id = ?", array_merge($ids, [$rubricaId]))
        );

        $ajenos = array_diff($ids, $propios);

        if ($ajenos !== []) {
            abort(422, "El {$nombre} ".implode(', ', $ajenos).' no es de esta rúbrica.');
        }
    }

    /**
     * Escribe criterios, niveles y descriptores de una rúbrica. Dentro de la
     * transacción de quien llama.
     *
     * Las filas que había y no vienen se borran, **salvo que tengan
     * valoraciones**: eso es 422 y se comprueba antes de borrar nada — la
     * excepción hace rollback de lo que ya se hubiera escrito en esta misma
     * transacción, así que la promesa de «no escribe nada» se cumple igual.
     *
     * @param  array<string, mixed>  $cuerpo
     */
    private function guardarMatriz(int $rubricaId, array $cuerpo, string $ahora): void
    {
        $criterioIds = $this->guardarFilas($rubricaId, 'rubrica_criterios', 'definicion', 'peso', $cuerpo['criterios'], $ahora, 'criterios_con_valoraciones');
        $nivelIds = $this->guardarFilas($rubricaId, 'rubrica_niveles', 'nombre', 'puntaje', $cuerpo['niveles'], $ahora, 'niveles_con_valoraciones');

        DB::delete(
            'DELETE d FROM rubrica_descriptores d
              INNER JOIN rubrica_criterios c ON c.id = d.criterio_id
              WHERE c.rubrica_id = ?',
            [$rubricaId]
        );

        foreach ($cuerpo['descriptores'] as $d) {
            DB::insert(
                'INSERT INTO rubrica_descriptores (criterio_id, nivel_id, texto, created_at, updated_at) VALUES (?, ?, ?, ?, ?)',
                [$criterioIds[$d['fila']], $nivelIds[$d['columna']], $d['texto'], $ahora, $ahora]
            );
        }
    }

    /**
     * Una de las dos tablas de filas de la matriz. Devuelve los ids **en el
     * orden del cuerpo**, que es lo que los descriptores referencian.
     *
     * @param  list<array{id: ?int, texto: string, valor: int, orden: int}>  $filas
     * @return list<int>
     */
    private function guardarFilas(int $rubricaId, string $tabla, string $campoTexto, string $campoValor, array $filas, string $ahora, string $claveDelRechazo): array
    {
        $existentes = array_map(
            fn ($f) => (int) $f->id,
            DB::select("SELECT t.id FROM {$tabla} t WHERE t.rubrica_id = ?", [$rubricaId])
        );

        $conservados = array_values(array_filter(array_column($filas, 'id'), fn ($id) => $id !== null));
        $sobrantes = array_values(array_diff($existentes, $conservados));

        if ($sobrantes !== []) {
            $columna = $tabla === 'rubrica_criterios' ? 'criterio_id' : 'nivel_id';
            $marcadores = implode(',', array_fill(0, count($sobrantes), '?'));

            $usados = array_map(
                fn ($f) => (int) $f->{$columna},
                DB::select("SELECT DISTINCT v.{$columna} FROM rubrica_valoraciones v WHERE v.{$columna} IN ({$marcadores})", $sobrantes)
            );

            if ($usados !== []) {
                $this->rechazar([
                    'message' => 'No se puede quitar un criterio o un nivel que ya se usó para calificar.',
                    'criterios_con_valoraciones' => $claveDelRechazo === 'criterios_con_valoraciones' ? $usados : [],
                    'niveles_con_valoraciones' => $claveDelRechazo === 'niveles_con_valoraciones' ? $usados : [],
                ]);
            }

            DB::delete("DELETE FROM {$tabla} WHERE id IN ({$marcadores})", $sobrantes);
        }

        $ids = [];

        foreach ($filas as $fila) {
            if ($fila['id'] !== null) {
                DB::update(
                    "UPDATE {$tabla} SET {$campoTexto} = ?, {$campoValor} = ?, orden = ?, updated_at = ? WHERE id = ?",
                    [$fila['texto'], $fila['valor'], $fila['orden'], $ahora, $fila['id']]
                );
                $ids[] = $fila['id'];

                continue;
            }

            DB::insert(
                "INSERT INTO {$tabla} (rubrica_id, {$campoTexto}, {$campoValor}, orden, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)",
                [$rubricaId, $fila['texto'], $fila['valor'], $fila['orden'], $ahora, $ahora]
            );
            $ids[] = (int) DB::getPdo()->lastInsertId();
        }

        return $ids;
    }

    // ------------------------------------------------------------------
    // Valorar
    // ------------------------------------------------------------------

    /**
     * Las marcas del cuerpo, validadas contra la rúbrica:
     * `{criterio_id: int, nivel_id: ?int, comentario: ?string}`.
     *
     * @param  array{id: int, criterios: array<int, array<string, mixed>>, niveles: array<int, array<string, mixed>>, orden: list<int>}  $rubrica
     * @return list<array{criterio_id: int, nivel_id: ?int, comentario: ?string}>
     */
    private function valoracionesDelCuerpo($lista, array $rubrica): array
    {
        if (! is_array($lista) || $lista === []) {
            abort(422, 'Hace falta una lista de valoraciones.');
        }

        $marcas = [];
        $vistos = [];

        foreach (array_values($lista) as $i => $v) {
            if (! is_array($v) || ! isset($v['criterio_id'])) {
                abort(422, "La valoración {$i} necesita 'criterio_id'.");
            }

            $criterioId = $this->identificador($v['criterio_id'], "'criterio_id' de la valoración {$i}");

            if (! isset($rubrica['criterios'][$criterioId])) {
                abort(422, "El criterio {$criterioId} no es de la rúbrica de esta subunidad.");
            }

            if (isset($vistos[$criterioId])) {
                abort(422, "El criterio {$criterioId} viene dos veces.");
            }

            $vistos[$criterioId] = true;

            $nivelId = null;

            if (array_key_exists('nivel_id', $v) && $v['nivel_id'] !== null && $v['nivel_id'] !== '') {
                $nivelId = $this->identificador($v['nivel_id'], "'nivel_id' de la valoración {$i}");

                if (! isset($rubrica['niveles'][$nivelId])) {
                    abort(422, "El nivel {$nivelId} no es de la rúbrica de esta subunidad.");
                }
            }

            $comentario = $v['comentario'] ?? null;

            if ($comentario !== null && ! is_string($comentario)) {
                abort(422, "El comentario de la valoración {$i} tiene que ser texto.");
            }

            if ($comentario !== null && mb_strlen($comentario) > 255) {
                abort(422, "El comentario de la valoración {$i} no puede pasar de 255 caracteres.");
            }

            $marcas[] = [
                'criterio_id' => $criterioId,
                'nivel_id' => $nivelId,
                'comentario' => ($comentario === null || trim($comentario) === '') ? null : $comentario,
            ];
        }

        return $marcas;
    }

    /**
     * `INSERT ... ON DUPLICATE KEY UPDATE` sobre `rubrica_valoraciones_marca
     * (nota_id, criterio_id, momento)`, y `DELETE` para `nivel_id: null`. Una
     * sentencia por marca y sin ventana de borrado, como `bol_ind_periodos`.
     *
     * @param  list<array{criterio_id: int, nivel_id: ?int, comentario: ?string}>  $marcas
     */
    private function escribirMarcas(int $notaId, string $momento, array $marcas): void
    {
        $ahora = Reloj::ahoraTexto();
        $usuario = (int) $this->user->user_id;

        foreach ($marcas as $m) {
            if ($m['nivel_id'] === null) {
                DB::delete(
                    'DELETE FROM rubrica_valoraciones WHERE nota_id = ? AND criterio_id = ? AND momento = ?',
                    [$notaId, $m['criterio_id'], $momento]
                );

                continue;
            }

            DB::insert(
                'INSERT INTO rubrica_valoraciones (nota_id, criterio_id, nivel_id, momento, comentario, created_by, updated_by, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE nivel_id = VALUES(nivel_id), comentario = VALUES(comentario),
                                         updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)',
                [$notaId, $m['criterio_id'], $m['nivel_id'], $momento, $m['comentario'], $usuario, $usuario, $ahora, $ahora]
            );
        }
    }

    /**
     * El cuerpo de la respuesta de valorar (24 §4.9): `completa`,
     * `nota_calculada`, `suma_pesos` y el `desglose` con **todos** los criterios,
     * marcados o no.
     *
     * La regla es la de la §3: Σ (peso/100 × puntaje), sin normalizar. La nota
     * sale **redondeada a entero** porque `notas.nota` es `int`, y sale `null`
     * mientras falte algún criterio: una rúbrica a medias no produce una nota
     * parcial.
     *
     * @param  array{id: int, criterios: array<int, array<string, mixed>>, niveles: array<int, array<string, mixed>>, orden: list<int>}  $rubrica
     * @return array<string, mixed>
     */
    private function resultadoDeValorar(int $notaId, string $momento, array $rubrica): array
    {
        $marcadas = [];

        foreach (DB::select(
            'SELECT v.criterio_id, v.nivel_id FROM rubrica_valoraciones v
              INNER JOIN rubrica_criterios c ON c.id = v.criterio_id
              WHERE v.nota_id = ? AND v.momento = ? AND c.rubrica_id = ?',
            [$notaId, $momento, $rubrica['id']]
        ) as $v) {
            $marcadas[(int) $v->criterio_id] = (int) $v->nivel_id;
        }

        $desglose = [];
        $suma = 0.0;
        $sumaPesos = 0;
        $completa = $rubrica['orden'] !== [];

        foreach ($rubrica['orden'] as $criterioId) {
            $criterio = $rubrica['criterios'][$criterioId];
            $sumaPesos += $criterio['peso'];
            $nivelId = $marcadas[$criterioId] ?? null;
            $nivel = $nivelId === null ? null : ($rubrica['niveles'][$nivelId] ?? null);

            if ($nivel === null) {
                $completa = false;
                $desglose[] = ['criterio_id' => $criterioId, 'peso' => $criterio['peso'], 'nivel_id' => null, 'puntaje' => null, 'aporte' => null];

                continue;
            }

            $aporte = $criterio['peso'] / 100 * $nivel['puntaje'];
            $suma += $aporte;

            $desglose[] = [
                'criterio_id' => $criterioId,
                'peso' => $criterio['peso'],
                'nivel_id' => $nivelId,
                'puntaje' => $nivel['puntaje'],
                'aporte' => round($aporte, 2),
            ];
        }

        return [
            'nota_id' => $notaId,
            'momento' => $momento,
            'completa' => $completa,
            'nota_calculada' => $completa ? (int) round($suma) : null,
            'suma_pesos' => $sumaPesos,
            'desglose' => $desglose,
        ];
    }

    // ------------------------------------------------------------------
    // Lectura del cuerpo
    // ------------------------------------------------------------------

    /**
     * `momento`, con el vocabulario cerrado de `Rubrica::MOMENTOS`. Ausente vale
     * `original`; cualquier otra cosa es 422 — «cualquier cadena vale» es la
     * familia de `tools/verdad-laxa-que-escribe.py`.
     */
    private function momento($valor): string
    {
        if ($valor === null || $valor === '') {
            return 'original';
        }

        if (! is_string($valor) || ! in_array($valor, Rubrica::MOMENTOS, true)) {
            abort(422, "'momento' tiene que ser 'original' o 'nivelacion'.");
        }

        return $valor;
    }

    /**
     * Una bandera con el vocabulario de `aplica` en `boletin-independiente/periodo`:
     * `1/0`, `true/false`, `"on"/"off"`, `"yes"/"no"`; ausente vale 0; lo demás 422.
     */
    private function bandera($valor, string $campo): int
    {
        if ($valor === null || $valor === '') {
            return 0;
        }

        $leido = filter_var($valor, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        if ($leido === null) {
            abort(422, "'{$campo}' tiene que ser verdadero o falso.");
        }

        return $leido ? 1 : 0;
    }

    private function esIdentificador($valor): bool
    {
        return is_scalar($valor) && preg_match('/^\d+$/', (string) $valor) === 1 && (int) $valor > 0;
    }

    private function identificador($valor, string $campo): int
    {
        if (! $this->esIdentificador($valor)) {
            abort(422, "{$campo} no es un identificador.");
        }

        return (int) $valor;
    }

    /** Un id que llega por la URL: si no tiene forma de id, la fila no existe. */
    private function identificadorDeRuta($valor, string $mensaje): int
    {
        if (! $this->esIdentificador($valor)) {
            abort(404, $mensaje);
        }

        return (int) $valor;
    }

    private function enteroNoNegativo($valor, string $campo): int
    {
        if (! is_scalar($valor) || is_bool($valor) || preg_match('/^\d+$/', (string) $valor) !== 1) {
            abort(422, "{$campo} tiene que ser un entero sin decimales y no negativo.");
        }

        return (int) $valor;
    }

    /** Un índice de `descriptores`: entero desde 0 y dentro de la lista. */
    private function indice($valor, int $tope, string $campo): int
    {
        $indice = $this->enteroNoNegativo($valor, "La {$campo}");

        if ($indice >= $tope) {
            abort(422, "La {$campo} apunta fuera de la matriz.");
        }

        return $indice;
    }

    /**
     * Un 422 con más campos que `message`. `abort()` con una respuesta la lanza
     * tal cual, así que el cuerpo llega entero y, dentro de una transacción, hace
     * rollback igual que un `abort(422, ...)`.
     *
     * @param  array<string, mixed>  $cuerpo
     */
    private function rechazar(array $cuerpo): never
    {
        abort(response()->json($cuerpo, 422));
    }

    private function rechazarFila(int $fila, ?int $notaId, string $motivo): never
    {
        $this->rechazar([
            'message' => "La fila {$fila} del lote no vale: {$motivo} Nada se escribió.",
            'fila' => $fila,
            'nota_id' => $notaId,
            'motivo' => $motivo,
        ]);
    }
}
