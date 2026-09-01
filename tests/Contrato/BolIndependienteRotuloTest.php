<?php

namespace Tests\Contrato;

use App\Services\BoletinIndependiente;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * `asignatura.bol_independiente` — el rótulo que dice **«este boletín es el suyo, no
 * el del grupo»**. §6.4 del [19](../../docs/migracion/19-boletin-independiente.md).
 *
 * ## Por qué los cuatro a la vez y no uno por uno
 *
 * Es la regla que ya gobernó el interruptor de puestos: **si lo emite uno y otro no,
 * los otros mienten**. El front pinta con este campo una **nota flotante que se ve en
 * pantalla y desaparece al imprimir** —un boletín es papel del colegio y no se
 * rotula—, así que **si el campo no viaja no se pinta nada y nadie se entera**. Un
 * documento que calla no se distingue de uno que no tiene nada que decir.
 *
 * ## Lo que este test comprueba y una lectura del código no puede
 *
 * **Que el campo LLEGA**, que es distinto de que se escriba. `Boletines3Controller`
 * hace `unset($alumno->asignaturas)` **después** de que se le ponga el campo: las
 * asignaturas sobreviven sólo **dentro de `areas[].asignaturas[]`**, porque los
 * objetos de PHP entran por referencia en el agrupado de `Area`. Escrito así se lee
 * como una pérdida; medido, no lo es. Por eso el recolector de abajo **busca el campo
 * donde esté** en vez de en la ruta que uno supone.
 *
 * Y comprueba **que varía**: el marcado en `true` y su compañero en `false` **en la
 * misma respuesta**. Un campo constante es uno sobre el que alguien ramificará sin
 * que su rama muerta se note nunca, y este módulo ya perdió dos campos así.
 */
class BolIndependienteRotuloTest extends CasoDeContrato
{
    protected function setUp(): void
    {
        parent::setUp();

        BoletinIndependiente::olvidar();
    }

    /**
     * Los cuatro documentos que emiten el rótulo, con su ruta.
     *
     * **`certificados-persona` NO está**, y no es un olvido: su
     * `detailedNotasGrupo` **no lo alcanza nadie** —la única ruta del controlador es
     * `putIndex`, que devuelve matrículas— y está medido dos veces en
     * [05 §211 y §218](../../docs/migracion/05-codigo-muerto-y-roto.md). El campo se
     * emite allí para que un día que se resucite nazca correcto, pero **no llega a
     * ningún cliente**, así que un test que lo diera por vivo estaría mintiendo.
     *
     * @return array<string, array{string}>
     */
    public static function losCuatroDocumentos(): array
    {
        return [
            'boletines' => ['boletines/detailed-notas'],
            'boletines2' => ['boletines2/detailed-notas'],
            'boletines3' => ['boletines3/detailed-notas'],
            'bolfinales-preescolar' => ['bolfinales-preescolar/detailed-notas-year'],
        ];
    }

    /**
     * Todos los `bol_independiente` que haya bajo una estructura, esté donde esté.
     *
     * **Busca en vez de navegar**, y es deliberado: cada uno de los cuatro documentos
     * cuelga las asignaturas de un sitio distinto —y `boletines3` las cuelga de
     * `areas[]` porque las borra de `alumno`—. Una ruta fija por documento probaría
     * cuatro caminos y no la pregunta, que es **si el dato sale**.
     *
     * @return list<bool>
     */
    private function rotulos(mixed $nodo): array
    {
        $encontrados = [];

        if (is_array($nodo)) {
            foreach ($nodo as $clave => $hijo) {
                if ($clave === 'bol_independiente') {
                    $encontrados[] = (bool) $hijo;
                } else {
                    $encontrados = array_merge($encontrados, $this->rotulos($hijo));
                }
            }
        }

        return $encontrados;
    }

    /** @return array{object, string, int, int, int} grupo, token, periodo, marcado, companero */
    private function escenario(): array
    {
        $grupo = $this->grupoConAlumnos();

        $usuario = DB::selectOne('SELECT u.username, u.periodo_id FROM users u
            INNER JOIN periodos p ON p.id = u.periodo_id AND p.deleted_at IS NULL
            WHERE u.tipo = "Usuario" AND u.is_active = 1 AND u.deleted_at IS NULL
              AND p.year_id = ? ORDER BY u.id LIMIT 1', [$grupo->year_id]);

        $this->assertNotNull($usuario, "El seed no tiene ningún Usuario en el año {$grupo->year_id}.");

        $dos = DB::select(
            'SELECT alumno_id FROM matriculas
              WHERE grupo_id = ? AND estado IN ("MATR","ASIS") AND deleted_at IS NULL
              ORDER BY alumno_id LIMIT 2',
            [$grupo->id]
        );

        $this->assertCount(2, $dos,
            'Este test necesita DOS alumnos en el grupo: uno marcado y un compañero sin marcar. '
            .'Con uno solo no se puede distinguir «el campo vale true» de «el campo es constante».');

        return [$grupo, $this->tokenDe($usuario->username), (int) $usuario->periodo_id,
            (int) $dos[0]->alumno_id, (int) $dos[1]->alumno_id];
    }

    /**
     * **`periodo_a_calcular` va SIEMPRE, y `boletines3` es la razón.**
     *
     * Su `putDetailedNotas` lo toma por defecto **10**, y
     * `Grupo::detailed_materias_notas_finales` sólo tiene ramas para **1, 2, 3 y 4**:
     * con 10 no entra en ninguna, devuelve cero asignaturas y el documento sale con
     * **las nueve áreas vacías** —`cant=0`— y sin una sola asignatura. Medido contra la
     * respuesta, no leído.
     *
     * **Eso pasa hoy y no lo trae este lote**, así que aquí no se arregla: se manda el
     * parámetro que manda un cliente de verdad. Pero hay que saberlo, porque **sin él
     * este test daría un rojo que parecería del rótulo y sería de otra cosa** — y con
     * un `assertNotEmpty` mal puesto habría dicho «boletines3 no emite el campo», que
     * es falso.
     */
    private function pedir(string $ruta, object $grupo, string $token, int $alumnoId): array
    {
        $r = $this->withToken($token)->putJson("/api/{$ruta}/{$grupo->id}", [
            'requested_alumnos' => [['alumno_id' => $alumnoId]],
            'periodo_a_calcular' => 4,
        ]);

        $this->assertSame(200, $r->status(), "`{$ruta}` tiene que contestar 200.");

        $cuerpo = $r->json();

        // **La población, dicha y no supuesta.** Un documento que no trae ni una
        // asignatura no puede traer el rótulo, y sin esta comprobación los dos casos se
        // leen igual: «el campo no viaja» y «no hay dónde ponerlo». Es lo que pasó al
        // escribir este test.
        $this->assertNotEmpty($this->asignaturas($cuerpo),
            "`{$ruta}` no devolvió ni una asignatura para el alumno {$alumnoId}, así que este test "
            .'no puede decir nada del rótulo. Si es por el `periodo_a_calcular`, mira el docblock.');

        return $this->rotulos($cuerpo);
    }

    /**
     * Cuántas asignaturas trae la respuesta, estén donde estén — el denominador del
     * rótulo.
     *
     * @return list<array<string, mixed>>
     */
    private function asignaturas(mixed $nodo): array
    {
        $encontradas = [];

        if (is_array($nodo)) {
            foreach ($nodo as $clave => $hijo) {
                if ($clave === 'asignaturas' && is_array($hijo)) {
                    foreach ($hijo as $asignatura) {
                        if (is_array($asignatura) && array_key_exists('asignatura_id', $asignatura)) {
                            $encontradas[] = $asignatura;
                        }
                    }
                }

                $encontradas = array_merge($encontradas, $this->asignaturas($hijo));
            }
        }

        return $encontradas;
    }

    /**
     * El rótulo **llega**, y llega en los cuatro.
     *
     * Es el caso que caza el modo de fallo de este campo: no un valor equivocado, sino
     * **el silencio**. Sin él, un documento que dejara de emitirlo seguiría contestando
     * 200 con el boletín entero bien.
     */
    #[DataProvider('losCuatroDocumentos')]
    public function test_el_rotulo_viaja_en_el_documento(string $ruta): void
    {
        [$grupo, $token, $periodoId, $marcado] = $this->escenario();

        $this->marcarIndependiente($marcado, $periodoId);

        $rotulos = $this->pedir($ruta, $grupo, $token, $marcado);

        $this->assertNotEmpty($rotulos,
            "`{$ruta}` no manda ni un `bol_independiente` en toda la respuesta. El front no pinta "
            .'nada y nadie se entera: el documento sale igual de bien sin el rótulo.');

        $this->assertContains(true, $rotulos,
            "`{$ruta}` manda el rótulo pero en `false` para un alumno que SÍ está marcado en el "
            .'periodo que ese documento cubre.');
    }

    /**
     * Y **varía dentro de la misma respuesta**: el marcado en `true`, su compañero en
     * `false`.
     *
     * Sin este caso, emitir `true` a secas —una constante— pasaría el test de arriba.
     * Es la trampa que el front ya cazó dos veces en este módulo con
     * `bol_independiente_periodo` y con `aplica`.
     */
    #[DataProvider('losCuatroDocumentos')]
    public function test_el_companero_sin_marcar_lo_lleva_en_false(string $ruta): void
    {
        [$grupo, $token, $periodoId, $marcado, $companero] = $this->escenario();

        $this->marcarIndependiente($marcado, $periodoId);

        $delMarcado = $this->pedir($ruta, $grupo, $token, $marcado);
        $delCompanero = $this->pedir($ruta, $grupo, $token, $companero);

        $this->assertNotEmpty($delCompanero, "`{$ruta}` no mandó rótulo para el compañero.");

        $this->assertNotContains(true, $delCompanero,
            "`{$ruta}` le pone el rótulo a un alumno que NO está marcado. El front le pintaría a él "
            .'la nota de que su boletín va aparte, que es exactamente lo contrario de lo que pasa.');

        $this->assertContains(true, $delMarcado,
            'Y el marcado sí lo lleva: si los dos salieran en false el campo sería constante y este '
            .'test no distinguiría nada.');
    }

    /**
     * En un boletín **de periodo**, la marca de OTRO periodo no lo enciende.
     *
     * Es la decisión 7 —*«tuvo un periodo normal y en el segundo un accidente, tienen
     * que convivir»*— aplicada al rótulo. `bolfinales-preescolar` **no entra aquí**:
     * ese documento es del año entero —no nombra `periodo` en ninguna línea— y le toca
     * la regla contraria, que es el caso de abajo.
     *
     * @return array<string, array{string}>
     */
    public static function losTresDePeriodo(): array
    {
        return [
            'boletines' => ['boletines/detailed-notas'],
            'boletines2' => ['boletines2/detailed-notas'],
            'boletines3' => ['boletines3/detailed-notas'],
        ];
    }

    #[DataProvider('losTresDePeriodo')]
    public function test_la_marca_de_otro_periodo_no_rotula_este(string $ruta): void
    {
        [$grupo, $token, $periodoId, $marcado] = $this->escenario();

        $otro = DB::selectOne(
            'SELECT p.id FROM periodos p
              INNER JOIN grupos g ON g.year_id = p.year_id
              WHERE g.id = ? AND p.id <> ? AND p.deleted_at IS NULL
              ORDER BY p.numero LIMIT 1',
            [$grupo->id, $periodoId]
        );

        $this->assertNotNull($otro, 'El año necesita un segundo periodo para que «otro periodo» exista.');

        $this->marcarIndependiente($marcado, (int) $otro->id);

        $this->assertNotContains(true, $this->pedir($ruta, $grupo, $token, $marcado),
            "`{$ruta}` rotula el boletín del periodo del token con una marca que es de OTRO periodo. "
            .'La marca cuelga de `(alumno_id, periodo_id)` y ese boletín no la lleva (decisión 7).');
    }

    /**
     * Y el de preescolar sí la ve, porque es del año entero.
     *
     * El documento promedia el año, así que dentro de sus cifras hay una definitiva
     * que **no se calculó sobre el reparto del grupo** — que es justo lo que el rótulo
     * avisa. Es la misma regla que el puesto en los informes anuales.
     */
    public function test_el_de_preescolar_ve_la_marca_de_cualquier_periodo(): void
    {
        [$grupo, $token, $periodoId, $marcado] = $this->escenario();

        $otro = DB::selectOne(
            'SELECT p.id FROM periodos p
              INNER JOIN grupos g ON g.year_id = p.year_id
              WHERE g.id = ? AND p.id <> ? AND p.deleted_at IS NULL
              ORDER BY p.numero LIMIT 1',
            [$grupo->id, $periodoId]
        );

        $this->assertNotNull($otro, 'El año necesita un segundo periodo.');

        $this->marcarIndependiente($marcado, (int) $otro->id);

        $this->assertContains(true,
            $this->pedir('bolfinales-preescolar/detailed-notas-year', $grupo, $token, $marcado),
            'El boletín final de preescolar es del año: una marca en cualquiera de sus periodos '
            .'tiene que rotularlo, porque sus cifras ya llevan dentro ese periodo.');
    }
}
