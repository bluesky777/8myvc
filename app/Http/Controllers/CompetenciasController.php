<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResuelveElUsuario;
use App\Services\Auditoria;
use App\Services\BoletinIndependiente;
use App\Support\Autoriza;
use App\Support\CatalogoDelMen;
use App\Support\Reloj;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;

/**
 * Las competencias del colegio: la tabla `competencias`, que hasta hoy no
 * existía.
 *
 * Es la **Fase 2** de
 * [35-el-modelo-de-evaluacion-del-colegio.md](../../../docs/migracion/35-el-modelo-de-evaluacion-del-colegio.md),
 * o sea la **Entrega 2** del [28](../../../docs/migracion/28-competencias-e-indicadores.md)
 * §5.2 con las decisiones **D10**, **D11** y **D13** encima. El documento manda;
 * si algo de aquí discrepa de él, el que está mal es éste.
 *
 * ## Qué es una competencia aquí, en una línea
 *
 * Un **texto por materia + grado**, sin nota, sin porcentaje y sin padre. Es un
 * **padre opcional** (D10): sus hijos son los desempeños de la Fase 3, que la
 * apuntarán con `competencia_id` **anulable**. El colegio que sólo quiera los
 * textos del periodo no escribe ni una competencia y **no ve nada distinto**.
 *
 * ## Las reglas de la §4 del doc 28 que gobiernan este fichero
 *
 * 1. **El catálogo siembra, no manda.** `copiar` —y adoptar del MEN es un origen
 *    de `copiar`— **copia el texto**. Ninguna competencia enlaza a otra ni al
 *    fichero del MEN: corregir una errata en 2028 no puede cambiar un boletín
 *    impreso en 2026.
 * 5. **`alumno_id IS NULL` es el reparto del curso.** Y aquí eso no se escribe
 *    `= NULL`, que no empareja nunca, sino **`<=>`** — ver `getIndex`.
 *
 * ## Quién puede qué, y por qué los dos `GET` son distintos del resto
 *
 * `auth.personal` en la ruta y `Autoriza::puedeEditarPlantillaNotas` **dentro**,
 * que es la forma de `PlantillaNotasController` y la **D13**: *«lo escribe quien
 * tenga `can_edit_plantilla_notas»*.
 *
 * **Pero el criterio fino sólo va en las cinco escrituras.** Los dos `GET` —el de
 * las competencias del año y el del catálogo del MEN— contestan **200 a cualquier
 * docente**, y es a propósito: el plan lo pide con esas palabras, y el motivo se
 * ve en la Fase 3 — el docente va a **colgar sus desempeños de una competencia**,
 * así que tiene que poder leer la lista. Es la diferencia con la plantilla, donde
 * hasta el `GET` pide permiso porque ahí lo que se enseña son **porcentajes que
 * el docente no debe ver antes de tiempo**.
 *
 * > **El plan dice «403 en las seis de escritura» y son CINCO.** Las seis del doc
 * > 28 §5.2 son `GET`, `POST`, `PUT {id}`, `DELETE {id}`, `PUT orden` y
 * > `PUT copiar`: el `GET` está dentro de esas seis, así que escrituras hay
 * > cinco. Con `GET competencias/catalogo-men` son **siete rutas, dos de lectura
 * > y cinco de escritura**, y así está escrito el test.
 *
 * ## Por qué SQL con columnas nombradas y nunca `SELECT *`
 *
 * Por lo mismo que el resto del repo, y aquí con un motivo extra: esta tabla
 * **va a ganar columnas** en las fases siguientes, y un `SELECT *` las repartiría
 * solas a toda respuesta que devuelva una competencia. Es la puerta exacta por la
 * que `profesores.tono` se repartió a seis respuestas vivas sin que nadie lo
 * mandara.
 */
class CompetenciasController extends Controller
{
    use ResuelveElUsuario;

    /**
     * `GET competencias` — **dos lecturas en una ruta**, y la segunda es la que
     * tiene trampa.
     *
     * ### 1. El catálogo del año *(sin `alumno_id`)*
     *
     * Todas las competencias del año, filtrables por `materia_id` y `grado_id`.
     * Es la pantalla donde el colegio las escribe, así que enseña **todas las
     * filas**, también las dirigidas a una materia que ya no existe: una fila mal
     * dirigida tiene que **verse** para poder arreglarse. Es la misma razón por la
     * que `PlantillaNotasController::getIndex` lee `todasDelAnio` y no
     * `unidadesPara`.
     *
     * ### 2. Las de UN alumno *(con `alumno_id`)*
     *
     * Lo que ese alumno recibiría en su boletín, y es donde está el fallo que hay
     * que no cometer:
     *
     * ```sql
     * AND c.alumno_id <=> ?     -- NO `= ?`
     * ```
     *
     * `BoletinIndependiente::alcance($alumno, $periodo)` devuelve **el id del
     * alumno si ese periodo suyo va aparte, y `NULL` si va con el grupo**. Con
     * `= NULL` el alumno normal **no empareja ni una fila** y se queda sin
     * competencias **sin dar ningún error**: 200, lista vacía, boletín mudo. Con
     * `<=>`, `NULL <=> NULL` es verdad y recibe las del grupo. Es el mismo
     * operador y el mismo motivo que en `unidades`.
     *
     * Y el grado va con `IS NULL OR =`, que es **D25 aplicada**: una competencia
     * de «Matemáticas, todos los grados» y otra de «Matemáticas, 6.º» le llegan
     * **las dos** al alumno de 6.º, ordenadas por `orden`. Acumulan, no compiten
     * — deliberadamente al revés que la plantilla, donde gana la más específica,
     * porque allí son porcentajes que pelean por el 100 % y aquí son textos que
     * conviven.
     *
     * **La respuesta dice con qué alcance leyó**, no sólo qué encontró: una lista
     * vacía tiene que poder distinguirse de «leí por el alcance equivocado».
     *
     * @return array<string, mixed>
     */
    public function getIndex(): array
    {
        $yearId = (int) $this->user->year_id;
        $alumnoId = $this->idOpcional('alumno_id');

        if ($alumnoId !== null) {
            return $this->lasDelAlumno($yearId, $alumnoId);
        }

        $filtros = ['year_id = ?'];
        $valores = [$yearId];

        if (Request::has('materia_id')) {
            $filtros[] = 'materia_id = ?';
            $valores[] = $this->idObligatorio('materia_id');
        }

        // **`<=>` también aquí, y no es simetría decorativa**: `grado_id=` vacío
        // es «las que valen para todos los grados», que es un filtro legítimo de
        // la pantalla, y con `= NULL` no devolvería ninguna.
        if (Request::has('grado_id')) {
            $filtros[] = 'grado_id <=> ?';
            $valores[] = $this->idOpcional('grado_id');
        }

        $filas = DB::select(
            'SELECT id, definicion, orden, materia_id, grado_id, alumno_id, codigo_men
               FROM competencias
              WHERE '.implode(' AND ', $filtros).' AND deleted_at IS NULL
              ORDER BY materia_id, grado_id, orden, id',
            $valores
        );

        return [
            'year_id' => $yearId,
            'competencias' => $this->conSusNombres($filas),
            'grupos' => $this->gruposDe($filas),
        ];
    }

    /**
     * `GET competencias/catalogo-men?materia_id=&grado_id=` — lo que el MEN
     * publica para **esa** materia y **ese** grado. **D11.**
     *
     * ## Los dos parámetros son obligatorios, y ésa es la decisión de diseño
     *
     * No devuelve el corpus entero, y no por tamaño sino por contrato: **los dos
     * parámetros son exactamente el alcance con el que se adopta**. El colegio
     * adopta «las competencias de Matemáticas de 6.º», no «las del MEN». Con eso
     * la respuesta está **acotada por construcción** —son las de un área y un
     * conjunto de grados, cinco o seis filas— y no hay que paginar ni cachear
     * nada nunca. Una ruta que puede crecer sin tope no se afina después, y en
     * este repositorio los informes ya tardan de 24 a 63 segundos.
     *
     * ## Lo que NO cubre el MEN contesta 200 con la lista vacía y el motivo
     *
     * **Nunca 404.** Un 404 diría «esa ruta no existe» cuando lo cierto es «el
     * MEN no publica estándares de Educación Religiosa», y el colegio se quedaría
     * pensando que la pantalla está rota. Es población, no `OK`, y hay **tres
     * noes distintos** que la respuesta separa:
     *
     * | `motivo` | qué decirle al colegio |
     * |---|---|
     * | `sin_estandares` | *«El MEN no publica Estándares Básicos para esta área. Escríbalas una vez y cópielas cada año.»* Religión, Artes, Ed. Física y Tecnología (D11) |
     * | `sin_emparejar` | *«No supimos a qué área del MEN corresponde esta materia.»* Puede ser una de las de arriba, o puede ser Lenguaje escrito de una forma que no reconocemos |
     * | `preescolar` | *«Los Estándares Básicos empiezan en 1.º.»* No es un fallo del colegio |
     *
     * La respuesta lleva siempre `emparejamiento`, con **qué clave casó**, para
     * que la adivinanza se pueda auditar desde la pantalla. Y `area` a mano gana
     * siempre: el que sepa que su «Dimensión Comunicativa» es Lenguaje lo dice y
     * este emparejador no opina.
     *
     * ## `por_tipo` es la mitad que evita adoptar 49 filas queriendo cinco
     *
     * El catálogo trae **tres granos** —ver `CatalogoDelMen::competencias`— porque
     * el MEN no publica lo mismo para las seis áreas. La respuesta los cuenta por
     * separado y marca cuáles se adoptan por defecto, para que la pantalla pueda
     * decir *«5 competencias, y 44 estándares del MEN si los quiere»* en vez de
     * soltar las 49 en una lista sin explicar de qué es cada renglón.
     *
     * @return array<string, mixed>
     */
    public function getCatalogoMen(): array
    {
        $materiaId = $this->idObligatorio('materia_id');
        $gradoId = $this->idObligatorio('grado_id');

        $materia = $this->materiaDelColegio($materiaId);
        $grado = $this->gradoDelColegio($gradoId);

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
     * `POST competencias` — una competencia nueva.
     *
     * @return array<string, mixed>
     */
    public function postStore(): array
    {
        Autoriza::exigir(
            Autoriza::puedeEditarPlantillaNotas($this->user),
            self::SIN_PERMISO
        );

        $yearId = (int) $this->user->year_id;
        $materiaId = $this->idObligatorio('materia_id', 422);
        $this->materiaDelColegio($materiaId, 422);

        $gradoId = $this->idOpcional('grado_id');
        if ($gradoId !== null) {
            $this->gradoDelColegio($gradoId, 422);
        }

        $alumnoId = $this->idOpcional('alumno_id');
        if ($alumnoId !== null) {
            $this->alumnoDelColegio($alumnoId);
        }

        $id = (int) DB::table('competencias')->insertGetId([
            'year_id' => $yearId,
            'materia_id' => $materiaId,
            'grado_id' => $gradoId,
            'alumno_id' => $alumnoId,
            'definicion' => $this->texto('definicion', null),
            'orden' => Request::has('orden')
                ? $this->enteroNoNegativo(Request::input('orden'), 'orden')
                : $this->siguienteOrden($yearId, $materiaId, $gradoId, $alumnoId),
            'codigo_men' => null,
            'created_by' => (int) $this->user->user_id,
            'created_at' => Reloj::ahoraTexto(),
            'updated_at' => Reloj::ahoraTexto(),
        ]);

        return $this->competenciaConSuGrupo($id);
    }

    /**
     * `PUT competencias/{id}`.
     *
     * **Cada campo por defecto vale lo que ya hay guardado**, que es la trampa (1)
     * de la §5.1.e del doc 28: los clientes de esta casa mandan el objeto entero
     * unas veces y un trozo otras, y un campo ausente tomado por «ponlo a null»
     * mandaría al colegio entero una competencia que era de 6.º.
     *
     * **`codigo_men` no se toca desde fuera.** Es procedencia: dice de qué
     * enunciado del MEN nació esta fila, y eso **no cambia porque alguien edite el
     * texto** — D11 dice que lo adoptado es suyo y puede editarlo. Dejar que el
     * cliente lo escribiera permitiría marcar como «del MEN» algo que el MEN no
     * dijo, que es la única forma que tiene esta columna de mentir.
     *
     * @return array<string, mixed>
     */
    public function putUpdate($id): array
    {
        Autoriza::exigir(
            Autoriza::puedeEditarPlantillaNotas($this->user),
            self::SIN_PERMISO
        );

        $competencia = $this->competenciaDelAnio($id);

        $materiaId = $this->idConDefecto('materia_id', (int) $competencia->materia_id);
        $this->materiaDelColegio($materiaId, 422);

        $gradoId = Request::has('grado_id')
            ? $this->idOpcional('grado_id')
            : ($competencia->grado_id === null ? null : (int) $competencia->grado_id);
        if ($gradoId !== null) {
            $this->gradoDelColegio($gradoId, 422);
        }

        $alumnoId = Request::has('alumno_id')
            ? $this->idOpcional('alumno_id')
            : ($competencia->alumno_id === null ? null : (int) $competencia->alumno_id);
        if ($alumnoId !== null) {
            $this->alumnoDelColegio($alumnoId);
        }

        DB::table('competencias')->where('id', $competencia->id)->update([
            'materia_id' => $materiaId,
            'grado_id' => $gradoId,
            'alumno_id' => $alumnoId,
            'definicion' => $this->texto('definicion', $competencia->definicion),
            'orden' => Request::has('orden')
                ? $this->enteroNoNegativo(Request::input('orden'), 'orden')
                : (int) $competencia->orden,
            'updated_by' => (int) $this->user->user_id,
            'updated_at' => Reloj::ahoraTexto(),
        ]);

        return $this->competenciaConSuGrupo((int) $competencia->id);
    }

    /**
     * `DELETE competencias/{id}` — a la papelera.
     *
     * **Borrado lógico y no físico**, como todo lo que el colegio escribe: la
     * Fase 3 va a colgar desempeños de aquí con `competencia_id`, y un borrado
     * físico dejaría a esos desempeños apuntando a nada. Con el lógico, el
     * desempeño sigue en pie y se ve **que perdió el padre**, que es lo que el
     * colegio puede arreglar.
     *
     * @return array<string, mixed>
     */
    public function deleteDestroy($id): array
    {
        Autoriza::exigir(
            Autoriza::puedeEditarPlantillaNotas($this->user),
            self::SIN_PERMISO
        );

        $competencia = $this->competenciaDelAnio($id);
        $ahora = Reloj::ahoraTexto();

        DB::table('competencias')->where('id', $competencia->id)->update([
            'deleted_at' => $ahora,
            'deleted_by' => (int) $this->user->user_id,
            'updated_at' => $ahora,
        ]);

        return ['id' => (int) $competencia->id];
    }

    /**
     * `PUT competencias/orden` — reordena **un grupo**, en una llamada.
     *
     * Cuerpo:
     *
     *     { "materia_id": 15, "grado_id": 11, "alumno_id": null,
     *       "orden": [7, 3, 5] }
     *
     * **La posición en la lista ES el `orden`**, y la lista tiene que traer
     * **exactamente** las competencias vivas de ese grupo: una lista parcial deja
     * huecos y repetidos, que es el estado del que se viene.
     *
     * > **Y el grupo va en el cuerpo, al revés que en la plantilla, que reordena
     * > el año entero.** No es un descuido: allí la pantalla enseña **una lista**
     * > —la plantilla del año— y aquí enseña **una por materia y grado**. Pedir
     * > aquí la lista del año obligaría a mandar las competencias de Matemáticas
     * > para reordenar las de Inglés.
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
        $materiaId = $this->idObligatorio('materia_id', 422);
        $gradoId = $this->idOpcional('grado_id');
        $alumnoId = $this->idOpcional('alumno_id');

        $pedidos = $this->listaDeIds(Request::input('orden'), 'orden');
        $existentes = array_map(
            fn ($c) => (int) $c->id,
            $this->delGrupo($yearId, $materiaId, $gradoId, $alumnoId)
        );

        $comprobar = $pedidos;
        sort($comprobar);
        $ordenados = $existentes;
        sort($ordenados);

        if ($comprobar !== $ordenados) {
            abort(422, 'La lista de `orden` tiene que traer exactamente las competencias vivas de '
                .'ese grupo: una lista parcial deja huecos y repetidos en el orden.');
        }

        // Se escribe después de comprobar el conjunto entero, no fila a fila:
        // media reordenación aplicada es el estado del que se viene.
        foreach ($pedidos as $posicion => $competenciaId) {
            DB::table('competencias')->where('id', $competenciaId)->update([
                'orden' => $posicion,
                'updated_by' => (int) $this->user->user_id,
                'updated_at' => Reloj::ahoraTexto(),
            ]);
        }

        return ['reordenadas' => count($pedidos)];
    }

    /**
     * `PUT competencias/copiar` — **la ruta que trae los textos de otra parte**, y
     * adoptar del MEN es **un origen suyo, no una ruta aparte** (D11).
     *
     * ```json
     * { "destino": { "materia_id": 15, "grado_id": 11 },
     *   "origen":  { "tipo": "men" } }
     * ```
     *
     * | `origen.tipo` | de dónde saca los textos |
     * |---|---|
     * | `men`   | el fichero de datos, por el área que casa con la materia de destino (o `origen.area` a mano) y el conjunto de grados del grado de destino |
     * | `grado` | otras competencias **del mismo año**: `origen.materia_id` y `origen.grado_id` |
     * | `year`  | las del **mismo alcance en otro año**: `origen.year_id` |
     *
     * > **Es un tercer origen y no una ruta nueva, exactamente como
     * > `POST boletin-independiente/copiar`.** Y el motivo aquí es doble: una
     * > ruta `competencias/adoptar-men` sola en su familia no cambiaría nada de lo
     * > que hace, y en cambio **añadiría una ruta al censo** por una diferencia
     * > que es de dónde se leen tres columnas.
     *
     * ## La respuesta dice la POBLACIÓN, no `OK`
     *
     * Un «0 copiadas» tiene que poder distinguirse de «no revisé nada», y aquí hay
     * **cuatro** desenlaces:
     *
     * - `revisadas` — cuántos textos traía el origen. **Cero revisadas con
     *   `motivo` es «el MEN no cubre esto»**, que no es un fallo;
     * - `copiadas`;
     * - `saltadas_por_duplicado` — ya estaba, y es lo que hace que **adoptar dos
     *   veces no duplique**;
     * - `saltadas_sin_catalogo` — el origen no tenía nada que dar. Es el contador
     *   que **delata un origen mal dirigido**, igual que `saltadas_sin_plantilla`
     *   en `putSembrar`.
     *
     * ## Duplicado se decide por `codigo_men` primero y por el TEXTO después
     *
     * Y en ese orden, porque D11 dice que **lo adoptado es suyo y el colegio lo
     * edita**: el día que alguien corrija una tilde, comparar textos deja de casar
     * y una segunda adopción metería las veinticinco competencias del área otra
     * vez. El código sobrevive a la edición; el texto no. Para los orígenes
     * `grado` y `year`, que pueden traer filas escritas a mano y sin código, se
     * compara además el texto **normalizado** —sin tildes, sin mayúsculas y sin
     * espacios de sobra—, que es lo único que queda.
     *
     * @return array<string, mixed>
     */
    public function putCopiar(): array
    {
        Autoriza::exigir(
            Autoriza::puedeEditarPlantillaNotas($this->user),
            self::SIN_PERMISO
        );

        $yearId = (int) $this->user->year_id;

        $destino = $this->destinoDelCuerpo();
        $tipo = $this->tipoDeOrigen();

        [$candidatas, $contexto] = $tipo === 'men'
            ? $this->textosDelMen($destino)
            : $this->textosDeOtroAlcance($tipo, $yearId, $destino);

        $yaEstan = $this->delGrupo($yearId, $destino['materia_id'], $destino['grado_id'], $destino['alumno_id']);
        $codigos = [];
        $textos = [];

        foreach ($yaEstan as $competencia) {
            if ($competencia->codigo_men !== null) {
                $codigos[$competencia->codigo_men] = true;
            }

            $textos[CatalogoDelMen::normalizar((string) $competencia->definicion)] = true;
        }

        $conteo = [
            'revisadas' => count($candidatas),
            'copiadas' => 0,
            'saltadas_por_duplicado' => 0,
            'saltadas_sin_catalogo' => $candidatas === [] ? 1 : 0,
        ];

        $orden = $this->siguienteOrden($yearId, $destino['materia_id'], $destino['grado_id'], $destino['alumno_id']);
        $ahora = Reloj::ahoraTexto();

        foreach ($candidatas as $candidata) {
            $codigo = $candidata['codigo_men'];
            $normal = CatalogoDelMen::normalizar($candidata['definicion']);

            if (($codigo !== null && isset($codigos[$codigo])) || isset($textos[$normal])) {
                $conteo['saltadas_por_duplicado']++;

                continue;
            }

            DB::table('competencias')->insert([
                'year_id' => $yearId,
                'materia_id' => $destino['materia_id'],
                'grado_id' => $destino['grado_id'],
                'alumno_id' => $destino['alumno_id'],
                'definicion' => $candidata['definicion'],
                'orden' => $orden++,
                'codigo_men' => $codigo,
                'created_by' => (int) $this->user->user_id,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ]);

            if ($codigo !== null) {
                $codigos[$codigo] = true;
            }

            $textos[$normal] = true;
            $conteo['copiadas']++;
        }

        /*
         * **Una línea de auditoría y no una por competencia**, y se escribe
         * **siempre**, también con `copiadas = 0`: «alguien lo apretó y no pasó
         * nada» es exactamente el suceso que se va a investigar. Es el criterio de
         * `PlantillaNotasController::putSembrar`, y por lo mismo — lo que tiene
         * consecuencia es la copia, no el original, así que el CRUD de arriba no
         * lleva auditoría y ésta sí.
         */
        Auditoria::registrar()
            ->crear('competencia')
            ->en(year: $yearId)
            ->a($conteo + ['origen' => $tipo] + $contexto + $destino)
            ->resumen(sprintf(
                'Copió competencias desde %s: %d de %d revisadas',
                $tipo,
                $conteo['copiadas'],
                $conteo['revisadas']
            ))
            ->guardar();

        return $conteo + [
            'origen' => ['tipo' => $tipo] + $contexto,
            'destino' => $destino,
        ];
    }

    private const SIN_PERMISO = 'No tiene permiso para editar las competencias del colegio.';

    /**
     * El tope de `definicion`.
     *
     * La columna es `text` —65.535 bytes— así que esto **no** existe para que
     * quepa: existe para que un cliente que mande medio megabyte reciba un 422 con
     * el número delante en vez de un texto recortado en silencio, que es lo que
     * MySQL hace aquí por no estar en modo estricto. El enunciado más largo del
     * catálogo del MEN que esta tanda empaqueta está muy por debajo.
     */
    private const MAXIMO = 2000;

    /**
     * Lo que le tocaría a UN alumno, con el `<=>` que el `= NULL` deja mudo.
     *
     * @return array<string, mixed>
     */
    private function lasDelAlumno(int $yearId, int $alumnoId): array
    {
        $periodoId = Request::has('periodo_id')
            ? $this->idObligatorio('periodo_id', 422)
            : (int) $this->user->periodo_id;

        $alcance = BoletinIndependiente::alcance($alumnoId, $periodoId);

        $filtros = ['c.year_id = ?', 'c.alumno_id <=> ?'];
        $valores = [$yearId, $alcance];

        if (Request::has('materia_id')) {
            $filtros[] = 'c.materia_id = ?';
            $valores[] = $this->idObligatorio('materia_id');
        }

        /*
         * El grado sale de la matrícula del alumno y no del cuerpo: preguntárselo
         * al cliente dejaría que una llamada mal armada le enseñara a un alumno de
         * 6.º las competencias de 11.º sin que nada fallara.
         */
        $grado = DB::selectOne(
            'SELECT g.grado_id
               FROM matriculas m
               JOIN grupos g ON g.id = m.grupo_id AND g.deleted_at IS NULL
              WHERE m.alumno_id = ? AND m.deleted_at IS NULL AND g.year_id = ?
                AND m.estado IN ("MATR", "ASIS", "PREM")
              ORDER BY m.id DESC LIMIT 1',
            [$alumnoId, $yearId]
        );

        $gradoId = $grado === null ? null : (int) $grado->grado_id;

        // **D25: acumulan, no compiten.** `grado_id IS NULL` es «para todos los
        // grados» y se suma a las del grado, ordenadas por `orden`.
        $filtros[] = '(c.grado_id IS NULL'.($gradoId === null ? ')' : ' OR c.grado_id = ?)');
        if ($gradoId !== null) {
            $valores[] = $gradoId;
        }

        $filas = DB::select(
            'SELECT c.id, c.definicion, c.orden, c.materia_id, c.grado_id, c.alumno_id, c.codigo_men
               FROM competencias c
              WHERE '.implode(' AND ', $filtros).' AND c.deleted_at IS NULL
              ORDER BY c.materia_id, c.grado_id IS NOT NULL, c.orden, c.id',
            $valores
        );

        return [
            'year_id' => $yearId,
            'alumno_id' => $alumnoId,
            'periodo_id' => $periodoId,
            'grado_id' => $gradoId,
            // Lo que se comparó con `<=>`: `null` es «va con el grupo».
            'alcance' => $alcance,
            'independiente' => $alcance !== null,
            'competencias' => $this->conSusNombres($filas),
        ];
    }

    /**
     * @return array{materia_id: int, grado_id: ?int, alumno_id: ?int}
     */
    private function destinoDelCuerpo(): array
    {
        $destino = Request::input('destino');

        if (! is_array($destino) || ! array_key_exists('materia_id', $destino)) {
            abort(422, '`destino` tiene que traer al menos `materia_id`.');
        }

        $materiaId = $this->comoId($destino['materia_id'] ?? null, 'destino.materia_id');

        if ($materiaId === null) {
            abort(422, '`destino.materia_id` no es un identificador.');
        }

        $this->materiaDelColegio($materiaId, 422);

        $gradoId = $this->comoId($destino['grado_id'] ?? null, 'destino.grado_id');
        if ($gradoId !== null) {
            $this->gradoDelColegio($gradoId, 422);
        }

        $alumnoId = $this->comoId($destino['alumno_id'] ?? null, 'destino.alumno_id');
        if ($alumnoId !== null) {
            $this->alumnoDelColegio($alumnoId);
        }

        return ['materia_id' => $materiaId, 'grado_id' => $gradoId, 'alumno_id' => $alumnoId];
    }

    private function tipoDeOrigen(): string
    {
        $origen = Request::input('origen');
        $tipo = is_array($origen) ? ($origen['tipo'] ?? null) : null;

        if (! in_array($tipo, ['men', 'grado', 'year'], true)) {
            abort(422, '`origen.tipo` tiene que ser "men", "grado" o "year".');
        }

        return (string) $tipo;
    }

    /**
     * Los textos del MEN que le tocan al destino.
     *
     * ## Tres cosas que este método decide, y las tres tienen motivo
     *
     * **1. `destino.grado_id` es obligatorio aquí y no en los otros dos orígenes.**
     * El catálogo del MEN está organizado por **conjunto de grados**, así que sin
     * grado no hay conjunto y no hay nada que traer. Adoptar «para todos los
     * grados» mezclaría 1.º con 11.º, que es justo lo que el documento separa.
     *
     * **2. Por defecto se adoptan `enunciado` y `eje`, no los `estandar`.** Es el
     * grano de competencia: cinco o seis filas. Con los estándares dentro, adoptar
     * Inglés de 10.º-11.º metería **49 filas** donde el colegio quería cinco, y la
     * pantalla que D11 existe para que se use sería la primera que nadie vuelve a
     * abrir. `origen.tipos` lo cambia a propósito.
     *
     * **3. `origen.codigos` adopta EXACTAMENTE lo que el colegio marcó.** Es lo que
     * convierte los 523 textos del fichero en algo usable: la pantalla enseña la
     * lista con sus casillas y manda los códigos de las marcadas. Sin esta clave,
     * «adoptar» sería siempre «adoptar todo», y el que quisiera tres de los cinco
     * pensamientos tendría que **borrar dos después**, que es trabajo que esta
     * entrega existe para quitar.
     *
     * Un código que no esté en el área y el conjunto del destino es **422 y no se
     * ignora**: ignorarlo daría un `copiadas` más bajo de lo pedido sin decir por
     * qué, que es la clase de silencio que este contrato evita en todas partes.
     *
     * @param  array{materia_id: int, grado_id: ?int, alumno_id: ?int}  $destino
     * @return array{0: list<array{definicion: string, codigo_men: ?string}>, 1: array<string, mixed>}
     */
    private function textosDelMen(array $destino): array
    {
        if ($destino['grado_id'] === null) {
            abort(422, 'Para adoptar del MEN hace falta `destino.grado_id`: su catálogo va por '
                .'conjuntos de grados (1.º-3.º, 4.º-5.º, 6.º-7.º, 8.º-9.º, 10.º-11.º), '
                .'así que sin grado no hay conjunto que traer.');
        }

        $materia = $this->materiaDelColegio($destino['materia_id'], 422);
        $grado = $this->gradoDelColegio($destino['grado_id'], 422);

        $origen = Request::input('origen');
        $areaPedida = is_array($origen) ? ($origen['area'] ?? null) : null;

        if ($areaPedida !== null && CatalogoDelMen::area((string) $areaPedida) === null) {
            abort(422, '`origen.area` no es un área del catálogo del MEN.');
        }

        $emparejamiento = $areaPedida !== null
            ? ['area' => (string) $areaPedida, 'motivo' => 'a_mano', 'por' => null]
            : $this->areaDeLaMateria($materia);

        $conjunto = CatalogoDelMen::conjuntoDeGrado($grado->abrev, $grado->nombre);

        $tipos = $this->tiposPedidos($origen);

        $competencias = $conjunto['conjunto'] === null
            ? []
            : CatalogoDelMen::competencias($emparejamiento['area'], $conjunto['conjunto'], $tipos);

        $codigos = is_array($origen) && array_key_exists('codigos', $origen)
            ? $this->codigosPedidos($origen['codigos'])
            : null;

        if ($codigos !== null) {
            $disponibles = array_column(
                CatalogoDelMen::competencias($emparejamiento['area'], $conjunto['conjunto']),
                null,
                'codigo'
            );

            $competencias = [];

            foreach ($codigos as $codigo) {
                if (! array_key_exists($codigo, $disponibles)) {
                    abort(422, "`origen.codigos` trae `{$codigo}`, que no es del catálogo del MEN "
                        .'para esta materia y este grado.');
                }

                $competencias[] = $disponibles[$codigo];
            }
        }

        $candidatas = array_map(fn ($c) => [
            'definicion' => $c['definicion'],
            'codigo_men' => $c['codigo'],
        ], $competencias);

        return [$candidatas, [
            'area_men' => $emparejamiento['area'],
            'conjunto' => $conjunto['conjunto'],
            'tipos' => $codigos === null ? $tipos : null,
            'codigos' => $codigos,
            'motivo' => $this->motivoDelCatalogo($emparejamiento, $conjunto, $competencias),
        ]];
    }

    /**
     * Qué granos del catálogo se piden. Sin `origen.tipos`, los adoptables.
     *
     * @param  mixed  $origen
     * @return list<string>
     */
    private function tiposPedidos($origen): array
    {
        if (! is_array($origen) || ! array_key_exists('tipos', $origen)) {
            return CatalogoDelMen::ADOPTABLES;
        }

        $pedidos = $origen['tipos'];

        if (! is_array($pedidos) || $pedidos === []) {
            abort(422, '`origen.tipos` tiene que ser una lista de "enunciado", "eje" o "estandar".');
        }

        foreach ($pedidos as $tipo) {
            if (! in_array($tipo, ['enunciado', 'eje', 'estandar'], true)) {
                abort(422, '`origen.tipos` sólo admite "enunciado", "eje" y "estandar".');
            }
        }

        return array_values(array_unique($pedidos));
    }

    /**
     * @param  mixed  $valor
     * @return list<string>
     */
    private function codigosPedidos($valor): array
    {
        if (! is_array($valor) || $valor === []) {
            abort(422, '`origen.codigos` tiene que ser una lista de códigos del catálogo del MEN.');
        }

        $codigos = [];

        foreach ($valor as $codigo) {
            if (! is_string($codigo) || trim($codigo) === '') {
                abort(422, '`origen.codigos` trae algo que no es un código.');
            }

            $codigos[] = trim($codigo);
        }

        return array_values(array_unique($codigos));
    }

    /**
     * Los textos de otras competencias del colegio: otro grado del mismo año, o el
     * mismo alcance de otro año.
     *
     * @param  array{materia_id: int, grado_id: ?int, alumno_id: ?int}  $destino
     * @return array{0: list<array{definicion: string, codigo_men: ?string}>, 1: array<string, mixed>}
     */
    private function textosDeOtroAlcance(string $tipo, int $yearId, array $destino): array
    {
        $origen = Request::input('origen');
        $origen = is_array($origen) ? $origen : [];

        $deYear = $yearId;

        if ($tipo === 'year') {
            $deYear = $this->comoId($origen['year_id'] ?? null, 'origen.year_id')
                ?? abort(422, '`origen.year_id` hace falta para copiar de otro año.');

            $existe = DB::table('years')->where('id', $deYear)->whereNull('deleted_at')->exists();

            if (! $existe) {
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

        $deAlumno = array_key_exists('alumno_id', $origen)
            ? $this->comoId($origen['alumno_id'], 'origen.alumno_id')
            : null;

        /*
         * **El origen no puede ser el destino.** Copiar un grupo sobre sí mismo
         * daría `revisadas = N`, `saltadas_por_duplicado = N` y `copiadas = 0`, o
         * sea un 200 que parece que funcionó y no hizo nada. 422 con el motivo.
         */
        if ($deYear === $yearId
            && $deMateria === $destino['materia_id']
            && $deGrado === $destino['grado_id']
            && $deAlumno === $destino['alumno_id']) {
            abort(422, 'El origen y el destino son el mismo grupo: no hay nada que copiar.');
        }

        $filas = $this->delGrupo($deYear, $deMateria, $deGrado, $deAlumno);

        $candidatas = array_map(fn ($c) => [
            'definicion' => (string) $c->definicion,
            'codigo_men' => $c->codigo_men === null ? null : (string) $c->codigo_men,
        ], $filas);

        return [$candidatas, [
            'year_id' => $deYear,
            'materia_id' => $deMateria,
            'grado_id' => $deGrado,
            'alumno_id' => $deAlumno,
        ]];
    }

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
                .'Escriba las competencias una vez y cópielas cada año con `origen.tipo = "year"`.';
        }

        if ($emparejamiento['area'] === null) {
            return 'No se reconoció a qué área del MEN corresponde esta materia. Los Estándares '
                .'Básicos llegan a Lenguaje, Matemáticas, Ciencias Naturales, Ciencias Sociales, '
                .'Competencias Ciudadanas e Inglés; si es una de ellas, mande `area` a mano.';
        }

        return 'El catálogo del MEN no trae competencias para esta área en este conjunto de grados.';
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

    /**
     * Las competencias vivas de un grupo exacto. **Los tres `<=>`** son lo que
     * hace que «para todos los grados» y «para todo el grupo» —los dos `NULL`—
     * sean un grupo como cualquier otro y no un agujero.
     *
     * @return list<object>
     */
    private function delGrupo(int $yearId, int $materiaId, ?int $gradoId, ?int $alumnoId): array
    {
        return array_values(DB::select(
            'SELECT id, definicion, orden, materia_id, grado_id, alumno_id, codigo_men
               FROM competencias
              WHERE year_id = ? AND materia_id = ? AND grado_id <=> ? AND alumno_id <=> ?
                AND deleted_at IS NULL
              ORDER BY orden, id',
            [$yearId, $materiaId, $gradoId, $alumnoId]
        ));
    }

    private function siguienteOrden(int $yearId, int $materiaId, ?int $gradoId, ?int $alumnoId): int
    {
        $fila = DB::selectOne(
            'SELECT COALESCE(MAX(orden), -1) + 1 AS siguiente
               FROM competencias
              WHERE year_id = ? AND materia_id = ? AND grado_id <=> ? AND alumno_id <=> ?
                AND deleted_at IS NULL',
            [$yearId, $materiaId, $gradoId, $alumnoId]
        );

        return (int) ($fila->siguiente ?? 0);
    }

    /**
     * Una competencia con su grupo dentro: lo que devuelven las escrituras, para
     * que el front pueda repintar la lista sin volver a pedirla entera.
     *
     * @return array<string, mixed>
     */
    private function competenciaConSuGrupo(int $id): array
    {
        $competencia = DB::selectOne(
            'SELECT id, definicion, orden, materia_id, grado_id, alumno_id, codigo_men
               FROM competencias WHERE id = ?',
            [$id]
        );

        $gradoId = $competencia->grado_id === null ? null : (int) $competencia->grado_id;
        $alumnoId = $competencia->alumno_id === null ? null : (int) $competencia->alumno_id;

        $hermanas = $this->delGrupo(
            (int) $this->user->year_id,
            (int) $competencia->materia_id,
            $gradoId,
            $alumnoId
        );

        return $this->conSusNombres([$competencia])[0] + [
            'grupo' => [
                'materia_id' => (int) $competencia->materia_id,
                'grado_id' => $gradoId,
                'alumno_id' => $alumnoId,
                'competencias' => count($hermanas),
            ],
        ];
    }

    /**
     * Las filas con el nombre de su materia y de su grado al lado.
     *
     * **Dos consultas para toda la lista, no una por fila**: los catálogos son de
     * decenas de filas y esta pantalla puede traer las competencias de un colegio
     * entero. Es lo mismo que hace `PlantillaNotasController::catalogo`.
     *
     * @param  list<object>  $filas
     * @return list<array<string, mixed>>
     */
    private function conSusNombres(array $filas): array
    {
        $materias = $this->catalogo('materias');
        $grados = $this->catalogo('grados');

        return array_map(function ($fila) use ($materias, $grados) {
            $materiaId = (int) $fila->materia_id;
            $gradoId = $fila->grado_id === null ? null : (int) $fila->grado_id;

            return [
                'id' => (int) $fila->id,
                'definicion' => $fila->definicion,
                'orden' => (int) $fila->orden,
                'materia_id' => $materiaId,
                'materia' => $materias[$materiaId] ?? null,
                'grado_id' => $gradoId,
                'grado' => $gradoId === null ? null : ($grados[$gradoId] ?? null),
                'alumno_id' => $fila->alumno_id === null ? null : (int) $fila->alumno_id,
                'codigo_men' => $fila->codigo_men,
            ];
        }, $filas);
    }

    /**
     * Los grupos `(materia, grado, alumno)` que hay, con cuántas competencias
     * lleva cada uno. Es lo que hace útil la pantalla del catálogo: dice de un
     * vistazo **qué materias del colegio están todavía vacías**, que es la mitad
     * que D11 existe para resolver.
     *
     * @param  list<object>  $filas
     * @return list<array<string, mixed>>
     */
    private function gruposDe(array $filas): array
    {
        $materias = $this->catalogo('materias');
        $grados = $this->catalogo('grados');
        $grupos = [];

        foreach ($filas as $fila) {
            $materiaId = (int) $fila->materia_id;
            $gradoId = $fila->grado_id === null ? null : (int) $fila->grado_id;
            $alumnoId = $fila->alumno_id === null ? null : (int) $fila->alumno_id;
            $clave = $materiaId.'|'.$gradoId.'|'.$alumnoId;

            $grupos[$clave] ??= [
                'materia_id' => $materiaId,
                'materia' => $materias[$materiaId] ?? null,
                'grado_id' => $gradoId,
                'grado' => $gradoId === null ? null : ($grados[$gradoId] ?? null),
                'alumno_id' => $alumnoId,
                'competencias' => 0,
            ];

            $grupos[$clave]['competencias']++;
        }

        return array_values($grupos);
    }

    /**
     * El nombre legible de un catálogo, por id. **`materias.materia` y
     * `grados.nombre` se llaman distinto**, que es la trampa que ya está escrita
     * en `PlantillaNotasController`.
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
     * La competencia, comprobando que es **del año del token**.
     *
     * El año no es decoración: sin él, cualquiera con el permiso editaría el plan
     * de área de un año cerrado desde el token de éste. Es la regla 1 de la §4 por
     * su otra cara.
     */
    private function competenciaDelAnio($id, int $codigo = 404): object
    {
        if (! $this->esIdentificador($id)) {
            abort($codigo, 'Esa competencia no existe.');
        }

        $fila = DB::selectOne(
            'SELECT id, definicion, orden, materia_id, grado_id, alumno_id, codigo_men
               FROM competencias
              WHERE id = ? AND year_id = ? AND deleted_at IS NULL',
            [(int) $id, (int) $this->user->year_id]
        );

        if ($fila === null) {
            abort($codigo, 'Esa competencia no existe.');
        }

        return $fila;
    }

    private function materiaDelColegio(int $id, int $codigo = 404): object
    {
        $fila = DB::selectOne('SELECT id, materia FROM materias WHERE id = ? AND deleted_at IS NULL', [$id]);

        if ($fila === null) {
            abort($codigo, 'Esa materia no existe.');
        }

        return $fila;
    }

    private function gradoDelColegio(int $id, int $codigo = 404): object
    {
        $fila = DB::selectOne(
            'SELECT id, nombre, abrev FROM grados WHERE id = ? AND deleted_at IS NULL',
            [$id]
        );

        if ($fila === null) {
            abort($codigo, 'Ese grado no existe.');
        }

        return $fila;
    }

    private function alumnoDelColegio(int $id): void
    {
        $existe = DB::table('alumnos')->where('id', $id)->whereNull('deleted_at')->exists();

        if (! $existe) {
            abort(422, '`alumno_id` apunta a alguien que no existe.');
        }
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

    /**
     * Un id que **tiene que venir**, y que falte es **422 y no 404**.
     *
     * No es lo mismo «pediste el catálogo de una materia que no existe» —404, la
     * fila no está— que «pediste el catálogo y no dijiste de qué» —422, la
     * petición está mal armada—. El 404 mandaría al front a buscar una materia
     * borrada que nadie borró.
     */
    private function idObligatorio(string $campo, int $codigo = 422): int
    {
        $valor = Request::input($campo);

        if (! $this->esIdentificador($valor)) {
            abort($codigo, "`{$campo}` hace falta y tiene que ser un identificador.");
        }

        return (int) $valor;
    }

    /** Un id que puede no venir o venir vacío. El vacío es `null`, no un error. */
    private function idOpcional(string $campo): ?int
    {
        if (! Request::has($campo)) {
            return null;
        }

        return $this->comoId(Request::input($campo), $campo);
    }

    private function idConDefecto(string $campo, int $porDefecto): int
    {
        if (! Request::has($campo)) {
            return $porDefecto;
        }

        return $this->idObligatorio($campo);
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
}
