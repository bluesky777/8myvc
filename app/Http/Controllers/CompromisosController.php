<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResuelveElUsuario;
use App\Mail\CompromisoAcademico;
use App\Support\Autoriza;
use App\Support\CorreoDeLaCuenta;
use App\Support\PlantillaDelCompromiso;
use App\Support\Reloj;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Request;

/**
 * **El compromiso académico de un alumno**: el coordinador lo propone, lo crea, lo
 * entrega, lo cierra y notifica cómo terminó.
 *
 * Diseño entero en `myvc_front/COMPROMISOS-ACADEMICOS.md` §3 y §5; el porqué de cada
 * columna, en el docblock de `2026_09_22_200000_el_compromiso_academico`. Su hermano
 * `CompromisosConfigController` guarda lo que el colegio escribe **una vez al año**;
 * aquí vive lo que **le pasa a un alumno concreto**, y las dos mitades se leen juntas.
 *
 * El contrato que manda es `myvc_front/app2/src/app/datos/compromisos.ts`, clase
 * `CompromisosDeAlumnosApi`: el front ya está escrito contra él, así que los nombres
 * de los campos de aquí **no son una elección de este fichero**.
 *
 * ## Lo que NO tiene, que es la decisión más importante del módulo
 *
 * **Ni endpoint, ni estado, ni campo de sanción, de matrícula condicional o de pérdida
 * del cupo.** §1.3 del diseño: eso es una sanción disciplinaria y la Corte
 * Constitucional sólo la valida tras debido proceso completo (T-1207/00, T-360/08,
 * T-004/24). Un compromiso que la lleve dentro y no venga de un proceso disciplinario
 * es exactamente el papel que se cae en tutela. El colegio puede escribir lo que quiera
 * en su plantilla; lo que el software no hace es **ofrecer** esa salida.
 *
 * ## Las siete rutas, y por qué las escrituras son tan pequeñas
 *
 *     PUT  compromisos/candidatos            a quién le saldría uno. NO ESCRIBE.
 *     POST compromisos                       crear, uno o en lote. Congela.
 *     GET  compromisos                       el tablero del coordinador
 *     GET  compromisos/{id}                  el compromiso con sus items: el papel
 *     PUT  compromisos/{id}/entregar         R2: se avisó, y consta el día
 *     PUT  compromisos/{id}/cerrar           el colegio ya sabe el resultado
 *     PUT  compromisos/{id}/entregar-resultado  R4: la familia ya sabe cómo terminó
 *
 * Las cuatro escrituras de abajo **ponen una fecha y un canal, y nada más**. No es
 * pobreza de API: §1.4 dice que lo que blinda al colegio no es la firma sino el rastro
 * fechado, así que cada una de esas fechas es una prueba y ninguna se puede reescribir.
 * Por eso todas comprueban el estado antes de escribir y contestan 422 si ya estaba
 * puesta: un `UPDATE` idempotente aquí borraría la fecha que demuestra el plazo.
 *
 * ## Lo que se CONGELA al crear, y por qué es media clase
 *
 * `texto`, `nota_al_crear`, `cantidad_perdidas`, `regla` y `porcentaje_ano`. Si el papel
 * se reimprime en diciembre y recalcula, dice otra cosa que la copia que firmó el padre
 * en septiembre — y entonces el documento no prueba nada. Es el mismo argumento del art.
 * 16 que sostuvo `notas_finales.nota_original` en las nivelaciones.
 *
 * Consecuencia práctica que conviene no olvidar: **ninguna lectura de este controlador
 * vuelve a mirar `notas_finales` para pintar un compromiso ya creado.** El único sitio
 * donde se leen notas vivas es `putCandidatos` —que no escribe— y `postStore` —que las
 * copia—. Y `sugerido` (D4), que es una propuesta y va aparte, marcada como tal.
 *
 * ## SQL crudo, `Reloj` y validación a mano
 *
 * Mismo camino que `CompromisosConfigController` y que
 * `Informes\FormulariosInscripcionController`: `DB::select`/`DB::insert` con parámetros
 * y nada de Eloquent. Y la hora sale de `Reloj::ahoraTexto()` y **no de `NOW()`**:
 * `config/database.php` no fija la zona de la sesión, así que `NOW()` es el reloj del
 * servidor —dieciséis cuentas de cPanel distintas— y estas columnas acabarían con una
 * hora distinta en cada colegio sin nada en la fila que lo dijera.
 */
class CompromisosController extends Controller
{
    use ResuelveElUsuario;

    /** El vocabulario de `compromisos.estado`, en el orden del flujo (§5). */
    private const ESTADOS = ['borrador', 'entregado', 'cerrado', 'notificado'];

    /** El de `compromisos.entrega_canal` y `resultado_canal`. Mismo que el contrato. */
    private const CANALES = ['push', 'correo', 'papel', 'app'];

    /** El de `compromisos.regla`, igual que `config_compromiso.regla`. */
    private const REGLAS = ['asignatura', 'area'];

    /**
     * Qué matrículas cuentan como «está en el colegio».
     *
     * Copiado de `Informes\NotasPerdidasController:68`, que es la pantalla hermana —las
     * notas perdidas de un periodo— y por tanto la que ya contestó esta pregunta. **No
     * se copia de `ActasEvaluacionController`**, que a propósito no filtra por estado:
     * el acta es del año entero y cuenta también a quien se retiró en mayo. El
     * compromiso es lo contrario — un plan de apoyo para quien todavía está.
     */
    private const ESTADOS_DE_MATRICULA_VIVA = ['MATR', 'ASIS', 'PREM'];

    /**
     * Cuántos periodos tiene un año, para el porcentaje del papel.
     *
     * `CalendarioDePeriodos::CANTIDAD` dice lo mismo y es de donde sale (§2.4): son
     * siempre cuatro, así que periodo N = N × 25 %. **No sale de fechas**:
     * `periodos.fecha_inicio` y `fecha_fin` están en `NULL` desde 2021.
     */
    private const PERIODOS_DEL_ANIO = 4;

    /**
     * Cuántas matrículas admite una creación en lote.
     *
     * No es una restricción de negocio, es el tope de una transacción: con corte 1 en
     * `simonbolivar` salen 505 candidatos (§6 D1) y crear los 505 de golpe son ~505
     * `INSERT` de cabecera más sus items dentro de un `BEGIN`. 500 deja pasar el peor
     * lote real de un colegio y corta el `matricula_ids` de 20.000 que llegaría de un
     * cliente roto antes de que abra la transacción.
     */
    private const TOPE_DEL_LOTE = 500;

    /** `compromisos.cantidad_perdidas` es `unsignedTinyInteger`: no cabe más. */
    private const TOPE_DE_PERDIDAS = 255;

    /** `compromiso_items.observacion` es `varchar(255)`, y MySQL aquí trunca en vez de lanzar. */
    private const LARGO_OBSERVACION = 255;

    /* ══════════════════════════════════════════════════════════════════════════════
     * EL COORDINADOR
     * ══════════════════════════════════════════════════════════════════════════════ */

    /**
     * **A quién le saldría compromiso, antes de crear nada. No escribe una sola fila.**
     *
     * `PUT` y no `GET` porque lleva cuerpo, que es el patrón de la casa para las
     * consultas con filtros (`notas/lote`, `boletines/detailed-notas`).
     *
     * ## EL RECUENTO SE HACE VIVO, Y ÉSA ES LA DECISIÓN DE FONDO
     *
     * `matriculas.cant_asign_perdidas` y `cant_areas_perdidas` **ya existen y no se
     * usan aquí**. Parece el camino obvio —están persistidas, indexadas y contadas— y es
     * el error que §2.6 deja documentado: esas dos columnas **sólo se refrescan al
     * ejecutar `PUT promovidos/calcular-grupo`** (`PromovidosController:152-159`), así
     * que no son un dato vivo. Un compromiso creado leyéndolas puede estar contando el
     * periodo pasado — y lo que se congela en el papel firmado sería un número de otro
     * mes.
     *
     * Y hay una segunda razón, más dura: **son del AÑO y esto es de un PERIODO.** Es
     * exactamente lo que ya razonó `riesgo-del-grupo.ts:36-48` para el semáforo — *«tres
     * asignaturas perdidas en el primer periodo no son tres perdidas al cerrar: hay tres
     * periodos por delante para levantarlas»*—. El compromiso existe **para evitar**
     * llegar al corte del año, así que no puede contarse con el corte del año.
     *
     * De dónde sale entonces: `notas_finales.nota` del periodo pedido, que es **la
     * definitiva** —la misma que imprime el boletín (§2.5)— comparada con
     * `years.nota_minima_aceptada`. No `notas.nota`, que es `int` y es la casilla suelta
     * que teclea el docente.
     *
     * ## REGLA Y CORTE: los del año, salvo que la petición traiga los suyos
     *
     * Salen de `config_compromiso` (§2.1 y §6 D1/D2), pero el cuerpo puede mandar
     * `corte` y `regla`: es el coordinador bajando el número **en pantalla** para ver a
     * cuántos alcanza, sin tocar la configuración del año. La respuesta devuelve **los
     * que de verdad se usaron**, porque si no la pantalla no puede rotularse.
     *
     * ## EL COSTE, que hay que decirlo antes de que alguien pulse sin grupo
     *
     * Sin `grupo_id` esto es el colegio entero. Son **dos consultas** y ninguna trae
     * filas que no hagan falta:
     *
     *   - con `regla = 'asignatura'`, el `WHERE nf.nota < minima` va **dentro del SQL**,
     *     así que vuelven sólo los pares (alumno, asignatura) perdidos del periodo;
     *   - con `regla = 'area'`, el promedio y el corte van en `GROUP BY … HAVING`, así
     *     que vuelven sólo las áreas perdidas y ya promediadas.
     *
     * Lo que **no** se puede hacer es traer las definitivas enteras y contar en PHP: en
     * un colegio de 3.594 matrículas por una docena de asignaturas eso son ~43.000 filas
     * por consulta para quedarse con unas pocas miles. El orden de magnitud del
     * resultado está medido (§2.6, `simonbolivar`): **634 matrículas con al menos una
     * asignatura perdida y 505 con al menos un área perdida**, y por eso la pantalla
     * enseña el recuento **antes** del botón de lote.
     *
     * La parte cara de verdad es el `JOIN` a `notas_finales`, que sólo tiene índices de
     * una columna (`alumno_id`, `asignatura_id`, `periodo_id`) y ninguno por `periodo`.
     * Se ataca por `asignatura_id` —acotado por el grupo— y el `periodo` se filtra sobre
     * lo que sale. Con grupo es inmediato; sin grupo es un barrido del año, y es el
     * motivo por el que la pantalla pide grupo primero.
     */
    public function putCandidatos()
    {
        $user = $this->user;

        $year_id = $this->anioDeLaPeticion(Request::input('year_id'));
        $periodo = $this->periodoValidado(Request::input('periodo'));
        $grupo_id = $this->idOpcional(Request::input('grupo_id'), 'El grupo');

        $this->exigirQuePuedaMirar($user, $grupo_id);

        $config = $this->configDelAnio($year_id);
        $regla = $this->reglaDeLaPeticion(Request::input('regla'), $config);
        $corte = $this->corteDeLaPeticion(Request::input('corte'), $config);

        $candidatos = $this->contarPerdidas($year_id, $periodo, $grupo_id, $regla, $corte, $config);

        return [
            'year_id' => $year_id,
            'periodo' => $periodo,
            // Los de VERDAD, no los pedidos: la pantalla se rotula con esto.
            'regla' => $regla,
            'corte' => $corte,
            'primaria_activa' => (bool) $config['primaria_activa'],
            'candidatos' => $candidatos,
        ];
    }

    /**
     * **Crear, uno o en lote, por la misma ruta.**
     *
     * El botón individual manda una matrícula y el de grupo manda cien. Si fueran dos
     * rutas, la de una sola acabaría siendo la que se mantiene y la otra divergiría en
     * silencio.
     *
     * ## QUÉ SE CONGELA AQUÍ, que es todo el punto de la tabla
     *
     *   - `texto`: la plantilla del colegio **resuelta** —`compromiso_bloques` sobre los
     *     defectos de `PlantillaDelCompromiso`, con los marcadores ya sustituidos—. Si
     *     se leyera al imprimir, un colegio que retoque su plantilla en diciembre
     *     reescribiría un documento firmado en septiembre.
     *   - `nota_al_crear` por item: la definitiva del periodo. Es lo que el padre leyó.
     *   - `cantidad_perdidas` y `regla`: con qué se contó. Con el parágrafo de primaria
     *     encendido, `cantidad_perdidas` **no es el total del alumno** sino el de las dos
     *     materias que el colegio eligió, y esa configuración puede cambiar.
     *   - `porcentaje_ano`: `periodo × 25` (§2.4). Se guarda y no se calcula al imprimir
     *     porque un colegio que reordene su calendario no puede cambiar el porcentaje de
     *     un papel ya firmado.
     *
     * ## UNA MATRÍCULA QUE YA TIENE COMPROMISO DE ESE PERIODO SE CONTESTA, NO REVIENTA
     *
     * La migración no puso `unique (matricula_id, periodo)` y su cabecera explica por
     * qué: hay duplicados legítimos —el que se abre después de un «no niveló», el de área
     * junto al de asignatura— y un `1062` en mitad de un lote de 500 tiraría los 499
     * buenos. Así que la comprobación vive aquí, es una lectura indexada por
     * `compromisos_del_alumno`, y lo que sale es `omitidos`: una lista con el motivo, que
     * es lo que deja a la pantalla preguntar *«éste ya tiene uno, ¿lo abro igual?»*.
     *
     * `omitidos` es un campo **de más** respecto al contrato de TypeScript
     * (`{creados, ids}`), y eso es seguro: un `interface` de TS no prohíbe campos
     * extra. Quitarlo obligaría a inventar un 409 para un lote en el que 480 de 500
     * salieron bien.
     *
     * ## Y TODO DENTRO DE UNA TRANSACCIÓN
     *
     * Un compromiso sin sus items no es medio compromiso: es un papel que dice «pierde
     * 3» y no dice cuáles, o sea un documento que se imprime igual de bien y que no
     * prueba nada. Es el mismo argumento que `putBloques`.
     */
    public function postStore()
    {
        $user = $this->user;

        $year_id = $this->anioDeLaPeticion(Request::input('year_id'));
        $periodo = $this->periodoValidado(Request::input('periodo'));
        $matricula_ids = $this->matriculasValidadas(Request::input('matricula_ids'));

        $config = $this->configDelAnio($year_id);
        $regla = $this->reglaDeLaPeticion(Request::input('regla'), $config);
        $corte = $this->corteDeLaPeticion(Request::input('corte'), $config);

        // El plazo que el papel promete. Si la pantalla no lo manda, sale de
        // `config_compromiso.plazo_dias` contado desde hoy — ver `plazoDeLaPeticion`.
        [$plazo_desde, $plazo_hasta] = $this->plazoDeLaPeticion($config);

        /*
         * **Se recuenta, no se cree lo que manda el cliente.** El cuerpo trae
         * `matricula_ids` y nada más: ni notas, ni cuántas pierde, ni cuáles. Si esos
         * datos vinieran de la pantalla, el papel congelaría lo que dijera el navegador
         * —que puede llevar veinte minutos abierto, o estar manipulado— y lo que se
         * firma tiene que salir de la base en el mismo instante en que se escribe.
         *
         * Es la misma consulta de `putCandidatos`, acotada a estas matrículas, así que
         * el corte también vuelve a aplicarse: una matrícula que ya no llega al corte
         * sale por `omitidos` y no se le crea un papel vacío.
         */
        $candidatos = $this->contarPerdidas($year_id, $periodo, null, $regla, $corte, $config, $matricula_ids);

        $porMatricula = [];

        foreach ($candidatos as $candidato) {
            $porMatricula[$candidato['matricula_id']] = $candidato;
        }

        $bloques = $this->bloquesDelAnio($year_id);
        $colegio = $this->nombreDelColegio($year_id);
        $acudientes = $this->acudientesDe(array_column($candidatos, 'alumno_id'));

        /*
         * Las cinco columnas que rellenan las dos casillas que el papel prometía y nadie
         * podía escribir (`col_periodos` y `col_falta`). Se piden **fuera del bucle y por
         * grupo**, porque dentro serían cuatro consultas por candidato.
         */
        $congelado = $this->congeladoDelLote($candidatos, $year_id, $periodo, $regla);

        $ahora = Reloj::ahoraTexto();
        $creados = [];
        $omitidos = [];

        DB::transaction(function () use (
            $matricula_ids, $porMatricula, $year_id, $periodo, $regla, $config, $bloques,
            $colegio, $acudientes, $congelado, $plazo_desde, $plazo_hasta, $user, $ahora,
            &$creados, &$omitidos
        ) {
            foreach ($matricula_ids as $matricula_id) {
                $candidato = $porMatricula[$matricula_id] ?? null;

                if ($candidato === null) {
                    // O no es del año pedido, o no está viva, o no llega al corte. Las
                    // tres son «hoy no le toca», y ninguna es un error del coordinador.
                    $omitidos[] = ['matricula_id' => $matricula_id, 'razon' => 'sin_perdidas'];

                    continue;
                }

                if ($candidato['ya_tiene'] !== null) {
                    $omitidos[] = [
                        'matricula_id' => $matricula_id,
                        'razon' => 'ya_tiene',
                        'compromiso_id' => $candidato['ya_tiene']['id'],
                    ];

                    continue;
                }

                $cantidad = min(count($candidato['perdidas']), self::TOPE_DE_PERDIDAS);

                $texto = $this->textoCongelado($bloques, $config, $regla, [
                    'alumno' => $candidato['alumno'],
                    'documento_alumno' => $candidato['documento'],
                    'grupo' => $candidato['grupo'],
                    'periodo' => $periodo,
                    'cuantas' => $cantidad,
                    'plazo_desde' => $plazo_desde,
                    'plazo_hasta' => $plazo_hasta,
                    'colegio' => $colegio,
                    'acudiente' => $acudientes[$candidato['alumno_id']] ?? null,
                ]);

                DB::insert('INSERT INTO compromisos
                        (matricula_id, year_id, periodo, regla, cantidad_perdidas, porcentaje_ano,
                         texto, plazo_desde, plazo_hasta, estado, creado_por, created_at, updated_at)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
                    [
                        $matricula_id, $year_id, $periodo, $regla, $cantidad,
                        $this->porcentajeDelAnio($periodo),
                        $texto, $plazo_desde, $plazo_hasta, 'borrador',
                        $user->user_id, $ahora, $ahora,
                    ]);

                $compromiso_id = (int) DB::getPdo()->lastInsertId();

                $suyo = $congelado[$matricula_id] ?? ['faltas' => [], 'notas' => [], 'hay_periodo' => false];

                foreach ($candidato['perdidas'] as $perdida) {
                    // La clave es la asignatura o el área, según la regla: es lo que
                    // identifica este renglón en las dos tandas de arriba.
                    $clave = $perdida['asignatura_id'] ?? $perdida['area_id'];

                    /*
                     * **Sin `periodo_id` del año, `NULL`; con él y sin filas, `0`.** Son
                     * dos afirmaciones distintas: «no se pudo contar» y «no faltó
                     * ninguna clase». La segunda va impresa en un papel que se firma, así
                     * que sólo se escribe cuando de verdad se contó.
                     */
                    $faltas = $suyo['hay_periodo'] ? ($suyo['faltas'][$clave] ?? 0) : null;

                    $notas = $suyo['notas'][$clave] ?? [
                        'per1_nota' => null, 'per2_nota' => null,
                        'per3_nota' => null, 'per4_nota' => null,
                    ];

                    /*
                     * **Para N = el periodo del compromiso manda `nota_al_crear`, no la
                     * consulta.** Es el único fallo de este bloque que el papel enseñaría
                     * en la cara: la columna del periodo y la nota que justifica el
                     * renglón, una al lado de la otra, diciendo números distintos. Salen
                     * de la misma lectura por construcción y no por confianza.
                     */
                    $notas['per'.$periodo.'_nota'] = $perdida['nota'];

                    DB::insert('INSERT INTO compromiso_items
                            (compromiso_id, asignatura_id, area_id, profesor_id, nota_al_crear,
                             faltas_al_crear, per1_nota, per2_nota, per3_nota, per4_nota,
                             created_at, updated_at)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
                        [
                            $compromiso_id,
                            $perdida['asignatura_id'],
                            $perdida['area_id'],
                            $perdida['profesor_id'],
                            $perdida['nota'],
                            $faltas,
                            $notas['per1_nota'], $notas['per2_nota'],
                            $notas['per3_nota'], $notas['per4_nota'],
                            $ahora, $ahora,
                        ]);
                }

                $creados[] = $compromiso_id;
            }
        });

        return [
            'creados' => count($creados),
            'ids' => $creados,
            'omitidos' => $omitidos,
        ];
    }

    /**
     * **El tablero del coordinador.** Los compromisos del año, con sus items.
     *
     * Los items viajan siempre, y no es un capricho del servidor: el `interface
     * Compromiso` del contrato los declara obligatorios, y el recuento de §5.8 —*«12 de
     * 18 con veredicto»*— se saca de ahí. Van en **una segunda consulta y no en un
     * `JOIN`**: con `JOIN` la cabecera —que lleva un `text` con el papel entero— se
     * repetiría una vez por renglón.
     *
     * ## EL ALCANCE LO PONE QUIÉN PREGUNTA (§3.5)
     *
     * Coordinación ve el colegio entero. Los demás ven **lo suyo**, y «lo suyo» son dos
     * cosas a la vez que caben en una condición: el grupo del que se es titular, y los
     * compromisos donde se tiene algún item. Un docente que no es titular de nada ve
     * exactamente los alumnos que tenían compromiso con él, que es lo que pedía el
     * encargo.
     */
    public function getIndex()
    {
        $user = $this->user;

        $year_id = $this->anioDeLaPeticion(Request::input('year_id'));

        $donde = ['c.year_id = ?'];
        $datos = [$year_id];

        $periodo = Request::input('periodo');

        if ($periodo !== null && $periodo !== '') {
            $donde[] = 'c.periodo = ?';
            $datos[] = $this->periodoValidado($periodo);
        }

        $grupo_id = $this->idOpcional(Request::input('grupo_id'), 'El grupo');

        if ($grupo_id !== null) {
            $donde[] = 'g.id = ?';
            $datos[] = $grupo_id;
        }

        $estado = Request::input('estado');

        if ($estado !== null && $estado !== '') {
            if (! in_array($estado, self::ESTADOS, true)) {
                abort(422, 'Ese estado de compromiso no existe.');
            }

            $donde[] = 'c.estado = ?';
            $datos[] = $estado;
        }

        $min_perdidas = Request::input('min_perdidas');

        if ($min_perdidas !== null && $min_perdidas !== '') {
            $donde[] = 'c.cantidad_perdidas >= ?';
            $datos[] = $this->enteroEntre($min_perdidas, 0, self::TOPE_DE_PERDIDAS,
                'El mínimo de perdidas no es un número válido.');
        }

        /*
         * «Notificados y sin firmar» — §6 D8, opción (a): el plazo vence solo y el
         * expediente queda *notificado sin acuse*, con la constancia del envío como
         * prueba. Pero la pantalla tiene que poder enseñar **cuántos** son, porque a
         * ésos el colegio los llama por teléfono. Éste es ese filtro.
         */
        if ($this->banderaPedida(Request::input('sin_acuse'))) {
            $donde[] = 'c.resultado_entregado_at IS NOT NULL AND c.resultado_acuse_at IS NULL';
        }

        $alcance = $this->alcanceDeLaConsulta($user);

        if ($alcance !== null) {
            $donde[] = $alcance['sql'];
            $datos = array_merge($datos, $alcance['datos']);
        }

        $filas = DB::select($this->consultaDeCabeceras().'
            WHERE '.implode(' AND ', $donde).'
            ORDER BY g.orden, a.apellidos, a.nombres, c.periodo, c.id', $datos);

        $items = $this->itemsDe(array_map(static fn ($f): int => (int) $f->id, $filas));
        $acudientes = $this->acudientesDe(array_map(static fn ($f): int => (int) $f->alumno_id, $filas));

        $compromisos = [];

        foreach ($filas as $fila) {
            $compromisos[] = $this->pintarCompromiso(
                $fila,
                $items[(int) $fila->id] ?? [],
                $acudientes[(int) $fila->alumno_id] ?? null
            );
        }

        return ['compromisos' => $compromisos];
    }

    /**
     * **El compromiso con sus items: lo que necesita el papel.**
     *
     * Y con `sugerido` en cada item, que es lo único que este método lee vivo. D4: si el
     * alumno ya niveló por la vía normal, se **propone** el resultado leído de
     * `notas_finales.nota_nivelacion` y el docente lo confirma. Propuesto no es escrito
     * —el item sigue en su estado hasta que alguien firma el veredicto—, porque el
     * veredicto es del docente y tiene que poder discrepar de la nota.
     */
    public function getShow($id)
    {
        $user = $this->user;

        $id = $this->idObligatorio($id, 'El compromiso');

        $alcance = $this->alcanceDeLaConsulta($user);

        $donde = 'c.id = ?';
        $datos = [$id];

        if ($alcance !== null) {
            $donde .= ' AND '.$alcance['sql'];
            $datos = array_merge($datos, $alcance['datos']);
        }

        $fila = DB::selectOne($this->consultaDeCabeceras().' WHERE '.$donde, $datos);

        if (! $fila) {
            // **404 y no 403 cuando el alcance es lo que lo esconde**, que es lo mismo
            // que hace el reseteo de contraseña con un correo que no existe: distinguir
            // «no existe» de «no es tuyo» deja averiguar, probando ids, qué alumnos del
            // colegio tienen compromiso. Es un dato de menores.
            abort(404, 'Ese compromiso no existe o no es de su alcance.');
        }

        $items = $this->itemsDe([$id], true);
        $acudiente = $this->acudientesDe([(int) $fila->alumno_id])[(int) $fila->alumno_id] ?? null;

        return $this->pintarCompromiso($fila, $items[$id] ?? [], $acudiente);
    }

    /**
     * **R2: se entregó, y consta el día y el canal.**
     *
     * `entregado_at` es una prueba, así que se escribe **una vez**. Un segundo `PUT`
     * contesta 422 con la fecha que ya hay, en vez de moverla: el día que el colegio
     * tenga que sostener ante una tutela que avisó *antes* del plazo, esta fecha es todo
     * lo que tiene.
     *
     * `canal` va explícito y se guarda porque el papel imprime **por dónde** se entregó
     * (D7: la aceptación digital acompaña a la firma en papel, no la sustituye), y «se
     * entregó en mano» y «se mandó un push» no valen lo mismo ante una reclamación.
     */
    public function putEntregar($id)
    {
        $user = $this->user;

        $this->exigirCoordinacion($user, 'entregar un compromiso académico');

        $id = $this->idObligatorio($id, 'El compromiso');
        $canal = $this->canalValidado(Request::input('canal'));

        $compromiso = $this->compromisoOFalla($id);

        if ($compromiso->entregado_at !== null) {
            abort(422, 'Este compromiso ya se entregó el '
                .(Reloj::humana($compromiso->entregado_at) ?? $compromiso->entregado_at)
                .'. Esa fecha es la prueba de la entrega y no se reescribe.');
        }

        $ahora = Reloj::ahoraTexto();

        DB::update('UPDATE compromisos
            SET entregado_at=?, entrega_canal=?, estado=?, updated_at=?
            WHERE id=? AND entregado_at IS NULL',
            [$ahora, $canal, 'entregado', $ahora, $id]);

        $this->avisarALaFamilia($id, CompromisoAcademico::ENTREGA);

        return 'Entregado';
    }

    /**
     * **El coordinador cierra: el colegio ya sabe cómo terminó.**
     *
     * ## CERRAR NO EXIGE QUE ESTÉN TODOS LOS VEREDICTOS, y es una decisión
     *
     * D3 lo dejó dicho al revés de como suena: *«el docente de la asignatura pone el
     * suyo, y el titular ve el de todos y rellena los que falten al cierre»*, y añade
     * qué evita — *«que un docente que no contesta bloquee el cierre para siempre»*—.
     * Exigirlos aquí sería devolverle al colegio justo ese bloqueo, y con un agravante:
     * **el que se queda sin poder cerrar no es el docente que no contestó, es la
     * familia**, porque hasta que no se cierra no se notifica el resultado (R4) y hasta
     * que no se notifica no empieza a correr el plazo para reclamar.
     *
     * Lo que **no** se hace es rellenarlos solo. Un `UPDATE … SET resultado='en_espera'`
     * sobre los que faltan parece inofensivo y destruye R3: `resultado IS NULL` significa
     * *«el docente no ha contestado»*, que no es lo mismo que *«asistió y quedó en
     * espera»*. La primera es una pregunta abierta y la segunda es un dictamen — y el
     * papel los imprimiría igual.
     *
     * Así que se cierra, no se toca ningún item, y **la respuesta dice cuántos quedaron
     * sin veredicto** para que el coordinador lo vea en el mismo clic. El contrato pide
     * un texto (`putTexto`), y un texto es exactamente lo que cabe aquí.
     */
    public function putCerrar($id)
    {
        $user = $this->user;

        $this->exigirCoordinacion($user, 'cerrar un compromiso académico');

        $id = $this->idObligatorio($id, 'El compromiso');

        $compromiso = $this->compromisoOFalla($id);

        if ($compromiso->entregado_at === null) {
            // Cerrar lo que no se entregó sería certificar el resultado de un apoyo que
            // la familia nunca supo que existía. El orden de §5 no es decorativo.
            abort(422, 'Este compromiso todavía no se ha entregado; no se puede cerrar.');
        }

        if ($compromiso->cerrado_at !== null) {
            abort(422, 'Este compromiso ya está cerrado.');
        }

        $cuenta = DB::selectOne('SELECT COUNT(*) AS total,
                SUM(CASE WHEN resultado IS NULL THEN 1 ELSE 0 END) AS sin_veredicto
            FROM compromiso_items WHERE compromiso_id=?', [$id]);

        $total = (int) ($cuenta->total ?? 0);
        $faltan = (int) ($cuenta->sin_veredicto ?? 0);

        $ahora = Reloj::ahoraTexto();

        DB::update('UPDATE compromisos
            SET cerrado_at=?, cerrado_por=?, estado=?, updated_at=?
            WHERE id=? AND cerrado_at IS NULL',
            [$ahora, $user->user_id, 'cerrado', $ahora, $id]);

        if ($faltan > 0) {
            return 'Cerrado con '.$faltan.' de '.$total
                .' renglones sin veredicto: quedan como «sin contestar» y así saldrán en el papel.';
        }

        return 'Cerrado';
    }

    /**
     * **R4: el resultado vuelve, y con él arranca el plazo de reclamación.**
     *
     * Es el paso que ningún formato del país sabe hacer hoy (§1.5) y el que convierte
     * este módulo en prueba: el mismo papel, con la misma fecha de origen, diciendo qué
     * pasó.
     *
     * ## `reclamacion_vence` SE CALCULA AQUÍ Y SE GUARDA
     *
     * Sale de **este** instante y no de `cerrado_at`: el plazo para reclamar no empieza
     * a correr el día en que el docente escribió el veredicto, sino **el día en que el
     * acudiente se entera**. Sin esta fecha el colegio no puede sostener que el plazo
     * venció.
     *
     * Y se guarda en vez de calcularse al imprimir porque los días hábiles dependen del
     * calendario, y recalcular esta fecha en diciembre daría una distinta de la impresa.
     */
    public function putEntregarResultado($id)
    {
        $user = $this->user;

        $this->exigirCoordinacion($user, 'notificar el resultado de un compromiso académico');

        $id = $this->idObligatorio($id, 'El compromiso');
        $canal = $this->canalValidado(Request::input('canal'));

        $compromiso = $this->compromisoOFalla($id);

        if ($compromiso->cerrado_at === null) {
            abort(422, 'Este compromiso todavía no está cerrado: no hay resultado que notificar.');
        }

        if ($compromiso->resultado_entregado_at !== null) {
            abort(422, 'El resultado de este compromiso ya se notificó el '
                .(Reloj::humana($compromiso->resultado_entregado_at) ?? $compromiso->resultado_entregado_at)
                .'. De esa fecha cuelga el plazo para reclamar y no se reescribe.');
        }

        $config = $this->configDelAnio((int) $compromiso->year_id);

        $ahora = Reloj::ahoraTexto();
        $vence = $this->sumarDiasHabiles($ahora, (int) $config['dias_reclamacion']);

        DB::update('UPDATE compromisos
            SET resultado_entregado_at=?, resultado_canal=?, reclamacion_vence=?, estado=?, updated_at=?
            WHERE id=? AND resultado_entregado_at IS NULL',
            [$ahora, $canal, $vence, 'notificado', $ahora, $id]);

        $this->avisarALaFamilia($id, CompromisoAcademico::RESULTADO);

        return 'Resultado notificado. El plazo para reclamar vence el '.$vence.'.';
    }

    /* ══════════════════════════════════════════════════════════════════════════════
     * EL RECUENTO, QUE ES EL CORAZÓN DEL MÓDULO
     * ══════════════════════════════════════════════════════════════════════════════ */

    /**
     * Cuenta las perdidas del periodo y devuelve a quién le saldría compromiso.
     *
     * Devuelve la lista **ya en la forma del contrato** (`CandidatoDeCompromiso`),
     * porque la usan dos llamantes —`putCandidatos` para enseñarla y `postStore` para
     * congelarla— y el día que las dos formas se separen es el día en que el papel dice
     * una cosa y la pantalla otra.
     *
     * @param  list<int>|null  $soloEstas  acota a unas matrículas concretas (el lote)
     * @return list<array<string, mixed>>
     */
    /**
     * **Las cinco columnas que el papel promete y que nadie podía rellenar**:
     * `faltas_al_crear` y `per1_nota`…`per4_nota`, congeladas al crear.
     *
     * Existen porque `config_compromiso.col_periodos` («columna con las notas de cada
     * periodo») y `col_falta` («columna de inasistencias») ya se ofrecían en la pantalla
     * de la plantilla y **no había de dónde sacarlas**. Buscarlas al imprimir sería leer
     * datos vivos en una hoja congelada: la nota de un periodo ya nivelado no es la que
     * se imprimió, y las faltas de hoy no son las de septiembre.
     *
     * ## SE CONGELAN AUNQUE EL COLEGIO TENGA LOS DOS INTERRUPTORES APAGADOS
     *
     * La configuración dice **qué se imprime**, no **qué se guarda**. Un colegio que
     * encienda `col_periodos` en noviembre tiene que poder hacerlo sin que los
     * compromisos de septiembre salgan con la casilla vacía para siempre, y sin
     * recalcular nada — que es justo lo que esta tabla viene a impedir.
     *
     * ## UNA TANDA POR GRUPO Y NO CUATRO POR ALUMNO
     *
     * Las consultas son **por matrícula** —dependen del alumno y de su grupo— pero se
     * piden **por grupo con `alumno_id IN (…)`**: un lote de 500 candidatos serían 2.000
     * consultas dentro de una sola transacción, y con ~30 grupos son 60.
     *
     * Eso obliga a un cambio de forma en las faltas por área, que va dicho porque no es
     * obvio: la versión por alumno usa `LEFT JOIN ausencias` para que **un área sin
     * faltas dé fila con 0**. Con varios alumnos a la vez ese `LEFT JOIN` no puede
     * llevar el `alumno_id` de la fila que no existe, así que se pregunta al revés
     * —desde `ausencias`— y **«no hay fila» se convierte en `0` aquí en PHP**, que es
     * exactamente el mismo resultado. Lo que sí sigue distinguiéndose es el otro caso:
     * sin `periodo_id` no se escribe `0`, se escribe `NULL`.
     *
     * @param  list<array<string, mixed>>  $candidatos
     * @return array<int, array{faltas: array<int, int>, notas: array<int, array<string, ?float>>, hay_periodo: bool}>
     */
    private function congeladoDelLote(array $candidatos, int $year_id, int $periodo, string $regla): array
    {
        /*
         * **`ausencias` no tiene `numero`: cuelga de `periodos`.** Una lectura por POST y
         * no una por candidato. Si el año no tiene ese periodo —un colegio de tres—
         * `faltas_al_crear` se queda en `NULL` y **nunca en 0**: «no se pudo contar» y
         * «no faltó nunca» son dos afirmaciones distintas, y la segunda va impresa en un
         * papel que un acudiente firma.
         */
        $periodoFila = DB::selectOne('SELECT id FROM periodos
            WHERE year_id=? AND numero=? AND deleted_at IS NULL', [$year_id, $periodo]);

        $periodo_id = $periodoFila === null ? null : (int) $periodoFila->id;

        $porGrupo = [];

        foreach ($candidatos as $candidato) {
            $porGrupo[$candidato['grupo_id']][$candidato['alumno_id']] = $candidato['alumno_id'];
        }

        $notas = [];
        $faltas = [];

        foreach ($porGrupo as $grupo_id => $alumnos) {
            $alumnos = array_values($alumnos);
            $huecos = implode(',', array_fill(0, count($alumnos), '?'));

            foreach ($this->notasDeLosCuatroPeriodos((int) $grupo_id, $alumnos, $huecos, $regla) as $fila) {
                $notas[(int) $fila->alumno_id][(int) $fila->clave] = [
                    'per1_nota' => $fila->per1_nota === null ? null : (float) $fila->per1_nota,
                    'per2_nota' => $fila->per2_nota === null ? null : (float) $fila->per2_nota,
                    'per3_nota' => $fila->per3_nota === null ? null : (float) $fila->per3_nota,
                    'per4_nota' => $fila->per4_nota === null ? null : (float) $fila->per4_nota,
                ];
            }

            if ($periodo_id === null) {
                continue;
            }

            foreach ($this->faltasDelPeriodo((int) $grupo_id, $alumnos, $huecos, $periodo_id, $regla) as $fila) {
                // Las filas de portería llevan `asignatura_id IS NULL` y no son de ninguna
                // asignatura ni de ninguna área: no estorban, pero tampoco entran.
                if ($fila->clave === null) {
                    continue;
                }

                $faltas[(int) $fila->alumno_id][(int) $fila->clave] = (int) ($fila->faltas ?? 0);
            }
        }

        $salida = [];

        foreach ($candidatos as $candidato) {
            $salida[$candidato['matricula_id']] = [
                'faltas' => $faltas[$candidato['alumno_id']] ?? [],
                'notas' => $notas[$candidato['alumno_id']] ?? [],
                'hay_periodo' => $periodo_id !== null,
            ];
        }

        return $salida;
    }

    /**
     * Las definitivas de los cuatro periodos, por alumno y por asignatura o por área.
     *
     * Es la consulta del recuento **sin el filtro de la mínima** —aquí no se busca lo
     * perdido, se busca el renglón entero de la tabla— y con los cuatro periodos a la vez
     * en vez de cuatro consultas.
     *
     * ## EL DESEMPATE DE LOS DUPLICADOS, que no lo elige el motor
     *
     * `notas_finales` tiene duplicados reales: en `simonbolivar` hay un
     * `(alumno_id, asignatura_id, periodo_id)` con dos filas. `Grupo.php:389` ya decidió
     * con cuál se queda el resto del sistema —`ORDER BY nf.id DESC`, la última escrita—
     * y aquí se repite **explícitamente**. Un `MAX(nota)` elegiría la nota más alta, que
     * es otra cosa y además favorece al alumno en un papel que puede acabar en una tutela.
     *
     * El idioma es `SUBSTRING_INDEX(GROUP_CONCAT(… ORDER BY nf.id DESC), ',', 1)` y no
     * una función de ventana **a propósito**: parte del parque corre **MySQL 5.7**, donde
     * `ROW_NUMBER()` no existe. `GROUP_CONCAT` salta los `NULL` solo, así que un periodo
     * sin definitiva vuelve `NULL` y no una cadena vacía.
     *
     * ## EN ÁREAS ES `AVG` Y NO SE DESEMPATA, y también es una decisión
     *
     * El área es el **promedio simple** de sus asignaturas, igual que en `areasPerdidas`,
     * y tiene que salir del mismo `AVG` que produjo `nota_al_crear`: si aquí se
     * desduplicara y allí no, `perN_nota` y `nota_al_crear` discreparían en el mismo
     * renglón del papel. Y **no se filtra `asg.profesor_id IS NOT NULL`**, por lo mismo
     * que ya razona `areasPerdidas`: el área dejaría de cuadrar con la del boletín.
     *
     * Y `notas_finales` **no tiene `deleted_at`**: no se le pone uno.
     *
     * @param  list<int>  $alumnos
     * @return list<object>
     */
    private function notasDeLosCuatroPeriodos(int $grupo_id, array $alumnos, string $huecos, string $regla): array
    {
        if ($regla === 'area') {
            return DB::select('SELECT nf.alumno_id, ar.id AS clave,
                    AVG(CASE WHEN nf.periodo = 1 THEN nf.nota END) AS per1_nota,
                    AVG(CASE WHEN nf.periodo = 2 THEN nf.nota END) AS per2_nota,
                    AVG(CASE WHEN nf.periodo = 3 THEN nf.nota END) AS per3_nota,
                    AVG(CASE WHEN nf.periodo = 4 THEN nf.nota END) AS per4_nota
                FROM asignaturas asg
                INNER JOIN materias mat ON mat.id = asg.materia_id AND mat.deleted_at IS NULL
                INNER JOIN areas ar ON ar.id = mat.area_id AND ar.deleted_at IS NULL
                INNER JOIN notas_finales nf ON nf.asignatura_id = asg.id
                    AND nf.alumno_id IN ('.$huecos.')
                WHERE asg.grupo_id = ? AND asg.deleted_at IS NULL
                GROUP BY nf.alumno_id, ar.id', array_merge($alumnos, [$grupo_id]));
        }

        $ultima = static fn (int $n): string => 'SUBSTRING_INDEX(GROUP_CONCAT(
                CASE WHEN nf.periodo = '.$n.' THEN nf.nota END ORDER BY nf.id DESC), ",", 1)
            AS per'.$n.'_nota';

        return DB::select('SELECT nf.alumno_id, asg.id AS clave,
                '.$ultima(1).', '.$ultima(2).', '.$ultima(3).', '.$ultima(4).'
            FROM asignaturas asg
            INNER JOIN notas_finales nf ON nf.asignatura_id = asg.id
                AND nf.alumno_id IN ('.$huecos.')
            WHERE asg.grupo_id = ? AND asg.deleted_at IS NULL
            GROUP BY nf.alumno_id, asg.id', array_merge($alumnos, [$grupo_id]));
    }

    /**
     * Las inasistencias del periodo, por alumno y por asignatura o por área.
     *
     * ## CUÁL DE LOS DOS NÚMEROS DEL SISTEMA ES ÉSTE
     *
     * En el proyecto conviven dos criterios sobre estas mismas filas, y
     * `AusenciasController:124-129` ya lo tenía escrito —*«unos endpoints cuentan filas
     * con `COUNT(*)` y otros suman `cantidad_ausencia`»*—:
     *
     *     A) COUNT(au.id) WHERE cantidad_ausencia > 0   → boletín FINAL, promovidos
     *     B) SUM(CASE WHEN tipo='ausencia' THEN cantidad_ausencia ELSE 0 END)
     *                                                   → boletín DE PERIODO
     *
     * **Se congela el B**, literal de
     * `BoletinPorCompetenciasController::faltasPorAsignatura` (`:770-777`) y de
     * `Nota::alumnoPeriodoDetalle` (`Nota.php:461-475`). No es elegancia: el compromiso
     * es de **un periodo**, y el documento con el que tiene que cuadrar es el boletín de
     * **ese** periodo, que es el que la familia ya leyó. El A es el criterio del boletín
     * *final*, que es otro papel.
     *
     * Son **sesiones de clase**, nunca días ni porcentaje. Y las tardanzas no entran: la
     * columna se llama `faltas_al_crear`, y una tardanza no es una falta.
     *
     * @param  list<int>  $alumnos
     * @return list<object>
     */
    private function faltasDelPeriodo(int $grupo_id, array $alumnos, string $huecos, int $periodo_id, string $regla): array
    {
        if ($regla === 'area') {
            return DB::select('SELECT au.alumno_id, ar.id AS clave,
                    SUM(CASE WHEN au.tipo = "ausencia" THEN au.cantidad_ausencia ELSE 0 END) AS faltas
                FROM ausencias au
                INNER JOIN asignaturas asg ON asg.id = au.asignatura_id
                    AND asg.deleted_at IS NULL AND asg.grupo_id = ?
                INNER JOIN materias mat ON mat.id = asg.materia_id AND mat.deleted_at IS NULL
                INNER JOIN areas ar ON ar.id = mat.area_id AND ar.deleted_at IS NULL
                WHERE au.alumno_id IN ('.$huecos.') AND au.periodo_id = ? AND au.deleted_at IS NULL
                GROUP BY au.alumno_id, ar.id', array_merge([$grupo_id], $alumnos, [$periodo_id]));
        }

        return DB::select('SELECT alumno_id, asignatura_id AS clave,
                SUM(CASE WHEN tipo = "ausencia" THEN cantidad_ausencia ELSE 0 END) AS faltas
            FROM ausencias
            WHERE alumno_id IN ('.$huecos.') AND periodo_id = ? AND deleted_at IS NULL
            GROUP BY alumno_id, asignatura_id', array_merge($alumnos, [$periodo_id]));
    }

    private function contarPerdidas(
        int $year_id,
        int $periodo,
        ?int $grupo_id,
        string $regla,
        int $corte,
        array $config,
        ?array $soloEstas = null
    ): array {
        $minima = $this->notaMinimaDe($year_id);

        $donde = ['g.year_id = ?', 'g.deleted_at IS NULL', 'm.deleted_at IS NULL', 'al.deleted_at IS NULL'];
        $datos = [$year_id];

        $donde[] = 'm.estado IN ("'.implode('","', self::ESTADOS_DE_MATRICULA_VIVA).'")';

        if ($grupo_id !== null) {
            $donde[] = 'g.id = ?';
            $datos[] = $grupo_id;
        }

        if ($soloEstas !== null) {
            $donde[] = 'm.id IN ('.implode(',', array_fill(0, count($soloEstas), '?')).')';
            $datos = array_merge($datos, $soloEstas);
        }

        $filas = $regla === 'area'
            ? $this->areasPerdidas($periodo, $minima, $donde, $datos)
            : $this->asignaturasPerdidas($periodo, $minima, $donde, $datos);

        return $this->armarCandidatos($filas, $year_id, $periodo, $corte, $regla, $config);
    }

    /**
     * Los pares (matrícula, asignatura) perdidos del periodo.
     *
     * `nf.nota < ?` **dentro del SQL**: lo que no llega aquí no se transporta, no se
     * ordena en PHP y no ocupa memoria. Es la mitad del coste de este módulo.
     *
     * `LEFT JOIN profesores` y no `INNER`: `asignaturas.profesor_id` es anulable —un año
     * recién nacido las tiene todas así— y una asignatura sin docente **sí** genera item;
     * lo que pasa es que el veredicto lo rellena el titular al cierre (D3). Un `INNER`
     * aquí haría desaparecer del papel justo las asignaturas que nadie está atendiendo.
     *
     * @param  list<string>  $donde
     * @param  list<mixed>  $datos
     * @return list<object>
     */
    private function asignaturasPerdidas(int $periodo, float $minima, array $donde, array $datos): array
    {
        return DB::select('SELECT m.id AS matricula_id, m.alumno_id, g.id AS grupo_id, g.grado_id,
                asg.id AS asignatura_id, NULL AS area_id, asg.materia_id, asg.profesor_id,
                mat.materia AS nombre,
                TRIM(CONCAT(COALESCE(p.nombres, ""), " ", COALESCE(p.apellidos, ""))) AS profesor,
                nf.nota
            FROM matriculas m
            INNER JOIN alumnos al ON al.id = m.alumno_id
            INNER JOIN grupos g ON g.id = m.grupo_id
            INNER JOIN asignaturas asg ON asg.grupo_id = g.id AND asg.deleted_at IS NULL
            INNER JOIN materias mat ON mat.id = asg.materia_id AND mat.deleted_at IS NULL
            LEFT JOIN profesores p ON p.id = asg.profesor_id AND p.deleted_at IS NULL
            INNER JOIN notas_finales nf ON nf.alumno_id = m.alumno_id
                AND nf.asignatura_id = asg.id AND nf.periodo = ?
            WHERE '.implode(' AND ', $donde).'
              AND nf.nota < ?
            ORDER BY m.id, nombre', array_merge([$periodo], $datos, [$minima]));
    }

    /**
     * Las áreas perdidas del periodo, ya promediadas.
     *
     * ## ES PROMEDIO SIMPLE, Y ESO ESTÁ MEDIDO
     *
     * `Area::agrupar_asignaturas` tiene dos ramas: ponderada por
     * `asignaturas.porcentaje_area` si **todas** las asignaturas del par (grupo, área)
     * tienen peso y suman 100, y **promedio simple** si no. Hoy `porcentaje_area` está
     * en `NULL` en los dieciséis colegios (§2.1), así que en la práctica manda el
     * promedio, y eso es lo que hace este `AVG`.
     *
     * **La rama ponderada no se replica aquí a propósito.** Reproducirla en SQL exigiría
     * traerse los pesos del grupo y decidir dentro de la consulta si «todos o ninguno»,
     * que es justo la clase de regla que se copia mal. El día que un colegio reparta
     * pesos de verdad, el delator es que el área del compromiso deje de cuadrar con la
     * del boletín — y la respuesta será llamar a `Area::agrupar_asignaturas` por alumno,
     * que es N consultas y por eso no se hace hoy.
     *
     * ## Y NO SE FILTRA POR `profesor_id IS NOT NULL`
     *
     * `Area.php:38` sí lo hace al agrupar; aquí no. Allí es para no pintar en el boletín
     * un área de la que nadie reporta; aquí quitar las asignaturas sin docente cambiaría
     * el **promedio** del área, y entonces el número del papel no cuadraría con el del
     * boletín del mismo periodo. Que un área no tenga docente es un problema del colegio,
     * no una razón para que la nota del alumno salga distinta en dos documentos.
     *
     * `materias` viaja como lista concatenada porque la necesita el parágrafo de primaria
     * (D5): sin ella no hay forma de saber si esta área contiene lenguaje o matemáticas.
     *
     * @param  list<string>  $donde
     * @param  list<mixed>  $datos
     * @return list<object>
     */
    private function areasPerdidas(int $periodo, float $minima, array $donde, array $datos): array
    {
        return DB::select('SELECT m.id AS matricula_id, m.alumno_id, g.id AS grupo_id, g.grado_id,
                NULL AS asignatura_id, ar.id AS area_id, NULL AS profesor_id, NULL AS profesor,
                ar.nombre AS nombre,
                AVG(nf.nota) AS nota,
                GROUP_CONCAT(DISTINCT asg.materia_id) AS materias
            FROM matriculas m
            INNER JOIN alumnos al ON al.id = m.alumno_id
            INNER JOIN grupos g ON g.id = m.grupo_id
            INNER JOIN asignaturas asg ON asg.grupo_id = g.id AND asg.deleted_at IS NULL
            INNER JOIN materias mat ON mat.id = asg.materia_id AND mat.deleted_at IS NULL
            INNER JOIN areas ar ON ar.id = mat.area_id AND ar.deleted_at IS NULL
            INNER JOIN notas_finales nf ON nf.alumno_id = m.alumno_id
                AND nf.asignatura_id = asg.id AND nf.periodo = ?
            WHERE '.implode(' AND ', $donde).'
            GROUP BY m.id, m.alumno_id, g.id, g.grado_id, ar.id, ar.nombre
            HAVING AVG(nf.nota) < ?
            ORDER BY m.id, nombre', array_merge([$periodo], $datos, [$minima]));
    }

    /**
     * Agrupa las perdidas por matrícula, aplica el parágrafo de primaria y el corte, y
     * cruza con lo ya creado.
     *
     * @param  list<object>  $filas
     * @return list<array<string, mixed>>
     */
    private function armarCandidatos(
        array $filas,
        int $year_id,
        int $periodo,
        int $corte,
        string $regla,
        array $config
    ): array {
        $gradosDePrimaria = [];
        $materiasDelParagrafo = [];

        if ($config['primaria_activa']) {
            /*
             * **Encendido sin las dos materias es 422, no «cuenta cero».** Lo pide D5
             * con esas palabras, y la pantalla de reglas ya lo impide al guardar
             * (`CompromisosConfigController::materiasDePrimariaValidadas`). Se repite
             * aquí porque una fila editada a mano en phpMyAdmin se saltaría aquel 422 y
             * este recuento devolvería **cero candidatos en 1.º, 2.º y 3.º** — que es
             * indistinguible de «este año nadie perdió nada».
             */
            if ($config['primaria_materia_1_id'] === null || $config['primaria_materia_2_id'] === null) {
                abort(422, 'El parágrafo de primaria está encendido pero no tiene elegidas las dos '
                    .'materias: sin ellas el recuento de 1.º a 3.º daría cero y parecería que nadie pierde.');
            }

            $gradosDePrimaria = $this->gradosDelParagrafoDePrimaria();

            $materiasDelParagrafo = [
                (int) $config['primaria_materia_1_id'],
                (int) $config['primaria_materia_2_id'],
            ];
        }

        $porMatricula = [];

        foreach ($filas as $fila) {
            $matricula_id = (int) $fila->matricula_id;

            if (! isset($porMatricula[$matricula_id])) {
                $porMatricula[$matricula_id] = [
                    'matricula_id' => $matricula_id,
                    'alumno_id' => (int) $fila->alumno_id,
                    'grupo_id' => (int) $fila->grupo_id,
                    'grado_id' => (int) $fila->grado_id,
                    'perdidas' => [],
                    'por_paragrafo_primaria' => false,
                ];
            }

            $porMatricula[$matricula_id]['perdidas'][] = [
                'asignatura_id' => $fila->asignatura_id === null ? null : (int) $fila->asignatura_id,
                'area_id' => $fila->area_id === null ? null : (int) $fila->area_id,
                'nombre' => (string) $fila->nombre,
                'profesor_id' => $fila->profesor_id === null ? null : (int) $fila->profesor_id,
                'profesor' => ($fila->profesor ?? '') === '' ? null : (string) $fila->profesor,
                'nota' => round((float) $fila->nota, 4),
                // No viaja al front: es el dato con el que se decide el parágrafo.
                'materias' => $this->materiasDeLaPerdida($fila),
            ];
        }

        $candidatos = [];

        foreach ($porMatricula as $matricula) {
            /*
             * ── D5, EL PARÁGRAFO DE PRIMARIA ──────────────────────────────────────
             *
             * El SIEP del Bethel dice que en 1.º, 2.º y 3.º la promoción se juega sólo
             * en lenguaje y matemáticas. Con el interruptor encendido y el alumno en uno
             * de esos tres grados, **se descartan las demás perdidas** antes de comparar
             * con el corte.
             *
             * Y se filtra al CONTAR, no al promediar: con `regla = 'area'` el área
             * conserva su nota —que es la del boletín, calculada con todas sus
             * asignaturas— y lo único que cambia es si esa área entra en el recuento.
             * Filtrar antes del `AVG` daría un número que no está en ningún otro
             * documento del colegio.
             */
            $porParagrafo = $config['primaria_activa']
                && in_array($matricula['grado_id'], $gradosDePrimaria, true);

            if ($porParagrafo) {
                $matricula['perdidas'] = array_values(array_filter(
                    $matricula['perdidas'],
                    static fn (array $p): bool => count(array_intersect($p['materias'], $materiasDelParagrafo)) > 0
                ));
                $matricula['por_paragrafo_primaria'] = true;
            }

            if (count($matricula['perdidas']) < $corte) {
                continue;
            }

            $candidatos[] = $matricula;
        }

        if ($candidatos === []) {
            return [];
        }

        $ids = array_column($candidatos, 'matricula_id');
        $nombres = $this->datosDeLasMatriculas($ids);
        $yaTienen = $this->compromisosYaCreados($year_id, $periodo, $ids);

        $salida = [];

        foreach ($candidatos as $candidato) {
            $ficha = $nombres[$candidato['matricula_id']] ?? null;

            if ($ficha === null) {
                continue;
            }

            $salida[] = [
                'matricula_id' => $candidato['matricula_id'],
                'alumno_id' => $candidato['alumno_id'],
                'alumno' => $ficha['alumno'],
                'documento' => $ficha['documento'],
                'grupo_id' => $candidato['grupo_id'],
                'grupo' => $ficha['grupo'],
                'cantidad_perdidas' => count($candidato['perdidas']),
                'perdidas' => array_map(static function (array $p): array {
                    unset($p['materias']);

                    return $p;
                }, $candidato['perdidas']),
                'ya_tiene' => $yaTienen[$candidato['matricula_id']] ?? null,
                'por_paragrafo_primaria' => $candidato['por_paragrafo_primaria'],
                // `regla` no viaja por candidato —va en la cabecera de la respuesta—,
                // pero sí hace falta dentro de `postStore` para saber qué columna del
                // item rellenar; se deduce de `asignatura_id`/`area_id` y no se repite.
            ];
        }

        return $salida;
    }

    /**
     * De qué materias está hecha una perdida.
     *
     * Una perdida de asignatura es una materia; una de área son las que la componen, que
     * vienen concatenadas del `GROUP_CONCAT`. Las dos formas se reducen a una lista para
     * que el parágrafo de primaria se escriba una vez y no dos.
     *
     * @return list<int>
     */
    private function materiasDeLaPerdida(object $fila): array
    {
        if (isset($fila->materias) && $fila->materias !== null && $fila->materias !== '') {
            return array_map('intval', explode(',', (string) $fila->materias));
        }

        if (isset($fila->materia_id) && $fila->materia_id !== null) {
            return [(int) $fila->materia_id];
        }

        return [];
    }

    /**
     * **Los grados de 1.º, 2.º y 3.º — y cómo se sabe cuáles son, que no era obvio.**
     *
     * La cadena es `matriculas.grupo_id → grupos.grado_id → grados`, y ahí se acaba lo
     * fácil. Los dos caminos que uno escribiría primero están medidos y los dos son
     * falsos:
     *
     *   - **`grados.orden` NO es el número del grado.** Lo dice el propio repositorio en
     *     `Informes\ActasEvaluacionController:505` —*«`grados.orden` es un entero libre,
     *     sin garantía de ser el número del grado»*— y se comprueba en el docker: en
     *     `la_hermosa`, `simonbolivar` y `caz_zaragoza` Primero tiene `orden = 4`, porque
     *     los grados de preescolar ocupan del 1 al 3. Un `orden <= 3` seleccionaría
     *     prejardín, jardín y transición: **el parágrafo aplicado a los tres grados
     *     equivocados, y sin dar ningún error**.
     *   - **`grados.abrev` tampoco**, aunque hoy valga `'1'`, `'2'`, `'3'`: es un
     *     `varchar` que teclea el colegio, y en los mismos tres volcados preescolar lo
     *     usa con `'00A'`, `'Pree'`, `'OOC'` y `'Kin'`. Casar grados por texto libre es
     *     el fallo que se descubre en el colegio diecisiete.
     *
     * Lo que sí es estructura es `grados.nivel_educativo_id → niveles_educativos`, que es
     * el catálogo del MEN y está sembrado igual en los tres volcados que se pudieron
     * mirar (ids 1..4, `orden` 1..4, «Educación Básica Primaria» en el 2). Así que la
     * regla es: **el nivel cuyo `orden` es 2 —la básica primaria— y, dentro de él, los
     * tres grados de menor `orden`**. En los tres volcados eso da exactamente Primero,
     * Segundo y Tercero (ids 6, 7 y 8).
     *
     * ## Y SI EL COLEGIO NO TIENE ESO PUESTO, ES UN 422 Y NO UN SILENCIO
     *
     * `grados.nivel_educativo_id` **es anulable** y hay colegios con grados sin nivel
     * (`AlcanceDeLaPlantilla.php:75-76`). Si el nivel no se puede resolver, este método
     * no devuelve una lista vacía y sigue: devolver vacío sería **apagar el parágrafo sin
     * decírselo a nadie**, que es la peor de las tres salidas —el coordinador lo encendió
     * en la pantalla de reglas, vería el interruptor en «sí» y el recuento contando
     * todas las materias—. Es la misma doctrina que ya eligió la configuración: encender
     * el parágrafo sin elegir las dos materias da 422, no cuenta cero.
     *
     * @return list<int>
     */
    private function gradosDelParagrafoDePrimaria(): array
    {
        $filas = DB::select('SELECT g.id
            FROM grados g
            INNER JOIN niveles_educativos n ON n.id = g.nivel_educativo_id AND n.deleted_at IS NULL
            WHERE g.deleted_at IS NULL AND n.orden = 2
            ORDER BY g.orden, g.id
            LIMIT 3');

        if (count($filas) < 3) {
            abort(422, 'El parágrafo de primaria está encendido, pero los grados de este colegio '
                .'no dicen cuáles son los tres primeros de básica primaria: hay que asignarles su '
                .'nivel educativo antes de poder aplicarlo.');
        }

        return array_map(static fn ($f): int => (int) $f->id, $filas);
    }

    /**
     * Nombre, documento y grupo de unas matrículas. Sólo se pide para las que pasaron el
     * corte, así que el `IN` va acotado por el resultado y no por el colegio.
     *
     * @param  list<int>  $ids
     * @return array<int, array{alumno: string, documento: ?string, grupo: string}>
     */
    private function datosDeLasMatriculas(array $ids): array
    {
        $filas = DB::select('SELECT m.id AS matricula_id,
                TRIM(CONCAT(COALESCE(a.nombres, ""), " ", COALESCE(a.apellidos, ""))) AS alumno,
                a.documento, g.nombre AS grupo
            FROM matriculas m
            INNER JOIN alumnos a ON a.id = m.alumno_id
            INNER JOIN grupos g ON g.id = m.grupo_id
            WHERE m.id IN ('.implode(',', array_fill(0, count($ids), '?')).')', $ids);

        $salida = [];

        foreach ($filas as $fila) {
            $salida[(int) $fila->matricula_id] = [
                'alumno' => (string) $fila->alumno,
                'documento' => $fila->documento === null || $fila->documento === ''
                    ? null : (string) $fila->documento,
                'grupo' => (string) $fila->grupo,
            ];
        }

        return $salida;
    }

    /**
     * **El cruce con lo ya creado**, que es lo que evita dos papeles del mismo periodo.
     *
     * Lectura por `compromisos_del_alumno` —el índice `(matricula_id, periodo)` que la
     * migración puso en lugar del `unique`—. Si hay varios, gana **el último**: el que se
     * abrió después de un «no niveló» es el vigente, y es el que la pantalla tiene que
     * ofrecer abrir.
     *
     * @param  list<int>  $ids
     * @return array<int, array{id: int, estado: string}>
     */
    private function compromisosYaCreados(int $year_id, int $periodo, array $ids): array
    {
        $filas = DB::select('SELECT id, matricula_id, estado FROM compromisos
            WHERE year_id=? AND periodo=?
              AND matricula_id IN ('.implode(',', array_fill(0, count($ids), '?')).')
            ORDER BY id', array_merge([$year_id, $periodo], $ids));

        $salida = [];

        foreach ($filas as $fila) {
            $salida[(int) $fila->matricula_id] = [
                'id' => (int) $fila->id,
                'estado' => (string) $fila->estado,
            ];
        }

        return $salida;
    }

    /* ══════════════════════════════════════════════════════════════════════════════
     * EL TEXTO CONGELADO
     * ══════════════════════════════════════════════════════════════════════════════ */

    /**
     * Los bloques activos del año, ya resueltos sobre los defectos.
     *
     * **«Sin fila» significa «los defectos», nunca «un papel en blanco»** — es la regla
     * del módulo y la migración de la plantilla es aditiva pura, así que un colegio que
     * no ha entrado nunca a la pantalla no tiene ni una fila y aun así su compromiso
     * tiene que salir escrito.
     *
     * Se recorre **el catálogo** y no las filas, por lo mismo que `pintarBloques`: una
     * clave guardada que ya no esté en el catálogo deja de salir sin romper nada y sin
     * perder su fila.
     *
     * @return list<array{titulo: ?string, cuerpo: string}>
     */
    private function bloquesDelAnio(int $year_id): array
    {
        $filas = DB::select('SELECT clave, orden, activo, titulo, cuerpo FROM compromiso_bloques
            WHERE year_id=? ORDER BY orden, id', [$year_id]);

        $porClave = [];

        foreach ($filas as $fila) {
            $porClave[$fila->clave] = $fila;
        }

        $bloques = [];

        foreach (PlantillaDelCompromiso::catalogo() as $puesto => $defecto) {
            $fila = $porClave[$defecto['clave']] ?? null;

            $activo = $fila !== null ? (bool) (int) $fila->activo : (bool) $defecto['activo'];

            if (! $activo) {
                continue;
            }

            $bloques[] = [
                'titulo' => $fila !== null ? $fila->titulo : $defecto['titulo'],
                'cuerpo' => $fila !== null ? (string) $fila->cuerpo : (string) $defecto['cuerpo'],
                'orden' => $fila !== null ? (int) $fila->orden : $puesto,
                'puesto' => $puesto,
            ];
        }

        usort($bloques, static fn (array $a, array $b): int => ($a['orden'] <=> $b['orden'])
            ?: ($a['puesto'] <=> $b['puesto']));

        return $bloques;
    }

    /**
     * **La plantilla del colegio, resuelta y con los marcadores sustituidos.** Esto es lo
     * que se guarda en `compromisos.texto` y lo que se firma.
     *
     * Los marcadores son los de `PlantillaDelCompromiso::MARCADORES`, que es el catálogo
     * que la pantalla de la plantilla le enseña al colegio como ayuda: si uno de ellos no
     * se sustituyera aquí, el papel imprimiría las llaves.
     *
     * **Un marcador sin dato se sustituye por una raya y no por la cadena vacía.** Un
     * papel que dice *«el acudiente ______ , identificado con C.C. ______»* se ve como un
     * formulario al que le faltan datos, que es lo que es; uno que dice *«el acudiente ,
     * identificado con C.C. »* parece un fallo del programa y nadie sabe si faltaba algo.
     *
     * @param  list<array{titulo: ?string, cuerpo: string}>  $bloques
     * @param  array<string, mixed>  $datos
     */
    private function textoCongelado(array $bloques, array $config, string $regla, array $datos): string
    {
        $partes = [];

        foreach ($bloques as $bloque) {
            $titulo = trim((string) ($bloque['titulo'] ?? ''));
            $cuerpo = trim($bloque['cuerpo']);

            if ($titulo === '' && $cuerpo === '') {
                continue;
            }

            $partes[] = trim($titulo."\n".$cuerpo);
        }

        $texto = implode("\n\n", $partes);

        $acudiente = $datos['acudiente'] ?? null;
        $raya = '__________';

        $plazo = trim((string) $config['plazo_label']);

        if ($datos['plazo_desde'] !== null && $datos['plazo_hasta'] !== null) {
            $plazo .= ' ('.$datos['plazo_desde'].' a '.$datos['plazo_hasta'].')';
        }

        return strtr($texto, [
            '{alumno}' => $datos['alumno'] !== '' ? $datos['alumno'] : $raya,
            '{documento_alumno}' => $datos['documento_alumno'] ?? $raya,
            '{grado}' => $datos['grupo'],
            '{acudiente}' => $acudiente['nombre'] ?? $raya,
            '{cedula_acudiente}' => $acudiente['documento'] ?? $raya,
            '{periodo}' => $this->periodoEnPalabras($datos['periodo']),
            '{porcentaje}' => $this->porcentajeDelAnio($datos['periodo']).' %',
            // «tres (3)», que es como lo escribe el papel del Bethel y como lo cita el
            // SIEP: la letra manda y el número va detrás para que no se pueda retocar
            // un documento firmado cambiando una cifra.
            '{cuantas}' => $this->cuantasEnPalabras((int) $datos['cuantas']),
            // «áreas» o «asignaturas», según con qué se contó, **y concordando**.
            //
            // Iba siempre en plural, con este motivo escrito: «el corte del compromiso
            // nunca es uno en la práctica (D1 lo deja en 3)». La premisa es falsa y se
            // vio en el primer papel que se imprimió, el 22 sep 2026: la pantalla del
            // coordinador deja **bajar el corte a mano** sin tocar la configuración del
            // año —está puesto ahí para poder proponer antes—, y con corte 1 el texto
            // congelado salió diciendo «presenta 1 áreas».
            //
            // Y no es una errata cualquiera: sale en un documento que el acudiente firma
            // y que puede acabar en un despacho.
            '{unidad}' => $this->unidadEnPalabras($regla, (int) $datos['cuantas']),
            '{plazo}' => $plazo,
            '{dias_reclamacion}' => (string) $config['dias_reclamacion'],
            '{colegio}' => $datos['colegio'],
        ]);
    }

    /**
     * El acudiente de cada alumno, para los marcadores del papel.
     *
     * La tabla es **`parentescos`** —no hay ninguna `acudiente_alumno`, aunque el nombre
     * lo sugiera— y el orden es `is_acudiente DESC`, el mismo que usan
     * `AcudientesController` y `Matriculas\EstacionesController:1570`: un alumno tiene
     * varios parientes registrados y **sólo uno es el acudiente**. Ordenando por id se
     * pondría en el papel al primero que alguien tecleó, que puede ser un tío.
     *
     * Una consulta para todo el lote: crear 500 compromisos no puede costar 500 lecturas
     * de parentesco.
     *
     * @param  list<int>  $alumnoIds
     * @return array<int, array{id: int, nombre: string, documento: ?string, email: ?string}>
     */
    private function acudientesDe(array $alumnoIds): array
    {
        if ($alumnoIds === []) {
            return [];
        }

        $alumnoIds = array_values(array_unique($alumnoIds));

        $filas = DB::select('SELECT pa.alumno_id, ac.id, ac.nombres, ac.apellidos, ac.documento,
                ac.email, ac.is_acudiente
            FROM parentescos pa
            INNER JOIN acudientes ac ON ac.id = pa.acudiente_id AND ac.deleted_at IS NULL
            WHERE pa.deleted_at IS NULL
              AND pa.alumno_id IN ('.implode(',', array_fill(0, count($alumnoIds), '?')).')
            ORDER BY pa.alumno_id, ac.is_acudiente DESC, ac.id', $alumnoIds);

        $salida = [];

        foreach ($filas as $fila) {
            $alumno_id = (int) $fila->alumno_id;

            // El `ORDER BY` ya puso delante al acudiente de verdad: el primero gana.
            if (isset($salida[$alumno_id])) {
                continue;
            }

            $salida[$alumno_id] = [
                'id' => (int) $fila->id,
                'nombre' => trim(($fila->nombres ?? '').' '.($fila->apellidos ?? '')),
                'documento' => ($fila->documento ?? '') === '' ? null : (string) $fila->documento,
                'email' => CorreoDeLaCuenta::oNada($fila->email),
            ];
        }

        return $salida;
    }

    /* ══════════════════════════════════════════════════════════════════════════════
     * EL AVISO A LA FAMILIA
     * ══════════════════════════════════════════════════════════════════════════════ */

    /**
     * **El punto de enganche del aviso, y está aislado aquí a propósito.**
     *
     * Son dos canales y **no se disparan igual**, que es lo que hay que leer antes de
     * tocar este método:
     *
     * ### El push NO se manda desde aquí, y no es un olvido
     *
     * Lo manda `Console\Commands\EnviarNotificaciones`, que **lee `compromisos` y avanza
     * por el sello**: `avisosDeCompromiso()` busca `entregado_at` dentro de su ventana y
     * `avisosDelResultadoDelCompromiso()` hace lo mismo con `resultado_entregado_at`.
     * O sea que **escribir la fecha ES disparar el push**, y meter aquí una llamada a
     * `Publicador::publicar()` mandaría el aviso dos veces: una ahora y otra en la
     * siguiente pasada del cron.
     *
     * Esa forma —cron que barre sellos, en vez de controlador que publica— no es de este
     * módulo: es la de las cinco fuentes de ese comando, y la razón es que el push tiene
     * que aguantar que el proceso se caiga a mitad. Un `publicar()` dentro de la
     * transacción de una entrega es un aviso perdido cada vez que FCM tarde.
     *
     * ### El correo sí, y con tres condiciones
     *
     * `config_compromiso.canal_correo` encendido —**nace apagado y es el único de los
     * tres que lo hace**: de 1.085 acudientes medidos, 100 tienen correo y 1.020
     * celular—, un acudiente con dirección que pase `CorreoDeLaCuenta::oNada()` —el
     * `@gmail.com` fabricado no es una dirección— y una `app.frontend_url` a la que
     * mandar. Sin las tres no se manda y no se falla.
     *
     * Y va **dentro de un `try`**, como el del reseteo (`LoginController:314-322`): el
     * correo de esta API está en rojo en al menos un colegio desde el 2 sep, y una
     * entrega que se cae entera porque el SMTP no contestó es una entrega perdida por el
     * canal que menos vale. Se registra el fallo y la entrega sigue — la fecha ya está
     * escrita, que es la prueba.
     *
     * ### Lo que va dentro del aviso
     *
     * Ni una asignatura, ni un número de perdidas, ni una nota, ni el veredicto. §4.4: el
     * contenido se lee **entrando**, no en la bandeja. Eso lo decide `CompromisoAcademico`
     * y por eso este método sólo le pasa el alumno, el colegio, el enlace y el momento.
     */
    private function avisarALaFamilia(int $compromiso_id, string $momento): void
    {
        $fila = DB::selectOne('SELECT c.year_id, m.alumno_id,
                TRIM(CONCAT(COALESCE(a.nombres, ""), " ", COALESCE(a.apellidos, ""))) AS alumno
            FROM compromisos c
            INNER JOIN matriculas m ON m.id = c.matricula_id
            INNER JOIN alumnos a ON a.id = m.alumno_id
            WHERE c.id = ?', [$compromiso_id]);

        if (! $fila) {
            return;
        }

        $config = $this->configDelAnio((int) $fila->year_id);

        if (! $config['canal_correo']) {
            return;
        }

        $acudiente = $this->acudientesDe([(int) $fila->alumno_id])[(int) $fila->alumno_id] ?? null;

        if ($acudiente === null || $acudiente['email'] === null) {
            return;
        }

        $base = (string) config('app.frontend_url', '');

        if ($base === '') {
            // Un botón «Ver el documento» que no lleva a ninguna parte es peor que no
            // mandar el correo: la familia cree que el enlace está roto por su lado.
            Log::warning('Compromiso '.$compromiso_id.': `app.frontend_url` está vacía, '
                .'no se manda el correo.');

            return;
        }

        try {
            Mail::to($acudiente['email'])->send(new CompromisoAcademico(
                (string) $fila->alumno,
                $this->nombreDelColegio((int) $fila->year_id),
                rtrim($base, '/').'/',
                $momento
            ));
        } catch (\Throwable $e) {
            Log::error('Fallo enviando el correo del compromiso '.$compromiso_id.': '.$e->getMessage());
        }
    }

    /* ══════════════════════════════════════════════════════════════════════════════
     * PERMISOS (§3.5)
     * ══════════════════════════════════════════════════════════════════════════════ */

    /**
     * **Quién coordina el compromiso: crear, entregar, cerrar y notificar.**
     *
     * El mismo conjunto que `Autoriza::puedeCambiarLaNotaNumerica` —superusuario,
     * Secretario, Coordinación Académica y Rector— y **el mismo que ya eligió
     * `CompromisosConfigController::puedeConfigurarElCompromiso` para la plantilla**.
     * Ése era el llamante único que aquel método decía estar esperando; con este segundo,
     * la regla de `Autoriza` diría que toca mudarlo allí. No se hace todavía y se escribe
     * por qué: **no son la misma pregunta**. Aquella decide quién escribe el papel del
     * colegio una vez al año; ésta, quién se lo entrega a una familia concreta. Hoy la
     * respuesta coincide; el día que un colegio quiera que el psicoorientador entregue
     * sin poder reescribir el SIEE, se separan sin perseguir copias.
     *
     * ## `is_superuser` Y NO EL ROL `Admin`, que es el aviso medido de §3.5
     *
     * `Autoriza::esSuperusuario()` mira la **columna**. Medido el 4 sep 2026: el rol
     * `Admin` (10 personas) es subconjunto estricto de `is_superuser` (11), así que hoy
     * coinciden **por población, no por definición**. El front decide por rol y la API
     * por columna, y ya discrepan en una persona. Declarado aquí para que la puerta de
     * este módulo tenga un solo criterio escrito en un sitio.
     *
     * **El coordinador académico entra, y ése es el punto de la puerta**: es quien firma
     * el papel. `Autoriza::esAdministrativo()` lo dejaría fuera, a él y al rector, que
     * son justamente los dos que firman.
     */
    private function puedeCoordinarCompromisos($user): bool
    {
        return Autoriza::puedeCambiarLaNotaNumerica($user);
    }

    private function exigirCoordinacion($user, string $que): void
    {
        Autoriza::exigir(
            $this->puedeCoordinarCompromisos($user),
            'Sólo un superusuario, secretario, coordinador académico o rector puede '.$que.'.'
        );
    }

    /**
     * Quién puede pedir candidatos, y de qué grupo.
     *
     * Coordinación, del colegio entero. El **titular**, de su grupo y sólo de su grupo
     * (§3.5: *«su grupo: ver, imprimir, proponer»*), porque proponer compromisos de su
     * curso es exactamente lo que hace un director de grupo la semana del cierre.
     *
     * ## `esTitularDe` Y NO `persona_id === titular_id`, que es la trampa documentada
     *
     * El front tiene esa regla escrita en `core/sesion/permisos.ts:48-85`: `persona_id`
     * sale de **una tabla distinta según `tipo`** —`profesores`, `alumnos`,
     * `acudientes`— así que comparar el número pelado acierta por casualidad. Aquí la
     * comprobación empieza por `tipo === 'Profesor'`, que es lo que hace que ese
     * `persona_id` sea de verdad un `profesores.id`, y sólo después compara. Es la misma
     * guarda que `Autoriza::puedeEscribirDesempenos:855`.
     *
     * Y sin grupo —el colegio entero— **no hay titularidad que valga**: 505 candidatos
     * con corte 1 no son «su grupo» de nadie.
     */
    private function exigirQuePuedaMirar($user, ?int $grupo_id): void
    {
        if ($this->puedeCoordinarCompromisos($user)) {
            return;
        }

        Autoriza::exigir(
            $grupo_id !== null && $this->esTitularDelGrupo($user, $grupo_id),
            'Sólo coordinación puede mirar los candidatos del colegio entero; '
                .'un director de grupo puede pedir los de su grupo.'
        );
    }

    private function esTitularDelGrupo($user, int $grupo_id): bool
    {
        if (($user->tipo ?? null) !== 'Profesor') {
            return false;
        }

        $persona_id = $user->persona_id ?? null;

        if ($persona_id === null) {
            return false;
        }

        return DB::selectOne('SELECT id FROM grupos
            WHERE id=? AND titular_id=? AND deleted_at IS NULL',
            [$grupo_id, (int) $persona_id]) !== null;
    }

    /**
     * La condición que acota las lecturas de quien no coordina, o `null` si lo ve todo.
     *
     * **Titular y docente caben en la misma condición**, y no por ahorrar: son la misma
     * pregunta —*«¿tengo algo que ver con este papel?»*— contestada por dos caminos, y el
     * encargo pedía las dos (*«el coordinador, el titular puedan regresar, cada docente
     * pueda ir»*). Un titular que además da clase en otro grupo ve las dos cosas sin que
     * nadie tenga que decidir cuál de sus dos sombreros lleva puesto.
     *
     * El `EXISTS` va sobre `compromiso_items_del_docente`, el índice
     * `(profesor_id, resultado)` que la migración puso para `GET compromisos/mios`.
     *
     * @return array{sql: string, datos: list<int>}|null
     */
    private function alcanceDeLaConsulta($user): ?array
    {
        if ($this->puedeCoordinarCompromisos($user)) {
            return null;
        }

        if (($user->tipo ?? null) !== 'Profesor' || ($user->persona_id ?? null) === null) {
            abort(403, 'Esta pantalla es del personal académico del colegio.');
        }

        $persona_id = (int) $user->persona_id;

        return [
            'sql' => '(g.titular_id = ? OR EXISTS (SELECT 1 FROM compromiso_items ci
                WHERE ci.compromiso_id = c.id AND ci.profesor_id = ?))',
            'datos' => [$persona_id, $persona_id],
        ];
    }

    /* ══════════════════════════════════════════════════════════════════════════════
     * LECTURA DE UN COMPROMISO
     * ══════════════════════════════════════════════════════════════════════════════ */

    /**
     * El `SELECT` de la cabecera, compartido por el listado y por el papel.
     *
     * Los dos tienen que devolver **exactamente** la misma forma —el contrato tiene un
     * solo `interface Compromiso`— así que la consulta es una y el `WHERE` lo pone quien
     * llama. Dos consultas parecidas es como se consiguen dos pantallas que discrepan en
     * un campo que nadie mira hasta que se imprime.
     *
     * `creado_por` apunta a `users.id` **sin clave ajena** (la papelera borra de verdad),
     * así que el nombre se resuelve por `LEFT JOIN` en dos saltos y si la persona ya no
     * está el papel imprime lo que sepa — el `username`, y si tampoco, nada.
     */
    private function consultaDeCabeceras(): string
    {
        return 'SELECT c.*,
                m.alumno_id, g.id AS grupo_id, g.nombre AS grupo, g.titular_id,
                TRIM(CONCAT(COALESCE(a.nombres, ""), " ", COALESCE(a.apellidos, ""))) AS alumno,
                a.documento AS documento_alumno,
                COALESCE(NULLIF(TRIM(CONCAT(COALESCE(pc.nombres, ""), " ",
                    COALESCE(pc.apellidos, ""))), ""), uc.username) AS creado_por_nombre
            FROM compromisos c
            INNER JOIN matriculas m ON m.id = c.matricula_id
            INNER JOIN alumnos a ON a.id = m.alumno_id
            INNER JOIN grupos g ON g.id = m.grupo_id
            LEFT JOIN users uc ON uc.id = c.creado_por
            LEFT JOIN profesores pc ON pc.id = uc.profesor_id';
    }

    /**
     * Los items de unos compromisos, agrupados por compromiso.
     *
     * `$conSugerido` sólo lo pide `getShow`: leer la nivelación de cada renglón de un
     * listado de 200 compromisos sería una consulta gorda para una propuesta que el
     * tablero ni pinta.
     *
     * @param  list<int>  $ids
     * @return array<int, list<array<string, mixed>>>
     */
    private function itemsDe(array $ids, bool $conSugerido = false): array
    {
        if ($ids === []) {
            return [];
        }

        $huecos = implode(',', array_fill(0, count($ids), '?'));

        /*
         * **El nombre largo y no el alias**, y va escrito porque se cambió a propósito:
         * `compromiso_items` no tiene columna `nombre` y lo resuelven por `join` dos
         * controladores —éste y `CompromisosDelDocenteController`—. Si uno pusiera
         * `mat.alias` y el otro `mat.materia`, **el mismo renglón se llamaría de dos
         * formas según la pantalla**. Gana el largo por dos motivos: es el que ya usa el
         * otro, y es el que tiene que salir impreso — un documento que un acudiente firma
         * no dice «ESP», dice «Humanidades, Lengua Castellana…».
         */
        $filas = DB::select('SELECT ci.*,
                COALESCE(mat.materia, ar.nombre) AS nombre,
                TRIM(CONCAT(COALESCE(p.nombres, ""), " ", COALESCE(p.apellidos, ""))) AS profesor,
                TRIM(CONCAT(COALESCE(pv.nombres, ""), " ", COALESCE(pv.apellidos, ""))) AS veredicto_por_nombre,
                c.periodo, c.matricula_id, m.alumno_id, g.nombre AS grupo,
                TRIM(CONCAT(COALESCE(al.nombres, ""), " ", COALESCE(al.apellidos, ""))) AS alumno
            FROM compromiso_items ci
            INNER JOIN compromisos c ON c.id = ci.compromiso_id
            INNER JOIN matriculas m ON m.id = c.matricula_id
            INNER JOIN alumnos al ON al.id = m.alumno_id
            INNER JOIN grupos g ON g.id = m.grupo_id
            LEFT JOIN asignaturas asg ON asg.id = ci.asignatura_id
            LEFT JOIN materias mat ON mat.id = asg.materia_id
            LEFT JOIN areas ar ON ar.id = ci.area_id
            LEFT JOIN profesores p ON p.id = ci.profesor_id
            LEFT JOIN profesores pv ON pv.id = ci.veredicto_por
            WHERE ci.compromiso_id IN ('.$huecos.')
            ORDER BY ci.compromiso_id, nombre, ci.id', $ids);

        $sugeridos = $conSugerido ? $this->sugerenciasDeNivelacion($ids) : [];

        $salida = [];

        foreach ($filas as $fila) {
            $compromiso_id = (int) $fila->compromiso_id;

            $salida[$compromiso_id][] = [
                'id' => (int) $fila->id,
                'compromiso_id' => $compromiso_id,
                'asignatura_id' => $fila->asignatura_id === null ? null : (int) $fila->asignatura_id,
                'area_id' => $fila->area_id === null ? null : (int) $fila->area_id,
                'nombre' => (string) ($fila->nombre ?? ''),
                'profesor_id' => $fila->profesor_id === null ? null : (int) $fila->profesor_id,
                'profesor' => ($fila->profesor ?? '') === '' ? null : (string) $fila->profesor,
                // `decimal` sale de PDO como cadena: sin este `float` el front recibe
                // `"34.6667"` y cualquier comparación numérica en TypeScript se cae de
                // lado. Es el mismo casteo que hace `pintarConfig` con los `tinyint(1)`.
                'nota_al_crear' => (float) $fila->nota_al_crear,
                /*
                 * Las cinco congeladas. **El `null` tiene que sobrevivir hasta el front**,
                 * y por eso no hay ni un `?: 0` aquí: el papel distingue «no se congeló»
                 * —un compromiso anterior a estas columnas, un periodo que aún no se ha
                 * cursado— de «cero faltas», y con esa diferencia decide si pinta el
                 * número o una raya. Un cero inventado en la columna de un periodo es
                 * además una afirmación grave: este colegio califica sobre 50.
                 */
                'faltas_al_crear' => $fila->faltas_al_crear === null ? null : (int) $fila->faltas_al_crear,
                'per1_nota' => $fila->per1_nota === null ? null : (float) $fila->per1_nota,
                'per2_nota' => $fila->per2_nota === null ? null : (float) $fila->per2_nota,
                'per3_nota' => $fila->per3_nota === null ? null : (float) $fila->per3_nota,
                'per4_nota' => $fila->per4_nota === null ? null : (float) $fila->per4_nota,
                'asistio' => $fila->asistio === null ? null : (int) $fila->asistio,
                'resultado' => $fila->resultado === null ? null : (string) $fila->resultado,
                'nota_al_cerrar' => $fila->nota_al_cerrar === null ? null : (float) $fila->nota_al_cerrar,
                'observacion' => $fila->observacion === null ? null : (string) $fila->observacion,
                'veredicto_por' => $fila->veredicto_por === null ? null : (int) $fila->veredicto_por,
                'veredicto_por_nombre' => ($fila->veredicto_por_nombre ?? '') === ''
                    ? null : (string) $fila->veredicto_por_nombre,
                'veredicto_at' => $fila->veredicto_at,
                'sugerido' => $sugeridos[(int) $fila->id] ?? null,
                'alumno' => (string) $fila->alumno,
                'grupo' => (string) $fila->grupo,
                'periodo' => (int) $fila->periodo,
            ];
        }

        return $salida;
    }

    /**
     * **D4: lo que la nivelación ya dice, para proponerlo.**
     *
     * Decidida el 22 sep 2026: si el alumno ya niveló por la vía normal, se propone el
     * resultado leído de la nivelación y el docente lo confirma. Evita teclear dos veces
     * lo mismo y evita que el compromiso diga «no niveló» de alguien que sí.
     *
     * **Propuesto no es escrito**, y por eso esto es un campo de lectura y no un
     * `UPDATE`: el item sigue como está hasta que el docente confirma, porque el veredicto
     * es suyo y tiene que poder discrepar de la nota. Y `asistio` no se propone **nunca**:
     * la base no sabe quién fue a la semana de nivelaciones.
     *
     * Sale de `notas_finales` —donde `nota_nivelacion` vive desde el 2 sep 2026 con la
     * misma precisión que la definitiva— y **sólo para los items de asignatura**: una
     * nivelación es de una asignatura, y un área es un promedio de varias que pueden
     * haberse nivelado unas sí y otras no. Proponer un resultado de área sería inventarlo.
     *
     * @param  list<int>  $ids
     * @return array<int, array{resultado: string, nota: float, observacion: ?string}>
     */
    private function sugerenciasDeNivelacion(array $ids): array
    {
        $huecos = implode(',', array_fill(0, count($ids), '?'));

        $filas = DB::select('SELECT ci.id, nf.nota_nivelacion, nf.nivelacion_obs, y.nota_minima_aceptada
            FROM compromiso_items ci
            INNER JOIN compromisos c ON c.id = ci.compromiso_id
            INNER JOIN matriculas m ON m.id = c.matricula_id
            INNER JOIN years y ON y.id = c.year_id
            INNER JOIN notas_finales nf ON nf.alumno_id = m.alumno_id
                AND nf.asignatura_id = ci.asignatura_id AND nf.periodo = c.periodo
            WHERE ci.compromiso_id IN ('.$huecos.')
              AND ci.asignatura_id IS NOT NULL
              AND nf.nota_nivelacion IS NOT NULL', $ids);

        $salida = [];

        foreach ($filas as $fila) {
            $nota = (float) $fila->nota_nivelacion;

            $salida[(int) $fila->id] = [
                'resultado' => $nota >= (float) $fila->nota_minima_aceptada ? 'nivelo' : 'no_nivelo',
                'nota' => $nota,
                'observacion' => ($fila->nivelacion_obs ?? '') === ''
                    ? null : (string) $fila->nivelacion_obs,
            ];
        }

        return $salida;
    }

    /**
     * La cabecera tal como la pinta el contrato.
     *
     * Los castes no son cosmética: PDO devuelve los enteros y los `tinyint` como cadenas,
     * y `"0"` es **verdadero** para el front en cuanto alguien escriba `if (c.periodo)`
     * en TypeScript sin `=== 0`. Se castean uno a uno, que es lo que evita esa familia
     * entera de fallos — el mismo razonamiento que dejó escrito `pintarConfig`.
     *
     * @param  list<array<string, mixed>>  $items
     * @param  array{id: int, nombre: string, documento: ?string, email: ?string}|null  $acudiente
     * @return array<string, mixed>
     */
    private function pintarCompromiso(object $fila, array $items, ?array $acudiente = null): array
    {
        return [
            'id' => (int) $fila->id,
            'year_id' => (int) $fila->year_id,
            'periodo' => (int) $fila->periodo,

            'matricula_id' => (int) $fila->matricula_id,
            'alumno_id' => (int) $fila->alumno_id,
            'alumno' => (string) $fila->alumno,
            'documento_alumno' => ($fila->documento_alumno ?? '') === ''
                ? null : (string) $fila->documento_alumno,
            'grupo_id' => (int) $fila->grupo_id,
            'grupo' => (string) $fila->grupo,
            // El acudiente se resuelve al pintar y no se congeló en la fila: el papel
            // necesita su nombre y su cédula, pero **la persona puede cambiar** —un
            // alumno que pasa a vivir con la abuela— y congelarla haría que el
            // expediente siguiera nombrando a quien ya no responde por él. Lo que se
            // congela es el TEXTO, que ya lleva dentro al acudiente del día en que se
            // firmó; esto es el dato de hoy, para la pantalla.
            'acudiente' => ($acudiente['nombre'] ?? '') === '' ? null : $acudiente['nombre'],
            'documento_acudiente' => $acudiente['documento'] ?? null,

            'regla' => (string) $fila->regla,
            'cantidad_perdidas' => (int) $fila->cantidad_perdidas,
            'porcentaje_ano' => (int) $fila->porcentaje_ano,
            'texto' => (string) ($fila->texto ?? ''),
            'plazo_desde' => $fila->plazo_desde,
            'plazo_hasta' => $fila->plazo_hasta,
            'estado' => (string) $fila->estado,

            'creado_por' => $fila->creado_por === null ? null : (int) $fila->creado_por,
            'creado_por_nombre' => ($fila->creado_por_nombre ?? '') === ''
                ? null : (string) $fila->creado_por_nombre,
            'created_at' => $fila->created_at,

            'entregado_at' => $fila->entregado_at,
            'entrega_canal' => $fila->entrega_canal,
            'acuse_at' => $fila->acuse_at,
            'acuse_por' => $fila->acuse_por === null ? null : (int) $fila->acuse_por,
            'acuse_canal' => $fila->acuse_canal,

            'cerrado_at' => $fila->cerrado_at,
            'cerrado_por' => $fila->cerrado_por === null ? null : (int) $fila->cerrado_por,

            'resultado_entregado_at' => $fila->resultado_entregado_at,
            'resultado_canal' => $fila->resultado_canal,
            'resultado_acuse_at' => $fila->resultado_acuse_at,
            'resultado_acuse_por' => $fila->resultado_acuse_por === null
                ? null : (int) $fila->resultado_acuse_por,
            'resultado_acuse_tipo' => $fila->resultado_acuse_tipo,
            'reclamacion_texto' => $fila->reclamacion_texto,
            'reclamacion_vence' => $fila->reclamacion_vence,

            'items' => $items,
        ];
    }

    /** La fila del compromiso, o 404. Las cuatro escrituras empiezan por aquí. */
    private function compromisoOFalla(int $id): object
    {
        $fila = DB::selectOne('SELECT * FROM compromisos WHERE id=?', [$id]);

        if (! $fila) {
            abort(404, 'Ese compromiso no existe.');
        }

        return $fila;
    }

    /* ══════════════════════════════════════════════════════════════════════════════
     * VALIDACIÓN A MANO
     * ══════════════════════════════════════════════════════════════════════════════ */

    /**
     * La configuración del compromiso del año, **con los defectos puestos**.
     *
     * Los defectos son los de la migración y están repetidos aquí igual que en
     * `CompromisosConfigController::pintarConfig`, por el mismo motivo que allí: un
     * colegio que no ha abierto nunca la pantalla **no tiene fila**, y sin defectos este
     * controlador no podría ni contar. Se leen sólo los valores que este fichero usa; el
     * papel y los canales de firma son de la otra pantalla.
     *
     * @return array<string, mixed>
     */
    private function configDelAnio(int $year_id): array
    {
        $fila = DB::selectOne('SELECT * FROM config_compromiso WHERE year_id=?', [$year_id]);

        return [
            'regla' => $fila->regla ?? 'area',
            'corte' => (int) ($fila->corte ?? 3),
            'primaria_activa' => (bool) (int) ($fila->primaria_activa ?? 0),
            'primaria_materia_1_id' => $fila->primaria_materia_1_id ?? null,
            'primaria_materia_2_id' => $fila->primaria_materia_2_id ?? null,
            'plazo_label' => $fila->plazo_label ?? 'Semana de nivelaciones',
            'plazo_dias' => (int) ($fila->plazo_dias ?? 5),
            'dias_reclamacion' => (int) ($fila->dias_reclamacion ?? 5),
            'canal_correo' => (bool) (int) ($fila->canal_correo ?? 0),
        ];
    }

    /**
     * El corte con el que se cuenta.
     *
     * El del año, salvo que la petición traiga el suyo: es el coordinador bajándolo **en
     * pantalla** para ver a cuántos alcanza, sin tocar `config_compromiso` (§6 D1). Lo
     * que no puede ser es cero — un corte de cero propone compromiso a todo el colegio,
     * incluidos los que no perdieron nada.
     */
    private function corteDeLaPeticion(mixed $corte, array $config): int
    {
        if ($corte === null || $corte === '') {
            return max(1, (int) $config['corte']);
        }

        return $this->enteroEntre($corte, 1, self::TOPE_DE_PERDIDAS,
            'El corte tiene que ser un número entre 1 y '.self::TOPE_DE_PERDIDAS.'.');
    }

    /**
     * La regla con la que se cuenta.
     *
     * D2: **hereda** la del año y se enseña cuál está usando. La petición puede cambiarla
     * porque la pantalla deja comparar las dos antes de crear, y porque un colegio que
     * usa áreas puede querer un compromiso de asignatura concreto — pero lo que se
     * congela en el papel es **la que se usó**, no la del año, y por eso `compromisos`
     * tiene su propia columna `regla`.
     */
    private function reglaDeLaPeticion(mixed $regla, array $config): string
    {
        if ($regla === null || $regla === '') {
            return in_array($config['regla'], self::REGLAS, true) ? $config['regla'] : 'area';
        }

        if (! in_array($regla, self::REGLAS, true)) {
            abort(422, 'La regla tiene que ser `'.implode('` o `', self::REGLAS).'`.');
        }

        return (string) $regla;
    }

    /**
     * El plazo que el papel promete.
     *
     * Si la pantalla lo manda, manda ella: la semana de nivelaciones la pone el colegio
     * en su calendario y no la puede adivinar el servidor. Si no, sale de
     * `config_compromiso.plazo_dias` contado desde hoy **en días hábiles**: `plazo_label`
     * nace diciendo *«Semana de nivelaciones»* y cinco días naturales desde un jueves
     * prometerían un plazo que incluye sábado y domingo, que no son días de colegio.
     *
     * @return array{0: string, 1: string}
     */
    private function plazoDeLaPeticion(array $config): array
    {
        $desde = $this->fechaOpcional(Request::input('plazo_desde'), 'La fecha de inicio del plazo');
        $hasta = $this->fechaOpcional(Request::input('plazo_hasta'), 'La fecha de fin del plazo');

        $desde ??= Reloj::ahora()->toDateString();
        $hasta ??= $this->sumarDiasHabiles($desde, max(1, (int) $config['plazo_dias']));

        if ($hasta < $desde) {
            abort(422, 'El plazo no puede terminar antes de empezar.');
        }

        return [$desde, $hasta];
    }

    /**
     * Suma días **hábiles** a una fecha.
     *
     * ## Se miró antes si el proyecto ya sabía hacerlo, y no
     *
     * Cero resultados en `app/` para `habil`, `addWeekdays` o cualquier variante: el
     * único calendario que hay es `Services\CalendarioDePeriodos`, que reparte los
     * cuatro periodos del año por semanas y no cuenta días laborables. Así que esto es
     * `Carbon::addWeekdays()`, que ya viene con el framework.
     *
     * **Y Carbon sólo sabe de fines de semana, no de festivos colombianos** —que son
     * dieciocho al año y se mueven—. O sea que esta fecha puede caer en un lunes festivo.
     * Es exactamente la razón por la que la migración decidió **guardar**
     * `reclamacion_vence` en vez de recalcularla: el día que el colegio quiera descontar
     * festivos, los expedientes ya notificados conservan la fecha que se imprimió, y no
     * hay ningún papel firmado que cambie de plazo retroactivamente.
     */
    private function sumarDiasHabiles(string $desde, int $dias): string
    {
        return Reloj::desdeTexto($desde)?->addWeekdays($dias)->toDateString()
            ?? Reloj::ahora()->addWeekdays($dias)->toDateString();
    }

    /**
     * «Cursado el N % del año escolar».
     *
     * `periodo × 25` (§2.4). **No sale de fechas**: `periodos.fecha_inicio` y `fecha_fin`
     * están en `NULL` desde 2021, y `periodos` no tiene columna de peso. Lo que sí existe
     * es la aritmética — son siempre cuatro (`CalendarioDePeriodos::CANTIDAD`) — y cuadra
     * con el papel del Bethel, que en «Fin Tercer Periodo» escribe «Cursado el 75 %».
     *
     * Se calcula aquí y **se guarda en la fila**, que es lo que permite que un colegio de
     * tres periodos cambie esta cuenta mañana sin reescribir el porcentaje de un papel
     * que ya se firmó.
     */
    private function porcentajeDelAnio(int $periodo): int
    {
        return (int) round($periodo * (100 / self::PERIODOS_DEL_ANIO));
    }

    /** «primero», «segundo»… para el marcador `{periodo}` del papel. */
    private function periodoEnPalabras(int $periodo): string
    {
        $palabras = [1 => 'primero', 2 => 'segundo', 3 => 'tercero', 4 => 'cuarto'];

        return $palabras[$periodo] ?? (string) $periodo;
    }

    /**
     * «una (1)», «tres (3)». **En femenino**, y no es una elección: las dos unidades que
     * puede acompañar —«área» y «asignatura»— lo son, así que no hay caso masculino al
     * que atender. El día que aparezca una tercera unidad, esto hay que mirarlo.
     *
     * La letra delante y el número entre paréntesis es como lo escribe el formato del
     * Bethel y como cita el SIEP sus propios artículos («tres (3) o más asignaturas»).
     * En un papel que se firma la letra es lo que manda: un número suelto se retoca con
     * un bolígrafo y la palabra no.
     *
     * Por encima de diez se deja la cifra sola. Diez áreas perdidas ya no es un
     * compromiso académico, es otra conversación, y escribir «veintitrés» en un
     * documento legal pide una tabla que no hace falta tener.
     */
    private function cuantasEnPalabras(int $cuantas): string
    {
        $palabras = [
            1 => 'una', 2 => 'dos', 3 => 'tres', 4 => 'cuatro', 5 => 'cinco',
            6 => 'seis', 7 => 'siete', 8 => 'ocho', 9 => 'nueve', 10 => 'diez',
        ];

        return isset($palabras[$cuantas])
            ? $palabras[$cuantas].' ('.$cuantas.')'
            : (string) $cuantas;
    }

    /**
     * «área» / «áreas», «asignatura» / «asignaturas», según **con qué se contó** y
     * **cuántas** salieron. Ver el comentario de `{unidad}` en la tabla de marcadores:
     * el plural fijo produjo «presenta 1 áreas» en el primer papel impreso.
     */
    private function unidadEnPalabras(string $regla, int $cuantas): string
    {
        if ($regla === 'area') {
            return $cuantas === 1 ? 'área' : 'áreas';
        }

        return $cuantas === 1 ? 'asignatura' : 'asignaturas';
    }

    /** El nombre del colegio, que va en el papel y en el asunto del correo. */
    private function nombreDelColegio(int $year_id): string
    {
        $fila = DB::selectOne('SELECT nombre_colegio FROM years WHERE id=?', [$year_id]);

        return (string) ($fila->nombre_colegio ?? '');
    }

    /** `years.nota_minima_aceptada`: el número contra el que se decide si está perdida. */
    private function notaMinimaDe(int $year_id): float
    {
        $fila = DB::selectOne('SELECT nota_minima_aceptada FROM years WHERE id=?', [$year_id]);

        return (float) ($fila->nota_minima_aceptada ?? 0);
    }

    /**
     * El año de la petición, comprobado. Por defecto, el de la sesión.
     *
     * Copiado de `CompromisosConfigController::anioDeLaPeticion` —que a su vez lo copió de
     * `FormulariosInscripcionController`— porque allí es `private`. Con este tercer
     * llamante ya toca el `trait`; se deja escrito aquí para que se vea que la deuda es
     * conocida y no un descuido.
     */
    private function anioDeLaPeticion(mixed $year_id): int
    {
        if ($year_id === null || $year_id === '') {
            return (int) $this->user->year_id;
        }

        if (! is_numeric($year_id)) {
            abort(422, 'El año no es válido.');
        }

        $anio = DB::selectOne('SELECT id FROM years WHERE id=? AND deleted_at IS NULL',
            [(int) $year_id]);

        if (! $anio) {
            abort(404, 'Ese año lectivo no existe.');
        }

        return (int) $anio->id;
    }

    /**
     * El periodo, 1..4.
     *
     * Obligatorio y sin defecto: el periodo de la sesión (`$user->numero_periodo`) es «en
     * cuál estamos», y el compromiso se crea **para un periodo que acaba de cerrar**. Un
     * defecto silencioso crearía los papeles del periodo equivocado el día que el colegio
     * abre el siguiente, y el error sólo se vería en el papel impreso.
     */
    private function periodoValidado(mixed $periodo): int
    {
        return $this->enteroEntre($periodo, 1, self::PERIODOS_DEL_ANIO,
            'El periodo tiene que ser un número entre 1 y '.self::PERIODOS_DEL_ANIO.'.');
    }

    /**
     * Las matrículas del lote: una o muchas, pero nunca ninguna y nunca veinte mil.
     *
     * @return list<int>
     */
    private function matriculasValidadas(mixed $ids): array
    {
        if (! is_array($ids) || $ids === []) {
            abort(422, 'Hay que decir de qué matrículas se crea el compromiso.');
        }

        if (count($ids) > self::TOPE_DEL_LOTE) {
            abort(422, 'No se pueden crear más de '.self::TOPE_DEL_LOTE.' compromisos de una vez.');
        }

        $limpios = [];

        foreach ($ids as $id) {
            if (! is_numeric($id) || (int) $id <= 0) {
                abort(422, 'Alguna de las matrículas no es válida.');
            }

            // Sin repetidos: el mismo id dos veces en el cuerpo crearía dos papeles
            // idénticos, y el segundo pasaría la comprobación de duplicado porque el
            // primero todavía no está confirmado dentro de la transacción.
            $limpios[(int) $id] = (int) $id;
        }

        return array_values($limpios);
    }

    private function canalValidado(mixed $canal): string
    {
        if (! is_string($canal) || ! in_array($canal, self::CANALES, true)) {
            abort(422, 'El canal tiene que ser `'.implode('`, `', self::CANALES).'`.');
        }

        return $canal;
    }

    private function idObligatorio(mixed $valor, string $que): int
    {
        if (! is_numeric($valor) || (int) $valor <= 0) {
            abort(422, $que.' no es válido.');
        }

        return (int) $valor;
    }

    private function idOpcional(mixed $valor, string $que): ?int
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        return $this->idObligatorio($valor, $que);
    }

    private function enteroEntre(mixed $valor, int $minimo, int $maximo, string $mensaje): int
    {
        if (! is_numeric($valor)) {
            abort(422, $mensaje);
        }

        $entero = (int) $valor;

        if ($entero < $minimo || $entero > $maximo) {
            abort(422, $mensaje);
        }

        return $entero;
    }

    /**
     * Una fecha `AAAA-MM-DD`, o nada.
     *
     * Se comprueba con `checkdate` y no sólo con la forma: `2026-02-31` casa el patrón y
     * MySQL la guardaría como `0000-00-00` en los servidores con `sql_mode` permisivo,
     * que es como un plazo desaparece de un papel sin que nadie vea un error.
     */
    private function fechaOpcional(mixed $valor, string $que): ?string
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        if (! is_string($valor) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor) !== 1) {
            abort(422, $que.' tiene que venir como AAAA-MM-DD.');
        }

        [$anio, $mes, $dia] = array_map('intval', explode('-', $valor));

        if (! checkdate($mes, $dia, $anio)) {
            abort(422, $que.' no existe en el calendario.');
        }

        return $valor;
    }

    /**
     * Un interruptor que llega por la URL.
     *
     * `GET compromisos?sin_acuse=false` manda la **cadena** `"false"`, que en PHP es
     * verdadera. Es el mismo fallo que `pintarConfig` documenta al revés —`"0"` como
     * verdadero en TypeScript— y aquí muerde igual: el tablero saldría filtrado sin que
     * nadie hubiera pulsado el filtro.
     */
    private function banderaPedida(mixed $valor): bool
    {
        return in_array($valor, [true, 1, '1', 'true', 'on'], true);
    }
}
